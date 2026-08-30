<?php

namespace App\Console\Commands;

use App\Models\CodiceAteco;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Chiede a ISTAT se ha pubblicato una **revisione** della classificazione più recente della nostra.
 *
 * ## ⚠️ La domanda è diversa da quella dei Comuni, e non per capriccio
 *
 * Sui Comuni si chiede *«l'elenco è più fresco del nostro?»*, e ha senso: i comuni si fondono e
 * cambiano nome durante l'anno, e ISTAT ripubblica l'elenco con una data.
 *
 * L'ATECO non cambia così. Cambia per **revisione della classificazione** — ATECO 2007, poi
 * l'aggiornamento 2022, poi ATECO 2025 — e nel file **una data non esiste**: verificata cella per
 * cella su entrambi i fogli. Quindi la domanda utile è *«ne è uscita una nuova?»*, e si risponde
 * leggendo la pagina della documentazione, dove ISTAT le elenca.
 *
 * ## ⛔ Non è pianificato, ed è la stessa decisione dei Comuni
 *
 * `routes/console.php` **non** lo contiene, e c'è un test che lo verifica. La classificazione viaggia
 * col codice proprio perché nessuna installazione debba dipendere dalla rete: pianificare questo
 * comando la rimetterebbe dentro dalla finestra. Lo lanciamo noi, e non c'è una stagione in cui
 * farlo — una revisione ATECO esce ogni molti anni, e quando esce se ne parla.
 *
 * ## ⚠️ Se la pagina non si legge, si dice — non si risponde «tutto a posto»
 *
 * È la classe di guasto che il documento di processo raccoglie da tre beta: la guardia che si svuota
 * senza far diventare rosso niente. Se il riconoscitore non trova **nessuna** revisione nella pagina,
 * vuol dire che ISTAT l'ha rifatta e che questo comando è cieco: si esce con un errore, non con un
 * silenzio rassicurante.
 *
 * ## Il peso e il `last-modified` si stampano, ma non fanno testo
 *
 * Servono a vedere se il file è stato ricaricato dentro la stessa revisione. Non sono un verdetto:
 * sui Comuni è stato misurato che il `last-modified` **non è stabile fra client** — `curl` e Guzzle
 * ne ricevono due valori a due secondi di distanza.
 */
class VerificaFonteAtecoCommand extends Command
{
    protected $signature = 'kondomanager:verifica-fonte-ateco';

    protected $description = 'Chiede a ISTAT se ha pubblicato una revisione ATECO più recente di quella in uso';

    /** La pagina dove ISTAT elenca le classificazioni e i loro file. */
    private const PAGINA = 'https://www.istat.it/classificazione/documenti-ateco/';

    /** Il file della struttura in uso, per la sola domanda «c'è, e quanto pesa». */
    private const FILE = 'https://www.istat.it/wp-content/uploads/2024/12/StrutturaATECO-2025-IT-EN-1.xlsx';

    public function handle(): int
    {
        $nostra = CodiceAteco::revisioneCorrente();

        if ($nostra === null) {
            $this->warn('In questa installazione la classificazione ATECO non è caricata.');
            $this->line('  Caricala con <comment>php artisan kondomanager:aggiorna-ateco</comment>, poi rilancia questo controllo.');

            return self::FAILURE;
        }

        $this->line("  In uso: <info>{$nostra}</info>");

        try {
            $pagina = Http::timeout(20)->get(self::PAGINA);
        } catch (Throwable $e) {
            $this->error('  Non riesco a raggiungere la pagina di ISTAT: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (! $pagina->successful()) {
            $this->error('  La pagina di ISTAT risponde ' . $pagina->status() . '.');

            return self::FAILURE;
        }

        $revisioni = self::revisioniNella($pagina->body());

        if ($revisioni === []) {
            // ⚠️ Qui NON si dice «tutto a posto»: il riconoscitore non ha trovato niente, e la
            // ragione più probabile è che ISTAT abbia rifatto la pagina. Un comando che tacesse
            // resterebbe verde per sempre senza guardare più niente.
            $this->error('  Nella pagina di ISTAT non riconosco nessuna revisione ATECO.');
            $this->line('  Quasi certamente la pagina è cambiata: <comment>va aggiornato questo comando</comment>, non ignorato.');
            $this->line('  Pagina: ' . self::PAGINA);

            return self::FAILURE;
        }

        $piuRecente = max($revisioni);
        $nostroAnno = self::annoDa($nostra);

        $this->line('  Sulla pagina di ISTAT: ' . implode(', ', array_map(fn ($a) => "ATECO {$a}", $revisioni)));

        $this->informativa();

        if ($nostroAnno === null) {
            // ⚠️ Se la revisione in tabella non porta un anno, questo comando **non sa rispondere**
            // alla domanda che gli si è fatta: dire «siamo allineati» sarebbe una risposta verde
            // data senza aver guardato — la classe di guasto che il flusso insegue da tre beta.
            $this->newLine();
            $this->error("  Dalla revisione in tabella («{$nostra}») non ricavo un anno: non posso confrontarla.");
            $this->line('  Ricarica la classificazione con <comment>php artisan kondomanager:aggiorna-ateco</comment>, poi rilancia.');

            return self::FAILURE;
        }

        if ($piuRecente > $nostroAnno) {
            $this->newLine();
            $this->warn("  ⚠️  ISTAT pubblica ATECO {$piuRecente}, noi siamo a {$nostra}.");
            $this->line('  Si rigenera così, e il file va preso dalla pagina qui sopra:');
            $this->line('    <comment>php artisan kondomanager:aggiorna-ateco --da=StrutturaATECO-XXXX-IT-EN.xlsx --scrivi-file</comment>');
            $this->line('    <comment>php artisan kondomanager:aggiorna-ateco</comment>');
            $this->newLine();
            $this->line('  ⚠️  Una revisione nuova <comment>rinomina e ritira codici</comment>: i fornitori già classificati');
            $this->line('      resteranno con codici della revisione vecchia, e vanno guardati prima di rimuoverli.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("  Siamo allineati: ISTAT non pubblica una revisione più recente di {$nostra}.");

        return self::SUCCESS;
    }

    /** Le revisioni nominate nella pagina, come anni. */
    private static function revisioniNella(string $html): array
    {
        // Due forme, perché ISTAT le usa entrambe: il nome del file («StrutturaATECO-2025-IT-EN»)
        // e il testo del collegamento («Struttura ATECO 2025 italiano inglese»).
        preg_match_all('/StrutturaATECO[-_\s]*(\d{4})/i', $html, $daiFile);
        preg_match_all('/ATECO\s+(\d{4})/i', $html, $daiTesti);

        $anni = array_map('intval', array_merge($daiFile[1] ?? [], $daiTesti[1] ?? []));

        // ⚠️ Si scartano gli anni impossibili: la pagina cita anche «ATECO 2007 aggiornamento 2022»,
        // e un anno futuro sarebbe un falso positivo che manderebbe a rigenerare per niente.
        $anni = array_filter($anni, fn (int $a) => $a >= 2000 && $a <= (int) date('Y') + 1);

        return array_values(array_unique($anni));
    }

    /** L'anno dentro «ATECO 2025». */
    private static function annoDa(string $versione): ?int
    {
        return preg_match('/(\d{4})/', $versione, $m) ? (int) $m[1] : null;
    }

    /**
     * Peso e data del file in uso. **Non è un verdetto**: serve a vedere se il file è stato
     * ricaricato dentro la stessa revisione, e il `last-modified` è dichiarato inaffidabile.
     */
    private function informativa(): void
    {
        try {
            $testa = Http::timeout(15)->head(self::FILE);
        } catch (Throwable) {
            $this->line('  <comment>(il file della struttura non risponde: informazione mancante, non un verdetto)</comment>');

            return;
        }

        if (! $testa->successful()) {
            $this->line('  <comment>(il file della struttura risponde ' . $testa->status() . ': informazione mancante, non un verdetto)</comment>');

            return;
        }

        $peso = $testa->header('Content-Length');
        $quando = $testa->header('Last-Modified');

        $this->line(sprintf(
            '  File della struttura: %s, caricato il %s <comment>(informativi: il last-modified non fa testo)</comment>',
            $peso ? round((int) $peso / 1024) . ' KB' : 'peso ignoto',
            $quando ?: 'data ignota',
        ));
    }
}
