<?php

namespace App\Listeners\Notifiche;

use App\Enums\NotificationType;
use App\Events\Notifiche\DestinatariDaAvvisare;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\Segnalazione;
use App\Notifications\Comunicazioni\NewComunicazioneNotification;
use App\Notifications\Comunicazioni\UpdatedComunicazioneNotification;
use App\Notifications\Documenti\NewDocumentoNotification;
use App\Notifications\Documenti\UpdatedDocumentoNotification;
use App\Notifications\Segnalazioni\NewSegnalazioneNotification;
use App\Notifications\Segnalazioni\UpdatedSegnalazioneNotification;
use App\Traits\FilterByNotificationPreference;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Manda l'avviso giusto al gruppo giusto.
 *
 * ⚠️ **Il filtro delle preferenze resta qui**, come nei listener di creazione: chi ha spento quel
 * tipo di notifica non la riceve, e la stessa persona resta comunque «destinataria» agli occhi di
 * `DestinatariNotifica`. Se il filtro stesse là, chi ha le notifiche spente risulterebbe *nuovo* a
 * ogni modifica, per sempre.
 */
class AvvisaIDestinatari implements ShouldQueue
{
    use FilterByNotificationPreference;

    /**
     * Per ogni entità: la preferenza da rispettare, e le due notifiche.
     *
     * ⚠️ **Un modello che non è qui dentro fa sollevare**, non passare oltre. È la lezione che
     * questa beta sta chiudendo: un ramo mancante che restituisce «nessuno» è indistinguibile da
     * «nessuno da avvisare», quindi nessun test diventa rosso e il difetto vive per mesi.
     *
     * ⚠️ **Le due preferenze sono distinte, e non è un dettaglio.** Nella prima stesura l'avviso di
     * modifica viaggiava sulla preferenza di «nuova comunicazione»: chi voleva sapere delle nuove
     * si sarebbe trovato anche gli aggiornamenti, senza nessun modo di distinguerli. Sono due cose
     * diverse — una è un fatto nuovo, l'altra una correzione a qualcosa che hai già letto — e chi
     * riceve deve poter spegnere la seconda tenendo la prima.
     *
     * @return array{0: string, 1: class-string, 2: string, 3: class-string}
     */
    private function mappaturaPer(object $oggetto): array
    {
        return match (true) {
            $oggetto instanceof Comunicazione => [
                NotificationType::NEW_COMMUNICATION->value,
                NewComunicazioneNotification::class,
                NotificationType::UPDATED_COMMUNICATION->value,
                UpdatedComunicazioneNotification::class,
            ],
            $oggetto instanceof Segnalazione => [
                NotificationType::NEW_TICKET->value,
                NewSegnalazioneNotification::class,
                NotificationType::UPDATED_TICKET->value,
                UpdatedSegnalazioneNotification::class,
            ],
            $oggetto instanceof Documento => [
                NotificationType::NEW_ARCHIVE_DOCUMENT->value,
                NewDocumentoNotification::class,
                NotificationType::UPDATED_ARCHIVE_DOCUMENT->value,
                UpdatedDocumentoNotification::class,
            ],
            default => throw new \InvalidArgumentException(
                'AvvisaIDestinatari non conosce '.$oggetto::class.
                ': aggiungere la riga qui, non lasciare che l\'avviso sparisca in silenzio.'
            ),
        };
    }

    public function handle(DestinatariDaAvvisare $event): void
    {
        if ($event->anagraficaIds === []) {
            return;
        }

        try {
            [$prefNuovo, $classeNuovo, $prefAggiornato, $classeAggiornato] = $this->mappaturaPer($event->oggetto);

            $aggiornamento = $event->motivo === 'aggiornato';

            $anagrafiche = $this->filterByNotificationPreference(
                Anagrafica::whereIn('id', $event->anagraficaIds),
                $aggiornamento ? $prefAggiornato : $prefNuovo
            )->get()->unique('email')->values();

            if ($anagrafiche->isEmpty()) {
                return;
            }

            $classe = $aggiornamento ? $classeAggiornato : $classeNuovo;

            Notification::send($anagrafiche, new $classe($event->oggetto));

        } catch (\Exception $e) {
            // Stesso trattamento dei listener di creazione: l'avviso non deve far fallire la
            // richiesta che l'ha originato. La differenza è che qui il log dice anche il motivo,
            // perché «nuovo» e «aggiornato» hanno cause di guasto diverse.
            Log::error(sprintf(
                'AvvisaIDestinatari (%s, motivo %s) id %s: %s',
                $event->oggetto::class,
                $event->motivo,
                $event->oggetto->id ?? '?',
                $e->getMessage()
            ));
        }
    }
}
