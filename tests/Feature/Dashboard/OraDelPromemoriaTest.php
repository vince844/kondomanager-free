<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'ora di «Ricordamelo» e di «Mettilo in agenda» nell'agenda: le 09:00, scritte come le scrive il resto dell'agenda (l'ora così come si legge, come
 * `InboxService` per i compiti delle rate), e mostrate «alle 09:00», d'inverno e d'estate. Per il compito spostato, per il promemoria a parte, per i sei mesi e
 * per il rendiconto.
 */

use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;
use App\Services\Gestionale\RispostePromemoria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

/** L'ora scritta nel database e quella che mostra l'agenda dello studio («20/11/2026 alle 09:00»). */
function odpOre($test, int $id): array
{
    $agenda = collect($test->actingAs($test->admin)->get(route('admin.eventi.index', ['date_from' => '2026-05-01', 'date_to' => '2026-12-31']))
        ->assertOk()->viewData('page')['props']['eventi'])->first(fn ($e) => (int) (is_array($e) ? $e['id'] : $e->id) === $id);
    $mostrata = $agenda === null ? null : (is_array($agenda) ? $agenda['occurs_at'] : $agenda->resolve()['occurs_at']);

    return [(string) DB::table('eventi')->where('id', $id)->value('start_time'), (string) DB::table('eventi')->where('id', $id)->value('end_time'), $mostrata];
}

it('il compito spostato e il promemoria a parte alle 09:00, d\'inverno e d\'estate, nel database e nell\'agenda', function (string $giorno, string $mostrato) {
    $incassi = icsCompito($this, 'controllo_incassi', [], 'Verifica incassi - Rata 1');
    $fattura = icsCompito($this, 'pagamento_fornitore', [], 'Pagare la ditta');
    icsRispondi($this, $incassi, ['tipo' => 'ricordamelo', 'giorno' => $giorno])->assertSessionHasNoErrors();
    icsRispondi($this, $fattura, ['tipo' => 'ricordamelo', 'giorno' => $giorno])->assertSessionHasNoErrors();
    $promemoria = (int) RispostaPromemoria::where('evento_id', $fattura->id)->value('promemoria_evento_id');

    expect(odpOre($this, $incassi->id))->toBe([$giorno . ' 09:00:00', $giorno . ' 10:00:00', $mostrato])
        ->and(odpOre($this, $promemoria))->toBe([$giorno . ' 09:00:00', $giorno . ' 10:00:00', $mostrato]);
})->with([
    'd\'inverno' => ['2026-11-20', '20/11/2026 alle 09:00'],
    'd\'estate' => ['2026-06-15', '15/06/2026 alle 09:00'],
]);

it('i sei mesi e «Mettilo in agenda» del rendiconto alle 09:00, d\'inverno e d\'estate', function (string $giorno, string $mostrato) {
    $e2025 = \App\Models\Esercizio::factory()->create(['condominio_id' => $this->condominio->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01',
        'data_fine' => '2025-12-31', 'stato' => 'aperto']);
    $risposte = app(RispostePromemoria::class);
    $agenda = $risposte->inAgenda($this->condominio, (int) $e2025->id, $giorno, null, $this->admin, 'Convocare l\'assemblea', '/ritorno');
    $promemoria = $risposte->ricordamelo($this->condominio, 'sei_mesi', (int) $e2025->id, $giorno, null, $this->admin, 'Ricordamelo: sei mesi', '/ritorno');

    expect(odpOre($this, (int) $agenda->agenda_evento_id))->toBe([$giorno . ' 09:00:00', $giorno . ' 10:00:00', $mostrato])
        ->and(odpOre($this, (int) $promemoria->promemoria_evento_id))->toBe([$giorno . ' 09:00:00', $giorno . ' 10:00:00', $mostrato])
        ->and(RispostePromemoria::giornoDellEvento(Evento::find($promemoria->promemoria_evento_id)))->toBe($giorno);
})->with([
    'd\'inverno' => ['2026-11-20', '20/11/2026 alle 09:00'],
    'd\'estate' => ['2026-06-15', '15/06/2026 alle 09:00'],
]);

it('il compito torna il giorno scelto, dalla mezzanotte di Roma: il 19 alle 23:30 no, il 20 alle 00:30 sì', function () {
    $incassi = icsCompito($this, 'controllo_incassi', [], 'Verifica incassi - Rata 1');
    icsRispondi($this, $incassi, ['tipo' => 'ricordamelo', 'giorno' => '2026-11-20'])->assertSessionHasNoErrors();

    $this->travelTo(Carbon::parse('2026-11-19 23:30', 'Europe/Rome')->utc());
    expect(icsCasella($this))->toBe([]);
    $this->travelTo(Carbon::parse('2026-11-20 00:30', 'Europe/Rome')->utc());
    expect(icsCasella($this))->toBe(['Verifica incassi - Rata 1']);
});
