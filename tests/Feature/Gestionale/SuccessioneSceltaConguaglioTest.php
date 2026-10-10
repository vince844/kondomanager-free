<?php

/**
 * 1.11.0-beta.48, Fase 1 (punti P1, P2, P3 e P4 del progetto): la scelta sul conguaglio della successione, lato server.
 *
 * Da dove nasce: la risposta di Fresco (07/10/2026) e il confronto con il codice. Il conguaglio fra il defunto e gli eredi, che il
 * programma propone di partenza, va contro i due studi che hanno risposto (Gabriele non lo fa mai, Fresco solo se glielo chiedono); per
 * non scriverlo oggi si spunta «Gli eredi hanno regolato il conguaglio fra loro», cioè si dichiara un accordo che non c'è. Decisioni 72
 * e 73: nella successione e nel legato il conguaglio diventa una scelta senza preselezione, «Scrivi il conguaglio» o «Non scriverlo: la
 * posizione resta com'è», con la nota facoltativa; vendita, usufrutto e locazione restano con la casella e la nota obbligatoria.
 *
 * Il caso di riferimento è quello di Fresco e della .44. € 1.200,00 l'anno in dodici rate da € 100,00, rate 1–4 a giornale e non
 * pagate, otto bozze; Ugo muore il 1° maggio; eredi Anna 33,34 %, Bruno 33,33 % e Carla 33,33 % (il «Dividi in parti uguali» del
 * modulo), Anna erede di riferimento, arretrato a nome del defunto.
 * - I giorni dopo il decesso sono 245 (maggio 31 + giugno 30 + luglio 31 + agosto 31 + settembre 30 + ottobre 31 + novembre 30 +
 *   dicembre 31): € 1.200,00 × 245 / 365 = € 805,48. Per quota: 80548 × 33,34 % = 26854,70, 80548 × 33,33 % = 26846,65 due volte;
 *   a 80546 mancano 2 centesimi, che vanno ai resti maggiori (,70 di Anna, poi ,65 di Bruno, primo dei due pari): Anna 26855, Bruno
 *   26847, Carla 26846 (somma 80548).
 * - Le otto bozze (€ 800,00) vanno ad Anna e la sua coppia si rovescia: 26855 − 80000 = −53145, cioè € 531,45 a credito. Bruno
 *   € 268,47 e Carla € 268,46 a debito. Il defunto ha la somma al contrario: −(−53145 + 26847 + 26846) = −548, € 5,48 a credito.
 * - Senza conguaglio le posizioni restano quelle emesse e passate: Ugo le quattro rate, € 400,00; Anna le otto bozze, € 800,00;
 *   Bruno e Carla niente. Totale € 1.200,00.
 * - Con il conguaglio: Ugo 40000 − 548 = 39452 (€ 394,52 = € 1.200,00 × 120 / 365, i 120 giorni fino al 30 aprile), Anna 80000 −
 *   53145 = 26855, Bruno 26847, Carla 26846; totale 39452 + 26855 + 26847 + 26846 = 120000.
 *
 * Cosa presidia: P1, la scelta senza valore di partenza, chiesta dall'azione solo con le coppie e l'arretrato a nome del defunto
 * (campo `conguaglio`, `scrivi` / `non_scrivere`), la nota facoltativa, il registro `conguaglio.scelta`, il rifiuto di «non
 * scrivere» con l'arretrato agli eredi, il legato con la stessa scelta, la vendita con la casella e la nota obbligatoria come oggi;
 * P2, `Subentro::conguaglioRinunciato()` e `conguaglioNonScritto()`, lo storico con lo stato «non scritto» e il campo
 * `conguaglio_non_scritto` nella riga del subentro; P3, `regolato_fuori` con le persone e la cifra di ciascuna, anche per il conguaglio
 * scritto e poi annullato; P4, le frasi di `PianoRate::fraseRegolatoFuori()` e dell'avviso dell'annullamento del passaggio, il rifiuto
 * di annullare l'emissione (con «scrivi»: con «non scrivere» ogni passaggio della .48 ha i suoi piani presi e l'annullamento riesce,
 * decisione 49), e il messaggio dell'annullamento del conguaglio.
 *
 * I controlli, verdi oggi, che devono restarlo: l'anteprima senza la scelta; la successione senza coppie e quella con l'arretrato
 * agli eredi, dove la scelta non si chiede; «scrivi», che scrive le sei righe come oggi; la vendita con la casella, la nota
 * obbligatoria e la rinuncia con la nota; gli stati «proposto» e «rinunciato» dello storico; con una persona sola le tre frasi di
 * oggi («€ 5,48», «le parti hanno già regolato fra loro»); con il conguaglio scritto il rifiuto di annullare l'emissione («il
 * conguaglio ha già regolato», su una vendita di prima della .42: una successione ha sempre i piani presi) e la frase del piano nulla; dopo l'annullamento di un conguaglio scritto «le parti hanno già regolato fra loro».
 *
 * Cosa NON copre: la pagina (P5: il pannello, le due voci, la conferma spenta, `rinunciaConguaglio.ts`, il messaggio dopo la
 * registrazione detto a video, il formato «€ 531,45 a credito» nella coppia del pannello) e i testi di P6 (guide, saldi, erede di
 * riferimento, «il giorno del decesso»); l'annullamento di un passaggio «non scritto» e l'effetto sui piani dopo; la scelta con una
 * successione senza coppie mandata comunque dal client; i test della .44 che registrano la successione con l'arretrato a nome del
 * defunto senza la scelta (SuccessioneTest e altri: con le coppie, dopo questa beta, il server li ferma).
 */

use App\Actions\Subentro\AnnullaPassaggioAction;
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
function scgPersona(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => 'scg' . $n . '-' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'SCGPERSONA' . str_pad((string) $n, 6, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/**
 * Il caso di riferimento: lo scenario della .44 con le rate 1–4 a giornale (`$emesse`) o senza nessuna rata emessa (il piano si
 * ricalcola ancora: niente bozze da spostare, quindi niente coppie), più i tre eredi.
 *
 * @return array{0: array, 1: Anagrafica, 2: Anagrafica, 3: Anagrafica} lo scenario, Anna, Bruno e Carla
 */
function scgCaso(bool $emesse = true): array
{
    $s = ruScenario('prima_rata', 0);
    if ($emesse) {
        ruEmetti($s);
    }

    return [$s, scgPersona($s, 'Anna'), scgPersona($s, 'Bruno'), scgPersona($s, 'Carla')];
}

/** Il corpo della successione di Ugo (1° maggio) ai tre eredi di riferimento, Anna erede di riferimento. */
function scgCorpo(array $s, Anagrafica $anna, Anagrafica $bruno, Anagrafica $carla, array $extra = [], string $arretrato = 'defunto'): array
{
    return array_merge([
        'tipo' => 'successione', 'riga_uscente_id' => $s['rigaV'], 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $anna->id, 'quota' => 33.34], ['anagrafica_id' => $bruno->id, 'quota' => 33.33], ['anagrafica_id' => $carla->id, 'quota' => 33.33]],
        'arretrato' => $arretrato, 'erede_di_riferimento' => $anna->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 123', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta: gli eredi entrano dal decesso',
    ], $extra);
}

/** Il corpo del legato: un legatario solo, l'arretrato resta per forza a nome del defunto. */
function scgCorpoLegato(array $s, Anagrafica $leo, array $extra = []): array
{
    return array_merge([
        'tipo' => 'successione', 'sottotipo' => 'legato', 'riga_uscente_id' => $s['rigaV'], 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $leo->id, 'quota' => 100]], 'arretrato' => 'defunto', 'erede_di_riferimento' => null,
        'copia_autentica' => false, 'estremi_titolo' => 'testamento pubblicato, rep. 55', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Testamento letto: il legatario entra dal decesso',
    ], $extra);
}

/** Il corpo di una vendita il 1° maggio, a Elsa (`a`), con il rogito letto. */
function scgCorpoVendita(array $s, array $extra = []): array
{
    return array_merge(ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-05-01', 100), ['ho_letto' => true, 'nota_cancello' => 'Rogito letto fra le parti'], $extra);
}

/** La registrazione dalla rotta vera, così com'è: la risposta da controllare. */
function scgStore($test, array $s, array $corpo): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $corpo);
}

/** La registrazione che deve riuscire; il passaggio padre appena scritto. */
function scgRegistra($test, array $s, array $corpo): Subentro
{
    scgStore($test, $s, $corpo)->assertSessionHasNoErrors();

    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

/** Il passaggio padre più recente dell'unità. */
function scgUltimo(array $s): Subentro
{
    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

/** La posizione aperta di una persona sull'unità, in centesimi: quote non pagate più righe in saldi non ancora assorbite da un piano. */
function scgAperto(array $s, Anagrafica $p): int
{
    $quote = (int) DB::table('rate_quote')->where('anagrafica_id', $p->id)->where('immobile_id', $s['unita']->id)->sum(DB::raw('importo - importo_pagato'));

    return $quote + (int) DB::table('saldi')->where('anagrafica_id', $p->id)->where('immobile_id', $s['unita']->id)->where('is_applicato', false)->sum('saldo_iniziale');
}

/**
 * Le posizioni di più persone: nome → centesimi, zeri compresi (un erede che non ha niente da pagare è un dato, non un'assenza).
 *
 * @param list<Anagrafica> $persone
 */
function scgPosizioni(array $s, array $persone): array
{
    $out = [];
    foreach ($persone as $p) {
        $out[$p->nome] = scgAperto($s, $p);
    }
    ksort($out);

    return $out;
}

/** Le righe in saldi scritte dai passaggi dell'unità, sommate per persona: nome → centesimi, senza gli zeri. */
function scgSaldi(array $s): array
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

/** Le quote pure del piano a nome di ciascuno: nome → centesimi, senza gli zeri (dove sono finite le bozze). */
function scgQuote(array $s): array
{
    $out = [];
    $quote = DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate_quote.immobile_id', $s['unita']->id)->get(['rate_quote.anagrafica_id', 'rate_quote.importo']);
    foreach ($quote as $q) {
        $out[(int) $q->anagrafica_id] = ($out[(int) $q->anagrafica_id] ?? 0) + (int) $q->importo;
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

/** `conguaglioNonScritto()` (P2), dopo aver detto con una frase che cosa manca se il metodo non c'è ancora. */
function scgNonScritto(Subentro $sub): bool
{
    Assert::assertTrue(method_exists($sub, 'conguaglioNonScritto'), 'Subentro::conguaglioNonScritto() non esiste ancora (P2).');

    return $sub->conguaglioNonScritto();
}

/** Tutto in minuscolo, per le frasi che il progetto cita a metà periodo e il codice può mettere anche a inizio frase o dopo un `lcfirst`. */
function scgMinuscolo(string $testo): string
{
    return mb_strtolower($testo);
}

/** La frase delle tre cifre per erede, come la dice il progetto (P3), con i nomi del caso di riferimento e la gestione dello scenario. */
function scgCifrePerErede(): string
{
    return '€ 531,45 a credito di Anna, € 268,47 a debito di Bruno e € 268,46 a debito di Carla sulla gestione Ordinaria 2026';
}

/**
 * Il passaggio registrato prima della .42, come lo vede la guardia della decisione 49 (senza `piani_presi`), per il rifiuto di
 * annullare l'emissione: il resto del registro, `conguaglio` compreso, non si tocca.
 */
function scgComePrimaDellaQuarantadue(Subentro $sub): void
{
    $registro = $sub->registro;
    unset($registro['piani_presi']);
    $sub->update(['registro' => array_replace($registro, ['versione' => 1])]);
}

/** Il rifiuto di annullare l'emissione della prima rata del piano dello scenario: il messaggio del flash. */
function scgAnnullaEmissione($test, array $s): array
{
    $rata = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 1)->value('id');

    return $test->actingAs($test->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $s['piano']->id, 'rata' => $rata]))
        ->getSession()->get('message');
}

// ---------------------------------------------------------------------------------------------------------------------
// P1 — la scelta, sul server
// ---------------------------------------------------------------------------------------------------------------------

it('P1: senza la scelta la registrazione si ferma sul campo «conguaglio» con la frase del progetto, e non scrive niente', function () {
    // Punto P1 del progetto: la scelta non ha valore di partenza; con l'arretrato a nome del defunto e le coppie proposte (qui tre,
    // € 5,48 la somma) l'azione la chiede, e rifiuta sul campo `conguaglio`.
    [$s, $anna, $bruno, $carla] = scgCaso();
    $righe = ruRighe($s['unita']->id);

    scgStore($this, $s, scgCorpo($s, $anna, $bruno, $carla))
        ->assertSessionHasErrors(['conguaglio' => 'Scegli se scrivere il conguaglio: il programma non lo sceglie al posto tuo.']);

    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0)
        ->and(ruRighe($s['unita']->id))->toBe($righe)
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->count())->toBe(0)
        // Le otto bozze non sono passate ad Anna: Ugo ha ancora tutte e dodici le quote, 12 × 10000 = 120000, e il piano non è toccato.
        ->and(scgQuote($s))->toBe(['Venditore Ugo' => 120000]);
});

it('P1, controllo: l\'anteprima non chiede la scelta, e il pannello mostra le tre coppie su cui l\'amministratore sceglie', function () {
    // Punto P1 del progetto: «lo sa solo l'azione, dopo l'anteprima, quindi è lì che si chiede». L'anteprima senza la scelta riesce.
    [$s, $anna, $bruno, $carla] = scgCaso();

    $a = ruAnteprima($this, $s, scgCorpo($s, $anna, $bruno, $carla));

    // Le coppie: Anna 26855 − 80000 = −53145; Bruno 26847; Carla 26846 (somma 548).
    expect(collect($a['rate']['conguaglio']['coppie'])->mapWithKeys(fn ($c) => [$c['entrante_nome'] => (int) $c['importo']])->all())
        ->toBe(['Anna' => -53145, 'Bruno' => 26847, 'Carla' => 26846]);
});

it('P1: un valore della scelta diverso da «scrivi» e «non_scrivere» si rifiuta sul campo «conguaglio»', function () {
    // Punto P1 del progetto: il campo vale `scrivi` o `non_scrivere`, e nient'altro.
    [$s, $anna, $bruno, $carla] = scgCaso();
    $corpo = scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'forse']);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $corpo)
        ->assertStatus(422)->assertJsonValidationErrors(['conguaglio']);
    scgStore($this, $s, $corpo)->assertSessionHasErrors('conguaglio');
    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0);
});

it('P1, controllo: senza coppie la scelta non si chiede, e la registrazione riesce come oggi', function () {
    // Punto P1 del progetto: la scelta si chiede «solo se il pannello propone delle coppie». Nessuna rata emessa: il piano si ricalcola
    // ancora, non ci sono bozze da spostare e non c'è niente da conguagliare.
    [$s, $anna, $bruno, $carla] = scgCaso(emesse: false);
    $corpo = scgCorpo($s, $anna, $bruno, $carla);

    expect(ruAnteprima($this, $s, $corpo)['rate']['conguaglio']['coppie'] ?? [])->toBe([]);
    $sub = scgRegistra($this, $s, $corpo);

    expect($sub->successione())->toBeTrue()
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->count())->toBe(0);
});

it('P1, controllo: con l\'arretrato agli eredi la scelta non si chiede, e coppie e arretrato si scrivono come oggi', function () {
    // Punto P1 del progetto: la scelta si chiede solo con l'arretrato a nome del defunto. Con l'arretrato agli eredi coppia e arretrato
    // sono un conto solo. Per erede, quota dell'anno intero − bozze passate: Anna 33,34 % × 120000 = 40008, − 80000 = −39992; Bruno e
    // Carla 33,33 % × 120000 = 39996; Ugo la somma al contrario, −(−39992 + 39996 + 39996) = −40000.
    [$s, $anna, $bruno, $carla] = scgCaso();

    scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, arretrato: 'eredi'));

    expect(scgSaldi($s))->toBe(['Anna' => -39992, 'Bruno' => 39996, 'Carla' => 39996, 'Venditore Ugo' => -40000])
        // In fondo ciascun erede paga la sua quota dell'anno intero (Anna 80000 − 39992 = 40008) e il defunto chiude a zero.
        ->and(scgPosizioni($s, [$s['v'], $anna, $bruno, $carla]))->toBe(['Anna' => 40008, 'Bruno' => 39996, 'Carla' => 39996, 'Venditore Ugo' => 0]);
});

it('P1: «non scrivere» senza nota si accetta: nessuna riga in saldi, e le posizioni restano com\'erano (Ugo € 400,00, Anna € 800,00, Bruno e Carla zero)', function () {
    // Punto P1 del progetto: con `non_scrivere` le coppie non si scrivono e la nota è facoltativa.
    [$s, $anna, $bruno, $carla] = scgCaso();

    $sub = scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']));

    expect($sub->nota_conguaglio)->toBeNull()
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->count())->toBe(0)
        // Ugo le quattro rate emesse, 4 × 10000 = 40000; Anna le otto bozze, 8 × 10000 = 80000; Bruno e Carla niente; totale 120000.
        ->and(scgPosizioni($s, [$s['v'], $anna, $bruno, $carla]))->toBe(['Anna' => 80000, 'Bruno' => 0, 'Carla' => 0, 'Venditore Ugo' => 40000]);
});

it('P1: «non scrivere» scrive nel registro la scelta «non_scritto», e il passaggio si legge come rinunciato e come non scritto', function () {
    // Punto P1 del progetto (`registro.conguaglio = {scelta: non_scritto}`) e punto P2 (`conguaglioRinunciato()` vera anche senza nota).
    [$s, $anna, $bruno, $carla] = scgCaso();

    $sub = scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']))->fresh();

    expect($sub->registro['conguaglio'] ?? null)->toBe(['scelta' => 'non_scritto'])
        ->and($sub->conguaglioRinunciato())->toBeTrue()
        ->and(scgNonScritto($sub))->toBeTrue();
});

it('P1: «non scrivere» lascia le bozze all\'erede di riferimento e a nome del defunto € 400,00, senza la coppia da € 5,48', function () {
    // Punto P1 del progetto, «le rate emesse restano a Ugo, quelle in bozza passano ad Anna». Il registro dell'arretrato dice quanto resta
    // a Ugo: senza la coppia i 40000 delle quattro rate, non i 40000 − 548 = 39452 del conguaglio scritto.
    [$s, $anna, $bruno, $carla] = scgCaso();

    $sub = scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']))->fresh();

    expect(scgQuote($s))->toBe(['Anna' => 80000, 'Venditore Ugo' => 40000])
        ->and($sub->registro['arretrato']['resta'])->toBe(40000)
        ->and($sub->registro['arretrato']['saldi'])->toBe([]);
});

it('P1, controllo: «scrivi» scrive le sei righe del conguaglio, tre per erede contro il defunto', function () {
    // Punto P1 del progetto: con `scrivi` le coppie si scrivono come oggi. Sei righe: per ciascun erede la sua e quella speculare di
    // Ugo. Per persona: Anna −53145, Bruno 26847, Carla 26846, Ugo −548; posizioni: Anna 80000 − 53145 = 26855, Ugo 40000 − 548 = 39452.
    [$s, $anna, $bruno, $carla] = scgCaso();

    scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'scrivi']));

    expect(DB::table('saldi')->whereNotNull('subentro_id')->count())->toBe(6)
        ->and(scgSaldi($s))->toBe(['Anna' => -53145, 'Bruno' => 26847, 'Carla' => 26846, 'Venditore Ugo' => -548])
        ->and(scgPosizioni($s, [$s['v'], $anna, $bruno, $carla]))->toBe(['Anna' => 26855, 'Bruno' => 26847, 'Carla' => 26846, 'Venditore Ugo' => 39452]);
});

it('P1: «scrivi» scrive nel registro la scelta «scritto», senza nota e senza «regolato fuori», e il passaggio non è né rinunciato né non scritto', function () {
    // Punto P1 del progetto (`registro.conguaglio = {scelta: scritto}`) e punto P2.
    [$s, $anna, $bruno, $carla] = scgCaso();

    $sub = scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'scrivi']))->fresh();

    expect($sub->registro['conguaglio'] ?? null)->toBe(['scelta' => 'scritto'])
        ->and($sub->nota_conguaglio)->toBeNull()
        ->and($sub->registro)->not->toHaveKey('regolato_fuori')
        ->and($sub->conguaglioRinunciato())->toBeFalse()
        ->and(scgNonScritto($sub))->toBeFalse();
});

it('P1: «non scrivere» con l\'arretrato agli eredi si rifiuta con la frase del progetto, e non scrive niente', function () {
    // Punto P1 del progetto: con l'arretrato agli eredi la scelta non c'è, e `non_scrivere` si rifiuta sul campo `conguaglio`.
    [$s, $anna, $bruno, $carla] = scgCaso();
    $righe = ruRighe($s['unita']->id);

    scgStore($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere'], arretrato: 'eredi'))
        ->assertSessionHasErrors(['conguaglio' => 'Con l\'arretrato agli eredi il conguaglio e l\'arretrato fanno un conto solo: per non scrivere il conguaglio lascia l\'arretrato a nome del defunto.']);

    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0)
        ->and(ruRighe($s['unita']->id))->toBe($righe)
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->count())->toBe(0);
});

it('P1, decisione 73: il legato senza la scelta si ferma sul campo «conguaglio», come la successione', function () {
    // Punto P1 del progetto (la scelta vale per la successione e per il legato, decisione 73). Il legatario ha le bozze (80000) contro
    // gli 80548 dei suoi giorni: la coppia è € 5,48 a suo debito, e la scelta è dovuta.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $leo = scgPersona($s, 'Leo');

    scgStore($this, $s, scgCorpoLegato($s, $leo))
        ->assertSessionHasErrors(['conguaglio' => 'Scegli se scrivere il conguaglio: il programma non lo sceglie al posto tuo.']);

    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0)
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->count())->toBe(0);
});

it('P1, decisione 73: il legato con «non scrivere» senza nota non scrive la coppia, e Leo ha le bozze (€ 800,00) mentre Ugo tiene i € 400,00', function () {
    // Punto P1 del progetto, legato. Senza conguaglio: Ugo le quattro rate, 40000; Leo le otto bozze, 80000 (cambia il nome, non
    // l'importo).
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $leo = scgPersona($s, 'Leo');

    $sub = scgRegistra($this, $s, scgCorpoLegato($s, $leo, ['conguaglio' => 'non_scrivere']))->fresh();

    expect($sub->legato())->toBeTrue()
        ->and($sub->nota_conguaglio)->toBeNull()
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->count())->toBe(0)
        ->and(scgQuote($s))->toBe(['Leo' => 80000, 'Venditore Ugo' => 40000])
        ->and($sub->registro['conguaglio'] ?? null)->toBe(['scelta' => 'non_scritto'])
        ->and(scgNonScritto($sub))->toBeTrue();
});

it('P1, decisione 73: il legato con «scrivi» scrive la coppia di € 5,48 e la scelta «scritto»', function () {
    // Punto P1 del progetto, legato. I giorni di Leo: € 805,48; le bozze 800,00 → Leo 80548 − 80000 = 548 a debito, Ugo −548.
    // Posizioni: Leo 80000 + 548 = 80548, Ugo 40000 − 548 = 39452.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $leo = scgPersona($s, 'Leo');

    $sub = scgRegistra($this, $s, scgCorpoLegato($s, $leo, ['conguaglio' => 'scrivi']))->fresh();

    expect(scgSaldi($s))->toBe(['Leo' => 548, 'Venditore Ugo' => -548])
        ->and(scgPosizioni($s, [$s['v'], $leo]))->toBe(['Leo' => 80548, 'Venditore Ugo' => 39452])
        ->and($sub->registro['conguaglio'] ?? null)->toBe(['scelta' => 'scritto']);
});

it('P1, controllo: la vendita con la rinuncia ha la nota obbligatoria come oggi, e senza nota si ferma sul campo «nota_conguaglio»', function () {
    // Punto P1 del progetto: «vendita, usufrutto e locazione restano con la casella e la nota obbligatoria di oggi».
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    scgStore($this, $s, scgCorpoVendita($s, ['rinuncia_conguaglio' => true]))
        ->assertSessionHasErrors(['nota_conguaglio' => 'Hai rinunciato al conguaglio proposto: scrivi perché (almeno dieci caratteri).']);

    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0);
});

it('P1, controllo: la vendita con la rinuncia e la nota non scrive la coppia, ricorda la nota e la registra come rinuncia', function () {
    // Punto P1 del progetto, vendita: la rinuncia resta come oggi. Elsa avrebbe pagato 80548 − 80000 = 548, € 5,48.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    $sub = scgRegistra($this, $s, scgCorpoVendita($s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito fra le parti']))->fresh();

    expect($sub->nota_conguaglio)->toBe('Regolato nel rogito fra le parti')
        ->and($sub->conguaglioRinunciato())->toBeTrue()
        ->and(DB::table('saldi')->whereNotNull('subentro_id')->count())->toBe(0)
        ->and(array_column($sub->registro['regolato_fuori'], 'importo'))->toBe([548]);
});

it('P1, controllo: la vendita senza la casella scrive la coppia e non chiede nessuna scelta', function () {
    // Punto P1 del progetto: la scelta a due voci è solo della successione e del legato. Coppia: Elsa 548 a debito, Ugo −548.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    scgRegistra($this, $s, scgCorpoVendita($s));

    expect(scgSaldi($s))->toBe(['Acquirente Elsa' => 548, 'Venditore Ugo' => -548]);
});

// ---------------------------------------------------------------------------------------------------------------------
// P2 — chi la legge
// ---------------------------------------------------------------------------------------------------------------------

it('P2: la rinuncia della vendita non è un «non scritto»: conguaglioNonScritto() distingue la scelta della successione dalla casella degli altri tipi', function () {
    // Punto P2 del progetto: «un metodo nuovo, conguaglioNonScritto(), distingue la scelta della successione dalla rinuncia degli altri tipi».
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    $sub = scgRegistra($this, $s, scgCorpoVendita($s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito fra le parti']))->fresh();

    expect($sub->conguaglioRinunciato())->toBeTrue()
        ->and(scgNonScritto($sub))->toBeFalse();
});

it('P2: conguaglioRinunciato() — la nota di prima conta come oggi, e la scelta «non scritto» conta anche senza nota', function (?string $nota, ?array $conguaglio, bool $atteso) {
    // Punto P2 del progetto: «vera anche con registro.conguaglio.scelta = non_scritto senza nota (i passaggi di prima si leggono come oggi)».
    $registro = ['versione' => 3] + ($conguaglio === null ? [] : ['conguaglio' => $conguaglio]);
    $sub = (new Subentro())->forceFill(['nota_conguaglio' => $nota, 'registro' => $registro]);

    expect($sub->conguaglioRinunciato())->toBe($atteso);
})->with([
    'passaggio di prima, nessuna rinuncia' => [null, null, false],
    'passaggio di prima, una nota di rinuncia' => ['Regolato nel rogito fra le parti', null, true],
    'una nota fatta di soli spazi non è una rinuncia' => ['   ', null, false],
    'scelta «scritto», nessuna nota' => [null, ['scelta' => 'scritto'], false],
    'scelta «non scritto», nessuna nota' => [null, ['scelta' => 'non_scritto'], true],
    'scelta «non scritto», una nota facoltativa' => ['Gli eredi si accordano da soli', ['scelta' => 'non_scritto'], true],
]);

it('P2: conguaglioNonScritto() è vera solo con la scelta «non_scritto» nel registro, anche con una nota', function (?string $nota, ?array $conguaglio, bool $atteso) {
    // Punto P2 del progetto: la nota di rinuncia dei passaggi di prima e degli altri tipi non fa un «non scritto».
    $registro = ['versione' => 3] + ($conguaglio === null ? [] : ['conguaglio' => $conguaglio]);
    $sub = (new Subentro())->forceFill(['nota_conguaglio' => $nota, 'registro' => $registro]);

    expect(scgNonScritto($sub))->toBe($atteso);
})->with([
    'passaggio di prima, nessuna rinuncia' => [null, null, false],
    'passaggio di prima, una nota di rinuncia' => ['Regolato nel rogito fra le parti', null, false],
    'scelta «scritto»' => [null, ['scelta' => 'scritto'], false],
    'scelta «non scritto», nessuna nota' => [null, ['scelta' => 'non_scritto'], true],
    'scelta «non scritto», una nota facoltativa' => ['Gli eredi si accordano da soli', ['scelta' => 'non_scritto'], true],
]);

it('P2: lo storico dice il conguaglio «non scritto», senza nota e senza righe', function () {
    // Punto P2 del progetto: «nello storico lo stato si chiama "non scritto"»; prima del `rinunciato` della nota.
    [$s, $anna, $bruno, $carla] = scgCaso();
    scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']));

    $conguaglio = app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'][0]['conguaglio'];

    expect($conguaglio['stato'])->toBe('non_scritto')
        ->and($conguaglio['nota'])->toBeNull()
        ->and($conguaglio['importo'])->toBe(0)
        ->and($conguaglio['per_entrante'])->toBe([]);
});

it('P2: lo storico dice «non scritto» anche con la nota facoltativa, e la nota resta sul passaggio', function () {
    // Punto P2 del progetto: «la scheda dice "Conguaglio non scritto", con la nota se c'è». La nota facoltativa si salva con «non scrivere».
    [$s, $anna, $bruno, $carla] = scgCaso();
    $sub = scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere', 'nota_conguaglio' => 'Gli eredi si accordano da soli']));

    $conguaglio = app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'][0]['conguaglio'];

    expect($sub->fresh()->nota_conguaglio)->toBe('Gli eredi si accordano da soli')
        ->and($conguaglio['stato'])->toBe('non_scritto')
        ->and($conguaglio['nota'])->toBe('Gli eredi si accordano da soli');
});

it('P2: nella riga del subentro lo storico porta «conguaglio_non_scritto» per ogni riga del passaggio, vero con «non scrivere» e falso con «scrivi»', function (string $scelta, bool $atteso) {
    // Punto P2 del progetto: la scheda non legge più la sola nota, ma un campo calcolato dal server. Le righe del passaggio sono quattro:
    // Ugo (chi esce) e i tre eredi.
    [$s, $anna, $bruno, $carla] = scgCaso();
    scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => $scelta]));

    $righe = array_values(array_filter(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['righe'], fn (array $r) => $r['subentro'] !== null));

    expect($righe)->toHaveCount(4)
        ->and(array_map(fn (array $r) => $r['subentro']['conguaglio_non_scritto'] ?? null, $righe))->toBe([$atteso, $atteso, $atteso, $atteso]);
})->with([
    'non_scrivere' => ['non_scrivere', true],
    'scrivi' => ['scrivi', false],
]);

it('P2, controllo: lo storico tiene gli stati di oggi — «proposto» con le righe scritte, «rinunciato» con la rinuncia della vendita e la sua nota', function () {
    // Punto P2 del progetto: ciò che non è la scelta della successione resta com'è.
    [$s, $anna, $bruno, $carla] = scgCaso();
    scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'scrivi']));
    expect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'][0]['conguaglio']['stato'])->toBe('proposto');

    $v = ruScenario('prima_rata', 0);
    ruEmetti($v);
    scgRegistra($this, $v, scgCorpoVendita($v, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito fra le parti']));
    $conguaglio = app(StoricoTitolarita::class)->perImmobile($v['unita']->fresh())['subentri'][0]['conguaglio'];

    expect($conguaglio['stato'])->toBe('rinunciato')
        ->and($conguaglio['nota'])->toBe('Regolato nel rogito fra le parti');
});

it('P1 e P5: il messaggio dopo la registrazione dice «non_scritto» vero con «non scrivere» e nessuna coppia scritta, falso con «scrivi»', function (string $scelta, bool $atteso, int $coppie) {
    // Punto P1/P5 del progetto, lato server: il flash `passaggio_registrato` porta il campo `non_scritto` (bool) con cui la pagina
    // dice «Nessuna riga in saldi: il conguaglio non è stato scritto, e la posizione resta com'è.». Le coppie scritte: 0 oppure 3.
    [$s, $anna, $bruno, $carla] = scgCaso();

    $flash = scgStore($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => $scelta]))->assertSessionHasNoErrors()->getSession()->get('passaggio_registrato');

    expect($flash)->toHaveKey('non_scritto')
        ->and($flash['non_scritto'])->toBe($atteso)
        ->and($flash['coppie'])->toBe($coppie);
})->with([
    'non_scrivere' => ['non_scrivere', true, 0],
    'scrivi' => ['scrivi', false, 3],
]);

// ---------------------------------------------------------------------------------------------------------------------
// P3 — le cifre per persona
// ---------------------------------------------------------------------------------------------------------------------

it('P3: con «non scrivere» il registro porta in «regolato fuori», per la gestione, le persone con la cifra di ciascuna e il segno della coppia', function () {
    // Punto P3 del progetto: `regolato_fuori` porta, per ogni gestione, anche le persone (anagrafica_id, nome, importo; positivo = debito
    // di quella persona). Anna −53145, Bruno 26847, Carla 26846; la somma, 548, è l'importo della gestione (€ 5,48).
    [$s, $anna, $bruno, $carla] = scgCaso();

    $sub = scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']))->fresh();
    $fuori = $sub->registro['regolato_fuori'] ?? [];

    expect($fuori)->toHaveCount(1)
        ->and($fuori[0]['gestione_id'])->toBe((int) $s['g']->id)
        ->and($fuori[0]['importo'])->toBe(548);
    $persone = $fuori[0]['persone'] ?? [];
    expect(collect($persone)->mapWithKeys(fn (array $p) => [$p['nome'] => $p['importo']])->sortKeys()->all())->toBe(['Anna' => -53145, 'Bruno' => 26847, 'Carla' => 26846])
        ->and(collect($persone)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe([(int) $anna->id, (int) $bruno->id, (int) $carla->id])
        ->and(array_keys($persone[0] ?? []))->toEqualCanonicalizing(['anagrafica_id', 'nome', 'importo'])
        ->and(array_sum(array_column($persone, 'importo')))->toBe($fuori[0]['importo']);
});

it('P3: anche con una persona sola le persone ci sono — la rinuncia della vendita porta Elsa con € 5,48 a debito', function () {
    // Punto P3 del progetto: «ogni gestione porta anche le persone»; con una persona sola. Elsa: 80548 − 80000 = 548.
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);

    $sub = scgRegistra($this, $s, scgCorpoVendita($s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito fra le parti']))->fresh();
    $fuori = $sub->registro['regolato_fuori'];

    expect($fuori)->toHaveCount(1)
        ->and($fuori[0]['importo'])->toBe(548)
        ->and($fuori[0]['persone'] ?? null)->toEqual([['anagrafica_id' => (int) $s['a']->id, 'nome' => 'Acquirente Elsa', 'importo' => 548]]);
});

it('P3, controllo: con una persona sola le frasi restano come oggi, «€ 5,48» e «le parti hanno già regolato fra loro», in tutti e tre i messaggi', function () {
    // Punto P3 del progetto: «con una persona sola restano come oggi». La vendita con la rinuncia: la frase del piano, l'avviso
    // dell'annullamento del passaggio e il rifiuto di annullare l'emissione (il passaggio visto come registrato prima della .42).
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $sub = scgRegistra($this, $s, scgCorpoVendita($s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito fra le parti']));

    expect($s['piano']->fresh()->fraseRegolatoFuori())->toBe('Le parti hanno già regolato fra loro € 5,48 sulla gestione Ordinaria 2026: ricalcolando, il condominio addebita a chi entra i suoi giorni, e quell\'accordo va rifatto fra le parti.')
        ->and(implode(' | ', app(AnnullaPassaggioAction::class)->avvisi($sub->fresh())))->toBe('Le parti hanno già regolato fra loro € 5,48 sulla gestione Ordinaria 2026: se il passaggio si registra di nuovo e il piano si ricalcola, il condominio addebita a chi entra i suoi giorni, e quell\'accordo va rifatto fra le parti.');

    scgComePrimaDellaQuarantadue($sub);
    $m = scgAnnullaEmissione($this, $s);
    expect($m['type'])->toBe('error')
        ->and($m['message'])->toContain('che rifarebbe per giorni ciò che le parti hanno già regolato fra loro.');
});

// ---------------------------------------------------------------------------------------------------------------------
// P4 — le frasi dei messaggi che rifiutano o avvisano
// ---------------------------------------------------------------------------------------------------------------------

it('P4: la frase del piano dice che il conguaglio della successione non è stato scritto, con le cifre per erede e «a ciascuno i suoi giorni»', function () {
    // Punto P4 del progetto per `PianoRate::fraseRegolatoFuori()`: non «€ 5,48» (la somma di coppie di segno diverso) e non «le parti
    // hanno già regolato fra loro», ma il conguaglio non scritto e le tre cifre (P3); la conseguenza è «a ciascuno», non «a chi entra».
    [$s, $anna, $bruno, $carla] = scgCaso();
    scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']));

    $frase = (string) $s['piano']->fresh()->fraseRegolatoFuori();

    expect(scgMinuscolo($frase))->toContain('il conguaglio della successione non è stato scritto');
    expect($frase)->toContain(scgCifrePerErede())
        ->toContain('il condominio addebita a ciascuno i suoi giorni');
    expect($frase)->not->toContain('€ 5,48');
    expect($frase)->not->toContain('hanno già regolato fra loro');
    expect($frase)->not->toContain('a chi entra');
});

it('P4: il rifiuto di ricalcolare il piano e i rimedi del fermo usano la stessa frase del conguaglio non scritto', function () {
    // Punto P4 del progetto: `fraseRegolatoFuori()` è letta da `fraseConguagliato()` (il rifiuto del ricalcolo) e da `rimediDelFermo()`.
    [$s, $anna, $bruno, $carla] = scgCaso();
    scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']));
    $piano = $s['piano']->fresh();

    $rifiuto = (string) $piano->fraseConguagliato('Ricalcolarlo');
    $rimedi = implode(' | ', $piano->rimediDelFermo());

    expect(scgMinuscolo($rifiuto))->toContain('il conguaglio della successione non è stato scritto');
    expect($rifiuto)->toContain(scgCifrePerErede());
    expect($rifiuto)->not->toContain('hanno già regolato fra loro');
    // Mutazione M25 della Fase 1: anche il primo periodo, che diceva «ciò che quel conguaglio ha già regolato».
    expect($rifiuto)->toContain('Ricalcolarlo rifarebbe per giorni la posizione che quel passaggio ha lasciato com\'era');
    expect($rifiuto)->not->toContain('ciò che quel conguaglio ha già regolato');
    expect(scgMinuscolo($rimedi))->toContain('il conguaglio della successione non è stato scritto');
    expect($rimedi)->not->toContain('hanno già regolato fra loro');
});

it('P4: l\'avviso dell\'annullamento del passaggio dice che il conguaglio non è stato scritto, con le cifre per erede e «a ciascuno i suoi giorni»', function () {
    // Punto P4 del progetto per `AnnullaPassaggioAction::avvisi()` (e per lo storico, che lo porta alla scheda).
    [$s, $anna, $bruno, $carla] = scgCaso();
    $sub = scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']));

    $avvisi = implode(' | ', app(AnnullaPassaggioAction::class)->avvisi($sub->fresh()));
    $dalloStorico = implode(' | ', app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'][0]['annullabile']['avvisi']);

    expect(scgMinuscolo($avvisi))->toContain('il conguaglio della successione non è stato scritto');
    expect($avvisi)->toContain(scgCifrePerErede())
        ->toContain('il condominio addebita a ciascuno i suoi giorni');
    expect($avvisi)->not->toContain('€ 5,48');
    expect($avvisi)->not->toContain('hanno già regolato fra loro');
    expect($avvisi)->not->toContain('a chi entra');
    expect($dalloStorico)->toBe($avvisi);
});

it('P4 e decisione 49: con il conguaglio non scritto di una successione della .48 l\'annullamento dell\'emissione non si ferma per il passaggio, e la rata torna in bozza', function () {
    // Rilievo R3 della Fase 1-bis: il rifiuto dell'annullamento dell'emissione guarda solo i passaggi registrati prima della .42, senza
    // `piani_presi` (decisione 49: il piano risulta preso grazie a una scrittura entro l'ora del passaggio). Ogni passaggio dalla .42 in
    // poi scrive `piani_presi`, e la scelta «non scritto» nasce con la .48: i rami del rifiuto scritti nella Fase 1 per il «non scritto»
    // non si raggiungevano con nessun dato vero, e sono stati tolti (il rifiuto è tornato com'era alla .47); il test di prima ci arrivava
    // togliendo `piani_presi` a un passaggio della .48 (uno stato che il programma non produce). Qui si guarda ciò che
    // succede davvero: il passaggio «non scritto» ha i suoi piani presi, e annullare l'emissione di una rata riesce, come per ogni
    // passaggio della .42 in poi (annullare l'emissione e rifarla dà le stesse quote).
    [$s, $anna, $bruno, $carla] = scgCaso();
    $sub = scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'non_scrivere']))->fresh();
    $rata = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 1)->value('id');
    // La premessa: è un passaggio della .48 (ha i suoi piani presi, fra cui il piano dello scenario) e il conguaglio è non scritto.
    expect(\App\Models\Gestionale\PianoRate::haIPianiPresi($sub))->toBeTrue()
        ->and(array_map('intval', $sub->registro['piani_presi']))->toContain((int) $s['piano']->id)
        ->and(scgNonScritto($sub))->toBeTrue()
        ->and(DB::table('rate')->where('id', $rata)->value('stato'))->toBe('emessa');

    $m = scgAnnullaEmissione($this, $s);

    // La rata 1 era a giornale (la scrittura dell'emissione): dopo l'annullamento è una bozza, senza scrittura sulle sue quote.
    expect($m['type'])->toBe('success')
        ->and($m['message'])->toContain('Emissione annullata')
        ->and(DB::table('rate')->where('id', $rata)->value('stato'))->toBe('bozza')
        ->and(DB::table('rate_quote')->where('rata_id', $rata)->whereNotNull('scrittura_contabile_id')->count())->toBe(0);
    expect($m['message'])->not->toContain('prima della versione 1.11.0-beta.42');
});

it('P4, controllo: il rifiuto di annullare l\'emissione con il conguaglio scritto dice, come oggi, che il conguaglio ha già regolato', function () {
    // Punto P4 del progetto: con il conguaglio scritto il piano è preso dal conguaglio come per ogni passaggio. Il rifiuto si raggiunge
    // solo con un passaggio registrato prima della .42 (senza `piani_presi`): una vendita, perché la successione nasce con la .44 e ha
    // sempre i piani presi (rilievo denaro-g3 del giro sulle correzioni: il controllo stava su una successione, un dato impossibile).
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $sub = scgRegistra($this, $s, scgCorpoVendita($s));
    scgComePrimaDellaQuarantadue($sub);

    $m = scgAnnullaEmissione($this, $s);

    expect($m['type'])->toBe('error')
        ->and($m['message'])->toContain('che rifarebbe per giorni ciò che il conguaglio ha già regolato.');
});

it('P4, controllo: con il conguaglio scritto la frase del piano non parla di un conguaglio non scritto né di una rinuncia', function () {
    // Punto P4 del progetto: con «scrivi» nessuna delle frasi dei messaggi di rifiuto cambia, e non c'è niente «fuori» da dire.
    [$s, $anna, $bruno, $carla] = scgCaso();
    $sub = scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'scrivi']));

    expect($s['piano']->fresh()->fraseRegolatoFuori())->toBeNull()
        ->and(app(AnnullaPassaggioAction::class)->avvisi($sub->fresh()))->toBe([]);
});

it('P3: il conguaglio scritto e poi annullato dallo storico porta le persone nel registro, con la cifra di ciascuna', function () {
    // Punto P3 del progetto: `AnnullaConguaglioAction` scrive `regolato_fuori` con le persone, dalle righe che toglie. Le righe che toglie
    // sono le sei del conguaglio; quelle degli eredi: Anna −53145, Bruno 26847, Carla 26846 (somma 548, € 5,48).
    [$s, $anna, $bruno, $carla] = scgCaso();
    $sub = scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'scrivi']));

    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $sub]), ['nota_annullamento_conguaglio' => 'Gli eredi hanno regolato fra loro'])
        ->assertSessionHasNoErrors();
    $fuori = $sub->fresh()->registro['regolato_fuori'];

    expect(scgSaldi($s))->toBe([])
        ->and($fuori)->toHaveCount(1)
        ->and($fuori[0]['importo'])->toBe(548);
    $persone = $fuori[0]['persone'] ?? [];
    expect(collect($persone)->mapWithKeys(fn (array $p) => [$p['nome'] => $p['importo']])->sortKeys()->all())->toBe(['Anna' => -53145, 'Bruno' => 26847, 'Carla' => 26846])
        ->and(collect($persone)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->sort()->values()->all())->toBe([(int) $anna->id, (int) $bruno->id, (int) $carla->id]);
});

it('P3 e P4: il messaggio dell\'annullamento del conguaglio dice le cifre per erede invece di «€ 5,48», e l\'accordo fra le parti resta', function () {
    // Punti P3 e P4 del progetto: nel messaggio che segue l'annullamento del conguaglio le tre cifre al posto della somma di coppie di
    // segno diverso. L'accordo fra le parti, lì, si dichiara davvero: la frase resta.
    [$s, $anna, $bruno, $carla] = scgCaso();
    $sub = scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'scrivi']));

    $messaggio = $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $sub]), ['nota_annullamento_conguaglio' => 'Gli eredi hanno regolato fra loro'])
        ->assertSessionHasNoErrors()->getSession()->get('message')['message'];

    expect($messaggio)->toContain(scgCifrePerErede())
        ->toContain('l\'accordo fra le parti');
    expect($messaggio)->not->toContain('€ 5,48');
});

it('P4, controllo: dopo l\'annullamento del conguaglio scritto la frase del piano e l\'avviso dicono ancora che le parti hanno regolato fra loro', function () {
    // Punto P4 del progetto: «per un conguaglio scritto e poi annullato dallo storico "le parti hanno regolato fra loro" resta (lì un
    // accordo si dichiara davvero)».
    [$s, $anna, $bruno, $carla] = scgCaso();
    $sub = scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'scrivi']));
    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $sub]), ['nota_annullamento_conguaglio' => 'Gli eredi hanno regolato fra loro'])
        ->assertSessionHasNoErrors();

    expect((string) $s['piano']->fresh()->fraseRegolatoFuori())->toStartWith('Le parti hanno già regolato fra loro ')
        ->and(implode(' | ', app(AnnullaPassaggioAction::class)->avvisi($sub->fresh())))->toStartWith('Le parti hanno già regolato fra loro ');
});

it('P3 e P4: dopo l\'annullamento del conguaglio scritto la frase del piano e l\'avviso hanno le cifre per erede e non la somma', function () {
    // Punti P3 e P4 del progetto: «le parti hanno regolato fra loro» resta, con le cifre per persona.
    [$s, $anna, $bruno, $carla] = scgCaso();
    $sub = scgRegistra($this, $s, scgCorpo($s, $anna, $bruno, $carla, ['conguaglio' => 'scrivi']));
    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $sub]), ['nota_annullamento_conguaglio' => 'Gli eredi hanno regolato fra loro'])
        ->assertSessionHasNoErrors();

    $frase = (string) $s['piano']->fresh()->fraseRegolatoFuori();
    $avvisi = implode(' | ', app(AnnullaPassaggioAction::class)->avvisi($sub->fresh()));

    expect($frase)->toContain(scgCifrePerErede());
    expect($frase)->not->toContain('€ 5,48');
    expect($avvisi)->toContain(scgCifrePerErede());
    expect($avvisi)->not->toContain('€ 5,48');
});
