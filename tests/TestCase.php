<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;

/**
 * @property User $user
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Controllo di sicurezza anti-database-reale.
     *
     * DEVE stare qui e non in setUp(): `parent::setUp()` applica i trait — fra cui
     * RefreshDatabase, che esegue `migrate:fresh` — PRIMA che il corpo di setUp()
     * venga eseguito. Con il controllo in setUp() l'eccezione arrivava a database
     * già distrutto: la guardia segnalava un disastro invece di impedirlo.
     *
     * `setUpTraits()` viene invocato da parent::setUp() dopo la creazione
     * dell'applicazione (quindi la config è disponibile) ma prima che i trait
     * tocchino il database: è l'unico punto in cui il blocco è ancora utile.
     */
    protected function setUpTraits(): array
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \Exception(
                'ATTENZIONE: Stai cercando di lanciare i test sul database reale! '
                .'I test devono girare esclusivamente su SQLite in memory. '
                .'Controlla la configurazione del tuo editor o usa phpunit.xml. '
                .'Connessione rilevata: '.config('database.default')
            );
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // I test non devono mai toccare la password di backup reale né i
        // temporanei reali dell'installazione; con --parallel ogni processo
        // usa percorsi propri, altrimenti i set()/clear() e le pulizie dei
        // vari file di test si pesterebbero i piedi a vicenda.
        $token = ParallelTesting::token() ?: 'serial';

        config()->set('backup.password_file', storage_path(
            'framework/testing/backup-password-'.$token
        ));
        config()->set('backup.tmp_path', storage_path(
            'framework/testing/backup-tmp-'.$token
        ));
        config()->set('backup.restore.state_file', storage_path(
            'framework/testing/restore-state-'.$token.'.json'
        ));
        config()->set('backup.restore.lock_file', storage_path(
            'framework/testing/restore-lock-'.$token
        ));
        config()->set('backup.restore.marker_file', storage_path(
            'framework/testing/restore-marker-'.$token
        ));
    }
}
