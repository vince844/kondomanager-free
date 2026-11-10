<?php

namespace App\Notifications\Allegati;

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

class AllegatoInAttesaNotification extends LocalizedNotification implements ShouldQueue
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
        public string $nomeAutore = 'Utente',
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
            ->where('is_approved', false)
            ->get();

        return $righe->contains(fn (Documento $d) => RegoleAllegati::puoApprovare($notifiable, $d, $elemento));
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $url = url("/admin/{$this->tipo}/{$this->elementoId}");
        $titolo = self::testoComeTesto($this->oggettoElemento);
        $autore = self::nomeComeTesto($this->nomeAutore);

        return (new MailMessage)
            ->subject("Allegati in attesa di approvazione — «{$titolo}»")
            ->greeting('Ciao!')
            ->line("{$autore} ha caricato allegati che richiedono approvazione in «{$titolo}».")
            ->action('Apri la scheda', $url);
    }
}

