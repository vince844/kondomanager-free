<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * @property \App\Models\User $user
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
    }
}
