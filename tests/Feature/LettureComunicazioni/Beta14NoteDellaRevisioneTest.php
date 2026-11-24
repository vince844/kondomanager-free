<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # La conferma di lettura all'ingresso nel core: le note della revisione e il registro delle modifiche (Coda 594, decisione 313)
 *
 * Le quattro note del server (le altre due, E5 ed E6, sono delle pagine e le provano `columnsRigaUnica.test.ts` e `PresaVisioneSpunta.test.ts`):
 * - **E1**: «Cancella le aperture registrate» toglie le aperture e poi le righe rimaste vuote; se la seconda scrittura non riesce, la prima non resta a metà.
 * - **E2**: il modello delle letture non dichiara una fabbrica che non esiste.
 * - **E3**: la scheda del pannello legge delle persone solo ciò che le serve (id, nome, account), non tutta la rubrica.
 * - **E4**: un errore nello scrivere l'apertura non fa cadere la pagina che il condòmino sta aprendo; l'errore va nel registro degli errori.
 *
 * E il registro delle modifiche della beta.13: la spunta «Chiedi la presa visione» ha la sua parola nella riga della modifica, e il ripristino di un campo
 * della comunicazione dalla «Storia» non la spegne (il modulo della pagina, senza la chiave, la scriverebbe spenta).
 *
 * Che cosa non copre: le letture nel registro (non ci vanno: le scrive il gesto del condòmino), la cancellazione delle aperture nel registro (resta nel
 * riquadro delle impostazioni).
 */

use App\Models\ComunicazioneLettura;
use App\Models\RegistroModifica;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Support\Comunicazioni\LettureComunicazione;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

require_once __DIR__ . '/LettureScenario.php';

beforeEach(function () {
    ContestoDelRegistro::azzera();
    lctPrepara($this);
    $this->withoutVite();
});

it('E1: se la cancellazione delle righe vuote non riesce, le aperture restano tutte com\'erano', function () {
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');
    lctScrivi($this->c, $this->paolo, '2026-11-11 09:00:00', '2026-11-11 09:05:00');
    DB::connection()->beforeExecuting(function (string $sql) {
        if (preg_match('/^delete from ["`]?comunicazione_letture/i', $sql)) {
            throw new RuntimeException('Il disco del database è pieno.');
        }
    });

    $this->actingAs($this->admin)->delete(route(LCT_ROTTA_CANCELLA));

    expect(lctRiga($this->c, $this->mario)->aperta_il)->toBe('2026-11-11 09:00:00')
        ->and(lctRiga($this->c, $this->paolo)->aperta_il)->toBe('2026-11-11 09:00:00')
        ->and(lctRiga($this->c, $this->paolo)->presa_visione_il)->toBe('2026-11-11 09:05:00');
});

it('E1: riuscita, toglie le aperture e le righe rimaste vuote, e lascia le prese visione', function () {
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');
    lctScrivi($this->c, $this->paolo, '2026-11-11 09:00:00', '2026-11-11 09:05:00');

    $this->actingAs($this->admin)->delete(route(LCT_ROTTA_CANCELLA))->assertRedirect();

    expect(lctRighe($this->c))->toHaveCount(1)
        ->and(lctRiga($this->c, $this->paolo)->aperta_il)->toBeNull()
        ->and(lctRiga($this->c, $this->paolo)->presa_visione_il)->toBe('2026-11-11 09:05:00');
});

it('E2: il modello delle letture non dichiara una fabbrica che non c\'è', function () {
    $fabbrica = database_path('factories/ComunicazioneLetturaFactory.php');
    $dichiara = in_array(HasFactory::class, class_uses_recursive(ComunicazioneLettura::class), true);

    expect($dichiara && ! file_exists($fabbrica))->toBeFalse('ComunicazioneLettura usa HasFactory, ma la fabbrica non esiste');
});

it('E3: la scheda del pannello legge delle persone solo id, nome e account', function () {
    lctImpostazione(true);
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');

    DB::flushQueryLog();
    DB::enableQueryLog();
    $letture = LettureComunicazione::perPannello($this->c->fresh());
    $richieste = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $sullaRubrica = $richieste->filter(fn (string $q) => preg_match('/from ["`]?anagrafiche["`]? where ["`]?anagrafiche["`]?\.["`]?id["`]? in|from ["`]?anagrafiche["`]? where ["`]?id["`]? in/i', $q))->values();
    expect($sullaRubrica)->not->toBeEmpty()
        ->and($sullaRubrica->filter(fn (string $q) => str_contains($q, '*'))->all())->toBe([])
        ->and(collect($letture['righe'])->pluck('nome')->all())->toBe(['Anna Collaboratrice', 'Giulia Senzaccount', 'Mario Rossi', 'Paolo Neri'])
        ->and(collect($letture['righe'])->firstWhere('nome', 'Giulia Senzaccount')['stato'])->toBe('senza_accesso')
        ->and(collect($letture['righe'])->firstWhere('nome', 'Mario Rossi')['stato'])->toBe('aperta');
});

it('E4: un errore nello scrivere l\'apertura non fa cadere la pagina del condòmino, e va nel registro degli errori', function () {
    Exceptions::fake();
    lctImpostazione(true);
    ComunicazioneLettura::creating(function () {
        throw new RuntimeException('Il disco del database è pieno.');
    });

    $risposta = lctApri($this, $this->mario, $this->c);

    $risposta->assertOk();
    expect(lctProps($risposta)['comunicazione'])->not->toBeNull()
        ->and(lctRighe($this->c))->toHaveCount(0);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Il disco del database è pieno.');
});

it('il registro delle modifiche: la spunta «Chiedi la presa visione» accesa dalla modifica è una riga con la parola della pagina', function () {
    $prima = (int) RegistroModifica::query()->max('id');

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $this->c), [
        'subject' => $this->c->subject, 'description' => $this->c->description, 'priority' => 'media',
        'can_comment' => true, 'is_featured' => false, 'is_published' => true, 'condomini_ids' => [$this->aurora->id],
        'chiedi_presa_visione' => true,
    ])->assertSessionHasNoErrors();

    $campi = RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'comunicazione')->get()
        ->flatMap(fn ($r) => $r->campi)->firstWhere('chiave', 'chiedi_presa_visione');
    expect($campi)->not->toBeNull()
        ->and($campi['etichetta'] . ': ' . $campi['prima'] . ' → ' . $campi['dopo'])->toBe('Chiedi la presa visione: no → sì');
});

it('il ripristino del titolo dalla «Storia» non spegne «Chiedi la presa visione»', function () {
    $this->actingAs($this->admin);
    lctChiedi($this->c, true);
    $this->c->fresh()->update(['subject' => 'Lavori al tetto e alle grondaie']);
    $riga = RegistroModifica::query()->where('oggetto_tipo', 'comunicazione')->where('oggetto_id', $this->c->id)->orderByDesc('id')->firstOrFail();

    $this->postJson(route('admin.registro-modifiche.ripristina', $riga), ['campo' => 'subject'])->assertOk();

    expect($this->c->fresh()->subject)->toBe('Lavori al tetto')
        ->and(lctChiesta($this->c->id))->toBeTrue();
});
