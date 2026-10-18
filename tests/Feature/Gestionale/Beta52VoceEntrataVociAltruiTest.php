<?php

/**
 * Una voce di un altro condominio non si legge, non si nomina e non si propone: le difese del servizio, del modulo e della regolazione.
 *
 * Le richieste rifiutano già l'id di una voce che non sta nel condominio (`exists` con il perimetro). Qui si provano le difese che stanno dietro: il servizio
 * «Sposta spesa» chiamato da solo non confronta il tipo con una voce altrui (e non la nomina nel rifiuto), una voce che non esiste non dà un errore di sistema nel
 * controllo del tipo, il modulo di registrazione non propone come ultima voce di un fornitore una voce di un altro condominio (il varco di `update` fino alla 1.9.1
 * poteva agganciare una riga a una voce altrui), e la regolazione immediata su una voce di entrata di un altro condominio prende un solo rifiuto, quello del
 * perimetro, senza il nome della voce.
 *
 * Fonte dell'atteso: i conti a mano di ogni test e la regola sulla sicurezza del flusso (ogni id dal browser sta nel condominio dell'indirizzo).
 *
 * Cosa resta scoperto: il rifiuto del tipo fra voci dello stesso condominio (`Beta52VoceEntrataSpostaSpesaTest`); le fatture e le coperture con una voce altrui
 * (`Beta52VoceEntrataPerimetroFattureTest`).
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Services\Gestionale\BudgetMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

it('il servizio «Sposta spesa» chiamato da solo non confronta il tipo con una voce di un altro condominio e non la nomina', function () {
    $s = be52Scenario($this);
    $altro = be52Scenario($this);
    $voceAltrui = be52Voce($this, $altro, $altro['pc'], 'Proventi del vicino', 'entrata', '100,00');
    $piano = be52PianoDallaRotta($this, $s);

    $messaggio = '';
    try {
        app(BudgetMovementService::class)->moveBudget($piano, $s['spesa']->id, $voceAltrui->id, 5000, 'Prova', $this->user->id);
    } catch (ValidationException $e) {
        $messaggio = json_encode($e->errors(), JSON_UNESCAPED_UNICODE);
    } catch (\Throwable) {
        // la destinazione altrui non è nel piano: ciò che succede dopo il controllo del tipo non è l'oggetto di questa prova
    }

    expect($messaggio)->not->toContain('Proventi del vicino')
        ->and($messaggio)->not->toContain('il budget si sposta solo fra voci dello stesso tipo');
});

it('il servizio «Sposta spesa» con una destinazione che non esiste non dà un errore di sistema nel controllo del tipo', function () {
    $s = be52Scenario($this);
    $piano = be52PianoDallaRotta($this, $s);

    $eccezione = null;
    try {
        app(BudgetMovementService::class)->moveBudget($piano, $s['spesa']->id, 999999, 5000, 'Prova', $this->user->id);
    } catch (\Throwable $e) {
        $eccezione = $e;
    }

    expect($eccezione)->not->toBeInstanceOf(\ErrorException::class)
        ->and($eccezione)->not->toBeInstanceOf(\TypeError::class)
        ->and((string) $eccezione?->getMessage())->not->toContain('Attempt to read property');
});

it('il modulo di registrazione non propone come ultima voce di un fornitore quella di un altro condominio', function () {
    $s = be52Scenario($this);
    $altro = be52Scenario($this);
    $f = be52Fornitore();
    $fattura = be52FatturaDalServizio($s, $f, $s['spesa']->id, 100.00, 'FT-VARCO');
    DB::table('righe_fattura')->where('fattura_passiva_id', $fattura->id)->update(['conto_id' => $altro['spesa']->id]);

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.fatture.create', [$s['c']]))->assertOk()->viewData('page')['props'];

    expect(collect($props['fornitori'])->firstWhere('id', $f->id)['ultimo_conto_id'])->toBeNull();
});

it('controprova — il modulo di registrazione propone come ultima voce quella di spesa del proprio condominio', function () {
    $s = be52Scenario($this);
    $f = be52Fornitore();
    be52FatturaDalServizio($s, $f, $s['spesa']->id, 100.00, 'FT-MIA');

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.fatture.create', [$s['c']]))->assertOk()->viewData('page')['props'];

    expect(collect($props['fornitori'])->firstWhere('id', $f->id)['ultimo_conto_id'])->toBe($s['spesa']->id);
});

it('la regolazione immediata su una voce di entrata di un altro condominio prende un solo rifiuto e non nomina la voce altrui', function () {
    $s = be52Scenario($this);
    $altro = be52Scenario($this);
    $voceAltrui = be52Voce($this, $altro, $altro['pc'], 'Proventi del vicino', 'entrata', '100,00');

    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), ['gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $voceAltrui->id,
        'cassa_id' => $s['cassa']->id, 'data_operazione' => '2026-03-10', 'causale' => 'Prova', 'importo' => 300.00]);
    $errori = be52Errori();

    expect(count($errori['conto_id'] ?? []))->toBe(1)
        ->and(json_encode($errori, JSON_UNESCAPED_UNICODE))->not->toContain('Proventi del vicino')
        ->and(DB::table('righe_scritture')->where('voce_spesa_id', $voceAltrui->id)->count())->toBe(0);
});
