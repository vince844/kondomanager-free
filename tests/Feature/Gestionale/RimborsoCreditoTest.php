<?php

/**
 * B2 (1.11.0-beta.31), S6 voce 9 — il rimborso del credito di un condòmino dall'estratto conto.
 *
 * Denaro che esce dalla cassa a fronte di una quota a credito, con la contropartita **secondo l'origine
 * del credito**: da un versamento in eccesso (l'incasso ha già scritto AVERE su Crediti verso condomini →
 * il rimborso lo rovescia in DARE) o da saldi (la quota a credito della rata zero, mai scritta a giornale
 * → DARE Passate gestioni). La quota si consuma come con lo `storno_credito` (pivot −importo), lo storno
 * è una `rettifica` con l'originale `annullata`, e un piano con un credito rimborsato non si ricalcola.
 */

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\User;
use App\Services\Gestionale\SaldoCassaService;
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

/** Condominio con cassa (saldo iniziale € 100,00), conti per ruolo, una persona, un piano con una rata emessa da € 100,00. */
function rcScenario(): object
{
    static $n = 0;
    $n++;
    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::create(['condominio_id' => $condominio->id, 'nome' => '2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $gestione = Gestione::create(['condominio_id' => $condominio->id, 'nome' => 'Ordinaria', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $contoBanca = ContoContabile::create(['condominio_id' => $condominio->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    $contoCrediti = ContoContabile::create(['condominio_id' => $condominio->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $condominio->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    $contoPassate = ContoContabile::create(['condominio_id' => $condominio->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassa = Cassa::create(['condominio_id' => $condominio->id, 'conto_contabile_id' => $contoBanca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 10000]);

    $persona = Anagrafica::forceCreate(['nome' => 'Rossi Giuseppe', 'email' => "rc{$n}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RCTEST'.str_pad((string) $n, 10, '0', STR_PAD_LEFT)]);
    $persona->condomini()->syncWithoutDetaching([$condominio->id]);
    $immobile = Immobile::create(['condominio_id' => $condominio->id, 'nome' => 'Int 1', 'descrizione' => 'Appartamento', 'interno' => '1', 'codice_immobile' => "RC-{$n}"]);
    $immobile->anagrafiche()->attach($persona->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01']);

    $piano = PianoRate::create(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'esercizio_id' => $esercizio->id, 'nome' => 'Piano 2026', 'numero_rate' => 2, 'stato' => 'approvato', 'tipo' => 'ordinario']);
    $rata = Rata::create(['piano_rate_id' => $piano->id, 'numero_rata' => 1, 'data_scadenza' => '2026-01-31', 'data_emissione' => '2026-01-10', 'importo_totale' => 10000, 'stato' => 'emessa']);
    $quota = RataQuote::create(['rata_id' => $rata->id, 'anagrafica_id' => $persona->id, 'immobile_id' => $immobile->id, 'importo' => 10000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-01-31']);

    return (object) compact('condominio', 'esercizio', 'gestione', 'cassa', 'contoBanca', 'contoCrediti', 'contoPassate', 'persona', 'immobile', 'piano', 'rata', 'quota');
}

/** Un incasso di € 120,00 sulla rata da € 100,00: la quota resta strapagata di € 20,00. */
function rcStrapaga(object $s): void
{
    app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s->persona->id, 'cassa_id' => $s->cassa->id, 'gestione_id' => $s->gestione->id,
        'data_pagamento' => '2026-02-01', 'importo_totale' => 120.00, 'descrizione' => 'Saldo + anticipo', 'eccedenza' => 20.00,
        'dettaglio_pagamenti' => [['rata_id' => $s->quota->id, 'importo' => 100.00]],
    ], $s->condominio, $s->esercizio);
}

/** La quota a credito della rata zero: il saldo di un passaggio (−€ 50,00) assorbito da un piano, mai scritto a giornale. */
function rcQuotaDaSaldi(object $s): RataQuote
{
    $rataZero = Rata::create(['piano_rate_id' => $s->piano->id, 'numero_rata' => 0, 'data_scadenza' => '2026-01-10', 'data_emissione' => '2026-01-10', 'importo_totale' => 0, 'stato' => 'emessa']);

    return RataQuote::create([
        'rata_id' => $rataZero->id, 'anagrafica_id' => $s->persona->id, 'immobile_id' => $s->immobile->id, 'importo' => -5000, 'importo_pagato' => 0,
        'stato' => 'credito', 'data_scadenza' => '2026-01-10',
        'regole_calcolo' => ['importi' => ['quota_pura_gestione' => 0, 'saldo_usato' => -5000, 'totale_calcolato' => -5000]],
    ]);
}

function rcRotta(object $s): string
{
    return route('admin.gestionale.anagrafiche.rimborsi.store', [$s->condominio, $s->persona]);
}

it('rimborsa un credito da versamento in eccesso: DARE Crediti verso condomini, AVERE la cassa, la quota si chiude, protocollo RMB', function () {
    $s = rcScenario();
    rcStrapaga($s);
    $s->quota->refresh();
    expect($s->quota->credito_disponibile)->toBe(2000)->and(app(SaldoCassaService::class)->saldoDisponibile($s->cassa))->toBe(22000);

    $r = $this->actingAs($this->user)->post(rcRotta($s), ['rata_quote_id' => $s->quota->id, 'cassa_id' => $s->cassa->id, 'data_rimborso' => '2026-03-05', 'importo' => '20,00', 'nota' => 'Bonifico del 5 marzo']);
    $r->assertRedirect()->assertSessionHasNoErrors();
    expect($r->getSession()->get('message')['type'])->toBe('success');

    $scrittura = ScritturaContabile::where('tipo_movimento', 'rimborso_condomino')->sole();
    expect($scrittura->numero_protocollo)->toStartWith('RMB-')->and($scrittura->stato)->toBe('registrata')
        ->and($scrittura->data_competenza->toDateString())->toBe('2026-03-05')->and($scrittura->descrizione)->toBe('Bonifico del 5 marzo')
        ->and($scrittura->gestione_id)->toBe($s->gestione->id);

    $righe = $scrittura->righe()->get();
    $dare = $righe->firstWhere('tipo_riga', 'dare');
    $avere = $righe->firstWhere('tipo_riga', 'avere');
    expect($dare->conto_contabile_id)->toBe($s->contoCrediti->id)->and((int) $dare->importo)->toBe(2000)
        ->and($dare->anagrafica_id)->toBe($s->persona->id)->and($dare->rata_id)->toBe($s->rata->id)->and($dare->immobile_id)->toBe($s->immobile->id)
        ->and($avere->conto_contabile_id)->toBe($s->contoBanca->id)->and((int) $avere->importo)->toBe(2000)
        ->and($avere->cassa_id)->toBe($s->cassa->id)->and($avere->anagrafica_id)->toBeNull();

    $s->quota->refresh();
    expect((int) $s->quota->importo_pagato)->toBe(10000)->and($s->quota->credito_disponibile)->toBe(0)->and($s->quota->stato)->toBe('pagata')
        ->and(DB::table('quota_scrittura')->where('scrittura_contabile_id', $scrittura->id)->value('importo_pagato'))->toBe(-2000)
        ->and(app(SaldoCassaService::class)->saldoDisponibile($s->cassa))->toBe(20000);
});

it('rimborsa un credito nato da saldi (quota a credito della rata zero): la contropartita è Passate gestioni, non Crediti verso condomini', function () {
    $s = rcScenario();
    $quota = rcQuotaDaSaldi($s);
    expect($quota->credito_disponibile)->toBe(5000);

    $this->actingAs($this->user)->post(rcRotta($s), ['rata_quote_id' => $quota->id, 'cassa_id' => $s->cassa->id, 'data_rimborso' => '2026-03-05', 'importo' => '50,00'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $scrittura = ScritturaContabile::where('tipo_movimento', 'rimborso_condomino')->sole();
    $dare = $scrittura->righe()->where('tipo_riga', 'dare')->sole();
    expect($dare->conto_contabile_id)->toBe($s->contoPassate->id)->and((int) $dare->importo)->toBe(5000)
        ->and($scrittura->note)->toContain('Passate gestioni');
    $quota->refresh();
    expect((int) $quota->importo_pagato)->toBe(-5000)->and($quota->credito_disponibile)->toBe(0)
        ->and(app(SaldoCassaService::class)->saldoDisponibile($s->cassa))->toBe(5000);
});

it('rifiuta più del credito disponibile, una cassa senza capienza, una data futura, e il credito di un\'altra persona — e non scrive nulla', function () {
    $s = rcScenario();
    rcStrapaga($s);
    $base = ['rata_quote_id' => $s->quota->id, 'cassa_id' => $s->cassa->id, 'data_rimborso' => '2026-03-05', 'importo' => '20,00'];

    $this->actingAs($this->user)->postJson(rcRotta($s), array_merge($base, ['importo' => '20,01']))->assertUnprocessable()->assertJsonValidationErrors(['importo' => 'al massimo € 20,00']);
    $this->actingAs($this->user)->postJson(rcRotta($s), array_merge($base, ['importo' => '0,00']))->assertUnprocessable()->assertJsonValidationErrors('importo');
    $this->actingAs($this->user)->postJson(rcRotta($s), array_merge($base, ['data_rimborso' => now()->addDay()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('data_rimborso');

    // La cassa scende a € 10,00: il rimborso di € 20,00 non ci sta.
    $s->cassa->update(['saldo_iniziale' => -21000]);
    $this->actingAs($this->user)->postJson(rcRotta($s), $base)->assertUnprocessable()->assertJsonValidationErrors(['cassa_id' => 'non basta']);
    $s->cassa->update(['saldo_iniziale' => 10000]);

    // Un fondo vincolato non rimborsa.
    $fondo = Cassa::create(['condominio_id' => $s->condominio->id, 'conto_contabile_id' => $s->contoBanca->id, 'nome' => 'Fondo', 'tipo' => 'fondo', 'attiva' => true, 'saldo_iniziale' => 0]);
    $this->actingAs($this->user)->postJson(rcRotta($s), array_merge($base, ['cassa_id' => $fondo->id]))->assertUnprocessable()->assertJsonValidationErrors('cassa_id');

    // La quota è di un'altra persona.
    $altra = Anagrafica::forceCreate(['nome' => 'Bianchi Anna', 'email' => 'rc-altra@test.it', 'indirizzo' => 'Via Roma 2', 'codice_fiscale' => 'RCALTRA000000001']);
    $altra->condomini()->syncWithoutDetaching([$s->condominio->id]);
    $s->immobile->anagrafiche()->attach($altra->id, ['tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01']);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.anagrafiche.rimborsi.store', [$s->condominio, $altra]), $base)->assertUnprocessable()->assertJsonValidationErrors('rata_quote_id');

    expect(ScritturaContabile::where('tipo_movimento', 'rimborso_condomino')->count())->toBe(0)
        ->and($s->quota->fresh()->credito_disponibile)->toBe(2000);
});

it('lo storno del rimborso è una rettifica: il credito torna sulla quota, la cassa rientra nei libri, l\'originale resta annullato', function () {
    $s = rcScenario();
    rcStrapaga($s);
    $this->actingAs($this->user)->post(rcRotta($s), ['rata_quote_id' => $s->quota->id, 'cassa_id' => $s->cassa->id, 'data_rimborso' => '2026-03-05', 'importo' => '20,00'])->assertSessionHasNoErrors();
    $rimborso = ScritturaContabile::where('tipo_movimento', 'rimborso_condomino')->sole();
    expect($s->quota->fresh()->credito_disponibile)->toBe(0);

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s->condominio, $rimborso->id]))->assertRedirect();

    $rimborso->refresh();
    $rettifica = ScritturaContabile::where('scrittura_padre_id', $rimborso->id)->sole();
    expect($rimborso->stato)->toBe('annullata')->and($rettifica->tipo_movimento->value)->toBe('rettifica')
        ->and($rettifica->righe()->where('tipo_riga', 'dare')->sole()->cassa_id)->toBe($s->cassa->id)
        ->and($s->quota->fresh()->credito_disponibile)->toBe(2000)
        ->and(app(SaldoCassaService::class)->saldoDisponibile($s->cassa))->toBe(22000);
});

it('un piano con un credito rimborsato (importo_pagato negativo) ha movimenti: non si ricalcola, non si elimina', function () {
    $s = rcScenario();
    $quota = rcQuotaDaSaldi($s);
    expect($s->piano->haIncassiRegistrati())->toBeFalse();

    $this->actingAs($this->user)->post(rcRotta($s), ['rata_quote_id' => $quota->id, 'cassa_id' => $s->cassa->id, 'data_rimborso' => '2026-03-05', 'importo' => '50,00'])->assertSessionHasNoErrors();
    expect((int) $quota->fresh()->importo_pagato)->toBe(-5000)->and($s->piano->haIncassiRegistrati())->toBeTrue();

    $r = $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->condominio, $s->esercizio, $s->piano]));
    $r->assertRedirect();
    expect($r->getSession()->get('message')['type'])->toBe('error')->and($r->getSession()->get('message')['message'])->toContain('rimborsati');
    expect(RataQuote::whereKey($quota->id)->exists())->toBeTrue();
});

it('l\'estratto conto mostra il rimborso come addebito pieno, e il box del credito torna a zero', function () {
    $s = rcScenario();
    $quota = rcQuotaDaSaldi($s);
    $pagina = fn () => $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s->condominio, $s->persona]))->assertOk()->viewData('page')['props'];

    $prima = $pagina();
    expect($prima['stats']['credito_disponibile_raw'])->toBe(5000)
        ->and($prima['rimborso']['quote'])->toHaveCount(1)->and($prima['rimborso']['quote'][0]['origine'])->toBe('saldi')->and($prima['rimborso']['quote'][0]['credito_euro'])->toBe('50,00')
        ->and($prima['rimborso']['casse'])->toHaveCount(1)->and($prima['rimborso']['casse'][0]['saldo_cents'])->toBe(10000);

    $this->actingAs($this->user)->post(rcRotta($s), ['rata_quote_id' => $quota->id, 'cassa_id' => $s->cassa->id, 'data_rimborso' => '2026-03-05', 'importo' => '50,00'])->assertSessionHasNoErrors();

    $dopo = $pagina();
    $riga = collect($dopo['timeline'])->firstWhere('tipo_movimento', 'rimborso_condomino');
    // Il DARE pesa l'importo contabile (€ 50,00), non la quota pura della rata zero (0): il saldo si muove.
    expect($riga)->not->toBeNull()->and($riga['dare'])->toBe(5000)->and($riga['tipo_icona'])->toBe('banknote')->and($riga['stornabile'])->toBeTrue()
        ->and(collect($riga['dettagli'])->pluck('text')->implode(' '))->toContain('Rimborso del credito')
        ->and($dopo['stats']['credito_disponibile_raw'])->toBe(0)->and($dopo['rimborso']['quote'])->toBe([]);
});
