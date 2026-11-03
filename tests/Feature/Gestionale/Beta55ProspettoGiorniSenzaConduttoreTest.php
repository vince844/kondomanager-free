<?php

/**
 * 1.11.0-beta.55, Coda 245 — il prospetto degli oneri accessori, i giorni senza conduttore di una fine con la regola del piano quando chi sta al posto
 * dell'inquilino ha già nelle sue rate una parte dell'anello.
 *
 * Alla generazione i giorni che nessun inquilino copriva c'erano solo con tutte le righe d'inquilino chiuse (la riga chiusa «Data fine competenza» prima della
 * generazione): allora la parte di quei giorni è già nelle rate di chi paga al posto dell'inquilino, il proprietario. La sua sezione nel prospetto deve contarla
 * accanto alla parte di oggi e al conguaglio, come per un conduttore: «da rimborsare» è la parte meno ciò che ha nelle rate meno il conguaglio.
 *
 * I CONTI A MANO (centesimi; anno 2026, 365 giorni; «Spese generali» € 1.200,00 al 30 % all'inquilino: l'anello dell'Interno 1 è 36000; Ugo proprietario):
 * - Ines 50 e Dora 50, le righe d'inquilino chiuse tutte e due al 30/11 prima della generazione (334 giorni, dicembre senza conduttore, 31 giorni). Congelato:
 *   i pesi 50 × 334 = 16700 ciascuna (somma 33400) sui 334 coperti, e 31 di Ugo: 36000 × 167/365 = 16471,23 per Ines e per Dora, 36000 × 31/365 = 3057,53 per
 *   Ugo; le parti intere sono 16471 + 16471 + 3057 = 35999 e il centesimo che avanza va al resto più grande, Ugo (,53): Ines 16471, Dora 16471, Ugo 3058.
 * - La fine di Ines il 1/7 con Dora che resta, «Scrivi»: Ines 181 giorni, Dora 334, dicembre a Ugo; i pesi 50 × 181 = 9050 e 16700 (somma 25750) sui 334
 *   coperti: Ines 117,386 giorni, Dora 216,614, e i 31 di Ugo (somma 365): 11577,84 / 21364,63 / 3057,53 → le parti intere sono 11577 + 21364 + 3057 = 35998, i due
 *   centesimi vanno ai resti più grandi (Ines ,84 e Dora ,63): Ines 11578, Dora 21365, Ugo 3057.
 * - Le differenze: Ines − 4893, Dora + 4894, Ugo − 1. La coppia: Ines − 4893, Dora + 4894, Ugo − 1 (nessuno era già uscito e la cosa torna: si scrivono le
 *   differenze di tutti). Ugo ha nelle rate 3058, la sua parte è 3057, il conguaglio gli dà € 0,01 a credito: da rimborsare 3057 − 3058 − (− 1) = 0.
 *   Prima della correzione il prospetto non contava le sue rate e diceva 3057 + 1 = 3058. I conduttori sono giusti: Ines 11578 − 16471 + 4893 = 0, Dora
 *   21365 − 16471 − 4894 = 0.
 */

use App\Models\User;
use App\Services\Riparto\ProspettoOneriAccessori;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Beta55CoinquilinoScenari.php';

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

it('Coda 245 (prospetto) — i giorni di dicembre senza conduttore già nelle rate di Ugo: la sua parte è € 30,57, ne ha € 30,58 nelle rate e € 0,01 a credito con il conguaglio, quindi niente da rimborsare', function () {
    $s = rdpScenario($this, [['Ines', 50], ['Dora', 50]], ['fini' => ['Ines' => '2026-11-30', 'Dora' => '2026-11-30']]);
    expect(fclNetti($s))->toBe(['Dora' => 16471, 'Ines' => 16471, 'Ugo' => 87058]);
    $righe = DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('ruolo_richiesto', 'inquilino')->where('tipo', 'riparto')
        ->orderBy('anagrafica_id')->pluck('importo', 'anagrafica_id')->map(fn ($c) => (int) $c)->all();
    expect($righe)->toBe([(int) $s['persone']['Ugo']->id => 3058, (int) $s['persone']['Ines']->id => 16471, (int) $s['persone']['Dora']->id => 16471]);

    $rigaInes = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $s['persone']['Ines']->id)->where('tipologia', 'inquilino')->value('id');
    $modulo = ['tipo' => 'fine_locazione', 'tipologia' => 'inquilino', 'riga_uscente_id' => $rigaInes, 'anagrafica_entrante_id' => null, 'decorrenza' => '2026-07-01', 'quota' => 50,
        'copia_autentica' => false, 'pertinenze' => []];
    expect(rdpCoppia(fclAnteprima($this, $s, $modulo)))->toBe(['Dora' => 4894, 'Ines' => -4893, 'Ugo' => -1]);
    fclRegistra($this, $s, $modulo + ['conguaglio' => 'scrivi']);
    expect(fclNetti($s))->toBe(['Dora' => 21365, 'Ines' => 11578, 'Ugo' => 87057]);

    $p = app(ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $sezioni = collect($p['conduttori'])->mapWithKeys(fn (array $c) => [$c['nome'] => [(int) $c['totale'], (int) $c['nelle_sue_rate'], (int) $c['con_il_conguaglio'], (int) $c['da_rimborsare']]])->all();
    expect($sezioni)->toBe(['Dora' => [21365, 16471, 4894, 0], 'Ines' => [11578, 16471, -4893, 0]])
        ->and((int) $p['senza_conduttore']['totale'])->toBe(3057)
        ->and(collect($p['senza_conduttore']['voci'])->map(fn (array $v) => [(int) $v['importo'], (int) ($v['nelle_rate'] ?? 0)])->all())->toBe([[3057, 3058]])
        // Era 3058: la sua parte e il centesimo del conguaglio, senza le rate che già pagava.
        ->and((int) $p['senza_conduttore']['regola_del_piano']['da_rimborsare'])->toBe(0)
        ->and(array_map(fn (array $x) => (int) $x['importo'], $p['senza_conduttore']['regola_del_piano']['conguagli']))->toBe([-1])
        ->and($p['senza_conduttore']['regola_del_piano']['rimborsi'])->toBe([]);

    // La stampa dice per chi sta al posto dell'inquilino la sua parte e ciò che ne ha già nelle rate: la riga finiva a «(Ugo)» e non spiegava perché da rimborsare è zero.
    expect(fclStampa($s))->toContain('Spese generali 31 giorni € 30,57 con la regola del piano, a chi sta al posto dell\'inquilino (Ugo); nelle sue rate € 30,58');
});
