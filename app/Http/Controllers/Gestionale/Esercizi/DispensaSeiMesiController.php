<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Esercizi;

use App\Services\RegistroModifiche\RegistroModifiche;
use App\Helpers\DateHelper;
use App\Http\Controllers\Assemblee\DeliberaNelleSchede;
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
 *
 * Dalla 1.12.0-beta.13 la data e il numero del verbale vengono dalla delibera scelta con «Scegli la delibera» (`delibera_id`, del condominio della rotta),
 * e la dispensa si lega alla delibera (ruolo `dispensa`; la revoca, ruolo `revoca_della_dispensa`). Le colonne `data_delibera` e `numero_verbale` restano
 * quelle che i sei mesi leggono; senza delibera la data scritta a mano vale come prima.
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
                'togli_sollecito_tesoreria' => (bool) $d->togli_sollecito_tesoreria,
                'esercizi' => $d->esercizi->map(fn ($e) => ['id' => $e->id, 'nome' => $e->nome])->values(),
                'persone' => $d->persone->map(fn ($p) => ['id' => $p->id, 'nome' => $p->nome])->values(),
                'revocata_il' => $d->revocata_il?->format('Y-m-d'),
                'nota_revoca' => $d->nota_revoca,
                'verbale' => $d->documenti->first()?->name,
                'delibera' => DeliberaNelleSchede::perLaPagina($d, 'dispensa'),
                'delibera_della_revoca' => DeliberaNelleSchede::perLaPagina($d, 'revoca_della_dispensa'),
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
        // La delibera scelta dà il giorno della riunione e il numero del suo verbale: le colonne di oggi si scrivono con quelli (R1).
        $delibera = DeliberaNelleSchede::dallaRichiesta($request, $condominio);
        if ($delibera !== null) {
            $request->merge(['data_delibera' => DeliberaNelleSchede::giorno($delibera), 'numero_verbale' => DeliberaNelleSchede::numeroDelVerbale($delibera)]);
        }
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
            // Una scelta di lavoro dell'amministratore, non una parte della delibera: di serie la dispensa lascia il sollecito in tesoreria.
            'togli_sollecito_tesoreria' => ['nullable', 'boolean'],
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
            DB::transaction(function () use ($condominio, $dati, $tutti, $esercizi, $persone, $request, $delibera, &$percorso) {
                $dispensa = DispensaSeiMesi::create([
                    'condominio_id' => $condominio->id,
                    'data_delibera' => $dati['data_delibera'],
                    'numero_verbale' => $dati['numero_verbale'] ?? null,
                    'nota' => $dati['nota'] ?? null,
                    'tutte_le_persone' => $tutti,
                    'togli_sollecito_tesoreria' => (bool) ($dati['togli_sollecito_tesoreria'] ?? false),
                    'registrata_da' => $request->user()?->id,
                ]);
                RegistroModifiche::legame($dispensa, 'esercizi', 'Esercizi', fn () => $dispensa->esercizi()->sync($esercizi));
                RegistroModifiche::legame($dispensa, 'persone', 'Persone', fn () => $dispensa->persone()->sync($persone));
                DeliberaNelleSchede::collega($dispensa, 'dispensa', $delibera, $request->user());
                // Il verbale è quello della riunione della delibera: il file si carica qui solo senza la delibera.
                if ($delibera === null && $request->hasFile('verbale')) {
                    $file = $request->file('verbale');
                    $percorso = app(ArchivioDocumenti::class)->salvaConLEstensioneDelNome($file);
                    // Un documento della dispensa: con il suo proprietario non entra nell'archivio né nel portale.
                    $dispensa->documenti()->create([
                        'name' => $file->getClientOriginalName(),
                        'description' => 'Verbale della dispensa dei sei mesi del ' . \Carbon\Carbon::parse($dati['data_delibera'])->format('d/m/Y'),
                        'path' => $percorso,
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'created_by' => $request->user()?->id,
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
        $delibera = DeliberaNelleSchede::dallaRichiesta($request, $condominio);
        if ($delibera !== null) {
            $request->merge(['revocata_il' => DeliberaNelleSchede::giorno($delibera)]);
        }
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
        // Il servizio che chiama anche il seguito delle delibere: la revoca e il collegamento nella stessa transazione.
        \App\Services\Gestionale\RevocaDellaDispensa::revoca($d, $dati['revocata_il'], $dati['nota_revoca'] ?? null, $delibera, $request->user());

        return back()->with($this->flashSuccess('Dispensa revocata: accanto alle date si legge «dispensa revocata il …».'));
    }

    /**
     * «Togli anche il sollecito in tesoreria», cambiata dall'elenco delle dispense: è una scelta di lavoro, non una parte della delibera, e si cambia anche
     * dopo. La dispensa toglie sempre i passi verso il legale; con la casella, in tesoreria, anche il sollecito.
     */
    public function sollecito(Request $request, Condominio $condominio, string|int $dispensa): RedirectResponse
    {
        $d = $this->assicuraDispensa($condominio, $dispensa);
        $dati = $request->validate(['togli' => ['required', 'boolean']]);
        $d->update(['togli_sollecito_tesoreria' => (bool) $dati['togli']]);

        return back()->with($this->flashSuccess((bool) $dati['togli']
            ? 'Fatto: la dispensa toglie anche il sollecito in tesoreria.'
            : 'Fatto: con questa dispensa la tesoreria propone di nuovo il sollecito.'));
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
