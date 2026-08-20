<?php

namespace App\Http\Controllers\Gestionale\PianiRate;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\BudgetMovementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BudgetMovementController extends Controller
{
    public function __construct(
        protected BudgetMovementService $budgetService
    ) {}

    /**
     * Esegue lo spostamento di budget.
     * Route: admin.gestionale.piani-rate.move-budget
     */
    public function store(Request $request, Condominio $condominio, PianoRate $pianoRate)
    {
        // ⚠️ Il binding implicito risolve `{pianoRate}` per id, senza guardare il condominio
        // dell'indirizzo: niente lega i due parametri fra loro. Guardia a mano, come in
        // `CassaController` e `TabellaQuotaController`. Vedi la coda ㊷ in `docs/roadmap.md`.
        abort_unless($pianoRate->condominio_id === $condominio->id, 404);

        // 1. Validazione
        //
        // ⚠️ `exists:conti,id` **senza perimetro** accettava una voce di spesa di un altro
        // condominio: i due id arrivano dal **corpo** della richiesta, quindi non li chiude né il
        // binding di rotta né `scopeBindings()`. Il controllo al punto 3 qui sotto pretende solo
        // che sorgente e destinazione stiano nello **stesso** piano dei conti — che potevano
        // essere entrambe di un piano estraneo. Ora le due regole sono ambitate ai piani dei conti
        // di questo condominio.
        $contiDelCondominio = Rule::exists('conti', 'id')->whereIn(
            'piano_conto_id',
            PianoConto::query()->where('condominio_id', $condominio->id)->pluck('id')->all(),
        );

        $validated = $request->validate([
            'source_id' => ['required', $contiDelCondominio],
            'destination_id' => ['required', 'different:source_id', $contiDelCondominio],
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
        ]);

        try {
            // 2. Conversione sicura in centesimi (Money Pattern)
            $amountCents = MoneyHelper::toCents($validated['amount']);
            
            $userId = Auth::id();

            // 3. Verifica Coerenza Piano dei Conti (Security)
            $source = Conto::findOrFail($validated['source_id']);
            $dest = Conto::findOrFail($validated['destination_id']);

            if ($source->piano_conto_id !== $dest->piano_conto_id) {
                 return back()->withErrors(['destination_id' => 'Sorgente e Destinazione devono appartenere allo stesso Piano dei Conti.']);
            }

            // 4. Esecuzione tramite Service
            $this->budgetService->moveBudget(
                $pianoRate,
                $validated['source_id'],
                $validated['destination_id'],
                $amountCents,
                $validated['reason'],
                $userId
            );

            return back()->with('success', 'Budget riallocato con successo.');

        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (\Exception $e) {
            Log::error("Budget Movement Error: " . $e->getMessage());
            return back()->withErrors(['amount' => 'Errore di sistema: ' . $e->getMessage()]);
        }
    }
}