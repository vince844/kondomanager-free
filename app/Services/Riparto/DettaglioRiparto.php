<?php

namespace App\Services\Riparto;

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RigaRiparto;
use App\Services\CalcoloQuoteService;
use App\Services\Riparto\CompetenzaDelPiano;
use Illuminate\Support\Carbon;

/**
 * Da dove le stampe del riparto prendono le righe (1.11.0-beta.29).
 *
 * Tre sorgenti, **una forma sola** di riga — la stessa di `CalcoloQuoteService::getRigheDettaglio()`:
 *
 * - `registrato`: il piano ha righe in `righe_riparto`, scritte alla generazione insieme alle
 *   quote. È la regola: la stampa legge ciò che è stato deliberato, e un contributo registrato
 *   dopo l'emissione non cambia il documento.
 * - `ricostruito`: il piano ha quote ma nessuna riga (generato prima della beta.29, o quote
 *   scritte da fuori). Il motore gira in `soloLettura` al momento della stampa e le righe sono
 *   quelle in memoria. È il ripiego, e la stampa lo **dichiara**: «ricostruito, non registrato».
 * - `anteprima`: il piano non ha quote. Stesso ricalcolo, e la stampa dice che è un'anteprima.
 *
 * Le due stampe consumano la stessa forma nei tre casi: il ripiego non è un secondo codice ma la
 * stessa lettura su una sorgente diversa. È ciò che toglie l'oggetto alla Coda 78 — due
 * aritmetiche che potevano divergere.
 */
final class DettaglioRiparto
{
    public const REGISTRATO = 'registrato';
    public const RICOSTRUITO = 'ricostruito';
    public const ANTEPRIMA = 'anteprima';

    /**
     * @return array{righe: list<array<string,mixed>>, fonte: array{tipo: string, generato_il: ?Carbon, versione: ?string}}
     */
    public static function perPiano(PianoRate $pianoRate): array
    {
        $registrate = RigaRiparto::where('piano_rate_id', $pianoRate->id)->orderBy('id')->get();

        if ($registrate->isNotEmpty()) {
            $prima = $registrate->first();

            $righe = $registrate->map(fn (RigaRiparto $r) => self::rigaDaModello($r))->all();

            return [
                'righe' => $righe,
                'fonte' => [
                    'tipo'        => self::REGISTRATO,
                    'generato_il' => $prima->created_at,
                    'versione'    => $prima->versione_calcolo,
                    // B2: registrate con un periodo se almeno una riga lo porta (decisione 15).
                    'risoluzione' => $registrate->contains(fn (RigaRiparto $r) => $r->competenza_dal !== null) ? 'temporale' : 'atemporale',
                    'competenza'  => null,
                    'competenza_non_risolta' => false,
                    'legenda'     => self::legendaCompetenza($righe),
                ],
            ];
        }

        // Il ripiego: il motore al momento della stampa, in sola lettura — la guardia di
        // sovra-finanziamento è di generazione, non di rilettura (vedi `calcolaPerGestione`).
        $haQuote = $pianoRate->rate()->whereHas('rateQuote')->exists();

        // B2, decisione 16: il ramo **ricostruito** — quote di un piano della 1.10.x, righe mai scritte —
        // resta **atemporale**, come il motore che lo ha generato, e la stampa lo dichiara. L'anteprima
        // di un piano senza quote invece deve dire ciò che la generazione farà, e la generazione è
        // temporale: stessa competenza di `GeneratePianoRateAction`, decisa in un posto solo. Se la
        // competenza non è risolvibile (straordinario senza delibera), l'anteprima non si ferma — è una
        // stampa — ma il motore, in sola lettura, va atemporale **solo dove** non può risolvere (la
        // fattura senza competenza dichiarata) e lo scrive in `fonte.competenza_non_risolta`: le fatture
        // con la competenza dichiarata restano temporali, come alla generazione (verifica S4, 19/09).
        $competenza = $haQuote ? null : app(CompetenzaDelPiano::class)->perPiano($pianoRate);

        $motore = app(CalcoloQuoteService::class);
        $gestione = $pianoRate->gestione;
        if ($pianoRate->tipo === 'straordinario' && $pianoRate->fatture()->exists()) {
            // Coda 165: il ramo ricostruito spiega le quote che esistono, e una nota collegata dopo non le ha cambiate
            // (con incassi le rate restano, per decisione): le note si applicano solo all'anteprima (R6 della Fase 1-bis).
            $motore->calcolaDaFattureStraordinarie($pianoRate, $competenza, soloLettura: true, conNoteCollegate: ! $haQuote);
        } elseif ($gestione) {
            $motore->calcolaPerGestione($gestione, $pianoRate, soloLettura: true, periodo: $competenza);
        }
        $risoluzione = $motore->getRisoluzioneTemporale();
        $righe = $motore->getRigheDettaglio();

        return [
            'righe' => $righe,
            'fonte' => [
                'tipo'        => $haQuote ? self::RICOSTRUITO : self::ANTEPRIMA,
                'generato_il' => null,
                'versione'    => null,
                // B2: come sono stati risolti i titolari in questa lettura (per la legenda di S7).
                'risoluzione' => $risoluzione['temporale'] ? 'temporale' : 'atemporale',
                'competenza'  => $competenza?->toArray(),
                'competenza_non_risolta' => $risoluzione['competenza_non_risolta'],
                'legenda'     => self::legendaCompetenza($righe),
            ],
        ];
    }

    /**
     * Ciò che la legenda delle stampe dice sulla competenza (B2, S7): i gradini che hanno deciso i periodi,
     * l'arco fra il primo `dal` e l'ultimo `al` delle righe, e quanti soggetti hanno una quota in proporzione
     * ai giorni (`giorni_titolarita` è scritto solo sulle righe pro rata: dove nessuno cambia, è nullo).
     *
     * @param list<array<string,mixed>> $righe
     * @return array{gradini: list<string>, periodo: ?array{dal: string, al: string}, soggetti_pro_rata: int}
     */
    public static function legendaCompetenza(array $righe): array
    {
        $gradini = [];
        $dal = null;
        $al = null;
        $soggetti = [];
        foreach ($righe as $r) {
            if (($r['tipo'] ?? null) !== 'riparto') {
                continue;
            }
            if (! empty($r['gradino_competenza'])) {
                $gradini[$r['gradino_competenza']] = true;
            }
            if (! empty($r['competenza_dal'])) {
                $dal = $dal === null || $r['competenza_dal'] < $dal ? $r['competenza_dal'] : $dal;
            }
            if (! empty($r['competenza_al'])) {
                $al = $al === null || $r['competenza_al'] > $al ? $r['competenza_al'] : $al;
            }
            if (($r['giorni_titolarita'] ?? null) !== null && ! empty($r['anagrafica_id'])) {
                $soggetti[(int) $r['anagrafica_id']] = true;
            }
        }

        return [
            'gradini'           => array_keys($gradini),
            'periodo'           => $dal !== null && $al !== null ? ['dal' => $dal, 'al' => $al] : null,
            'soggetti_pro_rata' => count($soggetti),
        ];
    }

    /** @return array<string,mixed> */
    private static function rigaDaModello(RigaRiparto $r): array
    {
        return [
            'tipo'              => $r->tipo,
            'anagrafica_id'     => $r->anagrafica_id,
            'immobile_id'       => $r->immobile_id,
            'conto_id'          => $r->conto_id,
            'conto_nome'        => $r->conto_nome,
            'conto_radice_id'   => $r->conto_radice_id,
            'conto_radice_nome' => $r->conto_radice_nome,
            'tabella_id'        => $r->tabella_id,
            'tabella_nome'      => $r->tabella_nome,
            'tabella_quota'     => $r->tabella_quota,
            'coefficiente'      => $r->coefficiente,
            'valore_millesimo'  => $r->valore_millesimo,
            'somma_valori'      => $r->somma_valori,
            'ruolo_richiesto'   => $r->ruolo_richiesto,
            'ruolo_risolto'     => $r->ruolo_risolto,
            'quota_possesso'    => $r->quota_possesso,
            'riga_fattura_id'   => $r->riga_fattura_id,
            'riga_descrizione'  => $r->riga_descrizione,
            'importo'           => (int) $r->importo,
            // B2 (decisione 15): il congelato temporale, nella stessa forma delle righe del motore.
            'competenza_dal'    => $r->competenza_dal?->toDateString(),
            'competenza_al'     => $r->competenza_al?->toDateString(),
            'gradino_competenza' => $r->gradino_competenza,
            'giorni_titolarita' => $r->giorni_titolarita,
            // Migrazione 11: quali giorni copre la riga, non solo quanti — il prospetto oneri li legge (B3a).
            'titolarita_dal'    => $r->titolarita_dal?->toDateString(),
            'titolarita_al'     => $r->titolarita_al?->toDateString(),
        ];
    }
}
