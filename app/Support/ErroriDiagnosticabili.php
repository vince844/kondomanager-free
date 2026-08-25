<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Un'eccezione inghiottita è un difetto che nessuno può diagnosticare.
 *
 * ## Perché questo file esiste
 *
 * Molti controller del gestionale chiudono le operazioni di scrittura in un `try/catch` che registra
 * nel log e rimanda l'utente indietro con un messaggio generico. La forma è giusta — un errore non
 * deve mai diventare una pagina bianca — ma ha due conseguenze che sono costate tempo vero:
 *
 * 1. **Il redirect è identico a quello del successo.** Chi guarda solo lo stato HTTP non distingue
 *    l'operazione riuscita da quella fallita: nella beta.58 un test è rimasto verde mentre la riga
 *    non veniva creata, e un altro ha impiegato quattro tentativi a dire *perché* falliva.
 * 2. **L'utente non ha niente da riferirci.** «Non funziona» è tutto ciò che può scrivere sul forum,
 *    e nel nostro log ci sono cento righe di quel giorno senza modo di collegarle alla sua.
 *
 * ## Cosa fa
 *
 * - **Nei test** rilancia l'eccezione: chi sta lavorando deve vedere il motivo vero, subito,
 *   invece di un messaggio tradotto. **Non in `local`**, che è l'ambiente con cui esce ogni
 *   installazione seguendo il README: lì l'utente vedrebbe una pagina d'errore al posto del banner.
 * - Con **`APP_DEBUG`** attivo il motivo vero viene aggiunto al riferimento mostrato, così chi
 *   sviluppa non deve andarlo a cercare nel log.
 * - In **produzione** registra l'eccezione con un **riferimento breve** e restituisce quel
 *   riferimento, perché il messaggio mostrato all'utente possa contenerlo: chi segnala scrive sei
 *   caratteri e noi troviamo la riga esatta.
 *
 * Non cambia il comportamento visibile: l'operazione fallita resta fallita e l'utente torna indietro
 * con un messaggio. Cambia solo che adesso quel messaggio è collegabile a una riga di log.
 */
final class ErroriDiagnosticabili
{
    /**
     * Registra l'eccezione e torna il riferimento da mostrare all'utente.
     *
     * @param  array<string, mixed>  $contesto
     *
     * @throws Throwable durante l'esecuzione dei test (non in `local`: vedi il riquadro in testa)
     */
    public static function registra(Throwable $e, string $operazione, array $contesto = []): string
    {
        // ⚠️ **Solo nei test**, non in `local`. La prima stesura rilanciava anche in ambiente locale,
        // e la revisione della beta.58 ha fatto notare che `APP_ENV=local` è la configurazione con
        // cui **esce ogni installazione** seguendo il README o la guida Synology: quegli
        // amministratori avrebbero visto una pagina d'errore al posto del banner che avevano prima.
        // Un miglioramento per chi sviluppa non deve diventare un peggioramento per chi installa.
        if (app()->runningUnitTests()) {
            throw $e;
        }

        $riferimento = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        Log::error($operazione, array_merge($contesto, [
            'riferimento' => $riferimento,
            'eccezione'   => $e->getMessage(),
            'file'        => $e->getFile().':'.$e->getLine(),
            // ⚠️ La traccia era stata tolta, e la revisione l'ha rivoluta con ragione: senza, per
            // l'eccezione tipica di questa beta resta `Connection.php:857`, uguale per qualunque
            // query fallita del programma — il riferimento porta a una riga che non dice da dove
            // viene. Si tengono i primi quindici livelli: bastano ad arrivare al controller.
            'traccia'     => collect(explode("\n", $e->getTraceAsString()))->take(15)->implode("\n"),
        ]));

        // In sviluppo il motivo vero va mostrato: chi lavora non deve andare a cercarlo nel log.
        if (config('app.debug')) {
            return $riferimento.' — '.$e->getMessage();
        }

        return $riferimento;
    }
}
