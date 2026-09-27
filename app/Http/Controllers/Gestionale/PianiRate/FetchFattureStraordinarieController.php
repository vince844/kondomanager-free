<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Helpers\MoneyHelper; 
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\NettoNoteCollegate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchFattureStraordinarieController extends Controller
{
    public function __invoke(Condominio $condominio, Request $request): JsonResponse
    {
        try {
            $request->validate([
                'gestione_id'  => 'required|integer|exists:gestioni,id',
                'esercizio_id' => 'required|integer|exists:esercizi,id'
            ]);

            $esercizioId   = $request->input('esercizio_id');
            $currentPlanId = $request->input('piano_rate_id');

            // 1a. Fatture CORRENTI con sopravvenienze o spese ad personam
            $rawFatture = DB::table('fatture_passive')
                ->join('fornitori', 'fatture_passive.fornitore_id', '=', 'fornitori.id')
                ->join('righe_fattura', 'fatture_passive.id', '=', 'righe_fattura.fattura_passiva_id')
                ->where('fatture_passive.condominio_id', $condominio->id)
                ->where('fatture_passive.esercizio_id', $esercizioId)
                ->where('fatture_passive.is_pregresso', false)
                // Coda 165 (1.11.0-beta.36): solo fatture. Una nota di credito con una riga ad personam positiva (la
                // «riga in diminuzione» di una nota che rettifica una fattura con uno sconto) entrava come fabbisogno.
                ->where('fatture_passive.tipo_documento', 'fattura')
                ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
                // ⚠️ **Una fattura stornata non è più un fabbisogno da finanziare.**
                //
                // Nessuna delle due query escludeva le stornate. Misurato sulla rotta vera: dopo lo
                // storno di una pregressa da € 610,00 il carrello continuava a offrirla identica —
                // `residuo_da_finanziare: 610`, `importo_suggerito: 610` — e generando il piano
                // quei soldi venivano **addebitati ai proprietari per un documento annullato**.
                //
                // Il difetto è precedente alla beta.22, ma è la stessa strada: la .22 rende lo
                // storno di una pregressa capace di azzerare il capitolo che aveva inventato, e
                // annunciarlo lasciando il carrello a chiedere quei soldi sarebbe stato vero a
                // metà proprio sul denaro. Il filtro sta su entrambe le query perché l'omissione
                // era su entrambe: la 1a la ereditava per le straordinarie ordinarie.
                ->where('fatture_passive.stato_pagamento', '!=', 'stornata')

                ->where(function($q) {
                    $q->where('righe_fattura.is_sopravvenienza', true)
                    ->orWhereNotNull('righe_fattura.immobile_id');
                })
                ->select(
                    'fatture_passive.id',
                    'fatture_passive.numero_documento',
                    'fatture_passive.data_documento',
                    // B2, S6 (verifica R5): il carrello dice se la fattura ha la competenza dichiarata — con
                    // «Urgenza» è l'unico gradino, e senza il riparto si ferma su quella fattura. Dalla decisione 26
                    // (1.11.0-beta.35) la competenza dichiarata decide il riparto anche su una gestione ordinaria.
                    'fatture_passive.competenza_dal',
                    'fatture_passive.competenza_al',
                    'fornitori.ragione_sociale as fornitore',
                    DB::raw('SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva) as totale_straordinario')
                )
                ->groupBy(
                    'fatture_passive.id',
                    'fatture_passive.numero_documento',
                    'fatture_passive.data_documento',
                    'fatture_passive.competenza_dal',
                    'fatture_passive.competenza_al',
                    'fornitori.ragione_sociale'
                )
                ->get();

            // 1b. Fatture PREGRESSE con copertura di tipo sopravvenienza
            $rawFattureProgresse = DB::table('fatture_passive')
                ->join('fornitori', 'fatture_passive.fornitore_id', '=', 'fornitori.id')
                ->join('fattura_coperture', 'fatture_passive.id', '=', 'fattura_coperture.fattura_passiva_id')
                ->where('fatture_passive.condominio_id', $condominio->id)
                ->where('fatture_passive.esercizio_id', $esercizioId)
                ->where('fatture_passive.is_pregresso', true)
                ->where('fatture_passive.tipo_documento', 'fattura')
                ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
                // Stesso filtro della 1a, stessa ragione: vedi il commento lì sopra.
                ->where('fatture_passive.stato_pagamento', '!=', 'stornata')
                ->where('fattura_coperture.tipo_copertura', 'sopravvenienza')
                ->select(
                    'fatture_passive.id',
                    'fatture_passive.numero_documento',
                    'fatture_passive.data_documento',
                    // B2, S6 (verifica R5): il carrello dice se la fattura ha la competenza dichiarata — con
                    // «Urgenza» è l'unico gradino, e senza il riparto si ferma su quella fattura. Dalla decisione 26
                    // (1.11.0-beta.35) la competenza dichiarata decide il riparto anche su una gestione ordinaria.
                    'fatture_passive.competenza_dal',
                    'fatture_passive.competenza_al',
                    'fornitori.ragione_sociale as fornitore',
                    DB::raw('SUM(fattura_coperture.importo) as totale_straordinario')
                )
                ->groupBy(
                    'fatture_passive.id',
                    'fatture_passive.numero_documento',
                    'fatture_passive.data_documento',
                    'fatture_passive.competenza_dal',
                    'fatture_passive.competenza_al',
                    'fornitori.ragione_sociale'
                )
                ->get();

            // Merge delle due liste. Decisione 26 (1.11.0-beta.35): ogni voce sa se è una pregressa, perché la pregressa
            // senza periodo il carrello la segnala. Cosa ne fa il piano dipende dalla gestione: sull'ordinaria la divide
            // sui giorni di quest'anno, sulla straordinaria la dà a chi è titolare alla data della delibera, e con
            // «Urgenza» il riparto si ferma — in nessun caso sul periodo in cui il costo è maturato. Il testo lo sceglie
            // il carrello (`avvisoFatturaNelCarrello`), che conosce gestione e autorizzazione.
            //
            // ⚠️ Un ciclo, non `->each(fn ($f) => $f->is_pregresso = false)`: la funzione freccia restituisce il valore
            // assegnato, e `Collection::each()` si ferma al primo `false`. Solo la prima fattura corrente aveva il campo, e
            // con due correnti il carrello rispondeva «Errore interno» (trovato dopo il commit della beta.35).
            foreach ($rawFatture as $f) {
                $f->is_pregresso = false;
            }
            foreach ($rawFattureProgresse as $f) {
                $f->is_pregresso = true;
            }
            $rawFatture = $rawFatture->concat($rawFattureProgresse);

            $fattureIds = $rawFatture->pluck('id')->toArray();

            $finanziamenti = [];
            if (!empty($fattureIds)) {
                $finanziamenti = DB::table('piano_rate_fatture')
                    ->join('piani_rate', 'piano_rate_fatture.piano_rate_id', '=', 'piani_rate.id')
                    ->whereIn('fattura_passiva_id', $fattureIds)
                    ->whereIn('piani_rate.stato', ['bozza', 'approvato'])
                    ->when($currentPlanId, fn($q) => $q->where('piani_rate.id', '!=', $currentPlanId))
                    ->select('fattura_passiva_id', DB::raw('SUM(importo_collegato) as gia_finanziato'))
                    ->groupBy('fattura_passiva_id')
                    ->pluck('gia_finanziato', 'fattura_passiva_id')
                    ->toArray();
            }

            // R2 della Fase 1-bis: una pregressa registrata prima della beta.35 può avere come periodo la data
            // dell'assemblea di quest'anno — la finestra della motivazione la precompilava. Il carrello la segnala con lo
            // stesso confronto della regola di registrazione.
            $inizioEsercizio = DB::table('esercizi')->where('id', $esercizioId)->value('data_inizio');

            // Coda 165 (1.11.0-beta.36): la fattura rettificata da una nota del fornitore collegata si offre **al netto**,
            // e quella annullata per intero non si offre più. Il netto lo calcola `NettoNoteCollegate`, la stessa regola
            // di creazione del piano, ricalcolo, motore e cruscotto. Una query per tutto il carrello, non una per fattura.
            $netti = NettoNoteCollegate::perFatture($fattureIds);
            // Per le fatture con note collegate, quanto chiedono già gli altri piani lo legge la stessa funzione della
            // creazione del piano (`chiestoDaiPiani`): la somma dei pivot contava zero un piano «tutto», il carrello
            // offriva di nuovo la fattura e la creazione rispondeva «Metti al massimo € 0,00» (V4 della verifica).
            $giaPerNetti = [];
            if ($netti !== []) {
                $modelli = \App\Models\Gestionale\FatturaPassiva::with([
                    'pianiRate' => fn ($q) => $currentPlanId ? $q->where('piani_rate.id', '!=', $currentPlanId) : $q,
                    'righe', 'coperture', 'noteCollegate',
                ])->whereIn('id', array_keys($netti))->get();
                foreach ($modelli as $m) {
                    $giaPerNetti[$m->id] = $m->chiestoDaiPiani();
                }
            }

            $carrello = [];
            foreach ($rawFatture as $f) {
                // Calcolo in centesimi (Logica DB)
                $totaleCents         = (int) $f->totale_straordinario;
                $nettoCents          = isset($netti[$f->id]) ? $netti[$f->id]['netto'] : $totaleCents;
                $giaFinanziatoCents  = (int) ($giaPerNetti[$f->id] ?? $finanziamenti[$f->id] ?? 0);
                $residuoCents        = max(0, $nettoCents - $giaFinanziatoCents);

                if ($residuoCents > 0) {
                    // TRASFORMAZIONE IN EURO PER IL FRONTEND (Logica Anti-SAP: uniformità)
                    $carrello[] = [
                        'id'                    => $f->id,
                        'fornitore'             => $f->fornitore,
                        'numero_documento'      => $f->numero_documento ?? 'S/N',
                        'data_documento'        => Carbon::parse($f->data_documento)->format('d/m/Y'),
                        // Usiamo MoneyHelper::fromCents per mandare 183.00 invece di 18300
                        'totale_straordinario'  => MoneyHelper::fromCents($totaleCents),
                        'gia_finanziato'        => MoneyHelper::fromCents($giaFinanziatoCents),
                        'residuo_da_finanziare' => MoneyHelper::fromCents($residuoCents),
                        'importo_suggerito'     => MoneyHelper::fromCents($residuoCents), 
                        // Coda 165: la parte al netto delle note collegate, e le note — il carrello le nomina accanto alla
                        // fattura, perché il residuo più basso del totale non resti un numero senza spiegazione.
                        'totale_netto'          => MoneyHelper::fromCents($nettoCents),
                        'note_collegate'        => array_map(fn (array $n) => [
                            'id' => $n['id'],
                            'numero' => $n['numero'],
                            'data' => $n['data'] !== null ? Carbon::parse($n['data'])->format('d/m/Y') : null,
                            'importo' => MoneyHelper::fromCents($n['importo']),
                        ], $netti[$f->id]['note'] ?? []),
                        'selezionata'           => false,
                        'ha_competenza'         => $f->competenza_dal !== null && $f->competenza_al !== null,
                        'is_pregresso'          => (bool) $f->is_pregresso,
                        'senza_periodo'         => (bool) $f->is_pregresso && ($f->competenza_dal === null || $f->competenza_al === null),
                        'periodo_nell_esercizio' => (bool) $f->is_pregresso && $f->competenza_dal !== null && $f->competenza_al !== null
                            && FatturaPassivaService::periodoChiusoPrimaDellEsercizio($f->competenza_al, $inizioEsercizio) === false,
                        'competenza'            => $f->competenza_dal !== null && $f->competenza_al !== null
                            ? ($f->competenza_dal === $f->competenza_al
                                ? 'deliberata il ' . Carbon::parse($f->competenza_dal)->format('d/m/Y')
                                : Carbon::parse($f->competenza_dal)->format('d/m/Y') . ' – ' . Carbon::parse($f->competenza_al)->format('d/m/Y'))
                            : null,
                    ];
                }
            }

            return response()->json($carrello);

        } catch (\Exception $e) {
            Log::error('Errore fetch fatture straordinarie: ' . $e->getMessage());
            return response()->json(['error' => 'Errore interno.'], 500);
        }
    }
}