<?php

use App\Services\Restore\RestoreLock;
use App\Services\Restore\RestoreMode;

/**
 * Test del lock flock e del marker di modalità ripristino. Puri, percorsi
 * espliciti in tmp.
 */
function restoreLockDir(): string
{
    $dir = sys_get_temp_dir().'/km-restore-lock-test-'.bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);

    return $dir;
}

test('il lock è esclusivo: un secondo detentore viene respinto', function () {
    $dir = restoreLockDir();
    $path = $dir.'/.restore-lock';

    $first = new RestoreLock($path);
    $second = new RestoreLock($path);

    expect($first->acquire())->toBeTrue();
    expect($first->acquire())->toBeTrue(); // ri-acquisizione dello stesso detentore: ok

    // flock è per file-description: un handle diverso non passa
    expect($second->acquire())->toBeFalse();

    $first->release();
    expect($second->acquire())->toBeTrue();

    $second->release();
    @unlink($path);
    @rmdir($dir);
});

test('il rilascio nel distruttore evita lucchetti fantasma', function () {
    $dir = restoreLockDir();
    $path = $dir.'/.restore-lock';

    $holder = new RestoreLock($path);
    expect($holder->acquire())->toBeTrue();

    unset($holder); // __destruct → release

    $next = new RestoreLock($path);
    expect($next->acquire())->toBeTrue();
    $next->release();

    @unlink($path);
    @rmdir($dir);
});

test('modalità ripristino: enter/active/info/exit', function () {
    $dir = restoreLockDir();
    $path = $dir.'/restore.lock';

    $mode = new RestoreMode($path);

    expect($mode->active())->toBeFalse();
    expect($mode->info())->toBeNull();

    $mode->enter('uuid-di-prova');

    expect($mode->active())->toBeTrue();
    expect($mode->info()['restore_uuid'])->toBe('uuid-di-prova');
    expect($mode->info()['entered_at'])->toBeInt();

    $mode->exit();
    expect($mode->active())->toBeFalse();

    @rmdir($dir);
});
