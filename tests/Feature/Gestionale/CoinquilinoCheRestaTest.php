<?php

/**
 * 1.11.0-beta.50 — la fine di una locazione in cui resta un coinquilino (decisioni 79, punto 2, e 80); dalla 1.11.0-beta.55 con la regola
 * del piano (Coda 245).
 *
 * Ines e Dora sono inquiline dell'Interno 1 al 50 %; il 1/7 Ines esce e Dora resta. Fino alla .54 la scelta del conguaglio non c'era: nessuna
 * coppia, la fermata del coinquilino con la cifra dei giorni dopo l'uscita di Ines (€ 90,74) e la strada a mano del Wallet. ➕ Dalla .55 la
 * parte dell'inquilino che il piano ha congelato si ridivide per quota e per giorni fra chi è stato conduttore (decisione 29.1, la regola del
 * piano), e la scelta c'è come nelle altre fini, senza preselezione: con «Scrivi il conguaglio» le righe portano ognuno alla sua parte, con
 * «Non scriverlo» nessuna riga, e le parti regolano con il prospetto. I test qui sotto erano della fermata: si cambiano, dicendo il perché.
 *
 * Le cifre a mano: «Spese generali» € 1.200,00, 30 % «Inquilino» = 36000, congelato per quota: Ines 18000, Dora 18000; 70 % «Proprietario» =
 * 84000 a Ugo. Con la regola del piano, dopo l'uscita: Ines 50 × 181 = 9050, Dora 50 × 365 = 18250 (somma 27300); 36000 × 9050/27300 =
 * 11934,07 → Ines 11934, Dora 24066 (il centesimo al resto più grande, ,93): la coppia è Ines − € 60,66, Dora + € 60,66, sulla gestione
 * «Ordinaria 2026». (Con la regola dei giorni della .50, la parte di Ines dopo l'uscita era 18000 × 184/365 = 9074, € 90,74: non c'è più.)
 *
 * Cosa NON copre: le cifre della regola del piano negli altri casi (`Beta55RegolaDelPianoTest`) e la registrazione per intero
 * (`Beta55CoinquilinoCoppiaDelPianoTest`); il prospetto; la pagina Vue; MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';

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

/**
 * L'Interno 1 di Ugo con Ines e Dora inquiline al 50 % dal 2019, il preventivo generato e le rate gennaio–giugno a giornale. Con
 * `$riporto` Ines ha già un saldo pregresso a mano (€ 50,00 a debito) sulla gestione, scritto prima della generazione: il caso normale
 * della decisione 80.
 */
function ccrScenario($test, bool $riporto = false): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    if ($riporto) {
        fclRiporto($s, 'Ines', 5000);
    }
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);

    return $s;
}

it('con Dora che resta la coppia è della regola del piano e la scelta c\'è: senza la scelta la registrazione si ferma sul campo; con «Scrivi» Ines 11934, Dora 24066', function () {
    // Coda 245 (1.11.0-beta.55): prima «nessuna coppia, nessuna scelta», la fine registrata senza il campo, 0 righe, i piani non presi e i netti
    // 18000 / 18000 / 84000. Ora la coppia [Dora + 6066] e la scelta; la fine prende il piano (decisione 47, la fine con la scelta).
    $s = ccrScenario($this);
    expect(fclNetti($s))->toBe(['Dora' => 18000, 'Ines' => 18000, 'Ugo' => 84000]);

    $a = fclAnteprima($this, $s, fclModulo($s));
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Dora', 6066]])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeTrue();
    fclStore($this, $s, fclModulo($s))->assertSessionHasErrors(['conguaglio' => 'Scegli se scrivere il conguaglio: il programma non lo sceglie al posto tuo.']);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi'])->fresh();

    expect(fclSaldi($s))->toBe(['Dora' => 6066, 'Ines' => -6066])
        ->and($fine->registro['conguaglio']['scelta'])->toBe(Subentro::CONGUAGLIO_SCRITTO)
        ->and($fine->registro['conguaglio']['piano'][0]['parti'])->toBe([(int) $s['persone']['Ines']->id => -6066, (int) $s['persone']['Dora']->id => 6066])
        ->and($fine->registro['piani_presi'] ?? null)->toBe([(int) $s['piano']->id])
        ->and(fclNetti($s))->toBe(['Dora' => 24066, 'Ines' => 11934, 'Ugo' => 84000]);
});

it('il campo «conguaglio» con Dora che resta si accetta: «Scrivi» scrive le due righe, «Non scriverlo» nessuna, e il registro dice ciò che le parti regolano', function (string $scelta) {
    // Coda 245 (1.11.0-beta.55): fino alla .54 il campo si rifiutava («La locazione continua con un coinquilino…») e nessun passaggio si scriveva.
    $s = ccrScenario($this);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => $scelta])->fresh();

    expect(fclSaldi($s))->toBe($scelta === 'scrivi' ? ['Dora' => 6066, 'Ines' => -6066] : [])
        ->and($fine->registro['conguaglio']['scelta'])->toBe($scelta === 'scrivi' ? Subentro::CONGUAGLIO_SCRITTO : Subentro::CONGUAGLIO_NON_SCRITTO);
    if ($scelta === 'non_scrivere') {
        expect(collect($fine->registro['regolato_fuori'][0]['persone'])->map(fn ($p) => [$p['nome'], (int) $p['importo']])->all())->toBe([['Ines', -6066], ['Dora', 6066]]);
    }
})->with(['scrivi', 'non_scrivere']);

it('il pannello dice la regola del piano con le cifre di ognuno sulla gestione Ordinaria 2026, le righe di «Scrivi» e chi rimborsa chi con «Non scriverlo»; non dice più € 90,74', function () {
    // Coda 245 (1.11.0-beta.55): prima la frase della fermata, con € 90,74, Ines, la gestione e il Wallet.
    $s = ccrScenario($this);

    $frasi = fclFrasi(fclAnteprima($this, $s, fclModulo($s)));

    expect($frasi)->toContain('Sulla gestione Ordinaria 2026 Ines € 119,34 per i suoi 181 giorni e Dora € 240,66 per i suoi 365 giorni')
        ->and($frasi)->toContain('le righe nei saldi sono € 60,66 a credito di Ines e € 60,66 a debito di Dora')
        ->and($frasi)->toContain('il prospetto degli oneri accessori dice che Dora rimborsa a Ines € 60,66')
        ->and($frasi)->not->toContain('€ 90,74');
});

it('con un saldo di Ines già sulla gestione il pannello dice le stesse cifre: il riporto non entra nella regola del piano', function () {
    // Coda 245 (1.11.0-beta.55): prima il test diceva che la frase della fermata indicava il Wallet anche con il riporto di Ines; il riporto
    // è un saldo pregresso, fuori dalla quota pura (`quota_pura_gestione`), e l'anello non lo vede.
    $s = ccrScenario($this, riporto: true);

    $frasi = fclFrasi(fclAnteprima($this, $s, fclModulo($s)));

    expect($frasi)->toContain('le righe nei saldi sono € 60,66 a credito di Ines e € 60,66 a debito di Dora')
        ->and($frasi)->not->toContain('€ 90,74')
        ->and($frasi)->not->toContain('fra le parti: Ines ha già un saldo');
});

it('il pannello con Dora che resta dice le righe della regola del piano e, delle 6 bozze, che «se scrivi il conguaglio» si conguagliano qui; nessun € 90,74', function () {
    // Coda 245 (1.11.0-beta.55): l'asserzione di prima si capovolge. Senza coppia il pannello non doveva dire «si conguagliano qui»; con la coppia
    // della regola del piano le bozze del piano fermo sono comprese nel conguaglio, se lo si scrive.
    $s = ccrScenario($this);

    $frasi = fclFrasi(fclAnteprima($this, $s, fclModulo($s)));

    expect($frasi)->not->toContain('€ 90,74')
        ->and($frasi)->toContain('€ 60,66 a credito di Ines')
        ->and($frasi)->toContain('e, se scrivi il conguaglio, si conguagliano qui');
});
