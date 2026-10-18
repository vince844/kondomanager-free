<?php

/**
 * Il fermo e l'avviso del piano nominano il sottoconto negativo di un capitolo con la copia a zero o negativa.
 *
 * Un capitolo senza tabelle ripartisce la sua copia (`piano_rate_capitoli.importo`) fra i sottoconti in proporzione al loro importo, o in parti uguali se
 * la somma non è maggiore di zero (`CalcoloQuoteService::processaConti`). Con la copia a zero o negativa e un sottoconto con un importo negativo, di spesa o di
 * entrata, il totale del piano non è quello del capitolo: il motore salta il capitolo, o lo divide in parti uguali, e «Ricalcola» risponde con successo. Il
 * fermo (`SpeseNegativeNelPiano`) nomina il sottoconto e non il capitolo, che sta a € 0,00 e non ha niente da correggere; dice di correggere il sottoconto e di
 * ricreare il piano, perché la copia del capitolo resta com'è; il motore non cambia (il capitolo misto è della 1.11.1).
 *
 * Il fermo non scatta dove il piano è giusto: un capitolo a zero con i sottoconti tutti non negativi (la riga che la migrazione della 1.9 scrive per un
 * capitolo vuoto) e una copia negativa senza sottoconti nel piano (il motore salta il capitolo: non addebita niente in positivo).
 *
 * Fonte dell'atteso: i conti a mano di ogni test (spese generali € 1.200,00; un capitolo con un sottoconto di spesa a € 100,00 e uno negativo a −€ 500,00 darebbe
 * € 1.200,00 + € 100,00 − € 500,00 = € 800,00 se il capitolo fosse ripartito per quello che dicono i sottoconti) e il comportamento del motore, che qui non cambia.
 *
 * Corretto il sottoconto, «Ricalcola» passa e non segnala più niente (il motore non cambia) ma la copia del capitolo resta com'è e il capitolo resta sbagliato: il fermo
 * lo dice, e la pagina del piano avvisa di un capitolo con la copia a zero o negativa e dei sottoconti con degli importi, se nessuno spostamento di budget parte da lui. La
 * copia negativa di un capitolo con sottoconti si toglie solo ricreando il piano, e se il capitolo è diventato misto la frase non dice a quanto il piano la addebita.
 *
 * Cosa resta scoperto: la correzione del motore per il capitolo con sottoconti di segno diverso (1.11.1); la schermata (`PianiRateShowAvvisoSpesaNegativa.test.ts`).
 *
 * I test che cominciano con «controprova» proteggono ciò che il fermo non deve fermare: sono verdi prima e dopo.
 */

use App\Enums\StatoPianoRate;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Services\CondominioService;
use App\Services\Gestionale\BudgetCoverageService;
use App\Services\Riparto\AvvisoSpeseNegative;
use App\Services\Riparto\SpeseNegativeNelPiano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52TotaliScenari.php';
require_once __DIR__.'/Beta52VoceEntrataScenari.php';
require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

const BE52_CAP_COSTO = 'Ricreandolo si perdono il riparto manuale dei saldi solidali, i tratti di competenza delle voci, la ricorrenza, gli spostamenti di budget fra le voci e gli importi scelti per le voci, da scrivere di nuovo.';
const BE52_CAP_CORREGGI_AFFITTO = ' Correggi l\'importo del sottoconto «Affitto antenna» nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.';
// Corretto il sottoconto, «Ricalcola» passa e non segnala più niente, ma la copia del capitolo non cambia: la frase lo dice, perché chi preme «Ricalcola» per vedere se basta
// legge «piano ricalcolato con successo» e non sa che il capitolo resta sbagliato.
const BE52_CAP_RICREA = ' Il piano tiene una copia dell\'importo del capitolo «Antenna» e correggere i suoi sottoconti non la cambia: elimina il piano e ricrealo dopo aver corretto i sottoconti, e non ricalcolarlo prima. '
    .'Corretti i sottoconti, «Ricalcola» passa e non segnala più niente, ma il capitolo resta sbagliato. '.BE52_CAP_COSTO;
const BE52_CAP_RICREA_LA_COPIA = ' Il piano tiene una copia dell\'importo del capitolo «Antenna» e correggere i suoi sottoconti non la cambia: elimina il piano e ricrealo. '.BE52_CAP_COSTO;

/** Un condominio con due unità uguali (Ugo e Bice), la voce «Pulizia» da € 1.200,00 e `$s['mk']` per creare voci con le tabelle. */
function be52CapScenario(): array
{
    $s = b52tCondominio();
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s['c'], $e);
    b52tUnita($s, 'A', 'ugo', $s['tabella'], 500.0);
    b52tUnita($s, 'C', 'bice', $s['tabella'], 500.0);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti']);
    $s += ['e' => $e, 'g' => $g, 'pc' => $pc];
    $s['mk'] = function (string $nome, ?int $parent, int $importo, string $tipo, bool $tabella = true) use ($pc, $s) {
        $c = Conto::create(['piano_conto_id' => $pc->id, 'parent_id' => $parent, 'nome' => $nome, 'tipo' => $tipo, 'importo' => $importo]);
        if ($tabella) {
            $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $c->id, 'tabella_id' => $s['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $c;
    };
    $s['pulizia'] = ($s['mk'])('Pulizia', null, 120000, 'spesa');

    return $s;
}

/** Il piano «lascia vuoto» (entra la sola «Pulizia» da € 1.200,00), poi un capitolo «Antenna» con i figli dati: nati prima del piano o dopo. */
function be52CapPianoConCapitolo($test, string $tipoPadre, array $figli, bool $figliDopoIlPiano = false): array
{
    $s = be52CapScenario();
    if (! $figliDopoIlPiano) {
        $s['padre'] = ($s['mk'])('Antenna', null, 0, $tipoPadre, false);
        foreach ($figli as [$nome, $importo, $tipo]) {
            $s['figli'][$nome] = ($s['mk'])($nome, $s['padre']->id, $importo, $tipo);
        }
        sleep(1);
        $s['piano'] = b52tPiano($test, $s, ['capitoli_config' => [['id' => $s['pulizia']->id, 'importo' => '1.200,00', 'note' => '']]]);
    } else {
        $s['piano'] = b52tPiano($test, $s, ['capitoli_config' => [['id' => $s['pulizia']->id, 'importo' => '1.200,00', 'note' => '']]]);
        sleep(1);
        $s['padre'] = ($s['mk'])('Antenna', null, 0, $tipoPadre, false);
        foreach ($figli as [$nome, $importo, $tipo]) {
            $s['figli'][$nome] = ($s['mk'])($nome, $s['padre']->id, $importo, $tipo);
        }
    }

    return $s;
}

function be52CapCopia(array $s, int $importo): void
{
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['piano']->id, 'conto_id' => $s['padre']->id, 'importo' => $importo, 'note' => 'Snapshot sincronizzazione orfani', 'created_at' => now(), 'updated_at' => now()]);
}

function be52CapRicalcola($test, array $s, array $orfani = []): ?array
{
    session()->forget('message');
    $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']->fresh()]), [
        'orphan_ids' => $orfani, 'accetta_scoperti' => true, 'nota_scoperti' => 'Piano di prova', 'accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova',
    ]);

    return session('message');
}

function be52CapPivot(array $s): array
{
    return DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->orderBy('conto_id')->pluck('importo', 'conto_id')->map(fn ($v) => $v === null ? null : (int) $v)->all();
}

/** La migrazione della 1.9 che scrive la riga di un capitolo nella pivot: per un padre a zero la somma dei figli di adesso, mai sotto zero. */
function be52CapMigrazione1_9(): void
{
    (require base_path('database/migrations/2026_02_16_102054_populate_piano_rate_capitoli_pivot.php'))->up();
}

// ─── La copia del capitolo a zero: la riga che scrive la migrazione della 1.9 ───────────────────────────────────

it('un capitolo a zero con un sottoconto di entrata negativo fra i figli ferma il piano, nomina il sottoconto e dice di ricreare il piano', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Affitto antenna', -50000, 'entrata']]);
    be52CapMigrazione1_9();
    $pivot = be52CapPivot($s);
    $totale = b52tQuoteDelMotore($s['piano']);

    $esito = be52CapRicalcola($this, $s);

    // la migrazione ha scritto zero per il capitolo (mai sotto zero), e il motore chiederebbe € 1.200,00 invece di € 800,00
    expect($pivot[$s['padre']->id])->toBe(0)
        ->and($totale)->toBe(120000)
        ->and($esito)->toBe(['type' => 'error', 'message' => 'Il piano non si ricalcola: il sottoconto «Affitto antenna», sotto il capitolo «Antenna», ha un importo negativo (meno € 500,00) e il capitolo ha nel piano una copia a zero: il totale del piano non è quello del capitolo.'
            .BE52_CAP_CORREGGI_AFFITTO.BE52_CAP_RICREA])
        ->and(be52CapPivot($s))->toBe($pivot)
        ->and(b52tQuoteDelMotore($s['piano']->fresh()))->toBe(120000);
});

it('l\'avviso della pagina dice la stessa cosa del fermo, con il sottoconto e la copia a zero', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Affitto antenna', -50000, 'entrata']]);
    be52CapMigrazione1_9();

    $avviso = app(AvvisoSpeseNegative::class)->perLaPagina($s['piano']->fresh());

    expect($avviso)->toBe([
        'titolo' => 'Il piano contiene una voce con un importo negativo',
        'voci' => ['Il sottoconto «Affitto antenna», sotto il capitolo «Antenna», ha un importo negativo (meno € 500,00) e il capitolo ha nel piano una copia a zero: il piano ripartisce la copia anche su di lui, e il totale non è quello del capitolo.'],
        'situazione' => '«Ricalcola» si ferma finché nel piano c\'è una voce con un importo negativo che il motore ripartisce male: il piano resta com\'è, con la cifra sbagliata.',
        'strade' => [trim(BE52_CAP_CORREGGI_AFFITTO), trim(BE52_CAP_RICREA)],
    ]);
});

it('controprova — un capitolo di sottoconti a zero con la riga a zero della migrazione non ferma il piano: il piano resta € 1.200,00', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Imprevisti', 0, 'spesa'], ['Piccole riparazioni', 0, 'spesa']]);
    be52CapMigrazione1_9();

    $esito = be52CapRicalcola($this, $s);

    expect(be52CapPivot($s)[$s['padre']->id])->toBe(0)
        ->and($esito['type'] ?? null)->toBe('success')
        ->and(b52tQuoteDelMotore($s['piano']->fresh()))->toBe(120000)
        ->and(app(AvvisoSpeseNegative::class)->perLaPagina($s['piano']->fresh()))->toBeNull();
});

it('controprova — un capitolo a zero con i sottoconti di segno diverso ma nessuno negativo non ferma il piano', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Affitto antenna', 50000, 'entrata']]);
    be52CapCopia($s, 0);

    expect(be52CapRicalcola($this, $s)['type'] ?? null)->toBe('success');
});

// ─── La copia del capitolo negativa ─────────────────────────────────────────────────────────────────────────────

it('un capitolo «entrata» con la copia a −€ 400,00 e un sottoconto di entrata negativo ferma il piano che il motore sbaglia in silenzio', function () {
    $s = be52CapPianoConCapitolo($this, 'entrata', [['Manutenzione antenna', 10000, 'spesa'], ['Affitto antenna', -50000, 'entrata']]);
    be52CapCopia($s, -40000);
    $quote = b52tQuoteDelMotore($s['piano']);

    $esito = be52CapRicalcola($this, $s);

    expect($quote)->toBe(120000)
        ->and($esito)->toBe(['type' => 'error', 'message' => 'Il piano non si ricalcola: il sottoconto «Affitto antenna», sotto il capitolo «Antenna», ha un importo negativo (meno € 500,00) e il capitolo ha nel piano una copia negativa (meno € 400,00): il totale del piano non è quello del capitolo.'
            .BE52_CAP_CORREGGI_AFFITTO.BE52_CAP_RICREA])
        ->and(b52tQuoteDelMotore($s['piano']->fresh()))->toBe(120000);
});

it('«Sincronizza» di un capitolo con un sottoconto negativo, figli nati dopo il piano, si rifiuta e annulla la scrittura: il capitolo non entra nel piano', function () {
    $s = be52CapPianoConCapitolo($this, 'entrata', [['Manutenzione antenna', 10000, 'spesa'], ['Affitto antenna', -50000, 'entrata']], figliDopoIlPiano: true);
    $pivot = be52CapPivot($s);

    $esito = be52CapRicalcola($this, $s, [$s['padre']->id]);

    // la copia che «Sincronizza» scriverebbe è la somma dei figli (−€ 400,00): il capitolo non c'è ancora, la frase è al condizionale e non dice di ricreare il piano
    expect($esito)->toBe(['type' => 'error', 'message' => 'Il piano non si ricalcola: il sottoconto «Affitto antenna», sotto il capitolo «Antenna», ha un importo negativo (meno € 500,00) e il capitolo avrebbe nel piano una copia negativa (meno € 400,00): il totale del piano non sarebbe quello del capitolo.'
        .BE52_CAP_CORREGGI_AFFITTO])
        ->and(be52CapPivot($s))->toBe($pivot)
        ->and(b52tQuoteDelMotore($s['piano']->fresh()))->toBe(120000);
});

it('un capitolo «spesa» con la copia a −€ 400,00 nomina il sottoconto negativo e non il capitolo: il capitolo, a € 0,00, non ha niente da correggere', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Rimborso assicurazione', -50000, 'spesa']], figliDopoIlPiano: true);
    be52CapCopia($s, -40000);

    $esito = be52CapRicalcola($this, $s);
    $avviso = app(AvvisoSpeseNegative::class)->perLaPagina($s['piano']->fresh());

    expect($esito['type'] ?? null)->toBe('error')
        ->and($esito['message'])->toContain('il sottoconto «Rimborso assicurazione», sotto il capitolo «Antenna», ha un importo negativo (meno € 500,00) e il capitolo ha nel piano una copia negativa (meno € 400,00)')
        ->and($esito['message'])->toContain('Correggi l\'importo del sottoconto «Rimborso assicurazione» nel piano dei conti')
        ->and($esito['message'])->toContain('elimina il piano e ricrealo dopo aver corretto i sottoconti')
        ->and($esito['message'])->not->toContain('«Sincronizza»')
        ->and($esito['message'])->not->toContain('la voce di spesa «Antenna»')
        ->and($avviso['voci'])->toHaveCount(1)
        ->and($avviso['voci'][0])->toStartWith('Il sottoconto «Rimborso assicurazione», sotto il capitolo «Antenna»')
        ->and(implode(' ', $avviso['voci']))->not->toContain('già corretta');
});

it('con due sottoconti negativi sotto lo stesso capitolo la frase li nomina tutti e due e il capitolo una volta sola', function () {
    $s = be52CapPianoConCapitolo($this, 'entrata', [['Manutenzione antenna', 10000, 'spesa'], ['Affitto antenna', -50000, 'entrata'], ['Affitto tetto', -20000, 'entrata']]);
    be52CapCopia($s, -60000);

    $esito = be52CapRicalcola($this, $s);

    expect($esito['message'])->toContain('Il piano non si ricalcola: il capitolo «Antenna» ha nel piano una copia negativa (meno € 600,00) e i sottoconti «Affitto antenna» (meno € 500,00), «Affitto tetto» (meno € 200,00) hanno un importo negativo: il totale del piano non è quello del capitolo.')
        ->and($esito['message'])->toContain('Correggi l\'importo dei sottoconti «Affitto antenna» e «Affitto tetto» nel piano dei conti: se sono proventi, scegli «Entrata» e scrivi l\'importo senza il segno.')
        ->and(substr_count($esito['message'], 'Il piano tiene una copia dell\'importo del capitolo «Antenna»'))->toBe(1);
});

it('corretto il sottoconto, la copia del capitolo resta e il fermo non c\'è più dove il motore salta il capitolo', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Rimborso assicurazione', -50000, 'spesa']], figliDopoIlPiano: true);
    be52CapCopia($s, -40000);
    DB::table('conti')->where('id', $s['figli']['Rimborso assicurazione']->id)->update(['importo' => 50000]);

    $esito = be52CapRicalcola($this, $s);

    // nessun sottoconto è nato fino alla creazione del piano: il motore salta il capitolo, e il piano chiede ancora € 1.200,00
    expect($esito['type'] ?? null)->toBe('success')
        ->and(b52tQuoteDelMotore($s['piano']->fresh()))->toBe(120000)
        ->and(app(AvvisoSpeseNegative::class)->perLaPagina($s['piano']->fresh()))->toBeNull();
});

it('una voce di spesa con la copia a −€ 400,00 trasformata in capitolo con un sottoconto positivo nato dopo il piano non è più una spesa negativa: nessun fermo', function () {
    $s = be52CapScenario();
    $s['spesa'] = ($s['mk'])('Antenna', null, 40000, 'spesa');
    $s['piano'] = b52tPiano($this, $s);
    DB::table('conti')->where('id', $s['spesa']->id)->update(['importo' => -40000]);
    DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $s['spesa']->id)->update(['importo' => -40000]);
    sleep(1);
    // l'amministratore la trasforma in capitolo: perde la tabella e riceve un sottoconto positivo
    DB::table('conto_tabella_millesimale')->where('conto_id', $s['spesa']->id)->delete();
    DB::table('conti')->where('id', $s['spesa']->id)->update(['importo' => 0]);
    ($s['mk'])('Manutenzione antenna', $s['spesa']->id, 10000, 'spesa');

    expect(app(SpeseNegativeNelPiano::class)->trova($s['piano']->fresh()))->toBe([]);
});

it('un capitolo con i figli nati prima del piano e la copia negativa si addebita in positivo: il fermo nomina il capitolo e dice che è già corretto', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Ascensore', 20000, 'spesa'], ['Caldaia', 10000, 'spesa']]);
    be52CapCopia($s, -30000);

    $esito = be52CapRicalcola($this, $s);

    expect($esito['type'] ?? null)->toBe('error')
        ->and($esito['message'])->toStartWith('Il piano non si ricalcola: la voce di spesa «Antenna» è già corretta, ma il piano ne tiene la copia negativa (meno € 300,00) e la addebiterebbe in positivo, per € 300,00.')
        ->and($esito['message'])->not->toContain('sotto il capitolo');
});

it('controprova — una copia positiva sul capitolo con un sottoconto di entrata negativo non ferma il piano: la somma dei pesi resta maggiore di zero', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 50000, 'spesa'], ['Affitto antenna', -10000, 'entrata']]);
    be52CapCopia($s, 40000);

    $esito = be52CapRicalcola($this, $s);

    // € 1.200,00 + (spesa € 500,00 − entrata € 100,00): il capitolo chiede i suoi € 400,00
    expect($esito['type'] ?? null)->toBe('success')
        ->and(b52tQuoteDelMotore($s['piano']->fresh()))->toBe(160000);
});

it('un capitolo con la copia negativa e una foglia negativa sotto un sottocapitolo non è «già corretto»: la voce da correggere sta più in basso', function () {
    $s = be52Vuoto($this);
    be52Voce($this, $s, $s['pc'], 'Spese generali', 'spesa', '1.200,00');
    $piano = be52PianoDallaRotta($this, $s);
    be52PostVoce($this, $s, ['nome' => 'Capitolo', 'importo' => '0,00', 'tabella_millesimale_id' => null, 'isCapitolo' => true]);
    $capitolo = Conto::where('nome', 'Capitolo')->firstOrFail();
    be52PostVoce($this, $s, ['nome' => 'Sottocapitolo', 'importo' => '100,00', 'parent_id' => $capitolo->id, 'isSottoConto' => true]);
    $sotto = Conto::where('nome', 'Sottocapitolo')->firstOrFail();
    be52PostVoce($this, $s, ['nome' => 'Foglia', 'importo' => '100,00', 'parent_id' => $capitolo->id, 'isSottoConto' => true]);
    // la foglia sta sotto il sottocapitolo (il terzo livello che le versioni vecchie permettevano), che non ha tabelle proprie, ed è scritta con il meno
    DB::table('conti')->where('nome', 'Foglia')->update(['parent_id' => $sotto->id, 'importo' => -10000]);
    DB::table('conto_tabella_ripartizioni')->whereIn('conto_tabella_millesimale_id', DB::table('conto_tabella_millesimale')->where('conto_id', $sotto->id)->pluck('id'))->delete();
    DB::table('conto_tabella_millesimale')->where('conto_id', $sotto->id)->delete();
    DB::table('conti')->where('piano_conto_id', $s['pc']->id)->whereIn('nome', ['Sottocapitolo', 'Foglia'])->update(['created_at' => now()->subDay()]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $capitolo->id, 'importo' => -30000, 'created_at' => now(), 'updated_at' => now()]);

    be52Ricalcola($this, $s, $piano->fresh());
    $voci = app(SpeseNegativeNelPiano::class)->trova($piano->fresh());

    // il capitolo ha la copia negativa e non ha un importo suo negativo; il sottoconto «Foglia» sì, e si nomina lui come la voce da correggere
    expect(be52Flash()['type'] ?? null)->toBe('error')
        ->and(be52Flash()['message'])->toContain('la voce di spesa «Capitolo» ha nel piano una copia negativa (meno € 300,00) e il piano la addebiterebbe in positivo, per € 300,00')
        ->and(be52Flash()['message'])->toContain('Correggi l\'importo del sottoconto «Foglia» nel piano dei conti')
        ->and(be52Flash()['message'])->not->toContain('è già corretta')
        ->and(array_map(fn ($v) => [$v['nome'], $v['voce_negativa'], $v['discendenti_negativi']], $voci))->toBe([['Capitolo', false, true], ['Foglia', true, false]]);
});

// ─── La strada seguita a metà: «Ricalcola» passa, e il capitolo resta sbagliato ─────────────────────────────────

const BE52_CAP_AVVISO_TITOLO = 'Il piano contiene un capitolo con la copia a zero o negativa';
const BE52_CAP_AVVISO_SITUAZIONE = '«Ricalcola» non segnala questo capitolo e non ne cambia la copia: se è la cifra che volevi, non c\'è niente da correggere.';
const BE52_CAP_AVVISO_STRADA = 'Se non è la cifra che volevi (per esempio hai corretto i sottoconti dopo aver creato il piano), elimina il piano e ricrealo: la copia di un capitolo si scrive solo quando il piano nasce, '
    .'e «Ricalcola» non la cambia. '.BE52_CAP_COSTO;

/** Il modale della voce: il sottoconto corretto a un provento («Entrata», l'importo senza il segno). */
function be52CapCorreggiSottoconto($test, array $s, Conto $figlio, string $importo, string $tipo = 'entrata'): void
{
    $test->actingAs($test->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $figlio]), [
        'nome' => $figlio->nome, 'codice' => '', 'tipo' => $tipo, 'importo' => $importo, 'descrizione' => '', 'note' => '', 'parent_id' => $s['padre']->id,
        'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => true, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
}

/** L'avviso che la pagina del piano riceve (`avviso_spesa_negativa`). */
function be52CapAvvisoDellaPagina($test, array $s): ?array
{
    $risposta = $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']->fresh()]));
    $risposta->assertOk();

    return $risposta->viewData('page')['props']['avviso_spesa_negativa'];
}

/** Le due strade del fermo, alla lettera: elimina il piano e ricrealo «lascia vuoto». */
function be52CapEliminaERicrea($test, array $s): \App\Models\Gestionale\PianoRate
{
    $test->actingAs($test->user)->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $s['piano']->fresh()]))->assertSessionHasNoErrors();

    return b52tPiano($test, $s, [], 'Preventivo 2026 bis');
}

it('corretto solo il sottoconto, «Ricalcola» passa e il capitolo resta a zero: la pagina avvisa, e ricreare il piano porta il totale a € 800,00', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Affitto antenna', -50000, 'entrata']]);
    be52CapMigrazione1_9();
    $rifiuto = be52CapRicalcola($this, $s);
    be52CapCorreggiSottoconto($this, $s, $s['figli']['Affitto antenna'], '500,00');

    $dopo = be52CapRicalcola($this, $s);
    $avviso = be52CapAvvisoDellaPagina($this, $s);
    $quote = b52tQuoteDelMotore($s['piano']->fresh());
    $s['piano'] = be52CapEliminaERicrea($this, $s);

    // a mano: la copia del capitolo resta a zero e il motore non gli chiede niente (€ 1.200,00); ricreato, il piano chiede € 1.200,00 + € 100,00 − € 500,00 = € 800,00
    expect($rifiuto['type'] ?? null)->toBe('error')
        ->and($dopo['type'] ?? null)->toBe('success')
        ->and($quote)->toBe(120000)
        ->and($avviso)->toBe([
            'titolo' => BE52_CAP_AVVISO_TITOLO,
            'voci' => ['Il capitolo «Antenna» ha nel piano una copia a zero e i suoi sottoconti «Manutenzione antenna» e «Affitto antenna» hanno degli importi: il piano ripartisce la copia fra i sottoconti, non i loro importi.'],
            'situazione' => BE52_CAP_AVVISO_SITUAZIONE,
            'strade' => [BE52_CAP_AVVISO_STRADA],
        ])
        ->and(b52tQuoteDelMotore($s['piano']))->toBe(80000)
        ->and(be52CapAvvisoDellaPagina($this, $s))->toBeNull();
});

it('un capitolo «entrata» con la copia a −€ 400,00, corretto il sottoconto, passa in silenzio: l\'avviso nomina la copia negativa', function () {
    $s = be52CapPianoConCapitolo($this, 'entrata', [['Manutenzione antenna', 10000, 'spesa'], ['Affitto antenna', -50000, 'entrata']]);
    be52CapCopia($s, -40000);
    be52CapCorreggiSottoconto($this, $s, $s['figli']['Affitto antenna'], '500,00');

    $dopo = be52CapRicalcola($this, $s);
    $avviso = be52CapAvvisoDellaPagina($this, $s);

    expect($dopo['type'] ?? null)->toBe('success')
        ->and($avviso['voci'] ?? null)->toBe(['Il capitolo «Antenna» ha nel piano una copia negativa (meno € 400,00) e i suoi sottoconti «Manutenzione antenna» e «Affitto antenna» hanno degli importi: il piano ripartisce la copia fra i sottoconti, non i loro importi.'])
        ->and($avviso['strade'] ?? null)->toBe([BE52_CAP_AVVISO_STRADA]);
});

it('con più capitoli così l\'avviso li nomina tutti, e il titolo li conta', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Affitto antenna', 50000, 'entrata']]);
    $giardino = ($s['mk'])('Giardino', null, 0, 'spesa', false);
    ($s['mk'])('Potatura', $giardino->id, 30000, 'spesa');
    DB::table('conti')->where('piano_conto_id', $s['pc']->id)->update(['created_at' => now()->subDay()]);
    be52CapCopia($s, 0);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['piano']->id, 'conto_id' => $giardino->id, 'importo' => 0, 'created_at' => now(), 'updated_at' => now()]);

    $avviso = be52CapAvvisoDellaPagina($this, $s);

    expect($avviso['titolo'] ?? null)->toBe('Il piano contiene 2 capitoli con la copia a zero o negativa')
        ->and($avviso['voci'] ?? null)->toBe([
            'Il capitolo «Antenna» ha nel piano una copia a zero e i suoi sottoconti «Manutenzione antenna» e «Affitto antenna» hanno degli importi: il piano ripartisce la copia fra i sottoconti, non i loro importi.',
            'Il capitolo «Giardino» ha nel piano una copia a zero e il suo sottoconto «Potatura» ha un importo: il piano ripartisce la copia fra i sottoconti, non i loro importi.',
        ]);
});

it('con un piano approvato l\'avviso dice che per ricrearlo va prima riportato in bozza', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Ispezione', 5000, 'spesa']]);
    be52CapCopia($s, 0);
    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['stato' => StatoPianoRate::APPROVATO->value]);

    $avviso = be52CapAvvisoDellaPagina($this, $s);

    expect($avviso['situazione'] ?? null)->toBe('Il piano è approvato: «Ricalcola» non cambia la copia del capitolo, e per ricrearlo va prima riportato in bozza.')
        ->and($avviso['strade'] ?? null)->toBe(['Riporta il piano in bozza (si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima).', BE52_CAP_AVVISO_STRADA]);
});

it('con un incasso sul piano l\'avviso dice di annullarlo prima, e che le tre strade lo rifiutano', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Ispezione', 5000, 'spesa']]);
    be52CapCopia($s, 0);
    $quota = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->orderBy('rate_quote.id')->value('rate_quote.id');
    DB::table('rate_quote')->where('id', $quota)->update(['importo_pagato' => 5000]);

    $avviso = be52CapAvvisoDellaPagina($this, $s);

    expect($avviso['situazione'] ?? null)->toBe('Il piano ha un incasso su una sua quota: «Ricalcola», «Rimuovi voce e ricalcola» ed «Elimina» lo rifiutano, quindi nessuno dei tre arriva a cambiare la copia del capitolo.')
        ->and($avviso['strade'][0] ?? null)->toBe('Per riscrivere il piano annulla gli incassi registrati, poi decidi come segue.')
        ->and($avviso['strade'][1] ?? null)->toBe(BE52_CAP_AVVISO_STRADA)
        ->and($avviso['strade'] ?? [])->toHaveCount(3);
});

it('controprova — la copia a zero voluta con uno spostamento di budget dal capitolo non ha avviso: i € 150,00 sono partiti da lui', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Ispezione', 5000, 'spesa']]);
    be52CapCopia($s, 0);
    $prima = be52CapAvvisoDellaPagina($this, $s);
    DB::table('budget_movements')->insert(['piano_rate_id' => $s['piano']->id, 'source_conto_id' => $s['padre']->id, 'destination_conto_id' => $s['pulizia']->id, 'amount' => 15000,
        'source_old_amount' => 15000, 'destination_old_amount' => 120000, 'type' => 'reallocation', 'created_at' => now(), 'updated_at' => now()]);

    $dopo = be52CapAvvisoDellaPagina($this, $s);

    expect($prima['titolo'] ?? null)->toBe(BE52_CAP_AVVISO_TITOLO)
        ->and($dopo)->toBeNull();
});

it('controprova — niente avviso con la copia positiva, con i sottoconti tutti a zero, con i sottoconti nati dopo il piano o con una voce che ha le sue tabelle', function () {
    $positiva = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Ispezione', 5000, 'spesa']]);
    be52CapCopia($positiva, 15000);
    $avvisoPositiva = be52CapAvvisoDellaPagina($this, $positiva);

    $azzerata = be52CapPianoConCapitolo($this, 'spesa', [['Imprevisti', 0, 'spesa'], ['Piccole riparazioni', 0, 'spesa']]);
    be52CapCopia($azzerata, 0);
    $avvisoAZero = be52CapAvvisoDellaPagina($this, $azzerata);

    $dopo = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Ispezione', 5000, 'spesa']], figliDopoIlPiano: true);
    be52CapCopia($dopo, 0);
    $avvisoDopo = be52CapAvvisoDellaPagina($this, $dopo);

    // il capitolo ha le sue tabelle: il motore ripartisce la copia su quelle e i sottoconti non contano
    $conTabelle = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa']]);
    $legame = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conTabelle['padre']->id, 'tabella_id' => $conTabelle['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $legame, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    be52CapCopia($conTabelle, 0);
    $avvisoConTabelle = be52CapAvvisoDellaPagina($this, $conTabelle);

    expect($avvisoPositiva)->toBeNull()
        ->and($avvisoAZero)->toBeNull()
        ->and($avvisoDopo)->toBeNull()
        ->and($avvisoConTabelle)->toBeNull();
});

it('controprova — un capitolo sotto un capitolo del piano riceve la copia da lui: la sua riga a zero non è un caso', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa']]);
    $impianti = ($s['mk'])('Impianti', $s['padre']->id, 0, 'spesa', false);
    ($s['mk'])('Ascensore', $impianti->id, 20000, 'spesa');
    DB::table('conti')->where('piano_conto_id', $s['pc']->id)->update(['created_at' => now()->subDay()]);
    be52CapCopia($s, 40000);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['piano']->id, 'conto_id' => $impianti->id, 'importo' => 0, 'created_at' => now(), 'updated_at' => now()]);

    expect(be52CapAvvisoDellaPagina($this, $s))->toBeNull();
});

it('controprova — dove il fermo nomina già il sottoconto negativo l\'avviso è uno solo, quello del fermo', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Affitto antenna', -50000, 'entrata']]);
    be52CapMigrazione1_9();

    $avviso = be52CapAvvisoDellaPagina($this, $s);

    expect($avviso['titolo'])->toBe('Il piano contiene una voce con un importo negativo')
        ->and($avviso['voci'])->toHaveCount(1)
        ->and($avviso['voci'][0])->toStartWith('Il sottoconto «Affitto antenna», sotto il capitolo «Antenna»');
});

it('con un capitolo e una voce negativa con la copia il rifiuto dice di ricreare il piano, e che per il capitolo «Ricalcola» passerebbe in silenzio', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Affitto antenna', -50000, 'entrata']]);
    be52CapCopia($s, 0);
    $rimborso = ($s['mk'])('Rimborso B', null, -10000, 'spesa');
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $s['piano']->id, 'conto_id' => $rimborso->id, 'importo' => -10000, 'created_at' => now(), 'updated_at' => now()]);

    $esito = be52CapRicalcola($this, $s);

    expect($esito['message'])->toContain('Il piano tiene una copia dell\'importo delle voci e correggere le voci non la cambia, e con più di una voce così il piano si ferma a ogni rimozione: elimina il piano e ricrealo dopo aver corretto le voci. '
        .'Per il capitolo «Antenna», corretti i sottoconti, «Ricalcola» non lo segnala più ma il capitolo resta sbagliato. '.BE52_CAP_COSTO);
});

// ─── La copia negativa di un capitolo con sottoconti: la strada e la cifra ──────────────────────────────────────

it('la copia negativa di un capitolo con sottoconti non si toglie con «Rimuovi voce» e «Sincronizza»: la strada è ricreare il piano', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Ascensore', 20000, 'spesa'], ['Caldaia', 10000, 'spesa']]);
    be52CapCopia($s, -30000);

    $esito = be52CapRicalcola($this, $s);
    $avviso = app(AvvisoSpeseNegative::class)->perLaPagina($s['piano']->fresh());
    // la strada «togli e riaggiungi»: dopo «Rimuovi voce» la pagina offre le foglie, e «Sincronizza» le rifiuta
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $s['piano'], $s['padre']->id]), ['accetta_destinatari' => true, 'nota_destinatari' => 'Piano di prova']);
    $offerte = collect(app(BudgetCoverageService::class)->getCapitoliFinanziabili(app(BudgetCoverageService::class)->analyze($s['g']->fresh(), [], [])))->pluck('id')->all();
    $sincronizza = be52CapRicalcola($this, $s, $offerte);

    expect($esito)->toBe(['type' => 'error', 'message' => 'Il piano non si ricalcola: la voce di spesa «Antenna» è già corretta, ma il piano ne tiene la copia negativa (meno € 300,00) e la addebiterebbe in positivo, per € 300,00.'
        .BE52_CAP_RICREA_LA_COPIA])
        ->and($avviso['strade'])->toBe([trim(BE52_CAP_RICREA_LA_COPIA)])
        ->and($offerte)->toBe([$s['figli']['Ascensore']->id, $s['figli']['Caldaia']->id])
        ->and($sincronizza['type'] ?? null)->toBe('error')
        ->and($sincronizza['message'])->toContain('Per includere un sotto-conto, elimina il piano e ricrealo selezionando la voce.');
});

it('un capitolo diventato misto con la copia negativa non è addebitato per € 400,00: il rifiuto dice che il piano la divide fra spese ed entrate, e la strada è ricreare il piano', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Rimborso assicurazione', -50000, 'spesa']]);
    be52CapCopia($s, -40000);
    $primo = be52CapRicalcola($this, $s);
    be52CapCorreggiSottoconto($this, $s, $s['figli']['Rimborso assicurazione'], '500,00');

    $secondo = be52CapRicalcola($this, $s);
    $avviso = app(AvvisoSpeseNegative::class)->perLaPagina($s['piano']->fresh());
    $s['piano'] = be52CapEliminaERicrea($this, $s);

    // a mano: il piano ricreato chiede € 1.200,00 + € 100,00 − € 500,00 = € 800,00
    expect($primo['type'] ?? null)->toBe('error')
        ->and($secondo)->toBe(['type' => 'error', 'message' => 'Il piano non si ricalcola: la voce di spesa «Antenna» è già corretta, ma il piano ne tiene la copia negativa (meno € 400,00) e la dividerebbe fra i suoi sottoconti di spesa e di entrata: il totale del piano non sarebbe quello del capitolo.'
            .BE52_CAP_RICREA_LA_COPIA])
        ->and($secondo['message'])->not->toContain('per € 400,00')
        ->and($secondo['message'])->not->toContain('«Sincronizza»')
        ->and($avviso['voci'] ?? null)->toBe(['La voce «Antenna» è già corretta, ma il piano ne tiene ancora la copia negativa (meno € 400,00) e la divide fra i suoi sottoconti di spesa e di entrata: il totale del piano non è quello del capitolo.'])
        ->and(b52tQuoteDelMotore($s['piano']))->toBe(80000);
});

it('con la copia negativa su un capitolo misto e una foglia negativa più in basso, la frase non dice a quanto il piano la addebita', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Impianti', 0, 'spesa'], ['Affitto antenna', 10000, 'entrata']]);
    // «Impianti» è un sottocapitolo senza tabelle, con una foglia scritta con il meno
    $impianti = $s['figli']['Impianti'];
    DB::table('conto_tabella_ripartizioni')->whereIn('conto_tabella_millesimale_id', DB::table('conto_tabella_millesimale')->where('conto_id', $impianti->id)->pluck('id'))->delete();
    DB::table('conto_tabella_millesimale')->where('conto_id', $impianti->id)->delete();
    ($s['mk'])('Rimborso assicurazione', $impianti->id, -10000, 'spesa');
    DB::table('conti')->where('piano_conto_id', $s['pc']->id)->update(['created_at' => now()->subDay()]);
    be52CapCopia($s, -30000);

    $esito = be52CapRicalcola($this, $s);
    $avviso = app(AvvisoSpeseNegative::class)->perLaPagina($s['piano']->fresh());

    expect($esito['message'] ?? '')->toContain('Il piano non si ricalcola: la voce di spesa «Antenna» ha nel piano una copia negativa (meno € 300,00) e il piano la dividerebbe fra i suoi sottoconti di spesa e di entrata: il totale del piano non sarebbe quello del capitolo.')
        ->and($esito['message'])->not->toContain('addebiterebbe in positivo, per € 300,00')
        ->and($avviso['voci'][0] ?? null)->toBe('La voce «Antenna» ha nel piano una copia negativa (meno € 300,00) e il piano la divide fra i suoi sottoconti di spesa e di entrata: il totale del piano non è quello del capitolo.');
});

it('l\'avviso di un sottoconto nato dopo il piano non dice che il piano lo ripartisce: il capitolo con la copia non positiva resta fuori', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Rimborso assicurazione', -50000, 'spesa']], figliDopoIlPiano: true);
    be52CapCopia($s, -40000);

    $avviso = app(AvvisoSpeseNegative::class)->perLaPagina($s['piano']->fresh());

    expect($avviso['voci'] ?? null)->toBe(['Il sottoconto «Rimborso assicurazione», sotto il capitolo «Antenna», ha un importo negativo (meno € 500,00) ed è nato dopo il piano: il piano non lo ripartisce, e il capitolo, che ha nel piano una copia negativa (meno € 400,00), non chiede niente.'])
        ->and(implode(' ', $avviso['voci']))->not->toContain('il piano ripartisce la copia');
});

it('controprova — un sottoconto negativo nato prima del piano è ripartito: l\'avviso lo dice come prima', function () {
    $s = be52CapPianoConCapitolo($this, 'spesa', [['Manutenzione antenna', 10000, 'spesa'], ['Rimborso assicurazione', -50000, 'spesa']]);
    be52CapCopia($s, -40000);

    $avviso = app(AvvisoSpeseNegative::class)->perLaPagina($s['piano']->fresh());

    expect($avviso['voci'] ?? null)->toBe(['Il sottoconto «Rimborso assicurazione», sotto il capitolo «Antenna», ha un importo negativo (meno € 500,00) e il capitolo ha nel piano una copia negativa (meno € 400,00): il piano ripartisce la copia anche su di lui, e il totale non è quello del capitolo.']);
});

