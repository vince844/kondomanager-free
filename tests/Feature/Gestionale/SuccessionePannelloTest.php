<?php

/**
 * 1.11.0-beta.48, Fase 1-bis (rilievi R1, R2, R5, R8, R9, R10 e R11 della lente «decide l'amministratore e testi»): le frasi del
 * pannello e del cancello della successione e del legato, lato server.
 *
 * Da dove nasce: la revisione della Fase 1 ha trovato che, finché la scelta sul conguaglio manca o è «Non scriverlo», il pannello e il
 * cancello descrivono ancora come esito quello di «Scrivi»: la cifra che resta al defunto è detta «€ 394,52» (che è la cifra del conguaglio
 * scritto) e il cancello la chiama «come emesso» anche dove restano a suo nome delle bozze; l'apertura del conguaglio dice «è proposto» e
 * le frasi della divisione per quota (€ 268,47 a Bruno…) stanno sempre nella stessa lista dei fatti; nel legato il conguaglio «va agli
 * eredi» e il rifiuto che chiede il riferimento parla di «erede»; «a Anna» sopravvive nel pannello e nei messaggi; la riga per gestione
 * dice «€ -4,38»; il rifiuto di ricalcolare dice «ha preso questo piano nel conguaglio» accanto a «il conguaglio non è stato scritto».
 * Decisione 72 (nessuna preselezione) e decisione 73 (la scelta vale per la successione e per il legato; «ad» davanti alla A).
 *
 * Il caso di riferimento è quello di Fresco e della .44. € 1.200,00 l'anno in dodici rate da € 100,00, rate 1–4 a giornale e non pagate,
 * otto bozze; Ugo muore il 1° maggio; eredi Anna 33,34 %, Bruno 33,33 % e Carla 33,33 %, Anna erede di riferimento, arretrato a nome del
 * defunto. Le cifre, rifatte a mano:
 * - i giorni dopo il decesso sono 245 e i giorni di Ugo 120 (gennaio 31, febbraio 28, marzo 31, aprile 30), su 365: € 1.200,00 × 245 / 365 =
 *   € 805,48 per gli eredi, € 1.200,00 × 120 / 365 = € 394,52 per Ugo; per quota (80548): Anna 26855, Bruno 26847, Carla 26846;
 * - le otto bozze (€ 800,00) passano ad Anna, la cui coppia è 26855 − 80000 = −53145 (€ 531,45 a credito); Bruno 26847, Carla 26846; la
 *   somma delle coppie è +548 e Ugo ha la coppia al contrario, −548;
 * - a nome di Ugo restano le quattro rate emesse: € 400,00 senza conguaglio, € 400,00 − € 5,48 = € 394,52 se si scrive il conguaglio.
 * Gli altri casi:
 * - le quattro rate emesse PAGATE: Ugo non ha niente di aperto, 0 senza conguaglio; con il conguaglio 0 − 548 = −548, cioè un credito
 *   dell'eredità di € 5,48 («€ 0,00 senza conguaglio, € 5,48 a credito se scrivi il conguaglio»);
 * - il decesso al 1° settembre, un erede solo (Anna 100 %): le rate 5–8 scadono il 5 maggio, giugno, luglio e agosto, prima del decesso, e
 *   restano a Ugo; le rate 9–12 (5 settembre, ottobre, novembre e dicembre, € 400,00) passano ad Anna. I giorni di Ugo sono 243
 *   (31 + 28 + 31 + 30 + 31 + 30 + 31 + 31): € 1.200,00 × 243 / 365 = 79890; quelli dell'erede 122 (30 + 31 + 30 + 31): × 122 / 365 =
 *   40110 (79890 + 40110 = 120000). A Ugo senza conguaglio restano le quattro emesse e le quattro bozze: 8 × 10000 = 80000 (€ 800,00);
 *   la coppia di Anna è 40110 − 40000 = 110, e con il conguaglio a Ugo restano 80000 − 110 = 79890 (€ 798,90, i suoi 243 giorni);
 * - il decesso il 4 maggio, due eredi al 50 %, Anna riferimento: i giorni dopo il decesso sono 28 + 214 = 242 (maggio dal 4: 28; giugno
 *   30, luglio 31, agosto 31, settembre 30, ottobre 31, novembre 30, dicembre 31 = 214), quelli di Ugo 123 (120 + 3): € 1.200,00 × 242 /
 *   365 = 79562 (€ 795,62, 39781 a erede) e × 123 / 365 = 40438; le bozze 5–12 (la rata del 5 maggio scade dopo il decesso) valgono
 *   80000. La coppia di Anna è 39781 − 80000 = −40219 (€ 402,19 a credito), quella di Bruno 39781; per gestione 79562 − 80000 = −438
 *   (€ 4,38 a credito). A Ugo restano 40000 senza conguaglio e 40000 − (−438) = 40438 con il conguaglio;
 * - il legato a Leo (un legatario solo, 100 %): le cifre di Fresco con una coppia sola, Leo 80548 − 80000 = 548 a debito, Ugo −548:
 *   € 400,00 senza conguaglio, € 394,52 se si scrive.
 *
 * Cosa presidia, rosso sul codice di oggi: `rate.arretrato.frase_da_scegliere` (R1: le due cifre prima della scelta, anche con le rate
 * pagate, dove `frase_senza_conguaglio` è nulla); «senza conguaglio» al posto di «come emesso» nelle informazioni del cancello (R1 e
 * R9, anche con il decesso al 1° settembre); la frase d'apertura al condizionale («Se scrivi il conguaglio…», senza «è proposto»; nel
 * legato «a chi riceve l'unità» e non «agli eredi») e `rate.frasi_del_conguaglio` con le frasi del calcolo per quota (R2); «ad Anna»
 * nelle frasi del pannello (anche con un erede solo e con due eredi), nei motivi del cancello, nel messaggio verde dopo la registrazione
 * e negli effetti dell'annullamento (R5, decisione 73 punto 4), e «ad Ada» per ogni nome che comincia per A; «€ 4,38 a credito» nella
 * riga per gestione (R8); il rifiuto che chiede il riferimento nel legato senza la parola «erede» (R10); il rifiuto di ricalcolare e la
 * ragione del fermo senza «preso nel conguaglio» per un passaggio «non scritto» (R11). Aggiunti con le correzioni, sulla stessa ragione di
 * R2: le bozze che restano al defunto «si conguagliano qui» e sono «comprese nel conguaglio» solo se il conguaglio si scrive (nella
 * vendita, senza condizione).
 *
 * I controlli, verdi oggi, che devono restarlo: le cifre dell'arretrato in centesimi, la frase_senza_conguaglio nulla con le rate pagate,
 * la frase_da_scegliere assente senza coppie e con l'arretrato agli eredi; i fatti (le bozze che passano) in `rate.frasi` anche quando il
 * conguaglio è da scegliere; la vendita con «è proposto» e il suo «credito … debito …» in `rate.frasi`, senza `frasi_del_conguaglio`;
 * l'arretrato agli eredi con «è proposto» e la divisione per quota in `rate.frasi`; «a Bruno» e «a Leo» (la «d» solo davanti alla A);
 * le coppie del 4 maggio e i totali; il rifiuto dell'arretrato agli eredi senza «se lo scrivi»; con «Scrivi», e con la rinuncia della
 * vendita, le parole di oggi del rifiuto di ricalcolare e della ragione del fermo.
 *
 * Cosa il test lascia libero: come sono spezzate le frasi. L'apertura del conguaglio si cerca per contenuto (`spnApertura()`) e il
 * verbale ne fissa solo l'inizio («Se scrivi il conguaglio»): vale sia che «Le rate già emesse non si toccano.» resti una frase a parte
 * sia che stia nella stessa stringa. Delle frasi che il verbale cita si controllano i pezzi fissati («si divide per quota: € 268,55 ad
 * Anna», «passano ad Anna»), non le parole intorno.
 *
 * Cosa NON copre: la pagina (`fraseArretrato(…, daScegliere)`, `aNome`, il riquadro «conguaglio da scegliere», le frasi del conguaglio
 * nascoste con «Non scriverlo», la riga della scheda dello storico: test JS); la scelta e il suo registro, le richieste e i divieti
 * sul campo `conguaglio`, il giorno del decesso nei messaggi, lo storico del box, le cifre per erede dei passaggi di prima e
 * l'annullamento dell'emissione con una successione «non scritta» (altri file della Fase 1-bis); i messaggi dei saldi.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
use App\Models\User;
use App\Services\Subentro\StoricoTitolarita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
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

/** Una persona del condominio dello scenario (un erede, un legatario). */
function spnPersona(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => 'spn' . $n . '-' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'SPNPERSONA' . str_pad((string) $n, 6, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/**
 * Lo scenario di Fresco: le rate 1–4 a giornale (`$emesse`) o nessuna rata emessa, più i tre eredi.
 *
 * @return array{0: array, 1: Anagrafica, 2: Anagrafica, 3: Anagrafica} lo scenario, Anna, Bruno e Carla
 */
function spnCaso(bool $emesse = true): array
{
    $s = ruScenario('prima_rata', 0);
    if ($emesse) {
        ruEmetti($s);
    }

    return [$s, spnPersona($s, 'Anna'), spnPersona($s, 'Bruno'), spnPersona($s, 'Carla')];
}

/** Le quattro rate emesse, pagate per intero. */
function spnPaga(array $s): void
{
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->whereIn('numero_rata', [1, 2, 3, 4])->pluck('id');
    DB::table('rate_quote')->whereIn('rata_id', $rate)->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
}

/** Il corpo della successione di Ugo (1° maggio) ai tre eredi; Anna è l'erede di riferimento salvo `$extra`. */
function spnCorpo(array $s, Anagrafica $anna, Anagrafica $bruno, Anagrafica $carla, array $extra = [], string $arretrato = 'defunto', ?Anagrafica $riferimento = null): array
{
    return array_merge([
        'tipo' => 'successione', 'riga_uscente_id' => $s['rigaV'], 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $anna->id, 'quota' => 33.34], ['anagrafica_id' => $bruno->id, 'quota' => 33.33], ['anagrafica_id' => $carla->id, 'quota' => 33.33]],
        'arretrato' => $arretrato, 'erede_di_riferimento' => ($riferimento ?? $anna)->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 123', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta: gli eredi entrano dal decesso',
    ], $extra);
}

/** Il corpo di una successione a un erede solo (100 %), con il decesso alla data data: nessun erede di riferimento da scegliere. */
function spnCorpoUnico(array $s, Anagrafica $erede, string $decesso, array $extra = []): array
{
    return array_merge([
        'tipo' => 'successione', 'riga_uscente_id' => $s['rigaV'], 'decorrenza' => $decesso, 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $erede->id, 'quota' => 100]], 'arretrato' => 'defunto', 'erede_di_riferimento' => null,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 123', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta: gli eredi entrano dal decesso',
    ], $extra);
}

/** Il corpo di una successione a due eredi al 50 %, Anna e Bruno, Anna riferimento, con il decesso alla data data. */
function spnCorpoDue(array $s, Anagrafica $anna, Anagrafica $bruno, string $decesso): array
{
    return [
        'tipo' => 'successione', 'riga_uscente_id' => $s['rigaV'], 'decorrenza' => $decesso, 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $anna->id, 'quota' => 50], ['anagrafica_id' => $bruno->id, 'quota' => 50]],
        'arretrato' => 'defunto', 'erede_di_riferimento' => $anna->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 123', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta: gli eredi entrano dal decesso',
    ];
}

/** Il corpo del legato: i legatari (nome → quota) e, se serve, il riferimento; l'arretrato resta a nome del defunto. */
function spnCorpoLegato(array $s, array $legatari, ?Anagrafica $riferimento = null, array $extra = []): array
{
    return array_merge([
        'tipo' => 'successione', 'sottotipo' => 'legato', 'riga_uscente_id' => $s['rigaV'], 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => array_map(fn (array $l) => ['anagrafica_id' => $l[0]->id, 'quota' => $l[1]], $legatari), 'arretrato' => 'defunto', 'erede_di_riferimento' => $riferimento?->id,
        'copia_autentica' => false, 'estremi_titolo' => 'testamento pubblicato, rep. 55', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Testamento letto: il legatario entra dal decesso',
    ], $extra);
}

/**
 * I cinque scenari della cifra che resta al defunto: la chiave dice quale; il risultato è lo scenario e il corpo dell'anteprima.
 *
 * @return array{0: array, 1: array} lo scenario e il corpo
 */
function spnScenario(string $chiave): array
{
    switch ($chiave) {
        case 'fresco':
            [$s, $anna, $bruno, $carla] = spnCaso();

            return [$s, spnCorpo($s, $anna, $bruno, $carla)];
        case 'pagate':
            [$s, $anna, $bruno, $carla] = spnCaso();
            spnPaga($s);

            return [$s, spnCorpo($s, $anna, $bruno, $carla)];
        case 'settembre':
            $s = ruScenario('prima_rata', 0);
            ruEmetti($s);

            return [$s, spnCorpoUnico($s, spnPersona($s, 'Anna'), '2026-09-01')];
        case 'quattro_maggio':
            $s = ruScenario('prima_rata', 0);
            ruEmetti($s);

            return [$s, spnCorpoDue($s, spnPersona($s, 'Anna'), spnPersona($s, 'Bruno'), '2026-05-04')];
        case 'legato':
            $s = ruScenario('prima_rata', 0);
            ruEmetti($s);

            return [$s, spnCorpoLegato($s, [[spnPersona($s, 'Leo'), 100]])];
    }
    throw new InvalidArgumentException("Scenario sconosciuto: {$chiave}");
}

/** Il corpo di una vendita il 1° maggio, a Elsa (`a`), con il rogito letto. */
function spnCorpoVendita(array $s, array $extra = []): array
{
    return array_merge(ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-05-01', 100), ['ho_letto' => true, 'nota_cancello' => 'Rogito letto fra le parti'], $extra);
}

/** La registrazione dalla rotta vera, che deve riuscire; il passaggio padre appena scritto. */
function spnRegistra($test, array $s, array $corpo): Subentro
{
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $corpo)->assertSessionHasNoErrors();

    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

/** Il messaggio verde dopo la registrazione (`passaggio_registrato`). */
function spnMessaggioVerde($test, array $s, array $corpo): array
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $corpo)
        ->assertSessionHasNoErrors()->getSession()->get('passaggio_registrato');
}

/** L'anteprima rifiutata con 422 sul campo: le frasi dell'errore, in un testo solo. */
function spnRifiuto($test, array $s, array $corpo, string $campo): string
{
    $errori = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $corpo)
        ->assertStatus(422)->assertJsonValidationErrors([$campo])->json("errors.{$campo}");

    return implode(' ', $errori);
}

/**
 * L'apertura del conguaglio nelle frasi del pannello, dal punto in cui comincia alla fine di quella stringa: «Se scrivi il conguaglio…»
 * (la forma della .48) o, sul codice di oggi, «Il conguaglio fra…». Si cerca per contenuto e non per posizione: il verbale fissa come
 * comincia l'apertura («Se scrivi il conguaglio»), non se «Le rate già emesse non si toccano.» sia una frase a parte o la stessa stringa.
 */
function spnApertura(array $frasi): string
{
    foreach (['Se scrivi il conguaglio', 'Il conguaglio fra'] as $inizio) {
        foreach ($frasi as $f) {
            if (($pos = mb_strpos($f, $inizio)) !== false) {
                return mb_substr($f, $pos);
            }
        }
    }
    Assert::fail('Nessuna frase di rate.frasi apre il conguaglio: ' . implode(' | ', $frasi));
}

/** Le frasi dell'anteprima, in un testo solo: i fatti (`rate.frasi`) e le frasi del calcolo (`rate.frasi_del_conguaglio`, se c'è). */
function spnTutteLeFrasi(array $anteprima): string
{
    return implode(' | ', array_merge($anteprima['rate']['frasi'], $anteprima['rate']['frasi_del_conguaglio'] ?? []));
}

/** `rate.frasi_del_conguaglio`, la chiave nuova della .48: se manca il test lo dice, invece di un «Undefined array key». */
function spnFrasiDelConguaglio(array $anteprima): array
{
    Assert::assertArrayHasKey('frasi_del_conguaglio', $anteprima['rate'], 'Manca rate.frasi_del_conguaglio (la chiave nuova della .48, R2).');
    Assert::assertIsArray($anteprima['rate']['frasi_del_conguaglio']);

    return $anteprima['rate']['frasi_del_conguaglio'];
}

/** `rate.arretrato.frase_da_scegliere`, la chiave nuova della .48: deve esserci, ed essere una frase. */
function spnFraseDaScegliere(array $anteprima): string
{
    Assert::assertArrayHasKey('frase_da_scegliere', $anteprima['rate']['arretrato'], 'Manca rate.arretrato.frase_da_scegliere (la chiave nuova della .48, R1).');
    Assert::assertIsString($anteprima['rate']['arretrato']['frase_da_scegliere']);

    return $anteprima['rate']['arretrato']['frase_da_scegliere'];
}

/** Gli effetti dell'annullamento del passaggio più recente dell'unità, come li dice lo storico (le frasi del modulo di annullamento). */
function spnEffettiDelloStorico(array $s): string
{
    return implode(' | ', app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'][0]['annullabile']['effetti']);
}

/** Le cinque cifre «che resta al defunto» dei cinque scenari: [scenario, resta, resta_senza_conguaglio, la frase delle due cifre]. */
dataset('spn_due_cifre', [
    // 4 × 10000 = 40000 emesse; coppia +548: 40000 − 548 = 39452 (€ 1.200,00 × 120 / 365).
    'Fresco, rate emesse non pagate' => ['fresco', 39452, 40000, '€ 400,00 senza conguaglio, € 394,52 se scrivi il conguaglio'],
    // Pagate: aperto 0; 0 − 548 = −548, un credito dell'eredità.
    'Fresco, le quattro rate emesse pagate' => ['pagate', -548, 0, '€ 0,00 senza conguaglio, € 5,48 a credito se scrivi il conguaglio'],
    // 8 × 10000 = 80000 (quattro emesse e quattro bozze che restano); coppia +110: 80000 − 110 = 79890 (243 giorni).
    'decesso al 1° settembre, bozze che restano al defunto' => ['settembre', 79890, 80000, '€ 800,00 senza conguaglio, € 798,90 se scrivi il conguaglio'],
    // Coppie −40219 e +39781, somma −438: 40000 − (−438) = 40438 (123 giorni).
    'decesso il 4 maggio, due eredi' => ['quattro_maggio', 40438, 40000, '€ 400,00 senza conguaglio, € 404,38 se scrivi il conguaglio'],
    // Una coppia sola, Leo +548: come Fresco.
    'legato a Leo' => ['legato', 39452, 40000, '€ 400,00 senza conguaglio, € 394,52 se scrivi il conguaglio'],
]);

// ---------------------------------------------------------------------------------------------------------------------
// R1 e R9 — la scelta richiesta dice le due cifre: `frase_da_scegliere` e il cancello
// ---------------------------------------------------------------------------------------------------------------------

it('R1: con la scelta richiesta il riquadro dell\'arretrato ha una terza frase, con le due cifre «senza conguaglio» e «se scrivi il conguaglio»', function (string $chiave, int $resta, int $senza, string $attesa) {
    // Rilievo R1 (decide-testi): finché la scelta manca, la pagina mostrava `frase` (€ 394,52), che è la cifra di «Scrivi». Dopo
    // la correzione il server manda `rate.arretrato.frase_da_scegliere`, con le due cifre.
    [$s, $corpo] = spnScenario($chiave);

    $frase = spnFraseDaScegliere(ruAnteprima($this, $s, $corpo));

    expect($frase)->toContain($attesa);
})->with('spn_due_cifre');

it('R1, controllo: le cifre dell\'arretrato a nome del defunto restano quelle di oggi, in centesimi', function (string $chiave, int $resta, int $senza, string $attesa) {
    // La correzione tocca le parole, non il denaro: `resta` (con il conguaglio) e `resta_senza_conguaglio`, rifatti a mano nel dataset.
    [$s, $corpo] = spnScenario($chiave);

    $arretrato = ruAnteprima($this, $s, $corpo)['rate']['arretrato'];

    expect($arretrato['resta'])->toBe($resta)
        ->and($arretrato['resta_senza_conguaglio'])->toBe($senza);
})->with('spn_due_cifre');

it('R1, controllo: con le rate emesse pagate senza conguaglio non resta niente a Ugo, e la frase «senza conguaglio» di oggi è nulla (per questo serve la terza frase)', function () {
    // Le quattro rate pagate: aperto 0. `frase` dice il credito di € 5,48 (la cifra di «Scrivi»), `frase_senza_conguaglio` è nulla perché
    // non c'è niente da dire; ma la scelta c'è ancora, e il riquadro non può sparire né mostrare solo il credito.
    [$s, $corpo] = spnScenario('pagate');

    $arretrato = ruAnteprima($this, $s, $corpo)['rate']['arretrato'];

    expect($arretrato['frase'])->toContain('€ 5,48 a credito')
        ->and($arretrato['frase_senza_conguaglio'])->toBeNull();
});

it('R1: nelle informazioni del cancello la cifra che resta al defunto dice «senza conguaglio» e non «come emesso»', function (string $chiave, int $resta, int $senza, string $attesa) {
    // Rilievi R1 e R9 (decide-testi): «come emesso» era falso già nel caso di Fresco (la scelta non ha preselezione) e dove restano a
    // nome del defunto delle bozze (decesso al 1° settembre: di emesso ci sono € 400,00, non € 800,00).
    [$s, $corpo] = spnScenario($chiave);

    $informazioni = implode(' | ', ruAnteprima($this, $s, $corpo)['cancello']['informazioni']);

    expect($informazioni)->toContain($attesa);
    expect($informazioni)->not->toContain('come emesso');
})->with('spn_due_cifre');

it('R1, controllo: senza coppie la scelta non è richiesta e non c\'è nessuna frase da scegliere', function () {
    // Nessuna rata emessa: il piano si ricalcola ancora, non ci sono bozze da spostare e non c'è niente da conguagliare.
    [$s, $anna, $bruno, $carla] = spnCaso(emesse: false);

    $arretrato = ruAnteprima($this, $s, spnCorpo($s, $anna, $bruno, $carla))['rate']['arretrato'];

    expect($arretrato['frase_da_scegliere'] ?? null)->toBeNull();
});

it('R1, controllo: con l\'arretrato agli eredi la scelta non c\'è e non c\'è nessuna frase da scegliere', function () {
    // Con l'arretrato agli eredi coppia e arretrato fanno un conto solo (P1): `non_scrivere` si rifiuta, e il riquadro non ha due cifre.
    [$s, $anna, $bruno, $carla] = spnCaso();

    $arretrato = ruAnteprima($this, $s, spnCorpo($s, $anna, $bruno, $carla, arretrato: 'eredi'))['rate']['arretrato'];

    expect($arretrato['scelta'])->toBe('eredi')
        ->and($arretrato['frase_da_scegliere'] ?? null)->toBeNull();
});

// ---------------------------------------------------------------------------------------------------------------------
// R2 — l'apertura al condizionale e le frasi del calcolo per quota
// ---------------------------------------------------------------------------------------------------------------------

it('R2: con la scelta richiesta l\'apertura del conguaglio comincia con «Se scrivi il conguaglio», nella successione e nel legato', function (string $chiave) {
    // Rilievo R2 (decide-testi): l'apertura del conguaglio. Oggi: «Le rate già emesse non si toccano. Il conguaglio fra Venditore Ugo e
    // Anna (33,34 %), Bruno (33,33 %) e Carla (33,33 %) è proposto come righe di saldo…». Il verbale fissa come comincia la frase, non se
    // «Le rate già emesse non si toccano.» resti nella stessa stringa: basta che una frase cominci con «Se scrivi il conguaglio».
    [$s, $corpo] = spnScenario($chiave);

    $frasi = ruAnteprima($this, $s, $corpo)['rate']['frasi'];

    expect(implode("\n", $frasi))->toMatch('/(^|\n|\. )Se scrivi il conguaglio/u');
})->with(['successione, caso di Fresco' => ['fresco'], 'legato a Leo' => ['legato']]);

it('R2: con la scelta richiesta l\'apertura del conguaglio non dice «è proposto», nella successione e nel legato', function (string $chiave) {
    // «È proposto» è proprio la proposta di partenza che la decisione 72 toglie: dopo «Non scriverlo» il pannello non può dirla.
    [$s, $corpo] = spnScenario($chiave);

    $apertura = spnApertura(ruAnteprima($this, $s, $corpo)['rate']['frasi']);

    expect($apertura)->not->toContain('è proposto');
})->with(['successione, caso di Fresco' => ['fresco'], 'legato a Leo' => ['legato']]);

it('R2, controllo: le frasi del pannello della successione dicono ancora da quale giorno gli eredi sono in carica, il 1 maggio 2026', function () {
    // La sostanza dell'apertura non cambia: solo il modo (condizionale) e, nel legato, il destinatario. Il giorno del decesso resta in
    // qualche frase del pannello, comunque il server la spezzi.
    [$s, $corpo] = spnScenario('fresco');

    $frasi = spnTutteLeFrasi(ruAnteprima($this, $s, $corpo));

    expect($frasi)->toContain('1 maggio 2026');
});

it('R2: nel legato l\'apertura del conguaglio dice «a chi riceve l\'unità», non «agli eredi»', function () {
    // Rilievo R2 (decide-testi), legato con Leo: oggi «va agli eredi, divisa per quota», ma Leo non è erede.
    [$s, $corpo] = spnScenario('legato');

    $apertura = spnApertura(ruAnteprima($this, $s, $corpo)['rate']['frasi']);

    expect($apertura)->toContain('a chi riceve l\'unità');
    expect($apertura)->not->toContain('agli eredi');
});

it('R2: le frasi del calcolo per quota escono dai fatti e vanno in «frasi_del_conguaglio», con «ad Anna»', function () {
    // Rilievo R2 (decide-testi): 245 giorni, € 805,48 per quota = € 268,55 ad Anna (33,34 %), € 268,47 a Bruno, € 268,46 a Carla; le otto
    // bozze (€ 800,00) vanno ad Anna. Sono vere solo se il conguaglio si scrive: con «Non scriverlo» Bruno e Carla non hanno niente da pagare.
    [$s, $corpo] = spnScenario('fresco');
    $a = ruAnteprima($this, $s, $corpo);

    $delConguaglio = implode(' | ', spnFrasiDelConguaglio($a));
    $fatti = implode(' | ', $a['rate']['frasi']);

    expect($delConguaglio)->toContain('si divide per quota: € 268,55 ad Anna')
        ->toContain('€ 268,47 a Bruno')
        ->toContain('€ 268,46 a Carla')
        ->toContain('Le rate in bozza (€ 800,00) vanno ad Anna');
    expect($fatti)->not->toContain('€ 268,47 a Bruno');
    expect($fatti)->not->toContain('Le rate in bozza (€ 800,00) vanno');
});

it('R2, controllo: i fatti che valgono con qualunque scelta restano in «frasi», le otto bozze che passano ad Anna comprese', function () {
    // Le bozze passano all'erede di riferimento anche con «Non scriverlo» (P5): la frase è un fatto, non una conseguenza del conguaglio.
    [$s, $corpo] = spnScenario('fresco');

    $fatti = implode(' | ', ruAnteprima($this, $s, $corpo)['rate']['frasi']);

    expect($fatti)->toContain('Le 8 rate in bozza del piano «Preventivo 2026» con scadenza dal 5 maggio 2026 al 5 dicembre 2026 passano')
        ->toContain('cambia l\'intestatario, non l\'importo (€ 800,00 di preventivo, che da ora paga a suo nome)')
        ->toContain('Venditore Ugo ha € 400,00 scaduti e non pagati: restano a suo nome, e ne rispondono gli eredi.');
});

it('R2: nel legato la frase «credito … a Venditore Ugo, debito … a Leo» va in «frasi_del_conguaglio» e non resta fra i fatti', function () {
    // Rilievo R2 (decide-testi), legato: Leo 805,48 − 800,00 = 5,48 a debito, Ugo 5,48 a credito. Con «Non scriverlo» la coppia non si
    // scrive, e la frase sarebbe falsa.
    [$s, $corpo] = spnScenario('legato');
    $a = ruAnteprima($this, $s, $corpo);

    $delConguaglio = implode(' | ', spnFrasiDelConguaglio($a));
    $fatti = implode(' | ', $a['rate']['frasi']);

    expect($delConguaglio)->toContain('credito € 5,48 a Venditore Ugo, debito € 5,48 a Leo');
    expect($fatti)->not->toContain('credito € 5,48 a Venditore Ugo');
});

it('R2: con la scelta richiesta le bozze che restano al defunto «si conguagliano qui» solo se scrivi il conguaglio, fra i fatti e nel cancello', function () {
    // Decesso al 1° settembre: le rate 5–8 scadono prima del decesso e restano intestate a Ugo; con «Non scriverlo» non si conguagliano.
    // Le frasi della vendita lo davano per fatto («e si conguagliano qui», «e sono comprese nel conguaglio»).
    [$s, $corpo] = spnScenario('settembre');
    $a = ruAnteprima($this, $s, $corpo);

    expect(implode(' | ', $a['rate']['frasi']))->toContain('resteranno intestate a Venditore Ugo e, se scrivi il conguaglio, si conguagliano qui.')
        ->and(implode(' | ', $a['cancello']['motivi']))->toContain('4 restano sue e sono comprese nel conguaglio, se lo scrivi');
});

it('R2, controllo: nella vendita le bozze che restano a chi esce si conguagliano senza condizione', function () {
    // Vendita al 1° settembre: le stesse quattro bozze restano a Ugo e il conguaglio si scrive sempre (la casella è una rinuncia, non una scelta).
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    $a = ruAnteprima($this, $s, array_merge(ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-09-01', 100), ['ho_letto' => true, 'nota_cancello' => 'Rogito letto fra le parti']));
    $fatti = implode(' | ', $a['rate']['frasi']);
    $motivi = implode(' | ', $a['cancello']['motivi']);

    expect($fatti)->toContain('resteranno intestate a Venditore Ugo e si conguagliano qui.')
        ->and($motivi)->toContain('comprese nel conguaglio');
    expect($fatti)->not->toContain('se scrivi il conguaglio');
    expect($motivi)->not->toContain('se lo scrivi');
});

it('R2, controllo: la vendita ha ancora «è proposto» e il suo «credito … debito …» fra i fatti, senza frasi del conguaglio a parte', function () {
    // Fuori dalla successione niente cambia (decisione 73, punto 3): vendita, usufrutto e locazione restano con la casella e le frasi di oggi.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    $a = ruAnteprima($this, $s, spnCorpoVendita($s));

    expect($a['rate']['frasi'][0])->toStartWith('Le rate già emesse non si toccano.')
        ->toContain('è proposto come due righe di saldo che sommano a zero')
        // Coppia: Elsa 805,48 − 800,00 = 5,48 a debito, Ugo a credito.
        ->and(implode(' | ', $a['rate']['frasi']))->toContain('credito € 5,48 a Venditore Ugo, debito € 5,48 a')
        ->and($a['rate']['frasi_del_conguaglio'] ?? [])->toBe([])
        ->and($a['rate']['arretrato']['frase_da_scegliere'] ?? null)->toBeNull();
});

it('R2, controllo: con l\'arretrato agli eredi la scelta non c\'è e il conguaglio resta «proposto», con la divisione per quota fra i fatti', function () {
    // Il condizionale e la chiave a parte valgono «con la scelta richiesta»: con l'arretrato agli eredi il conguaglio si scrive sempre.
    [$s, $anna, $bruno, $carla] = spnCaso();

    $a = ruAnteprima($this, $s, spnCorpo($s, $anna, $bruno, $carla, arretrato: 'eredi'));

    expect(spnApertura($a['rate']['frasi']))->toContain('è proposto come righe di saldo')
        ->and(implode(' | ', $a['rate']['frasi']))->toContain('€ 268,47 a Bruno')
        ->and($a['rate']['frasi_del_conguaglio'] ?? [])->toBe([]);
});

// ---------------------------------------------------------------------------------------------------------------------
// R5 — «ad» davanti ai nomi che cominciano per A, in tutte le frasi del pannello e dei messaggi della successione
// ---------------------------------------------------------------------------------------------------------------------

it('R5: le frasi del pannello della successione dicono «passano ad Anna», «€ 268,55 ad Anna» e «vanno ad Anna»', function () {
    // Rilievo R5 (decide-testi), decisione 73 punto 4: l'erede di riferimento si chiama Anna. Le bozze (€ 800,00) passano ad Anna; la sua
    // parte dei giorni dal decesso è € 268,55 (80548 × 33,34 % = 26854,70; dei due centesimi di resto uno va ad Anna, ,70, l'altro a Bruno, ,65); le bozze
    // «vanno ad Anna».
    [$s, $corpo] = spnScenario('fresco');

    $frasi = spnTutteLeFrasi(ruAnteprima($this, $s, $corpo));

    expect($frasi)->toContain('passano ad Anna')
        ->toContain('€ 268,55 ad Anna')
        ->toContain('vanno ad Anna');
});

it('R5: nelle altre frasi del calcolo «che passano ad Anna» e «245 ad Anna, Bruno e Carla», e in nessuna «a Anna»', function () {
    // Le frasi sorelle del pannello: «€ 800,00 sono già nelle rate in bozza che passano ad Anna» e i giorni di competenza di chi entra
    // («120 a Venditore Ugo, 245 ad Anna, Bruno e Carla»: 245 giorni dal decesso). Poi la verifica di tutte: nessuna «a Anna».
    [$s, $corpo] = spnScenario('fresco');

    $frasi = spnTutteLeFrasi(ruAnteprima($this, $s, $corpo));

    expect($frasi)->toContain('che passano ad Anna')
        ->toContain('245 ad Anna, Bruno e Carla');
    expect($frasi)->not->toContain(' a Anna');
});

it('R5: i motivi del cancello della successione dicono «passano ad Anna», anche con una parte sola che passa', function (string $chiave, string $attesa) {
    // Rilievo R5 (decide-testi): la riga «il piano … ha N quote non ancora emesse intestate a Venditore Ugo: non si può più ricalcolare,
    // passano ad Anna». Al 1° settembre solo quattro bozze passano (le altre quattro restano a Ugo, comprese nel conguaglio): «4 passano».
    [$s, $corpo] = spnScenario($chiave);

    $motivi = implode(' | ', ruAnteprima($this, $s, $corpo)['cancello']['motivi']);

    expect($motivi)->toContain($attesa);
    expect($motivi)->not->toContain(' a Anna');
})->with([
    'Fresco, tutte le otto bozze' => ['fresco', 'passano ad Anna'],
    'decesso al 1° settembre, quattro bozze su otto' => ['settembre', '4 passano ad Anna'],
]);

it('R5: il messaggio verde dopo la registrazione dice «sono passate ad Anna», con la scelta «scrivi» e con «non scrivere»', function (string $scelta) {
    // Rilievo R5 (decide-testi): `passaggio_registrato.riassegnate_frase`, la stessa cosa che il pannello dice al futuro, detta al passato.
    [$s, $anna, $bruno, $carla] = spnCaso();

    $verde = spnMessaggioVerde($this, $s, spnCorpo($s, $anna, $bruno, $carla, ['conguaglio' => $scelta]));

    expect($verde['riassegnate'])->toBe(8)
        ->and($verde['riassegnate_frase'])->toContain('sono passate ad Anna');
    expect($verde['riassegnate_frase'])->not->toContain(' a Anna');
})->with(['scrivi' => ['scrivi'], 'non scrivere' => ['non_scrivere']]);

it('R5: gli effetti dell\'annullamento, nella scheda dello storico, dicono «passate ad Anna», con la scelta «scrivi» e con «non scrivere»', function (string $scelta) {
    // Rilievo R5 (decide-testi): `annullabile.effetti`, «Le quote di 8 rate passate ad Anna tornano a Venditore Ugo, con le regole di prima.»
    [$s, $anna, $bruno, $carla] = spnCaso();
    spnRegistra($this, $s, spnCorpo($s, $anna, $bruno, $carla, ['conguaglio' => $scelta]));

    $effetti = spnEffettiDelloStorico($s);

    expect($effetti)->toContain('passate ad Anna');
    expect($effetti)->not->toContain(' a Anna');
})->with(['scrivi' => ['scrivi'], 'non scrivere' => ['non_scrivere']]);

it('R5: il messaggio dopo l\'annullamento del passaggio dice «passate ad Anna»', function (string $scelta) {
    // Gli stessi effetti, detti al passato dopo l'annullamento (con «non scrivere» il messaggio porta anche l'avviso sulle posizioni).
    [$s, $anna, $bruno, $carla] = spnCaso();
    $sub = spnRegistra($this, $s, spnCorpo($s, $anna, $bruno, $carla, ['conguaglio' => $scelta]));

    $messaggio = ruAnnulla($this, $s, $sub)->getSession()->get('message')['message'];

    expect($messaggio)->toContain('Passaggio annullato.')
        ->toContain('passate ad Anna');
    expect($messaggio)->not->toContain(' a Anna');
})->with(['scrivi' => ['scrivi'], 'non scrivere' => ['non_scrivere']]);

it('R5: con l\'arretrato agli eredi la frase dell\'arretrato e il motivo del cancello dicono «€ 131,53 ad Anna»', function () {
    // Le frasi del pannello con l'arretrato agli eredi. Posizione di Ugo 40000 (le quattro emesse) + 80000 (le bozze passate) = 120000,
    // per quota: Anna 40008, Bruno 39996, Carla 39996. Meno le bozze che paga Anna (80000) e meno la coppia di ciascuno:
    // Anna 40008 − 80000 − (−53145) = 13153; Bruno 39996 − 26847 = 13149; Carla 39996 − 26846 = 13150 (somma 39452 = € 394,52).
    [$s, $anna, $bruno, $carla] = spnCaso();

    $a = ruAnteprima($this, $s, spnCorpo($s, $anna, $bruno, $carla, arretrato: 'eredi'));
    $frase = $a['rate']['arretrato']['frase'];
    $motivi = implode(' | ', $a['cancello']['motivi']);

    expect($frase)->toContain('€ 131,53 ad Anna')
        ->toContain('€ 131,49 a Bruno')
        ->toContain('€ 131,50 a Carla');
    expect($frase)->not->toContain(' a Anna');
    expect($motivi)->toContain('€ 131,53 ad Anna')
        ->toContain('€ 131,49 a Bruno')
        ->toContain('€ 131,50 a Carla')
        ->toContain('passano ad Anna');
    expect($motivi)->not->toContain(' a Anna');
});

it('R5: anche con un erede solo e con due eredi nessuna frase del pannello dice «a Anna»', function (string $chiave) {
    // Decisione 73 punto 4: «in tutte le frasi del pannello». Al 1° settembre l'erede è uno solo (Anna 100 %), il 4 maggio sono due al
    // 50 %: le frasi sono quelle del calcolo («debito € 1,10 a Anna», «242 a Anna e Bruno», «€ 397,81 a Anna (50 %)»), dovunque stiano.
    [$s, $corpo] = spnScenario($chiave);

    $frasi = spnTutteLeFrasi(ruAnteprima($this, $s, $corpo));

    expect($frasi)->not->toContain(' a Anna');
    expect($frasi)->toContain('ad Anna');
})->with(['decesso al 1° settembre, un erede' => ['settembre'], 'decesso il 4 maggio, due eredi' => ['quattro_maggio']]);

it('R5: la «d» vale per ogni nome che comincia per A, non solo per Anna — nel legato a Leo e Ada, con Ada di riferimento, «passano ad Ada»', function () {
    // Il verbale dice «un nome che comincia per A» (la regola di `FrasiObbligati::a()`): Ada ha la A come Anna, Leo no.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $leo = spnPersona($s, 'Leo');
    $ada = spnPersona($s, 'Ada');

    $a = ruAnteprima($this, $s, spnCorpoLegato($s, [[$leo, 50], [$ada, 50]], $ada));
    $frasi = spnTutteLeFrasi($a);
    $motivi = implode(' | ', $a['cancello']['motivi']);

    expect($frasi)->toContain('passano ad Ada');
    expect($frasi)->not->toContain(' a Ada');
    expect($motivi)->toContain('passano ad Ada');
    expect($motivi)->not->toContain(' a Ada');
});

it('R5, controllo: un\'erede di riferimento che non comincia per A resta con «a» — «a Bruno» nel pannello, nel cancello e nei messaggi', function () {
    // La «d» si aggiunge solo davanti alla A: Bruno come riferimento (le sue cifre cambiano, la preposizione no).
    [$s, $anna, $bruno, $carla] = spnCaso();
    $corpo = spnCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'scrivi'], riferimento: $bruno);

    $a = ruAnteprima($this, $s, $corpo);
    $frasi = spnTutteLeFrasi($a);
    $motivi = implode(' | ', $a['cancello']['motivi']);
    $verde = spnMessaggioVerde($this, $s, $corpo);
    $effetti = spnEffettiDelloStorico($s);

    expect($frasi)->toContain('passano a Bruno')
        ->and($motivi)->toContain('passano a Bruno')
        ->and($verde['riassegnate_frase'])->toContain('sono passate a Bruno')
        ->and($effetti)->toContain('passate a Bruno');
    expect($frasi)->not->toContain('ad Bruno');
    expect($motivi)->not->toContain('ad Bruno');
});

it('R5, controllo: il legatario che non comincia per A resta con «a» — «passano a Leo» nel pannello e nel cancello', function () {
    // Leo, legatario unico: il nome non comincia per A.
    [$s, $corpo] = spnScenario('legato');

    $a = ruAnteprima($this, $s, $corpo);
    $frasi = spnTutteLeFrasi($a);
    $motivi = implode(' | ', $a['cancello']['motivi']);

    expect($frasi)->toContain('passano a Leo')
        ->toContain('debito € 5,48 a Leo')
        ->and($motivi)->toContain('passano a Leo');
    expect($frasi)->not->toContain('ad Leo');
    expect($motivi)->not->toContain('ad Leo');
});

// ---------------------------------------------------------------------------------------------------------------------
// R8 — la riga per gestione «a credito»
// ---------------------------------------------------------------------------------------------------------------------

it('R8: nella successione la riga per gestione con un importo negativo si scrive «€ 4,38 a credito», non «€ -4,38»', function () {
    // Rilievo R8 (decide-testi): decesso il 4 maggio, due eredi al 50 %, Anna riferimento. 242 giorni dal decesso: 79562 (€ 795,62); le
    // otto bozze (€ 800,00) coprono più dei giorni degli eredi: 79562 − 80000 = −438.
    [$s, $corpo] = spnScenario('quattro_maggio');

    $perGestione = ruAnteprima($this, $s, $corpo)['rate']['conguaglio']['per_gestione'];

    expect($perGestione)->toHaveCount(1)
        ->and($perGestione[0]['importo'])->toBe(-438)
        ->and($perGestione[0]['importo_formattato'])->toBe('€ 4,38 a credito');
});

it('R8, controllo: le coppie, i totali e le cifre di quella gestione restano quelli di oggi, e una riga positiva non prende nessun suffisso', function () {
    // Coppie: Anna 39781 − 80000 = −40219 (€ 402,19 a credito), Bruno 39781 (€ 397,81); somma −438. Il piede dice «€ 4,38».
    // La riga positiva di Fresco (80548 − 80000 = 548) resta «€ 5,48».
    [$s, $corpo] = spnScenario('quattro_maggio');

    $conguaglio = ruAnteprima($this, $s, $corpo)['rate']['conguaglio'];
    $coppie = collect($conguaglio['coppie'])->keyBy('entrante_nome');

    expect($coppie->pluck('importo', 'entrante_nome')->all())->toEqual(['Anna' => -40219, 'Bruno' => 39781])
        ->and($coppie['Anna']['importo_formattato'])->toBe('€ 402,19 a credito')
        ->and($coppie['Bruno']['importo_formattato'])->toBe('€ 397,81')
        ->and($conguaglio['per_gestione'][0]['importo_lordo'])->toBe(79562)
        ->and($conguaglio['per_gestione'][0]['bozze_passate_importo'])->toBe(80000)
        ->and($conguaglio['totale_entrante'])->toBe(-438)
        ->and($conguaglio['totale_entrante_assoluto_formattato'])->toBe('€ 4,38');

    [$f, $corpoFresco] = spnScenario('fresco');
    expect(ruAnteprima($this, $f, $corpoFresco)['rate']['conguaglio']['per_gestione'][0]['importo_formattato'])->toBe('€ 5,48');
});

// ---------------------------------------------------------------------------------------------------------------------
// R10 — il rifiuto che chiede il riferimento
// ---------------------------------------------------------------------------------------------------------------------

it('R10: nel legato a due il rifiuto che chiede il riferimento non dice «erede», perché i legatari non ereditano', function () {
    // Rilievo R10 (decide-testi): legato a Leo e Ada al 50 %, otto bozze su un piano già emesso e nessun riferimento. Oggi: «scegli l'erede di
    // riferimento … La parte di ogni erede si regola con il conguaglio, se lo scrivi.»
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $leo = spnPersona($s, 'Leo');
    $ada = spnPersona($s, 'Ada');

    $frase = spnRifiuto($this, $s, spnCorpoLegato($s, [[$leo, 50], [$ada, 50]]), 'erede_di_riferimento');

    expect($frase)->toContain('bozza');
    expect($frase)->not->toContain('erede');
});

it('R10, controllo: con l\'arretrato agli eredi il rifiuto che chiede il riferimento non dice «se lo scrivi»', function () {
    // Con l'arretrato agli eredi il conguaglio non si può non scrivere: «se lo scrivi» sarebbe falso (il server rifiuta «non_scrivere»).
    [$s, $anna, $bruno, $carla] = spnCaso();

    $frase = spnRifiuto($this, $s, spnCorpo($s, $anna, $bruno, $carla, ['erede_di_riferimento' => null], arretrato: 'eredi'), 'erede_di_riferimento');

    expect($frase)->toContain('bozza');
    expect($frase)->not->toContain('se lo scrivi');
});

// ---------------------------------------------------------------------------------------------------------------------
// R11 — il rifiuto di ricalcolare e la ragione del fermo, con un passaggio «non scritto»
// ---------------------------------------------------------------------------------------------------------------------

it('R11: con la successione «non scritta» il rifiuto di ricalcolare non dice «ha preso questo piano nel conguaglio»', function () {
    // Rilievo R11 (decide-testi): oggi «Un passaggio di titolarità ha preso questo piano nel conguaglio: successione di Venditore Ugo… Il
    // conguaglio della successione non è stato scritto (…)», la prima affermazione contraddice la seconda.
    [$s, $anna, $bruno, $carla] = spnCaso();
    spnRegistra($this, $s, spnCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']));

    $rifiuto = (string) $s['piano']->fresh()->fraseConguagliato('Ricalcolarlo');

    expect($rifiuto)->not->toContain('ha preso questo piano nel conguaglio');
});

it('R11, controllo: il rifiuto di ricalcolare nomina ancora il passaggio, dice che il conguaglio non è stato scritto e indica la strada', function () {
    // Cambia la prima affermazione, non il resto: il passaggio da annullare, la strada, la frase del conguaglio non scritto (P4). Come
    // comincia la frase (oggi «Un passaggio di titolarità ha preso questo piano…») non è fissato dal verbale.
    [$s, $anna, $bruno, $carla] = spnCaso();
    spnRegistra($this, $s, spnCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']));

    $rifiuto = (string) $s['piano']->fresh()->fraseConguagliato('Ricalcolarlo');

    expect($rifiuto)->toContain('successione di Venditore Ugo, Interno 1, dal 1 maggio 2026')
        ->toContain('annulla quel passaggio dallo storico della sua unità')
        ->toContain('Il conguaglio della successione non è stato scritto');
});

it('R11: con la successione «non scritta» la ragione del fermo non dice «preso nel conguaglio», né nella pagina del piano né nelle frasi', function (bool $passaggiAParte) {
    // Rilievo R11 (decide-testi): la pagina del piano elenca «è stato preso nel conguaglio di questo passaggio» (`passaggiAParte`), le altre
    // frasi «è stato preso nel conguaglio della successione di …». Con il conguaglio non scritto non c'è nessun conguaglio che l'abbia preso.
    [$s, $anna, $bruno, $carla] = spnCaso();
    spnRegistra($this, $s, spnCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']));

    $ragioni = $s['piano']->fresh()->ragioniInParole($passaggiAParte);

    expect($ragioni)->toHaveCount(2)
        ->and($ragioni[0])->toBe('ha già quote a giornale');
    expect(implode(' | ', $ragioni))->not->toContain('preso nel conguaglio');
})->with(['nella pagina del piano' => [true], 'nelle frasi' => [false]]);

it('R11, controllo: la ragione del fermo per la pagina del piano resta una per voce, e il passaggio da annullare resta elencato', function () {
    // `fermoPerLaPagina()`: le ragioni (due: le quote a giornale e il passaggio) e il passaggio in elenco.
    [$s, $anna, $bruno, $carla] = spnCaso();
    spnRegistra($this, $s, spnCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']));

    $fermo = $s['piano']->fresh()->fermoPerLaPagina();

    expect($fermo['ragioni'])->toHaveCount(2)
        ->and($fermo['ragioni'][0])->toBe('ha già quote a giornale')
        ->and($fermo['passaggi'])->toBe(['successione di Venditore Ugo, Interno 1, dal 1 maggio 2026']);
});

it('R11, controllo: con «scrivi» il rifiuto e la ragione dicono ancora che il passaggio ha preso il piano nel conguaglio', function () {
    // Il conguaglio scritto ha davvero preso il piano: le parole di oggi restano.
    [$s, $anna, $bruno, $carla] = spnCaso();
    spnRegistra($this, $s, spnCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'scrivi']));
    $piano = $s['piano']->fresh();

    expect((string) $piano->fraseConguagliato('Ricalcolarlo'))->toContain('Un passaggio di titolarità ha preso questo piano nel conguaglio')
        ->and($piano->ragioniInParole(true)[1])->toBe('è stato preso nel conguaglio di questo passaggio')
        ->and($piano->ragioniInParole()[1])->toBe('è stato preso nel conguaglio della successione di Venditore Ugo, Interno 1, dal 1 maggio 2026');
});

it('R11, controllo: la vendita con la rinuncia (la casella) ha le parole di oggi, perché il «non scritto» è della sola successione', function () {
    // La rinuncia della vendita è un accordo fra le parti (decisione 46): resta «ha preso questo piano nel conguaglio» e la ragione di oggi.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    spnRegistra($this, $s, spnCorpoVendita($s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito fra le parti']));
    $piano = $s['piano']->fresh();

    expect((string) $piano->fraseConguagliato('Ricalcolarlo'))->toContain('Un passaggio di titolarità ha preso questo piano nel conguaglio')
        ->and($piano->ragioniInParole(true)[1])->toBe('è stato preso nel conguaglio di questo passaggio');
});
