<?php

namespace App\Console\Commands;

use App\Models\Comune;
use App\Support\LettoreElencoIstat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

/**
 * Carica in tabella l'elenco dei Comuni italiani con il loro codice catastale.
 *
 * ## Da dove legge, e perché non da ISTAT
 *
 * Legge il file convertito che viaggia col codice. Allo stesso percorso ISTAT vivono due file che
 * **non sono lo stesso elenco**: il `.csv` è fermo al 26/01/2024 ed è l'indirizzo che si trova per
 * primo; l'`.xlsx` è aggiornato ma costa **142 MB** per essere aperto, cioè muore su un hosting con
 * `memory_limit = 128M` — che è esattamente il parco installato di questo prodotto. Convertiamo noi,
 * una volta, e spediamo il risultato: nessuna installazione paga quel costo e nessuna dipende dalla
 * rete per una funzione del prodotto.
 *
 * ## La via d'uscita, che dalla beta.60 si può percorrere davvero
 *
 * `--da=<percorso>` legge **due formati**: il nostro JSON convertito e l'**XLSX come lo pubblica
 * ISTAT**. Fino alla .59 accettava solo il primo, e la ricetta per produrlo dal secondo non esisteva
 * in nessun file del repository — era stata eseguita una volta a mano. Il changelog prometteva
 * quindi una via d'uscita che nessuno poteva percorrere.
 *
 * `--scrivi-file` riscrive l'elenco spedito col codice invece di caricarlo a database. È il comando
 * con cui **noi** aggiorniamo il file una volta l'anno, e la ragione per cui la ricetta non vive più
 * in una conversazione.
 *
 * L'elenco cambia una volta l'anno — le fusioni decorrono dal 1° gennaio e ISTAT pubblica fra
 * gennaio e febbraio — e noi rilasciamo molto più spesso: la porta resta aperta senza che nessuna
 * installazione dipenda dalla rete.
 *
 * ## Perché `upsert` e non `firstOrCreate`
 *
 * `TipologieImmobiliSeeder` usa `firstOrCreate` con chiave sul solo nome, e una riga già presente
 * **non viene mai corretta** da un aggiornamento successivo: accertato a database il 15/08/2026. Su
 * un elenco che esiste proprio per essere aggiornato quel comportamento sarebbe il difetto
 * principale, non un dettaglio. Qui la scrittura è un `upsert` a blocchi con chiave sul codice
 * catastale: corregge le righe che ci sono e ne costa una ogni cinquecento comuni invece di 7.894.
 */
class AggiornaComuniCommand extends Command
{
    protected $signature = 'kondomanager:aggiorna-comuni
                            {--da= : Percorso di un elenco: il nostro JSON convertito, oppure l\'XLSX come lo pubblica ISTAT}
                            {--scrivi-file : Invece di caricare a database, riscrive l\'elenco spedito col codice}
                            {--in= : Dove scrivere, se non nell\'elenco spedito (serve ai test, che non devono toccarlo)}';

    protected $description = 'Carica l\'elenco ISTAT dei Comuni italiani e dei loro codici catastali';

    /** Le chiavi che ogni comune deve portare: senza una di queste il file non è un elenco. */
    private const CHIAVI = ['codice', 'nome', 'sigla', 'provincia', 'regione'];

    public function handle(): int
    {
        $percorso = $this->option('da') ?: self::fileSpedito();

        try {
            $doc = $this->leggi($percorso);
        } catch (Throwable $e) {
            // Il fallimento è rumoroso, al contrario della convenzione degli altri comandi di
            // aggiornamento: quelli girano da scheduler e un errore a video non lo leggerebbe
            // nessuno, questo lo lancia una persona che sta aspettando una risposta.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('scrivi-file')) {
            return $this->scriviFileSpedito($doc);
        }

        $totale = count($doc['comuni']);
        $barra = $this->output->createProgressBar($totale);
        $barra->start();

        $scritti = 0;
        $adesso = now();

        // In transazione perché il caso peggiore — interruzione a metà — lascerebbe una tabella
        // mezza vecchia e mezza nuova, con `fonte_al` che dichiara due verità diverse.
        //
        // ⚠️ `upsert` a blocchi e non `updateOrCreate` riga per riga: la seconda forma costa **7.894
        // interrogazioni** (misurate: 2,2 s in locale, e su un hosting condiviso ben di più), la
        // prima ne costa una ogni cinquecento comuni. La semantica che serve è la stessa — una riga
        // già presente viene **corretta**, che è il punto dell'intero comando — perché la chiave del
        // conflitto è `codice_catasto`, univoco a database.
        DB::transaction(function () use ($doc, $barra, $adesso, &$scritti) {
            foreach (array_chunk($doc['comuni'], 500) as $blocco) {
                $righe = array_map(fn (array $c) => [
                    'codice_catasto'    => $c['codice'],
                    'nome'              => $c['nome'],
                    'nome_altra_lingua' => $c['altra'] ?? null,
                    // Calcolata qui una volta sola, con la stessa funzione che normalizza ciò che
                    // l'utente scrive: la regola sta in un posto solo e le due parti non possono
                    // divergere.
                    'nome_ricerca'      => Comune::testoRicerca($c['nome'], $c['altra'] ?? null),
                    'sigla'             => $c['sigla'],
                    'provincia'         => $c['provincia'],
                    'regione'           => $c['regione'],
                    'fonte_al'          => $doc['aggiornato_al'],
                    'created_at'        => $adesso,
                    'updated_at'        => $adesso,
                ], $blocco);

                // `created_at` resta fuori dalle colonne da aggiornare: una riga già in tabella
                // conserva la data in cui è nata.
                Comune::upsert(
                    $righe,
                    ['codice_catasto'],
                    ['nome', 'nome_altra_lingua', 'nome_ricerca', 'sigla', 'provincia', 'regione', 'fonte_al', 'updated_at']
                );

                $scritti += count($righe);
                $barra->advance(count($righe));
            }
        });

        $barra->finish();
        $this->newLine(2);

        $this->info(sprintf(
            '%d comuni caricati. Fonte: %s, aggiornata al %s.',
            $scritti,
            $doc['fonte'],
            \Carbon\Carbon::parse($doc['aggiornato_al'])->format('d/m/Y')
        ));

        // Le righe che l'elenco nuovo non nomina restano dove sono, con la loro `fonte_al` vecchia:
        // un comune soppresso continua a esistere in tabella. È voluto — chi ha un immobile in un
        // comune fuso deve poterlo ancora cercare — e va detto invece di lasciarlo scoprire.
        $superstiti = Comune::whereDate('fonte_al', '<', $doc['aggiornato_al'])->count();

        if ($superstiti > 0) {
            $this->line(sprintf(
                '  <comment>%d comuni non compaiono in questo elenco e restano in tabella con la loro data precedente.</comment>',
                $superstiti
            ));
        }

        return self::SUCCESS;
    }

    /** Il file che viaggia col codice, scritto in un posto solo. */
    public static function fileSpedito(): string
    {
        return resource_path('data/comuni/comuni-italiani.json');
    }

    /**
     * Riscrive l'elenco spedito col codice.
     *
     * **Una riga per comune**, e non è un vezzo: il file si aggiorna una volta l'anno e il diff deve
     * restare leggibile in revisione. Un JSON compattato su una riga sola renderebbe impossibile
     * vedere quali comuni sono cambiati.
     *
     * ⚠️ Non tocca il database. Sono due operazioni diverse — rigenerare il file che spediamo e
     * caricare l'elenco su questa installazione — e confonderle vorrebbe dire cambiare i dati di chi
     * lancia il comando per aggiornare il repository.
     */
    private function scriviFileSpedito(array $doc): int
    {
        $percorso = $this->option('in') ?: self::fileSpedito();

        // ⚠️ **Si scrive accanto e poi si sposta.** Aprire il file vero in modalità `w` lo tronca a
        // zero byte **prima** di avere il contenuto nuovo: se la scrittura si ferma a metà — disco
        // pieno, memoria esaurita, interruzione — l'elenco spedito col codice resta monco, e la
        // beta successiva spedirebbe un file di dati rotto. `rename()` sullo stesso filesystem è
        // atomico: o c'è il file vecchio, o c'è quello nuovo intero.
        $temporaneo = $percorso.'.nuovo';

        $f = @fopen($temporaneo, 'w');

        if ($f === false) {
            $this->error("Non riesco a scrivere accanto a {$percorso}: controlla i permessi della cartella.");

            return self::FAILURE;
        }

        fwrite($f, "{\n");

        foreach (['fonte', 'url', 'foglio', 'aggiornato_al'] as $chiave) {
            fwrite($f, json_encode($chiave).': '.json_encode($doc[$chiave] ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).",\n");
        }

        fwrite($f, '"comuni": ['."\n");

        $ultimo = count($doc['comuni']) - 1;

        foreach ($doc['comuni'] as $i => $c) {
            fwrite($f, json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).($i === $ultimo ? "\n" : ",\n"));
        }

        fwrite($f, "]\n}\n");

        // Gli esiti si guardano: `fwrite` non solleva quando il disco è pieno, restituisce meno
        // byte di quanti gliene hai dati, e `fclose` è il punto in cui il buffer arriva davvero sul
        // disco. Senza questo controllo un file troncato passerebbe per scritto.
        if (! fclose($f)) {
            @unlink($temporaneo);
            $this->error('La scrittura non è andata a buon fine: l\'elenco spedito non è stato toccato.');

            return self::FAILURE;
        }

        $riletto = json_decode((string) file_get_contents($temporaneo), true);

        if (! is_array($riletto) || count($riletto['comuni'] ?? []) !== count($doc['comuni'])) {
            @unlink($temporaneo);
            $this->error('Il file scritto non si rilegge: l\'elenco spedito non è stato toccato.');

            return self::FAILURE;
        }

        if (! @rename($temporaneo, $percorso)) {
            @unlink($temporaneo);
            $this->error("Non riesco a sostituire {$percorso}.");

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s riscritto: %d comuni, fonte aggiornata al %s.',
            str_replace(base_path().'/', '', $percorso),
            count($doc['comuni']),
            \Carbon\Carbon::parse($doc['aggiornato_al'])->format('d/m/Y')
        ));
        $this->line('  <comment>Il database non è stato toccato: per caricarlo, rilancia il comando senza --scrivi-file.</comment>');

        return self::SUCCESS;
    }

    /**
     * @return array{fonte: string, aggiornato_al: string, comuni: array<int, array<string, mixed>>}
     */
    private function leggi(string $percorso): array
    {
        if (! is_file($percorso) || ! is_readable($percorso)) {
            throw new \RuntimeException("Elenco non leggibile: {$percorso}");
        }

        // Il file di ISTAT si riconosce dall'estensione e si converte al volo: è la via d'uscita
        // vera, quella che permette a chi ha fretta di scaricare il file dal sito dell'istituto e
        // darlo a questo comando senza passare da noi.
        if (strtolower(pathinfo($percorso, PATHINFO_EXTENSION)) === 'xlsx') {
            $this->line('  <comment>Foglio ISTAT: lo converto. È l\'operazione cara — serve memoria.</comment>');

            $doc = LettoreElencoIstat::converti($percorso);

            return $this->validaForma($doc);
        }

        try {
            $doc = json_decode(file_get_contents($percorso), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException("L'elenco non è un JSON valido: {$e->getMessage()}");
        }

        return $this->validaForma($doc);
    }

    /**
     * ⚠️ La validazione della forma non è pignoleria: senza, un file sbagliato passato con `--da`
     * scriverebbe righe monche sopra un elenco buono. Meglio fermarsi e non toccare niente.
     *
     * Vale per tutti e due i formati: un XLSX convertito passa di qui esattamente come un JSON.
     *
     * @return array{fonte: string, aggiornato_al: string, comuni: array<int, array<string, mixed>>}
     */
    private function validaForma(mixed $doc): array
    {
        if (! is_array($doc)) {
            throw new \RuntimeException('L\'elenco non è nella forma attesa.');
        }

        foreach (['fonte', 'aggiornato_al', 'comuni'] as $chiave) {
            if (! isset($doc[$chiave])) {
                throw new \RuntimeException("L'elenco non ha la chiave «{$chiave}»: non sembra un elenco di comuni.");
            }
        }

        if (! is_array($doc['comuni']) || $doc['comuni'] === []) {
            throw new \RuntimeException('L\'elenco non contiene nessun comune.');
        }

        foreach ($doc['comuni'] as $i => $c) {
            foreach (self::CHIAVI as $chiave) {
                if (! isset($c[$chiave]) || trim((string) $c[$chiave]) === '') {
                    throw new \RuntimeException("Al comune n.{$i} manca «{$chiave}».");
                }
            }

            if (strlen((string) $c['codice']) !== 4) {
                throw new \RuntimeException("Il codice catastale «{$c['codice']}» non è di quattro caratteri.");
            }
        }

        $codici = array_column($doc['comuni'], 'codice');

        if (count(array_unique($codici)) !== count($codici)) {
            throw new \RuntimeException('L\'elenco contiene codici catastali ripetuti: sulla fonte ISTAT non succede, quindi il file è sbagliato.');
        }

        return $doc;
    }
}
