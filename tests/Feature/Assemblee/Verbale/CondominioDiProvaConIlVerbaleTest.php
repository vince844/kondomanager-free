<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Condominio;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Le tabelle del verbale e la cancellazione: il verbale registrato, le sue versioni e parti, il punto di partenza del registro e il registro di nomina e
 * revoca sono lavoro fatto per l'annullamento di un'importazione, e se ne vanno con il condominio di prova, dalle foglie alla radice (le chiavi sono restrict).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('il verbale registrato e il registro di nomina sono lavoro fatto; il condominio di prova se ne va con tutto', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.nomine.store', $s->c), ['amministratore' => 'Andrea Costa', 'dal' => '2025-04-10',
        'da_dove' => 'carta', 'estremi' => 'verbale del 10/04/2025'])->assertSessionHasNoErrors();

    expect((new ReflectionMethod(\App\Services\Import\AnnullamentoImportazione::class, 'impedimenti'))->invoke(app(\App\Services\Import\AnnullamentoImportazione::class), $s->c))
        ->toMatchArray(['verbali delle assemblee' => 1, 'registri dei verbali' => 1, 'righe del registro di nomina e revoca' => 1]);

    $s->c->forceFill(['is_demo' => true])->saveQuietly();
    app(\App\Actions\Condominio\CreaCondominioDimostrativoAction::class)->rimuovi($s->c->fresh());
    expect(Condominio::query()->whereKey($s->c->id)->exists())->toBeFalse();
    foreach (['assemblee_verbali', 'assemblee_verbali_parti', 'assemblee_verbali_versioni', 'assemblee_registri_verbali', 'registro_nomine'] as $tabella) {
        expect(DB::table($tabella)->count())->toBe(0, $tabella);
    }
});
