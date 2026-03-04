<?php

namespace App\Http\Controllers\Gestionale\Saldi;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Saldi\CreateSaldoRequest;
use App\Http\Requests\Gestionale\Saldi\UpdateSaldoRequest;
use App\Models\Condominio;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Traits\HasEsercizio;
use App\Traits\HandleFlashMessages;
use Inertia\Inertia;

class SaldoInizialeController extends Controller
{
    use HasEsercizio;
    use HandleFlashMessages;

    public function index(Condominio $condominio)
    {
        $esercizioAttivo = $this->getEsercizioCorrente($condominio);

        if (!$esercizioAttivo) {
            return back()->with($this->flashError('Nessun esercizio aperto trovato.'));
        }

        $immobili = $condominio->immobili()
            ->with([
                'anagrafiche',
                'saldi' => function ($q) use ($esercizioAttivo) {
                    $q->where('esercizio_id', $esercizioAttivo->id)
                      ->with(['gestione:id,nome,tipo', 'anagrafica:id,nome']);
                },
                'palazzina',
                'scala',
            ])
            ->get();

        $gestioni = Gestione::where('condominio_id', $condominio->id)
            ->where('attiva', true)
            ->get(['id', 'nome', 'tipo']);

        return Inertia::render('gestionale/saldi/SaldiList', [
            'condominio' => $condominio,
            'esercizio'  => $esercizioAttivo,
            'immobili'   => $immobili,
            'gestioni'   => $gestioni,
        ]);
    }

    public function store(CreateSaldoRequest $request, Condominio $condominio)
    {
        $validated = $request->validated();

        $esercizioAttivo = $this->getEsercizioCorrente($condominio);

        abort_unless(
            $condominio->immobili()->where('id', $validated['immobile_id'])->exists(),
            403, 'Immobile non appartiene a questo condominio.'
        );
        abort_unless(
            $condominio->gestioni()->where('id', $validated['gestione_id'])->exists(),
            403, 'Gestione non appartiene a questo condominio.'
        );

        Saldo::create([
            'esercizio_id'   => $esercizioAttivo->id,
            'condominio_id'  => $condominio->id,
            'immobile_id'    => $validated['immobile_id'],
            'anagrafica_id'  => $validated['anagrafica_id'] ?? null,
            'gestione_id'    => $validated['gestione_id'],
            'saldo_iniziale' => $validated['saldo_iniziale'],
            'origine'        => 'manuale',
        ]);

        return back()->with($this->flashSuccess('Saldo aggiunto al Wallet con successo.'));
    }

    public function update(UpdateSaldoRequest $request, Condominio $condominio, Saldo $saldo)
    {
        // Ownership, muro contabile e appartenenza gestione
        // sono già verificati in UpdateSaldoRequest::after()
        $saldo->update($request->validated());

        return back()->with($this->flashSuccess('Saldo aggiornato.'));
    }

    public function destroy(Condominio $condominio, Saldo $saldo)
    {
        abort_unless($saldo->condominio_id === $condominio->id, 403);
        abort_if($saldo->is_applicato, 403, 'Non puoi eliminare un saldo già applicato a un piano rate.');

        $saldo->delete();

        return back()->with($this->flashSuccess('Saldo rimosso dal Wallet.'));
    }
}