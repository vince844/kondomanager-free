<?php

/**
 * 1.11.0-beta.51 — la Coda 247: l'anteprima dell'inizio di una locazione rifiuta quello che la registrazione rifiuta, con lo stesso testo
 * e sullo stesso campo.
 *
 * Il difetto, misurato su TEST (591b2c11): Ugo, Ines inquilina al 100 %, rate emesse fino al 30/6. L'anteprima dell'inizio di Luca al 100 % dall'1/7
 * dà 200 (nessun errore), il pannello propone il passaggio; la registrazione dà 422 «Interno 1 — la somma delle quote per inquilino non può
 * superare 100: il 1 luglio 2026 farebbe 200.». Idem sul box: Ines al 50 % sul box e Luca al 100 % (la quota delle pertinenze è 100, come
 * `applicaAllaPertinenza`) fanno 150, e l'anteprima non lo dice. Cambia la Request (`AnteprimaPassaggioRequest::withValidator`), che
 * `store()` usa a sua volta: la guardia vale anche alla registrazione, prima del lock; l'azione la ripete sotto lock e resta la rete.
 *
 * Il conto a mano: la somma delle quote per inquilino dopo l'inizio non può superare 100. Ines 100 + Luca 100 = 200; Ines 60 + Luca 40 = 100
 * (ammesso), Ines 60 + Luca 41 = 101 (rifiutato); sul box Ines 50 + Luca 100 = 150.
 *
 * La sovrapposizione della stessa persona (Luca ha già una riga d'inquilino sull'unità o sul box) è rifiutata dalla Request con il campo che il
 * modulo mostra (`anagrafica_entrante_id`). Con una riga in corso la prende la regola «è già inquilino di questa unità», prima delle guardie di
 * `GuardieTitolarita` (O-247-CAMPO); con una riga già finita, che si sovrappone all'inizio, la prendono le guardie, che nominano il campo
 * `anagrafica_id` e la Request lo porta su `anagrafica_entrante_id` (O-247-CAMPO-FINITA).
 *
 * Che cosa NON copre: gli altri tipi di passaggio (cambio, vendita, costituzione, riserva, successione, accrescimento): il test differenziale
 * anteprima = scrittura su un dato che sfora è della 1.11.1 per la decisione 85.6, e ogni tipo che dà un rosso apre una Coda; la pagina Vue
 * (mostra già gli errori su `quota` e su `pertinenze`); MySQL.
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    b51aAccesso($this);
});

/**
 * L'anteprima e la registrazione dello stesso modulo dalle rotte vere: [stato dell'anteprima, errori dell'anteprima, stato della registrazione,
 * errori della registrazione]. La registrazione porta la presa d'atto del cancello, così il rifiuto è quello delle quote e non della spunta.
 */
function b51aAnteprimaESalvataggio($test, array $s, array $dati): array
{
    $a = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati);
    $r = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati + ['ho_letto' => true, 'nota_cancello' => 'Contratto letto, rate controllate bene']);

    return [$a->status(), $a->status() >= 400 ? ($a->json('errors') ?? []) : [], $r->status(), $r->status() >= 400 ? ($r->json('errors') ?? []) : []];
}

/** Ugo, Ines inquilina con la quota data (di partenza 100 %), rate emesse fino al 30/6, e Luca come persona del condominio. */
function b51aInizioSopra($test, array $opzioni = []): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], $opzioni);
    b51aPersone($s, ['Luca']);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');

    return $s;
}

it('O-247 — l\'inizio di Luca al 100 % dall\'1/7 con Ines inquilina al 100 %: l\'anteprima rifiuta con 422, sul campo quota e con il testo della registrazione («farebbe 200»)', function () {
    $s = b51aInizioSopra($this);
    [$statoAnteprima, $erroriAnteprima, $statoStore, $erroriStore] = b51aAnteprimaESalvataggio($this, $s, fds50ModuloInizio($s, 'Luca', '2026-07-01'));

    expect($statoStore)->toBe(422)
        ->and($erroriStore['quota'][0] ?? null)->toBe('Interno 1 — la somma delle quote per inquilino non può superare 100: il 1 luglio 2026 farebbe 200.')
        ->and($statoAnteprima)->toBe(422, 'L\'anteprima propone un inizio che la registrazione rifiuta.')
        ->and($erroriAnteprima)->toBe($erroriStore);
});

it('O-247 — il rifiuto non scrive niente: dopo l\'anteprima rifiutata e la registrazione rifiutata Luca non è inquilino e le quote emesse sono quelle di prima', function () {
    $s = b51aInizioSopra($this);
    $prima = Subentro::where('immobile_id', $s['unita']->id)->count();
    b51aAnteprimaESalvataggio($this, $s, fds50ModuloInizio($s, 'Luca', '2026-07-01'));

    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe($prima)
        ->and(\Illuminate\Support\Facades\DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Luca']->id)->count())->toBe(0);
});

it('sentinella — O-247: al limite (Ines 60 % e Luca 40 %, somma 100) l\'anteprima e la registrazione accettano', function () {
    $s = b51aInizioSopra($this, ['inquilini' => [['Ines', 60]]]);
    [$statoAnteprima, , $statoStore] = b51aAnteprimaESalvataggio($this, $s, ['quota' => 40] + fds50ModuloInizio($s, 'Luca', '2026-07-01'));

    expect([$statoAnteprima, $statoStore])->toBe([200, 302]);
});

it('O-247 — differenziale sulla soglia: Luca al 41 % (Ines 60 %, somma 101) è rifiutato dall\'anteprima come dalla registrazione, con lo stesso testo', function () {
    $s = b51aInizioSopra($this, ['inquilini' => [['Ines', 60]]]);
    [$statoAnteprima, $erroriAnteprima, $statoStore, $erroriStore] = b51aAnteprimaESalvataggio($this, $s, ['quota' => 41] + fds50ModuloInizio($s, 'Luca', '2026-07-01'));

    expect($statoStore)->toBe(422)
        ->and($erroriStore['quota'][0] ?? null)->toBe('Interno 1 — la somma delle quote per inquilino non può superare 100: il 1 luglio 2026 farebbe 101.')
        ->and($statoAnteprima)->toBe(422, 'L\'anteprima accetta una quota che la registrazione rifiuta.')
        ->and($erroriAnteprima)->toBe($erroriStore);
});

it('O-247 — con il box spuntato: Ines al 50 % sul box e Luca al 100 % (la quota delle pertinenze) fanno 150, e l\'anteprima rifiuta con «Box 12 — … farebbe 150» sul campo pertinenze, come la registrazione', function () {
    // Fonte: la quota sulla pertinenza è 100 (`applicaAllaPertinenza`: l'inizio non ha un uscente); Ines 50 + Luca 100 = 150. L'unità principale
    // non ha inquilini, quindi lì la somma è 100 e va bene.
    $s = b51aInizioSopra($this, ['inquilini' => [], 'box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 50]]]);
    [$statoAnteprima, $erroriAnteprima, $statoStore, $erroriStore] = b51aAnteprimaESalvataggio($this, $s, ['pertinenze' => [(int) $s['box']->id]] + fds50ModuloInizio($s, 'Luca', '2026-07-01'));

    $messaggio = 'Box 12 — la somma delle quote per inquilino non può superare 100: il 1 luglio 2026 farebbe 150.';
    expect($statoStore)->toBe(422)
        ->and(collect($erroriStore)->flatten()->all())->toBe([$messaggio])
        ->and($statoAnteprima)->toBe(422, 'L\'anteprima propone un inizio sul box che la registrazione rifiuta.')
        ->and(collect($erroriAnteprima)->flatten()->all())->toBe([$messaggio])
        // L'errore del box sta sul campo delle pertinenze (il modulo lo mostra lì), e la registrazione dice lo stesso campo.
        ->and(array_keys($erroriAnteprima))->toBe(['pertinenze'])
        ->and(array_keys($erroriStore))->toBe(array_keys($erroriAnteprima));
});

it('sentinella — O-247-CAMPO: la sovrapposizione della stessa persona (Luca ha già una riga d\'inquilino sull\'unità, anche futura, o sul box) è già rifiutata dall\'anteprima sul campo che il modulo mostra, con il testo della registrazione', function (string $caso) {
    // La regola «è già inquilino di questa unità» della Request prende la riga in corso, prima delle guardie di `GuardieTitolarita`.
    if ($caso === 'futura') {
        $s = b51aInizioSopra($this, ['inquilini' => []]);
        \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Luca']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino',
            'quota' => 50, 'attivo' => true, 'data_inizio' => '2026-09-01', 'data_fine' => '2026-11-30', 'created_at' => now(), 'updated_at' => now()]);
        $dati = ['quota' => 50] + fds50ModuloInizio($s, 'Luca', '2026-07-01');
        $atteso = 'Luca è già inquilino di questa unità: non può entrare anche come inquilino.';
    } else {
        $s = b51aInizioSopra($this, ['inquilini' => [], 'box' => [['Ugo', 'proprietario', 100], ['Luca', 'inquilino', 40]]]);
        $dati = ['pertinenze' => [(int) $s['box']->id]] + fds50ModuloInizio($s, 'Luca', '2026-07-01');
        $atteso = 'Box 12: Luca è già inquilino di questa pertinenza: non può entrare anche come inquilino.';
    }
    [$statoAnteprima, $erroriAnteprima, $statoStore, $erroriStore] = b51aAnteprimaESalvataggio($this, $s, $dati);

    expect([$statoAnteprima, $statoStore])->toBe([422, 422])
        ->and($erroriAnteprima)->toBe(['anagrafica_entrante_id' => [$atteso]])
        ->and($erroriStore)->toBe($erroriAnteprima);
})->with(['sull\'unità, riga futura' => ['futura'], 'sul box' => ['box']]);

it('O-247-CAMPO-FINITA — Luca ha una riga d\'inquilino già finita (1/5–31/8) che si sovrappone all\'inizio dall\'1/7: l\'anteprima e la registrazione rifiutano sullo stesso campo del modulo, `anagrafica_entrante_id`, con lo stesso testo', function () {
    // Fonte: la regola «è già inquilino di questa unità» guarda le righe in corso oggi (13/10/2026): la riga finita il 31/8 non c'è, e la
    // sovrapposizione la dice `GuardieTitolarita` con la sua chiave `anagrafica_id`, che il modulo non mostra: la Request la porta sul campo
    // `anagrafica_entrante_id`. Conto a mano: la riga di Luca copre 1/5–31/8, la nuova parte l'1/7 senza fine, e dall'1/7 al 31/8 sono
    // 62 giorni in comune (31 + 31). Le quote sono 50 e 50, somma 100: nessuno sforo, l'errore è uno solo.
    $s = b51aInizioSopra($this, ['inquilini' => []]);
    \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Luca']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino',
        'quota' => 50, 'attivo' => true, 'data_inizio' => '2026-05-01', 'data_fine' => '2026-08-31', 'created_at' => now(), 'updated_at' => now()]);
    [$statoAnteprima, $erroriAnteprima, $statoStore, $erroriStore] = b51aAnteprimaESalvataggio($this, $s, ['quota' => 50] + fds50ModuloInizio($s, 'Luca', '2026-07-01'));

    expect([$statoAnteprima, $statoStore])->toBe([422, 422])
        ->and(array_keys($erroriAnteprima))->toBe(['anagrafica_entrante_id'], 'L\'errore di sovrapposizione non sta sul campo che il modulo mostra.')
        ->and($erroriAnteprima['anagrafica_entrante_id'][0])->toContain('Interno 1 — ')->toContain('1 maggio 2026')->toContain('31 agosto 2026')
        ->and($erroriStore)->toBe($erroriAnteprima);
});

it('un corpo costruito a mano — la data dell\'atto o la scadenza del contratto mandate come elenco danno un 422 sul campo, mai una pagina 500, in anteprima e in registrazione, e non scrivono niente', function (array $cambia, string $campo) {
    // La regola `after:decorrenza` della scadenza passava l'elenco a `Carbon::parse` (TypeError, pagina 500 con i percorsi dei file a APP_DEBUG acceso).
    $s = b51aInizioSopra($this, ['inquilini' => []]);
    $prima = Subentro::count();

    [$stA, $erA, $stR, $erR] = b51aAnteprimaESalvataggio($this, $s, array_replace(fds50ModuloInizio($s, 'Luca', '2026-07-01'), $cambia));

    expect([$stA, $stR])->toBe([422, 422])
        ->and(array_keys($erA))->toContain($campo)
        ->and(array_keys($erR))->toContain($campo)
        ->and(Subentro::count())->toBe($prima);
})->with([
    'la data dell\'atto come elenco, con la scadenza' => [['decorrenza' => ['2026-07-01']], 'decorrenza'],
    'la data dell\'atto come mappa, con la scadenza' => [['decorrenza' => ['dal' => '2026-07-01']], 'decorrenza'],
    'la scadenza come elenco' => [['data_fine_locazione' => ['2030-06-30']], 'data_fine_locazione'],
]);

it('un corpo costruito a mano, controllo — la data dell\'atto e la scadenza normali si registrano come prima', function () {
    $s = b51aInizioSopra($this, ['inquilini' => []]);

    [$stA, , $stR] = b51aAnteprimaESalvataggio($this, $s, fds50ModuloInizio($s, 'Luca', '2026-07-01'));

    expect([$stA, $stR])->toBe([200, 302]);
});
