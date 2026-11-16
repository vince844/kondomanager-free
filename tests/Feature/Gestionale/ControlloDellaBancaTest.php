<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il quindicesimo controllo, il saldo della banca contro quello dell'estratto conto scritto nella nota sintetica, e le possibili cause di una
 * differenza, ognuna con la correzione consigliata e «Ignora» con una nota che resta nel registro.
 *
 * Le cifre, a mano, su Via Verdi (il caso del conguaglio, con le spese scritte con le fatture), al 31/12/2026: la banca ha il saldo di apertura di
 * € 1.000,00 e gli incassi del 01/03/2026, € 1.700,00 + € 1.200,00 + € 750,00 = € 4.650,00 in contabilità. Ogni caso aggiunge i suoi movimenti e scrive
 * l'estratto in modo che la differenza d = estratto − contabilità sia quella che la causa spiega:
 * - un pagamento di € 60,00 del 30/12: contabilità € 4.590,00, estratto € 4.650,00, d = +€ 60,00 = −(−€ 60,00): il pagamento in transito;
 * - due regolazioni di € 25,00 e € 35,00 del 28 e del 30/12: d = +€ 60,00, nessun movimento da € 60,00, la coppia;
 * - due incassi di Neri di € 100,00 il 10 e il 12/06 (2 giorni): contabilità € 4.850,00, estratto € 4.750,00, d = −€ 100,00: il doppio; e la banca ha di
 *   meno, senza una spesa della banca registrata: le spese;
 * - un incasso da terzi di € 75,00 del 15/12 sulla cassa contanti: estratto € 4.725,00, d = +€ 75,00: l'altro conto;
 * - un incasso da terzi di € 40,00 del 02/01/2027 sulla banca: la contabilità al 31/12 non lo ha, estratto € 4.690,00, d = +€ 40,00: dopo l'estratto;
 * - un giroconto di € 500,00 dalla banca al fondo il 30/06: contabilità del conto € 4.150,00, estratto € 4.650,00, d = +€ 500,00 = il fondo: i fondi.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\User;
use App\Services\Gestionale\ControlliDelRendiconto;
use App\Services\Gestionale\NotaSinteticaDelRendiconto;
use App\Services\Gestionale\PisteDellaBanca;
use App\Services\Gestionale\SaldiDelleBanche;
use App\Services\Gestionale\SintesiDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (\App\Enums\Permission::cases() as $permesso) {
        Permission::findOrCreate($permesso->value, 'web');
    }
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create(['name' => 'Anna']);
    $this->user->assignRole($ruolo);
    $this->withoutVite();
    Storage::fake('local');
});

function cdbViaVerdi($test): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    cpmSpesa($s, $s['giornale'], $s['pulizie'], 330000, []);
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 90000, []);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');

    return $s;
}

/** Il giorno dell'amministratore, alle 10 di Roma. */
function cdbGiorno($test, string $giorno): void
{
    $test->travelTo(\Carbon\Carbon::parse($giorno.' 10:00', 'Europe/Rome'));
}

/**
 * Un movimento di cassa scritto nel giornale: la riga della cassa (dare in entrata, avere in uscita) e la riga contraria su un altro conto.
 *
 * @return int la registrazione
 */
function cdbMovimento(array $s, int $cassaId, int $contoCassa, int $controparte, string $tipo, string $data, int $importo, ?Esercizio $esercizio = null): int
{
    static $n = 0;
    $n++;
    $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => ($esercizio ?? $s['e'])->id,
        'data_registrazione' => $data, 'data_competenza' => $data, 'numero_protocollo' => 'CDB-'.$n, 'causale' => 'Movimento '.$n, 'tipo_movimento' => $tipo,
        'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    [$cassaVerso, $altroVerso] = $importo >= 0 ? ['dare', 'avere'] : ['avere', 'dare'];
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $id, 'conto_contabile_id' => $contoCassa, 'cassa_id' => $cassaId, 'tipo_riga' => $cassaVerso, 'importo' => abs($importo), 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $id, 'conto_contabile_id' => $controparte, 'cassa_id' => null, 'tipo_riga' => $altroVerso, 'importo' => abs($importo), 'created_at' => now(), 'updated_at' => now()],
    ]);

    return $id;
}

/** Un pagamento a un fornitore dalla banca, con la sua riga dei pagamenti. Restituisce [registrazione, pagamento]. */
function cdbPagamento(array $s, string $data, int $importo): array
{
    $scrittura = cdbMovimento($s, $s['cassa']->id, $s['cassa']->conto_contabile_id, $s['giornale']['debiti_fornitori'], 'pagamento_fornitore', $data, -$importo);
    $pagamento = DB::table('pagamenti_fornitori')->insertGetId(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'scrittura_contabile_id' => $scrittura,
        'condominio_id' => $s['c']->id, 'fornitore_id' => $s['giornale']['fornitore'], 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'importo_lordo' => $importo,
        'importo_ritenuta' => 0, 'importo_netto' => $importo, 'importo_commissione' => 0, 'metodo_pagamento' => 'bonifico', 'data_pagamento' => $data, 'stato' => 'registrato',
        'created_at' => now(), 'updated_at' => now()]);

    return [$scrittura, $pagamento];
}

/** Il saldo dell'estratto al 31/12/2026, in centesimi, nella nota; e il controllo della banca e le cause, come le legge la scheda «Controlli». */
function cdbConEstratto($test, array $s, int $estratto): array
{
    $id = (string) $s['cassa']->id;
    $c = NotaSinteticaDelRendiconto::contenuto($s['e']);
    $c['banca'] = [$id => ['data' => '2026-12-31', 'saldo' => $estratto, 'spiegazione' => '']];
    NotaSinteticaDelRendiconto::salva($s['e'], $c, null);
    $props = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.rendiconto.controlli', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];
    $bc = collect($props['controlli'])->firstWhere('id', 'BC');
    $conto = collect($bc['banche'] ?? [])->firstWhere('cassa_id', $s['cassa']->id);

    return ['bc' => $bc, 'conto' => $conto, 'piste' => collect($conto['piste'] ?? [])->keyBy('tipo')->all(), 'tutte' => $conto['piste'] ?? [], 'props' => $props];
}

/** Una cassa della banca di un altro condominio, con il suo conto. */
function cdbCassaAltrove(?Condominio $condominio = null): Cassa
{
    $condominio ??= Condominio::factory()->create();
    $conto = ContoContabile::create(['condominio_id' => $condominio->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);

    return Cassa::create(['condominio_id' => $condominio->id, 'conto_contabile_id' => $conto->id, 'nome' => 'Altra', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 0]);
}

function cdbE(string $cifra): string
{
    return '€'."\u{a0}".$cifra;
}

it('i controlli sono quindici, sempre; un condominio senza un conto in banca: il controllo della banca torna e dice che non c\'è niente da confrontare', function () {
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $r = app(ControlliDelRendiconto::class)->perEsercizio($c, $e, '2027-01-15');
    $bc = collect($r['controlli'])->firstWhere('id', 'BC');

    expect($r['controlli'])->toHaveCount(15)
        ->and(array_column($r['controlli'], 'id')[14])->toBe('BC')
        ->and($bc)->toMatchArray(['esito' => 'quadra', 'nota' => SaldiDelleBanche::FRASE_SENZA_CONTI, 'conti' => 0, 'scritti' => 0, 'rimedio' => null, 'azione' => null])
        ->and($r['esito']['non_calcolati_banca'])->toBe(0)
        ->and(SaldiDelleBanche::FRASE_SENZA_CONTI)->not->toMatch('/errore|sbagliat/i');
});

it('senza l\'estratto il controllo della banca non è calcolato per il suo motivo: la riga degli esiti e il frontespizio lo dicono, non il conguaglio', function () {
    $s = cdbViaVerdi($this);
    cdbGiorno($this, '2027-03-01');
    $letto = app(\App\Services\Gestionale\ConguaglioDelRendiconto::class)->leggi($s['e']);
    $r = app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $s['e'], null, [], null, null, [], $letto);
    $bc = collect($r['controlli'])->firstWhere('id', 'BC');

    expect($r['controlli'])->toHaveCount(15)
        ->and($bc)->toMatchArray(['esito' => 'non_calcolato', 'conti' => 1, 'scritti' => 0, 'rimedio' => SaldiDelleBanche::RIMEDIO_SCRIVI, 'azione' => 'nota'])
        ->and($r['esito']['non_calcolati'])->toBe(1)->and($r['esito']['non_calcolati_banca'])->toBe(1)
        ->and($r['esito']['riga_esiti'])->toEndWith(ControlliDelRendiconto::RIGA_BANCA_NON_CALCOLATA)->not->toContain(ControlliDelRendiconto::RIGA_NON_CALCOLATI)
        ->and(SintesiDelRendiconto::controlliPerChiLegge($r['esito']))->toContain('; il saldo della banca non è confrontato, perché l\'amministratore non ha indicato il saldo dell\'estratto conto della banca.')
        ->not->toContain('leggono il conguaglio')->not->toContain('legge il conguaglio');

    // Senza la lettura del conguaglio: i quattro del conguaglio e la banca, ognuno con il suo motivo.
    $senza = app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $s['e']);
    expect($senza['esito']['non_calcolati'])->toBe(5)
        ->and(SintesiDelRendiconto::controlliPerChiLegge($senza['esito']))->toContain('; 4 non sono calcolati: leggono il conguaglio, che non è in questo fascicolo; il saldo della banca non è confrontato');
});

it('le cifre uguali: il controllo torna; il pagamento in transito del 30/12 è la causa di una differenza di € 60,00, con «Modifica la data del pagamento»', function () {
    $s = cdbViaVerdi($this);
    cdbGiorno($this, '2027-03-01');
    expect(cdbConEstratto($this, $s, 465000)['bc'])->toMatchArray(['esito' => 'quadra', 'sinistra' => 465000, 'destra' => 465000]);

    [$scrittura, $pagamento] = cdbPagamento($s, '2026-12-30', 6000);
    $r = cdbConEstratto($this, $s, 465000);

    expect($r['bc'])->toMatchArray(['esito' => 'segnalazione', 'sinistra' => 465000, 'destra' => 459000, 'segno' => '≠', 'rimedio' => SaldiDelleBanche::RIMEDIO_CAUSE])
        ->and($r['conto'])->toMatchArray(['estratto' => 465000, 'contabilita' => 459000, 'differenza' => 6000])
        ->and($r['tutte'])->toHaveCount(1)
        ->and($r['piste']['transito'])->toMatchArray(['chiave' => 'transito:'.$scrittura, 'ignorata' => null,
            'frase' => 'Può dipendere da un movimento registrato in contabilità che la banca al 31/12/2026 non aveva ancora: pagamento fornitore del 30/12/2026 (prot. '
                .DB::table('scritture_contabili')->where('id', $scrittura)->value('numero_protocollo').'), '.cdbE('60,00').' in uscita.',
            'correzione' => ['etichetta' => 'Modifica la data del pagamento', 'rotta' => 'gestionale.pagamenti-fornitori.edit',
                'parametri' => ['condominio' => $s['c']->id, 'pagamento' => $pagamento], 'query' => []]]);
});

it('due movimenti che insieme fanno la differenza, e nessuno da solo: la coppia', function () {
    $s = cdbViaVerdi($this);
    cdbGiorno($this, '2027-03-01');
    $a = cdbMovimento($s, $s['cassa']->id, $s['cassa']->conto_contabile_id, $s['giornale']['costi'], 'regolazione_immediata', '2026-12-28', -2500);
    $b = cdbMovimento($s, $s['cassa']->id, $s['cassa']->conto_contabile_id, $s['giornale']['costi'], 'regolazione_immediata', '2026-12-30', -3500);
    $r = cdbConEstratto($this, $s, 465000);

    expect($r['conto']['differenza'])->toBe(6000)
        ->and($r['tutte'])->toHaveCount(1)
        ->and($r['tutte'][0]['chiave'])->toBe('due:'.$a.'-'.$b)
        ->and($r['tutte'][0]['frase'])->toContain('due movimenti registrati in contabilità che la banca al 31/12/2026 non aveva ancora')->toEndWith('insieme '.cdbE('60,00').'.')
        ->and($r['tutte'][0]['correzione'])->toMatchArray(['etichetta' => 'Apri la registrazione', 'rotta' => 'gestionale.scritture.show']);
});

it('un incasso che sembra registrato due volte, e le spese della banca: le due cause, con lo storno dall\'elenco degli incassi e la spesa già compilata', function () {
    $s = cdbViaVerdi($this);
    cdbGiorno($this, '2026-06-10');
    $quota = lcdQuota($s, 'Neri', 4);
    lcdIncassa($this, $s, 'Neri', [$quota => 10000], '2026-06-10');
    cdbGiorno($this, '2026-06-12');
    $secondo = lcdIncassa($this, $s, 'Neri', [$quota => 10000], '2026-06-12');
    cdbGiorno($this, '2027-03-01');
    $r = cdbConEstratto($this, $s, 475000);
    $protocollo = DB::table('scritture_contabili')->where('id', $secondo)->value('numero_protocollo');

    expect($r['conto'])->toMatchArray(['contabilita' => 485000, 'differenza' => -10000])
        ->and(array_keys($r['piste']))->toBe(['doppio', 'spese_banca'])
        ->and($r['piste']['doppio']['frase'])->toStartWith('Può dipendere da un movimento che sembra registrato due volte: incasso rata del 10/06/2026')
        ->toEndWith('incasso rata del 12/06/2026 (prot. '.$protocollo.'), '.cdbE('100,00').' in entrata, a 2 giorni di distanza.')
        ->and($r['piste']['doppio']['correzione'])->toBe(['etichetta' => 'Storna l\'incasso registrato due volte', 'rotta' => 'gestionale.movimenti-rate.index',
            'parametri' => ['condominio' => $s['c']->id], 'query' => ['search' => $protocollo]])
        ->and($r['piste']['spese_banca']['frase'])->toBe('Può dipendere dalle spese della banca (commissioni, bolli, canone) non ancora registrate: su questo conto non c\'è una spesa '
            .'registrata con una regolazione immediata.')
        ->and($r['piste']['spese_banca']['correzione'])->toBe(['etichetta' => 'Registra la spesa', 'rotta' => 'gestionale.regolazioni-immediate.create',
            'parametri' => ['condominio' => $s['c']->id], 'query' => ['cassa_id' => $s['cassa']->id, 'esercizio_id' => $s['e']->id, 'importo' => '100.00', 'data' => '2026-12-31',
                'causale' => 'Spese della banca dal suo estratto conto al 31/12/2026']]);

    // La spesa si apre già compilata, e l'amministratore la rilegge e salva lui: un conto di un altro condominio non passa.
    $altra = cdbCassaAltrove();
    $pre = $this->actingAs($this->user)->get(route('admin.gestionale.regolazioni-immediate.create', [$s['c']]).'?'.http_build_query($r['piste']['spese_banca']['correzione']['query']))
        ->assertOk()->viewData('page')['props']['precompilata'];
    expect($pre)->toBe(['cassa_id' => $s['cassa']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => null, 'data_operazione' => '2026-12-31', 'importo' => '100.00',
        'causale' => 'Spese della banca dal suo estratto conto al 31/12/2026']);
    $estranea = $this->actingAs($this->user)->get(route('admin.gestionale.regolazioni-immediate.create', [$s['c']]).'?cassa_id='.$altra->id.'&importo=1e9')
        ->assertOk()->viewData('page')['props']['precompilata'];
    expect($estranea['cassa_id'])->toBeNull()->and($estranea['importo'])->toBeNull();

    // Con una spesa della banca registrata nell'ultimo mese la causa delle spese non si propone.
    cdbMovimento($s, $s['cassa']->id, $s['cassa']->conto_contabile_id, $s['giornale']['costi'], 'regolazione_immediata', '2026-12-20', -500);
    expect(array_keys(cdbConEstratto($this, $s, 474500)['piste']))->toBe(['doppio']);
});

it('lo stesso importo su un altro conto, un movimento con la data dopo l\'estratto, il denaro dei fondi', function () {
    $s = cdbViaVerdi($this);
    cdbGiorno($this, '2027-03-01');
    $ricavi = $s['giornale']['debiti_fornitori'];
    // La cassa contanti, con un incasso da terzi di € 75,00 del 15/12.
    $contanti = Cassa::create(['condominio_id' => $s['c']->id, 'conto_contabile_id' => ContoContabile::create(['condominio_id' => $s['c']->id, 'codice' => '10.30',
        'nome' => 'Contanti', 'tipo' => 'attivo', 'ruolo' => 'cassa', 'categoria' => 'liquidita'])->id, 'nome' => 'Cassa contanti', 'tipo' => 'contanti', 'attiva' => true, 'saldo_iniziale' => 0]);
    $suContanti = cdbMovimento($s, $contanti->id, $contanti->conto_contabile_id, $ricavi, 'incasso_diverso', '2026-12-15', 7500);
    $r = cdbConEstratto($this, $s, 472500);
    expect($r['conto']['differenza'])->toBe(7500)
        ->and(array_keys($r['piste']))->toBe(['altro_conto'])
        ->and($r['piste']['altro_conto']['chiave'])->toBe('altro:'.$suContanti)
        ->and($r['piste']['altro_conto']['frase'])->toStartWith('Può dipendere da un movimento registrato su un altro conto, «Cassa contanti», che la banca ha su questo: incasso da terzi del 15/12/2026');

    // Un incasso da terzi di € 40,00 del 02/01/2027 sulla banca: al 31/12 la contabilità non lo ha.
    DB::table('righe_scritture')->where('scrittura_id', $suContanti)->delete();
    DB::table('scritture_contabili')->where('id', $suContanti)->delete();
    $dopo = cdbMovimento($s, $s['cassa']->id, $s['cassa']->conto_contabile_id, $ricavi, 'incasso_diverso', '2027-01-02', 4000);
    $r = cdbConEstratto($this, $s, 469000);
    expect($r['conto']['differenza'])->toBe(4000)
        ->and(array_keys($r['piste']))->toBe(['dopo_estratto'])
        ->and($r['piste']['dopo_estratto']['chiave'])->toBe('dopo:'.$dopo)
        ->and($r['piste']['dopo_estratto']['frase'])->toStartWith('Può dipendere da un movimento registrato con una data dopo quella del saldo della banca, che la banca al 31/12/2026 aveva già: incasso da terzi del 02/01/2027');

    // Mai «errore»: la frase dice «può dipendere da».
    foreach ($r['tutte'] as $p) {
        expect($p['frase'])->toStartWith('Può dipendere ')->not->toMatch('/errore|sbagliat|ammanco/i');
    }

    // Il verso conta: un'uscita di € 75,00 sui contanti non spiega una banca che ha € 75,00 in più.
    DB::table('righe_scritture')->where('scrittura_id', $dopo)->delete();
    DB::table('scritture_contabili')->where('id', $dopo)->delete();
    cdbMovimento($s, $contanti->id, $contanti->conto_contabile_id, $ricavi, 'regolazione_immediata', '2026-12-15', -7500);
    expect(cdbConEstratto($this, $s, 472500)['piste'])->not->toHaveKey('altro_conto');
});

/** Un conto in banca in più, con il suo conto contabile. */
function cdbAltraBanca(array $s, string $nome, int $saldoIniziale = 0): Cassa
{
    $conto = ContoContabile::create(['condominio_id' => $s['c']->id, 'codice' => '10.1'.strlen($nome), 'nome' => $nome, 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);

    return Cassa::create(['condominio_id' => $s['c']->id, 'conto_contabile_id' => $conto->id, 'nome' => $nome, 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => $saldoIniziale]);
}

/** Un fondo, partizione del conto corrente, con il suo conto contabile e il saldo iniziale dato. */
function cdbFondo(array $s, int $saldoIniziale = 0): Cassa
{
    $conto = ContoContabile::create(['condominio_id' => $s['c']->id, 'codice' => '10.40', 'nome' => 'Fondo riserva', 'tipo' => 'attivo', 'ruolo' => 'fondo', 'categoria' => 'liquidita']);

    return Cassa::create(['condominio_id' => $s['c']->id, 'conto_contabile_id' => $conto->id, 'nome' => 'Fondo riserva', 'tipo' => 'fondo', 'attiva' => true,
        'saldo_iniziale' => $saldoIniziale]);
}

/** Un giroconto da una banca a un fondo, con le due righe sulle due casse. */
function cdbAccantona(array $s, Cassa $banca, Cassa $fondo, string $data, int $importo): int
{
    $id = cdbMovimento($s, $banca->id, $banca->conto_contabile_id, $fondo->conto_contabile_id, 'giroconto', $data, -$importo);
    DB::table('righe_scritture')->where('scrittura_id', $id)->where('conto_contabile_id', $fondo->conto_contabile_id)->update(['cassa_id' => $fondo->id]);

    return $id;
}

/** Il saldo dell'estratto di più conti al 31/12/2026, e i conti del controllo della banca come li legge la scheda «Controlli». */
function cdbConEstratti($test, array $s, array $estratti): array
{
    $c = NotaSinteticaDelRendiconto::contenuto($s['e']);
    $c['banca'] = collect($estratti)->mapWithKeys(fn ($saldo, $cassa) => [(string) $cassa => ['data' => '2026-12-31', 'saldo' => $saldo, 'spiegazione' => '']])->all();
    NotaSinteticaDelRendiconto::salva($s['e'], $c, null);
    $props = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.rendiconto.controlli', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];
    $bc = collect($props['controlli'])->firstWhere('id', 'BC');

    return ['bc' => $bc, 'conti' => collect($bc['banche'])->keyBy('cassa_id')->all()];
}

it('i fondi sono partizioni del conto corrente: con un conto solo la contabilità conta il conto e i fondi, e il controllo torna', function () {
    $s = cdbViaVerdi($this);
    cdbGiorno($this, '2027-03-01');
    $fondo = cdbFondo($s);
    cdbAccantona($s, $s['cassa'], $fondo, '2026-06-30', 50000);
    $r = cdbConEstratto($this, $s, 465000);

    // € 4.650,00 − € 500,00 sul conto, € 500,00 nel fondo: € 4.650,00, quanto l'estratto.
    expect($r['bc'])->toMatchArray(['esito' => 'quadra', 'sinistra' => 465000, 'destra' => 465000])
        ->and($r['conto'])->toMatchArray(['contabilita' => 465000, 'sul_conto' => 415000, 'nei_fondi' => 50000, 'differenza' => 0])
        ->and($r['tutte'])->toBe([]);
    // La frase della nota dice da dove viene la cifra, e «Genera la nota» la stampa vera.
    $letture = app(NotaSinteticaDelRendiconto::class)->letture($s['c'], $s['e']);
    $fatto = collect(app(\App\Services\Gestionale\FattiDellaNota::class)->perEsercizio($s['c'], $s['e'], $letture)['fatti'])->firstWhere('chiave', 'G6B:'.$s['cassa']->id);
    expect($fatto['frase'])->toBe('Il conto «Banca principale» al 31/12/2026: secondo la banca (dal suo estratto conto) '.cdbE('4.650,00').', secondo la contabilità '
        .cdbE('4.650,00').' ('.cdbE('4.150,00').' sul conto e '.cdbE('500,00').' nei fondi): coincidono.');
    app(NotaSinteticaDelRendiconto::class)->genera($s['c'], $s['e'], '', $this->user);
    $stampa = app(NotaSinteticaDelRendiconto::class)->perLaStampa($s['c'], $s['e'], app(NotaSinteticaDelRendiconto::class)->letture($s['c'], $s['e']));
    expect(json_encode($stampa, JSON_UNESCAPED_UNICODE))->toContain('nei fondi): coincidono.')->not->toContain('la differenza è');

    // Con un conto solo anche un fondo aperto con un saldo suo, € 300,00, sta su quel conto: € 4.150,00 + € 500,00 + € 300,00.
    DB::table('casse')->where('id', $fondo->id)->update(['saldo_iniziale' => 30000]);
    $r = cdbConEstratto($this, $s, 495000);
    expect($r['bc']['esito'])->toBe('quadra')
        ->and($r['conto'])->toMatchArray(['contabilita' => 495000, 'sul_conto' => 415000, 'nei_fondi' => 80000, 'fondi' => 'tutti']);
});

it('con più conti in banca ogni accantonamento resta nella banca da cui è partito; un fondo con un saldo suo è la causa «i fondi», solo con la sua cifra', function () {
    $s = cdbViaVerdi($this);
    cdbGiorno($this, '2027-03-01');
    $b = cdbAltraBanca($s, 'Conto postale', 20000);
    $fondo = cdbFondo($s);
    cdbAccantona($s, $s['cassa'], $fondo, '2026-06-30', 50000);
    $r = cdbConEstratti($this, $s, [$s['cassa']->id => 465000, $b->id => 20000]);
    // La banca principale: € 4.150,00 sul conto e € 500,00 accantonati da lì; il conto postale: € 200,00 suoi, niente fondi.
    expect($r['bc']['esito'])->toBe('quadra')
        ->and($r['conti'][$s['cassa']->id])->toMatchArray(['contabilita' => 465000, 'sul_conto' => 415000, 'nei_fondi' => 50000, 'differenza' => 0])
        ->and($r['conti'][$b->id])->toMatchArray(['contabilita' => 20000, 'nei_fondi' => 0, 'differenza' => 0]);

    // È la lettura del registro di contabilità alla stessa data: la banca del registro ha dentro i fondi accantonati da lei.
    $registro = collect(app(\App\Services\Gestionale\RegistroContabilitaService::class)->registroConRiepilogo($s['e'], ['data_a' => '2026-12-31'])['saldi_per_cassa'])
        ->keyBy('cassa');
    expect($registro['Banca principale']['saldo'])->toBe($r['conti'][$s['cassa']->id]['contabilita'])
        ->and($registro['Conto postale']['saldo'])->toBe($r['conti'][$b->id]['contabilita']);

    // Un fondo aperto con € 300,00 suoi: con più conti non si sa in quale banca stanno. La banca principale ne ha € 300,00 in più: la causa «i fondi», con € 300,00.
    DB::table('casse')->where('id', $fondo->id)->update(['saldo_iniziale' => 30000]);
    $r = cdbConEstratti($this, $s, [$s['cassa']->id => 495000, $b->id => 20000]);
    $conto = $r['conti'][$s['cassa']->id];
    expect($conto['differenza'])->toBe(30000)
        ->and(array_column($conto['piste'], 'tipo'))->toBe(['fondi'])
        ->and($conto['piste'][0]['frase'])->toContain('(«Fondo riserva» '.cdbE('300,00').' al 31/12/2026)');

    // Un fondo con € 600,00 suoi non spiega € 500,00.
    DB::table('casse')->where('id', $fondo->id)->update(['saldo_iniziale' => 60000]);
    $r = cdbConEstratti($this, $s, [$s['cassa']->id => 515000, $b->id => 20000]);
    expect($r['conti'][$s['cassa']->id]['differenza'])->toBe(50000)
        ->and(array_column($r['conti'][$s['cassa']->id]['piste'], 'tipo'))->not->toContain('fondi');
});

it('«registrato due volte» solo se i due movimenti pagano la stessa cosa: due versamenti giusti dello stesso giorno su rate diverse non lo sono; i giorni detti bene', function () {
    $s = cdbViaVerdi($this);
    cdbGiorno($this, '2027-03-01');
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('id')->pluck('id')->all();
    $incasso = function (string $data, int $importo, int $rata, string $chi) use ($s): int {
        $id = cdbMovimento($s, $s['cassa']->id, $s['cassa']->conto_contabile_id, $s['crediti']->id, 'incasso_rata', $data, $importo);
        DB::table('righe_scritture')->where('scrittura_id', $id)->where('conto_contabile_id', $s['crediti']->id)
            ->update(['anagrafica_id' => $s['persone'][$chi]->id, 'rata_id' => $rata]);

        return $id;
    };
    // Neri paga la rata 3 e la rata 4, € 500,00 ciascuna, il 10/06; Verdi versa € 500,00 il 30/12, non ancora in banca: d = −€ 500,00.
    $incasso('2026-06-10', 50000, $rate[2], 'Neri');
    $incasso('2026-06-10', 50000, $rate[3], 'Neri');
    $verdi = $incasso('2026-12-30', 50000, $rate[3], 'Verdi');
    $r = cdbConEstratto($this, $s, 465000 + 100000);
    expect($r['conto']['differenza'])->toBe(-50000)
        ->and(array_column($r['tutte'], 'tipo'))->toBe(['transito', 'spese_banca'])
        ->and($r['piste']['transito']['chiave'])->toBe('transito:'.$verdi);

    // Due versamenti di Neri sulla stessa rata, lo stesso giorno e a un giorno: «doppio», con i giorni detti bene.
    $a = $incasso('2026-07-01', 20000, $rate[1], 'Neri');
    $b = $incasso('2026-07-01', 20000, $rate[1], 'Neri');
    $r = cdbConEstratto($this, $s, 465000 + 100000 + 50000 + 20000);
    expect($r['conto']['differenza'])->toBe(-20000)
        ->and($r['piste']['doppio']['chiave'])->toBe('doppio:'.$a.'-'.$b)
        ->and($r['piste']['doppio']['frase'])->toEndWith(', lo stesso giorno.');
    DB::table('scritture_contabili')->where('id', $b)->update(['data_competenza' => '2026-07-02']);
    expect(cdbConEstratto($this, $s, 465000 + 100000 + 50000 + 20000)['piste']['doppio']['frase'])->toEndWith(', a 1 giorno di distanza.');

    // Il verso conta: due uscite uguali non spiegano una banca che ha di meno.
    DB::table('righe_scritture')->whereIn('scrittura_id', [$a, $b])->delete();
    DB::table('scritture_contabili')->whereIn('id', [$a, $b])->delete();
    cdbMovimento($s, $s['cassa']->id, $s['cassa']->conto_contabile_id, $s['giornale']['costi'], 'regolazione_immediata', '2026-08-01', -10000);
    cdbMovimento($s, $s['cassa']->id, $s['cassa']->conto_contabile_id, $s['giornale']['costi'], 'regolazione_immediata', '2026-08-02', -10000);
    DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'regolazione_immediata')->update(['causale' => 'Canone del conto']);
    $r = cdbConEstratto($this, $s, 465000 + 100000 + 50000 - 20000 - 10000);
    expect($r['conto']['differenza'])->toBe(-10000)->and($r['piste'])->not->toHaveKey('doppio');
    // Le stesse due uscite, con la stessa causale, spiegano una banca che ha di più: la seconda forse è doppia.
    $r = cdbConEstratto($this, $s, 465000 + 100000 + 50000 - 20000 + 10000);
    expect($r['conto']['differenza'])->toBe(10000)->and($r['piste'])->toHaveKey('doppio');
});

it('la spesa della banca già compilata con l\'esercizio dell\'estratto chiuso: si registra nell\'esercizio aperto con la data dell\'estratto, e il controllo torna', function () {
    $s = cdbViaVerdi($this);
    cdbGiorno($this, '2027-03-01');
    $e27 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e27, $s['g']->id);
    // La voce della spesa con il suo conto di costo, come le voci che la regolazione immediata offre.
    DB::table('conti')->where('id', $s['manutenzioni']->id)->update(['conto_contabile_id' => $s['giornale']['costi']]);
    $s['e']->update(['stato' => 'chiuso']);
    $r = cdbConEstratto($this, $s, 464500);
    $query = $r['piste']['spese_banca']['correzione']['query'];
    expect($query)->not->toHaveKey('esercizio_id')->and($query['data'])->toBe('2026-12-31');

    $pre = $this->actingAs($this->user)->get(route('admin.gestionale.regolazioni-immediate.create', [$s['c']]).'?'.http_build_query($query))
        ->assertOk()->viewData('page')['props'];
    expect($pre['precompilata']['esercizio_id'])->toBeNull()->and(array_column($pre['esercizi'], 'id'))->toBe([$e27->id]);
    // L'amministratore sceglie l'esercizio aperto (il solo), la voce, e salva: la spesa sta nel 2027 con la data del 31/12/2026.
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), ['esercizio_id' => $e27->id, 'gestione_id' => $s['g']->id,
        'conto_id' => $s['manutenzioni']->id, 'cassa_id' => $s['cassa']->id, 'data_operazione' => $query['data'], 'causale' => $query['causale'], 'importo' => $query['importo']])
        ->assertSessionHasNoErrors();
    $spesa = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'regolazione_immediata')->first();
    expect((int) $spesa->esercizio_id)->toBe($e27->id)->and(substr((string) $spesa->data_competenza, 0, 10))->toBe('2026-12-31')
        ->and(cdbConEstratto($this, $s, 464500)['bc']['esito'])->toBe('quadra');
});

it('«Ignora» chiede la nota, resta nel registro con chi e quando, non si ripropone e si riapre; la pagina della nota non lo cambia', function () {
    $s = cdbViaVerdi($this);
    [$scrittura] = cdbPagamento($s, '2026-12-30', 6000);
    cdbGiorno($this, '2027-03-01');
    $r = cdbConEstratto($this, $s, 465000);
    $chiave = $r['piste']['transito']['chiave'];
    $dalla = route('admin.gestionale.esercizi.rendiconto.controlli', [$s['c'], $s['e']]);

    // Senza la nota non si ignora.
    $this->actingAs($this->user)->from($dalla)->post(route('admin.gestionale.esercizi.rendiconto.controlli.banca.ignora', [$s['c'], $s['e']]),
        ['cassa_id' => $s['cassa']->id, 'chiave' => $chiave, 'nota' => ''])->assertSessionHasErrors('nota');
    expect(NotaSinteticaDelRendiconto::contenuto($s['e'])['registro'])->toBe([]);

    cdbGiorno($this, '2027-03-02');
    $this->actingAs($this->user)->from($dalla)->post(route('admin.gestionale.esercizi.rendiconto.controlli.banca.ignora', [$s['c'], $s['e']]),
        ['cassa_id' => $s['cassa']->id, 'chiave' => $chiave, 'nota' => 'Assegno non ancora incassato'])->assertSessionHasNoErrors()->assertRedirect($dalla);
    $registro = NotaSinteticaDelRendiconto::contenuto($s['e'])['registro'];
    expect($registro)->toHaveCount(1)
        ->and($registro[0])->toMatchArray(['tipo' => PisteDellaBanca::IGNORATA, 'utente_id' => $this->user->id, 'utente' => 'Anna', 'nota' => 'Assegno non ancora incassato'])
        ->and($registro[0]['dati'])->toMatchArray(['cassa_id' => $s['cassa']->id, 'chiave' => $chiave, 'differenza' => 6000, 'data' => '2026-12-31',
            'frase' => $r['piste']['transito']['frase']]);

    // Non si ripropone fra le aperte: è scritta, con il giorno, chi e la nota.
    $r = cdbConEstratto($this, $s, 465000);
    expect($r['piste']['transito']['ignorata'])->toBe(['il' => $registro[0]['il'], 'quando' => '02/03/2027', 'utente' => 'Anna', 'nota' => 'Assegno non ancora incassato']);

    // Il salvataggio della pagina della nota non tocca il registro, anche se lo manda vuoto.
    $this->actingAs($this->user)->from(route('admin.gestionale.esercizi.rendiconto.nota', [$s['c'], $s['e']]))
        ->put(route('admin.gestionale.esercizi.rendiconto.nota.update', [$s['c'], $s['e']]), ['contenuto' => ['registro' => [], 'banca' => [(string) $s['cassa']->id =>
            ['data' => '2026-12-31', 'saldo' => '4650.00', 'spiegazione' => 'Un assegno del 30/12']]]])->assertSessionHasNoErrors();
    expect(NotaSinteticaDelRendiconto::contenuto($s['e'])['registro'])->toHaveCount(1);

    // Si riapre: torna fra le aperte, e il registro tiene le due righe.
    $this->actingAs($this->user)->from($dalla)->post(route('admin.gestionale.esercizi.rendiconto.controlli.banca.riapri', [$s['c'], $s['e']]),
        ['cassa_id' => $s['cassa']->id, 'chiave' => $chiave])->assertSessionHasNoErrors();
    expect(cdbConEstratto($this, $s, 465000)['piste']['transito']['ignorata'])->toBeNull()
        ->and(array_column(NotaSinteticaDelRendiconto::contenuto($s['e'])['registro'], 'tipo'))->toBe([PisteDellaBanca::IGNORATA, PisteDellaBanca::RIAPERTA]);

    // Una causa che oggi non c'è (un'altra chiave) non si ignora; un conto di un altro condominio è rifiutato; un esercizio di un altro condominio fa 404.
    $this->actingAs($this->user)->from($dalla)->post(route('admin.gestionale.esercizi.rendiconto.controlli.banca.ignora', [$s['c'], $s['e']]),
        ['cassa_id' => $s['cassa']->id, 'chiave' => 'transito:999999', 'nota' => 'Prova'])->assertRedirect($dalla);
    expect(session('message')['type'] ?? null)->toBe('error');
    $altro = Condominio::factory()->create();
    $cassaAltra = cdbCassaAltrove($altro);
    $this->actingAs($this->user)->from($dalla)->post(route('admin.gestionale.esercizi.rendiconto.controlli.banca.ignora', [$s['c'], $s['e']]),
        ['cassa_id' => $cassaAltra->id, 'chiave' => $chiave, 'nota' => 'Prova'])->assertSessionHasErrors('cassa_id');
    $eAltro = Esercizio::factory()->create(['condominio_id' => $altro->id, 'nome' => 'Altro', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.rendiconto.controlli.banca.ignora', [$s['c'], $eAltro]),
        ['cassa_id' => $s['cassa']->id, 'chiave' => $chiave, 'nota' => 'Prova'])->assertNotFound();
    // Fuori dal pannello: niente.
    $this->actingAs(User::factory()->create())->post(route('admin.gestionale.esercizi.rendiconto.controlli.banca.ignora', [$s['c'], $s['e']]),
        ['cassa_id' => $s['cassa']->id, 'chiave' => $chiave, 'nota' => 'Prova'])->assertForbidden();
});
