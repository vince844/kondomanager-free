<?php

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Actions\PianoRate\GenerateRateQuotesAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RigaRiparto;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../Gestionale/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Il dettaglio del riparto **scritto** (1.11.0-beta.29): `righe_riparto` nasce con le quote,
 * nella stessa transazione, e con esse deve tornare al centesimo sul dato salvato — non in
 * memoria. Le invarianti in memoria stanno in `RigheRipartoInvariantiTest`; qui si prova ciò che
 * finisce nel database, la rigenerazione che sostituisce, la cancellazione che cancella, e il
 * rollback che non lascia quote senza dettaglio né dettaglio senza quote.
 */
function condominioScrittura(int $numeroRate = 2): array
{
    $condominio = Condominio::factory()->create(['nome' => 'SCRITTURA']);
    $esercizio = Esercizio::create(['condominio_id' => $condominio->id, 'nome' => '2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $gestione = Gestione::create(['condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'nome' => 'Gestione Base', 'tipo' => 'ordinaria']);
    $pianoConto = PianoConto::create(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'PC']);
    $tabA = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'TAB A', 'quota' => 'millesimi']);
    $tabB = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'TAB B', 'quota' => 'quote']);
    $immobili = [];
    foreach ([1 => [613.33, 1], 2 => [386.67, 2], 3 => [0, 0]] as $n => [$a, $b]) {
        $immobili[$n] = Immobile::create(['condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => (string) $n, 'nome' => "App $n", 'descrizione' => 'Test']);
        DB::table('quote_tabella')->insert([
            ['tabella_id' => $tabA->id, 'immobile_id' => $immobili[$n]->id, 'valore' => $a, 'created_at' => now(), 'updated_at' => now()],
            ['tabella_id' => $tabB->id, 'immobile_id' => $immobili[$n]->id, 'valore' => $b, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $ruoli = $n === 1 ? [['proprietario', 50], ['proprietario', 50]] : [['proprietario', 100]];
        foreach ($ruoli as [$ruolo, $quota]) {
            DB::table('anagrafica_immobile')->insert(['anagrafica_id' => Anagrafica::factory()->create()->id, 'immobile_id' => $immobili[$n]->id, 'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true, 'data_inizio' => now()]);
        }
    }
    foreach ([['Multi', 100001, [[$tabA->id, 60], [$tabB->id, 40]]], ['Solo A', 33333, [[$tabA->id, 100]]]] as [$nome, $importo, $tabelle]) {
        $conto = Conto::create(['piano_conto_id' => $pianoConto->id, 'nome' => $nome, 'tipo' => 'spesa', 'importo' => $importo]);
        foreach ($tabelle as [$tabId, $coeff]) {
            $pivotId = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabId, 'coefficiente' => $coeff, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $piano = PianoRate::create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'nome' => 'Piano', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => $numeroRate]);

    return [$condominio, $gestione, $piano, $immobili];
}

/** Per ogni «aid|iid» delle quote del piano: Σ quota_pura_gestione letta dal JSON salvato. */
function quotaPuraPerChiave(PianoRate $piano): array
{
    $out = [];
    foreach (RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->get() as $q) {
        $k = $q->anagrafica_id.'|'.$q->immobile_id;
        $out[$k] = ($out[$k] ?? 0) + (int) ($q->regole_calcolo['importi']['quota_pura_gestione'] ?? 0);
    }
    ksort($out);

    return $out;
}

function dettaglioPerChiave(PianoRate $piano): array
{
    $out = [];
    foreach ($piano->righeRiparto()->whereNotNull('anagrafica_id')->get() as $r) {
        $k = $r->anagrafica_id.'|'.$r->immobile_id;
        $out[$k] = ($out[$k] ?? 0) + $r->importo;
    }
    ksort($out);

    return $out;
}

it('la generazione scrive il dettaglio con le quote, e sul dato salvato ogni soggetto torna al centesimo con la sua quota pura di gestione', function () {
    [, , $piano, $immobili] = condominioScrittura(3);

    app(GeneratePianoRateAction::class)->execute($piano);

    expect($piano->righeRiparto()->count())->toBeGreaterThan(0)
        ->and(dettaglioPerChiave($piano))->toBe(quotaPuraPerChiave($piano))
        // Le righe hanno il piano e la versione di chi le ha scritte.
        ->and($piano->righeRiparto()->where('versione_calcolo', config('app.version'))->count())->toBe($piano->righeRiparto()->count())
        // Le colonne del conto multi-tabella tornano al budget: 60.000 su A e 40.001 su B.
        ->and((int) $piano->righeRiparto()->where('tipo', 'riparto')->where('conto_nome', 'Multi')->where('tabella_nome', 'TAB A')->sum('importo'))->toBe(60000)
        ->and((int) $piano->righeRiparto()->where('tipo', 'riparto')->where('conto_nome', 'Multi')->where('tabella_nome', 'TAB B')->sum('importo'))->toBe(40001)
        // L'unità 3, a zero su entrambe le tabelle, ha le sue due righe quota_zero e nessun importo.
        ->and($piano->righeRiparto()->where('tipo', 'quota_zero')->where('immobile_id', $immobili[3]->id)->count())->toBe(2)
        ->and((int) $piano->righeRiparto()->where('immobile_id', $immobili[3]->id)->sum('importo'))->toBe(0);
});

it('la rigenerazione sostituisce il dettaglio: nessuna riga della generazione precedente sopravvive', function () {
    [, , $piano] = condominioScrittura();
    app(GeneratePianoRateAction::class)->execute($piano);
    $primaGenerazione = $piano->righeRiparto()->pluck('id')->all();
    expect($primaGenerazione)->not->toBeEmpty();

    // La porta della rigenerazione: le rate si cancellano (cascata sulle quote) e si rigenera.
    $piano->rate()->delete();
    app(GeneratePianoRateAction::class)->execute($piano->refresh());

    expect(RigaRiparto::whereIn('id', $primaGenerazione)->count())->toBe(0)
        ->and($piano->righeRiparto()->count())->toBe(count($primaGenerazione))
        ->and(dettaglioPerChiave($piano))->toBe(quotaPuraPerChiave($piano));
});

it('cancellare il piano cancella il dettaglio per cascata', function () {
    [, , $piano] = condominioScrittura();
    app(GeneratePianoRateAction::class)->execute($piano);
    $id = $piano->id;
    expect(RigaRiparto::where('piano_rate_id', $id)->count())->toBeGreaterThan(0);

    $piano->delete();

    expect(RigaRiparto::where('piano_rate_id', $id)->count())->toBe(0);
});

it('un dettaglio che non spiega le quote non viene scritto, e con lui non vengono scritte le quote', function () {
    [, , $piano] = condominioScrittura();
    $totali = [1 => [1 => 10000], 2 => [2 => 5000]];
    $dettaglioRotto = [
        ['tipo' => 'riparto', 'anagrafica_id' => 1, 'immobile_id' => 1, 'conto_id' => null, 'importo' => 9999],
        ['tipo' => 'riparto', 'anagrafica_id' => 2, 'immobile_id' => 2, 'conto_id' => null, 'importo' => 5000],
    ];

    expect(fn () => app(GenerateRateQuotesAction::class)->execute($piano, $totali, ['2026-03-01', '2026-06-01'], [], $dettaglioRotto))
        ->toThrow(\RuntimeException::class);

    expect($piano->rate()->count())->toBe(0)
        ->and($piano->righeRiparto()->count())->toBe(0);
});

it('senza dettaglio passato la scrittura delle quote resta quella di sempre: nessuna riga, nessun errore', function () {
    [, , $piano] = condominioScrittura();
    $totali = [1 => [1 => 10000]];

    app(GenerateRateQuotesAction::class)->execute($piano, $totali, ['2026-03-01', '2026-06-01']);

    expect($piano->rate()->count())->toBe(2)->and($piano->righeRiparto()->count())->toBe(0);
});

it('se le rate falliscono dopo il dettaglio, il dettaglio già scritto viene annullato con loro', function () {
    // Il verso che la riconciliazione non prova: dettaglio valido, scritto, e poi un errore sulle
    // rate. La transazione dell'Action copre anche le righe: alla fine non resta né l'uno né le altre.
    [$condominio, , $piano, $immobili] = condominioScrittura();
    $anagrafica = \App\Models\Anagrafica::factory()->create();
    $totali = [$anagrafica->id => [$immobili[2]->id => 10000]];
    $dettaglio = [['tipo' => 'riparto', 'anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobili[2]->id, 'conto_id' => null, 'importo' => 10000]];

    $righeViste = null;
    \App\Models\Gestionale\Rata::creating(function () use ($piano, &$righeViste) {
        $righeViste = $piano->righeRiparto()->count();
        throw new \RuntimeException('boom');
    });

    expect(fn () => app(GenerateRateQuotesAction::class)->execute($piano, $totali, ['2026-03-01', '2026-06-01'], [], $dettaglio))
        ->toThrow(\RuntimeException::class, 'boom');

    expect($righeViste)->toBe(1)
        ->and($piano->righeRiparto()->count())->toBe(0)
        ->and($piano->rate()->count())->toBe(0);
});

it('le due colonne fantasma di rate_quote non esistono più, e la tabella del dettaglio sì', function () {
    expect(\Illuminate\Support\Facades\Schema::hasColumn('rate_quote', 'riga_fattura_id'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('rate_quote', 'voce_id'))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasTable('righe_riparto'))->toBeTrue();
});

it('l\'addebito ad personam senza titolare ferma la generazione come gli altri scoperti, e chi accetta non lo addebita a nessuno', function () {
    // Fino alla 1.11.0-beta.28 il motore lo perdeva con un avviso nei log e il piano si generava
    // con cento euro in meno, senza dirlo. Ora è uno scoperto: la generazione si ferma, chiede la
    // motivazione, e la riga arriva all'interfaccia con l'unità e la descrizione della spesa.
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo, , $immobile1] = setupContabile();
    $tab = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GEN', 'quota' => 'millesimi']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tab->id, 'immobile_id' => $immobile1, 'valore' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => Anagrafica::factory()->create()->id, 'immobile_id' => $immobile1, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $capitolo->id, 'tabella_id' => $tab->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $orfana = Immobile::create(['condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'interno' => '9', 'nome' => 'App 9', 'descrizione' => 'Senza proprietario']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => Anagrafica::factory()->create()->id, 'immobile_id' => $orfana->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);
    $fattura = \App\Models\Gestionale\FatturaPassiva::create(['condominio_id' => $condominio->id, 'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'FT-D7', 'data_documento' => now()->format('Y-m-d'), 'data_scadenza' => now()->addDays(30)->format('Y-m-d'), 'is_pregresso' => false, 'importo_imponibile' => 0, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => 0, 'netto_a_pagare' => 0, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    DB::table('righe_fattura')->insert([
        ['fattura_passiva_id' => $fattura->id, 'conto_id' => $capitolo->id, 'immobile_id' => null, 'descrizione' => 'Facciata', 'aliquota_iva' => 0, 'importo_imponibile' => 100000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()],
        // Senza conto: è ciò che `FatturaPassivaService` scrive su ogni riga con l'immobile.
        ['fattura_passiva_id' => $fattura->id, 'conto_id' => null, 'immobile_id' => $orfana->id, 'descrizione' => 'Balcone interno 9', 'aliquota_iva' => 0, 'importo_imponibile' => 10000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $piano = PianoRate::create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'nome' => 'Straordinario D7', 'stato' => 'bozza', 'tipo' => 'straordinario', 'numero_rate' => 1]);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 110000]);

    $scoperti = null;
    try {
        app(GeneratePianoRateAction::class)->execute($piano);
    } catch (\App\Exceptions\Gestionale\ScopertiNonAccettatiException $e) {
        $scoperti = $e->getScoperti();
    }

    expect($scoperti)->not->toBeNull()->toHaveCount(1)
        ->and($scoperti[0]['motivo'])->toBe('ad_personam_senza_titolare')
        ->and($scoperti[0]['immobile_id'])->toBe($orfana->id)
        ->and($scoperti[0]['immobile_nome'])->toBe('App 9')
        ->and($scoperti[0]['importo'])->toBe(10000)
        ->and($scoperti[0]['conto_nome'])->toBeNull()
        ->and($scoperti[0]['riga_descrizione'])->toBe('Balcone interno 9')
        ->and($piano->rate()->count())->toBe(0)
        ->and($piano->righeRiparto()->count())->toBe(0);

    // Accettando, il piano nasce senza quell'importo e senza righe per l'unità orfana.
    app(GeneratePianoRateAction::class)->execute($piano->fresh(), accettaScoperti: true);
    $piano = $piano->fresh();
    expect((int) RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->sum('importo'))->toBe(100000)
        ->and($piano->righeRiparto()->where('immobile_id', $orfana->id)->count())->toBe(0);
});
