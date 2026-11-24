<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 755 (3) — il wizard d'installazione non cancella un database che ha già delle tabelle.
 *
 * Fino alla 1.12.0-beta.13 il passo del database scriveva il `.env` e lanciava `migrate:fresh`, che cancella tutte le tabelle di un database che ha la
 * tabella `migrations`, anche quelle di un altro programma; la «verifica» non apriva nessuna connessione. Ora il wizard si ferma se il database ha delle
 * tabelle, di qualunque programma (`ControlloDatabaseVuoto`): prima di scrivere il `.env`, accanto a «Testa connessione», e per cintura dentro
 * `PreparaDatabase::migra(fresh: true)`. Il passo ripetuto sullo stesso database che il wizard ha appena preparato, senza il lock d'installazione, passa.
 *
 * I database delle prove sono file SQLite in una cartella temporanea; il `.env` della copia si rimette com'era alla fine di ogni prova.
 */

use App\Livewire\Installer\FixedEnvironmentSettings;
use App\Livewire\Installer\InstallerWizard;
use App\Services\Installer\ControlloDatabaseVuoto;
use App\Services\Installer\PreparaDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->cartella = storage_path('framework/testing/db-non-vuoto-'.uniqid());
    File::makeDirectory($this->cartella, 0755, true);
    $this->connessionePrima = config('database.default');
    $this->env = base_path('.env');
    $this->envPrima = File::exists($this->env) ? File::get($this->env) : null;
    config([
        'installer.options.progress_file' => $this->cartella.'/progress.json',
        'installer.options.lock_file' => $this->cartella.'/installed.lock',
    ]);
});

afterEach(function () {
    // La connessione di base si rimette prima di tutto: la chiusura della transazione della prova la cerca lì.
    config(['database.default' => $this->connessionePrima]);
    foreach (['installer_controllo', 'installer_test', 'prova_db'] as $nome) {
        DB::purge($nome);
    }
    if ($this->envPrima === null) {
        File::delete($this->env);
    } else {
        File::put($this->env, $this->envPrima);
    }
    File::deleteDirectory($this->cartella);
});

/** Un file SQLite con le tabelle date, ognuna con una riga; la connessione «mysql» delle prove punta lì (il wizard parte sempre da `mysql`). */
function dnvDatabase(array $tabelle, string $nome = 'esistente.sqlite'): string
{
    $percorso = test()->cartella.'/'.$nome;
    $pdo = new PDO('sqlite:'.$percorso);
    foreach ($tabelle as $tabella) {
        $pdo->exec("create table \"{$tabella}\" (id integer primary key, valore text)");
        $pdo->exec("insert into \"{$tabella}\" (valore) values ('da non perdere')");
    }
    $pdo = null;

    config(['database.connections.mysql' => ['driver' => 'sqlite', 'database' => $percorso, 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::purge('mysql');

    return $percorso;
}

/** Le tabelle del file, lette con una connessione a parte. */
function dnvTabelle(string $percorso): array
{
    $pdo = new PDO('sqlite:'.$percorso);
    $nomi = $pdo->query("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name")->fetchAll(PDO::FETCH_COLUMN);

    return $nomi;
}

function dnvDatiDelPasso(string $percorso): array
{
    return ['app_name' => 'Prova', 'app_url' => 'http://localhost', 'app_locale' => 'it', 'db_connection' => 'mysql', 'db_host' => '127.0.0.1', 'db_port' => '3306',
        'db_database' => $percorso, 'db_username' => 'utente', 'db_password' => 'segreta'];
}

function dnvPassoDelWizard(array $dati): void
{
    $wizard = app(InstallerWizard::class);
    $metodo = new ReflectionMethod($wizard, 'runEnvironmentSetup');
    $metodo->setAccessible(true);
    $metodo->invoke($wizard, $dati);
}

it('T6_755_wizard_cancella: con la tabella migrations e una tabella di un altro programma il passo si ferma con il nome del database e il numero, prima del .env, e le tabelle restano con i loro dati', function () {
    $percorso = dnvDatabase(['migrations', 'fatture_altro_programma']);
    config(['installer.requirements.environment.database' => true]);

    $errore = null;
    try {
        dnvPassoDelWizard(dnvDatiDelPasso($percorso));
    } catch (\Throwable $e) {
        $errore = $e;
    }

    expect($errore)->toBeInstanceOf(\DomainException::class)
        ->and($errore->getMessage())->toContain('non è vuoto')->toContain($percorso)->toContain('2 tabelle')
        ->and($errore->getMessage())->toContain('Il programma non lo cancella')
        // il .env non è stato toccato
        ->and(File::exists($this->env) ? File::get($this->env) : null)->toBe($this->envPrima)
        // le tabelle e i dati sono ancora lì
        ->and(dnvTabelle($percorso))->toBe(['fatture_altro_programma', 'migrations'])
        ->and((new PDO('sqlite:'.$percorso))->query('select count(*) from fatture_altro_programma')->fetchColumn())->toEqual(1);
});

it('T6_755_wizard_cancella: anche con la sola tabella di un altro programma (senza migrations) il passo si ferma, e dice «1 tabella»', function () {
    $percorso = dnvDatabase(['clienti_di_un_altro']);
    config(['installer.requirements.environment.database' => true]);

    expect(fn () => dnvPassoDelWizard(dnvDatiDelPasso($percorso)))->toThrow(\DomainException::class, '1 tabella');
    expect(dnvTabelle($percorso))->toBe(['clienti_di_un_altro'])
        ->and(File::exists($this->env) ? File::get($this->env) : null)->toBe($this->envPrima);
});

it('T6_755_wizard_cancella: la cintura dentro PreparaDatabase::migra(fresh) ferma anche chi la chiama da un altro punto, e non lancia migrate:fresh', function () {
    $percorso = dnvDatabase(['migrations', 'altro']);
    config(['database.default' => 'mysql']);
    DB::purge('mysql');

    expect(fn () => app(PreparaDatabase::class)->migra(fresh: true))->toThrow(\DomainException::class, 'non è vuoto');
    expect(dnvTabelle($percorso))->toBe(['altro', 'migrations']);
});

it('T6_755_wizard_cancella: un database vuoto passa il controllo, e il numero delle tabelle è zero', function () {
    $percorso = dnvDatabase([]);
    $controllo = app(ControlloDatabaseVuoto::class);

    expect($controllo->tabelle('mysql'))->toBe([])
        ->and(fn () => $controllo->controlla('mysql', $percorso))->not->toThrow(\Throwable::class);
});

it('T6_755_wizard_cancella: lo stesso database che il wizard ha appena preparato passa, se non c\'è il lock d\'installazione; con il lock o con un altro database no', function () {
    $percorso = dnvDatabase(['migrations', 'users']);
    $controllo = app(ControlloDatabaseVuoto::class);
    $progress = config('installer.options.progress_file');

    // Il wizard ha preparato questo database (host, porta, nome): il passo ripetuto passa.
    $controllo->ricordaDatabasePreparato($progress, '127.0.0.1', '3306', $percorso);
    expect($controllo->preparatoDalWizard($progress, '127.0.0.1', '3306', $percorso))->toBeTrue()
        ->and(fn () => $controllo->controlla('mysql', $percorso, preparatoDalWizard: true))->not->toThrow(\Throwable::class);

    // Un altro database, o un'altra porta: no.
    expect($controllo->preparatoDalWizard($progress, '127.0.0.1', '3306', $percorso.'-altro'))->toBeFalse()
        ->and($controllo->preparatoDalWizard($progress, '127.0.0.1', '3307', $percorso))->toBeFalse()
        ->and($controllo->preparatoDalWizard($progress, 'altro-host', '3306', $percorso))->toBeFalse();

    // Il lock d'installazione: l'installazione è finita, quel database è dei dati dell'amministratore.
    File::put(config('installer.options.lock_file'), now()->toDateTimeString());
    expect($controllo->preparatoDalWizard($progress, '127.0.0.1', '3306', $percorso))->toBeFalse();
    File::delete(config('installer.options.lock_file'));

    // Il file di progresso rifatto da zero: non ricorda niente.
    File::delete($progress);
    expect($controllo->preparatoDalWizard($progress, '127.0.0.1', '3306', $percorso))->toBeFalse()
        ->and(fn () => $controllo->controlla('mysql', $percorso))->toThrow(\DomainException::class);
});

it('T6_755_wizard_cancella: il file di progresso conserva il resto di ciò che c\'era quando il wizard ricorda il database', function () {
    $controllo = app(ControlloDatabaseVuoto::class);
    $progress = config('installer.options.progress_file');
    File::put($progress, json_encode(['current_step' => 'environment', 'data' => ['mail' => ['x' => 1]]]));

    $controllo->ricordaDatabasePreparato($progress, 'h', '1', 'd');

    $letto = json_decode(File::get($progress), true);
    expect($letto['current_step'])->toBe('environment')->and($letto['data'])->toBe(['mail' => ['x' => 1]])->and($letto)->toHaveKey('database_preparato');
});

it('T6_755_wizard_cancella: le frasi ci sono nelle due lingue, con il nome del database e il numero', function () {
    foreach (['it' => ['non è vuoto', 'Il programma non lo cancella'], 'en' => ['is not empty', 'will not erase it']] as $lingua => $pezzi) {
        $frase = trans_choice('installer.database.non_vuoto', 3, ['database' => 'km_prova', 'n' => 3], $lingua);
        expect($frase)->toContain('km_prova')->toContain('3')->toContain($pezzi[0])->toContain($pezzi[1]);
    }
    expect(trans_choice('installer.database.non_vuoto', 1, ['database' => 'km', 'n' => 1], 'it'))->toContain('1 tabella')->not->toContain('1 tabelle');

    // Il passo di benvenuto e il suggerimento del campo dicono «vuoto».
    expect(__('installer.welcome.database_text', [], 'it'))->toContain('vuoto')
        ->and(__('installer.welcome.database_text', [], 'en'))->toContain('empty')
        ->and(__('installer.fields.db_database.tooltip', [], 'it'))->toContain('vuoto')
        ->and(__('installer.fields.db_database.tooltip', [], 'en'))->toContain('empty');
});

it('T6_755_wizard_cancella: «Testa connessione» su un database con delle tabelle avvisa e non dice «riuscita»; su uno vuoto sì', function () {
    $percorso = dnvDatabase(['migrations', 'altro']);

    $componente = Livewire::test(FixedEnvironmentSettings::class)
        ->set('isDatabaseRequired', true)
        ->set('appName', 'Prova')->set('appUrl', 'http://localhost')->set('appLocale', 'it')
        ->set('dbHost', '127.0.0.1')->set('dbPort', '3306')->set('dbDatabase', $percorso)->set('dbUsername', 'utente')->set('dbPassword', 'segreta')
        ->call('testDatabaseConnection');

    expect($componente->get('dbTestStatus'))->not->toBe('success')
        ->and($componente->get('dbTestMessage'))->toContain('non è vuoto')->toContain('2 tabelle')->not->toContain('Connessione riuscita');

    $vuoto = dnvDatabase([], 'vuoto.sqlite');
    $componente = Livewire::test(FixedEnvironmentSettings::class)
        ->set('isDatabaseRequired', true)
        ->set('appName', 'Prova')->set('appUrl', 'http://localhost')->set('appLocale', 'it')
        ->set('dbHost', '127.0.0.1')->set('dbPort', '3306')->set('dbDatabase', $vuoto)->set('dbUsername', 'utente')->set('dbPassword', 'segreta')
        ->call('testDatabaseConnection');

    expect($componente->get('dbTestStatus'))->toBe('success')->and($componente->get('dbTestMessage'))->toContain('Connessione riuscita');
});
