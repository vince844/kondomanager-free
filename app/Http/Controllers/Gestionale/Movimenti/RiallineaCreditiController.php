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

        $adPersonam = self::fraseAdPersonam($fatto);

        if ($fatto['rettifiche'] === [] && $fatto['emissioni'] === []) {
            if ($fatto['restano'] !== [] || $adPersonam !== '') {
                return back()->with($this->flashWarning('Niente scritto.'
                    . ($fatto['restano'] !== [] ? ' Restano come sono: ' . implode('; ', array_column($fatto['restano'], 'motivo')) . '.' : '')
                    . $adPersonam));
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
            . ($fatto['restano'] !== [] ? ' Restano come sono: ' . implode('; ', array_column($fatto['restano'], 'motivo')) . '.' : '')
            . $adPersonam));
    }

    /**
     * Coda 313 (decisione 153.1): la spesa ad personam addebitata due volte che la card elenca resta com'è, e i piani da controllare a
     * mano; la conferma non la scrive, e lo dice. Vuota se non c'è niente da dire.
     *
     * @param array{ad_personam?: list<array{importo: int}>, ad_personam_non_calcolabili?: list<array{piano: string}>} $fatto
     */
    private static function fraseAdPersonam(array $fatto): string
    {
        $frase = '';
        $voci = $fatto['ad_personam'] ?? [];
        if ($voci !== []) {
            $frase .= ' La spesa ad personam addebitata due volte (' . \App\Helpers\MoneyHelper::format(array_sum(array_column($voci, 'importo')))
                . ' in tutto) resta com\'è: il riallineamento non la scrive.';
        }
        $piani = array_values(array_unique(array_column($fatto['ad_personam_non_calcolabili'] ?? [], 'piano')));
        if ($piani !== []) {
            $nomi = array_map(fn (string $p) => "«{$p}»", $piani);
            // Il perché intero, come il motivo della card: senza il registro il piano si ricostruisce, e non si calcola solo se la
            // ricostruzione non torna con le sue quote.
            $frase .= count($nomi) === 1
                ? " Il piano {$nomi[0]} va controllato a mano: non ha il registro del riparto, e la ricostruzione con i titolari di oggi non torna con le sue quote, quindi la cifra non si calcola."
                : ' I piani ' . implode(', ', array_slice($nomi, 0, -1)) . ' e ' . end($nomi) . ' vanno controllati a mano: non hanno il registro del riparto, e la ricostruzione con i titolari di oggi non torna con le loro quote, quindi la cifra non si calcola.';
        }

        return $frase;
    }
}
