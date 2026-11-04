<?php

/**
 * Coda 464 (3) — il rifiuto dell'eliminazione di una gestione non manda a eliminare dalla sezione «Saldi Iniziali» saldi che lì non compaiono.
 *
 * `GestioneController::destroy` rifiuta quando la gestione ha saldi iniziali. La frase di prima diceva che i pregressi dei condòmini «si eliminano dalla
 * sezione «Saldi»», e che i debiti verso i fornitori «nella sezione «Saldi» non compaiono». La sezione («Saldi Iniziali», `SaldoInizialeController::index`)
 * mostra i saldi del solo esercizio aperto (`HasEsercizio::getEsercizioCorrente`): con il saldo in un esercizio chiuso la frase indicava un'azione che la
 * pagina non permette. La frase di ora è quella del rifiuto dell'eliminazione di un esercizio (Coda 275): dice quanti saldi sono, di che genere, e che cosa
 * la sezione mostra, e non indica un'azione.
 *
 * Le prove partono dalla rotta che la pagina delle gestioni chiama (`DataTableRowActions.vue`), con la gestione agganciata a due esercizi, il 2026 aperto e il
 * 2025 chiuso.
 */

use App\Models\Anagrafica;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Saldo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Un condominio con il 2026 aperto, il 2025 chiuso e la gestione agganciata a tutti e due. */
function giro3GestioneInDueEsercizi(): array
{
    [$condominio, $aperto, $gestione, $fornitore, , , $immobile] = setupContabile();

    $chiuso = Esercizio::factory()->create([
        'condominio_id' => $condominio->id, 'nome' => 'Esercizio 2025',
        'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso',
    ]);
    legaAEsercizio($chiuso, $gestione->id);

    return compact('condominio', 'aperto', 'chiuso', 'gestione', 'fornitore', 'immobile');
}

function giro3SaldoDelCondomino(array $ctx, Esercizio $esercizio, int $centesimi): Saldo
{
    return Saldo::create([
        'esercizio_id' => $esercizio->id, 'condominio_id' => $ctx['condominio']->id,
        'anagrafica_id' => Anagrafica::factory()->create()->id, 'immobile_id' => $ctx['immobile'],
        'gestione_id' => $ctx['gestione']->id, 'saldo_iniziale' => $centesimi, 'origine' => 'manuale',
    ]);
}

function giro3DebitoVersoIlFornitore(array $ctx, Esercizio $esercizio, int $centesimi): Saldo
{
    return Saldo::create([
        'esercizio_id' => $esercizio->id, 'condominio_id' => $ctx['condominio']->id,
        'fornitore_id' => $ctx['fornitore']->id, 'gestione_id' => $ctx['gestione']->id,
        'saldo_iniziale' => $centesimi, 'origine' => 'manuale',
    ]);
}

/** La richiesta che la pagina delle gestioni manda, dall'esercizio aperto: la risposta e il messaggio che l'amministratore legge. */
function giro3EliminaLaGestione(object $prova, array $ctx): array
{
    $risposta = $prova->actingAs($prova->user)->from('/gestioni')
        ->delete(route('admin.gestionale.esercizi.gestioni.destroy', [$ctx['condominio'], $ctx['aperto'], $ctx['gestione']]));

    return [$risposta, (string) session('message.message')];
}

/** Gli id dei saldi che la sezione «Saldi Iniziali» mostra, con l'esercizio su cui lavora. */
function giro3SaldiVistiNellaSezione(object $prova, array $ctx): array
{
    $pagina = $prova->actingAs($prova->user)->get(route('admin.gestionale.saldi.index', $ctx['condominio']))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('gestionale/saldi/SaldiList')->where('esercizio.id', $ctx['aperto']->id));

    return collect($pagina->viewData('page')['props']['immobili'])->flatMap(fn ($i) => collect($i['saldi'])->pluck('id'))->all();
}

test('Coda 464 — il saldo di un condòmino in un esercizio chiuso: il rifiuto non manda alla sezione «Saldi», che quel saldo non mostra', function () {
    $ctx = giro3GestioneInDueEsercizi();
    $saldo = giro3SaldoDelCondomino($ctx, $ctx['chiuso'], 35552);

    [$risposta, $messaggio] = giro3EliminaLaGestione($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    // Il fatto su cui poggia la frase: la sezione lavora sull'esercizio aperto e il saldo del 2025 non c'è.
    expect(giro3SaldiVistiNellaSezione($this, $ctx))->not->toContain($saldo->id)
        ->and($messaggio)->not->toContain('si eliminano dalla sezione «Saldi»')
        ->and($messaggio)->not->toContain('pregressi dei condòmini')
        ->and($messaggio)->toBe(
            'Impossibile eliminare: la gestione contiene saldi iniziali pregressi: '
            ."1 saldo di un condòmino (la sezione «Saldi Iniziali» mostra solo quelli dell'esercizio aperto)."
        )
        // Il rifiuto non cancella niente.
        ->and(Gestione::find($ctx['gestione']->id))->not->toBeNull()
        ->and(Saldo::find($saldo->id)?->saldo_iniziale)->toBe(35552);
});

test('Coda 464 — con più saldi dei condòmini il conteggio è al plurale', function () {
    $ctx = giro3GestioneInDueEsercizi();
    giro3SaldoDelCondomino($ctx, $ctx['chiuso'], 10000);
    giro3SaldoDelCondomino($ctx, $ctx['aperto'], -2500);
    giro3SaldoDelCondomino($ctx, $ctx['chiuso'], 700);

    [$risposta, $messaggio] = giro3EliminaLaGestione($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toBe(
        'Impossibile eliminare: la gestione contiene saldi iniziali pregressi: '
        ."3 saldi dei condòmini (la sezione «Saldi Iniziali» mostra solo quelli dell'esercizio aperto)."
    );
});

test('Coda 464 — il debito verso un fornitore: il rifiuto lo dice con il nome vero della sezione, e senza saldi di condòmini che non ci sono', function () {
    $ctx = giro3GestioneInDueEsercizi();
    $debito = giro3DebitoVersoIlFornitore($ctx, $ctx['aperto'], 48000);

    [$risposta, $messaggio] = giro3EliminaLaGestione($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    // Neanche un debito dell'esercizio aperto compare nella sezione: la pagina carica i saldi passando dagli immobili e un debito verso un fornitore non ne ha uno.
    expect(giro3SaldiVistiNellaSezione($this, $ctx))->not->toContain($debito->id)
        ->and($messaggio)->toBe(
            'Impossibile eliminare: la gestione contiene saldi iniziali pregressi: '
            .'1 debito verso un fornitore (nella situazione debitoria del fornitore, non nella sezione «Saldi Iniziali»).'
        )
        ->and($messaggio)->not->toContain('condòmin')
        ->and($messaggio)->not->toContain('nella sezione «Saldi» non compaiono');
});

test('Coda 464 — i debiti verso i fornitori al plurale, insieme ai saldi dei condòmini', function () {
    $ctx = giro3GestioneInDueEsercizi();
    giro3SaldoDelCondomino($ctx, $ctx['chiuso'], 35552);
    giro3DebitoVersoIlFornitore($ctx, $ctx['aperto'], 48000);
    giro3DebitoVersoIlFornitore($ctx, $ctx['chiuso'], 12000);

    [$risposta, $messaggio] = giro3EliminaLaGestione($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    expect($messaggio)->toBe(
        'Impossibile eliminare: la gestione contiene saldi iniziali pregressi: '
        ."1 saldo di un condòmino (la sezione «Saldi Iniziali» mostra solo quelli dell'esercizio aperto) "
        .'e 2 debiti verso fornitori (nella situazione debitoria del fornitore, non nella sezione «Saldi Iniziali»).'
    );
});

test('Coda 464 — il saldo di un condòmino nell\'esercizio aperto: la sezione lo mostra, e la frase resta vera', function () {
    $ctx = giro3GestioneInDueEsercizi();
    $saldo = giro3SaldoDelCondomino($ctx, $ctx['aperto'], 20000);

    [$risposta, $messaggio] = giro3EliminaLaGestione($this, $ctx);

    $risposta->assertSessionHas('message.type', 'error');
    expect(giro3SaldiVistiNellaSezione($this, $ctx))->toContain($saldo->id)
        ->and($messaggio)->toContain("1 saldo di un condòmino (la sezione «Saldi Iniziali» mostra solo quelli dell'esercizio aperto)")
        ->and($messaggio)->not->toContain('si eliminano');
});

test('Coda 464 — controprova: senza saldi la gestione si elimina, e il rifiuto dei saldi non scatta', function () {
    $ctx = giro3GestioneInDueEsercizi();

    [$risposta, $messaggio] = giro3EliminaLaGestione($this, $ctx);

    $risposta->assertSessionHas('message.type', 'success');
    expect($messaggio)->not->toContain('Impossibile eliminare')
        ->and(Gestione::find($ctx['gestione']->id))->toBeNull();
});
