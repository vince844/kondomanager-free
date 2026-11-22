<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\Assemblea;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (§4.7 del progetto della tappa 2; §13 rilievo 15): «Annulla e riconvoca» dalla rotta. Si riconvoca un'assemblea già convocata, con il motivo;
 * la vecchia resta annullata con i suoi invii e i suoi convocati; la nuova copia l'ordine del giorno, ha le date nuove e un elenco nuovo da fissare; una riga
 * nel registro con il motivo. Prima della spedizione la scheda dice che le date si cambiano dalla preparazione.
 *
 * Che cosa non copre: «Rinvia» dalla sala (tappa 3); la copia dei memo (coperta nel motore).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-12-02 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('si riconvoca un\'assemblea convocata: la vecchia annullata con i suoi invii, la nuova con l\'ordine del giorno e le date nuove', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin);
    expect(app(StatoDellaConvocazione::class)->perLaPagina($a)['riconvoca']['si_puo'])->toBeFalse();
    // Si riconvoca un'assemblea convocata: la stampa non basta, la fa «convocata» la prima spedizione registrata.
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    expect(app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['riconvoca']['si_puo'])->toBeFalse();
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [ConvocazioneDiViaRoma12::invio($a, $s, 'carla')->id],
        'canale' => 'raccomandata', 'data' => '2026-12-02'])->assertSessionHasNoErrors();
    expect(app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['riconvoca']['si_puo'])->toBeTrue();
    $prima = ViaRoma12::ultimaRiga();

    $this->postJson(route('admin.gestionale.assemblee.riconvoca', [$s->c, $a]), ['motivo' => '', 'sedute' => []])->assertStatus(422)
        ->assertJsonValidationErrors(['motivo', 'sedute']);
    $this->post(route('admin.gestionale.assemblee.riconvoca', [$s->c, $a]), ['motivo' => 'Le raccomandate non arrivano in tempo',
        'sedute' => [['convocazione' => 'prima', 'fissata_il' => '2026-12-21 08:00'], ['convocazione' => 'seconda', 'fissata_il' => '2026-12-22 18:30']]])
        ->assertRedirect();

    $nuova = Assemblea::query()->where('riconvocata_da_id', $a->id)->firstOrFail();
    expect($a->refresh()->stato)->toBe(StatoAssemblea::ANNULLATA)
        ->and(RegistroInvio::query()->where('documentabile_id', $a->id)->count())->toBe(5)
        ->and($nuova->punti()->count())->toBe(6)->and($nuova->data->format('Y-m-d'))->toBe('2026-12-22')
        ->and($nuova->convocati()->count())->toBe(0)
        ->and(ConvocazioneDiViaRoma12::righeDelRegistro($prima)->first(fn ($r) => str_contains((string) $r->riepilogo, 'annullata e riconvocata'))?->perche)
        ->toBe('Le raccomandate non arrivano in tempo');
});
