<?php

/**
 * 1.11.0-beta.50 — la fine di una locazione in cui resta un coinquilino (decisioni 79, punto 2, e 80).
 *
 * Ines e Dora sono inquiline dell'Interno 1 al 50 %; il 1/7 Ines esce e Dora resta. Nessun documento dice a chi vanno i giorni dopo
 * l'uscita di Ines (il coinquilino che resta, il proprietario, le parti): il programma non lo indovina. Alla fine locazione la scelta
 * del conguaglio non c'è: nessuna coppia, nessuna scelta, e un `conguaglio` mandato a mano si rifiuta. Il pannello lo dice, con la
 * cifra e la persona (decisione 80), e indica il saldo manuale dal Wallet solo se Ines non ha già un saldo su quella gestione (il
 * Wallet ne accetta uno per persona, gestione, unità ed esercizio); altrimenti dice che la parte si regola fra le parti. Il pannello
 * non dice le due frasi che lo contraddirebbero, segnalate dalla revisione della Fase 1 e tolte: la riga «credito € 90,74 a Ines,
 * debito € 90,74 a Ugo» e «resteranno intestate a Ines e si conguagliano qui».
 *
 * Le cifre a mano: «Spese generali» € 1.200,00, 30 % «Inquilino» = 36000, diviso per quota fra le inquiline: Ines 18000, Dora 18000;
 * 70 % «Proprietario» = 84000 a Ugo. La parte di Ines dopo l'uscita, con la regola della coppia: 18000 × [181, 184] = 8926,03 /
 * 9073,97 → parti intere 8926 + 9073 = 17999, il centesimo al resto più grande (,97) → 9074, cioè € 90,74, sulla gestione «Ordinaria
 * 2026». Il denaro non cambia: Ines 18000, Dora 18000, Ugo 84000.
 *
 * ➕ 11/10/2026 notte, revisione dei test della Fase 1: la frase con la cifra non può essere la riga «credito/debito € 90,74» del
 *   conguaglio che non si scrive (con quella riga una cifra sbagliata nella frase della fermata passava), e la fine non prende piani
 *   (decisione 47).
 *
 * Cosa NON copre: il coinquilino con la regola del piano (Coda 245, dopo la 1.11); il coinquilino solo sul box o solo sull'unità con il
 * box spuntato; più coinquilini che restano; il prospetto con il coinquilino (area del prospetto: Ines e Dora restano a 365 giorni); il
 * registro e lo storico della fermata (in `RevisioneBeta50FrasiTest`, R12); la pagina Vue; MySQL.
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

it('controllo — con Dora che resta non c\'è nessuna coppia e nessuna scelta: la fine di Ines si registra senza il campo, e il denaro non cambia', function () {
    // Decisione 79 (2): «la scelta del conguaglio non c'è». Controllo, già giusto prima della .50: fissa che il codice nuovo non dia
    // la coppia a Ugo (la misura della 0.2 dava € 90,74 a Ugo) né a Dora.
    $s = ccrScenario($this);
    expect(fclNetti($s))->toBe(['Dora' => 18000, 'Ines' => 18000, 'Ugo' => 84000]);

    expect(fclCoppie(fclAnteprima($this, $s, fclModulo($s))))->toBe([]);
    $fine = fclRegistra($this, $s, fclModulo($s))->fresh();

    expect(fclRigheDeiPassaggi($s))->toBe(0)
        ->and($fine->registro)->not->toHaveKey('conguaglio')
        // Decisione 47: senza conguaglio nessun piano preso; altrimenti il piano a giornale resterebbe fermo «per il conguaglio» di una
        // fine che non ne ha (aggiunto nella revisione dei test della Fase 1).
        ->and($fine->registro['piani_presi'] ?? null)->toBe([])
        ->and(fclNetti($s))->toBe(['Dora' => 18000, 'Ines' => 18000, 'Ugo' => 84000]);
});

it('controllo — un «conguaglio» mandato a mano con Dora che resta si rifiuta sul campo, e non scrive niente', function (string $scelta) {
    // Decisione 79 (2) e rilievo SIC-2 della .48: un valore mandato a mano dove la scelta non c'è si rifiuta, non si ignora. Prima
    // della .50 dava lo stesso esito (il campo era vietato fuori dalla successione).
    $s = ccrScenario($this);

    fclStore($this, $s, fclModulo($s) + ['conguaglio' => $scelta])->assertSessionHasErrors('conguaglio');

    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0)
        ->and(fclRigheDeiPassaggi($s))->toBe(0);
})->with(['scrivi', 'non_scrivere']);

it('il rifiuto del «conguaglio» mandato a mano con Dora che resta non dice che la scelta c\'è solo nella successione, che dalla .50 è falso', function () {
    // Dalla .50 la scelta c'è anche nella fine locazione (decisione 77): la ragione del rifiuto è il coinquilino che resta.
    $s = ccrScenario($this);

    $errore = (string) (fclStore($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi'])->getSession()->get('errors')?->first('conguaglio') ?? '');

    expect($errore)->not->toBe('')
        ->and($errore)->not->toContain('solo nella successione');
});

it('il pannello dice la fermata con la cifra e la persona: € 90,74 dei giorni dopo l\'uscita di Ines sulla gestione Ordinaria 2026, e il saldo manuale dal Wallet, perché Ines non ha già un saldo lì', function () {
    // Decisioni 79 (2) e 80.
    $s = ccrScenario($this);

    $frasi = fclFrasi(fclAnteprima($this, $s, fclModulo($s)));

    // La cifra: 9074 → € 90,74 (vedi la testa del file); la persona: Ines; la gestione; la strada a mano. La frase della fermata, non
    // la riga «credito € 90,74 a Ines, debito € 90,74 a Ugo» del conguaglio che non si scrive (la revisione dei test della Fase 1:
    // con quella riga ancora nel pannello, una cifra sbagliata nella frase della fermata passava).
    $conLaCifra = array_values(array_filter(fclPeriodi($frasi), fn ($p) => str_contains($p, '€ 90,74') && ! str_contains($p, 'credito € 90,74') && ! str_contains($p, 'debito € 90,74')));
    expect($conLaCifra)->not->toBe([])
        ->and(collect($conLaCifra)->contains(fn ($p) => str_contains($p, 'Ines') && str_contains($p, 'Ordinaria 2026')))->toBeTrue(implode(' | ', $conLaCifra))
        ->and($frasi)->toContain('Wallet');
});

it('con un saldo di Ines già sulla gestione il pannello dice la cifra e che la parte si regola fra le parti, e non indica il Wallet, che rifiuterebbe la riga', function () {
    // Decisione 80: «il Wallet solo se la persona non ha già un saldo su quella gestione; altrimenti dicono che la parte si regola fra le
    // parti».
    $s = ccrScenario($this, riporto: true);

    $frasi = fclFrasi(fclAnteprima($this, $s, fclModulo($s)));

    expect(collect(fclPeriodi($frasi))->contains(fn ($p) => str_contains($p, '€ 90,74') && str_contains($p, 'Ines') && ! str_contains($p, 'credito € 90,74') && ! str_contains($p, 'debito € 90,74')))->toBeTrue($frasi)
        ->and($frasi)->toContain('fra le parti')
        ->and($frasi)->not->toContain('Wallet');
});

it('il pannello con Dora che resta non dice le due frasi che contraddicono la fermata: nessuna riga «credito/debito € 90,74», nessun «si conguagliano qui»', function () {
    // Correzione della revisione della Fase 1 (tema «il coinquilino che resta», strada b): nessuna coppia si scrive, quindi il pannello
    // non deve proporla né dire che le quote «si conguagliano qui». Controllo, già giusto prima della .50 (il codice di prima non
    // calcolava nessun conguaglio).
    $s = ccrScenario($this);

    $frasi = fclFrasi(fclAnteprima($this, $s, fclModulo($s)));

    expect($frasi)->not->toContain('credito € 90,74')
        ->and($frasi)->not->toContain('debito € 90,74')
        ->and($frasi)->not->toContain('si conguagliano qui');
});
