<?php

namespace App\Notifications\Users;

use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * L'avviso al titolare di un account quando cambia qualcosa della sua sicurezza (Coda 267): password
 * cambiata o reimpostata, email cambiata, autenticazione a due fattori attivata o disattivata, codici
 * di recupero rigenerati. Lo spedisce solo `App\Services\Notifiche\AvvisiDiSicurezza`, che sceglie il
 * destinatario e non lascia che un invio fallito fermi l'azione.
 *
 * Nel testo ci sono data e ora e, quando la modifica non l'ha fatta il titolare, il nome di chi l'ha
 * fatta. L'indirizzo IP no: è un dato personale di chi ha fatto la modifica, e su un'installazione
 * condivisa finirebbe nella casella di un altro. Nessun link: l'avviso dice che cosa fare, non porta
 * da nessuna parte.
 *
 * I due nomi li scrive qualcun altro — chi ha fatto la modifica sceglie il proprio dal profilo, il nome
 * del titolare lo corregge chi gestisce la sua scheda — e il messaggio è Markdown: arrivano come testo
 * semplice, su una riga e corti (`TestoScrittoDaAltri::nomeComeTesto()`, la stessa regola delle mail del
 * portale), perché nessun nome diventi un collegamento o una frase dentro un avviso che parte dal
 * gestionale (Coda 267).
 */
class AvvisoDiSicurezzaNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    /** Data e ora della modifica, nel fuso dell'utente, fissate quando l'avviso parte e non quando la coda lo spedisce. */
    public string $data;

    public string $ora;

    /**
     * @param  string  $evento  Una delle costanti di `AvvisiDiSicurezza`.
     * @param  string  $nome  Il nome del titolare, per il saluto.
     * @param  string|null  $autore  Chi ha fatto la modifica, solo se non è il titolare.
     * @param  string|null  $emailNuova  Il nuovo indirizzo, già mascherato (solo per l'email cambiata).
     */
    public function __construct(
        public string $evento,
        public string $nome,
        public ?string $autore = null,
        public ?string $emailNuova = null,
    ) {
        parent::__construct();

        $this->nome = self::nomeComeTesto($nome);
        $this->autore = $autore === null ? null : self::nomeComeTesto($autore);

        $adesso = now()->setTimezone(config('app.user_timezone', 'Europe/Rome'));
        $this->data = $adesso->format('d/m/Y');
        $this->ora = $adesso->format('H:i');
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
        $chiave = "notifications.security_alert.{$this->evento}";
        $sostituzioni = [
            'appName' => config('app.name'),
            'data'    => $this->data,
            'ora'     => $this->ora,
            'email'   => (string) $this->emailNuova,
        ];

        $mail = (new MailMessage)
            ->subject(__("{$chiave}.subject", $sostituzioni))
            ->greeting(__('notifications.security_alert.greeting', ['name' => $this->nome]))
            ->line(__("{$chiave}.line_1", $sostituzioni));

        if ($this->autore !== null) {
            $mail->line(__('notifications.security_alert.autore', ['autore' => $this->autore]));
        }

        return $mail
            ->line(__("{$chiave}.consiglio"))
            ->line(__('notifications.security_alert.sempre'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['evento' => $this->evento];
    }
}
