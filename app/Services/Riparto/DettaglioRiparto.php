<?php

namespace App\Services\Riparto;

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RigaRiparto;
use App\Services\CalcoloQuoteService;
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

            return [
                'righe' => $registrate->map(fn (RigaRiparto $r) => self::rigaDaModello($r))->all(),
                'fonte' => [
                    'tipo'        => self::REGISTRATO,
                    'generato_il' => $prima->created_at,
                    'versione'    => $prima->versione_calcolo,
                ],
            ];
        }

        // Il ripiego: il motore al momento della stampa, in sola lettura — la guardia di
        // sovra-finanziamento è di generazione, non di rilettura (vedi `calcolaPerGestione`).
        $motore = app(CalcoloQuoteService::class);
        $gestione = $pianoRate->gestione;
        if ($pianoRate->tipo === 'straordinario' && $pianoRate->fatture()->exists()) {
            $motore->calcolaDaFattureStraordinarie($pianoRate);
        } elseif ($gestione) {
            $motore->calcolaPerGestione($gestione, $pianoRate, soloLettura: true);
        }

        $haQuote = $pianoRate->rate()->whereHas('rateQuote')->exists();

        return [
            'righe' => $motore->getRigheDettaglio(),
            'fonte' => [
                'tipo'        => $haQuote ? self::RICOSTRUITO : self::ANTEPRIMA,
                'generato_il' => null,
                'versione'    => null,
            ],
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
        ];
    }
}
