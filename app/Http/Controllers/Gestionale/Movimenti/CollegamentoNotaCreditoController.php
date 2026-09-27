<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\FattureRettificabili;
use App\Services\Gestionale\NettoNoteCollegate;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Il collegamento fra una nota di credito del fornitore e la fattura che rettifica, dopo la registrazione (Coda 165,
 * 1.11.0-beta.36; decisione 26, punto 6).
 *
 * - **Collega**: per le note già registrate senza fattura — tutte quelle di prima di questa versione, e quelle registrate
 *   dal modulo senza sceglierla. La stessa scala della registrazione: se la fattura sta in un piano che non ha incassato
 *   niente, il collegamento si rifiuta e dice la via. Nessuna deduzione: la fattura la sceglie l'amministratore.
 * - **Scollega**: sempre. Un collegamento sbagliato si corregge, e togliere una nota non fa chiedere a nessun piano più di
 *   prima: il carrello riofre la differenza.
 * - **Candidate**: l'elenco delle fatture che la nota può rettificare, con i motivi già calcolati (JSON, in sola lettura).
 *
 * withErrors e non flash per i rifiuti: in una visita Inertia il flash impostato da back() non arriva a schermo
 * (`StornoFatturaController`). Il collegamento è un dato, non una scrittura: il giornale e le compensazioni non cambiano.
 */
class CollegamentoNotaCreditoController extends Controller
{
    use HandleFlashMessages;

    public function collega(Request $request, Condominio $condominio, FatturaPassiva $fattura)
    {
        // Oltre al vincolo sulla rotta: la difesa in profondità che `RotteAnnidateSenzaGuardiaTest` pretende.
        abort_unless((int) $fattura->condominio_id === (int) $condominio->id, 404);
        $nota = $fattura;
        $dati = $request->validate([
            'fattura_rettificata_id' => [
                'required', 'integer',
                Rule::exists('fatture_passive', 'id')
                    ->where('condominio_id', $condominio->id)
                    ->where('fornitore_id', $nota->fornitore_id)
                    ->where('tipo_documento', 'fattura'),
            ],
            'conferma_avviso_nota' => ['nullable', 'boolean'],
        ], [
            'fattura_rettificata_id.required' => 'Scegli la fattura che la nota rettifica.',
            'fattura_rettificata_id.exists' => 'La fattura che la nota rettifica dev\'essere una fattura dello stesso fornitore, registrata in questo condominio.',
        ]);

        $conferma = filter_var($dati['conferma_avviso_nota'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return DB::transaction(function () use ($nota, $dati, $conferma) {
            $nota = FatturaPassiva::lockForUpdate()->findOrFail($nota->id);
            if ($motivo = $nota->motivoBloccoCollegamento()) {
                return back()->withErrors(['collega_vietato' => $motivo]);
            }

            $rettificata = FatturaPassiva::with(['pianiRate', 'noteCollegate', 'righe', 'coperture'])
                ->lockForUpdate()->findOrFail((int) $dati['fattura_rettificata_id']);
            // Le righe vere della nota (o la testata, per la nota pregressa): dove riduce, e di quanto (R1, R4).
            $righeNota = NettoNoteCollegate::righeDellaNota((int) $nota->id);
            $contestata = (is_object($nota->stato_approvazione) ? $nota->stato_approvazione->value : $nota->stato_approvazione) === 'contestata';
            if ($motivo = $rettificata->motivoBloccoNotaCollegata(abs((int) $nota->totale_documento), 'collega la nota', $righeNota, $contestata)) {
                return back()->withErrors(['collega_vietato' => $motivo]);
            }
            // Con un piano che ha incassato si collega, dopo la conferma della finestra (R3 della Fase 1-bis).
            if (! $conferma && ($avviso = $rettificata->avvisoNotaCollegata($righeNota, $contestata))) {
                return back()->withErrors(['avviso_nota' => $avviso]);
            }

            $nota->update(['fattura_rettificata_id' => $rettificata->id]);

            return back()->with($this->flashSuccess("Nota n. {$nota->numero_documento} collegata alla fattura n. {$rettificata->numero_documento}."));
        });
    }

    public function scollega(Condominio $condominio, FatturaPassiva $fattura)
    {
        abort_unless((int) $fattura->condominio_id === (int) $condominio->id, 404);
        $nota = $fattura;
        if ($nota->tipo_documento !== 'nota_credito' || $nota->fattura_rettificata_id === null) {
            return back()->withErrors(['collega_vietato' => 'Questa nota non è collegata a nessuna fattura.']);
        }

        $numero = $nota->fatturaRettificata?->numero_documento;
        $nota->update(['fattura_rettificata_id' => null]);

        return back()->with($this->flashSuccess("Nota n. {$nota->numero_documento} scollegata" . ($numero ? " dalla fattura n. {$numero}." : '.')));
    }

    public function candidate(Request $request, Condominio $condominio, FattureRettificabili $servizio): JsonResponse
    {
        $dati = $request->validate([
            'fornitore_id' => ['required', 'integer'],
            'importo_cents' => ['nullable', 'integer', 'min:0'],
            'per_collegare' => ['nullable', 'boolean'],
            // La nota già registrata («Collega a una fattura»): righe e importo si leggono dal database.
            'nota_id' => ['nullable', 'integer'],
            // Le righe della nota che si sta scrivendo nel modulo, in JSON: [{conto_id, immobile_id, riduzione}], lordo in
            // centesimi. Servono al «di quanto» e a sapere se l'avviso serve (R4 della Fase 1-bis).
            'righe' => ['nullable', 'string', 'max:20000'],
            // La fattura dichiarata dall'XML, segnata fra le candidate a ogni caricamento (R8 della Fase 1-bis).
            'numero_dichiarato' => ['nullable', 'string', 'max:50'],
            'data_dichiarata' => ['nullable', 'date_format:Y-m-d'],
            // La nota del modulo è contestata: fuori da scala e avviso (V3 della verifica).
            'contestata' => ['nullable', 'boolean'],
        ]);

        $importo = (int) ($dati['importo_cents'] ?? 0);
        $righe = null;
        $contestata = filter_var($dati['contestata'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (! empty($dati['nota_id'])) {
            $nota = FatturaPassiva::where('condominio_id', $condominio->id)->where('tipo_documento', 'nota_credito')->find((int) $dati['nota_id']);
            if ($nota !== null) {
                $righe = NettoNoteCollegate::righeDellaNota((int) $nota->id);
                $importo = abs((int) $nota->totale_documento);
                $contestata = (is_object($nota->stato_approvazione) ? $nota->stato_approvazione->value : $nota->stato_approvazione) === 'contestata';
            }
        } elseif (! empty($dati['righe'])) {
            $righe = self::righeDalModulo($dati['righe']);
        }

        return response()->json($servizio->candidati(
            $condominio->id,
            (int) $dati['fornitore_id'],
            $importo,
            filter_var($dati['per_collegare'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'collega la nota' : 'registra la nota',
            $righe,
            $dati['numero_dichiarato'] ?? null,
            $dati['data_dichiarata'] ?? null,
            $contestata,
        ));
    }

    /**
     * Le righe del modulo, lette con prudenza: un elenco di al più 200 righe con numeri interi. Quello che non torna non si
     * indovina — `null`, e i messaggi restano senza cifra.
     *
     * @return list<array{conto_id: ?int, immobile_id: ?int, riduzione: int}>|null
     */
    private static function righeDalModulo(string $json): ?array
    {
        $righe = json_decode($json, true);
        if (! is_array($righe) || $righe === [] || count($righe) > 200) {
            return null;
        }
        $esito = [];
        foreach ($righe as $r) {
            if (! is_array($r) || ! isset($r['riduzione']) || ! is_numeric($r['riduzione'])) {
                return null;
            }
            $esito[] = [
                'conto_id' => isset($r['conto_id']) && is_numeric($r['conto_id']) ? (int) $r['conto_id'] : null,
                'immobile_id' => isset($r['immobile_id']) && is_numeric($r['immobile_id']) ? (int) $r['immobile_id'] : null,
                'riduzione' => (int) $r['riduzione'],
            ];
        }

        return $esito;
    }
}
