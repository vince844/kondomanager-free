<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Assegnazione delle segnalazioni: le due scelte dell'amministratore (decisione 294, punti 3 e 4)
 *
 * Due impostazioni generali, spente di serie:
 * - **«Mostra ai condòmini chi segue la segnalazione»**: accesa, la scheda del portale riceve `seguita_da`, il nome di chi
 *   la segue. Spenta, il portale non riceve quel nome in nessuna forma.
 * - **«Assegnando una segnalazione aperta, passa a "in lavorazione"»**: accesa, una segnalazione **aperta** che viene
 *   assegnata a qualcuno passa a «in lavorazione». Non tocca le altre, e togliere l'assegnazione non riporta indietro.
 *
 * Si accendono e si spengono dalla pagina delle impostazioni generali, da chi la può aprire.
 */

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Lang;

require_once __DIR__ . '/AssegnazioneScenario.php';

beforeEach(function () {
    asgPrepara($this);
});

/** Il modulo delle impostazioni generali com'è salvato oggi, con i campi dati in più. */
function asgModuloImpostazioni(array $campi = []): array
{
    $impostazioni = app(GeneralSettings::class);

    return array_merge([
        'user_frontend_registration' => (bool) $impostazioni->user_frontend_registration,
        'language' => 'it',
        'app_name' => 'Kondomanager',
        'open_condominio_on_login' => false,
        'default_condominio_id' => null,
        'default_user_role' => $impostazioni->default_user_role,
        'default_per_page' => 10,
    ], $campi);
}

// --- Lo stato ------------------------------------------------------------------------------------------

test('di serie assegnare una segnalazione aperta non ne cambia lo stato', function () {
    asgAssegna($this, $this->admin, $this->s, $this->elena->id)->assertSessionHasNoErrors();

    expect(asgRiga($this->s)->stato)->toBe('aperta');
});

test('con l\'impostazione accesa, una segnalazione aperta che viene assegnata passa a «in lavorazione»', function () {
    asgImpostazione(ASG_IMP_STATO, true);

    asgAssegna($this, $this->admin, $this->s, $this->elena->id)->assertSessionHasNoErrors();

    expect(asgRiga($this->s)->stato)->toBe('in lavorazione')
        ->and((int) asgRiga($this->s)->assigned_to)->toBe($this->elena->id);
});

test('vale anche per chi se la assegna da solo', function () {
    asgImpostazione(ASG_IMP_STATO, true);

    asgAssegna($this, $this->elena, $this->s, $this->elena->id)->assertSessionHasNoErrors();

    expect(asgRiga($this->s)->stato)->toBe('in lavorazione');
});

test('non tocca una segnalazione già in lavorazione o chiusa', function (string $stato) {
    asgImpostazione(ASG_IMP_STATO, true);
    $s = ccmSegnalazione($this->aurora, $this->mario, ['stato' => $stato]);

    asgAssegna($this, $this->admin, $s, $this->elena->id)->assertSessionHasNoErrors();

    expect(asgRiga($s)->stato)->toBe($stato);
})->with(['in lavorazione', 'chiusa']);

test('togliere l\'assegnazione non riporta indietro lo stato, e riassegnare non lo cambia', function () {
    asgImpostazione(ASG_IMP_STATO, true);
    asgAssegna($this, $this->admin, $this->s, $this->elena->id);
    expect(asgRiga($this->s)->stato)->toBe('in lavorazione');

    asgAssegna($this, $this->admin, $this->s->fresh(), null);
    expect(asgRiga($this->s)->stato)->toBe('in lavorazione');

    asgAssegna($this, $this->admin, $this->s->fresh(), $this->collaboratrice->id);
    expect(asgRiga($this->s)->stato)->toBe('in lavorazione');
});

test('togliere l\'assegnazione a una segnalazione aperta, con l\'impostazione accesa, la lascia aperta', function () {
    asgImpostazione(ASG_IMP_STATO, true);
    $s = asgAssegnata($this, $this->collaboratrice);

    asgAssegna($this, $this->admin, $s, null)->assertSessionHasNoErrors();

    expect(asgRiga($s)->stato)->toBe('aperta');
});

// --- Il portale ----------------------------------------------------------------------------------------

test('di serie il portale non riceve il nome di chi segue la segnalazione, in nessuna forma', function () {
    $s = asgAssegnata($this, $this->elena);

    $risposta = $this->actingAs($this->mario)->get(route('user.segnalazioni.show', $s));
    $props = asgProps($risposta);
    $scheda = asgRisorsa($props['segnalazione']);

    expect($scheda)->not->toHaveKey('assigned_to')
        ->and($scheda)->not->toHaveKey('assegnata_a')
        ->and($scheda['seguita_da'] ?? null)->toBeNull()
        ->and(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain('Elena Studio')
        ->and($props)->not->toHaveKey('assegnazione');
});

test('con l\'impostazione accesa la scheda del portale riceve il nome, e solo quello', function () {
    asgImpostazione(ASG_IMP_PORTALE, true);
    $s = asgAssegnata($this, $this->elena);

    $props = asgProps($this->actingAs($this->mario)->get(route('user.segnalazioni.show', $s)));
    $scheda = asgRisorsa($props['segnalazione']);

    expect($scheda['seguita_da'])->toBe('Elena Studio')
        ->and($scheda)->not->toHaveKey('assigned_to')
        ->and($scheda)->not->toHaveKey('assegnata_a')
        ->and(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain($this->elena->email)
        ->and($props)->not->toHaveKey('assegnazione');
});

test('con l\'impostazione accesa, una segnalazione senza nessuno non dice niente', function () {
    asgImpostazione(ASG_IMP_PORTALE, true);

    $scheda = asgRisorsa(asgProps($this->actingAs($this->mario)->get(route('user.segnalazioni.show', $this->s)))['segnalazione']);

    expect($scheda['seguita_da'] ?? null)->toBeNull();
});

test('con l\'impostazione accesa, il portale non dice il nome di chi non può più seguire le segnalazioni', function (string $caso) {
    asgImpostazione(ASG_IMP_PORTALE, true);
    $chi = $caso === 'sospesa' ? ccmStudioSenzaAnagrafica('Ida Sospesa', 'collaboratore') : $this->lucia;
    $s = asgAssegnata($this, $chi);
    if ($caso === 'sospesa') {
        $chi->forceFill(['suspended_at' => now()])->save();
    }

    $props = asgProps($this->actingAs($this->mario)->get(route('user.segnalazioni.show', $s)));

    expect(asgRisorsa($props['segnalazione'])['seguita_da'] ?? null)->toBeNull()
        ->and(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain($chi->name);
})->with(['sospesa', 'senza pannello']);

test('l\'elenco del portale non riceve mai i campi dell\'assegnazione del pannello', function (bool $accesa) {
    asgImpostazione(ASG_IMP_PORTALE, $accesa);
    $s = asgAssegnata($this, $this->elena);

    $props = asgProps($this->actingAs($this->mario)->get(route('user.segnalazioni.index')));
    $riga = collect(asgElenco($props))->firstWhere('id', $s->id);

    expect($riga)->not->toBeNull('La segnalazione non è nell\'elenco del portale')
        ->and($riga)->not->toHaveKey('assigned_to')
        ->and($riga)->not->toHaveKey('assegnata_a')
        ->and(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain($this->elena->email);
})->with(['impostazione spenta' => [false], 'impostazione accesa' => [true]]);

// --- La pagina delle impostazioni generali -------------------------------------------------------------

test('la pagina delle impostazioni generali riceve i due interruttori', function () {
    asgImpostazione(ASG_IMP_PORTALE, true);

    $props = asgProps($this->actingAs($this->admin)->get(route('impostazioni.generali')));

    expect($props[ASG_IMP_PORTALE] ?? null)->toBeTrue()
        ->and($props[ASG_IMP_STATO] ?? null)->toBeFalse();
});

test('salvando la pagina i due interruttori si accendono e si spengono', function () {
    $this->actingAs($this->admin)->post(route('impostazioni.generali.store'), asgModuloImpostazioni([ASG_IMP_PORTALE => true, ASG_IMP_STATO => true]))
        ->assertSessionHasNoErrors();
    app()->forgetInstance(GeneralSettings::class);
    expect(app(GeneralSettings::class)->{ASG_IMP_PORTALE})->toBeTrue()
        ->and(app(GeneralSettings::class)->{ASG_IMP_STATO})->toBeTrue();

    $this->actingAs($this->admin)->post(route('impostazioni.generali.store'), asgModuloImpostazioni([ASG_IMP_PORTALE => false, ASG_IMP_STATO => true]))
        ->assertSessionHasNoErrors();
    app()->forgetInstance(GeneralSettings::class);
    expect(app(GeneralSettings::class)->{ASG_IMP_PORTALE})->toBeFalse()
        ->and(app(GeneralSettings::class)->{ASG_IMP_STATO})->toBeTrue();
});

test('un salvataggio che non manda i due campi li lascia com\'erano', function () {
    asgImpostazione(ASG_IMP_PORTALE, true);
    asgImpostazione(ASG_IMP_STATO, true);

    $this->actingAs($this->admin)->post(route('impostazioni.generali.store'), asgModuloImpostazioni())->assertSessionHasNoErrors();

    app()->forgetInstance(GeneralSettings::class);
    expect(app(GeneralSettings::class)->{ASG_IMP_PORTALE})->toBeTrue()
        ->and(app(GeneralSettings::class)->{ASG_IMP_STATO})->toBeTrue();
});

test('i due interruttori hanno titolo e spiegazione, nelle quattro lingue', function () {
    $chiavi = [
        'impostazioni.dialogs.segnalazioni_assegnazione_visibile_nel_portale_title',
        'impostazioni.dialogs.segnalazioni_assegnazione_visibile_nel_portale_description',
        'impostazioni.dialogs.segnalazioni_assegnazione_porta_in_lavorazione_title',
        'impostazioni.dialogs.segnalazioni_assegnazione_porta_in_lavorazione_description',
    ];
    foreach ($chiavi as $chiave) {
        foreach (['it', 'en', 'es', 'pt'] as $lingua) {
            expect(Lang::hasForLocale($chiave, $lingua))->toBeTrue("Manca {$chiave} in {$lingua}");
        }
    }

    $it = fn (string $chiave) => __("impostazioni.dialogs.{$chiave}", [], 'it');
    expect($it('segnalazioni_assegnazione_visibile_nel_portale_title'))->toBe('Mostra ai condòmini chi segue la segnalazione')
        ->and($it('segnalazioni_assegnazione_visibile_nel_portale_description'))->toBe('Acceso, nella scheda del portale il condòmino legge il nome di chi nello studio segue la sua segnalazione. Spento, l\'assegnazione resta interna allo studio.')
        ->and($it('segnalazioni_assegnazione_porta_in_lavorazione_title'))->toBe('Assegnando una segnalazione aperta, passa a «in lavorazione»')
        ->and($it('segnalazioni_assegnazione_porta_in_lavorazione_description'))->toBe('Acceso, una segnalazione aperta che viene assegnata passa da sola a «in lavorazione». Spento, lo stato lo cambia chi la lavora.');
});
