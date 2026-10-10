<?php

/**
 * 1.11.0-beta.48, Fase 1-bis (rilievi bassi e medi della revisione): la richiesta e il registro della successione, lato server.
 *
 * Da dove nasce: la revisione della Fase 1-bis ha trovato, oltre ai testi del pannello, alcuni difetti piccoli nella richiesta
 * (`AnteprimaPassaggioRequest`), nel registro del passaggio e nei messaggi che lo leggono (nel rapporto: denaro R2, R4 e R5, testi R6
 * e R7, sicurezza SIC-1 e SIC-2). Dopo la Fase 1 il campo `conguaglio` (`scrivi` / `non_scrivere`) è accettato per ogni tipo di
 * passaggio: nella successione vince in silenzio sulla casella `rinuncia_conguaglio`, negli altri tipi è ignorato in silenzio (la
 * coppia si scrive comunque, anche con `non_scrivere`); un elenco al posto della scelta o dell'arretrato fa una pagina 500
 * (`(string)` su un array, la stessa classe di errore del «pippo» già corretto); nella successione la data si chiama ancora «la data
 * dell'atto»; sul box le righe degli eredi non dicono «non scritto»; la successione registrata fra la .44 e la .47 con la casella dice
 * ancora «€ 5,48», la somma di coppie di segno diverso; i messaggi dei saldi dicono «una delle due» righe dove le righe sono sei. Il
 * verbale (`docs/piano_esecutivo_beta48.md`, «Fase 1-bis») ha deciso che si correggono tutti, con i test prima: questi.
 *
 * Il caso di riferimento è quello di Fresco e della .44. € 1.200,00 l'anno in dodici rate da € 100,00, rate 1–4 a giornale e non
 * pagate, otto bozze; Ugo muore il 1° maggio; eredi Anna 33,34 %, Bruno 33,33 % e Carla 33,33 %, Anna erede di riferimento, arretrato a
 * nome del defunto. Le cifre, rifatte a mano:
 * - i giorni dopo il decesso sono 245 (31 + 30 + 31 + 31 + 30 + 31 + 30 + 31 = 245): € 1.200,00 × 245 / 365 = 80547,95, cioè 80548
 *   centesimi. Per quota: 80548 × 33,34 % = 26854,70 e 80548 × 33,33 % = 26846,65 due volte; le parti intere sommano 26854 + 26846 +
 *   26846 = 80546, mancano 2 centesimi, ai resti maggiori (,70 ad Anna, ,65 a Bruno, primo dei due pari): Anna 26855, Bruno 26847,
 *   Carla 26846 (somma 80548);
 * - le otto bozze (€ 800,00) vanno ad Anna: la sua coppia è 26855 − 80000 = −53145 (€ 531,45 a credito), Bruno +26847 (€ 268,47 a
 *   debito), Carla +26846 (€ 268,46 a debito); la somma, 548, è il « € 5,48 » del registro di prima e il credito di Ugo;
 * - con un erede solo (Anna al 100 %) la coppia è 80548 − 80000 = +548: la somma è davvero la cifra di una persona, e « € 5,48 »
 *   è giusto. Lo stesso nella vendita a Elsa: 120000 × 245 / 365 = 80548, meno le otto bozze (80000) = 548.
 *
 * Cosa presidia, rosso sul codice di oggi: `conguaglio` vietato fuori dalla successione, con la casella o senza (denaro R2 e SIC-2, 422
 * sul campo); la contraddizione `conguaglio` + `rinuncia_conguaglio=true` nella successione, rifiutata sul campo `conguaglio` con
 * qualunque valore (SIC-2); l'elenco al posto della scelta o dell'arretrato (SIC-1: 422, mai 500); «il giorno del decesso» nei messaggi
 * della data della successione, con la maiuscola a inizio frase, e il periodo chiuso che non dice «data dell'atto» (testi R7); lo
 * storico del box, dove le righe degli eredi leggono il passaggio padre (`conguaglio_non_scritto` vero e la nota, denaro R4); la
 * successione con più eredi registrata prima della .48 con la casella, che non dice la somma ma «le cifre per erede non sono nel
 * registro» (denaro R5); i messaggi dei saldi sulle righe del conguaglio e sul lucchetto dell'arretrato senza «una delle due» (testi R6).
 *
 * I controlli, verdi oggi, che devono restarlo: la vendita senza `conguaglio` (o con `null`, come la manda il modulo) che scrive la
 * coppia; la successione con `conguaglio` e `rinuncia_conguaglio=false`, come la manda il modulo nuovo; la casella da sola con la nota,
 * che vale «non scriverlo»; i messaggi della data fuori dalla successione («la data dell'atto»); le righe del box con «scrivi» (falso);
 * la successione con un erede solo o la vendita registrate prima della .48, che dicono « € 5,48 »; la registrazione della .48 con le
 * persone, che dice le tre cifre; il messaggio dei saldi che resta sul campo, dice che conguaglio e arretrato sono un conto solo e
 * la strada («annullando la successione»), con la riga che non cambia; il lucchetto dell'arretrato che resta un 403 e parla del piano
 * che assorbe la riga.
 *
 * Cosa NON copre: il pannello e il modulo (resources/js), le frasi dell'anteprima (`SuccessioneTestiTest`), le cifre per erede dei
 * messaggi della .48 (`SuccessioneSceltaConguaglioTest`); la frase nuova dei messaggi dei saldi (il verbale non la fissa: qui si prova
 * solo che «una delle due» non c'è e che il resto del messaggio resta); `Saldo::FRASE_ARRETRATO` («una delle due dell'arretrato»,
 * che con tre eredi è anch'essa una delle sei), che la revisione non nomina; la nota del box della rinuncia della vendita (R4 dice che
 * la correzione la sistema anche lì: qui si prova la successione). L'annullamento dell'emissione con un «non scritto» della .48, che
 * passa per la decisione 49, è il test che in `SuccessioneSceltaConguaglioTest` sostituisce quello che toglieva `piani_presi`.
 */

use App\Actions\Subentro\AnnullaPassaggioAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
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

/** Una persona del condominio dello scenario (un erede). */
function srqPersona(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => 'srq' . $n . '-' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'SRQPERSONA' . str_pad((string) $n, 6, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/**
 * Il caso di riferimento: lo scenario della .44 con le rate 1–4 a giornale e i tre eredi.
 *
 * @return array{0: array, 1: Anagrafica, 2: Anagrafica, 3: Anagrafica} lo scenario, Anna, Bruno e Carla
 */
function srqCaso(): array
{
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    return [$s, srqPersona($s, 'Anna'), srqPersona($s, 'Bruno'), srqPersona($s, 'Carla')];
}

/** Il corpo della successione di Ugo (1° maggio) ai tre eredi, Anna erede di riferimento. */
function srqCorpo(array $s, Anagrafica $anna, Anagrafica $bruno, Anagrafica $carla, array $extra = [], string $arretrato = 'defunto'): array
{
    return array_merge([
        'tipo' => 'successione', 'riga_uscente_id' => $s['rigaV'], 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $anna->id, 'quota' => 33.34], ['anagrafica_id' => $bruno->id, 'quota' => 33.33], ['anagrafica_id' => $carla->id, 'quota' => 33.33]],
        'arretrato' => $arretrato, 'erede_di_riferimento' => $anna->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 123', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta: gli eredi entrano dal decesso',
    ], $extra);
}

/** Il corpo della successione di Ugo a un erede solo, Anna al 100 %: la coppia è la cifra di una persona sola. */
function srqCorpoAnna(array $s, Anagrafica $anna, array $extra = []): array
{
    return srqCorpo($s, $anna, $anna, $anna, array_merge(['eredi' => [['anagrafica_id' => $anna->id, 'quota' => 100]], 'erede_di_riferimento' => null], $extra));
}

/** Il corpo di una vendita il 1° maggio a Elsa (`a`), con il rogito letto. */
function srqCorpoVendita(array $s, array $extra = []): array
{
    return array_merge(ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-05-01', 100), ['ho_letto' => true, 'nota_cancello' => 'Rogito letto fra le parti'], $extra);
}

/** La fine della locazione di un'inquilina dal 2025, al 1° luglio: un tipo di passaggio che non ha la scelta sul conguaglio. */
function srqCorpoFineLocazione(array $s): array
{
    $inquilina = srqPersona($s, 'Ines Inquilina');
    $riga = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $inquilina->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2025-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    return ['tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => $riga, 'decorrenza' => '2026-07-01', 'quota' => 100,
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Fine della locazione letta'];
}

/** La registrazione dalla rotta vera, così com'è: la risposta da controllare. */
function srqStore($test, array $s, array $corpo): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $corpo);
}

/** L'anteprima, così com'è: la risposta da controllare. */
function srqAnteprima($test, array $s, array $corpo): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $corpo);
}

/** La registrazione che deve riuscire; il passaggio padre appena scritto. */
function srqRegistra($test, array $s, array $corpo): Subentro
{
    srqStore($test, $s, $corpo)->assertSessionHasNoErrors();

    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

/**
 * L'anteprima e la registrazione rifiutate sul campo, con un 422 vero (mai una pagina 500): le frasi dell'errore dell'anteprima, in un
 * testo solo. Dopo, nessun passaggio e nessuna riga in saldi, e le righe di titolarità come prima.
 */
function srqRifiuto($test, array $s, array $corpo, string $campo): string
{
    $righe = ruRighe($s['unita']->id);

    $risposta = srqAnteprima($test, $s, $corpo);
    expect($risposta->status())->toBe(422);
    $risposta->assertJsonValidationErrors([$campo]);
    $frasi = implode(' ', $risposta->json("errors.{$campo}"));

    $store = srqStore($test, $s, $corpo);
    expect($store->status())->toBe(302);
    $store->assertSessionHasErrors($campo);

    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0)
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->count())->toBe(0)
        ->and(ruRighe($s['unita']->id))->toBe($righe);

    return $frasi;
}

/** Le righe in saldi scritte dai passaggi dell'unità, sommate per persona: nome → centesimi, senza gli zeri. */
function srqSaldi(array $s): array
{
    $out = [];
    foreach (DB::table('saldi')->whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->get() as $r) {
        $out[(int) $r->anagrafica_id] = ($out[(int) $r->anagrafica_id] ?? 0) + (int) $r->saldo_iniziale;
    }
    $nomi = Anagrafica::whereIn('id', array_keys($out))->pluck('nome', 'id');
    $nominati = [];
    foreach ($out as $id => $c) {
        if ($c !== 0) {
            $nominati[$nomi[$id]] = $c;
        }
    }
    ksort($nominati);

    return $nominati;
}

/**
 * Il registro come lo scriveva la .44–.47 con la casella «Gli eredi hanno regolato il conguaglio fra loro»: `regolato_fuori` senza le
 * persone e nessuna chiave `conguaglio` (la scelta nasce con la .48). La nota resta sul passaggio.
 */
function srqComePrimaDellaQuarantotto(Subentro $sub): Subentro
{
    $registro = $sub->registro;
    unset($registro['conguaglio']);
    $registro['regolato_fuori'] = array_map(function (array $voce) {
        unset($voce['persone']);

        return $voce;
    }, $registro['regolato_fuori']);
    $sub->update(['registro' => $registro]);

    return $sub->fresh();
}

// ---------------------------------------------------------------------------------------------------------------------
// denaro R2 e SIC-2 — la scelta fuori dalla successione, e in contraddizione con la casella
// ---------------------------------------------------------------------------------------------------------------------

it('SIC-2: fuori dalla successione il campo «conguaglio» si rifiuta sul campo, con qualunque valore e con la casella o senza', function (array $extra) {
    // La vendita ha la casella e la nota obbligatoria (decisione 73, punto 3): una scelta mandata a mano non può saltarla. Oggi la
    // coppia (Elsa +548, Ugo −548: 120000 × 245 / 365 = 80548, meno le otto bozze da 80000) si scrive comunque, in silenzio.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    srqRifiuto($this, $s, srqCorpoVendita($s, $extra), 'conguaglio');
})->with([
    'non_scrivere, senza la casella e senza nota' => [['conguaglio' => 'non_scrivere']],
    'scrivi, senza la casella' => [['conguaglio' => 'scrivi']],
    'non_scrivere, con la casella e la nota' => [['conguaglio' => 'non_scrivere', 'rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito fra le parti']],
]);

it('SIC-2: anche la fine della locazione rifiuta il campo «conguaglio» sul campo, e non scrive niente', function () {
    // Il divieto vale per ogni tipo che non è la successione: le righe dell'inquilina restano com'erano.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    srqRifiuto($this, $s, srqCorpoFineLocazione($s) + ['conguaglio' => 'non_scrivere'], 'conguaglio');
});

it('SIC-2, controllo: la vendita con «conguaglio» nullo, come la manda il modulo fuori dalla successione, si registra e scrive la coppia', function () {
    // Il modulo manda `conguaglio: null` per ogni tipo che non è la successione: il divieto deve lasciarlo passare. La coppia: Elsa
    // 80548 − 80000 = +548 a debito, Ugo −548 a credito.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    srqRegistra($this, $s, srqCorpoVendita($s, ['conguaglio' => null]));

    expect(srqSaldi($s))->toBe(['Acquirente Elsa' => 548, 'Venditore Ugo' => -548]);
});

it('SIC-2: nella successione «conguaglio» insieme a «rinuncia_conguaglio» vero si rifiuta sul campo «conguaglio», con qualunque scelta', function (string $scelta) {
    // Oggi `scrivi` più la casella scrive le sei righe e butta la nota senza dirlo (registro «scritto», nota nulla); `non_scrivere` più
    // la casella la accetta come se fosse una cosa sola. Due modi di dire la stessa scelta nello stesso corpo: si rifiuta.
    [$s, $anna, $bruno, $carla] = srqCaso();
    $corpo = srqCorpo($s, $anna, $bruno, $carla, ['conguaglio' => $scelta, 'rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Gli eredi hanno regolato fra loro il conguaglio']);

    srqRifiuto($this, $s, $corpo, 'conguaglio');
})->with(['scrivi' => ['scrivi'], 'non_scrivere' => ['non_scrivere']]);

it('SIC-2, controllo: la successione con la scelta e la casella spenta, come la manda il modulo nuovo, si registra', function () {
    // Il modulo nuovo manda `rinuncia_conguaglio: false` nella successione: la contraddizione è solo la casella vera.
    [$s, $anna, $bruno, $carla] = srqCaso();

    $sub = srqRegistra($this, $s, srqCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'scrivi', 'rinuncia_conguaglio' => false]))->fresh();

    // Sei righe: tre coppie (Anna −53145, Bruno +26847, Carla +26846, Ugo −548).
    expect(srqSaldi($s))->toBe(['Anna' => -53145, 'Bruno' => 26847, 'Carla' => 26846, 'Venditore Ugo' => -548])
        ->and($sub->conguaglioNonScritto())->toBeFalse();
});

it('SIC-2, controllo: la successione con la sola casella e la nota, come la manda un modulo di prima, vale «non scriverlo»', function () {
    // La mappatura del modulo vecchio resta: la casella da sola è la scelta «non scrivere», e la nota si conserva.
    [$s, $anna, $bruno, $carla] = srqCaso();

    $sub = srqRegistra($this, $s, srqCorpo($s, $anna, $bruno, $carla, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Gli eredi hanno regolato fra loro il conguaglio']))->fresh();

    expect($sub->conguaglioNonScritto())->toBeTrue()
        ->and($sub->nota_conguaglio)->toBe('Gli eredi hanno regolato fra loro il conguaglio')
        ->and(srqSaldi($s))->toBe([]);
});

// ---------------------------------------------------------------------------------------------------------------------
// SIC-1 — un elenco al posto della scelta o dell'arretrato
// ---------------------------------------------------------------------------------------------------------------------

it('SIC-1: un elenco al posto della scelta o dell\'arretrato dà un 422 sul campo, mai una pagina 500, e non scrive niente', function (array $extra, string $arretrato, string $campo) {
    // `(string) $this->input('conguaglio')` e `(string) $this->input('arretrato')` in `controllaSuccessione` fanno «Array to string
    // conversion» con un elenco: la richiesta costruita a mano finisce in una pagina 500 invece che nel messaggio del campo.
    [$s, $anna, $bruno, $carla] = srqCaso();

    srqRifiuto($this, $s, srqCorpo($s, $anna, $bruno, $carla, $extra, $arretrato), $campo);
})->with([
    'la scelta come elenco, «non_scrivere»' => [['conguaglio' => ['non_scrivere']], 'defunto', 'conguaglio'],
    'la scelta come elenco, «scrivi»' => [['conguaglio' => ['scrivi']], 'defunto', 'conguaglio'],
    'l\'arretrato come elenco, con la scelta «non_scrivere»' => [['conguaglio' => 'non_scrivere', 'arretrato' => ['defunto']], 'defunto', 'arretrato'],
    'l\'arretrato come elenco, con la casella di un modulo di prima' => [['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Gli eredi hanno regolato fra loro', 'arretrato' => ['eredi']], 'eredi', 'arretrato'],
]);

it('SIC-1, controllo: la vendita con «conguaglio» come elenco dà già un 422 sul campo, come prima', function () {
    // Fuori dalla successione il controllo dopo la validazione non gira: l'elenco è già rifiutato dalla regola del valore.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    srqRifiuto($this, $s, srqCorpoVendita($s, ['conguaglio' => ['non_scrivere']]), 'conguaglio');
});

// ---------------------------------------------------------------------------------------------------------------------
// testi R7 — «il giorno del decesso» nei messaggi della data
// ---------------------------------------------------------------------------------------------------------------------

it('testi R7: nella successione una data che non è una data dice «Il giorno del decesso non è una data valida.», con la maiuscola', function () {
    // Oggi: «la data dell'atto non è una data valida.», in minuscolo (è un'intera frase, non un pezzo di frase).
    [$s, $anna, $bruno, $carla] = srqCaso();

    $frasi = srqRifiuto($this, $s, srqCorpo($s, $anna, $bruno, $carla, ['decorrenza' => 'pippo']), 'decorrenza');

    expect($frasi)->toContain('Il giorno del decesso non è una data valida.');
    expect($frasi)->not->toContain('data dell\'atto');
});

it('testi R7, controllo: nella vendita una data che non è una data dice ancora «la data dell\'atto»', function () {
    // «Il giorno del decesso» è della successione: la vendita ha un atto. La maiuscola iniziale non è fissata fuori dalla successione.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    $frasi = srqRifiuto($this, $s, srqCorpoVendita($s, ['decorrenza' => 'pippo']), 'decorrenza');

    expect(mb_strtolower($frasi))->toContain('la data dell\'atto non è una data valida.');
    expect($frasi)->not->toContain('decesso');
});

it('testi R7: nella successione il messaggio del periodo chiuso non dice «data dell\'atto»', function () {
    // Il periodo di Ugo si è chiuso il 28 febbraio 2026 (la data di fine scritta a mano), il decesso è il 1° maggio: il giorno prima del
    // decesso, il 30 aprile, il periodo non è più in corso. Oggi: «… da un titolare in corso alla data dell'atto.».
    [$s, $anna, $bruno, $carla] = srqCaso();
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['data_fine' => '2026-02-28']);

    $frasi = srqRifiuto($this, $s, srqCorpo($s, $anna, $bruno, $carla), 'riga_uscente_id');

    expect($frasi)->toStartWith('Questo periodo si è chiuso il 28 febbraio 2026');
    expect($frasi)->not->toContain('data dell\'atto');
    expect($frasi)->toContain('decesso');
});

it('testi R7, controllo: nella vendita il messaggio del periodo chiuso dice ancora «alla data dell\'atto»', function () {
    // Stessa riga chiusa il 28 febbraio 2026, vendita del 1° maggio: il messaggio di oggi non cambia.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['data_fine' => '2026-02-28']);

    $frasi = srqRifiuto($this, $s, srqCorpoVendita($s), 'riga_uscente_id');

    expect($frasi)->toStartWith('Questo periodo si è chiuso il 28 febbraio 2026')
        ->toContain('alla data dell\'atto');
    expect($frasi)->not->toContain('decesso');
});

// ---------------------------------------------------------------------------------------------------------------------
// denaro R4 — lo storico del box
// ---------------------------------------------------------------------------------------------------------------------

/** Il Box 12 di Ugo, pertinenza dell'appartamento, tutto suo dal 2019: la successione lo porta agli stessi eredi, nello stesso atto. */
function srqBox(array $s): \App\Models\Immobile
{
    $box = \App\Models\Immobile::forceCreate(['condominio_id' => $s['c']->id, 'nome' => 'Box 12', 'descrizione' => 'Box', 'interno' => 'B12', 'pertinenza_di_immobile_id' => $s['unita']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['v']->id, 'immobile_id' => $box->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    return $box;
}

/** Le righe dello storico di un'unità che nascono da un passaggio, nell'ordine dello storico. */
function srqRigheDiUnPassaggio(\App\Models\Immobile $unita): array
{
    return array_values(array_filter(app(StoricoTitolarita::class)->perImmobile($unita->fresh())['righe'], fn (array $r) => $r['subentro'] !== null));
}

it('denaro R4: sul box le righe degli eredi di una successione «non scritta» dicono «conguaglio non scritto», come quelle dell\'appartamento', function () {
    // Le righe del passaggio sono quattro su ciascuna unità: Ugo (che esce, dal 2019 al 30 aprile) e Anna, Bruno, Carla (dal 1° maggio).
    // Il passaggio del box è un figlio che non porta né la nota né `registro.conguaglio`: la riga deve leggere il passaggio padre.
    // Sul box le bozze passano ad Anna e Ugo tiene le rate emesse: il «non scritto» è vero anche lì.
    [$s, $anna, $bruno, $carla] = srqCaso();
    $box = srqBox($s);

    srqRegistra($this, $s, srqCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere', 'pertinenze' => [$box->id]]));

    $sulBox = srqRigheDiUnPassaggio($box);
    $sull = srqRigheDiUnPassaggio($s['unita']);
    expect($sull)->toHaveCount(4)
        ->and(array_map(fn (array $r) => $r['subentro']['conguaglio_non_scritto'], $sull))->toBe([true, true, true, true]);
    expect($sulBox)->toHaveCount(4)
        ->and(array_map(fn (array $r) => $r['subentro']['conguaglio_non_scritto'] ?? null, $sulBox))->toBe([true, true, true, true]);
});

it('denaro R4: sul box le righe degli eredi portano anche la nota facoltativa del «non scritto», come quelle dell\'appartamento', function () {
    // La scheda dello storico scrive «conguaglio non scritto: «nota»» leggendo il flag e la nota della stessa riga (TitolaritaSheet.vue):
    // la correzione di R4 legge dal padre tutti e due, o la riga del box direbbe «non scritto» senza la nota che l'amministratore ha scritto.
    [$s, $anna, $bruno, $carla] = srqCaso();
    $box = srqBox($s);

    srqRegistra($this, $s, srqCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere', 'nota_conguaglio' => 'Gli eredi si accordano da soli', 'pertinenze' => [$box->id]]));

    $sull = srqRigheDiUnPassaggio($s['unita']);
    $sulBox = srqRigheDiUnPassaggio($box);
    expect(array_map(fn (array $r) => $r['subentro']['nota_conguaglio'], $sull))->toBe(array_fill(0, 4, 'Gli eredi si accordano da soli'));
    expect(array_map(fn (array $r) => $r['subentro']['nota_conguaglio'], $sulBox))->toBe(array_fill(0, 4, 'Gli eredi si accordano da soli'));
});

it('denaro R4, controllo: con «scrivi» le righe del box e quelle dell\'appartamento non dicono «non scritto»', function () {
    // Il campo è falso, non assente, su tutte e otto le righe: la lettura dal padre non può accendersi dove il conguaglio è scritto.
    [$s, $anna, $bruno, $carla] = srqCaso();
    $box = srqBox($s);

    srqRegistra($this, $s, srqCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'scrivi', 'pertinenze' => [$box->id]]));

    $sulBox = srqRigheDiUnPassaggio($box);
    $sull = srqRigheDiUnPassaggio($s['unita']);
    expect($sull)->toHaveCount(4)
        ->and(array_map(fn (array $r) => $r['subentro']['conguaglio_non_scritto'], $sull))->toBe([false, false, false, false]);
    expect($sulBox)->toHaveCount(4)
        ->and(array_map(fn (array $r) => $r['subentro']['conguaglio_non_scritto'] ?? null, $sulBox))->toBe([false, false, false, false]);
});

// ---------------------------------------------------------------------------------------------------------------------
// denaro R5 — la successione registrata prima della .48 con la casella
// ---------------------------------------------------------------------------------------------------------------------

it('denaro R5: la successione con più eredi registrata prima della .48 con la casella non dice la somma, ma che le cifre per erede non sono nel registro', function () {
    // Il registro della .44–.47: `regolato_fuori` = [{gestione «Ordinaria 2026», importo 548}] senza persone, nessuna chiave `conguaglio`,
    // la nota sul passaggio. 548 è −53145 + 26847 + 26846: la somma di coppie di segno diverso, la cifra di nessuno. Le cifre per erede
    // non si possono ricostruire, perché le coppie non sono state scritte: si dice.
    [$s, $anna, $bruno, $carla] = srqCaso();
    $sub = srqRegistra($this, $s, srqCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere', 'nota_conguaglio' => 'Gli eredi hanno regolato fra loro il conguaglio']));
    $sub = srqComePrimaDellaQuarantotto($sub);
    expect($sub->registro['regolato_fuori'][0]['importo'])->toBe(548)
        ->and($sub->registro['regolato_fuori'][0])->not->toHaveKey('persone')
        ->and($sub->conguaglioRinunciato())->toBeTrue()
        ->and($sub->conguaglioNonScritto())->toBeFalse();

    $frase = (string) $s['piano']->fresh()->fraseRegolatoFuori();

    expect($frase)->toContain('le cifre per erede non sono nel registro');
    expect($frase)->not->toContain('€ 5,48');
});

it('denaro R5: l\'avviso dell\'annullamento della stessa successione non dice la somma, ma che le cifre per erede non sono nel registro', function () {
    // Stesso registro di prima della .48: l'avviso di `AnnullaPassaggioAction::avvisi()` (lo stesso che la scheda dello storico mostra).
    [$s, $anna, $bruno, $carla] = srqCaso();
    $sub = srqRegistra($this, $s, srqCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere', 'nota_conguaglio' => 'Gli eredi hanno regolato fra loro il conguaglio']));
    $sub = srqComePrimaDellaQuarantotto($sub);

    $avvisi = implode(' | ', app(AnnullaPassaggioAction::class)->avvisi($sub));

    expect($avvisi)->toContain('le cifre per erede non sono nel registro');
    expect($avvisi)->not->toContain('€ 5,48');
});

it('denaro R5, controllo: la successione con un erede solo, registrata prima della .48, dice ancora «€ 5,48»: la somma è la cifra di Anna', function () {
    // Anna al 100 %: 120000 × 245 / 365 = 80548 per i suoi giorni, meno le otto bozze (80000) = +548, € 5,48 a suo debito. Con una persona
    // sola la somma è la cifra di quella persona, e il registro di prima non ha niente da nascondere.
    [$s, $anna] = srqCaso();
    $sub = srqRegistra($this, $s, srqCorpoAnna($s, $anna, ['conguaglio' => 'non_scrivere', 'nota_conguaglio' => 'Gli eredi hanno regolato fra loro il conguaglio']));
    $sub = srqComePrimaDellaQuarantotto($sub);
    expect($sub->registro['regolato_fuori'][0]['importo'])->toBe(548);

    $frase = (string) $s['piano']->fresh()->fraseRegolatoFuori();
    $avvisi = implode(' | ', app(AnnullaPassaggioAction::class)->avvisi($sub));

    expect($frase)->toContain('€ 5,48 sulla gestione Ordinaria 2026');
    expect($frase)->not->toContain('non sono nel registro');
    expect($avvisi)->toContain('€ 5,48 sulla gestione Ordinaria 2026');
    expect($avvisi)->not->toContain('non sono nel registro');
});

it('denaro R5, controllo: la vendita con la rinuncia registrata prima della .48, senza le persone nel registro, dice ancora «€ 5,48»', function () {
    // Elsa: 80548 − 80000 = +548. Il registro della vendita di prima non ha mai avuto le persone, e una persona sola non ne ha bisogno.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $sub = srqRegistra($this, $s, srqCorpoVendita($s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito fra le parti']));
    $sub = srqComePrimaDellaQuarantotto($sub);

    $frase = (string) $s['piano']->fresh()->fraseRegolatoFuori();
    $avvisi = implode(' | ', app(AnnullaPassaggioAction::class)->avvisi($sub));

    expect($frase)->toContain('Le parti hanno già regolato fra loro € 5,48 sulla gestione Ordinaria 2026');
    expect($frase)->not->toContain('non sono nel registro');
    expect($avvisi)->toContain('Le parti hanno già regolato fra loro € 5,48 sulla gestione Ordinaria 2026');
    expect($avvisi)->not->toContain('non sono nel registro');
});

it('denaro R5, controllo: la successione della .48 con le persone nel registro dice le tre cifre, non che le cifre mancano', function () {
    // Con le persone (€ 531,45 a credito di Anna, € 268,47 a debito di Bruno, € 268,46 a debito di Carla) la frase non ha niente da
    // nascondere: la nuova riga vale solo per il registro che le persone non le ha.
    [$s, $anna, $bruno, $carla] = srqCaso();
    srqRegistra($this, $s, srqCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']));

    $frase = (string) $s['piano']->fresh()->fraseRegolatoFuori();

    expect($frase)->toContain('€ 531,45 a credito di Anna, € 268,47 a debito di Bruno e € 268,46 a debito di Carla');
    expect($frase)->not->toContain('non sono nel registro');
});

// ---------------------------------------------------------------------------------------------------------------------
// testi R6 — i messaggi dei saldi senza «una delle due»
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Il caso di Fresco con l'arretrato agli eredi, «scrivi» non serve (con gli eredi non c'è scelta): sei righe di conguaglio e sei
 * dell'arretrato. La riga del conguaglio di Anna e una riga dell'arretrato.
 *
 * @return array{0: array, 1: Subentro, 2: Saldo, 3: Saldo}
 */
function srqConArretratoAgliEredi($test): array
{
    [$s, $anna, $bruno, $carla] = srqCaso();
    $sub = srqRegistra($test, $s, srqCorpo($s, $anna, $bruno, $carla, [], 'eredi'))->fresh();
    $righeArretrato = $sub->saldiDellArretrato();
    $conguaglio = Saldo::where('subentro_id', $sub->id)->where('anagrafica_id', $anna->id)->whereNotIn('id', $righeArretrato)->firstOrFail();
    $arretrato = Saldo::findOrFail($righeArretrato[0]);

    return [$s, $sub, $conguaglio, $arretrato];
}

it('testi R6: il messaggio della modifica o della cancellazione di una riga del conguaglio con l\'arretrato agli eredi non dice «una delle due»', function (string $strada) {
    // `Saldo::FRASE_CONGUAGLIO_CON_ARRETRATO`: con tre eredi le righe del conguaglio sono sei (una coppia per erede) e le dell'arretrato
    // altre sei: «una delle due» è falso come lo era «le due righe».
    [$s, $sub, $riga] = srqConArretratoAgliEredi($this);
    expect(Saldo::where('subentro_id', $sub->id)->count())->toBeGreaterThan(6);

    if ($strada === 'modifica') {
        $this->actingAs($this->user)->patch(route('admin.gestionale.saldi.update', [$s['c']->id, $riga->id]), ['saldo_iniziale' => 100, 'gestione_id' => $s['g']->id])
            ->assertSessionHasErrors('saldo');
    } else {
        $this->actingAs($this->user)->delete(route('admin.gestionale.saldi.destroy', [$s['c']->id, $riga->id]))->assertSessionHasErrors('saldo');
    }

    expect(session('errors')->first('saldo'))->not->toContain('una delle due');
})->with(['modifica' => ['modifica'], 'cancellazione' => ['cancellazione']]);

it('testi R6, controllo: lo stesso messaggio resta sul campo «saldo», dice che conguaglio e arretrato fanno un conto solo, e la riga non si tocca', function (string $strada) {
    // Cambia la parola, non l'effetto: la riga non si modifica né si cancella da sola, e si toglie annullando la successione.
    [$s, , $riga] = srqConArretratoAgliEredi($this);
    $importo = (int) $riga->saldo_iniziale;

    if ($strada === 'modifica') {
        $this->actingAs($this->user)->patch(route('admin.gestionale.saldi.update', [$s['c']->id, $riga->id]), ['saldo_iniziale' => 100, 'gestione_id' => $s['g']->id])
            ->assertSessionHasErrors('saldo');
    } else {
        $this->actingAs($this->user)->delete(route('admin.gestionale.saldi.destroy', [$s['c']->id, $riga->id]))->assertSessionHasErrors('saldo');
    }

    expect(session('errors')->first('saldo'))->toContain('conto solo')
        ->toContain('annullando la successione');
    expect((int) $riga->fresh()->saldo_iniziale)->toBe($importo);
})->with(['modifica' => ['modifica'], 'cancellazione' => ['cancellazione']]);

it('testi R6: il rifiuto di sbloccare una riga dell\'arretrato di una successione agli eredi non dice «una delle due»', function () {
    // Il lucchetto di `SaldoInizialeController::sblocca`: le righe dell'arretrato con tre eredi sono sei, non due.
    [$s, $sub, , $riga] = srqConArretratoAgliEredi($this);
    expect(count($sub->saldiDellArretrato()))->toBeGreaterThan(2);

    $risposta = $this->actingAs($this->user)->post(route('admin.gestionale.saldi.sblocca', [$s['c']->id, $riga->id]));

    expect($risposta->status())->toBe(403);
    expect((string) $risposta->exception?->getMessage())->not->toContain('una delle due');
});

it('testi R6, controllo: il rifiuto di sbloccare una riga dell\'arretrato resta un 403 che parla dell\'arretrato e del piano che la assorbe', function () {
    // Cambia la parola, non l'effetto: il lucchetto lo mette e lo toglie il piano, non si sblocca a mano.
    [$s, , , $riga] = srqConArretratoAgliEredi($this);

    $risposta = $this->actingAs($this->user)->post(route('admin.gestionale.saldi.sblocca', [$s['c']->id, $riga->id]));

    expect($risposta->status())->toBe(403);
    expect((string) $risposta->exception?->getMessage())->toContain('arretrato')
        ->toContain('assorbe');
});
