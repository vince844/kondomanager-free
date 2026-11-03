<?php

namespace Database\Seeders;

use App\Models\CodiceAteco;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Porta a database la classificazione ATECO, che viaggia come file nel repository.
 *
 * Delega al comando invece di riscriverne la logica: la validazione del file, la scrittura a blocchi
 * e la chiave sul codice stanno in un posto solo. È anche la ragione per cui un amministratore può
 * rilanciarlo a mano con `php artisan kondomanager:aggiorna-ateco`.
 *
 * ## Chi lo chiama, davvero
 *
 * Due posti, e vanno nominati entrambi perché il primo non basta:
 *
 * 1. `DatabaseSeeder`, cioè **la prima installazione**;
 * 2. `SystemFinalizer::caricaClassificazioneAteco()`, cioè **l'aggiornamento e il ripristino**.
 *
 * ⚠️ **Il secondo non è ridondante, ed è il difetto che questa classe è nata per chiudere.** La
 * prima stesura della beta.8 aveva la migrazione, il comando, l'endpoint e il componente — e
 * **nessuno dei due agganci**: la tabella nasceva vuota e restava vuota su ogni installazione, nuova
 * o aggiornata. Il pulsante compariva accanto al campo e non trovava mai niente, mostrando «la
 * classificazione non è ancora stata caricata su questa installazione», cioè dando la colpa
 * all'installazione di qualcosa che non le era mai stato consegnato. Nessun errore, nessun log,
 * suite verde. È **lo stesso guasto della beta.59 sui Comuni**, che aveva prodotto apposta
 * `ElencoComuniInAggiornamentoTest`: la macchina era stata copiata, la consegna no.
 *
 * ## La guardia non può essere su una data, perché una data non c'è
 *
 * Sui Comuni si confronta `max(fonte_al)` con la data dichiarata dal file. Nel file ATECO **una data
 * non esiste** — verificata cella per cella — quindi il confronto è sulla **revisione**: se la
 * tabella ha già la classificazione spedita, e ne ha abbastanza righe da non essere un caricamento
 * interrotto a metà, non si riscrive niente. Senza questa guardia `finalize()` riscriverebbe 3.257
 * righe a ogni aggiornamento.
 */
class AtecoSeeder extends Seeder
{
    /**
     * Sotto questa soglia la tabella è considerata **incompleta**, non «già caricata».
     *
     * La fonte ne ha 3.257: un caricamento interrotto a metà lascerebbe qualche centinaio di righe
     * con la revisione giusta, e senza soglia la guardia lo scambierebbe per un lavoro finito. È lo
     * stesso ragionamento dei Comuni, dove la soglia è 7.000 su 7.894.
     */
    private const RIGHE_MINIME = 3000;

    public function run(): void
    {
        // In prima installazione l'ordine dei passi è garantito, ma un ripristino o un aggiornamento
        // interrotto può arrivare qui con la migrazione non ancora applicata.
        if (! Schema::hasTable('codici_ateco')) {
            return;
        }

        $percorso = base_path(\App\Console\Commands\AggiornaAtecoCommand::ELENCO);

        if (! is_file($percorso)) {
            Log::warning('Classificazione ATECO: il file spedito col codice non esiste', ['percorso' => $percorso]);

            return;
        }

        $doc = json_decode(file_get_contents($percorso), true);
        $versioneSpedita = is_array($doc) ? ($doc['versione'] ?? null) : null;

        if ($versioneSpedita === null) {
            Log::warning('Classificazione ATECO: il file spedito non dichiara la revisione');

            return;
        }

        $gia = CodiceAteco::where('versione_fonte', $versioneSpedita)->count();

        if ($gia >= self::RIGHE_MINIME) {
            return;
        }

        // L'esito **si guarda**: un file corrotto fa fallire il comando in silenzio, e senza questa
        // riga un'installazione con la tabella vuota risulterebbe riuscita.
        $esito = Artisan::call('kondomanager:aggiorna-ateco');

        if ($esito !== 0) {
            Log::warning('Classificazione ATECO: il caricamento è fallito', [
                'esito'  => $esito,
                'output' => trim(Artisan::output()),
            ]);
        }
    }
}
