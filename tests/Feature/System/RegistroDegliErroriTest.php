<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il registro degli errori nel database (decisione 698 (1); proposte 11–14 della bozza docs/design/beta14_pagine_errore_grafica_bozza.html).
 *
 * Ogni errore che il registro scrive come guasto si salva anche nella tabella: un errore per impronta (`errori`, con quante volte, la prima e l'ultima) e
 * una riga per volta (`errori_volte`, ognuna con il suo codice). Prima di salvare si ripulisce: i valori delle query, l'indirizzo, la porta e il nome del
 * database, le email, i codici fiscali, gli IBAN, i codici d'accesso nell'indirizzo; la traccia senza i valori. Di chi incontra l'errore solo il ruolo. Si
 * tiene 90 giorni (la pulizia di ogni notte), al massimo 200 volte al giorno per lo stesso errore. L'errore del database che non risponde non si prova a
 * scrivere nel database.
 *
 * Che cosa non copre: MySQL (la suite gira su SQLite).
 */

use App\Models\Errori\Errore;
use App\Models\Errori\VoltaDellErrore;
use App\Models\User;
use App\Support\CodiceDellErrore;
use App\Support\Errori\RapportoDellErrore;
use App\Support\Errori\RegistroDegliErrori;
use App\Support\Errori\Ripulitura;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    config(['app.debug' => false]);
    $this->registro = sys_get_temp_dir().'/registro_errori_'.uniqid().'.log';
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->registro]);
    app('log')->forgetChannel('single');
    app('log')->forgetChannel('stack');
});

function rdeQuery(): QueryException
{
    return new QueryException('mysql', 'select * from `users` where `email` = ? and `id` = ?', ['mario.bianchi@example.it', 14],
        new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry \'mario.bianchi@example.it\' for key \'users_email_unique\''),
        ['driver' => 'mysql', 'host' => 'db.interno.example', 'port' => '3306', 'database' => 'studio_rossi']);
}

it('il messaggio di una query perde i valori, l\'indirizzo, la porta e il nome del database', function () {
    $messaggio = Ripulitura::messaggio(rdeQuery());

    expect($messaggio)->toContain('SQL: select * from `users` where `email` = ? and `id` = ?')
        ->and($messaggio)->toContain('Duplicate entry \'[valore]\'')
        ->and($messaggio)->not->toContain('mario.bianchi')
        ->and($messaggio)->not->toContain('14')
        ->and($messaggio)->not->toContain('db.interno.example')
        ->and($messaggio)->not->toContain('3306')
        ->and($messaggio)->not->toContain('studio_rossi');
});

it('nei messaggi le email, i codici fiscali, gli IBAN, gli utenti del database, le password e i codici nell\'indirizzo diventano segnaposto', function () {
    $testo = 'Invio a elena.rossi@example.it fallito; CF RSSLNE80A41H501Z, IBAN IT60X0542811101000000123456, '
        .'SQLSTATE[HY000] [1045] Access denied for user \'km_studio\'@\'10.0.0.5\' (using password: YES), '
        .'getaddrinfo for db.interno.example failed, password=segreta123, '
        .'GET https://api.example.it/v1/rate?token=abc123def456&utente=7 e telefono 3331234567, '
        .'(Connection: mysql, Host: 127.0.0.1, Port: 3306, Database: kondomanager, SQL: select * from x where nome = \'Elena\' and id = 42)';

    $pulito = Ripulitura::maschera($testo);

    foreach (['elena.rossi@example.it', 'RSSLNE80A41H501Z', 'IT60X0542811101000000123456', 'km_studio', '10.0.0.5', 'db.interno.example', 'segreta123',
        'abc123def456', '3331234567', '127.0.0.1', 'kondomanager', '\'Elena\'', '= 42'] as $dato) {
        expect($pulito, $dato)->not->toContain($dato);
    }
    expect($pulito)->toContain('[email]')->toContain('[codice fiscale]')->toContain('[IBAN]')->toContain('[numero]')
        ->toContain('https://api.example.it/v1/rate?…')->toContain('SQL: select * from x where nome = ? and id = ?');
});

it('la traccia tiene il file, la riga e la funzione, mai i valori passati; i percorsi partono dalla cartella del programma', function () {
    $funzione = function (string $segreto) {
        throw new \RuntimeException('qualcosa non va');
    };
    try {
        $funzione('segreto-passato-alla-funzione');
    } catch (\RuntimeException $e) {
        $traccia = Ripulitura::traccia($e);
    }

    expect(json_encode($traccia))->not->toContain('segreto-passato-alla-funzione')
        ->and(json_encode($traccia))->not->toContain(base_path())
        ->and($traccia[0])->toHaveKeys(['file', 'riga', 'funzione']);
});

it('la pagina si scrive con i segnaposto della rotta: il codice d\'accesso di «password dimenticata» non entra mai nel registro', function () {
    Route::middleware('web')->get('/prova-token/{token}', fn (string $token) => throw new \RuntimeException('rotto'));

    $this->get('/prova-token/a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6?email=elena.rossi@example.it')->assertStatus(500);

    $volta = VoltaDellErrore::latest('id')->firstOrFail();
    expect($volta->pagina)->toBe('/prova-token/{token}')
        ->and($volta->metodo)->toBe('GET')
        ->and(json_encode($volta->getAttributes()))->not->toContain('a1b2c3d4e5f6')
        ->and(json_encode($volta->getAttributes()))->not->toContain('elena.rossi');
});

it('di chi incontra l\'errore si salva solo il ruolo, mai il nome', function () {
    $condomino = User::factory()->create(['name' => 'Elena Rossi', 'email_verified_at' => now()]);
    $condomino->assignRole(Role::findByName('utente', 'web'));
    Route::middleware('web')->get('/prova-ruolo', fn () => throw new \RuntimeException('rotto'));

    $this->actingAs($condomino)->get('/prova-ruolo')->assertStatus(500);

    $volta = VoltaDellErrore::latest('id')->firstOrFail();
    expect($volta->ruolo)->toBe('utente')->and(json_encode($volta->getAttributes()))->not->toContain('Elena Rossi');
});

it('lo stesso errore due volte è un errore con due volte, ognuna con il suo codice', function () {
    $prima = rdeQuery();
    $seconda = rdeQuery();
    report($prima);
    report($seconda);

    expect(Errore::count())->toBe(1)
        ->and(Errore::first()->volte)->toBe(2)
        ->and(VoltaDellErrore::count())->toBe(2)
        ->and(CodiceDellErrore::di($prima))->not->toBe(CodiceDellErrore::di($seconda))
        ->and(VoltaDellErrore::pluck('codice')->sort()->values()->all())->toEqual(collect([CodiceDellErrore::di($prima), CodiceDellErrore::di($seconda)])->sort()->values()->all());
    expect(Errore::first()->impronta)->toHaveLength(40);
});

it('il codice è lo stesso per l\'eccezione e per quella che la avvolge', function () {
    $originale = new \RuntimeException('dentro');
    $avvolta = new \Symfony\Component\HttpKernel\Exception\HttpException(500, 'fuori', $originale);

    expect(CodiceDellErrore::di($originale))->toBe(CodiceDellErrore::di($avvolta))
        ->and(CodiceDellErrore::di($originale))->toMatch('/^['.CodiceDellErrore::ALFABETO.']{6}$/')
        ->and(CodiceDellErrore::ALFABETO)->not->toContain('0')->not->toContain('O')->not->toContain('1')->not->toContain('I')->not->toContain('L');
});

it('al massimo 200 volte al giorno per lo stesso errore: dopo cresce solo il conteggio, e il codice resta nel file', function () {
    report(rdeQuery());
    $errore = Errore::sole();
    $adesso = now();
    DB::table('errori_volte')->insert(collect(range(1, 199))->map(fn ($i) => [
        'errore_id' => $errore->id, 'codice' => 'AAAA'.str_pad((string) $i, 2, '2', STR_PAD_LEFT), 'avvenuto_il' => $adesso, 'classe' => 'X', 'messaggio' => 'x',
        'created_at' => $adesso, 'updated_at' => $adesso,
    ])->all());
    expect(VoltaDellErrore::count())->toBe(200);

    $oltre = rdeQuery();
    report($oltre);

    expect(VoltaDellErrore::count())->toBe(200)
        ->and($errore->fresh()->volte)->toBe(2)
        ->and(RegistroDegliErrori::esito($oltre)['motivo'])->toBe('troppe')
        ->and((string) file_get_contents($this->registro))->toContain(CodiceDellErrore::di($oltre));
});

it('l\'errore del database che non risponde non si prova a scrivere nel database', function () {
    $fermo = new QueryException('mysql', 'select 1', [], new \PDOException('SQLSTATE[HY000] [2002] Connection refused'), ['driver' => 'mysql', 'host' => 'x']);
    $query = 0;
    DB::listen(function () use (&$query) {
        $query++;
    });

    report($fermo);

    expect($query)->toBe(0)
        ->and(VoltaDellErrore::count())->toBe(0)
        ->and(RegistroDegliErrori::esito($fermo))->toBe(['salvato' => false, 'motivo' => 'database', 'volta_id' => null])
        ->and((string) file_get_contents($this->registro))->toContain(CodiceDellErrore::di($fermo));
});

it('un errore di un lavoro in background si salva senza pagina, con il lavoro', function () {
    report(new \RuntimeException('La coda non ha mandato la comunicazione'));

    $volta = VoltaDellErrore::sole();
    expect($volta->pagina)->toBeNull()->and($volta->metodo)->toBeNull()->and($volta->azione)->toStartWith('Lavoro in background');
});

it('i 404, i 403, i 419 e i 429 non si salvano: non sono guasti', function () {
    Route::middleware('web')->get('/prova-403', fn () => abort(403));
    Route::middleware('web')->get('/prova-429', fn () => abort(429));
    $this->get('/non-esiste-questa-pagina')->assertStatus(404);
    $this->get('/prova-403')->assertStatus(403);
    $this->get('/prova-429')->assertStatus(429);

    expect(VoltaDellErrore::count())->toBe(0);
});

it('la pulizia di ogni notte toglie le volte di più di 90 giorni, e l\'errore che non ha più volte', function () {
    report(rdeQuery());
    report(new \RuntimeException('vecchio'));
    [$recente, $vecchio] = [Errore::where('classe', QueryException::class)->sole(), Errore::where('classe', \RuntimeException::class)->sole()];
    VoltaDellErrore::where('errore_id', $vecchio->id)->update(['avvenuto_il' => Carbon::now()->subDays(91)]);
    $vecchio->forceFill(['ultima_volta_il' => Carbon::now()->subDays(91)])->save();
    // Una volta vecchia anche dell'errore recente: va via lei, l'errore resta.
    VoltaDellErrore::create(['errore_id' => $recente->id, 'codice' => 'ZZZZZZ', 'avvenuto_il' => Carbon::now()->subDays(95), 'classe' => 'X', 'messaggio' => 'x']);

    $this->artisan('model:prune', ['--model' => [VoltaDellErrore::class]])->assertSuccessful();
    $this->artisan('model:prune', ['--model' => [Errore::class]])->assertSuccessful();

    expect(Errore::pluck('id')->all())->toBe([$recente->id])
        ->and(VoltaDellErrore::where('codice', 'ZZZZZZ')->exists())->toBeFalse()
        ->and(VoltaDellErrore::where('errore_id', $recente->id)->count())->toBe(1);
});

it('il rapporto ha il codice, l\'ora, le versioni, la pagina e la traccia; non ha il nome, la nota, i valori, l\'indirizzo del database', function () {
    $condomino = User::factory()->create(['name' => 'Elena Rossi', 'email_verified_at' => now()]);
    $condomino->assignRole(Role::findByName('utente', 'web'));
    Route::middleware('web')->get('/prova-rapporto/{incasso}', fn () => throw rdeQuery());
    $this->actingAs($condomino)->get('/prova-rapporto/345')->assertStatus(500);
    $volta = VoltaDellErrore::sole();
    $volta->forceFill(['avvisato_da' => $condomino->id, 'avvisato_il' => now(), 'avvisato_ruolo' => 'utente', 'nota_avviso' => 'Scaricavo la ricevuta di Elena Rossi'])->save();

    $testo = RapportoDellErrore::testo($volta->fresh());

    expect($testo)->toContain('rapporto dell\'errore '.$volta->codice)
        ->and($testo)->toContain(config('app.version'))
        ->and($testo)->toContain('PHP '.PHP_VERSION)
        ->and($testo)->toContain('GET /prova-rapporto/{incasso}')
        ->and($testo)->toContain('il ruolo «utente» (condòmino), senza il nome')
        ->and($testo)->toContain('La traccia (senza i valori)')
        ->and($testo)->toContain('Illuminate\Database\QueryException')
        ->and($testo)->toContain('Senza password, senza i dati dei moduli e i valori delle query')
        ->and($testo)->not->toContain('Elena Rossi')
        ->and($testo)->not->toContain('Scaricavo la ricevuta')
        ->and($testo)->not->toContain('mario.bianchi')
        ->and($testo)->not->toContain('db.interno.example')
        ->and($testo)->not->toContain('/prova-rapporto/345')
        ->and($testo)->not->toContain(base_path());
});

it('l\'SQL di una query perde anche i valori scritti dentro, non come parametri: non sono nella volta né nel rapporto', function () {
    Route::middleware('web')->get('/prova-sql-con-i-valori', function () {
        DB::select("select * from users where email = 'mario.rossi@example.it' and codice_fiscale = 'RSSMRA80A01H501Z' "
            ."and iban = 'IT60X0542811101000000123456' and password = 'Segreta!2026' and nome = 'Mario Rossi' and id = 4242 limit 5");
    });
    $this->get('/prova-sql-con-i-valori')->assertStatus(500);
    $volta = VoltaDellErrore::with('errore')->sole();
    $rapporto = RapportoDellErrore::testo($volta);

    foreach (['mario.rossi@example.it', 'RSSMRA80A01H501Z', 'IT60X0542811101000000123456', 'Segreta!2026', 'Mario Rossi', '4242'] as $dato) {
        expect($volta->messaggio, $dato)->not->toContain($dato)
            ->and($rapporto, $dato)->not->toContain($dato);
    }
    // L'SQL resta leggibile, con i «?» al posto dei valori; il numero di «limit» resta, non è un dato.
    expect($volta->messaggio)->toContain('SQL: select * from users where email = ? and codice_fiscale = ? and iban = ? and password = ? and nome = ? and id = ? limit 5');
});

it('di un errore di sintassi di MySQL non resta il pezzo di SQL con i valori («near …»)', function () {
    $e = new QueryException('mysql', "select * from users where password = 'Segreta!2026' and nome = 'Mario Rossi' limit", [],
        new \PDOException('SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax; check the manual that corresponds to '
            ."your MySQL server version for the right syntax to use near 'Segreta!2026' and nome = 'Mario Rossi' limit' at line 1"),
        ['driver' => 'mysql']);

    $messaggio = Ripulitura::messaggio($e);

    expect($messaggio)->not->toContain('Segreta!2026')->and($messaggio)->not->toContain('Mario Rossi')
        ->and($messaggio)->toContain('Syntax error or access violation: 1064')->and($messaggio)->toContain('at line 1');
});

it('la ripulitura toglie la password, i segreti e i codici d\'accesso anche in JSON e in un elenco PHP, e l\'IBAN scritto in minuscolo', function () {
    Route::middleware('web')->get('/prova-json', fn () => throw new \RuntimeException(
        'Quota non trovata; dati {"email":"mario.rossi@example.it","password":"Segreta!2026","client_secret":"Sc-Valore-77","access_token":"Tk-Valore-88"} '
        ."opzioni ['api_key' => 'Ak-Valore-99', 'remember_token' => \"Rt-Valore-11\"] codice fiscale rssmra80a01h501z iban it60x0542811101000000123456"));
    $this->get('/prova-json')->assertStatus(500);
    $volta = VoltaDellErrore::with('errore')->sole();
    $rapporto = RapportoDellErrore::testo($volta);

    foreach (['mario.rossi@example.it', 'Segreta!2026', 'Sc-Valore-77', 'Tk-Valore-88', 'Ak-Valore-99', 'Rt-Valore-11', 'rssmra80a01h501z',
        'it60x0542811101000000123456'] as $dato) {
        expect($volta->messaggio, $dato)->not->toContain($dato)
            ->and($rapporto, $dato)->not->toContain($dato);
    }
    expect($volta->messaggio)->toContain('"password":"[nascosto]"')->toContain('\'api_key\' => \'[nascosto]\'')->toContain('[IBAN]')->toContain('[codice fiscale]');
});

it('il rapporto dice ciò che è garantito: senza password, dati dei moduli e valori delle query; i nomi nei messaggi possono restare', function () {
    Route::middleware('web')->get('/prova-nome', fn () => throw new \RuntimeException('Quota di Mario Rossi, Via Verdi 3 int. 5, non trovata'));
    $this->get('/prova-nome')->assertStatus(500);

    $rapporto = RapportoDellErrore::testo(VoltaDellErrore::with('errore')->sole());

    expect($rapporto)->toContain('Senza password, senza i dati dei moduli e i valori delle query')
        ->and($rapporto)->toContain('i nomi scritti nei messaggi possono restare: rileggilo prima di mandarlo.')
        ->and($rapporto)->not->toContain('i nomi e gli indirizzi email delle persone');
});

it('una query che si rompe dentro una vista (o dentro un\'altra eccezione) non porta i valori nel messaggio né nella causa', function () {
    Route::middleware('web')->get('/prova-query-nella-vista', fn () => \Illuminate\Support\Facades\Blade::render(
        "{{ count(\\Illuminate\\Support\\Facades\\DB::select('select * from tabella_che_non_c_e where nome = ? and via = ? and id = ?', ['Mario Rossi', 'Via Verdi 3', 4242])) }}"));
    $this->get('/prova-query-nella-vista')->assertStatus(500);
    $volta = VoltaDellErrore::with('errore')->sole();
    $rapporto = RapportoDellErrore::testo($volta);

    expect($volta->classe)->not->toBe(QueryException::class);
    foreach (['Mario Rossi', 'Via Verdi 3', '4242'] as $dato) {
        expect((string) $volta->messaggio, $dato)->not->toContain($dato)
            ->and((string) $volta->causa, $dato)->not->toContain($dato)
            ->and($rapporto, $dato)->not->toContain($dato);
    }
    expect($volta->messaggio)->toContain('where nome = ? and via = ? and id = ?');
});
