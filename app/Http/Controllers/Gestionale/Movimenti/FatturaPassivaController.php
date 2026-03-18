<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreFatturaRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Fornitore;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaPassiva;
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
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class FatturaPassivaController extends Controller
{
    use HandleFlashMessages, HasEsercizio, HasCondomini;

    public function __construct(private FatturaPassivaService $service) {}

    public function index(Request $request, Condominio $condominio)
    {
        $fatture = FatturaPassiva::where('condominio_id', $condominio->id)
            ->with(['fornitore', 'righe'])
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

    public function create(Condominio $condominio)
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
        $spesePerConto = collect(); // La nostra mappa dei costi reali

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

            // --- CALCOLO SPESA REALE (CONSUNTIVATO) DALLE FATTURE ---
            // Interroghiamo direttamente le righe delle fatture per la massima precisione
            $spesePerConto = DB::table('righe_fattura')
                ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
                ->where('fatture_passive.condominio_id', $condominio->id)
                ->where('fatture_passive.esercizio_id', $esercizio->id)
                ->where('fatture_passive.is_pregresso', false) // MAGIA: Ignoriamo i debiti pregressi!
                ->groupBy('righe_fattura.conto_id')
                ->selectRaw('righe_fattura.conto_id, SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva) as totale_spesa')
                ->pluck('totale_spesa', 'righe_fattura.conto_id');
        }

        // --- DATI PER IL WIDGET DOUBLE LOCK (DEBITI PREGRESSI E FONDI) ---

        // 1. Calcoliamo la Rata 0 Globale (Crediti vs Condòmini) GIA' EROSA e INCASSATA
        // A. Il monte debiti totale iniziale richiesto ai condòmini (La Delibera)
        $totaleRataZeroInizialeCents = Saldo::where('condominio_id', $condominio->id)
            ->where('esercizio_id', $esercizio->id)
            ->whereNotNull('anagrafica_id')
            ->where('saldo_iniziale', '>', 0)
            ->sum('saldo_iniziale');

        // B. Quanto è già stato incassato in cassa di questa Rata 0?
        $incassiCorrenti = ScritturaContabile::where('condominio_id', $condominio->id)
            ->where('esercizio_id', $esercizio->id)
            ->where('tipo_movimento', 'incasso_rata')
            ->with('quotePagate.rata') // <--- Carichiamo anche i dati della Rata padre
            ->get();

        $totaleRataZeroIncassataCents = $incassiCorrenti->sum(function ($movimento) {
            return $movimento->quotePagate
                ->filter(function ($quota) {
                    // LA MAGIA: Controlliamo che il pagamento appartenga alla Rata 0
                    return $quota->rata && (
                        $quota->rata->numero_rata === 0 || 
                        $quota->rata->numero_rata === '0'
                    );
                })
                ->sum(function ($quota) {
                    return $quota->pivot->importo_pagato ?? 0;
                });
        });

        // C. Calcoliamo il costo LORDO di tutte le fatture pregresse già registrate
        $totalePregressoGiaUsatoCents = \Illuminate\Support\Facades\DB::table('fatture_passive')
            ->where('condominio_id', $condominio->id)
            ->where('esercizio_id', $esercizio->id)
            ->where('is_pregresso', true)
            ->sum(DB::raw('importo_imponibile + importo_iva'));

        $capienzaRataZeroResidua = max(0, $totaleRataZeroInizialeCents - $totalePregressoGiaUsatoCents);

        // 2. Estraiamo i Debiti verso Fornitori e calcoliamo il loro residuo individuale
        $debitiPatrimoniali = collect();
        if ($esercizio) {
            $debitiPatrimoniali = Saldo::where('condominio_id', $condominio->id)
                ->where('esercizio_id', $esercizio->id)
                ->whereNull('anagrafica_id') // Solo i debiti verso terzi
                ->where('saldo_iniziale', '<', 0) // Solo le passività
                ->get()
                ->map(function($saldo) {
                    $importoInizialeCents = abs($saldo->saldo_iniziale);

                    // Troviamo le fatture pregresse GIA' collegate a questo specifico saldo
                    $fattureCollegate = FatturaPassiva::where('saldo_patrimoniale_id', $saldo->id)
                        ->where('is_pregresso', true)
                        ->get()
                        ->map(function($f) {
                            // Convertiamo in Euro per la visualizzazione nel widget
                            $lordoEuro = ($f->importo_imponibile + $f->importo_iva) / 100;
                            return [
                                'id'               => $f->id,
                                'numero_documento' => $f->numero_documento ?? 'S/N',
                                'data_documento'   => $f->data_documento ? \Carbon\Carbon::parse($f->data_documento)->format('d/m/Y') : '',
                                'importo_usato'    => round($lordoEuro, 2)
                            ];
                        });

                    // Sottraiamo il lordo delle fatture collegate dal debito iniziale
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

        // 2. I Fondi di Riserva disponibili
        $fondiRiserva = ContoContabile::where('condominio_id', $condominio->id)
            ->where('ruolo', 'fondo_riserva') // o categoria = 'fondi'
            ->withSum(['movimenti as totale_dare' => function ($q) {
                $q->where('tipo_riga', 'dare');
            }], 'importo')
            ->withSum(['movimenti as totale_avere' => function ($q) {
                $q->where('tipo_riga', 'avere');
            }], 'importo')
            ->get()
            ->map(function($fondo) {
                // Essendo una passività (Fondo), il saldo aumenta in AVERE e diminuisce in DARE
                $saldo = ($fondo->totale_avere ?? 0) - ($fondo->totale_dare ?? 0);
                return [
                    'id'            => $fondo->id,
                    'nome'          => $fondo->nome,
                    'saldo_attuale' => max(0, $saldo), // Evitiamo saldi negativi nel frontend
                ];
            });

        // 3. TUTTE le fatture pregresse già registrate in questo esercizio (Il Radar Anti-Duplicati)
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
                        'fornitore_id'     => $f->fornitore_id, // Fondamentale per il filtro
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

            // --- CARICAMENTO CONTI CON BUDGET DINAMICO E STORICO ANTIDUPLICAZIONE ---
            'conti' => Conto::whereIn('piano_conto_id', $condominio->pianiDeiConti()->pluck('id'))
                ->with('parent')
                ->whereDoesntHave('sottoconti')
                ->get()
                // Aggiungiamo $spesePerConto all'uso della closure
                ->map(function ($conto) use ($ultimeSpese, $spesePerConto) {
                    
                    $budgetApprovato = $conto->importo ?? 0; 
                    
                    // Ora la spesa attuale è reale: se non c'è, è 0
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
                        'residuo_budget'   => $residuo, // Questo finalmente scenderà!
                        'is_capiente'      => $residuo >= 0,
                        'ultimi_movimenti' => $storicoRecente 
                    ];
                })
                ->sortBy('_sort_key')
                ->values(),

            // --- CARICAMENTO CASSE E SALDO DINAMICO ---
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

    public function store(StoreFatturaRequest $request, Condominio $condominio)
    {
        try {
            // Esecuzione del Service
            $this->service->registraFattura(
                $request->validated(),
                $condominio->id,
                $request->file('file')
            );

            // Verifica se è stato attivato lo Scudo Legale
            $coperture = collect($request->input('coperture', []));
            $sopravvenienze = $coperture->where('tipo_copertura', 'sopravvenienza');

            if ($sopravvenienze->isNotEmpty()) {
                // Ritorna sulla stessa pagina (back) per permettere inserimenti multipli
                return back()->with('success', 'Fattura e Scudo Legale registrati con successo!');
            }

            // Ritorna sulla stessa pagina per inserimenti multipli standard
            return back()->with('success', 'Fattura registrata con successo.');

        } catch (ModelNotFoundException $e) {
            // Log solo in caso di errore
            Log::error("❌ ERRORE 404: " . $e->getMessage());
            return back()->withErrors(['error' => 'Risorsa non trovata. Verifica fornitore, conto e gestione.']);

        } catch (\Exception $e) {
            // Log spietato per catturare problemi al database
            Log::error("❌ FATAL ERROR NEL SERVICE: " . $e->getMessage());
            Log::error("Traccia: " . $e->getTraceAsString());
            
            return back()->withErrors(['error' => $e->getMessage()]);
        }
    }
}