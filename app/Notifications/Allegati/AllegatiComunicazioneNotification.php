<?php

namespace App\Notifications\Allegati;

use App\Helpers\RouteHelper;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\User;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Support\Allegati\RegoleAllegati;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class AllegatiComunicazioneNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    /**
     * @param  array<int, int>     $allegatoIds
     * @param  array<int, string>  $nomiFile
     */
    public function __construct(
        public int $comunicazioneId,
        public string $oggettoComunicazione,
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

        $comunicazione = Comunicazione::find($this->comunicazioneId);
        if (! $comunicazione || ! ($comunicazione->is_published && $comunicazione->is_approved)) {
            return false;
        }

        $righe = Documento::query()
            ->whereIn('id', $this->allegatoIds)
            ->where('documentable_type', $comunicazione->getMorphClass())
            ->where('documentable_id', $comunicazione->id)
            ->where('is_published', true)
            ->where('is_approved', true)
            ->get();

        return $righe->contains(fn (Documento $d) => RegoleAllegati::puoVedere($notifiable, $d, $comunicazione));
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $prefix = RouteHelper::getRoutePrefixForUser($notifiable);
        $url = url("/{$prefix}/comunicazioni/{$this->comunicazioneId}");
        $titolo = self::testoComeTesto($this->oggettoComunicazione);

        return (new MailMessage)
            ->subject("Nuovi allegati nella comunicazione «{$titolo}»")
            ->greeting('Ciao!')
            ->line("Sono stati aggiunti nuovi allegati alla comunicazione «{$titolo}».")
            ->action('Apri la comunicazione', $url);
    }
}

