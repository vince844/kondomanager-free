<?php

/**
 * B2 (1.11.0-beta.31), S6 — la data della delibera nasce con il piano, obbligatoria e senza default quando
 * la GESTIONE è straordinaria (decisioni 11 e 12): è il giorno che fa la competenza dello straordinario, e il
 * motore senza si ferma. Con «Urgenza» non si chiede (la competenza va sulla fattura). Tornare in bozza non la
 * cancella più: è un fatto dell'assemblea, non del programma.
 */

use App\Enums\StatoPianoRate;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'collaboratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->condominio = Condominio::factory()->create();
    $this->esercizio = Esercizio::factory()->create(['condominio_id' => $this->condominio->id, 'stato' => 'aperto', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
});

function doGestione(Condominio $c, Esercizio $e, string $tipo): Gestione
{
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'tipo' => $tipo, 'saldo_applicato' => 0, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $g->esercizi()->attach($e->id);
    // Il piano dei conti: senza, `store()` rifiuta la gestione («no linked chart of accounts»).
    DB::table('piani_conti')->insert(['gestione_id' => $g->id, 'condominio_id' => $c->id, 'nome' => 'Piano conti', 'created_at' => now(), 'updated_at' => now()]);

    return $g;
}

function doFattura(Condominio $c, Esercizio $e): int
{
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Fornitore Delibera', 'soggetto_ritenuta' => false, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);

    return DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'fornitore_id' => $fornitoreId, 'tipo_documento' => 'fattura',
        'numero_documento' => 'DEL-1', 'data_documento' => '2026-03-01', 'data_scadenza' => '2026-04-01',
        'importo_imponibile' => 100000, 'importo_iva' => 0, 'netto_a_pagare' => 100000, 'totale_documento' => 100000,
        'stato_approvazione' => 'da_approvare', 'stato_pagamento' => 'aperta', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function doCorpo(Gestione $g, int $fatturaId, array $extra = []): array
{
    return array_merge([
        'gestione_id' => $g->id, 'nome' => 'Facciata 2026', 'tipo' => 'straordinario',
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Verbale assemblea',
        'fatture_config' => [['id' => $fatturaId, 'importo' => '1000,00']],
        'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2, 'giorno_scadenza' => 10, 'capitoli_ids' => [],
        'genera_subito' => false, 'recurrence_enabled' => false,
    ], $extra);
}

it('su una gestione straordinaria con delibera la data è obbligatoria (422 senza), e con la data nasce scritta sul piano', function () {
    $g = doGestione($this->condominio, $this->esercizio, 'straordinaria');
    $f = doFattura($this->condominio, $this->esercizio);
    $rotta = route('admin.gestionale.esercizi.piani-rate.store', [$this->condominio, $this->esercizio]);

    $this->actingAs($this->user)->postJson($rotta, doCorpo($g, $f))->assertUnprocessable()->assertJsonValidationErrors('data_delibera_assemblea');
    expect(PianoRate::count())->toBe(0);

    $this->actingAs($this->user)->post($rotta, doCorpo($g, $f, ['data_delibera_assemblea' => '2026-02-12']))->assertRedirect();
    $piano = PianoRate::sole();
    expect($piano->data_delibera_assemblea->toDateString())->toBe('2026-02-12')->and($piano->stato)->toBe(StatoPianoRate::APPROVATO);
});

it('con «urgenza» la data non si chiede: la competenza va sulla fattura', function () {
    $g = doGestione($this->condominio, $this->esercizio, 'straordinaria');
    $f = doFattura($this->condominio, $this->esercizio);

    $r = $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$this->condominio, $this->esercizio]), doCorpo($g, $f, ['tipo_autorizzazione' => 'urgenza']));
    $r->assertRedirect();
    expect(PianoRate::sole()->data_delibera_assemblea)->toBeNull();
});

it('su una gestione ORDINARIA la data non è obbligatoria (decisione 11: la natura è della gestione, non del tipo del piano), ma se c\'è si scrive', function () {
    $g = doGestione($this->condominio, $this->esercizio, 'ordinaria');
    $f = doFattura($this->condominio, $this->esercizio);
    $rotta = route('admin.gestionale.esercizi.piani-rate.store', [$this->condominio, $this->esercizio]);

    $this->actingAs($this->user)->post($rotta, doCorpo($g, $f))->assertRedirect();
    expect(PianoRate::sole()->data_delibera_assemblea)->toBeNull();

    $this->actingAs($this->user)->post($rotta, doCorpo($g, $f, ['nome' => 'Facciata bis', 'data_delibera_assemblea' => '2026-05-20']))->assertRedirect();
    expect(PianoRate::where('nome', 'Facciata bis')->sole()->data_delibera_assemblea->toDateString())->toBe('2026-05-20');
});

it('tornare in bozza non cancella la data della delibera: è un fatto dell\'assemblea, e senza uno straordinario non si rigenera', function () {
    $g = doGestione($this->condominio, $this->esercizio, 'straordinaria');
    $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $this->condominio->id, 'nome' => 'Piano', 'stato' => StatoPianoRate::APPROVATO, 'tipo' => 'straordinario', 'numero_rate' => 1, 'data_delibera_assemblea' => '2026-02-12', 'approvato_il' => now()]);

    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$this->condominio, $this->esercizio, $piano]), ['approvato' => false])->assertRedirect();
    $piano->refresh();
    expect($piano->stato)->toBe(StatoPianoRate::BOZZA)->and($piano->data_delibera_assemblea->toDateString())->toBe('2026-02-12')->and($piano->approvato_il)->toBeNull();
});

it('verifica S6, R2 — con «urgenza» una data della delibera arrivata nel form non si scrive: non c\'è delibera, e il motore non deve trovarne una nascosta', function () {
    $g = doGestione($this->condominio, $this->esercizio, 'straordinaria');
    $f = doFattura($this->condominio, $this->esercizio);

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$this->condominio, $this->esercizio]), doCorpo($g, $f, ['tipo_autorizzazione' => 'urgenza', 'data_delibera_assemblea' => '2026-02-12']))->assertRedirect();
    $piano = PianoRate::sole();
    expect($piano->tipo_autorizzazione)->toBe('urgenza')->and($piano->data_delibera_assemblea)->toBeNull()
        ->and(app(\App\Services\Riparto\CompetenzaDelPiano::class)->perPiano($piano, $this->esercizio)->richiedeDelibera)->toBeTrue();
});

it('verifica S6, R4/R6 — su un piano tipo=ordinario aperto su una gestione straordinaria la data è obbligatoria, e il messaggio non manda a cercare «Urgenza» (che lì non c\'è)', function () {
    $g = doGestione($this->condominio, $this->esercizio, 'straordinaria');
    $conto = \App\Models\Gestionale\Conto::create(['piano_conto_id' => DB::table('piani_conti')->where('gestione_id', $g->id)->value('id'), 'nome' => 'Facciata', 'tipo' => 'spesa', 'natura_spesa' => 'straordinaria', 'importo' => 500000]);
    $rotta = route('admin.gestionale.esercizi.piani-rate.store', [$this->condominio, $this->esercizio]);
    $corpo = ['gestione_id' => $g->id, 'nome' => 'Facciata a rate', 'tipo' => 'ordinario', 'metodo_distribuzione' => 'rata_zero', 'numero_rate' => 4, 'giorno_scadenza' => 10,
        'capitoli_config' => [['id' => $conto->id, 'importo' => '5.000,00', 'note' => '']], 'capitoli_ids' => [], 'genera_subito' => false, 'recurrence_enabled' => false];

    $r = $this->actingAs($this->user)->postJson($rotta, $corpo);
    $r->assertUnprocessable()->assertJsonValidationErrors('data_delibera_assemblea');
    expect($r->json('errors.data_delibera_assemblea.0'))->toContain('giorno che decide chi paga')->not->toContain('Urgenza');

    $this->actingAs($this->user)->post($rotta, array_merge($corpo, ['data_delibera_assemblea' => '2026-02-12']))->assertRedirect();
    expect(PianoRate::sole()->data_delibera_assemblea->toDateString())->toBe('2026-02-12');
});

it('verifica S6, R3 — il flash del ritorno in bozza dice che la data della delibera resta', function () {
    $g = doGestione($this->condominio, $this->esercizio, 'straordinaria');
    $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $this->condominio->id, 'nome' => 'Piano', 'stato' => StatoPianoRate::APPROVATO, 'tipo' => 'straordinario', 'numero_rate' => 1, 'data_delibera_assemblea' => '2026-02-12', 'approvato_il' => now()]);

    $r = $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$this->condominio, $this->esercizio, $piano]), ['approvato' => false]);
    expect($r->getSession()->get('message')['message'])->toContain('La data della delibera resta registrata')->not->toContain('rimossi');
});

/*
|--------------------------------------------------------------------------
| S8-22 / S8-32 — la delibera cambiata dopo la generazione, e l'urgenza in approvazione
|--------------------------------------------------------------------------
*/

/** Uno straordinario già generato: una riga congelata col gradino «delibera» al 12/02 e una rata in bozza. */
function doPianoGenerato(Condominio $c, Gestione $g, ?string $delibera = '2026-02-12', string $autorizzazione = 'delibera'): PianoRate
{
    $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $c->id, 'nome' => 'Piano generato', 'stato' => StatoPianoRate::APPROVATO, 'tipo' => 'straordinario', 'numero_rate' => 1, 'data_delibera_assemblea' => $delibera, 'tipo_autorizzazione' => $autorizzazione, 'approvato_il' => now()]);
    if ($delibera !== null) {
        DB::table('righe_riparto')->insert(['piano_rate_id' => $piano->id, 'tipo' => 'riparto', 'anagrafica_id' => null, 'immobile_id' => null, 'conto_nome' => 'Facciata', 'conto_radice_nome' => 'Facciata', 'importo' => 100000, 'competenza_dal' => $delibera, 'competenza_al' => $delibera, 'gradino_competenza' => 'delibera', 'versione_calcolo' => 'test', 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('rate')->insert(['piano_rate_id' => $piano->id, 'numero_rata' => 1, 'data_scadenza' => '2026-06-30', 'importo_totale' => 100000, 'stato' => 'bozza', 'created_at' => now(), 'updated_at' => now()]);

    return $piano;
}

it('S8-22 — cambiare la data della delibera su uno straordinario già generato si accetta ma avvisa di ricalcolare; l\'emissione con le quote stantie è rifiutata finché le righe non portano la data nuova', function () {
    $g = doGestione($this->condominio, $this->esercizio, 'straordinaria');
    $piano = doPianoGenerato($this->condominio, $g);

    $r = $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$this->condominio, $this->esercizio, $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2026-06-01']);
    $r->assertRedirect();
    expect($piano->refresh()->data_delibera_assemblea->toDateString())->toBe('2026-06-01')
        ->and($r->getSession()->get('message')['type'])->toBe('warning')
        ->and($r->getSession()->get('message')['message'])->toContain('dal 12 febbraio 2026 al 1 giugno 2026')->toContain('ricalcola il piano prima di emettere');

    $rataId = (int) DB::table('rate')->where('piano_rate_id', $piano->id)->value('id');
    $e = $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$this->condominio, $piano]), ['rate_ids' => [$rataId], 'data_emissione' => '2026-06-05']);
    expect($e->getSession()->get('message')['type'])->toBe('error')
        ->and($e->getSession()->get('message')['message'])->toContain('calcolate con la delibera del 12 febbraio 2026')->toContain('registra una delibera del 1 giugno 2026')
        ->and(DB::table('rate')->where('id', $rataId)->value('stato'))->toBe('bozza');

    // Righe riallineate (come dopo un ricalcolo): la guardia non scatta più.
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->update(['competenza_dal' => '2026-06-01', 'competenza_al' => '2026-06-01']);
    $e2 = $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$this->condominio, $piano]), ['rate_ids' => [$rataId], 'data_emissione' => '2026-06-05']);
    expect($e2->getSession()->get('message')['message'] ?? '')->not->toContain('calcolate con la delibera');
});

it('S8-32 — un piano d\'urgenza si approva senza data della delibera (302, colonna NULL, richiedeDelibera resta vero) e una data inviata comunque non si scrive', function () {
    $g = doGestione($this->condominio, $this->esercizio, 'straordinaria');
    $piano = doPianoGenerato($this->condominio, $g, null, 'urgenza');
    $piano->update(['stato' => StatoPianoRate::BOZZA, 'approvato_il' => null]);
    $rotta = route('admin.gestionale.piani-rate.update-stato', [$this->condominio, $this->esercizio, $piano]);

    $r = $this->actingAs($this->user)->put($rotta, ['approvato' => true]);
    $r->assertRedirect()->assertSessionHasNoErrors();
    expect($piano->refresh()->stato)->toBe(StatoPianoRate::APPROVATO)->and($piano->data_delibera_assemblea)->toBeNull()
        ->and(app(\App\Services\Riparto\CompetenzaDelPiano::class)->perPiano($piano, $this->esercizio)->richiedeDelibera)->toBeTrue()
        ->and($r->getSession()->get('message')['message'])->toContain('nessuna delibera da registrare');

    $piano->update(['stato' => StatoPianoRate::BOZZA]);
    $this->actingAs($this->user)->put($rotta, ['approvato' => true, 'data_delibera_assemblea' => '2026-02-12'])->assertRedirect();
    expect($piano->refresh()->data_delibera_assemblea)->toBeNull();

    // Il messaggio del motore, con urgenza, manda alla fattura e non a una delibera che non esiste.
    $f = \App\Models\Gestionale\FatturaPassiva::find(doFattura($this->condominio, $this->esercizio));
    expect((new \App\Exceptions\Gestionale\RichiedeDeliberaException($piano, $f))->getMessage())->toContain('intervento d\'urgenza')->toContain('Dichiara il periodo di competenza sulla fattura')->not->toContain('Scrivi la data della delibera nel piano');
});

test('R20 [beta.35] — con «Urgenza» il messaggio d\'arresto per una pregressa non manda a modificarla: una pregressa si storna e si registra di nuovo', function () {
    $g = doGestione($this->condominio, $this->esercizio, 'straordinaria');
    $piano = doPianoGenerato($this->condominio, $g, null, 'urgenza');
    $f = \App\Models\Gestionale\FatturaPassiva::find(doFattura($this->condominio, $this->esercizio));
    $f->is_pregresso = true;

    $messaggio = (new \App\Exceptions\Gestionale\RichiedeDeliberaException($piano, $f))->getMessage();

    expect($messaggio)->toContain('intervento d\'urgenza')->toContain('si storna')->toContain('registrala di nuovo con il periodo')
        ->not->toContain('Movimenti → fatture → modifica');
});

test('verifica delle correzioni [beta.35] — senza data della delibera e con una pregressa il messaggio non manda a dichiarare la competenza sulla fattura', function () {
    $g = doGestione($this->condominio, $this->esercizio, 'straordinaria');
    $piano = doPianoGenerato($this->condominio, $g, null, 'delibera');
    $f = \App\Models\Gestionale\FatturaPassiva::find(doFattura($this->condominio, $this->esercizio));
    $f->is_pregresso = true;

    $messaggio = (new \App\Exceptions\Gestionale\RichiedeDeliberaException($piano, $f))->getMessage();

    expect($messaggio)->toContain('Scrivi la data della delibera nel piano')->toContain('si storna e si registra di nuovo')
        ->not->toContain('o la competenza sulla fattura');
});
