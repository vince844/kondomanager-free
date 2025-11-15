<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\PianoRate\CreatePianoRateRequest;
use App\Http\Requests\Gestionale\PianoRate\PianoRateIndexRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Gestionale\Immobili\ImmobileResource;
use App\Http\Resources\Gestionale\PianiRate\PianoRateResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Services\PianoRateGenerator;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Recurr\Rule;

class PianoRateController extends Controller
{
    use HandleFlashMessages, HasCondomini;

    public function index(PianoRateIndexRequest $request, Condominio $condominio, Esercizio $esercizio): Response
    {
        $validated = $request->validated();
        $pianiRate = PianoRate::with(['gestione'])
            ->where('condominio_id', $condominio->id)
            ->whereHas('gestione.esercizi', function ($q) use ($esercizio) {
                $q->where('esercizio_id', $esercizio->id);
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));

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
                'total' => $pianiRate->total(),
            ],
            'filters' => $request->only(['nome']),
        ]);
    }

    public function create(Condominio $condominio, Esercizio $esercizio): Response
    {
        $condomini = $this->getCondomini();
        $esercizi = $condominio->esercizi()
            ->orderBy('data_inizio', 'desc')
            ->get(['id', 'nome', 'stato']);

        $gestioni = Gestione::whereHas('esercizi', function ($query) use ($esercizio) {
            $query->where('esercizio_id', $esercizio->id);
        })
            ->with(['esercizi' => function ($query) use ($esercizio) {
                $query->where('esercizio_id', $esercizio->id);
            }])
            ->get();

        return Inertia::render('gestionale/pianiRate/PianiRateNew', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'esercizi' => $esercizi,
            'condomini' => $condomini,
            'gestioni' => $gestioni,
        ]);
    }

    public function store(CreatePianoRateRequest $request, Condominio $condominio, Esercizio $esercizio, PianoRateGenerator $generator)
    {
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            $gestione = $this->verificaGestione($validated['gestione_id']);
            $pianoRate = $this->creaPianoRate($validated, $condominio);

            if (!empty($validated['recurrence_enabled'])) {
                $this->creaRicorrenza($pianoRate, $validated);
            }

            $statistiche = [];
            if (!empty($validated['genera_subito'])) {
                $statistiche = $generator->genera($pianoRate);
            }

            DB::commit();

            return $this->redirectSuccess($condominio, $esercizio, $pianoRate, $validated, $statistiche);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Errore creazione piano rate", [
                'condominio_id' => $condominio->id,
                'esercizio_id' => $esercizio->id,
                'errore' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate): Response
    {
        $pianoRate->load([
            'gestione',
            'rate.rateQuote.anagrafica',
            'rate.rateQuote.immobile',
        ]);

        $oggi = now()->format('Y-m-d');

        // === QUOTE PER ANAGRAFICA ===
        $quotePerAnagrafica = $pianoRate->rate
            ->flatMap->rateQuote
            ->groupBy('anagrafica_id')
            ->map(function ($quotes) use ($oggi) {
                $anagrafica = $quotes->first()->anagrafica;

                $rate = $quotes
                    ->groupBy(fn($q) => $q->rata->numero_rata)
                    ->map(function ($q) use ($oggi) {
                        $rata = $q->first()->rata;
                        $importo = $q->sum('importo');
                        $pagate = $q->where('stato', 'pagata')->sum('importo');
                        $stato = $pagate == $importo && $importo > 0 ? 'pagata' : 'non_pagata';

                        return [
                            'numero' => $rata->numero_rata,
                            'scadenza' => optional($rata->data_scadenza)->format('Y-m-d'),
                            'importo' => $importo,
                            'stato' => $stato,
                        ];
                    })
                    ->sortBy('numero')
                    ->values();

                return [
                    'anagrafica' => [
                        'id' => $anagrafica->id,
                        'nome' => $anagrafica->nome,
                    ],
                    'rate' => $rate,
                ];
            })
            ->values();

        // === QUOTE PER IMMOBILE ===
        $quotePerImmobile = $pianoRate->rate
            ->flatMap->rateQuote
            ->whereNotNull('immobile_id')
            ->groupBy('immobile_id')
            ->map(function ($quotes) use ($oggi) {
                $immobile = $quotes->first()->immobile;

                $rate = $quotes
                    ->groupBy('rata_id')
                    ->map(function ($q) use ($oggi) {
                        $rata = $q->first()->rata;
                        $importo = $q->sum('importo');
                        $pagate = $q->where('stato', 'pagata')->sum('importo');
                        $stato = $pagate == $importo && $importo > 0 ? 'pagata' : 'non_pagata';

                        return [
                            'numero' => $rata->numero_rata,
                            'scadenza' => optional($rata->data_scadenza)->format('Y-m-d'),
                            'importo' => $importo,
                            'stato' => $stato,
                        ];
                    })
                    ->sortBy(fn($r) => $r['numero'])
                    ->values();

                return [
                    'immobile' => [
                        'id' => $immobile->id,
                        'nome' => $immobile->nome ?? 'Sconosciuto',
                        'interno' => $immobile->interno,
                        'piano' => $immobile->piano,
                        'superficie' => $immobile->superficie,
                    ],
                    'rate' => $rate,
                ];
            })
            ->values();

        return Inertia::render('gestionale/pianiRate/PianiRateShow', [
            'condominio' => $condominio,
            'esercizio' => $esercizio,
            'pianoRate' => [
                'id' => $pianoRate->id,
                'nome' => $pianoRate->nome,
                'numero_rate' => $pianoRate->numero_rate,
                'data_inizio' => $pianoRate->data_inizio,
                'gestione' => $pianoRate->gestione->nome,
            ],
            'quotePerAnagrafica' => $quotePerAnagrafica,
            'quotePerImmobile' => $quotePerImmobile,
        ]);
    }
    
    protected function verificaGestione(int $gestioneId): Gestione
    {
        $gestione = Gestione::with(['pianoConto.conti', 'esercizi'])->findOrFail($gestioneId);
        if (!$gestione->pianoConto) {
            throw new \RuntimeException("La gestione non ha un piano conti associato.");
        }
        if (!$gestione->data_inizio) {
            throw new \RuntimeException("La gestione non ha una data di inizio definita.");
        }
        return $gestione;
    }

    protected function creaPianoRate(array $validated, Condominio $condominio): PianoRate
    {
        return PianoRate::create([
            'gestione_id'          => $validated['gestione_id'],
            'condominio_id'        => $condominio->id,
            'nome'                 => $validated['nome'],
            'descrizione'          => $validated['descrizione'] ?? null,
            'metodo_calcolo'       => $validated['metodo_calcolo'],
            'metodo_distribuzione' => $validated['metodo_distribuzione'] ?? 'prima_rata',
            'numero_rate'          => $validated['numero_rate'],
            'giorno_scadenza'      => $validated['giorno_scadenza'] ?? 1,
            'note'                 => $validated['note'] ?? null,
            'attivo'               => true,
        ]);
    }

    protected function creaRicorrenza(PianoRate $pianoRate, array $validated): void
    {
        $gestione = $pianoRate->gestione;
        $start = new \DateTime($gestione->data_inizio, new \DateTimeZone('Europe/Rome'));
        $rule = (new Rule())
            ->setStartDate($start)
            ->setFreq($validated['recurrence_frequency'])
            ->setInterval($validated['recurrence_interval'] ?? 1);

        if ($validated['recurrence_frequency'] === 'MONTHLY') {
            $rule->setByMonthDay([$validated['giorno_scadenza'] ?? $pianoRate->giorno_scadenza]);
        }

        $pianoRate->ricorrenza()->create([
            'frequency' => strtolower($validated['recurrence_frequency']),
            'interval' => $validated['recurrence_interval'] ?? 1,
            'by_month_day' => $validated['giorno_scadenza'] ?? $pianoRate->giorno_scadenza,
            'rrule' => $rule->getString(),
        ]);
    }

    protected function redirectSuccess(Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate, array $validated, array $statistiche = [])
    {
        $message = $validated['genera_subito']
            ? "Piano rate creato e generato con successo! Rate create: {$statistiche['rate_create']}, Quote create: {$statistiche['quote_create']}"
            : "Piano rate creato con successo! Genera le rate quando sei pronto.";

        return redirect()
            ->route('admin.gestionale.esercizi.piani-rate.show', [
                'condominio' => $condominio->id,
                'esercizio' => $esercizio->id,
                'pianoRate' => $pianoRate->id
            ])
            ->with('success', $message);
    }
}