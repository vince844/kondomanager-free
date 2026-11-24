<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Il portale mostra a un condòmino solo i palazzi suoi (Coda 668)
 *
 * Una comunicazione, un documento o un evento dello studio può andare a più palazzi. Il portale mandava al browser del condòmino il nome di **tutti** i
 * palazzi dell'avviso (`condomini.options` e `condomini.full`), anche quelli in cui lui non abita: il nome di un altro cliente dello studio. Ora le tre
 * risorse del portale mandano solo i palazzi del condòmino, letti una volta per richiesta.
 *
 * Il caso: lo studio scrive a «Via Roma 12» e a «Corso Italia 3»; Ugo abita solo in via Roma. L'elenco, la scheda, la dashboard, l'agenda e l'archivio
 * dei documenti di Ugo non contengono «Corso Italia 3» in nessuna prop; contengono «Via Roma 12». Paolo, che abita in tutti e due, li vede tutti e due.
 * A chi ha scritto l'avviso restano tutti i suoi palazzi: la sua pagina di modifica li rimanda come sono, anche quello da cui è uscito.
 *
 * Che cosa non copre: le pagine del pannello (lì lo studio vede tutti i palazzi dell'avviso, ed è giusto); le segnalazioni, che hanno un palazzo solo.
 */

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->roma = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->italia = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    $this->admin = User::factory()->create(['email_verified_at' => now(), 'name' => 'Studio Bianchi']);
    $this->admin->assignRole(Role::AMMINISTRATORE->value);
    $this->ugo = sppResidente('Ugo Ferri', [$this->roma->id]);
    $this->paolo = sppResidente('Paolo Neri', [$this->roma->id, $this->italia->id]);
    $this->categoria = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Assemblee']);
});

function sppResidente(string $nome, array $condominiIds): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => $nome]);
    $user->assignRole(Role::UTENTE->value);
    Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => $nome])->condomini()->attach($condominiIds);

    return $user->fresh();
}

function sppComunicazione(User $autore, array $condomini, string $oggetto = 'Lavori alla rete del gas'): Comunicazione
{
    $c = Comunicazione::create([
        'subject' => $oggetto, 'description' => 'Dal 1° dicembre.', 'priority' => 'media', 'created_by' => $autore->id,
        'is_featured' => false, 'is_private' => false, 'is_published' => true, 'is_approved' => true, 'can_comment' => false,
    ]);
    $c->condomini()->attach(array_map(fn (Condominio $k) => $k->id, $condomini));

    return $c;
}

function sppDocumento(User $autore, CategoriaDocumento $categoria, array $condomini): Documento
{
    $d = Documento::create([
        'name' => 'Regolamento del gas', 'description' => 'Per tutti i palazzi', 'created_by' => $autore->id, 'is_published' => true, 'is_approved' => true,
        'path' => 'documenti/regolamento.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1024,
    ]);
    $d->categorie()->attach($categoria->id);
    $d->condomini()->attach(array_map(fn (Condominio $k) => $k->id, $condomini));

    return $d;
}

function sppEvento(User $autore, array $condomini): Evento
{
    $e = Evento::create([
        'title' => 'Sopralluogo del tecnico del gas', 'description' => 'In tutti e due i palazzi',
        'start_time' => now()->addDays(3), 'end_time' => now()->addDays(3)->addHour(),
        'created_by' => $autore->id, 'is_approved' => true, 'visibility' => 'public', 'timezone' => 'Europe/Rome',
    ]);
    $e->condomini()->attach(array_map(fn (Condominio $k) => $k->id, $condomini));

    return $e;
}

/** Le props della pagina del portale, senza quelle condivise, come testo. */
function sppProps($test, User $user, string $url): string
{
    $props = $test->actingAs($user)->get($url)->assertOk()->viewData('page')['props'];
    unset($props['auth'], $props['flash'], $props['errors'], $props['ziggy'], $props['app']);

    return json_encode($props, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

it('la comunicazione per due palazzi: l\'elenco e la dashboard di Ugo hanno solo il suo, la scheda nessuno', function () {
    $c = sppComunicazione($this->admin, [$this->roma, $this->italia]);

    foreach ([
        'elenco' => route('user.comunicazioni.index'),
        'dashboard' => route('user.dashboard'),
    ] as $dove => $url) {
        $json = sppProps($this, $this->ugo, $url);
        expect($json)->toContain('Lavori alla rete del gas')
            ->and($json)->toContain('Via Roma 12')
            ->and(str_contains($json, 'Corso Italia 3'))->toBeFalse("{$dove}: c'è «Corso Italia 3»");
    }
    // La scheda non carica i palazzi (il condòmino è già dentro il suo): nessun nome, e mai quello dell'altro.
    expect(str_contains(sppProps($this, $this->ugo, route('user.comunicazioni.show', $c)), 'Corso Italia 3'))->toBeFalse('scheda: c\'è «Corso Italia 3»');
});

it('il documento per due palazzi: l\'archivio e la dashboard di Ugo hanno solo il suo', function () {
    sppDocumento($this->admin, $this->categoria, [$this->roma, $this->italia]);

    foreach ([
        'archivio' => route('user.categorie-documenti.show', $this->categoria),
        'dashboard' => route('user.dashboard'),
    ] as $dove => $url) {
        $json = sppProps($this, $this->ugo, $url);
        expect($json)->toContain('Regolamento del gas')
            ->and($json)->toContain('Via Roma 12')
            ->and(str_contains($json, 'Corso Italia 3'))->toBeFalse("{$dove}: c'è «Corso Italia 3»");
    }
});

it('l\'evento per due palazzi: l\'agenda di Ugo ha solo il suo', function () {
    sppEvento($this->admin, [$this->roma, $this->italia]);

    $json = sppProps($this, $this->ugo, route('user.eventi.index'));

    expect($json)->toContain('Sopralluogo del tecnico del gas')
        ->and($json)->toContain('Via Roma 12')
        ->and(str_contains($json, 'Corso Italia 3'))->toBeFalse('agenda: c\'è «Corso Italia 3»');
});

it('chi abita in tutti e due i palazzi li vede tutti e due', function () {
    sppComunicazione($this->admin, [$this->roma, $this->italia]);
    sppDocumento($this->admin, $this->categoria, [$this->roma, $this->italia]);
    sppEvento($this->admin, [$this->roma, $this->italia]);

    foreach ([route('user.comunicazioni.index'), route('user.categorie-documenti.show', $this->categoria), route('user.eventi.index')] as $url) {
        expect(sppProps($this, $this->paolo, $url))->toContain('Via Roma 12')->toContain('Corso Italia 3');
    }
});

it('i palazzi del condòmino si leggono una volta per richiesta, non riga per riga', function () {
    $conta = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->ugo)->get(route('user.comunicazioni.index'))->assertOk();
        $quante = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $quante;
    };
    sppComunicazione($this->admin, [$this->roma, $this->italia], 'Prima');
    sppComunicazione($this->admin, [$this->roma, $this->italia], 'Seconda');
    $conta(); // la prima richiesta legge anche ruoli e impostazioni, che poi restano in memoria
    $conDue = $conta();
    foreach (['Terza', 'Quarta', 'Quinta', 'Sesta'] as $oggetto) {
        sppComunicazione($this->admin, [$this->roma, $this->italia], $oggetto);
    }

    expect($conta())->toBe($conDue);
});

it('a chi l\'ha scritta restano tutti i palazzi della sua comunicazione: la modifica non la toglie dal palazzo da cui è uscito', function () {
    $this->paolo->givePermissionTo(\App\Enums\Permission::EDIT_OWN_COMUNICAZIONI->value);
    $c = sppComunicazione($this->paolo, [$this->italia], 'Il cancello di Corso Italia');
    $this->paolo->anagrafica->condomini()->detach($this->italia->id);

    $props = $this->actingAs($this->paolo->fresh())->get(route('user.comunicazioni.edit', $c))->assertOk()->viewData('page')['props'];
    $ids = collect(json_decode(json_encode($props['comunicazione']), true)['condomini']['options'])->pluck('value')->all();
    expect($ids)->toBe([$this->italia->id]);

    $this->actingAs($this->paolo->fresh())->put(route('user.comunicazioni.update', $c), [
        'subject' => 'Il cancello di Corso Italia, riparato', 'description' => 'Dal 1° dicembre.', 'priority' => 'media',
        'is_featured' => false, 'is_private' => false, 'condomini_ids' => $ids,
    ])->assertSessionHasNoErrors();

    expect($c->fresh()->condomini()->pluck('condomini.id')->all())->toBe([$this->italia->id])
        ->and($c->fresh()->subject)->toBe('Il cancello di Corso Italia, riparato');
});
