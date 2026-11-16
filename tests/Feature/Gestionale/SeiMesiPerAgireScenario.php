<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Lo scenario dei sei mesi per agire: Via Verdi 12, quattro persone (Rossi, Bianchi, Verdi, Neri), un'unità ciascuna da 250 millesimi, un piano di 12 rate
 * mensili dal 05/01/2026 su € 4.800,00 l'anno, quindi € 100,00 a rata per persona, tutte emesse il 02/01/2026. Le rate 1 e 2 hanno la scadenza spostata
 * nel 2025 (05/11/2025 e 05/12/2025), dentro l'esercizio 2025 (aperto): la loro data dei sei mesi è il 30/06/2026. Le rate 3, 4 e 5 (05/03, 05/04, 05/05/2026)
 * sono dell'esercizio 2026: il 30/06/2027. Oggi è il 20/05/2026: il 30/06/2026 è fra 41 giorni, il 30/06/2027 fra 406.
 *
 * Per persona: € 200,00 dell'esercizio 2025 e € 300,00 del 2026, € 500,00 di scaduto in tutto.
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LetturaDebitiProva.php';

/** Un utente del pannello; con `$chiude` anche «Modifica scadenza in agenda», che chiude i compiti. */
function smaUtente(bool $chiude = true): User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $accesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $agenda = Permission::firstOrCreate(['name' => 'Modifica scadenza in agenda', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => $chiude ? 'admin' : 'admin-senza-agenda', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($chiude ? [$accesso, $agenda] : [$accesso]);
    $u = User::factory()->create(['name' => $chiude ? 'Vincenzo' : 'Ospite']);
    $u->assignRole($ruolo);

    return $u;
}

function smaViaVerdi($test): array
{
    $test->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 250], 'B' => ['bianchi', 250], 'C' => ['verdi', 250], 'D' => ['neri', 250]], 12, 'prima_rata', [], 480000);
    $s['c']->update(['nome' => 'Via Verdi 12']);
    $s['e2025'] = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'aperto']);
    lcdEmetti($test, $s, range(1, 12), '2026-01-02');
    smaSposta($s, 1, '2025-11-05');
    smaSposta($s, 2, '2025-12-05');
    $test->travelTo('2026-05-20 10:00:00');

    return $s;
}

/** La scadenza di una rata spostata, sulla rata e sulle sue quote. */
function smaSposta(array $s, int $numero, string $giorno): void
{
    DB::table('rate')->where('id', lcdRata($s, $numero))->update(['data_scadenza' => $giorno]);
    DB::table('rate_quote')->where('rata_id', lcdRata($s, $numero))->update(['data_scadenza' => $giorno]);
}

/** La chiave «rata|unità» di una rata di una persona. */
function smaChiave(array $s, string $chi, int $numero): string
{
    $unita = ['rossi' => 'A', 'bianchi' => 'B', 'verdi' => 'C', 'neri' => 'D'][$chi] ?? $chi;

    return lcdRata($s, $numero) . '|' . $s['immobili'][$unita]->id;
}

/** Una lettera del registro scritta a mano, con le sue rate e il termine. */
function smaLettera(array $s, string $chi, string $tipo, string $stato, string $giorno, array $numeri, array $altro = []): RegistroInvio
{
    $righe = array_map(function ($n) use ($s, $chi) {
        [$rata, $unita] = explode('|', smaChiave($s, $chi, $n));

        return ['rata_id' => (int) $rata, 'immobile_id' => (int) $unita, 'numero_rata' => $n, 'residuo' => 10000];
    }, $numeri);
    $dati = $altro + ['lettera' => (string) \Illuminate\Support\Str::uuid(), 'persona' => ['id' => $s['persone'][$chi]->id, 'nome' => $s['persone'][$chi]->nome],
        'righe' => $righe, 'totale' => 10000 * count($numeri), 'termine' => 15, 'termine_forma' => 'giorni'];
    if ($stato === RegistroInvio::STATO_SPEDITA && ! isset($dati['spedizione'])) {
        $dati['spedizione'] = ['canale' => 'raccomandata', 'data' => $giorno, 'numero' => 'RR1'];
    }

    return RegistroInvio::create([
        'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone'][$chi]->id, 'tipo' => $tipo,
        'canale' => in_array($stato, ['inviata', 'in_coda', 'non_partita'], true) ? 'email' : 'stampa',
        'documentabile_type' => \App\Models\Anagrafica::class, 'documentabile_id' => $s['persone'][$chi]->id,
        'dati' => $dati, 'stato' => $stato, 'emesso_il' => \Illuminate\Support\Carbon::parse($giorno . ' 10:00', 'Europe/Rome'),
    ]);
}

/** Un accordo di rientro della persona sulle rate date, per il loro residuo, con «fino al». */
function smaAccordo(array $s, string $chi, array $numeri, string $data = '2026-01-10', string $finoAl = '2026-09-30'): AccordoRientro
{
    $a = AccordoRientro::create(['condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone'][$chi]->id, 'data_accordo' => $data, 'fino_al' => $finoAl,
        'autorizzato_assemblea' => false]);
    foreach ($numeri as $n) {
        [$rata, $unita] = explode('|', smaChiave($s, $chi, $n));
        DB::table('accordo_rientro_rate')->insert(['accordo_rientro_id' => $a->id, 'rata_id' => (int) $rata, 'immobile_id' => (int) $unita, 'importo_riconosciuto' => 10000,
            'residuo_iniziale' => 10000, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $a;
}

/** Una dispensa dell'assemblea sugli esercizi dati, per tutti o per le persone date. */
function smaDispensa(array $s, array $esercizi, ?array $persone = null, bool $togliSollecito = false, string $delibera = '2026-03-15'): int
{
    $id = DB::table('dispense_sei_mesi')->insertGetId(['condominio_id' => $s['c']->id, 'data_delibera' => $delibera, 'numero_verbale' => '2',
        'tutte_le_persone' => $persone === null, 'togli_sollecito_tesoreria' => $togliSollecito, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($esercizi as $e) {
        DB::table('dispensa_sei_mesi_esercizio')->insert(['dispensa_id' => $id, 'esercizio_id' => $e->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ($persone ?? [] as $chi) {
        DB::table('dispensa_sei_mesi_anagrafica')->insert(['dispensa_id' => $id, 'anagrafica_id' => $s['persone'][$chi]->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $id;
}

/** Il riquadro di oggi. */
function smaRiquadro(array $s): array
{
    return app(SeiMesiPerAgire::class)->perCondominio($s['c']);
}

/** La riga dell'esercizio, per nome («Esercizio 2025»). */
function smaRiga(array $r, string $esercizio): ?array
{
    return collect($r['righe'])->first(fn ($x) => $x['esercizio']['nome'] === $esercizio);
}

/** La persona di una riga, per nome («Rossi»), fra le aperte. */
function smaPersona(array $riga, string $nome): ?array
{
    return collect($riga['persone'])->first(fn ($p) => $p['nome'] === $nome);
}

/** Una risposta dalla rotta del riquadro. */
function smaRispondi($test, array $s, array $dati, ?User $chi = null)
{
    return $test->actingAs($chi ?? $test->user)->post(route('admin.gestionale.sei-mesi.risposta', ['condominio' => $s['c']->id]), $dati);
}
