<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\OggettoDelPunto;
use App\Models\CategoriaDocumento;
use App\Models\Documento;
use App\Models\RegistroModifica;
use App\Services\Assemblee\Convocazione\DocumentiDeiPunti;
use App\Services\Assemblee\Convocazione\FascicoloDellAssemblea;
use App\Services\Assemblee\DiarioDellAssemblea;
use App\Services\Assemblee\OrdineDelGiorno;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';
require_once __DIR__ . '/DocumentiDiViaRoma12.php';

/**
 * 1.12.0-beta.13, il passo «Documenti» della pagina dell'assemblea (la bozza approvata dei documenti dei punti e del diario, scene 1, 1b e 2): ogni punto che
 * non è il rendiconto o il preventivo ha «Aggiungi un documento», con tre gruppi: i PDF proposti dalle schede di cui parla il punto (la fattura della
 * ratifica), i documenti PDF del condominio con la ricerca per nome, un PDF nuovo che va nei documenti del condominio nella categoria «Assemblee».
 *
 * - I proposti non entrano mai da soli: la riga del punto dice «Proposto dalla fattura n. 45» e aspetta il clic.
 * - Ogni aggiunta e ogni «Togli» passa dal campo «Parla di» del punto, con la sua riga del registro, e il diario la dice con il nome del documento.
 * - Un documento di un altro condominio è 404 (R18); un file che non è un PDF non entra; il rendiconto e il preventivo restano come sono.
 * - Dalla prima spedizione i documenti non cambiano più, come le date e i punti; chi vede soltanto legge il passo e non aggiunge.
 * - Il riepilogo del passo conta i documenti, le loro pagine e il peso, e dice quando il fascicolo è vicino al tetto.
 *
 * Che cosa non copre: la finestra e le righe a video (`FinestraDocumentiDelPunto.test.ts`, `SchedaFascicolo.test.ts`); le catene da un capo all'altro
 * (`CateneDeiDocumentiTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-11-26 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la proposta «Dalla fattura»: il punto della ratifica propone il PDF della fattura, che non entra da solo', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura, $doc] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    $this->actingAs($s->admin);

    $riga = ddpRiga($a, 1);
    expect($riga['documenti'])->toBe([])
        ->and($riga['documento'])->toBe('Nessun documento.')
        ->and(FascicoloDellAssemblea::NESSUN_DOCUMENTO)->toBe('Nessun documento.')
        ->and($riga['aggiungi'])->toBeTrue()
        ->and($riga['sotto'])->toStartWith('Fattura n. 45 · Idraulica Bianchi')
        ->and($riga['proposte'])->toBe([['da' => 'Proposto dalla fattura n. 45', 'breve' => 'Dalla fattura',
            'documenti' => [['id' => $doc->id, 'nome' => 'fattura-45.pdf', 'pagine' => 2]], 'pagine' => 2]])
        ->and(OggettoDelPunto::query()->where('oggetto_tipo', 'documento')->count())->toBe(0)
        ->and(app(FascicoloDellAssemblea::class)->riepilogo($a)['documenti'])->toBe(0);
    // Il punto senza schede non ha proposte.
    expect(ddpRiga($a, 2)['proposte'])->toBe([]);
});

it('la finestra: i proposti, i documenti del condominio con la ricerca per nome; un altro condominio non c\'è, un file che non è un PDF non si sceglie', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura, $doc] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    $altro = \App\Models\Condominio::factory()->create();
    ddpDelCondominio($s, 'Regolamento di condominio.pdf', 3, categoria: 'Regolamenti');
    $this->travel(1)->minutes();
    ddpDelCondominio($s, 'Foto della facciata.jpg', mime: 'image/jpeg');
    $this->travel(1)->minutes();
    $preventivo = ddpDelCondominio($s, 'Preventivo quadro di manovra.pdf', 3, categoria: 'Manutenzioni');
    ddpDelCondominio($s, 'Verbale di Corso Italia.pdf', condominioId: $altro->id);

    $r = $this->actingAs($s->admin)->getJson(ddpRotta('documenti', $s, $a, 1))->assertOk()->json();
    expect($r['punto'])->toMatchArray(['numero' => 1, 'testo' => 'Ratifica della spesa di € 1.800,00 per la colonna dell\'acqua'])
        ->and($r['proposti'])->toBe([['id' => $doc->id, 'nome' => 'fattura-45.pdf', 'pagine' => 2, 'da' => 'dalla fattura']])
        ->and($r['condominio']['nome'])->toBe($s->c->nome)
        ->and(array_column($r['condominio']['documenti'], 'nome'))->toBe(['Preventivo quadro di manovra.pdf', 'Foto della facciata.jpg', 'Regolamento di condominio.pdf'])
        ->and($r['condominio']['documenti'][0])->toMatchArray(['id' => $preventivo->id, 'pdf' => true, 'pagine' => 3, 'categorie' => 'Manutenzioni', 'caricato_il' => '26/11/2026', 'gia' => false])
        ->and($r['condominio']['documenti'][1])->toMatchArray(['pdf' => false, 'pagine' => null])
        ->and($r['condominio']['altri'])->toBe(0);

    $cercati = $this->getJson(ddpRotta('documenti', $s, $a, 1, ['cerca' => 'QUADRO']))->assertOk()->json('condominio.documenti');
    expect(array_column($cercati, 'nome'))->toBe(['Preventivo quadro di manovra.pdf']);
    // La ricerca non legge i caratteri speciali di SQL: «%» cerca un «%».
    expect($this->getJson(ddpRotta('documenti', $s, $a, 1, ['cerca' => '%']))->json('condominio.documenti'))->toBe([]);
});

it('aggiungere un documento del condominio: entra nel punto nell\'ordine dei clic, con la riga del registro sul campo «Parla di»; un altro condominio è 404', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    $regolamento = ddpDelCondominio($s, 'Regolamento di condominio.pdf', 3);
    $preventivo = ddpDelCondominio($s, 'Preventivo Ferri Serramenti.pdf', 4);
    $altro = ddpDelCondominio($s, 'Verbale di Corso Italia.pdf', condominioId: \App\Models\Condominio::factory()->create()->id);
    $prima = ViaRoma12::ultimaRiga();

    $this->actingAs($s->admin)->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$preventivo->id]])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('message.message', 'Un documento aggiunto al punto 2.');
    $this->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$regolamento->id]])->assertSessionHasNoErrors();

    $riga = ddpRiga($a, 2);
    expect(array_column($riga['documenti'], 'nome'))->toBe(['Preventivo Ferri Serramenti.pdf', 'Regolamento di condominio.pdf'])
        ->and($riga['documenti'][0])->toBe(['id' => $preventivo->id, 'nome' => 'Preventivo Ferri Serramenti.pdf', 'pagine' => 4])
        ->and(array_column(app(FascicoloDellAssemblea::class)->documenti($a), 'file'))->toBe(['Preventivo Ferri Serramenti.pdf', 'Regolamento di condominio.pdf']);
    $righe = RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'punto')->orderBy('id')->get();
    expect($righe)->toHaveCount(2)
        ->and($righe[0]->azione)->toBe('modificata')
        ->and($righe[0]->campi[0]['etichetta'])->toBe('Parla di')
        ->and($righe[0]->campi[0]['dopo'])->toBe('Documento: «Preventivo Ferri Serramenti.pdf»');

    $this->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$altro->id]])->assertNotFound();
    expect(OggettoDelPunto::query()->where('oggetto_tipo', 'documento')->where('oggetto_id', $altro->id)->exists())->toBeFalse();
    // Il PDF della fattura si aggiunge al punto che ne parla, non a un altro.
    $pdfDellaFattura = $fattura->documenti()->where('mime_type', 'application/pdf')->value('id');
    $this->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$pdfDellaFattura]])->assertNotFound();
    $this->post(ddpRotta('documenti.store', $s, $a, 1), ['documenti' => [$pdfDellaFattura]])->assertSessionHasNoErrors();
    expect(array_column(ddpRiga($a, 1)['documenti'], 'nome'))->toBe(['fattura-45.pdf'])
        ->and(ddpRiga($a, 1)['proposte'])->toBe([]);
});

it('un documento che non è un PDF non entra: lo dice con il suo nome', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    $foto = ddpDelCondominio($s, 'Foto della facciata.jpg', mime: 'image/jpeg');

    $this->actingAs($s->admin)->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$foto->id]])
        ->assertSessionHasErrors(['documenti' => '«Foto della facciata.jpg» non è un PDF: il fascicolo è un PDF solo.']);
    $this->post(ddpRotta('documenti.store', $s, $a, 2), [])->assertSessionHasErrors(['documenti' => DocumentiDeiPunti::FRASE_NIENTE]);
    expect(OggettoDelPunto::query()->where('oggetto_tipo', 'documento')->count())->toBe(0);
});

it('un PDF nuovo va nei documenti del condominio, nella categoria «Assemblee» (creata se manca), non pubblicato, e si collega al punto', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    expect(CategoriaDocumento::query()->where('name', 'Assemblee')->exists())->toBeFalse();

    $file = UploadedFile::fake()->createWithContent('Preventivo portone.pdf', ddpPdf(2));
    $this->actingAs($s->admin)->post(ddpRotta('documenti.store', $s, $a, 2), ['file' => $file])->assertSessionHasNoErrors();

    $nuovo = Documento::query()->where('name', 'Preventivo portone.pdf')->firstOrFail();
    $categoria = CategoriaDocumento::query()->where('slug', 'assemblee')->firstOrFail();
    expect($categoria->name)->toBe('Assemblee')
        ->and($nuovo->categorie()->pluck('categorie_documento.id')->all())->toBe([$categoria->id])
        ->and($nuovo->condomini()->pluck('condomini.id')->all())->toBe([$s->c->id])
        ->and((bool) $nuovo->is_published)->toBeFalse()
        ->and($nuovo->mime_type)->toBe('application/pdf')
        ->and(array_column(ddpRiga($a, 2)['documenti'], 'nome'))->toBe(['Preventivo portone.pdf']);

    // Il secondo trova la categoria: non ne nasce un'altra. Insieme a un documento scelto, entrano tutti e due.
    $regolamento = ddpDelCondominio($s, 'Regolamento di condominio.pdf');
    $this->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$regolamento->id], 'file' => UploadedFile::fake()->createWithContent('Relazione.pdf', ddpPdf())])
        ->assertSessionHasNoErrors()->assertSessionHas('message.message', '2 documenti aggiunti al punto 2.');
    expect(CategoriaDocumento::query()->where('slug', 'assemblee')->count())->toBe(1)
        ->and(array_column(ddpRiga($a, 2)['documenti'], 'nome'))->toBe(['Preventivo portone.pdf', 'Regolamento di condominio.pdf', 'Relazione.pdf']);

    $prima = Documento::query()->count();
    $this->post(ddpRotta('documenti.store', $s, $a, 2), ['file' => UploadedFile::fake()->image('foto.jpg')])->assertSessionHasErrors('file');
    expect(Documento::query()->count())->toBe($prima);
});

it('la categoria «Assemblee» che c\'è già, scritta dall\'amministratore, si usa: non ne nasce un\'altra', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    $sua = CategoriaDocumento::query()->create(['name' => 'Assemblee', 'description' => 'Le carte delle assemblee']);

    $this->actingAs($s->admin)->post(ddpRotta('documenti.store', $s, $a, 2), ['file' => UploadedFile::fake()->createWithContent('Relazione.pdf', ddpPdf())])
        ->assertSessionHasNoErrors();
    expect(CategoriaDocumento::query()->where('name', 'Assemblee')->count())->toBe(1)
        ->and(Documento::query()->where('name', 'Relazione.pdf')->firstOrFail()->categorie()->pluck('categorie_documento.id')->all())->toBe([$sua->id]);
});

it('«Togli» toglie il documento dal punto con la riga del registro; il documento resta nei documenti del condominio', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    $preventivo = ddpDelCondominio($s, 'Preventivo Ferri Serramenti.pdf', 4);
    $this->actingAs($s->admin)->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$preventivo->id]])->assertSessionHasNoErrors();
    $prima = ViaRoma12::ultimaRiga();

    $this->delete(ddpRotta('documenti.destroy', $s, $a, 2, ['documento' => $preventivo->id]))
        ->assertRedirect()->assertSessionHas('message.message', '«Preventivo Ferri Serramenti.pdf» tolto dal punto 2: resta nei documenti del condominio.');
    expect(ddpRiga($a, 2)['documenti'])->toBe([])
        ->and(Documento::query()->whereKey($preventivo->id)->exists())->toBeTrue();
    $riga = RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'punto')->sole();
    expect($riga->campi[0]['prima'])->toBe('Documento: «Preventivo Ferri Serramenti.pdf»')->and($riga->campi[0]['dopo'])->toBeNull();
    // Un documento che il punto non ha: 404.
    $this->delete(ddpRotta('documenti.destroy', $s, $a, 2, ['documento' => $preventivo->id]))->assertNotFound();
});

it('il rendiconto e il preventivo restano come oggi: niente «Aggiungi un documento», e il server rifiuta', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    $regolamento = ddpDelCondominio($s, 'Regolamento di condominio.pdf');
    $this->actingAs($s->admin);

    expect(ddpRiga($a, 3)['aggiungi'])->toBeFalse()->and(ddpRiga($a, 4)['aggiungi'])->toBeFalse()
        ->and(ddpRiga($a, 4)['azione'])->toBe('ferma_preventivo')
        ->and(ddpRiga($a, 5)['aggiungi'])->toBeTrue();
    $this->post(ddpRotta('documenti.store', $s, $a, 3), ['documenti' => [$regolamento->id]])->assertSessionHasErrors(['documenti' => DocumentiDeiPunti::FRASE_RENDICONTO]);
    $this->getJson(ddpRotta('documenti', $s, $a, 4))->assertStatus(422);
});

it('dalla prima spedizione i documenti dei punti non cambiano più, come le date e i punti', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    $regolamento = ddpDelCondominio($s, 'Regolamento di condominio.pdf');
    $this->actingAs($s->admin)->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$regolamento->id]])->assertSessionHasNoErrors();
    $a->forceFill(['stato' => 'convocata'])->saveQuietly();

    expect(ddpRiga($a, 2)['aggiungi'])->toBeFalse()->and(ddpRiga($a, 2)['togli'])->toBeFalse();
    $this->post(ddpRotta('documenti.store', $s, $a, 5), ['documenti' => [$regolamento->id]])->assertSessionHasErrors(['assemblea' => OrdineDelGiorno::FRASE_NON_SI_CAMBIA]);
    $this->delete(ddpRotta('documenti.destroy', $s, $a, 2, ['documento' => $regolamento->id]))->assertSessionHasErrors(['assemblea' => OrdineDelGiorno::FRASE_NON_SI_CAMBIA]);
    expect(array_column(ddpRiga($a, 2)['documenti'], 'nome'))->toBe(['Regolamento di condominio.pdf']);
});

it('chi vede soltanto legge il passo e non aggiunge né toglie: 403', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    $regolamento = ddpDelCondominio($s, 'Regolamento di condominio.pdf');
    $this->actingAs($s->admin)->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$regolamento->id]])->assertSessionHasNoErrors();
    $vede = \App\Models\User::factory()->create();
    $vede->assignRole('admin');
    Permission::firstOrCreate(['name' => Permesso::VIEW_ASSEMBLEE->value, 'guard_name' => 'web']);
    $vede->givePermissionTo(Permesso::VIEW_ASSEMBLEE->value);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->actingAs($vede)->getJson(ddpRotta('documenti', $s, $a, 2))->assertForbidden();
    $this->actingAs($vede)->post(ddpRotta('documenti.store', $s, $a, 5), ['documenti' => [$regolamento->id]])->assertForbidden();
    $this->actingAs($vede)->delete(ddpRotta('documenti.destroy', $s, $a, 2, ['documento' => $regolamento->id]))->assertForbidden();
    $this->actingAs($vede);
    $riga = ddpRiga($a, 2);
    expect(array_column($riga['documenti'], 'nome'))->toBe(['Regolamento di condominio.pdf'])
        ->and($riga['aggiungi'])->toBeFalse()->and($riga['togli'])->toBeFalse();
});

it('il diario dice «Documento aggiunto al punto 2» e «Documento tolto dal punto 2», con il nome', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    $preventivo = ddpDelCondominio($s, 'Preventivo Ferri Serramenti.pdf');
    $regolamento = ddpDelCondominio($s, 'Regolamento di condominio.pdf');
    $this->actingAs($s->admin)->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$preventivo->id, $regolamento->id]])->assertSessionHasNoErrors();
    $this->travel(1)->minutes();
    $this->delete(ddpRotta('documenti.destroy', $s, $a, 2, ['documento' => $preventivo->id]))->assertSessionHasNoErrors();

    $frasi = array_column(DiarioDellAssemblea::righe($a, 'modifiche')['righe'], 'frase');
    expect($frasi[0])->toBe('Documento tolto dal punto 2: «Preventivo Ferri Serramenti.pdf»')
        ->and($frasi[1])->toBe('Documenti aggiunti al punto 2: «Preventivo Ferri Serramenti.pdf», «Regolamento di condominio.pdf»');
});

it('il riepilogo del passo: i documenti, le loro pagine e il peso; vicino al tetto lo dice', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    [$fattura, $doc] = ddpFatturaConIlPdf($s);
    $a = ddpAssemblea($s, $fattura);
    $preventivo = ddpDelCondominio($s, 'Preventivo Ferri Serramenti.pdf', 3);
    $this->actingAs($s->admin)->post(ddpRotta('documenti.store', $s, $a, 1), ['documenti' => [$doc->id]])->assertSessionHasNoErrors();
    $this->post(ddpRotta('documenti.store', $s, $a, 2), ['documenti' => [$preventivo->id]])->assertSessionHasNoErrors();

    $f = app(FascicoloDellAssemblea::class)->perLaPagina($a);
    expect($f['riepilogo'])->toMatchArray(['documenti' => 2, 'pagine' => 5, 'tetto' => 40, 'vicino' => false])
        ->and($f['modificabile'])->toBeTrue()
        ->and($f['condominio'])->toBe($s->c->nome);

    // Un documento pesante: 35 MB su 40 è vicino al tetto.
    $preventivo->forceFill(['file_size' => 35 * 1024 * 1024])->saveQuietly();
    $f = app(FascicoloDellAssemblea::class)->perLaPagina($a);
    expect($f['riepilogo']['vicino'])->toBeTrue()->and($f['riepilogo']['mb'])->toBe(35);
});
