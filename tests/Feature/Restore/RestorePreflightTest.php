<?php

use App\Services\Restore\Exceptions\IncompatibleBackupException;
use App\Services\Restore\RestorePreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function manifest(array $overrides = []): array
{
    return array_replace_recursive([
        'manifest_format' => 1,
        'contents' => 'full',
        'encrypted' => false,
        'app' => ['name' => 'Kondomanager', 'version' => '1.0.0', 'url' => 'https://origine.test'],
        // Default allineato al driver dell'ambiente di test, così i casi che
        // NON testano il driver non inciampano nel controllo di famiglia.
        'database' => ['driver' => DB::connection()->getDriverName(), 'dump_sha256' => str_repeat('a', 64)],
    ], $overrides);
}

test('accetta un backup di versione uguale o più vecchia e riporta needs_migration', function () {
    $current = config('app.version');

    $result = app(RestorePreflight::class)->inspect(manifest(['app' => ['version' => '1.0.0']]), 1_000_000);

    expect($result['ok'])->toBeTrue();
    expect($result['source_version'])->toBe('1.0.0');
    expect($result['current_version'])->toBe($current);
    expect($result['needs_migration'])->toBeTrue(); // 1.0.0 < versione attuale
    expect($result['contents'])->toBe('full');
});

test('rifiuta un backup di versione più NUOVA del codice (no downgrade)', function () {
    app(RestorePreflight::class)->inspect(manifest(['app' => ['version' => '99.0.0']]), 1000);
})->throws(IncompatibleBackupException::class, 'più recente');

test('rifiuta un formato di manifest sconosciuto', function () {
    app(RestorePreflight::class)->inspect(manifest(['manifest_format' => 99]), 1000);
})->throws(IncompatibleBackupException::class, 'non supportato');

test('rifiuta un driver di database di famiglia diversa', function () {
    // Se l'ambiente è sqlite, un backup mysql è di famiglia diversa (e viceversa)
    $foreign = config('database.default') === 'sqlite' ? 'mysql' : 'sqlite';
    app(RestorePreflight::class)->inspect(manifest(['database' => ['driver' => $foreign]]), 1000);
})->throws(IncompatibleBackupException::class);

test('rileva il cambio di dominio senza bloccare', function () {
    config(['app.url' => 'https://nuovo-dominio.test']);

    $result = app(RestorePreflight::class)->inspect(manifest(), 1000);

    expect($result['url_changes'])->toBeTrue();
    expect($result['source_url'])->toBe('https://origine.test');
    expect($result['current_url'])->toBe('https://nuovo-dominio.test');
});

test('segnala se lo spazio su disco stimato non basta', function () {
    // Richiediamo uno spazio assurdo per forzare enough_space=false
    $result = app(RestorePreflight::class)->inspect(manifest(), PHP_INT_MAX >> 1);

    expect($result['enough_space'])->toBeFalse();
});
