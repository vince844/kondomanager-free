<?php

use App\Enums\TipoMovimentoContabile;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Fornitore;
use App\Models\Gestione;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\RegistroContabilitaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Un conto contabile "di appoggio" (non una cassa), per il lato debiti/costi delle scritture. */
function creaContoAppoggio(int $condominioId, string $nome = 'Debiti v/Fornitori'): int
{
    return DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId,
        'codice' => 'APP-'.uniqid(),
        'nome' => $nome,
        'tipo' => 'passivo',
        'categoria' => 'debiti',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** Una cassa vera, col proprio conto contabile — 'tipo' è banca/contanti/fondo/virtuale. */
function creaCassaRegistro(int $condominioId, string $tipo, string $nome): Cassa
{
    $conto = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId,
        'codice' => strtoupper($tipo).'-'.uniqid(),
        'nome' => $nome,
        'tipo' => 'attivo',
        'categoria' => 'liquidita',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Cassa::create([
        'condominio_id' => $condominioId,
        'nome' => $nome,
        'tipo' => $tipo,
        'conto_contabile_id' => $conto,
        'saldo_iniziale' => 0,
        'attiva' => true,
    ]);
}

/** Condominio + esercizio + gestione + conto di appoggio, per postare le scritture dei test. */
function setupRegistro(): array
{
    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::factory()->create([
        'condominio_id' => $condominio->id,
        'stato' => 'aperto',
    ]);
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);
    $contoDebiti = creaContoAppoggio($condominio->id);

    return [$condominio, $esercizio, $gestione, $contoDebiti];
}

function creaFornitoreRegistro(string $ragioneSociale): int
{
    return DB::table('fornitori')->insertGetId([
        'ragione_sociale' => $ragioneSociale,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function creaScrittura(array $overrides): ScritturaContabile
{
    return ScritturaContabile::create(array_merge([
        'data_registrazione' => now()->format('Y-m-d'),
        'data_competenza' => now()->format('Y-m-d'),
        'causale' => 'Scrittura di test',
        'stato' => 'registrata',
    ], $overrides));
}

test('un incasso rata compare come entrata, con il condòmino come controparte', function () {
    [$condominio, $esercizio, $gestione, $contoDebiti] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca Test');
    $anagrafica = Anagrafica::factory()->create(['nome' => 'Mario Rossi']);

    $scrittura = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::INCASSO_RATA->value,
    ]);

    // La riga di cassa NON porta anagrafica_id — lo porta solo la riga di credito,
    // esattamente come StoreIncassoRateAction (mai un JOIN diretto sulla riga di cassa).
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'dare',
        'importo' => 10000,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $contoDebiti,
        'anagrafica_id' => $anagrafica->id,
        'tipo_riga' => 'avere',
        'importo' => 10000,
    ]);

    $righe = (new RegistroContabilitaService)->registro($esercizio);

    expect($righe)->toHaveCount(1);
    expect($righe[0]['entrata'])->toBe(10000);
    expect($righe[0]['uscita'])->toBeNull();
    expect($righe[0]['controparte'])->toBe('Mario Rossi');
    expect($righe[0]['saldo_progressivo'])->toBe(10000);
});

test('un pagamento fornitore compare come uscita, con il fornitore come controparte', function () {
    [$condominio, $esercizio, $gestione, $contoDebiti] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca Test');
    $fornitore = Fornitore::create(['ragione_sociale' => 'Idraulica Bianchi Srl']);

    $scrittura = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::PAGAMENTO_FORNITORE->value,
    ]);

    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $contoDebiti,
        'tipo_riga' => 'dare',
        'importo' => 5000,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'avere',
        'importo' => 5000,
    ]);

    DB::table('pagamenti_fornitori')->insert([
        'uuid' => (string) Str::uuid(),
        'scrittura_contabile_id' => $scrittura->id,
        'condominio_id' => $condominio->id,
        'fornitore_id' => $fornitore->id,
        'conto_corrente_id' => $banca->conto_contabile_id,
        'importo_lordo' => 5000,
        'importo_ritenuta' => 0,
        'importo_netto' => 5000,
        'metodo_pagamento' => 'bonifico',
        'data_pagamento' => now()->format('Y-m-d'),
        'stato' => 'confermato',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $righe = (new RegistroContabilitaService)->registro($esercizio);

    expect($righe)->toHaveCount(1);
    expect($righe[0]['uscita'])->toBe(5000);
    expect($righe[0]['entrata'])->toBeNull();
    expect($righe[0]['controparte'])->toBe('Idraulica Bianchi Srl');
    expect($righe[0]['saldo_progressivo'])->toBe(-5000);
});

test('un versamento F24 compare come uscita, con l\'erario come controparte', function () {
    [$condominio, $esercizio, $gestione, $contoDebiti] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca Test');

    $scrittura = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::PAGAMENTO_F24->value,
    ]);

    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $contoDebiti,
        'tipo_riga' => 'dare',
        'importo' => 2000,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'avere',
        'importo' => 2000,
    ]);

    DB::table('deleghe_f24')->insert([
        'uuid' => (string) Str::uuid(),
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'scrittura_contabile_id' => $scrittura->id,
        'plafond' => 'erario',
        'data_scadenza' => now()->format('Y-m-d'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $righe = (new RegistroContabilitaService)->registro($esercizio);

    expect($righe)->toHaveCount(1);
    expect($righe[0]['uscita'])->toBe(2000);
    expect($righe[0]['controparte'])->toBe('Erario');
});

test('un accantonamento banca-fondo non compare: nessuna delle due righe è denaro che si muove', function () {
    [$condominio, $esercizio, $gestione] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca Test');
    $fondo = creaCassaRegistro($condominio->id, 'fondo', 'Fondo Lavori');

    $scrittura = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::ACCANTONAMENTO->value,
    ]);

    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $fondo->conto_contabile_id,
        'cassa_id' => $fondo->id,
        'tipo_riga' => 'dare',
        'importo' => 8000,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'avere',
        'importo' => 8000,
    ]);

    $righe = (new RegistroContabilitaService)->registro($esercizio);

    // ⚠️ La riga sulla banca è una riga vera (dare/avere quadrano), ma la sua sorella è
    // su un fondo: per D15 non è denaro che si muove, e non deve comparire — nemmeno lei.
    expect($righe)->toHaveCount(0);
});

test('una fattura di acquisto non pagata non tocca nessuna cassa e non compare', function () {
    [$condominio, $esercizio, $gestione, $contoDebiti] = setupRegistro();
    creaCassaRegistro($condominio->id, 'banca', 'Banca Test');
    $contoCosto = creaContoAppoggio($condominio->id, 'Spese condominiali');

    $scrittura = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::FATTURA_ACQUISTO->value,
    ]);

    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $contoCosto,
        'tipo_riga' => 'dare',
        'importo' => 3000,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $contoDebiti,
        'tipo_riga' => 'avere',
        'importo' => 3000,
    ]);

    $righe = (new RegistroContabilitaService)->registro($esercizio);

    expect($righe)->toHaveCount(0);
});

test('un giroconto fra due casse reali produce due righe, una per verso, con la cassa gemella come controparte', function () {
    [$condominio, $esercizio, $gestione] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca Test');
    $contanti = creaCassaRegistro($condominio->id, 'contanti', 'Cassa Contanti');

    $scrittura = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::GIROCONTO->value,
    ]);

    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $contanti->conto_contabile_id,
        'cassa_id' => $contanti->id,
        'tipo_riga' => 'dare',
        'importo' => 1500,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'avere',
        'importo' => 1500,
    ]);

    $righe = collect((new RegistroContabilitaService)->registro($esercizio));

    expect($righe)->toHaveCount(2);

    $ricevuta = $righe->firstWhere('entrata', 1500);
    $uscita = $righe->firstWhere('uscita', 1500);

    expect($ricevuta['controparte'])->toBe('Banca Test');
    expect($uscita['controparte'])->toBe('Cassa Contanti');

    // La cassa PROPRIA della riga, non quella gemella: chi ha più di un conto reale deve
    // poter dire quale dei due si è mosso — richiesto a video da Vincenzo aprendo la pagina.
    expect($ricevuta['cassa'])->toBe('Cassa Contanti');
    expect($uscita['cassa'])->toBe('Banca Test');

    // ⚠️ **I due saldi non sono lo stesso numero, ed è il punto.** Quello complessivo si elide
    // (il denaro non è entrato né uscito dal condominio, ha solo cambiato conto); quello della
    // singola cassa dice dove è andato. È la domanda a cui la colonna «Saldo» non può rispondere
    // con più di una cassa reale, e per cui esiste il pannello espanso.
    expect($righe->last()['saldo_progressivo'])->toBe(0);
    expect($ricevuta['saldo_cassa_progressivo'])->toBe(1500);
    expect($uscita['saldo_cassa_progressivo'])->toBe(-1500);
});

test('lo storno di un incasso resta visibile insieme alla rettifica, e la coppia si annulla nel saldo', function () {
    [$condominio, $esercizio, $gestione, $contoDebiti] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca Test');
    $anagrafica = Anagrafica::factory()->create(['nome' => 'Luigi Verdi']);

    $originale = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::INCASSO_RATA->value,
        'data_competenza' => now()->subDays(5)->format('Y-m-d'),
    ]);
    RigaScrittura::create([
        'scrittura_id' => $originale->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'dare',
        'importo' => 7000,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $originale->id,
        'conto_contabile_id' => $contoDebiti,
        'anagrafica_id' => $anagrafica->id,
        'tipo_riga' => 'avere',
        'importo' => 7000,
    ]);

    // Storno, stesso schema di StornoIncassoRateAction: l'originale resta intatta e
    // passa a 'annullata', una rettifica la specchia (dare/avere invertiti).
    $rettifica = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'scrittura_padre_id' => $originale->id,
        'tipo_movimento' => TipoMovimentoContabile::RETTIFICA->value,
        'causale' => 'Storno: '.$originale->causale,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $rettifica->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'avere',
        'importo' => 7000,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $rettifica->id,
        'conto_contabile_id' => $contoDebiti,
        'anagrafica_id' => $anagrafica->id,
        'tipo_riga' => 'dare',
        'importo' => 7000,
    ]);
    $originale->update(['stato' => 'annullata']);

    $righe = collect((new RegistroContabilitaService)->registro($esercizio));

    expect($righe)->toHaveCount(2);
    expect($righe->last()['saldo_progressivo'])->toBe(0);
    expect($righe->pluck('controparte')->unique()->all())->toBe(['Luigi Verdi']);
});

test('i filtri per data restringono il registro come nel Libro Giornale', function () {
    [$condominio, $esercizio, $gestione] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca Test');

    $vecchia = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::INCASSO_RATA->value,
        'data_competenza' => '2026-01-10',
    ]);
    RigaScrittura::create([
        'scrittura_id' => $vecchia->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'dare',
        'importo' => 1000,
    ]);

    $recente = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::INCASSO_RATA->value,
        'data_competenza' => '2026-06-10',
    ]);
    RigaScrittura::create([
        'scrittura_id' => $recente->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'dare',
        'importo' => 2000,
    ]);

    $righe = (new RegistroContabilitaService)->registro($esercizio, ['data_da' => '2026-03-01']);

    expect($righe)->toHaveCount(1);
    expect($righe[0]['entrata'])->toBe(2000);
});

/**
 * ⚠️ **Il difetto che questo test blocca è quello che manda l'amministratore in assemblea con un
 * numero falso.** Filtrando un periodo, la colonna «Saldo» deve continuare a dire quanto c'era
 * davvero sul conto a quella data — non il netto delle sole righe mostrate. La norma vuole questo
 * registro proprio per dare «un continuo e diretto controllo, in tempo reale, della situazione
 * contabile e delle somme a disposizione del condominio»: un saldo che riparte da zero a ogni
 * filtro non è una comodità mancante, è una cifra sbagliata su un documento che finisce davanti
 * a un CTU. Stesso discorso per il numero d'operazione (D5): identifica il movimento, quindi non
 * può cambiare a seconda di cosa si sta guardando.
 */
test('filtrando un periodo, numero e saldo restano quelli dell\'esercizio intero', function () {
    [$condominio, $esercizio, $gestione] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca Test');

    // Tre movimenti: due a gennaio, uno a giugno. Il saldo a giugno vale 15.000 solo se i due
    // di gennaio continuano a contare anche quando non si vedono.
    foreach ([['2026-01-10', 10000], ['2026-01-20', 3000], ['2026-06-10', 2000]] as [$data, $importo]) {
        $scrittura = creaScrittura([
            'condominio_id' => $condominio->id,
            'esercizio_id' => $esercizio->id,
            'gestione_id' => $gestione->id,
            'tipo_movimento' => TipoMovimentoContabile::INCASSO_RATA->value,
            'data_competenza' => $data,
        ]);
        RigaScrittura::create([
            'scrittura_id' => $scrittura->id,
            'conto_contabile_id' => $banca->conto_contabile_id,
            'cassa_id' => $banca->id,
            'tipo_riga' => 'dare',
            'importo' => $importo,
        ]);
    }

    $intero = (new RegistroContabilitaService)->registro($esercizio);
    expect($intero)->toHaveCount(3);
    expect(array_column($intero, 'numero'))->toBe([1, 2, 3]);
    expect(array_column($intero, 'saldo_progressivo'))->toBe([10000, 13000, 15000]);

    $giugno = (new RegistroContabilitaService)->registro($esercizio, ['data_da' => '2026-06-01']);

    expect($giugno)->toHaveCount(1);
    expect($giugno[0]['numero'])->toBe(3);
    expect($giugno[0]['saldo_progressivo'])->toBe(15000);
    expect($giugno[0]['saldo_cassa_progressivo'])->toBe(15000);
});

test('la ricerca guarda anche la controparte e la cassa, non solo causale e protocollo', function () {
    [$condominio, $esercizio, $gestione, $contoDebiti] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca Test');
    $anagrafica = Anagrafica::factory()->create(['nome' => 'Giovanna Neri']);

    $scrittura = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::INCASSO_RATA->value,
        'causale' => 'Versamento rata',
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'dare',
        'importo' => 9000,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $contoDebiti,
        'anagrafica_id' => $anagrafica->id,
        'tipo_riga' => 'avere',
        'importo' => 9000,
    ]);

    $service = new RegistroContabilitaService;

    // Chi cerca un condòmino nel registro cerca il nome che vede nella colonna, non la causale.
    expect($service->registro($esercizio, ['search' => 'Neri']))->toHaveCount(1);
    expect($service->registro($esercizio, ['search' => 'banca test']))->toHaveCount(1);
    expect($service->registro($esercizio, ['search' => 'versamento']))->toHaveCount(1);
    expect($service->registro($esercizio, ['search' => 'inesistente']))->toHaveCount(0);
});

test('un filtro data non interpretabile non fa saltare il registro: vale come nessun filtro', function () {
    [$condominio, $esercizio, $gestione] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca Test');

    $scrittura = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::INCASSO_RATA->value,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'dare',
        'importo' => 500,
    ]);

    $service = new RegistroContabilitaService;

    expect($service->registro($esercizio, ['data_da' => 'pippo']))->toHaveCount(1);
    expect($service->registro($esercizio, ['data_a' => ['array']]))->toHaveCount(1);
});

/**
 * ⚠️ **Un conto contabile non è una controparte.** La norma chiede «il destinatario del pagamento
 * o il soggetto che lo ha corrisposto»: un soggetto. Una versione precedente ripiegava sul nome
 * del conto della riga sorella e in stampa uscivano controparti come «Attivo» o «Costi per
 * Servizi» — visto solo generando il registro con 5.000 movimenti. La cella vuota è più onesta.
 */
test('quando non c\'è un soggetto la controparte resta vuota, non prende il nome di un conto', function () {
    [$condominio, $esercizio, $gestione] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca Test');
    $contoCosto = creaContoAppoggio($condominio->id, 'Costi per Servizi');

    $scrittura = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::REGOLAZIONE_IMMEDIATA->value,
        'causale' => 'Imposta di bollo su estratto conto',
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $contoCosto,
        'tipo_riga' => 'dare',
        'importo' => 1668,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'avere',
        'importo' => 1668,
    ]);

    $righe = (new RegistroContabilitaService)->registro($esercizio);

    expect($righe)->toHaveCount(1);
    expect($righe[0]['controparte'])->toBeNull();
});

test('un giroconto fra due fondi non compare: nessuna delle due righe è su una cassa reale', function () {
    [$condominio, $esercizio, $gestione] = setupRegistro();
    $fondoA = creaCassaRegistro($condominio->id, 'fondo', 'Fondo Lavori');
    $fondoB = creaCassaRegistro($condominio->id, 'fondo', 'Fondo Morosità');

    $scrittura = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::GIROCONTO->value,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $fondoB->conto_contabile_id,
        'cassa_id' => $fondoB->id,
        'tipo_riga' => 'dare',
        'importo' => 4000,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $fondoA->conto_contabile_id,
        'cassa_id' => $fondoA->id,
        'tipo_riga' => 'avere',
        'importo' => 4000,
    ]);

    $righe = (new RegistroContabilitaService)->registro($esercizio);

    expect($righe)->toHaveCount(0);
});

/**
 * ⚠️ **Fase 1-bis**: `StornaVersamentoF24Action` non collegava lo storno all'originale con
 * `scrittura_padre_id` — unico storno del progetto a non farlo (viola il contratto scritto in
 * `ScritturaContabile::padre()/figlie()`). Senza quel collegamento la riga di cassa dello storno
 * non trovava più «Erario»: cadeva sulla quarta fonte (conto sorella) e mostrava un nome di
 * conto tecnico. Corretto nell'Action; questo test lo prova sulla query del registro.
 */
test('lo storno di un versamento F24 mostra ancora l\'erario come controparte', function () {
    [$condominio, $esercizio, $gestione, $contoDebiti] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca Test');

    $originale = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'tipo_movimento' => TipoMovimentoContabile::PAGAMENTO_F24->value,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $originale->id,
        'conto_contabile_id' => $contoDebiti,
        'tipo_riga' => 'dare',
        'importo' => 3000,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $originale->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'avere',
        'importo' => 3000,
    ]);
    DB::table('deleghe_f24')->insert([
        'uuid' => (string) Str::uuid(),
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'scrittura_contabile_id' => $originale->id,
        'plafond' => 'erario',
        'data_scadenza' => now()->format('Y-m-d'),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Stesso schema di StornaVersamentoF24Action dopo la correzione: scrittura_padre_id
    // valorizzato, nessuna riga di `deleghe_f24` propria (resta sull'originale).
    $storno = creaScrittura([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'scrittura_padre_id' => $originale->id,
        'tipo_movimento' => TipoMovimentoContabile::STORNO_PAGAMENTO_F24->value,
        'causale' => 'Storno versamento F24 — errore',
    ]);
    RigaScrittura::create([
        'scrittura_id' => $storno->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'dare',
        'importo' => 3000,
    ]);
    RigaScrittura::create([
        'scrittura_id' => $storno->id,
        'conto_contabile_id' => $contoDebiti,
        'tipo_riga' => 'avere',
        'importo' => 3000,
    ]);

    $righe = collect((new RegistroContabilitaService)->registro($esercizio));

    expect($righe)->toHaveCount(2);
    expect($righe->pluck('controparte')->unique()->all())->toBe(['Erario']);
});

/**
 * ⚠️ **Il test che non c'era, e per questo il flag non scattava mai.** Nessun test creava uno
 * scarto fra data del movimento e data di annotazione: tutti usavano `now()` per entrambe. La
 * formula era scritta al contrario — `registrazione->diffInDays(competenza)`, che da Carbon 3
 * vale `competenza − registrazione`, negativo in ogni annotazione tardiva — e con 73 giorni di
 * ritardo rispondeva `false`. Trovato dalla revisione della beta.24, da due lenti indipendenti.
 * I quattro casi: ben oltre, il limite superato di uno, il limite esatto, e il postdatato.
 */
test('il ritardo di annotazione scatta oltre i trenta giorni, e non sul movimento postdatato', function () {
    [$condominio, $esercizio, $gestione, $contoDebiti] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca');

    $casi = [
        // [competenza, registrazione, atteso, perché]
        ['2026-01-01', '2026-03-15', true,  'annotato 73 giorni dopo'],
        ['2026-01-01', '2026-02-01', true,  'annotato 31 giorni dopo: il limite è superato'],
        ['2026-01-01', '2026-01-31', false, 'annotato 30 giorni dopo: entro il termine'],
        ['2026-03-15', '2026-01-01', false, 'postdatato: annotato prima della sua data, nessun ritardo'],
    ];

    foreach ($casi as [$competenza, $registrazione, $atteso, $perche]) {
        $scrittura = creaScrittura([
            'condominio_id' => $condominio->id,
            'esercizio_id' => $esercizio->id,
            'gestione_id' => $gestione->id,
            'data_competenza' => $competenza,
            'data_registrazione' => $registrazione,
            'causale' => $perche,
            'tipo_movimento' => TipoMovimentoContabile::INCASSO_RATA->value,
        ]);
        RigaScrittura::create(['scrittura_id' => $scrittura->id, 'conto_contabile_id' => $banca->conto_contabile_id, 'cassa_id' => $banca->id, 'tipo_riga' => 'dare', 'importo' => 1000]);
        RigaScrittura::create(['scrittura_id' => $scrittura->id, 'conto_contabile_id' => $contoDebiti, 'cassa_id' => null, 'tipo_riga' => 'avere', 'importo' => 1000]);
    }

    $righe = app(RegistroContabilitaService::class)->registro($esercizio);
    $perCausale = collect($righe)->keyBy('descrizione');

    foreach ($casi as [, , $atteso, $perche]) {
        expect($perCausale[$perche]['oltre_trenta_giorni'])->toBe($atteso, $perche);
    }
});

/**
 * ⚠️ **Tre storni su quattro non marcavano l'originale, e la legenda della stampa prometteva il
 * contrario.** Solo `StornoIncassoRateAction` mette `stato = 'annullata'` sull'originale;
 * giroconto, regolazione immediata e F24 scrivono la contraria con `scrittura_padre_id` e basta.
 * Il registro guardava lo stato, quindi un giroconto stornato usciva in stampa senza «stornata»
 * sotto una nota che cita l'art. 2219. Ora il segnale è la figlia di tipo `storno_*`, e questo
 * test lo tiene fermo sul caso che prima falliva in silenzio. Le righe dello storno stesso NON
 * sono marcate: sono la correzione, non l'operazione corretta.
 */
test('un giroconto stornato è marcato stornato anche se il DB lo lascia registrato', function () {
    [$condominio, $esercizio, $gestione] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca');
    $contanti = creaCassaRegistro($condominio->id, 'contanti', 'Contanti');

    $originale = creaScrittura([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
        'data_competenza' => '2026-03-01', 'data_registrazione' => '2026-03-01',
        'causale' => 'Prelievo', 'tipo_movimento' => TipoMovimentoContabile::GIROCONTO->value,
    ]);
    RigaScrittura::create(['scrittura_id' => $originale->id, 'conto_contabile_id' => $contanti->conto_contabile_id, 'cassa_id' => $contanti->id, 'tipo_riga' => 'dare', 'importo' => 5000]);
    RigaScrittura::create(['scrittura_id' => $originale->id, 'conto_contabile_id' => $banca->conto_contabile_id, 'cassa_id' => $banca->id, 'tipo_riga' => 'avere', 'importo' => 5000]);

    // Lo storno com'è davvero a DB dopo StornaGirocontoAction: figlia storno_giroconto,
    // originale ancora `registrata`.
    $storno = creaScrittura([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
        'data_competenza' => '2026-03-05', 'data_registrazione' => '2026-03-05',
        'causale' => 'Storno prelievo', 'tipo_movimento' => TipoMovimentoContabile::STORNO_GIROCONTO->value,
        'scrittura_padre_id' => $originale->id,
    ]);
    RigaScrittura::create(['scrittura_id' => $storno->id, 'conto_contabile_id' => $banca->conto_contabile_id, 'cassa_id' => $banca->id, 'tipo_riga' => 'dare', 'importo' => 5000]);
    RigaScrittura::create(['scrittura_id' => $storno->id, 'conto_contabile_id' => $contanti->conto_contabile_id, 'cassa_id' => $contanti->id, 'tipo_riga' => 'avere', 'importo' => 5000]);

    expect($originale->fresh()->stato)->toBe('registrata');

    $righe = collect(app(RegistroContabilitaService::class)->registro($esercizio));

    expect($righe)->toHaveCount(4)
        ->and($righe->where('scrittura_id', $originale->id)->pluck('stornata')->all())->toBe([true, true])
        ->and($righe->where('scrittura_id', $storno->id)->pluck('stornata')->all())->toBe([false, false]);
});

/**
 * ⚠️ **Il riepilogo si calcola sull'esercizio intero a una data, mai sulle righe filtrate.**
 * Tre reperti della revisione della beta.24 avevano questa radice: una cassa ferma nel periodo
 * spariva dal dettaglio (e con lei il conto scoperto), una cassa con un movimento nascosto dal
 * filtro portava il saldo di un'altra data, e con zero righe il saldo diventava lo zero del `??`.
 * Quattro movimenti su due casse, tre modi di filtrarli: in tutti e tre il dettaglio deve
 * sommare al totale e il totale deve essere quello vero alla data dichiarata.
 */
test('il riepilogo per cassa è alla data di riferimento, e somma sempre al totale', function () {
    [$condominio, $esercizio, $gestione, $contoDebiti] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca');
    $contanti = creaCassaRegistro($condominio->id, 'contanti', 'Contanti');

    $movimenti = [
        ['2026-03-01', $banca,    'dare',  100000, 'Zeta incasso'],
        ['2026-03-10', $contanti, 'dare',   50000, 'Zeta incasso 2'],
        ['2026-03-20', $banca,    'avere',  30000, 'Uscita'],
        ['2026-04-05', $contanti, 'avere',  10000, 'Zeta uscita'],
    ];
    foreach ($movimenti as [$data, $cassa, $verso, $importo, $causale]) {
        $sc = creaScrittura([
            'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
            'data_competenza' => $data, 'data_registrazione' => $data, 'causale' => $causale,
            'tipo_movimento' => TipoMovimentoContabile::INCASSO_RATA->value,
        ]);
        RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $cassa->conto_contabile_id, 'cassa_id' => $cassa->id, 'tipo_riga' => $verso, 'importo' => $importo]);
        RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $contoDebiti, 'cassa_id' => null, 'tipo_riga' => $verso === 'dare' ? 'avere' : 'dare', 'importo' => $importo]);
    }
    $servizio = app(RegistroContabilitaService::class);
    $perCassa = fn (array $r) => collect($r['saldi_per_cassa'])->keyBy('cassa');

    // (1) Filtro di periodo in cui la banca è ferma: deve comparire lo stesso, col suo saldo vero.
    $r = $servizio->registroConRiepilogo($esercizio, ['data_da' => '2026-04-01']);
    expect($r['righe'])->toHaveCount(1)
        ->and($r['data_riferimento'])->toBe('2026-04-05')
        ->and($r['saldo_finale'])->toBe(110000)
        ->and($perCassa($r)['Banca']['saldo'])->toBe(70000)
        ->and($perCassa($r)['Banca']['movimenti'])->toBe(0)
        ->and($perCassa($r)['Contanti']['saldo'])->toBe(40000)
        ->and($perCassa($r)['Contanti']['movimenti'])->toBe(1);

    // (2) Ricerca che nasconde l'uscita banca del 20/03: il saldo banca è quello al 05/04 (700),
    //     non quello dell'ultimo movimento MOSTRATO della banca (1.000). E 700 + 400 = 1.100.
    $r = $servizio->registroConRiepilogo($esercizio, ['search' => 'Zeta']);
    expect($r['righe'])->toHaveCount(3)
        ->and($perCassa($r)['Banca']['saldo'])->toBe(70000)
        ->and($perCassa($r)['Banca']['movimenti'])->toBe(1)
        ->and($perCassa($r)['Contanti']['saldo'])->toBe(40000)
        ->and(collect($r['saldi_per_cassa'])->sum('saldo'))->toBe($r['saldo_finale']);

    // (3) Nessuna riga nel periodo: il saldo è quello vero a `data_a`, non zero.
    $r = $servizio->registroConRiepilogo($esercizio, ['data_da' => '2026-05-01', 'data_a' => '2026-05-31']);
    expect($r['righe'])->toBe([])
        ->and($r['data_riferimento'])->toBe('2026-05-31')
        ->and($r['saldo_finale'])->toBe(110000)
        ->and($perCassa($r)['Banca']['saldo'])->toBe(70000)
        ->and($perCassa($r)['Contanti']['saldo'])->toBe(40000)
        ->and($perCassa($r)['Contanti']['movimenti'])->toBe(0);
});

/**
 * ⚠️ **La regolazione immediata «tagga» il fornitore con l'anagrafica del suo referente — una
 * persona — e il registro stampava lei al posto della società pagata.** Il ramo nuovo risale dal
 * referente al fornitore, ma solo se il referente appartiene a un fornitore solo: chi tiene i
 * conti di due ditte non decide per noi quale sia stata pagata, e lì resta il nome della persona.
 */
test('la controparte di una regolazione immediata è il fornitore, non il suo referente', function () {
    [$condominio, $esercizio, $gestione, $contoCosto] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca');

    $registra = function (string $causale, int $anagraficaId) use ($condominio, $esercizio, $gestione, $banca, $contoCosto) {
        $sc = creaScrittura([
            'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
            'causale' => $causale, 'tipo_movimento' => TipoMovimentoContabile::REGOLAZIONE_IMMEDIATA->value,
        ]);
        RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $contoCosto, 'cassa_id' => null, 'tipo_riga' => 'dare', 'importo' => 1668, 'anagrafica_id' => $anagraficaId]);
        RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $banca->conto_contabile_id, 'cassa_id' => $banca->id, 'tipo_riga' => 'avere', 'importo' => 1668]);
    };

    // Un referente di UNA sola ditta: il registro deve dire la ditta.
    $rossi = \App\Models\Anagrafica::factory()->create(['nome' => 'Mario Rossi']);
    $impianti = creaFornitoreRegistro('Mario Rossi Impianti s.r.l.');
    DB::table('anagrafica_fornitore')->insert(['fornitore_id' => $impianti, 'anagrafica_id' => $rossi->id, 'ruolo' => 'titolare', 'created_at' => now(), 'updated_at' => now()]);
    $registra('Bollo pagato a Impianti', $rossi->id);

    // Un referente di DUE ditte: nessuna decisione al posto nostro, resta la persona.
    $bianchi = \App\Models\Anagrafica::factory()->create(['nome' => 'Luca Bianchi']);
    foreach (['Bianchi Pulizie s.n.c.', 'Bianchi Giardini s.r.l.'] as $rs) {
        $f = creaFornitoreRegistro($rs);
        DB::table('anagrafica_fornitore')->insert(['fornitore_id' => $f, 'anagrafica_id' => $bianchi->id, 'ruolo' => 'amministrativo', 'created_at' => now(), 'updated_at' => now()]);
    }
    $registra('Bollo pagato a Bianchi', $bianchi->id);

    $perCausale = collect(app(RegistroContabilitaService::class)->registro($esercizio))->keyBy('descrizione');

    expect($perCausale['Bollo pagato a Impianti']['controparte'])->toBe('Mario Rossi Impianti s.r.l.')
        ->and($perCausale['Bollo pagato a Bianchi']['controparte'])->toBe('Luca Bianchi');
});

/**
 * ⚠️ **La rettifica di un incasso portava la data dell'incasso, e il registro mentiva fra le
 * due date.** Incasso del 1° marzo, stornato il 20 aprile: con la rettifica retrodatata al 1°
 * marzo, il saldo del 15 marzo diceva che quei soldi non c'erano — mentre c'erano, e sono usciti
 * solo il 20 aprile. Era l'unico storno del gestionale a retrodatare (Coda 93). Qui si passa
 * dall'azione vera, non da una scrittura costruita a mano.
 */
test('lo storno di un incasso ha la data dello storno, e il saldo fra le due date è quello vero', function () {
    [$condominio, $esercizio, $gestione, $contoDebiti] = setupRegistro();
    $banca = creaCassaRegistro($condominio->id, 'banca', 'Banca');

    $incasso = creaScrittura([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
        'data_competenza' => '2026-03-01', 'data_registrazione' => '2026-03-01',
        'causale' => 'Incasso rata', 'tipo_movimento' => TipoMovimentoContabile::INCASSO_RATA->value,
    ]);
    RigaScrittura::create(['scrittura_id' => $incasso->id, 'conto_contabile_id' => $banca->conto_contabile_id, 'cassa_id' => $banca->id, 'tipo_riga' => 'dare', 'importo' => 50000]);
    RigaScrittura::create(['scrittura_id' => $incasso->id, 'conto_contabile_id' => $contoDebiti, 'cassa_id' => null, 'tipo_riga' => 'avere', 'importo' => 50000]);

    \Carbon\Carbon::setTestNow('2026-04-20 10:00:00');
    app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute($incasso, $condominio);
    \Carbon\Carbon::setTestNow();

    $rettifica = \App\Models\Gestionale\ScritturaContabile::where('scrittura_padre_id', $incasso->id)->firstOrFail();
    expect($rettifica->data_competenza->format('Y-m-d'))->toBe('2026-04-20');

    // Al 15 marzo il denaro c'era: il registro filtrato fino a quel giorno lo deve dire.
    $r = app(RegistroContabilitaService::class)->registroConRiepilogo($esercizio, ['data_a' => '2026-03-15']);
    expect($r['saldo_finale'])->toBe(50000)
        ->and($r['righe'])->toHaveCount(1)
        ->and($r['righe'][0]['stornata'])->toBeTrue();

    // A fine aprile la coppia si è annullata.
    $r = app(RegistroContabilitaService::class)->registroConRiepilogo($esercizio, ['data_a' => '2026-04-30']);
    expect($r['saldo_finale'])->toBe(0)->and($r['righe'])->toHaveCount(2);
});
