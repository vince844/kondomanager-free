<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Actions\Gestionale\Movimenti\RegistraGirocontoAction;
use App\Actions\Gestionale\Movimenti\StornaGirocontoAction;
use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Gestionale\GirocontoNonAmmessoException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreGirocontoRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\FatturaCopertura;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\RiallineaFondiService;
use App\Services\Gestionale\SaldoCassaService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Giroconti — spostamenti di liquidità fra casse dello stesso condominio.
 *
 * I fondi sono partizioni contabili dell'unico conto corrente reale: un
 * giroconto banca → fondo accantona, fondo → banca libera liquidità (ed è
 * l'atto che conferma una copertura sforo), fondo → fondo ridestina. Nessun
 * denaro reale si muove: cambia solo la destinazione contabile.
 *
 * Come la Regolazione Immediata, il giroconto non è una nuova entità: è la
 * ScritturaContabile a due righe con tipo_movimento 'giroconto', previsto
 * nell'enum fin dal primo giorno.
 */
class GirocontoController extends Controller
{
    use HandleFlashMessages;
    use HasCondomini;
    use HasEsercizio;

    public function index(Request $request, Condominio $condominio, RiallineaFondiService $riallinea): Response
    {
        $scritture = ScritturaContabile::where('condominio_id', $condominio->id)
            ->whereIn('tipo_movimento', [
                TipoMovimentoContabile::GIROCONTO->value,
                TipoMovimentoContabile::STORNO_GIROCONTO->value,
            ])
            ->with(['righe.cassa'])
            ->when($request->search, function ($q, $v) {
                $q->where(function ($sub) use ($v) {
                    $sub->where('causale', 'like', "%{$v}%")
                        ->orWhere('numero_protocollo', 'like', "%{$v}%");
                });
            })
            ->orderByDesc('data_competenza')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        // Un giroconto è stornabile se non ha già uno storno figlio.
        $stornatiIds = ScritturaContabile::where('condominio_id', $condominio->id)
            ->where('tipo_movimento', TipoMovimentoContabile::STORNO_GIROCONTO->value)
            ->whereNotNull('scrittura_padre_id')
            ->pluck('scrittura_padre_id');

        // Coperture confermate da queste scritture (per la colonna "Copertura").
        $coperture = FatturaCopertura::whereIn('scrittura_giroconto_id', $scritture->getCollection()->pluck('id'))
            ->with('fattura:id,numero_documento')
            ->get()
            ->keyBy('scrittura_giroconto_id');

        $rows = $scritture->getCollection()->map(function (ScritturaContabile $s) use ($stornatiIds, $coperture) {
            $rigaDare = $s->righe->firstWhere('tipo_riga', 'dare');
            $rigaAvere = $s->righe->firstWhere('tipo_riga', 'avere');
            $copertura = $coperture->get($s->id);
            $isStorno = $s->tipo_movimento === TipoMovimentoContabile::STORNO_GIROCONTO;

            return [
                'id' => $s->id,
                'numero_protocollo' => $s->numero_protocollo,
                'data' => $s->data_competenza?->format('d/m/Y'),
                'causale' => $s->causale,
                'is_storno' => $isStorno,
                'origine' => $rigaAvere?->cassa?->nome ?? '—',
                'destinazione' => $rigaDare?->cassa?->nome ?? '—',
                'importo' => (int) ($rigaDare?->importo ?? 0),
                'copertura_fattura' => $copertura?->fattura?->numero_documento,
                'copertura_fattura_id' => $copertura?->fattura?->id,
                'stornato' => ! $isStorno && $stornatiIds->contains($s->id),
                'stornabile' => ! $isStorno && ! $stornatiIds->contains($s->id),
            ];
        });

        $stats = [
            'giroconti_attivi' => ScritturaContabile::where('condominio_id', $condominio->id)
                ->where('tipo_movimento', TipoMovimentoContabile::GIROCONTO->value)
                ->whereNotIn('id', $stornatiIds)
                ->count(),
            'stornati' => $stornatiIds->count(),
            'coperture_confermate' => FatturaCopertura::whereNotNull('scrittura_giroconto_id')
                ->whereHas('fattura', fn ($q) => $q->where('condominio_id', $condominio->id))
                ->count(),
        ];

        return Inertia::render('gestionale/movimenti/giroconti/GirocontiList', [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'giroconti' => [
                'data' => $rows,
                'meta' => [
                    'current_page' => $scritture->currentPage(),
                    'last_page' => $scritture->lastPage(),
                    'total' => $scritture->total(),
                    'per_page' => $scritture->perPage(),
                ],
            ],
            'stats' => $stats,
            'filters' => $request->only(['search']),
            // Card di riallineamento: compare SOLO se esistono scritture storiche
            // sui fondi da neutralizzare (pre-beta.19). A rettifiche fatte, il
            // rilevamento non trova più nulla e la card sparisce da sola.
            'riallineamento' => $riallinea->rileva($condominio),
        ]);
    }

    /**
     * Esegue il riallineamento delle scritture storiche sui fondi.
     * Parte solo dalla conferma esplicita dell'amministratore nella card.
     */
    public function riallinea(Condominio $condominio, RiallineaFondiService $service): RedirectResponse
    {
        $esito = $service->esegui($condominio);

        if ($esito['rettificate'] === 0) {
            return back()->with($this->flashInfo('Nessuna scrittura da riallineare.'));
        }

        Log::info(
            "Riallineamento fondi condominio #{$condominio->id} da utente #".Auth::id()
            ." — {$esito['rettificate']} rettifiche: ".implode(', ', $esito['protocolli'])
        );

        return back()->with($this->flashSuccess(sprintf(
            'Riallineamento completato: %d scritture di rettifica (%s). I saldi dei fondi ora riflettono i soli giroconti.',
            $esito['rettificate'],
            implode(', ', $esito['protocolli'])
        )));
    }

    /**
     * Form di registrazione: casse attive con saldo disponibile (fondi INCLUSI —
     * è l'unica pagina dove i fondi si movimentano) e coperture in attesa di
     * conferma, con precompilazione se si arriva dal deep-link della fattura.
     */
    public function create(Request $request, Condominio $condominio, SaldoCassaService $saldi): Response
    {
        $copertureInAttesa = FatturaCopertura::where('tipo_copertura', 'fondo_riserva')
            ->where('stato', 'pianificata')
            ->where('importo', '>', 0)
            // Le fatture stornate escono dal giro: confermare la loro copertura
            // consumerebbe il fondo per un debito che non esiste più.
            ->whereHas('fattura', fn ($q) => $q->where('condominio_id', $condominio->id)
                ->where('stato_pagamento', '!=', 'stornata'))
            ->with(['fattura:id,numero_documento,fornitore_id', 'fattura.fornitore:id,ragione_sociale'])
            ->get()
            ->map(fn (FatturaCopertura $c) => [
                'id' => $c->id,
                'importo' => $c->importo,
                'fondo_id' => $c->fondo_id,
                'fattura_id' => $c->fattura?->id,
                'fattura_numero' => $c->fattura?->numero_documento ?? 'S/N',
                'fornitore' => $c->fattura?->fornitore?->ragione_sociale ?? '—',
                'label' => sprintf(
                    'Ft. %s — %s — %s',
                    $c->fattura?->numero_documento ?? 'S/N',
                    $c->fattura?->fornitore?->ragione_sociale ?? '—',
                    \App\Helpers\MoneyHelper::format($c->importo)
                ),
            ])
            ->values();

        return Inertia::render('gestionale/movimenti/giroconti/GirocontoNew', [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            // Obbligatorio: GestionaleHeader legge `props.esercizio` (singolare) per
            // costruire i link a Gestioni, Piani conti e Piani rate. Senza, il setup
            // del componente va in errore e l'intera barra di navigazione sparisce.
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'esercizi' => $condominio->esercizi()->where('stato', 'aperto')->get(['esercizi.id', 'esercizi.nome']),
            'gestioni' => $condominio->gestioni()
                ->where('gestioni.attiva', true)
                ->with('esercizi:id')
                ->get()
                ->map(fn ($g) => [
                    'id' => $g->id,
                    'nome' => $g->nome,
                    'tipo' => $g->tipo,
                    'esercizio_ids' => $g->esercizi->pluck('id')->toArray(),
                ]),
            'casse' => $saldi->saldiPerCondominio($condominio),
            'coperture_in_attesa' => $copertureInAttesa,
            'copertura_preselezionata_id' => $request->integer('copertura_id') ?: null,
        ]);
    }

    public function store(
        StoreGirocontoRequest $request,
        Condominio $condominio,
        RegistraGirocontoAction $action
    ): RedirectResponse {
        $validated = $request->validated();

        $esercizio = Esercizio::where('condominio_id', $condominio->id)
            ->findOrFail($validated['esercizio_id']);

        try {
            $scrittura = $action->execute($validated, $condominio, $esercizio);

            Log::info(
                "Giroconto {$scrittura->numero_protocollo} registrato da utente #".Auth::id()
                ." — condominio #{$condominio->id}, importo {$validated['importo']}, causale: {$validated['causale']}"
            );

            // "Registra e nuovo": chi ha più operazioni da fare torna al modulo
            // vuoto (con saldi e coperture aggiornati), non al dettaglio scrittura.
            if ($request->boolean('crea_altro')) {
                return redirect()
                    ->route('admin.gestionale.giroconti.create', ['condominio' => $condominio->id])
                    ->with($this->flashSuccess(
                        "Giroconto {$scrittura->numero_protocollo} registrato. Il modulo è pronto per il prossimo."
                    ));
            }

            return redirect()
                ->route('admin.gestionale.scritture.show', [
                    'condominio' => $condominio->id,
                    'scrittura' => $scrittura->id,
                ])
                ->with($this->flashSuccess(
                    "Giroconto {$scrittura->numero_protocollo} registrato."
                ));
        } catch (GirocontoNonAmmessoException $e) {
            // Regola di dominio violata: il messaggio spiega cosa manca o quale
            // percorso usare, quindi va mostrato integralmente all'utente.
            return back()
                ->withInput()
                ->withErrors(['giroconto_non_ammesso' => $e->getMessage()]);
        }
    }

    /**
     * Storno di un giroconto: scrittura inversa, mai cancellazione.
     * Se il giroconto aveva confermato una copertura, la copertura torna in attesa.
     */
    public function storno(
        Request $request,
        Condominio $condominio,
        ScritturaContabile $scrittura,
        StornaGirocontoAction $action
    ): RedirectResponse {
        $validated = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'motivo.required' => 'È obbligatorio indicare il motivo dello storno.',
            'motivo.min' => 'Il motivo deve contenere almeno 10 caratteri.',
        ]);

        try {
            $storno = $action->execute($scrittura, $condominio, $validated['motivo']);

            Log::info(
                "Storno giroconto {$scrittura->numero_protocollo} → {$storno->numero_protocollo} "
                .'da utente #'.Auth::id()." — motivo: {$validated['motivo']}"
            );

            return redirect()
                ->route('admin.gestionale.scritture.show', [
                    'condominio' => $condominio->id,
                    'scrittura' => $storno->id,
                ])
                ->with($this->flashSuccess(
                    "Storno eseguito: scrittura {$storno->numero_protocollo}."
                ));
        } catch (GirocontoNonAmmessoException $e) {
            return back()->withErrors(['giroconto_non_ammesso' => $e->getMessage()]);
        }
    }
}
