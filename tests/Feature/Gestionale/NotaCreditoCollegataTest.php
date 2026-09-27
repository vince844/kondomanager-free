<?php

/*
|--------------------------------------------------------------------------
| Coda 165 (1.11.0-beta.36) — la nota di credito del fornitore e il piano rate
|--------------------------------------------------------------------------
|
| La nota di credito che emette il fornitore è la strada normale per correggere una fattura elettronica sbagliata, e
| fino alla beta.35 non si collegava alla fattura che annulla: la fattura restava «aperta» e nel suo piano, il piano
| continuava a chiederla, e la fattura nuova entrava in un secondo piano. La stessa spesa chiesta due volte — misurato
| con una sonda sulla beta.34 e sulla beta.35 (verbale in `docs/piano_esecutivo_beta35.md`).
|
| Decisione 26, punto 6 (`docs/subentro_e_competenza_temporale.md` §9), presa da Vincenzo il 27/09/2026:
| - la nota porta la fattura che rettifica (`fattura_rettificata_id`), facoltativa, di qualunque esercizio;
| - se quella fattura sta in un piano che non ha incassato niente, la nota non si registra finché il piano non è tolto
|   (la stessa scala dello storno, punto 5); con incassi si registra, e l'avviso dice che le rate restano;
| - il carrello ragiona sulla fattura al netto delle note collegate, e la voce della riga della nota dice quale parte
|   riduce; mai più del documento al netto;
| - il ricalcolo si ferma quando i piani chiedono per la fattura più del suo netto (criterio numerico);
| - storno ed eliminazione di una fattura con note collegate si rifiutano; «Scollega» è libero; cambiare l'importo di
|   una nota collegata segue la scala; eliminarla è libero.
|
| Gli helper hanno il prefisso `nc` perché Pest carica tutti i file nello stesso processo: le funzioni di
| `PianoRateStraordinarioPregressoTest.php` esistono solo se quel file è già stato caricato, e questo file deve girare
| anche da solo.
*/

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use App\Services\CalcoloQuoteService;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\NettoNoteCollegate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__ . '/GestionaleTestHelpers.php';

// =============================================================================
// HELPER
// =============================================================================

/** Condominio contabile, un immobile con proprietario, una tabella collegata al capitolo di `setupContabile`. */
function ncBase(): array
{
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo, , $immobileId] = setupContabile();
    DB::table('gestioni')->where('id', $gestione->id)->update(['data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $tabella = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Tabella Generale', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $immobileId, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $anagrafica = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $anagrafica->id, 'immobile_id' => $immobileId, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);
    ncLegaTabella($capitolo->id, $tabella->id);

    return ['condominio' => $condominio, 'esercizio' => $esercizio, 'gestione' => $gestione, 'fornitore' => $fornitore, 'capitolo' => $capitolo, 'immobileId' => $immobileId, 'tabella' => $tabella, 'anagraficaId' => $anagrafica->id];
}

function ncLegaTabella(int $contoId, int $tabellaId): void
{
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $contoId, 'tabella_id' => $tabellaId, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
}

/** Un secondo conto di spesa nello stesso piano dei conti del capitolo, collegato alla tabella. */
function ncAltroConto(array $base, string $nome): Conto
{
    $conto = Conto::create(['piano_conto_id' => $base['capitolo']->piano_conto_id, 'nome' => $nome, 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 0]);
    ncLegaTabella($conto->id, $base['tabella']->id);

    return $conto;
}

/**
 * Fattura corrente con righe controllate (centesimi). Ogni riga: ['conto_id', 'importo', 'is_sopravvenienza' => bool,
 * 'immobile_id' => ?int]. La testata porta il totale delle righe.
 */
function ncFattura(array $base, array $righe, ?string $numero = null): FatturaPassiva
{
    $totale = array_sum(array_column($righe, 'importo'));
    $f = FatturaPassiva::create([
        'condominio_id' => $base['condominio']->id, 'fornitore_id' => $base['fornitore']->id, 'esercizio_id' => $base['esercizio']->id,
        'tipo_documento' => 'fattura', 'numero_documento' => $numero ?? ('FT-' . uniqid()), 'data_documento' => '2026-03-10',
        'data_scadenza' => '2026-04-10', 'is_pregresso' => false, 'importo_imponibile' => $totale, 'importo_iva' => 0,
        'importo_ritenuta' => 0, 'totale_documento' => $totale, 'netto_a_pagare' => $totale, 'stato_pagamento' => 'aperta',
        'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
    ]);
    ncRighe($f->id, $righe);

    return $f;
}

function ncRighe(int $fatturaId, array $righe): void
{
    foreach ($righe as $r) {
        DB::table('righe_fattura')->insert([
            'fattura_passiva_id' => $fatturaId, 'conto_id' => $r['conto_id'] ?? null, 'immobile_id' => $r['immobile_id'] ?? null,
            'descrizione' => $r['descrizione'] ?? 'Riga test', 'aliquota_iva' => 0, 'importo_imponibile' => $r['importo'],
            'importo_iva' => 0, 'is_sopravvenienza' => $r['is_sopravvenienza'] ?? false, 'is_rateizzata' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

/** Una fattura corrente tutta fuori preventivo da € 1.000,00 sul capitolo. */
function ncFatturaFuoriPreventivo(array $base, int $cents = 100000, ?string $numero = null): FatturaPassiva
{
    return ncFattura($base, [['conto_id' => $base['capitolo']->id, 'importo' => $cents, 'is_sopravvenienza' => true]], $numero);
}

/**
 * Una nota di credito scritta direttamente (testata e righe NEGATIVE), per costruire stati che la registrazione
 * rifiuterebbe. Ogni riga: ['conto_id', 'importo' (magnitudine in centesimi), 'immobile_id' => ?int].
 */
function ncNota(array $base, ?FatturaPassiva $rettifica, array $righe, ?string $numero = null): FatturaPassiva
{
    $totale = array_sum(array_column($righe, 'importo'));
    $n = FatturaPassiva::create([
        'condominio_id' => $base['condominio']->id, 'fornitore_id' => $base['fornitore']->id, 'esercizio_id' => $base['esercizio']->id,
        'tipo_documento' => 'nota_credito', 'numero_documento' => $numero ?? ('NC-' . uniqid()), 'data_documento' => '2026-09-01',
        'data_scadenza' => '2026-09-30', 'is_pregresso' => false, 'importo_imponibile' => -$totale, 'importo_iva' => 0,
        'importo_ritenuta' => 0, 'totale_documento' => -$totale, 'netto_a_pagare' => -$totale, 'stato_pagamento' => 'aperta',
        'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
        'fattura_rettificata_id' => $rettifica?->id,
    ]);
    ncRighe($n->id, array_map(fn ($r) => array_merge($r, ['importo' => -$r['importo'], 'is_sopravvenienza' => false]), $righe));

    return $n;
}

/** Il piano da fatture sulla fattura, generato e portato al grado chiesto: bozza, approvato, emesso, incassato. */
function ncPiano(array $base, FatturaPassiva $fattura, int $importoCollegato, string $grado = 'approvato', string $nome = 'Cornicione 2026'): PianoRate
{
    $piano = PianoRate::create(['gestione_id' => $base['gestione']->id, 'condominio_id' => $base['condominio']->id, 'nome' => $nome, 'stato' => 'bozza', 'tipo' => 'straordinario']);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => $importoCollegato]);
    app(GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'Piano di prova della Coda 165');

    $quotaId = DB::table('rate_quote')->join('rate', 'rate_quote.rata_id', '=', 'rate.id')->where('rate.piano_rate_id', $piano->id)->orderBy('rate_quote.id')->value('rate_quote.id');
    expect($quotaId)->not->toBeNull('il piano non ha generato quote: lo scenario non è quello che credo');
    // Una scrittura d'emissione vera: la fattura scritta a mano non ne ha, e una quota «emessa» è una quota a giornale.
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $base['condominio']->id, 'gestione_id' => $base['gestione']->id, 'esercizio_id' => $base['esercizio']->id,
        'data_registrazione' => '2026-05-05', 'data_competenza' => '2026-05-05', 'numero_protocollo' => 'EM-' . uniqid(),
        'causale' => 'Emissione rata', 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now(),
    ]);
    match ($grado) {
        'bozza' => null,
        'approvato' => $piano->update(['stato' => 'approvato']),
        'emesso' => DB::table('rate_quote')->where('id', $quotaId)->update(['scrittura_contabile_id' => $scritturaId]),
        'incassato' => DB::table('rate_quote')->where('id', $quotaId)->update(['scrittura_contabile_id' => $scritturaId, 'importo_pagato' => 10000]),
    };
    if (in_array($grado, ['emesso', 'incassato'], true)) {
        $piano->update(['stato' => 'approvato']);
    }

    return $piano->fresh();
}

function ncTot(PianoRate $piano): int
{
    return (int) DB::table('rate_quote')->join('rate', 'rate_quote.rata_id', '=', 'rate.id')->where('rate.piano_rate_id', $piano->id)->sum('rate_quote.importo');
}

function ncUtente(): App\Models\User
{
    $permesso = Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $u = App\Models\User::factory()->create();
    $u->assignRole($ruolo);

    return $u;
}

/** Il corpo della richiesta di registrazione di una nota di credito (importi in euro, magnitudini). */
function ncCorpoNota(array $base, ?int $fatturaRettificataId, float $euro, ?int $contoId = null, array $extra = []): array
{
    return array_replace_recursive(datiBase([$base['condominio'], $base['esercizio'], $base['gestione'], $base['fornitore']], [
        'tipo_documento' => 'nota_credito', 'numero_documento' => 'NC-' . uniqid(), 'stato_approvazione' => 'approvata',
        'applica_ritenuta' => false, 'fattura_rettificata_id' => $fatturaRettificataId,
        'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo'], 'competenza' => null, 'override_budget' => null],
        'righe' => [['descrizione' => 'Rettifica', 'importo_imponibile' => $euro, 'aliquota_iva' => 0, 'importo_iva' => 0, 'conto_id' => $contoId ?? $base['capitolo']->id, 'is_sopravvenienza' => false]],
    ]), $extra);
}

function ncCarrello($test, App\Models\User $u, array $base): Illuminate\Support\Collection
{
    $url = route('admin.gestionale.fetch-fatture-straordinarie', $base['condominio']->id) . '?esercizio_id=' . $base['esercizio']->id . '&gestione_id=' . $base['gestione']->id;

    return collect($test->actingAs($u)->getJson($url)->assertOk()->json())->keyBy('id');
}

function ncCorpoPiano(array $base, array $fatture, string $nome = 'Cornicione bis'): array
{
    return [
        'gestione_id' => $base['gestione']->id, 'nome' => $nome, 'tipo' => 'straordinario',
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera di prova', 'data_delibera_assemblea' => '2026-03-01',
        'fatture_config' => $fatture, 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2, 'giorno_scadenza' => 10,
        'capitoli_ids' => [], 'genera_subito' => true, 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova della Coda 165',
    ];
}

// =============================================================================
// LA COLONNA E LA REGISTRAZIONE
// =============================================================================

test('Coda 165 [beta.36] — la nota registrata dal modulo porta la fattura che rettifica, riletta dal database', function () {
    $base = ncBase();
    $fattura = ncFatturaFuoriPreventivo($base);

    $this->actingAs(ncUtente())
        ->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNota($base, $fattura->id, 300))
        ->assertSessionHasNoErrors();

    $nota = FatturaPassiva::where('tipo_documento', 'nota_credito')->firstOrFail();
    expect(DB::table('fatture_passive')->where('id', $nota->id)->value('fattura_rettificata_id'))->toBe($fattura->id)
        ->and($fattura->fresh()->noteCollegate->pluck('id')->all())->toBe([$nota->id]);
});

test('Coda 165 [beta.36] — una nota collegata a una fattura in un piano che non ha incassato niente non si registra: il motivo nomina il piano e dice la via', function (string $grado, string $via, float $euro) {
    $base = ncBase();
    $fattura = ncFatturaFuoriPreventivo($base);
    ncPiano($base, $fattura, 100000, $grado);

    $this->actingAs(ncUtente())
        ->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNota($base, $fattura->id, $euro))
        ->assertSessionHasErrors('fattura_rettificata_id');
    $motivo = session('errors')->first('fattura_rettificata_id');

    expect($motivo)->toContain('«Cornicione 2026»')->toContain($via)
        ->and(FatturaPassiva::where('tipo_documento', 'nota_credito')->count())->toBe(0)
        // Il modulo mostra lo stesso motivo che applica il server: è la stessa funzione.
        ->and($fattura->fresh()->motivoBloccoNotaCollegata((int) round($euro * 100)))->toBe($motivo);
})->with([
    'in bozza, nota totale' => ['bozza', 'Elimina prima il piano', 1000],
    'approvato, nota parziale' => ['approvato', 'Riporta il piano in bozza ed eliminalo', 300],
    'con rate emesse, nota parziale' => ['emesso', 'Annulla le emissioni', 300],
]);

test('Coda 165 [beta.36] — con rate già incassate la nota collegata si registra, l\'avviso dice che le rate restano e di quanto chiedono in più, e il piano non cambia', function () {
    $base = ncBase();
    $fattura = ncFatturaFuoriPreventivo($base);
    $piano = ncPiano($base, $fattura, 100000, 'incassato');

    $righe = [['conto_id' => $base['capitolo']->id, 'immobile_id' => null, 'riduzione' => 30000]];
    expect($fattura->fresh()->motivoBloccoNotaCollegata(30000, 'registra la nota', $righe))->toBeNull()
        ->and($fattura->fresh()->avvisoNotaCollegata($righe))->toContain('«Cornicione 2026»')->toContain('restano')->toContain('€ 300,00');

    // Senza la conferma il servizio non registra: la chiede con l'avviso (R3, R7 della Fase 1-bis).
    $this->actingAs(ncUtente())
        ->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNota($base, $fattura->id, 300))
        ->assertSessionHasErrors('avviso_nota');
    expect(FatturaPassiva::where('tipo_documento', 'nota_credito')->count())->toBe(0)
        ->and(session('errors')->first('avviso_nota'))->toContain('€ 300,00');

    $this->actingAs(ncUtente())
        ->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNota($base, $fattura->id, 300, null, ['conferma_avviso_nota' => true]))
        ->assertSessionHasNoErrors();

    expect(FatturaPassiva::where('tipo_documento', 'nota_credito')->value('fattura_rettificata_id'))->toBe($fattura->id)
        ->and(ncTot($piano))->toBe(100000);
});

test('Coda 165 [beta.36] — il campo è solo della nota, e solo verso una fattura dello stesso fornitore e condominio', function () {
    $base = ncBase();
    $fattura = ncFatturaFuoriPreventivo($base);
    $altroFornitore = App\Models\Fornitore::create(['ragione_sociale' => 'Altra impresa', 'partita_iva' => '09876543210']);
    $altrui = FatturaPassiva::create(array_merge($fattura->only(['condominio_id', 'esercizio_id', 'tipo_documento', 'data_documento', 'data_scadenza', 'importo_imponibile', 'importo_iva', 'importo_ritenuta', 'totale_documento', 'netto_a_pagare', 'stato_pagamento', 'stato_approvazione', 'modalita_pagamento']), ['fornitore_id' => $altroFornitore->id, 'numero_documento' => 'ALT-1', 'is_pregresso' => false]));
    $u = ncUtente();

    // Una fattura (non una nota) che dichiara una fattura rettificata.
    $corpoFattura = ncCorpoNota($base, $fattura->id, 300, null, ['tipo_documento' => 'fattura', 'dati_extra' => ['override_budget' => null]]);
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), $corpoFattura)
        ->assertSessionHasErrors('fattura_rettificata_id');

    // Una nota che punta alla fattura di un altro fornitore.
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNota($base, $altrui->id, 300))
        ->assertSessionHasErrors('fattura_rettificata_id');

    expect(FatturaPassiva::where('tipo_documento', 'nota_credito')->count())->toBe(0);
});

test('Coda 165 [beta.36] — una nota non si collega a una fattura già stornata, né oltre il totale della fattura', function () {
    $base = ncBase();
    $u = ncUtente();

    $stornata = ncFatturaFuoriPreventivo($base);
    $stornata->update(['stato_pagamento' => 'stornata', 'dati_extra' => ['is_stornata' => true]]);
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNota($base, $stornata->id, 300))
        ->assertSessionHasErrors('fattura_rettificata_id');

    $fattura = ncFatturaFuoriPreventivo($base);
    ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'importo' => 80000]]);
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNota($base, $fattura->id, 300))
        ->assertSessionHasErrors('fattura_rettificata_id');
    expect(session('errors')->first('fattura_rettificata_id'))->toContain('€ 1.000,00');

    // Fino al totale si può: 800 + 200 = 1.000.
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNota($base, $fattura->id, 200))
        ->assertSessionHasNoErrors();
});

// =============================================================================
// COLLEGA E SCOLLEGA, DOPO
// =============================================================================

test('Coda 165 [beta.36] — «Collega a una fattura» su una nota già registrata segue la stessa scala', function (string $grado, bool $passa) {
    $base = ncBase();
    $fattura = ncFatturaFuoriPreventivo($base);
    ncPiano($base, $fattura, 100000, $grado);
    $nota = ncNota($base, null, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]]);

    $risposta = $this->actingAs(ncUtente())->post(
        route('admin.gestionale.fatture.collega-fattura', [$base['condominio']->id, $nota->id]),
        ['fattura_rettificata_id' => $fattura->id]
    );

    if ($passa) {
        // Con incassi il collegamento chiede la conferma della finestra, poi passa (R3 della Fase 1-bis).
        $risposta->assertSessionHasErrors('avviso_nota');
        expect($nota->fresh()->fattura_rettificata_id)->toBeNull();
        $this->actingAs(ncUtente())->post(
            route('admin.gestionale.fatture.collega-fattura', [$base['condominio']->id, $nota->id]),
            ['fattura_rettificata_id' => $fattura->id, 'conferma_avviso_nota' => true]
        )->assertSessionHasNoErrors();
        expect($nota->fresh()->fattura_rettificata_id)->toBe($fattura->id);
    } else {
        $risposta->assertSessionHasErrors('collega_vietato');
        expect(session('errors')->first('collega_vietato'))->toContain('«Cornicione 2026»')
            ->and($nota->fresh()->fattura_rettificata_id)->toBeNull();
    }
})->with([
    'in bozza' => ['bozza', false],
    'approvato' => ['approvato', false],
    'con rate emesse' => ['emesso', false],
    'con incassi' => ['incassato', true],
]);

test('Coda 165 [beta.36] — nessuna deduzione: una nota con importo e data compatibili resta scollegata finché qualcuno non la collega', function () {
    $base = ncBase();
    $fattura = ncFatturaFuoriPreventivo($base);
    $nota = ncNota($base, null, [['conto_id' => $base['capitolo']->id, 'importo' => 100000]]);

    $this->actingAs(ncUtente())->get(route('admin.gestionale.fatture.index', $base['condominio']->id))->assertOk();

    expect($nota->fresh()->fattura_rettificata_id)->toBeNull()
        ->and($fattura->fresh()->noteCollegate)->toHaveCount(0);
});

test('Coda 165 [beta.36] — la nota nata da uno storno interno non si collega: ha già il suo legame', function () {
    $base = ncBase();
    $fattura = ncFatturaFuoriPreventivo($base);
    $u = ncUtente();
    $this->actingAs($u)->post(route('admin.gestionale.fatture.storno', [$base['condominio']->id, $fattura->id]))->assertSessionHasNoErrors();
    $notaStorno = FatturaPassiva::where('tipo_documento', 'nota_credito')->firstOrFail();
    $altra = ncFatturaFuoriPreventivo($base);

    expect($notaStorno->motivoBloccoCollegamento())->not->toBeNull();
    $this->actingAs($u)->post(route('admin.gestionale.fatture.collega-fattura', [$base['condominio']->id, $notaStorno->id]), ['fattura_rettificata_id' => $altra->id])
        ->assertSessionHasErrors('collega_vietato');
    expect($notaStorno->fresh()->fattura_rettificata_id)->toBeNull();
});

test('Coda 165 [beta.36] — «Scollega» si può sempre, anche con un piano che ha incassato', function () {
    $base = ncBase();
    $fattura = ncFatturaFuoriPreventivo($base);
    ncPiano($base, $fattura, 100000, 'incassato');
    $nota = ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]]);

    $this->actingAs(ncUtente())->post(route('admin.gestionale.fatture.scollega-fattura', [$base['condominio']->id, $nota->id]))
        ->assertSessionHasNoErrors();

    expect($nota->fresh()->fattura_rettificata_id)->toBeNull();
});

// =============================================================================
// GLI INCROCI: STORNO, ELIMINAZIONE, MODIFICA
// =============================================================================

test('Coda 165 [beta.36] — lo storno di una fattura con una nota collegata si rifiuta e nomina la nota: il credito verso il fornitore conterebbe due volte', function () {
    $base = ncBase();
    $fattura = ncFatturaFuoriPreventivo($base);
    $nota = ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]], 'NC-77');

    $this->actingAs(ncUtente())->post(route('admin.gestionale.fatture.storno', [$base['condominio']->id, $fattura->id]))
        ->assertSessionHasErrors('storno_vietato');

    expect(session('errors')->first('storno_vietato'))->toContain('NC-77')
        ->and($fattura->fresh()->stato_pagamento->value)->toBe('aperta')
        ->and($fattura->fresh()->motivoBloccoStorno())->toContain('NC-77');
});

test('Coda 165 [beta.36] — con due note collegate i motivi di storno ed eliminazione le nominano al plurale (visto a video)', function () {
    $base = ncBase();
    $fattura = ncFatturaFuoriPreventivo($base);
    ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'importo' => 5000]], 'NC-81');
    ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'importo' => 6100]], 'NC-82');
    $f = $fattura->fresh();

    expect($f->motivoBloccoStorno())->toContain('le note di credito n. NC-81 e n. NC-82 del fornitore collegate')
        ->and($f->motivoBloccoStorno())->toContain('elimina prima le note')
        ->and($f->motivoBloccoEliminazione())->toContain('collegate: eliminandola le note resterebbero')
        ->and($f->motivoBloccoEliminazione())->not->toContain('collegata');
});

test('Coda 165 [beta.36] — l\'eliminazione di una fattura con una nota collegata si rifiuta: la nota resterebbe scollegata in silenzio', function () {
    $base = ncBase();
    $fattura = ncFatturaFuoriPreventivo($base);
    ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]], 'NC-78');

    $this->actingAs(ncUtente())->delete(route('admin.gestionale.fatture.destroy', [$base['condominio']->id, $fattura->id]));

    expect(FatturaPassiva::find($fattura->id))->not->toBeNull()
        ->and($fattura->fresh()->motivoBloccoEliminazione())->toContain('NC-78');
});

test('Coda 165 [beta.36] — cambiare l\'importo di una nota collegata segue la scala; eliminarla è libero e la fattura torna com\'era', function () {
    $base = ncBase();
    $u = ncUtente();
    $fattura = ncFatturaFuoriPreventivo($base);
    // La nota si registra prima del piano (nessun piano: passa), poi il piano nasce sul netto.
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNota($base, $fattura->id, 300))
        ->assertSessionHasNoErrors();
    $nota = FatturaPassiva::where('tipo_documento', 'nota_credito')->firstOrFail();
    ncPiano($base, $fattura, 70000, 'approvato');

    $corpo = ncCorpoNota($base, $fattura->id, 400, null, ['numero_documento' => $nota->numero_documento]);
    unset($corpo['tipo_documento'], $corpo['stato_approvazione'], $corpo['fattura_rettificata_id']);
    $this->actingAs($u)->put(route('admin.gestionale.fatture.update', [$base['condominio']->id, $nota->id]), $corpo)
        ->assertSessionHasErrors('fattura_rettificata_id');
    expect((int) $nota->fresh()->totale_documento)->toBe(-30000);

    $this->actingAs($u)->delete(route('admin.gestionale.fatture.destroy', [$base['condominio']->id, $nota->id]));
    expect(FatturaPassiva::find($nota->id))->toBeNull()
        ->and($fattura->fresh()->noteCollegate)->toHaveCount(0);
});

// =============================================================================
// IL CARRELLO
// =============================================================================

test('Coda 165 [beta.36] — il carrello: la fattura annullata per intero da una nota collegata sparisce, quella rettificata in parte si offre al netto', function () {
    $base = ncBase();
    $u = ncUtente();
    $annullata = ncFatturaFuoriPreventivo($base, 100000, 'FT-A');
    $parziale = ncFatturaFuoriPreventivo($base, 100000, 'FT-B');

    $prima = ncCarrello($this, $u, $base);
    expect($prima->has($annullata->id))->toBeTrue('il carrello non offriva la fattura nemmeno prima: lo scenario non è quello che credo')
        ->and($prima->has($parziale->id))->toBeTrue();

    ncNota($base, $annullata, [['conto_id' => $base['capitolo']->id, 'importo' => 100000]], 'NC-TOT');
    ncNota($base, $parziale, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]], 'NC-PAR');
    $voci = ncCarrello($this, $u, $base);

    expect($voci->has($annullata->id))->toBeFalse()
        ->and((float) $voci[$parziale->id]['totale_straordinario'])->toEqual(1000.0)
        ->and((float) $voci[$parziale->id]['residuo_da_finanziare'])->toEqual(700.0)
        ->and((float) $voci[$parziale->id]['importo_suggerito'])->toEqual(700.0)
        ->and($voci[$parziale->id]['note_collegate'][0]['numero'])->toBe('NC-PAR');
});

test('Coda 165 [beta.36] — carrello con due fatture correnti, una ad personam e una pregressa, una sola rettificata: tutte offerte, solo quella al netto (lezione 8 della beta.35)', function () {
    $base = ncBase();
    $u = ncUtente();
    $prima = ncFatturaFuoriPreventivo($base, 50000);
    $seconda = ncFatturaFuoriPreventivo($base, 30000);
    $adPersonam = ncFattura($base, [['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => 12000]]);
    $pregressa = (new FatturaPassivaService())->registraFattura(datiBase([$base['condominio'], $base['esercizio'], $base['gestione'], $base['fornitore']], [
        'is_pregresso' => true, 'imponibile_pregresso' => 1000.00, 'aliquota_iva_pregressa' => 0, 'coperture' => [],
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => [
            'origine_decisionale' => 'gestione_corrente', 'motivazione_sforo' => 'Debito pregresso', 'tipo_ripartizione' => 'millesimale',
            'nome_voce' => 'Debito Pregresso Straordinario', 'tabella_millesimale_id' => $base['tabella']->id, 'percentuale_proprietario' => 100,
        ]],
    ]), $base['condominio']->id);
    ncNota($base, $prima, [['conto_id' => $base['capitolo']->id, 'importo' => 20000]]);

    $voci = ncCarrello($this, $u, $base);

    expect((float) $voci[$prima->id]['residuo_da_finanziare'])->toEqual(300.0)
        ->and((float) $voci[$seconda->id]['residuo_da_finanziare'])->toEqual(300.0)
        ->and($voci[$seconda->id]['note_collegate'])->toBe([])
        ->and((float) $voci[$adPersonam->id]['residuo_da_finanziare'])->toEqual(120.0)
        ->and((float) $voci[$pregressa->id]['residuo_da_finanziare'])->toEqual(1000.0)
        ->and($voci[$pregressa->id]['is_pregresso'])->toBeTrue()
        ->and($voci[$prima->id]['is_pregresso'])->toBeFalse();
});

test('Coda 165 [beta.36] — fattura mista: la voce della riga della nota dice quale parte riduce, e la fattura non chiede mai più del documento al netto', function (string $dove, int $magnitudine, float $atteso) {
    $base = ncBase();
    $u = ncUtente();
    $imprevisti = ncAltroConto($base, 'Riparazioni impreviste');
    $estranea = ncAltroConto($base, 'Voce estranea');
    // € 300,00 a preventivo sul capitolo, € 700,00 fuori preventivo su «Riparazioni impreviste».
    $fattura = ncFattura($base, [
        ['conto_id' => $base['capitolo']->id, 'importo' => 30000, 'is_sopravvenienza' => false],
        ['conto_id' => $imprevisti->id, 'importo' => 70000, 'is_sopravvenienza' => true],
    ]);
    $conto = match ($dove) { 'fuori preventivo' => $imprevisti->id, 'a preventivo' => $base['capitolo']->id, 'estranea' => $estranea->id };
    ncNota($base, $fattura, [['conto_id' => $conto, 'importo' => $magnitudine]]);

    expect((float) ncCarrello($this, $u, $base)[$fattura->id]['residuo_da_finanziare'])->toEqual($atteso);
})->with([
    'sulla voce fuori preventivo' => ['fuori preventivo', 10000, 600.0],
    'sulla voce a preventivo: il credito lo registra già il giornale' => ['a preventivo', 10000, 700.0],
    'su una voce estranea che il preventivo assorbe' => ['estranea', 10000, 700.0],
    'su una voce estranea oltre la parte a preventivo: mai più del documento al netto' => ['estranea', 40000, 600.0],
]);

test('Coda 165 [beta.36] — una nota di credito non entra mai nel carrello, neanche con una riga ad personam positiva', function () {
    $base = ncBase();
    $u = ncUtente();
    $nota = ncNota($base, null, [['conto_id' => $base['capitolo']->id, 'importo' => 20000]]);
    ncRighe($nota->id, [['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => 5000]]);

    expect(ncCarrello($this, $u, $base)->has($nota->id))->toBeFalse();
    $this->actingAs($u)->post(route('admin.gestionale.esercizi.piani-rate.store', [$base['condominio']->id, $base['esercizio']->id]),
        ncCorpoPiano($base, [['id' => $nota->id, 'importo' => '50,00']]))
        ->assertSessionHasErrors('fatture_config.0.id');
});

// =============================================================================
// LA CREAZIONE DEL PIANO E IL RICALCOLO
// =============================================================================

test('Coda 165 [beta.36] — creare un piano con una fattura annullata per intero, o con un importo sopra il netto, si rifiuta sull\'indice giusto', function () {
    $base = ncBase();
    $u = ncUtente();
    $annullata = ncFatturaFuoriPreventivo($base, 100000, 'FT-TOT');
    ncNota($base, $annullata, [['conto_id' => $base['capitolo']->id, 'importo' => 100000]], 'NC-TOT');
    $parziale = ncFatturaFuoriPreventivo($base, 100000, 'FT-PAR');
    ncNota($base, $parziale, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]], 'NC-PAR');
    $pulita = ncFatturaFuoriPreventivo($base, 50000, 'FT-OK');
    $rotta = route('admin.gestionale.esercizi.piani-rate.store', [$base['condominio']->id, $base['esercizio']->id]);

    $this->actingAs($u)->post($rotta, ncCorpoPiano($base, [['id' => $annullata->id, 'importo' => '1000,00']], 'P1'))
        ->assertSessionHasErrors('fatture_config.0.id');
    expect(session('errors')->first('fatture_config.0.id'))->toContain('NC-TOT');

    $this->actingAs($u)->post($rotta, ncCorpoPiano($base, [['id' => $pulita->id, 'importo' => '500,00'], ['id' => $parziale->id, 'importo' => '1000,00']], 'P2'))
        ->assertSessionHasErrors('fatture_config.1.importo')
        ->assertSessionDoesntHaveErrors('fatture_config.0.importo');
    expect(session('errors')->first('fatture_config.1.importo'))->toContain('€ 300,00');

    expect(PianoRate::whereIn('nome', ['P1', 'P2'])->count())->toBe(0);
});

test('Coda 165 [beta.36] — un piano fatto dal carrello DOPO la nota si genera e si ricalcola: la guardia non è «la fattura ha una nota»', function () {
    $base = ncBase();
    $u = ncUtente();
    $fattura = ncFatturaFuoriPreventivo($base);
    ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]]);

    $this->actingAs($u)->post(route('admin.gestionale.esercizi.piani-rate.store', [$base['condominio']->id, $base['esercizio']->id]),
        ncCorpoPiano($base, [['id' => $fattura->id, 'importo' => '700,00']], 'Sul netto'))
        ->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Sul netto')->firstOrFail();
    expect(ncTot($piano))->toBe(70000);

    $this->actingAs($u)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$base['condominio']->id, $base['esercizio']->id, $piano->id]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova della Coda 165',
    ])->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) !== 'error');
    expect(ncTot($piano->fresh()))->toBe(70000);
});

test('Coda 165 [beta.36] — un piano che chiede più della fattura al netto delle note non si ricalcola: si ferma, nomina fattura e nota, dice di quanto, e le quote restano', function () {
    $base = ncBase();
    $u = ncUtente();
    $fattura = ncFatturaFuoriPreventivo($base, 100000, 'FT-CORN');
    $piano = ncPiano($base, $fattura, 100000, 'bozza');
    // Dati già a database: una nota collegata dopo (per esempio prima di questa versione, o dopo aver annullato gli
    // incassi di un piano che li aveva).
    ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]], 'NC-CORN');

    $this->actingAs($u)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$base['condominio']->id, $base['esercizio']->id, $piano->id]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova della Coda 165',
    ])->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'error'
        && str_contains($m['message'], 'FT-CORN') && str_contains($m['message'], 'NC-CORN') && str_contains($m['message'], '€ 300,00'));

    expect(ncTot($piano->fresh()))->toBe(100000);
});

// =============================================================================
// IL MOTORE
// =============================================================================

test('Coda 165 [beta.36] — una nota su un\'unità precisa toglie la quota a quell\'unità, e con importo_collegato a zero il piano chiede il netto', function (int $importoCollegato) {
    $base = ncBase();
    // € 300,00 addebitati all'unità, € 700,00 fuori preventivo sul capitolo.
    $fattura = ncFattura($base, [
        ['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => 30000],
        ['conto_id' => $base['capitolo']->id, 'importo' => 70000, 'is_sopravvenienza' => true],
    ]);
    ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => 30000]]);
    $piano = PianoRate::create(['gestione_id' => $base['gestione']->id, 'condominio_id' => $base['condominio']->id, 'nome' => 'Motore', 'stato' => 'bozza', 'tipo' => 'straordinario']);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => $importoCollegato]);

    $servizio = new CalcoloQuoteService();
    $totali = $servizio->calcolaDaFattureStraordinarie($piano->fresh(), soloLettura: true);
    $somma = 0;
    foreach ($totali as $perImmobile) {
        foreach ($perImmobile as $importo) {
            $somma += $importo;
        }
    }

    expect($somma)->toBe(70000)
        ->and((int) collect($servizio->getAddebitiDiretti())->where('immobile_id', $base['immobileId'])->sum('importo'))->toBe(0);
})->with(['importo collegato sul netto' => [70000], 'importo collegato a zero (tutto)' => [0]]);

// =============================================================================
// IL CRUSCOTTO
// =============================================================================

test('Coda 165 [beta.36] — il cruscotto non mette fra le fatture in sospeso quella annullata per intero da una nota collegata, e mostra al netto quella rettificata in parte', function () {
    $base = ncBase();
    $u = ncUtente();
    $annullata = ncFatturaFuoriPreventivo($base);
    $parziale = ncFatturaFuoriPreventivo($base);
    $leggi = fn () => collect($this->actingAs($u)->get(route('admin.gestionale.index', $base['condominio']->id))->assertOk()->viewData('page')['props']['fattureScoperte'])->keyBy('id');

    expect($leggi()->has($annullata->id))->toBeTrue('il cruscotto non la mostrava nemmeno prima: lo scenario non è quello che credo');

    ncNota($base, $annullata, [['conto_id' => $base['capitolo']->id, 'importo' => 100000]]);
    ncNota($base, $parziale, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]]);
    $dopo = $leggi();

    expect($dopo->has($annullata->id))->toBeFalse()
        ->and($dopo[$parziale->id]['totale_scoperto'])->toBe(70000);
});

// =============================================================================
// L'XML
// =============================================================================

/** La nota di credito sintetica, con i blocchi `DatiFattureCollegate` dati. */
function ncXmlNota(array $collegate): UploadedFile
{
    $xml = file_get_contents(base_path('tests/Fixtures/fatturapa/sintetica_nota_credito.xml'));
    $blocchi = '';
    foreach ($collegate as [$numero, $data]) {
        $blocchi .= '<DatiFattureCollegate><IdDocumento>' . $numero . '</IdDocumento>' . ($data ? '<Data>' . $data . '</Data>' : '') . '</DatiFattureCollegate>';
    }
    $xml = str_replace('</DatiGeneraliDocumento>', '</DatiGeneraliDocumento>' . $blocchi, $xml);

    return UploadedFile::fake()->createWithContent('nota.xml', $xml);
}

test('Coda 165 [beta.36] — dall\'XML la fattura che la nota rettifica si propone solo con numero e data che la trovano senza dubbi', function () {
    $base = ncBase();
    $u = ncUtente();
    DB::table('fornitori')->where('id', $base['fornitore']->id)->update(['partita_iva' => '01234567890']);
    $fattura = ncFatturaFuoriPreventivo($base, 100000, 'FT-28');
    DB::table('fatture_passive')->where('id', $fattura->id)->update(['data_documento' => '2025-12-22']);
    $rotta = route('admin.gestionale.fatture.importa-xml', $base['condominio']->id);

    $trovata = $this->actingAs($u)->postJson($rotta, ['file' => ncXmlNota([['FT-28', '2025-12-22']])])->assertOk()->json('fattura_rettificata');
    expect($trovata['esito'])->toBe('proposta')
        ->and($trovata['proposta']['id'])->toBe($fattura->id)
        ->and($trovata['dichiarate'][0]['numero'])->toBe('FT-28');

    $senzaData = $this->actingAs($u)->postJson($rotta, ['file' => ncXmlNota([['FT-28', null]])])->assertOk()->json('fattura_rettificata');
    expect($senzaData['proposta'])->toBeNull()
        ->and($senzaData['esito'])->toBe('senza_data');

    $due = $this->actingAs($u)->postJson($rotta, ['file' => ncXmlNota([['FT-28', '2025-12-22'], ['FT-29', '2025-12-23']])])->assertOk()->json('fattura_rettificata');
    expect($due['esito'])->toBe('piu_dichiarate')
        ->and($due['proposta'])->toBeNull()
        ->and($due['dichiarate'])->toHaveCount(2);
});

test('Coda 165 [beta.36] — dall\'XML non si propone quando numero e data trovano due fatture (il numero si confronta senza maiuscole né spazi)', function () {
    $base = ncBase();
    $u = ncUtente();
    DB::table('fornitori')->where('id', $base['fornitore']->id)->update(['partita_iva' => '01234567890']);
    $prima = ncFatturaFuoriPreventivo($base, 100000, 'FT-28');
    $seconda = ncFatturaFuoriPreventivo($base, 50000, 'ft-28 ');
    DB::table('fatture_passive')->whereIn('id', [$prima->id, $seconda->id])->update(['data_documento' => '2025-12-22']);

    $esito = $this->actingAs($u)->postJson(route('admin.gestionale.fatture.importa-xml', $base['condominio']->id), ['file' => ncXmlNota([['FT-28', '2025-12-22']])])
        ->assertOk()->json('fattura_rettificata');

    expect($esito['proposta'])->toBeNull()
        ->and($esito['esito'])->toBe('ambigua');
});

// =============================================================================
// FASE 1-BIS — i rilievi confermati (R1–R8), un test ciascuno
// =============================================================================

/** Una seconda unità con proprietario e 1.000 millesimi nella tabella della base. */
function ncSecondaUnita(array $base): int
{
    $imm = DB::table('immobili')->insertGetId([
        'condominio_id' => $base['condominio']->id, 'interno' => '2', 'nome' => 'Appartamento 2', 'descrizione' => '',
        'codice_immobile' => 'APP2-' . $base['condominio']->id, 'attivo' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('quote_tabella')->insert(['tabella_id' => $base['tabella']->id, 'immobile_id' => $imm, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $anagrafica = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $anagrafica->id, 'immobile_id' => $imm, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);

    return $imm;
}

/** La pregressa tutta «sopravvenienza», registrata dal servizio come nel prodotto. */
function ncPregressa(array $base, float $euro = 1000.00, string $numero = 'FT-28'): FatturaPassiva
{
    return (new FatturaPassivaService())->registraFattura(datiBase([$base['condominio'], $base['esercizio'], $base['gestione'], $base['fornitore']], [
        'numero_documento' => $numero, 'is_pregresso' => true, 'imponibile_pregresso' => $euro, 'aliquota_iva_pregressa' => 0, 'coperture' => [],
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => [
            'origine_decisionale' => 'gestione_corrente', 'motivazione_sforo' => 'Debito pregresso', 'tipo_ripartizione' => 'millesimale',
            'nome_voce' => 'Debito Pregresso Straordinario', 'tabella_millesimale_id' => $base['tabella']->id, 'percentuale_proprietario' => 100,
        ]],
    ]), $base['condominio']->id);
}

/** Il corpo della nota pregressa: niente righe, imponibile e aliquota del pannello, come il modulo. */
function ncCorpoNotaPregressa(array $base, int $fatturaId, float $euro, array $extra = []): array
{
    return array_replace_recursive(datiBase([$base['condominio'], $base['esercizio'], $base['gestione'], $base['fornitore']], [
        'tipo_documento' => 'nota_credito', 'numero_documento' => 'NC-' . uniqid(), 'stato_approvazione' => 'approvata',
        'applica_ritenuta' => false, 'is_pregresso' => true, 'imponibile_pregresso' => $euro, 'aliquota_iva_pregressa' => 0, 'coperture' => [],
        'fattura_rettificata_id' => $fatturaId,
        'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo'], 'competenza' => null, 'override_budget' => null],
    ]), $extra);
}

test('R1 [1-bis] — la nota pregressa (senza righe) collegata vale la sua testata: la fattura annullata esce dal carrello, e una seconda nota si ferma al tetto', function () {
    $base = ncBase();
    $u = ncUtente();
    $pregressa = ncPregressa($base);
    expect((float) ncCarrello($this, $u, $base)[$pregressa->id]['residuo_da_finanziare'])->toEqual(1000.0);

    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNotaPregressa($base, $pregressa->id, 1000))
        ->assertSessionHasNoErrors();
    $nota = FatturaPassiva::where('tipo_documento', 'nota_credito')->firstOrFail();
    expect(DB::table('righe_fattura')->where('fattura_passiva_id', $nota->id)->count())->toBe(0, 'lo scenario: la nota pregressa non ha righe')
        ->and(NettoNoteCollegate::perFattura($pregressa->id)['netto'])->toBe(0)
        ->and(ncCarrello($this, $u, $base)->has($pregressa->id))->toBeFalse();

    // Il tetto del totale la vede: una seconda nota pregressa, dal modulo e da «Collega», si ferma.
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNotaPregressa($base, $pregressa->id, 1000))
        ->assertSessionHasErrors('fattura_rettificata_id');
    expect(session('errors')->first('fattura_rettificata_id'))->toContain('oltre il suo totale di € 1.000,00');
    // Il tetto lo vede già la richiesta, con l'importo del pannello: insieme agli altri errori del modulo, non dopo.
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNotaPregressa($base, $pregressa->id, 1000, ['numero_documento' => '']))
        ->assertSessionHasErrors(['numero_documento', 'fattura_rettificata_id']);
    $libera = FatturaPassiva::create([
        'condominio_id' => $base['condominio']->id, 'fornitore_id' => $base['fornitore']->id, 'esercizio_id' => $base['esercizio']->id,
        'tipo_documento' => 'nota_credito', 'numero_documento' => 'NC-LIB', 'data_documento' => '2026-01-10', 'data_scadenza' => '2026-02-10',
        'is_pregresso' => true, 'importo_imponibile' => -50000, 'importo_iva' => 0, 'importo_ritenuta' => 0, 'totale_documento' => -50000,
        'netto_a_pagare' => -50000, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
    ]);
    $this->actingAs($u)->post(route('admin.gestionale.fatture.collega-fattura', [$base['condominio']->id, $libera->id]), ['fattura_rettificata_id' => $pregressa->id])
        ->assertSessionHasErrors('collega_vietato');
    expect(session('errors')->first('collega_vietato'))->toContain('oltre il suo totale');
});

test('R1 [1-bis] — la nota pregressa parziale porta la pregressa al netto nel carrello', function () {
    $base = ncBase();
    $u = ncUtente();
    $pregressa = ncPregressa($base);
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNotaPregressa($base, $pregressa->id, 300))
        ->assertSessionHasNoErrors();

    expect((float) ncCarrello($this, $u, $base)[$pregressa->id]['residuo_da_finanziare'])->toEqual(700.0);
});

test('R2 [1-bis] — una nota su un\'unità oltre la sua riga: netto e motore leggono lo stesso numero, e con importo «0,00» il piano chiede il netto', function () {
    $base = ncBase();
    $u = ncUtente();
    $fattura = ncFattura($base, [
        ['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => 10000],
        ['conto_id' => $base['capitolo']->id, 'importo' => 10000, 'is_sopravvenienza' => true],
    ], 'FT-B');
    ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => 15000]], 'NC-B');

    $netto = NettoNoteCollegate::perFattura($fattura->id);
    expect($netto['rettificato_piano'])->toBe(10000, 'la nota non toglie all\'unità più di quanto la fattura le addebita')
        ->and($netto['netto'])->toBe(5000);

    $this->actingAs($u)->post(route('admin.gestionale.esercizi.piani-rate.store', [$base['condominio']->id, $base['esercizio']->id]),
        ncCorpoPiano($base, [['id' => $fattura->id, 'importo' => '0,00']], 'Tutto'))
        ->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Tutto')->firstOrFail();
    expect(ncTot($piano))->toBe(5000)
        ->and($fattura->fresh()->chiestoDaiPiani())->toBe(5000);
});

test('R3 [1-bis] — con un piano parziale che ha incassato, la nota sull\'unità di una riga ad personam avvisa anche se i totali tornano', function () {
    $base = ncBase();
    $u = ncUtente();
    ncSecondaUnita($base);
    $fattura = ncFattura($base, [
        ['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => 30000],
        ['conto_id' => $base['capitolo']->id, 'importo' => 70000, 'is_sopravvenienza' => true],
    ], 'FT-E');
    $piano = ncPiano($base, $fattura, 70000, 'incassato');
    $prima = ncTot($piano);
    $righe = [['conto_id' => null, 'immobile_id' => $base['immobileId'], 'riduzione' => 30000]];

    expect($fattura->fresh()->motivoBloccoNotaCollegata(30000, 'registra la nota', $righe))->toBeNull()
        ->and($fattura->fresh()->avvisoNotaCollegata($righe))->toContain('parte precisa')->toContain('«Cornicione 2026»')
            // Come si sistema di solito: deciso da Vincenzo il 27/09/2026 dopo la ricerca sulla legge.
            ->toContain('conguaglio del consuntivo')->toContain('non fra condòmini');

    $corpo = ncCorpoNota($base, $fattura->id, 300, null, ['righe' => [0 => ['immobile_id' => $base['immobileId']]]]);
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), $corpo)->assertSessionHasErrors('avviso_nota');
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), $corpo + ['conferma_avviso_nota' => true])
        ->assertSessionHasNoErrors();
    expect(ncTot($piano->fresh()))->toBe($prima);
});

test('R4 [1-bis] — la nota sulla parte a preventivo non chiede di restituire niente, e il blocco non inventa una cifra', function () {
    $base = ncBase();
    $imprevisti = ncAltroConto($base, 'Riparazioni impreviste');
    $fattura = ncFattura($base, [
        ['conto_id' => $base['capitolo']->id, 'importo' => 30000, 'is_sopravvenienza' => false],
        ['conto_id' => $imprevisti->id, 'importo' => 70000, 'is_sopravvenienza' => true],
    ], 'FT-M');
    $sulPreventivo = [['conto_id' => $base['capitolo']->id, 'immobile_id' => null, 'riduzione' => 20000]];
    $sulPiano = [['conto_id' => $imprevisti->id, 'immobile_id' => null, 'riduzione' => 20000]];

    $incassato = ncPiano($base, $fattura, 70000, 'incassato');
    $f = $fattura->fresh();
    expect($f->avvisoNotaCollegata($sulPreventivo))->toBeNull()
        ->and($f->avvisoNotaCollegata($sulPiano))->toContain('€ 200,00');
    $incassato->fatture()->detach();

    ncPiano($base, $fattura, 70000, 'bozza', 'In bozza');
    $motivo = $fattura->fresh()->motivoBloccoNotaCollegata(20000, 'registra la nota', $sulPreventivo);
    expect($motivo)->toContain('«In bozza»')->toContain('già calcolate e non si aggiornano da sole')->not->toContain('€ 200,00')
        ->and($fattura->fresh()->motivoBloccoNotaCollegata(20000, 'registra la nota', $sulPiano))->toContain('€ 200,00');
});

test('R5 [1-bis] — aumentare una nota collegata con un piano che ha incassato chiede la conferma; ridurla no', function () {
    $base = ncBase();
    $u = ncUtente();
    $fattura = ncFatturaFuoriPreventivo($base);
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNota($base, $fattura->id, 300))
        ->assertSessionHasNoErrors();
    $nota = FatturaPassiva::where('tipo_documento', 'nota_credito')->firstOrFail();
    $piano = ncPiano($base, $fattura, 70000, 'incassato');
    $modifica = function (float $euro, array $extra = []) use ($base, $nota) {
        $corpo = ncCorpoNota($base, $fattura ?? null, $euro, null, ['numero_documento' => $nota->numero_documento] + $extra);
        unset($corpo['tipo_documento'], $corpo['stato_approvazione'], $corpo['fattura_rettificata_id']);

        return $this->actingAs(ncUtente())->put(route('admin.gestionale.fatture.update', [$base['condominio']->id, $nota->id]), $corpo);
    };

    $modifica(900)->assertSessionHasErrors('avviso_nota');
    expect(session('errors')->first('avviso_nota'))->toContain('€ 600,00')
        ->and((int) $nota->fresh()->totale_documento)->toBe(-30000);

    $modifica(900, ['conferma_avviso_nota' => true])->assertSessionHasNoErrors();
    expect((int) $nota->fresh()->totale_documento)->toBe(-90000)
        ->and(ncTot($piano->fresh()))->toBe(70000);

    $modifica(200)->assertSessionHasNoErrors();
    expect((int) $nota->fresh()->totale_documento)->toBe(-20000);
});

test('R5 [1-bis] — spostare una nota collegata su un\'altra unità, a netto invariato, segue la scala: cambia chi deve pagare', function () {
    $base = ncBase();
    $u = ncUtente();
    $seconda = ncSecondaUnita($base);
    $fattura = ncFattura($base, [
        ['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => 10000],
        ['conto_id' => $base['capitolo']->id, 'immobile_id' => $seconda, 'importo' => 10000],
    ], 'FT-U');
    $corpo = ncCorpoNota($base, $fattura->id, 50, null, ['righe' => [0 => ['immobile_id' => $base['immobileId']]]]);
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), $corpo)->assertSessionHasNoErrors();
    $nota = FatturaPassiva::where('tipo_documento', 'nota_credito')->firstOrFail();
    ncPiano($base, $fattura, 15000, 'bozza');

    $sposta = ncCorpoNota($base, null, 50, null, ['numero_documento' => $nota->numero_documento, 'righe' => [0 => ['immobile_id' => $seconda]]]);
    unset($sposta['tipo_documento'], $sposta['stato_approvazione'], $sposta['fattura_rettificata_id']);
    $this->actingAs($u)->put(route('admin.gestionale.fatture.update', [$base['condominio']->id, $nota->id]), $sposta)
        ->assertSessionHasErrors('fattura_rettificata_id');
    expect(session('errors')->first('fattura_rettificata_id'))->toContain('«Cornicione 2026»');

    // La stessa unità, un'altra data: libera.
    $data = ncCorpoNota($base, null, 50, null, ['numero_documento' => $nota->numero_documento, 'data_documento' => '2026-09-20', 'righe' => [0 => ['immobile_id' => $base['immobileId']]]]);
    unset($data['tipo_documento'], $data['stato_approvazione'], $data['fattura_rettificata_id']);
    $this->actingAs($u)->put(route('admin.gestionale.fatture.update', [$base['condominio']->id, $nota->id]), $data)->assertSessionHasNoErrors();
});

test('R6 [1-bis] — la stampa ricostruita di un piano con le quote non applica una nota collegata dopo: coincide con le quote', function () {
    $base = ncBase();
    $seconda = ncSecondaUnita($base);
    $fattura = ncFattura($base, [
        ['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => 50000],
        ['conto_id' => $base['capitolo']->id, 'importo' => 50000, 'is_sopravvenienza' => true],
    ], 'FT-S');
    $piano = ncPiano($base, $fattura, 100000, 'incassato');
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => 50000]]);

    $stampa = App\Services\Riparto\DettaglioRiparto::perPiano($piano->fresh());
    $perUnitaStampa = collect($stampa['righe'])->groupBy('immobile_id')->map(fn ($r) => (int) $r->sum('importo'))->sortKeys()->all();
    $perUnitaQuote = DB::table('rate_quote')->join('rate', 'rate_quote.rata_id', '=', 'rate.id')->where('rate.piano_rate_id', $piano->id)
        ->groupBy('rate_quote.immobile_id')->selectRaw('rate_quote.immobile_id, SUM(rate_quote.importo) as t')->pluck('t', 'immobile_id')
        ->map(fn ($v) => (int) $v)->sortKeys()->all();

    expect($stampa['fonte']['tipo'])->toBe(App\Services\Riparto\DettaglioRiparto::RICOSTRUITO)
        ->and($perUnitaStampa)->toBe($perUnitaQuote)
        ->and($perUnitaQuote[$seconda] ?? 0)->toBeLessThan(100000);
});

test('Fase 1-bis — una fattura con note collegate non scende, modificandola, sotto quanto le note rettificano', function () {
    $base = ncBase();
    $u = ncUtente();
    $fattura = ncFattura($base, [['conto_id' => $base['capitolo']->id, 'importo' => 100000, 'is_sopravvenienza' => false]], 'FT-P');
    ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]], 'NC-P');

    $corpo = ncCorpoNota($base, null, 200, null, ['numero_documento' => 'FT-P']);
    unset($corpo['tipo_documento'], $corpo['stato_approvazione'], $corpo['fattura_rettificata_id']);
    $this->actingAs($u)->put(route('admin.gestionale.fatture.update', [$base['condominio']->id, $fattura->id]), $corpo)
        ->assertSessionHasErrors('fattura_rettificata_id');
    expect(session('errors')->first('fattura_rettificata_id'))->toContain('€ 300,00')->toContain('meno di quanto rettifica la nota di credito n. NC-P')
        ->and((int) $fattura->fresh()->totale_documento)->toBe(100000);

    $corpo['righe'][0]['importo_imponibile'] = 500;
    $this->actingAs($u)->put(route('admin.gestionale.fatture.update', [$base['condominio']->id, $fattura->id]), $corpo)->assertSessionHasNoErrors();
});

test('Fase 1-bis — i testi: «della nota», «dalla nota», e a un piano in bozza non si dice di riportarlo in bozza', function () {
    $base = ncBase();
    $u = ncUtente();
    $annullata = ncFatturaFuoriPreventivo($base, 100000, 'FT-TOT');
    ncNota($base, $annullata, [['conto_id' => $base['capitolo']->id, 'importo' => 100000]], 'NC-TOT');
    $parziale = ncFatturaFuoriPreventivo($base, 100000, 'FT-PAR');
    ncNota($base, $parziale, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]], 'NC-PAR');
    $rotta = route('admin.gestionale.esercizi.piani-rate.store', [$base['condominio']->id, $base['esercizio']->id]);

    $this->actingAs($u)->post($rotta, ncCorpoPiano($base, [['id' => $annullata->id, 'importo' => '1000,00']], 'P1'));
    expect(session('errors')->first('fatture_config.0.id'))->toContain('annullata per intero dalla nota di credito n. NC-TOT');
    $this->actingAs($u)->post($rotta, ncCorpoPiano($base, [['id' => $parziale->id, 'importo' => '1000,00']], 'P2'));
    expect(session('errors')->first('fatture_config.0.importo'))->toContain('al netto della nota di credito n. NC-PAR');

    $bozza = ncPiano($base, ncFatturaFuoriPreventivo($base, 100000, 'FT-CB'), 100000, 'bozza', 'Solo bozza');
    ncNota($base, FatturaPassiva::where('numero_documento', 'FT-CB')->first(), [['conto_id' => $base['capitolo']->id, 'importo' => 30000]], 'NC-CB');
    $this->actingAs($u)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$base['condominio']->id, $base['esercizio']->id, $bozza->id]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova della Coda 165',
    ])->assertSessionHas('message', fn ($m) => str_contains($m['message'], 'Elimina il piano e crealo di nuovo')
        && ! str_contains($m['message'], 'Riporta il piano in bozza') && str_contains($m['message'], 'al netto della nota di credito n. NC-CB'));
});

test('Fase 1-bis — lo stesso riferimento in due blocchi DatiFattureCollegate è una fattura sola: si propone', function () {
    $base = ncBase();
    $u = ncUtente();
    DB::table('fornitori')->where('id', $base['fornitore']->id)->update(['partita_iva' => '01234567890']);
    $fattura = ncFatturaFuoriPreventivo($base, 100000, 'FT-28');
    DB::table('fatture_passive')->where('id', $fattura->id)->update(['data_documento' => '2025-12-22']);

    $esito = $this->actingAs($u)->postJson(route('admin.gestionale.fatture.importa-xml', $base['condominio']->id), ['file' => ncXmlNota([['FT-28', '2025-12-22'], ['ft-28', '2025-12-22']])])
        ->assertOk()->json('fattura_rettificata');
    expect($esito['esito'])->toBe('proposta')->and($esito['proposta']['id'])->toBe($fattura->id);
});

test('R8 [1-bis] — l\'elenco delle candidate segna la fattura dichiarata dal file a ogni caricamento, anche registrata dopo la lettura del file', function () {
    $base = ncBase();
    $u = ncUtente();
    $url = fn () => route('admin.gestionale.fetch-fatture-rettificabili', $base['condominio']->id)
        . '?fornitore_id=' . $base['fornitore']->id . '&numero_dichiarato=FT-28&data_dichiarata=2025-12-22';

    expect(collect($this->actingAs($u)->getJson($url())->assertOk()->json())->where('corrisponde_al_file', true))->toHaveCount(0);

    $fattura = ncFatturaFuoriPreventivo($base, 100000, 'FT-28');
    DB::table('fatture_passive')->where('id', $fattura->id)->update(['data_documento' => '2025-12-22']);
    $segnate = collect($this->actingAs($u)->getJson($url())->assertOk()->json())->where('corrisponde_al_file', true);
    expect($segnate)->toHaveCount(1)->and($segnate->first()['id'])->toBe($fattura->id);
});

test('R4 [1-bis] — le candidate calcolano motivi e avvisi con le righe della nota: di una nota registrata, o del modulo', function () {
    $base = ncBase();
    $u = ncUtente();
    $imprevisti = ncAltroConto($base, 'Riparazioni impreviste');
    $fattura = ncFattura($base, [
        ['conto_id' => $base['capitolo']->id, 'importo' => 30000, 'is_sopravvenienza' => false],
        ['conto_id' => $imprevisti->id, 'importo' => 70000, 'is_sopravvenienza' => true],
    ], 'FT-M');
    ncPiano($base, $fattura, 70000, 'incassato');
    $notaPreventivo = ncNota($base, null, [['conto_id' => $base['capitolo']->id, 'importo' => 20000]], 'NC-PREV');
    $base_url = route('admin.gestionale.fetch-fatture-rettificabili', $base['condominio']->id) . '?fornitore_id=' . $base['fornitore']->id;

    $perNota = collect($this->actingAs($u)->getJson($base_url . '&per_collegare=1&nota_id=' . $notaPreventivo->id)->assertOk()->json())->keyBy('id');
    expect($perNota[$fattura->id]['avviso_nota'])->toBeNull();

    $righe = json_encode([['conto_id' => $imprevisti->id, 'immobile_id' => null, 'riduzione' => 20000]]);
    $perModulo = collect($this->actingAs($u)->getJson($base_url . '&importo_cents=20000&righe=' . urlencode($righe))->assertOk()->json())->keyBy('id');
    expect($perModulo[$fattura->id]['avviso_nota'])->toContain('€ 200,00');
});

// =============================================================================
// VERIFICA DELLE CORREZIONI DELLA FASE 1-BIS (V1–V6)
// =============================================================================

test('V1 [verifica] — il pavimento vale per unità sul totale: l\'ordine delle righe della nota non cambia chi paga', function (bool $scontoPrima) {
    $base = ncBase();
    $b = ncSecondaUnita($base);
    $fattura = ncFattura($base, [
        ['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => 10000],
        ['conto_id' => $base['capitolo']->id, 'immobile_id' => $b, 'importo' => 90000],
    ], 'FT-V1');
    $rimborso = ['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => 20000];
    $sconto = ['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => -5000];
    ncNota($base, $fattura, $scontoPrima ? [$sconto, $rimborso] : [$rimborso, $sconto]);

    $netto = NettoNoteCollegate::perFattura($fattura->id);
    expect($netto['rettificato_piano'])->toBe(10000)
        ->and($netto['netto'])->toBe(85000)
        ->and(NettoNoteCollegate::impronta($netto))->toBe(['i' . $base['immobileId'] => -10000]);

    $piano = PianoRate::create(['gestione_id' => $base['gestione']->id, 'condominio_id' => $base['condominio']->id, 'nome' => 'V1', 'stato' => 'bozza', 'tipo' => 'straordinario']);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 0]);
    $motore = new CalcoloQuoteService();
    $motore->calcolaDaFattureStraordinarie($piano->fresh(), soloLettura: true);
    $perUnita = collect($motore->getAddebitiDiretti())->groupBy('immobile_id')->map(fn ($r) => (int) $r->sum('importo'));
    expect((int) ($perUnita[$base['immobileId']] ?? 0))->toBe(0)
        ->and((int) $perUnita[$b])->toBe(85000);
})->with(['rimborso poi sconto' => [false], 'sconto poi rimborso' => [true]]);

test('V2 [verifica] — la nota da XML che annulla una fattura con l\'imposta dichiarata si registra collegata: la richiesta usa il totale del servizio', function () {
    $base = ncBase();
    $u = ncUtente();
    $righe = fn () => array_map(fn ($i) => ['descrizione' => "Riga {$i}", 'importo_imponibile' => 10.03, 'aliquota_iva' => 22, 'natura' => null,
        'conto_id' => $base['capitolo']->id, 'is_sopravvenienza' => false], [1, 2, 3]);
    $riepiloghi = [['aliquota_iva' => 22.0, 'natura' => null, 'imponibile' => 30.09, 'imposta' => 6.62]];
    $fattura = (new FatturaPassivaService())->registraFattura(datiBase([$base['condominio'], $base['esercizio'], $base['gestione'], $base['fornitore']], [
        'numero_documento' => 'FT-XML', 'applica_ritenuta' => false, 'righe' => $righe(), 'riepiloghi' => $riepiloghi,
    ]), $base['condominio']->id);
    expect((int) $fattura->totale_documento)->toBe(3671, 'lo scenario: l\'imposta dichiarata vince sul calcolo per riga (che darebbe 3672)');

    $corpo = ncCorpoNota($base, $fattura->id, 0);
    $corpo['righe'] = $righe();
    $corpo['riepiloghi'] = $riepiloghi;
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), $corpo)->assertSessionHasNoErrors();

    expect(NettoNoteCollegate::perFattura($fattura->id)['rettificato'])->toBe(3671);
});

test('V3 [verifica] — la nota contestata non entra nella scala né nell\'avviso; il tetto del totale sì, anche in modifica', function () {
    $base = ncBase();
    $u = ncUtente();
    $fattura = ncFatturaFuoriPreventivo($base);
    ncPiano($base, $fattura, 100000, 'bozza');

    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNota($base, $fattura->id, 100, null, ['stato_approvazione' => 'contestata']))
        ->assertSessionHasNoErrors();
    $nota = FatturaPassiva::where('tipo_documento', 'nota_credito')->firstOrFail();
    expect(NettoNoteCollegate::perFattura($fattura->id))->toBeNull();

    $corpo = ncCorpoNota($base, null, 1500, null, ['numero_documento' => $nota->numero_documento]);
    unset($corpo['tipo_documento'], $corpo['stato_approvazione'], $corpo['fattura_rettificata_id']);
    $this->actingAs($u)->put(route('admin.gestionale.fatture.update', [$base['condominio']->id, $nota->id]), $corpo)
        ->assertSessionHasErrors('fattura_rettificata_id');
    expect(session('errors')->first('fattura_rettificata_id'))->toContain('oltre il suo totale di € 1.000,00')
        ->and((int) $nota->fresh()->totale_documento)->toBe(-10000);
});

test('V4 [verifica] — con un piano «tutto» già incassato l\'avviso non inventa la cifra', function () {
    $base = ncBase();
    $fattura = ncFatturaFuoriPreventivo($base);
    ncPiano($base, $fattura, 0, 'incassato');
    ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]], 'NC-A');

    $avviso = $fattura->fresh()->avvisoNotaCollegata([['conto_id' => $base['capitolo']->id, 'immobile_id' => null, 'riduzione' => 20000]]);
    // Senza cifra, ma con la frase sulla restituzione: il netto scende e le rate restano (W2 del terzo giro).
    expect($avviso)->toContain('restituito o destinato')->toContain('vale di meno')->not->toContain('più di quanto resta');
});

test('V4 [verifica] — il carrello legge un piano «tutto» come la creazione del piano: la fattura rettificata non si offre di nuovo', function () {
    $base = ncBase();
    $u = ncUtente();
    $fattura = ncFatturaFuoriPreventivo($base);
    ncPiano($base, $fattura, 0, 'approvato');
    ncNota($base, $fattura, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]]);

    expect(ncCarrello($this, $u, $base)->has($fattura->id))->toBeFalse();
});

test('V6 [verifica] — la copertura della voce legge la regola del motore: il piano sul netto copre la voce per intero', function () {
    $base = ncBase();
    // Come le scrive il servizio: la riga ad personam non ha voce (con la voce anche la formula vecchia dava € 700,00).
    $fattura = ncFattura($base, [
        ['conto_id' => null, 'immobile_id' => $base['immobileId'], 'importo' => 30000],
        ['conto_id' => $base['capitolo']->id, 'importo' => 70000, 'is_sopravvenienza' => true],
    ], 'FT-V6');
    ncNota($base, $fattura, [['conto_id' => null, 'immobile_id' => $base['immobileId'], 'importo' => 30000]]);
    $piano = ncPiano($base, $fattura, 70000, 'bozza');

    $motore = new CalcoloQuoteService();
    $motore->calcolaDaFattureStraordinarie($piano->fresh(), soloLettura: true);
    $report = collect((new App\Services\Gestionale\BudgetCoverageService())->analyze($base['gestione']->fresh())['items'])->keyBy('id');

    expect(NettoNoteCollegate::ripartoPerConto($fattura->id, 70000))->toBe([$base['capitolo']->id => 70000])
        ->and((int) $report[$base['capitolo']->id]['pianificato'])->toBe(70000);
});

// =============================================================================
// TERZO GIRO (W1–W4)
// =============================================================================

test('W1 [terzo giro] — un piano con le quote già generate si legge da ciò che ha registrato: la nota collegata dopo non gonfia la copertura', function () {
    $base = ncBase();
    $fattura = ncFattura($base, [
        ['conto_id' => null, 'immobile_id' => $base['immobileId'], 'importo' => 30000],
        ['conto_id' => $base['capitolo']->id, 'importo' => 70000, 'is_sopravvenienza' => true],
    ], 'FT-W1');
    ncPiano($base, $fattura, 100000, 'incassato');
    $coperta = fn () => (int) collect((new App\Services\Gestionale\BudgetCoverageService())->analyze($base['gestione']->fresh())['items'])
        ->keyBy('id')[$base['capitolo']->id]['pianificato'];
    expect($coperta())->toBe(70000, 'lo scenario: prima della nota la voce è coperta per € 700,00');

    ncNota($base, $fattura, [['conto_id' => null, 'immobile_id' => $base['immobileId'], 'importo' => 30000]]);

    expect($coperta())->toBe(70000);
});

test('W3 [terzo giro] — una nota contestata si corregge nella descrizione anche se le note superano il totale: il tetto scatta solo se cresce', function () {
    $base = ncBase();
    $u = ncUtente();
    $fattura = ncFatturaFuoriPreventivo($base);
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNota($base, $fattura->id, 800, null, ['stato_approvazione' => 'contestata', 'numero_documento' => 'NC-CONT']))
        ->assertSessionHasNoErrors();
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id), ncCorpoNota($base, $fattura->id, 300, null, ['numero_documento' => 'NC-APP']))
        ->assertSessionHasNoErrors();
    $contestata = FatturaPassiva::where('numero_documento', 'NC-CONT')->firstOrFail();

    $corpo = ncCorpoNota($base, null, 800, null, ['numero_documento' => 'NC-CONT', 'righe' => [0 => ['descrizione' => 'Rettifica corretta']]]);
    unset($corpo['tipo_documento'], $corpo['stato_approvazione'], $corpo['fattura_rettificata_id']);
    $this->actingAs($u)->put(route('admin.gestionale.fatture.update', [$base['condominio']->id, $contestata->id]), $corpo)->assertSessionHasNoErrors();

    $cresce = ncCorpoNota($base, null, 900, null, ['numero_documento' => 'NC-CONT']);
    unset($cresce['tipo_documento'], $cresce['stato_approvazione'], $cresce['fattura_rettificata_id']);
    $this->actingAs($u)->put(route('admin.gestionale.fatture.update', [$base['condominio']->id, $contestata->id]), $cresce)
        ->assertSessionHasErrors('fattura_rettificata_id');
});

test('W4 [terzo giro] — una nota pregressa collegata con l\'imposta non numerica dà l\'errore sul campo, non un 500', function () {
    $base = ncBase();
    $pregressa = ncPregressa($base);

    $this->actingAs(ncUtente())->post(route('admin.gestionale.fatture.store', $base['condominio']->id),
        ncCorpoNotaPregressa($base, $pregressa->id, 300, ['imposta_pregressa' => 'abc']))
        ->assertSessionHasErrors('imposta_pregressa');
});

// =============================================================================
// QUARTO GIRO (X1, X2 e i testi)
// =============================================================================

test('X1 [quarto giro] — un piano con le quote ma senza righe di riparto (di prima della beta.29) si legge senza note, come la stampa ricostruita', function () {
    $base = ncBase();
    $fattura = ncFattura($base, [
        ['conto_id' => null, 'immobile_id' => $base['immobileId'], 'importo' => 30000],
        ['conto_id' => $base['capitolo']->id, 'importo' => 70000, 'is_sopravvenienza' => true],
    ], 'FT-OLD');
    $piano = ncPiano($base, $fattura, 100000, 'incassato');
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    $coperta = fn () => (int) collect((new App\Services\Gestionale\BudgetCoverageService())->analyze($base['gestione']->fresh())['items'])
        ->keyBy('id')[$base['capitolo']->id]['pianificato'];
    $prima = $coperta();

    ncNota($base, $fattura, [['conto_id' => null, 'immobile_id' => $base['immobileId'], 'importo' => 30000]]);

    expect($coperta())->toBe($prima)->and($prima)->toBe(70000);
});

test('X2 [quarto giro] — in un piano con una fattura rettificata tutto il piano si legge da ciò che ha registrato, anche l\'altra fattura', function () {
    $base = ncBase();
    $pulizie = ncAltroConto($base, 'Pulizie');
    $a = ncFatturaFuoriPreventivo($base, 100000, 'FT-A');
    $b = ncFattura($base, [
        ['conto_id' => $pulizie->id, 'importo' => 40000, 'is_sopravvenienza' => false],
        ['conto_id' => $base['capitolo']->id, 'importo' => 60000, 'is_sopravvenienza' => true],
    ], 'FT-B');
    $piano = PianoRate::create(['gestione_id' => $base['gestione']->id, 'condominio_id' => $base['condominio']->id, 'nome' => 'Misto', 'stato' => 'bozza', 'tipo' => 'straordinario']);
    $piano->fatture()->attach([$a->id => ['importo_collegato' => 100000], $b->id => ['importo_collegato' => 60000]]);
    app(GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'Piano di prova della Coda 165');
    $totale = ncTot($piano);
    ncNota($base, $a, [['conto_id' => $base['capitolo']->id, 'importo' => 30000]]);

    $report = collect((new App\Services\Gestionale\BudgetCoverageService())->analyze($base['gestione']->fresh())['items'])->keyBy('id');
    $registrato = NettoNoteCollegate::ripartoRegistratoPerConto($piano->id);
    expect((int) $report[$base['capitolo']->id]['pianificato'])->toBe($registrato[$base['capitolo']->id])
        ->and((int) ($report[$pulizie->id]['pianificato'] ?? 0))->toBe($registrato[$pulizie->id] ?? 0)
        ->and(ncTot($piano->fresh()))->toBe($totale);
});

test('Quarto giro — in modifica l\'avviso non dice «riduce il debito»: le rate restano quelle già calcolate', function (int $pivot) {
    $base = ncBase();
    $u = ncUtente();
    $seconda = ncSecondaUnita($base);
    $fattura = ncFattura($base, [
        ['conto_id' => null, 'immobile_id' => $base['immobileId'], 'importo' => 10000],
        ['conto_id' => null, 'immobile_id' => $seconda, 'importo' => 10000],
    ], 'FT-M4');
    $this->actingAs($u)->post(route('admin.gestionale.fatture.store', $base['condominio']->id),
        ncCorpoNota($base, $fattura->id, 50, null, ['righe' => [0 => ['immobile_id' => $base['immobileId']]]]))->assertSessionHasNoErrors();
    $nota = FatturaPassiva::where('tipo_documento', 'nota_credito')->firstOrFail();
    ncPiano($base, $fattura, $pivot, 'incassato');

    $sposta = ncCorpoNota($base, null, 50, null, ['numero_documento' => $nota->numero_documento, 'righe' => [0 => ['immobile_id' => $seconda]]]);
    unset($sposta['tipo_documento'], $sposta['stato_approvazione'], $sposta['fattura_rettificata_id']);
    $this->actingAs($u)->put(route('admin.gestionale.fatture.update', [$base['condominio']->id, $nota->id]), $sposta)->assertSessionHasErrors('avviso_nota');

    expect(session('errors')->first('avviso_nota'))->toContain('La modifica cambia')->toContain('già calcolate')
        ->not->toContain('riduce il debito');
})->with(['piano con l\'importo fissato' => [15000], 'piano «tutto», quanto chiede non si sa' => [0]]);
