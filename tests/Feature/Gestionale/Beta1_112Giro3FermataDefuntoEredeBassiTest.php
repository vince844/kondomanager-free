<?php

/**
 * La frase sugli stessi saldi (Coda 463, decisione 188.1) — singolare e plurale secondo le righe, e il verso della somma delle parti.
 *
 * Con due defunti sulla stessa unità, chi senza righe legate ha un saldo non legato che non è la sua parte in nessuna voce è «incerto» in tutte: nelle voci dopo
 * la prima la frase dice che sono gli stessi saldi, che comprendono le sue parti solo se le comprendono insieme (con la somma, nel verso del Wallet) e che per quella
 * che non comprendono serve il saldo legato. Con una riga sola la frase dice «Il saldo non legato … è lo stesso … comprende», come quella della parte in un'altra voce;
 * con più righe «I saldi non legati … sono gli stessi … comprendono».
 *
 * Fonte delle cifre, la regola a mano. Spesa «Spese generali» € 3.650,00 (365000) sull'unità A da 1000 millesimi, 1000 centesimi al giorno per il 100 %; muoiono
 * il 1/5/2026 (120 giorni su 365).
 * - Due defunti: Ugo 60 % e Ada 40 %, eredi Anna 36 e Bruno 24 di Ugo, Anna 24 e Bruno 16 di Ada. Ugo 0,6 × 120 × 1000 = 72000 e Ada 0,4 × 120 × 1000 = 48000; le parti
 *   di Anna 72000 × 36 / 60 = 43200 e 48000 × 24 / 40 = 28800, insieme 72000.
 * - Tre defunti: quattro proprietari al 25 % (Ugo, Ada, Pia ed Eva), muoiono i primi tre, eredi Anna 15 e Bruno 10 della quota di ognuno: 0,25 × 120 × 1000 = 30000
 *   a ognuno, le parti di Anna 18000 (30000 × 15 / 25), di Bruno 12000; le tre parti di Anna insieme 54000.
 * - Un riporto di € 50,00 (5000) non è la parte e fa incerta la riga di Anna: 72000 + 5000 = 77000, o 54000 + 5000 = 59000.
 * - Il verso: con un'entrata di € 7.300,00 accanto alla spesa il netto è −365000 l'anno, −1000 al giorno per il 100 %: Ugo e Ada al 50 % −60000 ciascuno, le parti
 *   di Anna −30000 e −30000 (crediti), insieme −60000; la riga di Anna −60000 + 5000 = −55000 (un credito più il riporto a debito) non è la parte di nessuna.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    // Giugno 2027: il 2026 è chiuso, il 2027 è l'esercizio aperto, dove la porta del Wallet scrive.
    $this->travelTo(now()->setDate(2027, 6, 15)->setTime(10, 0));
});

/**
 * L'unità A da 1000 millesimi, «Spese generali» da € 3.650,00 e, se serve il verso a credito, «Affitto antenna» da € 7.300,00 di entrata; i titolari dal 2019; il
 * piano del 2026 non generato; le successioni del 1/5/2026 dalla rotta, con l'arretrato agli eredi.
 *
 * @param array<string, float> $titolari persona => quota
 * @param array<string, array<string, float>> $morti defunto => eredi
 * @return array{0: Beta51Scenario, 1: array<string, Subentro>}
 */
function g3bScenario($test, array $titolari, array $morti, int $entrata = 0): array
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000]);
    $es = [2026 => $s->e];
    $gestioni = [];
    foreach ([2026, 2027] as $anno) {
        $es[$anno] ??= Esercizio::create(['condominio_id' => $s->c->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
        $gestioni[] = app(CondominioService::class)->createDefaultGestione($s->c, $es[$anno])->id;
    }
    expect(array_unique($gestioni))->toHaveCount(1);
    $g = \App\Models\Gestione::findOrFail($gestioni[0]);
    $s->gestioni['Ordinaria'] = ['g' => $g, 'tipo' => 'ordinaria', 'pc' => PianoConto::create(['condominio_id' => $s->c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti'])];
    $s->voce('Ordinaria', 'Spese generali', 365000, ['Proprietà' => 100]);
    if ($entrata > 0) {
        $conto = Conto::create(['piano_conto_id' => $s->gestioni['Ordinaria']['pc']->id, 'nome' => 'Affitto antenna', 'tipo' => 'entrata', 'natura_spesa' => 'ordinaria', 'importo' => $entrata]);
        $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $s->tabelle['Proprietà']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ($titolari as $chi => $quota) {
        $s->titolare('A', $chi, 'proprietario', $quota);
    }
    $es[2026]->update(['stato' => 'chiuso']);
    $s->e = $es[2026];
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', primaScadenza: '2026-01-05');
    $sub = [];
    foreach ($morti as $d => $eredi) {
        $sub[$d] = $s->passaggio('successione', 'A', $d, null, '2026-05-01', ['eredi' => $eredi, 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    }

    return [$s, $sub];
}

/** Una riga non legata dalla porta del Wallet, nell'esercizio aperto (il 2027). */
function g3bWallet($test, Beta51Scenario $s, string $chi, int $cents): void
{
    $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => $cents,
    ])->assertSessionHasNoErrors();
}

/** Una riga non legata nel 2026, l'esercizio del piano, come la scrive l'importatore (la porta del Wallet scrive solo nell'esercizio aperto). */
function g3bImportata(Beta51Scenario $s, string $chi, int $cents): void
{
    Saldo::create(['esercizio_id' => Esercizio::where('condominio_id', $s->c->id)->where('nome', 'Esercizio 2026')->value('id'), 'condominio_id' => $s->c->id,
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id,
        'saldo_iniziale' => $cents, 'origine' => 'importato', 'is_applicato' => false]);
}

/** Le frasi delle voci dei defunti, per nome del defunto. @return array<string, string> */
function g3bFrasi($test, Beta51Scenario $s): array
{
    $r = $test->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $s->piano('Preventivo 2026')]), []);

    return collect($r->getSession()->get('destinatari_warning') ?? [])->where('motivo', 'defunto_nel_piano')->mapWithKeys(fn (array $v) => [$v['defunto']['nome'] => $v['frase']])->all();
}

it('Coda 463, singolare — una riga non legata di Anna (€ 770,00: le sue due parti € 720,00 e un riporto di € 50,00): la voce di Ada dice «Il saldo non legato … è lo stesso … comprende»', function () {
    [$s] = g3bScenario($this, ['ugo' => 60, 'ada' => 40], ['ugo' => ['anna' => 36, 'bruno' => 24], 'ada' => ['anna' => 24, 'bruno' => 16]]);
    // Le parti di Anna 43200 e 28800, insieme 72000; la riga 72000 + 5000 = 77000.
    g3bWallet($this, $s, 'anna', 72000 + 5000);

    $frasi = g3bFrasi($this, $s);

    expect($frasi['Ada'])->toEndWith(' Il saldo non legato di Anna è lo stesso anche per Ugo: comprende la sua parte per Ugo e per Ada solo se comprende le due parti insieme, € 720,00 a debito; se ne comprende una sola, per l\'altra serve il saldo legato.')
        ->and($frasi['Ada'])->not->toContain('I saldi non legati di Anna sono gli stessi');
    // La prima voce non ha la frase sugli stessi saldi: è la domanda di partenza.
    expect($frasi['Ugo'])->not->toContain('sono gli stessi')->not->toContain('è lo stesso');
});

it('Coda 463, plurale — due righe non legate di Anna (nel 2026 e nel 2027, € 770,00 in tutto): la voce di Ada dice «I saldi non legati … sono gli stessi … comprendono», come prima', function () {
    [$s] = g3bScenario($this, ['ugo' => 60, 'ada' => 40], ['ugo' => ['anna' => 36, 'bruno' => 24], 'ada' => ['anna' => 24, 'bruno' => 16]]);
    // Le due parti di Anna insieme 43200 + 28800 = 72000; due righe che sommano 72000 + 5000: 40000 nel 2027 e 37000 nel 2026.
    g3bWallet($this, $s, 'anna', 40000);
    g3bImportata($s, 'anna', 37000);

    $frasi = g3bFrasi($this, $s);

    // La riga del 2026 sta fuori dal Wallet (che mostra un solo esercizio aperto): la frase lo dice in fondo, dopo quella degli stessi saldi.
    expect($frasi['Ada'])->toContain(' I saldi non legati di Anna sono gli stessi anche per Ugo: comprendono la sua parte per Ugo e per Ada solo se comprendono le due parti insieme, € 720,00 a debito; se ne comprendono una sola, per l\'altra serve il saldo legato.')
        ->and($frasi['Ada'])->not->toContain('Il saldo non legato di Anna è lo stesso');
});

it('Coda 463, singolare con tre voci — una riga non legata di Anna (€ 590,00: le sue tre parti € 540,00 e un riporto di € 50,00): le voci di Ada e di Pia dicono «le comprende tutte insieme» e «per ogni parte che non comprende»', function () {
    [$s] = g3bScenario($this, ['ugo' => 25, 'ada' => 25, 'pia' => 25, 'eva' => 25], ['ugo' => ['anna' => 15, 'bruno' => 10], 'ada' => ['anna' => 15, 'bruno' => 10], 'pia' => ['anna' => 15, 'bruno' => 10]]);
    // 30000 a ognuno dei tre; le parti di Anna 30000 × 15 / 25 = 18000, in tutto 54000.
    g3bWallet($this, $s, 'anna', 54000 + 5000);

    $frasi = g3bFrasi($this, $s);

    expect($frasi['Ada'])->toEndWith(' Il saldo non legato di Anna è lo stesso anche per Ugo e Pia: comprende la sua parte per Ugo, per Ada e per Pia solo se le comprende tutte insieme, € 540,00 a debito; per ogni parte che non comprende serve il saldo legato.')
        ->and($frasi['Pia'])->toEndWith(' Il saldo non legato di Anna è lo stesso anche per Ugo e Ada: comprende la sua parte per Ugo, per Ada e per Pia solo se le comprende tutte insieme, € 540,00 a debito; per ogni parte che non comprende serve il saldo legato.');
});

it('Coda 463, il verso — con le parti a credito (un\'entrata che supera la spesa) la somma delle parti di Anna nella frase sugli stessi saldi è «€ 600,00 a credito», non a debito', function () {
    [$s] = g3bScenario($this, ['ugo' => 50, 'ada' => 50], ['ugo' => ['anna' => 25, 'bruno' => 25], 'ada' => ['anna' => 25, 'bruno' => 25]], entrata: 730000);
    // Netto −1000 al giorno per il 100 %: Ugo −60000 e Ada −60000; le parti di Anna −30000 e −30000, insieme −60000. La sua riga: −60000 + 5000 = −55000.
    g3bWallet($this, $s, 'anna', -60000 + 5000);

    $frasi = g3bFrasi($this, $s);

    expect($frasi['Ada'])->toEndWith(' Il saldo non legato di Anna è lo stesso anche per Ugo: comprende la sua parte per Ugo e per Ada solo se comprende le due parti insieme, € 600,00 a credito; se ne comprende una sola, per l\'altra serve il saldo legato.');
});
