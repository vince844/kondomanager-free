<?php

namespace App\Services\Gestionale;

use App\Enums\MetodoPagamento;
use App\Enums\StatoPagamentoFattura;
use App\Enums\StatoPagamentoFornitore;
use App\Enums\TipoAllocazioneFattura;
use App\Enums\TipoDetrazione;
use App\Enums\TipoMovimentoContabile;
use App\Events\Gestionale\PagamentoRegistrato;
use App\Events\Gestionale\PagamentoStornato;
use App\Exceptions\Pagamenti\AllocazioniInconsistentiException;
use App\Exceptions\Pagamenti\FatturaNonApprovataException;
use App\Exceptions\Pagamenti\FiscalYearClosedException;
use App\Exceptions\Pagamenti\IbanDiscrepanzaException;
use App\Exceptions\Pagamenti\IllegalCashAmountException;
use App\Exceptions\Pagamenti\InsufficientFundsException;
use App\Exceptions\Pagamenti\NessunEsercizioApertoException;
use App\Exceptions\Pagamenti\OverpaymentException;
use App\Exceptions\Pagamenti\PagamentoGiaStornatoException;
use App\Exceptions\Pagamenti\PossibilePagamentoDuplicatoException;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\FatturaScrittura;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Servizio per la gestione dei pagamenti ai fornitori (v1.9.1).
 *
 * Responsabilità:
 *  - Registrare pagamenti singoli, cumulativi e con netting NC
 *  - Stornare pagamenti (con supporto storno cross-esercizio Variante B1)
 *  - Ricalcolare lo stato_pagamento delle fatture dalla pivot (detection sistemica)
 *  - Generare causali bonifico standard e parlante (detrazioni fiscali)
 *  - Verificare capienza conto e trovare NC compensabili
 *
 * Principi architetturali rispettati:
 *  - Ledger-centric: il giornale è immutabile, storni = scritture inverse
 *  - Lock pessimistico con orderBy('id') per prevenire deadlock
 *  - Idempotency key con wrapper try/catch per race condition production-grade
 *  - BigInteger cents ovunque, nessun float
 *  - cassa_id popolato su tutte le righe di liquidità (coerenza con incassi)
 *  - DoubleEntryValidator::validateOrFail() dopo ogni inserimento righe
 *  - ricalcolaStatoFattura() è detection, non validation: non lancia mai eccezioni
 *
 * Cosa NON fa questo service (rimandato):
 *  - Acconti, anticipi admin, assegni, RID/SDD → v1.9.2
 *  - Netting NC > Fattura (compensazione pura senza cassa) → v1.9.2
 *  - Riconciliazione bancaria → v1.16
 *  - Scoring completo duplicati (CRO/TRN + 7gg) → v1.16
 */
class PagamentoFornitoreService
{
    // ════════════════════════════════════════════════════════════════════════
    // INTERFACCIA PUBBLICA
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Registra un pagamento fornitore (singolo, cumulativo o con netting NC).
     *
     * Input array — campi attesi:
     *   fornitore_id            int       Fornitore beneficiario
     *   condominio_id           int       Condominio
     *   esercizio_id            int       Esercizio DELLA SCRITTURA (non della fattura)
     *   gestione_id             int|null  Gestione (opzionale: derivata dalla prima fattura)
     *   conto_corrente_id       int       Conto banca/cassa da addebitare
     *   data_pagamento          string    Data disposizione (Y-m-d)
     *   metodo_pagamento        string    Valore MetodoPagamento enum
     *   allocazioni             array     [{fattura_id, tipo, importo_allocato_cents}]
     *   importo_lordo_cents     int       Importo lordo snapshot (default: Σ pagamenti)
     *   importo_ritenuta_cents  int       Ritenuta d'acconto (default: 0)
     *   importo_netto_cents     int       Netto bonificato (default: lordo - ritenuta)
     *   importo_commissioni_cents int     Commissioni bancarie (default: 0)
     *   iban_beneficiario       string|null IBAN destinatario
     *   causale_bonifico        string|null Override causale (null = auto-generata)
     *   riferimento_bancario    string|null CRO/TRN da valorizzare post-conferma banca
     *   bonifico_parlante       bool      Attiva Bonifico Parlante fiscale
     *   tipo_detrazione         string|null Valore TipoDetrazione enum (req. se parlante)
     *   beneficiari_detrazione  array|null CF condòmini beneficiari
     *   idempotency_key         string|null UUID per retry sicuro
     *   allow_overpayment       bool      Override overpayment (default: false)
     *   allow_overdraft         bool      Override capienza conto (default: false)
     *   iban_confermato_manualmente bool  Bypass sentinella IBAN (default: false)
     *   conferma_duplicato_verificato bool Bypass warning duplicato (default: false)
     *
     * @throws FiscalYearClosedException
     * @throws AllocazioniInconsistentiException
     * @throws FatturaNonApprovataException
     * @throws OverpaymentException
     * @throws IllegalCashAmountException
     * @throws InsufficientFundsException
     * @throws IbanDiscrepanzaException
     * @throws PossibilePagamentoDuplicatoException
     */
    public function registraPagamento(array $data): PagamentoFornitore
    {
        try {
            return DB::transaction(function () use ($data) {

                // ── Step 0: Lock pessimistico ────────────────────────────────
                // orderBy('id') CRITICO: ordine deterministico per prevenire deadlock.
                // Due transazioni che acquisiscono lock sulle stesse fatture in ordine
                // inverso causano deadlock MySQL. L'ordinamento garantisce che il lock
                // venga sempre acquisito nello stesso ordine globale.
                // ⚠️ SQLite ignora lockForUpdate() silenziosamente — i test di concorrenza
                // su SQLite in-memory non validano il lock reale.
                $fattureIds = collect($data['allocazioni'])
                    ->pluck('fattura_id')
                    ->unique()
                    ->sort()
                    ->values();

                $fatture = FatturaPassiva::whereIn('id', $fattureIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($fatture->count() !== $fattureIds->count()) {
                    $mancanti = $fattureIds->diff($fatture->keys())->implode(', ');
                    throw new \RuntimeException("Fatture non trovate: [{$mancanti}].");
                }

                // ── Step 1: Idempotency check (early return su replay) ───────
                // SPOSTATO PRIMA DELLA VALIDAZIONE: se è un replay di un pagamento
                // già andato a buon fine, il residuo sarà 0. Se validassimo prima,
                // scatterebbe un falso OverpaymentException.
                if ($key = $data['idempotency_key'] ?? null) {
                    $esistente = ScritturaContabile::where('idempotency_key', $key)->first();
                    if ($esistente) {
                        return $esistente->pagamentoFornitore;
                    }
                }

                // ── Step 2: Validazioni cross-field sui dati ora blindati ────
                $this->validaInput($data, $fatture);

                // ── Step 3: Calcolo importi aggregati ────────────────────────
                $commissioni = (int) ($data['importo_commissioni_cents'] ?? 0);
                $totali = $this->calcolaImporti($data['allocazioni'], $fatture, $commissioni);

                // ── Step 4: Risoluzione conti + cassa ────────────────────────
                $condominioId = (int) $data['condominio_id'];
                $contoDebiti  = $this->trovaConto($condominioId, 'debiti_fornitori');

                // cassa_id: coerenza con il pattern di tutti gli altri movimenti di cassa
                // (StoreIncassoRateAction, FatturaPassivaService usano sempre cassa_id).
                // Il valore viene derivato dal conto_contabile_id selezionato dall'utente.
                $cassaId = Cassa::where('conto_contabile_id', $data['conto_corrente_id'])
                    ->value('id');

                // ── Step 5: Crea scrittura contabile ─────────────────────────
                $fornitore  = Fornitore::with('referenti')->findOrFail($data['fornitore_id']);
                $anagraficaPrincipale = $fornitore->referenti()->first();

                // gestione_id: fatture_passive NON ha gestione_id come colonna.
                // Si deriva dalla scrittura di competenza della fattura (tipo='competenza'),
                // esattamente come fa StornoFatturaController.
                // Tutte le fatture del batch devono avere la stessa gestione —
                // la validaInput() garantisce già coerenza di fornitore/condominio,
                // ma qui aggiungiamo anche la gestione per sicurezza.
                $gestioneId = $data['gestione_id']
                    ?? $this->deriveGestioneId($fatture);

                $idempotencyKey = (string) ($data['idempotency_key'] ?? Str::uuid());

                $scrittura = ScritturaContabile::create([
                    'condominio_id'      => $condominioId,
                    'gestione_id'        => $gestioneId,
                    'esercizio_id'       => $data['esercizio_id'],
                    'data_registrazione' => $data['data_pagamento'],
                    'data_competenza'    => $data['data_pagamento'],
                    'causale'            => $this->generaCausaleScrittura($fornitore, $fatture),
                    'tipo_movimento'     => TipoMovimentoContabile::PAGAMENTO_FORNITORE,
                    'stato'              => 'registrata',
                    'created_by'         => Auth::id(),
                    'idempotency_key'    => $idempotencyKey,
                ]);

                // ── Step 6: Righe in partita doppia ──────────────────────────
                //
                // SCHEMA QUADRATURA (anche per netting):
                //
                //   DARE  Debiti v/Fornitori  = totaleSuFatture   ← chiude debito FT
                //   DARE  Spese Bancarie      = commissioni       ← se > 0
                //   AVERE Banca/Cassa         = uscitaCassa       ← cash out effettivo
                //   AVERE Debiti v/Fornitori  = totaleSuNC        ← consuma credito NC
                //
                // Verifica:
                //   DARE  = totaleSuFatture + commissioni
                //   AVERE = uscitaCassa + totaleSuNC
                //         = (totalePagamento + commissioni) + totaleSuNC
                //   Quadra perché totaleSuFatture = totalePagamento + compensazioni_su_FT
                //              e totaleSuNC       = compensazioni_su_NC = compensazioni_su_FT
                //   → DARE − AVERE = 0  ✓
                //
                // Esempio netting FT=1000 + NC=200 → bonifico=800:
                //   DARE  Debiti  1000  (totaleSuFatture: 800 pag + 200 comp)
                //   AVERE Banca    800  (uscitaCassa: solo i pagamento)
                //   AVERE Debiti   200  (totaleSuNC: compensazione NC)

                // DARE: Debiti v/Fornitori (chiusura debito da fatture)
                if ($totali['totaleSuFatture'] > 0) {
                    $scrittura->righe()->create([
                        'conto_contabile_id' => $contoDebiti->id,
                        'tipo_riga'          => 'dare',
                        'importo'            => $totali['totaleSuFatture'],
                        'anagrafica_id'      => $anagraficaPrincipale?->id,
                    ]);
                }

                // DARE: Spese Bancarie (commissioni — se presenti)
                if ($totali['commissioni'] > 0) {
                    $contoSpese = $this->trovaConto($condominioId, 'spese_bancarie');
                    $scrittura->righe()->create([
                        'conto_contabile_id' => $contoSpese->id,
                        'tipo_riga'          => 'dare',
                        'importo'            => $totali['commissioni'],
                    ]);
                }

                // AVERE: Banca / Cassa (uscita cash totale)
                // cassa_id è essenziale per coerenza con StoreIncassoRateAction
                // e per la futura riconciliazione bancaria (v1.16).
                if ($totali['uscitaCassa'] > 0) {
                    $scrittura->righe()->create([
                        'conto_contabile_id' => $data['conto_corrente_id'],
                        'cassa_id'           => $cassaId,
                        'tipo_riga'          => 'avere',
                        'importo'            => $totali['uscitaCassa'],
                    ]);
                }

                // AVERE: Debiti v/Fornitori (consumo credito da note di credito)
                // anagrafica_id: coerenza con FatturaPassivaService che lo passa
                // sulla riga AVERE Debiti per tracciabilità nei report per fornitore.
                if ($totali['totaleSuNC'] > 0) {
                    $scrittura->righe()->create([
                        'conto_contabile_id' => $contoDebiti->id,
                        'tipo_riga'          => 'avere',
                        'importo'            => $totali['totaleSuNC'],
                        'anagrafica_id'      => $anagraficaPrincipale?->id,
                    ]);
                }

                // ── Step 7: Verifica quadratura partita doppia (fail-fast) ───
                // Usa DoubleEntryValidator esistente — stessa logica di FatturaPassivaService.
                DoubleEntryValidator::validateOrFail($scrittura->id);

                // ── Step 8: Record pivot fattura_scrittura ────────────────────
                // Usa attach() per coerenza con FatturaPassivaService (stesso pattern).
                foreach ($data['allocazioni'] as $alloc) {
                    $fatture[(int) $alloc['fattura_id']]->scritture()->attach($scrittura->id, [
                        'tipo'             => TipoAllocazioneFattura::from($alloc['tipo'])->value,
                        'importo_allocato' => (int) $alloc['importo_allocato_cents'],
                    ]);
                }

                // Incrementa change counter: token per invalidazione cache/UI.
                // NON è optimistic lock — serve solo per sapere che la fattura è cambiata.
                FatturaPassiva::whereIn('id', $fattureIds)->increment('versione_allocazioni');

                // ── Step 9: Record PagamentoFornitore (1:1 con scrittura) ────
                // Snapshot fornitore: immutabile per audit fiscale storico (CU, 770).
                // Anche se l'anagrafica viene modificata in futuro, questo record
                // conserva i dati al momento del pagamento.
                
                $importoRitenuta = (int) ($data['importo_ritenuta_cents'] ?? 0);
                
                // Se non fornita esplicitamente, la calcoliamo pro-quota sulle fatture pagate
                if ($importoRitenuta === 0) {
                    foreach ($data['allocazioni'] as $alloc) {
                        if ($alloc['tipo'] === TipoAllocazioneFattura::PAGAMENTO->value) {
                            $f = $fatture[(int) $alloc['fattura_id']];
                            if (($f->importo_ritenuta ?? 0) > 0 && ($f->netto_a_pagare ?? 0) > 0) {
                                $ratio = min($alloc['importo_allocato_cents'] / $f->netto_a_pagare, 1);
                                $importoRitenuta += (int) round($f->importo_ritenuta * $ratio);
                            }
                        }
                    }
                }

                $importoNetto    = (int) ($data['importo_netto_cents'] ?? $totali['totalePagamento']);
                $importoLordo    = (int) ($data['importo_lordo_cents'] ?? ($importoNetto + $importoRitenuta));

                $causaleBonifico = $data['causale_bonifico'] ?? $this->generaCausaleBonifico(
                    $fatture->first(),
                    (bool) ($data['bonifico_parlante'] ?? false),
                    isset($data['tipo_detrazione'])
                        ? TipoDetrazione::from($data['tipo_detrazione'])
                        : null,
                    $data['beneficiari_detrazione'] ?? []
                );

                $pagamento = PagamentoFornitore::create([
                    'uuid'                   => (string) Str::uuid(),
                    'scrittura_contabile_id' => $scrittura->id,
                    'condominio_id'          => $condominioId,
                    'fornitore_id'           => (int) $data['fornitore_id'],
                    'conto_corrente_id'      => (int) $data['conto_corrente_id'],
                    'importo_lordo'          => $importoLordo,
                    'importo_ritenuta'       => $importoRitenuta,
                    'importo_netto'          => $importoNetto,
                    'importo_commissione'    => $totali['commissioni'],
                    'metodo_pagamento'       => MetodoPagamento::from($data['metodo_pagamento']),
                    'data_pagamento'         => $data['data_pagamento'],
                    'data_valuta'            => $data['data_valuta'] ?? null,
                    'iban_beneficiario'      => $data['iban_beneficiario'] ?? null,
                    'causale_bonifico'       => $causaleBonifico,
                    'riferimento_bancario'   => $data['riferimento_bancario'] ?? null,
                    'bonifico_parlante'      => (bool) ($data['bonifico_parlante'] ?? false),
                    'tipo_detrazione'        => isset($data['tipo_detrazione'])
                        ? TipoDetrazione::from($data['tipo_detrazione'])
                        : null,
                    'beneficiari_detrazione' => $data['beneficiari_detrazione'] ?? null,
                    'stato'                  => StatoPagamentoFornitore::CONFERMATO,
                    'fornitore_snapshot'     => $this->costruisciSnapshot($fornitore),
                    'user_id'                => Auth::id(),
                    'idempotency_key'        => $idempotencyKey,
                    'note_override'          => $data['nota_override'] ?? null,
                ]);

                // ── Audit trail override ─────────────────────────────────────
                // Se l'admin ha proceduto bypassando un blocco (saldo insufficiente,
                // overpayment), la motivazione viene loggata strutturata per garantire
                // tracciabilità anche dopo mesi dall'operazione.
                if (! empty($data['nota_override'])) {
                    Log::info('Override pagamento fornitore con nota audit.', [
                        'pagamento_id'    => $pagamento->id,
                        'condominio_id'   => $condominioId,
                        'fornitore_id'    => (int) $data['fornitore_id'],
                        'user_id'         => Auth::id(),
                        'allow_overdraft' => (bool) ($data['allow_overdraft'] ?? false),
                        'allow_overpayment' => (bool) ($data['allow_overpayment'] ?? false),
                        'nota_override'   => $data['nota_override'],
                        'importo_cents'   => $totali['uscitaCassa'],
                    ]);
                }

                // ── Step 10: Ricalcola stato pagamento su tutte le fatture ───
                foreach ($fatture as $fattura) {
                    $this->ricalcolaStatoFattura($fattura);
                }

                // ── Step 11: Dispatch evento (after commit) ──────────────────
                // I listener usano public bool $afterCommit = true per non tenere
                // il lock pessimistico attivo durante elaborazioni pesanti.
                event(new PagamentoRegistrato($pagamento));

                return $pagamento;
            });

        } catch (QueryException $e) {
            // Race condition su idempotency_key: due request simultanee con stessa key.
            // Il SELECT al step 2 non basta — due goroutine possono passare entrambe
            // prima che una inserisca. La UNIQUE violation è il vero guard finale.
            // Non è un errore: è un replay sicuro — restituiamo il pagamento già creato.
            if ($this->isIdempotencyKeyViolation($e, $data['idempotency_key'] ?? null)) {
                $esistente = ScritturaContabile::where(
                    'idempotency_key',
                    $data['idempotency_key']
                )->firstOrFail();

                return $esistente->pagamentoFornitore;
            }

            throw $e;
        }
    }

    /**
     * Storna un pagamento confermato.
     *
     * Crea una scrittura contabile inversa + record pivot negativi + record
     * PagamentoFornitore di storno. Il pagamento originale viene marcato 'stornato'.
     * Le fatture toccate tornano al loro stato precedente (ricalcolaStatoFattura).
     *
     * Storno cross-esercizio (Variante B1):
     * Se l'esercizio del pagamento originale è chiuso, lo storno viene registrato
     * nell'esercizio corrente aperto del condominio.
     * Motivazione: la fattura torna "aperta" nel workflow corrente — l'admin riprova
     * il pagamento come se l'insoluto fosse un evento dell'esercizio corrente.
     *
     * @param PagamentoFornitore $pagamento  Pagamento da stornare (stato=confermato)
     * @param string             $motivo     Motivazione obbligatoria (audit trail)
     * @param int|null           $esercizioId Override esercizio target (null = auto-detect)
     *
     * @throws PagamentoGiaStornatoException
     * @throws FiscalYearClosedException
     * @throws NessunEsercizioApertoException
     */
    public function stornaPagamento(
        PagamentoFornitore $pagamento,
        string $motivo,
        ?int $esercizioId = null
    ): PagamentoFornitore {
        return DB::transaction(function () use ($pagamento, $motivo, $esercizioId) {

            // ── Step 1: Verifica stato ────────────────────────────────────
            if ($pagamento->stato === StatoPagamentoFornitore::STORNATO) {
                throw new PagamentoGiaStornatoException(
                    "Pagamento #{$pagamento->id} è già stornato."
                );
            }

            // Carica scrittura originale con le righe per il mirror
            $scritturaOriginale = $pagamento->scrittura()->with('righe')->firstOrFail();
            $esercizioOriginale = $scritturaOriginale->esercizio;

            // ── Step 2: Scelta esercizio target (Variante B1) ─────────────
            // Paradigma ledger-centric: la validazione è sull'esercizio DELLA SCRITTURA
            // che si sta creando (lo storno), non sull'esercizio della fattura.
            $crossEsercizio = false;

            if ($this->isEsercizioClosed($esercizioOriginale)) {
                // Esercizio originale chiuso → storno in esercizio corrente aperto
                $esercizioTarget = $esercizioId
                    ? Esercizio::findOrFail($esercizioId)
                    : $this->trovaEsercizioCorrenteAperto($scritturaOriginale->condominio_id);

                if ($this->isEsercizioClosed($esercizioTarget)) {
                    throw new FiscalYearClosedException(
                        "Nessun esercizio aperto disponibile per lo storno cross-esercizio " .
                        "del pagamento #{$pagamento->id}."
                    );
                }

                $crossEsercizio = true;
            } else {
                // Storno normale: stesso esercizio dell'originale (simmetria DARE/AVERE)
                $esercizioTarget = $esercizioOriginale;
            }

            // ── Step 3: Causale arricchita per cross-esercizio ────────────
            $causaleStorno = $crossEsercizio
                ? sprintf(
                    "Storno pag. #%d: %s. Originale in esercizio '%s'.",
                    $pagamento->id,
                    $motivo,
                    $esercizioOriginale->nome
                )
                : sprintf("Storno pag. #%d: %s", $pagamento->id, $motivo);

            // ── Step 4: Crea scrittura inversa ────────────────────────────
            $scritturaStorno = ScritturaContabile::create([
                'condominio_id'      => $scritturaOriginale->condominio_id,
                'gestione_id'        => $scritturaOriginale->gestione_id,
                'esercizio_id'       => $esercizioTarget->id,
                'data_registrazione' => now()->toDateString(),
                'data_competenza'    => now()->toDateString(),
                'causale'            => mb_substr($causaleStorno, 0, 255),
                'tipo_movimento'     => TipoMovimentoContabile::STORNO_PAGAMENTO_FORNITORE,
                'stato'              => 'registrata',
                'scrittura_padre_id' => $scritturaOriginale->id,
                'created_by'         => Auth::id(),
                'idempotency_key'    => (string) Str::uuid(),
            ]);

            // ── Step 5: Righe inverse (DARE ↔ AVERE) ──────────────────────
            // Ogni riga dell'originale viene rispecchiata con tipo invertito.
            // cassa_id viene propagato per coerenza con la riconciliazione v1.16.
            // Esempio storno pagamento 1.180 + comm. 5:
            //   DARE  Banca      1.185   (era AVERE — rientro cassa)
            //   AVERE Debiti     1.180   (era DARE — riapre il debito)
            //   AVERE Spese Banc.   5   (era DARE — storna il costo commissioni)
            foreach ($scritturaOriginale->righe as $riga) {
                $scritturaStorno->righe()->create([
                    'conto_contabile_id' => $riga->conto_contabile_id,
                    'cassa_id'           => $riga->cassa_id,
                    'tipo_riga'          => $riga->tipo_riga === 'dare' ? 'avere' : 'dare',
                    'importo'            => $riga->importo,
                ]);
            }

            // ── Step 6: Verifica quadratura (fail-fast) ───────────────────
            DoubleEntryValidator::validateOrFail($scritturaStorno->id);

            // ── Step 7: Pivot negativi (mirror dell'originale) ────────────
            // Lo storno non cancella i record pivot originali (ledger immutabile).
            // Aggiunge nuovi record con importo_allocato NEGATIVO dello stesso tipo.
            // Effetto netto su ogni fattura: SUM(allocato) torna al valore pre-pagamento.
            //
            // Esempio:
            //   Originale:  +800 tipo=pagamento
            //   Storno:      -800 tipo=pagamento
            //   SUM = 0  → ricalcolaStatoFattura → stato='aperta'
            $pivotOriginali = FatturaScrittura::where(
                'scrittura_contabile_id',
                $scritturaOriginale->id
            )->get();

            $fattureStornateIds = $pivotOriginali->pluck('fattura_passiva_id')->unique();

            foreach ($pivotOriginali as $pivot) {
                FatturaScrittura::create([
                    'fattura_passiva_id'     => $pivot->fattura_passiva_id,
                    'scrittura_contabile_id' => $scritturaStorno->id,
                    'tipo'                   => $pivot->tipo,
                    'importo_allocato'       => -$pivot->importo_allocato,  // NEGATIVO
                ]);
            }

            // ── Step 8: Record PagamentoFornitore di storno ────────────────
            // Il record di storno punta al padre (self-ref pagamento_padre_id).
            // Eredita snapshot e importi dal pagamento originale.
            $pagamentoStorno = PagamentoFornitore::create([
                'uuid'                   => (string) Str::uuid(),
                'scrittura_contabile_id' => $scritturaStorno->id,
                'condominio_id'          => $pagamento->condominio_id,
                'fornitore_id'           => $pagamento->fornitore_id,
                'conto_corrente_id'      => $pagamento->conto_corrente_id,
                'importo_lordo'          => $pagamento->importo_lordo,
                'importo_ritenuta'       => $pagamento->importo_ritenuta,
                'importo_netto'          => $pagamento->importo_netto,
                'importo_commissione'    => $pagamento->importo_commissione,
                'metodo_pagamento'       => $pagamento->metodo_pagamento,
                'data_pagamento'         => now()->toDateString(),
                'iban_beneficiario'      => $pagamento->iban_beneficiario,
                'causale_bonifico'       => mb_substr(
                    "Storno: " . ($pagamento->causale_bonifico ?? ''),
                    0,
                    1000
                ),
                'stato'                  => StatoPagamentoFornitore::CONFERMATO,
                'pagamento_padre_id'     => $pagamento->id,
                'motivo_storno'          => $motivo,
                'storno_cross_esercizio' => $crossEsercizio,
                'esercizio_storno_id'    => $crossEsercizio ? $esercizioTarget->id : null,
                'fornitore_snapshot'     => $pagamento->fornitore_snapshot,
                'user_id'                => Auth::id(),
                'idempotency_key'        => (string) Str::uuid(),
            ]);

            // ── Step 9: Marca pagamento originale come stornato ────────────
            $pagamento->update([
                'stato'         => StatoPagamentoFornitore::STORNATO,
                'motivo_storno' => $motivo,
            ]);

            // ── Step 10: Incrementa versione + ricalcola stati fatture ─────
            FatturaPassiva::whereIn('id', $fattureStornateIds)->increment('versione_allocazioni');

            FatturaPassiva::whereIn('id', $fattureStornateIds)
                ->get()
                ->each(fn ($f) => $this->ricalcolaStatoFattura($f));

            // ── Step 11: Dispatch evento (after commit) ────────────────────
            event(new PagamentoStornato($pagamento, $motivo, $crossEsercizio));

            return $pagamentoStorno;
        });
    }

    /**
     * Ricalcola e persiste lo stato_pagamento di una fattura dalla pivot.
     *
     * DETECTION SISTEMICA — non validation runtime.
     * Non lancia mai eccezioni (neanche su overpayment): logga critical e marca
     * inconsistenza_pagamento=true per intervento manuale.
     *
     * Chiamato:
     *  - da registraPagamento() e stornaPagamento() dopo ogni modifica pivot
     *  - dal comando Artisan kondomanager:fatture-ricalcola-stati
     *
     * FILTRO CRITICO: include SOLO tipo IN ('pagamento','compensazione').
     * Il tipo 'competenza' è la registrazione iniziale del debito — non chiude il debito.
     */
    public function ricalcolaStatoFattura(FatturaPassiva $fattura): void
    {
        try {
            $totale = (int) DB::table('fattura_scrittura')
                ->where('fattura_passiva_id', $fattura->id)
                ->whereIn('tipo', [
                    TipoAllocazioneFattura::PAGAMENTO->value,
                    TipoAllocazioneFattura::COMPENSAZIONE->value,
                ])
                ->sum('importo_allocato');

            $netto = $fattura->netto_a_pagare;
            $inconsistente = false;
            $errore = null;

            // ⚠️ MODIFICA: Usiamo abs() per gestire correttamente le Note di Credito
            $totaleAssoluto = abs($totale);
            $nettoAssoluto = abs($netto);

            if ($totaleAssoluto > $nettoAssoluto) {
                // Overpayment rilevato DOPO la scrittura: dirty data o bug a monte.
                Log::critical("Fattura {$fattura->id}: overpayment rilevato in ricalcolo.", [
                    'fattura_id' => $fattura->id,
                    'totale'     => $totale,
                    'netto'      => $netto,
                    'delta'      => $totaleAssoluto - $nettoAssoluto, // Usa il delta assoluto
                ]);
                $inconsistente = true;
                $errore = "Overpayment: totale_allocato({$totale}) > netto_a_pagare({$netto})";
                $stato  = StatoPagamentoFattura::PARZIALE;
            } else {
                // ⚠️ MODIFICA: Usiamo le variabili assolute per il match
                $stato = match(true) {
                    $totaleAssoluto <= 0     => StatoPagamentoFattura::APERTA,
                    $totaleAssoluto < $nettoAssoluto => StatoPagamentoFattura::PARZIALE,
                    default          => StatoPagamentoFattura::PAGATA,
                };
            }
            
            $fattura->update([
                'stato_pagamento'               => $stato,
                'ultimo_ricalcolo_pagamento_at' => now(),
                'inconsistenza_pagamento'       => $inconsistente,
                'ultimo_errore_ricalcolo'       => $errore,
            ]);

        } catch (\Throwable $e) {
            // Questo non dovrebbe mai accadere — ma se accade, non interrompiamo
            // il reconcile (che potrebbe star girando su centinaia di fatture).
            Log::error("Errore inatteso in ricalcolaStatoFattura.", [
                'fattura_id' => $fattura->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Genera la causale del bonifico.
     * Standard: "Pagamento {numero} del {data}"
     * Parlante (art. 16-bis TUIR): include normativa, CF beneficiari, P.IVA fornitore.
     */
    public function generaCausaleBonifico(
        FatturaPassiva $fattura,
        bool $parlante = false,
        ?TipoDetrazione $tipoDetrazione = null,
        array $beneficiari = []
    ): string {
        $base = sprintf(
            'Pagamento %s del %s',
            $fattura->numero_documento ?? "FT#{$fattura->id}",
            $fattura->data_documento?->format('d/m/Y') ?? now()->format('d/m/Y')
        );

        if (! $parlante || ! $tipoDetrazione) {
            return $base;
        }

        // Bonifico Parlante: causale fiscalmente valida per detrazioni condòmini.
        // La banca trattiene automaticamente 8% A.d.E. (ritenuta diversa dalla ritenuta
        // d'acconto — si applica sull'importo lordo, netto = lordo × 0.92).
        $cfs = collect($beneficiari)->pluck('codice_fiscale')->filter()->implode(', ');
        $piva = $fattura->fornitore?->partita_iva ?? '';
        $normativa = $tipoDetrazione->riferimentoNormativo();

        $causale = sprintf(
            'Bonifico %s - %s - Benef. CF: %s - P.IVA: %s - %s',
            $tipoDetrazione->label(),
            $normativa,
            $cfs,
            $piva,
            $base
        );

        // Le causali bancarie hanno un limite ~140 caratteri.
        // Se supera, tronca e avvisa: l'admin dovrà allegare distinta PDF separata.
        if (mb_strlen($causale) > 140) {
            Log::warning("Causale Bonifico Parlante troncata per fattura {$fattura->id}.", [
                'lunghezza_originale' => mb_strlen($causale),
                'causale_troncata'    => mb_substr($causale, 0, 137) . '...',
            ]);
            return mb_substr($causale, 0, 137) . '...';
        }

        return $causale;
    }

    /**
     * Restituisce le note di credito del fornitore ancora compensabili.
     * Usato dal frontend per il pannello auto-netting (RadarNoteCredito widget).
     */
    public function trovaNoteCreditoCompensabili(int $fornitoreId, int $condominioId): Collection
    {
        return FatturaPassiva::where('fornitore_id', $fornitoreId)
            ->where('condominio_id', $condominioId)
            ->where('tipo_documento', 'nota_credito')
            ->whereIn('stato_pagamento', [
                StatoPagamentoFattura::APERTA->value,
                StatoPagamentoFattura::PARZIALE->value,
            ])
            ->where('stato_approvazione', 'approvata')
            ->orderBy('data_scadenza')
            ->get();
    }

    /**
     * Verifica la capienza del conto prima del pagamento.
     *
     * Check UX (non un constraint hard): il saldo è un aggregato derivato,
     * non un lock di riga. Non usare lockForUpdate() qui — sarebbe over-engineering.
     * La fonte di verità è il giornale (righe_scritture), non una colonna saldo.
     *
     * @return array{ok, saldo_attuale_cents, saldo_dopo_cents, scopertura_cents}
     */
    public function verificaCapienza(int $contoId, int $importoCents): array
    {
        $saldo = $this->saldoCorrente($contoId);
        $dopoPagamento = $saldo - $importoCents;

        return [
            'ok'                  => $dopoPagamento >= 0,
            'saldo_attuale_cents' => $saldo,
            'saldo_dopo_cents'    => $dopoPagamento,
            'scopertura_cents'    => $dopoPagamento < 0 ? abs($dopoPagamento) : 0,
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // METODI PRIVATI
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Validazioni cross-field eseguite DOPO il lock pessimistico.
     * Tutte le eccezioni di dominio vengono lanciate qui.
     * ricalcolaStatoFattura() non lancia mai — questa è l'unica sede di blocco.
     */
    private function validaInput(array $data, Collection $fatture): void
    {
        // 1. Esercizio SCRITTURA aperto (paradigma ledger-centric).
        //    NON controllare l'esercizio della fattura: una fattura di competenza 2025
        //    può essere pagata nel 2026 anche se il 2025 è chiuso.
        $esercizio = Esercizio::findOrFail($data['esercizio_id']);
        if ($this->isEsercizioClosed($esercizio)) {
            throw new FiscalYearClosedException(
                "Impossibile registrare pagamenti nell'esercizio chiuso '{$esercizio->nome}'. " .
                "Selezionare un esercizio aperto."
            );
        }

        // 2. Coerenza allocazioni: tutti stesso fornitore e condominio
        $fornitoriDistinti  = $fatture->pluck('fornitore_id')->unique();
        $condominioDistinti = $fatture->pluck('condominio_id')->unique();

        if ($fornitoriDistinti->count() > 1 || $condominioDistinti->count() > 1) {
            throw new AllocazioniInconsistentiException(
                "Tutte le fatture di un pagamento devono appartenere allo stesso fornitore e condominio."
            );
        }

        if ((int) $fornitoriDistinti->first() !== (int) $data['fornitore_id']) {
            throw new AllocazioniInconsistentiException(
                "Il fornitore delle fatture selezionate non corrisponde al fornitore del pagamento."
            );
        }

        // 3. Tutte le fatture devono essere approvate
        $nonApprovate = $fatture->where('stato_approvazione', '!=', 'approvata');
        if ($nonApprovate->isNotEmpty()) {
            throw new FatturaNonApprovataException(
                "Le seguenti fatture non sono ancora approvate: " .
                $nonApprovate->pluck('numero_documento')->implode(', ')
            );
        }

        // 4. Overpayment check per ogni fattura coinvolta
        // 4. Overpayment check per ogni fattura coinvolta
        $allowOverpayment = (bool) ($data['allow_overpayment'] ?? false);

        foreach ($fatture as $fattura) {
            $allocatoProposto = collect($data['allocazioni'])
                ->where('fattura_id', $fattura->id)
                ->sum('importo_allocato_cents');

            $residuo = $fattura->residuo;

            // ⚠️ MODIFICA: Uso abs() per confrontare le magnitudo.
            // Permette di gestire le Note di Credito dove il residuo è negativo (es. -244€),
            // verificando che l'allocazione proposta (es. 244€) non superi il credito disponibile.
            if (abs($allocatoProposto) > abs($residuo) && ! $allowOverpayment) {
                throw new OverpaymentException(
                    sprintf(
                        "Overpayment su fattura %s (id %d): si alloca %s€ ma il residuo è %s€.",
                        $fattura->numero_documento ?? "#{$fattura->id}",
                        $fattura->id,
                        number_format($allocatoProposto / 100, 2, ',', '.'),
                        number_format($residuo / 100, 2, ',', '.')
                    ),
                    allocatoCents: (int) $allocatoProposto,
                    residuoCents:  (int) $residuo,
                    numFattura:    $fattura->numero_documento ?? "#{$fattura->id}",
                );
            }
        }

        // 5. Antiriciclaggio (D.Lgs. 231/2007): contanti ≥ 5.000€ vietati
        if ($data['metodo_pagamento'] === MetodoPagamento::CONTANTI->value) {
            $totalePagamentoCash = collect($data['allocazioni'])
                ->where('tipo', TipoAllocazioneFattura::PAGAMENTO->value)
                ->sum('importo_allocato_cents');

            if ($totalePagamentoCash >= 500000) { // 5.000€ in centesimi
                throw new IllegalCashAmountException(
                    sprintf(
                        "Pagamento in contanti di %s€ non consentito: supera il limite di 5.000€ " .
                        "(D.Lgs. 231/2007 antiriciclaggio).",
                        number_format($totalePagamentoCash / 100, 2, ',', '.')
                    )
                );
            }
        }

        // 6. Capienza conto (bloccante senza override esplicito)
        if (! ($data['allow_overdraft'] ?? false)) {
            $totaleCassa = collect($data['allocazioni'])
                ->where('tipo', TipoAllocazioneFattura::PAGAMENTO->value)
                ->sum('importo_allocato_cents') + (int) ($data['importo_commissioni_cents'] ?? 0);

            $capienza = $this->verificaCapienza((int) $data['conto_corrente_id'], $totaleCassa);
            if (! $capienza['ok']) {
                throw new InsufficientFundsException(
                    sprintf(
                        "Saldo conto insufficiente: disponibile %s€, necessario %s€ (scopertura %s€). " .
                        "Usare allow_overdraft per procedere.",
                        number_format($capienza['saldo_attuale_cents'] / 100, 2, ',', '.'),
                        number_format($totaleCassa / 100, 2, ',', '.'),
                        number_format($capienza['scopertura_cents'] / 100, 2, ',', '.')
                    ),
                    saldoCents:      (int) $capienza['saldo_attuale_cents'],
                    necessarioCents: (int) $totaleCassa,
                    scoperturaCents: (int) $capienza['scopertura_cents'],
                );
            }
        }

        // 7. Sentinella Anti-Frode IBAN
        //    Confronta l'IBAN inserito con quello in anagrafica fornitore.
        //    Se differiscono, richiede conferma esplicita prima di procedere.
        if (! ($data['iban_confermato_manualmente'] ?? false)) {
            $ibanInput   = $data['iban_beneficiario'] ?? null;
            $fornitore = Fornitore::find($data['fornitore_id']);

            if ($ibanInput && $fornitore?->iban && $ibanInput !== $fornitore->iban) {
                throw new IbanDiscrepanzaException(
                    "L'IBAN inserito ({$ibanInput}) differisce dall'IBAN in anagrafica fornitore " .
                    "({$fornitore->iban}). Impostare iban_confermato_manualmente=true per procedere."
                );
            }
        }

        // 8. Duplicate detection MVP (warning, non blocco hard)
        //    Regola v1.9.1: stessa fattura + stesso importo + stesso tipo + entro 24h.
        //    Score completo (CRO/TRN, 7gg, IBAN+importo) rimandato a v1.16 Treasury.
        if (! ($data['conferma_duplicato_verificato'] ?? false)) {
            $this->rilevaDuplicato($data);
        }
    }

    /**
     * Calcola i totali aggregati necessari per costruire la partita doppia.
     *
     * Classificazione per tipo_documento della fattura puntata:
     *   - 'fattura'       → va in totaleSuFatture (debito da chiudere)
     *   - 'nota_credito'  → va in totaleSuNC (credito da consumare)
     *
     * @return array{totalePagamento, totaleSuFatture, totaleSuNC, uscitaCassa, commissioni}
     */
    private function calcolaImporti(array $allocazioni, Collection $fatture, int $commissioni): array
    {
        $totalePagamento = 0;
        $totaleSuFatture = 0;
        $totaleSuNC      = 0;

        foreach ($allocazioni as $alloc) {
            $fattura = $fatture[(int) $alloc['fattura_id']];
            $importo = (int) $alloc['importo_allocato_cents'];

            if ($alloc['tipo'] === TipoAllocazioneFattura::PAGAMENTO->value) {
                $totalePagamento += $importo;
            }

            if (($fattura->tipo_documento ?? 'fattura') === 'nota_credito') {
                $totaleSuNC += $importo;
            } else {
                $totaleSuFatture += $importo;
            }
        }

        // uscitaCassa = solo i pagamenti tipo='pagamento' + commissioni.
        // Le compensazioni non escono dalla cassa (si "annullano" tra FT e NC).
        $uscitaCassa = $totalePagamento + $commissioni;

        return compact(
            'totalePagamento',
            'totaleSuFatture',
            'totaleSuNC',
            'uscitaCassa',
            'commissioni'
        );
    }

    /**
     * Rileva possibili pagamenti duplicati (MVP: stessa fattura + stesso importo + 24h).
     * Lancia PossibilePagamentoDuplicatoException che la UI deve presentare come warning
     * con pulsante di conferma (non come errore bloccante definitivo).
     */
    private function rilevaDuplicato(array $data): void
    {
        $cutoff = now()->subHours(24);

        foreach ($data['allocazioni'] as $alloc) {
            $isDuplicate = FatturaScrittura::where('fattura_passiva_id', (int) $alloc['fattura_id'])
                ->where('importo_allocato', (int) $alloc['importo_allocato_cents'])
                ->where('tipo', $alloc['tipo'])
                ->where('created_at', '>=', $cutoff)
                ->exists();

            if ($isDuplicate) {
                throw new PossibilePagamentoDuplicatoException(
                    "Possibile pagamento duplicato rilevato su fattura #{$alloc['fattura_id']}: " .
                    "importo identico già registrato nelle ultime 24 ore. " .
                    "Impostare conferma_duplicato_verificato=true per procedere."
                );
            }
        }
    }

    /**
     * Deriva il gestione_id dalla scrittura di competenza delle fatture.
     *
     * fatture_passive NON ha gestione_id come colonna. Lo si ricava dalla prima
     * scrittura collegata (tipo='competenza') — pattern identico a StornoFatturaController.
     * Tutte le fatture del batch devono condividere la stessa gestione.
     *
     * @throws AllocazioniInconsistentiException se le fatture appartengono a gestioni diverse
     */
    private function deriveGestioneId(Collection $fatture): int
    {
        $gestioneIds = $fatture->map(function (FatturaPassiva $fattura) {
            return $fattura->scritture()->first()?->gestione_id;
        })->filter()->unique();

        if ($gestioneIds->isEmpty()) {
            throw new AllocazioniInconsistentiException(
                "Impossibile determinare la gestione contabile delle fatture selezionate. " .
                "Verificare che le fatture abbiano scritture di competenza collegate."
            );
        }

        if ($gestioneIds->count() > 1) {
            throw new AllocazioniInconsistentiException(
                "Le fatture selezionate appartengono a gestioni diverse ({$gestioneIds->implode(', ')}). " .
                "Un pagamento può coprire solo fatture della stessa gestione."
            );
        }

        return (int) $gestioneIds->first();
    }

    /**
     * Trova un conto di sistema per ruolo nel condominio specificato.
     * Tutti i ruoli necessari sono garantiti da CondominioService::ensureDefaultConti().
     *
     * @throws \RuntimeException se il conto non è trovato (configurazione mancante)
     */
    private function trovaConto(int $condominioId, string $ruolo): ContoContabile
    {
        $conto = ContoContabile::where('condominio_id', $condominioId)
            ->where('ruolo', $ruolo)
            ->where('attivo', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $conto) {
            throw new \RuntimeException(
                "Conto di sistema '{$ruolo}' non trovato per condominio #{$condominioId}. " .
                "Eseguire CondominioService::ensureDefaultConti() per creare i conti mancanti."
            );
        }

        return $conto;
    }

    /**
     * Calcola il saldo corrente di un conto aggregando le righe del giornale.
     * Per i conti ATTIVO (banca, cassa): saldo = DARE − AVERE.
     * Esclude le scritture soft-deleted (annullate).
     */
    private function saldoCorrente(int $contoId): int
    {
        // 1. Recupera il saldo iniziale della cassa associata al conto
        $saldoIniziale = DB::table('casse')
            ->where('conto_contabile_id', $contoId)
            ->value('saldo_iniziale') ?? 0;

        // 2. Calcola il delta dei movimenti (DARE - AVERE per conti attivi)
        $movimenti = DB::table('righe_scritture')
            ->join('scritture_contabili', 'righe_scritture.scrittura_id', '=', 'scritture_contabili.id')
            ->where('righe_scritture.conto_contabile_id', $contoId)
            ->whereNull('scritture_contabili.deleted_at')
            ->selectRaw("
                SUM(CASE WHEN tipo_riga = 'dare'  THEN importo ELSE 0 END) -
                SUM(CASE WHEN tipo_riga = 'avere' THEN importo ELSE 0 END) AS saldo
            ")
            ->value('saldo');

        // Il saldo reale è il saldo di apertura + il saldo dei movimenti
        return (int) $saldoIniziale + (int) ($movimenti ?? 0);
    }

    /**
     * Trova l'esercizio corrente aperto per un condominio.
     * Usato per lo storno cross-esercizio (Variante B1).
     */
    private function trovaEsercizioCorrenteAperto(int $condominioId): Esercizio
    {
        $esercizio = Esercizio::where('condominio_id', $condominioId)
            ->where('stato', '!=', 'chiuso')
            ->orderBy('data_fine', 'desc')
            ->first();

        if (! $esercizio) {
            throw new NessunEsercizioApertoException(
                "Nessun esercizio aperto trovato per il condominio #{$condominioId}. " .
                "Impossibile registrare lo storno cross-esercizio."
            );
        }

        return $esercizio;
    }

    /**
     * Determina se un esercizio è chiuso.
     * Implementato come metodo dedicato per facilitare un futuro refactor
     * se `stato` venisse sostituito da un Backed Enum su Esercizio.
     */
    private function isEsercizioClosed(Esercizio $esercizio): bool
    {
        return $esercizio->stato === 'chiuso';
    }

    /**
     * Verifica se una QueryException è una violazione UNIQUE su idempotency_key.
     * Necessario per distinguere il race condition idempotency (replay sicuro)
     * da altri errori di integrità DB (da rilanciare).
     */
    private function isIdempotencyKeyViolation(QueryException $e, ?string $key): bool
    {
        if (! $key) {
            return false;
        }

        $msg = $e->getMessage();

        // MySQL: errno 1062 = Duplicate entry for key
        // SQLite: SQLSTATE 23000, messaggio contiene 'UNIQUE constraint failed'
        $isDuplicate = ($e->errorInfo[1] ?? null) === 1062
            || ($e->errorInfo[0] ?? null) === '23000';

        return $isDuplicate && str_contains($msg, 'idempotency_key');
    }

    /**
     * Costruisce lo snapshot immutabile dell'anagrafica fornitore al momento del pagamento.
     * schema_version=1 permette evoluzioni future (v2 in v1.12 con DNA Fiscale completo)
     * senza migrazione distruttiva degli snapshot storici esistenti.
     */
    private function costruisciSnapshot(Fornitore $fornitore): array
    {
        return [
            'schema_version'  => 1,
            'snapshot_at'     => now()->toIso8601String(),
            'ragione_sociale' => $fornitore->ragione_sociale,
            'partita_iva'     => $fornitore->partita_iva,
            'codice_fiscale'  => $fornitore->codice_fiscale,
            'iban'            => $fornitore->iban,
            'indirizzo'       => $fornitore->indirizzo,
            // In v1.12 (DNA Fiscale) lo schema evolverà a version=2 con:
            // pec, regime_fiscale, split_payment, reverse_charge, nazione_iso
        ];
    }

    /**
     * Genera la causale per la testata della scrittura contabile.
     * Diversa dalla causale del bonifico (quella va in PagamentoFornitore::causale_bonifico).
     */
    private function generaCausaleScrittura(Fornitore $fornitore, Collection $fatture): string
    {
        $nome = $fornitore->ragione_sociale ?? "Fornitore #{$fornitore->id}";
        $n    = $fatture->count();

        if ($n === 1) {
            $fattura = $fatture->first();
            $numero  = $fattura->numero_documento ?? "FT#{$fattura->id}";
            return mb_substr("Pag. {$numero} - {$nome}", 0, 255);
        }

        return mb_substr("Pag. cumulativo {$n} fatture - {$nome}", 0, 255);
    }
}