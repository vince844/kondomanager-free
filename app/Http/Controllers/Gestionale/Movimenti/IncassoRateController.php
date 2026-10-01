<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Traits\OrdinaElenco;

use App\Exceptions\Gestionale\IncassoNonRegistrabileException;
use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Enums\TipoMovimentoContabile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreIncassoRateRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Anagrafica;
use App\Models\Evento;
use App\Models\Immobile;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\InboxService;
use App\Services\Gestionale\IncassoRateService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use App\Traits\PaginaElenco;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class IncassoRateController extends Controller
{
    use OrdinaElenco;

    /**
     * Le colonne ordinabili dell'elenco incassi, che poggia su `scritture_contabili`.
     *
     * ⚠️ Fuori «Soggetto» e «Importo»: il primo è una relazione risolta a video riga per riga,
     * il secondo è un totale ricomposto dalle righe della scrittura. Entrambi richiedono una
     * decisione sulla chiave, non un default.
     */
    public static function colonneOrdinabili(): array
    {
        return [
            'causale' => 'causale',
            'stato'   => 'stato',
        ];
    }

    use HandleFlashMessages, HasEsercizio, HasCondomini, PaginaElenco;

    /** Valori ammessi per il filtro stato — colonna DB enum, nessun PHP enum dietro. */
    private const STATI = ['bozza', 'registrata', 'riconciliata', 'annullata'];

    /**
     * Inizializza il controller iniettando il servizio per la gestione incassi.
     *
     * @param IncassoRateService $incassoService Servizio contenente le logiche di query e formattazione dei movimenti.
     */
    public function __construct(private IncassoRateService $incassoService) {}

    /**
     * Mostra la lista paginata degli incassi registrati per il condominio selezionato.
     * Fornisce al frontend i dati necessari per popolare la vista, inclusi i filtri di ricerca,
     * la lista dei condòmini e gli esercizi contabili disponibili.
     *
     * @param Request $request L'istanza della richiesta HTTP corrente contenente gli eventuali parametri di ricerca (es. ?search=...).
     * @param Condominio $condominio L'istanza del condominio in cui si sta operando.
     * @return \Inertia\Response Ritorna il componente Vue della lista incassi con le relative properties.
     */
    public function index(Request $request, Condominio $condominio)
    {
        // I due parametri dell'ordinamento si validano qui: questo elenco non ha una
        // FormRequest, e il nome della colonna finisce dentro `orderBy()`.
        $ordinamento = $request->validate(self::regoleOrdinamento(array_keys(self::colonneOrdinabili())) + [
            'per_page' => ['sometimes', 'integer'],
        ]);

        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        $ordinamento['per_page'] = $this->righePerPagina($request);

        $query = $this->incassoService->getIncassiQuery(
            $condominio,
            $request->input('search'),
            $request->input('stato'),
            $request->input('data_da'),
            $request->input('data_a')
        );

        $movimenti = $query
            ->tap(fn ($q) => $this->ordina($q, $ordinamento, self::colonneOrdinabili(), predefinita: 'data_registrazione', versoPredefinito: 'desc'))
            ->paginate($ordinamento['per_page'])
            ->withQueryString()
            ->through(fn($mov) => $this->incassoService->formatMovimentoForFrontend($mov));

        $listaPalazzi = CondominioResource::collection($this->getCondomini())->resolve();
        
        $soggettiList = Anagrafica::whereHas('immobili', fn($q) => 
            $q->where('condominio_id', $condominio->id)
        )->orderBy('nome')->get();
        
        $esercizio = $this->getEsercizioCorrente($condominio);

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        $stats = [
            'totale_incassi' => ScritturaContabile::where('condominio_id', $condominio->id)
                ->where('tipo_movimento', 'incasso_rata')
                ->count(),
            'incassi_mese' => ScritturaContabile::where('condominio_id', $condominio->id)
                ->where('tipo_movimento', 'incasso_rata')
                ->whereMonth('data_registrazione', now()->month)
                ->whereYear('data_registrazione', now()->year)
                ->count(),
            // StornoIncassoRateAction sigilla la scrittura originale con stato='annullata'
            // (giornale append-only: la scrittura resta, viene solo marcata). 'stornato'
            // non è un valore ammesso dalla colonna: il confronto non ha mai trovato nulla.
            'stornati' => ScritturaContabile::where('condominio_id', $condominio->id)
                ->where('tipo_movimento', 'incasso_rata')
                ->where('stato', 'annullata')
                ->count(),
        ];

        return Inertia::render('gestionale/movimenti/incassi/IncassoRateList', [
            'sort'      => $ordinamento['sort'] ?? null,
            'direction' => $ordinamento['direction'] ?? null,
            'condominio' => $condominio,
            'movimenti'  => $movimenti,
            'condomini'  => $listaPalazzi, 
            'soggetti'   => $soggettiList, 
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
            'stats'      => $stats,
            'stati'      => self::STATI,
            'filters'    => $request->all(['search', 'stato', 'data_da', 'data_a']),
        ]);
    }

    /**
     * Genera la schermata e prepara i dati per il form di registrazione di un nuovo incasso.
     * Recupera le entità relazionali attive (casse, anagrafiche, immobili, gestioni dell'esercizio corrente)
     * necessarie per compilare correttamente la Partita Doppia.
     *
     * @param Condominio $condominio L'istanza del condominio in cui si sta operando.
     * @return \Inertia\Response Ritorna il componente Vue per la creazione dell'incasso.
     */
    public function create(Condominio $condominio)
    {
        $risorse = Cassa::where('condominio_id', $condominio->id)
            ->whereIn('tipo', ['banca', 'contanti'])
            ->where('attiva', true)
            ->with('contoCorrente')
            ->get();

        $condomini = Anagrafica::whereHas('immobili', fn($q) => $q->where('condominio_id', $condominio->id))
            ->orderBy('nome')->get(['id', 'nome', 'indirizzo', 'codice_fiscale']);

        $immobili = Immobile::where('condominio_id', $condominio->id)
            ->orderBy('interno')->get(['id', 'interno', 'descrizione', 'nome']);

        // Coda 167, beta.40: chi può essere «Versato da» — le persone del condominio, con la stessa regola del pagante
        // (`StoreIncassoRateRequest::personaDelCondominio()`: titolari di un'unità o associati al condominio). Più ampio
        // di `$condomini`, che guarda solo le unità: il figlio che paga per la madre è associato senza unità. `ha_rate`
        // serve al modulo per avvisare prima del rifiuto, quando la parte in più non avrebbe dove stare (decisione 30.3).
        $conRate = RataQuote::whereHas('rata.pianoRate', fn ($p) => $p->where('condominio_id', $condominio->id))
            ->whereHas('rata', fn ($r) => $r->where('stato', 'emessa'))
            ->where('importo', '>', 0)
            ->distinct()
            ->pluck('anagrafica_id')
            ->flip();

        $persone = Anagrafica::where(fn ($q) => $q
                ->whereHas('immobili', fn ($i) => $i->where('condominio_id', $condominio->id))
                ->orWhereHas('condomini', fn ($c) => $c->where('condomini.id', $condominio->id)))
            ->orderBy('nome')
            ->get(['id', 'nome', 'indirizzo', 'codice_fiscale'])
            ->map(fn (Anagrafica $a) => [
                'id'             => $a->id,
                'nome'           => $a->nome,
                'indirizzo'      => $a->indirizzo,
                'codice_fiscale' => $a->codice_fiscale,
                'ha_rate'        => isset($conRate[$a->id]),
            ])->values();

        $esercizio = $this->getEsercizioCorrente($condominio);
        
        $gestioni = $esercizio 
            ? $esercizio->gestioni()->select('gestioni.id', 'gestioni.nome', 'gestioni.tipo')->orderBy('gestioni.tipo')->get() 
            : [];

        return Inertia::render('gestionale/movimenti/incassi/IncassoRateNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'risorse'    => $risorse,
            'condomini'  => $condomini,
            'persone'    => $persone,
            'immobili'   => $immobili,
            'gestioni'   => $gestioni,
        ]);
    }

    /**
     * Elabora, salva e contabilizza la registrazione di un incasso.
     * * Oltre ad affidare la creazione delle righe contabili alla StoreIncassoRateAction, 
     * questo metodo esegue operazioni di sincronizzazione dell'ecosistema:
     * 1. Ricalcola in tempo reale gli importi residui delle rate nello scadenziario dell'utente
     * (aggiornando lo stato in 'paid', 'partial' o 'pending').
     * 2. Intercetta e chiude automaticamente i "Task" di verifica incasso nella Inbox dell'amministratore 
     * se l'incasso è derivato da una segnalazione del condòmino.
     *
     * @param StoreIncassoRateRequest $request Request validata con payload rigoroso per l'operazione contabile.
     * @param Condominio $condominio L'istanza del condominio in cui si sta operando.
     * @param StoreIncassoRateAction $action L'Azione dedicata al salvataggio in Partita Doppia (Cassa vs Crediti).
     * @return \Illuminate\Http\RedirectResponse Reindirizzamento alla lista degli incassi con messaggio flash di successo.
     */
    public function store(StoreIncassoRateRequest $request, Condominio $condominio, StoreIncassoRateAction $action) 
    {
        try {
            $scrittura = $action->execute($request->validated(), $condominio, $this->getEsercizioCorrente($condominio));
        } catch (IncassoNonRegistrabileException $e) {
            // Conflitto di dominio, non guasto: l'incasso **non si poteva registrare**, e niente è
            // stato scritto. Si torna al modulo compilato con il motivo, invece della pagina 500
            // che l'amministratore si prendeva — perdendo la distribuzione appena fatta a mano.
            //
            // ⚠️ Si cattura **la famiglia, non il caso**. Questo `catch` era per tipo, e ogni
            // guardia nuova che sollevava un tipo non elencato tornava a produrre la 500: è
            // successo nella beta.43, di nuovo nella beta.48, e le tre guardie sulle
            // compensazioni a credito ci sono rimaste dentro fino alla beta.49. Con la base
            // comune una guardia nuova è coperta senza toccare questo file — ed è l'eccezione
            // stessa a dire, con `campo()`, su quale casella mostrare il messaggio.
            return back()->withInput()->withErrors([$e->campo() => $e->getMessage()]);
        }

        // --- AGGIORNAMENTO EVENTI SCADENZIARIO ---
        // Le coppie (rata, persona) che l'incasso ha davvero toccato, anche quella di chi ha versato per un altro e riceve
        // la parte in più (Coda 167): vedi `EventiRataCondomino::coppieToccate()`.
        $eventiRata = app(\App\Services\Gestionale\EventiRataCondomino::class);
        $coppie = $eventiRata->coppieToccate($scrittura);
        $rataIdsReali = $coppie->pluck(0)->unique()->values()->all();
        $eventiRata->allineaPagato($coppie);

        // Chiusura del Task Admin
        // Solo un'attività di **questo** condominio (lente sicurezza della Fase 1-bis della beta.40): l'id arriva dal
        // browser, e con `Evento::find()` si chiudeva un'attività di qualunque palazzo. Se non c'è — cancellata nel
        // frattempo, o di un altro condominio — l'incasso resta registrato e non si chiude niente.
        $relatedTaskId = $request->validated('related_task_id');

        if ($relatedTaskId) {
            /** @var \App\Models\Evento|null $task */
            $task = Evento::whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))
                ->find((int) $relatedTaskId);

            if ($task && !$task->is_completed) {
                $task->update([
                    'is_completed' => true,
                    'completed_at' => now(),
                ]);
            }
        }

        // -------------------------------------------------------------
        // 2. SMART TASK KILLER: Uccisione Globale (Verifica incassi rata)
        // -------------------------------------------------------------
        if (!empty($rataIdsReali)) {
            // Si chiude la «verifica incassi» globale di una rata solo quando ogni sua quota è pagata: con la somma, lo
            // strapagamento di uno (la parte in più di chi ha versato per un altro, un anticipo) nascondeva il debito di
            // un altro (reperto S7 della Fase 1-bis della 1.11.0-beta.40).
            foreach (Rata::whereIn('id', $rataIdsReali)->get() as $rataPadre) {
                if ($eventiRata->rataSaldata($rataPadre)) {
                    $rataPadreId = (int) $rataPadre->id;

                    Evento::where('meta->type', 'controllo_incassi')
                        ->where(function($q) use ($rataPadreId) {
                            $q->where('meta->context->rata_id', $rataPadreId)
                              ->orWhere('meta->context->rata_id', (string) $rataPadreId);
                        })
                        ->where('is_completed', false)
                        ->update([
                            'is_completed' => true,
                            'completed_at' => now(),
                        ]);
                }
            }
        }

        // Svuotiamo la cache della inbox dell'admin una sola volta alla fine
        InboxService::clearAdminCache();

        return to_route('admin.gestionale.movimenti-rate.index', $condominio)
            ->with($this->flashSuccess('Incasso registrato con successo.'));
    }

    /**
     * Mostra il dettaglio di un singolo incasso.
     * Recupera le informazioni estese della scrittura contabile, il pagante,
     * il conto di accredito e la scomposizione delle rate pagate con questo versamento.
     *
     * @param Condominio $condominio Il condominio di appartenenza.
     * @param string|int $scrittura L'ID della scrittura contabile (l'incasso) da visualizzare.
     * @return \Inertia\Response
     */
    public function show(Condominio $condominio, string|int $scrittura)
    {
        $scrittura = \App\Models\Gestionale\ScritturaContabile::findOrFail($scrittura);

        \Illuminate\Support\Facades\Log::info("IncassoRateController@show reached", [
            'condominio_id' => $condominio->id,
            'scrittura_id' => $scrittura->id,
            'tipo_movimento' => is_object($scrittura->tipo_movimento) ? $scrittura->tipo_movimento->value : $scrittura->tipo_movimento,
        ]);

        // Verifica di sicurezza base
        if ((int) $scrittura->condominio_id !== (int) $condominio->id || $scrittura->tipo_movimento !== \App\Enums\TipoMovimentoContabile::INCASSO_RATA) {
            \Illuminate\Support\Facades\Log::error("IncassoRateController@show ABORT 404", [
                'condominio_id_match' => (int) $scrittura->condominio_id === (int) $condominio->id,
                'tipo_match' => $scrittura->tipo_movimento === \App\Enums\TipoMovimentoContabile::INCASSO_RATA,
            ]);
            abort(404);
        }

        // Eager loading per tutte le informazioni di dettaglio
        $scrittura->load([
            'righe.anagrafica',
            'righe.cassa',
            'righe.riferimento',
            'quotePagate.rata.pianoRate.gestione',
            'quotePagate.immobile',
            'figlie.quotePagate.rata',
            'figlie.quotePagate.immobile',
            'figlie.righe.anagrafica',
        ]);

        // Caricamento del nome utente creatore per l'audit trail
        $utenteCreatore = DB::table('users')
            ->where('id', $scrittura->created_by)
            ->value('name');

        // Idem per l'utente che ha effettuato un eventuale storno
        $utenteStornatore = null;
        if ($scrittura->stato === 'stornato' && !empty($scrittura->dati_extra['stornato_da_user_id'])) {
            $utenteStornatore = DB::table('users')
                ->where('id', $scrittura->dati_extra['stornato_da_user_id'])
                ->value('name');
        }

        // Recuperiamo anche i formati frontend se vogliamo riciclare le stesse strutture logiche
        $formattato = clone $scrittura;
        $formattato = $this->incassoService->formatMovimentoForFrontend($formattato);

        $listaPalazzi = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/movimenti/incassi/IncassoRateShow', [
            'condominio'       => new CondominioResource($condominio),
            'esercizio'        => $esercizio,
            'condomini'        => $listaPalazzi,
            // Solo i campi che la pagina legge (S12 del rigiro della Fase 1-bis): con le relazioni caricate partivano
            // anche le anagrafiche intere delle righe — codice fiscale, email, telefono — che la pagina non mostra.
            // Il resto lo legge da `incassoFormatted`.
            'incasso'          => $scrittura->only(['id', 'numero_protocollo', 'stato', 'causale', 'data_competenza', 'updated_at']),
            'incassoFormatted' => $formattato,
            'utenteCreatore'   => $utenteCreatore,
            'utenteStornatore' => $utenteStornatore,
        ]);
    }
}