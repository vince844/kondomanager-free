<?php

use App\Models\Gestionale\Subentro;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 1.11.0-beta.38 — la riserva d'usufrutto letta in SQL su MySQL dà gli stessi passaggi della lettura in PHP.
 *
 * `Subentro::vincolaRiservaUsufrutto()` è l'unica condizione JSON nuova della beta (`registro->sottotipo`), e la leggono
 * tutte e due le forme del risolutore (rilievo B4 della Fase 1-bis). Sulla suite, che gira su sqlite, le due forme
 * condividono quella condizione: un errore di dialetto le farebbe sbagliare insieme, e la griglia C di
 * `InvariantiPassaggiTest` le vedrebbe concordare. Qui la stessa domanda si fa a un MySQL vero e si confronta con
 * `Subentro::riservaUsufrutto()`, che legge il registro in PHP.
 *
 * La colonna conta: come MySQL legge `registro->sottotipo` dipende dal tipo della colonna. Per questo `registro` non si
 * inventa: la scrive la migrazione vera della beta.37 (`2026_09_28_100000_add_annullamento_to_subentri_table.php`, `json`
 * nullable), su una tabella `subentri` ridotta alle colonne che la migrazione e il modello leggono, con `tipo_passaggio`
 * definito come nella migrazione che la crea (`string(30)`).
 *
 * Database usa e getta con il suffisso del checkout (`kmDatabaseDiProva`), mai il database condiviso `kondomanager-free`.
 * Senza un MySQL locale su 127.0.0.1:3306 il test si salta.
 *
 * **Cosa resta scoperto**: MySQL 5.7 (in locale c'è l'8; la versione è ancora supportata e la condizione JSON non ha una
 * prova, né qui né a mano); le due forme del risolutore su MySQL con le righe di titolarità vere — qui si prova la sola
 * condizione che condividono.
 */
uses(TestCase::class);

defined('KM_RISERVA_MYSQL_DB') || define('KM_RISERVA_MYSQL_DB', kmDatabaseDiProva('km_riserva_mysql'));

function kmRiservaAdminPdo(): ?PDO
{
    try {
        return new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable) {
        return null;
    }
}

beforeEach(function () {
    $admin = kmRiservaAdminPdo();
    if ($admin === null) {
        $this->markTestSkipped('MySQL locale non disponibile.');
    }
    $admin->exec('DROP DATABASE IF EXISTS ' . KM_RISERVA_MYSQL_DB);
    $admin->exec('CREATE DATABASE ' . KM_RISERVA_MYSQL_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    config()->set('database.connections.riserva_mysql', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => KM_RISERVA_MYSQL_DB, 'username' => 'root', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true, 'engine' => null,
    ]);
    config()->set('database.default', 'riserva_mysql');
    DB::purge('riserva_mysql');
    DB::reconnect('riserva_mysql');
});

afterEach(function () {
    kmRiservaAdminPdo()?->exec('DROP DATABASE IF EXISTS ' . KM_RISERVA_MYSQL_DB);
});

it('la riserva d\'usufrutto letta in SQL su MySQL (`registro->sottotipo`, colonna json nullable della migrazione vera) dà gli stessi passaggi della lettura in PHP, anche con la tabella sotto un alias come nel risolutore', function () {
    // `users` per la chiave esterna di `annullato_da`; `nota_annullamento_conguaglio` perché la migrazione aggiunge dopo di lei.
    Schema::create('users', fn (Blueprint $t) => $t->id());
    Schema::create('subentri', function (Blueprint $t) {
        $t->id();
        $t->string('tipo_passaggio', 30);
        $t->text('nota_annullamento_conguaglio')->nullable();
    });
    (require base_path('database/migrations/2026_09_28_100000_add_annullamento_to_subentri_table.php'))->up();
    $colonna = DB::selectOne("SELECT DATA_TYPE AS tipo, IS_NULLABLE AS nullo FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subentri' AND COLUMN_NAME = 'registro'");
    expect([$colonna->tipo, $colonna->nullo])->toBe(['json', 'YES']);

    $passaggi = [
        'registro nullo (un passaggio di prima della beta.37)' => ['vendita', null, null],
        'registro vuoto' => ['vendita', '{}', null],
        'vendita con riserva' => ['vendita', json_encode(['sottotipo' => Subentro::RISERVA_USUFRUTTO, 'righe' => [['id' => 7, 'operazione' => 'aperta']]]), null],
        'vendita con riserva, solo il sottotipo' => ['vendita', json_encode(['sottotipo' => Subentro::RISERVA_USUFRUTTO]), null],
        'vendita piena con il registro' => ['vendita', json_encode(['righe' => [['id' => 8, 'operazione' => 'chiusa']], 'quota_max_id' => 12]), null],
        'costituzione dell\'usufrutto' => ['usufrutto', json_encode(['sottotipo' => 'costituzione']), null],
        'usufrutto con il sottotipo della riserva' => ['usufrutto', json_encode(['sottotipo' => Subentro::RISERVA_USUFRUTTO]), null],
        'riserva annullata' => ['vendita', json_encode(['sottotipo' => Subentro::RISERVA_USUFRUTTO]), '2026-09-28 10:00:00'],
    ];
    $id = [];
    foreach ($passaggi as $nome => [$tipo, $registro, $annullato]) {
        $id[$nome] = DB::table('subentri')->insertGetId(['tipo_passaggio' => $tipo, 'registro' => $registro, 'annullato_il' => $annullato]);
    }

    $inPhp = Subentro::query()->orderBy('id')->get()->filter(fn (Subentro $s) => $s->riservaUsufrutto())->pluck('id')->map(fn ($x) => (int) $x)->values()->all();
    $inSql = Subentro::vincolaRiservaUsufrutto(DB::table('subentri'))->whereNull('annullato_il')->orderBy('id')->pluck('id')->map(fn ($x) => (int) $x)->all();
    $conAlias = Subentro::vincolaRiservaUsufrutto(DB::table('subentri as s'), 's')->whereNull('s.annullato_il')->orderBy('s.id')->pluck('s.id')->map(fn ($x) => (int) $x)->all();

    $attesi = [$id['vendita con riserva'], $id['vendita con riserva, solo il sottotipo']];
    expect($inPhp)->toBe($attesi)
        ->and($inSql)->toBe($attesi)
        ->and($conAlias)->toBe($attesi);
})->group('mysql');
