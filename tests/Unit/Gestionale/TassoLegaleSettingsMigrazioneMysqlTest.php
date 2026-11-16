<?php

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Il tasso legale scritto a mano su un MySQL vero: una riga nelle impostazioni del programma, `tasso_legale.scritti_a_mano`, con l'elenco vuoto. Una
 * sola scrittura, senza chiavi esterne: la ripresa non la rifà e non tocca un tasso già scritto; il JSON torna com'era.
 *
 * Database usa e getta con il suffisso del checkout (`kmDatabaseDiProva`), mai il database condiviso `kondomanager-free`. Senza un MySQL locale su
 * 127.0.0.1:3306 il test si salta.
 */
uses(TestCase::class);

defined('KM_TASSO_LEGALE_MYSQL_DB') || define('KM_TASSO_LEGALE_MYSQL_DB', kmDatabaseDiProva('km_tasso_legale_mysql'));

function kmTassoLegaleAdminPdo(): ?PDO
{
    try {
        return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable) {
        return null;
    }
}

beforeEach(function () {
    $admin = kmTassoLegaleAdminPdo();
    if ($admin === null) {
        $this->markTestSkipped('MySQL locale non disponibile.');
    }
    $admin->exec('DROP DATABASE IF EXISTS ' . KM_TASSO_LEGALE_MYSQL_DB);
    $admin->exec('CREATE DATABASE ' . KM_TASSO_LEGALE_MYSQL_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    config()->set('database.connections.tasso_legale_mysql', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => KM_TASSO_LEGALE_MYSQL_DB, 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null,
    ]);
    config()->set('database.default', 'tasso_legale_mysql');
    DB::purge('tasso_legale_mysql');
    DB::reconnect('tasso_legale_mysql');

    (require base_path('database/migrations/2022_12_14_083707_create_settings_table.php'))->up();
});

afterEach(function () {
    kmTassoLegaleAdminPdo()?->exec('DROP DATABASE IF EXISTS ' . KM_TASSO_LEGALE_MYSQL_DB);
});

it('la riga del tasso legale scritto a mano nasce vuota una volta sola; ripresa, non tocca un tasso già scritto; tornata indietro si toglie', function () {
    $migrazione = require base_path('database/settings/2026_11_15_130000_create_tasso_legale_settings.php');
    $riga = fn () => DB::table('settings')->where('group', 'tasso_legale')->where('name', 'scritti_a_mano');

    $migrazione->up();
    $migrazione->up();
    expect($riga()->count())->toBe(1)->and(json_decode((string) $riga()->value('payload'), true))->toBe([]);

    $scritto = [['anno' => 2027, 'tasso_e4' => 22500, 'fonte' => 'D.M. 10/12/2026, G.U. n. 290 del 14/12/2026', 'scritto_da' => 3, 'scritto_da_nome' => 'Mario Rossi',
        'scritto_il' => '2026-12-16']];
    $riga()->update(['payload' => json_encode($scritto)]);
    $migrazione->up();
    // La colonna JSON di MySQL riordina le chiavi: le stesse chiavi con gli stessi valori, in un altro ordine.
    expect($riga()->count())->toBe(1)->and(json_decode((string) $riga()->value('payload'), true))->toEqual($scritto);

    $migrazione->down();
    $migrazione->down();
    expect($riga()->count())->toBe(0);
    $migrazione->up();
    expect(json_decode((string) $riga()->value('payload'), true))->toBe([]);
});
