<?php

/**
 * Il consuntivo per persona dove il programma sceglieva da solo fra esiti legittimi diversi: la spesa personale chiesta in parte, l'unità mista, i periodi
 * diversi di due piani sulla stessa voce, la spesa personale con la competenza su più giorni, il già versato dell'unità non usato dopo un passaggio, la
 * spesa personale di un giorno senza titolare, il cambio di titolare con giorni scoperti e senza un passaggio registrato, il piano con l'esercizio dedotto.
 *
 * I casi sono Via Verdi (`ConsuntivoPerPersonaPosizioneScenario.php`: U1 Neri 500, U2 Bianchi 300 poi Rossi dal 01/07, U3 Verdi 200, Pulizie € 3.300,00 e
 * Manutenzioni € 900,00) e il caso del balcone (`Beta59313AdPersonamScenari.php`). Le cifre sono rifatte a mano nei commenti; positivo = debito.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\Conto;
use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\ConsuntivoPerPersona;
use App\Services\Gestionale\ControlliDelConguaglio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/ConsuntivoPerPersonaPosizioneScenario.php';
require_once __DIR__.'/../Gestionale/Beta59313AdPersonamScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Interno 1 misto: Mario proprietario pieno 50, Nina nuda proprietaria 50, Ugo usufruttuario 50. */
function cpdMista(array $s): array
{
    $altri = [];
    foreach ([['Nina Neri', 'nuda_proprietario'], ['Ugo Gialli', 'usufruttuario']] as [$nome, $ruolo]) {
        $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower(strtok($nome, ' ')).'-cpd'.$s['c']->id.'@test.it', 'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => strtoupper(substr(md5($nome.$s['c']->id), 0, 16))]);
        $p->condomini()->syncWithoutDetaching([$s['c']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $s['unita']['1']->id, 'tipologia' => $ruolo, 'quota' => 50,
            'attivo' => true, 'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
        $altri[strtolower(strtok($nome, ' '))] = $p;
    }

    return $altri;
}

/** La scheda «Conguaglio» del caso, dal servizio: le righe e le gestioni con le loro frasi. */
function cpdScheda(array $s): array
{
    $k = app(ConguaglioDelRendiconto::class);
    $letto = $k->leggi($s['e']->fresh());

    return ['letto' => $letto, 'righe' => $k->righe($s['c'], $s['e']->fresh(), $letto, ['commissioni_senza_voce' => ['consuntivo' => 0]])];
}

/** Le note della scheda sulle righe di un'unità. */
function cpdNote(array $scheda, int $iid): array
{
    $note = [];
    foreach ($scheda['gestioni'] as $g) {
        foreach ($g['righe'] as $r) {
            if (($r['immobile_id'] ?? null) === $iid) {
                array_push($note, ...$r['note']);
            }
        }
    }

    return $note;
}

// ─── Una spesa personale chiesta in parte da un piano ────────────────────────────────────────────────────────────────────────────────

it('una riga personale chiesta in parte da un piano: il resto è fra le spese personali non chieste, e le spese personali tornano con le anticipazioni', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    // La facciata comune € 500,00 e il balcone di Interno 1 € 100,00 nella stessa fattura; il piano chiede € 300,00 della fattura, la metà di ogni riga:
    // del balcone € 50,00. Il resto del balcone, € 50,00, è ancora da chiedere a Mario.
    $fattura = b59apFattura($this, $s, [b59apRigaComune(500.00), b59apRigaAdPersonam($s, 100.00, 0)]);
    $piano = b59apPiano($this, $s, $fattura, 1, '300,00');
    b59apEmetti($this, $s, $piano, [1]);
    $this->travelTo('2026-12-31 12:00:00');
    $c = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $m = $c['persone'][$s['g']->id][$s['persone']['mario']->id][$s['unita']['1']->id];

    // Mario: spese 250,00 (la metà della facciata); personali non chieste 50,00 (il balcone meno la parte chiesta); quote 125,00 (la parte comune delle sue
    // quote, la metà dei 250,00 comuni chiesti: la parte personale B non la conta). Conguaglio 250,00 + 50,00 − 125,00 = +175,00. Prima: personali 0, +125,00.
    expect([$m['a'], $m['personali']['non_chieste'], $m['b']['totale'], $m['conguaglio']])->toBe([25000, 5000, 12500, 17500]);
    // Il controllo per unità: personali non chieste 50,00 + chieste non emesse 0 = anticipazioni aperte 50,00.
    $ca = collect(ControlliDelConguaglio::tutti($c, ['capitoli_netti' => 0], ['esito' => 'quadra', 'nota' => '']))->firstWhere('id', 'CA');
    expect($ca['esito'])->toBe('quadra')->and([$ca['sinistra'], $ca['destra']])->toBe([5000, 5000]);
});

it('il controllo delle spese personali non torna dove la riga personale non è né fra le non chieste né fra le chieste, e la riga dell\'unità lo dice', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.00, 0)]);
    // Il citofono di Interno 1 è fra le anticipazioni aperte (€ 100,00); una riga del riparto scritta a mano dice che un piano l'ha chiesto, senza le rate.
    $piano = b59apPiano($this, $s, $fattura, 1, '100,00');
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $piano->id)->pluck('id'))->delete();
    $this->travelTo('2026-12-31 12:00:00');
    $c = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $ca = collect(ControlliDelConguaglio::tutti($c, ['capitoli_netti' => 0], ['esito' => 'quadra', 'nota' => '']))->firstWhere('id', 'CA');

    // Non chieste 0 + chieste non emesse 0 ≠ anticipazioni aperte 100,00: l'unità è nominata, con la cifra che manca.
    expect($ca['esito'])->toBe('non_quadra')->and($ca['nota'])->toContain('Int. 1')->toContain('€ 100,00');
});

it('una spesa personale chiesta da un piano con le rate non ancora emesse è ancora fra le anticipazioni aperte: il controllo la conta fra le chieste non emesse', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.00, 0)]);
    // Il piano chiede il balcone € 100,00; nessuna rata emessa: la spesa resta fra le anticipazioni aperte dell'unità.
    b59apPiano($this, $s, $fattura, 1, '100,00');
    $this->travelTo('2026-12-31 12:00:00');
    $c = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $ca = collect(ControlliDelConguaglio::tutti($c, ['capitoli_netti' => 0], ['esito' => 'quadra', 'nota' => '']))->firstWhere('id', 'CA');

    // Non chieste 0 + chieste con rate non emesse 100,00 = anticipazioni aperte 100,00.
    expect($c['personali_per_unita'][$s['unita']['1']->id])->toMatchArray(['non_chieste' => 0, 'non_emesse' => 10000, 'aperte' => 10000])
        ->and($ca['esito'])->toBe('quadra')->and([$ca['sinistra'], $ca['destra']])->toBe([10000, 10000]);
});

// ─── L'unità mista ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('l\'unità mista: la spesa personale non chiesta va al proprietario pieno e alla nuda proprietaria dell\'altra metà, come nel piano da fatture', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase(['Mario Rossi' => 50]);
    $altri = cpdMista($s);
    b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.00, 0)]);
    $this->travelTo('2026-12-31 12:00:00');
    $c = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $di = fn (Anagrafica $p) => $c['persone'][$s['g']->id][$p->id][$s['unita']['1']->id]['personali']['non_chieste'] ?? null;

    // Il balcone € 100,00: Mario 50 e Nina 50 (la nuda proprietà dell'altra metà); Ugo, usufruttuario, niente. Prima: Mario 100,00, Nina niente.
    expect([$di($s['persone']['mario']), $di($altri['nina'])])->toBe([5000, 5000])
        ->and($di($altri['ugo']) ?? 0)->toBe(0);
});

it('l\'unità mista: il già versato dell\'unità non usato va all\'usufruttuario e al proprietario pieno dell\'altra metà, non tutto al primo ruolo', function () {
    $s = cpcViaVerdi($this);
    // U2 mista: Bianchi proprietario pieno della metà, Nina nuda proprietaria e Ugo usufruttuario dell'altra metà.
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['quota' => 50]);
    $altri = [];
    foreach ([['Nina', 'nuda_proprietario'], ['Ugo', 'usufruttuario']] as [$nome, $ruolo]) {
        $altri[$nome] = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome).'-cpdm'.$s['c']->id.'@test.it', 'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => strtoupper(substr(md5($nome.'cpdm'.$s['c']->id), 0, 16))]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $altri[$nome]->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => $ruolo, 'quota' => 50,
            'attivo' => true, 'data_inizio' => '2020-01-01', 'created_at' => now(), 'updated_at' => now()]);
    }
    cpcRateEIncassi($this, $s);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['pulizie']->id, 'immobile_id' => $s['unita']['U2']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $s['e']->id]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);
    $di = fn (int $aid) => (int) ($c['persone'][$s['g']->id][$aid][$s['unita']['U2']->id]['gia_versato_non_usato']['questo_esercizio'] ?? 0);

    // L'ordinaria comincia dall'usufruttuario (Ugo, 50) e unisce il gemello dell'altra metà, il proprietario pieno (Bianchi, 50): 150,00 e 150,00. Prima tutto a Ugo.
    expect([$di($s['persone']['Bianchi']->id), $di($altri['Ugo']->id), $di($altri['Nina']->id)])->toBe([15000, 15000, 0]);
});

// ─── I periodi della voce diversi fra due piani ──────────────────────────────────────────────────────────────────────────────────────

/** Due piani dell'esercizio con la stessa voce Pulizie: il periodo (01/01–30/06) sul primo, sul secondo, o su nessuno; con o senza il passaggio di U2. */
function cpdDuePiani($test, ?string $periodoSu, bool $passaggio = true): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    if ($passaggio) {
        cpcPassaggio($test, $s);
    }
    $secondo = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Integrativo 2026',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 15, 'data_prima_scadenza' => '2026-09-15', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    $pivot1 = DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['pulizie']->id)->value('id')
        ?? DB::table('piano_rate_capitoli')->insertGetId(['piano_rate_id' => $s['piano']->id, 'conto_id' => $s['pulizie']->id, 'importo' => 300000, 'created_at' => now(), 'updated_at' => now()]);
    $pivot2 = DB::table('piano_rate_capitoli')->insertGetId(['piano_rate_id' => $secondo->id, 'conto_id' => $s['pulizie']->id, 'importo' => 30000, 'created_at' => now(), 'updated_at' => now()]);
    if ($periodoSu !== null) {
        DB::table('competenze_capitolo')->insert(['piano_rate_capitolo_id' => $periodoSu === 'primo' ? $pivot1 : $pivot2, 'dal' => '2026-01-01', 'al' => '2026-06-30', 'ordine' => 1,
            'created_at' => now(), 'updated_at' => now()]);
    }
    cpcSpese($s);

    return $s;
}

it('due piani dell\'esercizio con periodi diversi per la stessa voce, e un\'unità della voce che cambia titolare: la voce non si calcola, e la scheda dice perché', function () {
    $s = cpdDuePiani($this, 'primo');
    $c = cpcLeggi($this, $s);
    $g = $c['gestioni'][$s['g']->id];
    $scheda = cpdScheda($s)['righe'];
    $gestione = collect($scheda['gestioni'])->firstWhere('id', $s['g']->id);
    $pulizie = collect($gestione['non_attribuite'])->firstWhere('conto_id', $s['pulizie']->id);

    // Pulizie € 3.300,00 non divise: né Bianchi né Rossi le hanno (prima Bianchi 990,00 con il periodo sul primo piano, 490,93 sul secondo). Manutenzioni sì.
    expect($g['non_attribuite'][$s['pulizie']->id] ?? null)->toBe(330000)
        ->and(cpcDi($c, $s, 'Bianchi', 'U2')['voci'][$s['pulizie']->id] ?? 0)->toBe(0)
        ->and(cpcDi($c, $s, 'Rossi', 'U2')['voci'][$s['pulizie']->id] ?? 0)->toBe(0)
        ->and(cpcDi($c, $s, 'Bianchi', 'U2')['voci'][$s['manutenzioni']->id] ?? 0)->toBeGreaterThan(0)
        ->and($pulizie['motivo'])->toBe('I piani «Preventivo 2026» e «Integrativo 2026» dividono la voce con periodi diversi, e un\'unità della voce ha cambiato titolare: '
            .'il programma non sceglie quale vale. Scrivi la competenza sulle fatture, o correggi i periodi del piano.');
});

it('due piani con periodi diversi ma nessun cambio di titolare: la voce si divide come prima, i periodi non cambiano la parte di nessuno', function () {
    $s = cpdDuePiani($this, 'primo', false);
    $c = cpcLeggi($this, $s);

    // Bianchi tutto l'anno: 300/1000 di 3.300,00 = 990,00.
    expect($c['gestioni'][$s['g']->id]['non_attribuite'][$s['pulizie']->id] ?? 0)->toBe(0)
        ->and(cpcDi($c, $s, 'Bianchi', 'U2')['voci'][$s['pulizie']->id])->toBe(99000);
});

it('due piani con la stessa voce e senza periodi: la voce si divide come prima', function () {
    $s = cpdDuePiani($this, null);
    $c = cpcLeggi($this, $s);

    // Per giorni: Bianchi 181/365 di 990,00 = 490,93, Rossi 184/365 = 499,07.
    expect([cpcDi($c, $s, 'Bianchi', 'U2')['voci'][$s['pulizie']->id], cpcDi($c, $s, 'Rossi', 'U2')['voci'][$s['pulizie']->id]])->toBe([49093, 49907]);
});

it('una riga che comincia il primo giorno dell\'esercizio non è un cambio di titolare: la voce con i periodi diversi si divide, e il già versato non usato va a lei', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    // U2: Bianchi fino al 31/12/2025, Rossi dal 01/01/2026, il primo giorno dell'esercizio.
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['data_fine' => '2025-12-31']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Rossi']->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2026-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $secondo = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Integrativo 2026',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 1, 'giorno_scadenza' => 15, 'data_prima_scadenza' => '2026-09-15', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    $pivot1 = DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['pulizie']->id)->value('id')
        ?? DB::table('piano_rate_capitoli')->insertGetId(['piano_rate_id' => $s['piano']->id, 'conto_id' => $s['pulizie']->id, 'importo' => 300000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $secondo->id, 'conto_id' => $s['pulizie']->id, 'importo' => 30000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('competenze_capitolo')->insert(['piano_rate_capitolo_id' => $pivot1, 'dal' => '2026-01-01', 'al' => '2026-06-30', 'ordine' => 1, 'created_at' => now(), 'updated_at' => now()]);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['pulizie']->id, 'immobile_id' => $s['unita']['U2']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $s['e']->id]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    // Rossi tutto l'esercizio: le Pulizie 300/1000 di 3.300,00 = 990,00 (dal primo piano, con il periodo dichiarato), il già versato non usato € 300,00.
    expect($c['gestioni'][$s['g']->id]['non_attribuite'][$s['pulizie']->id] ?? 0)->toBe(0)
        ->and(cpcDi($c, $s, 'Rossi', 'U2')['voci'][$s['pulizie']->id] ?? 0)->toBeGreaterThan(0)
        ->and((int) (cpcDi($c, $s, 'Rossi', 'U2')['gia_versato_non_usato']['questo_esercizio'] ?? 0))->toBe(30000)
        ->and($c['gestioni'][$s['g']->id]['gia_versato_da_decidere'])->toBe([]);
});

// ─── La spesa personale con la competenza su più giorni ──────────────────────────────────────────────────────────────────────────────

it('una spesa personale con la competenza scritta su più giorni si divide per giorni fra chi era titolare in quei giorni; con un giorno solo, com\'era', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    cpcSpese($s);
    // Il citofono di U2 € 80,00, fattura del 10/08 con la competenza dal 01/06 al 31/07; il passaggio il 01/07.
    $riga = cpcSpesaPersonale($s, 'U2', 8000, '2026-08-10', null, '2026-06-01');
    DB::table('fatture_passive')->where('id', DB::table('righe_fattura')->where('id', $riga)->value('fattura_passiva_id'))->update(['competenza_al' => '2026-07-31']);
    // E una di un giorno solo, il 15/06: tutta a Bianchi.
    cpcSpesaPersonale($s, 'U2', 1000, '2026-08-10', null, '2026-06-15');
    $c = cpcLeggi($this, $s);
    $personali = fn (string $chi) => cpcDi($c, $s, $chi, 'U2')['personali']['non_chieste'] ?? 0;

    // 80,00 × 30/61 = 39,344 → 39,34 a Bianchi (giugno); 80,00 × 31/61 = 40,655 → 40,66 a Rossi (luglio). Più i 10,00 del 15/06 a Bianchi: 49,34.
    expect([$personali('Bianchi'), $personali('Rossi')])->toBe([4934, 4066]);
});

// ─── Il già versato dell'unità non usato, dopo un passaggio ──────────────────────────────────────────────────────────────────────────

it('il già versato dell\'unità che nessun piano ha usato, dopo un cambio di titolare: su nessun foglio, e la scheda dice «a chi va lo decidi tu»', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['pulizie']->id, 'immobile_id' => $s['unita']['U2']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $s['e']->id]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);
    $nonUsato = fn (string $chi) => (int) (cpcDi($c, $s, $chi, 'U2')['gia_versato_non_usato']['questo_esercizio'] ?? 0);
    $scheda = cpdScheda($s)['righe'];

    // Prima: Rossi 300,00, Bianchi 0. Ora nessuno dei due; la gestione lo tiene da decidere, e le righe di U2 lo dicono.
    expect([$nonUsato('Bianchi'), $nonUsato('Rossi')])->toBe([0, 0])
        ->and($c['gestioni'][$s['g']->id]['gia_versato_da_decidere'])->toBe([$s['unita']['U2']->id => 30000])
        ->and(cpdNote($scheda, $s['unita']['U2']->id))->toContain('Già versato registrato per questa unità e non usato dai piani: € 300,00. L\'unità ha cambiato titolare: '
            .'non è sul foglio di nessuno, a chi va lo decidi tu.');
});

it('il già versato dell\'unità non usato senza un cambio di titolare: a chi è titolare, come prima', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['pulizie']->id, 'immobile_id' => $s['unita']['U2']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $s['e']->id]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    expect((int) (cpcDi($c, $s, 'Bianchi', 'U2')['gia_versato_non_usato']['questo_esercizio'] ?? 0))->toBe(30000)
        ->and($c['gestioni'][$s['g']->id]['gia_versato_da_decidere'] ?? [])->toBe([]);
});

// ─── La spesa personale di un giorno senza titolare ──────────────────────────────────────────────────────────────────────────────────

it('la spesa personale di un giorno in cui l\'unità non ha un titolare: la scheda la dice con la strada, e il controllo delle spese personali la conta', function () {
    $s = cpcViaVerdi($this);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Verdi'])->update(['data_fine' => '2026-09-30']);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    cpcSpesaPersonale($s, 'U3', 5000, '2026-10-15');
    $this->travelTo('2026-12-31 12:00:00');
    ['letto' => $letto, 'righe' => $scheda] = cpdScheda($s);
    $gestione = collect($scheda['gestioni'])->firstWhere('id', $s['g']->id);
    $ca = collect(ControlliDelConguaglio::tutti($letto, ['capitoli_netti' => 0], ['esito' => 'quadra', 'nota' => '']))->firstWhere('id', 'CA');

    expect($gestione['avvisi'])->toContain('Spese per singole unità di giorni in cui l\'unità non aveva un titolare (Int. U3): € 50,00. Non sono nel conguaglio di nessuno: '
        .'controlla le date dei titolari, o scrivi la competenza sulla fattura.')
        ->and($ca['esito'])->toBe('quadra')->and($ca['nota'])->toContain('senza un titolare € 50,00');
});

// ─── Il cambio di titolare con giorni scoperti e senza un passaggio registrato ───────────────────────────────────────────────────────

it('un cambio di titolare con giorni scoperti e senza un passaggio registrato: la riga dell\'unità e il controllo per unità lo dicono, con «registra il passaggio»', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    // Bianchi fino al 30/06, Rossi dal 15/07: due righe scritte a mano, nessun passaggio. Il motore non cambia (Rossi conta da inizio anno).
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['data_fine' => '2026-06-30']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Rossi']->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2026-07-15', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    cpcSpese($s);
    $this->travelTo('2026-12-31 12:00:00');
    ['letto' => $letto, 'righe' => $scheda] = cpdScheda($s);
    $cu = ControlliDelConguaglio::perUnita($letto);
    $frase = 'Int. U2: Bianchi fino al 30/06/2026, Rossi dal 15/07/2026, senza un passaggio registrato. Così Rossi conta dall\'inizio dell\'anno; '
        .'con il passaggio registrato conterebbe dal 15/07/2026.';

    // Nella scheda la riga dell'unità dice anche dove si registra; il controllo lo dice nel rimedio.
    expect(cpdNote($scheda, $s['unita']['U2']->id))->toContain($frase.' Registra il passaggio dalla pagina dei titolari dell\'unità.')
        ->and($cu['esito'])->toBe('segnalazione')->and($cu['nota'])->toContain($frase)
        ->and($cu['rimedio'])->toContain('Registra il passaggio')
        ->and($cu['azione'])->toBe('titolari')->and($cu['azione_parametro'])->toBe((string) $s['unita']['U2']->id);
});

it('un cambio di titolare contiguo, o con il passaggio registrato, non ha l\'avviso', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    cpcSpese($s);
    $this->travelTo('2026-12-31 12:00:00');
    ['letto' => $letto, 'righe' => $scheda] = cpdScheda($s);

    expect(implode(' ', cpdNote($scheda, $s['unita']['U2']->id)))->not->toContain('senza un passaggio registrato')
        ->and(ControlliDelConguaglio::perUnita($letto)['esito'])->toBe('quadra');
});

it('un cambio di titolare contiguo scritto a mano, senza il passaggio: nessun giorno scoperto, nessun avviso', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    // Bianchi fino al 14/07, Rossi dal 15/07, senza il passaggio: per il programma è il predecessore chiuso il giorno prima.
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['data_fine' => '2026-07-14']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Rossi']->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2026-07-15', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    cpcSpese($s);
    $this->travelTo('2026-12-31 12:00:00');
    ['letto' => $letto, 'righe' => $scheda] = cpdScheda($s);

    expect($letto['passaggi_non_registrati'])->toBe([])
        ->and(implode(' ', cpdNote($scheda, $s['unita']['U2']->id)))->not->toContain('senza un passaggio registrato');
});

// ─── Il piano con l'esercizio dedotto ────────────────────────────────────────────────────────────────────────────────────────────────

it('un piano dell\'esercizio senza l\'esercizio scritto: la scheda dice che l\'esercizio è dedotto, e dove si scrive', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['esercizio_id' => null]);
    cpcSpese($s);
    $this->travelTo('2026-12-31 12:00:00');
    $gestione = collect(cpdScheda($s)['righe']['gestioni'])->firstWhere('id', $s['g']->id);

    expect($gestione['avvisi'])->toContain('Il piano «Preventivo 2026» non ha l\'esercizio scritto: il programma lo legge come piano di «Esercizio 2026» dalla sua data. '
        .'Se non è il suo, scrivi l\'esercizio nel piano.');
});
