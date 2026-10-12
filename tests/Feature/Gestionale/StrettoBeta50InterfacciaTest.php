<?php

/**
 * 1.11.0-beta.50, giro stretto sulle correzioni della Fase 1-bis — la parte del server dei rilievi sul modulo «Registra passaggio» e sul
 * pannello «Cosa cambierà» (verbale `piano_esecutivo_beta50.md`, «Giro stretto sulle correzioni»). Le pagine sono in
 * `StrettoBeta50Pannello.test.ts` e `StrettoBeta50Modulo.test.ts`.
 *
 * - G6: con la scheda delle pertinenze nella locazione (decisione 83.4) chi entra può diventare inquilino di un box di cui è
 *   proprietario. Il controllo «non può entrare anche come» guarda solo l'unità principale: va fatto su ogni unità del passaggio, con
 *   il nome della pertinenza davanti, nell'inizio, nel cambio d'inquilino e nella costituzione d'usufrutto (la correzione del rilievo
 *   dice «inizio, fine e usufrutto»).
 * - G7: la pertinenza spuntata nella locazione sta nello stesso contratto dell'unità, ma il passaggio figlio non riceve il regime del
 *   contratto né la data di fine: il prospetto del box dice «Il regime del contratto non è registrato».
 * - G9: la prop `righeDellaLocazione` (R16) manda anche il codice fiscale degli inquilini passati, che la pagina non legge; lo stesso
 *   `righeDellEstinzione`. La pagina legge l'id della riga, l'id e il nome della persona, il ruolo, la quota e le due date.
 * - G10 (senza test): nella locazione, che dalla 83.4 raggiunge le pertinenze dal modulo, nessun test fissava i rifiuti di una
 *   pertinenza sbagliata. Il codice li faceva già giusti; la prova che cadono senza il loro controllo è una mutazione in una
 *   copia.
 * - G20: nell'inizio con il box spuntato e nessun coinquilino sul box, chi entra va sul box al 100 % qualunque sia la quota del modulo,
 *   e il pannello dice solo la quota dell'unità. Il test non decide quale quota sia giusta sul box (lo decide Vincenzo con la voce di
 *   roadmap della decisione 77): chiede che il pannello dica quella che la registrazione scrive.
 *
 * Lo scenario è quello di `ScenariFineLocazione.php`: «Spese generali» € 1.200,00 sulla tabella «Proprietà», 30 % «Inquilino» e 70 %
 * «Proprietario»; il «Preventivo 2026» in dodici rate mensili; con il box l'Interno 1 ha 900 millesimi e il «Box 12», sua
 * pertinenza, ne ha 100. Il 2026 ha 365 giorni.
 *
 * Nati come test rossi, con accanto a ciascuno un controllo che dice che il rosso non veniva dal test.
 *
 * Cosa NON copre: le pagine Vue; G6 nell'estinzione dell'usufrutto, dove chi entra non si sceglie; la guardia di `apri` nella
 * registrazione, che confronta solo lo stesso ruolo (la richiesta si ferma prima); la fermata ridotta del box nel pannello
 * dell'inizio calcolata con la quota del modulo (G20, «non provato»); la rete di `RegistraSubentroAction` sulle pertinenze
 * sbagliate, che si raggiunge solo saltando la richiesta (qui la registrazione passa dalla rotta, e la richiesta si ferma prima); i
 * campi della prop `anagrafiche`, che la pagina usa per scegliere chi entra; le cifre del prospetto del box (qui solo la nota del
 * contratto); MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
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

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * I campi che il modulo manda all'anteprima e alla registrazione (`corpoAnteprima` e `submit` di `PassaggioNew.vue`), con i valori che
 * ha quando l'amministratore non tocca nient'altro.
 */
function sbiComeLoMandaIlModulo(array $campi): array
{
    return $campi + [
        'sottotipo' => null, 'copia_autentica' => false, 'copia_autentica_il' => null, 'estremi_titolo' => null, 'nota' => null,
        'data_fine_locazione' => null, 'regime_contratto' => null, 'ordinaria_dopo_atto' => 'usufruttuario', 'voci_da_tenere' => [],
        'nudi_che_tornano' => [], 'nudi_per_quota' => false, 'accrescimento' => false, 'conguaglio' => null,
        'promemoria_scadenza' => false, 'promemoria_giorni' => null, 'rinuncia_conguaglio' => false, 'nota_conguaglio' => null, 'ordinaria_impronta' => null,
    ];
}

/** L'inizio della locazione di una persona sull'Interno 1, con le pertinenze spuntate (gli id) e gli altri campi dati. */
function sbiInizio(array $s, string $chi, string $il, array $pertinenze = [], string $quota = '100', array $altro = []): array
{
    return sbiComeLoMandaIlModulo($altro + ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => null,
        'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => $il, 'quota' => $quota, 'pertinenze' => array_map('intval', $pertinenze)]);
}

/** La fine della locazione di Ines sull'Interno 1, con chi entra (il cambio d'inquilino) o senza, e le pertinenze spuntate (gli id). */
function sbiFine(array $s, ?string $entra, string $il, array $pertinenze = [], array $altro = []): array
{
    return sbiComeLoMandaIlModulo($altro + ['tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => fclRiga($s, 'Ines'),
        'anagrafica_entrante_id' => $entra !== null ? $s['persone'][$entra]->id : null, 'decorrenza' => $il, 'quota' => '100',
        'pertinenze' => array_map('intval', $pertinenze)]);
}

/** Tutti i messaggi di una risposta 422 dell'anteprima, campo per campo: [campo, messaggio]. */
function sbiErrori(TestResponse $r): array
{
    $out = [];
    foreach ((array) $r->json('errors') as $campo => $messaggi) {
        foreach ((array) $messaggi as $m) {
            $out[] = [(string) $campo, (string) $m];
        }
    }

    return $out;
}

/** I messaggi che la registrazione ha lasciato in sessione, in un testo solo. */
function sbiErroriInSessione(): string
{
    $errori = session('errors');

    return $errori ? implode(' | ', $errori->getBag('default')->all()) : '';
}

/**
 * Ciò che un passaggio scrive, in tutto il database: i passaggi, le righe di titolarità (con le date e le quote) e le righe in saldi.
 * Due impronte uguali vogliono dire che nessuna scrittura è passata.
 */
function sbiImpronta(): array
{
    return [
        'passaggi' => Subentro::count(),
        'righe' => DB::table('anagrafica_immobile')->orderBy('id')->get(['id', 'anagrafica_id', 'immobile_id', 'tipologia', 'quota', 'data_inizio', 'data_fine'])
            ->map(fn ($r) => implode('|', [(int) $r->id, (int) $r->anagrafica_id, (int) $r->immobile_id, $r->tipologia, (float) $r->quota, substr((string) $r->data_inizio, 0, 10), substr((string) $r->data_fine, 0, 10)]))->all(),
        'saldi' => DB::table('saldi')->count(),
    ];
}

/** Le righe di una persona su un'unità con un ruolo: [quota, data_inizio, data_fine], in ordine. */
function sbiRighe(array $s, Immobile $unita, string $nome, string $ruolo = 'inquilino'): array
{
    return DB::table('anagrafica_immobile')->where('immobile_id', $unita->id)->where('anagrafica_id', $s['persone'][$nome]->id)->where('tipologia', $ruolo)
        ->orderBy('id')->get(['quota', 'data_inizio', 'data_fine'])
        ->map(fn ($r) => [(float) $r->quota, substr((string) $r->data_inizio, 0, 10), $r->data_fine !== null ? substr((string) $r->data_fine, 0, 10) : null])->all();
}

/** Un'altra unità dello stesso condominio con la sua pertinenza «Box 99» (nessun titolare): l'id del box. */
function sbiBoxDiUnAltraUnita(array $s): int
{
    $interno2 = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'SBI-I2-' . $s['unita']->id, 'nome' => 'Interno 2', 'interno' => '2']);
    $box = Immobile::forceCreate(['condominio_id' => $s['c']->id, 'codice_immobile' => 'SBI-B99-' . $s['unita']->id, 'nome' => 'Box 99', 'descrizione' => 'Box',
        'interno' => 'B99', 'pertinenza_di_immobile_id' => $interno2->id]);

    return (int) $box->id;
}

// ---------------------------------------------------------------------------------------------------------------------
// G6: chi entra come inquilino è già proprietario del box spuntato
// ---------------------------------------------------------------------------------------------------------------------

it('G6 — l\'inizio di Luca sull\'Interno 1 di Ugo con spuntato il Box 12, di cui Luca è proprietario: si rifiuta come sull\'unità principale, con il nome del box, e non scrive niente', function () {
    // Atteso (correzione del rilievo: il controllo `giaTitolare` su ogni unità del passaggio, con il nome della pertinenza davanti):
    // un errore che dice il Box 12 e «Luca è già proprietario … non può entrare anche come inquilino». Prima della correzione
    // l'anteprima rispondeva 200 senza avvisi, e la registrazione scriveva sul box Luca proprietario al 100 % dal 2019 e inquilino
    // al 100 % dal 1/9.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [], 'box' => [['Luca', 'proprietario', 100]]]);
    fclPersona($s, 'Pia');

    // Controlli: sull'unità principale il rifiuto c'è già; Pia, che non è titolare di niente, entra con il box.
    $ugo = fclAnteprimaRisposta($this, $s, sbiInizio($s, 'Ugo', '2026-09-01', [$s['box']->id]));
    expect($ugo->status())->toBe(422)
        ->and(sbiErrori($ugo))->toContain(['anagrafica_entrante_id', 'Ugo è già proprietario di questa unità: non può entrare anche come inquilino.']);
    fclAnteprima($this, $s, sbiInizio($s, 'Pia', '2026-09-01', [$s['box']->id]));

    $prima = sbiImpronta();
    $modulo = sbiInizio($s, 'Luca', '2026-09-01', [$s['box']->id]);
    $a = fclAnteprimaRisposta($this, $s, $modulo);
    expect($a->status())->toBe(422, 'l\'anteprima accetta Luca inquilino del Box 12, di cui è proprietario');
    $sulBox = collect(sbiErrori($a))->first(fn ($e) => str_contains($e[1], 'Box 12') && str_contains($e[1], 'Luca è già proprietario') && str_contains($e[1], 'non può entrare anche come inquilino'));
    expect($sulBox)->not->toBeNull('il rifiuto non nomina il Box 12');

    fclStore($this, $s, $modulo);
    expect(sbiErroriInSessione())->toContain('Box 12')
        ->and(sbiImpronta())->toBe($prima)
        ->and(sbiRighe($s, $s['box'], 'Luca'))->toBe([]);
});

it('G6 — il cambio d\'inquilino: Ines esce il 1/7 ed entra Luca, con spuntato il Box 12 di cui Luca è proprietario e Ines inquilina: lo stesso rifiuto, e nessuna scrittura', function () {
    // Il rilievo vale per «inizio, fine e usufrutto»: nel cambio d'inquilino chi entra è inquilino anche delle pertinenze spuntate.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Luca', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);

    // Controllo: lo stesso cambio senza il box passa.
    fclAnteprima($this, $s, sbiFine($s, 'Luca', '2026-07-01'));

    $prima = sbiImpronta();
    $modulo = sbiFine($s, 'Luca', '2026-07-01', [$s['box']->id]);
    $a = fclAnteprimaRisposta($this, $s, $modulo);
    expect($a->status())->toBe(422, 'l\'anteprima accetta Luca inquilino del Box 12, di cui è proprietario');
    $sulBox = collect(sbiErrori($a))->first(fn ($e) => str_contains($e[1], 'Box 12') && str_contains($e[1], 'Luca è già proprietario') && str_contains($e[1], 'non può entrare anche come inquilino'));
    expect($sulBox)->not->toBeNull('il rifiuto non nomina il Box 12');

    fclStore($this, $s, $modulo);
    expect(sbiImpronta())->toBe($prima)
        ->and(sbiRighe($s, $s['box'], 'Luca'))->toBe([]);
});

it('G6 — la costituzione d\'usufrutto di Ugo a Carlo con spuntato il Box 12, di cui Carlo è inquilino: lo stesso rifiuto, con il nome del box, e nessuna scrittura', function () {
    // La correzione del rilievo vale «per inizio, fine e usufrutto»: il controllo dell'unità principale c'è già per tutti e tre, e
    // sulla pertinenza spuntata va fatto allo stesso modo. Una correzione che guardasse il box solo nella locazione lascerebbe Carlo
    // inquilino e usufruttuario dello stesso box. Prima della correzione l'anteprima rispondeva 200.
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [], 'box' => [['Ugo', 'proprietario', 100], ['Carlo', 'inquilino', 100]]]);
    fclPersona($s, 'Pia');
    $costituzione = fn (string $chi, array $pertinenze) => sbiComeLoMandaIlModulo(['tipo' => 'usufrutto', 'sottotipo' => 'costituzione', 'tipologia' => 'usufruttuario',
        'riga_uscente_id' => fclRiga($s, 'Ugo', 'proprietario'), 'anagrafica_entrante_id' => $s['persone'][$chi]->id, 'decorrenza' => '2026-07-01', 'quota' => '100',
        'estremi_titolo' => 'atto notaio Verdi, rep. 901', 'pertinenze' => array_map('intval', $pertinenze)]);

    // Controlli: la stessa costituzione senza il box passa; Pia, che non è titolare di niente, la riceve anche con il box.
    fclAnteprima($this, $s, $costituzione('Carlo', []));
    fclAnteprima($this, $s, $costituzione('Pia', [$s['box']->id]));

    $prima = sbiImpronta();
    $modulo = $costituzione('Carlo', [$s['box']->id]);
    $a = fclAnteprimaRisposta($this, $s, $modulo);
    expect($a->status())->toBe(422, 'l\'anteprima accetta Carlo usufruttuario del Box 12, di cui è inquilino');
    $sulBox = collect(sbiErrori($a))->first(fn ($e) => str_contains($e[1], 'Box 12') && str_contains($e[1], 'Carlo è già inquilino') && str_contains($e[1], 'non può entrare anche come usufruttuario'));
    expect($sulBox)->not->toBeNull('il rifiuto non nomina il Box 12');

    fclStore($this, $s, $modulo);
    expect(sbiErroriInSessione())->toContain('Box 12')
        ->and(sbiImpronta())->toBe($prima)
        ->and(sbiRighe($s, $s['box'], 'Carlo', 'usufruttuario'))->toBe([]);
});

// ---------------------------------------------------------------------------------------------------------------------
// G7: il box spuntato nella locazione sta nello stesso contratto dell'unità
// ---------------------------------------------------------------------------------------------------------------------

it('G7 — la locazione di Luca con il Box 12 spuntato, regime «abitativo» e scadenza: il passaggio del box riceve il regime e la data di fine, e il prospetto del box dice la stessa nota dell\'Interno 1', function (string $come) {
    // Atteso (correzione del rilievo: in `applicaAllaPertinenza` il regime e la data di fine si copiano dal padre, come gli estremi e
    // la nota): il figlio sul box con ['abitativo', scadenza], e nella sezione di Luca del prospetto del box la nota della locazione
    // abitativa. Prima della correzione il figlio aveva [null, null] e il prospetto del box diceva «Il regime del contratto non è
    // registrato nel programma».
    if ($come === 'inizio') {
        $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [], 'box' => [['Ugo', 'proprietario', 100]]]);
        fclPersona($s, 'Luca');
        $scadenza = '2030-08-31';
        $padre = fclRegistra($this, $s, sbiInizio($s, 'Luca', '2026-01-01', [$s['box']->id], '100', ['regime_contratto' => 'abitativo', 'data_fine_locazione' => $scadenza]));
    } else {
        $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]]);
        fclPersona($s, 'Luca');
        $scadenza = '2030-06-30';
        $padre = fclRegistra($this, $s, sbiFine($s, 'Luca', '2026-07-01', [$s['box']->id], ['regime_contratto' => 'abitativo', 'data_fine_locazione' => $scadenza]));
    }
    fclGenera($s);

    $figlio = Subentro::where('subentro_padre_id', $padre->id)->firstOrFail();
    $prospetto = app(ProspettoOneriAccessori::class);
    $diLuca = fn (Immobile $u) => collect($prospetto->calcola($u->fresh(), $s['e'])['conduttori'])->firstWhere('nome', 'Luca');
    $abitativa = $prospetto->notaContratto('abitativo');

    // Controlli: il padre ha il regime e la scadenza, e la sezione di Luca sull'Interno 1 dice la locazione abitativa.
    expect([$padre->regime_contratto, $padre->data_fine_locazione?->toDateString()])->toBe(['abitativo', $scadenza])
        ->and((int) $figlio->immobile_id)->toBe((int) $s['box']->id)
        ->and($diLuca($s['unita'])['nota_contratto'] ?? null)->toBe($abitativa)
        ->and($diLuca($s['box']))->not->toBeNull();

    expect([$figlio->regime_contratto, $figlio->data_fine_locazione?->toDateString()])->toBe(['abitativo', $scadenza])
        ->and($diLuca($s['box'])['nota_contratto'])->toBe($abitativa);
})->with([
    'l\'inizio dal 1/1' => ['inizio'],
    'il cambio d\'inquilino del 1/7' => ['cambio'],
]);

// ---------------------------------------------------------------------------------------------------------------------
// G9: le righe della locazione e dell'estinzione con i soli campi che la pagina legge
// ---------------------------------------------------------------------------------------------------------------------

/** Le chiavi di una riga e della sua persona, in ordine alfabetico: [chiavi della riga, chiavi della persona]. */
function sbiChiavi(array $riga): array
{
    $r = array_keys($riga);
    sort($r);
    $p = array_keys((array) ($riga['anagrafica'] ?? []));
    sort($p);

    return [$r, $p];
}

it('G9 — la pagina della fine di Ines riceve le righe della locazione (anche quella chiusa di Dora) senza il codice fiscale: solo l\'id, la persona (id e nome), il ruolo, la quota e le date', function () {
    // Atteso: i campi che `PassaggioNew.vue` legge da `righeDellaLocazione` (in `altriInquilini`: t.id, t.tipologia, t.quota,
    // t.anagrafica.id, t.anagrafica.nome, e le date per `inCorsoIl`). Prima della correzione ogni riga portava anche
    // `anagrafica.codice_fiscale`, per esempio quello di Dora, uscita il 31/8, che dal condominio potrebbe essersi staccata da
    // anni.
    Carbon::setTestNow('2026-10-12 10:00:00');
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 50], ['Dora', 50]]]);
    fclRegistra($this, $s, fclModulo($s, null, '2026-09-01', [], 'Dora'));

    $pagina = $this->actingAs($this->user)
        ->get(route('admin.gestionale.immobili.passaggi.create', [$s['c'], $s['unita'], 'tipo' => 'fine_locazione', 'riga' => fclRiga($s, 'Ines')]))
        ->assertOk()->viewData('page')['props'];
    $righe = $pagina['righeDellaLocazione'];
    $dora = collect($righe)->first(fn ($r) => ($r['anagrafica']['nome'] ?? null) === 'Dora');

    // Controlli: la riga di Dora c'è, con le sue date; `titolari` tiene il codice fiscale, che la pagina mostra sotto il nome di
    // chi esce.
    expect($dora)->not->toBeNull()
        ->and([$dora['data_inizio'], $dora['data_fine']])->toBe(['2019-01-01', '2026-08-31'])
        ->and(collect($pagina['titolari'])->firstWhere('anagrafica.nome', 'Ines')['anagrafica']['codice_fiscale'] ?? null)->not->toBeNull();

    foreach ($righe as $r) {
        expect(sbiChiavi($r))->toBe([['anagrafica', 'data_fine', 'data_inizio', 'id', 'quota', 'tipologia'], ['id', 'nome']],
            sprintf('la riga di %s porta campi che la pagina non legge', $r['anagrafica']['nome'] ?? '?'));
    }
});

it('G9 — lo stesso per la pagina dell\'usufrutto: le righe dell\'estinzione (nudi e usufruttuari) senza il codice fiscale', function () {
    // La correzione del rilievo: «lo stesso per righeDellEstinzione». La pagina ne legge gli stessi campi (`nudiAllaDecorrenza`,
    // `nudiCandidati`, `altriUsufrutti`, la scheda «Chi torna proprietario pieno»).
    $s = fclScenario([['Ugo', 'nuda_proprietario', 100], ['Carlo', 'usufruttuario', 100]]);

    $righe = $this->actingAs($this->user)
        ->get(route('admin.gestionale.immobili.passaggi.create', [$s['c'], $s['unita'], 'tipo' => 'usufrutto']))
        ->assertOk()->viewData('page')['props']['righeDellEstinzione'];

    // Controllo: le due righe ci sono.
    expect(collect($righe)->pluck('anagrafica.nome')->sort()->values()->all())->toBe(['Carlo', 'Ugo']);

    foreach ($righe as $r) {
        expect(sbiChiavi($r))->toBe([['anagrafica', 'data_fine', 'data_inizio', 'id', 'quota', 'tipologia'], ['id', 'nome']],
            sprintf('la riga di %s porta campi che la pagina non legge', $r['anagrafica']['nome'] ?? '?'));
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// G10 (senza test): le pertinenze sbagliate nella fine e nell'inizio della locazione si rifiutano, e non si scrive niente
// ---------------------------------------------------------------------------------------------------------------------

it('G10 — la fine di Ines il 1/7 con «Scrivi» e spuntato il Box 12, di cui è inquilina Pia: «Box 12: Ines non risulta inquilino alla data del passaggio…», e nessuna scrittura', function () {
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Ugo', 'proprietario', 100], ['Pia', 'inquilino', 100]]]);
    fclGenera($s);
    fclEmetti($this, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);

    // Controllo: la stessa fine senza il box passa, con la coppia dell'Interno 1 (€ 1.200,00 × 900/1000 × 30 % = 32400;
    // 32400 × 184/365 = 16333,15 → 16333 a Ugo).
    expect(fclCoppie(fclAnteprima($this, $s, sbiFine($s, null, '2026-07-01', [], ['conguaglio' => 'scrivi']))))->toBe([[(int) $s['unita']->id, 'Ugo', 16333]]);

    $prima = sbiImpronta();
    $modulo = sbiFine($s, null, '2026-07-01', [$s['box']->id], ['conguaglio' => 'scrivi']);
    $a = fclAnteprimaRisposta($this, $s, $modulo);
    expect($a->status())->toBe(422)
        ->and(sbiErrori($a))->toBe([['pertinenze', 'Box 12: Ines non risulta inquilino alla data del passaggio. Togli la spunta, o registra il passaggio dalla pertinenza.']]);

    fclStore($this, $s, $modulo)->assertSessionHasErrors('pertinenze');
    expect(sbiImpronta())->toBe($prima);
});

it('G10 — una pertinenza che non è dell\'Interno 1 nella locazione: «Una delle pertinenze indicate non è collegata a questa unità.», e nessuna scrittura', function (string $tipo, string $dove) {
    // Il Box 99 di un'altra unità dello stesso condominio, o il Box 12 di un altro condominio (con Ines inquilina, perché l'unico
    // ostacolo sia il collegamento). Nell'inizio entra Luca.
    $s = fclScenario([['Ugo', 'proprietario', 100]], $tipo === 'inizio' ? ['inquilini' => []] : []);
    fclPersona($s, 'Luca');
    $box = $dove === 'altra unità'
        ? sbiBoxDiUnAltraUnita($s)
        : (int) fclScenario([['Ugo', 'proprietario', 100]], ['box' => [['Ugo', 'proprietario', 100], ['Ines', 'inquilino', 100]]])['box']->id;
    $modulo = fn (array $pertinenze) => $tipo === 'inizio' ? sbiInizio($s, 'Luca', '2026-09-01', $pertinenze) : sbiFine($s, null, '2026-07-01', $pertinenze);

    // Controllo: lo stesso passaggio senza la pertinenza passa.
    fclAnteprima($this, $s, $modulo([]));

    $prima = sbiImpronta();
    $a = fclAnteprimaRisposta($this, $s, $modulo([$box]));
    expect($a->status())->toBe(422)
        ->and(sbiErrori($a))->toBe([['pertinenze', 'Una delle pertinenze indicate non è collegata a questa unità.']]);

    fclStore($this, $s, $modulo([$box]))->assertSessionHasErrors('pertinenze');
    expect(sbiImpronta())->toBe($prima);
})->with([
    'la fine, un box di un\'altra unità' => ['fine', 'altra unità'],
    'la fine, un box di un altro condominio' => ['fine', 'altro condominio'],
    'l\'inizio, un box di un\'altra unità' => ['inizio', 'altra unità'],
    'l\'inizio, un box di un altro condominio' => ['inizio', 'altro condominio'],
]);

// ---------------------------------------------------------------------------------------------------------------------
// G20: l'inizio con il box spuntato e nessun coinquilino sul box
// ---------------------------------------------------------------------------------------------------------------------

it('G20 — Dora inquilina al 50 % del solo Interno 1, Luca entra il 1/9 al 50 % con il Box 12 spuntato: il pannello dice la quota che la registrazione scrive sul Box 12', function () {
    // Atteso (correzione del rilievo): per ogni pertinenza spuntata il pannello dice la quota che la registrazione scriverà, nel caso
    // misurato «sul Box 12 al 100 %». Quale quota sia giusta sul box non lo decide il test: la legge da ciò che la registrazione
    // scrive. Prima della correzione il pannello diceva solo «Luca risulterà inquilino dal 1 settembre 2026, al 50 %.» e, a parte,
    // «Box 12».
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Dora', 50]], 'box' => [['Ugo', 'proprietario', 100]]]);
    fclPersona($s, 'Luca');
    $modulo = sbiInizio($s, 'Luca', '2026-09-01', [$s['box']->id], '50');

    $a = fclAnteprima($this, $s, $modulo);
    fclRegistra($this, $s, $modulo);
    $sulBox = sbiRighe($s, $s['box'], 'Luca');

    // Controlli: il pannello dice la quota dell'unità e il nome del box; la registrazione scrive Luca al 50 % sull'Interno 1,
    // e una riga sola sul box.
    expect(implode(' ', $a['anagrafica']['frasi']))->toContain("Luca risulterà inquilino dal 1 settembre 2026, al 50\u{00A0}%")
        ->and(implode(' ', $a['anagrafica']['pertinenze']))->toContain('Box 12')
        ->and(sbiRighe($s, $s['unita'], 'Luca'))->toBe([[50.0, '2026-09-01', null]])
        ->and($sulBox)->toHaveCount(1);

    $quota = rtrim(rtrim(number_format($sulBox[0][0], 2, ',', ''), '0'), ',');
    $frase = collect([...$a['anagrafica']['frasi'], ...$a['anagrafica']['pertinenze']])
        ->first(fn (string $f) => str_contains($f, 'Box 12') && preg_match('/\b' . preg_quote($quota, '/') . '[\s\x{00A0}]?%/u', $f));
    expect($frase)->not->toBeNull(sprintf('il pannello non dice che sul Box 12 Luca sarà inquilino al %s %%', $quota));
});
