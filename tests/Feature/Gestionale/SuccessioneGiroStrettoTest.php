<?php

/**
 * 1.11.0-beta.48, giro stretto sulle correzioni della Fase 1-bis: i rilievi bassi delle tre lenti, lato server.
 *
 * Da dove nasce: il giro sulle correzioni non ha trovato nessun rilievo alto o medio (la lente denaro ha confrontato 31 casi con la
 * Fase 1 e con il commit della .47: il denaro è identico). Ha trovato quattordici rilievi bassi; chi coordina li ha decisi tutti da
 * correggere nella beta, perché sono codice della 1.11. Questo file prova quelli del server:
 * - AMM-8: la frase d'apertura nuova ha il soggetto sottinteso («Se scrivi il conguaglio fra Venditore Ugo e Anna (50 %) e Bruno
 *   (50 %), entra nei saldi…»: chi entra? Carla?), con due «e» di fila; e «Se non lo scrivi, la posizione di Venditore Ugo resta com'è»
 *   è falso, perché le bozze passano comunque all'erede di riferimento (a nome di Ugo restano € 400,00, non € 1.200,00).
 * - AMM-2: «a A…» rimasta nella successione: il blocco dell'annullamento nello storico («è stata emessa a Ada la rata 5») e le quote di
 *   un comproprietario («restano a Alba Comproprietaria»).
 * - AMM-3: «erede» per i legatari nelle frasi del server (in comunione, teste in assemblea, la parte degli eredi, la divisione per erede).
 * - AMM-4: con il piano fermo per un incasso e nessuna rata a giornale, «le sue quote si conguagliano qui» detto come un fatto.
 * - AMM-6: il 422 di `conguaglio` mandato come elenco dice «conguaglio selezionato non è valido.», con il nome del campo in minuscolo.
 * - denaro-g1: un legato con più legatari registrato fra la .44 e la .47 dice «le cifre per erede».
 * - SIC-G1 e denaro-g2: `tipo`, `sottotipo` o `tipologia` mandati come elenco danno ancora una pagina 500 (di prima).
 * - SIC-G2: un erede con `anagrafica_id: true` passa la regola `integer`, salta i controlli della successione e fa erede l'anagrafica 1,
 *   anche di un altro condominio (di prima).
 * - SIC-G3: gli eredi mandati come mappa registrano la successione senza chi entra nel calcolo: niente conguaglio, le bozze al defunto,
 *   e la scelta non si chiede (di prima).
 *
 * Il caso di riferimento è quello della .44 (€ 1.200,00 l'anno in dodici rate da € 100,00, rate 1–4 a giornale, otto bozze). Le cifre del
 * decesso il 4 maggio con due eredi al 50 % sono quelle di `SuccessionePannelloTest` (242 giorni, coppie −40219 e +39781).
 *
 * Cosa presidia, rosso sul codice di prima del giro: le frasi e le risposte dette sopra. I controlli, verdi già prima, che devono
 * restarlo: la vendita con «a» davanti ai nomi con la A (decisione 73.3: fuori dalla successione niente cambia), il legato a un solo
 * legatario, gli elenchi normali che si registrano.
 *
 * Cosa NON copre: la pagina (`AnteprimaPassaggio.test.ts`), le guide, il denaro (identico: lo dice il verbale del giro).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Actions\Subentro\AnnullaPassaggioAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
use App\Models\User;
use App\Services\Subentro\StoricoTitolarita;
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
function sgsPersona(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => 'sgs' . $n . '-' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'SGSPERSONA' . str_pad((string) $n, 6, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** Lo scenario della .44 con le rate 1–4 a giornale. */
function sgsCaso(): array
{
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    return $s;
}

/**
 * Il corpo della successione (o del legato) di Ugo: `$eredi` è un elenco di [persona, quota].
 *
 * @param list<array{0: Anagrafica, 1: float|int}> $eredi
 */
function sgsCorpo(array $s, array $eredi, ?Anagrafica $riferimento, string $decesso = '2026-05-01', array $extra = [], bool $legato = false, float $quota = 100): array
{
    return array_merge([
        'tipo' => 'successione', 'riga_uscente_id' => $s['rigaV'], 'decorrenza' => $decesso, 'quota' => $quota, 'tipologia' => 'proprietario',
        'eredi' => array_map(fn (array $e) => ['anagrafica_id' => $e[0]->id, 'quota' => $e[1]], $eredi), 'arretrato' => 'defunto', 'erede_di_riferimento' => $riferimento?->id,
        'copia_autentica' => false, 'estremi_titolo' => $legato ? 'testamento pubblicato, rep. 55' : 'dichiarazione di successione n. 123', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione letta: chi riceve l\'unità entra dal decesso',
    ], $legato ? ['sottotipo' => 'legato'] : [], $extra);
}

/** La registrazione dalla rotta vera, che deve riuscire; il passaggio padre. */
function sgsRegistra($test, array $s, array $corpo): Subentro
{
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $corpo)->assertSessionHasNoErrors();

    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

/** L'anteprima e la registrazione rifiutate con 422 sul campo, e niente scritto. */
function sgsRifiuto($test, array $s, array $corpo, string $campo): string
{
    $anteprima = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $corpo);
    expect($anteprima->status())->toBe(422);
    $anteprima->assertJsonValidationErrors([$campo]);
    $store = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $corpo);
    expect($store->status())->toBe(422);
    $store->assertJsonValidationErrors([$campo]);
    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0);

    return implode(' ', (array) $anteprima->json("errors.{$campo}"));
}

// ---------------------------------------------------------------------------------------------------------------------
// AMM-8 — la frase d'apertura con il soggetto detto
// ---------------------------------------------------------------------------------------------------------------------

it('AMM-8: la frase d\'apertura dice di chi sono le righe — «le sue righe fra Venditore Ugo da una parte e … dall\'altra entrano nei saldi»', function () {
    // Decesso il 4 maggio, Anna e Bruno al 50 %: prima «fra Venditore Ugo e Anna (50 %) e Bruno (50 %), entra nei saldi».
    $s = sgsCaso();
    $anna = sgsPersona($s, 'Anna');
    $bruno = sgsPersona($s, 'Bruno');

    $apertura = ruAnteprima($this, $s, sgsCorpo($s, [[$anna, 50], [$bruno, 50]], $anna, '2026-05-04'))['rate']['frasi'][0];

    expect($apertura)->toContain('Se scrivi il conguaglio, le sue righe fra Venditore Ugo da una parte e Anna')
        ->toContain('dall\'altra entrano nei saldi e sommano a zero');
});

it('AMM-8: l\'altra strada non dice che la posizione del defunto resta com\'è — le bozze passano comunque: «Se non lo scrivi, nei saldi non si scrive niente»', function () {
    // Con «Non scriverlo» le otto bozze (€ 800,00) passano ad Anna: a nome di Ugo restano € 400,00, non € 1.200,00.
    $s = sgsCaso();
    $anna = sgsPersona($s, 'Anna');
    $bruno = sgsPersona($s, 'Bruno');

    $apertura = ruAnteprima($this, $s, sgsCorpo($s, [[$anna, 50], [$bruno, 50]], $anna, '2026-05-04'))['rate']['frasi'][0];

    expect($apertura)->toContain('Se non lo scrivi, nei saldi non si scrive niente.');
    expect($apertura)->not->toContain('la posizione di Venditore Ugo resta com\'è');
});

// ---------------------------------------------------------------------------------------------------------------------
// AMM-2 — «ad» anche nel blocco dell'annullamento e davanti al comproprietario
// ---------------------------------------------------------------------------------------------------------------------

it('AMM-2: dopo l\'emissione della rata 5, il blocco dell\'annullamento nello storico dice «emessa ad Ada»', function () {
    // Ada erede unica dal 1° maggio con «Non scriverlo»: la rata 5 (5 maggio) è sua, e viene emessa dopo il passaggio.
    $s = sgsCaso();
    $ada = sgsPersona($s, 'Ada');
    sgsRegistra($this, $s, sgsCorpo($s, [[$ada, 100]], null, extra: ['conguaglio' => 'non_scrivere']));
    ruEmetti($s, '2026-05-31');

    $motivo = (string) app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'][0]['annullabile']['motivo'];

    expect($motivo)->toContain('emessa ad Ada la rata 5');
    expect($motivo)->not->toContain('emessa a Ada');
});

it('AMM-2: nella successione di chi ha metà dell\'unità le quote del comproprietario «restano ad Alba Comproprietaria»', function () {
    // Ugo e Alba al 50 %; Ugo muore il 1° maggio. Le quattro quote emesse ad Alba non c'entrano con la successione.
    $s = ruScenario('prima_rata', 0, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['quota' => 50]);
    $alba = sgsPersona($s, 'Alba Comproprietaria');
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $alba->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 50,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s);
    $anna = sgsPersona($s, 'Anna');

    $frasi = implode(' | ', ruAnteprima($this, $s, sgsCorpo($s, [[$anna, 50]], null, quota: 50))['rate']['frasi']);

    expect($frasi)->toContain('restano ad Alba Comproprietaria');
    expect($frasi)->not->toContain('restano a Alba');
});

// ---------------------------------------------------------------------------------------------------------------------
// AMM-3 — nel legato chi riceve l'unità non è un erede
// ---------------------------------------------------------------------------------------------------------------------

it('AMM-3: nel legato a Leo e Ada le frasi del server non chiamano «eredi» chi riceve l\'unità', function () {
    // Leo e Ada al 50 %, Ada di riferimento. Prima: «ogni erede per la sua quota, in comunione», «gli eredi in comunione su questa unità»,
    // «la parte degli eredi sull'intero piano», «la divisione per erede», «la parte degli eredi, € 805,48 per i giorni dal decesso».
    $s = sgsCaso();
    $leo = sgsPersona($s, 'Leo');
    $ada = sgsPersona($s, 'Ada');

    $a = ruAnteprima($this, $s, sgsCorpo($s, [[$leo, 50], [$ada, 50]], $ada, legato: true));
    $tutte = implode(' | ', array_merge($a['anagrafica']['frasi'], $a['rate']['frasi'], $a['rate']['frasi_del_conguaglio'], $a['invarianti']['frasi']));

    expect($tutte)->not->toContain('ogni erede per la sua quota')
        ->not->toContain('gli eredi in comunione')
        ->not->toContain('la parte degli eredi')
        ->not->toContain('divisione per erede')
        ->not->toContain('giorni degli eredi');
    expect($tutte)->toContain('ognuno per la sua quota, in comunione')
        ->toContain('la parte di chi riceve l\'unità');
});

it('AMM-3, controllo: nella successione le stesse frasi dicono ancora «eredi»', function () {
    $s = sgsCaso();
    $anna = sgsPersona($s, 'Anna');
    $bruno = sgsPersona($s, 'Bruno');

    $a = ruAnteprima($this, $s, sgsCorpo($s, [[$anna, 50], [$bruno, 50]], $anna));
    $tutte = implode(' | ', array_merge($a['anagrafica']['frasi'], $a['rate']['frasi'], $a['rate']['frasi_del_conguaglio']));

    expect($tutte)->toContain('ogni erede per la sua quota, in comunione')
        ->toContain('la parte degli eredi');
});

// ---------------------------------------------------------------------------------------------------------------------
// AMM-4 — il piano fermo per un incasso, nessuna rata a giornale
// ---------------------------------------------------------------------------------------------------------------------

it('AMM-4: con il piano fermo per un incasso le quote «si conguagliano qui» solo se scrivi il conguaglio', function () {
    // Nessuna rata a giornale; un incasso di € 100,00 sulla rata 1 ferma il piano. Prima: «non si ricalcola più, e le sue quote si
    // conguagliano qui», detto come un fatto anche con «Non scriverlo».
    $s = ruScenario('prima_rata', 0);
    $quota = (int) DB::table('rate_quote')->where('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 1)->value('id'))->value('id');
    $incasso = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'data_registrazione' => now(), 'data_competenza' => now(),
        'numero_protocollo' => 'TEST-SGS-INC', 'causale' => 'Incasso', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('quota_scrittura')->insert(['rate_quota_id' => $quota, 'scrittura_contabile_id' => $incasso, 'importo_pagato' => 10000, 'data_pagamento' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rate_quote')->where('id', $quota)->update(['importo_pagato' => 10000, 'stato' => 'pagata']);
    $anna = sgsPersona($s, 'Anna');
    $bruno = sgsPersona($s, 'Bruno');

    $prima = ruAnteprima($this, $s, sgsCorpo($s, [[$anna, 50], [$bruno, 50]], $anna))['rate']['frasi'][0];

    expect($prima)->toStartWith('Nessuna rata di questa unità è ancora a giornale')
        ->toContain('se scrivi il conguaglio, le sue quote si conguagliano qui');
    expect($prima)->not->toContain('non si ricalcola più, e le sue quote si conguagliano qui');
});

// ---------------------------------------------------------------------------------------------------------------------
// AMM-6 — il messaggio della scelta mandata male
// ---------------------------------------------------------------------------------------------------------------------

it('AMM-6: la scelta mandata come elenco ha un messaggio con la maiuscola, senza il nome tecnico del campo', function () {
    $s = sgsCaso();
    $anna = sgsPersona($s, 'Anna');

    $frase = sgsRifiuto($this, $s, sgsCorpo($s, [[$anna, 100]], null, extra: ['conguaglio' => ['scrivi']]), 'conguaglio');

    expect($frase)->not->toContain('conguaglio selezionato');
    expect(mb_substr($frase, 0, 1))->toBe(mb_strtoupper(mb_substr($frase, 0, 1)));
});

// ---------------------------------------------------------------------------------------------------------------------
// denaro-g1 — il legato registrato prima della .48
// ---------------------------------------------------------------------------------------------------------------------

it('denaro-g1: il legato con più legatari registrato prima della .48 dice «le cifre di ciascuno», non «per erede»', function () {
    // Il registro della .44–.47: `regolato_fuori` senza persone e senza la chiave `conguaglio`, la nota sul passaggio.
    $s = sgsCaso();
    $leo = sgsPersona($s, 'Leo');
    $ada = sgsPersona($s, 'Ada');
    $sub = sgsRegistra($this, $s, sgsCorpo($s, [[$leo, 50], [$ada, 50]], $ada, extra: ['conguaglio' => 'non_scrivere', 'nota_conguaglio' => 'Regolato fra i legatari'], legato: true));
    $registro = $sub->registro;
    unset($registro['conguaglio']);
    $registro['regolato_fuori'] = array_map(function (array $v) {
        unset($v['persone']);

        return $v;
    }, $registro['regolato_fuori']);
    $sub->update(['registro' => $registro]);

    $frase = (string) $s['piano']->fresh()->fraseRegolatoFuori();
    $avvisi = implode(' | ', app(AnnullaPassaggioAction::class)->avvisi($sub->fresh()));

    expect($frase)->toContain('le cifre di ciascuno non sono nel registro')
        ->and($avvisi)->toContain('le cifre di ciascuno non sono nel registro');
    expect($frase)->not->toContain('erede');
});

// ---------------------------------------------------------------------------------------------------------------------
// SIC-G1, SIC-G2, SIC-G3 — tipi inattesi nella richiesta (difetti di prima)
// ---------------------------------------------------------------------------------------------------------------------

it('SIC-G1: tipo, sottotipo o ruolo mandati come elenco danno un 422 sul campo, mai una pagina 500, e non scrivono niente', function (string $campo, array $valore) {
    $s = sgsCaso();
    $anna = sgsPersona($s, 'Anna');

    sgsRifiuto($this, $s, sgsCorpo($s, [[$anna, 100]], null, extra: [$campo => $valore, 'conguaglio' => 'non_scrivere']), $campo);
})->with([
    'tipo' => ['tipo', ['successione']],
    'sottotipo' => ['sottotipo', ['legato']],
    'tipologia' => ['tipologia', ['proprietario']],
]);

it('SIC-G2: un erede con anagrafica_id vero (true) si rifiuta sul campo, invece di diventare l\'anagrafica 1', function () {
    // La regola `integer` leggeva `true` come 1: l'anagrafica 1, di qualunque condominio, diventava erede, e i controlli della
    // successione (il perimetro, «non può essere fra i suoi eredi», la somma delle quote) saltavano.
    $s = sgsCaso();

    $corpo = sgsCorpo($s, [], null);
    $corpo['eredi'] = [['anagrafica_id' => true, 'quota' => 100]];

    sgsRifiuto($this, $s, $corpo, 'eredi.0.anagrafica_id');
});

it('SIC-G3: gli eredi mandati come mappa si rifiutano sul campo, invece di registrare la successione senza conguaglio', function () {
    $s = sgsCaso();
    $anna = sgsPersona($s, 'Anna');

    $corpo = sgsCorpo($s, [], null);
    $corpo['eredi'] = ['pippo' => ['anagrafica_id' => $anna->id, 'quota' => 100]];

    sgsRifiuto($this, $s, $corpo, 'eredi');
});

it('SIC-G1, SIC-G2 e SIC-G3, controllo: l\'elenco normale con l\'id intero o in testo si registra come prima', function (bool $inTesto) {
    $s = sgsCaso();
    $anna = sgsPersona($s, 'Anna');
    $corpo = sgsCorpo($s, [[$anna, 100]], null, extra: ['conguaglio' => 'scrivi']);
    if ($inTesto) {
        $corpo['eredi'][0]['anagrafica_id'] = (string) $anna->id;
    }

    $sub = sgsRegistra($this, $s, $corpo);

    expect($sub->destinatari())->toHaveCount(1)
        ->and($sub->destinatari()[0]['anagrafica_id'])->toBe($anna->id);
})->with(['intero' => [false], 'in testo' => [true]]);

// ---------------------------------------------------------------------------------------------------------------------
// La successione del nudo proprietario: l'ordinaria segue la voce (decisione di Vincenzo del 10/10/2026)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Ugo nudo proprietario e Rita usufruttuaria dal 2019, entrambi al 100 %; la voce ordinaria sul ruolo dato (`$soggetto`). Il piano
 * ordinario si genera con loro due e le rate 1–4 si emettono. Il corpo della successione di Ugo (1° maggio) ad Anna, Bruno e Carla.
 *
 * @return array{0: array, 1: array} lo scenario e il corpo
 */
function sgsNuda(string $soggetto): array
{
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto, genera: false);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['tipologia' => 'nuda_proprietario']);
    $rita = sgsPersona($s, 'Rita Usufruttuaria');
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $rita->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'usufruttuario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    app(GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmetti($s);
    $anna = sgsPersona($s, 'Anna');
    $bruno = sgsPersona($s, 'Bruno');
    $carla = sgsPersona($s, 'Carla');

    return [$s, sgsCorpo($s, [[$anna, 33.34], [$bruno, 33.33], [$carla, 33.33]], $anna, extra: ['tipologia' => 'nuda_proprietario'])];
}

it('nuda proprietà: con la voce ordinaria sul «Proprietario» la frase non dice che l\'ordinaria resta all\'usufruttuario, ma che segue la voce', function () {
    // Decisione 29.2: fra usufruttuario e nudo proprietario decide la voce. Con la voce sul «Proprietario» il piano dava l'ordinaria a
    // Ugo, nudo proprietario, e le coppie del conguaglio ci sono anche sull'ordinaria (rilievo della lente testi del giro stretto, dubbio).
    [$s, $corpo] = sgsNuda('proprietario');

    $a = ruAnteprima($this, $s, $corpo);
    $apertura = $a['rate']['frasi'][0];

    expect(collect($a['rate']['conguaglio']['coppie'])->where('gestione', 'Ordinaria 2026')->isNotEmpty())->toBeTrue();
    expect($apertura)->not->toContain('La quota ordinaria resta all\'usufruttuario')
        ->not->toContain('solo la quota straordinaria');
    expect($apertura)->toContain('l\'ordinaria segue la voce: le voci sul «Proprietario» erano a carico di Venditore Ugo, nudo proprietario')
        ->toContain('per i piani che verranno la si segue spostando la voce su «Usufruttuario»');
});

it('nuda proprietà, controllo: con la voce ordinaria sull\'«Usufruttuario» e la facciata deliberata dopo il decesso, la frase dice ancora che l\'ordinaria resta all\'usufruttuario', function () {
    // L'ordinaria è di Rita per la voce; la facciata, deliberata il 1° giugno, è degli eredi: le coppie sono solo sulla straordinaria.
    [$s, $corpo] = sgsNuda('usufruttuario');
    $facciata = ruAggiungiStraordinaria($s, '2026-06-01');
    app(GeneratePianoRateAction::class)->execute($facciata, forzaApplicazioneSaldi: true, esercizio: $s['e']);
    ruEmettiBozze($s, $facciata, '2026-05-31');

    $a = ruAnteprima($this, $s, $corpo);
    $apertura = $a['rate']['frasi'][0];

    expect(collect($a['rate']['conguaglio']['coppie'])->where('gestione', 'Ordinaria 2026')->isEmpty())->toBeTrue()
        ->and(collect($a['rate']['conguaglio']['coppie'])->where('gestione', 'Facciata')->isNotEmpty())->toBeTrue();
    expect($apertura)->toContain('solo la quota straordinaria')
        ->toContain('La quota ordinaria resta all\'usufruttuario (art. 1004 c.c.)');
    expect($apertura)->not->toContain('segue la voce');
});

it('AMM-3, nuda proprietà: nel legato della nuda dopo una costituzione d\'usufrutto le frasi dell\'ordinaria dicono «a chi riceve l\'unità», non «agli eredi»', function () {
    // Dubbio del verificatore della Fase 2: le frasi del motore sulla nuda proprietà («non passano a chi compra la nuda proprietà») la
    // successione le adatta in «non passano agli eredi» (`ConguaglioPassaggio::perGliEredi()`), anche nel legato. Ugo costituisce
    // l'usufrutto a Rita il 1° marzo (con la legge: l'ordinaria all'usufruttuaria), poi muore il 1° maggio lasciando la nuda a Leo.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    ruRegistra($this, $s, ruPassaggio('costituzione', $s['rigaV'], sgsPersona($s, 'Rita Usufruttuaria'), '2026-03-01', 100) + ['ho_letto' => true, 'nota_cancello' => 'Atto di costituzione letto']);
    $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['v']->id)->where('tipologia', 'nuda_proprietario')->whereNull('data_fine')->value('id');
    $leo = sgsPersona($s, 'Leo');

    $legato = ruAnteprima($this, $s, sgsCorpo(['rigaV' => $riga] + $s, [[$leo, 100]], null, extra: ['tipologia' => 'nuda_proprietario'], legato: true));
    $successione = ruAnteprima($this, $s, sgsCorpo(['rigaV' => $riga] + $s, [[$leo, 100]], null, extra: ['tipologia' => 'nuda_proprietario']));
    $fatti = fn (array $a) => implode(' | ', array_merge($a['rate']['frasi'], $a['rate']['frasi_del_conguaglio']));

    expect($fatti($legato))->toContain('non passano a chi riceve l\'unità')
        ->not->toContain('non passano agli eredi');
    // Controllo: nella successione le stesse frasi dicono ancora «agli eredi».
    expect($fatti($successione))->toContain('non passano agli eredi');
});
