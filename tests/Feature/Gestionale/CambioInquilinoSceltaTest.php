<?php

/**
 * 1.11.0-beta.50 — il cambio d'inquilino («Fine locazione» con un nuovo inquilino): «Scrivi il conguaglio» o «Non scriverlo» al posto
 * della casella «Le parti hanno regolato il conguaglio fra loro» (decisione 77, punto 2: «un vocabolario solo per tutta la locazione»).
 *
 * Prima della .50 il cambio d'inquilino scriveva la coppia di partenza, e per non scriverla si spuntava la casella e si scriveva una
 * nota (si dichiara un accordo, decisione 73.3); il campo `conguaglio` si rifiutava fuori dalla successione. Dalla .50, quando
 * l'anteprima propone una coppia, la scelta si chiede senza niente di già scelto, con il campo `conguaglio` (`scrivi` / `non_scrivere`),
 * nota facoltativa; la scelta resta nel registro (`conguaglio.scelta`). Un modulo rimasto aperto da prima dell'aggiornamento, che manda
 * la vecchia casella spuntata, vale «Non scriverlo» (la regola della .48 per la successione, `decide_l_amministratore.md`). La casella
 * resta per la vendita e l'usufrutto, dove il campo `conguaglio` continua a rifiutarsi.
 *
 * Le cifre, fatte a mano con la regola della coppia (resti maggiori, il centesimo al resto più grande, a pari resto a chi esce): «Spese
 * generali» € 1.200,00, 30 % «Inquilino» = 36000, 70 % «Proprietario» = 84000; il 1/7 Ines lascia l'unità a Luca: Ines 181 giorni, Luca
 * 184. 36000 × 181/365 = 17852,05, × 184/365 = 18147,95 → Ines 17852, la coppia 18148 a Luca. Sul box locato insieme (Interno 1 900,
 * Box 12 100 millesimi): Interno 1 32400 × [181, 184] = 16066,85 / 16333,15 → 16067 / 16333; Box 3600 × [181, 184] = 1785,21 /
 * 1814,79 → 1785 / 1815.
 *
 * Fissa che il campo `conguaglio` si accetta nella fine locazione (prima si rifiutava: è la cosa nuova della beta, dichiarata). I
 * controlli, che il codice di prima faceva già giusti e devono restarlo, lo dicono nel nome.
 *
 * ➕ 11/10/2026 notte, revisione dei test della Fase 1: aggiunti la scelta con la vecchia casella (SIC-2 della .48 allargato alla
 *   locazione) e un valore del campo che non è una delle due voci (lente sicurezza); il registro si controlla sulla chiave
 *   `conguaglio.scelta`.
 *
 * Cosa NON copre: la pagina Vue (le due voci al posto della casella, `rinunciaConguaglio.ts`, il testo a video); il prospetto degli
 * oneri dopo il cambio con «Non scriverlo» (la strada A′: è nelle versioni nuove di `ProspettoCambioInquilinoTest`) e il cambio in due
 * passi (area del prospetto); due cambi di fila; il cambio con un coinquilino che resta; «Annulla il conguaglio» dopo «Scrivi» (resta
 * com'è, decisioni 43 e 46: `ProspettoCambioInquilinoTest`); MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';

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
 * L'unità di Ugo con Ines inquilina dal 2019, il preventivo generato e le rate gennaio–giugno a giornale; Luca è una persona del
 * condominio, non ancora sull'unità. Con `$box` anche il Box 12 di Ugo, locato a Ines insieme all'unità.
 *
 * @return array{0: array, 1: \App\Models\Anagrafica} lo scenario e Luca
 */
function cisConSeiRate($test, bool $box = false): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], $box ? ['box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]] : []);
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);
    $luca = fclPersona($s, 'Luca');

    return [$s, $luca];
}

/** La riga di inquilino di una persona sull'Interno 1 nata il giorno dato, se c'è: [inizio, fine]. */
function cisRigaInquilino(array $s, string $nome): ?array
{
    $r = DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone'][$nome]->id)->where('tipologia', 'inquilino')->first();

    return $r === null ? null : [substr((string) $r->data_inizio, 0, 10), $r->data_fine ? substr((string) $r->data_fine, 0, 10) : null];
}

/** Il modulo della vendita di Ugo a Elsa il 1/7, con gli estremi dell'atto. */
function cisVendita(array $s, \App\Models\Anagrafica $elsa): array
{
    return ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fclRiga($s, 'Ugo', 'proprietario'), 'anagrafica_entrante_id' => $elsa->id,
        'decorrenza' => '2026-07-01', 'quota' => 100, 'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Verdi, rep. 900', 'pertinenze' => []];
}

/** Il modulo della costituzione d'usufrutto di Ugo a Elsa il 1/7, con la legge sull'ordinaria (obbligatoria, decisione 31.5). */
function cisCostituzione(array $s, \App\Models\Anagrafica $elsa): array
{
    return ['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'tipologia' => 'usufruttuario', 'riga_uscente_id' => fclRiga($s, 'Ugo', 'proprietario'),
        'anagrafica_entrante_id' => $elsa->id, 'decorrenza' => '2026-07-01', 'quota' => 100, 'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Verdi, rep. 901',
        'pertinenze' => [], 'ordinaria_dopo_atto' => 'usufruttuario'];
}

// ---------------------------------------------------------------------------------------------------------------------
// La scelta nel cambio d'inquilino
// ---------------------------------------------------------------------------------------------------------------------

it('senza la scelta il cambio d\'inquilino con sei rate a giornale si ferma sul campo «conguaglio», e non scrive niente', function () {
    // Decisione 77 (2): le due voci, nessuna già scelta.
    [$s, $luca] = cisConSeiRate($this);

    fclStore($this, $s, fclModulo($s, $luca))->assertSessionHasErrors(['conguaglio' => 'Scegli se scrivere il conguaglio: il programma non lo sceglie al posto tuo.']);

    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0)
        ->and(cisRigaInquilino($s, 'Ines'))->toBe(['2019-01-01', null])
        ->and(cisRigaInquilino($s, 'Luca'))->toBeNull()
        ->and(fclRigheDeiPassaggi($s))->toBe(0);
});

it('controllo — l\'anteprima del cambio d\'inquilino senza la scelta riesce e propone la coppia verso Luca, € 181,48', function () {
    // L'amministratore sceglie dopo aver visto le cifre. Controllo: già giusto prima della .50.
    [$s, $luca] = cisConSeiRate($this);

    // La coppia: 18148 (vedi la testa del file).
    expect(fclCoppie(fclAnteprima($this, $s, fclModulo($s, $luca))))->toBe([[(int) $s['unita']->id, 'Luca', 18148]]);
});

it('il campo «conguaglio» è ammesso nella fine locazione: l\'anteprima del cambio d\'inquilino con la scelta risponde', function (string $scelta) {
    // Decisione 77: «il campo è `conguaglio`, come nella successione»; il divieto della .48 (rilievo SIC-2) si allarga alla fine
    // locazione, non si toglie.
    [$s, $luca] = cisConSeiRate($this);

    fclAnteprimaRisposta($this, $s, fclModulo($s, $luca) + ['conguaglio' => $scelta])->assertOk();
})->with(['scrivi', 'non_scrivere']);

it('«Scrivi» nel cambio d\'inquilino scrive la coppia fra Ines e Luca, e la scelta resta nel registro', function () {
    // Decisione 77 (2).
    [$s, $luca] = cisConSeiRate($this);

    $cambio = fclRegistra($this, $s, fclModulo($s, $luca) + ['conguaglio' => 'scrivi'])->fresh();

    // Ines 36000 − 18148 = 17852; Luca 18148; Ugo il «Proprietario» 84000. 17852 + 18148 + 84000 = 120000.
    expect(fclSaldi($s))->toBe(['Ines' => -18148, 'Luca' => 18148])
        ->and(fclNetti($s))->toBe(['Ines' => 17852, 'Luca' => 18148, 'Ugo' => 84000])
        ->and($cambio->registro['conguaglio']['scelta'] ?? null)->toBe('scritto')
        ->and($cambio->nota_conguaglio)->toBeNull()
        ->and($cambio->conguaglioRinunciato())->toBeFalse();
});

it('«Non scriverlo» nel cambio d\'inquilino, senza nota: nessuna riga in saldi, Luca entra dal 1/7, e la scelta resta nel registro', function () {
    // Decisione 77 (2): nota facoltativa (non si dichiara un accordo, decisione 73.1).
    [$s, $luca] = cisConSeiRate($this);

    $cambio = fclRegistra($this, $s, fclModulo($s, $luca) + ['conguaglio' => 'non_scrivere'])->fresh();

    // Verso il condominio niente cambia: Ines le sue 36000, Ugo 84000, Luca niente sul piano fermo.
    expect(fclRigheDeiPassaggi($s))->toBe(0)
        ->and(fclNetti($s))->toBe(['Ines' => 36000, 'Ugo' => 84000])
        ->and(cisRigaInquilino($s, 'Luca'))->toBe(['2026-07-01', null])
        ->and(cisRigaInquilino($s, 'Ines'))->toBe(['2019-01-01', '2026-06-30'])
        ->and($cambio->registro['conguaglio']['scelta'] ?? null)->toBe('non_scritto')
        ->and($cambio->nota_conguaglio)->toBeNull()
        ->and($cambio->conguaglioNonScritto())->toBeTrue();
});

it('un modulo rimasto aperto da prima dell\'aggiornamento, che manda la vecchia casella spuntata con la nota, vale «Non scriverlo»', function () {
    // La regola della .48 per la successione (`decide_l_amministratore.md`, «Nella beta.48»), con un vocabolario solo per la locazione
    // (decisione 77, 2).
    [$s, $luca] = cisConSeiRate($this);

    $cambio = fclRegistra($this, $s, fclModulo($s, $luca) + ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro le spese dell\'inquilino'])->fresh();

    expect(fclRigheDeiPassaggi($s))->toBe(0)
        ->and($cambio->registro['conguaglio']['scelta'] ?? null)->toBe('non_scritto')
        ->and($cambio->conguaglioNonScritto())->toBeTrue();
});

it('la scelta e la vecchia casella mandate insieme si contraddicono: la locazione le rifiuta sul campo «conguaglio», come la successione, e non scrive niente', function (bool $conLuca, string $scelta) {
    // Rilievo SIC-2 della .48 (`AnteprimaPassaggioRequest::controllaSuccessione`): «la scelta e la casella di prima insieme si
    // contraddicono, e la scelta vinceva in silenzio» («Scrivi» con la casella perdeva la nota che dichiara l'accordo). Con la .50 la
    // locazione ha le stesse due voci (decisione 77, 2: un vocabolario solo), e lo stesso rifiuto. Aggiunto nella revisione dei test
    // della Fase 1. Prima della .50 dava lo stesso esito per un'altra ragione (il campo era vietato fuori dalla successione: 422 sul
    // campo).
    [$s, $luca] = cisConSeiRate($this);

    $modulo = fclModulo($s, $conLuca ? $luca : null) + ['conguaglio' => $scelta, 'rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro le spese dell\'inquilino'];
    fclStore($this, $s, $modulo)->assertSessionHasErrors('conguaglio');

    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0)
        ->and(fclRigheDeiPassaggi($s))->toBe(0);
})->with([
    'cambio d\'inquilino, «Scrivi» con la casella' => [true, 'scrivi'],
    'fine senza nuovo inquilino, «Scrivi» con la casella' => [false, 'scrivi'],
]);

it('controllo — un valore del campo «conguaglio» che non è una delle due voci si rifiuta sul campo, nella fine e nel cambio d\'inquilino', function (bool $conLuca) {
    // Lente sicurezza (il campo nuovo nella locazione): solo `scrivi` o `non_scrivere`, come nella successione. Aggiunto nella
    // revisione dei test della Fase 1. Prima della .50 dava lo stesso esito (il campo era vietato).
    [$s, $luca] = cisConSeiRate($this);

    fclAnteprimaRisposta($this, $s, fclModulo($s, $conLuca ? $luca : null) + ['conguaglio' => 'forse'])->assertStatus(422)->assertJsonValidationErrors(['conguaglio']);
    fclStore($this, $s, fclModulo($s, $conLuca ? $luca : null) + ['conguaglio' => 'forse'])->assertSessionHasErrors('conguaglio');

    expect(Subentro::where('immobile_id', $s['unita']->id)->count())->toBe(0);
})->with(['cambio d\'inquilino' => [true], 'fine senza nuovo inquilino' => [false]]);

it('il cambio d\'inquilino con il box locato insieme: una scelta sola per l\'unità e il box', function (string $scelta, array $unita, array $box) {
    // Decisione 77 (1 e 2): una scelta sola per l'appartamento e le pertinenze spuntate.
    [$s, $luca] = cisConSeiRate($this, box: true);

    fclRegistra($this, $s, fclModulo($s, $luca, '2026-07-01', [$s['box']]) + ['conguaglio' => $scelta]);

    expect(fclSaldi($s))->toBe($unita)
        ->and(fclSaldi($s, $s['box']))->toBe($box);
})->with([
    // Le coppie: Interno 1 16333, Box 1815 (vedi la testa del file), tutte e due verso Luca.
    'Scrivi' => ['scrivi', ['Ines' => -16333, 'Luca' => 16333], ['Ines' => -1815, 'Luca' => 1815]],
    'Non scriverlo' => ['non_scrivere', [], []],
]);

// ---------------------------------------------------------------------------------------------------------------------
// La casella resta per la vendita e l'usufrutto (controlli)
// ---------------------------------------------------------------------------------------------------------------------

it('controllo — nella vendita e nella costituzione d\'usufrutto il campo «conguaglio» resta vietato: l\'anteprima lo rifiuta, e senza il campo risponde', function (string $passaggio) {
    // Decisione 77: la casella resta per la vendita e l'usufrutto (decisione 73.3), dove «Non scriverlo» non c'è. Controllo: già
    // giusto prima della .50. Il corpo senza il campo risponde: il rifiuto è sul campo, non su altro.
    [$s] = cisConSeiRate($this);
    $elsa = fclPersona($s, 'Elsa');
    $modulo = $passaggio === 'vendita' ? cisVendita($s, $elsa) : cisCostituzione($s, $elsa);

    fclAnteprimaRisposta($this, $s, $modulo)->assertOk();
    fclAnteprimaRisposta($this, $s, $modulo + ['conguaglio' => 'non_scrivere'])->assertStatus(422)->assertJsonValidationErrors(['conguaglio']);
})->with(['vendita', 'costituzione d\'usufrutto']);

it('controllo — la vendita tiene la casella «Le parti hanno regolato il conguaglio fra loro», con la nota obbligatoria: senza nota si ferma, con la nota non scrive la coppia', function () {
    // Decisioni 73.3 e 77: la casella resta per la vendita, e con lei la nota (si dichiara un accordo). Controllo: già giusto prima
    // della .50.
    [$s] = cisConSeiRate($this);
    $elsa = fclPersona($s, 'Elsa');

    fclStore($this, $s, cisVendita($s, $elsa) + ['rinuncia_conguaglio' => true])->assertSessionHasErrors('nota_conguaglio');
    $vendita = fclRegistra($this, $s, cisVendita($s, $elsa) + ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Ugo ed Elsa hanno regolato fra loro al rogito'])->fresh();

    expect(fclRigheDeiPassaggi($s))->toBe(0)
        ->and($vendita->nota_conguaglio)->toBe('Ugo ed Elsa hanno regolato fra loro al rogito')
        ->and($vendita->registro)->not->toHaveKey('conguaglio')
        ->and($vendita->conguaglioRinunciato())->toBeTrue();
});

it('controllo — l\'inizio di una locazione non ha conguaglio, e il campo «conguaglio» resta vietato', function () {
    // Decisione 47: l'inizio locazione non ha conguaglio. Controllo: già giusto prima della .50.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => []]);
    fclGenera($s);
    $luca = fclPersona($s, 'Luca');
    $inizio = ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $luca->id, 'decorrenza' => '2026-07-01', 'quota' => 100,
        'copia_autentica' => false, 'regime_contratto' => 'abitativo', 'pertinenze' => []];

    fclAnteprimaRisposta($this, $s, $inizio)->assertOk();
    fclAnteprimaRisposta($this, $s, $inizio + ['conguaglio' => 'scrivi'])->assertStatus(422)->assertJsonValidationErrors(['conguaglio']);
});
