<?php

/**
 * 1.11.0-beta.55, Coda 358 — il prospetto degli oneri accessori del cambio d'inquilino con «Non scriverlo» e un coinquilino che resta.
 *
 * Nel cambio d'inquilino in un passo chi entra prende la quota di chi esce (decisione 37). Con «Non scriverlo» i giorni dopo l'uscita restano nelle rate di
 * chi esce, e chi entra glieli rimborsa con il prospetto (decisione 77.4, la strada A′): il pannello lo dice («Sara glieli rimborsa»), il registro lo conserva
 * (`regolato_fuori`, Ines − 9074 e Sara + 9074), «Scrivi» scriverebbe la stessa coppia. Il coinquilino che resta ha i suoi giorni nelle sue rate: non rimborsa
 * niente. Dalla 1.11.0-beta.50 il prospetto divideva invece i giorni di chi esce fra tutti i conduttori dopo l'uscita, coinquilino compreso.
 *
 * Il condominio è quello di `ScenariFineLocazione`: «Spese generali» € 1.200,00 al 30 % all'inquilino, l'anello dell'Interno 1 è 36000; Ugo proprietario al
 * 100 %; le rate da gennaio a giugno a giornale. Il cambio Ines → Sara il 1/7, con la quota di Ines.
 *
 * I CONTI A MANO (centesimi; anno 2026, 365 giorni: 181 fino al 30/6, 184 dal 1/7):
 * - Ines 50 e Luca 50: le rate 18000 e 18000. La parte di Ines dopo l'uscita è 18000 × 184 / 365 = 9073,97 → 9074, prima 8926,03 → 8926 (18000 in tutto).
 *   Con «Non scriverlo»: Ines [8926, nelle rate 18000, da restituirle 9074]; Luca [18000, 18000, 0], i suoi 365 giorni; Sara [9074, 0, da rimborsare 9074],
 *   184 giorni. Prima: Luca [22537, 18000, 4537] con 549 giorni (365 + 184) e Sara [4537, 0, 4537]: i 9074 divisi 9200 : 9200 fra Luca e Sara.
 * - Ines 50 e Luca 30: le rate 36000 × 50 / 80 = 22500 e 13500. Dopo l'uscita 22500 × 184 / 365 = 11342,47 → 11342, prima 11157,53 → 11158. Ines [11158,
 *   22500, − 11342]; Luca [13500, 13500, 0]; Sara [11342, 0, 11342]. Prima: Luca 4253 (11342 × 30 × 184 / 80 × 184 = 4253,25) e Sara 7089.
 * - Il controllo con «Scrivi»: la coppia Ines − 9074, Sara + 9074 nei saldi; i giorni dopo l'uscita sono di Sara «con il conguaglio del cambio d'inquilino»:
 *   Ines [8926, 8926, 0] per i suoi 181 giorni, Sara [9074, 0, 0], Luca [18000, 18000, 0].
 * - Il controllo senza coinquilino (Ines 100): 36000 × 184 / 365 = 18147,95 → 18148; Ines [17852, 36000, − 18148], Sara [18148, 0, 18148].
 *
 * Cosa NON copre: il cambio d'inquilino sull'anello di una fine con la regola del piano (`Beta55CoinquilinoProspettoTest`); la pagina Vue; MySQL.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Ines e il coinquilino Luca con le quote date, il piano generato con le rate da gennaio a giugno a giornale, e Sara che entrerà. */
function pcc358Scenario($test, array $inquilini): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => $inquilini]);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);
    fclPersona($s, 'Sara');

    return $s;
}

/** Il cambio Ines → Sara il 1/7 dalla rotta vera, con la quota di Ines e la scelta data. */
function pcc358Cambio(array $s, string $scelta): array
{
    $quota = (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Ines'))->value('quota');

    return ['quota' => $quota] + fclModulo($s, $s['persone']['Sara']) + ['conguaglio' => $scelta];
}

it('Coda 358 — Ines e Luca al 50 %, il cambio Ines → Sara con «Non scriverlo»: Sara rimborsa a Ines € 90,74, e Luca non rimborsa niente', function () {
    $s = pcc358Scenario($this, [['Ines', 50], ['Luca', 50]]);
    $a = fclAnteprima($this, $s, pcc358Cambio($s, 'non_scrivere'));
    expect(fclFrasi($a))->toContain('Sara glieli rimborsa con il prospetto degli oneri accessori');
    $cambio = fclRegistra($this, $s, pcc358Cambio($s, 'non_scrivere'))->fresh();
    expect(fclSaldi($s))->toBe([])
        ->and(collect($cambio->registro['regolato_fuori'][0]['persone'])->pluck('importo', 'nome')->all())->toBe(['Ines' => -9074, 'Sara' => 9074]);

    // Era: luca [22537, 18000, 4537, 549], sara [4537, 0, 4537, 184].
    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [8926, 18000, -9074, 181],
        'luca' => [18000, 18000, 0, 365],
        'sara' => [9074, 0, 9074, 184],
    ]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Luca € 180,00')
        ->and($stampa)->toContain('Totale a carico di Sara € 90,74')
        ->and($stampa)->not->toContain('€ 225,37')
        ->and($stampa)->not->toContain('€ 45,37');
});

it('Coda 358 — Ines 50 e Luca 30, il cambio Ines → Sara con «Non scriverlo»: Sara rimborsa a Ines € 113,42, e Luca resta con le sue rate', function () {
    $s = pcc358Scenario($this, [['Ines', 50], ['Luca', 30]]);
    fclRegistra($this, $s, pcc358Cambio($s, 'non_scrivere'));

    // Era: luca [17753, 13500, 4253, …], sara [7089, 0, 7089, 184].
    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [11158, 22500, -11342, 181],
        'luca' => [13500, 13500, 0, 365],
        'sara' => [11342, 0, 11342, 184],
    ]);
});

it('Coda 358 — un coinquilino associato dopo il cambio, con la locazione dal 2019: non divide i giorni di Ines, che il registro del cambio dà a Sara', function () {
    // Ines 50 e Luca 30, il cambio Ines → Sara con «Non scriverlo» (Sara 11342: la variante sopra). Poi Elsa associata da «Associa soggetto», inquilina al 20 % dal
    // 1/1/2019 (la somma delle quote fa 100): per le righe di oggi era coinquilina il giorno prima del cambio e quel giorno, come Luca. Il registro del cambio dice
    // che i giorni di Ines dopo l'uscita li riceve Sara (`regolato_fuori` Ines − 11342, Sara + 11342): Sara [11342, 0, 11342]. Con le righe del giorno della
    // registrazione Elsa sarebbe fra chi divide: 11342 per 50 × 184 e 20 × 184, Sara 8101,43 ed Elsa 3240,57 → 8101 e 3241.
    $s = pcc358Scenario($this, [['Ines', 50], ['Luca', 30]]);
    fclRegistra($this, $s, pcc358Cambio($s, 'non_scrivere'));
    fclPersona($s, 'Elsa');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.anagrafiche.store', [$s['c'], $s['unita']]), [
        'anagrafica_id' => $s['persone']['Elsa']->id, 'tipologia' => 'inquilino', 'quota' => 20, 'data_inizio' => '2019-01-01',
    ])->assertSessionHasNoErrors();

    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [11158, 22500, -11342, 181],
        'luca' => [13500, 13500, 0, 365],
        'sara' => [11342, 0, 11342, 184],
    ]);
});

it('Coda 358 — il controllo con «Scrivi»: la coppia Ines − € 90,74, Sara + € 90,74 nei saldi, e nel prospetto nessuno rimborsa niente', function () {
    $s = pcc358Scenario($this, [['Ines', 50], ['Luca', 50]]);
    fclRegistra($this, $s, pcc358Cambio($s, 'scrivi'));

    expect(fclSaldi($s))->toBe(['Ines' => -9074, 'Sara' => 9074])
        ->and(fclProspetto($s)['conduttori'])->toBe([
            'ines' => [8926, 8926, 0, 181],
            'luca' => [18000, 18000, 0, 365],
            'sara' => [9074, 0, 0, 184],
        ]);
});

it('Coda 358 — il controllo senza coinquilino: Ines al 100 %, il cambio con «Non scriverlo», Sara rimborsa a Ines € 181,48', function () {
    $s = pcc358Scenario($this, [['Ines', 100]]);
    fclRegistra($this, $s, pcc358Cambio($s, 'non_scrivere'));

    expect(fclProspetto($s)['conduttori'])->toBe(['ines' => [17852, 36000, -18148, 181], 'sara' => [18148, 0, 18148, 184]]);
});

// --- Coda 360: dopo il cambio con «Non scriverlo», un altro entrante sulla stessa unità ------------------------------------------------------------------

/** Il cambio d'inquilino di `$esce` in `$entra` il giorno dato, dalla rotta vera, con la quota di chi esce e la scelta data. */
function pcc360Cambio($test, array &$s, string $esce, string $entra, string $il, string $scelta): void
{
    if (! isset($s['persone'][$entra])) {
        fclPersona($s, $entra);
    }
    $quota = (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, $esce))->value('quota');
    fclRegistra($test, $s, ['quota' => $quota] + fclModulo($s, $s['persone'][$entra], $il, [], $esce) + ['conguaglio' => $scelta]);
}

/** Chi ha pagato i giorni che un conduttore rimborsa nel prospetto (la strada A′): chi ha pagato → la cifra. */
function pcc360PagateDa(array $s, string $nome): array
{
    $c = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', $nome);

    return collect($c['voci'] ?? [])->filter(fn ($v) => ! empty($v['pagato_da']))->groupBy('pagato_da')->map(fn ($g) => (int) $g->sum('importo'))->sortKeys()->all();
}

it('Coda 360 — due cambi con «Non scriverlo», ognuno con il coinquilino che resta (Luca → Ivo il 1/4, Ines → Irma il 1/7): Ivo rimborsa a Luca € 135,62 e Irma a Ines € 90,74, come dice il registro di ogni cambio', function () {
    // Ines e Luca al 50 %, le rate 18000 e 18000. Il cambio di Luca il 1/4: i giorni di Luca dopo l'uscita, 18000 × 275/365 = 13561,64 → 13562, li prende Ivo (decisione 37: la quota di Luca), e
    // Ines è la coinquilina che resta. Il cambio di Ines il 1/7: 18000 × 184/365 = 9073,97 → 9074, a Irma, e il coinquilino che resta è Ivo. I pesi di oggi (Ines 9050, Irma 9200, Luca 4500,
    // Ivo 13750 su 36500) danno le stesse cifre: Ines 8926, Irma 9074, Luca 4438, Ivo 13562. Irma ha preso il posto di Ines, la coinquilina che restava al cambio di Luca: i giorni di Luca
    // non sono suoi. Era: i giorni di Luca divisi fra Ivo e Irma, che al cambio di Luca non c'era (Ivo 8125, Irma 14511).
    $s = pcc358Scenario($this, [['Ines', 50], ['Luca', 50]]);
    pcc360Cambio($this, $s, 'Luca', 'Ivo', '2026-04-01', 'non_scrivere');
    pcc360Cambio($this, $s, 'Ines', 'Irma', '2026-07-01', 'non_scrivere');

    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [8926, 18000, -9074, 181],
        'irma' => [9074, 0, 9074, 184],
        'ivo' => [13562, 0, 13562, 275],
        'luca' => [4438, 18000, -13562, 90],
    ])->and(pcc360PagateDa($s, 'Ivo'))->toBe(['Luca' => 13562])
        ->and(pcc360PagateDa($s, 'Irma'))->toBe(['Ines' => 9074]);
});

it('Coda 360 — il cambio Ines → Sara con «Non scriverlo» e poi il cambio del coinquilino Luca → Irma il 1/10: Sara rimborsa a Ines € 90,74, e Irma ha i giorni di Luca, con «Scrivi» e con «Non scriverlo»', function () {
    // I pesi di oggi: Ines 9050, Sara 9200, Luca 13650, Irma 4600 su 36500 → Ines 8926, Sara 9074, Luca 13463, Irma 4537 (18000 × 92/365 = 4536,99). Sara deve a Ines 9074 e basta: Irma
    // ha preso il posto di Luca, il coinquilino che restava al cambio di Ines. Con «Scrivi» la coppia di Luca, − 4537 → Irma, è nei saldi; con «Non scriverlo» Irma la rimborsa a Luca.
    // Era: i giorni di Ines divisi fra Sara e Irma, Sara 6049 e Irma 3025 (in più dei suoi 4537).
    foreach (['scrivi', 'non_scrivere'] as $scelta) {
        $s = pcc358Scenario($this, [['Ines', 50], ['Luca', 50]]);
        pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'non_scrivere');
        pcc360Cambio($this, $s, 'Luca', 'Irma', '2026-10-01', $scelta);

        expect(fclProspetto($s)['conduttori'])->toBe([
            'ines' => [8926, 18000, -9074, 181],
            'irma' => [4537, 0, $scelta === 'scrivi' ? 0 : 4537, 92],
            'luca' => [13463, $scelta === 'scrivi' ? 13463 : 18000, $scelta === 'scrivi' ? 0 : -4537, 273],
            'sara' => [9074, 0, 9074, 184],
        ], $scelta)->and(pcc360PagateDa($s, 'Sara'))->toBe(['Ines' => 9074]);
    }
});

it('Coda 360 — i due cambi con «Non scriverlo» lo stesso giorno, Ines 50 e Luca 30 (Ines → Sara e Luca → Irma il 1/7): Sara rimborsa i giorni di Ines e Irma quelli di Luca', function () {
    // Le rate 22500 e 13500. I giorni dopo il 1/7: Ines 22500 × 184/365 = 11342,47 → 11342, a Sara; Luca 13500 × 184/365 = 6805,48 → 6805, a Irma. Il giorno prima dei cambi Luca era il
    // coinquilino di Ines, e Ines quella di Luca: chi ha preso il loro posto non divide i giorni dell'altro. Era: i giorni di Ines divisi 50 : 30 fra Sara e Irma (7089 e 4253), e quelli di Luca
    // lo stesso (4253 e 2552): le somme tornavano, ma Sara «pagata da Luca» € 42,53 e Irma «pagata da Ines» € 42,53.
    $s = pcc358Scenario($this, [['Ines', 50], ['Luca', 30]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'non_scrivere');
    pcc360Cambio($this, $s, 'Luca', 'Irma', '2026-07-01', 'non_scrivere');

    expect(pcc360PagateDa($s, 'Sara'))->toBe(['Ines' => 11342])
        ->and(pcc360PagateDa($s, 'Irma'))->toBe(['Luca' => 6805]);
});

it('Coda 360 — la guardia: dopo il cambio Ines → Sara con «Non scriverlo» e Luca che resta, Teo entra con un inizio il 1/9 al 20 %: non ha preso il posto di nessuno, e i giorni di Ines li divide con Sara', function () {
    // Scritto dopo il codice (il rosso è la mutazione che toglie dalla divisione anche chi entra con un inizio). Ines 40 e Luca 40: le rate 18000 e 18000. I giorni di Ines dopo il 1/7,
    // 18000 × 184/365 = 9073,97 → 9074, divisi fra chi c'è dopo l'uscita senza Luca: Sara 40 × 184 = 7360 e Teo 20 × 122 = 2440 (su 9800): 6814,73 e 2259,27 → Sara 6815 (il centesimo
    // al resto più alto), Teo 2259. Senza Teo i giorni dal 1/9 di Teo non li pagherebbe nessuno nel prospetto.
    $s = pcc358Scenario($this, [['Ines', 40], ['Luca', 40]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'non_scrivere');
    fclPersona($s, 'Teo');
    fclRegistra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Teo']->id, 'decorrenza' => '2026-09-01', 'quota' => 20,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);

    expect(pcc360PagateDa($s, 'Sara'))->toBe(['Ines' => 6815])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ines' => 2259]);
});

// --- Dopo il cambio con «Non scriverlo», l'uscita di chi è entrato senza un nuovo inquilino ---------------------------------------------------------------

/** Ines e Dora al 50 %, il cambio Ines → Luca il 1/7 con «Non scriverlo», e la fine di Luca il 1/10 senza chi entra, dalle rotte vere: nessuna scelta, niente nei saldi. */
function pcc360FineDiLuca($test): array
{
    $s = pcc358Scenario($test, [['Ines', 50], ['Dora', 50]]);
    pcc360Cambio($test, $s, 'Ines', 'Luca', '2026-07-01', 'non_scrivere');
    $fine = ['quota' => 50.0] + fclModulo($s, null, '2026-10-01', [], 'Luca');
    expect(fclAnteprima($test, $s, $fine)['rate']['scelta_del_conguaglio'] ?? false)->toBeFalse();
    fclRegistra($test, $s, $fine);
    expect(fclSaldi($s))->toBe([]);

    return $s;
}

it('Coda 360 — dopo il cambio Ines → Luca con «Non scriverlo» e Dora che resta, la fine di Luca il 1/10 senza chi entra: dal 1/10 Dora è sola, e i giorni di Ines li rimborsa lei; Luca € 45,37, non € 90,74', function () {
    // Le rate 18000 e 18000. I giorni di Ines dopo il 1/7: 18000 × 184/365 = 9073,97 → 9074. Chi sta al posto di Ines, giorno per giorno: Luca dal 1/7 al 30/9 (92 giorni), poi Dora, sola, dal
    // 1/10 al 31/12 (92): per il motore la parte dell'inquilino di quei giorni è tutta sua, anche la metà che è nelle rate di Ines (quota × giorni fra chi c'è). Il coinquilino che resta ha i
    // suoi giorni nelle sue rate finché qualcuno sta al posto di chi esce; dopo, divide come gli altri. 9074 per giorni, 92 : 92: Luca 4537, Dora 4537. Ines [8926, 18000, − 9074] per 181
    // giorni; Luca [4537, 0, 4537] per 92; Dora [18000 + 4537 = 22537, 18000, 4537] per 365 + 92. Era: Dora tolta da chi divide anche dopo l'uscita di Luca, e Luca pagava tutti i 184 giorni,
    // [9074, 0, 9074] «pagata da Ines», e Dora niente.
    $s = pcc360FineDiLuca($this);

    expect(fclProspetto($s)['conduttori'])->toBe([
        'dora' => [22537, 18000, 4537, 457],
        'ines' => [8926, 18000, -9074, 181],
        'luca' => [4537, 0, 4537, 92],
    ])->and(pcc360PagateDa($s, 'Luca'))->toBe(['Ines' => 4537])
        ->and(pcc360PagateDa($s, 'Dora'))->toBe(['Ines' => 4537]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Luca € 45,37')->and($stampa)->toContain('Totale a carico di Dora € 225,37')
        ->and($stampa)->not->toContain('Totale a carico di Luca € 90,74');

    // Il controllo senza coinquilino (Ines 100, le rate 36000): i 18148 di Ines dopo il 1/7 (36000 × 184/365 = 18147,95) sono di Luca per i suoi 92 giorni, 9074, e degli altri 92 non è
    // conduttore nessuno: «senza conduttore», 9074. Com'era.
    $s = pcc358Scenario($this, [['Ines', 100]]);
    pcc360Cambio($this, $s, 'Ines', 'Luca', '2026-07-01', 'non_scrivere');
    fclRegistra($this, $s, ['quota' => 100.0] + fclModulo($s, null, '2026-10-01', [], 'Luca'));
    expect(fclProspetto($s))->toBe(['conduttori' => ['ines' => [17852, 36000, -18148, 181], 'luca' => [9074, 0, 9074, 92]], 'senza' => [9074, 92]]);
});

it('Coda 360 — dopo il cambio Ines → Luca con «Non scriverlo» e la fine di Luca, Teo entra il 1/11 al 50 %: i giorni di Ines li rimborsano Luca € 45,37, Dora € 15,29 per ottobre, quando era sola, e Teo € 30,08', function () {
    // I 9074 di Ines dopo il 1/7 per giorni: i giorni in cui qualcuno sta al posto di Ines, Luca dal 1/7 al 30/9 (92) e Teo dal 1/11 (61), sono 153; quelli di Dora sola, ottobre, 31.
    // 9074 × 31/184 = 1528,77 → Dora 1529, il resto 7545; fra Luca 50 × 92 = 4600 e Teo 50 × 61 = 3050: 4536,86 e 3008,14 → Luca 4537, Teo 3008 (le stesse cifre di 9074 × 92/184,
    // × 31/184 e × 61/184 in un passo). Dal 1/11 Dora ha di nuovo i suoi giorni nelle sue rate (c'è Teo, come nella guardia sopra). Era: 9074 fra Luca e Teo soli, 9074 × 4600/7650 = 5456,26
    // e × 3050/7650 = 3617,74 → 5456 e 3618, e Dora niente.
    $s = pcc360FineDiLuca($this);
    fclPersona($s, 'Teo');
    fclRegistra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Teo']->id, 'decorrenza' => '2026-11-01', 'quota' => 50,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);

    expect(fclProspetto($s)['conduttori'])->toBe([
        'dora' => [19529, 18000, 1529, 396],
        'ines' => [8926, 18000, -9074, 181],
        'luca' => [4537, 0, 4537, 92],
        'teo' => [3008, 0, 3008, 61],
    ])->and(pcc360PagateDa($s, 'Dora'))->toBe(['Ines' => 1529])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ines' => 3008]);
});

it('Coda 360 — dopo il cambio Ines → Luca con «Non scriverlo» e la fine di Luca, Ines torna il 1/11 al 50 %: i suoi giorni di novembre e dicembre sono nelle sue rate, e Dora rimborsa solo ottobre, € 15,29', function () {
    // Come con Teo, ma chi torna il 1/11 è Ines (il rientro): i giorni dal 1/11 sono suoi, già nelle sue rate (la voce torna «nelle sue rate» per quei giorni, e la restituzione non li conta).
    // Luca 4537, Dora 1529 per ottobre, Ines 3008 sua: Ines [8926 + 3008 = 11934, 18000, − (9074 − 3008) = − 6066] per 181 + 61 giorni. Era: 9074 fra Luca e Ines, 5456 e 3618, e Dora niente;
    // contando fra chi sta al posto di Ines anche lei, ma non i giorni del suo rientro, Dora dividerebbe con Ines novembre e dicembre (4537 per 50 × 92 e 50 × 61: Dora 2728).
    $s = pcc360FineDiLuca($this);
    fclRegistra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Ines']->id, 'decorrenza' => '2026-11-01', 'quota' => 50,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);

    expect(fclProspetto($s)['conduttori'])->toBe([
        'dora' => [19529, 18000, 1529, 396],
        'ines' => [11934, 18000, -6066, 242],
        'luca' => [4537, 0, 4537, 92],
    ])->and(pcc360PagateDa($s, 'Dora'))->toBe(['Ines' => 1529]);
});

it('Coda 360 — lo stesso con una voce dei soli giorni dopo l\'uscita di Luca («Verde», competenza 1/10–31/12): nessuno sta al posto di Ines, e la sua metà la rimborsa Dora, € 60,00, non resta «senza conduttore»', function () {
    // «Verde» € 400,00 al 30 % sulla «Proprietà» (12000), con la competenza 1/10–31/12 (92 giorni): Ines e Dora 6000 nelle rate. Il cambio del 1/7 viene prima della voce, e con «Non scriverlo» la
    // voce di Ines è tutta dopo la sua uscita (la strada A′); nei suoi giorni Luca non è più conduttore, e Dora è sola: i 6000 di Ines sono di Dora, «pagata da Ines». Le «Spese generali» come
    // sopra: Luca 4537, Dora 4537. Ines [8926, 24000, − 9074 − 6000 = − 15074]; Luca [4537, 0, 4537]; Dora [18000 + 6000 + 4537 + 6000 = 34537, 24000, 10537]. Era: i 6000 «senza conduttore»,
    // da restituire a Ines, che nessun conduttore rimborsa.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $verde = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Verde', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 40000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $verde->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => 120000], $verde->id => ['importo' => 40000]]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $verde->id)->value('id');
    \App\Models\Gestionale\CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-10-01', 'al' => '2026-12-31', 'ordine' => 0]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    expect(fclNetti($s))->toBe(['Dora' => 24000, 'Ines' => 24000, 'Ugo' => 112000]);
    pcc360Cambio($this, $s, 'Ines', 'Luca', '2026-07-01', 'non_scrivere');
    fclRegistra($this, $s, ['quota' => 50.0] + fclModulo($s, null, '2026-10-01', [], 'Luca'));

    $p = fclProspetto($s);
    expect(array_map(fn (array $c) => array_slice($c, 0, 3), $p['conduttori']))->toBe([
        'dora' => [34537, 24000, 10537],
        'ines' => [8926, 24000, -15074],
        'luca' => [4537, 0, 4537],
    ])->and($p['senza'][0])->toBe(0)
        ->and(pcc360PagateDa($s, 'Dora'))->toBe(['Ines' => 10537]);
});

it('Coda 360 — il rientro di Ines dopo la fine di Luca, con € 120,00 di già versato «dell\'unità» sulla voce: i suoi giorni dal 1/11 sono nelle sue rate al lordo, € 30,08, e chi sta al posto di Ines rimborsa al netto', function () {
    // Scritto dopo il codice (il rosso è la mutazione che divide il lordo come il netto). Il già versato «dell'unità» (decisione 17, D8), 12000 sulla «Spese generali»
    // prima della generazione: la parte dell'inquilino al lordo 36000 (Ines e Dora 18000), al netto (120000 − 12000) × 30 % = 32400 (Ines e Dora 16200, le rate). Dopo il 1/7, i giorni di Ines: al
    // lordo 18000 × 184/365 = 9073,97 → 9074, al netto 16200 × 184/365 = 8166,58 → 8167. I giorni con un altro conduttore al posto di Ines (Luca 92, Ines stessa dal 1/11 61) sono 153, quelli di
    // Dora sola 31: il netto 8167 × 31/184 = 1375,95 → 1376 e 6791; il lordo 9074 × 31/184 = 1528,77 → 1529 e 7545. Il netto dei 153 giorni fra Luca 50 × 92 = 4600 e Ines 50 × 61 = 3050:
    // 4083,48 e 2707,52 → Luca 4083, Ines 2708; il lordo: 4536,86 e 3008,14 → 4537 e 3008, i 61 giorni del rientro di Ines «nelle sue rate», che si sommano ai suoi 181 di prima (18000 × 181/365
    // = 8926,03 → 8926): la voce 11934 per 242 giorni. Dora 1376 per ottobre. Il totale di Ines è il netto dei suoi giorni, 8033 (16200 × 181/365 = 8033,42) + 2708 = 10741. Dividendo il lordo come
    // il netto (6791 + 907 = 7698) i 61 giorni sarebbero 7698 × 3050/7650 = 3069,18 → 3069, e la voce 11995.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->where('conti.nome', 'Spese generali')->value('conti.id');
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    expect(array_intersect_key(fclNetti($s), ['Dora' => 1, 'Ines' => 1]))->toBe(['Dora' => 16200, 'Ines' => 16200]);
    pcc360Cambio($this, $s, 'Ines', 'Luca', '2026-07-01', 'non_scrivere');
    fclRegistra($this, $s, ['quota' => 50.0] + fclModulo($s, null, '2026-10-01', [], 'Luca'));
    fclRegistra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Ines']->id, 'decorrenza' => '2026-11-01', 'quota' => 50,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);

    $ines = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Ines');
    expect(pcc360PagateDa($s, 'Luca'))->toBe(['Ines' => 4083])
        ->and(pcc360PagateDa($s, 'Dora'))->toBe(['Ines' => 1376])
        ->and(collect($ines['voci'])->where('giorni', 242)->map(fn ($v) => [(int) $v['importo'], $v['modo']])->values()->all())->toBe([[11934, 'rate']])
        ->and((int) $ines['totale'])->toBe(10741);
});

// --- Coda 364: dopo il cambio con «Scrivi», l'inizio di un altro conduttore -------------------------------------------------------------------------------

/** L'inizio della locazione di `$chi` il giorno dato, con la quota data, dalle rotte vere: le frasi del pannello «Cosa cambierà», poi la registrazione. */
function pcc361Inizio($test, array &$s, string $chi, string $il, int $quota): string
{
    if (! isset($s['persone'][$chi])) {
        fclPersona($s, $chi);
    }
    $dati = ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => $il, 'quota' => $quota,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []];
    $frasi = fclFrasi(fclAnteprima($test, $s, $dati));
    fclRegistra($test, $s, $dati);

    return $frasi;
}

/** La sezione di un conduttore nel prospetto: [totale, con il conguaglio, la parte di chi è entrato dopo, per nome (negativa: da ricevere; positiva: da restituire)]. */
function pcc361Sezione(array $s, string $nome): array
{
    $c = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', $nome);

    return [(int) ($c['totale'] ?? 0), (int) ($c['con_il_conguaglio'] ?? 0), $c['entrati_dopo'] ?? []];
}

it('Coda 364 — Ines sola all\'80 %, il cambio Ines → Sara con «Scrivi» il 1/7, Teo entra il 1/9 al 20 %: Teo rimborsa a Sara € 25,80, come dice il pannello del suo inizio', function () {
    // Ines è la sola inquilina, all'80 % (Teo entrerà al 20 %): la sua rata è tutto l'anello, 36000. I giorni dopo il 1/7: 36000 × 184/365 = 18147,95 → 18148, la coppia a Sara (Ines − 18148, Sara + 18148 nel Wallet). Dal 1/9 nei giorni di
    // Sara c'è anche Teo: i 18148 si dividono per quota e per giorni fra chi c'è, come con «Non scriverlo»: Sara 80 × 184 = 14720, Teo 20 × 122 = 2440, su 17160: 15567,52 e 2580,48 →
    // Sara 15568, Teo 2580. Sara ha pagato con il conguaglio 18148, la sua parte è 15568, e da Teo riceve 2580. Ines [17852, 17852, 0] per 181 giorni. Era: Sara [18148, 0, 0] per i 184 giorni
    // di Ines, e Teo niente, mentre il pannello dell'inizio diceva già «li rimborsa Teo a Sara, con il prospetto degli oneri accessori».
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148]);
    $frasi = pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);
    expect($frasi)->toContain('i giorni dopo la sua uscita li ha presi con il conguaglio Sara, e li rimborsa Teo a Sara, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148]);

    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [17852, 17852, 0, 181],
        'sara' => [15568, 0, -2580, 184],
        'teo' => [2580, 0, 2580, 122],
    ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([15568, 18148, ['Teo' => -2580]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 2580]);

    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Teo € 25,80')
        ->and($stampa)->toContain('pagata da Sara con il conguaglio del cambio d\'inquilino del 01/07/2026')
        ->and($stampa)->toContain('Totale a carico di Sara € 155,68 con il conguaglio del cambio d\'inquilino € 181,48 a favore di Sara: da ricevere da Teo, la parte di Teo dei giorni che ha pagato con il conguaglio € 25,80')
        ->and($stampa)->toContain('da ricevere da Teo: la parte di Teo dei giorni presi con il conguaglio del cambio d\'inquilino del 01/07/2026')
        ->and($stampa)->toContain('oppure chi è entrato con il conguaglio di un cambio d\'inquilino, per i giorni di un conduttore entrato dopo — è la parte da rimborsare.')
        ->and($stampa)->not->toContain('Totale a carico di Sara € 181,48')
        ->and($stampa)->not->toContain('di cui con il conguaglio');
});

it('Coda 364 — Ines 40 e Luca 40, il cambio Ines → Sara con «Scrivi», Teo entra il 1/9 al 20 %: Teo rimborsa a Sara € 22,59, e Luca, il coinquilino che resta, non divide', function () {
    // Le rate 18000 e 18000. I giorni di Ines dopo il 1/7: 18000 × 184/365 = 9073,97 → 9074, la coppia a Sara. Fra chi c'è dopo l'uscita senza Luca, che ha i suoi giorni nelle sue rate: Sara
    // 40 × 184 = 7360, Teo 20 × 122 = 2440 (su 9800): 6814,76 e 2259,24 → Sara 6815, Teo 2259. Le stesse cifre della guardia della Coda 360 con «Non scriverlo»: cambia solo chi riceve. Era:
    // Sara [9074, 0, 0] e Teo niente.
    $s = pcc358Scenario($this, [['Ines', 40], ['Luca', 40]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    $frasi = pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);
    expect($frasi)->toContain('li ha presi con il conguaglio Sara, e li rimborsa Teo a Sara, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Ines' => -9074, 'Sara' => 9074]);

    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [8926, 8926, 0, 181],
        'luca' => [18000, 18000, 0, 365],
        'sara' => [6815, 0, -2259, 184],
        'teo' => [2259, 0, 2259, 122],
    ])->and(pcc361Sezione($s, 'Sara'))->toBe([6815, 9074, ['Teo' => -2259]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 2259])
        ->and(pcc360PagateDa($s, 'Luca'))->toBe([]);
});

it('Coda 364 — gli stessi fatti, tre strade: il cambio con «Scrivi», con «Non scriverlo», e in due passi con «Scrivi»; Sara € 155,68 e Teo € 25,80 in tutte e tre, cambia solo chi riceve', function () {
    // Ines sola all'80 %, Ines esce il 1/7 e Sara entra con la sua quota, Teo il 1/9 al 20 %. La parte di Sara e di Teo dei 18148 di Ines dopo il 1/7 è la stessa (15568 e 2580, i conti del primo
    // test): con «Scrivi» li ha pagati Sara, con il conguaglio; con «Non scriverlo» sono nelle rate di Ines (Sara e Teo «pagata da Ines»); in due passi (la fine di Ines senza chi entra, con
    // «Scrivi», poi l'inizio di Sara il 1/7) li ha pagati Ugo con la coppia (Sara e Teo «pagata da Ugo»). Era: con «Scrivi» Sara 18148 e Teo niente.
    foreach (['scrivi' => 'Sara', 'non_scrivere' => 'Ines', 'due_passi' => 'Ugo'] as $come => $chiRiceve) {
        $s = pcc358Scenario($this, [['Ines', 80]]);
        if ($come === 'due_passi') {
            fclRegistra($this, $s, ['quota' => 80.0] + fclModulo($s, null, '2026-07-01') + ['conguaglio' => 'scrivi']);
            pcc361Inizio($this, $s, 'Sara', '2026-07-01', 80);
        } else {
            pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', $come);
        }
        pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);

        $c = fclProspetto($s)['conduttori'];
        expect([$c['sara'][0], $c['teo'][0]])->toBe([15568, 2580], $come)
            ->and(pcc360PagateDa($s, 'Teo'))->toBe([$chiRiceve => 2580], $come);
    }
});

it('Coda 364 — i controlli: senza nessuno entrato dopo il prospetto resta com\'era, e chi ha preso il posto del coinquilino con un altro cambio (Luca → Irma il 1/10) non divide i giorni di Ines', function () {
    // Ines 50 e Luca 50, le rate 18000 e 18000, il cambio Ines → Sara con «Scrivi» il 1/7: la coppia 9074 a Sara, tutta sua. Poi Luca → Irma il 1/10: 18000 × 92/365 = 4536,99 → 4537, e Irma
    // ha preso il posto di Luca, il coinquilino che restava al cambio di Ines: i giorni di Ines sono di Sara. Con «Scrivi» la coppia di Luca è nei saldi, con «Non scriverlo» Irma la rimborsa a
    // Luca. Se Irma dividesse con Sara i giorni di Ines (50 × 184 e 50 × 92): Sara 6049, Irma 3025 in più dei suoi.
    $s = pcc358Scenario($this, [['Ines', 50], ['Luca', 50]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [8926, 8926, 0, 181],
        'luca' => [18000, 18000, 0, 365],
        'sara' => [9074, 0, 0, 184],
    ])->and(pcc361Sezione($s, 'Sara'))->toBe([9074, 9074, []]);

    foreach (['scrivi', 'non_scrivere'] as $scelta) {
        $s = pcc358Scenario($this, [['Ines', 50], ['Luca', 50]]);
        pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
        pcc360Cambio($this, $s, 'Luca', 'Irma', '2026-10-01', $scelta);

        expect(fclProspetto($s)['conduttori'])->toBe([
            'ines' => [8926, 8926, 0, 181],
            'irma' => [4537, 0, $scelta === 'scrivi' ? 0 : 4537, 92],
            'luca' => [13463, $scelta === 'scrivi' ? 13463 : 18000, $scelta === 'scrivi' ? 0 : -4537, 273],
            'sara' => [9074, 0, 0, 184],
        ], $scelta)->and(pcc361Sezione($s, 'Sara'))->toBe([9074, 9074, []], $scelta);
    }
});

it('Coda 364 — chi legge: l\'annullamento dell\'inizio di Teo dallo storico riporta il prospetto a com\'era; «Annulla il conguaglio» del cambio lo lascia fra le parti, e Teo non c\'è', function () {
    // Ines sola all'80 %, «Scrivi» il 1/7, Teo dal 1/9 al 20 % (il primo test: Sara 15568, Teo 2580). Annullato l'inizio di Teo, Teo non è più conduttore: Sara [18148, 0, 0] con il conguaglio,
    // come prima del suo inizio. Con «Annulla il conguaglio» del cambio (e Teo che resta) le parti hanno regolato fra loro i giorni dopo il 1/7 (decisioni 43 e 46): il prospetto non li divide,
    // la voce resta nelle rate di Ines, 36000 per 365 giorni, e né Sara né Teo hanno una sezione.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);
    fclAnnulla($this, $s, fclUltimo($s))->assertStatus(302);
    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [17852, 17852, 0, 181],
        'sara' => [18148, 0, 0, 184],
    ])->and(pcc361Sezione($s, 'Sara'))->toBe([18148, 18148, []]);

    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    $cambio = fclUltimo($s);
    pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $cambio]), ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro'])
        ->assertStatus(302);
    expect(fclSaldi($s))->toBe([])
        ->and(fclProspetto($s)['conduttori'])->toBe(['ines' => [36000, 36000, 0, 365]]);
});

it('Coda 364 — due cambi con «Scrivi» (Ines → Sara il 1/7, Sara → Pia il 1/10) e Teo dal 1/9 al 20 %: Teo rimborsa a Sara settembre, € 6,84, e a Pia da ottobre, € 18,15', function () {
    // Ines sola all'80 %: la coppia a Sara 18148; il secondo cambio la divide per giorni, 92 e 92: a Sara 9074 (dal 1/7 al 30/9), a Pia 9074 (dal 1/10). Ognuno dei due pezzi si divide fra chi c'è nei
    // suoi giorni: Sara 80 × 92 = 7360 e Teo 20 × 30 = 600 (su 7960): 8390,03 e 683,97 → 8390 e 684; Pia 80 × 92 = 7360 e Teo 20 × 92 = 1840 (su 9200): 7259,2 e 1814,8 → 7259 e 1815. Teo
    // al secondo cambio era già lì, ma non è un coinquilino di Ines: i giorni di Pia sono quelli di Ines, e Teo li divide come quelli di Sara (letto al secondo cambio, Teo resterebbe con 684).
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Pia' => 9074, 'Sara' => 9074]);

    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [17852, 17852, 0, 181],
        'pia' => [7259, 0, -1815, 92],
        'sara' => [8390, 0, -684, 92],
        'teo' => [2499, 0, 2499, 122],
    ])->and(pcc360PagateDa($s, 'Teo'))->toBe(['Pia' => 1815, 'Sara' => 684])
        ->and(pcc361Sezione($s, 'Pia'))->toBe([7259, 9074, ['Teo' => -1815]]);
});

// --- Coda 365: due cambi in fila, «Scrivi» poi «Non scriverlo», con un conduttore entrato fra i due ------------------------------------------------------

it('Coda 365 — due cambi, «Scrivi» poi «Non scriverlo» (Ines → Sara il 1/7, Sara → Pia il 1/10), e Teo dal 1/9 al 20 %: Teo rimborsa a Sara anche i giorni da ottobre, € 24,99, e Pia € 72,59, come con «Scrivi» due volte', function () {
    // Ines sola all'80 %: la coppia a Sara 18148 (36000 × 184/365 = 18147,95), nel Wallet. Il secondo cambio divide i giorni di Sara 92 : 92, 9074 e 9074; con «Non scriverlo» la sua coppia resta
    // fra le parti (il registro: `regolato_fuori`), e i giorni dal 1/10 restano nel conguaglio di Sara, che Pia le rimborsa. Il pezzo di Sara, 1/7–30/9: Sara 80 × 92 = 7360 e Teo 20 × 30 = 600
    // (su 7960), 8390,03 e 683,97 → 8390 e 684. Il pezzo dal 1/10: chi non divide si legge al primo cambio della catena (i coinquilini di Ines il 30/6: nessuno), come per il pezzo di Sara; Teo
    // (20 × 92 = 1840) e Pia (80 × 92 = 7360), su 9200: 1814,8 e 7259,2 → 1815 e 7259. Le cifre del test di prima, con «Scrivi» due volte: la scelta dice se la coppia passa dal Wallet, non
    // quanto paga ciascuno. Era: Teo, entrato il 1/9, letto al secondo cambio come «coinquilino di Sara», e Pia 9074, Teo 684 (€ 18,15 di Teo a carico di Pia).
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);
    fclPersona($s, 'Pia');
    $secondo = ['quota' => 80.0] + fclModulo($s, $s['persone']['Pia'], '2026-10-01', [], 'Sara');
    expect(fclAnteprima($this, $s, $secondo)['rate']['scelta_del_conguaglio'] ?? false)->toBeTrue();
    $cambio = fclRegistra($this, $s, $secondo + ['conguaglio' => 'non_scrivere'])->fresh();
    expect($cambio->registro['conguaglio']['scelta'] ?? null)->toBe('non_scritto')
        ->and(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148]);

    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [17852, 17852, 0, 181],
        'pia' => [7259, 0, 7259, 92],
        'sara' => [8390, 0, -9758, 92],
        'teo' => [2499, 0, 2499, 122],
    ], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Pia'))->toBe(['Sara' => 7259])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 2499])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([8390, 18148, ['Teo' => -684]]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Pia € 72,59')
        ->and($stampa)->toContain('Totale a carico di Teo € 24,99')
        ->and($stampa)->toContain('Totale a carico di Sara € 83,90')
        ->and($stampa)->not->toContain('Totale a carico di Pia € 90,74')
        ->and($stampa)->not->toContain('Totale a carico di Teo € 6,84');
});

it('Coda 365 — lo stesso con il coinquilino Luca (Ines 40 e Luca 40): Pia € 30,25, Teo € 21,48, e Luca, coinquilino di Ines al primo cambio, resta con le sue rate', function () {
    // Le rate 18000 e 18000. La coppia a Sara 9074 (18000 × 184/365 = 9073,97); il secondo cambio 4537 e 4537. Chi non divide, al primo cambio: Luca. Il pezzo di Sara: Sara 40 × 92 = 3680, Teo
    // 20 × 30 = 600 (su 4280): 3900,97 e 636,03 → 3901 e 636. Dal 1/10: Teo 20 × 92 = 1840, Pia 40 × 92 = 3680 (su 5520): 1512,33 e 3024,67 → 1512 e 3025. Teo 636 + 1512 = 2148 per 30 + 92 giorni.
    // Era: Pia 4537, Teo 636 (€ 15,12 di Teo a carico di Pia).
    $s = pcc358Scenario($this, [['Ines', 40], ['Luca', 40]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'non_scrivere');

    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [8926, 8926, 0, 181],
        'luca' => [18000, 18000, 0, 365],
        'pia' => [3025, 0, 3025, 92],
        'sara' => [3901, 0, -5173, 92],
        'teo' => [2148, 0, 2148, 122],
    ])->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 2148])
        ->and(pcc360PagateDa($s, 'Luca'))->toBe([]);
});

it('Coda 365 — lo stesso con il rientro di Ines il 1/9 al 20 % al posto dell\'inizio di Teo: Ines rimborsa a Sara anche i giorni da ottobre, € 24,99, e Pia € 72,59', function () {
    // Come il primo test, con Ines al posto di Teo (il rientro, come il test del rientro della Coda 364): chi non divide si legge al primo cambio, fra i coinquilini di Ines il 30/6 (chi esce
    // non è un suo coinquilino), e Ines divide i due pezzi: 684 e 1815, i conti del primo test. Ines [17852 + 684 + 1815 = 20351, nelle rate 17852, da rimborsare 2499] per 181 + 30 + 92 giorni.
    // Era: Ines, con la riga del rientro il 30/9, letta come «coinquilina di Sara» al secondo cambio, e Pia 9074, Ines 684.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc361Inizio($this, $s, 'Ines', '2026-09-01', 20);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'non_scrivere');

    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [20351, 17852, 2499, 303],
        'pia' => [7259, 0, 7259, 92],
        'sara' => [8390, 0, -9758, 92],
    ])->and(pcc360PagateDa($s, 'Ines'))->toBe(['Sara' => 2499]);
});

it('Coda 365 — la guardia del confine: senza il primo cambio (Ines resta, Teo dal 1/9, Ines → Pia il 1/10) Teo è coinquilino di Ines e non divide, con «Non scriverlo» e con «Scrivi»', function () {
    // La lettura della Coda 358 (decisione 115.3, il test con Elsa associata dopo): il giorno prima del cambio Teo ha una riga d'inquilino, e i suoi giorni dal 1/9 sono nelle rate di Ines, che è
    // rimasta inquilina fino al 30/9. Il cambio divide la rata di Ines 273 : 92, 26926,03 e 9073,97 → 26926 e 9074, tutti a Pia: con «Non scriverlo» glieli rimborsa, con «Scrivi» sono nel Wallet.
    // La catena non ha un cambio prima: la correzione della Coda 365 non la tocca.
    foreach (['non_scrivere', 'scrivi'] as $scelta) {
        $s = pcc358Scenario($this, [['Ines', 80]]);
        pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);
        pcc360Cambio($this, $s, 'Ines', 'Pia', '2026-10-01', $scelta);

        expect(fclProspetto($s)['conduttori'])->toBe($scelta === 'scrivi'
            ? ['ines' => [26926, 26926, 0, 273], 'pia' => [9074, 0, 0, 92]]
            : ['ines' => [26926, 36000, -9074, 273], 'pia' => [9074, 0, 9074, 92]], $scelta)
            ->and(fclSaldi($s))->toBe($scelta === 'scrivi' ? ['Ines' => -9074, 'Pia' => 9074] : [], $scelta);
    }
});

it('Coda 365 — il controllo: Teo entra il 1/11, dopo il secondo cambio con «Non scriverlo»: il 30/9 Teo non c\'era, e divide i giorni di Pia, € 12,90', function () {
    // Il pezzo di Sara, 1/7–30/9, è tutto suo (9074). Dal 1/10: Pia 80 × 92 = 7360, Teo 20 × 61 = 1220 (su 8580): 7783,76 e 1290,24 → 7784 e 1290. Uguale prima e dopo la correzione: il secondo
    // cambio e il primo non hanno coinquilini.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'non_scrivere');
    pcc361Inizio($this, $s, 'Teo', '2026-11-01', 20);

    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [17852, 17852, 0, 181],
        'pia' => [7784, 0, 7784, 92],
        'sara' => [9074, 0, -9074, 92],
        'teo' => [1290, 0, 1290, 61],
    ])->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 1290]);
});

it('Coda 365 — senza coinquilini al primo cambio il pezzo dopo il secondo si divide in una volta sola, come prima: con i giorni coperti e scoperti a metà, il centesimo del pareggio va a chi c\'è', function () {
    // Scritto dopo il codice (il rosso è la mutazione che lascia a chi non divide la lista vuota invece di toglierla). La voce € 1.200,20 al 30 %: 36006, tutto di Ines; il cambio
    // 36006 × 184/365 = 18150,97 → 18151 a Sara; il secondo cambio 92 : 92, 9075,5 → 9076 a Sara (a pari resto chi esce) e 9075 dal 1/10. Pia è conduttrice dal 1/10 al 15/11 (46 giorni),
    // poi nessuno (46): una divisione sola fra i giorni con un conduttore e quelli senza, i conduttori prima, 4537,5 e 4537,5 → Pia 4538, senza conduttore 4537 (da restituire a Sara). Divisa
    // prima fra giorni coperti e scoperti (la scoperta prima) sarebbe Pia 4537 e 4538.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]], 'importo' => 120020]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    fclPersona($s, 'Sara');
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'non_scrivere');
    pcc366Fine($this, $s, 'Pia', '2026-11-16');

    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [17855, 17855, 0, 181],
        'pia' => [4538, 0, 4538, 46],
        'sara' => [9076, 0, -9075, 92],
    ], 'senza' => [4537, 46]])->and(pcc360PagateDa($s, 'Pia'))->toBe(['Sara' => 4538]);
});

it('Coda 365 — la guardia della fine senza chi entra: dopo il cambio «Scrivi», la fine di Sara con «Non scriverlo» divide i giorni dopo la sua uscita come la fine di Ines senza il cambio', function () {
    // Scritto dopo il codice (il rosso è la mutazione che legge chi non divide al primo cambio anche nella fine senza chi entra). Ines 40 e Luca 40, le rate 18000 e 18000; Luca → Irma il 1/10 con
    // «Non scriverlo»; la fine di chi ha i giorni di Ines il 1/10 con «Non scriverlo», registrata dopo (Luca non c'è più quel giorno: nessun coinquilino resta); Teo dal 1/11 al 20 %. La correzione
    // della Coda 365 vale per il cambio con chi entra: la fine senza chi entra divide fra tutti i conduttori, con il primo cambio e senza. I giorni di Luca dopo il 1/10, 18000 × 92/365 = 4536,99 →
    // 4537, e quelli di Ines (con il cambio, la metà della coppia di Sara, 9074 : 2; senza, 18000 × 92/365) sono 4537 tutti e due, fra Irma 40 × 92 = 3680 e Teo 20 × 61 = 1220 (su 4900): 3407,38 e
    // 1129,62 → 3407 e 1130. Se Irma, che ha preso il posto di Luca, non dividesse i giorni di Ines dove c'è Teo: Irma 1529 per ottobre, Teo 3008.
    foreach (['con il cambio' => 'Sara', 'senza il cambio' => 'Ines'] as $come => $esce) {
        $s = pcc358Scenario($this, [['Ines', 40], ['Luca', 40]]);
        if ($esce === 'Sara') {
            pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
        }
        pcc360Cambio($this, $s, 'Luca', 'Irma', '2026-10-01', 'non_scrivere');
        pcc366Fine($this, $s, $esce, '2026-10-01', 'non_scrivere');
        pcc361Inizio($this, $s, 'Teo', '2026-11-01', 20);

        expect(pcc360PagateDa($s, 'Irma'))->toBe(collect(['Luca' => 3407, $esce => 3407])->sortKeys()->all(), $come)
            ->and(pcc360PagateDa($s, 'Teo'))->toBe(collect(['Luca' => 1130, $esce => 1130])->sortKeys()->all(), $come);
    }
});

it('Coda 364 — il rientro: dopo il cambio Ines → Sara con «Scrivi», Ines torna il 1/11 al 20 %: i suoi giorni da novembre li ha pagati Sara con il conguaglio, e Ines glieli rimborsa, € 13,89', function () {
    // Ines sola all'80 %, la coppia a Sara 18148. Dal 1/11 Ines è di nuovo conduttrice, al 20 %: Sara 80 × 184 = 14720, Ines 20 × 61 = 1220 (su 15940): 16759,01 e 1388,99 → 16759 e 1389. Ines
    // [17852 + 1389 = 19241, nelle rate 17852, da rimborsare 1389] per 181 + 61 giorni; Sara [16759, 0, − 1389]. Era: Sara 18148, e Ines solo i suoi 181 giorni.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    // Il pannello del rientro lo dice come dopo una fine «Scrivi» (decisione 77): «è già nelle rate di Ines» sarebbe falso, i giorni dopo il 1/7 li ha presi Sara.
    $frasi = pcc361Inizio($this, $s, 'Ines', '2026-11-01', 20);
    expect($frasi)->toContain('La parte a carico dell\'inquilino dal 1 novembre 2026 l\'ha presa Sara con il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026, e Ines gliela rimborsa con il prospetto degli oneri accessori.')
        ->and($frasi)->not->toContain('è già nelle rate di Ines');

    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [19241, 17852, 1389, 242],
        'sara' => [16759, 0, -1389, 184],
    ])->and(pcc360PagateDa($s, 'Ines'))->toBe(['Sara' => 1389]);

    // Con due cambi in fila (Sara → Pia il 1/10, con la quota di Sara) i giorni del rientro li ha presi Pia, con il cambio di Sara: la coppia di Sara divisa 92 : 92, a Pia
    // 9074; Pia 80 × 92 = 7360 e Ines 20 × 61 = 1220 (su 8580): 7783,76 e 1290,24 → 7784 e 1290. Ines [17852 + 1290 = 19142, 17852, 1290]; Pia [7784, 0, − 1290]; Sara [9074, 0, 0].
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'scrivi');
    $frasi = pcc361Inizio($this, $s, 'Ines', '2026-11-01', 20);
    expect($frasi)->toContain('La parte a carico dell\'inquilino dal 1 novembre 2026 l\'ha presa Pia con il conguaglio scritto alla fine locazione di Sara del 1 ottobre 2026, e Ines gliela rimborsa con il prospetto degli oneri accessori.')
        ->and(fclProspetto($s)['conduttori'])->toBe([
            'ines' => [19142, 17852, 1290, 242],
            'pia' => [7784, 0, -1290, 92],
            'sara' => [9074, 0, 0, 92],
        ]);
});

it('Coda 364 — con € 120,00 di già versato «dell\'unità» sulla voce: Sara ha pagato con il conguaglio il netto, € 163,33, e Teo le rimborsa la sua parte del netto, € 23,22', function () {
    // Il già versato «dell'unità» (decisione 17, D8), 12000 sulla «Spese generali» prima della generazione: la parte dell'inquilino al lordo 36000, al netto (120000 − 12000) × 30 % = 32400, la
    // rata di Ines. Dopo il 1/7: al lordo 36000 × 184/365 = 18147,95 → 18148, al netto 32400 × 184/365 = 16333,15 → 16333, la coppia a Sara. Teo divide il netto, come nella strada A′: 16333 ×
    // 2440/17160 = 2322,41 → 2322, Sara 14010,59 → 14011. Sara: la voce al lordo 18148, il già versato − 1815, con il conguaglio 16333, da ricevere da Teo 2322: in tutto 14011. Ines: il netto dei
    // suoi giorni, 32400 − 16333 = 16067. Dividendo il lordo Teo avrebbe 2580.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->where('conti.nome', 'Spese generali')->value('conti.id');
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    expect(fclNetti($s)['Ines'])->toBe(32400);
    fclPersona($s, 'Sara');
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -16333, 'Sara' => 16333]);
    pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);

    expect(pcc361Sezione($s, 'Sara'))->toBe([14011, 16333, ['Teo' => -2322]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 2322])
        ->and(pcc361Sezione($s, 'Ines')[0])->toBe(16067)
        ->and(fclProspetto($s)['conduttori']['teo'])->toBe([2322, 0, 2322, 122]);
});

/**
 * Ines sola all'80 %, con l'«Affitto antenna»: un'entrata creata dalla pagina «Nuova voce», dell'importo dato (di partenza € 500,00) al 30 % all'inquilino sulla «Proprietà», con la
 * competenza dal 1/9 al 31/12; il piano generato, le rate da gennaio a giugno a giornale. Con `$cambio` il cambio Ines → Sara con «Scrivi» il 1/7: la voce è tutta dopo il cambio, e con
 * € 500,00 (− 15000 di Ines) la coppia di Sara è 18148 − 15000 = 3148.
 */
function pcc361ConLAntenna($test, string $importo = '500,00', bool $cambio = true): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
        'nome' => 'Affitto antenna', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'entrata', 'importo' => $importo, 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 70, 'percentuale_inquilino' => 30, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $antenna = \App\Models\Gestionale\Conto::where('nome', 'Affitto antenna')->where('piano_conto_id', $pc->id)->firstOrFail();
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => 120000], $antenna->id => ['importo' => (int) $antenna->importo]]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $antenna->id)->value('id');
    \App\Models\Gestionale\CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-09-01', 'al' => '2026-12-31', 'ordine' => 0]);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    if ($cambio) {
        expect(fclNetti($s)['Ines'])->toBe(21000);
        fclPersona($s, 'Sara');
        pcc360Cambio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
        expect(fclSaldi($s))->toBe(['Ines' => -3148, 'Sara' => 3148]);
    }

    return $s;
}

it('Coda 364 — un\'entrata dei soli mesi da settembre («Affitto antenna» € 500,00, 30 % all\'inquilino): nei giorni di Teo l\'entrata supera la spesa, e Sara gli restituisce € 4,20', function () {
    // Dal 1/9 Teo divide, al 20 %: le «Spese generali» Teo 2580 (il primo test), l'entrata 80 × 122 e 20 × 122: Sara − 12000, Teo − 3000. Teo 2580 − 3000 = − 420: a suo favore, e glieli
    // restituisce Sara. Sara: con il conguaglio 3148, da restituire a Teo 420, in tutto 3568 (15568 − 12000). Ines 17852, i suoi 181 giorni delle «Spese generali».
    $s = pcc361ConLAntenna($this);
    $frasi = pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);

    expect(pcc361Sezione($s, 'Sara'))->toBe([3568, 3148, ['Teo' => 420]])
        ->and(pcc361Sezione($s, 'Teo')[0])->toBe(-420)
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => -420])
        ->and(pcc361Sezione($s, 'Ines')[0])->toBe(17852);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a favore di Teo € 4,20')
        ->and($stampa)->toContain('Totale a carico di Sara € 35,68')
        ->and($stampa)->toContain('da restituire da Sara a Teo: la parte di Teo dei giorni presi con il conguaglio, in cui l\'entrata supera la spesa € 4,20')
        ->and($stampa)->toContain('da restituire a Teo: la parte di Teo dei giorni presi con il conguaglio del cambio d\'inquilino del 01/07/2026');
    // La frase dell'inizio dice il verso del prospetto: è Sara che restituisce la differenza a Teo. Era: «li rimborsa Teo a Sara».
    expect($frasi)->toContain('i giorni dopo la sua uscita li ha presi con il conguaglio Sara; dal giorno dell\'inizio l\'entrata supera la spesa, e Sara restituisce la differenza a Teo')
        ->and($frasi)->not->toContain('li rimborsa Teo a Sara');
});

it('Coda 364 — lo stesso con il rientro di Ines il 1/11 al 20 %: nei suoi giorni l\'entrata supera la spesa, e Sara le restituisce € 2,78', function () {
    // Le «Spese generali»: Sara 80 × 184 = 14720, Ines 20 × 61 = 1220 → Ines 1389 (il test del rientro). L'entrata, 1/9–31/12: Sara 80 × 122 = 9760, Ines 20 × 61 = 1220 (su 10980):
    // − 13333,33 e − 1666,67 → − 13333 e − 1667. Ines 1389 − 1667 = − 278, a suo favore. Il verso del pannello, sui giorni dal 1/11: 18148 × 61/184 − 15000 × 61/122 = 6016,46 − 7500 < 0.
    $s = pcc361ConLAntenna($this);
    $frasi = pcc361Inizio($this, $s, 'Ines', '2026-11-01', 20);

    // I giorni sono quelli delle voci sommati: 181 nelle sue rate, 61 delle «Spese generali» e 61 dell'entrata, pagate da Sara.
    expect(fclProspetto($s)['conduttori']['ines'])->toBe([17574, 17852, -278, 303])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([3426, 3148, ['Ines' => 278]]);
    expect($frasi)->toContain('La parte a carico dell\'inquilino dal 1 novembre 2026 l\'ha presa Sara con il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026, e in quei giorni l\'entrata supera la spesa: Sara restituisce la differenza a Ines.');
});

it('Coda 364 — il controllo del verso dopo una fine «Scrivi» senza chi entra (la strada B): la parte di chi entra nella coppia ridivisa, non i giorni senza le quote; Teo rimborsa a Ugo € 0,48', function () {
    // Scritto dopo il codice (il rosso è la mutazione che prende il verso sempre dal prospetto senza le quote). La fine di Ines il 1/7 con «Scrivi», senza chi entra: Ugo prende la coppia,
    // 18148 delle «Spese generali» e − 12300 dell'«Affitto antenna» (€ 410,00 al 30 %, dal 1/9), 5848. Elsa entra il 1/8 all'80 %, Teo il 1/9 al 20 %. Le spese dopo il 1/7: 153 giorni con un
    // conduttore e 31 senza, 15090,46 e 3057,54 → 15090 e 3058; fra Elsa 80 × 153 = 12240 e Teo 20 × 122 = 2440: Teo 2508,15 → 2508. L'entrata fra Elsa e Teo, 80 × 122 e 20 × 122: Teo − 2460.
    // Teo 2508 − 2460 = 48, da rimborsare a Ugo: lo dice la sua parte nella coppia ridivisa (48), e la frase. Senza le quote, sui giorni dal 1/9, 18148 × 122/184 − 12300 = − 267: un credito
    // che Teo non ha.
    $s = pcc361ConLAntenna($this, '410,00', false);
    fclRegistra($this, $s, ['quota' => 80.0] + fclModulo($s, null, '2026-07-01') + ['conguaglio' => 'scrivi']);
    expect(fclSaldi($s))->toBe(['Ines' => -5848, 'Ugo' => 5848]);
    pcc361Inizio($this, $s, 'Elsa', '2026-08-01', 80);
    $frasi = pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);

    expect($frasi)->toContain('i giorni dopo la sua uscita li ha presi con il conguaglio Ugo, e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
        ->and($frasi)->not->toContain('restituisce la differenza a Teo')
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 48])
        ->and(collect(\App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']->id)->where('tipo_passaggio', 'inizio_locazione')->latest('id')->firstOrFail()->registro['giorni_del_conduttore'])
            ->pluck('cifra')->all())->toBe([48]);
});

it('Coda 364 — due conduttori entrati dopo nei giorni di Sara (Teo dal 1/9 al 10 %, Elsa dal 1/10 al 10 %): ognuno rimborsa a Sara la sua parte, € 13,13 e € 9,90', function () {
    // Ines sola all'80 %, «Scrivi» il 1/7, la coppia a Sara 18148. Sara 80 × 184 = 14720, Teo 10 × 122 = 1220, Elsa 10 × 92 = 920 (su 16860): 15844,52, 1313,20 e 990,28 → Sara 15845 (il
    // centesimo al resto più alto), Teo 1313, Elsa 990. Nella sezione di Sara una riga per ciascuno.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-09-01', 10);
    pcc361Inizio($this, $s, 'Elsa', '2026-10-01', 10);

    expect(fclProspetto($s)['conduttori'])->toBe([
        'elsa' => [990, 0, 990, 92],
        'ines' => [17852, 17852, 0, 181],
        'sara' => [15845, 0, -2303, 184],
        'teo' => [1313, 0, 1313, 122],
    ])->and(pcc361Sezione($s, 'Sara'))->toBe([15845, 18148, ['Teo' => -1313, 'Elsa' => -990]]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('a favore di Sara: da ricevere da Teo, la parte di Teo dei giorni che ha pagato con il conguaglio € 13,13 a favore di Sara: da ricevere da Elsa, la parte di Elsa dei giorni che ha pagato con il conguaglio € 9,90');
});

it('Coda 364 — una voce piccola («Cancelleria» € 0,10, 3 centesimi all\'inquilino): la parte di Teo è zero, e nessuna delle due sezioni ha una voce da zero', function () {
    // La «Cancelleria» € 0,10 al 30 %: 3 centesimi di Ines; dopo il 1/7 3 × 184/365 = 1,51 → 2 a Sara. Teo 2 × 2440/17160 = 0,28 → 0, Sara 1,72 → 2. Le «Spese generali» come nel primo
    // test: Sara 15568 + 2, Teo 2580.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $cancelleria = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Cancelleria', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 10]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $cancelleria->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => 120000], $cancelleria->id => ['importo' => 10]]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    fclPersona($s, 'Sara');
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -18150, 'Sara' => 18150]);
    pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);

    $p = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->keyBy('nome');
    $voci = fn (string $nome) => collect($p[$nome]['voci'])->where('conto', 'Cancelleria')->map(fn ($v) => [(int) $v['importo'], $v['modo']])->values()->all();
    expect($voci('Teo'))->toBe([])
        ->and($voci('Sara'))->toBe([[2, 'conguaglio']])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([15570, 18150, ['Teo' => -2580]]);
});

// --- Coda 366: dopo «Annulla il conguaglio» del cambio, l'uscita «Scrivi» di chi era entrato (decisione 83.3) ---------------------------------------------

/** «Annulla il conguaglio» di un passaggio, dallo storico dell'Interno 1 (la rotta vera, con la nota). */
function pcc366Annulla($test, array $s, \App\Models\Gestionale\Subentro $passaggio): void
{
    $test->actingAs($test->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $passaggio]),
        ['nota_annullamento_conguaglio' => 'Le parti hanno regolato fra loro'])->assertStatus(302);
}

/** La fine della locazione di `$chi` senza chi entra, il giorno dato, dalle rotte vere: la scelta data, se l'anteprima la chiede (e solo se la chiede). */
function pcc366Fine($test, array $s, string $chi, string $il, string $scelta = ''): void
{
    $dati = ['quota' => (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, $chi))->value('quota')] + fclModulo($s, null, $il, [], $chi);
    expect((bool) (fclAnteprima($test, $s, $dati)['rate']['scelta_del_conguaglio'] ?? false))->toBe($scelta !== '');
    fclRegistra($test, $s, $dati + ($scelta !== '' ? ['conguaglio' => $scelta] : []));
}

/** La sezione di un conduttore nel prospetto: [totale, regolata fra le parti, la parte di chi è entrato dopo, per nome]. */
function pcc366Sezione(array $s, string $nome): array
{
    $c = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', $nome);

    return [(int) ($c['totale'] ?? 0), (int) ($c['regolato_fra_le_parti'] ?? 0), $c['entrati_dopo'] ?? []];
}

/**
 * Ines sola all'80 %, il cambio Ines → Sara il 1/7 con «Scrivi»; «Annulla il conguaglio» subito (`subito`), dopo l'inizio di Teo (`dopo`) o mai (null); Teo dal 1/8 al 31/8
 * al 20 % (l'inizio, poi la fine del 1/9, che non chiede la scelta: Teo non ha rate); poi Sara esce il 1/10 con «Scrivi», con la fine senza chi entra (`fine`) o con il cambio
 * Sara → Pia (`cambio`). Restituisce lo scenario e le frasi del pannello dell'inizio di Teo.
 */
function pcc366Storia($test, ?string $annulla, string $uscita = 'fine'): array
{
    $s = pcc358Scenario($test, [['Ines', 80]]);
    pcc360Cambio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    $cambio = fclUltimo($s);
    if ($annulla === 'subito') {
        pcc366Annulla($test, $s, $cambio);
    }
    $frasi = pcc361Inizio($test, $s, 'Teo', '2026-08-01', 20);
    if ($annulla === 'dopo') {
        pcc366Annulla($test, $s, $cambio);
    }
    pcc366Fine($test, $s, 'Teo', '2026-09-01');
    if ($uscita === 'fine') {
        pcc366Fine($test, $s, 'Sara', '2026-10-01', 'scrivi');
    } else {
        pcc360Cambio($test, $s, 'Sara', 'Pia', '2026-10-01', 'scrivi');
    }

    return [$s, $frasi];
}

it('Coda 366 — «Annulla il conguaglio» del cambio Ines → Sara, Teo dal 1/8 al 31/8 al 20 %, la fine di Sara il 1/10 con «Scrivi»: Teo rimborsa a Sara € 7,05, come con il conguaglio scritto, e la stampa dice «regolata fra le parti»', function () {
    // La coppia del cambio, 18148 (36000 × 184/365 = 18147,95), le parti l'hanno regolata fra loro; la fine di Sara la divide 92 : 92, 9074 e 9074 (saldi Sara − 9074, Ugo + 9074), e la catena
    // della decisione 83.3 la segue: il pezzo di Sara, 1/7–30/9, «regolato fra le parti», poi i giorni senza conduttore pagati da Ugo. Il pezzo di Sara si divide con chi c'è stato nei suoi giorni,
    // come quello con il conguaglio (decisione 117; la 84.1: le due catene danno gli stessi numeri): Sara 80 × 92 = 7360, Teo 20 × 31 = 620 (su 7980), 8369,0025 e 704,9975 → 8369 e 705, «pagata
    // da Sara». Era: Sara [9074, 0, 0] e Teo assente (Sara pagava € 7,05 di Teo).
    [$s] = pcc366Storia($this, 'subito');
    expect(fclSaldi($s))->toBe(['Sara' => -9074, 'Ugo' => 9074]);

    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [17852, 17852, 0, 181],
        'sara' => [8369, 0, -705, 92],
        'teo' => [705, 0, 705, 31],
    ], 'senza' => [9074, 92]])
        ->and(pcc366Sezione($s, 'Sara'))->toBe([8369, 9074, ['Teo' => -705]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 705]);

    // Il conguaglio è stato annullato: le righe di quel pezzo non dicono «con il conguaglio», dicono il modo del pezzo di Sara, con le parole della testa («con il cambio d'inquilino del …
    // regolato fra le parti»). Cambiate dopo la prima scrittura della Coda 366, che diceva «con la parte regolata fra le parti al cambio d'inquilino»: vera, ma nella voce di Sara si leggeva «la
    // parte di Teo dei giorni presi con la parte regolata fra le parti», tre volte «parte»; «regolato fra le parti» è del cambio, fra chi è uscito e chi è entrato, non della parte di Teo.
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Teo € 7,05')
        ->and($stampa)->toContain('pagata da Sara con il cambio d\'inquilino del 01/07/2026 regolato fra le parti')
        ->and($stampa)->toContain('da ricevere da Teo: la parte di Teo dei giorni presi con il cambio d\'inquilino del 01/07/2026 regolato fra le parti')
        ->and($stampa)->toContain('Totale a carico di Sara € 83,69 regolata fra le parti al cambio d\'inquilino € 90,74 a favore di Sara: da ricevere da Teo, la parte di Teo dei giorni che ha pagato con il cambio d\'inquilino regolato fra le parti € 7,05')
        ->and($stampa)->not->toContain('con la parte regolata fra le parti')
        ->and($stampa)->toContain('oppure chi è entrato con un cambio d\'inquilino regolato fra le parti, per i giorni di un conduttore entrato dopo')
        ->and($stampa)->not->toContain('con il conguaglio del cambio d\'inquilino del 01/07/2026')
        ->and($stampa)->not->toContain('dei giorni presi con il conguaglio')
        ->and($stampa)->not->toContain('dei giorni che ha pagato con il conguaglio')
        ->and($stampa)->not->toContain('oppure chi è entrato con il conguaglio di un cambio d\'inquilino');
});

it('Coda 366 — lo stesso con «Annulla il conguaglio» dopo l\'inizio di Teo: il pannello dell\'inizio aveva detto che Teo rimborsa Sara con il prospetto, e il prospetto lo fa, € 7,05', function () {
    // Le cifre del test di prima: l'ordine dell'annullamento non cambia la catena. Era: Teo assente, mentre il pannello del suo inizio, con il conguaglio ancora scritto, rimandava al prospetto.
    [$s, $frasi] = pcc366Storia($this, 'dopo');
    expect($frasi)->toContain('i giorni dopo la sua uscita li ha presi con il conguaglio Sara, e li rimborsa Teo a Sara, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Sara' => -9074, 'Ugo' => 9074]);

    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [17852, 17852, 0, 181],
        'sara' => [8369, 0, -705, 92],
        'teo' => [705, 0, 705, 31],
    ])->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 705]);
});

it('Coda 366 — lo stesso con Sara che esce con il cambio Sara → Pia il 1/10 con «Scrivi», al posto della fine: Teo rimborsa a Sara € 7,05, e Pia ha i suoi giorni con il conguaglio', function () {
    // Il cambio di Sara scrive la coppia dei giorni dopo il 1/10, 9074, a Pia (Sara − 9074, Pia + 9074): è la fine «Scrivi» della decisione 83.3. Il pezzo di Sara come nel primo test, 8369 e 705;
    // Pia [9074, 0, 0] con il conguaglio, sola nei suoi giorni. Era: Sara [9074, 0, 0] e Teo assente.
    [$s] = pcc366Storia($this, 'subito', 'cambio');
    expect(fclSaldi($s))->toBe(['Pia' => 9074, 'Sara' => -9074]);

    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [17852, 17852, 0, 181],
        'pia' => [9074, 0, 0, 92],
        'sara' => [8369, 0, -705, 92],
        'teo' => [705, 0, 705, 31],
    ], 'senza' => [0, 0]])->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 705]);
});

it('Coda 366 — Teo registrato dopo la fine di Sara, con le date di prima (dal 1/8, senza fine): Teo rimborsa a Sara agosto e settembre, € 12,90, e a Ugo i giorni da ottobre, € 90,74', function () {
    // «Annulla il conguaglio» del cambio, la fine di Sara il 1/10 con «Scrivi», poi l'inizio di Teo dal 1/8 al 20 % (la pagina lo accetta). Il pezzo di Sara: Sara 80 × 92 = 7360, Teo 20 × 61 = 1220
    // (su 8580), 7783,76 e 1290,24 → 7784 e 1290. Dopo il 1/10 Teo è solo: i 9074 della coppia di Ugo. Teo 1290 + 9074 = 10364 per 61 + 92 giorni. Era: Teo [9074] per 92 giorni, e Sara 9074.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($this, $s, fclUltimo($s));
    pcc366Fine($this, $s, 'Sara', '2026-10-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-08-01', 20);

    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [17852, 17852, 0, 181],
        'sara' => [7784, 0, -1290, 92],
        'teo' => [10364, 0, 10364, 153],
    ], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 1290, 'Ugo' => 9074])
        ->and(pcc366Sezione($s, 'Sara'))->toBe([7784, 9074, ['Teo' => -1290]]);
});

it('Coda 366 — con una spesa più grande (€ 3.000,00 all\'inquilino, Ines e Teo al 50 %, Teo dal 1/8 al 15/9): Teo rimborsa a Sara € 252,06', function () {
    // La voce € 10.000,00 al 30 %: 300000, tutto nelle rate di Ines. Il cambio: 300000 × 184/365 = 151232,88 → 151233; la fine di Sara 92 : 92, 75616,5 e 75616,5 → 75617 a Sara (a pari resto
    // a chi esce) e 75616 dopo (saldi Sara − 75616, Ugo + 75616). Il pezzo di Sara: Sara 50 × 92 = 4600, Teo 50 × 46 = 2300 (su 6900), 50411,33 e 25205,67 → 50411 e 25206. Era: Sara 75617.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50]], 'importo' => 1000000]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    fclPersona($s, 'Sara');
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($this, $s, fclUltimo($s));
    pcc361Inizio($this, $s, 'Teo', '2026-08-01', 50);
    pcc366Fine($this, $s, 'Teo', '2026-09-16');
    pcc366Fine($this, $s, 'Sara', '2026-10-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Sara' => -75616, 'Ugo' => 75616]);

    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [148767, 148767, 0, 181],
        'sara' => [50411, 0, -25206, 92],
        'teo' => [25206, 0, 25206, 46],
    ], 'senza' => [75616, 92]])->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 25206]);
});

it('Coda 366 — un\'entrata nei giorni di Sara («Affitto antenna» € 1.000,00 al 30 %, competenza 1/6–31/10): nei giorni di Teo l\'entrata supera la spesa, e Sara gli restituisce € 6,97', function () {
    // L'entrata creata dalla pagina «Nuova voce»: − 30000 di Ines, sui 153 giorni dal 1/6 al 31/10; la rata di Ines 36000 − 30000 = 6000. Il cambio del 1/7 la divide 30 : 123, − 5882,35 e
    // − 24117,65 → − 5882 e − 24118; la fine di Sara 92 : 31, − 18039,48 e − 6078,52 → − 18039 e − 6079 (il pezzo dopo il 1/10 è dei giorni senza conduttore, pagati da Ugo). Il pezzo di Sara
    // fra Sara 80 × 92 = 7360 e Teo 20 × 31 = 620 (su 7980): − 16637,47 e − 1401,53 → − 16637 e − 1402. Le «Spese generali» come nel primo test (Teo 705). Teo 705 − 1402 = − 697, a suo favore, e
    // glieli restituisce Sara. Sara: regolata fra le parti 9074 − 18039 = − 8965, la parte del conduttore entrato dopo + 697, in tutto − 8268. Era: Sara − 8965 e Teo assente.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
        'nome' => 'Affitto antenna', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'entrata', 'importo' => '1000,00', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 70, 'percentuale_inquilino' => 30, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $antenna = \App\Models\Gestionale\Conto::where('nome', 'Affitto antenna')->where('piano_conto_id', $pc->id)->firstOrFail();
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => 120000], $antenna->id => ['importo' => (int) $antenna->importo]]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $antenna->id)->value('id');
    \App\Models\Gestionale\CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-06-01', 'al' => '2026-10-31', 'ordine' => 0]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    expect(fclNetti($s)['Ines'])->toBe(6000);
    fclPersona($s, 'Sara');
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($this, $s, fclUltimo($s));
    pcc361Inizio($this, $s, 'Teo', '2026-08-01', 20);
    pcc366Fine($this, $s, 'Teo', '2026-09-01');
    pcc366Fine($this, $s, 'Sara', '2026-10-01', 'scrivi');

    expect(pcc366Sezione($s, 'Sara'))->toBe([-8268, -8965, ['Teo' => 697]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => -697])
        ->and(fclProspetto($s)['conduttori']['teo'])->toBe([-697, 0, -697, 62]);
    $stampa = fclStampa($s);
    // Le diciture del verso «da restituire» con le parole della testa, come nel primo test della Coda 366 (era «con la parte regolata fra le parti al cambio d'inquilino»).
    expect($stampa)->toContain('Totale a favore di Teo € 6,97')
        ->and($stampa)->toContain('da restituire da Sara a Teo: la parte di Teo dei giorni presi con il cambio d\'inquilino regolato fra le parti, in cui l\'entrata supera la spesa € 6,97')
        ->and($stampa)->toContain('da restituire a Teo: la parte di Teo dei giorni presi con il cambio d\'inquilino del 01/07/2026 regolato fra le parti')
        ->and($stampa)->not->toContain('dei giorni presi con il conguaglio');
});

it('Coda 366 — i due modi nello stesso prospetto: il cambio Ines → Sara con il conguaglio scritto, il cambio Sara → Pia regolato fra le parti, Teo nei giorni di tutte e due: ogni voce dice il suo modo, e la testa li dice tutti e due', function () {
    // Scritto dopo il codice (il rosso è sul codice di prima: Pia 6016 e Teo 684). Ines sola all'80 %, «Scrivi» il 1/7 (Ines − 18148, Sara + 18148); Teo dal 1/9 al 30/11 al 20 %; Sara → Pia il
    // 1/10 con «Scrivi», poi «Annulla il conguaglio» di quel cambio; la fine di Pia il 1/12 con «Scrivi». Il pezzo di Sara con il conguaglio, come nella Coda 364: 8390 e 684. I 9074 dal 1/10 la
    // fine di Pia li divide 61 : 31, 6016,46 e 3057,54 → 6016 (il pezzo di Pia, regolato fra le parti) e 3058 (dicembre, senza conduttore, pagati da Ugo: Pia − 3058, Ugo + 3058); il pezzo di Pia
    // fra Pia 80 × 61 = 4880 e Teo 20 × 61 = 1220, 4812,8 e 1203,2 → 4813 e 1203. Teo 684 + 1203 = 1887 per 30 + 61 giorni.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'scrivi');
    pcc366Annulla($this, $s, fclUltimo($s));
    pcc366Fine($this, $s, 'Teo', '2026-12-01');
    pcc366Fine($this, $s, 'Pia', '2026-12-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Pia' => -3058, 'Sara' => 18148, 'Ugo' => 3058]);

    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [17852, 17852, 0, 181],
        'pia' => [4813, 0, -1203, 61],
        'sara' => [8390, 0, -684, 92],
        'teo' => [1887, 0, 1887, 91],
    ], 'senza' => [3058, 31]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Pia' => 1203, 'Sara' => 684])
        ->and(pcc366Sezione($s, 'Pia'))->toBe([4813, 6016, ['Teo' => -1203]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([8390, 9074, ['Teo' => -684]]);
    // Le diciture del pezzo regolato con le parole della testa, come nel primo test della Coda 366 (era «con la parte regolata fra le parti al cambio d'inquilino»).
    $stampa = fclStampa($s);
    expect($stampa)->toContain('pagata da Sara con il conguaglio del cambio d\'inquilino del 01/07/2026')
        ->and($stampa)->toContain('pagata da Pia con il cambio d\'inquilino del 01/10/2026 regolato fra le parti')
        ->and($stampa)->toContain('a favore di Sara: da ricevere da Teo, la parte di Teo dei giorni che ha pagato con il conguaglio € 6,84')
        ->and($stampa)->toContain('a favore di Pia: da ricevere da Teo, la parte di Teo dei giorni che ha pagato con il cambio d\'inquilino regolato fra le parti € 12,03')
        ->and($stampa)->toContain('oppure chi è entrato con il conguaglio di un cambio d\'inquilino o con un cambio d\'inquilino regolato fra le parti, per i giorni di un conduttore entrato dopo');
});

it('Coda 366 — i controlli: senza «Annulla il conguaglio» il pezzo di Sara è con il conguaglio, con le stesse cifre (la fine di Sara e il cambio Sara → Pia); e prima della fine di Sara il prospetto non va oltre l\'accordo fra le parti', function () {
    // Con il conguaglio scritto la Coda 364 divide già il pezzo di Sara: 8369 e 705, i conti del primo test; la coppia del cambio è nel Wallet (Ines − 18148), quella dell'uscita di Sara pure.
    foreach (['fine' => ['Ines' => -18148, 'Sara' => 9074, 'Ugo' => 9074], 'cambio' => ['Ines' => -18148, 'Pia' => 9074, 'Sara' => 9074]] as $uscita => $saldi) {
        [$s] = pcc366Storia($this, null, $uscita);
        expect(fclSaldi($s))->toBe($saldi, $uscita)
            ->and(fclProspetto($s)['conduttori']['sara'])->toBe([8369, 0, -705, 92], $uscita)
            ->and(fclProspetto($s)['conduttori']['teo'])->toBe([705, 0, 705, 31], $uscita)
            ->and(pcc361Sezione($s, 'Sara'))->toBe([8369, 9074, ['Teo' => -705]], $uscita);
        $stampa = fclStampa($s);
        expect($stampa)->toContain('pagata da Sara con il conguaglio del cambio d\'inquilino del 01/07/2026')
            ->and($stampa)->toContain('a favore di Sara: da ricevere da Teo, la parte di Teo dei giorni che ha pagato con il conguaglio € 7,05')
            // Senza l'annullamento nessuna riga dice il modo regolato (le parole sono quelle di dopo la prima scrittura della Coda 366: prima «con la parte regolata fra le parti»).
            ->and($stampa)->not->toContain('regolato fra le parti')
            ->and($stampa)->not->toContain('regolata fra le parti');
    }

    // Decisioni 43 e 46: dopo «Annulla il conguaglio», senza una fine «Scrivi» di Sara, le parti hanno regolato fra loro i giorni dopo il 1/7, e la voce resta nelle rate di Ines per 365 giorni;
    // Teo, entrato e uscito nei giorni di Sara, non ha una sezione.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($this, $s, fclUltimo($s));
    pcc361Inizio($this, $s, 'Teo', '2026-08-01', 20);
    pcc366Fine($this, $s, 'Teo', '2026-09-01');
    expect(fclSaldi($s))->toBe([])
        ->and(fclProspetto($s)['conduttori'])->toBe(['ines' => [36000, 36000, 0, 365]]);
});

it('Coda 366 — il controllo con Teo che resta: la fine di Sara ha la regola del piano, con il conguaglio annullato e senza, e il prospetto è lo stesso', function () {
    // Teo dal 1/8 al 20 %, senza fine: alla fine di Sara del 1/10 resta un coinquilino, e la parte dell'inquilino si legge con la regola del piano, per quota e per giorni sull'intera voce:
    // Ines 80 × 181 = 14480, Sara 80 × 92 = 7360, Teo 20 × 153 = 3060 (su 24900): 20934,94, 10640,96 e 4424,10 → 20935, 10641 e 4424. Uguale nelle due catene, prima e dopo la correzione.
    foreach ([true, false] as $annulla) {
        $s = pcc358Scenario($this, [['Ines', 80]]);
        pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
        if ($annulla) {
            pcc366Annulla($this, $s, fclUltimo($s));
        }
        pcc361Inizio($this, $s, 'Teo', '2026-08-01', 20);
        pcc366Fine($this, $s, 'Sara', '2026-10-01');

        expect(array_map(fn (array $c) => $c[0], fclProspetto($s)['conduttori']))->toBe(['ines' => 20935, 'sara' => 10641, 'teo' => 4424], $annulla ? 'annullato' : 'scritto');
    }
});

// --- Coda 367: due cambi con «Scrivi» e «Annulla il conguaglio» del secondo, senza una fine «Scrivi» dopo (decisioni 43, 46 e 83.3) ------------------------

/**
 * Ines sola all'80 % (con il già versato «dell'unità» dato, sulla «Spese generali», prima della generazione), le rate da gennaio a giugno a giornale; il cambio Ines → Sara il 1/7
 * con «Scrivi» (la coppia 18148), con `$teo` l'inizio di Teo il 1/9 al 20 %, il cambio Sara → Pia il 1/10 con «Scrivi» (la coppia 9074) e «Annulla il conguaglio» di quel cambio.
 */
function pcc367Storia($test, bool $teo = false, int $versato = 0): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    if ($versato > 0) {
        $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->where('conti.nome', 'Spese generali')->value('conti.id');
        DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id,
            'anagrafica_id' => null, 'importo_cents' => $versato, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    }
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    pcc360Cambio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    if ($teo) {
        pcc361Inizio($test, $s, 'Teo', '2026-09-01', 20);
    }
    pcc360Cambio($test, $s, 'Sara', 'Pia', '2026-10-01', 'scrivi');
    pcc366Annulla($test, $s, fclUltimo($s));

    return $s;
}

/**
 * Ines sola all'80 %, con il «Riscaldamento»: una spesa creata dalla pagina «Nuova voce», € 600,00 al 30 % all'inquilino sulla «Proprietà» (18000), con la competenza dal giorno
 * dato (di partenza il 1/11) al 31/12; il piano generato, le rate da gennaio a giugno a giornale, e il cambio Ines → Sara con «Scrivi» il 1/7: la coppia 18148 + 18000 = 36148.
 */
function pcc367ConLaSpesa($test, string $dal = '2026-11-01'): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
        'nome' => 'Riscaldamento', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => '600,00', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 70, 'percentuale_inquilino' => 30, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $riscaldamento = \App\Models\Gestionale\Conto::where('nome', 'Riscaldamento')->where('piano_conto_id', $pc->id)->firstOrFail();
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->syncWithoutDetaching([$spese->id => ['importo' => 120000], $riscaldamento->id => ['importo' => (int) $riscaldamento->importo]]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $riscaldamento->id)->value('id');
    \App\Models\Gestionale\CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => $dal, 'al' => '2026-12-31', 'ordine' => 0]);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    pcc360Cambio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -36148, 'Sara' => 36148]);

    return $s;
}

it('Coda 367 — due cambi «Scrivi» (Ines → Sara il 1/7, Sara → Pia il 1/10) e «Annulla il conguaglio» del secondo: Pia non rimborsa a Sara i € 90,74 che le parti hanno regolato', function () {
    // La coppia del primo cambio 36000 × 184/365 = 18147,95 → 18148 (Ines − 18148, Sara + 18148); quella del secondo, 9074, l'annullamento la toglie. Il pezzo di Sara, 18148 dal 1/7 al 31/12:
    // fino al 30/9 nessun altro conduttore, dal 1/10 le parti hanno regolato fra loro (decisioni 43 e 46, e senza una fine «Scrivi» di Pia la 83.3 non vale): resta tutto nella voce di Sara, 184
    // giorni, come fa il prospetto quando si annulla il primo cambio. Era: Sara 9074 e Pia 9074 «da rimborsare» a Sara (18148 fra 80 × 92 e 80 × 92).
    $s = pcc367Storia($this);
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'sara' => [18148, 0, 0, 184],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([18148, 18148, []]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Sara € 181,48')
        ->and($stampa)->not->toContain('Totale a carico di Pia')
        ->and($stampa)->not->toContain('da ricevere da Pia');
});

it('Coda 367 — lo stesso con Teo dal 1/9 al 20 %: Teo rimborsa a Sara settembre, € 6,84, e né Teo né Pia i giorni regolati dal 1/10', function () {
    // Prima del 1/10: 18148 × 92/184 = 9074, fra Sara 80 × 92 = 7360 e Teo 20 × 30 = 600 (su 7960): 8390,03 e 683,97 → 8390 e 684. Dal 1/10 i 9074 restano a Sara. Sara 18148 − 684 = 17464.
    // Era: Sara 7784, Teo 2580 e Pia 7784 (18148 su 7360 + 2440 + 7360 = 17160).
    $s = pcc367Storia($this, teo: true);
    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [17852, 17852, 0, 181],
        'sara' => [17464, 0, -684, 184],
        'teo' => [684, 0, 684, 30],
    ])->and(pcc361Sezione($s, 'Sara'))->toBe([17464, 18148, ['Teo' => -684]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 684]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Teo € 6,84')
        ->and($stampa)->toContain('pagata da Sara con il conguaglio del cambio d\'inquilino del 01/07/2026')
        ->and($stampa)->not->toContain('Totale a carico di Pia');
});

it('Coda 367 — Elsa entra il 1/11 dopo l\'annullamento: il pannello del suo inizio dice che le parti hanno regolato fra loro, e il prospetto non la fa rimborsare', function () {
    // Era: Elsa 1389 «pagata da Sara» (18148 × 1220/15940 = 1388,99), Pia 8379, Sara 8380: il prospetto contraddiceva il pannello.
    $s = pcc367Storia($this);
    $frasi = pcc361Inizio($this, $s, 'Elsa', '2026-11-01', 20);
    // Coda 413: «la sua uscita» era quella di Ines, il cui conguaglio è scritto (Ines − 18148, Sara + 18148): le parti hanno regolato l'uscita di Sara, che ha i giorni con il conguaglio.
    expect($frasi)->toContain('quei giorni li ha presi con il conguaglio Sara, e il conguaglio dei giorni dopo l\'uscita di Sara le parti lo hanno regolato fra loro')
        ->and(fclProspetto($s)['conduttori'])->toBe([
            'ines' => [17852, 17852, 0, 181],
            'sara' => [18148, 0, 0, 184],
        ]);
});

it('Coda 367 — la fine di Pia il 1/12 con «Non scriverlo» dopo l\'annullamento: senza una fine «Scrivi» resta la lettura delle decisioni 43 e 46', function () {
    // Era: 18148 coperti 153 : scoperti 31 → 15090 e 3058; 15090 fra Sara 7360 e Pia 4880 → 9074 e 6016; Pia 6016 «da rimborsare» a Sara.
    $s = pcc367Storia($this);
    pcc366Fine($this, $s, 'Pia', '2026-12-01', 'non_scrivere');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(fclProspetto($s)['conduttori'])->toBe([
            'ines' => [17852, 17852, 0, 181],
            'sara' => [18148, 0, 0, 184],
        ]);
});

it('Coda 367 — con € 120,00 di già versato «dell\'unità» e Teo dal 1/9: Teo divide il netto dei giorni prima del 1/10, € 6,16', function () {
    // Il netto (120000 − 12000) × 30 % = 32400; la coppia del primo cambio 32400 × 184/365 = 16333,15 → 16333 (il già versato che passa, 18148 − 16333 = 1815). Prima del 1/10: 16333 × 92/184 =
    // 8166,5 → 8167 (a pari resto chi esce), fra Sara 7360 e Teo 600: 7551,40 e 615,60 → 7551 e 616. Sara 16333 − 616 = 15717; Ines 32400 − 16333 = 16067. Era: Sara 7005, Teo 2323, Pia 7005.
    $s = pcc367Storia($this, teo: true, versato: 12000);
    expect(pcc361Sezione($s, 'Sara'))->toBe([15717, 16333, ['Teo' => -616]])
        ->and(fclProspetto($s)['conduttori'])->toBe([
            'ines' => [16067, 16067, 0, 181],
            'sara' => [15717, 0, -616, 184],
            'teo' => [616, 0, 616, 30],
        ]);
});

it('Coda 367 — la guardia della decisione 83.3: con la fine di Pia «Scrivi» il 1/12 la catena continua, e il prospetto è quello di prima', function () {
    // Sara 9074 con il conguaglio (1/7–30/9); il pezzo di Pia 9074 × 61/92 = 6016,46 → 6016 regolato fra le parti, dicembre 3058 senza conduttore, pagato da Ugo con la coppia della fine
    // (36000 × 31/365 = 3057,53 → 3058).
    $s = pcc367Storia($this);
    pcc366Fine($this, $s, 'Pia', '2026-12-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Pia' => -3058, 'Sara' => 18148, 'Ugo' => 3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [6016, 0, 0, 61],
            'sara' => [9074, 0, 0, 92],
        ], 'senza' => [3058, 31]]);
});

it('Coda 367 — un\'entrata («Affitto antenna» € 500,00 al 30 %, 1/9–31/12) con Teo dal 1/9: Sara restituisce a Teo € 0,54 di settembre, e niente a Pia', function () {
    // Le spese prima del 1/10: Teo 684 (il secondo test). L'entrata, tutta di Sara con il conguaglio (− 15000), prima del 1/10: − 15000 × 30/122 = − 3688,52 → − 3689, fra Sara 80 × 30 e Teo
    // 20 × 30: − 2951,2 e − 737,8 → − 2951 e − 738. Teo 684 − 738 = − 54, a suo favore; Sara 18148 − 15000 − 684 + 738 = 3202, per 184 + 122 giorni.
    // Era: Sara restituiva a Pia € 12,65 dei giorni regolati (7784 − 9049) e a Teo € 4,20 (2580 − 3000).
    $s = pcc361ConLAntenna($this);
    pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'scrivi');
    pcc366Annulla($this, $s, fclUltimo($s));
    expect(fclSaldi($s))->toBe(['Ines' => -3148, 'Sara' => 3148])
        ->and(fclProspetto($s)['conduttori'])->toBe([
            'ines' => [17852, 17852, 0, 181],
            'sara' => [3202, 0, 54, 306],
            'teo' => [-54, 0, -54, 60],
        ])->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => -54]);
});

it('Coda 367 — una spesa dei soli mesi dopo il secondo cambio («Riscaldamento» € 600,00 al 30 %, 1/11–31/12): Pia non rimborsa a Sara la voce, tutta nei giorni regolati', function () {
    // La voce, 18000, è tutta dopo i due cambi: la coppia del primo la porta a Sara (18148 + 18000 = 36148), quella del secondo a Pia (9074 + 18000 = 27074), annullata. Il cambio annullato cade
    // prima della voce: la catena lo legge come quando cade nella voce, e senza una fine «Scrivi» dopo la voce resta a Sara, per 184 + 61 giorni. Era: Pia 27074 «da rimborsare» a Sara (la voce
    // la divideva Pia sola, più le spese dal 1/10).
    $s = pcc367ConLaSpesa($this);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -36148, 'Pia' => 27074, 'Sara' => 9074]);
    pcc366Annulla($this, $s, fclUltimo($s));
    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [17852, 17852, 0, 181],
        'sara' => [36148, 0, 0, 245],
    ])->and(pcc361Sezione($s, 'Sara'))->toBe([36148, 36148, []]);
});

it('Coda 367 — lo stesso, poi la fine di Pia «Scrivi» il 1/12: la catena della decisione 83.3 anche per la voce tutta dopo, Pia regolata e dicembre a Ugo', function () {
    // La fine di Pia scrive Pia − 12206, Ugo + 12206: le «Spese generali» di dicembre 3058 e il «Riscaldamento» di dicembre 18000 × 31/61 = 9147,54 → 9148. Il prospetto: Sara 9074 (1/7–30/9),
    // Pia regolata 6016 + 18000 × 30/61 = 8852,46 → 8852, in tutto 14868 per 61 + 30 giorni; senza conduttore 3058 + 9148 = 12206, pagati da Ugo. Era: Pia 8852 «da rimborsare» a Sara, Sara 18222, e
    // i 9148 di Ugo in nessuna sezione.
    $s = pcc367ConLaSpesa($this);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'scrivi');
    pcc366Annulla($this, $s, fclUltimo($s));
    pcc366Fine($this, $s, 'Pia', '2026-12-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -36148, 'Pia' => -12206, 'Sara' => 36148, 'Ugo' => 12206])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [14868, 0, 0, 91],
            'sara' => [9074, 0, 0, 92],
        ], 'senza' => [12206, 62]]);
});

it('Coda 367 — un\'entrata tutta dopo il secondo cambio (l\'«Affitto antenna» dal 1/9, il cambio Sara → Pia il 1/8, annullato), Teo dal 1/9: Sara non restituisce niente a Pia né a Teo', function () {
    // Le «Spese generali» prima del 1/8 sono di Sara sola (luglio, nessun altro conduttore); l'entrata, − 15000 dal 1/9, è tutta nei giorni regolati. Sara 18148 − 15000 = 3148, per 184 + 122
    // giorni. Era: Pia 945 e Teo − 420; con il solo taglio al giorno dell'accordo, Pia − 12000 e Teo − 3000 «da restituire» da Sara.
    $s = pcc361ConLAntenna($this);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-08-01', 'scrivi');
    pcc366Annulla($this, $s, fclUltimo($s));
    pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);
    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [17852, 17852, 0, 181],
        'sara' => [3148, 0, 0, 306],
    ]);
});

it('Coda 367 — il secondo cambio annullato nel primo giorno della voce («Riscaldamento» € 600,00 dal 1/10, il cambio Sara → Pia il 1/10), Teo dal 1/11: Sara tiene la voce, e Teo non divide', function () {
    // Il «Riscaldamento», 18000 dal 1/10 al 31/12, è tutto dopo il primo cambio (la coppia 18148 + 18000 = 36148) e comincia il giorno del secondo (la coppia 9074 + 18000 = 27074, annullata):
    // dal 1/10 le parti hanno regolato fra loro, e Teo, entrato il 1/11, non divide né le «Spese generali» né la voce. Sara 18148 + 18000 = 36148, per 184 + 92 giorni. Era: Pia 8379 + 15441 =
    // 23820 e Teo 1389 + 2559 = 3948 «pagata da Sara» (le spese fra 80 × 92, 80 × 92 e 20 × 61; la voce fra Pia 80 × 92 e Teo 20 × 61), Sara 8380.
    $s = pcc367ConLaSpesa($this, '2026-10-01');
    pcc361Inizio($this, $s, 'Teo', '2026-11-01', 20);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -36148, 'Pia' => 27074, 'Sara' => 9074]);
    pcc366Annulla($this, $s, fclUltimo($s));
    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [17852, 17852, 0, 181],
        'sara' => [36148, 0, 0, 276],
    ])->and(pcc361Sezione($s, 'Sara'))->toBe([36148, 36148, []]);
});

it('Coda 367 — la fine di Sara «Scrivi» il 1/10 dopo il cambio «Scrivi», poi «Annulla il conguaglio» della fine, e Teo dal 1/11: Teo non rimborsa a Sara i giorni regolati fra le parti', function () {
    // «Annulla il conguaglio» della fine di Sara: i giorni dopo il 1/10 le parti li hanno regolato fra loro (decisioni 43 e 46), e il pannello dell'inizio di Teo lo dice. Il prospetto non va oltre
    // l'accordo: Sara 18148 (con il «Riscaldamento» dei soli mesi di novembre e dicembre 18148 + 18000 = 36148, per 184 + 61 giorni), Teo nessuna sezione. Era: 18148 coperti 153 : scoperti 31,
    // 15090 e 3058; 15090 fra Sara 80 × 92 = 7360 e Teo 20 × 61 = 1220, 2145,66 → Teo 2146 «pagata da Sara»; con il «Riscaldamento» Teo 2146 + 18000 = 20146 (con il solo taglio al giorno
    // dell'accordo, Teo 18000 della voce tutta dopo la fine).
    foreach ([false, true] as $conLaSpesa) {
        if ($conLaSpesa) {
            $s = pcc367ConLaSpesa($this);
        } else {
            $s = pcc358Scenario($this, [['Ines', 80]]);
            pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
        }
        pcc366Fine($this, $s, 'Sara', '2026-10-01', 'scrivi');
        pcc366Annulla($this, $s, fclUltimo($s));
        $frasi = pcc361Inizio($this, $s, 'Teo', '2026-11-01', 20);
        $quanto = $conLaSpesa ? 'con il Riscaldamento' : 'senza';
        // Coda 413: le parti hanno regolato la fine di Sara, che ha i giorni con il conguaglio del 1/7; prima la frase diceva «la sua uscita» di Ines, il cui conguaglio è scritto.
        expect($frasi)->toContain('quei giorni li ha presi con il conguaglio Sara, e il conguaglio dei giorni dopo l\'uscita di Sara le parti lo hanno regolato fra loro')
            ->and(fclSaldi($s))->toBe($conLaSpesa ? ['Ines' => -36148, 'Sara' => 36148] : ['Ines' => -18148, 'Sara' => 18148], $quanto)
            ->and(fclProspetto($s)['conduttori'])->toBe([
                'ines' => [17852, 17852, 0, 181],
                'sara' => $conLaSpesa ? [36148, 0, 0, 245] : [18148, 0, 0, 184],
            ], $quanto);
        expect(fclStampa($s))->not->toContain('Totale a carico di Teo');
    }
});

it('Coda 367 — la casella di prima della .50 sul secondo cambio (le righe tolte, la nota, nessuna scelta nel registro), con il «Riscaldamento» dei soli mesi dopo: si legge come «Annulla il conguaglio»', function () {
    // Il secondo cambio com'era registrato prima della .50 con la casella «le parti hanno regolato» (decisioni 43 e 46): nessuna coppia, la nota, il registro senza la scelta. Lo si scrive nel
    // database, perché la pagina di oggi ha le due scelte al posto della casella. Come con il conguaglio annullato (il test del «Riscaldamento» qui sopra): Sara 36148 per 245 giorni, Pia senza
    // sezione. Era: la voce a Pia sola, 18000, più le spese dal 1/10, 9074: Pia 27074 «da rimborsare» a Sara.
    $s = pcc367ConLaSpesa($this);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'scrivi');
    $cambio = fclUltimo($s);
    DB::table('saldi')->where('subentro_id', $cambio->id)->delete();
    $registro = $cambio->registro;
    unset($registro['conguaglio']['scelta']);
    DB::table('subentri')->where('id', $cambio->id)->update(['nota_conguaglio' => 'Le parti hanno regolato fra loro', 'registro' => json_encode($registro)]);
    expect($cambio->fresh()->conguaglioRinunciato())->toBeTrue()
        ->and($cambio->fresh()->conguaglioNonScritto())->toBeFalse()
        ->and($cambio->fresh()->conguaglioAnnullato())->toBeFalse()
        ->and(fclSaldi($s))->toBe(['Ines' => -36148, 'Sara' => 36148]);

    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [17852, 17852, 0, 181],
        'sara' => [36148, 0, 0, 245],
    ]);
});

// --- Coda 369: il già versato «dell'unità» nella strada A′ di chi era entrato con il conguaglio di un cambio ---------------------------------------------

/**
 * Ines sola all'80 %, con € 120,00 di già versato «dell'unità» sulla «Spese generali» dalla pagina «Già versato» (l'interruttore della voce, poi la riga «l'unità», senza persona): la parte
 * dell'inquilino 12000 × 30 % = 3600, il netto 36000 − 3600 = 32400. Il piano generato, le rate da gennaio a giugno a giornale, e il cambio Ines → Sara il 1/7 con la scelta data: con
 * «Scrivi» la coppia sul netto, 32400 × 184/365 = 16333,15 → 16333 (Ines − 16333, Sara + 16333).
 */
function pcc369Scenario($test, string $primo = 'scrivi'): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    $conto = \App\Models\Gestionale\Conto::query()->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')
        ->where('piani_conti.gestione_id', $s['g']->id)->where('conti.nome', 'Spese generali')->select('conti.*')->firstOrFail();
    DB::table('conti')->where('id', $conto->id)->update(['richiede_gia_versato' => true]);
    $test->actingAs($test->user)->put(route('admin.gestionale.contributi.update', [$s['c'], $conto->id]), [
        'natura' => 'avanzo', 'righe' => [['immobile_id' => $s['unita']->id, 'gia_versato' => 12000, 'anagrafica_id' => null]],
        'liquidita_stato' => \App\Models\Gestionale\ContributoVersato::LIQUIDITA_GIA_IN_APERTURA,
    ])->assertSessionHasNoErrors();
    expect(DB::table('contributi_versati')->where('target_id', $conto->id)->whereNull('anagrafica_id')->sum('importo_cents'))->toEqual(12000);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclNetti($s)['Ines'])->toBe(32400);
    pcc360Cambio($test, $s, 'Ines', 'Sara', '2026-07-01', $primo);
    expect(fclSaldi($s))->toBe($primo === 'scrivi' ? ['Ines' => -16333, 'Sara' => 16333] : []);

    return $s;
}

it('Coda 369 — «Scrivi» poi «Non scriverlo» (Ines → Sara il 1/7, Sara → Pia il 1/10) con € 120,00 di già versato «dell\'unità»: il già versato dei giorni dopo l\'uscita di Sara passa a Sara, e il suo «con il conguaglio» è la coppia del Wallet, € 163,33', function () {
    // Il lordo: Ines 17852, il pezzo di Sara 18148, al 1/10 9074 e 9074; il netto: Ines 16067, Sara 16333, al 1/10 8166,5 → 8167 (a pari resto chi esce) e 8166. Il già versato di ogni pezzo,
    // lordo meno netto: Ines 1785, Sara fino al 30/9 907, i giorni dopo il 1/10 908 (in tutto 3600). Ines 17852 − 1785 = 16067; Sara con il conguaglio 9074 + 9074 − 907 − 908 = 16333, da
    // rimborsare − 8166, in tutto 8167; Pia 8166 «pagata da Sara». Era: la riga «già versato» di Ines − 3600 + 907 = − 2693, Ines 15159 (€ 151,59), Sara con il conguaglio 17241 (€ 172,41) e in
    // tutto 9075 (€ 90,75): i 908 dei giorni dopo l'uscita di Sara restavano sulla riga di Ines.
    $s = pcc369Scenario($this);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'non_scrivere');
    expect(fclSaldi($s))->toBe(['Ines' => -16333, 'Sara' => 16333])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [16067, 16067, 0, 181],
            'pia' => [8166, 0, 8166, 92],
            'sara' => [8167, 0, -8166, 92],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([8167, fclSaldi($s)['Sara'], []])
        ->and(pcc360PagateDa($s, 'Pia'))->toBe(['Sara' => 8166]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Ines € 160,67')
        ->and($stampa)->toContain('Totale a carico di Sara € 81,67 con il conguaglio del cambio d\'inquilino € 163,33')
        ->and($stampa)->toContain('Totale a carico di Pia € 81,66')
        ->and($stampa)->not->toContain('€ 151,59')
        ->and($stampa)->not->toContain('€ 172,41')
        ->and($stampa)->not->toContain('€ 90,75');
});

it('Coda 369 — lo stesso con la fine di Sara senza chi entra il 1/10, «Non scriverlo»: i giorni senza conduttore si restituiscono a Sara al netto, e il già versato di quei giorni è suo', function () {
    // Gli stessi pezzi: Ines 16067, Sara 8167 con il conguaglio 16333, i giorni dopo il 1/10 senza conduttore 8166 da restituirle (92 giorni). Era: Ines 15159, Sara 9075.
    $s = pcc369Scenario($this);
    pcc366Fine($this, $s, 'Sara', '2026-10-01', 'non_scrivere');
    expect(fclSaldi($s))->toBe(['Ines' => -16333, 'Sara' => 16333])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [16067, 16067, 0, 181],
            'sara' => [8167, 0, -8166, 92],
        ], 'senza' => [8166, 92]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([8167, 16333, []]);
});

it('Coda 369 — tre cambi (Ines → Sara «Scrivi» il 1/7, Sara → Pia «Scrivi» il 1/9, Pia → Zoe «Non scriverlo» il 1/11): il già versato dei giorni di Zoe passa a Pia', function () {
    // Il lordo: Sara 1/7–31/8 18148 × 62/184 = 6115,07 → 6115, Pia 12033, al 1/11 6016,5 → 6017 e 6016; il netto: Sara 16333 × 62/184 = 5503,51 → 5504, Pia 10829, al 1/11 5414,5 → 5415 e 5414.
    // Il già versato: Sara 611, Pia 602 e 602. Pia con il conguaglio 6017 + 6016 − 602 − 602 = 10829, in tutto 5415; Zoe 5414 «pagata da Pia»; Ines 16067. Il Wallet: la seconda coppia
    // 32400 × 122/365 = 10829,59 → 10830 a Pia (Sara + 5503); fra il Wallet e la catena un centesimo, come con «Scrivi» due volte. Era: Ines 15465, Pia con il conguaglio 11431, in tutto 6017.
    $s = pcc369Scenario($this);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-09-01', 'scrivi');
    pcc360Cambio($this, $s, 'Pia', 'Zoe', '2026-11-01', 'non_scrivere');
    expect(fclSaldi($s))->toBe(['Ines' => -16333, 'Pia' => 10830, 'Sara' => 5503])
        ->and(fclProspetto($s)['conduttori'])->toBe([
            'ines' => [16067, 16067, 0, 181],
            'pia' => [5415, 0, -5414, 61],
            'sara' => [5504, 0, 0, 62],
            'zoe' => [5414, 0, 5414, 61],
        ])->and(pcc361Sezione($s, 'Pia'))->toBe([5415, 10829, []]);
});

it('Coda 369 — con Teo coinquilino dal 1/9 al 20 % e il rientro di Sara il 1/12 al 20 %: chi divide i giorni dopo l\'uscita divide il netto, e il già versato di quei giorni resta a Sara', function () {
    // Teo: il netto del pezzo di Sara, 8167, fra Sara 7360 e Teo 600, 7551 e 616; il netto dopo il 1/10, 8166, fra Pia 7360 e Teo 1840, 6533 e 1633. Sara 16333 − 616 − 1633 − 6533 = 7551, da
    // rimborsare − 8782; Teo 2249 (30 + 92 giorni). Era: Sara 8459, Ines 15159.
    $s = pcc369Scenario($this);
    pcc361Inizio($this, $s, 'Teo', '2026-09-01', 20);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'non_scrivere');
    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [16067, 16067, 0, 181],
        'pia' => [6533, 0, 6533, 92],
        'sara' => [7551, 0, -8782, 92],
        'teo' => [2249, 0, 2249, 122],
    ])->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 2249]);

    // Il rientro di Sara: il netto dopo il 1/10, 8166, fra Pia 7360 e Sara 620 (31 giorni), 7531,55 e 634,45 → 7532 e 634: i giorni di Sara sono suoi, nelle sue rate. Sara 16333 − 7532 =
    // 8801 (8167 + 634), 92 + 31 giorni. Era: Sara 9709.
    $s = pcc369Scenario($this);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'non_scrivere');
    pcc361Inizio($this, $s, 'Sara', '2026-12-01', 20);
    expect(fclProspetto($s)['conduttori'])->toBe([
        'ines' => [16067, 16067, 0, 181],
        'pia' => [7532, 0, 7532, 92],
        'sara' => [8801, 0, -7532, 123],
    ]);
});

it('Coda 369 — le guardie: «Scrivi» due volte, la fine di Sara «Scrivi» (la strada B: il già versato una volta sola) e la strada A′ senza un cambio prima restano come prima', function () {
    // «Scrivi» due volte: Ines 16067, Sara 8167, Pia 8166, come con «Non scriverlo» per Ines (la scelta del secondo cambio non cambia le cifre di chi non ne è parte). Il Wallet ha la seconda coppia
    // sull'anno, 32400 × 92/365 = 8166,58 → Pia + 8167, Sara + 8166.
    $s = pcc369Scenario($this);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -16333, 'Pia' => 8167, 'Sara' => 8166])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [16067, 16067, 0, 181],
            'pia' => [8166, 0, 0, 92],
            'sara' => [8167, 0, 0, 92],
        ], 'senza' => [0, 0]]);

    // La fine di Sara «Scrivi»: la coppia Sara + 8166, Ugo + 8167; i giorni dopo il 1/10 li ha pagati Ugo, e la riga «già versato» di chi ha la riga toglie i 908 una volta sola.
    $s = pcc369Scenario($this);
    pcc366Fine($this, $s, 'Sara', '2026-10-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -16333, 'Sara' => 8166, 'Ugo' => 8167])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [16067, 16067, 0, 181],
            'sara' => [8167, 0, 0, 92],
        ], 'senza' => [8166, 92]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([8167, 8167, []]);

    // La strada A′ senza un cambio prima (Ines → Sara «Non scriverlo» il 1/7): chi esce ha la riga, e il già versato dei giorni dopo l'uscita è già sulla sua riga. Ines nelle sue rate 36000,
    // il già versato − 3600, da restituire − 16333: 16067; Sara 16333 «pagata da Ines».
    $s = pcc369Scenario($this, 'non_scrivere');
    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [16067, 32400, -16333, 181],
        'sara' => [16333, 0, 16333, 184],
    ], 'senza' => [0, 0]])->and(pcc360PagateDa($s, 'Sara'))->toBe(['Ines' => 16333]);
});

it('Coda 369 — il pezzo «regolato fra le parti» nella strada A′: il cambio Ines → Sara annullato, Sara → Pia «Non scriverlo» il 1/9, Pia → Sara il 1/10, la fine di Sara «Scrivi» il 1/11: il già versato dei giorni dopo il 1/9 va con la parte regolata', function () {
    // La fine di Sara del 1/11 scrive la coppia (Sara − 5415, Ugo + 5415: 32400 × 61/365 = 5414,79), e la catena della decisione 83.3 segue il cambio annullato: il pezzo di Sara è «regolato fra
    // le parti», e la sua uscita del 1/9 con «Non scriverlo» è la strada A′, fino alla sua seconda uscita, la fine del 1/11 (Coda 370): dal 1/11 la strada B, i giorni li ha presi Ugo con la coppia.
    // Il lordo: Sara 18148, al 1/9 6115 (6115,07) e 12033, al 1/11 6017 e 6016 (6016,5: a pari resto chi esce); il netto: 16333, 5504 (5503,51) e 10829, al 1/11 5415 e 5414 (5414,5). Il pezzo A′,
    // 1/9–31/10, tutto coperto: il netto 5415 fra Pia 80 × 30 e Sara 80 × 31, 2663,11 e 2751,89 → 2663 e 2752; il lordo 6017, Sara 3057,8 → 3058. Il già versato accanto a Sara (decisione 84.1):
    // 6115 − 5504 = 611 e 6017 − 5415 = 602; quello del pezzo B, 6016 − 5414 = 602, è passato con la coppia, e la riga di Ines lo toglie: Ines − 3600 + 611 + 602 + 602 = − 1785, 16067. Sara: 6115
    // + 3058 = 9173 per 62 + 31 giorni, i giorni dopo l'uscita 6017 − 3058 = 2959, il già versato − 1213, da restituirle 5415 − 2752 = 2663: 8256; la parte regolata 9173 + 2959 − 1213 = 10919. Dal
    // 1/11 nessun conduttore: 5414 «pagata da Ugo con il conguaglio della fine locazione del 01/11/2026» (la coppia 5415: il centesimo dell'arrotondamento in due passi).
    // Cambiato con la Coda 370 (decisione 121.1): questo test fissava la cifra della lettura di prima, che teneva nella strada A′ anche i giorni dopo la seconda uscita, già tolti a Sara dal Wallet
    // con la coppia: Sara da rimborsare − 8077 (2663 + 5414 «da restituire a chi l'ha pagata»), la parte regolata 16333, i 5414 senza conduttore «da restituire» a Sara; le cifre del già versato
    // (Ines 16067, Sara 8256) non cambiano. Prima della Coda 369: il già versato dei giorni dopo il 1/9 (1204) restava sulla riga di Ines, Ines 14863, Sara 9460.
    $s = pcc369Scenario($this);
    pcc366Annulla($this, $s, fclUltimo($s));
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-09-01', 'non_scrivere');
    $dati = ['quota' => (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Pia'))->value('quota')] + fclModulo($s, $s['persone']['Sara'], '2026-10-01', [], 'Pia');
    expect((bool) (fclAnteprima($this, $s, $dati)['rate']['scelta_del_conguaglio'] ?? false))->toBeFalse();
    fclRegistra($this, $s, $dati);
    pcc366Fine($this, $s, 'Sara', '2026-11-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Sara' => -5415, 'Ugo' => 5415]);

    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [16067, 16067, 0, 181],
        'pia' => [2663, 0, 2663, 30],
        'sara' => [8256, 0, -2663, 93],
    ], 'senza' => [5414, 61]])
        ->and(pcc366Sezione($s, 'Sara'))->toBe([8256, 10919, []])
        ->and(pcc360PagateDa($s, 'Pia'))->toBe(['Sara' => 2663])
        ->and(pcc370Senza($s))->toBe([[5414, 61, 'proprietario', 'Ugo', '2026-11-01', 'B']]);
});

it('Coda 366 — i due modi sulla stessa persona: Sara ha un pezzo con il conguaglio e uno regolato fra le parti, Teo in tutti e due; il piede di Sara dice tutti e due i modi della somma, € 16,32', function () {
    // Ines sola all'80 %. Ines → Sara «Scrivi» il 1/4: 36000 × 90/365 = 8876,71 → Ines 8877, Sara 27123. Teo dal 1/5 al 15/9 al 20 %. Sara → Pia «Scrivi» il 1/6: 27123 × 61/275 = 6016,48
    // → Sara 6016, Pia 21107. Pia → Sara «Scrivi» il 1/8, poi «Annulla il conguaglio»: 21107 × 61/214 = 6016,48 → Pia 6016, Sara 15091, regolata fra le parti. La fine di Sara il 1/10 «Scrivi»:
    // 15091 × 61/153 = 6016,67 → Sara 6017, Ugo 9074 (la catena della decisione 83.3). I pezzi con Teo: Sara 1/4–31/5, 6016 fra 80 × 61 e 20 × 31, 5338 e 678; Pia 1/6–31/7, 6016 fra 80 × 61 e
    // 20 × 61, 4813 e 1203; Sara regolato 1/8–30/9, 6017 fra 80 × 61 e 20 × 46, 5062,58 e 954,42 → 5063 e 954. Sara 10401, da ricevere da Teo 678 + 954 = 1632; Teo 2835 per 31 + 61 + 46 giorni.
    // Ogni voce dice il suo modo; il piede, che somma le due, li dice tutti e due (prima della dicitura con le parole della testa diceva «con il conguaglio o con la parte regolata fra le parti al
    // cambio d'inquilino», come se fosse uno dei due).
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-04-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-05-01', 20);
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-06-01', 'scrivi');
    pcc360Cambio($this, $s, 'Pia', 'Sara', '2026-08-01', 'scrivi');
    pcc366Annulla($this, $s, fclUltimo($s));
    pcc366Fine($this, $s, 'Teo', '2026-09-16');
    pcc366Fine($this, $s, 'Sara', '2026-10-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -27123, 'Pia' => 21107, 'Sara' => -3058, 'Ugo' => 9074]);

    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [8877, 8877, 0, 90],
        'pia' => [4813, 0, -1203, 61],
        'sara' => [10401, 0, -1632, 122],
        'teo' => [2835, 0, 2835, 138],
    ], 'senza' => [9074, 92]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([10401, 6016, ['Teo' => -1632]])
        ->and(pcc366Sezione($s, 'Sara'))->toBe([10401, 6017, ['Teo' => -1632]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Pia' => 1203, 'Sara' => 1632]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('pagata da Sara con il conguaglio del cambio d\'inquilino del 01/04/2026')
        ->and($stampa)->toContain('pagata da Pia con il conguaglio del cambio d\'inquilino del 01/06/2026')
        ->and($stampa)->toContain('pagata da Sara con il cambio d\'inquilino del 01/08/2026 regolato fra le parti')
        ->and($stampa)->toContain('da ricevere da Teo: la parte di Teo dei giorni presi con il conguaglio del cambio d\'inquilino del 01/04/2026')
        ->and($stampa)->toContain('da ricevere da Teo: la parte di Teo dei giorni presi con il cambio d\'inquilino del 01/08/2026 regolato fra le parti')
        ->and($stampa)->toContain('a favore di Sara: da ricevere da Teo, la parte di Teo dei giorni che ha pagato in parte con il conguaglio e in parte con il cambio d\'inquilino regolato fra le parti € 16,32')
        ->and($stampa)->toContain('a favore di Pia: da ricevere da Teo, la parte di Teo dei giorni che ha pagato con il conguaglio € 12,03')
        ->and($stampa)->not->toContain('con il conguaglio o con');
});

// --- Coda 371: dopo «Annulla il conguaglio» del primo cambio, la fine «Scrivi» di chi era entrato e una voce che comincia dopo il cambio (decisione 83.3) -------

/**
 * Ines sola all'80 %, Ugo proprietario, con una seconda voce dalla pagina «Nuova voce» (`$tipo` spesa o entrata, l'importo dato, 30 % all'inquilino sulla «Proprietà»: il
 * «Riscaldamento» € 600,00 è 18000, l'«Affitto antenna» € 500,00 di entrata − 15000) e il piano «Preventivo 2026» dalla pagina «Nuovo piano rate», con la competenza della voce
 * dal giorno dato al 31/12 (con `$soloLaVoce` il piano ha la sola voce, senza le «Spese generali»); il piano approvato dalla pagina, generato, le rate da gennaio a giugno a giornale.
 */
function pcc371Scenario($test, string $dal = '2026-11-01', string $nome = 'Riscaldamento', string $tipo = 'spesa', string $importo = '600,00', bool $soloLaVoce = false): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
        'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => $tipo, 'importo' => $importo, 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 70, 'percentuale_inquilino' => 30, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $voce = \App\Models\Gestionale\Conto::where('nome', $nome)->where('piano_conto_id', $pc->id)->firstOrFail();
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    DB::table('piani_rate')->where('id', $s['piano']->id)->delete();
    $capitoli = [['id' => $voce->id, 'importo' => $importo, 'note' => '']];
    $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'nome' => 'Preventivo 2026', 'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => false, 'recurrence_enabled' => false, 'capitoli_ids' => [],
        'capitoli_config' => $soloLaVoce ? $capitoli : [['id' => $spese->id, 'importo' => '1.200,00', 'note' => ''], ...$capitoli],
        'competenze_capitoli' => [['conto_id' => $voce->id, 'tratti' => [['dal' => $dal, 'al' => '2026-12-31']]]],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $s['piano'] = \App\Models\Gestionale\PianoRate::where('gestione_id', $s['g']->id)->latest('id')->firstOrFail();
    $test->actingAs($test->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), [
        'approvato' => true, 'data_delibera_assemblea' => '2025-12-15',
    ])->assertSessionHasNoErrors();
    $s['piano'] = $s['piano']->fresh();
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);
    fclPersona($s, 'Sara');

    return $s;
}

/**
 * Il cambio Ines → Sara il 1/7 con «Scrivi» e «Annulla il conguaglio»; poi l'uscita di Sara il 1/10, la fine senza chi entra con la scelta data (null: nessuna uscita), e l'inizio
 * di Teo al 20 % dal giorno dato (null: nessuno). Restituisce le frasi del pannello dell'inizio di Teo.
 */
function pcc371Storia($test, array &$s, ?string $fine = 'scrivi', ?string $teo = '2026-11-01'): string
{
    pcc360Cambio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($test, $s, fclUltimo($s));
    expect(fclSaldi($s))->toBe([]);
    if ($fine !== null) {
        pcc366Fine($test, $s, 'Sara', '2026-10-01', $fine);
    }

    return $teo === null ? '' : pcc361Inizio($test, $s, 'Teo', $teo, 20);
}

it('Coda 371 — il cambio Ines → Sara annullato, la fine di Sara «Scrivi» il 1/10, il «Riscaldamento» dei soli mesi dopo (1/11–31/12) e Teo dal 1/11: Teo rimborsa a Ugo anche la voce, € 240,16, come dice il pannello del suo inizio', function () {
    // La voce, 18000, comincia dopo il cambio annullato e dopo la fine di Sara: la coppia della fine l'ha portata tutta a Ugo (9074 delle «Spese generali» dopo il 1/10 + 18000 = 27074, Sara
    // − 27074, Ugo + 27074). La catena della decisione 83.3 vale anche per lei: il cambio regolato fra le parti, poi la fine «Scrivi» di chi era entrato, poi chi entra, che rimborsa chi ha la
    // coppia. Le «Spese generali»: Ines 36000 × 181/365 = 17852,05 → 17852, Sara 9074 regolata (18148 su 92 + 92), dopo il 1/10 9074: Teo 61 giorni 6016,46 → 6016, ottobre 3058 senza
    // conduttore; il «Riscaldamento» tutto nei giorni di Teo, 18000 «pagata da Ugo». Teo 6016 + 18000 = 24016, per 61 + 61 giorni. Era: Ines 35852 (la voce «nelle sue rate»), Teo 6016:
    // € 180,00 che Ugo ha pagato con la coppia e che nessuno gli rimborsava, mentre il pannello dell'inizio di Teo gli diceva che li rimborsa Teo.
    $s = pcc371Scenario($this);
    $frasi = pcc371Storia($this, $s);
    expect($frasi)->toContain('quei giorni li ha presi Ugo con il conguaglio della fine locazione del 1 ottobre 2026, e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Sara' => -27074, 'Ugo' => 27074]);

    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [17852, 17852, 0, 181],
        'sara' => [9074, 0, 0, 92],
        'teo' => [24016, 0, 24016, 122],
    ], 'senza' => [3058, 31]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 24016]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Teo € 240,16 di cui da rimborsare a chi le ha pagate € 240,16')
        ->and($stampa)->toContain('Totale a carico di Ines € 178,52')
        ->and($stampa)->toContain('01/11/2026–31/12/2026 61 € 180,00 pagata da Ugo con il conguaglio della fine locazione del 01/10/2026')
        ->and($stampa)->not->toContain('€ 358,52')
        ->and($stampa)->not->toContain('€ 180,00 nelle sue rate');
});

it('Coda 371 — lo stesso con il «Riscaldamento» dal 1/9, dopo il cambio e prima della fine: Sara la sua parte regolata fra le parti, e Teo rimborsa a Ugo € 150,16', function () {
    // La voce, 18000 su 122 giorni: Ines 0; Sara 1/9–30/9, 18000 × 30/122 = 4426,23 → 4426, regolata fra le parti; dopo la fine 13574 (la coppia 9074 + 13574 = 22648), Teo 61 giorni su 92,
    // 9000,15 → 9000, ottobre 4574. Sara 9074 + 4426 = 13500 per 92 + 30 giorni; Teo 6016 + 9000 = 15016; senza conduttore 3058 + 4574 = 7632, pagati da Ugo. Era: Ines 35852, Teo 6016.
    $s = pcc371Scenario($this, '2026-09-01');
    pcc371Storia($this, $s);
    expect(fclSaldi($s))->toBe(['Sara' => -22648, 'Ugo' => 22648])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'sara' => [13500, 0, 0, 122],
            'teo' => [15016, 0, 15016, 122],
        ], 'senza' => [7632, 62]])
        ->and(pcc366Sezione($s, 'Sara'))->toBe([13500, 13500, []])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 15016]);
});

it('Coda 371 — un\'entrata dei soli mesi dopo la fine («Affitto antenna» € 500,00 al 30 %, 1/11–31/12): Ugo restituisce a Teo € 89,84, e Teo non gli rimborsa € 60,16', function () {
    // L'entrata, − 15000, è tutta dopo la fine di Sara: la coppia l'ha portata a Ugo con il suo segno (9074 − 15000 = − 5926: Sara + 5926, Ugo − 5926). Teo, il solo conduttore dal 1/11:
    // 6016 − 15000 = − 8984, a suo favore, e glieli restituisce Ugo; il pannello del suo inizio lo dice già. Era: Ines 17852 − 15000 = 2852 (l'entrata «nelle sue rate»), Teo + 6016 «pagata
    // da Ugo»: il verso rovesciato, € 150,00 a danno di Teo.
    $s = pcc371Scenario($this, '2026-11-01', 'Affitto antenna', 'entrata', '500,00');
    $frasi = pcc371Storia($this, $s);
    expect($frasi)->toContain('in quei giorni l\'entrata supera la spesa, e Ugo restituisce la differenza a Teo, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Sara' => 5926, 'Ugo' => -5926])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'sara' => [9074, 0, 0, 92],
            'teo' => [-8984, 0, -8984, 122],
        ], 'senza' => [3058, 31]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => -8984]);
    expect(fclStampa($s))->toContain('Totale a favore di Teo € 89,84');
});

it('Coda 371 — al posto della fine il cambio Sara → Pia «Scrivi» il 1/10: Pia ha la voce con il conguaglio, € 270,74 come nel Wallet, e Teo dal 1/12 le rimborsa € 27,34', function () {
    // La coppia del secondo cambio: 9074 + 18000 = 27074 (Sara − 27074, Pia + 27074). Pia, all'80 %, ha con il conguaglio le «Spese generali» dal 1/10 e tutto il «Riscaldamento». Con Teo dal
    // 1/12 al 20 % i giorni di Pia si dividono per quota e giorni: le «Spese generali» 9074 × (20 × 31)/(80 × 92 + 20 × 31) = 9074 × 620/7980 = 705,00 → 705, il «Riscaldamento» 18000 × 620/
    // (80 × 61 + 20 × 31) = 18000 × 620/5500 = 2029,09 → 2029: Teo 2734 «pagata da Pia», Pia 27074 − 2734 = 24340. Era: Pia 9074 con il conguaglio (il «Riscaldamento» nelle rate di Ines) e,
    // con Teo, Teo 705.
    foreach ([false, true] as $conTeo) {
        $s = pcc371Scenario($this);
        pcc371Storia($this, $s, null, null);
        pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-10-01', 'scrivi');
        expect(fclSaldi($s))->toBe(['Pia' => 27074, 'Sara' => -27074]);
        if (! $conTeo) {
            expect(fclProspetto($s))->toBe(['conduttori' => [
                'ines' => [17852, 17852, 0, 181],
                'pia' => [27074, 0, 0, 153],
                'sara' => [9074, 0, 0, 92],
            ], 'senza' => [0, 0]])->and(pcc361Sezione($s, 'Pia'))->toBe([27074, fclSaldi($s)['Pia'], []]);

            continue;
        }
        $frasi = pcc361Inizio($this, $s, 'Teo', '2026-12-01', 20);
        expect($frasi)->toContain('li rimborsa Teo a Pia, con il prospetto degli oneri accessori')
            ->and(fclProspetto($s)['conduttori'])->toBe([
                'ines' => [17852, 17852, 0, 181],
                'pia' => [24340, 0, -2734, 153],
                'sara' => [9074, 0, 0, 92],
                'teo' => [2734, 0, 2734, 62],
            ])->and(pcc361Sezione($s, 'Pia'))->toBe([24340, 27074, ['Teo' => -2734]])
            ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Pia' => 2734]);
    }
});

it('Coda 371 — il rientro di Ines il 1/11 all\'80 % al posto dell\'inizio di Teo: Ines rimborsa a Ugo i giorni dal 1/11 che lui ha preso con la coppia, anche la voce, € 240,16', function () {
    // Le cifre del primo test, con Ines al posto di Teo: i giorni dal 1/11 sono nelle sue rate per le «Spese generali», ma la fine «Scrivi» di Sara li ha portati a Ugo; Ines gli rimborsa 6016 +
    // 18000 = 24016, e nelle sue rate restano i 17852 dei suoi 181 giorni. Era: da rimborsare 6016, e il «Riscaldamento» nelle sue rate.
    // Coda 377: il pannello del rientro lo dice come dopo una fine «Scrivi» di chi rientra (decisione 45, nota del 12/10): «è già nelle rate di Ines» sarebbe falso, i giorni dal 1/11 li ha presi
    // Ugo con la coppia della fine di Sara, e Ines glieli rimborsa. Era: «La parte a carico dell'inquilino dal 1 novembre 2026 è già nelle rate di Ines».
    $s = pcc371Scenario($this);
    pcc371Storia($this, $s, 'scrivi', null);
    $frasi = pcc361Inizio($this, $s, 'Ines', '2026-11-01', 80);
    expect($frasi)->toContain('La parte a carico dell\'inquilino dal 1 novembre 2026 l\'ha presa Ugo con il conguaglio scritto alla fine locazione di Sara del 1 ottobre 2026, e Ines gliela rimborsa con il prospetto degli oneri accessori.')
        ->and($frasi)->not->toContain('è già nelle rate di Ines');
    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [41868, 17852, 24016, 303],
        'sara' => [9074, 0, 0, 92],
    ], 'senza' => [3058, 31]])
        ->and(pcc360PagateDa($s, 'Ines'))->toBe(['Ugo' => 24016]);
});

it('Coda 371 — le guardie: senza la fine di Sara, o con la fine «Non scriverlo», la voce resta nelle rate di Ines (decisioni 43 e 46); la voce che comprende il cambio resta com\'era', function () {
    // Senza una fine «Scrivi» dopo il cambio regolato fra le parti, la 83.3 non vale: Ines 36000 + 18000 = 54000 per 365 + 61 giorni, Teo nessuna sezione, il pannello «regolato».
    foreach ([null, 'non_scrivere'] as $fine) {
        $s = pcc371Scenario($this);
        $frasi = pcc371Storia($this, $s, $fine);
        expect($frasi)->toContain('il conguaglio dei giorni dopo la sua uscita le parti lo hanno regolato fra loro')
            ->and(fclSaldi($s))->toBe([], (string) $fine)
            ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [54000, 54000, 0, 426]], 'senza' => [0, 0]], (string) $fine);
    }

    // Il «Riscaldamento» dal 6/6 comprende il cambio: la catena lo legge già nella voce. Ines 18000 × 25/209 = 2153,11 → 2153; il resto 15847, al 1/10 7923,5 → 7924 regolati a Sara (a pari resto
    // chi esce) e 7923 dopo, Teo 61 giorni su 92, 5253,34 → 5253, ottobre 2670. Ines 17852 + 2153 = 20005, Sara 9074 + 7924 = 16998, Teo 6016 + 5253 = 11269, senza 3058 + 2670 = 5728.
    $s = pcc371Scenario($this, '2026-06-06');
    pcc371Storia($this, $s);
    expect(fclSaldi($s))->toBe(['Sara' => -16997, 'Ugo' => 16997])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [20005, 20005, 0, 206],
            'sara' => [16998, 0, 0, 184],
            'teo' => [11269, 0, 11269, 122],
        ], 'senza' => [5728, 62]]);
});

it('Coda 371 — un piano con la sola voce dei mesi dopo (il «Riscaldamento» 1/11–31/12), Teo dal 1/11: il pannello dell\'inizio di Teo dice ciò che fa il prospetto, nelle tre storie della fine di Sara', function () {
    // Senza la fine e con «Non scriverlo» le parti hanno regolato fra loro: la voce resta nelle rate di Ines, 18000 per 61 giorni, e il pannello lo dice. Con «Scrivi» la coppia ha portato la voce a Ugo
    // (Sara − 18000, Ugo + 18000): Teo la rimborsa a Ugo, 18000. Era: in tutte e tre «chi paga i giorni dopo la sua uscita lo decide l'amministratore» e Ines 18000, anche con la coppia scritta.
    foreach ([null, 'non_scrivere', 'scrivi'] as $fine) {
        $s = pcc371Scenario($this, soloLaVoce: true);
        $frasi = pcc371Storia($this, $s, $fine);
        expect($frasi)->not->toContain('lo decide l\'amministratore');
        if ($fine !== 'scrivi') {
            expect($frasi)->toContain('il conguaglio dei giorni dopo la sua uscita le parti lo hanno regolato fra loro')
                ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [18000, 18000, 0, 61]], 'senza' => [0, 0]], (string) $fine);

            continue;
        }
        expect($frasi)->toContain('quei giorni li ha presi Ugo con il conguaglio della fine locazione del 1 ottobre 2026, e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
            ->and(fclSaldi($s))->toBe(['Sara' => -18000, 'Ugo' => 18000])
            ->and(fclProspetto($s))->toBe(['conduttori' => ['teo' => [18000, 0, 18000, 61]], 'senza' => [0, 0]])
            ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 18000]);
    }
});

it('Coda 371 — la casella di prima della .50 sul primo cambio (le righe tolte, la nota, nessuna scelta nel registro), la fine di Sara «Scrivi» e il «Riscaldamento» dei soli mesi dopo: si legge come «Annulla il conguaglio», e Teo rimborsa a Ugo € 240,16', function () {
    // Il cambio Ines → Sara com'era registrato prima della .50 con la casella «le parti hanno regolato» (decisioni 43 e 46): nessuna coppia, la nota, il registro senza la scelta. Lo si scrive nel database,
    // perché la pagina di oggi ha le due scelte al posto della casella (come il test della casella sul secondo cambio, Coda 367). La 83.3 vale per la casella come per il conguaglio annullato: le cifre del
    // primo test della Coda 371. Era: Ines 35852 (il «Riscaldamento» nelle sue rate), Teo 6016.
    $s = pcc371Scenario($this);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    $cambio = fclUltimo($s);
    DB::table('saldi')->where('subentro_id', $cambio->id)->delete();
    $registro = $cambio->registro;
    unset($registro['conguaglio']['scelta']);
    DB::table('subentri')->where('id', $cambio->id)->update(['nota_conguaglio' => 'Le parti hanno regolato fra loro', 'registro' => json_encode($registro)]);
    expect($cambio->fresh()->conguaglioRinunciato())->toBeTrue()
        ->and($cambio->fresh()->conguaglioNonScritto())->toBeFalse()
        ->and($cambio->fresh()->conguaglioAnnullato())->toBeFalse();
    pcc366Fine($this, $s, 'Sara', '2026-10-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-11-01', 20);
    expect(fclSaldi($s))->toBe(['Sara' => -27074, 'Ugo' => 27074])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'sara' => [9074, 0, 0, 92],
            'teo' => [24016, 0, 24016, 122],
        ], 'senza' => [3058, 31]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 24016]);
});

// --- Coda 372: due cambi «Scrivi» annullati tutti e due, poi la fine «Scrivi» di chi era entrato con il secondo (la decisione 83.3 su ciascun cambio) ------------

/**
 * Ines sola all'80 % (con `$conLaVoce` anche il «Riscaldamento» € 600,00 dei soli mesi di novembre e dicembre, dalla pagina); i cambi Ines → Sara il 1/7 e Sara → Pia il 1/10 con «Scrivi»,
 * «Annulla il conguaglio» dei cambi dati (`c1`, `c2`) nell'ordine dato, dopo il secondo cambio o, con `$subito`, ognuno subito dopo il suo; poi la fine di Pia il 1/12 senza chi entra, con la
 * scelta data (null: nessuna fine).
 */
function pcc372Storia($test, array $annulla, ?string $fine = 'scrivi', bool $subito = false, bool $conLaVoce = false): array
{
    $s = $conLaVoce ? pcc371Scenario($test) : pcc358Scenario($test, [['Ines', 80]]);
    pcc360Cambio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    $cambi = ['c1' => fclUltimo($s)];
    if ($subito && in_array('c1', $annulla, true)) {
        pcc366Annulla($test, $s, $cambi['c1']);
    }
    pcc360Cambio($test, $s, 'Sara', 'Pia', '2026-10-01', 'scrivi');
    $cambi['c2'] = fclUltimo($s);
    foreach ($annulla as $quale) {
        if (! $subito || $quale === 'c2') {
            pcc366Annulla($test, $s, $cambi[$quale]);
        }
        expect($cambi[$quale]->fresh()->conguaglioAnnullato())->toBeTrue()
            ->and(DB::table('saldi')->where('subentro_id', $cambi[$quale]->id)->count())->toBe(0);
    }
    if ($fine !== null) {
        pcc366Fine($test, $s, 'Pia', '2026-12-01', $fine);
    }

    return $s;
}

it('Coda 372 — i due cambi «Scrivi» annullati tutti e due (Ines → Sara il 1/7, Sara → Pia il 1/10), la fine di Pia «Scrivi» il 1/12 e Teo dal 1/12: Teo rimborsa a Ugo dicembre, € 30,58, in tutti e due gli ordini degli annullamenti', function () {
    // La fine di Pia scrive la coppia di dicembre: 36000 × 31/365 = 3057,53 → 3058 (Pia − 3058, Ugo + 3058). La catena della decisione 83.3 su ciascun cambio: il primo, regolato fra le parti, è seguito
    // dall'uscita di Sara, il secondo cambio, regolato anche lui e seguito dalla fine «Scrivi» di Pia. Ines 36000 × 181/365 = 17852,05 → 17852; Sara 18148 × 92/184 = 9074 «regolata fra le parti al
    // cambio d'inquilino del 01/07/2026»; Pia 9074 × 61/92 = 6016,46 → 6016, «… del 01/10/2026»; dicembre 3058, pagato da Ugo con la coppia, e Teo, solo conduttore, glielo rimborsa. Era: Ines 36000
    // per 365 giorni e nessun altro, perché la catena cercava una fine «Scrivi» di Sara, e il pannello dell'inizio di Teo diceva che le parti avevano regolato fra loro: Ugo restava con € 30,58 che
    // il Wallet gli aveva messo a debito. Con il solo primo annullato, o il solo secondo, Teo li rimborsava già.
    foreach ([['c2', 'c1'], ['c1', 'c2']] as $ordine) {
        $subito = $ordine === ['c1', 'c2'];
        $s = pcc372Storia($this, $ordine, 'scrivi', $subito);
        $frasi = pcc361Inizio($this, $s, 'Teo', '2026-12-01', 20);
        $come = $subito ? 'subito' : 'dopo';
        expect($frasi)->toContain('quei giorni li ha presi Ugo con il conguaglio della fine locazione del 1 dicembre 2026, e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
            ->and(fclSaldi($s))->toBe(['Pia' => -3058, 'Ugo' => 3058], $come)
            ->and(fclProspetto($s))->toBe(['conduttori' => [
                'ines' => [17852, 17852, 0, 181],
                'pia' => [6016, 0, 0, 61],
                'sara' => [9074, 0, 0, 92],
                'teo' => [3058, 0, 3058, 31],
            ], 'senza' => [0, 0]], $come)
            ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3058], $come);
        $stampa = fclStampa($s);
        expect($stampa)->toContain('92 € 90,74 regolata fra le parti al cambio d\'inquilino del 01/07/2026')
            ->and($stampa)->toContain('61 € 60,16 regolata fra le parti al cambio d\'inquilino del 01/10/2026')
            ->and($stampa)->toContain('31 € 30,58 pagata da Ugo con il conguaglio della fine locazione del 01/12/2026')
            ->and($stampa)->toContain('Totale a carico di Teo € 30,58 di cui da rimborsare a chi le ha pagate € 30,58')
            ->and($stampa)->not->toContain('€ 360,00 nelle sue rate');
    }
});

it('Coda 372 — lo stesso con Teo dal 15/12, e senza nessuno dopo: i giorni di dicembre senza conduttore restano a Ugo, che li ha pagati con la coppia', function () {
    // Con Teo dal 15/12 dicembre si divide fra i 17 giorni di Teo e i 14 senza nessuno: 3058 × 17/31 = 1676,97 → 1677 a Teo, 1381,03 → 1381 senza conduttore, tutti e due pagati da Ugo. Senza nessuno
    // dopo, 3058 senza conduttore. Era: Ines 36000 per 365 giorni, e né Teo né i giorni senza conduttore.
    $s = pcc372Storia($this, ['c2', 'c1']);
    pcc361Inizio($this, $s, 'Teo', '2026-12-15', 20);
    $p = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [17852, 17852, 0, 181],
        'pia' => [6016, 0, 0, 61],
        'sara' => [9074, 0, 0, 92],
        'teo' => [1677, 0, 1677, 17],
    ], 'senza' => [1381, 14]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 1677])
        ->and(collect($p['senza_conduttore']['voci'])->map(fn ($v) => [(int) $v['importo'], $v['pagato_da'] ?? null])->all())->toBe([[1381, 'Ugo']]);

    $s = pcc372Storia($this, ['c2', 'c1']);
    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [17852, 17852, 0, 181],
        'pia' => [6016, 0, 0, 61],
        'sara' => [9074, 0, 0, 92],
    ], 'senza' => [3058, 31]]);
});

it('Coda 372 — con il «Riscaldamento» dei soli mesi dopo i due cambi (1/11–31/12): Teo rimborsa a Ugo anche la voce, € 122,06, con la catena di tutti e due i cambi e quella della voce che comincia dopo', function () {
    // La fine di Pia scrive dicembre delle «Spese generali», 3058, e del «Riscaldamento», 18000 × 31/61 = 9147,54 → 9148: Pia − 12206, Ugo + 12206. Il «Riscaldamento» comincia dopo i due cambi: per lui
    // la catena parte dal cambio annullato prima della voce (Coda 371) e segue i due cambi regolati fino alla fine di Pia (Coda 372). Pia 6016 + 18000 × 30/61 = 8852,46 → 8852, in tutto 14868
    // regolati per 61 + 30 giorni; Teo 3058 + 9148 = 12206 «pagata da Ugo», per 31 + 31 giorni. Con una sola delle due correzioni Teo 3058 (la 372) o niente (la 371), e il «Riscaldamento» nelle
    // rate di Ines.
    $s = pcc372Storia($this, ['c2', 'c1'], 'scrivi', false, true);
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 20);
    expect(fclSaldi($s))->toBe(['Pia' => -12206, 'Ugo' => 12206])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [14868, 0, 0, 91],
            'sara' => [9074, 0, 0, 92],
            'teo' => [12206, 0, 12206, 62],
        ], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 12206]);
    expect(fclStampa($s))->toContain('Totale a carico di Teo € 122,06 di cui da rimborsare a chi le ha pagate € 122,06');
});

it('Coda 372 — i due cambi annullati e al posto della fine il terzo cambio Pia → Zoe «Scrivi» il 1/12: Zoe ha dicembre con il conguaglio, come nel Wallet', function () {
    // La coppia del terzo cambio, 3058 (Pia − 3058, Zoe + 3058). La catena segue i due cambi regolati fino al terzo, scritto: Zoe 3058 «con il conguaglio», niente da rimborsare; Ines 17852, Sara
    // 9074 e Pia 6016 regolate. Era: Ines 36000 per 365 giorni, e Zoe senza sezione mentre il Wallet le aveva dato dicembre.
    $s = pcc372Storia($this, ['c2', 'c1'], null);
    pcc360Cambio($this, $s, 'Pia', 'Zoe', '2026-12-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Pia' => -3058, 'Zoe' => 3058])
        ->and(fclProspetto($s)['conduttori'])->toBe([
            'ines' => [17852, 17852, 0, 181],
            'pia' => [6016, 0, 0, 61],
            'sara' => [9074, 0, 0, 92],
            'zoe' => [3058, 0, 0, 31],
        ])->and(pcc361Sezione($s, 'Zoe'))->toBe([3058, 3058, []]);
});

it('Coda 372 — tre cambi «Scrivi» annullati tutti e tre (Ines → Sara il 1/7, Sara → Pia il 1/9, Pia → Zoe il 1/11), la fine di Zoe «Scrivi» il 1/12 e Teo dal 1/12: la catena li segue tutti e tre, e Teo rimborsa a Ugo dicembre', function () {
    // La 83.3 su ciascun cambio, di cambio in cambio fino alla fine con la coppia. Ines 17852; Sara 18148 × 62/184 = 6115,07 → 6115; Pia 12033 × 61/122 = 6016,5 → 6017 (a pari resto chi esce); Zoe
    // 6016 × 30/61 = 2958,69 → 2959; dicembre 3057, che Teo rimborsa a Ugo. La coppia della fine di Zoe è 36000 × 31/365 = 3057,53 → 3058: fra il Wallet e la catena un centesimo, l'arrotondamento in
    // due passi. Era: Ines 36000 per 365 giorni, e nessun altro.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    $cambi = [];
    foreach ([['Ines', 'Sara', '2026-07-01'], ['Sara', 'Pia', '2026-09-01'], ['Pia', 'Zoe', '2026-11-01']] as [$esce, $entra, $il]) {
        pcc360Cambio($this, $s, $esce, $entra, $il, 'scrivi');
        $cambi[] = fclUltimo($s);
    }
    foreach (array_reverse($cambi) as $cambio) {
        pcc366Annulla($this, $s, $cambio);
    }
    pcc366Fine($this, $s, 'Zoe', '2026-12-01', 'scrivi');
    $frasi = pcc361Inizio($this, $s, 'Teo', '2026-12-01', 20);
    expect($frasi)->toContain('e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Ugo' => 3058, 'Zoe' => -3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [6017, 0, 0, 61],
            'sara' => [6115, 0, 0, 62],
            'teo' => [3057, 0, 3057, 31],
            'zoe' => [2959, 0, 0, 30],
        ], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3057]);
});

it('Coda 372 — le guardie: la fine di Pia «Non scriverlo» o nessuna fine dopo i due annullati (decisioni 43 e 46), e un solo cambio annullato o nessuno, come prima', function () {
    // Senza la coppia della fine la catena si ferma al primo cambio, regolato fra le parti: Ines 36000 per 365 giorni, Teo nessuna sezione, il pannello «regolato».
    foreach (['non_scrivere', null] as $fine) {
        $s = pcc372Storia($this, ['c2', 'c1'], $fine);
        $frasi = pcc361Inizio($this, $s, 'Teo', '2026-12-01', 20);
        expect($frasi)->toContain('il conguaglio dei giorni dopo la sua uscita le parti lo hanno regolato fra loro')
            ->and(fclSaldi($s))->toBe([], (string) $fine)
            ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [36000, 36000, 0, 365]], 'senza' => [0, 0]], (string) $fine);
    }

    // Il solo primo annullato, il solo secondo, nessuno: le cifre del primo test, e Teo rimborsa a Ugo € 30,58; cambia solo il modo del pezzo di Sara e di Pia.
    foreach ([[['c1'], ['Pia' => 6016, 'Sara' => -9074, 'Ugo' => 3058]], [['c2'], ['Ines' => -18148, 'Pia' => -3058, 'Sara' => 18148, 'Ugo' => 3058]],
        [[], ['Ines' => -18148, 'Pia' => 6016, 'Sara' => 9074, 'Ugo' => 3058]]] as [$annulla, $saldi]) {
        $s = pcc372Storia($this, $annulla);
        $frasi = pcc361Inizio($this, $s, 'Teo', '2026-12-01', 20);
        $quali = implode(',', $annulla);
        expect($frasi)->toContain('e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
            ->and(fclSaldi($s))->toBe($saldi, $quali)
            ->and(fclProspetto($s))->toBe(['conduttori' => [
                'ines' => [17852, 17852, 0, 181],
                'pia' => [6016, 0, 0, 61],
                'sara' => [9074, 0, 0, 92],
                'teo' => [3058, 0, 3058, 31],
            ], 'senza' => [0, 0]], $quali)
            ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3058], $quali);
    }
});

// --- Coda 370: chi esce con un cambio «Non scriverlo», rientra con un cambio e riesce con il conguaglio scritto ---------------------------------------------

/**
 * Ines sola all'80 %. `$primo`: il cambio Ines → Sara il 1/7 con «Scrivi» (`scrivi`), lo stesso con «Annulla il conguaglio» (`annulla`), o nessun cambio (`nessuno`: chi esce è Ines). Poi chi
 * ha la riga esce il 1/9 con il cambio verso Pia e «Non scriverlo», e Pia esce il 1/10 con il cambio di nuovo verso di lei: l'anteprima non chiede la scelta (nessuna rata è intestata a Pia). Il
 * 1/11 riesce: con la fine senza chi entra e la scelta data, o con il cambio verso Zoe e «Scrivi» (`$verso`). Restituisce lo scenario e il nome di chi esce.
 */
function pcc370Storia($test, string $primo = 'scrivi', string $seconda = 'scrivi', ?string $verso = null): array
{
    $s = pcc358Scenario($test, [['Ines', 80]]);
    $chi = 'Ines';
    if ($primo !== 'nessuno') {
        pcc360Cambio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
        if ($primo === 'annulla') {
            pcc366Annulla($test, $s, fclUltimo($s));
        }
        $chi = 'Sara';
    }
    pcc360Cambio($test, $s, $chi, 'Pia', '2026-09-01', 'non_scrivere');
    $dati = ['quota' => (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Pia'))->value('quota')] + fclModulo($s, $s['persone'][$chi], '2026-10-01', [], 'Pia');
    expect((bool) (fclAnteprima($test, $s, $dati)['rate']['scelta_del_conguaglio'] ?? false))->toBeFalse();
    fclRegistra($test, $s, $dati);
    if ($verso !== null) {
        pcc360Cambio($test, $s, $chi, $verso, '2026-11-01', $seconda);
    } else {
        pcc366Fine($test, $s, $chi, '2026-11-01', $seconda);
    }

    return [$s, $chi];
}

/** I giorni senza conduttore del prospetto: [importo, giorni, modo, pagata da, il giorno dell'uscita, la strada]. */
function pcc370Senza(array $s): array
{
    $p = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);

    return collect($p['senza_conduttore']['voci'])->map(fn ($v) => [(int) $v['importo'], $v['giorni'] ?? null, $v['modo'], $v['pagato_da'] ?? null, $v['uscita_del'] ?? null, $v['strada'] ?? null])->all();
}

/** Coda 376: le note delle voci di un conduttore che vengono dalla sua uscita del giorno dato (i giorni dopo l'uscita), una volta ciascuna. */
function pcc376Note(array $s, string $nome, string $uscita): array
{
    $c = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', $nome);

    return collect($c['voci'] ?? [])->where('uscita_del', $uscita)->pluck('nota')->unique()->values()->all();
}

it('Coda 370 — Ines → Sara «Scrivi» il 1/7, Sara → Pia «Non scriverlo» il 1/9, Pia → Sara il 1/10, la fine di Sara «Scrivi» il 1/11: i giorni dopo la seconda uscita li ha presi Ugo con la coppia, e Sara non li riceve due volte', function () {
    // La fine del 1/11 scrive la coppia: 36000 × 61/365 = 6016,44 → 6016 (Sara − 6016, Ugo + 6016); il Wallet di Sara 18148 − 6016 = 12132. Il pezzo di Sara al 1/9: 18148 × 62/184 = 6115,07 →
    // 6115, e 12033 dopo. La strada A′ del cambio «Non scriverlo» arriva fino alla seconda uscita: 12033 su 61 + 61 giorni, 6016,5 → 6017 fino al 31/10 (a pari resto chi esce) e 6016 dal 1/11, che
    // prendono la strada B della fine. Fino al 31/10 tutti coperti: Pia 80 × 30 = 2400 e Sara al rientro 80 × 31 = 2480, 2959,18 e 3057,82 → 2959 e 3058; Sara da rimborsare − (6017 − 3058) = − 2959,
    // con il conguaglio 6115 + 2959 + 3058 = 12132, la cifra del Wallet. Dal 1/11 nessun conduttore: 6016 pagati da Ugo con la coppia, che restano a lui. Era: tutto il pezzo dal 1/9 nella strada A′,
    // Sara da rimborsare − 8975 (2959 di Pia + 6016 «senza conduttore, da restituire a chi l'ha pagata»), con il conguaglio 18148: € 60,16 a Sara due volte, dal Wallet e dal prospetto.
    [$s] = pcc370Storia($this);
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 12132, 'Ugo' => 6016])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [2959, 0, 2959, 30],
            'sara' => [9173, 0, -2959, 93],
        ], 'senza' => [6016, 61]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([9173, fclSaldi($s)['Sara'], []])
        ->and(pcc360PagateDa($s, 'Pia'))->toBe(['Sara' => 2959])
        ->and(pcc370Senza($s))->toBe([[6016, 61, 'proprietario', 'Ugo', '2026-11-01', 'B']]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Spese generali 61 giorni € 60,16 pagata da Ugo con il conguaglio della fine locazione del 01/11/2026')
        ->and($stampa)->toContain('Totale che resta a chi ha pagato € 60,16')
        ->and($stampa)->not->toContain('si restituisce a chi l\'ha pagata')
        ->and($stampa)->not->toContain('€ 89,75');
    // Coda 376: la voce del pezzo A′ chiuso alla seconda uscita dice fino a quando, dal giorno dell'uscita con «Non scriverlo» al giorno prima della fine di Sara. Era: «giorni dopo l'uscita,
    // dal 01/09/2026», come se il pezzo arrivasse alla fine della voce.
    expect($stampa)->toContain('giorni dopo l\'uscita, dal 01/09/2026 al 31/10/2026')
        ->and(pcc376Note($s, 'Sara', '2026-09-01'))->toBe(['giorni dopo l\'uscita, dal 01/09/2026 al 31/10/2026']);
});

it('Coda 370 — lo stesso senza il primo cambio (Ines → Pia «Non scriverlo» il 1/9, Pia → Ines il 1/10, la fine di Ines «Scrivi» il 1/11): Ines riceve da Pia € 29,59, non € 89,75', function () {
    // Ines fino al 31/8: 36000 × 243/365 = 23967,12 → 23967, e 12033 dopo; poi come la storia di Sara: Ines da rimborsare − 2959, nelle sue rate 36000 − 6016 = 29984 (la parte dal 1/11 è passata a Ugo
    // con la coppia), in tutto 23967 + 3058 = 27025 per 243 + 31 giorni; senza conduttore 6016 pagati da Ugo. Era: nelle sue rate 36000, da rimborsare − 8975.
    [$s] = pcc370Storia($this, 'nessuno');
    expect(fclSaldi($s))->toBe(['Ines' => -6016, 'Ugo' => 6016])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [27025, 29984, -2959, 274],
            'pia' => [2959, 0, 2959, 30],
        ], 'senza' => [6016, 61]])
        ->and(pcc370Senza($s))->toBe([[6016, 61, 'proprietario', 'Ugo', '2026-11-01', 'B']]);
});

it('Coda 370 — la seconda uscita con il cambio Sara → Zoe «Scrivi» il 1/11: Zoe ha i suoi giorni con il conguaglio, come nel Wallet, e non li rimborsa anche a Sara', function () {
    // La coppia del cambio, 18148 × 61/184 = 6016,44 → 6016 (Sara − 6016, Zoe + 6016). Zoe 6016 «con il conguaglio», niente da rimborsare; Sara − 2959 come nella fine. Era: Zoe 6016 «pagata da Sara»
    // (12033 fra Pia 2400, Sara 2480 e Zoe 80 × 61 = 4880): Zoe pagava € 60,16 due volte, con il Wallet e a Sara.
    [$s] = pcc370Storia($this, 'scrivi', 'scrivi', 'Zoe');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 12132, 'Zoe' => 6016])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [2959, 0, 2959, 30],
            'sara' => [9173, 0, -2959, 93],
            'zoe' => [6016, 0, 0, 61],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Zoe'))->toBe([6016, 6016, []])
        ->and(pcc360PagateDa($s, 'Zoe'))->toBe([]);
});

it('Coda 370 — con Teo dal 1/12 all\'80 %, dopo la fine: Teo rimborsa a Ugo dicembre, € 30,57, come dice il pannello del suo inizio; con e senza il primo cambio', function () {
    // Il pezzo dal 1/11, 6016, è della strada B: 31 giorni di Teo e 30 senza nessuno, 3057,31 → Teo 3057 e 2958,69 → 2959 senza conduttore, tutti e due pagati da Ugo. Era: Teo 3057 «pagata da
    // Sara» (o da Ines), e il pannello lo diceva («li rimborsa Teo a Sara»), mentre i giorni li aveva presi Ugo con la coppia; novembre 2959 «da restituire» a chi è uscito.
    foreach (['scrivi' => 'Sara', 'nessuno' => 'Ines'] as $primo => $chi) {
        [$s] = pcc370Storia($this, $primo);
        $frasi = pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
        expect($frasi)->toContain('e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
            ->and($frasi)->not->toContain('li rimborsa Teo a ' . $chi)
            ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3057], $chi)
            ->and(fclProspetto($s)['conduttori'][mb_strtolower($chi)][2])->toBe(-2959, $chi)
            ->and(pcc370Senza($s))->toBe([[2959, 30, 'proprietario', 'Ugo', '2026-11-01', 'B']], $chi);
    }
});

it('Coda 370 — con Teo nei giorni del rientro di Sara (dal 1/10 al 20/10, al 20 %): il pezzo dei giorni dopo la prima uscita arriva fino alla seconda, e lo dividono Pia, Sara al rientro e Teo', function () {
    // La strada A′ della prima uscita va dal 1/9 al 31/10: i giorni del rientro di Sara ne fanno parte (sono nelle sue rate), e chi è conduttore in quei giorni divide il pezzo, per quota e per giorni,
    // come nella strada A′ con il rientro. Il pezzo, 6017, fra Pia 80 × 30 = 2400, Sara 80 × 31 = 2480 e Teo 20 × 20 = 400 (su 5280): 2735, 2826,17 e 455,83 → Pia 2735, Sara 2826, Teo 456, «pagata
    // da Sara»; Sara da rimborsare − (6017 − 2826) = − 3191, con il conguaglio 6115 + 3191 + 2826 = 12132 (il Wallet), in tutto 6115 + 2826 = 8941. Dal 1/11 la strada B, 6016 senza conduttore pagati da
    // Ugo. Era: tutto il pezzo dal 1/9 nella strada A′, con i 6016 «da restituire» a Sara.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-09-01', 'non_scrivere');
    $dati = ['quota' => (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Pia'))->value('quota')] + fclModulo($s, $s['persone']['Sara'], '2026-10-01', [], 'Pia');
    fclRegistra($this, $s, $dati);
    pcc361Inizio($this, $s, 'Teo', '2026-10-01', 20);
    pcc366Fine($this, $s, 'Teo', '2026-10-21');
    pcc366Fine($this, $s, 'Sara', '2026-11-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 12132, 'Ugo' => 6016])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [2735, 0, 2735, 30],
            'sara' => [8941, 0, -3191, 93],
            'teo' => [456, 0, 456, 20],
        ], 'senza' => [6016, 61]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([8941, 12132, []])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 456])
        ->and(pcc370Senza($s))->toBe([[6016, 61, 'proprietario', 'Ugo', '2026-11-01', 'B']]);
});

it('Coda 370 — le guardie: la seconda uscita con «Non scriverlo», e il rientro con un inizio di locazione (la seconda fine non scrive la coppia): i giorni dopo la prima uscita si restituiscono a chi è uscito, come prima', function () {
    // «Non scriverlo» alla seconda uscita: nessuna coppia, il pezzo dal 1/9 resta tutto nella strada A′ della prima uscita: Sara da rimborsare − 8975, con il conguaglio 18148, senza conduttore 6016
    // «da restituire» a Sara, con la data della prima uscita.
    [$s] = pcc370Storia($this, 'scrivi', 'non_scrivere');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(fclProspetto($s)['conduttori']['sara'])->toBe([9173, 0, -8975, 93])
        ->and(pcc370Senza($s))->toBe([[6016, 61, 'da_restituire', 'Sara', '2026-09-01', 'A']])
        ->and(fclStampa($s))->toContain('pagata da Sara, rimasta a suo carico (fine della locazione del 01/09/2026, conguaglio non scritto)');

    // Il rientro con un inizio, dopo la fine di Pia (con il primo cambio, e senza: la fine di Ines «Non scriverlo» il 1/9): la seconda fine non chiede la scelta, perché la parte dell'unità chi esce
    // l'ha già ceduta con un passaggio precedente, e non scrive righe. Il prospetto resta com'era: i giorni senza conduttore da restituire a chi è uscito, 8975 senza un altro conduttore, 6016 con Pia.
    foreach (['Sara', 'Ines'] as $chi) {
        $s = pcc358Scenario($this, [['Ines', 80]]);
        if ($chi === 'Sara') {
            pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
            pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-09-01', 'non_scrivere');
            pcc366Fine($this, $s, 'Pia', '2026-10-01');
        } else {
            pcc366Fine($this, $s, 'Ines', '2026-09-01', 'non_scrivere');
        }
        pcc361Inizio($this, $s, $chi, '2026-10-01', 80);
        pcc366Fine($this, $s, $chi, '2026-11-01');
        expect(fclSaldi($s))->toBe($chi === 'Sara' ? ['Ines' => -18148, 'Sara' => 18148] : [], $chi)
            ->and(fclProspetto($s)['conduttori'][mb_strtolower($chi)][2])->toBe(-8975, $chi)
            ->and(pcc370Senza($s))->toBe([[$chi === 'Sara' ? 6016 : 8975, $chi === 'Sara' ? 61 : 91, 'da_restituire', $chi, '2026-09-01', 'A']], $chi);
    }
});

// --- Coda 375: chi esce con «Non scriverlo», rientra con un cambio e riesce con un passaggio che le parti hanno regolato fra loro («Annulla il conguaglio») -----

it('Coda 375 — la storia della Coda 370 con «Annulla il conguaglio» della fine di Sara del 1/11: i giorni dopo la seconda uscita le parti li hanno regolati fra loro, e il prospetto non li restituisce a Sara; Sara riceve € 29,59, non € 89,75', function () {
    // La fine di Sara del 1/11 scrive la coppia 36000 × 61/365 = 6016,44 → 6016 (Sara − 6016, Ugo + 6016), e «Annulla il conguaglio» la toglie: il Wallet torna a Sara + 18148, e il registro della fine
    // conserva Sara − 6016 e Ugo + 6016 regolati fra le parti (decisioni 43 e 46). Il pezzo di Sara al 1/9: 18148 × 62/184 = 6115,07 → 6115, e 12033 dopo; il pezzo A′ si chiude alla seconda uscita:
    // 12033 su 61 + 61 giorni, 6016,5 → 6017 fino al 31/10 (a pari resto chi esce) e 6016 dal 1/11. Fino al 31/10 Pia 80 × 30 = 2400 e Sara al rientro 80 × 31 = 2480: 2959,18 e 3057,82 → 2959 e 3058.
    // Dal 1/11 i giorni restano dov'erano, con il conguaglio del cambio di Sara: le parti li hanno regolati fra loro, e nessuno li restituisce. Sara 6115 + 3058 + 6016 = 15189 per 62 + 31 + 61 = 154
    // giorni, con il conguaglio 18148, da ricevere da Pia 2959; il controllo 17852 + 15189 + 2959 = 36000. Era: il pezzo A′ fino al 31/12, 6016 «senza conduttore» da restituire a Sara, che riceveva
    // 2959 + 6016 = 8975: € 60,16 dei giorni che le parti avevano già regolato.
    [$s] = pcc370Storia($this);
    pcc366Annulla($this, $s, fclUltimo($s));
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [2959, 0, 2959, 30],
            'sara' => [15189, 0, -2959, 154],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([15189, 18148, []])
        ->and(pcc360PagateDa($s, 'Pia'))->toBe(['Sara' => 2959])
        ->and(pcc370Senza($s))->toBe([]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Sara € 151,89')
        ->and($stampa)->toContain('da restituire a Sara: i giorni dopo la sua uscita, che ha pagato € 29,59')
        ->and($stampa)->not->toContain('si restituisce a chi l\'ha pagata')
        ->and($stampa)->not->toContain('€ 89,75');
    // Coda 376: anche qui il pezzo A′ si chiude alla seconda uscita, e la voce dice fino a quando. Era: «giorni dopo l'uscita, dal 01/09/2026».
    expect(pcc376Note($s, 'Sara', '2026-09-01'))->toBe(['giorni dopo l\'uscita, dal 01/09/2026 al 31/10/2026']);
});

it('Coda 375 — lo stesso con Teo dal 1/12 all\'80 %: Teo non rimborsa a Sara i giorni regolati fra le parti, € 30,57, e il pannello del suo inizio lo dice', function () {
    // Come la fine annullata senza il rientro (Ines sola: decisioni 43 e 46, Coda 367): chi entra dopo l'accordo non divide i giorni dell'accordo. Sara come senza Teo, 15189 e 2959 da ricevere da Pia;
    // Teo senza sezione. Era: il pezzo A′ fino al 31/12, 92 giorni coperti e 30 scoperti, 9074,07 e 2958,93 → 9074 e 2959; i 9074 fra Pia 2400, Sara 2480 e Teo 80 × 31 = 2480, 2958,91, 3057,54 e
    // 3057,54 → 2959, 3058 e Teo 3057 (a pari resto Sara prima di Teo) «pagata da Sara», e novembre 2959 da restituire a Sara: 3057 + 2959 = 6016 a Sara, € 60,16.
    [$s] = pcc370Storia($this);
    pcc366Annulla($this, $s, fclUltimo($s));
    $frasi = pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    // Coda 413: le parti hanno regolato la fine di Sara del 1/11, che ha i giorni con il conguaglio; prima la frase diceva «la sua uscita» di Ines, il cui conguaglio è scritto.
    expect($frasi)->toContain('quei giorni li ha presi con il conguaglio Sara, e il conguaglio dei giorni dopo l\'uscita di Sara le parti lo hanno regolato fra loro')
        ->and($frasi)->not->toContain('li rimborsa Teo a Sara')
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [2959, 0, 2959, 30],
            'sara' => [15189, 0, -2959, 154],
        ], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe([]);
    expect(fclStampa($s))->not->toContain('Totale a carico di Teo');
});

it('Coda 375 — senza il primo cambio (Ines → Pia «Non scriverlo» il 1/9, Pia → Ines il 1/10, la fine di Ines «Scrivi» il 1/11 annullata) e Teo dal 1/12: Ines riceve da Pia € 29,59, e Teo non le rimborsa niente', function () {
    // Ines fino al 31/8: 36000 × 243/365 = 23967,12 → 23967, e 12033 dopo; poi come la storia di Sara: il pezzo A′ fino al 31/10, 6017, fra Pia 2959 e Ines al rientro 3058, e dal 1/11 i 6016 restano
    // nelle sue rate, regolati fra le parti. Ines 23967 + 3058 + 6016 = 33041 per 243 + 31 + 61 = 335 giorni, nelle sue rate 36000, da ricevere 2959; il controllo 33041 + 2959 = 36000. I saldi vuoti:
    // la coppia della fine (Ines − 6016, Ugo + 6016) l'annullamento l'ha tolta. Era: Ines 23967 + 3058 = 27025, da ricevere 8975, Teo 3057 «pagata da Ines» e novembre 2959 da restituire a Ines.
    [$s] = pcc370Storia($this, 'nessuno');
    pcc366Annulla($this, $s, fclUltimo($s));
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect(fclSaldi($s))->toBe([])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [33041, 36000, -2959, 335],
            'pia' => [2959, 0, 2959, 30],
        ], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Pia'))->toBe(['Ines' => 2959]);
});

it('Coda 375 — la seconda uscita con il cambio Sara → Zoe «Scrivi» il 1/11, poi annullato: Zoe non rimborsa a Sara i giorni che le due hanno regolato fra loro, € 60,16', function () {
    // La coppia del cambio, 18148 × 61/184 = 6016,44 → 6016 (Sara − 6016, Zoe + 6016), l'annullamento la toglie. Il pezzo A′ si chiude al cambio, e la catena si ferma lì: dal 1/11 i giorni restano a
    // Sara con il conguaglio, come nella fine annullata (Sara 15189, 2959 da ricevere da Pia), e Zoe, che con Sara li ha regolati, non ha sezione. Era: i 12033 fra Pia 2400, Sara 2480 e Zoe 80 × 61 =
    // 4880, 2958,93, 3057,57 e 6016,50 → 2959, 3058 e Zoe 6016 «pagata da Sara»: Zoe pagava € 60,16 due volte, a Sara fra le parti e con il prospetto.
    [$s] = pcc370Storia($this, 'scrivi', 'scrivi', 'Zoe');
    pcc366Annulla($this, $s, fclUltimo($s));
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [2959, 0, 2959, 30],
            'sara' => [15189, 0, -2959, 154],
        ], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Zoe'))->toBe([]);
});

it('Coda 375 — lo stesso, poi la fine di Zoe «Scrivi» il 1/12 e Teo dal 1/12 all\'80 %: la catena segue la fine di Zoe (decisione 83.3), Zoe ha novembre regolato fra le parti, e Teo rimborsa dicembre a Ugo, € 30,57, non a Sara', function () {
    // La fine di Zoe scrive la coppia 36000 × 31/365 = 3057,53 → 3058 (Zoe − 3058, Ugo + 3058). Il cambio Sara → Zoe regolato fra le parti e la fine «Scrivi» di Zoe dopo: la catena continua (83.3). I
    // 6016 dal 1/11 fra Zoe (30 giorni) e dicembre (31): 2958,69 e 3057,31 → Zoe 2959 regolata fra le parti, niente da rimborsare, e dicembre 3057 della strada B della fine di Zoe, tutto nei giorni di
    // Teo: Teo 3057 «pagata da Ugo», che li ha pagati con la coppia (3058: il centesimo dei due passi). Sara 6115 + 3058 = 9173, con il conguaglio 9173 + 2959 = 12132, da ricevere 2959; il controllo
    // 17852 + 9173 + 2959 + 2959 + 3057 = 36000. Era: i 12033 fra Pia 2400, Sara 2480, Zoe 2400 e Teo 2480 → 2959, 3058, Zoe 2959 e Teo 3057, tutti e due «pagata da Sara»: Zoe pagava a Sara giorni
    // regolati, Teo rimborsava Sara invece di Ugo, e Ugo restava senza i € 30,58 della coppia.
    [$s] = pcc370Storia($this, 'scrivi', 'scrivi', 'Zoe');
    pcc366Annulla($this, $s, fclUltimo($s));
    pcc366Fine($this, $s, 'Zoe', '2026-12-01', 'scrivi');
    $frasi = pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect($frasi)->toContain('e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
        ->and($frasi)->not->toContain('li rimborsa Teo a Sara')
        ->and(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148, 'Ugo' => 3058, 'Zoe' => -3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [2959, 0, 2959, 30],
            'sara' => [9173, 0, -2959, 93],
            'teo' => [3057, 0, 3057, 31],
            'zoe' => [2959, 0, 0, 30],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([9173, 12132, []])
        ->and(pcc366Sezione($s, 'Zoe'))->toBe([2959, 2959, []])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3057])
        ->and(pcc360PagateDa($s, 'Zoe'))->toBe([]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('regolata fra le parti al cambio d\'inquilino del 01/11/2026')
        ->and($stampa)->toContain('pagata da Ugo con il conguaglio della fine locazione del 01/12/2026')
        ->and($stampa)->toContain('Totale a carico di Teo € 30,57');
});

it('Coda 375 — lo stesso con il cambio Zoe → Teo «Scrivi» il 1/12 al posto della fine di Zoe: Teo ha dicembre con il conguaglio, come nel Wallet, e non lo rimborsa anche a Sara', function () {
    // Il cambio Zoe → Teo scrive la coppia 36000 × 31/365 = 3057,53 → 3058 (Zoe − 3058, Teo + 3058). Dopo il cambio Sara → Zoe regolato fra le parti, il cambio «Scrivi» di Zoe: la catena lo segue (83.3
    // e Coda 372). Zoe 2959 regolata fra le parti, Teo 3057 «con il conguaglio» (il centesimo dei due passi), niente da rimborsare. Era: Teo 3057 «pagata da Sara», oltre ai 3058 del Wallet: Teo pagava
    // dicembre due volte.
    [$s] = pcc370Storia($this, 'scrivi', 'scrivi', 'Zoe');
    pcc366Annulla($this, $s, fclUltimo($s));
    pcc360Cambio($this, $s, 'Zoe', 'Teo', '2026-12-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148, 'Teo' => 3058, 'Zoe' => -3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [2959, 0, 2959, 30],
            'sara' => [9173, 0, -2959, 93],
            'teo' => [3057, 0, 0, 31],
            'zoe' => [2959, 0, 0, 30],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Teo'))->toBe([3057, 3057, []])
        ->and(pcc366Sezione($s, 'Zoe'))->toBe([2959, 2959, []])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe([]);
});

it('Coda 375 — con il «Riscaldamento» dei soli mesi dopo la seconda uscita (1/11–31/12), la fine di Sara annullata e Teo dal 1/12: le due voci si leggono allo stesso modo, tutte e due a Sara dal 1/11, e nessuna si restituisce', function () {
    // Il «Riscaldamento» € 600,00 al 30 % è 18000, tutto dopo il cambio del 1/7: la coppia del cambio 18148 + 18000 = 36148, quella della fine di Sara 6016 + 18000 = 24016, tolta con l'annullamento.
    // La voce tutta dopo la seconda uscita la Coda 367 la legge già così: resta a Sara con il conguaglio, 18000, e Teo non la divide. Le «Spese generali» ora come lei: Sara 15189, 2959 da ricevere da
    // Pia, Teo senza sezione. Sara 15189 + 18000 = 33189 per 154 + 61 = 215 giorni, con il conguaglio 36148; il controllo 17852 + 33189 + 2959 = 54000. Era: per le «Spese generali» Teo 3057 «pagata
    // da Sara» e novembre 2959 da restituire a Sara, mentre il «Riscaldamento» della stessa uscita restava a Sara: la stessa uscita letta in due modi nello stesso prospetto.
    $s = pcc371Scenario($this);
    pcc360Cambio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc360Cambio($this, $s, 'Sara', 'Pia', '2026-09-01', 'non_scrivere');
    $dati = ['quota' => (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Pia'))->value('quota')] + fclModulo($s, $s['persone']['Sara'], '2026-10-01', [], 'Pia');
    expect((bool) (fclAnteprima($this, $s, $dati)['rate']['scelta_del_conguaglio'] ?? false))->toBeFalse();
    fclRegistra($this, $s, $dati);
    pcc366Fine($this, $s, 'Sara', '2026-11-01', 'scrivi');
    expect(fclSaldi($s))->toBe(['Ines' => -36148, 'Sara' => 12132, 'Ugo' => 24016]);
    pcc366Annulla($this, $s, fclUltimo($s));
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect(fclSaldi($s))->toBe(['Ines' => -36148, 'Sara' => 36148])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [2959, 0, 2959, 30],
            'sara' => [33189, 0, -2959, 215],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([33189, 36148, []])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe([]);
});

it('Coda 375 — le guardie, con Teo dal 1/12: la seconda uscita «Non scriverlo», la seconda uscita «Scrivi» non annullata (la Coda 370) e la fine annullata senza il rientro restano come prima', function () {
    // La fine di Sara «Non scriverlo»: nessuna coppia, il pezzo A′ della prima uscita fino al 31/12, 9074 coperti e 2959 scoperti; Teo 3057 «pagata da Sara», novembre 2959 da restituire a Sara, che
    // riceve 2959 + 3057 + 2959 = 8975.
    [$s] = pcc370Storia($this, 'scrivi', 'non_scrivere');
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect(fclProspetto($s)['conduttori']['sara'])->toBe([9173, 0, -8975, 93])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 3057])
        ->and(pcc370Senza($s))->toBe([[2959, 30, 'da_restituire', 'Sara', '2026-09-01', 'A']]);
    // La fine di Sara «Scrivi» non annullata (la Coda 370): dal 1/11 la strada B, 6016 su 31 + 30 giorni, Teo 3057 «pagata da Ugo» e novembre 2959 di Ugo con la coppia.
    [$s] = pcc370Storia($this);
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect(fclProspetto($s)['conduttori']['sara'])->toBe([9173, 0, -2959, 93])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3057])
        ->and(pcc370Senza($s))->toBe([[2959, 30, 'proprietario', 'Ugo', '2026-11-01', 'B']]);
    // Ines sola, la fine «Scrivi» del 1/11 annullata (decisioni 43 e 46, Coda 367): Ines tutto l'anno nelle sue rate, 36000 per 365 giorni, e Teo senza sezione.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc366Fine($this, $s, 'Ines', '2026-11-01', 'scrivi');
    pcc366Annulla($this, $s, fclUltimo($s));
    $frasi = pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect($frasi)->toContain('il conguaglio dei giorni dopo la sua uscita le parti lo hanno regolato fra loro')
        ->and(fclSaldi($s))->toBe([])
        ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [36000, 36000, 0, 365]], 'senza' => [0, 0]]);
});

it('Coda 375 — la casella di prima della .50 sulla seconda uscita (il cambio Sara → Zoe: le righe tolte, la nota, nessuna scelta nel registro): si legge come «Annulla il conguaglio», e Zoe non rimborsa a Sara', function () {
    // Il cambio Sara → Zoe com'era registrato prima della .50 con la casella «le parti hanno regolato» (decisioni 43 e 46): nessuna coppia, la nota, il registro senza la scelta. Lo si scrive nel
    // database, come i test della casella delle Code 367 e 371, perché la pagina di oggi ha le due scelte al posto della casella. Come con il cambio annullato: Sara 15189 per 154 giorni, 2959 da
    // ricevere da Pia, Zoe senza sezione. Era: Zoe 6016 «pagata da Sara».
    [$s] = pcc370Storia($this, 'scrivi', 'scrivi', 'Zoe');
    $cambio = fclUltimo($s);
    DB::table('saldi')->where('subentro_id', $cambio->id)->delete();
    $registro = $cambio->registro;
    unset($registro['conguaglio']['scelta']);
    DB::table('subentri')->where('id', $cambio->id)->update(['nota_conguaglio' => 'Le parti hanno regolato fra loro', 'registro' => json_encode($registro)]);
    expect($cambio->fresh()->conguaglioRinunciato())->toBeTrue()
        ->and($cambio->fresh()->conguaglioNonScritto())->toBeFalse()
        ->and($cambio->fresh()->conguaglioAnnullato())->toBeFalse()
        ->and(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [2959, 0, 2959, 30],
            'sara' => [15189, 0, -2959, 154],
        ], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Zoe'))->toBe([]);
});

/**
 * Ines all'80 % sull'Interno 1 e sul «Box 12», sua pertinenza (900 e 100 millesimi: l'anello 32400 e 3600); Ugo proprietario del solo Interno 1: sul box nessuno sta al posto
 * dell'inquilino, e la generazione chiede la presa d'atto dei giorni scoperti, che l'amministratore dà. Le rate da gennaio a giugno a giornale.
 */
function pcc375ConIlBox($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]], 'box' => [['Ines', 'inquilino', 80]]]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano']->fresh(), forzaApplicazioneSaldi: true, accettaScoperti: true,
        notaScoperti: 'Letto: il box non ha ancora il proprietario', accettaDestinatari: true, notaDestinatari: 'Letto: generazione del preventivo', esercizio: $s['e']);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);

    return $s;
}

/** Un passaggio dell'Interno 1 con il box spuntato, dalle rotte vere: il cambio (con chi entra) o la fine (senza); la scelta data solo se l'anteprima la chiede. */
function pcc375ConIlBoxPassaggio($test, array &$s, string $esce, ?string $entra, string $il, string $scelta = 'scrivi'): \App\Models\Gestionale\Subentro
{
    if ($entra !== null && ! isset($s['persone'][$entra])) {
        fclPersona($s, $entra);
    }
    $dati = ['quota' => (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, $esce))->value('quota')]
        + fclModulo($s, $entra !== null ? $s['persone'][$entra] : null, $il, [$s['box']], $esce);
    $chiesta = (bool) (fclAnteprima($test, $s, $dati)['rate']['scelta_del_conguaglio'] ?? false);

    return fclRegistra($test, $s, $dati + ($chiesta ? ['conguaglio' => $scelta] : []));
}

/** L'inizio della locazione di Teo il 1/12 all'80 % sull'Interno 1 con il box spuntato (la pertinenza la prende tutta), dalle rotte vere. */
function pcc375ConIlBoxTeo($test, array &$s): void
{
    fclPersona($s, 'Teo');
    fclRegistra($test, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Teo']->id, 'decorrenza' => '2026-12-01', 'quota' => 80,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => [(int) $s['box']->id]]);
}

it('Coda 375 — con il box dove nessuno sta al posto dell\'inquilino, la fine di Sara del 1/11 scrive la coppia solo sull\'Interno 1: «Annulla il conguaglio» vale per il passaggio, e sul box i giorni dopo la seconda uscita restano a Sara come sull\'Interno 1, come la fine annullata di Ines sola', function () {
    // L'anello 36000 × 900/1000 = 32400 sull'Interno 1 e 3600 sul box. Il cambio Ines → Sara del 1/7: 32400 × 184/365 = 16332,82 → 16333 e 3600 × 184/365 = 1814,79 → 1815 (le righe su tutte e due).
    // La fine di Sara del 1/11 con «Scrivi»: sull'Interno 1 la coppia a Ugo, 32400 × 61/365 = 5414,79 → 5415; sul box nessuno sta al posto dell'inquilino, e la parte, 602, resta nelle rate di Sara
    // senza righe (il pannello lo dice). L'annullamento toglie le righe dell'Interno 1 e dichiara l'accordo del passaggio: come la catena legge la fine annullata di Ines sola (decisione 46, la nota del
    // 12/10, punto 4: tutto l'anno a Ines, anche sul box, dove la fine non aveva scritto righe), il pezzo A′ si chiude anche sul box. Interno 1: il pezzo di Sara al 1/9, 16333 × 62/184 = 5503,51 →
    // 5504, e 10829; chiuso alla seconda uscita 5415 e 5414 (a pari resto chi esce); 5415 fra Pia 80 × 30 e Sara 80 × 31, 2663,11 e 2751,89 → 2663 e 2752; Sara 5504 + 2752 + 5414 = 13670, da
    // ricevere 2663. Box: 1815 × 62/184 = 611,58 → 612, e 1203; chiuso 602 e 601; 602 fra Pia e Sara, 296,07 e 305,93 → 296 e 306; Sara 612 + 306 + 601 = 1519, da ricevere 296. I controlli 16067 +
    // 13670 + 2663 = 32400 e 1785 + 1519 + 296 = 3600. Con una condizione stretta sulle righe dell'unità il box avrebbe letto la stessa uscita come «Non scriverlo» (Teo 352 «pagata da Sara», 296 da
    // restituire), mentre la fine annullata senza il rientro, sullo stesso box, torna a tutto l'anno: la stessa scelta letta in due modi.
    $s = pcc375ConIlBox($this);
    pcc375ConIlBoxPassaggio($this, $s, 'Ines', 'Sara', '2026-07-01');
    pcc375ConIlBoxPassaggio($this, $s, 'Sara', 'Pia', '2026-09-01', 'non_scrivere');
    pcc375ConIlBoxPassaggio($this, $s, 'Pia', 'Sara', '2026-10-01');
    $fine = pcc375ConIlBoxPassaggio($this, $s, 'Sara', null, '2026-11-01');
    expect(fclSaldi($s))->toBe(['Ines' => -16333, 'Sara' => 10918, 'Ugo' => 5415])
        ->and(DB::table('saldi')->where('subentro_id', $fine->id)->where('immobile_id', $s['box']->id)->exists())->toBeFalse();
    pcc366Annulla($this, $s, $fine);
    pcc375ConIlBoxTeo($this, $s);
    expect(fclSaldi($s))->toBe(['Ines' => -16333, 'Sara' => 16333])
        ->and(fclSaldi($s, $s['box']))->toBe(['Ines' => -1815, 'Sara' => 1815])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [16067, 16067, 0, 181],
            'pia' => [2663, 0, 2663, 30],
            'sara' => [13670, 0, -2663, 154],
        ], 'senza' => [0, 0]])
        ->and(fclProspetto($s, $s['box']))->toBe(['conduttori' => [
            'ines' => [1785, 1785, 0, 181],
            'pia' => [296, 0, 296, 30],
            'sara' => [1519, 0, -296, 154],
        ], 'senza' => [0, 0]]);

    // Il riferimento, sullo stesso box: Ines sola, la fine «Scrivi» del 1/11 con il box, annullata, Teo dal 1/12: tutto l'anno a Ines, 32400 e 3600 per 365 giorni.
    $s = pcc375ConIlBox($this);
    pcc366Annulla($this, $s, pcc375ConIlBoxPassaggio($this, $s, 'Ines', null, '2026-11-01'));
    pcc375ConIlBoxTeo($this, $s);
    expect(fclProspetto($s))->toBe(['conduttori' => ['ines' => [32400, 32400, 0, 365]], 'senza' => [0, 0]])
        ->and(fclProspetto($s, $s['box']))->toBe(['conduttori' => ['ines' => [3600, 3600, 0, 365]], 'senza' => [0, 0]]);
});

it('Coda 375 — il controllo del box senza l\'annullamento: la fine di Sara «Scrivi» chiude il pezzo sull\'Interno 1, dove ha scritto la coppia, e non sul box, dove i giorni dopo restano a Sara con la strada A′, come prima', function () {
    // Interno 1: dal 1/11 la strada B, 5414 su 31 + 30 giorni, 2751,38 e 2662,62 → Teo 2751 «pagata da Ugo» e 2663 senza conduttore pagati da Ugo; Sara 5504 + 2752 = 8256, da ricevere 2663 (la 370).
    // Box: nessuna riga della fine, il pezzo A′ del 1/9 fino al 31/12 (la 370 guarda le righe sull'unità): 1203 su 92 giorni coperti e 30 scoperti, 907,18 e 295,82 → 907 e 296; i 907 fra Pia 80 × 30,
    // Sara 80 × 31 e Teo 100 × 31 (l'inizio dà al box tutta la quota), 272,78, 281,88 e 352,34 → 273, 282 e 352 «pagata da Sara»; novembre 296 da restituire a Sara; Sara 612 + 282 = 894, da ricevere
    // 273 + 352 + 296 = 921.
    $s = pcc375ConIlBox($this);
    pcc375ConIlBoxPassaggio($this, $s, 'Ines', 'Sara', '2026-07-01');
    pcc375ConIlBoxPassaggio($this, $s, 'Sara', 'Pia', '2026-09-01', 'non_scrivere');
    pcc375ConIlBoxPassaggio($this, $s, 'Pia', 'Sara', '2026-10-01');
    pcc375ConIlBoxPassaggio($this, $s, 'Sara', null, '2026-11-01');
    pcc375ConIlBoxTeo($this, $s);
    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [16067, 16067, 0, 181],
        'pia' => [2663, 0, 2663, 30],
        'sara' => [8256, 0, -2663, 93],
        'teo' => [2751, 0, 2751, 31],
    ], 'senza' => [2663, 30]])
        ->and(fclProspetto($s, $s['box']))->toBe(['conduttori' => [
            'ines' => [1785, 1785, 0, 181],
            'pia' => [273, 0, 273, 30],
            'sara' => [894, 0, -921, 93],
            'teo' => [352, 0, 352, 31],
        ], 'senza' => [296, 30]]);
});

// --- Coda 379: dopo un passaggio che le parti hanno regolato fra loro, l'uscita dopo di chi ha il pezzo -----------------------------------------------------------

/** Un passaggio dell'Interno 1 dalle rotte vere: il cambio d'inquilino (con chi entra) o la fine (senza); la scelta data se l'anteprima la chiede, e solo se la chiede. */
function pcc379Passaggio($test, array &$s, string $esce, ?string $entra, string $il, string $scelta = ''): \App\Models\Gestionale\Subentro
{
    if ($entra !== null && ! isset($s['persone'][$entra])) {
        fclPersona($s, $entra);
    }
    $dati = ['quota' => (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, $esce))->value('quota')]
        + fclModulo($s, $entra !== null ? $s['persone'][$entra] : null, $il, [], $esce);
    expect((bool) (fclAnteprima($test, $s, $dati)['rate']['scelta_del_conguaglio'] ?? false))->toBe($scelta !== '', $esce . ' il ' . $il);

    return fclRegistra($test, $s, $dati + ($scelta !== '' ? ['conguaglio' => $scelta] : []));
}

/**
 * L'accordo fra le parti di un passaggio, nel momento dato (`subito` o `dopo` gli altri passaggi): «Annulla il conguaglio» dallo storico, o la casella di prima della .50 al suo posto
 * (`casella`: le righe tolte, la nota, nessuna scelta nel registro; si scrive nel database come nei test della casella delle Code 367, 371 e 375, perché la pagina di oggi ha le due
 * scelte al posto della casella); `mai`: nessun accordo.
 */
function pcc379Accordo($test, array $s, \App\Models\Gestionale\Subentro $p, string $come, string $quando): void
{
    if ($come === 'casella' && $quando === 'subito') {
        DB::table('saldi')->where('subentro_id', $p->id)->delete();
        $registro = $p->fresh()->registro;
        unset($registro['conguaglio']['scelta']);
        DB::table('subentri')->where('id', $p->id)->update(['nota_conguaglio' => 'Le parti hanno regolato fra loro', 'registro' => json_encode($registro)]);
        expect($p->fresh()->conguaglioRinunciato())->toBeTrue()
            ->and($p->fresh()->conguaglioNonScritto())->toBeFalse()
            ->and($p->fresh()->conguaglioAnnullato())->toBeFalse();
    } elseif ($come === $quando) {
        pcc366Annulla($test, $s, $p);
    }
}

/**
 * Ines sola all'80 %, il cambio Ines → Sara il 1/7 con «Scrivi» e l'accordo fra le parti (`$annulla`, vedi `pcc379Accordo`); Sara → Ines il 1/9 con «Non scriverlo» (Ines rientra); il 1/12 la
 * terza uscita di Ines, con la scelta data: la fine senza chi entra, o il cambio verso `$verso`; con la fine, Teo dal giorno dato all'80 % (null: nessuno). Lo scenario e le frasi del pannello
 * dell'inizio di Teo.
 */
function pcc379Rientro($test, string $annulla = 'subito', string $scelta = 'scrivi', ?string $verso = null, ?string $teo = '2026-12-01'): array
{
    $s = pcc358Scenario($test, [['Ines', 80]]);
    $cambio = pcc379Passaggio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc379Accordo($test, $s, $cambio, $annulla, 'subito');
    pcc379Passaggio($test, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
    pcc379Passaggio($test, $s, 'Ines', $verso, '2026-12-01', $scelta);
    $frasi = $verso === null && $teo !== null ? pcc361Inizio($test, $s, 'Teo', $teo, 80) : '';
    pcc379Accordo($test, $s, $cambio, $annulla, 'dopo');

    return [$s, $frasi];
}

/**
 * I due rientri: Ines sola all'80 %, Ines → Sara «Scrivi» il 1/7, Sara → Pia «Non scriverlo» il 1/8, Pia → Sara il 1/9 (la scelta non si chiede: nessuna rata è intestata a Pia), Sara → Zoe
 * «Scrivi» il 1/10 con l'accordo fra le parti (`$annulla`), Zoe → Sara il 1/11 con la scelta `$rientro`, e il 1/12 la terza uscita di Sara, con la scelta data: la fine senza chi entra, o il
 * cambio verso `$verso`; con la fine, Teo dal giorno dato all'80 % (null: nessuno). Lo scenario e le frasi del pannello dell'inizio di Teo.
 */
function pcc379DueRientri($test, string $annulla = 'subito', string $scelta = 'scrivi', ?string $verso = null, ?string $teo = '2026-12-01', string $rientro = 'non_scrivere'): array
{
    $s = pcc358Scenario($test, [['Ines', 80]]);
    pcc379Passaggio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc379Passaggio($test, $s, 'Sara', 'Pia', '2026-08-01', 'non_scrivere');
    pcc379Passaggio($test, $s, 'Pia', 'Sara', '2026-09-01');
    $cambio = pcc379Passaggio($test, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi');
    pcc379Accordo($test, $s, $cambio, $annulla, 'subito');
    pcc379Passaggio($test, $s, 'Zoe', 'Sara', '2026-11-01', $rientro);
    pcc379Passaggio($test, $s, 'Sara', $verso, '2026-12-01', $scelta);
    $frasi = $verso === null && $teo !== null ? pcc361Inizio($test, $s, 'Teo', $teo, 80) : '';
    pcc379Accordo($test, $s, $cambio, $annulla, 'dopo');

    return [$s, $frasi];
}

/** Il registro dell'inizio di Teo: i giorni del conduttore che la registrazione ha scritto, [cifra, chi li ha pagati]. */
function pcc379GiorniDelConduttore(array $s): array
{
    $inizio = \App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']->id)->where('tipo_passaggio', 'inizio_locazione')
        ->where('anagrafica_entrante_id', $s['persone']['Teo']->id)->latest('id')->firstOrFail();

    return collect($inizio->registro['giorni_del_conduttore'] ?? [])->map(fn ($g) => [(int) $g['cifra'], array_values((array) ($g['pagato_da'] ?? []))])->all();
}

it('Coda 379 — Ines → Sara «Scrivi» il 1/7 annullato, Sara → Ines «Non scriverlo» il 1/9 (Ines rientra), la fine di Ines «Scrivi» il 1/12 e Teo dal 1/12: l\'accordo fra Ines e Sara non va oltre l\'uscita di Ines, e Teo rimborsa a Ugo dicembre, € 30,58', function () {
    // Il cambio del 1/7, 36000 × 184/365 = 18147,95 → 18148, le parti l'hanno regolato fra loro (decisioni 43 e 46): i giorni di Sara restano dov'erano, nelle rate di Ines. La fine di Ines
    // del 1/12 scrive la coppia 36000 × 31/365 = 3057,53 → 3058 (Ines − 3058, Ugo + 3058), e il pannello della fine lo dice: dicembre l'ha preso Ugo. L'accordo fra Ines e Sara non riguarda né
    // Ugo né Teo: la fine di Ines si legge come senza l'accordo prima, con la strada B (decisioni 32 e 77). Ines 36000 su 334 + 31 giorni, 32942,47 → 32942 per 334 giorni nelle sue rate;
    // dicembre tutto nei giorni di Teo, 3058 «pagata da Ugo», come dice il registro del suo inizio; il controllo 32942 + 3058 = 36000. Era: la catena si fermava all'accordo, Ines 36000 per
    // 365 giorni e Teo senza sezione: Ugo non riaveva da nessuno i € 30,58 della coppia, e il pannello dell'inizio di Teo diceva che le parti avevano regolato fra loro.
    [$s, $frasi] = pcc379Rientro($this);
    expect($frasi)->toContain('e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
        ->and($frasi)->not->toContain('le parti lo hanno regolato fra loro')
        ->and(fclSaldi($s))->toBe(['Ines' => -3058, 'Ugo' => 3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [32942, 32942, 0, 334],
            'teo' => [3058, 0, 3058, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3058])
        ->and(pcc379GiorniDelConduttore($s))->toBe([[3058, ['Ugo']]]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Ines € 329,42')
        ->and($stampa)->toContain('Totale a carico di Teo € 30,58')
        ->and($stampa)->toContain('pagata da Ugo con il conguaglio della fine locazione del 01/12/2026')
        ->and($stampa)->not->toContain('Totale a carico di Ines € 360,00');
});

it('Coda 379 — lo stesso senza Teo, con la fine di Ines «Non scriverlo», con Teo dal 15/12 e con «Annulla il conguaglio» dato per ultimo: dicembre si legge come la fine di Ines senza l\'accordo prima', function () {
    // Senza Teo: dicembre, 3058, resta a Ugo che l'ha pagato con la coppia, «senza conduttore». Era: Ines 36000 per 365 giorni, e nessuna voce di Ugo.
    [$s] = pcc379Rientro($this, teo: null);
    expect(fclProspetto($s))->toBe(['conduttori' => ['ines' => [32942, 32942, 0, 334]], 'senza' => [3058, 31]])
        ->and(pcc370Senza($s))->toBe([[3058, 31, 'proprietario', 'Ugo', '2026-12-01', 'B']]);

    // La fine di Ines «Non scriverlo»: la strada A′, dicembre resta nelle sue rate e Teo glielo rimborsa. Ines 32942, nelle sue rate 36000, da ricevere 3058; Teo 3058 «pagata da Ines».
    // Era: Ines 36000, da ricevere 0, Teo senza sezione: Ines pagava i giorni di Teo.
    [$s, $frasi] = pcc379Rientro($this, scelta: 'non_scrivere');
    expect($frasi)->toContain('li rimborsa Teo a Ines')
        ->and(fclSaldi($s))->toBe([])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [32942, 36000, -3058, 334],
            'teo' => [3058, 0, 3058, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ines' => 3058]);

    // Teo dal 15/12: i 3058 di dicembre su 14 + 17 giorni, 1381,03 e 1676,97 → 1381 senza conduttore e Teo 1677, tutti e due pagati da Ugo con la coppia (il registro dell'inizio di Teo dice
    // 1677). Era: nessuno dei due.
    [$s] = pcc379Rientro($this, teo: '2026-12-15');
    expect(fclProspetto($s)['conduttori'])->toBe(['ines' => [32942, 32942, 0, 334], 'teo' => [1677, 0, 1677, 17]])
        ->and(pcc370Senza($s))->toBe([[1381, 14, 'proprietario', 'Ugo', '2026-12-01', 'B']])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 1677])
        ->and(pcc379GiorniDelConduttore($s))->toBe([[1677, ['Ugo']]]);

    // «Annulla il conguaglio» del cambio del 1/7 dato per ultimo, dopo l'inizio di Teo: lo stesso prospetto, l'ordine non conta.
    [$s] = pcc379Rientro($this, annulla: 'dopo');
    expect(fclSaldi($s))->toBe(['Ines' => -3058, 'Ugo' => 3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [32942, 32942, 0, 334], 'teo' => [3058, 0, 3058, 31]], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3058]);
});

it('Coda 379 — la terza uscita di Ines con il cambio Ines → Teo il 1/12: con «Scrivi» Teo ha dicembre con il conguaglio, come nel Wallet; con «Non scriverlo» lo rimborsa a Ines, € 30,58', function () {
    // Il cambio del 1/12 scrive la coppia 3058 (Ines − 3058, Teo + 3058): Teo 3058 «con il conguaglio», niente da rimborsare; Ines 32942 per 334 giorni. Era: Ines 36000 per 365 giorni e Teo
    // senza sezione, mentre il Wallet gli aveva già fatto pagare dicembre.
    [$s] = pcc379Rientro($this, verso: 'Teo');
    expect(fclSaldi($s))->toBe(['Ines' => -3058, 'Teo' => 3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [32942, 32942, 0, 334],
            'teo' => [3058, 0, 0, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Teo'))->toBe([3058, 3058, []])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe([]);

    // Con «Non scriverlo» la strada A′ del cambio, come la fine «Non scriverlo»: Ines da ricevere 3058, Teo 3058 «pagata da Ines». Era: Teo senza sezione.
    [$s] = pcc379Rientro($this, scelta: 'non_scrivere', verso: 'Teo');
    expect(fclSaldi($s))->toBe([])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [32942, 36000, -3058, 334],
            'teo' => [3058, 0, 3058, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ines' => 3058]);
});

it('Coda 379 — i due rientri: Sara → Zoe «Scrivi» il 1/10 annullato, Zoe → Sara «Non scriverlo» il 1/11, la fine di Sara «Scrivi» il 1/12 e Teo dal 1/12: l\'accordo fra Sara e Zoe non va oltre la terza uscita di Sara, e Teo rimborsa a Ugo dicembre, € 30,58', function () {
    // Sara 18148 dal 1/7 con il conguaglio (36000 × 184/365); al 1/8 «Non scriverlo»: 18148 su 31 + 153 giorni, 3057,54 → 3058 e 15090. Il pezzo A′ si chiude al cambio annullato del 1/10
    // (Coda 375): 15090 su 61 + 92, 6016 e 9074; i 6016 fra Pia (80 × 31) e Sara al primo rientro (80 × 30), 3057,31 e 2958,69 → Pia 3057 «pagata da Sara» e Sara 2959. L'accordo fra Sara e
    // Zoe vale fino alla terza uscita di Sara: 9074 su 61 + 31, 6016,46 e 3057,54 → 6016 restano a Sara, e dicembre, 3058, l'ha preso Ugo con la coppia della fine (Sara − 3058, Ugo + 3058:
    // 18148 × 31/184): tutto nei giorni di Teo, «pagata da Ugo», come dice il registro del suo inizio. Sara 3058 + 2959 + 6016 = 12033 per 31 + 30 + 61 = 122 giorni, da ricevere 3057, con il
    // conguaglio 3058 + 3057 + 2959 + 6016 = 15090, la cifra del Wallet; Zoe senza sezione (i suoi giorni sono nell'accordo); il controllo 17852 + 12033 + 3057 + 3058 = 36000. Era: la catena si
    // fermava al cambio annullato, Sara 15091 per 153 giorni con il conguaglio 18148, e Teo senza sezione: Ugo non riaveva i € 30,58 della coppia.
    [$s, $frasi] = pcc379DueRientri($this);
    expect($frasi)->toContain('e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
        ->and($frasi)->not->toContain('le parti lo hanno regolato fra loro')
        ->and(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 15090, 'Ugo' => 3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [3057, 0, 3057, 31],
            'sara' => [12033, 0, -3057, 122],
            'teo' => [3058, 0, 3058, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([12033, fclSaldi($s)['Sara'], []])
        ->and(pcc360PagateDa($s, 'Pia'))->toBe(['Sara' => 3057])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3058])
        ->and(pcc379GiorniDelConduttore($s))->toBe([[3058, ['Ugo']]]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Sara € 120,33')
        ->and($stampa)->toContain('Totale a carico di Teo € 30,58')
        ->and($stampa)->not->toContain('€ 150,91');
});

it('Coda 379 — i due rientri con la fine di Sara «Non scriverlo», senza Teo, con Teo dal 15/12 e con «Annulla il conguaglio» dato per ultimo', function () {
    // «Non scriverlo»: dicembre resta nelle rate di Sara (la strada A′), e Teo glielo rimborsa: Sara da ricevere 3057 + 3058 = 6115, netta 12033; Teo 3058 «pagata da Sara». Era: Teo senza
    // sezione, Sara da ricevere 3057.
    [$s, $frasi] = pcc379DueRientri($this, scelta: 'non_scrivere');
    expect($frasi)->toContain('li rimborsa Teo a Sara')
        ->and(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(fclProspetto($s)['conduttori']['sara'])->toBe([12033, 0, -6115, 122])
        ->and(fclProspetto($s)['conduttori']['teo'])->toBe([3058, 0, 3058, 31])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 3058]);

    // Senza Teo: dicembre a Ugo, «senza conduttore»; Sara 12033 con il conguaglio 15090, il Wallet. Era: Sara 15091 con il conguaglio 18148, e nessuna voce di Ugo.
    [$s] = pcc379DueRientri($this, teo: null);
    expect(fclProspetto($s)['conduttori']['sara'])->toBe([12033, 0, -3057, 122])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([12033, 15090, []])
        ->and(pcc370Senza($s))->toBe([[3058, 31, 'proprietario', 'Ugo', '2026-12-01', 'B']]);

    // Teo dal 15/12: 3058 su 14 + 17 giorni, 1381 senza conduttore e Teo 1677, pagati da Ugo (il registro dell'inizio di Teo dice 1677).
    [$s] = pcc379DueRientri($this, teo: '2026-12-15');
    expect(fclProspetto($s)['conduttori']['teo'])->toBe([1677, 0, 1677, 17])
        ->and(pcc370Senza($s))->toBe([[1381, 14, 'proprietario', 'Ugo', '2026-12-01', 'B']])
        ->and(pcc379GiorniDelConduttore($s))->toBe([[1677, ['Ugo']]]);

    // «Annulla il conguaglio» del cambio Sara → Zoe dato per ultimo, dopo l'inizio di Teo: lo stesso prospetto.
    [$s] = pcc379DueRientri($this, annulla: 'dopo');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 15090, 'Ugo' => 3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [3057, 0, 3057, 31],
            'sara' => [12033, 0, -3057, 122],
            'teo' => [3058, 0, 3058, 31],
        ], 'senza' => [0, 0]]);
});

it('Coda 379 — i due rientri con la terza uscita di Sara il cambio Sara → Teo il 1/12: con «Scrivi» Teo ha dicembre con il conguaglio, come nel Wallet; con «Non scriverlo» lo rimborsa a Sara', function () {
    // Il cambio scrive la coppia 18148 × 31/184 = 3057,54 → 3058 (Sara − 3058, Teo + 3058): Teo 3058 «con il conguaglio»; Sara come con la fine, 12033 e 3057 da ricevere. Era: Sara 15091 per 153
    // giorni e Teo senza sezione.
    [$s] = pcc379DueRientri($this, verso: 'Teo');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 15090, 'Teo' => 3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [3057, 0, 3057, 31],
            'sara' => [12033, 0, -3057, 122],
            'teo' => [3058, 0, 0, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Teo'))->toBe([3058, 3058, []]);

    // «Non scriverlo»: la strada A′ del cambio, Teo 3058 «pagata da Sara», Sara da ricevere 6115.
    [$s] = pcc379DueRientri($this, scelta: 'non_scrivere', verso: 'Teo');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(fclProspetto($s)['conduttori']['sara'])->toBe([12033, 0, -6115, 122])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 3058]);
});

it('Coda 379 — la casella di prima della .50 al posto di «Annulla il conguaglio» (le righe tolte, la nota, nessuna scelta nel registro), sul cambio Ines → Sara e sul cambio Sara → Zoe dei due rientri: l\'accordo non va oltre l\'uscita dopo, come con l\'annullamento', function () {
    // Un cambio registrato prima della .50 con la casella e, dopo l'aggiornamento, il rientro e la terza uscita: le stesse cifre di «Annulla il conguaglio». Era: la catena si fermava alla casella,
    // Ines 36000 per 365 giorni (Sara 15091 nei due rientri) e Teo senza sezione.
    [$s, $frasi] = pcc379Rientro($this, annulla: 'casella');
    expect($frasi)->toContain('e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Ines' => -3058, 'Ugo' => 3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [32942, 32942, 0, 334], 'teo' => [3058, 0, 3058, 31]], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3058]);

    [$s] = pcc379DueRientri($this, annulla: 'casella');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 15090, 'Ugo' => 3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [3057, 0, 3057, 31],
            'sara' => [12033, 0, -3057, 122],
            'teo' => [3058, 0, 3058, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3058]);
});

it('Coda 379 — dopo l\'accordo la terza uscita di Ines è regolata fra le parti anche lei (il cambio Ines → Zoe «Scrivi» il 1/10 annullato) e Zoe esce con la fine «Scrivi» il 1/11 (decisione 83.3), Teo dal 1/11: la catena segue la fine di Zoe, come senza l\'accordo prima, e Teo rimborsa a Ugo € 60,16', function () {
    // La terza uscita annullata non si salta: è un altro accordo fra le parti, e la catena la legge come ogni passaggio regolato fra le parti. Zoe esce con la coppia scritta (Zoe − 6016, Ugo
    // + 6016: 36000 × 61/365 = 6016,44): la 83.3 segue la catena. Ines 36000 su 273 + 92 giorni, 26926,03 → 26926 per 273 giorni nelle sue rate (i giorni di Sara nell'accordo del 1/7); i 9074
    // dal 1/10 su 31 + 61, 3057,54 e 6016,46 → Zoe 3058 «regolata fra le parti» e 6016 della strada B della fine di Zoe, tutti nei giorni di Teo, «pagata da Ugo»; il controllo 26926 + 3058 +
    // 6016 = 36000. Sono le cifre del riferimento senza il cambio del 1/7, qui sotto. Era: Ines 36000 per 365 giorni, Teo e Zoe senza sezione: Ugo non riaveva da nessuno i € 60,16 della coppia.
    foreach (['con l\'accordo prima', 'senza'] as $storia) {
        $s = pcc358Scenario($this, [['Ines', 80]]);
        if ($storia !== 'senza') {
            pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi'));
            pcc379Passaggio($this, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
        }
        pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Ines', 'Zoe', '2026-10-01', 'scrivi'));
        pcc379Passaggio($this, $s, 'Zoe', null, '2026-11-01', 'scrivi');
        $frasi = pcc361Inizio($this, $s, 'Teo', '2026-11-01', 80);
        expect($frasi)->toContain('e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
            ->and(fclSaldi($s))->toBe(['Ugo' => 6016, 'Zoe' => -6016], $storia)
            ->and(fclProspetto($s))->toBe(['conduttori' => [
                'ines' => [26926, 26926, 0, 273],
                'teo' => [6016, 0, 6016, 61],
                'zoe' => [3058, 0, 0, 31],
            ], 'senza' => [0, 0]], $storia)
            ->and(pcc366Sezione($s, 'Zoe'))->toBe([3058, 3058, []], $storia)
            ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 6016], $storia);
    }

    // La quarta uscita: dopo il cambio Ines → Zoe annullato, Zoe → Ines «Non scriverlo» il 1/11 (Ines rientra di nuovo) e la fine di Ines «Scrivi» il 1/12, Teo dal 1/12: i giorni fino al 30/11
    // sono nei due accordi, nelle rate di Ines, e dicembre si legge come la fine di Ines: Ines 32942 per 334 giorni, Teo 3058 «pagata da Ugo».
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi'));
    pcc379Passaggio($this, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Ines', 'Zoe', '2026-10-01', 'scrivi'));
    pcc379Passaggio($this, $s, 'Zoe', 'Ines', '2026-11-01', 'non_scrivere');
    pcc379Passaggio($this, $s, 'Ines', null, '2026-12-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect(fclSaldi($s))->toBe(['Ines' => -3058, 'Ugo' => 3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [32942, 32942, 0, 334], 'teo' => [3058, 0, 3058, 31]], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3058]);
});

it('Coda 379 — con il box dove nessuno sta al posto dell\'inquilino: la fine di Ines «Scrivi» del 1/12 scrive la coppia solo sull\'Interno 1, e sul box dicembre resta nelle rate di Ines, che Teo le rimborsa, come senza l\'accordo prima', function () {
    // L'anello 32400 sull'Interno 1 e 3600 sul box (900 e 100 millesimi). La fine di Ines del 1/12 è «Scrivi»: sull'Interno 1 la coppia a Ugo, 32400 × 31/365 = 2751,78 → 2752; sul box nessuno sta
    // al posto dell'inquilino, e la parte, 3600 × 31/365 = 305,75 → 306, resta nelle rate di Ines (la strada A′: punto 4 della lista della .50). La scelta è del passaggio: dopo l'accordo del 1/7 la
    // fine si legge su tutte e due le unità come senza l'accordo prima. Interno 1: Ines 29648 per 334 giorni, Teo 2752 «pagata da Ugo»; box: Ines 3294, nelle sue rate 3600, da ricevere 306, Teo
    // 306 «pagata da Ines». Era: Ines tutto l'anno sulle due unità e Teo senza sezione; con l'accordo che si ferma solo alle uscite con le righe su questa unità, sul box restava tutto l'anno a
    // Ines, € 3,06 che Teo non le rimborsava.
    foreach (['con l\'accordo prima', 'senza'] as $storia) {
        $s = pcc375ConIlBox($this);
        if ($storia !== 'senza') {
            pcc366Annulla($this, $s, pcc375ConIlBoxPassaggio($this, $s, 'Ines', 'Sara', '2026-07-01'));
            pcc375ConIlBoxPassaggio($this, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
        }
        pcc375ConIlBoxPassaggio($this, $s, 'Ines', null, '2026-12-01');
        pcc375ConIlBoxTeo($this, $s);
        $teoSulBox = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['box']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Teo');
        expect(fclSaldi($s))->toBe(['Ines' => -2752, 'Ugo' => 2752], $storia)
            ->and(fclSaldi($s, $s['box']))->toBe([], $storia)
            ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [29648, 29648, 0, 334], 'teo' => [2752, 0, 2752, 31]], 'senza' => [0, 0]], $storia)
            ->and(fclProspetto($s, $s['box']))->toBe(['conduttori' => ['ines' => [3294, 3600, -306, 334], 'teo' => [306, 0, 306, 31]], 'senza' => [0, 0]], $storia)
            ->and(collect($teoSulBox['voci'] ?? [])->filter(fn ($v) => ! empty($v['pagato_da']))->groupBy('pagato_da')->map(fn ($g) => (int) $g->sum('importo'))->all())->toBe(['Ines' => 306], $storia);
    }
});

it('Coda 379 — le guardie della storia di Ines che rientra: la 83.3 (Sara → Ines «Scrivi»), la fine senza la scelta dopo il rientro con un inizio o dopo la fine annullata (i giorni restano nell\'accordo) e la terza uscita annullata restano come prima', function () {
    // Sara → Ines «Scrivi» il 1/9: il cambio del 1/7 regolato fra le parti e l'uscita «Scrivi» di Sara, la catena la segue (decisione 83.3): Sara 18148 × 62/184 = 6115,07 → 6115 «regolata fra le
    // parti» per 62 giorni, e dicembre a Teo «pagata da Ugo», 3058.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi'));
    pcc379Passaggio($this, $s, 'Sara', 'Ines', '2026-09-01', 'scrivi');
    pcc379Passaggio($this, $s, 'Ines', null, '2026-12-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect(fclProspetto($s)['conduttori']['sara'])->toBe([6115, 0, 0, 62])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3058]);

    // Il rientro di Ines con un inizio (la fine di Sara «Non scriverlo» il 1/9, l'inizio di Ines il 1/9), poi la fine di Ines il 1/12: la scelta non si chiede (la parte dell'unità Ines l'ha già
    // ceduta con il cambio del 1/7) e non c'è una coppia; i giorni dopo restano nell'accordo del 1/7: Ines 36000 per 365 giorni, Teo senza sezione (decisioni 43 e 46).
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi'));
    pcc379Passaggio($this, $s, 'Sara', null, '2026-09-01', 'non_scrivere');
    pcc361Inizio($this, $s, 'Ines', '2026-09-01', 80);
    pcc379Passaggio($this, $s, 'Ines', null, '2026-12-01');
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect(fclSaldi($s))->toBe([])
        ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [36000, 36000, 0, 365]], 'senza' => [0, 0]]);

    // Ines sola, la fine «Scrivi» del 1/10 annullata, Teo dal 15/10, Teo → Ines il 1/11 (Ines rientra), la fine di Ines il 1/12 senza la scelta: tutto nell'accordo del 1/10, Ines 36000 per 365
    // giorni (Coda 367: chi entra dopo l'accordo non divide).
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Ines', null, '2026-10-01', 'scrivi'));
    pcc361Inizio($this, $s, 'Teo', '2026-10-15', 80);
    pcc379Passaggio($this, $s, 'Teo', 'Ines', '2026-11-01');
    pcc379Passaggio($this, $s, 'Ines', null, '2026-12-01');
    expect(fclSaldi($s))->toBe([])
        ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [36000, 36000, 0, 365]], 'senza' => [0, 0]]);

    // La terza uscita di Ines una fine «Scrivi» annullata anche lei, Teo dal 1/12: due accordi, Ines 36000 per 365 giorni, Teo senza sezione.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi'));
    pcc379Passaggio($this, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Ines', null, '2026-12-01', 'scrivi'));
    $frasi = pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect($frasi)->toContain('le parti lo hanno regolato fra loro')
        ->and(fclSaldi($s))->toBe([])
        ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [36000, 36000, 0, 365]], 'senza' => [0, 0]]);
});

it('Coda 379 — le guardie dei due rientri: la seconda uscita una fine annullata e la terza senza la scelta, la seconda uscita «Non scriverlo» con la terza annullata o no (Coda 375 e 370), e Zoe → Sara «Scrivi» (decisione 83.3) restano come prima', function () {
    // La fine di Sara «Scrivi» del 1/10 annullata, Teo dal 15/10, Teo → Sara il 1/11, la fine di Sara il 1/12 senza la scelta: dal 1/10 tutto nell'accordo, Sara 3058 + 2959 + 9074 = 15091 per
    // 153 giorni, da ricevere 3057 da Pia; Teo senza sezione.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc379Passaggio($this, $s, 'Sara', 'Pia', '2026-08-01', 'non_scrivere');
    pcc379Passaggio($this, $s, 'Pia', 'Sara', '2026-09-01');
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Sara', null, '2026-10-01', 'scrivi'));
    pcc361Inizio($this, $s, 'Teo', '2026-10-15', 80);
    pcc379Passaggio($this, $s, 'Teo', 'Sara', '2026-11-01');
    pcc379Passaggio($this, $s, 'Sara', null, '2026-12-01');
    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [17852, 17852, 0, 181],
        'pia' => [3057, 0, 3057, 31],
        'sara' => [15091, 0, -3057, 153],
    ], 'senza' => [0, 0]]);

    // La seconda uscita Sara → Zoe «Non scriverlo» il 1/10, Zoe → Sara il 1/11 (la scelta non si chiede), la fine di Sara «Scrivi» il 1/12 annullata, Teo dal 1/12: il pezzo A′ si chiude alla
    // terza uscita annullata (Coda 375), 15090 su 122 + 31 giorni, 12033 e 3057; i 12033 fra Pia (80 × 31), Sara (80 × 60) e Zoe (80 × 31): 3057,57, 5917,87 e 3057,57 → 3058 (a pari resto Pia
    // prima di Zoe), 5918 e 3057; dicembre nell'accordo. Sara 3058 + 5918 + 3057 = 12033, da ricevere 6115; Teo senza sezione. Senza l'annullamento (la Coda 370): dicembre della strada B,
    // 3057, Teo «pagata da Ugo».
    foreach ([true, false] as $annulla) {
        $s = pcc358Scenario($this, [['Ines', 80]]);
        pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
        pcc379Passaggio($this, $s, 'Sara', 'Pia', '2026-08-01', 'non_scrivere');
        pcc379Passaggio($this, $s, 'Pia', 'Sara', '2026-09-01');
        pcc379Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'non_scrivere');
        pcc379Passaggio($this, $s, 'Zoe', 'Sara', '2026-11-01');
        $fine = pcc379Passaggio($this, $s, 'Sara', null, '2026-12-01', 'scrivi');
        if ($annulla) {
            pcc366Annulla($this, $s, $fine);
        }
        pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
        expect(fclProspetto($s)['conduttori']['sara'])->toBe($annulla ? [12033, 0, -6115, 122] : [8976, 0, -6115, 91])
            ->and(fclProspetto($s)['conduttori']['pia'])->toBe([3058, 0, 3058, 31])
            ->and(fclProspetto($s)['conduttori']['zoe'])->toBe([3057, 0, 3057, 31])
            ->and(pcc360PagateDa($s, 'Teo'))->toBe($annulla ? [] : ['Ugo' => 3057]);
    }

    // Zoe → Sara «Scrivi» il 1/11: il cambio Sara → Zoe regolato fra le parti e l'uscita «Scrivi» di Zoe, la catena la segue (83.3): 9074 su 31 + 61, Zoe 3058 «regolata fra le parti»; 6016 su
    // 30 + 31, Sara novembre 2959 e dicembre 3057 della strada B, Teo «pagata da Ugo». Sara 3058 + 2959 + 2959 = 8976 per 91 giorni, da ricevere 3057.
    [$s] = pcc379DueRientri($this, rientro: 'scrivi');
    expect(fclProspetto($s)['conduttori']['sara'])->toBe([8976, 0, -3057, 91])
        ->and(pcc366Sezione($s, 'Zoe'))->toBe([3058, 3058, []])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3057]);
});

// --- Coda 380: dopo il cambio «Non scriverlo» di chi era entrato con il cambio «Scrivi», l'uscita con la coppia di chi rientra ------------------------------------------

it('Coda 380 — Ines → Sara «Scrivi» il 1/7, Sara → Ines «Non scriverlo» il 1/9 (Ines rientra), la fine di Ines «Scrivi» il 1/12 e Teo dal 1/12: Teo rimborsa a Ugo dicembre, e Ines rimborsa a Sara € 120,33', function () {
    // Il cambio del 1/7 dà a Sara 36000 × 184/365 = 18147,95 → 18148 (Ines − 18148, Sara + 18148). Al 1/9 «Non scriverlo»: i giorni dopo l'uscita di Sara, 18148 su 62 + 122 giorni, 6115,07 e
    // 12032,93 → 6115 e 12033, restano a Sara, e Ines glieli rimborsa (il registro del cambio: Sara − 12033, Ines + 12033). La fine di Ines del 1/12 «Scrivi» scrive la coppia 36000 × 31/365 =
    // 3057,53 → 3058 (Ines − 3058, Ugo + 3058): dicembre l'ha preso Ugo, e Teo glielo rimborsa (decisione 45, nota del 12/10, e 77.4), come dice il registro del suo inizio. Quella coppia dà a Ines
    // a credito dicembre, che aveva già dato a Sara: Ines rimborsa a Sara tutti i 12033, 8975 dei suoi 91 giorni (12033 × 91/122 = 8975,43) e 3058 di dicembre. Ines 17852 + 8975 = 26827 per 272
    // giorni, nelle sue rate 36000 − 18148 − 3058 = 14794 (il Wallet), da rimborsare 12033; Sara 6115 per 62 giorni con il conguaglio 18148; Teo 3058 «pagata da Ugo»; il controllo 26827 + 6115 +
    // 3058 = 36000. Era: Ines nelle sue rate 17852 (la coppia del 1/12 non c'era), da rimborsare 8975, e Teo 3058 «pagata da Sara»: Ugo non riaveva i € 30,58 della coppia, e Ines ne pagava
    // € 30,58 in meno dei suoi giorni.
    [$s, $frasi] = pcc379Rientro($this, 'mai');
    expect($frasi)->toContain('e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
        ->and($frasi)->not->toContain('li rimborsa Teo a Sara')
        ->and(fclSaldi($s))->toBe(['Ines' => -21206, 'Sara' => 18148, 'Ugo' => 3058])
        ->and(fclNetti($s)['Ines'])->toBe(14794)
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [26827, 14794, 12033, 272],
            'sara' => [6115, 0, -12033, 62],
            'teo' => [3058, 0, 3058, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([6115, 18148, []])
        ->and(pcc360PagateDa($s, 'Ines'))->toBe(['Sara' => 12033])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3058])
        ->and(pcc379GiorniDelConduttore($s))->toBe([[3058, ['Ugo']]]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Ines € 268,27 di cui già nelle sue rate € 147,94')
        ->and($stampa)->toContain('pagata da Ugo con il conguaglio della fine locazione del 01/12/2026')
        ->and($stampa)->not->toContain('di cui già nelle sue rate € 178,52');
});

it('Coda 380 — la stessa storia con Teo dal 15/12, senza Teo, con la terza uscita il cambio Ines → Teo «Scrivi» e con il box: Ines rimborsa a Sara € 120,33, e dicembre segue la coppia del 1/12', function () {
    // Teo dal 15/12: i 3058 di dicembre su 14 + 17 giorni, 1381,03 e 1676,97 → 1381 senza conduttore e Teo 1677, tutti e due «pagata da Ugo» con la strada B (il registro dell'inizio di Teo dice
    // 1677). Era: Teo 1677 «pagata da Sara» e i 1381 «da restituire» a Sara, che Ugo aveva già pagato con la coppia.
    [$s] = pcc379Rientro($this, 'mai', teo: '2026-12-15');
    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [26827, 14794, 12033, 272],
        'sara' => [6115, 0, -12033, 62],
        'teo' => [1677, 0, 1677, 17],
    ], 'senza' => [1381, 14]])
        ->and(pcc370Senza($s))->toBe([[1381, 14, 'proprietario', 'Ugo', '2026-12-01', 'B']])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 1677])
        ->and(pcc379GiorniDelConduttore($s))->toBe([[1677, ['Ugo']]]);

    // Senza Teo: dicembre resta a Ugo, che l'ha pagato con la coppia («senza conduttore», strada B), e non si restituisce a Sara. Era: 3058 «da restituire» a Sara fra le parti.
    [$s] = pcc379Rientro($this, 'mai', teo: null);
    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [26827, 14794, 12033, 272],
        'sara' => [6115, 0, -12033, 62],
    ], 'senza' => [3058, 31]])
        ->and(pcc370Senza($s))->toBe([[3058, 31, 'proprietario', 'Ugo', '2026-12-01', 'B']]);

    // Il cambio Ines → Teo «Scrivi» il 1/12 scrive la coppia 3058 (Ines − 3058, Teo + 3058): Teo ha dicembre «con il conguaglio», come nel Wallet, e non rimborsa nessuno. Era: Teo 3058 «pagata
    // da Sara», cioè dicembre due volte, dal Wallet e dal prospetto.
    [$s] = pcc379Rientro($this, 'mai', verso: 'Teo');
    expect(fclSaldi($s))->toBe(['Ines' => -21206, 'Sara' => 18148, 'Teo' => 3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [26827, 14794, 12033, 272],
            'sara' => [6115, 0, -12033, 62],
            'teo' => [3058, 0, 0, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Teo'))->toBe([3058, 3058, []])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe([]);

    // Con il box (l'anello 32400 sull'Interno 1 e 3600 sul box): sull'Interno 1 la coppia del 1/12, 32400 × 31/365 = 2751,78 → 2752 (Ines − 2752, Ugo + 2752), e la storia di sopra: Ines rimborsa a
    // Sara 10829 (8077 + 2752), Teo 2752 «pagata da Ugo». Sul box nessuno sta al posto dell'inquilino e la fine non scrive righe: dicembre resta nei giorni dopo l'uscita di Sara, come prima.
    $s = pcc375ConIlBox($this);
    pcc375ConIlBoxPassaggio($this, $s, 'Ines', 'Sara', '2026-07-01');
    pcc375ConIlBoxPassaggio($this, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
    pcc375ConIlBoxPassaggio($this, $s, 'Ines', null, '2026-12-01');
    pcc375ConIlBoxTeo($this, $s);
    expect(fclSaldi($s))->toBe(['Ines' => -19085, 'Sara' => 16333, 'Ugo' => 2752])
        ->and(fclSaldi($s, $s['box']))->toBe(['Ines' => -1815, 'Sara' => 1815])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [24144, 13315, 10829, 272],
            'sara' => [5504, 0, -10829, 62],
            'teo' => [2752, 0, 2752, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 2752])
        ->and(fclProspetto($s, $s['box'])['conduttori']['sara'])->toBe([612, 0, -1203, 62]);
});

it('Coda 380 — le guardie: la fine di Ines o il cambio Ines → Teo con «Non scriverlo», il rientro con un inizio e un altro conduttore al posto di Sara restano come prima', function () {
    // Con «Non scriverlo» alla fine di Ines (o nel cambio Ines → Teo) nel Wallet non c'è la coppia del 1/12: dicembre resta nei giorni dopo l'uscita di Sara, e Teo lo rimborsa a lei. Ines 17852
    // nelle sue rate, 8975 a Sara; Teo 3058 «pagata da Sara».
    foreach ([null, 'Teo'] as $verso) {
        [$s] = pcc379Rientro($this, 'mai', scelta: 'non_scrivere', verso: $verso);
        expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
            ->and(fclProspetto($s)['conduttori'])->toBe([
                'ines' => [26827, 17852, 8975, 272],
                'sara' => [6115, 0, -12033, 62],
                'teo' => [3058, 0, 3058, 31],
            ])
            ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 3058], (string) $verso);
    }

    // Il rientro di Ines con la fine di Sara «Non scriverlo» e un inizio: la fine di Ines del 1/12 non chiede la scelta (la parte l'ha già ceduta con il cambio del 1/7) e non scrive la coppia.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc379Passaggio($this, $s, 'Sara', null, '2026-09-01', 'non_scrivere');
    pcc361Inizio($this, $s, 'Ines', '2026-09-01', 80);
    pcc379Passaggio($this, $s, 'Ines', null, '2026-12-01');
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(fclProspetto($s)['conduttori'])->toBe(['ines' => [26827, 17852, 8975, 272], 'sara' => [6115, 0, -12033, 62], 'teo' => [3058, 0, 3058, 31]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 3058]);

    // Pia al posto di Sara con «Non scriverlo» e la fine di Pia il 1/12: Pia non ha rate, la fine non chiede la scelta e non scrive la coppia.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc379Passaggio($this, $s, 'Sara', 'Pia', '2026-09-01', 'non_scrivere');
    pcc379Passaggio($this, $s, 'Pia', null, '2026-12-01');
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect(fclProspetto($s)['conduttori'])->toBe(['ines' => [17852, 17852, 0, 181], 'pia' => [8975, 0, 8975, 91], 'sara' => [6115, 0, -12033, 62], 'teo' => [3058, 0, 3058, 31]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 3058]);
});

it('Coda 380 — i due rientri senza l\'annullamento: Sara → Zoe «Scrivi» il 1/10, Zoe → Sara «Non scriverlo» il 1/11, la fine di Sara «Scrivi» il 1/12 e Teo dal 1/12: Teo rimborsa a Ugo, e Sara rimborsa a Zoe € 60,16', function () {
    // Sara 18148 dal 1/7 (36000 × 184/365); al 1/8 «Non scriverlo», 3058 e 15090; il pezzo A′ si chiude al cambio Sara → Zoe del 1/10 (Coda 370): 15090 su 61 + 92, 6016 e 9074; i 6016 fra Pia
    // (agosto) e Sara (settembre), 3057 e 2959. Il cambio del 1/10 scrive la coppia 9074 (Sara − 9074, Zoe + 9074); al 1/11 «Non scriverlo»: 9074 su 31 + 61, 3058 e 6016, restano a Zoe, e
    // Sara glieli rimborsa (il registro del cambio: Zoe − 6016, Sara + 6016). La fine di Sara del 1/12 scrive la coppia 18148 × 31/184 = 3057,54 → 3058 (Sara − 3058, Ugo + 3058): dicembre l'ha
    // preso Ugo, e Teo glielo rimborsa; la coppia dà a Sara a credito dicembre, che aveva già dato a Zoe, e Sara rimborsa a Zoe i 6016, novembre 2959 e dicembre 3057 (6016 su 30 + 31 giorni). Sara
    // 3058 + 2959 + 2959 = 8976 per 91 giorni, con il conguaglio 6017, da rimborsare 2959 (6016 a Zoe, 3057 da Pia); Zoe 3058 per ottobre; Teo 3057 «pagata da Ugo». Il centesimo: la coppia della fine
    // è 3058 e la catena divide i 6016 di Zoe in 2959 e 3057, quindi Teo 3057 contro i 3058 della coppia e Sara con il conguaglio 6017 contro i 6016 del Wallet, € 0,01 (il centesimo in due passi,
    // come nelle guardie della Coda 379). Era: Teo 3057 «pagata da Zoe», Sara con il conguaglio 9074 e da rimborsare − 98: Ugo non riaveva i € 30,58 della coppia, e Sara ne pagava € 30,58 in meno.
    [$s, $frasi] = pcc379DueRientri($this, 'mai');
    expect($frasi)->toContain('e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 6016, 'Ugo' => 3058, 'Zoe' => 9074])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'pia' => [3057, 0, 3057, 31],
            'sara' => [8976, 0, 2959, 91],
            'teo' => [3057, 0, 3057, 31],
            'zoe' => [3058, 0, -6016, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([8976, 6017, []])
        ->and(pcc360PagateDa($s, 'Sara'))->toBe(['Zoe' => 6016])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3057]);

    // La terza uscita il cambio Sara → Teo «Scrivi»: Teo ha dicembre con il conguaglio (3057 contro i 3058 del Wallet, il centesimo) e non rimborsa nessuno. Era: Teo 3057 «pagata da Zoe».
    [$s] = pcc379DueRientri($this, 'mai', verso: 'Teo');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 6016, 'Teo' => 3058, 'Zoe' => 9074])
        ->and(fclProspetto($s)['conduttori']['teo'])->toBe([3057, 0, 0, 31])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe([])
        ->and(pcc360PagateDa($s, 'Sara'))->toBe(['Zoe' => 6016]);

    // Con «Non scriverlo» alla fine di Sara la coppia del 1/12 non c'è: come prima, Teo 3057 «pagata da Zoe», Sara con il conguaglio 9074.
    [$s] = pcc379DueRientri($this, 'mai', scelta: 'non_scrivere');
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 9074, 'Zoe' => 9074])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([8976, 9074, []])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Zoe' => 3057]);
});

/**
 * La storia della Coda 380 (Ines sola all'80 %, Ines → Sara «Scrivi» il 1/7, Sara → Ines «Non scriverlo» il 1/9, il 1/12 la terza uscita di Ines «Scrivi»: la fine senza chi entra, con
 * Teo dal 1/12 all'80 %, o il cambio verso `$verso`) con € 120,00 di già versato «dell'unità» sulla «Spese generali», registrato prima della generazione. Lo scenario e le frasi del
 * pannello dell'inizio di Teo.
 */
function pcc380ConIlVersato($test, ?string $verso = null): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->where('conti.nome', 'Spese generali')->value('conti.id');
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    fclPersona($s, 'Sara');
    pcc379Passaggio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc379Passaggio($test, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
    pcc379Passaggio($test, $s, 'Ines', $verso, '2026-12-01', 'scrivi');
    $frasi = $verso === null ? pcc361Inizio($test, $s, 'Teo', '2026-12-01', 80) : '';

    return [$s, $frasi];
}

it('Coda 380 — la stessa storia con € 120,00 di già versato «dell\'unità»: il già versato dei giorni che Ines ha ripreso è di Sara, che li ha pagati con il conguaglio; Sara € 55,04 con il conguaglio € 163,33 e Ines € 241,44 con € 133,15 nelle sue rate, come nel Wallet', function () {
    // Il già versato «dell'unità» (decisione 17, D8), 12000 sulla «Spese generali»: all'inquilino 12000 × 36000/120000 = 3600, l'anello al lordo 36000 e al netto 32400, la rata di Ines; il
    // conguaglio scrive il netto. Il cambio del 1/7 (77.3): 32400 × 184/365 = 16333,15 → 16333 (Ines − 16333, Sara + 16333); al lordo 18148, e a Ines 17852 per 181 giorni, al netto 16067. Al 1/9
    // «Non scriverlo» (46, nota del 12/10, la strada A′): i giorni dopo l'uscita di Sara restano suoi, e Ines glieli rimborsa: al netto 16333 su 62 + 122 giorni, 5503,60 → 5504 a Sara e 10829
    // dal 1/9; al lordo 6115 e 12033. La fine di Ines del 1/12 «Scrivi» (45, nota del 12/10) scrive 32400 × 31/365 = 2751,78 → 2752 (Ines − 2752, Ugo + 2752), e Teo lo rimborsa a Ugo. Per la
    // decisione 145 quella coppia dà a Ines a credito dicembre, che aveva ripreso da Sara, e Ines rimborsa a Sara tutti i 10829: 8077 dei suoi 91 giorni (10829 × 91/122 = 8077,39) e 2752 di
    // dicembre. A carico, dal Wallet: Ines 32400 − 16333 − 2752 = 13315 nelle sue rate + 10829 a Sara = 24144 (16067 + 8077); Sara 16333 − 10829 = 5504, con il conguaglio 16333; Teo 2752; il
    // controllo 24144 + 5504 + 2752 = 32400. La forma (decisione 150.2, trappola 7 della catena del prospetto): i giorni di dicembre di Sara al lordo, 3058 (12033 − 8975), con accanto la riga
    // «— già versato», − 306, come il pezzo dei giorni dopo la sua uscita (8975 e − 898) e quello prima (6115 e − 611): in tutto − 1815; la restituzione al netto, − 10829; il credito di Ines al
    // netto, − 2752, come la coppia nel Wallet. Con il credito al lordo (− 3058) e senza la riga, Sara € 58,10 con il conguaglio € 166,39 e Ines € 238,38 con € 130,09 nelle sue rate: i € 3,06 del
    // già versato di dicembre a carico di Sara.
    [$s, $frasi] = pcc380ConIlVersato($this);
    expect($frasi)->toContain('e li rimborsa Teo a Ugo, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Ines' => -19085, 'Sara' => 16333, 'Ugo' => 2752])
        ->and(fclNetti($s)['Ines'])->toBe(13315)
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [24144, 13315, 10829, 272],
            'sara' => [5504, 0, -10829, 62],
            'teo' => [2752, 0, 2752, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([5504, 16333, []])
        ->and(pcc360PagateDa($s, 'Ines'))->toBe(['Sara' => 10829])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 2752])
        ->and(pcc379GiorniDelConduttore($s))->toBe([[2752, ['Ugo']]]);
    $voci = fn (array $s, string $nome) => collect(collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', $nome)['voci'])
        ->map(fn ($v) => [$v['conto'], (int) $v['importo'], $v['modo']])->sortBy([[0, 'asc'], [2, 'asc'], [1, 'asc']])->values()->all();
    expect($voci($s, 'Sara'))->toBe([
        ['Spese generali', 6115, 'conguaglio'],
        ['Spese generali', 12033, 'conguaglio'],
        ['Spese generali', -10829, 'restituzione'],
        ['Spese generali — già versato', -1815, 'conguaglio'],
    ])
        ->and($voci($s, 'Ines'))->toBe([
            ['Spese generali', 2752, 'proprietario'],
            ['Spese generali', 8077, 'proprietario'],
            ['Spese generali', -2752, 'rate'],
            ['Spese generali', 17852, 'rate'],
            ['Spese generali — già versato', -1785, 'rate'],
        ]);
    $stampa = fclStampa($s);
    expect($stampa)->toContain('Totale a carico di Sara € 55,04 con il conguaglio del cambio d\'inquilino € 163,33')
        ->and($stampa)->toContain('Totale a carico di Ines € 241,44 di cui già nelle sue rate € 133,15 di cui da rimborsare a chi le ha pagate € 108,29')
        ->and($stampa)->toContain('a suo credito nel Wallet Proprietà1.000 su 1.000 millesimi 30 % 01/01/2026–31/12/2026 — € -27,52 nelle sue rate')
        ->and($stampa)->toContain('Spese generali — già versato — — — — € -18,15 con il conguaglio del cambio d\'inquilino del 01/07/2026')
        ->and($stampa)->not->toContain('€ 166,39');

    // Il cambio Ines → Teo «Scrivi» il 1/12 al posto della fine: la coppia 2752 (Ines − 2752, Teo + 2752); Teo ha dicembre con il conguaglio al lordo, 3058, con accanto il suo già versato, − 306:
    // € 27,52, come nel Wallet. Ines e Sara come sopra.
    [$s] = pcc380ConIlVersato($this, 'Teo');
    expect(fclSaldi($s))->toBe(['Ines' => -19085, 'Sara' => 16333, 'Teo' => 2752])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [24144, 13315, 10829, 272],
            'sara' => [5504, 0, -10829, 62],
            'teo' => [2752, 0, 0, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([5504, 16333, []])
        ->and(pcc361Sezione($s, 'Teo'))->toBe([2752, 2752, []]);

    // Senza il già versato lordo e netto sono uguali: le voci della prima storia della Coda 380, senza una riga «— già versato» e con il credito di Ines − 3058.
    [$s] = pcc379Rientro($this, 'mai');
    expect($voci($s, 'Sara'))->toBe([
        ['Spese generali', 6115, 'conguaglio'],
        ['Spese generali', 12033, 'conguaglio'],
        ['Spese generali', -12033, 'restituzione'],
    ])
        ->and($voci($s, 'Ines'))->toBe([
            ['Spese generali', 3058, 'proprietario'],
            ['Spese generali', 8975, 'proprietario'],
            ['Spese generali', -3058, 'rate'],
            ['Spese generali', 17852, 'rate'],
        ]);
});

// --- Coda 405: una voce che comincia dopo il passaggio prima della voce, e un'altra uscita di chi ha il pezzo dentro la voce (decisioni 43, 46, 83.3 e 145) -----------

/**
 * Ines sola all'80 % con il «Riscaldamento» € 600,00 dal giorno dato (`pcc371Scenario`); Ines → Sara «Scrivi» il 1/7, Sara → Zoe «Scrivi» il 1/10 con «Annulla il conguaglio» (senza con
 * `$annulla` falso), Zoe → Sara il 1/11 con la scelta data, la fine di Sara «Scrivi» il 1/12 e Teo dal 1/12 all'80 %.
 */
function pccVoceDopoLAccordo($test, string $dal, string $rientro = 'non_scrivere', bool $annulla = true): array
{
    $s = pcc371Scenario($test, $dal);
    pcc379Passaggio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    $cambio = pcc379Passaggio($test, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi');
    if ($annulla) {
        pcc366Annulla($test, $s, $cambio);
    }
    pcc379Passaggio($test, $s, 'Zoe', 'Sara', '2026-11-01', $rientro);
    pcc379Passaggio($test, $s, 'Sara', null, '2026-12-01', 'scrivi');
    pcc361Inizio($test, $s, 'Teo', '2026-12-01', 80);

    return $s;
}

/** Una voce del piano nella sezione di un conduttore: [la somma, chi ha pagato i giorni che rimborsa → la cifra]. */
function pccVoceDelConduttore(array $s, string $nome, string $conto): array
{
    $c = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', $nome);
    $voci = collect($c['voci'] ?? [])->where('conto', $conto);

    return [(int) $voci->sum('importo'), $voci->filter(fn ($v) => ! empty($v['pagato_da']))->groupBy('pagato_da')->map(fn ($g) => (int) $g->sum('importo'))->sortKeys()->all()];
}

it('Coda 405 — il «Riscaldamento» dal 15/10: Sara → Zoe «Scrivi» il 1/10 annullato, Zoe → Sara «Non scriverlo» il 1/11, la fine di Sara «Scrivi» il 1/12 e Teo dal 1/12: Zoe non rimborsa a Sara i giorni che hanno regolato fra loro, € 39,23', function () {
    // Il «Riscaldamento», 18000, ha la competenza dal 15/10 al 31/12, 78 giorni. Il cambio del 1/7 porta a Sara le «Spese generali» dopo il 1/7, 36000 × 184/365 = 18147,95 → 18148, e tutto il
    // «Riscaldamento», 18000: la coppia 36148. Il cambio Sara → Zoe del 1/10 (9074 + 18000) le parti l'hanno regolato fra loro (decisioni 43 e 46): i giorni di Zoe restano dov'erano, a Sara, e Zoe
    // non li rimborsa (Code 367 e 375). La fine di Sara del 1/12 scrive la coppia di dicembre, 18148 × 31/184 = 3057,54 → 3058 e 18000 × 31/78 = 7153,85 → 7154: 10212 a Ugo, e Teo glieli
    // rimborsa. Sara 15090 per 153 giorni e 10846 per 47, 25936 «con il conguaglio», la cifra del Wallet (36148 − 10212); il controllo 17852 + 25936 + 10212 = 54000. Era: la ricerca prendeva la
    // fine di Sara dentro la voce e saltava l'accordo, che cade prima della voce, e Zoe rimborsava a Sara 10846 × (80 × 17)/(80 × 17 + 80 × 30) = 3923,0 → 3923 del «Riscaldamento», mentre le
    // «Spese generali» dello stesso prospetto leggevano l'accordo.
    $s = pccVoceDopoLAccordo($this, '2026-10-15');
    expect(fclSaldi($s))->toBe(['Ines' => -36148, 'Sara' => 25936, 'Ugo' => 10212])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'sara' => [25936, 0, 0, 200],
            'teo' => [10212, 0, 10212, 62],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([25936, 25936, []])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 10212]);

    // La voce dal 1/10, il giorno dell'accordo: 18000 su 92 giorni, dicembre 18000 × 31/92 = 6065,22 → 6065 (la coppia 3058 + 6065 = 9123). Sara 15090 + 11935 = 27025, il Wallet; Teo 9123 «pagata
    // da Ugo». Era: Zoe rimborsava a Sara 11935 × 31/61 = 6065,41 → 6065.
    $s = pccVoceDopoLAccordo($this, '2026-10-01');
    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [17852, 17852, 0, 181],
        'sara' => [27025, 0, 0, 214],
        'teo' => [9123, 0, 9123, 62],
    ], 'senza' => [0, 0]]);

    // Zoe → Sara «Scrivi» il 1/11: il cambio regolato fra le parti e l'uscita di Zoe con la coppia scritta, la catena la segue (decisione 83.3), come per le «Spese generali»: i giorni di Zoe,
    // 18000 × 17/78 = 3923,08 → 3923, sono «regolata fra le parti», e novembre è di Sara con il conguaglio del 1/11, 18000 × 30/78 = 6923,08 → 6923. Zoe 3058 + 3923 = 6981 regolata, niente da
    // rimborsare. Era: Zoe rimborsava a Sara 3923 del «Riscaldamento», e novembre era di Sara con il conguaglio del 1/7.
    $s = pccVoceDopoLAccordo($this, '2026-10-15', 'scrivi');
    expect(pcc366Sezione($s, 'Zoe'))->toBe([6981, 6981, []])
        ->and(fclProspetto($s)['conduttori']['zoe'])->toBe([6981, 0, 0, 48])
        ->and(fclProspetto($s)['conduttori']['sara'])->toBe([18956, 0, 0, 152]);

    // La fine di Ines «Scrivi» il 1/10 annullata (l'accordo di tutto il passaggio), Teo dal 15/10, Teo → Ines il 1/11 e la fine di Ines il 1/12, che non chiede la scelta: come per la voce di
    // tutto l'anno, i giorni restano nell'accordo, Ines 36000 + 18000 = 54000 nelle sue rate, niente da restituire. Era: dicembre del «Riscaldamento», 7154, «da restituire» a Ines, senza conduttore.
    $s = pcc371Scenario($this, '2026-10-15');
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Ines', null, '2026-10-01', 'scrivi'));
    pcc361Inizio($this, $s, 'Teo', '2026-10-15', 80);
    pcc379Passaggio($this, $s, 'Teo', 'Ines', '2026-11-01');
    pcc379Passaggio($this, $s, 'Ines', null, '2026-12-01');
    expect(fclSaldi($s))->toBe([])
        ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [54000, 54000, 0, 443]], 'senza' => [0, 0]]);
});

it('Coda 405 — le guardie: senza l\'uscita di Sara dentro la voce, e con la voce dopo l\'uscita di Zoe, il prospetto resta com\'era', function () {
    // Senza la fine di Sara: la catena trovava già l'accordo prima della voce (`finePrimaDellaVoce`), Sara 18148 + 18000 = 36148, Zoe senza sezione.
    $s = pcc371Scenario($this, '2026-10-15');
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    pcc379Passaggio($this, $s, 'Zoe', 'Sara', '2026-11-01', 'non_scrivere');
    expect(fclProspetto($s))->toBe(['conduttori' => ['ines' => [17852, 17852, 0, 181], 'sara' => [36148, 0, 0, 262]], 'senza' => [0, 0]]);

    // La voce dal 15/11, dopo il rientro di Sara: 18000 su 47 giorni, dicembre 18000 × 31/47 = 11872,34 → 11872 a Ugo; Sara 15090 + 6128, Teo 3058 + 11872 «pagata da Ugo».
    $s = pccVoceDopoLAccordo($this, '2026-11-15');
    expect(fclProspetto($s))->toBe(['conduttori' => [
        'ines' => [17852, 17852, 0, 181],
        'sara' => [21218, 0, 0, 169],
        'teo' => [14930, 0, 14930, 62],
    ], 'senza' => [0, 0]]);
});

it('Coda 405 — la stessa storia voce per voce: le «Spese generali» e il «Riscaldamento» leggono l\'accordo allo stesso modo, e dicembre di tutte e due lo rimborsa Teo a Ugo', function () {
    // «Spese generali»: Sara 18148 − 3058 = 15090 dal 1/7 al 30/11, nessun rimborso; Teo 3058 «pagata da Ugo». «Riscaldamento»: Sara 18000 − 7154 = 10846 dal 15/10 al 30/11, nessun rimborso
    // (i giorni di Zoe sono nell'accordo, decisioni 43 e 46); Teo 7154 «pagata da Ugo», la coppia della fine di Sara (decisione 45, nota del 12/10). Zoe senza sezione. Era: il «Riscaldamento» di
    // Zoe 3923 «pagata da Sara», e Sara 10846 − 3923 = 6923.
    $s = pccVoceDopoLAccordo($this, '2026-10-15');
    expect(pccVoceDelConduttore($s, 'Sara', 'Spese generali'))->toBe([15090, []])
        ->and(pccVoceDelConduttore($s, 'Sara', 'Riscaldamento'))->toBe([10846, []])
        ->and(pccVoceDelConduttore($s, 'Teo', 'Spese generali'))->toBe([3058, ['Ugo' => 3058]])
        ->and(pccVoceDelConduttore($s, 'Teo', 'Riscaldamento'))->toBe([7154, ['Ugo' => 7154]])
        ->and(array_keys(fclProspetto($s)['conduttori']))->toBe(['ines', 'sara', 'teo']);
});

it('Coda 405 — la stessa storia senza «Annulla il conguaglio»: il «Riscaldamento» l\'ha preso tutto Zoe con la coppia del 1/10, e Sara le rimborsa novembre e dicembre, € 140,77; Teo rimborsa dicembre a Ugo', function () {
    // Il cambio Sara → Zoe del 1/10 con «Scrivi» scrive la coppia 18148 × 92/184 = 9074 delle «Spese generali» e tutto il «Riscaldamento», che comincia dopo il cambio (Coda 252.1): 18000; Zoe
    // + 27074. Al 1/11 Zoe → Sara «Non scriverlo»: i giorni dopo l'uscita di Zoe restano a lei, e Sara glieli rimborsa (decisioni 46, nota del 12/10, e 77): «Spese generali» 9074 su 31 + 61 giorni,
    // 3057,54 e 6016,46 → 3058 e 6016; «Riscaldamento» 18000 su 17 + 61, 3923,08 e 14076,92 → 3923 e 14077. La fine di Sara del 1/12 scrive la coppia di dicembre, 3058 + 7154 (18148 × 31/184 e
    // 18000 × 31/78): Sara − 10212, Ugo + 10212. Dicembre l'ha preso Ugo, e Teo glielo rimborsa; la coppia dà a Sara a credito dicembre, che aveva già dato a Zoe, e Sara rimborsa a Zoe tutti i
    // giorni dopo la sua uscita (decisione 145, la lettura P): «Riscaldamento» 14077, novembre 6923 e dicembre 7154 (su 30 + 31: 6923,11 e 7153,89); «Spese generali» 6016, 2959 e 3057. A carico:
    // Sara 9074 + 2959 + 6923 = 18956, Zoe 3058 + 3923 = 6981, Teo 7154 + 3057. Il centesimo in due passi: la coppia delle «Spese generali» di dicembre è 3058, e la catena divide i 6016 di Zoe in
    // 2959 e 3057, quindi Teo 3057 e Sara con il conguaglio − 1137 contro i − 1138 del Wallet (36148 − 27074 − 10212), € 0,01 come nelle guardie della Coda 379. Era: la ricerca prendeva la fine di
    // Sara dentro il «Riscaldamento» e saltava il cambio, che cade prima della voce: la voce restava a Sara «con il conguaglio del 1/7», 10846, e Zoe le rimborsava 3923, mentre l'aveva pagata
    // tutta con la coppia: € 180,00 a Zoe.
    $s = pccVoceDopoLAccordo($this, '2026-10-15', annulla: false);
    expect(fclSaldi($s))->toBe(['Ines' => -36148, 'Sara' => -1138, 'Ugo' => 10212, 'Zoe' => 27074])
        ->and(pccVoceDelConduttore($s, 'Zoe', 'Riscaldamento'))->toBe([3923, []])
        ->and(pccVoceDelConduttore($s, 'Zoe', 'Spese generali'))->toBe([3058, []])
        ->and(pccVoceDelConduttore($s, 'Sara', 'Riscaldamento'))->toBe([6923, ['Zoe' => 14077]])
        ->and(pccVoceDelConduttore($s, 'Sara', 'Spese generali'))->toBe([12033, ['Zoe' => 6016]])
        ->and(pccVoceDelConduttore($s, 'Teo', 'Riscaldamento'))->toBe([7154, ['Ugo' => 7154]])
        ->and(pccVoceDelConduttore($s, 'Teo', 'Spese generali'))->toBe([3057, ['Ugo' => 3057]])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'sara' => [18956, 0, 20093, 152],
            'teo' => [10211, 0, 10211, 62],
            'zoe' => [6981, 0, -20093, 48],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Zoe'))->toBe([6981, 27074, []])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([18956, -1137, []]);
});

it('Coda 405 — la voce che comincia dopo la fine «Scrivi» di Sara (il 1/10), Teo dal 15/10, Teo → Sara il 1/11 e la fine di Sara il 1/12: il «Riscaldamento» l\'ha preso Ugo con la coppia, e Teo e Sara glielo rimborsano', function () {
    // La fine di Sara del 1/10 con «Scrivi» scrive la coppia 18148 × 92/184 = 9074 delle «Spese generali» e tutto il «Riscaldamento», 18000, che comincia dopo: Sara − 27074, Ugo + 27074. I
    // giorni dopo l'uscita li ha presi Ugo, e chi entra glieli rimborsa (decisione 45, nota del 12/10, e 77.4): il «Riscaldamento», 78 giorni, Teo dal 15/10 al 31/10, 17 giorni, 18000 × 17/78 =
    // 3923,08 → 3923; Sara rientrata dal 1/11 al 30/11, 30 giorni, 6923,08 → 6923; dicembre senza conduttore, 7154, resta a Ugo (la fine del 1/12 non chiede la scelta: la parte l'aveva già ceduta).
    // Le «Spese generali» dal 1/10, 9074 su 92 giorni: Teo 1676,72 → 1677, Sara 2958,91 → 2959, senza conduttore il resto. Sara 9074 + 2959 + 6923 = 18956, con il conguaglio 9074 (il Wallet), da
    // rimborsare a Ugo 9882; Teo 1677 + 3923 = 5600. Era: la ricerca prendeva la fine di Sara del 1/12 dentro la voce e saltava quella del 1/10: il «Riscaldamento» restava a Sara «con il
    // conguaglio», e la fine senza la scelta glielo restituiva.
    $s = pcc371Scenario($this, '2026-10-15');
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc379Passaggio($this, $s, 'Sara', null, '2026-10-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-10-15', 80);
    pcc379Passaggio($this, $s, 'Teo', 'Sara', '2026-11-01');
    pcc379Passaggio($this, $s, 'Sara', null, '2026-12-01');
    expect(fclSaldi($s))->toBe(['Ines' => -36148, 'Sara' => 9074, 'Ugo' => 27074])
        ->and(pccVoceDelConduttore($s, 'Sara', 'Riscaldamento'))->toBe([6923, ['Ugo' => 6923]])
        ->and(pccVoceDelConduttore($s, 'Teo', 'Riscaldamento'))->toBe([3923, ['Ugo' => 3923]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([18956, 9074, []])
        ->and(pcc360PagateDa($s, 'Sara'))->toBe(['Ugo' => 9882])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 5600])
        ->and(fclProspetto($s)['senza'][0])->toBe(11592);
});

// --- Coda 406: chi entra nei giorni del rientro di chi ha il pezzo, dopo un accordo fra le parti (decisione 144) ---------------------------------------------------------

/**
 * Ines sola all'80 %; Ines → Sara «Scrivi» il 1/7, Sara → Zoe «Scrivi» il 1/10 con «Annulla il conguaglio», Zoe → Sara «Non scriverlo» il 1/11 (Sara rientra); Elsa dal giorno dato al 20 %, con
 * la sua fine il giorno dato (la scelta non si chiede: Elsa non ha rate); con `$fine` la fine di Sara «Scrivi» il 1/12 e Teo dal 1/12 all'80 %. Lo scenario e le frasi del pannello dell'inizio di Elsa.
 */
function pccElsaNelRientro($test, bool $fine = true, string $dal = '2026-11-01', string $esce = '2026-11-20'): array
{
    $s = pcc358Scenario($test, [['Ines', 80]]);
    pcc379Passaggio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($test, $s, pcc379Passaggio($test, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    // I passaggi nell'ordine delle date: Elsa nei giorni di Zoe prima del rientro di Sara.
    if ($dal >= '2026-11-01') {
        pcc379Passaggio($test, $s, 'Zoe', 'Sara', '2026-11-01', 'non_scrivere');
    }
    $frasi = pcc361Inizio($test, $s, 'Elsa', $dal, 20);
    pcc379Passaggio($test, $s, 'Elsa', null, $esce);
    if ($dal < '2026-11-01') {
        pcc379Passaggio($test, $s, 'Zoe', 'Sara', '2026-11-01', 'non_scrivere');
    }
    if ($fine) {
        pcc379Passaggio($test, $s, 'Sara', null, '2026-12-01', 'scrivi');
        pcc361Inizio($test, $s, 'Teo', '2026-12-01', 80);
    }

    return [$s, $frasi];
}

it('Coda 406 — dopo l\'accordo fra Sara e Zoe, Sara rientra il 1/11 ed Elsa entra il 1/11 al 20 % fino al 19/11: Elsa rimborsa a Sara la sua parte dei giorni di Sara, € 4,51, come se l\'accordo non ci fosse', function () {
    // Il cambio del 1/7 dà a Sara 36000 × 184/365 = 18147,95 → 18148. Il cambio Sara → Zoe del 1/10 le parti l'hanno regolato fra loro (decisioni 43 e 46), e l'accordo copre solo i giorni della
    // parte con cui è fatto, Zoe, dal 1/10 al 31/10 (decisione 144). La fine di Sara del 1/12 scrive la coppia di dicembre, 18148 × 31/184 = 3057,54 → 3058 (Sara − 3058, Ugo + 3058), e Teo la
    // rimborsa a Ugo; il pezzo di Sara è 15090 dal 1/7 al 30/11. Fuori dall'accordo, dal 1/7 al 30/9 e dal 1/11 al 30/11, 122 giorni su 153: 15090 × 122/153 = 12032,55 → 12033. Elsa, che
    // dell'accordo non è parte, divide quella parte con Sara per quota e per giorni, come chi entra nei giorni di chi ha il conguaglio (decisione 117, Coda 364): Sara 80 × 122 = 9760, Elsa 20 × 19
    // = 380, 12033 × 380/10140 = 450,94 → 451 «pagata da Sara». Sara 15090 − 451 = 14639, con il conguaglio 15090 (il Wallet), da ricevere 451; il controllo 17852 + 14639 + 451 + 3058 = 36000.
    // Il pannello dell'inizio di Elsa lo dice come il prospetto. Era: dal giorno dell'accordo il prospetto non divideva più nessuno, ed Elsa non rimborsava niente.
    [$s, $frasi] = pccElsaNelRientro($this);
    expect($frasi)->toContain('i giorni dopo la sua uscita li ha presi con il conguaglio Sara, e li rimborsa Elsa a Sara, con il prospetto degli oneri accessori')
        ->and($frasi)->not->toContain('le parti lo hanno regolato fra loro')
        ->and(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 15090, 'Ugo' => 3058])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'elsa' => [451, 0, 451, 19],
            'ines' => [17852, 17852, 0, 181],
            'sara' => [14639, 0, -451, 153],
            'teo' => [3058, 0, 3058, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([14639, 15090, ['Elsa' => -451]])
        ->and(pcc360PagateDa($s, 'Elsa'))->toBe(['Sara' => 451])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 3058]);

    // Senza la terza uscita: il pezzo di Sara è 18148 dal 1/7 al 31/12; fuori dall'accordo 153 giorni su 184, 18148 × 153/184 = 15090,46 → 15090; Sara 80 × 153 = 12240 ed Elsa 380, 15090 ×
    // 380/12620 = 454,37 → 454. Sara 18148 − 454 = 17694, con il conguaglio 18148. Era: Elsa niente.
    [$s] = pccElsaNelRientro($this, fine: false);
    expect(fclProspetto($s))->toBe(['conduttori' => [
        'elsa' => [454, 0, 454, 19],
        'ines' => [17852, 17852, 0, 181],
        'sara' => [17694, 0, -454, 184],
    ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([17694, 18148, ['Elsa' => -454]]);
});

it('Coda 406 — le guardie: chi entra nei giorni di Zoe, la parte con cui è fatto l\'accordo, non li divide (Coda 367), chi resta dopo il rientro divide i giorni dopo, e senza il cambio con Zoe Elsa divide tutto il pezzo di Sara', function () {
    // Elsa dal 15/10 al 24/10, nei giorni di Zoe: sono i giorni dell'accordo (decisioni 43, 46 e 144), ed Elsa non rimborsa niente. Sara 15090, con il conguaglio 15090; Teo 3058 «pagata da Ugo».
    [$s, $frasi] = pccElsaNelRientro($this, dal: '2026-10-15', esce: '2026-10-25');
    expect($frasi)->toContain('le parti lo hanno regolato fra loro')
        ->and(fclProspetto($s)['conduttori'])->toBe([
            'ines' => [17852, 17852, 0, 181],
            'sara' => [15090, 0, 0, 153],
            'teo' => [3058, 0, 3058, 31],
        ]);

    // Elsa dal 15/10 al 19/11, a cavallo del rientro di Sara, registrata dopo il cambio Zoe → Sara: divide solo i giorni dal 1/11, come chi entra il 1/11 senza la terza uscita, 15090 × 380/12620
    // = 454,37 → 454; il pannello del suo inizio non dice più che le parti hanno regolato tutto, ma che chi rimborsa chi lo dice il prospetto (i giorni nell'accordo e quelli dopo).
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    pcc379Passaggio($this, $s, 'Zoe', 'Sara', '2026-11-01', 'non_scrivere');
    $frasi = pcc361Inizio($this, $s, 'Elsa', '2026-10-15', 20);
    pcc379Passaggio($this, $s, 'Elsa', null, '2026-11-20');
    expect($frasi)->toContain('per i giorni dopo la sua uscita il prospetto degli oneri accessori dice chi rimborsa chi')
        ->and($frasi)->not->toContain('le parti lo hanno regolato fra loro')
        ->and(pcc360PagateDa($s, 'Elsa'))->toBe(['Sara' => 454]);

    // Senza il cambio con Zoe (né l'accordo né il rientro), con la fine di Sara e Teo: il pezzo di Sara è 15090 dal 1/7 al 30/11, ed Elsa lo divide tutto con lei (decisione 117, Coda 364): Sara
    // 80 × 153 = 12240, Elsa 380, 15090 × 380/12620 = 454,37 → 454. Con l'accordo i 31 giorni di Zoe ne restano fuori, e la parte di Elsa è 451.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc361Inizio($this, $s, 'Elsa', '2026-11-01', 20);
    pcc379Passaggio($this, $s, 'Elsa', null, '2026-11-20');
    pcc379Passaggio($this, $s, 'Sara', null, '2026-12-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect(pcc360PagateDa($s, 'Elsa'))->toBe(['Sara' => 454])
        ->and(fclProspetto($s)['conduttori']['sara'])->toBe([14636, 0, -454, 153]);
});

it('Coda 406 — l\'accordo finisce con l\'uscita di Zoe, non con il rientro di Sara: Zoe esce con la sua fine il 1/11 («Non scriverlo»), Elsa entra il 5/11 al 20 % fino al 19/11 e Sara rientra il 15/11; Elsa rimborsa a Sara € 3,86', function () {
    // L'accordo fra Sara e Zoe copre i giorni di Zoe, dal 1/10 al 31/10 (decisione 144): dal 1/11 i giorni sono di nuovo di Sara, con il conguaglio del 1/7, anche prima che rientri. Il pezzo di
    // Sara è 18148 dal 1/7 al 31/12; fuori dall'accordo 153 giorni su 184, 15090,46 → 15090. Dei 153 giorni, quattro (dal 1/11 al 4/11) non hanno un conduttore e restano a Sara (Coda 364): 15090
    // su 149 + 4, 14695,49 e 394,51 → 14695 ai conduttori. Sara 80 × 139 = 11120 (dal 1/7 al 30/9 e dal 15/11), Elsa 20 × 15 = 300: 14695 × 300/11420 = 386,03 → 386 «pagata da Sara». Sara 18148 −
    // 386 = 17762, con il conguaglio 18148. Il pannello dell'inizio di Elsa lo dice. Era: Elsa niente.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    pcc379Passaggio($this, $s, 'Zoe', null, '2026-11-01', 'non_scrivere');
    $frasi = pcc361Inizio($this, $s, 'Elsa', '2026-11-05', 20);
    pcc361Inizio($this, $s, 'Sara', '2026-11-15', 80);
    pcc379Passaggio($this, $s, 'Elsa', null, '2026-11-20');
    expect($frasi)->toContain('li rimborsa Elsa a Sara, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(fclProspetto($s)['conduttori'])->toBe([
            'elsa' => [386, 0, 386, 15],
            'ines' => [17852, 17852, 0, 181],
            'sara' => [17762, 0, -386, 184],
        ])
        ->and(pcc360PagateDa($s, 'Elsa'))->toBe(['Sara' => 386]);
});

it('Coda 406 — la fine di Sara del 1/10 annullata (l\'accordo senza chi entra), Teo dal 15/10, Teo → Sara il 1/11 (Sara rientra), Elsa dal 15/11 al 19/11 al 20 %: l\'accordo finisce al rientro di Sara (decisione 148.2), ed Elsa le rimborsa € 1,22, Teo niente', function () {
    // Il cambio del 1/7 dà a Sara 36000 × 184/365 = 18147,95 → 18148 (Ines − 18148, Sara + 18148). La fine di Sara del 1/10 «Scrivi» e poi «Annulla il conguaglio»: le parti hanno regolato fra
    // loro i giorni dopo l'uscita (decisioni 43 e 46), e l'accordo finisce quando Sara rientra, come per il cambio annullato (decisione 148.2): copre dal 1/10 al 31/10. Teo, entrato il 15/10 in quei
    // giorni, non divide (Coda 367). Il pezzo di Sara è 18148 dal 1/7 al 31/12; fuori dall'accordo 153 giorni su 184, 18148 × 153/184 = 15090,46 → 15090. Elsa, entrata dopo il rientro, divide
    // quella parte con Sara per quota e per giorni (decisione 117, Coda 364): Sara 80 × 153 = 12240, Elsa 20 × 5 = 100, 15090 × 100/12340 = 122,28 → 122 «pagata da Sara». Sara 18148 − 122 =
    // 18026, con il conguaglio 18148 (il Wallet); il controllo 17852 + 18026 + 122 = 36000. Il pannello dell'inizio di Teo dice l'accordo, quello di Elsa il rimborso a Sara.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Sara', null, '2026-10-01', 'scrivi'));
    $teo = pcc361Inizio($this, $s, 'Teo', '2026-10-15', 80);
    pcc379Passaggio($this, $s, 'Teo', 'Sara', '2026-11-01');
    $elsa = pcc361Inizio($this, $s, 'Elsa', '2026-11-15', 20);
    pcc379Passaggio($this, $s, 'Elsa', null, '2026-11-20');
    expect($teo)->toContain('le parti lo hanno regolato fra loro')
        ->and($elsa)->toContain('li rimborsa Elsa a Sara, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'elsa' => [122, 0, 122, 5],
            'ines' => [17852, 17852, 0, 181],
            'sara' => [18026, 0, -122, 184],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([18026, 18148, ['Elsa' => -122]])
        ->and(pcc360PagateDa($s, 'Elsa'))->toBe(['Sara' => 122])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe([]);

    // Sara rientra con un inizio il 1/11, senza Teo: lo stesso rientro, le stesse cifre (Sara 80 × 153, Elsa 20 × 5).
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Sara', null, '2026-10-01', 'scrivi'));
    pcc361Inizio($this, $s, 'Sara', '2026-11-01', 80);
    pcc361Inizio($this, $s, 'Elsa', '2026-11-15', 20);
    pcc379Passaggio($this, $s, 'Elsa', null, '2026-11-20');
    expect(pcc360PagateDa($s, 'Elsa'))->toBe(['Sara' => 122])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([18026, 18148, ['Elsa' => -122]]);
});

it('Coda 406 — due voci, le «Spese generali» e il «Riscaldamento» € 600,00 dal 15/10: l\'accordo fra Sara e Zoe vale per ogni voce nei suoi giorni, ed Elsa rimborsa a Sara € 4,51 e € 9,46, come dice il pannello del suo inizio; Teo rimborsa a Ugo dicembre di tutte e due', function () {
    // La storia della Coda 406 (`pccElsaNelRientro`) con una seconda voce, il «Riscaldamento», 18000 all'inquilino, competenza dal 15/10 al 31/12 (78 giorni). Il cambio del 1/7 dà a Sara le
    // «Spese generali» dopo il 1/7, 18148, e tutto il «Riscaldamento», 18000 (Coda 252.1): Sara + 36148. La fine di Sara del 1/12 «Scrivi» scrive dicembre, 18148 × 31/184 = 3057,54 → 3058 e
    // 18000 × 31/78 = 7153,85 → 7154: Sara − 10212, Ugo + 10212, e Teo li rimborsa a Ugo (decisione 45, nota del 12/10). Il pezzo di Sara, fino al 30/11: 15090 e 10846. L'accordo con Zoe copre i
    // giorni di Zoe, dal 1/10 al 31/10 (decisione 144), dentro i giorni di ogni voce: per le «Spese generali» 31 giorni su 153, fuori 15090 × 122/153 = 12032,55 → 12033, Elsa 12033 × 380/10140 =
    // 450,94 → 451 (Sara 80 × 122, Elsa 20 × 19); per il «Riscaldamento» 17 giorni (dal 15/10) su 47, fuori 10846 × 30/47 = 6922,98 → 6923, Elsa 6923 × 380/2780 = 946,29 → 946 (Sara 80 × 30). Sara
    // 25936 − 451 − 946 = 24539, con il conguaglio 25936 (il Wallet); Teo 3058 + 7154 = 10212; il controllo 17852 + 24539 + 1397 + 10212 = 54000. Zoe non ha voci: i suoi giorni sono l'accordo.
    $s = pcc371Scenario($this, '2026-10-15');
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    pcc379Passaggio($this, $s, 'Zoe', 'Sara', '2026-11-01', 'non_scrivere');
    $frasi = pcc361Inizio($this, $s, 'Elsa', '2026-11-01', 20);
    pcc379Passaggio($this, $s, 'Elsa', null, '2026-11-20');
    pcc379Passaggio($this, $s, 'Sara', null, '2026-12-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect($frasi)->toContain('li rimborsa Elsa a Sara, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Ines' => -36148, 'Sara' => 25936, 'Ugo' => 10212])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'elsa' => [1397, 0, 1397, 38],
            'ines' => [17852, 17852, 0, 181],
            'sara' => [24539, 0, -1397, 200],
            'teo' => [10212, 0, 10212, 62],
        ], 'senza' => [0, 0]])
        ->and(pccVoceDelConduttore($s, 'Elsa', 'Spese generali'))->toBe([451, ['Sara' => 451]])
        ->and(pccVoceDelConduttore($s, 'Elsa', 'Riscaldamento'))->toBe([946, ['Sara' => 946]])
        ->and(pccVoceDelConduttore($s, 'Teo', 'Spese generali'))->toBe([3058, ['Ugo' => 3058]])
        ->and(pccVoceDelConduttore($s, 'Teo', 'Riscaldamento'))->toBe([7154, ['Ugo' => 7154]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([24539, 25936, ['Elsa' => -1397]]);

    // Senza il rientro: Zoe esce con la sua fine il 1/11, e l'accordo finisce lì (decisione 144); Elsa entra il 5/11 al 20 % ed esce il 20/11, unica conduttrice dal 1/11. Il pezzo di Sara, 18148 e
    // 18000, fino al 31/12. «Riscaldamento»: fuori dall'accordo dal 1/11, 18000 × 61/78 = 14076,92 → 14077; i giorni di Elsa 15 su 61, 14077 × 15/61 = 3461,59 → 3462, tutti suoi; gli altri 46 restano a
    // Sara. «Spese generali»: fuori 15090 (153 giorni su 184), con un conduttore 107 giorni (Sara dal 1/7 al 30/9 e i 15 di Elsa), 15090 × 107/153 = 10553,14 → 10553; Elsa 10553 × 300/7660 = 413,30 → 413
    // (Sara 80 × 92 = 7360). Sara 36148 − 3875 = 32273, con il conguaglio 36148 (il Wallet).
    $s = pcc371Scenario($this, '2026-10-15');
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    pcc379Passaggio($this, $s, 'Zoe', null, '2026-11-01', 'non_scrivere');
    $frasi = pcc361Inizio($this, $s, 'Elsa', '2026-11-05', 20);
    pcc379Passaggio($this, $s, 'Elsa', null, '2026-11-20');
    expect($frasi)->toContain('li rimborsa Elsa a Sara, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Ines' => -36148, 'Sara' => 36148])
        ->and(pccVoceDelConduttore($s, 'Elsa', 'Riscaldamento'))->toBe([3462, ['Sara' => 3462]])
        ->and(pccVoceDelConduttore($s, 'Elsa', 'Spese generali'))->toBe([413, ['Sara' => 413]])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([32273, 36148, ['Elsa' => -3875]]);
});

it('Coda 406 — con il box: l\'accordo e il rientro valgono unità per unità; sull\'Interno 1 Elsa rimborsa a Sara € 4,09, e sul box, dove Elsa non entra, Sara tiene i suoi giorni', function () {
    // L'Interno 1 900 millesimi e il «Box 12» 100: l'anello 32400 e 3600, Ines all'80 % su tutti e due. I passaggi con il box spuntato: Ines → Sara «Scrivi» il 1/7 (32400 × 184/365 = 16333,15 →
    // 16333 e 3600 × 184/365 = 1814,79 → 1815), Sara → Zoe «Scrivi» il 1/10 annullato, Zoe → Sara «Non scriverlo» il 1/11. Elsa entra il 1/11 al 20 % sul solo Interno 1 (con il box spuntato la
    // registrazione si rifiuta: la pertinenza la prenderebbe tutta, e le quote degli inquilini sul box farebbero 180) ed esce il 20/11. Sull'Interno 1, fuori dall'accordo 153 giorni su 184:
    // 16333 × 153/184 = 13581,17 → 13581; Elsa 13581 × 380/12620 = 408,93 → 409 (Sara 80 × 153, Elsa 20 × 19); Sara 16333 − 409 = 15924, con il conguaglio 16333 (il Wallet). Sul box nessuno entra:
    // Sara 1815 con il conguaglio, Ines 3600 − 1815 = 1785.
    $s = pcc375ConIlBox($this);
    fclPersona($s, 'Sara');
    pcc375ConIlBoxPassaggio($this, $s, 'Ines', 'Sara', '2026-07-01');
    pcc366Annulla($this, $s, pcc375ConIlBoxPassaggio($this, $s, 'Sara', 'Zoe', '2026-10-01'));
    pcc375ConIlBoxPassaggio($this, $s, 'Zoe', 'Sara', '2026-11-01', 'non_scrivere');
    $frasi = pcc361Inizio($this, $s, 'Elsa', '2026-11-01', 20);
    pcc379Passaggio($this, $s, 'Elsa', null, '2026-11-20');
    expect($frasi)->toContain('li rimborsa Elsa a Sara, con il prospetto degli oneri accessori')
        ->and(fclSaldi($s))->toBe(['Ines' => -16333, 'Sara' => 16333])
        ->and(fclSaldi($s, $s['box']))->toBe(['Ines' => -1815, 'Sara' => 1815])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'elsa' => [409, 0, 409, 19],
            'ines' => [16067, 16067, 0, 181],
            'sara' => [15924, 0, -409, 184],
        ], 'senza' => [0, 0]])
        ->and(fclProspetto($s, $s['box']))->toBe(['conduttori' => [
            'ines' => [1785, 1785, 0, 181],
            'sara' => [1815, 0, 0, 184],
        ], 'senza' => [0, 0]]);
});

// --- La voce che comincia il giorno dell'ultima uscita di chi ha il pezzo, o dopo (decisioni 45, 77, 145; Code 252.1, 380 e 405) ---------------------------------------

/**
 * Ines sola all'80 % con il «Riscaldamento» € 600,00 dal giorno dato (`pcc371Scenario`), e la storia data, tutta prima della voce o nel suo primo giorno: `b0` Ines → Sara «Scrivi» il 1/7,
 * Sara → Ines «Non scriverlo» il 1/9, la fine di Ines «Scrivi» il 1/12, Teo dal 1/12 all'80 %; `rientri` Ines → Sara «Scrivi» il 1/7, Sara → Zoe «Scrivi» il 1/10, Zoe → Sara «Non scriverlo» il
 * 1/11, la fine di Sara «Scrivi» il 1/12, Teo dal 1/12; `fine` Ines → Sara «Scrivi» il 1/7, la fine di Sara «Scrivi» il 1/10, Teo dal 15/10, Teo → Sara il 1/11, la fine di Sara il 1/12 (la
 * scelta non si chiede), Elsa dal 5/12 all'80 %.
 */
function pccVoceDopoIPassaggi($test, string $storia, string $dal = '2026-12-01'): array
{
    $s = pcc371Scenario($test, $dal);
    pcc379Passaggio($test, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    if ($storia === 'b0') {
        pcc379Passaggio($test, $s, 'Sara', 'Ines', '2026-09-01', 'non_scrivere');
        pcc379Passaggio($test, $s, 'Ines', null, '2026-12-01', 'scrivi');
        pcc361Inizio($test, $s, 'Teo', '2026-12-01', 80);
    } elseif ($storia === 'rientri') {
        pcc379Passaggio($test, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi');
        pcc379Passaggio($test, $s, 'Zoe', 'Sara', '2026-11-01', 'non_scrivere');
        pcc379Passaggio($test, $s, 'Sara', null, '2026-12-01', 'scrivi');
        pcc361Inizio($test, $s, 'Teo', '2026-12-01', 80);
    } else {
        pcc379Passaggio($test, $s, 'Sara', null, '2026-10-01', 'scrivi');
        pcc361Inizio($test, $s, 'Teo', '2026-10-15', 80);
        pcc379Passaggio($test, $s, 'Teo', 'Sara', '2026-11-01');
        pcc379Passaggio($test, $s, 'Sara', null, '2026-12-01');
        pcc361Inizio($test, $s, 'Elsa', '2026-12-05', 80);
    }

    return $s;
}

it('La voce che comincia il giorno della fine di Ines, dopo Ines → Sara «Scrivi» e Sara → Ines «Non scriverlo»: Teo la rimborsa a Ugo, e Ines la rimborsa a Sara, € 180,00', function () {
    // Il «Riscaldamento», 18000, dal 1/12 al 31/12. Il cambio del 1/7 porta a Sara le «Spese generali» dopo il 1/7, 18148, e tutto il «Riscaldamento», che comincia dopo (Coda 252.1): Ines −
    // 36148, Sara + 36148. Al 1/9 «Non scriverlo»: la voce resta a Sara, e Ines gliela rimborsa (decisione 46, nota del 12/10, e 77.4). La fine di Ines del 1/12 «Scrivi» scrive la coppia dei
    // giorni dopo l'uscita, 3058 delle «Spese generali» (36000 × 31/365) e tutto il «Riscaldamento», 18000: Ines − 21058, Ugo + 21058. Ugo ha preso la voce con la coppia, e Teo, conduttore
    // di tutti i suoi giorni, gliela rimborsa (decisione 45, nota del 12/10); la coppia ha dato a Ines a credito la voce che era di Sara, e Ines la rimborsa a Sara (decisione 145, la lettura P).
    // «Riscaldamento»: Sara 18000 con il conguaglio e 18000 da ricevere da Ines; Ines − 18000 nelle sue rate e 18000 «pagata da Sara»; Teo 18000 «pagata da Ugo». Le «Spese generali» come nella
    // storia della Coda 380. Ines: nelle sue rate 14794 − 18000 = − 3206 (il Wallet: 54000 di rate e − 57206 di saldi), da rimborsare 12033 + 18000 = 30033; Sara con il conguaglio 36148 (il
    // Wallet). Era: la voce c'era solo nella sezione di Teo, e nessuno rimborsava a Sara i € 180,00 che aveva pagato con la coppia del 1/7.
    // Con la voce dal 15/12 la coppia del 1/12 la prende ancora tutta, e Teo ne ha i 17 giorni.
    foreach (['2026-12-01' => 31, '2026-12-15' => 17] as $dal => $giorni) {
        $s = pccVoceDopoIPassaggi($this, 'b0', $dal);
        expect(fclSaldi($s))->toBe(['Ines' => -57206, 'Sara' => 36148, 'Ugo' => 21058], $dal)
            ->and(fclNetti($s)['Ines'])->toBe(-3206)
            ->and(fclProspetto($s))->toBe(['conduttori' => [
                'ines' => [26827, -3206, 30033, 272],
                'sara' => [6115, 0, -30033, 62],
                'teo' => [21058, 0, 21058, 31 + $giorni],
            ], 'senza' => [0, 0]], $dal)
            ->and(pccVoceDelConduttore($s, 'Ines', 'Riscaldamento'))->toBe([0, ['Sara' => 18000]], $dal)
            ->and(pccVoceDelConduttore($s, 'Sara', 'Riscaldamento'))->toBe([0, []], $dal)
            ->and(pccVoceDelConduttore($s, 'Teo', 'Riscaldamento'))->toBe([18000, ['Ugo' => 18000]], $dal)
            ->and(pcc361Sezione($s, 'Sara'))->toBe([6115, 36148, []], $dal)
            ->and(pcc360PagateDa($s, 'Ines'))->toBe(['Sara' => 30033], $dal);
    }
});

it('La voce che comincia il giorno della terza uscita di Sara, dopo Sara → Zoe «Scrivi» e Zoe → Sara «Non scriverlo»: Teo la rimborsa a Ugo, e Sara la rimborsa a Zoe, € 180,00', function () {
    // Il cambio Sara → Zoe del 1/10 con «Scrivi» porta a Zoe 9074 delle «Spese generali» e tutto il «Riscaldamento», 18000 (Coda 252.1): Zoe + 27074. Al 1/11 Zoe → Sara «Non scriverlo»: la voce
    // resta a Zoe, e Sara gliela rimborsa (decisioni 46, nota del 12/10, e 77.4). La fine di Sara del 1/12 «Scrivi» scrive la coppia 3058 + 18000 (Sara − 21058, Ugo + 21058): Teo rimborsa a Ugo
    // la voce (decisione 45, nota del 12/10), e Sara, che l'ha avuta a credito, la rimborsa a Zoe (decisione 145). Le «Spese generali» come nella storia della Coda 405 senza l'annullamento: Sara
    // 9074 + 2959 + 3057 − 3057, con il conguaglio 6017 (il centesimo in due passi), da rimborsare a Zoe 6016; Teo 3057 «pagata da Ugo». Zoe: con il conguaglio 9074 + 18000 = 27074 (il Wallet),
    // da ricevere 6016 + 18000 = 24016. Sara: con il conguaglio 6017 − 18000 = − 11983 (il Wallet − 11984, il centesimo). Era: Zoe aveva pagato la voce con la coppia del 1/10, e nessuno gliela
    // rimborsava.
    $s = pccVoceDopoIPassaggi($this, 'rientri');
    expect(fclSaldi($s))->toBe(['Ines' => -36148, 'Sara' => -11984, 'Ugo' => 21058, 'Zoe' => 27074])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [17852, 17852, 0, 181],
            'sara' => [12033, 0, 24016, 122],
            'teo' => [21057, 0, 21057, 62],
            'zoe' => [3058, 0, -24016, 31],
        ], 'senza' => [0, 0]])
        ->and(pccVoceDelConduttore($s, 'Zoe', 'Riscaldamento'))->toBe([0, []])
        ->and(pccVoceDelConduttore($s, 'Sara', 'Riscaldamento'))->toBe([0, ['Zoe' => 18000]])
        ->and(pccVoceDelConduttore($s, 'Teo', 'Riscaldamento'))->toBe([18000, ['Ugo' => 18000]])
        ->and(pcc361Sezione($s, 'Zoe'))->toBe([3058, 27074, []])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([12033, -11983, []]);
});

it('La voce che comincia il giorno della seconda fine di Sara, dopo la sua fine «Scrivi» del 1/10 e il rientro: l\'ha presa Ugo con quella coppia, ed Elsa gliela rimborsa, € 156,77', function () {
    // La fine di Sara del 1/10 «Scrivi» scrive la coppia 9074 delle «Spese generali» e tutto il «Riscaldamento», 18000, che comincia dopo: Sara − 27074, Ugo + 27074. La fine del 1/12, dopo il
    // rientro con il cambio Teo → Sara, non chiede la scelta (la parte l'aveva già ceduta) e non scrive niente. I giorni della voce li ha presi Ugo con la coppia del 1/10, e chi entra glieli
    // rimborsa (decisione 45, nota del 12/10, e 77.4): Elsa dal 5/12, 27 giorni su 31, 18000 × 27/31 = 15677,42 → 15677 «pagata da Ugo»; dal 1/12 al 4/12 nessun conduttore, 2322,58 → 2323, resta a
    // Ugo, che li ha pagati («senza conduttore», la strada B). Le «Spese generali», 9074 su 92 giorni: Teo 17 giorni 1676,76 → 1677, Sara 30 giorni 2958,89 → 2959, Elsa 27 giorni 2663,02 → 2663,
    // senza conduttore 18 giorni 1775,32 → 1775, tutti «pagata da Ugo». Sara 9074 + 2959 = 12033, con il conguaglio 9074 (il Wallet), da rimborsare 2959. Era: la catena leggeva la fine del
    // 1/12 (senza la scelta, la strada A′): la voce restava a Sara «con il conguaglio», Elsa la rimborsava a Sara e i 4 giorni senza conduttore si restituivano a Sara, mentre l'aveva pagata Ugo.
    $s = pccVoceDopoIPassaggi($this, 'fine');
    expect(fclSaldi($s))->toBe(['Ines' => -36148, 'Sara' => 9074, 'Ugo' => 27074])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'elsa' => [18340, 0, 18340, 54],
            'ines' => [17852, 17852, 0, 181],
            'sara' => [12033, 0, 2959, 122],
            'teo' => [1677, 0, 1677, 17],
        ], 'senza' => [4098, 22]])
        ->and(pcc360PagateDa($s, 'Elsa'))->toBe(['Ugo' => 18340])
        ->and(pcc360PagateDa($s, 'Sara'))->toBe(['Ugo' => 2959])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([12033, 9074, []])
        ->and(collect(pcc370Senza($s))->map(fn ($v) => [$v[3], $v[5]])->unique()->values()->all())->toBe([['Ugo', 'B']]);
});

it('La voce che comincia dopo i passaggi — le guardie: con l\'accordo fra Sara e Zoe, e con la seconda uscita di Sara «Non scriverlo» poi la fine «Scrivi», la voce la rimborsa Teo a Ugo, come prima', function () {
    // L'accordo (il cambio Sara → Zoe del 1/10 annullato): la voce è di Sara con la coppia del 1/7 (+ 18000) e la fine del 1/12 la passa a Ugo (− 18000): Sara non ha niente della voce, Teo 18000
    // «pagata da Ugo» (decisioni 43, 46 e la Coda 379).
    $s = pcc371Scenario($this, '2026-12-01');
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    pcc379Passaggio($this, $s, 'Zoe', 'Sara', '2026-11-01', 'non_scrivere');
    pcc379Passaggio($this, $s, 'Sara', null, '2026-12-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect(fclProspetto($s)['conduttori'])->toBe(['ines' => [17852, 17852, 0, 181], 'sara' => [15090, 0, 0, 153], 'teo' => [21058, 0, 21058, 62]])
        ->and(pccVoceDelConduttore($s, 'Teo', 'Riscaldamento'))->toBe([18000, ['Ugo' => 18000]]);

    // La Coda 370: Sara → Pia «Non scriverlo» il 1/9, Pia → Sara il 1/10, la fine di Sara «Scrivi» il 1/11 (la coppia 6016 + 18000 a Ugo), Teo dal 1/12: la voce la rimborsa Teo a Ugo.
    $s = pcc371Scenario($this, '2026-12-01');
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc379Passaggio($this, $s, 'Sara', 'Pia', '2026-09-01', 'non_scrivere');
    pcc379Passaggio($this, $s, 'Pia', 'Sara', '2026-10-01');
    pcc379Passaggio($this, $s, 'Sara', null, '2026-11-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect(fclSaldi($s))->toBe(['Ines' => -36148, 'Sara' => 12132, 'Ugo' => 24016])
        ->and(pccVoceDelConduttore($s, 'Teo', 'Riscaldamento'))->toBe([18000, ['Ugo' => 18000]])
        ->and(pccVoceDelConduttore($s, 'Sara', 'Riscaldamento'))->toBe([0, []]);
});

it('Coda 406 — dopo l\'accordo fra Sara e Zoe, Zoe esce con il cambio Zoe → Elsa «Non scriverlo»: Sara non rientra, e per i giorni dell\'accordo il prospetto non fa rimborsare nessuno, come dice il pannello del cambio; la fine di Elsa lo chiude, e Teo rimborsa a Sara € 30,58 (Coda 413)', function () {
    // Il cambio Sara → Zoe del 1/10 le parti l'hanno regolato fra loro (decisioni 43 e 46). Il 1/11 Zoe esce con il cambio Zoe → Elsa «Non scriverlo»: nei saldi non si scrive niente, e il
    // pannello del cambio dice che il rimborso di Elsa a Zoe si regola fra le parti e che per i giorni dell'accordo il prospetto non fa rimborsare nessuno (la lettura delle decisioni 43 e 46 senza una fine «Scrivi» dopo,
    // decisione 83.3). La decisione 144 fa finire l'accordo dove chi ha il pezzo torna ad avere i suoi giorni (il suo rientro, o la fine di Zoe senza chi entra), non quando Zoe passa i suoi
    // giorni a chi ne prende il posto (decisione 148.1): Sara 18148 con il conguaglio, Elsa senza sezione.
    // Coda 413 (decisione 153.3): l'uscita di Elsa, che ha preso il posto di Zoe, chiude l'accordo come l'uscita di Zoe. Con la fine di Elsa il 1/12 l'accordo copre dal 1/10 al 30/11: fuori
    // 18148 × 123/184 = 12131,54 → 12132 (il resto maggiore), e Teo, dal 1/12 all'80 %, lo divide con Sara (80 × 92 e 80 × 31): 12132 × 2480/9840 = 3057,66 → 3058 «pagata da Sara». Sara 15090,
    // e il pannello di Teo dice il rimborso. Era (1.11.0-beta.58): Teo niente, e il pannello diceva l'accordo.
    $s = pcc358Scenario($this, [['Ines', 80]]);
    pcc379Passaggio($this, $s, 'Ines', 'Sara', '2026-07-01', 'scrivi');
    pcc366Annulla($this, $s, pcc379Passaggio($this, $s, 'Sara', 'Zoe', '2026-10-01', 'scrivi'));
    fclPersona($s, 'Elsa');
    $dati = ['quota' => (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Zoe'))->value('quota')] + fclModulo($s, $s['persone']['Elsa'], '2026-11-01', [], 'Zoe');
    $frasi = fclFrasi(fclAnteprima($this, $s, $dati));
    fclRegistra($this, $s, $dati + ['conguaglio' => 'non_scrivere']);
    expect($frasi)->toContain('il rimborso di Elsa a Zoe si regola fra le parti')
        ->and($frasi)->toContain('per i giorni di quell\'accordo il prospetto degli oneri accessori non fa rimborsare nessuno')
        ->and(fclSaldi($s))->toBe(['Ines' => -18148, 'Sara' => 18148])
        ->and(fclProspetto($s)['conduttori'])->toBe(['ines' => [17852, 17852, 0, 181], 'sara' => [18148, 0, 0, 184]]);

    pcc379Passaggio($this, $s, 'Elsa', null, '2026-12-01');
    $teo = pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect($teo)->toContain('li ha presi con il conguaglio Sara, e li rimborsa Teo a Sara, con il prospetto degli oneri accessori')
        ->and($teo)->not->toContain('le parti lo hanno regolato fra loro')
        ->and(fclProspetto($s)['conduttori'])->toBe(['ines' => [17852, 17852, 0, 181], 'sara' => [15090, 0, -3058, 184], 'teo' => [3058, 0, 3058, 31]])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Sara' => 3058]);
});

it('Coda 380 — chi riprende i giorni li aveva con il conguaglio (Ines → Pia «Scrivi» il 1/4, Pia → Sara «Scrivi» il 1/7, Sara → Pia «Non scriverlo» il 1/9, la fine di Pia «Scrivi» il 1/12, Teo dal 1/12) con € 120,00 di già versato «dell\'unità»: il credito di Pia è al netto e «con il conguaglio», Pia € 53,26 con il conguaglio come nel Wallet', function () {
    // Il già versato «dell'unità» (decisione 17, D8): 3600 all'inquilino, l'anello al lordo 36000 e al netto 32400. Il cambio del 1/4 (77.3): 32400 × 275/365 = 24410,96 → 24411 a Pia (al lordo
    // 27123); il cambio del 1/7: 24411 × 184/275 = 16333,06 → 16333 a Sara (al lordo 18148), a Pia restano 8078 per 91 giorni (al lordo 8975, il già versato 897). Al 1/9 «Non scriverlo» (46, nota del
    // 12/10): i giorni dopo l'uscita di Sara restano suoi, 5504 e 10829 al netto. La fine di Pia del 1/12 «Scrivi» (45, nota del 12/10) scrive dicembre, 2752 (Pia − 2752, Ugo + 2752), e Teo lo rimborsa
    // a Ugo; per la decisione 145 Pia rimborsa a Sara tutti i 10829: 8077 dei suoi 91 giorni e 2752 di dicembre. Il credito di Pia è al netto, come la coppia nel Wallet, e ha il suo modo, «con il
    // conguaglio del cambio d'inquilino del 01/04/2026» (decisione 150.2). Pia: 8975 − 897 + 8077 = 16155, con il conguaglio 8975 − 897 − 2752 = 5326 (il Wallet: 24411 − 16333 − 2752); Sara 5504 con
    // il conguaglio 16333; Ines 7989; Teo 2752; il controllo 7989 + 16155 + 5504 + 2752 = 32400. Con il credito di Pia al lordo (− 3058) Pia ha 15849 e 5020 con il conguaglio.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 80]]]);
    $contoId = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->where('conti.nome', 'Spese generali')->value('conti.id');
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $contoId, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-03-31');
    pcc379Passaggio($this, $s, 'Ines', 'Pia', '2026-04-01', 'scrivi');
    pcc379Passaggio($this, $s, 'Pia', 'Sara', '2026-07-01', 'scrivi');
    pcc379Passaggio($this, $s, 'Sara', 'Pia', '2026-09-01', 'non_scrivere');
    pcc379Passaggio($this, $s, 'Pia', null, '2026-12-01', 'scrivi');
    pcc361Inizio($this, $s, 'Teo', '2026-12-01', 80);
    expect(fclSaldi($s))->toBe(['Ines' => -24411, 'Pia' => 5326, 'Sara' => 16333, 'Ugo' => 2752])
        ->and(fclProspetto($s))->toBe(['conduttori' => [
            'ines' => [7989, 7989, 0, 90],
            'pia' => [16155, 0, 10829, 182],
            'sara' => [5504, 0, -10829, 62],
            'teo' => [2752, 0, 2752, 31],
        ], 'senza' => [0, 0]])
        ->and(pcc361Sezione($s, 'Pia'))->toBe([16155, 5326, []])
        ->and(pcc361Sezione($s, 'Sara'))->toBe([5504, 16333, []])
        ->and(pcc360PagateDa($s, 'Pia'))->toBe(['Sara' => 10829])
        ->and(pcc360PagateDa($s, 'Teo'))->toBe(['Ugo' => 2752]);
});
