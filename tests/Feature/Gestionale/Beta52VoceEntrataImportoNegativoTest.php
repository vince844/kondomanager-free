<?php

/**
 * L'importo di una voce si scrive senza il segno: il segno lo dà il tipo.
 *
 * Una voce di spesa a −€ 240,00 si addebita in positivo (il motore fa `spesa ? abs(x) : −abs(x)`): il piano passa da € 960,00 a € 1.440,00,
 * e la distinta delle spese somma la voce con il suo segno, quindi i due documenti si contraddicono. Le porte sono tre: la creazione, la modifica
 * e l'importo che il piano forza sulla voce (`capitoli_config.*.importo`). Il server rifiuta il meno su qualunque voce (spesa o entrata) dicendo di
 * scegliere «Entrata» o di scrivere la cifra senza il segno; la modifica rifiuta solo un importo cambiato in negativo, così una voce già negativa
 * (dato delle versioni precedenti) si può ancora rinominare o portare a «Entrata».
 *
 * Fonte dell'atteso: la riga 248 di `roadmap.md` («rifiutare l'importo negativo dicendo di scegliere Entrata»), il Wallet dei saldi
 * («il segno non si digita mai, lo decide la colonna») e i conti a mano di ogni test.
 *
 * Cosa resta scoperto: il fermo del piano che contiene già una voce negativa (`Beta52VoceEntrataFermoTest`); le liste malformate, il capitolo, il piano approvato
 * e l'importo negativo alla posizione due della configurazione (`Beta52VoceEntrataImportoNegativoCasiTest`); il testo del tipo in modifica, il capitolo aggiornato e
 * la voce di entrata già negativa portata a «Spesa» (`Beta52VoceEntrataModificaVoceTest`); la schermata (`ModalNuovoContoSegno.test.ts`,
 * `ModalModificaContoSegno.test.ts`).
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
});

function be52CreaVoce($test, array $s, string $nome, string $tipo, string $importo)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]), [
        'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => $tipo, 'importo' => $importo, 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ]);
}

// ─── Creazione ──────────────────────────────────────────────────────────────────────────────────────────────────

it('una voce di spesa a −€ 240,00 si rifiuta e non entra nel piano dei conti', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');

    be52CreaVoce($this, $s, 'Rimborso', 'spesa', '-240,00');
    $errori = be52Errori();

    expect(Conto::where('piano_conto_id', $s['pc']->id)->count())->toBe(1)
        ->and($errori['importo'][0] ?? null)->toBe(BE52_TESTO_SPESA_NEGATIVA);
});

it('una voce di entrata con il meno si rifiuta, scritta senza il segno si crea', function () {
    $s = be52Vuoto($this);

    be52CreaVoce($this, $s, 'Affitto antenna', 'entrata', '-240,00');
    $errori = be52Errori();
    $dopoIlRifiuto = Conto::where('piano_conto_id', $s['pc']->id)->count();

    be52CreaVoce($this, $s, 'Affitto antenna', 'entrata', '240,00')->assertSessionHasNoErrors();

    expect($dopoIlRifiuto)->toBe(0)
        ->and($errori['importo'][0] ?? null)->toBe(BE52_TESTO_ENTRATA_CON_MENO)
        ->and((int) Conto::where('nome', 'Affitto antenna')->value('importo'))->toBe(24000);
});

it('controprova — una voce di spesa a € 0,00 e una a € 1.200,00 si creano', function () {
    $s = be52Vuoto($this);

    be52CreaVoce($this, $s, 'Da definire', 'spesa', '0,00')->assertSessionHasNoErrors();
    be52CreaVoce($this, $s, 'Spese generali', 'spesa', '1.200,00')->assertSessionHasNoErrors();

    expect(Conto::where('piano_conto_id', $s['pc']->id)->pluck('importo', 'nome')->map(fn ($v) => (int) $v)->all())->toBe(['Da definire' => 0, 'Spese generali' => 120000]);
});

// ─── Modifica ───────────────────────────────────────────────────────────────────────────────────────────────────

it('portare una voce a −€ 300,00 in modifica dice di scrivere l\'importo senza il segno, non «già impegnato»', function () {
    $s = be52Scenario($this);

    be52UpdateVoce($this, $s, $s['spesa'], ['importo' => '-300,00']);
    $errori = be52Errori();

    expect((int) Conto::find($s['spesa']->id)->importo)->toBe(120000)
        ->and($errori['importo'][0] ?? null)->toBe(BE52_TESTO_SPESA_NEGATIVA);
});

it('controprova — una voce già negativa si rinomina e si porta a «Entrata» senza toccare l\'importo', function () {
    $s = be52Scenario($this);
    $vecchia = be52Voce($this, $s, $s['pc'], 'Rimborso', 'spesa', '240,00');
    DB::table('conti')->where('id', $vecchia->id)->update(['importo' => -24000]); // il dato che le versioni precedenti lasciavano

    be52UpdateVoce($this, $s, $vecchia, ['nome' => 'Rimborso assicurazione', 'importo' => '-240,00']);
    $primo = be52Errori();
    be52UpdateVoce($this, $s, $vecchia->fresh(), ['nome' => 'Rimborso assicurazione', 'tipo' => 'entrata', 'importo' => '-240,00']);
    $secondo = be52Errori();

    expect($primo)->toBe([])
        ->and($secondo)->toBe([])
        ->and(Conto::find($vecchia->id)->only(['nome', 'tipo']))->toBe(['nome' => 'Rimborso assicurazione', 'tipo' => 'entrata'])
        ->and((int) Conto::find($vecchia->id)->importo)->toBe(-24000);
});

it('controprova — una voce negativa si corregge scrivendo un importo positivo', function () {
    $s = be52Scenario($this);
    $vecchia = be52Voce($this, $s, $s['pc'], 'Rimborso', 'spesa', '240,00');
    DB::table('conti')->where('id', $vecchia->id)->update(['importo' => -24000]);

    be52UpdateVoce($this, $s, $vecchia, ['nome' => 'Rimborso', 'importo' => '200,00'])->assertSessionHasNoErrors();

    expect((int) Conto::find($vecchia->id)->importo)->toBe(20000);
});

// ─── L'importo che il piano forza sulla voce ────────────────────────────────────────────────────────────────────

it('un importo negativo nella configurazione del piano si rifiuta, per una spesa e per un\'entrata', function () {
    $s = be52Scenario($this);
    $corpo = fn (array $config) => ['tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Prova', 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 1, 'capitoli_config' => $config];

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), $corpo([['id' => $s['spesa']->id, 'importo' => '-300,00']]));
    $spesa = be52Errori();
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), $corpo([['id' => $s['entrata']->id, 'importo' => '-100,00']]));
    $entrata = be52Errori();

    expect(PianoRate::count())->toBe(0)
        ->and($spesa['capitoli_config.0.importo'][0] ?? null)->toBe(BE52_TESTO_CONFIG_NEGATIVA)
        ->and($entrata['capitoli_config.0.importo'][0] ?? null)->toBe(BE52_TESTO_CONFIG_NEGATIVA);
});

it('controprova — l\'importo positivo nella configurazione del piano resta quello scritto', function () {
    $s = be52Scenario($this);

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), ['tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Prova',
        'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 1,
        'capitoli_config' => [['id' => $s['spesa']->id, 'importo' => '300,00'], ['id' => $s['entrata']->id, 'importo' => '100,00']]])->assertSessionHasNoErrors();

    $piano = PianoRate::firstOrFail();
    expect(be52Pivot($piano))->toBe([$s['spesa']->id => 30000, $s['entrata']->id => 10000])
        ->and(be52Totale($piano))->toBe(20000);
});
