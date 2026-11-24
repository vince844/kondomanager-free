<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Conferma di lettura delle comunicazioni: che cosa vede lo studio (Coda 594, decisione 311)
 *
 * - **La scheda del pannello** riceve `letture`: il riassunto e l'elenco persona per persona, con lo stato di ognuno
 *   (`senza_accesso`, `non_aperta`, `non_confermata`, `aperta`, `presa_visione`) e con `manca`, che dice chi va ancora
 *   sentito. Chi ha scritto la comunicazione non è nell'elenco. Senza nessuna delle due forme attiva, `letture` dice
 *   solo che non sono attive.
 * - **L'elenco del pannello** porta due numeri per riga, le aperture e le prese visione, **senza richieste al database
 *   riga per riga**.
 * - **Il portale** non riceve le letture degli altri, né nell'elenco né nella scheda.
 * - **Il modulo del pannello** salva «Chiedi la presa visione», in creazione e in modifica; dal portale non si chiede.
 */

use App\Models\Comunicazione;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/LettureScenario.php';

beforeEach(function () {
    lctPrepara($this);
});

/** Le righe di `letture` per nome. */
function lctPerNome(array $letture): array
{
    return collect($letture['righe'])->keyBy('nome')->all();
}

/** Lo stato di ogni persona, nell'ordine dell'elenco. */
function lctStati(array $letture): array
{
    return collect($letture['righe'])->mapWithKeys(fn ($riga) => [$riga['nome'] => $riga['stato']])->all();
}

/** I campi del modulo del pannello per una comunicazione. */
function lctModulo($test, array $campi = []): array
{
    return array_merge([
        'subject' => 'Lettura dei contatori',
        'description' => 'Giovedì passa il letturista.',
        'priority' => 'media',
        'can_comment' => false,
        'is_featured' => false,
        'is_published' => true,
        'condomini_ids' => [$test->aurora->id],
    ], $campi);
}

/** Otto condòmini in più nel palazzo Aurora, ognuno con la sua lettura della comunicazione. */
function lctAltriOtto($test, Comunicazione $comunicazione): void
{
    foreach (range(1, 8) as $n) {
        $u = ccmUtente([$test->aurora->id], "Condomino Numero{$n}");
        lctScrivi($comunicazione, $u, '2026-11-11 09:00:00', $n % 2 === 0 ? '2026-11-11 09:05:00' : null);
    }
}

// --- La scheda del pannello -------------------------------------------------------------------------------

test('senza nessuna delle due forme attiva, la scheda dice solo che non sono attive', function () {
    expect(lctLetture($this, $this->admin, $this->c))->toBe([
        'registra_aperture' => false,
        'chiedi_presa_visione' => false,
        'attive' => false,
        'riassunto' => null,
        'righe' => [],
    ]);
});

test('con l\'impostazione accesa la scheda riceve il riassunto e l\'elenco persona per persona', function () {
    lctImpostazione(true);
    lctScrivi($this->c, $this->mario, '2026-11-11 09:15:00');

    $letture = lctLetture($this, $this->admin, $this->c);

    expect($letture['registra_aperture'])->toBeTrue()
        ->and($letture['chiedi_presa_visione'])->toBeFalse()
        ->and($letture['attive'])->toBeTrue()
        ->and($letture['riassunto'])->toBe(['destinatari' => 4, 'senza_accesso' => 1, 'aperte' => 1, 'prese_visione' => 0])
        ->and(lctStati($letture))->toBe([
            'Anna Collaboratrice' => 'non_aperta',
            'Giulia Senzaccount' => 'senza_accesso',
            'Mario Rossi' => 'aperta',
            'Paolo Neri' => 'non_aperta',
        ]);

    $righe = lctPerNome($letture);
    expect($righe['Mario Rossi'])->toBe([
        'anagrafica_id' => $this->mario->anagrafica->id,
        'nome' => 'Mario Rossi',
        'stato' => 'aperta',
        // Le 09:15 UTC sono le 10:15 a Roma.
        'aperta' => ['il' => '11/11/2026', 'alle' => '10:15'],
        'presa_visione' => null,
        'manca' => false,
    ])
        ->and($righe['Giulia Senzaccount'])->toBe([
            'anagrafica_id' => $this->giulia->id,
            'nome' => 'Giulia Senzaccount',
            'stato' => 'senza_accesso',
            'aperta' => null,
            'presa_visione' => null,
            'manca' => true,
        ])
        ->and($righe['Paolo Neri']['manca'])->toBeTrue();
});

test('chi ha scritto la comunicazione non è nell\'elenco e non entra nel conto', function () {
    lctImpostazione(true);
    $diMario = ccmComunicazione([$this->aurora], $this->mario, ['subject' => 'Bicicletta nell\'androne']);

    $letture = lctLetture($this, $this->admin, $diMario);

    // Lo Studio Bianchi ha un'anagrafica in Aurora: di questa comunicazione è un destinatario come gli altri.
    expect(array_keys(lctStati($letture)))->toBe(['Anna Collaboratrice', 'Giulia Senzaccount', 'Paolo Neri', 'Studio Bianchi'])
        ->and($letture['riassunto']['destinatari'])->toBe(4);
    expect(array_keys(lctStati(lctLetture($this, $this->admin, $this->c))))->not->toContain('Studio Bianchi');
});

test('la scheda non porta dati delle persone oltre al nome', function () {
    lctImpostazione(true);
    lctScrivi($this->c, $this->mario, '2026-11-11 09:15:00');

    $letture = lctLetture($this, $this->admin, $this->c);
    $tutto = json_encode($letture, JSON_UNESCAPED_UNICODE);

    foreach ($letture['righe'] as $riga) {
        expect(array_keys($riga))->toBe(['anagrafica_id', 'nome', 'stato', 'aperta', 'presa_visione', 'manca']);
    }
    expect($tutto)->not->toContain($this->mario->email)
        ->and($tutto)->not->toContain($this->paolo->email);
});

test('con la presa visione chiesta e l\'impostazione spenta, chi non ha premuto il pulsante «non ha confermato»', function () {
    $c = lctChiedi($this->c);
    lctScrivi($c, $this->mario, null, '2026-11-11 09:15:00');

    $letture = lctLetture($this, $this->admin, $c);

    expect($letture['registra_aperture'])->toBeFalse()
        ->and($letture['chiedi_presa_visione'])->toBeTrue()
        ->and($letture['attive'])->toBeTrue()
        ->and($letture['riassunto'])->toBe(['destinatari' => 4, 'senza_accesso' => 1, 'aperte' => 0, 'prese_visione' => 1])
        ->and(lctStati($letture))->toBe([
            'Anna Collaboratrice' => 'non_confermata',
            'Giulia Senzaccount' => 'senza_accesso',
            'Mario Rossi' => 'presa_visione',
            'Paolo Neri' => 'non_confermata',
        ]);

    $righe = lctPerNome($letture);
    expect($righe['Mario Rossi']['presa_visione'])->toBe(['il' => '11/11/2026', 'alle' => '10:15'])
        ->and($righe['Mario Rossi']['aperta'])->toBeNull()
        ->and($righe['Mario Rossi']['manca'])->toBeFalse()
        ->and($righe['Paolo Neri']['manca'])->toBeTrue()
        ->and($righe['Giulia Senzaccount']['manca'])->toBeTrue();
});

test('con tutte e due le forme, chi ha aperto ma non ha confermato manca ancora', function () {
    lctImpostazione(true);
    $c = lctChiedi($this->c);
    lctScrivi($c, $this->mario, '2026-11-11 09:00:00', '2026-11-11 09:05:00');
    lctScrivi($c, $this->paolo, '2026-11-11 12:00:00');

    $letture = lctLetture($this, $this->admin, $c);
    $righe = lctPerNome($letture);

    expect($letture['riassunto'])->toBe(['destinatari' => 4, 'senza_accesso' => 1, 'aperte' => 2, 'prese_visione' => 1])
        ->and(lctStati($letture))->toBe([
            'Anna Collaboratrice' => 'non_aperta',
            'Giulia Senzaccount' => 'senza_accesso',
            'Mario Rossi' => 'presa_visione',
            'Paolo Neri' => 'aperta',
        ])
        ->and($righe['Mario Rossi']['aperta'])->toBe(['il' => '11/11/2026', 'alle' => '10:00'])
        ->and($righe['Mario Rossi']['presa_visione'])->toBe(['il' => '11/11/2026', 'alle' => '10:05'])
        ->and($righe['Mario Rossi']['manca'])->toBeFalse()
        ->and($righe['Paolo Neri']['manca'])->toBeTrue();
});

test('spenta l\'impostazione, le aperture registrate restano nella scheda', function () {
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');

    $letture = lctLetture($this, $this->admin, $this->c);

    expect($letture['registra_aperture'])->toBeFalse()
        ->and($letture['chiedi_presa_visione'])->toBeFalse()
        ->and($letture['attive'])->toBeTrue()
        ->and($letture['riassunto']['aperte'])->toBe(1)
        ->and(lctStati($letture)['Mario Rossi'])->toBe('aperta')
        ->and(lctStati($letture)['Paolo Neri'])->toBe('non_aperta');
});

test('una presa visione data prima resta nella scheda anche se la spunta è stata tolta', function () {
    lctScrivi($this->c, $this->mario, null, '2026-11-11 09:00:00');

    $letture = lctLetture($this, $this->admin, $this->c);

    expect($letture['attive'])->toBeTrue()
        ->and($letture['riassunto']['prese_visione'])->toBe(1)
        ->and(lctStati($letture)['Mario Rossi'])->toBe('presa_visione');
});

test('chi è sospeso risulta senza accesso al portale; se aveva già letto, resta la sua lettura', function () {
    lctImpostazione(true);
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');
    $this->mario->forceFill(['suspended_at' => now()])->save();
    $this->paolo->forceFill(['suspended_at' => now()])->save();

    $letture = lctLetture($this, $this->admin, $this->c);

    expect(lctStati($letture)['Mario Rossi'])->toBe('aperta')
        ->and(lctStati($letture)['Paolo Neri'])->toBe('senza_accesso')
        ->and($letture['riassunto']['senza_accesso'])->toBe(2);
});

test('i nomi sono in ordine senza distinguere maiuscole e minuscole', function () {
    lctImpostazione(true);
    ccmUtente([$this->aurora->id], 'dario minuscolo');

    expect(array_keys(lctStati(lctLetture($this, $this->admin, $this->c))))->toBe([
        'Anna Collaboratrice', 'dario minuscolo', 'Giulia Senzaccount', 'Mario Rossi', 'Paolo Neri',
    ]);
});

test('una comunicazione non ancora uscita non ha destinatari', function () {
    $bozza = lctChiedi(ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Bozza', 'is_published' => false]));

    $letture = lctLetture($this, $this->admin, $bozza);

    expect($letture['attive'])->toBeTrue()
        ->and($letture['riassunto'])->toBe(['destinatari' => 0, 'senza_accesso' => 0, 'aperte' => 0, 'prese_visione' => 0])
        ->and($letture['righe'])->toBe([]);
});

test('una comunicazione indirizzata a delle persone elenca solo loro', function () {
    $c = lctChiedi(ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Conguaglio dell\'acqua'], [$this->paolo, $this->mario]));

    $letture = lctLetture($this, $this->admin, $c);

    expect(array_keys(lctStati($letture)))->toBe(['Mario Rossi', 'Paolo Neri'])
        ->and($letture['riassunto']['destinatari'])->toBe(2);
});

test('le letture le vede chi nel pannello apre la scheda, senza un permesso in più', function () {
    lctImpostazione(true);
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');

    $letture = lctLetture($this, $this->collaboratrice, $this->c);

    expect($letture['attive'])->toBeTrue()
        ->and($letture['riassunto']['aperte'])->toBe(1);
});

test('la scheda non fa richieste al database persona per persona', function () {
    lctImpostazione(true);
    lctChiedi($this->c);
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');

    $conQuattro = lctRichieste(fn () => lctApri($this, $this->admin, $this->c, 'admin')->assertOk());
    lctAltriOtto($this, $this->c);
    $conDodici = lctRichieste(fn () => lctApri($this, $this->admin, $this->c, 'admin')->assertOk());

    expect(lctLetture($this, $this->admin, $this->c)['righe'])->toHaveCount(12);
    expect($conDodici - $conQuattro)->toBeLessThanOrEqual(1, 'Con otto destinatari in più la scheda fa ' . ($conDodici - $conQuattro) . ' richieste in più');
});

// --- L'elenco del pannello ---------------------------------------------------------------------------------

test('l\'elenco del pannello porta per ogni comunicazione le aperture e le prese visione', function () {
    $chiesta = lctChiedi(ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Pulizia delle scale']));
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');
    lctScrivi($this->c, $this->paolo, '2026-11-11 09:00:00', '2026-11-11 09:05:00');
    lctScrivi($this->c, $this->collaboratrice, null, '2026-11-11 09:05:00');

    $righe = lctElenco(lctProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.index'))));

    expect($righe[$this->c->id]['letture'] ?? null)->toBe(['aperte' => 2, 'prese_visione' => 2])
        ->and($righe[$this->c->id]['chiedi_presa_visione'] ?? null)->toBeFalse()
        ->and($righe[$chiesta->id]['letture'] ?? null)->toBe(['aperte' => 0, 'prese_visione' => 0])
        ->and($righe[$chiesta->id]['chiedi_presa_visione'] ?? null)->toBeTrue();
});

test('l\'elenco del pannello non fa richieste al database riga per riga', function () {
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');
    $elenco = fn () => $this->actingAs($this->admin)->get(route('admin.comunicazioni.index'))->assertOk();

    $conUna = lctRichieste($elenco);
    foreach (range(1, 7) as $n) {
        $c = lctChiedi(ccmComunicazione([$this->aurora], $this->admin, ['subject' => "Avviso numero {$n}"]));
        lctScrivi($c, $this->mario, '2026-11-11 09:00:00', '2026-11-11 09:05:00');
        lctScrivi($c, $this->paolo, '2026-11-11 09:00:00');
    }
    $conOtto = lctRichieste($elenco);

    expect(lctElenco(lctProps($elenco())))->toHaveCount(8);
    expect($conOtto - $conUna)->toBeLessThanOrEqual(1, 'Con sette comunicazioni in più l\'elenco del pannello fa ' . ($conOtto - $conUna) . ' richieste in più');
});

// --- Il portale ---------------------------------------------------------------------------------------------

test('l\'elenco del portale non riceve le letture', function () {
    lctImpostazione(true);
    lctChiedi($this->c);
    lctScrivi($this->c, $this->paolo, '2026-11-11 09:00:00', '2026-11-11 09:05:00');

    $props = lctProps($this->actingAs($this->mario)->get(route('user.comunicazioni.index')));
    $righe = lctElenco($props);

    expect($righe)->toHaveKey($this->c->id)
        ->and($righe[$this->c->id])->not->toHaveKey('letture')
        ->and(json_encode($props, JSON_UNESCAPED_UNICODE))->not->toContain('Paolo Neri');
});

test('l\'elenco del portale non fa richieste al database in più per le letture', function () {
    lctImpostazione(true);
    lctScrivi($this->c, $this->paolo, '2026-11-11 09:00:00');
    $elenco = fn () => $this->actingAs($this->mario)->get(route('user.comunicazioni.index'))->assertOk();

    $conUna = lctRichieste($elenco);
    foreach (range(1, 7) as $n) {
        $c = lctChiedi(ccmComunicazione([$this->aurora], $this->admin, ['subject' => "Avviso numero {$n}"]));
        lctScrivi($c, $this->paolo, '2026-11-11 09:00:00', '2026-11-11 09:05:00');
    }
    $conOtto = lctRichieste($elenco);

    expect(lctElenco(lctProps($elenco())))->toHaveCount(8);
    expect($conOtto - $conUna)->toBeLessThanOrEqual(1, 'Con sette comunicazioni in più l\'elenco del portale fa ' . ($conOtto - $conUna) . ' richieste in più');
});

// --- Il modulo ----------------------------------------------------------------------------------------------

test('dal modulo del pannello «Chiedi la presa visione» si salva alla creazione', function () {
    $this->actingAs($this->admin)->post(route('admin.comunicazioni.store'), lctModulo($this, [LCT_COLONNA => true]))
        ->assertSessionHasNoErrors();
    $chiesta = Comunicazione::query()->orderByDesc('id')->firstOrFail();

    $this->actingAs($this->admin)->post(route('admin.comunicazioni.store'), lctModulo($this, ['subject' => 'Senza la spunta']))
        ->assertSessionHasNoErrors();
    $nonChiesta = Comunicazione::query()->orderByDesc('id')->firstOrFail();

    expect($chiesta->subject)->toBe('Lettura dei contatori')
        ->and(lctChiesta($chiesta->id))->toBeTrue()
        ->and($nonChiesta->subject)->toBe('Senza la spunta')
        ->and(lctChiesta($nonChiesta->id))->toBeFalse();
});

test('in modifica la spunta si mette e si toglie; un salvataggio che non la manda la lascia com\'era', function () {
    $stato = fn () => lctChiesta($this->c->id);
    $salva = fn (array $campi) => $this->actingAs($this->admin)
        ->put(route('admin.comunicazioni.update', $this->c), lctModulo($this, $campi))
        ->assertSessionHasNoErrors();

    $salva([LCT_COLONNA => true]);
    expect($stato())->toBeTrue();

    $salva([]);
    expect($stato())->toBeTrue();

    $salva([LCT_COLONNA => false]);
    expect($stato())->toBeFalse();
});

test('un valore che non è vero o falso è rifiutato', function () {
    $this->actingAs($this->admin)->post(route('admin.comunicazioni.store'), lctModulo($this, [LCT_COLONNA => 'forse']))
        ->assertSessionHasErrors(LCT_COLONNA);
});

test('la scheda e la pagina di modifica del pannello ricevono la spunta', function () {
    $c = lctChiedi($this->c);

    $scheda = lctRisorsa(lctProps(lctApri($this, $this->admin, $c, 'admin'))['comunicazione']);
    $modifica = lctRisorsa(lctProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.edit', $c)))['comunicazione']);

    expect($scheda[LCT_COLONNA] ?? null)->toBeTrue()
        ->and($modifica[LCT_COLONNA] ?? null)->toBeTrue();
});

test('dal portale un condòmino non chiede la presa visione', function () {
    // Di serie un condòmino non scrive comunicazioni: qui Mario ha il permesso, come in uno studio che lo concede.
    $this->mario->givePermissionTo('Crea comunicazioni');

    $this->actingAs($this->mario)->post(route('user.comunicazioni.store'), [
        'subject' => 'Bicicletta nell\'androne',
        'description' => 'Qualcuno lascia la bicicletta davanti alle cassette.',
        'priority' => 'bassa',
        'is_featured' => false,
        'is_private' => false,
        'condomini_ids' => [$this->aurora->id],
        LCT_COLONNA => true,
    ])->assertSessionHasNoErrors();

    $nuova = Comunicazione::query()->where('created_by', $this->mario->id)->orderByDesc('id')->first();

    expect($nuova)->not->toBeNull('La comunicazione del condòmino non è stata salvata')
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('comunicazioni', LCT_COLONNA))->toBeTrue('Manca la colonna comunicazioni.' . LCT_COLONNA)
        ->and(lctChiesta($nuova->id))->toBeFalse();
});
