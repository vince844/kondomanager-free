<?php

/**
 * # La dashboard del pannello porta solo ciò che si può aprire (decisione 137)
 *
 * La dashboard del pannello mette nella pagina le ultime segnalazioni (anche private, con l'anagrafica di chi le ha
 * aperte), comunicazioni, documenti e le scadenze dei prossimi giorni. Ogni blocco arriva nella pagina solo a chi può
 * aprire il suo elenco nel pannello, con la stessa domanda dell'elenco: per le segnalazioni, le comunicazioni e i
 * documenti la policy `view` sul modello vuoto (che è ciò che l'elenco riceve), per le scadenze `viewAny`. Le cifre in
 * testa alla pagina (condomìni, segnalazioni aperte, scadenze imminenti, spazio) restano: sono conteggi, non contenuti.
 *
 * Cosa NON copre: come la pagina mostra un blocco che manca (`resources/js/pages/dashboard/Dashboard.test.ts`); la
 * dashboard del portale (`ScadenzeNellaDashboardDelPortaleTest`).
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\VisibilityStatus;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role as RuoloSpatie;

/** I quattro blocchi della dashboard e l'elenco del pannello di ciascuno. */
const DASHBOARD_PANNELLO_BLOCCHI = [
    'segnalazioni' => 'admin.segnalazioni.index',
    'comunicazioni' => 'admin.comunicazioni.index',
    'documenti' => 'admin.documenti.index',
    'eventi' => 'admin.eventi.index',
];

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $staff = User::factory()->create(['email_verified_at' => now()]);
    $mario = User::factory()->create(['email_verified_at' => now()]);
    Anagrafica::create(['user_id' => $mario->id, 'nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'RSSMRA80A01H501U'])
        ->condomini()->attach($aurora->id);

    Segnalazione::create([
        'subject' => 'Privata di Mario sulla muffa', 'description' => 'x', 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $aurora->id, 'created_by' => $mario->id, 'is_private' => true, 'is_published' => true,
        'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ]);
    Comunicazione::create([
        'subject' => 'Turni delle pulizie riservati', 'description' => 'x', 'priority' => 'media', 'created_by' => $staff->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => false,
    ])->condomini()->attach($aurora->id);
    Documento::create([
        'name' => 'Verbale riservato di giugno', 'description' => 'x', 'path' => 'documenti/verbale.pdf', 'mime_type' => 'application/pdf',
        'file_size' => 9, 'created_by' => $staff->id, 'is_published' => true, 'is_approved' => true,
    ]);
    Evento::create([
        'title' => 'Assemblea riservata', 'start_time' => now()->addDays(3), 'end_time' => now()->addDays(3)->addHour(),
        'created_by' => $staff->id, 'visibility' => VisibilityStatus::PUBLIC->value, 'is_approved' => true,
    ])->condomini()->attach($aurora->id);
});

function dashboardPannelloUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

function dashboardPannelloRuolo(array $permessi): string
{
    RuoloSpatie::findOrCreate('Su misura', 'web')->syncPermissions(array_merge([Permission::ACCESS_ADMIN_PANEL->value], $permessi));

    return 'Su misura';
}

/** I blocchi che la dashboard del pannello manda alla pagina, in ordine. */
function dashboardPannelloBlocchi(User $user): array
{
    $props = test()->actingAs($user)->get(route('admin.dashboard'))->assertOk()->viewData('page')['props'];

    return array_values(array_intersect(array_keys(DASHBOARD_PANNELLO_BLOCCHI), array_keys($props)));
}

test('decisione 137 — chi ha il solo accesso al pannello non riceve segnalazioni, comunicazioni, documenti e scadenze nella dashboard', function () {
    $solo = dashboardPannelloUtente(dashboardPannelloRuolo([]));

    $risposta = $this->actingAs($solo)->get(route('admin.dashboard'))->assertOk();
    $props = $risposta->viewData('page')['props'];

    expect(array_intersect(array_keys(DASHBOARD_PANNELLO_BLOCCHI), array_keys($props)))->toBe([])
        ->and($props)->toHaveKey('stats');
    foreach (['Privata di Mario sulla muffa', 'RSSMRA80A01H501U', 'Turni delle pulizie riservati', 'Verbale riservato di giugno', 'Assemblea riservata'] as $dato) {
        expect($risposta->getContent())->not->toContain($dato);
    }
});

test('decisione 137 — l\'amministratore e il collaboratore ricevono i quattro blocchi, come prima', function (string $ruolo) {
    $chi = dashboardPannelloUtente($ruolo);
    $props = $this->actingAs($chi)->get(route('admin.dashboard'))->assertOk()->viewData('page')['props'];

    expect(dashboardPannelloBlocchi($chi))->toBe(['segnalazioni', 'comunicazioni', 'documenti', 'eventi'])
        ->and(json_encode($props['segnalazioni'], JSON_UNESCAPED_UNICODE))->toContain('Privata di Mario sulla muffa')
        ->and(json_encode($props['comunicazioni'], JSON_UNESCAPED_UNICODE))->toContain('Turni delle pulizie riservati')
        ->and(json_encode($props['documenti'], JSON_UNESCAPED_UNICODE))->toContain('Verbale riservato di giugno')
        ->and(json_encode($props['eventi'], JSON_UNESCAPED_UNICODE))->toContain('Assemblea riservata');
})->with([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value]);

test('decisione 137 — ogni blocco arriva solo a chi apre il suo elenco nel pannello', function (array $permessi, array $attesi) {
    $chi = dashboardPannelloUtente(dashboardPannelloRuolo($permessi));

    // L'elenco del pannello di ogni blocco: si apre esattamente a chi riceve il blocco.
    $siAprono = array_keys(array_filter(DASHBOARD_PANNELLO_BLOCCHI, fn (string $rotta) => $this->actingAs($chi)->get(route($rotta))->status() === 200));

    expect(dashboardPannelloBlocchi($chi))->toBe($attesi)
        ->and($siAprono)->toBe($attesi);
})->with([
    'Visualizza segnalazioni' => [[Permission::VIEW_SEGNALAZIONI->value], ['segnalazioni']],
    'Visualizza proprie segnalazioni' => [[Permission::VIEW_OWN_SEGNALAZIONI->value], []],
    'Visualizza comunicazioni' => [[Permission::VIEW_COMUNICAZIONI->value], ['comunicazioni']],
    'Visualizza documenti archivio' => [[Permission::VIEW_ARCHIVE_DOCUMENTS->value], ['documenti']],
    'Visualizza scadenze in agenda' => [[Permission::VIEW_EVENTS->value], ['eventi']],
]);

test('decisione 137 — la ricarica parziale delle scadenze non le porta a chi non apre l\'agenda del pannello', function () {
    $solo = dashboardPannelloUtente(dashboardPannelloRuolo([Permission::VIEW_SEGNALAZIONI->value]));
    $versione = $this->actingAs($solo)->get(route('admin.dashboard'))->assertOk()->viewData('page')['version'];

    $parziale = $this->actingAs($solo)->get(route('admin.dashboard', ['page' => 1]), [
        'X-Inertia' => 'true', 'X-Inertia-Version' => (string) $versione,
        'X-Inertia-Partial-Component' => 'dashboard/Dashboard', 'X-Inertia-Partial-Data' => 'eventi',
    ])->assertOk();

    expect($parziale->json('props'))->not->toHaveKey('eventi')
        ->and($parziale->getContent())->not->toContain('Assemblea riservata');
});
