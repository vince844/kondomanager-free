<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Seguito;

use App\Http\Controllers\Controller;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Assemblee\Delibera;
use App\Models\Condominio;
use App\Models\Documento;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Seguito\CoseDaFare;
use App\Services\Assemblee\Seguito\DopoIlRifiuto;
use App\Services\Assemblee\Seguito\SegniDellaDelibera;
use App\Services\Assemblee\Seguito\SeguitoDelleDelibere;
use App\Traits\HandleFlashMessages;
use App\Traits\ScaricaEApprovaDocumento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Il seguito delle delibere (decisioni 500 (3), 522, 523): la conferma di «Che cosa cambia nel gestionale», «Applica» di una riga tolta, le cose da fare
 * (scritte dopo, «Segna eseguita», «Non serve più», «Ricordamelo»), la risposta a una respinta («Riproponi in assemblea», «Chiudi con il motivo») e i segni
 * («Segna annullata dal giudice», «Segna revocata»; «Segna impugnata» è del verbale). Tutto con «Gestisce le assemblee»; ogni id è dell'assemblea e del
 * condominio della rotta (R13). Il riepilogo e il passo 6 arrivano con la pagina dell'assemblea (`seguito`, con una visita parziale).
 */
class SeguitoController extends Controller
{
    use HandleFlashMessages;
    use ScaricaEApprovaDocumento;

    /** «Conferma»: le righe spuntate, le date e le persone delle cose da fare, le cose scritte, le modifiche. L'esito per riga torna con la pagina. */
    public function conferma(Request $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse|JsonResponse
    {
        $this->assicuraLAssemblea($request, $condominio, $assemblea);
        $dati = $request->validate([
            'chiavi' => ['present', 'array'],
            'chiavi.*' => ['string', 'max:120'],
            'cose' => ['nullable', 'array'],
            'cose.*.entro_il' => ['nullable', 'date_format:Y-m-d'],
            'cose.*.a_chi' => ['nullable', 'in:me,studio,collaboratore'],
            'cose.*.assegnato_a' => ['nullable', 'integer'],
            'cose.*.testo' => ['nullable', 'string', 'max:255'],
            'altre' => ['nullable', 'array'],
            'altre.*.delibera_id' => ['required', 'integer'],
            'altre.*.testo' => ['required', 'string', 'max:255'],
            'altre.*.entro_il' => ['nullable', 'date_format:Y-m-d'],
            'altre.*.a_chi' => ['nullable', 'in:me,studio,collaboratore'],
            'altre.*.assegnato_a' => ['nullable', 'integer'],
            'modifiche' => ['nullable', 'array'],
            'modifiche.*' => ['nullable', 'string', 'max:2000'],
        ], [
            'altre.*.testo.required' => 'Scrivi che cosa resta da fare.',
            'cose.*.entro_il.date_format' => 'Il giorno non è valido.',
        ]);
        $esito = SeguitoDelleDelibere::conferma($assemblea, $dati['chiavi'], $dati['cose'] ?? [], $dati['altre'] ?? [], $dati['modifiche'] ?? [],
            $request->user());
        if ($request->expectsJson()) {
            return response()->json($esito);
        }

        return back()->with('seguito_esiti', $esito['esiti'])->with($this->flashSuccess('Che cosa cambia nel gestionale: ' . $esito['conto'] . '.'));
    }

    /** «Applica» di una riga tolta dal riepilogo, nel passo 6. */
    public function applica(Request $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse|JsonResponse
    {
        $this->assicuraLAssemblea($request, $condominio, $assemblea);
        $dati = $request->validate([
            'chiave' => ['required', 'string', 'max:120'],
            'entro_il' => ['nullable', 'date_format:Y-m-d'],
            'a_chi' => ['nullable', 'in:me,studio,collaboratore'],
            'assegnato_a' => ['nullable', 'integer'],
        ]);
        $esito = SeguitoDelleDelibere::applica($assemblea, $dati['chiave'], array_diff_key($dati, ['chiave' => true]), $request->user());
        if ($request->expectsJson()) {
            return response()->json($esito, $esito['esito'] === 'applicata' ? 200 : 422);
        }

        return back()->with('seguito_esiti', [$dati['chiave'] => $esito])->with($esito['esito'] === 'applicata'
            ? $this->flashSuccess($esito['frase']) : $this->flashError($esito['frase']));
    }

    /** «Un'altra cosa da fare» su una delibera, dopo la conferma; prima del verbale registrato (il riepilogo chiuso) non si scrive. */
    public function nuovaCosa(Request $request, Condominio $condominio, Assemblea $assemblea, Delibera $delibera): RedirectResponse
    {
        $this->assicuraLAssemblea($request, $condominio, $assemblea);
        abort_unless((int) $delibera->assemblea_id === (int) $assemblea->id, 404);
        if (! SeguitoDelleDelibere::siApre($delibera, $assemblea)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['testo' => 'Le cose da fare si scrivono con il riepilogo aperto: dopo il verbale registrato.']);
        }
        $dati = $request->validate([
            'testo' => ['required', 'string', 'max:255'],
            'entro_il' => ['nullable', 'date_format:Y-m-d'],
            'a_chi' => ['nullable', 'in:me,studio,collaboratore'],
            'assegnato_a' => ['nullable', 'integer'],
        ], ['testo.required' => 'Scrivi che cosa resta da fare.']);
        CoseDaFare::crea($delibera, \App\Enums\Assemblee\TipoCosaDaFare::FUORI_DAL_PROGRAMMA, $dati['testo'], $dati['entro_il'] ?? null, $dati['a_chi'] ?? null,
            isset($dati['assegnato_a']) ? (int) $dati['assegnato_a'] : null, $request->user());

        return back()->with($this->flashSuccess(isset($dati['entro_il']) ? 'Cosa da fare aggiunta, con il compito nell\'Action inbox.' : 'Cosa da fare aggiunta.'));
    }

    /** «Segna eseguita»: il giorno, la nota, il documento facoltativo. */
    public function eseguita(Request $request, Condominio $condominio, Assemblea $assemblea, string|int $cosa): RedirectResponse
    {
        $c = $this->assicuraLaCosa($request, $condominio, $assemblea, $cosa);
        $dati = $request->validate([
            'giorno' => ['required', 'date_format:Y-m-d'],
            'nota' => ['nullable', 'string', 'max:2000'],
            'documento' => CoseDaFare::regolaDelDocumento(),
        ], ['giorno.required' => 'Scrivi il giorno in cui l\'hai fatta.']);
        CoseDaFare::segnaEseguita($c, $dati['giorno'], $dati['nota'] ?? null, $request->file('documento'), $request->user());

        return back()->with($this->flashSuccess('Segnata eseguita: resta nel registro delle modifiche con il tuo nome.'));
    }

    /** «Non serve più», con il motivo. */
    public function nonServe(Request $request, Condominio $condominio, Assemblea $assemblea, string|int $cosa): RedirectResponse
    {
        $c = $this->assicuraLaCosa($request, $condominio, $assemblea, $cosa);
        $dati = $request->validate(['motivo' => ['required', 'string', 'max:2000']], ['motivo.required' => 'Scrivi perché non serve più.']);
        CoseDaFare::nonServe($c, $dati['motivo'], $request->user());

        return back()->with($this->flashSuccess('Non serve più: il motivo resta nel registro delle modifiche.'));
    }

    /** «Ricordamelo il …»: sposta il giorno, e il compito. */
    public function ricordamelo(Request $request, Condominio $condominio, Assemblea $assemblea, string|int $cosa): RedirectResponse
    {
        $c = $this->assicuraLaCosa($request, $condominio, $assemblea, $cosa);
        $dati = $request->validate(['giorno' => ['required', 'date_format:Y-m-d']], ['giorno.required' => 'Scegli il giorno.']);
        CoseDaFare::ricordamelo($c, $dati['giorno'], $request->user());

        return back()->with($this->flashSuccess('Te lo ricordo il ' . \Illuminate\Support\Carbon::parse($dati['giorno'])->format('d/m/Y') . '.'));
    }

    /** «Riproponi in assemblea» di una delibera respinta: un memo con la stessa origine. */
    public function riproponi(Request $request, Condominio $condominio, Assemblea $assemblea, string|int $cosa): RedirectResponse
    {
        $c = $this->assicuraLaCosa($request, $condominio, $assemblea, $cosa);
        DopoIlRifiuto::riproponi($c, $request->user());

        return back()->with($this->flashSuccess('Riproposta: è nella memoria del condominio, per la prossima assemblea.'));
    }

    /** «Chiudi con il motivo» di una delibera respinta: chiude anche i compiti dell'origine. */
    public function chiudi(Request $request, Condominio $condominio, Assemblea $assemblea, string|int $cosa): RedirectResponse
    {
        $c = $this->assicuraLaCosa($request, $condominio, $assemblea, $cosa);
        $dati = $request->validate(['motivo' => ['required', 'string', 'max:2000']], ['motivo.required' => 'Scrivi perché chiudi.']);
        DopoIlRifiuto::chiudi($c, $dati['motivo'], $request->user());

        return back()->with($this->flashSuccess('Chiusa: il motivo resta nel registro delle modifiche.'));
    }

    /** Il documento di «Segna eseguita», per chi vede le assemblee. */
    public function documento(Request $request, Condominio $condominio, Assemblea $assemblea, string|int $cosa)
    {
        $this->assicuraLAssemblea($request, $condominio, $assemblea, false);
        $c = $this->cosaDellAssemblea($assemblea, $cosa);
        $documento = $c->documento_id === null ? null : Documento::query()->find($c->documento_id);
        abort_if($documento === null || $documento->documentable_type !== $assemblea->getMorphClass() || (int) $documento->documentable_id !== (int) $assemblea->id, 404);

        return $this->scaricaIlDocumento($documento);
    }

    /** «Segna annullata dal giudice»: il giorno e il provvedimento. */
    public function annullata(Request $request, Condominio $condominio, Assemblea $assemblea, Delibera $delibera): RedirectResponse
    {
        $this->assicuraLAssemblea($request, $condominio, $assemblea);
        abort_unless((int) $delibera->assemblea_id === (int) $assemblea->id, 404);
        $dati = $request->validate([
            'giorno' => ['required', 'date_format:Y-m-d'],
            'provvedimento' => ['required', 'string', 'max:1000'],
        ], ['giorno.required' => 'Scrivi il giorno del provvedimento.', 'provvedimento.required' => 'Scrivi il provvedimento del giudice.']);
        SegniDellaDelibera::annullata($delibera, $dati['giorno'], $dati['provvedimento'], $request->user());

        return back()->with($this->flashSuccess('Segnata annullata dal giudice: le schede che la usano lo dicono. Nessun conto cambia da solo.'));
    }

    /** «Segna revocata»: la delibera successiva dello stesso condominio che la revoca. */
    public function revocata(Request $request, Condominio $condominio, Assemblea $assemblea, Delibera $delibera): RedirectResponse
    {
        $this->assicuraLAssemblea($request, $condominio, $assemblea);
        abort_unless((int) $delibera->assemblea_id === (int) $assemblea->id, 404);
        $dati = $request->validate(['revocata_da_delibera_id' => ['required', 'integer']], ['revocata_da_delibera_id.required' => 'Scegli la delibera che la revoca.']);
        /** @var Delibera $da */
        $da = \App\Services\Assemblee\SchedaDelCondominio::risolvi($condominio, 'delibera', (int) $dati['revocata_da_delibera_id']);
        SegniDellaDelibera::revocata($delibera, $da, $request->user());

        return back()->with($this->flashSuccess('Segnata revocata: le schede che la usano lo dicono, e «Scegli la delibera» la spegne.'));
    }

    /** Le delibere che possono revocarla, per la finestra «Segna revocata». */
    public function revoche(Request $request, Condominio $condominio, Assemblea $assemblea, Delibera $delibera): JsonResponse
    {
        $this->assicuraLAssemblea($request, $condominio, $assemblea);
        abort_unless((int) $delibera->assemblea_id === (int) $assemblea->id, 404);

        return response()->json(['delibere' => SegniDellaDelibera::proposteDiRevoca($delibera)]);
    }

    /** L'assemblea è del condominio della rotta (R13); scrivere chiede «Gestisce le assemblee», leggere «Vede». */
    private function assicuraLAssemblea(Request $request, Condominio $condominio, Assemblea $assemblea, bool $scrive = true): void
    {
        abort_unless((int) $assemblea->condominio_id === (int) $condominio->id, 404);
        Gate::allowIf($scrive ? AccessoAlleAssemblee::gestisce($request->user()) : AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
    }

    private function assicuraLaCosa(Request $request, Condominio $condominio, Assemblea $assemblea, string|int $cosa): CosaDaFareDellaDelibera
    {
        $this->assicuraLAssemblea($request, $condominio, $assemblea);

        return $this->cosaDellAssemblea($assemblea, $cosa);
    }

    /** La cosa da fare di una delibera di questa assemblea; altrimenti 404. */
    private function cosaDellAssemblea(Assemblea $assemblea, string|int $cosa): CosaDaFareDellaDelibera
    {
        abort_unless(ctype_digit((string) $cosa), 404);

        return CosaDaFareDellaDelibera::query()->whereKey((int) $cosa)
            ->whereIn('delibera_id', Delibera::query()->where('assemblea_id', $assemblea->id)->select('id'))->first() ?? abort(404);
    }
}
