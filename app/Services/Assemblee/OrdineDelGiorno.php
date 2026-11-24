<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Enums\Assemblee\FontePunto;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\RuoloOggettoPunto;
use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Assemblee\OggettoDelPunto;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Documento;
use App\Models\Tabella;
use App\Models\User;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * L'ordine del giorno di un'assemblea in preparazione (§2, R11; §6.3 del progetto della tappa 2): aggiungere, riscrivere, togliere, riordinare, e i punti
 * scelti dalle proposte. **Dalla prima spedizione date e ordine del giorno non si cambiano più**: si annulla e si riconvoca (§4.7).
 *
 * Le righe del registro: un punto aggiunto, riscritto o tolto è la riga del suo tratto, nella «Storia» dell'assemblea («Punto 4 — … · Creata, con 4 dati»);
 * togliendo un punto, gli altri si rinumerano dentro lo stesso gesto e non fanno righe; riordinare è un gesto solo («Ordine del giorno riordinato: il punto 5
 * diventa il 4»). «Varie ed eventuali» resta in fondo.
 *
 * **L'assemblea dei condòmini di un gruppo** (Coda 734, §1.1-bis del progetto della tappa 3, decisione 699 (3)), in un posto solo per `aggiungi`, `riscrivi` e
 * `dalleProposte` ({@see nelGruppo()}): il punto che non si conta sul gruppo (la scala, la palazzina, una tabella senza il segno o con unità fuori) si ferma
 * sempre; il punto di tutto il condominio (il rendiconto e il preventivo, la nomina e la revoca dell'amministratore, la dispensa dei sei mesi, le regole del
 * recupero dei crediti) si ferma con la frase del rischio, e con «Mettilo lo stesso» (`mettilo_lo_stesso`) entra: la scelta resta nel registro, con chi l'ha
 * fatta. Il rendiconto di una gestione che riguarda solo il gruppo passa sempre.
 */
final class OrdineDelGiorno
{
    public const FRASE_NON_SI_CAMBIA = 'Dalla prima spedizione date e ordine del giorno non si cambiano più: si annulla e si riconvoca.';

    /** Le materie che per legge sono di tutto il condominio, con l'inizio della frase del rischio (decisione 699 (3)). */
    public const MATERIE_DI_TUTTI = [
        'rendiconto' => 'Il rendiconto è',
        'preventivo' => 'Il preventivo è',
        'nomina' => 'La nomina e la revoca dell\'amministratore sono',
        'dispensa' => 'La dispensa dei sei mesi è',
        'regole' => 'Le regole del recupero dei crediti sono',
    ];

    /** I campi del punto che arrivano dalla pagina o dalla proposta. */
    private const CAMPI = ['testo', 'testo_pubblico', 'materia', 'maggioranza', 'maggioranza_libera', 'amministrazione', 'conduttore', 'ambito', 'palazzina_id',
        'scala_id', 'tabella_id', 'esercizio_id', 'importo', 'dati'];

    /**
     * Un punto nuovo, in fondo (prima di «Varie ed eventuali»). `$dati`: i campi del punto, e `fonte`, `chiave_fonte`, `memo_id`, `oggetti`
     * (`[[tipo, id, ruolo], …]`). La maggioranza e l'amministrazione, se non ci sono, sono quelle proposte per la materia.
     *
     * @param array<string, mixed> $dati
     */
    public static function aggiungi(Assemblea $assemblea, array $dati, User $utente): PuntoDellOrdineDelGiorno
    {
        self::siPuoCambiare($assemblea);
        $rischio = self::nelGruppo($assemblea, $dati);

        return DB::transaction(function () use ($assemblea, $dati, $utente, $rischio) {
            // Un sospeso è un punto solo: se l'assemblea ha già il punto con la stessa chiave, il memo entra lì, e non nasce un secondo punto.
            $chiave = $dati['chiave_fonte'] ?? null;
            if ($chiave !== null && $chiave !== ProposteDellOrdineDelGiorno::CHIAVE_VARIE) {
                $esistente = $assemblea->punti()->where('chiave_fonte', $chiave)->orderBy('numero')->first();
                if ($esistente !== null) {
                    self::entranoIMemo($assemblea, $esistente, $dati);

                    return $esistente;
                }
            }
            $varie = $assemblea->punti()->where('materia', MateriaPunto::VARIE->value)->orderByDesc('numero')->first();
            $numero = $varie !== null && ($dati['materia'] ?? null) !== MateriaPunto::VARIE->value ? (int) $varie->numero
                : (int) $assemblea->punti()->max('numero') + 1;
            // Il punto nuovo prende il posto di «Varie» (o del primo punto da lì in giù): tutti quelli da quel numero scendono di uno, mai due numeri uguali.
            foreach ($assemblea->punti()->where('numero', '>=', $numero)->orderByDesc('numero')->get() as $dopo) {
                $dopo->numero = (int) $dopo->numero + 1;
                $dopo->save();
            }
            $punto = PuntoDellOrdineDelGiorno::create(self::campi($dati) + [
                'assemblea_id' => $assemblea->id,
                'numero' => $numero,
                'fonte' => $dati['fonte'] ?? FontePunto::A_MANO->value,
                'chiave_fonte' => $dati['chiave_fonte'] ?? null,
                'created_by' => $utente->id,
            ]);
            self::scriviGliOggetti($punto, (array) ($dati['oggetti'] ?? []));
            self::entranoIMemo($assemblea, $punto, $dati);
            self::rigaDelRischio($assemblea, $punto, $rischio);

            return $punto;
        });
    }

    /**
     * Il memo della proposta e gli altri memo in attesa con la stessa chiave entrano nel punto: due memo sulla stessa scheda sono lo stesso punto, e il
     * compito di ognuno si chiude con la frase del programma.
     *
     * @param array<string, mixed> $dati
     */
    private static function entranoIMemo(Assemblea $assemblea, PuntoDellOrdineDelGiorno $punto, array $dati): void
    {
        if (! empty($dati['memo_id'])) {
            $memo = MemoDellAssemblea::query()->whereKey((int) $dati['memo_id'])->where('condominio_id', $assemblea->condominio_id)->first();
            if ($memo !== null && $memo->eInAttesa()) {
                MemoriaDelCondominio::entraNelPunto($memo, $punto);
            }
        }
        $chiave = $punto->chiave_fonte;
        if ($chiave === null || $chiave === ProposteDellOrdineDelGiorno::CHIAVE_VARIE) {
            return;
        }
        foreach (MemoriaDelCondominio::inAttesaConLaChiave($assemblea, (string) $chiave) as $altro) {
            MemoriaDelCondominio::entraNelPunto($altro, $punto);
        }
    }

    /** Riscrive i campi di un punto (le due frasi, la materia, la maggioranza, il perimetro…). @param array<string, mixed> $dati */
    public static function riscrivi(PuntoDellOrdineDelGiorno $punto, array $dati, User $utente): PuntoDellOrdineDelGiorno
    {
        self::siPuoCambiare($punto->assemblea);
        $rischio = self::nelGruppo($punto->assemblea, $dati, $punto);
        $campi = array_intersect_key($dati, array_flip(self::CAMPI));
        if (isset($campi['materia']) && ! array_key_exists('amministrazione', $campi)) {
            $campi['amministrazione'] = MaggioranzePerMateria::amministrazione((string) $campi['materia'], $campi['maggioranza'] ?? $punto->maggioranza?->value);
        }
        $punto->fill($campi)->save();
        if (array_key_exists('oggetti', $dati)) {
            self::scriviGliOggetti($punto, (array) $dati['oggetti'], true);
        }
        self::rigaDelRischio($punto->assemblea, $punto, $rischio);

        return $punto->refresh();
    }

    /**
     * Toglie un punto: i memo entrati nel punto tornano nella memoria del condominio (R10, con il compito se la data è ancora davanti), gli altri punti si
     * rinumerano. Una riga sola, quella del punto («Punto 6 — … · Eliminata», «Torna nella memoria del condominio.»).
     */
    public static function togli(PuntoDellOrdineDelGiorno $punto, User $utente): void
    {
        $assemblea = $punto->assemblea;
        self::siPuoCambiare($assemblea);
        abort_if($punto->delibere()->exists(), 422, 'Il punto ha una delibera: non si toglie.');
        $gesto = Gesto::sulModello($assemblea)->conIlTrattoDi($punto)->scriviSe(fn () => false);
        RegistroModifiche::durante($gesto, function () use ($punto, $assemblea) {
            foreach (MemoriaDelCondominio::nelPunto($punto) as $memo) {
                MemoriaDelCondominio::tornaInAttesa($memo);
            }
            $numero = (int) $punto->numero;
            $punto->delete();
            foreach ($assemblea->punti()->where('numero', '>', $numero)->get() as $dopo) {
                $dopo->numero = (int) $dopo->numero - 1;
                $dopo->save();
            }
        });
    }

    /**
     * Il nuovo ordine, come elenco degli id dei punti dal primo all'ultimo. Una riga sola: «Ordine del giorno riordinato: il punto 5 diventa il 4».
     *
     * @param list<int> $ordine
     */
    public static function riordina(Assemblea $assemblea, array $ordine, User $utente): void
    {
        self::siPuoCambiare($assemblea);
        $punti = $assemblea->punti()->get()->keyBy('id');
        $ordine = array_values(array_map('intval', $ordine));
        if (count($ordine) !== $punti->count() || array_diff($ordine, $punti->keys()->all()) !== []) {
            throw ValidationException::withMessages(['ordine' => 'L\'ordine deve elencare tutti i punti di questa assemblea, una volta sola.']);
        }
        $varie = $punti->filter(fn (PuntoDellOrdineDelGiorno $p) => $p->materia === MateriaPunto::VARIE)->keys()->all();
        if ($varie !== [] && ! in_array(end($ordine), $varie, true)) {
            throw ValidationException::withMessages(['ordine' => '«Varie ed eventuali» resta l\'ultimo punto dell\'ordine del giorno.']);
        }
        RegistroModifiche::durante(GestiDelRegistro::ordineRiordinato($assemblea), function () use ($punti, $ordine) {
            $spostati = [];
            foreach ($ordine as $i => $id) {
                $punto = $punti[$id];
                if ((int) $punto->numero !== $i + 1) {
                    $spostati[] = 'il punto ' . $punto->numero . ' diventa il ' . ($i + 1);
                    $punto->numero = $i + 1;
                    $punto->save();
                }
            }

            return $spostati;
        });
    }

    /**
     * I punti scelti fra le proposte ({@see ProposteDellOrdineDelGiorno::per()}), nell'ordine dato: per la preparazione dell'assemblea e per «Mettilo
     * nell'ordine del giorno». Ognuna porta i suoi campi, la fonte, la chiave del sospeso, gli oggetti e il memo.
     *
     * @param list<array<string, mixed>> $proposte
     * @return list<PuntoDellOrdineDelGiorno>
     */
    public static function dalleProposte(Assemblea $assemblea, array $proposte, User $utente): array
    {
        // Il fermo del gruppo per ogni proposta, prima di scrivere: o entrano tutte, o nessuna.
        foreach ($proposte as $proposta) {
            self::nelGruppo($assemblea, $proposta);
        }
        $punti = [];
        foreach ($proposte as $proposta) {
            $punti[] = self::aggiungi($assemblea, $proposta, $utente);
        }

        return $punti;
    }

    /**
     * Il punto nell'assemblea dei condòmini di un gruppo (§1.1-bis, 6; decisione 699 (3)). Il punto che non si può contare sul gruppo si ferma sempre (422):
     * «Per chi» una scala o una palazzina, o una tabella che non è quella del gruppo né una tabella di un gruppo dentro di lei. Il punto di tutto il
     * condominio ({@see MATERIE_DI_TUTTI}) si ferma con la frase del rischio, salvo `mettilo_lo_stesso`: allora entra, e la funzione rende la frase per la riga
     * del registro. Null per tutto il resto, e per le assemblee che non sono di un gruppo. Con `$punto` (riscrivere) i campi che non arrivano sono i suoi.
     *
     * @param array<string, mixed> $dati
     */
    private static function nelGruppo(Assemblea $assemblea, array $dati, ?PuntoDellOrdineDelGiorno $punto = null): ?string
    {
        $gruppo = UnitaDelPunto::gruppoDellAssemblea($assemblea);
        if ($gruppo === null) {
            return null;
        }
        $nome = (string) Tabella::query()->whereKey($gruppo)->value('nome');
        $fuori = 'In un\'assemblea dei condòmini della tabella «' . $nome . '» un punto si vota fra loro: con la tabella «' . $nome
            . '» o con la tabella di un gruppo che sta dentro di lei.';
        $ambito = array_key_exists('ambito', $dati) ? $dati['ambito'] : $punto?->ambito?->value;
        if ($ambito !== null && $ambito !== AmbitoAssemblea::CONDOMINIO->value) {
            throw ValidationException::withMessages(['ambito' => $fuori]);
        }
        $tabella = array_key_exists('tabella_id', $dati) ? $dati['tabella_id'] : $punto?->tabella_id;
        if ($tabella !== null && (int) $tabella !== $gruppo && ! self::dentroIlGruppo((int) $tabella, $gruppo)) {
            throw ValidationException::withMessages(['tabella_id' => $fuori]);
        }
        $materia = (string) (array_key_exists('materia', $dati) ? $dati['materia'] : $punto?->materia?->value);
        // Riscritto con la stessa materia: il punto di tutti l'amministratore l'ha già messo lo stesso, e la sua riga c'è.
        if (! isset(self::MATERIE_DI_TUTTI[$materia]) || $punto?->materia?->value === $materia || self::dellaGestioneDelGruppo($dati, $punto, $gruppo)) {
            return null;
        }
        $rischio = self::MATERIE_DI_TUTTI[$materia] . ' di tutto il condominio: con i soli condòmini del gruppo la delibera è annullabile da chi non è convocato.';
        if (! filter_var($dati['mettilo_lo_stesso'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['materia' => $rischio . ' Mettilo in un\'assemblea di tutto il condominio, o premi «Mettilo lo stesso».']);
        }

        return $rischio;
    }

    /** Una tabella segnata «di un gruppo» con le sue unità (quelle con la quota) tutte dentro il gruppo dell'assemblea. */
    private static function dentroIlGruppo(int $tabella, int $gruppo): bool
    {
        if (! (bool) Tabella::query()->whereKey($tabella)->value('di_un_gruppo')) {
            return false;
        }
        $quote = UnitaDelPunto::quoteMaggioriDiZero([$tabella, $gruppo]);
        $sue = array_keys($quote[$tabella] ?? []);

        return $sue !== [] && array_diff($sue, array_keys($quote[$gruppo] ?? [])) === [];
    }

    /**
     * Il rendiconto (o il preventivo) di una gestione che riguarda solo il gruppo: il punto parla di una gestione (fra i suoi oggetti) le cui voci si
     * dividono solo con il gruppo o con tabelle di un gruppo dentro di lui. Passa sempre (decisione 699 (3)). Le proposte dell'assemblea del gruppo lo
     * offrono con la gestione fra gli oggetti ({@see gestioniDelGruppo()}).
     *
     * @param array<string, mixed> $dati
     */
    private static function dellaGestioneDelGruppo(array $dati, ?PuntoDellOrdineDelGiorno $punto, int $gruppo): bool
    {
        $gestioni = [];
        foreach ((array) ($dati['oggetti'] ?? []) as $o) {
            [$tipo, $id] = array_is_list($o) ? [$o[0] ?? null, $o[1] ?? null] : [$o['tipo'] ?? null, $o['id'] ?? null];
            if ($tipo === 'gestione' && $id !== null) {
                $gestioni[] = (int) $id;
            }
        }
        if ($punto !== null && ! array_key_exists('oggetti', $dati)) {
            $gestioni = OggettoDelPunto::query()->where('punto_id', $punto->id)->where('oggetto_tipo', 'gestione')->pluck('oggetto_id')->map(fn ($id) => (int) $id)->all();
        }
        if ($gestioni === []) {
            return false;
        }
        $tabelle = DB::table('conto_tabella_millesimale')->join('conti', 'conti.id', '=', 'conto_tabella_millesimale.conto_id')
            ->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->whereIn('piani_conti.gestione_id', $gestioni)
            ->distinct()->pluck('conto_tabella_millesimale.tabella_id')->map(fn ($id) => (int) $id)->all();

        return self::soloDelGruppo($tabelle, $gruppo);
    }

    /**
     * Le gestioni attive del condominio che riguardano solo il gruppo (decisione 699 (3)): le loro voci si dividono solo con la tabella del gruppo o con
     * tabelle di un gruppo dentro di lei. Il loro rendiconto e il loro preventivo sono fra le proposte dell'assemblea del gruppo.
     *
     * @return list<array{id: int, nome: string, tipo: string}>
     */
    public static function gestioniDelGruppo(int $condominioId, int $gruppo): array
    {
        $tabelle = [];
        foreach (DB::table('conto_tabella_millesimale')->join('conti', 'conti.id', '=', 'conto_tabella_millesimale.conto_id')
            ->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->join('gestioni', 'gestioni.id', '=', 'piani_conti.gestione_id')
            ->where('gestioni.condominio_id', $condominioId)->where('gestioni.attiva', true)->distinct()
            ->get(['piani_conti.gestione_id', 'conto_tabella_millesimale.tabella_id']) as $r) {
            $tabelle[(int) $r->gestione_id][] = (int) $r->tabella_id;
        }
        $del = array_keys(array_filter($tabelle, fn (array $t) => self::soloDelGruppo($t, $gruppo)));

        return $del === [] ? [] : DB::table('gestioni')->whereIn('id', $del)->orderBy('data_inizio')->orderBy('id')->get(['id', 'nome', 'tipo'])
            ->map(fn ($g) => ['id' => (int) $g->id, 'nome' => (string) $g->nome, 'tipo' => (string) $g->tipo])->values()->all();
    }

    /** Le tabelle (di una gestione) sono il gruppo o tabelle di un gruppo dentro di lui, e almeno una. @param list<int> $tabelle */
    private static function soloDelGruppo(array $tabelle, int $gruppo): bool
    {
        return $tabelle !== [] && array_filter($tabelle, fn (int $t) => $t !== $gruppo && ! self::dentroIlGruppo($t, $gruppo)) === [];
    }

    /**
     * La riga del registro del punto di tutto il condominio messo lo stesso nell'assemblea di un gruppo: una riga sua, scritta subito (anche dentro la
     * preparazione, che altrimenti la conterebbe soltanto), con l'autore di ogni riga, cioè chi l'ha scelto.
     */
    private static function rigaDelRischio(Assemblea $assemblea, PuntoDellOrdineDelGiorno $punto, ?string $rischio): void
    {
        if ($rischio === null) {
            return;
        }
        $nome = (string) Tabella::query()->whereKey(UnitaDelPunto::gruppoDellAssemblea($assemblea))->value('nome');
        $gesto = Gesto::su('assemblee', 'assemblea', (int) $assemblea->id, GestiDelRegistro::nomeDellAssemblea($assemblea))
            ->nelCondominio((int) $assemblea->condominio_id)
            ->riepilogo((new \App\Services\RegistroModifiche\Schede\PuntoNelRegistro())->nome($punto) . ': messo lo stesso nell\'assemblea dei condòmini della '
                . 'tabella «' . $nome . '». ' . $rischio);
        RegistroModifiche::scrivi($gesto->perLaRiga());
    }

    /** Un'assemblea si cambia solo in preparazione. */
    public static function siPuoCambiare(?Assemblea $assemblea): void
    {
        if ($assemblea === null || $assemblea->stato !== StatoAssemblea::IN_PREPARAZIONE) {
            throw ValidationException::withMessages(['assemblea' => self::FRASE_NON_SI_CAMBIA]);
        }
    }

    /** @param array<string, mixed> $dati @return array<string, mixed> */
    private static function campi(array $dati): array
    {
        $campi = array_intersect_key($dati, array_flip(self::CAMPI));
        $materia = (string) ($campi['materia'] ?? MateriaPunto::ALTRO->value);
        $campi['materia'] = $materia;
        if (! array_key_exists('maggioranza', $campi)) {
            $campi['maggioranza'] = MaggioranzePerMateria::proposta($materia);
        }
        $campi['amministrazione'] ??= MaggioranzePerMateria::amministrazione($materia, $campi['maggioranza']);
        // Il conduttore proposto per la materia, se chi aggiunge il punto non l'ha scelto (la finestra lo manda sempre, anche vuoto).
        if (! array_key_exists('conduttore', $campi)) {
            $campi['conduttore'] = MaggioranzePerMateria::conduttore($materia);
        }
        $campi['testo'] = trim((string) ($campi['testo'] ?? ''));

        return $campi;
    }

    /**
     * I documenti del punto, dal passo «Documenti»: aggiunti in fondo nell'ordine dato, o tolti. Passano dal campo «Parla di» come gli altri oggetti, con la
     * sua riga del registro, e così entrano nel diario. Solo in preparazione, come i punti: dalla prima spedizione i documenti sono partiti con l'avviso.
     *
     * @param list<int> $aggiungi
     * @param list<int> $togli
     */
    public static function documentiDelPunto(PuntoDellOrdineDelGiorno $punto, array $aggiungi, array $togli = []): void
    {
        self::siPuoCambiare($punto->assemblea);
        self::campoParlaDi($punto, function () use ($punto, $aggiungi, $togli) {
            if ($togli !== []) {
                OggettoDelPunto::query()->where('punto_id', $punto->id)->where('oggetto_tipo', 'documento')->whereIn('oggetto_id', $togli)->delete();
            }
            foreach ($aggiungi as $id) {
                OggettoDelPunto::query()->firstOrCreate(['punto_id' => $punto->id, 'oggetto_tipo' => 'documento', 'oggetto_id' => (int) $id,
                    'ruolo' => RuoloOggettoPunto::DOCUMENTO->value]);
            }
        });
    }

    /**
     * Gli oggetti del punto («Parla di»): dentro il campo della scheda, mai una riga per oggetto (§6.1). Con `$sostituisci` gli oggetti di prima se ne vanno.
     *
     * @param list<array{0: string, 1: int, 2?: string}|array{tipo: string, id: int, ruolo?: string}> $oggetti
     */
    private static function scriviGliOggetti(PuntoDellOrdineDelGiorno $punto, array $oggetti, bool $sostituisci = false): void
    {
        self::campoParlaDi($punto, function () use ($punto, $oggetti, $sostituisci) {
            if ($sostituisci) {
                OggettoDelPunto::query()->where('punto_id', $punto->id)->delete();
            }
            foreach ($oggetti as $o) {
                [$tipo, $id, $ruolo] = array_is_list($o) ? [$o[0], $o[1], $o[2] ?? 'parla_di'] : [$o['tipo'], $o['id'], $o['ruolo'] ?? 'parla_di'];
                OggettoDelPunto::query()->firstOrCreate(['punto_id' => $punto->id, 'oggetto_tipo' => (string) $tipo, 'oggetto_id' => (int) $id,
                    'ruolo' => (string) $ruolo]);
            }
        });
    }

    /**
     * Il campo «Parla di» del punto, con la sua riga del registro: com'era e com'è, un oggetto per voce. Un documento si legge con il suo nome
     * («Documento: «Preventivo portone.pdf»»): è la riga che il diario dice «Documento aggiunto al punto 2».
     */
    private static function campoParlaDi(PuntoDellOrdineDelGiorno $punto, \Closure $lavoro): void
    {
        $fotografia = function () use ($punto) {
            $oggetti = OggettoDelPunto::query()->where('punto_id', $punto->id)->orderBy('id')->get();
            $documenti = $oggetti->where('oggetto_tipo', 'documento')->pluck('oggetto_id')->all();
            $nomi = $documenti === [] ? collect() : Documento::query()->whereIn('id', $documenti)->pluck('name', 'id');

            return $oggetti->mapWithKeys(fn (OggettoDelPunto $o) => [$o->oggetto_tipo . ':' . $o->oggetto_id . ':' . $o->ruolo?->value
                => $o->ruolo?->parola() . ': ' . ($o->oggetto_tipo === 'documento' && isset($nomi[$o->oggetto_id]) ? '«' . $nomi[$o->oggetto_id] . '»'
                    : $o->oggetto_tipo . ' n. ' . $o->oggetto_id)])->all();
        };
        RegistroModifiche::campoDellaScheda($punto, 'oggetti', 'Parla di', $fotografia, $lavoro);
    }
}
