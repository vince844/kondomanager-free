<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Dopo «Emetti» dalla finestra «Emetti le rate» la dashboard non dice più una cosa falsa: l'emissione cancella i compiti «Emettere rata N» delle rate emesse
 * (`EmissioneRateController::store`), e il caricamento parziale che la finestra chiede (le prop di `only` in `EmissioneRapidaDialog.vue`, lette dal suo
 * sorgente) riporta anche l'Inbox operativa, senza quei compiti, e i sei mesi. Nessuna riga a video resta senza il suo compito nel database.
 */

use App\Enums\EventoTipo;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';
require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

/** Le prop che la finestra chiede dopo «Emetti»: l'elenco di `only` della richiesta di `emetti()`, quello con la tesoreria. */
function erlPropDellaFinestra(): array
{
    $sorgente = file_get_contents(base_path('resources/js/pages/gestionale/dashboard/components/EmissioneRapidaDialog.vue'));
    preg_match_all("/only: \\[([^\\]]*)\\]/", $sorgente, $m);
    $elenco = collect($m[1])->first(fn ($e) => str_contains($e, "'widgets'"));
    expect($elenco)->not->toBeNull();
    preg_match_all("/'([^']+)'/", (string) $elenco, $nomi);

    return $nomi[1];
}

function erlParziale(array $prop): array
{
    $versione = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());

    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $versione, 'X-Inertia-Partial-Component' => 'gestionale/dashboard/Dashboard',
        'X-Inertia-Partial-Data' => implode(',', $prop)];
}

it('dopo l\'emissione dalla finestra l\'Inbox operativa arriva di nuovo, senza i compiti «Emettere rata» che l\'emissione ha cancellato', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 400], 'B' => ['bianchi', 300], 'C' => ['verdi', 200], 'D' => ['neri', 100]], 12, 'prima_rata', [], 1200000);
    (new \App\Listeners\Gestionale\SyncScadenziarioWithPianoRate())->handle(new \App\Events\Gestionale\PianoRateStatusUpdated($s['c'], $s['e'], $s['piano'], $this->user,
        \App\Enums\StatoPianoRate::BOZZA, \App\Enums\StatoPianoRate::APPROVATO));
    lcdEmetti($this, $s, [1, 2, 3], '2026-01-02');
    $this->travelTo('2026-05-20 10:00:00');
    $dashboard = route('admin.gestionale.index', $s['c']);
    $compiti = fn (int $n) => DB::table('eventi')->where('tipo', EventoTipo::EMISSIONE_RATA->value)->get()
        ->filter(fn ($e) => (int) (json_decode((string) $e->meta, true)['context']['rata_id'] ?? 0) === lcdRata($s, $n));

    // La pagina intera: l'Inbox operativa con i compiti «Emettere rata» delle rate 4, 5, 6.
    $prima = collect($this->actingAs($this->user)->get($dashboard)->assertOk()->viewData('page')['props']['inboxTasks']['data'] ?? []);
    expect($prima->pluck('title')->filter(fn ($t) => str_starts_with((string) $t, 'Emettere rata'))->count())->toBeGreaterThanOrEqual(3)
        ->and($compiti(4))->toHaveCount(1);

    // L'emissione dalla finestra, con il caricamento parziale che la finestra chiede.
    $prop = erlPropDellaFinestra();
    $r = $this->actingAs($this->user)->withHeaders(erlParziale($prop))->from($dashboard)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => [lcdRata($s, 4), lcdRata($s, 5), lcdRata($s, 6)], 'data_emissione' => '2026-05-20', 'descrizione_personalizzata' => '', 'invia_notifiche' => true,
        'da' => 'dashboard',
    ]);
    $dopo = $this->actingAs($this->user)->withHeaders(erlParziale($prop))->get($r->headers->get('Location'))->assertOk()->json('props');

    // Il compito non c'è più nel database, e la pagina lo sa: l'Inbox è arrivata di nuovo, e ogni sua riga ha il suo compito.
    expect($compiti(4))->toBeEmpty()
        ->and($dopo)->toHaveKeys(['widgets', 'flash', 'inboxTasks', 'inboxSchede', 'inboxScaduti', 'seiMesi', 'compitiSeiMesi'])
        ->and($dopo)->not->toHaveKeys(['copertura', 'emissioneRapida']);
    $righe = collect($dopo['inboxTasks']['data'] ?? []);
    expect($righe->filter(fn ($riga) => ! DB::table('eventi')->where('id', $riga['id'])->exists())->pluck('title')->values()->all())->toBe([])
        ->and($righe->pluck('title')->filter(fn ($t) => preg_match('/^Emettere rata [456]\b/', (string) $t))->all())->toBe([]);
});
