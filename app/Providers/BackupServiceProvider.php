<?php

namespace App\Providers;

use App\Contracts\Backup\DatabaseDumperInterface;
use App\Services\Backup\Database\MySqlDumper;
use App\Services\Backup\Database\SqliteDumper;
use App\Services\Backup\Destinations\DestinationManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class BackupServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Singleton: il plugin backup vi registra destinazioni aggiuntive
        // dal proprio provider tramite extend().
        $this->app->singleton(DestinationManager::class);

        // Il dumper viene scelto in base al driver del database di default.
        // Il plugin backup può fare override di questo binding (es. fast-path
        // mysqldump su VPS) senza toccare il core.
        $this->app->bind(DatabaseDumperInterface::class, function ($app) {
            $driver = DB::connection()->getDriverName();

            return match ($driver) {
                'mysql', 'mariadb' => $app->make(MySqlDumper::class),
                'sqlite' => $app->make(SqliteDumper::class),
                // Il preflight blocca la creazione del backup molto prima di
                // arrivare qui: questa è solo una rete di sicurezza.
                default => throw new RuntimeException("Driver database [{$driver}] non supportato dal backup."),
            };
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
