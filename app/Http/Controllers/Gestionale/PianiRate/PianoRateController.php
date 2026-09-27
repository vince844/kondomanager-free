<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use Illuminate\Validation\Rule;
use App\Enums\NaturaGestione;
use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Exceptions\Gestionale\DestinatariCambiatiException;
use App\Exceptions\Gestionale\ScopertiNonAccettatiException;
use App\Enums\StatoPianoRate;
use App\Enums\VisibilityStatus;
use App\Events\Gestionale\PianoRateStatusUpdated;
use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoRate\CreatePianoRateRequest;
use App\Http\Requests\Gestionale\PianoRate\PianoRateIndexRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Gestionale\PianiRate\PianoRateResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Gestionale\BudgetMovement;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Services\Gestionale\BudgetCoverageService;
use App\Services\Gestionale\SaldoEsercizioService;
use App\Services\Gestionale\SpesaPerVoceService;
use App\Services\PianoRateCreatorService;
use App\Services\PianoRateQuoteService;
use App\Traits\HandleFlashMessages;
use App\Traits\OrdinaElenco;
use App\Traits\PaginaElenco;
use App\Traits\HasCondomini;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use App\Services\Riparto\RisolutoreTitolari;

class PianoRateController extends Controller
{
    use HandleFlashMessages, HasCondomini, OrdinaElenco, PaginaElenco;

    /**
     * Costruttore del controller.
     * Inietta i servizi necessari per l'estrazione delle quote, la creazione dei piani e la verifica dei saldi pregressi.
     *
     * @param PianoRateQuoteService $pianoRateQuoteService
     * @param PianoRateCreatorService $pianoRateCreatorService
     * @param SaldoEsercizioService $saldoService
     */
    public function __construct(
        private readonly PianoRateQuoteService $pianoRateQuoteService,
        private readonly PianoRateCreatorService $pianoRateCreatorService,
        private readonly SaldoEsercizioService $saldoService,
    ) {}

    /**
     * Mostra l'elenco dei Piani Rate associati a un condominio e a uno specifico esercizio.
     * Supporta la paginazione e la ricerca per nome del piano rate.
     *
     * @param PianoRateIndexRequest $request Dati della richiesta validati (es. filtri, per_page)
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @return Response Renderizzazione del componente Vue per la lista
     */
    public function index(PianoRateIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        $validated = $request->validated();

        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        $validated['per_page'] = $this->righePerPagina($request);

        $pianiRate = PianoRate::with(['gestione'])
            ->where('condominio_id', $condominio->id)
            ->whereHas('gestione.esercizi', fn($q) => $q->where('esercizio_id', $esercizio->id))
            ->tap(fn ($q) => $this->ordina($q, $validated, PianoRateIndexRequest::colonneOrdinabili(), predefinita: 'nome'))
            ->paginate($validated['per_page']);

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        return Inertia::render('gestionale/pianiRate/PianiRateList', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'esercizi' => $esercizi,
            'condomini' => CondominioResource::collection($this->getCondomini()),
            'pianiRate' => PianoRateResource::collection($pianiRate)->resolve(),
            'meta' => [
                'current_page' => $pianiRate->currentPage(),
                'last_page' => $pianiRate->lastPage(),
                'per_page' => $pianiRate->perPage(),
                'total' => $pianiRate->total()
            ],
            'filters' => $request->only(['nome']),
            'sort'      => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? null,
        ]);
    }

    /**
     * Mostra la pagina di creazione per un nuovo Piano Rate.
     * Recupera e prepara tutte le dipendenze necessarie (gestioni attive, saldi pregressi vuoti e anagrafiche) per alimentare il form frontend.
     *
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @return Response Renderizzazione del componente Vue per il form di creazione
     */
    public function create(Request $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        $condomini = $this->getCondomini();
        
        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        $gestioni = Gestione::whereHas('esercizi', fn($q) => $q->where('esercizio_id', $esercizio->id))
            ->with(['esercizi' => fn($q) => $q->where('esercizio_id', $esercizio->id)])
            ->get();

        // --- FIX CHIRURGICO: Ascoltiamo la richiesta di Inertia (Reload) ---
        if ($request->has('gestione_id') && $request->gestione_id != '') {

            // Se Inertia sta chiedendo i dati di una gestione specifica, la cerchiamo
            $gestioneSelezionata = Gestione::where('condominio_id', $condominio->id)->findOrFail($request->gestione_id);

            // E usiamo il service per farci dire se è bloccata o meno
            $saldoInfo = $this->saldoService->calcolaSaldoApplicabile($gestioneSelezionata);

            // Segnala al frontend se per questa gestione esiste già un piano rate
            // "madre" (preventivo iniziale, non un'integrazione né uno
            // straordinario): usato per rietichettare "Piano rate ordinario"
            // come "Piano Rata Integrativa" quando non è la prima emissione
            // dell'anno. Scoping su tipo+contesto_creazione (non un ->exists()
            // generico) per non confondere un piano straordinario/integrativo
            // preesistente con un vero preventivo iniziale già emesso.
            //
            // Dalla 1.11.0-beta.31 `piani_rate.esercizio_id` esiste (migrazione 9) e la generazione lo
            // scrive: il preventivo iniziale si cerca nell'esercizio di questa pagina, così un piano
            // dell'anno prima sulla stessa gestione (riusata su più esercizi) non fa più scattare
            // l'etichetta «Integrativa». I piani vecchi senza colonna (nulla) contano ancora: meglio
            // un'etichetta prudente di un preventivo che si crede primo.
            $hasPianoEsistente = PianoRate::where('gestione_id', $gestioneSelezionata->id)
                ->where('tipo', 'ordinario')
                ->where('contesto_creazione', 'preventivo_iniziale')
                ->where(fn ($q) => $q->where('esercizio_id', $esercizio->id)->orWhereNull('esercizio_id'))
                ->exists();

        } else {
            // Comportamento di default (al primo caricamento della pagina)
            $saldoInfo = [
                'saldo' => 0,
                'has_movimenti' => false,
                'applicabile' => false,
                'motivo' => 'Seleziona una gestione per verificare i saldi.',
                'is_primo_anno' => false
            ];
            $hasPianoEsistente = false;
        }
        // -------------------------------------------------------------------

        return Inertia::render('gestionale/pianiRate/PianiRateNew', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'esercizi' => $esercizi,
            'condomini' => $condomini,
            'gestioni' => $gestioni,
            'saldoInfo' => $saldoInfo,
            'hasPianoEsistente' => $hasPianoEsistente,
            'anagraficheDisponibili' => $condominio->anagrafiche()->orderBy('nome')->get(['anagrafiche.id', 'nome']),
        ]);
    }

    /**
     * Salva nel database un nuovo Piano Rate.
     * Questo metodo esegue operazioni complesse all'interno di una transazione:
     * 1. Creazione del record principale.
     * 2. Associazione dei conti/sottoconti (Emissione Globale vs Parziale).
     * 3. Configurazione della ricorrenza delle rate.
     * 4. Applicazione e blocco ("lucchetto") dei saldi pregressi della gestione.
     * 5. (Opzionale) Generazione immediata delle rate (Action).
     *
     * @param CreatePianoRateRequest $request La richiesta validata dal form
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @return RedirectResponse Reindirizzamento al dettaglio del piano rate generato
     */
    public function store(CreatePianoRateRequest $request, Condominio $condominio, Esercizio $esercizio)
    {
        $request->validate([
            // `required_if_accepted` e non `required_if:…,true`: con «1» o «on» la regola di prima non chiedeva la nota, mentre
            // `boolean()` qui sotto la presa d'atto la accettava lo stesso (R10 della Fase 1-bis, 1.11.0-beta.35). E la regola
            // `boolean` sui due flag: `boolean()` legge anche «TRUE» e «On», che `accepted` non riconosce.
            'accetta_scoperti' => 'nullable|boolean',
            'accetta_destinatari' => 'nullable|boolean',
            'nota_scoperti' => 'required_if_accepted:accetta_scoperti|nullable|string|min:10',
            // B2, cancello (2) della decisione 14.
            'nota_destinatari' => 'required_if_accepted:accetta_destinatari|nullable|string|min:10',
        ]);
        $validated = $request->validated();
        $accettaScoperti = (bool) $request->boolean('accetta_scoperti', false);
        $accettaDestinatari = (bool) $request->boolean('accetta_destinatari', false);
        $notaDestinatari    = $request->string('nota_destinatari')->trim()->value() ?: null;
        $notaScoperti    = $request->string('nota_scoperti')->trim()->value();

        try {
            DB::beginTransaction();

            // 1. Validazione Gestione
            $gestione = Gestione::findOrFail($validated['gestione_id']);
            $this->pianoRateCreatorService->verificaGestione($validated['gestione_id']);

            // 2. Analisi Saldi (Invariato)
            $saldoInfo = $this->saldoService->calcolaSaldoApplicabile($gestione);
            $haMovimenti = $saldoInfo['has_movimenti'] ?? false;
            
            if (!$haMovimenti && $saldoInfo['saldo'] == 0) {
                $esisteManuale = DB::table('saldi')->where('gestione_id', $gestione->id)->where('saldo_iniziale', '!=', 0)->exists();
                if ($esisteManuale) $haMovimenti = true;
            }
            $applicareSaldi = ($saldoInfo['applicabile'] && $haMovimenti);

            // 3. Creazione Core del Piano
            $pianoRate = $this->pianoRateCreatorService->creaPianoRate($validated, $condominio);

            // --- [CORREZIONE CHIRURGICA: MAPPIAMO SULLE COLONNE REALI] ---
            $tipoPiano = $validated['tipo'] ?? 'ordinario';
            $pianoRate->tipo = $tipoPiano;

            // La scelta sui saldi va persistita, non dedotta dal lucchetto: un
            // piano creato senza "genera subito" deve poter ritrovare la stessa
            // intenzione quando le rate verranno generate più tardi.
            // (Non è una spunta dell'amministratore: è lo stato del wallet al
            // momento della creazione, congelato per le generazioni successive.)
            $pianoRate->applica_saldi = $applicareSaldi;

            // 1. Determiniamo la Genesi del Piano (Il Contesto)
            $pianoRate->contesto_creazione = match(true) {
                $request->input('origine') === 'dashboard' => 'integrazione_dashboard',
                !empty($validated['fatture_config']) => 'integrazione_dashboard', // Se ha fatture è sicuramente un'integrazione
                !empty($validated['capitoli_ids']) => 'libero_manuale', // Se l'utente ha cliccato check specifici
                default => 'preventivo_iniziale', // Il piano madre di inizio anno
            };

            // B2 (decisione 12): la data della delibera nasce con il piano, quando c'è. Su una gestione
            // straordinaria la Request la pretende (salvo urgenza); altrove è un dato in più che non guida nulla.
            // Con «Urgenza» la delibera non esiste per costruzione: una data battuta prima di scegliere l'urgenza
            // (il campo sparisce dallo schermo, il valore resterebbe nel form) non si scrive, altrimenti il motore
            // la userebbe come gradino senza che nessuno la veda più (verifica S6, R2).
            if (! empty($validated['data_delibera_assemblea']) && ($validated['tipo_autorizzazione'] ?? null) !== 'urgenza') {
                $pianoRate->data_delibera_assemblea = $validated['data_delibera_assemblea'];
            }

            if ($tipoPiano === 'straordinario') {
                // Usiamo le colonne dedicate presenti nella tabella piani_rate
                $pianoRate->tipo_autorizzazione = $validated['tipo_autorizzazione'] ?? null;
                $pianoRate->motivazione_autorizzazione = $validated['motivazione_autorizzazione'] ?? null;
                
                // Tracciamo l'audit usando i campi che hai già nel DB
                $pianoRate->approvato_da_user_id = Auth::id();
                $pianoRate->approvato_il = now();
                $pianoRate->stato = StatoPianoRate::APPROVATO;
            }
            
            $pianoRate->save(); 
            // -------------------------------------------------------------

            // 4. Gestione Capitoli o Fatture
            if ($tipoPiano === 'straordinario') {
                // SCENARIO 2/3: Sincronizzazione Fatture Straordinarie
                $syncFatture = [];
                $fattureIds = []; // Per tenere traccia di quali fatture aggiornare
                
                foreach ($validated['fatture_config'] ?? [] as $fConf) {
                    $importoCents = (isset($fConf['importo']) && $fConf['importo'] !== '') 
                        ? MoneyHelper::toCents($fConf['importo']) 
                        : 0;
                    $syncFatture[$fConf['id']] = ['importo_collegato' => $importoCents];
                    $fattureIds[] = $fConf['id'];
                }
                
                $pianoRate->fattureStraordinarie()->sync($syncFatture);

                // --- MAGIA DASHBOARD: Spegniamo il semaforo sulle righe di queste fatture ---
                if (!empty($fattureIds)) {
                    DB::table('righe_fattura')
                        ->whereIn('fattura_passiva_id', $fattureIds)
                        // Spegniamo solo le righe che legittimavano lo sforo o l'ad personam
                        ->where(function ($query) {
                            $query->where('is_sopravvenienza', true)
                                  ->orWhereNotNull('immobile_id');
                        })
                        ->update(['is_rateizzata' => true]);
                }
                // ----------------------------------------------------------------------------

            } else {
                // SCENARIO 1: Gestione Capitoli (Tua logica esistente preservata al 100%)
                $calcolaVeroTotale = function ($conto) use (&$calcolaVeroTotale) {
                    if ($conto->relationLoaded('sottoconti') && $conto->sottoconti->isNotEmpty()) {
                        return $conto->sottoconti->sum(fn($sub) => $calcolaVeroTotale($sub));
                    }
                    return $conto->importo ?? 0;
                };

                $capitoliConfig = $validated['capitoli_config'] ?? [];
                $syncData = [];

                if (!empty($capitoliConfig)) {
                    foreach ($capitoliConfig as $conf) {
                        $importoCents = (isset($conf['importo']) && $conf['importo'] !== '') ? MoneyHelper::toCents($conf['importo']) : null;
                        $syncData[$conf['id']] = ['importo' => $importoCents, 'note' => $conf['note'] ?? null];
                    }
                } else {
                    // Logica intelligenza sui deficit (preservata)
                    // Speso reale dal libro giornale: include regolazioni immediate
                    // e fatture pregresse, e scarta da sé le spese ad personam.
                    $fatturatoMap = app(SpesaPerVoceService::class)->perEsercizio($esercizio);

                    $fattureSforo = DB::table('righe_fattura')
                        ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                        ->where('fatture_passive.esercizio_id', $esercizio->id)
                        ->where('fatture_passive.stato_approvazione', 'sforo_motivato')
                        ->whereNull('righe_fattura.immobile_id')
                        ->select('righe_fattura.conto_id', 'fatture_passive.dati_extra', 'righe_fattura.importo_imponibile', 'righe_fattura.importo_iva')->get();

                    $coperturaVirtualeMap = [];
                    foreach ($fattureSforo as $row) {
                        $datiE = is_string($row->dati_extra) ? json_decode($row->dati_extra, true) : (array) $row->dati_extra;
                        $strat = $datiE['override_budget']['strategia_rientro'] ?? 'conguaglio_fine_anno'; 
                        if (in_array($strat, ['conguaglio_fine_anno', 'fondo_riserva'])) {
                            $coperturaVirtualeMap[(int)$row->conto_id] = ($coperturaVirtualeMap[(int)$row->conto_id] ?? 0) + abs((int)$row->importo_imponibile + (int)$row->importo_iva);
                        }
                    }

                    $coverageService = app(BudgetCoverageService::class);
                    $analisiBilancio = $coverageService->analyze($gestione, $fatturatoMap, $coperturaVirtualeMap);
                    $capitoliFinanziabili = collect($coverageService->getCapitoliFinanziabili($analisiBilancio))->keyBy('id');

                    /* if (!empty($validated['capitoli_ids'])) {
                        $conti = Conto::with('sottoconti')->findMany($validated['capitoli_ids']);
                        foreach ($conti as $c) {
                            $importoDaFinanziare = $capitoliFinanziabili->has($c->id) ? $capitoliFinanziabili->get($c->id)['importo_suggerito'] : $calcolaVeroTotale($c);
                            $syncData[$c->id] = ['importo' => $importoDaFinanziare, 'note' => 'Selezione rapida (Smart Budget)'];
                        } */
                    if (!empty($validated['capitoli_ids'])) {
                        $conti = Conto::with(['sottoconti' => fn($q) => $q->visibili()])->findMany($validated['capitoli_ids']);
                        foreach ($conti as $c) {
                            if ($c->sottoconti->isNotEmpty()) {
                                // SNAPSHOT: salviamo le foglie esistenti ORA, non il padre
                                foreach ($c->sottoconti as $sub) {
                                    $importoSub = $capitoliFinanziabili->has($sub->id)
                                        ? $capitoliFinanziabili->get($sub->id)['importo_suggerito']
                                        : ($sub->importo ?? 0);
                                    $syncData[$sub->id] = ['importo' => $importoSub, 'note' => 'Selezione rapida (Smart Budget)'];
                                }
                            } else {
                                // Padre senza figli: è già una foglia, comportamento invariato
                                $importoDaFinanziare = $capitoliFinanziabili->has($c->id)
                                    ? $capitoliFinanziabili->get($c->id)['importo_suggerito']
                                    : $calcolaVeroTotale($c);
                                $syncData[$c->id] = ['importo' => $importoDaFinanziare, 'note' => 'Selezione rapida (Smart Budget)'];
                            }
                        }
                    }else {

                        $capitoliOrfani = $gestione->pianoConto->conti()
                        ->visibili()
                        ->whereNull('parent_id')
                        ->where(function ($q) {
                            // Il padre stesso non deve essere in un piano attivo (caso padre=foglia)
                            $q->whereDoesntHave('pianiRate', fn($q2) => $q2->whereIn('stato', ['bozza', 'approvato']))
                            // E nessuno dei suoi sottoconti deve essere in un piano attivo (caso snapshot)
                            ->whereDoesntHave('sottoconti', fn($sub) =>
                                $sub->whereHas('pianiRate', fn($q2) => $q2->whereIn('stato', ['bozza', 'approvato']))
                            );
                        })
                        ->with(['sottoconti' => fn($q) => $q->visibili()])
                        ->get();

                        foreach ($capitoliOrfani as $c) {
                            if ($c->sottoconti->isNotEmpty()) {
                                // SNAPSHOT: salviamo le foglie esistenti ORA, non il padre
                                foreach ($c->sottoconti as $sub) {
                                    $importoSub = $capitoliFinanziabili->has($sub->id)
                                        ? $capitoliFinanziabili->get($sub->id)['importo_suggerito']
                                        : ($sub->importo ?? 0);
                                    $syncData[$sub->id] = ['importo' => $importoSub, 'note' => 'Inclusione automatica orfani'];
                                }
                            } else {
                                // Padre senza figli: è già una foglia, comportamento invariato
                                $importoDaFinanziare = $capitoliFinanziabili->has($c->id)
                                    ? $capitoliFinanziabili->get($c->id)['importo_suggerito']
                                    : $calcolaVeroTotale($c);
                                $syncData[$c->id] = ['importo' => $importoDaFinanziare, 'note' => 'Inclusione automatica orfani'];
                            }
                        }

                    }
                }
                $pianoRate->capitoli()->sync($syncData);

                // B2, S6 (decisione 20): i tratti di competenza per voce, sulla pivot appena nata.
                $competenzeNonApplicate = $this->scriviCompetenzeCapitoli($pianoRate, $validated['competenze_capitoli'] ?? []);
            }
            // --- [FINE MODIFICA CHIRURGICA] ---

            $pianoRate->load('capitoli');

            // 5. Ricorrenza
            if (!empty($validated['recurrence_enabled'])) {
                $this->pianoRateCreatorService->creaRicorrenza($pianoRate, $validated);
            }

            // Conversioni saldi in centesimi (Invariato)
            $saldiConfigCents = $validated['saldi_config'] ?? [];
            foreach ($saldiConfigCents as &$configSaldo) {
                if (isset($configSaldo['ripartizioni']) && is_array($configSaldo['ripartizioni'])) {
                    foreach ($configSaldo['ripartizioni'] as &$rip) {
                        if (isset($rip['importo']) && $rip['importo'] !== '') {
                            $rip['importo'] = MoneyHelper::toCents($rip['importo']);
                        }
                    }
                }
            }
            unset($configSaldo, $rip);

            // Il riparto manuale di un saldo solidale (Art. 63) è una decisione
            // dell'amministratore, non un dato ricalcolabile: va persistita, o
            // il primo ricalcolo la sostituisce col pro-quota automatico senza
            // dirlo. Arriva dal form solo qui, alla creazione.
            if (!empty($saldiConfigCents)) {
                $pianoRate->saldi_config = $saldiConfigCents;
                $pianoRate->save();
            }

            // 6. Generazione Rate fisiche (IMPORTANTE: L'Action ora troverà il tipo corretto)
            $statistiche = [];
            if (!empty($validated['genera_subito'])) {
                $statistiche = app(GeneratePianoRateAction::class)->execute(
                    pianoRate: $pianoRate, 
                    forzaApplicazioneSaldi: $applicareSaldi, 
                    saldiConfig: $saldiConfigCents,
                    accettaScoperti: $accettaScoperti,
                    accettaDestinatari: $accettaDestinatari,
                    notaDestinatari: $notaDestinatari,
                    esercizio: $esercizio,
                    notaScoperti: $notaScoperti
                );
            }

            // 7. Applicazione Saldi
            // Il lucchetto lo chiude GeneratePianoRateAction, intestandolo al
            // piano e solo sui saldi finiti davvero nelle quote. Bloccarlo qui
            // significava bloccarlo anche quando le rate non venivano generate:
            // saldi congelati da un piano che non li conteneva, e nessun modo
            // di riaprirli dalla UI.
            if ($applicareSaldi) {
                $gestione->refresh();
                $pianoRate->setRelation('gestione', $gestione);
            }

            // --- BUG FIX SCADENZIARIO ---
            // I piani straordinari nascono già in stato APPROVATO, bypassando updateStato().
            // Dobbiamo dispatchare l'evento manualmente affinché vengano creati gli avvisi
            // per amministratore e condòmini (controllo incassi, solleciti, ecc).
            if ($tipoPiano === 'straordinario' && !empty($validated['genera_subito'])) {
                PianoRateStatusUpdated::dispatch(
                    $condominio,
                    $esercizio,
                    $pianoRate,
                    Auth::user(),
                    StatoPianoRate::BOZZA,
                    StatoPianoRate::APPROVATO
                );
            }
            // ----------------------------

            DB::commit();
            return $this->redirectSuccess($condominio, $esercizio, $pianoRate, $validated, $statistiche, $competenzeNonApplicate ?? []);

        } catch (ScopertiNonAccettatiException $e) {
            DB::rollBack();
            return back()->withInput()->with('scoperti_warning', $e->getScoperti());
        } catch (DestinatariCambiatiException $e) {
            DB::rollBack();
            return back()->withInput()->with('destinatari_warning', $e->getCambiamenti());
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Errore store piano rate", ['msg' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->withInput()->with($this->flashError($e->getMessage()));
        }
    }
    
    /**
     * Mostra i dettagli di un singolo Piano Rate.
     * Carica tutte le relazioni necessarie (rate, quote, anagrafiche, immobili) e calcola
     * la copertura del budget (capitoli orfani) e la disponibilità per lo "sposta spesa".
     *
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @param PianoRate $pianoRate L'entità del piano rate da visualizzare
     * @return Response Renderizzazione del componente Vue di dettaglio
     */
    public function show(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate): Response
    {
        // --- [MODIFICA 1: Aggiunto fattureStraordinarie.fornitore al load] ---
        $pianoRate->load([
            'rate.rateQuote.anagrafica', 
            'rate.rateQuote.immobile', 
            'gestione.pianoConto', 
            'capitoli.sottoconti',
            'fattureStraordinarie.fornitore', 
            'budgetMovements.sourceConto',
            'budgetMovements.destinationConto',
            'budgetMovements.user'
        ]);

        // Calcolo voci di bilancio non coperte da questo (o altri) piani rate attivi
        $orfani = [];
        if ($pianoRate->gestione) {
            $coverageService = app(BudgetCoverageService::class);
            
            // 1. Recupero Veloce Fatturato e Virtuale
            // Speso reale dal libro giornale: include regolazioni immediate e
            // fatture pregresse, e scarta da sé le spese ad personam.
            $fatturatoMap = app(SpesaPerVoceService::class)->perEsercizio($esercizio);

            $fattureSforo = DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('fatture_passive.esercizio_id', $esercizio->id)
                ->where('fatture_passive.stato_approvazione', 'sforo_motivato')
                ->whereNull('righe_fattura.immobile_id')
                ->select('righe_fattura.conto_id', 'fatture_passive.dati_extra', 'righe_fattura.importo_imponibile', 'righe_fattura.importo_iva')->get();

            $coperturaVirtualeMap = [];
            foreach ($fattureSforo as $row) {
                $datiExtra = is_string($row->dati_extra) ? json_decode($row->dati_extra, true) : (array) $row->dati_extra;
                $strat = $datiExtra['override_budget']['strategia_rientro'] ?? 'conguaglio_fine_anno'; 
                if (in_array($strat, ['conguaglio_fine_anno', 'fondo_riserva'])) {
                    $coperturaVirtualeMap[(int)$row->conto_id] = ($coperturaVirtualeMap[(int)$row->conto_id] ?? 0) + abs((int)$row->importo_imponibile + (int)$row->importo_iva);
                }
            }

            // 2. Usiamo la nuova intelligenza!
            $report = $coverageService->analyze($pianoRate->gestione, $fatturatoMap, $coperturaVirtualeMap);

            // ⚠️ Beta.73: quali voci hanno ancora un residuo aperto da "Sposta spesa", in
            // qualunque piano rate di QUESTA gestione — non solo questo piano, perché il deficit
            // misurato da analyze() è già aggregato a livello di gestione. Saldo netto, non
            // semplice presenza: una voce stornata per intero non deve portare l'etichetta, o
            // direbbe che Sposta Spesa l'ha lasciata scoperta quando non è più vero (trovato in
            // Fase 1-bis, verificando che lo storno chiudesse davvero il cerchio).
            $pianiRateIds = $pianoRate->gestione->pianiRate()->pluck('id');
            $ceduto = BudgetMovement::whereIn('piano_rate_id', $pianiRateIds)
                ->selectRaw('source_conto_id, SUM(amount) as tot')
                ->groupBy('source_conto_id')
                ->pluck('tot', 'source_conto_id');
            $ricevuto = BudgetMovement::whereIn('piano_rate_id', $pianiRateIds)
                ->selectRaw('destination_conto_id, SUM(amount) as tot')
                ->groupBy('destination_conto_id')
                ->pluck('tot', 'destination_conto_id');
            $vociDaSpostaSpesa = [];
            foreach ($ceduto as $contoId => $totCeduto) {
                if (((int) $totCeduto - (int) ($ricevuto[$contoId] ?? 0)) > 0) {
                    $vociDaSpostaSpesa[(int) $contoId] = true;
                }
            }

            // 3. Estraiamo solo quelli che hanno davvero bisogno di soldi
            $orfani = collect($coverageService->getCapitoliFinanziabili($report, $vociDaSpostaSpesa))
                ->map(fn($item) => [
                    'id'               => $item['id'],
                    'nome'             => $item['padre'] ? "— " . $item['nome'] : $item['nome'],
                    'importo'          => $item['importo_suggerito'],
                    'da_sposta_spesa'  => $item['da_sposta_spesa'], 
                ])->values()->toArray();
        }
        
        $coperturaData = [
            'scoperto_count' => count($orfani), 
            'orfani' => $orfani
        ];

        // 1. Troviamo gli ID delle rate di questo piano che sono state emesse ma "nascoste"
        $rateNascosteIds = Evento::where('meta->type', 'scadenza_rata_condomino')
            ->where('meta->context->piano_rate_id', $pianoRate->id)
            ->where('visibility', VisibilityStatus::HIDDEN->value)
            ->get()
            ->pluck('meta.context.rata_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->toArray();

        // 2. Estrazione delle sole scadenze (Rate pure) per la timeline
        $ratePure = $pianoRate->rate()
            ->orderBy('numero_rata')
            ->get()
            ->map(function ($rata) use ($rateNascosteIds) {
                $isEmessa = $rata->rateQuote()->whereNotNull('scrittura_contabile_id')->exists();
                $isPublished = $isEmessa ? !in_array($rata->id, $rateNascosteIds) : false;

                return [
                    'id' => $rata->id, 
                    'numero_rata' => $rata->numero_rata, 
                    'is_emessa' => $isEmessa, 
                    'is_published' => $isPublished, 
                    'totale_rata' => MoneyHelper::fromCents($rata->importo_totale)
                ];
            });

        // --- [MODIFICA 2: Il Bivio per leggere Fatture o Capitoli] ---
        if ($pianoRate->tipo === 'straordinario') {
            // Logica Piani Straordinari (Legge dalle fatture)
            $sources = $pianoRate->fattureStraordinarie->map(function ($fattura) {
                $importoReale = $fattura->pivot->importo_collegato ?? 0;
                return [
                    'id'                => $fattura->id,
                    'nome'              => 'Ft. ' . ($fattura->numero_documento ?? 'S/N') . ' (' . ($fattura->fornitore->ragione_sociale ?? 'Fornitore') . ')',
                    'importo_residuo'   => $importoReale,
                    'formatted_residuo' => MoneyHelper::format($importoReale, false)
                ];
            });
        } else {
            // Logica Piani Ordinari (Legge dai capitoli come faceva prima)
            $sources = $pianoRate->capitoli->map(function ($conto) {
                $importoReale = $conto->pivot->importo ?? $conto->importo; 
                return [
                    'id'                => $conto->id,
                    'nome'              => $conto->nome,
                    'importo_residuo'   => $importoReale,
                    'formatted_residuo' => MoneyHelper::format($importoReale, false)
                ];
            });
        }
        // -------------------------------------------------------------

        $destinations = [];
        $pianoContoId = $pianoRate->gestione->pianoConto?->id;

        if ($pianoContoId) {
            $destinations = Conto::where('piano_conto_id', $pianoContoId)
                ->orderBy('nome')
                ->visibili()
                ->get(['id', 'nome'])
                ->map(fn($c) => [
                    'id' => $c->id,
                    'nome' => $c->nome,
                ]);
        } else {
            Log::warning("Sposta Spesa: Nessun Piano Conto trovato per la gestione {$pianoRate->gestione_id}");
        }

        return Inertia::render('gestionale/pianiRate/PianiRateShow', [
            'condominio' => $condominio, 
            'esercizio' => $esercizio, 
            'pianoRate' => new PianoRateResource($pianoRate),
            'ratePure' => $ratePure, 
            'quotePerAnagrafica' => $this->pianoRateQuoteService->quotePerAnagrafica($pianoRate),
            'quotePerImmobile' => $this->pianoRateQuoteService->quotePerImmobile($pianoRate),
            // Il verdetto sull'allineamento arriva dal server, calcolato dallo **stesso** metodo
            // che usa il cruscotto: fino all'11/08/2026 le due schermate se lo calcolavano da
            // sole, con metodi e tolleranze diverse, e potevano contraddirsi.
            'disallineato' => $this->pianoRateQuoteService->eDisallineato($pianoRate),
            'needsMigration' => false, 
            'copertura' => $coperturaData,
            'sources' => $sources, // <--- Ora questo conterrà le fatture!
            'destinations' => $destinations,
            'has_unpublished_rates' => Evento::where('meta->type', 'scadenza_rata_condomino')
                ->where('meta->context->piano_rate_id', $pianoRate->id)
                ->where('meta->is_emitted', true)        
                ->where('meta->is_published', false)     
                ->exists(),
        ]);
    }

    /**
     * Aggiorna lo stato di approvazione del Piano Rate.
     * Cambiare lo stato dispatcha l'evento 'PianoRateStatusUpdated', che viene 
     * intercettato dai Listener per aggiungere (se approvato) o rimuovere (se in bozza) gli eventi nello scadenziario generale.
     *
     * @param Request $request Richiesta contenente il booleano 'approvato'
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @param PianoRate $pianoRate Il piano rate da aggiornare
     * @return RedirectResponse Risposta con messaggio flash di successo
     */
    /**
     * Aggiorna lo stato di approvazione del Piano Rate e salva i dati della delibera.
     * Cambiare lo stato dispatcha l'evento 'PianoRateStatusUpdated', che viene 
     * intercettato dai Listener per aggiungere o rimuovere gli eventi nello scadenziario.
     *
     * @param Request $request
     * @param Condominio $condominio
     * @param Esercizio $esercizio
     * @param PianoRate $pianoRate
     * @return RedirectResponse
     */
    public function updateStato(Request $request, Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate)
    {
        // Con «Urgenza» (art. 1135 co. 2 c.c.) non c'è una delibera da registrare: la data non si chiede e, se
        // arriva, non si scrive — stessa guardia di `store()` (decisione 12, verifica S6 R2, S8-32). La
        // competenza dello straordinario d'urgenza si dichiara sulle fatture del piano.
        $urgenza = $pianoRate->tipo_autorizzazione === 'urgenza';
        $validated = $request->validate([
            'approvato'               => 'required|boolean',
            'data_delibera_assemblea' => ['nullable', 'date', Rule::requiredIf(fn () => $request->boolean('approvato') && ! $urgenza)],
            'numero_verbale'          => 'nullable|string|max:50',
            'nota_approvazione'       => 'nullable|string|max:500',
        ]);
        
        $vecchioStato = $pianoRate->stato; // È già un oggetto Enum
        $nuovoStato = $validated['approvato'] ? StatoPianoRate::APPROVATO : StatoPianoRate::BOZZA;
        
        $updateData = ['stato' => $nuovoStato];
        $dataPrecedente = $pianoRate->data_delibera_assemblea?->toDateString();
        $dataCambiata = false;

        if ($validated['approvato']) {
            if (! $urgenza) {
                $updateData['data_delibera_assemblea'] = $validated['data_delibera_assemblea'];
                $dataCambiata = ! empty($validated['data_delibera_assemblea']) && $dataPrecedente !== null && $dataPrecedente !== \Carbon\CarbonImmutable::parse($validated['data_delibera_assemblea'])->toDateString();
            }
            $updateData['numero_verbale']          = $validated['numero_verbale'] ?? null;
            $updateData['nota_approvazione']       = $validated['nota_approvazione'] ?? null;
            $updateData['approvato_da_user_id']    = Auth::id();
            $updateData['approvato_il']            = now();
        } else {
            // Torna in bozza: azzera l'audit dell'approvazione, **non** la data della delibera — è un fatto
            // dell'assemblea, non del programma, e senza di essa uno straordinario non si rigenera (decisione
            // 12; verifica S6). Si riscrive alla prossima approvazione, se è cambiata.
            $updateData['numero_verbale']          = null;
            $updateData['nota_approvazione']       = null;
            $updateData['approvato_da_user_id']    = null;
            $updateData['approvato_il']            = null;
        }

        $pianoRate->update($updateData);
        
        PianoRateStatusUpdated::dispatch(
            $condominio, 
            $esercizio, 
            $pianoRate, 
            Auth::user(), 
            $vecchioStato, 
            $nuovoStato
        );
        
        // S8-22: su uno straordinario con quote già generate, cambiare la data della delibera sposta il gradino
        // della competenza (decisione 12) ma le quote restano quelle calcolate con la data di prima. Si accetta
        // la correzione e si dice di ricalcolare; l'emissione lo pretende (EmissioneRateController::store).
        if ($dataCambiata && NaturaGestione::daStringa($pianoRate->gestione?->tipo) === NaturaGestione::Straordinaria && $pianoRate->rate()->exists()) {
            $formato = fn (string $d) => \Carbon\CarbonImmutable::parse($d)->locale('it')->translatedFormat('j F Y');

            return back()->with($this->flashWarning(sprintf(
                'Data della delibera aggiornata dal %s al %s. Le quote già generate sono state calcolate con la data precedente: ricalcola il piano prima di emettere le rate.',
                $formato($dataPrecedente), $formato($validated['data_delibera_assemblea'])
            )));
        }

        $messaggio = $validated['approvato']
            ? ($urgenza ? 'Piano approvato. Intervento d\'urgenza: nessuna delibera da registrare, la competenza è quella dichiarata sulle fatture.' : 'Piano approvato e delibera registrata con successo.')
            : 'Piano riportato in bozza. La data della delibera resta registrata; verbale e note dell\'approvazione sono stati azzerati.';

        return back()->with($this->flashSuccess($messaggio));
    }

    /**
     * Endpoint API per il Frontend.
     * Recupera i dettagli analitici dei saldi per una specifica gestione, non ancora bloccati.
     * Identifica il ruolo dell'anagrafica sull'immobile (es. proprietario) per supportare
     * la logica di ripartizione avanzata (Subentri Art. 63) all'interno del form di creazione.
     *
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile
     * @param Gestione $gestione La gestione selezionata nel frontend
     * @return \Illuminate\Http\JsonResponse Un array JSON di Saldi pronti per la modale Vue
     */
    public function fetchSaldiAnalitici(
        Condominio $condominio,
        Esercizio $esercizio,
        Gestione $gestione,
        \App\Actions\PianoRate\GenerateSaldiAction $anteprima
    ) {
        $saldi = Saldo::where('gestione_id', $gestione->id)
            ->where('is_applicato', false)
            // Terzo posto in cui `saldi` veniva letta senza separare le due famiglie che ci
            // convivono (beta.43). Un debito verso fornitore non ha anagrafica né immobile,
            // quindi compariva qui come saldo «solidale» intestato a «Unità Sconosciuta» — e
            // l'amministratore poteva configurargli un riparto manuale che `GenerateSaldiAction`
            // non avrebbe mai applicato, visto che di là il filtro `whereNull('fornitore_id')`
            // c'è da sempre. Stesso filtro, così le due estremità del flusso guardano la stessa
            // lista.
            ->whereNull('fornitore_id')
            // Le righe a zero non entrano mai nella generazione: mostrarle qui
            // farebbe configurare una ripartizione che non verrà mai applicata.
            ->where('saldo_iniziale', '!=', 0)
            ->with(['anagrafica', 'immobile'])
            ->get()
            ->map(function($s) use ($condominio, $gestione, $anteprima) {
                $ruolo = null;
                if ($s->anagrafica_id && $s->immobile_id) {
                    // Nessun filtro su `attivo`, come prima (il saldo può essere di un titolare
                    // cessato); B1 aggiunge solo l'ordine deterministico per quando B2 ammetterà
                    // due periodi della stessa persona sulla stessa unità.
                    $ruolo = app(RisolutoreTitolari::class)->ordinePreferenza(
                        DB::table('anagrafica_immobile')
                            ->where('anagrafica_id', $s->anagrafica_id)
                            ->where('immobile_id', $s->immobile_id)
                    )->value('tipologia');
                }

                return [
                    'id' => $s->id,
                    'anagrafica_id' => $s->anagrafica_id,
                    'tipo' => $s->anagrafica_id ? 'nominale' : 'solidale',
                    'soggetto_nome' => $s->anagrafica_id ? $s->anagrafica->nome : "Unità " . ($s->immobile->nome ?? 'Sconosciuta'),
                    // `etichettaEstesa`: senza interno restava «Posto auto 3 (Int. )», con la parentesi vuota.
                    'immobile_nome' => $s->immobile ? $s->immobile->etichettaEstesa : 'N/D',
                    'ruolo' => $ruolo ?? ($s->anagrafica_id ? 'Anagrafica' : 'Condominio'),
                    'importo' => $s->saldo_iniziale,
                    'is_debito' => $s->saldo_iniziale > 0,
                    'immobile_id' => $s->immobile_id,
                    // L'anteprima del riparto automatico: chi pagherebbe cosa se il piano
                    // venisse generato adesso. Solo per i solidali — un saldo nominale ha già
                    // il suo destinatario e non c'è niente da risolvere. La calcola il server
                    // con le stesse funzioni del generatore, non il frontend: un'anteprima che
                    // ricalcola a modo suo diverge al primo cambio (beta.35).
                    'riparto_previsto' => $s->anagrafica_id
                        ? null
                        : $anteprima->anteprimaSolidale($s, $gestione),
                ];
            });

        return response()->json($saldi);
    }

    /**
     * Elimina un Piano Rate dal sistema.
     * Applica controlli rigidi (Il Muro Contabile):
     * 1. Blocca se ci sono pagamenti registrati.
     * 2. Blocca se ci sono emissioni in partita doppia (Libro Giornale).
     * 3. Blocca se il piano è Approvato (forzando il ripristino in Bozza per eliminare gli eventi dello scadenziario).
     * Se i controlli passano, elimina il piano e riapre il lucchetto sui saldi intestati
     * a questo piano (saldi.piano_rate_id), lasciando intatti quelli di altri piani e i
     * debiti verso fornitori.
     *
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @param PianoRate $pianoRate Il piano rate da distruggere
     * @return RedirectResponse Redirect alla lista dei piani rate con messaggio di successo o errore
     */
    public function destroy(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate): RedirectResponse
    {
        // 1. IL MURO CONTABILE: Controlli prima di permettere l'eliminazione
        
        // A. Controllo Incassi (Pagamenti registrati)
        // `importo_pagato ≠ 0`: un credito compensato o rimborsato è un movimento come un incasso (B2, S6).
        $hasPagamenti = $pianoRate->haIncassiRegistrati();

        if ($hasPagamenti) {
            return back()->with($this->flashError(
                'Impossibile eliminare il piano rate: ci sono incassi registrati, o crediti già usati in compensazione o rimborsati. ' .
                'Annulla prima quei movimenti.'
            ));
        }

        // B. Controllo Emissioni (Scritture sul Libro Giornale)
        $hasEmissioni = $pianoRate->rate()->whereHas('rateQuote', function ($q) {
            $q->whereNotNull('scrittura_contabile_id');
        })->exists();

        if ($hasEmissioni) {
            return back()->with($this->flashError(
                'Impossibile eliminare il piano rate: le rate risultano già emesse in contabilità. ' .
                'Usa l\'opzione "Annulla Emissioni" all\'interno del piano rate prima di eliminarlo.'
            ));
        }

        // C. Controllo Approvazione (Ping-Pong Scadenziario)
        if ($pianoRate->stato === StatoPianoRate::APPROVATO) {
            return back()->with($this->flashError(
                'Impossibile eliminare un piano rate approvato. ' .
                'Devi prima togliere l\'approvazione (riportandolo in Bozza) affinché il sistema elimini automaticamente in modo pulito gli eventi dallo scadenziario.'
            ));
        }

        // 2. ELIMINAZIONE E SBLOCCO SALDI
        try {
            DB::beginTransaction();

            $gestione = $pianoRate->gestione;

            if ($gestione) {
                // Lo sblocco segue l'intestazione (saldi.piano_rate_id): si
                // riaprono i saldi che QUESTO piano teneva chiusi, non tutti
                // quelli della gestione — i debiti verso fornitori vivono nella
                // stessa tabella con is_applicato=true e non vanno toccati.
                $rilasciati = $this->saldoService->rilasciaLucchetti($pianoRate, $gestione);

                // Rete di sicurezza per i piani antecedenti alla beta.32 che la
                // migrazione di riparazione non è riuscita a intestare: si torna
                // al vecchio criterio, dedotto dal contenuto delle quote.
                if ($rilasciati === 0 && $gestione->saldo_applicato && $this->pianoContieneSaldi($pianoRate)) {
                    Saldo::where('gestione_id', $gestione->id)
                        ->where('is_applicato', true)
                        ->whereNull('fornitore_id')
                        // Mai toccare un lucchetto che ha già un titolare: quello
                        // appartiene a un altro piano, che continua ad addebitarlo.
                        ->whereNull('piano_rate_id')
                        ->update(['is_applicato' => false, 'piano_rate_id' => null]);

                    $this->saldoService->allineaFlagGestione($gestione);
                }
            }

           // 1. RIACCENSIONE SEMAFORO DASHBOARD (Per Piani Straordinari)
            if ($pianoRate->tipo === 'straordinario') {
                $fattureIds = $pianoRate->fattureStraordinarie()->pluck('fatture_passive.id')->toArray();
                
                if (!empty($fattureIds)) {
                    DB::table('righe_fattura')
                        ->whereIn('fattura_passiva_id', $fattureIds)
                        ->where(function ($query) {
                            $query->where('is_sopravvenienza', true)
                                  ->orWhereNotNull('immobile_id');
                        })
                        ->update(['is_rateizzata' => false]); // Riaccende l'allarme
                }
            }

            // 2. Sganciamo le relazioni (Sia per Ordinario che Straordinario)
            $pianoRate->capitoli()->detach();
            $pianoRate->fattureStraordinarie()->detach();

            // 3. Elimina fisicamente il piano rate
            $pianoRate->delete();
            
            DB::commit();

            return to_route('admin.gestionale.esercizi.piani-rate.index', [
                'condominio' => $condominio->id, 
                'esercizio' => $esercizio->id
            ])->with($this->flashSuccess(__('gestionale.success_delete_piano_rate')));

        } catch (\Throwable $e) {

            DB::rollBack();

            Log::error("Errore cancellazione piano rate", ['msg' => $e->getMessage()]);
            
            return to_route('admin.gestionale.esercizi.piani-rate.index', [
                'condominio' => $condominio->id, 
                'esercizio' => $esercizio->id
            ])->with($this->flashError(__('gestionale.error_delete_piano_rate')));
        }
    }

    /**
     * Vecchio criterio (pre beta.32): il piano contiene tracce di saldo pregresso
     * nelle quote generate? Usato solo come rete di sicurezza per dati storici —
     * è fragile per costruzione, perché un ricalcolo cancella proprio quelle quote.
     */
    private function pianoContieneSaldi(PianoRate $pianoRate): bool
    {
        foreach ($pianoRate->rate()->with('rateQuote')->get() as $rata) {
            foreach ($rata->rateQuote as $quota) {
                // Retrocompatibilità V1.8
                if ($quota->tipo === 'saldo_iniziale') {
                    return true;
                }

                // regole_calcolo è già un array grazie al cast nel modello
                $regole = $quota->regole_calcolo;

                if (is_array($regole) && !empty($regole['importi']['saldo_usato'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Rimuove uno specifico capitolo di spesa da un Piano Rate esistente.
     * Elimina e ricalcola le rate basandosi sui capitoli rimanenti, verificando
     * che il capitolo da eliminare non sia vincolato da incassi, emissioni o spostamenti manuali di budget (BudgetMovement).
     *
     * @param Condominio $condominio Il condominio corrente
     * @param Esercizio $esercizio L'esercizio contabile corrente
     * @param PianoRate $pianoRate Il piano rate target
     * @param int $capitoloId L'ID del conto/capitolo da sganciare
     * @return RedirectResponse Redirect alla vista di dettaglio con esito operazione
     */
    public function detachCapitolo(Request $request, Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate, $capitoloId)
    {
        // B2, cancello (2): il ricalcolo dopo la rimozione può cambiare i destinatari rispetto al piano
        // com'era (un titolare chiuso nel frattempo dall'elenco titolari). La presa d'atto è
        // dell'amministratore, non del programma: stessa forma di `PianoRateGenerationController`.
        $request->validate([
            'accetta_destinatari' => 'nullable|boolean',
            'nota_destinatari' => 'required_if_accepted:accetta_destinatari|nullable|string|min:10',
        ]);
        $accettaDestinatari = (bool) $request->boolean('accetta_destinatari', false);
        $notaDestinatari    = $request->string('nota_destinatari')->trim()->value() ?: null;

        if ($pianoRate->haIncassiRegistrati()) {
            return back()->with($this->flashError("Impossibile modificare: ci sono incassi registrati, o crediti già usati o rimborsati."));
        }
        
        if ($pianoRate->rate()->whereHas('rateQuote', fn($q) => $q->whereNotNull('scrittura_contabile_id'))->exists()) {
            return back()->with($this->flashError("Annulla le emissioni prima di modificare le voci."));
        }

        // ⚠️ Il saldo NETTO, non la semplice esistenza di righe storiche — beta.73.
        //
        // Fino a questa beta il controllo guardava se la voce era MAI comparsa in un movimento,
        // in QUALUNQUE piano rate: un blocco permanente, perché nessuno storno esisteva per
        // farlo tornare a zero (il messaggio diceva «restituisci i fondi», ma non c'era nessuna
        // rotta per farlo davvero). E lo scope era comunque sbagliato: un movimento scritto su
        // un ALTRO piano rate non lascia nessuna traccia nel pivot DI QUESTO piano — bloccare la
        // rimozione qui per una storia che appartiene a un piano diverso non protegge niente.
        //
        // Ora il controllo è scoped a questo piano soltanto, e guarda il saldo netto: se la voce
        // ha ricevuto e restituito lo stesso importo (uno storno completo), è di nuovo rimovibile.
        $nettoRicevuto = (int) BudgetMovement::where('piano_rate_id', $pianoRate->id)
            ->where('destination_conto_id', $capitoloId)
            ->sum('amount');
        $nettoCeduto = (int) BudgetMovement::where('piano_rate_id', $pianoRate->id)
            ->where('source_conto_id', $capitoloId)
            ->sum('amount');
        $saldoNetto = $nettoRicevuto - $nettoCeduto;

        if ($saldoNetto !== 0) {
            $importo = number_format(abs($saldoNetto) / 100, 2, ',', '.');
            $messaggio = $saldoNetto > 0
                ? "Questa voce ha ricevuto € {$importo} netti da altre voci con Sposta Spesa, in questo piano rate. "
                : "Questa voce ha ceduto € {$importo} netti ad altre voci con Sposta Spesa, in questo piano rate. ";

            return back()->with($this->flashError(
                $messaggio . "Storna i movimenti dallo storico (icona dell'orologio) prima di rimuoverla."
            ));
        }

        try {
            DB::beginTransaction();
            
            // Sgancia il capitolo, elimina le rate attuali e le ricalcola
            $pianoRate->capitoli()->detach($capitoloId);
            
            $vecchioStato = $pianoRate->stato;
            
            if ($vecchioStato === \App\Enums\StatoPianoRate::APPROVATO) {
                PianoRateStatusUpdated::dispatch(
                    $condominio, $esercizio, $pianoRate, Auth::user(), 
                    $vecchioStato, \App\Enums\StatoPianoRate::BOZZA
                );
            }

            $pianoRate->rate()->delete();
            // Gli scoperti erano già stati accettati alla generazione: la rimozione di una voce non li
            // riapre. Il cancello (2) invece sì, se scatta: la generazione precedente può non averlo mai
            // attraversato (verifica indipendente S4, 19/09).
            app(GeneratePianoRateAction::class)->execute(
                pianoRate: $pianoRate,
                accettaScoperti: true,
                accettaDestinatari: $accettaDestinatari,
                notaDestinatari: $notaDestinatari,
                esercizio: $esercizio,
            ); 
            
            if ($vecchioStato === \App\Enums\StatoPianoRate::APPROVATO) {
                PianoRateStatusUpdated::dispatch(
                    $condominio, $esercizio, $pianoRate, Auth::user(), 
                    \App\Enums\StatoPianoRate::BOZZA, $vecchioStato
                );
            }
            
            DB::commit();
            return back()->with($this->flashSuccess("Voce rimossa e ricalcolata."));
            
        } catch (DestinatariCambiatiException $e) {
            // Il rollback annulla anche il detach: la voce è ancora nel piano finché non c'è la presa d'atto.
            DB::rollBack();
            return back()
                ->with('destinatari_warning', $e->getCambiamenti())
                ->with('destinatari_warning_detach', (int) $capitoloId);
        } catch (\Throwable $e) {
            DB::rollBack();
            return back()->with($this->flashError("Errore durante la rimozione: " . $e->getMessage()));
        }
    }

    /**
     * Helper di redirezione centralizzato.
     * Restituisce un feedback all'utente a seconda che il piano rate sia stato solo creato in bozza o anche popolato fisicamente di rate.
     *
     * @param Condominio $condominio
     * @param Esercizio $esercizio
     * @param PianoRate $pianoRate
     * @param array $validated L'array dei campi validati
     * @param array $statistiche Statistiche generate dalla action (non usate al momento)
     * @return RedirectResponse
     */
    /**
     * Scrive in `competenze_capitolo` i tratti dichiarati per voce (B2, S6, decisione 20), **dopo** il
     * `sync` della pivot, e torna i nomi delle voci a cui non è stato possibile applicarli.
     *
     * La regola segue il motore (`CalcoloQuoteService::impostaCompetenzaPerConto`): per un conto cerca la
     * pivot del conto stesso, poi quella della radice. Quindi un tratto dichiarato su un conto che ha la
     * sua pivot va lì; dichiarato su un padre la cui pivot è nata sulle foglie (selezione rapida, orfani) va
     * su **ogni foglia** presente; dichiarato su una voce che nel piano non c'è — un capitolo già in un altro
     * piano attivo, per esempio — non ha dove stare, e lo si dice invece di tacerlo.
     *
     * @return list<string> nomi delle voci non applicate
     */
    private function scriviCompetenzeCapitoli(PianoRate $pianoRate, array $competenze): array
    {
        if ($competenze === []) {
            return [];
        }

        $pivotPerConto = DB::table('piano_rate_capitoli')->where('piano_rate_id', $pianoRate->id)->pluck('id', 'conto_id');
        $nonApplicate = [];

        foreach ($competenze as $voce) {
            $contoId = (int) $voce['conto_id'];
            $tratti = collect($voce['tratti'])->map(fn ($t) => ['dal' => substr((string) $t['dal'], 0, 10), 'al' => substr((string) $t['al'], 0, 10)])->sortBy('dal')->values();

            $pivotIds = [];
            if ($pivotPerConto->has($contoId)) {
                $pivotIds = [(int) $pivotPerConto[$contoId]];
            } else {
                $conto = Conto::with('sottoconti')->find($contoId);
                foreach ($conto?->sottoconti ?? [] as $foglia) {
                    if ($pivotPerConto->has($foglia->id)) {
                        $pivotIds[] = (int) $pivotPerConto[$foglia->id];
                    }
                }
            }

            if ($pivotIds === []) {
                $nonApplicate[] = Conto::whereKey($contoId)->value('nome') ?? "conto #{$contoId}";
                continue;
            }

            foreach ($pivotIds as $pivotId) {
                \App\Models\Gestionale\CompetenzaCapitolo::where('piano_rate_capitolo_id', $pivotId)->delete();
                foreach ($tratti as $ordine => $t) {
                    \App\Models\Gestionale\CompetenzaCapitolo::create([
                        'piano_rate_capitolo_id' => $pivotId,
                        'dal' => $t['dal'],
                        'al' => $t['al'],
                        'ordine' => $ordine,
                    ]);
                }
            }
        }

        return $nonApplicate;
    }

    protected function redirectSuccess(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate, array $validated, array $statistiche = [], array $competenzeNonApplicate = []) 
    {
        $message = !empty($validated['genera_subito']) 
            ? "Piano rate creato e generato con successo!" 
            : "Piano rate creato con successo!";

        // B2, S6: una competenza dichiarata su una voce che nel piano non c'è non si perde in silenzio.
        if ($competenzeNonApplicate !== []) {
            $message .= sprintf(' La competenza dichiarata su «%s» non è stata applicata: %s nel piano (già in un altro piano attivo, o senza budget).',
                implode('», «', $competenzeNonApplicate), count($competenzeNonApplicate) === 1 ? 'la voce non è' : 'le voci non sono');
        }
            
        return redirect()->route('admin.gestionale.esercizi.piani-rate.show', [
            'condominio' => $condominio->id, 
            'esercizio' => $esercizio->id, 
            'pianoRate' => $pianoRate->id
        ])->with('success', $message);
    }
}