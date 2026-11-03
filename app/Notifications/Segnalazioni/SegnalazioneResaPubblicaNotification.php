<?php

namespace App\Notifications\Segnalazioni;

use App\Enums\Permission;
use App\Helpers\RouteHelper;
use App\Models\Segnalazione;
use App\Notifications\Concerns\PulsanteSoloSeLaApre;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * L'avviso all'autore che la sua segnalazione privata è stata resa pubblica (Coda 304, decisione
 * 129.2).
 *
 * Non passa dalle preferenze di notifica, e non è una svista: è il testo dell'autore che diventa
 * visibile a tutto il palazzo, con il suo nome, per una scelta di qualcun altro. Chi non ha acceso
 * le notifiche lo deve sapere lo stesso.
 *
 * Il comando toglie la privacy, non approva e non pubblica: se la segnalazione non è ancora
 * pubblicata e approvata, la mail dice che i vicini la vedranno quando lo sarà, non che la vedono
 * ora, e la prima riga dice che non è più privata invece di «ha reso pubblica». Lo stato si legge
 * quando la mail si compone, cioè quello vero al momento dell'invio.
 *
 * Tolta la privacy, l'autore che non è più nel palazzo non la apre più dal portale: la mail allora
 * parte senza il pulsante e lo dice (`PulsanteSoloSeLaApre`).
 */
class SegnalazioneResaPubblicaNotification extends LocalizedNotification implements ShouldQueue
{
    use PulsanteSoloSeLaApre;
    use Queueable;
    use TestoScrittoDaAltri;

    public function __construct(
        public Segnalazione $segnalazione,
        public string $chiLHaResaPubblica,
        public string $condominio,
    ) {
        parent::__construct();
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $routePrefix = RouteHelper::getRoutePrefixForUser($notifiable);
        $laVedonoGia = $this->segnalazione->is_published && $this->segnalazione->is_approved;

        $mail = (new MailMessage)
            ->subject($laVedonoGia
                ? __('notifications.ticket_made_public.subject')
                : __('notifications.ticket_made_public.subject_pending'))
            ->greeting(__('notifications.ticket_made_public.greeting', [
                'name' => self::nomeComeTesto($notifiable->name ?? $notifiable->nome),
            ]))
            ->line(__($laVedonoGia ? 'notifications.ticket_made_public.line_1' : 'notifications.ticket_made_public.line_1_pending', [
                'user' => self::nomeComeTesto($this->chiLHaResaPubblica),
            ]))
            ->line('**' . __('notifications.ticket_made_public.object') . ':** ' . self::testoComeTesto($this->segnalazione->subject))
            ->line(__($laVedonoGia ? 'notifications.ticket_made_public.line_2' : 'notifications.ticket_made_public.line_2_pending', [
                'condominio' => $this->condominio,
            ]));

        return $this->pulsanteOMotivo(
            $mail,
            $notifiable,
            $this->segnalazione,
            __('notifications.ticket_made_public.action'),
            url("/{$routePrefix}/segnalazioni/{$this->segnalazione->id}"),
            Permission::VIEW_SEGNALAZIONI->value,
            $this->condominio,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [];
    }
}
