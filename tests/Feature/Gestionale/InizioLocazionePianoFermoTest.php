<?php

/**
 * 1.11.0-beta.47 — l'inizio di una locazione su un piano che non si ricalcola più.
 *
 * Nasce da DL3 del laboratorio (rapporto della sessione 3-bis, §5.3; era già nel rapporto 3 sulla .41) e dalla decisione 45
 * («l'inizio di una locazione su un piano fermo va con la beta della locazione»). Un piano con sei rate a giornale non si
 * ricalcola più (decisione 34.1): le sue quote, emesse e ancora da emettere, restano a chi le ha, e la parte a carico
 * dell'inquilino dal giorno dell'inizio la regolano proprietario e inquilino con il prospetto degli oneri accessori (D11,
 * decisione 25). Il pannello diceva il contrario: il blocco «Rate» prometteva che «dal 1 luglio le voci a carico dell'inquilino
 * verranno intestate a Luca», e il cancello chiedeva la spunta perché «il destinatario cambierebbe».
 *
 * Presidia: sul piano fermo il cancello non ha quel motivo e ha un'informazione che dice che il piano non si ricalcola più, e la
 * frase del blocco «Rate» rimanda al prospetto invece di promettere quote; sul piano che si ricalcola ancora il motivo resta
 * (controllo); con due piani sull'unità, uno fermo e uno no, motivo e informazione si dicono piano per piano. I controlli dicono
 * che il denaro è già giusto: Luca non riceve quote, il ricalcolo è rifiutato, e il prospetto gli dà da rimborsare a Ugo i suoi
 * 184 giorni.
 *
 * Dalla Fase 1-bis della .47, in fondo al file: i piani senza voci dell'inquilino e quelli degli anni passati non contano, nei due
 * versi; il cambio d'inquilino in due passi, dove i giorni stanno nelle rate dell'inquilino di prima (decisione 32); il perimetro
 * del condominio.
 *
 * Dal secondo giro sulle correzioni, in fondo al file: le pertinenze con storie diverse (una frase per unità, con l'etichetta e il
 * prospetto di quell'unità); il piano della 1.10 senza riparto registrato, fermo, che dopo il passaggio il prospetto lascia fuori; la
 * sua data sulla gestione riusata; il rinnovo con il ripiego dopo la fine delle righe; i tratti per capitolo; il perimetro di «c'è
 * ancora».
 *
 * Dal terzo giro sulle correzioni, in fondo al file: il piano della 1.10 generato con l'inquilino (rinnovo, due passi, coinquilino),
 * dove la frase segue chi ha le quote; il piano con le righe solo sulla pertinenza, che nomina l'unità; i tratti per capitolo, dove
 * l'intervallo comincia dal primo giorno vero.
 *
 * Cosa NON copre: il piano fermo per un incasso su una bozza senza niente a giornale; l'unità mista; più di una pertinenza; il cambio
 * in due passi con la fine e l'inizio in giorni diversi; più piani fermi di inquilini di prima diversi; la pagina del modulo nel
 * browser.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Una gestione ordinaria 2026 con una voce sulla tabella data, il suo piano rate approvato e generato. */
function ilpfGestioneConPiano(Condominio $c, Esercizio $e, Tabella $tabella, string $gestione, string $voce, int $importo, array $ripartizione, string $piano, int $numeroRate, string $primaScadenza): PianoRate
{
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => $gestione, 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC ' . $gestione]);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => $voce, 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => $importo]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($ripartizione as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $p = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => $piano, 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => $numeroRate, 'giorno_scadenza' => 5, 'data_prima_scadenza' => $primaScadenza, 'metodo_distribuzione' => 'prima_rata',
    ]);
    app(GeneratePianoRateAction::class)->execute($p, esercizio: $e);

    return $p;
}

/**
 * L'unità di Ugo (1000 millesimi su 1000), nessun inquilino alla generazione. «Preventivo 2026»: «Spese generali» € 1.200,00,
 * 30 % all'inquilino e 70 % al proprietario, dodici rate mensili dal 5 gennaio. Con `$riscaldamento` anche la gestione
 * «Riscaldamento 2026» con «Combustibile» € 730,00 tutto all'inquilino e il suo piano «Rate del riscaldamento 2026», quattro
 * rate dal 5 settembre. I conti per emettere. Luca è un'anagrafica del condominio, non ancora sull'unità.
 */
function ilpfScenario(bool $riscaldamento = false): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "ILPF-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);

    $ugo = Anagrafica::forceCreate(['nome' => 'Ugo Rinaldi', 'email' => "ilpf-u{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ILPFPROPRIET' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT)]);
    $luca = Anagrafica::forceCreate(['nome' => 'Luca Ferri', 'email' => "ilpf-l{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ILPFINQUILIN' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT)]);
    $ugo->condomini()->syncWithoutDetaching([$c->id]);
    $luca->condomini()->syncWithoutDetaching([$c->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $ugo->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    $piano = ilpfGestioneConPiano($c, $e, $tabella, 'Ordinaria 2026', 'Spese generali', 120000, ['inquilino' => 30, 'proprietario' => 70], 'Preventivo 2026', 12, '2026-01-05');
    $pianoRiscaldamento = $riscaldamento
        ? ilpfGestioneConPiano($c, $e, $tabella, 'Riscaldamento 2026', 'Combustibile', 73000, ['inquilino' => 100], 'Rate del riscaldamento 2026', 4, '2026-09-05')
        : null;

    return compact('c', 'e', 'unita', 'ugo', 'luca', 'piano', 'pianoRiscaldamento');
}

/** L'emissione vera, dalla rotta della pagina del piano: le rate in scadenza fino al giorno dato, emesse quel giorno. */
function ilpfEmettiFino($test, array $s, PianoRate $piano, string $fino): void
{
    $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), [
        'rate_ids' => $rate, 'data_emissione' => $fino, 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors();
}

/** Il modulo dell'inizio locazione di Luca il 1/7/2026, con la presa d'atto del cancello (oggi la chiede, dopo la correzione no). */
function ilpfInizioLocazione(array $s): array
{
    return [
        'tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $s['luca']->id, 'decorrenza' => '2026-07-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Contratto di locazione registrato, pannello letto',
    ];
}

function ilpfAnteprima($test, array $s): array
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), ilpfInizioLocazione($s))->assertOk()->json();
}

function ilpfRegistra($test, array $s): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), ilpfInizioLocazione($s))
        ->assertSessionHasNoErrors()->assertRedirect();
}

/** Il ricalcolo dalla rotta vera; restituisce il messaggio flash ['type' => …, 'message' => …]. */
function ilpfRicalcola($test, array $s, PianoRate $piano): array
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Inizio locazione registrato, quote da rifare per giorni',
    ])->getSession()->get('message');
}

/** Le quote a giornale del piano, e quelle di una persona sul piano: [numero, somma degli importi]. */
function ilpfQuote(PianoRate $piano, ?Anagrafica $di = null, bool $soloAGiornale = false): array
{
    $q = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->when($di !== null, fn ($w) => $w->where('rate_quote.anagrafica_id', $di->id))
        ->when($soloAGiornale, fn ($w) => $w->whereNotNull('rate_quote.scrittura_contabile_id'));

    return [(clone $q)->count(), (int) (clone $q)->sum('rate_quote.importo')];
}

/*
|--------------------------------------------------------------------------
| Il piano fermo: sei rate a giornale, poi Luca entra il 1/7
|--------------------------------------------------------------------------
*/

it('DL3 — inizio locazione su un piano con sei rate a giornale: il cancello non dice «il destinatario cambierebbe» e non chiede la spunta, un\'informazione dice che il piano non si ricalcola più, e il blocco «Rate» rimanda al prospetto degli oneri accessori invece di promettere quote a Luca', function () {
    $s = ilpfScenario();
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');

    // Il presupposto: le rate dal 5 gennaio al 5 giugno sono a giornale, tutte di Ugo, e il piano non si ricalcola più.
    // 120000 / 12 = 10000 a rata (nessun inquilino alla generazione: la parte inquilino va al proprietario per ripiego);
    // 6 rate × 10000 = 60000.
    expect(ilpfQuote($s['piano'], $s['ugo'], soloAGiornale: true))->toBe([6, 60000])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprima($this, $s);
    $cancello = $anteprima['cancello'];
    $frase = implode(' ', $anteprima['rate']['frasi']);

    // Il destinatario non cambia: il ricalcolo è rifiutato (secondo test). Il passaggio non tocca nessuna quota, e il cancello non
    // ha niente da far firmare (rapporto 3-bis, §5.3: «un'informazione, non un motivo: è voluto, non cambia nessun importo»).
    expect(implode(' | ', $cancello['motivi']))->not->toContain('il destinatario cambierebbe')
        ->and($cancello['motivi'])->toBe([])
        ->and($cancello['richiesto'])->toBeFalse()
        ->and(implode(' | ', $cancello['informazioni']))->toContain('non si ricalcola più')
        // La parte dell'inquilino dal 1/7 non va nelle rate di Luca: la rimborsa a Ugo con il prospetto.
        ->and($frase)->not->toContain('verranno intestate a Luca')
        ->and($frase)->toContain('prospetto degli oneri accessori');
});

it('DL3, controllo — sullo stesso piano fermo, registrato l\'inizio, Luca non riceve quote, il ricalcolo è rifiutato e il prospetto gli dà da rimborsare a Ugo € 181,48 per 184 giorni: è ciò che la frase nuova deve dire', function () {
    $s = ilpfScenario();
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    ilpfRegistra($this, $s);

    $ricalcolo = ilpfRicalcola($this, $s, $s['piano']);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    $luca = collect($p['conduttori'])->firstWhere('nome', 'Luca Ferri');

    expect($ricalcolo['type'])->toBe('error')
        ->and($ricalcolo['message'])->toContain('ci sono rate già emesse in contabilità')
        // Le quote restano com'erano: nessuna a Luca, dodici a Ugo per 120000 (12 × 10000).
        ->and(ilpfQuote($s['piano'], $s['luca']))->toBe([0, 0])
        ->and(ilpfQuote($s['piano'], $s['ugo']))->toBe([12, 120000])
        // Parte inquilino dell'anno: 120000 × 30 % = 36000. Luca dal 1/7 al 31/12: 31 + 31 + 30 + 31 + 30 + 31 = 184 giorni;
        // 36000 × 184/365 = 18147,95 → 18148, pagati da Ugo nelle sue rate e da rimborsargli.
        ->and($luca['totale'])->toBe(18148)
        ->and($luca['nelle_sue_rate'])->toBe(0)
        ->and($luca['da_rimborsare'])->toBe(18148)
        ->and(collect($luca['voci'])->pluck('pagato_da')->unique()->values()->all())->toBe(['Ugo Rinaldi'])
        // Dal 1/1 al 30/6: 31 + 28 + 31 + 30 + 31 + 30 = 181 giorni; 36000 × 181/365 = 17852,05 → 17852, restano a Ugo.
        // 17852 + 18148 = 36000.
        ->and($p['senza_conduttore']['totale'])->toBe(17852);
});

/*
|--------------------------------------------------------------------------
| Il piano che si ricalcola ancora: il motivo resta
|--------------------------------------------------------------------------
*/

it('DL3, controllo — inizio locazione su un piano generato e non emesso: il cancello dice ancora «il destinatario cambierebbe», ed è vero: ricalcolato, il piano intesta a Luca i suoi 184 giorni della parte inquilino', function () {
    $s = ilpfScenario();
    expect(ilpfQuote($s['piano'], soloAGiornale: true))->toBe([0, 0])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([]);

    $cancello = ilpfAnteprima($this, $s)['cancello'];
    expect($cancello['richiesto'])->toBeTrue()
        ->and(implode(' | ', $cancello['motivi']))->toContain('il destinatario cambierebbe')
        ->and(implode(' | ', $cancello['informazioni']))->not->toContain('non si ricalcola più');

    ilpfRegistra($this, $s);
    $ricalcolo = ilpfRicalcola($this, $s, $s['piano']);

    // 36000 × 184/365 = 18147,95 → 18148 a Luca; a Ugo 120000 − 18148 = 101852 (la parte proprietario 84000 e i 181 giorni
    // della parte inquilino senza inquilino, 17852: 84000 + 17852 = 101852).
    expect($ricalcolo['type'])->toBe('success')
        ->and(ilpfQuote($s['piano'], $s['luca'])[1])->toBe(18148)
        ->and(ilpfQuote($s['piano'], $s['ugo'])[1])->toBe(101852);
});

/*
|--------------------------------------------------------------------------
| Due piani sull'unità: uno fermo, uno che si ricalcola
|--------------------------------------------------------------------------
*/

it('DL3, piano per piano — con il «Preventivo 2026» fermo e le «Rate del riscaldamento 2026» ancora da emettere, il motivo nomina il piano che si ricalcola e l\'informazione quello fermo, non un motivo generico per tutti e due', function () {
    $s = ilpfScenario(riscaldamento: true);
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');

    // Il presupposto: il preventivo ha sei rate a giornale ed è fermo; il riscaldamento ha le sue quattro rate a Ugo, nessuna
    // a giornale, e si ricalcola ancora (73000, tutto all'inquilino, va al proprietario per ripiego).
    expect(PianoRate::immutabiliFra([$s['piano']->id, $s['pianoRiscaldamento']->id]))->toBe([$s['piano']->id])
        ->and(ilpfQuote($s['pianoRiscaldamento'], $s['ugo']))->toBe([4, 73000])
        ->and(ilpfQuote($s['pianoRiscaldamento'], soloAGiornale: true))->toBe([0, 0]);

    $cancello = ilpfAnteprima($this, $s)['cancello'];
    $motivo = collect($cancello['motivi'])->first(fn ($m) => str_contains($m, 'il destinatario cambierebbe'));
    $informazione = collect($cancello['informazioni'])->first(fn ($i) => str_contains($i, 'non si ricalcola più'));

    // Il riscaldamento ricalcolato darà a Luca le sue voci dal 1/7: la spunta resta, e dice di quale piano parla. Il preventivo
    // non si ricalcola più: un'informazione, con il suo nome.
    expect($cancello['richiesto'])->toBeTrue()
        ->and($motivo)->toContain('Rate del riscaldamento 2026')
        ->and($motivo)->not->toContain('Preventivo 2026')
        ->and($informazione)->toContain('Preventivo 2026')
        ->and($informazione)->not->toContain('Rate del riscaldamento 2026');
});

/*
|--------------------------------------------------------------------------
| Fase 1-bis della .47: i piani che il cancello e la frase contano
|--------------------------------------------------------------------------
*/

/**
 * «Lavori tetto 2026»: «Rifacimento tetto» € 4.000,00 tutto al «Proprietario», il piano «Rate lavori tetto 2026» in quattro rate
 * mensili dalla scadenza data, generato. Nessuna parte a carico dell'inquilino: è il piano che il cancello non deve contare.
 */
function ilpfPianoLavori(array $s, string $primaScadenza): PianoRate
{
    $tabella = Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();

    return ilpfGestioneConPiano($s['c'], $s['e'], $tabella, 'Lavori tetto 2026', 'Rifacimento tetto', 400000, ['proprietario' => 100], 'Rate lavori tetto 2026', 4, $primaScadenza);
}

/**
 * L'anno prima: «Esercizio 2025» con la gestione «Ordinaria 2025», «Spese generali» € 1.200,00 30/70, il piano «Preventivo 2025»
 * in dodici rate dal 5/1/2025, generato (nessun inquilino: tutto a Ugo), emesso per intero dalla rotta il 31/12/2025, e
 * l'esercizio chiuso.
 */
function ilpfAnnoPrima($test, array $s): PianoRate
{
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'aperto']);
    $tabella = Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $g = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Ordinaria 2025', 'tipo' => 'ordinaria', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'PC Ordinaria 2025']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $p = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2025', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2025-01-05', 'metodo_distribuzione' => 'prima_rata',
    ]);
    app(GeneratePianoRateAction::class)->execute($p, esercizio: $e);
    ilpfEmettiFino($test, $s, $p, '2025-12-31');
    $e->update(['stato' => 'chiuso']);

    return $p;
}

/** Il piano approvato ma non ancora generato: senza rate, quote e righe di riparto (le cancellazioni scendono in quest'ordine). */
function ilpfSenzaRate(PianoRate $piano): void
{
    $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->pluck('id')->all();
    DB::table('rate_quote')->whereIn('rata_id', $rate)->delete();
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    DB::table('rate')->where('piano_rate_id', $piano->id)->delete();
}

/** Ines Galli inquilina dell'unità dal 2019, e il preventivo ricalcolato dalla rotta vera: la parte inquilino passa a lei. */
function ilpfConInes($test, array $s): Anagrafica
{
    $ines = Anagrafica::forceCreate(['nome' => 'Ines Galli', 'email' => "ilpf-i{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'ILPFINESGALL' . str_pad((string) $s['unita']->id, 4, '0', STR_PAD_LEFT)]);
    $ines->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $ines->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    expect(ilpfRicalcola($test, $s, $s['piano'])['type'])->toBe('success');

    return $ines;
}

/** La fine locazione di Ines il 1/7/2026 senza nuovo inquilino, dalla rotta vera; la presa d'atto solo se il pannello la chiede. */
function ilpfFineDiInes($test, array $s, Anagrafica $ines): void
{
    $riga = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $ines->id)->where('tipologia', 'inquilino')->value('id');
    $dati = ['tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => null,
        'decorrenza' => '2026-07-01', 'quota' => 100, 'copia_autentica' => false, 'pertinenze' => []];
    $anteprima = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati)->assertOk()->json();
    if ($anteprima['cancello']['richiesto'] ?? false) {
        $dati += ['ho_letto' => true, 'nota_cancello' => 'Disdetta letta, rate emesse controllate'];
    }
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati)->assertSessionHasNoErrors()->assertRedirect();
}

// Rilievo «la divisione piano per piano conta anche i piani senza voci dell'inquilino» (DL3, medio), primo verso. Non copre: un
// piano con voci dell'inquilino a zero euro, le righe di ripiego scritte con ruolo inquilino, le pertinenze.
it('DL3, piani senza voci dell\'inquilino — preventivo 30/70 fermo e «Rate lavori tetto 2026» tutto al proprietario, generato e non emesso: il cancello non chiede la spunta per i lavori, l\'informazione nomina solo il preventivo, la frase non nomina i lavori', function () {
    $s = ilpfScenario();
    $lavori = ilpfPianoLavori($s, '2026-09-05');
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');

    // Il presupposto. Preventivo: 120000 / 12 = 10000 a rata, sei a giornale: 6 × 10000 = 60000, tutte di Ugo. Lavori: 400000 / 4
    // = 100000 a rata, quattro a Ugo: 4 × 100000 = 400000, nessuna a giornale. Il preventivo è fermo, i lavori no.
    expect(ilpfQuote($s['piano'], $s['ugo'], soloAGiornale: true))->toBe([6, 60000])
        ->and(ilpfQuote($lavori, $s['ugo']))->toBe([4, 400000])
        ->and(ilpfQuote($lavori, soloAGiornale: true))->toBe([0, 0])
        ->and(PianoRate::immutabiliFra([$s['piano']->id, $lavori->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprima($this, $s);
    $cancello = $anteprima['cancello'];
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = collect($cancello['informazioni'])->first(fn ($i) => str_contains($i, 'non si ricalcola più'));

    // Ricalcolati, i lavori danno a Luca 0 (non hanno parte inquilino): il destinatario non cambia, non c'è niente da firmare. Il solo
    // piano con la parte dell'inquilino è il preventivo, ed è fermo.
    expect($cancello['motivi'])->toBe([])
        ->and($cancello['richiesto'])->toBeFalse()
        ->and($informazione)->toContain('Preventivo 2026')
        ->and(implode(' | ', $cancello['informazioni']))->not->toContain('Rate lavori tetto 2026')
        ->and($frase)->not->toContain('Rate lavori tetto 2026')
        ->and($frase)->not->toContain('verranno intestate a Luca')
        ->and($frase)->toContain('Il piano «Preventivo 2026» non si ricalcola più');
});

// Rilievo «la divisione piano per piano conta anche i piani senza voci dell'inquilino» (DL3, medio), verso contrario. Non copre:
// lavori fermi per un incasso su una bozza, senza niente a giornale; la registrazione e il ricalcolo dopo.
it('DL3, piani senza voci dell\'inquilino, al contrario — preventivo 30/70 che si ricalcola e «Rate lavori tetto 2026» fermo: il motivo nomina il preventivo, nessuna informazione e nessuna frase sui lavori', function () {
    $s = ilpfScenario();
    $lavori = ilpfPianoLavori($s, '2026-03-05');
    ilpfEmettiFino($this, $s, $lavori, '2026-06-30');

    // Il presupposto. Lavori: quattro rate dal 5/3 al 5/6, tutte a giornale: 4 × 100000 = 400000, di Ugo. Preventivo: 12 × 10000 =
    // 120000 a Ugo, nessuna a giornale. Fermi i lavori, il preventivo si ricalcola.
    expect(ilpfQuote($lavori, $s['ugo'], soloAGiornale: true))->toBe([4, 400000])
        ->and(ilpfQuote($s['piano'], $s['ugo']))->toBe([12, 120000])
        ->and(ilpfQuote($s['piano'], soloAGiornale: true))->toBe([0, 0])
        ->and(PianoRate::immutabiliFra([$s['piano']->id, $lavori->id]))->toBe([$lavori->id]);

    $anteprima = ilpfAnteprima($this, $s);
    $cancello = $anteprima['cancello'];
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $motivo = collect($cancello['motivi'])->first(fn ($m) => str_contains($m, 'il destinatario cambierebbe'));

    // Il preventivo ricalcolato darà a Luca 36000 × 184/365 = 18148 (terzo test): la spunta resta, con il suo nome. I lavori non
    // hanno parte dell'inquilino: non c'è niente da regolare con il prospetto.
    expect($cancello['richiesto'])->toBeTrue()
        ->and($motivo)->toContain('Preventivo 2026')
        ->and($motivo)->not->toContain('Rate lavori tetto 2026')
        ->and(implode(' | ', $cancello['informazioni']))->not->toContain('Rate lavori tetto 2026')
        ->and(implode(' | ', $cancello['informazioni']))->not->toContain('non si ricalcola più')
        ->and($frase)->not->toContain('Rate lavori tetto 2026');
});

// Rilievo «i piani degli anni passati finiscono nella frase e nell'informazione dell'inizio locazione» (DL3, medio), con il
// preventivo nuovo generato. Non copre: un piano dell'anno prima con l'esercizio ancora aperto, un piano a cavallo dei due anni.
it('DL3, piani degli anni passati — «Preventivo 2025» emesso per intero (esercizio chiuso) e «Preventivo 2026» generato e non emesso: la frase e le informazioni non nominano il 2025', function () {
    $s = ilpfScenario();
    $p25 = ilpfAnnoPrima($this, $s);

    // Il presupposto. 2025: 12 × 10000 = 120000 a giornale, tutte di Ugo; 2026: 12 × 10000 = 120000 a Ugo, nessuna a giornale.
    // Fermo il 2025, che non ha nessun giorno dopo il 1/7/2026.
    expect(ilpfQuote($p25, $s['ugo'], soloAGiornale: true))->toBe([12, 120000])
        ->and(ilpfQuote($s['piano'], $s['ugo']))->toBe([12, 120000])
        ->and(ilpfQuote($s['piano'], soloAGiornale: true))->toBe([0, 0])
        ->and(PianoRate::immutabiliFra([$p25->id, $s['piano']->id]))->toBe([$p25->id]);

    $anteprima = ilpfAnteprima($this, $s);
    $cancello = $anteprima['cancello'];
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $motivo = collect($cancello['motivi'])->first(fn ($m) => str_contains($m, 'il destinatario cambierebbe'));

    // Il 2025 non ha rate da emettere né giorni dal 1/7/2026, e il prospetto del 2026 non lo legge: né la frase né le informazioni lo
    // nominano. Il 2026 si ricalcola: il motivo resta (controllo) e la frase promette le voci a Luca.
    expect($frase)->not->toContain('Preventivo 2025')
        ->and(implode(' | ', $cancello['informazioni']))->not->toContain('Preventivo 2025')
        ->and($frase)->not->toContain('prospetto degli oneri accessori')
        ->and($frase)->toContain('verranno intestate a Luca Ferri')
        ->and($cancello['richiesto'])->toBeTrue()
        ->and($motivo)->toContain('Preventivo 2026');
});

// Rilievo «i piani degli anni passati finiscono nella frase e nell'informazione dell'inizio locazione» (DL3, medio), senza il
// piano nuovo. Non copre: il piano 2026 cancellato dalla sua pagina (qui le rate si tolgono a mano, come prima della generazione).
it('DL3, piani degli anni passati, solo il 2025 — «Preventivo 2025» emesso per intero e nessun piano 2026 generato: nessun motivo, nessuna informazione e nessuna frase sul 2025, e la frase dice che le voci andranno a Luca', function () {
    $s = ilpfScenario();
    $p25 = ilpfAnnoPrima($this, $s);
    ilpfSenzaRate($s['piano']);

    // Il presupposto: sull'unità solo le 12 quote del 2025, tutte a giornale (12 × 10000 = 120000); il preventivo 2026 è approvato
    // ma senza rate.
    expect(ilpfQuote($p25, $s['ugo'], soloAGiornale: true))->toBe([12, 120000])
        ->and(ilpfQuote($s['piano']))->toBe([0, 0])
        ->and(DB::table('rate_quote')->where('immobile_id', $s['unita']->id)->count())->toBe(12);

    $anteprima = ilpfAnteprima($this, $s);
    $cancello = $anteprima['cancello'];
    $frase = implode(' ', $anteprima['rate']['frasi']);

    // Il piano 2026, quando si genererà, intesterà a Luca i suoi giorni: è la sola frase vera. Del 2025 non c'è niente da dire.
    expect($cancello['motivi'])->toBe([])
        ->and($cancello['richiesto'])->toBeFalse()
        ->and(implode(' | ', $cancello['informazioni']))->not->toContain('Preventivo 2025')
        ->and($frase)->not->toContain('Preventivo 2025')
        ->and($frase)->toContain('verranno intestate a Luca Ferri');
});

// Rilievo «nel cambio d'inquilino in due passi la frase nuova promette un prospetto che non nomina il nuovo inquilino» (DL3,
// medio). Non copre: il due passi con la fine e l'inizio in giorni diversi, la decisione 32 (chi paga i giorni), le pertinenze.
it('DL3, cambio d\'inquilino in due passi — fine di Ines il 1/7 senza nuovo inquilino, poi inizio di Luca il 1/7 sul piano fermo: la frase non dice «la rimborsa Luca», dice che i giorni restano nelle rate di Ines e che chi li paga lo decide l\'amministratore; l\'informazione non rimanda al prospetto', function () {
    $s = ilpfScenario();
    $ines = ilpfConInes($this, $s);
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    ilpfFineDiInes($this, $s, $ines);

    // Il presupposto. Ricalcolato con Ines: parte inquilino 120000 × 30 % = 36000, 36000 / 12 = 3000 a rata, 12 × 3000 = 36000 a
    // Ines; parte proprietario 120000 × 70 % = 84000, 84000 / 12 = 7000 a rata, 12 × 7000 = 84000 a Ugo (36000 + 84000 = 120000).
    // Sei rate a giornale per ciascuno (6 × 3000 = 18000, 6 × 7000 = 42000): il piano è fermo, e la fine non ha toccato quote.
    expect(ilpfQuote($s['piano'], $ines))->toBe([12, 36000])
        ->and(ilpfQuote($s['piano'], $s['ugo']))->toBe([12, 84000])
        ->and(ilpfQuote($s['piano'], $ines, soloAGiornale: true))->toBe([6, 18000])
        ->and(ilpfQuote($s['piano'], $s['ugo'], soloAGiornale: true))->toBe([6, 42000])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprima($this, $s);
    $cancello = $anteprima['cancello'];
    $frase = implode(' ', $anteprima['rate']['frasi']);

    // I giorni dal 1/7 (36000 × 184/365 = 18148) sono nelle rate di Ines, non in righe di ripiego di Ugo: il prospetto li dà a Ines
    // per tutto l'anno e Luca non c'è. Chi li paga è la decisione 32, non ancora costruita: lo decide l'amministratore.
    expect($frase)->not->toContain('la rimborsa Luca')
        ->and($frase)->toContain('nelle rate di Ines Galli')
        ->and($frase)->toContain('lo decide l\'amministratore')
        ->and(implode(' | ', $cancello['informazioni']))->not->toContain('si regola con il prospetto');
});

// Controllo del rilievo sul cambio d'inquilino in due passi (DL3, medio): nel passaggio in un passo la frase con il prospetto
// resta. Non copre: le cifre del prospetto (le dice il secondo test), il piano con un inquilino precedente ancora sull'unità.
it('DL3, controllo — inizio di Luca su un piano fermo senza inquilino precedente: la frase dice ancora che la parte dell\'inquilino dal 1/7 la rimborsa Luca con il prospetto, e l\'informazione che si regola con il prospetto', function () {
    $s = ilpfScenario();
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');

    // Il presupposto: sei rate a giornale, tutte di Ugo (6 × 10000 = 60000); i giorni dal 1/7 sono nelle righe di ripiego di Ugo.
    expect(ilpfQuote($s['piano'], $s['ugo'], soloAGiornale: true))->toBe([6, 60000]);

    $anteprima = ilpfAnteprima($this, $s);
    $frase = implode(' ', $anteprima['rate']['frasi']);

    expect($frase)->toContain('la rimborsa Luca Ferri a chi le paga, con il prospetto degli oneri accessori')
        ->and($frase)->not->toContain('lo decide l\'amministratore')
        ->and(implode(' | ', $anteprima['cancello']['informazioni']))->toContain('si regola con il prospetto degli oneri accessori');
});

// Rilievo «manca il test negativo sul perimetro dei piani dell'inizio locazione» (DL3, basso): controllo, verde oggi. Non copre:
// una pertinenza presa da un altro condominio (la rifiuta la richiesta), la frase con le pertinenze dello stesso condominio.
it('DL3, perimetro — un secondo condominio con il «Piano riservato del condominio B» generato e non emesso: l\'anteprima dell\'inizio su A non lo nomina, non ha motivi e non chiede la spunta', function () {
    $s = ilpfScenario();
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    $b = ilpfScenario();
    $b['piano']->update(['nome' => 'Piano riservato del condominio B']);

    // Il presupposto. A: sei rate a giornale (6 × 10000 = 60000), fermo. B: dodici quote a Ugo di B (12 × 10000 = 120000), nessuna a
    // giornale, si ricalcola: se entrasse nei piani di A darebbe il motivo con la spunta.
    expect(ilpfQuote($s['piano'], $s['ugo'], soloAGiornale: true))->toBe([6, 60000])
        ->and(ilpfQuote($b['piano'], $b['ugo']))->toBe([12, 120000])
        ->and(ilpfQuote($b['piano'], soloAGiornale: true))->toBe([0, 0])
        ->and(PianoRate::immutabiliFra([$b['piano']->id]))->toBe([]);

    $anteprima = ilpfAnteprima($this, $s);
    $informazione = collect($anteprima['cancello']['informazioni'])->first(fn ($i) => str_contains($i, 'non si ricalcola più'));

    expect(json_encode($anteprima, JSON_UNESCAPED_UNICODE))->not->toContain('riservato')
        ->and($anteprima['cancello']['motivi'])->toBe([])
        ->and($anteprima['cancello']['richiesto'])->toBeFalse()
        ->and($informazione)->toContain('Preventivo 2026');
});

// Fase 1-bis della .47, il ramo dei piani senza riparto registrato (generati da una versione precedente): contano se il condominio ha
// voci a carico dell'inquilino, com'era prima della correzione. Non copre: lo stesso piano fermo, un piano senza riparto in un
// condominio senza voci a carico dell'inquilino.
it('DL3, un piano generato da una versione precedente, senza riparto registrato e non emesso: conta ancora, perché il condominio ha voci a carico dell\'inquilino, e il motivo lo nomina', function () {
    $s = ilpfScenario();
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();

    // Il presupposto: le 12 quote di Ugo ci sono (12 × 10000 = 120000), nessuna a giornale, e il piano non ha righe del riparto.
    expect(ilpfQuote($s['piano'], $s['ugo']))->toBe([12, 120000])
        ->and(ilpfQuote($s['piano'], soloAGiornale: true))->toBe([0, 0])
        ->and(DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->count())->toBe(0);

    $cancello = ilpfAnteprima($this, $s)['cancello'];
    expect($cancello['richiesto'])->toBeTrue()
        ->and((string) collect($cancello['motivi'])->first(fn ($m) => str_contains($m, 'il destinatario cambierebbe')))->toContain('Preventivo 2026');
});

/*
|--------------------------------------------------------------------------
| Giro sulle correzioni della Fase 1-bis della .47: DL3, lente testi e sicurezza
|--------------------------------------------------------------------------
|
| I piani che contano si decidono con la competenza registrata sulle righe (la gestione riusata su più esercizi, la straordinaria
| alla data della delibera); il cambio d'inquilino in due passi si dice per parti (inquilino di prima uscito, coinquilino che
| resta, rinnovo con la stessa persona, righe di ripiego insieme); «ad» davanti ai nomi che cominciano per a; il perimetro
| dell'unità sullo stesso piano.
*/

require_once __DIR__.'/Support/ScenariPassaggi.php';

/**
 * Come `ilpfScenario`, ma la gestione ordinaria nasce come la fa nascere il prodotto: `CondominioService::createDefaultGestione`
 * sull'«Esercizio 2025» la crea datata 1/1–31/12/2025, e chiamata di nuovo sull'«Esercizio 2026» restituisce la stessa e le
 * aggancia soltanto il 2026 (la strada dell'importatore, `LivelloEsercizi`). Il «Preventivo 2026» sta su quella gestione con
 * l'esercizio 2026: «Spese generali» € 1.200,00, 30 % all'inquilino e 70 % al proprietario, dodici rate dal 5 gennaio, generato.
 * L'unità di Ugo senza inquilino, Luca anagrafica del condominio, i conti per emettere.
 */
function ilpfScenarioGestioneRiusata(): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e25 = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $servizio = app(\App\Services\CondominioService::class);
    $gestione = $servizio->createDefaultGestione($c, $e25);
    $riusata = $servizio->createDefaultGestione($c, $e);

    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "ILPF-GR{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);

    $ugo = Anagrafica::forceCreate(['nome' => 'Ugo Rinaldi', 'email' => "ilpf-gru{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ILPFRIUSPROP' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT)]);
    $luca = Anagrafica::forceCreate(['nome' => 'Luca Ferri', 'email' => "ilpf-grl{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ILPFRIUSINQU' . str_pad((string) $seq, 4, '0', STR_PAD_LEFT)]);
    $ugo->condomini()->syncWithoutDetaching([$c->id]);
    $luca->condomini()->syncWithoutDetaching([$c->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $ugo->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $gestione->id, 'nome' => 'PC Gestione ordinaria']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $piano = PianoRate::create([
        'gestione_id' => $gestione->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata',
    ]);
    app(GeneratePianoRateAction::class)->execute($piano, esercizio: $e);

    return compact('c', 'e', 'e25', 'gestione', 'riusata', 'unita', 'ugo', 'luca', 'piano') + ['pianoRiscaldamento' => null];
}

/**
 * Le righe del riparto con la parte dell'inquilino di un piano (di un'unità, se data), come
 * «nome|ruolo risolto|competenza dal→al|titolarità dal→al|giorni|importo».
 */
function ilpfRighe(PianoRate $piano, ?int $immobileId = null): array
{
    $giorno = fn ($d) => $d === null ? '' : substr((string) $d, 0, 10);

    return DB::table('righe_riparto')->join('anagrafiche', 'anagrafiche.id', '=', 'righe_riparto.anagrafica_id')
        ->where('righe_riparto.piano_rate_id', $piano->id)->where('righe_riparto.tipo', 'riparto')->where('righe_riparto.ruolo_richiesto', 'inquilino')
        ->when($immobileId !== null, fn ($q) => $q->where('righe_riparto.immobile_id', $immobileId))
        ->orderBy('righe_riparto.immobile_id')->orderBy('righe_riparto.titolarita_dal')->orderBy('anagrafiche.nome')
        ->get(['anagrafiche.nome', 'righe_riparto.ruolo_risolto', 'righe_riparto.competenza_dal', 'righe_riparto.competenza_al', 'righe_riparto.titolarita_dal',
            'righe_riparto.titolarita_al', 'righe_riparto.giorni_titolarita', 'righe_riparto.importo'])
        ->map(fn ($r) => sprintf('%s|%s|%s→%s|%s→%s|%s|%d', $r->nome, $r->ruolo_risolto, $giorno($r->competenza_dal), $giorno($r->competenza_al),
            $giorno($r->titolarita_dal), $giorno($r->titolarita_al), $r->giorni_titolarita ?? '', (int) $r->importo))
        ->values()->all();
}

/**
 * Come `ilpfConInes`, con la quota e la fine della riga date: Ines Galli inquilina dell'unità dal 2019 al 50 % o fino al 30/9, e il
 * preventivo ricalcolato dalla rotta vera.
 */
function ilpfConInesCosi($test, array $s, int $quota, ?string $fino): Anagrafica
{
    $ines = Anagrafica::forceCreate(['nome' => 'Ines Galli', 'email' => "ilpf-ic{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'ILPFINESCOSI' . str_pad((string) $s['unita']->id, 4, '0', STR_PAD_LEFT)]);
    $ines->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $ines->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => $quota, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => $fino, 'created_at' => now(), 'updated_at' => now()]);
    expect(ilpfRicalcola($test, $s, $s['piano'])['type'])->toBe('success');

    return $ines;
}

/** Il modulo dell'inizio locazione il 1/7/2026 di una persona data, con la quota data (la presa d'atto come in `ilpfInizioLocazione`). */
function ilpfInizioDi(array $s, Anagrafica $chi, int $quota = 100): array
{
    return ['anagrafica_entrante_id' => $chi->id, 'quota' => $quota] + ilpfInizioLocazione($s);
}

function ilpfAnteprimaDi($test, array $s, Anagrafica $chi, int $quota = 100): array
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), ilpfInizioDi($s, $chi, $quota))->assertOk()->json();
}

function ilpfRegistraDi($test, array $s, Anagrafica $chi, int $quota = 100): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), ilpfInizioDi($s, $chi, $quota))
        ->assertSessionHasNoErrors()->assertRedirect();
}

/** Il conduttore del prospetto con quel nome, o null se il prospetto non lo ha. */
function ilpfConduttore(array $prospetto, string $nome): ?array
{
    return collect($prospetto['conduttori'])->firstWhere('nome', $nome);
}

/** Le voci di un conduttore del prospetto come «giorni|importo|modo|pagato da». */
function ilpfVoci(array $conduttore): array
{
    return collect($conduttore['voci'])->map(fn ($v) => sprintf('%s|%d|%s|%s', $v['giorni'] ?? '', (int) $v['importo'], $v['modo'], $v['pagato_da'] ?? ''))->values()->all();
}

/** La prima informazione del cancello che contiene il testo dato, '' se non c'è. */
function ilpfInformazione(array $cancello, string $testo): string
{
    return (string) collect($cancello['informazioni'])->first(fn ($i) => str_contains($i, $testo));
}

/** Rinomina un'anagrafica, per le frasi con un nome che comincia per a. */
function ilpfRinomina(Anagrafica $chi, string $nome): void
{
    DB::table('anagrafiche')->where('id', $chi->id)->update(['nome' => $nome]);
    $chi->refresh();
}

/**
 * La straordinaria di `ruAggiungiStraordinaria` («Rifacimento facciata» € 1.200,00, sei rate dal 5 aprile) tutta a carico
 * dell'inquilino, deliberata il giorno dato, con la gestione «Facciata» portata al 1/10/2025–30/9/2026, generata (nessun inquilino:
 * ripiego al proprietario).
 */
function ilpfFacciata(array $s, string $delibera): PianoRate
{
    $piano = ruAggiungiStraordinaria($s, $delibera, 'inquilino');
    Gestione::whereKey($piano->gestione_id)->update(['data_inizio' => '2025-10-01', 'data_fine' => '2026-09-30']);
    app(GeneratePianoRateAction::class)->execute($piano->fresh(), esercizio: $s['e']);

    return $piano->fresh();
}

/**
 * `ilpfScenario` con una seconda unità sullo stesso piano: l'Interno 1 di Ugo (500 millesimi, nessun inquilino) e l'Interno 2 di
 * Bruno Neri (500 millesimi) con l'inquilino Zeno Altrui dal 2019, e il preventivo ricalcolato dalla rotta vera.
 */
function ilpfDueUnita($test): array
{
    $s = ilpfScenario();
    $tabella = Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $b = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => "ILPF-B{$s['unita']->id}", 'nome' => 'Interno 2', 'interno' => '2']);
    DB::table('quote_tabella')->where('tabella_id', $tabella->id)->where('immobile_id', $s['unita']->id)->update(['valore' => 500.0]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $b->id, 'valore' => 500.0, 'created_at' => now(), 'updated_at' => now()]);
    $bruno = Anagrafica::forceCreate(['nome' => 'Bruno Neri', 'email' => "ilpf-b{$b->id}@test.it", 'indirizzo' => 'Via Roma 2', 'codice_fiscale' => 'ILPFBRUNONER' . str_pad((string) $b->id, 4, '0', STR_PAD_LEFT)]);
    $zeno = Anagrafica::forceCreate(['nome' => 'Zeno Altrui', 'email' => "ilpf-z{$b->id}@test.it", 'indirizzo' => 'Via Roma 2', 'codice_fiscale' => 'ILPFZENOALTR' . str_pad((string) $b->id, 4, '0', STR_PAD_LEFT)]);
    foreach ([$bruno, $zeno] as $chi) {
        $chi->condomini()->syncWithoutDetaching([$s['c']->id]);
    }
    DB::table('anagrafica_immobile')->insert([
        ['anagrafica_id' => $bruno->id, 'immobile_id' => $b->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()],
        ['anagrafica_id' => $zeno->id, 'immobile_id' => $b->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);
    expect(ilpfRicalcola($test, $s, $s['piano'])['type'])->toBe('success');

    return $s + ['b' => $b, 'bruno' => $bruno, 'zeno' => $zeno];
}

// Rilievo «la data che decide guarda prima la gestione e poi l'esercizio» (DL3, medio), piano fermo. Non copre: l'importatore vero
// (LivelloEsercizi), la gestione agganciata dalla sua pagina (GestioneController::update), un piano della 1.10 senza riparto sulla
// gestione riusata.
it('DL3, gestione riusata — la gestione ordinaria nata sul 2025 e riusata sul 2026, «Preventivo 2026» con sei rate a giornale: l\'informazione dice che non si ricalcola più, la frase rimanda al prospetto e non promette le voci a Luca', function () {
    $s = ilpfScenarioGestioneRiusata();
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');

    // Il presupposto. La gestione è una sola, datata 2025 e agganciata ai due esercizi; la riga dell'inquilino del preventivo ha la
    // competenza 2026 (il motore riparte sull'esercizio): 120000 × 30 % = 36000, al proprietario per ripiego. Sei rate a giornale di
    // Ugo: 120000 / 12 = 10000 a rata, 6 × 10000 = 60000. Il piano è fermo.
    $g = DB::table('gestioni')->where('id', $s['gestione']->id)->first();
    expect($s['riusata']->id)->toBe($s['gestione']->id)
        ->and(substr((string) $g->data_inizio, 0, 10) . '→' . substr((string) $g->data_fine, 0, 10))->toBe('2025-01-01→2025-12-31')
        ->and(DB::table('esercizio_gestione')->where('gestione_id', $g->id)->orderBy('esercizio_id')->pluck('esercizio_id')->map(fn ($id) => (int) $id)->all())->toBe([$s['e25']->id, $s['e']->id])
        ->and(ilpfRighe($s['piano']))->toBe(['Ugo Rinaldi|proprietario|2026-01-01→2026-12-31|→||36000'])
        ->and(ilpfQuote($s['piano'], $s['ugo'], soloAGiornale: true))->toBe([6, 60000])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprima($this, $s);
    $cancello = $anteprima['cancello'];
    $frase = implode(' ', $anteprima['rate']['frasi']);

    // Il piano ha giorni dal 1/7/2026 (le sue righe arrivano al 31/12/2026) e non si ricalcola più: come con la gestione datata 2026
    // (primo test), l'informazione e il prospetto, non la promessa di quote a Luca.
    expect(ilpfInformazione($cancello, 'non si ricalcola più'))->toContain('Preventivo 2026')
        ->and($frase)->not->toContain('verranno intestate a Luca')
        ->and($frase)->toContain('prospetto degli oneri accessori')
        ->and($cancello['motivi'])->toBe([])
        ->and($cancello['richiesto'])->toBeFalse();

    // Il denaro è già giusto (controllo): registrato l'inizio, Luca non riceve quote e il prospetto gli dà da rimborsare a Ugo i suoi
    // 184 giorni (31 + 31 + 30 + 31 + 30 + 31): 36000 × 184/365 = 18147,95 → 18148.
    ilpfRegistra($this, $s);
    $luca = ilpfConduttore(app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']), 'Luca Ferri');
    expect(ilpfQuote($s['piano'], $s['luca']))->toBe([0, 0])
        ->and($luca['totale'])->toBe(18148)
        ->and($luca['nelle_sue_rate'])->toBe(0)
        ->and($luca['da_rimborsare'])->toBe(18148);
});

// Rilievo «la data che decide guarda prima la gestione e poi l'esercizio» (DL3, medio), piano non emesso. Non copre: la gestione
// riusata con un piano dell'anno prima ancora aperto, il ricalcolo rifiutato senza la presa d'atto.
it('DL3, gestione riusata, controllo — la stessa gestione nata sul 2025, «Preventivo 2026» generato e non emesso: il cancello chiede la spunta con il motivo che nomina il preventivo, e ricalcolato il piano dà a Luca i suoi 184 giorni', function () {
    $s = ilpfScenarioGestioneRiusata();

    // Il presupposto: nessuna rata a giornale, 12 quote di Ugo (12 × 10000 = 120000), il piano si ricalcola.
    expect(ilpfQuote($s['piano'], soloAGiornale: true))->toBe([0, 0])
        ->and(ilpfQuote($s['piano'], $s['ugo']))->toBe([12, 120000])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([]);

    $cancello = ilpfAnteprima($this, $s)['cancello'];
    $motivo = (string) collect($cancello['motivi'])->first(fn ($m) => str_contains($m, 'il destinatario cambierebbe'));

    expect($cancello['richiesto'])->toBeTrue()
        ->and($motivo)->toContain('Preventivo 2026');

    // Il destinatario cambia davvero: 36000 × 184/365 = 18147,95 → 18148 a Luca; a Ugo 120000 − 18148 = 101852 (84000 della parte
    // proprietario + 17852 dei 181 giorni senza inquilino, 36000 × 181/365 = 17852,05 → 17852: 84000 + 17852 = 101852).
    ilpfRegistra($this, $s);
    expect(ilpfRicalcola($this, $s, $s['piano'])['type'])->toBe('success')
        ->and(ilpfQuote($s['piano'], $s['luca'])[1])->toBe(18148)
        ->and(ilpfQuote($s['piano'], $s['ugo'])[1])->toBe(101852);
});

// Rilievo «la straordinaria a cavallo dei due anni» (DL3, basso). Non copre: la straordinaria deliberata dopo la fine della sua
// gestione, i tratti per capitolo, la competenza dichiarata sulla fattura.
it('DL3, straordinaria a cavallo dei due anni — preventivo fermo e «Rifacimento facciata» tutto sull\'inquilino, deliberato il 1/11/2025 su una gestione 1/10/2025–30/9/2026: nessun motivo, nessuna spunta, la frase non nomina la facciata e non promette niente a Luca', function () {
    $s = ilpfScenario();
    $facciata = ilpfFacciata($s, '2025-11-01');
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');

    // Il presupposto. La facciata ha la competenza del giorno della delibera, 1/11/2025: 120000 tutti all'inquilino, nessuno alla
    // generazione, al proprietario per ripiego; sei rate di Ugo (120000 / 6 = 20000, 6 × 20000 = 120000), nessuna a giornale. Il
    // preventivo ha sei rate a giornale (6 × 10000 = 60000): fermo il preventivo, la facciata si ricalcola.
    expect(ilpfRighe($facciata))->toBe(['Ugo Rinaldi|proprietario|2025-11-01→2025-11-01|→||120000'])
        ->and(ilpfQuote($facciata, $s['ugo']))->toBe([6, 120000])
        ->and(ilpfQuote($facciata, soloAGiornale: true))->toBe([0, 0])
        ->and(ilpfQuote($s['piano'], $s['ugo'], soloAGiornale: true))->toBe([6, 60000])
        ->and(PianoRate::immutabiliFra([$s['piano']->id, $facciata->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprima($this, $s);
    $cancello = $anteprima['cancello'];
    $frase = implode(' ', $anteprima['rate']['frasi']);

    // La facciata non ha giorni dal 1/7/2026: ricalcolata non dà niente a Luca (sotto), quindi non c'è un destinatario che cambia. Il
    // preventivo resta fermo e conta.
    expect($cancello['motivi'])->toBe([])
        ->and($cancello['richiesto'])->toBeFalse()
        ->and($frase)->not->toContain('Rifacimento facciata')
        ->and($frase)->not->toContain('verranno intestate a Luca')
        ->and(ilpfInformazione($cancello, 'non si ricalcola più'))->toContain('Preventivo 2026');

    // Il denaro (controllo): registrato l'inizio e ricalcolata la facciata, i 120000 restano a Ugo in sei rate, Luca non ha niente.
    ilpfRegistra($this, $s);
    expect(ilpfRicalcola($this, $s, $facciata)['type'])->toBe('success')
        ->and(ilpfQuote($facciata, $s['luca']))->toBe([0, 0])
        ->and(ilpfQuote($facciata, $s['ugo']))->toBe([6, 120000]);
});

// Controllo del rilievo «la straordinaria a cavallo dei due anni» (DL3, basso): senza, il test di sopra passerebbe anche togliendo
// tutte le straordinarie. Non copre: la delibera proprio il giorno dell'inizio.
it('DL3, straordinaria a cavallo dei due anni, controllo — la stessa gestione con la delibera il 1/8/2026: il motivo nomina la facciata, e ricalcolata la facciata va tutta a Luca', function () {
    $s = ilpfScenario();
    $facciata = ilpfFacciata($s, '2026-08-01');
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');

    // Il presupposto: la competenza è il 1/8/2026, dopo l'inizio; 120000 a Ugo per ripiego in sei rate, nessuna a giornale.
    expect(ilpfRighe($facciata))->toBe(['Ugo Rinaldi|proprietario|2026-08-01→2026-08-01|→||120000'])
        ->and(ilpfQuote($facciata, $s['ugo']))->toBe([6, 120000])
        ->and(PianoRate::immutabiliFra([$s['piano']->id, $facciata->id]))->toBe([$s['piano']->id]);

    $cancello = ilpfAnteprima($this, $s)['cancello'];
    $motivo = (string) collect($cancello['motivi'])->first(fn ($m) => str_contains($m, 'il destinatario cambierebbe'));

    expect($cancello['richiesto'])->toBeTrue()
        ->and($motivo)->toContain('Rifacimento facciata');

    // Il 1/8/2026 l'inquilino è Luca: ricalcolata, la facciata gli dà tutti i 120000 in sei rate (6 × 20000), a Ugo niente.
    ilpfRegistra($this, $s);
    expect(ilpfRicalcola($this, $s, $facciata)['type'])->toBe('success')
        ->and(ilpfQuote($facciata, $s['luca']))->toBe([6, 120000])
        ->and(ilpfQuote($facciata, $s['ugo']))->toBe([0, 0]);
});

// Rilievo «cambio in due passi: la condizione riconosce anche casi che non lo sono» (DL3, medio), caso (d): le righe di Ines finiscono
// il 30/9, prima della fine del piano, e dopo c'è il ripiego. Non copre: più inquilini di prima sullo stesso piano, le pertinenze, la
// data da cui il prospetto entra in gioco detta nella frase.
it('DL3, due passi (d) — Ines censita fino al 30/9 alla generazione, fine il 1/7 e inizio di Luca il 1/7 sul piano fermo: la frase dice che dal 1/7 al 30/9 la parte resta nelle rate di Ines e che per gli altri giorni Luca la rimborsa con il prospetto; il prospetto gli dà da rimborsare a Ugo € 90,74', function () {
    $s = ilpfScenario();
    $ines = ilpfConInesCosi($this, $s, 100, '2026-09-30');
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    ilpfFineDiInes($this, $s, $ines);

    // Il presupposto. Le righe della parte inquilino (120000 × 30 % = 36000): Ines dal 1/1 al 30/9, 31 + 28 + 31 + 30 + 31 + 30 + 31
    // + 31 + 30 = 273 giorni, 36000 × 273/365 = 26926,03 → 26926; Ugo per ripiego dal 1/10 al 31/12, 31 + 30 + 31 = 92 giorni,
    // 36000 × 92/365 = 9073,97 → 9074 (26926 + 9074 = 36000). La fine ha chiuso la riga di Ines al 30/6; il piano è fermo.
    expect(ilpfRighe($s['piano']))->toBe([
        'Ines Galli|inquilino|2026-01-01→2026-12-31|2026-01-01→2026-09-30|273|26926',
        'Ugo Rinaldi|proprietario|2026-01-01→2026-12-31|2026-10-01→2026-12-31|92|9074',
    ])
        ->and(substr((string) DB::table('anagrafica_immobile')->where('anagrafica_id', $ines->id)->where('immobile_id', $s['unita']->id)->value('data_fine'), 0, 10))->toBe('2026-06-30')
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprima($this, $s);
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = ilpfInformazione($anteprima['cancello'], 'non si ricalcola più');

    // Dei 184 giorni di Luca, 92 (1/7–30/9) sono nelle rate di Ines: chi li paga lo decide l'amministratore (decisione 32). Gli altri
    // 92 (1/10–31/12) sono nelle rate di Ugo: Luca glieli rimborsa con il prospetto. Il pannello deve dire tutti e due i pezzi.
    expect($frase)->toContain('Per gli altri giorni la rimborsa Luca Ferri a chi le paga, con il prospetto degli oneri accessori.')
        ->and($frase)->toContain('La parte a carico dell\'inquilino dal 1 luglio al 30 settembre 2026 resta nelle rate di Ines Galli: chi paga i giorni dopo la sua uscita lo decide l\'amministratore.')
        ->and($frase)->toContain('Il piano «Preventivo 2026» non si ricalcola più: anche le rate ancora da emettere restano a chi le ha.')
        ->and($informazione)->toContain('resta nelle rate di Ines Galli: chi paga i giorni dopo la sua uscita lo decide l\'amministratore — se serve, con un saldo manuale dal Wallet sulla stessa gestione; per gli altri giorni si regola con il prospetto degli oneri accessori');

    // Il denaro (controllo): registrato l'inizio, il prospetto dà a Ines i suoi 273 giorni nelle sue rate (26926) e a Luca i 92 giorni
    // dal 1/10 pagati da Ugo, 9074 da rimborsare (€ 90,74). Le quote non si muovono: Ugo 84000 della parte proprietario + 9074 del
    // ripiego = 93074, Ines 26926 (93074 + 26926 = 120000), Luca niente.
    ilpfRegistra($this, $s);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    $diInes = ilpfConduttore($p, 'Ines Galli');
    $diLuca = ilpfConduttore($p, 'Luca Ferri');
    expect($diInes['totale'])->toBe(26926)
        ->and($diInes['nelle_sue_rate'])->toBe(26926)
        ->and(ilpfVoci($diInes))->toBe(['273|26926|rate|'])
        ->and($diLuca['totale'])->toBe(9074)
        ->and($diLuca['da_rimborsare'])->toBe(9074)
        ->and(ilpfVoci($diLuca))->toBe(['92|9074|proprietario|Ugo Rinaldi'])
        ->and(ilpfQuote($s['piano'], $s['ugo']))->toBe([12, 93074])
        ->and(ilpfQuote($s['piano'], $ines))->toBe([12, 26926])
        ->and(ilpfQuote($s['piano'], $s['luca']))->toBe([0, 0]);
});

// Rilievo «cambio in due passi: la condizione riconosce anche casi che non lo sono» (DL3, medio), caso (e): Ines al 50 % resta
// inquilina e Luca entra al 50 %. Non copre: le cifre di un piano generato dopo (la divisione per quote e giorni), Ines al 100 %
// (la registrazione la rifiuta: 150 %).
it('DL3, due passi (e) — coinquilino: Ines al 50 % resta inquilino e Luca entra il 1/7 al 50 % sul piano fermo: la frase dice che la parte resta nelle rate di Ines, «che resta inquilino», senza «dopo la sua uscita», e che un piano generato dopo dividerà quelle voci fra i due', function () {
    $s = ilpfScenario();
    $ines = ilpfConInesCosi($this, $s, 50, null);
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');

    // Il presupposto. Ines è la sola inquilina alla generazione: tutta la parte inquilino è sua, 36000 (12 × 3000); Ugo 84000
    // (12 × 7000); 36000 + 84000 = 120000. Sei rate a giornale per ciascuno (6 × 3000 = 18000, 6 × 7000 = 42000): fermo.
    expect(ilpfQuote($s['piano'], $ines))->toBe([12, 36000])
        ->and(ilpfQuote($s['piano'], $s['ugo']))->toBe([12, 84000])
        ->and(ilpfQuote($s['piano'], $ines, soloAGiornale: true))->toBe([6, 18000])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprimaDi($this, $s, $s['luca'], 50);
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = ilpfInformazione($anteprima['cancello'], 'non si ricalcola più');

    // Ines non esce: non c'è un'uscita né un giorno dopo l'uscita da far decidere all'amministratore.
    expect($frase)->toContain('La parte a carico dell\'inquilino resta nelle rate di Ines Galli, che resta inquilino.')
        ->and($frase)->not->toContain('dopo la sua uscita')
        ->and($frase)->not->toContain('lo decide l\'amministratore')
        // Secondo giro sulle correzioni, «Forma: le dividerà non ha un antecedente» (basso): «quelle voci», come «intesterà quelle voci».
        ->and($frase)->toContain('Un piano generato dopo dividerà quelle voci fra Ines Galli e Luca Ferri, per quote e giorni.')
        ->and($frase)->not->toContain('Un piano generato dopo intesterà quelle voci')
        ->and($informazione)->toContain('resta nelle rate di Ines Galli, che resta inquilino')
        ->and($informazione)->not->toContain('dopo la sua uscita')
        ->and($informazione)->not->toContain('saldo manuale');

    // Il denaro (controllo): registrato, Ines resta inquilina senza fine, e il prospetto le dà tutta la parte dell'anno nelle sue rate,
    // 365 giorni, 36000; Luca non ha voci.
    ilpfRegistraDi($this, $s, $s['luca'], 50);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    $diInes = ilpfConduttore($p, 'Ines Galli');
    expect(DB::table('anagrafica_immobile')->where('anagrafica_id', $ines->id)->where('immobile_id', $s['unita']->id)->value('data_fine'))->toBeNull()
        ->and($diInes['totale'])->toBe(36000)
        ->and($diInes['nelle_sue_rate'])->toBe(36000)
        ->and(ilpfVoci($diInes))->toBe(['365|36000|rate|'])
        ->and(ilpfConduttore($p, 'Luca Ferri'))->toBeNull();
});

// Rilievo «cambio in due passi: la condizione riconosce anche casi che non lo sono» (DL3, medio), caso (a): il rinnovo con la stessa
// persona. Non copre: il rinnovo con una quota diversa, il rinnovo in un giorno diverso dalla fine.
it('DL3, due passi (a) — rinnovo: fine di Ines il 1/7 e inizio di Ines il 1/7 sul piano fermo: la frase dice che la parte è già nelle rate di Ines, senza un rimborso e senza il prospetto; il prospetto non ha niente da rimborsare', function () {
    $s = ilpfScenario();
    $ines = ilpfConInes($this, $s);
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    ilpfFineDiInes($this, $s, $ines);

    // Il presupposto: Ines 12 quote per 36000 (12 × 3000), Ugo 84000 (12 × 7000), 36000 + 84000 = 120000; il piano è fermo.
    expect(ilpfQuote($s['piano'], $ines))->toBe([12, 36000])
        ->and(ilpfQuote($s['piano'], $s['ugo']))->toBe([12, 84000])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprimaDi($this, $s, $ines);
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = ilpfInformazione($anteprima['cancello'], 'non si ricalcola più');

    // Chi entra è chi c'era: i giorni dal 1/7 sono nelle sue rate, non c'è niente da rimborsare a nessuno.
    expect($frase)->toContain('La parte a carico dell\'inquilino dal 1 luglio 2026 è già nelle rate di Ines Galli.')
        ->and($frase)->not->toContain('la rimborsa Ines Galli')
        ->and($frase)->not->toContain('prospetto')
        ->and($informazione)->toContain('è già nelle rate di Ines Galli')
        ->and($informazione)->not->toContain('si regola con il prospetto');

    // Il denaro (controllo): registrato il rinnovo, il prospetto ha solo Ines, 365 giorni nelle sue rate, 36000, da rimborsare 0.
    ilpfRegistraDi($this, $s, $ines);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    $diInes = ilpfConduttore($p, 'Ines Galli');
    expect(collect($p['conduttori'])->pluck('nome')->all())->toBe(['Ines Galli'])
        ->and($diInes['totale'])->toBe(36000)
        ->and($diInes['nelle_sue_rate'])->toBe(36000)
        ->and($diInes['da_rimborsare'])->toBe(0)
        ->and(ilpfVoci($diInes))->toBe(['365|36000|rate|']);
});

// Rilievo «Forma: le frasi nuove scrivono a davanti al nome» (basso), con il solo ripiego e un piano che si ricalcola. Non copre: la
// frase degli obbligati della fine locazione (FineLocazioneFrasiTest), i nomi con À.
it('Forma, «ad» davanti al nome — chi entra si chiama Andrea Ferri, preventivo fermo e riscaldamento che si ricalcola: «verranno intestate ad Andrea Ferri», «la rimborsa Andrea Ferri», «intesterà quelle voci ad Andrea Ferri», mai «a Andrea»', function () {
    $s = ilpfScenario(riscaldamento: true);
    ilpfRinomina($s['luca'], 'Andrea Ferri');
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');

    // Il presupposto: il preventivo ha sei rate a giornale (6 × 10000 = 60000) ed è fermo; il riscaldamento no.
    expect(ilpfQuote($s['piano'], $s['ugo'], soloAGiornale: true))->toBe([6, 60000])
        ->and(PianoRate::immutabiliFra([$s['piano']->id, $s['pianoRiscaldamento']->id]))->toBe([$s['piano']->id]);

    $frase = implode(' ', ilpfAnteprima($this, $s)['rate']['frasi']);

    expect($frase)->toContain('verranno intestate ad Andrea Ferri')
        ->and($frase)->toContain('la rimborsa Andrea Ferri a chi le paga, con il prospetto degli oneri accessori')
        ->and($frase)->toContain('Un piano generato dopo intesterà quelle voci ad Andrea Ferri.')
        ->and($frase)->not->toContain(' a Andrea Ferri');
});

// Rilievo «Forma: le frasi nuove scrivono a davanti al nome» (basso), nel cambio in due passi. Non copre: «restano intestate a» in
// apertura del blocco (non è una frase nuova), il nome dell'inquilino di prima (lì non c'è preposizione).
it('Forma, «ad» davanti al nome, due passi — Anna Galli esce il 1/7 e Andrea Ferri entra il 1/7 sul piano fermo: la parte resta nelle rate di Anna Galli e un piano generato dopo intesterà quelle voci ad Andrea Ferri', function () {
    $s = ilpfScenario();
    ilpfRinomina($s['luca'], 'Andrea Ferri');
    $anna = ilpfConInes($this, $s);
    ilpfRinomina($anna, 'Anna Galli');
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    ilpfFineDiInes($this, $s, $anna);

    // Il presupposto: Anna 12 quote per 36000 (12 × 3000), sei a giornale (6 × 3000 = 18000); il piano è fermo.
    expect(ilpfQuote($s['piano'], $anna))->toBe([12, 36000])
        ->and(ilpfQuote($s['piano'], $anna, soloAGiornale: true))->toBe([6, 18000])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $frase = implode(' ', ilpfAnteprima($this, $s)['rate']['frasi']);

    expect($frase)->toContain('Un piano generato dopo intesterà quelle voci ad Andrea Ferri.')
        ->and($frase)->toContain('resta nelle rate di Anna Galli')
        ->and($frase)->not->toContain(' a Andrea Ferri');
});

// Rilievo «le query nuove che mettono nomi nelle frasi non hanno un test negativo sul perimetro dell'unità» (sicurezza, basso):
// controllo, verde oggi. Non copre: bozzeFermeDi (FineLocazioneFrasiTest), le pertinenze, l'inquilino di B iscritto a un altro
// condominio.
it('DL3, perimetro dell\'unità — due unità sullo stesso piano fermo, l\'inquilino Zeno solo sull\'Interno 2, l\'inizio di Luca sull\'Interno 1: la frase rimanda al prospetto e Zeno non compare da nessuna parte', function () {
    $s = ilpfDueUnita($this);
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');

    // Il presupposto. 120000 su 500 + 500 millesimi: 60000 a unità. Interno 1: tutto a Ugo (parte inquilino 60000 × 30 % = 18000 per
    // ripiego, parte proprietario 42000; 18000 + 42000 = 60000), 12 × 5000. Interno 2: Zeno 18000 (12 × 1500), Bruno 42000
    // (12 × 3500); 60000 + 18000 + 42000 = 120000. Sei rate a giornale: fermo. Le righe dell'inquilino dei due interni: il ripiego di
    // Ugo sull'1, Zeno sul 2.
    expect(ilpfQuote($s['piano'], $s['ugo']))->toBe([12, 60000])
        ->and(ilpfQuote($s['piano'], $s['zeno']))->toBe([12, 18000])
        ->and(ilpfQuote($s['piano'], $s['bruno']))->toBe([12, 42000])
        ->and(ilpfQuote($s['piano'], $s['zeno'], soloAGiornale: true))->toBe([6, 9000])
        ->and(ilpfRighe($s['piano'], $s['unita']->id))->toBe(['Ugo Rinaldi|proprietario|2026-01-01→2026-12-31|→||18000'])
        ->and(ilpfRighe($s['piano'], $s['b']->id))->toBe(['Zeno Altrui|inquilino|2026-01-01→2026-12-31|→||18000'])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprima($this, $s);
    $frase = implode(' ', $anteprima['rate']['frasi']);

    // Sull'Interno 1 i giorni dal 1/7 sono nelle righe di ripiego di Ugo: la frase del prospetto, non quella dei due passi con Zeno.
    expect($frase)->toContain('la rimborsa Luca Ferri a chi le paga, con il prospetto degli oneri accessori')
        ->and($frase)->not->toContain('nelle rate di')
        ->and(json_encode($anteprima, JSON_UNESCAPED_UNICODE))->not->toContain('Zeno')
        ->and(ilpfInformazione($anteprima['cancello'], 'non si ricalcola più'))->toContain('si regola con il prospetto degli oneri accessori')
        ->and($anteprima['cancello']['motivi'])->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Secondo giro sulle correzioni della .47: DL3, lenti testi e sicurezza
|--------------------------------------------------------------------------
|
| Le pertinenze con storie diverse (una frase per unità, con la sua etichetta e il suo prospetto); il piano della 1.10 senza riparto
| registrato, che dopo il passaggio il prospetto lascia fuori; la data di quel piano sulla gestione riusata; il rinnovo con il ripiego dopo la fine delle
| righe; i tratti per capitolo, dove i giorni dell'inquilino di prima dal giorno dell'inizio non ci sono; il perimetro di «c'è ancora».
*/

/**
 * `ilpfScenario` con il «Box 12» pertinenza dell'Interno 1, tutti e due di Ugo: 900 millesimi all'Interno 1 e 100 al box. Ines Galli
 * inquilina dal 2019 (quota 100, senza fine) dell'appartamento, del box o di tutti e due, e il preventivo ricalcolato dalla rotta vera.
 * Parte inquilino: Interno 1 120000 × 900/1000 × 30 % = 32400, box 120000 × 100/1000 × 30 % = 3600 (32400 + 3600 = 36000).
 */
function ilpfConBox($test, bool $inesSullUnita, bool $inesSulBox): array
{
    $s = ilpfScenario();
    $tabella = Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    DB::table('quote_tabella')->where('tabella_id', $tabella->id)->where('immobile_id', $s['unita']->id)->update(['valore' => 900.0]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $box->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['ugo']->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $ines = Anagrafica::forceCreate(['nome' => 'Ines Galli', 'email' => "ilpf-ib{$box->id}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'ILPFINESBOX0' . str_pad((string) $box->id, 4, '0', STR_PAD_LEFT)]);
    $ines->condomini()->syncWithoutDetaching([$s['c']->id]);
    foreach (array_filter([$inesSullUnita ? $s['unita'] : null, $inesSulBox ? $box : null]) as $dove) {
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $ines->id, 'immobile_id' => $dove->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true,
            'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    expect(ilpfRicalcola($test, $s, $s['piano'])['type'])->toBe('success');

    return $s + ['box' => $box, 'ines' => $ines];
}

/** Come `ilpfFineDiInes`, sull'unità data (anche il box) e con le pertinenze spuntate date. */
function ilpfFineDiInesSu($test, array $s, Anagrafica $ines, Immobile $unita, array $pertinenze = []): void
{
    $riga = (int) DB::table('anagrafica_immobile')->where('immobile_id', $unita->id)->where('anagrafica_id', $ines->id)->where('tipologia', 'inquilino')->value('id');
    $dati = ['tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => null,
        'decorrenza' => '2026-07-01', 'quota' => 100, 'copia_autentica' => false, 'pertinenze' => array_map(fn (Immobile $p) => (int) $p->id, $pertinenze)];
    $anteprima = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $unita]), $dati)->assertOk()->json();
    if ($anteprima['cancello']['richiesto'] ?? false) {
        $dati += ['ho_letto' => true, 'nota_cancello' => 'Disdetta letta, rate emesse controllate'];
    }
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $unita]), $dati)->assertSessionHasNoErrors()->assertRedirect();
}

/** Il modulo dell'inizio locazione di Luca il 1/7/2026 sull'Interno 1, con il Box 12 spuntato fra le pertinenze. */
function ilpfInizioConBox(array $s): array
{
    return ['pertinenze' => [(int) $s['box']->id]] + ilpfInizioLocazione($s);
}

function ilpfAnteprimaConBox($test, array $s): array
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), ilpfInizioConBox($s))->assertOk()->json();
}

function ilpfRegistraConBox($test, array $s): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), ilpfInizioConBox($s))
        ->assertSessionHasNoErrors()->assertRedirect();
}

/** Il piano come lo lascia l'aggiornamento dalla 1.10: senza righe del riparto e, se chiesto, con `esercizio_id` nullo. */
function ilpfComeVersionePrecedente(PianoRate $piano, bool $senzaEsercizio = false): PianoRate
{
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    if ($senzaEsercizio) {
        DB::table('piani_rate')->where('id', $piano->id)->update(['esercizio_id' => null]);
    }

    return $piano->fresh();
}

/**
 * «Spese generali» (120000) diventa un capitolo del preventivo con la competenza a due tratti, la stagione del riscaldamento:
 * 1/1–15/4 e 15/10–31/12 (31 + 28 + 31 + 15 = 105 giorni, più 17 + 30 + 31 = 78: 183 in tutto).
 */
function ilpfCapitoloATratti(array $s): void
{
    $conto = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')
        ->where('piani_conti.gestione_id', $s['piano']->gestione_id)->where('conti.nome', 'Spese generali')->value('conti.id');
    $s['piano']->capitoli()->attach([$conto => ['importo' => 120000]]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $conto)->value('id');
    \App\Models\Gestionale\CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-01-01', 'al' => '2026-04-15', 'ordine' => 0]);
    \App\Models\Gestionale\CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-10-15', 'al' => '2026-12-31', 'ordine' => 1]);
}

/**
 * `ilpfScenario` con una seconda unità sullo stesso piano: l'Interno 1 di Ugo e l'Interno 2 di Bruno Neri, 500 millesimi ciascuno,
 * e Ines Galli inquilina di tutti e due dal 2019 (quota 100, senza fine); il preventivo ricalcolato dalla rotta vera.
 */
function ilpfInesSuDueUnita($test): array
{
    $s = ilpfScenario();
    $tabella = Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $b = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => "ILPF-I2{$s['unita']->id}", 'nome' => 'Interno 2', 'interno' => '2']);
    DB::table('quote_tabella')->where('tabella_id', $tabella->id)->where('immobile_id', $s['unita']->id)->update(['valore' => 500.0]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $b->id, 'valore' => 500.0, 'created_at' => now(), 'updated_at' => now()]);
    $bruno = Anagrafica::forceCreate(['nome' => 'Bruno Neri', 'email' => "ilpf-bi{$b->id}@test.it", 'indirizzo' => 'Via Roma 2', 'codice_fiscale' => 'ILPFBRUNOIN2' . str_pad((string) $b->id, 4, '0', STR_PAD_LEFT)]);
    $ines = Anagrafica::forceCreate(['nome' => 'Ines Galli', 'email' => "ilpf-i2{$b->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ILPFINESDUE0' . str_pad((string) $b->id, 4, '0', STR_PAD_LEFT)]);
    foreach ([$bruno, $ines] as $chi) {
        $chi->condomini()->syncWithoutDetaching([$s['c']->id]);
    }
    $riga = fn (Anagrafica $chi, Immobile $dove, string $tipologia) => ['anagrafica_id' => $chi->id, 'immobile_id' => $dove->id, 'tipologia' => $tipologia, 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()];
    DB::table('anagrafica_immobile')->insert([$riga($bruno, $b, 'proprietario'), $riga($ines, $s['unita'], 'inquilino'), $riga($ines, $b, 'inquilino')]);
    expect(ilpfRicalcola($test, $s, $s['piano'])['type'])->toBe('success');

    return $s + ['b' => $b, 'bruno' => $bruno, 'ines' => $ines];
}

// Secondo giro, rilievo «Pertinenze: per gli altri giorni la rimborsa Luca quando il ripiego sta sul box» (testi, medio), caso (a).
// Non copre: più di una pertinenza, le parti «presenti» o «propri» su un'unità e il ripiego sull'altra, l'unità mista.
it('Pertinenze (a) — Ines inquilina del solo Interno 1, il Box 12 col ripiego di Ugo, fine di Ines il 1/7 e inizio di Luca il 1/7 con il box spuntato sul piano fermo: una frase per unità, con l\'etichetta, e nessun «Per gli altri giorni»; il prospetto del box dà a Luca € 18,15 da rimborsare a Ugo', function () {
    $s = ilpfConBox($this, inesSullUnita: true, inesSulBox: false);
    $ines = $s['ines'];
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    ilpfFineDiInesSu($this, $s, $ines, $s['unita']);

    // Il presupposto. Interno 1: 120000 × 900/1000 = 108000, parte inquilino 30 % = 32400, tutta a Ines (12 × 2700). Box: 120000 ×
    // 100/1000 = 12000, parte inquilino 3600, al proprietario per ripiego. A Ugo 75600 (12 × 6300) sull'Interno 1 e 12000 (12 × 1000)
    // sul box: 75600 + 12000 = 87600; 87600 + 32400 = 120000. Il piano è fermo.
    expect(ilpfRighe($s['piano'], $s['unita']->id))->toBe(['Ines Galli|inquilino|2026-01-01→2026-12-31|→||32400'])
        ->and(ilpfRighe($s['piano'], $s['box']->id))->toBe(['Ugo Rinaldi|proprietario|2026-01-01→2026-12-31|→||3600'])
        ->and(ilpfQuote($s['piano'], $ines))->toBe([12, 32400])
        ->and(ilpfQuote($s['piano'], $s['ugo'])[1])->toBe(87600)
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprimaConBox($this, $s);
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = ilpfInformazione($anteprima['cancello'], 'non si ricalcola più');
    $unita = $s['unita']->etichetta_estesa;
    $box = $s['box']->etichetta_estesa;

    // I 184 giorni dal 1/7 dell'Interno 1 sono nelle rate di Ines; quelli del box sono nelle rate di Ugo, negli stessi giorni, e
    // stanno nel prospetto del box: non sono «altri giorni».
    expect($frase)->toContain(sprintf('Nell\'unità «%s» la parte a carico dell\'inquilino dal 1 luglio 2026 resta nelle rate di Ines Galli: chi paga i giorni dopo la sua uscita lo decide l\'amministratore.', $unita))
        ->and($frase)->toContain(sprintf('Nell\'unità «%s» la parte a carico dell\'inquilino dal 1 luglio 2026 la rimborsa Luca Ferri a chi le paga, con il prospetto degli oneri accessori di quell\'unità.', $box))
        ->and($frase)->not->toContain('Per gli altri giorni')
        ->and($informazione)->toContain(sprintf('le sue quote restano a chi le ha, e nell\'unità «%s» la parte a carico dell\'inquilino dal 1 luglio 2026 resta nelle rate di Ines Galli: ', $unita))
        ->and($informazione)->toContain(sprintf('; nell\'unità «%s» la parte a carico dell\'inquilino si regola con il prospetto degli oneri accessori di quell\'unità', $box))
        ->and($informazione)->not->toContain('per gli altri giorni');

    // Il denaro (controllo). Box: Luca dal 1/7 al 31/12, 31 + 31 + 30 + 31 + 30 + 31 = 184 giorni, 3600 × 184/365 = 1814,79 → 1815,
    // pagati da Ugo e da rimborsargli; dal 1/1 al 30/6, 181 giorni, 3600 × 181/365 = 1785,21 → 1785 senza conduttore (1815 + 1785 =
    // 3600). Interno 1: solo Ines, 365 giorni nelle sue rate, 32400.
    ilpfRegistraConBox($this, $s);
    $pBox = app(ProspettoOneriAccessori::class)->calcola($s['box'], $s['e']);
    $pUnita = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    $lucaBox = ilpfConduttore($pBox, 'Luca Ferri');
    expect($lucaBox['totale'])->toBe(1815)
        ->and($lucaBox['nelle_sue_rate'])->toBe(0)
        ->and($lucaBox['da_rimborsare'])->toBe(1815)
        ->and(ilpfVoci($lucaBox))->toBe(['184|1815|proprietario|Ugo Rinaldi'])
        ->and($pBox['senza_conduttore']['totale'])->toBe(1785)
        ->and(collect($pUnita['conduttori'])->pluck('nome')->all())->toBe(['Ines Galli'])
        ->and(ilpfVoci(ilpfConduttore($pUnita, 'Ines Galli')))->toBe(['365|32400|rate|'])
        ->and(ilpfQuote($s['piano'], $s['luca']))->toBe([0, 0]);
});

// Secondo giro, rilievo «Pertinenze: per gli altri giorni la rimborsa Luca» (testi, medio), caso (b): il verso contrario, il rimborso
// grosso sull'appartamento. Non copre: la fine di Ines registrata dall'appartamento con il box spuntato (Ines lì non c'è), l'unità mista.
it('Pertinenze (b) — Ines inquilina del solo Box 12, l\'Interno 1 col ripiego di Ugo, fine di Ines il 1/7 dal box e inizio di Luca il 1/7 con il box spuntato: sull\'Interno 1 la rimborsa Luca con il prospetto di quell\'unità, sul box resta nelle rate di Ines; il prospetto dell\'Interno 1 dà a Luca € 163,33', function () {
    $s = ilpfConBox($this, inesSullUnita: false, inesSulBox: true);
    $ines = $s['ines'];
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    ilpfFineDiInesSu($this, $s, $ines, $s['box']);

    // Il presupposto. Interno 1: parte inquilino 32400 al proprietario per ripiego; box: parte inquilino 3600 a Ines (12 × 300). A Ugo
    // 108000 sull'Interno 1 (12 × 9000) e 12000 − 3600 = 8400 sul box (12 × 700): 108000 + 8400 = 116400; 116400 + 3600 = 120000.
    expect(ilpfRighe($s['piano'], $s['unita']->id))->toBe(['Ugo Rinaldi|proprietario|2026-01-01→2026-12-31|→||32400'])
        ->and(ilpfRighe($s['piano'], $s['box']->id))->toBe(['Ines Galli|inquilino|2026-01-01→2026-12-31|→||3600'])
        ->and(ilpfQuote($s['piano'], $ines))->toBe([12, 3600])
        ->and(ilpfQuote($s['piano'], $s['ugo'])[1])->toBe(116400)
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprimaConBox($this, $s);
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = ilpfInformazione($anteprima['cancello'], 'non si ricalcola più');
    $unita = $s['unita']->etichetta_estesa;
    $box = $s['box']->etichetta_estesa;

    // Oggi «resta nelle rate di Ines» parla dei 3600 del box, e sotto «per gli altri giorni» c'è il rimborso dell'appartamento.
    expect($frase)->toContain(sprintf('Nell\'unità «%s» la parte a carico dell\'inquilino dal 1 luglio 2026 la rimborsa Luca Ferri a chi le paga, con il prospetto degli oneri accessori di quell\'unità.', $unita))
        ->and($frase)->toContain(sprintf('Nell\'unità «%s» la parte a carico dell\'inquilino dal 1 luglio 2026 resta nelle rate di Ines Galli: chi paga i giorni dopo la sua uscita lo decide l\'amministratore.', $box))
        ->and($frase)->not->toContain('Per gli altri giorni')
        ->and($informazione)->toContain(sprintf('nell\'unità «%s» la parte a carico dell\'inquilino si regola con il prospetto degli oneri accessori di quell\'unità', $unita))
        ->and($informazione)->toContain(sprintf('nell\'unità «%s» la parte a carico dell\'inquilino dal 1 luglio 2026 resta nelle rate di Ines Galli: ', $box))
        ->and($informazione)->not->toContain('per gli altri giorni');

    // Il denaro (controllo). Interno 1: Luca 184 giorni, 32400 × 184/365 = 16332,93 → 16333 da rimborsare a Ugo; senza conduttore i
    // 181 giorni, 32400 × 181/365 = 16067,07 → 16067 (16333 + 16067 = 32400). Box: Ines, 365 giorni nelle sue rate, 3600.
    ilpfRegistraConBox($this, $s);
    $pUnita = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    $pBox = app(ProspettoOneriAccessori::class)->calcola($s['box'], $s['e']);
    $lucaUnita = ilpfConduttore($pUnita, 'Luca Ferri');
    expect($lucaUnita['totale'])->toBe(16333)
        ->and($lucaUnita['da_rimborsare'])->toBe(16333)
        ->and(ilpfVoci($lucaUnita))->toBe(['184|16333|proprietario|Ugo Rinaldi'])
        ->and($pUnita['senza_conduttore']['totale'])->toBe(16067)
        ->and(ilpfVoci(ilpfConduttore($pBox, 'Ines Galli')))->toBe(['365|3600|rate|']);
});

// Secondo giro, rilievo «Pertinenze: per gli altri giorni la rimborsa Luca» (testi, medio), caso (c): controllo, verde oggi. Le due
// unità hanno la stessa storia e la frase resta senza etichette. Non copre: Ines su tutti e due con fini diverse.
it('Pertinenze (c), controllo — Ines inquilina dell\'Interno 1 e del Box 12, fine il 1/7 con il box spuntato, inizio di Luca il 1/7 con il box: la frase di oggi, senza etichette e senza il prospetto; i prospetti danno a Ines 32400 e 3600 nelle sue rate', function () {
    $s = ilpfConBox($this, inesSullUnita: true, inesSulBox: true);
    $ines = $s['ines'];
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    ilpfFineDiInesSu($this, $s, $ines, $s['unita'], [$s['box']]);

    // Il presupposto: la parte inquilino è tutta di Ines, 32400 + 3600 = 36000; la fine ha chiuso al 30/6 le sue righe sulle due unità.
    expect(ilpfRighe($s['piano'], $s['unita']->id))->toBe(['Ines Galli|inquilino|2026-01-01→2026-12-31|→||32400'])
        ->and(ilpfRighe($s['piano'], $s['box']->id))->toBe(['Ines Galli|inquilino|2026-01-01→2026-12-31|→||3600'])
        ->and(ilpfQuote($s['piano'], $ines)[1])->toBe(36000)
        ->and(DB::table('anagrafica_immobile')->where('anagrafica_id', $ines->id)->orderBy('immobile_id')->pluck('data_fine')->map(fn ($d) => substr((string) $d, 0, 10))->all())->toBe(['2026-06-30', '2026-06-30']);

    $anteprima = ilpfAnteprimaConBox($this, $s);
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = ilpfInformazione($anteprima['cancello'], 'non si ricalcola più');

    expect($frase)->toContain('La parte a carico dell\'inquilino dal 1 luglio 2026 resta nelle rate di Ines Galli: chi paga i giorni dopo la sua uscita lo decide l\'amministratore.')
        ->and($frase)->not->toContain('Nell\'unità')
        ->and($frase)->not->toContain($s['box']->etichetta_estesa)
        ->and($frase)->not->toContain('prospetto degli oneri accessori')
        ->and($frase)->not->toContain('Per gli altri giorni')
        ->and($informazione)->not->toContain('nell\'unità');

    // Il denaro (controllo): Ines 365 giorni nelle sue rate su tutte e due le unità.
    ilpfRegistraConBox($this, $s);
    $pUnita = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    $pBox = app(ProspettoOneriAccessori::class)->calcola($s['box'], $s['e']);
    expect(ilpfVoci(ilpfConduttore($pUnita, 'Ines Galli')))->toBe(['365|32400|rate|'])
        ->and(ilpfVoci(ilpfConduttore($pBox, 'Ines Galli')))->toBe(['365|3600|rate|']);
});

// Secondo giro, rilievo «Piano della 1.10 senza riparto, fermo: la frase manda al prospetto, che lo esclude» (testi, medio); terzo giro,
// rilievo «Piano della 1.10 generato con l'inquilino» (testi, basso), caso base Q1c: solo il proprietario ha le quote, e il prospetto
// il piano lo legge fino al passaggio. Non copre: il piano della 1.10 con decorrenza futura, le pertinenze.
it('Piano della 1.10 fermo — «Preventivo 2026» senza righe del riparto, sei rate a giornale, inizio di Luca il 1/7: la frase e l\'informazione dicono che dopo il passaggio il prospetto lo lascia fuori e che il rimborso si regola fra le parti, senza rimandare al prospetto né al Wallet; prima il prospetto lo legge, registrato lo esclude', function () {
    $s = ilpfScenario();
    ilpfComeVersionePrecedente($s['piano']);
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');

    // Il presupposto: nessuna riga del riparto; sei rate a giornale, tutte di Ugo (6 × 10000 = 60000); il piano è fermo.
    expect(DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->count())->toBe(0)
        ->and(ilpfQuote($s['piano'], $s['ugo'], soloAGiornale: true))->toBe([6, 60000])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    // Terzo giro, Q1c (controllo): prima del passaggio il prospetto il piano lo legge, ricostruito, e la parte dell'inquilino dell'anno,
    // 120000 × 30 % = 36000, è tutta senza conduttore. Il «non lo legge» al presente era vero solo dopo.
    $prima = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    expect($prima['piani_esclusi'])->toBe([])
        ->and(collect($prima['piani'])->map(fn ($p) => $p['nome'] . '|' . $p['fonte'])->all())->toBe(['Preventivo 2026|ricostruito'])
        ->and($prima['senza_conduttore']['totale'])->toBe(36000);

    $anteprima = ilpfAnteprima($this, $s);
    $cancello = $anteprima['cancello'];
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = ilpfInformazione($cancello, 'non si ricalcola più');

    // La parte che oggi la frase promette al prospetto è 36000 × 184/365 = 18147,95 → 18148: dopo la registrazione il prospetto non
    // la ha (il riparto ricostruito mette Luca, le rate sono di Ugo).
    expect($frase)->toContain('Il piano «Preventivo 2026» non si ricalcola più: anche le rate ancora da emettere restano a chi le ha. È stato generato da una versione precedente, senza riparto registrato: dopo il passaggio il prospetto degli oneri accessori lo lascia fuori, e la parte a carico dell\'inquilino dal 1 luglio 2026 non si divide; il rimborso di Luca Ferri a chi le paga si regola fra le parti.')
        ->and($frase)->toContain('versione precedente')
        ->and($frase)->not->toContain('non lo legge')
        ->and($frase)->not->toContain('con il prospetto degli oneri accessori')
        ->and($frase)->not->toContain('si regola con il prospetto')
        ->and($frase)->not->toContain('Wallet')
        ->and($informazione)->toContain('il piano «Preventivo 2026» non si ricalcola più: le sue quote restano a chi le ha, e dopo il passaggio il prospetto degli oneri accessori lo lascia fuori (è stato generato da una versione precedente, senza riparto registrato): il rimborso della parte a carico dell\'inquilino si regola fra le parti')
        ->and($informazione)->toContain('versione precedente')
        ->and($informazione)->not->toContain('non lo legge')
        ->and($informazione)->not->toContain('con il prospetto degli oneri accessori')
        ->and($informazione)->not->toContain('si regola con il prospetto')
        ->and($informazione)->not->toContain('Wallet')
        ->and($cancello['motivi'])->toBe([])
        ->and($cancello['richiesto'])->toBeFalse();

    // Il denaro (controllo): registrato l'inizio, il prospetto esclude il piano e non ha conduttori; Luca non ha quote.
    ilpfRegistra($this, $s);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    expect(collect($p['piani_esclusi'])->pluck('nome')->all())->toBe(['Preventivo 2026'])
        ->and((string) collect($p['piani_esclusi'])->first()['motivo'])->toContain('versione precedente')
        ->and($p['conduttori'])->toBe([])
        ->and(ilpfQuote($s['piano'], $s['luca']))->toBe([0, 0]);
});

// Controllo del rilievo «Piano della 1.10 senza riparto, fermo» (testi, medio): con il riparto registrato la frase del prospetto resta,
// ed è vera. Non copre: le pertinenze (sopra), il piano della 1.10 non emesso (DL3, sopra).
it('Piano della 1.10 fermo, controllo — lo stesso piano con il riparto registrato: la frase rimanda al prospetto, non nomina la versione precedente, e registrato il prospetto dà a Luca € 181,48 da rimborsare', function () {
    $s = ilpfScenario();
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');

    // Il presupposto: il riparto c'è (la riga di ripiego di Ugo, 36000), sei rate a giornale (6 × 10000 = 60000).
    expect(ilpfRighe($s['piano']))->toBe(['Ugo Rinaldi|proprietario|2026-01-01→2026-12-31|→||36000'])
        ->and(ilpfQuote($s['piano'], $s['ugo'], soloAGiornale: true))->toBe([6, 60000]);

    $anteprima = ilpfAnteprima($this, $s);
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = ilpfInformazione($anteprima['cancello'], 'non si ricalcola più');

    expect($frase)->toContain('La parte a carico dell\'inquilino dal 1 luglio 2026 la rimborsa Luca Ferri a chi le paga, con il prospetto degli oneri accessori')
        ->and($frase)->not->toContain('versione precedente')
        ->and($informazione)->toContain('si regola con il prospetto degli oneri accessori')
        ->and($informazione)->not->toContain('versione precedente');

    // 36000 × 184/365 = 18147,95 → 18148 a Luca, da rimborsare a Ugo; il piano non è escluso.
    ilpfRegistra($this, $s);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    expect($p['piani_esclusi'])->toBe([])
        ->and(ilpfConduttore($p, 'Luca Ferri')['da_rimborsare'])->toBe(18148);
});

// Secondo giro, rilievo «DL3, la data del piano della 1.10 sulla gestione riusata» (testi, basso), piano fermo. Non copre: il piano
// della 1.10 creato a cavallo d'anno (la deduzione dalla data di creazione), `PianoRate::passaggiDaSeguire`, l'importatore vero.
it('DL3, gestione riusata, piano della 1.10 fermo — «Preventivo 2026» senza riparto e con l\'esercizio nullo sulla gestione nata nel 2025, sei rate a giornale: l\'informazione dice che non si ricalcola più e la frase non promette le voci a Luca', function () {
    $s = ilpfScenarioGestioneRiusata();
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    $piano = ilpfComeVersionePrecedente($s['piano'], senzaEsercizio: true);

    // Il presupposto: l'esercizio non è salvato (la migrazione lo riempie solo con un esercizio per gestione, qui sono due), ma il
    // motore lo deduce sul 2026; nessuna riga del riparto; sei rate a giornale di Ugo (6 × 10000 = 60000); il piano è fermo.
    expect($piano->esercizio_id)->toBeNull()
        ->and((new \App\Services\Riparto\CompetenzaDelPiano)->esercizioDelPiano($piano)?->id)->toBe($s['e']->id)
        ->and(DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->count())->toBe(0)
        ->and(ilpfQuote($piano, $s['ugo'], soloAGiornale: true))->toBe([6, 60000])
        ->and(PianoRate::immutabiliFra([$piano->id]))->toBe([$piano->id]);

    $anteprima = ilpfAnteprima($this, $s);
    $cancello = $anteprima['cancello'];
    $frase = implode(' ', $anteprima['rate']['frasi']);

    // Il piano ha giorni dal 1/7/2026 (il suo esercizio è il 2026) e non si ricalcola più. La frase è quella del piano della 1.10
    // (rilievo «il prospetto lo esclude»).
    expect(ilpfInformazione($cancello, 'non si ricalcola più'))->toContain('Preventivo 2026')
        ->and($frase)->not->toContain('verranno intestate a Luca')
        ->and($frase)->toContain('versione precedente')
        ->and($cancello['motivi'])->toBe([])
        ->and($cancello['richiesto'])->toBeFalse();
});

// Secondo giro, rilievo «DL3, la data del piano della 1.10 sulla gestione riusata» (testi, basso), piano non emesso: regressione
// della .47 (nella .46 la spunta c'era). Non copre: un «Preventivo 2025» della 1.10 sulla stessa gestione (escluso con la data di
// creazione), il ricalcolo senza la presa d'atto.
it('DL3, gestione riusata, piano della 1.10 non emesso — lo stesso piano senza riparto e senza esercizio, generato e non emesso: il cancello chiede la spunta con il motivo che lo nomina, e ricalcolato dà a Luca i suoi 184 giorni', function () {
    $s = ilpfScenarioGestioneRiusata();
    $piano = ilpfComeVersionePrecedente($s['piano'], senzaEsercizio: true);

    // Il presupposto: esercizio nullo e dedotto sul 2026; dodici quote di Ugo (12 × 10000 = 120000), nessuna a giornale.
    expect($piano->esercizio_id)->toBeNull()
        ->and((new \App\Services\Riparto\CompetenzaDelPiano)->esercizioDelPiano($piano)?->id)->toBe($s['e']->id)
        ->and(ilpfQuote($piano, $s['ugo']))->toBe([12, 120000])
        ->and(ilpfQuote($piano, soloAGiornale: true))->toBe([0, 0])
        ->and(PianoRate::immutabiliFra([$piano->id]))->toBe([]);

    $cancello = ilpfAnteprima($this, $s)['cancello'];
    $motivo = (string) collect($cancello['motivi'])->first(fn ($m) => str_contains($m, 'il destinatario cambierebbe'));

    expect($cancello['richiesto'])->toBeTrue()
        ->and($motivo)->toContain('Preventivo 2026');

    // Il destinatario cambia davvero: 36000 × 184/365 = 18147,95 → 18148 a Luca; a Ugo 120000 − 18148 = 101852 (84000 della parte
    // proprietario + 17852 dei 181 giorni senza inquilino: 84000 + 17852 = 101852).
    ilpfRegistra($this, $s);
    expect(ilpfRicalcola($this, $s, $piano)['type'])->toBe('success')
        ->and(ilpfQuote($piano, $s['luca'])[1])->toBe(18148)
        ->and(ilpfQuote($piano, $s['ugo'])[1])->toBe(101852);
});

// Secondo giro, rilievo «Rinnovo più ripiego: dal 1 luglio è già nelle rate di Ines anche per i giorni che Ines rimborsa» (testi,
// basso). Non copre: l'informazione del cancello (la stessa correzione, non chiesta qui), il rinnovo con una quota diversa.
it('DL3, rinnovo più ripiego — Ines censita fino al 30/9, fine il 1/7 e rinnovo di Ines il 1/7 sul piano fermo: dal 1/7 al 30/9 la parte è già nelle sue rate, per gli altri giorni la rimborsa lei con il prospetto, € 90,74 a Ugo', function () {
    $s = ilpfScenario();
    $ines = ilpfConInesCosi($this, $s, 100, '2026-09-30');
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    ilpfFineDiInes($this, $s, $ines);

    // Il presupposto (come il caso (d)): Ines dal 1/1 al 30/9, 273 giorni, 36000 × 273/365 = 26926,03 → 26926; Ugo per ripiego dal
    // 1/10 al 31/12, 92 giorni, 36000 × 92/365 = 9073,97 → 9074 (26926 + 9074 = 36000). Il piano è fermo.
    expect(ilpfRighe($s['piano']))->toBe([
        'Ines Galli|inquilino|2026-01-01→2026-12-31|2026-01-01→2026-09-30|273|26926',
        'Ugo Rinaldi|proprietario|2026-01-01→2026-12-31|2026-10-01→2026-12-31|92|9074',
    ])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $frase = implode(' ', ilpfAnteprimaDi($this, $s, $ines)['rate']['frasi']);

    // Dal 1/7 sono nelle rate di Ines solo i 92 giorni fino al 30/9; dal 1/10 sono nelle rate di Ugo, e Ines glieli rimborsa.
    expect($frase)->toContain('La parte a carico dell\'inquilino dal 1 luglio al 30 settembre 2026 è già nelle rate di Ines Galli.')
        ->and($frase)->toContain('Per gli altri giorni la rimborsa Ines Galli');

    // Il denaro (controllo): registrato il rinnovo, Ines ha 36000 in tutto, 26926 nelle sue rate e 9074 da rimborsare a Ugo
    // (26926 + 9074 = 36000).
    ilpfRegistraDi($this, $s, $ines);
    $diInes = ilpfConduttore(app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']), 'Ines Galli');
    expect($diInes['totale'])->toBe(36000)
        ->and($diInes['nelle_sue_rate'])->toBe(26926)
        ->and($diInes['da_rimborsare'])->toBe(9074)
        ->and(collect(ilpfVoci($diInes))->sort()->values()->all())->toBe(['273|26926|rate|', '92|9074|proprietario|Ugo Rinaldi']);
});

// Secondo giro, rilievo «Tratti per capitolo: l'inquilino di prima c'è dal 1/7 al 30/9 anche quando la voce non ha competenza» (testi,
// basso). Non copre: i tratti con la fine delle righe dentro un tratto (giorni veri dell'inquilino di prima dopo l'inizio), il
// gradino «conto».
it('DL3, tratti per capitolo — «Spese generali» con la competenza 1/1–15/4 e 15/10–31/12, Ines censita fino al 30/9, fine il 1/7 e inizio di Luca il 1/7 sul piano fermo: la frase non mette niente nelle rate di Ines e dice che dal 1/7 la rimborsa Luca; il prospetto gli dà € 153,44', function () {
    $s = ilpfScenario();
    ilpfCapitoloATratti($s);
    $ines = ilpfConInesCosi($this, $s, 100, '2026-09-30');
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    ilpfFineDiInes($this, $s, $ines);

    // Il presupposto. La parte inquilino 36000 su 183 giorni di competenza: Ines i 105 giorni del primo tratto, tutti prima del 1/7,
    // 36000 × 105/183 = 20655,74 → 20656; Ugo per ripiego i 78 giorni dal 15/10, 36000 × 78/183 = 15344,26 → 15344 (20656 + 15344 =
    // 36000). Dal 1/7 al 30/9 la voce non ha competenza: la riga di Ines arriva al 30/9 solo con il tratto di titolarità. Il piano è fermo.
    expect(ilpfRighe($s['piano']))->toBe([
        'Ines Galli|inquilino|2026-01-01→2026-12-31|2026-01-01→2026-09-30|105|20656',
        'Ugo Rinaldi|proprietario|2026-01-01→2026-12-31|2026-10-15→2026-12-31|78|15344',
    ])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprima($this, $s);
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = ilpfInformazione($anteprima['cancello'], 'non si ricalcola più');

    // Nelle rate di Ines, dal 1/7, non c'è niente: non c'è un giorno da far decidere all'amministratore, né un saldo da scrivere.
    expect($frase)->not->toContain('resta nelle rate di Ines')
        // Terzo giro sulle correzioni: la data è il primo giorno vero dopo l'inizio, la ripresa del riscaldamento il 15/10.
        ->and($frase)->toContain('La parte a carico dell\'inquilino dal 15 ottobre 2026 la rimborsa Luca Ferri')
        ->and($informazione)->not->toContain('resta nelle rate di Ines');

    // Il denaro (controllo): registrato l'inizio, Luca i 78 giorni dal 15/10, 15344, da rimborsare a Ugo; Ines i suoi 105 nelle rate.
    ilpfRegistra($this, $s);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    $diLuca = ilpfConduttore($p, 'Luca Ferri');
    expect($diLuca['totale'])->toBe(15344)
        ->and($diLuca['da_rimborsare'])->toBe(15344)
        ->and(ilpfVoci($diLuca))->toBe(['78|15344|proprietario|Ugo Rinaldi'])
        ->and(ilpfVoci(ilpfConduttore($p, 'Ines Galli')))->toBe(['105|20656|rate|']);
});

// Secondo giro, rilievo «il perimetro di c'è ancora non ha un test negativo» (sicurezza, basso): controllo, verde oggi; rosso se si
// toglie `whereIn('immobile_id', $immobileIds)` da «c'è ancora». Non copre: la catena dei pagatori del prospetto fra due unità
// (ProspettoCambioInquilinoTest), Ines inquilina di una pertinenza non spuntata.
it('DL3, perimetro di «c\'è ancora» — Ines inquilina dell\'Interno 1 e dell\'Interno 2, esce dall\'Interno 1 il 1/7 e Luca vi entra il 1/7 sul piano fermo: la frase dice «dopo la sua uscita», non «che resta inquilino»', function () {
    $s = ilpfInesSuDueUnita($this);
    $ines = $s['ines'];
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    ilpfFineDiInes($this, $s, $ines);

    // Il presupposto. 120000 su 500 + 500 millesimi: 60000 a unità, parte inquilino 18000 ciascuna, tutte e due a Ines (18000 + 18000 =
    // 36000); Ugo 42000 e Bruno 42000 (36000 + 42000 + 42000 = 120000). Ines esce dal solo Interno 1: sull'Interno 2 resta inquilina.
    expect(ilpfRighe($s['piano'], $s['unita']->id))->toBe(['Ines Galli|inquilino|2026-01-01→2026-12-31|→||18000'])
        ->and(ilpfRighe($s['piano'], $s['b']->id))->toBe(['Ines Galli|inquilino|2026-01-01→2026-12-31|→||18000'])
        ->and(ilpfQuote($s['piano'], $ines)[1])->toBe(36000)
        ->and(ilpfQuote($s['piano'], $s['ugo'])[1])->toBe(42000)
        ->and(ilpfQuote($s['piano'], $s['bruno'])[1])->toBe(42000)
        ->and(substr((string) DB::table('anagrafica_immobile')->where('anagrafica_id', $ines->id)->where('immobile_id', $s['unita']->id)->value('data_fine'), 0, 10))->toBe('2026-06-30')
        ->and(DB::table('anagrafica_immobile')->where('anagrafica_id', $ines->id)->where('immobile_id', $s['b']->id)->value('data_fine'))->toBeNull()
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprima($this, $s);
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = ilpfInformazione($anteprima['cancello'], 'non si ricalcola più');

    // Sull'Interno 1 Ines non c'è più: che resti inquilina dell'Interno 2 non conta.
    expect($frase)->toContain('resta nelle rate di Ines Galli: chi paga i giorni dopo la sua uscita lo decide l\'amministratore')
        ->and($frase)->not->toContain('che resta inquilino')
        ->and($informazione)->toContain('dopo la sua uscita')
        ->and($informazione)->not->toContain('che resta inquilino');
});

// Secondo giro sulle correzioni, «c'è ancora» unità per unità: Ines esce dall'appartamento e resta inquilina del box. Su ciascuna unità
// la frase dice la sua storia. NON copre: la frase finale sul piano generato dopo (dice la divisione fra Ines e Luca anche per
// l'appartamento, dove Luca sarà solo), il prospetto; la registrazione: con il coinquilino sul box spuntato la pertinenza dà a chi
// entra la quota di chi esce (100) e lo sforo la rifiuta; è un limite di prima di questa beta.
it('Pertinenze (d) — Ines inquilina al 50 % dell\'Interno 1 e del Box 12 esce dal solo appartamento; Luca entra al 50 % su tutti e due: sull\'appartamento «dopo la sua uscita», sul box «che resta inquilino»', function () {
    $s = ilpfConBox($this, inesSullUnita: true, inesSulBox: true);
    $ines = $s['ines'];
    DB::table('anagrafica_immobile')->where('anagrafica_id', $ines->id)->where('tipologia', 'inquilino')->update(['quota' => 50]);
    expect(ilpfRicalcola($this, $s, $s['piano'])['type'])->toBe('success');
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    ilpfFineDiInesSu($this, $s, $ines, $s['unita']);

    // Il presupposto: Ines ha chiuso la sola riga dell'appartamento; sul box resta, al 50 %.
    expect(DB::table('anagrafica_immobile')->where('anagrafica_id', $ines->id)->where('immobile_id', $s['unita']->id)->value('data_fine'))->not->toBeNull()
        ->and(DB::table('anagrafica_immobile')->where('anagrafica_id', $ines->id)->where('immobile_id', $s['box']->id)->value('data_fine'))->toBeNull();

    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), ['quota' => 50] + ilpfInizioConBox($s))
        ->assertOk()->json();
    $frase = implode(' ', $anteprima['rate']['frasi']);

    expect($frase)->toContain(sprintf('Nell\'unità «%s» la parte a carico dell\'inquilino dal 1 luglio 2026 resta nelle rate di Ines Galli: chi paga i giorni dopo la sua uscita lo decide l\'amministratore.', $s['unita']->etichetta_estesa))
        ->and($frase)->toContain(sprintf('Nell\'unità «%s» la parte a carico dell\'inquilino resta nelle rate di Ines Galli, che resta inquilino.', $s['box']->etichetta_estesa));
});

/*
|--------------------------------------------------------------------------
| Terzo giro sulle correzioni della .47: lente testi
|--------------------------------------------------------------------------
|
| Il piano della 1.10 generato con l'inquilino: la frase segue chi ha le quote (il rinnovo, i due passi, il coinquilino), e dice che il
| prospetto lascia fuori il piano solo quando dopo il passaggio lo lascia fuori davvero. Il piano con le righe solo sulla pertinenza
| nomina l'unità. Nei tratti per capitolo l'intervallo comincia dal primo giorno vero dal giorno dell'inizio.
*/

/**
 * Lo scenario Q2 del terzo giro: `ilpfScenario`, Ines Galli inquilina dal 2019 (la quota data, senza fine) e il preventivo ricalcolato
 * dalla rotta vera, poi riportato com'era nella 1.10 (senza righe del riparto) ed emesso fino al 30/6. Le quote restano quelle
 * generate con Ines: parte inquilino 120000 × 30 % = 36000, 12 × 3000 a Ines; parte proprietario 84000, 12 × 7000 a Ugo.
 */
function ilpfInesVersionePrecedente($test, int $quota = 100): array
{
    $s = ilpfScenario();
    $ines = $quota === 100 ? ilpfConInes($test, $s) : ilpfConInesCosi($test, $s, $quota, null);
    ilpfComeVersionePrecedente($s['piano']);
    ilpfEmettiFino($test, $s, $s['piano'], '2026-06-30');

    return $s + ['ines' => $ines];
}

/**
 * Il pezzo della frase del blocco «Rate» che parla del piano dato: da «Il piano «nome»» fino al piano fermo seguente, o alla fine.
 * Serve quando due piani fermi hanno frasi che si somigliano (la stessa frase del box può stare in tutti e due).
 */
function ilpfFraseDelPiano(array $anteprima, string $nome): string
{
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $da = mb_strpos($frase, 'Il piano «' . $nome . '»');
    if ($da === false) {
        return '';
    }
    $resto = mb_substr($frase, $da);
    $dopo = collect(['Il piano «', 'I piani «'])->map(fn (string $t) => mb_strpos($resto, $t, 1))->filter(fn ($p) => $p !== false)->min();

    return $dopo === null ? $resto : mb_substr($resto, 0, $dopo);
}

/**
 * Lo scenario Q1d del terzo giro, sopra `ilpfConBox`: la tabella «Autorimessa» con il solo Box 12 (1000 millesimi), la gestione
 * «Autorimessa 2026» con «Pulizia autorimessa» € 365,00 tutta all'inquilino, e il piano «Rate autorimessa 2026» in dodici rate dal
 * 5 gennaio, generato.
 */
function ilpfAutorimessa(array $s): PianoRate
{
    $tabella = Tabella::create(['condominio_id' => $s['c']->id, 'nome' => 'Autorimessa', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $s['box']->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    return ilpfGestioneConPiano($s['c'], $s['e'], $tabella, 'Autorimessa 2026', 'Pulizia autorimessa', 36500, ['inquilino' => 100], 'Rate autorimessa 2026', 12, '2026-01-05');
}

/**
 * Lo scenario Q3 del terzo giro: «Spese generali» con la competenza a due tratti (`ilpfCapitoloATratti`, 105 + 78 = 183 giorni), Ines
 * Galli inquilina dal 2019 fino al 31/10 e il preventivo ricalcolato, emesso fino al 30/6, poi la fine di Ines il 1/7.
 */
function ilpfTrattiFinoAOttobre($test): array
{
    $s = ilpfScenario();
    ilpfCapitoloATratti($s);
    $ines = ilpfConInesCosi($test, $s, 100, '2026-10-31');
    ilpfEmettiFino($test, $s, $s['piano'], '2026-06-30');
    ilpfFineDiInes($test, $s, $ines);

    return $s + ['ines' => $ines];
}

// Terzo giro, rilievo «Piano della 1.10 generato con l'inquilino» (testi, basso), scenario Q2a: il rinnovo, le quote sono di Ines e Ines
// entra di nuovo. NON copre: l'informazione del cancello oltre alle tre affermazioni false, il rinnovo con una quota diversa.
it('Piano della 1.10 con l\'inquilino, rinnovo (Q2a) — «Preventivo 2026» generato con Ines, senza riparto, fermo; fine di Ines il 1/7 e rinnovo di Ines il 1/7: la frase dice che la parte è già nelle rate di Ines, senza «versione precedente», «lascia fuori», «non lo legge» né un rimborso di Ines; il prospetto le dà 36000 nelle sue rate', function () {
    $s = ilpfInesVersionePrecedente($this);
    $ines = $s['ines'];
    ilpfFineDiInes($this, $s, $ines);

    // Il presupposto. Nessuna riga del riparto. Ines: 120000 × 30 % = 36000, 12 × 3000; sei a giornale, 6 × 3000 = 18000. Ugo:
    // 120000 × 70 % = 84000, 12 × 7000 (36000 + 84000 = 120000). Il piano è fermo.
    expect(DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->count())->toBe(0)
        ->and(ilpfQuote($s['piano'], $ines))->toBe([12, 36000])
        ->and(ilpfQuote($s['piano'], $s['ugo']))->toBe([12, 84000])
        ->and(ilpfQuote($s['piano'], $ines, soloAGiornale: true))->toBe([6, 18000])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprimaDi($this, $s, $ines);
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = ilpfInformazione($anteprima['cancello'], 'non si ricalcola più');

    // Chi entra ha le quote: i giorni dal 1/7 (36000 × 184/365 = 18147,95 → 18148) sono già nelle rate di Ines, e dopo il rinnovo il
    // prospetto il piano lo legge (sotto). Oggi la frase dice che non lo legge e che il rimborso di Ines si regola fra le parti.
    expect($frase)->toContain('La parte a carico dell\'inquilino dal 1 luglio 2026 è già nelle rate di Ines Galli.')
        ->and($frase)->not->toContain('versione precedente')
        ->and($frase)->not->toContain('lascia fuori')
        ->and($frase)->not->toContain('non lo legge')
        ->and($frase)->not->toContain('rimborso di Ines')
        ->and($informazione)->not->toContain('non lo legge')
        ->and($informazione)->not->toContain('lascia fuori')
        ->and($informazione)->not->toContain('si regola fra le parti');

    // Il denaro (controllo): registrato il rinnovo, il prospetto legge il piano (ricostruito con Ines e Ugo, le persone delle rate) e
    // dà a Ines i 365 giorni nelle sue rate, 36000, da rimborsare 0.
    ilpfRegistraDi($this, $s, $ines);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    $diInes = ilpfConduttore($p, 'Ines Galli');
    expect($p['piani_esclusi'])->toBe([])
        ->and(collect($p['conduttori'])->pluck('nome')->all())->toBe(['Ines Galli'])
        ->and($diInes['totale'])->toBe(36000)
        ->and($diInes['nelle_sue_rate'])->toBe(36000)
        ->and($diInes['da_rimborsare'])->toBe(0)
        ->and(ilpfVoci($diInes))->toBe(['365|36000|rate|']);
});

// Terzo giro, rilievo «Piano della 1.10 generato con l'inquilino» (testi, basso), scenario Q2b: i due passi, le quote sono di Ines,
// uscita, ed entra Luca. NON copre: l'informazione del cancello, la fine e l'inizio in giorni diversi.
it('Piano della 1.10 con l\'inquilino, due passi (Q2b) — «Preventivo 2026» generato con Ines, senza riparto, fermo; fine di Ines il 1/7 e inizio di Luca il 1/7: la frase dice che dopo il passaggio il prospetto lo lascia fuori e che la parte resta nelle rate di Ines, chi paga lo decide l\'amministratore, senza un rimborso di Luca; registrato, il prospetto lo esclude', function () {
    $s = ilpfInesVersionePrecedente($this);
    $ines = $s['ines'];
    ilpfFineDiInes($this, $s, $ines);

    // Il presupposto: come il rinnovo, Ines 12 × 3000 = 36000 (sei a giornale, 18000), Ugo 12 × 7000 = 84000; nessuna riga del
    // riparto; la riga di Ines chiusa al 30/6; il piano è fermo.
    expect(DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->count())->toBe(0)
        ->and(ilpfQuote($s['piano'], $ines))->toBe([12, 36000])
        ->and(ilpfQuote($s['piano'], $ines, soloAGiornale: true))->toBe([6, 18000])
        ->and(ilpfQuote($s['piano'], $s['ugo']))->toBe([12, 84000])
        ->and(substr((string) DB::table('anagrafica_immobile')->where('anagrafica_id', $ines->id)->where('immobile_id', $s['unita']->id)->value('data_fine'), 0, 10))->toBe('2026-06-30')
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $frase = implode(' ', ilpfAnteprima($this, $s)['rate']['frasi']);

    // Dal 1/7 nelle rate di Ines restano 6 bozze, 6 × 3000 = 18000: i giorni dopo la sua uscita (decisione 32), non un rimborso fra due
    // conduttori. Oggi la frase dice «il rimborso di Luca Ferri a chi le paga».
    expect($frase)->toContain('È stato generato da una versione precedente, senza riparto registrato: dopo il passaggio il prospetto degli oneri accessori lo lascia fuori.')
        ->and($frase)->toContain('La parte a carico dell\'inquilino dal 1 luglio 2026 resta nelle rate di Ines Galli: chi paga i giorni dopo la sua uscita lo decide l\'amministratore.')
        ->and($frase)->not->toContain('rimborso di Luca');

    // Il denaro (controllo): registrato l'inizio, il riparto ricostruito ha Luca e le rate sono di Ines: il prospetto esclude il piano e
    // non ha conduttori; le quote non si muovono, Luca niente.
    ilpfRegistra($this, $s);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    expect(collect($p['piani_esclusi'])->pluck('nome')->all())->toBe(['Preventivo 2026'])
        ->and($p['conduttori'])->toBe([])
        ->and(ilpfQuote($s['piano'], $ines))->toBe([12, 36000])
        ->and(ilpfQuote($s['piano'], $s['luca']))->toBe([0, 0]);
});

// Terzo giro, rilievo «Piano della 1.10 generato con l'inquilino» (testi, basso), scenario Q2e: il coinquilino, Ines al 50 % resta e
// Luca entra al 50 %. NON copre: l'informazione del cancello, le cifre di un piano generato dopo.
it('Piano della 1.10 con l\'inquilino, coinquilino (Q2e) — «Preventivo 2026» generato con Ines al 50 %, senza riparto, fermo; Luca entra il 1/7 al 50 % e Ines resta: la frase dice che la parte resta nelle rate di Ines, che resta inquilino, e che un piano generato dopo dividerà quelle voci fra Ines e Luca; registrato, il prospetto lo esclude', function () {
    $s = ilpfInesVersionePrecedente($this, 50);
    $ines = $s['ines'];

    // Il presupposto: Ines è la sola inquilina alla generazione, tutta la parte inquilino è sua, 12 × 3000 = 36000 (sei a giornale,
    // 18000); Ugo 12 × 7000 = 84000; nessuna riga del riparto; il piano è fermo.
    expect(DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->count())->toBe(0)
        ->and(ilpfQuote($s['piano'], $ines))->toBe([12, 36000])
        ->and(ilpfQuote($s['piano'], $ines, soloAGiornale: true))->toBe([6, 18000])
        ->and(ilpfQuote($s['piano'], $s['ugo']))->toBe([12, 84000])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $frase = implode(' ', ilpfAnteprimaDi($this, $s, $s['luca'], 50)['rate']['frasi']);

    // Ines non esce: dal 1/7 le sue 6 bozze (18000) restano sue, e un piano generato dopo le dividerebbe fra lei e Luca. Oggi la frase
    // dice «intesterà quelle voci a Luca Ferri».
    expect($frase)->toContain('resta nelle rate di Ines Galli, che resta inquilino')
        ->and($frase)->toContain('Un piano generato dopo dividerà quelle voci fra Ines Galli e Luca Ferri, per quote e giorni.');

    // Il denaro (controllo): registrato, il riparto ricostruito ha anche Luca e le rate no: il prospetto esclude il piano; Luca niente.
    ilpfRegistraDi($this, $s, $s['luca'], 50);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    expect(collect($p['piani_esclusi'])->pluck('nome')->all())->toBe(['Preventivo 2026'])
        ->and(ilpfQuote($s['piano'], $s['luca']))->toBe([0, 0]);
});

// Terzo giro, rilievo «Il piano che ha righe solo sulla pertinenza resta senza il nome dell'unità» (testi, basso), scenario Q1d. NON
// copre: un piano con righe solo su due pertinenze, l'unità principale con righe e la stessa storia del box.
it('Pertinenze, piano solo sul box (Q1d) — Ines inquilina del solo Interno 1, «Rate autorimessa 2026» tutto all\'inquilino sul solo Box 12 e fermo; fine di Ines il 1/7 e inizio di Luca il 1/7 con il box spuntato: la frase e l\'informazione dell\'autorimessa nominano il box e il prospetto di quell\'unità; il prospetto del box dà a Luca € 184,00 dell\'autorimessa', function () {
    $s = ilpfConBox($this, inesSullUnita: true, inesSulBox: false);
    $ines = $s['ines'];
    $autorimessa = ilpfAutorimessa($s);
    ilpfEmettiFino($this, $s, $s['piano'], '2026-06-30');
    ilpfEmettiFino($this, $s, $autorimessa, '2026-06-30');
    ilpfFineDiInesSu($this, $s, $ines, $s['unita']);

    // Il presupposto. Autorimessa: nessuna riga sull'Interno 1; sul box la riga di ripiego di Ugo, 36500 (tutto all'inquilino, nessun
    // inquilino sul box alla generazione); dodici quote di Ugo, 36500 in tutto, sei a giornale. Fermi tutti e due i piani.
    expect(ilpfRighe($autorimessa, $s['unita']->id))->toBe([])
        ->and(ilpfRighe($autorimessa, $s['box']->id))->toBe(['Ugo Rinaldi|proprietario|2026-01-01→2026-12-31|→||36500'])
        ->and(ilpfQuote($autorimessa, $s['ugo']))->toBe([12, 36500])
        ->and(ilpfQuote($autorimessa, soloAGiornale: true)[0])->toBe(6)
        ->and(collect(PianoRate::immutabiliFra([$s['piano']->id, $autorimessa->id]))->sort()->values()->all())->toBe([$s['piano']->id, $autorimessa->id]);

    $anteprima = ilpfAnteprimaConBox($this, $s);
    $frase = ilpfFraseDelPiano($anteprima, 'Rate autorimessa 2026');
    $informazione = ilpfInformazione($anteprima['cancello'], 'Rate autorimessa 2026');
    $box = $s['box']->etichetta_estesa;

    // Il rimborso dell'autorimessa sta solo nel prospetto del box: in quello dell'Interno 1 il piano non ha voci (sotto). Oggi la frase
    // dice «con il prospetto degli oneri accessori» senza dire quale.
    expect($frase)->toContain('Il piano «Rate autorimessa 2026» non si ricalcola più: anche le rate ancora da emettere restano a chi le ha.')
        ->and($frase)->toContain(sprintf('Nell\'unità «%s» la parte a carico dell\'inquilino dal 1 luglio 2026 la rimborsa Luca Ferri a chi le paga, con il prospetto degli oneri accessori di quell\'unità.', $box))
        ->and($informazione)->toContain(sprintf('nell\'unità «%s»', $box))
        ->and($informazione)->toContain('di quell\'unità');

    // Il denaro (controllo). Box, autorimessa: Luca dal 1/7 al 31/12, 184 giorni, 36500 × 184/365 = 18400, pagati da Ugo; senza
    // conduttore i 181 giorni, 36500 × 181/365 = 18100 (18400 + 18100 = 36500). Box, preventivo (come Pertinenze (a)): 1815 a Luca, 1785
    // senza conduttore. Luca sul box: 1815 + 18400 = 20215 da rimborsare. Interno 1: solo Ines, 365 giorni nelle sue rate, 32400.
    ilpfRegistraConBox($this, $s);
    $pBox = app(ProspettoOneriAccessori::class)->calcola($s['box'], $s['e']);
    $pUnita = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    $lucaBox = ilpfConduttore($pBox, 'Luca Ferri');
    expect(ilpfVoci(['voci' => collect($lucaBox['voci'])->where('piano', 'Rate autorimessa 2026')->values()->all()]))->toBe(['184|18400|proprietario|Ugo Rinaldi'])
        ->and((int) collect($pBox['senza_conduttore']['voci'])->where('piano', 'Rate autorimessa 2026')->sum('importo'))->toBe(18100)
        ->and($lucaBox['da_rimborsare'])->toBe(20215)
        // Nel prospetto dell'Interno 1 l'autorimessa non ha voci, né di conduttori né senza conduttore.
        ->and(collect($pUnita['conduttori'])->flatMap(fn ($c) => $c['voci'])->merge($pUnita['senza_conduttore']['voci'])->where('piano', 'Rate autorimessa 2026')->values()->all())->toBe([])
        ->and(collect($pUnita['conduttori'])->pluck('nome')->all())->toBe(['Ines Galli'])
        ->and(ilpfVoci(ilpfConduttore($pUnita, 'Ines Galli')))->toBe(['365|32400|rate|']);
});

// Terzo giro, rilievo «Tratti per capitolo: dal 1 luglio al 31 ottobre quando i giorni veri dal 1/7 sono 17» (testi, basso), scenario
// Q3a. NON copre: il ripiego senza altre parti con i giorni che partono dopo l'inizio, la parte «propri» (Q3b, sotto).
it('DL3, tratti per capitolo fino al 31/10 (Q3a) — «Spese generali» con la competenza 1/1–15/4 e 15/10–31/12, Ines censita fino al 31/10, fine il 1/7 e inizio di Luca il 1/7 sul piano fermo: la parte resta nelle rate di Ines dal 15 al 31 ottobre, non dal 1 luglio al 31 ottobre; il prospetto dà a Luca € 120,00 da rimborsare a Ugo', function () {
    $s = ilpfTrattiFinoAOttobre($this);
    $ines = $s['ines'];

    // Il presupposto. 183 giorni di competenza (105 + 78). Ines dal 1/1 al 31/10: 105 + 17 (15/10–31/10) = 122 giorni, 36000 × 122/183
    // = 24000; Ugo per ripiego dal 1/11: 30 + 31 = 61 giorni, 36000 × 61/183 = 12000 (24000 + 12000 = 36000). Il piano è fermo.
    expect(ilpfRighe($s['piano']))->toBe([
        'Ines Galli|inquilino|2026-01-01→2026-12-31|2026-01-01→2026-10-31|122|24000',
        'Ugo Rinaldi|proprietario|2026-01-01→2026-12-31|2026-11-01→2026-12-31|61|12000',
    ])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprima($this, $s);
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = ilpfInformazione($anteprima['cancello'], 'non si ricalcola più');

    // Dal 1/7 nelle rate di Ines ci sono solo i 17 giorni dal 15/10 al 31/10, 36000 × 17/183 = 3344,26 → € 33,44; i 123 giorni di
    // calendario dal 1/7 al 31/10 varrebbero 36000 × 123/365 = 12131,51 → € 121,32: un saldo fatto su quelli sbaglia.
    expect($frase)->toContain('La parte a carico dell\'inquilino dal 15 al 31 ottobre 2026 resta nelle rate di Ines Galli')
        ->and($frase)->not->toContain('dal 1 luglio al 31 ottobre')
        ->and($frase)->toContain('Per gli altri giorni la rimborsa Luca Ferri a chi le paga, con il prospetto degli oneri accessori.')
        ->and($informazione)->not->toContain('dal 1 luglio al 31 ottobre');

    // Il denaro (controllo): registrato l'inizio, Ines ha i suoi 122 giorni nelle sue rate, 24000; Luca i 61 dal 1/11, 12000, pagati da
    // Ugo e da rimborsargli.
    ilpfRegistra($this, $s);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']);
    $diLuca = ilpfConduttore($p, 'Luca Ferri');
    expect(ilpfVoci(ilpfConduttore($p, 'Ines Galli')))->toBe(['122|24000|rate|'])
        ->and(ilpfVoci($diLuca))->toBe(['61|12000|proprietario|Ugo Rinaldi'])
        ->and($diLuca['da_rimborsare'])->toBe(12000);
});

// Terzo giro, rilievo «Tratti per capitolo: dal 1 luglio al 31 ottobre» (testi, basso), scenario Q3b: il rinnovo. NON copre: il
// rinnovo con una quota diversa, il ripiego senza altre parti.
it('DL3, tratti per capitolo fino al 31/10, rinnovo (Q3b) — lo stesso piano, fine di Ines il 1/7 e rinnovo di Ines il 1/7: dal 15 al 31 ottobre la parte è già nelle rate di Ines, per gli altri giorni la rimborsa lei; il prospetto le dà 24000 nelle sue rate e 12000 da rimborsare a Ugo', function () {
    $s = ilpfTrattiFinoAOttobre($this);
    $ines = $s['ines'];

    // Il presupposto: le righe di Q3a, Ines 122 giorni per 24000 e Ugo 61 per 12000; il piano è fermo.
    expect(ilpfRighe($s['piano']))->toBe([
        'Ines Galli|inquilino|2026-01-01→2026-12-31|2026-01-01→2026-10-31|122|24000',
        'Ugo Rinaldi|proprietario|2026-01-01→2026-12-31|2026-11-01→2026-12-31|61|12000',
    ])
        ->and(PianoRate::immutabiliFra([$s['piano']->id]))->toBe([$s['piano']->id]);

    $anteprima = ilpfAnteprimaDi($this, $s, $ines);
    $frase = implode(' ', $anteprima['rate']['frasi']);
    $informazione = ilpfInformazione($anteprima['cancello'], 'non si ricalcola più');

    // Dal 1/7 nelle sue rate ci sono i 17 giorni dal 15/10 (3344,26 → € 33,44), non 123 giorni di calendario.
    expect($frase)->toContain('dal 15 al 31 ottobre 2026 è già nelle rate di Ines Galli')
        ->and($frase)->not->toContain('dal 1 luglio al 31 ottobre')
        ->and($frase)->toContain('Per gli altri giorni la rimborsa Ines Galli')
        ->and($informazione)->not->toContain('dal 1 luglio al 31 ottobre');

    // Il denaro (controllo): registrato il rinnovo, Ines ha 36000 in tutto, 24000 nelle sue rate e 12000 da rimborsare a Ugo
    // (24000 + 12000 = 36000).
    ilpfRegistraDi($this, $s, $ines);
    $diInes = ilpfConduttore(app(ProspettoOneriAccessori::class)->calcola($s['unita'], $s['e']), 'Ines Galli');
    expect($diInes['totale'])->toBe(36000)
        ->and($diInes['nelle_sue_rate'])->toBe(24000)
        ->and($diInes['da_rimborsare'])->toBe(12000)
        ->and(collect(ilpfVoci($diInes))->sort()->values()->all())->toBe(['122|24000|rate|', '61|12000|proprietario|Ugo Rinaldi']);
});
