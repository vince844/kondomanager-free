<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Http\Controllers\Assemblee\PerLePagine;
use App\Models\Evento;
use App\Services\Assemblee\Convocazione\CompitiDellaConvocazione;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\Inbox\FasceDeiCompiti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * La prima convocazione troppo vicina (decisione 665, rilievo C45; completa la decisione 662 (2)): l'ordinaria di Via Roma 12 con la prima lunedì 14/12,
 * guardata giovedì 10/12, quando il giorno di legge delle raccomandate (03/12) e l'ultimo giorno di ricezione (09/12) sono passati. «Spedisco le raccomandate
 * entro» propone oggi, e con lui dicono oggi la strada dei sei passi, la riga di ogni persona e il compito dell'Action inbox con il suo promemoria: «entro
 * oggi 10/12», non un giorno passato. Il compito non risulta scaduto, e dice «fuori dai cinque giorni dell'art. 66».
 *
 * Che cosa non copre: con il tempo dei cinque giorni il giorno di legge resta com'è (`PromemoriaDellaConvocazioneTest`, `PassiDellAssembleaTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la strada, la riga di ogni persona e il compito dicono oggi, con l\'avviso; il compito non è scaduto; il promemoria dice oggi (C45)', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    $this->actingAs($s->admin);

    // La strada dei sei passi.
    expect(collect(PerLePagine::passi($a->refresh()))->keyBy('chiave')['convocazione']['riga'])->toStartWith('9 da spedire · entro oggi 10/12');

    // Il compito: oggi, con l'avviso, e non scaduto.
    app(CompitiDellaConvocazione::class)->sincronizza($a->refresh());
    $compito = Evento::query()->where('meta->type', 'spedire_convocazione')->where('meta->context->assemblea_id', $a->id)->where('is_completed', false)->sole();
    expect($compito->title)->toBe('Spedire la convocazione entro il 10/12, fuori dai cinque giorni dell\'art. 66')
        ->and($compito->start_time->format('Y-m-d'))->toBe('2026-12-10')
        ->and(in_array(FasceDeiCompiti::oggi()->fascia($compito->start_time->format('Y-m-d'), 'spedire_convocazione'), FasceDeiCompiti::SCADUTE, true))->toBeFalse();

    // La riga di ogni persona, con le lettere stampate.
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $righe = collect(app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['convocati']['righe']);
    expect($righe->firstWhere('nome', 'Russo Marco'))->toMatchArray(['stato' => 'stampata', 'dettaglio' => 'entro oggi 10/12'])
        ->and($righe->firstWhere('nome', 'Russo Anna')['dettaglio'])->toBe('entro oggi 10/12');

    // Il promemoria del giorno: oggi, fuori dai cinque giorni.
    ConvocazioneDiViaRoma12::oggi('2026-12-10 08:15');
    $this->artisan('assemblee:promemoria-convocazioni')->assertSuccessful();
    $email = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage())
        ->filter(fn ($m) => str_starts_with((string) $m->getSubject(), 'Convocazione da spedire'))->last();
    expect($email->getSubject())->toBe('Convocazione da spedire entro il 10/12: assemblea ordinaria del 15/12/2026')
        ->and(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $email->getHtmlBody()), ENT_QUOTES)))
        ->toContain('Il giorno limite è oggi, giovedì 10/12, fuori dai cinque giorni dell\'art. 66: spedisci appena puoi.');
});
