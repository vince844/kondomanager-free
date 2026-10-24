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
