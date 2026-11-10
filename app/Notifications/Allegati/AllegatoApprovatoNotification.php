<?php

namespace App\Notifications\Allegati;

use App\Helpers\RouteHelper;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Support\Allegati\RegoleAllegati;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class AllegatoApprovatoNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    /**
     * @param  array<int, int>     $allegatoIds
     * @param  array<int, string>  $nomiFile
     */
    public function __construct(
        public string $tipo,
        public int $elementoId,
        public string $oggettoElemento,
        public array $allegatoIds,
        public array $nomiFile = [],
    ) {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (! $notifiable instanceof User) {
            return false;
        }

        $elemento = $this->tipo === 'segnalazioni'
            ? Segnalazione::find($this->elementoId)
            : Comunicazione::find($this->elementoId);

        if (! $elemento) {
            return false;
        }

        $righe = Documento::query()
            ->whereIn('id', $this->allegatoIds)
            ->where('documentable_type', $elemento->getMorphClass())
            ->where('documentable_id', $elemento->id)
            ->where('is_published', true)
            ->where('is_approved', true)
            ->get();

        return $righe->contains(fn (Documento $d) => RegoleAllegati::puoVedere($notifiable, $d, $elemento));
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $prefix = RouteHelper::getRoutePrefixForUser($notifiable);
        $url = url("/{$prefix}/{$this->tipo}/{$this->elementoId}");
        $titolo = self::testoComeTesto($this->oggettoElemento);

        return (new MailMessage)
            ->subject("Allegato approvato — «{$titolo}»")
            ->greeting('Ciao!')
            ->line("Il tuo allegato in «{$titolo}» è stato approvato.")
            ->action('Apri la scheda', $url);
    }
}

