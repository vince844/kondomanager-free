<?php

use App\Models\Anagrafica;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * I fatti delle librerie su cui poggia la ricevuta dell'incasso, provati sul codice installato (Laravel 13, Symfony Mailer, mPDF),
 * prima di costruirci sopra:
 *
 * 1. una notifica in coda porta un PDF allegato, con il suo nome, fino al messaggio che il trasporto riceve;
 * 2. l'identificativo che si dà alla notifica (`$notifica->id`) arriva all'evento `MessageSent` nei dati (`__laravel_notification_id`), anche per una
 *    notifica in coda: è il legame fra le righe del registro e il messaggio che parte, senza intestazioni da mostrare a chi riceve;
 * 3. se il trasporto rifiuta il messaggio, con la coda `sync` dei test scattano tutti e due: l'evento `NotificationFailed` e il `failed()`
 *    della notifica, con l'errore; l'eccezione risale a chi ha chiamato `notify()`;
 * 4. mPDF scrive più pagine da un HTML solo con `<pagebreak />`, e il numero delle pagine si legge dal documento;
 * 5. mPDF mette un blocco con `position: absolute`, scritto al primo livello (fuori da ogni altro blocco), alle coordinate misurate dal bordo
 *    della pagina e sulla pagina in cui si trova nel flusso: è così che il riquadro dell'indirizzo sta nella finestra della busta.
 *
 * La notifica di prova è una classe con un nome suo, perché una notifica in coda si serializza e una classe anonima no.
 */
class NotificaDiProvaAllegatoRicevuta extends Notification implements ShouldQueue
{
    use Queueable;

    public static ?string $errore = null;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Prova')
            ->line('Riga')
            ->attachData('%PDF-1.4 finto', 'ricevuta-prova.pdf', ['mime' => 'application/pdf']);
    }

    public function failed(\Throwable $e): void
    {
        static::$errore = $e->getMessage();
    }
}

function personaDiProvaFattiLibreria(): Anagrafica
{
    return Anagrafica::create([
        'nome' => 'Ida Prova', 'indirizzo' => 'Via Uno 1', 'email' => 'ida@prova.it', 'email_secondaria' => 'ida2@prova.it',
        'codice_fiscale' => strtoupper(Str::random(16)),
    ]);
}

test('una notifica in coda porta il PDF allegato con il suo nome fino al trasporto, a tutti e due gli indirizzi in un messaggio solo', function () {
    config(['mail.default' => 'array']);

    personaDiProvaFattiLibreria()->notify(new NotificaDiProvaAllegatoRicevuta);

    $messaggi = app('mailer')->getSymfonyTransport()->messages();
    expect($messaggi)->toHaveCount(1);

    /** @var Email $email */
    $email = $messaggi[0]->getOriginalMessage();
    $allegati = $email->getAttachments();

    expect($allegati)->toHaveCount(1)
        ->and($allegati[0]->getFilename())->toBe('ricevuta-prova.pdf')
        ->and($allegati[0]->getContentType())->toBe('application/pdf')
        ->and($allegati[0]->getBody())->toBe('%PDF-1.4 finto')
        ->and(collect($email->getTo())->map->getAddress()->all())->toBe(['ida@prova.it', 'ida2@prova.it']);
});

test('l\'identificativo dato alla notifica arriva all\'evento MessageSent, anche per una notifica in coda, e il messaggio non porta altre intestazioni', function () {
    config(['mail.default' => 'array']);
    $letto = null;
    Event::listen(MessageSent::class, function (MessageSent $e) use (&$letto) {
        $letto = $e->data['__laravel_notification_id'] ?? null;
    });
    $notifica = new NotificaDiProvaAllegatoRicevuta;
    $notifica->id = '6f1c2d4e-0000-4000-8000-00000000abcd';

    personaDiProvaFattiLibreria()->notify($notifica);

    /** @var Email $email */
    $email = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
    expect($letto)->toBe('6f1c2d4e-0000-4000-8000-00000000abcd')
        ->and($email->getHeaders()->has('X-Registro-Invio'))->toBeFalse();
});

test('un trasporto che rifiuta il messaggio fa scattare NotificationFailed e il failed() della notifica, e l\'eccezione risale', function () {
    Mail::extend('rotto_prova_fatti', fn () => new class extends AbstractTransport {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connessione rifiutata dal server di prova');
        }

        public function __toString(): string
        {
            return 'rotto_prova_fatti';
        }
    });
    config(['mail.mailers.rotto_prova_fatti' => ['transport' => 'rotto_prova_fatti'], 'mail.default' => 'rotto_prova_fatti']);
    NotificaDiProvaAllegatoRicevuta::$errore = null;
    $evento = null;
    Event::listen(NotificationFailed::class, function (NotificationFailed $e) use (&$evento) {
        $evento = $e->data['exception']?->getMessage();
    });

    $risalita = null;
    try {
        personaDiProvaFattiLibreria()->notify(new NotificaDiProvaAllegatoRicevuta);
    } catch (\Throwable $e) {
        $risalita = $e->getMessage();
    }

    expect($evento)->toContain('Connessione rifiutata')
        ->and(NotificaDiProvaAllegatoRicevuta::$errore)->toContain('Connessione rifiutata')
        ->and($risalita)->toContain('Connessione rifiutata');
});

test('mPDF scrive una pagina per ricevuta con pagebreak, e il documento dice quante sono', function () {
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => storage_path('framework/cache/mpdf')]);
    $mpdf->WriteHTML('<p>Prima</p><pagebreak resetpagenum="1" /><p>Seconda</p><pagebreak resetpagenum="1" /><p>Terza</p>');
    $pdf = $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);

    expect(str_starts_with($pdf, '%PDF'))->toBeTrue()
        ->and(preg_match_all('/\/Type\s*\/Page[^s]/', $pdf))->toBe(3);
});

test('mPDF mette un blocco con position absolute, scritto al primo livello, alle coordinate della pagina e sulla pagina in cui si trova', function () {
    $mpdf = new \Mpdf\Mpdf(['mode' => 'c', 'format' => 'A4', 'margin_top' => 34, 'margin_left' => 12, 'margin_right' => 12, 'tempDir' => storage_path('framework/cache/mpdf')]);
    $mpdf->SetCompression(false);
    $riquadro = fn (string $testo) => '<div style="position: absolute; top: 52mm; left: 112mm; width: 76mm; height: 30mm; overflow: auto;">' . $testo . '</div>';
    $mpdf->WriteHTML('<div class="content"><p>Prima</p></div>' . $riquadro('Nomeuno') . '<div class="content"><p>Dopo</p><pagebreak /><p>Seconda</p></div>'
        . $riquadro('Nomedue') . '<div class="content"><p>Fine</p></div>');
    $pagine = testiDellePagineDelPdf($mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN));

    expect($pagine)->toHaveCount(2)
        ->and(doveStaNellaPagina($pagine[0], 'Nomedue'))->toBe([])
        ->and(doveStaNellaPagina($pagine[1], 'Nomeuno'))->toBe([]);
    foreach ([[0, 'Nomeuno'], [1, 'Nomedue']] as [$n, $testo]) {
        [[$x, $y]] = doveStaNellaPagina($pagine[$n], $testo);
        // La riga comincia sotto il bordo alto del riquadro: la linea di base sta fra 52 e 58 mm dall'alto della pagina.
        expect($x)->toBe(112.0)
            ->and($y)->toBeGreaterThan(52.0)->toBeLessThan(58.0);
    }
});
