<?php

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Exceptions\Gestionale\ContoPassateGestioniMancanteException;
use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Services\Gestionale\RiallineaCreditiService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * La conferma della card «Crediti da riallineare» nella pagina «Incassi rate» (decisioni 75.1 e 76.1, 1.11.0-beta.49): parte solo
 * dalla conferma esplicita dell'amministratore, dopo l'anteprima. Il servizio rilegge tutto sotto blocco: una pagina aperta prima
 * non scrive due volte, e una voce che non si può fare (un esercizio chiuso) non si scrive e il messaggio lo dice.
 */
class RiallineaCreditiController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(Condominio $condominio, RiallineaCreditiService $service): RedirectResponse
    {
        try {
            $fatto = $service->esegui($condominio, Auth::id());
        } catch (ContoPassateGestioniMancanteException $e) {
            // La frase dell'eccezione dice già la strada (rilievo LDA-7): qui si aggiunge solo che non è stato scritto niente.
            return back()->with($this->flashError($e->getMessage() . ' Niente è stato scritto.'));
        }

        if ($fatto['rettifiche'] === [] && $fatto['emissioni'] === []) {
            if ($fatto['restano'] !== []) {
                return back()->with($this->flashWarning('Niente scritto. Restano come sono: ' . implode('; ', array_column($fatto['restano'], 'motivo')) . '.'));
            }

            return back()->with($this->flashInfo('Niente da riallineare: i crediti sono già a posto.'));
        }

        Log::info("Riallineamento crediti condominio #{$condominio->id} da utente #" . Auth::id() . ': rettifiche ' . implode(', ', $fatto['rettifiche'])
            . '; emissioni ' . implode(', ', $fatto['emissioni']));

        $parti = [];
        if ($fatto['rettifiche'] !== []) {
            $parti[] = (count($fatto['rettifiche']) === 1 ? '1 rettifica' : count($fatto['rettifiche']) . ' rettifiche') . ' (prot. ' . implode(', ', $fatto['rettifiche']) . ')';
        }
        if ($fatto['emissioni'] !== []) {
            $parti[] = (count($fatto['emissioni']) === 1 ? '1 emissione' : count($fatto['emissioni']) . ' emissioni') . ' di quote a credito (prot. ' . implode(', ', $fatto['emissioni']) . ')';
        }

        return back()->with($this->flashSuccess('Riallineamento fatto: ' . implode(' e ', $parti) . ' nel libro giornale.'
            . ($fatto['restano'] !== [] ? ' Restano come sono: ' . implode('; ', array_column($fatto['restano'], 'motivo')) . '.' : '')));
    }
}
