<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le chiusure da sole di oggi restano dopo una risposta: il programma chiude un compito quando la cosa è fatta (l'incasso registrato dal bonifico segnalato,
 * la rata pagata per intero, gli allegati approvati, le quote non assegnate coperte dagli addebiti), e a volte lo riapre (l'F24 ricalcolato, l'addebito
 * annullato). Una risposta non le ferma: un compito che il programma riapre
 * torna nell'Inbox, e la risposta di prima resta nel suo registro.
 */

use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;
use App\Models\Segnalazione;
use App\Services\Allegati\AllegatoEventoService;
use App\Services\Gestionale\InboxService;
use App\Services\Gestionale\RispostePromemoria;
use Illuminate\Support\Carbon;

require_once __DIR__.'/InboxCompitiScenario.php';
require_once __DIR__.'/../Gestionale/LetturaDebitiProva.php';
require_once __DIR__.'/../Gestionale/AddebitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
    $this->user = $this->admin;
});

function icdRisposta(Evento $e): ?RispostaPromemoria
{
    return RispostaPromemoria::where('chiave', RispostePromemoria::chiaveDelCompito((int) $e->id))->first();
}

it('dopo «Ricordamelo» l\'incasso registrato dal bonifico segnalato chiude il suo compito, e la rata pagata chiude la verifica degli incassi', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1], '2026-01-02');
    $this->travelTo(Carbon::parse('2026-01-10 10:00', 'Europe/Rome')->utc());
    $rata = lcdRata($s, 1);
    $verifica = InboxService::createTask(tipo: EventoTipo::VERIFICA_PAGAMENTO, title: 'Verifica pagamento di Ugo', description: 'Ugo ha segnalato un bonifico',
        scadenza: now(), createdByUserId: $this->admin->id, condominioId: $s['c']->id);
    $incassi = InboxService::createTask(tipo: EventoTipo::CONTROLLO_INCASSI, title: 'Verifica incassi - Rata 1', description: 'Rata 1', scadenza: now()->subDay(),
        createdByUserId: $this->admin->id, condominioId: $s['c']->id, context: ['rata_id' => $rata]);
    foreach ([$verifica, $incassi] as $e) {
        icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-01-20'])->assertSessionHasNoErrors();
    }

    $quota = lcdQuota($s, 'ugo', 1);
    $importo = (int) \Illuminate\Support\Facades\DB::table('rate_quote')->where('id', $quota)->value('importo');
    $this->actingAs($this->admin)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['ugo']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-01-10', 'importo_totale' => number_format($importo / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'related_task_id' => $verifica->id,
        'dettaglio_pagamenti' => [['rata_id' => $quota, 'importo' => number_format($importo / 100, 2, '.', '')]],
    ])->assertSessionHasNoErrors();

    expect($verifica->fresh()->is_completed)->toBeTrue()
        ->and($incassi->fresh()->is_completed)->toBeTrue()
        ->and(icdRisposta($verifica)->stato)->toBe('ricordamelo')
        ->and(icdRisposta($incassi)->registro)->toHaveCount(1);
});

it('dopo «Ricordamelo» gli allegati approvati chiudono il loro compito', function () {
    $segnalazione = Segnalazione::factory()->create(['condominio_id' => $this->condominio->id, 'stato' => 'aperta']);
    $e = InboxService::createTask(tipo: EventoTipo::ALLEGATO, title: 'Allegati da approvare — Ascensore', description: 'In attesa', scadenza: now()->subDay(),
        createdByUserId: $this->admin->id, condominioId: $this->condominio->id, eventableType: $segnalazione->getMorphClass(), eventableId: $segnalazione->getKey());
    icsRispondi($this, $e, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-01'])->assertSessionHasNoErrors();

    app(AllegatoEventoService::class)->chiudiPerElemento($segnalazione);

    expect($e->fresh()->is_completed)->toBeTrue()->and(icdRisposta($e))->not->toBeNull();
});

it('un compito chiuso con «Fatto» e riaperto dal programma (l\'F24 ricalcolato) torna nell\'Inbox, e la risposta di prima resta nel registro', function () {
    $f24 = icsCompito($this, 'versamento_ritenuta', ['context' => ['pagamento_id' => 5]], 'F24 Ritenuta — Ditta Rossi');
    icsRispondi($this, $f24, ['tipo' => 'fatto', 'nota' => 'versato con l\'home banking'])->assertSessionHasNoErrors();
    expect(icsCasella($this))->toBe([]);

    // Come il secondo `updateOrCreate` di `SyncF24WithPagamento` al ricalcolo: il compito è di nuovo da fare.
    Evento::where('meta->type', 'versamento_ritenuta')->where('meta->context->pagamento_id', 5)->update(['is_completed' => false]);

    expect(icsCasella($this))->toBe(['F24 Ritenuta — Ditta Rossi'])
        ->and(icdRisposta($f24)->stato)->toBe('fatto')
        ->and(collect(icdRisposta($f24)->registro)->pluck('nota')->all())->toBe(['versato con l\'home banking']);
});

it('dopo «Ricordamelo» gli addebiti che coprono le quote non assegnate chiudono il loro compito, e l\'annullamento lo riapre', function () {
    $s = adbVerdiFinoANovembre($this);
    $quote = adbQuoteNonAssegnate($s);
    $compito = fn () => Evento::where('tipo', EventoTipo::SCOPERTO_DOCUMENTATO->value)->where('meta->context->piano_rate_id', $s['piano']->id)->firstOrFail();
    $this->travelTo('2026-12-20 10:00:00');
    icsRispondi($this, $compito(), ['tipo' => 'ricordamelo', 'giorno' => '2027-01-10'])->assertSessionHasNoErrors();

    adbRegistra($this, $s, [['chi' => 'Verdi', 'unita' => 'U3', 'euro' => '50,96', 'chiave' => $quote['Pulizie']['chiave'], 'piano_origine_id' => $s['piano']->id]])->assertCreated();
    $rata = adbRegistra($this, $s, [['chi' => 'Verdi', 'unita' => 'U3', 'euro' => '16,99', 'chiave' => $quote['Manutenzioni']['chiave'], 'piano_origine_id' => $s['piano']->id]])
        ->assertCreated()->json('rate.0');
    expect($compito()->is_completed)->toBeTrue()->and(icdRisposta($compito())->stato)->toBe('ricordamelo');

    $this->actingAs($this->admin)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $rata]))->assertOk();
    expect($compito()->is_completed)->toBeFalse()->and($compito()->meta['requires_action'])->toBeTrue();
});

it('l\'incasso con il compito collegato chiude solo un bonifico segnalato: una segnalazione o un altro compito restano aperti', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1, 2, 3], '2026-01-02');
    $this->travelTo(Carbon::parse('2026-01-10 10:00', 'Europe/Rome')->utc());
    $segnalazione = Segnalazione::create(['subject' => 'Cancello rotto', 'description' => 'Non si chiude.', 'created_by' => $this->admin->id, 'condominio_id' => $s['c']->id,
        'priority' => 'media', 'stato' => 'aperta', 'is_published' => true, 'is_approved' => true, 'is_private' => false, 'can_comment' => true]);
    // Un compito per rata: l'incasso della rata porta il compito collegato.
    $compiti = [
        1 => Evento::where('tipo', EventoTipo::SEGNALAZIONE_GUASTO->value)->where('eventable_id', $segnalazione->id)->sole(),
        2 => InboxService::createTask(tipo: EventoTipo::SCADENZA, title: 'Rinnovo assicurazione', description: 'Polizza', scadenza: now(),
            createdByUserId: $this->admin->id, condominioId: $s['c']->id),
        3 => InboxService::createTask(tipo: EventoTipo::VERIFICA_PAGAMENTO, title: 'Verifica pagamento di Ugo', description: 'Ugo ha segnalato un bonifico',
            scadenza: now(), createdByUserId: $this->admin->id, condominioId: $s['c']->id),
    ];

    foreach ($compiti as $rata => $compito) {
        $quota = lcdQuota($s, 'ugo', $rata);
        $euro = number_format((int) \Illuminate\Support\Facades\DB::table('rate_quote')->where('id', $quota)->value('importo') / 100, 2, '.', '');
        $this->actingAs($this->admin)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
            'pagante_id' => $s['persone']['ugo']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-01-10', 'importo_totale' => $euro,
            'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'related_task_id' => $compito->id,
            'dettaglio_pagamenti' => [['rata_id' => $quota, 'importo' => $euro]],
        ])->assertSessionHasNoErrors();
    }

    expect($compiti[1]->fresh()->is_completed)->toBeFalse()
        ->and($compiti[2]->fresh()->is_completed)->toBeFalse()
        ->and($compiti[3]->fresh()->is_completed)->toBeTrue();
});
