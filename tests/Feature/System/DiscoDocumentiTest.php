<?php

/**
 * # Il disco dei documenti si sceglie dalla configurazione (1.11.0-beta.33)
 *
 * I documenti caricati e la firma delle stampe passano da `config('kondomanager.disco_documenti')`
 * e `config('kondomanager.disco_pubblici')`. Il vincolo che questo test presidia è il primo di
 * tutti: **senza la variabile `DOCUMENTI_DISK` i due dischi sono `local` e `public`, cioè le
 * cartelle di sempre** — chi si autoospita e chi installa dallo zip non deve accorgersi di niente.
 * Il secondo: con `s3` i dischi sono quelli S3 con il prefisso come radice, e le `AWS_*` standard.
 *
 * ## Cosa NON copre
 *
 * Non parla con un bucket: prova la funzione «ambiente → disco», non che R2 risponda. Che i
 * cinquanta punti del codice leggano queste chiavi invece di `'local'` lo provano i test dei
 * documenti con `Storage::fake()` sul disco configurato.
 */

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

function kondomanagerConAmbiente(array $variabili): array
{
    $vecchie = [];
    foreach ($variabili as $k => $v) {
        $vecchie[$k] = $_ENV[$k] ?? null;
        if ($v === null) {
            unset($_ENV[$k], $_SERVER[$k]);
        } else {
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
    }

    try {
        return [
            'kondomanager' => require config_path('kondomanager.php'),
            'filesystems' => require config_path('filesystems.php'),
        ];
    } finally {
        foreach ($vecchie as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k], $_SERVER[$k]);
            } else {
                $_ENV[$k] = $_SERVER[$k] = $v;
            }
        }
    }
}

it('senza la variabile i documenti stanno dove sono sempre stati', function () {
    $c = kondomanagerConAmbiente(['DOCUMENTI_DISK' => null])['kondomanager'];

    expect($c['disco_documenti'])->toBe('local')
        ->and($c['disco_pubblici'])->toBe('public')
        ->and($c['disco_documenti_richiesto'])->toBe('local');

    expect(Storage::disk(config('kondomanager.disco_documenti'))->path('documenti/x.pdf'))
        ->toBe(storage_path('app/private/documenti/x.pdf'))
        ->and(Storage::disk(config('kondomanager.disco_pubblici'))->path('firme/f.png'))
        ->toBe(storage_path('app/public/firme/f.png'));
});

it('con s3 i dischi sono quelli S3, con il prefisso come radice', function () {
    $c = kondomanagerConAmbiente(['DOCUMENTI_DISK' => 's3', 'DOCUMENTI_PREFIX' => '/studio-rossi/', 'AWS_BUCKET' => 'kondo', 'AWS_ENDPOINT' => 'https://r2.example', 'AWS_DEFAULT_REGION' => 'auto']);

    expect($c['kondomanager']['disco_documenti'])->toBe('documenti_s3')
        ->and($c['kondomanager']['disco_pubblici'])->toBe('pubblici_s3');

    $d = $c['filesystems']['disks']['documenti_s3'];
    $p = $c['filesystems']['disks']['pubblici_s3'];
    expect($d['driver'])->toBe('s3')
        ->and($d['root'])->toBe('studio-rossi')
        ->and($d['bucket'])->toBe('kondo')
        ->and($d['endpoint'])->toBe('https://r2.example')
        ->and($d['region'])->toBe('auto')
        ->and($p['root'])->toBe('studio-rossi/pubblici');
});

it('senza prefisso la radice è vuota e i pubblici stanno in «pubblici»', function () {
    $f = kondomanagerConAmbiente(['DOCUMENTI_DISK' => 's3', 'DOCUMENTI_PREFIX' => null])['filesystems'];

    expect($f['disks']['documenti_s3']['root'])->toBe('')
        ->and($f['disks']['pubblici_s3']['root'])->toBe('pubblici');
});

it('un valore che non è né local né s3 finisce su local, e resta leggibile com\'era scritto', function () {
    $c = kondomanagerConAmbiente(['DOCUMENTI_DISK' => 'r2'])['kondomanager'];

    expect($c['disco_documenti'])->toBe('local')
        ->and($c['disco_documenti_richiesto'])->toBe('r2');
});

it('documenta le variabili in .env.example, commentate', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain("\n# DOCUMENTI_DISK=local\n")
        ->and($env)->toContain("\n# DOCUMENTI_PREFIX=\n")
        ->and($env)->toContain("\n# LIMITE_SPAZIO_MB=0\n")
        ->and($env)->toContain("\n# GESTIONE_PIANO_URL=\n")
        ->and($env)->not->toMatch('/^DOCUMENTI_DISK=/m');
});

it('il driver S3 è installato: senza, DOCUMENTI_DISK=s3 morirebbe alla prima scrittura', function () {
    expect(class_exists(AwsS3V3Adapter::class))->toBeTrue()
        ->and(class_exists(S3Client::class))->toBeTrue();
});

/*
 * Guardia sul sorgente: fuori da backup, ripristino, importazione e persistenza — che sono stato
 * della macchina e restano locali di proposito — nessun file di `app/` nomina più `local` o
 * `public` a mano. Se uno torna a scriverlo, i documenti di un'installazione su S3 finirebbero nel
 * container, in silenzio, come prima della beta.33.
 */
it('nessun punto di app/ nomina più il disco dei documenti a mano', function () {
    $esclusi = ['app/Services/Backup/', 'app/Services/Restore/', 'app/Services/Import/', 'app/Support/PersistenzaStorage.php', 'app/Services/System/SystemFinalizer.php', 'app/Services/Documenti/ArchivioDocumenti.php'];
    $colpevoli = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relativo = str_replace(base_path().'/', '', $file->getPathname());
        foreach ($esclusi as $e) {
            if (str_starts_with($relativo, $e)) {
                continue 2;
            }
        }
        $sorgente = file_get_contents($file->getPathname());
        // Tre forme: il disco nominato (virgolette singole o doppie), il disco PREDEFINITO — che
        // è `local` — usato con un verbo di Storage, e la cartella scritta come percorso assoluto.
        if (preg_match('/Storage::disk\(["\'](local|public)["\']\)|Storage::(put|putFile|putFileAs|get|delete|exists|missing|download|path|url|size|readStream|writeStream|move|copy)\(|store(?:As)?\([^;]*,\s*["\'](local|public)["\']\)|storage_path\(["\']app\/(private|public)/', $sorgente)) {
            $colpevoli[] = $relativo;
        }
    }

    expect($colpevoli)->toBe([]);
});

it('verifica-persistenza avvisa solo su un valore sconosciuto di DOCUMENTI_DISK, e prova il disco S3', function () {
    config(['kondomanager.disco_documenti_richiesto' => 's3', 'kondomanager.disco_documenti' => 'documenti_s3']);
    Storage::fake('documenti_s3');
    $this->artisan('kondomanager:verifica-persistenza')
        ->doesntExpectOutputToContain('non è un valore conosciuto')
        ->expectsOutputToContain('scrittura, rilettura e cancellazione di prova riuscite')
        ->assertSuccessful();
    expect(Storage::disk('documenti_s3')->allFiles())->toBe([]);

    config(['kondomanager.disco_documenti_richiesto' => 'r2', 'kondomanager.disco_documenti' => 'local']);
    $this->artisan('kondomanager:verifica-persistenza')
        ->expectsOutputToContain('DOCUMENTI_DISK=r2 non è un valore conosciuto');
});
