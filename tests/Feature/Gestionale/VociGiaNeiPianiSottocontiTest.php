<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il «Ricalcola» di un piano senza voci (nato con le versioni di prima) quando tutte le voci della gestione sono già in altri piani dell'esercizio, anche un
 * capitolo con le sue sottovoci chieste una per una: il programma si ferma con la causa vera e nomina i piani che hanno le voci, non con le tre verifiche
 * generiche.
 *
 * Il caso: il capitolo «Manutenzioni» con Ascensore € 600,00 e Caldaia € 400,00, nel «Preventivo 2026» come sottovoci; l'«Integrativo 2026» senza righe.
 */

use App\Models\Gestionale\Conto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

it('il «Ricalcola» del piano senza voci, con il capitolo già chiesto per sottovoci, dice che le voci sono già nei piani e nomina «Preventivo 2026»', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-12-05 10:00:00'));
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'bice')->gestione('Ordinaria 2026');
    $pc = $s->gestioni['Ordinaria 2026']['pc'];
    $capitolo = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Manutenzioni', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 0]);
    $s->voce('Ordinaria 2026', 'Ascensore', 60000, ['Proprietà' => 100])->voce('Ordinaria 2026', 'Caldaia', 40000, ['Proprietà' => 100]);
    Conto::query()->whereIn('id', [$s->voci['Ascensore']['conto']->id, $s->voci['Caldaia']['conto']->id])->update(['parent_id' => $capitolo->id]);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', 2, '2026-11-30', 'tutte_rate', true)->genera('Preventivo 2026');
    $s->pianoOrdinario('Integrativo 2026', 'Ordinaria 2026', 2, '2026-11-30', 'tutte_rate', false, true);
    expect(DB::table('piano_rate_capitoli')->where('piano_rate_id', $s->piano('Integrativo 2026')->id)->count())->toBe(0);

    session()->forget(['message', 'errors']);
    $s->ricalcola('Integrativo 2026')->assertRedirect();
    $messaggio = (string) (session('message')['message'] ?? '');
    expect(session('message')['type'] ?? null)->toBe('error')
        ->and($messaggio)->toContain('«Preventivo 2026»')
        ->and($messaggio)->toContain(\App\Services\CalcoloQuoteService::fraseVociGiaNeiPiani(collect([$s->piano('Preventivo 2026')]), ''));
});
