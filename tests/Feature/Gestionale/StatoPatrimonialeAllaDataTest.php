<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\StatoPatrimonialeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * D16 — la situazione patrimoniale è uno STOCK a una data, su tutti gli esercizi.
 *
 * ⚠️ **Valori attesi calcolati a mano, non letti da `calcola()`.** Prima di questa beta nessun
 * test del motore aveva costi ≠ 0 né dati su due esercizi, e l'unico test del riquadro
 * confrontava le prop con `calcola()` stesso — cioè non provava niente. Lo scenario qui sotto
 * è scritto per far divergere il flusso per esercizio dalla fotografia a una data: è la
 * differenza che D16 esiste per rendere visibile.
 */
function spdConto(int $condominioId, string $ruolo, string $codice, string $tipo, string $categoria): int
{
    return DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId, 'ruolo' => $ruolo, 'codice' => $codice, 'nome' => ucfirst(str_replace('_', ' ', $ruolo)),
        'tipo' => $tipo, 'categoria' => $categoria, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function spdScrittura(array $ctx, Esercizio $esercizio, string $data, int $contoDare, int $contoAvere, int $importo, string $causale, string $tipo = 'regolazione_immediata'): void
{
    $sc = ScritturaContabile::create([
        'condominio_id' => $ctx['condominio']->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $ctx['gestione']->id,
        'data_registrazione' => $data, 'data_competenza' => $data, 'causale' => $causale,
        'tipo_movimento' => $tipo, 'stato' => 'registrata',
    ]);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $contoDare, 'tipo_riga' => 'dare', 'importo' => $importo]);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $contoAvere, 'tipo_riga' => 'avere', 'importo' => $importo]);
}

/**
 * Due esercizi, un piano dei conti minimo, sei scritture. I numeri (in euro, poi centesimi):
 *
 *   2025-01-01  apertura banca          DARE banca 5.000   / AVERE passate gestioni 5.000
 *   2025-03-01  emissione rate          DARE crediti 3.000 / AVERE gestione rate 3.000
 *   2025-04-01  incasso                 DARE banca 2.000   / AVERE crediti 2.000
 *   2025-06-01  fattura                 DARE costi 1.500   / AVERE debiti 1.500
 *   2025-07-01  pagamento               DARE debiti 1.000  / AVERE banca 1.000
 *   2026-02-01  incasso (esercizio 2026) DARE banca 500    / AVERE crediti 500
 */
function spdScenario(): array
{
    $condominio = Condominio::factory()->create();
    $es2025 = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $es2026 = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);
    $ctx = ['condominio' => $condominio, 'gestione' => $gestione];

    $c = [
        'banca'    => spdConto($condominio->id, 'conto_bancario',    '1010', 'attivo',  'liquidita'),
        'crediti'  => spdConto($condominio->id, 'crediti_condomini', '1101', 'attivo',  'crediti'),
        'debiti'   => spdConto($condominio->id, 'debiti_fornitori',  '2201', 'passivo', 'debiti'),
        'gestione' => spdConto($condominio->id, 'gestione_rate',     '3001', 'passivo', 'fondi'),
        'passate'  => spdConto($condominio->id, 'passate_gestioni',  '3002', 'passivo', 'fondi'),
        'costi'    => spdConto($condominio->id, 'costi_servizi',     '5001', 'costo',   'costi'),
    ];

    spdScrittura($ctx, $es2025, '2025-01-01', $c['banca'],   $c['passate'],  500000, 'Apertura banca', 'apertura');
    spdScrittura($ctx, $es2025, '2025-03-01', $c['crediti'], $c['gestione'], 300000, 'Emissione rate');
    spdScrittura($ctx, $es2025, '2025-04-01', $c['banca'],   $c['crediti'],  200000, 'Incasso');
    spdScrittura($ctx, $es2025, '2025-06-01', $c['costi'],   $c['debiti'],   150000, 'Fattura');
    spdScrittura($ctx, $es2025, '2025-07-01', $c['debiti'],  $c['banca'],    100000, 'Pagamento');
    spdScrittura($ctx, $es2026, '2026-02-01', $c['banca'],   $c['crediti'],   50000, 'Incasso 2026');

    return [$condominio, $es2025, $es2026, $c];
}

test('la situazione a fine 2025 è la fotografia di tutto quello che è successo fino a lì', function () {
    [$condominio] = spdScenario();

    $sp = app(StatoPatrimonialeService::class)->calcola($condominio, allaData: '2025-12-31');
    $voci = collect(array_merge($sp['attivo']['voci'], $sp['passivo']['voci']))->keyBy('ruolo');

    // banca 5.000 + 2.000 − 1.000 = 6.000; crediti 3.000 − 2.000 = 1.000
    expect($voci['conto_bancario']['saldo'])->toBe(600000)
        ->and($voci['crediti_condomini']['saldo'])->toBe(100000)
        ->and($sp['attivo']['totale'])->toBe(700000)
        // debiti 1.500 − 1.000 = 500; gestione rate 3.000; passate gestioni 5.000
        ->and($voci['debiti_fornitori']['saldo'])->toBe(50000)
        ->and($voci['gestione_rate']['saldo'])->toBe(300000)
        ->and($voci['passate_gestioni']['saldo'])->toBe(500000)
        ->and($sp['passivo']['totale'])->toBe(850000)
        // nessun conto ricavo: il risultato del motore è −costi
        ->and($sp['costi'])->toBe(150000)
        ->and($sp['ricavi'])->toBe(0)
        ->and($sp['risultato_esercizio'])->toBe(-150000)
        // 7.000 − 8.500 − (−1.500) = 0
        ->and($sp['sbilancio'])->toBe(0)
        ->and($sp['quadra'])->toBeTrue();
});

test('a metà anno la fotografia si ferma alla data: la fattura di giugno non c\'è ancora', function () {
    [$condominio] = spdScenario();

    $sp = app(StatoPatrimonialeService::class)->calcola($condominio, allaData: '2025-05-15');
    $voci = collect(array_merge($sp['attivo']['voci'], $sp['passivo']['voci']))->keyBy('ruolo');

    expect($voci['conto_bancario']['saldo'])->toBe(700000)
        ->and($voci['crediti_condomini']['saldo'])->toBe(100000)
        ->and($voci->has('debiti_fornitori'))->toBeFalse('a maggio il debito non esiste ancora, e un conto a zero non compare')
        ->and($sp['attivo']['totale'])->toBe(800000)
        ->and($sp['passivo']['totale'])->toBe(800000)
        ->and($sp['costi'])->toBe(0)
        ->and($sp['sbilancio'])->toBe(0);
});

/**
 * ⚠️ **Il flusso per esercizio non è una fotografia dei conti — è la ragione di D16.** Nel 2026
 * c'è un solo movimento, un incasso: letto per esercizio, l'attivo vale zero (banca +500, crediti
 * −500) e i debiti, le quote deliberate, l'apertura sono spariti. Letto a una data del 2026, la
 * fotografia porta dentro tutto il 2025. Entrambi quadrano — R6 è meccanica — ma solo il secondo
 * dice quanto c'è sul conto.
 */
test('dal secondo esercizio la fotografia a una data e il flusso per esercizio divergono, e solo la prima è vera', function () {
    [$condominio, , $es2026] = spdScenario();
    $servizio = app(StatoPatrimonialeService::class);

    $flusso = $servizio->calcola($condominio, $es2026);
    $foto = $servizio->calcola($condominio, allaData: '2026-12-31');

    expect($flusso['attivo']['totale'])->toBe(0)
        ->and($flusso['passivo']['totale'])->toBe(0)
        ->and($flusso['quadra'])->toBeTrue();

    $voci = collect($foto['attivo']['voci'])->keyBy('ruolo');
    expect($voci['conto_bancario']['saldo'])->toBe(650000)
        ->and($voci['crediti_condomini']['saldo'])->toBe(50000)
        ->and($foto['attivo']['totale'])->toBe(700000)
        ->and($foto['passivo']['totale'])->toBe(850000)
        ->and($foto['risultato_esercizio'])->toBe(-150000)
        ->and($foto['quadra'])->toBeTrue();
});

test('esercizio e data insieme sono una domanda ambigua, e il motore la rifiuta', function () {
    [$condominio, $es2025] = spdScenario();

    expect(fn () => app(StatoPatrimonialeService::class)->calcola($condominio, $es2025, '2025-12-31'))
        ->toThrow(InvalidArgumentException::class);
});

test('i saldi di apertura non registrati a giornale entrano nell\'attivo anche a una data, e sbilanciano', function () {
    [$condominio] = spdScenario();
    $contoId = spdConto($condominio->id, 'conto_bancario', '1011', 'attivo', 'liquidita');
    DB::table('casse')->insert([
        'condominio_id' => $condominio->id, 'conto_contabile_id' => $contoId, 'nome' => 'Contanti', 'tipo' => 'contanti',
        'saldo_iniziale' => 25000, 'attiva' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $sp = app(StatoPatrimonialeService::class)->calcola($condominio, allaData: '2025-12-31');

    expect($sp['liquidita_non_contabilizzata'])->toBe(25000)
        ->and($sp['attivo']['totale'])->toBe(725000)
        ->and($sp['sbilancio'])->toBe(25000)
        ->and($sp['quadra'])->toBeFalse();
});

/*
 * ─── La pagina assemblata: D18 risultato di gestione, R7 raccordo, R8, R2, gruppi ───────────────
 * Stesso scenario, stessi numeri a mano.
 */

function spdCassaBanca(int $condominioId, int $contoBanca): void
{
    DB::table('casse')->insert([
        'condominio_id' => $condominioId, 'conto_contabile_id' => $contoBanca, 'nome' => 'Conto corrente', 'tipo' => 'banca',
        'saldo_iniziale' => 0, 'attiva' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('D18: il risultato di gestione è quote emesse meno costi, non il −costi del motore', function () {
    [$condominio, $es2025, , $c] = spdScenario();
    spdCassaBanca($condominio->id, $c['banca']);

    $p = app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class)->costruisci($condominio, $es2025, oggi: '2026-09-12');

    expect($p['data'])->toBe('2025-12-31')->and($p['stato_esercizio'])->toBe('chiuso')
        // quote 3.000 − costi 1.500 = avanzo 1.500 da conguagliare; il motore dice −1.500
        ->and($p['risultato_gestione']['quote_emesse'])->toBe(300000)
        ->and($p['risultato_gestione']['costi'])->toBe(150000)
        ->and($p['risultato_gestione']['risultato'])->toBe(150000)
        ->and($p['situazione']['risultato_motore'])->toBe(-150000)
        ->and($p['situazione']['quadra'])->toBeTrue();
});

test('R7: la variazione della liquidità si spiega con risultato, crediti, debiti e riporti — e torna', function () {
    [$condominio, $es2025, $es2026, $c] = spdScenario();
    spdCassaBanca($condominio->id, $c['banca']);
    $servizio = app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class);

    // 2025, flussi senza l'apertura (che è iniziale, non movimento): Δ liquidità 1.000 =
    // 1.500 (risultato) − 1.000 (crediti saliti) + 500 (debiti saliti). Nessuna riga «riporti».
    $r = $servizio->costruisci($condominio, $es2025, oggi: '2026-09-12')['raccordo'];
    $effetti = collect($r['righe'])->pluck('effetto', 'voce');
    expect($r['variazione_liquidita'])->toBe(100000)
        ->and($effetti['Risultato di gestione dell\'esercizio'])->toBe(150000)
        ->and($effetti['Quote emesse e non ancora incassate'])->toBe(-100000)
        ->and($effetti['Fatture registrate e non ancora pagate'])->toBe(50000)
        ->and($effetti->has('Riporti da esercizi precedenti'))->toBeFalse()
        ->and($r['somma'])->toBe(100000)
        ->and($r['quadra'])->toBeTrue();

    // 2026 (aperto, a oggi): Δ liquidità 500 = 0 (nessuna quota, nessun costo) + 500 (crediti scesi: incassati)
    $r = $servizio->costruisci($condominio, $es2026, oggi: '2026-09-12')['raccordo'];
    $effetti = collect($r['righe'])->pluck('effetto', 'voce');
    expect($r['variazione_liquidita'])->toBe(50000)
        ->and($effetti['Risultato di gestione dell\'esercizio'])->toBe(0)
        ->and($effetti['Quote emesse e non ancora incassate'])->toBe(50000)
        ->and($r['quadra'])->toBeTrue();
});

test('i gruppi si etichettano dal ruolo: «Gestione Rate» sta fra le quote deliberate, non fra i fondi', function () {
    [$condominio, $es2025, , $c] = spdScenario();
    spdCassaBanca($condominio->id, $c['banca']);

    $s = app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class)->costruisci($condominio, $es2025, oggi: '2026-09-12')['situazione'];
    $attivo = collect($s['attivo']['gruppi'])->pluck('totale', 'gruppo');
    $passivo = collect($s['passivo']['gruppi'])->pluck('totale', 'gruppo');

    expect($attivo->all())->toBe(['Liquidità' => 600000, 'Crediti verso i condòmini' => 100000])
        ->and($passivo->all())->toBe([
            'Debiti verso fornitori' => 50000,
            'Quote emesse ai condòmini' => 300000,
            'Riporti da esercizi precedenti' => 500000,
        ])
        ->and(array_sum($attivo->all()))->toBe($s['attivo']['totale'])
        ->and(array_sum($passivo->all()))->toBe($s['passivo']['totale']);
});

test('i controlli: R6, R7, R2 quadrano; R8 segnala un vincolo non accantonato e diventa rosso su un fondo scoperto', function () {
    [$condominio, $es2025, $es2026, $c] = spdScenario();
    spdCassaBanca($condominio->id, $c['banca']);
    $servizio = app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class);

    // Esercizio aperto, nessun vincolo: tutto quadra.
    $controlli = collect($servizio->costruisci($condominio, $es2026, oggi: '2026-09-12')['controlli']);
    expect($controlli->pluck('esito', 'id')->all())->toBe(['PD' => 'quadra', 'R6' => 'quadra', 'R7' => 'quadra', 'R8' => 'quadra', 'R2' => 'quadra', 'CN' => 'quadra']);
    // PD è lo stesso totale del Libro Giornale: dare = avere su tutte le scritture fino alla data.
    $pd = $controlli->firstWhere('id', 'PD');
    expect($pd['sinistra'])->toBe($pd['destra'])->and($pd['sinistra'])->toBeGreaterThan(0);
    // Senza vincoli dichiarati R8 non mostra «0 = 0»: dice che non c'è nulla da coprire.
    expect($controlli->firstWhere('id', 'R8')['segno'])->toBe('≤')
        ->and($controlli->firstWhere('id', 'R8')['nota'])->toContain('Nessun contributo è dichiarato vincolato');
    // Esercizio chiuso: R8 non può essere datato e si dichiara, non finge di quadrare.
    $r8Chiuso = collect($servizio->costruisci($condominio, $es2025, oggi: '2026-09-12')['controlli'])->firstWhere('id', 'R8');
    expect($r8Chiuso['esito'])->toBe('segnalazione')->and($r8Chiuso['nota'])->toContain('esercizio aperto');
    // R6 mostra i numeri che compongono l'identità, non «0 = 0»: attività 7.000 + costi 1.500 = passività 8.500
    $r6 = collect($servizio->costruisci($condominio, $es2025, oggi: '2026-09-12')['controlli'])->firstWhere('id', 'R6');
    expect($r6['titolo'])->toBe('Attività + Costi = Passività')
        ->and($r6['sinistra'])->toBe(850000)->and($r6['destra'])->toBe(850000)
        ->and($r6['nome'])->toBe('Quadratura patrimoniale');

    // € 200 dichiarati come fondo vincolato, ancora liquidi, e nessuna cassa fondo che li tenga.
    $immobileId = DB::table('immobili')->insertGetId([
        'condominio_id' => $condominio->id, 'interno' => '1', 'nome' => 'Int. 1', 'descrizione' => '',
        'codice_immobile' => 'SP-'.$condominio->id, 'attivo' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('contributi_versati')->insert([
        'condominio_id' => $condominio->id, 'target_type' => 'x', 'target_id' => 1, 'immobile_id' => $immobileId,
        'importo_cents' => 20000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'liquidita_stato' => 'registrata_in_cassa',
        'created_at' => '2025-06-01', 'updated_at' => '2025-06-01',
    ]);
    // Senza una cassa fondo assegnata il vincolo sta nella liquidità libera: segnalazione, non rosso.
    $p = $servizio->costruisci($condominio, $es2026, oggi: '2026-09-12');
    $r8 = collect($p['controlli'])->firstWhere('id', 'R8');
    expect($p['liquidita']['vincolo_registrato'])->toBe(20000)
        ->and($p['liquidita']['vincolo_non_accantonato'])->toBe(20000)
        ->and($r8['esito'])->toBe('segnalazione')
        ->and($r8['nota'])->toContain('liquidità libera');

    // Un fondo con 50 di saldo a cui sono assegnati 200 di vincolo: rosso, mancano 150.
    $contoFondo = spdConto($condominio->id, 'conto_fondo', '1012', 'attivo', 'liquidita');
    $fondoId = DB::table('casse')->insertGetId([
        'condominio_id' => $condominio->id, 'conto_contabile_id' => $contoFondo, 'nome' => 'Fondo lavori', 'tipo' => 'fondo',
        'sottotipo_fondo' => 'vincolato_lavori', 'saldo_iniziale' => 0, 'attiva' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $gestione = Gestione::where('condominio_id', $condominio->id)->first();
    spdScrittura(['condominio' => $condominio, 'gestione' => $gestione], $es2026, '2026-03-01', $contoFondo, $c['banca'], 5000, 'Accantonamento');
    DB::table('contributi_versati')->where('condominio_id', $condominio->id)->update(['cassa_id' => $fondoId]);

    $p = $servizio->costruisci($condominio, $es2026, oggi: '2026-09-12');
    $r8 = collect($p['controlli'])->firstWhere('id', 'R8');
    // La forma è quella del fac-simile: «vincolato ≤ nei fondi», con il segno vero fra i due numeri.
    expect($p['liquidita']['scoperto_fondi'])->toBe(15000)
        ->and($r8['esito'])->toBe('non_quadra')
        ->and($r8['sinistra'])->toBe(20000)->and($r8['destra'])->toBe(5000)->and($r8['segno'])->toBe('>')
        ->and($r8['azione'])->toBe('giroconti');
});

test('R2 riconosce l\'esercizio con le date sbagliate: tutte le scritture fuori periodo → «modifica l\'esercizio», non il giornale', function () {
    [$condominio, $es2025, $es2026, $c] = spdScenario();
    spdCassaBanca($condominio->id, $c['banca']);
    // L'esercizio «2026» viene spostato al 2027: le sue scritture restano datate 2026, cioè tutte fuori
    // dal periodo. È il caso «Via delle Acacie» (12/09/2026): un esercizio «2025» con tutte le operazioni
    // del 2026. Consigliare di correggere dodici scritture una per una sarebbe il consiglio sbagliato.
    $es2026->update(['data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);

    $r2 = collect(app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class)->costruisci($condominio, $es2026->fresh(), oggi: '2027-06-01')['controlli'])->firstWhere('id', 'R2');

    expect($r2['esito'])->toBe('non_quadra')
        ->and($r2['nota'])->toContain('sono le date dell\'esercizio a essere sbagliate')
        ->and($r2['azione'])->toBe('esercizio');
});

test('CN distingue la banca senza apertura né incassi (→ saldo iniziale) dal movimento sulla cassa sbagliata (→ Prima nota)', function () {
    [$condominio, $es2025, $es2026, $c] = spdScenario();
    spdCassaBanca($condominio->id, $c['banca']);
    $costi = DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', 'costi_servizi')->value('id');
    $gestione = Gestione::where('condominio_id', $condominio->id)->first();
    // Un pagamento da 50 dalla banca nel 2026: la banca ha l'apertura del 2025 (600 di iniziale), quindi
    // NON è «senza apertura»: se andasse sotto zero il rimedio è la Prima nota. Qui non va sotto zero.
    spdScrittura(['condominio' => $condominio, 'gestione' => $gestione], $es2026, '2026-03-01', $costi, $c['banca'], 5000, 'Spesa');
    $cn = collect(app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class)->costruisci($condominio, $es2026, oggi: '2026-09-12')['controlli'])->firstWhere('id', 'CN');
    expect($cn['esito'])->toBe('quadra');

    // Il caso «Via delle Acacie»: una banca nuova, senza apertura e senza incassi, da cui esce un pagamento.
    $contoNuovo = spdConto($condominio->id, 'conto_nuovo', '1013', 'attivo', 'liquidita');
    $bancaNuova = DB::table('casse')->insertGetId([
        'condominio_id' => $condominio->id, 'conto_contabile_id' => $contoNuovo, 'nome' => 'Banca nuova', 'tipo' => 'banca',
        'saldo_iniziale' => 0, 'attiva' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    spdScrittura(['condominio' => $condominio, 'gestione' => $gestione], $es2026, '2026-08-26', $costi, $contoNuovo, 52500, 'Pagamento da banca vuota');
    $cn = collect(app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class)->costruisci($condominio, $es2026, oggi: '2026-09-12')['controlli'])->firstWhere('id', 'CN');

    expect($cn['esito'])->toBe('non_quadra')
        ->and($cn['nota'])->toContain('non ha un saldo di apertura e nessun incasso')
        ->and($cn['rimedio'])->toContain('registra il saldo iniziale')
        ->and($cn['azione'])->toBe('cassa')
        ->and($cn['azione_parametro'])->toBe((string) $bancaNuova);
});

test('R2 misura lo scarto quando un conto di liquidità non ha una cassa che lo rappresenti', function () {
    [$condominio, $es2025] = spdScenario(); // nessuna riga in `casse`: la banca è solo un conto

    $r2 = collect(app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class)->costruisci($condominio, $es2025, oggi: '2026-09-12')['controlli'])->firstWhere('id', 'R2');

    expect($r2['sinistra'])->toBe(600000)->and($r2['destra'])->toBe(0)->and($r2['esito'])->toBe('non_quadra');
});
