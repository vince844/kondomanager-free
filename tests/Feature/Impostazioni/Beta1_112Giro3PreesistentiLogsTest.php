<?php

/**
 * Coda 464 (1) e Coda 465 — la pagina «Audit & Logs», dal lato del server.
 *
 * Le pagine del registro delle email si sfogliano con i pulsanti della paginazione, che la pagina disegna dai `links` del paginatore (la prova della
 * pagina: `impostazioniLogsPaginazione.test.ts`): qui si fissa che cosa il controller manda davvero, le etichette nella lingua dell'applicazione
 * comprese. La scheda «Attività Sistema» legge un elenco che nessuno compila (la query è commentata): il controller non manda più la proprietà vuota, e la
 * pagina non ha più la scheda finché non ci sono dati.
 */

use App\Enums\Role;
use App\Models\MailLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(Role::AMMINISTRATORE->value);
});

/** Quindici invii nel registro: dieci stanno nella prima pagina, cinque nella seconda. */
function giro3QuindiciInvii(): void
{
    foreach (range(1, 15) as $n) {
        MailLog::create([
            'recipient' => "destinatario{$n}@studio.example",
            'subject' => "Avviso {$n}",
            'mailer' => 'smtp',
            'status' => 'sent',
            'error_message' => null,
            'sent_at' => now()->subMinutes($n),
        ]);
    }
}

it('Coda 465 — il controller non manda più la proprietà «activityLogs» alla pagina', function () {
    giro3QuindiciInvii();

    $this->actingAs($this->amministratore)
        ->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('impostazioni/impostazioniLogs')
            ->has('mailLogs')
            ->has('filters')
            ->missing('activityLogs'));
});

it('Coda 464 — la prima pagina del registro ha dieci righe e quattro link, e il primo non porta da nessuna parte', function () {
    giro3QuindiciInvii();

    $this->actingAs($this->amministratore)
        ->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('mailLogs.data', 10)
            ->where('mailLogs.total', 15)
            ->where('mailLogs.current_page', 1)
            ->where('mailLogs.last_page', 2)
            ->has('mailLogs.links', 4)
            // «Precedente» sulla prima pagina: nessun indirizzo.
            ->where('mailLogs.links.0.url', null)
            ->where('mailLogs.links.1.active', true)
            ->where('mailLogs.links.2.active', false)
            ->where('mailLogs.links.2.url', fn ($url) => str_ends_with($url, '?mail_page=2'))
            ->where('mailLogs.links.3.url', fn ($url) => str_ends_with($url, '?mail_page=2')));
});

it('Coda 464 — le etichette di «precedente» e «successiva» arrivano nella lingua dell\'applicazione, con le entità HTML, e la pagina le riconosce dalla posizione', function () {
    giro3QuindiciInvii();

    $etichette = $this->actingAs($this->amministratore)
        ->get(route('logs.index'))
        ->viewData('page')['props']['mailLogs']['links'];

    // In italiano non contengono la parola inglese «Previous»: la pagina che le riconoscesse da quella le stamperebbe per esteso, dentro un pulsante quadrato.
    expect(app()->getLocale())->toBe('it')
        ->and($etichette[0]['label'])->toBe('&laquo; Precedente')
        ->and($etichette[3]['label'])->toBe('Successiva &raquo;')
        ->and($etichette[0]['label'])->not->toContain('Previous')
        ->and($etichette[3]['label'])->not->toContain('Next');
});

it('Coda 464 — la seconda pagina serve le righe che restavano fuori, e «successiva» non porta da nessuna parte', function () {
    giro3QuindiciInvii();

    $this->actingAs($this->amministratore)
        ->get(route('logs.index', ['mail_page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('mailLogs.data', 5)
            ->where('mailLogs.current_page', 2)
            ->where('mailLogs.links.0.url', fn ($url) => str_ends_with($url, '?mail_page=1'))
            ->where('mailLogs.links.3.url', null));
});

it('Coda 464 — con una ricerca in corso i link delle pagine la tengono', function () {
    giro3QuindiciInvii();

    $this->actingAs($this->amministratore)
        ->get(route('logs.index', ['search' => 'studio.example']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.search', 'studio.example')
            ->where('mailLogs.links.2.url', fn ($url) => str_contains($url, 'search=studio.example') && str_contains($url, 'mail_page=2')));
});
