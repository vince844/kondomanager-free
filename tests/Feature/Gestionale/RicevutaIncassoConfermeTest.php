<?php

use App\Models\Gestionale\RegistroInvio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * La conferma di «Invia di nuovo» dice la verità: l'indirizzo a cui la ricevuta va adesso (quello di oggi dell'anagrafica per primaria, secondaria
 * e PEC; quello scritto a mano com'era) e lo stato del bollo della riga che si rimanda, che nella pagina delle ricevute è l'ultima email e non
 * l'ultima emissione di qualunque canale.
 *
 * Che cosa NON copre: il testo della conferma (nei test JavaScript).
 */
function confermeUrl(string $nome, object $s, array $altro = []): string
{
    return route('admin.gestionale.ricevute.' . $nome, ['condominio' => $s->condominio->id] + $altro);
}

function confermeRighe($test, object $s, $utente): \Illuminate\Support\Collection
{
    return collect($test->actingAs($utente)->get(confermeUrl('index', $s, ['ricevuta' => 'tutte']))->viewData('page')['props']['incassi']['data']);
}

test('dopo una correzione dell\'email la conferma della pagina dice l\'indirizzo di oggi, e la ricevuta parte proprio a quello (Anna e Persona 01)', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    [$id1] = incassiDiPiuPersoneConDueIndirizzi($s, 1);
    $p1 = \App\Models\Anagrafica::where('nome', 'Persona 01')->firstOrFail();
    $this->actingAs($u)->postJson(confermeUrl('invia', $s), ['incassi' => [$a->id, $id1], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta', 'bollo_sotto' => 'non_dovuta'])->assertOk();
    RegistroInvio::query()->update(['stato' => 'inviata']);
    $s->anna->update(['email' => 'anna.corretta@prova.it']);
    $p1->update(['email' => 'p1.corretta@prova.it']);

    $righe = confermeRighe($this, $s, $u);
    $nellaConferma = $righe->map(fn ($r) => $r['ultima_email']['manda_a'])->sort()->values()->all();
    foreach ($righe as $r) {
        $this->actingAs($u)->postJson(confermeUrl('invia-di-nuovo', $s, ['invio' => $r['ultima_email_id']]))->assertOk();
    }
    $partiti = RegistroInvio::orderByDesc('id')->take(2)->pluck('destinatario')->sort()->values()->all();

    expect($nellaConferma)->toBe(['anna.corretta@prova.it', 'p1.corretta@prova.it'])
        ->and($partiti)->toBe($nellaConferma);
});

test('la conferma della pagina legge l\'ultima email, non l\'ultima emissione: dopo una stampa con un altro bollo dice quello che «Invia di nuovo» rimanda (Anna e Persona 01)', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    [$id1] = incassiDiPiuPersoneConDueIndirizzi($s, 1);
    $this->actingAs($u)->postJson(confermeUrl('invia', $s), ['incassi' => [$a->id, $id1], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta', 'modo_bollo' => 'virtuale', 'bollo_sotto' => 'non_dovuta'])->assertOk();
    RegistroInvio::query()->update(['stato' => 'inviata']);
    $this->travel(1)->minutes();
    $this->actingAs($u)->post(confermeUrl('stampa', $s), ['incassi' => [$a->id, $id1], 'bollo_sopra' => 'da_assolvere', 'bollo_sotto' => 'da_assolvere'])->assertOk();

    $esito = [];
    foreach (confermeRighe($this, $s, $u) as $r) {
        $this->actingAs($u)->postJson(confermeUrl('invia-di-nuovo', $s, ['invio' => $r['ultima_email_id']]))->assertOk();
        $esito[] = [$r['ultima']['canale'], $r['ultima_email']['canale'], $r['ultima_email']['bollo'], RegistroInvio::orderByDesc('id')->first()->dati['bollo']];
    }
    sort($esito);

    // L'ultima emissione è la stampa; la conferma legge l'email, con il suo bollo.
    expect($esito)->toBe([['stampa', 'email', 'assolta', 'assolta'], ['stampa', 'email', 'non_dovuta', 'non_dovuta']]);
});

test('la riga scritta a mano si rimanda a quell\'indirizzo; se la persona non ha più l\'indirizzo del tipo la conferma non ha un indirizzo da dire; la pagina dell\'incasso dice lo stesso', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $this->actingAs($u)->postJson(confermeUrl('invia-una', $s, ['scrittura' => $a->id]), ['destinatari' => [], 'altro_indirizzo' => 'mano@esterno.example', 'bollo' => 'assolta'])->assertOk();
    $this->actingAs($u)->postJson(confermeUrl('invia-una', $s, ['scrittura' => $a->id]), ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email_secondaria']]], 'bollo' => 'assolta'])->assertOk();
    RegistroInvio::query()->update(['stato' => 'inviata']);
    $show = fn () => collect($this->actingAs($u)->get(route('admin.gestionale.movimenti-rate.show', ['condominio' => $s->condominio->id, 'scrittura' => $a->id]))->viewData('page')['props']['emissioni'])
        ->mapWithKeys(fn ($e) => [$e['destinatario'] => $e['manda_a']]);

    $vecchia = $s->anna->email_secondaria;
    $s->anna->update(['email_secondaria' => 'anna.nuova.casa@prova.it']);
    expect($show()->all())->toEqual(['mano@esterno.example' => 'mano@esterno.example', $vecchia => 'anna.nuova.casa@prova.it']);

    $s->anna->update(['email_secondaria' => null]);
    $riga = RegistroInvio::where('destinatario', $vecchia)->sole();
    expect($show()[$vecchia])->toBeNull();
    $this->actingAs($u)->postJson(confermeUrl('invia-di-nuovo', $s, ['invio' => $riga->id]))->assertStatus(422);
});
