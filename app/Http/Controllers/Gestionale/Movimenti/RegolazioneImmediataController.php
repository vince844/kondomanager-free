<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Actions\Gestionale\Movimenti\RegistraRegolazioneImmediataAction;
use App\Exceptions\Gestionale\RegolazioneImmediataNonAmmessaException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreRegolazioneImmediataRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registrazione a regolazione immediata (prima nota diretta costo → banca/cassa).
 *
 * Percorso PARALLELO alla registrazione fattura, non sostitutivo: serve ai fatti
 * amministrativi che nascono e si estinguono nello stesso momento — imposte di
 * bollo, commissioni bancarie, addebiti automatici, piccole spese — per i quali
 * aprire una partita fornitore significa inventare un fornitore fittizio "BANCA"
 * e sporcare il libro giornale con documenti che non esistono.
 *
 * Cfr. docs/registrazione_e_regolazione_immediata.md
 */
class RegolazioneImmediataController extends Controller
{
    use HandleFlashMessages;
    use HasCondomini;
    use HasEsercizio;

    /**
     * Form di registrazione. Espone solo ciò che la scrittura richiede davvero:
     * capitolo di spesa (DARE), cassa (AVERE), importo, data, causale — più il
     * fornitore come tag facoltativo.
     *
     * I capitoli senza `conto_contabile_id` non vengono esposti: sono quelli su
     * cui l'Action rifiuterebbe la registrazione per mancanza di ancoraggio in
     * partita doppia, e mostrarli produrrebbe solo un errore dopo il click.
     */
    public function create(Condominio $condominio): Response
    {
        $capitoli = Conto::whereIn('piano_conto_id', $condominio->pianiDeiConti()->pluck('id'))
            ->whereDoesntHave('sottoconti')
            ->whereNotNull('conto_contabile_id')
            ->visibili()
            ->with('parent:id,nome')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'nome' => $c->nome,
                'parent_nome' => $c->parent?->nome,
                'label' => $c->parent ? $c->parent->nome.' — '.$c->nome : $c->nome,
            ])
            ->sortBy('label')
            ->values();

        return Inertia::render('gestionale/movimenti/regolazioni/RegolazioneImmediataNew', [
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
            'capitoli' => $capitoli,
            // I fondi sono partizioni virtuali dell'unico conto corrente reale, non
            // casse da cui far uscire denaro: una regolazione immediata è un'uscita
            // di cassa vera. Stessa esclusione del form di pagamento fattura.
            'casse' => Cassa::where('condominio_id', $condominio->id)
                ->where('attiva', true)
                ->where('tipo', '!=', 'fondo')
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'nome' => $c->nome,
                    'tipo' => $c->tipo,
                ]),
            // soggetto_ritenuta serve al form per avvisare PRIMA del submit che
            // quel fornitore richiede una fattura (guard rail §6).
            'fornitori' => Fornitore::orderBy('ragione_sociale')
                ->get(['id', 'ragione_sociale', 'soggetto_ritenuta']),
        ]);
    }

    /**
     * Storno di una regolazione immediata: scrittura inversa, mai cancellazione.
     * Il giornale è append-only e lo storno è l'operazione sempre ammessa.
     */
    public function storno(
        \Illuminate\Http\Request $request,
        Condominio $condominio,
        \App\Models\Gestionale\ScritturaContabile $scrittura,
        \App\Actions\Gestionale\Movimenti\StornaRegolazioneImmediataAction $action
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
                "Storno regolazione immediata {$scrittura->numero_protocollo} → {$storno->numero_protocollo} "
                ."da utente #".Auth::id()." — motivo: {$validated['motivo']}"
            );

            return redirect()
                ->route('admin.gestionale.scritture.show', [
                    'condominio' => $condominio->id,
                    'scrittura' => $storno->id,
                ])
                ->with($this->flashSuccess(
                    "Storno eseguito: scrittura {$storno->numero_protocollo}."
                ));
        } catch (RegolazioneImmediataNonAmmessaException $e) {
            return back()->with($this->flashError($e->getMessage()));
        }
    }

    public function store(
        StoreRegolazioneImmediataRequest $request,
        Condominio $condominio,
        RegistraRegolazioneImmediataAction $action
    ): RedirectResponse {
        $validated = $request->validated();

        $esercizio = Esercizio::where('condominio_id', $condominio->id)
            ->findOrFail($validated['esercizio_id']);

        try {
            $scrittura = $action->execute($validated, $condominio, $esercizio);

            Log::info(
                "Regolazione immediata {$scrittura->numero_protocollo} registrata da utente #".Auth::id()
                ." — condominio #{$condominio->id}, importo {$validated['importo']}, causale: {$validated['causale']}"
            );

            // Destinazione: il dettaglio della scrittura appena creata. `movimenti.index`
            // rimbalza su Incassi rate (MovimentiController:27), dove il movimento non
            // compare: l'utente resterebbe senza riscontro di ciò che ha appena registrato.
            return redirect()
                ->route('admin.gestionale.scritture.show', [
                    'condominio' => $condominio->id,
                    'scrittura' => $scrittura->id,
                ])
                ->with($this->flashSuccess(
                    "Registrazione a regolazione immediata {$scrittura->numero_protocollo} eseguita."
                ));
        } catch (RegolazioneImmediataNonAmmessaException $e) {
            // Regola di dominio violata: il messaggio spiega quale percorso usare
            // al posto di questo, quindi va mostrato integralmente all'utente.
            return back()
                ->withInput()
                ->withErrors(['regolazione_non_ammessa' => $e->getMessage()]);
        }
    }
}
