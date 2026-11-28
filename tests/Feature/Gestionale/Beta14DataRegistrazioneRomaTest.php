<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14 (Coda 494) — la data di registrazione di una scrittura è il giorno di Roma.
 *
 * `data_registrazione` è una colonna DATE e le scritture la riempivano con `now()`, in UTC: alle 00:30 di Roma del 01/01/2027 una scrittura
 * portava il 31/12/2026, e un incasso di inizio anno finiva nel registro e nel protocollo dell'anno prima. Ora tutti i punti usano
 * `DateHelper::oggiUtente()`, e una guardia cerca nel codice che nessuno torni a `now()`.
 *
 * La catena: l'incasso dalla rotta della pagina → la scrittura (data e protocollo) → il suo storno. Nella tappa 2 anche la successione dalla
 * rotta → gli interessi del defunto passati agli eredi (`AccessoriAgliEredi`, la scrittura «Agli eredi: …»): l'ultimo punto che la guardia ammetteva
 * per nome, ora senza eccezioni.
 *
 * Cosa NON copre: i due punti F24 (`StornaVersamentoF24Action`, scritto con una variabile prima del `create`, e `DelegaF24Controller`), del
 * pacchetto C: la guardia non li vede e vanno guardati a mano.
 */

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/LetturaDebitiProva.php';
require_once __DIR__.'/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Bianca, una rata da 30000 emessa il 01/11/2026; nessun incasso ancora. */
function b14DrScenario($test): array
{
    $test->travelTo('2026-10-30 09:00:00');
    $s = lcdScenario(['B' => ['bianca', 1000]], 1, 'prima_rata', [], 30000, '2026-11-30', 30);
    $test->travelTo('2026-11-01 10:00:00');
    lcdEmetti($test, $s, [1], '2026-11-01');

    return $s;
}

it('un incasso registrato alle 00:30 di Roma del 01/01/2027 ha la data del 01/01/2027 (e non del 31/12/2026)', function () {
    $s = b14DrScenario($this);
    $this->travelTo(Carbon::parse('2027-01-01 00:30:00', 'Europe/Rome'));

    $incasso = lcdIncassa($this, $s, 'bianca', [lcdQuota($s, 'bianca', 1) => 30000], '2026-12-10');

    expect(substr((string) DB::table('scritture_contabili')->where('id', $incasso)->value('data_registrazione'), 0, 10))->toBe('2027-01-01');
});

it('lo storno dell\'incasso, alle 00:30 di Roma del 01/01/2027, porta la data del 01/01/2027', function () {
    $s = b14DrScenario($this);
    $this->travelTo('2026-12-10 10:00:00');
    $incasso = lcdIncassa($this, $s, 'bianca', [lcdQuota($s, 'bianca', 1) => 30000], '2026-12-10');
    $this->travelTo(Carbon::parse('2027-01-01 00:30:00', 'Europe/Rome'));

    lcdStorna($this, $s, $incasso);

    $date = DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->pluck('data_registrazione')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->all();
    expect($date)->toBe(['2027-01-01']);
});

it('guardia — in app/ nessuna scrittura scrive data_registrazione con now() o con il giorno di Greenwich', function () {
    $trovati = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $rel = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
        foreach (file($file->getPathname()) as $n => $riga) {
            if (preg_match('/[\'"](data_registrazione|data_competenza)[\'"]\s*=>\s*(now\(|Carbon::now\(|today\(|Carbon::today\(|CarbonImmutable::now\()/', $riga)) {
                $trovati[] = $rel.':'.($n + 1);
            }
        }
    }

    expect($trovati)->toBe([]);
});

it('lo storno di un giroconto alle 00:30 di Roma del 16/03/2026 ha la registrazione del giorno di Roma e la competenza del giroconto', function () {
    $this->travelTo(Carbon::parse('2026-03-15 10:00', 'Europe/Rome')->utc());
    [$condominio, $esercizio, $gestione] = setupContabile();
    $this->actingAs($this->user);
    $crea = function (string $tipo, int $saldo) use ($condominio) {
        $contoId = DB::table('conti_contabili')->insertGetId(['condominio_id' => $condominio->id, 'ruolo' => $tipo === 'banca' ? 'conto_bancario' : 'fondo_riserva',
            'codice' => '1010.'.uniqid(), 'nome' => 'Conto '.$tipo.' '.uniqid(), 'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);

        return \App\Models\Gestionale\Cassa::create(['condominio_id' => $condominio->id, 'nome' => ucfirst($tipo).' '.uniqid(), 'tipo' => $tipo, 'conto_contabile_id' => $contoId,
            'saldo_iniziale' => $saldo, 'attiva' => true, 'sottotipo_fondo' => $tipo === 'fondo' ? 'generico' : null]);
    };
    $banca = $crea('banca', 100000);
    $fondo = $crea('fondo', 0);
    $giroconto = app(\App\Actions\Gestionale\Movimenti\RegistraGirocontoAction::class)->execute([
        'cassa_origine_id' => $banca->id, 'cassa_destinazione_id' => $fondo->id, 'importo' => 500.00, 'data_operazione' => '2026-03-15',
        'causale' => 'Giroconto di prova', 'gestione_id' => $gestione->id, 'esercizio_id' => $esercizio->id,
    ], $condominio, $esercizio);

    $this->travelTo(Carbon::parse('2026-03-16 00:30', 'Europe/Rome')->utc());
    $storno = app(\App\Actions\Gestionale\Movimenti\StornaGirocontoAction::class)->execute($giroconto, $condominio, 'Errore di importo, va rifatto');
    $storno->refresh();

    // La competenza dello storno nello stesso esercizio è la data del giroconto che annulla (la regola dello storno di un incasso).
    expect($storno->data_registrazione->toDateString())->toBe('2026-03-16')
        ->and($storno->data_competenza->toDateString())->toBe('2026-03-15');
});

it('Coda 494, il resto: gli interessi di Ettore passati agli eredi con la successione registrata alle 00:30 di Roma del 01/01/2027 portano il 01/01/2027', function () {
    $s = ideCaso($this);
    // Gli interessi di Ettore sulla rata di aprile, registrati al 31/10/2026 dalla rotta della finestra.
    $proposta = ideProposta($this, $s, ['Neri'], '2026-10-31');
    $righe = collect($proposta['persone'])->flatMap(fn ($p) => $p['righe'])->where('chiave', $s['persone']['Neri']->id . '|' . lcdRata($s, 1) . '|' . $s['unita']['U1']->id);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.interessi.store', [$s['c']]), [
        'alla' => $proposta['alla'], 'righe' => $righe->pluck('chiave')->values()->all(), 'atteso' => (int) $righe->sum('interessi'),
        'voci' => collect($proposta['gestioni'])->mapWithKeys(fn ($g) => [$g['gestione_id'] => $s['interessi']->id])->all(),
    ])->assertCreated();

    $this->travelTo(Carbon::parse('2027-01-01 00:30:00', 'Europe/Rome'));
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), ideSuccessione($s))->assertSessionHasNoErrors();

    // Con il codice di prima il 31/12/2026, il giorno di Greenwich: la scrittura finiva nel registro dell'anno prima. Il protocollo, dalla tappa 1, è del 2027.
    $agliEredi = DB::table('scritture_contabili')->where('causale', 'like', 'Agli eredi: %')->get(['data_registrazione', 'data_competenza', 'numero_protocollo']);
    expect($agliEredi)->not->toBeEmpty()
        ->and($agliEredi->map(fn ($x) => substr((string) $x->data_registrazione, 0, 10))->unique()->values()->all())->toBe(['2027-01-01'])
        ->and($agliEredi->map(fn ($x) => substr((string) $x->data_competenza, 0, 10))->unique()->values()->all())->toBe(['2026-11-17'])
        ->and($agliEredi->every(fn ($x) => str_contains((string) $x->numero_protocollo, '-2027-')))->toBeTrue();
});
