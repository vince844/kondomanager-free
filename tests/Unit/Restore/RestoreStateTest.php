<?php

use App\Services\Restore\RestoreState;

/**
 * Test dello stato su file del ripristino: persistenza atomica, merge,
 * token di step. Test puri: il percorso è passato esplicitamente, nessun
 * framework coinvolto.
 */
function restoreStatePath(): string
{
    return sys_get_temp_dir().'/km-restore-state-test-'.bin2hex(random_bytes(6)).'/state.json';
}

test('put e get: round-trip con updated_at automatico', function () {
    $path = restoreStatePath();
    $state = new RestoreState($path);

    expect($state->exists())->toBeFalse();
    expect($state->get())->toBeNull();

    $written = $state->put([
        'uuid' => 'abc-123',
        'phase' => 'extracting',
        'checkpoint' => ['entry_index' => 42],
    ]);

    expect($written['updated_at'])->toBeInt();
    expect($state->exists())->toBeTrue();

    $read = $state->get();
    expect($read['uuid'])->toBe('abc-123');
    expect($read['phase'])->toBe('extracting');
    expect($read['checkpoint'])->toBe(['entry_index' => 42]);
    expect($read['updated_at'])->toBe($written['updated_at']);

    $state->clear();
    expect($state->exists())->toBeFalse();
    @rmdir(dirname($path));
});

test('merge: le chiavi della patch sostituiscono, le altre restano', function () {
    $path = restoreStatePath();
    $state = new RestoreState($path);

    $state->put(['uuid' => 'abc', 'phase' => 'extracting', 'checkpoint' => ['a' => 1]]);
    $state->merge(['phase' => 'importing_database', 'checkpoint' => ['offset' => 99]]);

    $read = $state->get();
    expect($read['uuid'])->toBe('abc');
    expect($read['phase'])->toBe('importing_database');
    // Il checkpoint viene SOSTITUITO per intero, non fuso ricorsivamente
    expect($read['checkpoint'])->toBe(['offset' => 99]);

    $state->clear();
    @rmdir(dirname($path));
});

test('uno stato corrotto lancia, non finge che non ci sia un ripristino', function () {
    $path = restoreStatePath();
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, '{json corrotto...');

    $state = new RestoreState($path);

    expect(fn () => $state->get())->toThrow(RuntimeException::class);

    // validateToken su stato corrotto: nega, senza esplodere
    expect($state->validateToken('qualunque'))->toBeFalse();

    @unlink($path);
    @rmdir(dirname($path));
});

test('token di step: emissione, validazione, rifiuto e scadenza', function () {
    $path = restoreStatePath();
    $state = new RestoreState($path);
    $state->put(['uuid' => 'abc']);

    $token = $state->issueToken(3600);

    expect(strlen($token))->toBe(64); // 32 byte esadecimali

    // Nello stato vive SOLO l'hash, mai il token in chiaro
    $raw = file_get_contents($path);
    expect($raw)->not->toContain($token);
    expect($state->get()['token_hash'])->toBe(hash('sha256', $token));

    expect($state->validateToken($token))->toBeTrue();
    expect($state->validateToken('token-sbagliato'))->toBeFalse();
    expect($state->validateToken(null))->toBeFalse();
    expect($state->validateToken(''))->toBeFalse();

    // Scaduto: emissione con ttl negativo
    $expired = $state->issueToken(-1);
    expect($state->validateToken($expired))->toBeFalse();

    $state->clear();
    @rmdir(dirname($path));
});

test('la scrittura crea le cartelle mancanti e non lascia file temporanei', function () {
    $path = restoreStatePath(); // la cartella non esiste ancora
    $state = new RestoreState($path);

    $state->put(['uuid' => 'x']);

    expect(is_file($path))->toBeTrue();
    // Nessun residuo .tmp nella cartella
    expect(glob(dirname($path).'/*.tmp.*'))->toBe([]);

    $state->clear();
    @rmdir(dirname($path));
});
