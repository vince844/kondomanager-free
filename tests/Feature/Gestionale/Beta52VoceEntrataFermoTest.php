<?php

/**
 * La generazione e il ricalcolo si fermano su una voce di spesa già negativa (decisione 87, punto 2).
 *
 * Una voce di spesa a −€ 240,00 (dato possibile dalla v1.7.0) si addebita in positivo a ogni ricalcolo: il piano di € 960,00 diventa di
 * € 1.440,00. Correggere l'importo della voce non basta, perché il piano ne tiene una copia (`piano_rate_capitoli.importo`): voce portata da
 * −€ 240,00 a € 200,00, il ricalcolo chiede ancora € 240,00. Il motore legge la copia se c'è, altrimenti l'importo della voce; il fermo legge
 * lo stesso dato, nomina la voce e dice che cosa fare. Una voce di entrata negativa non si ferma (il motore la legge `−abs`), e una copia positiva
 * su una voce negativa nemmeno (il piano segue la copia). Il fermo non diventa una ragione di `fermo_del_piano`: spegnerebbe il cestino
 * «Rimuovi voce e ricalcola», cioè la via d'uscita.
 *
 * Il dato vecchio si costruisce senza scavalcare nessuna guardia: voce e piano nascono positivi dalle rotte, poi `conti.importo` e la copia si
 * riscrivono negativi, come li ha lasciati la versione di prima.
 *
 * Fonte dell'atteso: i conti a mano della tabella `be52CasiDelFermo()` (spesa base € 1.200,00 più la voce del caso; il motore fa `abs` sulla spesa e
 * `−abs` sull'entrata) e il testo della decisione 87.
 *
 * Cosa resta scoperto: i piani straordinari e le voci di altre gestioni, i capitoli con i figli, il piano senza voci nella pivot e la riga della pivot di un
 * altro piano dei conti (`Beta52VoceEntrataFermoSelezioneTest`, `Beta52VoceEntrataFermoCasiTest`); le stampe e le anteprime in sola lettura (non passano da `GeneratePianoRateAction`);
 * la schermata (`PianiRateShowSpesaNegativa.test.ts`) e il riquadro di creazione del piano (si guarda a video).
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Enums\StatoPianoRate;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
});

function be52FermoMessaggio(): string
{
    return (string) (be52Flash()['message'] ?? '');
}

// ─── Il fermo ───────────────────────────────────────────────────────────────────────────────────────────────────

it('il ricalcolo di un piano con una voce di spesa a −€ 240,00 si ferma, la nomina e non tocca le quote', function () {
    [$s, $piano, $voce] = be52PianoVecchio($this);
    $quoteDi = be52Quote($piano);
    $pivot = be52Pivot($piano);

    be52Ricalcola($this, $s, $piano);

    expect(be52Flash())->toBe(['type' => 'error', 'message' => BE52_FERMO_TESTA_UNA.BE52_FERMO_CORREGGI_LA_VOCE.BE52_FERMO_LA_COPIA])
        ->and(be52Quote($piano))->toBe($quoteDi)
        ->and(be52Pivot($piano))->toBe($pivot);
});

it('con la voce già corretta a € 200,00 il ricalcolo si ferma lo stesso: la copia del piano è ancora negativa, e il rifiuto dice che la voce è già corretta', function () {
    [$s, $piano, $voce] = be52PianoVecchio($this);
    be52UpdateVoce($this, $s, $voce, ['nome' => 'Rimborso', 'importo' => '200,00'])->assertSessionHasNoErrors();
    expect((int) Conto::find($voce->id)->importo)->toBe(20000);

    be52Ricalcola($this, $s, $piano->fresh());

    // La testa del rifiuto dice «già corretta» come l'avviso della pagina: la voce vale € 200,00 e a essere negativa è la copia.
    expect(be52Flash())->toBe(['type' => 'error', 'message' => 'Il piano non si ricalcola: la voce di spesa «Rimborso» è già corretta, ma il piano ne tiene la copia negativa (meno € 240,00) e la addebiterebbe in positivo, per € 240,00.'
        .BE52_FERMO_LA_COPIA])
        ->and(be52Totale($piano->fresh()))->toBe(144000);
});

it('con la copia del piano vuota e la voce negativa il ricalcolo si ferma e dice di correggere la voce', function () {
    [$s, $piano] = be52PianoVecchio($this, 'spesa', -24000, null);

    be52Ricalcola($this, $s, $piano);

    expect(be52Flash())->toBe(['type' => 'error', 'message' => BE52_FERMO_TESTA_UNA.BE52_FERMO_CORREGGI_LA_VOCE]);
});

it('un piano approvato dice prima di riportarlo in bozza', function () {
    Event::fake([\App\Events\Gestionale\PianoRateStatusUpdated::class]);
    [$s, $piano] = be52PianoVecchio($this);
    DB::table('piani_rate')->where('id', $piano->id)->update(['stato' => StatoPianoRate::APPROVATO->value]);

    be52Ricalcola($this, $s, $piano->fresh());

    expect(be52Flash())->toBe(['type' => 'error', 'message' => BE52_FERMO_TESTA_UNA.BE52_FERMO_APPROVATO.BE52_FERMO_CORREGGI_LA_VOCE.BE52_FERMO_LA_COPIA]);
});

it('due voci negative nel piano: il rifiuto le nomina, indica di eliminare il piano e ricrearlo e dice che cosa si perde', function () {
    [$s, $piano, $a] = be52PianoVecchio($this);
    $b = be52Voce($this, $s, $s['pc'], 'Rimborso B', 'spesa', '100,00');
    DB::table('conti')->where('id', $b->id)->update(['importo' => -10000]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $b->id, 'importo' => -10000, 'created_at' => now(), 'updated_at' => now()]);

    be52Ricalcola($this, $s, $piano);

    expect(be52Flash())->toBe(['type' => 'error', 'message' => 'Il piano non si ricalcola: 2 voci di spesa hanno un importo negativo e il piano le addebiterebbe in positivo: «Rimborso» (meno € 240,00), «Rimborso B» (meno € 100,00).'
        .' Correggi l\'importo delle voci nel piano dei conti: se sono proventi, scegli «Entrata» e scrivi l\'importo senza il segno.'
        .' Il piano tiene una copia dell\'importo delle voci e correggere le voci non la cambia, e con più di una voce così il piano si ferma a ogni rimozione: elimina il piano e ricrealo dopo aver corretto le voci.'
        // Eliminare il piano cancella anche gli spostamenti di budget fra le voci (`budget_movements`, onDelete cascade) e gli importi scelti per le voci (la copia in
        // `piano_rate_capitoli`): l'elenco di quello che si perde li dice.
        .' Ricreandolo si perdono il riparto manuale dei saldi solidali, i tratti di competenza delle voci, la ricorrenza, gli spostamenti di budget fra le voci e gli importi scelti per le voci, da scrivere di nuovo.']);
});

// ─── La creazione del piano ─────────────────────────────────────────────────────────────────────────────────────

it('la creazione di un piano che include una voce di spesa negativa si ferma e il piano non nasce', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $voce = be52Voce($this, $s, $s['pc'], 'Rimborso', 'spesa', '240,00');
    DB::table('conti')->where('id', $voce->id)->update(['importo' => -24000]);

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), ['tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2026',
        'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 1]);

    expect(PianoRate::count())->toBe(0)
        ->and(be52Flash())->toBe(['type' => 'error', 'message' => 'Il piano non si crea: la voce di spesa «Rimborso» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00.'
            .BE52_FERMO_CORREGGI_LA_VOCE.' Poi rimanda il modulo.']);
});

it('la creazione senza generare ferma lo stesso: non nasce un piano con la copia negativa', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $voce = be52Voce($this, $s, $s['pc'], 'Rimborso', 'spesa', '240,00');
    DB::table('conti')->where('id', $voce->id)->update(['importo' => -24000]);

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), ['tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2026',
        'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 0]);

    expect(PianoRate::count())->toBe(0)
        ->and(DB::table('piano_rate_capitoli')->count())->toBe(0)
        ->and(be52Flash()['type'] ?? null)->toBe('error')
        ->and(str_contains(be52FermoMessaggio(), '«Rimborso»'))->toBeTrue('il rifiuto della creazione nomina la voce');
});

it('controprova — un piano con le rate emesse dice il rifiuto delle emissioni: il fermo non lo sostituisce', function () {
    [$s, $piano] = be52PianoVecchio($this);
    aGiornaleNeiTest($piano->id, DB::table('rate')->where('piano_rate_id', $piano->id)->pluck('id')->all());

    be52Ricalcola($this, $s, $piano->fresh());

    expect(be52Flash()['type'] ?? null)->toBe('error')
        ->and(be52FermoMessaggio())->toContain('ci sono rate già emesse in contabilità')
        ->and(be52FermoMessaggio())->not->toContain('importo negativo');
});

// ─── La via d'uscita ────────────────────────────────────────────────────────────────────────────────────────────

it('controprova — la via d\'uscita: corregge la voce, la toglie dal piano e la riaggiunge, e il piano chiede € 1.400,00', function () {
    [$s, $piano, $voce, $base] = be52PianoVecchio($this);
    be52UpdateVoce($this, $s, $voce, ['nome' => 'Rimborso', 'importo' => '200,00'])->assertSessionHasNoErrors();

    be52Detach($this, $s, $piano->fresh(), $voce->id);
    $dopoLaRimozione = [be52Flash(), be52Pivot($piano->fresh()), be52Totale($piano->fresh())];

    be52RicalcolaCon($this, $s, $piano->fresh(), ['orphan_ids' => [$voce->id]]);
    $dopoLaRiaggiunta = [be52Flash()['type'] ?? null, be52Pivot($piano->fresh()), be52Totale($piano->fresh())];

    expect($dopoLaRimozione)->toBe([['type' => 'success', 'message' => 'Voce rimossa e ricalcolata.'], [$base->id => 120000], 120000])
        ->and($dopoLaRiaggiunta)->toBe(['success', [$base->id => 120000, $voce->id => 20000], 140000]);
});

it('controprova — la via d\'uscita con «Entrata»: l\'importo scritto senza il segno, e il piano chiede € 960,00', function () {
    [$s, $piano, $voce, $base] = be52PianoVecchio($this);
    be52UpdateVoce($this, $s, $voce, ['nome' => 'Rimborso', 'tipo' => 'entrata', 'importo' => '240,00'])->assertSessionHasNoErrors();

    be52Detach($this, $s, $piano->fresh(), $voce->id);
    be52RicalcolaCon($this, $s, $piano->fresh(), ['orphan_ids' => [$voce->id]]);

    expect(be52Pivot($piano->fresh()))->toBe([$base->id => 120000, $voce->id => 24000])
        ->and(be52Totale($piano->fresh()))->toBe(96000);
});

it('togliere dal piano una voce positiva mentre un\'altra è negativa si rifiuta: la voce resta nel piano', function () {
    [$s, $piano, $voce] = be52PianoVecchio($this);
    $pulizie = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '600,00');
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $pulizie->id, 'importo' => 60000, 'created_at' => now(), 'updated_at' => now()]);
    $pivot = be52Pivot($piano->fresh());

    be52Detach($this, $s, $piano->fresh(), $pulizie->id);

    expect(be52Flash()['type'] ?? null)->toBe('error')
        ->and(be52FermoMessaggio())->toContain('«Rimborso»')
        ->and(be52Pivot($piano->fresh()))->toBe($pivot);
});

// ─── Che cosa non si ferma, e perché: il fermo e il motore dicono la stessa cosa ────────────────────────────────

/** @return array<string, array{0: Closure, 1: bool, 2: int}> nome => [come si costruisce, il fermo scatta, il totale del piano che il motore chiede dopo il ricalcolo] */
function be52CasiDelFermo(): array
{
    return [
        'spesa negativa con la copia negativa' => [fn ($t) => be52PianoVecchio($t, 'spesa', -24000, -24000), true, 144000],
        'spesa negativa senza copia' => [fn ($t) => be52PianoVecchio($t, 'spesa', -24000, null), true, 144000],
        'controprova — spesa negativa con la copia positiva: il piano segue la copia' => [fn ($t) => be52PianoVecchio($t, 'spesa', -24000, 24000), false, 144000],
        'controprova — entrata negativa con la copia negativa: il motore la legge come entrata' => [fn ($t) => be52PianoVecchio($t, 'entrata', -24000, -24000), false, 96000],
        'controprova — entrata a € 240,00' => [fn ($t) => be52PianoVecchio($t, 'entrata', 24000, 24000), false, 96000],
        'controprova — spesa a € 240,00' => [fn ($t) => be52PianoVecchio($t, 'spesa', 24000, 24000), false, 144000],
        'controprova — spesa a € 0,00' => [fn ($t) => be52PianoVecchio($t, 'spesa', 0, 0), false, 120000],
    ];
}

it('il fermo scatta dove il motore addebiterebbe in positivo un importo negativo, e solo lì', function (Closure $costruisci, bool $scatta, int $totaleDelMotore) {
    [$s, $piano] = $costruisci($this);
    $quoteDi = be52Quote($piano);

    be52Ricalcola($this, $s, $piano);
    $esito = be52Flash();

    if ($scatta) {
        expect($esito['type'] ?? null)->toBe('error')
            ->and(str_contains((string) ($esito['message'] ?? ''), '«Rimborso»'))->toBeTrue('il rifiuto nomina la voce negativa')
            ->and(be52Quote($piano))->toBe($quoteDi);
    } else {
        expect($esito['type'] ?? null)->toBe('success')
            ->and(be52Totale($piano->fresh()))->toBe($totaleDelMotore);
    }
})->with(fn () => be52CasiDelFermo());

// ─── La pagina del piano ────────────────────────────────────────────────────────────────────────────────────────

it('controprova — la pagina del piano con una voce negativa non manda `fermo_del_piano`: il cestino «Rimuovi voce e ricalcola» resta la via d\'uscita', function (string $stato) {
    DB::connection()->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($x) => $x);
    [$s, $piano] = be52PianoVecchio($this);
    DB::table('piani_rate')->where('id', $piano->id)->update(['stato' => $stato]);

    $risposta = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $piano]));
    $risposta->assertOk();
    $props = $risposta->viewData('page')['props'];

    expect(array_key_exists('fermo_del_piano', $props))->toBeTrue()
        ->and($props['fermo_del_piano'])->toBeNull();
})->with([
    'piano in bozza' => [StatoPianoRate::BOZZA->value],
    'piano approvato' => [StatoPianoRate::APPROVATO->value],
]);
