<?php

/**
 * Le regole dell'accordo di rientro oltre il caso di Giulia del registro: la lettera in coda prima dell'accordo, «residuo invariato» con una
 * rata coperta in parte, il segno con il filtro di una gestione, l'accordo nell'estratto conto della persona, una rata in un accordo solo,
 * la chiusura «da oggi», la persona con un accordo che non si cancella, la rata rigenerata dal ricalcolo del piano, il registro della persona
 * dopo la chiusura.
 *
 * Gli scenari: Giulia (unità A, rate mensili da € 500,00, la rata 1 scade il 05/01/2026 ed è emessa il 02/01) e il caso delle quattro rate
 * trimestrali da € 750,00 (le rate 1 e 2 in accordo dal 26/06, € 17,67 versati oltre il capitale a fine accordo).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Actions\PianoRate\RigeneraRateTenendoILegami;
use App\Models\Anagrafica;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Notifications\Gestionale\LetteraNotification;
use App\Services\Gestionale\Lettere\RegistroLettere;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LettereScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create(['name' => 'Anna Studio', 'email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

/** Giulia, unità A, rate mensili da € 500,00; la rata 1 (scadenza 05/01) emessa il 02/01. */
function argGiulia($test): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['giulia', 1000]], 12, 'prima_rata', [], 600000);
    lcdEmetti($test, $s, [1], '2026-01-02');

    return $s;
}

/** L'accordo di Giulia dalla rotta: [numero della rata => centesimi riconosciuti]. */
function argRegistra($test, array $s, array $rate, string $data, string $finoAl = '2026-06-30', string $chi = 'giulia', ?string $unita = null)
{
    $unita ??= array_key_first($s['immobili']);

    return $test->actingAs($test->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone'][$chi]->id, 'data_accordo' => $data, 'fino_al' => $finoAl, 'autorizzato_assemblea' => '0',
        'rate' => collect($rate)->map(fn ($cent, $n) => ['rata_id' => lcdRata($s, $n), 'immobile_id' => $s['immobili'][$unita]->id,
            'importo' => number_format($cent / 100, 2, ',', '.')])->values()->all(),
    ]);
}

function argRiga($test, array $s, array $query = []): ?array
{
    $righe = $test->actingAs($test->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id] + $query))->assertOk()->viewData('page')['props']['righe']['data'];

    return collect($righe)->first();
}

function argAnteprima($test, array $s): array
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.lettere.anteprima', ['condominio' => $s['c']->id]), [
        'tipo' => 'sollecito', 'tutte' => 1, 'filtri' => [], 'testo' => 'Gentile [nome], risultano da versare [totale].', 'termine' => 15,
    ])->assertOk()->json();
}

/** Il sollecito per email a Giulia, messo in coda (il lavoratore della coda non è partito). */
function argSollecitoInCoda($test, array $s): RegistroInvio
{
    Queue::fake();
    $test->actingAs($test->user)->postJson(route('admin.gestionale.lettere.invia', ['condominio' => $s['c']->id]), [
        'tipo' => 'sollecito', 'persone' => [$s['persone']['giulia']->id], 'testo' => 'Gentile [nome], [totale].', 'termine' => 15, 'indirizzi' => ['email'],
    ])->assertOk();

    return RegistroInvio::where('tipo', 'sollecito')->sole();
}

it('un sollecito in coda prima dell\'accordo non parte se, alla partenza, l\'accordo copre una parte di quello che la lettera chiede', function () {
    $s = argGiulia($this);
    $s['persone']['giulia']->update(['email' => 'giulia@prova.it']);
    $this->travelTo('2026-02-10 09:00:00');
    $riga = argSollecitoInCoda($this, $s);
    expect($riga->stato)->toBe('in_coda')->and($riga->dati['totale'])->toBe(50000);

    // Alle 10:00 l'accordo riconosce € 200,00 della rata da € 500,00: la lettera di oggi chiederebbe € 300,00.
    $this->travelTo('2026-02-10 10:00:00');
    argRegistra($this, $s, [1 => 20000], '2026-02-10')->assertCreated();
    expect(argAnteprima($this, $s)['persone'][0]['totale'])->toBe(30000);

    // Il 12/02 il lavoratore la fa partire: la guardia rilegge anche l'accordo, e la lettera da € 500,00 non parte.
    $this->travelTo('2026-02-12 10:00:00');
    expect(LetteraNotification::debitoCambiato($riga->fresh()))->toBeTrue();
});

it('un sollecito in coda prima dell\'accordo che copre tutta la rata non parte; uno con le rate in accordo rimesse dall\'amministratore sì', function () {
    $s = argGiulia($this);
    $s['persone']['giulia']->update(['email' => 'giulia@prova.it']);
    $this->travelTo('2026-02-10 09:00:00');
    $riga = argSollecitoInCoda($this, $s);
    $this->travelTo('2026-02-10 10:00:00');
    argRegistra($this, $s, [1 => 50000], '2026-02-10')->assertCreated();
    expect(argAnteprima($this, $s)['solo_in_accordo'])->toBe(['Giulia']);

    $this->travelTo('2026-02-12 10:00:00');
    expect(LetteraNotification::debitoCambiato($riga->fresh()))->toBeTrue();

    // La stessa lettera scritta con «Metti nella lettera anche le rate in accordo di rientro»: l'amministratore l'ha chiesta tutta.
    $riga->update(['dati' => $riga->dati + ['anche_in_accordo' => true]]);
    expect(LetteraNotification::debitoCambiato($riga->fresh()))->toBeFalse();
});

it('una rata coperta in parte: dopo un versamento su quella rata il segno non dice più «residuo invariato»', function () {
    $s = argGiulia($this);
    $this->travelTo('2026-02-10 10:00:00');
    argRegistra($this, $s, [1 => 20000], '2026-02-10')->assertCreated();
    $this->travelTo('2026-02-11 10:00:00');
    lcdIncassa($this, $s, 'giulia', [lcdQuota($s, 'giulia', 1) => 10000], '2026-02-11');

    $this->travelTo('2026-02-12 10:00:00');
    $riga = argRiga($this, $s);
    // La rata è scesa da € 500,00 a € 400,00: il residuo non è invariato. La parte in accordo resta il minore fra residuo e riconosciuto.
    expect($riga['rate'][0]['residuo'])->toBe(40000)
        ->and($riga['accordi'][0])->toMatchArray(['riconosciuti' => 20000, 'oggi' => 20000, 'invariato' => false])
        ->and($riga['rate'][0]['accordo'])->toMatchArray(['riconosciuto' => 20000, 'in_accordo' => 20000, 'in_parte' => true]);
});

it('alle 00:30 di Roma del 12/02 il versamento dell\'11/02 sulla rata coperta in parte toglie «residuo invariato»', function () {
    $s = argGiulia($this);
    $this->travelTo(Carbon::parse('2026-02-10 00:30', 'Europe/Rome'));
    argRegistra($this, $s, [1 => 20000], '2026-02-10')->assertCreated();
    $this->travelTo(Carbon::parse('2026-02-11 00:30', 'Europe/Rome'));
    lcdIncassa($this, $s, 'giulia', [lcdQuota($s, 'giulia', 1) => 10000], '2026-02-11');
    $this->travelTo(Carbon::parse('2026-02-12 00:30', 'Europe/Rome'));
    expect(argRiga($this, $s)['accordi'][0]['invariato'])->toBeFalse();
});

it('con il filtro di un\'altra gestione il segno dell\'accordo dice le cifre di tutte le sue rate, non «oggi € 0,00»', function () {
    $s = lttScenario($this);
    $g2 = lttSecondaGestione($this, $s);
    // Il 20/03 Ugo firma un accordo sulle rate 1 e 2 della gestione ordinaria (€ 60,00 + € 60,00).
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone']['ugo']->id, 'data_accordo' => '2026-03-20', 'fino_al' => '2026-09-30', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => $s['immobili']['A']->id, 'importo' => '60,00'],
            ['rata_id' => lcdRata($s, 2), 'immobile_id' => $s['immobili']['A']->id, 'importo' => '60,00']],
    ])->assertCreated();

    $ugo = fn (array $q) => collect($this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id] + $q))
        ->viewData('page')['props']['righe']['data'])->firstWhere('nome', 'Ugo');
    expect($ugo([])['accordi'][0])->toMatchArray(['riconosciuti' => 12000, 'oggi' => 12000, 'invariato' => true])
        ->and($ugo(['gestione_id' => $g2->id])['accordi'][0])->toMatchArray(['riconosciuti' => 12000, 'oggi' => 12000, 'invariato' => true]);
});

it('l\'accordo riuscito si legge nell\'estratto conto della persona, con i € 17,67 versati oltre il capitale, anche quando lei non è più fra le rate scadute', function () {
    $s = acgGiulia($this);
    $q = fn (int $n) => lcdQuota($s, 'giulia', $n);
    $this->travelTo('2026-06-26 10:00:00');
    argRegistra($this, $s, [1 => 75000, 2 => 75000], '2026-06-26', '2026-12-15')->assertCreated();
    acgIncassa($this, $s, [$q(1) => 25295], '2026-07-15');
    acgIncassa($this, $s, [$q(3) => 75000], '2026-07-25', 0, 'indicata_dal_versante');
    acgIncassa($this, $s, [$q(1) => 25295], '2026-08-15');
    acgIncassa($this, $s, [$q(1) => 24410, $q(2) => 885], '2026-09-15');
    acgIncassa($this, $s, [$q(2) => 25295], '2026-10-15');
    acgIncassa($this, $s, [$q(4) => 75000], '2026-11-02', 0, 'indicata_dal_versante');
    acgIncassa($this, $s, [$q(2) => 25295], '2026-11-15');
    acgIncassa($this, $s, [$q(2) => 23525], '2026-12-15', 1767);

    $this->travelTo('2026-12-16 10:00:00');
    expect(argRiga($this, $s))->toBeNull();
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone']['giulia']]))->assertOk()->viewData('page')['props'];
    expect($pagina['stats']['saldo_raw'])->toBe(-1767)
        ->and($pagina['accordi_rientro'])->toHaveCount(1)
        ->and($pagina['accordi_rientro'][0])->toMatchArray(['data_accordo' => '2026-06-26', 'riconosciuti' => 150000, 'oggi' => 0, 'versati_oltre' => 1767, 'chiuso_il' => null]);
});

it('una rata sta in un accordo solo: anche un secondo accordo con una data prima del primo è rifiutato; dopo la chiusura del primo, uno nuovo dopo la chiusura sì', function () {
    $s = argGiulia($this);
    $this->travelTo('2026-02-10 10:00:00');
    argRegistra($this, $s, [1 => 20000], '2026-02-10')->assertCreated();
    argRegistra($this, $s, [1 => 10000], '2026-02-10')->assertStatus(422)->assertJsonValidationErrors('rate');
    argRegistra($this, $s, [1 => 30000], '2026-01-20')->assertStatus(422)->assertJsonValidationErrors('rate');
    expect(AccordoRientro::count())->toBe(1);

    // Chiuso il 01/03: un accordo del 20/02 si sovrapporrebbe (il primo era in corso), uno del 05/03 no.
    $this->travelTo('2026-03-05 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $s['c']->id, 'accordo' => AccordoRientro::sole()->id]),
        ['chiuso_il' => '2026-03-01'])->assertOk();
    argRegistra($this, $s, [1 => 30000], '2026-02-20')->assertStatus(422);
    argRegistra($this, $s, [1 => 30000], '2026-03-05')->assertCreated();
    expect(AccordoRientro::count())->toBe(2);
});

it('la chiusura dice da quando le rate tornano nelle lettere proposte: da oggi, o dal giorno scelto', function () {
    $s = argGiulia($this);
    $this->travelTo('2026-02-10 10:00:00');
    argRegistra($this, $s, [1 => 50000], '2026-02-10')->assertCreated();
    $this->travelTo('2026-03-01 10:00:00');
    $r = $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $s['c']->id, 'accordo' => AccordoRientro::sole()->id]),
        ['chiuso_il' => '2026-03-01'])->assertOk();
    expect($r->json('messaggio'))->toBe('Accordo chiuso: da oggi le sue rate tornano nelle lettere proposte.')
        ->and(argAnteprima($this, $s)['persone'][0]['totale'])->toBe(50000);

    argRegistra($this, $s, [1 => 50000], '2026-03-01')->assertCreated();
    $this->travelTo('2026-03-10 10:00:00');
    $r = $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $s['c']->id, 'accordo' => AccordoRientro::latest('id')->first()->id]),
        ['chiuso_il' => '2026-03-04'])->assertOk();
    expect($r->json('messaggio'))->toBe('Accordo chiuso: dal 04/03/2026 le sue rate tornano nelle lettere proposte.');
});

it('alle 00:30 di Roma del 01/03 la chiusura del 01/03 è «da oggi», e le rate sono già nelle lettere di quel giorno', function () {
    $s = argGiulia($this);
    $this->travelTo('2026-02-10 10:00:00');
    argRegistra($this, $s, [1 => 50000], '2026-02-10')->assertCreated();
    $this->travelTo(Carbon::parse('2026-03-01 00:30', 'Europe/Rome'));
    $r = $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $s['c']->id, 'accordo' => AccordoRientro::sole()->id]),
        ['chiuso_il' => '2026-03-01'])->assertOk();
    expect($r->json('messaggio'))->toBe('Accordo chiuso: da oggi le sue rate tornano nelle lettere proposte.')
        ->and(argAnteprima($this, $s)['persone'][0]['totale'])->toBe(50000);
});

it('i versati oltre il capitale di un accordo chiuso si fermano alla chiusura: con un accordo nuovo sulla stessa rata non si contano due volte', function () {
    $s = argGiulia($this);
    $this->travelTo('2026-02-10 10:00:00');
    argRegistra($this, $s, [1 => 50000], '2026-02-10')->assertCreated();
    $this->travelTo('2026-03-01 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $s['c']->id, 'accordo' => AccordoRientro::sole()->id]),
        ['chiuso_il' => '2026-03-01'])->assertOk();
    argRegistra($this, $s, [1 => 50000], '2026-03-01')->assertCreated();
    $this->travelTo('2026-03-10 10:00:00');
    lcdIncassa($this, $s, 'giulia', [lcdQuota($s, 'giulia', 1) => 50000], '2026-03-10', 1767);
    $this->travelTo('2026-03-11 10:00:00');
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone']['giulia']]))->assertOk()->viewData('page')['props'];
    expect($p['stats']['saldo_raw'])->toBe(-1767)
        ->and(collect($p['accordi_rientro'])->map(fn ($a) => [$a['data_accordo'], $a['chiuso_il'], $a['versati_oltre']])->all())
        ->toBe([['2026-03-01', null, 1767], ['2026-02-10', '2026-03-01', 0]]);
});

it('un versamento del giorno prima della chiusura conta per l\'accordo chiuso', function () {
    $s = argGiulia($this);
    $this->travelTo('2026-02-10 10:00:00');
    argRegistra($this, $s, [1 => 50000], '2026-02-10')->assertCreated();
    $this->travelTo('2026-02-28 10:00:00');
    lcdIncassa($this, $s, 'giulia', [lcdQuota($s, 'giulia', 1) => 50000], '2026-02-28', 1767);
    $this->travelTo('2026-03-01 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $s['c']->id, 'accordo' => AccordoRientro::sole()->id]),
        ['chiuso_il' => '2026-03-01'])->assertOk();
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone']['giulia']]))->assertOk()->viewData('page')['props'];
    expect($p['accordi_rientro'][0]['versati_oltre'])->toBe(1767);
});

it('la persona con un accordo di rientro non si cancella, anche tolta dal condominio: la frase dice perché', function () {
    $s = argGiulia($this);
    Permission::firstOrCreate(['name' => \App\Enums\Permission::DELETE_ANAGRAFICHE->value, 'guard_name' => 'web']);
    $this->user->givePermissionTo(\App\Enums\Permission::DELETE_ANAGRAFICHE->value);
    $this->travelTo('2026-02-10 10:00:00');
    argRegistra($this, $s, [1 => 50000], '2026-02-10')->assertCreated();
    $giulia = $s['persone']['giulia'];
    $giulia->condomini()->detach();

    $r = $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $giulia));
    $messaggio = session('message');
    expect(Anagrafica::find($giulia->id))->not->toBeNull()
        ->and(AccordoRientro::count())->toBe(1)
        ->and($messaggio['type'] ?? null)->toBe('error')
        ->and($messaggio['message'] ?? '')->toBe(\App\Http\Controllers\Anagrafiche\AnagraficaController::CON_UN_ACCORDO)
        // Senza la scansione la frase non dice «firmato» né «scansione»: il programma non lo sa.
        ->and(\App\Http\Controllers\Anagrafiche\AnagraficaController::CON_UN_ACCORDO)->not->toContain('firmato')->not->toContain('scansione');
    $r->assertRedirect('/anagrafiche');

    // Con la scansione caricata, la frase la nomina.
    $accordo = AccordoRientro::sole();
    $accordo->documenti()->create(['name' => 'accordo.pdf', 'path' => 'documenti/accordo.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
        'created_by' => $this->user->id, 'is_published' => false, 'is_approved' => true]);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $giulia));
    expect(session('message')['message'] ?? '')->toBe(\App\Http\Controllers\Anagrafiche\AnagraficaController::CON_UN_ACCORDO_CON_LA_SCANSIONE)
        ->and(Anagrafica::find($giulia->id))->not->toBeNull();
});

it('il ricalcolo del piano rigenera le rate e rimette nell\'accordo la rata nuova con lo stesso numero, con lo stesso importo riconosciuto', function () {
    $s = argGiulia($this);
    $this->travelTo('2026-02-10 10:00:00');
    argRegistra($this, $s, [1 => 20000], '2026-02-10')->assertCreated();
    $vecchia = lcdRata($s, 1);

    app(RigeneraRateTenendoILegami::class)->esegui($s['piano'], fn () => app(GeneratePianoRateAction::class)->execute(pianoRate: $s['piano'],
        forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']));

    $nuova = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 1)->value('id');
    expect($nuova)->not->toBe($vecchia)
        ->and(DB::table('accordo_rientro_rate')->get(['rata_id', 'importo_riconosciuto'])->map(fn ($r) => [(int) $r->rata_id, (int) $r->importo_riconosciuto])->all())
        ->toBe([[$nuova, 20000]]);
});

it('nel registro della persona il residuo di oggi toglie la parte in accordo solo finché l\'accordo è in corso', function () {
    $s = argGiulia($this);
    $this->travelTo('2026-02-10 10:00:00');
    argRegistra($this, $s, [1 => 20000], '2026-02-10')->assertCreated();
    $this->actingAs($this->user)->post(route('admin.gestionale.lettere.stampa', ['condominio' => $s['c']->id]), [
        'tipo' => 'sollecito', 'persone' => [$s['persone']['giulia']->id], 'testo' => 'Gentile [nome], [totale].', 'termine' => 15,
    ])->assertOk();
    $registro = fn () => app(RegistroLettere::class)->perPersona($s['c'], $s['persone']['giulia'])[0];
    // La lettera chiedeva € 300,00, la parte fuori dall'accordo; oggi sulle stesse rate fuori dall'accordo resta lo stesso.
    expect($registro())->toMatchArray(['totale' => 30000, 'residuo_oggi' => 30000]);

    $this->travelTo('2026-02-20 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $s['c']->id, 'accordo' => AccordoRientro::sole()->id]),
        ['chiuso_il' => '2026-02-20'])->assertOk();
    // Chiuso l'accordo, sulle stesse rate oggi la persona deve € 500,00.
    expect($registro()['residuo_oggi'])->toBe(50000);
});

/** Giulia con le quattro rate trimestrali da € 750,00; la 3 e la 4 si emettono quando nascono. */
function acgGiulia($test): array
{
    $test->travelTo('2026-01-05 10:00:00');
    $s = lcdScenario(['7' => ['giulia', 1000]], 4, 'prima_rata', [], 300000, '2026-01-31', 31);
    foreach ([1 => '2026-01-31', 2 => '2026-04-30', 3 => '2026-07-31', 4 => '2026-10-31'] as $n => $data) {
        DB::table('rate')->where('id', lcdRata($s, $n))->update(['data_scadenza' => $data]);
        DB::table('rate_quote')->where('rata_id', lcdRata($s, $n))->update(['data_scadenza' => $data]);
    }
    $test->travelTo('2026-01-10 10:00:00');
    lcdEmetti($test, $s, [1, 2], '2026-01-10');
    $test->travelTo('2026-07-01 10:00:00');
    lcdEmetti($test, $s, [3], '2026-07-01');
    $test->travelTo('2026-10-01 10:00:00');
    lcdEmetti($test, $s, [4], '2026-10-01');

    return $s;
}

/** Un versamento di Giulia dalla rotta della pagina, con la dichiarazione dell'imputazione se serve. */
function acgIncassa($test, array $s, array $righe, string $data, int $parteInPiu = 0, ?string $scelta = null): void
{
    $test->travelTo($data . ' 11:00:00');
    $dati = [
        'pagante_id' => $s['persone']['giulia']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data,
        'importo_totale' => number_format((array_sum($righe) + $parteInPiu) / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => number_format($parteInPiu / 100, 2, '.', ''), 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => array_map(fn ($id, $cent) => ['rata_id' => (int) $id, 'importo' => number_format($cent / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
    ];
    if ($scelta !== null) {
        $dati['imputazione_scelta'] = $scelta;
        $dati['imputazione_nota'] = 'Rata fuori dall\'accordo';
    }
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), $dati)->assertSessionHasNoErrors();
}
