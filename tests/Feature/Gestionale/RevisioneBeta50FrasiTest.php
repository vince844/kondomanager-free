<?php

/**
 * 1.11.0-beta.50, Fase 1-bis — le frasi: i rilievi della revisione sui testi del pannello, del cancello, dello storico, del messaggio e
 * del prospetto (verbale `piano_esecutivo_beta50.md`, «Fase 1-bis», e i rilievi della ripresa in fondo a «La lista della ripresa»).
 *
 * Lo scenario è quello di `ScenariFineLocazione.php`: «Spese generali» € 1.200,00 sulla tabella «Proprietà», 30 % «Inquilino» (36000) e
 * 70 % «Proprietario» (84000); il «Preventivo 2026» in dodici rate mensili; Ines inquilina dal 2019; il 2026 ha 365 giorni. Ines esce il
 * 1/7: 181 giorni suoi, 184 dopo; 36000 × [181, 184] / 365 = 17852,05 / 18147,95 → 17852 + 18147 = 35999, il centesimo al resto più grande
 * (,95) → la coppia 18148 (€ 181,48). Con due inquiline al 50 % la parte di una è 18000: 18000 × [181, 184] / 365 = 8926,03 / 9073,97 →
 * 9074 (€ 90,74). Le altre cifre sono fatte a mano accanto a ogni test, in frazioni esatte, con la regola della coppia (resti maggiori, il
 * centesimo al resto più grande, a pari resto a chi esce).
 *
 * Nati come test rossi: ognuno descrive un difetto del codice della .50 trovato dalla revisione, con l'oracolo della decisione (83.2 per
 * «Annulla il conguaglio» dopo una fermata) o della correzione verificata, corretto prima del commit. Ognuno ha accanto un controllo (la
 * stessa scena prima del passo che la guastava, il denaro, o il prospetto) che dice che il rosso non veniva dal test. Gli altri sono
 * guardie: fissano un comportamento giusto che non aveva un test (i rami della ripresa), o il confine di una correzione (la frase che deve
 * restare dove è vera).
 *
 * Oltre ai rilievi assegnati, un difetto trovato scrivendo la guardia di `piu_persone`: con due comproprietari la seconda vendita metteva
 * la fermata a carico di tutti e due, mentre la prima l'aveva messa a carico di uno solo (lo stesso punto di
 * `FermataDopoScrivi::dopoUnaFermata` del test sulla successione con l'arretrato agli eredi di `RevisioneBeta50MotoreTest`, in un altro
 * caso). Il test «al proprietario nell'unità «Box 12»» diceva il contrario di `M112` di `MutazioniCorrezioniBeta50Test`, che fissa il
 * ripiego della .47: con la correzione `M112` è stato riscritto.
 *
 * Che cosa NON copre:
 * - il denaro dei rilievi alti (R1–R4, R7) e la decisione 82 (la seconda vendita anche per chi ha una riga nella coppia, `M69` che si
 *   riscrive; lo storico della prima fermata con la parte passata): stanno in `RevisioneBeta50MotoreTest`; la scheda delle pertinenze
 *   nel modulo (R13, decisione 83.4), la casella che dice «resta sfitta» per una fine retrodatata (R16), la scelta che resta fatta quando
 *   cambia la data (R17) e la risposta vecchia dell'anteprima (R21): stanno in `RevisioneBeta50InterfacciaTest` e nei test del modulo;
 * - R5, la fermata ridotta il cui passaggio è annullato dopo (la variante con l'inizio datato prima e registrato dopo la vendita): con la
 *   decisione 83.1 la vendita non si annulla più prima dell'inizio registrato dopo, e il caso non si raggiunge; l'avviso nel modulo della
 *   scheda prima di confermare (si fissa il dato del server, la chiave la sceglie la correzione);
 * - R6, la parte di Dora dal 1/3 al 30/6, che divide con Ines: il piano è fermo, e il prospetto non la dice in nessuna delle due versioni
 *   (limite dichiarato dalla verifica del rilievo); qui si fissa solo che la fine già scritta non cambia lettura; e la frase dell'anteprima
 *   dell'inizio retrodatato («Ines, che resta inquilino»), per cui la correzione non ha ancora le parole;
 * - R9, il registro (`wallet`, `con_un_saldo` per candidato) e l'avviso dell'annullamento: si fissa la frase che l'amministratore segue;
 * - R8, la guardia generale («ogni pagato_da del prospetto di chi entra compare nella frase del suo piano»): i quattro casi la dicono uno
 *   per uno, non per ogni scenario;
 * - R10, la voce «Non scriverlo» del modulo (testo fisso nel componente) e la guida in app: il pannello dice la frase del server, e la
 *   voce del modulo dipende da come la correzione passerà l'informazione (`rate.prospetto_esclude` o altro);
 * - R11, il riquadro verde dopo la registrazione nella pagina dei titolari («a debito di chi entra», testo nel componente): qui si fissa
 *   che il server dà il nome e l'estratto conto di chi ha la riga a debito; il componente dello storico è in `RevisioneBeta50Frasi.test.ts`;
 * - R14 è raggiungibile dal modulo solo con la scheda delle pertinenze della decisione 83.4: qui la richiesta si costruisce a mano;
 * - la pagina Vue del pannello, i PDF resi da browser, MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
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

/**
 * Lo scenario con il piano generato e, se si dice, le sei rate gennaio–giugno a giornale (il piano non si ricalcola più). Con `$riporti`
 * le persone che hanno già un saldo pregresso a mano sulla gestione e sull'unità, scritto prima della generazione: [nome => centesimi].
 */
function rbfScenario($test, array $titolari, array $opzioni = [], bool $seiRate = true, array $riporti = []): array
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

/** Il modulo dell'inizio di una locazione di una persona dello scenario. */
function rbfInizio(array $s, string $chi, string $il = '2026-07-01', float|int $quota = 100, array $pertinenze = []): array
{
    return ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => $il, 'quota' => $quota,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo',
        'pertinenze' => array_map(fn ($p) => (int) $p->id, $pertinenze)];
}

/** Le frasi divise in pezzi alla fine di un periodo o di un punto e virgola: per guardare una proposizione alla volta. */
function rbfPezzi(string $testo): array
{
    return array_values(array_filter(array_map('trim', preg_split('/(?<=[.;])\s+/u', $testo)), fn ($p) => $p !== ''));
}

/** La voce di un passaggio nello storico, senza `annullabile` (gli avvisi dell'annullamento del passaggio sono un'altra cosa). */
function rbfStoricoSenzaAnnullabile(array $s, Subentro $passaggio): array
{
    $voce = fds50Storico($s, $passaggio);
    unset($voce['annullabile']);

    return $voce;
}

// ---------------------------------------------------------------------------------------------------------------------
// La fermata del coinquilino: la frase (R9, R20) e lo storico (R12)
// ---------------------------------------------------------------------------------------------------------------------

it('R20 — Ines esce mentre restano Dora e Pia: la frase del coinquilino non dice «Se li paga Dora e Pia», con il verbo al singolare', function (array $riporti) {
    // Ines al 50 %, Dora e Pia al 25 %: la parte dell'inquilino di Ines è 36000 × 50 % = 18000, e i giorni dopo l'uscita 18000 × 184/365 =
    // 9073,97 → 9074 (€ 90,74). La frase è `AnteprimaPassaggio::fraseDelCoinquilino`: «Se li paga %s». Dalla 1.11.0-beta.53 il Wallet, con i
    // saldi legati al passaggio, anche con il riporto di Ines (la riga legata non occupa il posto).
    $s = rbfScenario($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 25], ['Pia', 25]]], riporti: $riporti);

    $a = fclAnteprima($this, $s, fclModulo($s));
    $frase = collect($a['rate']['frasi'])->first(fn (string $f) => str_contains($f, 'continua con Dora e Pia')) ?? '';

    // Controlli: la fermata c'è, con la cifra e chi resta, e la frase la dice, nel ramo giusto.
    expect(collect($a['rate']['fermata_coinquilino'])->sum('cifra'))->toBe(9074)
        ->and(collect($a['rate']['fermata_coinquilino'])->pluck('restano')->flatten()->all())->toBe(['Dora', 'Pia'])
        ->and($frase)->toContain('€ 90,74')
        ->and($frase)->toContain('registra dal Wallet i saldi legati a questo passaggio')
        ->and($frase)->not->toContain('la parte si regola fra le parti');

    expect(preg_match('/\bli paga \p{Lu}\p{L}* e \p{Lu}/u', $frase))->toBe(0, 'Il verbo è al singolare con due soggetti: ' . $frase);
})->with([
    'il ramo del Wallet' => [[]],
    'con il riporto di Ines, lo stesso Wallet' => [['Ines' => 5000]],
]);

it('R9 — la fermata del coinquilino quando chi potrebbe pagare ha già un saldo sulla gestione (il riporto di Ugo o di Dora): la frase indica a tutti il Wallet, con i saldi legati al passaggio, e le due righe legate si scrivono accanto al riporto', function (string $conIlSaldo) {
    // Fino alla .52 la frase diceva «fra le parti» per chi aveva già un saldo (il Wallet rifiutava la sua riga). Dalla 1.11.0-beta.53 la riga
    // legata al passaggio non occupa il posto: il Wallet c'è per tutti i candidati. La cifra: 18000 × 184/365 = 9073,97 → 9074.
    $s = rbfScenario($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]], riporti: [$conIlSaldo => 5000]);

    $a = fclAnteprima($this, $s, fclModulo($s));
    $frase = collect($a['rate']['frasi'])->first(fn (string $f) => str_contains($f, 'continua con Dora')) ?? '';

    expect(collect($a['rate']['fermata_coinquilino'])->sum('cifra'))->toBe(9074)
        ->and($frase)->toContain('€ 90,74')
        ->and($frase)->toContain('registra dal Wallet i saldi legati a questo passaggio, sulla stessa gestione: una riga a credito di Ines e una a debito di chi li paga')
        ->and($frase)->not->toContain('fra le parti');

    // Controllo: una riga non legata di chi ha già il riporto il Wallet la rifiuta ancora (il posto è occupato); quella legata al passaggio no.
    $fine = fclRegistra($this, $s, fclModulo($s));
    $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $s['persone'][$conIlSaldo]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 9074,
    ])->assertSessionHasErrors(['anagrafica_id' => 'Esiste già un saldo per questa anagrafica in questa gestione.']);
    foreach ([['Ines', -9074], [$conIlSaldo, 9074]] as [$nome, $importo]) {
        $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
            'anagrafica_id' => $s['persone'][$nome]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $importo, 'passaggio_id' => $fine->id,
        ])->assertSessionHasNoErrors();
    }
})->with(['il riporto di Ugo' => ['Ugo'], 'il riporto di Dora' => ['Dora']]);

it('R12 — la fine di Ines con Dora che resta: il registro conserva la fermata del coinquilino (€ 90,74), e lo storico la dice con la cifra, la persona e la strada a mano', function () {
    // Condizione 2 di `decide_l_amministratore.md` e scelta di progetto della .50: la fermata che ha chiesto la spunta lascia traccia nello
    // storico, come la fermata dopo «Scrivi». `StoricoTitolarita` costruisce le fermate solo da `fermata_dopo_scrivi` e `fermata_ridotta`:
    // di `fermata_coinquilino` restano solo gli avvisi dell'annullamento (`annullabile`), finché il passaggio si può annullare.
    $s = rbfScenario($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);

    $fine = fclRegistra($this, $s, fclModulo($s));

    // Controllo: il registro ha la fermata, con la cifra e chi resta.
    expect(collect($fine->registro['fermata_coinquilino'] ?? [])->sum('cifra'))->toBe(9074)
        ->and(collect($fine->registro['fermata_coinquilino'] ?? [])->pluck('restano')->flatten()->all())->toBe(['Dora']);

    // La strada a mano: il Wallet, «fra le parti», o «da regolare a mano» come la frase dello storico della fermata dopo «Scrivi».
    $conLaCifra = fds50Con(rbfStoricoSenzaAnnullabile($s, $fine), '€ 90,74');
    expect($conLaCifra)->not->toBe([], 'Lo storico della fine non dice la fermata del coinquilino (€ 90,74).')
        ->and(collect($conLaCifra)->contains(fn (string $t) => str_contains($t, 'Ines') && str_contains($t, 'Dora') && preg_match('/Wallet|le parti|a mano/u', $t) === 1))
        ->toBeTrue('La frase dello storico con € 90,74 non dice Ines, Dora e la strada a mano: ' . implode(' | ', $conLaCifra));
});

// ---------------------------------------------------------------------------------------------------------------------
// R15: nella successione senza coppie un «non_scrivere» mandato a mano toglie la fermata dopo «Scrivi»
// ---------------------------------------------------------------------------------------------------------------------

/**
 * La voce tutta all'inquilino (Ugo non ha quote nel piano), la fine di Ines il 1/7 con «Scrivi», le rate fino al 30/9, Anna e Bruno nel
 * condominio. La coppia: 120000 × [181, 184] / 365 = 59506,85 / 60493,15 → 59506 + 60493 = 119999, il centesimo al resto più grande (,85)
 * → Ines 59507, la coppia 60493 (Ines −60493, Ugo +60493).
 */
function rbfSuccessioneSenzaCoppie($test): array
{
    $s = rbfScenario($test, [['Ugo', 'proprietario', 100]], ['ripartizione' => ['inquilino' => 100]]);
    fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(fclSaldi($s))->toBe(['Ines' => -60493, 'Ugo' => 60493]);
    fclEmetti($test, $s, '2026-09-30');
    fclPersona($s, 'Anna');
    fclPersona($s, 'Bruno');

    return $s;
}

it('R15 — successione di Ugo il 1/10 senza coppie (con l\'arretrato al defunto): un «non_scrivere» mandato a mano, o la casella di un modulo di prima, non toglie la fermata dopo «Scrivi» (€ 302,46 verso Anna e Bruno), oppure si rifiuta', function (array $aMano) {
    // `AnteprimaPassaggio::calcola` salta la fermata con `successione && ! arretratoAgliEredi && conguaglio === non_scrivere`, senza
    // guardare se la scelta c'è (`scelta_del_conguaglio`): qui non c'è, perché Ugo non ha quote nel piano e non ci sono coppie. Dal
    // modulo non ci si arriva (manda la scelta solo con le coppie), ma la richiesta passa, e la registrazione non ha né la spunta né la
    // fermata nel registro. Le cifre: la coppia 60493 ridivisa dopo la successione, Ugo 92 giorni (1/7–30/9), Anna e Bruno 46 ciascuno
    // (50 % di 92): 60493 × 92/184 = 30246,5; 60493 × 46/184 = 15123,25 due volte → 30246 + 15123 + 15123 = 60492, il centesimo al resto
    // più grande (,5) → Ugo 30247. La parte degli eredi è 15123 + 15123 = 30246 (€ 302,46).
    $s = rbfSuccessioneSenzaCoppie($this);
    $modulo = fds50ModuloSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO, null, '2026-10-01');

    // Controllo: senza il campo la fermata c'è, ed è un motivo del cancello.
    $senza = fclAnteprima($this, $s, $modulo);
    expect($senza['rate']['scelta_del_conguaglio'])->toBeFalse()
        ->and(collect($senza['rate']['fermate_dopo_scrivi'])->sum('cifra'))->toBe(30246)
        ->and($senza['cancello']['richiesto'])->toBeTrue();

    // La strada del rifiuto (la regola del SIC-2 della .48) è sul campo mandato, non altrove: `conguaglio`, o `rinuncia_conguaglio` per la
    // casella (come il rifiuto della successione con l'arretrato agli eredi); o su `conguaglio` per tutti e due, dopo la traduzione della
    // casella.
    $campi = [array_key_first($aMano), 'conguaglio'];
    $r = fclAnteprimaRisposta($this, $s, $modulo + $aMano);
    if ($r->status() === 422) {
        expect(array_intersect($campi, array_keys($r->json('errors') ?? [])))->not->toBeEmpty('Rifiutata, ma non sul campo mandato: ' . json_encode($r->json('errors'), JSON_UNESCAPED_UNICODE));

        return;
    }
    expect($r->status())->toBe(200)
        ->and(collect($r->json('rate.fermate_dopo_scrivi'))->sum('cifra'))->toBe(30246, 'Il valore mandato a mano toglie la fermata di € 302,46.')
        ->and($r->json('cancello.richiesto'))->toBeTrue();

    // La registrazione: o si rifiuta sul campo mandato, o conserva la fermata nel registro.
    $store = fclStore($this, $s, $modulo + $aMano);
    $errori = $store->getSession()->get('errors');
    if ($errori === null || $errori->isEmpty()) {
        expect(collect(fds50Fermate(fclUltimo($s)))->sum('cifra'))->toBe(30246, 'Registrata con il valore mandato a mano, la successione non conserva la fermata.');
    } else {
        expect(array_intersect($campi, $errori->keys()))->not->toBeEmpty('Registrazione rifiutata, ma non sul campo mandato: ' . json_encode($errori->toArray(), JSON_UNESCAPED_UNICODE));
    }
})->with([
    '«non_scrivere» mandato a mano' => [['conguaglio' => 'non_scrivere']],
    'la casella della rinuncia di un modulo di prima' => [['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Gli eredi hanno regolato fra loro alla successione']],
]);

// ---------------------------------------------------------------------------------------------------------------------
// R5, decisione 83.2: «Annulla il conguaglio» dopo una fermata avvisa, prima di confermare e nel messaggio, e lo storico dice che la
// fermata non vale più
// ---------------------------------------------------------------------------------------------------------------------

/**
 * Il caso H della revisione: la fine di Ines il 1/7 con «Scrivi» (Ines −18148, Ugo +18148), le rate fino al 30/9, la vendita di Ugo a Elsa
 * il 1/10 con la fermata di € 90,74 (18148 × 92/184 = 9074, esatto), e i saldi del Wallet che la frase della fermata indica, legati alla
 * vendita dal modale (dalla 1.11.0-beta.53): Ugo −9074, Elsa +9074. Con `$luca` anche l'inizio della locazione di Luca il 1/11, che riduce la fermata (`fermata_ridotta`): dei 92
 * giorni dal 1/10 restano senza conduttore i 31 di ottobre. 18148 × [92, 31, 61] / 184 = 9074 / 3057,54 / 6016,46 (Ugo, Elsa, Luca) → 9074
 * + 3057 + 6016 = 18147, il centesimo al resto più grande (,54) va a Elsa → resta da regolare la parte di Elsa, 3058 (€ 30,58): la cifra di
 * `InizioDopoScriviTest` (S7) e di `M92`.
 */
function rbfCasoH($test, bool $luca = false): array
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
            'passaggio_id' => $s['vendita']->id,
        ])->assertSessionHasNoErrors();
    }
    if ($luca) {
        $s['inizio'] = fds50Registra($test, $s, fds50ModuloInizio($s, 'Luca', '2026-11-01'))['subentro'];
    }

    return $s;
}

/** «Annulla il conguaglio» della fine dalla rotta dello storico, con la nota; la risposta. */
function rbfAnnullaConguaglio($test, array $s, Subentro $fine, string $nota = 'Le parti hanno regolato diversamente alla riconsegna'): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->user)->delete(route('admin.gestionale.immobili.passaggi.annulla-conguaglio', [$s['c'], $s['unita'], $fine]), ['nota_annullamento_conguaglio' => $nota]);
}

/** Un testo che dice la fermata della vendita da rivedere: il passaggio, la cifra e i saldi del Wallet legati alla vendita. */
function rbfAvvisoDellaFermata(string $t): bool
{
    return str_contains($t, 'vendita del 1 ottobre 2026') && str_contains($t, '€ 90,74') && str_contains($t, 'Wallet');
}

it('R5, decisione 83.2 — prima di confermare «Annulla il conguaglio» della fine, lo storico avvisa che la vendita del 1/10 si era fermata su quella coppia (€ 90,74) e che i saldi del Wallet legati alla vendita vanno rivisti', function () {
    // `AnnullaConguaglioAction` non guarda le fermate: c'è la guardia della successione (67.3), nessuna sulle fermate. Decisione 83.2: un
    // avviso, non un rifiuto, detto prima di confermare (lo storico, accanto ad `annullabile` del conguaglio) e ripetuto nel messaggio.
    $s = rbfCasoH($this);

    $voce = rbfStoricoSenzaAnnullabile($s, $s['fine']);

    // Controlli: il conguaglio si può annullare da qui (il pulsante c'è), e i netti sono quelli della strada del Wallet: Elsa
    // 84000 × 92/365 = 21172,60 → 21173, più 9074 = 30247; Ugo 84000 × 273/365 = 62827,40 → 62827, più la coppia 18148, meno 9074 = 71901;
    // Ines 36000 − 18148 = 17852. 30247 + 17852 + 71901 = 120000.
    expect($voce['conguaglio']['stato'])->toBe('proposto')
        ->and($voce['conguaglio']['annullabile'])->toBeTrue()
        ->and(fds50Netti($s))->toBe(['Elsa' => 30247, 'Ines' => 17852, 'Ugo' => 71901]);

    // L'avviso può stare in una frase o in più (la fermata, poi il saldo da rivedere): si legge la voce tutta, senza `annullabile`.
    expect(rbfAvvisoDellaFermata(implode(' ', fds50Testi($voce))))
        ->toBeTrue('Prima di confermare, lo storico della fine non dice la fermata della vendita del 1 ottobre 2026 (€ 90,74) né il Wallet: ' . implode(' | ', fds50Testi($voce)));
});

it('R5, decisione 83.2 — «Annulla il conguaglio» dopo la fermata della vendita riesce, e il messaggio dice la fermata (€ 90,74, la vendita del 1/10) e i saldi del Wallet legati alla vendita da rivedere', function () {
    $s = rbfCasoH($this);

    $r = rbfAnnullaConguaglio($this, $s, $s['fine']);
    $messaggio = (string) ($r->getSession()->get('message')['message'] ?? '');

    // Controlli: l'annullamento riesce (un avviso, non un rifiuto), la coppia è tolta e le righe del Wallet restano. I netti
    // dopo: Elsa 21173 + 9074 = 30247; Ines 36000; Ugo 62827 − 9074 = 53753. 30247 + 36000 + 53753 = 120000. (Nell'ordine inverso,
    // annullato il conguaglio prima della vendita, nessuna fermata e nessun Wallet: Elsa 21173, Ines 36000, Ugo 62827.) L'avviso può
    // venire prima o dopo «Conguaglio annullato»: il messaggio non deve cominciare per forza così.
    $r->assertSessionHasNoErrors();
    expect(fds50Coppia($s, $s['fine']))->toBe([])
        ->and(fds50Netti($s))->toBe(['Elsa' => 30247, 'Ines' => 36000, 'Ugo' => 53753])
        ->and($messaggio)->toContain('Conguaglio annullato');

    expect(rbfAvvisoDellaFermata($messaggio))->toBeTrue('Il messaggio non dice la fermata della vendita (€ 90,74) e il Wallet: ' . $messaggio);
});

it('R5, decisione 83.2 — annullato il conguaglio della fine, lo storico dice che la fermata non vale più, invece di «€ 90,74 … sono rimasti a carico di Ugo»', function (bool $luca) {
    // `StoricoTitolarita` legge le fermate dal registro guardando solo se il passaggio che le porta è annullato: con il conguaglio della
    // fine annullato (`FermataDopoScrivi::fineScritta` è falsa), la vendita continua a dire la fermata, e l'inizio di Luca la sua parte.
    // Il calcolo invece è già coerente (dopo l'annullamento `fermateRidotte` non trova niente): restano vecchie le parole.
    $s = rbfCasoH($this, $luca);
    $passaggio = $luca ? $s['inizio'] : $s['vendita'];

    // Controllo: prima dell'annullamento lo storico dice la fermata con la sua cifra.
    $prima = implode(' ', fds50Storico($s, $passaggio)['fermate'] ?? []);
    expect($prima)->toContain($luca ? '€ 30,58' : '€ 90,74');

    rbfAnnullaConguaglio($this, $s, $s['fine'])->assertSessionHasNoErrors();
    // La voce tutta, senza `annullabile` (gli avvisi dell'annullamento del passaggio): la correzione può dire la fermata che non vale più
    // fra le `fermate` o accanto.
    $testi = fds50Testi(rbfStoricoSenzaAnnullabile($s, $passaggio));

    expect(collect($testi)->contains(fn (string $t) => preg_match('/non val(e|gono) più/u', $t) === 1))
        ->toBeTrue('Lo storico non dice che la fermata non vale più: ' . implode(' | ', $testi))
        ->and(collect($testi)->filter(fn (string $t) => str_contains($t, '€ 90,74') && ! str_contains($t, 'annullat') && preg_match('/non val(e|gono) più/u', $t) !== 1)->values()->all())
        ->toBe([], 'Lo storico dice ancora la fermata come se valesse.');
})->with([
    'lo storico della vendita' => [false],
    'lo storico dell\'inizio di Luca, che l\'ha ridotta' => [true],
]);

// ---------------------------------------------------------------------------------------------------------------------
// R6: una fine «Scrivi» della .50 si legge com'era quando è stata registrata, anche dopo un inizio registrato dopo con una data prima
// ---------------------------------------------------------------------------------------------------------------------

it('R6 — fine di Ines (unica inquilina, al 50 %) il 1/7 con «Scrivi», poi l\'inizio di Dora al 50 % dal 1/3, dimenticato e registrato dopo: il prospetto e lo storico della fine non cambiano lettura contro la coppia già scritta', function () {
    // `ChiPagaAlPostoDellInquilino::coinquiliniDelPassaggio` torna alle righe quando il registro non ha `fermata_coinquilino`: per una
    // fine della .50 con la scelta, Dora associata dopo diventa una coinquilina che restava, e il prospetto passa alla strada C («resta un
    // coinquilino») con Ines a 365 giorni, mentre i saldi le danno € 181,48 di credito già pagati da Ugo con la coppia. La correzione
    // verificata: con la scelta nel registro e senza la fermata del coinquilino, le righe fino a `riga_max_id` del registro.
    // Le cifre: Ines è la sola inquilina, la parte dell'inquilino è tutta sua, 36000. 36000 × 181/365 = 17852,05 → 17852; 36000 × 184/365
    // = 18147,95 → 18148. Dopo l'inizio di Dora, i giorni dopo l'uscita li rimborsa Dora a Ugo: [18148, 0, 18148, 184].
    $s = rbfScenario($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50]]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    // Controlli: prima dell'inizio di Dora.
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [17852, 17852, 0, 181]], 'senza' => [18148, 184]])
        ->and(fclStorico($s, $fine))->toContain('Dal 1 luglio 2026 le voci a carico dell\'inquilino tornano a Ugo');

    fclPersona($s, 'Dora');
    fclRegistra($this, $s, rbfInizio($s, 'Dora', '2026-03-01', 50));

    // Controllo: i saldi non si muovono.
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Ugo' => 18148]);

    $p = fclProspetto($s);
    expect($p['conduttori']['ines'] ?? null)->toBe([17852, 17852, 0, 181])
        ->and($p['conduttori']['dora'] ?? null)->toBe([18148, 0, 18148, 184])
        ->and(fclStorico($s, $fine))->toContain('tornano a Ugo')
        ->and(fclStorico($s, $fine))->not->toContain('la locazione continua con Dora')
        ->and(fclStampa($s))->not->toContain('Totale a carico di Ines € 360,00');
});

// ---------------------------------------------------------------------------------------------------------------------
// R10: il cambio d'inquilino su un piano della 1.10 (senza riparto registrato) non promette il prospetto, che lo lascia fuori
// ---------------------------------------------------------------------------------------------------------------------

/** Lo scenario con le sei rate e, se si dice, il piano come lo genera la 1.10: senza righe di riparto. */
function rbfPianoDellaVersionePrecedente($test, bool $senzaRiparto = true): array
{
    $s = rbfScenario($test, [['Ugo', 'proprietario', 100]]);
    if ($senzaRiparto) {
        DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    }

    return $s;
}

it('R10 — cambio d\'inquilino Ines → Luca il 1/7 su un piano della 1.10: la voce «Non scriverlo» del pannello non dice che Luca rimborsa «con il prospetto degli oneri accessori», che quel piano lo lascia fuori, ma che il rimborso si regola fra le parti', function () {
    // `AnteprimaPassaggio::blocco2`, ramo della scelta con chi entra: «… e Luca glieli rimborsa con il prospetto degli oneri accessori».
    // Registrato, il prospetto esclude il piano (`ProspettoOneriAccessori::stessePersone`: il riparto ricostruito con Luca ha una persona
    // che nelle rate non c'è) e non dice niente dei € 181,48 che Luca deve a Ines. La regola della correzione verificata: il prospetto
    // lascerà fuori il piano dopo questo passaggio, cioè senza riparto registrato e con chi entra. La cifra: 36000 × 184/365 = 18147,95 →
    // 18148.
    $s = rbfPianoDellaVersionePrecedente($this);
    $luca = fclPersona($s, 'Luca');

    $a = fclAnteprima($this, $s, fclModulo($s, $luca));
    $frasi = implode(' ', $a['rate']['frasi']);

    // Controlli: la coppia c'è e la scelta si chiede; registrato con «Non scriverlo», il prospetto lascia fuori il piano e non ha
    // conduttori (il presupposto della frase).
    expect(fclCoppie($a))->toBe([[(int) $s['unita']->id, 'Luca', 18148]])
        ->and($a['rate']['scelta_del_conguaglio'])->toBeTrue();
    fclRegistra($this, $s, fclModulo($s, $luca) + ['conguaglio' => 'non_scrivere']);
    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    expect(array_column($p['piani_esclusi'], 'nome'))->toBe(['Preventivo 2026'])
        ->and($p['conduttori'])->toBe([]);

    expect($frasi)->not->toContain('Luca glieli rimborsa con il prospetto degli oneri accessori')
        ->and($frasi)->toContain('fra le parti')
        ->and($frasi)->toContain('versione precedente');
});

it('R10, la guardia — sullo stesso piano della 1.10 la fine di Ines senza un nuovo inquilino tiene «con il prospetto»: senza chi entra il prospetto il piano lo legge (Ines [17852, 36000, −18148, 181], senza conduttore [18148, 184])', function () {
    // Il confine della correzione: «quando un piano delle coppie non ha righe_riparto» sarebbe troppo largo. Senza chi entra le persone del
    // riparto ricostruito restano Ugo e Ines, il piano resta nel prospetto, e la frase è vera.
    $s = rbfPianoDellaVersionePrecedente($this);

    $frasi = implode(' ', fclAnteprima($this, $s, fclModulo($s))['rate']['frasi']);
    fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);

    expect($frasi)->toContain('le parti li regolano fra loro con il prospetto degli oneri accessori')
        ->and(fclProspetto($s))->toBe(['conduttori' => ['ines' => [17852, 36000, -18148, 181]], 'senza' => [18148, 184]]);
});

it('R10, la guardia — il cambio d\'inquilino con il riparto registrato tiene la frase «Luca glieli rimborsa con il prospetto», e il prospetto la mantiene (Luca [18148, 0, 18148, 184])', function () {
    $s = rbfPianoDellaVersionePrecedente($this, senzaRiparto: false);
    $luca = fclPersona($s, 'Luca');

    $frasi = implode(' ', fclAnteprima($this, $s, fclModulo($s, $luca))['rate']['frasi']);
    fclRegistra($this, $s, fclModulo($s, $luca) + ['conguaglio' => 'non_scrivere']);

    expect($frasi)->toContain('Luca glieli rimborsa con il prospetto degli oneri accessori')
        ->and(fclProspetto($s)['conduttori']['luca'] ?? null)->toBe([18148, 0, 18148, 184]);
});

// ---------------------------------------------------------------------------------------------------------------------
// R11: dopo una fine senza un nuovo inquilino nessuno «entra»: lo storico, il riquadro della registrazione, le frasi dopo «Annulla il
// conguaglio»
// ---------------------------------------------------------------------------------------------------------------------

it('R11 — registrata la fine di Ines con «Scrivi», senza un nuovo inquilino: il riquadro della registrazione e lo storico nominano chi ha la riga a debito (Ugo), con il suo estratto conto, non «chi entra»', function () {
    // `PassaggioController::store` mette nel riquadro solo `entrante` (nullo) e l'estratto conto di chi entra; lo storico dà `per_entrante`
    // solo con due persone o più (`StoricoTitolarita::conguaglioPerEntrante`), e la scheda scrive «€ 181,48 a chi entra». La coppia è di chi
    // paga al posto dell'inquilino: Ugo, +18148.
    $s = rbfScenario($this, [['Ugo', 'proprietario', 100]]);

    $r = fclStore($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    $riquadro = (array) $r->getSession()->get('passaggio_registrato');
    $fine = fclUltimo($s);

    // Controlli: la coppia è scritta, e il riquadro c'è, con una coppia.
    $r->assertSessionHasNoErrors();
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and($riquadro['coppie'] ?? null)->toBe(1);

    $conguaglio = fds50Storico($s, $fine)['conguaglio'] ?? [];
    expect(collect(fds50Testi($riquadro))->contains(fn (string $t) => str_contains($t, 'Ugo')))->toBeTrue('Il riquadro non nomina Ugo: ' . json_encode($riquadro, JSON_UNESCAPED_UNICODE))
        // L'estratto conto di Ugo, dove la correzione lo mette (le azioni del riquadro o una chiave nuova).
        ->and(fds50Testi($riquadro))->toContain(route('admin.gestionale.anagrafiche.estratto-conto', ['condominio' => $s['c']->id, 'anagrafica' => $s['persone']['Ugo']->id]))
        ->and(collect(fds50Testi($conguaglio))->contains(fn (string $t) => str_contains($t, 'Ugo')))->toBeTrue('Il conguaglio nello storico non nomina Ugo: ' . json_encode($conguaglio, JSON_UNESCAPED_UNICODE));
});

it('R11 — dopo «Annulla il conguaglio» della fine senza un nuovo inquilino, il messaggio, l\'avviso dell\'annullamento del passaggio e la frase del piano non dicono «il condominio addebita a chi entra i suoi giorni»', function () {
    // Le tre frasi della decisione 46 (`PassaggioController::annullaConguaglio`, `AnnullaPassaggioAction::avvisi`,
    // `PianoRate::fraseRegolatoFuori`) sono scritte per chi entra; alla fine senza un nuovo inquilino i giorni dopo l'uscita, ricalcolando,
    // vanno a chi paga al posto dell'inquilino.
    $s = rbfScenario($this, [['Ugo', 'proprietario', 100]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);

    $r = rbfAnnullaConguaglio($this, $s, $fine, 'Le parti hanno regolato con il deposito cauzionale');
    $messaggio = (string) ($r->getSession()->get('message')['message'] ?? '');
    $avvisi = implode(' | ', app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->avvisi($fine->fresh()));
    $delPiano = (string) $s['piano']->fresh()->fraseRegolatoFuori();

    // Controlli: annullato, con le cifre di chi esce e di chi ha la coppia nel registro; e le tre frasi ci sono.
    $r->assertSessionHasNoErrors();
    expect(rbfRegolatoFuori($fine))->toBe(['Ines' => -18148, 'Ugo' => 18148])
        ->and($messaggio)->toStartWith('Conguaglio annullato')
        ->and($avvisi)->toContain('Le parti hanno già regolato fra loro')
        ->and($delPiano)->toContain('Le parti hanno già regolato fra loro');

    // E dicono a chi il condominio li addebita: a chi paga al posto dell'inquilino, con il nome o con la sua parte (non «all'inquilino»,
    // che è uscito).
    $testi = ['il messaggio' => $messaggio, 'l\'avviso dell\'annullamento' => $avvisi, 'la frase del piano' => $delPiano];
    expect(array_keys(array_filter($testi, fn (string $t) => str_contains($t, 'a chi entra'))))
        ->toBe([], 'Dicono «a chi entra»: ' . implode(' || ', array_filter($testi, fn (string $t) => str_contains($t, 'a chi entra'))))
        ->and(array_keys(array_filter($testi, fn (string $t) => preg_match('/al posto dell\'inquilino|Ugo|proprietari/u', $t) !== 1)))
        ->toBe([], 'Non dicono a chi vanno i giorni dopo l\'uscita: ' . implode(' || ', $testi));
});

/** `regolato_fuori` del registro di un passaggio, per nome, sulla sola gestione dello scenario. */
function rbfRegolatoFuori(Subentro $passaggio): array
{
    return collect($passaggio->fresh()->registro['regolato_fuori'][0]['persone'] ?? [])->mapWithKeys(fn ($p) => [(string) $p['nome'] => (int) $p['importo']])->sortKeys()->all();
}

// ---------------------------------------------------------------------------------------------------------------------
// R14: Dora resta sull'Interno 1, il box è spuntato: le bozze dell'Interno 1 non sono «comprese nel conguaglio»
// ---------------------------------------------------------------------------------------------------------------------

it('R14 — Ines e Dora inquiline dell\'Interno 1 al 50 %, il box di Ines spuntato: il cancello e «Chi resta obbligato» non dicono tutte le quote non emesse di Ines «comprese nel conguaglio, se lo scrivi»; quelle dell\'Interno 1 restano sue, senza conguaglio, perché lì la locazione continua con Dora', function () {
    // `AnteprimaPassaggio::cancello` usa la scelta (`sceltaQui`) per tutto il passaggio: le bozze dell'unità con il coinquilino non hanno una
    // categoria loro e finiscono fra quelle «comprese»; `FrasiObbligati` dice la scelta prima dei coinquilini. Il box spuntato a mano: dal
    // modulo ci si arriverà con la scheda delle pertinenze della decisione 83.4.
    // Le cifre: Interno 1, 900 millesimi, 120000 × 0,9 × 30 % = 32400, Ines al 50 % 16200, 1350 a rata, sei bozze 8100 (€ 81,00); Box 12,
    // 100 millesimi, 3600, 300 a rata, sei bozze 1800 (€ 18,00). Il conguaglio è solo quello del box: 3600 × [181, 184] / 365 = 1785,21 /
    // 1814,79 → 1785 + 1814 = 3599, il centesimo al resto più grande (,79) → 1815 (€ 18,15). Sull'Interno 1 la fermata del coinquilino:
    // 16200 × [181, 184] / 365 = 8033,42 / 8166,58 → 8033 + 8166 = 16199, il centesimo al resto più grande (,58) → 8167 (€ 81,67).
    $s = rbfScenario($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]], 'box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
    $modulo = fclModulo($s, null, '2026-07-01', [$s['box']]);

    $a = fclAnteprima($this, $s, $modulo);
    $motivi = $a['cancello']['motivi'];
    $obbligati = $a['obbligati']['frasi'];
    fclRegistra($this, $s, $modulo + ['conguaglio' => 'scrivi']);

    // Controlli: la coppia è solo quella del box; sull'Interno 1 la fermata del coinquilino; registrato con «Scrivi», nessuna riga
    // sull'Interno 1 e la coppia del box.
    expect(fclCoppie($a))->toBe([[(int) $s['box']->id, 'Ugo', 1815]])
        ->and(collect($a['rate']['fermata_coinquilino'])->map(fn ($f) => [(int) $f['immobile_id'], (int) $f['cifra']])->all())->toBe([[(int) $s['unita']->id, 8167]])
        ->and(fclSaldi($s))->toBe([])
        ->and(fclSaldi($s, $s['box']))->toBe(['Ines' => -1815, 'Ugo' => 1815]);

    // Nessuna frase dice «comprese nel conguaglio» per le 12 quote insieme, e nessuna dice che «Scrivi il conguaglio» porta le bozze
    // dell'Interno 1 a chi paga al posto dell'inquilino senza dire l'unità. Come per l'unità senza nessuno al posto dell'inquilino
    // (punto 4 della ripresa), il piano si può dividere nella stessa frase: «12 quote …, 6 restano sue e sono comprese nel conguaglio, se
    // lo scrivi …; 6 restano sue, senza conguaglio: nell'unità «Interno 1» …», e «Chi resta obbligato» può nominare il box o l'Interno 1.
    $tutteComprese = array_values(array_filter($motivi, fn (string $m) => str_contains($m, 'comprese nel conguaglio') && ! str_contains($m, 'senza conguaglio')));
    $obbligatiScelta = array_values(array_filter($obbligati, fn (string $f) => str_contains($f, '«Scrivi il conguaglio» li porta') && ! str_contains($f, 'Box 12') && ! str_contains($f, 'Interno 1')));
    expect($tutteComprese)->toBe([], 'Il cancello dice comprese nel conguaglio tutte le 12 quote: ' . implode(' | ', $motivi))
        ->and($obbligatiScelta)->toBe([], '«Chi resta obbligato» dice la scelta per tutte le bozze: ' . implode(' | ', $obbligati))
        // Fra i motivi o fra le informazioni: la correzione lo può dire nell'uno o nell'altro posto, come le quote emesse dell'unità
        // senza nessuno al posto dell'inquilino, passate alle informazioni.
        ->and(collect([...$motivi, ...($a['cancello']['informazioni'] ?? [])])->contains(fn (string $m) => str_contains($m, 'senza conguaglio')))
        ->toBeTrue('Il cancello non dice che le 6 quote dell\'Interno 1 restano a Ines senza conguaglio: ' . implode(' | ', [...$motivi, ...($a['cancello']['informazioni'] ?? [])]));
});

// ---------------------------------------------------------------------------------------------------------------------
// Il prospetto: la testa con più piani (R18) e le fini di prima della .50 già regolate dal Wallet (R19)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * La gestione «Riscaldamento 2026» con «Combustibile» € 730,00 tutto all'inquilino, e il suo piano «Riscaldamento 2026» in dodici rate
 * mensili dal 5/1, approvato e generato. Come `mcb50SecondaGestione`, con le rate da gennaio: generato prima della fine e non emesso, la
 * coppia della fine non lo prende (`presoNelConguaglioDa` falso), e lo si emette dopo, senza ricalcolo.
 */
function rbfRiscaldamento(array $s): \App\Models\Gestionale\PianoRate
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

/** L'emissione dalla rotta della pagina del piano, per un piano dato, delle rate in bozza fino al giorno dato. */
function rbfEmettiPiano($test, array $s, \App\Models\Gestionale\PianoRate $piano, string $fino): void
{
    $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->where('stato', '!=', 'emessa')->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), ['rate_ids' => $rate, 'data_emissione' => $fino, 'invia_notifiche' => false])
        ->assertSessionHasNoErrors();
}

/**
 * Il caso (a) di R8 e il caso di R18: il «Preventivo 2026» con le sei rate a giornale, il «Riscaldamento 2026» generato e non emesso, la
 * fine di Ines il 1/7 con «Scrivi» (la coppia prende il preventivo, non il riscaldamento), poi il riscaldamento a giornale fino al 31/8.
 */
function rbfDuePianiDopoLaFine($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    fclGenera($s);
    $s['riscaldamento'] = rbfRiscaldamento($s);
    fclEmetti($test, $s, '2026-06-30');
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Ugo' => 18148]);
    rbfEmettiPiano($test, $s, $s['riscaldamento'], '2026-08-31');

    return $s;
}

it('R18 — fine di Ines con «Scrivi» e due piani (il preventivo preso dalla coppia, il riscaldamento no): le frasi della fine nella testa del prospetto dicono di quale piano parlano, invece di «nessun conguaglio ha spostato i giorni» e «il conguaglio è stato scritto» per la stessa fine', function () {
    // `ProspettoOneriAccessori::frasiDellUscita` raggruppa per data, strada e scelta, senza il piano: le due frasi della fine del 1/7, una
    // per strada, si leggono una dopo l'altra e si contraddicono. Le cifre (controllo): sul riscaldamento, € 730,00 tutto all'inquilino,
    // i giorni dopo l'uscita sono di Ines (strada A′): 73000 × 184/365 = 36800 da restituirle; sul preventivo la coppia (strada B), 18148.
    $s = rbfDuePianiDopoLaFine($this);

    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $dellaFine = array_values(array_filter($p['intestazione'], fn (string $f) => str_contains($f, 'Alla fine della locazione del 01/07/2026')));
    $voci = collect(collect($p['conduttori'])->firstWhere('nome', 'Ines')['voci'] ?? [])->merge($p['senza_conduttore']['voci'])->filter(fn ($v) => ! empty($v['strada']));

    // Controlli: le due strade ci sono, una per piano, e le due frasi.
    expect($voci->where('conto', 'Combustibile')->pluck('strada')->unique()->values()->all())->toBe(['A'])
        ->and((int) $voci->where('conto', 'Combustibile')->where('modo', 'restituzione')->sum('importo'))->toBe(-36800)
        ->and($voci->where('conto', 'Spese generali')->pluck('strada')->unique()->values()->all())->toBe(['B'])
        ->and(collect($dellaFine)->contains(fn (string $f) => str_contains($f, 'nessun conguaglio ha spostato')))->toBeTrue()
        ->and(collect($dellaFine)->contains(fn (string $f) => str_contains($f, 'il conguaglio è stato scritto')))->toBeTrue();

    // Ogni frase dice il suo piano, e solo il suo (un elenco di tutti i piani in ogni frase non direbbe quale); fuse in una, tutti e due.
    $senzaIlPiano = array_values(array_filter($dellaFine, function (string $f) {
        $nessuno = str_contains($f, 'nessun conguaglio ha spostato');
        $scritto = str_contains($f, 'il conguaglio è stato scritto');
        $risc = str_contains($f, 'Riscaldamento 2026');
        $prev = str_contains($f, 'Preventivo 2026');

        return match (true) {
            $nessuno && $scritto => ! ($risc && $prev),
            $nessuno => ! $risc || $prev,
            $scritto => ! $prev || $risc,
            default => false,
        };
    }));
    expect($senzaIlPiano)->toBe([], 'Frasi della stessa fine senza il nome del loro piano: ' . implode(' | ', $dellaFine));
});

it('R19 — una fine di prima della .50 (nessuna scelta nel registro) regolata dal Wallet come suggeriva il pannello dalla .42 alla .49: il prospetto, che la legge come strada A′ e chiede di restituire € 181,48 a Ines, dice che un saldo manuale scritto per quei giorni li ha già regolati', function () {
    // `ProspettoOneriAccessori::frasiDellUscita`, ramo senza la scelta: «nessun conguaglio ha spostato i giorni dopo l'uscita … Nella sua
    // sezione sono da restituire». Il cancello delle beta .42–.49 diceva «chi paga i giorni dopo l'uscita lo decide l'amministratore — se
    // serve, con un saldo manuale dal Wallet sulla stessa gestione»: chi l'ha seguito ha già Ines −18148 e Ugo +18148 nei saldi, e la frase
    // gli fa restituire € 181,48 una seconda volta. La strada A′ resta (scelta di progetto): manca la frase.
    $s = rbfScenario($this, [['Ugo', 'proprietario', 100]]);
    $fine = fclRegistra($this, $s, fclModulo($s) + ['conguaglio' => 'non_scrivere']);
    foreach (Subentro::where('id', $fine->id)->orWhere('subentro_padre_id', $fine->id)->get() as $x) {
        $registro = $x->registro ?? [];
        unset($registro['conguaglio'], $registro['regolato_fuori']);
        $registro['piani_presi'] = [];
        DB::table('subentri')->where('id', $x->id)->update(['registro' => json_encode($registro), 'nota_conguaglio' => null]);
    }
    fclRiporto($s, 'Ines', -18148);
    fclRiporto($s, 'Ugo', 18148);

    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);

    // Controlli: la strada A′, € 181,48 da restituire a Ines; la frase della fine senza la scelta.
    expect(fclProspetto($s))->toBe(['conduttori' => ['ines' => [17852, 36000, -18148, 181]], 'senza' => [18148, 184]])
        ->and(collect($p['intestazione'])->contains(fn (string $f) => str_contains($f, 'Alla fine della locazione del 01/07/2026 nessun conguaglio ha spostato')))->toBeTrue();

    expect(collect($p['intestazione'])->contains(fn (string $f) => str_contains($f, 'saldo manuale')))
        ->toBeTrue('La testa del prospetto non dice che un saldo manuale scritto per quei giorni li ha già regolati: ' . implode(' | ', $p['intestazione']));
});

// ---------------------------------------------------------------------------------------------------------------------
// R8: il pannello dell'inizio di una locazione nomina chi rimborsa come il prospetto, piano per piano
// ---------------------------------------------------------------------------------------------------------------------

/** Le frasi dell'inizio sui piani fermi: il blocco «Rate» e le informazioni del cancello. */
function rbfFrasiDellInizio(array $anteprima): array
{
    return [...$anteprima['rate']['frasi'], ...($anteprima['cancello']['informazioni'] ?? [])];
}

/**
 * Chi rimborsa chi, per etichetta (il nome di un piano o di un'unità): per ogni «li rimborsa X a Y» del testo, l'ultima delle etichette
 * date che lo precede. Il pannello mette in una frase sola tutti i piani fermi dell'inizio, quindi «contiene» non basta a dire quale
 * rimborso è di quale piano. [etichetta => [Y, …]], in ordine di etichetta.
 */
function rbfRimborsiPer(string $testo, array $etichette): array
{
    $dove = [];
    foreach ($etichette as $e) {
        preg_match_all('/' . preg_quote($e, '/') . '/u', $testo, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [, $o]) {
            $dove[$o] = $e;
        }
    }
    ksort($dove);
    preg_match_all('/li rimborsa \p{Lu}\p{L}* a (\p{Lu}\p{L}*)/u', $testo, $r, PREG_OFFSET_CAPTURE);
    $out = [];
    foreach ($r[1] as [$a, $o]) {
        $prima = '?';
        foreach ($dove as $p => $e) {
            if ($p < $o) {
                $prima = $e;
            }
        }
        $out[$prima][] = $a;
    }
    ksort($out);

    return array_map(fn (array $l) => array_values(array_unique($l)), $out);
}

/** Le voci di un conduttore nel prospetto di un'unità (l'Interno 1 se non si dice): [conto => [pagato_da, strada, importo, giorni]]. */
function rbfVociDi(array $s, string $nome, ?\App\Models\Immobile $unita = null): array
{
    $p = app(ProspettoOneriAccessori::class)->calcola(($unita ?? $s['unita'])->fresh(), $s['e']);

    return collect(collect($p['conduttori'])->firstWhere('nome', $nome)['voci'] ?? [])->filter(fn ($v) => ! empty($v['strada']))
        ->mapWithKeys(fn ($v) => [(string) $v['conto'] => [(string) ($v['pagato_da'] ?? ''), (string) $v['strada'], (int) $v['importo'], (int) ($v['giorni'] ?? 0)]])->all();
}

it('R8 (a) — due piani fermi all\'inizio di Luca il 1/9, dopo la fine di Ines con «Scrivi» che ha preso solo il preventivo: il pannello dice per il riscaldamento «li rimborsa Luca a Ines», come il prospetto, e per il preventivo «li rimborsa Luca a Ugo»', function () {
    // `AnteprimaPassaggio::comeSonoUsciti` non riceve il piano: guarda la fine sull'unità e non `presoNelConguaglioDa`, e i due piani fanno un
    // gruppo solo (`gruppiDeiPianiFermi`), con «li ha presi con il conguaglio Ugo, e li rimborsa Luca a Ugo». Il prospetto: sul riscaldamento,
    // che la coppia non ha preso, i giorni di Luca sono nelle rate di Ines (strada A′): 73000 × 122/365 = 24400, esatto, pagata da Ines; sul
    // preventivo la strada B: 36000 × 122/365 = 12032,88 → 12033, pagata da Ugo. La correzione verificata: una sola fonte, piano per piano.
    $s = rbfDuePianiDopoLaFine($this);
    fclPersona($s, 'Luca');

    $a = fclAnteprima($this, $s, rbfInizio($s, 'Luca', '2026-09-01'));
    fclRegistra($this, $s, rbfInizio($s, 'Luca', '2026-09-01'));

    // Controllo: il prospetto, piano per piano.
    expect(rbfVociDi($s, 'Luca'))->toBe(['Spese generali' => ['Ugo', 'B', 12033, 122], 'Combustibile' => ['Ines', 'A', 24400, 122]]);

    // Ogni «li rimborsa» va con il piano che lo precede: i piani fermi stanno in una frase sola, e uno scambio fra i due piani darebbe le
    // stesse parole.
    $piani = ['«Preventivo 2026»', '«Riscaldamento 2026»'];
    $atteso = ['«Preventivo 2026»' => ['Ugo'], '«Riscaldamento 2026»' => ['Ines']];
    $frasi = implode(' ', $a['rate']['frasi']);
    $informazioni = implode(' ', $a['cancello']['informazioni'] ?? []);
    expect(rbfRimborsiPer($frasi, $piani))->toBe($atteso, 'Il pannello non dice per il riscaldamento «li rimborsa Luca a Ines» e per il preventivo «a Ugo»: ' . $frasi)
        ->and(rbfRimborsiPer($informazioni, $piani))->toBe($atteso, 'Le informazioni del cancello non dicono per il riscaldamento «li rimborsa Luca a Ines» e per il preventivo «a Ugo»: ' . $informazioni);
});

it('R8 (b) — cambio Ines → Luca il 1/7 con «Scrivi», fine di Luca il 1/9 con «Scrivi», inizio di Anna il 1/11: il pannello dice «li rimborsa Anna a Ugo», come il prospetto, non «a Luca»', function () {
    // `comeSonoUsciti` guarda solo la fine di chi ha le rate (il cambio d'inquilino, «scritto» con le righe di Luca), non la catena fino
    // all'ultimo conduttore. Le cifre: il cambio, 36000 × 184/365 = 18147,95 → 18148 a Luca; la fine di Luca, i suoi 122 giorni dal 1/9 su
    // 184: 18148 × 122/184 = 12032,91 → 12033 a Ugo, e a Luca restano 18148 − 12033 = 6115 (1/7–31/8). Novembre e dicembre li ha presi Ugo:
    // nel prospetto Anna rimborsa Ugo (strada B).
    $s = rbfScenario($this, [['Ugo', 'proprietario', 100]]);
    $luca = fclPersona($s, 'Luca');
    fclPersona($s, 'Anna');
    fclRegistra($this, $s, fclModulo($s, $luca) + ['conguaglio' => 'scrivi']);
    fclRegistra($this, $s, fclModulo($s, null, '2026-09-01', [], 'Luca') + ['conguaglio' => 'scrivi']);

    $a = fclAnteprima($this, $s, rbfInizio($s, 'Anna', '2026-11-01'));
    fclRegistra($this, $s, rbfInizio($s, 'Anna', '2026-11-01'));

    // Controlli: i saldi delle due coppie e il prospetto.
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Luca' => 6115, 'Ugo' => 12033])
        ->and(array_column(rbfVociDi($s, 'Anna'), 0))->toBe(['Ugo'])
        ->and(array_column(rbfVociDi($s, 'Anna'), 1))->toBe(['B']);

    $testo = implode(' | ', rbfFrasiDellInizio($a));
    expect($testo)->not->toContain('li rimborsa Anna a Luca')
        ->and($testo)->toContain('li rimborsa Anna a Ugo');
});

it('R8 (c) — fine di Ines il 1/4 con «Scrivi», rientro il 1/5, fine il 1/7 senza coppie, inizio di Anna il 1/9: il pannello dice «li rimborsa Anna a Ugo», come il prospetto, non «a Ines», che ha già il credito della coppia', function () {
    // `comeSonoUsciti` prende l'ultima fine (`orderByDesc`), quella del 1/7 senza coppie («non scritto»), mentre il prospetto prende la prima
    // del periodo. Le cifre: la fine del 1/4, 36000 × [90, 275] / 365 = 8876,71 / 27123,29 → 8876 + 27123 = 35999, il centesimo al resto più
    // grande (,71) → Ines 8877, la coppia 27123 a Ugo; Anna dal 1/9, 122 giorni, 12033 (36000 × 122/365 = 12032,88), pagata da Ugo. Chi
    // segue il pannello fa pagare € 120,33 a Ines, che ha già € 271,23 di credito.
    $s = fclScenario([['Ugo', 'proprietario', 100]]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-03-31');
    fclPersona($s, 'Anna');
    fclRegistra($this, $s, fclModulo($s, null, '2026-04-01') + ['conguaglio' => 'scrivi']);
    fclRegistra($this, $s, rbfInizio($s, 'Ines', '2026-05-01'));
    fclEmetti($this, $s, '2026-06-30');
    $seconda = fclRegistra($this, $s, fclModulo($s, null, '2026-07-01'));

    $a = fclAnteprima($this, $s, rbfInizio($s, 'Anna', '2026-09-01'));
    fclRegistra($this, $s, rbfInizio($s, 'Anna', '2026-09-01'));

    // Controlli: la coppia della prima fine, la seconda senza coppie, e il prospetto.
    expect(fclSaldi($s))->toBe(['Ines' => -27123, 'Ugo' => 27123])
        ->and($seconda->registro['conguaglio'] ?? null)->toBeNull()
        ->and(rbfVociDi($s, 'Anna'))->toBe(['Spese generali' => ['Ugo', 'B', 12033, 122]]);

    $testo = implode(' | ', rbfFrasiDellInizio($a));
    expect($testo)->not->toContain('li rimborsa Anna a Ines')
        ->and($testo)->toContain('li rimborsa Anna a Ugo');
});

/**
 * Il caso della ripresa: Ugo proprietario dell'Interno 1 (900 millesimi), il «Box 12» (100 millesimi) con Ines inquilina e nessun
 * proprietario; il piano generato con la presa d'atto delle quote scoperte, le sei rate a giornale.
 */
function rbfBoxSenzaNessuno($test): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Ines', 'inquilino', 100]]]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano']->fresh(), forzaApplicazioneSaldi: true, accettaScoperti: true,
        notaScoperti: 'Box senza proprietario: la parte del proprietario resta scoperta', accettaDestinatari: true, notaDestinatari: 'Letto: generazione del preventivo', esercizio: $s['e']);
    fclEmetti($test, $s, '2026-06-30');

    return $s;
}

it('R8 (d) — il box senza nessuno al posto dell\'inquilino, fine di Ines con «Scrivi» e il box spuntato, inizio di Luca il 1/9 con il box: sul box il pannello dice «li rimborsa Luca a Ines», come il prospetto, non «li ha presi con il conguaglio chi paga al posto dell\'inquilino»', function () {
    // Sul box la coppia non si scrive (punto 4 della ripresa): i giorni dopo l'uscita sono rimasti nelle rate di Ines. Le cifre del box (100
    // millesimi, 3600): Luca dal 1/9, 3600 × 122/365 = 1203,29 → 1203, pagata da Ines (strada A′); senza conduttore il 1/7–31/8, 3600 ×
    // 62/365 = 611,51 → 612 (Ines 181 giorni, 1785,21: 1203 + 611 + 1785 = 3599, il centesimo al resto più grande, ,51). Sull'Interno 1 la
    // strada B, pagata da Ugo, che ha la coppia.
    $s = rbfBoxSenzaNessuno($this);
    fclRegistra($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]) + ['conguaglio' => 'scrivi']);
    fclPersona($s, 'Luca');

    $a = fclAnteprima($this, $s, rbfInizio($s, 'Luca', '2026-09-01', 100, [$s['box']]));
    fclRegistra($this, $s, rbfInizio($s, 'Luca', '2026-09-01', 100, [$s['box']]));

    // Controlli: sul box nessuna riga in saldi, e il prospetto delle due unità.
    expect(fclSaldi($s, $s['box']))->toBe([])
        ->and(rbfVociDi($s, 'Luca', $s['box']))->toBe(['Spese generali' => ['Ines', 'A', 1203, 122]])
        ->and(fclProspetto($s, $s['box'])['senza'])->toBe([612, 62])
        ->and(array_map(fn (array $v) => [$v[0], $v[1]], rbfVociDi($s, 'Luca')))->toBe(['Spese generali' => ['Ugo', 'B']]);

    // Unità per unità, come il prospetto: ogni «li rimborsa» va con l'unità che lo precede (le due unità stanno nella stessa frase).
    $testo = implode(' | ', rbfFrasiDellInizio($a));
    expect($testo)->not->toContain('li ha presi con il conguaglio chi paga al posto dell\'inquilino')
        ->and(rbfRimborsiPer(implode(' ', $a['rate']['frasi']), ['Interno 1', 'Box 12']))->toBe(['Box 12' => ['Ines'], 'Interno 1' => ['Ugo']], 'Il pannello non dice per il box «li rimborsa Luca a Ines» e per l\'Interno 1 «a Ugo»: ' . $testo);
});

// ---------------------------------------------------------------------------------------------------------------------
// I rilievi della ripresa: il box senza nessuno al posto dell'inquilino
// ---------------------------------------------------------------------------------------------------------------------

it('ripresa, la guardia — il box senza nessuno: il cancello conta a parte le 6 quote emesse del box, che il conguaglio non tocca, e il motivo dice solo le 6 dell\'Interno 1', function () {
    // Il ramo `$emesseSenzaNessuno` di `AnteprimaPassaggio::cancello` (punto 4 della ripresa), che non aveva un test: senza, il motivo dice
    // «12 quote di rate già emesse a Ines su questa unità», e 6 sono del box, che il conguaglio non tocca. Gennaio–giugno, sei rate per unità.
    $s = rbfBoxSenzaNessuno($this);

    $a = fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]));

    expect($a['cancello']['motivi'])->toContain('6 quote di rate già emesse a Ines su questa unità')
        ->and(implode(' | ', $a['cancello']['motivi']))->not->toContain('12 quote di rate già emesse')
        ->and($a['cancello']['informazioni'] ?? [])->toContain('6 quote di rate già emesse a Ines: restano sue, e il conguaglio non le tocca: nell\'unità «Box 12» nessuno sta al posto dell\'inquilino nei giorni dopo l\'uscita');
});

it('ripresa, la guardia — il box senza nessuno: «Chi resta obbligato» dice che sul box le quote non emesse restano nelle rate di Ines anche con «Scrivi il conguaglio»', function () {
    // La frase delle bozze ferme con le unità senza nessuno (`FrasiObbligati`, punto 4 della ripresa), che non aveva un test.
    $s = rbfBoxSenzaNessuno($this);

    $obbligati = implode(' ', fclAnteprima($this, $s, fclModulo($s, null, '2026-07-01', [$s['box']]))['obbligati']['frasi']);

    expect($obbligati)->toContain('Nel piano «Preventivo 2026», che non si ricalcola più, restano a Ines anche le 12 quote non ancora emesse:')
        ->and($obbligati)->toContain('nell\'unità «Box 12» nessuno sta al posto dell\'inquilino, e lì restano nelle rate di Ines anche con «Scrivi il conguaglio».');
});

it('ripresa — il box senza nessuno: «Chi resta obbligato» e lo storico della fine non dicono che le voci «tornano … al proprietario nell\'unità «Box 12»», che un proprietario non ce l\'ha, accanto a «nessuno sta al posto dell\'inquilino»', function () {
    // `FrasiObbligati::fraseTornano`, per un'unità senza nomi, ripiega su «al proprietario» (ripiego della .47): sul box non c'è nessuno, e
    // lo stesso pannello dice «Nell'unità «Box 12» nessuno sta al posto dell'inquilino». Il test `M112` di `MutazioniCorrezioniBeta50Test`
    // fissa il ripiego (contro la frase senza nome «a »): con la correzione va riscritto.
    $s = rbfBoxSenzaNessuno($this);
    $modulo = fclModulo($s, null, '2026-07-01', [$s['box']]);

    $a = fclAnteprima($this, $s, $modulo);
    $obbligati = implode(' ', $a['obbligati']['frasi']);
    $fine = fclRegistra($this, $s, $modulo + ['conguaglio' => 'scrivi']);
    // Lo storico tutto, senza `annullabile`: la correzione può dire il box in «Chi resta obbligato» o accanto.
    $storico = implode(' ', fds50Testi(rbfStoricoSenzaAnnullabile($s, $fine)));
    $aUgo = '/tornano a Ugo[^.]*«Interno 1»|«Interno 1»[^.]*tornano a Ugo/u';

    // Controlli: il pannello sa che sul box non c'è nessuno, e l'Interno 1 torna a Ugo, nel pannello e nello storico.
    expect(implode(' ', $a['rate']['frasi']))->toContain('Nell\'unità «Box 12» nessuno sta al posto dell\'inquilino nei giorni dopo l\'uscita di Ines')
        ->and(preg_match($aUgo, $obbligati))->toBe(1)
        ->and(preg_match($aUgo, $storico))->toBe(1);

    expect($obbligati)->not->toContain('al proprietario nell\'unità «Box 12»')
        ->and($storico)->not->toContain('al proprietario nell\'unità «Box 12»')
        ->and(collect(rbfPezzi($storico))->contains(fn (string $p) => str_contains($p, 'Box 12') && str_contains($p, 'nessuno')))
        ->toBeTrue('Lo storico della fine non dice che sul box nessuno sta al posto dell\'inquilino: ' . $storico);
});

// ---------------------------------------------------------------------------------------------------------------------
// I rilievi della ripresa: chi entra lo stesso giorno non «resta» inquilino
// ---------------------------------------------------------------------------------------------------------------------

it('ripresa — Luca entra il 1/7 al 50 % (registrato prima) e Ines esce lo stesso giorno: il pannello della fine non dice «resta inquilino Luca», ma non dice nemmeno che l\'unità resta sfitta', function () {
    // Punto 5 della ripresa: la condizione dello «sfitta» guarda chi è inquilino il giorno del passaggio (più larga del coinquilino che
    // resta, voluto), ma la parola «resta» è sbagliata per chi entra quel giorno: Luca non c'era il 30/6.
    $s = rbfScenario($this, [['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50]]]);
    fclPersona($s, 'Luca');
    fclRegistra($this, $s, rbfInizio($s, 'Luca', '2026-07-01', 50));

    $a = fclAnteprima($this, $s, fclModulo($s));
    $blocco1 = implode(' | ', [$a['riferimento']['frase'], ...$a['anagrafica']['frasi']]);

    // Controlli: l'unità non resta sfitta, e il pannello nomina Luca.
    expect($blocco1)->not->toContain('sfitta')
        ->and($blocco1)->toContain('Luca');

    expect($blocco1)->not->toContain('resta inquilino Luca');
});

// ---------------------------------------------------------------------------------------------------------------------
// I rilievi della ripresa: la seconda vendita dopo una fermata (decisione 81.2)
// ---------------------------------------------------------------------------------------------------------------------

/**
 * La fine di Ines il 1/7 con «Scrivi», le rate fino al 31/8, la vendita di Ugo a Elsa il 1/9 (tutta la sua quota, `$quota`) registrata, le
 * rate fino al 31/10. Carlo è la persona che compra da Elsa.
 */
function rbfDopoLaPrimaVendita($test, array $titolari = [['Ugo', 'proprietario', 100]], float|int $quota = 100): array
{
    $s = rbfScenario($test, $titolari);
    $s['fine'] = fclRegistra($test, $s, fclModulo($s) + ['conguaglio' => 'scrivi']);
    fclEmetti($test, $s, '2026-08-31');
    fclPersona($s, 'Elsa');
    fclPersona($s, 'Carlo');
    $s['prima'] = fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2026-09-01', $quota))['subentro'];
    fclEmetti($test, $s, '2026-10-31');

    return $s;
}

it('ripresa, la guardia — la seconda vendita (Elsa → Carlo il 1/11) dopo la fermata della prima: il registro conserva chi esce e la fermata di prima (uscente_id, uscente, dopo_la_fermata, piu_persone), e lo storico e il motivo del cancello li dicono; l\'annullamento, senza saldi scritti dopo, non avvisa di un saldo del Wallet', function () {
    // Le chiavi nuove di `FermataDopoScrivi::perIlRegistro` e le frasi che le leggono (decisione 81.2), che non avevano un test. Le cifre (di
    // `CorrezioniDellaBeta50Test`): la coppia 18148; la prima vendita, Ugo 62 giorni ed Elsa 122: 18148 × 122/184 = 12032,91 → la fermata
    // 12033 (€ 120,33) verso Elsa; la seconda, Ugo 62, Elsa 61, Carlo 61: 6115,09 / 6016,46 / 6016,46, il centesimo a pari resto a chi esce
    // (Elsa) → Carlo 6016 (€ 60,16). La cifra resta a carico di chi ha le righe della coppia, Ugo, o di Elsa se la prima fermata è regolata.
    $s = rbfDopoLaPrimaVendita($this);
    $modulo = fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01');

    $motivi = fclAnteprima($this, $s, $modulo)['cancello']['motivi'];
    $seconda = fds50Registra($this, $s, $modulo)['subentro'];
    $f = fds50Fermate($seconda)[0] ?? [];

    expect([(int) ($f['uscente_id'] ?? 0), $f['uscente'] ?? null, $f['dopo_la_fermata'] ?? null, $f['piu_persone'] ?? null, $f['persona'] ?? null, (int) ($f['cifra'] ?? 0)])
        ->toBe([(int) $s['persone']['Elsa']->id, 'Elsa', ['passaggio' => 'la vendita del 1 settembre 2026', 'cifra' => 12033], false, 'Ugo', 6016])
        // Dalla 1.11.0-beta.53 il motivo dice anche l'alternativa, come il pannello.
        ->and($motivi)->toContain('€ 60,16 dei giorni dopo l\'uscita di Ines restano a carico di Ugo, o di Elsa se la fermata della vendita del 1 settembre 2026 è già stata regolata (gestione Ordinaria 2026): il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Carlo con questo passaggio, come non era passato a Elsa con la vendita del 1 settembre 2026')
        ->and(implode(' ', fds50Storico($s, $seconda)['fermate'] ?? []))->toContain('da regolare a mano con Carlo, o fra Elsa e Carlo se la fermata della vendita del 1 settembre 2026 era già stata regolata.')
        // Dalla 1.11.0-beta.53 gli avvisi dell'annullamento non leggono il registro della fermata: nominano i saldi scritti a mano dopo la
        // registrazione (qui nessuno), e i saldi legati li dice l'effetto.
        ->and(implode(' | ', app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->avvisi($seconda->fresh())))->not->toContain('a debito di Carlo')->not->toContain('Wallet');
});

it('ripresa — Ugo e Rita comproprietari al 50 %, Ugo vende la sua metà a Elsa il 1/9 (la fermata, € 60,16, resta a carico di Ugo), Elsa rivende a Carlo il 1/11: la seconda vendita non dice che la fermata di prima è «rimasta a carico di Ugo e Rita», né che i € 30,08 di Carlo restano a carico di Ugo e Rita', function () {
    // Trovato scrivendo la guardia di `piu_persone`. `FermataDopoScrivi::dopoUnaFermata` mette a carico tutti quelli che hanno righe nella
    // coppia (Ugo e Rita), mentre la parte di Elsa veniva dalla riga di Ugo, e il registro della prima vendita dice «a carico di Ugo»: le due
    // frasi si contraddicono. Le cifre: la coppia 9074 a Ugo e 9074 a Rita (18148 a metà). La prima vendita, sui 184 giorni a quota 50: Ugo 62,
    // Elsa 122, Rita 184 → 18148 × [31; 61; 92] / 184 = 3057,54 / 6016,46 / 9074 → 3057 + 6016 + 9074 = 18147, il centesimo al resto più grande
    // (,54, Ugo) → la fermata di Elsa 6016 (€ 60,16), a carico di Ugo. La seconda: Ugo 62, Elsa 61, Carlo 61, Rita 184 a quota 50 → 18148 ×
    // [31; 30,5; 30,5; 92] / 184 = 3057,54 / 3008,23 / 3008,23 / 9074 → 3057 + 3008 + 3008 + 9074 = 18147, il centesimo al resto più grande
    // (,54, Ugo) → Carlo 3008 (€ 30,08).
    $s = rbfDopoLaPrimaVendita($this, [['Ugo', 'proprietario', 50], ['Rita', 'proprietario', 50]], 50);
    $modulo = fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-11-01', 50);

    $a = fclAnteprima($this, $s, $modulo);
    $frase = collect($a['rate']['frasi'])->first(fn (string $f) => str_contains($f, '€ 30,08')) ?? '';

    // Controlli: la coppia, la prima fermata a carico di Ugo, e la seconda di € 30,08 verso Carlo.
    expect(fds50Coppia($s, $s['fine']))->toBe(['Ines' => -18148, 'Rita' => 9074, 'Ugo' => 9074])
        ->and(collect(fds50Fermate($s['prima']))->map(fn ($f) => [$f['persona'], (int) $f['cifra'], $f['verso']])->all())->toBe([['Ugo', 6016, ['Elsa']]])
        ->and(collect($a['rate']['fermate_dopo_scrivi'])->map(fn ($f) => [(int) $f['cifra'], $f['verso']])->all())->toBe([[3008, ['Carlo']]]);

    expect($frase)->not->toContain('rimasta a carico di Ugo e Rita')
        ->and($frase)->not->toContain('restano a carico di Ugo e Rita')
        ->and($frase)->toContain('€ 30,08 restano a carico di Ugo');
});
