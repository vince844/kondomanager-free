<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use Illuminate\Support\Facades\DB;

/**
 * I gesti che più punti del programma scrivono allo stesso modo: l'eliminazione di un condominio (gesto 3), dal pulsante, dal condominio di prova e
 * dall'annullamento di un'importazione. I conteggi si leggono **prima** del `delete`: dopo, le chiavi a cascata li hanno portati via.
 */
final class GestiDelRegistro
{
    /** Le cose che spariscono con un condominio, con la parola della riga. */
    private const CONTEGGI = [
        'unita' => ['immobili', 'unità', 'unità'],
        'persone' => ['anagrafica_condominio', 'persona collegata', 'persone collegate'],
        'piani_rate' => ['piani_rate', 'piano rate', 'piani rate'],
        'rate' => ['rate', 'rata', 'rate'],
        'scritture' => ['scritture_contabili', 'scrittura', 'scritture'],
        'fatture' => ['fatture_passive', 'fattura', 'fatture'],
        'documenti' => ['condominio_documento', 'documento', 'documenti'],
        'comunicazioni' => ['comunicazione_condominio', 'comunicazione', 'comunicazioni'],
        'segnalazioni' => ['segnalazioni', 'segnalazione', 'segnalazioni'],
    ];

    /** I conteggi di ciò che sparisce con il condominio. @return array<string, int> */
    public static function conteggiDelCondominio(int $condominioId): array
    {
        $conti = [];
        foreach (self::CONTEGGI as $chiave => [$tabella]) {
            $conti[$chiave] = match ($tabella) {
                'rate' => (int) DB::table('rate')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
                    ->where('piani_rate.condominio_id', $condominioId)->count(),
                default => (int) DB::table($tabella)->where('condominio_id', $condominioId)->count(),
            };
        }

        return $conti;
    }

    /** «3 unità, 12 persone collegate, 2 piani rate…», senza ciò che è zero. @param array<string, int> $conti */
    public static function fraseDeiConteggi(array $conti): string
    {
        $pezzi = [];
        foreach (self::CONTEGGI as $chiave => [, $una, $tante]) {
            $n = $conti[$chiave] ?? 0;
            if ($n > 0) {
                $pezzi[] = number_format($n, 0, ',', '.') . ' ' . ($n === 1 ? $una : $tante);
            }
        }

        return $pezzi === [] ? 'nessun dato collegato' : implode(', ', $pezzi);
    }

    /** Il gesto dell'eliminazione di un condominio, con i conteggi letti adesso. */
    public static function eliminazioneDelCondominio(Condominio $condominio, string $parole = 'Condominio eliminato', ?string $perche = null): Gesto
    {
        $conti = self::conteggiDelCondominio((int) $condominio->getKey());

        return Gesto::sulModello($condominio)->azione('eliminata')
            ->riepilogo($parole . ': ' . self::fraseDeiConteggi($conti))
            ->perche($perche)
            ->lotto(['sparisce' => $conti]);
    }

    /**
     * Il gesto 4: «Passaggio registrato: Int. 7 da Mario Rossi a Ida Romano dal 01/03/2026», con il rimando al passaggio e le due persone fra i soggetti. Il
     * «prima e dopo» resta in `subentri.registro`. Il passaggio si conosce alla fine del lavoro: l'esito porta `subentro`.
     */
    public static function passaggioRegistrato(Condominio $condominio, Immobile $immobile): Gesto
    {
        $gesto = Gesto::su('condominio', 'passaggio', null, 'Passaggio dell\'unità ' . $immobile->etichetta)->nelCondominio($condominio)
            ->scheda('immobile', (int) $immobile->id);

        return $gesto->riepilogo(function (Lotto $lotto, mixed $esito) use ($gesto, $immobile) {
            $subentro = is_array($esito) ? ($esito['subentro'] ?? null) : null;
            if (! $subentro instanceof Subentro) {
                return 'Passaggio registrato: ' . $immobile->etichetta;
            }
            $gesto->oggetto((int) $subentro->id)
                ->soggetto('anagrafica', $subentro->anagrafica_uscente_id === null ? null : (int) $subentro->anagrafica_uscente_id)
                ->soggetto('anagrafica', $subentro->anagrafica_entrante_id === null ? null : (int) $subentro->anagrafica_entrante_id)
                ->rimando('passaggio', (int) $subentro->id, 'Passaggio dell\'unità ' . $immobile->etichetta);

            return 'Passaggio registrato: ' . self::frasePassaggio($subentro, $immobile);
        });
    }

    /** Il gesto 4 all'indietro: «Passaggio annullato: …», con il motivo scritto. */
    public static function passaggioAnnullato(Subentro $passaggio, string $nota): Gesto
    {
        $immobile = Immobile::query()->find($passaggio->immobile_id);
        $etichetta = $immobile?->etichetta ?? '';

        return Gesto::su('condominio', 'passaggio', (int) $passaggio->id, 'Passaggio dell\'unità ' . $etichetta)
            ->nelCondominio((int) $passaggio->condominio_id)
            ->scheda('immobile', $immobile === null ? null : (int) $immobile->id)
            ->riepilogo('Passaggio annullato: ' . self::frasePassaggio($passaggio, $immobile))
            ->perche($nota)
            ->soggetto('anagrafica', $passaggio->anagrafica_uscente_id === null ? null : (int) $passaggio->anagrafica_uscente_id)
            ->soggetto('anagrafica', $passaggio->anagrafica_entrante_id === null ? null : (int) $passaggio->anagrafica_entrante_id)
            ->rimando('passaggio', (int) $passaggio->id, 'Passaggio dell\'unità ' . $etichetta);
    }

    /** «Int. 7 da Mario Rossi a Ida Romano dal 01/03/2026». */
    private static function frasePassaggio(Subentro $subentro, ?Immobile $immobile): string
    {
        $uscente = $subentro->anagrafica_uscente_id === null ? null : Anagrafica::query()->whereKey($subentro->anagrafica_uscente_id)->value('nome');
        $entrante = $subentro->anagrafica_entrante_id === null ? null : Anagrafica::query()->whereKey($subentro->anagrafica_entrante_id)->value('nome');
        $dal = $subentro->getAttributes()['decorrenza'] ?? null;

        return trim(($immobile?->etichetta ?? '') . ($uscente !== null ? ' da ' . $uscente : '') . ($entrante !== null ? ' a ' . $entrante : '')
            . ($dal !== null ? ' dal ' . FormatoDelValore::testo($dal, 'data') : ''));
    }

    /** I millesimi di una tabella adesso: unità => [etichetta, valore grezzo]. @return array<int, array{0: string, 1: ?string}> */
    public static function millesimi(\App\Models\Tabella $tabella): array
    {
        $per = [];
        foreach (DB::table('quote_tabella')->leftJoin('immobili', 'immobili.id', '=', 'quote_tabella.immobile_id')
            ->where('quote_tabella.tabella_id', $tabella->id)->orderBy('quote_tabella.immobile_id')
            ->get(['quote_tabella.immobile_id', 'quote_tabella.valore', 'immobili.interno', 'immobili.nome', 'immobili.codice_immobile']) as $q) {
            $etichetta = trim((string) $q->interno) !== '' ? 'Int. ' . trim((string) $q->interno) : (trim((string) $q->nome) !== '' ? (string) $q->nome
                : (string) $q->codice_immobile);
            $per[(int) $q->immobile_id] = [$etichetta, $q->valore === null ? null : (string) $q->valore];
        }

        return $per;
    }

    /**
     * Il gesto 1: un campo per unità cambiata, tolta o aggiunta («Int. 7: 55,60 → 60,00»), con i decimali della tabella.
     *
     * @param array<int, array{0: string, 1: ?string}> $prima
     * @param array<int, array{0: string, 1: ?string}> $dopo
     * @return list<array<string, mixed>>
     */
    public static function campiDeiMillesimi(array $prima, array $dopo, int $decimali): array
    {
        $scrivi = fn (?string $v) => $v === null ? null : number_format((float) $v, $decimali, ',', '.');
        $campi = [];
        foreach (array_unique([...array_keys($prima), ...array_keys($dopo)]) as $unita) {
            $vPrima = $prima[$unita][1] ?? null;
            $vDopo = $dopo[$unita][1] ?? null;
            $tPrima = $scrivi($vPrima);
            $tDopo = $scrivi($vDopo);
            if ($tPrima === $tDopo && isset($prima[$unita]) === isset($dopo[$unita])) {
                continue;
            }
            $etichetta = $dopo[$unita][0] ?? $prima[$unita][0];
            $campi[] = Campo::nuovo('immobile_' . $unita, $etichetta, isset($prima[$unita]) ? ($tPrima ?? 'non compilato') : 'non c\'era',
                isset($dopo[$unita]) ? ($tDopo ?? 'non compilato') : 'tolta', $vPrima, $vDopo);
        }

        return $campi;
    }

    /** Le rate e le quote di un piano adesso: le quote nascono con `insert` a blocchi, e si contano dal database. @return array{rate: int, quote: int} */
    public static function rateEQuote(PianoRate $piano): array
    {
        $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->pluck('id');

        return ['rate' => $rate->count(), 'quote' => $rate->isEmpty() ? 0 : (int) DB::table('rate_quote')->whereIn('rata_id', $rate)->count()];
    }

    /** Il gesto 2: «Piano «Ordinario 2027» generato: 12 rate, 312 quote», con il rimando al piano. */
    public static function pianoGenerato(PianoRate $piano, string $parola = 'generato'): Gesto
    {
        return Gesto::sulModello($piano)
            ->riepilogo(function () use ($piano, $parola) {
                $conti = self::rateEQuote($piano);

                return 'Piano «' . $piano->nome . '» ' . $parola . ': ' . $conti['rate'] . ($conti['rate'] === 1 ? ' rata, ' : ' rate, ')
                    . $conti['quote'] . ($conti['quote'] === 1 ? ' quota' : ' quote');
            })
            ->rimando('piano_rate', (int) $piano->id, 'Piano rate «' . $piano->nome . '»');
    }

    /** L'eliminazione di un piano, con le rate e le quote lette prima. */
    public static function pianoEliminato(PianoRate $piano): Gesto
    {
        $conti = self::rateEQuote($piano);

        return Gesto::sulModello($piano)->azione('eliminata')
            ->riepilogo('Piano «' . $piano->nome . '» eliminato: ' . $conti['rate'] . ($conti['rate'] === 1 ? ' rata, ' : ' rate, ') . $conti['quote']
                . ($conti['quote'] === 1 ? ' quota' : ' quote'))
            ->lotto(['sparisce' => $conti]);
    }

    /** L'emissione delle rate a giornale (gesto 2), con le scritture contate nel lotto. */
    public static function emissione(PianoRate $piano): Gesto
    {
        return Gesto::sulModello($piano)
            ->riepilogo(fn (Lotto $lotto) => 'Rate del piano «' . $piano->nome . '» emesse: ' . $lotto->quante('scrittura_contabile', 'creata')
                . ($lotto->quante('scrittura_contabile', 'creata') === 1 ? ' scrittura' : ' scritture') . ' a giornale')
            ->rimando('piano_rate', (int) $piano->id, 'Piano rate «' . $piano->nome . '»');
    }

    /** L'annullamento dell'emissione di una rata (gesto 2): le scritture tolte per sempre sono nel lotto, mai una riga per riga di giornale. */
    public static function emissioneAnnullata(PianoRate $piano, \App\Models\Gestionale\Rata $rata): Gesto
    {
        return Gesto::sulModello($piano)
            ->riepilogo('Emissione della rata n. ' . $rata->numero_rata . ' del piano «' . $piano->nome . '» annullata: la rata torna in bozza')
            ->rimando('piano_rate', (int) $piano->id, 'Piano rate «' . $piano->nome . '»');
    }

    /**
     * La fattura modificata: i suoi dati nella sua riga (il tratto), e un gesto con le scritture di competenza riscritte (righe di giornale rilegate o
     * cancellate per sempre, rischi 5 e 6 del contesto: mai una riga per riga di giornale).
     */
    public static function fatturaModificata(FatturaPassiva $fattura): Gesto
    {
        $nome = Schede\FatturaPassivaNelRegistro::class;

        return Gesto::sulModello($fattura)->conIlTrattoDi($fattura)
            ->riepilogo(fn (Lotto $lotto) => (new $nome())->nome($fattura) . ' modificata: scritture di competenza riscritte ('
                . $lotto->quante('scrittura_contabile', 'eliminata') . ' tolte, ' . $lotto->quante('scrittura_contabile', 'creata') . ' nuove)')
            ->rimando('fattura_passiva', (int) $fattura->id, (new $nome())->nome($fattura));
    }

    /** La fattura eliminata, con il totale e le scritture tolte per sempre nel lotto. */
    public static function fatturaEliminata(FatturaPassiva $fattura): Gesto
    {
        $nome = (new Schede\FatturaPassivaNelRegistro())->nome($fattura);

        return Gesto::sulModello($fattura)->azione('eliminata')
            ->riepilogo(fn (Lotto $lotto) => $nome . ' eliminata, ' . \App\Helpers\MoneyHelper::format((int) $fattura->totale_documento) . ': '
                . $lotto->quante('scrittura_contabile', 'eliminata') . ' scritture tolte');
    }

    /** Lo storno: «Fattura n. 88 stornata: nota di credito NC-2026-0001». L'esito è il protocollo della nota. */
    public static function fatturaStornata(FatturaPassiva $fattura): Gesto
    {
        $nome = (new Schede\FatturaPassivaNelRegistro())->nome($fattura);

        return Gesto::sulModello($fattura)
            ->riepilogo(fn (Lotto $lotto, mixed $protocollo) => $nome . ' stornata' . (is_string($protocollo) && $protocollo !== '' ? ': nota di credito '
                . $protocollo : ''))
            ->rimando('fattura_passiva', (int) $fattura->id, $nome);
    }

    /** Il «già versato» di una voce adesso, per unità: unità => [etichetta, centesimi]. @return array<int, array{0: string, 1: ?string}> */
    public static function giaVersato(\App\Models\Gestionale\Conto $conto): array
    {
        $per = [];
        foreach (DB::table('contributi_versati')->leftJoin('immobili', 'immobili.id', '=', 'contributi_versati.immobile_id')
            ->where('contributi_versati.target_type', \App\Models\Gestionale\Conto::class)->where('contributi_versati.target_id', $conto->id)
            ->groupBy('contributi_versati.immobile_id', 'immobili.interno', 'immobili.nome')
            ->selectRaw('contributi_versati.immobile_id, immobili.interno, immobili.nome, SUM(contributi_versati.importo_cents) as totale')->get() as $r) {
            $etichetta = trim((string) $r->interno) !== '' ? 'Int. ' . trim((string) $r->interno) : (string) $r->nome;
            $per[(int) $r->immobile_id] = [$etichetta, (string) (int) $r->totale];
        }

        return $per;
    }

    /**
     * P4: «Già versato della voce X: Int. 7 € 0,00 → € 120,00», un campo per unità cambiata.
     *
     * @param array<int, array{0: string, 1: ?string}> $prima
     * @param array<int, array{0: string, 1: ?string}> $dopo
     * @return list<array<string, mixed>>
     */
    public static function campiDelGiaVersato(array $prima, array $dopo): array
    {
        $campi = [];
        foreach (array_unique([...array_keys($prima), ...array_keys($dopo)]) as $unita) {
            $vPrima = (int) ($prima[$unita][1] ?? 0);
            $vDopo = (int) ($dopo[$unita][1] ?? 0);
            if ($vPrima === $vDopo) {
                continue;
            }
            $campi[] = Campo::nuovo('immobile_' . $unita, $dopo[$unita][0] ?? $prima[$unita][0], \App\Helpers\MoneyHelper::format($vPrima),
                \App\Helpers\MoneyHelper::format($vDopo), $vPrima, $vDopo);
        }

        return $campi;
    }

    /**
     * Decisione 548 e P11: «riallineati i crediti, 3 scritture di rettifica», una riga sola per condominio, con il rimando alle scritture (gli id sono nel
     * lotto). Un condominio senza rettifiche non scrive niente. L'autore è quello del contesto: «Il programma, comando lanciato da terminale: …» dal
     * terminale, la persona dalla pagina.
     */
    public static function riallineamento(Condominio $condominio, string $che): Gesto
    {
        return Gesto::su('contabilita', 'riallineamento', null, ucfirst($che))->nelCondominio($condominio)
            ->riepilogo(fn (Lotto $lotto) => 'Riallineati ' . $che . ': ' . $lotto->quante('scrittura_contabile', 'creata')
                . ($lotto->quante('scrittura_contabile', 'creata') === 1 ? ' scrittura di rettifica' : ' scritture di rettifica'))
            ->rimando('scritture_contabili', null, 'Le scritture di rettifica')
            ->scriviSe(fn (Lotto $lotto) => $lotto->quante('scrittura_contabile') > 0);
    }

    /**
     * Decisione 547: un gesto di una persona sull'agenda o su un compito, con il titolo copiato nella riga, così la riga resta anche quando il programma
     * pota il compito. Un compito è un evento che chiede un'azione (`meta.requires_action`).
     */
    public static function agenda(\App\Models\Evento $evento, string $riepilogo, ?string $perche = null): Gesto
    {
        $compito = (bool) (($evento->meta ?? [])['requires_action'] ?? false);
        $tipo = $compito ? 'compito' : 'evento';
        $nome = ($compito ? 'Compito' : 'Appuntamento') . ' «' . $evento->title . '»';
        $gesto = Gesto::su('agenda', $tipo, $evento->id === null ? null : (int) $evento->id, $nome)->riepilogo($riepilogo)->perche($perche);
        if ($evento->exists) {
            $gesto->neiCondomini($evento->condomini()->pluck('condomini.id'))->soggettiDi('anagrafica', $evento->anagrafiche()->pluck('anagrafiche.id'));
        }

        return $gesto;
    }

    /**
     * Decisione 547 sui compiti del riquadro «Sei mesi per agire» e del rendiconto: non sono eventi dell'agenda, sono la
     * risposta di un argomento del condominio (`risposte_promemoria`), e il nome dice il compito con le parole del promemoria che mette in agenda:
     * «Compito «Sei mesi per agire, esercizio 2025»», «Compito «Rendiconto 2025 da portare in assemblea»», «… per Mario Rossi» sulla riga di una
     * persona.
     *
     * @param iterable<int> $persone le persone del gesto, fra i soggetti (quella della riga c'è sempre)
     */
    public static function compitoDelRiquadro(\App\Models\Gestionale\RispostaPromemoria $risposta, string $riepilogo, ?string $perche = null,
        iterable $persone = []): Gesto
    {
        $esercizio = $risposta->esercizio_id === null ? null : \App\Models\Esercizio::query()->find($risposta->esercizio_id);
        $anno = trim((string) preg_replace('/^esercizio\s+/iu', '', (string) $esercizio?->nome));
        $titolo = $risposta->argomento === \App\Services\Gestionale\RispostePromemoria::RENDICONTO
            ? 'Rendiconto ' . $anno . ' da portare in assemblea' : 'Sei mesi per agire, esercizio ' . $anno;
        $persona = $risposta->anagrafica_id === null ? null : Anagrafica::query()->find($risposta->anagrafica_id);
        $nome = 'Compito «' . $titolo . '»' . ($persona !== null ? ' per ' . $persona->nome : '');
        $soggetti = [...($persona !== null ? [(int) $persona->id] : []),
            ...array_map('intval', is_array($persone) ? $persone : iterator_to_array($persone, false))];

        return Gesto::su('agenda', CatalogoDelRegistro::tipoDi(\App\Models\Gestionale\RispostaPromemoria::class), (int) $risposta->id, $nome)
            ->nelCondominio($risposta->condominio_id === null ? null : (int) $risposta->condominio_id)
            ->soggettiDi('anagrafica', array_values(array_unique($soggetti)))
            ->riepilogo($riepilogo)->perche($perche);
    }

    /**
     * L'importazione (decisione 518: una riga per lotto; P10: una per transazione, cioè per livello, `ImportRunner`): «Importazione da Danea, livello
     * «Soggetti»: 214 righe», con il rimando all'elenco del lotto. L'autore è chi l'ha avviata, anche quando il lavoro gira in coda.
     */
    public static function livelloDellImportazione(\App\Models\ImportBatch $lotto, string $livello): Gesto
    {
        return Gesto::su('condominio', 'importazione', (int) $lotto->id, 'Importazione da ' . ucfirst((string) ($lotto->sorgente ?: 'archivio')))
            ->nelCondominio($lotto->condominio_id === null ? null : (int) $lotto->condominio_id)
            ->riepilogo(fn (Lotto $l) => 'Livello «' . $livello . '» importato: ' . array_sum($l->perLaRiga()['conteggi'] ?? []) . ' righe')
            ->rimando('importazione', (int) $lotto->id, 'L\'elenco del lotto');
    }

    /** L'autore di un'importazione: chi l'ha avviata, con l'origine. */
    public static function autoreDellImportazione(\App\Models\ImportBatch $lotto): Autore
    {
        $utente = $lotto->user_id === null ? null : \App\Models\User::query()->find($lotto->user_id);
        $dettaglio = 'Importazione da ' . ucfirst((string) ($lotto->sorgente ?: 'archivio')) . ($utente !== null ? ' avviata da ' . $utente->name : '');

        return $utente !== null ? Autore::persona($utente, 'importazione', $dettaglio) : Autore::programma('importazione', $dettaglio);
    }

    /** Le tabelle di una voce adesso, con il coefficiente e le ripartizioni: «A — Proprietà 100 % (proprietario 100 %)». @return array<int, string> */
    public static function tabelleDellaVoce(int $contoId): array
    {
        $per = [];
        foreach (DB::table('conto_tabella_millesimale')->join('tabelle', 'tabelle.id', '=', 'conto_tabella_millesimale.tabella_id')
            ->where('conto_tabella_millesimale.conto_id', $contoId)->orderBy('conto_tabella_millesimale.tabella_id')
            ->get(['conto_tabella_millesimale.id', 'conto_tabella_millesimale.tabella_id', 'conto_tabella_millesimale.coefficiente', 'tabelle.nome']) as $t) {
            $ripartizioni = DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $t->id)->orderBy('soggetto')
                ->get(['soggetto', 'percentuale'])->map(fn ($r) => $r->soggetto . ' ' . FormatoDelValore::testo($r->percentuale, 'quota'))->implode(', ');
            $per[(int) $t->tabella_id] = $t->nome . ' ' . FormatoDelValore::testo($t->coefficiente, 'quota') . ($ripartizioni !== '' ? ' (' . $ripartizioni . ')' : '');
        }

        return $per;
    }

    /** Gesto 7, voci e tabelle: il campo «Tabelle millesimali» della voce, se le tabelle, i coefficienti o le ripartizioni sono cambiati. @param array<int, string> $prima */
    public static function scriviTabelleDellaVoce(\App\Models\Gestionale\Conto $voce, array $prima): void
    {
        $dopo = self::tabelleDellaVoce((int) $voce->id);
        if ($prima === $dopo) {
            return;
        }
        RegistroModifiche::gesto(Gesto::sulModello($voce)->azione('modificata')->campo('tabelle', 'Tabelle millesimali',
            $prima === [] ? null : implode('; ', $prima), $dopo === [] ? null : implode('; ', $dopo), array_keys($prima), array_keys($dopo)));
    }

    // ── Le assemblee (Coda 679, §6.3 del progetto della tappa 2) ─────────────────────────────────────────────────────────────────────────────────────
    // Ogni gesto dentro `RegistroModifiche::durante()` nella transazione del lavoro: le sedute, i punti, le delibere e i convocati si contano nel lotto. I
    // compiti che il programma chiude da solo non sono righe (decisione 547 (2)): la frase sta nella risposta del compito.

    /** «Assemblea ordinaria del 15/12/2026», «Riunione del 12/03/2024 registrata a mano, verbale n. 3/2024»: il nome della riga. */
    public static function nomeDellAssemblea(\App\Models\Assemblee\Assemblea $assemblea): string
    {
        return (new Schede\AssembleaNelRegistro())->nome($assemblea);
    }

    /** Un gesto sull'assemblea, nel condominio, nella sua «Storia». */
    private static function sullAssemblea(\App\Models\Assemblee\Assemblea $assemblea): Gesto
    {
        return Gesto::su('assemblee', 'assemblea', $assemblea->id === null ? null : (int) $assemblea->id, self::nomeDellAssemblea($assemblea))
            ->nelCondominio((int) $assemblea->condominio_id);
    }

    /** «15/12/2026 alle 18:30», dall'ora come si legge. */
    public static function oraDellaSeduta(mixed $fissataIl): string
    {
        return (string) FormatoDelValore::testo($fissataIl, 'ora_letta');
    }

    /**
     * Preparare l'assemblea: «Assemblea ordinaria del 15/12/2026 preparata: 6 punti, prima convocazione 14/12/2026 alle 8:00, seconda 15/12/2026 alle 18:30»,
     * una riga sola con i punti e le sedute nel lotto. L'assemblea si conosce alla fine del lavoro: l'esito è l'assemblea.
     */
    public static function assembleaPreparata(\App\Models\Condominio $condominio): Gesto
    {
        $gesto = Gesto::su('assemblee', 'assemblea', null, 'Assemblea')->nelCondominio($condominio);

        return $gesto->riepilogo(function (Lotto $lotto, mixed $esito) use ($gesto) {
            if (! $esito instanceof \App\Models\Assemblee\Assemblea) {
                return 'Assemblea preparata';
            }
            $esito->refresh();
            $gesto->oggetto((int) $esito->id, self::nomeDellAssemblea($esito));
            $punti = $esito->punti()->count();
            $sedute = $esito->sedute()->get();
            $pezzi = [$punti === 1 ? '1 punto' : $punti . ' punti'];
            foreach ($sedute as $s) {
                $chi = $s->convocazione === \App\Enums\Assemblee\ConvocazioneSeduta::PRIMA ? 'prima convocazione' : 'seconda';
                $pezzi[] = ($s->numero > 1 ? 'prosecuzione ' : $chi . ' ') . self::oraDellaSeduta($s->getAttributes()['fissata_il'] ?? null);
            }

            return self::nomeDellAssemblea($esito) . ' preparata: ' . implode(', ', $pezzi);
        });
    }

    /** Riordinare: «Ordine del giorno riordinato: il punto 5 diventa il 4». @param list<string> $spostati le frasi dei punti spostati */
    public static function ordineRiordinato(\App\Models\Assemblee\Assemblea $assemblea): Gesto
    {
        $gesto = self::sullAssemblea($assemblea);

        return $gesto->riepilogo(fn (Lotto $lotto, mixed $esito) => 'Ordine del giorno riordinato' . (is_array($esito) && $esito !== []
            ? ': ' . implode('; ', $esito) : ''));
    }

    /** Eliminare un'assemblea in preparazione: «Assemblea ordinaria del 15/12/2026 eliminata, in preparazione: 6 punti; 2 memo tornano nella memoria». */
    public static function assembleaEliminata(\App\Models\Assemblee\Assemblea $assemblea, int $punti, int $memo): Gesto
    {
        return self::sullAssemblea($assemblea)->azione('eliminata')
            ->riepilogo(self::nomeDellAssemblea($assemblea) . ' eliminata, in preparazione: ' . ($punti === 1 ? '1 punto' : $punti . ' punti')
                . ($memo > 0 ? '; ' . ($memo === 1 ? '1 memo torna nella memoria' : $memo . ' memo tornano nella memoria') : ''));
    }

    /** «Assemblea ordinaria del 15/12/2026 tenuta su carta: 5 delibere registrate a mano, 1 punto non trattato». */
    public static function tenutaFuoriDalProgramma(\App\Models\Assemblee\Assemblea $assemblea): Gesto
    {
        // Il nome di prima del lavoro: la seduta tenuta può essere di un altro giorno, e la riga dice l'assemblea che era stata preparata.
        $nome = self::nomeDellAssemblea($assemblea);

        return self::sullAssemblea($assemblea)->riepilogo(function (Lotto $lotto, mixed $esito) use ($nome) {
            $delibere = (int) (is_array($esito) ? ($esito['delibere'] ?? 0) : 0);
            $nonTrattati = (int) (is_array($esito) ? ($esito['non_trattati'] ?? 0) : 0);

            return $nome . ' tenuta su carta: '
                . ($delibere === 1 ? '1 delibera registrata a mano' : $delibere . ' delibere registrate a mano')
                . ($nonTrattati > 0 ? ', ' . ($nonTrattati === 1 ? '1 punto non trattato' : $nonTrattati . ' punti non trattati') : '');
        });
    }

    /** «Assemblea ordinaria del 15/12/2026 annullata e riconvocata per il 22/12/2026», con il motivo nel perché. */
    public static function annullataERiconvocata(\App\Models\Assemblee\Assemblea $assemblea, string $motivo): Gesto
    {
        $nome = self::nomeDellAssemblea($assemblea);

        return self::sullAssemblea($assemblea)->perche($motivo)->riepilogo(function (Lotto $lotto, mixed $esito) use ($nome) {
            $nuova = $esito instanceof \App\Models\Assemblee\Assemblea ? $esito : null;

            return $nome . ' annullata e riconvocata' . ($nuova?->data !== null ? ' per il ' . $nuova->data->format('d/m/Y') : '');
        })->rimando(fn (Lotto $lotto, mixed $esito) => $esito instanceof \App\Models\Assemblee\Assemblea
            ? ['tipo' => 'assemblea', 'id' => (int) $esito->id, 'etichetta' => 'L\'assemblea riconvocata'] : null);
    }

    /**
     * Un gesto della sala (R10 della sala): una riga sull'assemblea, «Assemblea ordinaria del 15/12/2026: Gallo Enzo entra in sala alle 18:50», con **l'ora
     * del gesto** (`avvenuta_il`, anche quella scritta a mano dal foglio della sala) e l'ora di arrivo (`registrata_il`, quella di adesso); «registrato dal
     * foglio della sala» quando la seduta si registra dopo, dalla carta. Il motivo nel perché; le persone fra i soggetti.
     *
     * @param iterable<int> $persone
     */
    public static function dellaSala(\App\Models\Assemblee\Assemblea $assemblea, string|\Closure $frase, \DateTimeInterface $quando, bool $dalFoglio = false,
        ?string $perche = null, iterable $persone = []): Gesto
    {
        $nome = self::nomeDellAssemblea($assemblea);
        $coda = $dalFoglio ? ' · registrato dal foglio della sala' : '';

        return self::sullAssemblea($assemblea)->avvenutaIl($quando)->perche($perche)->soggettiDi('anagrafica', $persone)
            ->riepilogo($frase instanceof \Closure ? fn (Lotto $lotto, mixed $esito) => $nome . ': ' . $frase($lotto, $esito) . $coda : $nome . ': ' . $frase . $coda);
    }

    /**
     * Un gesto della sala che non è una riga (il voto prima della conferma, il segno del conflitto, la dichiarazione): le scritture dentro si contano nel lotto
     * e la riga non si scrive. La riga arriva con la conferma, con i voti nel lotto (decisione 518, una riga per lotto).
     */
    public static function dellaSalaSenzaRiga(\App\Models\Assemblee\Assemblea $assemblea): Gesto
    {
        return self::sullAssemblea($assemblea)->scriviSe(fn () => false);
    }

    /**
     * Un gesto del verbale (V15 del progetto della tappa del verbale): una riga sul verbale, nella «Storia» (e nel «Diario») dell'assemblea, «Verbale n. 42 ·
     * bozza stampata per la firma, versione 3, impronta 3f2a…». `$quando` è l'ora del gesto quando non è adesso (una versione arrivata senza rete: l'ora in cui è
     * stata scritta, e la riga ha anche l'ora di arrivo); il perché e le persone fra i soggetti come le altre righe.
     *
     * @param iterable<int> $persone
     */
    public static function delVerbale(\App\Models\Assemblee\VerbaleDellAssemblea $verbale, string|\Closure $frase, ?\DateTimeInterface $quando = null,
        ?string $perche = null, iterable $persone = []): Gesto
    {
        return Gesto::sulModello($verbale)->avvenutaIl($quando)->perche($perche)->soggettiDi('anagrafica', $persone)->riepilogo($frase);
    }

    /**
     * «Impugnata» su una delibera: «Delibera del 15/12/2026, punto 1 · impugnata il 10/01/2027», con la nota come perché. È un segno, non ferma niente; la riga sta nella scheda
     * dell'assemblea, con il rimando alla delibera.
     */
    public static function deliberaImpugnata(\App\Models\Assemblee\Delibera $delibera, string $giorno, string $nota): Gesto
    {
        return Gesto::sulModello($delibera)->perche($nota)->rimando('delibera', (int) $delibera->id, 'La delibera')
            ->riepilogo('impugnata il ' . \Carbon\Carbon::parse($giorno)->format('d/m/Y'));
    }

    /**
     * «Segna annullata dal giudice»: «annullata dal giudice il 14/03/2028», con il provvedimento come perché e le schede che la usano. È un segno: nessun conto
     * cambia, le schede lo dicono.
     */
    public static function deliberaAnnullata(\App\Models\Assemblee\Delibera $delibera, string $giorno, string $provvedimento, ?string $posti): Gesto
    {
        return Gesto::sulModello($delibera)->perche(trim($provvedimento . ($posti !== null ? '. ' . $posti : '')))
            ->rimando('delibera', (int) $delibera->id, 'La delibera')->riepilogo('annullata dal giudice il ' . \Carbon\Carbon::parse($giorno)->format('d/m/Y'));
    }

    /** «Segna revocata»: «revocata il 20/05/2027 dalla delibera del 20/05/2027, punto 6», con le schede che la usano. */
    public static function deliberaRevocata(\App\Models\Assemblee\Delibera $delibera, \App\Models\Assemblee\Delibera $da, ?string $posti): Gesto
    {
        $breve = \App\Services\Assemblee\DeliberaInParole::breve($da);

        return Gesto::sulModello($delibera)->perche($posti)->rimando('delibera', (int) $da->id, 'La delibera che revoca')
            ->riepilogo('revocata il ' . $da->deliberata_il?->format('d/m/Y') . ' dalla ' . lcfirst($breve));
    }

    /**
     * La conferma del riepilogo «Che cosa cambia nel gestionale», una riga sull'assemblea: «Che cosa cambia nel gestionale: 3 cambi applicati, 2 cose da fare,
     * 1 comunicazione da rileggere», con le righe non applicate e il perché. Le schede hanno ognuna la sua riga (il collegamento, la ratifica).
     *
     * @param list<string> $nonApplicate
     */
    public static function seguitoConfermato(\App\Models\Assemblee\Assemblea $assemblea, string $conto, array $nonApplicate): Gesto
    {
        return self::sullAssemblea($assemblea)->perche($nonApplicate === [] ? null : 'Non applicate: ' . implode('; ', $nonApplicate))
            ->riepilogo('Che cosa cambia nel gestionale: ' . $conto);
    }

    /**
     * «Che cosa ha cambiato l'assemblea» di una delibera approvata con modifiche, scritto alla conferma del riepilogo: una riga sulla delibera, con il testo fra
     * i suoi dati della persona (il campo, non il perché).
     */
    public static function modificheDellAssemblea(\App\Models\Assemblee\Delibera $delibera): Gesto
    {
        return Gesto::sulModello($delibera)->rimando('delibera', (int) $delibera->id, 'La delibera')
            ->riepilogo('Approvata con modifiche: scritto che cosa ha cambiato l\'assemblea');
    }

    /** Una risposta a una delibera respinta: «Respinta, punto 3: riproposta in assemblea» o «chiusa»; il motivo resta nella cosa da fare, fra i dati della persona. */
    public static function respintaRisposta(\App\Models\Assemblee\Delibera $delibera, string $frase, ?string $motivo): Gesto
    {
        return Gesto::sulModello($delibera)->perche($motivo)->rimando('delibera', (int) $delibera->id, 'La delibera')->riepilogo($frase);
    }

    /** «Assemblea ordinaria del 15/12/2026 convocata» (il passaggio di stato; la spedizione ha il suo gesto). */
    public static function assembleaConvocata(\App\Models\Assemblee\Assemblea $assemblea): Gesto
    {
        return self::sullAssemblea($assemblea)->riepilogo(self::nomeDellAssemblea($assemblea) . ' convocata');
    }

    /**
     * Registrare una delibera a mano: «Delibera registrata a mano: riunione del 12/03/2024, «Sostituzione dell'ascensore», approvata, verbale n. 3/2024». Con
     * la riunione e il punto nel lotto; la delibera si conosce alla fine (l'esito è la delibera).
     */
    public static function deliberaRegistrataAMano(\App\Models\Condominio $condominio): Gesto
    {
        $gesto = Gesto::su('assemblee', 'delibera', null, 'Delibera')->nelCondominio($condominio);

        return $gesto->riepilogo(function (Lotto $lotto, mixed $esito) use ($gesto) {
            if (! $esito instanceof \App\Models\Assemblee\Delibera) {
                return 'Delibera registrata a mano';
            }
            $esito->loadMissing('assemblea', 'punto');
            $gesto->oggetto((int) $esito->id, (new Schede\DeliberaNelRegistro())->nome($esito))->scheda('assemblea', (int) $esito->assemblea_id)
                ->rimando('delibera', (int) $esito->id, 'La delibera');

            return 'Delibera registrata a mano: ' . self::fraseDellaDeliberaAMano($esito);
        });
    }

    /** «riunione del 12/03/2024, «Sostituzione dell'ascensore», approvata, verbale n. 3/2024». */
    public static function fraseDellaDeliberaAMano(\App\Models\Assemblee\Delibera $delibera): string
    {
        $testo = trim(preg_replace('/\s+/u', ' ', (string) $delibera->testo) ?? '');
        if (mb_strlen($testo) > 70) {
            $testo = rtrim(mb_substr($testo, 0, 69)) . '…';
        }
        $verbale = trim((string) $delibera->assemblea?->numero_verbale);

        return 'riunione del ' . $delibera->deliberata_il?->format('d/m/Y') . ', «' . $testo . '», ' . mb_strtolower((string) $delibera->esito?->parola())
            . ($verbale !== '' ? ', verbale n. ' . $verbale : '');
    }

    /** Correggere la data di una riunione a mano: «Il giorno: 30/11/2026 → 28/11/2026», con le schede che usano le sue delibere nel perché. */
    public static function dataDellaRiunioneCorretta(\App\Models\Assemblee\Assemblea $assemblea, string $prima, string $dopo, ?string $schede): Gesto
    {
        return self::sullAssemblea($assemblea)->azione('modificata')
            ->campo('data', 'Il giorno', FormatoDelValore::testo($prima, 'data'), FormatoDelValore::testo($dopo, 'data'), $prima, $dopo)
            ->perche($schede);
    }

    /** «Delibera spostata nella riunione del 22/10/2026, verbale n. 42», nella «Storia» delle due riunioni (la riga è della delibera). */
    public static function deliberaSpostata(\App\Models\Assemblee\Delibera $delibera, \App\Models\Assemblee\Assemblea $da): Gesto
    {
        $gesto = Gesto::su('assemblee', 'delibera', (int) $delibera->id, (new Schede\DeliberaNelRegistro())->nome($delibera))
            ->nelCondominio((int) $delibera->condominio_id)->scheda('assemblea', (int) $da->id);

        return $gesto->riepilogo(function (Lotto $lotto, mixed $esito) {
            $verso = $esito instanceof \App\Models\Assemblee\Assemblea ? $esito : null;
            $verbale = trim((string) $verso?->numero_verbale);

            return 'Delibera spostata nella riunione' . ($verso?->data !== null ? ' del ' . $verso->data->format('d/m/Y') : '')
                . ($verbale !== '' ? ', verbale n. ' . $verbale : '');
        })->rimando(fn (Lotto $lotto, mixed $esito) => $esito instanceof \App\Models\Assemblee\Assemblea
            ? ['tipo' => 'assemblea', 'id' => (int) $esito->id, 'etichetta' => 'La riunione di arrivo'] : null);
    }

    /** «Delibera registrata a mano eliminata: riunione del …, «…», approvata, verbale n. …». */
    public static function deliberaAManoEliminata(\App\Models\Assemblee\Delibera $delibera): Gesto
    {
        return Gesto::su('assemblee', 'delibera', (int) $delibera->id, (new Schede\DeliberaNelRegistro())->nome($delibera))
            ->nelCondominio((int) $delibera->condominio_id)->scheda('assemblea', (int) $delibera->assemblea_id)->azione('eliminata')
            ->riepilogo('Delibera registrata a mano eliminata: ' . self::fraseDellaDeliberaAMano($delibera));
    }

    /** «Riunione del 12/03/2024 registrata a mano eliminata», senza delibere. */
    public static function riunioneAManoEliminata(\App\Models\Assemblee\Assemblea $assemblea): Gesto
    {
        return self::sullAssemblea($assemblea)->azione('eliminata')->riepilogo(self::nomeDellAssemblea($assemblea) . ' eliminata: non aveva delibere');
    }

    /**
     * Collegare, cambiare, scollegare (sulla scheda): «Delibera: nessuna → assemblea del 22/10/2026, punto 1 «Rifacimento della facciata…»», con il rimando
     * alla delibera. Una scheda senza tratto (la scrittura del giroconto, il già versato, la quota di un addebito) ha il gesto esplicito sulla sua area e il
     * suo tipo, perché `Gesto::sulModello()` per quei modelli cadrebbe nell'area del condominio con il nome della tabella (§6.1).
     */
    public static function deliberaDellaScheda(\Illuminate\Database\Eloquent\Model $scheda, string $ruolo, ?string $prima, ?string $dopo,
        ?\App\Models\Assemblee\Delibera $delibera, ?string $nota = null): Gesto
    {
        $descrizione = CatalogoDelRegistro::descrizionePer($scheda);
        if ($descrizione !== null) {
            $gesto = Gesto::sulModello($scheda);
        } else {
            $area = CatalogoDelRegistro::SOLO_GESTO[$scheda::class]['area'] ?? 'condominio';
            $gesto = Gesto::su($area, CatalogoDelRegistro::tipoDi($scheda), (int) $scheda->getKey(),
                \App\Services\Assemblee\SchedaDelCondominio::nomeDi($scheda));
            $condominio = \App\Services\Assemblee\SchedaDelCondominio::condominioDi($scheda);
            if ($condominio !== null) {
                $gesto->nelCondominio($condominio);
            }
        }
        $etichetta = $ruolo === \App\Enums\Assemblee\RuoloCollegamento::DELIBERA->value ? 'Delibera'
            : 'Delibera (' . mb_strtolower(\App\Enums\Assemblee\RuoloCollegamento::parolaDi($ruolo) ?? $ruolo) . ')';
        $gesto->azione('modificata')->campo('delibera:' . $ruolo, $etichetta, $prima, $dopo)->perche($nota);
        if ($delibera !== null) {
            $gesto->rimando('delibera', (int) $delibera->id, 'La delibera');
        }

        return $gesto;
    }

    /**
     * Ratificare (sulla fattura): «Fattura n. 45 di Idraulica Bianchi ratificata con la delibera del 15/12/2026, punto 3 (verbale n. 42); chiusi i compiti
     * «Assemblee da convocare» e «Ratifiche in assemblea»». L'esito porta i nomi dei compiti chiusi.
     */
    public static function ratifica(FatturaPassiva $fattura, \App\Models\Assemblee\Delibera $delibera): Gesto
    {
        $fornitore = $fattura->fornitore()->value('ragione_sociale');
        $nomeFattura = 'Fattura n. ' . $fattura->numero_documento . ($fornitore !== null ? ' di ' . $fornitore : '');

        return Gesto::sulModello($fattura)->riepilogo(function (Lotto $lotto, mixed $esito) use ($nomeFattura, $delibera) {
            $chiusi = is_array($esito) ? array_values(array_filter((array) ($esito['chiusi'] ?? []))) : [];
            $frase = $nomeFattura . ' ratificata con ' . mb_strtolower(mb_substr(\App\Services\Assemblee\DeliberaInParole::breve($delibera), 0, 1))
                . mb_substr(\App\Services\Assemblee\DeliberaInParole::breve($delibera), 1);
            if ($chiusi !== []) {
                $nomi = array_map(fn ($n) => '«' . $n . '»', $chiusi);
                $frase .= '; ' . (count($nomi) === 1 ? 'chiuso il compito ' . $nomi[0] : 'chiusi i compiti ' . implode(' e ', $nomi));
            }

            return $frase;
        })->rimando('delibera', (int) $delibera->id, 'La delibera');
    }

    /** La raccolta delle date, il riepilogo sul condominio: «Date scritte a mano raccolte in delibere: 3 riunioni, 6 delibere, 8 schede collegate». */
    public static function dateRaccolte(\App\Models\Condominio $condominio): Gesto
    {
        return Gesto::sulModello($condominio)->riepilogo(function (Lotto $lotto, mixed $esito) {
            $e = is_array($esito) ? $esito : [];
            $n = fn (int $q, string $una, string $tante) => $q . ' ' . ($q === 1 ? $una : $tante);

            return 'Date scritte a mano raccolte in delibere: ' . $n((int) ($e['riunioni'] ?? 0), 'riunione', 'riunioni') . ', '
                . $n((int) ($e['delibere'] ?? 0), 'delibera', 'delibere') . ', ' . $n((int) ($e['schede'] ?? 0), 'scheda collegata', 'schede collegate');
        });
    }

    // Le fabbriche della convocazione, del fascicolo e del preventivo fermato: le usa il lavoro della convocazione, con le stesse parole.

    /**
     * Spedire: «Convocazione spedita: 11 PEC, 5 lettere stampate», con i convocati fra i soggetti (l'oscuramento li trova). `$mezzi` è mezzo => quanti.
     *
     * @param array<string, int> $mezzi
     * @param iterable<int> $convocati gli id delle anagrafiche
     */
    public static function convocazioneSpedita(\App\Models\Assemblee\Assemblea $assemblea, array $mezzi, iterable $convocati): Gesto
    {
        $parole = ['pec' => ['PEC', 'PEC'], 'raccomandata' => ['lettera stampata', 'lettere stampate'], 'a_mano' => ['consegna a mano', 'consegne a mano'],
            'fax' => ['fax', 'fax']];
        $pezzi = [];
        foreach ($mezzi as $mezzo => $quanti) {
            if ($quanti > 0) {
                $pezzi[] = $quanti . ' ' . ($parole[$mezzo][$quanti === 1 ? 0 : 1] ?? $mezzo);
            }
        }

        return self::sullAssemblea($assemblea)->soggettiDi('anagrafica', $convocati)
            ->riepilogo('Convocazione spedita' . ($pezzi === [] ? '' : ': ' . implode(', ', $pezzi)))->lotto(['mezzi' => $mezzi]);
    }

    /**
     * Stampare le lettere: «Lettere della convocazione stampate: 5», con i convocati fra i soggetti. La stampa non spedisce: la convocazione parte con la
     * spedizione registrata.
     *
     * @param iterable<int> $convocati gli id delle anagrafiche
     */
    public static function lettereStampate(\App\Models\Assemblee\Assemblea $assemblea, int $quante, iterable $convocati): Gesto
    {
        return self::sullAssemblea($assemblea)->soggettiDi('anagrafica', $convocati)
            ->riepilogo($quante === 1 ? 'Lettera della convocazione stampata: 1' : 'Lettere della convocazione stampate: ' . $quante)->lotto(['stampate' => $quante]);
    }

    /** «Consegna a mano registrata: 4 persone, con il foglio firmato». @param iterable<int> $persone */
    public static function consegnaAManoRegistrata(\App\Models\Assemblee\Assemblea $assemblea, int $quante, bool $conIlFoglio, iterable $persone): Gesto
    {
        return self::sullAssemblea($assemblea)->soggettiDi('anagrafica', $persone)
            ->riepilogo('Consegna a mano registrata: ' . ($quante === 1 ? '1 persona' : $quante . ' persone') . ($conIlFoglio ? ', con il foglio firmato' : ''));
    }

    /**
     * Registrare un esito: «Raccomandata a Neri Carla: in giacenza, avviso del 07/12». Una riga per gesto, sull'assemblea, con la persona fra i soggetti: il
     * giorno che decide la validità sta così nella catena firmata (§4.4).
     */
    public static function esitoRegistrato(\App\Models\Assemblee\Assemblea $assemblea, \App\Models\Gestionale\RegistroInvio $invio, string $frase): Gesto
    {
        return self::sullAssemblea($assemblea)->soggetto('anagrafica', $invio->anagrafica_id === null ? null : (int) $invio->anagrafica_id)
            ->riepilogo($frase)->lotto(['invio_id' => (int) $invio->id, 'esito' => $invio->esito, 'ricevuta_il' => $invio->ricevuta_il?->format('Y-m-d')]);
    }

    /**
     * «Allega la prova» su un invio della convocazione: «Prova allegata alla raccomandata di Neri Carla: cartolina.jpg, impronta 7f3a…». Una riga sua, con la
     * persona fra i soggetti e il nome e l'impronta del file nel lotto: una prova cambiata dopo si vede nella catena.
     */
    public static function provaAllegata(\App\Models\Assemblee\Assemblea $assemblea, \App\Models\Gestionale\RegistroInvio $invio, string $nome, string $impronta): Gesto
    {
        $chi = (string) ($invio->dati['persona']['nome'] ?? 'la persona');
        $mezzo = ['pec' => 'alla PEC', 'raccomandata' => 'alla raccomandata', 'a_mano' => 'all\'avviso a mano', 'fax' => 'al fax'][$invio->dati['mezzo'] ?? ''] ?? 'all\'avviso';

        return self::sullAssemblea($assemblea)->soggetto('anagrafica', $invio->anagrafica_id === null ? null : (int) $invio->anagrafica_id)
            ->riepilogo('Prova allegata ' . $mezzo . ' di ' . $chi . ': ' . $nome . ', impronta ' . mb_substr($impronta, 0, 4) . '…')
            ->lotto(['invio_id_della_prova' => (int) $invio->id]);
    }

    /** Correggere un esito: «Ricevuta di Neri Carla corretta: 10/12 → 09/12», con il perché. */
    public static function esitoCorretto(\App\Models\Assemblee\Assemblea $assemblea, \App\Models\Gestionale\RegistroInvio $invio, string $frase,
        string $perche): Gesto
    {
        return self::esitoRegistrato($assemblea, $invio, $frase)->perche($perche);
    }

    /** «Preventivo 2027 fermato per l'assemblea: versione 1, impronta 7f3a…». */
    public static function preventivoFermato(\App\Models\Assemblee\Assemblea $assemblea, \App\Models\Gestionale\VersionePreventivo $versione, string $anno): Gesto
    {
        return self::sullAssemblea($assemblea)->riepilogo('Preventivo ' . $anno . ' fermato per l\'assemblea: versione ' . $versione->numero
            . ', impronta ' . mb_substr((string) $versione->impronta, 0, 4) . '…');
    }

    /** «Rendiconto 2025, versione 2, allegato al punto 1». */
    public static function rendicontoAllegato(\App\Models\Assemblee\Assemblea $assemblea, string $anno, int $versione, int $punto): Gesto
    {
        return self::sullAssemblea($assemblea)->riepilogo('Rendiconto ' . $anno . ', versione ' . $versione . ', allegato al punto ' . $punto);
    }

    /** «Fascicolo composto: 5 documenti, impronta …». */
    public static function fascicoloComposto(\App\Models\Assemblee\Assemblea $assemblea, int $documenti, string $impronta): Gesto
    {
        return self::sullAssemblea($assemblea)->riepilogo('Fascicolo composto: ' . ($documenti === 1 ? '1 documento' : $documenti . ' documenti')
            . ', impronta ' . mb_substr($impronta, 0, 4) . '…');
    }
}
