<?php

namespace App\Http\Controllers\Gestionale\Saldi;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Traits\HasEsercizio;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SaldoInizialeController extends Controller
{
    use HasEsercizio; 

    /**
     * Display a listing of the resource.
     */
    public function index(Condominio $condominio)
    {
        $esercizioAttivo = $this->getEsercizioCorrente($condominio); 

        if (!$esercizioAttivo) {
            return back()->with('error', 'Nessun esercizio aperto trovato.');
        }

        $immobili = $condominio->immobili()
            ->with([
                'anagrafiche', // Pivot per sapere chi vive lì
                'saldi' => function($q) use ($esercizioAttivo) {
                    $q->where('esercizio_id', $esercizioAttivo->id)
                      ->with(['gestione:id,nome,tipo', 'anagrafica:id,nome']); 
                }, 
                'palazzina', 
                'scala'
            ])
            ->get();

        $gestioni = Gestione::where('condominio_id', $condominio->id)
            ->where('attiva', true)
            ->get(['id', 'nome', 'tipo']);

        return Inertia::render('gestionale/saldi/SaldiList', [
            'condominio' => $condominio,
            'esercizio' => $esercizioAttivo,
            'immobili' => $immobili,
            'gestioni' => $gestioni,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, Condominio $condominio)
    {
        // 1. Validazione (Ora anagrafica_id accetta null)
        $validated = $request->validate([
            'anagrafica_id'  => 'nullable|exists:anagrafiche,id',
            'immobile_id'    => 'required|exists:immobili,id',
            'gestione_id'    => 'required|exists:gestioni,id',
            'saldo_iniziale' => 'required|numeric',
        ]);

        $esercizioAttivo = $this->getEsercizioCorrente($condominio);

        if (!$esercizioAttivo) {
            return back()->with('error', 'Impossibile salvare: nessun esercizio aperto.');
        }

        // 2. Prevenzione Crash SQL (Unique Constraint Interceptor)
        // Trasformiamo eventuale stringa vuota in null reale
        $anagraficaId = empty($validated['anagrafica_id']) ? null : $validated['anagrafica_id'];

        $queryDuplicate = Saldo::where('esercizio_id', $esercizioAttivo->id)
            ->where('immobile_id', $validated['immobile_id'])
            ->where('gestione_id', $validated['gestione_id']);

        if (is_null($anagraficaId)) {
            $queryDuplicate->whereNull('anagrafica_id');
            $errorMsg = 'Esiste già un debito/credito sull\'intero immobile per questa gestione. Modifica quello esistente.';
        } else {
            $queryDuplicate->where('anagrafica_id', $anagraficaId);
            $errorMsg = 'Esiste già un saldo per questa persona in questa gestione. Modifica quello esistente.';
        }

        if ($queryDuplicate->exists()) {
            return back()->withErrors(['anagrafica_id' => $errorMsg]);
        }

        // 3. Creazione sicura
        Saldo::create([
            'esercizio_id'   => $esercizioAttivo->id,
            'condominio_id'  => $condominio->id,
            'immobile_id'    => $validated['immobile_id'],
            'anagrafica_id'  => $anagraficaId,
            'gestione_id'    => $validated['gestione_id'],
            'saldo_iniziale' => $validated['saldo_iniziale'],
            'origine'        => 'manuale',
        ]);

        return back()->with('message', 'Saldo aggiunto al Wallet con successo.');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Condominio $condominio, $id)
    {
        $saldo = Saldo::findOrFail($id);
        
        // Muro Contabile
        if ($saldo->is_applicato) {
            return back()->withErrors(['error' => 'Non puoi modificare un saldo già incluso in un piano rate emesso.']);
        }

        $validated = $request->validate([
            'saldo_iniziale' => 'required|numeric',
            'gestione_id'   => 'required|exists:gestioni,id',
        ]);

        $saldo->update($validated);

        return back()->with('message', 'Saldo aggiornato.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Condominio $condominio, $id)
    {
        $saldo = Saldo::findOrFail($id);
        
        // Muro Contabile
        if ($saldo->is_applicato) {
            return back()->withErrors(['error' => 'Non puoi eliminare un saldo già applicato.']);
        }

        $saldo->delete();
        return back()->with('message', 'Saldo rimosso dal Wallet.');
    }
}