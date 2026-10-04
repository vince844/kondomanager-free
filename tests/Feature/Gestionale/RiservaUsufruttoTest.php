<?php

/**
 * 1.11.0-beta.38 — la vendita con riserva d'usufrutto (decisioni 27.6, 27.7 e 28 di `docs/subentro_e_competenza_temporale.md`).
 *
 * Chi vende (proprietario pieno) vende la nuda proprietà e resta sulla stessa quota come usufruttuario; chi compra entra
 * nudo proprietario. Si registra dal modulo della **vendita** (decisione 28.1), dichiarandola: la riserva non si deduce.
 * Dopo l'atto i ruoli sono quelli di una costituzione d'usufrutto con le persone scambiate, quindi il motore di riparto
 * non cambia; cambia il passaggio:
 *
 * - le **ordinarie** le deve ancora chi vende (art. 1004 c.c.): nessuna bozza ordinaria passa, e nel conguaglio delle
 *   quote già emesse l'ordinaria resta fuori (lettura dichiarata nel verbale della beta);
 * - le **straordinarie** seguono la regola della vendita (decisione 25): passano a chi compra se la delibera, o la
 *   competenza dichiarata sulla fattura, è dalla decorrenza in poi (art. 1005 c.c.; testi T5);
 * - verso il condominio nudo proprietario e usufruttuario rispondono in solido dal giorno dell'atto (art. 67 ult. co.);
 *   se chi compra risponda anche dell'arretrato di chi vende (art. 63 co. 4) nessuna fonte lo chiarisce, e la nota lo
 *   dice come punto aperto su cui decide l'amministratore (decisione 28.2, corretta dopo la ricerca del 29/09/2026);
 * - la copia autentica non libera chi vende: resta usufruttuario (decisione 28.3);
 * - un piano generato o ricalcolato dopo l'atto addebita secondo i coefficienti: le voci sul «Proprietario» scendono dal
 *   giorno dell'atto al nudo proprietario, e l'anteprima lo dice leggendo il riparto del piano (decisione 28.5, rilievo B1);
 *   con la riserva sulla quota di un comproprietario l'unità resta mista, e dalla beta.41 il motore la divide per le
 *   quote registrate (Coda 170, decisione 31.1): l'avviso della decisione 28.6 è tolto.
 *
 * Flusso vero: il piano generato a gennaio con il solo venditore, l'emissione a giornale, il passaggio dalla rotta.
 *
 * **Cosa resta scoperto** (regola «ogni test dichiara cosa NON copre»). Le combinazioni non si provano tutte qui: gli
 * invarianti — il totale dell'unità che non cambia, le coppie a zero e il loro valore, l'anteprima che è la scrittura,
 * l'annullamento che rimette tutto, l'ordinaria che resta a chi vende, la straordinaria che segue la delibera, le due
 * forme del risolutore che concordano con le righe scritte — girano sulle griglie di `InvariantiPassaggiTest`: oltre
 * cento combinazioni scelte a coppie (forma, natura e data della delibera, emissione, saldi, ruolo della voce, giorno
 * dell'atto, pertinenza, rinuncia, generazione del piano), più le catene annullate all'indietro e le forme del
 * risolutore; lì anche l'estinzione con più nudi proprietari, e il docblock di quel file dice che cosa ciascun invariante
 * non guarda. Qui i test dicono chi paga e che cosa si scrive dove la regola lo stabilisce: in fondo al file, dalla mappa
 * dei casi, la riserva all'altro comproprietario pieno, la quota dichiarata su una comproprietà, le seconde riserve con il
 * denaro, la straordinaria nella rivendita della nuda proprietà, la nota di solidarietà il giorno dell'atto e con le
 * pertinenze, i passaggi della .37 con il consuntivo dell'anno prima generato dopo (anche nella catena, sul motore),
 * l'avviso del ricalcolo con una voce senza coefficienti e su un piano senza righe di riparto; la presa d'atto con un solo
 * titolare sta nel test del rilievo B4. Un test fissa il comportamento di oggi e lo dice nel nome, «sentinella della Coda
 * 172»: quando la coda si corregge deve cambiare. Quelli delle Code 170 e 171, chiuse nella beta.41, asseriscono il
 * comportamento giusto. Restano fuori:
 * - l'emissione, simulata a giornale e non da `EmissioneRateController`, e la corsa fra anteprima e registrazione
 *   (SQLite serializza; provata a mano nella .37);
 * - MySQL: qui la condizione JSON di `Subentro::vincolaRiservaUsufrutto` gira su sqlite, dove le due forme del
 *   risolutore la condividono; la confronta con la lettura in PHP `tests/Unit/Subentro/RiservaUsufruttoMysqlTest`
 *   (gruppo `mysql`, solo con un MySQL locale). MySQL 5.7 non è provato;
 * - l'unità mista oltre la riserva su metà (Coda 170, chiusa nella beta.41): il test «Coda 170 (decisione 31.1)» prova il
 *   denaro dopo una riserva su metà; le voci, l'addebito diretto, i giorni scoperti e i saldi pregressi sono in
 *   `tests/Feature/Riparto/UnitaMistaTest.php`;
 * - la scelta su chi paga l'ordinaria (Coda 171, chiusa nella beta.41): il test «Coda 171 (decisione 31.5)» prova che il
 *   ricalcolo segue la scelta; la scelta, le voci spostate e bloccate, le catene e il conguaglio voce per voce sono in
 *   `OrdinariaDopoAttoTest`. I test del conguaglio di questo file mandano la legge. L'addebito diretto all'unità non ha
 *   una voce da spostare e con la legge resta a due persone (Coda 216, sentinella in `OrdinariaDopoAttoTest`).
 *   L'ordinaria da fatture con la competenza dichiarata (decisione 26) nella riserva segue il ramo dell'ordinaria, e non
 *   ha un test suo;
 * - la nota di solidarietà non legge la coppia (Coda 172): con la straordinaria deliberata dopo l'atto la cifra delle
 *   certe, € 400,00, conta quote che la coppia ha già spostato su chi compra, e la fissa com'è la sentinella della Coda
 *   172; la nota non legge nemmeno la competenza dichiarata sulla fattura. Della nota il giorno dell'atto è provato il
 *   punto aperto, non le certe;
 * - in attesa delle decisioni di Vincenzo, senza asserire quale esito è giusto: l'annullamento della riserva — e della
 *   costituzione, anteriore — dopo un piano generato ed emesso con la voce sull'«Usufruttuario» o sull'«Inquilino» senza
 *   inquilino, che oggi si ferma (rilievo R2: è provato che storico e rotta concordano, e che con l'inquilino dal primo
 *   giorno l'annullamento passa); il pregresso spalmato sulle rate che scadono dopo l'atto, e la gemella straordinaria
 *   emessa, che la nota conta fra le certe e non nel punto aperto (rilievo R4);
 * - S1E, i due genitori che donano con riserva e poi muore uno dei due: nella griglia B valgono solo gli invarianti di
 *   coerenza; a chi va l'usufrutto del genitore morto dipende dall'eventuale accrescimento, ed è materia della
 *   successione (beta.42);
 * - la gestione senza esercizio, che nella riserva cambia solo la frase per gestione; la rata zero rimasta in bozza in
 *   una catena, che `ruEmetti` non sa costruire (emette per data, e la rata zero scade con la prima);
 * - «il destinatario cambierebbe» dove paga sempre la stessa persona, nella costituzione con la voce sul «Proprietario»
 *   (anteriore) e nella riserva con la voce sull'«Usufruttuario» o sull'«Inquilino» senza inquilino (rilievo R1): non è un
 *   difetto (decisione 28.8) — per il motore il destinatario è la risoluzione per periodo, e il ricalcolo chiede davvero
 *   la presa d'atto del cancello (2), come asseriscono il test «Coda 173» e la controprova del rilievo B1 —, ma confonde:
 *   il testo si chiarisce nella Coda 173, e allora quei test cambiano. Le altre incoerenze fra ciò che il programma dice
 *   e ciò che fa, trovate con questa, sono corrette nei testi, non nel denaro (decisione 28.8, cantieri C7 e C8). In fondo a
 *   questo file le gemelle di solo pregresso lasciate a chi vendeva prima (rilievo R3): in bozza, nella catena e
 *   nell'estinzione, non chiedono la spunta; emesse, nella vendita piena, non «passano ancora» e vanno fra le informazioni.
 *   Nella sezione «Coerenza» di `InvariantiPassaggiTest`: l'eccezione dei saldi intestati all'unità, o a una pertinenza del
 *   passaggio, nella frase «Il programma non intesta nulla a …» dell'anteprima e dello storico e nella frase gemella
 *   «Nessuna quota è intestata a …» della nota di solidarietà, con una forma che non dice a chi vadano quei saldi (lo fissa
 *   com'è la sentinella della Coda 174, sul piano straordinario: tutto a chi compra nella riserva, metà a chi compra e metà a
 *   chi vende nella vendita piena); la nota della vendita della sola nuda proprietà, che nomina l'usufruttuario solo quando l'usufrutto grava con
 *   certezza sulla quota venduta; le quote di una straordinaria tutta di chi vende, in bozza e già emesse, fra le
 *   informazioni; la finestra della nota;
 * - le bozze non risolte di un predecessore (anteriore, Coda 173): in una catena di vendite piene, su un piano senza data
 *   della delibera e senza righe di riparto, le bozze di chi vendeva prima prendono il motivo «predecessore», che in
 *   `ConguaglioPassaggio::decidiBozze` viene prima di «non risolta». Il pannello dice insieme «restano sue e sono comprese
 *   nel conguaglio» (cancello) o «si conguagliano qui» (blocco 2) e «Nessun conguaglio proposto su quelle quote», con le
 *   coppie vuote (sonda C3 dello scettico). Dichiarato, non corretto, e nessun test lo asserisce.
 * Il suggerimento sotto «Ho ricevuto copia autentica» nel modulo (V4), il riquadro «Quote che questo passaggio non tocca»
 * (cantiere C6: è provato il campo `cancello.informazioni` che lo riempie) e la guida in-app non hanno un test: si
 * verificano a video. Il riquadro è da verificare a video anche dopo i cantieri C7 e C8, che ne hanno cambiato il contenuto
 * (le quote di solo pregresso e quelle di una spesa tutta di chi vende, anche nella vendita piena) e il piede, ora una
 * frase generale senza gli artt. 1004 e 1005 c.c.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\User;
use App\Services\Subentro\FrasiObbligati;
use App\Services\Subentro\NotaSolidarieta;
use App\Services\Subentro\StoricoTitolarita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
// Gli scenari `ru*` (ruScenario, ruRiserva, ruRegistra, ruDueGenitori, …) vivono in `Support/ScenariPassaggi.php`: li usa anche
// `InvariantiPassaggiTest`, la griglia degli invarianti dei passaggi.
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

it('le righe: chi vende chiude da proprietario e resta usufruttuario sulla stessa quota, chi compra entra nudo proprietario; il passaggio è una vendita con la riserva scritta nel registro', function () {
    $s = ruScenario('prima_rata', 0);

    $anteprima = ruAnteprima($this, $s, ruRiserva($s));
    expect(implode("\n", $anteprima['anagrafica']['frasi']))->toContain('usufruttuario dal')->toContain('nudo proprietario dal')
        ->and($anteprima['riferimento']['frase'])->toContain('proprietario pieno fino al')->toContain('usufruttuario')->toContain('nudo proprietario dal');

    $subentro = ruRegistra($this, $s, ruRiserva($s));

    expect(ruRighe($s['unita']->id))->toBe([
        [$s['v']->id, 'proprietario', 100.0, '2019-01-01', '2026-04-30'],
        [$s['v']->id, 'usufruttuario', 100.0, '2026-05-01', null],
        [$s['a']->id, 'nuda_proprietario', 100.0, '2026-05-01', null],
    ]);
    $rigaA = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->value('id');
    expect($subentro->tipo_passaggio)->toBe('vendita')
        ->and($subentro->tipologia)->toBe('nuda_proprietario')
        ->and((int) $subentro->anagrafica_uscente_id)->toBe($s['v']->id)
        ->and((int) $subentro->anagrafica_entrante_id)->toBe($s['a']->id)
        ->and((int) $subentro->riga_entrante_id)->toBe($rigaA)
        ->and($subentro->registro['sottotipo'] ?? null)->toBe('riserva_usufrutto')
        ->and($subentro->riservaUsufrutto())->toBeTrue();
});

it('piano ordinario con quattro rate emesse: nessuna bozza passa e nessun conguaglio — le ordinarie le deve ancora chi vende, ora usufruttuario (art. 1004 c.c.)', function (string $metodo, int $saldo) {
    $s = ruScenario($metodo, $saldo);
    ruEmetti($s);
    $totaliPrima = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('numero_rata')->pluck('importo_totale', 'numero_rata')->all();

    $anteprima = ruAnteprima($this, $s, ruRiserva($s));
    $conguaglio = $anteprima['rate']['conguaglio'];
    expect($conguaglio['bozze_riassegnate'])->toBe([])
        ->and(collect($conguaglio['quote_in_bozza'])->pluck('motivo')->unique()->values()->all())->toBe(['ordinaria_riservata'])
        ->and((int) $conguaglio['totale_entrante'])->toBe(0);
    $frasi = implode("\n", $conguaglio['frasi']);
    expect($frasi)->toContain('resta usufruttuario')->toContain('art. 1004 c.c.')
        // Le due frasi della riserva: per gestione e per le bozze che restano («non passano a chi compra»). Senza pronomi
        // (V3 della verifica a video): il programma non conosce il genere delle persone, e «le deve lui» non serviva.
        ->toContain('Venditore Ugo resta usufruttuario (art. 1004 c.c.): non passano a Acquirente Elsa.')->toContain('non passano a chi compra la nuda proprietà')
        ->not->toContain(' lui')
        ->not->toContain('regolate alla costituzione')->not->toContain('già regolate')->not->toContain('non ne risponde');
    // Il blocco 2 del pannello lo dice con le sue parole, prima delle righe del conguaglio.
    expect(implode("\n", $anteprima['rate']['frasi']))->toContain('continua a dovere la quota ordinaria')->toContain('art. 1005 c.c.');

    ruRegistra($this, $s, ruRiserva($s));

    expect(ruQuote($s))->toBe(['v_preventivo' => 120000, 'v_pregresso' => $saldo, 'a_preventivo' => 0, 'a_pregresso' => 0])
        ->and(ruCoppia($s))->toBe([0, 0])
        ->and(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('numero_rata')->pluck('importo_totale', 'numero_rata')->all())->toBe($totaliPrima);
})->with([
    'prima rata, nessun saldo' => ['prima_rata', 0],
    'spalmati, venditore a debito € 180,00' => ['tutte_rate', 18000],
    'rata zero, venditore a credito € 60,00' => ['rata_zero', -6000],
]);

it('straordinario, delibera DOPO il rogito: la spesa è tutta di chi compra la nuda proprietà (art. 1005 c.c.), le bozze passano e la coppia gli fa pagare le due emesse — come nella vendita', function () {
    $s = ruScenario('prima_rata', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6);
    ruEmetti($s, '2026-07-31');
    ruRegistra($this, $s, ruRiserva($s));

    $q = ruQuote($s);
    expect($q['a_preventivo'])->toBe(80000)->and($q['v_preventivo'])->toBe(40000)
        ->and(ruCoppia($s))->toBe([40000, -40000]);
});

it('straordinario, delibera PRIMA del rogito: la spesa è di chi vendeva quando era proprietario pieno, nessuna bozza passa e nessuna coppia', function () {
    $s = ruScenario('prima_rata', 0, 'straordinaria', '2026-03-15', '2026-04-05', 6);
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s));

    expect(ruQuote($s)['v_preventivo'])->toBe(120000)->and(ruQuote($s)['a_preventivo'])->toBe(0)->and(ruCoppia($s))->toBe([0, 0]);
});

it('la riserva non si deduce: una vendita che fa entrare un nudo proprietario da un proprietario pieno, senza dichiararla, si ferma — e il messaggio dice come dichiararla', function () {
    $s = ruScenario('prima_rata', 0);
    $dati = ruRiserva($s);
    unset($dati['sottotipo']);

    $risposta = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati)
        ->assertUnprocessable()->assertJsonValidationErrors('tipologia');
    // Testi T8: la casella dice anche la donazione, il caso più frequente, come il tipo di base «Vendita o donazione».
    expect($risposta->json('errors.tipologia.0'))->toContain('Chi vende o dona resta usufruttuario')->not->toContain('non è ancora prevista');
    expect(DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->count())->toBe(1);
});

it('la riserva si dichiara solo da un proprietario pieno, sulla sua quota intera, e chi compra entra nudo proprietario', function () {
    $s = ruScenario('prima_rata', 0);
    $anteprima = fn (array $dati) => $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati);

    // Chi compra entra nudo proprietario, non proprietario pieno. I messaggi nominano il passaggio come la casella e lo
    // storico: «vendita o donazione» (testi T8).
    $anteprima(ruRiserva($s, extra: ['tipologia' => 'proprietario']))->assertUnprocessable()
        ->assertJsonValidationErrors(['tipologia' => 'Nella vendita o donazione con riserva d\'usufrutto chi compra entra come nudo proprietario: chi vende resta usufruttuario.']);
    // La nuda proprietà di tutta la quota: con la metà resterebbe un usufrutto su 100 e una nuda proprietà su 50.
    $anteprima(ruRiserva($s, extra: ['quota' => 50]))->assertUnprocessable()
        ->assertJsonValidationErrors(['quota' => 'Nella vendita o donazione con riserva d\'usufrutto chi compra riceve la nuda proprietà di tutta la quota di chi vende (100 %)']);
    // Il sottotipo della vendita è uno solo, e quelli dell'usufrutto non valgono per la vendita (né il contrario).
    $anteprima(ruRiserva($s, extra: ['sottotipo' => 'costituzione']))->assertUnprocessable()->assertJsonValidationErrors('sottotipo');
    $anteprima(ruRiserva($s, extra: ['tipo' => 'usufrutto', 'tipologia' => 'usufruttuario']))->assertUnprocessable()->assertJsonValidationErrors('sottotipo');

    // Da un nudo proprietario la riserva non si dichiara: chi vende la nuda proprietà non ha un usufrutto da riservarsi.
    $ursula = Anagrafica::forceCreate(['nome' => 'Usufruttuaria Ursula', 'email' => 'ru-u@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUUSUFRUTTU00001']);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'nuda_proprietario']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $ursula->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'usufruttuario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $anteprima(ruRiserva($s))->assertUnprocessable()->assertJsonValidationErrors('sottotipo');
});

it('chi resta obbligato: dal giorno dell\'atto in solido nudo proprietario e usufruttuario (art. 67), l\'arretrato è un punto aperto su cui decide l\'amministratore, e la copia autentica non libera chi vende', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    $obbligati = implode("\n", ruAnteprima($this, $s, ruRiserva($s))['obbligati']['frasi']);
    expect($obbligati)->toContain('art. 67')->toContain('art. 63 co. 4')->toContain('decide l\'amministratore')
        ->toContain('art. 1004')->toContain('art. 1005')
        // La frase della vendita, che dà per certa la solidarietà dell'arretrato, qui non c'è (decisione 28.2).
        ->not->toContain('risponde in solido con Venditore Ugo per i contributi di questa unità relativi');

    $subentro = ruRegistra($this, $s, ruRiserva($s));
    $vademecum = implode("\n", app(FrasiObbligati::class)->daSubentro($subentro));
    // Copia autentica registrata: nella vendita direbbe che chi vende «è liberato»; qui resta usufruttuario (decisione 28.3).
    expect($vademecum)->toContain('non è liberato')->toContain('art. 67')->not->toContain('è liberato verso il condominio per i contributi successivi');

    $note = app(NotaSolidarieta::class)->per($s['c'], $s['a']);
    expect($note)->toHaveCount(1)
        ->and($note[0]['testo'])->toContain('art. 67')->toContain('decide l\'amministratore')
        ->not->toContain('risponde in solido con Venditore Ugo per i contributi di Interno 1');
});

it('teste in assemblea: chi vende resta come usufruttuario e continua a contare, chi compra la nuda proprietà si aggiunge', function () {
    $s = ruScenario('prima_rata', 0);
    $invarianti = implode("\n", ruAnteprima($this, $s, ruRiserva($s))['invarianti']['frasi']);
    expect($invarianti)->toContain('resta come usufruttuario')->toContain('continua a contare');
});

it('lo storico la chiama vendita con riserva d\'usufrutto e aspetta la copia autentica come ogni vendita', function () {
    $s = ruScenario('prima_rata', 0);
    $subentro = ruRegistra($this, $s, ruRiserva($s, extra: ['copia_autentica' => false, 'copia_autentica_il' => null]));

    $voce = collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $subentro->id);
    expect($voce['tipo_passaggio'])->toBe('vendita')
        ->and($voce['sottotipo'])->toBe('riserva_usufrutto')
        ->and($voce['copia_autentica_attesa'])->toBeTrue();
});

it('l\'annullamento rimette tutto com\'era: chi vende torna proprietario pieno, le righe della riserva spariscono, le bozze straordinarie tornano a lui e la coppia si toglie', function () {
    $s = ruScenario('prima_rata', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6);
    ruEmetti($s, '2026-07-31');
    $righePrima = ruRighe($s['unita']->id);
    $quotePrima = ruQuote($s);
    $subentro = ruRegistra($this, $s, ruRiserva($s));
    expect(ruCoppia($s))->toBe([40000, -40000]);

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $subentro]), ['nota_annullamento' => 'Registrata la riserva sulla persona sbagliata'])
        ->assertRedirect();

    expect(ruRighe($s['unita']->id))->toBe($righePrima)
        ->and(ruQuote($s))->toBe($quotePrima)
        ->and(ruCoppia($s))->toBe([0, 0]);
});

it('catena: dopo la riserva l\'usufrutto si estingue («Usufrutto → estinzione») e chi aveva comprato la nuda proprietà torna proprietario pieno', function () {
    $s = ruScenario('prima_rata', 0);
    ruRegistra($this, $s, ruRiserva($s));
    $rigaUsu = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'usufruttuario')->value('id');

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), [
        'tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUsu, 'decorrenza' => '2026-09-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione per morte dell\'usufruttuario, letta',
    ])->assertSessionHasNoErrors();

    expect(ruRighe($s['unita']->id))->toBe([
        [$s['v']->id, 'proprietario', 100.0, '2019-01-01', '2026-04-30'],
        [$s['v']->id, 'usufruttuario', 100.0, '2026-05-01', '2026-08-31'],
        [$s['a']->id, 'nuda_proprietario', 100.0, '2026-05-01', '2026-08-31'],
        [$s['a']->id, 'proprietario', 100.0, '2026-09-01', null],
    ]);
});

it('le pertinenze seguono la principale: sul box chi vende resta usufruttuario e chi compra entra nudo proprietario', function () {
    $s = ruScenario('prima_rata', 0);
    $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['v']->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    ruRegistra($this, $s, ruRiserva($s, extra: ['pertinenze' => [$box->id]]));

    expect(ruRighe($box->id))->toBe([
        [$s['v']->id, 'proprietario', 100.0, '2019-01-01', '2026-04-30'],
        [$s['v']->id, 'usufruttuario', 100.0, '2026-05-01', null],
        [$s['a']->id, 'nuda_proprietario', 100.0, '2026-05-01', null],
    ]);
    $figlio = Subentro::where('immobile_id', $box->id)->sole();
    expect($figlio->riservaUsufrutto())->toBeTrue();
});

it('lo storico nomina la riserva anche quando ferma un altro annullamento: dopo la vendita a Elsa c\'è la sua vendita con riserva d\'usufrutto a Carlo', function () {
    $s = ruScenario('prima_rata', 0);
    $vendita = ruRegistra($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-03-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-03-05', 'estremi_titolo' => 'rep. 1', 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Prima vendita, letto']);
    $carlo = Anagrafica::forceCreate(['nome' => 'Compratore Carlo', 'email' => 'ru-cc@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUCOMPRATOR00001']);
    $carlo->condomini()->syncWithoutDetaching([$s['c']->id]);
    $rigaA = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->value('id');
    ruRegistra($this, $s, ruRiserva(['rigaV' => $rigaA, 'a' => $carlo] + $s, '2026-06-01'));

    $voce = collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $vendita->id);
    expect($voce['annullabile']['si'])->toBeFalse()
        ->and($voce['annullabile']['motivo'])->toContain('la vendita o donazione con riserva d\'usufrutto da Acquirente Elsa a Compratore Carlo');
});

// --- Rilievo B5 della Fase 1-bis (decisione 28.6): la riserva su una quota si somma come la vendita ---------------------

it('rilievo B5, S1 — i due genitori donano al figlio, con lo stesso atto, la nuda proprietà delle loro metà: la seconda riserva si somma sulla riga del figlio nata quel giorno, e anteprima e registrazione rispondono allo stesso modo', function () {
    [$s, $madre, $rigaM] = ruDueGenitori();
    ruRegistra($this, $s, ruRiserva($s, extra: ['quota' => 50]));

    $seconda = ruRiserva(['rigaV' => $rigaM] + $s, extra: ['quota' => 50]);
    ruAnteprima($this, $s, $seconda);
    $subentro = ruRegistra($this, $s, $seconda);

    expect(ruRighe($s['unita']->id))->toBe([
        [$s['v']->id, 'proprietario', 50.0, '2019-01-01', '2026-04-30'],
        [$madre->id, 'proprietario', 50.0, '2019-01-01', '2026-04-30'],
        [$s['v']->id, 'usufruttuario', 50.0, '2026-05-01', null],
        [$s['a']->id, 'nuda_proprietario', 100.0, '2026-05-01', null],
        [$madre->id, 'usufruttuario', 50.0, '2026-05-01', null],
    ]);
    $rigaFiglio = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->value('id');
    expect((int) $subentro->riga_entrante_id)->toBe($rigaFiglio)
        ->and((int) $subentro->anagrafica_uscente_id)->toBe($madre->id)
        ->and($subentro->riservaUsufrutto())->toBeTrue();
});

it('rilievo B5, S1 — l\'annullamento della seconda riserva riporta il figlio a 50 sulla stessa riga, e la madre torna proprietaria piena', function () {
    [$s, $madre, $rigaM] = ruDueGenitori();
    ruRegistra($this, $s, ruRiserva($s, extra: ['quota' => 50]));
    $dopoLaPrima = ruRighe($s['unita']->id);
    $seconda = ruRegistra($this, $s, ruRiserva(['rigaV' => $rigaM] + $s, extra: ['quota' => 50]));

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $seconda]), ['nota_annullamento' => 'La madre non ha ancora firmato'])
        ->assertRedirect();

    expect(ruRighe($s['unita']->id))->toBe($dopoLaPrima)
        ->and($dopoLaPrima)->toBe([
            [$s['v']->id, 'proprietario', 50.0, '2019-01-01', '2026-04-30'],
            [$madre->id, 'proprietario', 50.0, '2019-01-01', null],
            [$s['v']->id, 'usufruttuario', 50.0, '2026-05-01', null],
            [$s['a']->id, 'nuda_proprietario', 50.0, '2026-05-01', null],
        ]);
});

it('rilievo B5, S2 — la seconda riserva un mese dopo: la riga del figlio al 50 % si chiude il giorno prima e se ne apre una al 100 %, come nella vendita (decisione A)', function () {
    [$s, $madre, $rigaM] = ruDueGenitori();
    ruRegistra($this, $s, ruRiserva($s, extra: ['quota' => 50]));

    $seconda = ruRiserva(['rigaV' => $rigaM] + $s, '2026-06-01', ['quota' => 50, 'copia_autentica_il' => '2026-06-05']);
    ruAnteprima($this, $s, $seconda);
    ruRegistra($this, $s, $seconda);

    expect(ruRighe($s['unita']->id))->toBe([
        [$s['v']->id, 'proprietario', 50.0, '2019-01-01', '2026-04-30'],
        [$madre->id, 'proprietario', 50.0, '2019-01-01', '2026-05-31'],
        [$s['v']->id, 'usufruttuario', 50.0, '2026-05-01', null],
        [$s['a']->id, 'nuda_proprietario', 50.0, '2026-05-01', '2026-05-31'],
        [$madre->id, 'usufruttuario', 50.0, '2026-06-01', null],
        [$s['a']->id, 'nuda_proprietario', 100.0, '2026-06-01', null],
    ]);
});

it('rilievo B5, S3 — chi vende è già usufruttuario dell\'altra metà: la sua riga d\'usufrutto si somma (chiusa il giorno prima, riaperta al 100 %), e l\'annullamento la rimette com\'era', function () {
    $s = ruScenario('prima_rata', 0);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $figlia = Anagrafica::forceCreate(['nome' => 'Figlia Nora', 'email' => "ru-f{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUFIGLIANOR' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $figlia->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->insert([
        ['anagrafica_id' => $s['v']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'usufruttuario', 'quota' => 50, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()],
        ['anagrafica_id' => $figlia->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'nuda_proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $righePrima = ruRighe($s['unita']->id);

    $riserva = ruRiserva($s, extra: ['quota' => 50]);
    ruAnteprima($this, $s, $riserva);
    $subentro = ruRegistra($this, $s, $riserva);

    expect(ruRighe($s['unita']->id))->toBe([
        [$s['v']->id, 'proprietario', 50.0, '2019-01-01', '2026-04-30'],
        [$s['v']->id, 'usufruttuario', 50.0, '2019-01-01', '2026-04-30'],
        [$figlia->id, 'nuda_proprietario', 50.0, '2019-01-01', null],
        [$s['v']->id, 'usufruttuario', 100.0, '2026-05-01', null],
        [$s['a']->id, 'nuda_proprietario', 50.0, '2026-05-01', null],
    ]);

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $subentro]), ['nota_annullamento' => 'Atto registrato sulla persona sbagliata'])
        ->assertRedirect();
    expect(ruRighe($s['unita']->id))->toBe($righePrima);
});

// --- Rilievo B4 della Fase 1-bis: la riga d'usufrutto di chi vende è entrata con il passaggio (D7) ---------------------

it('rilievo B4 — piano 2026 generato dopo la riserva, con un titolare precedente nel periodo: la riga d\'usufrutto di chi vende vale dal giorno dell\'atto, e i giorni di prima vanno a chi era proprietario allora', function () {
    [$s, $zeta] = ruDopoUnaVendita($this);

    // Il cancello (2) vede che la risoluzione per periodo cambia i destinatari, come dopo una costituzione.
    expect(fn () => app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']))
        ->toThrow(\App\Exceptions\Gestionale\DestinatariCambiatiException::class);
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: passaggi nel 2026', esercizio: $s['e']);

    // 59 giorni a Ugo (01/01–28/02), 61 a Zeta proprietaria (01/03–30/04), 245 a Zeta usufruttuaria (01/05–31/12).
    expect(ruPerPersona($s['piano']))->toBe([$s['v']->id => 19397, $zeta->id => 100603])
        ->and(ruRiparto($s['piano']))->toBe([
            [$s['v']->id, 'proprietario', 19397, '2026-01-01', '2026-02-28'],
            [$zeta->id, 'proprietario', 20055, '2026-03-01', '2026-04-30'],
            [$zeta->id, 'usufruttuario', 80548, '2026-05-01', '2026-12-31'],
        ]);
});

it('rilievo B4 — con un solo titolare (Ugo vende a Elsa con riserva) il denaro non cambia, ma il riparto ha i due tratti: proprietario fino al giorno prima dell\'atto, usufruttuario dal giorno dell\'atto; e la generazione chiede comunque la presa d\'atto del cancello (2)', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'inquilino', genera: false);
    ruRegistra($this, $s, ruRiserva($s));
    // Il verbale lo manda nel changelog (conseguenze del cantiere C1): anche con un solo titolare la risoluzione per periodo
    // cambia i destinatari, e senza la presa d'atto la generazione si ferma prima di scrivere una rata.
    expect(fn () => app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']))
        ->toThrow(\App\Exceptions\Gestionale\DestinatariCambiatiException::class);
    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->count())->toBe(0);
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: riserva nel 2026', esercizio: $s['e']);

    expect(ruPerPersona($s['piano']))->toBe([$s['v']->id => 120000])
        ->and(ruRiparto($s['piano']))->toBe([
            [$s['v']->id, 'proprietario', 39452, '2026-01-01', '2026-04-30'],
            [$s['v']->id, 'usufruttuario', 80548, '2026-05-01', '2026-12-31'],
        ]);
});

it('rilievo B4 — nell\'anno prima della riserva chi vende non è usufruttuario: la collection e la forma SQL del risolutore lo lasciano fuori tutte e due (invariante 4)', function () {
    [$s, $zeta] = ruDopoUnaVendita($this);
    $r = app(\App\Services\Riparto\RisolutoreTitolari::class);

    foreach (['2025' => [$s['v']->id . ':proprietario'], '2026' => [$s['v']->id . ':proprietario', $zeta->id . ':proprietario', $zeta->id . ':usufruttuario', $s['a']->id . ':nuda_proprietario']] as $anno => $attesi) {
        $periodo = new \App\Support\PeriodoCompetenza("{$anno}-01-01", "{$anno}-12-31");
        $collection = $r->attiviAlla($s['unita']->anagrafiche()->get(), $periodo)->map(fn ($a) => $a->id . ':' . $a->pivot->tipologia)->sort()->values()->all();
        $sql = $r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id), $periodo)->get(['anagrafica_id', 'tipologia'])
            ->map(fn ($x) => $x->anagrafica_id . ':' . $x->tipologia)->sort()->values()->all();
        sort($attesi);
        expect($collection)->toBe($attesi, "collection {$anno}")->and($sql)->toBe($attesi, "SQL {$anno}");
    }
});

// --- Rilievo B7 della Fase 1-bis (decisione 24): le righe che il passaggio ha scritto, lette dal suo registro ----------

it('rilievo B7 — la riga d\'usufrutto con cui chi vende resta l\'ha aperta il passaggio: il suo ruolo non si cambia da «Modifica associazione» (422, righe intatte), la pagina e l\'elenco la dicono agganciata, e le note restano modificabili', function () {
    $s = ruScenario('prima_rata', 0);
    ruRegistra($this, $s, ruRiserva($s));
    $rigaUsu = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'usufruttuario')->value('id');
    $rotta = route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['unita'], $rigaUsu]);
    $righe = ruRighe($s['unita']->id);

    $r = $this->actingAs($this->user)->putJson($rotta, ['anagrafica_id' => $s['v']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'data_inizio' => '2026-05-01', 'data_fine' => null, 'note' => null]);
    $r->assertUnprocessable()->assertJsonValidationErrors('tipologia');
    expect($r->json('errors.tipologia.0'))->toContain('fa parte di un passaggio registrato')
        ->and(ruRighe($s['unita']->id))->toBe($righe)
        ->and(ruAgganciata($this, $s, $rigaUsu))->toBe(['edit' => true, 'elenco' => true]);

    $this->actingAs($this->user)->put($rotta, ['anagrafica_id' => $s['v']->id, 'tipologia' => 'usufruttuario', 'quota' => 100, 'data_inizio' => '2026-05-01', 'data_fine' => null, 'note' => 'Riserva nel rogito rep. 777'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('anagrafica_immobile')->where('id', $rigaUsu)->value('note'))->toBe('Riserva nel rogito rep. 777');
});

it('rilievo B7, controprova — una riga che il passaggio non ha scritto resta libera anche se comincia il giorno dell\'atto: l\'inquilino associato a mano cambia ruolo', function () {
    $s = ruScenario('prima_rata', 0);
    ruRegistra($this, $s, ruRiserva($s));
    $luca = Anagrafica::forceCreate(['nome' => 'Inquilino Luca', 'email' => "ru-l{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUINQUILINO' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $luca->condomini()->syncWithoutDetaching([$s['c']->id]);
    $rigaL = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $luca->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-05-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    expect(ruAgganciata($this, $s, $rigaL))->toBe(['edit' => false, 'elenco' => false]);
    $this->actingAs($this->user)->put(route('admin.gestionale.immobili.anagrafiche.update', [$s['c'], $s['unita'], $rigaL]), ['anagrafica_id' => $luca->id, 'tipologia' => 'proprietario', 'quota' => 100, 'data_inizio' => '2026-05-01', 'data_fine' => null, 'note' => null])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(DB::table('anagrafica_immobile')->where('id', $rigaL)->value('tipologia'))->toBe('proprietario');
});

// --- Rilievo B6 della Fase 1-bis: l'annullamento conta solo le quote nate dopo che cambierebbe -------------------------

it('rilievo B6 — il consuntivo dell\'anno prima, generato dopo la riserva, è tutto di chi vendeva e l\'annullamento non lo cambierebbe: nessun avviso, e una volta emesso non ferma niente', function () {
    $s = ruScenario('prima_rata', 0);
    $subentro = ruRegistra($this, $s, ruRiserva($s));
    $consuntivo = ruConsuntivo2025($s);
    expect(ruPerPersona($consuntivo))->toBe([$s['v']->id => 30000])
        ->and(ruRiparto($consuntivo))->toBe([[$s['v']->id, 'proprietario', 30000, null, null]])
        ->and(ruCompetenze($consuntivo))->toBe([['2025-01-01', '2025-12-31']]);

    $annulla = app(\App\Actions\Subentro\AnnullaPassaggioAction::class);
    expect(ruAvvisiSenzaVoci($annulla->avvisi($subentro)))->toBe([]);
    ruEmetti(['piano' => $consuntivo] + $s, '2026-12-31');
    expect($annulla->motivoBlocco($subentro->fresh()))->toBeNull();

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $subentro]), ['nota_annullamento' => 'Riserva registrata sulla persona sbagliata'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(ruPerPersona($consuntivo))->toBe([$s['v']->id => 30000]);
});

it('rilievo B6 — lo stesso nell\'estinzione dell\'usufrutto, dove chi torna pieno è l\'entrante del passaggio: il consuntivo 2025, tutto suo come nudo proprietario, non fa avvisi né blocchi', function () {
    $s = ruScenario('prima_rata', 0);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'nuda_proprietario']);
    $rigaUsu = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $s['a']->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'usufruttuario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $subentro = ruRegistra($this, $s, [
        'tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUsu, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione per morte dell\'usufruttuario, letta',
    ]);
    expect((int) $subentro->anagrafica_entrante_id)->toBe($s['v']->id);
    $consuntivo = ruConsuntivo2025($s);
    expect(ruPerPersona($consuntivo))->toBe([$s['v']->id => 30000])
        ->and(ruRiparto($consuntivo))->toBe([[$s['v']->id, 'nuda_proprietario', 30000, null, null]]);

    $annulla = app(\App\Actions\Subentro\AnnullaPassaggioAction::class);
    expect(ruAvvisiSenzaVoci($annulla->avvisi($subentro)))->toBe([]);
    ruEmetti(['piano' => $consuntivo] + $s, '2026-12-31');
    expect($annulla->motivoBlocco($subentro->fresh()))->toBeNull();
});

it('rilievo B6, controprova — il blocco vero resta: riserva sulla metà di un comproprietario e preventivo 2026 generato dopo, che l\'annullamento cambierebbe per tutti e due', function () {
    [$s, $madre] = ruDueGenitori();
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->delete();
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    DB::table('rate')->where('piano_rate_id', $s['piano']->id)->delete();
    $subentro = ruRegistra($this, $s, ruRiserva($s, extra: ['quota' => 50]));
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: riserva nel 2026', esercizio: $s['e']);

    $annulla = app(\App\Actions\Subentro\AnnullaPassaggioAction::class);
    expect(ruAvvisiSenzaVoci($annulla->avvisi($subentro)))->toBe(['Il piano «Preventivo 2026» è stato generato o ricalcolato dopo il passaggio: ricalcolalo di nuovo, così quote e riparto tornano sulla titolarità di prima.']);
    ruEmetti($s, '2026-05-31');
    expect($annulla->motivoBlocco($subentro->fresh()))->toContain('del piano «Preventivo 2026»')->toContain('Annulla l\'emissione');
});

it('rilievo B6, controprova — una quota nata dopo senza dettaglio del riparto (scritta a mano, non dal motore) conta ancora solo se è di chi è entrato', function () {
    $s = ruScenario('prima_rata', 0);
    $subentro = ruRegistra($this, $s, ruRiserva($s));
    $bruno = Anagrafica::forceCreate(['nome' => 'Condòmino Bruno', 'email' => "ru-b{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUCONDOMINO' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $aMano = function (string $nome, Anagrafica $persona) use ($s) {
        $piano = DB::table('piani_rate')->insertGetId([
            'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'nome' => $nome, 'numero_rate' => 1, 'giorno_scadenza' => 5,
            'metodo_distribuzione' => 'tutte_rate', 'attivo' => true, 'stato' => 'approvato', 'tipo' => 'ordinario', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rata = DB::table('rate')->insertGetId(['piano_rate_id' => $piano, 'numero_rata' => 1, 'data_scadenza' => '2026-07-05', 'importo_totale' => 5000, 'stato' => 'bozza', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('rate_quote')->insert(['rata_id' => $rata, 'anagrafica_id' => $persona->id, 'immobile_id' => $s['unita']->id, 'importo' => 5000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria', 'data_scadenza' => '2026-07-05', 'created_at' => now(), 'updated_at' => now()]);
    };
    $aMano('Conguaglio luglio', $s['a']);
    $aMano('Rimborso Bruno', $bruno);

    expect(ruAvvisiSenzaVoci(app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->avvisi($subentro)))
        ->toBe(['Il piano «Conguaglio luglio» è stato generato o ricalcolato dopo il passaggio: ricalcolalo di nuovo, così quote e riparto tornano sulla titolarità di prima.']);
});

// --- Rilievo B3 della Fase 1-bis (e testi T3): la nuda proprietà rivenduta dopo la riserva ------------------------------

it('rilievo B3 — Elsa, che ha comprato la nuda proprietà con la riserva, la rivende a Carlo: le ordinarie restano a Ugo, ancora usufruttuario, e fra Elsa e Carlo non c\'è coppia — anche senza righe di riparto o con la voce sull\'usufruttuario', function (string $caso, string $riserva) {
    $s = ruScenario('prima_rata', 0, soggetto: $caso === 'voce' ? 'usufruttuario' : 'proprietario', genera: $caso !== 'voce');
    if ($caso === 'senza righe') {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }
    if ($caso !== 'voce') {
        ruEmetti($s);
    }
    ruRegistra($this, $s, ruRiserva($s, $riserva));
    if ($caso === 'voce') {
        app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: riserva nel 2026', esercizio: $s['e']);
        ruEmetti($s);
    }
    expect(ruPerPersona($s['piano']))->toBe([$s['v']->id => 120000])->and(ruCoppia($s))->toBe([0, 0]);

    [$carlo, $rivendita] = ruRivendita($s);
    $conguaglio = ruAnteprima($this, $s, $rivendita)['rate']['conguaglio'];
    expect($conguaglio['coppie'])->toBe([])
        ->and($conguaglio['bozze_riassegnate'])->toBe([])
        ->and(collect($conguaglio['quote_in_bozza'])->pluck('motivo')->unique()->values()->all())->toBe(['ordinaria_riservata']);
    ruRegistra($this, $s, $rivendita);

    expect(Saldo::whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->pluck('saldo_iniziale', 'anagrafica_id')->all())->toBe([])
        ->and(ruPerPersona($s['piano']))->toBe([$s['v']->id => 120000]);
})->with([
    'piano senza righe di riparto (anteriore alla beta.29)' => ['senza righe', '2026-05-01'],
    'riserva nel 2025, piano 2026 generato dopo con la voce sull\'usufruttuario (righe risolte solo «usufruttuario»)' => ['voce', '2025-12-01'],
    'riserva nel 2026, piano generato dopo con la voce sull\'usufruttuario (Ugo «proprietario» fino ad aprile: il denaro era già giusto)' => ['voce', '2026-05-01'],
    'righe risolte «proprietario» (controllo: il denaro era già giusto)' => ['righe', '2026-05-01'],
]);

it('rilievo B3, testi T3 — nella rivendita le frasi nominano chi resta usufruttuario, non chi rivende, e non parlano di una costituzione dell\'usufrutto che non c\'è stata; il cancello non dice che quelle quote passano ancora', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s));

    [, $rivendita] = ruRivendita($s);
    $anteprima = ruAnteprima($this, $s, $rivendita);
    $frasi = implode("\n", $anteprima['rate']['conguaglio']['frasi']);
    expect($frasi)->toContain('Sulla gestione Ordinaria 2026: € 1.200,00 restano a Venditore Ugo — sono spese ordinarie, e la vendita o donazione con riserva d\'usufrutto non le ha fatte passare a Acquirente Elsa: Venditore Ugo resta usufruttuario (art. 1004 c.c.), e non passano nemmeno a Compratore Carlo.')
        ->toContain('resteranno intestate a Venditore Ugo: sono spese ordinarie, e Venditore Ugo resta usufruttuario (art. 1004 c.c.)')
        ->not->toContain('restano a Acquirente Elsa')->not->toContain('costituzione')->not->toContain('già regolate')->not->toContain('si conguagliano qui');
    // Cantiere C6 (decisione di Vincenzo del 29/09): quelle quote restano per legge a chi le ha, e il pannello le dice come
    // informazione, senza spunta; in nessuna delle due liste «passano ancora».
    expect(implode("\n", $anteprima['cancello']['informazioni']))
        ->toContain('4 quote di rate già emesse a Venditore Ugo: restano sue, questo passaggio non le tocca');
    expect(implode("\n", [...$anteprima['cancello']['motivi'], ...$anteprima['cancello']['informazioni']]))->not->toContain('passa ancora');
});

it('rilievo B3 — catena riserva, estinzione dell\'usufrutto e vendita piena: l\'ordinaria di Ugo è passata a Elsa con l\'estinzione del 1 settembre, non con la riserva — anteprima e frase dicono quel giorno e i suoi 122 giorni, e la coppia resta € 200,55', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s));
    ruEstinzione($this, $s);
    // All'estinzione Elsa prende l'ordinaria da settembre: 120.000 × 122/365 = 40.110.
    expect(ruCoppia($s))->toBe([40110, -40110]);

    [, $vendita] = ruRivendita($s, '2026-11-01', 'proprietario');
    $conguaglio = ruAnteprima($this, $s, $vendita)['rate']['conguaglio'];
    // Settembre e ottobre a Elsa, novembre e dicembre a Carlo: 61 giorni ciascuno, 120.000 × 61/365 = 20.055.
    expect(array_column($conguaglio['coppie'], 'importo'))->toBe([20055])
        ->and($conguaglio['per_gestione'][0]['per_periodo'][0])->toMatchArray([
            'ereditata_da' => 'Venditore Ugo', 'decorrenza_acquisto' => '2026-09-01', 'giorni_periodo' => 122, 'giorni_uscente' => 61, 'giorni_entrante' => 61, 'uscente' => 20055, 'entrante' => 20055,
        ])
        ->and(implode("\n", $conguaglio['frasi']))->toContain('è emessa a Venditore Ugo: la sua competenza è passata a Acquirente Elsa dal 1 settembre 2026 con un passaggio precedente, e quella parte (122 giorni) è divisa in proporzione ai giorni: 61 a Acquirente Elsa, 61 a Compratore Carlo.');
});

it('rilievo B3, controprova — senza riserva la catena non cambia: vendita piena e poi rivendita, la competenza di Ugo è passata a Elsa il giorno della prima vendita', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    ruRegistra($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'rep. 1', 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Prima vendita, letto']);

    [, $vendita] = ruRivendita($s, '2026-09-01', 'proprietario');
    $conguaglio = ruAnteprima($this, $s, $vendita)['rate']['conguaglio'];
    // La parte di Carlo sull'intero piano è 120.000 × 122/365 = 40.110; le quattro bozze da settembre (40.000) passano a Carlo.
    expect(array_column($conguaglio['coppie'], 'importo'))->toBe([110])
        ->and(collect($conguaglio['per_gestione'][0]['per_periodo'])->firstWhere('ereditata_da', 'Venditore Ugo'))->toMatchArray(['decorrenza_acquisto' => '2026-05-01', 'giorni_periodo' => 245, 'giorni_entrante' => 122]);
});

it('rilievo B3, controprova — senza riserva la catena non cambia: costituzione dell\'usufrutto e poi estinzione, l\'ordinaria di Ugo è passata a Elsa usufruttuario il giorno della costituzione e torna a Ugo con l\'estinzione', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    ruRegistra($this, $s, ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Costituzione dell\'usufrutto, letta']);
    $rigaUsu = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->where('tipologia', 'usufruttuario')->value('id');

    $conguaglio = ruAnteprima($this, $s, [
        'tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUsu, 'decorrenza' => '2026-09-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Estinzione dell\'usufrutto, letta',
    ])['rate']['conguaglio'];
    expect(array_column($conguaglio['coppie'], 'importo'))->toBe([40110])
        ->and($conguaglio['per_gestione'][0]['per_periodo'][0])->toMatchArray(['ereditata_da' => 'Venditore Ugo', 'decorrenza_acquisto' => '2026-05-01', 'giorni_periodo' => 245, 'giorni_uscente' => 123, 'giorni_entrante' => 122]);
});

it('rilievo B3 — la catena prima della riserva: Ugo vende a Zeta, Zeta vende a Elsa con riserva, Elsa rivende la nuda a Carlo. Le ordinarie emesse a Ugo sono passate a Zeta con la coppia della prima vendita, le bozze con la vendita stessa, e Zeta le tiene come usufruttuario: né la riserva né la rivendita le toccano, e le frasi nominano Ugo e Zeta come intestatari e Zeta come usufruttuario', function () {
    $s = ruScenario('prima_rata', 0);
    $zeta = Anagrafica::forceCreate(['nome' => 'Venditrice Zeta', 'email' => "ru-z{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUZETAVENDI' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $zeta->condomini()->syncWithoutDetaching([$s['c']->id]);
    // Le rate da gennaio ad aprile emesse a Ugo prima della vendita (dalla .42, decisione 35: emesse dopo senza ricalcolo era la
    // forma di U1, e il conguaglio dopo si fermerebbe — decisione 48).
    ruEmetti($s);
    ruRegistra($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $zeta->id, 'decorrenza' => '2026-03-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-03-05', 'estremi_titolo' => 'rep. 1', 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Prima vendita, letto']);
    $rigaZ = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $zeta->id)->value('id');

    $riserva = ruRiserva(['rigaV' => $rigaZ] + $s);
    $conguaglio = ruAnteprima($this, $s, $riserva)['rate']['conguaglio'];
    expect($conguaglio['coppie'])->toBe([])
        // Dalla .42 il piano emesso prima della vendita è preso dal suo conguaglio: le 8 bozze da maggio sono passate a Zeta.
        ->and($conguaglio['quote_in_bozza'])->toBe([['piano' => 'Preventivo 2026', 'intestatario' => 'Venditrice Zeta', 'n' => 8, 'motivo' => 'ordinaria_riservata', 'usufruttuario' => 'Venditrice Zeta']])
        ->and(implode("\n", $conguaglio['frasi']))->toContain('Le 8 quote del piano «Preventivo 2026» non ancora emesse resteranno intestate a Venditrice Zeta: sono spese ordinarie, e Venditrice Zeta resta usufruttuario (art. 1004 c.c.)')
        ->toContain('Sulla gestione Ordinaria 2026: € 1.200,00 restano a Venditore Ugo e Venditrice Zeta — sono spese ordinarie, e Venditrice Zeta resta usufruttuario (art. 1004 c.c.): non passano a Acquirente Elsa.');
    ruRegistra($this, $s, $riserva);

    [, $rivendita] = ruRivendita($s);
    $conguaglio = ruAnteprima($this, $s, $rivendita)['rate']['conguaglio'];
    expect($conguaglio['coppie'])->toBe([])
        ->and($conguaglio['quote_in_bozza'])->toBe([['piano' => 'Preventivo 2026', 'intestatario' => 'Venditrice Zeta', 'n' => 8, 'motivo' => 'ordinaria_riservata', 'usufruttuario' => 'Venditrice Zeta']])
        ->and(implode("\n", $conguaglio['frasi']))->toContain('Sulla gestione Ordinaria 2026: € 1.200,00 restano a Venditore Ugo e Venditrice Zeta — sono spese ordinarie, e la vendita o donazione con riserva d\'usufrutto non le ha fatte passare a Acquirente Elsa: Venditrice Zeta resta usufruttuario (art. 1004 c.c.), e non passano nemmeno a Compratore Carlo.');
    ruRegistra($this, $s, $rivendita);

    // Una coppia sola in tutta la catena, quella della prima vendita: Zeta, 306 giorni, 120000 × 306/365 = 100603, meno le 8 bozze
    // passate (80000) = 20603. La riserva e la rivendita non toccano l'ordinaria.
    expect(Saldo::whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->count())->toBe(2)
        ->and((int) Saldo::whereNotNull('subentro_id')->where('anagrafica_id', $zeta->id)->sum('saldo_iniziale'))->toBe(20603)
        ->and(ruPerPersona($s['piano']))->toBe([$s['v']->id => 40000, $zeta->id => 80000]);
});

// --- Rilievo B1 della Fase 1-bis (decisione 28.5): il ricalcolo dopo la riserva segue i coefficienti --------------------

// Dalla 1.11.0-beta.41 il test di questo avviso sta in `OrdinariaDopoAttoTest` («rilievo B1 (decisione 28.5)» e «rilievo A5»):
// il consiglio vale solo per le voci bloccate da un piano approvato, e lo scenario ha bisogno dei suoi helper.

// Coda 171, chiusa nella 1.11.0-beta.41 (decisioni 29.1 e 31.5). Fino alla beta.40 era la sentinella dell'incoerenza: il
// ricalcolo dopo la riserva dava l'ordinaria, dal giorno dell'atto, al nudo proprietario (la voce sul «Proprietario» scende
// al nudo), mentre il conguaglio delle rate emesse la lasciava a chi vende. Ora la sceglie l'amministratore al passaggio:
// con la legge proposta (art. 1004 c.c.) la voce passa all'«Usufruttuario» e il ricalcolo dà l'ordinaria tutta a Ugo, come
// il conguaglio — una regola sola; con «come la voce» il ricalcolo resta quello di prima, e il conguaglio lo segue.
it('Coda 171 (decisione 31.5) — il ricalcolo dopo la riserva segue la scelta: con la legge proposta l\'ordinaria resta tutta a Ugo, usufruttuario; con «come la voce» Ugo fino al giorno prima dell\'atto ed Elsa da nuda proprietaria', function (?string $scelta, array $attesi, array $riparto) {
    $s = ruScenario('prima_rata', 0);
    ruPianoInBozza($s);
    ruRegistra($this, $s, ruRiserva($s, extra: $scelta === null ? [] : ['ordinaria_dopo_atto' => $scelta]));
    ruRicalcola($s);
    $nomi = ['U' => $s['v']->id, 'E' => $s['a']->id];

    expect(ruPerPersona($s['piano']))->toBe(collect($attesi)->mapWithKeys(fn ($v, $k) => [$nomi[$k] => $v])->sortKeys()->all())
        ->and(ruRiparto($s['piano']))->toBe(array_map(fn (array $r) => [$nomi[$r[0]], ...array_slice($r, 1)], $riparto));
})->with([
    // Con la legge (anche senza dirlo: è la proposta) la voce è sull'«Usufruttuario»: Ugo da proprietario fino al 30/04,
    // poi da usufruttuario — due righe, stessa persona.
    'la legge, senza dirlo' => [null, ['U' => 120000], [['U', 'proprietario', 39452, '2026-01-01', '2026-04-30'], ['U', 'usufruttuario', 80548, '2026-05-01', '2026-12-31']]],
    'la legge, scelta' => ['usufruttuario', ['U' => 120000], [['U', 'proprietario', 39452, '2026-01-01', '2026-04-30'], ['U', 'usufruttuario', 80548, '2026-05-01', '2026-12-31']]],
    // 120 giorni a Ugo proprietario; dal 1/5 nessuno è proprietario e la voce scende al nudo (decisione 22).
    'come la voce' => ['voce', ['U' => 39452, 'E' => 80548], [['U', 'proprietario', 39452, '2026-01-01', '2026-04-30'], ['E', 'nuda_proprietario', 80548, '2026-05-01', '2026-12-31']]],
]);

it('rilievo B1, controprova — con la voce sull\'«Usufruttuario», o sull\'«Inquilino», il ricalcolo non sposta l\'ordinaria: nessun avviso del ricalcolo, e ricalcolato il piano resta a chi lo pagava; senza inquilino resta la frase generica del cancello, e il ricalcolo chiede davvero la presa d\'atto del cancello (2) (Coda 173)', function (string $voce) {
    $s = ruScenario('prima_rata', 0, soggetto: $voce === 'U' ? 'usufruttuario' : 'inquilino', genera: false);
    if ($voce === 'I1') {
        $luca = Anagrafica::forceCreate(['nome' => 'Inquilino Luca', 'email' => "ru-l{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUINQUILINO' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
        $luca->condomini()->syncWithoutDetaching([$s['c']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $luca->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    $prima = ruPerPersona($s['piano']);

    $motivi = implode(' | ', ruAnteprima($this, $s, ruRiserva($s))['cancello']['motivi']);
    expect($motivi)->not->toContain('se lo ricalcoli')->not->toContain('nudo proprietario');
    if ($voce === 'I1') {
        // Il piano è tutto dell'inquilino: a Ugo non intesta niente, e il cancello non ne parla.
        expect($motivi)->not->toContain('il destinatario cambierebbe');
    } else {
        // Rilievo R1, deciso con la 28.8: la frase non è un difetto. Per il motore il destinatario è la risoluzione per
        // periodo — Ugo proprietario fino al giorno prima dell'atto, usufruttuario dal giorno dell'atto — e il ricalcolo, qui
        // sotto, chiede davvero la presa d'atto del cancello (2), anche se paga sempre Ugo. Confonde: il testo si chiarisce
        // nella Coda 173, e allora questa riga cambia.
        expect($motivi)->toContain('un piano rate già generato intesta quote a Venditore Ugo: il destinatario cambierebbe');
    }

    ruRegistra($this, $s, ruRiserva($s));
    if ($voce !== 'I1') {
        $s['piano']->rate()->delete();
        expect(fn () => app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']))
            ->toThrow(\App\Exceptions\Gestionale\DestinatariCambiatiException::class);
    }
    ruRicalcola($s);
    expect(ruPerPersona($s['piano']))->toBe($prima)
        ->and(array_values($prima))->toBe([120000]);
})->with([
    'voce sull\'«Usufruttuario»' => ['U'],
    'voce sull\'«Inquilino», senza inquilino' => ['I0'],
    'voce sull\'«Inquilino», con l\'inquilino dal primo giorno' => ['I1'],
]);

// Coda 173, testo da chiarire (decisione 28.8). Nella costituzione dell'usufrutto con la voce sul «Proprietario» il cancello
// dice «il destinatario cambierebbe», e paga sempre Ugo: proprietario fino al giorno prima dell'atto, nudo proprietario dal
// giorno dell'atto (catena proprietario → nudo). Non è un difetto: per il motore il destinatario è la risoluzione per
// periodo, e il ricalcolo chiede davvero la presa d'atto del cancello (2). Confonde: quando la Coda 173 riscrive la frase,
// questo test cambia. Anteriore alla beta; nella riserva lo stesso lo prova la controprova del rilievo B1, qui sopra.
it('Coda 173, testo da chiarire — nella costituzione dell\'usufrutto con la voce sul «Proprietario» il cancello dice «il destinatario cambierebbe» e il ricalcolo chiede davvero la presa d\'atto del cancello (2), anche se paga sempre la stessa persona', function () {
    $s = ruScenario('prima_rata', 0);
    $dati = ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Costituzione dell\'usufrutto, letta',
        // Dalla 1.11.0-beta.41: con la legge proposta (decisione 31.5) la voce passa all'«Usufruttuario» e il destinatario
        // cambia davvero; il testo della Coda 173 riguarda la voce che resta sul «Proprietario».
        'ordinaria_dopo_atto' => 'voce'];
    expect(implode(' | ', ruAnteprima($this, $s, $dati)['cancello']['motivi']))->toContain('un piano rate già generato intesta quote a Venditore Ugo: il destinatario cambierebbe');
    $prima = ruPerPersona($s['piano']);

    ruRegistra($this, $s, $dati);
    $s['piano']->rate()->delete();
    expect(fn () => app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']))
        ->toThrow(\App\Exceptions\Gestionale\DestinatariCambiatiException::class);
    ruRicalcola($s);

    expect(ruPerPersona($s['piano']))->toBe($prima)->and($prima)->toBe([$s['v']->id => 120000]);
});

it('rilievo B1, controprova — l\'avviso guarda solo le voci ordinarie con una competenza che arriva al giorno dell\'atto: non la straordinaria, che dal giorno dell\'atto è del nudo proprietario (art. 1005 c.c.), né il consuntivo dell\'anno prima', function () {
    $st = ruScenario('prima_rata', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6);
    expect(implode(' | ', ruAnteprima($this, $st, ruRiserva($st, extra: ['ordinaria_dopo_atto' => 'voce']))['cancello']['motivi']))
        ->toContain('il destinatario cambierebbe')->not->toContain('se lo ricalcoli');

    $s = ruScenario('prima_rata', 0);
    ruConsuntivo2025($s);
    $motivi = implode(' | ', ruAnteprima($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']))['cancello']['motivi']);
    expect($motivi)->toContain('il piano «Preventivo 2026», non ancora emesso, intesta quote a Venditore Ugo: se lo ricalcoli')
        ->not->toContain('il piano «Consuntivo 2025», non ancora emesso')
        // Il consuntivo resta nel cancello con la frase di sempre: è un piano generato con quote di chi vende.
        ->toContain('un piano rate già generato intesta quote a Venditore Ugo: il destinatario cambierebbe');
});

// --- Rilievo B2 della Fase 1-bis (decisione 28.6), superato dalla Coda 170 (decisione 31.1, 1.11.0-beta.41) ----------------
// Fino alla beta.40 la riserva su una quota lasciava un'unità mista che i piani non sapevano dividere, e l'anteprima lo diceva
// con un avviso (anche sulle pertinenze). Dalla beta.41 il motore la divide per quote: l'avviso non c'è più, e l'avviso del
// ricalcolo (rilievo B1), che sull'unità mista taceva perché il ricalcolo non arrivava al nudo proprietario, vale anche qui.

it('Coda 170 (decisione 31.1) — Ugo vende con riserva la nuda proprietà della sua metà e Rita resta proprietaria piena: nessun avviso dell\'unità mista, e con «come la voce» il cancello avvisa del ricalcolo come su un\'unità intera; la registrazione passa', function () {
    [$s] = ruDueGenitori();
    $dati = ruRiserva($s, extra: ['quota' => 50, 'ordinaria_dopo_atto' => 'voce']);

    $anteprima = ruAnteprima($this, $s, $dati);
    expect($anteprima['anagrafica'])->not->toHaveKey('avvisi')
        ->and(implode(' | ', $anteprima['cancello']['motivi']))->not->toContain('piena proprietà')->toContain('se lo ricalcoli');

    ruRegistra($this, $s, $dati);
    expect(DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->value('tipologia'))->toBe('nuda_proprietario');
});

// Coda 170, corretta nella 1.11.0-beta.41 (decisione 31.1). Fino alla beta.40 questa era la sentinella del difetto: il
// motore normalizzava ogni ruolo sulle sue quote, e Bice pagava € 903,09 con la voce sul «Proprietario», € 1.200,00 sulla
// straordinaria deliberata dopo l'atto, mentre con la voce sull'«Usufruttuario» Ugo pagava dal 1/05 anche la metà di Bice
// (€ 1.002,74). Ora il ruolo che paga si unisce al gemello nell'unità (proprietario pieno con nudo proprietario, usufruttuario
// con proprietario pieno) e la voce si divide per quote e giorni: Bice paga la sua metà, € 600,00, in tutti i casi.
it('Coda 170 (decisione 31.1) — l\'unità mista dopo una riserva su metà: Bice paga la sua metà in ogni caso, e l\'altra metà va a chi la voce indica fra usufruttuario e nudo proprietario, per i giorni', function (string $natura, string $soggetto, array $attesi, ?string $scelta = null) {
    [$s, $bice] = ruMista($this, $natura, $soggetto, $scelta === null ? [] : ['ordinaria_dopo_atto' => $scelta]);
    $nomi = ['U' => $s['v']->id, 'E' => $s['a']->id, 'B' => $bice->id];
    $atteso = collect($attesi)->mapWithKeys(fn ($importo, $chi) => [$nomi[$chi] => $importo])->all();
    ksort($atteso);

    expect(ruPerPersona($s['piano']))->toBe($atteso);
})->with([
    // Voce sul «Proprietario» (il capitale): Bice 50 tutto l'anno; l'altra metà a Ugo da proprietario fino al 30/04 (120
    // giorni, € 197,26) e a Elsa da nuda proprietaria dal 1/05 (245 giorni, € 402,74).
    'ordinaria sul «Proprietario», come la voce' => ['ordinaria', 'proprietario', ['U' => 19726, 'E' => 40274, 'B' => 60000], 'voce'],
    // Con la legge proposta (decisione 31.5) la voce passa all'«Usufruttuario» al passaggio: il godimento, come qui sotto.
    'ordinaria sul «Proprietario», con la legge proposta' => ['ordinaria', 'proprietario', ['U' => 60000, 'B' => 60000]],
    // Voce sull'«Usufruttuario» (il godimento): l'altra metà è di Ugo tutto l'anno, prima da proprietario e poi da usufruttuario.
    'ordinaria sull\'«Usufruttuario»' => ['ordinaria', 'usufruttuario', ['U' => 60000, 'B' => 60000]],
    'ordinaria sull\'«Inquilino», senza inquilino' => ['ordinaria', 'inquilino', ['U' => 60000, 'B' => 60000]],
    // Straordinaria deliberata il 20/5: quel giorno il capitale è di Bice e di Elsa, metà ciascuna.
    'straordinaria sul «Proprietario» deliberata dopo l\'atto' => ['straordinaria', 'proprietario', ['B' => 60000, 'E' => 60000]],
]);

// --- Testi T2 e T5, difetto V1 della verifica a video ---------------------------------------------------------------------

it('testi T2 (V2 della verifica a video) — il cancello legge dal calcolo perché restano le bozze di un piano già emesso: quelle escluse per legge «restano sue: il conguaglio non le tocca», non «sono comprese nel conguaglio»', function (string $caso) {
    if ($caso === 'straordinaria_del_nudo') {
        // Costituzione dell'usufrutto su una straordinaria deliberata prima: la straordinaria resta a chi resta nudo proprietario.
        $s = ruScenario('prima_rata', 0, 'straordinaria', '2026-03-15', '2026-04-05', 6);
        ruEmetti($s);
        $dati = ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01',
            'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Costituzione dell\'usufrutto, letta'];
        [$piano, $n] = ['Rifacimento facciata', 5];
    } elseif ($caso === 'ordinaria_dell_usufruttuario') {
        // R4: costituzione a Ursula, poi Ugo vende la nuda proprietà a Carlo; le ordinarie del piano generato prima sono dell'usufruttuaria.
        $s = ruScenario('prima_rata', 0);
        ruEmetti($s);
        $ursula = Anagrafica::forceCreate(['nome' => 'Usufruttuaria Ursula', 'email' => "ru-uu{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUUSUFRUTT' . str_pad((string) $s['unita']->id, 6, '0', STR_PAD_LEFT)]);
        $carlo = Anagrafica::forceCreate(['nome' => 'Compratore Carlo', 'email' => "ru-cc{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUCOMPRATOR' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
        $ursula->condomini()->syncWithoutDetaching([$s['c']->id]);
        $carlo->condomini()->syncWithoutDetaching([$s['c']->id]);
        ruRegistra($this, $s, ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $ursula->id, 'decorrenza' => '2026-05-01',
            'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Costituzione dell\'usufrutto, letta']);
        ruEmetti($s, '2026-08-31');
        $rigaNuda = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'nuda_proprietario')->value('id');
        $dati = ['tipo' => 'vendita', 'riga_uscente_id' => $rigaNuda, 'anagrafica_entrante_id' => $carlo->id, 'decorrenza' => '2026-09-01', 'quota' => 100, 'tipologia' => 'nuda_proprietario',
            'copia_autentica' => true, 'copia_autentica_il' => '2026-09-05', 'estremi_titolo' => 'rep. 3', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Vendita della nuda, letta'];
        [$piano, $n] = ['Preventivo 2026', 4];
    } else {
        $s = ruScenario('prima_rata', 0);
        ruEmetti($s);
        $dati = ruRiserva($s);
        [$piano, $n] = ['Preventivo 2026', 8];
    }

    $anteprima = ruAnteprima($this, $s, $dati);
    expect(collect($anteprima['rate']['conguaglio']['quote_in_bozza'])->pluck('motivo')->unique()->values()->all())->toBe([$caso]);
    // Cantiere C6 (decisione di Vincenzo del 29/09): qui tutte le quote, emesse e in bozza, restano per legge a chi le ha —
    // nessuna spunta, e le frasi restano nel pannello come informazione.
    expect($anteprima['cancello']['richiesto'])->toBeFalse()
        ->and($anteprima['cancello']['motivi'])->toBe([]);
    expect(implode(' | ', $anteprima['cancello']['informazioni']))
        ->toContain("il piano «{$piano}» ha {$n} quote non ancora emesse intestate a Venditore Ugo: non si può più ricalcolare, restano sue: il conguaglio non le tocca")
        ->not->toContain('comprese nel conguaglio');
})->with(['ordinaria_riservata', 'ordinaria_dell_usufruttuario', 'straordinaria_del_nudo']);

it('testi T5 e V1 — nella riserva la straordinaria segue la competenza (la data della delibera, o quella dichiarata sulla fattura) e dal giorno dell\'atto è del nudo proprietario; la gestione esclusa è l\'ordinaria, ed è quella che la vista etichetta', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    $anteprima = ruAnteprima($this, $s, ruRiserva($s));
    expect(implode("\n", $anteprima['rate']['frasi']))
        ->toContain('La quota straordinaria va a chi era titolare alla data della delibera o, se la fattura dichiara la competenza, si divide per giorni su quella; dal 1 maggio 2026 il titolare è Acquirente Elsa, nudo proprietario (art. 1005 c.c.).')
        ->not->toContain('va per intero a chi era titolare alla data della delibera');
    // V1: la colonna della gestione esclusa si decide su `natura` (AnteprimaPassaggio.vue, `etichettaEsclusa`).
    $g = $anteprima['rate']['conguaglio']['per_gestione'][0];
    expect([$g['natura'], $g['escluse'], $g['importo']])->toBe(['ordinaria', 12, 0]);
});

// --- Testi T1, T4 e T7 della Fase 1-bis (V4 della verifica a video è il gemello di T1, nel modulo) -------------------------

it('testi T1 — la copia autentica registrata dopo, dallo storico, su una riserva: il messaggio dice che chi vende non è liberato e perché (art. 67 ult. co., decisione 28.3), non che è liberato come nella vendita', function () {
    $s = ruScenario('prima_rata', 0);
    $subentro = ruRegistra($this, $s, ruRiserva($s, extra: ['copia_autentica' => false, 'copia_autentica_il' => null]));

    $r = $this->actingAs($this->user)->patch(route('admin.gestionale.immobili.passaggi.copia-autentica', [$s['c'], $s['unita'], $subentro]), ['copia_autentica_il' => '2026-05-20']);
    $r->assertRedirect();
    $messaggio = $r->getSession()->get('message');
    expect($messaggio['type'])->toBe('success')
        ->and($messaggio['message'])->toBe('Copia autentica registrata, ricevuta il 20 maggio 2026: per i contributi successivi Venditore Ugo non è liberato, perché resta usufruttuario e risponde in solido con il nudo proprietario (art. 67 ult. co. disp. att. c.c.).')
        // La copia si registra come per ogni vendita: la data resta sul passaggio e il vademecum la cita.
        ->and($subentro->fresh()->copia_autentica_il->toDateString())->toBe('2026-05-20');
});

it('testi T4 — nota di solidarietà della riserva: il punto aperto (art. 63 co. 4) conta solo le quote scadute prima dell\'atto e non cresce con le emissioni; quelle che scadono dopo, di chi vende e resta usufruttuario, sono in solido (art. 67 ult. co.) e si dicono a parte', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s));
    $nota = fn () => app(NotaSolidarieta::class)->per($s['c'], $s['a'])[0];
    $prima = $nota();
    // Le rate di maggio-agosto, emesse a Ugo come usufruttuario: la cifra del punto aperto resta € 400,00 (prima diventava € 800,00).
    ruEmetti($s, '2026-08-31');
    $dopo = $nota();

    expect([$prima['residuo_uscente_cents'], $dopo['residuo_uscente_cents']])->toBe([40000, 40000]);
    expect($prima['testo'])->toContain('Nessuna quota è intestata a Acquirente Elsa per quel periodo: a nome di Venditore Ugo risultano oggi € 400,00 non pagati su questa unità con scadenza prima del 1 maggio 2026.')
        ->not->toContain('ne risultano oggi');
    expect($dopo['testo'])
        ->toContain('rispondono in solido per i contributi di Interno 1 (Int. 1) (art. 67 ult. co. disp. att. c.c.): a nome di Venditore Ugo ne risultano oggi € 400,00 non pagati, con scadenza da quel giorno in poi.')
        ->toContain('a nome di Venditore Ugo risultano oggi € 400,00 non pagati su questa unità con scadenza prima del 1 maggio 2026.')
        ->not->toContain('€ 800,00');
});

it('testi T4, controprova — la nota della vendita piena non cambia: con otto rate emesse a Ugo prima di registrarla il residuo è uno solo, € 800,00, e il testo è quello di sempre', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-08-31');
    ruRegistra($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'rep. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Vendita piena, rogito letto']);

    $nota = app(NotaSolidarieta::class)->per($s['c'], $s['a'])[0];
    expect($nota['residuo_uscente_cents'])->toBe(80000)
        ->and($nota['testo'])->toBe('Acquirente Elsa risponde in solido con Venditore Ugo per i contributi di Interno 1 (Int. 1) relativi agli esercizi 2026 e 2025 (art. 63 co. 4 disp. att. c.c.). Nessuna quota è intestata a Acquirente Elsa per quel periodo: a nome di Venditore Ugo risultano oggi € 800,00 non pagati su questa unità. Chi paga in forza della solidarietà ha regresso verso il venditore, salvo diverso accordo (Cass. 11199/2021).');
});

it('testi T7 — nello storico anche le righe toccate dalla riserva portano il sottotipo del passaggio (la riga chiusa di chi vende, la sua riga d\'usufrutto, la nuda proprietà di chi compra), così la riga e il passaggio hanno lo stesso nome', function () {
    $s = ruScenario('prima_rata', 0);
    ruRegistra($this, $s, ruRiserva($s));

    $righe = collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['righe'])
        ->map(fn ($r) => [$r['anagrafica']['id'], $r['tipologia'], $r['subentro']['ruolo_nel_passaggio'] ?? null, $r['subentro']['tipo_passaggio'] ?? null, $r['subentro']['sottotipo'] ?? null])
        ->sortBy(fn ($r) => $r[1])->values()->all();
    expect($righe)->toBe([
        [$s['a']->id, 'nuda_proprietario', 'entrante', 'vendita', 'riserva_usufrutto'],
        [$s['v']->id, 'proprietario', 'uscente', 'vendita', 'riserva_usufrutto'],
        [$s['v']->id, 'usufruttuario', 'continuazione', 'vendita', 'riserva_usufrutto'],
    ]);
});

// --- Cantiere C6 della beta.38: il cancello che informa, la via dell'«Usufruttuario», le frasi dell'usufrutto e la nota --

it('cancello, decisione di Vincenzo del 29/09 — la rivendita della nuda proprietà dopo la riserva, senza nient\'altro: le quote restano per legge a chi le ha, il pannello lo dice come informazione e non chiede la spunta; la registrazione passa senza presa d\'atto', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s));

    [, $rivendita] = ruRivendita($s);
    $cancello = ruAnteprima($this, $s, $rivendita)['cancello'];
    expect($cancello['richiesto'])->toBeFalse()
        ->and($cancello['motivi'])->toBe([])
        ->and($cancello['informazioni'] ?? null)->toBe([
            '4 quote di rate già emesse a Venditore Ugo: restano sue, questo passaggio non le tocca',
            // Referto C3, terzo dubbio: le bozze riservate non «sono comprese nel conguaglio» (corretto da C4, testi T2).
            'il piano «Preventivo 2026» ha 8 quote non ancora emesse intestate a Venditore Ugo: non si può più ricalcolare, restano sue: il conguaglio non le tocca',
        ]);

    // Il cancello del server risponde come il pannello: senza spunta né nota la rivendita si registra.
    ruRegistra($this, $s, array_merge($rivendita, ['ho_letto' => false, 'nota_cancello' => null]));
});

it('cancello, controprova — con un motivo vero la spunta resta: nella rivendita un piano ancora ricalcolabile intesta quote a chi rivende, e le quote che restano per legge si dicono lo stesso, a parte', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s));
    // Un piano in bozza, mai emesso, con una quota a Elsa: ricalcolato, il destinatario cambierebbe (decisione 14).
    $piano = DB::table('piani_rate')->insertGetId([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'nome' => 'Conguaglio luglio', 'numero_rate' => 1, 'giorno_scadenza' => 5,
        'metodo_distribuzione' => 'tutte_rate', 'attivo' => true, 'stato' => 'approvato', 'tipo' => 'ordinario', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $rata = DB::table('rate')->insertGetId(['piano_rate_id' => $piano, 'numero_rata' => 1, 'data_scadenza' => '2026-10-05', 'importo_totale' => 5000, 'stato' => 'bozza', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate_quote')->insert(['rata_id' => $rata, 'anagrafica_id' => $s['a']->id, 'immobile_id' => $s['unita']->id, 'importo' => 5000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'tipo' => 'ordinaria', 'data_scadenza' => '2026-10-05', 'created_at' => now(), 'updated_at' => now()]);

    [, $rivendita] = ruRivendita($s);
    $cancello = ruAnteprima($this, $s, $rivendita)['cancello'];
    expect($cancello['richiesto'])->toBeTrue()
        ->and($cancello['motivi'])->toBe(['un piano rate già generato intesta quote a Acquirente Elsa: il destinatario cambierebbe'])
        ->and($cancello['informazioni'] ?? null)->toHaveCount(2);

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), array_merge($rivendita, ['ho_letto' => false, 'nota_cancello' => null]))
        ->assertSessionHasErrors('nota_cancello');
});

it('cancello, nella riserva stessa — l\'ordinaria emessa e le bozze restano per legge a chi vende: nessuna spunta; la straordinaria deliberata dopo l\'atto passa a chi compra, e la spunta resta', function (string $natura, bool $richiesto, array $motivi, array $informazioni) {
    $s = $natura === 'ordinaria' ? ruScenario('prima_rata', 0) : ruScenario('prima_rata', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6);
    ruEmetti($s, $natura === 'ordinaria' ? '2026-04-30' : '2026-07-31');

    $cancello = ruAnteprima($this, $s, ruRiserva($s))['cancello'];
    expect($cancello['richiesto'])->toBe($richiesto)
        ->and($cancello['motivi'])->toBe($motivi)
        ->and($cancello['informazioni'] ?? null)->toBe($informazioni);
})->with([
    'ordinaria: quattro rate emesse e otto bozze, tutte di chi vende come usufruttuario' => ['ordinaria', false, [], [
        '4 quote di rate già emesse a Venditore Ugo su questa unità: restano sue, questo passaggio non le tocca',
        'il piano «Preventivo 2026» ha 8 quote non ancora emesse intestate a Venditore Ugo: non si può più ricalcolare, restano sue: il conguaglio non le tocca',
    ]],
    'straordinaria deliberata dopo l\'atto: le emesse si conguagliano e le bozze passano' => ['straordinaria', true, [
        '2 quote di rate già emesse a Venditore Ugo su questa unità',
        'il piano «Rifacimento facciata» ha 4 quote non ancora emesse intestate a Venditore Ugo: non si può più ricalcolare, passano a Acquirente Elsa (cambia l\'intestatario, non l\'importo)',
    ], []],
]);

it('decisione 29.3 — costituzione dell\'usufrutto: verso il condominio nudo proprietario e usufruttuario rispondono in solido dal giorno dell\'atto (art. 67 ult. co.), la natura della spesa conta fra le parti; l\'anteprima e il vademecum dello storico dicono la stessa frase', function () {
    $s = ruScenario('prima_rata', 0);
    $dati = ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Costituzione dell\'usufrutto, letta'];
    // Il modello è la frase della riserva (`FrasiObbligati::frasiRiserva`): ciò che è solido verso il condominio, poi fra le parti.
    $attesa = 'Dal 1 maggio 2026 Venditore Ugo, nudo proprietario, e Acquirente Elsa, usufruttuario, rispondono in solido verso il condominio (art. 67 ult. co. disp. att. c.c.); fra di loro le spese ordinarie sono dell\'usufruttuario (art. 1004 c.c.), quelle straordinarie del nudo proprietario (art. 1005 c.c.). Come il programma li addebita è scritto nella guida «Ruoli e usufrutto».';

    expect(ruAnteprima($this, $s, $dati)['obbligati']['frasi'])->toBe([$attesa]);
    $subentro = ruRegistra($this, $s, $dati);
    expect(app(FrasiObbligati::class)->daSubentro($subentro))->toBe([$attesa]);
});

it('straordinaria esclusa in una catena (referto C3, ultimo dubbio) — Zeta costituisce l\'usufrutto a Elsa, e le straordinarie sono emesse a Ugo, che le aveva prima: la frase nomina Ugo, l\'intestatario vero, non chi esce', function () {
    [$s, , $rigaZ] = ruStraordinariaDopoUnaVendita($this, '2026-02-15');

    $anteprima = ruAnteprima($this, $s, ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $rigaZ, 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Costituzione dell\'usufrutto, letta']);
    $conguaglio = $anteprima['rate']['conguaglio'];
    expect($conguaglio['coppie'])->toBe([])
        ->and($conguaglio['per_gestione'][0]['escluse_di'])->toBe(['Venditore Ugo']);
    expect(implode("\n", $conguaglio['frasi']))
        ->toContain('Sulla gestione Facciata (straordinaria): € 1.200,00 restano interamente a Venditore Ugo — le spese straordinarie sono del nudo proprietario (art. 1005 c.c.), il passaggio non le tocca.')
        ->not->toContain('restano interamente a Venditrice Zeta');
});

it('straordinaria in una catena, piano senza righe di riparto (anteriore alla beta.29) — Zeta rivende a Elsa: le quote sono emesse a Ugo, e la frase dice a chi sono emesse e di chi era l\'unità alla delibera, non «restano a Zeta, quando l\'unità era sua»', function (string $delibera, string $eraDi, string $aUgo) {
    [$s, , $rigaZ] = ruStraordinariaDopoUnaVendita($this, $delibera, senzaRighe: true);

    $conguaglio = ruAnteprima($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $rigaZ, 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'rep. 2', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Seconda vendita, letto'])['rate']['conguaglio'];
    $data = $delibera === '2026-02-15' ? '15 febbraio 2026' : '15 marzo 2026';
    $frasi = implode("\n", $conguaglio['frasi']);
    expect($conguaglio['coppie'])->toBe([])
        ->and($frasi)
        ->toContain("Sulla gestione Facciata (straordinaria): le quote emesse a Venditore Ugo ({$aUgo}) non passano a chi entra: l'assemblea ha deliberato il {$data}, quando l'unità era di {$eraDi} (art. 63 disp. att. c.c.; Cass. civ. 30 agosto 2025 n. 24236).");
    // Dalla .42 il piano è emesso (marzo e aprile, € 400,00) prima della vendita a Zeta, che lo prende nel conguaglio: le quattro
    // bozze seguono la delibera. Deliberata prima, restano a Ugo; deliberata dopo, passano a Zeta, e sono davvero sue.
    $delibera === '2026-02-15'
        ? expect($frasi)->not->toContain('restano interamente a Venditrice Zeta')
        : expect($frasi)->toContain("Sulla gestione Facciata (straordinaria): € 800,00 restano interamente a Venditrice Zeta, perché l'assemblea ha deliberato il 15 marzo 2026, quando l'unità era sua");
})->with([
    'deliberata prima della vendita a Zeta: l\'unità era di Ugo' => ['2026-02-15', 'Venditore Ugo', '€ 1.200,00'],
    'deliberata dopo la vendita a Zeta: l\'unità era sua' => ['2026-03-15', 'Venditrice Zeta', '€ 400,00'],
]);

it('testi T5, vendita piena — il blocco 2 dice che la straordinaria segue la competenza (la data della delibera, o quella dichiarata sulla fattura), come la frase della riserva', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    $frasi = implode("\n", ruAnteprima($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'rep. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Vendita piena, rogito letto'])['rate']['frasi']);
    expect($frasi)
        ->toContain('Le rate già emesse non si toccano. Il conguaglio fra Venditore Ugo e Acquirente Elsa è proposto come due righe di saldo che sommano a zero, sulla gestione di ciascun piano: la quota ordinaria è divisa in proporzione ai giorni; la quota straordinaria va a chi era titolare alla data della delibera o, se la fattura dichiara la competenza, si divide per giorni su quella (art. 63 disp. att. c.c.; Cass. civ. 30 agosto 2025 n. 24236).')
        ->not->toContain('per intero a chi era titolare alla data della delibera');
});

it('nota di solidarietà della riserva, straordinarie — conta la data della delibera, non la scadenza della rata (decisione 25): deliberata prima dell\'atto è arretrato anche con rate che scadono dopo, e senza data della delibera lo è per prudenza; deliberata dopo non è nel punto aperto (art. 63 co. 4)', function (string $caso, int $puntoAperto, array $contiene, string $assente) {
    $s = $caso === 'dopo'
        ? ruScenario('prima_rata', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6)
        : ruScenario('prima_rata', 0, 'straordinaria', '2026-03-15', '2026-04-05', 6);
    // Emesse a Ugo e non pagate: aprile–luglio (€ 800,00) con la delibera di marzo, giugno–luglio (€ 400,00) con quella di maggio.
    ruEmetti($s, '2026-07-31');
    ruRegistra($this, $s, ruRiserva($s));
    if ($caso === 'senza delibera') {
        DB::table('piani_rate')->where('id', $s['piano']->id)->update(['data_delibera_assemblea' => null]);
    }

    $nota = app(NotaSolidarieta::class)->per($s['c'], $s['a'])[0];
    // `residuo_uscente_cents` è il punto aperto (art. 63 co. 4); le certe (art. 67 ult. co.) le dice il testo.
    expect($nota['residuo_uscente_cents'])->toBe($puntoAperto)
        ->and($nota['testo'])->not->toContain($assente);
    foreach ($contiene as $frase) {
        expect($nota['testo'])->toContain($frase);
    }
})->with([
    'deliberata il 15 marzo, rate da aprile: tutto arretrato' => ['prima', 80000, [
        'Nessuna quota è intestata a Acquirente Elsa per quel periodo: a nome di Venditore Ugo risultano oggi € 800,00 non pagati su questa unità per contributi sorti prima del 1 maggio 2026.',
        'Per le spese straordinarie conta la data della delibera, per le altre la scadenza della rata.',
    ], 'ne risultano oggi'],
    'senza data della delibera: arretrato, per prudenza' => ['senza delibera', 80000, [
        'a nome di Venditore Ugo risultano oggi € 800,00 non pagati su questa unità per contributi sorti prima del 1 maggio 2026.',
        'Le spese straordinarie senza data della delibera (€ 800,00) sono contate prima dell\'atto.',
    ], 'ne risultano oggi'],
    // Delle certe (art. 67 ult. co.) qui non si dice la cifra: è quella della Coda 172, fissata dalla sua sentinella, sotto.
    'deliberata il 20 maggio: niente nel punto aperto' => ['dopo', 0, [
        'a nome di Venditore Ugo non risulta oggi nulla di non pagato su questa unità per contributi sorti prima del 1 maggio 2026.',
    ], 'senza data della delibera'],
]);

// Sentinella della Coda 172: fissa il comportamento di oggi, noto come sbagliato. La straordinaria deliberata il 20 maggio,
// dopo l'atto, è di chi compra: le due quote emesse a Ugo prima della registrazione (giugno e luglio, € 400,00) la coppia
// del conguaglio le ha già spostate su Elsa, ma la nota non legge la coppia e le conta fra le certe di Ugo. Quando la coda
// si corregge questo test deve cambiare; il punto aperto (zero) lo asserisce, e resta, il test qui sopra.
it('sentinella della Coda 172 — fissa il comportamento di oggi, noto come sbagliato: nella nota della riserva, con la straordinaria deliberata dopo l\'atto, le quote emesse a chi vende (€ 400,00) stanno fra le certe anche se la coppia le ha già spostate su chi compra; quando la coda si corregge questo test deve cambiare', function () {
    $s = ruScenario('prima_rata', 0, 'straordinaria', '2026-05-20', '2026-06-05', 6);
    ruEmetti($s, '2026-07-31');
    ruRegistra($this, $s, ruRiserva($s));

    // La coppia: € 400,00 a debito di Elsa, a credito di Ugo.
    expect(ruCoppia($s))->toBe([40000, -40000]);
    expect(app(NotaSolidarieta::class)->per($s['c'], $s['a'])[0]['testo'])
        ->toContain('(art. 67 ult. co. disp. att. c.c.): a nome di Venditore Ugo ne risultano oggi € 400,00 non pagati, per contributi sorti da quel giorno in poi.');
});

// --- La mappa dei casi della beta.38: i test mirati (le celle che le griglie di `InvariantiPassaggiTest` non dicono) ----

it('mappa dei casi — riserva all\'altro comproprietario pieno: Rita resta proprietaria piena della sua metà ed entra nuda proprietaria dell\'altra, Ugo resta usufruttuario; anteprima e registrazione passano, e l\'annullamento rimette le righe', function () {
    [$s, $madre] = ruDueGenitori();
    $righePrima = ruRighe($s['unita']->id);
    $riserva = ruRiserva($s, extra: ['quota' => 50, 'anagrafica_entrante_id' => $madre->id]);

    ruAnteprima($this, $s, $riserva);
    $subentro = ruRegistra($this, $s, $riserva);

    // Due righe della stessa persona con due ruoli: la riga piena non si somma con la nuda (decisione A vale sullo stesso ruolo).
    expect(ruRighe($s['unita']->id))->toBe([
        [$s['v']->id, 'proprietario', 50.0, '2019-01-01', '2026-04-30'],
        [$madre->id, 'proprietario', 50.0, '2019-01-01', null],
        [$s['v']->id, 'usufruttuario', 50.0, '2026-05-01', null],
        [$madre->id, 'nuda_proprietario', 50.0, '2026-05-01', null],
    ]);
    // Il denaro e il testo dell'avviso dell'unità mista non si asseriscono qui: sono della Coda 170.

    ruAnnulla($this, $s, $subentro)->assertRedirect();
    expect(ruRighe($s['unita']->id))->toBe($righePrima);
});

it('mappa dei casi — la quota dichiarata nella riserva è quella di chi vende anche su una comproprietà: Ugo ha il 50 %, e dichiararne il 30 % si ferma sulla quota, a righe intatte', function () {
    [$s] = ruDueGenitori();
    $righe = ruRighe($s['unita']->id);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), ruRiserva($s, extra: ['quota' => 30]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['quota' => 'Nella vendita o donazione con riserva d\'usufrutto chi compra riceve la nuda proprietà di tutta la quota di chi vende (50 %)']);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), ruRiserva($s, extra: ['quota' => 30]))
        ->assertSessionHasErrors('quota');
    expect(ruRighe($s['unita']->id))->toBe($righe);
});

it('mappa dei casi — i due genitori donano con riserva una straordinaria deliberata il 20 maggio: con lo stesso atto del 1 maggio la spesa è tutta del figlio e le bozze di entrambi passano; con la seconda riserva il 1 giugno la metà della madre, deliberata quando era piena proprietaria, resta sua', function (string $forma, array $attesoPerPersona, array $coppie) {
    $s = ruScenario('prima_rata', 0, 'straordinaria', '2026-05-20', '2026-03-05', 6, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $madre = Anagrafica::forceCreate(['nome' => 'Madre Rita', 'email' => "ru-m{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUMADRERITA' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $madre->condomini()->syncWithoutDetaching([$s['c']->id]);
    $rigaM = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $madre->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    // Il piano di gennaio: sei rate da marzo, € 100,00 a testa per rata; marzo e aprile emesse prima dell'atto.
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s);

    $prima = ruRegistra($this, $s, ruRiserva($s, extra: ['quota' => 50]));
    if ($forma === 'S2') {
        // L'emissione segue il tempo: maggio si emette prima della seconda riserva (la quota di Ugo è già di Elsa).
        ruEmettiBozze($s, $s['piano'], '2026-05-31');
    }
    $seconda = ruRegistra($this, $s, ruRiserva(['rigaV' => $rigaM] + $s, $forma === 'S2' ? '2026-06-01' : '2026-05-01', ['quota' => 50, 'copia_autentica_il' => $forma === 'S2' ? '2026-06-05' : '2026-05-06']));

    $nomi = ['U' => $s['v']->id, 'R' => $madre->id, 'E' => $s['a']->id];
    $perId = fn (array $x) => collect($x)->mapWithKeys(fn ($v, $k) => [$nomi[$k] => $v])->sortKeys()->all();
    $coppia = fn (Subentro $p) => Saldo::where('subentro_id', $p->id)->pluck('saldo_iniziale', 'anagrafica_id')->map(fn ($v) => (int) $v)->sortKeys()->all();
    expect(ruPerPersona($s['piano']))->toBe($perId($attesoPerPersona))
        ->and($coppia($prima))->toBe($perId($coppie[0]))
        ->and($coppia($seconda))->toBe($perId($coppie[1]));
})->with([
    // Ugo: le quattro bozze da maggio (€ 400,00) a Elsa, e la coppia le fa pagare marzo e aprile (€ 200,00). Lo stesso per Rita.
    'S1, lo stesso atto: tutto al figlio' => ['S1', ['U' => 20000, 'R' => 20000, 'E' => 80000], [['U' => -20000, 'E' => 20000], ['R' => -20000, 'E' => 20000]]],
    // Alla delibera Rita era proprietaria piena della sua metà: niente passa e niente coppia (decisione 25).
    'S2, la seconda riserva il 1 giugno: la metà della madre resta sua' => ['S2', ['U' => 20000, 'R' => 60000, 'E' => 40000], [['U' => -20000, 'E' => 20000], []]],
]);

it('mappa dei casi, rilievo B3 — nella rivendita della nuda proprietà la straordinaria segue la delibera: deliberata fra la riserva e la rivendita resta a chi rivende, deliberata dopo la rivendita è tutta di chi compra; chi si è riservato l\'usufrutto non è mai toccato', function (string $delibera, int $coppiaCarlo, int $bozzeACarlo) {
    // Sei rate da aprile, € 200,00 l'una, generate a gennaio a Ugo; aprile emessa prima della riserva.
    $s = ruScenario('prima_rata', 0, 'straordinaria', $delibera, '2026-04-05', 6);
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s));
    // Delibera dopo il 1 maggio in tutti e due i casi: le bozze da maggio a Elsa, e la coppia le fa pagare aprile.
    expect(ruCoppia($s))->toBe([20000, -20000]);
    ruEmettiBozze($s, $s['piano'], '2026-08-31');
    $diUgo = fn () => [
        DB::table('rate_quote')->where('anagrafica_id', $s['v']->id)->orderBy('id')->get(['id', 'importo', 'rata_id', 'regole_calcolo'])->toArray(),
        Saldo::where('anagrafica_id', $s['v']->id)->orderBy('id')->get(['id', 'saldo_iniziale', 'subentro_id'])->toArray(),
    ];
    $ugoPrima = $diUgo();

    [$carlo, $rivendita] = ruRivendita($s);
    $conguaglio = ruAnteprima($this, $s, $rivendita)['rate']['conguaglio'];
    expect(count($conguaglio['bozze_riassegnate']))->toBe($bozzeACarlo);
    $subentro = ruRegistra($this, $s, $rivendita);

    $coppia = Saldo::where('subentro_id', $subentro->id)->pluck('saldo_iniziale', 'anagrafica_id')->map(fn ($v) => (int) $v)->all();
    expect($coppia)->toBe($coppiaCarlo === 0 ? [] : [$s['a']->id => -$coppiaCarlo, $carlo->id => $coppiaCarlo])
        ->and($diUgo())->toEqual($ugoPrima);
})->with([
    // Il 15 luglio l'unità era di Elsa, nuda proprietaria: la spesa è sua (art. 1005 c.c.), la bozza di settembre resta a lei.
    'deliberata il 15 luglio, fra la riserva e la rivendita' => ['2026-07-15', 0, 0],
    // Il 20 settembre è di Carlo: la bozza di settembre gli passa, e la coppia gli fa pagare il resto della spesa (€ 1.000,00,
    // da aprile ad agosto: aprile emessa a Ugo, con la competenza passata a Elsa dalla coppia della riserva, maggio-agosto
    // emesse a Elsa).
    'deliberata il 20 settembre, dopo la rivendita' => ['2026-09-20', 100000, 1],
]);

it('mappa dei casi — nota di solidarietà della riserva, il giorno dell\'atto: la rata che scade quel giorno e la delibera di quel giorno sono dopo l\'atto, fuori dal punto aperto (art. 63 co. 4)', function (string $natura, int $puntoAperto) {
    if ($natura === 'ordinaria') {
        // L'atto il 5 maggio, il giorno della scadenza della quinta rata: gennaio-aprile sono arretrato, maggio no.
        $s = ruScenario('prima_rata', 0);
        ruEmetti($s, '2026-05-31');
        ruRegistra($this, $s, ruRiserva($s, '2026-05-05'));
    } else {
        // Deliberata il giorno dell'atto: la spesa è di chi compra. Aprile-luglio emesse a Ugo prima di registrare.
        $s = ruScenario('prima_rata', 0, 'straordinaria', '2026-05-01', '2026-04-05', 6);
        ruEmetti($s, '2026-07-31');
        ruRegistra($this, $s, ruRiserva($s));
    }

    // Solo il punto aperto: la cifra delle certe, con la straordinaria emessa prima, è quella della Coda 172.
    expect(app(NotaSolidarieta::class)->per($s['c'], $s['a'])[0]['residuo_uscente_cents'])->toBe($puntoAperto);
})->with([
    'ordinaria, atto il giorno di una scadenza: € 400,00, non € 500,00' => ['ordinaria', 40000],
    'straordinaria deliberata il giorno dell\'atto: nessun arretrato' => ['straordinaria', 0],
]);

it('mappa dei casi — le pertinenze nella riserva: la nota di solidarietà ha una voce per l\'unità e una per il box, ciascuna con la solidarietà dell\'art. 67 e l\'arretrato del suo immobile', function () {
    $s = ruScenario('prima_rata', 0, genera: false);
    $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    // Il box ha la sua quota nella tabella, quindi nel piano: generato dopo averlo censito.
    DB::table('quote_tabella')->insert(['tabella_id' => (int) DB::table('quote_tabella')->where('immobile_id', $s['unita']->id)->value('tabella_id'), 'immobile_id' => $box->id, 'valore' => 100.0, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['v']->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s);
    ruRegistra($this, $s, ruRiserva($s, extra: ['pertinenze' => [$box->id]]));

    // L'arretrato di ogni immobile, dalle quote: le quattro emesse a Ugo, tutte con scadenza prima dell'atto.
    $arretrato = fn (int $immobileId) => (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate.stato', 'emessa')->where('rate_quote.anagrafica_id', $s['v']->id)->where('rate_quote.immobile_id', $immobileId)->sum('rate_quote.importo');
    expect($arretrato($box->id))->toBeGreaterThan(0);

    $note = collect(app(NotaSolidarieta::class)->per($s['c'], $s['a']))->keyBy('immobile');
    expect($note->keys()->sort()->values()->all())->toBe(['Box 12 (Int. B12)', 'Interno 1 (Int. 1)']);
    foreach (['Interno 1 (Int. 1)' => $s['unita']->id, 'Box 12 (Int. B12)' => $box->id] as $nome => $id) {
        expect($note[$nome]['testo'])->toContain('art. 67 ult. co.')->toContain("per i contributi di {$nome}")->toContain('decide l\'amministratore')
            ->and($note[$nome]['residuo_uscente_cents'])->toBe($arretrato($id));
    }
});

it('mappa dei casi, rilievo B6 sui passaggi della .37 — il consuntivo dell\'anno prima generato dopo non fa avvisi né blocchi: dopo una costituzione dell\'usufrutto, dopo la vendita della metà all\'altra comproprietaria, e nella catena vendita piena e riserva', function (string $caso, array $consuntivoAtteso) {
    if ($caso === 'costituzione') {
        $s = ruScenario('prima_rata', 0);
        $subentro = ruRegistra($this, $s, ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01',
            'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Costituzione dell\'usufrutto, letta']);
        $nomi = ['U' => $s['v']->id];
    } elseif ($caso === 'comproprietaria') {
        [$s, $madre] = ruDueGenitori();
        $subentro = ruRegistra($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $madre->id, 'decorrenza' => '2026-05-01', 'quota' => 50, 'tipologia' => 'proprietario',
            'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'rep. 5', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Vendita della metà a Rita, letta']);
        // La metà di Ugo si somma alla riga di Rita (decisione A).
        expect(DB::table('anagrafica_immobile')->where('anagrafica_id', $madre->id)->whereNull('data_fine')->value('quota'))->toEqual(100);
        $nomi = ['U' => $s['v']->id, 'R' => $madre->id];
    } else {
        [$s] = ruDopoUnaVendita($this);
        $subentro = Subentro::where('immobile_id', $s['unita']->id)->latest('id')->firstOrFail();
        expect($subentro->riservaUsufrutto())->toBeTrue();
        $nomi = ['U' => $s['v']->id];
    }

    $consuntivo = ruConsuntivo2025($s);
    $atteso = collect($consuntivoAtteso)->mapWithKeys(fn ($v, $k) => [$nomi[$k] => $v])->sortKeys()->all();
    expect(ruPerPersona($consuntivo))->toBe($atteso);

    $annulla = app(\App\Actions\Subentro\AnnullaPassaggioAction::class);
    expect(ruAvvisiSenzaVoci($annulla->avvisi($subentro)))->toBe([]);
    ruEmetti(['piano' => $consuntivo] + $s, '2026-12-31');
    expect($annulla->motivoBlocco($subentro->fresh()))->toBeNull();

    ruAnnulla($this, $s, $subentro)->assertRedirect();
    expect(ruPerPersona($consuntivo))->toBe($atteso);
})->with([
    'costituzione dell\'usufrutto: il 2025 è tutto di Ugo, proprietario pieno' => ['costituzione', ['U' => 30000]],
    'vendita della metà all\'altra comproprietaria: il 2025 è a metà' => ['comproprietaria', ['U' => 15000, 'R' => 15000]],
    'catena vendita piena e riserva: il 2025 è tutto di Ugo, che vendeva a marzo' => ['catena', ['U' => 30000]],
]);

it('mappa dei casi, rilievo B1 — l\'avviso del ricalcolo vale anche per una voce senza coefficienti, che il motore dà al «Proprietario»; su un piano senza righe di riparto (anteriore alla beta.29) non si scrive, e resta la frase generica del cancello', function (string $caso) {
    if ($caso === 'senza coefficienti') {
        $s = ruScenario('prima_rata', 0, genera: false);
        $conti = DB::table('conti')->join('piani_conti', 'piani_conti.id', '=', 'conti.piano_conto_id')->where('piani_conti.gestione_id', $s['g']->id)->pluck('conti.id');
        DB::table('conto_tabella_ripartizioni')->whereIn('conto_tabella_millesimale_id', DB::table('conto_tabella_millesimale')->whereIn('conto_id', $conti)->pluck('id'))->delete();
        app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    } else {
        $s = ruScenario('prima_rata', 0);
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }

    $motivi = implode(' | ', ruAnteprima($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => 'voce']))['cancello']['motivi']);
    if ($caso === 'senza coefficienti') {
        expect($motivi)->toContain('il piano «Preventivo 2026», non ancora emesso, intesta quote a Venditore Ugo: se lo ricalcoli, dal 1 maggio 2026 le voci sul «Proprietario» (Spese generali) vanno a Acquirente Elsa, nudo proprietario')
            ->not->toContain('il destinatario cambierebbe');
    } else {
        expect($motivi)->toContain('un piano rate già generato intesta quote a Venditore Ugo: il destinatario cambierebbe')->not->toContain('se lo ricalcoli');
    }
})->with(['senza coefficienti', 'senza righe di riparto']);

it('mappa dei casi, rilievo R2 — annullare la riserva dopo un piano generato ed emesso con la voce sull\'«Usufruttuario» o sull\'«Inquilino»: lo storico e la rotta dicono la stessa cosa, e con un inquilino dal primo giorno l\'annullamento passa e rimette le righe; lo stesso nella costituzione dell\'usufrutto con la voce sul «Proprietario»', function (string $voce) {
    $s = ruScenario('prima_rata', 0, soggetto: match ($voce) { 'U' => 'usufruttuario', 'CP' => 'proprietario', default => 'inquilino' }, genera: false);
    if ($voce === 'I1') {
        // L'inquilino copre tutto il periodo del piano: le righe di riparto hanno il solo ruolo «inquilino», che la riserva non
        // tocca. Se entrasse dopo il 1 gennaio i mesi prima andrebbero per ripiego all'usufruttuario o al proprietario, ruoli
        // toccati, e l'annullamento si fermerebbe come con la voce sull'«Usufruttuario».
        $luca = Anagrafica::forceCreate(['nome' => 'Inquilino Luca', 'email' => "ru-l{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUINQUILINO' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
        $luca->condomini()->syncWithoutDetaching([$s['c']->id]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $luca->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    $righePrima = ruRighe($s['unita']->id);
    // La costituzione (CP, anteriore alla beta): Ugo resta nudo proprietario, e i ruoli toccati sono proprietario e nuda.
    $subentro = ruRegistra($this, $s, $voce === 'CP'
        ? ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'ordinaria_dopo_atto' => 'usufruttuario', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01',
            'quota' => 100, 'tipologia' => 'usufruttuario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Costituzione dell\'usufrutto, letta']
        : ruRiserva($s));
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Letto: passaggio nel 2026', esercizio: $s['e']);
    ruEmetti($s);
    $righeDopo = ruRighe($s['unita']->id);
    $quoteDopo = ruPerPersona($s['piano']);

    $voceStorico = collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $subentro->id);
    $risposta = ruAnnulla($this, $s, $subentro);

    // Lo storico e la rotta concordano, qualunque sia l'esito (con la voce sull'«Usufruttuario», e sull'«Inquilino» senza
    // inquilino, l'esito lo decide Vincenzo: rilievo R2, dichiarato nel docblock).
    if ($voceStorico['annullabile']['si']) {
        $risposta->assertRedirect();
        expect(ruRighe($s['unita']->id))->toBe($righePrima);
    } else {
        $risposta->assertUnprocessable();
        expect($risposta->json('errors.passaggio.0'))->toBe($voceStorico['annullabile']['motivo'])
            ->and(ruRighe($s['unita']->id))->toBe($righeDopo)->and(ruPerPersona($s['piano']))->toBe($quoteDopo);
    }
    if ($voce === 'I1') {
        expect($voceStorico['annullabile']['si'])->toBeTrue()->and($voceStorico['annullabile']['avvisi'])->toBe([])
            ->and(ruPerPersona($s['piano']))->toBe($quoteDopo);
    }
})->with([
    'voce sull\'«Usufruttuario»' => ['U'],
    'voce sull\'«Inquilino», senza inquilino' => ['I0'],
    'voce sull\'«Inquilino», con l\'inquilino dal primo giorno' => ['I1'],
    'costituzione dell\'usufrutto con la voce sul «Proprietario» (anteriore alla beta)' => ['CP'],
]);

// Rilievo R3 della mappa dei casi, decisione 28.8 c (28.7: il cancello non chiede la spunta se nessuna quota cambia persona).
// Nella catena Ugo → Zeta (vendita piena del 1/3, pregresso di Ugo spalmato) → Elsa (riserva del 1/5) le 8 gemelle di solo
// pregresso lasciate a Ugo dalla prima vendita restano sue, e nessuna parte ne passa a chi entra: la quota pura è zero.
// Prima il cancello le diceva «restano sue e sono comprese nel conguaglio» fra i motivi — falso anche nel merito: nella
// riserva l'ordinaria non si conguaglia — e la riserva senza nota si fermava su `nota_cancello`. Il motivo della bozza è
// `solo_pregresso` (ConguaglioPassaggio::decidiBozze), che non era fra quelli che il cancello lascia fra le informazioni.
// La variante con la rata zero rimasta in bozza non si costruisce con gli helper (`ruEmetti` emette per data e la rata
// zero scade con la prima): non scritta.
it('mappa dei casi, rilievo R3 (decisione 28.8 c) — in una catena le quote di solo pregresso lasciate a chi vendeva prima non cambiano persona: la riserva non chiede la spunta, le nomina fra le informazioni con una frase vera, e si registra senza nota', function () {
    // Ugo a debito di € 180,00, spalmato su tutte le rate: vende a Zeta il 1 marzo con gennaio e febbraio emesse; le bozze
    // passano a Zeta per la quota pura e il pregresso resta a Ugo nelle gemelle. Marzo e aprile si emettono dopo.
    $s = ruScenario('tutte_rate', 18000);
    ruEmetti($s, '2026-02-28');
    $zeta = Anagrafica::forceCreate(['nome' => 'Venditrice Zeta', 'email' => "ru-z{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUZETAVENDI' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $zeta->condomini()->syncWithoutDetaching([$s['c']->id]);
    ruRegistra($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $zeta->id, 'decorrenza' => '2026-03-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-03-05', 'estremi_titolo' => 'rep. 1', 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Prima vendita, letto']);
    ruEmettiBozze($s, $s['piano'], '2026-04-30');
    $gemelle = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)->where('rate.stato', '!=', 'emessa')
        ->where('rate_quote.anagrafica_id', $s['v']->id)->count();
    expect($gemelle)->toBe(8);

    $riserva = ruRiserva(['rigaV' => (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $zeta->id)->value('id')] + $s, extra: ['ho_letto' => false, 'nota_cancello' => null]);
    $anteprima = ruAnteprima($this, $s, $riserva);
    expect(collect($anteprima['rate']['conguaglio']['quote_in_bozza'])->where('intestatario', 'Venditore Ugo')->pluck('motivo')->all())->toBe(['solo_pregresso'])
        ->and($anteprima['cancello']['richiesto'])->toBeFalse()
        ->and($anteprima['cancello']['motivi'])->toBe([])
        ->and($anteprima['cancello']['informazioni'])->toContain('il piano «Preventivo 2026» ha 8 quote non ancora emesse intestate a Venditore Ugo: non si può più ricalcolare, restano sue: il conguaglio non le tocca')
        ->and(implode(' | ', $anteprima['cancello']['informazioni']))->not->toContain('comprese nel conguaglio');

    $subentro = ruRegistra($this, $s, $riserva);
    expect($subentro->nota_cancello)->toBeNull();
});

// Rilievo R3 nella vendita piena, sulle quote già emesse (decisione 28.8 c; sonda C1 dello scettico). Nella catena Ugo → Zeta
// (vendita piena, pregresso di Ugo spalmato) → Elsa (vendita piena del 1/6) le gemelle di solo pregresso lasciate a Ugo
// sulle rate emesse dopo la prima vendita hanno quota pura zero e niente per chi entra. Il motivo le contava fra le quote
// del predecessore «la parte che ne resta passa ancora», che per loro è falso: ora il numero e la frase sono quelli delle
// quote di cui passa davvero una parte, e le gemelle vanno fra le informazioni. Se sono le sole emesse di Ugo, il motivo
// del predecessore non c'è più; la spunta resta per le emesse di Zeta, che si conguagliano.
it('mappa dei casi, rilievo R3 nella vendita piena (decisione 28.8 c) — le gemelle emesse di solo pregresso lasciate a chi vendeva prima non «passano ancora»: il motivo conta solo le quote del predecessore di cui passa una parte, e le gemelle vanno fra le informazioni', function (string $metodo, string $primaVendita, int $nGemelle, int $nZeta, ?string $motivoUgo, string $informazione) {
    $s = ruScenario($metodo, 18000);
    if ($metodo === 'rata_zero') {
        // Emessa la sola rata zero, di soli saldi pregressi: il piano non si ricalcola più, e le altre bozze passano a Zeta.
        $zero = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 0)->pluck('id');
        $scrittura = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-01-01', 'data_competenza' => '2026-01-01',
            'numero_protocollo' => 'EMI-RU-ZERO-' . $s['piano']->id, 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('rate')->whereIn('id', $zero)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-01']);
        DB::table('rate_quote')->whereIn('rata_id', $zero)->update(['scrittura_contabile_id' => $scrittura]);
    } else {
        ruEmetti($s, '2026-02-28');
    }
    $zeta = Anagrafica::forceCreate(['nome' => 'Venditrice Zeta', 'email' => "ru-z{$s['unita']->id}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RUZETAVENDI' . str_pad((string) $s['unita']->id, 5, '0', STR_PAD_LEFT)]);
    $zeta->condomini()->syncWithoutDetaching([$s['c']->id]);
    ruRegistra($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $zeta->id, 'decorrenza' => $primaVendita,
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-03-05', 'estremi_titolo' => 'rep. 1', 'pertinenze' => [], 'ho_letto' => true,
        'nota_cancello' => 'Prima vendita, letto']);
    ruEmettiBozze($s, $s['piano'], '2026-04-30');
    $rigaZ = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $zeta->id)->value('id');

    $anteprima = ruAnteprima($this, $s, ruPassaggio('vendita', $rigaZ, $s['a'], '2026-06-01', 100) + ['ho_letto' => false, 'nota_cancello' => null]);
    // Le gemelle emesse di Ugo, dal calcolo: quota pura zero, niente a chi entra.
    $gemelle = collect($anteprima['rate']['conguaglio']['quote'])->where('intestatario_id', $s['v']->id)->where('in_bozza', false)->where('quota_pura', 0);
    expect($gemelle)->toHaveCount($nGemelle)
        ->and($gemelle->pluck('entrante')->unique()->values()->all())->toBe([0])
        ->and($anteprima['cancello']['informazioni'])->toContain($informazione)
        ->and($anteprima['cancello']['richiesto'])->toBeTrue()
        ->and($anteprima['cancello']['motivi'])->toContain("{$nZeta} quote di rate già emesse a Venditrice Zeta su questa unità");
    $diUgo = collect($anteprima['cancello']['motivi'])->filter(fn ($m) => str_contains($m, 'a Venditore Ugo, la cui competenza'))->values()->all();
    expect($diUgo)->toBe($motivoUgo === null ? [] : [$motivoUgo]);
})->with([
    'gennaio e febbraio emessi prima della prima vendita: due quote piene e due gemelle' => ['tutte_rate', '2026-03-01', 2, 2,
        '2 quote di rate già emesse a Venditore Ugo, la cui competenza è passata a Venditrice Zeta con un passaggio precedente: la parte che ne resta passa ancora',
        '2 quote di rate già emesse a Venditore Ugo: restano sue, questo passaggio non le tocca'],
    'emessa la sola rata zero prima della prima vendita: di Ugo resta solo quella, di soli saldi pregressi' => ['rata_zero', '2026-01-01', 1, 4, null,
        '1 quota di rata già emessa a Venditore Ugo: resta sua, questo passaggio non la tocca'],
]);

// Rilievo R3 nell'estinzione dell'usufrutto (decisione 28.8 c; lo scettico l'ha trovato nella griglia: RE · SG · SR · T−, S3E,
// RVE). La riserva su una straordinaria deliberata dopo l'atto fa passare a Elsa le bozze e lascia a Ugo le gemelle del suo
// pregresso; quando l'usufrutto di Ugo si estingue, le gemelle sono di Ugo, che esce. Sono quote di soli saldi pregressi:
// il motivo della bozza è `solo_pregresso` (viene prima dell'esclusione della straordinaria, che l'estinzione non conguaglia),
// e prima il cancello le diceva «comprese nel conguaglio» e chiedeva la spunta.
it('mappa dei casi, rilievo R3 nell\'estinzione (decisione 28.8 c) — le gemelle del pregresso di chi vendeva con riserva restano sue quando il suo usufrutto si estingue: niente spunta, una frase vera fra le informazioni, e l\'estinzione si registra senza nota', function () {
    $s = ruScenario('tutte_rate', 18000, 'straordinaria', '2026-05-20', '2026-06-05', 6);
    ruEmetti($s, '2026-07-31');
    ruRegistra($this, $s, ruRiserva($s));
    $rigaUsufrutto = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    $estinzione = ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $rigaUsufrutto, 'decorrenza' => '2026-09-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => false, 'nota_cancello' => null];

    $anteprima = ruAnteprima($this, $s, $estinzione);
    expect($anteprima['rate']['conguaglio']['quote_in_bozza'])->toBe([['piano' => 'Rifacimento facciata', 'intestatario' => 'Venditore Ugo', 'n' => 4, 'motivo' => 'solo_pregresso']])
        ->and($anteprima['cancello']['richiesto'])->toBeFalse()
        ->and($anteprima['cancello']['motivi'])->toBe([])
        ->and($anteprima['cancello']['informazioni'])->toContain('il piano «Rifacimento facciata» ha 4 quote non ancora emesse intestate a Venditore Ugo: non si può più ricalcolare, restano sue: il conguaglio non le tocca');

    expect(ruRegistra($this, $s, $estinzione)->nota_cancello)->toBeNull();
});

// --- Decisione 48 (rilievo W7 del giro sulle correzioni della .42): i dati di prima, nella riserva e nella costituzione ----------

/** Una persona nuova del condominio dello scenario. */
function ruPersonaNuova(array $s, string $nome, string $sigla): Anagrafica
{
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($sigla) . "{$s['unita']->id}@ru.test", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => strtoupper($sigla) . str_pad((string) $s['unita']->id, 16 - strlen($sigla), '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** La forma U1 dei dati di prima: una vendita con il piano ancora da ricalcolare, poi tutte le rate a giornale a chi vendeva. */
function ruU1($test, array $s, Anagrafica $a, string $dal): void
{
    ruRegistra($test, $s, ruPassaggio('vendita', $s['rigaV'], $a, $dal, 100) + ['ho_letto' => true, 'nota_cancello' => 'Vendita, letta']);
    $test->travel(1)->days();
    ruEmetti($s, '2026-12-31');
    $test->travel(1)->days();
}

/** Il fermo della decisione 48: la riga «mai passate», il motivo con la spunta nel cancello, nessuna coppia. */
function ruFermoMaiPassate($test, array $anteprima, string $dal, string $piano): void
{
    $c = $anteprima['rate']['conguaglio'];
    expect($c['coppie'])->toBe([])
        ->and(collect($c['non_risolte'])->firstWhere('piano', $piano)['motivo'] ?? '')->toStartWith("le quote intestate a Venditore Ugo su Interno 1 non sono passate con il suo passaggio del {$dal}")
        ->and($anteprima['cancello']['richiesto'])->toBeTrue()
        ->and(implode(' | ', $anteprima['cancello']['motivi']))->toContain('12 quote di rate già emesse a Venditore Ugo: restano sue, senza conguaglio: il passaggio di prima non le ha fatte passare');
}

it('decisione 48 — dati di prima: Ugo vende a Elsa senza che il passaggio prenda il piano, il piano si emette tutto a Ugo; Elsa fa una riserva a Zeta. La legge lascerebbe l\'ordinaria a Elsa, ma le quote sono ancora di Ugo, che ha pagato i 334 giorni di Elsa (€ 1.098,08): il pannello lo dice, con la spunta', function () {
    $s = ruScenario('prima_rata', 0);
    ruU1($this, $s, $s['a'], '2026-02-01');
    $zeta = ruPersonaNuova($s, 'Zeta Terza', 'RUZETAW7');
    $rigaElsa = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->whereNull('data_fine')->value('id');
    // Prima (estensione di A7): nessun fermo, cancello muto, e la frase «restano a Venditore Ugo — sono spese ordinarie, art. 1004».
    ruFermoMaiPassate($this, ruAnteprima($this, $s, ruRiserva(['rigaV' => $rigaElsa, 'a' => $zeta] + $s, '2026-06-01')), '1 febbraio 2026', 'Preventivo 2026');
});

it('decisione 48 — dati di prima, la straordinaria: delibera del 15/3, Ugo vende a Elsa il 1/3 senza che il passaggio prenda il piano, tutto emesso a Ugo; Elsa costituisce l\'usufrutto a Carlo. La straordinaria resterebbe comunque al nudo, ma è ancora tutta di Ugo (€ 1.200,00): il pannello lo dice', function () {
    $s = ruScenario('prima_rata', 0, 'straordinaria', '2026-03-15', '2026-01-05', 12);
    ruU1($this, $s, $s['a'], '2026-03-01');
    $carlo = ruPersonaNuova($s, 'Carlo Quarto', 'RUCARLOW7');
    $rigaElsa = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['a']->id)->whereNull('data_fine')->value('id');
    ruFermoMaiPassate($this, ruAnteprima($this, $s, ruPassaggio('costituzione', $rigaElsa, $carlo, '2026-06-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Costituzione, letta']), '1 marzo 2026', 'Rifacimento facciata');
});

it('decisione 48 — l\'anello mancato prima di una riserva: Ugo vende a Zeta il 1/3 senza che il passaggio prenda il piano, tutto emesso a Ugo; Zeta vende a Elsa con riserva; Elsa rivende la nuda a Carlo. Le quote di Ugo non sono mai arrivate a Zeta (€ 1.006,03 dei suoi giorni): il fermo nomina la vendita del 1/3, non la riserva', function () {
    $s = ruScenario('prima_rata', 0);
    $zeta = ruPersonaNuova($s, 'Zeta Terza', 'RUZETAWC');
    ruU1($this, $s, $zeta, '2026-03-01');
    $rigaZ = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $zeta->id)->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, ruRiserva(['rigaV' => $rigaZ] + $s));
    [, $rivendita] = ruRivendita($s);
    // Prima: tenere fuori i gruppi `riservata_da` faceva tacere il fermo, perché la riserva non trasferisce l'ordinaria; ma
    // l'anello che non ha preso il piano è la vendita prima.
    ruFermoMaiPassate($this, ruAnteprima($this, $s, $rivendita), '1 marzo 2026', 'Preventivo 2026');
});

it('rilievo T4 — una vendita con il box: il passaggio della pertinenza ha preso anche lui il piano, ma le frasi lo nominano una volta sola, con l\'unità principale', function () {
    $caso = ruCaso($this, ['F' => 'CPV', 'N' => 'O', 'E' => 'E4', 'S' => '0', 'V' => 'P', 'D' => '1', 'P' => 'box', 'K' => 'no', 'G' => 'prima'],
        ['descrizione' => 'vendita con il box', 'titolari' => [['v', 'proprietario', 100]], 'passaggi' => [['vendita', 'v', 'a', '05']]]);
    $s = $caso['s'];
    ruRegistra($this, $s, array_merge($caso['esame']['dati'], ['ho_letto' => true, 'nota_cancello' => 'Rogito letto, box compreso']));
    $piano = $s['piano']->fresh();

    // Prima: «…del passaggio Venditore Ugo → Acquirente Elsa (dal 1 maggio 2026); Venditore Ugo → Acquirente Elsa (dal 1 maggio 2026)».
    expect(count($piano->passaggiCheLoHannoConguagliato()))->toBe(2)
        ->and(count($piano->passaggiDaAnnullare()))->toBe(1)
        ->and($piano->fraseDelFermo())->toEndWith('è stato preso nel conguaglio del passaggio Venditore Ugo → Acquirente Elsa, ' . $s['unita']->nome . ', dal 1 maggio 2026')
        ->and($piano->fraseConguagliato('Ricalcolarlo'))->toStartWith('Un passaggio di titolarità ha preso questo piano nel conguaglio: Venditore Ugo → Acquirente Elsa, ' . $s['unita']->nome . ', dal 1 maggio 2026.');
});

// --- Decisione 51 (rilievo X1 del terzo giro della .42): il fermo «mai passate» riga per riga --------------------------------

/** Ugo costituisce a Uso l'usufrutto dal 1/2 (con la scelta data), poi vende la nuda a Elsa dal 1/5; con `$u1` il piano è emesso tutto dopo i passaggi (U1), altrimenti tutto prima. */
function ruNudaDopoCostituzione($test, array $s, string $scelta, bool $u1): Anagrafica
{
    $uso = ruPersonaNuova($s, 'Uso Usufruttuario', 'RUUSOX1');
    if (! $u1) {
        ruEmetti($s, '2026-12-31');
    }
    ruRegistra($test, $s, array_merge(ruPassaggio('costituzione', $s['rigaV'], $uso, '2026-02-01', 100), ['ordinaria_dopo_atto' => $scelta, 'ho_letto' => true, 'nota_cancello' => 'Costituzione, letta']));
    $rigaNuda = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');
    $test->travel(1)->minutes();
    ruRegistra($test, $s, ruPassaggio('nuda', $rigaNuda, $s['a'], '2026-05-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Vendita della nuda, letta']);
    $test->travel(1)->days();
    if ($u1) {
        ruEmetti($s, '2026-12-31');
        $test->travel(1)->days();
    }

    return $uso;
}

it('decisione 51 — dati di prima: costituzione «come dice ogni voce», vendita della nuda a Elsa senza che i passaggi prendano il piano, tutto emesso a Ugo; nella rivendita della nuda a Carlo il fermo c\'è, e nessun credito a Elsa su quote mai sue', function () {
    $s = ruScenario('prima_rata', 0);
    ruNudaDopoCostituzione($this, $s, Subentro::ORDINARIA_COME_LA_VOCE, u1: true);
    [, $rivendita] = ruRivendita($s);
    // Prima: coppia di € 401,10 a Elsa (120000 × 122/365), che non ha mai pagato niente — la vendita della nuda era saltata.
    ruFermoMaiPassate($this, ruAnteprima($this, $s, $rivendita), '1 maggio 2026', 'Preventivo 2026');
});

it('decisione 51, controprova — la stessa catena con il piano emesso tutto prima dei passaggi: i passaggi lo prendono, e nella rivendita la coppia a Carlo è € 401,10 (120000 × 122/365), senza fermo', function () {
    $s = ruScenario('prima_rata', 0);
    ruNudaDopoCostituzione($this, $s, Subentro::ORDINARIA_COME_LA_VOCE, u1: false);
    [, $rivendita] = ruRivendita($s);
    $c = ruAnteprima($this, $s, $rivendita)['rate']['conguaglio'];
    expect(array_column($c['coppie'], 'importo'))->toBe([40110])->and($c['non_risolte'])->toBe([]);
});

it('decisione 51, il rovescio — con la legge la vendita della nuda non trasferisce l\'ordinaria di quel piano (le righe sono dell\'usufruttuario): niente fermo e niente coppia', function () {
    $s = ruScenario('prima_rata', 0);
    ruNudaDopoCostituzione($this, $s, Subentro::ORDINARIA_ALL_USUFRUTTUARIO, u1: true);
    [, $rivendita] = ruRivendita($s);
    $c = ruAnteprima($this, $s, $rivendita)['rate']['conguaglio'];
    expect($c['coppie'])->toBe([])->and(collect($c['non_risolte'])->pluck('motivo')->implode(' | '))->not->toContain('non sono passate con il suo passaggio');
});

it('decisione 51, il rovescio della riserva — riserva «come dice ogni voce» con la voce sull\'«Usufruttuario», senza che il passaggio prenda il piano, tutto emesso a Ugo: nella rivendita della nuda il fermo non scatta, perché quella riserva fa passare solo le voci sul «Proprietario»', function () {
    $s = ruScenario('prima_rata', 0, soggetto: 'usufruttuario');
    ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => Subentro::ORDINARIA_COME_LA_VOCE]));
    $this->travel(1)->days();
    ruEmetti($s, '2026-12-31');
    $this->travel(1)->days();
    [, $rivendita] = ruRivendita($s);
    $an = ruAnteprima($this, $s, $rivendita);
    // Prima: il fermo con la spunta, e «se serve un conguaglio, si scrive con un saldo manuale» — verso chi non ne ha diritto: un
    // ricalcolo dà comunque a Ugo l'intero, la voce è dell'usufruttuario che resta lui.
    expect(collect($an['rate']['conguaglio']['non_risolte'])->pluck('motivo')->implode(' | '))->not->toContain('non sono passate con il suo passaggio')
        ->and($an['rate']['conguaglio']['coppie'])->toBe([]);
});

it('sentinella della Coda 218 — fissa il comportamento di oggi, noto e sbagliato (difetto della .41, si corregge con la .43): piano senza dettaglio del riparto, emesso tutto prima: costituzione «come dice ogni voce», vendita della nuda a Elsa (coppia € 805,48), rivendita della nuda a Carlo. Oggi la rivendita non conguaglia e Elsa perde € 401,10; l\'atteso è una coppia di 40110 a carico di Carlo', function () {
    $s = ruScenario('prima_rata', 0);
    // Un piano anteriore alla beta.29: senza righe di riparto.
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    ruNudaDopoCostituzione($this, $s, Subentro::ORDINARIA_COME_LA_VOCE, u1: false);
    expect((int) Saldo::whereNotNull('subentro_id')->where('anagrafica_id', $s['a']->id)->sum('saldo_iniziale'))->toBe(80548);
    [, $rivendita] = ruRivendita($s);

    // Con le righe di riparto la coppia è 40110 (120000 × 122/365): `$vociPassateDi` ignora `$vociDelNudo` per un gruppo
    // ereditato, e `$esclusaDi` lo esclude. Quando la correzione arriva, questa attesa diventa [40110].
    expect(array_column(ruAnteprima($this, $s, $rivendita)['rate']['conguaglio']['coppie'], 'importo'))->toBe([]);
});

it('rilievo T4 del quarto giro — una vendita con il box e il piano ancora da ricalcolare: il rifiuto dell\'emissione dice «del passaggio», al singolare, anche se i passaggi registrati sono due (l\'unità e il box)', function () {
    $caso = ruCaso($this, ['F' => 'CPV', 'N' => 'O', 'E' => 'NE', 'S' => '0', 'V' => 'P', 'D' => '1', 'P' => 'box', 'K' => 'no', 'G' => 'prima'],
        ['descrizione' => 'vendita con il box', 'titolari' => [['v', 'proprietario', 100]], 'passaggi' => [['vendita', 'v', 'a', '05']]]);
    $s = $caso['s'];
    ruRegistra($this, $s, array_merge($caso['esame']['dati'], ['ho_letto' => true, 'nota_cancello' => 'Rogito letto, box compreso']));
    expect(count($s['piano']->fresh()->passaggiDaSeguire()))->toBe(2);

    $r = $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => [(int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('numero_rata')->value('id')], 'data_emissione' => '2026-05-10', 'invia_notifiche' => false,
    ]);
    // Prima: «…prima del passaggio di Venditore Ugo su Interno 1…: ricalcola il piano prima di emettere, perché tenga conto dei passaggi».
    expect($r->getSession()->get('message')['message'])->toContain('perché tenga conto del passaggio.')->not->toContain('dei passaggi');
});

it('rilievo T7 del quarto giro — un piano preso solo dall\'estinzione di un usufrutto: la frase dice «nel conguaglio dell\'estinzione dell\'usufrutto di …», non «del passaggio estinzione…»', function () {
    $s = ruScenario('prima_rata', 0);
    $uso = ruPersonaNuova($s, 'Uso Usufruttuario', 'RUUSOT7');
    ruRegistra($this, $s, array_merge(ruPassaggio('costituzione', $s['rigaV'], $uso, '2026-02-01', 100), ['ho_letto' => true, 'nota_cancello' => 'Costituzione, letta']));
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]), ['accetta_destinatari' => true, 'nota_destinatari' => 'Il piano segue la costituzione'])->assertSessionHasNoErrors();
    $this->travel(1)->minutes();
    ruEmetti($s, '2026-08-31');
    $this->travel(1)->minutes();
    $rigaUso = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $uso->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    ruRegistra($this, $s, array_merge(ruPassaggio('estinzione', $rigaUso, null, '2026-09-01', 100), ['ho_letto' => true, 'nota_cancello' => 'Estinzione, letta']));

    $piano = $s['piano']->fresh();
    expect(count($piano->passaggiDaAnnullare()))->toBe(1)
        ->and($piano->fraseDelFermo())->toBe('ha già quote a giornale ed è stato preso nel conguaglio dell\'estinzione dell\'usufrutto di Uso Usufruttuario, ' . $s['unita']->nome . ', dal 1 settembre 2026');
});
