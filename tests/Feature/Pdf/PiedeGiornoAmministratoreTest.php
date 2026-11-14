<?php

/**
 * Il piede delle stampe, «Documento emesso il …», nel giorno dell'amministratore: una stampa che non passa la data la prendeva dal giorno del
 * server (UTC), e fra le 00:00 e le 02:00 di Roma scriveva ieri. La regola sta nel layout, una volta per tutte le stampe.
 */

use Carbon\Carbon;
use Illuminate\Support\Facades\View;

it('alle 00:30 del 01/01/2027 a Roma (le 23:30 del 31/12/2026 in UTC) il piede dice il 01/01/2027', function () {
    $this->travelTo(Carbon::parse('2027-01-01 00:30:00', 'Europe/Rome'));
    expect(now()->toDateString())->toBe('2026-12-31');

    $html = View::make('pdf.base')->render();

    expect($html)->toContain('Documento emesso il 01/01/2027')
        ->and($html)->not->toContain('Documento emesso il 31/12/2026');
});

it('con il piede compatto vale lo stesso, e una data passata dalla stampa resta la sua', function () {
    $this->travelTo(Carbon::parse('2027-01-01 00:30:00', 'Europe/Rome'));

    expect(View::make('pdf.base', ['piede_compatto' => true])->render())->toContain('Documento emesso il 01/01/2027')
        ->and(View::make('pdf.base', ['data_emissione_stampe' => Carbon::parse('2026-11-05')])->render())->toContain('Documento emesso il 05/11/2026');
});

it('nessuna stampa scrive il giorno o l\'ora con il giorno del server: i piedi propri e gli «Stampato il» usano quello dell\'amministratore', function () {
    $trovati = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/pdf'), FilesystemIterator::SKIP_DOTS)) as $file) {
        foreach (file($file->getPathname()) as $n => $riga) {
            if (preg_match('/\bnow\(\)|\btoday\(\)|Carbon::(now|today)\(/', $riga)) {
                $trovati[] = str_replace(resource_path('views/pdf') . '/', '', $file->getPathname()) . ':' . ($n + 1);
            }
        }
    }

    expect($trovati)->toBe([]);
});

it('alle 00:30 del 01/01/2027 a Roma lo stato patrimoniale, che ha un piede suo, dice il 01/01/2027 nel piede e in «Stampato il»', function () {
    $this->travelTo(Carbon::parse('2027-01-01 00:30:00', 'Europe/Rome'));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente = \App\Models\User::factory()->create();
    $utente->assignRole($ruolo);
    $condominio = \App\Models\Condominio::factory()->create();
    $esercizio = \App\Models\Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => 'aperto']);
    $dati = [];
    View::composer('pdf.gestionale.stato_patrimoniale', function ($v) use (&$dati) {
        $dati = $v->getData();
    });

    $this->actingAs($utente)->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$condominio, $esercizio]))->assertOk();
    $html = View::make('pdf.gestionale.stato_patrimoniale', $dati)->render();

    expect($html)->toContain('Documento emesso il 01/01/2027')
        ->and($html)->toContain('Stampato il <b class="forte">01/01/2027</b>')
        ->and($html)->not->toContain('31/12/2026');
});

it('alle 00:30 del 01/01/2027 a Roma il Libro Giornale scrive «Stampato il 01/01/2027 00:30», non le 23:30 del giorno prima', function () {
    $this->travelTo(Carbon::parse('2027-01-01 00:30:00', 'Europe/Rome'));
    $condominio = \App\Models\Condominio::factory()->create();
    $esercizio = \App\Models\Esercizio::factory()->create(['condominio_id' => $condominio->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);

    $html = View::make('pdf.gestionale.libro_giornale', [
        'condominio' => $condominio, 'esercizio' => $esercizio, 'righe' => [], 'totale_dare' => 0, 'totale_avere' => 0,
        'filtri' => ['data_da' => null, 'data_a' => null, 'stato' => null, 'tipo_movimento' => null, 'search' => null],
    ])->render();

    expect($html)->toContain('Stampato il <strong>01/01/2027 00:30</strong>');
});
