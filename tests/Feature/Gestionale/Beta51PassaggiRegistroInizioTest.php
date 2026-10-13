<?php

/**
 * 1.11.0-beta.51 — la cifra dei giorni del conduttore nel registro dell'inizio (decisione 85, punto 4).
 *
 * La regola: l'inizio di una locazione scrive nel registro, per ogni coppia di una fine «Scrivi» sull'unità, la parte del conduttore
 * nella coppia ridivisa con lui e chi aveva preso quei giorni (chiave `giorni_del_conduttore`, anche nel blocco «Rate» del pannello come
 * `rate.giorni_del_conduttore`). È una fotografia valida fino alla fine del conduttore: serve al laboratorio e a chi legge il registro
 * dopo (la fine di Luca: cifra dell'inizio meno la parte rimasta = la parte che torna). Dove l'inizio è su un piano fermo senza una fine
 * «Scrivi» prima (decisione 25) non c'è: neanche il pannello ha una cifra.
 *
 * I CONTI A MANO (centesimi; regola della coppia, resti maggiori):
 *   - DL7: coppia 35552 (24/1–31/12, 342 giorni); Luca dall'8/2 = 327 giorni → pesi [Luca 327, Ugo 15] → 35552 × 327/342 = 33992,70 → 33993;
 *   - INT: coppia 18148 (1/7–31/12); Luca dall'1/11 = 61 giorni → pesi [Ugo 92, Elsa 31, Luca 61] → Luca 6016,46 → 6016;
 *   - Marco dall'1/6 dopo DL7 (vendita il 12/3, fine di Luca il 18/4): 214 giorni → pesi [Luca 69, Ugo 15, Elsa 44, Marco 214] → Marco
 *     22245,99 → 22246 (tre centesimi ai resti più alti: Marco ,99; Elsa ,94; Luca ,77);
 *   - rientro: Ines rientra l'8/2 (stessi giorni di Luca) → 33993, la stessa cifra di `rinnovo_dopo_scrivi`;
 *   - Luca e Dora al 50 % dall'8/2: Luca da solo 33993 (la quota non cambia i pesi di un conduttore solo); con Dora le parti si dividono
 *     16997 e 16996 (163,5 giorni ciascuno).
 *   Concordanza (entro un centesimo: partiDiChiEntra e rientro dividono senza primo, la fine con chi ha la coppia per primo):
 *   33993 − 7173 (la parte di Luca dopo la fine) = 26820 = la cifra della fermata della fine di Luca.
 *
 * Che cosa NON copre: la lettura del registro da parte del laboratorio (DeviazioniDichiarate, dopo il commit); la stampa; MySQL.
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    b51aAccesso($this);
});

// --- O-REG-INIZIO: la cifra nel registro dell'inizio --------------------------------------------------------------------------------------

it('O-REG-INIZIO — DL7: l\'inizio di Luca l\'8/2 scrive nel registro la sua parte della coppia (€ 339,93) con la fine di Ines, Ugo che l\'aveva presa e le chiavi dichiarate', function () {
    // Fonte: 35552 × 327/342 = 33992,70 → 33993 (conto a mano in testa). Oggi il registro dell'inizio non ha la chiave.
    $s = b51aDl7($this);

    $esercizioDellaCoppia = DB::table('saldi')->where('subentro_id', $s['fine_ines']->id)->value('esercizio_id');
    $esercizioDellaCoppia = $esercizioDellaCoppia === null ? null : (int) $esercizioDellaCoppia;
    $registro = b51aRegistro($s['inizio_luca']);
    expect($registro['giorni_del_conduttore'] ?? null)->not->toBeNull('Il registro dell\'inizio di Luca non ha `giorni_del_conduttore`: ' . json_encode(array_keys($registro)));
    expect($registro['giorni_del_conduttore'])->toHaveCount(1);
    $g = $registro['giorni_del_conduttore'][0];
    // Una chiave dichiarata che manca sparisce in silenzio da `perIlRegistro`: ognuna deve esserci.
    foreach (b51aChiaviGiorni() as $chiave) {
        expect(array_key_exists($chiave, $g))->toBeTrue("Il registro dell'inizio di Luca non conserva la chiave «{$chiave}»: " . json_encode(array_keys($g)));
    }
    expect((int) $g['fine_id'])->toBe((int) $s['fine_ines']->id)
        ->and((int) $g['immobile_id'])->toBe((int) $s['unita']->id)
        ->and((int) $g['gestione_id'])->toBe((int) $s['g']->id)
        ->and($g['esercizio_id'] === null ? null : (int) $g['esercizio_id'])->toBe($esercizioDellaCoppia)
        ->and($g['conduttore'])->toBe('Luca')
        ->and((int) $g['conduttore_id'])->toBe((int) $s['persone']['Luca']->id)
        ->and($g['dal'])->toBe('2026-02-08')
        ->and((int) $g['cifra'])->toBe(33993)
        ->and(array_values((array) $g['pagato_da']))->toBe(['Ugo']);
});

it('O-REG-INIZIO — il pannello dell\'inizio di Luca porta la stessa cifra in `rate.giorni_del_conduttore`', function () {
    $s = b51aDl7($this);
    $dalPannello = $s['anteprima_inizio_luca']['rate']['giorni_del_conduttore'] ?? null;

    expect($dalPannello)->not->toBeNull('Il pannello dell\'inizio di Luca non ha rate.giorni_del_conduttore.');
    expect(collect($dalPannello)->map(fn ($g) => [(int) $g['cifra'], $g['conduttore'], $g['dal']])->all())->toBe([[33993, 'Luca', '2026-02-08']])
        // Ciò che il pannello dice è ciò che il registro conserva.
        ->and(collect($dalPannello)->pluck('cifra')->all())->toBe(collect(b51aRegistro($s['inizio_luca'])['giorni_del_conduttore'] ?? [])->pluck('cifra')->all());
});

it('O-REG-INIZIO — INT: l\'inizio di Luca l\'1/11 dopo la vendita scrive € 60,16', function () {
    // Fonte: pesi [Ugo 92, Elsa 31, Luca 61] su 18148 → Luca 6016,46 → 6016.
    $s = b51aInt($this);

    expect(collect(b51aRegistro($s['inizio_luca'])['giorni_del_conduttore'] ?? [])->map(fn ($g) => [(int) $g['cifra'], $g['conduttore'], $g['dal'], array_values((array) $g['pagato_da'])])->all())
        ->toBe([[6016, 'Luca', '2026-11-01', ['Ugo']]]);
});

it('O-REG-INIZIO — Marco dall\'1/6 dopo la fine di Luca: nel registro dell\'inizio € 222,46, chi paga al posto dell\'inquilino è Ugo', function () {
    // Fonte: conto a mano in testa (22246).
    $s = b51aDl7($this);
    b51aVendi($this, $s, 'Ugo', 'Elsa', '2026-03-12');
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    $marco = b51aRegistraInizio($this, $s, 'Marco', '2026-06-01');

    expect(collect(b51aRegistro($marco)['giorni_del_conduttore'] ?? [])->map(fn ($g) => [(int) $g['cifra'], $g['conduttore'], $g['dal']])->all())->toBe([[22246, 'Marco', '2026-06-01']]);
});

it('O-REG-INIZIO — il rientro: la cifra dei giorni di Ines è la stessa di `rinnovo_dopo_scrivi` (un array solo, due chiavi)', function () {
    // Fonte: Ines rientra l'8/2, stessi giorni di Luca nel caso DL7: 33993. La chiave `rinnovo_dopo_scrivi` resta com'è (la leggono i test).
    $s = b51aDl7($this, ['senzaLuca' => true]);
    $rientro = fds50Registra($this, $s, fds50ModuloInizio($s, 'Ines', '2026-02-08'));

    $rinnovo = $rientro['anteprima']['rate']['rinnovo_dopo_scrivi'] ?? [];
    expect(collect($rinnovo)->map(fn ($r) => (int) $r['cifra'])->all())->toBe([33993]);
    $giorni = b51aRegistro($rientro['subentro'])['giorni_del_conduttore'] ?? null;
    expect($giorni)->not->toBeNull('Il registro del rientro non ha `giorni_del_conduttore`.')
        ->and(collect($giorni)->map(fn ($g) => (int) $g['cifra'])->all())->toBe([33993])
        ->and(collect($giorni)->map(fn ($g) => (int) $g['cifra'])->all())->toBe(collect($rinnovo)->map(fn ($r) => (int) $r['cifra'])->all());
});

it('O-REG-INIZIO — la fotografia non si rilegge: l\'arrivo di Dora al 50 % non cambia la cifra di Luca nel registro (€ 339,93), e Dora ha la sua con Luca già presente (€ 169,96)', function () {
    // Fonte: conto a mano in testa. Luca solo (al 50 %) 33993; con Dora 16997 e 16996. Il limite della decisione 85.4: la cifra è la parte
    // com'era alla registrazione; il nome del campo lo dice (`giorni_del_conduttore`, con `dal`).
    $s = b51aDl7($this, ['senzaLuca' => true]);
    $luca = fds50Registra($this, $s, ['quota' => 50] + fds50ModuloInizio($s, 'Luca', '2026-02-08'))['subentro'];
    $prima = collect(b51aRegistro($luca)['giorni_del_conduttore'] ?? [])->map(fn ($g) => (int) $g['cifra'])->all();
    expect($prima)->toBe([33993]);

    $dora = fds50Registra($this, $s, ['quota' => 50] + fds50ModuloInizio($s, 'Dora', '2026-02-08'))['subentro'];
    expect(collect(b51aRegistro($luca->fresh())['giorni_del_conduttore'] ?? [])->map(fn ($g) => (int) $g['cifra'])->all())->toBe([33993])
        ->and(collect(b51aRegistro($dora)['giorni_del_conduttore'] ?? [])->map(fn ($g) => (int) $g['cifra'])->all())->toBe([16996]);
});

it('O-REG-INIZIO — annullato l\'inizio (l\'ultimo passaggio) la cifra non conta più: lo storico lo dà annullato e senza fermate', function () {
    $s = b51aDl7($this, ['senzaLuca' => true]);
    $luca = b51aRegistraInizio($this, $s, 'Luca', '2026-02-08');
    expect(b51aRegistro($luca)['giorni_del_conduttore'] ?? null)->not->toBeNull('Il registro dell\'inizio di Luca non ha `giorni_del_conduttore`.');

    fclAnnulla($this, $s, $luca)->assertStatus(302);
    $voce = fds50Storico($s, $luca);
    expect($voce['annullato'] ?? null)->toBeTrue()
        ->and($voce['fermate'] ?? [])->toBe([]);
});

it('O-REG-INIZIO-CAMBIO — il cambio d\'inquilino in un passo (Luca esce e Marco entra il 18/4) scrive nel registro la cifra dei giorni di Marco, € 268,20, con la fine di Ines: è un inizio di locazione anche lui', function () {
    // Conto a mano (coppia di Ines 35552, 24/1–31/12 = 342 giorni): Luca resta inquilino fino al 17/4 = 69 giorni (8/2–17/4: 21 + 31 + 17), Marco dal 18/4 =
    // 258 giorni (18/4–31/12: 13 + 31 + 30 + 31 + 31 + 30 + 31 + 30 + 31), Ugo i 15 giorni scoperti (24/1–7/2): 69 + 258 + 15 = 342. La coppia ridivisa su quei
    // pesi: Luca 35552 × 69/342 = 7172,6 → 7173, Marco 35552 × 258/342 = 26819,9 → 26820, Ugo 1559; somma 35552. La cifra di Marco è la sua parte, perché
    // prima del cambio non ne aveva (la locazione di Luca, aperta, ne copre 327 giorni): è la stessa della fermata che la fine di Luca scrive
    // in DL7 quando nessun altro entra (€ 268,20).
    $s = b51aDl7($this);
    $cambio = b51aRegistraFine($this, $s, 'Luca', '2026-04-18', 'Marco');

    $registro = b51aRegistro($cambio);
    expect($registro['giorni_del_conduttore'] ?? null)->not->toBeNull('Il registro del cambio non ha `giorni_del_conduttore`: ' . json_encode(array_keys($registro)));
    expect($registro['giorni_del_conduttore'])->toHaveCount(1);
    $g = $registro['giorni_del_conduttore'][0];
    foreach (b51aChiaviGiorni() as $chiave) {
        expect(array_key_exists($chiave, $g))->toBeTrue("Il registro del cambio non conserva la chiave «{$chiave}»: " . json_encode(array_keys($g)));
    }
    expect((int) $g['fine_id'])->toBe((int) $s['fine_ines']->id)
        ->and($g['conduttore'])->toBe('Marco')
        ->and((int) $g['conduttore_id'])->toBe((int) $s['persone']['Marco']->id)
        ->and($g['dal'])->toBe('2026-04-18')
        ->and((int) $g['cifra'])->toBe(26820);
});

it('O-REG-INIZIO-CAMBIO, concordanza — il cambio in un passo e i due passi (fine di Luca, poi inizio di Marco lo stesso giorno) scrivono nel registro lo stesso pagato_da, Ugo che ha la coppia, e il prospetto dice per i giorni di Marco la stessa persona', function (bool $unPasso) {
    // Conto a mano: i giorni dal 18/4 (258) li ha pagati Ugo, che ha la coppia della fine di Ines e li aveva presi tutti: Marco li rimborsa a lui (26820, la stessa
    // cifra nei due casi). Nei due passi la fine di Luca li lascia a Ugo e l'inizio di Marco glieli toglie; in un passo la locazione di Luca, aperta, nelle righe di
    // adesso li copre ancora: «prima» è lo stato dopo la fine di Luca, senza nessuno.
    $s = b51aDl7($this);
    if ($unPasso) {
        $passaggio = b51aRegistraFine($this, $s, 'Luca', '2026-04-18', 'Marco');
    } else {
        b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
        $passaggio = b51aRegistraInizio($this, $s, 'Marco', '2026-04-18');
    }

    $giorni = b51aRegistro($passaggio)['giorni_del_conduttore'] ?? [];
    expect($giorni)->toHaveCount(1);
    expect((int) $giorni[0]['cifra'])->toBe(26820)
        ->and(array_values((array) $giorni[0]['pagato_da']))->toBe(['Ugo']);
    // Il prospetto, nei due casi: le voci di Marco sono pagate da Ugo (26820 in tutto).
    $voceDiMarco = collect(b51aProspettoInBreve($s)['Marco']['voci'])->first(fn (array $v) => $v[3] === 26820);
    expect($voceDiMarco)->not->toBeNull()
        ->and($voceDiMarco[2])->toBe('Ugo');
})->with(['in un passo' => [true], 'in due passi' => [false]]);

it('sentinella — O-REG-INIZIO-CAMBIO: la fine di Luca senza nessuno che entra non scrive la cifra (non c\'è un conduttore), e il cambio senza una fine «Scrivi» prima nemmeno', function () {
    // Fonte: decisione 85.4, «una cifra per chi entra»: senza chi entra non c'è una cifra da conservare.
    $s = b51aDl7($this);
    $fine = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    expect(b51aRegistro($fine))->not->toHaveKey('giorni_del_conduttore');

    $s2 = fclScenario([['Ugo', 'proprietario', 100]], ['importo' => 126475]);
    b51aPersone($s2, ['Marco']);
    fclGenera($s2);
    fclEmetti($this, $s2, '2026-01-07');
    $cambio = b51aRegistraFine($this, $s2, 'Ines', '2026-04-18', 'Marco', ['conguaglio' => Subentro::NON_SCRIVERE_IL_CONGUAGLIO]);
    expect(b51aRegistro($cambio))->not->toHaveKey('giorni_del_conduttore');
});

// --- Dove la cifra non c'è (sentinelle verdi: decisione 25 e 77) --------------------------------------------------------------------------------

it('sentinella — O-REG-INIZIO senza coppia: l\'inizio su un piano fermo senza una fine «Scrivi» prima (decisione 25) non scrive la cifra', function () {
    // Fonte: decisione 25 («neanche il pannello ha una cifra»).
    // L'unità non ha un inquilino in corso (altrimenti l'inizio sovrapporrebbe due inquilini al 100 %).
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => []]);
    b51aPersone($s, ['Luca']);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    $r = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-07-01'));

    expect(b51aRegistro($r['subentro']))->not->toHaveKey('giorni_del_conduttore')
        ->and($r['anteprima']['rate']['giorni_del_conduttore'] ?? [])->toBe([]);
});

it('sentinella — O-REG-INIZIO con «Non scriverlo»: nessuna coppia, nessuna cifra nel registro dell\'inizio', function () {
    // Lo scenario di base ha la fine di Ines con «Scrivi»: qui serve «Non scriverlo», quindi si costruisce da capo.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['importo' => 126475]);
    b51aPersone($s, ['Luca']);
    fclGenera($s);
    fclEmetti($this, $s, '2026-01-07');
    fclRegistra($this, $s, fclModulo($s, null, '2026-01-24') + ['conguaglio' => Subentro::NON_SCRIVERE_IL_CONGUAGLIO]);
    $luca = b51aRegistraInizio($this, $s, 'Luca', '2026-02-08');

    expect(b51aRegistro($luca))->not->toHaveKey('giorni_del_conduttore');
});
