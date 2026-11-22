<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\RaccoltaRequest;
use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\RaccoltaDelleDate;
use App\Traits\HandleFlashMessages;
use App\Traits\HasCondomini;
use App\Traits\HasEsercizio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La raccolta delle date già scritte (§3 P7, R9, D10): l'anteprima per riunione proposta, con una casella per scheda e il segno delle date sospette, e
 * «Raccogli N date», che crea solo le righe spuntate con i loro collegamenti. L'anteprima non scrive niente. Le schede spuntate si rileggono dal server
 * (R18): conta solo ciò che l'anteprima propone in questo condominio.
 */
class RaccoltaController extends Controller
{
    use HandleFlashMessages, HasCondomini, HasEsercizio;

    public function index(Request $request, Condominio $condominio): Response
    {
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $anteprima = array_map(function (array $g) {
            $riunione = $g['riunione_id'] === null ? null : Assemblea::query()->find($g['riunione_id']);

            return [
                'chiave' => $g['chiave'],
                'giorno' => $g['giorno'],
                'numero_verbale' => $g['numero_verbale'],
                'riunione' => $riunione === null ? null : ['id' => (int) $riunione->id, 'nome' => $riunione->nome()],
                'schede' => array_map(fn (array $s) => array_diff_key($s, ['modello' => true]), $g['schede']),
            ];
        }, RaccoltaDelleDate::anteprima($condominio));

        return Inertia::render('gestionale/assemblee/Raccolta', [
            'condominio' => new CondominioResource($condominio),
            'condomini' => $this->getCondomini(),
            'esercizio' => $this->getEsercizioCorrente($condominio),
            'anteprima' => $anteprima,
            // «Per chi» di ogni riunione proposta: di serie tutto il condominio, oppure una palazzina o una scala.
            'palazzine' => \App\Models\Palazzina::query()->where('condominio_id', $condominio->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'nome' => (string) $p->name])->values()->all(),
            'scale' => \App\Models\Scala::query()->where('condominio_id', $condominio->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($sc) => ['id' => (int) $sc->id, 'nome' => (string) $sc->name])->values()->all(),
        ]);
    }

    public function store(RaccoltaRequest $request, Condominio $condominio): RedirectResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $esito = RaccoltaDelleDate::conferma($condominio, array_map(fn (array $s) => [(string) $s['oggetto_tipo'], (int) $s['oggetto_id']],
            (array) $request->validated('spuntate')), $request->user(),
            collect((array) $request->validated('perimetri', []))->mapWithKeys(fn (array $p) => [(string) $p['chiave'] => $p])->all());
        $quante = fn (int $n, string $una, string $tante) => $n === 1 ? '1 ' . $una : $n . ' ' . $tante;

        return to_route('admin.gestionale.assemblee.index', ['condominio' => $condominio->id])->with($this->flashSuccess('Date raccolte: '
            . $quante($esito['riunioni'], 'riunione nuova', 'riunioni nuove') . ', ' . $quante($esito['delibere'], 'delibera', 'delibere') . ', '
            . $quante($esito['schede'], 'scheda collegata', 'schede collegate') . '.'));
    }
}
