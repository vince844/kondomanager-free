<?php

/**
 * 1.11.0-beta.50 — i test del prospetto degli oneri accessori e della sua stampa che prendono le mutazioni sopravvissute ai test della
 * beta (giro di mutazioni a mano sulle correzioni). Lo scenario è quello di `ScenariProspettoLocazione.php`: «Spese generali»
 * € 1.200,00, 30 % «Inquilino» (36000) e 70 % «Proprietario»; Ines Moretti inquilina dal 2019, Ugo Rinaldi proprietario; Ines esce il
 * 1/7: 181 giorni suoi, 184 dopo, 36000 × 184/365 = 18147,95 → 18148 (€ 181,48).
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariProspettoLocazione.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

it('M130, M138, M139, M141, M143, M144 — stampa della fine con «Non scriverlo» (strada A′): le parole della restituzione, una per una', function () {
    // La testa con il «pagata da …» della fine (`$pagateAllaFine`); la riga del piede «da restituire a …» con l'importo positivo
    // (`abs`); «conguaglio non scritto» accanto alla voce (non «nessun conguaglio»); la sezione senza conduttore e il suo totale, che si
    // restituiscono; il totale dell'unità diviso; la voce «pagata da Ines, rimasta a suo carico …: si restituisce …».
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'non_scrivere');

    $testo = pflStampa($this, $s);

    expect($testo)->toContain('«pagata da …» se l\'ha pagata qualcun altro: un titolare perché nei giorni del piano nessun inquilino era registrato, oppure, alla fine di una locazione, chi è uscito o chi paga al posto dell\'inquilino')
        ->and($testo)->toContain('da restituire a Ines Moretti: i giorni dopo la sua uscita, che ha pagato € 181,48')
        ->and($testo)->toContain('da restituire a Ines Moretti (fine della locazione del 01/07/2026, conguaglio non scritto)')
        ->and($testo)->toContain('dopo l\'uscita di un inquilino che le aveva nelle sue rate: si restituiscono a chi le ha pagate, fra le parti.')
        ->and($testo)->toContain('Totale da restituire a chi le ha pagate, fra le parti € 181,48')
        ->and($testo)->toContain('di cui € 178,52 ai conduttori e € 181,48 da restituire a chi è uscito, fra le parti.')
        ->and($testo)->toContain('pagata da Ines Moretti, rimasta a suo carico (fine della locazione del 01/07/2026, conguaglio non scritto): si restituisce a chi l\'ha pagata, fra le parti');
});

it('M142 — Ines dal 1/3 ed esce il 1/7 con «Non scriverlo»: i giorni senza conduttore sono di due specie, e la stampa li divide (€ 58,19 restano a Ugo, € 181,48 si restituiscono a Ines)', function () {
    // Le cifre di `ProspettoFineLocazioneTest` (Ines dal 1/3): gennaio e febbraio, 36000 × 59/365 = 5819,18 → 5819, pagati da Ugo per
    // ripiego; i 184 giorni dopo l'uscita, 18148, rimasti nelle rate di Ines.
    $s = pflScenario(inquilini: []);
    pflInizio($this, $s, 'Ines Moretti', '2026-03-01');
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'non_scrivere');

    $testo = pflStampa($this, $s);

    // Fra l'etichetta e la cifra la stampa non ha spazio (due celle della tabella): si cerca con o senza.
    expect($testo)->toContain('Totale dei giorni senza conduttore')
        ->and($testo)->toMatch('/di cui restano a chi le ha pagate ?€ 58,19/u')
        ->and($testo)->toMatch('/di cui da restituire a chi le ha pagate, fra le parti ?€ 181,48/u');
});

it('M122 — Ines esce il 1/7 e Dora resta, con € 120,00 già versati dall\'unità: con la fermata del coinquilino di una beta i giorni dopo l\'uscita restano nelle rate di Ines al lordo; con la regola del piano l\'anello è al netto', function () {
    // Strada C (decisione 79), dei registri delle beta .50–.53 con la fermata del coinquilino: la voce dopo l'uscita è il pezzo lordo della voce (`$lordo`), come la
    // voce di prima dell'uscita; il versato dell'unità si sconta una volta, nella sua riga. Ines 50 %: 18000 × [181, 184] / 365 = 8926,03 / 9073,97 → 8926 e 9074; il
    // versato, 12000 × 30 % / 2 = 1800. 8926 + 9074 − 1800 = 16200, le sue rate. Con il pezzo netto la voce sarebbe 8167, e il totale € 152,92, meno di ciò che Ines
    // ha nelle rate. Coda 245 (1.11.0-beta.55): una fine registrata oggi con un coinquilino che resta ha la regola del piano, e la strada C resta solo per i registri
    // delle beta: qui il registro di quella fine si riscrive com'era (nessuna scelta, la fermata del coinquilino), come in `Beta55CoinquilinoRegistriVecchiTest`.
    $s = pflScenario(inquilini: [['Ines Moretti', 50], ['Dora Neri', 50]]);
    pflVersatoDellUnita($s, 12000);
    pflSeiRateEmesse($this, $s);
    $fine = pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'non_scrivere');
    $registro = $fine->registro;
    unset($registro['conguaglio'], $registro['regolato_fuori'], $registro['coinquilini_letti']);
    $registro['piani_presi'] = [];
    $registro['fermata_coinquilino'] = [['immobile_id' => (int) $s['unita']->id, 'unita' => 'Interno 1', 'gestione_id' => (int) $s['g']->id, 'gestione' => 'Ordinaria 2026',
        'esercizio_id' => (int) $s['e']->id, 'cifra' => 8167, 'persona_id' => (int) $s['persone']['Ines Moretti']->id, 'persona' => 'Ines Moretti', 'restano' => ['Dora Neri'],
        'al_posto' => ['Ugo Rinaldi'], 'wallet' => true, 'strada' => 'legata']];
    \Illuminate\Support\Facades\DB::table('subentri')->where('id', $fine->id)->update(['registro' => json_encode($registro)]);

    $p = pflProspetto($s);

    expect(pflConduttori($p)['Ines Moretti'])->toBe([16200, 16200, 0, 365])
        ->and(collect(pflSezione($p, 'Ines Moretti')['voci'])->firstWhere('strada', 'C')['importo'] ?? null)->toBe(9074);

    // La regola del piano: l'anello è al netto della parte dell'unità del versato, 36000 − 3600 = 32400 (congelato 16200 e 16200); 32400 × 9050/27300 = 10740,66 →
    // Ines 10741, Dora 21659 (∓ 5459). Nelle loro rate 16200 ciascuna, e con «Scrivi» niente da rimborsare.
    $s = pflScenario(inquilini: [['Ines Moretti', 50], ['Dora Neri', 50]]);
    pflVersatoDellUnita($s, 12000);
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'scrivi');

    expect(pflConduttori(pflProspetto($s)))->toBe(['Dora Neri' => [21659, 16200, 0, 365], 'Ines Moretti' => [10741, 16200, 0, 181]]);
});

it('M125 — Ines esce il 1/7 con «Non scriverlo» e rientra il 1/10, con € 120,00 già versati dall\'unità: i suoi giorni sono 273, e le si restituiscono solo luglio–settembre, al netto', function () {
    // Il rientro nella strada A′: i giorni di chi è tornato si contano al lordo nella voce «nelle sue rate» e al netto nella
    // restituzione (`$suoiLordi`, `$suoiNetti`). Il netto delle rate di Ines: 32400 (36000 − 3600 di versato); i suoi 273 giorni:
    // 32400 × 273/365 = 24233,42 → 24234; luglio–settembre (92 giorni): 32400 × 92/365 = 8166,58 → 8166, da restituirle fra le parti.
    $s = pflScenario();
    pflVersatoDellUnita($s, 12000);
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'non_scrivere');
    pflInizio($this, $s, 'Ines Moretti', '2026-10-01');

    $p = pflProspetto($s);

    // Le voci sono al lordo, e il versato dell'unità ha la sua riga (12000 × 30 % = 3600): la voce «nelle sue rate» dei 273 giorni
    // 17852 + 18148 × 92/184 = 17852 + 9074 = 26926; i giorni dopo l'uscita che non sono suoi (luglio–settembre) 18148 − 9074 = 9074;
    // la restituzione al netto, −8166. 26926 + 9074 − 3600 − 8166 = 24234. Con i suoi giorni al netto (8167) nella voce dei 273 giorni,
    // la somma tornava uguale ma le due voci erano 26019 e 9981.
    $voci = collect(pflSezione($p, 'Ines Moretti')['voci'])->map(fn ($v) => [(int) $v['importo'], $v['modo'], $v['strada'] ?? null])->all();

    expect(pflConduttori($p))->toBe(['Ines Moretti' => [24234, 32400, -8166, 273]])
        ->and(pflSenza($p))->toBe([['Ines Moretti', 8166, 92]])
        ->and($voci)->toContain([26926, 'rate', null])
        ->and($voci)->toContain([9074, 'rate', 'A'])
        ->and($voci)->toContain([-8166, 'restituzione', 'A']);
});

it('M133, M135, M140 — fermata della vendita del 1/10 con Luca già registrato dal 1/11: i giorni di Luca li ha pagati Ugo con la coppia, e la voce di Luca non nomina Elsa; ottobre sì', function () {
    // `fermateDopoIlConduttore`: la fermata conta i giorni senza un conduttore registrato; chi era già conduttore quando la vendita si è
    // fermata non c'entra, e per i suoi giorni ha pagato solo chi ha la coppia. Ottobre (senza conduttore) è coperto dalla fermata:
    // Ugo, o Elsa se le parti hanno regolato. La testa lo dice, e la stampa nomina tutti e due.
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'scrivi');
    pflInizio($this, $s, 'Luca Ferri', '2026-11-01');
    pflVendita($this, $s, 'Ugo Rinaldi', 'Elsa Conti', '2026-10-01');

    $p = pflProspetto($s);
    $testo = fn (array $voce) => implode(' ', pflFrasi($voce));
    $luca = collect(pflSezione($p, 'Luca Ferri')['voci'])->map($testo)->implode(' | ');
    $ottobre = collect($p['senza_conduttore']['voci'])->filter(fn ($v) => str_contains($testo($v), 'Elsa Conti'))->sum('giorni');

    expect($luca)->toContain('Ugo Rinaldi')->not->toContain('Elsa Conti')
        ->and((int) $ottobre)->toBe(31)
        ->and(implode(' ', $p['intestazione']))->toContain('Dopo la fine con il conguaglio scritto un passaggio ha cambiato chi paga al posto dell\'inquilino senza spostare il conguaglio, e lo ha detto')
        ->and(pflStampa($this, $s))->toContain('pagata da Ugo Rinaldi con il conguaglio della fine locazione del 01/07/2026, o da Elsa Conti, se le parti hanno regolato fra loro la parte del conguaglio che la vendita del 01/10/2026 non ha spostato');
});

it('M132 — la vendita il 31/12 dopo «Scrivi»: la fermata copre l\'ultimo giorno, e la voce di quel giorno nomina anche Elsa', function () {
    // `pagatoriDopoLUscita` taglia i giorni dopo l'uscita al giorno di ogni fermata, compreso l'ultimo giorno (`lte`). La fermata: 18148
    // × 1/184 = 98,63 → 99 a Elsa.
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', null, '2026-07-01', 'scrivi');
    // Come `pflVendita`, senza la copia autentica: ricevuta il 31/12 sarebbe un giorno futuro.
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => pflRigaAperta($s, $s['unita'], 'Ugo Rinaldi', 'proprietario'), 'anagrafica_entrante_id' => pflPersona($s, 'Elsa Conti')->id,
        'decorrenza' => '2026-12-31', 'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Verdi, rep. 12346',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, la parte della fine locazione si regola a mano',
    ])->assertSessionHasNoErrors();

    $p = pflProspetto($s);
    $testo = fn (array $voce) => implode(' ', pflFrasi($voce));
    $conElsa = collect($p['senza_conduttore']['voci'])->filter(fn ($v) => str_contains($testo($v), 'Elsa Conti'));

    expect((int) $conElsa->sum('giorni'))->toBe(1);
});
