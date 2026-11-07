<?php

use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * L'anteprima del PDF e la prova: si guarda la ricevuta senza emetterla. L'anteprima non scrive niente nel registro, quindi non brucia
 * l'originale (la prima emissione che esce): la ricevuta stampata per guardarla non fa uscire come «Copia» quella che va alla persona. Il PDF
 * dice in testa che non è una ricevuta, e così quello della prova «Mandane una a me».
 *
 * Che cosa NON copre: l'aspetto del PDF (verifica a video).
 */
function anteprimaPdf($test, object $s, array $campi, $utente = null)
{
    return ($test->actingAs($utente ?? amministratoreRicevute()))
        ->post(route('admin.gestionale.ricevute.pdf-anteprima', ['condominio' => $s->condominio->id]), $campi);
}

/** I dati con cui il programma ha reso il modello del PDF, una volta per ogni PDF fatto. */
function catturaIPdfRenduti(): \ArrayObject
{
    $resi = new \ArrayObject;
    View::composer('pdf.gestionale.ricevute_incasso', function ($vista) use ($resi) {
        $resi[] = $vista->getData();
    });

    return $resi;
}

test('l\'anteprima apre il PDF senza registrarlo: né una riga, né un originale, e non chiede il bollo', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    $senzaBollo = anteprimaPdf($this, $s, ['incassi' => [$incasso->id]], $u);
    $conBollo = anteprimaPdf($this, $s, ['incassi' => [$incasso->id], 'bollo' => 'assolta', 'nota' => 'Prova'], $u);

    $senzaBollo->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $conBollo->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with($senzaBollo->getContent(), '%PDF'))->toBeTrue()
        ->and($conBollo->headers->get('Content-Disposition'))->toContain('anteprima-ricevuta')
        ->and(RegistroInvio::count())->toBe(0);

    // La ricevuta che esce davvero subito dopo è l'originale: l'anteprima non l'ha bruciato.
    $this->actingAs($u)->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))
        ->assertJson(['copia' => null]);
    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id], 'bollo' => 'assolta'])->assertOk();
    expect(RegistroInvio::sole()->dati)->not->toHaveKey('copia_del');
});

test('l\'anteprima si ferma al tetto di un PDF, come la stampa; un incasso di un altro condominio, da solo o in una lista, è un 404', function () {
    $s = scenarioRicevutaIncasso();
    $altro = scenarioRicevutaIncasso('Condominio Corso Italia');
    $mio = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $suo = incassaPerRicevuta($altro, $altro->anna, [[$altro->anna1, 150.00]], '2026-02-01', 150.00);

    anteprimaPdf($this, $s, ['incassi' => [$suo->id]])->assertNotFound();
    anteprimaPdf($this, $s, ['incassi' => [$mio->id, $suo->id]])->assertNotFound();
    anteprimaPdf($this, $s, ['incassi' => range(1000, 1199)])->assertNotFound();
    anteprimaPdf($this, $s, ['incassi' => range(1000, 1200)])->assertStatus(422)->assertSee('al massimo 200', false);
    expect(RegistroInvio::count())->toBe(0);
});

test('l\'anteprima di una ricevuta già emessa esce come copia, con la data dell\'originale, e dice in testa che non è una ricevuta', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id], 'bollo' => 'non_dovuta'])->assertOk();
    $resi = catturaIPdfRenduti();

    anteprimaPdf($this, $s, ['incassi' => [$incasso->id], 'bollo' => 'assolta', 'modo_bollo' => 'virtuale', 'estremi_bollo' => 'aut. 12'], $u)->assertOk();

    $voce = $resi[0]['ricevute'][0];
    $oggi = now()->setTimezone(config('app.user_timezone'))->format('d/m/Y');
    expect($voce['etichetta'])->toBe('ANTEPRIMA')
        ->and($voce['copia_del'])->toBe($oggi)
        ->and($voce['bollo'])->toBe('assolta')
        ->and($voce['modo_bollo'])->toBe('virtuale')
        ->and(RegistroInvio::count())->toBe(1);
});

test('il modello della ricevuta dice in testa che l\'anteprima e la prova non sono una ricevuta, e non le dice emesse', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $voce = ['contenuto' => app(RicevutaIncasso::class)->per($incasso->fresh()), 'bollo' => null, 'nota' => null, 'emessa_il' => '05/11/2026', 'indirizzo_per_posta' => false];
    $html = fn (array $extra) => View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s->condominio, 'ricevute' => [$voce + $extra]])->render();

    expect($html(['etichetta' => 'ANTEPRIMA']))->toContain('ANTEPRIMA — non è una ricevuta')
        ->and($html(['etichetta' => 'ANTEPRIMA']))->toContain('Non emessa')
        ->and($html(['etichetta' => 'ANTEPRIMA']))->not->toContain('Emessa il')
        ->and($html(['etichetta' => 'ANTEPRIMA']))->toContain('Da scegliere')
        ->and($html(['etichetta' => 'PROVA']))->toContain('PROVA — non è una ricevuta')
        ->and($html([]))->not->toContain('non è una ricevuta')
        ->and($html([]))->toContain('Emessa il');
});

test('il PDF di «Mandane una a me» porta PROVA in testa, e quello dell\'invio vero no', function () {
    config(['mail.default' => 'array']);
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $resi = catturaIPdfRenduti();

    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.prova', ['condominio' => $s->condominio->id]),
        ['incassi' => [$incasso->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta'])->assertOk();
    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.invia', ['condominio' => $s->condominio->id]),
        ['incassi' => [$incasso->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta'])->assertOk();

    expect($resi[0]['ricevute'][0]['etichetta'])->toBe('PROVA')
        ->and($resi[1]['ricevute'][0]['etichetta'] ?? null)->toBeNull();
});

test('la ricevuta di un incasso annullato dice che le quote non risultano più pagate con questo incasso, non che il versamento non c\'è', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    app(StornoIncassoRateAction::class)->execute($incasso->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio, \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    $voce = ['contenuto' => app(RicevutaIncasso::class)->per($incasso->fresh()), 'bollo' => 'non_dovuta', 'nota' => null, 'emessa_il' => '05/11/2026', 'indirizzo_per_posta' => false];

    $html = View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s->condominio, 'ricevute' => [$voce]])->render();

    expect($html)->toContain('INCASSO ANNULLATO')
        ->and($html)->toContain('le quote qui sotto non risultano più pagate con questo incasso')
        ->and($html)->not->toContain('Il versamento non risulta più registrato');
});

test('il testo della conferma dello storno non prescrive: dice che la ricevuta si ristampa o si rimanda, e che se dirlo alla persona lo decide l\'amministratore', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $u = amministratoreRicevute();
    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id], 'bollo' => 'assolta'])->assertOk();

    $frase = $this->actingAs($u)->getJson(route('admin.gestionale.movimenti-rate.storno.anteprima', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))->json('ricevuta');

    expect($frase)->toContain('«INCASSO ANNULLATO»')
        ->and($frase)->toContain('lo decidi tu')
        ->and($frase)->not->toContain('da consegnare');
});
