<?php

/**
 * Il fermo del piano nei casi di confine, e le frasi che dicono che cosa costa la via d'uscita.
 *
 * `Beta52VoceEntrataFermoTest` e `Beta52VoceEntrataFermoSelezioneTest` provano il fermo sui casi di fondo; qui stanno i punti che li completano. Il fermo legge
 * le stesse voci e lo stesso importo del motore: una voce di entrata negativa senza copia non lo ferma (il motore le toglie il segno), un capitolo con la copia
 * negativa è nominato una volta sola anche su tre livelli, un figlio di entrata negativo non gonfia il totale, i figli nati dopo il piano contano quando nessuno è
 * nato prima, una gestione senza piano dei conti non rompe niente, e un piano senza voci proprie legge solo le righe di sé stesso. L'ordine delle voci nel
 * rifiuto è quello degli id, i sottoconti dello stesso capitolo si nominano tutti, la frase di un sottoconto dopo quella di una voce comincia con la maiuscola.
 *
 * Le frasi dicono il costo di ogni strada, e il costo detto è quello vero:
 * - togliere una voce dal piano ne cancella i tratti di competenza (decisione 20), che nessuna rotta riscrive dopo la creazione: si riscrivono solo ricreando il piano;
 * - riportare in bozza un piano approvato azzera il numero del verbale e la nota dell'approvazione;
 * - «Sincronizza» di una voce negativa che non è ancora nel piano non ha nessuna copia da togliere: il rifiuto dice solo di correggere la voce;
 * - togliere l'unica voce del piano riporta il piano a leggere tutte le voci del piano dei conti, e il rifiuto lo dice.
 *
 * Fonte dell'atteso: i conti a mano di ogni test (spesa base € 1.200,00, copia del capitolo € 400,00, i figli indicati), il comportamento del motore
 * (`processaConti`), che qui non cambia, e gli effetti scritti da `PianoRateController::detachCapitolo` e `updateStato`.
 *
 * Cosa resta scoperto: le stampe in sola lettura; la schermata (`PianiRateShowSpesaNegativa.test.ts`).
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 */

use App\Enums\StatoPianoRate;
use App\Models\Gestionale\CompetenzaCapitolo;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoRate;
use App\Services\Riparto\SpeseNegativeNelPiano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
});

function be52CasiMessaggio(): string
{
    return (string) (be52Flash()['message'] ?? '');
}

// ─── Che cosa il fermo non ferma, e che cosa ferma una volta sola ────────────────────────────────────────────────

it('una voce di entrata a −€ 240,00 senza copia nel piano non ferma il ricalcolo: il motore le toglie il segno e il piano chiede € 960,00', function () {
    [$s, $piano] = be52PianoVecchio($this, 'entrata', -24000, null);

    be52Ricalcola($this, $s, $piano);

    expect(be52Flash()['type'] ?? null)->toBe('success')
        ->and(be52Totale($piano->fresh()))->toBe(96000);
});

it('un capitolo con la copia negativa e un sottocapitolo con una foglia, su tre livelli, è nominato una volta sola', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $piano = be52PianoDallaRotta($this, $s);
    be52PostVoce($this, $s, ['nome' => 'Capitolo', 'importo' => '0,00', 'tabella_millesimale_id' => null, 'isCapitolo' => true]);
    $capitolo = Conto::where('nome', 'Capitolo')->firstOrFail();
    be52PostVoce($this, $s, ['nome' => 'Sottocapitolo', 'importo' => '100,00', 'parent_id' => $capitolo->id, 'isSottoConto' => true]);
    $sotto = Conto::where('nome', 'Sottocapitolo')->firstOrFail();
    be52PostVoce($this, $s, ['nome' => 'Foglia', 'importo' => '100,00', 'parent_id' => $capitolo->id, 'isSottoConto' => true]);
    // la foglia sta sotto il sottocapitolo (il terzo livello che le versioni vecchie permettevano), e il sottocapitolo non ha tabelle proprie
    DB::table('conti')->where('nome', 'Foglia')->update(['parent_id' => $sotto->id]);
    DB::table('conto_tabella_ripartizioni')->whereIn('conto_tabella_millesimale_id', DB::table('conto_tabella_millesimale')->where('conto_id', $sotto->id)->pluck('id'))->delete();
    DB::table('conto_tabella_millesimale')->where('conto_id', $sotto->id)->delete();
    DB::table('conti')->where('piano_conto_id', $s['pc']->id)->whereIn('nome', ['Sottocapitolo', 'Foglia'])->update(['created_at' => now()->subDay()]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $capitolo->id, 'importo' => -30000, 'created_at' => now(), 'updated_at' => now()]);

    be52Ricalcola($this, $s, $piano->fresh());

    expect(be52Flash()['type'] ?? null)->toBe('error')
        ->and(be52CasiMessaggio())->toContain('«Capitolo»')
        ->and(be52CasiMessaggio())->not->toContain('«Sottocapitolo»');
});

it('un sottoconto di entrata negativo sotto un capitolo con la copia non ferma il piano: il motore gli toglie il segno', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $piano = be52PianoDallaRotta($this, $s);
    be52CapitoloConFigli($this, $s, $piano, ['Ascensore' => 50000, 'Rimborso figlio' => -10000], 40000);
    DB::table('conti')->where('piano_conto_id', $s['pc']->id)->where('nome', 'Rimborso figlio')->update(['tipo' => 'entrata']);

    be52Ricalcola($this, $s, $piano->fresh());

    expect(be52Flash()['type'] ?? null)->toBe('success')
        ->and(be52Totale($piano->fresh()))->toBe(160000);
});

it('un figlio negativo ferma il piano anche quando tutti i figli sono nati dopo la creazione del piano: il motore usa quelli di adesso', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $piano = be52PianoDallaRotta($this, $s);
    $domani = now()->addDay()->toDateTimeString();
    be52CapitoloConFigli($this, $s, $piano, ['Ascensore' => 50000, 'Rimborso figlio' => -10000], 40000, false, ['Ascensore' => $domani, 'Rimborso figlio' => $domani]);

    be52Ricalcola($this, $s, $piano->fresh());

    expect(be52Flash()['type'] ?? null)->toBe('error')
        ->and(be52CasiMessaggio())->toContain('«Rimborso figlio»');
});

it('il fermo di una gestione senza piano dei conti non trova niente e non rompe', function () {
    $s = be52Vuoto($this);
    $straordinaria = app(\App\Services\CondominioService::class)->createDefaultGestione($s['c'], $s['e'], 'straordinaria');
    $piano = PianoRate::create(['gestione_id' => $straordinaria->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Senza piano dei conti', 'stato' => 'bozza',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => true]);

    expect(app(SpeseNegativeNelPiano::class)->trova($piano))->toBe([]);
});

it('un piano senza voci proprie legge le radici della gestione, non le righe di un altro piano', function () {
    $s = be52Vuoto($this);
    $x = be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $y = be52Voce($this, $s, $s['pc'], 'Rimborso', 'spesa', '240,00');
    $altro = PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Altro piano', 'stato' => 'bozza', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => true, 'attivo' => true]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $altro->id, 'conto_id' => $x->id, 'importo' => 120000, 'created_at' => now(), 'updated_at' => now()]);
    $globale = be52PianoSenzaPivot($s);
    DB::table('conti')->where('id', $y->id)->update(['importo' => -24000]);

    be52Ricalcola($this, $s, $globale->fresh());

    expect(be52Flash()['type'] ?? null)->toBe('error')
        ->and(be52CasiMessaggio())->toContain('«Rimborso»');
});

// ─── L'elenco delle voci nel rifiuto ────────────────────────────────────────────────────────────────────────────

it('le voci del rifiuto stanno in ordine di id, anche quando un sottoconto ha l\'id più alto di una radice successiva', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $piano = be52PianoDallaRotta($this, $s);
    be52PostVoce($this, $s, ['nome' => 'Cap', 'importo' => '0,00', 'tabella_millesimale_id' => null, 'isCapitolo' => true]);
    $capitolo = Conto::where('nome', 'Cap')->firstOrFail();
    $radice = be52Voce($this, $s, $s['pc'], 'Radice B', 'spesa', '100,00');
    be52PostVoce($this, $s, ['nome' => 'Figlio C', 'importo' => '100,00', 'parent_id' => $capitolo->id, 'isSottoConto' => true]);
    DB::table('conti')->where('nome', 'Figlio C')->update(['importo' => -10000, 'created_at' => now()->subDay()]);
    DB::table('conti')->where('id', $radice->id)->update(['importo' => -20000]);
    // nel piano stanno il capitolo (id più basso) e la radice: il motore visita il capitolo, poi il suo figlio, poi la radice
    DB::table('piano_rate_capitoli')->insert([
        ['piano_rate_id' => $piano->id, 'conto_id' => $capitolo->id, 'importo' => null, 'created_at' => now(), 'updated_at' => now()],
        ['piano_rate_id' => $piano->id, 'conto_id' => $radice->id, 'importo' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    be52Ricalcola($this, $s, $piano->fresh());

    expect(strpos(be52CasiMessaggio(), '«Radice B»'))->toBeInt()
        ->and(strpos(be52CasiMessaggio(), '«Radice B»'))->toBeLessThan(strpos(be52CasiMessaggio(), '«Figlio C»'));
});

it('due sottoconti negativi sotto lo stesso capitolo sono nominati tutti e due, con la forma plurale', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $piano = be52PianoDallaRotta($this, $s);
    be52CapitoloConFigli($this, $s, $piano, ['Ascensore' => 80000, 'Rimborso B' => -10000, 'Rimborso C' => -10000], 40000);

    be52Ricalcola($this, $s, $piano->fresh());

    expect(be52Flash()['type'] ?? null)->toBe('error')
        ->and(be52CasiMessaggio())->toContain('si ripartisce anche sui sottoconti con un importo negativo, «Rimborso B» (meno € 100,00), «Rimborso C» (meno € 100,00): il totale del piano supererebbe quello del capitolo');
});

it('la frase del sottoconto, dopo quella della voce, comincia con la maiuscola', function () {
    [$s, $piano] = be52PianoVecchio($this);
    be52CapitoloConFigli($this, $s, $piano, ['Ascensore' => 80000, 'Rimborso B' => -10000], 40000);

    be52Ricalcola($this, $s, $piano->fresh());

    expect(be52CasiMessaggio())->toContain('per € 240,00. L\'importo del capitolo «Manutenzioni»');
});

// ─── La via d'uscita costa i tratti di competenza della voce ─────────────────────────────────────────────────────

it('togliere e riaggiungere una voce con due tratti di competenza ne perde i tratti, e il rifiuto lo dice prima', function () {
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class, \App\Events\Gestionale\PianoRateStatusUpdated::class]);
    $s = be52Vuoto($this);
    $spese = be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $riscaldamento = be52Voce($this, $s, $s['pc'], 'Riscaldamento', 'spesa', '240,00');
    $piano = be52PianoConVoci($this, $s, [$spese->id, $riscaldamento->id], 'Con competenze', [
        'competenze_capitoli' => [['conto_id' => $riscaldamento->id, 'tratti' => [['dal' => '2026-01-01', 'al' => '2026-04-15'], ['dal' => '2026-10-15', 'al' => '2026-12-31']]]],
    ]);
    $tratti = CompetenzaCapitolo::count();
    // il dato vecchio: la voce è negativa e il piano ne tiene la copia negativa
    DB::table('conti')->where('id', $riscaldamento->id)->update(['importo' => -24000]);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $piano->id)->where('conto_id', $riscaldamento->id)->update(['importo' => -24000]);

    be52Ricalcola($this, $s, $piano->fresh());
    $rifiuto = be52CasiMessaggio();

    // la strada che il rifiuto indica: corregge la voce, la toglie dal piano, la riaggiunge
    be52UpdateVoce($this, $s, $riscaldamento->fresh(), ['nome' => 'Riscaldamento', 'importo' => '200,00'])->assertSessionHasNoErrors();
    be52Detach($this, $s, $piano->fresh(), $riscaldamento->id);
    $dopoLaRimozione = CompetenzaCapitolo::count();
    be52RicalcolaCon($this, $s, $piano->fresh(), ['orphan_ids' => [$riscaldamento->id]]);

    expect($rifiuto)->toBe('Il piano non si ricalcola: la voce di spesa «Riscaldamento» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00.'.BE52_FERMO_CORREGGI_LA_VOCE.BE52_FERMO_LA_COPIA)
        ->and($tratti)->toBe(2)
        ->and($dopoLaRimozione)->toBe(0)
        ->and(CompetenzaCapitolo::count())->toBe(0)
        ->and(be52Pivot($piano->fresh()))->toBe([$spese->id => 120000, $riscaldamento->id => 20000]);
});

// ─── «Sincronizza» di una voce negativa che non è ancora nel piano ───────────────────────────────────────────────

it('«Sincronizza» di una voce negativa non ancora nel piano si rifiuta senza dire di toglierla dal piano: non c\'è nessuna copia', function () {
    [$s, $piano] = be52PianoVecchio($this, 'spesa', 24000, 24000);
    $nuova = be52Voce($this, $s, $s['pc'], 'Altra', 'spesa', '100,00');
    DB::table('conti')->where('id', $nuova->id)->update(['importo' => -10000]);
    $pivot = be52Pivot($piano->fresh());
    $quote = be52Quote($piano);

    be52RicalcolaCon($this, $s, $piano->fresh(), ['orphan_ids' => [$nuova->id]]);

    expect(be52Flash())->toBe(['type' => 'error', 'message' => 'Il piano non si ricalcola: la voce di spesa «Altra» ha un importo negativo (meno € 100,00) e il piano la addebiterebbe in positivo, per € 100,00.'
        .BE52_FERMO_CORREGGI_LA_VOCE])
        ->and(be52Pivot($piano->fresh()))->toBe($pivot)
        ->and(be52Quote($piano))->toBe($quote);
});

it('«Sincronizza» di un capitolo i cui sottoconti sommano un importo negativo si rifiuta e nomina il sottoconto da correggere, non il capitolo che ha importo zero', function () {
    $s = be52Vuoto($this);
    $spese = be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $piano = be52PianoConVoci($this, $s, [$spese->id]);
    be52PostVoce($this, $s, ['nome' => 'Manutenzioni', 'importo' => '0,00', 'tabella_millesimale_id' => null, 'isCapitolo' => true]);
    $capitolo = Conto::where('piano_conto_id', $s['pc']->id)->where('nome', 'Manutenzioni')->firstOrFail();
    be52PostVoce($this, $s, ['nome' => 'Rimborso', 'importo' => '10,00', 'parent_id' => $capitolo->id, 'isSottoConto' => true]);
    DB::table('conti')->where('piano_conto_id', $s['pc']->id)->where('nome', 'Rimborso')->update(['importo' => -10000]);
    $pivot = be52Pivot($piano->fresh());

    be52RicalcolaCon($this, $s, $piano->fresh(), ['orphan_ids' => [$capitolo->id]]);

    // Il rifiuto nomina il sottoconto «Rimborso», che è quello negativo: il capitolo «Manutenzioni» sta a € 0,00 e non ha niente da correggere.
    expect(be52Flash())->toBe(['type' => 'error', 'message' => 'Il piano non si ricalcola: il sottoconto «Rimborso», sotto il capitolo «Manutenzioni», ha un importo negativo (meno € 100,00) e il capitolo avrebbe nel piano una copia negativa (meno € 100,00): il totale del piano non sarebbe quello del capitolo.'
        .' Correggi l\'importo del sottoconto «Rimborso» nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.'])
        ->and(be52Pivot($piano->fresh()))->toBe($pivot);
});

it('controprova — «Sincronizza» con una voce positiva e un\'altra negativa già nel piano nomina la voce già nel piano e la sua copia', function () {
    [$s, $piano] = be52PianoVecchio($this);
    $nuova = be52Voce($this, $s, $s['pc'], 'Altra', 'spesa', '100,00');

    be52RicalcolaCon($this, $s, $piano->fresh(), ['orphan_ids' => [$nuova->id]]);

    expect(be52Flash())->toBe(['type' => 'error', 'message' => BE52_FERMO_TESTA_UNA.BE52_FERMO_CORREGGI_LA_VOCE.BE52_FERMO_LA_COPIA])
        ->and(array_keys(be52Pivot($piano->fresh())))->not->toContain($nuova->id);
});

// ─── Togliere l'unica voce del piano ────────────────────────────────────────────────────────────────────────────

it('togliere l\'unica voce del piano si rifiuta e dice che senza voci il piano ripartisce tutte le voci del piano dei conti', function () {
    $s = be52Vuoto($this);
    $spese = be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $vecchia = be52Voce($this, $s, $s['pc'], 'Vecchio rimborso', 'spesa', '240,00');
    $piano = be52PianoConVoci($this, $s, [$spese->id]);
    DB::table('conti')->where('id', $vecchia->id)->update(['importo' => -24000]);
    $pivot = be52Pivot($piano->fresh());
    $quote = be52Quote($piano);

    be52Detach($this, $s, $piano->fresh(), $spese->id);

    expect(be52Flash())->toBe(['type' => 'error', 'message' => 'Il piano non si ricalcola: la voce di spesa «Vecchio rimborso» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00.'
        .' Senza voci nel piano, il piano ripartisce tutte le voci del piano dei conti: «Vecchio rimborso» ne fa parte.'.BE52_FERMO_CORREGGI_LA_VOCE])
        ->and(be52Pivot($piano->fresh()))->toBe($pivot)
        ->and(be52Quote($piano))->toBe($quote);
});

it('togliere l\'unica voce con due voci negative fuori dal piano le nomina tutte e due, al plurale', function () {
    $s = be52Vuoto($this);
    $spese = be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $a = be52Voce($this, $s, $s['pc'], 'Vecchio rimborso', 'spesa', '240,00');
    $b = be52Voce($this, $s, $s['pc'], 'Vecchio ristorno', 'spesa', '100,00');
    $piano = be52PianoConVoci($this, $s, [$spese->id]);
    DB::table('conti')->where('id', $a->id)->update(['importo' => -24000]);
    DB::table('conti')->where('id', $b->id)->update(['importo' => -10000]);

    be52Detach($this, $s, $piano->fresh(), $spese->id);

    expect(be52CasiMessaggio())->toContain(' Senza voci nel piano, il piano ripartisce tutte le voci del piano dei conti: «Vecchio rimborso» e «Vecchio ristorno» ne fanno parte.');
});

it('controprova — togliere una voce da un piano che ne ha altre non dice niente sul piano senza voci', function () {
    [$s, $piano, $voce] = be52PianoVecchio($this);
    $pulizie = be52Voce($this, $s, $s['pc'], 'Pulizie', 'spesa', '600,00');
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $pulizie->id, 'importo' => 60000, 'created_at' => now(), 'updated_at' => now()]);

    be52Detach($this, $s, $piano->fresh(), $pulizie->id);

    expect(be52Flash()['type'] ?? null)->toBe('error')
        ->and(be52CasiMessaggio())->toContain('«Rimborso»')
        ->and(be52CasiMessaggio())->not->toContain('Senza voci nel piano');
});

// ─── Riportare in bozza costa il verbale e la nota ──────────────────────────────────────────────────────────────

it('un piano approvato con un verbale e una nota: il rifiuto dice che riportarlo in bozza li azzera, e li azzera davvero', function () {
    Event::fake([\App\Events\Gestionale\PianoRateStatusUpdated::class]);
    [$s, $piano] = be52PianoVecchio($this);
    DB::table('piani_rate')->where('id', $piano->id)->update(['stato' => StatoPianoRate::APPROVATO->value, 'numero_verbale' => '4/2026', 'nota_approvazione' => 'Approvato in assemblea del 12 marzo']);

    be52Ricalcola($this, $s, $piano->fresh());
    $rifiuto = be52CasiMessaggio();
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => false])->assertSessionHasNoErrors();
    $dopo = DB::table('piani_rate')->where('id', $piano->id)->first(['stato', 'numero_verbale', 'nota_approvazione']);

    expect($rifiuto)->toContain('riportalo prima in bozza (si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima)')
        ->and([$dopo->stato, $dopo->numero_verbale, $dopo->nota_approvazione])->toBe(['bozza', null, null]);
});
