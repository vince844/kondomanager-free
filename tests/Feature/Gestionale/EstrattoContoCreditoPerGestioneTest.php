<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il riquadro del credito dell'estratto conto, con credito e debito su gestioni diverse: separato per gestione, non il netto unico.
 *
 * Bice ha € 120,00 di credito sulla gestione ordinaria (una rata emessa da € 100,00 pagata € 220,00) e € 200,00 di rate emesse da pagare sui
 * «Lavori straordinari». Il riquadro diceva «€ 80,00 da versare»: il credito dell'ordinaria toglieva dal debito della straordinaria. Il credito
 * di una gestione copre da sé solo le rate della sua, quindi il riquadro dice «€ 200,00 da versare» sui lavori e «€ 120,00 di credito»
 * sull'ordinaria. Dentro la stessa gestione il netto resta (`CreditoDisponibileNettoTest`). Il pregresso della rata 0 (saldi iniziali, riporti)
 * sta nella gestione del suo piano: un piano ha sempre la sua gestione.
 *
 * Lo scenario è quello di `Beta59EstrattoScenari.php` (il piano ordinario generato resta in bozza e non pesa), con le rate emesse scritte
 * nelle tabelle delle rate: il netto della persona legge le quote, non il giornale. Cifre in centesimi. Prefisso `ecpg`.
 */

use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Services\Gestionale\CreditoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59EstrattoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

/** Una rata emessa del piano dato con la quota di Bice sull'Interno A: `$importo` e `$pagato` in centesimi. */
function ecpgRata(array $s, ?int $gestioneId, string $piano, int $importo, int $pagato): RataQuote
{
    $p = PianoRate::forceCreate(['condominio_id' => $s['c']->id, 'gestione_id' => $gestioneId, 'nome' => $piano, 'numero_rate' => 1]);
    $r = Rata::create(['piano_rate_id' => $p->id, 'numero_rata' => 1, 'data_scadenza' => '2026-06-05', 'importo_totale' => $importo, 'stato' => 'emessa']);

    return RataQuote::create(['rata_id' => $r->id, 'anagrafica_id' => $s['mario']->id, 'immobile_id' => $s['unita']->id, 'importo' => $importo,
        'importo_pagato' => $pagato, 'stato' => $pagato >= $importo ? 'pagata' : 'da_pagare', 'data_scadenza' => '2026-06-05']);
}

function ecpgScenario($test): array
{
    $s = b59eScenario($test);
    DB::table('anagrafiche')->where('id', $s['mario']->id)->update(['nome' => 'Bice Neri']);
    $s['lavori'] = Gestione::create(['condominio_id' => $s['c']->id, 'nome' => 'Lavori straordinari', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01']);
    $s['lavori']->esercizi()->attach($s['e']->id, ['attiva' => true]);
    ecpgRata($s, (int) $s['g']->id, 'Conguaglio ordinaria', 10000, 22000);
    ecpgRata($s, (int) $s['lavori']->id, 'Rifacimento tetto', 20000, 0);

    return $s;
}

function ecpgStats($test, array $s): array
{
    return $test->actingAs($s['admin'])->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['mario']]))->assertOk()->viewData('page')['props']['stats'];
}

it('Bice: «€ 200,00 da versare» sui lavori straordinari e «€ 120,00 di credito» sull\'ordinaria, non «€ 80,00 da versare»', function () {
    $s = ecpgScenario($this);

    $netto = app(CreditoService::class)->nettoDellaPersona((int) $s['c']->id, (int) $s['mario']->id);
    expect($netto['netto_cents'])->toBe(12000)->and($netto['da_versare_cents'])->toBe(20000);

    $stats = ecpgStats($this, $s);
    expect($stats['credito_disponibile_raw'])->toBe(12000)
        ->and($stats['credito_disponibile'])->toBe('€ 120,00')
        ->and($stats['credito_da_versare_raw'])->toBe(20000)
        ->and($stats['credito_da_versare'])->toBe('€ 200,00');

    $righe = collect($stats['credito_per_gestione'])->keyBy('gestione_nome');
    // Prima la gestione con qualcosa da versare, poi quella a credito.
    expect($righe->keys()->all())->toBe(['Lavori straordinari', (string) $s['g']->nome])
        ->and($righe['Lavori straordinari']['da_versare_cents'])->toBe(20000)
        ->and($righe['Lavori straordinari']['credito_cents'])->toBe(0)
        ->and($righe['Lavori straordinari']['frase'])->toBe('€ 200,00 da versare')
        ->and($righe[$s['g']->nome]['credito_cents'])->toBe(12000)
        ->and($righe[$s['g']->nome]['da_versare_cents'])->toBe(0)
        ->and($righe[$s['g']->nome]['frase'])->toBe('€ 120,00 di credito');
});

it('Bice nel PDF: le due righe per gestione, con le stesse cifre della pagina', function () {
    $s = ecpgScenario($this);

    $testo = b59ePdf($s)['testo'];

    expect($testo)->toContain('Lavori straordinari: € 200,00 da versare')
        ->and($testo)->toContain($s['g']->nome.': € 120,00 di credito');
});

it('dentro la stessa gestione il netto resta: € 120,00 di credito e € 200,00 di debito sull\'ordinaria sono € 80,00 da versare', function () {
    $s = b59eScenario($this);
    ecpgRata($s, (int) $s['g']->id, 'Conguaglio ordinaria', 10000, 22000);
    ecpgRata($s, (int) $s['g']->id, 'Seconda ordinaria', 20000, 0);

    $stats = ecpgStats($this, $s);
    expect($stats['credito_disponibile_raw'])->toBe(0)
        ->and($stats['credito_da_versare_raw'])->toBe(8000)
        ->and(collect($stats['credito_per_gestione'])->pluck('frase')->all())->toBe(['€ 80,00 da versare']);
});

it('il pregresso della rata 0 (un saldo iniziale, un riporto) sta nella riga della gestione del suo piano, e si netta lì', function () {
    // Un piano ha sempre la sua gestione (`piani_rate.gestione_id` non è nullo): il saldo iniziale a credito di € 50,00 della rata 0 dei lavori,
    // ancora in bozza, toglie dai € 200,00 dei lavori, non dall'ordinaria. A mano: lavori 20000 − 5000 = 15000 da versare; ordinaria 12000 di credito.
    $s = ecpgScenario($this);
    $piano = PianoRate::where('nome', 'Rifacimento tetto')->firstOrFail();
    $zero = Rata::create(['piano_rate_id' => $piano->id, 'numero_rata' => 0, 'data_scadenza' => '2026-01-01', 'importo_totale' => -5000, 'stato' => 'bozza', 'descrizione' => 'Saldo Pregresso']);
    RataQuote::create(['rata_id' => $zero->id, 'anagrafica_id' => $s['mario']->id, 'immobile_id' => $s['unita']->id, 'importo' => -5000, 'importo_pagato' => 0,
        'stato' => 'credito', 'tipo' => 'saldo_iniziale', 'data_scadenza' => '2026-01-01']);

    $stats = ecpgStats($this, $s);
    $righe = collect($stats['credito_per_gestione'])->keyBy('gestione_nome');
    expect($stats['credito_disponibile_raw'])->toBe(12000)
        ->and($stats['credito_da_versare_raw'])->toBe(15000)
        ->and($righe['Lavori straordinari']['frase'])->toBe('€ 150,00 da versare')
        ->and($righe[$s['g']->nome]['frase'])->toBe('€ 120,00 di credito');
});
