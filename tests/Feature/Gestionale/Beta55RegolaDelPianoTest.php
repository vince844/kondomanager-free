<?php

/**
 * 1.11.0-beta.55, Coda 245 — la regola del piano alla fine di una locazione con un coinquilino che resta.
 *
 * Alla fine della locazione di Ines, con Dora che resta, la parte «Inquilino» che il piano ha congelato (le righe del riparto con il
 * ruolo richiesto «inquilino», sull'unità, voce per voce: l'anello) si ridivide con i pesi del motore dopo l'uscita: quota × giorni, e i
 * giorni che nessun inquilino copre alla catena del ripiego (il proprietario, l'usufruttuario, con il ruolo gemello sull'unità mista). Un
 * arrotondamento solo sull'anello, con i resti maggiori; a pari resto prima chi esce, poi le righe d'inquilino nell'ordine, poi il ripiego.
 * La coppia di ognuno è la parte nuova meno quella congelata: somma zero per voce e per unità.
 *
 * Il condominio è quello di `ScenariFineLocazione`: «Spese generali» € 1.200,00 sulla tabella «Proprietà», 30 % «Inquilino» e 70 %
 * «Proprietario», il «Preventivo 2026» in dodici rate, generato, con le rate da gennaio a giugno a giornale; Ugo proprietario al 100 %.
 * Ines esce il 1/7 (la sua riga chiusa il 30/6): 181 giorni su 365.
 *
 * I CONTI A MANO (centesimi, frazioni esatte; resti maggiori). L'anello è 120000 × 30 % = 36000.
 * - C1, Ines 50 e Dora 50: congelato 18000 e 18000. Dopo: pesi Ines 50 × 181 = 9050, Dora 50 × 365 = 18250 (somma 27300). Ines
 *   36000 × 9050/27300 = 11934,07, Dora 24065,93 → interi 11934 + 24065 = 35999, il centesimo al resto più grande (,93) → Dora 24066.
 *   Coppia: Ines − 6066, Dora + 6066.
 * - C3, Ines 40, Dora 30, Pia 30: congelato 14400, 10800, 10800. Pesi 7240, 10950, 10950 (somma 29140): 8944,41, 13527,80, 13527,80 →
 *   8944 + 13527 + 13527 = 35998, due centesimi ai resti ,80 (pari: prima Dora, poi Pia) → 8944, 13528, 13528. Coppia − 5456, + 2728, + 2728.
 * - R20, Ines 50, Dora 25, Pia 25: congelato 18000, 9000, 9000. Pesi 9050, 9125, 9125 (somma 27300): 11934,07, 12032,97, 12032,97 →
 *   11934 + 12032 + 12032 = 35998, i due ,97 a Dora e Pia → coppia − 6066, + 3033, + 3033.
 * - C6.5g, Ines 50, Dora 25, Pia 25, la voce € 1.000,00 (anello 30000): congelato 15000, 7500, 7500. 30000 × 9050/27300 = 9945,05;
 *   30000 × 9125/27300 = 10027,4725 per Dora e per Pia → 9945 + 10027 + 10027 = 29999, il centesimo al resto più grande: pari (,4725),
 *   va alla riga che viene prima. Con la riga di Dora prima: Dora 10028, Pia 10027 → coppia − 5055, + 2528, + 2527; con la riga di Pia
 *   prima, Pia + 2528 e Dora + 2527.
 * - C6.5d, Ines 50, Dora 30: congelato 22500, 13500. Pesi 9050, 10950 (somma 20000): 16290 e 19710 esatti → coppia ∓ 6210.
 * - C6.5b: Ines esce il 1/1/2026 (riga chiusa il 31/12/2025): nel 2026 solo Dora → 36000: ∓ 18000. Il 31/12/2026: Ines 50 × 364 =
 *   18200, Dora 18250 (somma 36450): 17975,31, 18024,69 → 17975 + 18025 → ∓ 25. Il 1/1/2027: la riga chiusa il 31/12/2026, che è
 *   l'ultimo giorno della competenza: nessuno cambia nel 2026, nessuna coppia e nessuna scelta.
 * - C6.5c, Dora con la riga chiusa il 10/10 già alla generazione (congelato Ines 20278, Dora 15722, dal motore). Dopo: Ines 9050, Dora
 *   50 × 283 = 14150 (1/1–10/10), somma 23200 sui 283 giorni coperti; gli 82 giorni dall'11/10 al ripiego, Ugo. Ines 36000 × (9050/23200
 *   × 283)/365 = 10888,21…, Dora 17024,11…, Ugo 36000 × 82/365 = 8087,67… → 10888 + 17024 + 8087 = 35999, il centesimo al resto più grande
 *   (Ugo, ,67) → 8088. Coppia − 9390, + 1302, Ugo + 8088.
 * - C6.5c-bis, Dora chiusa il 31/7 (congelato 22773 e 13227): Dora scende. Coppia Ines − 13143, Dora − 1947, Ugo + 15090.
 * - B6, Dora chiusa il 30/9 (congelato 20596 e 15404): dopo Ines 9050 e Dora 50 × 273 = 13650 sui 273 giorni coperti, Ugo i 92 giorni dal
 *   1/10 al 31/12. Ines 10735, Dora 16191, Ugo 9074 → coppia − 9861, + 787, + 9074, la parte di Ugo sui giorni 1/10–31/12.
 * - C11, una seconda voce «Pulizie secondo semestre» € 600,00 dal 1/7 al 31/12 (anello 18000, congelato 9000 e 9000): dal 1/7 Ines non
 *   c'è, tutto a Dora → ∓ 9000; con la voce di sempre ∓ 6066: in tutto ∓ 15066. C12, una voce dal 1/1 al 30/6: nessuno cambia, niente.
 * - C13, il 2028 (366 giorni): Ines 50 × 182 = 9100, Dora 18300 (somma 27400): 11956,2 → ∓ 6044.
 * - C6.5e, l'«Affitto antenna» − € 300,00 di entrata tutto l'anno (anello − 9000, congelato − 4500 e − 4500): − 9000 × 9050/27300 =
 *   − 2983,5… → Ines − 2984, Dora − 6016: per l'entrata Ines + 1516, Dora − 1516; con la spesa ∓ 6066: in tutto Ines − 4550, Dora + 4550.
 * - E1, l'«Affitto antenna» − € 1.000,00 dal 1/10 al 31/12 (anello − 30000, congelato − 15000 e − 15000): dal 1/10 Ines non c'è, tutto a
 *   Dora: Ines + 15000, Dora − 15000; con la spesa ∓ 6066: Ines + 8934 (debito), Dora − 8934 (credito). E2, la stessa con € 400,00
 *   (anello − 12000): Ines + 6000, Dora − 6000; in tutto Ines − 66, Dora + 66.
 * - C6.5f, due voci da € 1.000,01 al 33 % (anello 33000,33 → 33000 per voce), Ines 40, Dora 30, Pia 30: per voce Ines − 5001, Dora
 *   + 2501, Pia + 2500 (oracolo); in tutto − 10002, + 5002, + 5000. Il piano del motore dopo dà le stesse cifre: scarto 0.
 *
 * Cosa NON copre: i passaggi dopo una fine con la regola del piano (la fine del coinquilino, il cambio, l'inizio, la vendita sul ripiego);
 * il prospetto degli oneri accessori; MySQL (l'ordine a pari resto del motore su MySQL è la Coda 301).
 */

use App\Models\User;
use App\Services\Subentro\ChiPagaAlPostoDellInquilino;
use App\Services\Subentro\RegolaDelPiano;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Beta55CoinquilinoScenari.php';
require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

it('Coda 245 (regola del piano) — C1: Ines esce il 1/7, Dora resta: la coppia è Ines − € 60,66, Dora + € 60,66, e la scelta si chiede', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect(rdpCoppia($a))->toBe(['Dora' => 6066, 'Ines' => -6066])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeTrue()
        ->and(collect($a['rate']['conguaglio']['per_gestione'])->pluck('regola')->all())->toBe(['piano']);
});

it('Coda 245 (regola del piano) — C3 e R20: tre conduttori, Ines 40, Dora 30, Pia 30 e Ines 50, Dora 25, Pia 25', function () {
    $s = rdpScenario($this, [['Ines', 40], ['Dora', 30], ['Pia', 30]]);
    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s))))->toBe(['Dora' => 2728, 'Ines' => -5456, 'Pia' => 2728]);

    $s = rdpScenario($this, [['Ines', 50], ['Dora', 25], ['Pia', 25]]);
    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s))))->toBe(['Dora' => 3033, 'Ines' => -6066, 'Pia' => 3033]);
});

it('Coda 245 (regola del piano) — C6.5g: a pari resto il centesimo va alla riga d\'inquilino che viene prima, nei due ordini', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 25], ['Pia', 25]], ['importo' => 100000]);
    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s))))->toBe(['Dora' => 2528, 'Ines' => -5055, 'Pia' => 2527]);

    // La riga di Pia prima di quella di Dora: il centesimo a Pia.
    $s = rdpScenario($this, [['Ines', 50], ['Pia', 25], ['Dora', 25]], ['importo' => 100000]);
    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s))))->toBe(['Dora' => 2527, 'Ines' => -5055, 'Pia' => 2528]);
});

it('Coda 245 (regola del piano) — C6.5d: quote che non sommano 100, Ines 50 e Dora 30: ∓ € 62,10', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 30]]);

    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s))))->toBe(['Dora' => 6210, 'Ines' => -6210]);
});

it('Coda 245 (regola del piano) — C6.5b: l\'uscita il 1/1/2026 (∓ € 180,00), il 31/12/2026 (∓ € 0,25) e il 1/1/2027 (nessuna coppia, nessuna scelta)', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]]);
    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s, il: '2026-01-01'))))->toBe(['Dora' => 18000, 'Ines' => -18000]);
    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s, il: '2026-12-31'))))->toBe(['Dora' => 25, 'Ines' => -25]);

    $a = fclAnteprima($this, $s, fclModulo($s, il: '2027-01-01'));
    // Senza una coppia il gruppo della regola del piano non è un gruppo in cui «nessuno sta al posto dell'inquilino»: Dora resta.
    expect(rdpCoppia($a))->toBe([])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeFalse()
        ->and($a['rate']['conguaglio']['senza_nessuno'])->toBe([])
        ->and(fclFrasi($a))->not->toContain('nessuno sta al posto dell\'inquilino');
});

it('Coda 245 (regola del piano) — C6.5c e C6.5c-bis: la coinquilina con la fine già registrata; i giorni che nessun inquilino copre vanno a Ugo, e con la fine vicina la parte di Dora scende', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['fini' => ['Dora' => '2026-10-10']]);
    expect(fclNetti($s))->toBe(['Dora' => 15722, 'Ines' => 20278, 'Ugo' => 84000]);
    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s))))->toBe(['Dora' => 1302, 'Ines' => -9390, 'Ugo' => 8088]);

    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['fini' => ['Dora' => '2026-07-31']]);
    expect(fclNetti($s))->toBe(['Dora' => 13227, 'Ines' => 22773, 'Ugo' => 84000]);
    // Il credito della coinquilina è una coppia negativa: Ines → Dora − € 19,47, Ines → Ugo + € 150,90.
    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s))))->toBe(['Dora' => -1947, 'Ines' => -13143, 'Ugo' => 15090]);
});

it('Coda 245 (regola del piano) — B6: Dora chiusa il 30/9, la parte di Ugo è quella del ripiego, sui giorni dal 1/10 al 31/12', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['fini' => ['Dora' => '2026-09-30']]);
    expect(fclNetti($s))->toBe(['Dora' => 15404, 'Ines' => 20596, 'Ugo' => 84000]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect(rdpCoppia($a))->toBe(['Dora' => 787, 'Ines' => -9861, 'Ugo' => 9074]);
    $piano = $a['rate']['conguaglio']['per_gestione'][0]['piano'];
    expect($piano['ripiego'])->toBe([(int) $s['persone']['Ugo']->id])
        ->and($piano['periodi'])->toBe([['tratti' => [['dal' => '2026-10-01', 'al' => '2026-12-31']], 'importo' => 9074]]);
});

it('Coda 245 (regola del piano) — C11 e C12: la competenza della voce; dal 1/7 la voce del secondo semestre è tutta di Dora, quella del primo non cambia', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['capitoli' => [['Pulizie secondo semestre', 'spesa', 60000, '2026-07-01', '2026-12-31']]]);
    $a = fclAnteprima($this, $s, fclModulo($s));
    expect(rdpCoppia($a))->toBe(['Dora' => 15066, 'Ines' => -15066])
        ->and(rdpPerVoce($a, $s))->toBe(['Pulizie secondo semestre' => ['Dora' => 9000, 'Ines' => -9000], 'Spese generali' => ['Dora' => 6066, 'Ines' => -6066]]);

    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['capitoli' => [['Spese primo semestre', 'spesa', 60000, '2026-01-01', '2026-06-30']]]);
    expect(rdpPerVoce(fclAnteprima($this, $s, fclModulo($s)), $s))->toBe(['Spese generali' => ['Dora' => 6066, 'Ines' => -6066]]);
});

it('Coda 245 (regola del piano) — C13: il 2028 ha 366 giorni: ∓ € 60,44', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['anno' => 2028]);

    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s, il: '2028-07-01'))))->toBe(['Dora' => 6044, 'Ines' => -6044]);
});

it('Coda 245 (regola del piano) — C6.5e, E1 ed E2: le entrate con il loro segno, anche quando chi esce ha un debito', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['capitoli' => [['Affitto antenna', 'entrata', 30000, null, null]]]);
    $a = fclAnteprima($this, $s, fclModulo($s));
    expect(rdpCoppia($a))->toBe(['Dora' => 4550, 'Ines' => -4550])
        ->and(rdpPerVoce($a, $s))->toBe(['Affitto antenna' => ['Dora' => -1516, 'Ines' => 1516], 'Spese generali' => ['Dora' => 6066, 'Ines' => -6066]]);

    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['capitoli' => [['Affitto antenna', 'entrata', 100000, '2026-10-01', '2026-12-31']]]);
    $a = fclAnteprima($this, $s, fclModulo($s));
    expect(rdpCoppia($a))->toBe(['Dora' => -8934, 'Ines' => 8934])
        ->and(rdpPerVoce($a, $s))->toBe(['Affitto antenna' => ['Dora' => -15000, 'Ines' => 15000], 'Spese generali' => ['Dora' => 6066, 'Ines' => -6066]]);

    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['capitoli' => [['Affitto antenna', 'entrata', 40000, '2026-10-01', '2026-12-31']]]);
    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s))))->toBe(['Dora' => 66, 'Ines' => -66]);
});

it('Coda 245 (regola del piano) — C6.5f: due voci, l\'arrotondamento per voce; registrata con «Scrivi», i netti sono quelli del motore ricalcolato', function () {
    $s = rdpScenario($this, [['Ines', 40], ['Dora', 30], ['Pia', 30]], ['importo' => 100001, 'ripartizione' => ['inquilino' => 33, 'proprietario' => 67],
        'capitoli' => [['Voce B', 'spesa', 100001, null, null, ['inquilino' => 33, 'proprietario' => 67], 100001]]]);
    $a = fclAnteprima($this, $s, fclModulo($s));
    expect(rdpCoppia($a))->toBe(['Dora' => 5002, 'Ines' => -10002, 'Pia' => 5000])
        ->and(rdpPerVoce($a, $s))->toBe(['Spese generali' => ['Dora' => 2501, 'Ines' => -5001, 'Pia' => 2500], 'Voce B' => ['Dora' => 2501, 'Ines' => -5001, 'Pia' => 2500]]);

    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    expect(fclNetti($s))->toBe(rdpMotore($s));
});

it('Coda 245 (regola del piano) — contro il motore ricalcolato dopo «Scrivi» lo scarto sta nella tolleranza di 1 + voci × (1 + passaggi) centesimi (C1, C3, C6.5c, B6)', function (array $inquilini, array $opz) {
    $s = rdpScenario($this, $inquilini, $opz);

    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    $netti = fclNetti($s);
    $motore = rdpMotore($s);
    foreach (array_keys($netti + $motore) as $chi) {
        expect(abs(($netti[$chi] ?? 0) - ($motore[$chi] ?? 0)))->toBeLessThanOrEqual(1 + 1 * (1 + 1), "{$chi}: " . json_encode([$netti, $motore]));
    }
})->with([
    'C1' => [[['Ines', 50], ['Dora', 50]], []],
    'C3' => [[['Ines', 40], ['Dora', 30], ['Pia', 30]], []],
    'C6.5c' => [[['Ines', 50], ['Dora', 50]], ['fini' => ['Dora' => '2026-10-10']]],
    'B6' => [[['Ines', 50], ['Dora', 50]], ['fini' => ['Dora' => '2026-09-30']]],
]);

it('Coda 245 (regola del piano) — l\'unità in usufrutto: i giorni che nessun inquilino copre vanno all\'usufruttuaria, Rita, non al nudo proprietario', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['titolari' => [['Ugo', 'nuda_proprietario', 100], ['Rita', 'usufruttuario', 100]], 'fini' => ['Dora' => '2026-09-30']]);

    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s))))->toBe(['Dora' => 787, 'Ines' => -9861, 'Rita' => 9074]);
});

it('Coda 245 (regola del piano) — l\'unità mista: Bice proprietaria piena al 50 %, Rita usufruttuaria e Ugo nudo dell\'altra metà; il ripiego si divide con il ruolo gemello, Rita e Bice per metà', function () {
    // I 92 giorni dal 1/10: l'anello del ripiego è l'usufruttuaria (Rita, 50) con la proprietaria piena come ruolo gemello (Bice, 50),
    // 46 giorni ciascuna: Ines 10735, Dora 16191, Rita 4537, Bice 4537 → coppia Ines − 9861, Dora + 787, Rita + 4537, Bice + 4537.
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['titolari' => [['Bice', 'proprietario', 50], ['Rita', 'usufruttuario', 50], ['Ugo', 'nuda_proprietario', 50]],
        'fini' => ['Dora' => '2026-09-30']]);

    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s))))->toBe(['Bice' => 4537, 'Dora' => 787, 'Ines' => -9861, 'Rita' => 4537]);
});

it('Coda 245 (regola del piano) — i comproprietari al posto dell\'inquilino, Ugo 40 e Rita 60: il ripiego per quota', function () {
    // I 92 giorni dal 1/10: Ugo 0,4 × 92 = 36,8, Rita 55,2: Ugo 3629,6, Rita 5444,4 nell'anello → 3630 e 5444.
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['titolari' => [['Ugo', 'proprietario', 40], ['Rita', 'proprietario', 60]], 'fini' => ['Dora' => '2026-09-30']]);

    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s))))->toBe(['Dora' => 787, 'Ines' => -9861, 'Rita' => 5444, 'Ugo' => 3630]);
});

it('Coda 245 (regola del piano) — i giorni che non copre nessuno, neanche il ripiego: la loro parte resta a chi esce', function () {
    // B6, e la riga di Ugo chiusa a mano il 30/11 dopo la generazione: dal 1/12 nessuno. Ines 10735 + 3058 (i 31 giorni di dicembre) =
    // 13793, Dora 16191, Ugo 6016 (61 giorni) → coppia Ines − 6803, Dora + 787, Ugo + 6016.
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['fini' => ['Dora' => '2026-09-30']]);
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Ugo']->id)->update(['data_fine' => '2026-11-30']);

    $a = fclAnteprima($this, $s, fclModulo($s));

    $piano = $a['rate']['conguaglio']['per_gestione'][0]['piano'];
    expect(rdpCoppia($a))->toBe(['Dora' => 787, 'Ines' => -6803, 'Ugo' => 6016])
        ->and($piano['scoperti'])->toBe([['dal' => '2026-12-01', 'al' => '2026-12-31']])
        // Le parti sommano a zero, chi esce compreso: la parte dei giorni di nessuno è sua.
        ->and($piano['parti'])->toBe([(int) $s['persone']['Ines']->id => -6803, (int) $s['persone']['Dora']->id => 787, (int) $s['persone']['Ugo']->id => 6016])
        ->and($a['rate']['frasi'][0])->toContain('Ines € 107,35 per i suoi 181 giorni')
        ->and($a['rate']['frasi'][0])->toContain('Per i 31 giorni dal 1 dicembre 2026 non copre nessuno neanche chi sta al posto dell\'inquilino: la loro parte, € 30,58, resta nelle rate di Ines.');
});

it('Coda 245 (regola del piano) — il già versato: la parte «dell\'unità» abbassa l\'anello, quella «della persona» resta di chi l\'ha versata', function () {
    // € 120,00 versati dall'unità su «Spese generali»: il motore li divide in proporzione ai lordi (Ugo 8400, Ines 1800, Dora 1800), e
    // l'anello è 36000 − 3600 = 32400, congelato 16200 e 16200. Dopo: 32400 × 9050/27300 = 10740,66 → Ines 10741, Dora 21659: ∓ 5459.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    $conto = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->whereIn('piano_conto_id', \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->pluck('id'))->firstOrFail();
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $conto->id, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    expect(fclNetti($s))->toBe(['Dora' => 16200, 'Ines' => 16200, 'Ugo' => 75600]);
    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s))))->toBe(['Dora' => 5459, 'Ines' => -5459]);

    // € 120,00 versati da Ines: sono suoi, e non entrano nell'anello (36000, congelato 18000 e 18000): ∓ 6066.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    $conto = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->whereIn('piano_conto_id', \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->pluck('id'))->firstOrFail();
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $conto->id, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => $s['persone']['Ines']->id, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    expect(fclNetti($s))->toBe(['Dora' => 18000, 'Ines' => 6000, 'Ugo' => 84000]);
    expect(rdpCoppia(fclAnteprima($this, $s, fclModulo($s))))->toBe(['Dora' => 6066, 'Ines' => -6066]);
});

it('Coda 245 (regola del piano) — due esercizi sulla stessa gestione: una coppia per gruppo, e il piano 2025 non ne ha', function () {
    // La gestione riusata sul 2025 e sul 2026, un preventivo per anno, generato con Ines e Dora e tutto a giornale. L'uscita del 1/7/2026
    // non tocca il 2025 (nessuno cambia nella sua competenza); sul 2026 ∓ 6066.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    $e2025 = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e2025, $s['g']->id);
    $p2025 = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $e2025->id, 'nome' => 'Preventivo 2025', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2025-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($p2025->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: generazione del preventivo', esercizio: $e2025);
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $p2025]), ['rate_ids' => DB::table('rate')->where('piano_rate_id', $p2025->id)->pluck('id')->all(), 'data_emissione' => '2025-12-31', 'invia_notifiche' => false]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');

    $a = fclAnteprima($this, $s, fclModulo($s));

    $gruppi = collect($a['rate']['conguaglio']['per_gestione'])->keyBy('esercizio_id');
    expect($gruppi[$s['e']->id]['importo'])->toBe(6066)
        ->and($gruppi[$e2025->id]['importo'] ?? 0)->toBe(0)
        ->and(collect($a['rate']['conguaglio']['coppie'])->pluck('esercizio_id')->unique()->values()->all())->toBe([$s['e']->id])
        ->and(rdpCoppia($a))->toBe(['Dora' => 6066, 'Ines' => -6066]);
});

it('Coda 245 (regola del piano) — i pesi: quando nessuno entra o esce nella competenza sono le quote, come il motore; altrimenti quota × giorni, e il ripiego in giorni', function () {
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 30]]]);
    $chiPaga = new ChiPagaAlPostoDellInquilino();
    $anno = InsiemePeriodi::uno(new PeriodoCompetenza('2026-01-01', '2026-12-31'));
    $ines = (int) $s['persone']['Ines']->id;
    $dora = (int) $s['persone']['Dora']->id;
    $ugo = (int) $s['persone']['Ugo']->id;

    // Nessuno cambia: le quote, proprio quelle (la garanzia d'identità del motore, `CalcoloQuoteService::distribuisciSuTabelle`).
    $pesi = $chiPaga->pesiDelPiano($chiPaga->righe((int) $s['unita']->id), $anno);
    expect($pesi['pesi'])->toBe([$ines => 50.0, $dora => 30.0])
        ->and($pesi['cambia'])->toBeFalse()
        ->and($pesi['ripiego'])->toBe([]);

    // Dora fino al 30/9 e Ines fino al 30/6: quota × giorni sui 273 giorni coperti, e i 92 giorni dal 1/10 a Ugo.
    DB::table('anagrafica_immobile')->where('anagrafica_id', $dora)->update(['data_fine' => '2026-09-30']);
    DB::table('anagrafica_immobile')->where('anagrafica_id', $ines)->update(['data_fine' => '2026-06-30']);
    $pesi = $chiPaga->pesiDelPiano($chiPaga->righe((int) $s['unita']->id), $anno);
    $somma = 50 * 181 + 30 * 273;
    expect(array_keys($pesi['pesi']))->toBe([$ines, $dora, $ugo])
        ->and($pesi['pesi'][$ines])->toEqualWithDelta(50 * 181 / $somma * 273, 1e-9)
        ->and($pesi['pesi'][$dora])->toEqualWithDelta(30 * 273 / $somma * 273, 1e-9)
        ->and($pesi['pesi'][$ugo])->toEqualWithDelta(92.0, 1e-9)
        ->and($pesi['ripiego'])->toBe([$ugo])
        ->and($pesi['scoperti']->toArray())->toBe([['dal' => '2026-10-01', 'al' => '2026-12-31']]);
});

it('Coda 245 (regola del piano) — la ridivisione: un arrotondamento solo, con i resti maggiori; a pari resto prima chi esce, poi l\'ordine dato', function () {
    // 10 su tre pesi uguali: 3,33 ciascuno, l'avanzo di un centesimo a chi esce (Ines, 3) anche se viene dopo.
    expect(RegolaDelPiano::ridividi(10, [2 => 1.0, 3 => 1.0, 1 => 1.0], 1))->toBe([1 => 4, 2 => 3, 3 => 3])
        ->and(RegolaDelPiano::ridividi(10, [2 => 1.0, 3 => 1.0, 1 => 1.0], null))->toBe([2 => 4, 3 => 3, 1 => 3])
        // Il segno lo dà l'anello: un'entrata.
        ->and(RegolaDelPiano::ridividi(-10, [2 => 1.0, 3 => 1.0, 1 => 1.0], 1))->toBe([1 => -4, 2 => -3, 3 => -3]);
});

it('Coda 245 (regola del piano) — una voce in cui nessuno cambia resta com\'è, anche dove la ridivisione darebbe il centesimo a un\'altra persona', function () {
    // La voce € 10,03 al 30 %: l'anello è 300,9. Il motore arrotonda sull'intera voce, con Ugo: Ines, Dora e Pia 100,3 ciascuna, Ugo 702,1 → 100 +
    // 100 + 100 + 702 = 1002, il centesimo al resto più grande, ,3, a pari resto alla prima riga: Ines 101, Dora 100, Pia 100 (l'anello è 301).
    // Dora esce il 1/1/2027, l'ultimo giorno della competenza è il 31/12/2026: nessuno cambia, e la coppia non c'è. Ridividere l'anello con le
    // quote darebbe il centesimo a chi esce, Dora (101), e una coppia di un centesimo con Ines.
    $s = rdpScenario($this, [['Ines', 30], ['Dora', 30], ['Pia', 30]], ['importo' => 1003]);
    expect(fclNetti($s))->toBe(['Dora' => 100, 'Ines' => 101, 'Pia' => 100, 'Ugo' => 702]);

    $a = fclAnteprima($this, $s, fclModulo($s, il: '2027-01-01', esce: 'Dora'));

    expect(rdpCoppia($a, 'Dora'))->toBe([])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeFalse();
});

it('Coda 245 (regola del piano) — un piano straordinario con la parte «Inquilino»: la competenza è la data della delibera, e con la delibera dopo l\'uscita la voce va tutta a chi resta', function () {
    // La straordinaria si divide sulla sua competenza, la data della delibera (`CompetenzaDelPiano`): la «Pulizia urgente» € 500,00 al 30 %
    // all'inquilino, anello 15000, congelato 7500 e 7500. Deliberata il 1/9, quando Ines è già uscita: nella competenza c'è solo Dora, e
    // nessuno cambia (le quote): Dora 15000, la coppia Ines − 7500, Dora + 7500. Senza la delibera il piano straordinario non si genera
    // (`RichiedeDeliberaException`): una voce senza competenza alla fine non arriva.
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['1' => 1000])->gestione('Facciata 2026', 'straordinaria')
        ->voce('Facciata 2026', 'Pulizia urgente', 50000, ['Proprietà' => 100], ['proprietario' => 70, 'inquilino' => 30])
        ->titolare('1', 'ugo')->titolare('1', 'ines', 'inquilino', 50)->titolare('1', 'dora', 'inquilino', 50);
    $s->pianoStraordinario('Facciata', 'Facciata 2026', ['Pulizia urgente' => 50000], '2026-09-01', 6, '2026-02-05');
    $s->genera('Facciata');
    $s->emetti('Facciata', '2026-06-30');
    $ines = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['1']->id)->where('anagrafica_id', $s->persona('ines')->id)->value('id');

    $a = $this->actingAs($s->admin)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s->c, $s->unita['1']]), [
        'tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => $ines, 'anagrafica_entrante_id' => null, 'decorrenza' => '2026-07-01',
        'quota' => 100, 'copia_autentica' => false, 'pertinenze' => [],
    ])->assertOk()->json();

    expect(collect($a['rate']['conguaglio']['coppie'] ?? [])->map(fn ($c) => [(string) $c['entrante_nome'], (int) $c['importo']])->all())->toBe([['Dora', 7500]])
        ->and(collect($a['rate']['conguaglio']['per_gestione'])->pluck('regola')->all())->toBe(['piano']);
});

it('Coda 245 (regola del piano) — il già versato dell\'unità con chi sta al posto dell\'inquilino nell\'anello: la sua parte del versato si divide fra l\'anello e le sue altre righe, in proporzione ai lordi', function () {
    // Ines e Dora entrano il 1/3 al 50 % (gennaio e febbraio, 59 giorni, li copre Ugo al posto dell'inquilino); € 120,00 versati dall'unità. Il
    // motore: Ines e Dora 36000 × 153/365 = 15090,41 ciascuna, Ugo 36000 × 59/365 = 5819,18 nell'anello più 84000 → 15090 + 15090 + 89819 =
    // 119999, il centesimo a Ines (resto ,41, la prima riga). Il versato, 12000 in proporzione ai lordi: Ines 1509,1, Dora 1509,0, Ugo 8981,9 →
    // 1509, 1509, 8982. La parte di Ugo nell'anello: 8982 × 5819 / (5819 + 84000) = 581,9 → 582, e il suo congelato 5819 − 582 = 5237. L'anello
    // è 13582 + 13581 + 5237 = 32400. Ines esce il 1/7: Ines 50 × 122 = 6100 e Dora 50 × 306 = 15300 sui 306 giorni coperti, Ugo i suoi 59 →
    // Ines 7742,65, Dora 19420,09, Ugo 5237,26 → 7742 + 19420 + 5237 = 32399, il centesimo a Ines (,65) → Ines 7743, Dora 19420, Ugo 5237: Ines −
    // 5839, Dora + 5839, Ugo niente.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => []]);
    $conto = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->whereIn('piano_conto_id', \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->pluck('id'))->firstOrFail();
    DB::table('contributi_versati')->insert(['condominio_id' => $s['c']->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $conto->id, 'immobile_id' => $s['unita']->id,
        'anagrafica_id' => null, 'importo_cents' => 12000, 'natura' => 'avanzo', 'origine' => 'migrazione', 'created_at' => now(), 'updated_at' => now()]);
    foreach (['Ines', 'Dora'] as $chi) {
        fclPersona($s, $chi);
        fds50Registra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => '2026-03-01',
            'quota' => 50, 'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []]);
    }
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    expect(fclNetti($s))->toBe(['Dora' => 13581, 'Ines' => 13582, 'Ugo' => 80837]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    $voce = $a['rate']['conguaglio']['per_gestione'][0]['piano']['voci'][0];
    expect($voce['anello'])->toBe(32400)
        ->and($voce['prima'][(int) $s['persone']['Ugo']->id] ?? null)->toBe(5237)
        ->and(rdpCoppia($a))->toBe(['Dora' => 5839, 'Ines' => -5839]);
});
