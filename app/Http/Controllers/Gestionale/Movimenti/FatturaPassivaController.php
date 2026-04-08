<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreFatturaRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Fornitore;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Services\Gestionale\FatturaPassivaService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Controller per la gestione delle Fatture Passive (Ciclo Passivo).
 * * Gestisce la visualizzazione, la creazione, l'eliminazione sicura e il download
 * dei documenti fiscali passivi (fatture fornitori e note di credito) per un condominio.
 */
class FatturaPassivaController extends Controller
{
    use HandleFlashMessages, HasEsercizio, HasCondomini;

    /**
     * Inizializza il controller iniettando il service per le fatture passive.
     *
     * @param FatturaPassivaService $service Il servizio che contiene la logica di business e la partita doppia.
     */
    public function __construct(private FatturaPassivaService $service) {}

    /**
     * Mostra l'elenco delle fatture passive del condominio selezionato.
     *
     * Permette di filtrare i documenti per stato di pagamento, stato di approvazione e testo libero
     * (numero documento o ragione sociale del fornitore).
     *
     * @param Request $request La richiesta HTTP contenente i filtri (search, stato_pagamento, ecc).
     * @param Condominio $condominio Il condominio di cui si stanno visualizzando le fatture.
     * @return Response Vista Inertia con i dati paginati e le statistiche sommarie.
     */
    public function index(Request $request, Condominio $condominio): Response
    {
        $fatture = FatturaPassiva::where('condominio_id', $condominio->id)
            ->with(['fornitore', 'righe', 'documenti'])
            ->when($request->stato_pagamento, fn($q, $v) => $q->where('stato_pagamento', $v))
            ->when($request->stato_approvazione, fn($q, $v) => $q->where('stato_approvazione', $v))
            ->when($request->search, fn($q, $v) =>
                $q->where('numero_documento', 'like', "%{$v}%")
                  ->orWhereHas('fornitore', fn($qf) => $qf->where('ragione_sociale', 'like', "%{$v}%"))
            )
            ->orderByDesc('data_documento')
            ->paginate(20)
            ->withQueryString();
        
        $listaCondomini = CondominioResource::collection($this->getCondomini())->resolve();
        $esercizio = $this->getEsercizioCorrente($condominio);

        $stats = [
            'totale_aperte'       => FatturaPassiva::where('condominio_id', $condominio->id)->where('stato_pagamento', 'aperta')->count(),
            'totale_sfori'        => FatturaPassiva::where('condominio_id', $condominio->id)->where('stato_approvazione', 'sforo_motivato')->count(),
            'importo_da_pagare'   => FatturaPassiva::where('condominio_id', $condominio->id)->where('stato_pagamento', 'aperta')->sum('netto_a_pagare'),
        ];

        return Inertia::render('gestionale/movimenti/fatture/FatturaRegisterList', [
            'condominio' => $condominio,
            'fatture'    => $fatture,
            'stats'      => $stats,
            'esercizio'  => $esercizio,
            'condomini'  => $listaCondomini, 
            'filters'    => $request->only(['stato_pagamento', 'stato_approvazione', 'search']),
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
     * @param Condominio $condominio Il condominio in cui si sta registrando la fattura.
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
            $nextNum = str_pad((int)$matches[1] + 1, 4, '0', STR_PAD_LEFT);
            $protocolloSuggerito = "PR-{$annoInCorso}-{$nextNum}";
        } else {
            $protocolloSuggerito = "PR-{$annoInCorso}-0001";
        }
        // ------------------------------------------

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
                ->orderByDesc('fatture_passive.data_documento')
                ->get()
                ->groupBy('conto_id');

            $spesePerConto = DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('fatture_passive.condominio_id', $condominio->id)
                ->where('fatture_passive.esercizio_id', $esercizio->id)
                ->where('fatture_passive.is_pregresso', false) 
                ->groupBy('righe_fattura.conto_id')
                ->selectRaw('righe_fattura.conto_id, SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva) as totale_spesa')
                ->pluck('totale_spesa', 'righe_fattura.conto_id');
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
                ->map(function($saldo) {
                    $importoInizialeCents = abs($saldo->saldo_iniziale);

                    $fattureCollegate = FatturaPassiva::where('saldo_patrimoniale_id', $saldo->id)
                        ->where('is_pregresso', true)
                        ->get()
                        ->map(function($f) {
                            $lordoEuro = ($f->importo_imponibile + $f->importo_iva) / 100;
                            return [
                                'id'               => $f->id,
                                'numero_documento' => $f->numero_documento ?? 'S/N',
                                'data_documento'   => $f->data_documento ? \Carbon\Carbon::parse($f->data_documento)->format('d/m/Y') : '',
                                'importo_usato'    => round($lordoEuro, 2)
                            ];
                        });

                    $importoUsatoCents = $fattureCollegate->sum(fn($f) => $f['importo_usato'] * 100);
                    $importoDisponibileCents = max(0, $importoInizialeCents - $importoUsatoCents);

                    return [
                        'id'                  => $saldo->id,
                        'fornitore_id'        => $saldo->fornitore_id,
                        'descrizione'         => $saldo->descrizione ?? 'Debito pregresso senza descrizione',
                        'importo_iniziale'    => $importoInizialeCents,
                        'importo_disponibile' => (int) $importoDisponibileCents,
                        'fatture_collegate'   => $fattureCollegate->toArray(),
                    ];
                })->values();
        }

        // 3. I Fondi di Riserva disponibili (Presi dalla tabella CASSE)
        $fondiRiserva = Cassa::where('condominio_id', $condominio->id)
            ->where('tipo', 'fondo')
            ->where('attiva', true)
            ->get()
            ->map(function($cassa) {
                // Per ogni fondo, recuperiamo i movimenti DARE/AVERE dal suo conto contabile associato
                $movimenti = DB::table('righe_scritture')
                    ->where('conto_contabile_id', $cassa->conto_contabile_id)
                    ->selectRaw("SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE 0 END) as dare")
                    ->selectRaw("SUM(CASE WHEN tipo_riga = 'avere' THEN importo ELSE 0 END) as avere")
                    ->first();

                // Calcolo: Saldo Iniziale (della tabella casse) + Entrate - Uscite
                $saldoIniziale = $cassa->saldo_iniziale ?? 0;
                $saldoAttuale = $saldoIniziale + ($movimenti->avere ?? 0) - ($movimenti->dare ?? 0);

                return [
                    'id'            => $cassa->conto_contabile_id, // L'ID del conto serve per la registrazione contabile
                    'nome'          => $cassa->nome,
                    'saldo_attuale' => (int) max(0, $saldoAttuale),
                ];
            });

        // 4. Fatture pregresse registrate (Radar Anti-Duplicati)
        $fatturePregresseRegistrate = collect();
        if ($esercizio) {
            $fatturePregresseRegistrate = FatturaPassiva::where('condominio_id', $condominio->id)
                ->where('esercizio_id', $esercizio->id)
                ->where('is_pregresso', true)
                ->get()
                ->map(function($f) {
                    $lordoEuro = ($f->importo_imponibile + $f->importo_iva) / 100;
                    return [
                        'id'               => $f->id,
                        'fornitore_id'     => $f->fornitore_id,
                        'numero_documento' => $f->numero_documento ?? 'S/N',
                        'data_documento'   => $f->data_documento ? \Carbon\Carbon::parse($f->data_documento)->format('d/m/Y') : '',
                        'importo_usato'    => round($lordoEuro, 2)
                    ];
                });
        }

        return Inertia::render('gestionale/movimenti/fatture/FatturaRegisterNew', [
            'condominio' => $condominio,
            'fornitori'  => Fornitore::all(),
            'esercizi'   => $condominio->esercizi()->where('stato', 'aperto')->get(),
            'esercizio'  => $esercizio,
            'condomini'  => $listaCondomini, 
            'debiti_patrimoniali' => $debitiPatrimoniali,
            'fatture_pregresse_registrate' => $fatturePregresseRegistrate,
            'fondi_riserva'       => $fondiRiserva,
            'capienza_rata_zero'  => (int) $capienzaRataZeroResidua,
            'incassato_rata_zero' => (int) $totaleRataZeroIncassataCents,
            'gestioni' => $condominio->gestioni()
                ->where('gestioni.attiva', true)
                ->with('esercizi:id')
                ->get()
                ->map(function ($gestione) {
                    return [
                        'id'   => $gestione->id,
                        'nome' => $gestione->nome,
                        'tipo' => $gestione->tipo,
                        'esercizio_ids' => $gestione->esercizi->pluck('id')->toArray(),
                    ];
                }),

            'conti' => Conto::whereIn('piano_conto_id', $condominio->pianiDeiConti()->pluck('id'))
                ->with('parent')
                ->whereDoesntHave('sottoconti')
                ->get()
                ->map(function ($conto) use ($ultimeSpese, $spesePerConto) {
                    $budgetApprovato = $conto->importo ?? 0; 
                    $spesaAttuale    = $spesePerConto->get($conto->id, 0); 
                    $residuo         = $budgetApprovato - $spesaAttuale;

                    $storicoRecente = [];
                    if ($ultimeSpese->has($conto->id)) {
                        $storicoRecente = $ultimeSpese->get($conto->id)->take(3)->map(function($spesa) {
                            return [
                                'data'         => \Carbon\Carbon::parse($spesa->data_documento)->format('d/m/Y'),
                                'fornitore'    => $spesa->ragione_sociale,
                                'documento'    => $spesa->numero_documento,
                                'is_pregresso' => (bool) $spesa->is_pregresso, 
                                'importo'      => $spesa->importo_imponibile + $spesa->importo_iva,
                            ];
                        })->values()->toArray();
                    }

                    return [
                        'id'               => $conto->id,
                        'nome'             => $conto->nome, 
                        'parent_nome'      => $conto->parent ? $conto->parent->nome : null, 
                        '_sort_key'        => $conto->parent ? $conto->parent->nome . ' ' . $conto->nome : $conto->nome,
                        'codice'           => null,
                        'residuo_budget'   => $residuo,
                        'is_capiente'      => $residuo >= 0,
                        'ultimi_movimenti' => $storicoRecente 
                    ];
                })
                ->sortBy('_sort_key')
                ->values(),

            'banche' => Cassa::where('condominio_id', $condominio->id)
                ->where('attiva', true)
                ->withSum(['movimenti as totale_entrate' => function ($q) {
                    $q->where('tipo_riga', 'dare');
                }], 'importo')
                ->withSum(['movimenti as totale_uscite' => function ($q) {
                    $q->where('tipo_riga', 'avere');
                }], 'importo')
                ->get()
                ->map(function ($cassa) {
                    $entrate = $cassa->totale_entrate ?? 0;
                    $uscite  = $cassa->totale_uscite ?? 0;
                    $saldoIniziale = $cassa->saldo_iniziale ?? 0; 
                    $saldoAttuale = $saldoIniziale + $entrate - $uscite;

                    return [
                        'id'            => $cassa->conto_contabile_id, 
                        'cassa_id'      => $cassa->id,
                        'nome'          => $cassa->nome,
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
                        'id'    => $imm->id,
                        'label' => 'Int. ' . $imm->interno . ' — ' . $imm->nome, 
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
     * @param StoreFatturaRequest $request Request validata in ingresso.
     * @param Condominio $condominio Il condominio interessato.
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

            return back()->with($this->flashSuccess('Fattura registrata con successo.'));

        } catch (ModelNotFoundException $e) {
            Log::error("ERRORE 404: " . $e->getMessage());
            return back()->withErrors(['error' => 'Risorsa non trovata. Verifica fornitore, conto e gestione.']);

        } catch (\Exception $e) {
            Log::error("FATAL ERROR NEL SERVICE: " . $e->getMessage());
            Log::error("Traccia: " . $e->getTraceAsString());
            
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
     * @param Condominio $condominio Il condominio di appartenenza.
     * @param FatturaPassiva $fattura La fattura da eliminare.
     * @return RedirectResponse
     */
    public function destroy(Condominio $condominio, FatturaPassiva $fattura): RedirectResponse
    {
        // --- INIZIO FIX: BLOCCO INTELLIGENTE PIANO RATE STRAORDINARIO ---
        $pivotPlan = DB::table('piano_rate_fatture')->where('fattura_passiva_id', $fattura->id)->first();
        
        if ($pivotPlan) {
            $piano = PianoRate::find($pivotPlan->piano_rate_id);
            
            if ($piano) {
                // 1. Blocco Duro: Ci sono già incassi o emissioni contabili fisiche?
                $hasPagamenti = $piano->rate()->whereHas('rateQuote', fn($q) => $q->where('importo_pagato', '>', 0))->exists();
                $hasEmissioni = $piano->rate()->whereHas('rateQuote', fn($q) => $q->whereNotNull('scrittura_contabile_id'))->exists();

                if ($hasPagamenti || $hasEmissioni) {
                    return back()->with($this->flashError(
                        'Operazione negata: La fattura è in un Piano Straordinario con rate già emesse o incassate. Usa lo Storno.'
                    ));
                }

                // 2. Blocco Legale: Il piano è approvato (scudo Art. 1135)?
                $stato = is_object($piano->stato) ? $piano->stato->value : $piano->stato;
                if ($stato === 'approvato') {
                    return back()->with($this->flashError(
                        'Operazione negata: La fattura è in un Piano Approvato. Riporta il piano in Bozza per poterla eliminare.'
                    ));
                }

                // Se arriviamo qui, il piano è in 'bozza' ed è vuoto. 
                // Possiamo procedere! La 'cascadeOnDelete' della tua migration 
                // cancellerà in automatico la riga dalla tabella 'piano_rate_fatture' mantenendo pulito il database.
            }
        }
        // --- FINE FIX ---

        // 1. IL MURO CONTABILE
        if ($fattura->stato_pagamento !== 'aperta') {
            return back()->with($this->flashError(
                'Operazione negata: La fattura risulta pagata o parzialmente saldata. ' .
                'Per mantenere la coerenza del Libro Giornale, devi usare la funzione "Storna".'
            ));
        }

        $statoEsercizio = DB::table('esercizi')->where('id', $fattura->esercizio_id)->value('stato');

        if ($statoEsercizio === 'chiuso') {
            return back()->with($this->flashError(
                'Operazione negata: La fattura appartiene a un esercizio chiuso e rendicontato. Usa lo Storno.'
            ));
        }

        if ($fattura->dati_extra['is_stornata'] ?? false) {
            return back()->with($this->flashError(
                'Operazione negata: Questa fattura è già stata annullata tramite storno contabile.'
            ));
        }

        // --- RICERCA DELLA FATTURA ORIGINALE ---
        $fatturaOriginale = FatturaPassiva::where('condominio_id', $condominio->id)
            ->where('dati_extra->stornata_da_id', $fattura->id)
            ->first();

        // 2. ELIMINAZIONE FISICA E PULIZIA
        try {
            DB::transaction(function () use ($fattura, $fatturaOriginale) {
                
                // --- LA RESURREZIONE ---
                if ($fatturaOriginale) {
                    $datiExtraOriginali = $fatturaOriginale->dati_extra ?? [];
                    unset($datiExtraOriginali['is_stornata']);
                    unset($datiExtraOriginali['stornata_da_id']);
                    
                    $fatturaOriginale->update([
                        'stato_pagamento' => 'aperta',
                        'dati_extra'      => $datiExtraOriginali
                    ]);
                }

                $fattura->coperture()->delete();
                $fattura->righe()->delete();
                
                foreach ($fattura->scritture as $scrittura) {
                    $scrittura->righe()->delete();
                    $scrittura->forceDelete(); 
                }

                $fattura->scritture()->detach();

                foreach ($fattura->documenti as $documento) {
                    if (Storage::disk('local')->exists($documento->path)) {
                        Storage::disk('local')->delete($documento->path);
                    }
                    $documento->delete();
                }
                
                $fattura->delete();
            });

            $msg = $fatturaOriginale 
                ? 'Nota di Credito eliminata. La fattura originale è stata ripristinata e risulta di nuovo aperta.' 
                : 'Fattura eliminata fisicamente dal sistema.';

            return back()->with($this->flashSuccess($msg));
            
        } catch (\Exception $e) {
            Log::error("Errore durante l'eliminazione fisica della fattura ID {$fattura->id}: " . $e->getMessage());
            return back()->with($this->flashError('Errore di sistema durante l\'eliminazione.'));
        }
    }

    /**
     * Esegue il download sicuro (protetto) del file PDF allegato alla fattura.
     *
     * Applica un controllo autorizzativo tramite Policy e un controllo anti-IDOR polimorfico
     * per garantire che il documento richiesto appartenga effettivamente alla fattura in oggetto.
     * I file sono protetti nel disco privato (local) del server.
     *
     * @param Condominio $condominio Il condominio della fattura.
     * @param FatturaPassiva $fattura La fattura passiva "contenitore".
     * @param Documento $documento Il documento polimorfico associato.
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
            if (!Storage::disk('local')->exists($documento->path)) {
                return redirect()->back()->with(
                    $this->flashError(__('documenti.file_not_found') ?? 'File della fattura non trovato sul server.')
                );
            }

            // Otteniamo il percorso assoluto del file sul server
            $percorsoAssoluto = Storage::disk('local')->path($documento->path);
            
            return response()->download($percorsoAssoluto, $documento->name);

        } catch (\Exception $e) {
            Log::error("Errore download fattura ID {$fattura->id}: " . $e->getMessage());
            
            return redirect()->back()->with(
                $this->flashError(__('documenti.error_downloading_document') ?? 'Errore durante il download del documento.')
            );
        }
    }
}