<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\RegistroModifica;
use App\Services\Assemblee\Convocazione\DiarioDegliInvii;
use App\Services\Assemblee\DiarioDellAssemblea;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (§6.3 e §6.4 del progetto della tappa 2): i gesti della convocazione dalla rotta, ognuno una riga del registro nell'area «Assemblee»
 * sull'assemblea, con le sue parole e le persone fra i soggetti (l'oscuramento le trova): la convocazione spedita, l'esito, la correzione con il perché, il
 * foglio delle consegne, la data delle raccomandate scelta (nel tratto dell'assemblea), il preventivo fermato, il fascicolo composto. Il «Diario» mette
 * insieme le righe del registro e gli invii, senza ripetere gli esiti.
 *
 * Che cosa non copre: le righe del registro dei gesti della preparazione (sono del motore e delle pagine).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-12-02 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function convAreaDopo(int $prima)
{
    return RegistroModifica::query()->where('id', '>', $prima)->where('area', 'assemblee')->orderBy('id')->get();
}

it('le righe della convocazione: spedita con i convocati fra i soggetti, l\'esito con la persona, la correzione con il perché, la data delle raccomandate', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $prima = ViaRoma12::ultimaRiga();

    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.convocazione.raccomandate-entro', [$s->c, $a]), ['giorno' => '2026-12-04'])->assertRedirect();
    $data = convAreaDopo($prima)->last();
    expect($data->oggetto_tipo)->toBe('assemblea')->and(collect($data->campi)->pluck('etichetta')->all())->toContain('Spedire le raccomandate entro (scelto da te)');

    // La stampa non spedisce: la riga dice le lettere stampate (la convocazione parte con la spedizione registrata).
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $spedita = convAreaDopo($prima)->first(fn ($r) => $r->riepilogo === 'Lettere della convocazione stampate: 5');
    expect($spedita)->not->toBeNull()
        ->and(collect($spedita->soggetti())->filter(fn ($x) => $x[0] === 'anagrafica')->pluck(1)->sort()->values()->all())
        ->toBe(collect(['anna', 'marco', 'carla', 'luca', 'gallo'])->map(fn ($c) => $s->persone[$c]->id)->sort()->values()->all());

    $carla = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$carla->id], 'canale' => 'raccomandata', 'data' => '2026-12-02',
        'numeri' => [$carla->id => '61 2345 6789']]);
    ConvocazioneDiViaRoma12::oggi('2026-12-09 10:00');
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $carla]), ['esito' => 'in_giacenza', 'giorno' => '2026-12-08']);
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $carla]), ['esito' => 'in_giacenza', 'giorno' => '2026-12-07', 'perche' => 'L\'avviso dice il 7']);
    $esiti = convAreaDopo($prima)->filter(fn ($r) => isset($r->lotto['invio_id']))->values();
    expect($esiti->pluck('riepilogo')->all())->toBe(['Raccomandata a Neri Carla: in giacenza, avviso del 08/12', 'Ricevuta di Neri Carla corretta: 08/12 → 07/12'])
        ->and($esiti[1]->perche)->toBe('L\'avviso dice il 7')
        ->and($esiti[0]->soggetti())->toBe([['anagrafica', $s->persone['carla']->id]]);

    // Il «Diario»: la spedizione della raccomandata dagli invii, senza badge (la frase dice già che cosa è); l'esito una volta sola, dal registro.
    $diario = DiarioDellAssemblea::righe($a, 'tutto', null);
    $frasi = collect($diario['righe'])->map(fn ($r) => [$r['frase'], $r['fonte']])->all();
    expect($frasi)->toContain(['Raccomandata a Neri Carla: spedita il 02/12, n. 61 2345 6789', 'invio'])
        ->and(collect($diario['righe'])->filter(fn ($r) => str_contains((string) $r['frase'], 'in giacenza'))->count())->toBe(1)
        ->and(app(DiarioDegliInvii::class)->righe($a)[0]['chi'])->toBe($s->admin->name);
});

it('il foglio delle consegne, il preventivo fermato e il fascicolo composto: una riga ciascuno, sull\'assemblea', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $s->gestione('Ordinaria 2026')->voce('Ordinaria 2026', 'Pulizia delle scale', 240000, ['Proprietà' => 100]);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $a->punti()->where('materia', 'preventivo')->update(['esercizio_id' => $s->e->id]);
    $prima = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.fascicolo.preventivo', [$s->c, $a]))->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $anna = ConvocazioneDiViaRoma12::invio($a, $s, 'anna');
    $this->post(route('admin.gestionale.assemblee.convocazione.foglio-firmato', [$s->c, $a]), ['foglio' => UploadedFile::fake()->create('f.pdf', 5, 'application/pdf'),
        'consegne' => [['invio_id' => $anna->id, 'giorno' => '2026-12-02']]])->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.fascicolo.componi', [$s->c, $a]))->assertOk();

    $frasi = convAreaDopo($prima)->pluck('riepilogo')->all();
    expect(collect($frasi)->contains(fn ($f) => str_starts_with((string) $f, 'Preventivo 2026 fermato per l\'assemblea: versione 1, impronta ')))->toBeTrue()
        ->and($frasi)->toContain('Consegna a mano registrata: 1 persona, con il foglio firmato')
        ->and(collect($frasi)->contains(fn ($f) => str_starts_with((string) $f, 'Fascicolo composto: 3 documenti, impronta ')))->toBeTrue();
});
