<?php

/**
 * 1.11.0-beta.50 — i test che prendono le mutazioni sopravvissute ai test della beta (giro di mutazioni a mano sulle correzioni).
 *
 * Ogni test dice nel commento quale mutazione prende e perché la beta non la vedeva. Le cifre sono quelle dello scenario di
 * `ScenariFineLocazione.php`: «Spese generali» € 1.200,00 sulla tabella «Proprietà», 30 % «Inquilino» (36000) e 70 % «Proprietario»
 * (84000); il «Preventivo 2026» in dodici rate mensili; Ines inquilina dal 2019; il 2026 ha 365 giorni. Ines esce il 1/7: 181 giorni
 * suoi, 184 dopo; 36000 × [181, 184] / 365 = 17852,05 / 18147,95 → la coppia 18148 (€ 181,48).
 *
 * I test della sezione «Difetti veri» sono nati come test rossi: ognuno descrive un difetto vero del codice della .50 trovato con
 * le mutazioni, corretto prima del commit. Il prospetto e la stampa sono in `ProspettoMutazioniBeta50Test.php`, la pagina del
 * modulo e lo storico in `PassaggioNewLocazione.test.ts` e `TitolaritaSheetLocazione.test.ts`.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';
require_once __DIR__.'/FermataDopoScriviHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Lo scenario con le sei rate gennaio–giugno a giornale: il piano non si ricalcola più. */
function mcb50ConSeiRate($test, array $titolari, array $opzioni = []): array
{
    $s = fclScenario($titolari, $opzioni);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);

    return $s;
}

/** Il modulo dell'inizio di una locazione di una persona dello scenario. */
function mcb50Inizio(array $s, string $chi, string $il = '2026-07-01', float|int $quota = 100): array
{
    return ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => $il, 'quota' => $quota,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []];
}

it('M08 — Ines esce il 31/12, l\'ultimo giorno della competenza: un giorno dopo l\'uscita c\'è, e la coppia (€ 0,99 a Ugo) si propone', function () {
    // `ChiPagaAlPostoDellInquilino::giorniDopo` scarta la competenza solo se finisce prima del giorno del passaggio (`gt`): il giorno
    // stesso è un giorno dopo l'uscita. Con `gte` la coppia di un giorno spariva senza dirlo. 36000 × [364, 1] / 365 = 35901,37 / 98,63
    // → parti intere 35901 + 98 = 35999, il centesimo al resto più grande (,63) → la coppia 99.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);

    $a = fclAnteprima($this, $s, fclModulo($s, null, '2026-12-31'));

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 99]])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeTrue();
});

it('M10, M113 — la frase «tornano a …» nomina prima chi pesa di più: Rita al 60 % e Ugo al 40 %, «tornano a Rita e Ugo»', function () {
    // `ChiPagaAlPostoDellInquilino::nomi` ordina per peso, il più grande prima (`arsort`). Le righe sono scritte Ugo prima di Rita: con
    // `asort`, o senza ordinamento, la frase diceva «a Ugo e Rita».
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 40], ['Rita', 'proprietario', 60]]);

    $frasi = implode(' ', fclAnteprima($this, $s, fclModulo($s))['obbligati']['frasi']);

    expect($frasi)->toContain('le voci a carico dell\'inquilino tornano a Rita e Ugo,');
});

it('M11 — una riga d\'inquilino di Dora con quota zero non è un coinquilino che resta: la coppia c\'è, e la scelta si chiede', function () {
    // `coinquiliniCheRestano` vuole una quota (`> 0`): una riga a zero non paga niente e non continua la locazione. Con `>= 0` la riga
    // vuota di Dora fermava il conguaglio («la locazione continua con Dora»).
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 100], ['Dora', 0]]]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    // Coda 245 (1.11.0-beta.55): la chiave della fermata del coinquilino non c'è più; con un coinquilino vero la coppia sarebbe della regola del
    // piano (`regola` = `piano`), qui è quella dei giorni.
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 18148]])
        ->and($a['rate'])->not->toHaveKey('fermata_coinquilino')
        ->and($a['rate']['conguaglio']['regola'])->toBe('giorni')
        ->and(fclFrasi($a))->not->toContain('la locazione continua con Dora');
});

it('M12, M90 — Dora è uscita il 1/7, il giorno stesso, prima di Ines: alla fine di Ines nessun coinquilino resta, e la coppia della parte di Ines va a Ugo, con la regola del piano della fine di Dora', function (string $scelta) {
    // Ines e Dora al 50 %. La fine di Dora (registrata prima) chiude la sua riga al 30/6: il 1/7 Dora non c'è più. Il coinquilino si
    // guarda il giorno del passaggio (`inCorsoIl($decorrenza)`); guardato il giorno prima, Dora c'era ancora, e la fine di Ines diceva
    // «la locazione continua con Dora».
    // Coda 245 (1.11.0-beta.55): la fine di Dora, con Ines che restava, è una fine con la regola del piano (la scelta c'è: Dora − 6066, Ines + 6066
    // con «Scrivi»), e la fine di Ines lo stesso giorno sta sul suo anello (decisione 95.6 b): la differenza del piano del passaggio. Prima Dora 11934
    // e Ines 24066; dopo Dora 8926, Ines 8926, Ugo 18148 (36000 × 90,5 / 365 = 8926,03 e 18147,95): la coppia di Ines è − 15140 a Ugo, e restano fuori
    // Dora − 3008 e Ugo + 3008 (C6.5a a parti scambiate). Le cifre non dipendono dalla scelta della fine di Dora. Prima della .55: Ugo + 9074, i giorni.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    $dora = fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-07-01', [], 'Dora') + ['conguaglio' => $scelta]);
    expect($dora->registro['conguaglio']['piano'] ?? [])->not->toBe([]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect($a['rate'])->not->toHaveKey('fermata_coinquilino')
        ->and(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 15140]])
        ->and(collect($a['rate']['resto_del_piano'][0]['parti'] ?? [])->pluck('cifra', 'nome')->all())->toBe(['Dora' => -3008, 'Ugo' => 3008])
        ->and(fclFrasi($a))->not->toContain('la locazione continua con Dora');
})->with(['dopo «Scrivi»' => ['scrivi'], 'dopo «Non scriverlo»' => ['non_scrivere']]);

it('M21 — con due persone al posto dell\'inquilino la frase del calcolo dice la divisione per persona: € 108,89 a Ugo e € 72,59 a Rita', function () {
    // La frase «si divide fra chi paga al posto dell'inquilino …» si scrive quando le parti sono più d'una (`count($parti) > 1`). Ugo
    // 60 e Rita 40: 18148 × 0,6 = 10888,8 e × 0,4 = 7259,2 → 10888 + 7259 = 18147, il centesimo al resto ,8 → Ugo 10889, Rita 7259.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 60], ['Rita', 'proprietario', 40]]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect(implode(' ', $a['rate']['frasi_del_conguaglio']))
        ->toContain('Sulla gestione Ordinaria 2026 il debito di € 181,48 si divide fra chi paga al posto dell\'inquilino nei giorni dopo l\'uscita, per quota e per giorni: € 108,89 a Ugo, € 72,59 a Rita.');
});

it('M23, M24, M31 — con il box di Rita la frase d\'apertura dice chi riceve unità per unità, con il nome dell\'unità: «Ugo nell\'unità «Interno 1» e Rita nell\'unità «Box 12»»', function () {
    // `chiRiceveInParole`: con persone diverse da un'unità all'altra la frase va unità per unità (con `<= 2` diceva solo «Ugo»), e
    // l'unità si chiama con il nome (`nomeDellUnita`), non con l'etichetta «Int. 1». Totale: 16333 + 1815 = 18148.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]], ['box' => [['Rita', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);

    $a = fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]));

    expect($a['rate']['frasi'][0])->toContain('passano a chi paga al posto dell\'inquilino, Ugo nell\'unità «Interno 1» e Rita nell\'unità «Box 12»:')
        ->and($a['rate']['frasi'][0])->toContain('i giorni dopo l\'uscita di Ines (€ 181,48)');
});

it('M31 — alla fine senza un nuovo inquilino la frase d\'apertura dice le due strade e chi riceve, non «chi entra come inquilino»', function () {
    // Il ramo `$scelta && $entrante === null` del blocco 2: senza, la frase diventava quella del cambio d'inquilino, con «chi entra».
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);

    $apertura = fclAnteprima($this, $s, fclModulo($s))['rate']['frasi'][0];

    expect($apertura)->toBe('Le rate già emesse non si toccano. Se scrivi il conguaglio, i giorni dopo l\'uscita di Ines (€ 181,48) passano a chi paga al posto dell\'inquilino, Ugo: le righe del conguaglio entrano nei saldi e sommano a zero, sulla gestione di ciascun piano, con la quota divisa in proporzione ai giorni. Se non lo scrivi, nei saldi non si scrive niente: i giorni dopo l\'uscita restano nelle rate di Ines, e le parti li regolano fra loro con il prospetto degli oneri accessori.');
});

it('M32 — con la scelta le frasi del calcolo non stanno fra i fatti del blocco «Rate», e le bozze «si conguagliano qui» solo «se scrivi il conguaglio»', function () {
    // `fattiDelConguaglio`: senza, la riga «credito … debito …» restava fra le frasi anche con «Non scriverlo», e «si conguagliano qui»
    // lo prometteva senza condizione.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);

    $a = fclAnteprima($this, $s, fclModulo($s));
    $fatti = implode(' ', $a['rate']['frasi']);

    expect($fatti)->not->toContain('credito € 181,48 a Ines')
        ->and($fatti)->toContain('resteranno intestate a Ines e, se scrivi il conguaglio, si conguagliano qui')
        ->and(implode(' ', $a['rate']['frasi_del_conguaglio']))->toContain('credito € 181,48 a Ines, debito € 181,48 a Ugo');
});

it('M34, M110 — nel blocco «Chi resta obbligato» le quote non emesse dicono che chi paga i giorni dopo l\'uscita lo decide la scelta sul conguaglio', function () {
    // `blocco3` passa `scelta_del_conguaglio` a `FrasiObbligati`: senza, la frase diceva solo «lo decide l'amministratore».
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);

    $obbligati = implode(' ', fclAnteprima($this, $s, fclModulo($s))['obbligati']['frasi']);

    expect($obbligati)->toContain('restano a Ines anche le 6 quote non ancora emesse: chi paga i giorni dopo l\'uscita lo decide l\'amministratore con la scelta sul conguaglio («Scrivi il conguaglio» li porta a chi paga al posto dell\'inquilino, «Non scriverlo» li lascia nelle rate di Ines).');
});

it('M26, M29, M33, M35, M40 — con Dora che resta: il cancello ha i motivi di sempre, con le 6 bozze comprese nel conguaglio, se lo scrivi, e nessun motivo del coinquilino; «Chi resta obbligato» dice la scelta con la regola del piano', function () {
    // Coda 245 (1.11.0-beta.55): fino alla .54 qui c'erano il motivo della fermata del coinquilino (€ 90,74 «restano nelle sue rate … non propone
    // il conguaglio»), le bozze «senza conguaglio: con un coinquilino che resta … lo decidono le parti» e la stessa frase in «Chi resta
    // obbligato». Con la regola del piano la scelta c'è: le bozze sono comprese nel conguaglio, se lo scrivi, e chi paga lo decide la scelta.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);

    $a = fclAnteprima($this, $s, fclModulo($s));
    $motivi = implode(' | ', $a['cancello']['motivi']);
    $obbligati = implode(' ', $a['obbligati']['frasi']);

    expect($motivi)->not->toContain('€ 90,74')
        ->and($motivi)->not->toContain('coinquilino')
        // Con la regola del piano non sono «i giorni dopo l'uscita» a passare a chi decide la scelta, ma la parte dell'inquilino divisa fra chi è stato conduttore: come «Chi resta obbligato».
        ->and($motivi)->toContain('restano sue e sono comprese nel conguaglio, se lo scrivi: chi paga la parte dell\'inquilino dopo l\'uscita lo decide l\'amministratore con la scelta sul conguaglio')
        ->and($motivi)->not->toContain('chi paga i giorni dopo l\'uscita')
        ->and(implode(' ', $a['rate']['frasi']))->not->toContain('Dalla prossima generazione')
        ->and($obbligati)->toContain('anche le 6 quote non ancora emesse: chi paga la parte dell\'inquilino dopo l\'uscita lo decide l\'amministratore con la scelta sul conguaglio («Scrivi il conguaglio» la ridivide con la regola del piano fra chi è stato conduttore, «Non scriverlo» la lascia nelle rate di Ines).')
        ->and($obbligati)->not->toContain('lo decidono le parti');
});

it('M26 (guardia) — senza un coinquilino che resta il cancello dice ancora «chi paga i giorni dopo l\'uscita»', function () {
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);

    $motivi = implode(' | ', fclAnteprima($this, $s, fclModulo($s))['cancello']['motivi']);

    expect($motivi)->toContain('restano sue e sono comprese nel conguaglio, se lo scrivi: chi paga i giorni dopo l\'uscita lo decide l\'amministratore con la scelta sul conguaglio')
        ->and($motivi)->not->toContain('la parte dell\'inquilino');
});

it('M30 — nel cambio d\'inquilino il cancello dice «comprese nel conguaglio, se lo scrivi» senza la coda della fine senza un nuovo inquilino', function () {
    // La coda «: chi paga i giorni dopo l'uscita lo decide l'amministratore con la scelta» vale solo senza un nuovo inquilino: nel
    // cambio d'inquilino i giorni dopo l'uscita vanno a chi entra.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    $luca = fclPersona($s, 'Luca');

    $motivi = implode(' | ', fclAnteprima($this, $s, fclModulo($s, $luca))['cancello']['motivi']);

    expect($motivi)->toContain('compres')
        ->and($motivi)->toContain('nel conguaglio, se lo scrivi')
        ->and($motivi)->not->toContain('con la scelta sul conguaglio');
});

// ---------------------------------------------------------------------------------------------------------------------
// Il cambio d'inquilino in due passi: come sono usciti (`comeSonoUsciti`, `dopoLUscitaDiPrima`)
// ---------------------------------------------------------------------------------------------------------------------

/** La frase del blocco «Rate» dell'inizio di Luca, dopo la fine (o le fini) già registrate. */
function mcb50FraseInizio($test, array &$s, string $il = '2026-07-01', float|int $quota = 100): string
{
    if (! isset($s['persone']['Luca'])) {
        fclPersona($s, 'Luca');
    }

    return implode(' ', fclAnteprima($test, $s, mcb50Inizio($s, 'Luca', $il, $quota))['rate']['frasi']);
}

it('M44 — dopo una fine «Scrivi» l\'inizio di Luca dice che i giorni li ha presi con il conguaglio Ugo, non anche Ines, che nella coppia ha la riga a credito', function () {
    // `comeSonoUsciti` legge chi ha preso la coppia dalle righe a debito (`saldo_iniziale > 0`); senza il filtro anche la riga a
    // credito di Ines finiva fra chi ha pagato («li ha presi con il conguaglio Ines e Ugo»).
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    expect(mcb50FraseInizio($this, $s))->toContain('resta nelle rate di Ines: i giorni dopo la sua uscita li ha presi con il conguaglio Ugo, e li rimborsa Luca a Ugo, con il prospetto degli oneri accessori.');
});

it('M53 — dopo «Annulla il conguaglio» l\'inizio di Luca dice che le parti hanno regolato fra loro i giorni dopo l\'uscita', function () {
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $fine]),
        ['nota_annullamento_conguaglio' => 'Ugo e Ines hanno regolato fra loro alla riconsegna delle chiavi'])->assertSessionHasNoErrors();
    expect($fine->fresh()->conguaglioAnnullato())->toBeTrue();

    expect(mcb50FraseInizio($this, $s))->toContain('resta nelle rate di Ines: il conguaglio dei giorni dopo la sua uscita le parti lo hanno regolato fra loro.');
});

it('M41 — una fine di prima della .50 con la casella «le parti hanno regolato» (nessuna scelta nel registro, la nota): l\'inizio di Luca dice che le parti hanno regolato, non che Luca rimborsa Ines', function () {
    // Il ramo `$scelta === null && conguaglioRinunciato()` di `comeSonoUsciti` (decisioni 43 e 46): senza, la fine di prima della .50
    // con la rinuncia diventava «non scritto», e la frase prometteva un rimborso con il prospetto. La fine si simula come
    // `pciComePrimaDellaCinquanta` di `ProspettoCambioInquilinoTest`: niente chiave `conguaglio` nel registro, la nota sul padre.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);
    $registro = $fine->registro;
    unset($registro['conguaglio']);
    DB::table('subentri')->where('id', $fine->id)->update(['registro' => json_encode($registro), 'nota_conguaglio' => 'Le parti hanno regolato fra loro alla riconsegna']);

    $frase = mcb50FraseInizio($this, $s);

    expect($frase)->toContain('resta nelle rate di Ines: il conguaglio dei giorni dopo la sua uscita le parti lo hanno regolato fra loro.')
        ->and($frase)->not->toContain('li rimborsa Luca a Ines');
});

it('M52 — Ines è uscita mentre Dora restava, con la regola del piano: l\'inizio di Luca dice le cifre della regola del piano e chi rimborsa chi, non che lo decidono le parti', function () {
    // Coda 245 (1.11.0-beta.55): fino alla .54 la fine di Ines con Dora che restava lasciava i giorni dopo l'uscita nelle rate di Ines, e l'inizio di
    // Luca diceva «resta nelle rate di Ines: chi paga i giorni dopo la sua uscita lo decidono le parti». Ora la fine di Ines ha la regola del piano
    // (con «Scrivi»: Ines 11934, Dora 24066), e l'inizio di Luca dal 1/7 al 50 % è un'informazione con le sue cifre (il cambio in due passi): Luca
    // 50 × 184 = 9200, Ines 9050, Dora 18250 (somma 36500) danno 9073,97, 8926,03 e 18000 → Luca 9074, Ines 8926, Dora 18000: Luca + 9074, Dora − 6066,
    // Ines − 3008.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    $frase = mcb50FraseInizio($this, $s, '2026-07-01', 50);
    expect($frase)->toContain('Con Luca dal 1 luglio 2026 al 50 % la regola del piano darebbe Luca € 90,74, Dora € 180,00 e Ines € 89,26: il programma non scrive niente, e Luca rimborsa con il prospetto degli oneri accessori € 60,66 a Dora e € 30,08 a Ines.')
        ->and($frase)->not->toContain('lo decidono le parti');
});

it('M43 — Dora tolta a mano e Ines uscita con «Non scriverlo»: due modi diversi, e l\'inizio di Luca dice che vale la scelta della fine di ciascuno', function () {
    // `comeSonoUsciti` dà «misto» quando i modi sono più d'uno; preso il primo («a mano», la riga di Dora chiusa senza un passaggio), la
    // frase diceva «lo decide l'amministratore» anche per Ines, che ha scelto «Non scriverlo».
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Dora'))->update(['data_fine' => '2026-06-30']);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);

    expect(mcb50FraseInizio($this, $s))->toContain('resta nelle rate di Dora e Ines: per i giorni dopo la loro uscita vale la scelta sul conguaglio della fine di ciascuno, e il prospetto degli oneri accessori dice chi rimborsa chi.');
});

it('M47 — la riga di Ines chiusa a mano, senza un passaggio: il cancello dell\'inizio di Luca indica il Wallet, con i saldi legati a questo passaggio, che resta la strada solo per questo caso', function () {
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Ines'))->update(['data_fine' => '2026-06-30']);
    fclPersona($s, 'Luca');

    $informazioni = implode(' | ', fclAnteprima($this, $s, mcb50Inizio($s, 'Luca'))['cancello']['informazioni']);

    expect($informazioni)->toContain('resta nelle rate di Ines: chi paga i giorni dopo la sua uscita lo decide l\'amministratore — se serve, dal Wallet con saldi legati a questo passaggio, sulla stessa gestione');
});

it('M46, M54 — un piano della 1.10, senza riparto: dopo la fine di Ines con «Non scriverlo» l\'inizio di Luca dice che il rimborso a Ines si regola fra le parti, perché il prospetto quel piano lo lascia fuori', function () {
    // Il ramo dei piani senza riparto di `comeRestanoIPianiFermi` chiama anche lui `comeSonoUsciti` (senza, «lo decide
    // l'amministratore»), e `dopoLUscitaDiPrima` senza il prospetto dice «fra le parti» (sempre «con il prospetto», prometteva un
    // documento che il piano della 1.10 non ha).
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();

    expect(mcb50FraseInizio($this, $s))->toContain('resta nelle rate di Ines: i giorni dopo la sua uscita li rimborsa Luca a Ines, fra le parti.');
});

it('M48 — Ines rientra dopo una fine «Scrivi» con Ugo e Rita al posto dell\'inquilino: «l\'hanno presa Ugo e Rita …, e Ines la rimborsa», al plurale', function () {
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 50], ['Rita', 'proprietario', 50]]);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    $frase = implode(' ', fclAnteprima($this, $s, mcb50Inizio($s, 'Ines', '2026-10-01'))['rate']['frasi']);

    expect($frase)->toContain('La parte a carico dell\'inquilino dal 1 ottobre 2026 l\'hanno presa Ugo e Rita con il conguaglio scritto alla fine locazione di Ines del 1 luglio 2026, e Ines la rimborsa con il prospetto degli oneri accessori.');
});

// ---------------------------------------------------------------------------------------------------------------------
// La coppia della fine: la divisione per persona e per giorno, i resti, le coppie senza nessuno
// ---------------------------------------------------------------------------------------------------------------------

it('M22 — il box senza nessuno al posto dell\'inquilino dopo l\'uscita: la sua coppia non si scrive, e il totale proposto è solo quello dell\'Interno 1 (€ 163,33)', function () {
    // `conguaglioDellaFine` rifà il totale sulle coppie che restano: senza, `totale_entrante` contava anche la coppia del box che non
    // si scrive (16333 + 1815 = 18148) e la frase d'apertura diceva € 181,48. Rita, sola sul box, lo lascia il 30/6 (riga chiusa a
    // mano dopo l'emissione): dal 1/7 sul box non c'è nessuno al posto dell'inquilino.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]], ['box' => [['Rita', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Rita', 'proprietario', $s['box']))->update(['data_fine' => '2026-06-30']);

    $a = fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]));

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 16333]])
        ->and($a['rate']['conguaglio']['totale_entrante'])->toBe(16333)
        ->and($a['rate']['frasi'][0])->toContain('i giorni dopo l\'uscita di Ines (€ 163,33)');
});

it('M07 — una persona con una parte di zero centesimi non ha una coppia: la vendita a Elsa del 31/12 le dà un giorno su 184 di € 0,15', function () {
    // `ChiPagaAlPostoDellInquilino::dividi` non tiene le parti a zero: senza, la coppia aveva una riga di € 0,00 per Elsa. «Spese
    // generali» € 1,00: 30 centesimi all'inquilino, 30 × [181, 184] / 365 = 14,88 / 15,12 → Ines 15, la coppia 15. Dopo l'uscita Ugo
    // 183 giorni ed Elsa 1: 15 × 183/184 = 14,92 e 15 × 1/184 = 0,08 → 14 + 0, il centesimo al resto più grande (,92) → Ugo 15, Elsa 0.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]], ['importo' => 100]);
    $elsa = fclPersona($s, 'Elsa');
    fclRegistra($this, $s, ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fclRiga($s, 'Ugo', 'proprietario'), 'anagrafica_entrante_id' => $elsa->id,
        'decorrenza' => '2026-12-31', 'quota' => 100, 'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Verdi, rep. 901', 'pertinenze' => []]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 15]]);
});

/**
 * Lo scenario dei due piani: «Preventivo 2026» con il solo capitolo «Spese generali» (tutto l'anno) e «Pulizie 2026» (gestione e
 * tabella dello stesso scenario) con il capitolo «Pulizie» € 600,00 a competenza 1/1–30/9; tutti e due generati con Ines e a
 * giornale fino al 31/8; la vendita di Ugo a Elsa il 1/9, registrata.
 */
function mcb50DuePiani($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    $pulizie = mcb50Capitolo($s, 'Pulizie', 60000);
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->whereIn('piano_conto_id', \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->pluck('id'))->firstOrFail();
    $s['piano']->capitoli()->attach([$spese->id => ['importo' => 120000]]);
    $secondo = \App\Models\Gestionale\PianoRate::create([
        'gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Pulizie 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false,
    ]);
    $secondo->capitoli()->attach([$pulizie->id => ['importo' => 60000]]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $secondo->id)->where('conto_id', $pulizie->id)->value('id');
    \App\Models\Gestionale\CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-01-01', 'al' => '2026-09-30', 'ordine' => 0]);
    fclGenera($s);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($secondo->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione delle pulizie', esercizio: $s['e']);
    fclEmetti($test, $s, '2026-08-31');
    mcb50EmettiPiano($test, $s, $secondo, '2026-08-31');
    $s['secondo'] = $secondo;
    $elsa = fclPersona($s, 'Elsa');
    fclRegistra($test, $s, ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fclRiga($s, 'Ugo', 'proprietario'), 'anagrafica_entrante_id' => $elsa->id,
        'decorrenza' => '2026-09-01', 'quota' => 100, 'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Verdi, rep. 900', 'pertinenze' => []]);

    return $s;
}

/** Un secondo conto della gestione dello scenario, sulla tabella «Proprietà», 30 % «Inquilino» e 70 % «Proprietario». */
function mcb50Capitolo(array $s, string $nome, int $importo): \App\Models\Gestionale\Conto
{
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $conto = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => $nome, 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => $importo]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $conto;
}

/** L'emissione dalla rotta della pagina del piano, per un piano dato. */
function mcb50EmettiPiano($test, array $s, \App\Models\Gestionale\PianoRate $piano, string $fino): void
{
    $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), ['rate_ids' => $rate, 'data_emissione' => $fino, 'invia_notifiche' => false]);
}

it('Punto 1 (variante a) — un piano con un capitolo a competenza 1/1–30/9: la coppia della fine divide la parte del capitolo sui suoi giorni dopo l\'uscita, fino al 30/9, e dà a Ugo € 102,03 e a Elsa € 140,11, come il piano generato dopo', function () {
    // Difetto vero trovato con le mutazioni M04/M05/M20 (`pesiDeiPezzi` non era mai chiamato con più pezzi). La chiusura che dava le
    // parti per persona (`conguaglioDellaFine`, `$chiRiceve`) prendeva il periodo del gruppo per periodo di `ConguaglioPassaggio`: con i
    // capitoli (`per_capitolo`) il gruppo era uno per piano e il suo periodo era quello del piano intero (1/1–31/12), non quello del
    // capitolo. Così le «Pulizie» dei giorni dopo l'uscita, che finiscono il 30/9, si dividevano fra Ugo ed Elsa sui 184 giorni fino al
    // 31/12. Lo stesso piano con i due capitoli: «Spese generali» € 1.200,00 tutto l'anno e «Pulizie» € 600,00 a competenza 1/1–30/9; la
    // vendita di Ugo a Elsa il 1/9, registrata prima della fine. La regola dichiarata (per persona e per giorno, «uguali al piano
    // generato dopo, tolleranza un centesimo per unità»): Spese 18148 (Ugo 62 giorni, Elsa 122) e Pulizie 6066 sui 92 giorni fino al 30/9
    // (Ugo 62, Elsa 30) → Ugo 10203, Elsa 14011, come nel test dei due piani qui sopra. Prima della correzione: 24214 su 184 giorni → Ugo
    // 62/184 = 8158,8 → 8159, Elsa 16055.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    $pulizie = mcb50Capitolo($s, 'Pulizie', 60000);
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->whereIn('piano_conto_id', \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->pluck('id'))->firstOrFail();
    $s['piano']->capitoli()->attach([$spese->id => ['importo' => 120000], $pulizie->id => ['importo' => 60000]]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $pulizie->id)->value('id');
    \App\Models\Gestionale\CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => '2026-01-01', 'al' => '2026-09-30', 'ordine' => 0]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-08-31');
    $elsa = fclPersona($s, 'Elsa');
    fclRegistra($this, $s, ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fclRiga($s, 'Ugo', 'proprietario'), 'anagrafica_entrante_id' => $elsa->id,
        'decorrenza' => '2026-09-01', 'quota' => 100, 'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Verdi, rep. 900', 'pertinenze' => []]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    // Il piano generato dopo i due passaggi (stesso scenario, vendita e fine registrate prima della generazione) dà Elsa 46703 e
    // Ugo 103511; con la vendita (Elsa 32692, Ugo 93308) e questa coppia: Elsa 32692 + 14011 = 46703, Ugo 93308 + 10203 = 103511.
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Elsa', 14011], [(int) $s['unita']->id, 'Ugo', 10203]]);
});

it('Punto 1 (variante b) — lo stesso con due piani della stessa gestione, uno per capitolo: la coppia divide le «Pulizie» sui giorni del capitolo, non su quelli del piano, e le cifre sono quelle della variante a', function () {
    // È anche la ragione per cui le mutazioni M04, M05 e M20 su `pesiDeiPezzi` sopravvivevano: i pezzi arrivano tutti con lo stesso
    // periodo (1/1–31/12, quello del piano), e pesarli con il loro importo, con il primo solo o con importo 1 dà la stessa divisione.
    // Le cifre attese sono quelle della variante a: Ugo 10203, Elsa 14011. Prima della correzione: Ugo 8159, Elsa 16055.
    $s = mcb50DuePiani($this);

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Elsa', 14011], [(int) $s['unita']->id, 'Ugo', 10203]]);
});

// ---------------------------------------------------------------------------------------------------------------------
// La fermata dopo «Scrivi»: il pari resto, chi riceve, i casi esclusi, l'inizio dopo una fermata
// ---------------------------------------------------------------------------------------------------------------------

/** Lo scenario con la fine di Ines «Scrivi» il 1/7, registrata, e le rate emesse fino al giorno dato. */
function mcb50DopoScrivi($test, array $titolari = [['Ugo', 'proprietario', 100]], array $opzioni = [], string $emessoFino = '2026-09-30'): array
{
    $s = mcb50ConSeiRate($test, $titolari, $opzioni);
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($test, $s, $emessoFino);

    return $s;
}

it('M37, M80 — la successione dopo una fine «Scrivi», prima della scelta sul suo conguaglio: la fermata si dice «se scrivi il conguaglio della successione»', function () {
    // Con l'arretrato al defunto e la scelta non ancora fatta la fermata vale solo con «Scrivi» (`$seScrivi`): senza, il pannello la
    // dava per certa anche se l'amministratore sceglie poi «Non scriverlo», che non ferma niente. 18148 × 92/184 = 9074.
    $s = mcb50DopoScrivi($this);
    fclPersona($s, 'Anna');
    fclPersona($s, 'Bruno');

    $a = fclAnteprima($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, null, '2026-10-01'));

    expect(implode(' ', $a['rate']['frasi']))->toContain('Se scrivi il conguaglio della successione: la fine locazione di Ines del 1 luglio 2026 ha scritto il conguaglio')
        ->and(collect($a['cancello']['motivi'])->contains(fn (string $m) => str_starts_with($m, 'se scrivi il conguaglio, € 90,74 dei giorni dopo l\'uscita di Ines restano a carico di Ugo')))->toBeTrue(implode(' | ', $a['cancello']['motivi']));
});

it('M06, M59 — la fermata a pari resto: il centesimo resta a chi esce (verbale della .50), anche quando chi riceve viene prima nell\'ordine dei pesi', function () {
    // «Spese generali» € 1.201,00: 30 % = 36030, 36030 × [181, 184] / 365 = 17866,93 / 18163,07 → 17866 + 18163 = 36029, il centesimo
    // al resto ,93 → Ines 17867, la coppia 18163, dispari. La costituzione a Elsa il 1/10: Elsa usufruttuaria 92 giorni (l'anello
    // dell'usufruttuario viene prima, e prima nei pesi), Ugo proprietario 92: 18163 / 2 = 9081,5 a testa. A pari resto il centesimo a
    // Ugo, che esce (`dividi` con `$primo`): la fermata è la parte di Elsa, 9081 (€ 90,81). Senza `$primo` andava a Elsa: 9082.
    $s = mcb50DopoScrivi($this, [['Ugo', 'proprietario', 100]], ['importo' => 120100]);
    expect(fclSaldi($s))->toBe(['Ines' => -18163, 'Ugo' => 18163]);
    fclPersona($s, 'Elsa');

    $a = fclAnteprima($this, $s, fds50ModuloCostituzione($s, 'Ugo', 'Elsa', '2026-10-01'));

    expect(collect($a['rate']['fermate_dopo_scrivi'])->sum('cifra'))->toBe(9081)
        ->and($a['rate']['fermate_dopo_scrivi'][0]['verso'])->toBe(['Elsa']);
});

it('M60 — Ugo e Zoe al 50 %, Ugo vende a Elsa il 10/7: Zoe, che non cambia peso, non riceve niente dalla fermata anche se l\'arrotondamento le dà un centesimo in più', function () {
    // Chi riceve deve avere più peso dopo il passaggio (`$peso > $pesiPrima + 1e-9`), non solo un centesimo in più. La coppia 18163
    // (€ 1.201,00, vedi sopra) divisa alla fine fra Ugo e Zoe: 9081,5 a testa, il centesimo a Ugo (il primo) → Ugo 9082, Zoe 9081. Dopo la
    // vendita, sui 184 giorni: Ugo 9 (1/7–9/7), Zoe 184, Elsa 175, a quota 50: 18163 × [4,5; 92; 87,5] / 184 = 444,20 / 9081,5 /
    // 8637,30 → 444 + 9081 + 8637 = 18162, il centesimo al resto più grande, Zoe (,5) → Zoe 9082. La fermata è la parte di Elsa, 8637
    // (€ 86,37); con Zoe fra chi riceve sarebbe 8638, verso Zoe ed Elsa.
    $s = mcb50DopoScrivi($this, [['Ugo', 'proprietario', 50], ['Zoe', 'proprietario', 50]], ['importo' => 120100], '2026-06-30');
    expect(fclSaldi($s))->toBe(['Ines' => -18163, 'Ugo' => 9082, 'Zoe' => 9081]);
    fclPersona($s, 'Elsa');

    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-07-10', 50));

    expect(collect($a['rate']['fermate_dopo_scrivi'])->sum('cifra'))->toBe(8637)
        ->and($a['rate']['fermate_dopo_scrivi'][0]['verso'])->toBe(['Elsa']);
});

it('M69 — Ines, uscita con «Scrivi», compra l\'unità da Ugo (la vendita si ferma verso di lei, € 120,33) e la vende a Elsa il 1/11: la seconda vendita dice la parte di Elsa della fermata, € 60,16, come per una seconda vendita qualunque (decisione 82.1)', function () {
    // Fino alla decisione 82.1 questo test voleva nessuna fermata: Ines ha nella coppia della sua fine la riga a credito (−18148), e la
    // seconda vendita la lasciava fuori. La 82.1: la 81.2 vale anche per chi ha una riga nella coppia, altrimenti la parte di Elsa
    // resterebbe a Ines in silenzio. Resta la ragione della mutazione M69: la riga a credito di Ines non fa di lei chi ha preso la coppia
    // (`coppieScritte` cerca le righe a debito, `saldo_iniziale > 0`), e la cifra resta a carico di chi ha le righe a debito.
    // Le cifre, sulla coppia 18148 dei 184 giorni dopo l'uscita (1/7–31/12). Prima vendita, Ugo → Ines il 1/9: Ugo 62 giorni, Ines 122 →
    // 6115,09 / 12032,91 → Ugo 6115, Ines 12033 (€ 120,33). Seconda vendita, Ines → Elsa il 1/11: Ugo 62, Ines 61 (1/9–31/10), Elsa 61 →
    // 6115,09 / 6016,46 / 6016,46 → 6115 + 6016 + 6016 = 18147, il centesimo a pari resto (,46) a chi esce, Ines → Ugo 6115, Ines 6017,
    // Elsa 6016: la parte di Elsa, € 60,16. A carico resta Ugo, che ha la riga a debito, come nella 81.2: non Ines, che nella coppia ha
    // solo la riga a credito.
    $s = mcb50DopoScrivi($this, [['Ugo', 'proprietario', 100]], [], '2026-08-31');
    $prima = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Ines', '2026-09-01'));
    fclEmetti($this, $s, '2026-10-31');
    fclPersona($s, 'Elsa');

    // Controllo: la prima vendita si è fermata, € 120,33 verso Ines.
    expect(collect($prima['anteprima']['rate']['fermate_dopo_scrivi'])->sum('cifra'))->toBe(12033)
        ->and(collect($prima['anteprima']['rate']['fermate_dopo_scrivi'])->pluck('verso')->flatten()->all())->toBe(['Ines']);

    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Ines', 'Elsa', '2026-11-01'));
    $frasi = collect($a['rate']['frasi']);
    $motivi = collect($a['cancello']['motivi']);

    expect(collect($a['rate']['fermate_dopo_scrivi'])->sum('cifra'))->toBe(6016)
        ->and(collect($a['rate']['fermate_dopo_scrivi'])->pluck('verso')->flatten()->all())->toBe(['Elsa'])
        ->and(collect($a['rate']['fermate_dopo_scrivi'])->pluck('persona')->all())->toBe(['Ugo'])
        ->and($frasi->contains(fn (string $f) => str_contains($f, '€ 60,16') && str_contains($f, 'Elsa')))->toBeTrue('Il pannello non dice € 60,16 verso Elsa: ' . $frasi->implode(' | '))
        ->and($motivi->contains(fn (string $m) => str_contains($m, '€ 60,16')))->toBeTrue('Nessun motivo del cancello dice € 60,16: ' . $motivi->implode(' | '));
});

it('M65 — Ines rientra dopo una fine «Scrivi» che non è più l\'ultimo passaggio (dopo c\'è la vendita): il pannello non propone di annullare la fine', function () {
    // L'alternativa del rinnovo si dice solo se la fine si può annullare adesso (`annullabile`, decisione 27: dall'ultimo passaggio).
    $s = mcb50DopoScrivi($this);
    fclPersona($s, 'Elsa');
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));

    $a = fclAnteprima($this, $s, mcb50Inizio($s, 'Ines', '2026-11-01'));

    expect($a['rate']['rinnovo_dopo_scrivi'])->not->toBe([])
        ->and($a['rate']['rinnovo_dopo_scrivi'][0]['annullabile'])->toBeFalse()
        ->and(implode(' ', $a['rate']['frasi']))->not->toContain('puoi invece annullare');
});

it('M66, M79 — dopo la fermata della vendita, Luca e poi Marco: l\'inizio di Marco conta quanto resta senza i giorni di Luca, già tolti dal suo inizio', function () {
    // `fermateRidotte` parte dalle righe senza i conduttori entrati dopo la fermata (`$entratiDopo`). La fermata: 18148 × 92/184 = 9074
    // a Elsa. Luca dal 1/11 al 30/11, Marco dal 1/12: a Elsa resta solo ottobre. Le cifre le dice la regola della coppia con i
    // conduttori: prima di Marco, con Luca a novembre, 18148 × [92 Ugo; 30 Luca; 62 Elsa] / 184 = 9074 / 2958,91 / 6115,09 → il
    // centesimo a Luca (,91) → Elsa 6115 (€ 61,15); con Marco a dicembre, [92; 30; 31 Marco; 31 Elsa] → 9074 / 2958,91 / 3057,54 /
    // 3057,54, i due centesimi a Luca (,91) e, a pari resto, a Marco, che viene prima → Elsa 3057 (€ 30,57). Contati anche i giorni
    // di Luca dentro la base, la fermata «prima di Marco» sarebbe ancora € 90,74 e il resto € 60,16.
    $s = mcb50DopoScrivi($this);
    fclPersona($s, 'Elsa');
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));
    fclPersona($s, 'Luca');
    fds50Registra($this, $s, mcb50Inizio($s, 'Luca', '2026-11-01'));
    fds50Registra($this, $s, fclModulo($s, null, '2026-12-01', [], 'Luca'));
    fclPersona($s, 'Marco');

    $a = fclAnteprima($this, $s, mcb50Inizio($s, 'Marco', '2026-12-01'));
    $ridotte = $a['rate']['fermate_ridotte'];

    expect($ridotte)->toHaveCount(1)
        ->and([$ridotte[0]['cifra'], $ridotte[0]['prima'], $ridotte[0]['residuo']])->toBe([9074, 6115, 3057])
        // Coda 351 (1.11.0-beta.55): la cifra di prima cambia anche con una ricompera, non solo con una locazione: la parentesi dice «i passaggi
        // registrati da allora» (prima «le locazioni»). Le cifre non cambiano.
        ->and(implode(' ', $a['rate']['frasi']))->toContain('€ 90,74 dei giorni dopo l\'uscita di Ines, da regolare a mano con Elsa (con i passaggi registrati da allora, € 61,15)')
        ->and(implode(' ', $a['rate']['frasi']))->toContain('resta da regolare € 30,57 con Elsa');
});

it('M67 — una locazione che comincia dopo i giorni della coppia (1/1/2027) non cambia la fermata: nessuna frase e nessun motivo dopo la fermata', function () {
    $s = mcb50DopoScrivi($this);
    fclPersona($s, 'Elsa');
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));
    fclPersona($s, 'Luca');

    // `fermateRidotte` salta la fermata quando il resto non cambia (`$residuo === $primaDiLui`): senza, l'inizio diceva «restano da
    // regolare € 90,74, non più € 90,74» e chiedeva la spunta per niente.
    $a = fclAnteprima($this, $s, mcb50Inizio($s, 'Luca', '2027-01-01'));

    expect($a['rate']['fermate_ridotte'])->toBe([])
        ->and(implode(' | ', $a['cancello']['motivi']))->not->toContain('della fermata');
});

it('M73 — Ines rientra al 60 % il 1/10 accanto a Dora al 40 %: la parte che rimborsa è la sua quota dei giorni, € 54,44, non metà', function () {
    // Nei pesi della fermata i conduttori pesano quota × giorni (`FermataDopoScrivi::pesi`), come le righe al posto dell'inquilino. Dal
    // 1/10 al 31/12 (92 giorni) la coppia vale 18148 × 92/184 = 9074; Ines al 60 % e Dora al 40 % → Ines 5444,4 → 5444 (€ 54,44). Con i
    // soli giorni le due conduttrici pesavano uguale: 4537.
    $s = mcb50DopoScrivi($this);
    fclPersona($s, 'Dora');
    fds50Registra($this, $s, mcb50Inizio($s, 'Dora', '2026-10-01', 40));

    $a = fclAnteprima($this, $s, mcb50Inizio($s, 'Ines', '2026-10-01', 60));

    expect(collect($a['rate']['rinnovo_dopo_scrivi'])->sum('cifra'))->toBe(5444);
});

it('M78 — la fermata della successione verso Anna e Bruno: la strada del Wallet elenca le righe con la virgola, «€ 90,74 a credito di Ugo, € 45,37 a debito di Anna e € 45,37 a debito di Bruno»', function () {
    $s = mcb50DopoScrivi($this);
    fclPersona($s, 'Anna');
    fclPersona($s, 'Bruno');

    $a = fclAnteprima($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, Subentro::SCRIVI_IL_CONGUAGLIO, '2026-10-01'));

    expect(implode(' ', $a['rate']['frasi']))->toContain('registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: € 90,74 a credito di Ugo, € 45,37 a debito di Anna e € 45,37 a debito di Bruno.');
});

it('M49 — Ines rientra il 1/10 con due piani fermi: sul preventivo la parte l\'ha presa Ugo con la coppia della fine, sul riscaldamento (fermo dopo la fine, senza coppia) è già nelle rate di Ines', function () {
    // `rientroSullUnita` prende le voci del rientro della gestione del piano: senza il filtro sulla gestione anche il piano del
    // riscaldamento, che la coppia della fine non ha preso, diceva «l'ha presa Ugo con il conguaglio scritto».
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    $riscaldamento = mcb50SecondaGestione($s);
    fclGenera($s);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($riscaldamento->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione del riscaldamento', esercizio: $s['e']);
    fclEmetti($this, $s, '2026-06-30');
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    mcb50EmettiPiano($this, $s, $riscaldamento, '2026-09-30');
    expect(DB::table('rate')->where('piano_rate_id', $riscaldamento->id)->where('stato', 'emessa')->count())->toBe(1);

    $frasi = fclPeriodi(implode(' ', fclAnteprima($this, $s, mcb50Inizio($s, 'Ines', '2026-10-01'))['rate']['frasi']));
    $dopo = fn (string $piano) => implode(' ', array_slice($frasi, (int) array_key_first(array_filter($frasi, fn ($p) => str_contains($p, "«{$piano}»"))), 3));

    expect($dopo('Preventivo 2026'))->toContain('l\'ha presa Ugo con il conguaglio scritto alla fine locazione di Ines')
        ->and($dopo('Rate del riscaldamento 2026'))->toContain('è già nelle rate di Ines')
        ->and($dopo('Rate del riscaldamento 2026'))->not->toContain('l\'ha presa Ugo');
});

/** La gestione «Riscaldamento 2026» con «Combustibile» € 730,00 tutto all'inquilino, e il suo piano in quattro rate dal 5/9, approvato. */
function mcb50SecondaGestione(array $s): \App\Models\Gestionale\PianoRate
{
    $g = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $g->id);
    $pc = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'PC riscaldamento']);
    $conto = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Combustibile', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 73000]);
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'inquilino', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);

    return \App\Models\Gestionale\PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Rate del riscaldamento 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 4, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-09-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false,
    ]);
}

// ---------------------------------------------------------------------------------------------------------------------
// Difetti veri, corretti: il pannello dell'inizio e il prospetto dicevano due cose diverse
// ---------------------------------------------------------------------------------------------------------------------

it('Punto 3 — Dora esce il 1/7 mentre Ines (al tempo) resta, poi Ines esce lo stesso giorno: l\'inizio di Luca dice la regola del piano, come il prospetto, e non promette che Luca rimborsa anche Dora', function () {
    // Trovato con la mutazione M12 (e M43, M52): l'inizio di Luca e il prospetto devono leggere la fine di Dora allo stesso modo. Coda 245 (1.11.0-beta.55): la fine
    // di Dora, con Ines che resta, ha la scelta e la regola del piano (qui «Scrivi»: Dora 50 × 181 = 9050, Ines 50 × 365 = 18250, 36000 × 9050/27300 = 11934,07 →
    // Dora − 6066, Ines + 6066); la fine di Ines lo stesso giorno, con «Non scriverlo», sta sul suo anello: prima Dora 11934 e Ines 24066, dopo Dora 8926, Ines 8926 e
    // Ugo, al posto dell'inquilino dal 1/7, 18148: la coppia non scritta Ines − 15140 → Ugo, e fuori Dora − 3008 e Ugo + 3008. Luca entra il 1/7 al 100 %: tutti i
    // giorni sono coperti, Dora 9050, Ines 9050, Luca 18400 (somma 36500) → 8926,03, 8926,03, 18147,95 → Luca 18148, e Ugo non ha più parte: Luca + 18148, Ugo
    // − 18148. Il pannello lo dice («Luca rimborsa … € 181,48 a Ugo») e il prospetto lo stesso: Luca rimborsa a Ugo 18148, e Ugo restituisce a Ines 15140 e a Dora
    // 3008, la differenza che la fine di Ines ha lasciato fra le parti. Prima (decisione 79) Luca rimborsava 9074 a Ines, e Dora aveva la strada C.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    fclRegistra($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-07-01', [], 'Dora') + ['conguaglio' => 'scrivi']);
    fclRegistra($this, $s, ['quota' => 50] + fclModulo($s) + ['conguaglio' => 'non_scrivere']);

    $frase = mcb50FraseInizio($this, $s);
    fclRegistra($this, $s, mcb50Inizio($s, 'Luca'));
    $prospetto = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $sezione = fn (string $nome) => collect($prospetto['conduttori'])->firstWhere('nome', $nome);
    $rimborsi = fn (?array $x) => collect($x['regola_del_piano']['rimborsi'] ?? [])->map(fn ($r) => [$r['verso'], $r['nome'], (int) $r['importo']])->all();

    expect([(int) $sezione('Luca')['totale'], (int) $sezione('Luca')['da_rimborsare']])->toBe([18148, 18148])
        ->and($rimborsi($sezione('Luca')))->toBe([['a', 'Ugo', 18148]])
        ->and([(int) $sezione('Dora')['totale'], (int) $sezione('Dora')['nelle_sue_rate'], (int) $sezione('Dora')['con_il_conguaglio'], (int) $sezione('Dora')['da_rimborsare']])->toBe([8926, 18000, -6066, -3008])
        ->and($rimborsi($sezione('Dora')))->toBe([['da', 'Ugo', 3008]])
        ->and([(int) $sezione('Ines')['totale'], (int) $sezione('Ines')['nelle_sue_rate'], (int) $sezione('Ines')['con_il_conguaglio'], (int) $sezione('Ines')['da_rimborsare']])->toBe([8926, 18000, 6066, -15140])
        ->and($rimborsi($sezione('Ines')))->toBe([['da', 'Ugo', 15140]])
        ->and(collect($prospetto['conduttori'])->flatMap(fn ($c) => $c['voci'])->contains(fn ($v) => ($v['strada'] ?? null) === 'C'))->toBeFalse();
    // Il pannello dell'inizio dice le stesse persone e la stessa cifra, e non promette a Dora un rimborso di Luca che il prospetto non fa.
    expect($frase)->toContain('Luca rimborsa con il prospetto degli oneri accessori € 181,48 a Ugo')
        ->and($frase)->not->toContain('li rimborsa Luca a Dora');
    // La stampa dice anche Ugo, che senza giorni senza conduttore riceve da Luca e restituisce a Ines e a Dora.
    expect(fclStampa($s))->toContain('da rimborsare a Ugo da Luca: con l\'inizio della locazione di Luca del 01/07/2026 € 181,48')
        ->toContain('da restituire a Ines da Ugo: la fine della locazione del 01/07/2026 non ha scritto il conguaglio € 151,40');
});

it('Punto 2 — un cambio d\'inquilino di prima della .50 con la coppia scritta (Ines → Marco), poi la fine di Marco: le righe del cambio dicono che la coppia è scritta, e l\'inizio di Luca non dice che Luca rimborsa Ines, come il prospetto, che fa rimborsare Marco', function () {
    // Trovato con la mutazione M42. `comeSonoUsciti` dà «non scritto» a ogni fine senza la scelta nel registro, anche al cambio
    // d'inquilino di prima della .50, che la coppia l'ha scritta: i giorni dopo l'uscita di Ines li ha presi Marco. Il prospetto lo sa
    // (la catena del cambio d'inquilino, `uscitaDellaLocazione` dà null senza la scelta) e fa rimborsare a Luca i giorni di Marco: 36000 ×
    // 122/365 = 12032,88 → 12033, pagati da Marco. Il pannello dice «li rimborsa Luca a Ines». Il cambio di prima della .50 si simula come
    // in `ProspettoCambioInquilinoTest` (`pciComePrimaDellaCinquanta`): nessuna chiave `conguaglio` nel registro.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    $marco = fclPersona($s, 'Marco');
    $cambio = fclRegistra($this, $s, fclModulo($s, $marco) + ['conguaglio' => 'scrivi']);
    $registro = $cambio->registro;
    unset($registro['conguaglio']);
    DB::table('subentri')->where('id', $cambio->id)->update(['registro' => json_encode($registro)]);
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Marco' => 18148]);
    fclRegistra($this, $s, fclModulo($s, null, '2026-09-01', [], 'Marco') + ['conguaglio' => 'non_scrivere']);

    $frase = mcb50FraseInizio($this, $s, '2026-09-01');
    fclRegistra($this, $s, mcb50Inizio($s, 'Luca', '2026-09-01'));
    $luca = collect(app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Luca');

    // Il prospetto: Luca rimborsa 12033 a Marco.
    expect((int) $luca['da_rimborsare'])->toBe(12033)
        ->and(collect($luca['voci'])->pluck('pagato_da')->filter()->unique()->values()->all())->toBe(['Marco']);
    // Il pannello dell'inizio (prima della correzione sbagliava): non deve dire che Luca rimborsa Ines, che quei giorni non li
    // ha.
    expect($frase)->not->toContain('li rimborsa Luca a Ines');
});

it('M82 — dopo la fermata dell\'estinzione, l\'inizio di Luca dice «della fermata dell\'estinzione dell\'usufrutto del 1 ottobre 2026», con l\'apostrofo', function () {
    // `FermataDopoScrivi::di` mette la preposizione davanti alle parole del passaggio: «della vendita», «del legato», «dell'estinzione».
    // Senza il ramo dell'apostrofo il motivo diceva «di l'estinzione».
    $s = mcb50DopoScrivi($this, [['Ugo', 'usufruttuario', 100], ['Bice', 'nuda_proprietario', 100]]);
    fds50Registra($this, $s, fds50ModuloEstinzione($s, 'Ugo', '2026-10-01'));
    fclPersona($s, 'Luca');

    $motivi = implode(' | ', fclAnteprima($this, $s, mcb50Inizio($s, 'Luca', '2026-11-01'))['cancello']['motivi']);

    expect($motivi)->toContain('della fermata dell\'estinzione dell\'usufrutto del 1 ottobre 2026 restano da regolare fra Ugo e Bice');
});

it('Decisione 81.1 — l\'estinzione dell\'usufrutto di Ugo con l\'accrescimento a Carlo dopo una fine «Scrivi»: il peso di Ugo cala, e il pannello dice la fermata, € 45,37 verso Carlo', function () {
    // Trovato con la mutazione M84. Ugo e Carlo usufruttuari al 50 %, Bice nuda: la coppia della fine va metà a Ugo e metà a Carlo
    // (9074 + 9074). Il 1/10 l'usufrutto di Ugo si estingue con l'accrescimento: registrato, Carlo ha l'usufrutto al 100 % dal 1/10
    // (verificato sulle righe). Dal 1/10 al posto dell'inquilino c'è solo Carlo, e la parte di Ugo di quei giorni (18148 × 46/184 =
    // 4537, € 45,37) è la regola della fermata (decisione 77, punto 3: «l'estinzione dell'usufrutto» abbassa il peso di chi esce).
    // `FermataDopoScrivi::conIlPassaggio` con l'accrescimento non chiudeva la riga di Ugo (il ramo `else` toglieva la chiusura) e non
    // apriva quella di Carlo: per lui il passaggio non cambiava niente, e la fermata non c'era. (Il verbale elencava costituzione ed
    // estinzione senza dire l'accrescimento: la decisione 81.1 ha stabilito che è una fermata come le altre; il pannello taceva su €
    // 45,37 che restavano a Ugo.)
    $s = mcb50DopoScrivi($this, [['Ugo', 'usufruttuario', 50], ['Carlo', 'usufruttuario', 50], ['Bice', 'nuda_proprietario', 100]]);
    expect(fclSaldi($s))->toBe(['Carlo' => 9074, 'Ines' => -18148, 'Ugo' => 9074]);
    $modulo = ['quota' => 50, 'accrescimento' => true] + fds50ModuloEstinzione($s, 'Ugo', '2026-10-01');

    $a = fclAnteprima($this, $s, $modulo);

    expect(collect($a['rate']['fermate_dopo_scrivi'])->sum('cifra'))->toBe(4537)
        ->and(collect($a['rate']['fermate_dopo_scrivi'])->pluck('verso')->flatten()->all())->toBe(['Carlo']);
});

it('Decisione 81.3 (dato anomalo) — sul box Ugo ha anche una riga a quota zero, prima di quella vera: la registrazione della vendita chiude la riga a zero e lascia Ugo al 100 %, e il pannello non annuncia la fermata del box, come la registrazione', function () {
    // Trovato con la mutazione M83. `FermataDopoScrivi::conIlPassaggio` cercava la riga da chiudere sulla pertinenza «come
    // `RegistraSubentroAction::applicaAllaPertinenza`», ma con la quota (`inCorso` vuole `quota > 0`); la registrazione prende la prima
    // riga in corso il giorno prima, anche a quota zero (`TitolaritaImmobile::inCorsoIl`, codice di prima della .50). Il modulo accetta
    // quota 0 (`CreateImmobileAnagraficaRequest`: `min:0`). Così il pannello diceva che dal 1/10 la parte del box toccava a Elsa, e
    // dopo la registrazione al posto dell'inquilino sul box c'era ancora Ugo al 100 % ed Elsa aveva una riga a zero: la fermata
    // annunciata non era successa. Decisione 81.3: il pannello segue la registrazione, non il contrario. La fermata della pertinenza si
    // annuncia sulla riga che la registrazione chiude davvero: qui la riga a zero, che non abbassa il peso di Ugo sul box, quindi sul
    // box nessuna fermata. Che la registrazione scelga la riga a zero è un difetto di prima della .50 che non sposta soldi (Coda 246,
    // fuori dalla beta): la registrazione resta com'è, con la riga di Ugo al 100 % aperta. L'Interno 1 si ferma come sempre: la coppia
    // 16333 (900 millesimi) sui 184 giorni, Ugo 92 ed Elsa 92, 8166,5 a testa, il centesimo a pari resto a chi esce → la parte di Elsa
    // 8166 (€ 81,66).
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Ugo', 'proprietario', 0], ['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-09-30');
    fclPersona($s, 'Elsa');
    $modulo = ['pertinenze' => [(int) $s['box']->id]] + fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01');

    $fermate = collect(fclAnteprima($this, $s, $modulo)['rate']['fermate_dopo_scrivi']);
    fclRegistra($this, $s, $modulo);
    $ugoAlCento = DB::table('anagrafica_immobile')->where('immobile_id', $s['box']->id)->where('anagrafica_id', $s['persone']['Ugo']->id)->where('quota', 100)->value('data_fine');

    // Controlli: la fermata dell'Interno 1 c'è, € 81,66 verso Elsa; la registrazione lascia aperta la riga di Ugo al 100 %.
    expect($fermate->firstWhere('immobile_id', (int) $s['unita']->id)['cifra'] ?? null)->toBe(8166)
        ->and($ugoAlCento)->toBeNull();

    expect($fermate->firstWhere('immobile_id', (int) $s['box']->id))->toBeNull();
});

// ---------------------------------------------------------------------------------------------------------------------
// La scelta nel registro e nella registrazione
// ---------------------------------------------------------------------------------------------------------------------

it('M86 — con Dora che resta, un modulo rimasto aperto da prima della .50 manda solo la casella della rinuncia: la fine si registra, senza rifiuto', function () {
    // Fino alla .54 `RegistraSubentroAction` rifiutava un `conguaglio` mandato a mano con un coinquilino che resta, ma non quello che
    // `datiPassaggio` ricava dalla casella di prima (`empty($dati['rinuncia_conguaglio'])`, verifica S5, R13): senza le coppie la casella non
    // aveva mai contato. Senza l'eccezione il modulo vecchio si fermava con «la scelta non c'è. Ricarica la pagina».
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);

    fclStore($this, $s, fclModulo($s) + ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro alla riconsegna'])->assertSessionHasNoErrors();

    // Coda 245 (1.11.0-beta.55): con Dora che resta le coppie ora ci sono (la regola del piano) e la scelta si chiede; la casella del modulo
    // rimasto aperto vale «Non scriverlo» (`AnteprimaPassaggioRequest`), e il registro lo dice.
    expect(Subentro::where('immobile_id', $s['unita']->id)->where('tipo_passaggio', 'fine_locazione')->count())->toBe(1)
        ->and(fclRigheDeiPassaggi($s))->toBe(0)
        ->and(fclUltimo($s)->registro['conguaglio']['scelta'] ?? null)->toBe(Subentro::CONGUAGLIO_NON_SCRITTO);
});

it('M88, M93, M115 — il registro della fine «Scrivi» senza un nuovo inquilino conserva i periodi della coppia; quello del cambio d\'inquilino no', function () {
    // I periodi della coppia (`RegistraSubentroAction::periodiDellaCoppia`, dalle coppie con `giorni_dopo` di `ConguaglioPassaggio`)
    // servono alla fermata per ridividere la coppia: senza, la fermata ripiega sui giorni dall'uscita alla fine della gestione, che
    // con una gestione riusata su più esercizi non ci sono. Il cambio d'inquilino non ha periodi (chi riceve è una persona sola).
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    expect($fine->registro['conguaglio'])->toBe(['scelta' => 'scritto', 'periodi' => [[
        'gestione_id' => (int) $s['g']->id, 'immobile_id' => (int) $s['unita']->id, 'esercizio_id' => (int) $s['e']->id, 'importo' => 18148,
        'periodi' => [['tratti' => [['dal' => '2026-07-01', 'al' => '2026-12-31']], 'importo' => 18148]],
    ]]]);

    $t = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    $cambio = fclRegistra($this, $t, fclModulo($t, fclPersona($t, 'Luca')) + ['conguaglio' => 'scrivi']);
    expect($cambio->registro['conguaglio'])->toBe(['scelta' => 'scritto']);
});

it('M91 — il registro dell\'inizio di Ines dopo la sua fine «Scrivi» conserva il rientro, con la cifra (€ 90,74 dal 1/10)', function () {
    $s = mcb50DopoScrivi($this);

    $inizio = fds50Registra($this, $s, mcb50Inizio($s, 'Ines', '2026-10-01'))['subentro'];

    expect(collect($inizio->registro['rinnovo_dopo_scrivi'] ?? [])->sum('cifra'))->toBe(9074);
});

it('M92, M107 — lo storico dell\'inizio di Luca dopo la fermata della vendita dice quanto ne resta da regolare: il registro conserva la fermata ridotta (€ 30,58)', function () {
    // Le cifre di `InizioDopoScriviTest` (S7): dei € 90,74 della fermata, con Luca dal 1/11 resta ottobre, € 30,58.
    $s = mcb50DopoScrivi($this);
    fclPersona($s, 'Elsa');
    fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));
    fclPersona($s, 'Luca');

    $inizio = fds50Registra($this, $s, mcb50Inizio($s, 'Luca', '2026-11-01'))['subentro'];

    expect(implode(' ', fds50Storico($s, $inizio)['fermate'] ?? []))
        ->toContain('Con questo inizio, della fermata della vendita del 1 ottobre 2026 (€ 90,74 rimasti a carico di Ugo) restano da regolare fra Ugo e Elsa € 30,58');
});

it('M96 — il campo «conguaglio» mandato nell\'inizio di una locazione si rifiuta con la ragione dell\'inizio; nella vendita con quella della casella', function () {
    // `AnteprimaPassaggioRequest::messages`: il messaggio dice perché la scelta non c'è nel passaggio in corso.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    fclPersona($s, 'Luca');
    fclPersona($s, 'Elsa');

    $inizio = fclAnteprimaRisposta($this, $s, mcb50Inizio($s, 'Luca') + ['conguaglio' => 'scrivi'])->assertStatus(422)->json('errors.conguaglio.0');
    $vendita = fclAnteprimaRisposta($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01') + ['conguaglio' => 'scrivi'])->assertStatus(422)->json('errors.conguaglio.0');

    expect($inizio)->toBe('L\'inizio di una locazione non ha un conguaglio: la scelta non c\'è.')
        ->and($vendita)->toStartWith('In questo passaggio la scelta sul conguaglio non c\'è: per non scrivere la coppia proposta spunta');
});

it('M100 — dopo «Non scriverlo» l\'avviso dell\'annullamento dice la cifra di chi l\'avrebbe presa, € 181,48 sulla gestione, senza la riga specchio di Ines', function () {
    // Dalla .50 il registro della locazione porta anche chi esce, con il segno dei saldi (Ines −18148, Ugo +18148): le parole
    // (`Subentro::cifreInParole`) lo tolgono, altrimenti due «persone» diventano «€ 181,48 a credito di Ines e € 181,48 a debito di
    // Ugo», che non è un accordo di nessuno.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);

    $avvisi = implode(' | ', app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->avvisi($fine->fresh()));

    expect($avvisi)->toContain('Il conguaglio della fine locazione di Ines non è stato scritto (€ 181,48 sulla gestione Ordinaria 2026)')
        ->and($avvisi)->not->toContain('a credito di Ines');
});

it('M13 — una fine il 1/3/2027, senza un esercizio che la contenga: la frase «tornano a …» guarda un anno dal passaggio, e la vendita a Elsa del 1/6/2028 non c\'entra', function () {
    // `ChiPagaAlPostoDellInquilino::giorniDellaFrase`: senza esercizio, dal giorno del passaggio per un anno (fino al 29/2/2028). Con
    // un periodo più lungo la frase nominava anche Elsa, che entra il 1/6/2028.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    $elsa = fclPersona($s, 'Elsa');
    fclRegistra($this, $s, ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fclRiga($s, 'Ugo', 'proprietario'), 'anagrafica_entrante_id' => $elsa->id,
        'decorrenza' => '2028-06-01', 'quota' => 100, 'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Verdi, rep. 902', 'pertinenze' => []]);

    $obbligati = implode(' ', fclAnteprima($this, $s, fclModulo($s, null, '2027-03-01'))['obbligati']['frasi']);

    expect($obbligati)->toContain('Dal 1 marzo 2027 le voci a carico dell\'inquilino tornano a Ugo, nei piani generati o ricalcolati dopo il passaggio.');
});

it('M102 — «Annulla il conguaglio» dopo una fine «Scrivi» scrive nel registro anche chi esce, con il segno dei saldi, come la registrazione con «Non scriverlo»: Ines −18148, Ugo +18148', function () {
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $fine]),
        ['nota_annullamento_conguaglio' => 'Ugo e Ines hanno regolato fra loro alla riconsegna delle chiavi'])->assertSessionHasNoErrors();

    $persone = collect($fine->fresh()->registro['regolato_fuori'][0]['persone'] ?? [])->mapWithKeys(fn ($p) => [$p['nome'] => (int) $p['importo']])->all();

    expect($persone)->toBe(['Ines' => -18148, 'Ugo' => 18148]);
});

it('M103 — annullare la fine con Dora che resta non avvisa più di un saldo manuale dal Wallet scritto per la fermata, con o senza il riporto di Ines', function () {
    // Dalla 1.11.0-beta.53 gli avvisi dell'annullamento non leggono il registro della fermata: i saldi legati al passaggio li dice l'effetto,
    // quelli scritti a mano senza legame la ricerca dei saldi a mano scritti dopo (`Beta53SaldiAManoDiPrimaTest`). Senza saldi, niente avviso.
    $avviso = 'perché la locazione continuava con Dora: se per questo hai registrato un saldo manuale dal Wallet';
    foreach ([false, true] as $riporto) {
        $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
        if ($riporto) {
            fclRiporto($s, 'Ines', 5000);
        }
        fclGenera($s);
        fclEmetti($this, $s, '2026-06-30');
        // Coda 245 (1.11.0-beta.55): con il coinquilino la scelta c'è; con «Non scriverlo» l'avviso dice che il conguaglio non è stato scritto e a
        // chi andrebbe la parte ricalcolando (a ciascun conduttore la sua parte, per quota e per giorni), senza il Wallet.
        $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);

        $avvisi = implode(' | ', app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->avvisi($fine->fresh()));

        expect(str_contains($avvisi, $avviso))->toBeFalse($avvisi)
            ->and(str_contains($avvisi, 'Wallet'))->toBeFalse($avvisi)
            ->and($avvisi)->toContain('il condominio addebita a ciascun conduttore la sua parte, per quota e per giorni');
    }
});

it('M105 — annullare la vendita che si è fermata dopo «Scrivi», senza saldi scritti dopo: gli avvisi non dicono più «annullare il passaggio non lo toglie», che non c\'è niente da togliere', function () {
    // Coda 244: i saldi legati alla vendita si tolgono con lei; quelli a mano non legati, se ci sono, li nomina la ricerca dei saldi a mano.
    $s = mcb50DopoScrivi($this);
    fclPersona($s, 'Elsa');
    $vendita = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];

    $avvisi = implode(' | ', app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->avvisi($vendita->fresh()));

    expect($avvisi)->not->toContain('annullare il passaggio non lo toglie')
        ->and($avvisi)->not->toContain('sono rimasti a carico di Ugo sulla gestione Ordinaria 2026, da regolare a mano');
});

it('M108 — annullata la vendita che si era fermata, lo storico non dice più la fermata', function () {
    // `StoricoTitolarita`: le fermate di un passaggio annullato non valgono più (`$s->annullato() ? []`).
    $s = mcb50DopoScrivi($this);
    fclPersona($s, 'Elsa');
    $vendita = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    expect(fds50Storico($s, $vendita)['fermate'])->not->toBe([]);

    fclAnnulla($this, $s, $vendita)->assertStatus(302);

    expect(fds50Storico($s, $vendita)['fermate'])->toBe([]);
});

it('M109 — l\'Interno 1 e il box sono tutti e due di Ugo, ma sul box resta Dora: la frase «tornano a …» va unità per unità, e sul box dice che la locazione continua', function () {
    // `FrasiObbligati::fraseTornano` usa la frase unica solo se le unità hanno gli stessi nomi **e** gli stessi coinquilini: con i
    // soli nomi il box diceva «tornano a Ugo», come l'Interno 1.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]], ['box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 50], ['Dora', 'inquilino', 50]]]);

    $obbligati = implode(' ', fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]))['obbligati']['frasi']);

    expect($obbligati)->toContain('le voci a carico dell\'inquilino tornano a Ugo nell\'unità «Interno 1»')
        ->and($obbligati)->toContain('Nell\'unità «Box 12» la locazione continua con Dora');
});

it('M112 — sul box senza nessuno al posto dell\'inquilino dopo l\'uscita la frase «tornano …» non dice «a » senza nome né «al proprietario»: dice che nell\'unità «Box 12» nessuno sta al posto dell\'inquilino', function () {
    // `FrasiObbligati::fraseTornano`, unità per unità: un'unità senza nomi non ha nessuno a cui le voci tornino. La mutazione che il test
    // prendeva è la frase senza nome («tornano a nell'unità «Box 12»»); il ripiego della .47 («al proprietario») contraddiceva la frase del
    // pannello, e il test della ripresa sul box senza nessuno (`RevisioneBeta50FrasiTest`) vuole che sul box si dica che nessuno sta al posto
    // dell'inquilino. Qui la riga di Rita, proprietaria del box, finisce il 30/6.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]], ['box' => [['Rita', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Rita', 'proprietario', $s['box']))->update(['data_fine' => '2026-06-30']);

    $obbligati = implode(' ', fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]))['obbligati']['frasi']);
    $periodi = preg_split('/(?<=\.)\s+/u', $obbligati);
    $tornano = (string) collect($periodi)->first(fn (string $p) => str_contains($p, 'tornano'));

    // La «a » senza nome si cerca in tutto il periodo «tornano …», non solo subito dopo «tornano»: l'unità senza nomi può venire dopo
    // un'altra («tornano a Ugo nell'unità «Interno 1» e a  nell'unità «Box 12»»). E il periodo su «Box 12» è quello delle voci a carico
    // dell'inquilino: la frase del piano fermo dice anche lei che sul box «nessuno sta al posto dell'inquilino», e non basta.
    expect($obbligati)->toContain('le voci a carico dell\'inquilino tornano a Ugo nell\'unità «Interno 1»')
        ->and($obbligati)->not->toContain('al proprietario nell\'unità «Box 12»')
        ->and(preg_match('/\bad?\s+(?:nell|e\s|,|\.)/u', $tornano))->toBe(0, 'La frase «tornano …» ha una «a » senza nome: ' . $tornano)
        ->and(collect($periodi)->contains(fn (string $p) => str_contains($p, '«Box 12»') && str_contains($p, 'nessuno sta al posto dell\'inquilino') && str_contains($p, 'voci a carico dell\'inquilino')))
        ->toBeTrue('Nessuna frase dice che nell\'unità «Box 12» nessuno sta al posto dell\'inquilino per le voci a carico dell\'inquilino: ' . $obbligati);
});

// ---------------------------------------------------------------------------------------------------------------------
// Il prospetto: la strada della fine, piano per piano e unità per unità
// ---------------------------------------------------------------------------------------------------------------------

it('M119 — dopo una fine «Scrivi» il piano del riscaldamento, che la coppia non ha preso (emesso dopo, senza ricalcolo), è la strada A′: i giorni dopo l\'uscita sono di Ines, da restituirle', function () {
    // `uscitaDellaLocazione`: «Scrivi» vale solo sul piano che la coppia ha preso (`presoNelConguaglioDa`); sul riscaldamento la coppia non
    // c'è, i giorni dopo l'uscita sono nelle rate di Ines. «Combustibile» € 730,00 tutto all'inquilino: 73000 × 184/365 = 36800.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    $riscaldamento = mcb50SecondaGestione($s);
    fclGenera($s);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($riscaldamento->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione del riscaldamento', esercizio: $s['e']);
    fclEmetti($this, $s, '2026-06-30');
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    mcb50EmettiPiano($this, $s, $riscaldamento, '2026-09-30');

    $p = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $combustibile = collect(collect($p['conduttori'])->firstWhere('nome', 'Ines')['voci'])->filter(fn ($v) => ($v['conto'] ?? null) === 'Combustibile' && ! empty($v['strada']));

    expect($combustibile->pluck('strada')->unique()->values()->all())->toBe(['A'])
        ->and((int) $combustibile->where('modo', 'restituzione')->sum('importo'))->toBe(-36800);
});

it('M121 — l\'Interno 1 e il box spuntato, con Dora che resta solo sul box: il prospetto dell\'Interno 1 è la strada B (Ugo ha la coppia), non quella del coinquilino', function () {
    // `coinquilinoCheResta` legge nel registro la fermata del coinquilino **dell'unità** (`immobile_id`): la fermata del box non vale per
    // l'Interno 1. Interno 1 (900 millesimi): 32400 × [181, 184] / 365 → 16067 a Ines, la coppia 16333 a Ugo.
    $s = mcb50ConSeiRate($this, [['Ugo', 'proprietario', 100]], ['box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 50], ['Dora', 'inquilino', 50]]]);
    // Coda 245 (1.11.0-beta.55): sul box, dove resta Dora, la coppia è della regola del piano (anello 3600, congelato 1800 e 1800, dopo 3600 ×
    // 9050/27300 = 1193,4 → Ines 1193, Dora 2407: ∓ 607) e il registro ha la regola del piano del box, non più la fermata del coinquilino.
    $fine = fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);
    expect(collect($fine->registro['conguaglio']['piano'] ?? [])->pluck('immobile_id')->all())->toBe([(int) $s['box']->id])
        ->and(fclSaldi($s, $s['box']))->toBe(['Dora' => 607, 'Ines' => -607]);

    expect(fclProspetto($s))->toBe(['conduttori' => ['ines' => [16067, 16067, 0, 181]], 'senza' => [16333, 184]]);
});
