<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Notifications\Assemblee;

use App\Models\Gestionale\RegistroInvio;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\Ricevute\ErroreDiPosta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Il promemoria ai condòmini convocati quando l'assemblea è sospesa e prosegue in un'altra data dell'avviso (decisione 595 (1)): l'oggetto e il testo stanno
 * nella riga del registro degli invii (`dati.oggetto`, `dati.righe`), che porta il gettone come le lettere: il registro delle email la porta a «inviata» quando
 * il server accetta il messaggio. Non è una convocazione, e il messaggio lo dice.
 */
class PromemoriaDellAssembleaNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    /** @param array<int, int> $invii la riga del registro che il messaggio porta */
    public function __construct(public array $invii = [])
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
            RegistroInvio::whereIn('id', $this->invii ?: [0])->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => EmissioneRicevute::NON_PARTITO_SENZA_SERVER]);

            return false;
        }
        $this->invii = RegistroInvio::whereIn('id', $this->invii ?: [0])->where('stato', RegistroInvio::STATO_IN_CODA)->pluck('id')->all();

        return $this->invii !== [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $riga = RegistroInvio::query()->findOrFail($this->invii[0]);
        $dati = (array) $riga->dati;
        $messaggio = (new MailMessage)->subject(self::testoComeTesto((string) ($dati['oggetto'] ?? '')))
            ->greeting('Gentile ' . self::nomeComeTesto((string) ($dati['persona']['nome'] ?? '')) . ',');
        foreach ((array) ($dati['righe'] ?? []) as $r) {
            $messaggio->line(self::testoComeTesto((string) $r));
        }
        $studio = EmissioneLettere::studio();
        if ($studio['email'] !== null && filter_var($studio['email'], FILTER_VALIDATE_EMAIL) !== false) {
            $messaggio->replyTo($studio['email'], $studio['nome']);
        }

        return $messaggio->salutation(self::testoComeTesto(($studio['nome'] ?? 'L\'amministratore') . ', amministratore'));
    }

    public function failed(\Throwable $e): void
    {
        RegistroInvio::whereIn('id', $this->invii ?: [0])->where('stato', RegistroInvio::STATO_IN_CODA)->update([
            'stato' => RegistroInvio::STATO_NON_PARTITA,
            'errore' => ErroreDiPosta::daEccezione($e),
        ]);
    }
}
