<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\FontePunto;
use App\Enums\Assemblee\MateriaPunto;
use App\Enums\Assemblee\RuoloOggettoPunto;
use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Assemblee\OggettoDelPunto;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Documento;
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
 */
final class OrdineDelGiorno
{
    public const FRASE_NON_SI_CAMBIA = 'Dalla prima spedizione date e ordine del giorno non si cambiano più: si annulla e si riconvoca.';

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

        return DB::transaction(function () use ($assemblea, $dati, $utente) {
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
        $campi = array_intersect_key($dati, array_flip(self::CAMPI));
        if (isset($campi['materia']) && ! array_key_exists('amministrazione', $campi)) {
            $campi['amministrazione'] = MaggioranzePerMateria::amministrazione((string) $campi['materia'], $campi['maggioranza'] ?? $punto->maggioranza?->value);
        }
        $punto->fill($campi)->save();
        if (array_key_exists('oggetti', $dati)) {
            self::scriviGliOggetti($punto, (array) $dati['oggetti'], true);
        }

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
        $punti = [];
        foreach ($proposte as $proposta) {
            $punti[] = self::aggiungi($assemblea, $proposta, $utente);
        }

        return $punti;
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
