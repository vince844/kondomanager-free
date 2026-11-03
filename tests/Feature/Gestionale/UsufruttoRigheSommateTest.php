<?php

/**
 * 1.11.0-beta.42, decisione 36 — costituzione ed estinzione dell'usufrutto sommano le righe dello stesso ruolo, come la vendita.
 *
 * Quando chi riceve un ruolo ha già un'altra riga di quel ruolo sull'unità — la nuda proprietaria che è già proprietaria piena
 * dell'altra metà e torna piena con l'estinzione, o che è già nuda di una metà e costituisce l'usufrutto sull'altra — la
 * registrazione rifiutava per sovrapposizione dopo un'anteprima che aveva accettato. Ora la sua riga si chiude il giorno prima
 * e se ne apre una alla quota somma (`apriSommando`, decisione 28.6), e l'annullamento disfa la somma.
 *
 * Anche il nudo proprietario nato lo stesso giorno dell'estinzione, che ha anche una riga piena (rilievo R9 della Fase 1-bis):
 * somma se la riga piena è di prima, e si rifiuta se è nata anch'essa quel giorno. E la parte tornata piena che il conguaglio
 * legge è quella della nuda proprietà, non la somma.
 *
 * Cosa NON copre: l'estinzione di uno fra due usufrutti sulla stessa unità (si ricongiungono anche i nudi dell'altro, beta della
 * genealogia). Chi riceve l'usufrutto non può già essere titolare dell'unità (`AnteprimaPassaggioRequest`): lì anteprima e
 * registrazione rifiutano insieme.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Un'unità senza piani e le persone, con le righe date: [nome, ruolo, quota, dal]. */
function urScenario(array $righe): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "UR-{$seq}", 'nome' => 'Interno A', 'interno' => 'A']);
    $p = [];
    foreach (['rita', 'nora', 'bice', 'elsa'] as $i => $nome) {
        $p[$nome] = Anagrafica::forceCreate(['nome' => ucfirst($nome), 'email' => "ur-{$nome}{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => strtoupper("UR{$nome}") . str_pad((string) ($seq * 10 + $i), 8, '0', STR_PAD_LEFT)]);
        $p[$nome]->condomini()->syncWithoutDetaching([$c->id]);
    }
    foreach ($righe as [$nome, $ruolo, $quota, $dal]) {
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p[$nome]->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true, 'data_inizio' => $dal, 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    return compact('c', 'unita') + ['p' => $p];
}

function urRiga(array $s, string $nome, string $ruolo): int
{
    return (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['p'][$nome]->id)
        ->where('tipologia', $ruolo)->whereNull('data_fine')->value('id');
}

function urRegistra($test, array $s, array $dati)
{
    $dati += ['copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Atto letto, righe controllate'];
    $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati)->assertOk();

    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati);
}

/** Le righe dell'unità, leggibili: [nome, ruolo, quota, dal, al]. */
function urRighe(array $s): array
{
    $nomi = collect($s['p'])->mapWithKeys(fn ($a, $n) => [$a->id => $n])->all();

    return DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->orderBy('id')->get()
        ->map(fn ($r) => [$nomi[$r->anagrafica_id], $r->tipologia, round((float) $r->quota, 2), substr((string) $r->data_inizio, 0, 10), $r->data_fine === null ? null : substr((string) $r->data_fine, 0, 10)])->all();
}

it('D4 — l\'estinzione dell\'usufrutto di Bice riunisce la metà di Nora, che è già proprietaria piena dell\'altra metà: Nora proprietaria al 100 % dal giorno dopo, e l\'annullamento torna alle righe di prima', function () {
    $s = urScenario([['nora', 'nuda_proprietario', 50, '2026-03-01'], ['bice', 'usufruttuario', 50, '2026-03-01'], ['nora', 'proprietario', 50, '2026-04-01']]);
    $prima = urRighe($s);

    urRegistra($this, $s, ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => urRiga($s, 'bice', 'usufruttuario'), 'decorrenza' => '2026-05-01', 'quota' => 50, 'tipologia' => 'proprietario'])
        ->assertSessionHasNoErrors()->assertRedirect();
    // Prima: «questa persona è già proprietario di questa unità dal 1 aprile 2026, in corso: i due periodi si sovrappongono».
    expect(urRighe($s))->toBe([
        ['nora', 'nuda_proprietario', 50.0, '2026-03-01', '2026-04-30'],
        ['bice', 'usufruttuario', 50.0, '2026-03-01', '2026-04-30'],
        ['nora', 'proprietario', 50.0, '2026-04-01', '2026-04-30'],
        ['nora', 'proprietario', 100.0, '2026-05-01', null],
    ]);

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], Subentro::latest('id')->firstOrFail()]), ['nota_annullamento' => 'Registrata con la data sbagliata, la rifaccio'])->assertRedirect();
    expect(urRighe($s))->toBe($prima);
});

it('D4, seconda forma — Nora, già nuda proprietaria di una metà, costituisce a Elsa l\'usufrutto dell\'altra: Nora nuda proprietaria al 100 %, Elsa usufruttuaria della sua metà', function () {
    $s = urScenario([['rita', 'usufruttuario', 50, '2019-01-01'], ['nora', 'nuda_proprietario', 50, '2019-01-01'], ['nora', 'proprietario', 50, '2026-03-15']]);

    urRegistra($this, $s, ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => urRiga($s, 'nora', 'proprietario'),
        'anagrafica_entrante_id' => $s['p']['elsa']->id, 'decorrenza' => '2026-04-16', 'quota' => 50, 'tipologia' => 'usufruttuario'])
        ->assertSessionHasNoErrors()->assertRedirect();
    // Prima: «questa persona è già nudo proprietario di questa unità dal 1 gennaio 2019, in corso: i due periodi si sovrappongono».
    expect(urRighe($s))->toBe([
        ['rita', 'usufruttuario', 50.0, '2019-01-01', null],
        ['nora', 'nuda_proprietario', 50.0, '2019-01-01', '2026-04-15'],
        ['nora', 'proprietario', 50.0, '2026-03-15', '2026-04-15'],
        ['nora', 'nuda_proprietario', 100.0, '2026-04-16', null],
        ['elsa', 'usufruttuario', 50.0, '2026-04-16', null],
    ]);
});

it('R9 — vendita della nuda a Nora, già proprietaria piena dell\'altra metà, ed estinzione dell\'usufrutto lo stesso giorno: una riga piena al 100 %, non due sovrapposte; l\'annullamento torna indietro', function () {
    $s = urScenario([['rita', 'nuda_proprietario', 50, '2019-01-01'], ['bice', 'usufruttuario', 50, '2019-01-01'], ['nora', 'proprietario', 50, '2019-01-01']]);
    urRegistra($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => urRiga($s, 'rita', 'nuda_proprietario'), 'anagrafica_entrante_id' => $s['p']['nora']->id,
        'decorrenza' => '2026-05-01', 'quota' => 50, 'tipologia' => 'nuda_proprietario'])->assertSessionHasNoErrors()->assertRedirect();
    $prima = urRighe($s);

    urRegistra($this, $s, ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => urRiga($s, 'bice', 'usufruttuario'), 'decorrenza' => '2026-05-01', 'quota' => 50, 'tipologia' => 'proprietario'])
        ->assertSessionHasNoErrors()->assertRedirect();
    // Prima: Nora con due righe «proprietario» aperte, 50 dal 2019 e 50 dal 1/5 (invariante 11 violata).
    expect(urRighe($s))->toBe([
        ['rita', 'nuda_proprietario', 50.0, '2019-01-01', '2026-04-30'],
        ['bice', 'usufruttuario', 50.0, '2019-01-01', '2026-04-30'],
        ['nora', 'proprietario', 50.0, '2019-01-01', '2026-04-30'],
        ['nora', 'proprietario', 100.0, '2026-05-01', null],
    ]);

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], Subentro::latest('id')->firstOrFail()]), ['nota_annullamento' => 'Registrata con la data sbagliata, la rifaccio'])->assertRedirect();
    expect(urRighe($s))->toBe($prima);
});

/** L'estinzione dell'usufrutto di Bice al 1/5. */
function urEstinzione(array $s): array
{
    return ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => urRiga($s, 'bice', 'usufruttuario'), 'decorrenza' => '2026-05-01', 'quota' => 50, 'tipologia' => 'proprietario',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Atto letto, righe controllate'];
}

function urVendeANora($test, array $s, string $chi, string $ruolo)
{
    return urRegistra($test, $s, ['tipo' => 'vendita', 'riga_uscente_id' => urRiga($s, $chi, $ruolo), 'anagrafica_entrante_id' => $s['p']['nora']->id,
        'decorrenza' => '2026-05-01', 'quota' => 50, 'tipologia' => $ruolo])->assertSessionHasNoErrors()->assertRedirect();
}

function urAnnullaUltimo($test, array $s): void
{
    $test->actingAs($test->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], Subentro::latest('id')->firstOrFail()]), ['nota_annullamento' => 'Lo rifaccio nell\'ordine giusto'])->assertRedirect();
}

/** Il rifiuto dell'estinzione, uguale nell'anteprima e nella registrazione, e le righe che non cambiano. */
function urRifiutoEstinzione($test, array $s): string
{
    $prima = urRighe($s);
    $frase = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), urEstinzione($s))->assertUnprocessable()->json('errors.decorrenza.0');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), urEstinzione($s))->assertSessionHasErrors(['decorrenza' => $frase]);
    expect(urRighe($s))->toBe($prima);

    return $frase;
}

const UR_TESTA_R9 = 'Nora è nudo proprietario e proprietario pieno di questa unità da due righe nate lo stesso giorno, il 1 maggio 2026, e l\'estinzione non le può riunire.';
const UR_SOLO_NORA = [['nora', 'proprietario', 100.0, '2026-05-01', null]];

it('R9, l\'ordine (rilievo V8) — prima la piena, poi la nuda: l\'estinzione si rifiuta e dice di annullarle tutte e due e rifarle con la nuda prima e la piena dopo; seguita fino in fondo, Nora è proprietaria al 100 % dal 1/5', function () {
    $s = urScenario([['rita', 'nuda_proprietario', 50, '2019-01-01'], ['bice', 'usufruttuario', 50, '2019-01-01'], ['elsa', 'proprietario', 50, '2019-01-01']]);
    urVendeANora($this, $s, 'elsa', 'proprietario');
    urVendeANora($this, $s, 'rita', 'nuda_proprietario');

    expect(urRifiutoEstinzione($this, $s))->toBe(UR_TESTA_R9 . ' Annulla dallo storico dell\'unità, l\'ultimo per primo, la vendita della nuda proprietà da Rita a Nora e la vendita da Elsa a Nora; poi registra di nuovo la vendita della nuda proprietà da Rita a Nora, questa estinzione e infine la vendita da Elsa a Nora.');

    urAnnullaUltimo($this, $s);
    urAnnullaUltimo($this, $s);
    urVendeANora($this, $s, 'rita', 'nuda_proprietario');
    urRegistra($this, $s, urEstinzione($s))->assertSessionHasNoErrors()->assertRedirect();
    urVendeANora($this, $s, 'elsa', 'proprietario');
    expect(collect(urRighe($s))->where(4, null)->values()->all())->toBe(UR_SOLO_NORA);
});

it('R9, l\'altro ordine — prima la nuda, poi la piena: basta annullare la piena, registrare l\'estinzione e di nuovo la piena; e con la piena associata a mano la frase dice il fatto, senza un passaggio da annullare che non c\'è', function () {
    $s = urScenario([['rita', 'nuda_proprietario', 50, '2019-01-01'], ['bice', 'usufruttuario', 50, '2019-01-01'], ['elsa', 'proprietario', 50, '2019-01-01']]);
    urVendeANora($this, $s, 'rita', 'nuda_proprietario');
    urVendeANora($this, $s, 'elsa', 'proprietario');

    expect(urRifiutoEstinzione($this, $s))->toBe(UR_TESTA_R9 . ' Annulla dallo storico dell\'unità la vendita da Elsa a Nora, registra questa estinzione e poi di nuovo quel passaggio.');

    urAnnullaUltimo($this, $s);
    urRegistra($this, $s, urEstinzione($s))->assertSessionHasNoErrors()->assertRedirect();
    urVendeANora($this, $s, 'elsa', 'proprietario');
    expect(collect(urRighe($s))->where(4, null)->values()->all())->toBe(UR_SOLO_NORA);

    // La piena associata a mano il 1/5 (per esempio la via a mano della decisione 37), la nuda da un passaggio.
    $m = urScenario([['rita', 'nuda_proprietario', 50, '2019-01-01'], ['bice', 'usufruttuario', 50, '2019-01-01'], ['nora', 'proprietario', 50, '2026-05-01']]);
    urVendeANora($this, $m, 'rita', 'nuda_proprietario');
    expect(urRifiutoEstinzione($this, $m))->toBe(UR_TESTA_R9 . ' La riga piena di Nora dal 1 maggio 2026 è stata associata a mano: va corretta a mano da «Modifica associazione» prima dell\'estinzione.');
});

it('decisione 36 — con più nudi la parte tornata piena è quella della nuda proprietà, non la somma con la piena che il nudo aveva già', function () {
    // Bice usufruttuaria della metà di cui Nora e Rita sono nude al 25 % ciascuna; Nora è anche piena dell'altra metà.
    $s = urScenario([['bice', 'usufruttuario', 50, '2019-01-01'], ['nora', 'nuda_proprietario', 25, '2019-01-01'], ['rita', 'nuda_proprietario', 25, '2019-01-01'], ['nora', 'proprietario', 50, '2019-01-01']]);
    urRegistra($this, $s, ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => urRiga($s, 'bice', 'usufruttuario'), 'decorrenza' => '2026-05-01', 'quota' => 50, 'tipologia' => 'proprietario'])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->whereNull('data_fine')->where('anagrafica_id', $s['p']['nora']->id)->value('quota'))->toEqual(75);

    $nudi = (new ReflectionMethod(\App\Services\Subentro\ConguaglioPassaggio::class, 'nudiDellEstinzione'))->invoke(app(\App\Services\Subentro\ConguaglioPassaggio::class), Subentro::latest('id')->firstOrFail());
    // Prima: Nora 75 (la riga riaperta con la somma) e Rita 25, cioè 3/4 dell'usufrutto a Nora invece di metà.
    expect($nudi)->toEqualCanonicalizing([$s['p']['nora']->id => 25.0, $s['p']['rita']->id => 25.0]);
});

/** Una vendita a Nora dal 1/5, della quota data. */
function urVende($test, array $s, string $chi, string $ruolo, float $quota)
{
    return urRegistra($test, $s, ['tipo' => 'vendita', 'riga_uscente_id' => urRiga($s, $chi, $ruolo), 'anagrafica_entrante_id' => $s['p']['nora']->id,
        'decorrenza' => '2026-05-01', 'quota' => $quota, 'tipologia' => $ruolo])->assertSessionHasNoErrors()->assertRedirect();
}

/** Annulla dallo storico i `$quanti` passaggi più recenti, l'ultimo per primo. */
function urAnnullaFinoA($test, array $s, int $quanti): void
{
    for ($i = 0; $i < $quanti; $i++) {
        urAnnullaUltimo($test, $s);
    }
}

it('rilievo W3 — due nudi venduti a Nora lo stesso giorno, la seconda nuda sommata sulla prima: la frase annulla tutti i passaggi dalla prima vendita della piena e rifà le due nude prima dell\'estinzione; seguita, Nora è proprietaria al 100 %', function () {
    $s = urScenario([['rita', 'nuda_proprietario', 25, '2019-01-01'], ['bice', 'usufruttuario', 50, '2019-01-01'], ['elsa', 'proprietario', 50, '2019-01-01']]);
    $gino = Anagrafica::forceCreate(['nome' => 'Gino', 'email' => 'ur-gino' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'URGINO' . str_pad((string) $s['unita']->id, 10, '0', STR_PAD_LEFT)]);
    $gino->condomini()->syncWithoutDetaching([$s['c']->id]);
    $s['p']['gino'] = $gino;
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $gino->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'nuda_proprietario', 'quota' => 25, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    urVende($this, $s, 'elsa', 'proprietario', 50);
    urVende($this, $s, 'rita', 'nuda_proprietario', 25);
    urVende($this, $s, 'gino', 'nuda_proprietario', 25);

    // Prima: la frase partiva dall'ultimo passaggio sulla riga nuda (Gino), e seguita dava Nora 75 e Rita proprietaria piena 25.
    expect(urRifiutoEstinzione($this, $s))->toBe(UR_TESTA_R9 . ' Annulla dallo storico dell\'unità, l\'ultimo per primo, la vendita della nuda proprietà da Gino a Nora, la vendita della nuda proprietà da Rita a Nora e la vendita da Elsa a Nora; poi registra di nuovo la vendita della nuda proprietà da Rita a Nora e la vendita della nuda proprietà da Gino a Nora, questa estinzione e infine la vendita da Elsa a Nora.');

    urAnnullaFinoA($this, $s, 3);
    urVende($this, $s, 'rita', 'nuda_proprietario', 25);
    urVende($this, $s, 'gino', 'nuda_proprietario', 25);
    urRegistra($this, $s, urEstinzione($s))->assertSessionHasNoErrors()->assertRedirect();
    urVende($this, $s, 'elsa', 'proprietario', 50);
    expect(collect(urRighe($s))->where(4, null)->values()->all())->toBe(UR_SOLO_NORA);
});

it('rilievo W4 — lo stesso venditore, Rita, vende a Nora la piena e la nuda lo stesso giorno: la frase le distingue («della nuda proprietà»), e seguita porta Nora al 100 %', function () {
    $s = urScenario([['rita', 'nuda_proprietario', 50, '2019-01-01'], ['bice', 'usufruttuario', 50, '2019-01-01'], ['rita', 'proprietario', 50, '2019-01-01']]);
    urVende($this, $s, 'rita', 'proprietario', 50);
    urVende($this, $s, 'rita', 'nuda_proprietario', 50);

    // Prima: «la vendita da Rita a Nora e poi la vendita da Rita a Nora», e rifatta per prima la piena l'estinzione dava Rita 50.
    expect(urRifiutoEstinzione($this, $s))->toBe(UR_TESTA_R9 . ' Annulla dallo storico dell\'unità, l\'ultimo per primo, la vendita della nuda proprietà da Rita a Nora e la vendita da Rita a Nora; poi registra di nuovo la vendita della nuda proprietà da Rita a Nora, questa estinzione e infine la vendita da Rita a Nora.');

    urAnnullaFinoA($this, $s, 2);
    urVende($this, $s, 'rita', 'nuda_proprietario', 50);
    urRegistra($this, $s, urEstinzione($s))->assertSessionHasNoErrors()->assertRedirect();
    urVende($this, $s, 'rita', 'proprietario', 50);
    expect(collect(urRighe($s))->where(4, null)->values()->all())->toBe(UR_SOLO_NORA);
});

it('rilievo W3, la data dopo — un inizio locazione registrato con una data successiva: la frase lo comprende, nell\'ordine della guardia dell\'annullamento, e lo rifà per ultimo', function () {
    $s = urScenario([['rita', 'nuda_proprietario', 50, '2019-01-01'], ['bice', 'usufruttuario', 50, '2019-01-01'], ['elsa', 'proprietario', 50, '2019-01-01']]);
    urVende($this, $s, 'elsa', 'proprietario', 50);
    urVende($this, $s, 'rita', 'nuda_proprietario', 50);
    $locazione = ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $s['p']['elsa']->id, 'decorrenza' => '2026-06-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'data_fine_locazione' => '2030-05-31', 'regime_contratto' => 'abitativo'];
    urRegistra($this, $s, $locazione)->assertSessionHasNoErrors()->assertRedirect();

    expect(urRifiutoEstinzione($this, $s))->toBe(UR_TESTA_R9 . ' Annulla dallo storico dell\'unità, l\'ultimo per primo, l\'inizio locazione a Elsa, la vendita della nuda proprietà da Rita a Nora e la vendita da Elsa a Nora; poi registra di nuovo la vendita della nuda proprietà da Rita a Nora, questa estinzione e infine la vendita da Elsa a Nora e l\'inizio locazione a Elsa.');

    urAnnullaFinoA($this, $s, 3);
    urVende($this, $s, 'rita', 'nuda_proprietario', 50);
    urRegistra($this, $s, urEstinzione($s))->assertSessionHasNoErrors()->assertRedirect();
    urVende($this, $s, 'elsa', 'proprietario', 50);
    urRegistra($this, $s, $locazione)->assertSessionHasNoErrors()->assertRedirect();
    expect(collect(urRighe($s))->where(4, null)->where(1, 'proprietario')->values()->all())->toBe(UR_SOLO_NORA);
});

/*
| Decisioni 52 e 53 (terzo giro della .42): fuori dal caso semplice la frase rimanda alla correzione a mano, e due guardie sotto.
*/

const UR_A_MANO = ': per questo caso il programma non indica una strada. Correggi le righe a mano da «Modifica associazione», o chiedi assistenza.';

/** Un box pertinenza dell'unità, con gli stessi titolari. */
function urBox(array $s, array $righe): Immobile
{
    $box = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'box', 'codice_immobile' => 'UR-BOX-' . $s['unita']->id, 'nome' => 'Box 12', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    foreach ($righe as [$nome, $ruolo, $quota, $dal]) {
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['p'][$nome]->id, 'immobile_id' => $box->id, 'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true, 'data_inizio' => $dal, 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $box;
}

it('decisione 52 (rilievo X2) — una vendita della nuda a Nora datata dopo l\'estinzione: niente ricetta, la frase rimanda alla correzione a mano; e la guardia della 53 dice che la riga di Gino è già chiusa da un passaggio dopo', function () {
    $s = urScenario([['rita', 'nuda_proprietario', 25, '2019-01-01'], ['bice', 'usufruttuario', 50, '2019-01-01'], ['elsa', 'proprietario', 50, '2019-01-01']]);
    $gino = Anagrafica::forceCreate(['nome' => 'Gino', 'email' => 'ur-ginox2' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'URGINOX2' . str_pad((string) $s['unita']->id, 8, '0', STR_PAD_LEFT)]);
    $gino->condomini()->syncWithoutDetaching([$s['c']->id]);
    $s['p']['gino'] = $gino;
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $gino->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'nuda_proprietario', 'quota' => 25, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    urVende($this, $s, 'elsa', 'proprietario', 50);
    urVende($this, $s, 'rita', 'nuda_proprietario', 25);
    urRegistra($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => urRiga($s, 'gino', 'nuda_proprietario'), 'anagrafica_entrante_id' => $s['p']['nora']->id,
        'decorrenza' => '2026-06-01', 'quota' => 25, 'tipologia' => 'nuda_proprietario'])->assertSessionHasNoErrors()->assertRedirect();
    $prima = urRighe($s);

    $errori = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), urEstinzione($s))->assertUnprocessable()->json('errors.decorrenza');
    // Prima: «registra di nuovo … la vendita della nuda proprietà da Gino a Nora, questa estinzione…», e seguita lasciava Gino
    // proprietario pieno al 25 % dal 1/5.
    expect($errori[0])->toBe(UR_TESTA_R9 . ' Fra i passaggi da rifare c\'è la vendita della nuda proprietà da Gino a Nora, dal 1 giugno 2026, dopo questa estinzione' . UR_A_MANO)
        ->and($errori[1])->toStartWith('Gino era nudo proprietario di questa unità il 1 maggio 2026, e un passaggio registrato dopo ha chiuso quella riga dal 1 giugno 2026');
    expect(urRighe($s))->toBe($prima);
});

it('decisione 52 (rilievo X4) — fra i passaggi da rifare c\'è una costituzione in cui esce il nudo: la frase rimanda alla correzione a mano', function () {
    $s = urScenario([['rita', 'nuda_proprietario', 50, '2019-01-01'], ['bice', 'usufruttuario', 50, '2019-01-01'], ['elsa', 'proprietario', 50, '2019-01-01']]);
    $gino = Anagrafica::forceCreate(['nome' => 'Gino', 'email' => 'ur-ginox4' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'URGINOX4' . str_pad((string) $s['unita']->id, 8, '0', STR_PAD_LEFT)]);
    $gino->condomini()->syncWithoutDetaching([$s['c']->id]);
    $s['p']['gino'] = $gino;
    urVende($this, $s, 'elsa', 'proprietario', 50);
    urVende($this, $s, 'rita', 'nuda_proprietario', 50);
    urRegistra($this, $s, ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => urRiga($s, 'nora', 'proprietario'),
        'anagrafica_entrante_id' => $gino->id, 'decorrenza' => '2026-07-01', 'quota' => 50, 'tipologia' => 'usufruttuario'])->assertSessionHasNoErrors()->assertRedirect();

    // Prima: la frase faceva rifare per ultima la costituzione a Gino, che dopo l'estinzione si rifiutava (decisione 37).
    expect(urRifiutoEstinzione($this, $s))->toBe(UR_TESTA_R9 . ' Fra i passaggi da rifare c\'è la costituzione dell\'usufrutto a favore di Gino, dal 1 luglio 2026, in cui esce Nora' . UR_A_MANO);
});

it('decisione 53 (rilievo X6) — il controllo vale anche sul box spuntato: con la nuda e la piena di Nora nate lo stesso giorno anche sul box, l\'estinzione si rifiuta, con il nome del box', function () {
    $righe = [['rita', 'nuda_proprietario', 50, '2019-01-01'], ['bice', 'usufruttuario', 50, '2019-01-01'], ['elsa', 'proprietario', 50, '2019-01-01']];
    $s = urScenario($righe);
    $box = urBox($s, $righe);
    // La piena di Elsa a Nora dall'Interno A con il box; la nuda di Rita a Nora registrata dal box.
    urRegistra($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => urRiga($s, 'elsa', 'proprietario'), 'anagrafica_entrante_id' => $s['p']['nora']->id,
        'decorrenza' => '2026-05-01', 'quota' => 50, 'tipologia' => 'proprietario', 'pertinenze' => [$box->id]])->assertSessionHasNoErrors()->assertRedirect();
    $sb = ['unita' => $box] + $s;
    urRegistra($this, $sb, ['tipo' => 'vendita', 'riga_uscente_id' => urRiga($sb, 'rita', 'nuda_proprietario'), 'anagrafica_entrante_id' => $s['p']['nora']->id,
        'decorrenza' => '2026-05-01', 'quota' => 50, 'tipologia' => 'nuda_proprietario'])->assertSessionHasNoErrors()->assertRedirect();

    $dati = urEstinzione($s) + ['pertinenze' => [$box->id]];
    $dati['pertinenze'] = [$box->id];
    // Prima: anteprima 200 e registrazione accettata, e sul box due righe «Nora proprietario 50» aperte, sovrapposte.
    $errore = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati)->assertUnprocessable()->json('errors.decorrenza.0');
    expect($errore)->toStartWith('Box 12: Nora è nudo proprietario e proprietario pieno di questa unità da due righe nate lo stesso giorno, il 1 maggio 2026')
        ->toContain('Fra i passaggi da rifare ce n\'è uno registrato insieme a una pertinenza: ')->toContain(UR_A_MANO);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati)->assertSessionHasErrors('decorrenza');
    expect(DB::table('anagrafica_immobile')->where('immobile_id', $box->id)->where('anagrafica_id', $s['p']['nora']->id)->where('tipologia', 'proprietario')->whereNull('data_fine')->count())->toBe(1);

    // Rilievo X5: dal box, la frase non dice più «associata a mano» per una riga scritta da un passaggio.
    $daBox = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $box]), urEstinzione($sb))->assertUnprocessable()->json('errors.decorrenza.0');
    expect($daBox)->not->toContain('associata a mano')->toContain(UR_A_MANO);
});

it('decisione 53 (2) — un\'estinzione con una data che precede la vendita della nuda già registrata si rifiuta: chi ha venduto non resta proprietario pieno in silenzio', function () {
    $s = urScenario([['rita', 'nuda_proprietario', 100, '2019-01-01'], ['bice', 'usufruttuario', 100, '2019-01-01']]);
    urRegistra($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => urRiga($s, 'rita', 'nuda_proprietario'), 'anagrafica_entrante_id' => $s['p']['nora']->id,
        'decorrenza' => '2026-06-01', 'quota' => 100, 'tipologia' => 'nuda_proprietario'])->assertSessionHasNoErrors()->assertRedirect();
    $prima = urRighe($s);
    $dati = ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => urRiga($s, 'bice', 'usufruttuario'), 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Atto letto, righe controllate'];

    $errore = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati)->assertUnprocessable()->json('errors.decorrenza.0');
    expect($errore)->toBe('Rita era nudo proprietario di questa unità il 1 maggio 2026, e un passaggio registrato dopo ha chiuso quella riga dal 1 giugno 2026: dal giorno dell\'estinzione quella nuda proprietà diventa piena, e il passaggio dopo andrebbe registrato sulla proprietà piena. Annulla prima dallo storico dell\'unità i passaggi successivi, l\'ultimo per primo, registra l\'estinzione e poi di nuovo quei passaggi dalla riga piena; se l\'atto dice altro, correggi le righe a mano da «Modifica associazione».');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati)->assertSessionHasErrors('decorrenza');
    expect(urRighe($s))->toBe($prima);
});

it('rilievo Y1 del quarto giro — una vendita della nuda datata dopo l\'estinzione a una persona diversa dal nudo: niente ricetta (finiva nel rifiuto della decisione 53), la correzione a mano', function () {
    $s = urScenario([['rita', 'nuda_proprietario', 25, '2019-01-01'], ['bice', 'usufruttuario', 50, '2019-01-01'], ['elsa', 'proprietario', 50, '2019-01-01']]);
    foreach (['gino' => 'URGINOY1', 'dora' => 'URDORAY1'] as $nome => $cf) {
        $s['p'][$nome] = Anagrafica::forceCreate(['nome' => ucfirst($nome), 'email' => "ur-{$nome}y1" . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => $cf . str_pad((string) $s['unita']->id, 8, '0', STR_PAD_LEFT)]);
        $s['p'][$nome]->condomini()->syncWithoutDetaching([$s['c']->id]);
    }
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['p']['gino']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'nuda_proprietario', 'quota' => 25, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    urVende($this, $s, 'elsa', 'proprietario', 50);
    urVende($this, $s, 'rita', 'nuda_proprietario', 25);
    urRegistra($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => urRiga($s, 'gino', 'nuda_proprietario'), 'anagrafica_entrante_id' => $s['p']['dora']->id,
        'decorrenza' => '2026-06-01', 'quota' => 25, 'tipologia' => 'nuda_proprietario'])->assertSessionHasNoErrors()->assertRedirect();

    $errori = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), urEstinzione($s))->assertUnprocessable()->json('errors.decorrenza');
    // Prima: la ricetta faceva annullare tre passaggi e registrarne di nuovo due, poi l'estinzione si rifiutava.
    expect($errori[0])->toBe(UR_TESTA_R9 . ' Fra i passaggi da rifare c\'è la vendita della nuda proprietà da Gino a Dora, dal 1 giugno 2026, dopo questa estinzione' . UR_A_MANO)
        ->and($errori[1])->toStartWith('Gino era nudo proprietario di questa unità il 1 maggio 2026, e un passaggio registrato dopo ha chiuso quella riga');
});

it('rilievo T2 del quarto giro — una riga di nudo chiusa a mano (la via a mano della decisione 37): l\'estinzione retrodatata si rifiuta e lo dice, senza mandare a uno storico vuoto', function () {
    $s = urScenario([['bice', 'usufruttuario', 100, '2019-01-01'], ['rita', 'nuda_proprietario', 50, '2026-06-01'], ['nora', 'nuda_proprietario', 50, '2026-06-01']]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['p']['rita']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'nuda_proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => '2026-05-31', 'created_at' => now(), 'updated_at' => now()]);
    $dati = ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => urRiga($s, 'bice', 'usufruttuario'), 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Atto letto, righe controllate'];

    expect(Subentro::count())->toBe(0)
        ->and($this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati)->assertUnprocessable()->json('errors.decorrenza.0'))
        ->toBe('Rita era nudo proprietario di questa unità il 1 maggio 2026, e la sua riga è stata chiusa a mano dal 1 giugno 2026: dal giorno dell\'estinzione quella nuda proprietà diventa piena. Correggi le righe a mano da «Modifica associazione» prima di registrare l\'estinzione.');
});
