<?php

/**
 * 1.11.0-beta.53 (Coda 244) — la porta del saldo legato a un passaggio: il modale del Wallet manda `passaggio_id` con la riga.
 *
 * La riga legata è una riga del Wallet come le altre (origine «manuale», l'esercizio aperto, la persona e la cifra le sceglie
 * l'amministratore), che porta il passaggio per cui è scritta: il passaggio padre (il capo, anche per una riga sul box passato con lui),
 * dello stesso condominio, non annullato, la cui famiglia comprende l'unità della riga; la persona ha o ha avuto una riga di titolarità su
 * quell'unità; la gestione è fra quelle attive del condominio; l'importo non è € 0,00 (una riga legata a zero non sposta niente; senza
 * legame una riga a zero si scrive come prima). Ogni rifiuto dice il campo e la ragione, e non scrive niente.
 *
 * Il posto del Wallet (un saldo per persona, gestione, unità ed esercizio) non conta né le righe scritte da un passaggio né quelle legate
 * a un passaggio: la riga legata si scrive anche accanto a un riporto, e il riporto accanto a lei. Il doppione delle righe legate ha la
 * sua chiave (passaggio, persona, gestione, unità ed esercizio) e conta solo le righe che si possono ancora modificare: una riga legata
 * ferma in un piano con quote a giornale non impedisce quella che la corregge.
 *
 * Le cifre: la fermata della vendita del 1/11 è € 60,16 (6016), a carico di Ugo, verso Elsa (vedi `Beta53SaldoLegatoScenari.php`).
 *
 * Che cosa NON copre: due richieste davvero contemporanee (sqlite non mostra i blocchi: il doppio clic si prova in sequenza); la pagina
 * (in `Beta53SaldoLegatoWalletTest` e nei test del modale); il ramo senza passaggio con una persona di un altro condominio (Coda 274,
 * fuori da questa beta); MySQL.
 */

use App\Models\Anagrafica;
use App\Models\Esercizio;
use App\Models\Gestionale\Subentro;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

/** Il primo errore del campo dato, o stringa vuota. */
function sl53pErrore($r, string $campo): string
{
    return (string) (sl53Errori($r)[$campo][0] ?? '');
}

const SL53P_PASSAGGIO_ALTRUI = 'Il passaggio scelto non è di questo condominio, o è stato annullato.';
const SL53P_PERSONA_ESTRANEA = 'Questa persona non ha mai avuto una riga su questa unità: un saldo legato a un passaggio è di una delle sue persone.';

// --- La riga legata si scrive ----------------------------------------------------------------------------------------------------

it('il saldo legato si scrive sull\'esercizio aperto, con il passaggio, origine manuale e senza subentro_id', function () {
    $s = sl53Scenario($this);
    expect($s['cifra'])->toBe(6016);

    $r = sl53Posta($this, $s, 'Ugo', -6016, (int) $s['vendita']->id);

    expect(sl53Errori($r))->toBe([]);
    $riga = Saldo::where('anagrafica_id', $s['persone']['Ugo']->id)->where('saldo_iniziale', -6016)->firstOrFail();
    expect($riga->passaggio_id === null ? null : (int) $riga->passaggio_id)->toBe((int) $s['vendita']->id)
        ->and($riga->origine)->toBe('manuale')
        ->and($riga->subentro_id)->toBeNull()
        ->and((int) $riga->esercizio_id)->toBe((int) $s['e']->id)
        ->and((bool) $riga->is_applicato)->toBeFalse();
});

it('il box passato con la vendita: la riga sul box si lega al passaggio padre, non a quello del box', function () {
    $s = sl53Scenario($this, box: true);
    $figlio = Subentro::where('subentro_padre_id', $s['vendita']->id)->firstOrFail();
    expect((int) $figlio->immobile_id)->toBe((int) $s['box']->id);

    $r = sl53Posta($this, $s, 'Elsa', 1000, (int) $s['vendita']->id, ['immobile_id' => $s['box']->id]);

    expect(sl53Errori($r))->toBe([]);
    $riga = Saldo::where('immobile_id', $s['box']->id)->where('anagrafica_id', $s['persone']['Elsa']->id)->firstOrFail();
    expect($riga->passaggio_id === null ? null : (int) $riga->passaggio_id)->toBe((int) $s['vendita']->id);
});

it('un\'altra gestione attiva del condominio: la riga legata si accetta (la gestione la sceglie l\'amministratore)', function () {
    $s = sl53Scenario($this);
    $altra = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Straordinaria facciata', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'attiva' => true]);
    legaAEsercizio($s['e'], $altra->id);

    $r = sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id, ['gestione_id' => $altra->id]);

    expect(sl53Errori($r))->toBe([]);
    $riga = Saldo::where('gestione_id', $altra->id)->where('anagrafica_id', $s['persone']['Elsa']->id)->firstOrFail();
    expect($riga->passaggio_id === null ? null : (int) $riga->passaggio_id)->toBe((int) $s['vendita']->id);
});

it('fine di un conduttore: Ugo, con un riporto, ed Elsa, le persone della fermata, si legano alla fine della locazione di Luca', function () {
    // Le persone della fermata non sono chi esce (Luca) né chi entra (nessuno): sono chi ha la coppia (Ugo) e chi paga dopo (Elsa). Tutti e due
    // hanno una riga sull'unità. La cifra è quella di O-DL7 (`Beta51PassaggiFineConduttoreTest`): 26820.
    $s = b51aDl7ConVendita($this);
    b51aSaldoManuale($s, 'Ugo', 5000);
    $fineLuca = b51aRegistraFine($this, $s, 'Luca', '2026-04-18');
    expect(collect(fds50Fermate($fineLuca))->map(fn ($f) => [$f['persona'], (int) $f['cifra']])->all())->toBe([['Ugo', 26820]]);

    foreach ([['Ugo', -26820], ['Elsa', 26820]] as [$chi, $cents]) {
        expect(sl53Errori(sl53Posta($this, $s, $chi, $cents, (int) $fineLuca->id)))->toBe([], "La riga legata di {$chi} alla fine di Luca è rifiutata.");
    }
    expect(sl53Legate($s, $fineLuca))->toBe(['Elsa' => 26820, 'Ugo' => -26820]);
});

// --- Il posto e il doppione ------------------------------------------------------------------------------------------------------

it('il posto non è occupato: con la riga legata di Ugo il suo riporto si scrive ancora, e un secondo riporto no', function () {
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Ugo', -6016, (int) $s['vendita']->id)))->toBe([]);

    // Gli errori si leggono subito dopo ogni richiesta: la sessione del test è una sola.
    $erroriRiporto = sl53Errori(sl53Posta($this, $s, 'Ugo', 12345, null));
    $erroreSecondo = sl53pErrore(sl53Posta($this, $s, 'Ugo', 500, null), 'anagrafica_id');

    expect($erroriRiporto)->toBe([])
        ->and($erroreSecondo)->toBe('Esiste già un saldo per questa anagrafica in questa gestione.')
        ->and(collect(sl53Righe($s))->filter(fn ($x) => $x[1] === 'Ugo' && $x[3] === null)->map(fn ($x) => [$x[2], $x[4]])->values()->all())
        ->toBe([[-6016, (int) $s['vendita']->id], [12345, null]]);
});

it('il posto non è occupato: con il riporto di Elsa la sua riga legata si scrive', function () {
    $s = sl53Scenario($this, ['Elsa' => 7000]);

    $r = sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id);

    expect(sl53Errori($r))->toBe([])
        ->and(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6016]);
});

it('il doppio clic: il secondo POST uguale si rifiuta con «ha già un saldo legato» e la riga legata resta una', function () {
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);

    $secondo = sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id);

    expect(sl53pErrore($secondo, 'anagrafica_id'))->toBe('Questa persona ha già un saldo legato a questo passaggio su questa gestione e su questa unità: modifica quello.')
        ->and(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 6016])
        ->and(Saldo::where('anagrafica_id', $s['persone']['Elsa']->id)->where('saldo_iniziale', 6016)->count())->toBe(1);
});

it('una riga legata ferma in un piano con quote a giornale non impedisce la riga legata che la corregge', function () {
    // La riga di Elsa entra nel «Conguaglio 2026», che emette la sua prima rata: la riga non si modifica più (il 422 del piano fermo). La
    // correzione è un'altra riga legata, di segno opposto: il doppione conta solo le righe legate che si possono ancora modificare.
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id)))->toBe([]);
    $piano = sl53SecondoPiano($s, genera: true, stato: 'approvato');
    fds50Emetti($this, $s, '2026-11-30', $piano);
    $ferma = Saldo::where('anagrafica_id', $s['persone']['Elsa']->id)->where('saldo_iniziale', 6016)->whereNull('subentro_id')->firstOrFail();
    expect($ferma->eBloccato())->toBeTrue();

    $correzione = sl53Posta($this, $s, 'Elsa', -6016, (int) $s['vendita']->id);

    expect(sl53Errori($correzione))->toBe([])
        ->and(sl53Legate($s, $s['vendita']))->toBe(['Elsa' => 0])
        ->and(Saldo::where('anagrafica_id', $s['persone']['Elsa']->id)->whereNull('subentro_id')->count())->toBe(2);
});

it('stessa persona, stesso passaggio, esercizio diverso: la riga legata dell\'esercizio di prima non è un doppione di quella dell\'esercizio aperto', function () {
    $s = sl53Scenario($this);
    $prima = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    legaAEsercizio($prima, $s['g']->id);
    Saldo::create(['esercizio_id' => $prima->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['Elsa']->id, 'immobile_id' => $s['unita']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 3000, 'origine' => 'manuale', 'is_applicato' => false, 'passaggio_id' => $s['vendita']->id]);

    $r = sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id);

    // Le righe di Elsa scritte a mano (la coppia della vendita, con `subentro_id`, resta fuori), per esercizio: prima l'aperto, creato per primo.
    expect(sl53Errori($r))->toBe([])
        ->and(Saldo::where('anagrafica_id', $s['persone']['Elsa']->id)->whereNull('subentro_id')->get()->map(fn ($x) => [(int) $x->esercizio_id, (int) $x->saldo_iniziale, $x->passaggio_id === null ? null : (int) $x->passaggio_id])->sortBy(0)->values()->all())
        ->toBe([[(int) $s['e']->id, 6016, (int) $s['vendita']->id], [(int) $prima->id, 3000, (int) $s['vendita']->id]]);
});

// --- I rifiuti: il campo, la ragione, nessuna scrittura ------------------------------------------------------------------------

it('un passaggio di un altro condominio: rifiuto sul passaggio e nessuna riga', function () {
    $s = sl53Scenario($this);
    $altro = sl53Scenario($this);
    $prima = Saldo::count();

    $r = sl53Posta($this, $s, 'Elsa', 6016, (int) $altro['vendita']->id);

    expect(sl53pErrore($r, 'passaggio_id'))->toBe(SL53P_PASSAGGIO_ALTRUI)
        ->and(Saldo::count())->toBe($prima);
});

it('un passaggio annullato: rifiuto sul passaggio e nessuna riga', function () {
    $s = sl53Scenario($this);
    expect(sl53Messaggio(sl53Annulla($this, $s, $s['vendita']))['message'])->toStartWith('Passaggio annullato.');
    $prima = Saldo::count();

    $r = sl53Posta($this, $s, 'Ugo', -6016, (int) $s['vendita']->id);

    expect(sl53pErrore($r, 'passaggio_id'))->toBe(SL53P_PASSAGGIO_ALTRUI)
        ->and(Saldo::count())->toBe($prima);
});

it('il passaggio del box al posto del passaggio padre: rifiuto sul passaggio e nessuna riga', function () {
    $s = sl53Scenario($this, box: true);
    $figlio = Subentro::where('subentro_padre_id', $s['vendita']->id)->firstOrFail();
    $prima = Saldo::count();

    $r = sl53Posta($this, $s, 'Elsa', 1000, (int) $figlio->id, ['immobile_id' => $s['box']->id]);

    expect(sl53pErrore($r, 'passaggio_id'))->toBe(SL53P_PASSAGGIO_ALTRUI)
        ->and(Saldo::count())->toBe($prima);
});

it('un\'unità fuori dalla famiglia del passaggio: rifiuto sul passaggio e nessuna riga', function () {
    $s = sl53Scenario($this);
    $interno2 = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'SL53-I2-' . $s['unita']->id, 'nome' => 'Interno 2', 'interno' => '2']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Elsa']->id, 'immobile_id' => $interno2->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $prima = Saldo::count();

    $r = sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id, ['immobile_id' => $interno2->id]);

    expect(sl53pErrore($r, 'passaggio_id'))->toBe('Il passaggio scelto non riguarda questa unità.')
        ->and(Saldo::count())->toBe($prima);
});

it('una persona del condominio che non ha mai avuto righe sull\'unità: rifiuto sulla persona e nessuna riga', function () {
    // Luca è nel condominio (fds50Scenario lo aggancia) e non ha righe di titolarità da nessuna parte.
    $s = sl53Scenario($this);
    $prima = Saldo::count();

    $r = sl53Posta($this, $s, 'Luca', 6016, (int) $s['vendita']->id);

    expect(sl53pErrore($r, 'anagrafica_id'))->toBe(SL53P_PERSONA_ESTRANEA)
        ->and(Saldo::count())->toBe($prima);
});

it('una persona con una riga su un\'altra unità del condominio e nessuna su questa: rifiuto sulla persona', function () {
    // La regola è «una riga su questa unità», non «una riga su qualunque unità del condominio».
    $s = sl53Scenario($this);
    $interno2 = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'SL53-I2B-' . $s['unita']->id, 'nome' => 'Interno 2', 'interno' => '2']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Luca']->id, 'immobile_id' => $interno2->id, 'tipologia' => 'inquilino', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2020-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $prima = Saldo::count();

    $r = sl53Posta($this, $s, 'Luca', 6016, (int) $s['vendita']->id);

    expect(sl53pErrore($r, 'anagrafica_id'))->toBe(SL53P_PERSONA_ESTRANEA)
        ->and(Saldo::count())->toBe($prima);
});

it('una persona di un altro condominio: rifiuto sulla persona e nessuna riga', function () {
    $s = sl53Scenario($this);
    $altro = sl53Scenario($this);
    $prima = Saldo::count();

    $r = sl53Posta($this, $s, null, 6016, (int) $s['vendita']->id, ['anagrafica_id' => $altro['persone']['Elsa']->id]);

    expect(sl53pErrore($r, 'anagrafica_id'))->toBe(SL53P_PERSONA_ESTRANEA)
        ->and(Saldo::count())->toBe($prima);
});

it('una gestione non attiva del condominio: rifiuto sulla gestione e nessuna riga', function () {
    $s = sl53Scenario($this);
    $chiusa = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Ordinaria 2025', 'tipo' => 'ordinaria', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'attiva' => false]);
    $prima = Saldo::count();

    $r = sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id, ['gestione_id' => $chiusa->id]);

    expect(sl53pErrore($r, 'gestione_id'))->toBe('La gestione scelta non è fra quelle attive di questo condominio.')
        ->and(Saldo::count())->toBe($prima);
});

it('senza persona: rifiuto, un saldo legato a un passaggio è di una persona', function () {
    $s = sl53Scenario($this);
    $prima = Saldo::count();

    $r = sl53Posta($this, $s, null, 6016, (int) $s['vendita']->id);

    expect(sl53pErrore($r, 'anagrafica_id'))->toBe('Un saldo legato a un passaggio è di una persona: scegli chi.')
        ->and(Saldo::count())->toBe($prima);
});

it('un passaggio registrato senza registro (prima della 1.11.0-beta.37): rifiuto sul passaggio, un saldo per lui si scrive senza legame', function () {
    $s = sl53Scenario($this);
    $s['vendita']->forceFill(['registro' => null])->save();
    $prima = Saldo::count();

    $r = sl53Posta($this, $s, 'Elsa', 6016, (int) $s['vendita']->id);

    expect(sl53pErrore($r, 'passaggio_id'))->toBe('Questo passaggio è stato registrato prima della 1.11.0-beta.37 e non si annulla dal programma: un saldo per lui si scrive senza legame.')
        ->and(Saldo::count())->toBe($prima);
});

it('controprova — un utente senza ruolo né permesso: 403 e nessuna riga (verde anche prima della porta nuova: il gruppo della rotta)', function () {
    $s = sl53Scenario($this);
    $prima = Saldo::count();
    $estraneo = User::factory()->create();

    $r = $this->actingAs($estraneo)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $s['persone']['Elsa']->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 6016, 'passaggio_id' => $s['vendita']->id,
    ]);

    expect($r->status())->toBe(403)
        ->and(Saldo::count())->toBe($prima);
});

it('una riga legata di € 0,00: rifiuto sull\'importo, non sposta niente, e nessuna riga', function () {
    $s = sl53Scenario($this);
    $prima = Saldo::count();

    $r = sl53Posta($this, $s, 'Ugo', 0, (int) $s['vendita']->id);

    expect(sl53pErrore($r, 'saldo_iniziale'))->toBe('Un saldo legato a un passaggio di € 0,00 non sposta niente: scrivi l\'importo della parte.')
        ->and(Saldo::count())->toBe($prima);
});

it('controprova — senza legame una riga di € 0,00 si scrive come prima (verde anche prima: il rifiuto è della sola riga legata)', function () {
    $s = sl53Scenario($this);
    $prima = Saldo::count();

    $r = sl53Posta($this, $s, 'Elsa', 0, null);

    expect(sl53Errori($r))->toBe([])
        ->and(Saldo::count())->toBe($prima + 1);
});
