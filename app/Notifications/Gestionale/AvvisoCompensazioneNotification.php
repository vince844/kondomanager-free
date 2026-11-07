<?php

namespace App\Notifications\Gestionale;

use App\Models\Gestionale\RegistroInvio;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Services\Gestionale\Ricevute\AvvisiCompensazione;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\PDF\PdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Un avviso di compensazione per email: un messaggio a un indirizzo, con l'avviso in PDF fatto dalla riga del registro (l'istantanea di
 * quando è stato emesso), sullo stesso disegno della ricevuta: la riga porta un gettone, che è l'identificativo della notifica, e il registro delle
 * email la porta a «inviata» quando il server accetta il messaggio.
 *
 * Non parte senza un server di posta, e non parte se l'incasso è stato annullato fra l'accodamento e la partenza: la compensazione non c'è più.
 */
class AvvisoCompensazioneNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    /** Quello che dice la riga di un avviso il cui incasso è stato annullato fra l'accodamento e la partenza. */
    public const ANNULLATO_PRIMA_DELL_INVIO = 'L\'incasso è stato annullato prima dell\'invio: l\'avviso di compensazione non è partito.';

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
        $righe = RegistroInvio::with('documentabile')->whereIn('id', $this->invii ?: [0])->where('stato', RegistroInvio::STATO_IN_CODA)->get();
        $annullate = $righe->filter(fn (RegistroInvio $r) => $r->documentabile === null || $r->documentabile->stato === 'annullata')->pluck('id');
        if ($annullate->isNotEmpty()) {
            RegistroInvio::whereIn('id', $annullate)->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => self::ANNULLATO_PRIMA_DELL_INVIO]);
        }
        $this->invii = $righe->pluck('id')->diff($annullate)->values()->all();

        return $this->invii !== [];
    }

    /** Il testo dell'email: il nome del condominio con la parola davanti una volta sola, come la lettera («del Condominio Le Rose»). */
    public static function testo(string $condominio): string
    {
        return 'In allegato l\'avviso del ' . self::testoComeTesto(TestiLettere::condominioConNome($condominio, 'condominio'))
            . ': un credito è stato usato per le sue rate, senza un versamento nuovo. Le rate e l\'importo sono nel PDF.';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $riga = RegistroInvio::with('condominio')->findOrFail($this->invii[0]);
        $dati = (array) $riga->dati;
        $pdf = app(AvvisiCompensazione::class)->rendi($riga->condominio, [$dati]);
        RegistroInvio::whereKey($riga->id)->update(['impronta' => hash('sha256', $pdf)]);
        $condominio = self::testoComeTesto((string) $riga->condominio->nome);
        $nome = $dati['persona']['nome'] ?? null;

        return (new MailMessage)
            ->subject($condominio . ' — ' . AvvisiCompensazione::TITOLO)
            ->greeting(filled($nome) ? 'Gentile ' . self::nomeComeTesto($nome) . ',' : 'Buongiorno,')
            ->line(self::testo((string) $riga->condominio->nome))
            ->salutation('Cordiali saluti')
            ->attachData($pdf, PdfService::nomeFile('avviso-di-compensazione', (string) $riga->condominio->nome, (string) ($dati['emesso_il'] ?? ''),
                \Illuminate\Support\Str::slug((string) $nome)), ['mime' => 'application/pdf']);
    }

    /** Un errore mentre il messaggio parte: le righe ancora in coda diventano «non partita», con l'errore. */
    public function failed(\Throwable $e): void
    {
        if ($this->invii === []) {
            return;
        }
        RegistroInvio::whereIn('id', $this->invii)->where('stato', RegistroInvio::STATO_IN_CODA)->update([
            'stato'  => RegistroInvio::STATO_NON_PARTITA,
            'errore' => \App\Services\Gestionale\Ricevute\ErroreDiPosta::daEccezione($e),
        ]);
    }
}
