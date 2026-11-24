<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La scheda «Errori» della pagina «Registri» (Impostazioni), accanto a «Email» (decisione 698 (2) e (3); bozza docs/design/beta14_pagine_errore_grafica_bozza.html,
 * scene 7–10). La vede chi ha «Gestisci impostazioni generali», come le email. Un errore per riga, con lo stato («Nuovo», «Avvisato», «Visto»); la ricerca
 * per codice apre l'errore; un codice che non c'è lo dice. «Scarica il rapporto» dà un file di testo senza password, dati dei moduli e valori delle query.
 */

use App\Enums\Permission;
use App\Models\Errori\Errore;
use App\Models\Errori\VoltaDellErrore;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    config(['app.debug' => false]);
    $this->admin = User::factory()->create(['email_verified_at' => now()]);
    $this->admin->assignRole(Role::findByName('amministratore', 'web'));

    report(new QueryException('mysql', 'select * from `rate` where `id` = ?', [14], new \PDOException('SQLSTATE[42S22]: Column not found: 1054 Unknown column \'importo_residuo\''),
        ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'kondomanager']));
    report(new \RuntimeException('Division by zero'));
    $this->query = Errore::where('classe', QueryException::class)->sole();
    $this->volta = $this->query->volteRegistrate()->sole();
});

function seRegistri($test, array $parametri = [], ?User $chi = null)
{
    return $test->actingAs($chi ?? $test->admin)->get(route('logs.index', $parametri));
}

it('la pagina si chiama «Registri» e porta il numero degli errori nuovi; la scheda «Errori» elenca un errore per riga', function () {
    $props = seRegistri($this, ['scheda' => 'errori'])->assertOk()->viewData('page')['props'];

    expect($props['scheda'])->toBe('errori')
        ->and($props['erroriNuovi'])->toBe(2)
        ->and($props['errori']['total'])->toBe(2);
    $riga = collect($props['errori']['data'])->firstWhere('id', $this->query->id);
    expect($riga['stato'])->toBe('nuovo')
        ->and($riga['codice'])->toBe($this->volta->codice)
        ->and($riga['volte'])->toBe(1)
        ->and($riga['messaggio'])->toContain('Unknown column')
        ->and(json_encode($props['errori']))->not->toContain('127.0.0.1');
});

it('la scheda «Email» non carica gli errori; la scheda si chiede da sola, senza ricaricare la pagina', function () {
    $props = seRegistri($this)->assertOk()->viewData('page')['props'];
    expect($props['scheda'])->toBe('email')->and($props)->not->toHaveKey('errori');

    $versione = (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
    $parziale = $this->actingAs($this->admin)->withHeaders([
        'X-Inertia' => 'true', 'X-Inertia-Version' => $versione, 'X-Inertia-Partial-Component' => 'impostazioni/impostazioniLogs',
        'X-Inertia-Partial-Data' => 'scheda,errori,erroriFiltri',
    ])->get(route('logs.index', ['scheda' => 'errori']))->assertOk()->json('props');
    expect(array_keys($parziale))->toContain('errori')->and($parziale)->not->toHaveKey('mailLogs');
});

it('il codice intero nella ricerca apre l\'errore, con quella volta per prima; aprirlo lo segna «Visto»', function () {
    $dettaglio = seRegistri($this, ['scheda' => 'errori', 'cerca' => strtolower($this->volta->codice)])->assertOk()->viewData('page')['props']['dettaglio'];

    expect($dettaglio['id'])->toBe($this->query->id)
        ->and($dettaglio['volta']['codice'])->toBe($this->volta->codice)
        ->and($dettaglio['volte'][0]['codice'])->toBe($this->volta->codice)
        ->and($dettaglio['rapporto'])->toContain('rapporto dell\'errore '.$this->volta->codice)
        ->and($dettaglio['rapporto_url'])->toBe(route('logs.errori.rapporto', $this->volta));
    expect($this->query->fresh()->stato)->toBe(Errore::VISTO);
});

it('un errore avvisato resta «Avvisato» anche dopo averlo aperto', function () {
    $this->query->forceFill(['stato' => Errore::AVVISATO])->save();
    seRegistri($this, ['scheda' => 'errori', 'errore' => $this->query->id])->assertOk();

    expect($this->query->fresh()->stato)->toBe(Errore::AVVISATO);
});

it('un codice che non c\'è lo dice, invece di un elenco vuoto senza perché', function () {
    $props = seRegistri($this, ['scheda' => 'errori', 'cerca' => 'T5VB2R'])->assertOk()->viewData('page')['props'];

    expect($props['codiceNonTrovato'])->toBe('T5VB2R')->and($props['dettaglio'])->toBeNull()->and($props['errori']['total'])->toBe(0);
});

it('lo stato filtra l\'elenco', function () {
    $this->query->forceFill(['stato' => Errore::AVVISATO])->save();

    $righe = seRegistri($this, ['scheda' => 'errori', 'stato_errori' => 'avvisato'])->assertOk()->viewData('page')['props']['errori']['data'];
    expect(collect($righe)->pluck('id')->all())->toBe([$this->query->id]);
});

it('«Scarica il rapporto» dà il file di testo con il codice nel nome, senza l\'indirizzo del database né i valori', function () {
    $risposta = $this->actingAs($this->admin)->get(route('logs.errori.rapporto', $this->volta))->assertOk();

    expect($risposta->headers->get('Content-Type'))->toContain('text/plain')
        ->and($risposta->headers->get('Content-Disposition'))->toContain('rapporto-errore-'.$this->volta->codice.'.txt');
    $testo = $risposta->streamedContent() ?: $risposta->getContent();
    expect($testo)->toContain($this->volta->codice)->not->toContain('127.0.0.1')->not->toContain('= 14');
});

it('chi non ha «Gestisci impostazioni generali» non apre la scheda né scarica il rapporto', function () {
    $collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $collaboratore->assignRole(Role::findByName('collaboratore', 'web'));
    expect($collaboratore->checkPermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value))->toBeFalse();

    seRegistri($this, ['scheda' => 'errori'], $collaboratore)->assertForbidden();
    $this->actingAs($collaboratore)->get(route('logs.errori.rapporto', $this->volta))->assertForbidden();
});
