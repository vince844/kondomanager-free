<?php

namespace App\Http\Controllers\Gestionale\Esercizi;

use App\Helpers\DateHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\DispensaSeiMesi;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Debiti\SeiMesi;
use App\Support\LimiteCaricamento;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La dispensa dell'assemblea dall'agire entro sei mesi (art. 1129 c. 9 c.c.): l'elenco, la registrazione, la revoca e il verbale. Si arriva
 * dalla pagina degli esercizi e dall'elenco delle rate scadute. Una delibera alla volta: è un fatto dell'assemblea, che l'amministratore
 * registra; il perimetro (gli esercizi, tutte le persone o alcune) lo dice la delibera, e il programma non lo estende da solo. Gli esercizi e
 * le persone della richiesta devono essere di questo condominio, la dispensa delle rotte anche: altrimenti 404 per tutta la richiesta.
 */
class DispensaSeiMesiController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    public function index(Condominio $condominio): Response
    {
        $dispense = DispensaSeiMesi::where('condominio_id', $condominio->id)->with(['esercizi:id,nome', 'persone:id,nome', 'documenti:id,documentable_id,documentable_type,name'])
            ->orderByDesc('data_delibera')->orderByDesc('id')->get()
            ->map(fn (DispensaSeiMesi $d) => [
                'id' => $d->id,
                'data_delibera' => $d->data_delibera?->format('Y-m-d'),
                'numero_verbale' => $d->numero_verbale,
                'nota' => $d->nota,
                'tutte_le_persone' => $d->tutte_le_persone,
                'esercizi' => $d->esercizi->map(fn ($e) => ['id' => $e->id, 'nome' => $e->nome])->values(),
                'persone' => $d->persone->map(fn ($p) => ['id' => $p->id, 'nome' => $p->nome])->values(),
                'revocata_il' => $d->revocata_il?->format('Y-m-d'),
                'nota_revoca' => $d->nota_revoca,
                'verbale' => $d->documenti->first()?->name,
            ]);

        return Inertia::render('gestionale/esercizi/DispenseSeiMesi', [
            'condominio' => ['id' => $condominio->id, 'nome' => $condominio->nome],
            'condomini' => CondominioResource::collection($this->getCondomini())->resolve(),
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'esercizi' => $condominio->esercizi()->orderByDesc('data_inizio')->get(['id', 'nome', 'data_inizio', 'data_fine', 'stato']),
            'persone' => $condominio->anagrafiche()->orderBy('nome')->get(['anagrafiche.id', 'anagrafiche.nome']),
            'dispense' => $dispense,
            'testo_norma' => SeiMesi::TESTO_NORMA,
            'oggi' => DateHelper::oggiUtente(),
        ]);
    }

    public function store(Request $request, Condominio $condominio): RedirectResponse
    {
        $dati = $request->validate([
            'data_delibera' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . DateHelper::oggiUtente()],
            'numero_verbale' => ['nullable', 'string', 'max:50'],
            'nota' => ['nullable', 'string', 'max:1000'],
            'esercizi' => ['required', 'array', 'min:1'],
            'esercizi.*' => ['integer'],
            // Tutte le persone o alcune: lo dice la delibera, e la scelta non è già fatta.
            'per_tutti' => ['required', Rule::in(['0', '1', 0, 1, true, false])],
            'persone' => [Rule::requiredIf(fn () => ! filter_var($request->input('per_tutti'), FILTER_VALIDATE_BOOLEAN)), 'array'],
            'persone.*' => ['integer'],
            'verbale' => ['nullable', 'file', 'extensions:pdf,jpg,jpeg,png', 'max:' . LimiteCaricamento::regolaMax(10.0), \App\Support\FileComeIlNome::regola()],
        ], [
            'data_delibera.required' => 'Scrivi la data dell\'assemblea che ha deliberato la dispensa.',
            'data_delibera.before_or_equal' => 'La data della delibera non può essere dopo oggi.',
            'esercizi.required' => 'Scegli almeno un esercizio coperto dalla dispensa.',
            'per_tutti.required' => 'Di\' se la dispensa vale per tutte le persone o solo per alcune: lo dice la delibera.',
            'persone.required' => 'Scegli le persone per cui vale la dispensa.',
        ]);

        $tutti = filter_var($dati['per_tutti'], FILTER_VALIDATE_BOOLEAN);
        $esercizi = array_values(array_unique(array_map('intval', $dati['esercizi'])));
        abort_unless($condominio->esercizi()->whereIn('id', $esercizi)->count() === count($esercizi), 404);
        $persone = $tutti ? [] : array_values(array_unique(array_map('intval', $dati['persone'] ?? [])));
        if (! $tutti && $persone === []) {
            return back()->withErrors(['persone' => 'Scegli le persone per cui vale la dispensa.']);
        }
        abort_unless($persone === [] || Anagrafica::whereIn('id', $persone)->whereHas('condomini', fn ($q) => $q->where('condomini.id', $condominio->id))->count() === count($persone), 404);

        $percorso = null;
        try {
            DB::transaction(function () use ($condominio, $dati, $tutti, $esercizi, $persone, $request, &$percorso) {
                $dispensa = DispensaSeiMesi::create([
                    'condominio_id' => $condominio->id,
                    'data_delibera' => $dati['data_delibera'],
                    'numero_verbale' => $dati['numero_verbale'] ?? null,
                    'nota' => $dati['nota'] ?? null,
                    'tutte_le_persone' => $tutti,
                    'registrata_da' => $request->user()?->id,
                ]);
                $dispensa->esercizi()->sync($esercizi);
                $dispensa->persone()->sync($persone);
                if ($request->hasFile('verbale')) {
                    $file = $request->file('verbale');
                    $percorso = app(ArchivioDocumenti::class)->salvaConLEstensioneDelNome($file);
                    // Un documento della dispensa: con il suo proprietario non entra nell'archivio né nel portale.
                    $dispensa->documenti()->create([
                        'name' => $file->getClientOriginalName(),
                        'description' => 'Verbale della dispensa dei sei mesi del ' . \Carbon\Carbon::parse($dati['data_delibera'])->format('d/m/Y'),
                        'path' => $percorso,
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'created_by' => $request->user()?->id ?? 1,
                        'is_published' => false,
                        'is_approved' => true,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            app(ArchivioDocumenti::class)->elimina($percorso);

            throw $e;
        }

        return back()->with($this->flashSuccess('Dispensa registrata: accanto alle date dei sei mesi che copre si legge «dispensa dell\'assemblea». Le date non cambiano.'));
    }

    public function revoca(Request $request, Condominio $condominio, string|int $dispensa): RedirectResponse
    {
        $d = $this->assicuraDispensa($condominio, $dispensa);
        $dati = $request->validate([
            'revocata_il' => ['required', 'date_format:Y-m-d', 'after_or_equal:' . $d->data_delibera->format('Y-m-d'), 'before_or_equal:' . DateHelper::oggiUtente()],
            'nota_revoca' => ['nullable', 'string', 'max:1000'],
        ], [
            'revocata_il.required' => 'Scrivi la data della revoca.',
            'revocata_il.after_or_equal' => 'La revoca non può essere prima della delibera della dispensa.',
            'revocata_il.before_or_equal' => 'La data della revoca non può essere dopo oggi.',
        ]);
        if ($d->revocata()) {
            return back()->withErrors(['revocata_il' => 'Questa dispensa è già revocata.']);
        }
        $d->update(['revocata_il' => $dati['revocata_il'], 'nota_revoca' => $dati['nota_revoca'] ?? null, 'revocata_da' => $request->user()?->id]);

        return back()->with($this->flashSuccess('Dispensa revocata: accanto alle date si legge «dispensa revocata il …».'));
    }

    public function verbale(Condominio $condominio, string|int $dispensa)
    {
        $d = $this->assicuraDispensa($condominio, $dispensa);
        $documento = $d->documenti()->first();
        abort_if($documento === null || ! app(ArchivioDocumenti::class)->esiste($documento->path), 404);

        return app(ArchivioDocumenti::class)->scarica($documento->path, $documento->nomeDiScaricamento());
    }

    /** La dispensa dell'indirizzo, se è di questo condominio; altrimenti 404. */
    private function assicuraDispensa(Condominio $condominio, string|int $id): DispensaSeiMesi
    {
        abort_unless(is_numeric($id), 404);
        $d = DispensaSeiMesi::find((int) $id);
        abort_unless($d !== null && (int) $d->condominio_id === (int) $condominio->id, 404);

        return $d;
    }
}
