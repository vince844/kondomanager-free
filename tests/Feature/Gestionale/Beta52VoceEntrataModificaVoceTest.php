<?php

/**
 * La modifica di una voce rifiuta il meno con il testo del suo tipo, lascia passare i capitoli e non riapre la porta della spesa negativa.
 *
 * `Beta52VoceEntrataImportoNegativoTest` prova la creazione e la modifica di una spesa. Qui stanno i punti che la completano. Il rifiuto di un importo
 * negativo in modifica sceglie il testo dal tipo che la richiesta manda: una voce di entrata prende «si scrive senza il segno», una spesa prende il testo della spesa.
 * Un capitolo non ha importo e il suo campo non si controlla: scritto con un meno resta a zero senza errori. Una voce già negativa (dato delle versioni precedenti) si
 * rinomina e si porta a «Entrata» senza toccare l'importo, ma non si porta a «Spesa» con l'importo negativo invariato: sarebbe una spesa negativa nuova, quella che la
 * creazione rifiuta, e il rifiuto dice di scegliere «Entrata» o di scrivere l'importo senza il segno.
 *
 * Fonte dell'atteso: i conti a mano di ogni test e il testo della decisione 87 (punti 1 e 2).
 *
 * Cosa resta scoperto: la schermata (`ModalModificaContoSegno.test.ts`); la creazione (`Beta52VoceEntrataImportoNegativoTest`).
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Models\Gestionale\Conto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
});

it('portare una voce di entrata a un importo negativo in modifica prende il testo dell\'entrata', function () {
    $s = be52Scenario($this);

    be52UpdateVoce($this, $s, $s['entrata'], ['nome' => 'Affitto antenna', 'tipo' => 'entrata', 'importo' => '-100,00']);

    expect(be52Errori()['importo'][0] ?? null)->toBe(BE52_TESTO_ENTRATA_CON_MENO)
        ->and((int) Conto::find($s['entrata']->id)->importo)->toBe(24000);
});

it('un capitolo aggiornato con un importo negativo non si rifiuta e resta a zero: il capitolo non ha importo', function () {
    $s = be52Vuoto($this);
    be52PostVoce($this, $s, ['nome' => 'Manutenzioni', 'importo' => '0,00', 'tabella_millesimale_id' => null, 'isCapitolo' => true]);
    $capitolo = Conto::where('piano_conto_id', $s['pc']->id)->where('nome', 'Manutenzioni')->firstOrFail();

    be52UpdateVoce($this, $s, $capitolo, ['nome' => 'Manutenzioni', 'importo' => '-10,00', 'isCapitolo' => true, 'tabella_millesimale_id' => null])->assertSessionHasNoErrors();

    expect((int) Conto::find($capitolo->id)->importo)->toBe(0);
});

it('una voce di entrata già negativa non si porta a «Spesa» con l\'importo invariato: si rifiuta con il testo della spesa e resta un\'entrata', function () {
    $s = be52Scenario($this);
    $vecchia = be52Voce($this, $s, $s['pc'], 'Rimborso', 'entrata', '240,00');
    DB::table('conti')->where('id', $vecchia->id)->update(['importo' => -24000]); // il dato che le versioni precedenti lasciavano

    be52UpdateVoce($this, $s, $vecchia, ['nome' => 'Rimborso', 'tipo' => 'spesa', 'importo' => '-240,00']);

    expect(be52Errori()['importo'][0] ?? null)->toBe(BE52_TESTO_SPESA_NEGATIVA)
        ->and(Conto::find($vecchia->id)->only(['tipo']))->toBe(['tipo' => 'entrata'])
        ->and((int) Conto::find($vecchia->id)->importo)->toBe(-24000);
});

it('controprova — una voce di entrata già negativa si rinomina e resta un\'entrata con l\'importo invariato', function () {
    $s = be52Scenario($this);
    $vecchia = be52Voce($this, $s, $s['pc'], 'Rimborso', 'entrata', '240,00');
    DB::table('conti')->where('id', $vecchia->id)->update(['importo' => -24000]);

    be52UpdateVoce($this, $s, $vecchia, ['nome' => 'Rimborso assicurazione', 'tipo' => 'entrata', 'importo' => '-240,00'])->assertSessionHasNoErrors();

    expect(Conto::find($vecchia->id)->only(['nome', 'tipo']))->toBe(['nome' => 'Rimborso assicurazione', 'tipo' => 'entrata'])
        ->and((int) Conto::find($vecchia->id)->importo)->toBe(-24000);
});

it('controprova — una voce di entrata già negativa si porta a «Spesa» scrivendo l\'importo senza il segno', function () {
    $s = be52Scenario($this);
    $vecchia = be52Voce($this, $s, $s['pc'], 'Rimborso', 'entrata', '240,00');
    DB::table('conti')->where('id', $vecchia->id)->update(['importo' => -24000]);

    be52UpdateVoce($this, $s, $vecchia, ['nome' => 'Rimborso', 'tipo' => 'spesa', 'importo' => '240,00'])->assertSessionHasNoErrors();

    expect(Conto::find($vecchia->id)->only(['tipo']))->toBe(['tipo' => 'spesa'])
        ->and((int) Conto::find($vecchia->id)->importo)->toBe(24000);
});

it('controprova — una voce di spesa già negativa resta una spesa con l\'importo invariato quando si rinomina', function () {
    $s = be52Scenario($this);
    $vecchia = be52Voce($this, $s, $s['pc'], 'Rimborso', 'spesa', '240,00');
    DB::table('conti')->where('id', $vecchia->id)->update(['importo' => -24000]);

    be52UpdateVoce($this, $s, $vecchia, ['nome' => 'Rimborso assicurazione', 'tipo' => 'spesa', 'importo' => '-240,00'])->assertSessionHasNoErrors();

    expect(Conto::find($vecchia->id)->only(['nome', 'tipo']))->toBe(['nome' => 'Rimborso assicurazione', 'tipo' => 'spesa']);
});
