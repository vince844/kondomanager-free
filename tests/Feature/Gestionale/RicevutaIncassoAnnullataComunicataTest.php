<?php

use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * La ricevuta di un incasso annullato che era già uscita: a chi si manda per email (anche quando la persona ha corretto l'indirizzo e la
 * ricevuta è andata a tutti e due) e come l'amministratore dice che alla persona non serve comunicarlo. «Non serve comunicarla» scrive una riga
 * nel registro (chi e quando), toglie la ricevuta dall'avviso delle annullate da comunicare e resta fra le emissioni dell'incasso.
 *
 * Che cosa NON copre: la finestra e la pagina (nei test JavaScript).
 */
function annullataUrl(string $nome, object $s, array $altro = []): string
{
    return route('admin.gestionale.ricevute.' . $nome, ['condominio' => $s->condominio->id] + $altro);
}

function annullataStorna(ScritturaContabile $incasso, object $s): void
{
    app(StornoIncassoRateAction::class)->execute(ScritturaContabile::with(['righe', 'quotePagate', 'figlie'])->find($incasso->id), $s->condominio);
}

/** L'avviso della pagina delle ricevute: i numeri degli incassi annullati dopo l'uscita della ricevuta. */
function annullataDaComunicare($test, object $s, $utente): array
{
    return collect($test->actingAs($utente)->get(annullataUrl('index', $s))->viewData('page')['props']['annullate_da_comunicare'])->pluck('id')->sort()->values()->all();
}

test('la ricevuta annullata partita a due indirizzi primari (prima e dopo una correzione) si propone a tutti e due, ognuno con la sua casella, e va a quelli spuntati', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $x = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $vecchio = $s->anna->email;
    $nuovo = 'anna.nuova@prova.it';
    $this->actingAs($u)->postJson(annullataUrl('invia-una', $s, ['scrittura' => $x->id]), ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'])->assertOk();
    RegistroInvio::query()->update(['stato' => 'inviata']);
    // Anna corregge l'email, e «Invia di nuovo» manda la ricevuta al nuovo indirizzo.
    $s->anna->update(['email' => $nuovo]);
    $this->actingAs($u)->postJson(annullataUrl('invia-di-nuovo', $s, ['invio' => RegistroInvio::sole()->id]))->assertOk();
    RegistroInvio::query()->update(['stato' => 'inviata']);
    $this->travel(1)->minutes();
    annullataStorna($x, $s);

    $indirizzi = $this->actingAs($u)->getJson(annullataUrl('opzioni', $s, ['scrittura' => $x->id]))->assertOk()->json('destinatari.0.indirizzi');
    $chiavi = collect($indirizzi)->pluck('chiave');
    expect($indirizzi)->toHaveCount(2)
        ->and($chiavi->unique())->toHaveCount(2)
        ->and(collect($indirizzi)->pluck('indirizzo')->sort()->values()->all())->toBe(collect([$vecchio, $nuovo])->sort()->values()->all())
        ->and(collect($indirizzi)->pluck('proposto')->unique()->all())->toBe([true]);

    $conta = fn (string $indirizzo) => notificheRicevutaA($indirizzo)->count();
    $invia = fn (array $tipi) => $this->actingAs($u)->postJson(annullataUrl('invia-una', $s, ['scrittura' => $x->id]),
        ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => $tipi]], 'bollo' => 'non_dovuta'])->assertOk();
    $chiaveDi = fn (string $indirizzo) => collect($indirizzi)->firstWhere('indirizzo', $indirizzo)['chiave'];
    [$primaVecchio, $primaNuovo] = [$conta($vecchio), $conta($nuovo)];

    // Spuntata solo la casella del nuovo indirizzo: va solo a quello.
    $invia([$chiaveDi($nuovo)]);
    expect($conta($nuovo))->toBe($primaNuovo + 1)->and($conta($vecchio))->toBe($primaVecchio);

    // Spuntate tutte e due: vanno a tutti e due.
    $invia($chiavi->all());
    expect($conta($nuovo))->toBe($primaNuovo + 2)->and($conta($vecchio))->toBe($primaVecchio + 1);

    // Un tipo da solo vale per gli indirizzi di quel tipo, come prima: tutti e due.
    $invia(['email']);
    expect($conta($nuovo))->toBe($primaNuovo + 3)->and($conta($vecchio))->toBe($primaVecchio + 2);

    // Una casella che la ricevuta non ha è rifiutata.
    $this->actingAs($u)->postJson(annullataUrl('invia-una', $s, ['scrittura' => $x->id]),
        ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email|altro@esterno.example']]], 'bollo' => 'non_dovuta'])->assertStatus(422);
});

test('«Non serve comunicarla»: la ricevuta dell\'incasso annullato esce dall\'avviso, resta fra le emissioni dell\'incasso con chi e quando, e non cambia l\'originale', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $uno = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $due = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50);
    $this->actingAs($u)->post(annullataUrl('stampa', $s), ['incassi' => [$uno->id, $due->id], 'bollo' => 'non_dovuta'])->assertOk();
    $originale = RegistroInvio::where('documentabile_id', $uno->id)->sole();
    $this->travel(1)->minutes();
    annullataStorna($uno, $s);
    annullataStorna($due, $s);
    expect(annullataDaComunicare($this, $s, $u))->toBe(collect([$uno->id, $due->id])->sort()->values()->all());

    $risposta = $this->actingAs($u)->postJson(annullataUrl('non-serve-comunicarla', $s, ['scrittura' => $uno->id]))->assertOk()->json();
    $riga = RegistroInvio::where('documentabile_id', $uno->id)->where('stato', 'non_da_comunicare')->sole();
    $emissioni = $this->actingAs($u)->getJson(annullataUrl('opzioni', $s, ['scrittura' => $uno->id]))->assertOk()->json('emissioni');

    expect($risposta['segnata'])->toBeTrue()
        ->and(annullataDaComunicare($this, $s, $u))->toBe([$due->id])
        ->and($riga->canale)->toBe('nessuno')
        ->and($riga->emesso_da)->toBe($u->id)
        ->and($riga->emesso_il)->not->toBeNull()
        ->and($riga->tipo)->toBe('ricevuta')
        ->and(collect($emissioni)->pluck('stato')->all())->toBe(['non_da_comunicare', 'stampata'])
        ->and(collect($emissioni)->firstWhere('stato', 'non_da_comunicare')['canale'])->toBe('nessuno');

    // L'originale è sempre la prima emissione uscita: la riga non lo cambia, e una ristampa dopo è ancora una copia con la data di prima.
    $copia = $this->actingAs($u)->getJson(annullataUrl('opzioni', $s, ['scrittura' => $uno->id]))->assertOk()->json('copia');
    expect($copia['del'])->toBe($originale->emesso_il->copy()->setTimezone('Europe/Rome')->format('Y-m-d'));

    // Premuto di nuovo non scrive un'altra riga; e l'avviso non torna anche se l'incasso annullato viene toccato dopo.
    $this->actingAs($u)->postJson(annullataUrl('non-serve-comunicarla', $s, ['scrittura' => $uno->id]))->assertOk();
    ScritturaContabile::where('id', $uno->id)->update(['updated_at' => now()->addDays(2)]);
    expect(RegistroInvio::where('stato', 'non_da_comunicare')->count())->toBe(1)
        ->and(annullataDaComunicare($this, $s, $u))->toBe([$due->id]);
});

test('«Non serve comunicarla» si dice solo di una ricevuta annullata che era uscita; di un altro condominio è 404', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $altro = scenarioRicevutaIncasso('Condominio Corso Italia');
    $u = amministratoreRicevute();
    $attivo = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $mai = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50);
    $this->actingAs($u)->post(annullataUrl('stampa', $s), ['incassi' => [$attivo->id], 'bollo' => 'non_dovuta'])->assertOk();
    $this->travel(1)->minutes();
    annullataStorna($mai, $s);

    $this->actingAs($u)->postJson(annullataUrl('non-serve-comunicarla', $s, ['scrittura' => $attivo->id]))->assertStatus(422);
    $this->actingAs($u)->postJson(annullataUrl('non-serve-comunicarla', $s, ['scrittura' => $mai->id]))->assertStatus(422);
    $this->actingAs($u)->postJson(annullataUrl('non-serve-comunicarla', $altro, ['scrittura' => $attivo->id]))->assertStatus(404);

    expect(RegistroInvio::where('stato', 'non_da_comunicare')->count())->toBe(0);
});
