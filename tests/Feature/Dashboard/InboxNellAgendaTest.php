<?php

/**
 * «Ricordamelo» e «Mettilo in agenda» vanno davvero nell'agenda dell'amministratore: la pagina dell'agenda e il blocco degli eventi della dashboard dello
 * studio mostrano i promemoria (anche quelli dei sei mesi e del rendiconto) e i compiti spostati da un «Ricordamelo» in attesa, nel giorno scelto, anche se
 * sono nascosti. Restano nascosti ai condòmini, e fuori dall'Inbox fino al giorno. Gli altri compiti dell'Inbox non vanno nell'agenda; il promemoria di un
 * compito che il programma ha chiuso da solo non si mostra più.
 */

use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

/** I titoli dell'agenda dello studio (i prossimi 60 giorni) e del blocco degli eventi della dashboard dello studio (90 giorni). */
function inaAgenda($test): array
{
    $agenda = collect($test->actingAs($test->admin)->get(route('admin.eventi.index'))->assertOk()->viewData('page')['props']['eventi'])
        ->map(fn ($e) => is_array($e) ? $e['title'] : $e->title)->sort()->values()->all();
    $dashboard = $test->actingAs($test->admin)->get(route('admin.dashboard'))->assertOk()->viewData('page')['props']['eventi'];
    $blocco = collect($dashboard['data'] ?? $dashboard)->map(fn ($e) => is_array($e) ? $e['title'] : $e->title)->sort()->values()->all();

    return [$agenda, $blocco];
}

it('il promemoria a parte e il compito spostato sono nell\'agenda e nel blocco della dashboard, nel giorno scelto; gli altri compiti no', function () {
    $fattura = icsCompito($this, 'pagamento_fornitore', [], 'Pagare la ditta Rossi');
    $incassi = icsCompito($this, 'controllo_incassi', [], 'Verifica incassi - Rata 3');
    icsCompito($this, 'emissione_rata', [], 'Un compito senza risposte');
    $fattura->update(['start_time' => now()->addDays(10), 'end_time' => now()->addDays(10)->addHour()]);
    icsRispondi($this, $fattura, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-25'])->assertSessionHas('message.message', 'Ti ricordo il compito il 25/05/2026: è nell\'agenda.');
    icsRispondi($this, $incassi, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-25'])->assertSessionHasNoErrors();
    Evento::create(['title' => 'Assemblea ordinaria', 'start_time' => now()->addDays(3), 'end_time' => now()->addDays(3)->addHour(), 'created_by' => $this->admin->id,
        'visibility' => 'public', 'is_approved' => true]);
    [$agenda, $blocco] = inaAgenda($this);

    expect($agenda)->toBe(['Assemblea ordinaria', 'Ricordamelo: Pagare la ditta Rossi', 'Verifica incassi - Rata 3'])
        ->and($blocco)->toBe($agenda);
    $promemoria = collect($this->actingAs($this->admin)->get(route('admin.eventi.index'))->viewData('page')['props']['eventi'])
        ->first(fn ($e) => (is_array($e) ? $e['title'] : $e->title) === 'Ricordamelo: Pagare la ditta Rossi');
    expect(substr((string) (is_array($promemoria) ? $promemoria['start_time'] : $promemoria->start_time), 0, 10))->toBe('2026-05-25');
});

it('dopo «Fatto» il compito spostato esce dall\'agenda; il promemoria di un compito chiuso dal programma non si mostra più', function () {
    $incassi = icsCompito($this, 'controllo_incassi', [], 'Verifica incassi - Rata 3');
    $fattura = icsCompito($this, 'pagamento_fornitore', [], 'Pagare la ditta Rossi');
    icsRispondi($this, $incassi, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-25'])->assertSessionHasNoErrors();
    icsRispondi($this, $fattura, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-25'])->assertSessionHasNoErrors();
    icsRispondi($this, $incassi, ['tipo' => 'fatto'])->assertSessionHasNoErrors();
    // Come `SyncScadenziarioWithPagamento` a fattura pagata: il compito non chiede più un'azione ed è chiuso; il promemoria resta nell'agenda, nascosto.
    $fattura->fresh()->update(['is_completed' => true, 'completed_at' => now()]);
    [$agenda, $blocco] = inaAgenda($this);

    expect($agenda)->toBe([])->and($blocco)->toBe([])
        ->and(Evento::where('title', 'Ricordamelo: Pagare la ditta Rossi')->exists())->toBeTrue();
});

it('il promemoria dei sei mesi e «Mettilo in agenda» del rendiconto sono nell\'agenda', function () {
    $e2025 = \App\Models\Esercizio::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01',
        'data_fine' => '2025-12-31', 'stato' => 'aperto']);
    app(\App\Services\Gestionale\RispostePromemoria::class)->inAgenda($this->condominio, (int) $e2025->id, '2026-06-29', null, $this->admin,
        'Convocare l\'assemblea per il rendiconto 2025 — Condominio Aurora', '/ritorno');
    app(\App\Services\Gestionale\RispostePromemoria::class)->ricordamelo($this->condominio, 'sei_mesi', (int) $e2025->id, '2026-06-01', null, $this->admin,
        'Ricordamelo: sei mesi per agire, esercizio 2025 — Condominio Aurora', '/ritorno');
    [$agenda] = inaAgenda($this);

    expect($agenda)->toBe(['Convocare l\'assemblea per il rendiconto 2025 — Condominio Aurora', 'Ricordamelo: sei mesi per agire, esercizio 2025 — Condominio Aurora'])
        ->and(RispostaPromemoria::count())->toBe(2);
});

it('i condòmini non li vedono: la loro agenda resta quella di prima', function () {
    $fattura = icsCompito($this, 'pagamento_fornitore', [], 'Pagare la ditta Rossi');
    icsRispondi($this, $fattura, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-25'])->assertSessionHasNoErrors();
    $condomino = \App\Models\User::factory()->create(['email_verified_at' => now()]);
    $condomino->assignRole(\App\Enums\Role::UTENTE->value);
    $anagrafica = \App\Models\Anagrafica::create(['user_id' => $condomino->id, 'nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 1']);
    $anagrafica->condomini()->attach($this->condominio->id);
    $servizio = app(\App\Services\RecurrenceService::class);
    $this->actingAs($condomino->fresh());

    expect($servizio->getEventsInNextDays(60, [], null, null, $anagrafica, collect([$this->condominio->id]))->pluck('title')->all())->toBe([]);
});
