<?php

/**
 * 1.11.0-beta.50, giro stretto sulle correzioni della Fase 1-bis — le frasi: i rilievi G5, G8, G11–G19 e G21 del giro stretto (verbale
 * `piano_esecutivo_beta50.md`, «Giro stretto sulle correzioni»), e quattro rilievi della verifica delle correzioni («Le correzioni della
 * Fase 1-bis»): R19 con un saldo «importato», le due diciture nuove della stampa del prospetto, «il legato … si è fermata», e il «tornano
 * al proprietario» con una sola unità senza nessuno al posto dell'inquilino (il ripiego della .47 nel ramo `$stessa` di
 * `FrasiObbligati::fraseTornano`).
 *
 * Lo scenario è quello di `ScenariFineLocazione.php`, `FermataDopoScriviHelpers.php` e `ScenariProspettoLocazione.php`: «Spese generali»
 * € 1.200,00 sulla tabella «Proprietà», 30 % «Inquilino» (36000) e 70 % «Proprietario» (84000); il «Preventivo 2026» in dodici rate
 * mensili; Ines inquilina dal 2019; il 2026 ha 365 giorni. Ines esce il 1/7: 181 giorni suoi, 184 dopo; 36000 × [181, 184] / 365 =
 * 17852,05 / 18147,95 → 17852 + 18147 = 35999, il centesimo al resto più grande (,95) → la coppia 18148 (€ 181,48). Con due inquiline al
 * 50 % la parte di una è 18000: 18000 × 184 / 365 = 9073,97 → 9074 (€ 90,74). Le altre cifre sono fatte a mano accanto a ogni test, in
 * frazioni esatte, con la regola della coppia (le parti intere, il centesimo al resto più grande, a pari resto a chi esce).
 *
 * Nati come test rossi: ognuno descrive un difetto del codice della .50 trovato dal giro stretto, con l'oracolo della correzione verificata
 * (G5), di quella proposta dal rilievo, o della decisione (83.2 per G8, G11 e G12), corretto prima del commit. Ognuno ha accanto un
 * controllo (la stessa scena prima del passo che la guastava, le cifre, o il caso vicino che il codice faceva già giusto) che dice che il
 * rosso non veniva dal test. Gli altri sono guardie: fissano un pezzo di codice già giusto che nessun test teneva (le due diciture della
 * stampa: tolta la dicitura, tornano rossi), o il confine di una correzione (la frase che deve restare dove è vera).
 *
 * Che cosa NON copre:
 * - il denaro: le coppie, i netti e il prospetto si leggono solo come controlli; i rilievi del motore del giro stretto (G1–G4, G20) e
 *   quelli della pagina e del modulo (G6, G7, G9, G10) stanno nei file delle altre due aree;
 * - le frasi parola per parola: si cercano la cifra, il nome e la parola che conta, non la frase intera, che scriverà chi corregge;
 * - G5, la guida in app; e il nome della chiave per la voce «Non scriverlo» del modulo, che qui è quello che la correzione verificata
 *   propone «per esempio» (`rate.prospetto_regolato`): se la correzione ne sceglie un altro, cambia qui e nel vitest gemello
 *   `StrettoBeta50Frasi.test.ts`, che tiene la voce del componente;
 * - G8, la pagina: si fissa il tipo del messaggio che il server mette in sessione (`warning`, che `Alert.vue` non chiude da solo);
 * - G12, l'avviso dell'annullamento del passaggio (`AnnullaPassaggioAction::avvisi`) quando il registro ha `wallet` falso: non è nel
 *   rilievo;
 * - G13, quale delle due strade prende la correzione nel ramo dell'erede (la frase che dice la successione, o togliere «ha preso»): si
 *   fissa che ogni «ha preso» dica la riga vera nella coppia;
 * - G14 e G18 (B) stringono qui ciò che `R12` e `R8 (b)` di `RevisioneBeta50FrasiTest` lasciano largo (la strada a mano con /Wallet|le
 *   parti|a mano/, «a Ugo» senza la fine che ha scritto la coppia): quei due test restano come sono;
 * - G17, l'impaginazione della stampa: si legge la testa, che la stampa ripete;
 * - G21, gli altri messaggi «i passaggi successivi, l'ultimo per primo» (solo imprecisi: la guardia dell'annullamento corregge l'ordine)
 *   e il docblock di `fraseNudaEPienaDelloStessoGiorno`; e la forma della ricetta: si fissa che comprenda il passaggio registrato dopo con
 *   una data precedente, che lo annulli prima e che lo faccia registrare di nuovo prima dell'altro, in ordine di data;
 * - la pagina Vue del pannello e dello storico, i PDF resi da browser, MySQL.
 */

use App\Actions\Subentro\AnnullaPassaggioAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';
require_once __DIR__.'/Support/ScenariProspettoLocazione.php';
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

/**
 * Lo scenario con il piano generato e, se si dice, le sei rate gennaio–giugno a giornale; con `$riporti` le persone che hanno già un saldo
 * pregresso a mano sulla gestione e sull'unità, scritto prima della generazione: [nome => centesimi]. È `rbfScenario` di
 * `RevisioneBeta50FrasiTest`, che sta in un file di test e non si può includere.
 */
function sbfScenario($test, array $titolari, array $opzioni = [], bool $seiRate = true, array $riporti = []): array
{
    $s = fclScenario($titolari, $opzioni);
    foreach ($riporti as $nome => $centesimi) {
        fclRiporto($s, $nome, $centesimi);
    }
    fclGenera($s);
    if ($seiRate) {
        fclEmetti($test, $s, '2026-06-30');
        expect(fclEmesse($s))->toBe(6);
    }

    return $s;
}

/** La voce di un passaggio nello storico, senza `annullabile` (gli avvisi dell'annullamento del passaggio sono un'altra cosa). */
function sbfStoricoSenzaAnnullabile(array $s, Subentro $passaggio): array
{
    $voce = fds50Storico($s, $passaggio);
    unset($voce['annullabile']);

    return $voce;
}

/** «Annulla il conguaglio» della fine dalla rotta dello storico, con la nota; la risposta. */
function sbfAnnullaConguaglio($test, array $s, Subentro $fine, string $nota = 'Le parti hanno regolato diversamente alla riconsegna'): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $fine]), ['nota_annullamento_conguaglio' => $nota]);
}

/** Il messaggio che la rotta mette in sessione: [tipo, testo]. */
function sbfMessaggio(\Illuminate\Testing\TestResponse $r): array
{
    $m = (array) ($r->getSession()->get('message') ?? []);

    return [(string) ($m['type'] ?? ''), (string) ($m['message'] ?? '')];
}

/**
 * Il caso H della revisione (è `rbfCasoH`): la fine di Ines il 1/7 con «Scrivi» (Ines −18148, Ugo +18148), le rate fino al 30/9, la vendita
 * di Ugo a Elsa il 1/10 con la fermata di € 90,74 (18148 × 92/184 = 9074, esatto), e il saldo manuale dal Wallet che la frase della
 * decisione 80 indica: Ugo −9074, Elsa +9074.
 */
function sbfCasoH($test): array
{
    $s = fds50Scenario();
    fds50Genera($s);
    fds50Emetti($test, $s, '2026-06-30');
    $s['fine'] = fds50Fine($test, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    expect(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Ugo' => 18148]);
    fds50Emetti($test, $s, '2026-09-30');
    $s['vendita'] = fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['subentro'];
    expect(collect(fds50Fermate($s['vendita']))->sum('cifra'))->toBe(9074);
    foreach ([['Ugo', -9074], ['Elsa', 9074]] as [$chi, $importo]) {
        $test->actingAs($test->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $importo,
        ])->assertSessionHasNoErrors();
    }

    return $s;
}

/**
 * La fine di Ines il 1/7 con «Scrivi», le rate fino al 31/8, la vendita di Ugo a Elsa il 1/9 (tutta la sua quota, `$quota`) registrata, le
 * rate fino al 31/10 (è `rbfDopoLaPrimaVendita`). Carlo è la persona che compra da Elsa.
 */
function sbfDopoLaPrimaVendita($test, array $titolari = [['Ugo', 'proprietario', 100]], float|int $quota = 100): array
{
    $s = sbfScenario($test, $titolari);
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($test, $s, '2026-08-31');
    fclPersona($s, 'Elsa');
    fclPersona($s, 'Carlo');
    $s['prima'] = fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-01', $quota))['subentro'];
    fclEmetti($test, $s, '2026-10-31');

    return $s;
}

/**
 * Le frasi «X ha preso € …» (o «X e Y hanno preso € …») di un testo la cui cifra non è la riga di quelle persone nella coppia della fine:
 * [frase trovata => la somma delle loro righe]. Vuoto se ogni «ha preso» dice la riga vera, o se la frase non c'è.
 *
 * @param array<string, int> $coppia nome → centesimi, come `fds50Coppia`
 * @return array<string, int>
 */
function sbfHaPresoNonVero(string $testo, array $coppia): array
{
    $male = [];
    preg_match_all('/(\p{Lu}\p{L}*(?:(?:, | e )\p{Lu}\p{L}*)*) (?:ha|hanno) preso € ([\d.]+,\d{2})/u', $testo, $m, PREG_SET_ORDER);
    foreach ($m as [$tutta, $chi, $cifra]) {
        $persone = preg_split('/, | e /u', $chi);
        $righe = (int) array_sum(array_map(fn (string $n) => (int) ($coppia[$n] ?? 0), $persone));
        if ($righe !== (int) str_replace(['.', ','], '', $cifra)) {
            $male[$tutta] = $righe;
        }
    }

    return $male;
}

/**
 * Le voci di un conduttore nel prospetto di un'unità (l'Interno 1 se non si dice), anche più d'una per conto: [pagato_da, strada, importo,
 * giorni], in ordine di pagatore.
 *
 * @return list<array{0: string, 1: string, 2: int, 3: int}>
 */
function sbfVociDi(array $s, string $nome, ?Immobile $unita = null): array
{
    $p = app(ProspettoOneriAccessori::class)->calcola(($unita ?? $s['unita'])->fresh(), $s['e']);

    return collect(collect($p['conduttori'])->firstWhere('nome', $nome)['voci'] ?? [])->filter(fn ($v) => ! empty($v['strada']))
        ->map(fn ($v) => [(string) ($v['pagato_da'] ?? ''), (string) $v['strada'], (int) $v['importo'], (int) ($v['giorni'] ?? 0)])
        ->sortBy(fn ($v) => $v[0] . '|' . $v[2])->values()->all();
}

/** Le frasi divise in pezzi alla fine di un periodo o di un punto e virgola: per guardare una proposizione alla volta. */
function sbfPezzi(string $testo): array
{
    return array_values(array_filter(array_map('trim', preg_split('/(?<=[.;])\s+/u', $testo)), fn ($p) => $p !== ''));
}

// ---------------------------------------------------------------------------------------------------------------------
// G5: dopo un cambio d'inquilino «regolato fra le parti», il cambio «Non scriverlo» non promette il rimborso con il prospetto
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Il cambio d'inquilino di Ines Moretti a Luca Ferri il 1/7 «regolato fra le parti», nei due modi di `rbmDopoIlCambioRegolato` di
 * `RevisioneBeta50MotoreTest` (la casella di prima della .50, o il conguaglio scritto e poi annullato), senza la fine di Luca dopo.
 */
function sbfCambioRegolato($test, string $modo): array
{
    $s = pflScenario();
    pflSeiRateEmesse($test, $s);
    if ($modo === 'casella') {
        $cambio = pflFine($test, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', null, [], ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro le spese dell\'inquilino']);
        $registro = $cambio->registro;
        unset($registro['conguaglio']);
        DB::table('subentri')->where('id', $cambio->id)->update(['registro' => json_encode($registro)]);
        expect($cambio->fresh()->conguaglioRinunciato())->toBeTrue()->and($cambio->fresh()->conguaglioNonScritto())->toBeFalse();
    } else {
        $cambio = pflFine($test, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', 'scrivi');
        $test->actingAs($test->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $cambio]),
            ['nota_annullamento_conguaglio' => 'Ines e Luca hanno regolato fra loro alla consegna delle chiavi'])->assertSessionHasNoErrors();
        expect($cambio->fresh()->conguaglioAnnullato())->toBeTrue();
    }
    // Controllo: nei saldi niente, Ines ha le 36000 nelle sue rate.
    expect(pflNetti($s))->toBe(['Ines Moretti' => 36000, 'Ugo Rinaldi' => 84000]);

    return $s;
}

it('G5 — dopo il cambio Ines → Luca «regolato fra le parti», il cambio Luca → Anna del 1/9: la voce «Non scriverlo» del pannello non dice che Anna rimborsa Luca «con il prospetto degli oneri accessori», che non lo fa, ma che quel rimborso si regola fra le parti', function (string $modo) {
    // `AnteprimaPassaggio::rimborsoSenzaScrivere` dice «e Anna Neri glieli rimborsa con il prospetto degli oneri accessori» per ogni piano
    // con il riparto registrato. Registrato con «Non scriverlo», il prospetto ha solo Ines [36000, 36000, 0, 365]: la 83.3 tiene la lettura
    // della 43 e della 46 quando dopo il cambio regolato non c'è una fine «Scrivi», e il cambio di Luca ad Anna non lo è. Anna non c'è, e
    // nessuno rimborsa Luca, che con l'accordo fuori ha pagato a Ines anche i giorni di Anna. La correzione verificata: solo la frase,
    // il rimborso di Anna a Luca si regola fra le parti; e la prima metà dice di chi sono le rate (di Ines, non di Luca, che non ne ha).
    // Le cifre: 36000 × 122/365 = 12032,88 → 12033 (€ 120,33), la coppia di Anna dal 1/9.
    $s = sbfCambioRegolato($this, $modo);
    $modulo = pflModuloFine($s, 'Luca Ferri', 'Anna Neri', '2026-09-01', null);

    $a = fclAnteprima($this, $s, $modulo);
    $frase = collect($a['rate']['frasi'])->first(fn (string $f) => str_contains($f, 'Se non lo scrivi')) ?? '';
    pflFine($this, $s, 'Luca Ferri', 'Anna Neri', '2026-09-01', 'non_scrivere');

    // Controlli: la scelta si chiede, la coppia è di Anna; registrato con «Non scriverlo», il prospetto ha solo Ines, e Anna non
    // rimborsa niente.
    expect($a['rate']['scelta_del_conguaglio'])->toBeTrue()
        ->and(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Anna Neri', 12033]])
        ->and(pflConduttori(pflProspetto($s)))->toBe(['Ines Moretti' => [36000, 36000, 0, 365]]);

    expect(str_contains($frase, 'Anna Neri glieli rimborsa con il prospetto degli oneri accessori'))
        ->toBeFalse('La voce «Non scriverlo» promette il rimborso di Anna con il prospetto, che il prospetto non fa: ' . $frase)
        ->and(str_contains($frase, 'restano nelle rate di Luca Ferri'))->toBeFalse('La voce dice le rate di Luca, che non ne ha: ' . $frase)
        ->and(str_contains($frase, 'fra le parti'))->toBeTrue('La voce non dice che il rimborso di Anna a Luca si regola fra le parti: ' . $frase);
})->with([
    'la casella di prima della .50' => ['casella'],
    'il conguaglio scritto e poi annullato' => ['annullato'],
]);

it('G5, la chiave per il modulo — la voce «Non scriverlo» del modulo (`AnteprimaPassaggio.vue`) ha un testo suo: il pannello le dice i piani su cui il cambio regolato fra le parti lascia il rimborso fra le parti, in `rate.prospetto_regolato` accanto a `rate.prospetto_esclude` (R10)', function (string $modo) {
    // La chiave e il nome sono della correzione verificata («per esempio `rate.prospetto_regolato`»); il vitest gemello è
    // `StrettoBeta50Frasi.test.ts`. Il piano ha il riparto registrato: `prospetto_esclude` resta vuoto.
    $s = sbfCambioRegolato($this, $modo);

    $a = fclAnteprima($this, $s, pflModuloFine($s, 'Luca Ferri', 'Anna Neri', '2026-09-01', null));

    // Controlli: la scelta si chiede, e il piano non è della 1.10.
    expect($a['rate']['scelta_del_conguaglio'])->toBeTrue()
        ->and($a['rate']['prospetto_esclude'] ?? null)->toBe([]);

    expect($a['rate']['prospetto_regolato'] ?? null)->toBe(['Preventivo 2026'], 'Il pannello non dice al modulo il piano regolato fra le parti.');
})->with([
    'la casella di prima della .50' => ['casella'],
    'il conguaglio scritto e poi annullato' => ['annullato'],
]);

it('G5, la guardia — con il primo cambio scritto la catena c\'è: la voce «Non scriverlo» del cambio Luca → Anna tiene «con il prospetto», e il prospetto la mantiene (Anna [12033, 0, 12033, 122], pagata da Luca)', function () {
    // Il confine della correzione: la frase si cambia solo dopo un cambio regolato. Con il primo cambio scritto Luca ha i giorni di Ines con
    // la coppia (18148), e Anna gli rimborsa i suoi (12033) con il prospetto: 36000 × 122/365 = 12032,88 → 12033; Luca 1/7–31/8, 62 giorni,
    // 6115,07 → 6115; Ines 181 giorni, 17852.
    $s = pflScenario();
    pflSeiRateEmesse($this, $s);
    pflFine($this, $s, 'Ines Moretti', 'Luca Ferri', '2026-07-01', 'scrivi');
    $modulo = pflModuloFine($s, 'Luca Ferri', 'Anna Neri', '2026-09-01', null);

    $a = fclAnteprima($this, $s, $modulo);
    $frase = collect($a['rate']['frasi'])->first(fn (string $f) => str_contains($f, 'Se non lo scrivi')) ?? '';
    pflFine($this, $s, 'Luca Ferri', 'Anna Neri', '2026-09-01', 'non_scrivere');
    $p = pflProspetto($s);

    expect($frase)->toContain('Anna Neri glieli rimborsa con il prospetto degli oneri accessori')
        ->and($a['rate']['prospetto_regolato'] ?? [])->toBe([])
        ->and(pflConduttori($p)['Anna Neri'] ?? null)->toBe([12033, 0, 12033, 122])
        ->and(pflPagatori(pflSezione($p, 'Anna Neri')['voci'] ?? []))->toBe(['Luca Ferri']);
});

// ---------------------------------------------------------------------------------------------------------------------
// G8: gli avvisi della 83.2 dopo «Annulla il conguaglio» non stanno in un messaggio di successo che si chiude da solo
// ---------------------------------------------------------------------------------------------------------------------

it('G8 — «Annulla il conguaglio» della fine dopo la fermata della vendita: il messaggio con l\'avviso della fermata da rivedere è un avviso (warning), che resta, non un successo che si chiude dopo sei secondi', function () {
    // `PassaggioController::annullaConguaglio` usa sempre `flashSuccess`, anche con le fermate in coda; `annulla` (l'annullamento del
    // passaggio) passa a `flashWarning` quando ci sono avvisi (regola L-R1: un avviso è una cosa ancora da fare). `Alert.vue` chiude da
    // solo ogni tipo che non sia `error` o `warning`.
    $s = sbfCasoH($this);

    [$tipo, $testo] = sbfMessaggio(sbfAnnullaConguaglio($this, $s, $s['fine'])->assertSessionHasNoErrors());

    // Controlli: l'annullamento riesce, il messaggio dice la fermata della vendita (€ 90,74) che non vale più (decisione 83.2). Senza saldi legati
    // alla vendita (dalla 1.11.0-beta.53) l'avviso dice l'accordo fra le parti da rivedere, non un saldo del Wallet.
    expect($testo)->toStartWith('Conguaglio annullato')
        ->and($testo)->toContain('€ 90,74')
        ->and($testo)->toContain('quella fermata non vale più');

    expect($tipo)->toBe('warning', 'Il messaggio con l\'avviso della fermata è di tipo «' . $tipo . '», e si chiude da solo: ' . $testo);
});

it('G8, la guardia — «Annulla il conguaglio» di una fine senza nessuna fermata dopo resta un successo', function () {
    // Il confine della correzione: il tipo cambia solo con le fermate (`$fermate !== []`).
    $s = sbfScenario($this, [['Ugo', 'proprietario', 100]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    [$tipo, $testo] = sbfMessaggio(sbfAnnullaConguaglio($this, $s, $fine)->assertSessionHasNoErrors());

    expect($testo)->toStartWith('Conguaglio annullato')
        ->and($testo)->not->toContain('Wallet')
        ->and($tipo)->toBe('success');
});

// ---------------------------------------------------------------------------------------------------------------------
// G11: annullato il conguaglio della fine, gli avvisi dell'annullamento della vendita non danno per valida la sua fermata
// ---------------------------------------------------------------------------------------------------------------------

it('G11 — annullato il conguaglio della fine, l\'annullamento della vendita del 1/10 non avvisa che € 90,74 «sono rimasti a carico di Ugo, da regolare a mano», e lo storico dice che la fermata non vale più', function () {
    // Dalla 1.11.0-beta.53 gli avvisi dell'annullamento non leggono più il registro della fermata («se per questo hai registrato un saldo
    // manuale dal Wallet… Toglilo dal Wallet»): i saldi legati alla vendita li dice l'effetto dell'annullamento, quelli scritti a mano senza
    // legame la ricerca dei saldi a mano scritti dopo (`Beta53SaldiAManoDiPrimaTest`). Senza saldi, nessun avviso sulla fermata, prima e dopo.
    $s = sbfCasoH($this);

    // Controllo: prima dell'annullamento del conguaglio nessun avviso della vendita parla del Wallet.
    expect(implode(' | ', app(AnnullaPassaggioAction::class)->avvisi($s['vendita']->fresh())))->not->toContain('Toglilo dal Wallet');

    sbfAnnullaConguaglio($this, $s, $s['fine'])->assertSessionHasNoErrors();
    $avvisi = app(AnnullaPassaggioAction::class)->avvisi($s['vendita']->fresh());
    $dalloStorico = pflAvvisiDelloStorico($s, $s['vendita']);

    // Controlli: lo storico della vendita dice che la fermata non vale più (R5), e la vendita si annulla da qui.
    expect(implode(' ', fds50Storico($s, $s['vendita'])['fermate'] ?? []))->toMatch('/non val(e|gono) più/u')
        ->and(fds50Storico($s, $s['vendita'])['annullabile']['si'] ?? null)->toBeTrue();

    $ancoraValida = fn (array $lista) => array_values(array_filter($lista, fn (string $a) => str_contains($a, 'sono rimasti a carico di Ugo') && preg_match('/non val(e|gono) più/u', $a) !== 1));
    expect($ancoraValida($avvisi))->toBe([], 'L\'avviso dell\'annullamento della vendita dice ancora la fermata come valida: ' . implode(' | ', $avvisi))
        ->and(collect($avvisi)->contains(fn (string $a) => str_contains($a, 'Toglilo dal Wallet')))
        ->toBeFalse('L\'avviso dice ancora di togliere dal Wallet un saldo che il programma non vede: ' . implode(' | ', $avvisi))
        ->and($ancoraValida(explode(' | ', $dalloStorico)))->toBe([], 'Gli avvisi dell\'annullamento nello storico dicono ancora la fermata come valida: ' . $dalloStorico);
});

// ---------------------------------------------------------------------------------------------------------------------
// G12 e la concordanza: l'avviso di «Annulla il conguaglio» (decisione 83.2) legge il registro della fermata
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Le due vendite dopo la fine «Scrivi» della decisione 81.2: Ugo → Elsa il 1/9 (fermata, € 120,33 verso Elsa) e Elsa → Carlo il 1/11 (€ 60,16
 * verso Carlo, a carico di Ugo, o di Elsa se la prima fermata è già regolata). Le cifre (di `CorrezioniDellaBeta50Test`): la prima vendita,
 * Ugo 62 giorni, Elsa 122: 18148 × 122/184 = 12032,91 → 12033; la seconda, Ugo 62, Elsa 61, Carlo 61: 6115,09 / 6016,46 / 6016,46, il
 * centesimo a pari resto a chi esce (Elsa) → Carlo 6016. Controlla che il registro della seconda abbia chi esce e niente Wallet.
 */
function sbfDueVendite($test): array
{
    $s = sbfDopoLaPrimaVendita($test);
    $s['seconda'] = fds50Registra($test, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01'))['subentro'];
    $f = fds50Fermate($s['seconda'])[0] ?? [];
    expect([(int) ($f['cifra'] ?? 0), $f['persona'] ?? null, $f['uscente'] ?? null, $f['wallet'] ?? null])->toBe([6016, 'Ugo', 'Elsa', false]);

    return $s;
}

it('G12 (1) — due vendite dopo la fine «Scrivi» (Ugo → Elsa il 1/9, Elsa → Carlo il 1/11): l\'avviso di «Annulla il conguaglio» per la seconda dice «a carico di Ugo, o di Elsa», come il pannello e il registro, e non indica una riga del Wallet che il pannello non aveva indicato', function () {
    // `FermataDopoScrivi::avvisiDelConguaglio` legge solo persona, verso e cifra: non `uscente`, `dopo_la_fermata` né `wallet`. Il pannello
    // della seconda vendita ha detto «€ 60,16 restano a carico di Ugo, o di Elsa se la fermata della vendita del 1 settembre 2026 è già stata
    // regolata… il programma non lo sa», senza il Wallet (registro: `wallet` falso). L'avviso si legge prima di confermare (lo storico della
    // fine, `conguaglio.avvisi`) e nel messaggio.
    $s = sbfDueVendite($this);

    $prima = fds50Storico($s, $s['fine'])['conguaglio']['avvisi'] ?? [];
    [, $messaggio] = sbfMessaggio(sbfAnnullaConguaglio($this, $s, $s['fine'])->assertSessionHasNoErrors());

    // Controllo: l'avviso della prima vendita, con la sua cifra (senza saldi legati alla vendita dice l'accordo fra le parti da rivedere).
    expect(collect($prima)->contains(fn (string $a) => str_contains($a, '€ 120,33') && str_contains($a, 'La vendita del 1 settembre 2026')))->toBeTrue();

    // L'avviso della seconda vendita è quello con la sua cifra e senza quella della prima (che può dire, con la decisione 82.2, la parte
    // passata a Carlo). Nel messaggio gli avvisi sono uno dopo l'altro: si guarda il testo tutto. La riga del Wallet si cerca in ogni forma
    // («a credito di Ugo, a debito di Carlo», ma anche «a credito di Ugo, o di Elsa se …, a debito di Carlo», come negli avvisi
    // dell'annullamento del passaggio): con `wallet` falso non va indicata in nessuna.
    $rigaDelWallet = '/a credito di [^)]*a debito di Carlo/u';
    $dellaSeconda = array_values(array_filter($prima, fn (string $a) => str_contains($a, '€ 60,16') && ! str_contains($a, '€ 120,33')));
    expect($dellaSeconda)->toHaveCount(1)
        ->and(array_values(array_filter($dellaSeconda, fn (string $a) => str_contains($a, 'a carico di Ugo') && ! str_contains($a, 'o di Elsa'))))
        ->toBe([], 'L\'avviso della seconda vendita perde «o di Elsa»: ' . implode(' | ', $dellaSeconda))
        ->and(array_values(array_filter($dellaSeconda, fn (string $a) => preg_match($rigaDelWallet, $a) === 1)))
        ->toBe([], 'L\'avviso della seconda vendita indica la riga del Wallet, che il pannello non aveva indicato: ' . implode(' | ', $dellaSeconda))
        ->and(preg_match($rigaDelWallet, $messaggio))->toBe(0, 'Il messaggio indica la riga del Wallet per la seconda vendita: ' . $messaggio)
        ->and(str_contains($messaggio, 'o di Elsa'))->toBeTrue('Il messaggio perde «o di Elsa»: ' . $messaggio);
});

it('G12 (1), lo storico — annullato il conguaglio della fine, lo storico della seconda vendita dice che la fermata non vale più senza perdere l\'alternativa «o fra Elsa e Carlo»', function () {
    // `FermataDopoScrivi::fraseStoricoAnnullata` dice «rimasti a carico di Ugo da regolare a mano con Carlo»; prima dell'annullamento lo
    // storico della stessa vendita (`fraseStorico`) diceva «… con Carlo, o fra Elsa e Carlo se la fermata della vendita del 1 settembre 2026
    // era già stata regolata».
    $s = sbfDueVendite($this);
    $primaDellAnnullamento = implode(' ', fds50Storico($s, $s['seconda'])['fermate'] ?? []);

    sbfAnnullaConguaglio($this, $s, $s['fine'])->assertSessionHasNoErrors();
    $storico = implode(' ', fds50Storico($s, $s['seconda'])['fermate'] ?? []);

    // Controlli: prima l'alternativa c'è; dopo, la fermata non vale più.
    expect($primaDellAnnullamento)->toContain('o fra Elsa e Carlo se la fermata della vendita del 1 settembre 2026 era già stata regolata')
        ->and($storico)->toMatch('/non val(e|gono) più/u');

    expect(preg_match('/€ 60,16[^.]*o (di|fra) Elsa/u', $storico))->toBe(1, 'Lo storico della seconda vendita, annullato il conguaglio, perde l\'alternativa di Elsa: ' . $storico);
});

it('G12 (2) — Ugo ha già un saldo sulla gestione, la fine «Scrivi» e la vendita a Elsa del 1/10: il pannello indica il Wallet con i saldi legati, e senza saldi legati l\'avviso di «Annulla il conguaglio» non indica una riga del Wallet (€ 90,74 a credito di Ugo, a debito di Elsa) che non c\'è', function () {
    // Dalla 1.11.0-beta.53 la riga legata non occupa il posto: il pannello indica il Wallet anche con il riporto di Ugo. La fermata: 18148 × 92/184 = 9074 (€ 90,74).
    $s = sbfScenario($this, [['Ugo', 'proprietario', 100]], riporti: ['Ugo' => 5000]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-09-30');
    fclPersona($s, 'Elsa');
    $registrata = fds50Registra($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'));
    $f = fds50Fermate($registrata['subentro'])[0] ?? [];

    $prima = fds50Storico($s, $fine)['conguaglio']['avvisi'] ?? [];
    [, $messaggio] = sbfMessaggio(sbfAnnullaConguaglio($this, $s, $fine)->assertSessionHasNoErrors());

    // Controlli: la fermata è di € 90,74 con il Wallet, e il pannello della vendita l'ha detto.
    expect([(int) ($f['cifra'] ?? 0), $f['wallet'] ?? null])->toBe([9074, true])
        ->and(implode(' ', $registrata['anteprima']['rate']['frasi']))->toContain('registra dal Wallet i saldi legati a questo passaggio');

    $avvisi = array_values(array_filter([...$prima, ...sbfPezzi($messaggio)], fn (string $a) => str_contains($a, '€ 90,74')));
    expect($avvisi)->not->toBe([])
        ->and(array_values(array_filter($avvisi, fn (string $a) => preg_match('/a credito di [^)]*a debito di Elsa/u', $a) === 1)))
        ->toBe([], 'L\'avviso indica una riga del Wallet che non c\'è: ' . implode(' | ', $avvisi));
});

it('la concordanza — dopo la fermata di un legato, l\'avviso di «Annulla il conguaglio» non dice «Il legato del 1 ottobre 2026 si è fermata»', function () {
    // `FermataDopoScrivi::avvisiDelConguaglio` ha la frase fissa al femminile, «%s si è fermata su questo conguaglio», ma `passaggioInParole`
    // dà anche «il legato del …». Il legato ha l'arretrato al defunto: con «Scrivi» cambia chi sta al posto dell'inquilino e si ferma come la
    // vendita (decisione 79, nota delle misure). La fermata: Ugo 1/7–30/9 e Anna 1/10–31/12, 92 giorni ciascuno, 18148 × 92/184 = 9074.
    $s = fds50Scenario();
    fds50Genera($s);
    fds50Emetti($this, $s, '2026-06-30');
    $fine = fds50Fine($this, $s, Subentro::SCRIVI_IL_CONGUAGLIO);
    fds50Emetti($this, $s, '2026-09-30');
    $legato = fds50Registra($this, $s, ['tipo' => 'successione', 'sottotipo' => Subentro::LEGATO, 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, 'Ugo', 'proprietario'),
        'decorrenza' => '2026-10-01', 'quota' => 100, 'eredi' => [['anagrafica_id' => $s['persone']['Anna']->id, 'quota' => 100]], 'arretrato' => Subentro::ARRETRATO_AL_DEFUNTO,
        'erede_di_riferimento' => null, 'copia_autentica' => false, 'estremi_titolo' => 'testamento pubblicato, rep. 55', 'pertinenze' => [], 'conguaglio' => 'scrivi'])['subentro'];

    $avvisi = implode(' | ', fds50Storico($s, $fine)['conguaglio']['avvisi'] ?? []);

    // Controlli: è un legato, si è fermato su € 90,74 verso Anna, e l'avviso lo dice.
    expect($legato->legato())->toBeTrue()
        ->and(collect(fds50Fermate($legato))->map(fn ($x) => [(int) $x['cifra'], $x['verso']])->all())->toBe([[9074, ['Anna']]])
        ->and($avvisi)->toContain('legato del 1 ottobre 2026')
        ->and($avvisi)->toContain('€ 90,74');

    expect(preg_match('/[Ii]l legato del 1 ottobre 2026 si è fermata/u', $avvisi))->toBe(0, 'Concordanza: ' . $avvisi);
});

it('la concordanza, la guardia — dopo la fermata di una vendita l\'avviso dice «La vendita del 1 ottobre 2026 si è fermata su questo conguaglio»', function () {
    $s = sbfCasoH($this);

    expect(implode(' | ', fds50Storico($s, $s['fine'])['conguaglio']['avvisi'] ?? []))->toContain('La vendita del 1 ottobre 2026 si è fermata su questo conguaglio');
});

// ---------------------------------------------------------------------------------------------------------------------
// G13: il pannello della fermata dopo una fermata non dice «ha preso» una cifra che quella persona non ha preso
// ---------------------------------------------------------------------------------------------------------------------

it('G13 (A) — successione di Ugo il 1/10 ad Anna e Bruno con l\'arretrato agli eredi, Anna vende la sua metà a Carlo il 1/11: il pannello non dice «Anna ha preso € 90,74», che la coppia l\'ha presa Ugo (€ 181,48)', function () {
    // `FermataDopoScrivi::dopoUnaFermata`, ramo dell'erede: 'coppia' => la sua parte, senza `uscente_id`, quindi la frase della prima fermata
    // («dei giorni dopo la sua uscita %s ha preso %s»). La parte di Anna le è arrivata con la successione: 18148 a metà, 9074. La fermata
    // (controllo, da `RevisioneBeta50MotoreTest`): 18148 × 3050/18400 = 3008,23 → 3008 (€ 30,08) verso Carlo, a carico di Anna.
    $s = sbfScenario($this, [['Ugo', 'proprietario', 100]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-09-30');
    foreach (['Anna', 'Bruno', 'Carlo'] as $nome) {
        fclPersona($s, $nome);
    }
    fds50Registra($this, $s, fds50ModuloSuccessione($s, Subentro::ARRETRATO_AGLI_EREDI, null, '2026-10-01'));
    fclEmetti($this, $s, '2026-10-31');

    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Anna', 'Carlo', '2026-11-01', 50));
    $frasi = implode(' ', $a['rate']['frasi']);

    // Controlli: la coppia è di Ugo, la fermata è € 30,08 verso Carlo a carico di Anna.
    expect(fds50Coppia($s, $fine))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and(collect($a['rate']['fermate_dopo_scrivi'])->map(fn ($f) => [$f['persona'], (int) $f['cifra'], $f['verso']])->all())->toBe([['Anna', 3008, ['Carlo']]])
        ->and($frasi)->toContain('€ 30,08');

    expect(sbfHaPresoNonVero($frasi, fds50Coppia($s, $fine)))->toBe([], 'Il pannello dice «ha preso» una cifra che non è la riga di quella persona nella coppia: ' . $frasi);
});

it('G13 (B) — Ugo e Rita comproprietari al 50 %, Ugo vende la sua metà a Elsa il 1/9, Elsa rivende a Carlo il 1/11: il pannello non dice «Ugo ha preso € 181,48», che è la coppia di tutti e due (Ugo € 90,74)', function () {
    // `FermataDopoScrivi::dopoUnaFermata`, ramo ristretto alla persona della fermata di prima: `$hanno` è solo Ugo, ma 'coppia' =>
    // `$c['totale']`, tutta la coppia. La coppia: Ugo 9074, Rita 9074. La fermata di Carlo (controllo, da `RevisioneBeta50FrasiTest`): 18148 ×
    // 30,5/184 = 3008,23 → 3008 (€ 30,08), a carico di Ugo.
    $s = sbfDopoLaPrimaVendita($this, [['Ugo', 'proprietario', 50], ['Rita', 'proprietario', 50]], 50);

    $a = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01', 50));
    $frasi = implode(' ', $a['rate']['frasi']);

    expect(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Rita' => 9074, 'Ugo' => 9074])
        ->and(collect($a['rate']['fermate_dopo_scrivi'])->map(fn ($f) => [$f['persona'], (int) $f['cifra'], $f['verso']])->all())->toBe([['Ugo', 3008, ['Carlo']]])
        ->and(collect(fds50Fermate($s['prima']))->map(fn ($f) => [$f['persona'], (int) $f['coppia']])->all())->toBe([['Ugo', 9074]]);

    expect(sbfHaPresoNonVero($frasi, fds50Coppia($s, $s['fine'])))->toBe([], 'Il pannello dice «ha preso» una cifra che non è la riga di quella persona nella coppia: ' . $frasi);
});

it('G13, la guardia — la prima fermata dice «Ugo ha preso € 181,48», la sua riga nella coppia', function () {
    // Il ramo semplice di `FermataDopoScrivi::frasePannello`, giusto: è la cifra che la correzione dei due rami deve tenere.
    $s = sbfScenario($this, [['Ugo', 'proprietario', 100]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($this, $s, '2026-09-30');
    fclPersona($s, 'Elsa');

    $frasi = implode(' ', fclAnteprima($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-10-01'))['rate']['frasi']);

    expect($frasi)->toContain('Ugo ha preso € 181,48')
        ->and(sbfHaPresoNonVero($frasi, fds50Coppia($s, $fine)))->toBe([]);
});

// ---------------------------------------------------------------------------------------------------------------------
// G14 e G15: la strada del Wallet nello storico e nel pannello (dalla 1.11.0-beta.55 il resto della regola del piano; la fermata del coinquilino
// resta nei registri delle beta, «G14, con i saldi»)
// ---------------------------------------------------------------------------------------------------------------------

it('G14 — dopo la fine di Ines con «Scrivi», la fine di Dora, nessuno ha un saldo: il pannello indica il Wallet con i saldi legati al passaggio per il resto della regola del piano, e lo storico dice la stessa strada, non «da regolare a mano fra le parti»', function () {
    // Coda 245 (1.11.0-beta.55): fino alla .54 la prova era sulla fermata del coinquilino della fine di Ines (€ 90,74, `fermata_coinquilino` con
    // `al_posto` [Ugo]), che non si scrive più: la fine di Ines con Dora che resta ha la regola del piano. La strada a mano del Wallet c'è nel resto della
    // fine di Dora dopo quella fine (decisione 95.6 b, il caso C4): Dora − 7875 → Ugo, e restano fuori Ines − 1199 e Ugo + 1199; il registro lo conserva in
    // `resto_del_piano` con `wallet` vero e `strada` = `legata`. Gli avvisi dell'annullamento non dicono «Toglilo dal Wallet»: senza saldi legati non c'è
    // niente da togliere, e l'avviso dice che le parti, se l'avevano regolata, rivedono l'accordo.
    $s = sbfScenario($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    $mod = ['quota' => 50] + fclModulo($s, null, '2026-10-01', [], 'Dora');

    $a = fclAnteprima($this, $s, $mod);
    $fine = fclRegistra($this, $s, $mod + ['conguaglio' => 'scrivi'])->fresh();
    $r = $fine->registro['resto_del_piano'][0] ?? [];
    $storico = collect(fds50Con(sbfStoricoSenzaAnnullabile($s, $fine), '€ 11,99'))->first(fn (string $t) => str_contains($t, 'Ines')) ?? '';

    // Controlli: il pannello indica il Wallet, il registro lo conserva.
    expect(implode(' ', $a['rate']['frasi']))->toContain('registra dal Wallet i saldi legati a questo passaggio')
        ->and([(int) ($r['cifra'] ?? 0), $r['wallet'] ?? null, $r['strada'] ?? null])->toBe([1199, true, 'legata'])
        ->and(implode(' | ', app(AnnullaPassaggioAction::class)->avvisi($fine->fresh())))->not->toContain('Toglilo dal Wallet')
        ->and($storico)->not->toBe('');

    expect(str_contains($storico, 'Wallet'))->toBeTrue('Lo storico del resto non dice la strada che il pannello aveva indicato (il Wallet): ' . $storico)
        ->and($storico)->not->toContain('fra le parti');
});

it('G14, con i saldi — Ugo e Dora hanno già un saldo: lo storico di una fine con la fermata del coinquilino dice lo stesso il Wallet con i saldi legati, non «fra le parti»', function () {
    // Coda 245 (1.11.0-beta.55): la registrazione non scrive più la fermata del coinquilino (la fine ha la regola del piano). Il registro delle
    // beta .50–.53 si scrive qui a mano, come lo scriveva allora, e lo storico lo legge com'era: la frase dello storico resta vera.
    $s = sbfScenario($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]], riporti: ['Ugo' => 5000, 'Dora' => 3000]);

    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere'])->fresh();
    $registro = $fine->registro;
    unset($registro['conguaglio'], $registro['regolato_fuori'], $registro['coinquilini_letti']);
    $registro['piani_presi'] = [];
    $registro['fermata_coinquilino'] = [['immobile_id' => (int) $s['unita']->id, 'unita' => 'Interno 1', 'gestione_id' => (int) $s['g']->id, 'gestione' => 'Ordinaria 2026',
        'esercizio_id' => (int) $s['e']->id, 'cifra' => 9074, 'persona_id' => (int) $s['persone']['Ines']->id, 'persona' => 'Ines', 'restano' => ['Dora'], 'al_posto' => ['Ugo'],
        'wallet' => true, 'strada' => 'legata']];
    $fine->registro = $registro;
    $fine->save();
    $storico = implode(' ', fds50Con(sbfStoricoSenzaAnnullabile($s, $fine->fresh()), '€ 90,74'));

    expect($storico)->toContain('la strada indicata erano i saldi dal Wallet legati a questo passaggio')
        ->and($storico)->not->toContain('fra le parti');
});

it('G15 (A) — dopo la fine di Ines, la fine di Dora quando Ugo e Dora hanno già un saldo: la frase dice le persone del resto, ognuna con la sua cifra, non due alternative, con il Wallet legato', function () {
    // Coda 245 (1.11.0-beta.55): fino alla .54 qui c'era la fermata del coinquilino della fine di Ines, che non sapeva chi pagava i giorni dopo l'uscita
    // e diceva due alternative, «Dora, o Ugo». La regola del piano lo sa: nel resto della fine di Dora (C4) Ines − 1199 e Ugo + 1199, persone e cifre,
    // con la strada del Wallet anche per Ugo, che ha già un saldo (dalla 1.11.0-beta.53 la riga legata non occupa il posto). I riporti sono saldi
    // pregressi, fuori dall'anello: le cifre non cambiano.
    $s = sbfScenario($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]], riporti: ['Ugo' => 5000, 'Dora' => 3000]);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-10-01', [], 'Dora'));
    $frase = $a['rate']['frasi'][0] ?? '';

    // Controlli: il resto c'è, € 11,99, con il Wallet legato.
    expect(collect($a['rate']['resto_del_piano'])->sum('cifra'))->toBe(1199)
        ->and($a['rate']['resto_del_piano'][0]['wallet'] ?? null)->toBeTrue()
        ->and($frase)->toContain('registra dal Wallet i saldi legati a questo passaggio, sulla gestione Ordinaria 2026: € 11,99 a credito di Ines e € 11,99 a debito di Ugo.')
        ->and($frase)->not->toContain('fra le parti');

    expect(str_contains($frase, 'Dora, o Ugo'))->toBeFalse('La frase dice ancora due alternative: ' . $frase);
});

it('G15 (B) — Ugo e Rita comproprietari (Rita con un saldo), Ines esce e poi Dora, mentre Pia resta: la coda del Wallet dice la riga di ciascuna persona del resto, con la sua cifra', function () {
    // Coda 245 (1.11.0-beta.55): fino alla .54 la prova era sulla fermata del coinquilino della fine di Ines, con la coda del Wallet al plurale per il
    // gruppo «Dora e Pia» che poteva pagare. Ora la fine di Ines con Dora e Pia che restano ha la regola del piano, e la coda del Wallet è quella del resto
    // della fine di Dora (decisione 95.6 b, il caso C3-Dora): Ines 40, Dora 30, Pia 30; dopo la fine di Ines Ines 8944, Dora 13528, Pia 13528; Dora esce il
    // 1/10 e Pia resta: 7240, 8190, 10950 (somma 26380) → Ines 9880, Dora 11177, Pia 14943. Dora − 2351 → Pia, e restano fuori Ines + 936 e Pia − 936:
    // una riga per persona, con la sua cifra. Ugo e Rita non sono sull'anello (Pia copre tutto l'anno); il saldo di Rita non c'entra.
    $s = sbfScenario($this, [['Ugo', 'proprietario', 50], ['Rita', 'proprietario', 50]], ['inquilini' => [['Ines', 40], ['Dora', 30], ['Pia', 30]]], riporti: ['Rita' => 1000]);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    $a = fclAnteprima($this, $s, ['quota' => 30] + fclModulo($s, null, '2026-10-01', [], 'Dora'));
    $frase = $a['rate']['frasi'][0] ?? '';

    expect(collect($a['rate']['resto_del_piano'][0]['parti'] ?? [])->pluck('cifra', 'nome')->all())->toBe(['Ines' => 936, 'Pia' => -936])
        ->and($frase)->not->toContain('la parte si regola fra le parti')
        ->and($frase)->toContain('registra dal Wallet i saldi legati a questo passaggio, sulla gestione Ordinaria 2026: € 9,36 a debito di Ines e € 9,36 a credito di Pia.');
});

it('G15 (B), chi sta al posto in due — Ugo e Rita comproprietari senza un saldo, Ines e Dora inquiline al 50 %: dopo la fine di Ines la fine di Dora divide la sua coppia fra Ugo e Rita, e la coda del Wallet dice la riga di ciascuno, con la sua cifra', function () {
    // Coda 245 (1.11.0-beta.55): fino alla .54 la prova era sulla fermata del coinquilino della fine di Ines, con «Dora, o Ugo e Rita» come alternative.
    // Ora la regola del piano: dopo la fine di Ines con «Scrivi» Ines 11934, Dora 24066; Dora esce il 1/10 e i 92 giorni dal 1/10 vanno a chi sta al
    // posto dell'inquilino, Ugo e Rita al 50 %: 36000 × 46 / 365 = 4536,99 ciascuno, Ines 10734,85, Dora 16191,18 → Ines 10735, Dora 16191, Ugo 4537, Rita
    // 4537. La differenza di Dora, − 7875, si divide fra Ugo e Rita in proporzione alle loro differenze (4537 e 4537): 3937,5 ciascuno, il centesimo a
    // pari resto a chi viene prima, Ugo → Ugo 3938, Rita 3937. Restano fuori Ines − 1199, Ugo + 599, Rita + 600: una riga per persona, con la sua cifra.
    $s = sbfScenario($this, [['Ugo', 'proprietario', 50], ['Rita', 'proprietario', 50]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    $a = fclAnteprima($this, $s, ['quota' => 50] + fclModulo($s, null, '2026-10-01', [], 'Dora'));
    $frase = $a['rate']['frasi'][0] ?? '';
    $r = $a['rate']['resto_del_piano'][0] ?? [];

    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Rita', 3937], [(int) $s['unita']->id, 'Ugo', 3938]])
        ->and(collect($r['parti'] ?? [])->pluck('cifra', 'nome')->all())->toBe(['Ines' => -1199, 'Ugo' => 599, 'Rita' => 600])
        ->and([$r['wallet'] ?? null, $r['strada'] ?? null])->toBe([true, 'legata'])
        ->and($frase)->toContain('registra dal Wallet i saldi legati a questo passaggio, sulla gestione Ordinaria 2026: € 11,99 a credito di Ines, € 5,99 a debito di Ugo e € 6,00 a debito di Rita.');
});

// ---------------------------------------------------------------------------------------------------------------------
// G16: la frase dei giorni scoperti (R2) con un'entrata e con più unità
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Il piano a capitoli: «Spese generali» € 1.200,00 per tutto l'anno e «Affitto antenna», un conto di entrata, con la competenza data, sulla
 * stessa tabella e con la stessa divisione (30 % «Inquilino», 70 % «Proprietario»). Prima della generazione. È `rbmConLAntenna` di
 * `RevisioneBeta50MotoreTest`.
 */
function sbfConLAntenna(array $s, int $importo, string $dal, string $al): void
{
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $antenna = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Affitto antenna', 'tipo' => 'entrata', 'natura_spesa' => 'ordinaria', 'importo' => $importo]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $antenna->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    foreach (['inquilino' => 30, 'proprietario' => 70] as $soggetto => $percentuale) {
        DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => $soggetto, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
    }
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $s['piano']->capitoli()->attach([$spese->id => ['importo' => 120000], $antenna->id => ['importo' => $importo]]);
    $pivot = (int) DB::table('piano_rate_capitoli')->where('piano_rate_id', $s['piano']->id)->where('conto_id', $antenna->id)->value('id');
    \App\Models\Gestionale\CompetenzaCapitolo::create(['piano_rate_capitolo_id' => $pivot, 'dal' => $dal, 'al' => $al, 'ordine' => 0]);
}

it('G16 (1) — la riga di Ugo finisce il 30/9 e l\'«Affitto antenna» di entrata ha competenza 1/10–31/12: la frase dei giorni scoperti non dice «€ -89,26 resta nelle rate di Ines», ma la cifra a credito, con le parole', function () {
    // `ConguaglioPassaggio`, la frase di R2: `MoneyHelper::format` sulla parte scoperta anche negativa. Le cifre: l'antenna € 600,00, 30 %
    // all'inquilino = −18000, tutta nel 1/10–31/12 (92 giorni dopo l'uscita); le spese dopo l'uscita 18148. Ugo copre il 1/7–30/9: 18148 ×
    // 92/184 = 9074 delle spese, niente dell'antenna → 9074. La parte scoperta: 18148 − 18000 − 9074 = −8926 (€ 89,26 a credito). La regola
    // (rilievo L1 della .44): una cifra negativa si dice con le parole, mai «€ -89,26».
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    sbfConLAntenna($s, 60000, '2026-10-01', '2026-12-31');
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Ugo', 'proprietario'))->update(['data_fine' => '2026-09-30']);

    $a = fclAnteprima($this, $s, fclModulo($s));
    $scoperti = collect(fclPeriodi(fclFrasi($a)))->first(fn (string $p) => str_contains($p, 'nessuno sta al posto dell\'inquilino') && str_contains($p, '89,26')) ?? '';

    // Controlli: la coppia è di Ugo, 9074; la frase dei giorni scoperti c'è, con la cifra.
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 9074]])
        ->and($scoperti)->not->toBe('');

    expect(str_contains($scoperti, '€ -'))->toBeFalse('Una cifra negativa con il meno dentro: ' . $scoperti)
        ->and(str_contains($scoperti, 'a credito'))->toBeTrue('La frase non dice che la parte scoperta è a credito: ' . $scoperti);
});

it('G16 (2) — Interno 1 di Ugo e Box 12 di Rita, la riga di Rita chiusa il 31/10: la frase dei giorni scoperti dice l\'unità, il «Box 12», non solo la gestione (sull\'Interno 1 in quei giorni Ugo c\'è)', function () {
    // La frase usa la gestione e non `immobile_nome` del gruppo, che è per gestione e unità. Le cifre (di `RevisioneBeta50MotoreTest`, R2
    // sul box): 3600 × 184/365 = 1814,79 → 1815; 1815 × 61/184 = 601,71 → 602 scoperti; Rita 1815 − 602 = 1213. L'Interno 1, 900 millesimi:
    // 32400 × 184/365 = 16333,15 → 16333, tutta di Ugo.
    $s = sbfScenario($this, [['Ugo', 'proprietario', 100]], ['box' => [['Rita', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Rita', 'proprietario', $s['box']))->update(['data_fine' => '2026-10-31']);

    $a = fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]));
    $scoperti = collect(fclPeriodi(fclFrasi($a)))->first(fn (string $p) => str_contains($p, 'nessuno sta al posto dell\'inquilino') && str_contains($p, '€ 6,02')) ?? '';

    // Controlli: le coppie delle due unità, e la frase dei giorni scoperti con la cifra del box.
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Ugo', 16333], [(int) $s['box']->id, 'Rita', 1213]])
        ->and($scoperti)->not->toBe('');

    expect(str_contains($scoperti, '«Box 12»'))->toBeTrue('La frase dei giorni scoperti non dice di quale unità parla: ' . $scoperti);
});

// ---------------------------------------------------------------------------------------------------------------------
// G17 e G18: la testa del prospetto e il pannello dell'inizio dopo una fine con i giorni scoperti, e dopo la catena della 83.3
// ---------------------------------------------------------------------------------------------------------------------

/**
 * La riga di Ugo chiusa a mano al 30/9 e nessuno dopo, la fine di Ines il 1/7 con «Scrivi» (la coppia sui 92 giorni coperti: 18148 × 92/184 =
 * 9074, Ines −9074, Ugo +9074; gli altri 92 giorni restano nelle rate di Ines), le rate fino al 31/8, Luca nel condominio.
 */
function sbfRigaDiUgoChiusa($test): array
{
    $s = sbfScenario($test, [['Ugo', 'proprietario', 100]]);
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Ugo', 'proprietario'))->update(['data_fine' => '2026-09-30']);
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(fclSaldi($s))->toBe(['Ines' => -9074, 'Ugo' => 9074]);
    fclEmetti($test, $s, '2026-08-31');
    fclPersona($s, 'Luca');

    return $s;
}

it('G17 — la riga di Ugo chiusa al 30/9, la fine di Ines con «Scrivi», l\'inizio di Luca il 1/9: la testa del prospetto non dice per la stessa fine e lo stesso piano che una parte dei giorni è rimasta a chi è uscito e poi che tutti i giorni dopo l\'uscita li ha presi con la coppia chi paga al posto dell\'inquilino', function () {
    // `ProspettoOneriAccessori::frasiDellUscita`: la frase della strada B resta per tutti i giorni, e la nuova «scritto, ma in una parte …» le
    // sta accanto; con un piano solo `$dove` è vuoto. Le voci (controllo): Luca dal 1/9, 122 giorni: i 30 di settembre pagati da Ugo con la
    // coppia, 9074 × 30/92 = 2958,91 → 2959; i 92 dal 1/10 pagati da Ines, 36000 × 92/365 = 9073,97 → 9074. 2959 + 9074 = 12033 = 36000 ×
    // 122/365 = 12032,88 → 12033.
    $s = sbfRigaDiUgoChiusa($this);
    fclRegistra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-09-01'));

    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $dellaFine = array_values(array_filter($p['intestazione'], fn (string $f) => str_contains($f, 'Alla fine della locazione del 01/07/2026')));
    $stampa = fclStampa($s);

    // Controlli: le voci di Luca, e la testa dice la fine con il conguaglio scritto.
    expect(sbfVociDi($s, 'Luca'))->toBe([['Ines', 'A', 9074, 92], ['Ugo', 'B', 2959, 30]])
        ->and(collect($dellaFine)->contains(fn (string $f) => str_contains($f, 'il conguaglio è stato scritto')))->toBeTrue();

    // Tutti i giorni dopo l'uscita «li ha presi con la coppia», accanto a «una parte … nessuno stava»: una lettura sola, o «gli altri giorni».
    // E la testa continua a dire che gli altri giorni li ha presi con il conguaglio chi paga al posto dell'inquilino: togliere la frase della
    // strada B lascerebbe le voci «pagata da Ugo» senza la loro lettura.
    $tutti = '/il conguaglio è stato scritto: i giorni dopo l\'uscita li ha presi con la coppia/u';
    expect(array_values(array_filter($dellaFine, fn (string $f) => preg_match($tutti, $f) === 1)))
        ->toBe([], 'La testa del prospetto si contraddice sulla stessa fine: ' . implode(' | ', $dellaFine))
        ->and(preg_match($tutti, $stampa))->toBe(0, 'La stampa ripete le due letture della stessa fine.')
        ->and(preg_match('/con la coppia|con il conguaglio/u', implode(' ', $dellaFine)))
        ->toBe(1, 'La testa non dice più chi ha preso con il conguaglio gli altri giorni dopo l\'uscita: ' . implode(' | ', $dellaFine));
});

it('G18 (A) — lo stesso caso, l\'anteprima dell\'inizio di Luca il 1/9: il pannello non dice «vale la scelta sul conguaglio della fine di ciascuno» per un\'uscita sola, ma che Luca rimborsa Ugo e Ines, come il prospetto', function () {
    // `AnteprimaPassaggio::dopoLUscitaDiPrima`, modello «misto» (da `ProspettoOneriAccessori::chiHaPagatoDopo`, che dà «scritto» per i giorni
    // di Ugo e «non_scritto» per quelli scoperti): l'uscita è una sola, e la scelta «Scrivi» quei 92 giorni non li ha spostati. Il prospetto
    // (controllo): Luca rimborsa € 29,59 a Ugo ed € 90,74 a Ines.
    $s = sbfRigaDiUgoChiusa($this);

    $a = fclAnteprima($this, $s, fds50ModuloInizio($s, 'Luca', '2026-09-01'));
    $testo = implode(' | ', [...$a['rate']['frasi'], ...($a['cancello']['informazioni'] ?? [])]);
    fclRegistra($this, $s, fds50ModuloInizio($s, 'Luca', '2026-09-01'));

    expect(sbfVociDi($s, 'Luca'))->toBe([['Ines', 'A', 9074, 92], ['Ugo', 'B', 2959, 30]]);

    $rimborsi = collect(sbfPezzi($testo))->filter(fn (string $p) => str_contains($p, 'li rimborsa Luca'))->implode(' ');
    expect(str_contains($testo, 'della fine di ciascuno'))->toBeFalse('Il pannello dice il modello «misto» per un\'uscita sola: ' . $testo)
        ->and(str_contains($rimborsi, 'Ugo') && str_contains($rimborsi, 'Ines'))->toBeTrue('Il pannello non dice che Luca rimborsa Ugo e Ines: ' . $testo);
});

it('G18 (B) — decisione 83.3: il cambio Ines → Luca «regolato fra le parti», la fine di Luca il 1/9 con «Scrivi», l\'inizio di Anna il 1/11: il pannello dice che i giorni li ha presi Ugo con la fine del 1 settembre 2026, non «dopo la sua uscita» di Ines', function (string $modo) {
    // `dopoLUscitaDiPrima`, modello «scritto»: «la sua uscita» è quella di Ines (1/7), ma Ugo li ha presi con la fine di Luca del 1/9. La
    // stampa (controllo) è giusta: «pagata da Ugo Rinaldi con il conguaglio della fine locazione del 01/09/2026», e per i giorni di Luca
    // «regolata fra le parti al cambio d'inquilino del 01/07/2026». Le cifre (di `RevisioneBeta50MotoreTest`, R7): Anna 6017, Ugo 6016.
    $s = sbfCambioRegolato($this, $modo);
    pflFine($this, $s, 'Luca Ferri', null, '2026-09-01', 'scrivi');
    $inizio = ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => pflPersona($s, 'Anna Neri')->id, 'decorrenza' => '2026-11-01', 'quota' => 100,
        'tipologia' => 'inquilino', 'copia_autentica' => false, 'regime_contratto' => 'abitativo', 'pertinenze' => []];

    $a = fclAnteprima($this, $s, $inizio);
    $testo = implode(' | ', [...$a['rate']['frasi'], ...($a['cancello']['informazioni'] ?? [])]);
    pflInizio($this, $s, 'Anna Neri', '2026-11-01');
    $stampa = pflStampa($this, $s);

    // Controlli: il prospetto e la stampa seguono la catena; il pannello nomina Ugo (R7).
    expect(pflConduttori(pflProspetto($s))['Anna Neri'] ?? null)->toBe([6017, 0, 6017, 61])
        ->and($stampa)->toContain('pagata da Ugo Rinaldi con il conguaglio della fine locazione del 01/09/2026')
        ->and($testo)->toContain('Ugo Rinaldi');

    expect(str_contains($testo, 'i giorni dopo la sua uscita li ha presi con il conguaglio Ugo Rinaldi'))
        ->toBeFalse('Il pannello attribuisce la coppia all\'uscita di Ines: ' . $testo)
        ->and(str_contains($testo, '1 settembre 2026'))->toBeTrue('Il pannello non dice la fine che ha scritto la coppia (1 settembre 2026): ' . $testo);
})->with([
    'la casella di prima della .50' => ['casella'],
    'il conguaglio scritto e poi annullato' => ['annullato'],
]);

// ---------------------------------------------------------------------------------------------------------------------
// G19 e R19: la frase del saldo manuale nella testa del prospetto
// ---------------------------------------------------------------------------------------------------------------------

/**
 * La gestione «Riscaldamento 2026» con «Combustibile» € 730,00 tutto all'inquilino, e il suo piano in dodici rate mensili dal 5/1, approvato e
 * generato (è `rbfRiscaldamento`). Generato prima della fine e non emesso, la coppia della fine non lo prende.
 */
function sbfRiscaldamento(array $s): \App\Models\Gestionale\PianoRate
{
    $g = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Riscaldamento 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $g->id);
    $pc = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'PC riscaldamento']);
    $conto = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Combustibile', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 73000]);
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'inquilino', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $piano = \App\Models\Gestionale\PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Riscaldamento 2026', 'stato' => 'approvato',
        'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false,
    ]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true,
        notaDestinatari: 'Letto: generazione del riscaldamento', esercizio: $s['e']);

    return $piano;
}

it('G19 — una fine registrata con la .50 («Scrivi», la scelta nel registro) e il riscaldamento che la coppia non ha preso, con un riporto manuale di Ugo: la testa del prospetto non dice «come il pannello indicava nelle versioni precedenti»', function () {
    // `ProspettoOneriAccessori::frasiDellUscita`, R19: `$senzaScelta` è la strada A con la scelta nulla nella voce, e `uscitaDellaLocazione`
    // mette la scelta a null per ogni strada A′ che non è «non scritto», anche dopo «Scrivi». Il pannello della .50 non ha indicato il Wallet
    // per quei giorni. Le cifre (controllo): sul riscaldamento, € 730,00 tutto all'inquilino, 73000 × 184/365 = 36800, esatto, da restituire a
    // Ines; sul preventivo la coppia, 18148.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    fclGenera($s);
    $s['riscaldamento'] = sbfRiscaldamento($s);
    fclEmetti($this, $s, '2026-06-30');
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Ugo' => 18148]);
    $rate = DB::table('rate')->where('piano_rate_id', $s['riscaldamento']->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', '2026-08-31 23:59:59')->pluck('id')->all();
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['riscaldamento']]), ['rate_ids' => $rate, 'data_emissione' => '2026-08-31', 'invia_notifiche' => false])
        ->assertSessionHasNoErrors();
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['Ugo']->id, 'immobile_id' => $s['unita']->id,
        'gestione_id' => $s['riscaldamento']->gestione_id, 'saldo_iniziale' => 5000, 'origine' => 'manuale', 'is_applicato' => false]);

    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $voci = collect(collect($p['conduttori'])->firstWhere('nome', 'Ines')['voci'] ?? [])->merge($p['senza_conduttore']['voci'])->filter(fn ($v) => ! empty($v['strada']));

    // Controlli: la fine ha la scelta della .50; le due strade, una per piano.
    expect($fine->registro['conguaglio']['scelta'] ?? null)->toBe(Subentro::CONGUAGLIO_SCRITTO)
        ->and((int) $voci->where('conto', 'Combustibile')->where('modo', 'restituzione')->sum('importo'))->toBe(-36800)
        ->and($voci->where('conto', 'Spese generali')->pluck('strada')->unique()->values()->all())->toBe(['B']);

    expect(collect($p['intestazione'])->filter(fn (string $f) => str_contains($f, 'versioni precedenti'))->values()->all())
        ->toBe([], 'La testa del prospetto dice la frase delle fini di prima della .50 per una fine della .50: ' . implode(' | ', $p['intestazione']));
});

/**
 * Una fine di prima della .50 (la scelta tolta dal registro, come `pflComePrimaDellaCinquanta`), registrata da questa scena con «Non
 * scriverlo»: il prospetto la legge come strada A′ e chiede di restituire a Ines € 181,48.
 */
function sbfFinePrimaDellaCinquanta($test): array
{
    $s = sbfScenario($test, [['Ugo', 'proprietario', 100]]);
    $s['fine'] = pflComePrimaDellaCinquanta(fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']));

    return $s;
}

it('R19, con un saldo «importato» — una fine di prima della .50 e un saldo di Ugo scritto dall\'importatore (origine «importato»), nessun saldo dal Wallet: la testa del prospetto non dice «Sull\'unità c\'è un saldo manuale scritto dal Wallet»', function () {
    // `ProspettoOneriAccessori::frasiDellUscita` guarda solo `subentro_id` nullo, non `origine`: il saldo dell'importatore (`LivelloSaldi`) non
    // è un saldo manuale dal Wallet, e non è stato scritto per i giorni dopo l'uscita. Le cifre (controllo): 36000 × 181/365 = 17852,05 →
    // 17852; i giorni dopo l'uscita 18148, da restituire a Ines.
    $s = sbfFinePrimaDellaCinquanta($this);
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['Ugo']->id, 'immobile_id' => $s['unita']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 5000, 'origine' => 'importato', 'is_applicato' => false, 'descrizione' => 'Saldo importato']);
    $conImportato = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['intestazione'];

    // Controlli: la strada A′ con la frase senza la scelta; con un saldo manuale dal Wallet (il riporto di Ines) la frase R19 c'è.
    expect(fclProspetto($s))->toBe(['conduttori' => ['ines' => [17852, 36000, -18148, 181]], 'senza' => [18148, 184]])
        ->and(collect($conImportato)->contains(fn (string $f) => str_contains($f, 'Alla fine della locazione del 01/07/2026 nessun conguaglio ha spostato')))->toBeTrue();
    fclRiporto($s, 'Ines', -18148);
    expect(collect(app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e'])['intestazione'])->contains(fn (string $f) => str_contains($f, 'saldo manuale')))->toBeTrue();

    expect(collect($conImportato)->filter(fn (string $f) => str_contains($f, 'saldo manuale'))->values()->all())
        ->toBe([], 'Con il solo saldo dell\'importatore la testa del prospetto dice un saldo manuale scritto dal Wallet: ' . implode(' | ', $conImportato));
});

// ---------------------------------------------------------------------------------------------------------------------
// Il ripiego della .47: una sola unità senza nessuno al posto dell'inquilino non «torna al proprietario»
// ---------------------------------------------------------------------------------------------------------------------

it('il ripiego della .47 — una sola unità, la riga di Ugo chiusa il 30/6 e nessuno dopo: il pannello, «Chi resta obbligato» e lo storico della fine non dicono che le voci «tornano al proprietario», ma che nessuno sta al posto dell\'inquilino', function () {
    // `FrasiObbligati::fraseTornano`, ramo `$stessa`: senza nomi ripiega su `aChi`, «al proprietario»; e il blocco «Rate» di
    // `AnteprimaPassaggio` lo stesso («Dalla prossima generazione … tornano al proprietario»). Con più unità la ripresa della .50 dice già
    // «nessuno sta al posto dell'inquilino dopo l'uscita: … restano scoperte» (`RevisioneBeta50FrasiTest`, il box senza nessuno). Qui la
    // frase «nessuno sta al posto» accanto non c'è.
    $s = sbfScenario($this, [['Ugo', 'proprietario', 100]]);
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Ugo', 'proprietario'))->update(['data_fine' => '2026-06-30']);

    $a = fclAnteprima($this, $s, fclModulo($s));
    $fine = fclRegistra($this, $s, fclModulo($s));
    $pannello = implode(' ', [...$a['rate']['frasi'], ...$a['obbligati']['frasi']]);
    $storico = implode(' ', fds50Testi(sbfStoricoSenzaAnnullabile($s, $fine)));

    // Controlli: nessuno è proprietario dopo il 30/6, la scelta non si chiede e nei saldi non si scrive niente.
    expect(DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('tipologia', '!=', 'inquilino')->whereNull('data_fine')->count())->toBe(0)
        ->and($a['rate']['scelta_del_conguaglio'] ?? null)->toBeFalse()
        ->and(fclSaldi($s))->toBe([]);

    expect(str_contains($pannello, 'tornano al proprietario'))->toBeFalse('Il pannello fa tornare le voci a un proprietario che non c\'è: ' . $pannello)
        ->and(str_contains($storico, 'tornano al proprietario'))->toBeFalse('Lo storico fa tornare le voci a un proprietario che non c\'è: ' . $storico)
        ->and(str_contains(implode(' ', $a['obbligati']['frasi']), 'nessuno sta al posto dell\'inquilino'))->toBeTrue('«Chi resta obbligato» non dice che nessuno sta al posto dell\'inquilino: ' . implode(' ', $a['obbligati']['frasi']));
});

it('il ripiego della .47, la guardia — con la riga di Ugo aperta le voci tornano a Ugo, con il nome', function () {
    $s = sbfScenario($this, [['Ugo', 'proprietario', 100]]);

    $a = fclAnteprima($this, $s, fclModulo($s));

    expect(implode(' ', $a['obbligati']['frasi']))->toContain('le voci a carico dell\'inquilino tornano a Ugo');
});

// ---------------------------------------------------------------------------------------------------------------------
// Le due diciture nuove della stampa del prospetto, che non avevano un test (tolta la dicitura, tornano rossi)
// ---------------------------------------------------------------------------------------------------------------------

it('la stampa, R2 — la riga di Ugo chiusa al 30/9 e la fine di Ines con «Scrivi»: la voce dei giorni che nessuno copriva dice «nessuno al posto dell\'inquilino», non «nessun conguaglio», che per una fine con il conguaglio scritto sarebbe falso', function () {
    // La dicitura nuova di `prospetto_oneri_accessori.blade.php` (`$allaFine`, scelta «scritto»). Le cifre (di `RevisioneBeta50MotoreTest`,
    // R2): Ugo 92 giorni, 18148 × 92/184 = 9074, pagata da lui con la coppia; gli altri 92 giorni, 9074, nelle rate di Ines.
    $s = sbfScenario($this, [['Ugo', 'proprietario', 100]]);
    DB::table('anagrafica_immobile')->where('id', fclRiga($s, 'Ugo', 'proprietario'))->update(['data_fine' => '2026-09-30']);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    $stampa = fclStampa($s);

    expect(fclSaldi($s))->toBe(['Ines' => -9074, 'Ugo' => 9074])
        ->and($stampa)->toContain('92 giorni € 90,74 pagata da Ugo con il conguaglio della fine locazione del 01/07/2026')
        ->and($stampa)->toContain('92 giorni € 90,74 pagata da Ines, rimasta a suo carico (fine della locazione del 01/07/2026, nessuno al posto dell\'inquilino)')
        ->and($stampa)->not->toContain('nessun conguaglio');
});

it('la stampa, decisione 83.3 — dopo il cambio «regolato fra le parti» e la fine di Luca con «Scrivi», la voce di Luca dice «regolata fra le parti al cambio d\'inquilino del 01/07/2026», non «pagata da»', function (string $modo) {
    // La dicitura nuova di `prospetto_oneri_accessori.blade.php` (`$come`, modo «regolato»). Le cifre: Luca 1/7–31/8, 62 giorni, 36000 ×
    // 62/365 = 6115,07 → 6115.
    $s = sbfCambioRegolato($this, $modo);
    pflFine($this, $s, 'Luca Ferri', null, '2026-09-01', 'scrivi');
    pflInizio($this, $s, 'Anna Neri', '2026-11-01');

    $luca = pflSezioneNellaStampa(pflStampa($this, $s), 'Luca Ferri');

    expect(pflConduttori(pflProspetto($s))['Luca Ferri'][0] ?? null)->toBe(6115)
        ->and($luca)->toContain('62 € 61,15 regolata fra le parti al cambio d\'inquilino del 01/07/2026')
        ->and(pflPagataDaSenzaNome($luca))->toBe([]);
})->with([
    'la casella di prima della .50' => ['casella'],
    'il conguaglio scritto e poi annullato' => ['annullato'],
]);

// ---------------------------------------------------------------------------------------------------------------------
// G21: la ricetta del rifiuto della nuda e della piena nate lo stesso giorno segue l'ordine di registrazione (decisione 83.1)
// ---------------------------------------------------------------------------------------------------------------------

/** Un'unità senza piani con le righe date ([nome, ruolo, quota, dal]) e le persone Rita, Nora, Bice, Elsa e Luca (come `urScenario`). */
function sbfUnitaSenzaPiani(array $righe): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "SBF-{$seq}", 'nome' => 'Interno A', 'interno' => 'A']);
    $p = [];
    foreach (['rita', 'nora', 'bice', 'elsa', 'luca'] as $i => $nome) {
        $p[$nome] = Anagrafica::forceCreate(['nome' => ucfirst($nome), 'email' => "sbf-{$nome}{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => strtoupper("SBF{$nome}") . str_pad((string) ($seq * 10 + $i), 8, '0', STR_PAD_LEFT)]);
        $p[$nome]->condomini()->syncWithoutDetaching([$c->id]);
    }
    foreach ($righe as [$nome, $ruolo, $quota, $dal]) {
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p[$nome]->id, 'immobile_id' => $unita->id, 'tipologia' => $ruolo, 'quota' => $quota, 'attivo' => true, 'data_inizio' => $dal, 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    return compact('c', 'unita') + ['p' => $p];
}

/** La registrazione dalla rotta vera, con l'anteprima accettata e la presa d'atto; la risposta. */
function sbfRegistraSullUnita($test, array $s, array $dati): \Illuminate\Testing\TestResponse
{
    $dati += ['copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Atto letto, righe controllate'];
    $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $dati)->assertOk();

    return $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $dati);
}

/** La vendita a Nora dal 1/5, della quota data, dalla riga aperta di chi vende in quel ruolo. */
function sbfVendeANora($test, array $s, string $chi, string $ruolo): Subentro
{
    $riga = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['p'][$chi]->id)->where('tipologia', $ruolo)->whereNull('data_fine')->value('id');
    sbfRegistraSullUnita($test, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $riga, 'anagrafica_entrante_id' => $s['p']['nora']->id, 'decorrenza' => '2026-05-01', 'quota' => 50, 'tipologia' => $ruolo])
        ->assertSessionHasNoErrors()->assertRedirect();

    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

it('G21 — la nuda e la piena di Nora nate il 1/5 (prima la nuda, poi la piena), poi l\'inizio della locazione di Luca dal 1/4, registrato dopo con una data precedente: la ricetta del rifiuto dell\'estinzione comprende l\'inizio di Luca, che si annulla prima della vendita della piena', function () {
    // `AnteprimaPassaggioRequest::fraseNudaEPienaDelloStessoGiorno` ordina per data (`orderBy('decorrenza')`) e taglia dal primo passaggio che
    // ha toccato la riga piena: l'inizio di Luca, del 1/4, resta fuori, e la ricetta dice «Annulla dallo storico dell'unità la vendita da Elsa a
    // Nora, registra questa estinzione e poi di nuovo quel passaggio». Dalla decisione 83.1 si annulla per primo l'ultimo registrato: la
    // vendita della piena non si annulla finché c'è l'inizio di Luca, e la ricetta non dice mai di registrarlo di nuovo.
    $s = sbfUnitaSenzaPiani([['rita', 'nuda_proprietario', 50, '2019-01-01'], ['bice', 'usufruttuario', 50, '2019-01-01'], ['elsa', 'proprietario', 50, '2019-01-01']]);
    sbfVendeANora($this, $s, 'rita', 'nuda_proprietario');
    $piena = sbfVendeANora($this, $s, 'elsa', 'proprietario');
    sbfRegistraSullUnita($this, $s, ['tipo' => 'inizio_locazione', 'anagrafica_entrante_id' => $s['p']['luca']->id, 'decorrenza' => '2026-04-01', 'quota' => 100, 'tipologia' => 'inquilino',
        'data_fine_locazione' => '2030-03-31', 'regime_contratto' => 'abitativo'])->assertSessionHasNoErrors()->assertRedirect();
    $riga = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['p']['bice']->id)->where('tipologia', 'usufruttuario')->whereNull('data_fine')->value('id');
    $estinzione = ['tipo' => 'usufrutto', 'sottotipo' => 'estinzione', 'riga_uscente_id' => $riga, 'decorrenza' => '2026-05-01', 'quota' => 50, 'tipologia' => 'proprietario',
        'copia_autentica' => false, 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Atto letto, righe controllate'];

    $frase = (string) $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $estinzione)->assertUnprocessable()->json('errors.decorrenza.0');

    // Controlli: è il rifiuto della nuda e della piena dello stesso giorno; e la vendita della piena non si annulla finché c'è
    // l'inizio di Luca, registrato dopo con una data precedente (decisione 83.1).
    expect($frase)->toStartWith('Nora è nudo proprietario e proprietario pieno di questa unità da due righe nate lo stesso giorno, il 1 maggio 2026')
        ->and(app(AnnullaPassaggioAction::class)->motivoBlocco($piena->fresh()))->toContain('registrato dopo, con una data precedente')->toContain('l\'inizio locazione a Luca');

    // Si annullano dall'ultimo registrato (Luca prima della vendita della piena) e si registrano di nuovo in ordine di data (l'inizio di Luca
    // del 1/4 prima della vendita del 1/5): nella ricetta l'inizio di Luca viene prima la prima volta che li nomina e anche l'ultima.
    $luca = mb_strpos($frase, 'l\'inizio locazione a Luca');
    expect($luca !== false)->toBeTrue('La ricetta non comprende l\'inizio di Luca, registrato dopo la vendita della piena: ' . $frase)
        ->and($luca < mb_strpos($frase, 'la vendita da Elsa a Nora'))->toBeTrue('La ricetta non annulla l\'inizio di Luca prima della vendita della piena: ' . $frase)
        ->and(mb_strrpos($frase, 'l\'inizio locazione a Luca') < mb_strrpos($frase, 'la vendita da Elsa a Nora'))
        ->toBeTrue('La ricetta non fa registrare di nuovo i passaggi in ordine di data (l\'inizio di Luca del 1/4 prima della vendita del 1/5): ' . $frase);
});
