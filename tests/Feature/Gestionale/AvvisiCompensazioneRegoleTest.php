<?php

/**
 * L'avviso di compensazione oltre lo scenario di base: l'incasso annullato dopo il solo avviso (nessuna ricevuta) è fra quelli da comunicare,
 * detto «avviso», e «Non serve comunicarla» lo toglie; lo stato del bollo dell'ultimo avviso si ricorda per condominio, come la ricevuta; ogni
 * compensazione porta gli indirizzi a cui andrebbe l'avviso per email, per la conferma prima dell'invio.
 *
 * Lo scenario delle ricevute: Anna Bruni ha un credito di € 20,00, e un incasso fatto solo di credito copre la sua rata 2.
 */

use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

function acrgRotta(object $s, string $nome, array $altro = []): string
{
    return route('admin.gestionale.ricevute.' . $nome, ['condominio' => $s->condominio->id] + $altro);
}

/** L'incasso fatto solo del credito di Anna (€ 20,00 sulla rata 2), il 01/03/2026. */
function acrgCompensazione(object $s): ScritturaContabile
{
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);

    return incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], '2026-03-01', 0.00);
}

function acrgAnnullate($test, object $s, $u): array
{
    return $test->actingAs($u)->get(acrgRotta($s, 'index'))->viewData('page')['props']['annullate_da_comunicare'];
}

test('un incasso con il solo avviso di compensazione, poi annullato, è fra quelli da comunicare detto «avviso»; «Non serve comunicarla» lo toglie', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $this->travelTo('2026-03-02 10:00:00');
    $incasso = acrgCompensazione($s);
    $this->actingAs($u)->post(acrgRotta($s, 'avvisi-compensazione.stampa'), ['avvisi' => [$incasso->id . ':' . $s->anna->id]])->assertOk();
    expect(RegistroInvio::where('tipo', RegistroInvio::TIPO_RICEVUTA)->where('documentabile_id', $incasso->id)->count())->toBe(0);

    $this->travelTo('2026-03-03 10:00:00');
    app(StornoIncassoRateAction::class)->execute(ScritturaContabile::with(['righe', 'quotePagate', 'figlie'])->find($incasso->id), $s->condominio, \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);

    expect(acrgAnnullate($this, $s, $u))->toBe([['id' => $incasso->id, 'numero' => $incasso->numero_protocollo, 'data' => '2026-03-01', 'documento' => 'avviso']]);
    $this->actingAs($u)->postJson(acrgRotta($s, 'non-serve-comunicarla', ['scrittura' => $incasso->id]))->assertOk()->assertJson(['segnata' => true]);
    expect(acrgAnnullate($this, $s, $u))->toBe([])
        ->and(RegistroInvio::where('documentabile_id', $incasso->id)->where('stato', RegistroInvio::STATO_NON_DA_COMUNICARE)->sole()->only(['tipo', 'canale', 'anagrafica_id', 'emesso_da']))
        ->toBe(['tipo' => RegistroInvio::TIPO_AVVISO_COMPENSAZIONE, 'canale' => RegistroInvio::CANALE_NESSUNO, 'anagrafica_id' => $s->anna->id, 'emesso_da' => $u->id]);

    // Una seconda volta non scrive un'altra riga.
    $this->actingAs($u)->postJson(acrgRotta($s, 'non-serve-comunicarla', ['scrittura' => $incasso->id]))->assertOk();
    expect(RegistroInvio::where('documentabile_id', $incasso->id)->where('stato', RegistroInvio::STATO_NON_DA_COMUNICARE)->count())->toBe(1);
});

test('un incasso annullato senza ricevuta né avviso usciti non è fra quelli da comunicare, e «Non serve comunicarla» dice che non c\'è niente', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $this->travelTo('2026-03-02 10:00:00');
    $incasso = acrgCompensazione($s);
    $this->travelTo('2026-03-03 10:00:00');
    app(StornoIncassoRateAction::class)->execute(ScritturaContabile::with(['righe', 'quotePagate', 'figlie'])->find($incasso->id), $s->condominio, \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    expect(acrgAnnullate($this, $s, $u))->toBe([]);
    $this->actingAs($u)->postJson(acrgRotta($s, 'non-serve-comunicarla', ['scrittura' => $incasso->id]))->assertStatus(422)
        ->assertJson(['message' => 'Per questo incasso non è uscita né una ricevuta né un avviso di compensazione: non c\'è niente da comunicare.']);
});

test('lo stato del bollo dell\'ultimo avviso si ricorda per chi lavora in questo condominio; per un altro utente no', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $this->travelTo('2026-03-02 10:00:00');
    $incasso = acrgCompensazione($s);
    $sezione = fn ($chi) => $this->actingAs($chi)->get(acrgRotta($s, 'index', ['data_da' => '2026-01-01', 'data_a' => '2026-12-31']))->viewData('page')['props']['compensazioni'];
    expect($sezione($u)['bollo_ricordato'])->toBeNull();

    $this->actingAs($u)->post(acrgRotta($s, 'avvisi-compensazione.stampa'), ['avvisi' => [$incasso->id . ':' . $s->anna->id], 'bollo' => 'non_dovuta'])->assertOk();
    expect($sezione($u)['bollo_ricordato'])->toBe('non_dovuta')
        ->and($sezione(amministratoreRicevute())['bollo_ricordato'])->toBeNull();
});

test('ogni compensazione porta gli indirizzi a cui va l\'avviso per email (la primaria e la secondaria), per la conferma prima dell\'invio', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $this->travelTo('2026-03-02 10:00:00');
    acrgCompensazione($s);
    $riga = collect($this->actingAs($u)->get(acrgRotta($s, 'index', ['data_da' => '2026-01-01', 'data_a' => '2026-12-31']))->viewData('page')['props']['compensazioni']['righe'])->sole();
    expect($riga['indirizzi'])->toBe([$s->anna->email, $s->anna->email_secondaria]);
});
