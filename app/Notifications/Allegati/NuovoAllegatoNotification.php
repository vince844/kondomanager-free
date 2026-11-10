<?php

namespace App\Notifications\Allegati;

use App\Helpers\RouteHelper;
use App\Models\Documento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Support\Allegati\RegoleAllegati;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class NuovoAllegatoNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    /**
     * @param  array<int, int>     $allegatoIds
     * @param  array<int, string>  $nomiFile
     */
    public function __construct(
        public int $segnalazioneId,
        public string $oggettoSegnalazione,
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

        $segnalazione = Segnalazione::find($this->segnalazioneId);
        if (! $segnalazione) {
            return false;
        }

        $righe = Documento::query()
            ->whereIn('id', $this->allegatoIds)
            ->where('documentable_type', $segnalazione->getMorphClass())
            ->where('documentable_id', $segnalazione->id)
            ->where('is_published', true)
            ->where('is_approved', true)
            ->get();

        return $righe->contains(fn (Documento $d) => RegoleAllegati::puoVedere($notifiable, $d, $segnalazione));
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $prefix = RouteHelper::getRoutePrefixForUser($notifiable);
        $url = url("/{$prefix}/segnalazioni/{$this->segnalazioneId}");
        $titolo = self::testoComeTesto($this->oggettoSegnalazione);
        $autore = self::nomeComeTesto($this->nomeAutore);

        $nomi = $this->nomiAttuali();
        $elenco = implode(', ', array_map(fn (string $n) => self::nomeComeTesto($n), $nomi));

        return (new MailMessage)
            ->subject("Nuovo allegato nella segnalazione «{$titolo}»")
            ->greeting('Ciao!')
            ->line("{$autore} ha aggiunto nuovi allegati nella segnalazione «{$titolo}»:")
            ->line($elenco)
            ->action('Apri la segnalazione', $url);
    }

    /**
     * @return array<int, string>
     */
    private function nomiAttuali(): array
    {
        $daDb = Documento::query()
            ->whereIn('id', $this->allegatoIds)
            ->orderBy('id')
            ->pluck('name')
            ->map(fn ($n) => (string) $n)
            ->all();

        return $daDb !== [] ? $daDb : $this->nomiFile;
    }
}

