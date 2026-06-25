<?php

namespace App\Actions\PianoRate;

use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Conto;
use App\Models\Immobile;
use App\Exceptions\Gestionale\ScopertiNonAccettatiException;
use App\Services\CalcoloQuoteService;
use App\Services\Gestionale\InboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Action responsabile della generazione fisica di un Piano Rate.
 * Coordina il calcolo delle quote per immobile, la gestione dei saldi,
 * il calcolo delle scadenze e la generazione finale delle rate.
 * Integra un gatekeeper per il blocco della generazione in presenza di quote non assegnabili (scoperti).
 */
class GeneratePianoRateAction
{
    public function __construct(
        private CalcoloQuoteService $calcolatore,
        private GenerateSaldiAction $saldiAction,
        private GenerateDateRateAction $dateRateAction,
        private GenerateRateQuotesAction $rateQuotesAction,
    ) {}

    /**
     * Esegue la pipeline di generazione del Piano Rate.
     *
     * @param PianoRate $pianoRate Il piano rate da generare.
     * @param bool|null $forzaApplicazioneSaldi Se true/false forza o disabilita l'inclusione dei saldi. Se null, si usa l'impostazione della Gestione.
     * @param array $saldiConfig Array contenente la configurazione di applicazione personalizzata per i saldi.
     * @param bool $accettaScoperti Se true, ignora le quote non coperte (scoperti) e procede con la generazione.
     * @param string|null $notaScoperti Motivazione obbligatoria che giustifica la generazione con scoperti (salvata a DB).
     *
     * @throws ScopertiNonAccettatiException Se vi sono quote scoperte e $accettaScoperti è false.
     * @throws \RuntimeException Se il calcolo non produce alcuna quota (errore di configurazione tabelle/anagrafiche).
     *
     * @return array Statistiche di generazione (es: piano_rate_id, rate_create, quote_create).
     */
    public function execute(
        PianoRate $pianoRate, 
        ?bool $forzaApplicazioneSaldi = null, 
        array $saldiConfig = [],
        bool $accettaScoperti = false,
        ?string $notaScoperti = null
    ): array
    {
        $pianoRate->refresh();

        Log::info("=== GENERAZIONE PIANO RATE ===");
        Log::info("▶ Tipo Piano: " . strtoupper($pianoRate->tipo));

        $pianoRate->load(['ricorrenza', 'fatture']);

        if (!$pianoRate->relationLoaded('gestione')) {
            $pianoRate->load('gestione');
        }
        $gestione = $pianoRate->gestione;

        // =========================================================================
        // BIVIO ARCHITETTURALE
        // =========================================================================
        if ($pianoRate->tipo === 'straordinario') {
            $totaliPerImmobile = $this->calcolatore->calcolaDaFattureStraordinarie($pianoRate);
        } else {
            $totaliPerImmobile = $this->calcolatore->calcolaPerGestione($gestione, $pianoRate);
        }
        // =========================================================================

        $scoperti = $this->calcolatore->getScoperti();

        if (!empty($scoperti) && !$accettaScoperti) {
            // Arricchisce gli scoperti con nomi leggibili per la UI (singola query per tipo)
            $immobiliIds = array_unique(array_column($scoperti, 'immobile_id'));
            $contiIds    = array_unique(array_column($scoperti, 'conto_id'));

            $immobiliNomi = Immobile::whereIn('id', $immobiliIds)
                ->pluck('nome', 'id')
                ->toArray();
            $contiNomi = Conto::whereIn('id', $contiIds)
                ->pluck('nome', 'id')
                ->toArray();

            $scopertiArricchiti = array_map(fn ($s) => array_merge($s, [
                'immobile_nome' => $immobiliNomi[$s['immobile_id']] ?? 'Immobile #' . $s['immobile_id'],
                'conto_nome'    => $contiNomi[$s['conto_id']]       ?? 'Conto #'    . $s['conto_id'],
            ]), $scoperti);

            throw new ScopertiNonAccettatiException($scopertiArricchiti);
        }

        // Se accettaScoperti è true, persiste la nota e crea task inbox promemoria
        if (!empty($scoperti) && $accettaScoperti && $notaScoperti) {
            $pianoRate->nota_scoperti = $notaScoperti;
            $pianoRate->save();

            // Calcola l'importo totale degli scoperti per il messaggio
            $importoScopertiCents = array_sum(array_column($scoperti, 'importo'));
            $importoFormattato    = '€ ' . number_format($importoScopertiCents / 100, 2, ',', '.');

            // Trova il condominio attraverso la catena di relazioni
            $condominioId = $pianoRate->gestione?->esercizio?->condominio_id
                ?? $pianoRate->gestione?->esercizio?->condominio?->id;

            // Crea il task inbox — rimane aperto finché l'admin non lo chiude manualmente
            try {
                $pianoRate->loadMissing('gestione.esercizio');
                $condominioId = $pianoRate->gestione?->esercizio?->condominio_id;

                InboxService::createTask(
                    tipo: EventoTipo::SCOPERTO_DOCUMENTATO,
                    title: "Quote non assegnate — {$pianoRate->nome}",
                    description: "{$importoFormattato} in quote non assegnabili per unità senza anagrafiche attive. "
                        . "Motivazione registrata: \"{$notaScoperti}\". "
                        . "Azione: censire le anagrafiche mancanti. Il recupero avverrà con addebito manuale o a conguaglio.",
                    scadenza: now(),
                    createdByUserId: $pianoRate->created_by ?? 1,
                    condominioId: $condominioId,
                    context: [
                        'piano_rate_id'   => $pianoRate->id,
                        'piano_rate_nome' => $pianoRate->nome,
                        'importo_cents'   => $importoScopertiCents,
                    ],
                    actionUrl: '/gestionale/' . ($condominioId ?? '') . '/esercizi/' . ($pianoRate->gestione?->esercizio?->id ?? '') . '/piani-rate/' . $pianoRate->id,
                    priorita: 'alta'
                );

                Log::info("GeneratePianoRate: Task inbox SCOPERTO_DOCUMENTATO creato per piano ID={$pianoRate->id}, importo={$importoScopertiCents} cents.");
            } catch (\Throwable $e) {
                // Non bloccare la generazione se il task inbox fallisce
                Log::warning("GeneratePianoRate: Impossibile creare task inbox per scoperto documentato.", [
                    'piano_rate_id' => $pianoRate->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        // =========================================================================
        // GUARD PRE-GENERAZIONE (fail-fast)
        // Blocca la generazione se il calcolo ha prodotto zero soggetti/importi.
        // Evita che il piano venga salvato come "completato" con quote_create=0
        // mascherando un errore di configurazione (millesimi mancanti, anagrafiche
        // non configurate, tabelle millesimali vuote).
        // =========================================================================
        if (empty($totaliPerImmobile)) {
            Log::error("GeneratePianoRate: GUARD — totaliPerImmobile vuoto per piano_rate_id={$pianoRate->id}. Generazione bloccata.", [
                'piano_rate_id' => $pianoRate->id,
                'tipo'          => $pianoRate->tipo,
                'gestione_id'   => $gestione->id,
            ]);

            throw new \RuntimeException(
                "Impossibile generare il piano rate: nessuna quota calcolata. " .
                "Verificare che (1) le tabelle millesimali abbiano i millesimi inseriti per ogni immobile, " .
                "(2) ogni immobile abbia almeno un condòmino attivo associato, " .
                "(3) ogni voce di spesa sia collegata a una tabella millesimale."
            );
        }
        // =========================================================================

        // 3. GESTIONE SALDI
        $flagDb   = $gestione->fresh()->saldo_applicato;
        $applicare = $forzaApplicazioneSaldi !== null ? $forzaApplicazioneSaldi : $flagDb;

        if ($pianoRate->tipo === 'straordinario') {
            Log::info("▶ Piano Straordinario: Saldi ignorati di default (salvo override esplicito).");
        }

        if ($applicare) {
            $saldi = $this->saldiAction->execute($pianoRate, $gestione, $saldiConfig);
            Log::info("Generazione: Saldi INCLUSI (" . count($saldi) . " anagrafiche)");
        } else {
            $saldi = [];
            Log::info("Generazione: Saldi ESCLUSI (Array vuoto)");
        }

        // 4. Generazione Date
        $dateRate = $this->dateRateAction->execute($pianoRate, $gestione);

        // 5. Creazione Rate Fisiche
        $stats = $this->rateQuotesAction->execute(
            $pianoRate,
            $totaliPerImmobile,
            $dateRate,
            $saldi
        );

        // =========================================================================
        // AUTO-CHIUSURA TASK "EMISSIONE RATA SOPRAVVENIENZA"
        // =========================================================================
        try {
            Log::info("▶ START AUTO-CHIUSURA INBOX per Piano Rate ID: {$pianoRate->id}");

            if ($pianoRate->tipo === 'straordinario' && $pianoRate->fatture->isNotEmpty()) {
                $fattureIds = $pianoRate->fatture->pluck('id')->toArray();
            } else {
                if (!$pianoRate->relationLoaded('capitoli')) {
                    $pianoRate->load('capitoli');
                }
                $capitoliIds = $pianoRate->capitoli->pluck('id')->toArray();

                $fattureIds = DB::table('fattura_coperture')
                    ->where('tipo_copertura', 'sopravvenienza')
                    ->whereIn('conto_id', $capitoliIds)
                    ->pluck('fattura_passiva_id')
                    ->unique()
                    ->toArray();
            }

            if (!empty($fattureIds)) {
                $eventiChiusi = 0;

                foreach ($fattureIds as $fattId) {
                    $evento = Evento::where('meta->type', 'emissione_rata_sopravvenienza')
                        ->where('meta->context->fattura_id', $fattId)
                        ->where('meta->requires_action', true)
                        ->first();

                    if ($evento) {
                        $meta = $evento->meta;
                        $meta['requires_action'] = false;
                        $meta['is_completed']    = true;
                        $evento->meta = $meta;
                        $evento->save();
                        $eventiChiusi++;
                    }
                }

                Log::info("AUTO-CHIUSURA INBOX completata. Task risolti: {$eventiChiusi}");
            }
        } catch (\Exception $e) {
            Log::error("Errore Auto-chiusura Inbox: " . $e->getMessage());
        }
        // =========================================================================

        return array_merge([
            'piano_rate_id' => $pianoRate->id,
            'rate_create'   => $stats['rate_create'] ?? count($dateRate),
        ], $stats);
    }
}
