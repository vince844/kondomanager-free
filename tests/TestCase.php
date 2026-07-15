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
    protected function setUp(): void
    {
        parent::setUp();

        // Controllo di sicurezza: impedisce ai test di girare sul database reale
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \Exception('ATTENZIONE: Stai cercando di lanciare i test sul database reale! I test devono girare esclusivamente su SQLite in memory. Controlla la configurazione del tuo editor o usa phpunit.xml');
        }

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
    }
}
