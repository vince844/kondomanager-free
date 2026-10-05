<?php

/**
 * 1.11.0-beta.43: le catene di passaggi nel conguaglio, con le cifre fatte a mano.
 *
 * Una catena è una serie di passaggi sulla stessa unità: chi esce oggi ha avuto la sua quota da un predecessore, e le quote
 * già emesse sono ancora a nome del predecessore. Il conguaglio deve portare a chi entra solo ciò che è arrivato a chi esce.
 *
 * Cosa copre (decisione 59 del registro del subentro):
 * - **DV4**: il predecessore conta solo sulle unità dove ha ceduto qualcosa a chi esce; sul box che ha tenuto, le sue quote
 *   restano sue (prima chi comprava l'appartamento con il box pagava anche i giorni della metà del box rimasta all'altro);
 * - **U3**: due passaggi della stessa persona lo stesso giorno contano nell'ordine di registrazione; il secondo non porta
 *   la quota già ceduta con il primo (prima chi comprava la metà piena pagava anche i giorni della metà nuda già venduta);
 * - **U4**: nell'estinzione dell'usufrutto con il box, chi entra sul box è il nudo del box, che può non essere quello
 *   dell'appartamento (prima la coppia del box andava al nudo dell'appartamento, che sul box non ha niente);
 * - **DV1**: nell'ultimo anello di una catena le quote hanno più intestatari; la parte di chi entra si arrotonda una volta
 *   per piano e unità, con i resti maggiori, e non una volta per intestatario (prima un centesimo in più a chi entra).
 *
 * Decisione 56, la genealogia della quota: ogni riga di riparto sa da quale riga di titolarità viene (decisione 55), e il
 * conguaglio la segue nei passaggi fino a chi esce. Una forma per test, con le cifre a mano e, dove la costituzione o la
 * riserva le distinguono, i due versi della scelta sull'ordinaria (la legge e «come dice ogni voce»):
 * - **U2** (tre casi) e **U5**: le catene che non si fermavano e sbagliavano in silenzio;
 * - **DV3**, **R4**, **R1**, **R2**, **R7**, **R3**, **R6**: le catene che nella .41 e nella .42 si fermavano («le quote sono
 *   passate per più strade»); R6 anche con la coppia dell'estinzione divisa per nudo. M2-1 è in `QuotaCheEsceTest`;
 * - **la parte già ceduta**: la frase dice che chi esce l'ha ceduta con un passaggio precedente, non che la tiene;
 * - **decisione 55**: sui piani di prima, senza legame, il legame si deduce al volo dove è unico (stesse cifre, mai scritto);
 *   dove non lo è, sulla catena il conguaglio si ferma e dice perché; la deduzione da sola (la correzione dell'addebito diretto
 *   senza tratto, le righe gemelle);
 * - **le letture**: la genealogia legge righe e passaggi una volta per unità, qualunque sia la lunghezza della catena;
 * - **decisione 60**: un passaggio della .41 o della .42 che ha preso il piano senza scrivere una coppia sull'unità (lì, su una
 *   catena, il conguaglio si fermava) ferma la quota che lo attraversa cambiando persona; con la coppia, registrato dalla .43,
 *   senza quel piano, o con la quota che resta alla stessa persona (la riserva con la legge), si segue.
 *
 * Cosa NON copre: le fermate che restano (piano senza righe di riparto, quote della 1.7.x, quote mai passate: i loro test sono
 * in `QuotaCheEsceTest`, `ConguaglioQuoteSenzaComposizioneTest`, `StatoDelPianoDopoPassaggioTest`, `RiservaUsufruttoTest`);
 * la catena con la straordinaria, l'addebito diretto e le pertinenze, che la genealogia segue con le stesse regole ma che qui
 * non hanno una forma con le cifre; la griglia S3E, che è in `InvariantiPassaggiTest`.
 *
 * Il netto di una persona su un'unità è la quota pura delle quote a suo nome più le righe dei conguagli: è ciò che paga
 * dell'anno, e deve coincidere con i suoi giorni da titolare.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo('Accesso pannello amministratore');
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Una persona del condominio dello scenario. */
function cdcPersona(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower(str_replace(' ', '.', $nome)) . "{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'CDCPERSONA' . str_pad((string) $n, 6, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** Una riga di titolarità censita dal 2019. */
function cdcTitolare(Immobile $unita, Anagrafica $p, string $ruolo, float $quota): int
{
    return DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
}

/** La riga in corso di una persona su un'unità, in un ruolo. */
function cdcRiga(Immobile $unita, Anagrafica $p, string $ruolo = 'proprietario'): int
{
    return (int) DB::table('anagrafica_immobile')->where('immobile_id', $unita->id)->where('anagrafica_id', $p->id)->where('tipologia', $ruolo)
        ->whereNull('data_fine')->orderByDesc('id')->value('id');
}

/** Registra un passaggio dalla rotta vera, con il cancello letto. */
function cdcPassa($test, array $s, Immobile $unita, array $dati): void
{
    $dati += ['ho_letto' => true, 'nota_cancello' => 'Rogito letto, passaggio di prova della catena'];
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $unita]), $dati)->assertSessionHasNoErrors();
}

/**
 * Il netto di ciascuno su un'unità: quota pura delle quote a suo nome, più le righe dei conguagli.
 *
 * @return array<string, int> nome → centesimi, senza gli zeri
 */
function cdcNetti(array $s, Immobile $unita): array
{
    $netti = [];
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate_quote.immobile_id', $unita->id)->get(['rate_quote.anagrafica_id', 'rate_quote.importo', 'rate_quote.regole_calcolo']);
    foreach ($quote as $q) {
        $pura = json_decode((string) $q->regole_calcolo, true)['importi']['quota_pura_gestione'] ?? $q->importo;
        $netti[(int) $q->anagrafica_id] = ($netti[(int) $q->anagrafica_id] ?? 0) + (int) $pura;
    }
    foreach (DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $unita->id)->get() as $r) {
        $netti[(int) $r->anagrafica_id] = ($netti[(int) $r->anagrafica_id] ?? 0) + (int) $r->saldo_iniziale;
    }
    $nomi = Anagrafica::whereIn('id', array_keys($netti))->pluck('nome', 'id');
    $out = [];
    foreach ($netti as $id => $c) {
        if ($c !== 0) {
            $out[$nomi[$id]] = $c;
        }
    }
    ksort($out);

    return $out;
}

/** Genera il piano dello scenario dopo aver sistemato titolari e tabella. */
function cdcGenera(array $s): void
{
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
}

it('DV4 — Bice vende a Ugo la sua metà dell\'appartamento ma non del box; Ugo vende l\'appartamento con il box a Elsa: sul box la metà di Bice resta sua, e Elsa paga solo i giorni della metà di Ugo', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $ugo = $s['v'];
    $elsa = $s['a'];
    $bice = cdcPersona($s, 'Bice Seconda');
    // Appartamento 900 millesimi e box 100, metà ciascuno di Ugo e di Bice. Voce € 1.200,00: il box vale 12000.
    $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
    DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->update(['valore' => 900.0]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $box->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    cdcTitolare($s['unita'], $bice, 'proprietario', 50);
    cdcTitolare($box, $ugo, 'proprietario', 50);
    cdcTitolare($box, $bice, 'proprietario', 50);
    cdcGenera($s);
    ruEmetti($s, '2026-02-28');

    // Il 1/3 Bice vende a Ugo la sua metà dell'appartamento, senza il box.
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $bice), $ugo, '2026-03-01', 50));
    ruEmetti($s, '2026-04-30');
    // Il 1/5 Ugo vende a Elsa l'appartamento, con la sua metà del box.
    $quotaUgo = (float) DB::table('anagrafica_immobile')->where('id', cdcRiga($s['unita'], $ugo))->value('quota');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $ugo), $elsa, '2026-05-01', $quotaUgo, [$box->id]));

    // A mano, sul box: Bice resta proprietaria della sua metà tutto l'anno, 6000. La metà di Ugo, 6000: 120 giorni a lui
    // (6000 × 120/365 = 1972,60 → 1973) e 245 a Elsa (4027,40 → 4027). Prima Elsa pagava anche i giorni della metà di Bice:
    // coppia di 4054 sul box, Elsa 8054 e Ugo −2054.
    expect(cdcNetti($s, $box))->toBe(['Acquirente Elsa' => 4027, 'Bice Seconda' => 6000, 'Venditore Ugo' => 1973]);
});

it('U3 — Ugo, pieno di metà e nudo dell\'altra (Mara usufruttuaria), vende il 30/9 la nuda a Elsa e la metà piena a Nora: ciascuno paga i giorni della sua metà, in tutti e due gli ordini di registrazione', function (bool $primaLaNuda) {
    $s = ruScenario('prima_rata', 0, genera: false);
    $ugo = $s['v'];
    $elsa = $s['a'];
    $nora = cdcPersona($s, 'Nora Terza');
    $mara = cdcPersona($s, 'Mara Usufruttuaria');
    // Voce € 1.200,00 sul «Proprietario»: la paga il capitale, cioè Ugo per la metà piena e per la metà nuda.
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    cdcTitolare($s['unita'], $ugo, 'nuda_proprietario', 50);
    cdcTitolare($s['unita'], $mara, 'usufruttuario', 50);
    cdcGenera($s);
    ruEmetti($s, '2026-06-13');

    $nuda = fn () => cdcPassa($this, $s, $s['unita'], ruPassaggio('nuda', cdcRiga($s['unita'], $ugo, 'nuda_proprietario'), $elsa, '2026-09-30', 50));
    $piena = fn () => cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $ugo), $nora, '2026-09-30', 50));
    $primaLaNuda ? [$nuda(), $piena()] : [$piena(), $nuda()];

    // A mano: dal 30/9 (93 giorni) ogni metà vale 60000 × 93/365 = 15287,67 → 15288 a Elsa e 15288 a Nora; Ugo 120000 −
    // 30576 = 89424. Prima, registrata per prima la nuda, la vendita della metà piena portava a Nora anche la metà nuda: 30576.
    expect(cdcNetti($s, $s['unita']))->toBe(['Acquirente Elsa' => 15288, 'Nora Terza' => 15288, 'Venditore Ugo' => 89424]);
})->with(['prima la nuda' => true, 'prima la piena' => false]);

it('U4 — Ugo usufruttuario e Bice nuda dell\'appartamento e del box; il 1/5 Bice vende a Elsa la nuda dell\'appartamento, non del box; il 1/9 l\'usufrutto di Ugo si estingue con il box: sul box la coppia va a Bice, non a Elsa', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $ugo = $s['v'];
    $elsa = $s['a'];
    $bice = cdcPersona($s, 'Bice Nuda');
    $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    $tabellaId = (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id');
    DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->update(['valore' => 900.0]);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabellaId, 'immobile_id' => $box->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    // Voce € 1.200,00 sull'«Usufruttuario»: appartamento 108000, box 12000, tutte e due di Ugo finché l'usufrutto dura.
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    cdcTitolare($s['unita'], $bice, 'nuda_proprietario', 100);
    cdcTitolare($box, $ugo, 'usufruttuario', 100);
    cdcTitolare($box, $bice, 'nuda_proprietario', 100);
    cdcGenera($s);
    ruEmetti($s, '2026-03-31');

    cdcPassa($this, $s, $s['unita'], ruPassaggio('nuda', cdcRiga($s['unita'], $bice, 'nuda_proprietario'), $elsa, '2026-05-01', 100));
    $estinzione = ruPassaggio('estinzione', cdcRiga($s['unita'], $ugo, 'usufruttuario'), null, '2026-09-01', 100, [$box->id]);
    $an = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $estinzione)->assertOk()->json();
    cdcPassa($this, $s, $s['unita'], $estinzione);

    // A mano, dal 1/9 (122 giorni): box 12000 × 122/365 = 4010,96 → 4011 a Bice, piena del box; Ugo 7989. Appartamento
    // 108000 × 122/365 = 36098,63 → 36099 a Elsa, piena dell'appartamento; Ugo 71901. Prima la coppia del box andava a Elsa.
    expect(cdcNetti($s, $box))->toBe(['Bice Nuda' => 4011, 'Venditore Ugo' => 7989])
        ->and(cdcNetti($s, $s['unita']))->toBe(['Acquirente Elsa' => 36099, 'Venditore Ugo' => 71901])
        // Il pannello dice chi entra su ciascuna unità.
        ->and(implode(' ', $an['rate']['conguaglio']['frasi']))->toContain('debito € 40,11 a Bice Nuda')->toContain('debito € 360,99 a Acquirente Elsa');
});

it('DV1 — Ugo vende a Elsa il 1/5, Elsa a Bruno il 1/7, Bruno a Carlo il 15/9, con le rate emesse fra un passaggio e l\'altro: ognuno paga i suoi giorni al centesimo', function () {
    $s = ruScenario('prima_rata', 0);
    $ugo = $s['v'];
    $elsa = $s['a'];
    $bruno = cdcPersona($s, 'Bruno Terzo');
    $carlo = cdcPersona($s, 'Carlo Quarto');
    ruEmetti($s, '2026-04-30');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $ugo), $elsa, '2026-05-01', 100));
    ruEmetti($s, '2026-06-30');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $elsa), $bruno, '2026-07-01', 100));
    ruEmetti($s, '2026-08-31');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $bruno), $carlo, '2026-09-15', 100));

    // A mano, su € 1.200,00: Ugo 1/1–30/4, 120 giorni (39452,05); Elsa 1/5–30/6, 61 (20054,79); Bruno 1/7–14/9, 76 (24986,30);
    // Carlo dal 15/9, 108 (35506,85). Resti maggiori: Elsa. Prima, nel terzo anello, le quote avevano tre intestatari (Ugo,
    // Elsa, Bruno) e la parte di Carlo si arrotondava su ciascuno: 11835,67 → 11836, 5917,83 → 5918, 17753,5 → 17754, cioè
    // 35508, e Bruno 24985.
    expect(cdcNetti($s, $s['unita']))->toBe(['Acquirente Elsa' => 20055, 'Bruno Terzo' => 24986, 'Carlo Quarto' => 35507, 'Venditore Ugo' => 39452]);
});

// --- Decisione 56: la genealogia della quota --------------------------------------------------------------------------------

/** L'anteprima di un passaggio dalla rotta vera, con il cancello letto. */
function cdcAnteprima($test, array $s, Immobile $unita, array $dati): array
{
    $dati += ['ho_letto' => true, 'nota_cancello' => 'Rogito letto, passaggio di prova della catena'];

    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $unita]), $dati)->assertOk()->json();
}

/** Il conguaglio dell'anteprima si ferma per una catena («le quote sono passate per più strade»), e con quale frase. */
function cdcFermo(array $anteprima): ?string
{
    return collect($anteprima['rate']['conguaglio']['non_risolte'] ?? [])->pluck('motivo')->first(fn ($m) => str_contains((string) $m, 'sono passate per più strade'));
}

/** L'importo della voce dello scenario (€ 1.200,00 di norma), prima di generare. */
function cdcVoce(array $s, int $cents): void
{
    $conto = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->value('conti.id');
    DB::table('conti')->where('id', $conto)->update(['importo' => $cents]);
}

/** Un passaggio registrato con la scelta sull'ordinaria data (la legge, `usufruttuario`, o `voce`). */
function cdcConScelta(array $dati, string $scelta): array
{
    return array_replace($dati, ['ordinaria_dopo_atto' => $scelta]);
}

it('U2 — Ugo nudo e Rita usufruttuaria, voce sull\'«Usufruttuario»; l\'usufrutto di Rita si estingue il 1/5, Ugo costituisce l\'usufrutto a Carlo il 1/7 e vende la nuda a Fede il 1/9: a Fede non passa niente, con la legge e «come dice ogni voce»', function (string $scelta) {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $ugo = $s['v'];
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'nuda_proprietario']);
    $rita = cdcPersona($s, 'Rita Usufruttuaria');
    $rigaRita = cdcTitolare($s['unita'], $rita, 'usufruttuario', 100);
    $carlo = cdcPersona($s, 'Carlo Usufruttuario');
    $fede = cdcPersona($s, 'Fede Compratrice');
    cdcGenera($s);
    ruEmetti($s, '2026-04-30');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', $rigaRita, null, '2026-05-01', 100));
    cdcPassa($this, $s, $s['unita'], cdcConScelta(ruPassaggio('costituzione', cdcRiga($s['unita'], $ugo), $carlo, '2026-07-01', 100), $scelta));
    $nuda = ruPassaggio('nuda', cdcRiga($s['unita'], $ugo, 'nuda_proprietario'), $fede, '2026-09-01', 100);
    $an = cdcAnteprima($this, $s, $s['unita'], $nuda);
    cdcPassa($this, $s, $s['unita'], $nuda);

    // A mano, su € 1.200,00 sull'«Usufruttuario». Rita fino al 30/4, 120 giorni: le dodici quote sono sue (120000) e l'estinzione
    // dà a Ugo i 245 giorni dal 1/5, 120000 × 245/365 = 80547,95 → 80548. Carlo dal 1/7, 184 giorni: 60493,15 → 60493, dalla
    // costituzione (con la legge l'ordinaria è sua; con la voce anche, perché la voce è dell'usufruttuario). Fede compra la nuda
    // proprietà: la riga di Rita è arrivata a Ugo con l'estinzione e passata a Carlo con la costituzione, e alla nuda non porta
    // niente. Rita 120000 − 80548 = 39452, Ugo 80548 − 60493 = 20055, Carlo 60493, Fede 0. Prima la vendita della nuda faceva
    // pagare a Fede € 401,10 (122 giorni), senza fermarsi.
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe(['Carlo Usufruttuario' => 60493, 'Rita Usufruttuaria' => 39452, 'Venditore Ugo' => 20055]);
})->with(['con la legge' => 'usufruttuario', 'come dice ogni voce' => 'voce']);

it('U2, seconda forma — Ugo pieno di metà e usufruttuario dell\'altra, Mara nuda; l\'usufrutto di Ugo si estingue il 31/3, Mara costituisce l\'usufrutto a Elsa il 1/5 «come dice ogni voce» e vende la nuda a Carlo il 10/5: a Carlo non passa niente', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $ugo = $s['v'];
    $elsa = $s['a'];
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $rigaUsufrutto = cdcTitolare($s['unita'], $ugo, 'usufruttuario', 50);
    $mara = cdcPersona($s, 'Mara Nuda');
    cdcTitolare($s['unita'], $mara, 'nuda_proprietario', 50);
    $carlo = cdcPersona($s, 'Carlo Compratore');
    cdcGenera($s);
    ruEmetti($s, '2026-01-11');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', $rigaUsufrutto, null, '2026-03-31', 50));
    cdcPassa($this, $s, $s['unita'], cdcConScelta(ruPassaggio('costituzione', cdcRiga($s['unita'], $mara), $elsa, '2026-05-01', 50), 'voce'));
    $nuda = ruPassaggio('nuda', cdcRiga($s['unita'], $mara, 'nuda_proprietario'), $carlo, '2026-05-10', 50);
    $an = cdcAnteprima($this, $s, $s['unita'], $nuda);
    cdcPassa($this, $s, $s['unita'], $nuda);

    // A mano: le righe di Ugo sono due da € 600,00, il godimento della metà piena e l'usufrutto dell'altra. La metà piena è sua
    // tutto l'anno. L'usufrutto: a Mara dal 31/3, 276 giorni, 60000 × 276/365 = 45369,86 → 45370; a Elsa dal 1/5, 245 giorni,
    // 40273,97 → 40274. Carlo compra la nuda proprietà: niente. Ugo 120000 − 45370 = 74630, Mara 45370 − 40274 = 5096, Elsa
    // 40274. Prima Carlo pagava € 387,95 (236 giorni) di un godimento che è di Elsa.
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe(['Acquirente Elsa' => 40274, 'Mara Nuda' => 5096, 'Venditore Ugo' => 74630]);
});

it('U5 — Ugo usufruttuario e Rita nuda, voce da € 600,00 sul «Proprietario»; l\'usufrutto di Ugo si estingue il 15/4, Rita vende a Carlo con riserva d\'usufrutto il 5/5 e il suo usufrutto si estingue il 18/5: con la legge la voce passa a Carlo all\'estinzione; «come dice ogni voce» alla riserva', function (string $scelta, array $netti) {
    $s = ruScenario('prima_rata', 0, genera: false);
    cdcVoce($s, 60000);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    $rita = cdcPersona($s, 'Rita Nuda');
    cdcTitolare($s['unita'], $rita, 'nuda_proprietario', 100);
    $carlo = cdcPersona($s, 'Carlo Compratore');
    cdcGenera($s);
    ruEmetti($s, '2026-04-08');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', $s['rigaV'], null, '2026-04-15', 100));
    cdcPassa($this, $s, $s['unita'], cdcConScelta(ruPassaggio('riserva', cdcRiga($s['unita'], $rita), $carlo, '2026-05-05', 100), $scelta));
    $estinzione = ruPassaggio('estinzione', cdcRiga($s['unita'], $rita, 'usufruttuario'), null, '2026-05-18', 100);
    $an = cdcAnteprima($this, $s, $s['unita'], $estinzione);
    cdcPassa($this, $s, $s['unita'], $estinzione);

    // A mano: la voce sul «Proprietario», piano di gennaio con Rita nuda, è di Rita per tutto l'anno (60000). Con la legge la
    // riserva le lascia l'ordinaria, da usufruttuaria; all'estinzione del suo usufrutto, il 18/5, la riga torna a Carlo, pieno:
    // 228 giorni, 60000 × 228/365 = 37479,45 → 37479; Rita 22521. Prima la frase diceva che quella riga era della nuda proprietà
    // che Rita teneva, e niente passava. «Come dice ogni voce» la voce sul «Proprietario» passa con la riserva al nudo, dal 5/5:
    // 241 giorni, 39616,44 → 39616, e all'estinzione niente (è già di Carlo); Rita 20384.
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe($netti);
})->with([
    'con la legge' => ['usufruttuario', ['Carlo Compratore' => 37479, 'Rita Nuda' => 22521]],
    'come dice ogni voce' => ['voce', ['Carlo Compratore' => 39616, 'Rita Nuda' => 20384]],
]);

it('DV3 — Ugo vende a Bice il 1/3, Bice gli rivende l\'unità il 1/5, Ugo la vende a Carlo il 1/7, con le rate emesse fra un passaggio e l\'altro: il conguaglio non si ferma, e ognuno paga i suoi giorni', function () {
    $s = ruScenario('prima_rata', 0);
    $ugo = $s['v'];
    $bice = cdcPersona($s, 'Bice Seconda');
    $carlo = cdcPersona($s, 'Carlo Quarto');
    ruEmetti($s, '2026-02-28');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $ugo), $bice, '2026-03-01', 100));
    ruEmetti($s, '2026-04-30');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $bice), $ugo, '2026-05-01', 100));
    ruEmetti($s, '2026-06-30');
    $ultima = ruPassaggio('vendita', cdcRiga($s['unita'], $ugo), $carlo, '2026-07-01', 100);
    $an = cdcAnteprima($this, $s, $s['unita'], $ultima);
    cdcPassa($this, $s, $s['unita'], $ultima);

    // A mano, su € 1.200,00: Ugo 1/1–28/2 e 1/5–30/6, 59 + 61 = 120 giorni (39452,05); Bice 1/3–30/4, 61 (20054,79); Carlo dal
    // 1/7, 184 (60493,15). Resti maggiori: il centesimo a Bice. Le coppie: il 1/3 Bice 100603 meno le dieci bozze passate (603),
    // il 1/5 Ugo 80548 meno le otto bozze tornate (548), il 1/7 Carlo 60493 meno le sei bozze dal 5/7 (493). Prima l'ultimo
    // anello si fermava («Ugo ha ceduto una quota … e ne ha avuta un'altra»): le bozze restavano a Ugo e Carlo non pagava niente.
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe(['Bice Seconda' => 20055, 'Carlo Quarto' => 60493, 'Venditore Ugo' => 39452])
        ->and(DB::table('rate_quote')->where('anagrafica_id', $carlo->id)->count())->toBe(6);
});

/** L'unità mista S3 dei test: Ugo pieno di metà e usufruttuario dell'altra, Bice nuda di quella; voce sul ruolo dato, emesse fino al 31/3. */
function cdcS3(string $soggetto): array
{
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $s['rigaU'] = cdcTitolare($s['unita'], $s['v'], 'usufruttuario', 50);
    $s['bice'] = cdcPersona($s, 'Bice Nuda');
    cdcTitolare($s['unita'], $s['bice'], 'nuda_proprietario', 50);
    cdcGenera($s);
    ruEmetti($s, '2026-03-31');

    return $s;
}

it('R4 — S3 con la voce sull\'«Usufruttuario»: l\'usufrutto di Ugo si estingue il 1/4, Ugo ricompra da Bice la metà il 1/5 e vende tutto a Elsa il 1/9: a Elsa le due metà', function () {
    $s = cdcS3('usufruttuario');
    $ugo = $s['v'];
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', $s['rigaU'], null, '2026-04-01', 50));
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $s['bice']), $ugo, '2026-05-01', 50));
    $ultima = ruPassaggio('vendita', cdcRiga($s['unita'], $ugo), $s['a'], '2026-09-01', 100);
    $an = cdcAnteprima($this, $s, $s['unita'], $ultima);
    cdcPassa($this, $s, $s['unita'], $ultima);

    // A mano: le due righe di Ugo, € 600,00 ciascuna. L'usufrutto passa a Bice il 1/4 (60000 × 275/365 = 45205,48 → 45205) e torna
    // a Ugo il 1/5 (245 giorni, 40273,97 → 40274); il 1/9 Ugo vende tutto: le due righe arrivano a Elsa, 122 giorni ciascuna,
    // 2 × 20054,79 = 40109,59 → 40110, di cui € 400,00 con le quattro bozze dal 5/9 e € 1,10 con la coppia. Ugo 120000 − 45205 +
    // 40274 − 40110 = 74959, Bice 45205 − 40274 = 4931. Prima si fermava, e prima ancora Elsa pagava la sola metà piena (€ 200,55).
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe(['Acquirente Elsa' => 40110, 'Bice Nuda' => 4931, 'Venditore Ugo' => 74959]);
});

it('R1 — S3 con la voce sull\'«Usufruttuario»: Ugo vende la metà piena a Elsa il 1/4, il suo usufrutto si estingue il 1/6, Bice vende a Carlo il 1/9: a Carlo la sola metà dell\'usufrutto', function () {
    $s = cdcS3('usufruttuario');
    $ugo = $s['v'];
    $carlo = cdcPersona($s, 'Carlo Compratore');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-04-01', 50));
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', $s['rigaU'], null, '2026-06-01', 50));
    $ultima = ruPassaggio('vendita', cdcRiga($s['unita'], $s['bice']), $carlo, '2026-09-01', 50);
    $an = cdcAnteprima($this, $s, $s['unita'], $ultima);
    cdcPassa($this, $s, $s['unita'], $ultima);

    // A mano: la metà piena va a Elsa il 1/4 (275 giorni, 45205); l'usufrutto a Bice il 1/6 (214 giorni, 35178,08 → 35178) e da
    // Bice a Carlo il 1/9 (122 giorni, 20054,79 → 20055). Ugo 120000 − 45205 − 35178 = 39617, Bice 35178 − 20055 = 15123. Prima
    // si fermava, e prima ancora Carlo pagava anche la metà piena, già di Elsa (€ 401,10).
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe(['Acquirente Elsa' => 45205, 'Bice Nuda' => 15123, 'Carlo Compratore' => 20055, 'Venditore Ugo' => 39617]);
});

/** Ugo nudo e Rita usufruttuaria, voce sul «Proprietario»: l'usufrutto di Rita si estingue il 1/5, Ugo costituisce l'usufrutto a Carlo il 1/7. */
function cdcNudoTornatoPieno($test, string $scelta): array
{
    $s = ruScenario('prima_rata', 0, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'nuda_proprietario']);
    $rita = cdcPersona($s, 'Rita Usufruttuaria');
    $rigaRita = cdcTitolare($s['unita'], $rita, 'usufruttuario', 100);
    $s['carlo'] = cdcPersona($s, 'Carlo Usufruttuario');
    cdcGenera($s);
    ruEmetti($s, '2026-04-30');
    cdcPassa($test, $s, $s['unita'], ruPassaggio('estinzione', $rigaRita, null, '2026-05-01', 100));
    cdcPassa($test, $s, $s['unita'], cdcConScelta(ruPassaggio('costituzione', cdcRiga($s['unita'], $s['v']), $s['carlo'], '2026-07-01', 100), $scelta));

    return $s;
}

it('R2 — Ugo nudo torna pieno, costituisce l\'usufrutto a Carlo il 1/7, e l\'usufrutto di Carlo si estingue il 1/10: con la legge la voce torna a Ugo dal 1/10; «come dice ogni voce» non era mai passata', function (string $scelta, array $netti) {
    $s = cdcNudoTornatoPieno($this, $scelta);
    $estinzione = ruPassaggio('estinzione', cdcRiga($s['unita'], $s['carlo'], 'usufruttuario'), null, '2026-10-01', 100);
    $an = cdcAnteprima($this, $s, $s['unita'], $estinzione);
    cdcPassa($this, $s, $s['unita'], $estinzione);

    // A mano, su € 1.200,00 sul «Proprietario»: con l'usufrutto di Rita la voce scende al nudo, e le dodici quote sono di Ugo.
    // Con la legge l'ordinaria è di Carlo dal 1/7: 184 giorni, 60493,15 → 60493. All'estinzione torna a Ugo dal 1/10: 92 giorni,
    // 30246,58 → 30247 (un passaggio alla volta, come la catena di M2-2). Ugo 120000 − 60493 + 30247 = 89754, Carlo 60493 − 30247
    // = 30246. «Come dice ogni voce» la voce sul «Proprietario» resta al nudo: nessuna coppia, Ugo 120000. Prima si fermava («Ugo
    // ha avuto lo stesso ruolo in due periodi separati»), e prima ancora Carlo pagava anche i 92 giorni dopo la fine.
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe($netti);
})->with([
    'con la legge' => ['usufruttuario', ['Carlo Usufruttuario' => 30246, 'Venditore Ugo' => 89754]],
    'come dice ogni voce' => ['voce', ['Venditore Ugo' => 120000]],
]);

it('R7 — Ugo nudo torna pieno, costituisce l\'usufrutto a Carlo il 1/7 e vende la nuda a Fede il 1/9: con la legge a Fede non passa niente; «come dice ogni voce» passa la voce sul «Proprietario», rimasta al nudo', function (string $scelta, array $netti) {
    $s = cdcNudoTornatoPieno($this, $scelta);
    $fede = cdcPersona($s, 'Fede Compratrice');
    $nuda = ruPassaggio('nuda', cdcRiga($s['unita'], $s['v'], 'nuda_proprietario'), $fede, '2026-09-01', 100);
    $an = cdcAnteprima($this, $s, $s['unita'], $nuda);
    cdcPassa($this, $s, $s['unita'], $nuda);

    // A mano: con la legge la voce è di Carlo dal 1/7 (60493) e la nuda non porta niente a Fede: Ugo 59507, Carlo 60493. «Come
    // dice ogni voce» la voce resta al nudo, e con la nuda passa a Fede per 122 giorni: 40109,59 → 40110, di cui € 400,00 con le
    // quattro bozze dal 5/9 e € 1,10 con la coppia; Ugo 79890. Prima si fermava, e prima ancora con la legge Fede pagava € 401,10.
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe($netti);
})->with([
    'con la legge' => ['usufruttuario', ['Carlo Usufruttuario' => 60493, 'Venditore Ugo' => 59507]],
    'come dice ogni voce' => ['voce', ['Fede Compratrice' => 40110, 'Venditore Ugo' => 79890]],
]);

it('R3 — Ugo usufruttuario, Nora e Bice nude al 50 %, voce sull\'«Usufruttuario»: l\'usufrutto si estingue il 1/4, Bice vende la sua metà a Nora il 1/6, Nora vende tutto a Carlo il 1/9: a Carlo le due metà', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    $nora = cdcPersona($s, 'Nora Nuda');
    $bice = cdcPersona($s, 'Bice Nuda');
    cdcTitolare($s['unita'], $nora, 'nuda_proprietario', 50);
    cdcTitolare($s['unita'], $bice, 'nuda_proprietario', 50);
    $carlo = cdcPersona($s, 'Carlo Compratore');
    cdcGenera($s);
    ruEmetti($s, '2026-03-31');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', $s['rigaV'], null, '2026-04-01', 100));
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $bice), $nora, '2026-06-01', 50));
    $ultima = ruPassaggio('vendita', cdcRiga($s['unita'], $nora), $carlo, '2026-09-01', 100);
    $an = cdcAnteprima($this, $s, $s['unita'], $ultima);
    cdcPassa($this, $s, $s['unita'], $ultima);

    // A mano: le dodici quote sono di Ugo (120000). L'estinzione dà a ciascuna nuda la sua metà dal 1/4: 120000 × 275/365 =
    // 90410,96 → 90411, 45206 a Nora (scritta per prima) e 45205 a Bice. Il 1/6 Nora compra la metà di Bice: 60000 × 214/365 =
    // 35178,08 → 35178. Il 1/9 Nora vende tutto: la riga di Ugo è arrivata a Nora per due strade, metà il 1/4 e metà il 1/6, e
    // tutta passa a Carlo: 120000 × 122/365 = 40109,59 → 40110. Ugo 29589, Nora 45206 + 35178 − 40110 = 40274, Bice 10027.
    // Prima si fermava, e prima ancora Carlo pagava una metà sola (€ 200,55).
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe(['Bice Nuda' => 10027, 'Carlo Compratore' => 40110, 'Nora Nuda' => 40274, 'Venditore Ugo' => 29589]);
});

it('R6 — S3 con la voce sul «Proprietario»: Ugo vende con riserva d\'usufrutto la metà piena a Nora il 1/4, l\'usufrutto (ora di tutta l\'unità) si estingue il 1/9, Bice vende a Carlo il 1/10: all\'estinzione la riga di Ugo torna a Nora, non a Bice', function (string $scelta, array $nettiEstinzione, array $netti, ?array $coppiaEstinzione) {
    $s = cdcS3('proprietario');
    $nora = cdcPersona($s, 'Nora Compratrice');
    $carlo = cdcPersona($s, 'Carlo Compratore');
    cdcPassa($this, $s, $s['unita'], cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $nora, '2026-04-01', 50), $scelta));
    $estinzione = ruPassaggio('estinzione', cdcRiga($s['unita'], $s['v'], 'usufruttuario'), null, '2026-09-01', 100);
    $an = cdcAnteprima($this, $s, $s['unita'], $estinzione);
    cdcPassa($this, $s, $s['unita'], $estinzione);
    $nettiDopo = cdcNetti($s, $s['unita']);
    $vendita = ruPassaggio('vendita', cdcRiga($s['unita'], $s['bice']), $carlo, '2026-10-01', 50);
    $anVendita = cdcAnteprima($this, $s, $s['unita'], $vendita);
    cdcPassa($this, $s, $s['unita'], $vendita);

    // A mano: le righe sono la metà piena di Ugo (€ 600,00, 5000 a rata) e la nuda di Bice (€ 600,00, sul capitale). Con la legge
    // la riserva lascia a Ugo l'ordinaria della metà che vende, legata alla nuda di Nora; l'usufrutto di Ugo diventa dell'unità
    // intera. All'estinzione del 1/9 quella riga torna a Nora per 122 giorni: 60000 × 122/365 = 20054,79 → 20055; la riga di Bice
    // era già sua. Ugo 60000 − 20055 = 39945. Il 1/10 Bice vende a Carlo la sua metà: 92 giorni, 15123,29 → 15123, di cui
    // € 150,00 con le tre bozze dal 5/10 e € 1,23 con la coppia; la riga di Ugo è andata a Nora e non c'entra. «Come dice ogni
    // voce» la voce sul «Proprietario» passa a Nora con la riserva (275 giorni, 45205) e all'estinzione niente. Prima si fermava
    // all'estinzione e alla vendita, e prima ancora l'estinzione divideva per quota fra Nora e Bice (€ 100,27 e € 100,28).
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcFermo($anVendita))->toBeNull()
        ->and($nettiDopo)->toBe($nettiEstinzione)
        ->and(cdcNetti($s, $s['unita']))->toBe($netti)
        ->and(collect($an['rate']['conguaglio']['coppie'] ?? [])->map(fn ($c) => [$c['entrante_nome'], $c['importo']])->all())->toBe($coppiaEstinzione ?? []);
})->with([
    'con la legge' => ['usufruttuario', ['Bice Nuda' => 60000, 'Nora Compratrice' => 20055, 'Venditore Ugo' => 39945],
        ['Bice Nuda' => 44877, 'Carlo Compratore' => 15123, 'Nora Compratrice' => 20055, 'Venditore Ugo' => 39945], [['Nora Compratrice', 20055]]],
    'come dice ogni voce' => ['voce', ['Bice Nuda' => 60000, 'Nora Compratrice' => 45205, 'Venditore Ugo' => 14795],
        ['Bice Nuda' => 44877, 'Carlo Compratore' => 15123, 'Nora Compratrice' => 45205, 'Venditore Ugo' => 14795], null],
]);

it('la parte già ceduta — S3 con la voce sull\'«Usufruttuario»: l\'usufrutto di Ugo si estingue il 10/3, poi Ugo vende la metà piena a Elsa il 17/4: la frase dice che l\'usufrutto Ugo l\'ha già ceduto, non che lo tiene', function () {
    $s = cdcS3('usufruttuario');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', $s['rigaU'], null, '2026-03-10', 50));
    $vendita = ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-04-17', 50);
    $an = cdcAnteprima($this, $s, $s['unita'], $vendita);
    cdcPassa($this, $s, $s['unita'], $vendita);

    // A mano: l'usufrutto va a Bice dal 10/3, 297 giorni, 60000 × 297/365 = 48821,92 → 48822; la metà piena a Elsa dal 17/4, 259
    // giorni, 42575,34 → 42575; Ugo 120000 − 48822 − 42575 = 28603. La riga dell'usufrutto non passa a Elsa: la genealogia sa che
    // è andata a Bice con l'estinzione. Prima la frase diceva «la parte dell'unità che Venditore Ugo tiene (l'usufrutto)».
    expect(cdcNetti($s, $s['unita']))->toBe(['Acquirente Elsa' => 42575, 'Bice Nuda' => 48822, 'Venditore Ugo' => 28603])
        ->and(implode("\n", $an['rate']['conguaglio']['frasi']))
        ->toContain('è della parte dell\'unità che Venditore Ugo ha già ceduto con un passaggio precedente (l\'usufrutto), e non passa.')
        ->toContain('ogni quota comprende anche la spesa di una parte dell\'unità che Venditore Ugo ha già ceduto con un passaggio precedente')
        ->not->toContain('che Venditore Ugo tiene');
});

it('rilievo HT1 — ragioni miste: Ugo pieno, usufruttuario e nudo; il suo usufrutto si estingue il 1/3, poi vende la nuda il 1/6: le sue bozze sono della parte che tiene e dell\'usufrutto già passato, e la frase non dice che le tiene tutte', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $ugo = $s['v'];
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 40]);
    $rigaU = cdcTitolare($s['unita'], $ugo, 'usufruttuario', 30);
    cdcTitolare($s['unita'], cdcPersona($s, 'Bice Nuda'), 'nuda_proprietario', 30);
    $rigaN = cdcTitolare($s['unita'], $ugo, 'nuda_proprietario', 30);
    cdcTitolare($s['unita'], $s['a'], 'usufruttuario', 30);
    cdcGenera($s);
    ruEmetti($s, '2026-02-28');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', $rigaU, null, '2026-03-01', 30));

    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio('nuda', $rigaN, cdcPersona($s, 'Nora Compratrice'), '2026-06-01', 30));

    // Le bozze di Ugo hanno due parti: la piena, che Ugo tiene, e l'usufrutto, andato a Bice il 1/3. La nuda che Ugo vende non
    // paga la voce sull'«Usufruttuario». Prima la frase diceva che erano tutte della parte che Ugo tiene.
    expect(collect($an['rate']['conguaglio']['quote_in_bozza'])->where('intestatario', 'Venditore Ugo')->pluck('motivo')->unique()->values()->all())->toBe(['altra_quota'])
        ->and(implode("\n", $an['rate']['conguaglio']['frasi']))
        ->toContain('resteranno intestate a Venditore Ugo: sono di parti dell\'unità che non passano con questo atto.')
        ->not->toContain('che Venditore Ugo tiene, e non passano');
});

// --- Decisione 55: i piani generati prima della .43, senza legame ------------------------------------------------------------

it('decisione 55 — un piano generato prima della .43 non ha il legame: dove la riga di titolarità possibile è una sola, il conguaglio lo deduce al volo, con le stesse cifre, e non lo scrive', function () {
    $s = cdcS3('usufruttuario');
    $carlo = cdcPersona($s, 'Carlo Compratore');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-04-01', 50));
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', $s['rigaU'], null, '2026-06-01', 50));
    // Il piano come lo avrebbe scritto la .42: le righe di riparto senza la riga di titolarità.
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->update(['anagrafica_immobile_id' => null]);
    $ultima = ruPassaggio('vendita', cdcRiga($s['unita'], $s['bice']), $carlo, '2026-09-01', 50);
    $an = cdcAnteprima($this, $s, $s['unita'], $ultima);
    cdcPassa($this, $s, $s['unita'], $ultima);

    // Le cifre di R1: la riga «usufruttuario» di Ugo si deduce dalla sola riga d'usufrutto che aveva, quella «proprietario» dalla
    // sola piena; a Carlo la metà dell'usufrutto, 20055. Il legame dedotto non si scrive: le righe restano senza.
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe(['Acquirente Elsa' => 45205, 'Bice Nuda' => 15123, 'Carlo Compratore' => 20055, 'Venditore Ugo' => 39617])
        ->and(DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->whereNotNull('anagrafica_immobile_id')->count())->toBe(0);
});

it('decisione 55 — dove la riga di titolarità non si deduce con certezza (Ugo ha venduto e ricomprato prima della generazione, e il piano, scritto da una versione senza tratti, ha due righe uguali a suo nome), su una catena il conguaglio si ferma e dice perché', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $ugo = $s['v'];
    $bice = cdcPersona($s, 'Bice Seconda');
    $carlo = cdcPersona($s, 'Carlo Quarto');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', $s['rigaV'], $bice, '2026-03-01', 100));
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $bice), $ugo, '2026-05-01', 100));
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e'], accettaDestinatari: true);
    ruEmetti($s, '2026-06-30');
    // Le righe come le scriveva la beta.29: senza tratto, senza competenza e senza legame. Le due righe di Ugo (gennaio-febbraio e
    // da maggio) diventano uguali, e nessuna delle due righe di titolarità si può dire «quella».
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->update(['anagrafica_immobile_id' => null, 'titolarita_dal' => null, 'titolarita_al' => null, 'competenza_dal' => null, 'competenza_al' => null]);
    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $ugo), $carlo, '2026-07-01', 100));

    expect(cdcFermo($an))->toContain('il dettaglio del riparto non dice da quale riga di titolarità viene la quota di Venditore Ugo, e non si deduce con certezza')
        ->and(collect($an['rate']['conguaglio']['coppie'] ?? [])->sum('importo'))->toBe(0);
});

it('decisione 55 — la deduzione da sola: una riga per ruolo si lega; l\'addebito diretto senza tratto di chi ha più righe sull\'unità (la piena e la nuda) non si lega, come nel motore; due righe gemelle nemmeno', function () {
    $riga = fn (array $c) => (object) ($c + ['piano_rate_id' => 1, 'immobile_id' => 1, 'anagrafica_id' => 7, 'anagrafica_immobile_id' => null, 'conto_id' => 1, 'tabella_id' => 1,
        'ruolo_richiesto' => 'proprietario', 'riga_fattura_id' => null, 'competenza_dal' => '2026-01-01', 'competenza_al' => '2026-12-31', 'titolarita_dal' => null, 'titolarita_al' => null,
        'quota_possesso' => 50, 'created_at' => '2026-01-10 10:00:00']);
    $titolarita = collect([
        2 => (object) ['id' => 2, 'anagrafica_id' => 7, 'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => 1, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => '2026-01-01 09:00:00'],
        3 => (object) ['id' => 3, 'anagrafica_id' => 7, 'tipologia' => 'nuda_proprietario', 'quota' => 50, 'attivo' => 1, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => '2026-01-01 09:00:00'],
    ]);
    $piani = [1 => ['prima_quota' => 1, 'dal' => '2026-01-01', 'natura' => 'ordinaria']];
    $legami = fn (array $righe) => \App\Services\Subentro\GenealogiaDellaQuota::legamiDedotti(collect($righe), $titolarita, [], $piani);

    // La riga del riparto «proprietario» viene dalla sola riga piena; quella «nuda_proprietario» dalla sola nuda.
    expect($legami([$riga(['id' => 10, 'tipo' => 'riparto', 'ruolo_risolto' => 'proprietario']), $riga(['id' => 11, 'tipo' => 'riparto', 'ruolo_risolto' => 'nuda_proprietario'])]))->toBe([10 => 2, 11 => 3])
        // L'addebito diretto senza tratto è per persona (il motore somma le due righe): nessun legame.
        ->and($legami([$riga(['id' => 12, 'tipo' => 'ad_personam', 'ruolo_risolto' => 'proprietario', 'ruolo_richiesto' => null])]))->toBe([])
        // Due righe gemelle dello stesso piano vengono da due righe di titolarità: nessuna delle due si lega.
        ->and($legami([$riga(['id' => 13, 'tipo' => 'riparto', 'ruolo_risolto' => 'proprietario']), $riga(['id' => 14, 'tipo' => 'riparto', 'ruolo_risolto' => 'proprietario'])]))->toBe([]);
});

it('la genealogia legge righe e passaggi una volta per unità: nell\'anteprima dell\'ultima di sette vendite le sue letture sono quante nell\'anteprima della seconda', function () {
    $letture = function (array $s, Immobile $unita, array $dati): int {
        $n = 0;
        DB::listen(function () use (&$n) {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $f) {
                if (($f['class'] ?? null) === \App\Services\Subentro\GenealogiaDellaQuota::class) {
                    $n++;

                    return;
                }
            }
        });
        cdcAnteprima($this, $s, $unita, $dati);

        return $n;
    };
    $s = ruScenario('prima_rata', 0);
    $chi = [$s['v'], $s['a']];
    foreach (['Bice', 'Carlo', 'Dora', 'Ezio', 'Fede', 'Gina'] as $nome) {
        $chi[] = cdcPersona($s, $nome . ' Catena');
    }
    $mesi = ['2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01'];
    $alla = [];
    foreach ($mesi as $i => $giorno) {
        ruEmetti($s, CarbonImmutable::parse($giorno)->subDay()->toDateString());
        $dati = ruPassaggio('vendita', cdcRiga($s['unita'], $chi[$i]), $chi[$i + 1], $giorno, 100);
        $alla[$i + 1] = $letture($s, $s['unita'], $dati);
        cdcPassa($this, $s, $s['unita'], $dati);
    }

    // Una lettura delle righe di titolarità e una dei passaggi dell'unità, qualunque sia la lunghezza della catena: non una per
    // riga di riparto né per passaggio. (I nomi si leggono solo per le frasi di una riga che non si decide: qui nessuna.)
    expect($alla[7])->toBe(2)->and($alla[2])->toBe(2)
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->count())->toBe(14);
});

it('decisione 60 — un anello registrato prima della .43 che ha preso il piano senza scrivere una coppia sull\'unità (lì la .42 si fermava): la catena che lo attraversa si ferma e dice quale passaggio; con la coppia, o registrato dalla .43, o senza quel piano, si segue', function (int $versione, bool $senzaCoppia, bool $pianoPreso, bool $siFerma) {
    // La catena del DV3 (Ugo a Bice il 1/3, Bice a Ugo il 1/5, Ugo a Carlo il 1/7), poi Carlo vende a Dora il 1/9. L'anello del 1/7
    // si riscrive come lo lascia la .42 quando si ferma: registro di versione 2, nessuna coppia sull'unità.
    $s = ruScenario('prima_rata', 0);
    $ugo = $s['v'];
    $bice = cdcPersona($s, 'Bice Seconda');
    $carlo = cdcPersona($s, 'Carlo Quarto');
    $dora = cdcPersona($s, 'Dora Quinta');
    ruEmetti($s, '2026-02-28');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $ugo), $bice, '2026-03-01', 100));
    ruEmetti($s, '2026-04-30');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $bice), $ugo, '2026-05-01', 100));
    ruEmetti($s, '2026-06-30');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $ugo), $carlo, '2026-07-01', 100));
    $anello = Subentro::where('immobile_id', $s['unita']->id)->latest('id')->firstOrFail();
    $registro = $anello->registro;
    $registro['versione'] = $versione;
    if (! $pianoPreso) {
        $registro['piani_presi'] = [];
    }
    $anello->update(['registro' => $registro]);
    if ($senzaCoppia) {
        DB::table('saldi')->where('subentro_id', $anello->id)->delete();
    }
    ruEmetti($s, '2026-08-31');

    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $carlo), $dora, '2026-09-01', 100));

    if ($siFerma) {
        expect(cdcFermo($an))->toContain('la quota è passata con il passaggio del 1 luglio 2026, registrato prima della 1.11.0-beta.43 senza una coppia di conguaglio su questa unità: non si sa se la parte di quel tratto è stata regolata a mano');
    } else {
        expect(cdcFermo($an))->toBeNull();
    }
})->with([
    'versione 2, senza coppia: si ferma' => [2, true, true, true],
    'versione 2, con la sua coppia: si segue' => [2, false, true, false],
    'versione 3, senza coppia: si segue' => [3, true, true, false],
    'versione 2, senza coppia, piano non preso: si segue' => [2, true, false, false],
]);

it('decisione 60 — una riserva d\'usufrutto registrata prima della .43, con la legge, non scrive coppie (l\'ordinaria resta a chi vende): la quota che la attraversa resta alla stessa persona, e l\'estinzione dopo si segue come nella R6', function () {
    $s = cdcS3('proprietario');
    $nora = cdcPersona($s, 'Nora Compratrice');
    cdcPassa($this, $s, $s['unita'], cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $nora, '2026-04-01', 50), 'usufruttuario'));
    $riserva = Subentro::where('immobile_id', $s['unita']->id)->latest('id')->firstOrFail();
    $riserva->update(['registro' => array_replace($riserva->registro, ['versione' => 2])]);

    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio('estinzione', cdcRiga($s['unita'], $s['v'], 'usufruttuario'), null, '2026-09-01', 100));

    // Le premesse: la riserva ha preso il piano e non ha scritto coppie. Le cifre sono quelle della R6 con la legge: la riga di Ugo
    // torna a Nora per 122 giorni, 60000 × 122/365 = 20054,79 → 20055.
    expect(DB::table('saldi')->where('subentro_id', $riserva->id)->count())->toBe(0)
        ->and($riserva->registro['piani_presi'])->toContain($s['piano']->id)
        ->and(cdcFermo($an))->toBeNull()
        ->and(collect($an['rate']['conguaglio']['coppie'] ?? [])->map(fn ($c) => [$c['entrante_nome'], $c['importo']])->all())->toBe([['Nora Compratrice', 20055]]);
});

it('chi esce era anche nudo e all\'estinzione torna pieno; poi vende la piena: la genealogia segue la sua quota attraverso l\'estinzione, e chi compra paga i suoi giorni', function (string $soggetto) {
    // Ugo usufruttuario di tutto e nudo di tutto (la nuda comprata mentre era usufruttuario), le dodici rate emesse. Il 1/4
    // l'usufrutto si estingue e Ugo torna pieno; il 1/9 vende a Dino.
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto, genera: false);
    $dino = cdcPersona($s, 'Dino Compratore');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    cdcTitolare($s['unita'], $s['v'], 'nuda_proprietario', 100);
    cdcGenera($s);
    ruEmetti($s, '2026-12-31');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', $s['rigaV'], null, '2026-04-01', 100));
    $vendita = ruPassaggio('vendita', cdcRiga($s['unita'], $s['v']), $dino, '2026-09-01', 100);
    $an = cdcAnteprima($this, $s, $s['unita'], $vendita);
    cdcPassa($this, $s, $s['unita'], $vendita);

    // A mano: dal 1/9, 122 giorni: 120000 × 122/365 = 40109,59 → 40110 a Dino; Ugo 120000 − 40110 = 79890. Prima la genealogia
    // toglieva chi esce dai nudi dell'estinzione registrata: la quota restava sulla sua riga di nuda chiusa, e Dino non pagava
    // niente («Ugo ha già ceduto la nuda proprietà», o «tiene l'usufrutto»).
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe(['Dino Compratore' => 40110, 'Venditore Ugo' => 79890]);
})->with(['voce sul «Proprietario»' => 'proprietario', 'voce sull\'«Usufruttuario»' => 'usufruttuario']);

it('chi esce era nudo di metà, Mara dell\'altra: all\'estinzione tornano pieni tutti e due; Ugo vende la sua metà a Dino, Mara la sua a Elio: ognuno paga i suoi giorni, senza fermate', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    $mara = cdcPersona($s, 'Mara Nuda');
    $dino = cdcPersona($s, 'Dino Compratore');
    $elio = cdcPersona($s, 'Elio Compratore');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    cdcTitolare($s['unita'], $s['v'], 'nuda_proprietario', 50);
    cdcTitolare($s['unita'], $mara, 'nuda_proprietario', 50);
    cdcGenera($s);
    ruEmetti($s, '2026-12-31');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', $s['rigaV'], null, '2026-04-01', 100));
    $aDino = ruPassaggio('vendita', cdcRiga($s['unita'], $s['v']), $dino, '2026-09-01', 50);
    $anDino = cdcAnteprima($this, $s, $s['unita'], $aDino);
    cdcPassa($this, $s, $s['unita'], $aDino);
    $aElio = ruPassaggio('vendita', cdcRiga($s['unita'], $mara), $elio, '2026-10-01', 50);
    $anElio = cdcAnteprima($this, $s, $s['unita'], $aElio);
    cdcPassa($this, $s, $s['unita'], $aElio);

    // A mano, sulla voce di € 1.200,00 tutta di Ugo usufruttuario: all'estinzione (275 giorni) metà a Mara, 60000 × 275/365 =
    // 45205,48 → 45205, e metà resta a Ugo. Dal 1/9 la metà di Ugo a Dino: 60000 × 122/365 = 20054,79 → 20055. Dal 1/10 la metà
    // di Mara a Elio: 60000 × 92/365 = 15123,29 → 15123. Netti: Ugo 120000 − 45205 − 20055 = 54740, Mara 45205 − 15123 = 30082.
    expect(cdcFermo($anDino))->toBeNull()
        ->and(cdcFermo($anElio))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe(['Dino Compratore' => 20055, 'Elio Compratore' => 15123, 'Mara Nuda' => 30082, 'Venditore Ugo' => 54740]);
});

/** U3 con un predecessore: Ugo pieno 50, Bice nuda 50, Mara usufruttuaria 50, voce € 1.200,00 sul «Proprietario», tutte le rate emesse. */
function cdcU3Predecessore(): array
{
    $s = ruScenario('prima_rata', 0, genera: false);
    $s['bice'] = cdcPersona($s, 'Bice Nuda');
    $s['nora'] = cdcPersona($s, 'Nora Terza');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    cdcTitolare($s['unita'], $s['bice'], 'nuda_proprietario', 50);
    cdcTitolare($s['unita'], cdcPersona($s, 'Mara Usufruttuaria'), 'usufruttuario', 50);
    cdcGenera($s);
    ruEmetti($s, '2026-12-31');

    return $s;
}

it('U3 con un predecessore — Bice vende la nuda a Ugo il 1/3; il 30/9 Ugo vende prima la nuda a Elsa, poi la metà piena a Nora: a Nora solo la metà piena, e la frase dice che la nuda Ugo l\'ha già ceduta', function () {
    $s = cdcU3Predecessore();
    cdcPassa($this, $s, $s['unita'], ruPassaggio('nuda', cdcRiga($s['unita'], $s['bice'], 'nuda_proprietario'), $s['v'], '2026-03-01', 50));
    cdcPassa($this, $s, $s['unita'], ruPassaggio('nuda', cdcRiga($s['unita'], $s['v'], 'nuda_proprietario'), $s['a'], '2026-09-30', 50));
    $piena = ruPassaggio('vendita', cdcRiga($s['unita'], $s['v']), $s['nora'], '2026-09-30', 50);
    $an = cdcAnteprima($this, $s, $s['unita'], $piena);
    cdcPassa($this, $s, $s['unita'], $piena);

    // A mano: il 1/3 la riga di Bice passa a Ugo per 306 giorni, 60000 × 306/365 = 50301,37 → 50301 (Bice 9699). Il 30/9 Ugo
    // la cede a Elsa: 93 giorni, 15287,67 → 15288. A Nora solo la metà piena: 15288. Ugo 60000 + 50301 − 15288 − 15288 = 79725.
    // Prima la riga d'arrivo della nuda di Elsa, nata il 30/9, non risultava «in corso il giorno prima»: la quota di Bice era
    // indecidibile, e le regole del ruolo la facevano pagare di nuovo a Nora (30576).
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe(['Acquirente Elsa' => 15288, 'Bice Nuda' => 9699, 'Nora Terza' => 15288, 'Venditore Ugo' => 79725])
        ->and(implode(' ', $an['rate']['conguaglio']['frasi']))->not->toContain('non è mai passata')
        ->toContain('emessa a Bice Nuda per una parte dell\'unità che Venditore Ugo ha avuto e ha già ceduto con un passaggio precedente (la nuda proprietà)')
        ->not->toContain('resta a Venditore Ugo: è della parte');
});

it('U3 con un predecessore — il 30/9 Bice vende la nuda a Ugo e Ugo, lo stesso giorno, vende la metà piena a Nora: a Nora la sola metà piena, la nuda resta a Ugo', function () {
    $s = cdcU3Predecessore();
    cdcPassa($this, $s, $s['unita'], ruPassaggio('nuda', cdcRiga($s['unita'], $s['bice'], 'nuda_proprietario'), $s['v'], '2026-09-30', 50));
    $piena = ruPassaggio('vendita', cdcRiga($s['unita'], $s['v']), $s['nora'], '2026-09-30', 50);
    $an = cdcAnteprima($this, $s, $s['unita'], $piena);
    cdcPassa($this, $s, $s['unita'], $piena);

    // A mano: la riga di Bice passa a Ugo il 30/9, 93 giorni: 15288 (Bice 44712); a Nora solo la metà piena di Ugo, 15288. Ugo
    // 60000 + 15288 − 15288 = 60000. Prima Nora pagava anche la nuda, appena comprata da Ugo e rimasta sua: 30576.
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe(['Bice Nuda' => 44712, 'Nora Terza' => 15288, 'Venditore Ugo' => 60000]);
});

it('U3, la frase — Ugo vende il 30/9 la nuda a Elsa e poi la metà piena a Nora: la quota della nuda resta fuori perché Ugo l\'ha già ceduta, non perché la tiene', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $nora = cdcPersona($s, 'Nora Terza');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    cdcTitolare($s['unita'], $s['v'], 'nuda_proprietario', 50);
    cdcTitolare($s['unita'], cdcPersona($s, 'Mara Usufruttuaria'), 'usufruttuario', 50);
    cdcGenera($s);
    ruEmetti($s, '2026-06-13');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('nuda', cdcRiga($s['unita'], $s['v'], 'nuda_proprietario'), $s['a'], '2026-09-30', 50));
    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $s['v']), $nora, '2026-09-30', 50));

    expect(implode(' ', $an['rate']['conguaglio']['frasi']))->toContain('ha già ceduto con un passaggio precedente')
        ->not->toContain('che Venditore Ugo tiene');
});

it('R3 con la vendita il 1/10 — la riga di Ugo arriva a Nora in due parti (1/4 e 1/6) e passa tutta a Carlo: si arrotonda una volta sola sulla riga, non una volta per parte', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario', genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'usufruttuario']);
    $nora = cdcPersona($s, 'Nora Nuda');
    $bice = cdcPersona($s, 'Bice Nuda');
    cdcTitolare($s['unita'], $nora, 'nuda_proprietario', 50);
    cdcTitolare($s['unita'], $bice, 'nuda_proprietario', 50);
    $carlo = cdcPersona($s, 'Carlo Compratore');
    cdcGenera($s);
    ruEmetti($s, '2026-03-31');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', $s['rigaV'], null, '2026-04-01', 100));
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $bice), $nora, '2026-06-01', 50));
    $ultima = ruPassaggio('vendita', cdcRiga($s['unita'], $nora), $carlo, '2026-10-01', 100);
    $an = cdcAnteprima($this, $s, $s['unita'], $ultima);
    cdcPassa($this, $s, $s['unita'], $ultima);

    // A mano: dal 1/10, 92 giorni, la riga intera di Ugo: 120000 × 92/365 = 30246,58 → 30247 a Carlo, una volta sola. Per parte
    // sarebbero 60000 × 92/365 = 15123,29 → 15123 due volte, 30246: un centesimo in meno secondo la strada fatta dalla quota.
    // Le due parti si dividono i 30247 con i resti maggiori: € 151,24 e € 151,23. Nora 45206 + 35178 − 30247 = 50137.
    $frasi = implode(' ', $an['rate']['conguaglio']['frasi']);
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcNetti($s, $s['unita']))->toBe(['Bice Nuda' => 10027, 'Carlo Compratore' => 30247, 'Nora Nuda' => 50137, 'Venditore Ugo' => 29589])
        ->and($frasi)->toContain('€ 151,24')->toContain('€ 151,23')->toContain('€ 302,47');
});

it('rilievo G2 — la riserva si somma alla nuda che Bruno aveva già, e l\'estinzione la consolida solo in parte: nella vendita della piena dopo, la quota della nuda di prima non passa a chi compra', function () {
    // Unità mista: Ugo pieno 50, Carla usufruttuaria 50, Bruno nudo 50; voce € 1.200,00 sul «Proprietario», tutte le rate emesse.
    // 1/3 Ugo vende con riserva la nuda della sua metà a Bruno (con la legge), che la somma: nuda 100. 1/6 l'usufrutto di Ugo si
    // estingue: il registro dice che torna piena la parte della riserva (50). 1/9 Bruno vende la piena 50 a Elio.
    $s = ruScenario('prima_rata', 0, genera: false);
    $bruno = cdcPersona($s, 'Bruno Nudo');
    $elio = cdcPersona($s, 'Elio Compratore');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    cdcTitolare($s['unita'], cdcPersona($s, 'Carla Usufruttuaria'), 'usufruttuario', 50);
    cdcTitolare($s['unita'], $bruno, 'nuda_proprietario', 50);
    cdcGenera($s);
    ruEmetti($s, '2026-12-31');
    cdcPassa($this, $s, $s['unita'], cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $bruno, '2026-03-01', 50), 'usufruttuario'));
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', cdcRiga($s['unita'], $s['v'], 'usufruttuario'), null, '2026-06-01', 50));
    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $bruno), $elio, '2026-09-01', 50));

    // Giusto: a Elio la sola quota della metà consolidata, 60000 × 122/365 = 20054,79 → 20055. Prima la genealogia divideva per
    // quota anche la riga di Bruno sulla nuda di prima, e Elio pagava 30082 (€ 100,27 di troppo). Ora quella riga non si segue e il
    // gruppo di Bruno si ferma, anche se è chi esce (decisione 63: le regole del ruolo, che qui la lascerebbero a Bruno, sbagliano
    // quando muore prima l'altro usufruttuario — rilievo K2); la coppia della riga di Ugo resta.
    expect(cdcFermo($an))->toContain('somma')
        ->and(collect($an['rate']['conguaglio']['coppie'] ?? [])->map(fn ($c) => [$c['entrante_nome'], $c['importo']])->all())->toBe([['Elio Compratore', 20055]]);
});

it('rilievo G5 — la voce del predecessore su due tabelle (50 e 50): un gruppo solo, la frase compatta, e le cifre di sempre', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $bice = cdcPersona($s, 'Bice Seconda');
    $nora = cdcPersona($s, 'Nora Terza');
    $conto = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->value('conti.id');
    DB::table('conto_tabella_millesimale')->where('conto_id', $conto)->update(['coefficiente' => 50]);
    $scale = DB::table('tabelle')->insertGetId(['condominio_id' => $s['c']->id, 'nome' => 'Scale', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('quote_tabella')->insert(['tabella_id' => $scale, 'immobile_id' => $s['unita']->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto, 'tabella_id' => $scale, 'coefficiente' => 50, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    cdcGenera($s);
    ruEmetti($s, '2026-12-31');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $s['v']), $bice, '2026-03-01', 100));
    $ultima = ruPassaggio('vendita', cdcRiga($s['unita'], $bice), $nora, '2026-09-01', 100);
    $an = cdcAnteprima($this, $s, $s['unita'], $ultima);
    cdcPassa($this, $s, $s['unita'], $ultima);

    // A mano: la voce intera di Ugo, 120000 in due righe da 60000. Il 1/3 a Bice per 306 giorni: 100602,74 → 100603 (Ugo 19397).
    // Il 1/9 a Nora per 122 giorni: 40109,59 → 40110 (Bice 60493). Prima la prima riga perdeva il giorno d'arrivo e finiva in un
    // gruppo suo: la stessa voce compariva due volte, «voce per voce».
    expect(DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'riparto')->count())->toBe(2)
        ->and(implode(' ', $an['rate']['conguaglio']['frasi']))->not->toContain('voce per voce')
        ->and(cdcNetti($s, $s['unita']))->toBe(['Bice Seconda' => 60493, 'Nora Terza' => 40110, 'Venditore Ugo' => 19397]);
});

it('rilievo H1 — la riserva che si somma alla nuda di Bruno è registrata prima della generazione: il riparto nasce sulla nuda sommata intera, e dopo il consolidamento a chi compra va la sua metà', function (bool $vendeLaPiena) {
    // Ugo pieno 50, Carla usufruttuaria 50, Bruno nudo 50. Il 1/1 Ugo vende con riserva la nuda della sua metà a Bruno (nuda 100);
    // solo dopo si genera il piano: la voce € 1.200,00 sul «Proprietario» va tutta a Bruno, su una riga legata alla nuda 100.
    // Il 1/6 l'usufrutto di Ugo si estingue (Bruno pieno 50 e nudo 50); il 1/9 Bruno vende a Elio la piena, o la nuda che resta.
    $s = ruScenario('prima_rata', 0, genera: false);
    $bruno = cdcPersona($s, 'Bruno Nudo');
    $elio = cdcPersona($s, 'Elio Compratore');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    cdcTitolare($s['unita'], cdcPersona($s, 'Carla Usufruttuaria'), 'usufruttuario', 50);
    cdcTitolare($s['unita'], $bruno, 'nuda_proprietario', 50);
    cdcPassa($this, $s, $s['unita'], cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $bruno, '2026-01-01', 50), 'usufruttuario'));
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Riserva registrata prima del piano, di prova', esercizio: $s['e']);
    ruEmetti($s, '2026-12-31');
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', cdcRiga($s['unita'], $s['v'], 'usufruttuario'), null, '2026-06-01', 50));
    $riga = $vendeLaPiena ? cdcRiga($s['unita'], $bruno) : cdcRiga($s['unita'], $bruno, 'nuda_proprietario');
    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio($vendeLaPiena ? 'vendita' : 'nuda', $riga, $elio, '2026-09-01', 50));

    // A mano: la riga di Bruno vale 120000 ed è nata sulla nuda 100, metà sotto l'usufrutto di Ugo e metà sotto quello di Carla:
    // la divisione per quota è esatta. A Elio la sua metà per 122 giorni: 120000 × 50/100 × 122/365 = 20054,79 → 20055. Prima la
    // somma fatta prima del piano rendeva la riga indecidibile: con la piena Elio non pagava niente, con la nuda 40110.
    expect(cdcFermo($an))->toBeNull()
        ->and(collect($an['rate']['conguaglio']['coppie'] ?? [])->map(fn ($c) => [$c['entrante_nome'], $c['importo']])->all())->toBe([['Elio Compratore', 20055]]);
})->with(['vende la piena' => true, 'vende la nuda che resta' => false]);

it('rilievo H2 — la nuda sommata con la riserva è venduta intera prima del consolidamento: la riga di Bruno, predecessore, non passa a chi compra la piena; il suo gruppo si ferma e lo dice', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $bruno = cdcPersona($s, 'Bruno Nudo');
    $elio = cdcPersona($s, 'Elio Nudo');
    $fabio = cdcPersona($s, 'Fabio Finale');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    cdcTitolare($s['unita'], cdcPersona($s, 'Carla Usufruttuaria'), 'usufruttuario', 50);
    cdcTitolare($s['unita'], $bruno, 'nuda_proprietario', 50);
    cdcGenera($s);
    ruEmetti($s, '2026-12-31');
    cdcPassa($this, $s, $s['unita'], cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $bruno, '2026-03-01', 50), 'usufruttuario'));
    cdcPassa($this, $s, $s['unita'], ruPassaggio('nuda', cdcRiga($s['unita'], $bruno, 'nuda_proprietario'), $elio, '2026-05-01', 100));
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', cdcRiga($s['unita'], $s['v'], 'usufruttuario'), null, '2026-06-01', 50));
    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $elio), $fabio, '2026-09-01', 50));

    // A mano: a Fabio solo la metà consolidata, quella di Ugo: 60000 × 122/365 = 20054,79 → 20055. La riga di Bruno è la nuda della
    // metà di Carla, che Elio tiene: non passa. Prima Fabio pagava 30082; con la sola fermata sulla riga, le regole del ruolo la
    // facevano passare intera (40110). La frase dice dove le nude si sono sommate: in Bruno, prima della vendita a Elio (rilievo K4).
    expect(cdcFermo($an))->toContain('la nuda proprietà di Elio Nudo viene da quella di Bruno Nudo, nata da una somma il 1 marzo 2026, ed è tornata piena solo in parte con l\'estinzione del 1 giugno 2026')
        ->and(collect($an['rate']['conguaglio']['coppie'] ?? [])->map(fn ($c) => [$c['entrante_nome'], $c['importo']])->all())->toBe([['Fabio Finale', 20055]]);
});

it('rilievo H2, forma B — riserva di Ugo a Fede, poi Carlo vende a Fede la sua nuda, che si somma; l\'estinzione consolida la parte del registro; Fede vende la piena: la riga di Carlo si ferma, e a Elio va la sola metà di Ugo', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $fede = cdcPersona($s, 'Fede Compratrice');
    $elio = cdcPersona($s, 'Elio Finale');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    cdcTitolare($s['unita'], $s['a'], 'usufruttuario', 50);
    $rigaCarlo = cdcTitolare($s['unita'], cdcPersona($s, 'Carlo Nudo'), 'nuda_proprietario', 25);
    cdcTitolare($s['unita'], cdcPersona($s, 'Dora Nuda'), 'nuda_proprietario', 25);
    cdcGenera($s);
    ruEmetti($s, '2026-12-31');
    cdcPassa($this, $s, $s['unita'], cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $fede, '2026-02-01', 50), 'usufruttuario'));
    cdcPassa($this, $s, $s['unita'], ruPassaggio('nuda', $rigaCarlo, $fede, '2026-03-01', 25));
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', cdcRiga($s['unita'], $s['v'], 'usufruttuario'), null, '2026-04-01', 50));
    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $fede), $elio, '2026-09-01', 50));

    // A mano: la metà di Ugo, 60000 × 122/365 = 20054,79 → 20055. Prima Elio pagava 26740, e con la sola fermata sulla riga 30082.
    expect(cdcFermo($an))->toContain('la nuda proprietà di Fede Compratrice, nata da una somma il 1 marzo 2026, è tornata piena solo in parte con l\'estinzione del 1 aprile 2026')
        ->and(collect($an['rate']['conguaglio']['coppie'] ?? [])->map(fn ($c) => [$c['entrante_nome'], $c['importo']])->all())->toBe([['Elio Finale', 20055]]);
});

/** Le coppie dell'anteprima: [chi entra, centesimi]. */
function cdcCoppie(array $an): array
{
    return collect($an['rate']['conguaglio']['coppie'] ?? [])->map(fn ($c) => [$c['entrante_nome'], $c['importo']])->all();
}

/**
 * Decisione 63: l'unità della G2 (Ugo pieno 50, Bruno, Elio, voce € 1.200,00 sul «Proprietario», rate tutte emesse) con la nuda di
 * Bruno che si somma nel modo dato. L'altra metà, censita dal 2019: `piena` (Carla piena 50), `carlo` (Carla usufruttuaria e Carlo
 * nudo) o `bruno` (Carla usufruttuaria e Bruno nudo). Il piano si genera dove dice `$generaDopo` (i passaggi già registrati).
 *
 * @param list<callable(array, array<string, mixed>): array> $passi i passaggi, ciascuno costruito sullo scenario
 */
function cdcSomma($test, array $passi, int $generaDopo, string $altraMeta): array
{
    $s = ruScenario('prima_rata', 0, genera: false);
    $p = ['ugo' => $s['v'], 'bruno' => cdcPersona($s, 'Bruno Nudo'), 'elio' => cdcPersona($s, 'Elio Compratore'), 'carla' => cdcPersona($s, 'Carla Seconda')];
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    cdcTitolare($s['unita'], $p['carla'], $altraMeta === 'piena' ? 'proprietario' : 'usufruttuario', 50);
    if ($altraMeta === 'carlo') {
        $p['rigaCarlo'] = cdcTitolare($s['unita'], cdcPersona($s, 'Carlo Nudo'), 'nuda_proprietario', 50);
    } elseif ($altraMeta === 'bruno') {
        cdcTitolare($s['unita'], $p['bruno'], 'nuda_proprietario', 50);
    }
    foreach ($passi as $i => $passo) {
        if ($i === $generaDopo) {
            app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Passaggi registrati prima del piano, di prova', esercizio: $s['e']);
            ruEmetti($s, '2026-12-31');
        }
        cdcPassa($test, $s, $s['unita'], $passo($s, $p));
    }
    if (count($passi) <= $generaDopo) {
        app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Passaggi registrati prima del piano, di prova', esercizio: $s['e']);
        ruEmetti($s, '2026-12-31');
    }

    return [$s, $p];
}

it('rilievo K1 — Carlo vende la nuda a Bruno prima del piano, e la riserva di Ugo dello stesso giorno, registrata dopo, la somma sul posto: Bruno è chi esce, e il suo gruppo si ferma invece di dividere per quota', function (bool $vendeLaPiena) {
    // Ugo pieno 50, Carla usufruttuaria 50, Carlo nudo 50. 1/3 Carlo vende la nuda a Bruno (registrata prima del piano: le righe
    // di riparto si scrivono per tratti, Bruno 50301 dal 1/3); 1/3 Ugo vende con riserva la nuda della sua metà a Bruno, dopo il
    // piano: la nuda di Bruno nata quel giorno sale da 50 a 100 sul posto, senza frecce. 1/6 muore Ugo (il registro consolida la
    // sua parte); 1/9 Bruno vende a Elio la piena, o la nuda che resta.
    [$s, $p] = cdcSomma($this, [
        fn ($s, $p) => ruPassaggio('nuda', $p['rigaCarlo'], $p['bruno'], '2026-03-01', 50),
        fn ($s, $p) => cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $p['bruno'], '2026-03-01', 50), 'usufruttuario'),
        fn ($s, $p) => ruPassaggio('estinzione', cdcRiga($s['unita'], $p['ugo'], 'usufruttuario'), null, '2026-06-01', 50),
    ], generaDopo: 1, altraMeta: 'carlo');
    $riga = $vendeLaPiena ? cdcRiga($s['unita'], $p['bruno']) : cdcRiga($s['unita'], $p['bruno'], 'nuda_proprietario');
    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio($vendeLaPiena ? 'vendita' : 'nuda', $riga, $p['elio'], '2026-09-01', 50));

    // A mano, il giusto: la riga di Bruno (50301, la nuda della metà di Carla) resta nuda; con la piena a Elio va la sola riga di
    // Ugo per la coppia, 60000 × 122/365 = 20054,79 → 20055; con la nuda, la riga di Bruno intera (50301 × 122/306 = 20055). Prima
    // la somma sul posto non si vedeva e la riga di Bruno si divideva per quota: 30083 con la piena, 10028 con la nuda. Ora il
    // gruppo di Bruno si ferma (decisione 63); con la piena resta la coppia giusta della riga di Ugo.
    expect(cdcFermo($an))->toContain('somma')
        ->and(cdcCoppie($an))->toBe($vendeLaPiena ? [['Elio Compratore', 20055]] : []);
})->with(['vende la piena' => true, 'vende la nuda che resta' => false]);

it('rilievo K1, i due genitori a cavallo del piano — Carla dona con riserva prima della generazione, Ugo lo stesso giorno dopo: muore Carla, e il gruppo di Bruno si ferma', function (bool $vendeLaPiena) {
    [$s, $p] = cdcSomma($this, [
        fn ($s, $p) => cdcConScelta(ruPassaggio('riserva', cdcRiga($s['unita'], $p['carla']), $p['bruno'], '2026-03-01', 50), 'usufruttuario'),
        fn ($s, $p) => cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $p['bruno'], '2026-03-01', 50), 'usufruttuario'),
        fn ($s, $p) => ruPassaggio('estinzione', cdcRiga($s['unita'], $p['carla'], 'usufruttuario'), null, '2026-06-01', 50),
    ], generaDopo: 1, altraMeta: 'piena');
    $riga = $vendeLaPiena ? cdcRiga($s['unita'], $p['bruno']) : cdcRiga($s['unita'], $p['bruno'], 'nuda_proprietario');
    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio($vendeLaPiena ? 'vendita' : 'nuda', $riga, $p['elio'], '2026-09-01', 50));

    // A mano, il giusto: la riga di Bruno è proprio la metà di Carla, tornata piena: con la piena passa intera (20055), con la nuda
    // niente. Prima si divideva per quota: 10028 in tutti e due i versi. Ora il gruppo di Bruno si ferma.
    expect(cdcFermo($an))->toContain('somma')
        ->and(cdcCoppie($an))->toBe([]);
})->with(['vende la piena' => true, 'vende la nuda che resta' => false]);

it('decisione 63, sentinella — i due genitori donano con riserva prima del piano, muore Carla, Bruno vende la piena: la somma è nel riparto, e il conguaglio si calcola', function () {
    [$s, $p] = cdcSomma($this, [
        fn ($s, $p) => cdcConScelta(ruPassaggio('riserva', cdcRiga($s['unita'], $p['carla']), $p['bruno'], '2026-01-01', 50), 'usufruttuario'),
        fn ($s, $p) => cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $p['bruno'], '2026-01-01', 50), 'usufruttuario'),
        fn ($s, $p) => ruPassaggio('estinzione', cdcRiga($s['unita'], $p['carla'], 'usufruttuario'), null, '2026-06-01', 50),
    ], generaDopo: 2, altraMeta: 'piena');
    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $p['bruno']), $p['elio'], '2026-09-01', 50));

    // A mano: il piano nasce sulla nuda 100 di Bruno, metà sotto ciascun usufrutto: la divisione per quota è esatta, 120000 × ½ ×
    // 122/365 = 20054,79 → 20055. Il caso più comune della donazione: non si deve fermare.
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcCoppie($an))->toBe([['Elio Compratore', 20055]]);
});

it('rilievo K1, i due genitori dopo il piano con «come dice ogni voce» — le due riserve dello stesso giorno portano le quote sulla nuda di Bruno, sommata sul posto: muore Carla, e i gruppi si fermano invece di dividere a metà', function () {
    [$s, $p] = cdcSomma($this, [
        fn ($s, $p) => cdcConScelta(ruPassaggio('riserva', cdcRiga($s['unita'], $p['carla']), $p['bruno'], '2026-03-01', 50), 'voce'),
        fn ($s, $p) => cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $p['bruno'], '2026-03-01', 50), 'voce'),
        fn ($s, $p) => ruPassaggio('estinzione', cdcRiga($s['unita'], $p['carla'], 'usufruttuario'), null, '2026-06-01', 50),
    ], generaDopo: 0, altraMeta: 'piena');
    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $p['bruno']), $p['elio'], '2026-09-01', 50));

    // A mano, il giusto: la riga di Carla va intera sulla piena, 60000 × 122/365 = 20055; quella di Ugo resta nuda. Prima le due
    // righe si dividevano a metà (10027 + 10027 = 20054, e le frasi davano a Elio metà della quota di Ugo). Ora si fermano.
    expect(cdcFermo($an))->toContain('somma')
        ->and(cdcCoppie($an))->toBe([]);
});

it('rilievo K2 — muore prima Carla, usufruttuaria censita: torna piena la parte della nuda di Bruno che era sotto di lei, e la riga di Bruno, chi esce, si ferma invece di seguire le regole del ruolo', function (bool $vendeLaPiena) {
    [$s, $p] = cdcSomma($this, [
        fn ($s, $p) => cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $p['bruno'], '2026-03-01', 50), 'usufruttuario'),
        fn ($s, $p) => ruPassaggio('estinzione', cdcRiga($s['unita'], $p['carla'], 'usufruttuario'), null, '2026-05-01', 50),
    ], generaDopo: 0, altraMeta: 'bruno');
    $riga = $vendeLaPiena ? cdcRiga($s['unita'], $p['bruno']) : cdcRiga($s['unita'], $p['bruno'], 'nuda_proprietario');
    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio($vendeLaPiena ? 'vendita' : 'nuda', $riga, $p['elio'], '2026-09-01', 50));

    // A mano, il giusto: la riga di Bruno (60000, la nuda della metà di Carla) è tutta della piena: con la piena passa a Elio,
    // 60000 × 122/365 = 20055; con la nuda, niente. Prima le regole del ruolo davano il contrario: 0 con la piena, 20055 con la
    // nuda. Ora il gruppo di Bruno si ferma.
    expect(cdcFermo($an))->toContain('somma')
        ->and(cdcCoppie($an))->toBe([]);
})->with(['vende la piena' => true, 'vende la nuda che resta' => false]);

it('decisione 63 — la fermata vale per il gruppo da qualunque sua riga venga: la K2 con la voce su due tabelle, e la prima riga di Bruno indecidibile per un\'altra ragione', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $bruno = cdcPersona($s, 'Bruno Nudo');
    $elio = cdcPersona($s, 'Elio Compratore');
    $carla = cdcPersona($s, 'Carla Seconda');
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $rigaCarla = cdcTitolare($s['unita'], $carla, 'usufruttuario', 50);
    cdcTitolare($s['unita'], $bruno, 'nuda_proprietario', 50);
    $conto = (int) DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->value('conti.id');
    DB::table('conto_tabella_millesimale')->where('conto_id', $conto)->update(['coefficiente' => 50]);
    $scale = DB::table('tabelle')->insertGetId(['condominio_id' => $s['c']->id, 'nome' => 'Scale', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('quote_tabella')->insert(['tabella_id' => $scale, 'immobile_id' => $s['unita']->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto, 'tabella_id' => $scale, 'coefficiente' => 50, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    cdcGenera($s);
    ruEmetti($s, '2026-12-31');
    cdcPassa($this, $s, $s['unita'], cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $bruno, '2026-03-01', 50), 'usufruttuario'));
    cdcPassa($this, $s, $s['unita'], ruPassaggio('estinzione', $rigaCarla, null, '2026-05-01', 50));
    // La prima riga di riparto di Bruno legata a una riga che non è sua: la genealogia la dice indecidibile, senza fermata.
    $prima = (int) DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('anagrafica_id', $bruno->id)->min('id');
    DB::table('righe_riparto')->where('id', $prima)->update(['anagrafica_immobile_id' => $rigaCarla]);
    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $bruno), $elio, '2026-09-01', 50));

    // Le due righe di Bruno sono nello stesso gruppo. Se contasse solo la prima riga indecidibile, il gruppo seguirebbe le regole
    // del ruolo, che qui sbagliano (rilievo K2: Bruno terrebbe tutto, il giusto è 20055 a Elio). Si ferma.
    expect(DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('anagrafica_id', $bruno->id)->count())->toBe(2)
        ->and(cdcFermo($an))->toContain('somma')
        ->and(cdcCoppie($an))->toBe([]);
});

it('rilievo Q1 — la G2, e poi Bruno vende con riserva la piena a Elio con la legge: l\'ordinaria resta comunque a chi vende, e il gruppo di Bruno non si ferma per la somma', function () {
    [$s, $p] = cdcSomma($this, [
        fn ($s, $p) => cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $p['bruno'], '2026-03-01', 50), 'usufruttuario'),
        fn ($s, $p) => ruPassaggio('estinzione', cdcRiga($s['unita'], $p['ugo'], 'usufruttuario'), null, '2026-06-01', 50),
    ], generaDopo: 0, altraMeta: 'bruno');
    $an = cdcAnteprima($this, $s, $s['unita'], cdcConScelta(ruPassaggio('riserva', cdcRiga($s['unita'], $p['bruno']), $p['elio'], '2026-09-01', 50), 'usufruttuario'));

    // Con la legge l'ordinaria resta a chi vende con riserva (art. 1004 c.c.), da qualunque pezzo della nuda venga la quota: il
    // programma sa la risposta, niente passa a Elio. Prima della correzione il gruppo di Bruno si fermava per la somma, e il
    // pannello diceva «se serve un conguaglio, si scrive a mano» sotto «l'ordinaria non si conguaglia».
    expect(cdcFermo($an))->toBeNull()
        ->and(cdcCoppie($an))->toBe([])
        ->and(implode(' ', $an['rate']['frasi']))->toContain('resta usufruttuario (art. 1004 c.c.): non passano a Elio Compratore')
        ->not->toContain('nata da una somma');
});

it('decisione 63, sentinella — la G2 con la riserva «come dice ogni voce»: la quota di Ugo arriva sulla nuda sommata, e il conguaglio si ferma (la fermata che la decisione accetta: il calcolo vero è la Coda 225)', function () {
    [$s, $p] = cdcSomma($this, [
        fn ($s, $p) => cdcConScelta(ruPassaggio('riserva', $s['rigaV'], $p['bruno'], '2026-03-01', 50), 'voce'),
        fn ($s, $p) => ruPassaggio('estinzione', cdcRiga($s['unita'], $p['ugo'], 'usufruttuario'), null, '2026-06-01', 50),
    ], generaDopo: 0, altraMeta: 'bruno');
    $an = cdcAnteprima($this, $s, $s['unita'], ruPassaggio('vendita', cdcRiga($s['unita'], $p['bruno']), $p['elio'], '2026-09-01', 50));

    expect(cdcFermo($an))->toContain('somma')
        ->and(cdcCoppie($an))->toBe([]);
});
