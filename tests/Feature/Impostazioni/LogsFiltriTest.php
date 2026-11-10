<?php

use App\Enums\Role;
use App\Models\MailLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(Role::AMMINISTRATORE->value);

    // Record 1: inviata via smtp oggi
    $this->log1 = MailLog::create([
        'recipient' => 'alfa@studio.example',
        'subject' => 'Avviso convocazione',
        'mailer' => 'smtp',
        'status' => 'sent',
        'error_message' => null,
        'sent_at' => now()->startOfDay()->addHours(10),
    ]);

    // Record 2: non partita (log) ieri
    $this->log2 = MailLog::create([
        'recipient' => 'beta@studio.example',
        'subject' => 'Sollecito rate',
        'mailer' => 'log',
        'status' => 'logged',
        'error_message' => null,
        'sent_at' => now()->subDay()->startOfDay()->addHours(12),
    ]);

    // Record 3: fallita via smtp 2 giorni fa
    $this->log3 = MailLog::create([
        'recipient' => 'gamma@studio.example',
        'subject' => 'Rendiconto annuale',
        'mailer' => 'smtp',
        'status' => 'failed',
        'error_message' => 'Connection refused',
        'sent_at' => now()->subDays(2)->startOfDay()->addHours(14),
    ]);
});

test('i filtri di stato, mailer, data e paginazione funzionano correttamente', function () {
    // Filtro per stato: failed
    $resStatus = $this->actingAs($this->amministratore)
        ->get(route('logs.index', ['status' => 'failed']))
        ->assertOk();
    $dataStatus = collect($resStatus->viewData('page')['props']['mailLogs']['data']);
    expect($dataStatus->pluck('id'))->toContain($this->log3->id)
        ->and($dataStatus->pluck('id'))->not->toContain($this->log1->id)
        ->and($dataStatus->pluck('id'))->not->toContain($this->log2->id);

    // Filtro per mailer: log
    $resMailer = $this->actingAs($this->amministratore)
        ->get(route('logs.index', ['mailer' => 'log']))
        ->assertOk();
    $dataMailer = collect($resMailer->viewData('page')['props']['mailLogs']['data']);
    expect($dataMailer->pluck('id'))->toContain($this->log2->id)
        ->and($dataMailer->pluck('id'))->not->toContain($this->log1->id);

    // Filtro per data: solo oggi
    $resDate = $this->actingAs($this->amministratore)
        ->get(route('logs.index', ['date_from' => now()->toDateString(), 'date_to' => now()->toDateString()]))
        ->assertOk();
    $dataDate = collect($resDate->viewData('page')['props']['mailLogs']['data']);
    expect($dataDate->pluck('id'))->toContain($this->log1->id)
        ->and($dataDate->pluck('id'))->not->toContain($this->log2->id)
        ->and($dataDate->pluck('id'))->not->toContain($this->log3->id);

    // Filtro per paginazione (per_page)
    $resPerPage = $this->actingAs($this->amministratore)
        ->get(route('logs.index', ['per_page' => 25]))
        ->assertOk();
    expect($resPerPage->viewData('page')['props']['mailLogs']['per_page'])->toBe(25);
});

