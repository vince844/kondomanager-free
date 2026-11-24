<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Casse;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Casse\CassaIndexRequest;
use App\Http\Requests\Gestionale\Casse\CreateCassaRequest;
use App\Http\Resources\Gestionale\Casse\CassaResource;
use App\Actions\Cassa\CreateCassaAction;
use App\Actions\Cassa\RegistraAperturaCassaAction;
use App\Actions\Cassa\UpdateCassaAction;
use App\Helpers\MoneyHelper;
use App\Http\Requests\Gestionale\Casse\UpdateCassaRequest;
use App\Http\Resources\Gestionale\Casse\UpdateCassaResource;
use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use App\Traits\HandleFlashMessages;
use App\Traits\OrdinaElenco;
use App\Traits\PaginaElenco;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CassaController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio, OrdinaElenco, PaginaElenco;

    public function __construct(
        private CreateCassaAction $createCassaAction,
        private UpdateCassaAction $updateCassaAction,
        private RegistraAperturaCassaAction $aperturaAction
    ) {}

    /**
     * Porta a giornale il saldo di apertura di una cassa che ce l'ha in colonna.
     *
     * L'azione esisteva già, era transazionale e idempotente, e aveva **un solo chiamante**:
     * la creazione della cassa. Dalla diagnosi dello Stato Patrimoniale non c'era modo di
     * invocarla — il widget nominava la causa dello sbilancio e linkava una pagina dove
     * l'unico pulsante presente passa da `UpdateCassaAction`, che l'apertura non la chiamava
     * mai. Diagnosi senza cura.
     *
     * L'esito è **tipizzato** e i suoi sei casi si dividono in tre risposte diverse: fatto,
     * non c'era niente da fare, manca qualcosa e ti dico cosa. Un unico «non è stato
     * possibile» avrebbe spostato il vicolo cieco di un passo invece di toglierlo.
     */
    public function registraApertura(Condominio $condominio, Cassa $cassa): RedirectResponse
    {
        abort_unless($cassa->condominio_id === $condominio->id, 403);

        $esito = $this->aperturaAction->execute($cassa);

        if ($esito->riuscita()) {
            return back()->with($this->flashSuccess($esito->messaggio()));
        }

        // «Già a posto» non è un fallimento: presentarlo come tale insegnerebbe a diffidare
        // dei messaggi veri.
        return back()->with(
            $esito->giaAPosto()
                ? $this->flashInfo($esito->messaggio())
                : $this->flashError($esito->messaggio())
        );
    }

    /**
     * Display a listing of the resource.
     */
    public function index(CassaIndexRequest $request, Condominio $condominio): Response
    {
        $validated = $request->validated();

        // Le righe per pagina si risolvono qui, una volta: la scelta esplicita se c'è, altrimenti
        // quella che l'utente aveva già fatto su questo elenco, altrimenti le impostazioni generali.
        $validated['per_page'] = $this->righePerPagina($request);

        $query = $condominio
            ->casse()
            ->with(['contoCorrente'])
            ->when($validated['nome'] ?? false, function ($query, $name) {
                $query->where('nome', 'like', "%{$name}%");
            })
            // --- CALCOLO SALDO DINAMICO ---
            // Usiamo "totale_entrate" e "totale_uscite" perché la CassaResource cerca questi!
            ->withSum(['movimenti as totale_entrate' => function ($q) {
                $q->where('tipo_riga', 'dare');
            }], 'importo')
            ->withSum(['movimenti as totale_uscite' => function ($q) {
                $q->where('tipo_riga', 'avere');
            }], 'importo');

        $casse = $query->tap(fn ($q) => $this->ordina($q, $validated, CassaIndexRequest::colonneOrdinabili(), predefinita: 'nome', versoPredefinito: 'asc'))
            ->paginate($validated['per_page']);

        // Letta prima della Resource, che sostituisce gli elementi della pagina con le sue righe.
        $storniDelleAperture = (object) collect($casse->items())
            ->filter(fn (Cassa $c) => $c->motivoBloccoEliminazione() === null)
            ->mapWithKeys(fn (Cassa $c) => [$c->id => $c->fraseDelloStornoDellApertura()])
            ->filter()->all();

        return Inertia::render('gestionale/casse/CasseList', [
            'condominio' => $condominio,
            'esercizio'  => $this->getEsercizioCorrente($condominio),
            'condomini'  => $this->getCondomini(),
            // La Resource ora troverà i dati corretti
            'casse'      => CassaResource::collection($casse)->resolve(),
            'meta'       => [
                'current_page' => $casse->currentPage(),
                'last_page'    => $casse->lastPage(),
                'total'        => $casse->total(),
            ],
            'filters'    => $request->only(['nome']),
            // Coda 754 (1): per la finestra «Elimina» delle risorse che si possono eliminare con l'apertura a giornale, la frase dello storno.
            'storni_delle_aperture' => $storniDelleAperture,
            'sort'      => $validated['sort'] ?? null,
            'direction' => $validated['direction'] ?? null,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Condominio $condominio): Response
    {
        $condomini = $this->getCondomini();

        // Get the current active and open esercizio this is important to navigate gestioni menu
        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/casse/CasseNew', [
            'condominio' => $condominio,
            'esercizio'  => $esercizio,
            'condomini'  => $condomini,
        ] + $this->ilGiornoDelSaldo($condominio));
    }

    /**
     * Il campo «Saldo al» del modulo della cassa: il primo giorno dell'esercizio aperto (con due, il più recente: Coda 646, `HasEsercizio`); il minimo del
     * calendario è il primo giorno del più vecchio degli aperti, che accetta ancora registrazioni: mai dentro un esercizio chiuso (il rifiuto vero è del
     * server, `RegistraAperturaCassaAction`). Nulli se nessun esercizio è aperto.
     *
     * @return array{saldo_al_predefinito: ?string, saldo_al_minimo: ?string}
     */
    private function ilGiornoDelSaldo(Condominio $condominio): array
    {
        $primo = $condominio->esercizi()->where('stato', 'aperto')->orderBy('data_inizio')->first();
        $giorno = $this->getEsercizioCorrente($condominio)?->data_inizio?->format('Y-m-d');

        return ['saldo_al_predefinito' => $giorno, 'saldo_al_minimo' => $primo?->data_inizio?->format('Y-m-d')];
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateCassaRequest $request, Condominio $condominio): RedirectResponse
    {
        try {
 
            // Lo sblocco d'emergenza con la delibera scelta: il fondo si lega alla delibera (ruolo «deroga»), nella stessa transazione.
            DB::transaction(function () use ($condominio, $request) {
                $cassa = $this->createCassaAction->execute($condominio, $request->validated());
                \App\Http\Controllers\Assemblee\DeliberaNelleSchede::collega($cassa, 'deroga', $request->deliberaDellaDeroga(), $request->user());
            });

            return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashSuccess(__('gestionale.success_create_cassa'))
            );

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Una delibera che non si sceglie più (revocata fra il menu e il salvataggio) è un errore del campo: la frase va accanto al campo, sulla
            // pagina del fondo nuovo, non in un messaggio generico sull'elenco.
            throw $e;
        } catch (\Throwable $e) {

            Log::error('Errore creazione cassa', [
                'condominio_id' => $condominio->id,
                'msg' => $e->getMessage()
            ]);
            
            return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashError($e->getMessage())
            );

        }

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
    public function edit(Condominio $condominio, Cassa $cassa): Response
    {
        $condomini = $this->getCondomini();

        $esercizio = $this->getEsercizioCorrente($condominio);

        return Inertia::render('gestionale/casse/CasseEdit', [
            'condominio' => $condominio,
            'condomini'  => $condomini,
            'esercizio'  => $esercizio,
            'cassa'      => new UpdateCassaResource($cassa),
            // La delibera dello sblocco d'emergenza, se è collegata: «Scegli la delibera» la mostra scelta.
            'delibera'   => \App\Http\Controllers\Assemblee\DeliberaNelleSchede::perLaPagina($cassa, 'deroga'),
        ] + $this->ilGiornoDelSaldo($condominio));
    }

    /**
     * Update the specified resource in storage.
     */
    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCassaRequest $request, Condominio $condominio, Cassa $cassa): RedirectResponse
    {
        // 1. Sicurezza dominio (Questo è compito del Controller o di un Policy/Middleware)
        if ($cassa->condominio_id !== $condominio->id) {
             return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashError('Risorsa non appartenente al condominio corrente.')
            );
        }

        try {
            // 2. Chiamata alla Action
            // Iniettiamo l'action nel costruttore come fatto prima ($this->updateCassaAction)
            DB::transaction(function () use ($cassa, $request) {
                $this->updateCassaAction->execute($cassa, $request->validated());
                // La delibera dello sblocco, o il legame tolto (tolta la delibera, o spento lo sblocco): il servizio che chiama anche il seguito delle
                // delibere. Il testo della motivazione resta com'è.
                \App\Services\Gestionale\DerogaDelFondo::dallaPagina($cassa, $request->deliberaDellaDeroga(),
                    $request->has('delibera_id') || ! $request->boolean('is_override_assemblea'), $request->user());
            });

            return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashSuccess('Risorsa aggiornata correttamente.')
            );

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Un rifiuto di dominio (tipo o saldo non modificabili) è un errore di campo: deve
            // comparire sotto il campo, dove `form.errors` lo mostra. Inghiottito nel `Throwable`
            // qui sotto diventava un flash che la pagina di modifica non espone: l'amministratore
            // cliccava «Aggiorna» e la pagina si ricaricava in silenzio (12/09/2026).
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Errore aggiornamento cassa', [
                'condominio_id' => $condominio->id,
                'cassa_id' => $cassa->id,
                'msg' => $e->getMessage()
            ]);
            
            return back()->with(
                $this->flashError('Errore durante il salvataggio: ' . $e->getMessage())
            );
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Condominio $condominio, Cassa $cassa): RedirectResponse
    {
        // 1. Sicurezza: Verifica che la cassa appartenga al condominio corrente
        if ($cassa->condominio_id !== $condominio->id) {
            return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashError('Operazione non consentita: la risorsa non appartiene a questo condominio.')
            );
        }

        // --- CONTROLLO INTEGRITÀ ---
        // Era scritto e commentato con «DA IMPLEMENTARE QUANDO AVREMO I MOVIMENTI». I movimenti
        // ci sono da un pezzo, e nel frattempo l'eliminazione era diventata la scorciatoia più
        // conveniente per far tornare verde lo Stato Patrimoniale: `casse` non ha `deleted_at`,
        // `righe_scritture.cassa_id` è `nullOnDelete`, e la liquidità non contabilizzata
        // spariva insieme alla riga. Il bollino diventava verde senza che nessuno avesse
        // sistemato niente.
        //
        // La guardia vive sul model — `Cassa::motivoBloccoEliminazione()` — così questa
        // decisione e la spiegazione mostrata nell'elenco non possono divergere.
        if ($motivo = $cassa->motivoBloccoEliminazione()) {
            return back()->with($this->flashError($motivo));
        }

        // Coda 754 (1), decisione 696 (D3, «Elimina e storna»): la frase si legge prima, quando l'apertura è ancora valida.
        $fraseDelloStorno = $cassa->fraseDelloStornoDellApertura(fatto: true);

        try {
            DB::transaction(function () use ($cassa, $condominio) {

                // Coda 754 (1): prima di tutto la sua apertura, stornata nel libro giornale. Senza, l'apertura restava sul conto archiviato e una
                // cassa ricreata con il saldo giusto si sommava a quella sbagliata (€ 2.150,00 + € 2.100,00 di liquidità, «Liquidità e cassa» rosso).
                $this->stornaLeAperture($cassa);

                // Carichiamo il conto contabile associato per poterlo eliminare dopo
                $contoContabile = $cassa->contoContabile;

                // 2. Elimina i dati bancari (ContoCorrente) se presenti
                // Utilizziamo la relazione per eliminare il record polimorfico
                $cassa->contoCorrente()->get()->each->delete(); // con il modello: il registro delle modifiche scrive il conto tolto

                // 3. Elimina la Cassa
                $cassa->delete();

                // 🔥 FIX 2: CONGELAMENTO CONTABILE (Mai delete sui conti di sistema!)
                if ($contoContabile) {
                    $contoContabile->update([
                        'attivo' => false,
                        // Opzionale: Rinominiamo per chiarezza storica ed evitare conflitti futuri
                        'nome'   => $contoContabile->nome . ' (Archiviato ' . date('d/m/Y') . ')',
                        'note'   => 'Cassa eliminata in data ' . date('d/m/Y H:i'),
                    ]);
                    
                    // Se usi SoftDeletes nel modello ContoContabile, puoi anche fare:
                    // $contoContabile->delete(); 
                    // (Che fa un soft delete, mantenendo il record nel DB. È equivalente a disattivare).
                    // Ma seguire il feedback letterale ('attivo' => false) è più esplicito.
                }
            });

            return to_route('admin.gestionale.casse.index', $condominio)->with(
                $this->flashSuccess($fraseDelloStorno === null ? 'Risorsa eliminata correttamente.' : 'Risorsa eliminata. ' . $fraseDelloStorno)
            );

        } catch (\Throwable $e) {
            Log::error('Errore eliminazione cassa', [
                'condominio_id' => $condominio->id,
                'cassa_id'      => $cassa->id,
                'error'         => $e->getMessage()
            ]);

            return back()->with(
                $this->flashError('Impossibile eliminare la risorsa: ' . $e->getMessage())
            );
        }
    }

    /**
     * Coda 754 (1), decisione 696 (D3): lo storno delle aperture della cassa che si elimina, dentro la transazione dell'eliminazione.
     *
     * Come lo storno di un incasso (`StornoIncassoRateAction`): una scrittura uguale e contraria, figlia dell'apertura (`scrittura_padre_id`), con la
     * registrazione di oggi, e l'apertura «annullata»; tutte e due restano nel libro giornale. Due differenze, volute:
     * - **la data di competenza è quella dell'apertura**, non oggi: l'apertura sbagliata non è mai stata liquidità vera, e così a ogni data il conto
     *   archiviato e «Fondo Passate Gestioni» tornano a zero (la situazione patrimoniale di gennaio non vede € 2.150,00 su un conto senza cassa);
     * - **il tipo è `apertura`**, non `rettifica`: le letture che tengono le aperture fuori dai flussi dell'esercizio (il riepilogo finanziario, il registro,
     *   il risultato di gestione) tengono fuori anche lo storno, e quelle che le contano sempre (la tesoreria) contano anche lui. Le guardie «un'apertura
     *   sola per cassa» lo saltano perché ha il padre (`Cassa::apertureValide`).
     */
    private function stornaLeAperture(Cassa $cassa): void
    {
        foreach ($cassa->apertureValide() as $trovata) {
            // Riletta sotto blocco: due eliminazioni insieme non stornano la stessa apertura due volte.
            $apertura = \App\Models\Gestionale\ScritturaContabile::with(['righe', 'esercizio'])->lockForUpdate()->find($trovata->id);
            if (! $apertura || $apertura->stato === 'annullata') {
                continue;
            }
            if ($apertura->esercizio?->stato === 'chiuso') {
                throw new \RuntimeException('la sua apertura è nell\'esercizio «' . $apertura->esercizio->nome . '», che è chiuso.');
            }

            $storno = \App\Models\Gestionale\ScritturaContabile::create([
                'condominio_id'      => $apertura->condominio_id,
                'esercizio_id'       => $apertura->esercizio_id,
                'gestione_id'        => $apertura->gestione_id,
                'scrittura_padre_id' => $apertura->id,
                'data_registrazione' => \App\Helpers\DateHelper::oggiUtente(),
                'data_competenza'    => $apertura->data_competenza,
                'causale'            => mb_substr('Storno del saldo di apertura — ' . $cassa->nome . ', risorsa eliminata', 0, 255),
                'tipo_movimento'     => \App\Enums\TipoMovimentoContabile::APERTURA,
                'stato'              => 'registrata',
                'note'               => 'Annullamento prot. ' . $apertura->numero_protocollo,
                'created_by'         => auth()->id(),
            ]);

            foreach ($apertura->righe as $riga) {
                $storno->righe()->create([
                    'conto_contabile_id' => $riga->conto_contabile_id,
                    'cassa_id'           => $riga->cassa_id,
                    'tipo_riga'          => $riga->tipo_riga === 'dare' ? 'avere' : 'dare',
                    'importo'            => $riga->importo,
                    'note'               => 'Storno del saldo di apertura',
                ]);
            }

            \App\Services\Gestionale\DoubleEntryValidator::validateOrFail($storno->id);
            $apertura->update(['stato' => 'annullata']);
        }
    }
}
