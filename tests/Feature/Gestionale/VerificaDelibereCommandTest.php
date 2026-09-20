<?php

/**
 * B2 (1.11.0-beta.31), S6 — `kondomanager:verifica-delibere`, il comando che elenca i piani su gestioni
 * straordinarie che la beta.31 fermerà o leggerà male: delibera assente, delibera uguale al giorno del
 * clic di approvazione (il default del vecchio modal), urgenza con fatture senza competenza. Fa fede
 * `gestioni.tipo` (decisione 11), non `piani_rate.tipo`. Sola lettura, `SUCCESS` sempre.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\PianoRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function vdCondominio(): array
{
    $c = Condominio::factory()->create(['nome' => 'Condominio Delibere']);
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'stato' => 'aperto', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);

    return [$c, $e];
}

function vdGestione(Condominio $c, Esercizio $e, string $tipo, string $nome = 'Facciata'): Gestione
{
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'tipo' => $tipo, 'nome' => $nome, 'saldo_applicato' => 0, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $g->esercizi()->attach($e->id);

    return $g;
}

function vdPiano(Gestione $g, array $extra = []): PianoRate
{
    return PianoRate::create(array_merge([
        'gestione_id' => $g->id, 'condominio_id' => $g->condominio_id, 'nome' => 'Piano '.uniqid(), 'stato' => 'approvato',
        'tipo' => 'straordinario', 'numero_rate' => 1, 'tipo_autorizzazione' => 'delibera',
    ], $extra));
}

function vdFattura(Condominio $c, Esercizio $e, PianoRate $p, string $numero, ?string $dal = null, ?string $al = null): int
{
    static $n = 0;
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Fornitore '.(++$n), 'soggetto_ritenuta' => false, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $id = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'fornitore_id' => $fornitoreId, 'tipo_documento' => 'fattura',
        'numero_documento' => $numero, 'data_documento' => '2026-03-01', 'data_scadenza' => '2026-04-01',
        'importo_imponibile' => 100000, 'importo_iva' => 0, 'netto_a_pagare' => 100000, 'totale_documento' => 100000,
        'stato_approvazione' => 'approvata', 'stato_pagamento' => 'aperta',
        'competenza_dal' => $dal, 'competenza_al' => $al, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('piano_rate_fatture')->insert(['piano_rate_id' => $p->id, 'fattura_passiva_id' => $id, 'importo_collegato' => 100000, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function vdOutput(Condominio $c): string
{
    expect(Artisan::call('kondomanager:verifica-delibere', ['--condominio' => $c->id]))->toBe(0);

    return Artisan::output();
}

it('tace quando ogni piano straordinario ha la sua delibera, in un giorno diverso dal clic', function () {
    [$c, $e] = vdCondominio();
    $g = vdGestione($c, $e, 'straordinaria');
    vdPiano($g, ['data_delibera_assemblea' => '2026-02-12', 'approvato_il' => '2026-02-20 10:00:00']);

    expect(vdOutput($c))->toContain('Nessun segnale')->not->toContain('Delibera assente');
});

it('segnala la delibera assente su una gestione straordinaria, anche se il piano si dice «ordinario» (decisione 11)', function () {
    [$c, $e] = vdCondominio();
    $g = vdGestione($c, $e, 'straordinaria');
    vdPiano($g, ['nome' => 'Rifacimento tetto', 'tipo' => 'ordinario', 'tipo_autorizzazione' => null, 'data_delibera_assemblea' => null]);

    $out = vdOutput($c);
    expect($out)->toContain('Delibera assente (1)')->toContain('Rifacimento tetto')->toContain('1 segnale in totale');
});

it('non segnala un piano «straordinario» su una gestione ORDINARIA: lì si va pro rata e la data non conta', function () {
    [$c, $e] = vdCondominio();
    $g = vdGestione($c, $e, 'ordinaria');
    vdPiano($g, ['nome' => 'Spesa imprevista', 'tipo' => 'straordinario', 'data_delibera_assemblea' => null]);

    expect(vdOutput($c))->toContain('Nessun segnale')->not->toContain('Spesa imprevista');
});

it('segnala come sospetta la delibera che coincide col giorno dell\'approvazione, non quella di un altro giorno', function () {
    [$c, $e] = vdCondominio();
    $g = vdGestione($c, $e, 'straordinaria');
    vdPiano($g, ['nome' => 'Del giorno del clic', 'data_delibera_assemblea' => '2026-03-15', 'approvato_il' => '2026-03-15 16:42:00']);
    vdPiano($g, ['nome' => 'Dal verbale', 'data_delibera_assemblea' => '2026-03-01', 'approvato_il' => '2026-03-15 16:45:00']);
    // Bozza mai approvata con la data scritta: `approvato_il` nullo non è «uguale» a niente.
    vdPiano($g, ['nome' => 'In bozza', 'stato' => 'bozza', 'data_delibera_assemblea' => '2026-03-15', 'approvato_il' => null]);

    $out = vdOutput($c);
    expect($out)->toContain("Delibera uguale al giorno dell'approvazione (1)")->toContain('Del giorno del clic')
        ->not->toContain('Dal verbale')->not->toContain('In bozza');
});

it('con «urgenza» non chiede la delibera ma segnala le fatture collegate senza competenza, per numero', function () {
    [$c, $e] = vdCondominio();
    $g = vdGestione($c, $e, 'straordinaria');
    $p = vdPiano($g, ['nome' => 'Caldaia rotta', 'tipo_autorizzazione' => 'urgenza', 'data_delibera_assemblea' => null]);
    vdFattura($c, $e, $p, 'URG-1');
    vdFattura($c, $e, $p, 'URG-2', '2026-03-10', '2026-03-10');
    vdFattura($c, $e, $p, 'URG-3', '2026-03-12', null);

    $out = vdOutput($c);
    expect($out)->not->toContain('Delibera assente')
        ->toContain('Urgenza con fatture senza competenza (1)')->toContain('Caldaia rotta')
        ->toContain('URG-1')->toContain('URG-3')->not->toContain('URG-2');
});

it('con «urgenza» e ogni fattura con la competenza tace', function () {
    [$c, $e] = vdCondominio();
    $g = vdGestione($c, $e, 'straordinaria');
    $p = vdPiano($g, ['tipo_autorizzazione' => 'urgenza', 'data_delibera_assemblea' => null]);
    vdFattura($c, $e, $p, 'URG-OK', '2026-03-10', '2026-03-10');

    expect(vdOutput($c))->toContain('Nessun segnale');
});

it('conta i segnali dei tre tipi insieme e resta SUCCESS', function () {
    [$c, $e] = vdCondominio();
    $g = vdGestione($c, $e, 'straordinaria');
    vdPiano($g, ['nome' => 'Senza data', 'data_delibera_assemblea' => null]);
    vdPiano($g, ['nome' => 'Sospetto', 'data_delibera_assemblea' => '2026-04-01', 'approvato_il' => '2026-04-01 09:00:00']);
    $p = vdPiano($g, ['nome' => 'Urgente', 'tipo_autorizzazione' => 'urgenza', 'data_delibera_assemblea' => null]);
    vdFattura($c, $e, $p, 'URG-9');

    expect(vdOutput($c))->toContain('3 segnali in totale');
});

it('non tocca nulla', function () {
    [$c, $e] = vdCondominio();
    $g = vdGestione($c, $e, 'straordinaria');
    $p = vdPiano($g, ['data_delibera_assemblea' => null]);
    $prima = DB::table('piani_rate')->where('id', $p->id)->first();

    vdOutput($c);

    expect(DB::table('piani_rate')->where('id', $p->id)->first())->toEqual($prima);
});
