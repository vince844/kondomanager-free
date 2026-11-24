<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La pagina d'errore (Coda 755 (1), decisioni 690 (2), 696 (8) e 698; bozza docs/design/beta14_pagine_errore_grafica_bozza.html).
 *
 * Fino alla 1.12.0-beta.13 la pagina «500 - Errore interno» stampava a chiunque il messaggio dell'eccezione, anche con il debug spento: con il database
 * fermo l'indirizzo del server, la porta e il nome del database. Ora a tutti «Errore interno», una frase e un codice di sei caratteri, lo stesso della riga
 * del registro degli errori e della riga della tabella `errori_volte`; il dettaglio (ripulito) solo a chi ha «Gestisci impostazioni generali», mai nella
 * demo pubblica. Chi è collegato senza il dettaglio ha «Avvisa lo studio» (il collaboratore «Avvisa l'amministratore»); il visitatore no. Con il database
 * fermo la pagina non prova a leggere chi è collegato e lo dice. Le pagine d'errore hanno lo stile dentro la pagina: niente Tailwind dalla rete.
 *
 * Che cosa non copre: il database davvero fermo (una porta chiusa): qui l'eccezione di connessione è lanciata da una rotta di prova, e SQLite risponde.
 */

use App\Enums\Permission;
use App\Models\Errori\VoltaDellErrore;
use App\Models\User;
use App\Support\CodiceDellErrore;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    config(['app.debug' => false]);
    $this->registro = sys_get_temp_dir().'/pagina_errore_'.uniqid().'.log';
    config(['logging.default' => 'single', 'logging.channels.single.path' => $this->registro]);
    app('log')->forgetChannel('single');
    app('log')->forgetChannel('stack');

    // Un errore di una query con i dettagli della connessione e un valore: il messaggio che oggi esce a tutti.
    Route::middleware('web')->get('/prova-errore-query', function () {
        throw new QueryException('mysql', 'select * from `users` where `email` = ? limit 1', ['elena.rossi@example.it'],
            new \PDOException('SQLSTATE[42S22]: Column not found: 1054 Unknown column \'importo_residuo\' in \'field list\''),
            ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'kondomanager']);
    });
    // Il database che non risponde: la connessione rifiutata.
    Route::middleware('web')->get('/prova-database-fermo', function () {
        throw new QueryException('mysql', 'select * from `sessions` where `id` = ?', ['abc'],
            new \PDOException('SQLSTATE[HY000] [2002] Connection refused'),
            ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'kondomanager']);
    });
    Route::middleware('web')->get('/prova-abort', fn () => abort(500, 'dettaglio interno del controller'));
    Route::middleware('web')->post('/prova-salvataggio', fn () => throw new \RuntimeException('Salvataggio non riuscito per elena.rossi@example.it'));
    Route::middleware('web')->get('/prova-403-framework', fn () => throw new \Illuminate\Auth\Access\AuthorizationException());
    Route::middleware('web')->get('/prova-403-programma', fn () => abort(403, __('errors.403.account_suspended')));
    Route::middleware('web')->get('/prova-419', fn () => abort(419));
    Route::middleware('web')->get('/prova-503', fn () => abort(503, 'manutenzione', ['Retry-After' => '60']));
});

function pdeUtente(string $ruolo): User
{
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $utente->assignRole(Role::findByName($ruolo, 'web'));

    return $utente->fresh();
}

/** Il codice che la pagina mostra, letto dall'attributo `data-codice-errore`. */
function pdeCodice(string $html): string
{
    expect(preg_match('/data-codice-errore="(['.CodiceDellErrore::ALFABETO.']{6})"/', $html, $m))->toBe(1);

    return $m[1];
}

it('a un visitatore, a un condòmino e a un collaboratore: «Errore interno» e il codice, nessun messaggio tecnico né l\'indirizzo del database', function () {
    foreach (['visitatore' => null, 'condòmino' => pdeUtente('utente'), 'collaboratore' => pdeUtente('collaboratore')] as $chi => $utente) {
        $this->flushHeaders();
        $risposta = $utente ? $this->actingAs($utente)->get('/prova-errore-query') : $this->get('/prova-errore-query');
        $html = $risposta->assertStatus(500)->getContent();

        expect($html, $chi)->toContain('Errore interno')
            ->and($html)->toContain('Si è verificato un errore imprevisto. Riprova fra qualche minuto.')
            ->and(pdeCodice($html))->toHaveLength(6)
            ->and($html)->not->toContain('SQLSTATE')
            ->and($html)->not->toContain('importo_residuo')
            ->and($html)->not->toContain('Host:')
            ->and($html)->not->toContain('127.0.0.1')
            ->and($html)->not->toContain('elena.rossi@example.it')
            ->and($html)->not->toContain('Dettaglio tecnico');
        auth()->logout();
    }
});

it('il visitatore ha la frase approvata e nessun pulsante dell\'avviso; il condòmino «Avvisa lo studio», il collaboratore «Avvisa l\'amministratore»', function () {
    $visitatore = $this->get('/prova-errore-query')->getContent();
    expect($visitatore)->toContain('Se si ripete, comunica allo studio questo codice:')
        ->and($visitatore)->not->toContain('name="firma"');

    $condomino = $this->actingAs(pdeUtente('utente'))->get('/prova-errore-query')->getContent();
    expect($condomino)->toContain('name="firma"')->and($condomino)->toContain('Avvisa lo studio')
        ->and($condomino)->toContain('Che cosa stavi facendo?');
    auth()->logout();

    $collaboratore = $this->actingAs(pdeUtente('collaboratore'))->get('/prova-errore-query')->getContent();
    expect($collaboratore)->toContain('name="firma"')->and($collaboratore)->toContain('Avvisa l&#039;amministratore');
});

it('chi ha «Gestisci impostazioni generali» vede il dettaglio ripulito e «Copia il rapporto», senza l\'indirizzo del database né i valori', function () {
    $html = $this->actingAs(pdeUtente('amministratore'))->get('/prova-errore-query')->assertStatus(500)->getContent();

    expect($html)->toContain('Dettaglio tecnico (lo vedi solo tu)')
        ->and($html)->toContain('Unknown column &#039;importo_residuo&#039;')
        ->and($html)->toContain('where `email` = ? limit 1')
        ->and($html)->toContain('Copia il rapporto')
        ->and($html)->not->toContain('127.0.0.1')
        ->and($html)->not->toContain('Host:')
        ->and($html)->not->toContain('Database: kondomanager')
        ->and($html)->not->toContain('elena.rossi@example.it')
        ->and($html)->not->toContain('name="firma"');
});

it('un collaboratore a cui l\'amministratore dà «Gestisci impostazioni generali» vede il dettaglio', function () {
    $collaboratore = pdeUtente('collaboratore');
    $collaboratore->givePermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value);

    expect($this->actingAs($collaboratore->fresh())->get('/prova-errore-query')->getContent())->toContain('Dettaglio tecnico (lo vedi solo tu)');
});

it('il codice della pagina sta nella riga del registro degli errori e nella tabella delle volte', function () {
    $html = $this->get('/prova-errore-query')->getContent();
    $codice = pdeCodice($html);

    $registro = (string) file_get_contents($this->registro);
    $riga = collect(explode("\n", $registro))->first(fn ($r) => str_contains($r, 'Unknown column'));
    expect($riga)->not->toBeNull()->and($riga)->toContain('"codice_errore":"'.$codice.'"');

    $volta = VoltaDellErrore::where('codice', $codice)->sole();
    expect($volta->messaggio)->toContain('Unknown column')->and($volta->messaggio)->not->toContain('127.0.0.1');
});

it('dentro la finestra di una pagina (Inertia) la risposta è la stessa pagina, senza il messaggio tecnico', function () {
    $versione = (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    $html = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $versione, 'Accept' => 'text/html, application/xhtml+xml'])
        ->get('/prova-errore-query')->assertStatus(500)->getContent();

    expect($html)->toContain('Errore interno')->and($html)->not->toContain('SQLSTATE')->and(pdeCodice($html))->toHaveLength(6)
        // I collegamenti escono dalla finestra: aprono la pagina intera.
        ->and($html)->toContain('target="_top"');
});

it('l\'errore 500 scritto a mano dal programma: la risposta JSON dice «Server Error» con il codice, senza il testo; finisce nel registro e nella tabella', function () {
    $json = $this->getJson('/prova-abort')->assertStatus(500)->json();

    expect($json['message'])->toBe('Server Error')
        ->and($json['codice'])->toMatch('/^['.CodiceDellErrore::ALFABETO.']{6}$/')
        ->and(json_encode($json))->not->toContain('dettaglio interno');
    expect((string) file_get_contents($this->registro))->toContain($json['codice']);
    expect(VoltaDellErrore::where('codice', $json['codice'])->exists())->toBeTrue();

    $html = $this->get('/prova-abort')->assertStatus(500)->getContent();
    expect($html)->toContain('Errore interno')->and($html)->not->toContain('dettaglio interno');
});

it('anche la risposta JSON di un errore non scritto a mano porta il codice', function () {
    $json = $this->getJson('/prova-errore-query')->assertStatus(500)->json();

    expect($json['message'])->toBe('Server Error')->and(VoltaDellErrore::where('codice', $json['codice'])->exists())->toBeTrue();
});

it('dopo un salvataggio niente «Riprova»: la frase dice di controllare se l\'operazione è stata salvata', function () {
    $html = $this->post('/prova-salvataggio')->assertStatus(500)->getContent();

    expect($html)->toContain('Controlla se l&#039;operazione è stata salvata prima di rifarla.')
        ->and($html)->not->toContain('href="" data-riprova');
    expect($this->get('/prova-errore-query')->getContent())->toContain('href="" data-riprova');
});

it('con il database fermo la pagina non legge chi è collegato, non scrive nella tabella, e lo dice', function () {
    $html = $this->actingAs(pdeUtente('amministratore'))->get('/prova-database-fermo')->assertStatus(500)->getContent();

    expect($html)->toContain('Il programma non raggiunge i suoi dati.')
        ->and($html)->not->toContain('Dettaglio tecnico')
        ->and($html)->not->toContain('name="firma"')
        ->and($html)->toContain('Se si ripete, comunica allo studio questo codice:')
        ->and(pdeCodice($html))->toHaveLength(6);
    expect(VoltaDellErrore::count())->toBe(0);
    expect((string) file_get_contents($this->registro))->toContain(pdeCodice($html));
});

it('nella demo pubblica nessun dettaglio e nessun avviso, nemmeno all\'amministratore; l\'errore si salva comunque', function () {
    // La demo come la vede l'immagine (`artisan optimize`): dalla configurazione, `kondomanager.demo`.
    config(['kondomanager.demo' => true]);

    foreach ([pdeUtente('amministratore'), pdeUtente('utente')] as $utente) {
        $html = $this->actingAs($utente)->get('/prova-errore-query')->assertStatus(500)->getContent();
        expect($html)->toContain('Questa è la demo pubblica.')
            ->and($html)->not->toContain('Dettaglio tecnico')
            ->and($html)->not->toContain('name="firma"')
            ->and($html)->not->toContain('importo_residuo');
        expect(VoltaDellErrore::where('codice', pdeCodice($html))->exists())->toBeTrue();
    }
});

it('con il debug acceso resta la pagina di sempre, con il messaggio', function () {
    config(['app.debug' => true]);

    expect($this->get('/prova-errore-query')->assertStatus(500)->getContent())->toContain('importo_residuo');
});

it('le pagine d\'errore hanno lo stile dentro la pagina: niente Tailwind dalla rete', function () {
    foreach (['500', '503', '404', '403', '413', '419', '429', 'minimal', '4xx', '5xx'] as $vista) {
        $sorgente = (string) file_get_contents(resource_path("views/errors/{$vista}.blade.php"));
        expect($sorgente, $vista)->not->toContain('cdn.jsdelivr')->and($sorgente)->not->toContain('@vite');
    }
    $html = $this->get('/prova-errore-query')->getContent();
    expect($html)->toContain('<style')->and($html)->not->toContain('<script src=');
});

it('la 403 non stampa il messaggio di Laravel in inglese; stampa quello scritto dal programma per le persone', function () {
    $html = $this->get('/prova-403-framework')->assertStatus(403)->getContent();
    expect($html)->toContain('Non puoi aprire questa pagina')
        ->and($html)->toContain('Il tuo account non ha il permesso per questa pagina. Se ti serve, chiedilo allo studio.')
        ->and($html)->not->toContain('This action is unauthorized.');

    expect($this->get('/prova-403-programma')->assertStatus(403)->getContent())->toContain('Il tuo account è stato temporaneamente sospeso.');
});

it('la 419, la 503 e la 404 parlano italiano, nella veste nuova', function () {
    expect($this->get('/prova-419')->assertStatus(419)->getContent())->toContain('La pagina è scaduta')->toContain('Ricarica la pagina');
    $manutenzione = $this->get('/prova-503')->assertStatus(503)->getContent();
    expect($manutenzione)->toContain('Manutenzione in corso')->toContain('Riprova adesso')->not->toContain('manutenzione</');
    expect($this->get('/una-pagina-che-non-esiste-davvero')->assertStatus(404)->getContent())->toContain('Pagina non trovata')->toContain('Torna indietro');
});
