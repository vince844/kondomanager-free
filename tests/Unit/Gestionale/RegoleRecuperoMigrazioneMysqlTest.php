<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Le regole del recupero su un MySQL vero: la tabella, l'indice unico (condominio, giorno di inizio) e le due chiavi esterne sono quattro statement, e
 * il DDL di MySQL non è transazionale. Gli stati a metà si ricostruiscono a mano (la tabella senza l'indice né le chiavi; la tabella con una chiave sola)
 * e la migrazione ripresa li completa, una chiave per colonna; la seconda esecuzione non cambia niente.
 *
 * Database usa e getta con il suffisso del checkout (`kmDatabaseDiProva`), mai il database condiviso `kondomanager-free`. Senza un MySQL locale su
 * 127.0.0.1:3306 il test si salta.
 */
uses(TestCase::class);

defined('KM_REGOLE_RECUPERO_MYSQL_DB') || define('KM_REGOLE_RECUPERO_MYSQL_DB', kmDatabaseDiProva('km_regole_recupero_mysql'));

function kmRegoleRecuperoAdminPdo(): ?PDO
{
    try {
        return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable) {
        return null;
    }
}

/** Gli indici e le chiavi esterne di `regole_recupero`, letti da `information_schema`. */
function kmRegoleRecuperoStato(): array
{
    return [
        'indici' => collect(DB::select("SELECT DISTINCT INDEX_NAME AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'regole_recupero' AND INDEX_NAME <> 'PRIMARY'"))
            ->pluck('n')->sort()->values()->all(),
        'chiavi' => collect(DB::select("
            SELECT k.COLUMN_NAME AS colonna, k.REFERENCED_TABLE_NAME AS arrivo, r.DELETE_RULE AS regola
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'regole_recupero' AND k.REFERENCED_TABLE_NAME IS NOT NULL"))
            ->map(fn ($k) => $k->colonna . '>' . $k->arrivo . ' ' . strtolower($k->regola))->sort()->values()->all(),
    ];
}

beforeEach(function () {
    $admin = kmRegoleRecuperoAdminPdo();
    if ($admin === null) {
        $this->markTestSkipped('MySQL locale non disponibile.');
    }
    $admin->exec('DROP DATABASE IF EXISTS ' . KM_REGOLE_RECUPERO_MYSQL_DB);
    $admin->exec('CREATE DATABASE ' . KM_REGOLE_RECUPERO_MYSQL_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    config()->set('database.connections.regole_recupero_mysql', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => KM_REGOLE_RECUPERO_MYSQL_DB, 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null,
    ]);
    config()->set('database.default', 'regole_recupero_mysql');
    DB::purge('regole_recupero_mysql');
    DB::reconnect('regole_recupero_mysql');

    Schema::create('users', fn (Blueprint $t) => $t->id());
    Schema::create('condomini', fn (Blueprint $t) => $t->id());
});

afterEach(function () {
    kmRegoleRecuperoAdminPdo()?->exec('DROP DATABASE IF EXISTS ' . KM_REGOLE_RECUPERO_MYSQL_DB);
});

it('le regole del recupero: riprese dopo la tabella e dopo una chiave mettono l\'indice e le chiavi una volta; il condominio eliminato le porta via, l\'utente no', function () {
    $migrazione = require base_path('database/migrations/2026_11_15_110000_create_regole_recupero_table.php');
    $atteso = ['indici' => ['regole_recupero_creata_da_foreign', 'regole_recupero_dal_unico'], 'chiavi' => ['condominio_id>condomini cascade', 'creata_da>users set null']];

    $migrazione->up();
    expect(kmRegoleRecuperoStato())->toBe($atteso);
    $migrazione->up();
    expect(kmRegoleRecuperoStato())->toBe($atteso);

    // Interrotta dopo la tabella: niente indice, niente chiavi.
    DB::statement('ALTER TABLE regole_recupero DROP FOREIGN KEY regole_recupero_condominio_id_foreign');
    DB::statement('ALTER TABLE regole_recupero DROP FOREIGN KEY regole_recupero_creata_da_foreign');
    DB::statement('ALTER TABLE regole_recupero DROP INDEX regole_recupero_creata_da_foreign');
    DB::statement('ALTER TABLE regole_recupero DROP INDEX regole_recupero_dal_unico');
    expect(kmRegoleRecuperoStato())->toBe(['indici' => [], 'chiavi' => []]);
    $migrazione->up();
    expect(kmRegoleRecuperoStato())->toBe($atteso);

    // Interrotta dopo la prima chiave: la seconda manca.
    DB::statement('ALTER TABLE regole_recupero DROP FOREIGN KEY regole_recupero_creata_da_foreign');
    expect(kmRegoleRecuperoStato()['chiavi'])->toBe(['condominio_id>condomini cascade']);
    $migrazione->up();
    $migrazione->up();
    expect(kmRegoleRecuperoStato())->toBe($atteso)
        // Una chiave per colonna: nessun doppione a ogni ripresa.
        ->and(collect(DB::select("SELECT CONSTRAINT_NAME AS n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'regole_recupero'"))->count())->toBe(2);

    // Il condominio eliminato porta via le sue regole; l'utente eliminato le lascia, senza autore. Due regole con lo stesso giorno non stanno.
    $c = DB::table('condomini')->insertGetId([]);
    $u = DB::table('users')->insertGetId([]);
    DB::table('regole_recupero')->insert(['condominio_id' => $c, 'dal' => '2026-01-01', 'tasso_tipo' => 'scritto', 'tasso_annuo' => '3.1250', 'giorni_decorrenza' => 0, 'creata_da' => $u]);
    expect(DB::table('regole_recupero')->value('tasso_annuo'))->toBe('3.1250');
    expect(fn () => DB::table('regole_recupero')->insert(['condominio_id' => $c, 'dal' => '2026-01-01', 'tasso_tipo' => 'legale', 'giorni_decorrenza' => 0]))
        ->toThrow(\Illuminate\Database\QueryException::class);
    DB::table('users')->where('id', $u)->delete();
    expect(DB::table('regole_recupero')->value('creata_da'))->toBeNull();
    DB::table('condomini')->where('id', $c)->delete();
    expect(DB::table('regole_recupero')->count())->toBe(0);

    $migrazione->down();
    $migrazione->down();
    expect(Schema::hasTable('regole_recupero'))->toBeFalse();
})->group('mysql');
