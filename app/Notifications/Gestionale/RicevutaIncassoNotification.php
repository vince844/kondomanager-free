<?php

namespace App\Notifications\Gestionale;

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Notifications\Concerns\TestoScrittoDaAltri;
use App\Notifications\LocalizedNotification;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\Ricevute\ErroreDiPosta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * La ricevuta dell'incasso per email: un messaggio a un indirizzo, con un PDF che porta le ricevute delle sue righe del registro (una o più della
 * stessa persona). Il PDF si fa quando il messaggio parte, dalle righe: il contenuto della ricevuta (quello dell'originale, per una copia) e le
 * scelte (bollo, nota, causale) stanno nella riga, scritti quando l'invio si è accodato; l'impronta del PDF partito si scrive sulle righe.
 *
 * Il testo dice solo il condominio, il numero, la data e l'importo: le rate e il residuo stanno nel PDF. Le righe portano un gettone, che è
 * l'identificativo della notifica (`$id`): il registro delle email (`LogSentMessage`) lo rilegge dall'evento e le porta a «inviata» quando il
 * server accetta il messaggio; il messaggio non porta niente del registro. Se parte con errore, `failed()` le mette «non partita»; la ricevuta
 * di un incasso annullato prima che il messaggio parta non parte (le altre ricevute del messaggio, se ce ne sono, partono); senza un server di
 * posta nessuna.
 *
 * In prova («Mandane una a me») il messaggio va all'utente che lavora, senza righe nel registro degli invii (nel registro delle email la riga
 * c'è, come per ogni messaggio).
 */
class RicevutaIncassoNotification extends LocalizedNotification implements ShouldQueue
{
    use Queueable;
    use TestoScrittoDaAltri;

    /** Quello che dice la riga di una ricevuta il cui incasso è stato annullato fra l'accodamento e la partenza. */
    public const ANNULLATO_PRIMA_DELL_INVIO = 'L\'incasso è stato annullato prima dell\'invio: la ricevuta non è partita.';

    /** @var array<string, mixed>|null la prova: condominio, persona, ricevute con le scelte */
    public ?array $prova = null;

    /** @param array<int, int> $invii le righe del registro che il messaggio porta */
    public function __construct(public array $invii = [])
    {
        parent::__construct();
    }

    /** @param array{condominio_id: int, anagrafica_id: int, ricevute: array<int, array{incasso_id: int, dati: array<string, mixed>}>} $prova */
    public static function prova(array $prova): self
    {
        $notifica = new self([]);
        $notifica->prova = $prova;

        return $notifica;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Non parte un messaggio se il server di posta non c'è più: con il trasporto `log` il messaggio intero, PDF compreso, finirebbe nel file di
     * log; le sue righe diventano «non partita». Non parte nemmeno la ricevuta di un incasso annullato fra l'accodamento e la partenza. La
     * ricevuta di un incasso già annullato quando si è accodata (con «INCASSO ANNULLATO» in testa, per chi l'aveva avuta) parte.
     */
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

        $righe = RegistroInvio::with('documentabile')->whereIn('id', $this->invii)->get();
        $annullate = $righe
            ->filter(fn (RegistroInvio $r) => $r->documentabile === null || ($r->documentabile->stato === 'annullata' && empty($r->dati['contenuto']['annullato'])))
            ->pluck('id');
        if ($annullate->isNotEmpty()) {
            RegistroInvio::whereIn('id', $annullate)->update([
                'stato'  => RegistroInvio::STATO_NON_PARTITA,
                'errore' => self::ANNULLATO_PRIMA_DELL_INVIO,
            ]);
        }
        $this->invii = $righe->pluck('id')->diff($annullate)->values()->all();

        return $this->invii !== [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$condominio, $invii, $persona] = $this->prova !== null ? $this->dallaProva() : $this->dalleRighe();
        $pdf = app(EmissioneRicevute::class)->pdfDelMessaggio($condominio, $invii, $persona?->nome, $this->prova !== null ? EmissioneRicevute::ETICHETTA_PROVA : null);
        if ($this->invii !== []) {
            RegistroInvio::whereIn('id', $this->invii)->update(['impronta' => hash('sha256', $pdf['pdf'])]);
        }

        $testo = self::testo((string) $condominio->nome, $pdf['contenuti'], $persona?->nome);

        $messaggio = (new MailMessage)
            ->subject(($this->prova !== null ? 'Prova — ' : '') . $testo['oggetto'])
            ->greeting($testo['saluto']);

        if ($this->prova !== null) {
            $messaggio->line('Questa è una prova: è il messaggio che riceverà ' . self::nomeComeTesto($persona?->nome) . ', con il suo PDF.');
        }
        foreach ($testo['righe'] as $riga) {
            $messaggio->line($riga);
        }

        return $messaggio
            ->salutation($testo['chiusura'])
            ->attachData($pdf['pdf'], $pdf['nome'], ['mime' => 'application/pdf']);
    }

    /**
     * Il testo del messaggio della ricevuta: l'oggetto, il saluto, le righe, la chiusura. Lo usa il messaggio che il programma manda e la bozza di
     * «Prepara l'email» (decisione 207.1), che l'amministratore manda dal suo programma di posta: le stesse parole.
     *
     * @param array<int, array<string, mixed>> $contenuti le ricevute del messaggio
     * @return array{oggetto: string, saluto: string, righe: array<int, string>, chiusura: string}
     */
    public static function testo(string $condominio, array $contenuti, ?string $persona): array
    {
        $euro = fn (int $cents) => '€ ' . number_format($cents / 100, 2, ',', '.');
        $giorno = fn (?string $data) => $data ? \Carbon\Carbon::parse($data)->format('d/m/Y') : '';
        $nomeCondominio = self::testoComeTesto($condominio);
        $una = count($contenuti) === 1;

        $righe = $una
            ? ["In allegato la ricevuta del versamento di {$euro($contenuti[0]['importo_versato'])} del "
                . "{$giorno($contenuti[0]['data_versamento'])}, numero {$contenuti[0]['numero']}."]
            : ['In allegato le ricevute dei versamenti:', ...array_map(fn (array $c) => "n. {$c['numero']} del {$giorno($c['data_versamento'])}: {$euro($c['importo_versato'])}", $contenuti)];

        return [
            'oggetto'  => $una
                ? "{$nomeCondominio} — Ricevuta n. {$contenuti[0]['numero']} del {$giorno($contenuti[0]['data_versamento'])}"
                : "{$nomeCondominio} — Ricevute di pagamento (" . count($contenuti) . ')',
            'saluto'   => $persona !== null ? 'Gentile ' . self::nomeComeTesto($persona) . ',' : 'Buongiorno,',
            'righe'    => $righe,
            'chiusura' => 'Cordiali saluti, ' . $nomeCondominio,
        ];
    }

    /** L'invio non è partito: le righe ancora in coda diventano «non partita», con l'errore (del server di posta, o del programma). */
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

    /** @return array{0: Condominio, 1: array<int, array<string, mixed>>, 2: ?Anagrafica} */
    private function dalleRighe(): array
    {
        $righe = RegistroInvio::with(['documentabile', 'condominio', 'anagrafica'])->whereIn('id', $this->invii)->orderBy('id')->get();

        return [
            $righe->first()->condominio,
            $righe->map(fn (RegistroInvio $r) => ['incasso' => $r->documentabile, 'dati' => (array) $r->dati, 'emesso_il' => $r->emesso_il])->all(),
            $righe->first()->anagrafica,
        ];
    }

    /** @return array{0: Condominio, 1: array<int, array<string, mixed>>, 2: ?Anagrafica} */
    private function dallaProva(): array
    {
        $incassi = ScritturaContabile::whereIn('id', array_column($this->prova['ricevute'], 'incasso_id'))->get()->keyBy('id');
        $adesso = now();

        return [
            Condominio::findOrFail($this->prova['condominio_id']),
            array_map(fn ($r) => ['incasso' => $incassi[$r['incasso_id']], 'dati' => $r['dati'], 'emesso_il' => $adesso], $this->prova['ricevute']),
            Anagrafica::find($this->prova['anagrafica_id']),
        ];
    }
}
