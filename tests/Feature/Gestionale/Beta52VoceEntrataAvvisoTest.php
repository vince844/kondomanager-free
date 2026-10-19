<?php

/**
 * La pagina del piano avvisa che contiene una voce di spesa negativa, in ogni stato del piano.
 *
 * Con incassi registrati, rate emesse o un passaggio che ha conguagliato il piano, «Ricalcola», «Rimuovi voce e ricalcola» ed «Elimina» rifiutano già: il
 * fermo di una voce di spesa negativa (`Beta52VoceEntrataFermoTest`) non si raggiunge, e nessuno dice che la voce negativa è stata addebitata in positivo. La
 * pagina del piano (`PianoRateController::show`) manda la proprietà `avviso_spesa_negativa` quando il piano ne contiene una, con lo stesso rilevamento del fermo
 * (`SpeseNegativeNelPiano`), nomina la voce con la cifra che il piano addebita e dice le strade: in bozza che «Ricalcola» si ferma e come correggere; approvato,
 * che va prima riportato in bozza; con rate emesse o incassi, che vanno prima annullati e che, se il piano non si può più riscrivere, la differenza si regola con
 * un saldo sulle unità. Le strade sono tutte elencate: nessuna è già scelta, e nessuna parte da sola.
 *
 * L'avviso non spegne il cestino né le altre vie d'uscita: `fermo_del_piano`, che le spegne, resta nullo; sono due proprietà diverse.
 *
 * Fonte dell'atteso: i conti a mano di ogni test (spesa € 1.200,00 e voce −€ 240,00, il motore fa `abs` sulla spesa: il piano addebita € 240,00 invece di
 * accreditarli) e il testo della decisione 87.
 *
 * Ogni strada dice anche quello che costa: togliere la voce ne cancella i tratti di competenza, riportare in bozza un piano approvato azzera il numero del verbale
 * e la nota dell'approvazione.
 *
 * Cosa resta scoperto: la schermata (`PianiRateShowAvvisoSpesaNegativa.test.ts`); il credito usato e il passaggio che ha conguagliato il piano
 * (`Beta52VoceEntrataAvvisoCasiTest`); i piani straordinari con voci negative (non hanno voci: nessun avviso, provato in
 * un solo caso); l'esercizio chiuso come ragione per cui l'emissione non si annulla (non lo è: `EmissioneRateController::destroy` non lo guarda).
 *
 * I test che cominciano con «controprova» proteggono ciò che l'avviso non deve fare: dove non ci sono voci negative, o dove la voce è già corretta, la proprietà è nulla.
 */

use App\Enums\StatoPianoRate;
use App\Models\Gestionale\PianoRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
    DB::connection()->getPdo()->sqliteCreateFunction('JSON_UNQUOTE', fn ($x) => $x);
});

const BE52_AVVISO_TITOLO_UNA = 'Il piano contiene una voce di spesa con un importo negativo';
const BE52_AVVISO_VOCE_UNA = 'La voce «Rimborso» ha un importo negativo (meno € 240,00) e il piano la addebita in positivo, per € 240,00.';
const BE52_AVVISO_BOZZA = '«Ricalcola» si ferma finché nel piano c\'è una voce di spesa negativa: il piano resta com\'è, con la cifra sbagliata.';
const BE52_AVVISO_APPROVATO = 'Il piano è approvato: «Ricalcola» si ferma finché nel piano c\'è una voce di spesa negativa, e per correggerlo va prima riportato in bozza.';
const BE52_AVVISO_CORREGGI = 'Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.';
const BE52_AVVISO_COPIA = 'Il piano tiene una copia dell\'importo della voce e correggere la voce non la cambia: togli la voce dal piano con «Rimuovi voce e ricalcola» e riaggiungila con «Sincronizza».'
    .' Togliendola si perdono i suoi tratti di competenza, se ne ha: annotali prima e riscrivili ricreando il piano.';
// La strada del saldo dice dove entra il saldo: un saldo scritto dopo la generazione non entra in questo piano (entra nel prossimo piano della gestione che applica i saldi,
// o nell'esercizio dopo), e il Wallet accetta un solo saldo per persona, gestione, unità ed esercizio: dove la persona ne ha già uno il saldo nuovo si rifiuta
// («Esiste già un saldo per questa anagrafica in questa gestione.») e si corregge quello che c'è, finché nessun piano l'ha assorbito.
const BE52_AVVISO_SALDO = 'Se il piano non si può più riscrivere (incassi o crediti usati da tenere, o un passaggio di titolarità che l\'ha conguagliato), regola la differenza con un saldo sulle unità, da «Saldi iniziali»: '
    .'questo piano non lo legge più, entra nel prossimo piano della gestione che applica i saldi, o nell\'esercizio dopo. '
    .'Il Wallet tiene un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio né quelli legati a un passaggio: dove la persona ne ha già uno, si corregge quello, se nessun piano l\'ha ancora assorbito o se il piano che l\'ha assorbito si può ancora riscrivere; se l\'ha già assorbito un piano che non si riscrive più, il Wallet non accetta né un secondo saldo né la correzione, e per quella persona la differenza va nell\'esercizio dopo. Se il saldo è fra quelli da cui una successione ha calcolato l\'arretrato agli eredi, il Wallet lo dice e indica la strada.';

/** Le proprietà che la pagina del piano riceve. */
function be52Pagina($test, array $s, PianoRate $piano): array
{
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $piano]));
    $r->assertOk();

    return $r->viewData('page')['props'];
}

function be52PianoInStato(PianoRate $piano, StatoPianoRate $stato): void
{
    DB::table('piani_rate')->where('id', $piano->id)->update(['stato' => $stato->value]);
}

// ─── Le varianti dell'avviso ────────────────────────────────────────────────────────────────────────────────────

it('in bozza l\'avviso nomina la voce e la cifra addebitata, dice che «Ricalcola» si ferma e indica come correggere', function () {
    [$s, $piano] = be52PianoVecchio($this);

    $props = be52Pagina($this, $s, $piano);

    expect($props['avviso_spesa_negativa'])->toBe([
        'titolo' => BE52_AVVISO_TITOLO_UNA,
        'voci' => [BE52_AVVISO_VOCE_UNA],
        'situazione' => BE52_AVVISO_BOZZA,
        'strade' => [BE52_AVVISO_CORREGGI, BE52_AVVISO_COPIA],
    ])
        // l'avviso non spegne il cestino: `fermo_del_piano`, che lo spegne, non lo riguarda
        ->and($props['fermo_del_piano'])->toBeNull();
});

it('con la voce già corretta e la copia del piano ancora negativa l\'avviso dice che la copia resta', function () {
    [$s, $piano, $voce] = be52PianoVecchio($this);
    be52UpdateVoce($this, $s, $voce, ['nome' => 'Rimborso', 'importo' => '200,00'])->assertSessionHasNoErrors();

    $avviso = be52Pagina($this, $s, $piano)['avviso_spesa_negativa'];

    expect($avviso['voci'])->toBe(['La voce «Rimborso» è già corretta, ma il piano ne tiene ancora la copia negativa (meno € 240,00) e la addebita in positivo, per € 240,00.'])
        ->and($avviso['strade'])->toBe([BE52_AVVISO_COPIA]);
});

it('senza la copia nel piano l\'avviso dice solo di correggere la voce', function () {
    [$s, $piano] = be52PianoVecchio($this, 'spesa', -24000, null);

    $avviso = be52Pagina($this, $s, $piano)['avviso_spesa_negativa'];

    expect($avviso['voci'])->toBe([BE52_AVVISO_VOCE_UNA])
        ->and($avviso['strade'])->toBe([BE52_AVVISO_CORREGGI]);
});

it('approvato l\'avviso dice di riportare prima il piano in bozza', function () {
    [$s, $piano] = be52PianoVecchio($this);
    be52PianoInStato($piano, StatoPianoRate::APPROVATO);

    $props = be52Pagina($this, $s, $piano);

    expect($props['avviso_spesa_negativa'])->toBe([
        'titolo' => BE52_AVVISO_TITOLO_UNA,
        'voci' => [BE52_AVVISO_VOCE_UNA],
        'situazione' => BE52_AVVISO_APPROVATO,
        'strade' => ['Riporta il piano in bozza ('.BE52_COSTO_DELLA_BOZZA.').', BE52_AVVISO_CORREGGI, BE52_AVVISO_COPIA],
    ])
        ->and($props['fermo_del_piano'])->toBeNull();
});

it('con una rata emessa l\'avviso dice che le strade rifiutano e che le emissioni vanno annullate', function () {
    [$s, $piano] = be52PianoVecchio($this);
    be52PianoInStato($piano, StatoPianoRate::APPROVATO);
    aGiornaleNeiTest($piano->id, DB::table('rate')->where('piano_rate_id', $piano->id)->pluck('id')->all());

    $props = be52Pagina($this, $s, $piano);

    expect($props['avviso_spesa_negativa'])->toBe([
        'titolo' => BE52_AVVISO_TITOLO_UNA,
        'voci' => [BE52_AVVISO_VOCE_UNA],
        'situazione' => 'Il piano ha già quote a giornale: «Ricalcola», «Rimuovi voce e ricalcola» ed «Elimina» lo rifiutano, quindi nessuno dei tre arriva a togliere la voce negativa dalle quote.',
        'strade' => [
            'Per riscrivere il piano annulla le emissioni delle rate e riportalo in bozza ('.BE52_COSTO_DELLA_BOZZA.'), poi correggi come segue.',
            BE52_AVVISO_CORREGGI,
            BE52_AVVISO_COPIA,
            BE52_AVVISO_SALDO,
        ],
    ])
        // il fermo del piano lo mostra già (sul pulsante «Ricalcola»): è la proprietà dell'avviso a essere un'altra
        ->and($props['fermo_del_piano']['ragioni'])->toBe(['ha già quote a giornale']);
});

it('con un incasso registrato l\'avviso dice di annullare gli incassi', function () {
    [$s, $piano] = be52PianoVecchio($this);
    $quota = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->orderBy('rate_quote.id')->value('rate_quote.id');
    DB::table('rate_quote')->where('id', $quota)->update(['importo_pagato' => 5000]);

    $avviso = be52Pagina($this, $s, $piano)['avviso_spesa_negativa'];

    expect($avviso['situazione'])->toBe('Il piano ha un incasso su una sua quota: «Ricalcola», «Rimuovi voce e ricalcola» ed «Elimina» lo rifiutano, quindi nessuno dei tre arriva a togliere la voce negativa dalle quote.')
        ->and($avviso['strade'])->toBe([
            'Per riscrivere il piano annulla gli incassi registrati, poi correggi come segue.',
            BE52_AVVISO_CORREGGI,
            BE52_AVVISO_COPIA,
            BE52_AVVISO_SALDO,
        ]);
});

it('con un incasso e una rata emessa gli incassi si annullano prima delle emissioni', function () {
    [$s, $piano] = be52PianoVecchio($this);
    aGiornaleNeiTest($piano->id, DB::table('rate')->where('piano_rate_id', $piano->id)->pluck('id')->all());
    $quota = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)->orderBy('rate_quote.id')->value('rate_quote.id');
    DB::table('rate_quote')->where('id', $quota)->update(['importo_pagato' => 5000]);

    $avviso = be52Pagina($this, $s, $piano)['avviso_spesa_negativa'];

    expect($avviso['situazione'])->toStartWith('Il piano ha già quote a giornale e ha un incasso su una sua quota: ')
        ->and($avviso['strade'][0])->toBe('Per riscrivere il piano annulla prima gli incassi registrati, poi le emissioni delle rate, poi correggi come segue.');
});

it('con più voci negative l\'avviso le nomina tutte e indica di eliminare il piano e ricrearlo, dicendo che cosa si perde', function () {
    [$s, $piano] = be52PianoVecchio($this);
    $b = be52Voce($this, $s, $s['pc'], 'Rimborso B', 'spesa', '100,00');
    DB::table('conti')->where('id', $b->id)->update(['importo' => -10000]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $b->id, 'importo' => -10000, 'created_at' => now(), 'updated_at' => now()]);

    $avviso = be52Pagina($this, $s, $piano)['avviso_spesa_negativa'];

    expect($avviso['titolo'])->toBe('Il piano contiene 2 voci di spesa con un importo negativo')
        ->and($avviso['voci'])->toBe([
            BE52_AVVISO_VOCE_UNA,
            'La voce «Rimborso B» ha un importo negativo (meno € 100,00) e il piano la addebita in positivo, per € 100,00.',
        ])
        ->and($avviso['situazione'])->toBe(BE52_AVVISO_BOZZA)
        ->and($avviso['strade'])->toBe([
            'Correggi l\'importo delle voci nel piano dei conti: se sono proventi, scegli «Entrata» e scrivi l\'importo senza il segno.',
            'Il piano tiene una copia dell\'importo delle voci e correggere le voci non la cambia, e con più di una voce così il piano si ferma a ogni rimozione: elimina il piano e ricrealo dopo aver corretto le voci. '
                // Eliminare il piano cancella anche gli spostamenti di budget fra le voci e gli importi scelti per le voci: l'elenco di quello che si perde li dice.
                .'Ricreandolo si perdono il riparto manuale dei saldi solidali, i tratti di competenza delle voci, la ricorrenza, gli spostamenti di budget fra le voci e gli importi scelti per le voci, da scrivere di nuovo.',
        ]);
});

it('un sottoconto negativo sotto un capitolo con la copia ha la sua frase, senza la cifra addebitata', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $piano = be52PianoDallaRotta($this, $s);
    be52CapitoloConFigli($this, $s, $piano, ['Ascensore' => 50000, 'Rimborso figlio' => -10000], 40000);

    $avviso = be52Pagina($this, $s, $piano->fresh())['avviso_spesa_negativa'];

    expect($avviso['voci'])->toBe(['Il sottoconto «Rimborso figlio», sotto il capitolo «Manutenzioni», ha un importo negativo (meno € 100,00): il piano ripartisce l\'importo del capitolo anche su di lui, e il totale supera quello del capitolo.'])
        ->and($avviso['strade'])->toBe([BE52_AVVISO_CORREGGI]);
});

// ─── Dove l'avviso non c'è ──────────────────────────────────────────────────────────────────────────────────────

it('controprova — senza voci negative la pagina non manda l\'avviso, in bozza e approvato', function (string $stato) {
    $s = be52Scenario($this);
    $piano = be52PianoDallaRotta($this, $s);
    be52PianoInStato($piano, StatoPianoRate::from($stato));

    $props = be52Pagina($this, $s, $piano);

    expect(array_key_exists('avviso_spesa_negativa', $props))->toBeTrue()
        ->and($props['avviso_spesa_negativa'])->toBeNull();
})->with([
    'bozza' => [StatoPianoRate::BOZZA->value],
    'approvato' => [StatoPianoRate::APPROVATO->value],
]);

it('controprova — una voce di entrata negativa non è un caso: il motore la legge come entrata e non c\'è avviso', function () {
    [$s, $piano] = be52PianoVecchio($this, 'entrata', -24000, -24000);

    expect(be52Pagina($this, $s, $piano)['avviso_spesa_negativa'])->toBeNull();
});

it('controprova — la voce corretta e riaggiunta al piano toglie l\'avviso', function () {
    [$s, $piano, $voce] = be52PianoVecchio($this);
    be52UpdateVoce($this, $s, $voce, ['nome' => 'Rimborso', 'importo' => '200,00'])->assertSessionHasNoErrors();
    be52Detach($this, $s, $piano->fresh(), $voce->id);
    be52RicalcolaCon($this, $s, $piano->fresh(), ['orphan_ids' => [$voce->id]]);

    expect(be52Pagina($this, $s, $piano->fresh())['avviso_spesa_negativa'])->toBeNull();
});
