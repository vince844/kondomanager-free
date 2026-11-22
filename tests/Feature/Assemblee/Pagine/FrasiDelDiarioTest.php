<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\DiarioDellAssemblea;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/../Convocazione/ConvocazioneDiViaRoma12.php';

/**
 * Il diario dell'assemblea senza parole ripetute. Dentro il diario di un'assemblea il suo nome non serve: le sue righe cominciano dal fatto («Convocata»,
 * «Delega di … a …, ricevuta su carta il …», «Preparata: …»). Le righe di un'altra cosa (un punto, una delibera) tengono il loro nome. Gli invii non hanno
 * più il badge «Invio»: la frase dice già che cosa è («Raccomandata a …: spedita il …»), e il filtro «Invii» li separa.
 *
 * Che cosa non copre: il cassetto a video (`CassettoDelDiario.test.ts`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-12-02 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('le righe dell\'assemblea senza il suo nome davanti; quelle di un punto con il nome; gli invii senza badge', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $nome = 'Assemblea ordinaria del 15/12/2026';
    $punto = $a->punti()->where('numero', 1)->first();
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.punti.update', [$s->c, $a, $punto]), ['testo' => 'Il rendiconto del 2025'])
        ->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $carla = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$carla->id], 'canale' => 'raccomandata',
        'data' => '2026-12-02', 'numeri' => [$carla->id => '61 2345 6789']])->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone['carla']->id,
        'delegato' => ['nome' => 'Paolo Ferraris'], 'ricevuta_il' => '2026-12-01'])->assertSessionHasNoErrors();

    $righe = DiarioDellAssemblea::righe($a->refresh())['righe'];
    $frasi = array_column($righe, 'frase');
    expect($frasi)->toContain('Convocata')
        ->toContain('Delega di Neri Carla a Paolo Ferraris, ricevuta su carta il 01/12/2026')
        ->toContain('Lettere della convocazione stampate: 5')
        ->toContain('Preparata: 6 punti, prima convocazione 14/12/2026 alle 08:00, seconda 15/12/2026 alle 18:30')
        ->toContain('Raccomandata a Neri Carla: spedita il 02/12, n. 61 2345 6789')
        ->and(array_values(array_filter($frasi, fn ($f) => str_contains((string) $f, $nome))))->toBe([])
        // Le righe di un punto tengono il nome del punto.
        ->and(array_values(array_filter($frasi, fn ($f) => str_starts_with((string) $f, 'Punto 1'))))->toHaveCount(1)
        ->and(collect($righe)->every(fn ($r) => ! array_key_exists('badge', $r)))->toBeTrue()
        ->and(DiarioDellAssemblea::RIGA)->not->toContain('badge');
});
