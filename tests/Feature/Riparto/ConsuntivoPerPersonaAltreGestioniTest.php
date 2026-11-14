<?php

/**
 * Il consuntivo per persona: la riga «da altre gestioni e da esercizi prima», l'avviso del saldo scritto a mano con l'origine in un altro
 * esercizio (anche già nelle rate), l'inquilino uscito nell'anno e il già versato che i piani non hanno usato, sul caso di Via Verdi.
 *
 * Le cifre sono rifatte a mano dalla regola, in centesimi; positivo = debito. A di Via Verdi: Pulizie € 3.300,00 → Neri 1.650,00, Bianchi 990,00,
 * Verdi 660,00; Manutenzioni € 900,00 → 450,00, 270,00, 180,00. Le rate: Pulizie € 3.000,00 e Manutenzioni € 1.000,00, per millesimi.
 */

use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\Conto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/ConsuntivoPerPersonaPosizioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** La riga «da altre gestioni e da esercizi prima» di una persona su un'unità. */
function cpaAltre(array $consuntivo, array $s, string $chi, string $unita): ?array
{
    return $consuntivo['da_altre_gestioni']['persone'][$s['persone'][$chi]->id][$s['unita'][$unita]->id] ?? null;
}

/** Le colonne di una riga del consuntivo, per confrontarle in un colpo. */
function cpaColonne(?array $r): array
{
    return $r === null ? [] : ['a' => $r['a'], 'personali' => $r['personali']['non_chieste'], 'b' => $r['b']['totale'], 'conguaglio' => $r['conguaglio'],
        'posizione' => $r['posizione'], 'fuori' => $r['fuori_dalle_rate'], 'saldo' => $r['saldo']];
}

/** Il saldo di una persona: la somma delle sue righe delle gestioni e della riga da altre gestioni. */
function cpaSaldoDellaPersona(array $consuntivo, array $s, string $chi): int
{
    $aid = $s['persone'][$chi]->id;
    $saldo = 0;
    foreach ($consuntivo['persone'] as $perPersona) {
        foreach ($perPersona[$aid] ?? [] as $riga) {
            $saldo += (int) $riga['saldo'];
        }
    }
    foreach ($consuntivo['da_altre_gestioni']['persone'][$aid] ?? [] as $riga) {
        $saldo += $riga['saldo'];
    }

    return $saldo;
}

it('le cifre di altre gestioni e degli esercizi prima stanno in una riga loro, non su una gestione dell\'esercizio, anche se la prima gestione creata è il riscaldamento', function () {
    // «Riscaldamento 2026», ordinaria, creata prima dell'«Ordinaria 2026». La rata del 2025 di Verdi, € 200,00, non pagata: la sua riga da altre
    // gestioni ha la posizione 200,00 e il saldo 200,00; la riga dell'ordinaria ha il conguaglio +40,00 (840,00 − 800,00) e il saldo 40,00. Il saldo
    // di Verdi è 240,00, l'estratto conto (200,00) più il conguaglio: come prima, con le cifre in un altro posto.
    $s = cpcViaVerdi($this, ['riscaldamento' => true]);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    [$e2025, $g2025] = cpcAnnoPrima($s);
    cpcRataNonPagata($s, $e2025, $g2025, 'Verdi');
    $c = cpcLeggi($this, $s);

    expect($s['riscaldamento']->id)->toBeLessThan($s['g']->id)
        ->and(cpaAltre($c, $s, 'Verdi', 'U3'))->toMatchArray(['posizione' => 20000, 'conguaglio' => 0, 'saldo' => 20000])
        ->and($c['persone'][$s['riscaldamento']->id] ?? [])->toBe([])
        ->and(cpcDi($c, $s, 'Verdi', 'U3'))->toMatchArray(['conguaglio' => 4000, 'posizione' => 0, 'fuori_dalle_rate' => 0, 'saldo' => 4000])
        ->and(cpcDi($c, $s, 'Verdi', 'U3'))->not->toHaveKey('altre_gestioni')
        ->and(cpaSaldoDellaPersona($c, $s, 'Verdi'))->toBe(24000)
        ->and(cpaSaldoDellaPersona($c, $s, 'Verdi'))->toBe(lcdEstratto($this, $s, 'Verdi') + 4000)
        ->and($c['da_altre_gestioni']['totali'])->toBe(['posizione' => 20000, 'personali' => 0, 'conguaglio' => 0, 'saldo' => 20000]);
});

it('la spesa personale del 2025 non chiesta sta nella riga da altre gestioni, con il suo conguaglio: il saldo della persona non cambia', function () {
    // € 30,00 su U3 il 20/11/2025, scritti sulla gestione del 2025: riga da altre gestioni personali 30,00 (nate prima 30,00), conguaglio 30,00,
    // saldo 30,00; la riga dell'ordinaria +40,00. Verdi: 70,00.
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    [$e2025, $g2025] = cpcAnnoPrima($s);
    cpcSpesaPersonale(array_merge($s, ['g' => $g2025]), 'U3', 3000, '2025-11-20', $e2025);
    $c = cpcLeggi($this, $s);

    expect(cpaAltre($c, $s, 'Verdi', 'U3'))->toMatchArray(['posizione' => 0, 'conguaglio' => 3000, 'saldo' => 3000])
        ->and(cpaAltre($c, $s, 'Verdi', 'U3')['personali'])->toMatchArray(['non_chieste' => 3000, 'di_cui_esercizi_prima' => 3000])
        ->and(cpcDi($c, $s, 'Verdi', 'U3')['personali']['non_chieste'])->toBe(0)
        ->and(cpcDi($c, $s, 'Verdi', 'U3')['conguaglio'])->toBe(4000)
        ->and(cpaSaldoDellaPersona($c, $s, 'Verdi'))->toBe(7000)
        ->and($c['da_altre_gestioni']['personali_aperte'])->toBe([$s['unita']['U3']->id => 3000]);
});

it('l\'avviso dice all\'amministratore che cosa controllare, con le cifre: il saldo scritto a mano non applicato', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    [$e2025, $g2025] = cpcAnnoPrima($s);
    cpcRataNonPagata($s, $e2025, $g2025, 'Verdi');
    cpcSaldoDaAnnoPrima($s, $e2025, 'Verdi', 'U3', 20000);
    $c = cpcLeggi($this, $s);
    $avviso = cpcDi($c, $s, 'Verdi', 'U3')['avviso_anno_prima'];

    expect($avviso)->toMatchArray(['altre_gestioni' => 20000, 'personali_esercizi_prima' => 0, 'saldi_con_origine' => 20000, 'applicati' => 0, 'esercizi_origine' => [$e2025->id]])
        ->and($avviso['frase'])->toBe('Il saldo iniziale scritto a mano di € 200,00 viene da «Esercizio 2025»: se comprende già la posizione ancora aperta di altre gestioni (€ 200,00), la stessa cifra conta due volte. Controllalo nei saldi iniziali dell\'esercizio, e togli la parte che è già nell\'altra cifra.')
        // Le cifre di oggi, non tolte: fuori dalle rate 200,00 e la riga da altre gestioni 200,00; saldo di Verdi 440,00.
        ->and(cpcDi($c, $s, 'Verdi', 'U3')['fuori_dalle_rate'])->toBe(20000)
        ->and(cpaSaldoDellaPersona($c, $s, 'Verdi'))->toBe(44000);
});

it('l\'avviso c\'è anche quando il saldo scritto a mano è già entrato in una rata di un piano dell\'esercizio', function () {
    // Il saldo di Verdi di € 200,00 entra nella prima rata del piano 2026 (riporto) e Verdi paga tutte le rate; poi si dichiara l'origine nel 2025, e
    // la rata del 2025 di € 200,00 è ancora aperta. Posizione 0, fuori dalle rate 0, conguaglio +40,00; da altre gestioni 200,00; l'avviso, con la
    // cifra già nelle rate.
    $s = cpcViaVerdi($this, ['saldo_verdi' => 20000]);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    [$e2025, $g2025] = cpcAnnoPrima($s);
    cpcRataNonPagata($s, $e2025, $g2025, 'Verdi');
    DB::table('saldi')->where('esercizio_id', $s['e']->id)->where('anagrafica_id', $s['persone']['Verdi']->id)->whereNull('subentro_id')
        ->update(['origine_dichiarata' => 'esercizio', 'esercizio_origine_id' => $e2025->id]);
    expect((bool) DB::table('saldi')->where('esercizio_id', $s['e']->id)->where('anagrafica_id', $s['persone']['Verdi']->id)->value('is_applicato'))->toBeTrue();
    $c = cpcLeggi($this, $s);
    $verdi = cpcDi($c, $s, 'Verdi', 'U3');

    expect($verdi)->toMatchArray(['posizione' => 0, 'fuori_dalle_rate' => 0, 'conguaglio' => 4000, 'saldo' => 4000])
        ->and(cpaAltre($c, $s, 'Verdi', 'U3')['posizione'])->toBe(20000)
        ->and($verdi['avviso_anno_prima'])->toMatchArray(['saldi_con_origine' => 20000, 'applicati' => 20000, 'altre_gestioni' => 20000])
        ->and($verdi['avviso_anno_prima']['frase'])->toBe('Il saldo iniziale scritto a mano di € 200,00 viene da «Esercizio 2025» ed è già nelle rate di questo esercizio: se comprende già la posizione ancora aperta di altre gestioni (€ 200,00), la stessa cifra conta due volte. Controllalo nei saldi iniziali dell\'esercizio, e togli la parte che è già nell\'altra cifra.');
});

it('senza rate o spese aperte di esercizi prima un saldo con l\'origine nel 2025 non fa l\'avviso', function () {
    $s = cpcViaVerdi($this, ['saldo_verdi' => 20000]);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    [$e2025] = cpcAnnoPrima($s);
    DB::table('saldi')->where('esercizio_id', $s['e']->id)->where('anagrafica_id', $s['persone']['Verdi']->id)->update(['origine_dichiarata' => 'esercizio', 'esercizio_origine_id' => $e2025->id]);

    expect(cpcDi(cpcLeggi($this, $s), $s, 'Verdi', 'U3')['avviso_anno_prima'])->toBeNull();
});

it('l\'inquilino uscito nell\'anno ha la sua parte per i suoi giorni, come le rate, e la sua riga anche se non è più inquilino', function () {
    // Gialli inquilino di U1 fino al 30/06 (181 giorni), il 30 % delle voci all'inquilino; dopo, la sua parte va al proprietario. A, resti maggiori
    // sull'intera voce: Pulizie 1.650,00 × 0,3 × 181/365 = 245,4657… → 245,47 (il centesimo a Gialli, ,57 contro ,42), Neri 1.404,53; Manutenzioni
    // 450,00 × 0,3 × 181/365 = 66,9452… → 66,95, Neri 383,05. Le rate (Pulizie 1.500,00 e Manutenzioni 500,00 di U1): Gialli 223,15 (,07 contro
    // ,93: il centesimo a Neri, 1.276,85) e 74,38 (Neri 425,62). Conguaglio Gialli 312,42 − 297,53 = +14,89; Neri 1.787,58 − 1.702,47 = +85,11.
    // Nessun incasso: posizione = B; saldo = A.
    $s = cpcViaVerdi($this, ['inquilino' => ['U1', 'Gialli', 30, '2020-01-01', '2026-06-30']]);
    cpcRateEIncassi($this, $s, [1, 2, 3, 4], []);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    expect(cpaColonne(cpcDi($c, $s, 'Gialli', 'U1')))->toBe(['a' => 31242, 'personali' => 0, 'b' => 29753, 'conguaglio' => 1489, 'posizione' => 29753, 'fuori' => 0, 'saldo' => 31242])
        ->and(cpaColonne(cpcDi($c, $s, 'Neri', 'U1')))->toBe(['a' => 178758, 'personali' => 0, 'b' => 170247, 'conguaglio' => 8511, 'posizione' => 170247, 'fuori' => 0, 'saldo' => 178758]);
});

it('il già versato che i piani non hanno usato e l\'eccedenza stanno in una riga che spiega, fuori dal conguaglio', function () {
    // U1 ha versato € 2.000,00 per le Pulizie prima del piano: il piano ne usa 1.500,00 (la sua parte), l'eccedenza è 500,00. A di Neri (1.650,00 −
    // 1.500,00) + 450,00 = 600,00; B 500,00 (le sole Manutenzioni); conguaglio +100,00. Dopo il piano U3 versa € 300,00 per le Manutenzioni: nessun
    // piano li usa. Nessuna cifra cambia; le righe li dicono.
    $s = cpcViaVerdi($this, ['gia_versato' => [['U1', 'pulizie', 200000, null]]]);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['manutenzioni']->id, 'immobile_id' => $s['unita']['U3']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $s['e']->id]);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);

    expect(cpaColonne(cpcDi($c, $s, 'Neri', 'U1')))->toMatchArray(['a' => 60000, 'b' => 50000, 'conguaglio' => 10000])
        ->and(cpcDi($c, $s, 'Neri', 'U1')['gia_versato_non_usato'])->toBe(['questo_esercizio' => 50000, 'tutti_gli_esercizi' => 0])
        ->and(cpcDi($c, $s, 'Verdi', 'U3')['gia_versato_non_usato'])->toBe(['questo_esercizio' => 30000, 'tutti_gli_esercizi' => 0])
        ->and(cpcDi($c, $s, 'Verdi', 'U3')['conguaglio'])->toBe(12000 - 8000)
        ->and(cpcDi($c, $s, 'Bianchi', 'U2')['gia_versato_non_usato'])->toBe(['questo_esercizio' => 0, 'tutti_gli_esercizi' => 0]);
});
