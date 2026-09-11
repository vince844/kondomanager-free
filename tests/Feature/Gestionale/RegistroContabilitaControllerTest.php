<?php

use App\Enums\TipoMovimentoContabile;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function adminRegistro(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $user = User::factory()->create();
    $user->assignRole($ruolo);

    return $user;
}

/** Condominio + esercizio + gestione + una cassa reale con un incasso registrato. */
function setupRegistroConUnIncasso(): array
{
    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);

    $contoCassa = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id,
        'codice' => 'CASSA-'.uniqid(),
        'nome' => 'Banca Test',
        'tipo' => 'attivo',
        'categoria' => 'liquidita',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $banca = Cassa::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Banca Test',
        'tipo' => 'banca',
        'conto_contabile_id' => $contoCassa,
        'saldo_iniziale' => 0,
        'attiva' => true,
    ]);

    $scrittura = ScritturaContabile::create([
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'data_registrazione' => now()->format('Y-m-d'),
        'data_competenza' => now()->format('Y-m-d'),
        'causale' => 'Incasso di test',
        'tipo_movimento' => TipoMovimentoContabile::INCASSO_RATA->value,
        'stato' => 'registrata',
    ]);
    RigaScrittura::create([
        'scrittura_id' => $scrittura->id,
        'conto_contabile_id' => $banca->conto_contabile_id,
        'cassa_id' => $banca->id,
        'tipo_riga' => 'dare',
        'importo' => 12345,
    ]);

    return [$condominio, $esercizio];
}

/**
 * ⚠️ **404, non 403** — stessa misura di `ScritturaContabileControllerTest`, stesso motivo:
 * `scopeBindings()` sul gruppo di rotte del gestionale (beta.66) rifiuta di risolvere un
 * `{esercizio}` non annidato sotto quel `{condominio}` prima che il controller giri.
 * L'`abort(403, ...)` in cima a `index()`/`stampa()` resta come difesa in profondità, non
 * raggiunta da questo scenario.
 */
test('un esercizio di un altro condominio non si risolve nemmeno per rotta', function () {
    $user = adminRegistro();
    [$condominio] = setupRegistroConUnIncasso();
    [, $esercizioDiAltri] = setupRegistroConUnIncasso();

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.registro-contabilita.index', [$condominio, $esercizioDiAltri]))
        ->assertNotFound();
});

test('index mostra il movimento con il riepilogo corretto', function () {
    $user = adminRegistro();
    [$condominio, $esercizio] = setupRegistroConUnIncasso();

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.registro-contabilita.index', [$condominio, $esercizio]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('riepilogo.totale_entrate', 12345)
            ->where('riepilogo.totale_uscite', 0)
            ->where('riepilogo.saldo_finale', 12345)
            ->has('righe.data', 1)
            ->where('righe.data.0.controparte', null)
        );
});

/**
 * ⚠️ **Il numero che manda l'amministratore in assemblea con una cifra falsa.** Con un filtro
 * attivo, «entrate − uscite» è il netto del periodo, non il saldo di cassa: qui il netto di
 * giugno vale −7.000, ma sul conto a fine giugno c'erano 3.000, perché i 10.000 di gennaio ci
 * sono comunque. La card deve dire il secondo e dichiarare a quale data si riferisce.
 */
test('con un filtro attivo il saldo è quello vero a quella data, non il netto del periodo', function () {
    $user = adminRegistro();
    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);

    $contoCassa = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id, 'codice' => 'CASSA-'.uniqid(), 'nome' => 'Banca Test',
        'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $banca = Cassa::create([
        'condominio_id' => $condominio->id, 'nome' => 'Banca Test', 'tipo' => 'banca',
        'conto_contabile_id' => $contoCassa, 'saldo_iniziale' => 0, 'attiva' => true,
    ]);

    foreach ([['2026-01-15', 'dare', 1000000], ['2026-06-20', 'avere', 700000]] as [$data, $verso, $importo]) {
        $scrittura = ScritturaContabile::create([
            'condominio_id' => $condominio->id,
            'esercizio_id' => $esercizio->id,
            'gestione_id' => $gestione->id,
            'data_registrazione' => $data,
            'data_competenza' => $data,
            'causale' => 'Movimento di prova',
            'tipo_movimento' => TipoMovimentoContabile::INCASSO_RATA->value,
            'stato' => 'registrata',
        ]);
        RigaScrittura::create([
            'scrittura_id' => $scrittura->id,
            'conto_contabile_id' => $banca->conto_contabile_id,
            'cassa_id' => $banca->id,
            'tipo_riga' => $verso,
            'importo' => $importo,
        ]);
    }

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.registro-contabilita.index', [
            $condominio, $esercizio, 'data_da' => '2026-06-01',
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Il periodo filtrato ha solo un'uscita: il netto sarebbe −700.000.
            ->where('riepilogo.totale_entrate', 0)
            ->where('riepilogo.totale_uscite', 700000)
            // Ma il saldo a quella data è 300.000, perché gennaio conta comunque.
            ->where('riepilogo.saldo_finale', 300000)
            ->where('riepilogo.saldo_alla_data', '2026-06-20')
            // E il movimento resta il n. 2 dell'esercizio, non il n. 1 del filtro.
            ->where('righe.data.0.numero', 2)
        );
});

/**
 * ⚠️ **Il totale può nascondere un conto scoperto.** Con due casse reali — banca a −370,56 e
 * contanti a +372,50 — la card direbbe «1,94»: vero, e inutile. La scomposizione per cassa esiste
 * per questo, e con una cassa sola non deve comparire, perché lì il totale È già il dettaglio.
 */
test('con due casse il riepilogo scompone il saldo, con una sola no', function () {
    $user = adminRegistro();
    $condominio = Condominio::factory()->create();
    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);

    $creaCassa = function (string $tipo, string $nome) use ($condominio) {
        $conto = DB::table('conti_contabili')->insertGetId([
            'condominio_id' => $condominio->id, 'codice' => strtoupper($tipo).'-'.uniqid(), 'nome' => $nome,
            'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Cassa::create([
            'condominio_id' => $condominio->id, 'nome' => $nome, 'tipo' => $tipo,
            'conto_contabile_id' => $conto, 'saldo_iniziale' => 0, 'attiva' => true,
        ]);
    };

    $banca = $creaCassa('banca', 'Banca Test');

    $movimento = function (Cassa $cassa, string $verso, int $importo, string $data) use ($condominio, $esercizio, $gestione) {
        $scrittura = ScritturaContabile::create([
            'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
            'data_registrazione' => $data, 'data_competenza' => $data, 'causale' => 'Movimento',
            'tipo_movimento' => TipoMovimentoContabile::INCASSO_RATA->value, 'stato' => 'registrata',
        ]);
        RigaScrittura::create([
            'scrittura_id' => $scrittura->id, 'conto_contabile_id' => $cassa->conto_contabile_id,
            'cassa_id' => $cassa->id, 'tipo_riga' => $verso, 'importo' => $importo,
        ]);
    };

    $movimento($banca, 'dare', 10000, '2026-02-01');

    // Con una cassa sola: nessuna scomposizione da mostrare.
    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.registro-contabilita.index', [$condominio, $esercizio]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('riepilogo.saldi_per_cassa', 1));

    // Arriva la seconda cassa: il totale resta 1.000 ma nasconde una banca scoperta.
    $contanti = $creaCassa('contanti', 'Cassa contanti');
    $movimento($banca, 'avere', 50000, '2026-03-01');
    $movimento($contanti, 'dare', 40000, '2026-03-02');

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.registro-contabilita.index', [$condominio, $esercizio]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('riepilogo.saldi_per_cassa', 2)
            ->where('riepilogo.saldo_finale', 0)
            ->where('riepilogo.saldi_per_cassa.0.cassa', 'Banca Test')
            ->where('riepilogo.saldi_per_cassa.0.saldo', -40000)
            ->where('riepilogo.saldi_per_cassa.1.cassa', 'Cassa contanti')
            ->where('riepilogo.saldi_per_cassa.1.saldo', 40000)
        );
});

test('stampa risponde con un PDF valido', function () {
    $user = adminRegistro();
    [$condominio, $esercizio] = setupRegistroConUnIncasso();

    $risposta = $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.registro-contabilita.print', [$condominio, $esercizio]));

    $risposta->assertOk();
    $risposta->assertHeader('Content-Type', 'application/pdf');
});

test('il registro condivide il tetto di stampa del Libro Giornale, non uno inventato', function () {
    $metodoRegistro = new ReflectionMethod(
        App\Http\Controllers\Gestionale\Movimenti\RegistroContabilitaController::class,
        'righeStampabiliCon'
    );
    $metodoGiornale = new ReflectionMethod(
        App\Http\Controllers\Gestionale\Movimenti\ScritturaContabileController::class,
        'righeStampabiliCon'
    );

    expect($metodoRegistro->invoke(null, '128M'))->toBe($metodoGiornale->invoke(null, '128M'));
});

/**
 * ⚠️ **Il foglio che va in assemblea non denuncia i ritardi di chi lo ha scritto.** D9 di
 * docs/registri_contabili.md: «un registro che denuncia da solo i ritardi del suo autore,
 * consegnato in assemblea, è un'arma contro l'amministratore, non uno strumento per lui». A
 * schermo la colonna «annotato il» resta sempre — lì serve, è la vista con cui rimediare — ma in
 * stampa entra «solo se l'amministratore lo chiede», e questo test tiene fermo il valore
 * predefinito: chi non chiede niente ottiene la copia d'assemblea.
 */
test('la stampa non porta la data di annotazione, se non gliela si chiede', function () {
    $user = adminRegistro();
    [$condominio, $esercizio] = setupRegistroConUnIncasso();

    $catturati = [];
    View::composer('pdf.gestionale.registro_contabilita', function ($view) use (&$catturati) {
        $catturati = $view->getData();
    });

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.registro-contabilita.print', [$condominio, $esercizio]))
        ->assertOk();

    expect($catturati['mostra_annotazione'])->toBeFalse();

    $html = view('pdf.gestionale.registro_contabilita', $catturati)->render();
    expect($html)->not->toContain('Annotato')
        ->and($html)->not->toContain('oltre 30 gg')
        ->and($html)->not->toContain('Copia di controllo');
});

test('la copia di controllo porta le annotazioni e dichiara di essere tale', function () {
    $user = adminRegistro();
    [$condominio, $esercizio] = setupRegistroConUnIncasso();

    $catturati = [];
    View::composer('pdf.gestionale.registro_contabilita', function ($view) use (&$catturati) {
        $catturati = $view->getData();
    });

    $this->actingAs($user)
        ->get(route('admin.gestionale.esercizi.registro-contabilita.print', [
            $condominio, $esercizio, 'annotazioni' => 1,
        ]))
        ->assertOk();

    expect($catturati['mostra_annotazione'])->toBeTrue();

    $html = view('pdf.gestionale.registro_contabilita', $catturati)->render();
    // ⚠️ La dichiarazione conta quanto la colonna: due stampe dello stesso registro che si
    // distinguono solo per una colonna in più sono indistinguibili una volta sul tavolo.
    expect($html)->toContain('Annotato')
        ->and($html)->toContain('Copia di controllo');
});
