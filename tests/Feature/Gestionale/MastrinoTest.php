<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use App\Services\Gestionale\RegistroContabilitaService;
use App\Services\Gestionale\StatoPatrimonialeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * D21 — il mastrino di un conto: seconda proiezione della query di D10.
 *
 * ⚠️ **Valori attesi calcolati a mano nei commenti, mai letti dal servizio.** Lo scenario è
 * costruito per far divergere ciò che il mastrino deve distinguere: il riporto da un esercizio
 * precedente, una riga di un altro esercizio dentro il periodo, una postdatata, un'apertura di
 * cassa mai passata a giornale, un conto passivo che va contro natura.
 */
function mConto(int $condominioId, string $codice, string $nome, string $tipo, string $categoria, ?string $ruolo = null): int
{
    return DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominioId, 'ruolo' => $ruolo, 'codice' => $codice, 'nome' => $nome,
        'tipo' => $tipo, 'categoria' => $categoria, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function mScrittura(array $ctx, Esercizio $esercizio, string $data, int $contoDare, int $contoAvere, int $importo, string $causale, string $tipo = 'regolazione_immediata', ?int $cassaDare = null, ?int $cassaAvere = null): ScritturaContabile
{
    $sc = ScritturaContabile::create([
        'condominio_id' => $ctx['condominio']->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $ctx['gestione']->id,
        'data_registrazione' => $data, 'data_competenza' => $data, 'causale' => $causale,
        'tipo_movimento' => $tipo, 'stato' => 'registrata',
    ]);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $contoDare, 'cassa_id' => $cassaDare, 'tipo_riga' => 'dare', 'importo' => $importo]);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $contoAvere, 'cassa_id' => $cassaAvere, 'tipo_riga' => 'avere', 'importo' => $importo]);

    return $sc;
}

/**
 * Due esercizi. Il 2025 è chiuso; il «corrente» è aperto e comincia il primo del mese scorso,
 * così «oggi» cade dentro e le date relative a oggi (postdatata) hanno senso su ogni macchina.
 *
 *   2025-01-01  apertura banca       DARE banca 1.000  / AVERE passate 1.000     (es. 2025)
 *   2025-06-01  fattura A            DARE costi 400    / AVERE fornitori 400     (es. 2025)
 *   2025-07-01  pagamento A          DARE fornitori 100 / AVERE banca 100        (es. 2025)
 *   inizio+5    incasso              DARE banca 500    / AVERE crediti 500       (corrente)
 *   inizio+10   fattura B            DARE costi 250    / AVERE fornitori 250     (corrente)
 *   inizio+12   pagamento B          DARE fornitori 700 / AVERE banca 700        (corrente) → fornitori contro natura
 *   inizio+15   scrittura del 2025 datata qui: DARE banca 30 / AVERE crediti 30  (es. 2025, «fuori periodo»)
 *   oggi+3      incasso postdatato   DARE banca 60     / AVERE crediti 60        (corrente)
 *
 * Banca (attivo, dare − avere): riporto al giorno prima = 1.000 − 100 = 900.
 *   +500 → 1.400; −700 → 700; +30 → 730. Postdatata fuori. Saldo finale 730.
 * Fornitori (passivo, avere − dare): riporto = 400 − 100 = 300. +250 → 550; −700 → −150.
 */
function mScenario(): array
{
    $condominio = Condominio::factory()->create();
    $inizio = now()->subMonthNoOverflow()->startOfMonth();
    $es2025 = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso', 'nome' => 'Esercizio 2025']);
    $corrente = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'data_inizio' => $inizio->format('Y-m-d'), 'data_fine' => $inizio->copy()->addYear()->subDay()->format('Y-m-d'), 'stato' => 'aperto', 'nome' => 'Corrente']);
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);
    $ctx = ['condominio' => $condominio, 'gestione' => $gestione];

    $c = [
        'banca'     => mConto($condominio->id, '1010.01', 'Banca Prova', 'attivo', 'liquidita', 'conto_bancario'),
        'crediti'   => mConto($condominio->id, '1101', 'Crediti verso Condomini', 'attivo', 'crediti', 'crediti_condomini'),
        'fornitori' => mConto($condominio->id, '2201', 'Debiti v/Fornitori', 'passivo', 'debiti', 'debiti_fornitori'),
        'passate'   => mConto($condominio->id, '2301', 'Fondo Passate Gestioni', 'passivo', 'fondi', 'passate_gestioni'),
        'costi'     => mConto($condominio->id, '6001', 'Costi per Servizi', 'costo', 'costi', 'costi_servizi'),
    ];
    $banca = Cassa::create(['condominio_id' => $condominio->id, 'nome' => 'Banca Prova', 'tipo' => 'banca', 'conto_contabile_id' => $c['banca'], 'saldo_iniziale' => 0, 'attiva' => true]);

    $d = fn (int $giorni) => $inizio->copy()->addDays($giorni)->format('Y-m-d');

    mScrittura($ctx, $es2025, '2025-01-01', $c['banca'], $c['passate'], 100000, 'Apertura banca', 'apertura', $banca->id);
    mScrittura($ctx, $es2025, '2025-06-01', $c['costi'], $c['fornitori'], 40000, 'Fattura A', 'fattura_acquisto');
    mScrittura($ctx, $es2025, '2025-07-01', $c['fornitori'], $c['banca'], 10000, 'Pagamento A', 'pagamento_fornitore', null, $banca->id);
    mScrittura($ctx, $corrente, $d(5), $c['banca'], $c['crediti'], 50000, 'Incasso rate', 'incasso_rata', $banca->id);
    mScrittura($ctx, $corrente, $d(10), $c['costi'], $c['fornitori'], 25000, 'Fattura B', 'fattura_acquisto');
    mScrittura($ctx, $corrente, $d(12), $c['fornitori'], $c['banca'], 70000, 'Pagamento B', 'pagamento_fornitore', null, $banca->id);
    mScrittura($ctx, $es2025, $d(15), $c['banca'], $c['crediti'], 3000, 'Incasso datato male', 'incasso_rata', $banca->id);
    mScrittura($ctx, $corrente, now()->addDays(3)->format('Y-m-d'), $c['banca'], $c['crediti'], 6000, 'Incasso postdatato', 'incasso_rata', $banca->id);

    return [$condominio, $es2025, $corrente, $c, $banca, $inizio];
}

function mastrinoDi(Esercizio $esercizio, int $contoId, array $filtri = []): array
{
    return app(RegistroContabilitaService::class)->mastrino($esercizio, ContoContabile::findOrFail($contoId), $filtri);
}

test('il saldo parte dal riporto e arriva alla fotografia, righe di altri esercizi comprese', function () {
    [, , $corrente, $c] = mScenario();

    $m = mastrinoDi($corrente, $c['banca']);

    expect($m['riporto'])->toBe(90000)
        ->and($m['periodo']['stato'])->toBe('aperto')
        ->and(array_column($m['righe'], 'descrizione'))->toBe(['Incasso rate', 'Pagamento B', 'Incasso datato male'])
        ->and(array_column($m['righe'], 'saldo_progressivo'))->toBe([140000, 70000, 73000])
        ->and(array_column($m['righe'], 'numero'))->toBe([1, 2, 3])
        ->and($m['saldo_finale'])->toBe(73000)
        ->and($m['totale_dare'])->toBe(53000)
        ->and($m['totale_avere'])->toBe(70000)
        ->and($m['postdatate'])->toBe(1)
        ->and($m['altri_esercizi'])->toBe(1)
        ->and($m['righe'][2]['altro_esercizio'])->toBe('Esercizio 2025')
        ->and($m['righe'][0]['altro_esercizio'])->toBeNull();
});

test('il saldo finale del mastrino è il saldo del conto nella fotografia dello Stato patrimoniale', function () {
    [$condominio, , $corrente, $c] = mScenario();

    $m = mastrinoDi($corrente, $c['banca']);
    $foto = app(StatoPatrimonialeService::class)->calcola($condominio, null, $m['periodo']['al']);
    $banca = collect($foto['attivo']['voci'])->firstWhere('id', $c['banca']);

    // Calcolato a mano: 1.000 − 100 + 500 − 700 + 30 = 730; la postdatata è oltre `al` per entrambi.
    expect($banca['saldo'])->toBe(73000)
        ->and($m['saldo_finale'])->toBe($banca['saldo']);
});

test('un conto passivo ha il saldo nel suo verso, e sotto zero è contro natura', function () {
    [$condominio, , $corrente, $c] = mScenario();

    $m = mastrinoDi($corrente, $c['fornitori']);

    // avere − dare: riporto 400 − 100 = 300; fattura B +250 → 550; pagamento B −700 → −150.
    expect($m['conto']['natura_dare'])->toBeFalse()
        ->and($m['riporto'])->toBe(30000)
        ->and(array_column($m['righe'], 'saldo_progressivo'))->toBe([55000, -15000])
        ->and($m['saldo_finale'])->toBe(-15000);

    $foto = app(StatoPatrimonialeService::class)->calcola($condominio, null, $m['periodo']['al']);
    $fornitori = collect($foto['passivo']['voci'])->firstWhere('id', $c['fornitori']);
    expect($fornitori['saldo'])->toBe(-15000);
});

test('un esercizio chiuso taglia alla sua data di fine, e non dichiara postdatate', function () {
    [, $es2025, , $c] = mScenario();

    $m = mastrinoDi($es2025, $c['banca']);

    // Nel 2025: riporto 0; apertura +1.000; pagamento A −100 → 900. La riga «datata male» sta
    // nel periodo dell'esercizio corrente, non qui; tutto ciò che è dopo il 31/12/2025 è
    // l'esercizio dopo, non una postdatata.
    expect($m['periodo'])->toBe(['dal' => '2025-01-01', 'al' => '2025-12-31', 'stato' => 'chiuso'])
        ->and($m['riporto'])->toBe(0)
        ->and(array_column($m['righe'], 'descrizione'))->toBe(['Apertura banca', 'Pagamento A'])
        ->and($m['saldo_finale'])->toBe(90000)
        ->and($m['postdatate'])->toBe(0)
        ->and($m['altri_esercizi'])->toBe(0);
});

test('filtrando, i totali sono parziali ma il saldo alla data resta quello vero', function () {
    [, , $corrente, $c, , $inizio] = mScenario();

    // Solo il pagamento B (giorno 12): totale avere 700, dare 0; il saldo a quella data è 700,
    // non −700 — porta dentro riporto e incasso.
    $m = mastrinoDi($corrente, $c['banca'], [
        'data_da' => $inizio->copy()->addDays(11)->format('Y-m-d'),
        'data_a' => $inizio->copy()->addDays(13)->format('Y-m-d'),
    ]);

    expect(array_column($m['righe'], 'descrizione'))->toBe(['Pagamento B'])
        ->and($m['totale_dare'])->toBe(0)
        ->and($m['totale_avere'])->toBe(70000)
        ->and($m['saldo_alla_data'])->toBe(70000)
        ->and($m['righe'][0]['numero'])->toBe(2)
        ->and($m['riporto'])->toBe(90000)
        ->and($m['saldo_finale'])->toBe(73000);

    // Ricerca sulla controparte/descrizione, stesso criterio del registro.
    $r = mastrinoDi($corrente, $c['banca'], ['search' => 'datato']);
    expect(array_column($r['righe'], 'descrizione'))->toBe(['Incasso datato male']);
});

test("un saldo di apertura di cassa non ancora a giornale si dichiara e non si somma", function () {
    [, , $corrente, $c, $banca] = mScenario();
    $banca->update(['saldo_iniziale' => 12345]);

    $m = mastrinoDi($corrente, $c['banca']);

    expect($m['apertura_non_registrata'])->toBe(12345)
        ->and($m['saldo_finale'])->toBe(73000);
});

test('la riga stornata resta con la marca, e lo storno è una riga come le altre', function () {
    [$condominio, , $corrente, $c, $banca, $inizio] = mScenario();
    $ctx = ['condominio' => $condominio, 'gestione' => Gestione::where('condominio_id', $condominio->id)->first()];
    $originale = mScrittura($ctx, $corrente, $inizio->copy()->addDays(20)->format('Y-m-d'), $c['banca'], $c['crediti'], 8000, 'Incasso da stornare', 'incasso_rata', $banca->id);
    $originale->update(['stato' => 'annullata']);
    $storno = mScrittura($ctx, $corrente, $inizio->copy()->addDays(21)->format('Y-m-d'), $c['crediti'], $c['banca'], 8000, 'Storno: Incasso da stornare', 'rettifica', null, $banca->id);
    $storno->update(['scrittura_padre_id' => $originale->id]);

    $m = mastrinoDi($corrente, $c['banca']);
    $righe = collect($m['righe'])->keyBy('descrizione');

    expect($righe['Incasso da stornare']['stornata'])->toBeTrue()
        ->and($righe['Storno: Incasso da stornare']['stornata'])->toBeFalse()
        ->and($righe['Incasso rate']['stornata'])->toBeFalse()
        // +80 −80: il saldo finale non cambia.
        ->and($m['saldo_finale'])->toBe(73000);
});

test('le righe sorelle sono la contropartita, e il selettore elenca solo le foglie con il conteggio', function () {
    [$condominio, , $corrente, $c] = mScenario();
    $servizio = app(RegistroContabilitaService::class);

    $m = $servizio->mastrino($corrente, ContoContabile::findOrFail($c['banca']));
    $sorelle = $servizio->sorelle([$m['righe'][0]['scrittura_id']]);
    $altre = array_values(array_filter($sorelle[$m['righe'][0]['scrittura_id']], fn ($s) => $s['id'] !== $m['righe'][0]['id']));

    expect($altre)->toHaveCount(1)
        ->and($altre[0]['codice'])->toBe('1101')
        ->and($altre[0]['avere'])->toBe(50000)
        ->and($altre[0]['dare'])->toBeNull();

    // Un mastro con un figlio non compare; la banca ha 3 righe nel periodo (la postdatata no).
    $mastro = mConto($condominio->id, '1000', 'ATTIVO', 'attivo', 'liquidita');
    DB::table('conti_contabili')->where('id', $c['banca'])->update(['parent_id' => $mastro]);

    $selettore = collect($servizio->contiPerSelettore($corrente))->keyBy('codice');
    expect($selettore->has('1000'))->toBeFalse()
        ->and($selettore['1010.01']['righe'])->toBe(3)
        ->and($selettore['2201']['righe'])->toBe(2)
        ->and($selettore['2301']['righe'])->toBe(0);
});

test('la pagina rende il mastrino e la stampa scarica con il nome del conto', function () {
    [$condominio, , $corrente, $c] = mScenario();
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    $this->actingAs($user)
        ->get("/admin/gestionale/{$condominio->id}/esercizi/{$corrente->id}/conti/{$c['banca']}/movimenti")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('gestionale/movimenti/mastrino/Show')
            ->where('conto.codice', '1010.01')
            ->where('riepilogo.riporto', 90000)
            ->where('riepilogo.saldo_finale', 73000)
            ->where('riepilogo.altri_esercizi', 1)
            ->where('riepilogo.postdatate', 1)
            ->where('righe.meta.total', 3)
            ->has('righe.data.0.sorelle', 1)
            ->has('conti', 5)
        );

    $risposta = $this->actingAs($user)
        ->get("/admin/gestionale/{$condominio->id}/esercizi/{$corrente->id}/conti/{$c['banca']}/movimenti/print");
    $risposta->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($risposta->headers->get('Content-Disposition'))->toContain('mastrino-1010.01-');
});

test('il conto di un altro condominio non si apre da qui: 404', function () {
    [$condominio, , $corrente] = mScenario();
    $altro = Condominio::factory()->create();
    $contoAltrui = mConto($altro->id, '1010.01', 'Banca altrui', 'attivo', 'liquidita');
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    $this->actingAs($user)
        ->get("/admin/gestionale/{$condominio->id}/esercizi/{$corrente->id}/conti/{$contoAltrui}/movimenti")
        ->assertNotFound();
});

// ─── Revisione della beta.26: i reperti confermati, ognuno con il suo test ───────────────────

function mAdmin(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

test("«oggi» è quello dell'utente, lo stesso della pagina Stato patrimoniale, anche di notte", function () {
    [$condominio, , $corrente, $c, $banca] = mScenario();
    $ctx = ['condominio' => $condominio, 'gestione' => Gestione::where('condominio_id', $condominio->id)->first()];

    // 22:30 UTC = 00:30 in Italia (ora legale): per la pagina è già domani. Un incasso datato
    // «oggi utente» deve stare nel mastrino, non fra le postdatate.
    \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-07-20 22:30:00', 'UTC'));
    $oggiUtente = \App\Helpers\DateHelper::oggiUtente();
    expect($oggiUtente)->toBe('2026-07-21');
    // Il periodo dell'esercizio «corrente» dello scenario è costruito su now(): riallineo le date.
    $corrente->update(['data_inizio' => '2026-07-01', 'data_fine' => '2027-06-30']);
    mScrittura($ctx, $corrente, $oggiUtente, $c['banca'], $c['crediti'], 9900, 'Incasso di notte', 'incasso_rata', $banca->id);

    $m = mastrinoDi($corrente->fresh(), $c['banca']);
    $pagina = app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class)->costruisci($condominio, $corrente->fresh());

    expect($m['periodo']['al'])->toBe('2026-07-21')
        ->and($m['periodo']['al'])->toBe($pagina['data'])
        ->and(collect($m['righe'])->pluck('descrizione'))->toContain('Incasso di notte');

    \Carbon\Carbon::setTestNow();
});

test('un esercizio non ancora cominciato: il riporto è la fotografia a oggi e lo dice', function () {
    [$condominio, , , $c, $banca] = mScenario();
    $ctx = ['condominio' => $condominio, 'gestione' => Gestione::where('condominio_id', $condominio->id)->first()];
    $inizio = now()->addMonths(2)->startOfMonth();
    $futuro = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'data_inizio' => $inizio->format('Y-m-d'), 'data_fine' => $inizio->copy()->addYear()->subDay()->format('Y-m-d'), 'stato' => 'aperto', 'nome' => 'Futuro']);
    // Una riga fra oggi e l'inizio: non è riporto (che si ferma a oggi) né riga del periodo.
    mScrittura($ctx, $futuro, now()->addDays(10)->format('Y-m-d'), $c['banca'], $c['crediti'], 100, 'Nel limbo', 'incasso_rata', $banca->id);

    $m = mastrinoDi($futuro, $c['banca']);

    // Riporto = fotografia a oggi = 900 + 500 − 700 + 30 = 730 (la postdatata di oggi+3 e quella del
    // limbo restano fuori); righe nessuna; le due oltre oggi sono contate.
    expect($m['periodo']['stato'])->toBe('futuro')
        ->and($m['riporto_al'])->toBe($m['periodo']['al'])
        ->and($m['riporto'])->toBe(73000)
        ->and($m['righe'])->toBe([])
        ->and($m['saldo_finale'])->toBe(73000)
        ->and($m['postdatate'])->toBe(2);
});

test('una data di filtro fuori dal periodo non produce un saldo inventato', function () {
    [$condominio, , $corrente, $c] = mScenario();

    // Nel servizio: data_a prima del periodo si ritaglia al giorno del riporto, dove il riporto È
    // il saldo (900 = 1.000 − 100 alla fine del 2025).
    $m = mastrinoDi($corrente, $c['banca'], ['data_a' => '2025-12-01']);
    expect($m['righe'])->toBe([])
        ->and($m['data_riferimento'])->toBe($m['riporto_al'])
        ->and($m['saldo_alla_data'])->toBe(90000);

    // Nel controller: un filtro tutto fuori dal periodo si scarta, e la pagina lo rimanda assente.
    $this->actingAs(mAdmin())
        ->get("/admin/gestionale/{$condominio->id}/esercizi/{$corrente->id}/conti/{$c['banca']}/movimenti?data_a=2025-12-01")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters', [])
            ->where('righe.meta.total', 3));
});

test('i quattro confini di data mordono anche quando la data è salvata come in MySQL', function () {
    [$condominio, $es2025, $corrente, $c, $banca, $inizio] = mScenario();
    $ctx = ['condominio' => $condominio, 'gestione' => Gestione::where('condominio_id', $condominio->id)->first()];
    $oggi = \App\Helpers\DateHelper::oggiUtente();
    $casi = [
        ['giorno prima', $es2025, $inizio->copy()->subDay()->format('Y-m-d'), 100],
        ['primo giorno', $corrente, $inizio->format('Y-m-d'), 200],
        ['oggi', $corrente, $oggi, 400],
        ['domani', $corrente, \Carbon\Carbon::parse($oggi)->addDay()->format('Y-m-d'), 800],
    ];
    foreach ($casi as [$nome, $es, $data, $importo]) {
        $sc = mScrittura($ctx, $es, $data, $c['banca'], $c['crediti'], $importo, "Confine: {$nome}", 'incasso_rata', $banca->id);
        // ⚠️ Su SQLite Eloquent salva 'Y-m-d 00:00:00' e contro un letterale 'Y-m-d' il confronto
        // `<` vale `<=`: riscritta come la memorizza MySQL (DATE), così i confini si provano davvero.
        DB::table('scritture_contabili')->where('id', $sc->id)->update(['data_competenza' => $data]);
    }

    $m = mastrinoDi($corrente, $c['banca']);
    $nomi = collect($m['righe'])->pluck('descrizione');

    // Riporto 900 + 1,00 (giorno prima); righe: primo giorno e oggi dentro; domani fuori (postdatata).
    expect($m['riporto'])->toBe(90100)
        ->and($nomi)->toContain('Confine: primo giorno')
        ->and($nomi)->toContain('Confine: oggi')
        ->and($nomi)->not->toContain('Confine: giorno prima')
        ->and($nomi)->not->toContain('Confine: domani')
        ->and($m['postdatate'])->toBe(2);
});

test('una fattura stornata esce marcata anche sul mastrino dei fornitori', function () {
    [$condominio, , $corrente, $c, , $inizio] = mScenario();
    $fornitoreId = DB::table('fornitori')->insertGetId(['ragione_sociale' => 'Ditta Stornata s.r.l.', 'soggetto_ritenuta' => false, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now()]);
    $fatturaId = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $condominio->id, 'esercizio_id' => $corrente->id, 'fornitore_id' => $fornitoreId,
        'tipo_documento' => 'fattura', 'numero_documento' => 'S1', 'data_documento' => now(), 'data_scadenza' => now(),
        'importo_imponibile' => 10000, 'importo_iva' => 0, 'netto_a_pagare' => 10000, 'totale_documento' => 10000,
        'stato_approvazione' => 'approvata', 'stato_pagamento' => 'stornata', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $ctx = ['condominio' => $condominio, 'gestione' => Gestione::where('condominio_id', $condominio->id)->first()];
    $sc = mScrittura($ctx, $corrente, $inizio->copy()->addDays(20)->format('Y-m-d'), $c['costi'], $c['fornitori'], 10000, 'Fattura S1', 'fattura_acquisto');
    DB::table('fattura_scrittura')->insert(['fattura_passiva_id' => $fatturaId, 'scrittura_contabile_id' => $sc->id, 'importo_allocato' => 10000, 'tipo' => 'competenza', 'created_at' => now(), 'updated_at' => now()]);

    $righe = collect(mastrinoDi($corrente, $c['fornitori'])['righe'])->keyBy('descrizione');

    expect($righe['Fattura S1']['stornata'])->toBeTrue()
        ->and($righe['Fattura S1']['controparte'])->toBe('Ditta Stornata s.r.l.')
        ->and($righe['Fattura B']['stornata'])->toBeFalse();
});

test('sul mastrino dei crediti ogni riga porta il suo condòmino, non quello della riga accanto', function () {
    [$condominio, , $corrente, $c, , $inizio] = mScenario();
    $ctx = ['condominio' => $condominio, 'gestione' => Gestione::where('condominio_id', $condominio->id)->first()];
    $rossi = \App\Models\Anagrafica::factory()->create(['nome' => 'Rossi Anna']);
    $verdi = \App\Models\Anagrafica::factory()->create(['nome' => 'Verdi Bruno']);

    // Un'emissione con due righe di credito (una per condòmino) e una sola in avere.
    $sc = ScritturaContabile::create([
        'condominio_id' => $condominio->id, 'esercizio_id' => $corrente->id, 'gestione_id' => $ctx['gestione']->id,
        'data_registrazione' => $inizio->copy()->addDays(2)->format('Y-m-d'), 'data_competenza' => $inizio->copy()->addDays(2)->format('Y-m-d'),
        'causale' => 'Emissione rata', 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata',
    ]);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $c['crediti'], 'anagrafica_id' => $rossi->id, 'tipo_riga' => 'dare', 'importo' => 30000]);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $c['crediti'], 'anagrafica_id' => $verdi->id, 'tipo_riga' => 'dare', 'importo' => 20000]);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $c['passate'], 'tipo_riga' => 'avere', 'importo' => 50000]);

    $righe = collect(mastrinoDi($corrente, $c['crediti'])['righe'])->where('descrizione', 'Emissione rata')->values();

    expect($righe->pluck('controparte')->all())->toBe(['Rossi Anna', 'Verdi Bruno'])
        ->and($righe->pluck('dare')->all())->toBe([30000, 20000]);
});

test('nel pannello le righe sorelle escono dare prima di avere, e un mastro non si apre', function () {
    [$condominio, , $corrente, $c] = mScenario();
    $servizio = app(RegistroContabilitaService::class);

    // Il pagamento B: DARE fornitori, AVERE banca — sul mastrino della banca la sorella è il dare.
    $m = $servizio->mastrino($corrente, ContoContabile::findOrFail($c['fornitori']));
    $pagamento = collect($m['righe'])->firstWhere('descrizione', 'Pagamento B');
    $sorelle = $servizio->sorelle([$pagamento['scrittura_id']])[$pagamento['scrittura_id']];
    expect($sorelle[0]['dare'])->toBe(70000)
        ->and($sorelle[1]['avere'])->toBe(70000);

    $mastro = mConto($condominio->id, '1000', 'ATTIVO', 'attivo', 'liquidita');
    DB::table('conti_contabili')->where('id', $c['banca'])->update(['parent_id' => $mastro]);

    $this->actingAs(mAdmin())
        ->get("/admin/gestionale/{$condominio->id}/esercizi/{$corrente->id}/conti/{$mastro}/movimenti")
        ->assertNotFound();
});

test("le righe di questo esercizio datate prima del suo inizio stanno nel riporto, e si contano", function () {
    [$condominio, , $corrente, $c, $banca, $inizio] = mScenario();
    $ctx = ['condominio' => $condominio, 'gestione' => Gestione::where('condominio_id', $condominio->id)->first()];
    $sc = mScrittura($ctx, $corrente, $inizio->copy()->subDays(3)->format('Y-m-d'), $c['banca'], $c['crediti'], 1100, 'Pregressa mia', 'incasso_rata', $banca->id);
    DB::table('scritture_contabili')->where('id', $sc->id)->update(['data_competenza' => $inizio->copy()->subDays(3)->format('Y-m-d')]);

    $m = mastrinoDi($corrente, $c['banca']);

    expect($m['riporto'])->toBe(91100)
        ->and($m['riporto_proprie'])->toBe(1)
        ->and(collect($m['righe'])->pluck('descrizione'))->not->toContain('Pregressa mia');
});

test('il libro mastro stampa tutti i conti movimentati in un foglio, e dice da dove si torna', function () {
    [$condominio, , $corrente, $c] = mScenario();
    $servizio = app(RegistroContabilitaService::class);

    $libro = $servizio->libroMastro($corrente);
    $codici = array_column(array_column($libro['mastrini'], 'conto'), 'codice');

    // A mano: banca 3 (incasso, pagamento B, datato male), crediti 2 (incasso, datato male — la
    // postdatata è fuori), fornitori 2 (fattura B, pagamento B), passate 0 (solo il riporto di
    // 1.000 dal 2025, e ci sta lo stesso), costi 1 (fattura B). Nessun conto vuoto.
    expect($codici)->toBe(['1010.01', '1101', '2201', '2301', '6001'])
        ->and($libro['righe_totali'])->toBe(3 + 2 + 2 + 0 + 1)
        ->and(collect($libro['mastrini'])->firstWhere('conto.codice', '2301')['riporto'])->toBe(100000)
        ->and(collect($libro['mastrini'])->firstWhere('conto.codice', '1010.01')['saldo_finale'])->toBe(73000);

    $user = mAdmin();
    $risposta = $this->actingAs($user)->get("/admin/gestionale/{$condominio->id}/esercizi/{$corrente->id}/libro-mastro/print");
    $risposta->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($risposta->headers->get('Content-Disposition'))->toContain('libro-mastro-');

    // La provenienza viaggia con i filtri: da «casse» si torna alle casse.
    $this->actingAs($user)
        ->get("/admin/gestionale/{$condominio->id}/esercizi/{$corrente->id}/conti/{$c['banca']}/movimenti?da=casse&search=Pagamento")
        ->assertInertia(fn ($page) => $page->where('filters.da', 'casse')->where('filters.search', 'Pagamento')->where('righe.meta.total', 1));
    $this->actingAs($user)
        ->get("/admin/gestionale/{$condominio->id}/esercizi/{$corrente->id}/conti/{$c['banca']}/movimenti?da=altrove")
        ->assertInertia(fn ($page) => $page->where('filters', []));
});
