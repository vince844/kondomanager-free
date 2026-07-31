<?php

namespace App\Actions\PianoRate;

use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Conto;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Exceptions\Gestionale\ScopertiNonAccettatiException;
use App\Services\CalcoloQuoteService;
use App\Services\Gestionale\InboxService;
use App\Services\Gestionale\SaldoEsercizioService;
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
        private SaldoEsercizioService $saldoService,
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

        // Eccedenze del netting "già versato" (beta.26): unità che avevano versato
        // più del dovuto per una voce. Non bloccano la generazione — è denaro dei
        // condòmini, non un errore — ma resta nei log soltanto (invisibile
        // all'amministratore) finché non apriamo anche un task Inbox, sullo
        // stesso pattern usato qui sotto per gli scoperti.
        $eccedenze = $this->calcolatore->getEccedenzeCopertura();
        if (!empty($eccedenze)) {
            $totaleEccedenzeCents = array_sum(array_column($eccedenze, 'eccedenza'));

            Log::warning("GeneratePianoRate: eccedenze di copertura rilevate — versato più del dovuto, da restituire o conguagliare.", [
                'piano_rate_id' => $pianoRate->id,
                'totale_cents'  => $totaleEccedenzeCents,
                'unita'         => count($eccedenze),
            ]);

            try {
                // REVISIONE AVVERSARIALE: senza questo controllo, ogni "Ricalcola"
                // di un piano rate ancora in bozza (rotta .regenerate, nessun
                // vincolo sul numero di rigenerazioni) apriva un NUOVO task
                // identico per la stessa eccedenza mai risolta — stesso principio
                // di AUTO-CHIUSURA più sotto in questo file, che infatti controlla
                // prima di agire.
                $eventoEsistente = Evento::where('meta->type', EventoTipo::ECCEDENZA_GIA_VERSATO_RILEVATA->value)
                    ->where('meta->context->piano_rate_id', $pianoRate->id)
                    ->where('meta->requires_action', true)
                    ->exists();

                if (! $eventoEsistente) {
                    // condominio_id è una colonna diretta su piani_rate — più
                    // affidabile della catena gestione->esercizio (Gestione ha solo
                    // esercizi(), plurale: un ->esercizio singolare non esiste).
                    $condominioIdEccedenze = $pianoRate->condominio_id;
                    // REVISIONE AVVERSARIALE: senza fallback, una gestione senza
                    // alcun esercizio con pivot attiva=true produceva un segmento
                    // vuoto nell'URL ("/esercizi//piani-rate/12") — 404 al click.
                    // Stesso fallback già usato da PianoRateQuoteService.
                    $esercizioAttivoId = $pianoRate->gestione
                        ?->esercizi()->wherePivot('attiva', true)->value('esercizi.id')
                        ?? $pianoRate->gestione?->esercizi()->first()?->id;

                    $immobiliNomiEcc = Immobile::whereIn('id', array_column($eccedenze, 'immobile_id'))
                        ->pluck('nome', 'id')
                        ->toArray();
                    $dettaglioUnita = collect($eccedenze)
                        ->map(fn ($e) => ($immobiliNomiEcc[$e['immobile_id']] ?? "Immobile #{$e['immobile_id']}")
                            .': € '.number_format($e['eccedenza'] / 100, 2, ',', '.'))
                        ->implode(', ');

                    InboxService::createTask(
                        tipo: EventoTipo::ECCEDENZA_GIA_VERSATO_RILEVATA,
                        title: "Eccedenza già-versato — {$pianoRate->nome}",
                        description: '€ '.number_format($totaleEccedenzeCents / 100, 2, ',', '.')
                            .' versati in più del dovuto su '.count($eccedenze).' unità, per una o più voci con '
                            .'già-versato registrato. La quota non è mai andata sotto zero, ma questo denaro dei '
                            .'condòmini resta da restituire o conguagliare a fine gestione. Dettaglio: '.$dettaglioUnita,
                        scadenza: now(),
                        createdByUserId: $pianoRate->created_by ?? 1,
                        condominioId: $condominioIdEccedenze,
                        context: [
                            'piano_rate_id' => $pianoRate->id,
                            'piano_rate_nome' => $pianoRate->nome,
                            'totale_cents' => $totaleEccedenzeCents,
                            'eccedenze' => $eccedenze,
                        ],
                        actionUrl: '/gestionale/'.($condominioIdEccedenze ?? '').'/esercizi/'
                            .($esercizioAttivoId ?? '').'/piani-rate/'.$pianoRate->id,
                        priorita: 'normale'
                    );
                }
            } catch (\Throwable $e) {
                // Non blocca la generazione se il task inbox fallisce — stesso
                // principio usato sotto per lo scoperto documentato.
                Log::warning("GeneratePianoRate: Impossibile creare task inbox per eccedenza già-versato.", [
                    'piano_rate_id' => $pianoRate->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

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

            $dettaglio = $pianoRate->tipo === 'straordinario'
                ? "Per i piani straordinari verificare che ogni fattura collegata abbia importo finanziato (> 0) e "
                    . "componenti straordinari validi: righe imprevisto/ad personam per le fatture correnti, "
                    . "oppure una copertura di tipo 'sopravvenienza' agganciata a un capitolo con tabella millesimale "
                    . "per le fatture pregresse."
                : "Verificare che (1) le tabelle millesimali abbiano i millesimi inseriti per ogni immobile, "
                    . "(2) ogni immobile abbia almeno un condòmino attivo associato, "
                    . "(3) ogni voce di spesa sia collegata a una tabella millesimale.";

            throw new \RuntimeException(
                "Impossibile generare il piano rate: nessuna quota calcolata. " . $dettaglio
            );
        }
        // =========================================================================

        // 3. GESTIONE SALDI
        //
        // Ordine di precedenza, dal più esplicito al più debole:
        //   a) l'override passato dal chiamante (creazione del piano);
        //   b) il piano possiede già dei saldi — un ricalcolo non può perderli
        //      per strada, è esattamente il bug del "lucchetto orfano";
        //   c) i piani straordinari non assorbono pregressi se nessuno lo chiede
        //      esplicitamente: è ciò che il log qui sotto dichiara da sempre, ma
        //      che senza questo ramo non era vero per i piani generati in differita;
        //   d) la scelta persistita alla creazione (piani generati più tardi);
        //   e) il vecchio flag di gestione, per i piani antecedenti alla beta.32.
        $possiedeSaldi = Saldo::where('piano_rate_id', $pianoRate->id)->exists();

        $applicare = match (true) {
            $forzaApplicazioneSaldi !== null      => $forzaApplicazioneSaldi,
            $possiedeSaldi                        => true,
            $pianoRate->tipo === 'straordinario'  => false,
            $pianoRate->applica_saldi !== null    => (bool) $pianoRate->applica_saldi,
            default                               => (bool) $gestione->fresh()->saldo_applicato,
        };

        if ($pianoRate->tipo === 'straordinario') {
            Log::info("▶ Piano Straordinario: Saldi ignorati di default (salvo override esplicito).");
        }

        if ($applicare) {
            // La ripartizione manuale dei saldi solidali (Art. 63) arriva dal form
            // solo alla creazione. Senza il fallback su quanto persistito, ogni
            // ricalcolo la sostituirebbe in silenzio con il riparto pro-quota
            // automatico: stessi soggetti, importi diversi, nessun avviso.
            $configEffettiva = !empty($saldiConfig)
                ? $saldiConfig
                : ($pianoRate->saldi_config ?? []);

            $saldi = $this->saldiAction->execute($pianoRate, $gestione, $configEffettiva);
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

        // 6. LUCCHETTO SALDI
        // Chi genera le quote è anche l'unico che chiude (o riapre) il lucchetto,
        // e lo fa a proprio nome: così lo stato del wallet non può divergere da
        // ciò che il piano contiene davvero.
        if ($gestione) {
            [$saldiConsumatiIds, $totaleSaldi] = $this->riepilogaSaldiConsumati($saldi);

            $this->saldoService->sincronizzaLucchetti(
                $pianoRate,
                $gestione,
                $saldiConsumatiIds,
                empty($saldiConsumatiIds) ? null : $totaleSaldi
            );
        }

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

    /**
     * Estrae dalla distribuzione le righe della tabella `saldi` realmente
     * consumate e il loro totale netto in centesimi.
     *
     * @param array $saldi Formato di GenerateSaldiAction: [anagrafica][immobile] => ['importo', 'meta_storico']
     * @return array{0: int[], 1: int}
     */
    private function riepilogaSaldiConsumati(array $saldi): array
    {
        $ids = [];
        $totale = 0;

        foreach ($saldi as $immobiliSaldi) {
            foreach ($immobiliSaldi as $dati) {
                // Una coppia che si azzera (un debito e un credito che si
                // compensano) non produce alcuna quota: GenerateRateQuotesAction
                // la salta. Bloccare quei saldi renderebbe falsa l'invariante
                // "intestati = finiti nelle quote" su cui poggia lo sblocco.
                if ((int) ($dati['importo'] ?? 0) === 0) {
                    continue;
                }

                $totale += (int) $dati['importo'];

                foreach ($dati['meta_storico'] ?? [] as $meta) {
                    if (!empty($meta['saldo_origine_id'])) {
                        $ids[] = (int) $meta['saldo_origine_id'];
                    }
                }
            }
        }

        return [array_values(array_unique($ids)), $totale];
    }
}
