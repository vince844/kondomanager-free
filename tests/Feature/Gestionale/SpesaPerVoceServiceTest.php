<?php

use App\Actions\Gestionale\Movimenti\RegistraRegolazioneImmediataAction;
use App\Actions\Gestionale\Movimenti\StornaRegolazioneImmediataAction;
use App\Models\User;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function speso(array $ctx, ?array $vociIds = null): array
{
    [, $esercizio] = $ctx;

    return app(SpesaPerVoceService::class)->perEsercizio($esercizio, $vociIds);
}

function regolazione(array $ctx, float $importo, array $extra = []): \App\Models\Gestionale\ScritturaContabile
{
    [$condominio, $esercizio, $gestione, , , $capitolo] = $ctx;

    $cassaId = DB::table('casse')->where('condominio_id', $condominio->id)->value('id');

    return (new RegistraRegolazioneImmediataAction())->execute(array_merge([
        'gestione_id' => $gestione->id,
        'esercizio_id' => $esercizio->id,
        'conto_id' => $capitolo->id,
        'cassa_id' => $cassaId,
        'fornitore_id' => null,
        'data_operazione' => now()->toDateString(),
        'causale' => 'Imposta di bollo',
        'importo' => $importo,
    ], $extra), $condominio, $esercizio);
}

// ─── Il bug segnalato in beta.29 ─────────────────────────────────────────────

test('una regolazione immediata entra nello speso della voce', function () {
    $ctx = setupPagamentiService();
    [, , , , , $capitolo] = $ctx;

    expect(speso($ctx))->toBe([]);

    regolazione($ctx, 6.72);

    // Il caso dell'amministratore: 6,72 € di regolazione immediata che la vecchia
    // query su righe_fattura non vedeva, lasciando il capitolo apparentemente libero.
    expect(speso($ctx)[$capitolo->id] ?? 0)->toBe(672);
});

test('fattura e regolazione immediata sulla stessa voce si sommano', function () {
    $ctx = setupPagamentiService();
    [, , , , , $capitolo] = $ctx;

    $fattura = registraFatturaServiceTest($ctx);
    $lordoFattura = (int) DB::table('righe_fattura')
        ->where('fattura_passiva_id', $fattura->id)
        ->sum(DB::raw('importo_imponibile + importo_iva'));

    regolazione($ctx, 6.72);

    expect(speso($ctx)[$capitolo->id])->toBe($lordoFattura + 672);
});

// ─── Nessuna regressione sul caso comune ─────────────────────────────────────

test('per una fattura semplice la nuova fonte dà lo stesso numero della vecchia query su righe_fattura', function () {
    $ctx = setupPagamentiService();
    [, $esercizio, , , , $capitolo] = $ctx;

    registraFatturaServiceTest($ctx);

    // La query che questo servizio sostituisce, riprodotta qui come oracolo.
    $vecchiaFonte = (int) DB::table('righe_fattura')
        ->join('fatture_passive', 'righe_fattura.fattura_passiva_id', '=', 'fatture_passive.id')
        ->where('fatture_passive.esercizio_id', $esercizio->id)
        ->where('fatture_passive.stato_approvazione', '!=', 'contestata')
        ->whereNull('righe_fattura.immobile_id')
        ->where('righe_fattura.conto_id', $capitolo->id)
        ->sum(DB::raw('righe_fattura.importo_imponibile + righe_fattura.importo_iva'));

    expect($vecchiaFonte)->toBeGreaterThan(0)
        ->and(speso($ctx)[$capitolo->id])->toBe($vecchiaFonte);
});

// ─── Segno: storni e note di credito ─────────────────────────────────────────

test('lo storno di una regolazione immediata riporta lo speso a zero', function () {
    $ctx = setupPagamentiService();
    [$condominio, , , , , $capitolo] = $ctx;

    $scrittura = regolazione($ctx, 50.00);
    expect(speso($ctx)[$capitolo->id])->toBe(5000);

    (new StornaRegolazioneImmediataAction())->execute($scrittura, $condominio, 'Errore di digitazione');

    // La riga inversa ricopia voce_spesa_id: il budget del capitolo torna libero.
    expect(speso($ctx)[$capitolo->id] ?? 0)->toBe(0);
});

test('due regolazioni di cui una stornata lasciano solo la superstite', function () {
    $ctx = setupPagamentiService();
    [$condominio, , , , , $capitolo] = $ctx;

    $daStornare = regolazione($ctx, 30.00);
    regolazione($ctx, 12.50);

    (new StornaRegolazioneImmediataAction())->execute($daStornare, $condominio, 'Doppio inserimento');

    expect(speso($ctx)[$capitolo->id])->toBe(1250);
});

// ─── Confini: ad personam, esercizio, filtro voci ────────────────────────────

test('le spese ad personam non entrano nello speso comune', function () {
    $ctx = setupPagamentiService();
    [$condominio, , , , , $capitolo] = $ctx;

    $immobileId = DB::table('immobili')->where('condominio_id', $condominio->id)->value('id');

    if (! $immobileId) {
        $this->markTestSkipped('Il setup non fornisce immobili per questo condominio.');
    }

    registraFatturaServiceTest($ctx, [
        'righe' => [[
            'descrizione' => 'Spesa privata',
            'importo_imponibile' => 5000,
            'aliquota_iva' => 22,
            'conto_id' => null,
            'immobile_id' => $immobileId,
            'is_sopravvenienza' => false,
        ]],
    ]);

    // La riga ad personam DEVE esistere a giornale: senza questa verifica il test
    // passerebbe anche se la fattura non fosse stata registrata affatto.
    $rigaAdPersonam = DB::table('righe_scritture')
        ->where('immobile_id', $immobileId)
        ->whereNull('voce_spesa_id')
        ->first();

    expect($rigaAdPersonam)->not->toBeNull()
        // Nasce con voce_spesa_id = null (art. 63 disp. att. c.c.): fuori per costruzione.
        ->and(speso($ctx)[$capitolo->id] ?? 0)->toBe(0);
});

test('il filtro per voci restringe il risultato', function () {
    $ctx = setupPagamentiService();
    [, , , , , $capitolo] = $ctx;

    regolazione($ctx, 20.00);

    expect(speso($ctx, [$capitolo->id]))->toHaveKey($capitolo->id)
        ->and(speso($ctx, [$capitolo->id + 9999]))->toBe([])
        ->and(speso($ctx, []))->toBe([]);
});

test('lo speso è isolato per esercizio', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , , , $capitolo] = $ctx;

    regolazione($ctx, 44.00);

    $altroEsercizioId = DB::table('esercizi')->insertGetId([
        'condominio_id' => $condominio->id,
        'nome' => 'Esercizio 2027',
        'stato' => 'aperto',
        'data_inizio' => '2027-01-01',
        'data_fine' => '2027-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $altroEsercizio = \App\Models\Esercizio::find($altroEsercizioId);

    expect(speso($ctx)[$capitolo->id])->toBe(4400)
        ->and(app(SpesaPerVoceService::class)->perEsercizio($altroEsercizio))->toBe([]);
});

// ─── Invariante analitico ↔ sintetico ────────────────────────────────────────

test('lo speso della voce coincide col saldo del suo conto contabile', function () {
    $ctx = setupPagamentiService();
    [, $esercizio, , , , $capitolo] = $ctx;

    registraFatturaServiceTest($ctx);
    regolazione($ctx, 9.99);

    // Il capitolo è l'unica voce agganciata a questo conto contabile, quindi la
    // lettura analitica (voce_spesa_id) e quella sintetica (conto_contabile_id)
    // devono coincidere. Se divergono, una delle due scritture ha perso l'aggancio.
    $saldoSintetico = (int) DB::table('righe_scritture as rs')
        ->join('scritture_contabili as sc', 'rs.scrittura_id', '=', 'sc.id')
        ->whereNull('sc.deleted_at')
        ->where('sc.esercizio_id', $esercizio->id)
        ->where('rs.conto_contabile_id', $capitolo->conto_contabile_id)
        ->sum(DB::raw("CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END"));

    expect(speso($ctx)[$capitolo->id])->toBe($saldoSintetico);
});

// ─── Casi contabili: nota di credito e fattura contestata ────────────────────

test('una nota di credito scomputa lo speso della voce', function () {
    $ctx = setupPagamentiService();
    [, , , , , $capitolo] = $ctx;

    $fattura = registraFatturaServiceTest($ctx);
    $lordo = (int) DB::table('righe_fattura')
        ->where('fattura_passiva_id', $fattura->id)
        ->sum(DB::raw('importo_imponibile + importo_iva'));

    expect(speso($ctx)[$capitolo->id])->toBe($lordo);

    // FatturaPassivaService inverte i versi di tutte le righe della scrittura:
    // il costo finisce in AVERE, e Σdare − Σavere lo scomputa da sé.
    registraFatturaServiceTest($ctx, [
        'tipo_documento' => 'nota_credito',
        'applica_ritenuta' => false,
    ]);

    expect(speso($ctx)[$capitolo->id] ?? 0)->toBe(0);
});

test('una fattura contestata non pesa sullo speso', function () {
    $ctx = setupPagamentiService();
    [, , , , , $capitolo] = $ctx;

    $fattura = registraFatturaServiceTest($ctx);
    expect(speso($ctx)[$capitolo->id])->toBeGreaterThan(0);

    // La scrittura resta a giornale (il costo di competenza c'è), ma il budget non
    // deve pesarla: regola già applicata dalle query che questo servizio sostituisce.
    DB::table('fatture_passive')->where('id', $fattura->id)->update(['stato_approvazione' => 'contestata']);

    expect(speso($ctx)[$capitolo->id] ?? 0)->toBe(0);
});

test('la fattura in modifica può essere esclusa senza toccare le altre scritture', function () {
    $ctx = setupPagamentiService();
    [, $esercizio, , , , $capitolo] = $ctx;

    $fattura = registraFatturaServiceTest($ctx);
    $lordo = (int) DB::table('righe_fattura')
        ->where('fattura_passiva_id', $fattura->id)
        ->sum(DB::raw('importo_imponibile + importo_iva'));

    regolazione($ctx, 15.00);

    $conFattura = app(SpesaPerVoceService::class)->perEsercizio($esercizio);
    $senzaFattura = app(SpesaPerVoceService::class)->perEsercizio($esercizio, null, $fattura->id);

    expect($conFattura[$capitolo->id])->toBe($lordo + 1500)
        // Esclusa la fattura resta solo la regolazione: l'esclusione è chirurgica.
        ->and($senzaFattura[$capitolo->id])->toBe(1500);
});
