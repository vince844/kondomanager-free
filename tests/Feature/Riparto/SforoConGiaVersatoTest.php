<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\CalcoloQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * IL CASO DEL FORUM, esatto: delibera maggio 2025, accantonamento €1.000 sui
 * millesimi, variante 2026 con fattura reale €1.100 — €100 di sforo da
 * chiedere con un piano rate. Fissa la procedura CORRETTA e blinda contro le
 * due varianti che, verificate con questi stessi test PRIMA di scrivere la
 * guardia in CalcoloQuoteService::guardiaSovraFinanziamentoGiaVersato,
 * chiedevano silenziosamente zero invece dei €100 dovuti.
 *
 * @see docs/fondo_accantonato_e_quadratura_sp.md
 */
function sforoScenario(int $budgetOriginale): object
{
    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Straordinaria Ristrutturazione',
        'data_inizio' => '2026-01-01', 'tipo' => 'straordinaria',
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);
    $pianoConto = PianoConto::create(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'Piano Lavori']);
    $tabella = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Millesimi Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);

    $conto = Conto::forceCreate([
        'piano_conto_id' => $pianoConto->id, 'nome' => 'Ristrutturazione facciata',
        'importo' => $budgetOriginale, 'tipo' => 'spesa', 'attivo' => true,
        'richiede_gia_versato' => true,
    ]);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $immobile = Immobile::forceCreate(['condominio_id' => $condominio->id, 'nome' => 'Interno 1', 'descrizione' => 'App', 'interno' => '1']);
    $anagrafica = Anagrafica::forceCreate([
        'nome' => 'Proprietario Unico', 'email' => 'unico@example.com', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'SFORTEST0000001',
    ]);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobile->id,
        'tipologia' => 'proprietario', 'quota' => 100.0, 'attivo' => true, 'data_inizio' => now()->format('Y-m-d'),
    ]);
    DB::table('quote_tabella')->insert([
        'tabella_id' => $tabella->id, 'immobile_id' => $immobile->id, 'valore' => 1000,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Fornitore Test Srl', 'soggetto_ritenuta' => false,
        'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4, 'giorni_scadenza' => 30,
        'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $fornitore = \App\Models\Fornitore::find($fornitoreId);

    return (object) compact('condominio', 'esercizio', 'gestione', 'conto', 'immobile', 'anagrafica', 'fornitore');
}

/** Registra una fattura ORDINARIA (non pregressa) collegata al conto, come farebbe l'amministratore. */
function registraFatturaSuConto(object $sc, int $importoTotaleCents): FatturaPassiva
{
    $fattura = FatturaPassiva::create([
        'condominio_id' => $sc->condominio->id,
        'fornitore_id' => $sc->fornitore->id,
        'esercizio_id' => $sc->esercizio->id,
        'tipo_documento' => 'fattura',
        'numero_documento' => 'FT-'.uniqid(),
        'data_documento' => now()->format('Y-m-d'),
        'data_scadenza' => now()->addDays(30)->format('Y-m-d'),
        'is_pregresso' => false,
        'importo_imponibile' => $importoTotaleCents,
        'importo_iva' => 0,
        'importo_ritenuta' => 0,
        'totale_documento' => $importoTotaleCents,
        'netto_a_pagare' => $importoTotaleCents,
        'stato_pagamento' => 'aperta',
        'stato_approvazione' => 'approvata',
        'modalita_pagamento' => 'bonifico',
    ]);

    DB::table('righe_fattura')->insert([
        'fattura_passiva_id' => $fattura->id,
        'conto_id' => $sc->conto->id,
        'descrizione' => 'Lavori facciata',
        'aliquota_iva' => 0,
        'importo_imponibile' => $importoTotaleCents,
        'importo_iva' => 0,
        'is_sopravvenienza' => false,
        'is_rateizzata' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $fattura;
}

test('IL CASO DEL FORUM, PROCEDURA CORRETTA: budget aggiornato al costo reale prima di generare il piano', function () {
    $sc = sforoScenario(100_000); // maggio 2025: delibera e accantonamento €1.000

    ContributoVersato::create([
        'condominio_id' => $sc->condominio->id, 'target_type' => Conto::class, 'target_id' => $sc->conto->id,
        'immobile_id' => $sc->immobile->id, 'importo_cents' => 100_000, 'natura' => 'fondo_vincolato',
        'descrizione' => 'Accantonamento delibera maggio 2025',
    ]);

    // 2026: la variante porta il costo reale a €1.100. La fattura viene
    // registrata — la sforamento la segnala, ma NON tocca conto->importo
    // (verificato: nessun aggiornamento automatico in FatturaPassivaService).
    registraFatturaSuConto($sc, 110_000);

    // Passo che la procedura corretta richiede: l'amministratore aggiorna
    // l'importo della voce nel piano dei conti al costo REALE (€1.100), prima
    // di generare qualunque piano rate collegato allo sforo.
    $sc->conto->forceFill(['importo' => 110_000])->save();

    $piano = PianoRate::create([
        'gestione_id' => $sc->gestione->id, 'condominio_id' => $sc->condominio->id,
        'nome' => 'Rata Integrativa — Variante Facciata', 'attivo' => true,
    ]);
    $piano->capitoli()->attach($sc->conto->id, ['importo' => 110_000]);

    $quote = (new CalcoloQuoteService())->calcolaPerGestione($sc->gestione, $piano);
    $totale = collect($quote)->flatMap(fn ($i) => array_values($i))->sum();

    // Esattamente lo sforo: 1.100 (costo reale) − 1.000 (già versato) = 100.
    expect($totale)->toBe(10_000);
})->group('sforo-gia-versato', 'riparto');

test('GUARDIA: uno sforo finanziato senza aggiornare il budget viene bloccato, anche con un pivot piccolo', function () {
    $sc = sforoScenario(100_000);

    ContributoVersato::create([
        'condominio_id' => $sc->condominio->id, 'target_type' => Conto::class, 'target_id' => $sc->conto->id,
        'immobile_id' => $sc->immobile->id, 'importo_cents' => 100_000, 'natura' => 'fondo_vincolato',
    ]);

    registraFatturaSuConto($sc, 110_000);
    // NESSUN aggiornamento di conto->importo: resta a 100.000, come farebbe
    // un amministratore che pensa "il già versato copre già il grosso, chiedo
    // solo l'eccesso" — è esattamente l'errore che rende silenzioso il buco.

    $piano = PianoRate::create([
        'gestione_id' => $sc->gestione->id, 'condominio_id' => $sc->condominio->id,
        'nome' => 'Solo lo sforo', 'attivo' => true,
    ]);
    $piano->capitoli()->attach($sc->conto->id, ['importo' => 10_000]); // solo €100

    (new CalcoloQuoteService())->calcolaPerGestione($sc->gestione, $piano);
})->throws(\RuntimeException::class)
  ->group('sforo-gia-versato', 'riparto', 'regressione-avversariale');

test('GUARDIA: anche accettando il valore suggerito (l\'intera fattura) senza aggiornare il budget si viene bloccati', function () {
    $sc = sforoScenario(100_000);

    ContributoVersato::create([
        'condominio_id' => $sc->condominio->id, 'target_type' => Conto::class, 'target_id' => $sc->conto->id,
        'immobile_id' => $sc->immobile->id, 'importo_cents' => 100_000, 'natura' => 'fondo_vincolato',
    ]);

    registraFatturaSuConto($sc, 110_000);

    $piano = PianoRate::create([
        'gestione_id' => $sc->gestione->id, 'condominio_id' => $sc->condominio->id,
        'nome' => 'Integrativo — importo suggerito', 'attivo' => true,
    ]);
    // L'admin usa il valore "suggerito" (l'intera fattura), ma senza aver
    // prima corretto conto->importo: la guardia non guarda quanto vale il
    // pivot, guarda se il budget dichiarato è coerente col fatturato reale.
    $piano->capitoli()->attach($sc->conto->id, ['importo' => 110_000]);

    (new CalcoloQuoteService())->calcolaPerGestione($sc->gestione, $piano);
})->throws(\RuntimeException::class)
  ->group('sforo-gia-versato', 'riparto', 'regressione-avversariale');

test('Senza già versato, uno sforo di budget NON viene bloccato dalla guardia (comportamento invariato)', function () {
    $sc = sforoScenario(100_000);
    // Nessun ContributoVersato registrato per questo conto.

    registraFatturaSuConto($sc, 110_000);
    // conto->importo resta 100.000: senza già-versato la guardia non si applica,
    // il comportamento pre-beta.27 è invariato.

    $piano = PianoRate::create([
        'gestione_id' => $sc->gestione->id, 'condominio_id' => $sc->condominio->id,
        'nome' => 'Sforo senza già versato', 'attivo' => true,
    ]);
    $piano->capitoli()->attach($sc->conto->id, ['importo' => 10_000]);

    $quote = (new CalcoloQuoteService())->calcolaPerGestione($sc->gestione, $piano);
    $totale = collect($quote)->flatMap(fn ($i) => array_values($i))->sum();

    expect($totale)->toBe(10_000);
})->group('sforo-gia-versato', 'riparto');
