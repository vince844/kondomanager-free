<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Notifications\Assemblee;

use App\Models\Gestionale\RegistroInvio;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\Ricevute\ErroreDiPosta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Il promemoria per email a chi ha preparato l'assemblea: la convocazione è da spedire entro il giorno limite del compito
 * ({@see \App\Services\Assemblee\Convocazione\PromemoriaDellaConvocazione}). L'oggetto, le righe e l'indirizzo della pagina della convocazione stanno nella
 * riga del registro degli invii, che porta il gettone: il registro delle email la porta a «inviata» quando il server accetta il messaggio. Un'email del
 * programma, con il modello delle altre: le righe, il pulsante «Apri la convocazione», la riga che dice dove si spegne.
 */
class ConvocazioneDaSpedireNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    public function __construct(public int $invio)
    {
        parent::__construct();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (EmissioneRicevute::senzaServerPosta()) {
            RegistroInvio::whereKey($this->invio)->where('stato', RegistroInvio::STATO_IN_CODA)
                ->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => EmissioneRicevute::NON_PARTITO_SENZA_SERVER]);

            return false;
        }

        return RegistroInvio::whereKey($this->invio)->where('stato', RegistroInvio::STATO_IN_CODA)->exists();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dati = (array) RegistroInvio::query()->findOrFail($this->invio)->dati;
        $messaggio = (new MailMessage)->subject(self::testoComeTesto((string) ($dati['oggetto'] ?? '')));
        foreach ((array) ($dati['righe'] ?? []) as $riga) {
            $messaggio->line(self::testoComeTesto((string) $riga));
        }
        if (filled($dati['url'] ?? null)) {
            $messaggio->action('Apri la convocazione', (string) $dati['url']);
        }

        return $messaggio->line(self::testoComeTesto((string) ($dati['chiusura'] ?? '')));
    }

    public function failed(\Throwable $e): void
    {
        RegistroInvio::whereKey($this->invio)->where('stato', RegistroInvio::STATO_IN_CODA)->update([
            'stato' => RegistroInvio::STATO_NON_PARTITA,
            'errore' => ErroreDiPosta::daEccezione($e),
        ]);
    }
}
