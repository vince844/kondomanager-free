<?php

namespace App\Console\Commands;

use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Immobile;
use App\Services\Documenti\SpazioDocumenti;
use App\Support\PersistenzaStorage;
use Illuminate\Console\Command;

/**
 * I numeri dell'installazione, per chi la ospita o la amministra: quanti condomini (i
 * dimostrativi a parte), quante unità, quanti documenti e quanti byte, con i tetti e il disco.
 * `--json` per gli script; senza, una tabella. Non scrive niente.
 */
class StatsCommand extends Command
{
    protected $signature = 'kondomanager:stats {--json : stampa un oggetto JSON invece della tabella}';

    protected $description = 'Conteggi e spazio dell\'installazione (condomini, unità, documenti, byte, tetti, disco)';

    public function handle(SpazioDocumenti $spazio): int
    {
        $dati = [
            'versione' => (string) config('app.version'),
            'condomini' => Condominio::query()->where('is_demo', false)->count(),
            'condomini_dimostrativi' => Condominio::query()->where('is_demo', true)->count(),
            'unita' => Immobile::query()->count(),
            'documenti' => Documento::query()->count(),
            'byte_documenti' => $spazio->usatoByte(),
            'limite_condomini' => (int) config('kondomanager.limite_condomini', 0),
            'limite_spazio_mb' => (int) config('kondomanager.limite_spazio_mb', 0),
            'disco_documenti' => (string) config('kondomanager.disco_documenti', 'local'),
            'documenti_stato' => PersistenzaStorage::statoDocumenti(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($dati, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Voce', 'Valore'], array_map(fn ($k, $v) => [$k, is_int($v) && $k === 'byte_documenti' ? number_format($v, 0, ',', '.') : (string) $v], array_keys($dati), $dati));

        return self::SUCCESS;
    }
}
