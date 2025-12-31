<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreIncassoRateRequest;
use App\Models\Condominio;
use App\Models\Anagrafica;
use App\Models\Immobile;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\IncassoRateService;
use App\Traits\HandleFlashMessages;
use App\Traits\HasEsercizio;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IncassoRateController extends Controller
{
    use HandleFlashMessages, HasEsercizio;

    public function __construct(
        private IncassoRateService $incassoService
    ) {}

    public function index(Request $request, Condominio $condominio)
    {
        $query = $this->incassoService->getIncassiQuery(
            $condominio,
            $request->input('search')
        );

        $movimenti = $query->paginate(config('pagination.default_per_page'))
            ->withQueryString()
            ->through(fn($mov) => $this->incassoService->formatMovimentoForFrontend($mov));

        $condominiList = Anagrafica::whereHas('immobili', fn($q) => 
            $q->where('condominio_id', $condominio->id)
        )->orderBy('nome')->get();
        
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/movimenti/incassi/IncassoRateList', [
            'condominio' => $condominio,
            'movimenti'  => $movimenti,
            'condomini'  => $condominiList,
            'esercizio'  => $esercizio,
            'filters'    => $request->all(['search']),
        ]);
    }

    public function create(Condominio $condominio)
    {
        $risorse = Cassa::where('condominio_id', $condominio->id)
            ->whereIn('tipo', ['banca', 'contanti'])
            ->where('attiva', true)
            ->with('contoCorrente')
            ->get();

        $condomini = Anagrafica::whereHas('immobili', fn($q) => $q->where('condominio_id', $condominio->id))
            ->orderBy('nome')->get()->map(fn($a) => ['id' => $a->id, 'label' => $a->nome]);

        $immobili = Immobile::where('condominio_id', $condominio->id)
            ->orderBy('interno')->get()
            ->map(fn($i) => ['id' => $i->id, 'label' => "Int. $i->interno" . ($i->descrizione ? " - $i->descrizione" : "") . " ($i->nome)"]);

        $esercizio = $this->getEsercizioCorrente($condominio);
        
        $gestioni = $esercizio 
            ? $esercizio->gestioni()->select('gestioni.id', 'gestioni.nome', 'gestioni.tipo')->orderBy('gestioni.tipo')->get() 
            : [];

        return Inertia::render('gestionale/movimenti/incassi/IncassoRateNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'risorse'    => $risorse,
            'condomini'  => $condomini,
            'immobili'   => $immobili,
            'gestioni'   => $gestioni,
        ]);
    }

    public function store(StoreIncassoRateRequest $request, Condominio $condominio, StoreIncassoRateAction $action) 
    {
        $action->execute($request->validated(), $condominio, $this->getEsercizioCorrente($condominio));

        return to_route('admin.gestionale.movimenti-rate.index', $condominio)
            ->with($this->flashSuccess('Incasso registrato correttamente.'));
    }
    
    public function storno(Request $request, Condominio $condominio, ScritturaContabile $scrittura, StornoIncassoRateAction $action) 
    {
        if ($scrittura->stato === 'annullata') {
            return back();
        }

        $action->execute($scrittura, $condominio);

        return back()->with($this->flashSuccess('Storno completato.'));
    }
}