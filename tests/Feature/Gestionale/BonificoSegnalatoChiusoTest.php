<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un bonifico segnalato dal portale non resta «in verifica» per sempre. Quando il compito della segnalazione si chiude (l'incasso registrato da «Registra l'incasso»,
 * «Fatto», «Non serve»), la rata segnalata non resta `reported`: pagata, in parte pagata, o di nuovo da versare se l'incasso è andato su altre rate; allora il
 * condòmino legge nel portale dove è andato il suo versamento, con le rate e le cifre, e può segnalare di nuovo. Con «Non serve» il rifiuto di sempre. Le rate
 * rimaste bloccate prima (`reported` con il compito già chiuso) si sbloccano con la correzione una volta sola, senza gesti dell'amministratore.
 *
 * Il caso: Ugo, rate mensili da € 100,00 dal 05/01/2026, le prime tre emesse; oggi il 10/03/2026; segnala dal portale la rata 3.
 */

use App\Enums\EventoTipo;
use App\Enums\Role;
use App\Models\Evento;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/LetturaDebitiProva.php';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->user = User::factory()->create(['name' => 'Vincenzo', 'email_verified_at' => now()]);
    $this->user->assignRole(Role::AMMINISTRATORE->value);
    $this->user = $this->user->fresh();
    DB::connection()->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($x) => $x);

    $this->travelTo('2026-01-02 10:00:00');
    $this->s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    // Come l'approvazione del piano dalla pagina: i promemoria delle rate per il portale.
    \App\Events\Gestionale\PianoRateStatusUpdated::dispatch($this->s['c'], $this->s['e'], $this->s['piano'], $this->user, \App\Enums\StatoPianoRate::BOZZA,
        \App\Enums\StatoPianoRate::APPROVATO);
    lcdEmetti($this, $this->s, [1, 2, 3], '2026-01-02');
    $this->travelTo('2026-03-10 10:00:00');
    $this->condomino = User::factory()->create(['email_verified_at' => now()]);
    $this->condomino->assignRole(Role::UTENTE->value);
    $this->s['persone']['ugo']->update(['user_id' => $this->condomino->id]);
});

/** Il promemoria della rata di Ugo nel portale. */
function bscRata(array $s, int $n): Evento
{
    return Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->context->rata_id', lcdRata($s, $n))
        ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $s['persone']['ugo']->id))->firstOrFail();
}

/** Ugo segnala dal portale il pagamento della rata; il compito della verifica. */
function bscSegnala($test, int $n): Evento
{
    $test->actingAs($test->condomino)->post(route('user.eventi.report_payment', bscRata($test->s, $n)))->assertSessionHasNoErrors();

    return Evento::where('tipo', EventoTipo::VERIFICA_PAGAMENTO->value)->orderByDesc('id')->firstOrFail();
}

/** L'incasso dalla rotta della pagina: [numero della rata => centesimi], con il compito collegato se c'è. */
function bscIncassa($test, array $righe, ?Evento $compito = null)
{
    $totale = array_sum($righe);

    return $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$test->s['c']]), [
        'pagante_id' => $test->s['persone']['ugo']->id, 'cassa_id' => $test->s['cassa']->id, 'data_pagamento' => '2026-03-10',
        'importo_totale' => number_format($totale / 100, 2, '.', ''), 'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $test->s['g']->id,
        'dettaglio_pagamenti' => array_map(fn ($n, $c) => ['rata_id' => lcdQuota($test->s, 'ugo', $n), 'importo' => number_format($c / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
    ] + ($compito !== null ? ['related_task_id' => $compito->id] : []))->assertSessionHasNoErrors();
}

it('l\'incasso intero sulla rata segnalata, da «Registra l\'incasso»: il compito si chiude e la rata è pagata', function () {
    $compito = bscSegnala($this, 3);
    bscIncassa($this, [3 => 10000], $compito);

    expect($compito->fresh()->is_completed)->toBeTrue()
        ->and(bscRata($this->s, 3)->meta['status'])->toBe('paid')
        ->and(bscRata($this->s, 3)->meta)->not->toHaveKey('esito_segnalazione');
});

it('l\'incasso in parte sulla rata segnalata: in parte pagata, e la frase dice dove è andato il resto', function () {
    $compito = bscSegnala($this, 3);
    bscIncassa($this, [3 => 5000, 1 => 10000], $compito);
    $rata = bscRata($this->s, 3);

    expect($rata->meta['status'])->toBe('partial')
        ->and($rata->meta['esito_segnalazione'])->toBe('Il tuo versamento del 10/03/2026, € 150,00, ha pagato € 50,00 di questa rata; il resto è andato su altre rate: '
            . 'rata 1 di «Preventivo 2026», € 100,00.');
});

it('l\'incasso tutto su altre rate: la rata segnalata torna da versare, la frase dice dove è andato il versamento, e Ugo può segnalare di nuovo', function () {
    $compito = bscSegnala($this, 3);
    bscIncassa($this, [1 => 10000, 2 => 5000], $compito);
    $rata = bscRata($this->s, 3);

    expect($compito->fresh()->is_completed)->toBeTrue()
        ->and($rata->meta['status'])->toBe('pending')
        ->and($rata->meta['esito_segnalazione'])->toBe('Il tuo versamento del 10/03/2026, € 150,00, è andato su altre rate: rata 1 di «Preventivo 2026», € 100,00; '
            . 'rata 2 di «Preventivo 2026», € 50,00. Questa rata resta da versare: quando l\'hai pagata, puoi segnalarlo di nuovo.');

    $nuovo = bscSegnala($this, 3);
    expect(bscRata($this->s, 3)->meta['status'])->toBe('reported')
        ->and(bscRata($this->s, 3)->meta)->not->toHaveKey('esito_segnalazione')
        ->and($nuovo->id)->not->toBe($compito->id)
        ->and($nuovo->is_completed)->toBeFalse();
});

it('«Fatto» dopo un incasso registrato dalla pagina degli incassi (senza «Registra l\'incasso»): si accetta, e la rata non resta in verifica', function () {
    $compito = bscSegnala($this, 3);
    bscIncassa($this, [1 => 5000]);
    expect(bscRata($this->s, 3)->meta['status'])->toBe('reported');

    $this->actingAs($this->user)->from(route('admin.inbox'))->post(route('admin.inbox.risposta', $compito), ['tipo' => 'fatto'])->assertSessionHasNoErrors();

    expect($compito->fresh()->is_completed)->toBeTrue()
        ->and(bscRata($this->s, 3)->meta['status'])->toBe('pending')
        ->and(bscRata($this->s, 3)->meta['esito_segnalazione'])->toBe('Il tuo versamento del 10/03/2026, € 50,00, è andato su altre rate: rata 1 di «Preventivo 2026», € 50,00. '
            . 'Questa rata resta da versare: quando l\'hai pagata, puoi segnalarlo di nuovo.');
});

it('«Fatto» dopo un incasso parziale sulla rata segnalata: in parte pagata, come le altre', function () {
    $compito = bscSegnala($this, 3);
    bscIncassa($this, [3 => 4000]);
    expect(bscRata($this->s, 3)->meta['status'])->toBe('partial');

    $this->actingAs($this->user)->from(route('admin.inbox'))->post(route('admin.inbox.risposta', $compito), ['tipo' => 'fatto'])->assertSessionHasNoErrors();

    expect($compito->fresh()->is_completed)->toBeTrue()->and(bscRata($this->s, 3)->meta['status'])->toBe('partial');
});

it('«Fatto» senza nessun incasso dopo la segnalazione resta spento, con la sua frase', function () {
    $compito = bscSegnala($this, 3);
    $this->actingAs($this->user)->from(route('admin.inbox'))->post(route('admin.inbox.risposta', $compito), ['tipo' => 'fatto'])
        ->assertSessionHasErrors(['tipo' => 'Prima registra l\'incasso con «Registra l\'incasso», o rifiuta la segnalazione con «Non serve».']);

    expect($compito->fresh()->is_completed)->toBeFalse()->and(bscRata($this->s, 3)->meta['status'])->toBe('reported');
});

it('«Non serve» è il rifiuto di sempre, e Ugo può segnalare di nuovo', function () {
    $compito = bscSegnala($this, 3);
    $this->actingAs($this->user)->from(route('admin.inbox'))->post(route('admin.inbox.risposta', $compito), ['tipo' => 'non_serve', 'motivo' => 'Bonifico non arrivato'])
        ->assertSessionHasNoErrors();

    expect(bscRata($this->s, 3)->meta['status'])->toBe('rejected')
        ->and(bscRata($this->s, 3)->meta['rejection_reason'])->toBe('Bonifico non arrivato');
    bscSegnala($this, 3);
    expect(bscRata($this->s, 3)->meta['status'])->toBe('reported');
});

it('le rate già bloccate prima (in verifica con il compito chiuso) si sbloccano con la correzione una volta sola; rieseguita non fa niente', function () {
    $compito = bscSegnala($this, 3);
    // Com'era prima: l'incasso da «Registra l'incasso» è andato sulle rate più vecchie, il compito si è chiuso e la rata è rimasta in verifica.
    bscIncassa($this, [1 => 10000]);
    $compito->update(['is_completed' => true, 'completed_at' => now()]);
    expect(bscRata($this->s, 3)->meta['status'])->toBe('reported');

    $migrazione = require database_path('migrations/2026_11_15_210000_sblocca_le_rate_segnalate_con_il_compito_chiuso.php');
    $migrazione->up();
    $rata = bscRata($this->s, 3);

    expect($rata->meta['status'])->toBe('pending')
        ->and($rata->meta['esito_segnalazione'])->toBe('Il tuo versamento del 10/03/2026, € 100,00, è andato su altre rate: rata 1 di «Preventivo 2026», € 100,00. '
            . 'Questa rata resta da versare: quando l\'hai pagata, puoi segnalarlo di nuovo.');

    $prima = bscRata($this->s, 3)->meta;
    $migrazione->up();
    expect(bscRata($this->s, 3)->meta)->toBe($prima);
});

it('una segnalazione con il compito ancora aperto non si tocca: aspetta l\'amministratore', function () {
    bscSegnala($this, 3);
    bscIncassa($this, [1 => 10000]);
    $migrazione = require database_path('migrations/2026_11_15_210000_sblocca_le_rate_segnalate_con_il_compito_chiuso.php');
    $migrazione->up();

    expect(bscRata($this->s, 3)->meta['status'])->toBe('reported');
});
