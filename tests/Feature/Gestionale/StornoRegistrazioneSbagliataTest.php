<?php

/**
 * Le parole delle due nature dello storno di un incasso (decisione 219), con le date della decisione 217 che non cambiano.
 *
 * Il caso che le ha cambiate: un versamento vero registrato male. Ugo versa € 100,00 il 10/12 e la causale dice «rata 1»; l'incasso si
 * registra sulla rata 2. Il 15/01 si storna e si registra di nuovo sulla rata 1 con la data del versamento. «Registrazione sbagliata» lo
 * toglie dal 10/12: al 31/12 la banca ha € 100,00 e la rata 2 resta da pagare. «Tornato indietro» lo toglie dal 15/01: al 31/12 la banca
 * ha € 200,00 e le due rate risultano pagate, perché il versamento conta due volte fino allo storno.
 *
 * Un incasso fatto solo di credito (€ 0,00 in cassa) non ha un versamento: le stesse due nature si dicono con le parole della compensazione.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Il saldo della banca nel giornale (DARE − AVERE, centesimi) con data fino al giorno dato. */
function srsBanca(array $s, string $d): int
{
    return (int) DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
        ->whereNull('sc.deleted_at')->where('sc.condominio_id', $s['c']->id)->where('cc.ruolo', 'banca')
        ->where('sc.data_competenza', '<', \Carbon\CarbonImmutable::parse($d)->addDay()->format('Y-m-d'))
        ->sum(DB::raw("CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END"));
}

function srsPagina($test, array $s, int $incasso): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.movimenti-rate.show', [$s['c'], $incasso]))->assertOk()->viewData('page')['props'];
}

/** Ugo, due rate da 10000 (30/11 e 30/12/2026) emesse il 01/11; € 100,00 del 10/12 registrati sulla rata 2; il 15/01 lo storno con la natura data e il nuovo incasso sulla rata 1. */
function srsImputazioneSbagliata($test, string $natura): array
{
    $test->travelTo('2026-10-30 09:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 2, 'prima_rata', [], 20000, '2026-11-30', 30);
    $test->travelTo('2026-11-01 10:00:00');
    lcdEmetti($test, $s, [1, 2], '2026-11-01');
    $test->travelTo('2026-12-10 10:00:00');
    $sbagliato = lcdIncassa($test, $s, 'ugo', [lcdQuota($s, 'ugo', 2) => 10000], '2026-12-10');

    $test->travelTo('2027-01-15 11:00:00');
    lcdStorna($test, $s, $sbagliato, ['natura_storno' => $natura]);
    lcdIncassa($test, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 10000], '2026-12-10');

    return [$s, $sbagliato];
}

it('registrazione sbagliata: il versamento vero registrato sulla rata sbagliata si toglie dal suo giorno; al 31/12 la banca ha € 100,00 e la rata 2 resta da pagare', function () {
    [$s, $sbagliato] = srsImputazioneSbagliata($this, 'errore_registrazione');

    $l = lcdLeggi($s, 'ugo', '2026-12-31');

    // La rata 1 è saldata: la lettura sommata nel database (dalla 1.12.0-beta.4) non porta più la chiave a zero.
    expect(lcdRiga($l, $s, 'A', 1))->toBeNull()
        ->and(lcdRiga($l, $s, 'A', 2)['residuo'])->toBe(10000)
        ->and(srsBanca($s, '2026-12-31'))->toBe(10000)
        ->and(srsPagina($this, $s, $sbagliato)['storno'])->toMatchArray([
            'vale_dal'     => '2026-12-10',
            'natura'       => 'errore_registrazione',
            'natura_testo' => 'Registrazione sbagliata: il versamento non c\'è mai stato, o va registrato di nuovo in un altro modo',
        ]);
});

it('versamento tornato indietro: vale da oggi, quindi fino allo storno il versamento conta due volte; la pagina lo dice con le sue parole', function () {
    [$s, $sbagliato] = srsImputazioneSbagliata($this, 'versamento_tornato');

    $l = lcdLeggi($s, 'ugo', '2026-12-31');

    // La rata 2 è saldata: la lettura sommata nel database (dalla 1.12.0-beta.4) non porta più la chiave a zero.
    expect(lcdRiga($l, $s, 'A', 2))->toBeNull()
        ->and(srsBanca($s, '2026-12-31'))->toBe(20000)
        ->and(srsPagina($this, $s, $sbagliato)['storno'])->toMatchArray([
            'vale_dal'     => '2027-01-15',
            'natura_testo' => 'Il versamento c\'era ed è tornato indietro (assegno insoluto, addebito respinto, bonifico richiamato)',
        ]);
});

it('senza la natura il server rifiuta con le parole delle due nature', function () {
    [$s] = srsImputazioneSbagliata($this, 'errore_registrazione');
    $incasso = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->where('stato', '!=', 'annullata')->max('id');

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]), [])
        ->assertSessionHasErrors(['natura_storno' => 'Scegli perché storni questo incasso: registrazione sbagliata (vale dal giorno del versamento) o versamento tornato indietro (vale da oggi).']);
});

it('un incasso fatto solo di credito: il rifiuto e la pagina parlano della compensazione, con le stesse date', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 3, 'rata_zero', [['ugo', 'A', -5000]], 30000, '2026-01-05');
    lcdEmetti($this, $s, [1, 2, 3], '2026-01-01');
    $compensazione = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -5000, lcdQuota($s, 'ugo', 1) => 5000], '2026-03-10');

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $compensazione]), [])
        ->assertSessionHasErrors(['natura_storno' => 'Scegli perché storni questa compensazione: è sbagliata (si toglie dal giorno in cui è stata fatta) o era giusta e si annulla da oggi.']);

    lcdStorna($this, $s, $compensazione, ['natura_storno' => 'errore_registrazione']);

    expect(srsPagina($this, $s, $compensazione)['storno'])->toMatchArray([
        'vale_dal'     => '2026-03-10',
        'natura_testo' => 'La compensazione è sbagliata',
    ]);
});

it('la compensazione giusta che si annulla da oggi', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 3, 'rata_zero', [['ugo', 'A', -5000]], 30000, '2026-01-05');
    lcdEmetti($this, $s, [1, 2, 3], '2026-01-01');
    $compensazione = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -5000, lcdQuota($s, 'ugo', 1) => 5000], '2026-03-10');

    lcdStorna($this, $s, $compensazione, ['natura_storno' => 'versamento_tornato']);

    expect(srsPagina($this, $s, $compensazione)['storno'])->toMatchArray([
        'vale_dal'     => '2026-03-20',
        'natura_testo' => 'La compensazione era giusta ed è stata annullata',
    ]);
});
