<?php

use App\Models\Gestionale\RegistroInvio;
use App\Services\Gestionale\Ricevute\LimiteAltroIndirizzo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * Il limite di dieci al minuto per utente vale per ogni strada con cui il programma manda una ricevuta a un indirizzo scritto a mano: la
 * finestra dell'incasso, «Invia di nuovo» e «Riprova le non partite». Un invio all'indirizzo di una persona non lo sente. Quando scatta, chi
 * lavora legge una frase in italiano, non quella del framework.
 *
 * Che cosa NON copre: l'aspetto della frase nella finestra (nei test JavaScript).
 */
function limiteUrl(string $nome, object $s, array $altro = []): string
{
    return route('admin.gestionale.ricevute.' . $nome, ['condominio' => $s->condominio->id] + $altro);
}

function limiteAIndirizzoScrittoAMano($test, object $s, $utente, int $incasso, string $indirizzo)
{
    return $test->actingAs($utente)->postJson(limiteUrl('invia-una', $s, ['scrittura' => $incasso]), ['destinatari' => [], 'altro_indirizzo' => $indirizzo, 'bollo' => 'assolta']);
}

test('il limite conta per utente su due incassi, e quando scatta la frase è in italiano', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $altroUtente = amministratoreRicevute();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    [$id1] = incassiDiPiuPersoneConDueIndirizzi($s, 1);

    $stati = collect(range(1, 11))->map(fn ($n) => limiteAIndirizzoScrittoAMano($this, $s, $u, $n % 2 ? $a->id : $id1, "esterno{$n}@esterno.example")->status());
    $dellAltro = limiteAIndirizzoScrittoAMano($this, $s, $altroUtente, $id1, 'x@esterno.example')->status();
    $risposta = limiteAIndirizzoScrittoAMano($this, $s, $u, $a->id, 'y@esterno.example')->assertStatus(429);

    expect($stati->take(10)->unique()->values()->all())->toBe([200])
        ->and($stati->last())->toBe(429)
        ->and($dellAltro)->toBe(200)
        ->and($risposta->json('message'))->toBe(LimiteAltroIndirizzo::FRASE)
        ->and($risposta->json('message'))->not->toContain('Too Many Attempts')->toContain('scritti a mano');
});

test('la frase del limite è la stessa nel server e nella pagina: quella che la finestra scrive per un 429 senza messaggio del server', function () {
    $pagina = file_get_contents(resource_path('js/lib/gestionale/incassi/ricevute.ts'));

    expect(str_replace("\\'", "'", $pagina))->toContain("FRASE_LIMITE_ALTRO_INDIRIZZO = '" . LimiteAltroIndirizzo::FRASE . "'");
});

test('«Invia di nuovo» di una ricevuta mandata a un indirizzo scritto a mano conta nello stesso limite; quello all\'indirizzo di una persona no', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $this->actingAs($u)->postJson(limiteUrl('invia-una', $s, ['scrittura' => $a->id]), ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'assolta'])->assertOk();
    limiteAIndirizzoScrittoAMano($this, $s, $u, $a->id, 'esterno1@esterno.example')->assertOk();
    RegistroInvio::query()->update(['stato' => 'inviata']);
    $aMano = RegistroInvio::where('destinatario', 'esterno1@esterno.example')->sole();
    $allaPersona = RegistroInvio::where('destinatario', $s->anna->email)->sole();

    // Uno è già contato; nove «Invia di nuovo» arrivano a dieci, il decimo no.
    $stati = collect(range(1, 10))->map(fn () => $this->actingAs($u)->postJson(limiteUrl('invia-di-nuovo', $s, ['invio' => $aMano->id]))->status());
    $oltre = $this->actingAs($u)->postJson(limiteUrl('invia-di-nuovo', $s, ['invio' => $aMano->id]))->assertStatus(429);
    $allIndirizzoDiUnaPersona = collect(range(1, 12))->map(fn () => $this->actingAs($u)->postJson(limiteUrl('invia-di-nuovo', $s, ['invio' => $allaPersona->id]))->status());

    expect($stati->take(9)->unique()->values()->all())->toBe([200])
        ->and($stati->last())->toBe(429)
        ->and($oltre->json('message'))->toBe(LimiteAltroIndirizzo::FRASE)
        ->and(notificheRicevutaA('esterno1@esterno.example'))->toHaveCount(10)
        ->and($allIndirizzoDiUnaPersona->unique()->values()->all())->toBe([200])
        ->and(notificheRicevutaA($s->anna->email))->toHaveCount(13);
});

test('«Riprova le non partite» manda a indirizzi scritti a mano al massimo dieci al minuto: gli altri restano da riprovare, e la risposta lo dice', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    foreach (range(1, 12) as $n) {
        RegistroInvio::create([
            'condominio_id' => $s->condominio->id, 'anagrafica_id' => $s->anna->id, 'tipo' => 'ricevuta', 'canale' => 'email',
            'documentabile_type' => \App\Models\Gestionale\ScritturaContabile::class, 'documentabile_id' => $a->id, 'numero' => $a->numero_protocollo,
            'destinatario' => "mano{$n}@esterno.example", 'dati' => ['bollo' => 'assolta', 'tipo_indirizzo' => 'altro', 'soglia' => 'sopra'],
            'stato' => 'non_partita', 'errore' => 'Rifiutato', 'emesso_il' => now(),
        ]);
    }
    $riprova = fn () => $this->actingAs($u)->postJson(limiteUrl('riprova', $s))->assertOk()->json();
    $partiti = fn () => collect(range(1, 12))->filter(fn ($n) => notificheRicevutaA("mano{$n}@esterno.example")->isNotEmpty())->count();

    $prima = $riprova();
    expect($partiti())->toBe(10)
        ->and($prima['messaggi'])->toBe(10)
        ->and($prima['limite_altro_indirizzo'])->toBe(2);

    // Passato il minuto, ripartono quelli rimasti.
    $this->travel(61)->seconds();
    $seconda = $riprova();
    expect($partiti())->toBe(12)
        ->and($seconda['messaggi'])->toBe(2)
        ->and($seconda['limite_altro_indirizzo'])->toBe(0);
});
