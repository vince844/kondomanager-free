<?php

namespace App\Http\Controllers\Gestionale\Immobili\Anagrafiche;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Immobile\Anagrafica\CreateImmobileAnagraficaRequest;
use App\Http\Requests\Gestionale\Immobile\Anagrafica\UpdateImmobileAnagraficaRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Gestionale\Immobili\ImmobileResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Traits\HandleFlashMessages;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Controller for managing the association of "Anagrafica" records
 * with an "Immobile" in the Gestionale module.
 *
 * Responsibilities:
 * - List all anagrafiche linked to an immobile
 * - Create new associations
 * - Update pivot data (tipologia, quota, dates, note)
 * - Prevent duplicates
 * - Enforce business rules (e.g., quotas per tipologia)
 * - Remove associations
 *
 * @package App\Http\Controllers\Gestionale\Immobili\Anagrafiche
 */
class ImmobileAnagraficaController extends Controller
{
    use HandleFlashMessages, HasEsercizio;
    
    /**
     * Display a listing of the resource.
     *
     * @param Condominio $condominio
     * @param Immobile $immobile
     * @return Response
     */
    public function index(Condominio $condominio, Immobile $immobile): Response
    {
        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);
    
        $immobile->loadMissing(['anagrafiche.saldi' => function($query) use ($immobile, $esercizio) {
            $query->where('immobile_id', $immobile->id)
                ->where('esercizio_id', $esercizio->id)
                ->with('esercizio');
        }]);

        // ⚠️ **Chi ha già quote emesse su questa unità.**
        //
        // «Dissocia» faceva un `detach()` dietro una conferma generica — «questa azione non è
        // reversibile» — che non diceva la cosa che conta: se quel soggetto ha già rate emesse,
        // le sue quote restano in `rate_quote` mentre lui sparisce dalla pivot, e i documenti
        // cominciano a raccontare due storie diverse.
        //
        // Non è un caso di laboratorio: **è il rimedio che gli amministratori usano oggi per il
        // subentro**, perché il motore non legge le date di competenza. Genero le rate, stacco il
        // vecchio proprietario, ristampo. È lo stesso scenario che ha prodotto il difetto A6
        // chiuso in questa beta, e finché il subentro vero non esiste (blocco B2, 1.11) la strada
        // resta praticata: tanto vale dire all'amministratore cosa comporta, invece di lasciarlo
        // scoprire dal riparto.
        //
        // Si guarda `rate_quote` e non i saldi: è lì che vive la quota emessa, ed è la tabella
        // che il documento di riparto legge.
        $anagraficheConQuoteEmesse = DB::table('rate_quote')
            ->where('immobile_id', $immobile->id)
            ->whereNotNull('anagrafica_id')
            ->distinct()
            ->pluck('anagrafica_id')
            ->all();

        return Inertia::render('gestionale/immobili/anagrafiche/AnagraficheList', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'immobile'   => new ImmobileResource($immobile),
            'anagraficheConQuoteEmesse' => $anagraficheConQuoteEmesse,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @param Condominio $condominio
     * @param Immobile $immobile
     * @return Response
     */
    public function create(Condominio $condominio, Immobile $immobile): Response
    {
        $esercizio = $this->getEsercizioCorrente($condominio);

        // Recupera gli ID delle anagrafiche GIA' associate a questo immobile
        // per escluderle dalla lista (evita duplicati)
        $idsGiaAssociati = $immobile->anagrafiche()->pluck('anagrafiche.id');

        // Filtra le anagrafiche:
        // 1. Devono appartenere al Condominio (contesto)
        // 2. NON devono essere già nell'Immobile (evita doppi inserimenti)
        $anagraficheDisponibili = $condominio->anagrafiche()
            ->whereNotIn('anagrafiche.id', $idsGiaAssociati)
            ->orderBy('nome') // Opzionale: per averle in ordine alfabetico
            ->get();

        return Inertia::render('gestionale/immobili/anagrafiche/AnagraficheNew', [
            'condominio'  => $condominio,
            'esercizio'   => $esercizio,
            'immobile'    => $immobile,
            // Passiamo solo quelle filtrate
            'anagrafiche' => AnagraficaResource::collection($anagraficheDisponibili)
        ]);
    }

    /**
     * Store a newly created association in storage.
     *
     * @param CreateImmobileAnagraficaRequest $request
     * @param Condominio $condominio
     * @param Immobile $immobile
     * @return RedirectResponse
     */
    public function store(CreateImmobileAnagraficaRequest $request, Condominio $condominio, Immobile $immobile): RedirectResponse
    {
        $data = $request->validated();

        try {

            // Assegnare un'unità a una persona la rende **condòmina di questo stabile**, e il
            // pivot `anagrafica_condominio` è dove quel fatto vive: lo leggono le altre parti
            // del gestionale per sapere chi appartiene al condominio.
            //
            // Fino alla beta.48 questa riga non c'era, e `anagrafica_id` è validato **senza**
            // filtro sul condominio (`CreateImmobileAnagraficaRequest:38`): si poteva quindi
            // assegnare un'unità a chiunque, ottenendo un proprietario che il gestionale non
            // considerava del condominio. Sull'incasso questo si traduceva in un pagante
            // rifiutato — vedi la coda ⑫ in roadmap, e `PaganteDelCondominioTest`.
            //
            // `syncWithoutDetaching`: chi possiede unità in più stabili non deve perderli, e
            // un secondo collegamento non deve produrre una riga doppia.
            Anagrafica::find($data['anagrafica_id'])
                ?->condomini()
                ->syncWithoutDetaching([$condominio->id]);

            // ⚠️ `tipologie_spese` non viene più scritta (beta.50). Era presa da `validated()`
            // ma **nessuna FormRequest la valida**, quindi arrivava sempre `null`: verificato,
            // 0 righe valorizzate su 60. Nessun calcolo la legge. La colonna resta finché non
            // cade con le altre migrazioni della 1.11; la `Resource` continua a esporla — oggi
            // restituisce `null` come sempre, e toglierla cambierebbe ciò che il frontend riceve.
            $immobile->anagrafiche()->attach($data['anagrafica_id'], [
                'tipologia'       => $data['tipologia'],
                'quota'           => $data['quota'],
                'data_inizio'     => $data['data_inizio'],
                'data_fine'       => $data['data_fine'] ?? null,
                'attivo'          => true,
                'note'            => $data['note'] ?? null,
            ]);

           return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashSuccess(__('gestionale.success_attach_anagrafica')));

        } catch (\Throwable $e) {

            Log::error('Error attaching anagrafica to immobile', [
                'immobile_id'   => $immobile->id,
                'anagrafica_id' => $data['anagrafica_id'],
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashError(__('gestionale.error_attach_anagrafica')));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // Not implemented. Could return a detailed view.
    }

    /**
     * Show the form for editing the specified association.
     *
     * @param Condominio $condominio
     * @param Immobile $immobile
     * @param Anagrafica $anagrafica
     * @return Response
     */
    public function edit(Condominio $condominio, Immobile $immobile, Anagrafica $anagrafica): Response
    {
        $anagraficaPivot = $immobile->anagrafiche()->where('anagrafica_id', $anagrafica->id)->first();
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/immobili/anagrafiche/AnagraficheEdit', [
            'condominio'    => $condominio,
            'esercizio'     => $esercizio,
            'immobile'      => new ImmobileResource($immobile),
            'anagrafiche'   => AnagraficaResource::collection(Anagrafica::all()),
            'anagrafica'    => $anagraficaPivot,
        ]);
    }

    /**
     * Update the specified association in storage.
     *
     * @param UpdateImmobileAnagraficaRequest $request
     * @param Condominio $condominio
     * @param Immobile $immobile
     * @param Anagrafica $anagrafica (L'anagrafica attualmente associata prima della modifica)
     * @return RedirectResponse
     */
    public function update(UpdateImmobileAnagraficaRequest $request, Condominio $condominio, Immobile $immobile, Anagrafica $anagrafica): RedirectResponse
    {
        $data = $request->validated();
        
        try {
            DB::beginTransaction();

            $nuovoAnagraficaId = (int) $data['anagrafica_id'];
            $vecchioAnagraficaId = (int) $anagrafica->id;
            $anagraficaCambiata = $nuovoAnagraficaId !== $vecchioAnagraficaId;

            // 1. GESTIONE ASSOCIAZIONE (PIVOT)
            if ($anagraficaCambiata) {
                // L'utente ha selezionato una persona diversa: scollega la vecchia, collega la nuova
                $immobile->anagrafiche()->detach($vecchioAnagraficaId);

                $immobile->anagrafiche()->attach($nuovoAnagraficaId, [
                    'tipologia'       => $data['tipologia'],
                    'quota'           => $data['quota'],
                    'data_inizio'     => $data['data_inizio'],
                    'data_fine'       => $data['data_fine'] ?? null,
                    'note'            => $data['note'] ?? null,
                    'attivo'          => true,
                ]);
            } else {
                // Stessa persona: aggiorna solo i dati
                $immobile->anagrafiche()->updateExistingPivot($vecchioAnagraficaId, [
                    'tipologia'       => $data['tipologia'],
                    'quota'           => $data['quota'],
                    'data_inizio'     => $data['data_inizio'],
                    'data_fine'       => $data['data_fine'] ?? null,
                    'note'            => $data['note'] ?? null,
                ]);
            }

            DB::commit();

            return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashSuccess(__('gestionale.success_update_anagrafica')));

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Error updating anagrafica for immobile', [
                'immobile_id'       => $immobile->id,
                'old_anagrafica_id' => $anagrafica->id,
                'new_anagrafica_id' => $data['anagrafica_id'] ?? null,
                'message'           => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashError(__('gestionale.error_update_anagrafica')));
        }
    }

    /**
     * Remove the specified association from storage.
     *
     * @param Condominio $condominio
     * @param Immobile $immobile
     * @param Anagrafica $anagrafica
     * @return RedirectResponse
     */
    public function destroy(Condominio $condominio, Immobile $immobile, Anagrafica $anagrafica): RedirectResponse
    {
        
        try {
            // Detach the anagrafica from the immobile
            $immobile->anagrafiche()->detach($anagrafica->id);

            return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashSuccess(__('gestionale.success_detach_anagrafica')));

        } catch (\Throwable $e) {

            Log::error('Error detaching anagrafica from immobile', [
                'immobile_id'   => $immobile->id,
                'anagrafica_id' => $anagrafica->id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashError(__('gestionale.error_detach_anagrafica')));
        }
    }   

}
