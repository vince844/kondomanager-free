<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Dashboard;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Evento;
use App\Services\Gestionale\Inbox\CompitoDaModerare;
use App\Services\Gestionale\Inbox\CompitoDellaSegnalazione;
use App\Services\Gestionale\Inbox\ElencoDellInbox;
use App\Services\Gestionale\Inbox\FasceDeiCompiti;
use App\Services\Gestionale\Inbox\RiepilogoDellInbox;
use App\Services\Gestionale\Inbox\TipiDeiCompiti;
use App\Services\Gestionale\RispostePromemoria;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'Action inbox, la casella delle attività del pannello: i compiti aperti dell'amministratore (le verifiche dei bonifici segnalati, le rate da emettere e da
 * incassare, le fatture da pagare, le segnalazioni, le scadenze amministrative) di tutti i condomini o di quello scelto.
 *
 * La pagina porta: le cinque card, i due grafici e «Dove si accumulano», dalla lettura aggregata ({@see RiepilogoDellInbox}); l'elenco dei compiti
 * ({@see ElencoDellInbox}) con le schede «Tutti / Scaduti / In arrivo» e il loro numero, la ricerca e l'ordine, 15 alla volta («Mostra altri 15»,
 * `Inertia::scroll`) o, con «Tutti» e più di un gruppo, l'anteprima di ogni gruppo; i condomini dei sei mesi per agire, che la pagina chiede uno alla volta
 * (`SeiMesiStudioController`). La regola di «scaduto» è una sola ({@see FasceDeiCompiti}), la prossima azione di ogni tipo pure ({@see TipiDeiCompiti}). I
 * filtri sono nell'indirizzo: `quando` (una scheda, la card «Scaduti» o una barra del grafico per età), `tipo` (una card del tipo o una barra del grafico per
 * tipo), `cerca`, `ordine`, `condominio_id`; si sommano, e un valore che non c'è non filtra. «Mostra altri 15» chiede solo l'elenco: le letture contate non
 * si rifanno.
 *
 * I compiti sono scadenze in agenda, e la casella ne segue i permessi: si legge con `EventoPolicy::viewAny` («Visualizza scadenze in agenda», la regola con
 * cui la dashboard del pannello mostra le scadenze); le risposte chiedono `EventoPolicy::chiudiCompito` («Modifica scadenza in agenda») prima di ogni altra
 * verifica. I compiti delle segnalazioni, con il loro titolo e la loro descrizione, seguono anche la regola della pagina delle segnalazioni: chi non può aprire
 * una segnalazione non ne legge né ne conta il compito ({@see CompitoDellaSegnalazione::chiVede()}).
 *
 * Le risposte a un compito («Fatto, con una nota», «Non serve, con il motivo», «Ricordamelo il…») passano da `risposta()`; «Completa» e «Rifiuta» restano con
 * il loro effetto e passano dalle stesse funzioni ({@see RispostePromemoria}) e dalle stesse guardie: il compito di una segnalazione che chi risponde non apre
 * è 404, e «Completa» sul bonifico segnalato ancora in verifica ha la frase di «Fatto». I compiti elencati e contati sono quelli di {@see Evento::scopeCompitiAperti()}:
 * un compito in attesa di «Ricordamelo» non c'è.
 */
class ActionInboxController extends Controller
{
    use HandleFlashMessages;
    use HasCondomini;

    public const RISPOSTE = [RispostePromemoria::FATTO, RispostePromemoria::NON_SERVE, RispostePromemoria::RICORDAMELO];

    public const FRASE_PERCHE = 'Scrivi perché non serve.';
    public const FRASE_PERCHE_RIFIUTI = 'Scrivi perché rifiuti la segnalazione: il condòmino lo legge.';
    public const FRASE_DA_DOMANI = 'Scegli un giorno da domani in poi.';

    public function __invoke(Request $request, RiepilogoDellInbox $riepilogo): Response
    {
        Gate::authorize('viewAny', Evento::class);

        // I filtri dell'indirizzo; le segnalazioni che si vedono vengono da chi legge, non dall'indirizzo.
        $filtri = ElencoDellInbox::filtri($request->only(['quando', 'tipo', 'cerca', 'ordine', 'condominio_id']), $request->user());
        $fasce = FasceDeiCompiti::oggi();
        $elenco = new ElencoDellInbox($fasce);

        // Le letture contate, una volta per la pagina: «Mostra altri 15» chiede solo l'elenco, e non le rifà.
        $letto = null;
        $leggi = function () use (&$letto, $riepilogo, $filtri, $fasce) {
            return $letto ??= $riepilogo->leggi($filtri['condominio_id'], $filtri['segnalazioni'], $fasce, $filtri['moderare']);
        };
        $perFascia = null;
        $contati = function () use (&$perFascia, $elenco, $filtri) {
            return $perFascia ??= $elenco->perFascia($filtri);
        };
        $gruppi = fn () => ElencoDellInbox::gruppi($contati(), $filtri['quando']);

        return Inertia::render('dashboard/ActionInbox', [
            'tasks' => Inertia::scroll(fn () => $elenco->pagina($filtri)),
            'counts' => fn () => $leggi()['counts'],
            'grafici' => fn () => $leggi()['grafici'],
            'dove' => fn () => $leggi()['dove'],
            'schede' => fn () => ElencoDellInbox::schede($contati()),
            'gruppi' => $gruppi,
            'modo' => fn () => ElencoDellInbox::modo($filtri['quando'], $gruppi()),
            'anteprima' => fn () => ElencoDellInbox::modo($filtri['quando'], $gruppi()) === 'anteprima' ? $elenco->anteprima($filtri, $gruppi()) : [],
            'filtri' => ['quando' => $filtri['quando'], 'tipo' => $filtri['tipo'], 'cerca' => $filtri['cerca'], 'ordine' => $filtri['ordine'],
                'condominio_id' => $filtri['condominio_id'], 'scheda' => FasceDeiCompiti::scheda($filtri['quando']),
                'tipo_nome' => $filtri['tipo'] === null ? null : TipiDeiCompiti::nome($filtri['tipo'])],
            'condomini' => $this->getCondomini(),
            // I condomini dei sei mesi per agire, in ordine di nome: la colonna li chiede uno alla volta dopo che la pagina si è aperta. Nessuna cifra qui.
            'seiMesiCondomini' => fn () => Condominio::query()->when($filtri['condominio_id'] !== null, fn ($q) => $q->whereKey($filtri['condominio_id']))
                ->orderBy('nome')->orderBy('id')->get(['id', 'nome'])
                ->map(fn (Condominio $c) => ['id' => (int) $c->id, 'nome' => (string) $c->nome])->values()->all(),
            // Il giorno dell'amministratore, per «Ricordamelo» (da domani): non quello del browser.
            'oggi' => DateHelper::oggiUtente(),
        ]);
    }

    /**
     * Le risposte a un compito della casella, dalle due Inbox: «Fatto» (la nota facoltativa), «Non serve» (il motivo; sul
     * bonifico segnalato è il rifiuto della segnalazione, che il condòmino legge), «Ricordamelo» (il giorno da domani, nel
     * calendario dell'amministratore). La guardia è quella di «Rifiuta»: prima il permesso, poi un compito aperto della
     * casella che chi risponde vede (il compito di una segnalazione solo a chi apre la segnalazione), altrimenti 404.
     */
    public function risposta(Request $request, Evento $task, RispostePromemoria $risposte): RedirectResponse
    {
        Gate::authorize('chiudiCompito', Evento::class);

        abort_unless($this->eUnCompitoAperto($task) && $this->loVede($request, $task), 404);

        $tipo = $request->validate(['tipo' => ['required', Rule::in(self::RISPOSTE)]])['tipo'];
        $utente = $request->user();
        // Il compito di una segnalazione ha solo «Ricordamelo»: si chiude quando si chiude la segnalazione.
        if ($tipo !== RispostePromemoria::RICORDAMELO && $this->eUnaSegnalazione($task)) {
            throw ValidationException::withMessages(['tipo' => CompitoDellaSegnalazione::FRASE_SOLO_RICORDAMELO]);
        }

        if ($tipo === RispostePromemoria::FATTO) {
            $this->fattoSoloDopoLIncasso($task);
            $dati = $request->validate(['nota' => ['nullable', 'string', 'max:500']]);
            $risposte->fattoDelCompito($task, $dati['nota'] ?? null, $utente);

            return back()->with($this->flashSuccess('Fatto: il compito è chiuso.'));
        }

        if ($tipo === RispostePromemoria::NON_SERVE) {
            $bonifico = RispostePromemoria::eUnBonificoSegnalato($task);
            $frase = $bonifico ? self::FRASE_PERCHE_RIFIUTI : self::FRASE_PERCHE;
            $dati = $request->validate(['motivo' => ['required', 'string', 'min:3', 'max:255']],
                ['motivo.required' => $frase, 'motivo.min' => $frase]);
            $risposte->nonServeDelCompito($task, trim($dati['motivo']), $utente);

            return back()->with($this->flashSuccess($bonifico
                ? 'Segnalazione rifiutata: il condòmino legge il motivo nel portale.'
                : 'Non serve: il compito è chiuso.'));
        }

        $dati = $request->validate([
            'giorno' => ['required', 'date_format:Y-m-d', 'after:' . DateHelper::oggiUtente()],
            'nota' => ['nullable', 'string', 'max:500'],
        ], ['giorno.required' => self::FRASE_DA_DOMANI, 'giorno.date_format' => self::FRASE_DA_DOMANI, 'giorno.after' => self::FRASE_DA_DOMANI]);
        $risposte->ricordameloDelCompito($task, $dati['giorno'], $dati['nota'] ?? null, $utente);

        return back()->with($this->flashSuccess('Ti ricordo il compito il ' . CarbonImmutable::parse($dati['giorno'])->format('d/m/Y') . ': è nell\'agenda.'));
    }

    /**
     * Rifiuta una segnalazione di pagamento: è «Non serve» del bonifico segnalato, con lo stesso effetto di prima
     * sull'evento del condòmino collegato, e la risposta scritta.
     *
     * Si rifiuta soltanto un compito aperto della casella: uno che chiede un'azione, non privato e non già chiuso, cioè
     * uno di quelli che la casella elenca. Una scadenza privata di un condòmino, una scadenza qualunque dell'agenda o un
     * compito già chiuso non sono compiti da rifiutare: 404, e niente cambia, nemmeno la segnalazione collegata.
     */
    public function reject(Request $request, Evento $task, RispostePromemoria $risposte)
    {
        Gate::authorize('chiudiCompito', Evento::class);

        abort_unless($this->eUnCompitoAperto($task) && $this->loVede($request, $task), 404);
        if ($this->eUnaSegnalazione($task)) {
            throw ValidationException::withMessages(['reason' => CompitoDellaSegnalazione::FRASE_SOLO_RICORDAMELO]);
        }

        $request->validate([
            'reason' => 'required|string|max:255', 
        ]);

        $risposte->nonServeDelCompito($task, (string) $request->input('reason'), $request->user());

        return back()->with('success', 'Segnalazione rifiutata e condòmino notificato.');
    }

    /**
     * Segna un Task Admin come completato dalla Inbox: è «Fatto» senza nota, con la risposta scritta, e con le sue guardie: il compito di una segnalazione
     * che chi risponde non apre è 404; sul bonifico segnalato ancora in verifica, senza un incasso, la frase di «Fatto», e niente cambia.
     */
    public function complete(Request $request, Evento $task, RispostePromemoria $risposte)
    {
        Gate::authorize('chiudiCompito', Evento::class);

        abort_unless($this->loVede($request, $task), 404);
        // Evita di chiudere task già completati o task privati dei condòmini
        if ($task->is_completed || $task->visibility === 'private') {
            return back()->with('error', 'Impossibile completare questo task.');
        }
        if ($this->eUnaSegnalazione($task)) {
            throw ValidationException::withMessages(['tipo' => CompitoDellaSegnalazione::FRASE_SOLO_RICORDAMELO]);
        }
        $this->fattoSoloDopoLIncasso($task);

        $risposte->fattoDelCompito($task, null, $request->user());

        return back()->with('success', 'Task completato.');
    }

    /** Il compito di una segnalazione di guasto: si chiude con la segnalazione, e qui ha solo «Ricordamelo». */
    private function eUnaSegnalazione(Evento $task): bool
    {
        return ($task->meta['type'] ?? $task->tipo?->value) === CompitoDellaSegnalazione::TIPO;
    }

    /**
     * Chi risponde vede il compito: il compito di una segnalazione solo se apre la segnalazione, con la regola delle due Inbox
     * ({@see CompitoDellaSegnalazione::chiVede()}); gli altri compiti sì.
     */
    private function loVede(Request $request, Evento $task): bool
    {
        $tipo = $task->meta['type'] ?? $task->tipo?->value;
        if ($tipo !== CompitoDellaSegnalazione::TIPO && $tipo !== CompitoDaModerare::TIPO) {
            return true;
        }

        return CompitoDellaSegnalazione::soloQuelliCheVede(Evento::query()->whereKey($task->getKey()), CompitoDellaSegnalazione::chiVede($request->user()),
            CompitoDaModerare::chiVede($request->user()))->exists();
    }

    /**
     * Sul bonifico segnalato «Fatto» (e «Completa», che è «Fatto» senza nota) vale dopo un incasso, anche parziale, anche andato su altre rate: senza, il
     * condòmino resterebbe «in verifica».
     */
    private function fattoSoloDopoLIncasso(Evento $task): void
    {
        if (RispostePromemoria::eUnBonificoSegnalato($task) && app(\App\Services\Gestionale\SegnalazioneDelBonifico::class)->fattoSpento($task)) {
            throw ValidationException::withMessages(['tipo' => RispostePromemoria::FRASE_PRIMA_L_INCASSO]);
        }
    }

    /**
     * Un compito aperto della casella: chiede un'azione, non è privato, non è chiuso. Le stesse tre condizioni con cui
     * `getTasks()` e i conteggi lo elencano.
     */
    private function eUnCompitoAperto(Evento $task): bool
    {
        return data_get($task->meta, 'requires_action') === true
            && $task->visibility !== 'private'
            && ! $task->is_completed;
    }
}