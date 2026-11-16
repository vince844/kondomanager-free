<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Le tre migrazioni del rendiconto conservato su un MySQL vero: l'autore dei documenti che lascia vuoto, la bozza della nota, le versioni.
 *
 * Sulla suite, che gira su SQLite, una chiave esterna si toglie e si rimette riscrivendo la tabella: gli stati a metà che un'interruzione lascia su
 * MySQL (la colonna senza la sua chiave, la chiave in cascata già tolta con la colonna ancora obbligatoria) lì non esistono come stati distinti. Qui
 * si ricostruiscono a mano su un MySQL vero e si riprende la migrazione: deve finire con la chiave giusta, una sola per colonna, e la seconda
 * esecuzione non deve cambiare niente.
 *
 * Le tabelle di arrivo sono ridotte alle colonne che le chiavi leggono; `documenti` nasce come la crea la sua migrazione del 2025, con l'autore
 * obbligatorio e in cascata.
 *
 * Database usa e getta con il suffisso del checkout (`kmDatabaseDiProva`), mai il database condiviso `kondomanager-free`. Senza un MySQL locale su
 * 127.0.0.1:3306 il test si salta.
 */
uses(TestCase::class);

defined('KM_RENDICONTO_MYSQL_DB') || define('KM_RENDICONTO_MYSQL_DB', kmDatabaseDiProva('km_rendiconto_mysql'));

function kmRendicontoAdminPdo(): ?PDO
{
    try {
        return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable) {
        return null;
    }
}

/** Le chiavi esterne di una tabella su una colonna: «colonna>arrivo regola», in ordine. */
function kmRendicontoChiavi(string $tabella): array
{
    return collect(DB::select('
        SELECT k.COLUMN_NAME AS colonna, k.REFERENCED_TABLE_NAME AS arrivo, r.DELETE_RULE AS regola
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL', [$tabella]))
        ->map(fn ($k) => $k->colonna . '>' . $k->arrivo . ' ' . strtolower($k->regola))->sort()->values()->all();
}

function kmRendicontoVuotabile(string $tabella, string $colonna): bool
{
    return DB::selectOne('SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$tabella, $colonna])->n === 'YES';
}

beforeEach(function () {
    $admin = kmRendicontoAdminPdo();
    if ($admin === null) {
        $this->markTestSkipped('MySQL locale non disponibile.');
    }
    $admin->exec('DROP DATABASE IF EXISTS ' . KM_RENDICONTO_MYSQL_DB);
    $admin->exec('CREATE DATABASE ' . KM_RENDICONTO_MYSQL_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    config()->set('database.connections.rendiconto_mysql', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => KM_RENDICONTO_MYSQL_DB, 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null,
    ]);
    config()->set('database.default', 'rendiconto_mysql');
    DB::purge('rendiconto_mysql');
    DB::reconnect('rendiconto_mysql');

    Schema::create('users', fn (Blueprint $t) => $t->id());
    Schema::create('condomini', fn (Blueprint $t) => $t->id());
    Schema::create('esercizi', fn (Blueprint $t) => $t->id());
    Schema::create('documenti', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->foreignId('created_by')->constrained('users')->onDelete('cascade');
        $t->unsignedBigInteger('updated_by')->nullable();
        $t->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
    });
});

afterEach(function () {
    kmRendicontoAdminPdo()?->exec('DROP DATABASE IF EXISTS ' . KM_RENDICONTO_MYSQL_DB);
});

it('l\'autore dei documenti: dalla cascata a «lascia vuoto» anche ripresa dopo il primo e dopo il secondo passo; l\'utente eliminato lascia il documento', function () {
    $migrazione = require base_path('database/migrations/2026_11_14_100000_documenti_created_by_null_on_delete.php');
    $stato = fn () => ['vuotabile' => kmRendicontoVuotabile('documenti', 'created_by'), 'chiavi' => kmRendicontoChiavi('documenti')];
    $atteso = ['vuotabile' => true, 'chiavi' => ['created_by>users set null', 'updated_by>users set null']];
    expect($stato())->toBe(['vuotabile' => false, 'chiavi' => ['created_by>users cascade', 'updated_by>users set null']]);

    $migrazione->up();
    expect($stato())->toBe($atteso);
    $migrazione->up();
    expect($stato())->toBe($atteso);

    // Interrotta dopo il primo passo: la chiave in cascata tolta, la colonna ancora obbligatoria.
    $migrazione->down();
    DB::statement('ALTER TABLE documenti DROP FOREIGN KEY documenti_created_by_foreign');
    expect($stato())->toBe(['vuotabile' => false, 'chiavi' => ['updated_by>users set null']]);
    $migrazione->up();
    expect($stato())->toBe($atteso);

    // Interrotta dopo il secondo passo: la colonna vuotabile, la chiave assente.
    DB::statement('ALTER TABLE documenti DROP FOREIGN KEY documenti_created_by_foreign');
    expect($stato())->toBe(['vuotabile' => true, 'chiavi' => ['updated_by>users set null']]);
    $migrazione->up();
    $migrazione->up();
    expect($stato())->toBe($atteso)
        // Un indice solo sulla colonna: quello che la chiave usa, non uno nuovo a ogni ripresa.
        ->and(collect(DB::select("SHOW INDEX FROM documenti WHERE Column_name = 'created_by'"))->pluck('Key_name')->unique()->values()->all())
        ->toBe(['documenti_created_by_foreign']);

    // L'utente eliminato: il documento resta, senza autore.
    $utente = DB::table('users')->insertGetId([]);
    $documento = DB::table('documenti')->insertGetId(['name' => 'Rendiconto approvato', 'created_by' => $utente]);
    DB::table('users')->where('id', $utente)->delete();
    expect(DB::table('documenti')->where('id', $documento)->value('created_by'))->toBeNull();
})->group('mysql');

it('la bozza della nota e le versioni: riprese dopo la tabella mettono l\'indice e le chiavi una volta; un esercizio con una versione non si elimina', function () {
    $nota = require base_path('database/migrations/2026_11_14_100100_create_note_sintetiche_table.php');
    $versioni = require base_path('database/migrations/2026_11_14_100200_create_rendiconto_versioni_table.php');
    $nota->up();
    $versioni->up();
    $attesoNota = ['aggiornata_da>users set null', 'condominio_id>condomini cascade', 'esercizio_id>esercizi cascade'];
    $attesoVersioni = ['approvata_da>users set null', 'condominio_id>condomini restrict', 'creata_da>users set null', 'esercizio_id>esercizi restrict',
        'verbale_documento_id>documenti set null'];
    $indici = fn (string $t) => collect(DB::select("SHOW INDEX FROM {$t} WHERE Non_unique = 0 AND Key_name <> 'PRIMARY'"))->pluck('Key_name')->unique()->values()->all();
    expect(kmRendicontoChiavi('note_sintetiche'))->toBe($attesoNota)
        ->and(kmRendicontoChiavi('rendiconto_versioni'))->toBe($attesoVersioni)
        ->and($indici('note_sintetiche'))->toBe(['note_sintetiche_esercizio_unica'])
        ->and($indici('rendiconto_versioni'))->toBe(['rendiconto_versioni_numero_unico']);

    // Interrotte dopo la tabella: colonna presente, vincolo assente; e l'indice unico che manca.
    DB::statement('ALTER TABLE rendiconto_versioni DROP FOREIGN KEY rendiconto_versioni_esercizio_id_foreign');
    DB::statement('ALTER TABLE rendiconto_versioni DROP FOREIGN KEY rendiconto_versioni_verbale_documento_id_foreign');
    DB::statement('ALTER TABLE rendiconto_versioni DROP INDEX rendiconto_versioni_numero_unico');
    DB::statement('ALTER TABLE note_sintetiche DROP FOREIGN KEY note_sintetiche_esercizio_id_foreign');
    DB::statement('ALTER TABLE note_sintetiche DROP INDEX note_sintetiche_esercizio_unica');
    expect(kmRendicontoChiavi('rendiconto_versioni'))->toBe(['approvata_da>users set null', 'condominio_id>condomini restrict', 'creata_da>users set null']);

    foreach ([$nota, $versioni, $nota, $versioni] as $m) {
        $m->up();
    }
    expect(kmRendicontoChiavi('note_sintetiche'))->toBe($attesoNota)
        ->and(kmRendicontoChiavi('rendiconto_versioni'))->toBe($attesoVersioni)
        ->and($indici('note_sintetiche'))->toBe(['note_sintetiche_esercizio_unica'])
        ->and($indici('rendiconto_versioni'))->toBe(['rendiconto_versioni_numero_unico']);

    // L'esercizio con una versione: MySQL rifiuta di eliminarlo (1451), e l'esercizio resta.
    $condominio = DB::table('condomini')->insertGetId([]);
    $esercizio = DB::table('esercizi')->insertGetId([]);
    DB::table('rendiconto_versioni')->insert(['condominio_id' => $condominio, 'esercizio_id' => $esercizio, 'numero' => 1, 'stato' => 'presentata', 'forma' => 1,
        'cifre' => '{}', 'calcolato_il' => '2027-03-01 10:00:00']);
    expect(fn () => DB::table('esercizi')->where('id', $esercizio)->delete())->toThrow(\Illuminate\Database\QueryException::class, '1451')
        ->and(fn () => DB::table('condomini')->where('id', $condominio)->delete())->toThrow(\Illuminate\Database\QueryException::class, '1451')
        ->and(DB::table('esercizi')->where('id', $esercizio)->exists())->toBeTrue();
})->group('mysql');
