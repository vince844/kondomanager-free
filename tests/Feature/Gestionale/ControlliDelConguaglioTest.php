<?php

/**
 * I controlli del conguaglio nella scheda «Controlli» del rendiconto, letti dalla stessa lettura del consuntivo per persona: la 8.3 con tutti i termini
 * (le spese divise fra le persone + il già versato delle unità + le non attribuite = le spese per capitolo meno le entrate, e i costi tornano con il
 * riepilogo), ogni unità ha la sua parte, le quote tornano con il riparto. Ognuno con un caso che non torna. Il quarto, le spese personali con le
 * anticipazioni, ha i suoi casi in `ConsuntivoPerPersonaChiDecideTest.php`.
 *
 * Il caso è Via Verdi (`ConsuntivoPerPersonaPosizioneScenario.php`): Pulizie € 3.300,00 e Manutenzioni € 900,00 sulla tabella «Generali» (U1 500, U2 300,
 * U3 200), il passaggio di U2 da Bianchi a Rossi il 01/07 con la coppia, il citofono di U3 (€ 80,00, una spesa personale) e «Varie» € 123,45 su una voce
 * senza tabella. Le cifre a mano sono nei commenti.
 */

use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\ControlliDelConguaglio;
use App\Services\Gestionale\ControlliDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
});

/** I controlli della scheda, per id. */
function cdgxControlli($test, array $s): array
{
    $p = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.rendiconto.controlli', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];

    return collect($p['controlli'])->keyBy('id')->all() + ['_esito' => $p['esito']];
}

/** Via Verdi intero, come la scheda «Conguaglio»: rate, incassi, passaggio, spese, il citofono e «Varie» senza tabella. */
function cdgxViaVerdi($test): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    cpcPassaggio($test, $s);
    cpcSpese($s);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');
    $s['varie'] = cpmVoce($s, 'Varie', [], 'spesa', 0);
    cpmSpesa($s, $s['giornale'], $s['varie'], 12345);
    // Il conto delle spese del caso è un conto di costo, come quello di una fattura: i costi dell'esercizio lo contano.
    DB::table('conti_contabili')->where('id', $s['giornale']['costi'])->update(['tipo' => 'costo', 'categoria' => 'costi']);
    $test->travelTo('2027-01-10 10:00:00');

    return $s;
}

it('la 8.3 con tutti i termini: le spese divise fra le persone, il già versato, le non attribuite fanno le spese per capitolo; e i costi tornano', function () {
    $s = cdgxViaVerdi($this);
    $c = cdgxControlli($this, $s);

    // Divise fra le persone: Pulizie 3.300,00 + Manutenzioni 900,00 = 4.200,00; il già versato delle unità 0; non attribuite «Varie» 123,45; in tutto
    // 4.323,45 = le spese per capitolo del riepilogo (3.300,00 + 900,00 + 123,45). Il citofono è una spesa personale: non è di nessuna voce.
    expect($c['CG']['esito'])->toBe('quadra')
        ->and([$c['CG']['sinistra'], $c['CG']['destra']])->toBe([432345, 432345])
        ->and($c['CG']['nota'])->toStartWith('Spese divise fra le persone € 4.200,00 + già versato delle unità tolto dalle loro spese € 0,00 + spese non attribuite € 123,45 = spese per capitolo meno entrate € 4.323,45. Costi dell\'esercizio € 4.323,45 = ');
});

it('la 8.3 non torna quando le spese per capitolo non sono quelle che il conguaglio divide: la differenza e il rimedio', function () {
    $s = cdgxViaVerdi($this);
    $letto = app(ConguaglioDelRendiconto::class)->leggi($s['e']->fresh());
    $costi = ControlliDelRendiconto::costi(['costi' => 442345, 'capitoli_netti' => 442345, 'commissioni_senza_voce' => 0, 'contestate' => 0, 'non_classificati' => 0, 'differenza' => 0]);
    // Le spese per capitolo € 4.423,45, € 100,00 più di quelle che la lettura divide: una spesa che il conguaglio non legge.
    $cg = ControlliDelConguaglio::spese($letto, ['capitoli_netti' => 442345], $costi);

    expect($cg['esito'])->toBe('non_quadra')->and($cg['segno'])->toBe('≠')
        ->and($cg['nota'])->toContain('spese non attribuite € 123,45 ≠ spese per capitolo meno entrate € 4.423,45.')
        ->and($cg['breve'])->toBe('differenza € -100,00')
        ->and($cg['azione'])->toBe('conguaglio')->and($cg['rimedio'])->toContain('Apri la scheda «Conguaglio»');

    // La prima uguaglianza torna, i costi del riepilogo no (€ 50,00 di costi senza voce): non torna il controllo dei costi, e la 8.3 lo dice e rimanda a
    // lui, senza contare la stessa differenza una seconda volta nell'esito.
    $costiNo = ControlliDelRendiconto::costi(['costi' => 437345, 'capitoli_netti' => 432345, 'commissioni_senza_voce' => 0, 'contestate' => 0, 'non_classificati' => 5000, 'differenza' => 0]);
    $cg = ControlliDelConguaglio::spese($letto, ['capitoli_netti' => 432345], $costiNo);
    expect($costiNo['esito'])->toBe('non_quadra')->and($cg['esito'])->toBe('quadra')->and($cg['segno'])->toBe('=')
        ->and($cg['nota'])->toContain('I costi non tornano con il riepilogo: lo dice il controllo «I costi tornano con il riepilogo», con il perché.')
        ->and($cg['nota'])->not->toContain('Costi dell\'esercizio € 4.373,45')
        ->and($cg['breve'])->toBeNull()->and($cg['rimedio'])->toBeNull();
});

it('ogni unità ha la sua parte con dodici fatture mensili delle pulizie che dicono la competenza: i centesimi di ogni divisione non la fanno cadere', function () {
    $s = cdgxViaVerdi($this);
    $this->travelTo('2026-12-20 10:00:00');
    for ($m = 1; $m <= 12; $m++) {
        $dal = sprintf('2026-%02d-01', $m);
        cpmSpesa($s, $s['giornale'], $s['pulizie'], 27533, ['competenza_dal' => $dal, 'competenza_al' => \Carbon\CarbonImmutable::parse($dal)->endOfMonth()->format('Y-m-d')]);
    }
    $this->travelTo('2027-01-10 10:00:00');
    $c = cdgxControlli($this, $s);

    // € 275,33 al mese: ogni fattura si divide da sola, con il suo resto. Prima la tolleranza contava una divisione per voce: «2 unità non tornano: U1 …
    // mancano € 0,06; U3 … in più € 0,05», con zero spese non attribuite.
    expect($c['CU']['esito'])->toBe('quadra')->and([$c['CU']['sinistra'], $c['CU']['destra']])->toBe([0, 3])
        ->and($c['CG']['esito'])->toBe('quadra');
});

it('le quote tornano con il riparto, ma la tabella è cambiata dopo il piano: il controllo lo dice, unità per unità, e il conguaglio comprende la differenza', function () {
    $s = cdgxViaVerdi($this);
    // Dopo il piano la tabella «Generali» passa da U1 500 / U3 200 a U1 400 / U3 300 (U2 resta 300; la somma resta 1.000).
    DB::table('quote_tabella')->where('tabella_id', $s['generali']->id)->where('immobile_id', $s['unita']['U1']->id)->update(['valore' => 400]);
    DB::table('quote_tabella')->where('tabella_id', $s['generali']->id)->where('immobile_id', $s['unita']['U3']->id)->update(['valore' => 300]);
    $c = cdgxControlli($this, $s);
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['conguaglio'];
    $neri = collect($p['righe'])->first(fn ($r) => ! $r['subtotale'] && $r['anagrafica_id'] === $s['persone']['Neri']->id);

    // Neri: le spese con la tabella di oggi, 400/1000 di 4.200,00 = 1.680,00, contro le quote del piano, 2.000,00: −320,00 (con la tabella del piano +100,00).
    // La riga resta: il conguaglio comprende la differenza (la regola scelta), e il controllo la fa leggere.
    expect($c['CB']['esito'])->toBe('segnalazione')
        ->and($c['CB']['nota'])->toContain('La tabella è cambiata dopo il piano su 2 unità: Int. U1 (Generali, 500,00 su 1.000,00 nel piano, 400,00 su 1.000,00 oggi); Int. U3 (Generali, 200,00 su 1.000,00 nel piano, 300,00 su 1.000,00 oggi).')
        ->and($c['CB']['nota'])->toContain('Il conguaglio comprende la differenza: le quote sono del piano, le spese dell\'esercizio sono divise con la tabella di oggi.')
        ->and($c['CB']['azione'])->toBe('piano')
        ->and($neri['conguaglio'])->toBe(-32000)->and($neri['non_disponibile'])->toBeNull();
});

it('un\'unità aggiunta alla tabella dopo il piano: i millesimi delle altre non cambiano, cambia la somma, e il controllo lo dice per ognuna', function () {
    $s = cdgxViaVerdi($this);
    // Un frazionamento: U4, 100 millesimi, entra nella «Generali» dopo il piano; la somma passa da 1.000 a 1.100.
    $u4 = \App\Models\Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'CDGX-U4-'.$s['c']->id, 'nome' => 'U4', 'interno' => 'U4']);
    DB::table('quote_tabella')->insert(['tabella_id' => $s['generali']->id, 'immobile_id' => $u4->id, 'valore' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $c = cdgxControlli($this, $s);

    expect($c['CB']['esito'])->toBe('segnalazione')
        ->and($c['CB']['nota'])->toContain('La tabella è cambiata dopo il piano su 3 unità: Int. U1 (Generali, 500,00 su 1.000,00 nel piano, 500,00 su 1.100,00 oggi); '
            .'Int. U2 (Generali, 300,00 su 1.000,00 nel piano, 300,00 su 1.100,00 oggi); Int. U3 (Generali, 200,00 su 1.000,00 nel piano, 200,00 su 1.100,00 oggi).');
});

it('ogni unità ha la sua parte: le tre unità tornano, a meno dei centesimi del resto, anche con una voce all\'80 %', function () {
    $s = cdgxViaVerdi($this);
    // L'ascensore, € 1.000,00 sulla tabella «Generali» all'80 %: alle unità 800,00 (U1 400,00, U2 240,00, U3 160,00), 200,00 a nessuno.
    $ascensore = cpmVoce($s, 'Ascensore', [[$s['generali'], 80]], 'spesa', 0);
    cpmSpesa($s, $s['giornale'], $ascensore, 100000);
    $c = cdgxControlli($this, $s);

    // U1 500/1000 di 4.200,00 = 2.100,00 a Neri; U2 1.260,00 = Bianchi 624,82 + Rossi 635,18; U3 840,00 a Verdi. «Varie» non ha tabella: non conta.
    // Con l'ascensore la parte di ogni unità è all'80 %, come le persone: senza il coefficiente U1 avrebbe 500,00 contro 400,00.
    expect($c['CU']['esito'])->toBe('quadra')->and([$c['CU']['sinistra'], $c['CU']['destra']])->toBe([0, 3])
        ->and($c['CU']['nota'])->toBe('Per ognuna delle 3 unità la somma delle persone è la sua parte della spesa divisa sulla sola tabella, a meno dei centesimi del resto.');
});

it('ogni unità ha la sua parte: U2 senza un titolare dopo l\'uscita di Bianchi non torna, e il rimedio porta ai titolari dell\'unità', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    // Bianchi esce il 30/06 e nessuno entra: dal 01/07 U2 non ha un titolare.
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['data_fine' => '2026-06-30']);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $c = cdgxControlli($this, $s);

    // La parte di U2: 300/1000 di 4.200,00 = 1.260,00. Bianchi ha i suoi 181 giorni: Pulizie 99.000 × 181/365 = 49.093,15 → 490,93, Manutenzioni
    // 27.000 × 181/365 = 13.389,04 → 133,89: 624,82. Mancano 635,18, i 184 giorni di nessuno, fra le non attribuite delle due voci.
    expect($c['CU']['esito'])->toBe('non_quadra')->and([$c['CU']['sinistra'], $c['CU']['destra']])->toBe([1, 3])
        ->and($c['CU']['nota'])->toStartWith('Un\'unità non torna: Int. U2 (Ordinaria 2026): persone € 624,82, sua parte € 1.260,00, mancano € 635,18.')
        ->and($c['CU']['azione'])->toBe('titolari')->and($c['CU']['azione_parametro'])->toBe((string) $s['unita']['U2']->id)
        ->and($c['CU']['rimedio'])->toContain('controlla le date dei titolari dell\'unità');
});

it('le quote tornano con il riparto; una riga del riparto cambiata dopo il piano no, e il conguaglio di quell\'unità non è disponibile, con il perché', function () {
    $s = cdgxViaVerdi($this);
    $c = cdgxControlli($this, $s);

    // Il piano di Via Verdi, generato dal programma: U1 2.000,00, U2 1.200,00, U3 800,00 (750,00 + il riporto di 50,00 fuori dalla spesa): le quote = il riparto.
    expect($c['CB']['esito'])->toBe('quadra')->and([$c['CB']['sinistra'], $c['CB']['destra']])->toBe([0, 3]);

    // Una riga del riparto di U2 cambiata dopo il piano, + 10,00: il riparto di U2 fa 1.210,00, le quote 1.200,00. (Il programma non ha una strada che
    // cambi una delle due senza l'altra: la riga si cambia a mano, come la troverebbe il controllo.)
    $riga = DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('immobile_id', $s['unita']['U2']->id)->where('tipo', 'riparto')->orderBy('id')->value('id');
    DB::table('righe_riparto')->where('id', $riga)->increment('importo', 1000);
    $c = cdgxControlli($this, $s);
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['conguaglio'];
    $bianchi = collect($p['righe'])->first(fn ($r) => ! $r['subtotale'] && $r['anagrafica_id'] === $s['persone']['Bianchi']->id && $r['immobile_id'] === $s['unita']['U2']->id);
    $neri = collect($p['righe'])->first(fn ($r) => ! $r['subtotale'] && $r['anagrafica_id'] === $s['persone']['Neri']->id);

    expect($c['CB']['esito'])->toBe('non_quadra')->and([$c['CB']['sinistra'], $c['CB']['destra']])->toBe([1, 3])
        ->and($c['CB']['nota'])->toStartWith('Un\'unità non torna: Int. U2 (Ordinaria 2026): quote € 1.200,00, riparto € 1.210,00.')
        ->and($c['CB']['azione'])->toBe('piano')->and($c['CB']['azione_parametro'])->toBe((string) $s['piano']->id)
        ->and($bianchi['conguaglio'])->toBeNull()
        ->and($bianchi['non_disponibile'])->toBe('Le quote dei piani su questa unità (€ 1.200,00) non sono il riparto registrato (€ 1.210,00): il piano è cambiato dopo il riparto, per esempio una tabella. Il conguaglio di questa unità non si calcola.')
        ->and($neri['conguaglio'])->toBe(10000);
});

it('i controlli sono quattordici, sempre: senza la lettura i quattro del conguaglio ci sono, «non calcolati», fuori dal conto dei «da leggere»', function () {
    $s = cdgxViaVerdi($this);
    $tutti = app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $s['e']->fresh());

    expect(array_column($tutti['controlli'], 'id'))->toBe(['PD', 'R6', 'R7', 'R8', 'R2', 'CN', 'AC', 'CR', 'CP', 'GE', 'CG', 'CU', 'CB', 'CA'])
        ->and(array_column(array_slice($tutti['controlli'], 10), 'esito'))->toBe(['non_calcolato', 'non_calcolato', 'non_calcolato', 'non_calcolato'])
        ->and($tutti['controlli'][10]['nota'])->toBe(ControlliDelConguaglio::NOTA_NON_LETTO)
        ->and($tutti['esito']['non_calcolati'])->toBe(4)
        ->and($tutti['esito']['tornano'] + $tutti['esito']['non_tornano'] + $tutti['esito']['da_leggere'])->toBe(10)
        ->and($tutti['esito']['frase'])->toEndWith(', 4 non calcolati')
        ->and($tutti['esito']['riga_esiti'])->toEndWith(ControlliDelRendiconto::RIGA_NON_CALCOLATI);
    // In stampa la nota non rimanda a una scheda del programma.
    expect(\App\Services\Gestionale\ParoleDellaStampa::frase(ControlliDelConguaglio::NOTA_NON_LETTO))->not->toContain('scheda');

    $letto = app(ConguaglioDelRendiconto::class)->leggi($s['e']->fresh());
    $con = app(ControlliDelRendiconto::class)->conIlConguaglio($tutti, $letto);
    expect(array_column($con['controlli'], 'id'))->toBe(array_column($tutti['controlli'], 'id'))
        ->and(array_column(array_slice($con['controlli'], 10), 'esito'))->toBe(['quadra', 'quadra', 'quadra', 'quadra'])
        ->and($con['esito']['tornano'])->toBe($tutti['esito']['tornano'] + 4)->and($con['esito']['non_calcolati'])->toBe(0)
        ->and($con['esito']['frase'])->not->toContain('non calcolat')->and($con['esito']['riga_esiti'])->toBe(ControlliDelRendiconto::RIGA_ESITI)
        ->and($con['conguaglio'])->toBe($letto);
});
