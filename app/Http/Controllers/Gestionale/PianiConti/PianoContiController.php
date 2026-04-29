<?php

namespace App\Http\Controllers\Gestionale\PianiConti;

use App\Enums\StatoPianoRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoConto\CreatePianoContoRequest;
use App\Http\Requests\Gestionale\PianoConto\PianoContoIndexRequest;
use App\Http\Requests\Gestionale\PianoConto\UpdatePianoContoRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Gestionale\PianiDeiConti\Conti\ContoResource;
use App\Http\Resources\Gestionale\PianiDeiConti\PianoDeiContiResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Tabella;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestione;
use App\Services\Gestionale\BudgetCoverageService; 
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB; 
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

class PianoContiController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    public function index(PianoContoIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        $validated = $request->validated();

        $pianiDeiConti = $condominio->pianiDeiConti()
            ->whereHas('gestione.esercizi', function ($q) use ($esercizio) {
                $q->where('esercizio_id', $esercizio->id);
            })
            ->with(['gestione.esercizi' => function ($q) use ($esercizio) {
                $q->where('esercizio_id', $esercizio->id);
            }])
            ->when($validated['nome'] ?? false, function ($query, $nome) {
                $query->where('nome', 'like', "%{$nome}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        return Inertia::render('gestionale/pianiDeiConti/PianiDeiContiList', [
            'condominio'    => $condominio,
            'esercizio'     => $esercizio,
            'esercizi'      => $esercizi,
            'condomini'     => CondominioResource::collection($this->getCondomini()),
            'pianiDeiConti' => PianoDeiContiResource::collection($pianiDeiConti)->resolve(),
            'meta' => [
                'current_page' => $pianiDeiConti->currentPage(),
                'last_page'    => $pianiDeiConti->lastPage(),
                'per_page'     => $pianiDeiConti->perPage(),
                'total'        => $pianiDeiConti->total(),
            ],
            'filters' => $request->only(['nome']),
        ]);
    }

    public function create(Condominio $condominio, Esercizio $esercizio)
    {
        $condomini = $this->getCondomini();

        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        $gestioni = Gestione::whereHas('esercizi', function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            })
            ->whereDoesntHave('pianoConto')
            ->with(['esercizi' => function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            }])
            ->get();

        if ($gestioni->isEmpty()) {
            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio' => $condominio->id,
                'esercizio'  => $esercizio->id,
            ])->with($this->flashWarning(__('gestionale.warning_all_gestioni_have_piano_conti')));
        }

        return Inertia::render('gestionale/pianiDeiConti/PianiDeiContiNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'esercizi'   => $esercizi,
            'condomini'  => $condomini,
            'gestioni'   => $gestioni,
        ]);
    }

    public function store(CreatePianoContoRequest $request, Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        try {
            $data       = $request->validated();
            $pianoConto = PianoConto::create($data);

            return to_route('admin.gestionale.esercizi.piani-conti.show', [
                'condominio' => $condominio->id,
                'esercizio'  => $esercizio->id,
                'pianoConto' => $pianoConto->id,
            ])->with($this->flashSuccess(__('gestionale.success_create_piano_conto')));

        } catch (\Throwable $e) {
            Log::error('Error creating piano conti', [
                'condominio_id' => $condominio->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio' => $condominio->id,
                'esercizio'  => $esercizio->id,
            ])->with($this->flashError(__('gestionale.error_create_piano_conto')));
        }
    }

    public function show(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): Response
    {
        $conti = Conto::with([
            'sottoconti' => function ($query) {
                $query->with([
                    'tabelleMillesimali.tabella',
                    'tabelleMillesimali.ripartizioni' => fn($q) => $q->orderBy('soggetto'),
                    'pianiRate' 
                ]);
            },
            'tabelleMillesimali.tabella',
            'tabelleMillesimali.ripartizioni' => fn($q) => $q->orderBy('soggetto'),
            'pianiRate', 
        ])->where('piano_conto_id', $pianoConto->id)
        ->whereNull('parent_id')
        ->orderBy('nome')
        ->get();

        // Mappa dei Budget Originali (PRIMA della sovrascrittura)
        $budgetOriginaliMap = [];
        foreach ($conti as $c) {
            $budgetOriginaliMap[$c->id] = $c->importo;
            foreach ($c->sottoconti as $s) {
                $budgetOriginaliMap[$s->id] = $s->importo;
            }
        }

        $rawFatturato = DB::table('righe_fattura')
            ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
            ->where('fatture_passive.esercizio_id', $esercizio->id)
            ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
            ->select('righe_fattura.conto_id', DB::raw('SUM(righe_fattura.importo_imponibile + righe_fattura.importo_iva) as totale'))
            ->groupBy('righe_fattura.conto_id')
            ->get();

        $fatturatoMap = [];
        foreach ($rawFatturato as $row) {
            $fatturatoMap[(int)$row->conto_id] = (int)$row->totale;
        }
        
        // 4. Addebiti Personali (Widget Audit) - FIX Comproprietà
        // Leggiamo la riga fattura, raggruppando i nomi dei proprietari con GROUP_CONCAT
        $addebitiRaw = DB::table('righe_fattura')
            ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
            ->leftJoin('immobili', 'righe_fattura.immobile_id', '=', 'immobili.id')
            ->leftJoin('anagrafica_immobile', fn($j) => $j->on('immobili.id', '=', 'anagrafica_immobile.immobile_id')->where('anagrafica_immobile.tipologia', 'proprietario'))
            ->leftJoin('anagrafiche', 'anagrafica_immobile.anagrafica_id', '=', 'anagrafiche.id')
            ->where('fatture_passive.esercizio_id', $esercizio->id)
            ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
            ->select(
                'righe_fattura.id as riga_id', // Group By sulla riga
                'righe_fattura.conto_id', 
                'righe_fattura.immobile_id', 
                'immobili.nome as imm_nome', 
                'immobili.interno as imm_int', 
                DB::raw('GROUP_CONCAT(anagrafiche.nome SEPARATOR ", ") as ana_nome'), // Unisce Cecilia e Marta
                DB::raw('(righe_fattura.importo_imponibile + righe_fattura.importo_iva) as riga_tot')
            )
            ->groupBy(
                'righe_fattura.id',
                'righe_fattura.conto_id', 
                'righe_fattura.immobile_id', 
                'immobili.nome', 
                'immobili.interno', 
                'riga_tot'
            )
            ->get();

        $addebitiMap = [];
        // Raccogliamo le spese per CONTO. Attenzione: più righe condominiali nello stesso conto vanno sommate!
        foreach ($addebitiRaw as $riga) {
            $contoId = (int)$riga->conto_id;
            
            if ($riga->immobile_id) {
                // È uno spesa privata
                $addebitiMap[$contoId][] = [
                    'tipo' => 'privato',
                    'immobile' => $riga->imm_nome . ' (Int. ' . $riga->imm_int . ')',
                    'proprietario' => $riga->ana_nome ?? 'N/D',
                    'importo' => (int) $riga->riga_tot
                ];
            } else {
                // È una spesa condominiale. La accumuliamo se esiste già per questo conto.
                $found = false;
                if (isset($addebitiMap[$contoId])) {
                    foreach ($addebitiMap[$contoId] as &$add) {
                        if ($add['tipo'] === 'condominiale') {
                            $add['importo'] += (int) $riga->riga_tot;
                            $found = true;
                            break;
                        }
                    }
                }
                
                if (!$found) {
                    $addebitiMap[$contoId][] = [
                        'tipo' => 'condominiale',
                        'immobile' => 'Intero Condominio',
                        'proprietario' => 'Ripartizione Millesimale',
                        'importo' => (int) $riga->riga_tot
                    ];
                }
            }
        }

        $fattureSforo = DB::table('righe_fattura')
            ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
            ->where('fatture_passive.esercizio_id', $esercizio->id)
            ->where('fatture_passive.stato_approvazione', 'sforo_motivato')
            ->select('righe_fattura.conto_id', 'fatture_passive.dati_extra', 'righe_fattura.importo_imponibile', 'righe_fattura.importo_iva')
            ->get();

        $coperturaVirtualeMap = [];
        $strategieSforoMap = [];

        foreach ($fattureSforo as $row) {
            $datiExtra = is_string($row->dati_extra) ? json_decode($row->dati_extra, true) : (array) $row->dati_extra;
            $strat = $datiExtra['override_budget']['strategia_rientro'] ?? 'conguaglio_fine_anno'; 
            
            // Logica intatta per il service
            if (in_array($strat, ['conguaglio_fine_anno', 'fondo_riserva'])) {
                $coperturaVirtualeMap[(int)$row->conto_id] = ($coperturaVirtualeMap[(int)$row->conto_id] ?? 0) + abs((int)$row->importo_imponibile + (int)$row->importo_iva);
            }

            // Calcolo DELTA per il widget (es. 1952 - 1545 = 407€)
            $spesoTotale = $fatturatoMap[$row->conto_id] ?? 0;
            $budgetIniziale = $budgetOriginaliMap[$row->conto_id] ?? 0;
            $delta = max(0, $spesoTotale - $budgetIniziale);

            if ($delta > 0) {
                $strategieSforoMap[(int)$row->conto_id][] = [
                    'strategia'   => $strat,
                    'importo'     => $delta,
                    'motivazione' => $datiExtra['override_budget']['motivazione'] ?? 'Nessuna nota specificata'
                ];
            }
        }

        // TUA LOGICA ORIGINALE INTATTA PER L'ALBERO VERDE
        foreach ($conti as $conto) {
            $speso = $fatturatoMap[$conto->id] ?? 0;
            if ($speso > $conto->importo) {
                $conto->importo = $speso;
            }
            foreach ($conto->sottoconti as $sottoconto) {
                $spesoSotto = $fatturatoMap[$sottoconto->id] ?? 0;
                if ($spesoSotto > $sottoconto->importo) {
                    $sottoconto->importo = $spesoSotto;
                }
            }
        }

        $coverageMap = [];
        $extraPianiNames = [];
        $gestione = $pianoConto->gestione;

        if ($gestione) {
            $service = new BudgetCoverageService();
            $report  = $service->analyze($gestione, $fatturatoMap, $coperturaVirtualeMap); 

            foreach ($report['items'] ?? [] as $item) {
                $coverageMap[(int) $item['id']] = (int) ($item['pianificato'] ?? 0);
            }

            $extraPianiNames = \App\Models\Gestionale\PianoRate::where('gestione_id', $gestione->id)
                ->where('tipo', 'straordinario')
                ->pluck('nome')
                ->toArray();
        }

        // Inizio fix piani straordinari
        $pianiStraordinariMap = [];
 
        if ($gestione) {
            // Riusiamo i piani già caricati dal service (già in memoria)
            $gestione->loadMissing(['pianiRate.fattureStraordinarie']);
        
            $straordinari = $gestione->pianiRate->filter(
                fn($p) => $p->tipo === 'straordinario'
            );
        
            if ($straordinari->isNotEmpty()) {
                $fattureIds = $straordinari
                    ->flatMap->fattureStraordinarie
                    ->pluck('id')
                    ->unique()
                    ->toArray();
        
                if (!empty($fattureIds)) {
                    // Stessa query dello Step 3 del BudgetCoverageService
                    $righePerFattura = DB::table('righe_fattura')
                        ->whereIn('fattura_passiva_id', $fattureIds)
                        ->whereNotNull('conto_id')        // escludi righe private
                        ->get()
                        ->groupBy('fattura_passiva_id');
        
                    foreach ($straordinari as $piano) {
                        $statoPiano = $piano->stato instanceof \App\Enums\StatoPianoRate
                            ? $piano->stato->value
                            : $piano->stato;
        
                        foreach ($piano->fattureStraordinarie as $fattura) {
                            $importoFinanziato = (int) ($fattura->pivot->importo_collegato ?? 0);
                            if ($importoFinanziato <= 0) continue;
        
                            $righeFattura = $righePerFattura->get($fattura->id, collect());
                            if ($righeFattura->isEmpty()) continue;
        
                            $totaleFattura = $righeFattura->sum(
                                fn($r) => $r->importo_imponibile + $r->importo_iva
                            );
                            if ($totaleFattura <= 0) continue;
        
                            foreach ($righeFattura as $riga) {
                                $importoRiga = $riga->importo_imponibile + $riga->importo_iva;
                                $quota = (int) round(($importoRiga / $totaleFattura) * $importoFinanziato);
                                if ($quota <= 0) continue;
        
                                $contoId = (int) $riga->conto_id;
        
                                // Accumula per conto, raggruppando per piano
                                $trovato = false;
                                foreach ($pianiStraordinariMap[$contoId] ?? [] as &$entry) {
                                    if ($entry['id'] === $piano->id) {
                                        $entry['importo'] += $quota;
                                        $trovato = true;
                                        break;
                                    }
                                }
                                unset($entry);
        
                                if (!$trovato) {
                                    $pianiStraordinariMap[$contoId][] = [
                                        'id'      => $piano->id,
                                        'nome'    => $piano->nome,
                                        'stato'   => $statoPiano,
                                        'importo' => $quota,
                                    ];
                                }
                            }
                        }
                    }
                }
            }
        }

        // Fine fix piani straordinari

        ContoResource::$coverageMap = $coverageMap;
        ContoResource::$extraPianiNames = $extraPianiNames;
        ContoResource::$addebitiMap = $addebitiMap; // Passata
        ContoResource::$strategieSforoMap = $strategieSforoMap; // Passata
        ContoResource::$budgetOriginaliMap = $budgetOriginaliMap; // Passata
        ContoResource::$pianiStraordinariMap = $pianiStraordinariMap;

        // --- FIX v1.9.23: TOTALI SEPARATI (Preventivo vs Sopravvenienze) ---
        $totalePreventivo = 0;
        $totaleSopravvenienze = 0;

        foreach ($conti as $conto) {
            if ($conto->is_tecnico) {
                $totaleSopravvenienze += $conto->importo;
                foreach ($conto->sottoconti as $sottoconto) {
                    $totaleSopravvenienze += $sottoconto->importo;
                }
            } else {
                $totalePreventivo += $conto->importo;
                foreach ($conto->sottoconti as $sottoconto) {
                    if ($sottoconto->is_tecnico) {
                        $totaleSopravvenienze += $sottoconto->importo;
                    } else {
                        $totalePreventivo += $sottoconto->importo;
                    }
                }
            }
        }
        // -----------------------------------------------------------------

        $fornitori = Fornitore::attivi()->orderBy('ragione_sociale')->get(['id', 'ragione_sociale']);

        return Inertia::render('gestionale/pianiDeiConti/conti/ContiNew', [
            'condominio'            => ['id' => $condominio->id, 'nome' => $condominio->nome],
            'esercizio'             => ['id' => $esercizio->id, 'nome' => $esercizio->nome],
            'pianoConti'            => new PianoDeiContiResource($pianoConto),
            'conti'                 => ContoResource::collection($conti),
            'fornitori'             => $fornitori,
            'tabelle'               => Tabella::query()->where('condominio_id', $condominio->id)->orderBy('nome')->get(['id', 'nome']),
            'totalePreventivo'      => $totalePreventivo,
            'totaleSopravvenienze'  => $totaleSopravvenienze,
        ]);
    }

    public function edit(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): Response
    {
        $pianoConto->loadMissing(['gestione']);
        $gestioni = Gestione::whereHas('esercizi', function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            })->with(['esercizi' => function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            }])->get();

        return Inertia::render('gestionale/pianiDeiConti/PianiDeiContiEdit', [
            'condominio'  => $condominio,
            'esercizio'   => $esercizio,
            'gestioni'    => $gestioni,
            'pianoConti'  => new PianoDeiContiResource($pianoConto),
        ]);
    }

    public function update(UpdatePianoContoRequest $request, Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): RedirectResponse
    {
        try {

            $data = $request->validated();
            $pianoConto->update($data);
            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio' => $condominio->id, 'esercizio'  => $esercizio->id, 'pianoConto' => $pianoConto->id,
            ])->with($this->flashSuccess(__('gestionale.success_update_piano_conto')));

        } catch (\Throwable $e) {

            Log::error('Error updating piano conti', ['condominio_id' => $condominio->id, 'piano_conto_id' => $pianoConto->id, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio' => $condominio->id, 'esercizio'  => $esercizio->id, 'pianoConto' => $pianoConto->id,
            ])->with($this->flashError(__('gestionale.error_update_piano_conto')));
            
        }
    }

    public function destroy(Condominio $condominio, Esercizio $esercizio, PianoConto $pianoConto): RedirectResponse
    {
        try {
            $pianoConto->delete();
            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio' => $condominio->id, 'esercizio'  => $esercizio->id, 'pianoConto' => $pianoConto->id,
            ])->with($this->flashSuccess(__('gestionale.success_delete_piano_conto')));
        } catch (\Throwable $e) {
            Log::error('Error deleting piano conto', ['condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'piano_conto_id' => $pianoConto->id, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return to_route('admin.gestionale.esercizi.piani-conti.index', [
                'condominio' => $condominio->id, 'esercizio'  => $esercizio->id, 'pianoConto' => $pianoConto->id,
            ])->with($this->flashError(__('gestionale.error_delete_piano_conto')));
        }
    }
}