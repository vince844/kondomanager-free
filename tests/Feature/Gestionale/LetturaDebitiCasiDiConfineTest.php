<?php

/**
 * Cinque confini della lettura comune dei debiti (1.12.0-beta.4) e della parte in accordo di una rata, che l'adeguamento del laboratorio alla
 * beta.4 ha trovato senza un test del prodotto (le mutazioni N04, N06, N07, N08 e A06 del suo rapporto). Sono dati che il programma di oggi non
 * scrive, o non scrive più, ma che un database può avere: per questo i dati si scrivono come li scriverebbe il programma, con la riga di giornale
 * nella forma che la lettura somma (come `LetturaDebitiNelDatabaseTest`).
 *
 * - le righe senza rata sul mastro dei crediti di due gestioni restano due (la chiave porta la gestione);
 * - una scrittura cancellata (`deleted_at`, dalle versioni prima della 1.9.1, che avevano la cancellazione morbida) non conta;
 * - la lettura di un condominio non vede le righe di un altro, nemmeno della stessa persona;
 * - una riga del mastro dei crediti senza persona non diventa una persona «0»;
 * - una rata in due accordi in corso (il programma non lo permette, ma un dato vecchio o scritto a mano può averlo) lascia fuori dalle lettere
 *   la parte più grande, mai la somma.
 *
 * Centesimi.
 */

use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\AccordoRientroRata;
use App\Models\User;
use App\Services\Gestionale\Debiti\AccordiRientro;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Una scrittura con due righe, banca e mastro dei crediti, come le scrive il programma; `$altro` per la riga dei crediti e per la scrittura. */
function ldcScrittura(array $s, int $gestioneId, string $data, string $tipoRiga, int $importo, ?int $persona, ?int $rata, array $scrittura = []): int
{
    $crediti = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'crediti_condomini')->value('id');
    $banca = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'banca')->value('id');
    $id = DB::table('scritture_contabili')->insertGetId(array_merge(['condominio_id' => $s['c']->id, 'gestione_id' => $gestioneId, 'esercizio_id' => $s['e']->id,
        'data_registrazione' => $data, 'data_competenza' => $data, 'numero_protocollo' => 'LDC-' . uniqid(), 'causale' => 'Prova', 'tipo_movimento' => 'incasso_rata',
        'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()], $scrittura));
    $altroTipo = $tipoRiga === 'dare' ? 'avere' : 'dare';
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $id, 'conto_contabile_id' => $banca, 'anagrafica_id' => null, 'rata_id' => null, 'immobile_id' => null, 'tipo_riga' => $altroTipo, 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $id, 'conto_contabile_id' => $crediti, 'anagrafica_id' => $persona, 'rata_id' => $rata, 'immobile_id' => $persona === null ? null : (int) $s['immobili']['A']->id,
            'tipo_riga' => $tipoRiga, 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
    ]);

    return $id;
}

it('le righe senza rata sul mastro dei crediti di due gestioni restano due, una per gestione', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    $altra = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Straordinaria', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01']);
    $ugo = (int) $s['persone']['ugo']->id;
    ldcScrittura($s, (int) $s['g']->id, '2026-03-01', 'dare', 3000, $ugo, null);
    ldcScrittura($s, (int) $altra->id, '2026-03-02', 'dare', 5000, $ugo, null);

    $nonEmesse = collect(lcdLeggi($s, 'ugo')['non_emesse'])->whereNull('rata_id')->sortBy('saldo')->values();
    expect($nonEmesse)->toHaveCount(2)
        ->and($nonEmesse->map(fn ($r) => [$r['gestione_id'], $r['saldo']])->all())->toBe([[(int) $s['g']->id, 3000], [(int) $altra->id, 5000]]);
});

it('una scrittura cancellata (con la cancellazione morbida delle versioni prima della 1.9.1) non conta nella lettura', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1], '2026-02-28');
    // Un versamento della rata 1 cancellato: resta nel database con `deleted_at`.
    ldcScrittura($s, (int) $s['g']->id, '2026-03-10', 'avere', 4000, (int) $s['persone']['ugo']->id, lcdRata($s, 1), ['deleted_at' => now()]);

    expect(lcdRiga(lcdLeggi($s, 'ugo'), $s, 'A', 1))->toMatchArray(['dovuto' => 10000, 'versato' => 0, 'residuo' => 10000]);
});

it('la lettura di un condominio non vede le righe di un altro, nemmeno quelle della stessa persona', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $primo = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $primo, [1], '2026-02-28');
    $secondo = lcdScenario(['A' => ['bice', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $secondo, [1], '2026-02-28');
    // Ugo ha anche una riga nel secondo condominio.
    ldcScrittura($secondo, (int) $secondo['g']->id, '2026-03-01', 'dare', 7000, (int) $primo['persone']['ugo']->id, null);

    $delPrimo = app(LetturaDebiti::class)->perCondominio((int) $primo['c']->id);
    expect(array_keys($delPrimo))->toBe([(int) $primo['persone']['ugo']->id])
        ->and(collect($delPrimo[(int) $primo['persone']['ugo']->id]['non_emesse'])->whereNull('rata_id')->all())->toBe([])
        ->and(collect($delPrimo[(int) $primo['persone']['ugo']->id]['rate'])->sum('dovuto'))->toBe(10000);
    $delSecondo = app(LetturaDebiti::class)->perCondominio((int) $secondo['c']->id);
    expect(array_keys($delSecondo))->toEqualCanonicalizing([(int) $secondo['persone']['bice']->id, (int) $primo['persone']['ugo']->id]);
});

it('una riga del mastro dei crediti senza persona non diventa una persona «0»', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1], '2026-02-28');
    ldcScrittura($s, (int) $s['g']->id, '2026-03-01', 'dare', 2500, null, null);

    $lettura = app(LetturaDebiti::class)->perCondominio((int) $s['c']->id);
    expect(array_keys($lettura))->toBe([(int) $s['persone']['ugo']->id])
        ->and($lettura)->not->toHaveKey(0);
});

it('una rata in due accordi in corso lascia fuori dalle lettere la parte più grande, mai la somma né la più piccola', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    lcdEmetti($this, $s, [1], '2026-02-28');
    $ugo = (int) $s['persone']['ugo']->id;
    $rata = lcdRata($s, 1);
    $immobile = (int) $s['immobili']['A']->id;
    // Il programma non lascia mettere una rata in due accordi in corso: i due accordi si scrivono come li scriverebbe la sua azione.
    foreach ([5000, 8000] as $riconosciuto) {
        $accordo = AccordoRientro::create(['condominio_id' => $s['c']->id, 'anagrafica_id' => $ugo, 'data_accordo' => '2026-03-15', 'fino_al' => '2026-12-15',
            'autorizzato_assemblea' => false, 'registrato_da' => $this->user->id]);
        AccordoRientroRata::create(['accordo_rientro_id' => $accordo->id, 'rata_id' => $rata, 'immobile_id' => $immobile, 'importo_riconosciuto' => $riconosciuto,
            'residuo_iniziale' => 10000]);
    }

    $lettura = lcdLeggi($s, 'ugo');
    $fuori = app(AccordiRientro::class)->fuoriDalleLettere((int) $s['c']->id, $ugo, $lettura['rate'], '2026-03-20');
    expect($fuori)->toBe([$rata . '|' . $immobile => 8000]);
});
