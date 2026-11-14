<?php

namespace App\Notifications\Gestionale;

use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\InvioDeiFogli;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\Ricevute\ErroreDiPosta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Il foglio del conguaglio per email: un messaggio a un indirizzo, con il foglio della persona in PDF. È la sorella della lettera, sullo stesso
 * disegno: la riga del registro porta un gettone, che è l'identificativo della notifica, e il registro delle email la porta a «inviata» quando il
 * server accetta il messaggio; se parte con errore la riga è «non partita», con l'errore.
 *
 * - **Il PDF** è quello conservato all'invio, sempre lo stesso a ogni tentativo: il foglio dice la data e l'ora del suo calcolo, e il messaggio
 *   anche. Non c'è la guardia alla partenza delle lettere: il foglio non chiede un pagamento, è una proposta datata.
 * - **Il testo** è l'oggetto e il testo scritti nella finestra, con i campi riempiti; poi, fuori dal testo che si cambia, la frase della proposta
 *   ({@see InvioDeiFogli::FRASE_PROPOSTA}), per chi ha un foglio con una parte non ancora calcolata la sua riga ({@see InvioDeiFogli::FRASE_NON_PRONTO}) e la
 *   riga del calcolo ({@see InvioDeiFogli::rigaDelCalcolo()}). La chiusura dice chi scrive: lo studio, amministratore del condominio.
 *   Il saluto e la chiusura come le lettere; la risposta va all'email dello studio, se c'è nelle impostazioni di stampa.
 * - Senza un server di posta il messaggio non parte.
 */
class FoglioConguaglioNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    /** @param  array<int, int>  $invii  la riga del registro che il messaggio porta */
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
        $riga = RegistroInvio::with('condominio')->findOrFail($this->invii[0]);
        $dati = (array) $riga->dati;
        $testo = self::testo((string) $riga->condominio?->nome, (string) ($dati['persona']['nome'] ?? ''), $dati);
        $messaggio = (new MailMessage)->subject($testo['oggetto'])->greeting($testo['saluto']);
        foreach ($testo['righe'] as $r) {
            $messaggio->line($r);
        }
        $studio = EmissioneLettere::studio();
        if ($studio['email'] !== null && filter_var($studio['email'], FILTER_VALIDATE_EMAIL) !== false) {
            $messaggio->replyTo($studio['email'], $studio['nome']);
        }

        return $messaggio->salutation($testo['chiusura'])
            ->attachData($this->pdfDellaRiga($riga), (string) ($dati['allegato'] ?? 'conguaglio.pdf'), ['mime' => 'application/pdf']);
    }

    /**
     * Il messaggio: l'oggetto, il saluto, le righe del testo, la frase della proposta e la riga del calcolo, la chiusura. Il foglio sta nel PDF.
     *
     * @param  array{oggetto?: string, testo?: string, alla?: ?string, finito?: ?bool, calcolato_il?: ?string}  $dati
     * @return array{oggetto: string, saluto: string, righe: list<string>, chiusura: string}
     */
    public static function testo(string $condominio, string $nome, array $dati): array
    {
        $studio = EmissioneLettere::studio();
        // Le righe del testo senza i segni di un blocco di codice (l'accento grave, una riga che comincia con «~~~»): un blocco aperto nel testo
        // prenderebbe la frase della proposta e la riga del calcolo, che vengono dopo.
        $righe = array_values(array_filter(array_map(fn ($r) => preg_match('/^\s*~~~/u', $r) === 1 ? '' : self::testoComeTesto(str_replace('`', '', $r)),
            explode("\n", (string) ($dati['testo'] ?? ''))), fn ($r) => $r !== ''));

        return [
            'oggetto'  => trim((string) preg_replace('/\s+/u', ' ', (string) ($dati['oggetto'] ?? ''))),
            'saluto'   => trim($nome) !== '' ? 'Gentile ' . self::nomeComeTesto($nome) . ',' : 'Buongiorno,',
            // Fuori dal testo che si cambia: la frase della proposta, la riga di chi ha una parte del foglio non ancora calcolata, l'ora del calcolo.
            'righe'    => [...$righe, InvioDeiFogli::FRASE_PROPOSTA, ...(! empty($dati['non_disponibile']) ? [InvioDeiFogli::FRASE_NON_PRONTO] : []),
                InvioDeiFogli::rigaDelCalcolo($dati)],
            // Chi scrive: lo studio, amministratore del condominio; senza lo studio nelle impostazioni di stampa, l'amministratore.
            'chiusura' => 'Cordiali saluti, ' . self::testoComeTesto(($studio['nome'] !== null ? $studio['nome'] . ', amministratore' : 'l\'amministratore')
                . ' del ' . TestiLettere::condominioConNome($condominio, 'condominio')),
        ];
    }

    /** Il PDF conservato all'invio: lo stesso a ogni tentativo. */
    private function pdfDellaRiga(RegistroInvio $riga): string
    {
        $documento = isset($riga->dati['documento_id']) ? Documento::find((int) $riga->dati['documento_id']) : null;
        $contenuto = $documento === null ? null : app(ArchivioDocumenti::class)->leggi($documento->path);
        if ($contenuto === null) {
            throw new \RuntimeException('Il PDF conservato del foglio non si trova sul disco dei documenti.');
        }

        return $contenuto;
    }

    public function failed(\Throwable $e): void
    {
        RegistroInvio::whereIn('id', $this->invii ?: [0])->where('stato', RegistroInvio::STATO_IN_CODA)->update([
            'stato'  => RegistroInvio::STATO_NON_PARTITA,
            'errore' => ErroreDiPosta::daEccezione($e),
        ]);
    }
}
