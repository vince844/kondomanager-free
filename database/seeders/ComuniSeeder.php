<?php

namespace Database\Seeders;

use App\Models\Comune;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Porta a database l'elenco dei Comuni italiani, che viaggia come file nel repository.
 *
 * Delega al comando invece di riscriverne la logica: la validazione del file, la scrittura a blocchi
 * e la chiave sul codice catastale stanno in un posto solo. È anche la ragione per cui un
 * amministratore può rilanciarlo a mano con `php artisan kondomanager:aggiorna-comuni`.
 *
 * ## Chi lo chiama, davvero
 *
 * Due posti, e vanno nominati entrambi perché il primo non basta:
 *
 * 1. `DatabaseSeeder`, cioè **la prima installazione**;
 * 2. `SystemFinalizer::caricaElencoComuni()`, cioè **l'aggiornamento e il ripristino di un backup**.
 *
 * ⚠️ Il secondo non è ridondante. `db:seed` **intero non viene mai eseguito in aggiornamento**, per
 * scelta dichiarata in `SystemFinalizer`: senza quella chiamata mirata questa classe girerebbe solo
 * su installazioni nuove, e su tutto il parco già installato la tabella resterebbe vuota per sempre.
 * La prima stesura di questo file affermava il contrario, ed era falsa.
 */
class ComuniSeeder extends Seeder
{
    public function run(): void
    {
        // In prima installazione l'ordine dei passi è garantito, ma un ripristino o un
        // aggiornamento interrotto può arrivare qui con la migrazione non ancora applicata.
        if (! Schema::hasTable('comuni')) {
            return;
        }

        $percorso = resource_path('data/comuni/comuni-italiani.json');

        if (! is_file($percorso)) {
            Log::warning('Elenco comuni: il file spedito col codice non esiste', ['percorso' => $percorso]);

            return;
        }

        $doc = json_decode(file_get_contents($percorso), true);
        $dataSpedita = is_array($doc) ? ($doc['aggiornato_al'] ?? null) : null;

        if ($dataSpedita === null) {
            Log::warning('Elenco comuni: il file spedito non dichiara la data della fonte');

            return;
        }

        // ⚠️ Si confronta con la data **massima** in tabella, non con l'esistenza di una riga più
        // recente. La differenza conta: la via d'uscita `--da` permette di caricare un elenco
        // parziale, e una sola riga datata avanti basterebbe a far saltare per sempre il
        // caricamento di tutte le altre 7.894.
        $inTabella = Comune::count() > 0 ? Comune::max('fonte_al') : null;

        if ($inTabella !== null
            && Comune::count() >= 7000
            && \Carbon\Carbon::parse($inTabella)->toDateString() >= $dataSpedita) {
            return;
        }

        // L'esito **si guarda**: un file corrotto fa fallire il comando in silenzio, e senza questa
        // riga un'installazione con la tabella vuota risulterebbe riuscita.
        $esito = Artisan::call('kondomanager:aggiorna-comuni');

        if ($esito !== 0) {
            Log::warning('Elenco comuni: il caricamento è fallito', [
                'esito'  => $esito,
                'output' => trim(Artisan::output()),
            ]);
        }
    }
}
