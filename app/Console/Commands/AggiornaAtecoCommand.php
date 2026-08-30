<?php

namespace App\Console\Commands;

use App\Models\CodiceAteco;
use App\Support\LettoreStrutturaAteco;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Carica la classificazione ATECO nella tabella che il programma interroga.
 *
 * ## Come i Comuni, e per le stesse ragioni
 *
 * L'elenco **viaggia col codice** (`resources/data/ateco/ateco-2025.json`): nessuna installazione
 * deve dipendere dalla rete per avere i codici. Questa tabella è la sua forma interrogabile.
 *
 * `--da=<percorso>` legge **due formati**: il nostro JSON convertito e l'**XLSX come lo pubblica
 * ISTAT**, convertito al volo. È la via d'uscita vera — chi ha fretta scarica il file dal sito
 * dell'istituto e lo dà a questo comando senza passare da noi.
 *
 * `--scrivi-file` riscrive l'elenco spedito col codice invece di caricarlo a database: è il comando
 * con cui si rigenera la fonte quando ISTAT pubblica una revisione nuova.
 *
 * ## ⚠️ Perché non c'è un «aggiornato al»
 *
 * Sui Comuni la data la dichiara ISTAT nel nome del foglio, e finisce su ogni riga. **Nel file
 * ATECO quella data non esiste**: verificata cella per cella su entrambi i fogli. E non è una
 * dimenticanza — l'ATECO cambia per **revisione**, non in continuazione, quindi il timbro giusto è
 * il nome della revisione, «ATECO 2025», che il lettore ricava dal nome del foglio.
 *
 * `--fonte-al=` esiste per chi vuole comunque timbrare una data **dichiarandola**. Non viene mai
 * dedotta dal `last-modified` HTTP: il documento di processo, dopo averlo misurato sui Comuni, lo
 * definisce inaffidabile — l'intestazione diceva 26/02 mentre il foglio diceva 21/02, e i due valori
 * non erano nemmeno stabili fra client.
 */
class AggiornaAtecoCommand extends Command
{
    protected $signature = 'kondomanager:aggiorna-ateco
                            {--da= : Percorso di un elenco: il nostro JSON convertito, oppure l\'XLSX come lo pubblica ISTAT}
                            {--scrivi-file : Invece di caricare a database, riscrive l\'elenco spedito col codice}
                            {--in= : Dove scrivere, se non nell\'elenco spedito (serve ai test, che non devono toccarlo)}
                            {--fonte-al= : Data della fonte, se la si vuole timbrare: va DICHIARATA, non viene mai dedotta}';

    protected $description = 'Carica la classificazione ATECO di ISTAT (codici, titoli e gerarchia)';

    /** L'elenco spedito col codice. */
    public const ELENCO = 'resources/data/ateco/ateco-2025.json';

    /** Le chiavi che ogni codice deve avere: senza una di queste la riga è monca. */
    private const CHIAVI = ['ordine', 'codice', 'titolo', 'livello'];

    public function handle(): int
    {
        try {
            $doc = $this->leggi($this->option('da') ?: base_path(self::ELENCO));
        // ⚠️ `\Throwable` e non `\RuntimeException`: un XLSX rotto fa uscire PhpSpreadsheet con
        // messaggi propri, e l'amministratore leggeva «Could not find zip member zip:///…#_rels\.rels»
        // invece della guida. È la stessa cattura larga del gemello sui Comuni.
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('scrivi-file')) {
            return $this->scriviFile($doc);
        }

        return $this->carica($doc);
    }

    private function carica(array $doc): int
    {
        $fonteAl = $this->option('fonte-al');

        if ($fonteAl !== null && $fonteAl !== '') {
            // ⚠️ Una forma sola, e rigida. `Carbon::parse()` accetta tutto e **indovina**: misurato,
            // «11/12/2024» diventava il 12 novembre e «2025» diventava la data di oggi, perché lo
            // leggeva come un orario. Una data timbrata sulla fonte che sia sbagliata in silenzio è
            // peggio di una data che manca — e qui manca per costruzione, quindi è la sola che c'è.
            $letta = \DateTimeImmutable::createFromFormat('!Y-m-d', $fonteAl);

            if ($letta === false || $letta->format('Y-m-d') !== $fonteAl) {
                $this->error("«{$fonteAl}» non è una data nella forma attesa: si scrive 2024-12-11.");

                return self::FAILURE;
            }

            $fonteAl = $letta->format('Y-m-d');
        } else {
            $fonteAl = null;
        }

        $this->line("  <info>{$doc['fonte']}</info>");
        $this->line('  ' . count($doc['codici']) . ' codici');

        $barra = $this->output->createProgressBar(count($doc['codici']));
        $barra->start();

        $adesso = now();
        $scritti = 0;

        // In transazione: un'interruzione a metà lascerebbe una tabella mezza vecchia e mezza nuova,
        // con due revisioni dichiarate contemporaneamente.
        //
        // ⚠️ `upsert` a blocchi e non `updateOrCreate` riga per riga — è la lezione già pagata sui
        // Comuni: la seconda forma costa un'interrogazione per riga, qui una ogni cinquecento. La
        // semantica che serve è la stessa, perché la chiave del conflitto è `codice`, univoco a
        // database.
        DB::transaction(function () use ($doc, $barra, $adesso, $fonteAl, &$scritti) {
            foreach (array_chunk($doc['codici'], 500) as $blocco) {
                $righe = array_map(fn (array $c) => [
                    'codice'         => $c['codice'],
                    'titolo'         => $c['titolo'],
                    'titolo_en'      => $c['titolo_en'] ?? null,
                    'livello'        => $c['livello'],
                    'codice_padre'   => $c['codice_padre'] ?? null,
                    'ordine'         => $c['ordine'],
                    // Calcolata qui una volta sola, con la stessa funzione che normalizza ciò che
                    // l'utente scrive: la regola sta in un posto solo e le due parti non possono
                    // divergere. `upsert` non fa scattare gli eventi del model, quindi la rete di
                    // `CodiceAteco::booted()` qui non scatterebbe.
                    'testo_ricerca'  => CodiceAteco::testoRicerca($c['codice'], $c['titolo']),
                    'versione_fonte' => $doc['versione'],
                    'fonte_al'       => $fonteAl,
                    'created_at'     => $adesso,
                    'updated_at'     => $adesso,
                ], $blocco);

                // `created_at` resta fuori: una riga già in tabella conserva la data in cui è nata.
                CodiceAteco::upsert(
                    $righe,
                    ['codice'],
                    ['titolo', 'titolo_en', 'livello', 'codice_padre', 'ordine', 'testo_ricerca', 'versione_fonte', 'fonte_al', 'updated_at']
                );

                $scritti += count($righe);
                $barra->advance(count($righe));
            }
        });

        $barra->finish();
        $this->newLine(2);
        $this->info("  Caricati {$scritti} codici — {$doc['versione']}.");

        // Le righe di una revisione precedente non spariscono da sole: `upsert` corregge quelle che
        // ritrova e lascia stare le altre. Dirlo è il minimo — una classificazione che ne perde
        // qualcuno lascerebbe in tabella codici che ISTAT ha ritirato.
        $superstiti = CodiceAteco::where('versione_fonte', '!=', $doc['versione'])->count();

        if ($superstiti > 0) {
            $this->warn("  ⚠️  {$superstiti} codici in tabella appartengono a un'altra revisione: ISTAT non li ha più.");
            $this->line('     Si tolgono a mano, dopo aver controllato che nessun fornitore li usi.');
        }

        return self::SUCCESS;
    }

    private function scriviFile(array $doc): int
    {
        $dove = $this->option('in') ?: base_path(self::ELENCO);

        if (! is_dir(dirname($dove))) {
            mkdir(dirname($dove), 0755, true);
        }

        // ⚠️ Si scrive accanto e si rinomina, **mai direttamente sul file spedito**, ed è la forma
        // che il gemello sui Comuni ha già e che qui mancava. Aprire il file vero lo tronca a zero
        // byte **prima** di avere il contenuto nuovo: misurato, da 967.947 byte a 32.768 con un
        // `json_encode` fallito, e il comando stampava lo stesso «Scritti 3257 codici» e usciva con
        // successo. Un elenco troncato committato è un file di dati vuoto spedito a ogni
        // installazione — cioè il difetto peggiore che questo comando possa produrre.
        $temporaneo = $dove . '.nuovo';

        // ⚠️ Un codice per riga, non `JSON_PRETTY_PRINT`: misurato, i rientri costavano **324 KB su
        // 945**. Così il file pesa 621 KB **e** il diff di una revisione futura resta di una riga per
        // codice invece di sette — che è la ragione per cui i Comuni sono scritti così.
        $righe = array_map(
            fn (array $c) => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $doc['codici']
        );

        if (in_array(false, $righe, true)) {
            $this->error('  Un codice non è convertibile in JSON: non tocco l\'elenco spedito.');

            return self::FAILURE;
        }

        $contenuto = "{\n"
            . '  "fonte": ' . json_encode($doc['fonte'], JSON_UNESCAPED_UNICODE) . ",\n"
            . '  "versione": ' . json_encode($doc['versione'], JSON_UNESCAPED_UNICODE) . ",\n"
            . "  \"codici\": [\n"
            . implode(",\n", $righe)
            . "\n  ]\n}\n";

        if (file_put_contents($temporaneo, $contenuto) === false) {
            $this->error("  Non riesco a scrivere {$temporaneo}: l'elenco spedito non è stato toccato.");

            return self::FAILURE;
        }

        // Si **rilegge** prima di sostituire: `file_put_contents` non solleva quando il disco è
        // pieno, e un file mezzo scritto passerebbe inosservato.
        $riletto = json_decode((string) file_get_contents($temporaneo), true);
        $quanti = is_array($riletto) ? count($riletto['codici'] ?? []) : 0;

        if ($quanti !== count($doc['codici'])) {
            @unlink($temporaneo);
            $this->error("  Il file scritto ne contiene {$quanti} invece di " . count($doc['codici']) . ': non tocco l\'elenco spedito.');

            return self::FAILURE;
        }

        if (! rename($temporaneo, $dove)) {
            @unlink($temporaneo);
            $this->error("  Non riesco a sostituire {$dove}.");

            return self::FAILURE;
        }

        $this->info("  Scritti {$quanti} codici ({$doc['versione']}) in {$dove}.");

        return self::SUCCESS;
    }

    /**
     * @return array{fonte: string, versione: string, codici: array<int, array<string, mixed>>}
     */
    private function leggi(string $percorso): array
    {
        if (! is_file($percorso) || ! is_readable($percorso)) {
            throw new \RuntimeException("Elenco non leggibile: {$percorso}");
        }

        if (strtolower(pathinfo($percorso, PATHINFO_EXTENSION)) === 'xlsx') {
            $this->line('  <comment>Foglio ISTAT: lo converto. È l\'operazione cara — serve memoria.</comment>');

            return $this->validaForma(LettoreStrutturaAteco::converti($percorso));
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
     * @return array{fonte: string, versione: string, codici: array<int, array<string, mixed>>}
     */
    private function validaForma(mixed $doc): array
    {
        if (! is_array($doc)) {
            throw new \RuntimeException('L\'elenco non è nella forma attesa.');
        }

        foreach (['fonte', 'versione', 'codici'] as $chiave) {
            if (! isset($doc[$chiave])) {
                throw new \RuntimeException("L'elenco non ha la chiave «{$chiave}»: non sembra la struttura ATECO.");
            }
        }

        if (! is_array($doc['codici']) || $doc['codici'] === []) {
            throw new \RuntimeException('L\'elenco non contiene nessun codice.');
        }

        foreach ($doc['codici'] as $i => $c) {
            foreach (self::CHIAVI as $chiave) {
                if (! isset($c[$chiave]) || trim((string) $c[$chiave]) === '') {
                    throw new \RuntimeException("Al codice n.{$i} manca «{$chiave}».");
                }
            }

            if ((int) $c['livello'] < 1 || (int) $c['livello'] > 6) {
                throw new \RuntimeException("Il codice «{$c['codice']}» dichiara un livello fuori dall'intervallo 1–6.");
            }

            if (mb_strlen((string) $c['codice']) > 12) {
                throw new \RuntimeException("Il codice «{$c['codice']}» supera i 12 caratteri della colonna.");
            }

            // ⚠️ Anche titolo e testo di ricerca, e soprattutto il secondo: su MySQL non stretto
            // verrebbero **troncati in silenzio**, e un codice troncato a metà del titolo entra in
            // tabella e non si trova più cercandolo a parole, senza nessun errore.
            foreach (['titolo' => (string) $c['titolo'], 'titolo_en' => (string) ($c['titolo_en'] ?? '')] as $nome => $valore) {
                if (mb_strlen($valore) > 255) {
                    throw new \RuntimeException("Il codice «{$c['codice']}» ha un «{$nome}» di " . mb_strlen($valore) . ' caratteri: la colonna ne regge 255.');
                }
            }

            $ricerca = CodiceAteco::testoRicerca((string) $c['codice'], (string) $c['titolo']);

            if (mb_strlen($ricerca) > 255) {
                throw new \RuntimeException("Il codice «{$c['codice']}» produce un testo di ricerca di " . mb_strlen($ricerca) . ' caratteri: la colonna ne regge 255, e troncato non si troverebbe più.');
            }
        }

        $codici = array_column($doc['codici'], 'codice');

        if (count(array_unique($codici)) !== count($codici)) {
            throw new \RuntimeException('L\'elenco contiene codici ripetuti: sulla fonte ISTAT non succede, quindi il file è sbagliato.');
        }

        return $doc;
    }
}
