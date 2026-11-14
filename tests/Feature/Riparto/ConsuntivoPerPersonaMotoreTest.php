<?php

/**
 * Il consuntivo per persona, la porta del motore del riparto (`CalcoloQuoteService::consuntivoPerGestione`).
 *
 * Il caso è «Via Verdi», esercizio 2026 (365 giorni): U1 di Neri, 500 millesimi; U2, 300 millesimi, di Bianchi fino al 30/06
 * e di Rossi dal 01/07 (181 e 184 giorni); U3 di Verdi, 200 millesimi. Ogni cifra attesa è fatta a mano nel commento, con i resti
 * maggiori sull'intera voce: non è letta dal codice.
 *
 * Il caso e i suoi aiuti (`cpm*`) sono in `ConsuntivoPerPersonaScenario.php`, condivisi con la prova del servizio.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\CalcoloQuoteService;
use App\Services\Riparto\CompetenzaDelPiano;
use App\Services\Riparto\RisolutoreTitolari;
use App\Services\Riparto\UnitaMista;
use Illuminate\Support\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

require_once __DIR__ . '/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__ . '/ConsuntivoPerPersonaScenario.php';

it('divide la spesa di Via Verdi per persona e voce al centesimo, con i giorni del passaggio', function () {
    $s = cpmViaVerdi();
    $pulizie = cpmVoce($s, 'Pulizie', [[$s['generali'], 100]]);
    $manutenzioni = cpmVoce($s, 'Manutenzioni', [[$s['generali'], 100]]);

    $esito = cpmPorta($s, [$pulizie->id => 330000, $manutenzioni->id => 90000]);

    // Pulizie € 3.300,00: Neri 500‰ = 1.650,00; Verdi 200‰ = 660,00; U2 = 990,00 per giorni: Bianchi 99.000 × 181/365 = 49.093,15,
    // Rossi 99.000 × 184/365 = 49.906,85; le parti intere fanno 329.999, il centesimo al resto più grande (,85): Rossi 499,07.
    [$n, $b, $r, $v] = [$s['persone']['Neri'], $s['persone']['Bianchi'], $s['persone']['Rossi'], $s['persone']['Verdi']];
    expect([cpmDi($esito, $n, $pulizie), cpmDi($esito, $b, $pulizie), cpmDi($esito, $r, $pulizie), cpmDi($esito, $v, $pulizie)])->toBe([165000, 49093, 49907, 66000]);
    // Manutenzioni € 900,00: 450,00; U2 = 270,00: Bianchi 27.000 × 181/365 = 13.389,04, Rossi 13.610,96 → il centesimo a Rossi.
    expect([cpmDi($esito, $n, $manutenzioni), cpmDi($esito, $b, $manutenzioni), cpmDi($esito, $r, $manutenzioni), cpmDi($esito, $v, $manutenzioni)])->toBe([45000, 13389, 13611, 18000]);
    // Le persone fanno le uscite, voce per voce; niente di non attribuito; nessuna riga di spiegazione chiesta.
    expect(cpmSommaVoce($esito, $pulizie))->toBe(330000)->and(cpmSommaVoce($esito, $manutenzioni))->toBe(90000)
        ->and($esito['non_attribuite'])->toBe([])->and($esito['righe'])->toBe([])->and($esito['non_calcolabile'])->toBeNull();
    // Bianchi e Rossi sulla stessa unità, una riga per persona e unità.
    expect(array_keys($esito['persone'][$b->id]))->toBe([$s['unita']['U2']->id])->and(array_keys($esito['persone'][$r->id]))->toBe([$s['unita']['U2']->id]);
});

it('con la cifra del preventivo dà le stesse quote del motore del preventivo, voce per voce: una voce invariata non sposta un centesimo', function () {
    $s = cpmViaVerdi();
    $pulizie = cpmVoce($s, 'Pulizie', [[$s['generali'], 100]]);
    $manutenzioni = cpmVoce($s, 'Manutenzioni', [[$s['generali'], 100]]);
    $piano = PianoRate::create(['gestione_id' => $s['gestione']->id, 'condominio_id' => $s['condominio']->id, 'nome' => 'Piano', 'stato' => 'bozza', 'tipo' => 'ordinario', 'numero_rate' => 1]);
    DB::table('piano_rate_capitoli')->insert([
        ['piano_rate_id' => $piano->id, 'conto_id' => $pulizie->id, 'importo' => 300001, 'created_at' => now(), 'updated_at' => now()],
        ['piano_rate_id' => $piano->id, 'conto_id' => $manutenzioni->id, 'importo' => 100001, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $motore = new CalcoloQuoteService();
    $motore->calcolaPerGestione($s['gestione']->fresh(), $piano->fresh(), true, app(CompetenzaDelPiano::class)->perPiano($piano->fresh(), $s['esercizio']));
    $preventivo = [];
    foreach ($motore->getRigheDettaglio() as $riga) {
        if ($riga['tipo'] === 'riparto') {
            $preventivo[$riga['anagrafica_id']][$riga['immobile_id']][$riga['conto_id']] = ($preventivo[$riga['anagrafica_id']][$riga['immobile_id']][$riga['conto_id']] ?? 0) + $riga['importo'];
        }
    }

    // Cifre con un resto (€ 3.000,01 e € 1.000,01): il centesimo va dove lo manda il preventivo.
    $esito = cpmPorta($s, [$pulizie->id => 300001, $manutenzioni->id => 100001]);

    ksort($preventivo);
    $consuntivo = $esito['persone'];
    ksort($consuntivo);
    expect($consuntivo)->toEqual($preventivo);
});

it('divide una voce su due tabelle, e una voce senza tabella resta non attribuita con il suo motivo', function () {
    $s = cpmViaVerdi();
    $scale = cpmTabella($s, 'Scale', ['U1' => 200, 'U2' => 300, 'U3' => 500]);
    $ascensore = cpmVoce($s, 'Ascensore', [[$s['generali'], 50], [$scale, 50]]);
    $varie = cpmVoce($s, 'Varie', []);

    $esito = cpmPorta($s, [$ascensore->id => 100000, $varie->id => 12345]);

    // € 1.000,00, metà su Generali e metà su Scale: Neri 250,00 + 100,00 = 350,00; Verdi 100,00 + 250,00 = 350,00;
    // U2 150,00 + 150,00 = 300,00 per giorni: Bianchi 30.000 × 181/365 = 14.876,71, Rossi 15.123,29 → parti 99.999, il centesimo a Bianchi (,71).
    [$n, $b, $r, $v] = [$s['persone']['Neri'], $s['persone']['Bianchi'], $s['persone']['Rossi'], $s['persone']['Verdi']];
    expect([cpmDi($esito, $n, $ascensore), cpmDi($esito, $b, $ascensore), cpmDi($esito, $r, $ascensore), cpmDi($esito, $v, $ascensore)])->toBe([35000, 14877, 15123, 35000]);
    // La voce senza tabella: nessuna persona, € 123,45 non attribuiti, con il motivo.
    expect(cpmSommaVoce($esito, $varie))->toBe(0)
        ->and($esito['non_attribuite'])->toBe([$varie->id => 12345])
        ->and($esito['motivi'][$varie->id])->toBe(['conto_senza_tabella' => 12345]);
});

it('una voce di entrata incassata dà una spesa negativa a ognuno; una non incassata non dà niente', function () {
    $s = cpmViaVerdi();
    $antenna = cpmVoce($s, 'Affitto antenna', [[$s['generali'], 100]], 'entrata');
    $lavatrici = cpmVoce($s, 'Lavanderia', [[$s['generali'], 100]], 'entrata');

    // L'antenna ha incassato € 240,00: il giornale dà −24.000 (dare − avere). La lavanderia non ha incassato: nessuna cifra.
    $esito = cpmPorta($s, [$antenna->id => -24000]);

    // Neri −120,00; Verdi −48,00; U2 −72,00 per giorni: Bianchi 7.200 × 181/365 = 3.570,41, Rossi 3.629,59 → il centesimo a Rossi (,59).
    [$n, $b, $r, $v] = [$s['persone']['Neri'], $s['persone']['Bianchi'], $s['persone']['Rossi'], $s['persone']['Verdi']];
    expect([cpmDi($esito, $n, $antenna), cpmDi($esito, $b, $antenna), cpmDi($esito, $r, $antenna), cpmDi($esito, $v, $antenna)])->toBe([-12000, -3570, -3630, -4800])
        ->and(cpmSommaVoce($esito, $antenna))->toBe(-24000)
        ->and(cpmSommaVoce($esito, $lavatrici))->toBe(0)
        ->and($esito['non_attribuite'])->toBe([]);
});

it('con i coefficienti sotto il cento divide la parte dichiarata e lascia il resto non attribuito', function () {
    $s = cpmViaVerdi();
    $giardino = cpmVoce($s, 'Giardino', [[$s['generali'], 80]]);

    $esito = cpmPorta($s, [$giardino->id => 100000]);

    // L'80 % di € 1.000,00 = € 800,00: Neri 400,00, Verdi 160,00, U2 240,00 per giorni (Bianchi 24.000 × 181/365 = 11.901,37,
    // Rossi 12.098,63 → il centesimo a Rossi); il 20 %, € 200,00, a nessuno.
    [$n, $b, $r, $v] = [$s['persone']['Neri'], $s['persone']['Bianchi'], $s['persone']['Rossi'], $s['persone']['Verdi']];
    expect([cpmDi($esito, $n, $giardino), cpmDi($esito, $b, $giardino), cpmDi($esito, $r, $giardino), cpmDi($esito, $v, $giardino)])->toBe([40000, 11901, 12099, 16000])
        ->and($esito['non_attribuite'])->toBe([$giardino->id => 20000])
        ->and($esito['motivi'][$giardino->id])->toBe(['coefficienti_sotto_il_cento' => 20000]);
});

it('con il piano sulla sottovoce, come lo salva la pagina del piano, divide la sottovoce sulle sue tabelle', function () {
    $s = cpmViaVerdi();
    $scale = cpmTabella($s, 'Scale', ['U1' => 0, 'U2' => 400, 'U3' => 600]);
    $generali = cpmVoce($s, 'Spese generali', [[$s['generali'], 100]], 'spesa', 100000);
    $cancelleria = cpmVoce($s, 'Cancelleria', [[$scale, 100]], 'spesa', 10000, $generali->id);
    [$piano, $capitoli] = cpmPiano($s, [$cancelleria->id => 10000]);

    $esito = cpmPorta($s, [$cancelleria->id => 10000], ['capitoli' => $capitoli]);

    // € 100,00 su Scale: Neri 0 millesimi, niente; Verdi 600 = 60,00; U2 400 = 40,00 per giorni: Bianchi 4.000 × 181/365 = 1.983,56,
    // Rossi 2.016,44 centesimi, il centesimo al resto più grande (,56): Bianchi 19,84, Rossi 20,16.
    [$n, $b, $r, $v] = [$s['persone']['Neri'], $s['persone']['Bianchi'], $s['persone']['Rossi'], $s['persone']['Verdi']];
    expect([cpmDi($esito, $n, $cancelleria), cpmDi($esito, $b, $cancelleria), cpmDi($esito, $r, $cancelleria), cpmDi($esito, $v, $cancelleria)])->toBe([0, 1984, 2016, 6000])
        ->and(cpmSommaVoce($esito, $generali))->toBe(0)
        ->and($esito['persone'])->toEqual(cpmPreventivo($s, $piano)['persone']);
});

it('con il piano sulla sottovoce senza tabelle la lascia non attribuita, come il preventivo che non la chiede a nessuno', function () {
    $s = cpmViaVerdi();
    $generali = cpmVoce($s, 'Spese generali', [[$s['generali'], 100]]);
    $cancelleria = cpmVoce($s, 'Cancelleria', [], 'spesa', 6000, $generali->id);
    [$piano, $capitoli] = cpmPiano($s, [$cancelleria->id => 6000]);

    $esito = cpmPorta($s, [$cancelleria->id => 6000], ['capitoli' => $capitoli]);

    // Il preventivo: € 60,00 scoperti sulla sottovoce, `conto_senza_tabella`; nessuna persona.
    expect($esito['persone'])->toBe([])
        ->and($esito['non_attribuite'])->toBe([$cancelleria->id => 6000])
        ->and($esito['motivi'][$cancelleria->id])->toBe(['conto_senza_tabella' => 6000])
        ->and(cpmPreventivo($s, $piano)['scoperti'])->toBe([$cancelleria->id => ['conto_senza_tabella' => 6000]]);
});

it('con il piano sul capitolo con tabelle, come lo scrive la riga dei capitoli orfani, divide le sottovoci insieme sul capitolo', function () {
    $s = cpmViaVerdi();
    $scale = cpmTabella($s, 'Scale', ['U1' => 0, 'U2' => 400, 'U3' => 600]);
    $generali = cpmVoce($s, 'Spese generali', [[$s['generali'], 100]]);
    $cancelleria = cpmVoce($s, 'Cancelleria', [[$scale, 100]], 'spesa', 0, $generali->id);
    $postali = cpmVoce($s, 'Postali', [], 'spesa', 0, $generali->id);
    [$piano, $capitoli] = cpmPiano($s, [$generali->id => 10000]);

    $esito = cpmPorta($s, [$cancelleria->id => 6000, $postali->id => 4000], ['capitoli' => $capitoli]);

    // € 60,00 + € 40,00 sul capitolo, su Generali, divisi una volta: Neri 50,00, Verdi 20,00, U2 30,00 (Bianchi 3.000 × 181/365 = 1.487,67,
    // Rossi 1.512,33 centesimi → 14,88 e 15,12). Le uscite e le persone hanno la stessa chiave, il capitolo, e la mappa dice da dove vengono.
    [$n, $b, $r, $v] = [$s['persone']['Neri'], $s['persone']['Bianchi'], $s['persone']['Rossi'], $s['persone']['Verdi']];
    expect([cpmDi($esito, $n, $generali), cpmDi($esito, $b, $generali), cpmDi($esito, $r, $generali), cpmDi($esito, $v, $generali)])->toBe([5000, 1488, 1512, 2000])
        ->and($esito['uscite'])->toBe([$generali->id => 10000])
        ->and($esito['diviso_su'])->toEqual([$cancelleria->id => [$generali->id => 6000], $postali->id => [$generali->id => 4000]])
        ->and($esito['non_attribuite'])->toBe([])
        ->and($esito['persone'])->toEqual(cpmPreventivo($s, $piano)['persone']);
});

it('senza un piano che la tenga, la sottovoce si divide sulle sue tabelle', function () {
    $s = cpmViaVerdi();
    $scale = cpmTabella($s, 'Scale', ['U1' => 0, 'U2' => 400, 'U3' => 600]);
    $generali = cpmVoce($s, 'Spese generali', [[$s['generali'], 100]]);
    $cancelleria = cpmVoce($s, 'Cancelleria', [[$scale, 100]], 'spesa', 0, $generali->id);

    $esito = cpmPorta($s, [$cancelleria->id => 10000]);

    // Come con il piano sulla sottovoce: Bianchi 19,84, Rossi 20,16, Verdi 60,00, Neri niente.
    [$n, $b, $r, $v] = [$s['persone']['Neri'], $s['persone']['Bianchi'], $s['persone']['Rossi'], $s['persone']['Verdi']];
    expect([cpmDi($esito, $n, $cancelleria), cpmDi($esito, $b, $cancelleria), cpmDi($esito, $r, $cancelleria), cpmDi($esito, $v, $cancelleria)])->toBe([0, 1984, 2016, 6000])
        ->and($esito['uscite'])->toBe([$cancelleria->id => 10000]);
});

it('la spesa scritta sul capitolo, con il piano sul capitolo, si divide fra le sottovoci come il preventivo', function () {
    $s = cpmViaVerdi();
    $scale = cpmTabella($s, 'Scale', ['U1' => 0, 'U2' => 400, 'U3' => 600]);
    $capitolo = cpmVoce($s, 'Spese generali', []);
    $cancelleria = cpmVoce($s, 'Cancelleria', [[$scale, 100]], 'spesa', 6000, $capitolo->id);
    $postali = cpmVoce($s, 'Postali', [[$s['generali'], 100]], 'spesa', 4000, $capitolo->id);
    [$piano, $capitoli] = cpmPiano($s, [$capitolo->id => 5000]);

    $esito = cpmPorta($s, [$capitolo->id => 5000], ['capitoli' => $capitoli]);

    // € 50,00 fra le sottovoci per i loro importi (60 e 40): € 30,00 a Cancelleria su Scale, U2 12,00 per giorni (Bianchi 1.200 × 181/365 = 595,07,
    // Rossi 604,93 centesimi → 5,95 e 6,05), Verdi 18,00; € 20,00 a Postali su Generali: Neri 10,00, U2 6,00 (297,53 e 302,47 → 2,98 e 3,02), Verdi 4,00.
    [$n, $b, $r, $v] = [$s['persone']['Neri'], $s['persone']['Bianchi'], $s['persone']['Rossi'], $s['persone']['Verdi']];
    expect([cpmDi($esito, $n, $cancelleria), cpmDi($esito, $b, $cancelleria), cpmDi($esito, $r, $cancelleria), cpmDi($esito, $v, $cancelleria)])->toBe([0, 595, 605, 1800])
        ->and([cpmDi($esito, $n, $postali), cpmDi($esito, $b, $postali), cpmDi($esito, $r, $postali), cpmDi($esito, $v, $postali)])->toBe([1000, 298, 302, 400])
        ->and($esito['non_attribuite'])->toBe([])
        ->and($esito['diviso_su'])->toEqual([$capitolo->id => [$cancelleria->id => 3000, $postali->id => 2000]])
        ->and($esito['uscite'])->toEqual([$cancelleria->id => 3000, $postali->id => 2000])
        ->and($esito['persone'])->toEqual(cpmPreventivo($s, $piano)['persone']);
});

it('la spesa sul capitolo va solo alle sottovoci che c\'erano quando il piano è nato, come nel preventivo', function () {
    $s = cpmViaVerdi();
    $scale = cpmTabella($s, 'Scale', ['U1' => 0, 'U2' => 400, 'U3' => 600]);
    $capitolo = cpmVoce($s, 'Spese generali', []);
    $cancelleria = cpmVoce($s, 'Cancelleria', [[$scale, 100]], 'spesa', 6000, $capitolo->id);
    $postali = cpmVoce($s, 'Postali', [[$s['generali'], 100]], 'spesa', 4000, $capitolo->id);
    DB::table('conti')->where('id', $cancelleria->id)->update(['created_at' => '2020-01-01 00:00:00']);
    DB::table('conti')->where('id', $postali->id)->update(['created_at' => '2099-01-01 00:00:00']);
    [$piano, $capitoli] = cpmPiano($s, [$capitolo->id => 5000]);

    $esito = cpmPorta($s, [$capitolo->id => 5000], ['capitoli' => $capitoli]);

    // Postali è nata dopo il piano: tutti i € 50,00 a Cancelleria su Scale, U2 20,00 (Bianchi 2.000 × 181/365 = 991,78, Rossi 1.008,22 centesimi → 9,92 e 10,08), Verdi 30,00.
    expect([cpmDi($esito, $s['persone']['Bianchi'], $cancelleria), cpmDi($esito, $s['persone']['Rossi'], $cancelleria), cpmDi($esito, $s['persone']['Verdi'], $cancelleria)])->toBe([992, 1008, 3000])
        ->and(cpmSommaVoce($esito, $postali))->toBe(0)
        ->and($esito['persone'])->toEqual(cpmPreventivo($s, $piano)['persone']);
});

it('la spesa scritta sul capitolo senza tabelle, senza un piano che lo tenga, resta non attribuita', function () {
    $s = cpmViaVerdi();
    $scale = cpmTabella($s, 'Scale', ['U1' => 0, 'U2' => 400, 'U3' => 600]);
    $capitolo = cpmVoce($s, 'Spese generali', []);
    cpmVoce($s, 'Cancelleria', [[$scale, 100]], 'spesa', 6000, $capitolo->id);

    $esito = cpmPorta($s, [$capitolo->id => 5000]);

    expect($esito['persone'])->toBe([])
        ->and($esito['non_attribuite'])->toBe([$capitolo->id => 5000])
        ->and($esito['motivi'][$capitolo->id])->toBe(['conto_senza_tabella' => 5000]);
});

it('con il piano sul capitolo senza tabelle la sottovoce senza tabelle resta non attribuita, come lo scoperto del preventivo', function () {
    $s = cpmViaVerdi();
    $scale = cpmTabella($s, 'Scale', ['U1' => 0, 'U2' => 400, 'U3' => 600]);
    $capitolo = cpmVoce($s, 'Spese generali', []);
    $cancelleria = cpmVoce($s, 'Cancelleria', [[$scale, 100]], 'spesa', 6000, $capitolo->id);
    $postali = cpmVoce($s, 'Postali', [], 'spesa', 4000, $capitolo->id);
    [$piano, $capitoli] = cpmPiano($s, [$capitolo->id => 10000]);

    // La spesa per voce come la divide il preventivo: € 60,00 e € 40,00.
    $esito = cpmPorta($s, [$cancelleria->id => 6000, $postali->id => 4000], ['capitoli' => $capitoli]);
    $preventivo = cpmPreventivo($s, $piano);

    // Cancelleria su Scale: Bianchi 2.400 × 181/365 = 1.190,14, Rossi 1.209,86 centesimi → 11,90 e 12,10, Verdi 36,00; Postali: € 40,00 a nessuno, nei due.
    expect([cpmDi($esito, $s['persone']['Bianchi'], $cancelleria), cpmDi($esito, $s['persone']['Rossi'], $cancelleria), cpmDi($esito, $s['persone']['Verdi'], $cancelleria)])->toBe([1190, 1210, 3600])
        ->and($esito['persone'])->toEqual($preventivo['persone'])
        ->and($esito['non_attribuite'])->toBe([$postali->id => 4000])
        ->and($preventivo['scoperti'])->toBe([$postali->id => ['conto_senza_tabella' => 4000]]);
});

it('una straordinaria con la delibera va tutta a chi era titolare il giorno della delibera', function () {
    $s = cpmViaVerdi('straordinaria');
    $facciata = cpmVoce($s, 'Facciata', [[$s['generali'], 100]]);

    $esito = cpmPorta($s, [['conto_id' => $facciata->id, 'importo' => 1000000]], ['data_delibera' => '2026-03-15']);

    // Il 15/03 U2 è di Bianchi: € 10.000,00 → Neri 5.000,00, Bianchi 3.000,00, Rossi niente, Verdi 2.000,00.
    [$n, $b, $r, $v] = [$s['persone']['Neri'], $s['persone']['Bianchi'], $s['persone']['Rossi'], $s['persone']['Verdi']];
    expect([cpmDi($esito, $n, $facciata), cpmDi($esito, $b, $facciata), cpmDi($esito, $r, $facciata), cpmDi($esito, $v, $facciata)])->toBe([500000, 300000, 0, 200000])
        ->and($esito['persone'])->not->toHaveKey($r->id)
        ->and($esito['non_calcolabile'])->toBeNull();
});

it('divide due fatture straordinarie con competenze diverse ognuna al suo titolare, e la delibera vale solo dove la fattura tace', function () {
    $s = cpmViaVerdi('straordinaria');
    $facciata = cpmVoce($s, 'Facciata', [[$s['generali'], 100]]);

    $esito = cpmPorta($s, [
        ['conto_id' => $facciata->id, 'importo' => 100000, 'competenza_dal' => '2026-03-15', 'competenza_al' => '2026-03-15'],
        ['conto_id' => $facciata->id, 'importo' => 100000, 'competenza_dal' => '2026-09-15', 'competenza_al' => '2026-09-15'],
        ['conto_id' => $facciata->id, 'importo' => 50000],
    ], ['data_delibera' => '2026-02-01']);

    // Fattura del 15/03 (€ 1.000,00): U2 a Bianchi, 300,00. Fattura del 15/09 (€ 1.000,00): U2 a Rossi, 300,00. La terza, € 500,00,
    // senza competenza: alla delibera del 01/02, U2 a Bianchi, 150,00. Neri 500,00 + 500,00 + 250,00; Verdi 200,00 + 200,00 + 100,00.
    [$n, $b, $r, $v] = [$s['persone']['Neri'], $s['persone']['Bianchi'], $s['persone']['Rossi'], $s['persone']['Verdi']];
    expect([cpmDi($esito, $n, $facciata), cpmDi($esito, $b, $facciata), cpmDi($esito, $r, $facciata), cpmDi($esito, $v, $facciata)])->toBe([125000, 45000, 30000, 50000])
        ->and(cpmSommaVoce($esito, $facciata))->toBe(250000);
});

it('senza la data della delibera una straordinaria con una spesa senza competenza non si calcola, e lo dice', function () {
    $s = cpmViaVerdi('straordinaria');
    $facciata = cpmVoce($s, 'Facciata', [[$s['generali'], 100]]);

    $senza = cpmPorta($s, [
        ['conto_id' => $facciata->id, 'importo' => 100000, 'competenza_dal' => '2026-03-15', 'competenza_al' => '2026-03-15'],
        ['conto_id' => $facciata->id, 'importo' => 50000],
    ]);
    expect($senza['persone'])->toBe([])
        ->and($senza['non_calcolabile'])->toContain('delibera');

    // Con la competenza scritta su ogni fattura la delibera non serve.
    $dichiarate = cpmPorta($s, [['conto_id' => $facciata->id, 'importo' => 100000, 'competenza_dal' => '2026-09-15', 'competenza_al' => '2026-09-15']]);
    expect($dichiarate['non_calcolabile'])->toBeNull()
        ->and(cpmDi($dichiarate, $s['persone']['Rossi'], $facciata))->toBe(30000);
});

it('tiene le righe di spiegazione solo delle persone chieste, e non scrive nel registro', function () {
    $s = cpmViaVerdi();
    $pulizie = cpmVoce($s, 'Pulizie', [[$s['generali'], 100]]);
    $varie = cpmVoce($s, 'Varie', []);
    Log::spy();

    // Una voce senza tabella: nel preventivo il motore lo scrive nel registro; nella lettura del consuntivo no.
    $esito = cpmPorta($s, [$pulizie->id => 330000, $varie->id => 100], ['righe_per' => [$s['persone']['Rossi']->id]]);

    $righe = collect($esito['righe']);
    expect($righe->pluck('anagrafica_id')->unique()->values()->all())->toBe([$s['persone']['Rossi']->id])
        ->and($righe->sum('importo'))->toBe(49907)
        ->and($righe->first()['giorni_titolarita'])->toBe(184)
        ->and($righe->first()['tabella_nome'])->toBe('Generali');
    Log::shouldNotHaveReceived('info');
    Log::shouldNotHaveReceived('warning');
});

it('dopo la lettura, anche interrotta a metà, il motore è come uno nuovo', function () {
    $s = cpmViaVerdi();
    $scale = cpmTabella($s, 'Scale', ['U1' => 0, 'U2' => 400, 'U3' => 600]);
    $cifre = [cpmVoce($s, 'Pulizie', [[$s['generali'], 100]])->id => 330000, cpmVoce($s, 'Varie', [])->id => 100, cpmVoce($s, 'Scala', [[$scale, 100]])->id => 5000];
    $titolari = new class extends RisolutoreTitolari {
        public int $chiamate = 0;
        public int $fermaDopo = PHP_INT_MAX;

        public function attiviAlla(Collection $anagrafiche, $periodo = null): Collection
        {
            if (++$this->chiamate > $this->fermaDopo) {
                throw new RuntimeException('interrotta');
            }

            return parent::attiviAlla($anagrafiche, $periodo);
        }
    };
    $motore = new CalcoloQuoteService($titolari);
    $nuovo = new CalcoloQuoteService(new RisolutoreTitolari());
    // Lo stato confrontabile: ogni proprietà del motore, tranne gli aiutanti (il risolutore dei titolari e l'unità mista), che sono oggetti suoi.
    $stato = function (CalcoloQuoteService $m): array {
        $out = [];
        foreach ((new ReflectionObject($m))->getProperties() as $proprieta) {
            $valore = $proprieta->getValue($m);
            $out[$proprieta->getName()] = $valore instanceof RisolutoreTitolari || $valore instanceof UnitaMista ? null : $valore;
        }

        return $out;
    };

    $motore->consuntivoPerGestione($s['gestione']->fresh(), $s['esercizio'], $cifre, ['righe_per' => [$s['persone']['Rossi']->id]]);
    expect($stato($motore))->toEqual($stato($nuovo));

    $titolari->fermaDopo = $titolari->chiamate + intdiv($titolari->chiamate, 2);
    expect(fn () => $motore->consuntivoPerGestione($s['gestione']->fresh(), $s['esercizio'], $cifre, ['righe_per' => [$s['persone']['Rossi']->id]]))
        ->toThrow(RuntimeException::class, 'interrotta');
    expect($stato($motore))->toEqual($stato($nuovo))
        ->and(Log::getFacadeRoot())->toBeInstanceOf(\Illuminate\Log\LogManager::class);
});
