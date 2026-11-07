<?php

namespace App\Notifications\Gestionale;

use App\Helpers\DateHelper;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Debiti\AccordiRientro;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\Gestionale\Lettere\TestiLettere;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\Ricevute\ErroreDiPosta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Una lettera per email (promemoria o sollecito): un messaggio a un indirizzo, con la lettera in PDF. È la sorella della ricevuta, sullo
 * stesso disegno: la riga del registro porta un gettone, che è l'identificativo della notifica, e il registro delle email la porta a «inviata»
 * quando il server accetta il messaggio; se parte con errore la riga è «non partita».
 *
 * - **Il PDF**: per il sollecito è il file conservato all'emissione, sempre lo stesso a ogni tentativo; per il promemoria si fa dalla riga,
 *   quando il messaggio parte, e l'impronta si scrive sulla riga.
 * - **La guardia alla partenza**: con la coda del database un messaggio può partire giorni dopo l'accodamento. Al momento della partenza la
 *   notifica rilegge le rate della lettera con la lettura dei debiti di oggi: se una qualunque è cambiata (il residuo è sceso o salito, la
 *   rata non c'è più perché la sua emissione è stata annullata, un accordo di rientro registrato dopo ne copre una parte), il messaggio non
 *   parte e la riga lo dice. «Riprova» non rimanda la stessa
 *   lettera: la rifà con le cifre di oggi. Una lettera che non parte non chiede mai una cifra che non è più vera.
 * - Senza un server di posta il messaggio non parte.
 * - **Rispondere**: se lo studio ha un'email nelle impostazioni di stampa, la risposta va lì.
 *
 * In prova («Mandane una a me») il messaggio va a chi lavora, con «PROVA» in testa al PDF, senza righe nel registro degli invii.
 */
class LetteraNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    /** Quello che dice la riga di una lettera il cui debito è cambiato fra l'accodamento e la partenza. */
    public const CAMBIATO_PRIMA_DELL_INVIO = 'Il debito della lettera è cambiato prima dell\'invio: rivedila e mandala di nuovo.';

    /** Il tipo della lettera con l'articolo, nel testo del messaggio. */
    private const CON_ARTICOLO = ['promemoria' => 'il promemoria', 'sollecito' => 'il sollecito', 'diffida' => 'la diffida', 'messa_in_mora' => 'la messa in mora'];

    /** @var array{condominio_id: int, voce: array<string, mixed>, pdf: string}|null la prova */
    public ?array $prova = null;

    /** @param array<int, int> $invii la riga del registro che il messaggio porta */
    public function __construct(public array $invii = [])
    {
        parent::__construct();
    }

    /** @param array<string, mixed> $voce */
    public static function prova(int $condominioId, array $voce, string $pdf): self
    {
        $notifica = new self([]);
        $notifica->prova = ['condominio_id' => $condominioId, 'voce' => $voce, 'pdf' => base64_encode($pdf)];

        return $notifica;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if (EmissioneRicevute::senzaServerPosta()) {
            if ($this->invii !== []) {
                RegistroInvio::whereIn('id', $this->invii)->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => EmissioneRicevute::NON_PARTITO_SENZA_SERVER]);
            }

            return false;
        }
        if ($this->prova !== null) {
            return true;
        }

        $cambiate = RegistroInvio::whereIn('id', $this->invii)->where('stato', RegistroInvio::STATO_IN_CODA)->get()
            ->filter(fn (RegistroInvio $r) => self::debitoCambiato($r))->pluck('id');
        if ($cambiate->isNotEmpty()) {
            RegistroInvio::whereIn('id', $cambiate)->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => self::CAMBIATO_PRIMA_DELL_INVIO]);
        }
        $this->invii = RegistroInvio::whereIn('id', $this->invii)->where('stato', RegistroInvio::STATO_IN_CODA)->pluck('id')->all();

        return $this->invii !== [];
    }

    /**
     * Le rate della lettera, rilette oggi: è cambiata se una ha un residuo diverso da quello della lettera o non è più nella lettura (la sua
     * emissione è stata annullata), se la persona non c'è più, o se la parte di una rata che un accordo di rientro lascia fuori dalle lettere
     * oggi non è quella della lettera (un accordo registrato, o chiuso, dopo l'accodamento). Una lettera scritta con le rate in accordo rimesse
     * dall'amministratore (`anche_in_accordo`) le chiedeva tutte, e l'accordo non la cambia. Una rata che mancava dalla lettera perché era tutta
     * in accordo non la ferma: la lettera chiede meno.
     */
    public static function debitoCambiato(RegistroInvio $riga): bool
    {
        if ($riga->anagrafica_id === null) {
            return true;
        }
        $lettura = app(LetturaDebiti::class)->perPersona((int) $riga->condominio_id, (int) $riga->anagrafica_id)['rate'];
        $oggi = collect($lettura)->mapWithKeys(fn ($r) => [$r['rata_id'] . '|' . ($r['immobile_id'] ?? '') => (int) $r['residuo']]);
        $inAccordo = ! empty($riga->dati['anche_in_accordo']) ? []
            : app(AccordiRientro::class)->fuoriDalleLettere((int) $riga->condominio_id, (int) $riga->anagrafica_id, $lettura, DateHelper::oggiUtente());
        foreach ((array) ($riga->dati['righe'] ?? []) as $r) {
            $chiave = ($r['rata_id'] ?? '') . '|' . ($r['immobile_id'] ?? '');
            if (! $oggi->has($chiave) || $oggi[$chiave] !== (int) ($r['residuo'] ?? -1)) {
                return true;
            }
            if (($inAccordo[$chiave] ?? 0) !== (int) ($r['in_accordo'] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Il nome dell'allegato porta il giorno della lettera, non quello in cui il messaggio parte (dentro la lettera dice quel giorno).
        $giorno = null;
        if ($this->prova !== null) {
            $condominio = Condominio::findOrFail($this->prova['condominio_id']);
            $voce = $this->prova['voce'];
            $pdf = (string) base64_decode($this->prova['pdf']);
        } else {
            $riga = RegistroInvio::with('condominio')->findOrFail($this->invii[0]);
            $condominio = $riga->condominio;
            $voce = app(EmissioneLettere::class)->voceDallaRiga($riga);
            $pdf = $this->pdfDellaRiga($riga);
            $giorno = EmissioneLettere::giornoDellaLettera($riga);
        }

        $testo = self::testo((string) $condominio->nome, $voce);
        $messaggio = (new MailMessage)
            ->subject(($this->prova !== null ? 'Prova — ' : '') . $testo['oggetto'])
            ->greeting($testo['saluto']);
        if ($this->prova !== null) {
            $messaggio->line('Questa è una prova: è il messaggio che riceverà ' . self::nomeComeTesto($voce['persona']['nome'] ?? null) . ', con la sua lettera.');
        }
        foreach ($testo['righe'] as $riga) {
            $messaggio->line($riga);
        }
        $studio = EmissioneLettere::studio();
        if ($studio['email'] !== null && filter_var($studio['email'], FILTER_VALIDATE_EMAIL) !== false) {
            $messaggio->replyTo($studio['email'], $studio['nome']);
        }

        return $messaggio
            ->salutation($testo['chiusura'])
            ->attachData($pdf, EmissioneLettere::nomeFile($condominio, (string) $voce['tipo'], \Illuminate\Support\Str::slug((string) ($voce['persona']['nome'] ?? '')), $giorno), ['mime' => 'application/pdf']);
    }

    /**
     * Il PDF del messaggio: il file conservato, se la lettera ne ha uno (lo stesso a ogni tentativo); altrimenti fatto dalla riga, con la sua
     * impronta scritta sulla riga.
     */
    private function pdfDellaRiga(RegistroInvio $riga): string
    {
        if (isset($riga->dati['documento_id'])) {
            $documento = Documento::find((int) $riga->dati['documento_id']);
            $contenuto = $documento === null ? null : app(ArchivioDocumenti::class)->leggi($documento->path);
            if ($contenuto === null) {
                throw new \RuntimeException('Il PDF conservato della lettera non si trova sul disco dei documenti.');
            }

            return $contenuto;
        }
        $pdf = app(\App\Services\PDF\PdfService::class)->generate('pdf.gestionale.lettera', app(EmissioneLettere::class)->datiDellaVista($riga->condominio, [app(EmissioneLettere::class)->voceDallaRiga($riga)]), ['margin_top' => 14])
            ->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        $riga->update(['impronta' => hash('sha256', $pdf)]);

        return $pdf;
    }

    /**
     * Il testo del messaggio: l'oggetto, il saluto, due righe, la chiusura. Le rate, il totale e il testo dell'amministratore stanno nel PDF.
     *
     * @param array<string, mixed> $voce
     * @return array{oggetto: string, saluto: string, righe: array<int, string>, chiusura: string}
     */
    public static function testo(string $condominio, array $voce): array
    {
        $nomeCondominio = self::testoComeTesto($condominio);
        $tipo = (string) ($voce['tipo'] ?? 'sollecito');
        $studio = EmissioneLettere::studio();

        return [
            'oggetto'  => $nomeCondominio . ' — ' . (EmissioneLettere::NOMI[$tipo] ?? $tipo),
            'saluto'   => filled($voce['persona']['nome'] ?? null) ? 'Gentile ' . self::nomeComeTesto($voce['persona']['nome']) . ',' : 'Buongiorno,',
            'righe'    => [
                // «del condominio Le Rose», o «del Condominio Le Rose» se il nome comincia già con la parola: non «del condominio Condominio …».
                'In allegato ' . (self::CON_ARTICOLO[$tipo] ?? 'la lettera') . ' del ' . self::testoComeTesto(TestiLettere::condominioConNome($condominio, 'condominio'))
                    . ': le rate e il totale sono nel PDF.',
                'Se ha già versato, non tenga conto di questo messaggio.',
            ],
            'chiusura' => 'Cordiali saluti, ' . ($studio['nome'] !== null ? self::testoComeTesto($studio['nome']) : $nomeCondominio),
        ];
    }

    public function failed(\Throwable $e): void
    {
        if ($this->invii === []) {
            return;
        }

        RegistroInvio::whereIn('id', $this->invii)->where('stato', RegistroInvio::STATO_IN_CODA)->update([
            'stato'  => RegistroInvio::STATO_NON_PARTITA,
            'errore' => ErroreDiPosta::daEccezione($e),
        ]);
    }
}
