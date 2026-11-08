<?php

/**
 * Le due date dei sei mesi di una rata scaduta (art. 1129 c. 9): dalla scadenza (la fine dell'esercizio che contiene la scadenza, più sei
 * mesi) e dalla competenza (la fine dell'esercizio del piano, o di quello della delibera per una gestione straordinaria, più sei mesi); la più
 * vicina in evidenza, mai una data che viene prima della scadenza della rata. Dove la data non si calcola, la cella lo dice. La dispensa
 * dell'assemblea si mostra accanto alle date che copre, e le date non cambiano.
 *
 * Le date sono scritte a mano dalla regola: «più sei mesi» è lo stesso giorno del sesto mese, o l'ultimo di quel mese se il giorno manca.
 */

use App\Models\Esercizio;
use App\Models\User;
use App\Services\Gestionale\Debiti\SeiMesi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

/** Ugo, un piano di 12 rate mensili dal 05/07/2026, tutte emesse il 01/07/2026, con il solo esercizio 2026 (aperto). */
function smPiano($test): array
{
    $test->travelTo('2026-06-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [], 120000, '2026-07-05', 5);
    lcdEmetti($test, $s, range(1, 12), '2026-07-01');

    return $s;
}

/** Le date di ogni rata scaduta di Ugo alla data, per numero di rata. */
function smDate(array $s, string $alla): array
{
    $calcolo = app(SeiMesi::class)->perCondominio((int) $s['c']->id);
    $esito = [];
    foreach (lcdLeggi($s, 'ugo', $alla)['rate'] as $r) {
        $esito[$r['numero_rata']] = $calcolo->perRiga($r, (int) $s['persone']['ugo']->id);
    }

    return $esito;
}

function smEsercizio(array $s, string $nome, string $dal, string $al, string $stato = 'chiuso'): Esercizio
{
    return Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => $nome, 'data_inizio' => $dal, 'data_fine' => $al, 'stato' => $stato]);
}

it('«più sei mesi»: le sei date della regola, e il giorno festivo non sposta la data', function () {
    expect(SeiMesi::piuSeiMesi('2026-12-31'))->toBe('2027-06-30')
        ->and(SeiMesi::piuSeiMesi('2026-06-30'))->toBe('2026-12-30')
        ->and(SeiMesi::piuSeiMesi('2026-08-31'))->toBe('2027-02-28')
        ->and(SeiMesi::piuSeiMesi('2026-10-31'))->toBe('2027-04-30')
        ->and(SeiMesi::piuSeiMesi('2026-02-28'))->toBe('2026-08-28')
        ->and(SeiMesi::piuSeiMesi('2028-02-29'))->toBe('2028-08-29')
        // Un esercizio chiuso il 31/12/2029: il 30/06/2030 è una domenica, e la data resta quella (la più presto).
        ->and(SeiMesi::piuSeiMesi('2029-12-31'))->toBe('2030-06-30')
        ->and(CarbonImmutable::parse('2030-06-30')->isSunday())->toBeTrue();
});

it('con il solo esercizio 2026 le rate di gennaio–giugno 2027 non hanno la data dalla scadenza: la cella dice di chiudere il 2026; dalla competenza 30/06/2027', function () {
    $s = smPiano($this);
    $this->travelTo('2027-07-10 10:00:00');
    $date = smDate($s, '2027-07-10');

    foreach (range(1, 6) as $n) {
        expect($date[$n]['dalla_scadenza'])->toMatchArray(['data' => '2027-06-30', 'stato' => 'ok'])
            ->and($date[$n]['dalla_competenza'])->toMatchArray(['data' => '2027-06-30', 'stato' => 'ok', 'dedotto' => false])
            ->and($date[$n]['piu_vicina'])->toBe('2027-06-30');
    }
    foreach (range(7, 12) as $n) {
        expect($date[$n]['dalla_scadenza'])->toMatchArray(['data' => null, 'stato' => 'manca_esercizio', 'esercizio_da_chiudere' => 'Esercizio 2026'])
            ->and($date[$n]['dalla_scadenza']['data_cercata'])->toBe(sprintf('2027-%02d-05', $n - 6))
            ->and($date[$n]['dalla_competenza'])->toMatchArray(['data' => '2027-06-30'])
            ->and($date[$n]['piu_vicina'])->toBe('2027-06-30');
    }
});

it('con il 2027 creato la rata 7 (05/01/2027) ha 30/06/2028 dalla scadenza e 30/06/2027 dalla competenza, in evidenza la seconda; due esercizi sulla rata 10', function () {
    $s = smPiano($this);
    smEsercizio($s, 'Esercizio 2027', '2027-01-01', '2027-12-31', 'aperto');
    $this->travelTo('2027-07-10 10:00:00');
    $date = smDate($s, '2027-07-10');

    expect($date[7]['dalla_scadenza'])->toMatchArray(['data' => '2028-06-30', 'stato' => 'ok', 'doppio' => false])
        ->and($date[7]['dalla_competenza']['data'])->toBe('2027-06-30')
        ->and($date[7]['piu_vicina'])->toBe('2027-06-30');

    // Un esercizio dal 01/03/2027 al 29/02/2028: la rata 10 (05/04/2027) cade in due esercizi; si mostra la più vicina delle due (30/06/2028
    // dal 2027, contro il 29/08/2028), con il segno.
    smEsercizio($s, 'Esercizio 2027/28', '2027-03-01', '2028-02-29');
    $date = smDate($s, '2027-07-10');
    expect($date[10]['dalla_scadenza'])->toMatchArray(['data' => '2028-06-30', 'doppio' => true])
        ->and($date[7]['dalla_scadenza']['doppio'])->toBeFalse();
});

it('un piano senza esercizio salvato: la competenza si deduce, e la cella lo dice', function () {
    $s = smPiano($this);
    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['esercizio_id' => null]);
    $this->travelTo('2026-10-10 10:00:00');

    expect(smDate($s, '2026-10-10')[1]['dalla_competenza'])->toMatchArray(['data' => '2027-06-30', 'stato' => 'ok', 'dedotto' => true]);
});

it('un piano straordinario con la delibera del 15/03/2026 e la rata del 05/09/2027: letto il 01/10/2027 la data dalla competenza (30/06/2027) viene prima della scadenza, e in evidenza va il 30/06/2028; senza la delibera, la frase', function () {
    $s = smPiano($this);
    smEsercizio($s, 'Esercizio 2027', '2027-01-01', '2027-12-31', 'aperto');
    DB::table('gestioni')->where('id', $s['g']->id)->update(['tipo' => 'straordinaria']);
    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['data_delibera_assemblea' => '2026-03-15']);
    DB::table('rate')->where('id', lcdRata($s, 12))->update(['data_scadenza' => '2027-09-05']);
    $this->travelTo('2027-10-01 10:00:00');

    $r = smDate($s, '2027-10-01')[12];
    expect($r['dalla_scadenza'])->toMatchArray(['data' => '2028-06-30', 'stato' => 'ok'])
        ->and($r['dalla_competenza'])->toMatchArray(['data' => '2027-06-30', 'prima_della_scadenza' => true])
        ->and($r['piu_vicina'])->toBe('2028-06-30');

    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['data_delibera_assemblea' => null]);
    $r = smDate($s, '2027-10-01')[12];
    expect($r['dalla_competenza'])->toMatchArray(['data' => null, 'stato' => 'senza_delibera'])
        ->and($r['piu_vicina'])->toBe('2028-06-30');
});

it('la dispensa sull\'esercizio 2026 per tutti: accanto alle date del 2026, non alle altre; revocata, la cella lo dice; le date non cambiano mai', function () {
    $s = smPiano($this);
    smEsercizio($s, 'Esercizio 2027', '2027-01-01', '2027-12-31', 'aperto');
    $this->travelTo('2027-07-10 10:00:00');
    $prima = smDate($s, '2027-07-10');

    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.store', ['condominio' => $s['c']->id]), [
        'data_delibera' => '2027-03-20', 'numero_verbale' => '12', 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '1', 'nota' => 'Dispensa per i crediti del 2026',
    ])->assertSessionHasNoErrors();
    $dispensa = DB::table('dispense_sei_mesi')->where('condominio_id', $s['c']->id)->first();
    expect($dispensa)->not->toBeNull()->and((int) $dispensa->registrata_da)->toBe((int) $this->user->id);

    $dopo = smDate($s, '2027-07-10');
    expect($dopo[7]['dalla_competenza']['dispensa'])->toMatchArray(['data_delibera' => '2027-03-20', 'numero_verbale' => '12', 'revocata_il' => null])
        ->and($dopo[7]['dalla_scadenza']['dispensa'])->toBeNull()
        // La più vicina fra le date non coperte: il 30/06/2028 dalla scadenza.
        ->and($dopo[7]['piu_vicina'])->toBe('2028-06-30')
        // La rata 1 ha le due date dal 2026, tutte e due coperte: la più vicina resta il 30/06/2027, con la dispensa.
        ->and($dopo[1]['piu_vicina'])->toBe('2027-06-30')
        ->and($dopo[1]['piu_vicina_dispensa'])->toMatchArray(['numero_verbale' => '12']);
    foreach ([1, 7, 12] as $n) {
        expect($dopo[$n]['dalla_scadenza']['data'])->toBe($prima[$n]['dalla_scadenza']['data'])
            ->and($dopo[$n]['dalla_competenza']['data'])->toBe($prima[$n]['dalla_competenza']['data']);
    }

    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.revoca', ['condominio' => $s['c']->id, 'dispensa' => $dispensa->id]), [
        'revocata_il' => '2027-06-01', 'nota_revoca' => 'Assemblea del 01/06',
    ])->assertSessionHasNoErrors();
    $revocata = smDate($s, '2027-07-10');
    expect($revocata[1]['dalla_competenza']['dispensa'])->toMatchArray(['revocata_il' => '2027-06-01'])
        ->and($revocata[1]['piu_vicina_dispensa'])->toBeNull()
        ->and($revocata[1]['dalla_competenza']['data'])->toBe($prima[1]['dalla_competenza']['data']);
});

it('la dispensa per alcune persone copre solo loro', function () {
    $this->travelTo('2026-06-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata', [], 120000, '2026-07-05', 5);
    lcdEmetti($this, $s, [1], '2026-07-01');
    $this->travelTo('2026-08-10 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.store', ['condominio' => $s['c']->id]), [
        'data_delibera' => '2026-08-01', 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '0', 'persone' => [(int) $s['persone']['ugo']->id],
    ])->assertSessionHasNoErrors();

    $calcolo = app(SeiMesi::class)->perCondominio((int) $s['c']->id);
    $ugo = $calcolo->perRiga(lcdRiga(lcdLeggi($s, 'ugo'), $s, 'A', 1), (int) $s['persone']['ugo']->id);
    $bice = $calcolo->perRiga(lcdRiga(lcdLeggi($s, 'bice'), $s, 'B', 1), (int) $s['persone']['bice']->id);
    expect($ugo['dalla_scadenza']['dispensa'])->not->toBeNull()
        ->and($bice['dalla_scadenza']['dispensa'])->toBeNull();
});

it('la dispensa si rifiuta senza la data, senza esercizi, con le persone vuote, e con un esercizio o una persona di un altro condominio (404)', function () {
    $s = smPiano($this);
    $altro = lcdScenario(['A' => ['zeno', 1000]], 1, 'prima_rata');
    $rotta = route('admin.gestionale.dispense.store', ['condominio' => $s['c']->id]);
    $this->travelTo('2027-07-10 10:00:00');
    // Una delibera dopo oggi si rifiuta.
    $this->actingAs($this->user)->post($rotta, ['data_delibera' => '2027-07-11', 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '1'])->assertSessionHasErrors('data_delibera');

    $this->actingAs($this->user)->post($rotta, ['esercizi' => [(int) $s['e']->id], 'per_tutti' => '1'])->assertSessionHasErrors('data_delibera');
    $this->actingAs($this->user)->post($rotta, ['data_delibera' => '2027-03-20', 'per_tutti' => '1'])->assertSessionHasErrors('esercizi');
    $this->actingAs($this->user)->post($rotta, ['data_delibera' => '2027-03-20', 'esercizi' => [(int) $s['e']->id]])->assertSessionHasErrors('per_tutti');
    $this->actingAs($this->user)->post($rotta, ['data_delibera' => '2027-03-20', 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '0', 'persone' => []])->assertSessionHasErrors('persone');
    $this->actingAs($this->user)->post($rotta, ['data_delibera' => '2027-03-20', 'esercizi' => [(int) $altro['e']->id], 'per_tutti' => '1'])->assertNotFound();
    $this->actingAs($this->user)->post($rotta, ['data_delibera' => '2027-03-20', 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '0', 'persone' => [(int) $altro['persone']['zeno']->id]])->assertNotFound();
    expect(DB::table('dispense_sei_mesi')->count())->toBe(0);

    // La revoca di una dispensa di un altro condominio: 404.
    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.store', ['condominio' => $altro['c']->id]), [
        'data_delibera' => '2026-03-20', 'esercizi' => [(int) $altro['e']->id], 'per_tutti' => '1',
    ])->assertSessionHasNoErrors();
    $suaId = (int) DB::table('dispense_sei_mesi')->where('condominio_id', $altro['c']->id)->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.revoca', ['condominio' => $s['c']->id, 'dispensa' => $suaId]), ['revocata_il' => '2026-05-01'])->assertNotFound();
    expect(DB::table('dispense_sei_mesi')->where('id', $suaId)->value('revocata_il'))->toBeNull();
});

it('le dispense guardano la data dell\'elenco: una delibera presa dopo quella data non c\'è ancora, e una revoca successiva non vale ancora', function () {
    $this->travelTo('2026-06-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [], 120000, '2026-07-05', 5);
    lcdEmetti($this, $s, [1], '2026-07-01');
    $this->travelTo('2027-04-10 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.store', ['condominio' => $s['c']->id]), [
        'data_delibera' => '2027-03-20', 'numero_verbale' => '12', 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '1',
    ])->assertSessionHasNoErrors();
    $alla = function (string $d) use ($s) {
        $p = $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id, 'alla' => $d]))->assertOk()->viewData('page')['props'];

        return $p['righe']['data'][0];
    };

    // Al 31/12/2026, per l'assemblea, la delibera del 20/03/2027 non c'era: nessuna dispensa accanto al 30/06/2027.
    $fine2026 = $alla('2026-12-31');
    expect($fine2026['sei_mesi'])->toBe(['data' => '2027-06-30', 'dispensa' => null, 'mancano' => 0])
        ->and($fine2026['rate'][0]['sei_mesi']['dalla_scadenza']['dispensa'])->toBeNull();
    expect($alla('2027-04-10')['sei_mesi']['dispensa'])->toMatchArray(['data_delibera' => '2027-03-20', 'revocata_il' => null]);

    // Revocata il 01/04/2027: al 25/03 era in vigore, oggi la cella dice la revoca.
    $id = DB::table('dispense_sei_mesi')->where('condominio_id', $s['c']->id)->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.revoca', ['condominio' => $s['c']->id, 'dispensa' => $id]), [
        'revocata_il' => '2027-04-01', 'nota_revoca' => 'Assemblea del 01/04',
    ])->assertSessionHasNoErrors();
    $marzo = $alla('2027-03-25');
    expect($marzo['sei_mesi']['dispensa'])->toMatchArray(['data_delibera' => '2027-03-20', 'revocata_il' => null])
        ->and($marzo['rate'][0]['sei_mesi']['dalla_scadenza']['dispensa']['revocata_il'])->toBeNull();
    $oggi = $alla('2027-04-10');
    expect($oggi['sei_mesi']['dispensa'])->toBeNull()
        ->and($oggi['rate'][0]['sei_mesi']['dalla_scadenza']['dispensa']['revocata_il'])->toBe('2027-04-01');
});

it('una data prima del primo esercizio del programma non «c\'è ancora»: la cella dice che viene prima, e non manda a creare un esercizio passato', function () {
    $s = smPiano($this);
    $this->travelTo('2027-07-10 10:00:00');
    $calcolo = app(SeiMesi::class)->perCondominio((int) $s['c']->id);
    // Una rata storica importata, con la scadenza del 05/01/2019; l'esercizio più vecchio del condominio comincia il 01/01/2026.
    $storica = ['scadenza' => '2019-01-05', 'piano_rate_id' => (int) $s['piano']->id, 'pregresso' => null];
    $date = $calcolo->perRiga($storica, (int) $s['persone']['ugo']->id);

    expect($date['dalla_scadenza'])->toMatchArray(['data' => null, 'stato' => 'prima_degli_esercizi', 'data_cercata' => '2019-01-05', 'esercizio_da_chiudere' => null])
        ->and($date['dalla_competenza'])->toMatchArray(['data' => '2027-06-30', 'stato' => 'ok'])
        ->and($date['piu_vicina'])->toBe('2027-06-30');

    // Dopo l'ultimo esercizio resta «non c'è ancora».
    expect($calcolo->perRiga(['scadenza' => '2027-01-05'] + $storica, (int) $s['persone']['ugo']->id)['dalla_scadenza']['stato'])->toBe('manca_esercizio');
});

it('alle 00:30 del 21/03/2027 a Roma (le 23:30 del 20/03 in UTC) la dispensa deliberata il 21/03 c\'è nell\'elenco di oggi: il giorno è quello dell\'amministratore', function () {
    $this->travelTo('2026-06-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [], 120000, '2026-07-05', 5);
    lcdEmetti($this, $s, [1], '2026-07-01');
    $this->travelTo(\Carbon\Carbon::parse('2027-03-21 00:30:00', 'Europe/Rome'));
    expect(now()->toDateString())->toBe('2027-03-20');

    $this->actingAs($this->user)->post(route('admin.gestionale.dispense.store', ['condominio' => $s['c']->id]), [
        'data_delibera' => '2027-03-21', 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '1',
    ])->assertSessionHasNoErrors();
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->assertOk()->viewData('page')['props'];

    expect($p['filtri']['alla'])->toBe('2027-03-21')
        ->and($p['righe']['data'][0]['sei_mesi']['dispensa'])->toMatchArray(['data_delibera' => '2027-03-21', 'revocata_il' => null]);
});

it('un numero di pagina enorme scritto a mano nell\'indirizzo è la pagina vuota dopo l\'ultima, come una pagina che non c\'è più, non un errore del server', function () {
    $this->withoutVite();
    $s = smPiano($this);
    $this->travelTo('2027-02-10 10:00:00');
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id, 'page' => '9223372036854775807']))->assertOk()->viewData('page')['props'];

    expect($p['righe']['meta']['current_page'])->toBe($p['righe']['meta']['last_page'] + 1)->and($p['righe']['data'])->toBe([])->and($p['righe']['meta']['total'])->toBeGreaterThan(0);
});
