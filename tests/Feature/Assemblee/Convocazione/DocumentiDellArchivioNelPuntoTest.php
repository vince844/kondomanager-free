<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Documento;
use App\Services\Assemblee\Convocazione\DocumentiDeiPunti;
use App\Services\Assemblee\Convocazione\FascicoloDellAssemblea;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

require_once __DIR__ . '/DocumentiDiViaRoma12.php';

/**
 * «Aggiungi un documento» del punto prende solo i documenti dell'archivio del condominio (R18), e solo ciò che parte davvero:
 *
 * - il rogito di Rossi Mario caricato dalla pagina della sua unità non compare fra «I documenti di Via Roma 12» e «Aggiungi» non lo accetta (404): con la
 *   convocazione partirebbe a tutti; lo stesso per un documento di un condòmino non ancora approvato;
 * - un documento dell'archivio legato a una persona resta sceglibile, ma la finestra dice che arriva a tutti i convocati;
 * - un «PDF» che non si legge come PDF non entra («non si legge come PDF»), e il fascicolo si ferma con il nome del documento invece di saltarlo;
 * - le pagine di un PDF che la prima volta non si legge non restano «niente» per sempre.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('il rogito di un\'unità e il documento non approvato non sono dell\'archivio: la finestra non li propone, «Aggiungi» risponde 404', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    $unita = array_values($s->unita)[0];
    $contenuto = ddpPdf(1, 'Rogito di Rossi Mario');
    $rogito = $unita->documenti()->create(['name' => 'Rogito Rossi Mario.pdf', 'description' => 'Rogito', 'path' => app(ArchivioDocumenti::class)
        ->salvaContenuto($contenuto, 'pdf', 'documenti/' . $s->c->id), 'mime_type' => 'application/pdf', 'file_size' => strlen($contenuto),
        'created_by' => $s->admin->id, 'is_approved' => true]);
    $rogito->condomini()->attach($s->c->id);
    $daApprovare = ddpDelCondominio($s, 'Caricato da un condòmino.pdf');
    $daApprovare->update(['is_approved' => false]);
    $diUnaPersona = ddpDelCondominio($s, 'Lettera dell\'avvocato di Bianchi.pdf');
    $diUnaPersona->anagrafiche()->attach($s->persone['bianchi']->id ?? \App\Models\Anagrafica::query()->value('id'));
    ddpDelCondominio($s, 'Preventivo Edil Rossi.pdf');

    $this->actingAs($s->admin);
    $finestra = $this->getJson(ddpRotta('documenti', $s, $a, 2))->assertOk()->json();
    $nomi = array_column($finestra['condominio']['documenti'], 'nome');
    expect($nomi)->not->toContain('Rogito Rossi Mario.pdf')->not->toContain('Caricato da un condòmino.pdf')->toContain('Preventivo Edil Rossi.pdf')
        ->and(collect($finestra['condominio']['documenti'])->firstWhere('nome', 'Lettera dell\'avvocato di Bianchi.pdf')['di_una_persona'])->toBeTrue()
        ->and(collect($finestra['condominio']['documenti'])->firstWhere('nome', 'Preventivo Edil Rossi.pdf')['di_una_persona'])->toBeFalse();
    $this->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$rogito->id]])->assertNotFound();
    $this->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$daApprovare->id]])->assertNotFound();
    expect(DocumentiDeiPunti::delPunto($a->punti()->where('numero', 2)->firstOrFail()))->toBe([]);
});

it('un «PDF» che non si legge come PDF non entra nel punto; il fascicolo si ferma con il nome del documento che non c\'è più', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    $falso = Documento::create(['name' => 'verbale-scansione.pdf', 'description' => 'x', 'path' => app(ArchivioDocumenti::class)->salvaContenuto("\xFF\xD8\xFFjpeg", 'pdf',
        'documenti/prova'), 'mime_type' => 'application/pdf', 'file_size' => 7, 'created_by' => $s->admin->id, 'is_published' => false, 'is_approved' => true]);
    $falso->condomini()->attach($s->c->id);
    $vero = ddpDelCondominio($s, 'Preventivo Edil Rossi.pdf', 2);

    $this->actingAs($s->admin);
    $this->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$falso->id]])
        ->assertSessionHasErrors(['documenti' => '«verbale-scansione.pdf» non si legge come PDF: non va nel fascicolo.']);
    $this->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$vero->id]])->assertSessionHasNoErrors();

    // Il file sparisce dal disco dopo che è entrato: il fascicolo non lo salta in silenzio.
    app(ArchivioDocumenti::class)->elimina($vero->path);
    expect(fn () => app(FascicoloDellAssemblea::class)->documenti($a->refresh()))->toThrow(ValidationException::class,
        '«Preventivo Edil Rossi.pdf» del punto 2 non c\'è più nell\'archivio: toglilo o caricalo di nuovo.');
});

it('le pagine di un PDF che la prima volta non si legge si rileggono la volta dopo', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $d = ddpDelCondominio($s, 'Preventivo Edil Rossi.pdf', 3);
    $contenuto = app(ArchivioDocumenti::class)->leggi($d->path);
    // Il disco non risponde un momento: il file non c'è.
    app(ArchivioDocumenti::class)->elimina($d->path);
    expect(app(DocumentiDeiPunti::class)->pagine($d))->toBeNull()
        ->and(Cache::get('assemblee.pagine.' . sha1((string) $d->path)))->toBeNull();
    // Il disco torna, con lo stesso file.
    \Illuminate\Support\Facades\Storage::disk(app(ArchivioDocumenti::class)->nomeDisco())->put((string) $d->path, (string) $contenuto);
    expect(app(DocumentiDeiPunti::class)->pagine($d))->toBe(3);
});
