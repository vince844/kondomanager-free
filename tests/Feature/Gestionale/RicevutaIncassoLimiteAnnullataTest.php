<?php

use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Ricevute\LimiteAltroIndirizzo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * Il limite di dieci al minuto sugli indirizzi scritti a mano vale anche per la ricevuta di un incasso annullato mandata di nuovo dalla sua
 * finestra all'indirizzo scritto a mano a cui era partita: la finestra lo propone con la sua chiave (`altro|…`), e la richiesta non porta un
 * «altro indirizzo». Un indirizzo della persona non conta.
 *
 * Che cosa NON copre: la frase nella finestra (nei test JavaScript).
 */
function limiteAnnullataUrl(string $nome, object $s, array $altro = []): string
{
    return route('admin.gestionale.ricevute.' . $nome, ['condominio' => $s->condominio->id] + $altro);
}

function limiteAnnullataStorna(ScritturaContabile $incasso, object $s): void
{
    app(StornoIncassoRateAction::class)->execute(ScritturaContabile::with(['righe', 'quotePagate', 'figlie'])->find($incasso->id), $s->condominio, \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
}

test('la ricevuta annullata rimandata all\'indirizzo scritto a mano a cui era partita conta nel limite: dieci al minuto, poi 429 con la frase', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    [$p1] = incassiDiPiuPersoneConDueIndirizzi($s, 1);
    foreach ([[$a->id, 'mano.anna@esterno.example'], [$p1, 'mano.p1@esterno.example']] as [$id, $indirizzo]) {
        $this->actingAs($u)->postJson(limiteAnnullataUrl('invia-una', $s, ['scrittura' => $id]), ['destinatari' => [], 'altro_indirizzo' => $indirizzo, 'bollo' => 'non_dovuta'])->assertOk();
    }
    RegistroInvio::query()->update(['stato' => 'inviata']);
    // Il minuto dei due invii è passato: il conto riparte.
    $this->travel(2)->minutes();
    limiteAnnullataStorna($a, $s);
    limiteAnnullataStorna(ScritturaContabile::find($p1), $s);

    $chiave = fn (int $id) => $this->actingAs($u)->getJson(limiteAnnullataUrl('opzioni', $s, ['scrittura' => $id]))->assertOk()->json('destinatari.0');
    $dA = $chiave($a->id);
    $dP = $chiave($p1);
    expect($dA['indirizzi'][0]['chiave'])->toBe('altro|mano.anna@esterno.example');

    $risposte = collect(range(1, 14))->map(function ($n) use ($u, $s, $a, $p1, $dA, $dP) {
        [$id, $d] = $n % 2 ? [$a->id, $dA] : [$p1, $dP];

        return $this->actingAs($u)->postJson(limiteAnnullataUrl('invia-una', $s, ['scrittura' => $id]),
            ['destinatari' => [['anagrafica_id' => $d['anagrafica_id'], 'tipi' => [$d['indirizzi'][0]['chiave']]]], 'bollo' => 'non_dovuta']);
    });
    $stati = $risposte->map(fn ($r) => $r->status());

    expect($stati->take(10)->unique()->values()->all())->toBe([200])
        ->and($stati->slice(10)->unique()->values()->all())->toBe([429])
        ->and($risposte->last()->json('message'))->toBe(LimiteAltroIndirizzo::FRASE)
        ->and((int) $risposte->last()->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and(notificheRicevutaA('mano.anna@esterno.example')->count() + notificheRicevutaA('mano.p1@esterno.example')->count())->toBe(12);
});

test('con il tipo da solo («altro») ogni indirizzo scritto a mano conta; l\'indirizzo della persona di un annullato no', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $this->actingAs($u)->postJson(limiteAnnullataUrl('invia-una', $s, ['scrittura' => $a->id]),
        ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'altro_indirizzo' => 'mano.uno@esterno.example', 'bollo' => 'non_dovuta'])->assertOk();
    $this->actingAs($u)->postJson(limiteAnnullataUrl('invia-una', $s, ['scrittura' => $a->id]),
        ['destinatari' => [], 'altro_indirizzo' => 'mano.due@esterno.example', 'bollo' => 'non_dovuta'])->assertOk();
    RegistroInvio::query()->update(['stato' => 'inviata']);
    $this->travel(2)->minutes();
    limiteAnnullataStorna($a, $s);

    // Ogni richiesta con il tipo «altro» manda ai due indirizzi scritti a mano: due posti del limite. Cinque richieste fanno dieci, la sesta no.
    $altro = collect(range(1, 6))->map(fn () => $this->actingAs($u)->postJson(limiteAnnullataUrl('invia-una', $s, ['scrittura' => $a->id]),
        ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['altro']]], 'bollo' => 'non_dovuta'])->status());
    // All'indirizzo della persona si manda ancora: non conta.
    $allaPersona = collect(range(1, 3))->map(fn () => $this->actingAs($u)->postJson(limiteAnnullataUrl('invia-una', $s, ['scrittura' => $a->id]),
        ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'non_dovuta'])->status());

    expect($altro->take(5)->unique()->values()->all())->toBe([200])
        ->and($altro->last())->toBe(429)
        ->and(notificheRicevutaA('mano.uno@esterno.example'))->toHaveCount(6)
        ->and(notificheRicevutaA('mano.due@esterno.example'))->toHaveCount(6)
        ->and($allaPersona->unique()->values()->all())->toBe([200])
        ->and(notificheRicevutaA($s->anna->email))->toHaveCount(4);
});
