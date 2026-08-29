<?php

use App\Models\Condominio;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * **Un'importazione è di chi l'ha caricata.**
 *
 * Fino al 28/08/2026 non lo era. Tutte e nove le porte del lotto facevano
 * `ImportBatch::where('uuid', $uuid)->firstOrFail()` e nient'altro: chiunque potesse importare
 * poteva aprire, dirottare e **scartare** l'importazione a metà di un collega. E non c'era
 * nemmeno un uuid da indovinare — la schermata d'ingresso mostrava a tutti l'ultimo lotto in
 * corso, quello di chiunque l'avesse lasciato aperto, col nome del suo condominio.
 *
 * Il difetto è **preesistente**, non della porta nuova: la destinazione scelta a mano ha
 * semplicemente copiato la forma delle altre otto. È saltato fuori cercando difetti *su quella*,
 * ed è stato riprodotto eseguendo, con due collaboratori: il secondo riceveva l'uuid del primo
 * nei dati della hub, gli impostava una destinazione (302, decisioni scritte), ne apriva la
 * verifica (200) e lo portava a «annullato».
 *
 * ⚠️ **Nessun test l'aveva visto, e non per distrazione: i test lo esercitavano.**
 * `caricaBundle()` caricava come un utente appena creato e il test proseguiva come un altro —
 * passava proprio perché la guardia non c'era. Una suite che percorre il buco non lo può vedere.
 *
 * **404 e non 403**: un lotto che non è tuo non esiste, per te. Un 403 confermerebbe che
 * quell'uuid appartiene a qualcuno, che è metà dell'informazione che non deve uscire.
 */
function personaCheImporta(bool $amministratore = false): User
{
    (new Database\Seeders\RolesAndPermissionsSeeder)->run();
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);

    $u = User::factory()->create();
    $u->assignRole(Role::findByName(
        $amministratore ? App\Enums\Role::AMMINISTRATORE->value : App\Enums\Role::COLLABORATORE->value,
        'web',
    ));

    return $u;
}

function lottoDi(User $proprietario): ImportBatch
{
    return ImportBatch::create([
        'sorgente' => 'danea',
        'user_id' => $proprietario->id,
        'stato' => ImportBatch::STATO_IN_CORSO,
    ]);
}

it('non mostra a un collega il lotto interrotto di un altro', function () {
    $alice = personaCheImporta();
    lottoDi($alice);

    $this->actingAs(personaCheImporta())
        ->get(route('import.index'))
        ->assertInertia(fn ($p) => $p->where('interrotto', null));
});

it('mostra il proprio, di lotto interrotto', function () {
    $alice = personaCheImporta();
    $lotto = lottoDi($alice);

    $this->actingAs($alice)
        ->get(route('import.index'))
        ->assertInertia(fn ($p) => $p->where('interrotto.uuid', $lotto->uuid));
});

it('chiude tutte le porte del lotto a chi non è il proprietario', function () {
    $lotto = lottoDi(personaCheImporta());
    $bob = personaCheImporta();
    $c = Condominio::create([
        'nome' => 'CONDOMINIO DI BOB',
        'codice_fiscale' => '97123456780',
        'indirizzo' => 'Via X 1',
    ]);

    // Le porte in lettura
    foreach (['import.riconoscimento', 'import.verifica', 'import.anteprima', 'import.esito'] as $rotta) {
        $this->actingAs($bob)->get(route($rotta, $lotto->uuid))->assertNotFound();
    }

    // Le porte che scrivono
    $this->actingAs($bob)
        ->put(route('import.destinazione', $lotto->uuid), ['condominio_id' => $c->id])
        ->assertNotFound();

    $this->actingAs($bob)
        ->put(route('import.decisione', $lotto->uuid), ['chiave' => 'condominio:x', 'scelta' => 'salta'])
        ->assertNotFound();

    $this->actingAs($bob)->post(route('import.conferma', $lotto->uuid))->assertNotFound();
    $this->actingAs($bob)->delete(route('import.scarta', $lotto->uuid))->assertNotFound();

    // E niente è cambiato: né una decisione scritta di straforo, né il lotto annullato.
    expect($lotto->fresh()->decisioni ?? [])->toBe([])
        ->and($lotto->fresh()->stato)->toBe(ImportBatch::STATO_IN_CORSO);
});

it('lascia passare il proprietario', function () {
    $alice = personaCheImporta();
    $lotto = lottoDi($alice);

    $this->actingAs($alice)->get(route('import.verifica', $lotto->uuid))->assertOk();
});

it('lascia passare l\'amministratore, che rimette in piedi il lavoro altrui', function () {
    $lotto = lottoDi(personaCheImporta());

    $this->actingAs(personaCheImporta(amministratore: true))
        ->get(route('import.verifica', $lotto->uuid))
        ->assertOk();
});

it('non mura i lotti caricati prima che esistesse il proprietario', function () {
    // `user_id` è nullo sui lotti più vecchi della colonna. Chiuderli vorrebbe dire cancellare
    // del lavoro vero senza dirlo a nessuno.
    $senzaPadrone = ImportBatch::create(['sorgente' => 'danea', 'stato' => ImportBatch::STATO_IN_CORSO]);

    $this->actingAs(personaCheImporta())
        ->get(route('import.verifica', $senzaPadrone->uuid))
        ->assertOk();
});
