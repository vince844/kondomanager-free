<?php

/**
 * Cosa resta scoperto: la riga del Wallet legata al passaggio e la compilazione automatica del modale dalla fermata (non ci sono); un
 * saldo intestato a una persona di un altro condominio (la regola è del pannello, che gestisce l'intera installazione); il selettore del
 * pannello dei saldi, che è in `SaldiDetailPanelPassaggi.test.ts`.
 *
 * La strada a mano di una fermata, dal server: chi ha soltanto la riga di un passaggio nel Wallet può avere il suo saldo manuale. È la regola
 * che le guide dicono: «un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio».
 *
 * Il caso: Ines esce il 24/1 con «Scrivi» (coppia di € 355,52 a Ugo), Luca entra l'8/2, Ugo vende a Elsa il 12/3 (coppia di € 51,55),
 * Luca finisce il 18/4. I giorni dal 18/4 sono di Elsa, ma la coppia è a nome di Ugo: la fermata dice € 268,20 a carico di Ugo e il
 * Wallet, con € 268,20 a credito di Ugo e € 268,20 a debito di Elsa. Ugo ha già due righe di passaggio, Elsa una.
 *
 * `CreateSaldoRequest` conta solo i saldi che un passaggio non ha scritto (`->whereNull('subentro_id')`): i due POST sono accettati, e il
 * secondo saldo manuale della stessa persona sulla stessa gestione no, con il testo di sempre sul campo `anagrafica_id`. Una riga di
 * passaggio resta una gamba di una coppia: non si modifica e non si cancella da sola, anche con la riga manuale accanto.
 *
 * Il prossimo piano assorbe tutte le righe sommate: per Ugo la riga di passaggio di € 355,52, quella di − € 51,55 e il suo saldo manuale di
 * − € 268,20 fanno € 35,77; per Elsa € 51,55 e € 268,20 fanno € 319,75; Ines resta a − € 355,52.
 */

use App\Actions\PianoRate\GenerateSaldiAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;

require_once __DIR__.'/Beta51PassaggiScenari.php';

beforeEach(function () {
    b51aAccesso($this);
});

/** Le righe di saldo dell'unità: persona, importo, e se le ha scritte un passaggio. */
function b52WalletSaldiDellUnita(array $s): array
{
    $nomi = Anagrafica::pluck('nome', 'id');

    return Saldo::where('immobile_id', $s['unita']->id)->orderBy('id')->get()
        ->map(fn ($x) => [$nomi[$x->anagrafica_id] ?? null, (int) $x->saldo_iniziale, $x->subentro_id !== null ? 'passaggio' : 'manuale'])->all();
}

/** Il POST del pannello dei saldi: «Soggetto specifico», la persona, l'importo con il segno della colonna. */
function b52WalletPostSaldo($test, array $s, string $chi, int $centesimi)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $centesimi,
    ]);
}

it('controprova — chi ha già la riga di un passaggio nel Wallet può registrare il suo saldo manuale: i POST della strada a mano sono accettati', function () {
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    expect(b52WalletSaldiDellUnita($s))->toBe([['Ines', -35552, 'passaggio'], ['Ugo', 35552, 'passaggio'], ['Ugo', -5155, 'passaggio'], ['Elsa', 5155, 'passaggio']]);

    b52WalletPostSaldo($this, $s, 'Ugo', -26820)->assertSessionHasNoErrors();
    b52WalletPostSaldo($this, $s, 'Elsa', 26820)->assertSessionHasNoErrors();

    expect(b52WalletSaldiDellUnita($s))->toBe([
        ['Ines', -35552, 'passaggio'], ['Ugo', 35552, 'passaggio'], ['Ugo', -5155, 'passaggio'], ['Elsa', 5155, 'passaggio'],
        ['Ugo', -26820, 'manuale'], ['Elsa', 26820, 'manuale'],
    ]);
});

it('controprova — il secondo saldo manuale della stessa persona sulla stessa gestione si rifiuta, con il testo di sempre sul campo della persona', function () {
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    b52WalletPostSaldo($this, $s, 'Ugo', -26820)->assertSessionHasNoErrors();

    b52WalletPostSaldo($this, $s, 'Ugo', -100)->assertSessionHasErrors(['anagrafica_id' => 'Esiste già un saldo per questa anagrafica in questa gestione.']);

    expect(Saldo::where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Ugo']->id)->whereNull('subentro_id')->count())->toBe(1);
});

it('controprova — una gamba della coppia non si modifica e non si cancella da sola, nemmeno con la riga manuale accanto', function () {
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    b52WalletPostSaldo($this, $s, 'Ugo', -26820)->assertSessionHasNoErrors();
    $gamba = Saldo::where('immobile_id', $s['unita']->id)->whereNotNull('subentro_id')->orderBy('id')->firstOrFail();

    // La pagina parla con Inertia (redirect con gli errori in sessione); chi chiama in JSON ha 422.
    $modifica = $this->actingAs($this->user)->patchJson(route('admin.gestionale.saldi.update', [$s['c'], $gamba]), ['saldo_iniziale' => $gamba->saldo_iniziale + 100, 'gestione_id' => $gamba->gestione_id]);
    $modifica->assertStatus(422)->assertJsonValidationErrors('saldo');
    expect($modifica->json('errors.saldo.0'))->toContain('Questa riga fa parte del conguaglio di un passaggio di titolarità')->toContain('non si modifica da sola');

    $cancella = $this->actingAs($this->user)->deleteJson(route('admin.gestionale.saldi.destroy', [$s['c'], $gamba]));
    $cancella->assertStatus(422)->assertJsonValidationErrors('saldo');
    expect($cancella->json('errors.saldo.0'))->toContain('non si cancella da sola');

    $dallaPagina = $this->actingAs($this->user)->patch(route('admin.gestionale.saldi.update', [$s['c'], $gamba]), ['saldo_iniziale' => $gamba->saldo_iniziale + 100, 'gestione_id' => $gamba->gestione_id]);
    $dallaPagina->assertSessionHasErrors('saldo');

    expect(Saldo::find($gamba->id))->not->toBeNull()
        ->and((int) Saldo::find($gamba->id)->saldo_iniziale)->toBe((int) $gamba->saldo_iniziale);
});

it('controprova — dopo i due saldi manuali la fermata non propone più il Wallet a Ugo e a Elsa: hanno già un saldo che un passaggio non ha scritto', function () {
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    b52WalletPostSaldo($this, $s, 'Ugo', -26820)->assertSessionHasNoErrors();
    b52WalletPostSaldo($this, $s, 'Elsa', 26820)->assertSessionHasNoErrors();

    $conUnSaldo = \App\Services\Subentro\FermataDopoScrivi::conUnSaldo([(int) $s['persone']['Ugo']->id, (int) $s['persone']['Elsa']->id], (int) $s['g']->id, (int) $s['unita']->id, (int) $s['e']->id);

    expect($conUnSaldo)->toEqualCanonicalizing([(int) $s['persone']['Ugo']->id, (int) $s['persone']['Elsa']->id]);
});

it('controprova — la pagina dei saldi porta a ogni riga `subentro_id` e `e_conguaglio`: è ciò che il selettore legge', function () {
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    b52WalletPostSaldo($this, $s, 'Ugo', -26820)->assertSessionHasNoErrors();

    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']->id]))->assertOk()->viewData('page')['props'];
    $righe = collect(collect($pagina['immobili'])->firstWhere('id', $s['unita']->id)['saldi']);

    expect($righe->map(fn ($r) => [$r['anagrafica']['nome'], $r['subentro_id'] !== null, $r['e_conguaglio']])->all())
        ->toBe([['Ines', true, true], ['Ugo', true, true], ['Ugo', true, true], ['Elsa', true, true], ['Ugo', false, false]]);
});

it('controprova — il prossimo piano assorbe la riga di passaggio e il saldo manuale della stessa persona, sommate: Ugo € 35,77, Elsa € 319,75, Ines − € 355,52', function () {
    $s = b51aDl7ConVendita($this);
    b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    b52WalletPostSaldo($this, $s, 'Ugo', -26820)->assertSessionHasNoErrors();
    b52WalletPostSaldo($this, $s, 'Elsa', 26820)->assertSessionHasNoErrors();

    $distribuzione = (new GenerateSaldiAction())->execute(PianoRate::where('gestione_id', $s['g']->id)->firstOrFail(), $s['g']);
    $nomi = Anagrafica::pluck('nome', 'id');
    $perPersona = [];
    foreach ($distribuzione as $anagraficaId => $perUnita) {
        foreach ($perUnita as $dati) {
            $perPersona[$nomi[$anagraficaId] ?? $anagraficaId] = ($perPersona[$nomi[$anagraficaId] ?? $anagraficaId] ?? 0) + $dati['importo'];
        }
    }
    ksort($perPersona);

    // Ugo: +35552 − 5155 − 26820 = 3577. Elsa: +5155 + 26820 = 31975. Ines: − 35552.
    expect($perPersona)->toBe(['Elsa' => 31975, 'Ines' => -35552, 'Ugo' => 3577]);
});
