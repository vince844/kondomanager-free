<?php

/**
 * 1.11.0-beta.53 (Coda 244) — il saldo legato nella pagina dei saldi: che cosa manda il server, la modifica, l'eliminazione, le persone e le
 * unità che lo nominano.
 *
 * La pagina riceve per ogni riga `e_legata` e `passaggio_in_parole` («la vendita del 1 novembre 2026»), e per ogni unità `passaggi_legabili`:
 * i passaggi non annullati, padri, la cui famiglia comprende l'unità, con un registro (quelli di prima della 1.11.0-beta.37 non si annullano
 * dal programma, e un saldo legato a loro non si toglierebbe), dal più recente registrato; per ognuno l'id, le parole, i nomi di chi esce e
 * di chi entra, e se il registro ha una parte da regolare a mano su quell'unità. Nient'altro del passaggio: nessun registro.
 * Questo test è la fonte dei campi che i test del modale (`SaldiDetailPanelLegata.test.ts`) scrivono a mano.
 *
 * Una riga legata libera si modifica come ogni saldo manuale, e il legame resta; non cambia gestione; ferma in un piano emesso non si modifica
 * né si elimina, con i rimedi del piano. Una persona con un saldo legato non si elimina (la cascata toglierebbe la riga in silenzio, e
 * l'annullamento del passaggio non la troverebbe più): il messaggio dice i fatti delle sue righe legate (decisione 100), quanto sommano oggi e
 * dove stanno. Una riga dell'esercizio aperto si toglie dai saldi dell'unità; una di un altro esercizio si toglie annullando il passaggio dallo
 * storico dell'unità, che dice se prima c'è qualcosa da fare (un passaggio registrato dopo, un piano che ha assorbito la riga, una rata già
 * emessa), e il passaggio avvenuto davvero si registra di nuovo; una riga in un piano che non si riscrive più non si toglie, e la persona resta.
 * Ogni strada è seguita dalle rotte.
 *
 * Che cosa NON copre: la pagina Vue (nei test del modale); la persona staccata dal condominio dall'interfaccia (lo stato si costruisce con il
 * database); la cancellazione di un esercizio con un saldo legato (Coda 275, nella beta della chiusura); MySQL.
 */

use App\Models\Anagrafica;
use App\Models\Gestione;
use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

/** Le proprietà della pagina dei saldi del condominio. */
function sl53wPagina($test, array $s): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
}

/** L'unità dello scenario fra quelle della pagina. */
function sl53wUnita(array $props, int $immobileId): array
{
    $unita = collect($props['immobili'])->firstWhere('id', $immobileId);
    expect($unita)->not->toBeNull();

    return $unita;
}

/** L'apertura del rifiuto dell'eliminazione di chi ha solo saldi legati a un passaggio: non compare in nessun passaggio, lo nominano i suoi saldi. */
const SL53W_NOMINA = 'Questa anagrafica non si può eliminare, perché i saldi legati a un passaggio di titolarità (vendita, locazione, usufrutto o successione) la nominano.';
/** Il rifiuto dell'eliminazione di Dora, con la sua riga legata alla vendita (€ 10,00 a debito). */
const SL53W_DORA = SL53W_NOMINA . ' Il saldo del Wallet legato alla vendita del 1 novembre 2026 è oggi di € 10,00 a debito di Dora.';
/** Il rifiuto, con la riga di Dora nell'esercizio 2026 chiuso e libera. */
const SL53W_DORA_CHIUSO = SL53W_DORA . ' Quello nell\'esercizio «Esercizio 2026», che è chiuso (€ 10,00 a debito di Dora), i saldi dell\'unità non lo mostrano: se non serve più, si toglie annullando la vendita del 1 novembre 2026 dallo storico dell\'unità, che dice se prima c\'è qualcosa da fare, e un passaggio avvenuto davvero poi si registra di nuovo.';

it('la pagina manda per ogni riga il legame a parole e per l\'unità i passaggi a cui legare, senza il registro', function () {
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);

    $props = sl53wPagina($this, $s);
    $unita = sl53wUnita($props, (int) $s['unita']->id);
    $legata = collect($unita['saldi'])->first(fn ($x) => (int) $x['anagrafica_id'] === (int) $s['persone']['Elsa']->id && (int) $x['saldo_iniziale'] === 6016);
    $coppia = collect($unita['saldi'])->first(fn ($x) => $x['subentro_id'] !== null);

    expect($legata['e_legata'] ?? null)->toBeTrue()
        ->and($legata['passaggio_in_parole'] ?? null)->toBe('la vendita del 1 novembre 2026')
        ->and($coppia['e_legata'] ?? null)->toBeFalse()
        ->and(array_key_exists('passaggio_in_parole', $coppia ?? []))->toBeTrue()
        ->and(array_key_exists('passaggio_in_parole', $coppia ?? []) ? $coppia['passaggio_in_parole'] : 'manca')->toBeNull();
    // L'ordine delle chiavi di una voce non conta (toEqual); conta l'ordine delle voci, dal passaggio registrato più di recente.
    expect($unita['passaggi_legabili'] ?? null)->toEqual([
        ['id' => (int) $s['vendita']->id, 'parole' => 'la vendita del 1 novembre 2026', 'persone' => ['Ugo', 'Elsa'], 'con_parte_a_mano' => true],
        ['id' => (int) $s['fine']->id, 'parole' => 'la fine della locazione di Ines del 1 luglio 2026', 'persone' => ['Ines'], 'con_parte_a_mano' => false],
    ]);
    expect(str_contains(json_encode($props), '"registro"'))->toBeFalse();
});

it('un passaggio annullato, uno senza registro e il passaggio del box non sono fra quelli a cui legare; l\'inizio di una locazione si dice per quello che è', function () {
    $s = sl53Scenario($this, box: true);
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-12-01'))['subentro'];
    $s['fine']->forceFill(['registro' => null])->save();

    $legabili = sl53wUnita(sl53wPagina($this, $s), (int) $s['unita']->id)['passaggi_legabili'] ?? null;
    expect($legabili)->toBeArray();
    expect(collect($legabili)->pluck('parole')->all())->toBe(['l\'inizio della locazione di Luca del 1 dicembre 2026', 'la vendita del 1 novembre 2026']);
    expect(collect($legabili)->pluck('id')->all())->toBe([(int) $inizio->id, (int) $s['vendita']->id]);
    // Sul box la vendita c'è, con il passaggio padre.
    expect(collect(sl53wUnita(sl53wPagina($this, $s), (int) $s['box']->id)['passaggi_legabili'] ?? [])->pluck('id')->all())->toBe([(int) $s['vendita']->id]);

    sl53Annulla($this, $s, $inizio, 'Contratto mai firmato, annullo l\'inizio');
    expect(collect(sl53wUnita(sl53wPagina($this, $s), (int) $s['unita']->id)['passaggi_legabili'] ?? [])->pluck('id')->all())->toBe([(int) $s['vendita']->id]);
});

it('una riga legata libera si modifica, e il legame resta', function () {
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);
    $riga = Saldo::where('anagrafica_id', $s['persone']['Elsa']->id)->where('saldo_iniziale', 6016)->firstOrFail();

    $r = $this->actingAs($this->user)->from('/saldi')->patch(route('admin.gestionale.saldi.update', [$s['c'], $riga]), ['saldo_iniziale' => 5000, 'gestione_id' => $s['g']->id]);

    expect(sl53Errori($r))->toBe([]);
    $riga->refresh();
    expect((int) $riga->saldo_iniziale)->toBe(5000)
        ->and($riga->passaggio_id === null ? null : (int) $riga->passaggio_id)->toBe((int) $s['vendita']->id);
});

it('una riga legata non cambia gestione', function () {
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);
    $riga = Saldo::where('anagrafica_id', $s['persone']['Elsa']->id)->where('saldo_iniziale', 6016)->firstOrFail();
    $altra = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Straordinaria facciata', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'attiva' => true]);

    $r = $this->actingAs($this->user)->from('/saldi')->patch(route('admin.gestionale.saldi.update', [$s['c'], $riga]), ['saldo_iniziale' => 6016, 'gestione_id' => $altra->id]);

    expect(sl53Errori($r)['gestione_id'][0] ?? null)->toBe('Un saldo legato a un passaggio resta sulla sua gestione: per spostarlo, eliminalo e registralo di nuovo.')
        ->and((int) $riga->fresh()->gestione_id)->toBe((int) $s['g']->id);
});

it('una riga legata in un piano emesso: modifica ed eliminazione rifiutate con i rimedi del piano, e la riga resta legata', function () {
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);
    $piano = sl53SecondoPiano($s, stato: 'approvato');
    fds50Emetti($this, $s, '2026-11-30', $piano);
    $riga = Saldo::where('anagrafica_id', $s['persone']['Elsa']->id)->where('saldo_iniziale', 6016)->firstOrFail();
    expect($riga->eBloccato())->toBeTrue();

    // Gli errori si leggono subito dopo ogni richiesta: la sessione del test è una sola.
    $erroreModifica = sl53Errori($this->actingAs($this->user)->from('/saldi')->patch(route('admin.gestionale.saldi.update', [$s['c'], $riga]), ['saldo_iniziale' => 5000, 'gestione_id' => $s['g']->id]))['saldo'][0] ?? '';
    $delete = $this->actingAs($this->user)->from('/saldi')->delete(route('admin.gestionale.saldi.destroy', [$s['c'], $riga]));

    expect($erroreModifica)->toStartWith('Non puoi modificare questo saldo: è incluso nel piano rate «Conguaglio 2026», che')
        ->and($delete->status())->toBe(403)
        ->and(Saldo::find($riga->id))->not->toBeNull()
        ->and($riga->fresh()->passaggio_id === null ? null : (int) $riga->fresh()->passaggio_id)->toBe((int) $s['vendita']->id);
});

it('una persona con un saldo legato non si elimina, e il messaggio dice quanto somma e di toglierlo dai saldi dell\'unità; seguito, la persona si elimina', function () {
    // Dora era coinquilina di Ines fino al 30/6 (una riga chiusa sull'unità); la sua riga legata alla vendita; poi staccata dal condominio.
    $s = sl53Scenario($this);
    $dora = Anagrafica::forceCreate(['nome' => 'Dora', 'email' => 'dora-sl53@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'DORAXSL53000001']);
    $dora->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $dora->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => 50,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => '2026-06-30', 'created_at' => now(), 'updated_at' => now()]);
    $s['persone']['Dora'] = $dora;
    expect(sl53Errori(sl53Posta($this, $s, 'Dora', 1000, (int) $s['vendita']->id)))->toBe([]);
    $dora->condomini()->detach();

    $r = $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));

    expect(Anagrafica::find($dora->id))->not->toBeNull()
        ->and(Saldo::where('anagrafica_id', $dora->id)->count())->toBe(1)
        ->and(sl53Messaggio($r)['message'])->toBe(SL53W_DORA . ' Se non serve più, toglilo dai saldi dell\'unità.')
        ->and(sl53Messaggio($r)['message'])->not->toContain('compare in un passaggio');

    // Seguito dalle rotte: la riga si toglie dai saldi dell'unità, e la persona si elimina.
    $riga = Saldo::where('anagrafica_id', $dora->id)->sole();
    expect(sl53Errori($this->actingAs($this->user)->from('/saldi')->delete(route('admin.gestionale.saldi.destroy', [$s['c'], $riga]))))->toBe([]);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->toBeNull();
});

it('chi vende e chi compra, con un saldo legato, non si eliminano, e il messaggio non indica di togliere il saldo legato: la persona resta lo stesso', function (string $chi) {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);
    $persona = $s['persone'][$chi];
    $persona->condomini()->detach();

    $r = $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $persona));

    expect(sl53Messaggio($r)['message'])->toStartWith('Questa anagrafica compare in un passaggio di titolarità registrato (vendita, locazione, usufrutto o successione): non si può eliminare')
        ->and(sl53Messaggio($r)['message'])->toEndWith('e i saldi legati a un passaggio la nominano.')
        ->and(sl53Messaggio($r)['message'])->not->toContain('toglilo dai saldi')
        ->and(Anagrafica::find($persona->id))->not->toBeNull()
        ->and(Saldo::where('anagrafica_id', $persona->id)->whereNotNull('passaggio_id')->count())->toBe(1);
})->with(['chi vende' => ['Ugo'], 'chi compra' => ['Elsa']]);

it('una persona con un saldo legato in un esercizio che non è quello aperto: il messaggio dice dove sta e che si toglie annullando il passaggio dallo storico dell\'unità, e quella strada c\'è', function () {
    $s = sl53Scenario($this);
    $dora = Anagrafica::forceCreate(['nome' => 'Dora', 'email' => 'dora-sl53e@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'DORAXSL53000002']);
    $dora->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $dora->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => 50,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => '2026-06-30', 'created_at' => now(), 'updated_at' => now()]);
    $s['persone']['Dora'] = $dora;
    expect(sl53Errori(sl53Posta($this, $s, 'Dora', 1000, (int) $s['vendita']->id)))->toBe([]);
    $riga = Saldo::where('anagrafica_id', $dora->id)->firstOrFail();

    // L'esercizio 2026 si chiude e si apre il 2027: i saldi dell'unità mostrano solo l'esercizio aperto.
    $s['e']->update(['stato' => 'chiuso']);
    $nuovo = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($nuovo, $s['g']->id);
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(collect($props['immobili'])->flatMap(fn ($u) => $u['saldi'])->contains(fn ($x) => (int) $x['id'] === (int) $riga->id))->toBeFalse();
    $dora->condomini()->detach();

    $r = $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));

    expect(Anagrafica::find($dora->id))->not->toBeNull()
        ->and(sl53Messaggio($r)['message'])->toEndWith(SL53W_DORA_CHIUSO)
        ->and(sl53Messaggio($r)['message'])->not->toContain('toglilo dai saldi dell\'unità');

    // La strada detta: lo storico non dice niente da fare prima; annullato il passaggio, la riga legata non c'è più, e la persona si elimina.
    expect(fds50Storico($s, $s['vendita']->fresh())['annullabile']['motivo'] ?? null)->toBeNull();
    expect(sl53Errori(sl53Annulla($this, $s, $s['vendita'])))->toBe([])
        ->and(Saldo::find($riga->id))->toBeNull();
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->toBeNull();
});

/**
 * Dora, coinquilina di Ines fino al 30/6 (o inquilina nel periodo dato), con la sua riga legata alla vendita (€ 10,00 a debito), poi staccata dal
 * condominio dopo `$poi`.
 */
function sl53wDora($test, array &$s, string $email, callable $poi, string $dal = '2019-01-01', string $al = '2026-06-30'): Anagrafica
{
    $dora = Anagrafica::forceCreate(['nome' => 'Dora', 'email' => $email, 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => strtoupper(substr(md5($email), 0, 16))]);
    $dora->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $dora->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => 50,
        'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);
    $s['persone']['Dora'] = $dora;
    expect(sl53Errori(sl53Posta($test, $s, 'Dora', 1000, (int) $s['vendita']->id)))->toBe([]);
    $test->travel(1)->minutes();
    $poi();
    // L'esercizio 2026 si chiude e si apre il 2027: i saldi dell'unità mostrano solo l'esercizio aperto.
    $s['e']->update(['stato' => 'chiuso']);
    $nuovo = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($nuovo, $s['g']->id);
    $dora->condomini()->detach();

    return $dora;
}

it('una persona con un saldo legato nell\'esercizio chiuso e un passaggio registrato dopo la vendita: il messaggio rimanda allo storico, che dice di annullare prima quello; seguita, la persona si elimina e i passaggi si registrano di nuovo', function () {
    $s = sl53Scenario($this);
    $dopo = null;
    $dora = sl53wDora($this, $s, 'dora-sl53d@test.it', function () use (&$dopo, $s) {
        $dopo = fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Anna', '2026-12-15'))['subentro'];
        $this->travel(1)->minutes();
    });

    $r = $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->not->toBeNull()
        ->and(sl53Messaggio($r)['message'])->toEndWith(SL53W_DORA_CHIUSO);

    // La strada detta, dalle rotte: lo storico della vendita a Elsa dice che dopo c'è un altro passaggio; prima la vendita ad Anna, poi quella a
    // Elsa; la riga di Dora non c'è più, e Dora si elimina.
    expect(fds50Storico($s, $s['vendita']->fresh())['annullabile']['motivo'] ?? '')->toStartWith('Dopo questo, su Interno 1, c\'è un altro passaggio');
    expect(sl53Errori(sl53Annulla($this, $s, $dopo, 'Annullo per togliere la riga di Dora')))->toBe([]);
    $this->travel(1)->minutes();
    expect(sl53Errori(sl53Annulla($this, $s, $s['vendita'])))->toBe([])
        ->and(Saldo::where('anagrafica_id', $dora->id)->count())->toBe(0);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->toBeNull();
    // I due passaggi, avvenuti davvero, si registrano di nuovo dal primo.
    $this->travel(1)->minutes();
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-01'));
    $this->travel(1)->minutes();
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Anna', '2026-12-15'));
});

it('una persona con un saldo legato nell\'esercizio chiuso, assorbito da un piano in bozza: il messaggio rimanda allo storico, che dice di eliminare prima quel piano; seguita, la vendita si annulla e la persona si elimina', function () {
    $s = sl53Scenario($this);
    $p2 = null;
    $dora = sl53wDora($this, $s, 'dora-sl53p@test.it', function () use (&$p2, $s) {
        $p2 = sl53SecondoPiano($s);
    });
    expect(Saldo::where('anagrafica_id', $dora->id)->first()?->piano_rate_id)->toBe((int) $p2->id);

    $r = $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->not->toBeNull()
        ->and(sl53Messaggio($r)['message'])->toEndWith(SL53W_DORA_CHIUSO);

    // La strada detta, dalle rotte: lo storico dice il piano che ha assorbito la riga; il piano in bozza si elimina, poi la vendita si annulla, e
    // Dora si elimina.
    expect(fds50Storico($s, $s['vendita']->fresh())['annullabile']['motivo'] ?? '')->toContain('«Conguaglio 2026»');
    $this->actingAs($this->user)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $p2]));
    expect(\App\Models\Gestionale\PianoRate::find($p2->id))->toBeNull();
    expect(sl53Errori(sl53Annulla($this, $s, $s['vendita'])))->toBe([])
        ->and(Saldo::where('anagrafica_id', $dora->id)->count())->toBe(0);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->toBeNull();
});

/** Annulla dalla pagina del piano le emissioni delle sue rate con scadenza dopo il giorno dato. */
function sl53wAnnullaEmissioni($test, array $s, \App\Models\Gestionale\PianoRate $piano, string $dopo = '0000-00-00'): void
{
    foreach (DB::table('rate')->where('piano_rate_id', $piano->id)->where('stato', 'emessa')->where('data_scadenza', '>', $dopo)->pluck('id') as $rataId) {
        $test->actingAs($test->user)->from('/piano')->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $piano, $rataId]));
    }
}

/** Il rifiuto dell'eliminazione della persona, dalla rotta: la persona resta, e il messaggio. */
function sl53wEliminaDora($test, Anagrafica $dora): string
{
    $r = $test->actingAs($test->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->not->toBeNull();

    return sl53Messaggio($r)['message'];
}

it('una persona con il saldo legato in un piano emesso dell\'esercizio chiuso, e un passaggio registrato dopo: il messaggio dice dove sta e che la persona resta, e non indica di annullare nessun passaggio', function () {
    $s = sl53Scenario($this);
    $p = null;
    $dopo = null;
    $dora = sl53wDora($this, $s, 'dora-sl53k@test.it', function () use (&$p, &$dopo, $s) {
        $p = sl53SecondoPiano($s, stato: 'approvato');
        fds50Emetti($this, $s, '2026-12-31', $p);
        $this->travel(1)->minutes();
        $dopo = fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Anna', '2026-12-15'))['subentro'];
        $this->travel(1)->minutes();
    });
    $riga = Saldo::with('pianoRate')->where('anagrafica_id', $dora->id)->firstOrFail();
    expect((int) $riga->piano_rate_id)->toBe((int) $p->id)
        ->and($riga->eBloccato())->toBeTrue();

    $messaggio = sl53wEliminaDora($this, $dora);
    expect($messaggio)->toEndWith(SL53W_DORA . ' Non si toglie più quello nel piano «Conguaglio 2026», che non si riscrive più (€ 10,00 a debito di Dora): la persona resta.')
        ->and($messaggio)->not->toContain('annullando')
        ->and($messaggio)->not->toContain('storico dell\'unità');
    // Nessuno dei due passaggi è stato toccato.
    expect($dopo->fresh()->annullato())->toBeFalse()
        ->and($s['vendita']->fresh()->annullato())->toBeFalse();
});

it('una persona con il saldo legato libero nell\'esercizio chiuso e il conguaglio della vendita in un piano emesso: il messaggio dice di annullare la vendita dallo storico, che dice che cosa fare prima; seguito lo storico, la persona si elimina', function () {
    $s = sl53Scenario($this);
    // «Conguaglio 2026» assorbe le coppie ed emette; poi la riga di Dora, libera.
    $p = sl53SecondoPiano($s, stato: 'approvato');
    fds50Emetti($this, $s, '2026-12-31', $p);
    $this->travel(1)->minutes();
    $dora = sl53wDora($this, $s, 'dora-sl53f@test.it', function () {
    });
    expect(Saldo::where('anagrafica_id', $dora->id)->firstOrFail()->is_applicato)->toBeFalse();

    expect(sl53wEliminaDora($this, $dora))->toEndWith(SL53W_DORA_CHIUSO);

    // Lo storico dice che cosa fare prima: riaprire il piano, riportarlo in bozza ed eliminarlo. Fatto dalle rotte, la vendita si annulla e Dora si elimina.
    expect(fds50Storico($s, $s['vendita']->fresh())['annullabile']['motivo'] ?? '')->toContain('«Conguaglio 2026», che ha già quote a giornale');
    sl53wAnnullaEmissioni($this, $s, $p);
    $this->actingAs($this->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $p]), ['approvato' => false]);
    $this->actingAs($this->user)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $p]));
    expect(\App\Models\Gestionale\PianoRate::find($p->id))->toBeNull();
    expect(sl53Errori(sl53Annulla($this, $s, $s['vendita'])))->toBe([])
        ->and(Saldo::where('anagrafica_id', $dora->id)->count())->toBe(0);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->toBeNull();
});

it('una persona con il saldo legato nell\'esercizio chiuso, un passaggio registrato dopo e un piano in bozza che ha assorbito la riga e il conguaglio di quel passaggio: il messaggio rimanda allo storico; seguita, la persona si elimina e i passaggi si registrano di nuovo', function () {
    $s = sl53Scenario($this);
    $p2 = null;
    $dopo = null;
    $dora = sl53wDora($this, $s, 'dora-sl53j@test.it', function () use (&$p2, &$dopo, $s) {
        $dopo = fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Anna', '2026-12-15'))['subentro'];
        $this->travel(1)->minutes();
        $p2 = sl53SecondoPiano($s);
    });
    expect(Saldo::where('subentro_id', $dopo->id)->pluck('piano_rate_id')->map(fn ($id) => (int) $id)->unique()->all())->toBe([(int) $p2->id]);

    expect(sl53wEliminaDora($this, $dora))->toEndWith(SL53W_DORA_CHIUSO);

    // La strada detta, dalle rotte, come la dice lo storico a ogni passo: la vendita ad Anna ha il conguaglio nel piano in bozza, da eliminare; poi la
    // vendita ad Anna, poi quella a Elsa; Dora si elimina, le due vendite si registrano di nuovo.
    expect(fds50Storico($s, $dopo->fresh())['annullabile']['motivo'] ?? '')->toContain('«Conguaglio 2026»');
    $this->actingAs($this->user)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $p2]));
    expect(\App\Models\Gestionale\PianoRate::find($p2->id))->toBeNull();
    expect(sl53Errori(sl53Annulla($this, $s, $dopo, 'Annullo per togliere la riga di Dora')))->toBe([]);
    $this->travel(1)->minutes();
    expect(sl53Errori(sl53Annulla($this, $s, $s['vendita'])))->toBe([])
        ->and(Saldo::where('anagrafica_id', $dora->id)->count())->toBe(0);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->toBeNull();
    $this->travel(1)->minutes();
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-11-01'));
    $this->travel(1)->minutes();
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Anna', '2026-12-15'));
});

it('una persona con i saldi legati a due vendite nell\'esercizio chiuso e le rate passate già emesse: il messaggio dice quanto sommano e di annullare le due vendite dallo storico; seguito, la persona si elimina', function () {
    $s = sl53Scenario($this);
    $dopo = null;
    $dora = sl53wDora($this, $s, 'dora-sl53g@test.it', function () use (&$dopo, &$s) {
        $dopo = fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Anna', '2026-12-15'))['subentro'];
        $this->travel(1)->minutes();
        expect(sl53Errori(sl53Posta($this, $s, 'Dora', 500, (int) $dopo->id)))->toBe([]);
        $this->travel(1)->minutes();
        fds50Emetti($this, $s, '2026-12-31');
        $this->travel(1)->minutes();
    });

    expect(sl53wEliminaDora($this, $dora))->toEndWith(SL53W_NOMINA . ' I saldi del Wallet legati alla vendita del 1 novembre 2026 e alla vendita del 15 dicembre 2026 sommano oggi € 15,00 a debito di Dora. Quelli nell\'esercizio «Esercizio 2026», che è chiuso (€ 10,00 a debito di Dora, € 5,00 a debito di Dora), i saldi dell\'unità non li mostrano: se non servono più, si tolgono annullando la vendita del 1 novembre 2026 e la vendita del 15 dicembre 2026 dallo storico dell\'unità, che dice se prima c\'è qualcosa da fare, e un passaggio avvenuto davvero poi si registra di nuovo.');

    // La strada detta, dalle rotte: la vendita ad Anna, l'ultima registrata; poi lo storico dice di annullare le emissioni delle rate passate con la
    // vendita a Elsa; fatto, la vendita si annulla, e Dora si elimina.
    expect(sl53Errori(sl53Annulla($this, $s, $dopo, 'Annullo per togliere le righe di Dora')))->toBe([]);
    $this->travel(1)->minutes();
    expect(fds50Storico($s, $s['vendita']->fresh())['annullabile']['motivo'] ?? '')->toStartWith('Dopo il passaggio sono state emesse a Elsa');
    sl53wAnnullaEmissioni($this, $s, $s['piano'], '2026-10-31');
    expect(fds50Storico($s, $s['vendita']->fresh())['annullabile']['motivo'] ?? null)->toBeNull();
    expect(sl53Errori(sl53Annulla($this, $s, $s['vendita'])))->toBe([])
        ->and(Saldo::where('anagrafica_id', $dora->id)->count())->toBe(0);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->toBeNull();
});

/** Il rifiuto, con la riga di Dora portata a zero dal Wallet nell'esercizio 2026 chiuso. */
const SL53W_DORA_ZERO = SL53W_NOMINA . ' Il saldo del Wallet legato alla vendita del 1 novembre 2026 è oggi di € 0,00 per Dora. Quello nell\'esercizio «Esercizio 2026», che è chiuso (€ 0,00 per Dora), i saldi dell\'unità non lo mostrano: se non serve più, si toglie annullando la vendita del 1 novembre 2026 dallo storico dell\'unità, che dice se prima c\'è qualcosa da fare, e un passaggio avvenuto davvero poi si registra di nuovo.';

it('una persona con il saldo legato portato a € 0,00 e l\'esercizio chiuso: la riga blocca l\'eliminazione anche a zero, e il messaggio dice dove toglierla; seguito, la vendita si annulla e la persona si elimina', function () {
    $s = sl53Scenario($this);
    $dora = sl53wDora($this, $s, 'dora-sl53z@test.it', function () use (&$s) {
        $riga = Saldo::where('passaggio_id', $s['vendita']->id)->where('anagrafica_id', $s['persone']['Dora']->id)->sole();
        expect(sl53Errori($this->actingAs($this->user)->from('/saldi')->put(route('admin.gestionale.saldi.update', [$s['c'], $riga]), ['saldo_iniziale' => 0, 'gestione_id' => $s['g']->id])))->toBe([]);
        $this->travel(1)->minutes();
    });
    expect((int) Saldo::where('anagrafica_id', $dora->id)->sole()->saldo_iniziale)->toBe(0);

    expect(sl53wEliminaDora($this, $dora))->toEndWith(SL53W_DORA_ZERO);

    // Lo storico non chiede niente prima: la vendita si annulla, la riga a zero si toglie con lei, e la persona si elimina.
    expect(fds50Storico($s, $s['vendita']->fresh())['annullabile']['motivo'] ?? null)->toBeNull();
    expect(sl53Errori(sl53Annulla($this, $s, $s['vendita'])))->toBe([])
        ->and(Saldo::where('anagrafica_id', $dora->id)->count())->toBe(0);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->toBeNull();
});

/** Il rifiuto, con le righe di Dora legate alla fine di Ines (€ 7,00) e alla vendita (€ 10,00), nell'esercizio 2026 chiuso. */
const SL53W_DORA_INES = SL53W_NOMINA . ' I saldi del Wallet legati alla fine della locazione di Ines del 1 luglio 2026 e alla vendita del 1 novembre 2026 sommano oggi € 17,00 a debito di Dora. Quelli nell\'esercizio «Esercizio 2026», che è chiuso (€ 10,00 a debito di Dora, € 7,00 a debito di Dora), i saldi dell\'unità non li mostrano: se non servono più, si tolgono annullando la fine della locazione di Ines del 1 luglio 2026 e la vendita del 1 novembre 2026 dallo storico dell\'unità, che dice se prima c\'è qualcosa da fare, e un passaggio avvenuto davvero poi si registra di nuovo.';

it('una persona con i saldi legati alla fine di Ines e alla vendita, nell\'esercizio chiuso: il messaggio dice di annullare quei passaggi dallo storico, che dice di annullare prima la vendita; seguito, la persona si elimina', function () {
    $s = sl53Scenario($this);
    // Dora inquilina prima di Ines: annullata la fine, la riga di Ines che si riapre non si sovrappone alla sua.
    $dora = sl53wDora($this, $s, 'dora-sl53h@test.it', function () use (&$s) {
        expect(sl53Errori(sl53Posta($this, $s, 'Dora', 700, (int) $s['fine']->id)))->toBe([]);
        $this->travel(1)->minutes();
    }, '2015-01-01', '2018-12-31');

    expect(sl53wEliminaDora($this, $dora))->toEndWith(SL53W_DORA_INES);

    // Dalle rotte, dall'ultimo registrato: prima la vendita, poi la fine di Ines, che lo storico dice annullabile.
    expect(fds50Storico($s, $s['fine']->fresh())['annullabile']['motivo'] ?? '')->toStartWith('Dopo questo, su Interno 1, c\'è un altro passaggio');
    expect(sl53Errori(sl53Annulla($this, $s, $s['vendita'])))->toBe([]);
    $this->travel(1)->minutes();
    expect(fds50Storico($s, $s['fine']->fresh())['annullabile']['motivo'] ?? null)->toBeNull();
    expect(sl53Errori(sl53Annulla($this, $s, $s['fine'], 'Annullo per togliere le righe di Dora')))->toBe([])
        ->and(Saldo::where('anagrafica_id', $dora->id)->count())->toBe(0);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->toBeNull();
});

it('una persona con i saldi legati alla fine di Ines e alla vendita, e un passaggio registrato dopo: il messaggio dice di annullarli dallo storico, che dice di annullare prima quello dopo; seguito, la persona si elimina', function () {
    $s = sl53Scenario($this);
    $dopo = null;
    $dora = sl53wDora($this, $s, 'dora-sl53t@test.it', function () use (&$s, &$dopo) {
        expect(sl53Errori(sl53Posta($this, $s, 'Dora', 700, (int) $s['fine']->id)))->toBe([]);
        $this->travel(1)->minutes();
        $dopo = fds50Registra($this, $s, fds50ModuloVendita($s, 'Elsa', 'Anna', '2026-12-15'))['subentro'];
        $this->travel(1)->minutes();
    }, '2015-01-01', '2018-12-31');

    expect(sl53wEliminaDora($this, $dora))->toEndWith(SL53W_DORA_INES);
    expect(fds50Storico($s, $s['vendita']->fresh())['annullabile']['motivo'] ?? '')->toStartWith('Dopo questo, su Interno 1, c\'è un altro passaggio');

    // Dalle rotte, dall'ultimo registrato: la vendita ad Anna, la vendita a Elsa, la fine di Ines; Dora si elimina.
    expect(sl53Errori(sl53Annulla($this, $s, $dopo, 'Annullo per togliere le righe di Dora')))->toBe([]);
    $this->travel(1)->minutes();
    expect(sl53Errori(sl53Annulla($this, $s, $s['vendita'])))->toBe([]);
    $this->travel(1)->minutes();
    expect(sl53Errori(sl53Annulla($this, $s, $s['fine'], 'Annullo per togliere le righe di Dora')))->toBe([])
        ->and(Saldo::where('anagrafica_id', $dora->id)->count())->toBe(0);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->toBeNull();
});

it('una persona con il saldo legato nell\'esercizio chiuso, assorbito da un piano in bozza, e le rate passate con la vendita già emesse: il messaggio rimanda allo storico, che dice che cosa fare prima; seguito lo storico, la persona si elimina', function () {
    $s = sl53Scenario($this);
    $p2 = null;
    $dora = sl53wDora($this, $s, 'dora-sl53r@test.it', function () use (&$p2, $s) {
        fds50Emetti($this, $s, '2026-12-31');
        $this->travel(1)->minutes();
        $p2 = sl53SecondoPiano($s);
    });
    expect(Saldo::where('anagrafica_id', $dora->id)->first()?->piano_rate_id)->toBe((int) $p2->id);

    expect(sl53wEliminaDora($this, $dora))->toEndWith(SL53W_DORA_CHIUSO);
    expect(fds50Storico($s, $s['vendita']->fresh())['annullabile']['motivo'] ?? '')->toContain('«Conguaglio 2026»');

    // Dalle rotte: il piano si elimina; lo storico dice di annullare le emissioni delle rate passate a Elsa; fatto, la vendita si annulla e Dora si elimina.
    $this->actingAs($this->user)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $p2]));
    expect(\App\Models\Gestionale\PianoRate::find($p2->id))->toBeNull();
    expect(fds50Storico($s, $s['vendita']->fresh())['annullabile']['motivo'] ?? '')->toStartWith('Dopo il passaggio sono state emesse a Elsa');
    sl53wAnnullaEmissioni($this, $s, $s['piano'], '2026-10-31');
    expect(sl53Errori(sl53Annulla($this, $s, $s['vendita'])))->toBe([])
        ->and(Saldo::where('anagrafica_id', $dora->id)->count())->toBe(0);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $dora));
    expect(Anagrafica::find($dora->id))->toBeNull();
});

it('il selettore del modale non dice «c\'è una parte da regolare a mano» per una fermata che non vale più: annullato il conguaglio della fine', function () {
    $s = sl53Scenario($this);
    $legabili = fn () => collect(collect($this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->viewData('page')['props']['immobili'])
        ->firstWhere('id', $s['unita']->id)['passaggi_legabili'])->keyBy('id');
    expect($legabili()[(int) $s['vendita']->id]['con_parte_a_mano'])->toBeTrue();

    expect(sl53Errori(sl53AnnullaConguaglio($this, $s, $s['fine'])))->toBe([]);

    expect($legabili()[(int) $s['vendita']->id]['con_parte_a_mano'])->toBeFalse();
});

it('il selettore del modale non dice «c\'è una parte da regolare a mano» per l\'inizio che azzera la fermata', function () {
    $s = sl53Scenario($this);
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    $ridotte = \App\Models\Gestionale\Subentro::find($inizio->id)->registro['fermata_ridotta'] ?? [];
    expect(collect($ridotte)->map(fn ($f) => [(int) $f['prima'], (int) $f['residuo']])->all())->toBe([[6016, 0]]);

    $legabili = collect(collect($this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->viewData('page')['props']['immobili'])
        ->firstWhere('id', $s['unita']->id)['passaggi_legabili'])->keyBy('id');

    expect($legabili[(int) $inizio->id]['con_parte_a_mano'])->toBeFalse();
});

/** I passaggi a cui il modale lega una riga sull'Interno 1, per id. */
function sl53wLegabili($test, array $s): \Illuminate\Support\Collection
{
    return collect(sl53wUnita(sl53wPagina($test, $s), (int) $s['unita']->id)['passaggi_legabili'])->keyBy('id');
}

it('il selettore del modale non dice «c\'è una parte da regolare a mano» per la vendita la cui fermata un inizio ha azzerato: la voce sta nella vendita, il residuo nell\'inizio', function () {
    $s = sl53Scenario($this);
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    // Misurato: l'inizio dello stesso giorno della vendita toglie alla fermata tutti i suoi giorni, € 60,16 → € 0,00.
    $ridotte = \App\Models\Gestionale\Subentro::find($inizio->id)->registro['fermata_ridotta'] ?? [];
    expect(collect($ridotte)->map(fn ($f) => [(int) $f['passaggio_id'], (int) $f['prima'], (int) $f['residuo']])->all())->toBe([[(int) $s['vendita']->id, 6016, 0]]);

    $legabili = sl53wLegabili($this, $s);

    expect($legabili[(int) $s['vendita']->id]['con_parte_a_mano'])->toBeFalse()
        ->and($legabili[(int) $inizio->id]['con_parte_a_mano'])->toBeFalse()
        ->and($legabili[(int) $s['fine']->id]['con_parte_a_mano'])->toBeFalse();
});

it('controprova — la fine del conduttore che fa risalire la parte azzerata: la vendita torna a dire «c\'è una parte da regolare a mano»', function () {
    $s = sl53Scenario($this);
    fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'));
    $this->travel(1)->minutes();
    $fine = fds50Registra($this, $s, fds50ModuloFine($s, null, '2026-12-01', 'Luca'))['subentro'];
    // Misurato: con la fine di Luca i giorni dal 1/12 tornano a chi paga al posto dell'inquilino, e la parte verso Elsa sale da € 0,00.
    $risale = \App\Models\Gestionale\Subentro::find($fine->id)->registro['fermata_risale'] ?? [];
    expect(collect($risale)->map(fn ($f) => [(int) $f['passaggio_id'], (int) $f['prima']])->all())->toBe([[(int) $s['vendita']->id, 0]])
        ->and((int) ($risale[0]['dopo'] ?? 0))->toBeGreaterThan(0);

    expect(sl53wLegabili($this, $s)[(int) $s['vendita']->id]['con_parte_a_mano'])->toBeTrue();
});

it('controprova — l\'inizio che riduce la fermata senza azzerarla: la vendita dice ancora «c\'è una parte da regolare a mano»', function () {
    $s = fds50Scenario([['Ugo', 'proprietario', 100]]);
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    fds50Fine($this, $s, \App\Models\Gestionale\Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($this, $s, '2026-09-30');
    $this->travel(1)->minutes();
    $s['vendita'] = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    $this->travel(1)->minutes();
    $inizio = fds50Registra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    // Misurato (il caso della parte che risale): € 90,74 → € 30,58.
    $ridotte = \App\Models\Gestionale\Subentro::find($inizio->id)->registro['fermata_ridotta'] ?? [];
    expect(collect($ridotte)->map(fn ($f) => [(int) $f['passaggio_id'], (int) $f['prima'], (int) $f['residuo']])->all())->toBe([[(int) $s['vendita']->id, 9074, 3058]]);

    $legabili = sl53wLegabili($this, $s);

    expect($legabili[(int) $s['vendita']->id]['con_parte_a_mano'])->toBeTrue()
        ->and($legabili[(int) $inizio->id]['con_parte_a_mano'])->toBeTrue();
});

it('controprova — un\'unità con un saldo legato non si elimina: ha un passaggio registrato (verde anche prima: la guardia dei passaggi)', function () {
    $s = sl53Scenario($this);
    sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id);

    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.destroy', [$s['c'], $s['unita']]));

    expect(\App\Models\Immobile::find($s['unita']->id))->not->toBeNull()
        ->and(Saldo::where('anagrafica_id', $s['persone']['Elsa']->id)->where('saldo_iniziale', 6016)->count())->toBe(1);
});

it('aperta e salvata senza toccare niente, la riga legata resta com\'era: un credito di € 1.234,57 e un debito di € 0,29', function () {
    // Il corpo che il pannello manda alla modifica in linea senza cambiare la cifra: il segno della colonna e la gestione della riga.
    $s = sl53Scenario($this);
    foreach ([['Ugo', -123457], ['Elsa', 29]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $s['vendita']->id)))->toBe([]);
    }
    $righe = Saldo::whereIn('saldo_iniziale', [-123457, 29])->orderBy('id')->get();
    expect($righe)->toHaveCount(2);
    $prima = $righe->map(fn (Saldo $x) => [(int) $x->anagrafica_id, (int) $x->saldo_iniziale, (int) $x->gestione_id, (int) $x->esercizio_id, $x->passaggio_id === null ? null : (int) $x->passaggio_id])->all();

    foreach ($righe as $x) {
        $r = $this->actingAs($this->user)->from('/saldi')->patch(route('admin.gestionale.saldi.update', [$s['c'], $x]), ['saldo_iniziale' => (int) $x->saldo_iniziale, 'gestione_id' => (int) $x->gestione_id]);
        expect(sl53Errori($r))->toBe([]);
    }

    expect(Saldo::whereIn('id', $righe->pluck('id'))->orderBy('id')->get()->map(fn (Saldo $x) => [(int) $x->anagrafica_id, (int) $x->saldo_iniziale, (int) $x->gestione_id, (int) $x->esercizio_id, $x->passaggio_id === null ? null : (int) $x->passaggio_id])->all())
        ->toBe($prima)
        ->and(array_column($prima, 4))->toBe([(int) $s['vendita']->id, (int) $s['vendita']->id]);
});
