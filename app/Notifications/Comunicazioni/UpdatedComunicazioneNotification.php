<?php

namespace App\Notifications\Comunicazioni;

use App\Helpers\RouteHelper;
use App\Models\Comunicazione;
use App\Notifications\LocalizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/**
 * «Questa comunicazione è cambiata» — e non «ne è arrivata una nuova».
 *
 * ⚠️ **Serve una classe a parte e non basta riusare quella di creazione.** Chi riceve questo avviso
 * la comunicazione l'ha già letta: scrivergli «nuova comunicazione» sarebbe falso, e alla seconda
 * volta smetterebbe di aprirle. La differenza fra i due avvisi è tutta nel testo, ed è il motivo
 * per cui esistono due classi invece di un parametro.
 *
     * ⚠️ **Il testo nomina chi ha MODIFICATO l'oggetto, e questo è possibile solo dalla beta.64.**
     * Le tre tabelle avevano `created_by` e non `updated_by`, quindi la prima stesura di questo
     * avviso era costretta a nominare il **creatore** — cioè a dire una cosa falsa su chi aveva
     * fatto cosa, in una mail che arriva a tutto il condominio. La colonna è stata aggiunta
     * (`2026_08_22_090000_add_updated_by_...`) e adesso l'avviso dice il vero.
     *
     * Il ripiego su `createdBy` resta per le righe modificate **prima** dell'aggiornamento, che
     * hanno `updated_by` a `null`. In pratica non si incontra — l'avviso parte da una modifica, e
     * ogni modifica da qui in avanti valorizza la colonna — ma un `null` non deve far fallire
     * l'invio di una mail.
     *
 * Parte **solo se l'amministratore lo chiede**, spuntando la casella in modifica: correggere un
 * refuso non deve mandare una mail a tutto il condominio. È il principio che il prodotto applica
 * dappertutto — l'ultima parola a chi firma.
 */
class UpdatedComunicazioneNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;

    public $comunicazione;

    public function __construct(Comunicazione $comunicazione)
    {
        parent::__construct();
        $this->comunicazione = $comunicazione;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $routePrefix = RouteHelper::getRoutePrefixForUser($notifiable);

        return (new MailMessage)
            ->subject(__('notifications.updated_communication.subject'))
            ->greeting(__('notifications.updated_communication.greeting', [
                'name' => $notifiable->name ?? $notifiable->nome,
            ]))
            ->line(__('notifications.updated_communication.line_1', [
                'user' => $this->comunicazione->updatedBy?->name ?? $this->comunicazione->createdBy->name,
            ]))
            ->line('**' . __('notifications.updated_communication.object') . ':** ' . $this->comunicazione->subject)
            ->line('**' . __('notifications.updated_communication.priority') . ':** ' . Str::ucfirst($this->comunicazione->priority))
            ->action(
                __('notifications.updated_communication.action'),
                url("/{$routePrefix}/comunicazioni/{$this->comunicazione->id}")
            );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
