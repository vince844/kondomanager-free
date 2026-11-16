<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use App\Services\Gestionale\PisteDellaBanca;
use App\Services\Gestionale\SaldiDelleBanche;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «Ignora» e «Riapri» di una possibile causa di una differenza fra il saldo dell'estratto conto della banca e quello della contabilità
 * ({@see PisteDellaBanca}), dalla scheda «Controlli». Ignorare chiede una nota, che resta scritta nel registro della nota sintetica con chi e quando: la
 * causa non si ripropone, e chi rilegge il rendiconto ne vede la ragione. Le cause si rifanno qui sul server, così il registro ha la frase che il
 * programma ha proposto, non una che arriva dalla pagina.
 *
 * Le rotte stanno nel gruppo del gestionale, dietro il pannello. L'esercizio di un altro condominio fa 404; un conto che non è di questo condominio non
 * ha cause.
 */
class RendicontoBancaController extends Controller
{
    use HandleFlashMessages;

    public function ignora(Request $request, Condominio $condominio, Esercizio $esercizio, PisteDellaBanca $piste, SaldiDelleBanche $banche,
        StatoPatrimonialePaginaService $pagina): RedirectResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $dati = $request->validate([
            'cassa_id' => ['required', 'integer', Rule::exists('casse', 'id')->where('condominio_id', $condominio->id)],
            'chiave' => ['required', 'string', 'max:120'],
            'nota' => ['required', 'string', 'min:3', 'max:'.NotaSinteticaDelRendiconto::LUNGHEZZA_RIGA],
        ], [
            'nota.required' => 'Scrivi perché la ignori: la nota resta nel registro, accanto al controllo.',
            'nota.min' => 'Scrivi perché la ignori: la nota resta nel registro, accanto al controllo.',
        ]);
        $confronto = $banche->confronto($condominio, NotaSinteticaDelRendiconto::contenuto($esercizio)['banca'], $pagina->costruisci($condominio, $esercizio));
        $pista = collect($piste->perIConti($condominio, $esercizio, $confronto)[(int) $dati['cassa_id']] ?? [])->firstWhere('chiave', $dati['chiave']);
        if ($pista === null) {
            return back()->with($this->flashError('Questa causa oggi non c\'è più: i movimenti o il saldo dell\'estratto sono cambiati. La pagina mostra quelle di oggi.'));
        }
        if ($pista['ignorata'] !== null) {
            return back()->with($this->flashInfo('Questa causa è già ignorata.'));
        }
        PisteDellaBanca::scrivi($esercizio, PisteDellaBanca::IGNORATA, (int) $dati['cassa_id'], $pista, $dati['nota'], $request->user());

        return back()->with($this->flashSuccess('Causa ignorata: la nota resta nel registro, accanto al controllo. Si può riaprire.'));
    }

    public function riapri(Request $request, Condominio $condominio, Esercizio $esercizio): RedirectResponse
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
        $dati = $request->validate([
            'cassa_id' => ['required', 'integer', Rule::exists('casse', 'id')->where('condominio_id', $condominio->id)],
            'chiave' => ['required', 'string', 'max:120'],
            'nota' => ['nullable', 'string', 'max:'.NotaSinteticaDelRendiconto::LUNGHEZZA_RIGA],
        ]);
        $ignorate = PisteDellaBanca::ignorate(NotaSinteticaDelRendiconto::contenuto($esercizio)['registro']);
        if (! isset($ignorate[PisteDellaBanca::chiaveCompleta((int) $dati['cassa_id'], $dati['chiave'])])) {
            return back()->with($this->flashInfo('Questa causa non è ignorata.'));
        }
        PisteDellaBanca::scrivi($esercizio, PisteDellaBanca::RIAPERTA, (int) $dati['cassa_id'], ['chiave' => $dati['chiave']], (string) ($dati['nota'] ?? ''), $request->user());

        return back()->with($this->flashSuccess('Causa riaperta: torna fra quelle da guardare.'));
    }
}
