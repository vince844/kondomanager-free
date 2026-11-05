<?php

namespace App\Listeners;

use App\Models\MailLog;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class LogSentMessage
{
    /**
     * Gestisce l'evento di invio mail.
     */
    public function handle(MessageSent $event): void
    {
        try {
            // In Laravel 12, $event->message è un oggetto Symfony\Component\Mime\Email
            $message = $event->message;

            // 1. Estrazione del Destinatario
            // Symfony Mailer restituisce un array di oggetti Address
            $to = $message->getTo();
            $recipient = 'Sconosciuto';

            if (is_array($to) && count($to) > 0) {
                // Prendiamo il primo destinatario della lista
                $recipient = $to[0]->getAddress();
            }

            // 2. Determiniamo il driver utilizzato (SMTP o Log)
            // Se il nostro Provider ha lavorato bene, qui avremo 'smtp' o 'log'
            $driver = Config::get('mail.default');
            
            // 3. Salvataggio nel Database
            $log = MailLog::create([
                'recipient' => $recipient,
                'subject'   => $message->getSubject() ?? '(Nessun oggetto)',
                'mailer'    => $driver,
                // Se il driver è 'log', segniamo lo stato come 'logged' (simulato)
                'status'    => ($driver === 'log') ? 'logged' : 'sent',
                'sent_at'   => now(),
            ]);

        } catch (\Throwable $e) {
            // Se il salvataggio del log fallisce, NON dobbiamo bloccare l'invio della mail.
            // Scriviamo l'errore nel file di log di sistema (storage/logs/laravel.log)
            Log::error("Errore salvataggio MailLog: " . $e->getMessage());
        }

        $this->aggiornaIlRegistroDegliInvii($event, $log ?? null);
    }

    /**
     * Le righe del registro degli invii di questo messaggio: quelle che hanno nel gettone l'identificativo della notifica che lo ha generato
     * (`__laravel_notification_id` nei dati dell'evento). Il server ha accettato il messaggio, quindi «inviata», con il legame a questa riga del
     * registro delle email. «Inviata» non vuol dire «consegnata». Con il trasporto `log` il messaggio non è partito: la riga resta «non
     * partita», e lo dice.
     *
     * Solo le righe ancora in coda: una ricevuta tolta dal messaggio prima della partenza (il suo incasso è stato annullato) ha già la sua riga
     * «non partita», e non nel messaggio che parte; il gettone è di tutte le righe del messaggio e non deve riportarla a «inviata».
     */
    private function aggiornaIlRegistroDegliInvii(MessageSent $event, ?MailLog $log): void
    {
        try {
            $gettone = $event->data['__laravel_notification_id'] ?? null;
            if (! is_string($gettone) || $gettone === '') {
                return;
            }

            $senzaServer = Config::get('mail.default') === 'log';
            RegistroInvio::where('dati->gettone', $gettone)->where('stato', RegistroInvio::STATO_IN_CODA)->update([
                'stato'       => $senzaServer ? RegistroInvio::STATO_NON_PARTITA : RegistroInvio::STATO_INVIATA,
                'errore'      => $senzaServer ? EmissioneRicevute::NON_PARTITO_SENZA_SERVER : null,
                'mail_log_id' => $log?->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Errore aggiornamento del registro degli invii: ' . $e->getMessage());
        }
    }
}
