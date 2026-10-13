<?php

/**
 * 1.11.0-beta.51, Coda 172 — la nota di solidarietà al netto della coppia (decisione 85.2).
 *
 * La cifra «non pagati» della nota è il residuo emesso di chi esce meno, gestione per gestione e mai sotto zero, ciò che la coppia
 * del conguaglio ha già messo a debito di chi compra e che è ancora nel Wallet; la coppia sta nella stessa frase («…€ 1.200,00 nelle
 * rate, di cui € 805,48 già a debito di Elsa con il conguaglio del 1 maggio 2026»; se il netto è zero «tutte già a debito di»).
 * `residuo_uscente_cents` diventa il netto (stesso nome); si aggiungono `residuo_lordo_cents` e `coppia_cents`. La nota legge il
 * fatto scritto (le righe di `saldi` con il `subentro_id` del padre), non rifà la divisione.
 * Fonte dell'atteso: decisione 85 (2), decisione 30.11 (il credito di una gestione non copre il debito di un'altra), e il conto a
 * mano scritto in ogni test (oracoli O-172-1 … O-172-18).
 *
 * La coppia assorbita da un piano (O-172-12 e O-172-12b) segue la decisione 86.3: nella .51 la nota torna alla cifra lorda, che non
 * sottostima mai il debito; la cifra al netto del solo credito non ancora usato è della 1.11.1.
 *
 * Cosa NON copre, per scelta: la catena di due vendite (O-172-16), che non ha un atteso; il testo
 * del PDF (si controlla il tipo e che la rotta risponda, il testo è nella pagina e nella situazione debitoria).
 *
 * I test che cominciano con «guardia —» proteggono ciò che la nota non deve rompere.
 */

use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Models\User;
use App\Services\Subentro\NotaSolidarieta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Beta51PianoRateScenario.php';
require_once __DIR__.'/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

// --- Gli scenari ------------------------------------------------------------------------------------------------------------

/** La vendita piena del 1/5 di Venditore Ugo ad Acquirente Elsa, con il conguaglio scritto (nessuna rinuncia). */
function b51nVendita(array $s, array $extra = []): array
{
    return $extra + [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'rep. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Vendita piena, rogito letto',
    ];
}

/** Ugo ha pagato `$cents`, in ordine di rata, sulle sue quote del piano. */
function b51nPaga(array $s, int $cents): void
{
    $resta = $cents;
    foreach (DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->where('rate_quote.anagrafica_id', $s['v']->id)
        ->orderBy('rate.numero_rata')->get(['rate_quote.id', 'rate_quote.importo']) as $q) {
        $pagato = min($resta, (int) $q->importo);
        DB::table('rate_quote')->where('id', $q->id)->update(['importo_pagato' => $pagato]);
        $resta -= $pagato;
    }
}

/** La nota di Elsa dopo la vendita: l'ordinaria da € 1.200,00 emessa fino al giorno dato, `$pagato` già versato da Ugo. */
function b51nNotaOrdinaria($test, string $emessaFino = '2026-12-31', int $pagato = 0, int $saldoUgo = 0): array
{
    $s = ruScenario('prima_rata', $saldoUgo);
    ruEmetti($s, $emessaFino);
    if ($pagato > 0) {
        b51nPaga($s, $pagato);
    }
    ruRegistra($test, $s, b51nVendita($s));

    return [$s, app(NotaSolidarieta::class)->per($s['c'], $s['a'])[0]];
}

/** La frase di solidarietà con la cifra: «a nome di Venditore Ugo risultano oggi € 394,52 non pagati su questa unità (…)». */
function b51nFrase(string $netto, string $dettaglio): string
{
    return 'a nome di Venditore Ugo risultano oggi ' . $netto . ' non pagati su questa unità (' . $dettaglio . ' già a debito di Acquirente Elsa con il conguaglio del 1 maggio 2026). Chi paga in forza della solidarietà';
}

// --- O-172-1 … O-172-5: la vendita piena con l'ordinaria -------------------------------------------------------------------

it('O-172-1 — vendita piena, ordinaria emessa tutta, niente pagato: la nota dice € 394,52, la parte di Ugo, e nella stessa frase che € 805,48 sono già a debito di Elsa', function () {
    [$s, $nota] = b51nNotaOrdinaria($this);

    // Conto a mano: Elsa 245/365 → 120000 × 245/365 = 80547,95 → 80548 (la coppia: nessuna bozza passa, tutte emesse). Residuo lordo di Ugo 120000.
    // Netto 120000 − 80548 = 39452 = 120/365 di 120000, la parte di Ugo per giorni.
    expect(ruCoppia($s))->toBe([80548, -80548]);
    expect($nota['residuo_uscente_cents'])->toBe(39452)
        ->and($nota['residuo_lordo_cents'] ?? null)->toBe(120000)
        ->and($nota['coppia_cents'] ?? null)->toBe(80548)
        ->and($nota['residuo_uscente_formattato'])->toBe('€ 394,52')
        ->and($nota['testo'])->toContain(b51nFrase('€ 394,52', '€ 1.200,00 nelle rate, di cui € 805,48'));
});

it('O-172-2 — ordinaria emessa fino al 30/6: le sei rate di luglio-dicembre passano a Elsa, la coppia è € 205,48 e il netto è ancora la parte di Ugo, € 394,52', function () {
    [$s, $nota] = b51nNotaOrdinaria($this, '2026-06-30');

    // Conto a mano: Elsa totale 80548; le sei rate in bozza (60000) passano a lei; coppia 80548 − 60000 = 20548. Residuo lordo di Ugo 60000; netto 60000 − 20548 = 39452.
    expect(ruCoppia($s))->toBe([20548, -20548]);
    expect($nota['residuo_uscente_cents'])->toBe(39452)->and($nota['residuo_lordo_cents'] ?? null)->toBe(60000)->and($nota['coppia_cents'] ?? null)->toBe(20548)
        ->and($nota['testo'])->toContain(b51nFrase('€ 394,52', '€ 600,00 nelle rate, di cui € 205,48'));
});

it('O-172-3 — straordinaria deliberata dopo l\'atto (20/5): è tutta di Elsa, le due rate emesse a Ugo (€ 400,00) sono già sue con la coppia, e la nota dice che non resta nulla da pagare', function () {
    $s = ruScenario('prima_rata', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6);
    ruEmetti($s, '2026-07-31');
    ruRegistra($this, $s, b51nVendita($s));
    $nota = app(NotaSolidarieta::class)->per($s['c'], $s['a'])[0];

    // Conto a mano: delibera dopo l'atto → tutta di Elsa (120000); le quattro rate in bozza (80000) passano a lei; coppia = 120000 − 80000 = 40000 = le due rate emesse a Ugo.
    // Residuo lordo di Ugo 40000, netto 0.
    expect(ruCoppia($s))->toBe([40000, -40000]);
    expect($nota['residuo_uscente_cents'])->toBe(0)->and($nota['residuo_lordo_cents'] ?? null)->toBe(40000)->and($nota['coppia_cents'] ?? null)->toBe(40000)
        ->and($nota['testo'])->toContain('a nome di Venditore Ugo non risulta oggi nulla di non pagato su questa unità (€ 400,00 nelle rate, tutte già a debito di Acquirente Elsa con il conguaglio del 1 maggio 2026)');
});

it('O-172-4 — controprova di «testi T4»: con otto rate emesse a Ugo il residuo è € 394,52, non € 800,00, e la frase ha la parentesi', function () {
    [$s, $nota] = b51nNotaOrdinaria($this, '2026-08-31');

    // Conto a mano: Elsa 80548; le quattro rate in bozza (40000) passano a lei; coppia 80548 − 40000 = 40548. Lordo di Ugo 80000, netto 80000 − 40548 = 39452.
    expect(ruCoppia($s))->toBe([40548, -40548]);
    expect($nota['residuo_uscente_cents'])->toBe(39452)->and($nota['residuo_lordo_cents'] ?? null)->toBe(80000)->and($nota['coppia_cents'] ?? null)->toBe(40548)
        ->and($nota['testo'])->toContain(b51nFrase('€ 394,52', '€ 800,00 nelle rate, di cui € 405,48'));
});

it('O-172-5 — Ugo ha già pagato € 300,00: il netto scende di quanto ha pagato, € 94,52, e la frase dice € 900,00 nelle rate', function () {
    [$s, $nota] = b51nNotaOrdinaria($this, '2026-12-31', 30000);

    // Conto a mano: lordo non pagato 120000 − 30000 = 90000; coppia 80548; netto 9452 = 39452 (parte di Ugo) − 30000 (pagato).
    expect($nota['residuo_uscente_cents'])->toBe(9452)->and($nota['residuo_lordo_cents'] ?? null)->toBe(90000)->and($nota['coppia_cents'] ?? null)->toBe(80548)
        ->and($nota['testo'])->toContain(b51nFrase('€ 94,52', '€ 900,00 nelle rate, di cui € 805,48'));
});

it('O-172-6 — Ugo ha già pagato € 700,00, più della sua parte: il lordo (€ 500,00) è tutto coperto dalla coppia, il netto è zero e la frase dice «tutte già a debito di»', function () {
    [$s, $nota] = b51nNotaOrdinaria($this, '2026-12-31', 70000);

    // Conto a mano: lordo non pagato 120000 − 70000 = 50000; la coppia (80548) è più grande: coperto = min(50000, 80548) = 50000; netto 0.
    // Ugo ha pagato 70000 − 39452 = 30548 in più della sua parte.
    expect($nota['residuo_uscente_cents'])->toBe(0)->and($nota['residuo_lordo_cents'] ?? null)->toBe(50000)->and($nota['coppia_cents'] ?? null)->toBe(80548)
        ->and($nota['testo'])->toContain('a nome di Venditore Ugo non risulta oggi nulla di non pagato su questa unità (€ 500,00 nelle rate, tutte già a debito di Acquirente Elsa con il conguaglio del 1 maggio 2026)');
});

it('guardia — O-172-7: Ugo ha pagato tutto, il lordo è zero e la nota non ha nessuna parentesi: «non risulta oggi nulla di non pagato su questa unità.»', function () {
    [$s, $nota] = b51nNotaOrdinaria($this, '2026-12-31', 120000);

    expect($nota['residuo_uscente_cents'])->toBe(0)
        ->and($nota['testo'])->toContain('a nome di Venditore Ugo non risulta oggi nulla di non pagato su questa unità. Chi paga in forza della solidarietà')
        ->and($nota['testo'])->not->toContain('già a debito di');
});

// --- O-172-17, O-172-18: il pregresso di chi esce e il netto costante -------------------------------------------------------

it('O-172-17 — Ugo ha un pregresso di € 500,00 nella prima rata: il pregresso non passa a chi compra, il netto è la sua parte per giorni più il pregresso, € 894,52', function () {
    [$s, $nota] = b51nNotaOrdinaria($this, '2026-12-31', 0, 50000);

    // Conto a mano: lordo non pagato 120000 + 50000 = 170000; la coppia è solo del preventivo (80548: il pregresso resta a Ugo, decisione 25).
    // Netto 170000 − 80548 = 89452 = 39452 + 50000.
    expect(ruCoppia($s))->toBe([80548, -80548]);
    expect($nota['residuo_uscente_cents'])->toBe(89452)->and($nota['residuo_lordo_cents'] ?? null)->toBe(170000)->and($nota['coppia_cents'] ?? null)->toBe(80548)
        ->and($nota['testo'])->toContain(b51nFrase('€ 894,52', '€ 1.700,00 nelle rate, di cui € 805,48'));
});

it('O-172-18 (DT5) — il netto non cambia con le rate emesse: con 6, 8 o 12 rate e niente pagato resta la parte di Ugo per giorni, 120/365 di € 1.200,00 = € 394,52', function (string $emessaFino, int $lordo, int $coppia) {
    [$s, $nota] = b51nNotaOrdinaria($this, $emessaFino);

    // Conto a mano: 12 rate: 120000 − 80548; 8 rate: 80000 − 40548; 6 rate: 60000 − 20548. Sempre 39452.
    expect($nota['residuo_uscente_cents'])->toBe(39452)->and($nota['residuo_lordo_cents'] ?? null)->toBe($lordo)->and($nota['coppia_cents'] ?? null)->toBe($coppia)
        ->and(($nota['residuo_lordo_cents'] ?? 0) - ($nota['coppia_cents'] ?? 0))->toBe(39452);
})->with([
    'sei rate emesse' => ['2026-06-30', 60000, 20548],
    'otto rate emesse' => ['2026-08-31', 80000, 40548],
    'dodici rate emesse' => ['2026-12-31', 120000, 80548],
]);

// --- O-172-9: la riserva d'usufrutto ----------------------------------------------------------------------------------------

it('O-172-9 — riserva d\'usufrutto con la straordinaria deliberata il 20/5, emesse due rate: la coppia toglie dalle certe (€ 400,00, tutte già a debito di Elsa) e il punto aperto resta zero, invariato', function () {
    $s = ruScenario('prima_rata', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6);
    ruEmetti($s, '2026-07-31');
    ruRegistra($this, $s, ruRiserva($s));
    $nota = app(NotaSolidarieta::class)->per($s['c'], $s['a'])[0];

    // Conto a mano: la coppia è 40000 (Elsa a debito); le certe (delibera dopo l'atto) valgono 40000 lorde, 0 nette; il punto aperto (art. 63 co. 4) è 0.
    expect(ruCoppia($s))->toBe([40000, -40000]);
    expect($nota['residuo_uscente_cents'])->toBe(0)
        ->and($nota['testo'])->toContain('a nome di Venditore Ugo non ne risulta oggi nulla di non pagato, per contributi sorti da quel giorno in poi (€ 400,00 nelle rate, tutte già a debito di Acquirente Elsa con il conguaglio del 1 maggio 2026)')
        // La frase del punto aperto è quella di sempre.
        ->and($nota['testo'])->toContain('a nome di Venditore Ugo non risulta oggi nulla di non pagato su questa unità per contributi sorti prima del 1 maggio 2026.');
});

// --- O-172-10: il caso S7 di RegistraSubentroTest ---------------------------------------------------------------------------

it('O-172-10 — una quota emessa di € 412,00 e la vendita del 1/5: la nota dice € 135,45, la parte di chi esce per i suoi 120 giorni, con € 276,55 già a debito di chi compra', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 41200, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', numeroRate: 1)->titolare('A', 'ugo');
    $s->persona('elsa');
    $s->genera('Preventivo 2026');
    $s->emetti('Preventivo 2026', '2026-01-31')->assertSessionHasNoErrors();
    $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');

    // Conto a mano: Ugo 120/365 di 41200 = 13545,2 → 13545; la coppia è 41200 − 13545 = 27655. Residuo lordo 41200.
    $nota = app(NotaSolidarieta::class)->per($s->c, $s->persona('elsa'))[0];
    expect($nota['residuo_uscente_cents'])->toBe(13545)->and($nota['residuo_lordo_cents'] ?? null)->toBe(41200)->and($nota['coppia_cents'] ?? null)->toBe(27655)
        ->and($nota['testo'])->toContain('a nome di Ugo risultano oggi € 135,45 non pagati su questa unità (€ 412,00 nelle rate, di cui € 276,55 già a debito di Elsa con il conguaglio del 1 maggio 2026)');
});

// --- O-172-11, O-172-14: dove la coppia non c'è -----------------------------------------------------------------------------

it('guardia — O-172-11: con la rinuncia al conguaglio non c\'è nessuna coppia e la nota resta lorda, € 1.200,00, senza parentesi', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    ruRegistra($this, $s, b51nVendita($s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro il conguaglio nel rogito']));
    $nota = app(NotaSolidarieta::class)->per($s['c'], $s['a'])[0];

    // Verso il condominio la posizione di Ugo non è cambiata, e la nota non dice che le parti hanno regolato fuori.
    expect(Saldo::whereNotNull('subentro_id')->count())->toBe(0)
        ->and($nota['residuo_uscente_cents'])->toBe(120000)
        ->and($nota['testo'])->toContain('a nome di Venditore Ugo risultano oggi € 1.200,00 non pagati su questa unità. Chi paga in forza della solidarietà')
        ->and($nota['testo'])->not->toContain('già a debito di');
});

it('guardia — O-172-11, conguaglio annullato: tolta la coppia dallo storico la nota torna lorda, € 1.200,00, senza parentesi', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    $passaggio = ruRegistra($this, $s, b51nVendita($s));
    expect(Saldo::whereNotNull('subentro_id')->count())->toBe(2);

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $passaggio]), ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro'])->assertRedirect();
    $nota = app(NotaSolidarieta::class)->per($s['c'], $s['a'])[0];

    expect(Saldo::whereNotNull('subentro_id')->count())->toBe(0)
        ->and($nota['residuo_uscente_cents'])->toBe(120000)
        ->and($nota['testo'])->toContain('a nome di Venditore Ugo risultano oggi € 1.200,00 non pagati su questa unità. Chi paga')
        ->and($nota['testo'])->not->toContain('già a debito di');
});

it('guardia — O-172-14: una coppia a segno rovesciato (Elsa a credito, Ugo a debito: l\'entrata nei suoi giorni supera la spesa) non è un debito di chi compra da togliere: cifra lorda, nessuna parentesi', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    $passaggio = ruRegistra($this, $s, b51nVendita($s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro il conguaglio nel rogito']));
    // Il segno rovesciato si costruisce a mano (nessuna voce di entrata dal modulo): le righe della coppia, scritte dal passaggio, con Elsa a credito.
    foreach ([[$s['a']->id, -5000], [$s['v']->id, 5000]] as [$persona, $importo]) {
        Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $persona, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id,
            'saldo_iniziale' => $importo, 'origine' => 'automatico', 'is_applicato' => false, 'subentro_id' => $passaggio->id]);
    }
    $nota = app(NotaSolidarieta::class)->per($s['c'], $s['a'])[0];

    expect($nota['residuo_uscente_cents'])->toBe(120000)
        ->and($nota['testo'])->toContain('a nome di Venditore Ugo risultano oggi € 1.200,00 non pagati su questa unità. Chi paga')
        ->and($nota['testo'])->not->toContain('già a debito di');
});

// --- O-172-8: due gestioni --------------------------------------------------------------------------------------------------

it('guardia — O-172-8: due gestioni, il credito di una non copre il debito dell\'altra (decisione 30.11): € 1.000,00 della straordinaria, non € 194,52', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026')
        ->gestione('Straordinaria', 'straordinaria')->voce('Straordinaria', 'Lavori', 100000, ['Proprietà' => 100])
        ->pianoStraordinario('Lavori 2026', 'Straordinaria', ['Lavori' => 100000], '2026-02-10', 4, '2026-03-05')->titolare('A', 'ugo');
    $s->persona('elsa');
    $s->genera('Preventivo 2026');
    $s->genera('Lavori 2026');
    $s->emetti('Preventivo 2026', '2026-12-31')->assertSessionHasNoErrors();
    $s->emetti('Lavori 2026', '2026-12-31')->assertSessionHasNoErrors();
    // Ugo ha pagato tutta l'ordinaria; la straordinaria, deliberata prima dell'atto, è emessa e non pagata.
    DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s->piani['Preventivo 2026']['p']->id)
        ->where('rate_quote.anagrafica_id', $s->persona('ugo')->id)->update(['rate_quote.importo_pagato' => DB::raw('rate_quote.importo')]);
    $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');

    // Conto a mano: la coppia è solo sull'ordinaria (80548 a debito di Elsa); la straordinaria è tutta di Ugo, coppia 0. Per gestione:
    // ordinaria max(0, 0 − 80548) = 0; straordinaria 100000 − 0. Sul totale sarebbe 100000 − 80548 = 19452, errato.
    $nota = app(NotaSolidarieta::class)->per($s->c, $s->persona('elsa'))[0];
    expect($nota['residuo_uscente_cents'])->toBe(100000)
        ->and($nota['testo'])->toContain('a nome di Ugo risultano oggi € 1.000,00 non pagati su questa unità.')
        ->and($nota['testo'])->not->toContain('€ 194,52')
        ->and($nota['testo'])->not->toContain('già a debito di');
});

// --- O-172-13: il box -------------------------------------------------------------------------------------------------------

it('O-172-13 — vendita con il box: due note, una per unità, ciascuna con la coppia della propria unità (€ 724,93 sull\'appartamento, € 80,55 sul box) e nessuna cifra mescolata', function () {
    $s = Beta51Scenario::nuovo($this)->pertinenza('BX', 'A')->tabella('Proprietà', ['A' => 900, 'BX' => 100])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026')
        ->titolare('A', 'ugo')->titolare('BX', 'ugo');
    $s->persona('elsa');
    $s->genera('Preventivo 2026');
    $s->emetti('Preventivo 2026', '2026-12-31')->assertSessionHasNoErrors();
    $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01', ['pertinenze' => ['BX']]);

    // Conto a mano: BX totale 12000: Elsa 245/365 = 8054,8 → 8055 (la coppia del box), Ugo 120/365 = 3945,2 → 3945. A totale 108000: Elsa 108000 × 245/365 = 72493,15 → 72493,
    // Ugo 35507. Le coppie per unità sommano 80548. Netto A = 108000 − 72493 = 35507; netto BX = 12000 − 8055 = 3945.
    // (3945,2 è la parte di Ugo sul box, non quella di Elsa.)
    $note = collect(app(NotaSolidarieta::class)->per($s->c, $s->persona('elsa')))->keyBy(fn ($n) => str_starts_with($n['immobile'], 'Box') ? 'box' : 'appartamento');
    expect($note->keys()->sort()->values()->all())->toBe(['appartamento', 'box']);
    expect([$note['appartamento']['residuo_uscente_cents'], $note['appartamento']['residuo_lordo_cents'] ?? null, $note['appartamento']['coppia_cents'] ?? null])->toBe([35507, 108000, 72493])
        ->and([$note['box']['residuo_uscente_cents'], $note['box']['residuo_lordo_cents'] ?? null, $note['box']['coppia_cents'] ?? null])->toBe([3945, 12000, 8055]);
});

// --- O-172-15: le tre schermate dicono lo stesso ----------------------------------------------------------------------------

it('O-172-15 — l\'estratto conto di Elsa, la situazione debitoria dell\'unità e quella della persona portano la stessa nota al netto, con la parentesi; il PDF si stampa', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    ruRegistra($this, $s, b51nVendita($s));
    $attesa = b51nFrase('€ 394,52', '€ 1.200,00 nelle rate, di cui € 805,48');

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['a']]))->assertOk()->viewData('page')['props'];
    expect($props['solidarieta'])->toHaveCount(1);
    expect($props['solidarieta'][0]['testo'])->toContain($attesa)->and($props['solidarieta'][0]['residuo_uscente_cents'])->toBe(39452);

    $perUnita = $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', ['condominio' => $s['c']->id, 'immobile_id' => $s['unita']->id]))->assertOk()->json();
    $perPersona = $this->actingAs($this->user)->getJson(route('admin.gestionale.situazione-debitoria', ['condominio' => $s['c']->id, 'anagrafica_id' => $s['a']->id]))->assertOk()->json();
    expect($perUnita['note_solidarieta'])->toHaveCount(1)->and($perUnita['note_solidarieta'][0])->toContain($attesa)
        ->and($perPersona['note_solidarieta'])->toHaveCount(1)->and($perPersona['note_solidarieta'][0])->toBe($perUnita['note_solidarieta'][0])
        ->and($perUnita['note_solidarieta'][0])->toBe($props['solidarieta'][0]['testo']);

    $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['a']]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

// --- O-172-12 e O-172-12b: la coppia assorbita da un secondo piano (decisione 86.3) ---------------------------------------------

/**
 * L'ordinaria da € 1.200,00 emessa tutta, la vendita Ugo → Elsa del 1/5 con il conguaglio scritto, poi un secondo piano della stessa
 * gestione (Pulizia, € 240,00 in sei rate dal 5/7) generato con i saldi, che assorbe la coppia. Misurato:
 * la coppia diventa Ugo −80548 / Elsa +80548 con `is_applicato` vero e `piano_rate_id` del secondo piano, e nel piano che assorbe Ugo
 * ha quote a credito.
 */
function b51nCoppiaAssorbita($test): Beta51Scenario
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', capitoli: true)->titolare('A', 'ugo');
    $s->persona('elsa');
    $s->genera('Preventivo 2026');
    $s->emetti('Preventivo 2026', '2026-12-31')->assertSessionHasNoErrors();
    $s->passaggio('vendita', 'A', 'ugo', 'elsa', '2026-05-01');
    $s->voce('Ordinaria 2026', 'Pulizia', 24000, ['Proprietà' => 100])->pianoOrdinario('Pulizia 2026', 'Ordinaria 2026', 6, '2026-07-05', capitoli: true);
    $s->piani['Pulizia 2026']['p']->capitoli()->detach($s->voci['Spese generali']['conto']->id);
    $s->genera('Pulizia 2026');

    return $s;
}

it('sentinella — O-172-12: con la coppia assorbita da un secondo piano della stessa gestione la nota torna lorda, € 1.200,00 e nessuna parentesi: il credito di Ugo sta in una sua quota di quell\'altro piano, e la .51 non lo conta (decisione 86.3; il netto del credito non usato è della 1.11.1)', function () {
    $s = b51nCoppiaAssorbita($this);

    // La coppia non è più nel Wallet: è stata assorbita dal secondo piano (is_applicato vero, piano_rate_id del secondo piano).
    $coppia = DB::table('saldi')->whereNotNull('subentro_id')->get(['saldo_iniziale', 'is_applicato', 'piano_rate_id']);
    expect($coppia)->toHaveCount(2)
        ->and($coppia->every(fn ($r) => (bool) $r->is_applicato && (int) $r->piano_rate_id === (int) $s->piani['Pulizia 2026']['p']->id))->toBeTrue();

    // Conto a mano: la nota non rilegge la quota a credito del piano che assorbe, e non può togliere una coppia che non è più nel Wallet.
    // Lordo 120000 (tutte emesse a Ugo e non pagate), niente di coperto: la cifra è quella di prima della Coda 172. Con la formula che
    // toglie sempre la coppia sarebbe 39452 (120000 − 80548): è il valore del caso O-172-12b, dove la compensazione ha già pagato la coppia.
    $nota = app(NotaSolidarieta::class)->per($s->c, $s->persona('elsa'))[0];
    expect($nota['residuo_uscente_cents'])->toBe(120000)
        ->and($nota['residuo_lordo_cents'])->toBe(120000)
        ->and($nota['coppia_cents'])->toBe(0)
        ->and($nota['testo'])->toContain('a nome di Ugo risultano oggi € 1.200,00 non pagati su questa unità. Chi paga in forza della solidarietà')
        ->and($nota['testo'])->not->toContain('già a debito di');
});

it('O-172-12b — coppia assorbita e credito di Ugo usato in compensazione: la compensazione ha già pagato € 805,48 delle sue quote emesse, e la nota dice € 394,52 (la sua parte per giorni) senza togliere la coppia una seconda volta', function () {
    $s = b51nCoppiaAssorbita($this);
    // Il credito della coppia (80548) usato sulle quote emesse di Ugo, dalla più vecchia: importo_pagato sale come con la compensazione vera
    // (emulato con l'aggiornamento diretto).
    $resta = 80548;
    foreach (DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s->piani['Preventivo 2026']['p']->id)
        ->where('rate_quote.anagrafica_id', $s->persona('ugo')->id)->orderBy('rate.numero_rata')->get(['rate_quote.id', 'rate_quote.importo']) as $q) {
        $pagato = min($resta, (int) $q->importo);
        DB::table('rate_quote')->where('id', $q->id)->update(['importo_pagato' => $pagato]);
        $resta -= $pagato;
    }
    expect($resta)->toBe(0);

    // Conto a mano: non pagato 120000 − 80548 = 39452 = 120/365 di 120000, la parte di Ugo. Se la nota togliesse anche la coppia
    // darebbe 39452 − 80548, cioè zero («nulla di non pagato»), mentre Ugo deve ancora € 394,52 (misurato S6).
    $nota = app(NotaSolidarieta::class)->per($s->c, $s->persona('elsa'))[0];
    expect($nota['residuo_uscente_cents'])->toBe(39452)
        ->and($nota['residuo_lordo_cents'])->toBe(39452)
        ->and($nota['coppia_cents'])->toBe(0)
        ->and($nota['testo'])->toContain('a nome di Ugo risultano oggi € 394,52 non pagati su questa unità. Chi paga in forza della solidarietà')
        ->and($nota['testo'])->not->toContain('già a debito di')
        ->and($nota['testo'])->not->toContain('nulla di non pagato');
});
