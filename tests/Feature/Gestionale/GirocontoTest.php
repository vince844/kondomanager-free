<?php

use App\Actions\Gestionale\Movimenti\RegistraGirocontoAction;
use App\Actions\Gestionale\Movimenti\StornaGirocontoAction;
use App\Enums\TipoMovimentoContabile;
use App\Exceptions\Gestionale\GirocontoNonAmmessoException;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\FatturaCopertura;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\RiallineaFondiService;
use App\Services\Gestionale\SaldoCassaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Crea una cassa con il suo conto contabile ATTIVO/LIQUIDITÀ, come fa
 * CreateCassaAccountAction in produzione (ogni cassa, fondo incluso, è una
 * partizione dell'unico c/c: conto figlio del mastro 1010).
 */
function creaCassaConConto(int $condominioId, string $tipo, int $saldoIniziale, array $overrides = []): Cassa
{
    $ruolo = match ($tipo) {
        'banca' => 'conto_bancario',
        'fondo' => 'fondo_riserva',
        default => 'cassa_contanti',
    };

    $contoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId,
        'ruolo' => $ruolo,
        'codice' => '1010.'.uniqid(),
        'nome' => 'Conto '.$tipo.' '.uniqid(),
        'tipo' => 'attivo',
        'categoria' => 'liquidita',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Cassa::create(array_merge([
        'condominio_id' => $condominioId,
        'nome' => ucfirst($tipo).' '.uniqid(),
        'tipo' => $tipo,
        'conto_contabile_id' => $contoId,
        'saldo_iniziale' => $saldoIniziale,
        'attiva' => true,
        'sottotipo_fondo' => $tipo === 'fondo' ? 'generico' : null,
    ], $overrides));
}

function datiGiroconto(array $ctx, Cassa $origine, Cassa $destinazione, float $importoEuro, array $extra = []): array
{
    [, $esercizio, $gestione] = $ctx;

    return array_merge([
        'cassa_origine_id' => $origine->id,
        'cassa_destinazione_id' => $destinazione->id,
        'importo' => $importoEuro,
        'data_operazione' => now()->format('Y-m-d'),
        'causale' => 'Giroconto di test',
        'gestione_id' => $gestione->id,
        'esercizio_id' => $esercizio->id,
    ], $extra);
}

/** Registra una fattura in sforo con strategia fondo → copertura 'pianificata' agganciata alla cassa fondo. */
function creaCoperturaPianificata(array $ctx, Cassa $cassaFondo, int $importoSforoCents = 50000): FatturaCopertura
{
    [$condominio, , , , $capitolo] = $ctx;

    $fattura = (new FatturaPassivaService())->registraFattura(
        array_merge(datiBase(array_slice($ctx, 0, 4)), [
            'righe' => [[
                'descrizione' => 'Lavoro in sforo',
                'importo_imponibile' => 1000,
                'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
            'dati_extra' => [
                'fiscal' => [],
                'competenza' => null,
                'override_budget' => [
                    'strategia_rientro' => 'fondo_riserva',
                    'fondo_patrimoniale_id' => $cassaFondo->conto_contabile_id,
                    'importo_sforo' => $importoSforoCents,
                    'motivazione' => 'Sforo di test per conferma giroconto',
                ],
            ],
        ]),
        $condominio->id
    );

    return $fattura->coperture()->where('tipo_copertura', 'fondo_riserva')->firstOrFail();
}

// ─────────────────────────────────────────────────────────────────────────────
// Registrazione — partita doppia e saldi
// ─────────────────────────────────────────────────────────────────────────────

test('accantonamento banca→fondo: 2 righe quadrate, DARE fondo, AVERE banca, cassa_id valorizzati', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 1000000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 200000);

    $scrittura = app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $banca, $fondo, 500.00), $condominio, $esercizio);

    assertQuadraturaPerfetta($scrittura->id);
    expect($scrittura->tipo_movimento)->toEqual(TipoMovimentoContabile::GIROCONTO)
        ->and($scrittura->numero_protocollo)->toStartWith('GIR-')
        ->and($scrittura->righe)->toHaveCount(2);

    $dare = $scrittura->righe->firstWhere('tipo_riga', 'dare');
    $avere = $scrittura->righe->firstWhere('tipo_riga', 'avere');
    expect($dare->conto_contabile_id)->toEqual($fondo->conto_contabile_id)
        ->and($dare->cassa_id)->toEqual($fondo->id)
        ->and((int) $dare->importo)->toEqual(50000)
        ->and($avere->conto_contabile_id)->toEqual($banca->conto_contabile_id)
        ->and($avere->cassa_id)->toEqual($banca->id);
});

test('i saldi si muovono in convenzione unica: banca scende, fondo sale, totale invariato', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 1000000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 200000);
    $saldi = app(SaldoCassaService::class);

    app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $banca, $fondo, 500.00), $condominio, $esercizio);

    expect($saldi->saldoDisponibile($banca->fresh()))->toEqual(950000)
        ->and($saldi->saldoDisponibile($fondo->fresh()))->toEqual(250000);
});

test('il saldo del fondo esposto da CassaResource coincide con SaldoCassaService (regressione armonizzazione)', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 1000000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 200000);

    app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $banca, $fondo, 500.00), $condominio, $esercizio);

    // Simula gli alias e l'eager loading del CassaController::index
    $fondoConAlias = Cassa::where('id', $fondo->id)
        ->with('contoCorrente')
        ->withSum(['movimenti as totale_entrate' => fn ($q) => $q->where('tipo_riga', 'dare')], 'importo')
        ->withSum(['movimenti as totale_uscite' => fn ($q) => $q->where('tipo_riga', 'avere')], 'importo')
        ->first();

    $resource = (new \App\Http\Resources\Gestionale\Casse\CassaResource($fondoConAlias))
        ->toArray(request());

    expect((int) round($resource['saldo_raw'] * 100))
        ->toEqual(app(SaldoCassaService::class)->saldoDisponibile($fondo->fresh()));
});

// ─────────────────────────────────────────────────────────────────────────────
// Guardie
// ─────────────────────────────────────────────────────────────────────────────

test('capienza insufficiente sulla cassa origine blocca il giroconto', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 10000); // 100 €
    $fondo = creaCassaConConto($condominio->id, 'fondo', 0);

    app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $banca, $fondo, 500.00), $condominio, $esercizio);
})->throws(GirocontoNonAmmessoException::class, 'Capienza insufficiente');

test('origine e destinazione coincidenti sono rifiutate', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 100000);

    app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $banca, $banca, 10.00), $condominio, $esercizio);
})->throws(GirocontoNonAmmessoException::class, 'Origine e destinazione coincidono');

test('cassa di un altro condominio rifiutata', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 100000);

    $altro = \App\Models\Condominio::create(['nome' => 'Altro condominio', 'indirizzo' => 'Via X 1']);
    $cassaAltrui = creaCassaConConto($altro->id, 'banca', 100000);

    app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $banca, $cassaAltrui, 10.00), $condominio, $esercizio);
})->throws(GirocontoNonAmmessoException::class, 'non appartiene a questo condominio');

test('giroconto fondo↔contanti vietato: la liquidità del fondo vive sul c/c', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $fondo = creaCassaConConto($condominio->id, 'fondo', 100000);
    $contanti = creaCassaConConto($condominio->id, 'contanti', 0);

    app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $fondo, $contanti, 10.00), $condominio, $esercizio);
})->throws(GirocontoNonAmmessoException::class, 'solo con il conto corrente');

test('fondo vincolato senza deroga assembleare non può uscire; con deroga sì', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 0);
    $vincolato = creaCassaConConto($condominio->id, 'fondo', 100000, [
        'sottotipo_fondo' => 'vincolato_lavori',
        'vincolo_descrizione' => 'Rifacimento facciata',
    ]);

    expect(fn () => app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $vincolato, $banca, 10.00), $condominio, $esercizio)
    )->toThrow(GirocontoNonAmmessoException::class, 'vincolato');

    $vincolato->update(['is_override_assemblea' => true, 'motivazione_override' => 'Delibera del 01/07/2026']);

    $scrittura = app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $vincolato->fresh(), $banca, 10.00), $condominio, $esercizio);

    assertQuadraturaPerfetta($scrittura->id);
});

test('il fondo vincolato in INGRESSO è sempre ammesso (il vincolo riguarda l\'uscita)', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 100000);
    $vincolato = creaCassaConConto($condominio->id, 'fondo', 0, [
        'sottotipo_fondo' => 'vincolato_lavori',
        'vincolo_descrizione' => 'Rifacimento facciata',
    ]);

    $scrittura = app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $banca, $vincolato, 100.00), $condominio, $esercizio);

    assertQuadraturaPerfetta($scrittura->id);
    expect(app(SaldoCassaService::class)->saldoDisponibile($vincolato->fresh()))->toEqual(10000);
});

test('data futura rifiutata dalla validazione HTTP (422)', function () {
    // La regola vive nella Request: qui il percorso HTTP completo.
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    $ctx = setupContabile();
    [$condominio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 100000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 0);

    $this->actingAs($user)
        ->post(route('admin.gestionale.giroconti.store', $condominio), datiGiroconto($ctx, $banca, $fondo, 10.00, [
            'data_operazione' => now()->addDays(3)->format('Y-m-d'),
        ]))
        ->assertSessionHasErrors(['data_operazione']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Conferma copertura
// ─────────────────────────────────────────────────────────────────────────────

test('la conferma porta la copertura a confermata e la aggancia alla scrittura GIR', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 0);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 100000);
    $copertura = creaCoperturaPianificata($ctx, $fondo);

    $scrittura = app(RegistraGirocontoAction::class)->execute(
        datiGiroconto($ctx, $fondo, $banca, $copertura->importo / 100, [
            'fattura_copertura_id' => $copertura->id,
            'causale' => 'Conferma copertura sforo',
        ]),
        $condominio, $esercizio
    );

    $copertura->refresh();
    expect($copertura->stato)->toEqual('confermata')
        ->and($copertura->scrittura_giroconto_id)->toEqual($scrittura->id)
        ->and($copertura->confermata_at)->not->toBeNull();
});

test('conferma solo integrale: importo diverso dalla copertura rifiutato', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 0);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 100000);
    $copertura = creaCoperturaPianificata($ctx, $fondo, 50000);

    app(RegistraGirocontoAction::class)->execute(
        datiGiroconto($ctx, $fondo, $banca, 123.45, ['fattura_copertura_id' => $copertura->id]),
        $condominio, $esercizio
    );
})->throws(GirocontoNonAmmessoException::class, 'solo integrale');

test('copertura già confermata non è confermabile due volte', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 0);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 200000);
    $copertura = creaCoperturaPianificata($ctx, $fondo);

    $registra = app(RegistraGirocontoAction::class);
    $dati = datiGiroconto($ctx, $fondo, $banca, $copertura->importo / 100, ['fattura_copertura_id' => $copertura->id]);

    $registra->execute($dati, $condominio, $esercizio);
    $registra->execute($dati, $condominio, $esercizio);
})->throws(GirocontoNonAmmessoException::class, 'già stata confermata');

test('la conferma esige che l\'origine sia il fondo della copertura e la destinazione una banca', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 200000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 200000);
    $altroFondo = creaCassaConConto($condominio->id, 'fondo', 200000);
    $copertura = creaCoperturaPianificata($ctx, $fondo);

    // Origine sbagliata: un fondo diverso da quello della copertura.
    expect(fn () => app(RegistraGirocontoAction::class)->execute(
        datiGiroconto($ctx, $altroFondo, $banca, $copertura->importo / 100, ['fattura_copertura_id' => $copertura->id]),
        $condominio, $esercizio
    ))->toThrow(GirocontoNonAmmessoException::class, 'non è il fondo indicato');

    // Destinazione sbagliata: un fondo invece della banca.
    expect(fn () => app(RegistraGirocontoAction::class)->execute(
        datiGiroconto($ctx, $fondo, $altroFondo, $copertura->importo / 100, ['fattura_copertura_id' => $copertura->id]),
        $condominio, $esercizio
    ))->toThrow(GirocontoNonAmmessoException::class, 'deve essere una cassa di tipo banca');
});

// ─────────────────────────────────────────────────────────────────────────────
// Storno
// ─────────────────────────────────────────────────────────────────────────────

test('lo storno crea la scrittura specchio e riporta i saldi al punto di partenza', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 1000000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 200000);
    $saldi = app(SaldoCassaService::class);

    $giroconto = app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $banca, $fondo, 500.00), $condominio, $esercizio);

    $storno = app(StornaGirocontoAction::class)
        ->execute($giroconto, $condominio, 'Errore di importo, va rifatto');

    assertQuadraturaPerfetta($storno->id);
    expect($storno->tipo_movimento)->toEqual(TipoMovimentoContabile::STORNO_GIROCONTO)
        ->and($storno->numero_protocollo)->toStartWith('STO-')
        ->and($storno->scrittura_padre_id)->toEqual($giroconto->id)
        ->and($saldi->saldoDisponibile($banca->fresh()))->toEqual(1000000)
        ->and($saldi->saldoDisponibile($fondo->fresh()))->toEqual(200000);

    // Le righe sono lo specchio: DARE banca, AVERE fondo, cassa_id propagati.
    $dare = $storno->righe->firstWhere('tipo_riga', 'dare');
    expect($dare->conto_contabile_id)->toEqual($banca->conto_contabile_id)
        ->and($dare->cassa_id)->toEqual($banca->id);
});

test('il doppio storno è vietato', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 100000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 0);

    $giroconto = app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $banca, $fondo, 100.00), $condominio, $esercizio);

    $storna = app(StornaGirocontoAction::class);
    $storna->execute($giroconto, $condominio, 'Primo storno legittimo');
    $storna->execute($giroconto, $condominio, 'Secondo storno che deve fallire');
})->throws(GirocontoNonAmmessoException::class, 'già stato stornato');

test('lo storno di un movimento che non è un giroconto è rifiutato', function () {
    $ctx = setupContabile();
    [$condominio, , , , $capitolo] = $ctx;
    $fattura = registraFatturaServiceTest($ctx, [
        'righe' => [[
            'descrizione' => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ]);
    $scritturaFattura = $fattura->scritture->first();

    app(StornaGirocontoAction::class)
        ->execute($scritturaFattura, $condominio, 'Tentativo su una fattura');
})->throws(GirocontoNonAmmessoException::class, 'storna solo i giroconti');

test('lo storno della conferma riporta la copertura a pianificata', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 0);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 100000);
    $copertura = creaCoperturaPianificata($ctx, $fondo);

    $giroconto = app(RegistraGirocontoAction::class)->execute(
        datiGiroconto($ctx, $fondo, $banca, $copertura->importo / 100, ['fattura_copertura_id' => $copertura->id]),
        $condominio, $esercizio
    );

    app(StornaGirocontoAction::class)->execute($giroconto, $condominio, 'La conferma era prematura');

    $copertura->refresh();
    expect($copertura->stato)->toEqual('pianificata')
        ->and($copertura->scrittura_giroconto_id)->toBeNull()
        ->and($copertura->confermata_at)->toBeNull();
});

test('storno cross-esercizio (B1): si appoggia all\'esercizio aperto con la provenienza in causale', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 100000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 0);

    $giroconto = app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $banca, $fondo, 100.00), $condominio, $esercizio);

    // Chiudiamo l'esercizio del giroconto e apriamo il successivo.
    DB::table('esercizi')->where('id', $esercizio->id)->update(['stato' => 'chiuso']);
    $nuovoEsercizioId = DB::table('esercizi')->insertGetId([
        'condominio_id' => $condominio->id,
        'nome' => 'Esercizio successivo',
        'stato' => 'aperto',
        'data_inizio' => now()->addYear()->startOfYear(),
        'data_fine' => now()->addYear()->endOfYear(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $storno = app(StornaGirocontoAction::class)
        ->execute($giroconto->fresh(), $condominio, 'Storno dopo chiusura');

    expect($storno->esercizio_id)->toEqual($nuovoEsercizioId)
        ->and($storno->causale)->toContain('Originale in esercizio');
});

// ─────────────────────────────────────────────────────────────────────────────
// Riallineamento scritture storiche
// ─────────────────────────────────────────────────────────────────────────────

test('riallineamento: la coppia storica dello sforo viene neutralizzata con una rettifica quadrata', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione] = $ctx;
    $fondo = creaCassaConConto($condominio->id, 'fondo', 100000);
    $contoSopravv = \App\Models\Gestionale\ContoContabile::where('condominio_id', $condominio->id)
        ->where('ruolo', 'sopravvenienze_passive')->first();

    // Scrittura storica pre-beta.19: fattura con la vecchia coppia sul fondo.
    $storica = ScritturaContabile::create([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'data_registrazione' => now()->toDateString(),
        'data_competenza' => now()->toDateString(),
        'causale' => 'Ft. storica con sforo coperto da fondo',
        'tipo_movimento' => 'fattura_acquisto',
        'stato' => 'registrata',
    ]);
    $storica->righe()->create(['conto_contabile_id' => $fondo->conto_contabile_id, 'tipo_riga' => 'dare', 'importo' => 30000, 'note' => 'Utilizzo fondo riserva per sforo budget (storico)']);
    $storica->righe()->create(['conto_contabile_id' => $contoSopravv->id, 'tipo_riga' => 'avere', 'importo' => 30000, 'note' => 'Giroconto copertura sforo (storico)']);

    $service = app(RiallineaFondiService::class);

    $rilevate = $service->rileva($condominio);
    expect($rilevate)->toHaveCount(1)
        ->and($rilevate->first()['importo_netto_fondo'])->toEqual(30000);

    $esito = $service->esegui($condominio);
    expect($esito['rettificate'])->toEqual(1);

    $rettifica = ScritturaContabile::where('scrittura_padre_id', $storica->id)
        ->where('tipo_movimento', 'rettifica')->firstOrFail();
    assertQuadraturaPerfetta($rettifica->id);

    $avereFondo = $rettifica->righe->firstWhere('tipo_riga', 'avere');
    expect($avereFondo->conto_contabile_id)->toEqual($fondo->conto_contabile_id);

    // Il saldo del fondo torna al solo saldo iniziale, e il rilevamento si azzera.
    expect(app(SaldoCassaService::class)->saldoDisponibile($fondo->fresh()))->toEqual(100000)
        ->and($service->rileva($condominio))->toHaveCount(0);
});

test('riallineamento: il DARE storico del pregresso si chiude su passate gestioni', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione] = $ctx;
    $fondo = creaCassaConConto($condominio->id, 'fondo', 100000);
    $contoDebiti = \App\Models\Gestionale\ContoContabile::where('condominio_id', $condominio->id)
        ->where('ruolo', 'debiti_fornitori')->first();
    $contoPassate = \App\Models\Gestionale\ContoContabile::where('condominio_id', $condominio->id)
        ->where('ruolo', 'passate_gestioni')->first();

    // Scrittura storica: pregresso con DARE fondo strutturale (bilancia AVERE debiti).
    $storica = ScritturaContabile::create([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'data_registrazione' => now()->toDateString(),
        'data_competenza' => now()->toDateString(),
        'causale' => '[PREGRESSO] Ft. storica con copertura fondo',
        'tipo_movimento' => 'fattura_acquisto',
        'stato' => 'registrata',
    ]);
    $storica->righe()->create(['conto_contabile_id' => $fondo->conto_contabile_id, 'tipo_riga' => 'dare', 'importo' => 40000, 'note' => 'Utilizzo fondo riserva per debito pregresso (storico)']);
    $storica->righe()->create(['conto_contabile_id' => $contoDebiti->id, 'tipo_riga' => 'avere', 'importo' => 40000]);

    $esito = app(RiallineaFondiService::class)->esegui($condominio);
    expect($esito['rettificate'])->toEqual(1);

    $rettifica = ScritturaContabile::where('scrittura_padre_id', $storica->id)
        ->where('tipo_movimento', 'rettifica')->firstOrFail();
    assertQuadraturaPerfetta($rettifica->id);

    // AVERE fondo (neutralizza) + DARE passate gestioni (chiude lo sbilancio).
    expect($rettifica->righe->firstWhere('tipo_riga', 'avere')->conto_contabile_id)->toEqual($fondo->conto_contabile_id)
        ->and($rettifica->righe->firstWhere('tipo_riga', 'dare')->conto_contabile_id)->toEqual($contoPassate->id)
        ->and(app(SaldoCassaService::class)->saldoDisponibile($fondo->fresh()))->toEqual(100000);
});

// ─────────────────────────────────────────────────────────────────────────────
// HTTP
// ─────────────────────────────────────────────────────────────────────────────

function adminGiroconti(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

test('store: happy path con redirect al dettaglio della scrittura', function () {
    $user = adminGiroconti();
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 100000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 0);

    $response = $this->actingAs($user)
        ->post(route('admin.gestionale.giroconti.store', $condominio), datiGiroconto($ctx, $banca, $fondo, 250.00, [
            'causale' => 'Accantonamento mensile a fondo',
        ]));

    $scrittura = ScritturaContabile::where('tipo_movimento', 'giroconto')->firstOrFail();
    $response->assertRedirect(route('admin.gestionale.scritture.show', [
        'condominio' => $condominio->id,
        'scrittura' => $scrittura->id,
    ]));
});

test('store con crea_altro: redirect al modulo di creazione, non al dettaglio scrittura', function () {
    $user = adminGiroconti();
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 100000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 0);

    $this->actingAs($user)
        ->post(route('admin.gestionale.giroconti.store', $condominio), datiGiroconto($ctx, $banca, $fondo, 250.00, [
            'crea_altro' => true,
        ]))
        ->assertRedirect(route('admin.gestionale.giroconti.create', ['condominio' => $condominio->id]));

    expect(ScritturaContabile::where('tipo_movimento', 'giroconto')->count())->toEqual(1);
});

test('store: la violazione di dominio torna come errore giroconto_non_ammesso, non come 500', function () {
    $user = adminGiroconti();
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 100); // 1 €
    $fondo = creaCassaConConto($condominio->id, 'fondo', 0);

    $this->actingAs($user)
        ->post(route('admin.gestionale.giroconti.store', $condominio), datiGiroconto($ctx, $banca, $fondo, 250.00))
        ->assertSessionHasErrors(['giroconto_non_ammesso']);
});

test('store: esercizio chiuso respinto dalla validazione', function () {
    $user = adminGiroconti();
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 100000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 0);

    DB::table('esercizi')->where('id', $esercizio->id)->update(['stato' => 'chiuso']);

    $this->actingAs($user)
        ->post(route('admin.gestionale.giroconti.store', $condominio), datiGiroconto($ctx, $banca, $fondo, 10.00))
        ->assertSessionHasErrors(['esercizio_id']);
});

test('create espone casse con saldi, coperture in attesa ed esercizio singolare', function () {
    $user = adminGiroconti();
    $ctx = setupContabile();
    [$condominio] = $ctx;
    creaCassaConConto($condominio->id, 'banca', 100000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 200000);
    creaCoperturaPianificata($ctx, $fondo);

    $this->actingAs($user)
        ->get(route('admin.gestionale.giroconti.create', $condominio))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gestionale/movimenti/giroconti/GirocontoNew')
            ->has('esercizio')
            ->has('casse', 2)
            ->has('coperture_in_attesa', 1)
            ->where('coperture_in_attesa.0.fondo_id', $fondo->conto_contabile_id)
        );
});

test('index elenca i giroconti e la prop di riallineamento', function () {
    $user = adminGiroconti();
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 100000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 0);

    app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $banca, $fondo, 100.00), $condominio, $esercizio);

    $this->actingAs($user)
        ->get(route('admin.gestionale.giroconti.index', $condominio))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gestionale/movimenti/giroconti/GirocontiList')
            ->has('giroconti.data', 1)
            ->where('giroconti.data.0.stornabile', true)
            ->where('stats.giroconti_attivi', 1)
            ->has('riallineamento', 0)
        );
});

test('storno HTTP: motivo sotto i 10 caratteri respinto', function () {
    $user = adminGiroconti();
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 100000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 0);

    $giroconto = app(RegistraGirocontoAction::class)
        ->execute(datiGiroconto($ctx, $banca, $fondo, 100.00), $condominio, $esercizio);

    $this->actingAs($user)
        ->post(route('admin.gestionale.giroconti.storno', ['condominio' => $condominio->id, 'scrittura' => $giroconto->id]), [
            'motivo' => 'corto',
        ])
        ->assertSessionHasErrors(['motivo']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Ciclo di vita della copertura vs storno/eliminazione fattura (fix revisione)
// ─────────────────────────────────────────────────────────────────────────────

test('la copertura di una fattura stornata non è confermabile', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 0);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 100000);
    $copertura = creaCoperturaPianificata($ctx, $fondo);

    // Simuliamo lo storno: la fattura viene congelata come fa StornoFatturaController.
    $copertura->fattura->update([
        'stato_pagamento' => 'stornata',
        'dati_extra' => array_merge($copertura->fattura->dati_extra ?? [], ['is_stornata' => true]),
    ]);

    app(RegistraGirocontoAction::class)->execute(
        datiGiroconto($ctx, $fondo, $banca, $copertura->importo / 100, ['fattura_copertura_id' => $copertura->id]),
        $condominio, $esercizio
    );
})->throws(GirocontoNonAmmessoException::class, 'stornata');

test('le coperture di fatture stornate non compaiono fra quelle in attesa nel form', function () {
    $user = adminGiroconti();
    $ctx = setupContabile();
    [$condominio] = $ctx;
    creaCassaConConto($condominio->id, 'banca', 0);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 100000);
    $copertura = creaCoperturaPianificata($ctx, $fondo);

    $copertura->fattura->update(['stato_pagamento' => 'stornata']);

    $this->actingAs($user)
        ->get(route('admin.gestionale.giroconti.create', $condominio))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('coperture_in_attesa', 0));
});

test('una fattura con copertura confermata non è eliminabile finché il giroconto è vivo', function () {
    $user = adminGiroconti();
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 0);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 100000);
    $copertura = creaCoperturaPianificata($ctx, $fondo);

    app(RegistraGirocontoAction::class)->execute(
        datiGiroconto($ctx, $fondo, $banca, $copertura->importo / 100, ['fattura_copertura_id' => $copertura->id]),
        $condominio, $esercizio
    );

    $this->actingAs($user)
        ->delete(route('admin.gestionale.fatture.destroy', ['condominio' => $condominio->id, 'fattura' => $copertura->fattura_passiva_id]));

    // La fattura esiste ancora e la copertura è intatta.
    expect(\App\Models\Gestionale\FatturaPassiva::find($copertura->fattura_passiva_id))->not->toBeNull()
        ->and($copertura->fresh()->stato)->toEqual('confermata');
});

test('una fattura con copertura fondo non è modificabile: la copertura fotografa lo sforo', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;
    creaCassaConConto($condominio->id, 'banca', 0);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 100000);
    $copertura = creaCoperturaPianificata($ctx, $fondo);

    $motivo = (new FatturaPassivaService())->motivoBloccoModifica($copertura->fattura->fresh());

    expect($motivo)->toContain('copertura dal fondo');
});

test('l\'idempotency_key previene il doppio giroconto da doppio click', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $banca = creaCassaConConto($condominio->id, 'banca', 100000);
    $fondo = creaCassaConConto($condominio->id, 'fondo', 0);

    $key = (string) \Illuminate\Support\Str::uuid();
    $registra = app(RegistraGirocontoAction::class);
    $dati = datiGiroconto($ctx, $banca, $fondo, 100.00, ['idempotency_key' => $key]);

    $prima = $registra->execute($dati, $condominio, $esercizio);
    $seconda = $registra->execute($dati, $condominio, $esercizio);

    expect($seconda->id)->toEqual($prima->id)
        ->and(ScritturaContabile::where('tipo_movimento', 'giroconto')->count())->toEqual(1);
});
