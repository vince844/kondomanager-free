<?php

/**
 * «Togli anche il sollecito in tesoreria» nella dispensa dei sei mesi: una colonna della dispensa (`togli_sollecito_tesoreria`, falsa di serie), scelta nel
 * modulo «Registra una dispensa» e cambiata dopo dall'elenco (`dispense.sollecito`). La dispensa toglie sempre i passi verso il legale; con la casella, in
 * tesoreria, anche il sollecito.
 */

use App\Models\Gestionale\DispensaSeiMesi;
use App\Services\Gestionale\Debiti\PassoProposto;
use App\Services\Gestionale\Debiti\SeiMesi;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

it('la colonna è falsa di serie; il modulo la scrive', function () {
    $s = smaViaVerdi($this);
    $base = ['data_delibera' => '2026-03-15', 'numero_verbale' => '2', 'esercizi' => [$s['e2025']->id], 'per_tutti' => '1'];
    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.store', $s['c']), $base)->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.store', $s['c']), $base + ['togli_sollecito_tesoreria' => '1'])->assertSessionHasNoErrors();

    expect(DispensaSeiMesi::orderBy('id')->pluck('togli_sollecito_tesoreria')->all())->toBe([false, true])
        ->and(DB::table('dispense_sei_mesi')->insertGetId(['condominio_id' => $s['c']->id, 'data_delibera' => '2026-03-01', 'tutte_le_persone' => true,
            'created_at' => now(), 'updated_at' => now()]))->toBeInt()
        ->and((bool) DB::table('dispense_sei_mesi')->orderByDesc('id')->value('togli_sollecito_tesoreria'))->toBeFalse();
});

it('l\'elenco dice la scelta, e la rotta la cambia con il suo messaggio; la dispensa di un altro condominio è 404', function () {
    $s = smaViaVerdi($this);
    $altro = smaViaVerdi($this);
    $id = smaDispensa($s, [$s['e2025']]);
    $suo = smaDispensa($altro, [$altro['e2025']]);

    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.sollecito', [$s['c'], $id]), ['togli' => true])
        ->assertSessionHasNoErrors()->assertSessionHas('message.message', 'Fatto: la dispensa toglie anche il sollecito in tesoreria.');
    expect((bool) DB::table('dispense_sei_mesi')->where('id', $id)->value('togli_sollecito_tesoreria'))->toBeTrue();
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.dispense.index', $s['c']))->viewData('page')['props'];
    expect($pagina['dispense'][0]['togli_sollecito_tesoreria'])->toBeTrue();

    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.sollecito', [$s['c'], $id]), ['togli' => false])
        ->assertSessionHas('message.message', 'Fatto: con questa dispensa la tesoreria propone di nuovo il sollecito.');
    expect((bool) DB::table('dispense_sei_mesi')->where('id', $id)->value('togli_sollecito_tesoreria'))->toBeFalse();

    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.sollecito', [$s['c'], $suo]), ['togli' => true])->assertNotFound();
    expect((bool) DB::table('dispense_sei_mesi')->where('id', $suo)->value('togli_sollecito_tesoreria'))->toBeFalse();
});

it('`SeiMesi` porta la scelta nella dispensa di ogni data', function () {
    $s = smaViaVerdi($this);
    smaDispensa($s, [$s['e2025'], $s['e']], ['rossi'], true);
    $calcolo = app(SeiMesi::class)->perCondominio((int) $s['c']->id);
    $rata = collect(lcdLeggi($s, 'rossi')['rate'])->firstWhere('numero_rata', 1);

    expect($calcolo->perRiga($rata, (int) $s['persone']['rossi']->id)['piu_vicina_dispensa']['togli_sollecito'])->toBeTrue();
});

it('in tesoreria la dispensa toglie i passi verso il legale e lascia il sollecito; con la casella toglie anche quello', function () {
    $s = smaViaVerdi($this);
    // Rossi e Bianchi hanno la data vicina (30/06/2026): senza la dispensa la regola propone la diffida.
    smaDispensa($s, [$s['e2025'], $s['e']], ['rossi']);
    smaDispensa($s, [$s['e2025'], $s['e']], ['bianchi'], true);
    $passi = app(SeiMesiPerAgire::class)->passi($s['c'], PassoProposto::TESORERIA);

    expect($passi[$s['persone']['rossi']->id]['passo'])->toBeNull()
        ->and($passi[$s['persone']['rossi']->id]['frase'])->toBe('Dispensa dell\'assemblea del 15/03/2026: niente diffida né fascicolo.')
        ->and($passi[$s['persone']['bianchi']->id]['frase'])->toBe('Dispensa dell\'assemblea del 15/03/2026: niente solleciti, come hai scelto.')
        ->and($passi[$s['persone']['verdi']->id]['passo'])->toBe(PassoProposto::DIFFIDA);
});
