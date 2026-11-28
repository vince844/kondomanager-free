<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'emissione di un condominio grande: le stesse scritture, con meno istruzioni al database.
 *
 * L'emissione scriveva il legame fra la quota e la sua scrittura con un aggiornamento per quota (36.000 per 3.000 unità e 12 rate) e cercava
 * i promemoria dei condòmini nel JSON degli eventi, senza indice, una volta per rata. Ora il legame si scrive con un aggiornamento per rata, e i
 * promemoria si leggono una volta per emissione; il compito dello studio si toglie con una cancellazione sola.
 *
 * Il condominio della prova ha 50 unità, ognuna con il suo condòmino, e il «Preventivo 2026» di € 6.000,00 in 12 rate: € 10,00 a unità per
 * rata. Si emettono le rate 1, 2 e 3. Le prove: (1) le scritture sono quelle di sempre (una per rata, 50 righe a € 10,00 in DARE e il totale
 * € 500,00 in AVERE, ogni quota legata alla scrittura della sua rata, i promemoria segnati emessi e visibili, il compito dello studio tolto);
 * (2) le istruzioni al database: un aggiornamento delle quote per rata e una lettura dei promemoria per emissione, non una per quota né per rata.
 */

use App\Enums\EventoTipo;
use App\Models\Anagrafica;
use App\Models\Immobile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59EstrattoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

/** Lo scenario di `b59eScenario` con 49 unità in più, ognuna con il suo condòmino, scritte prima del piano: 50 unità a 20 millesimi. */
function ecgScenario($test): array
{
    $s = b59eScenario($test);
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->delete();
    DB::table('rate')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('quote_tabella')->where('tabella_id', $s['tabella']->id)->update(['valore' => 20]);
    for ($i = 2; $i <= 50; $i++) {
        $unita = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => "ECG-{$s['seq']}-{$i}", 'nome' => "Interno {$i}", 'interno' => (string) $i]);
        DB::table('quote_tabella')->insert(['tabella_id' => $s['tabella']->id, 'immobile_id' => $unita->id, 'valore' => 20, 'created_at' => now(), 'updated_at' => now()]);
        $persona = Anagrafica::forceCreate(['nome' => "Condòmino {$i}", 'email' => "ecg{$s['seq']}-{$i}@test.it", 'indirizzo' => 'Via dei Mille 1',
            'codice_fiscale' => 'ECGCND70A01H' . str_pad((string) $i, 4, '0', STR_PAD_LEFT)]);
        $persona->condomini()->syncWithoutDetaching([$s['c']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persona->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100,
            'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $test->actingAs($s['admin'])->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => false])->assertSessionHasNoErrors();
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano']->fresh(), esercizio: $s['e']);
    $test->actingAs($s['admin'])->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])
        ->assertSessionHasNoErrors();
    expect(DB::table('rate_quote')->where('rata_id', b59eRata($s, 1))->count())->toBe(50)
        ->and(DB::table('rate_quote')->where('rata_id', b59eRata($s, 1))->distinct()->pluck('importo')->all())->toBe([1000]);

    return $s;
}

/** I promemoria dei condòmini di una rata, letti in PHP: [emessa, pubblicata, visibilità]. */
function ecgPromemoria(int $rataId): array
{
    return DB::table('eventi')->where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)->get()
        ->filter(fn ($e) => (int) (json_decode($e->meta, true)['context']['rata_id'] ?? 0) === $rataId)
        ->map(fn ($e) => [json_decode($e->meta, true)['is_emitted'] ?? null, json_decode($e->meta, true)['is_published'] ?? null, $e->visibility])
        ->values()->all();
}

it('emesse tre rate di un condominio di 50 unità, le scritture sono quelle di sempre: una per rata, 50 righe da € 10,00 e il totale da € 500,00, ogni quota legata alla sua', function () {
    $s = ecgScenario($this);
    $promemoriaPrima = count(ecgPromemoria(b59eRata($s, 1)));
    expect($promemoriaPrima)->toBe(50);

    $this->actingAs($s['admin'])->from('/piano')->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => [b59eRata($s, 1), b59eRata($s, 2), b59eRata($s, 3)], 'data_emissione' => '2026-01-05', 'invia_notifiche' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    foreach ([1, 2, 3] as $n) {
        $rata = b59eRata($s, $n);
        $scritture = DB::table('scritture_contabili')->where('tipo_movimento', 'emissione_rata')->whereIn('id', DB::table('righe_scritture')->where('rata_id', $rata)->pluck('scrittura_id'))->pluck('id');
        expect($scritture)->toHaveCount(1);
        $righe = DB::table('righe_scritture')->where('scrittura_id', $scritture[0])->get();
        expect($righe->where('tipo_riga', 'dare')->count())->toBe(50)
            ->and($righe->where('tipo_riga', 'dare')->pluck('importo')->unique()->values()->all())->toBe([1000])
            ->and($righe->where('tipo_riga', 'avere')->pluck('importo')->all())->toBe([50000])
            ->and(DB::table('rate_quote')->where('rata_id', $rata)->where('scrittura_contabile_id', $scritture[0])->count())->toBe(50)
            ->and(DB::table('rate')->where('id', $rata)->value('stato'))->toBe('emessa');
        expect(collect(ecgPromemoria($rata))->unique()->values()->all())->toBe([[true, true, 'private']]);
    }
    expect(DB::table('rate_quote')->where('rata_id', b59eRata($s, 4))->whereNotNull('scrittura_contabile_id')->count())->toBe(0)
        ->and(DB::table('eventi')->where('tipo', EventoTipo::EMISSIONE_RATA->value)->get()
            ->filter(fn ($e) => in_array((int) (json_decode($e->meta, true)['context']['rata_id'] ?? 0), [b59eRata($s, 1), b59eRata($s, 2), b59eRata($s, 3)], true))->count())->toBe(0);
});

it('emesse tre rate di un condominio di 50 unità, le quote si legano con un aggiornamento per rata e i promemoria si leggono una volta sola', function () {
    $s = ecgScenario($this);
    $istruzioni = [];
    DB::listen(function ($q) use (&$istruzioni) {
        $istruzioni[] = strtolower($q->sql);
    });

    $this->actingAs($s['admin'])->from('/piano')->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => [b59eRata($s, 1), b59eRata($s, 2), b59eRata($s, 3)], 'data_emissione' => '2026-01-05', 'invia_notifiche' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $legami = array_filter($istruzioni, fn ($sql) => str_starts_with($sql, 'update "rate_quote"') && str_contains($sql, '"scrittura_contabile_id"'));
    $letturePromemoria = array_filter($istruzioni, fn ($sql) => str_starts_with($sql, 'select') && str_contains($sql, 'from "eventi"') && str_contains($sql, 'rata_id'));
    expect(count($legami))->toBe(3)
        ->and(count($letturePromemoria))->toBe(1);
});
