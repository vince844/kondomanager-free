<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Assemblee\ConvocatoDellAssemblea;
use App\Models\Documento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Convocazione\EmissioneDelleConvocazioni;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §4.3 del progetto della tappa 2): «Stampa le lettere» dalla rotta. Un PDF per persona conservato come documento della sua riga, con
 * l'impronta; una riga «stampata» per persona con i punti del suo avviso; il convocato fissato all'invio con i dati di quel giorno; la stampa che non fa
 * «convocata» (la fa la prima spedizione registrata, `SpeditoAllaSpedizioneTest`); una riga sola nel registro con i convocati fra i soggetti; il PDF unico e lo .zip per persona; il tetto; l'avviso del conduttore con
 * i soli suoi punti (decisione 566); la seconda stampa che non rifà chi ha già l'avviso.
 *
 * Che cosa non copre: le PEC (`PecDellaConvocazioneTest`); la spedizione e le consegne (`ConsegneDellaConvocazioneTest`); l'aspetto del PDF (le immagini
 * nel rapporto).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('«Stampa le lettere»: un PDF per persona conservato con l\'impronta, la riga stampata, il convocato fissato, l\'assemblea in preparazione, una riga nel registro', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $prima = ViaRoma12::ultimaRiga();

    $r = $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf']);
    $r->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(str_starts_with((string) $r->getContent(), '%PDF'))->toBeTrue()
        ->and($r->headers->get('X-Avvisi'))->toBe('5');

    // Su carta: Russo Anna e Ferri Luca a mano, Russo Marco, Neri Carla, Gallo Enzo per raccomandata; Bianchi Anna non ha un mezzo, le PEC non si stampano.
    $righe = RegistroInvio::query()->where('documentabile_id', $a->id)->where('tipo', 'convocazione')->get();
    expect($righe)->toHaveCount(5)
        ->and($righe->pluck('stato')->unique()->all())->toBe(['stampata'])
        ->and($righe->pluck('canale')->unique()->all())->toBe(['stampa']);
    $carla = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    $doc = Documento::find($carla->dati['documento_id']);
    expect($doc)->not->toBeNull()
        ->and(hash('sha256', (string) app(ArchivioDocumenti::class)->leggi($doc->path)))->toBe($carla->impronta)
        ->and($carla->dati['mezzo'])->toBe('raccomandata')
        ->and(array_column($carla->dati['punti'], 'numero'))->toBe([1, 2, 3, 4, 5, 6]);
    $luca = ConvocazioneDiViaRoma12::invio($a, $s, 'luca');
    expect(array_column($luca->dati['punti'], 'numero'))->toBe([5]);

    $fissato = ConvocatoDellAssemblea::query()->where('assemblea_id', $a->id)->where('anagrafica_id', $s->persone['carla']->id)->first();
    expect($fissato->nome)->toBe('Neri Carla')->and($fissato->mezzo->value)->toBe('raccomandata')->and($fissato->invio_id)->toBe($carla->id)
        ->and($a->refresh()->stato)->toBe(StatoAssemblea::IN_PREPARAZIONE);

    // La stampa non spedisce: niente «convocata», e la riga dice le lettere stampate.
    $registro = ConvocazioneDiViaRoma12::righeDelRegistro($prima);
    expect($registro->pluck('riepilogo')->all())->not->toContain('Assemblea ordinaria del 15/12/2026 convocata')
        ->toContain('Lettere della convocazione stampate: 5');
});

it('la seconda stampa non rifà chi ha già l\'avviso; lo .zip ha un PDF per persona', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf', 'anagrafiche' => [$s->persone['carla']->id]])
        ->assertOk()->assertHeader('X-Avvisi', '1');
    $zip = $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'zip']);
    $zip->assertOk()->assertHeader('Content-Type', 'application/zip')->assertHeader('X-Avvisi', '4');
    $file = $zip->baseResponse->getFile()->getPathname();
    $z = new ZipArchive();
    $z->open($file);
    $nomi = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $nomi[] = $z->getNameIndex($i);
    }
    sort($nomi);
    expect($nomi)->toBe(['convocazione-2026-12-15-ferri-luca.pdf', 'convocazione-2026-12-15-gallo-enzo.pdf', 'convocazione-2026-12-15-russo-anna.pdf',
        'convocazione-2026-12-15-russo-marco.pdf']);

    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])
        ->assertStatus(422)->assertJsonPath('errors.convocazione.0', EmissioneDelleConvocazioni::NIENTE_DA_STAMPARE);
});

it('l\'avviso del conduttore ha solo il suo punto e la frase della decisione 566; quello di un condòmino tutti i punti e la delega intestata', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $e = app(EmissioneDelleConvocazioni::class);
    $voci = collect(app(\App\Services\Assemblee\Convocazione\ElencoDeiConvocati::class)->per($a))->keyBy('nome');

    $luca = $e->avviso($a, $voci['Ferri Luca'], 'a_mano');
    expect(array_column($luca['punti'], 'numero'))->toBe([5])
        ->and($luca['conduttore'])->toStartWith('Lei è convocato per il punto 5, dove vota al posto del proprietario')
        ->and($luca['delega']['poteri'])->toEndWith('per il punto 5.');
    $rossi = $e->avviso($a, $voci['Rossi Mario'], 'pec');
    expect($rossi['punti'])->toHaveCount(6)->and($rossi['conduttore'])->toBeNull()
        // La delega in una forma che vale per tutti: l'anagrafica non ha il genere.
        ->and($rossi['delega']['testo'])->toBe('Chi firma, Rossi Mario, Int. 3 e box 21, delega')
        ->and($rossi['delega']['poteri'])->toStartWith('a rappresentare chi firma nell\'assemblea ordinaria')
        ->and($rossi['delega']['avvertenza'])->toStartWith('Non si può delegare l\'amministratore (art. 67 c. 5 disp. att. c.c.)')
        ->and($rossi['sedute'][0])->toBe(['nome' => 'Prima convocazione', 'quando' => 'lunedì 14/12/2026 alle 08:00', 'dove' => 'Sala condominiale, Via Roma 12']);
});

it('oltre il tetto di 200 avvisi per volta la stampa non parte; un\'assemblea senza punti non si spedisce', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $vuota = ConvocazioneDiViaRoma12::ordinaria($s, [], []);
    $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $vuota]), ['formato' => 'pdf'])
        ->assertStatus(422)->assertJsonPath('errors.convocazione.0', 'L\'ordine del giorno è vuoto: aggiungi i punti prima di spedire.');

    $tetto = (new ReflectionClass(EmissioneDelleConvocazioni::class))->getMethod('dentroIlTetto');
    expect(fn () => $tetto->invoke(app(EmissioneDelleConvocazioni::class), 201))->toThrow(\Illuminate\Validation\ValidationException::class)
        ->and(fn () => $tetto->invoke(app(EmissioneDelleConvocazioni::class), 200))->not->toThrow(\Illuminate\Validation\ValidationException::class);
});
