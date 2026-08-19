<?php

namespace App\Console\Commands;

use App\Models\Comune;
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
 * ## La via d'uscita
 *
 * `--da=<percorso>` legge un file convertito diverso da quello spedito. Serve a chi ha bisogno di un
 * aggiornamento fuori ciclo: l'elenco cambia una volta l'anno (le fusioni decorrono dal 1° gennaio)
 * e noi rilasciamo molto più spesso, ma la porta resta aperta senza introdurre una dipendenza.
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
                            {--da= : Percorso di un elenco convertito diverso da quello spedito col codice}';

    protected $description = 'Carica l\'elenco ISTAT dei Comuni italiani e dei loro codici catastali';

    /** Le chiavi che ogni comune deve portare: senza una di queste il file non è un elenco. */
    private const CHIAVI = ['codice', 'nome', 'sigla', 'provincia', 'regione'];

    public function handle(): int
    {
        $percorso = $this->option('da') ?: resource_path('data/comuni/comuni-italiani.json');

        try {
            $doc = $this->leggi($percorso);
        } catch (Throwable $e) {
            // Il fallimento è rumoroso, al contrario della convenzione degli altri comandi di
            // aggiornamento: quelli girano da scheduler e un errore a video non lo leggerebbe
            // nessuno, questo lo lancia una persona che sta aspettando una risposta.
            $this->error($e->getMessage());

            return self::FAILURE;
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

    /**
     * @return array{fonte: string, aggiornato_al: string, comuni: array<int, array<string, mixed>>}
     */
    private function leggi(string $percorso): array
    {
        if (! is_file($percorso) || ! is_readable($percorso)) {
            throw new \RuntimeException("Elenco non leggibile: {$percorso}");
        }

        try {
            $doc = json_decode(file_get_contents($percorso), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException("L'elenco non è un JSON valido: {$e->getMessage()}");
        }

        // ⚠️ La validazione della forma non è pignoleria: senza, un file sbagliato passato con `--da`
        // scriverebbe righe monche sopra un elenco buono. Meglio fermarsi e non toccare niente.
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
