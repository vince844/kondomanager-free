<?php

namespace App\Console\Commands;

use App\Models\Comune;
use App\Support\LettoreElencoIstat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Chiede a ISTAT se ha pubblicato un elenco di Comuni più fresco del nostro.
 *
 * ## Il pezzo che mancava
 *
 * L'elenco viaggia dentro il codice e arriva a destinazione da solo: chi aggiorna KondoManager lo
 * riceve senza fare niente. Restava scoperto **il passo prima** — accorgersi che ISTAT ne ha
 * pubblicato uno nuovo — e alla domanda *«controlleremo manualmente ogni tanto?»* la risposta onesta
 * era: sì, e senza qualcosa che lo ricordi non sarebbe successo.
 *
 * ## ⛔ Non è pianificato, ed è la decisione che tiene in piedi il disegno
 *
 * `routes/console.php` **non** lo contiene, e c'è un test che lo verifica. L'elenco viaggia col
 * codice proprio perché nessuna installazione debba dipendere dalla rete: pianificare questo comando
 * la rimetterebbe dentro dalla finestra. Lo lanciamo noi, e il documento di processo dice quando —
 * alla prima beta dell'anno, perché le fusioni decorrono dal 1° gennaio e ISTAT pubblica fra gennaio
 * e febbraio.
 *
 * ## Perché confronta il nome del foglio e non il `last-modified`
 *
 * Misurato il 19/08/2026: l'intestazione HTTP dice **26/02/2026**, il nome del foglio dice **«CODICI
 * al 21_02_2026»**. Cinque giorni di scarto, e sono due cose diverse — quando ISTAT ha caricato il
 * file, e a quando l'elenco è aggiornato. Peggio: il `last-modified` **non è stabile fra client**,
 * perché `curl` e Guzzle ne ricevono due valori a due secondi di distanza. Un controllo costruito su
 * quell'intestazione griderebbe al lupo prima o poi.
 *
 * La HEAD serve lo stesso, ma per un'altra domanda: **c'è, e quanto pesa**. Costa 0,2 secondi e zero
 * byte di corpo, e se la fonte non risponde ci si ferma lì senza scaricare niente.
 */
class VerificaFonteComuniCommand extends Command
{
    protected $signature = 'kondomanager:verifica-fonte-comuni';

    protected $description = 'Chiede a ISTAT se ha pubblicato un elenco di Comuni più recente di quello in uso';

    public function handle(): int
    {
        $nostra = Comune::count() > 0 ? Comune::max('fonte_al') : null;

        if ($nostra === null) {
            $this->warn('In questa installazione l\'elenco dei Comuni non è caricato.');
            $this->line('  Caricalo con <comment>php artisan kondomanager:aggiorna-comuni</comment>, poi rilancia questo controllo.');

            return self::FAILURE;
        }

        $nostra = \Carbon\Carbon::parse($nostra)->toDateString();

        // Prima la domanda economica: la fonte c'è? Una HEAD non porta corpo — 0,2 secondi misurati.
        try {
            $sonda = Http::timeout(15)->head(LettoreElencoIstat::URL);
        } catch (Throwable $e) {
            $this->error('Non riesco a raggiungere ISTAT: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $sonda->successful()) {
            $this->error(sprintf(
                'ISTAT ha risposto %d a %s. Se l\'indirizzo è cambiato, va aggiornato in LettoreElencoIstat::URL.',
                $sonda->status(),
                LettoreElencoIstat::URL
            ));

            return self::FAILURE;
        }

        $peso = (int) $sonda->header('Content-Length');
        $this->line(sprintf('  Fonte raggiungibile%s.', $peso > 0 ? sprintf(' (%.1f MB)', $peso / 1048576) : ''));

        // Poi il file, che serve solo per leggerne il nome del foglio: si scarica in un temporaneo e
        // si legge dallo zip, senza mai aprirlo con PhpSpreadsheet.
        $temporaneo = tempnam(sys_get_temp_dir(), 'istat').'.xlsx';

        try {
            $risposta = Http::timeout(120)->get(LettoreElencoIstat::URL);

            if (! $risposta->successful()) {
                $this->error('ISTAT ha risposto '.$risposta->status().' allo scaricamento.');

                return self::FAILURE;
            }

            file_put_contents($temporaneo, $risposta->body());

            $foglio = LettoreElencoIstat::nomeFoglio($temporaneo);
            $loro = LettoreElencoIstat::dataDa($foglio);
        } catch (Throwable $e) {
            $this->error('Non riesco a leggere la data della fonte: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($temporaneo);
        }

        $questa = fn (string $iso) => \Carbon\Carbon::parse($iso)->format('d/m/Y');

        if ($loro <= $nostra) {
            $this->info(sprintf(
                'L\'elenco in uso è aggiornato: la fonte dichiara %s («%s»), noi %s.',
                $questa($loro),
                $foglio,
                $questa($nostra)
            ));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn(sprintf('ISTAT ha pubblicato un elenco più recente: %s, contro il nostro %s.', $questa($loro), $questa($nostra)));
        $this->newLine();
        $this->line('  Per rigenerare l\'elenco che spediamo col codice:');
        $this->line(sprintf('    <comment>curl -sSL -o comuni.xlsx %s</comment>', LettoreElencoIstat::URL));
        $this->line('    <comment>php artisan kondomanager:aggiorna-comuni --da=comuni.xlsx --scrivi-file</comment>');
        $this->newLine();

        // Esito diverso da zero anche se non è un errore: serve a poterlo mettere in una catena che
        // si ferma, ed è lo stesso senso di «ci sono migrazioni in sospeso».
        return self::FAILURE;
    }
}
