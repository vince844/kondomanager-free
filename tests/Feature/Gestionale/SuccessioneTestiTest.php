<?php

/**
 * 1.11.0-beta.48 — i testi lato server della successione (P6 del progetto) e la cifra «a credito» della coppia (P5).
 *
 * Nasce da: il confronto della risposta di Fresco (forum t=150, 07/10/2026) con il codice, e la Fase 0.2 della .48
 * (`docs/piano_esecutivo_beta48.md`): una dozzina di frasi della successione, nate nella .44, sono false o fuorvianti. Le più
 * serie: le righe di saldo degli altri eredi «entrano nel piano dopo», mentre il piano rate prende solo i saldi della stessa
 * gestione; il conguaglio «che gli eredi rinunciano», quando per non scriverlo basta non scriverlo; la parentesi «credito a chi
 * esce, debito a chi entra» sulle righe del conguaglio, falsa per l'erede di riferimento (la sua riga è un credito di chi entra);
 * «la data dell'atto» per un decesso; «le due righe» dove sono sei; il rifiuto dell'annullamento che manda a «se gli eredi hanno
 * regolato fra loro»; e le due frasi della .42 che scrivono «a Anna» (decisione 73, punto 4).
 *
 * Il caso di riferimento è quello di Fresco e della .44: € 1.200,00 l'anno su una voce del «Proprietario», dodici rate da
 * € 100,00, rate 1–4 a giornale e non pagate, Ugo muore il 1° maggio, eredi Anna 33,34 %, Bruno e Carla 33,33 %, Anna erede di
 * riferimento, arretrato a nome del defunto. Le cifre, rifatte a mano:
 * - i giorni dopo il decesso sono 245 (maggio 31, giugno 30, luglio 31, agosto 31, settembre 30, ottobre 31, novembre 30,
 *   dicembre 31), su 365: € 1.200,00 × 245 / 365 = € 805,48;
 * - per quota, a centesimi: 80548 × 33,34 % = 26854,70 e 80548 × 33,33 % = 26846,65 (due volte). Le parti intere sommano
 *   26854 + 26846 + 26846 = 80546, restano due centesimi, dati ai resti maggiori: il primo ad Anna (resto 0,70), il secondo a
 *   Bruno (resto 0,65, pari a quello di Carla, che viene dopo). Anna € 268,55, Bruno € 268,47, Carla € 268,46 (somma 80548);
 * - le otto bozze (rate 5–12, € 100,00 l'una) sono € 800,00 e passano ad Anna: la sua coppia è € 268,55 − € 800,00 = − € 531,45,
 *   cioè € 531,45 a credito; Bruno € 268,47 e Carla € 268,46 a debito; Ugo, che chiude la gestione, − € 5,48
 *   (− 531,45 + 268,47 + 268,46 = + 5,48 dalla parte degli eredi). Sei righe in saldi in tutto: tre coppie;
 * - senza il conguaglio Ugo resta con le quattro rate emesse, 4 × € 100,00 = € 400,00; con il conguaglio € 400,00 − € 5,48 =
 *   € 394,52 (i suoi 120 giorni, 31 + 28 + 31 + 30: € 1.200,00 × 120 / 365 = € 394,52). Anna ha € 800,00 di bozze, Bruno e Carla
 *   zero.
 *
 * Cosa presidia, rosso sul codice di oggi: le frasi di P6 che stanno sul server — il «prossimo piano della stessa gestione»; il
 * rifiuto che chiede l'erede di riferimento con «se lo scrivi»; «€ 400,00 senza conguaglio, € 394,52 se scrivi il conguaglio» senza
 * «rinunciano» e senza «come emesso» (Fase 1-bis); «il giorno del decesso» nel messaggio della richiesta; i messaggi dei saldi senza la parentesi e senza «una delle
 * due» (punto 0.2 del verbale); il rifiuto dell'annullamento del conguaglio con l'arretrato agli eredi, che indica «a nome del
 * defunto» e «Non scriverlo»; «le due righe» assente dal messaggio dell'annullamento; le due frasi della .42 con «ad Anna»; la
 * coppia di Anna «€ 531,45 a credito». Verdi oggi e da tenere verdi: gli importi interi delle coppie, la vendita che chiede
 * ancora «la data dell'atto», la vendita con la rinuncia e la nota obbligatoria, i nomi che non cominciano per A con «a», le cifre
 * dell'arretrato, l'annullamento che riesce (a nome del defunto) o resta rifiutato (agli eredi).
 *
 * Cosa NON copre: la scelta sul conguaglio (P1: il campo `conguaglio`, il rifiuto senza scelta, il registro) e chi la legge (P2),
 * le cifre per persona nei messaggi (P3), le frasi di chi non ha scritto il conguaglio (P4), il pannello Vue, le guide, lo
 * storico e la scheda (P5, P6 lato JS), l'`attachTo` e il commento del seeder (P7). I passaggi del test che registrano una
 * successione con l'arretrato a nome del defunto mandano `conguaglio = scrivi`: oggi il campo non esiste e la richiesta lo
 * ignora, dopo P1 è la scelta che la registrazione chiede.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use App\Models\User;
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

/** Un erede del condominio dello scenario (gli aiuti di `SuccessioneTest` sono funzioni globali di quel file: qui i nomi sono propri). */
function sxtErede(array $s, string $nome): Anagrafica
{
    static $n = 0;
    $n++;
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => 'sxt' . $n . '-' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'SXTEREDE' . str_pad((string) $n, 8, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/**
 * Il corpo del modulo per la successione del venditore dello scenario (`v`, proprietario al 100 %): come `sucCorpo`.
 *
 * @param  array<int, array{0: Anagrafica, 1: float}>  $eredi
 */
function sxtCorpo(array $s, array $eredi, string $decesso = '2026-05-01', string $arretrato = 'eredi', ?Anagrafica $riferimento = null, array $extra = []): array
{
    return array_merge([
        'tipo' => 'successione', 'riga_uscente_id' => $s['rigaV'], 'decorrenza' => $decesso, 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => array_map(fn ($e) => ['anagrafica_id' => $e[0]->id, 'quota' => $e[1]], $eredi),
        'arretrato' => $arretrato, 'erede_di_riferimento' => $riferimento?->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 123', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta: gli eredi entrano dal decesso',
    ], $extra);
}

/**
 * Il caso di Fresco: rate 1–4 emesse a giornale e non pagate (€ 400,00 a Ugo), otto bozze (€ 800,00), tre eredi.
 *
 * @return array{0: array, 1: Anagrafica, 2: array<int, array{0: Anagrafica, 1: float}>} lo scenario, Anna (il riferimento) e gli eredi con la quota
 */
function sxtCasoFresco(): array
{
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s);
    $anna = sxtErede($s, 'Anna Erede');
    $bruno = sxtErede($s, 'Bruno Erede');
    $carla = sxtErede($s, 'Carla Erede');

    return [$s, $anna, [[$anna, 33.34], [$bruno, 33.33], [$carla, 33.33]]];
}

/** Registra il caso di Fresco con l'arretrato a nome del defunto e il conguaglio scritto (oggi la richiesta ignora il campo `conguaglio`). */
function sxtRegistraConConguaglio($test, array $s, Anagrafica $riferimento, array $eredi): Subentro
{
    return ruRegistra($test, $s, sxtCorpo($s, $eredi, arretrato: 'defunto', riferimento: $riferimento, extra: ['conguaglio' => 'scrivi']));
}

/** L'anteprima rifiutata con 422 sul campo: le frasi dell'errore, in un testo solo. */
function sxtRifiuto($test, array $s, array $corpo, string $campo): string
{
    $errori = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $corpo)
        ->assertStatus(422)->assertJsonValidationErrors([$campo])->json("errors.{$campo}");

    return implode(' ', $errori);
}

/** La riga in saldi del conguaglio di una persona: nel caso di Fresco ne ha una ciascuno degli eredi. */
function sxtRigaDi(Subentro $sub, Anagrafica $p): Saldo
{
    return Saldo::where('subentro_id', $sub->id)->where('anagrafica_id', $p->id)->firstOrFail();
}

// --- P6: «nel prossimo piano della stessa gestione» -----------------------------------------------------------------------

it('P6, primo punto: l\'avviso sulle righe di saldo degli eredi dice «nel prossimo piano della stessa gestione», non «nel piano dopo»', function () {
    // Prova P6, primo punto: la frase di AnteprimaPassaggio sulle righe degli eredi (arretrato agli eredi, rate emesse al defunto).
    [$s, $anna, $eredi] = sxtCasoFresco();

    $a = ruAnteprima($this, $s, sxtCorpo($s, $eredi, arretrato: 'eredi', riferimento: $anna));
    $avvisi = implode(' | ', $a['cancello']['avvisi']);

    // Le quattro rate emesse a Ugo e non pagate restano a suo nome: 4 × € 100,00 = € 400,00 (la premessa, già vera oggi).
    expect($avvisi)->toContain('€ 400,00')
        ->toContain('nel prossimo piano della stessa gestione');
    expect($avvisi)->not->toContain('nel piano dopo');
});

// --- P6: il rifiuto che chiede l'erede di riferimento ---------------------------------------------------------------------

it('P6, terzo punto: il rifiuto che chiede l\'erede di riferimento, con l\'arretrato a nome del defunto, dice che la parte di ogni erede si regola con il conguaglio «se lo scrivi»', function () {
    // Prova P6, terzo punto: «La parte di ogni erede si regola con il conguaglio, se lo scrivi.» (AnteprimaPassaggio::conguaglioDegliEredi).
    [$s, , $eredi] = sxtCasoFresco();

    // Tre eredi e otto bozze su un piano già emesso, nessun erede di riferimento scelto: il programma non lo sceglie al posto suo.
    $frase = sxtRifiuto($this, $s, sxtCorpo($s, $eredi, arretrato: 'defunto'), 'erede_di_riferimento');

    expect($frase)->toContain('scegli l\'erede di riferimento')
        ->toContain('La parte di ogni erede si regola con il conguaglio, se lo scrivi.');
});

it('controllo di P6, terzo punto: con l\'arretrato agli eredi il rifiuto dell\'erede di riferimento nomina ancora le righe dell\'arretrato', function () {
    // Controllo del terzo punto di P6: con l'arretrato agli eredi la parte di ogni erede passa anche dalle righe dell'arretrato.
    [$s, , $eredi] = sxtCasoFresco();

    $frase = sxtRifiuto($this, $s, sxtCorpo($s, $eredi, arretrato: 'eredi'), 'erede_di_riferimento');

    expect($frase)->toContain('scegli l\'erede di riferimento')
        ->toContain('righe dell\'arretrato');
});

// --- P6: la cifra che resta al defunto ------------------------------------------------------------------------------------

it('P6, quarto punto: la cifra che resta al defunto dice «€ 400,00 senza conguaglio, € 394,52 se scrivi il conguaglio», senza «rinunciano» e senza «come emesso»', function () {
    // Prova P6, quarto punto: la riga delle informazioni del cancello (arretrato a nome del defunto). Fase 1-bis, rilievi R1 e R9: «senza
    // conguaglio» al posto di «come emesso» (la scelta non ha preselezione, e «emesso» è falso dove restano a suo nome delle bozze).
    [$s, $anna, $eredi] = sxtCasoFresco();

    $a = ruAnteprima($this, $s, sxtCorpo($s, $eredi, arretrato: 'defunto', riferimento: $anna));
    $informazioni = implode(' | ', $a['cancello']['informazioni']);

    // € 400,00: le quattro rate emesse (4 × € 100,00); € 394,52: lo stesso meno il credito di € 5,48 della coppia di Ugo.
    expect($informazioni)->toContain('resta a suo nome')
        ->toContain('€ 400,00 senza conguaglio, € 394,52 se scrivi il conguaglio');
    expect($informazioni)->not->toContain('rinunciano');
    expect($informazioni)->not->toContain('come emesso');
});

it('controllo di P6, quarto punto: le cifre dell\'arretrato a nome del defunto non cambiano, € 394,52 con il conguaglio e € 400,00 senza', function () {
    // Controllo del quarto punto di P6: la correzione tocca le parole, non il denaro.
    [$s, $anna, $eredi] = sxtCasoFresco();

    $arretrato = ruAnteprima($this, $s, sxtCorpo($s, $eredi, arretrato: 'defunto', riferimento: $anna))['rate']['arretrato'];

    // € 400,00 (le rate 1–4) meno la coppia di Ugo, € 5,48 a credito: € 394,52.
    expect($arretrato['resta'])->toBe(39452)
        ->and($arretrato['resta_formattato'])->toBe('€ 394,52')
        ->and($arretrato['resta_senza_conguaglio'])->toBe(40000)
        ->and($arretrato['resta_senza_conguaglio_formattato'])->toBe('€ 400,00');
});

// --- P6: «il giorno del decesso» nel messaggio della richiesta ------------------------------------------------------------

it('P6, quinto punto: nella successione la richiesta senza data chiede «il giorno del decesso», non «la data dell\'atto»', function () {
    // Prova P6, quinto punto: il messaggio `decorrenza.required` di AnteprimaPassaggioRequest, nella successione.
    [$s, $anna, $eredi] = sxtCasoFresco();
    $corpo = sxtCorpo($s, $eredi, arretrato: 'defunto', riferimento: $anna);
    unset($corpo['decorrenza']);

    $frase = sxtRifiuto($this, $s, $corpo, 'decorrenza');

    expect($frase)->toContain('il giorno del decesso');
    expect($frase)->not->toContain('data dell\'atto');
});

it('fuori tema (Fase 1 della .48, dubbio di uno scettico): una data che non è una data dà un errore sul campo, non un 500', function () {
    // `AnteprimaPassaggioRequest::withValidator` leggeva la decorrenza con `CarbonImmutable::parse` anche quando la regola `date`
    // l'aveva già rifiutata: «pippo» faceva esplodere il controllo dopo la validazione, con una pagina 500 al posto del messaggio.
    [$s, $anna, $eredi] = sxtCasoFresco();
    $corpo = sxtCorpo($s, $eredi, arretrato: 'defunto', riferimento: $anna, extra: ['decorrenza' => 'pippo']);

    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $corpo)
        ->assertStatus(422)
        ->assertJsonValidationErrors('decorrenza');
});

it('controllo di P6, quinto punto: la vendita senza data chiede ancora «la data dell\'atto»', function () {
    // Controllo del quinto punto di P6: «il giorno del decesso» vale per la successione, non per gli altri tipi.
    [$s] = sxtCasoFresco();
    $corpo = ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-05-01', 100);
    unset($corpo['decorrenza']);

    $frase = sxtRifiuto($this, $s, $corpo, 'decorrenza');

    expect($frase)->toContain('Scrivi la data dell\'atto');
    expect($frase)->not->toContain('decesso');
});

// --- P6: i messaggi dei saldi sulle righe del conguaglio -----------------------------------------------------------------

it('P6, sesto punto: il messaggio della modifica di una riga del conguaglio non dice «credito a chi esce, debito a chi entra»', function () {
    // Prova P6, sesto punto: UpdateSaldoRequest. Nel caso di Fresco la riga di Anna è un credito di chi entra: la parentesi è falsa.
    [$s, $anna, $eredi] = sxtCasoFresco();
    $sub = sxtRegistraConConguaglio($this, $s, $anna, $eredi);
    $riga = sxtRigaDi($sub, $anna);
    // La premessa: la riga di Anna è − € 531,45 (€ 268,55 di sua parte meno € 800,00 di bozze), un credito di chi entra.
    expect((int) $riga->saldo_iniziale)->toBe(-53145);

    $this->actingAs($this->user)->patch(route('admin.gestionale.saldi.update', [$s['c']->id, $riga->id]), ['saldo_iniziale' => 100, 'gestione_id' => $s['g']->id])
        ->assertSessionHasErrors('saldo');
    $frase = session('errors')->first('saldo');

    expect($frase)->toContain('annulla il conguaglio');
    expect($frase)->not->toContain('credito a chi esce');
});

it('controllo di P6, sesto punto: il messaggio della cancellazione di una riga del conguaglio non ha la parentesi, e la riga resta', function () {
    // Controllo del sesto punto di P6: SaldoInizialeController::destroy non ha mai avuto la parentesi, e rifiuta come oggi.
    [$s, $anna, $eredi] = sxtCasoFresco();
    $sub = sxtRegistraConConguaglio($this, $s, $anna, $eredi);
    $riga = sxtRigaDi($sub, $anna);

    $this->actingAs($this->user)->delete(route('admin.gestionale.saldi.destroy', [$s['c']->id, $riga->id]))->assertSessionHasErrors('saldo');
    $frase = session('errors')->first('saldo');

    expect($frase)->toContain('annulla il conguaglio');
    expect($frase)->not->toContain('credito a chi esce');
    expect((int) $riga->fresh()->saldo_iniziale)->toBe(-53145)
        // Le sei righe del caso di Fresco (tre coppie) restano tutte.
        ->and(Saldo::where('subentro_id', $sub->id)->count())->toBe(6);
});

it('P6, sesto punto e 0.2 del verbale: i messaggi dei saldi non dicono «una delle due» righe, che in successione sono sei', function (string $strada) {
    // Prova la Fase 0.2 (e il sesto punto di P6): la successione scrive sei righe, non due; «una delle due» è falso come la parentesi.
    // Nota di lettura: P6 nomina solo la parentesi; «una delle due» sta nel punto 0.2 («i tre messaggi dei saldi»).
    [$s, $anna, $eredi] = sxtCasoFresco();
    $sub = sxtRegistraConConguaglio($this, $s, $anna, $eredi);
    $riga = sxtRigaDi($sub, $anna);
    expect(Saldo::where('subentro_id', $sub->id)->count())->toBe(6);

    if ($strada === 'modifica') {
        $this->actingAs($this->user)->patch(route('admin.gestionale.saldi.update', [$s['c']->id, $riga->id]), ['saldo_iniziale' => 100, 'gestione_id' => $s['g']->id])
            ->assertSessionHasErrors('saldo');
    } else {
        $this->actingAs($this->user)->delete(route('admin.gestionale.saldi.destroy', [$s['c']->id, $riga->id]))->assertSessionHasErrors('saldo');
    }

    expect(session('errors')->first('saldo'))->not->toContain('una delle due');
})->with(['modifica' => ['modifica'], 'cancellazione' => ['cancellazione']]);

// --- P6: l'annullamento del conguaglio -----------------------------------------------------------------------------------

it('P6, settimo punto: il rifiuto di annullare il conguaglio con l\'arretrato agli eredi indica «a nome del defunto» e «Non scriverlo»', function () {
    // Prova P6, settimo punto: AnnullaConguaglioAction. La strada è registrare di nuovo con l'arretrato a nome del defunto e «Non scriverlo».
    [$s, $anna, $eredi] = sxtCasoFresco();
    $sub = ruRegistra($this, $s, sxtCorpo($s, $eredi, arretrato: 'eredi', riferimento: $anna));
    expect($sub->fresh()->arretratoAgliEredi())->toBeTrue();

    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $sub]),
        ['nota_annullamento_conguaglio' => 'Gli eredi hanno regolato fra loro'])->assertSessionHasErrors('conguaglio');
    $frase = session('errors')->first('conguaglio');

    expect($frase)->toContain('a nome del defunto')->toContain('Non scriverlo');
});

it('controllo di P6, settimo punto: con l\'arretrato agli eredi il conguaglio resta com\'è, e il rifiuto è sul campo «conguaglio»', function () {
    // Controllo del settimo punto di P6: cambiano le parole del rifiuto, non l'effetto (decisione 65: coppia e arretrato fanno un conto solo).
    [$s, $anna, $eredi] = sxtCasoFresco();
    $sub = ruRegistra($this, $s, sxtCorpo($s, $eredi, arretrato: 'eredi', riferimento: $anna));
    $righe = Saldo::where('subentro_id', $sub->id)->count();
    // Le righe del passaggio sono quelle delle coppie e quelle dell'arretrato agli eredi: più delle sei di una coppia per erede.
    expect($righe)->toBeGreaterThan(6);

    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $sub]),
        ['nota_annullamento_conguaglio' => 'Gli eredi hanno regolato fra loro'])->assertSessionHasErrors('conguaglio');

    expect(session('errors')->first('conguaglio'))->not->toBeEmpty()
        ->and($sub->fresh()->conguaglio_annullato_il)->toBeNull()
        ->and(Saldo::where('subentro_id', $sub->id)->count())->toBe($righe);
});

it('P6, ottavo punto: il messaggio dell\'annullamento del conguaglio non dice «le due righe», con le sei righe di una successione', function () {
    // Prova P6, ottavo punto: PassaggioController::annullaConguaglio. «Le due righe» sta nel ramo dei passaggi senza `piani_presi`
    // (registrati prima della .42); si raggiunge togliendo la chiave dal registro. Le righe tolte sono sei: tre coppie.
    [$s, $anna, $eredi] = sxtCasoFresco();
    $sub = sxtRegistraConConguaglio($this, $s, $anna, $eredi);
    $registro = $sub->fresh()->registro;
    unset($registro['piani_presi']);
    $sub->update(['registro' => $registro]);

    $risposta = $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $sub]),
        ['nota_annullamento_conguaglio' => 'Gli eredi hanno regolato fra loro'])->assertSessionHasNoErrors();
    $messaggio = $risposta->getSession()->get('message');

    expect($messaggio['type'])->toBe('success')
        ->and($messaggio['message'])->toContain('6 righe tolte');
    expect($messaggio['message'])->not->toContain('le due righe');
});

it('controllo di P6, ottavo punto: l\'annullamento del conguaglio a nome del defunto riesce, toglie le sei righe e lo dice senza «le due righe»', function () {
    // Controllo dell'ottavo punto di P6: il ramo dei passaggi di oggi (con `piani_presi`) non ha mai detto «le due righe».
    [$s, $anna, $eredi] = sxtCasoFresco();
    $sub = sxtRegistraConConguaglio($this, $s, $anna, $eredi);
    expect(Saldo::where('subentro_id', $sub->id)->count())->toBe(6);

    $risposta = $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $sub]),
        ['nota_annullamento_conguaglio' => 'Gli eredi hanno regolato fra loro'])->assertSessionHasNoErrors();
    $messaggio = $risposta->getSession()->get('message');

    expect($messaggio['type'])->toBe('success')
        ->and($messaggio['message'])->toContain('6 righe tolte')
        ->and(Saldo::where('subentro_id', $sub->id)->count())->toBe(0)
        ->and($sub->fresh()->conguaglio_annullato_il)->not->toBeNull();
    expect($messaggio['message'])->not->toContain('le due righe');
});

// --- P6: le due frasi della .42 con «ad Anna» (decisione 73, punto 4) -----------------------------------------------------

/** Anna, inquilina dell'unità dal 2025, senza nessuna rata emessa a suo nome; il modulo della fine della sua locazione al 1° luglio. */
function sxtFineLocazione(array $s, string $nome): array
{
    $inquilina = Anagrafica::forceCreate(['nome' => $nome, 'email' => 'sxt-inq' . $s['unita']->id . '@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'SXTINQUILINA' . str_pad((string) $s['unita']->id, 4, '0', STR_PAD_LEFT)]);
    $inquilina->condomini()->syncWithoutDetaching([$s['c']->id]);
    $riga = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $inquilina->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'inquilino', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2025-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);

    return ['tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => $riga, 'decorrenza' => '2026-07-01', 'quota' => 100,
        'copia_autentica' => false, 'pertinenze' => []];
}

it('decisione 73 (4): la fine locazione senza rate emesse a un\'inquilina che si chiama Anna dice «intestata ad Anna»', function () {
    // Prova P6, ottava voce dell'elenco (le due frasi della .42): «Nessuna rata emessa è intestata a …» (AnteprimaPassaggio::blocco2, fine locazione).
    [$s] = sxtCasoFresco();
    $frasi = implode(' ', ruAnteprima($this, $s, sxtFineLocazione($s, 'Anna Inquilina'))['rate']['frasi']);

    expect($frasi)->toContain('Nessuna rata emessa è intestata ad Anna Inquilina');
    expect($frasi)->not->toContain('intestata a Anna');
});

it('controllo della decisione 73 (4): la stessa frase per un\'inquilina che non comincia per A resta con «a»', function () {
    // Controllo: la «d» si aggiunge solo davanti alla A; «a Ines Inquilina» resta com'è.
    [$s] = sxtCasoFresco();
    $frasi = implode(' ', ruAnteprima($this, $s, sxtFineLocazione($s, 'Ines Inquilina'))['rate']['frasi']);

    expect($frasi)->toContain('Nessuna rata emessa è intestata a Ines Inquilina');
    expect($frasi)->not->toContain('intestata ad');
});

it('decisione 73 (4): il motivo del cancello sulle quote non emesse di chi esce, se si chiama Anna, dice «intestate ad Anna»', function () {
    // Prova P6, ottava voce dell'elenco (le due frasi della .42): la testa «il piano … ha N quote non ancora emesse intestate a …».
    // Rate 1–4 emesse, otto bozze: il piano non si ricalcola più e le sue otto quote non emesse sono intestate a chi vende.
    [$s] = sxtCasoFresco();
    $s['v']->update(['nome' => 'Anna Verdi']);
    $s['a']->update(['nome' => 'Bruno Compratore']);

    $a = ruAnteprima($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-05-01', 100));
    $motivi = implode(' | ', $a['cancello']['motivi']);

    expect($motivi)->toContain('quote non ancora emesse intestate ad Anna Verdi');
    expect($motivi)->not->toContain('intestate a Anna');
});

it('controllo della decisione 73 (4): il motivo del cancello per chi non comincia per A resta «intestate a Venditore Ugo»', function () {
    // Controllo: Venditore Ugo, il nome dello scenario, non comincia per A.
    [$s] = sxtCasoFresco();

    $a = ruAnteprima($this, $s, ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-05-01', 100));
    $motivi = implode(' | ', $a['cancello']['motivi']);

    expect($motivi)->toContain('quote non ancora emesse intestate a Venditore Ugo');
    expect($motivi)->not->toContain('intestate ad');
});

// --- P5: la coppia di Anna nel pannello -----------------------------------------------------------------------------------

it('P5: nel pannello della successione la coppia di Anna, − € 531,45, si scrive «€ 531,45 a credito», non «€ -531,45»', function () {
    // Prova P5: «Le cifre negative delle coppie si scrivono "€ 531,45 a credito", non "€ -531,45"» (conguaglioDegliEredi).
    [$s, $anna, $eredi] = sxtCasoFresco();

    $coppie = collect(ruAnteprima($this, $s, sxtCorpo($s, $eredi, arretrato: 'defunto', riferimento: $anna))['rate']['conguaglio']['coppie'])->keyBy('entrante_nome');

    // La sua parte è € 268,55 (26855) e le otto bozze € 800,00 (80000): 26855 − 80000 = − 53145.
    expect($coppie['Anna Erede']['importo'])->toBe(-53145)
        ->and($coppie['Anna Erede']['importo_formattato'])->toBe('€ 531,45 a credito');
});

it('controllo di P5: gli importi delle coppie restano in centesimi e le cifre a debito di Bruno e Carla si scrivono come oggi', function () {
    // Controllo di P5: cambia la scrittura del segno meno, non il denaro; i positivi non prendono nessun suffisso.
    [$s, $anna, $eredi] = sxtCasoFresco();

    $coppie = collect(ruAnteprima($this, $s, sxtCorpo($s, $eredi, arretrato: 'defunto', riferimento: $anna))['rate']['conguaglio']['coppie'])->keyBy('entrante_nome');

    // Bruno € 268,47 (26847) e Carla € 268,46 (26846): la loro parte, senza bozze. Somma: − 53145 + 26847 + 26846 = + 548, il credito di Ugo.
    expect($coppie->pluck('importo', 'entrante_nome')->all())->toEqual(['Anna Erede' => -53145, 'Bruno Erede' => 26847, 'Carla Erede' => 26846])
        ->and($coppie['Bruno Erede']['importo_formattato'])->toBe('€ 268,47')
        ->and($coppie['Carla Erede']['importo_formattato'])->toBe('€ 268,46')
        ->and($coppie->sum('importo'))->toBe(548);
});

// --- Controlli: la vendita con la rinuncia e la nota obbligatoria (P1: gli altri tipi restano con la casella di oggi) -------

it('controllo di P1: la vendita con la rinuncia al conguaglio e senza nota si rifiuta ancora, con «scrivi perché»', function () {
    // Controllo di P1 e P6: vendita, usufrutto e locazione restano con la casella e la nota obbligatoria di oggi (`rinuncia_conguaglio`).
    [$s] = sxtCasoFresco();
    $corpo = ruPassaggio('vendita', $s['rigaV'], $s['a'], '2026-05-01', 100) + ['rinuncia_conguaglio' => true];

    $frase = sxtRifiuto($this, $s, $corpo, 'nota_conguaglio');

    expect($frase)->toContain('Hai rinunciato al conguaglio proposto')->toContain('scrivi perché');
    // Con la nota la stessa vendita si registra, e la coppia non si scrive: la rinuncia c'è, motivata.
    $sub = ruRegistra($this, $s, $corpo + ['nota_conguaglio' => 'Regolato nel rogito fra le parti', 'ho_letto' => true, 'nota_cancello' => 'Rogito letto']);
    expect($sub->fresh()->conguaglioRinunciato())->toBeTrue()
        ->and(Saldo::where('subentro_id', $sub->id)->count())->toBe(0);
});
