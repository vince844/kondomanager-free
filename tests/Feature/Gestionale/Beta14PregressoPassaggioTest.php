<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La issue 58 (Coda 174, decisioni 674 e 685): il pregresso intestato all'unità va a chi ne era titolare il primo giorno del suo
 * esercizio, e dove un passaggio lo tocca l'amministratore può scegliere diversamente.
 *
 * Fino alla 1.12.0-beta.13 `GenerateSaldiAction::risolviTitolari()` prendeva i titolari senza periodo: tutte le righe attive
 * dell'unità, anche quella chiusa da un passaggio. Il caso della decisione 674: l'Interno 1 ha € 180,00 di arretrato all'1/1/2026
 * intestato all'unità; l'1/4/2026 Ugo vende tutto a Elsa; il piano generato a novembre metteva € 90,00 a Ugo e € 90,00 a Elsa, e
 * dopo una vendita con riserva d'usufrutto, sulla straordinaria, tutti i € 180,00 a Elsa. Con la regola: tutti a Ugo.
 *
 * - **Il giorno della regola** è il primo giorno dell'esercizio del saldo (`saldi.esercizio_id`); se quel giorno comincia un
 *   passaggio, il giorno prima (decisione 685 (2): il saldo al primo giorno è quello che si chiude il giorno prima).
 * - **La successione** (decisione 685 (1)): con «l'arretrato agli eredi» il pregresso va agli eredi per le loro quote; con «a nome
 *   del defunto» resta al defunto.
 * - **La scelta dell'amministratore**: l'anteprima della finestra «Riparto saldi iniziali» offre un gruppo di titolari per ogni
 *   giorno che conta (la regola, la data di ogni passaggio nell'arco della solidarietà, il giorno prima di un passaggio che viene
 *   prima della regola); la scelta diversa dalla regola si conserva nel piano come `alla_data`, e un ricalcolo la rifà.
 *
 * - **Il giro di correzioni dopo lo scettico della tappa** (in fondo): la scelta salvata che non vale più (rilievo 2), la successione
 *   nell'anteprima (rilievo 4), ciò che «elimina e crea di nuovo» perde (rilievo 9), le etichette senza genere e la natura della gestione
 *   (rilievi 15 e 16), la finestra chiusa che tiene il riparto a mano (decisione 689 (1), il capo nel server) e la successione prima del
 *   primo giorno con l'arretrato a nome del defunto (decisione 689 (2)).
 * - **Il secondo giro di correzioni** (in fondo): l'esito della voce tolta (rilievo 2 del secondo scettico), l'etichetta della regola con l'erede già
 *   comproprietaria e con il defunto al posto di una parte (rilievo 3), la frase della creazione senza «le stesse cifre» e l'esito del ricalcolo che dice
 *   a chi va ora il pregresso (rilievo 4), l'avviso solo quando il pregresso cambia persona e «ed» davanti a «Erede» (rilievo 7).
 *
 * Gli scenari sono quelli di `Support/ScenariPassaggi.php` (`ruScenario`, `ruPassaggio`, `ruRegistra`), con il saldo dell'unità
 * scritto accanto. Le cifre si rifanno a mano: € 180,00 = 18000 centesimi, a metà 9000.
 *
 * **Cosa NON copre.** I piani già generati con la regola di prima (le quote sono fotografie: si rifanno con «annulla l'emissione,
 * ricalcola, riemetti»); i piani che nascono senza la finestra (l'anno dopo, il ripianamento, «Prepara il piano») prendono la
 * regola senza la scelta, e qui non hanno un caso loro; la finestra a video (le parole e la disposizione si verificano a video e
 * con il vitest della finestra); più di quattro gruppi di titolari nell'arco. La frase della scelta che non vale più
 * (`GenerateSaldiAction::fraseDelleScelteCheNonValgonoPiu()`) qui si legge anche dall'azione: l'esito delle rotte che rigenerano il piano la dice solo
 * quando il pregresso va ad altre persone (`GenerateSaldiAction::fraseDelPregressoCambiato()`).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Actions\PianoRate\GenerateSaldiAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use App\Models\User;
use App\Services\Subentro\FrasiObbligati;
use App\Services\Subentro\NotaSolidarieta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Lo scenario di `ruScenario` (Ugo proprietario pieno dal 2019, il piano 2026 da generare) con € 180,00 di arretrato intestato all'unità. */
function b14pScenario(string $natura = 'ordinaria'): array
{
    $s = $natura === 'ordinaria'
        ? ruScenario('tutte_rate', 0, genera: false)
        : ruScenario('tutte_rate', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6, genera: false);
    $s['saldo'] = Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => null, 'immobile_id' => $s['unita']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 18000, 'origine' => 'manuale', 'is_applicato' => false]);

    return $s;
}

/** Un passaggio della griglia registrato dalla rotta, con la spunta del cancello. */
function b14pPassaggio($test, array $s, string $tipo, string $dal, array $extra = []): \App\Models\Gestionale\Subentro
{
    return ruRegistra($test, $s, array_replace(ruPassaggio($tipo, $s['rigaV'], $s['a'], $dal, 100), ['ho_letto' => true, 'nota_cancello' => 'Rogito letto, passaggio registrato'], $extra));
}

/** La generazione del piano dello scenario, come la fa la creazione (o il ricalcolo, senza configurazione). */
function b14pGenera(array $s, array $config = []): void
{
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, saldiConfig: $config, accettaDestinatari: true,
        notaDestinatari: 'Letto: passaggio nel 2026', esercizio: $s['e']);
}

/** Il pregresso dell'unità nelle quote del piano, per persona: la somma di `saldo_usato` delle regole congelate. */
function b14pPregresso(PianoRate $piano, int $anagraficaId): int
{
    return (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->where('rate_quote.anagrafica_id', $anagraficaId)->pluck('rate_quote.regole_calcolo')
        ->sum(fn ($r) => (int) (json_decode((string) $r, true)['importi']['saldo_usato'] ?? 0));
}

/** Le voci `dettagli_saldo` delle quote di una persona nel piano (una per quota che porta il saldo). */
function b14pDettagli(PianoRate $piano, int $anagraficaId): array
{
    return DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->where('rate_quote.anagrafica_id', $anagraficaId)->pluck('rate_quote.regole_calcolo')
        ->flatMap(fn ($r) => json_decode((string) $r, true)['dettagli_saldo'] ?? [])->values()->all();
}

/** L'anteprima del riparto di un saldo, come la legge la finestra (dalla rotta `fetch-saldi-analitici`). */
function b14pAnteprima($test, array $s): array
{
    $righe = $test->actingAs($test->user)->getJson(route('admin.gestionale.fetch-saldi-analitici', [$s['c'], $s['e'], $s['g']]))->assertOk()->json();

    return collect($righe)->firstWhere('id', $s['saldo']->id)['riparto_previsto'];
}

/** Il pregresso della scelta per persona, dall'anteprima: [nome => centesimi]. */
function b14pScelta(array $scelta): array
{
    return collect($scelta['quote'])->mapWithKeys(fn ($q) => [$q['nome'] => $q['importo']])->all();
}

it('1 — vendita piena dell\'1/4, € 180,00 dell\'1/1 sull\'ordinaria: la generazione dà tutto a Ugo, titolare il primo giorno dell\'esercizio, e niente a Elsa (prima € 90,00 a testa)', function () {
    $s = b14pScenario();
    b14pPassaggio($this, $s, 'vendita', '2026-04-01');

    b14pGenera($s);

    expect(['Ugo' => b14pPregresso($s['piano'], $s['v']->id), 'Elsa' => b14pPregresso($s['piano'], $s['a']->id)])->toBe(['Ugo' => 18000, 'Elsa' => 0]);
    // In ogni quota il giorno usato e che è la regola.
    expect(collect(b14pDettagli($s['piano'], $s['v']->id))->map(fn ($m) => [$m['riferito_al'] ?? null, $m['scelta'] ?? null])->unique()->values()->all())
        ->toBe([['2026-01-01', 'regola']]);
});

it('2 — vendita con riserva d\'usufrutto dell\'1/5 sulla straordinaria: tutto a Ugo, proprietario pieno l\'1/1 (prima tutto a Elsa, nuda proprietaria)', function () {
    $s = b14pScenario('straordinaria');
    b14pPassaggio($this, $s, 'riserva', '2026-05-01');

    b14pGenera($s);

    expect(['Ugo' => b14pPregresso($s['piano'], $s['v']->id), 'Elsa' => b14pPregresso($s['piano'], $s['a']->id)])->toBe(['Ugo' => 18000, 'Elsa' => 0]);
});

it('3 — vendita del 15/12/2025, saldo del 2026: la regola dà tutto a Elsa, titolare l\'1/1; l\'anteprima offre due scelte, Elsa come regola e Ugo fino al 14/12/2025', function () {
    $s = b14pScenario();
    b14pPassaggio($this, $s, 'vendita', '2025-12-15');

    $anteprima = b14pAnteprima($this, $s);
    expect($anteprima['riferito_al'])->toBe('2026-01-01')
        ->and($anteprima['passaggi'])->toHaveCount(1)
        ->and(collect($anteprima['scelte'])->map(fn ($c) => [$c['alla_data'], $c['regola'], b14pScelta($c)])->all())->toBe([
            ['2026-01-01', true, ['Acquirente Elsa' => 18000]],
            ['2025-12-14', false, ['Venditore Ugo' => 18000]],
        ])
        ->and($anteprima['scelte'][1]['etichetta'])->toBe('titolare fino al 14 dicembre 2025');

    b14pGenera($s);
    expect(['Ugo' => b14pPregresso($s['piano'], $s['v']->id), 'Elsa' => b14pPregresso($s['piano'], $s['a']->id)])->toBe(['Ugo' => 0, 'Elsa' => 18000]);
});

it('4 — la scelta «Elsa» dalla rotta di creazione: il piano la conserva come giorno dei titolari, la generazione e il ricalcolo danno tutto a Elsa; un giorno che la finestra non offre si rifiuta', function () {
    $s = b14pScenario();
    b14pPassaggio($this, $s, 'vendita', '2026-04-01');
    DB::table('piani_rate')->where('id', $s['piano']->id)->delete();
    $corpo = fn (string $giorno, string $nome) => [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2026-12-05', 'genera_subito' => true, 'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: vendita del 1 aprile',
        'saldi_config' => [['saldo_id' => $s['saldo']->id, 'alla_data' => $giorno]],
    ];

    // Un giorno che la finestra non offre: la regola (1/1) e la vendita (1/4) sì, il 15/3 no.
    $rifiuto = $this->actingAs($this->user)->postJson(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), $corpo('2026-03-15', 'Preventivo con un giorno qualunque'));
    expect($rifiuto->status())->toBe(422)
        ->and(array_keys($rifiuto->json('errors')))->toContain('saldi_config.0.alla_data')
        ->and(PianoRate::where('nome', 'Preventivo con un giorno qualunque')->exists())->toBeFalse();

    $this->actingAs($this->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), $corpo('2026-04-01', 'Preventivo 2026 bis'))
        ->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Preventivo 2026 bis')->firstOrFail();

    // Con il giorno, le persone che la finestra mostrava (rilievo 2 dello scettico): il ricalcolo rifà la scelta solo se sono ancora loro.
    expect($piano->saldi_config)->toBe([['saldo_id' => $s['saldo']->id, 'alla_data' => '2026-04-01', 'titolari' => [$s['a']->id]]])
        ->and(['Ugo' => b14pPregresso($piano, $s['v']->id), 'Elsa' => b14pPregresso($piano, $s['a']->id)])->toBe(['Ugo' => 0, 'Elsa' => 18000])
        ->and(collect(b14pDettagli($piano, $s['a']->id))->map(fn ($m) => [$m['riferito_al'] ?? null, $m['scelta'] ?? null])->unique()->values()->all())
            ->toBe([['2026-04-01', 'amministratore']]);

    // «Ricalcola» dalla rotta: la scelta resta.
    $this->actingAs($this->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo dopo la vendita',
    ])->assertSessionHasNoErrors();
    expect(['Ugo' => b14pPregresso($piano, $s['v']->id), 'Elsa' => b14pPregresso($piano, $s['a']->id)])->toBe(['Ugo' => 0, 'Elsa' => 18000]);
});

it('5 — l\'unità diventata mista con una riserva su metà dopo l\'1/1: il pregresso va a chi era titolare l\'1/1, Ugo e Bice per le loro metà, senza superare il saldo (prima tutto a Ugo usufruttuario)', function (string $natura) {
    $s = $natura === 'ordinaria'
        ? ruScenario('prima_rata', 0, genera: false)
        : ruScenario('prima_rata', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $bice = Anagrafica::forceCreate(['nome' => 'Comproprietaria Bice', 'email' => "b14p-bi{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'B14PBICECOM' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $bice->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $bice->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    ruPianoInBozza($s);
    ruRegistra($this, $s, ruRiserva($s, extra: ['quota' => 50]));
    $s['saldo'] = Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => null, 'immobile_id' => $s['unita']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 18000, 'origine' => 'manuale', 'is_applicato' => false]);

    b14pGenera($s);

    expect(['Ugo' => b14pPregresso($s['piano'], $s['v']->id), 'Bice' => b14pPregresso($s['piano'], $bice->id), 'Elsa' => b14pPregresso($s['piano'], $s['a']->id)])
        ->toBe(['Ugo' => 9000, 'Bice' => 9000, 'Elsa' => 0]);
})->with(['gestione ordinaria' => ['ordinaria'], 'gestione straordinaria' => ['straordinaria']]);

it('6 — vende e ricompra: Ugo fino al 30/04, Elsa dal 1/05 al 31/08, Ugo dal 1/09; l\'1/1 il titolare è Ugo, che prende tutto il pregresso (prima € 666,67 e € 333,33)', function () {
    $s = umSaldoUnitaMistaB14p([
        ['U', 'proprietario', 100, '2019-01-01', '2026-04-30'], ['E', 'proprietario', 100, '2026-05-01', '2026-08-31'], ['U', 'proprietario', 100, '2026-09-01'],
    ]);
    $distribuzione = app(GenerateSaldiAction::class)->execute($s->piano, $s->gestione);

    expect($distribuzione[$s->persone['U']->id][$s->immobile->id]['importo'])->toBe(100000)
        ->and($distribuzione)->not->toHaveKey($s->persone['E']->id);
});

it('7 — nessun passaggio nell\'arco della solidarietà (la vendita è del 2023): l\'anteprima è quella di sempre, senza scelte, e le cifre non cambiano', function () {
    $s = b14pScenario();
    b14pPassaggio($this, $s, 'vendita', '2023-06-01');

    $anteprima = b14pAnteprima($this, $s);
    expect($anteprima['passaggi'])->toBe([])
        ->and($anteprima['scelte'])->toBe([])
        ->and(b14pScelta($anteprima))->toBe(['Acquirente Elsa' => 18000]);

    b14pGenera($s);
    expect(['Ugo' => b14pPregresso($s['piano'], $s['v']->id), 'Elsa' => b14pPregresso($s['piano'], $s['a']->id)])->toBe(['Ugo' => 0, 'Elsa' => 18000]);
});

it('8 — le frasi: con un saldo dell\'unità l\'anteprima del passaggio, lo storico e la nota di solidarietà dicono la regola (a chi era titolare il primo giorno del suo esercizio, salvo una scelta nel piano); senza, la frase resta quella di prima', function (string $forma, string $senza, string $conAnteprima, string $conStorico, string $nellaNota) {
    $s = ruScenario('tutte_rate', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6, genera: false);
    $dati = ($forma === 'riserva' ? ruPassaggio('riserva', $s['rigaV'], $s['a'], '2026-05-01', 100) : ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-05-01', 100))
        + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto, passaggio del 1 maggio'];
    $frasi = fn () => implode("\n", ruAnteprima($this, $s, $dati)['obbligati']['frasi']);
    $nota = fn () => app(NotaSolidarieta::class)->per($s['c'], $s['a'])[0]['testo'] ?? '';

    expect($frasi())->toContain($senza)->not->toContain('saldi intestati all\'unità');
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => null, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 18000, 'origine' => 'manuale', 'is_applicato' => false]);
    expect($frasi())->toContain($conAnteprima);

    $subentro = ruRegistra($this, $s, $dati);
    expect(implode("\n", app(FrasiObbligati::class)->daSubentro($subentro)))->toContain($conStorico)->not->toContain('una tua scelta')
        ->and($nota())->toContain($nellaNota);
})->with([
    'vendita piena' => ['vendita',
        'Il programma non intesta nulla ad Acquirente Elsa: la nota resta qui',
        'Il programma non intesta nulla ad Acquirente Elsa, e i saldi intestati all\'unità restano a chi ne era titolare il primo giorno del loro esercizio, salvo una tua scelta diversa nel piano rate: la nota resta qui',
        'Il programma non intesta nulla ad Acquirente Elsa, e i saldi intestati all\'unità restano a chi ne era titolare il primo giorno del loro esercizio, salvo una scelta diversa nel piano rate: la nota resta qui',
        'Nessuna quota è intestata ad Acquirente Elsa per quel periodo, e i saldi intestati all\'unità restano a chi ne era titolare il primo giorno del loro esercizio, salvo una scelta diversa nel piano rate: '],
    'vendita con riserva d\'usufrutto' => ['riserva',
        'Il programma non intesta nulla ad Acquirente Elsa per quel periodo.',
        'Il programma non intesta nulla ad Acquirente Elsa per quel periodo, e i saldi intestati all\'unità restano a chi ne era titolare il primo giorno del loro esercizio, salvo una tua scelta diversa nel piano rate.',
        'Il programma non intesta nulla ad Acquirente Elsa per quel periodo, e i saldi intestati all\'unità restano a chi ne era titolare il primo giorno del loro esercizio, salvo una scelta diversa nel piano rate.',
        'Nessuna quota è intestata ad Acquirente Elsa per quel periodo, e i saldi intestati all\'unità restano a chi ne era titolare il primo giorno del loro esercizio, salvo una scelta diversa nel piano rate: '],
]);

it('D2 — la vendita datata il primo giorno dell\'esercizio: il saldo all\'1/1 è quello che si chiude il 31/12, e va a Ugo, titolare il giorno prima; la finestra offre anche Elsa', function () {
    $s = b14pScenario();
    b14pPassaggio($this, $s, 'vendita', '2026-01-01');

    $anteprima = b14pAnteprima($this, $s);
    expect($anteprima['titolari_al'])->toBe('2025-12-31')
        ->and(collect($anteprima['scelte'])->map(fn ($c) => [$c['alla_data'], $c['regola'], b14pScelta($c)])->all())->toBe([
            ['2025-12-31', true, ['Venditore Ugo' => 18000]],
            ['2026-01-01', false, ['Acquirente Elsa' => 18000]],
        ]);

    b14pGenera($s);
    expect(['Ugo' => b14pPregresso($s['piano'], $s['v']->id), 'Elsa' => b14pPregresso($s['piano'], $s['a']->id)])->toBe(['Ugo' => 18000, 'Elsa' => 0]);
});

it('D1 — la successione dell\'1/4: con «l\'arretrato agli eredi» il pregresso va ad Anna e Bruno per le loro quote; con «a nome del defunto» resta a Ugo', function (string $arretrato, array $attesi) {
    $s = b14pScenario();
    $bruno = Anagrafica::forceCreate(['nome' => 'Erede Bruno', 'email' => "b14p-br{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'B14PBRUNOER' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $bruno->condomini()->syncWithoutDetaching([$s['c']->id]);
    $dati = ruPassaggio('successione', $s['rigaV'], $s['a'], '2026-04-01', 100);
    $dati['eredi'] = [['anagrafica_id' => $s['a']->id, 'quota' => 50], ['anagrafica_id' => $bruno->id, 'quota' => 50]];
    $dati['erede_di_riferimento'] = $s['a']->id;
    $dati['arretrato'] = $arretrato;
    if ($arretrato === 'defunto') {
        $dati['conguaglio'] = 'scrivi';
    }
    ruRegistra($this, $s, $dati + ['ho_letto' => true, 'nota_cancello' => 'Successione letta']);

    b14pGenera($s);

    expect(['Ugo' => b14pPregresso($s['piano'], $s['v']->id), 'Elsa' => b14pPregresso($s['piano'], $s['a']->id), 'Bruno' => b14pPregresso($s['piano'], $bruno->id)])->toBe($attesi);
})->with([
    'l\'arretrato agli eredi' => ['eredi', ['Ugo' => 0, 'Elsa' => 9000, 'Bruno' => 9000]],
    'l\'arretrato a nome del defunto' => ['defunto', ['Ugo' => 18000, 'Elsa' => 0, 'Bruno' => 0]],
]);

it('la catena da capo a capo — il saldo dell\'unità dal Wallet, la vendita dalla rotta, il piano creato con la regola: Ugo ha € 180,00 e Elsa niente, nelle quote e nell\'estratto conto; la nota di solidarietà dice la regola; un secondo piano con la scelta «Elsa» li dà a lei', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    DB::table('piani_rate')->where('id', $s['piano']->id)->delete();

    // Il Wallet: il saldo dell'intero immobile, dalla porta.
    $this->actingAs($this->user)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => null, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 18000,
    ])->assertSessionHasNoErrors();
    $s['saldo'] = Saldo::where('immobile_id', $s['unita']->id)->whereNull('anagrafica_id')->firstOrFail();

    // La vendita dalla rotta.
    b14pPassaggio($this, $s, 'vendita', '2026-04-01');

    // La finestra: tocca un passaggio, la regola è Ugo.
    $anteprima = b14pAnteprima($this, $s);
    expect($anteprima['scelte'][0]['regola'])->toBeTrue()->and(b14pScelta($anteprima['scelte'][0]))->toBe(['Venditore Ugo' => 18000])
        ->and(b14pScelta($anteprima['scelte'][1]))->toBe(['Acquirente Elsa' => 18000]);

    // Il piano creato con la regola (senza configurazione), generato subito.
    $corpo = fn (string $nome, array $config) => ['tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-12-05', 'genera_subito' => true, 'accetta_destinatari' => true,
        'nota_destinatari' => 'Letto: vendita del 1 aprile'] + ($config === [] ? [] : ['saldi_config' => $config]);
    $this->actingAs($this->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), $corpo('Preventivo 2026', []))->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Preventivo 2026')->firstOrFail();
    expect(['Ugo' => b14pPregresso($piano, $s['v']->id), 'Elsa' => b14pPregresso($piano, $s['a']->id)])->toBe(['Ugo' => 18000, 'Elsa' => 0]);

    // L'emissione dalla rotta, e l'estratto conto: il pregresso del piano entra nel saldo iniziale della persona (`saldo_usato` delle sue
    // quote, `EstrattoContoDellaPersona::saldoIniziale`), € 180,00 in quello di Ugo e niente in quello di Elsa.
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), [
        'rate_ids' => DB::table('rate')->where('piano_rate_id', $piano->id)->pluck('id')->all(), 'data_emissione' => '2026-11-20', 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors();
    $saldoIniziale = fn ($chi) => app(\App\Services\Gestionale\EstrattoContoDellaPersona::class)->ledger($s['c'], $s['e']->fresh(), $chi)[2];
    expect(['Ugo' => $saldoIniziale($s['v']), 'Elsa' => $saldoIniziale($s['a'])])->toBe(['Ugo' => 18000, 'Elsa' => 0]);

    // La nota di solidarietà dice la regola.
    expect(app(NotaSolidarieta::class)->per($s['c'], $s['a'])[0]['testo'] ?? '')
        ->toContain('i saldi intestati all\'unità restano a chi ne era titolare il primo giorno del loro esercizio');

    // Un secondo saldo dell'unità, una voce nuova della gestione, e un integrativo con la scelta «Elsa» (nel rogito si è accollata
    // l'arretrato): il pregresso va a lei, e lo dice il suo estratto conto dopo l'emissione.
    $secondo = Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => null, 'immobile_id' => $s['unita']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 6000, 'origine' => 'manuale', 'is_applicato' => false]);
    $voce = \App\Models\Gestionale\Conto::create(['piano_conto_id' => \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->value('id'), 'nome' => 'Integrazione',
        'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 10000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voce->id, 'tabella_id' => \App\Models\Tabella::where('condominio_id', $s['c']->id)->value('id'), 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), $corpo('Integrativo 2026', [['saldo_id' => $secondo->id, 'alla_data' => '2026-04-01']]))
        ->assertSessionHasNoErrors();
    $integrativo = PianoRate::where('nome', 'Integrativo 2026')->firstOrFail();
    expect(['Ugo' => b14pPregresso($integrativo, $s['v']->id), 'Elsa' => b14pPregresso($integrativo, $s['a']->id)])->toBe(['Ugo' => 0, 'Elsa' => 6000]);
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $integrativo]), [
        'rate_ids' => DB::table('rate')->where('piano_rate_id', $integrativo->id)->pluck('id')->all(), 'data_emissione' => '2026-11-20', 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors();
    expect(['Ugo' => $saldoIniziale($s['v']), 'Elsa' => $saldoIniziale($s['a'])])->toBe(['Ugo' => 18000, 'Elsa' => 6000]);
});

// ── Il giro di correzioni dopo lo scettico della tappa (rilievi 2, 4, 9, 15, 16) ─────────────────────────────────────────────────

/** Una persona del condominio dello scenario. */
function b14pPersona(array $s, string $nome, string $sigla): Anagrafica
{
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => "b14p-{$sigla}{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'B14P' . strtoupper($sigla) . 'PERSON' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** Il piano «Preventivo 2026 bis» creato dalla rotta, generato subito, con la scelta del giorno dei titolari per il saldo dell'unità. */
function b14pCreaConScelta($test, array $s, string $giorno, array $inPiu = []): PianoRate
{
    DB::table('piani_rate')->where('id', $s['piano']->id)->delete();
    $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2026 bis', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-12-05', 'genera_subito' => true, 'accetta_destinatari' => true,
        'nota_destinatari' => 'Letto: il rogito dice che l\'arretrato lo paga chi compra',
        'saldi_config' => [['saldo_id' => $s['saldo']->id, 'alla_data' => $giorno] + $inPiu],
    ])->assertSessionHasNoErrors();

    return PianoRate::where('nome', 'Preventivo 2026 bis')->firstOrFail();
}

/** L'annullamento del passaggio dalla rotta («registrato per sbaglio»): torna indietro, e il passaggio è annullato. */
function b14pAnnulla($test, array $s, \App\Models\Gestionale\Subentro $passaggio): void
{
    ruAnnulla($test, $s, $passaggio)->assertStatus(302);
    expect(\App\Models\Gestionale\Subentro::conAnnullati()->find($passaggio->id)?->annullato_il)->not->toBeNull();
}

/** «Ricalcola» dalla rotta, con la presa d'atto dei destinatari: l'esito del ricalcolo (il messaggio della sessione). */
function b14pRicalcola($test, array $s, PianoRate $piano): ?array
{
    $r = $test->actingAs($test->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: ricalcolo dopo il passaggio corretto',
    ]);

    return $r->getSession()->get('message');
}

/** Il giorno e chi l'ha deciso nelle quote di una persona, e la scelta di prima se non vale più: una voce per forma diversa. */
function b14pComeDeciso(PianoRate $piano, int $anagraficaId): array
{
    return collect(b14pDettagli($piano, $anagraficaId))
        ->map(fn ($m) => [$m['riferito_al'] ?? null, $m['scelta'] ?? null, $m['scelta_di_prima']['alla_data'] ?? null])->unique()->values()->all();
}

it('rilievo 2 — la scelta «Elsa dal 1/4» conserva anche le persone scelte, dal server: quelle che manda il browser non contano', function () {
    $s = b14pScenario();
    $carla = b14pPersona($s, 'Acquirente Carla', 'ca');
    b14pPassaggio($this, $s, 'vendita', '2026-04-01');

    $piano = b14pCreaConScelta($this, $s, '2026-04-01', ['titolari' => [$carla->id]]);

    expect($piano->saldi_config)->toBe([['saldo_id' => $s['saldo']->id, 'alla_data' => '2026-04-01', 'titolari' => [$s['a']->id]]]);
});

it('rilievo 2 — la scelta «Elsa dal 1/4» salvata, poi la vendita annullata (registrata per sbaglio): il ricalcolo torna alla regola, Ugo, e nelle quote la scelta è della regola, con la scelta di prima che non vale più', function () {
    $s = b14pScenario();
    $vendita = b14pPassaggio($this, $s, 'vendita', '2026-04-01');
    $piano = b14pCreaConScelta($this, $s, '2026-04-01');
    expect(b14pPregresso($piano, $s['a']->id))->toBe(18000);

    b14pAnnulla($this, $s, $vendita);
    $esito = b14pRicalcola($this, $s, $piano);

    expect($esito['type'] ?? null)->toBe('success')
        ->and(['Ugo' => b14pPregresso($piano, $s['v']->id), 'Elsa' => b14pPregresso($piano, $s['a']->id)])->toBe(['Ugo' => 18000, 'Elsa' => 0])
        ->and(b14pComeDeciso($piano, $s['v']->id))->toBe([['2026-01-01', 'regola', '2026-04-01']])
        ->and(app(GenerateSaldiAction::class)->fraseDelleScelteCheNonValgonoPiu($piano->fresh()))
            ->toBe('La scelta di prima per il pregresso di Interno 1 (Acquirente Elsa, titolare il 1 aprile 2026) non vale più: il passaggio dell\'1 aprile 2026 è stato annullato. Il pregresso torna alla regola: va a Venditore Ugo.');
});

it('rilievo 2 — la vendita a Elsa dell\'1/4 annullata e registrata quella giusta, a Carla il 15/3: il ricalcolo non dà il pregresso a Carla, che nessuno ha scelto, ma a Ugo con la regola (S-A5b)', function () {
    $s = b14pScenario();
    $vendita = b14pPassaggio($this, $s, 'vendita', '2026-04-01');
    $piano = b14pCreaConScelta($this, $s, '2026-04-01');

    b14pAnnulla($this, $s, $vendita);
    $carla = b14pPersona($s, 'Acquirente Carla', 'ca');
    $rigaUgo = DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['v']->id)->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, ruPassaggio('vendita', $rigaUgo, $carla, '2026-03-15', 100) + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto: la vendita giusta']);
    b14pRicalcola($this, $s, $piano);

    expect(['Ugo' => b14pPregresso($piano, $s['v']->id), 'Elsa' => b14pPregresso($piano, $s['a']->id), 'Carla' => b14pPregresso($piano, $carla->id)])
        ->toBe(['Ugo' => 18000, 'Elsa' => 0, 'Carla' => 0])
        ->and(b14pComeDeciso($piano, $s['v']->id))->toBe([['2026-01-01', 'regola', '2026-04-01']])
        ->and(app(GenerateSaldiAction::class)->fraseDelleScelteCheNonValgonoPiu($piano->fresh()))->toContain('il passaggio dell\'1 aprile 2026 è stato annullato');
});

it('rilievo 2 — la vendita a Elsa dell\'1/4 annullata e registrata di nuovo lo stesso giorno a Carla: il giorno c\'è ancora, ma le persone no; il ricalcolo torna alla regola e lo dice', function () {
    $s = b14pScenario();
    $vendita = b14pPassaggio($this, $s, 'vendita', '2026-04-01');
    $piano = b14pCreaConScelta($this, $s, '2026-04-01');

    b14pAnnulla($this, $s, $vendita);
    $carla = b14pPersona($s, 'Acquirente Carla', 'ca');
    $rigaUgo = DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['v']->id)->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, ruPassaggio('vendita', $rigaUgo, $carla, '2026-04-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto: a comprare è Carla']);
    expect(app(GenerateSaldiAction::class)->giorniOfferti($s['saldo']->fresh()))->toContain('2026-04-01');
    b14pRicalcola($this, $s, $piano);

    expect(['Ugo' => b14pPregresso($piano, $s['v']->id), 'Carla' => b14pPregresso($piano, $carla->id)])->toBe(['Ugo' => 18000, 'Carla' => 0])
        ->and(b14pComeDeciso($piano, $s['v']->id))->toBe([['2026-01-01', 'regola', '2026-04-01']])
        ->and(app(GenerateSaldiAction::class)->fraseDelleScelteCheNonValgonoPiu($piano->fresh()))
            ->toBe('La scelta di prima per il pregresso di Interno 1 (Acquirente Elsa, titolare il 1 aprile 2026) non vale più: il passaggio dell\'1 aprile 2026 è stato annullato, e quel giorno ora è titolare Acquirente Carla. Il pregresso torna alla regola: va a Venditore Ugo.');
});

it('rilievo 2 — la vendita a Elsa annullata e registrata di nuovo lo stesso giorno a Carla: l\'esito del ricalcolo dice che la scelta di prima non vale più', function () {
    $s = b14pScenario();
    $vendita = b14pPassaggio($this, $s, 'vendita', '2026-04-01');
    $piano = b14pCreaConScelta($this, $s, '2026-04-01');

    b14pAnnulla($this, $s, $vendita);
    $carla = b14pPersona($s, 'Acquirente Carla', 'ca');
    $rigaUgo = DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['v']->id)->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, ruPassaggio('vendita', $rigaUgo, $carla, '2026-04-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Rogito letto: a comprare è Carla']);
    $esito = b14pRicalcola($this, $s, $piano);

    expect($esito['type'] ?? null)->toBe('success')
        ->and($esito['message'] ?? '')->toContain('Piano rate ricalcolato con successo!')
        ->and($esito['message'] ?? '')->toContain('La scelta di prima per il pregresso di Interno 1 (Acquirente Elsa, titolare il 1 aprile 2026) non vale più');
});

it('rilievo 2 — la scelta che vale ancora: l\'esito del ricalcolo non ha la frase', function () {
    $s = b14pScenario();
    b14pPassaggio($this, $s, 'vendita', '2026-04-01');
    $piano = b14pCreaConScelta($this, $s, '2026-04-01');

    $esito = b14pRicalcola($this, $s, $piano);

    expect($esito['message'] ?? '')->not->toContain('non vale più');
});

it('rilievo 2 — la scelta che vale ancora non ha frasi: il ricalcolo la rifà, e la scelta resta dell\'amministratore', function () {
    $s = b14pScenario();
    b14pPassaggio($this, $s, 'vendita', '2026-04-01');
    $piano = b14pCreaConScelta($this, $s, '2026-04-01');

    b14pRicalcola($this, $s, $piano);

    expect(b14pPregresso($piano, $s['a']->id))->toBe(18000)
        ->and(b14pComeDeciso($piano, $s['a']->id))->toBe([['2026-04-01', 'amministratore', null]])
        ->and(app(GenerateSaldiAction::class)->fraseDelleScelteCheNonValgonoPiu($piano->fresh()))->toBeNull();
});

it('rilievo 4 — la successione dell\'1/4 con «l\'arretrato agli eredi»: l\'anteprima dice che il titolare del primo giorno era Ugo e che l\'arretrato è passato agli eredi, e la regola si chiama «eredi di Ugo»; con «a nome del defunto» niente di questo', function (string $arretrato, ?array $successione, string $etichetta) {
    $s = b14pScenario();
    $bruno = b14pPersona($s, 'Erede Bruno', 'br');
    $dati = ruPassaggio('successione', $s['rigaV'], $s['a'], '2026-04-01', 100);
    $dati['eredi'] = [['anagrafica_id' => $s['a']->id, 'quota' => 50], ['anagrafica_id' => $bruno->id, 'quota' => 50]];
    $dati['erede_di_riferimento'] = $s['a']->id;
    $dati['arretrato'] = $arretrato;
    if ($arretrato === 'defunto') {
        $dati['conguaglio'] = 'scrivi';
    }
    ruRegistra($this, $s, $dati + ['ho_letto' => true, 'nota_cancello' => 'Successione letta']);

    $anteprima = b14pAnteprima($this, $s);

    expect($anteprima['successione'])->toBe($successione)
        ->and($anteprima['scelte'][0]['regola'])->toBeTrue()
        ->and($anteprima['scelte'][0]['etichetta'])->toBe($etichetta);
})->with([
    'l\'arretrato agli eredi' => ['eredi', ['defunti' => ['Venditore Ugo'], 'data' => '2026-04-01', 'titolari' => ['Venditore Ugo'], 'arretrato' => 'eredi'], 'eredi di Venditore Ugo · la regola'],
    'l\'arretrato a nome del defunto' => ['defunto', null, 'titolare al 1 gennaio 2026 · la regola'],
]);

it('decisione 689 (2) — Ugo muore il 15/12/2025, prima del primo giorno dell\'esercizio; con «l\'arretrato a nome del defunto» i € 180,00 all\'1/1/2026 vanno a Ugo, e la finestra offre gli eredi; con «agli eredi» restano agli eredi', function (string $arretrato, array $attesi, array $scelte, ?array $successione) {
    $s = b14pScenario();
    $bruno = b14pPersona($s, 'Erede Bruno', 'br');
    $dati = ruPassaggio('successione', $s['rigaV'], $s['a'], '2025-12-15', 100);
    $dati['eredi'] = [['anagrafica_id' => $s['a']->id, 'quota' => 50], ['anagrafica_id' => $bruno->id, 'quota' => 50]];
    $dati['erede_di_riferimento'] = $s['a']->id;
    $dati['arretrato'] = $arretrato;
    if ($arretrato === 'defunto') {
        $dati['conguaglio'] = 'scrivi';
    }
    ruRegistra($this, $s, $dati + ['ho_letto' => true, 'nota_cancello' => 'Successione letta']);

    $anteprima = b14pAnteprima($this, $s);
    expect(collect($anteprima['scelte'])->map(fn ($c) => [$c['alla_data'], $c['regola'], $c['etichetta'], b14pScelta($c)])->all())->toBe($scelte)
        ->and($anteprima['successione'])->toBe($successione);

    b14pGenera($s);
    expect(['Ugo' => b14pPregresso($s['piano'], $s['v']->id), 'Elsa' => b14pPregresso($s['piano'], $s['a']->id), 'Bruno' => b14pPregresso($s['piano'], $bruno->id)])->toBe($attesi);
})->with([
    'l\'arretrato a nome del defunto' => ['defunto', ['Ugo' => 18000, 'Elsa' => 0, 'Bruno' => 0],
        [['2026-01-01', true, 'a nome del defunto · la regola', ['Venditore Ugo' => 18000]], ['2025-12-15', false, 'titolari dal 15 dicembre 2025', ['Acquirente Elsa' => 9000, 'Erede Bruno' => 9000]]],
        ['defunti' => ['Venditore Ugo'], 'data' => '2025-12-15', 'titolari' => ['Acquirente Elsa', 'Erede Bruno'], 'arretrato' => 'defunto']],
    'l\'arretrato agli eredi' => ['eredi', ['Ugo' => 0, 'Elsa' => 9000, 'Bruno' => 9000],
        [['2026-01-01', true, 'titolari al 1 gennaio 2026 · la regola', ['Acquirente Elsa' => 9000, 'Erede Bruno' => 9000]]],
        null],
]);

it('decisione 689 (2) — con l\'arretrato a nome del defunto l\'amministratore sceglie gli eredi dalla rotta di creazione: il pregresso va a loro, per le loro quote', function () {
    $s = b14pScenario();
    $bruno = b14pPersona($s, 'Erede Bruno', 'br');
    $dati = ruPassaggio('successione', $s['rigaV'], $s['a'], '2025-12-15', 100);
    $dati['eredi'] = [['anagrafica_id' => $s['a']->id, 'quota' => 50], ['anagrafica_id' => $bruno->id, 'quota' => 50]];
    $dati['erede_di_riferimento'] = $s['a']->id;
    $dati['arretrato'] = 'defunto';
    $dati['conguaglio'] = 'scrivi';
    ruRegistra($this, $s, $dati + ['ho_letto' => true, 'nota_cancello' => 'Successione letta']);

    $piano = b14pCreaConScelta($this, $s, '2025-12-15');

    expect(['Ugo' => b14pPregresso($piano, $s['v']->id), 'Elsa' => b14pPregresso($piano, $s['a']->id), 'Bruno' => b14pPregresso($piano, $bruno->id)])
        ->toBe(['Ugo' => 0, 'Elsa' => 9000, 'Bruno' => 9000]);
});

it('decisione 689 (2) — una successione fuori dall\'arco (Ugo è morto nel 2023) non tocca il saldo: il pregresso del 2026 è degli eredi, titolari da anni', function () {
    $s = b14pScenario();
    $bruno = b14pPersona($s, 'Erede Bruno', 'br');
    $dati = ruPassaggio('successione', $s['rigaV'], $s['a'], '2023-06-01', 100);
    $dati['eredi'] = [['anagrafica_id' => $s['a']->id, 'quota' => 50], ['anagrafica_id' => $bruno->id, 'quota' => 50]];
    $dati['erede_di_riferimento'] = $s['a']->id;
    $dati['arretrato'] = 'defunto';
    $dati['conguaglio'] = 'scrivi';
    ruRegistra($this, $s, $dati + ['ho_letto' => true, 'nota_cancello' => 'Successione letta']);

    b14pGenera($s);

    expect(['Ugo' => b14pPregresso($s['piano'], $s['v']->id), 'Elsa' => b14pPregresso($s['piano'], $s['a']->id), 'Bruno' => b14pPregresso($s['piano'], $bruno->id)])
        ->toBe(['Ugo' => 0, 'Elsa' => 9000, 'Bruno' => 9000]);
});

it('decisione 689 (1), il capo della catena nel server — Interno 1 diviso a mano, Anna € 120,00 e Bruno € 60,00, come lo manda la finestra chiusa con la X: il piano creato usa il riparto a mano', function () {
    $s = b14pScenario();
    $anna = b14pPersona($s, 'Anna Verdi', 'an');
    $bruno = b14pPersona($s, 'Bruno Verdi', 'br');
    DB::table('piani_rate')->where('id', $s['piano']->id)->delete();

    $this->actingAs($this->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2026 bis', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-12-05', 'genera_subito' => true, 'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: riparto a mano',
        'saldi_config' => [['saldo_id' => $s['saldo']->id, 'ripartizioni' => [['anagrafica_id' => $anna->id, 'importo' => '120,00'], ['anagrafica_id' => $bruno->id, 'importo' => '60,00']]]],
    ])->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Preventivo 2026 bis')->firstOrFail();

    expect(['Ugo' => b14pPregresso($piano, $s['v']->id), 'Anna' => b14pPregresso($piano, $anna->id), 'Bruno' => b14pPregresso($piano, $bruno->id)])
        ->toBe(['Ugo' => 0, 'Anna' => 12000, 'Bruno' => 6000]);
});

it('rilievo 15 e 16 — la riserva sulla straordinaria: le etichette senza genere («con la piena proprietà», «con la nuda proprietà») e la natura della gestione nell\'anteprima', function () {
    $s = b14pScenario('straordinaria');
    b14pPassaggio($this, $s, 'riserva', '2026-05-01');

    $anteprima = b14pAnteprima($this, $s);

    expect($anteprima['natura'])->toBe('straordinaria')
        ->and(array_column($anteprima['scelte'], 'etichetta'))->toBe(['con la piena proprietà al 1 gennaio 2026 · la regola', 'con la nuda proprietà dal 1 maggio 2026']);
});

it('rilievo 9 — «elimina e crea di nuovo» dice che si perde anche la scelta di a chi va il pregresso; una scelta che non vale più non la nomina', function () {
    $s = b14pScenario();
    $vendita = b14pPassaggio($this, $s, 'vendita', '2026-04-01');
    $piano = b14pCreaConScelta($this, $s, '2026-04-01');
    // Il piano senza l'esercizio salvato su una gestione usata in due esercizi: il ricalcolo si rifiuta e dice la strada (decisione 192.1).
    $e27 = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $s['g']->esercizi()->attach($e27->id, ['attiva' => true]);
    DB::table('piani_rate')->where('id', $piano->id)->update(['esercizio_id' => null]);

    expect(b14pRicalcola($this, $s, $piano)['message'] ?? '')
        ->toContain('Eliminandolo si perde anche ciò che il piano tiene e la creazione non riprende da sola: a chi va il pregresso di Interno 1 (€ 180,00: la tua scelta, Acquirente Elsa, titolare dal 1 aprile 2026).');

    b14pAnnulla($this, $s, $vendita);
    expect(b14pRicalcola($this, $s, $piano)['message'] ?? '')->toContain('Impossibile ricalcolare: il piano rate «Preventivo 2026 bis» non ha il suo esercizio salvato')
        ->not->toContain('a chi va il pregresso');
});

/**
 * Un pregresso da € 1.000,00 intestato all'unità, con le righe date: [chiave, ruolo, quota, dal?, al?]. Lo stesso impianto di
 * `umSaldoUnitaMista` di `UnitaMistaTest`, ricostruito qui perché quel file eseguito da solo non è caricato.
 */
function umSaldoUnitaMistaB14p(array $righe): object
{
    $condominio = \App\Models\Condominio::factory()->create();
    $esercizio = \App\Models\Esercizio::create(['condominio_id' => $condominio->id, 'nome' => '2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $gestione = \App\Models\Gestione::create(['condominio_id' => $condominio->id, 'nome' => 'Ordinaria', 'data_inizio' => '2026-01-01', 'tipo' => 'ordinaria', 'saldo_applicato' => false]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);
    $immobile = \App\Models\Immobile::forceCreate(['condominio_id' => $condominio->id, 'nome' => 'Int 1', 'descrizione' => 'Appartamento', 'interno' => '1']);
    $persone = [];
    foreach ($righe as $r) {
        [$chiave, $ruolo, $quota] = $r;
        $persone[$chiave] ??= Anagrafica::factory()->create();
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$chiave]->id, 'immobile_id' => $immobile->id, 'tipologia' => $ruolo, 'quota' => $quota,
            'attivo' => true, 'data_inizio' => $r[3] ?? '2026-01-01', 'data_fine' => $r[4] ?? null]);
    }
    $saldo = Saldo::forceCreate(['condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id, 'immobile_id' => $immobile->id,
        'anagrafica_id' => null, 'saldo_iniziale' => 100000, 'descrizione' => 'Pregresso dell\'unità', 'is_applicato' => false]);
    $piano = PianoRate::create(['condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'Piano', 'numero_rate' => 2, 'metodo_distribuzione' => 'rata_zero', 'stato' => 'bozza', 'tipo' => 'ordinario']);

    return (object) compact('condominio', 'esercizio', 'gestione', 'immobile', 'saldo', 'piano', 'persone');
}

// --- Il secondo giro di correzioni, dopo il secondo scettico della tappa 1 (rilievi 2, 3, 4 e 7) ------------------------------------------

/** Ugo muore il giorno dato; eredi Elsa e Bruno a metà; l'arretrato come detto. Con `$quota` la quota di Ugo che passa (Elsa già comproprietaria). */
function b14pSuccessione($test, array $s, Anagrafica $bruno, string $giorno, string $arretrato, float $quota = 100): \App\Models\Gestionale\Subentro
{
    $dati = ruPassaggio('successione', $s['rigaV'], $s['a'], $giorno, $quota);
    $dati['eredi'] = [['anagrafica_id' => $s['a']->id, 'quota' => $quota / 2], ['anagrafica_id' => $bruno->id, 'quota' => $quota / 2]];
    $dati['erede_di_riferimento'] = $s['a']->id;
    $dati['arretrato'] = $arretrato;
    if ($arretrato === 'defunto') {
        $dati['conguaglio'] = 'scrivi';
    }

    return ruRegistra($test, $s, $dati + ['ho_letto' => true, 'nota_cancello' => 'Successione letta']);
}

it('rilievo 2 del secondo scettico — la scelta «Elsa dal 1/4» che non vale più (la vendita annullata), e il piano rigenerato togliendo una voce: l\'esito dice che la scelta non vale più', function () {
    $s = b14pScenario();
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $pulizie = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Pulizie', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 20000]);
    $tabellaId = DB::table('tabelle')->where('condominio_id', $s['c']->id)->value('id');
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $pulizie->id, 'tabella_id' => $tabellaId, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $vendita = b14pPassaggio($this, $s, 'vendita', '2026-04-01');
    $piano = b14pCreaConScelta($this, $s, '2026-04-01');
    expect(b14pPregresso($piano, $s['a']->id))->toBe(18000);
    b14pAnnulla($this, $s, $vendita);

    $esito = $this->actingAs($this->user)->from('/piano')->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $piano, $pulizie->id]), [
        'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: tolgo le pulizie',
    ])->getSession()->get('message');

    // I € 180,00 passano da Elsa a Ugo: prima l'esito diceva solo «Voce rimossa e ricalcolata.».
    expect(['Ugo' => b14pPregresso($piano, $s['v']->id), 'Elsa' => b14pPregresso($piano, $s['a']->id)])->toBe(['Ugo' => 18000, 'Elsa' => 0])
        ->and($esito)->toBe(['type' => 'success', 'message' => 'Voce rimossa e ricalcolata. La scelta di prima per il pregresso di Interno 1 (Acquirente Elsa, '
            . 'titolare il 1 aprile 2026) non vale più: il passaggio dell\'1 aprile 2026 è stato annullato. Il pregresso torna alla regola: va a Venditore Ugo.']);
});

it('rilievo 3 del secondo scettico — l\'erede già comproprietaria (Ugo 50, Elsa 50; eredi di Ugo Elsa e Bruno a metà della sua quota): l\'etichetta della regola dice la successione, non «titolari al 1 gennaio 2026»', function (string $giorno, string $arretrato, string $etichetta, array $cifre) {
    $s = b14pScenario();
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['a']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $bruno = b14pPersona($s, 'Erede Bruno', 'br');
    b14pSuccessione($this, $s, $bruno, $giorno, $arretrato, 50);

    $regola = collect(b14pAnteprima($this, $s)['scelte'])->firstWhere('regola', true);

    expect($regola['etichetta'])->toBe($etichetta)
        ->and(b14pScelta($regola))->toBe($cifre);
})->with([
    // L'1/1 erano titolari Ugo ed Elsa: Bruno non lo era, e la regola dà a lui la parte di Ugo.
    'muore l\'1/4/2026, l\'arretrato agli eredi' => ['2026-04-01', 'eredi', 'con l\'arretrato di Venditore Ugo agli eredi · la regola', ['Acquirente Elsa' => 13500, 'Erede Bruno' => 4500]],
    // L'1/1 erano titolari Elsa e Bruno, e Ugo era morto: la regola dà a lui la parte che gli eredi hanno ricevuto (decisione 689 (2)).
    'muore il 15/12/2025, l\'arretrato a nome del defunto' => ['2025-12-15', 'defunto', 'con l\'arretrato a nome di Venditore Ugo · la regola', ['Acquirente Elsa' => 9000, 'Venditore Ugo' => 9000]],
]);

it('rilievo 4 del secondo scettico — il preventivo della 1.10 senza voci, generato con il pregresso diviso come prima (Ugo ed Elsa € 90,00): la creazione non promette «le stesse cifre», e l\'esito del ricalcolo dice che il pregresso è passato a Ugo', function () {
    $s = b14pScenario();
    b14pPassaggio($this, $s, 'vendita', '2026-04-01');
    // Il piano dello scenario com'era nella 1.10: senza righe delle voci, il pregresso diviso come lo divideva la versione di prima, senza configurazione.
    ruPianoInBozza($s);
    b14pGenera($s, [['saldo_id' => $s['saldo']->id, 'ripartizioni' => [['anagrafica_id' => $s['v']->id, 'importo' => 9000], ['anagrafica_id' => $s['a']->id, 'importo' => 9000]]]]);
    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['saldi_config' => null]);
    expect(['Ugo' => b14pPregresso($s['piano'], $s['v']->id), 'Elsa' => b14pPregresso($s['piano'], $s['a']->id)])->toBe(['Ugo' => 9000, 'Elsa' => 9000])
        ->and(DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->count())->toBe(0);

    $conto = \App\Models\Gestionale\Conto::where('piano_conto_id', \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->value('id'))->firstOrFail();
    $creazione = $this->actingAs($this->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Integrativo', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2026-12-05', 'genera_subito' => true, 'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: integrativo',
        'capitoli_config' => [['id' => $conto->id, 'importo' => '100,00']],
    ])->getSession()->get('message');
    expect($creazione['message'] ?? '')->toBe('Impossibile creare un piano nuovo finché il piano «' . $s['piano']->nome . '» non ha voci associate: ricalcolalo prima (il '
        . 'ricalcolo gli scrive le voci che chiede, con gli stessi importi; se il pregresso di un\'unità va a un\'altra persona, l\'esito del ricalcolo lo dice), poi crea questo piano.');

    $esito = b14pRicalcola($this, $s, $s['piano']);

    // Le cifre della regola (decisione 674): tutto a Ugo. Prima l'esito diceva solo «Piano rate ricalcolato con successo!».
    expect(['Ugo' => b14pPregresso($s['piano'], $s['v']->id), 'Elsa' => b14pPregresso($s['piano'], $s['a']->id)])->toBe(['Ugo' => 18000, 'Elsa' => 0])
        ->and($esito)->toBe(['type' => 'success', 'message' => 'Piano rate ricalcolato con successo! Il ricalcolo ha cambiato a chi va il pregresso di Interno 1 '
            . '(€ 180,00): prima Venditore Ugo € 90,00 e Acquirente Elsa € 90,00, ora Venditore Ugo € 180,00, con la regola (titolare al 1 gennaio 2026).']);
});

it('rilievo 7 del secondo scettico — la scelta «Ugo fino al 14/12/2025» e la vendita del 15/12/2025 annullata: il pregresso resta a Ugo, e l\'esito non dice che la scelta non vale più', function () {
    $s = b14pScenario();
    $vendita = b14pPassaggio($this, $s, 'vendita', '2025-12-15');
    $piano = b14pCreaConScelta($this, $s, '2025-12-14');
    expect(b14pPregresso($piano, $s['v']->id))->toBe(18000);
    b14pAnnulla($this, $s, $vendita);

    $esito = b14pRicalcola($this, $s, $piano);

    expect(b14pPregresso($piano, $s['v']->id))->toBe(18000)
        ->and($esito)->toBe(['type' => 'success', 'message' => 'Piano rate ricalcolato con successo!']);
});

it('rilievo 7 del secondo scettico — la scelta degli eredi (decisione 689 (2)) e la successione annullata: la frase dice «Acquirente Elsa ed Erede Bruno»', function () {
    $s = b14pScenario();
    $bruno = b14pPersona($s, 'Erede Bruno', 'br');
    $sub = b14pSuccessione($this, $s, $bruno, '2025-12-15', 'defunto');
    $piano = b14pCreaConScelta($this, $s, '2025-12-15');
    expect(['Ugo' => b14pPregresso($piano, $s['v']->id), 'Elsa' => b14pPregresso($piano, $s['a']->id), 'Bruno' => b14pPregresso($piano, $bruno->id)])
        ->toBe(['Ugo' => 0, 'Elsa' => 9000, 'Bruno' => 9000]);
    b14pAnnulla($this, $s, $sub);

    $esito = b14pRicalcola($this, $s, $piano);

    expect(b14pPregresso($piano, $s['v']->id))->toBe(18000)
        ->and($esito['message'] ?? '')->toContain('La scelta di prima per il pregresso di Interno 1 (Acquirente Elsa ed Erede Bruno, titolari il 15 dicembre 2025) non vale più');
});
