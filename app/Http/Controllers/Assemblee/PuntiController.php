<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\DalleProposteRequest;
use App\Http\Requests\Assemblee\DeliberaSulPuntoRequest;
use App\Http\Requests\Assemblee\PuntoRequest;
use App\Http\Requests\Assemblee\RiordinaRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\DeliberaAMano;
use App\Services\Assemblee\OrdineDelGiorno;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * L'ordine del giorno di un'assemblea in preparazione (§3 P2): aggiungere, riscrivere, togliere, riordinare, aggiungere dalle proposte; e «Registra la
 * delibera» sul punto di un'assemblea tenuta fuori dal programma (R16). Ogni gesto torna alla pagina, che rilegge solo `ordine`, `proposte` e
 * `passi`. La palazzina, la scala e la tabella del punto sono del condominio (R18).
 */
class PuntiController extends Controller
{
    use HandleFlashMessages;

    public function store(PuntoRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $punto = OrdineDelGiorno::aggiungi($assemblea, self::nelCondominio($condominio, $request->campi()), $request->user());

        return back()->with($this->flashSuccess($this->conLaRistampa($assemblea, 'Punto ' . $punto->numero . ' aggiunto all\'ordine del giorno.')));
    }

    public function update(PuntoRequest $request, Condominio $condominio, Assemblea $assemblea, PuntoDellOrdineDelGiorno $punto): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        $this->assicuraIlPunto($assemblea, $punto);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        OrdineDelGiorno::riscrivi($punto, self::nelCondominio($condominio, $request->campi(), $punto), $request->user());

        return back()->with($this->flashSuccess($this->conLaRistampa($assemblea, 'Punto ' . $punto->numero . ' salvato.')));
    }

    public function destroy(Request $request, Condominio $condominio, Assemblea $assemblea, PuntoDellOrdineDelGiorno $punto): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        $this->assicuraIlPunto($assemblea, $punto);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $daUnMemo = $punto->memo()->exists();
        OrdineDelGiorno::togli($punto, $request->user());

        return back()->with($this->flashSuccess($this->conLaRistampa($assemblea, 'Punto tolto dall\'ordine del giorno.' . ($daUnMemo ? ' Il memo torna nella memoria del condominio.' : ''))));
    }

    public function riordina(RiordinaRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        OrdineDelGiorno::riordina($assemblea, (array) $request->validated('ordine'), $request->user());

        return back()->with($this->flashSuccess($this->conLaRistampa($assemblea, 'Ordine del giorno riordinato.')));
    }

    /** I punti spuntati fra le proposte, riletti dal server (R18). */
    public function dalleProposte(DalleProposteRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $punti = AssembleeController::propostePrese($condominio, $assemblea, (array) $request->validated('proposte'), (array) $request->validated('maggioranze', []));
        $aggiunti = OrdineDelGiorno::dalleProposte($assemblea, $punti, $request->user());

        return back()->with($this->flashSuccess(count($aggiunti) === 1 ? 'Un punto aggiunto all\'ordine del giorno.' : count($aggiunti) . ' punti aggiunti all\'ordine del giorno.'));
    }

    /** «Registra la delibera» su un punto di un'assemblea tenuta fuori dal programma (R16): nasce lì, non in una riunione nuova. */
    public function delibera(DeliberaSulPuntoRequest $request, Condominio $condominio, Assemblea $assemblea, PuntoDellOrdineDelGiorno $punto): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        $this->assicuraIlPunto($assemblea, $punto);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        DeliberaAMano::registraSulPunto($punto, $request->validated(), $request->user());

        return back()->with($this->flashSuccess('Delibera registrata sul punto ' . $punto->numero . '. Adesso la scegli come le altre, nelle pagine che la chiedono.'));
    }

    /**
     * I campi di un punto scritto, pronti per il servizio: le chiavi esterne risolte nel condominio e i dati uniti a quelli che il punto ha già. È la strada
     * di «Aggiungi un punto» e di «Modifica», e quella dei punti scritti in «Prepara l'assemblea» ({@see AssembleeController::store()}), che nascono con
     * l'assemblea.
     *
     * @param array<string, mixed> $campi i campi di {@see PuntoRequest::campiDelPunto()}
     * @return array<string, mixed>
     */
    public static function nelCondominio(Condominio $condominio, array $campi, ?PuntoDellOrdineDelGiorno $punto = null): array
    {
        return self::conIDati($punto, self::campi($condominio, $campi));
    }

    /**
     * I dati del punto che scrive la pagina (le parti comuni e la nuova destinazione) si uniscono a quelli che ha già (il motivo della spesa, che scrive il
     * programma); un campo vuoto si toglie.
     *
     * @param array<string, mixed> $campi
     * @return array<string, mixed>
     */
    private static function conIDati(?PuntoDellOrdineDelGiorno $punto, array $campi): array
    {
        if (array_key_exists('dati', $campi)) {
            $uniti = array_filter([...(array) ($punto?->dati ?? []), ...(array) ($campi['dati'] ?? [])], fn ($v) => $v !== null);
            $campi['dati'] = $uniti === [] ? null : $uniti;
        }

        return $campi;
    }

    private function assicuraIlPunto(Assemblea $assemblea, PuntoDellOrdineDelGiorno $punto): void
    {
        abort_unless((int) $punto->assemblea_id === (int) $assemblea->id, 404);
    }

    /**
     * Le chiavi esterne del punto risolte nel condominio (R18): la palazzina, la scala (con la sua palazzina) e la tabella. Un punto di tutto il condominio
     * non tiene la scala.
     *
     * @param array<string, mixed> $campi
     * @return array<string, mixed>
     */
    private static function campi(Condominio $condominio, array $campi): array
    {
        if (! empty($campi['tabella_id'])) {
            $campi['tabella_id'] = (int) SchedaDelCondominio::risolvi($condominio, 'tabella', (int) $campi['tabella_id'])->getKey();
        }
        if (($campi['ambito'] ?? null) === AmbitoAssemblea::SCALA->value) {
            $scala = SchedaDelCondominio::risolvi($condominio, 'scala', (int) $campi['scala_id']);
            $campi['scala_id'] = (int) $scala->getKey();
            $campi['palazzina_id'] = $scala->getAttribute('palazzina_id') === null ? null : (int) $scala->getAttribute('palazzina_id');
        } elseif (($campi['ambito'] ?? null) === AmbitoAssemblea::PALAZZINA->value) {
            $campi['palazzina_id'] = (int) SchedaDelCondominio::risolvi($condominio, 'palazzina', (int) $campi['palazzina_id'])->getKey();
            $campi['scala_id'] = null;
        } elseif (array_key_exists('ambito', $campi)) {
            $campi['palazzina_id'] = null;
            $campi['scala_id'] = null;
        }

        return $campi;
    }

    /** Il messaggio, e «Hai stampato le lettere il …: ristampale» se dopo il cambio ci sono lettere stampate da ristampare. */
    private function conLaRistampa(Assemblea $assemblea, string $messaggio): string
    {
        return app(\App\Services\Assemblee\Convocazione\ElencoDeiConvocati::class)->fraseDopoIlSalvataggio($assemblea->refresh(), $messaggio);
    }
}
