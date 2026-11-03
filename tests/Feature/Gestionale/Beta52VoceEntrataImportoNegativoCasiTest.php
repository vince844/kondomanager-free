<?php

/**
 * Il rifiuto dell'importo negativo nei casi di bordo: la posizione nella lista, le liste malformate, il capitolo e il piano approvato.
 *
 * Il rifiuto del meno sull'importo che il piano forza sulle voci (`capitoli_config.*.importo`) vale per ogni posizione della lista, non solo per la prima,
 * e non trasforma una richiesta malformata in un errore di sistema: una configurazione che non è una lista, o un importo che è una lista, restano un
 * errore sul campo. Un capitolo non ha importo (il valore scritto si scarta e si crea a zero), quindi il meno non lo riguarda. Con il piano approvato una
 * voce già negativa si rinomina senza toccare l'importo, mentre un importo cambiato in negativo prende il testo del segno e non il blocco delle rate
 * approvate: chi legge «Modifica inibita» penserebbe di dover aspettare un piano, e invece deve togliere il meno.
 *
 * Fonte dell'atteso: i conti a mano di ogni test e il testo del rifiuto (una voce di spesa non ha importo negativo, il segno lo dà il tipo).
 *
 * Cosa resta scoperto: l'importo con il meno nei sottoconti (passano dalla stessa regola della voce); la schermata
 * (`PianiRateNewErroriCapitoli.test.ts`, `ModalNuovoContoSegno.test.ts`); l'importo negativo di una voce già dentro un piano con le rate emesse
 * (il blocco delle rate emesse viene prima, e resta com'è).
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Enums\StatoPianoRate;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
});

/** Il corpo di `piani-rate.store` per un piano «lascia vuoto», con le aggiunte date. */
function be52CorpoPiano(array $s, array $extra = []): array
{
    return array_merge(['tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Prova', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 1], $extra);
}

// ─── La configurazione del piano ────────────────────────────────────────────────────────────────────────────────

it('un importo negativo alla seconda voce della configurazione si rifiuta sul suo indice e non nasce nessun piano', function () {
    $s = be52Scenario($this);

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), be52CorpoPiano($s, [
        'capitoli_config' => [['id' => $s['spesa']->id, 'importo' => '300,00'], ['id' => $s['entrata']->id, 'importo' => '-100,00']]]));
    $errori = be52Errori();

    expect(array_keys($errori))->toBe(['capitoli_config.1.importo'])
        ->and($errori['capitoli_config.1.importo'][0])->toBe(BE52_TESTO_CONFIG_NEGATIVA)
        ->and(PianoRate::count())->toBe(0);
});

it('controprova — una configurazione malformata resta un errore sul campo e non diventa un errore di sistema', function (Closure $extra, string $campo) {
    $s = be52Scenario($this);

    $risposta = $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), be52CorpoPiano($s, $extra($s)));

    expect($risposta->getStatusCode())->toBe(302)
        ->and(array_key_exists($campo, be52Errori()))->toBeTrue('l\'errore sta sul campo malformato')
        ->and(PianoRate::count())->toBe(0);
})->with([
    'la configurazione è una stringa' => [fn (array $s) => ['capitoli_config' => 'x'], 'capitoli_config'],
    'l\'importo è una lista' => [fn (array $s) => ['capitoli_config' => [['id' => $s['spesa']->id, 'importo' => ['-1']]]], 'capitoli_config.0.importo'],
]);

// ─── Il capitolo ────────────────────────────────────────────────────────────────────────────────────────────────

it('controprova — un capitolo scritto con un importo negativo si crea a zero: il capitolo non ha importo', function () {
    $s = be52Vuoto($this);

    be52PostVoce($this, $s, ['nome' => 'Manutenzioni', 'importo' => '-10,00', 'tabella_millesimale_id' => null, 'isCapitolo' => true])->assertSessionHasNoErrors();

    $capitolo = Conto::where('piano_conto_id', $s['pc']->id)->where('nome', 'Manutenzioni')->firstOrFail();
    expect((int) $capitolo->importo)->toBe(0)
        ->and((bool) $capitolo->is_capitolo)->toBeTrue();
});

// ─── Il piano approvato ─────────────────────────────────────────────────────────────────────────────────────────

it('controprova — con il piano approvato una voce già negativa si rinomina senza toccare l\'importo', function () {
    [$s, $piano, $voce] = be52PianoVecchio($this);
    DB::table('piani_rate')->where('id', $piano->id)->update(['stato' => StatoPianoRate::APPROVATO->value]);

    be52UpdateVoce($this, $s, $voce, ['nome' => 'Rimborso assicurazione', 'importo' => '-240,00']);

    expect(be52Errori())->toBe([])
        ->and(Conto::find($voce->id)->nome)->toBe('Rimborso assicurazione')
        ->and((int) Conto::find($voce->id)->importo)->toBe(-24000);
});

it('con il piano approvato un importo cambiato in negativo prende il testo del segno e non il blocco delle rate approvate', function () {
    $s = be52Scenario($this);
    $piano = be52PianoDallaRotta($this, $s);
    DB::table('piani_rate')->where('id', $piano->id)->update(['stato' => StatoPianoRate::APPROVATO->value]);

    be52UpdateVoce($this, $s, $s['spesa'], ['importo' => '-300,00']);
    $errori = be52Errori();

    expect(array_keys($errori))->toBe(['importo'])
        ->and($errori['importo'][0])->toBe(BE52_TESTO_SPESA_NEGATIVA)
        ->and((int) Conto::find($s['spesa']->id)->importo)->toBe(120000);
});
