<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Il calendario dell'accordo di rientro su un MySQL vero: la tabella delle rate, il suo indice unico, la sua chiave esterna e le quattro colonne
 * sull'accordo sono sette statement, e il DDL di MySQL non è transazionale. Gli stati a metà si ricostruiscono a mano (la tabella senza l'indice né la
 * chiave, con una colonna sola sull'accordo; la colonna senza il vincolo) e la migrazione ripresa li completa, una chiave sola; la seconda esecuzione
 * non cambia niente.
 *
 * Database usa e getta con il suffisso del checkout (`kmDatabaseDiProva`), mai il database condiviso `kondomanager-free`. Senza un MySQL locale su
 * 127.0.0.1:3306 il test si salta.
 */
uses(TestCase::class);

defined('KM_CALENDARIO_ACCORDO_MYSQL_DB') || define('KM_CALENDARIO_ACCORDO_MYSQL_DB', kmDatabaseDiProva('km_calendario_accordo_mysql'));

function kmCalendarioAccordoAdminPdo(): ?PDO
{
    try {
        return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable) {
        return null;
    }
}

/** Gli indici e le chiavi esterne della tabella delle rate, e le colonne nuove dell'accordo, letti da `information_schema`. */
function kmCalendarioAccordoStato(): array
{
    return [
        'indici' => collect(DB::select("SELECT DISTINCT INDEX_NAME AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accordo_rientro_scadenze' AND INDEX_NAME <> 'PRIMARY'"))
            ->pluck('n')->sort()->values()->all(),
        'chiavi' => collect(DB::select("
            SELECT k.COLUMN_NAME AS colonna, k.REFERENCED_TABLE_NAME AS arrivo, r.DELETE_RULE AS regola
            FROM information_schema.KEY_COLUMN_USAGE k
            JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'accordo_rientro_scadenze' AND k.REFERENCED_TABLE_NAME IS NOT NULL"))
            ->map(fn ($k) => $k->colonna . '>' . $k->arrivo . ' ' . strtolower($k->regola))->sort()->values()->all(),
        'colonne' => collect(DB::select("SELECT COLUMN_NAME AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accordi_rientro'
            AND COLUMN_NAME IN ('decadenza_rate', 'decadenza_giorni', 'segnali', 'decadenza_registrata_il')"))->pluck('n')->sort()->values()->all(),
    ];
}

beforeEach(function () {
    $admin = kmCalendarioAccordoAdminPdo();
    if ($admin === null) {
        $this->markTestSkipped('MySQL locale non disponibile.');
    }
    $admin->exec('DROP DATABASE IF EXISTS ' . KM_CALENDARIO_ACCORDO_MYSQL_DB);
    $admin->exec('CREATE DATABASE ' . KM_CALENDARIO_ACCORDO_MYSQL_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    config()->set('database.connections.calendario_accordo_mysql', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => KM_CALENDARIO_ACCORDO_MYSQL_DB, 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null,
    ]);
    config()->set('database.default', 'calendario_accordo_mysql');
    DB::purge('calendario_accordo_mysql');
    DB::reconnect('calendario_accordo_mysql');

    // L'accordo com'è prima: le colonne dopo cui le nuove si mettono ci sono.
    Schema::create('accordi_rientro', function (Blueprint $t) {
        $t->id();
        $t->text('nota')->nullable();
        $t->date('chiuso_il')->nullable();
    });
});

afterEach(function () {
    kmCalendarioAccordoAdminPdo()?->exec('DROP DATABASE IF EXISTS ' . KM_CALENDARIO_ACCORDO_MYSQL_DB);
});

it('il calendario dell\'accordo: ripreso dopo la tabella e dopo la colonna senza il vincolo mette l\'indice, la chiave e le colonne una volta; l\'accordo eliminato porta via le sue rate', function () {
    $migrazione = require base_path('database/migrations/2026_11_15_120000_add_calendario_e_decadenza_to_accordi_rientro.php');
    $atteso = [
        'indici' => ['accordo_rientro_scadenze_numero_unico'],
        'chiavi' => ['accordo_rientro_id>accordi_rientro cascade'],
        'colonne' => ['decadenza_giorni', 'decadenza_rate', 'decadenza_registrata_il', 'segnali'],
    ];

    $migrazione->up();
    expect(kmCalendarioAccordoStato())->toBe($atteso);
    $migrazione->up();
    expect(kmCalendarioAccordoStato())->toBe($atteso);

    // Interrotta dopo la tabella e la prima colonna: niente indice, niente chiave, tre colonne mancano. L'indice unico comincia con la colonna della
    // chiave, e MySQL lo usa per lei: si toglie prima la chiave.
    DB::statement('ALTER TABLE accordo_rientro_scadenze DROP FOREIGN KEY accordo_rientro_scadenze_accordo_rientro_id_foreign');
    DB::statement('ALTER TABLE accordo_rientro_scadenze DROP INDEX accordo_rientro_scadenze_numero_unico');
    DB::statement('ALTER TABLE accordi_rientro DROP COLUMN decadenza_giorni, DROP COLUMN segnali, DROP COLUMN decadenza_registrata_il');
    expect(kmCalendarioAccordoStato())->toBe(['indici' => [], 'chiavi' => [], 'colonne' => ['decadenza_rate']]);
    $migrazione->up();
    expect(kmCalendarioAccordoStato())->toBe($atteso);

    // La colonna c'è, il vincolo no: la ripresa mette una chiave sola.
    DB::statement('ALTER TABLE accordo_rientro_scadenze DROP FOREIGN KEY accordo_rientro_scadenze_accordo_rientro_id_foreign');
    expect(kmCalendarioAccordoStato()['chiavi'])->toBe([]);
    $migrazione->up();
    $migrazione->up();
    expect(kmCalendarioAccordoStato())->toBe($atteso)
        ->and(collect(DB::select("SELECT CONSTRAINT_NAME AS n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'accordo_rientro_scadenze'"))->count())->toBe(1);

    // Le rate del calendario se ne vanno con l'accordo; due rate con lo stesso numero non stanno. I segnali si leggono come JSON.
    $a = DB::table('accordi_rientro')->insertGetId(['nota' => null, 'segnali' => json_encode(['giorni' => ['il' => '2026-09-16']])]);
    DB::table('accordo_rientro_scadenze')->insert(['accordo_rientro_id' => $a, 'numero' => 1, 'data' => '2026-07-31', 'importo' => 7531]);
    expect(fn () => DB::table('accordo_rientro_scadenze')->insert(['accordo_rientro_id' => $a, 'numero' => 1, 'data' => '2026-08-31', 'importo' => 7531]))
        ->toThrow(\Illuminate\Database\QueryException::class);
    expect(json_decode((string) DB::table('accordi_rientro')->value('segnali'), true))->toBe(['giorni' => ['il' => '2026-09-16']]);
    DB::table('accordi_rientro')->where('id', $a)->delete();
    expect(DB::table('accordo_rientro_scadenze')->count())->toBe(0);

    $migrazione->down();
    $migrazione->down();
    expect(Schema::hasTable('accordo_rientro_scadenze'))->toBeFalse()->and(kmCalendarioAccordoStato()['colonne'])->toBe([]);
})->group('mysql');
