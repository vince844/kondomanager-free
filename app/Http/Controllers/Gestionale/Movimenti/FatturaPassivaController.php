<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Enums\StatoPagamentoFattura;
use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Pagamenti\FatturaModificaVietataException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreFatturaRequest;
use App\Http\Requests\Gestionale\Movimenti\UpdateFatturaRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\FatturaCopertura;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\PagamentoFornitoreService;
use App\Services\Gestionale\SpesaPerVoceService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Controller per la gestione delle Fatture Passive (Ciclo Passivo).
 * * Gestisce la visualizzazione, la creazione, l'eliminazione sicura e il download
 * dei documenti fiscali passivi (fatture fornitori e note di credito) per un condominio.
 */
class FatturaPassivaController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    /**
     * Inizializza il controller iniettando il service per le fatture passive.
     *
     * @param  FatturaPassivaService  $service  Il servizio che contiene la logica di business e la partita doppia.
     */
    public function __construct(
        private FatturaPassivaService $service,
        private SpesaPerVoceService $spesaPerVoce,
    ) {}

    /**
     * Mostra l'elenco delle fatture passive del condominio selezionato.
     *
     * Permette di filtrare i documenti per stato di pagamento, stato di approvazione, testo libero
     * (numero documento o ragione sociale del fornitore) e intervallo di data documento.
     *
     * @param  Request  $request  La richiesta HTTP contenente i filtri (search, stato_pagamento, data_da, data_a, ecc).
     * @param  Condominio  $condominio  Il condominio di cui si stanno visualizzando le fatture.
     * @return Response Vista Inertia con i dati paginati e le statistiche sommarie.
     */
    public function index(Request $request, Condominio $condominio): Response
    {
        $fatture = FatturaPassiva::where('condominio_id', $condominio->id)
            // `coperture`, `pianiRate`, `scritture` ed `esercizio` servono a
            // motivoBloccoEliminazione(): caricate qui una volta, invece di
            // sette query per riga moltiplicate per le venti righe di pagina.
            ->with(['fornitore', 'righe', 'documenti', 'coperture', 'pianiRate', 'scritture', 'esercizio'])
            ->when($request->stato_pagamento, fn ($q, $v) => $q->where('stato_pagamento', $v))
            ->when($request->stato_approvazione, fn ($q, $v) => $q->where('stato_approvazione', $v))
            ->when($request->search, fn ($q, $v) => $q->where('numero_documento', 'like', "%{$v}%")
                ->orWhereHas('fornitore', fn ($qf) => $qf->where('ragione_sociale', 'like', "%{$v}%"))
            )
            ->when($request->data_da, fn ($q, $v) => $q->whereDate('data_documento', '>=', $v))
            ->when($request->data_a, fn ($q, $v) => $q->whereDate('data_documento', '<=', $v))
            ->orderByDesc('data_documento')
            ->paginate(20)
            ->withQueryString();

        // Il motivo del divieto viaggia col dato, non ricostruito dal frontend:
        // `null` significa eliminabile. Finché il menu deduceva da sé chi fosse
        // eliminabile, guardava due condizioni su sette e sbagliava in entrambi
        // i versi — nascondeva la voce senza spiegare, o la mostrava e poi il
        // server la rifiutava.
        $fatture->through(fn (FatturaPassiva $fattura) => $fattura->append('motivo_blocco_eliminazione'));

        $listaCondomini = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio = $this->getEsercizioCorrente($condominio);

        // beta.30 — ponte verso il Libro Giornale. Una Regolazione Immediata non crea
        // mai una riga in `fatture_passive`, quindi in questa pagina non comparirà mai:
        // è corretto, ma il pulsante per crearle sta proprio in questa toolbar, e chi
        // le registra da qui poi non le ritrova da qui («ho fatto 2 registrazioni ma non
        // si trovano da nessuna parte» — segnalazione di un amministratore in beta.29).
        // Stesse condizioni della query del Libro Giornale, così il numero mostrato
        // coincide con quello che si trova cliccando.
        $regolazioniImmediate = $esercizio
            ? ScritturaContabile::where('condominio_id', $condominio->id)
                ->where('esercizio_id', $esercizio->id)
                ->where('tipo_movimento', TipoMovimentoContabile::REGOLAZIONE_IMMEDIATA)
                ->count()
            : 0;

        $stats = [
            'totale_aperte' => FatturaPassiva::where('condominio_id', $condominio->id)->where('stato_pagamento', StatoPagamentoFattura::APERTA)->count(),
            'totale_sfori' => FatturaPassiva::where('condominio_id', $condominio->id)->where('stato_approvazione', 'sforo_motivato')->count(),
            'importo_da_pagare' => FatturaPassiva::where('condominio_id', $condominio->id)->where('stato_pagamento', StatoPagamentoFattura::APERTA)->sum('netto_a_pagare'),
        ];

        return Inertia::render('gestionale/movimenti/fatture/FatturaRegisterList', [
            'condominio' => $condominio,
            'fatture' => $fatture,
            'stats' => $stats,
            'regolazioniImmediate' => $regolazioniImmediate,
            'esercizio' => $esercizio,
            'condomini' => $listaCondomini,
            'statiPagamento' => collect(StatoPagamentoFattura::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => $c->label(),
            ])->values(),
            'filters' => $request->only(['stato_pagamento', 'stato_approvazione', 'search', 'data_da', 'data_a']),
        ]);
    }

    /**
     * Mostra il form per la registrazione di una nuova fattura passiva.
     *
     * Prepara e calcola dinamicamente:
     * - I saldi residui reali per il controllo sfori (Budget Cap).
     * - Il protocollo contabile suggerito per l'anno in corso.
     * - I fondi di riserva e le capienze per le fatture pregresse (Rata 0).
     * - Lo storico recente per la prevenzione dei duplicati.
     *
     * @param  Condominio  $condominio  Il condominio in cui si sta registrando la fattura.
     * @return Response Vista Inertia contenente il "Matrix Workspace" e tutte le dipendenze calcolate.
     */
    public function create(Condominio $condominio): Response
    {
        $listaCondomini = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio = $this->getEsercizioCorrente($condominio);

        // --- AUTOGENERAZIONE NUMERO PROTOCOLLO ---
        $annoInCorso = date('Y');
        $ultimoProtocollo = FatturaPassiva::where('condominio_id', $condominio->id)
            ->whereYear('created_at', $annoInCorso)
            ->whereNotNull('numero_protocollo')
            ->orderBy('id', 'desc')
            ->value('numero_protocollo');

        if ($ultimoProtocollo && preg_match('/-(\d+)$/', $ultimoProtocollo, $matches)) {
            $nextNum = str_pad((int) $matches[1] + 1, 4, '0', STR_PAD_LEFT);
            $protocolloSuggerito = "PR-{$annoInCorso}-{$nextNum}";
        } else {
            $protocolloSuggerito = "PR-{$annoInCorso}-0001";
        }
        // ------------------------------------------

        $contestoBudget = $this->prepareContestoBudget($condominio, $esercizio);

        return Inertia::render('gestionale/movimenti/fatture/FatturaRegisterNew', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'condomini' => $listaCondomini,
            ...$contestoBudget,
            'gestioni' => $condominio->gestioni()
                ->where('gestioni.attiva', true)
                ->with('esercizi:id')
                ->get()
                ->map(function ($gestione) {
                    return [
                        'id' => $gestione->id,
                        'nome' => $gestione->nome,
                        'tipo' => $gestione->tipo,
                        'esercizio_ids' => $gestione->esercizi->pluck('id')->toArray(),
                    ];
                }),

            'banche' => Cassa::where('condominio_id', $condominio->id)
                ->where('attiva', true)
                ->where('tipo', '!=', 'fondo')
                ->withSum(['movimenti as totale_entrate' => function ($q) {
                    $q->where('tipo_riga', 'dare');
                }], 'importo')
                ->withSum(['movimenti as totale_uscite' => function ($q) {
                    $q->where('tipo_riga', 'avere');
                }], 'importo')
                ->get()
                ->map(function ($cassa) {
                    $entrate = $cassa->totale_entrate ?? 0;
                    $uscite = $cassa->totale_uscite ?? 0;
                    // beta.25: saldo da SaldoCassaService (unica fonte), non ricalcolato qui.
                    $saldoAttuale = (int) $cassa->saldo_reale;

                    return [
                        'id' => $cassa->conto_contabile_id,
                        'cassa_id' => $cassa->id,
                        'nome' => $cassa->nome,
                        'saldo_attuale' => $saldoAttuale,
                    ];
                }),

            'immobili' => Immobile::where('condominio_id', $condominio->id)
                ->where('attivo', true)
                ->select('id', 'interno', 'nome')
                ->orderBy('interno')
                ->get()
                ->map(function ($imm) {
                    return [
                        'id' => $imm->id,
                        'label' => 'Int. '.$imm->interno.' — '.$imm->nome,
                    ];
                }),
        ]);
    }

    /**
     * Salva una nuova fattura passiva nel database.
     *
     * Invia i dati validati al service che si occupa di generare la Partita Doppia
     * e gestire logiche complesse (es. ritenute d'acconto, coperture e "Scudo Legale").
     * In caso di eccezioni del Service, blocca l'operazione restituendo un errore visibile all'utente.
     *
     * @param  StoreFatturaRequest  $request  Request validata in ingresso.
     * @param  Condominio  $condominio  Il condominio interessato.
     * @return RedirectResponse Reindirizza alla pagina precedente (back) per permettere inserimenti multipli.
     */
    public function store(StoreFatturaRequest $request, Condominio $condominio): RedirectResponse
    {
        try {
            $this->service->registraFattura(
                $request->validated(),
                $condominio->id,
                $request->file('file')
            );

            $coperture = collect($request->input('coperture', []));
            $sopravvenienze = $coperture->where('tipo_copertura', 'sopravvenienza');

            if ($sopravvenienze->isNotEmpty()) {
                return back()->with($this->flashSuccess('Fattura e Scudo Legale registrati con successo!'));
            }

            // Beta.19: la copertura dal fondo nasce pianificata — il fondo non è
            // ancora stato toccato. Il flash indica subito il passo successivo.
            $strategiaFondo = data_get($request->input('dati_extra'), 'override_budget.strategia_rientro') === 'fondo_riserva';
            if ($strategiaFondo) {
                return back()->with($this->flashSuccess(
                    'Fattura registrata. La copertura dal fondo è pianificata: '
                    .'confermala con il giroconto proposto sulla fattura, il fondo si decurta solo allora.'
                ));
            }

            return back()->with($this->flashSuccess('Fattura registrata con successo.'));

        } catch (ModelNotFoundException $e) {
            Log::error('ERRORE 404: '.$e->getMessage());

            return back()->withErrors(['error' => 'Risorsa non trovata. Verifica fornitore, conto e gestione.']);

        } catch (\Exception $e) {
            Log::error('FATAL ERROR NEL SERVICE: '.$e->getMessage());
            Log::error('Traccia: '.$e->getTraceAsString());

            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Elimina fisicamente una fattura ("Muro Contabile").
     *
     * L'operazione è permessa ESCLUSIVAMENTE se la fattura si trova nello stato "aperta" e
     * l'esercizio contabile è ancora aperto. Una volta eliminata, vengono distrutti a cascata
     * i record collegati (Partita Doppia, coperture, file PDF dal disco locale).
     * Se la fattura cancellata è una Nota di Credito creata da uno storno, il sistema applica
     * la "Resurrezione", sbloccando e riportando ad "aperta" la fattura originale.
     *
     * @param  Condominio  $condominio  Il condominio di appartenenza.
     * @param  FatturaPassiva  $fattura  La fattura da eliminare.
     */
    public function destroy(Condominio $condominio, FatturaPassiva $fattura): RedirectResponse
    {
        // I sette motivi di rifiuto vivevano qui, srotolati, e il menu della riga
        // ne conosceva due. Ora stanno tutti in `motivoBloccoEliminazione()`, che
        // è anche ciò che la lista mostra all'utente: una guardia sola, quindi
        // impossibile che il menu prometta un'operazione che il server nega.
        // Le vie d'uscita (storna il giroconto, riporta il piano in bozza,
        // storna il pagamento…) sono dentro i messaggi, dove servono.
        if ($motivo = $fattura->motivoBloccoEliminazione()) {
            return back()->with($this->flashError('Operazione negata: '.$motivo));
        }

        // --- RICERCA DELLA FATTURA ORIGINALE ---
        $fatturaOriginale = FatturaPassiva::where('condominio_id', $condominio->id)
            ->where('dati_extra->stornata_da_id', $fattura->id)
            ->first();

        // --- FIX: RACCOLTA CONTI IMPREVISTI DA ELIMINARE ---
        $contiImprevistiIds = collect();

        // 1. Conti nati da righe correnti (Sopravvenienze)
        foreach ($fattura->righe as $riga) {
            if ($riga->is_sopravvenienza && $riga->conto_id) {
                $contiImprevistiIds->push($riga->conto_id);
            }
        }

        // 2. Conti nati da debiti pregressi frazionati
        foreach ($fattura->coperture as $copertura) {
            if ($copertura->tipo_copertura === 'sopravvenienza' && $copertura->conto_id) {
                $contiImprevistiIds->push($copertura->conto_id);
            }
        }

        $contiImprevistiIds = $contiImprevistiIds->filter()->unique();
        // ---------------------------------------------------

        // 2. ELIMINAZIONE FISICA E PULIZIA
        try {
            DB::transaction(function () use ($fattura, $fatturaOriginale, $contiImprevistiIds) {

                // --- LA RESURREZIONE ---
                // Sciogliere il congelamento non basta: lo stato di pagamento va
                // RICALCOLATO dai pivot, non forzato ad APERTA. Forzarlo produceva
                // fatture "aperte" con pagamenti ancora vivi — uno stato che nessuna
                // guardia sa più trattare, perché il muro contabile di destroy() legge
                // proprio stato_pagamento per decidere.
                if ($fatturaOriginale) {
                    $datiExtraOriginali = $fatturaOriginale->dati_extra ?? [];
                    unset($datiExtraOriginali['is_stornata']);
                    unset($datiExtraOriginali['stornata_da_id']);

                    // Il congelamento va sciolto su ENTRAMBE le fonti di verità prima
                    // del ricalcolo: la guardia in ricalcolaStatoFattura è in OR
                    // (stato_pagamento === STORNATA || dati_extra.is_stornata), quindi
                    // ripulire solo dati_extra la lascerebbe scattare lo stesso e la
                    // fattura resterebbe congelata per sempre. APERTA è solo un valore
                    // di transito: il valore vero lo deriva subito dopo dai pivot.
                    $fatturaOriginale->update([
                        'dati_extra' => $datiExtraOriginali,
                        'stato_pagamento' => StatoPagamentoFattura::APERTA,
                    ]);
                    $fatturaOriginale->refresh();

                    app(PagamentoFornitoreService::class)->ricalcolaStatoFattura($fatturaOriginale);
                }

                $fattura->coperture()->delete();
                $fattura->righe()->delete();

                $scritture = $fattura->scritture;

                $fattura->scritture()->detach();

                foreach ($scritture as $scrittura) {
                    $scrittura->righe()->delete();
                    $scrittura->forceDelete();
                }

                // --- FIX: ELIMINAZIONE CONTI FANTASMA ---
                $contiDaEliminare = Conto::whereIn('id', $contiImprevistiIds)
                    ->where('is_tecnico', true) // solo conti generati on-the-fly
                    ->whereNotIn('id', function ($q) {
                        $q->select('conto_id')->from('righe_fattura')->whereNotNull('conto_id');
                    })
                    ->whereNotIn('id', function ($q) {
                        $q->select('conto_id')->from('fattura_coperture')->whereNotNull('conto_id');
                    })
                    ->pluck('id');

                if ($contiDaEliminare->isNotEmpty()) {
                    Conto::whereIn('id', $contiDaEliminare)->delete();
                }
                // ----------------------------------------

                foreach ($fattura->documenti as $documento) {
                    if (Storage::disk('local')->exists($documento->path)) {
                        Storage::disk('local')->delete($documento->path);
                    }
                    $documento->delete();
                }

                $fattura->delete();
            });

            $msg = $fatturaOriginale
                ? 'Nota di credito eliminata. La fattura originale è stata ripristinata e risulta di nuovo aperta.'
                : 'Fattura eliminata fisicamente dal sistema.';

            return back()->with($this->flashSuccess($msg));

        } catch (\Exception $e) {
            Log::error("Errore durante l'eliminazione fisica della fattura ID {$fattura->id}: ".$e->getMessage());

            return back()->with($this->flashError('Errore di sistema durante l\'eliminazione.'));
        }
    }

    /**
     * Mostra il form per la modifica di una fattura passiva aperta.
     *
     * Prepara gli stessi dati di create() ma pre-popolati con la fattura esistente.
     * Il service espone la stessa guard usata da aggiornaFattura() tramite
     * motivoBloccoModifica(): se la fattura non è modificabile (stornata, esercizio
     * chiuso, pregressa, ecc.) reindirizziamo con un messaggio chiaro invece di
     * mostrare un form che fallirebbe silenziosamente al salvataggio.
     */
    public function edit(Condominio $condominio, FatturaPassiva $fattura): Response|RedirectResponse
    {
        abort_if($fattura->condominio_id !== $condominio->id, 403, 'Accesso non autorizzato.');

        if ($motivo = $this->service->motivoBloccoModifica($fattura)) {
            return redirect()
                ->route('admin.gestionale.fatture.show', ['condominio' => $condominio->id, 'fattura' => $fattura->id])
                ->with($this->flashError($motivo));
        }

        $fattura->load(['fornitore', 'righe.conto.parent', 'documenti', 'coperture']);

        $listaCondomini = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio = $this->getEsercizioCorrente($condominio);

        // In modifica il residuo va calcolato AL NETTO della fattura stessa: il frontend
        // (budgetImpacts) risomma l'intero importo digitato, quindi lasciarla dentro
        // la conterebbe due volte e farebbe scattare un falso "sforamento budget".
        $contestoBudget = $this->prepareContestoBudget($condominio, $esercizio, $fattura);

        return Inertia::render('gestionale/movimenti/fatture/FatturaRegisterEdit', [
            'condominio' => $condominio,
            'fattura' => $fattura,
            'esercizio' => $esercizio,
            'condomini' => $listaCondomini,
            ...$contestoBudget,
            'gestioni' => $condominio->gestioni()
                ->where('gestioni.attiva', true)
                ->with('esercizi:id')
                ->get()
                ->map(function ($gestione) {
                    return [
                        'id' => $gestione->id,
                        'nome' => $gestione->nome,
                        'tipo' => $gestione->tipo,
                        'esercizio_ids' => $gestione->esercizi->pluck('id')->toArray(),
                    ];
                }),
            'banche' => Cassa::where('condominio_id', $condominio->id)
                ->where('attiva', true)
                ->where('tipo', '!=', 'fondo')
                ->withSum(['movimenti as totale_entrate' => function ($q) {
                    $q->where('tipo_riga', 'dare');
                }], 'importo')
                ->withSum(['movimenti as totale_uscite' => function ($q) {
                    $q->where('tipo_riga', 'avere');
                }], 'importo')
                ->get()
                ->map(function ($cassa) {
                    $entrate = $cassa->totale_entrate ?? 0;
                    $uscite = $cassa->totale_uscite ?? 0;
                    // beta.25: saldo da SaldoCassaService (unica fonte), non ricalcolato qui.
                    $saldoAttuale = (int) $cassa->saldo_reale;

                    return [
                        'id' => $cassa->conto_contabile_id,
                        'cassa_id' => $cassa->id,
                        'nome' => $cassa->nome,
                        'saldo_attuale' => $saldoAttuale,
                    ];
                }),
            'immobili' => Immobile::where('condominio_id', $condominio->id)
                ->where('attivo', true)
                ->select('id', 'interno', 'nome')
                ->orderBy('interno')
                ->get()
                ->map(fn ($i) => [
                    'id' => $i->id,
                    'label' => 'Int. '.$i->interno.' — '.$i->nome,
                ]),
        ]);
    }

    /**
     * Calcola il contesto di budget/capienza condiviso tra create() ed edit().
     *
     * Centralizza fornitori, esercizi aperti, conti con residuo budget/storico movimenti,
     * debiti patrimoniali pregressi, fondi di riserva e capienza Rata 0 — tutti dati che
     * il "Matrix Workspace" Vue richiede come prop obbligatorie sia in creazione sia in modifica.
     *
     * @param  Esercizio|null  $esercizio  Esercizio corrente del condominio (null se nessuno aperto).
     * @param  FatturaPassiva|null  $escludiFattura  Fattura in corso di modifica: le sue righe vanno
     *                                               escluse dallo speso, altrimenti in edit la fattura
     *                                               conta sé stessa e il frontend — che somma di nuovo
     *                                               l'importo digitato — segnala un falso sforamento.
     * @return array<string, mixed>
     */
    private function prepareContestoBudget(Condominio $condominio, ?Esercizio $esercizio, ?FatturaPassiva $escludiFattura = null): array
    {
        // --- ESTRAZIONE ULTIME SPESE E CALCOLO REALE BUDGET ---
        $ultimeSpese = collect();
        $spesePerConto = collect();

        if ($esercizio) {
            $ultimeSpese = DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->join('fornitori', 'fatture_passive.fornitore_id', '=', 'fornitori.id')
                ->where('fatture_passive.condominio_id', $condominio->id)
                ->where('fatture_passive.esercizio_id', $esercizio->id)
                ->select(
                    'righe_fattura.conto_id',
                    'fatture_passive.data_documento',
                    'fatture_passive.numero_documento',
                    'fatture_passive.is_pregresso',
                    'fornitori.ragione_sociale',
                    'righe_fattura.importo_imponibile',
                    'righe_fattura.importo_iva'
                )
                ->when($escludiFattura, fn ($q) => $q->where('righe_fattura.fattura_passiva_id', '!=', $escludiFattura->id))
                ->orderByDesc('fatture_passive.data_documento')
                ->get()
                ->groupBy('conto_id');

            // beta.30: lo speso che alimenta `residuo_budget` e l'avviso di sforo arriva
            // ora dal libro giornale, non da `righe_fattura`. Motivo: una REGOLAZIONE
            // IMMEDIATA non crea righe fattura, quindi il residuo risultava più capiente
            // del reale e l'avviso non scattava — mentre la Dashboard, che legge dalla
            // stessa fonte nuova, lo segnalava. Due schermate dello stesso flusso che si
            // contraddicevano.
            //
            // Il filtro `is_pregresso = false` non serve più: una fattura pregressa non
            // scrive mai su un capitolo di budget (la parte coperta va su passate_gestioni
            // senza voce_spesa_id, l'eccedenza sulla voce sopravvenienza designata).
            $spesePerConto = collect(
                $this->spesaPerVoce->perEsercizio($esercizio, null, $escludiFattura?->id)
            );
        }

        // --- DATI PER IL WIDGET DOUBLE LOCK ---

        // 1. Calcoliamo la Rata 0 Globale (Crediti vs Condòmini) GIA' EROSA e INCASSATA
        $totaleRataZeroInizialeCents = 0;
        $totaleRataZeroIncassataCents = 0;
        $totalePregressoGiaUsatoCents = 0;
        $capienzaRataZeroResidua = 0;

        if ($esercizio) {
            $totaleRataZeroInizialeCents = Saldo::where('condominio_id', $condominio->id)
                ->where('esercizio_id', $esercizio->id)
                ->whereNotNull('anagrafica_id')
                ->where('saldo_iniziale', '>', 0)
                ->sum('saldo_iniziale');

            $incassiCorrenti = ScritturaContabile::where('condominio_id', $condominio->id)
                ->where('esercizio_id', $esercizio->id)
                ->where('tipo_movimento', 'incasso_rata')
                ->with('quotePagate.rata')
                ->get();

            $totaleRataZeroIncassataCents = $incassiCorrenti->sum(function ($movimento) {
                return $movimento->quotePagate
                    ->filter(function ($quota) {
                        return $quota->rata && (
                            $quota->rata->numero_rata === 0 ||
                            $quota->rata->numero_rata === '0'
                        );
                    })
                    ->sum(function ($quota) {
                        return $quota->pivot->importo_pagato ?? 0;
                    });
            });

            $totalePregressoGiaUsatoCents = DB::table('fatture_passive')
                ->where('condominio_id', $condominio->id)
                ->where('esercizio_id', $esercizio->id)
                ->where('is_pregresso', true)
                ->sum(DB::raw('importo_imponibile + importo_iva'));

            $capienzaRataZeroResidua = max(0, $totaleRataZeroInizialeCents - $totalePregressoGiaUsatoCents);
        }

        // 2. Estraiamo i Debiti verso Fornitori e calcoliamo il loro residuo individuale
        $debitiPatrimoniali = collect();
        if ($esercizio) {
            $debitiPatrimoniali = Saldo::where('condominio_id', $condominio->id)
                ->where('esercizio_id', $esercizio->id)
                ->whereNull('anagrafica_id')
                ->where('saldo_iniziale', '<', 0)
                ->get()
                ->map(function ($saldo) {
                    $importoInizialeCents = abs($saldo->saldo_iniziale);

                    $fattureCollegate = FatturaPassiva::where('saldo_patrimoniale_id', $saldo->id)
                        ->where('is_pregresso', true)
                        ->get()
                        ->map(function ($f) {
                            $lordoEuro = ($f->importo_imponibile + $f->importo_iva) / 100;

                            return [
                                'id' => $f->id,
                                'numero_documento' => $f->numero_documento ?? 'S/N',
                                'data_documento' => $f->data_documento ? Carbon::parse($f->data_documento)->format('d/m/Y') : '',
                                'importo_usato' => round($lordoEuro, 2),
                            ];
                        });

                    $importoUsatoCents = $fattureCollegate->sum(fn ($f) => $f['importo_usato'] * 100);
                    $importoDisponibileCents = max(0, $importoInizialeCents - $importoUsatoCents);

                    return [
                        'id' => $saldo->id,
                        'fornitore_id' => $saldo->fornitore_id,
                        'descrizione' => $saldo->descrizione ?? 'Debito pregresso senza descrizione',
                        'importo_iniziale' => $importoInizialeCents,
                        'importo_disponibile' => (int) $importoDisponibileCents,
                        'fatture_collegate' => $fattureCollegate->toArray(),
                    ];
                })->values();
        }

        // 3. I Fondi di Riserva disponibili (Presi dalla tabella CASSE)
        // Beta.19: il saldo esposto è al netto delle coperture PIANIFICATE già
        // promesse su quel fondo (fatture non stornate in attesa di giroconto):
        // senza questa sottrazione, due sfori consecutivi potevano pianificare
        // oltre la capienza — la conferma sarebbe poi fallita, ma l'amministratore
        // avrebbe promesso una copertura impossibile senza saperlo.
        $impegnatoPerFondo = FatturaCopertura::where('tipo_copertura', 'fondo_riserva')
            ->where('stato', 'pianificata')
            ->where('importo', '>', 0)
            ->whereHas('fattura', fn ($q) => $q->where('condominio_id', $condominio->id)
                ->where('stato_pagamento', '!=', 'stornata'))
            ->selectRaw('fondo_id, SUM(importo) as impegnato')
            ->groupBy('fondo_id')
            ->pluck('impegnato', 'fondo_id');

        $fondiRiserva = Cassa::where('condominio_id', $condominio->id)
            ->where('tipo', 'fondo')
            ->where('attiva', true)
            ->get()
            ->map(function ($cassa) use ($impegnatoPerFondo) {
                // Convenzione unica (attivo, beta.19): DARE aumenta, AVERE diminuisce.
                // beta.25: saldo da SaldoCassaService (unica fonte, esclude le scritture
                // annullate — cosa che questa query locale non faceva). Resta qui solo la
                // detrazione dell'impegnato, che è specifica di questa vista.
                $saldoAttuale = (int) $cassa->saldo_reale
                    - ($impegnatoPerFondo->get($cassa->conto_contabile_id) ?? 0);

                return [
                    'id' => $cassa->conto_contabile_id,
                    'nome' => $cassa->nome,
                    'saldo_attuale' => (int) max(0, $saldoAttuale),
                    'sottotipo_fondo' => $cassa->sottotipo_fondo,
                    'is_override_assemblea' => (bool) $cassa->is_override_assemblea,
                    'is_utilizzabile_per_imprevisti' => (bool) $cassa->is_utilizzabile_per_imprevisti,
                ];
            });

        // 4. Fatture pregresse registrate (Radar Anti-Duplicati)
        $fatturePregresseRegistrate = collect();
        if ($esercizio) {
            $fatturePregresseRegistrate = FatturaPassiva::where('condominio_id', $condominio->id)
                ->where('esercizio_id', $esercizio->id)
                ->where('is_pregresso', true)
                ->get()
                ->map(function ($f) {
                    $lordoEuro = ($f->importo_imponibile + $f->importo_iva) / 100;

                    return [
                        'id' => $f->id,
                        'fornitore_id' => $f->fornitore_id,
                        'numero_documento' => $f->numero_documento ?? 'S/N',
                        'data_documento' => $f->data_documento ? Carbon::parse($f->data_documento)->format('d/m/Y') : '',
                        'importo_usato' => round($lordoEuro, 2),
                    ];
                });
        }

        // Già-versato per voce (beta.27): quanto i condòmini hanno già contribuito
        // per ciascuna voce, indipendentemente da quanto risulta fatturato finora.
        // Serve al frontend per avvisare, quando c'è uno sforo su una voce con
        // già-versato attivo, che la rata integrativa chiederà solo il residuo
        // netto — non l'intero sforo lordo (vedi CalcoloQuoteService::
        // guardiaSovraFinanziamentoGiaVersato per la ragione tecnica).
        $giaVersatoPerConto = ContributoVersato::where('target_type', Conto::class)
            ->groupBy('target_id')
            ->selectRaw('target_id, SUM(importo_cents) as totale')
            ->pluck('totale', 'target_id');

        // 5. Conti con residuo budget e storico movimenti recenti
        $conti = Conto::whereIn('piano_conto_id', $condominio->pianiDeiConti()->pluck('id'))
            ->with('parent')
            ->whereDoesntHave('sottoconti')
            ->get()
            ->map(function ($conto) use ($ultimeSpese, $spesePerConto, $giaVersatoPerConto) {
                $budgetApprovato = $conto->importo ?? 0;
                $spesaAttuale = $spesePerConto->get($conto->id, 0);
                $residuo = $budgetApprovato - $spesaAttuale;

                $storicoRecente = [];
                if ($ultimeSpese->has($conto->id)) {
                    $storicoRecente = $ultimeSpese->get($conto->id)->take(3)->map(function ($spesa) {
                        return [
                            'data' => Carbon::parse($spesa->data_documento)->format('d/m/Y'),
                            'fornitore' => $spesa->ragione_sociale,
                            'documento' => $spesa->numero_documento,
                            'is_pregresso' => (bool) $spesa->is_pregresso,
                            'importo' => $spesa->importo_imponibile + $spesa->importo_iva,
                        ];
                    })->values()->toArray();
                }

                return [
                    'id' => $conto->id,
                    'nome' => $conto->nome,
                    'parent_nome' => $conto->parent ? $conto->parent->nome : null,
                    '_sort_key' => $conto->parent ? $conto->parent->nome.' '.$conto->nome : $conto->nome,
                    'codice' => null,
                    'residuo_budget' => $residuo,
                    'is_capiente' => $residuo >= 0,
                    'ultimi_movimenti' => $storicoRecente,
                    'gia_versato_cents' => (int) ($giaVersatoPerConto->get($conto->id) ?? 0),
                ];
            })
            ->sortBy('_sort_key')
            ->values();

        // Ultima aliquota IVA usata per fornitore: prefill intelligente delle nuove
        // righe (invece del 22% fisso, spesso sbagliato in ambito condominiale —
        // manodopera edile 10%, bancarie/assicurazioni 0%, professionisti 22%).
        $ultimeAliquoteIva = DB::table('righe_fattura')
            ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
            ->where('fatture_passive.condominio_id', $condominio->id)
            ->select('fatture_passive.fornitore_id', 'righe_fattura.aliquota_iva')
            ->orderByDesc('fatture_passive.data_documento')
            ->orderByDesc('righe_fattura.id')
            ->get()
            ->groupBy('fornitore_id')
            ->map(fn ($righe) => (float) $righe->first()->aliquota_iva);

        return [
            'fornitori' => Fornitore::all()->map(function (Fornitore $f) use ($ultimeAliquoteIva) {
                $data = $f->toArray();
                $data['ultima_aliquota_iva'] = $ultimeAliquoteIva->get($f->id);

                return $data;
            }),
            'esercizi' => $condominio->esercizi()->where('stato', 'aperto')->get(),
            'debiti_patrimoniali' => $debitiPatrimoniali,
            'fatture_pregresse_registrate' => $fatturePregresseRegistrate,
            'fondi_riserva' => $fondiRiserva,
            'capienza_rata_zero' => (int) $capienzaRataZeroResidua,
            'incassato_rata_zero' => (int) $totaleRataZeroIncassataCents,
            'conti' => $conti,
        ];
    }

    /**
     * Aggiorna una fattura passiva aperta, ricreando le scritture contabili.
     *
     * Delega tutta la logica a FatturaPassivaService::aggiornaFattura().
     * Le guard di modificabilità sono nel service.
     */
    public function update(UpdateFatturaRequest $request, Condominio $condominio, FatturaPassiva $fattura): RedirectResponse
    {
        abort_if($fattura->condominio_id !== $condominio->id, 403, 'Accesso non autorizzato.');

        try {
            $this->service->aggiornaFattura(
                $fattura,
                $request->validated(),
                $request->file('file')
            );

            return redirect()
                ->route('admin.gestionale.fatture.index', ['condominio' => $condominio->id])
                ->with($this->flashSuccess('Fattura aggiornata con successo.'));

        } catch (FatturaModificaVietataException $e) {
            return back()->withErrors(['modifica_vietata' => $e->getMessage()]);

        } catch (\Exception $e) {
            Log::error("Errore modifica fattura ID {$fattura->id}: ".$e->getMessage());

            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * Approva una fattura in stato "da_approvare" (flusso interno).
     *
     * Transizione: da_approvare → approvata.
     * Differisce dalla ratifica sforo perché non richiede motivazioni legali.
     */
    public function approva(Condominio $condominio, FatturaPassiva $fattura): RedirectResponse
    {
        if ($fattura->stato_approvazione !== 'da_approvare') {
            return back()->with($this->flashError(
                'Operazione non valida: questa fattura non è in stato "da approvare".'
            ));
        }

        $fattura->update(['stato_approvazione' => 'approvata']);

        Log::info("Fattura ID {$fattura->id} approvata (da_approvare → approvata) da utente ID ".Auth::id());

        return back()->with($this->flashSuccess('Fattura approvata con successo.'));
    }

    /**
     * Registra la ratifica assembleare di una fattura in sforo motivato (Art. 1135 c.c.).
     *
     * Transizione: sforo_motivato → approvata.
     * La fattura diventa selezionabile per il pagamento in PagamentoNew.
     * I dati dell'approvazione (note, timestamp, utente) vengono salvati in dati_extra
     * per garantire l'audit trail della delibera assembleare.
     */
    public function approvaSforo(Request $request, Condominio $condominio, FatturaPassiva $fattura): RedirectResponse
    {
        // Guard: solo fatture in sforo_motivato possono essere ratificate
        if ($fattura->stato_approvazione !== 'sforo_motivato') {
            return back()->with($this->flashError(
                'Operazione non valida: questa fattura non è in stato "sforo motivato".'
            ));
        }

        $request->validate([
            'note' => 'nullable|string|max:1000',
        ]);

        $datiExtra = $fattura->dati_extra ?? [];
        $datiExtra['ratifica_assembleare'] = [
            'note' => $request->input('note'),
            'approvato_il' => now()->toIso8601String(),
            'approvato_da' => Auth::id(),
        ];

        $fattura->update([
            'stato_approvazione' => 'approvata',
            'dati_extra' => $datiExtra,
        ]);

        Log::info("Fattura ID {$fattura->id} ratificata (sforo_motivato → approvata) da utente ID ".Auth::id());

        return back()->with($this->flashSuccess(
            'Fattura ratificata con successo. Può ora essere pagata.'
        ));
    }

    /**
     * Mostra il dettaglio della singola fattura passiva.
     *
     * Include le righe di dettaglio, i documenti allegati e le informazioni
     * sull'eventuale ratifica dello sforo motivato.
     */
    public function show(Condominio $condominio, FatturaPassiva $fattura): Response
    {
        $fattura->load([
            'fornitore',
            'righe.conto.parent',
            'documenti',
            // Beta.19: le coperture fondo alimentano il banner "conferma con
            // giroconto" (pianificata) / "coperta da GIR-…" (confermata).
            'coperture.scritturaGiroconto:id,numero_protocollo',
        ]);

        // Caricamento del nome utente che ha ratificato se presente
        $utenteRatifica = null;
        if (! empty($fattura->dati_extra['ratifica_assembleare']['approvato_da'])) {
            $utenteRatifica = DB::table('users')
                ->where('id', $fattura->dati_extra['ratifica_assembleare']['approvato_da'])
                ->value('name');
        }

        $listaCondomini = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/movimenti/fatture/FatturaShow', [
            'condominio' => new CondominioResource($condominio),
            'fattura' => $fattura,
            'utenteRatifica' => $utenteRatifica,
            'esercizio' => $esercizio,
            'condomini' => $listaCondomini,
        ]);
    }

    /**
     * Esegue il download sicuro (protetto) del file PDF allegato alla fattura.
     *
     * Applica un controllo autorizzativo tramite Policy e un controllo anti-IDOR polimorfico
     * per garantire che il documento richiesto appartenga effettivamente alla fattura in oggetto.
     * I file sono protetti nel disco privato (local) del server.
     *
     * @param  Condominio  $condominio  Il condominio della fattura.
     * @param  FatturaPassiva  $fattura  La fattura passiva "contenitore".
     * @param  Documento  $documento  Il documento polimorfico associato.
     * @return BinaryFileResponse|RedirectResponse Il file binario da scaricare, o un redirect in caso di errore/divieto.
     */
    public function download(Condominio $condominio, FatturaPassiva $fattura, Documento $documento)
    {
        // 1. AUTORIZZAZIONE
        Gate::authorize('view', $documento);

        // 2. CONTROLLO ANTI-IDOR
        if ($documento->documentable_id !== $fattura->id || $documento->documentable_type !== FatturaPassiva::class) {
            abort(403, 'Azione non autorizzata. Il documento non appartiene a questa fattura.');
        }

        try {
            // 3. VERIFICA ESISTENZA
            if (! Storage::disk('local')->exists($documento->path)) {
                return redirect()->back()->with(
                    $this->flashError(__('documenti.file_not_found') ?? 'File della fattura non trovato sul server.')
                );
            }

            // Otteniamo il percorso assoluto del file sul server
            $percorsoAssoluto = Storage::disk('local')->path($documento->path);

            return response()->download($percorsoAssoluto, $documento->name);

        } catch (\Exception $e) {
            Log::error("Errore download fattura ID {$fattura->id}: ".$e->getMessage());

            return redirect()->back()->with(
                $this->flashError(__('documenti.error_downloading_document') ?? 'Errore durante il download del documento.')
            );
        }
    }
}
