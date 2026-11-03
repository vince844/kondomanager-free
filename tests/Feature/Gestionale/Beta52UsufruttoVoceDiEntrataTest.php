<?php

/**
 * Cosa resta scoperto: il cambio di tipo di una voce con movimenti; l'estinzione, la vendita piena, la locazione e la successione (il
 * blocco delle voci c'è solo alla costituzione e alla riserva); le frasi del pannello sono in `Beta52UsufruttoPannelloFrasiTest.php`, quelle
 * delle voci bloccate e del cancello della riserva in `Beta52UsufruttoEntrateBloccateTest.php`.
 *
 * L'art. 984 c.c. e le voci di entrata: ciò che il testo dei modali e della guida promette, e il pannello del passaggio che sposta
 * un'entrata dal «Proprietario» all'«Usufruttuario».
 *
 * Il motore non cambia. Un'entrata entra nel riparto come importo negativo e segue la stessa catena di una spesa: con «Usufruttuario
 * 100 %» sull'unità in usufrutto va all'usufruttuario (nudo proprietario Elsa, usufruttuario Ugo: spesa € 1.200,00 sul «Proprietario»,
 * affitto € 240,00 → Elsa € 1.200,00 e Ugo − € 240,00, cioè un credito); con «Proprietario 100 %» va al nudo proprietario (Elsa € 960,00,
 * Ugo nulla); senza usufrutto va al proprietario. Le controprove tengono ferme queste cifre, che il testo dichiara.
 *
 * Il giorno per giorno lo fa «Registra passaggio»: dopo la costituzione registrata il 1/5 l'affitto segue l'usufruttuario per i suoi
 * giorni (24000 × 120/365 = 7890 a Ugo, × 245/365 = 16110 a Elsa). Con un usufrutto inserito a mano nella scheda dell'unità vale
 * `RisolutoreTitolari::periodoEffettivo`: la data di fine taglia sempre (l'usufruttuario che finisce il 30/4 riceve i suoi 120 giorni),
 * la data di inizio taglia solo se sulla stessa coppia (unità, ruolo) c'è una riga chiusa il giorno prima o se la riga nasce da un
 * passaggio; altrimenti l'usufruttuario riceve l'affitto per tutto l'anno, come paga le spese per tutto l'anno. Il testo dei modali e
 * della guida lo dice, e tre controprove lo tengono fermo.
 *
 * Il pannello del passaggio elenca anche le voci di entrata fra quelle che passano all'«Usufruttuario»: `voci[].tipo` le distingue,
 * l'impronta delle voci porta il tipo (un cambio di tipo fra il pannello e il clic ferma la registrazione), il registro dei passaggi
 * scrive `tipo` solo per un'entrata, lo storico e l'annullamento la nominano.
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52UsufruttoScenari.php';

beforeEach(function () {
    b51aAccesso($this);
});

// --- Controprove: ciò che il testo promette, e il motore fa già -------------------------------------------------------------------

it('controprova — su un\'unità con un nudo proprietario e un usufruttuario decide la voce: «Usufruttuario 100 %» dà l\'affitto all\'usufruttuario, «Proprietario 100 %» al nudo proprietario', function () {
    $nudaEUsufrutto = [['Elsa', 'nuda_proprietario', 100, '2019-01-01', null], ['Ugo', 'usufruttuario', 100, '2019-01-01', null]];

    // Spesa € 1.200,00 sul «Proprietario», affitto € 240,00.
    expect(b52UsMisura($nudaEUsufrutto, ['proprietario' => 100], ['proprietario' => 100]))->toBe(['Elsa' => 96000])
        ->and(b52UsMisura($nudaEUsufrutto, ['proprietario' => 100], ['usufruttuario' => 100]))->toBe(['Elsa' => 120000, 'Ugo' => -24000])
        ->and(b52UsMisura($nudaEUsufrutto, ['usufruttuario' => 100], ['usufruttuario' => 100]))->toBe(['Ugo' => 96000]);
});

it('controprova — senza usufrutto «Usufruttuario 100 %» va al proprietario; sull\'unità in parte in piena proprietà il proprietario pieno tiene la sua metà', function () {
    expect(b52UsMisura([['Ugo', 'proprietario', 100, '2019-01-01', null]], ['proprietario' => 100], ['usufruttuario' => 100]))->toBe(['Ugo' => 96000]);

    $mista = [['Bice', 'proprietario', 50, '2019-01-01', null], ['Ugo', 'usufruttuario', 50, '2019-01-01', null], ['Elsa', 'nuda_proprietario', 50, '2019-01-01', null]];
    expect(b52UsMisura($mista, ['proprietario' => 100], ['usufruttuario' => 100]))->toBe(['Bice' => 48000, 'Elsa' => 60000, 'Ugo' => -12000]);
});

it('controprova — un nudo proprietario registrato come «proprietario» (dato di prima del ruolo «Nudo proprietario») dà le stesse cifre', function () {
    $vecchio = [['Elsa', 'proprietario', 100, '2019-01-01', null], ['Ugo', 'usufruttuario', 100, '2019-01-01', null]];

    expect(b52UsMisura($vecchio, ['proprietario' => 100], ['usufruttuario' => 100]))->toBe(['Elsa' => 120000, 'Ugo' => -24000])
        ->and(b52UsMisura($vecchio, ['proprietario' => 100], ['proprietario' => 100]))->toBe(['Elsa' => 96000]);
});

it('controprova — un usufrutto inserito a mano con una data di inizio a metà anno non divide l\'affitto giorno per giorno: l\'usufruttuario lo riceve per tutto l\'anno', function () {
    // Ugo proprietario fino al 30/4, poi usufruttuario; Elsa nuda proprietaria dal 1/5. Spesa sul «Proprietario», affitto sull'«Usufruttuario».
    $aMano = [['Ugo', 'proprietario', 100, '2019-01-01', '2026-04-30'], ['Ugo', 'usufruttuario', 100, '2026-05-01', null], ['Elsa', 'nuda_proprietario', 100, '2026-05-01', null]];

    // Ugo: la spesa dei suoi 120 giorni da proprietario (39452) meno l'affitto di tutto l'anno (24000), non dei soli 245 giorni.
    expect(b52UsMisura($aMano, ['proprietario' => 100], ['usufruttuario' => 100]))->toBe(['Elsa' => 80548, 'Ugo' => 15452]);
});

it('controprova — con una riga di usufrutto chiusa il giorno prima la data di inizio a metà anno conta: l\'affitto si divide giorno per giorno fra i due usufruttuari', function () {
    // Marco usufruttuario fino al 30/4, Ugo dal 1/5, Elsa nuda proprietaria da sempre. Spesa sul «Proprietario», affitto sull'«Usufruttuario».
    $conPredecessore = [['Elsa', 'nuda_proprietario', 100, '2019-01-01', null], ['Marco', 'usufruttuario', 100, '2019-01-01', '2026-04-30'], ['Ugo', 'usufruttuario', 100, '2026-05-01', null]];

    // 24000 × 120/365 = 7890 a Marco, 24000 × 245/365 = 16110 a Ugo, due crediti; la spesa va tutta a Elsa.
    expect(b52UsMisura($conPredecessore, ['proprietario' => 100], ['usufruttuario' => 100]))->toBe(['Elsa' => 120000, 'Marco' => -7890, 'Ugo' => -16110]);
});

it('controprova — un usufrutto inserito a mano che finisce a metà anno divide l\'affitto giorno per giorno: la data di fine taglia sempre', function () {
    // Elsa nuda proprietaria da sempre, Ugo usufruttuario dal 1/1/2019 al 30/4/2026, nessuno dopo. Spesa sul «Proprietario», affitto sull'«Usufruttuario».
    $finisce = [['Elsa', 'nuda_proprietario', 100, '2019-01-01', null], ['Ugo', 'usufruttuario', 100, '2019-01-01', '2026-04-30']];

    // 24000 × 120/365 = 7890 a Ugo, i giorni da usufruttuario; Elsa paga la spesa (120000) meno i 245 giorni dell'affitto dopo la fine (16110).
    expect(b52UsMisura($finisce, ['proprietario' => 100], ['usufruttuario' => 100]))->toBe(['Elsa' => 103890, 'Ugo' => -7890]);
});

it('controprova — dalla costituzione registrata il 1/5 l\'affitto sull\'«Usufruttuario» segue l\'usufruttuario giorno per giorno: Ugo € 78,90 dei suoi 120 giorni, Elsa € 161,10 dei suoi 245', function () {
    $s = fds50Scenario([['Ugo', 'proprietario', 100]]);
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('tipologia', 'inquilino')->delete();
    $ctmSpese = (int) DB::table('conto_tabella_millesimale')->where('conto_id', $s['conto']->id)->value('id');
    DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $ctmSpese)->delete();
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctmSpese, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $ctmE = b52UsVoce($s, 'Affitto antenna', 'entrata', 24000, ['proprietario' => 100]);
    $s['piano']->capitoli()->syncWithoutDetaching([$s['conto']->id => ['importo' => 120000], (int) DB::table('conto_tabella_millesimale')->where('id', $ctmE)->value('conto_id') => ['importo' => 24000]]);
    $s['piano']->update(['stato' => 'bozza']);

    fds50Registra($this, $s, fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-05-01'));
    fds50Genera($s);

    $righe = DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'riparto')->where('importo', '<', 0)->orderBy('id')->get(['anagrafica_id', 'ruolo_risolto', 'importo']);
    expect($righe->map(fn ($r) => [(int) $r->anagrafica_id, $r->ruolo_risolto, (int) $r->importo])->all())
        ->toBe([[(int) $s['persone']['Ugo']->id, 'proprietario', -7890], [(int) $s['persone']['Elsa']->id, 'usufruttuario', -16110]])
        ->and(b52UsPerPersona($s))->toBe(['Elsa' => 64438, 'Ugo' => 31562]);
});

// --- Il pannello del passaggio: il tipo di ogni voce --------------------------------------------------------------------------------

it('il pannello dice di ogni voce se è una spesa o un\'entrata', function (string $passaggio) {
    $s = b52UsScenarioPannello();
    $dati = $passaggio === 'riserva' ? ruRiserva($s) : b52UsCostituzione($s);
    $ordinaria = ruAnteprima($this, $s, $dati)['ordinaria'];

    expect(collect($ordinaria['voci'])->mapWithKeys(fn ($v) => [$v['conto'] => $v['tipo'] ?? null])->all())
        ->toBe(['Affitto antenna' => 'entrata', 'Spese generali' => 'spesa']);
})->with(['riserva', 'costituzione']);

it('con le sole spese il pannello dice che la voce è una spesa e non cita l\'art. 984 c.c.', function () {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $ordinaria = ruAnteprima($this, $s, ruRiserva($s))['ordinaria'];
    $testo = implode("\n", $ordinaria['frasi']);

    expect(collect($ordinaria['voci'])->pluck('tipo')->all())->toBe(['spesa'])
        ->and(str_contains($testo, '984'))->toBeFalse($testo)
        ->and(str_contains($testo, 'entrata'))->toBeFalse($testo);
});

it('un\'entrata compresa in un piano approvato è bloccata come una spesa: si elenca come entrata, non si sposta', function () {
    $s = b52UsScenarioPannello();
    $s['piano']->update(['stato' => 'approvato']);
    $s['piano']->capitoli()->syncWithoutDetaching([(int) DB::table('conto_tabella_millesimale')->where('id', $s['ctmEntrata'])->value('conto_id') => ['importo' => 24000]]);
    $ordinaria = ruAnteprima($this, $s, ruRiserva($s))['ordinaria'];
    $entrata = collect($ordinaria['voci'])->firstWhere('conto', 'Affitto antenna');

    expect($entrata['bloccata'])->toBeTrue()
        ->and($entrata['spostata'])->toBeFalse()
        ->and($entrata['tipo'] ?? null)->toBe('entrata');
});

// --- L'impronta, il registro, lo storico, l'annullamento ---------------------------------------------------------------------------

it('l\'impronta delle voci cambia se una voce cambia tipo fra il pannello e il clic: la registrazione si ferma, con il rifiuto di sempre', function () {
    $s = b52UsScenarioPannello();
    $anteprima = ruAnteprima($this, $s, ruRiserva($s))['ordinaria'];
    DB::table('conti')->where('nome', 'Affitto antenna')->update(['tipo' => 'spesa']);

    $r = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), ruRiserva($s, extra: ['ordinaria_impronta' => $anteprima['impronta']]));

    expect($r->status())->toBe(422)
        ->and(array_keys($r->json('errors') ?? []))->toContain('ordinaria_impronta')
        ->and($r->json('errors.ordinaria_impronta.0'))->toContain('Le voci da spostare sono cambiate da quando il pannello le ha mostrate')
        ->and(DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $s['ctmEntrata'])->pluck('soggetto')->all())->toBe(['proprietario']);
});

it('controprova — con lo stesso elenco di voci l\'impronta del pannello vale: la registrazione passa e sposta l\'entrata', function () {
    $s = b52UsScenarioPannello();
    $anteprima = ruAnteprima($this, $s, ruRiserva($s))['ordinaria'];

    $r = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), ruRiserva($s, extra: ['ordinaria_impronta' => $anteprima['impronta']]));

    expect($r->status())->toBeLessThan(400)
        ->and(DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $s['ctmEntrata'])->pluck('soggetto')->all())->toBe(['usufruttuario']);
});

it('il registro del passaggio scrive `tipo` = «entrata» per l\'entrata che si sposta e nessun tipo per la spesa', function () {
    $s = b52UsScenarioPannello();
    $subentro = ruRegistra($this, $s, ruRiserva($s));

    expect(collect($subentro->registro['voci_spostate'])->mapWithKeys(fn ($v) => [$v['conto'] => $v['tipo'] ?? null])->all())->toBe(['Affitto antenna' => 'entrata', 'Spese generali' => null]);
});

it('lo storico dell\'unità nomina l\'entrata spostata come entrata e la spesa come prima', function () {
    $s = b52UsScenarioPannello();
    $subentro = ruRegistra($this, $s, ruRiserva($s));

    // Le percentuali sono scritte con lo spazio che non va a capo prima del «%».
    $testo = str_replace("\u{00A0}", ' ', implode("\n", fds50Testi(fds50Storico($s, $subentro))));
    expect(str_contains($testo, 'Affitto antenna (Proprietà, Ordinaria 2026, entrata): prima Proprietario 100 %, dopo Usufruttuario 100 %'))->toBeTrue($testo)
        ->and(str_contains($testo, 'Spese generali (Proprietà, Ordinaria 2026): prima Proprietario 100 %, dopo Usufruttuario 100 %'))->toBeTrue($testo);
});

it('l\'avviso dell\'annullamento nomina l\'entrata spostata come entrata e la spesa come prima', function () {
    $s = b52UsScenarioPannello();
    $subentro = ruRegistra($this, $s, ruRiserva($s));

    $avvisi = str_replace("\u{00A0}", ' ', implode("\n", fds50Storico($s, $subentro)['annullabile']['avvisi'] ?? []));
    expect(str_contains($avvisi, 'Affitto antenna (Proprietà, Ordinaria 2026, entrata; prima Proprietario 100 %)'))->toBeTrue($avvisi)
        ->and(str_contains($avvisi, 'Spese generali (Proprietà, Ordinaria 2026; prima Proprietario 100 %)'))->toBeTrue($avvisi);
});

it('controprova — un passaggio registrato prima, senza `tipo` nel registro, si legge come prima: lo storico non mette l\'etichetta', function () {
    $s = b52UsScenarioPannello();
    $subentro = ruRegistra($this, $s, ruRiserva($s));
    $registro = $subentro->registro;
    $registro['voci_spostate'] = array_map(function (array $v) {
        unset($v['tipo']);

        return $v;
    }, $registro['voci_spostate']);
    $subentro->forceFill(['registro' => $registro])->save();

    $storico = fds50Storico($s, $subentro->fresh());
    $testo = str_replace("\u{00A0}", ' ', implode("\n", fds50Testi($storico)));

    expect(str_contains($testo, 'Affitto antenna (Proprietà, Ordinaria 2026): prima Proprietario 100 %'))->toBeTrue($testo)
        ->and(str_contains($testo, ', entrata'))->toBeFalse($testo);
});

it('controprova — con la spunta tolta l\'entrata resta sul «Proprietario» e il registro non la nomina', function () {
    $s = b52UsScenarioPannello();
    $subentro = ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctmEntrata']]]));

    expect(collect($subentro->registro['voci_spostate'])->pluck('conto')->all())->toBe(['Spese generali'])
        ->and(DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $s['ctmEntrata'])->pluck('soggetto')->all())->toBe(['proprietario']);
});

it('controprova — il registro di una spesa resta com\'è: nessuna chiave in più oltre a quelle di prima', function () {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    $subentro = ruRegistra($this, $s, ruRiserva($s));

    expect(array_keys($subentro->registro['voci_spostate'][0]))->toBe(['id', 'conto', 'tabella', 'gestione', 'prima', 'dopo']);
});
