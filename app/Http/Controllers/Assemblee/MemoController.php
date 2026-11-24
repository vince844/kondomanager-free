<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Enums\Assemblee\StatoAssemblea;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\MemoRequest;
use App\Http\Requests\Assemblee\NonServeRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\PropostaDelPunto;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

/**
 * Il memo «Da portare in assemblea» (§3 P8; decisioni 499 e 501): la proposta per la finestra veloce, il salvataggio, la modifica, «Non serve più» e
 * «Mettilo nell'ordine del giorno del …». La finestra si apre da quindici posti: `proposta` e `store` rispondono con un JSON (standard 16). L'origine e
 * l'assemblea arrivano come id e passano dal risolutore (R18). Salvare un memo non collega niente (R3).
 */
class MemoController extends Controller
{
    use HandleFlashMessages;

    /** Il punto proposto dal contesto, l'origine con il nome e «Apri», le assemblee in preparazione, i collaboratori, «il programma propone già …». */
    public function proposta(Request $request, Condominio $condominio): JsonResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $dati = $request->validate([
            'origine_tipo' => ['nullable', 'string', Rule::in(array_keys(SchedaDelCondominio::TIPI)), 'required_with:origine_id'],
            'origine_id' => ['nullable', 'integer', 'required_with:origine_tipo'],
        ], [], ['origine_tipo' => 'da dove nasce', 'origine_id' => 'da dove nasce']);
        $origine = empty($dati['origine_tipo']) ? null : SchedaDelCondominio::risolvi($condominio, (string) $dati['origine_tipo'], (int) $dati['origine_id']);
        $proposta = $origine === null ? null : PropostaDelPunto::perLaScheda($origine);
        // Solo la chiave di questa scheda, senza ricalcolare l'ordine del giorno: è già un punto di un'assemblea che si prepara, o un sospeso del programma?
        $giaProposto = null;
        if ($proposta !== null) {
            $punto = ProposteDellOrdineDelGiorno::puntoConLaChiave($condominio, (string) $proposta['chiave_fonte']);
            if ($punto !== null) {
                $giaProposto = 'È già il punto ' . $punto['numero'] . ' dell\'ordine del giorno'
                    . ($punto['data'] !== null ? ' del ' . \Illuminate\Support\Carbon::parse($punto['data'])->format('d/m/Y') : '')
                    . ': il tuo memo entra nello stesso punto, con la tua nota.';
            } elseif (ProposteDellOrdineDelGiorno::eUnSospeso($origine)) {
                $giaProposto = 'Il programma propone già questo punto fra quelli della prossima assemblea: il tuo memo diventa lo stesso punto, con la tua nota.';
            }
        }
        $esistente = $origine === null ? null : MemoDellAssemblea::query()->where('condominio_id', $condominio->id)
            ->where('origine_tipo', SchedaDelCondominio::tipoDi($origine))->where('origine_id', $origine->getKey())->whereIn('stato', ['in_attesa', 'nel_punto'])
            ->orderBy('id')->first();

        return response()->json([
            'testo' => $proposta['testo'] ?? '',
            'nota' => $proposta['nota'] ?? null,
            'origine' => $origine === null ? null : [
                'tipo' => SchedaDelCondominio::tipoDi($origine),
                'id' => (int) $origine->getKey(),
                'nome' => SchedaDelCondominio::nomeDi($origine),
                'apri' => self::indirizzoDi($condominio, $origine),
            ],
            'assemblee' => Assemblea::query()->where('condominio_id', $condominio->id)->where('stato', StatoAssemblea::IN_PREPARAZIONE->value)
                ->orderByRaw('data IS NULL')->orderBy('data')->get()->map(fn (Assemblea $a) => ['id' => (int) $a->id, 'nome' => $a->nome()])->values()->all(),
            'collaboratori' => array_values(array_filter(PerLePagine::collaboratori(), fn ($u) => $u['id'] !== (int) $request->user()->id)),
            'gia_proposto' => $giaProposto,
            'esistente' => $esistente === null ? null : ['id' => (int) $esistente->id, 'frase' => 'Già nella memoria dal '
                . $esistente->created_at?->copy()->setTimezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y') . ': «' . $esistente->testo . '»'],
            'condominio' => $condominio->nome,
        ]);
    }

    public function store(MemoRequest $request, Condominio $condominio): JsonResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $memo = MemoriaDelCondominio::salva($condominio, $request->dati(), $request->user());

        return response()->json(['memo' => self::memo($memo), 'frase' => self::adesso($memo, $condominio)], 201);
    }

    public function update(MemoRequest $request, Condominio $condominio, MemoDellAssemblea $memo): JsonResponse
    {
        abort_unless($memo->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $memo = MemoriaDelCondominio::aggiorna($memo, $request->dati(), $request->user());

        return response()->json(['memo' => self::memo($memo), 'frase' => self::adesso($memo, $condominio)]);
    }

    public function nonServe(NonServeRequest $request, Condominio $condominio, MemoDellAssemblea $memo): RedirectResponse
    {
        abort_unless($memo->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        MemoriaDelCondominio::nonServe($memo, (string) $request->validated('motivo'), $request->user());

        return back()->with($this->flashSuccess('Il memo non serve più: esce dalla memoria del condominio, e il suo compito si chiude.'));
    }

    /** «Mettilo nell'ordine del giorno del 15/12»: l'assemblea in preparazione del condominio (R18). */
    public function nellOrdine(Request $request, Condominio $condominio, MemoDellAssemblea $memo): RedirectResponse
    {
        abort_unless($memo->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $id = $request->validate(['assemblea_id' => ['required', 'integer']], ['assemblea_id.required' => 'Scegli l\'assemblea in preparazione.'],
            ['assemblea_id' => 'l\'assemblea'])['assemblea_id'];
        /** @var Assemblea $assemblea */
        $assemblea = SchedaDelCondominio::risolvi($condominio, 'assemblea', (int) $id);
        $punto = MemoriaDelCondominio::nellOrdine($memo, $assemblea, $request->user());

        return to_route('admin.gestionale.assemblee.show', ['condominio' => $condominio->id, 'assemblea' => $assemblea->id, 'punto' => $punto->id])
            ->with($this->flashSuccess('Il memo è il punto ' . $punto->numero . ' dell\'ordine del giorno del ' . $assemblea->data?->format('d/m/Y') . '.'));
    }

    /** @return array<string, mixed> */
    private static function memo(MemoDellAssemblea $memo): array
    {
        return ['id' => (int) $memo->id, 'testo' => $memo->testo, 'stato' => $memo->stato?->value, 'ricordami_dal' => $memo->ricordami_dal?->format('Y-m-d'),
            'a_chi' => $memo->a_chi?->value, 'evento_id' => $memo->evento_id];
    }

    /**
     * «Adesso: dal 25/11/2026 nell'Action inbox e nell'agenda, a te.», «Adesso: lo trovi proposto quando prepari la prossima assemblea di Via Roma 12.», o,
     * per un memo entrato nel punto che c'era già, «Adesso: è il punto 3 dell'ordine del giorno del 15/12/2026, con la tua nota.»
     */
    public static function adesso(MemoDellAssemblea $memo, Condominio $condominio): string
    {
        if ($memo->stato === \App\Enums\Assemblee\StatoMemo::NEL_PUNTO && $memo->punto !== null) {
            $data = $memo->punto->assemblea?->data;

            return 'Adesso: è il punto ' . $memo->punto->numero . ' dell\'ordine del giorno' . ($data !== null ? ' del ' . $data->format('d/m/Y') : '') . ', con la tua nota.';
        }
        if ($memo->ricordami_dal === null) {
            return 'Adesso: lo trovi proposto quando prepari la prossima assemblea di ' . $condominio->nome . '.';
        }
        $aChi = match ($memo->a_chi?->value) {
            'studio' => 'a tutto lo studio',
            'collaboratore' => 'a ' . (\App\Models\User::query()->whereKey($memo->assegnato_a)->value('name') ?? 'un collaboratore'),
            default => 'a te',
        };

        return 'Adesso: dal ' . $memo->ricordami_dal->format('d/m/Y') . ' nell\'Action inbox e nell\'agenda, ' . $aChi . '.';
    }

    /** L'indirizzo della scheda di origine, per «Apri», dove la pagina c'è. */
    public static function indirizzoDi(Condominio $condominio, \Illuminate\Database\Eloquent\Model $scheda): ?string
    {
        $tipo = SchedaDelCondominio::tipoDi($scheda);
        $id = (int) $scheda->getKey();
        $c = (int) $condominio->id;
        [$nome, $parametri] = match ($tipo) {
            'fattura_passiva' => ['admin.gestionale.fatture.show', ['condominio' => $c, 'fattura' => $id]],
            'piano_rate' => ['admin.gestionale.esercizi.piani-rate.show', ['condominio' => $c, 'esercizio' => (int) ($scheda->getAttribute('esercizio_id') ?? 0), 'pianoRate' => $id]],
            'assemblea' => ['admin.gestionale.assemblee.show', ['condominio' => $c, 'assemblea' => $id]],
            default => [null, []],
        };
        if ($nome === null || ! Route::has($nome) || in_array(0, $parametri, true)) {
            return null;
        }

        return route($nome, $parametri, false);
    }
}
