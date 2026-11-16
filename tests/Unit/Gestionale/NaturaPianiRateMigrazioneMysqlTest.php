<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La natura del piano rate su un MySQL vero: la colonna nasce vuotabile, dopo `tipo`, e la seconda esecuzione non cambia niente. `piani_rate` nasce
 * con il suo `tipo` di MySQL, un `enum('ordinario','straordinario')`: la natura non lo tocca, e un piano già scritto resta con la natura vuota.
 *
 * Database usa e getta con il suffisso del checkout (`kmDatabaseDiProva`), mai il database condiviso `kondomanager-free`. Senza un MySQL locale su
 * 127.0.0.1:3306 il test si salta.
 */
uses(TestCase::class);

defined('KM_NATURA_PIANI_MYSQL_DB') || define('KM_NATURA_PIANI_MYSQL_DB', kmDatabaseDiProva('km_natura_piani_mysql'));

function kmNaturaPianiAdminPdo(): ?PDO
{
    try {
        return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable) {
        return null;
    }
}

/** Le colonne di `piani_rate` dalla `tipo` in poi, con il tipo e il vuotabile, in ordine. */
function kmNaturaPianiColonne(): array
{
    return collect(DB::select('SELECT COLUMN_NAME AS nome, COLUMN_TYPE AS tipo, IS_NULLABLE AS vuota FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', ['piani_rate']))
        ->map(fn ($c) => $c->nome . ' ' . $c->tipo . ($c->vuota === 'YES' ? ' vuota' : ''))->all();
}

beforeEach(function () {
    $admin = kmNaturaPianiAdminPdo();
    if ($admin === null) {
        $this->markTestSkipped('MySQL locale non disponibile.');
    }
    $admin->exec('DROP DATABASE IF EXISTS ' . KM_NATURA_PIANI_MYSQL_DB);
    $admin->exec('CREATE DATABASE ' . KM_NATURA_PIANI_MYSQL_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    config()->set('database.connections.natura_piani_mysql', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => KM_NATURA_PIANI_MYSQL_DB, 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null,
    ]);
    config()->set('database.default', 'natura_piani_mysql');
    DB::purge('natura_piani_mysql');
    DB::reconnect('natura_piani_mysql');

    Schema::create('piani_rate', function (Blueprint $t) {
        $t->id();
        $t->string('nome');
        $t->enum('tipo', ['ordinario', 'straordinario'])->default('ordinario');
        $t->string('tipo_autorizzazione')->nullable();
    });
});

afterEach(function () {
    kmNaturaPianiAdminPdo()?->exec('DROP DATABASE IF EXISTS ' . KM_NATURA_PIANI_MYSQL_DB);
});

it('la natura del piano nasce vuota dopo il tipo, una volta sola; i piani di prima restano senza natura', function () {
    $migrazione = require base_path('database/migrations/2026_11_15_100000_add_natura_to_piani_rate_table.php');
    $piano = DB::table('piani_rate')->insertGetId(['nome' => 'Preventivo 2026', 'tipo' => 'ordinario']);
    $atteso = ['id bigint unsigned', 'nome varchar(255)', "tipo enum('ordinario','straordinario')", 'natura varchar(20) vuota', 'tipo_autorizzazione varchar(255) vuota'];

    $migrazione->up();
    expect(kmNaturaPianiColonne())->toBe($atteso)
        ->and(DB::table('piani_rate')->where('id', $piano)->value('natura'))->toBeNull();

    // La ripresa dopo un'interruzione che ha messo la colonna senza registrare la migrazione.
    $migrazione->up();
    expect(kmNaturaPianiColonne())->toBe($atteso);

    // Un piano degli addebiti si scrive; l'enum del tipo non si tocca.
    DB::table('piani_rate')->insert(['nome' => 'Addebiti individuali 2026 – Ordinaria', 'tipo' => 'ordinario', 'natura' => 'addebiti']);
    expect(DB::table('piani_rate')->where('natura', 'addebiti')->count())->toBe(1);

    $migrazione->down();
    expect(kmNaturaPianiColonne())->toBe(['id bigint unsigned', 'nome varchar(255)', "tipo enum('ordinario','straordinario')", 'tipo_autorizzazione varchar(255) vuota']);
    $migrazione->down();
    $migrazione->up();
    expect(kmNaturaPianiColonne())->toBe($atteso);
})->group('mysql');
