<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il tetto sulle voci vale dopo ogni scrittura che cambia che cosa chiede un piano o di quale esercizio è (`TettoSulleVoci::primoSforo`): la somma che i
 * piani attivi di un esercizio chiedono su una voce non supera il maggiore fra il suo preventivo e il suo speso. Le strade: la creazione
 * (`PianoRateController::store`), «Ricalcola» con le voci da aggiungere (`SyncOrphanChaptersAction`) e «Esercizio · Cambia»
 * (`PianoRateController::updateEsercizio`). «Sposta spesa» resta fuori: sposta quanto il piano finanzia, e quello che sposta non ferma le altre strade.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../Beta51PianoRateScenario.php';
require_once __DIR__ . '/../Beta52TotaliScenari.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

function tdsPiano($t, array $r, string $nome, array $voci)
{
    session()->forget(['message', 'errors']);

    return $t->actingAs($r['s']->admin)->from('/piani-rate/nuovo')->post(route('admin.gestionale.esercizi.piani-rate.store', [$r['s']->c, $r['s']->e]), array_merge([
        'tipo' => 'ordinario', 'gestione_id' => $r['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2026-11-30', 'genera_subito' => 1, 'capitoli_ids' => [],
    ], $voci));
}

function tdsRicalcola($t, array $r, PianoRate $piano, array $orfani = [])
{
    session()->forget(['message', 'errors']);

    return $t->actingAs($r['s']->admin)->from('/p')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$r['s']->c, $r['s']->e, $piano]), array_merge(
        $orfani === [] ? [] : ['orphan_ids' => $orfani],
        ['accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova']));
}

function tdsChiesto(array $r): int
{
    return (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->join('piani_rate', 'piani_rate.id', '=', 'rate.piano_rate_id')
        ->where('piani_rate.gestione_id', $r['g']->id)->sum('rate_quote.importo');
}

/** Il capitolo «Manutenzione» (Ascensore € 600,00, Caldaia € 400,00) e il Giardino € 200,00, su A di Ugo e B di Bice (600/400). */
function tdsCapitolo($t, int $importoDelCapitolo = 0, int $ascensore = 60000, int $caldaia = 40000): array
{
    $t->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Manutenzione', $importoDelCapitolo, $importoDelCapitolo === 0 ? [] : ['Proprietà' => 100])
        ->voce('Ordinaria 2026', 'Ascensore', $ascensore, ['Proprietà' => 100])
        ->voce('Ordinaria 2026', 'Caldaia', $caldaia, ['Proprietà' => 100])
        ->voce('Ordinaria 2026', 'Giardino', 20000, ['Proprietà' => 100]);
    app(CondominioService::class)->ensureDefaultConti($s->c);
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $capitolo = $s->voci['Manutenzione']['conto'];
    DB::table('conti')->whereIn('id', [$s->voci['Ascensore']['conto']->id, $s->voci['Caldaia']['conto']->id])->update(['parent_id' => $capitolo->id]);

    return ['s' => $s, 'g' => $s->gestioni['Ordinaria 2026']['g'], 'capitolo' => $capitolo,
        'ascensore' => $s->voci['Ascensore']['conto'], 'caldaia' => $s->voci['Caldaia']['conto'], 'giardino' => $s->voci['Giardino']['conto']];
}

/** Il «Preventivo 2026» (Spese generali € 1.200,00, antenna − € 240,00), la gestione anche nel 2027 e, se chiesto, il «Preventivo 2027» con le stesse voci. */
function tdsDueEsercizi($t, bool $conIlPreventivo2027 = true): array
{
    $t->user = b52tAdmin();
    b52tSqliteJson();
    $s = b52tScenario($t);
    $s['piano'] = b52tPiano($t, $s);
    $s['e27'] = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    app(CondominioService::class)->createDefaultGestione($s['c'], $s['e27']);
    if ($conIlPreventivo2027) {
        $t->actingAs($t->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e27']]), [
            'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2027', 'metodo_distribuzione' => 'prima_rata',
            'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2027-01-05', 'genera_subito' => true,
            'capitoli_ids' => [], 'capitoli_config' => [['id' => $s['spesa']->id, 'importo' => ''], ['id' => $s['entrata']->id, 'importo' => '']], 'competenze_capitoli' => [],
            'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
        ])->assertSessionHasNoErrors();
        $s['p27'] = PianoRate::where('nome', 'Preventivo 2027')->sole();
    }

    return $s;
}

function tdsCambia($t, array $s, PianoRate $piano, Esercizio $verso)
{
    session()->forget(['message', 'errors']);

    return $t->actingAs($t->user)->from('/pagina-del-piano')->put(route('admin.gestionale.esercizi.piani-rate.update-esercizio', [$s['c'], $s['e'], $piano]),
        ['esercizio_id' => $verso->id]);
}

it('«Ricalcola» con il capitolo fra le voci da aggiungere non chiede di nuovo la sottovoce che un altro piano chiede per intero, e non scrive niente', function () {
    $r = tdsCapitolo($this);
    tdsPiano($this, $r, 'Solo ascensore', ['capitoli_config' => [['id' => $r['ascensore']->id, 'importo' => '']]])->assertSessionHas('success');
    tdsPiano($this, $r, 'Giardino', ['capitoli_config' => [['id' => $r['giardino']->id, 'importo' => '']]])->assertSessionHas('success');
    expect(tdsChiesto($r))->toBe(80000);
    $giardino = PianoRate::where('nome', 'Giardino')->sole();
    $quotePrima = b52tSommeQuote($giardino);

    tdsRicalcola($this, $r, $giardino, [$r['capitolo']->id])->assertRedirect();

    // A mano: l'Ascensore è chiesto € 600,00 da «Solo ascensore»; il capitolo da € 1.000,00 gliene passerebbe € 600,00 di nuovo.
    expect(session('message')['type'] ?? null)->toBe('error')
        ->and(session('message')['message'])->toBe('La voce «Ascensore» vale € 600,00 ed è già chiesta dal piano «Solo ascensore» (€ 600,00): restano € 0,00 da chiedere, e il capitolo '
            . '«Manutenzione», aggiunto con il ricalcolo, gliene passerebbe € 600,00. Il ricalcolo non ha cambiato il piano: per le altre sottovoci del capitolo crea un piano '
            . 'che le sceglie una per una, ognuna con al massimo quello che resta.')
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $giardino->id)->pluck('conto_id')->map(fn ($id) => (int) $id)->all())->toBe([(int) $r['giardino']->id])
        ->and(b52tSommeQuote($giardino->fresh()))->toBe($quotePrima)
        ->and(tdsChiesto($r))->toBe(80000);
});

it('controprova: «Ricalcola» con il capitolo libero lo aggiunge, come prima', function () {
    $r = tdsCapitolo($this);
    tdsPiano($this, $r, 'Giardino', ['capitoli_config' => [['id' => $r['giardino']->id, 'importo' => '']]])->assertSessionHas('success');
    $giardino = PianoRate::where('nome', 'Giardino')->sole();

    tdsRicalcola($this, $r, $giardino, [$r['capitolo']->id])->assertRedirect();

    expect(session('message')['message'] ?? null)->toStartWith('Sincronizzazione completata: inclusi 1 nuovi capitoli di spesa.')
        ->and(tdsChiesto($r))->toBe(120000);
});

it('«Esercizio · Cambia» non porta nel 2027 un piano su voci che il «Preventivo 2027» chiede già per intero: la frase dice il piano e quanto resta', function () {
    $s = tdsDueEsercizi($this);
    $quote27 = b52tSommeQuote($s['p27']);

    tdsCambia($this, $s, $s['piano'], $s['e27'])->assertRedirect('/pagina-del-piano');

    expect((int) $s['piano']->fresh()->esercizio_id)->toBe((int) $s['e']->id)
        ->and(session('message')['type'] ?? null)->toBe('error')
        ->and(session('message')['message'])->toBe('Il piano «Preventivo 2026» non passa all\'esercizio «Esercizio 2027»: la voce «Spese generali» vale € 1.200,00 ed è già chiesta '
            . 'dal piano «Preventivo 2027» (€ 1.200,00), restano € 0,00 da chiedere e questo piano ne chiede € 1.200,00. Lascialo nel suo esercizio, o togli la voce da uno dei due piani prima di spostarlo.')
        ->and(b52tSommeQuote($s['p27']->fresh()))->toBe($quote27)
        // Il gesto non resta nel registro delle modifiche: la transazione l'ha tolto con lo spostamento.
        ->and(DB::table('registro_modifiche')->where('riepilogo', 'like', '%spostato dall\'esercizio%')->count())->toBe(0);
});

it('il menu di «Cambia» dice perché un esercizio non si può scegliere', function () {
    $s = tdsDueEsercizi($this);

    $riga = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']]))->assertOk()
        ->viewData('page')['props']['cambio_esercizio'];

    expect($riga['scelte'])->toBe([['id' => $s['e27']->id, 'nome' => 'Esercizio 2027',
        'perche' => 'la voce «Spese generali» vale € 1.200,00 ed è già chiesta dal piano «Preventivo 2027» (€ 1.200,00), restano € 0,00 da chiedere e questo piano ne chiede € 1.200,00.']]);
});

it('controprova: senza il «Preventivo 2027» il piano passa al 2027, e la frase dice che le scadenze restano quelle del piano', function () {
    $s = tdsDueEsercizi($this, false);

    tdsCambia($this, $s, $s['piano'], $s['e27'])->assertRedirect();

    expect((int) $s['piano']->fresh()->esercizio_id)->toBe((int) $s['e27']->id)
        ->and(session('message')['message'])->toBe('Il piano «Preventivo 2026» ora è dell\'esercizio «Esercizio 2027». Le rate sono state calcolate con l\'esercizio di prima: '
            . 'ricalcola il piano, così toglie il già versato di questo esercizio. Le scadenze delle rate restano quelle del piano: se servono altre date, rifallo.');
});

it('il capitolo con un importo suo: la sua parte non si chiede due volte', function () {
    // Manutenzione € 300,00 suoi sulla tabella, l'Ascensore e la Caldaia sotto a € 0: chiedere il capitolo vuol dire chiedere i suoi € 300,00.
    $r = tdsCapitolo($this, 30000, 0, 0);
    tdsPiano($this, $r, 'Primo', ['capitoli_config' => [['id' => $r['capitolo']->id, 'importo' => '']]])->assertSessionHas('success');
    $chiesto = tdsChiesto($r);

    tdsPiano($this, $r, 'Secondo', ['capitoli_config' => [['id' => $r['capitolo']->id, 'importo' => '']]]);

    expect(PianoRate::where('nome', 'Secondo')->exists())->toBeFalse()
        ->and(session('message')['message'] ?? null)->toStartWith('La voce «Manutenzione» vale € 300,00 ed è già chiesta dal piano «Primo» (€ 300,00): restano € 0,00 da chiedere.')
        ->and(tdsChiesto($r))->toBe($chiesto);
});

it('dopo «Sposta spesa» il ricalcolo con una voce in più e il cambio di esercizio non si fermano per lo spostamento', function () {
    // Pulizie € 1.230,00 tutta in «Acconto», Giardino € 200,00 in «Integrativo»; in «Integrativo» € 100,00 passano da Giardino a Pulizie.
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Pulizie', 123000, ['Proprietà' => 100])
        ->voce('Ordinaria 2026', 'Giardino', 20000, ['Proprietà' => 100])
        ->voce('Ordinaria 2026', 'Antenna', 5000, ['Proprietà' => 100])
        ->voce('Ordinaria 2026', 'Citofono', 3000, ['Proprietà' => 100]);
    app(CondominioService::class)->ensureDefaultConti($s->c);
    $s->cassa();
    \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $r = ['s' => $s, 'g' => $s->gestioni['Ordinaria 2026']['g']];
    $pul = $s->voci['Pulizie']['conto'];
    $gia = $s->voci['Giardino']['conto'];
    tdsPiano($this, $r, 'Acconto', ['capitoli_config' => [['id' => $pul->id, 'importo' => '']]])->assertSessionHas('success');
    tdsPiano($this, $r, 'Integrativo', ['capitoli_config' => [['id' => $gia->id, 'importo' => '']]])->assertSessionHas('success');
    $acconto = PianoRate::where('nome', 'Acconto')->sole();
    $int = PianoRate::where('nome', 'Integrativo')->sole();
    $this->actingAs($s->admin)->from('/p')->post(route('admin.gestionale.piani-rate.move-budget', [$s->c, $int]),
        ['source_id' => $gia->id, 'destination_id' => $pul->id, 'amount' => '100.00', 'reason' => 'Le pulizie costano di più'])->assertSessionHasNoErrors();

    tdsRicalcola($this, $r, $int->fresh(), [$s->voci['Antenna']['conto']->id])->assertRedirect();
    expect(session('message')['message'] ?? null)->toStartWith('Sincronizzazione completata: inclusi 1 nuovi capitoli di spesa.');
    tdsRicalcola($this, $r, $acconto->fresh(), [$s->voci['Citofono']['conto']->id])->assertRedirect();
    expect(session('message')['message'] ?? null)->toStartWith('Sincronizzazione completata: inclusi 1 nuovi capitoli di spesa.')
        // A mano: Pulizie 1.230 + 100 spostati, Giardino 100, Antenna 50, Citofono 30.
        ->and(tdsChiesto($r))->toBe(151000);

    // Il cambio di esercizio: «Integrativo» passa al 2027, dove un «Acconto 2027» chiede Pulizie per intero; i € 100,00 spostati non lo fermano.
    $e27 = Esercizio::factory()->create(['condominio_id' => $s->c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    DB::table('esercizio_gestione')->insert(['esercizio_id' => $e27->id, 'gestione_id' => $r['g']->id, 'attiva' => true, 'created_at' => now(), 'updated_at' => now()]);
    session()->forget(['message', 'errors']);
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $e27]), [
        'tipo' => 'ordinario', 'gestione_id' => $r['g']->id, 'nome' => 'Acconto 2027', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 2,
        'giorno_scadenza' => 30, 'data_prima_scadenza' => '2027-01-30', 'genera_subito' => 1, 'capitoli_ids' => [], 'capitoli_config' => [['id' => $pul->id, 'importo' => '']],
    ])->assertSessionHas('success');
    session()->forget(['message', 'errors']);
    $this->actingAs($s->admin)->from('/p')->put(route('admin.gestionale.esercizi.piani-rate.update-esercizio', [$s->c, $s->e, $int]), ['esercizio_id' => $e27->id])
        ->assertRedirect();

    expect(session('message')['type'] ?? null)->toBe('success')
        ->and((int) $int->fresh()->esercizio_id)->toBe((int) $e27->id);
});
