<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Impostazioni;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\Assemblee\Verbale\ModelliDelVerbale;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * I modelli del verbale dell'assemblea (V12 e P4 del progetto del verbale), nella pagina «Modelli delle lettere» delle impostazioni, sezione «Il verbale
 * dell'assemblea»: creare, cambiare ed eliminare con le regole dei modelli delle lettere (chi gestisce le impostazioni di stampa; il nome unico per «vale
 * per»). Un modello ha due testi, prima e dopo i punti, nella struttura dell'editor. Cambiare o eliminare un modello non cambia i verbali già generati.
 */
class ModelliDelVerbaleController extends Controller
{
    use HandleFlashMessages;

    public function __construct(private readonly ModelliDelVerbale $modelli) {}

    public function store(Request $request): RedirectResponse
    {
        abort_unless(self::puoModificare($request), 403);
        $modello = $this->modelli->salva(null, $this->dati($request), $request->user());

        return back()->with($this->flashSuccess('Modello del verbale «' . $modello->nome . '» salvato.'));
    }

    public function update(Request $request, string|int $modello): RedirectResponse
    {
        $esistente = ModelliDelVerbale::oppure404($modello);
        abort_unless(self::puoModificare($request), 403);
        $this->modelli->salva($esistente, $this->dati($request), $request->user());

        return back()->with($this->flashSuccess('Modello del verbale «' . $esistente->nome . '» salvato. I verbali già generati non cambiano.'));
    }

    public function destroy(Request $request, string|int $modello): RedirectResponse
    {
        $esistente = ModelliDelVerbale::oppure404($modello);
        abort_unless(self::puoModificare($request), 403);
        $nome = $esistente->nome;
        $esistente->delete();

        return back()->with($this->flashSuccess('Modello del verbale «' . $nome . '» eliminato. I verbali già generati non cambiano.'));
    }

    /** @return array{nome: string, prima: mixed, dopo: mixed, condominio_id: ?int} */
    private function dati(Request $request): array
    {
        $dati = $request->validate([
            'nome' => ['required', 'string', 'max:' . \App\Models\ModelloTesto::LUNGHEZZA_NOME],
            'prima' => ['required', 'array'],
            'dopo' => ['required', 'array'],
            'condominio_id' => ['present', 'nullable', 'integer', 'exists:condomini,id'],
        ], ['nome.required' => 'Scrivi il nome del modello.', 'prima.required' => 'Scrivi il testo prima dei punti.', 'dopo.required' => 'Scrivi il testo dopo i punti.',
            'condominio_id.present' => 'Scegli per quali condomini vale il modello: tutti, o uno.']);

        return ['nome' => (string) $dati['nome'], 'prima' => $dati['prima'], 'dopo' => $dati['dopo'], 'condominio_id' => $dati['condominio_id'] ?? null];
    }

    private static function puoModificare(Request $request): bool
    {
        return $request->user()?->can(Permission::MANAGE_GENERAL_SETTINGS->value) === true;
    }
}
