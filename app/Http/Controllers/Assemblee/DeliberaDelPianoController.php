<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Enums\Assemblee\RuoloCollegamento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\DeliberaDelPianoRequest;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * La delibera di un piano rate fuori da «Approva» (§3 P11, R1): sul **piano già emesso** il collegamento e basta, la data del piano non cambia e la pagina
 * dice la differenza (scena 3 della bozza approvata con la decisione 502); sul **piano d'urgenza** «Riferito all'assemblea», con il ruolo `riferimento`, dove
 * si sceglie anche una delibera senza voto (la comunicazione dei lavori urgenti, legge §10). Senza delibera, il collegamento si toglie. Sul piano non ancora
 * emesso la data entra per la sua strada (la colonna di oggi), con il numero del verbale della riunione.
 */
class DeliberaDelPianoController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(DeliberaDelPianoRequest $request, Condominio $condominio, Esercizio $esercizio, PianoRate $pianoRate): RedirectResponse
    {
        abort_unless((int) $pianoRate->condominio_id === (int) $condominio->id, 404);
        $this->assicuraIlPianoDellEsercizio($condominio, $esercizio);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $ruolo = (string) $request->validated('ruolo');
        if ($request->validated('delibera_id') === null) {
            CollegamentiDelleDelibere::scollega($pianoRate, $ruolo, $request->user());

            return back()->with($this->flashSuccess('Tolta la delibera dal piano. La data del piano resta com\'è.'));
        }
        /** @var \App\Models\Assemblee\Delibera $delibera */
        $delibera = SchedaDelCondominio::risolvi($condominio, 'delibera', (int) $request->validated('delibera_id'));
        $esito = DB::transaction(function () use ($pianoRate, $ruolo, $delibera, $request) {
            $esito = CollegamentiDelleDelibere::collega($pianoRate, $ruolo, $delibera, $request->validated('nota'), $request->user());
            if ($ruolo === RuoloCollegamento::DELIBERA->value && $esito->dataEntrata !== null && ! $pianoRate->eImmutabile()) {
                // Il numero del verbale della riunione di quel giorno, quando ne ha uno: senza, resta quello scritto a mano nel piano.
                $pianoRate->update(['data_delibera_assemblea' => $esito->dataEntrata,
                    'numero_verbale' => \App\Services\Assemblee\DeliberaInParole::numeroDelVerbale($delibera) ?? $pianoRate->numero_verbale]);
            }

            return $esito;
        });

        return back()->with($this->flashSuccess($esito->differenza !== null
            ? 'Delibera collegata al piano. ' . $esito->differenza . ': il piano è emesso e la data non cambia da sola.'
            : ($ruolo === RuoloCollegamento::RIFERIMENTO->value ? 'Il piano d\'urgenza è riferito all\'assemblea.' : 'Delibera collegata al piano.')));
    }

    private function assicuraIlPianoDellEsercizio(Condominio $condominio, Esercizio $esercizio): void
    {
        abort_unless((int) $esercizio->condominio_id === (int) $condominio->id, 404);
    }
}
