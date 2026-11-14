<?php

/**
 * Le frasi del conguaglio per l'amministratore: il segno detto senza nominare un altro programma, chi decide del già versato non usato, e gli avvisi che
 * dicono che cosa fare (la delibera che manca, i costi senza voce, il passaggio regolato fuori dal registro).
 */

use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\Conto;
use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

it('il segno si dice da solo, senza nominare un altro programma, in nessuna frase del conguaglio', function () {
    expect(ConguaglioDelRendiconto::FRASE_SEGNO)->toBe('Il segno più vuol dire da versare, il segno meno vuol dire a credito.');
    $frasi = [];
    $costanti = (new ReflectionClass(ConguaglioDelRendiconto::class))->getConstants();
    array_walk_recursive($costanti, function ($v) use (&$frasi) {
        if (is_string($v)) {
            $frasi[] = $v;
        }
    });
    expect(implode(' ', $frasi))->not->toContain('Danea');
});

it('il già versato non usato dice che a decidere è l\'amministratore, non una chiusura dell\'esercizio', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['pulizie']->id, 'immobile_id' => $s['unita']['U2']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => $s['e']->id]);
    cpcSpese($s);
    $this->travelTo('2026-12-31 12:00:00');
    $k = app(ConguaglioDelRendiconto::class);
    $righe = $k->righe($s['c'], $s['e']->fresh(), $k->leggi($s['e']->fresh()));
    $note = collect($righe['gestioni'][0]['righe'])->pluck('note')->flatten()->filter(fn ($n) => str_contains($n, 'Già versato'))->values()->all();

    expect($note)->toBe(['Già versato registrato per questo esercizio e non usato dai suoi piani: € 300,00. Non entra nel conguaglio, e il programma non lo sposta da solo: '
        .'che cosa farne lo decidi tu (per esempio con un piano che lo usa).']);
});

it('la straordinaria senza la data della delibera dice dove si scrive', function () {
    $s = cpmViaVerdi('straordinaria');
    $facciata = cpmVoce($s, 'Facciata', [[$s['generali'], 100]]);
    $esito = cpmPorta($s, [['conto_id' => $facciata->id, 'importo' => 100000, 'competenza_dal' => null, 'competenza_al' => null]], ['delibere' => []]);

    expect($esito['non_calcolabile'])->toBe('Manca la data della delibera: una spesa straordinaria senza competenza scritta sulla fattura si divide fra chi era titolare il giorno '
        .'della delibera. Scrivi la data della delibera nel piano, o la competenza sulla fattura.');
});

it('i costi non classificati dicono dove si dà la voce', function () {
    expect(ConguaglioDelRendiconto::FUORI_DAI_CAPITOLI['non_classificati'][1])
        ->toBe('Sono righe di costo senza voce. Apri le scritture nel Libro Giornale: la voce si dà stornando la registrazione e rifacendola con la voce.');
});

it('il passaggio regolato fra le parti senza la cifra nel registro dice che quel conguaglio si fa a mano', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $sub = cpcPassaggio($this, $s);
    // La rinuncia al conguaglio, senza la cifra regolata nel registro (un passaggio di prima che il registro la tenesse).
    \Illuminate\Support\Facades\DB::table('subentri')->where('id', $sub->id)->update(['nota_conguaglio' => 'Regolato nel rogito', 'registro' => json_encode([])]);
    cpcSpese($s);
    $c = cpcLeggi($this, $s);
    $motivo = cpcDi($c, $s, 'Rossi', 'U2')['non_disponibile'] ?? cpcDi($c, $s, 'Bianchi', 'U2')['non_disponibile'] ?? null;

    expect($motivo)->toBe('Il passaggio è regolato fra le parti, ma la cifra non è nel registro: si legge dalla nota del passaggio, e il conguaglio di queste persone su questa '
        .'unità si fa a mano.');
});
