<?php

namespace App\Http\Controllers\Gestionale\Immobili\Anagrafiche;

use App\Helpers\DateHelper;
use App\Models\Gestionale\Subentro;
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
use App\Models\TitolaritaImmobile;
use App\Services\Subentro\StoricoTitolarita;
use App\Traits\HandleFlashMessages;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        // **La tabella mostra solo i titolari attuali** (§6.5 di `pertinenze_vendita_locazione.md`,
        // livello 1): un periodo chiuso nella stessa tabella degli attivi, con un suffisso «(ex)», è
        // il modo più veloce per intestare una rata a chi ha venduto. Lo storico — tutte le righe,
        // come frasi — va nel pannello «Chi ha avuto questa unità» (`StoricoTitolarita`).
        $oggi = DateHelper::oggiUtenteImmutable();
        $storico = app(StoricoTitolarita::class)->perImmobile($immobile);
        // Ordine fisso (§6.6): prima chi risponde verso il condominio — proprietario, nudo proprietario,
        // usufruttuario — poi gli occupanti; a parità, per nome.
        $ordine = ['proprietario' => 0, 'nuda_proprietario' => 1, 'usufruttuario' => 2, 'inquilino' => 3];
        $immobile->setRelation('anagrafiche', $immobile->anagrafiche
            ->filter(fn ($a) => $a->pivot->inCorsoIl($oggi))
            ->sortBy([
                fn ($a, $b) => ($ordine[$a->pivot->tipologia] ?? 9) <=> ($ordine[$b->pivot->tipologia] ?? 9),
                fn ($a, $b) => strcmp((string) $a->nome, (string) $b->nome),
            ])
            ->values());
        // Decisione 24: quali righe fanno parte di un passaggio registrato (il ruolo non si cambia) — una query.
        $passaggi = Subentro::where('immobile_id', $immobile->id)->get(['riga_uscente_id', 'riga_entrante_id', 'tipologia', 'decorrenza', 'tipo_passaggio']);
        $agganciate = $passaggi->flatMap(fn ($s) => [$s->riga_uscente_id, $s->riga_entrante_id])->filter()->map(fn ($id) => (int) $id)->flip()->all();
        $triple = $passaggi->where('tipo_passaggio', 'usufrutto')->map(fn ($s) => $s->tipologia . '|' . $s->decorrenza->toDateString())->flip()->all();
        $immobile->anagrafiche->each(fn ($a) => $a->pivot->setAttribute('agganciata_a_passaggio',
            isset($agganciate[(int) $a->pivot->id]) || ($a->pivot->data_inizio !== null && isset($triple[$a->pivot->tipologia . '|' . $a->pivot->data_inizio->toDateString()]))));

        return Inertia::render('gestionale/immobili/anagrafiche/AnagraficheList', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'immobile'   => new ImmobileResource($immobile),
            'anagraficheConQuoteEmesse' => $anagraficheConQuoteEmesse,
            'storico' => $storico,
            'oggi' => $oggi->toDateString(),
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

        // Le anagrafiche con una titolarità **in corso oggi** su questo immobile: sono già qui, e si escludono
        // dalla lista. Chi ha solo periodi chiusi torna selezionabile (S8-bis, L3-2): la stessa persona può avere
        // due periodi sulla stessa unità (inv. 12) — chi vende e ricompra, chi vende la nuda proprietà e resta
        // usufruttuario — e senza questo la strada «chiudi e riapri con un altro ruolo» non si poteva percorrere.
        $oggi = DateHelper::oggiUtenteImmutable();
        $idsGiaAssociati = $immobile->anagrafiche()->get()->filter(fn ($a) => $a->pivot->inCorsoIl($oggi))->pluck('id')->unique()->values();

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
    public function edit(Condominio $condominio, Immobile $immobile, TitolaritaImmobile $titolarita): Response
    {
        // Lavora **per periodo** dalla 1.11.0-beta.31: la rotta riceve l'`id` della riga (`{titolarita}`),
        // e la stessa persona con due periodi sulla stessa unità apre quello indicato, non il primo che
        // la query restituisce. Fino alla beta.30 riceveva la persona e faceva `where('anagrafica_id')`.
        $this->appartieneAllUnita($titolarita, $immobile);
        $anagraficaPivot = $immobile->anagrafiche()->wherePivot('id', $titolarita->id)->first();
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/immobili/anagrafiche/AnagraficheEdit', [
            'condominio'    => $condominio,
            'esercizio'     => $esercizio,
            'immobile'      => new ImmobileResource($immobile),
            'anagrafiche'   => AnagraficaResource::collection(Anagrafica::all()),
            'anagrafica'    => $anagraficaPivot,
            // Decisione 24: la riga fa parte di un passaggio registrato → il ruolo non si cambia da qui.
            'agganciata_a_passaggio' => $titolarita->faParteDiUnPassaggio(),
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
    public function update(UpdateImmobileAnagraficaRequest $request, Condominio $condominio, Immobile $immobile, TitolaritaImmobile $titolarita): RedirectResponse
    {
        $this->appartieneAllUnita($titolarita, $immobile);
        $data = $request->validated();

        $nuovoAnagraficaId = (int) $data['anagrafica_id'];
        $vecchioAnagraficaId = (int) $titolarita->anagrafica_id;
        $anagraficaCambiata = $nuovoAnagraficaId !== $vecchioAnagraficaId;

        // Decisione 13: cambiare la persona su una riga con storia è un passaggio travestito da
        // correzione, e il vecchio `detach()` + `attach()` cancellava il periodo. Correggere quota,
        // date o note sulla **stessa** persona resta «Modifica associazione»: passa.
        if ($anagraficaCambiata && $titolarita->haStoria()) {
            throw ValidationException::withMessages([
                'anagrafica_id' => 'Questa riga ha una storia — un periodo chiuso o un passaggio registrato — e la persona non si sostituisce: se il titolare è cambiato, usa «Registra passaggio». Qui puoi correggere ruolo, quota, date e note.',
            ]);
        }

        // Decisione 24 (S8-11): il ruolo di una riga agganciata a un passaggio registrato (`subentri`, come uscente o
        // entrante) è la chiave con cui il motore ritrova chi c'era prima (D7: il predecessore è sulla stessa
        // tipologia) e con cui il passaggio dice cosa è passato: cambiarlo scollegherebbe il passaggio in silenzio.
        $ruoloCambiato = (string) $data['tipologia'] !== (string) $titolarita->tipologia;
        if ($ruoloCambiato && $titolarita->faParteDiUnPassaggio()) {
            throw ValidationException::withMessages([
                'tipologia' => 'Questa riga fa parte di un passaggio registrato e il suo ruolo non si cambia da qui: è la chiave con cui il programma lega il passaggio a chi c\'era prima e dopo. Qui puoi correggere quota, date e note. Un passaggio registrato oggi non si annulla dal programma (arriva con la prossima versione): se il tipo era sbagliato, chiudi questa riga con una data di fine e registra da «Associa soggetto» la titolarità giusta.',
            ]);
        }

        try {
            DB::beginTransaction();

            // Per **riga** (`wherePivot('id')`), non per persona: con due periodi della stessa persona
            // sulla stessa unità si tocca solo quello aperto dall'interfaccia (decisione 13).
            if ($anagraficaCambiata) {
                // Persona diversa su una riga **senza** storia: la riga era un errore di battitura, e si
                // riscrive. Non è un subentro — quello passa da «Registra passaggio» e conserva il periodo.
                $immobile->anagrafiche()->wherePivot('id', $titolarita->id)->detach($vecchioAnagraficaId);

                $immobile->anagrafiche()->attach($nuovoAnagraficaId, [
                    'tipologia'       => $data['tipologia'],
                    'quota'           => $data['quota'],
                    'data_inizio'     => $data['data_inizio'],
                    'data_fine'       => $data['data_fine'] ?? null,
                    'note'            => $data['note'] ?? null,
                    'attivo'          => true,
                ]);
            } else {
                $immobile->anagrafiche()->wherePivot('id', $titolarita->id)->updateExistingPivot($vecchioAnagraficaId, [
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
                'titolarita_id'     => $titolarita->id,
                'old_anagrafica_id' => $titolarita->anagrafica_id,
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
    public function destroy(Condominio $condominio, Immobile $immobile, TitolaritaImmobile $titolarita): RedirectResponse
    {
        $this->appartieneAllUnita($titolarita, $immobile);

        // Decisione 13: una riga con storia si chiude, non si cancella. `ValidationException` e non
        // `abort(422)`: dal browser Inertia la porta nel dialogo come errore di campo, dai test JSON
        // è un 422 con il messaggio.
        if ($titolarita->haStoria()) {
            throw ValidationException::withMessages([
                'titolarita' => 'Questa riga ha una storia — un periodo chiuso o un passaggio registrato — e non si cancella: il riparto e l\'estratto conto l\'hanno già raccontata. Per un cambio di titolare usa «Registra passaggio»; «Dissocia» resta per le associazioni scritte per sbaglio.',
            ]);
        }

        try {
            // Per riga, non per persona: `detach($anagraficaId)` da solo cancellerebbe tutti i periodi
            // di questa persona sull'unità.
            $immobile->anagrafiche()->wherePivot('id', $titolarita->id)->detach($titolarita->anagrafica_id);

            return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashSuccess(__('gestionale.success_detach_anagrafica')));

        } catch (\Throwable $e) {

            Log::error('Error detaching anagrafica from immobile', [
                'immobile_id'   => $immobile->id,
                'titolarita_id' => $titolarita->id,
                'anagrafica_id' => $titolarita->anagrafica_id,
                'message'       => $e->getMessage(),
                'trace'         => $e->getTraceAsString(),
            ]);

            return to_route('admin.gestionale.immobili.anagrafiche.index', [
                'condominio' => $condominio->id,
                'immobile'   => $immobile->id,
            ])->with($this->flashError(__('gestionale.error_detach_anagrafica')));
        }
    }

    /**
     * L'`id` della riga arriva dall'indirizzo, e l'indirizzo dice anche di quale unità si parla: se non
     * combaciano è un 404, non un aggiornamento su un'unità che non è quella a schermo. Lo fa già il
     * binding annidato del gruppo (`scopeBindings()` + `Immobile::relazioniDeiFigliNelleRotte()`); qui
     * si ripete perché un controller non deve fidarsi di come è montato.
     */
    private function appartieneAllUnita(TitolaritaImmobile $titolarita, Immobile $immobile): void
    {
        abort_unless((int) $titolarita->immobile_id === (int) $immobile->id, 404);
    }
}
