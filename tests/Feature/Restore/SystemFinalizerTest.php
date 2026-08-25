<?php

use App\Services\System\SystemFinalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Il SystemFinalizer è la finalizzazione condivisa tra auto-update e
 * ripristino backup (design doc §7-bis): migrazioni, riallineamento della
 * versione nel database, cache. Qui si verifica il contratto centrale:
 * dopo finalize(), la versione nel DB è quella dei file — è la risposta al
 * caso "database 1.9.1 con codice 1.10".
 */
test('finalize riallinea la versione nel database a quella del codice', function () {
    // Simula un database ripristinato da un backup di versione più vecchia
    DB::table('settings')
        ->where('group', 'general')
        ->where('name', 'version')
        ->update(['payload' => json_encode('1.9.1')]);

    app(SystemFinalizer::class)->finalize();

    $version = json_decode(
        DB::table('settings')->where('group', 'general')->where('name', 'version')->value('payload'),
        true
    );

    expect($version)->toBe(config('app.version'));
});

test('finalize è idempotente su un sistema già allineato', function () {
    app(SystemFinalizer::class)->finalize();
    app(SystemFinalizer::class)->finalize();

    $version = json_decode(
        DB::table('settings')->where('group', 'general')->where('name', 'version')->value('payload'),
        true
    );

    expect($version)->toBe(config('app.version'));
});

test('alignDatabaseVersion aggiorna solo la riga della versione', function () {
    $appNameBefore = DB::table('settings')->where('group', 'general')->where('name', 'app_name')->value('payload');

    DB::table('settings')
        ->where('group', 'general')
        ->where('name', 'version')
        ->update(['payload' => json_encode('0.0.1')]);

    app(SystemFinalizer::class)->alignDatabaseVersion();

    expect(json_decode(DB::table('settings')->where('group', 'general')->where('name', 'version')->value('payload'), true))
        ->toBe(config('app.version'));
    expect(DB::table('settings')->where('group', 'general')->where('name', 'app_name')->value('payload'))
        ->toBe($appNameBefore);
});
