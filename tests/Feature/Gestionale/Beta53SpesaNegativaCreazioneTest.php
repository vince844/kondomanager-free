<?php

/**
 * Un piano nuovo non addebita una voce di spesa scritta con il meno (dato delle versioni fino alla 1.11.0-beta.51), da nessuna strada della creazione.
 *
 * Il motore addebita la voce in positivo (`CalcoloQuoteService::processaConti`). Con il piano di prima della correzione, che la addebita già per
 * € 240,00, il selettore proponeva «Sforo da recuperare: € 240,00» e la pagina mandava quella cifra fra le voci scelte: il secondo piano nasceva con la
 * copia +24000 e addebitava la voce una seconda volta (€ 480,00 per una voce scritta −€ 240,00), senza avviso. Lo stesso con la selezione rapida
 * (`capitoli_ids` senza le cifre). Ora:
 * - una riga delle voci scelte con un importo su una voce di spesa negativa si rifiuta sul suo campo (`capitoli_config.N.importo`), con il nome della
 *   voce: se la voce è già in un piano il rifiuto nomina il piano e rimanda alla sua pagina, altrimenti dice di correggerla. Vale anche per un
 *   sottoconto, che il fermo del piano su una copia positiva non vede. Una riga con l'importo vuoto non si rifiuta qui: la copia resta quella della
 *   voce e la ferma il fermo;
 * - la voce non è mai una voce da finanziare: la selezione rapida e «lascia vuoto» ricadono sull'importo della voce, negativo, e il fermo della
 *   creazione della 1.11.0-beta.52 le ferma con il suo testo, che resta com'era;
 * - un id fuori dal condominio si rifiuta solo per il perimetro: il rifiuto nuovo non legge quella voce e non ne dice il nome.
 *
 * Il fermo del ricalcolo e l'avviso della pagina del piano della 1.11.0-beta.52 restano com'erano, alla lettera.
 *
 * Fonte dell'atteso: i conti a mano dello scenario (il piano di prima della correzione chiede 120000; il secondo piano, se nascesse, 24000) e i testi
 * della 1.11.0-beta.52 per il fermo e l'avviso.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * Cosa resta scoperto: la schermata di creazione montata (il badge e la nota li prova `PianiRateNewSpesaNegativa.test.ts`, il rifiuto sul campo
 * `PianiRateNewErroriCapitoli.test.ts`); «Sincronizza» e «Ricalcola con voci orfane» (la copia è l'importo della voce: `Beta52VoceEntrataFermoTest`); il
 * piano straordinario (le sue voci sono fatture); il secondo piano già fatto prima della correzione (si vede, `Beta53SpesaNegativaCopertureTest`, ma non
 * si ripara da solo); i database diversi da sqlite.
 */

use App\Models\Gestionale\PianoRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta53SpesaNegativaScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

/** Le voci scelte come le manda la pagina: `capitoli_ids` e una riga di `capitoli_config` per voce, con la cifra data. */
function b53snVociScelte(array $righe): array
{
    return ['capitoli_ids' => array_keys($righe), 'capitoli_config' => array_map(fn ($id, $importo) => ['id' => $id, 'importo' => $importo, 'note' => ''], array_keys($righe), array_values($righe))];
}

// ─── Le voci scelte con la cifra ────────────────────────────────────────────────────────────────────────────────

it('T297.6 un secondo piano con la voce a «240,00», come la manda la pagina con il residuo del selettore: si rifiuta sul campo, nomina il piano che la addebita già, e niente cambia', function (string $stato) {
    $s = b53snV2($this, $stato);

    b53snCrea($this, $s, b53snVociScelte([$s['rimborso']->id => '240,00']));

    expect(b53snErrori()['capitoli_config.0.importo'] ?? null)->toBe([B53SN_RIFIUTO_NEL_PIANO])
        ->and(PianoRate::where('gestione_id', $s['g']->id)->count())->toBe(1)
        ->and(b53snCopie($s, $s['rimborso']))->toBe([$s['piano']->id => -24000])
        ->and(b52tQuoteDelMotore($s['piano']))->toBe(120000);
})->with(['bozza', 'approvato', 'emessa']);

it('T297.6-bis senza piano, la voce scelta con una cifra accanto a un\'altra: il rifiuto sta sulla sua riga e dice di correggerla', function () {
    $s = b53snV0($this);

    b53snCrea($this, $s, b53snVociScelte([$s['generali']->id => '1.200,00', $s['rimborso']->id => '240,00']));

    expect(b53snErrori()['capitoli_config.1.importo'] ?? null)->toBe([B53SN_RIFIUTO_FUORI_DAI_PIANI])
        ->and(b53snErrori())->not->toHaveKey('capitoli_config.0.importo')
        ->and(PianoRate::count())->toBe(0);
});

it('T297.6-ter un sottoconto di spesa negativo scelto con una cifra si rifiuta sul campo, con il suo nome', function () {
    $s = b53snCapitolo($this);

    b53snCrea($this, $s, b53snVociScelte([$s['rimborso']->id => '100,00']));

    expect(b53snErrori()['capitoli_config.0.importo'] ?? null)->toBe([
        'La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo. Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.',
    ])
        ->and(PianoRate::count())->toBe(0);
});

it('T297.24 un sottoconto di spesa negativo il cui capitolo è nel piano scelto con una cifra: il rifiuto nomina il piano che lo addebita dal capitolo', function (string $stato) {
    $s = b53snCapitoloNelPiano($this, '', $stato);

    b53snCrea($this, $s, b53snVociScelte([$s['rimborso']->id => '100,00']));

    expect(b53snErrori()['capitoli_config.0.importo'] ?? null)->toBe([
        'La voce di spesa «Rimborso» ha un importo negativo (meno € 100,00) ed è già nel piano «Ordinario 2026», che la addebita in positivo: '
        .'un piano nuovo la addebiterebbe una seconda volta. La pagina di «Ordinario 2026» dice che cosa fare.',
    ])
        ->and(PianoRate::count())->toBe(1);
})->with(['bozza', 'approvato']);

it('controprova — una voce di un altro condominio si rifiuta solo per il perimetro: nessun rifiuto sull\'importo, nessun nome di voce', function () {
    $s = b53snV0($this);
    $altro = b53snV0($this);

    b53snCrea($this, $s, b53snVociScelte([$altro['rimborso']->id => '240,00']));
    $errori = b53snErrori();

    expect($errori)->toHaveKey('capitoli_config.0.id')
        ->and($errori)->not->toHaveKey('capitoli_config.0.importo')
        ->and(str_contains(json_encode($errori, JSON_UNESCAPED_UNICODE), 'Rimborso vecchio'))->toBeFalse('un rifiuto nomina la voce di un altro condominio: '.json_encode($errori, JSON_UNESCAPED_UNICODE))
        ->and(PianoRate::count())->toBe(0);
});

it('controprova — la voce scelta con l\'importo vuoto non si rifiuta sul campo: la copia è l\'importo della voce e la ferma il fermo della creazione', function () {
    $s = b53snV0($this);

    b53snCrea($this, $s, b53snVociScelte([$s['rimborso']->id => '']));

    expect(b53snErrori())->not->toHaveKey('capitoli_config.0.importo')
        ->and(b53snFlash()['type'] ?? null)->toBe('error')
        ->and(str_starts_with((string) (b53snFlash()['message'] ?? ''), B53SN_FERMO_CREAZIONE_TESTA))->toBeTrue('il fermo non ha fermato il piano: '.json_encode(b53snFlash(), JSON_UNESCAPED_UNICODE))
        ->and(PianoRate::count())->toBe(0);
});

it('controprova — una voce di spesa positiva scelta con la sua cifra crea il piano come prima', function () {
    $s = b53snV0($this);

    b53snCrea($this, $s, b53snVociScelte([$s['generali']->id => '1.200,00']));

    expect(b53snErrori())->toBe([])
        ->and(PianoRate::count())->toBe(1)
        ->and(b52tQuoteDelMotore(PianoRate::firstOrFail()))->toBe(120000);
});

// ─── La selezione rapida e «lascia vuoto» ───────────────────────────────────────────────────────────────────────

it('T297.7 dal piano di prima della correzione, la selezione rapida della voce (senza cifre) si ferma con il fermo della creazione: nessun secondo piano', function () {
    $s = b53snV2($this);

    b53snCrea($this, $s, ['capitoli_ids' => [$s['rimborso']->id], 'capitoli_config' => []]);

    expect(b53snFlash()['type'] ?? null)->toBe('error')
        ->and(str_starts_with((string) (b53snFlash()['message'] ?? ''), B53SN_FERMO_CREAZIONE_TESTA))->toBeTrue('il secondo piano non si è fermato: '.json_encode(b53snFlash(), JSON_UNESCAPED_UNICODE))
        ->and(PianoRate::where('gestione_id', $s['g']->id)->count())->toBe(1)
        ->and(b53snCopie($s, $s['rimborso']))->toBe([$s['piano']->id => -24000]);
});

it('controprova — T297.7 senza piano: la selezione rapida della voce si ferma con il fermo della creazione e la copia non nasce positiva', function () {
    $s = b53snV0($this);

    b53snCrea($this, $s, ['capitoli_ids' => [$s['rimborso']->id], 'capitoli_config' => []]);

    expect(b53snFlash()['type'] ?? null)->toBe('error')
        ->and(str_starts_with((string) (b53snFlash()['message'] ?? ''), B53SN_FERMO_CREAZIONE_TESTA))->toBeTrue('il piano non si è fermato: '.json_encode(b53snFlash(), JSON_UNESCAPED_UNICODE))
        ->and(PianoRate::count())->toBe(0)
        ->and(b53snCopie($s, $s['rimborso']))->toBe([]);
});

it('T297.8 controprova — «lascia vuoto» con la spesa negativa si ferma con il fermo della creazione, alla lettera, e nessun piano nasce', function () {
    $s = b53snV0($this);

    b53snCrea($this, $s, [], 'Preventivo 2026');

    expect(b53snFlash())->toBe(['type' => 'error', 'message' => B53SN_FERMO_CREAZIONE])
        ->and(PianoRate::count())->toBe(0);
});

// ─── Il fermo e l'avviso del piano che c'è ──────────────────────────────────────────────────────────────────────

it('T297.9 controprova — l\'avviso della pagina del piano di prima della correzione resta quello della 1.11.0-beta.52, alla lettera', function () {
    $s = b53snV2($this);

    expect(b53snPaginaPiano($this, $s, $s['piano'])['avviso_spesa_negativa'])->toBe([
        'titolo' => 'Il piano contiene una voce di spesa con un importo negativo',
        'voci' => ['La voce «Rimborso vecchio» ha un importo negativo (meno € 240,00) e il piano la addebita in positivo, per € 240,00.'],
        'situazione' => '«Ricalcola» si ferma finché nel piano c\'è una voce di spesa negativa: il piano resta com\'è, con la cifra sbagliata.',
        'strade' => [
            'Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.',
            'Il piano tiene una copia dell\'importo della voce e correggere la voce non la cambia: togli la voce dal piano con «Rimuovi voce e ricalcola» e riaggiungila con «Sincronizza». Togliendola si perdono i suoi tratti di competenza, se ne ha: annotali prima e riscrivili ricreando il piano.',
        ],
    ]);
});

it('T297.9 controprova — «Ricalcola» sul piano di prima della correzione si ferma con il testo della 1.11.0-beta.52 e le quote restano 120000', function () {
    $s = b53snV2($this);
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->orderBy('rate_quote.id')->pluck('rate_quote.id')->all();

    $this->actingAs($this->user)->from('/pagina-del-piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova', 'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova',
    ]);

    expect(b53snFlash())->toBe(['type' => 'error', 'message' => 'Il piano non si ricalcola: la voce di spesa «Rimborso vecchio» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00.'
        .' Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.'
        .' Il piano tiene una copia dell\'importo della voce e correggere la voce non la cambia: togli la voce dal piano con «Rimuovi voce e ricalcola» e riaggiungila con «Sincronizza».'
        .' Togliendola si perdono i suoi tratti di competenza, se ne ha: annotali prima e riscrivili ricreando il piano.'])
        ->and(b52tQuoteDelMotore($s['piano']))->toBe(120000)
        ->and(DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->orderBy('rate_quote.id')->pluck('rate_quote.id')->all())->toBe($quote);
});
