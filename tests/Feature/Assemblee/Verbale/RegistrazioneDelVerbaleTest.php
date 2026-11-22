<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoVerbale;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Models\Documento;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\Verbale\RegistroDeiVerbali;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * La registrazione del verbale sottoscritto (V7, scene 3 e 4): la prima volta da dove parte il registro (il libro di carta arrivato al n. 41: il primo è il
 * 42), correggibile finché non c'è un verbale registrato; il numero proposto e correggibile, mai uno già usato (nemmeno dentro il libro); «Sottoscritto il»
 * fra il giorno della riunione e oggi; la copia (pdf, p7m, immagine) fra i documenti dell'assemblea, fuori dal portale; `numero_verbale` e la copia
 * sull'assemblea, e la frase della delibera che dice «(verbale n. 42)»; «Sostituisci la copia» con il perché; «Annulla» con il perché: la riga resta, il 43
 * sostituisce il 42; il permesso «Registra il verbale sottoscritto».
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la prima registrazione chiede da dove parte il registro; con il libro al n. 41 il verbale è il 42, sull\'assemblea e nella frase della delibera', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);

    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['partenza' => null])->assertSessionHasErrors(['partenza']);
    expect($verbale->refresh()->stato)->toBe(StatoVerbale::BOZZA);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();

    $verbale->refresh();
    $copia = Documento::query()->findOrFail($verbale->documento_id);
    expect($verbale->stato)->toBe(StatoVerbale::REGISTRATO)->and($verbale->numero)->toBe(42)->and($verbale->sottoscritto_il->format('Y-m-d'))->toBe('2026-12-15')
        ->and($a->refresh()->numero_verbale)->toBe('42')->and($a->verbale_documento_id)->toBe($verbale->documento_id)
        ->and($copia->documentable_type)->toBe($a->getMorphClass())->and((bool) $copia->is_published)->toBeFalse()
        ->and(DeliberaInParole::breve(\App\Models\Assemblee\Delibera::query()->where('assemblea_id', $a->id)->orderBy('id')->firstOrFail()))->toEndWith('(verbale n. 42)')
        ->and(RegistroDeiVerbali::registrato($a))->toMatchArray(['numero' => 42, 'sottoscritto_il' => '2026-12-15', 'documento_id' => $verbale->documento_id])
        ->and($verbale->versioni()->reorder()->orderByDesc('id')->value('perche'))->toBe('registrato n. 42');
    // Dopo il primo verbale il punto di partenza non si cambia più.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.registro.partenza', $s->c), ['scelta' => 'da_uno'])
        ->assertSessionHasErrors(['partenza' => 'Il registro ha già un verbale registrato: il numero va solo avanti, e il punto di partenza non si cambia più.']);
});

it('il numero si corregge ma non può essere già usato, né dentro il libro di carta; «Sottoscritto il» fra la riunione e oggi; la copia è pdf, p7m o immagine', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['numero' => 40])->assertSessionHasErrors(['numero' => 'Il n. 40 è nel libro dei verbali di carta, che arriva al n. 41: il numero va solo avanti.']);
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['sottoscritto_il' => '2026-12-14'])->assertSessionHasErrors(['sottoscritto_il']);
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['copia' => UploadedFile::fake()->create('verbale.docx', 10)])->assertSessionHasErrors(['copia']);
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['numero' => 45, 'copia' => VerbaleDiViaRoma12::copia('verbale.pdf.p7m')])
        ->assertSessionHasErrors(['numero' => 'Salti i numeri 42–44? Il registro va solo avanti: un numero saltato non torna. Conferma il salto, o usa il 42.']);
    VerbaleDiViaRoma12::registra($this, $s, $verbale, ['numero' => 45, 'salto' => true, 'copia' => VerbaleDiViaRoma12::copia('verbale.pdf.p7m')])->assertSessionHasNoErrors();
    expect($verbale->refresh()->numero)->toBe(45)
        ->and(app(RegistroDeiVerbali::class)->numeroUsato($s->c, 'condominio', 45))->toBe('Il n. 45 è già nel registro.')
        ->and(app(RegistroDeiVerbali::class)->prossimoNumero($s->c, 'condominio'))->toBe(46);
});

it('registrare vuole il permesso suo: chi gestisce soltanto riceve 403', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    $chi = ViaRoma12::utente([\App\Enums\Permission::VIEW_ASSEMBLEE, \App\Enums\Permission::MANAGE_ASSEMBLEE, \App\Enums\Permission::ACCESS_ADMIN_PANEL]);
    $this->actingAs($chi)->post(route('admin.gestionale.assemblee.verbale.registra', [$s->c, $a, $verbale]), ['copia' => VerbaleDiViaRoma12::copia(),
        'sottoscritto_il' => '2026-12-15'])->assertForbidden();
    expect($verbale->refresh()->stato)->toBe(StatoVerbale::BOZZA);
});

it('«Sostituisci la copia»: il numero e il giorno della firma restano, la copia di prima resta fra le versioni, il perché è nella riga', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $prima = (int) \App\Models\RegistroModifica::query()->max('id');

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.copia', [$s->c, $a, $verbale]), ['copia' => VerbaleDiViaRoma12::copia('verbale_completo.pdf'),
        'perche' => 'Nelle foto mancava la pagina 4, l\'elenco dei presenti in calce.'])->assertSessionHasNoErrors();

    $riga = \App\Models\RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'verbale')->firstOrFail();
    expect($verbale->refresh()->numero)->toBe(42)->and(\App\Models\DocumentoVersione::query()->where('documento_id', $verbale->documento_id)->count())->toBe(1)
        ->and($riga->riepilogo)->toContain('copia sottoscritta sostituita: verbale_completo.pdf')->and($riga->perche)->toBe('Nelle foto mancava la pagina 4, l\'elenco dei presenti in calce.');
});

it('«Annulla» con il perché: la riga 42 resta annullata, il testo torna in bozza, e la registrazione giusta prende il 43 e dice che sostituisce il 42', function () {
    [$s, $a] = VerbaleDiViaRoma12::salaChiusa($this);
    $verbale = VerbaleDiViaRoma12::genera($this, $s, $a);
    VerbaleDiViaRoma12::registra($this, $s, $verbale)->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.annulla', [$s->c, $a, $verbale]), ['motivo' => ''])->assertSessionHasErrors(['motivo']);

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.verbale.annulla', [$s->c, $a, $verbale]),
        ['motivo' => 'La scansione caricata è dell\'assemblea di Corso Italia.'])->assertSessionHasNoErrors();

    $nuovo = VerbaleDellAssemblea::query()->where('assemblea_id', $a->id)->orderByDesc('id')->firstOrFail();
    expect($verbale->refresh()->stato)->toBe(StatoVerbale::ANNULLATO)->and($verbale->numero)->toBe(42)
        ->and($a->refresh()->numero_verbale)->toBeNull()->and($nuovo->stato)->toBe(StatoVerbale::BOZZA)->and($nuovo->id)->not->toBe($verbale->id);

    VerbaleDiViaRoma12::registra($this, $s, $nuovo, ['numero' => 42])->assertSessionHasErrors(['numero' => 'Il n. 42 è già nel registro, annullato: un numero annullato non torna.']);
    VerbaleDiViaRoma12::registra($this, $s, $nuovo)->assertSessionHasNoErrors();
    expect($nuovo->refresh()->numero)->toBe(43)->and($nuovo->sostituisce_id)->toBe($verbale->id)->and($a->refresh()->numero_verbale)->toBe('43');
});
