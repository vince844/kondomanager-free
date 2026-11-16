<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I compiti che nascono da una fattura e dal già versato hanno il giorno di Roma, anche fra le 00:00 e l'01:00 di Roma d'inverno (le 02:00 d'estate), quando
 * il server è ancora a ieri: la convocazione per lo sforo e il piano della sopravvenienza fra 7 giorni, la ratifica fra 30, il deficit ereditato fra 15; il
 * compito «Già versato dichiarato come acconto» il giorno in cui è stato dichiarato. Il giorno di un compito è la data di `start_time` letta come giorno di
 * Roma (`FasceDeiCompiti`), scritta come l'agenda scrive le ore (`DateHelper::adessoComeSiLegge`).
 */

use App\Enums\EventoTipo;
use App\Events\Gestionale\FatturaRegistrata;
use App\Helpers\DateHelper;
use App\Listeners\Gestionale\SyncScadenziarioWithFattura;
use App\Models\Anagrafica;
use App\Models\CategoriaEvento;
use App\Models\Evento;
use App\Models\Gestionale\Conto;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\Inbox\FasceDeiCompiti;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

const CFG_NOTTI = [
    'inverno, alle 00:30' => ['2026-11-16 00:30', '2026-11-16'],
    'ora legale, alle 01:30' => ['2026-07-16 01:30', '2026-07-16'],
];

/** Una fattura pregressa di € 122,00 coperta dalla rata 0, con il debito ereditato verso il fornitore: il deficit è tutto da ripianare. */
function cfgFatturaPregressa(): array
{
    [$condominio, $esercizio, $gestione, $fornitore] = setupContabile();
    CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'prova']);
    $saldoFornitoreId = DB::table('saldi')->insertGetId(['condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id,
        'fornitore_id' => $fornitore->id, 'saldo_iniziale' => -12200, 'is_applicato' => false, 'created_at' => now(), 'updated_at' => now()]);
    $dati = datiBase([$condominio, $esercizio, $gestione, $fornitore], [
        'data_documento' => '2025-12-01', 'data_scadenza' => '2025-12-31', 'is_pregresso' => true,
        'imponibile_pregresso' => 100.00, 'aliquota_iva_pregressa' => 22, 'saldo_patrimoniale_id' => $saldoFornitoreId, 'righe' => [],
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 122.00, 'fonte_id' => $saldoFornitoreId]],
    ]);

    return [(new FatturaPassivaService())->registraFattura($dati, $condominio->id), User::factory()->create()];
}

/** I giorni che il compito aspetta da oggi, come li conta l'Inbox. */
function cfgGiorni(string $tipo): int
{
    return FasceDeiCompiti::oggi()->giorni(Evento::where('meta->type', $tipo)->sole()->start_time->format('Y-m-d'));
}

it('il compito del deficit ereditato aspetta quindici giorni da oggi, nel giorno di Roma', function (string $adesso, string $oggi) {
    [$fattura, $user] = cfgFatturaPregressa();
    $this->travelTo(Carbon::parse($adesso, 'Europe/Rome')->utc());

    (new SyncScadenziarioWithFattura())->handle(new FatturaRegistrata($fattura, $user->id));

    expect(DateHelper::oggiUtente())->toBe($oggi)
        ->and(cfgGiorni('pianifica_ripianamento_deficit'))->toBe(15);
})->with(CFG_NOTTI);

it('la convocazione per lo sforo e il piano della sopravvenienza aspettano sette giorni, la ratifica trenta, nel giorno di Roma', function (string $adesso, string $oggi) {
    [$fattura, $user] = cfgFatturaPregressa();
    // La stessa fattura in sforo motivato, con una parte coperta da una sopravvenienza.
    DB::table('fatture_passive')->where('id', $fattura->id)->update(['stato_approvazione' => 'sforo_motivato']);
    DB::table('fattura_coperture')->insert(['fattura_passiva_id' => $fattura->id, 'tipo_copertura' => 'sopravvenienza', 'importo' => 5000, 'stato' => 'pianificata',
        'created_at' => now(), 'updated_at' => now()]);
    $this->travelTo(Carbon::parse($adesso, 'Europe/Rome')->utc());

    (new SyncScadenziarioWithFattura())->handle(new FatturaRegistrata($fattura->fresh(), $user->id));

    expect(DateHelper::oggiUtente())->toBe($oggi)
        ->and(cfgGiorni('convocazione_urgenza'))->toBe(7)
        ->and(cfgGiorni('ratifica_sforo'))->toBe(30)
        ->and(cfgGiorni('emissione_rata_sopravvenienza'))->toBe(7);
})->with(CFG_NOTTI);

/** L'utente del pannello con il solo accesso, come le prove del già versato. */
function cfgUtenteDelPannello(): User
{
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $u = User::factory()->create();
    $u->assignRole($ruolo);

    return $u;
}

/** La voce di spesa da € 1.100,00 che chiede il già versato, su due unità, come `cvScenario` di `ContributiVersatiHttpTest.php`. */
function cfgScenarioGiaVersato(array $ctx): object
{
    [$condominio, $esercizio, $gestione] = $ctx;
    $pianoConto = DB::table('piani_conti')->where('gestione_id', $gestione->id)->first();
    $tabella = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'Millesimi Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $conto = Conto::forceCreate(['piano_conto_id' => $pianoConto->id, 'nome' => 'Lavori ristrutturazione', 'importo' => 110_000, 'tipo' => 'spesa', 'attivo' => true,
        'richiede_gia_versato' => true]);
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100,
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $pivotId, 'soggetto' => 'proprietario', 'percentuale' => 100,
        'created_at' => now(), 'updated_at' => now()]);
    $unita = [];
    foreach ([1, 2] as $n) {
        $immobile = Immobile::forceCreate(['condominio_id' => $condominio->id, 'nome' => "Interno $n", 'descrizione' => 'App', 'interno' => (string) $n]);
        $an = Anagrafica::forceCreate(['nome' => "Proprietario $n", 'email' => "cfg$n@example.com", 'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => 'CFGTST'.str_pad((string) $n, 10, '0', STR_PAD_LEFT)]);
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $an->id, 'immobile_id' => $immobile->id, 'tipologia' => 'proprietario', 'quota' => 100.0,
            'attivo' => true, 'data_inizio' => now()->format('Y-m-d')]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $immobile->id, 'valore' => 500, 'created_at' => now(), 'updated_at' => now()]);
        $unita[] = (object) ['immobile' => $immobile, 'anagrafica' => $an];
    }

    return (object) compact('condominio', 'gestione', 'conto', 'unita');
}

it('il compito «Già versato dichiarato come acconto» è del giorno di Roma in cui è stato dichiarato', function (string $adesso, string $oggi) {
    $utente = cfgUtenteDelPannello();
    $sc = cfgScenarioGiaVersato(setupContabile());
    $this->travelTo(Carbon::parse($adesso, 'Europe/Rome')->utc());

    $this->actingAs($utente)->put("/admin/gestionale/{$sc->condominio->id}/contributi/{$sc->conto->id}", [
        'natura' => 'fondo_vincolato',
        'righe' => [['immobile_id' => $sc->unita[0]->immobile->id, 'gia_versato' => 50_000]],
        'liquidita_stato' => 'gia_speso_acconto',
        'nota_acconto' => 'Acconto versato al fornitore il 15/06/2025.',
    ])->assertSessionHasNoErrors();

    expect(Evento::where('tipo', EventoTipo::GIA_VERSATO_ACCONTO_DICHIARATO)->sole()->start_time->format('Y-m-d'))->toBe($oggi);
})->with(CFG_NOTTI);
