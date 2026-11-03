<?php

/**
 * B2 (1.11.0-beta.31), S6 — la competenza per voce (decisione 20) dichiarata alla creazione del piano
 * ordinario arriva in `competenze_capitolo`, sulla pivot giusta: quella del conto se c'è, quelle delle
 * foglie se la pivot è nata su di loro (selezione rapida), e su una voce assente dal piano non si perde in
 * silenzio. La Request rifiuta tratti fuori dall'esercizio, in disordine o con un giorno in comune.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\CompetenzaCapitolo;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
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
    $this->gestione = Gestione::factory()->create(['condominio_id' => $this->condominio->id, 'tipo' => 'ordinaria', 'saldo_applicato' => 0, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $this->gestione->esercizi()->attach($this->esercizio->id);
    $pc = PianoConto::create(['condominio_id' => $this->condominio->id, 'gestione_id' => $this->gestione->id, 'nome' => 'PC']);
    $this->padre = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Riscaldamento', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 0]);
    $this->foglia1 = Conto::create(['piano_conto_id' => $pc->id, 'parent_id' => $this->padre->id, 'nome' => 'Gas', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 500000]);
    $this->foglia2 = Conto::create(['piano_conto_id' => $pc->id, 'parent_id' => $this->padre->id, 'nome' => 'Manutenzione caldaia', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 100000]);
    $this->pulizie = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Pulizie', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 200000]);
    $this->rotta = route('admin.gestionale.esercizi.piani-rate.store', [$this->condominio, $this->esercizio]);
});

function ccCorpo(array $extra = []): array
{
    return array_merge([
        'nome' => 'Preventivo 2026', 'tipo' => 'ordinario', 'metodo_distribuzione' => 'rata_zero', 'numero_rate' => 4,
        'giorno_scadenza' => 10, 'genera_subito' => false, 'recurrence_enabled' => false,
        'capitoli_ids' => [], 'capitoli_config' => [], 'competenze_capitoli' => [],
    ], $extra);
}

function ccTratti(Conto $conto): array
{
    $pivot = DB::table('piano_rate_capitoli')->where('conto_id', $conto->id)->value('id');

    return $pivot ? CompetenzaCapitolo::where('piano_rate_capitolo_id', $pivot)->orderBy('dal')->get()->map(fn ($t) => [$t->dal->toDateString(), $t->al->toDateString()])->all() : [];
}

it('scrive i tratti sulla pivot del conto dichiarato, ordinati, e non tocca le altre voci', function () {
    $stagione = [['dal' => '2026-10-15', 'al' => '2026-12-31'], ['dal' => '2026-01-01', 'al' => '2026-04-15']];
    $this->actingAs($this->user)->post($this->rotta, ccCorpo([
        'gestione_id' => $this->gestione->id,
        'capitoli_config' => [['id' => $this->foglia1->id, 'importo' => '5.000,00', 'note' => ''], ['id' => $this->pulizie->id, 'importo' => '2.000,00', 'note' => '']],
        'competenze_capitoli' => [['conto_id' => $this->foglia1->id, 'tratti' => $stagione]],
    ]))->assertRedirect()->assertSessionHasNoErrors();

    expect(PianoRate::count())->toBe(1)
        ->and(ccTratti($this->foglia1))->toBe([['2026-01-01', '2026-04-15'], ['2026-10-15', '2026-12-31']])
        ->and(ccTratti($this->pulizie))->toBe([])
        ->and(CompetenzaCapitolo::orderBy('dal')->pluck('ordine')->all())->toBe([0, 1]);
});

it('dichiarata sul padre con la pivot nata sulle foglie (selezione rapida), va su ogni foglia', function () {
    $this->actingAs($this->user)->post($this->rotta, ccCorpo([
        'gestione_id' => $this->gestione->id,
        'capitoli_ids' => [$this->padre->id],
        'competenze_capitoli' => [['conto_id' => $this->padre->id, 'tratti' => [['dal' => '2026-01-01', 'al' => '2026-04-15']]]],
    ]))->assertRedirect()->assertSessionHasNoErrors();

    expect(DB::table('piano_rate_capitoli')->pluck('conto_id')->sort()->values()->all())->toBe(collect([$this->foglia1->id, $this->foglia2->id])->sort()->values()->all())
        ->and(ccTratti($this->foglia1))->toBe([['2026-01-01', '2026-04-15']])
        ->and(ccTratti($this->foglia2))->toBe([['2026-01-01', '2026-04-15']])
        ->and(ccTratti($this->padre))->toBe([]);
});

it('su una voce che nel piano non c\'è la competenza non si applica, e il messaggio lo dice', function () {
    $r = $this->actingAs($this->user)->post($this->rotta, ccCorpo([
        'gestione_id' => $this->gestione->id,
        'capitoli_config' => [['id' => $this->pulizie->id, 'importo' => '2.000,00', 'note' => '']],
        'competenze_capitoli' => [['conto_id' => $this->foglia1->id, 'tratti' => [['dal' => '2026-01-01', 'al' => '2026-04-15']]]],
    ]));
    $r->assertRedirect()->assertSessionHasNoErrors();

    expect(CompetenzaCapitolo::count())->toBe(0)
        ->and(session('success'))->toContain('«Gas»')->toContain('non è stata applicata');
});

it('rifiuta con 422 i tratti fuori dall\'esercizio, con la fine prima dell\'inizio, e con un giorno in comune', function () {
    $prova = fn (array $tratti) => $this->actingAs($this->user)->postJson($this->rotta, ccCorpo([
        'gestione_id' => $this->gestione->id,
        'capitoli_config' => [['id' => $this->foglia1->id, 'importo' => '5.000,00', 'note' => '']],
        'competenze_capitoli' => [['conto_id' => $this->foglia1->id, 'tratti' => $tratti]],
    ]));

    $prova([['dal' => '2025-10-15', 'al' => '2026-04-15']])->assertUnprocessable()->assertJsonValidationErrors(['competenze_capitoli.0' => 'dentro l\'esercizio']);
    $prova([['dal' => '2026-04-15', 'al' => '2026-01-01']])->assertUnprocessable()->assertJsonValidationErrors(['competenze_capitoli.0' => 'non può precedere']);
    // 01/01–15/04 e 15/04–31/12: il 15 aprile è di entrambi, e `InsiemePeriodi` lo rifiuterebbe alla generazione.
    $prova([['dal' => '2026-01-01', 'al' => '2026-04-15'], ['dal' => '2026-04-15', 'al' => '2026-12-31']])->assertUnprocessable()->assertJsonValidationErrors(['competenze_capitoli.0' => 'giorno in comune']);
    $prova([['dal' => '2026-01-01', 'al' => '']])->assertUnprocessable()->assertJsonValidationErrors('competenze_capitoli.0.tratti.0.al');

    expect(PianoRate::count())->toBe(0);
});

it('verifica S6, R1 — su una gestione STRAORDINARIA i tratti per voce sono rifiutati con 422: la competenza è la delibera, o quella dichiarata sulla fattura', function () {
    $straordinaria = Gestione::factory()->create(['condominio_id' => $this->condominio->id, 'tipo' => 'straordinaria', 'saldo_applicato' => 0, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $straordinaria->esercizi()->attach($this->esercizio->id);
    $pc = PianoConto::create(['condominio_id' => $this->condominio->id, 'gestione_id' => $straordinaria->id, 'nome' => 'PC str']);
    $lavori = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Facciata', 'tipo' => 'spesa', 'natura_spesa' => 'straordinaria', 'importo' => 500000]);

    $this->actingAs($this->user)->postJson($this->rotta, ccCorpo([
        'gestione_id' => $straordinaria->id, 'data_delibera_assemblea' => '2026-02-15',
        'capitoli_config' => [['id' => $lavori->id, 'importo' => '5.000,00', 'note' => '']],
        'competenze_capitoli' => [['conto_id' => $lavori->id, 'tratti' => [['dal' => '2026-01-01', 'al' => '2026-12-31']]]],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['competenze_capitoli' => 'giorno della delibera']);

    expect(PianoRate::count())->toBe(0)->and(CompetenzaCapitolo::count())->toBe(0);
});

it('la competenza già dichiarata sulla voce si ripropone al piano nuovo (fetch-capitoli-gestione: competenza_dichiarata dal piano più recente, dal capitolo padre se la voce non ne ha) e si legge sulla pagina della fattura (conti.*.competenza_piano)', function () {
    // Piano madre: la stagione su Gas (foglia1), niente su Pulizie; Manutenzione caldaia (foglia2) eredita dal padre
    // se il padre dichiara: qui il padre non dichiara, quindi foglia2 resta senza.
    $stagione = [['dal' => '2026-01-01', 'al' => '2026-04-15'], ['dal' => '2026-10-15', 'al' => '2026-12-31']];
    $this->actingAs($this->user)->post($this->rotta, ccCorpo([
        'nome' => 'Preventivo 2026', 'gestione_id' => $this->gestione->id,
        'capitoli_config' => [['id' => $this->foglia1->id, 'importo' => '5.000,00', 'note' => ''], ['id' => $this->foglia2->id, 'importo' => '1.000,00', 'note' => ''], ['id' => $this->pulizie->id, 'importo' => '2.000,00', 'note' => '']],
        'competenze_capitoli' => [['conto_id' => $this->foglia1->id, 'tratti' => $stagione]],
    ]))->assertRedirect()->assertSessionHasNoErrors();

    $voci = collect($this->actingAs($this->user)->get(route('admin.gestionale.fetch-capitoli-gestione', ['condominio' => $this->condominio->id]) . '?gestione_id=' . $this->gestione->id . '&esercizio_id=' . $this->esercizio->id)->json())->keyBy('id');
    expect($voci[$this->foglia1->id]['competenza_dichiarata'])->toMatchArray(['piano' => 'Preventivo 2026', 'tratti' => $stagione])
        ->and($voci[$this->foglia2->id]['competenza_dichiarata'])->toBeNull()
        ->and($voci[$this->pulizie->id]['competenza_dichiarata'])->toBeNull();

    // Un secondo piano (l'integrativa) che dichiara sul padre un tratto diverso: vale il più recente, e le foglie
    // senza tratti proprio lo ereditano dal padre — foglia1 tiene il suo (il piano nuovo lo scrive sulle foglie).
    $this->actingAs($this->user)->post($this->rotta, ccCorpo([
        'nome' => 'Integrativa', 'gestione_id' => $this->gestione->id,
        'capitoli_ids' => [$this->padre->id],
        'competenze_capitoli' => [['conto_id' => $this->padre->id, 'tratti' => [['dal' => '2026-10-15', 'al' => '2026-12-31']]]],
    ]))->assertRedirect()->assertSessionHasNoErrors();
    $voci = collect($this->actingAs($this->user)->get(route('admin.gestionale.fetch-capitoli-gestione', ['condominio' => $this->condominio->id]) . '?gestione_id=' . $this->gestione->id . '&esercizio_id=' . $this->esercizio->id)->json())->keyBy('id');
    expect($voci[$this->foglia1->id]['competenza_dichiarata'])->toMatchArray(['piano' => 'Integrativa', 'tratti' => [['dal' => '2026-10-15', 'al' => '2026-12-31']]])
        ->and($voci[$this->foglia2->id]['competenza_dichiarata'])->toMatchArray(['piano' => 'Integrativa', 'tratti' => [['dal' => '2026-10-15', 'al' => '2026-12-31']]]);

    // La pagina della fattura dice la stessa cosa, voce per voce.
    $this->actingAs($this->user)->get(route('admin.gestionale.fatture.create', ['condominio' => $this->condominio->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('gestionale/movimenti/fatture/FatturaRegisterNew')
            ->where('conti', fn ($conti) => collect($conti)->firstWhere('id', $this->foglia1->id)['competenza_piano']['piano'] === 'Integrativa'
                && collect($conti)->firstWhere('id', $this->pulizie->id)['competenza_piano'] === null));
});
