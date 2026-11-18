<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Ogni segnalazione di guasto aperta o in lavorazione è un compito dell'Inbox (`CompitoDellaSegnalazione`, chiamato dalla segnalazione stessa): nasce con la
 * segnalazione, dallo studio o dal portale; si chiude da solo quando la segnalazione si chiude e torna quando la si riapre; sparisce con la segnalazione
 * cancellata. La prossima azione dipende dallo stato («Approva o rifiuta» finché aspetta l'approvazione, poi «Apri la segnalazione»); la priorità si vede
 * (il badge, le urgenti prima a pari giorno, «di cui N urgenti»); il menu ha solo «Ricordamelo». Le segnalazioni entrano nel grafico «Per tipo», in «Dove si
 * accumulano», nel filtro del condominio e nel numero del menu. Per quelle già aperte una migrazione dei dati crea i compiti, senza doppioni.
 */

use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Gestionale\Inbox\CompitoDellaSegnalazione;
use Illuminate\Support\Carbon;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

/** Una segnalazione del condominio dello scenario, come la scrive lo studio. */
function aisSegnalazione($test, array $dati = []): Segnalazione
{
    return Segnalazione::create($dati + [
        'subject' => 'Ascensore fermo', 'description' => 'Si è fermato al secondo piano.', 'created_by' => $test->admin->id, 'condominio_id' => $test->condominio->id,
        'priority' => 'media', 'stato' => 'aperta', 'is_published' => true, 'is_approved' => true, 'is_private' => false, 'can_comment' => true,
    ]);
}

/** I compiti delle segnalazioni, aperti e chiusi. */
function aisCompiti(): \Illuminate\Support\Collection
{
    return Evento::where('tipo', 'segnalazione_guasto')->orderBy('id')->get();
}

/** La riga della casella con quel titolo. */
function aisRiga($test, string $titolo, array $filtri = []): ?array
{
    return collect($test->actingAs($test->admin)->get(route('admin.inbox', $filtri))->viewData('page')['props']['tasks']['data'])->firstWhere('title', $titolo);
}

it('nasce con la segnalazione dal portale, nelle due Inbox: «Approva o rifiuta» finché aspetta l\'approvazione, poi «Apri la segnalazione»', function () {
    $condomino = User::factory()->create(['email_verified_at' => now(), 'name' => 'Mario Rossi']);
    $condomino->assignRole(Role::UTENTE->value);
    Anagrafica::create(['user_id' => $condomino->id, 'nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 1'])->condomini()->attach($this->condominio->id);

    $this->actingAs($condomino)->post(route('user.segnalazioni.store'), [
        'subject' => 'Perdita in cantina', 'description' => 'Acqua sul pavimento.', 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $this->condominio->id, 'is_private' => false,
    ])->assertSessionHasNoErrors();
    $s = Segnalazione::query()->latest('id')->firstOrFail();
    $compito = aisCompiti()->sole();

    expect($compito->meta)->toMatchArray(['type' => 'segnalazione_guasto', 'requires_action' => true, 'context' => ['segnalazione_id' => $s->id], 'is_approved' => false,
        'priority' => 'alta'])
        ->and($compito->eventable_id)->toBe($s->id)
        ->and($compito->is_completed)->toBeFalse()
        ->and($compito->condomini->pluck('id')->all())->toBe([$this->condominio->id]);
    $riga = aisRiga($this, 'Perdita in cantina');
    expect($riga['type'])->toBe('segnalazione_guasto')
        ->and($riga['azione'])->toBe(['testo' => 'Approva o rifiuta', 'url' => '/admin/segnalazioni/' . $s->id])
        ->and($riga['priorita'])->toBe('alta')
        ->and(icsInboxDelCondominio($this))->toContain('Perdita in cantina');

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.toggle-approval', $s))->assertRedirect();
    expect(aisRiga($this, 'Perdita in cantina')['azione'])->toBe(['testo' => 'Apri la segnalazione', 'url' => '/admin/segnalazioni/' . $s->id])
        ->and(aisCompiti())->toHaveCount(1);
});

it('si chiude da solo con la segnalazione chiusa, torna se la si riapre, resta in lavorazione; sparisce con la segnalazione cancellata; una chiusa nasce senza', function () {
    $s = aisSegnalazione($this);
    expect(aisCompiti())->toHaveCount(1)->and(aisRiga($this, 'Ascensore fermo'))->not->toBeNull();

    $s->update(['stato' => 'in lavorazione']);
    expect(aisCompiti()->sole()->is_completed)->toBeFalse();

    $s->update(['stato' => 'chiusa']);
    expect(aisCompiti()->sole()->is_completed)->toBeTrue()
        ->and(aisCompiti()->sole()->completed_at)->not->toBeNull()
        ->and(aisRiga($this, 'Ascensore fermo'))->toBeNull();

    $s->update(['stato' => 'aperta', 'subject' => 'Ascensore fermo di nuovo']);
    expect(aisCompiti())->toHaveCount(1)
        ->and(aisCompiti()->sole()->is_completed)->toBeFalse()
        ->and(aisCompiti()->sole()->completed_at)->toBeNull()
        ->and(aisRiga($this, 'Ascensore fermo di nuovo'))->not->toBeNull();

    $s->delete();
    expect(aisCompiti())->toHaveCount(0);

    aisSegnalazione($this, ['stato' => 'chiusa', 'subject' => 'Già risolta']);
    expect(aisCompiti())->toHaveCount(0);
});

it('il giorno è quello della segnalazione e non si riscrive: «Ricordamelo» scrive un promemoria a parte, e la segnalazione torna «aperta il» suo giorno', function () {
    // Prima «Ricordamelo» spostava il giorno del compito, e la segnalazione tornava «aperta il 25/05 · oggi»: il giorno dice da quando aspetta, e resta.
    $this->travelTo(Carbon::parse('2026-05-10 10:00', 'Europe/Rome')->utc());
    $s = aisSegnalazione($this);
    $this->travelTo(Carbon::parse('2026-05-20 10:00', 'Europe/Rome')->utc());
    $compito = aisCompiti()->sole();
    expect($compito->start_time->format('Y-m-d'))->toBe('2026-05-10')
        ->and(aisRiga($this, 'Ascensore fermo')['giorni'])->toBe(-10);

    icsRispondi($this, $compito, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-25'])->assertSessionHasNoErrors();
    $s->update(['priority' => 'urgente']);
    expect(aisCompiti()->sole()->start_time->format('Y-m-d'))->toBe('2026-05-10')
        ->and(aisCompiti()->sole()->meta['priority'])->toBe('urgente')
        ->and(Evento::where('tipo', 'promemoria')->sole()->start_time->format('Y-m-d H:i'))->toBe('2026-05-25 09:00')
        ->and(aisRiga($this, 'Ascensore fermo'))->toBeNull();

    $this->travelTo(Carbon::parse('2026-05-25 10:00', 'Europe/Rome')->utc());
    $riga = aisRiga($this, 'Ascensore fermo');
    expect([$riga['giorno'], $riga['giorni'], $riga['gruppo']])->toBe(['2026-05-10', -15, 'segnalazioni']);
});

it('un compito solo per la segnalazione, aperta, in lavorazione, chiusa e riaperta; spostata in un altro condominio, il compito la segue', function () {
    $s = aisSegnalazione($this);
    foreach ([['in lavorazione', false], ['chiusa', true], ['aperta', false]] as [$stato, $chiuso]) {
        $s->update(['stato' => $stato]);
        expect(aisCompiti())->toHaveCount(1)->and(aisCompiti()->sole()->is_completed)->toBe($chiuso);
    }
    $altro = Condominio::factory()->create(['nome' => 'Via Nuova 3']);
    $s->update(['condominio_id' => $altro->id]);

    expect(aisCompiti()->sole()->condomini->pluck('id')->all())->toBe([$altro->id])
        ->and(aisRiga($this, 'Ascensore fermo', ['condominio_id' => $altro->id]))->not->toBeNull()
        ->and(aisRiga($this, 'Ascensore fermo', ['condominio_id' => $this->condominio->id]))->toBeNull();
});

it('il menu ha solo «Ricordamelo»: «Fatto», «Non serve», «Rifiuta» e «Completa» si rifiutano con la frase, e il compito resta aperto', function () {
    aisSegnalazione($this);
    $compito = aisCompiti()->sole();

    icsRispondi($this, $compito, ['tipo' => 'fatto'])->assertSessionHasErrors(['tipo' => CompitoDellaSegnalazione::FRASE_SOLO_RICORDAMELO]);
    icsRispondi($this, $compito, ['tipo' => 'non_serve', 'motivo' => 'già fatto'])->assertSessionHasErrors(['tipo' => CompitoDellaSegnalazione::FRASE_SOLO_RICORDAMELO]);
    $this->actingAs($this->admin)->from(route('admin.inbox'))->post(route('admin.inbox.reject', $compito), ['reason' => 'no'])
        ->assertSessionHasErrors(['reason' => CompitoDellaSegnalazione::FRASE_SOLO_RICORDAMELO]);
    $this->actingAs($this->admin)->from(route('admin.inbox'))->patch(route('admin.inbox.complete', $compito))
        ->assertSessionHasErrors(['tipo' => CompitoDellaSegnalazione::FRASE_SOLO_RICORDAMELO]);
    expect($compito->fresh()->is_completed)->toBeFalse();

    icsRispondi($this, $compito, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-22'])->assertSessionHasNoErrors();
    expect(aisRiga($this, 'Ascensore fermo'))->toBeNull();
});

it('la priorità: il badge «urgente» o «alta», a pari giorno le urgenti prima, e sotto la card «di cui N urgenti»', function () {
    $this->travelTo(Carbon::parse('2026-05-12 08:00', 'Europe/Rome')->utc());
    aisSegnalazione($this, ['subject' => 'Citofono', 'priority' => 'bassa']);
    $this->travelTo(Carbon::parse('2026-05-12 09:00', 'Europe/Rome')->utc());
    aisSegnalazione($this, ['subject' => 'Cancello', 'priority' => 'alta']);
    $this->travelTo(Carbon::parse('2026-05-12 11:00', 'Europe/Rome')->utc());
    aisSegnalazione($this, ['subject' => 'Gas', 'priority' => 'urgente']);
    $this->travelTo(Carbon::parse('2026-05-11 11:00', 'Europe/Rome')->utc());
    aisSegnalazione($this, ['subject' => 'Luce scale', 'priority' => 'media']);
    $this->travelTo(Carbon::parse('2026-05-20 10:00', 'Europe/Rome')->utc());

    $p = $this->actingAs($this->admin)->get(route('admin.inbox', ['tipo' => 'segnalazione_guasto']))->viewData('page')['props'];
    expect(collect($p['tasks']['data'])->map(fn ($r) => [$r['title'], $r['priorita']])->all())->toBe([
        ['Luce scale', null], ['Gas', 'urgente'], ['Cancello', 'alta'], ['Citofono', null],
    ])
        ->and($p['counts']['segnalazioni'])->toBe(4)
        ->and($p['counts']['segnalazioni_urgenti'])->toBe(1);
});

it('le segnalazioni entrano in «Per tipo», in «Dove si accumulano», nel filtro del condominio e nel numero del menu', function () {
    $altro = Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    aisSegnalazione($this);
    aisSegnalazione($this, ['subject' => 'Portone', 'condominio_id' => $altro->id]);
    \Illuminate\Support\Facades\Cache::flush();

    $p = $this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props'];
    expect(collect($p['grafici']['tipi'])->firstWhere('tipo', 'segnalazione_guasto'))->toMatchArray(['etichetta' => 'Segnalazioni', 'valore' => 2])
        ->and(collect($p['dove'])->pluck('valore', 'nome')->all())->toBe(['Condominio Aurora' => 1, 'Corso Italia 3' => 1])
        ->and($p['counts']['segnalazioni'])->toBe(2)
        ->and(icsBarra($this))->toBe(2);
    $soloAltro = $this->actingAs($this->admin)->get(route('admin.inbox', ['condominio_id' => $altro->id]))->viewData('page')['props'];
    expect(collect($soloAltro['tasks']['data'])->pluck('title')->all())->toBe(['Portone']);
});

it('la migrazione dei dati crea i compiti delle segnalazioni già aperte o in lavorazione, non delle chiuse, e rieseguita non fa doppioni', function () {
    // Le segnalazioni di prima, senza il compito: come erano prima di questa versione.
    Segnalazione::withoutEvents(function () {
        aisSegnalazione($this, ['subject' => 'Aperta']);
        aisSegnalazione($this, ['subject' => 'In lavorazione', 'stato' => 'in lavorazione', 'priority' => 'urgente']);
        aisSegnalazione($this, ['subject' => 'Chiusa', 'stato' => 'chiusa']);
    });
    expect(aisCompiti())->toHaveCount(0);
    $migrazione = require database_path('migrations/2026_11_15_230000_crea_i_compiti_delle_segnalazioni_aperte.php');

    $migrazione->up();
    $migrazione->up();

    expect(aisCompiti()->pluck('title')->all())->toBe(['Aperta', 'In lavorazione'])
        ->and(aisCompiti()->every(fn ($e) => ! $e->is_completed))->toBeTrue()
        ->and(aisCompiti()->firstWhere('title', 'In lavorazione')->meta['priority'])->toBe('urgente')
        ->and(aisRiga($this, 'Aperta')['azione']['testo'])->toBe('Apri la segnalazione');
});

it('una segnalazione non scade: aperta 267 giorni fa è nel gruppo delle segnalazioni, non fra gli scaduti, non nelle schede «Scaduti» e «In arrivo», non nel grafico per età', function () {
    $this->travelTo(Carbon::parse('2025-08-26 10:00', 'Europe/Rome')->utc());
    aisSegnalazione($this, ['priority' => 'alta']);
    $this->travelTo(Carbon::parse('2026-05-20 10:00', 'Europe/Rome')->utc());
    $p = $this->actingAs($this->admin)->get(route('admin.inbox'))->viewData('page')['props'];
    $riga = aisRiga($this, 'Ascensore fermo');

    expect($riga['gruppo'])->toBe('segnalazioni')
        ->and($riga['giorni'])->toBe(-267)
        ->and($p['counts'])->toMatchArray(['scaduti' => 0, 'settimana' => 0, 'segnalazioni' => 1])
        ->and($p['schede'])->toBe(['tutti' => 1, 'scaduti' => 0, 'in_arrivo' => 0])
        ->and(collect($p['grafici']['eta'])->sum('valore'))->toBe(0)
        ->and($p['grafici']['fuori']['segnalazioni'])->toBe(1)
        ->and($p['gruppi'])->toBe(['scaduti' => 0, 'segnalazioni' => 1, 'bonifici' => 0, 'da_moderare' => 0, 'avanti' => 0]);
    // Il filtro «Scaduti» non la trova; la card «Segnalazioni» (il tipo) sì.
    expect(aisRiga($this, 'Ascensore fermo', ['quando' => 'scaduti']))->toBeNull()
        ->and(aisRiga($this, 'Ascensore fermo', ['tipo' => 'segnalazione_guasto']))->not->toBeNull();
});
