<?php

use App\Services\Restore\ArchiveReader;
use App\Services\Restore\Exceptions\InvalidArchivePasswordException;
use App\Services\Restore\Exceptions\MalformedArchiveException;

/**
 * Test del lettore di archivi: è la superficie di SICUREZZA del ripristino
 * (zip-slip, symlink, allowlist, password AES), quindi gli archivi ostili
 * vengono costruiti ad arte e devono essere respinti PRIMA di toccare il
 * filesystem. Test puri: solo ZipArchive e cartelle temporanee.
 */
function archiveReaderDir(): string
{
    $dir = sys_get_temp_dir().'/km-archive-reader-test-'.bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);

    return $dir;
}

function buildArchive(string $path, array $entries, ?string $password = null): void
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($password !== null) {
        $zip->setPassword($password);
    }

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);

        if ($password !== null) {
            $zip->setEncryptionName($name, ZipArchive::EM_AES_256);
        }
    }

    $zip->close();
}

function validArchiveEntries(): array
{
    return [
        'manifest.json' => json_encode(['manifest_format' => 1, 'contents' => 'full', 'app' => ['version' => '1.10.0-beta.13']]),
        'db/database.sql' => "SET NAMES utf8mb4;\nSELECT 1;\n",
        'files/.env' => "APP_KEY=base64:test\n",
        'files/storage/app/private/doc.pdf' => str_repeat('PDF', 2000),
    ];
}

function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

test('archivio valido: manifest leggibile ed estrazione completa', function () {
    $dir = archiveReaderDir();
    $archive = $dir.'/backup.zip';
    buildArchive($archive, validArchiveEntries());

    $reader = new ArchiveReader($archive);

    expect($reader->isEncrypted())->toBeFalse();
    expect($reader->readManifest()['manifest_format'])->toBe(1);
    expect($reader->entryCount())->toBe(4);

    $target = $dir.'/estratto';
    $result = $reader->extractBatch($target, 0);

    expect($result['done'])->toBeTrue();
    expect($result['files'])->toBe(4);
    expect(file_get_contents($target.'/db/database.sql'))->toBe("SET NAMES utf8mb4;\nSELECT 1;\n");
    expect(file_get_contents($target.'/files/.env'))->toBe("APP_KEY=base64:test\n");
    expect(is_file($target.'/files/storage/app/private/doc.pdf'))->toBeTrue();

    $reader->close();
    rrmdir($dir);
});

test('estrazione a batch da 1 voce: riprendibile per indice', function () {
    $dir = archiveReaderDir();
    $archive = $dir.'/backup.zip';
    buildArchive($archive, validArchiveEntries());

    $reader = new ArchiveReader($archive);
    $target = $dir.'/estratto';

    $index = 0;
    $rounds = 0;

    do {
        $result = $reader->extractBatch($target, $index, maxEntries: 1);
        $index = $result['nextIndex'];
        $rounds++;
    } while (! $result['done'] && $rounds < 20);

    expect($rounds)->toBe(4);
    expect(is_file($target.'/manifest.json'))->toBeTrue();
    expect(is_file($target.'/files/storage/app/private/doc.pdf'))->toBeTrue();

    $reader->close();
    rrmdir($dir);
});

test('archivio cifrato: password giusta apre, sbagliata o assente no', function () {
    $dir = archiveReaderDir();
    $archive = $dir.'/backup.zip';
    buildArchive($archive, validArchiveEntries(), 'password-giusta');

    // Password corretta
    $reader = new ArchiveReader($archive, 'password-giusta');
    expect($reader->isEncrypted())->toBeTrue();
    expect($reader->readManifest()['contents'])->toBe('full');

    $target = $dir.'/estratto';
    $result = $reader->extractBatch($target, 0);
    expect($result['done'])->toBeTrue();
    expect(file_get_contents($target.'/files/.env'))->toBe("APP_KEY=base64:test\n");
    $reader->close();

    // Password errata
    $wrong = new ArchiveReader($archive, 'password-sbagliata');
    expect(fn () => $wrong->readManifest())->toThrow(InvalidArchivePasswordException::class);
    $wrong->close();

    // Password assente
    $missing = new ArchiveReader($archive);
    expect(fn () => $missing->readManifest())->toThrow(InvalidArchivePasswordException::class);
    $missing->close();

    rrmdir($dir);
});

test('zip-slip: traversal, percorsi assoluti e backslash vengono respinti', function () {
    $hostileNames = [
        '../evil.txt',
        'files/../../evil.txt',
        '/etc/evil.txt',
        'files\\evil.txt',
        'files/./nascosto.txt',
    ];

    foreach ($hostileNames as $hostile) {
        $dir = archiveReaderDir();
        $archive = $dir.'/backup.zip';
        buildArchive($archive, validArchiveEntries() + [$hostile => 'contenuto ostile']);

        $reader = new ArchiveReader($archive);
        $target = $dir.'/estratto';

        expect(fn () => $reader->extractBatch($target, 0))
            ->toThrow(MalformedArchiveException::class, exceptionMessage: null);

        // Niente è finito FUORI dalla cartella di destinazione
        expect(is_file($dir.'/evil.txt'))->toBeFalse();
        expect(is_file(sys_get_temp_dir().'/evil.txt'))->toBeFalse();

        $reader->close();
        rrmdir($dir);
    }
});

test('voci fuori dall allowlist vengono respinte', function () {
    $dir = archiveReaderDir();
    $archive = $dir.'/backup.zip';
    buildArchive($archive, validArchiveEntries() + ['evil.php' => '<?php echo "no";']);

    $reader = new ArchiveReader($archive);

    expect(fn () => $reader->extractBatch($dir.'/estratto', 0))
        ->toThrow(MalformedArchiveException::class);

    $reader->close();
    rrmdir($dir);
});

test('le voci symlink vengono respinte', function () {
    $dir = archiveReaderDir();
    $archive = $dir.'/backup.zip';

    $zip = new ZipArchive;
    $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach (validArchiveEntries() as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    // Una voce marcata come symlink negli attributi esterni Unix
    $zip->addFromString('files/collegamento', '/etc/passwd');
    $zip->setExternalAttributesName('files/collegamento', ZipArchive::OPSYS_UNIX, (0120777 << 16));
    $zip->close();

    $reader = new ArchiveReader($archive);

    expect(fn () => $reader->extractBatch($dir.'/estratto', 0))
        ->toThrow(MalformedArchiveException::class);

    $reader->close();
    rrmdir($dir);
});

test('un file che non è uno zip viene respinto', function () {
    $dir = archiveReaderDir();
    $archive = $dir.'/finto.zip';
    file_put_contents($archive, 'non sono uno zip');

    $reader = new ArchiveReader($archive);

    expect(fn () => $reader->readManifest())->toThrow(MalformedArchiveException::class);

    rrmdir($dir);
});

test('un archivio senza manifest non è un backup di KondoManager', function () {
    $dir = archiveReaderDir();
    $archive = $dir.'/backup.zip';
    buildArchive($archive, ['db/database.sql' => 'SELECT 1;']);

    $reader = new ArchiveReader($archive);

    expect(fn () => $reader->readManifest())->toThrow(MalformedArchiveException::class);

    $reader->close();
    rrmdir($dir);
});
