<?php

namespace App\Http\Controllers\Fornitori;

use App\Http\Controllers\Controller;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Fornitore;
use App\Models\Saldo;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FornitoreSituazioneDebitoriaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Fornitore $fornitore)
    {
        // Recuperiamo tutti i "saldi" (passività) legati a questo fornitore
        $situazioneDebitoria = Saldo::where('fornitore_id', $fornitore->id)
            ->with(['condominio', 'esercizio'])
            ->get()
            ->map(function ($saldo) {
                return [
                    'id'            => $saldo->id,
                    'condominio'    => $saldo->condominio->nome,
                    'esercizio'     => $saldo->esercizio->nome,
                    'descrizione'   => $saldo->descrizione,
                    'importo'       => abs($saldo->saldo_iniziale), // Mostriamo il debito come valore positivo in UI
                    'data_inserimento' => $saldo->created_at->format('d/m/Y'),
                ];
            });

        return Inertia::render('fornitori/FornitoreSituazioneDebitoria', [
            'fornitore' => $fornitore,
            'debiti'    => $situazioneDebitoria,
            // Ci serve la lista condomini per il form di aggiunta
            'condomini' => Condominio::select('id', 'nome')->orderBy('nome')->get(),
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
    public function store(Request $request, Fornitore $fornitore)
    {
        // ⚠️ **`importo` arriva MASCHERATO, e con `numeric` non passava mai.**
        // La modale usa `MoneyInput` con `masked: true`, quindi il campo vale «380,00» —
        // punto per le migliaia, virgola per i decimali. `is_numeric('380,00')` è **false**
        // in PHP: la validazione falliva a ogni tentativo, e la modale non ha un posto dove
        // mostrare l'errore (nessun `InputError`), quindi il modulo rifiutava **in silenzio**
        // qualunque importo. Il caricamento dei debiti pregressi era di fatto inutilizzabile,
        // e con lui il percorso delle fatture pregresse, che questa pagina dichiara di
        // autorizzare. Corretto nella beta.75, insieme agli avvisi lato modale.
        //
        // La conversione la fa `MoneyHelper::toCents()`, che è il **confine di ingresso**
        // del progetto e sa leggere sia la stringa mascherata sia un numero puro. Una volta
        // sola: da qui in poi si ragiona in centesimi.
        $validated = $request->validate([
            'condominio_id' => 'required|exists:condomini,id',
            'descrizione'   => 'required|string|max:255',
            'importo'       => 'required',
        ]);

        $importoCents = MoneyHelper::toCents($validated['importo']);

        if ($importoCents <= 0) {
            return back()->withErrors([
                'importo' => 'Inserisci un importo maggiore di zero.',
            ])->withInput();
        }

        // 2. Troviamo l'esercizio APERTO per quel condominio
        // Senza un esercizio, non possiamo legare il debito allo Stato Patrimoniale Iniziale
        $condominio = Condominio::findOrFail($validated['condominio_id']);
        $esercizio = $condominio->esercizi()->where('stato', 'aperto')->orderBy('data_inizio', 'desc')->first();

        if (!$esercizio) {
            return back()->with('message', [
                'type' => 'error',
                'message' => "Impossibile salvare: il condominio {$condominio->nome} non ha un esercizio contabile aperto."
            ]);
        }

        // 3. Troviamo la gestione 'ordinaria' di default per l'esercizio
        // Spesso i saldi iniziali vengono caricati sulla gestione principale
        $gestione = $esercizio->gestioni()->where('tipo', 'ordinaria')->first() 
                    ?? $esercizio->gestioni()->first();

        // 4. Trasformazione Contabile: il segno. L'importo è già in centesimi (vedi sopra):
        // qui si inverte soltanto, perché un debito verso fornitore è una passività.
        $saldoIniziale = $importoCents * -1;

        // 5. Salvataggio nella tabella Saldi
        Saldo::create([
            'condominio_id'  => $condominio->id,
            'esercizio_id'   => $esercizio->id,
            'gestione_id'    => $gestione?->id,
            'fornitore_id'   => $fornitore->id,
            'anagrafica_id'  => null, // Fondamentale: NULL identifica un debito vs terzi
            'immobile_id'    => null,
            'saldo_iniziale' => $saldoIniziale,
            'descrizione'    => $validated['descrizione'],
            'origine'        => 'manuale',
            'is_applicato'   => true,
        ]);

        return redirect()->route('admin.fornitori.situazione-debitoria.index', $fornitore->id)
            ->with('message', [
                'type' => 'success',
                'message' => 'Debito pregresso registrato con successo. Ora è disponibile per la copertura fatture.'
            ]);
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
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Fornitore $fornitore, Saldo $saldo)
    {
        // Sicurezza: verifichiamo che il saldo appartenga effettivamente a questo fornitore
        if ($saldo->fornitore_id !== $fornitore->id) {
            return back()->with('message', [
                'type' => 'error',
                'message' => 'Operazione non autorizzata.'
            ]);
        }

        // Eliminiamo il record
        $saldo->delete();

        return back()->with('message', [
            'type' => 'success',
            'message' => 'Pendenza rimossa correttamente dallo Stato Patrimoniale.'
        ]);
    }
}
