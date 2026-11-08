<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Actions\Gestionale\Movimenti\RegistraIncassoDaTerziAction;
use App\Actions\Gestionale\Movimenti\StornaIncassoDaTerziAction;
use App\Enums\NaturaStorno;
use App\Exceptions\Gestionale\IncassoDaTerziNonAmmessoException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Gestionale\Movimenti\StoreIncassoDaTerziRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ScritturaContabile;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * L'incasso da terzi: il modulo, la registrazione e lo storno (`RegistraIncassoDaTerziAction`, `StornaIncassoDaTerziAction`).
 *
 * Ci si arriva dalla pagina degli incassi («Altro incasso») e dal blocco delle regolazioni scritte su una voce di entrata nella lista delle
 * fatture; dopo la registrazione si torna da dove si è partiti (`da`), altrimenti alla scrittura appena registrata.
 */
class IncassoDaTerziController extends Controller
{
    use HandleFlashMessages;
    use HasCondomini;
    use HasEsercizio;

    /** Da dove si arriva al modulo, e dove si torna dopo: un elenco chiuso, mai un indirizzo che arriva dalla richiesta. */
    public const PROVENIENZE = ['incassi', 'fatture'];

    public function create(Request $request, Condominio $condominio): Response
    {
        $esercizio = $this->getEsercizioCorrente($condominio);

        $voci = $esercizio
            ? RegistraIncassoDaTerziAction::vociAmmesse($condominio, $esercizio)
                ->with('parent:id,nome')
                ->get(['id', 'nome', 'parent_id', 'importo'])
                ->map(fn (Conto $v) => [
                    'id' => $v->id,
                    'nome' => $v->nome,
                    'label' => $v->parent ? $v->parent->nome.' — '.$v->nome : $v->nome,
                    // Il preventivo della voce, in centesimi, per la frase accanto alla scelta.
                    'preventivo' => abs((int) $v->importo),
                ])
                ->sortBy('label')
                ->values()
            : collect();

        $voceRichiesta = filter_var($request->query('voce'), FILTER_VALIDATE_INT);
        $da = in_array($request->query('da'), self::PROVENIENZE, true) ? $request->query('da') : null;

        return Inertia::render('gestionale/movimenti/incassiDaTerzi/IncassoDaTerziNew', [
            'condominio' => $condominio,
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            // `GestionaleHeader` legge `props.esercizio` per i collegamenti della barra.
            'esercizio' => $esercizio,
            'voci' => $voci,
            'casse' => Cassa::where('condominio_id', $condominio->id)
                ->where('attiva', true)
                ->whereIn('tipo', RegistraIncassoDaTerziAction::CASSE_REALI)
                ->orderBy('nome')
                ->get(['id', 'nome', 'tipo'])
                ->map(fn (Cassa $c) => ['id' => $c->id, 'nome' => $c->nome, 'tipo' => $c->tipo])
                ->values(),
            // La rubrica: chi ha versato può non essere un condòmino (il gestore dell'antenna, la banca).
            'anagrafiche' => Anagrafica::orderBy('nome')->get(['id', 'nome'])->map(fn (Anagrafica $a) => ['id' => $a->id, 'nome' => $a->nome])->values(),
            'prossimo_protocollo' => ScritturaContabile::previewNextProtocolNumber($condominio->id, 'ENT'),
            'voce_scelta' => $voceRichiesta !== false && $voci->contains('id', $voceRichiesta) ? $voceRichiesta : null,
            'da' => $da,
            'link_piano_dei_conti' => $esercizio ? route('admin.gestionale.esercizi.piani-conti.index', [$condominio, $esercizio]) : null,
        ]);
    }

    public function store(StoreIncassoDaTerziRequest $request, Condominio $condominio, RegistraIncassoDaTerziAction $action): RedirectResponse
    {
        $validated = $request->validated();
        $esercizio = Esercizio::where('condominio_id', $condominio->id)->findOrFail($validated['esercizio_id']);
        $da = in_array($request->input('da'), self::PROVENIENZE, true) ? $request->input('da') : null;

        try {
            $scrittura = $action->execute($validated, $condominio, $esercizio);
        } catch (IncassoDaTerziNonAmmessoException $e) {
            return back()->withInput()->withErrors(['incasso_non_ammesso' => $e->getMessage()]);
        }

        Log::info("Incasso da terzi {$scrittura->numero_protocollo} registrato da utente #".Auth::id()." — condominio #{$condominio->id}");
        $messaggio = $this->flashSuccess("Incasso da terzi {$scrittura->numero_protocollo} registrato.");

        if ($request->boolean('crea_altro')) {
            return redirect()->route('admin.gestionale.incassi-da-terzi.create', array_filter(['condominio' => $condominio->id, 'da' => $da]))->with($messaggio);
        }

        return match ($da) {
            'incassi' => redirect()->route('admin.gestionale.movimenti-rate.index', ['condominio' => $condominio->id])->with($messaggio),
            'fatture' => redirect()->route('admin.gestionale.fatture.index', ['condominio' => $condominio->id])->with($messaggio),
            default => redirect()->route('admin.gestionale.scritture.show', ['condominio' => $condominio->id, 'scrittura' => $scrittura->id])->with($messaggio),
        };
    }

    public function storno(Request $request, Condominio $condominio, ScritturaContabile $scrittura, StornaIncassoDaTerziAction $action): RedirectResponse
    {
        // La rotta cerca già la scrittura dentro il condominio; la guardia resta, se un giorno la rotta uscisse dal vincolo.
        abort_unless((int) $scrittura->condominio_id === (int) $condominio->id, 404);

        $domanda = 'Scegli perché storni questo incasso: registrazione sbagliata (vale dal giorno dell\'incasso, o da oggi se il suo esercizio è chiuso) o incasso tornato indietro (vale da oggi).';
        $validated = $request->validate([
            'natura_storno' => ['required', 'string', Rule::enum(NaturaStorno::class)],
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'natura_storno.required' => $domanda,
            'natura_storno.string' => $domanda,
            'natura_storno.enum' => $domanda,
            'motivo.required' => 'È obbligatorio indicare il motivo dello storno.',
            'motivo.min' => 'Il motivo deve contenere almeno 10 caratteri.',
        ]);

        try {
            $storno = $action->execute($scrittura, $condominio, NaturaStorno::from($validated['natura_storno']), $validated['motivo']);
        } catch (IncassoDaTerziNonAmmessoException $e) {
            return back()->with($this->flashError($e->getMessage()));
        }

        Log::info("Storno incasso da terzi {$scrittura->numero_protocollo} → {$storno->numero_protocollo} da utente #".Auth::id());

        return redirect()
            ->route('admin.gestionale.scritture.show', ['condominio' => $condominio->id, 'scrittura' => $storno->id])
            ->with($this->flashSuccess("Storno eseguito: scrittura {$storno->numero_protocollo}."));
    }
}
