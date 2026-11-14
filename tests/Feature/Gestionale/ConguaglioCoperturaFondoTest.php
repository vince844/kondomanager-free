<?php

/**
 * Lo sforo coperto da un fondo: il conguaglio è lordo, e la scheda «Conguaglio» dice la copertura indicata sulla fattura; se la copertura è già eseguita con
 * un giroconto dal fondo, dice che il fondo ha già pagato e che approvando il conguaglio lordo il fondo si ricostituisce. La scelta di usare il fondo la fa
 * l'assemblea quando approva.
 *
 * Il caso è Via Verdi (`ConsuntivoPerPersonaPosizioneScenario.php`): Pulizie a preventivo € 3.000,00, spese € 3.300,00 con una fattura che indica la copertura
 * dello sforo, € 300,00, con il «Fondo di riserva». A mano: Neri Pulizie 1.650,00 + Manutenzioni 450,00 − quote 2.000,00 = +100,00, lordo (al netto della
 * copertura sarebbe Pulizie su 3.000,00: 1.500,00 + 450,00 − 2.000,00 = −50,00).
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
});

/** Via Verdi con la fattura delle Pulizie che indica la copertura dello sforo con il fondo; `$eseguita`: il giroconto dal fondo c'è. */
function cfxCaso($test, bool $eseguita, bool $conGiroconto = true): array
{
    $s = cpcViaVerdi($test);
    cpcRateEIncassi($test, $s);
    $fattura = cpmSpesa($s, $s['giornale'], $s['pulizie'], 330000, ['competenza_dal' => null, 'competenza_al' => null]);
    cpmSpesa($s, $s['giornale'], $s['manutenzioni'], 90000);
    $fondo = DB::table('conti_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'codice' => '15.10', 'nome' => 'Fondo di riserva', 'tipo' => 'attivo', 'categoria' => 'liquidita',
        'created_at' => now(), 'updated_at' => now()]);
    $giroconto = null;
    if ($eseguita && $conGiroconto) {
        $giroconto = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-10-20',
            'data_competenza' => '2026-10-20', 'numero_protocollo' => 'CFX-G', 'causale' => 'giroconto', 'tipo_movimento' => 'giroconto', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('fattura_coperture')->insert(['fattura_passiva_id' => $fattura, 'tipo_copertura' => 'fondo_riserva', 'importo' => 30000, 'stato' => $eseguita ? 'confermata' : 'pianificata',
        'fondo_id' => $fondo, 'scrittura_giroconto_id' => $giroconto, 'nota_amministratore' => 'Copertura sforo budget (Art. 1135 c.c.): prova', 'created_at' => now(), 'updated_at' => now()]);
    $test->travelTo('2027-01-10 10:00:00');
    $p = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['conguaglio'];

    return [$s, $p, DB::table('fatture_passive')->where('id', $fattura)->value('numero_documento')];
}

it('la copertura indicata sulla fattura: il conguaglio resta lordo, Neri +100,00, e la scheda dice la copertura', function () {
    [$s, $p, $numero] = cfxCaso($this, false);
    $neri = collect($p['righe'])->first(fn ($r) => ! $r['subtotale'] && $r['anagrafica_id'] === $s['persone']['Neri']->id);
    $gestione = collect($p['gestioni'])->firstWhere('id', $s['g']->id);

    expect($neri['conguaglio'])->toBe(10000)
        ->and($gestione['coperture'])->toBe(['Pulizie: la fattura n. '.$numero.' indica la copertura dello sforo con il fondo «Fondo di riserva», € 300,00. Il conguaglio è lordo: '
            .'se usare il fondo lo decide l\'assemblea quando approva. Al netto della copertura il conguaglio del condominio sarebbe € 300,00 più basso.']);
});

it('la copertura già eseguita con un giroconto dal fondo: il fondo ha già pagato, i condòmini li rimettono con il conguaglio lordo, e la cifra netta', function () {
    [$s, $p, $numero] = cfxCaso($this, true);
    $neri = collect($p['righe'])->first(fn ($r) => ! $r['subtotale'] && $r['anagrafica_id'] === $s['persone']['Neri']->id);
    $gestione = collect($p['gestioni'])->firstWhere('id', $s['g']->id);

    expect($neri['conguaglio'])->toBe(10000)
        // Il fondo ha già pagato: che cosa succede lo decide l'assemblea, lordo o netto; la frase dice tutte e due le strade.
        ->and($gestione['coperture'])->toBe(['Pulizie: la fattura n. '.$numero.' indica la copertura dello sforo con il fondo «Fondo di riserva», € 300,00. Il fondo ha già pagato € 300,00. '
            .'Se l\'assemblea approva il conguaglio lordo, i condòmini li rimettono e tornano al fondo con un giroconto dalla banca; se lo approva al netto, il fondo resta com\'è. '
            .'Al netto della copertura il conguaglio del condominio sarebbe € 300,00 più basso.']);
});

it('una copertura confermata senza il giroconto non dice che il fondo ha già pagato', function () {
    [$s, $p, $numero] = cfxCaso($this, true, false);
    $gestione = collect($p['gestioni'])->firstWhere('id', $s['g']->id);

    expect($gestione['coperture'][0])->not->toContain('ha già pagato')->toContain('se usare il fondo lo decide l\'assemblea quando approva');
});
