<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role;
use App\Models\Documento;
use App\Models\DocumentoVersione;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\Documenti\SpazioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * 1.12.0-beta.13 (Coda 679, §9 del progetto, P21, decisione 518 (4)): un documento sostituito resta come versione di prima, in sola lettura, con la sua
 * impronta, e occupa spazio come gli altri file. L'ordine della sostituzione (l'archivio e le schede di persone, fornitori e unità, decisione 559): il
 * file nuovo salvato, il vecchio copiato fra le versioni, la riga del documento e la versione scritte nella transazione, e il vecchio tolto dal suo posto
 * solo dopo il commit; se la transazione cade il file nuovo e la copia si tolgono, e il vecchio non si è mai mosso. Togliendo il documento, i file suoi e
 * delle versioni si cancellano dopo il commit, e la riga del registro dice nome, grandezza e impronta.
 *
 * Che cosa non copre: il disco S3 (le stesse chiamate di `ArchivioDocumenti`, provate sul disco locale finto); un processo che muore davvero a metà (si
 * guarda il disco prima del commit: il file di prima è lì); il clic vero sulla pagina (a video).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Notification::fake();
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $this->andrea->assignRole(Role::AMMINISTRATORE->value);
    Storage::disk('local')->put('documenti/vecchio.pdf', '%PDF-1.4 il verbale di prima');
    $this->documento = Documento::query()->create(['name' => 'Verbale 12/2026', 'description' => 'Il verbale', 'created_by' => $this->andrea->id,
        'is_published' => true, 'is_approved' => true, 'path' => 'documenti/vecchio.pdf', 'mime_type' => 'application/pdf',
        'file_size' => strlen('%PDF-1.4 il verbale di prima')]);
});

function vddSostituisci($test, Documento $documento, string $nome = 'nuovo.pdf', int $kb = 3)
{
    return $test->actingAs($test->andrea)->put(route('admin.documenti.update', $documento), [
        'name' => $documento->name, 'description' => $documento->description, 'is_published' => true,
        'file' => UploadedFile::fake()->create($nome, $kb, 'application/pdf'),
    ]);
}

it('il file sostituito resta come versione di prima: fra le versioni, non cancellato, con la sua impronta, chi e quando', function () {
    vddSostituisci($this, $this->documento)->assertRedirect();

    $documento = $this->documento->refresh();
    $versione = DocumentoVersione::query()->where('documento_id', $documento->id)->sole();
    expect($documento->path)->not->toBe('documenti/vecchio.pdf')
        ->and(Storage::disk('local')->exists($documento->path))->toBeTrue()
        ->and(Storage::disk('local')->exists('documenti/vecchio.pdf'))->toBeFalse()
        ->and(Storage::disk('local')->get($versione->path))->toBe('%PDF-1.4 il verbale di prima')
        ->and($versione->impronta)->toBe(hash('sha256', '%PDF-1.4 il verbale di prima'))
        ->and($versione->nome_originale)->toBe('Verbale 12-2026.pdf')
        ->and((int) $versione->file_size)->toBe(strlen('%PDF-1.4 il verbale di prima'))
        ->and($versione->sostituita_da)->toBe($this->andrea->id)
        ->and($versione->sostituita_il)->not->toBeNull();
});

it('la sostituzione è una riga del registro, «File», con il nome e la grandezza di prima e di dopo, mai il percorso', function () {
    $prima = (int) RegistroModifica::query()->max('id');
    vddSostituisci($this, $this->documento, 'verbale-firmato.pdf', 2)->assertRedirect();

    $riga = RegistroModifica::query()->where('id', '>', $prima)->get()->first(fn ($r) => collect($r->campi)->contains('chiave', 'file'));
    expect($riga)->not->toBeNull();
    $campo = collect($riga->campi)->firstWhere('chiave', 'file');
    expect($riga->oggetto_tipo)->toBe('documento')
        ->and($riga->oggetto_id)->toBe($this->documento->id)
        ->and($riga->autore_nome)->toBe('Andrea Costa')
        ->and($campo['etichetta'])->toBe('File')
        ->and($campo['prima'])->toContain('Verbale 12-2026.pdf')
        ->and($campo['dopo'])->toContain('2 KB')
        ->and(json_encode($riga->campi))->not->toContain('documenti/');
});

it('se la transazione cade dopo la copia, il file vecchio resta dov\'era, il nuovo e la copia si tolgono, e non resta una versione', function () {
    Documento::updating(function () {
        throw new RuntimeException('Il database non risponde');
    });

    vddSostituisci($this, $this->documento)->assertRedirect();

    expect(Storage::disk('local')->get('documenti/vecchio.pdf'))->toBe('%PDF-1.4 il verbale di prima')
        ->and(Storage::disk('local')->allFiles('documenti'))->toBe(['documenti/vecchio.pdf'])
        ->and(DocumentoVersione::query()->count())->toBe(0)
        ->and($this->documento->refresh()->path)->toBe('documenti/vecchio.pdf');
});

it('lo spazio usato conta anche le versioni di prima', function () {
    $prima = app(SpazioDocumenti::class)->usatoByte();
    vddSostituisci($this, $this->documento, 'nuovo.pdf', 3)->assertRedirect();

    expect(app(SpazioDocumenti::class)->usatoByte())->toBe($prima + 3 * 1024);
});

it('togliendo il documento, i file suoi e delle versioni si cancellano dopo il commit, e la riga dice nome, grandezza e impronta dei file tolti', function () {
    vddSostituisci($this, $this->documento)->assertRedirect();
    $documento = $this->documento->refresh();
    $versione = DocumentoVersione::query()->sole();
    $prima = (int) RegistroModifica::query()->max('id');

    $this->actingAs($this->andrea)->delete(route('admin.documenti.destroy', $documento))->assertRedirect();

    $riga = RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'documento')->sole();
    $testo = collect($riga->campi)->map(fn ($c) => $c['etichetta'] . ': ' . $c['prima'])->implode(' | ');
    expect(Storage::disk('local')->exists($versione->path))->toBeFalse()
        ->and(Storage::disk('local')->exists($documento->path))->toBeFalse()
        ->and(DocumentoVersione::query()->count())->toBe(0)
        ->and($riga->azione)->toBe('eliminata')
        ->and($testo)->toContain('Verbale 12-2026.pdf')
        ->and($testo)->toContain(substr(hash('sha256', '%PDF-1.4 il verbale di prima'), 0, 12));
});

it('se l\'eliminazione cade, i file restano: si cancellano solo dopo il commit', function () {
    vddSostituisci($this, $this->documento)->assertRedirect();
    $documento = $this->documento->refresh();
    $versione = DocumentoVersione::query()->sole();
    Documento::deleting(function () {
        throw new RuntimeException('Il database non risponde');
    });

    $this->actingAs($this->andrea)->delete(route('admin.documenti.destroy', $documento))->assertRedirect();

    expect(Storage::disk('local')->exists($versione->path))->toBeTrue()
        ->and(Storage::disk('local')->exists($documento->path))->toBeTrue();
});

it('la pagina di modifica porta le versioni di prima senza il percorso, e «Scarica» dà il file di prima', function () {
    vddSostituisci($this, $this->documento)->assertRedirect();
    $versione = DocumentoVersione::query()->sole();

    $this->actingAs($this->andrea)->get(route('admin.documenti.edit', $this->documento))
        ->assertInertia(fn ($page) => $page->component('documenti/DocumentiEdit')
            ->has('versioni', 1)
            ->where('versioni.0.nome', 'Verbale 12-2026.pdf')
            ->where('versioni.0.sostituita_da', 'Andrea Costa')
            ->where('versioni.0.impronta', $versione->impronta)
            ->missing('versioni.0.path'));

    $risposta = $this->actingAs($this->andrea)->get(route('admin.documenti.versioni.download', [$this->documento, $versione]));
    $risposta->assertOk();
    expect($risposta->streamedContent())->toBe('%PDF-1.4 il verbale di prima');
});

it('la versione di un altro documento non si scarica da questo (404), e chi non vede il documento non la scarica (403)', function () {
    vddSostituisci($this, $this->documento)->assertRedirect();
    $versione = DocumentoVersione::query()->sole();
    $altro = Documento::query()->create(['name' => 'Altro', 'created_by' => $this->andrea->id, 'is_published' => true, 'is_approved' => true,
        'path' => 'documenti/altro.pdf', 'mime_type' => 'application/pdf', 'file_size' => 1]);
    $condomino = User::factory()->create(['email_verified_at' => now()]);
    $condomino->assignRole(Role::UTENTE->value);

    $this->actingAs($this->andrea)->get(route('admin.documenti.versioni.download', [$altro, $versione]))->assertNotFound();
    $this->actingAs($condomino)->get(route('admin.documenti.versioni.download', [$this->documento, $versione]))->assertForbidden();
});

it('dal portale: il condòmino che sostituisce il file del suo documento lascia la versione di prima, e togliendolo se ne vanno anche i file delle versioni', function () {
    $condomino = User::factory()->create(['name' => 'Ida Romano', 'email_verified_at' => now()]);
    $condomino->assignRole(Role::UTENTE->value);
    $condomino->givePermissionTo([\App\Enums\Permission::EDIT_OWN_ARCHIVE_DOCUMENTS->value, \App\Enums\Permission::DELETE_OWN_ARCHIVE_DOCUMENTS->value,
        \App\Enums\Permission::CREATE_ARCHIVE_DOCUMENTS->value]);
    \App\Models\Anagrafica::factory()->create(['user_id' => $condomino->id]);
    Storage::disk('local')->put('documenti/suo.pdf', '%PDF-1.4 il suo di prima');
    $suo = Documento::query()->create(['name' => 'Il mio contratto', 'created_by' => $condomino->id, 'is_published' => true, 'is_approved' => true,
        'path' => 'documenti/suo.pdf', 'mime_type' => 'application/pdf', 'file_size' => 24]);

    $this->actingAs($condomino)->put(route('user.documenti.update', $suo), [
        'name' => 'Il mio contratto', 'file' => UploadedFile::fake()->create('nuovo.pdf', 2, 'application/pdf'),
    ])->assertRedirect();

    $versione = DocumentoVersione::query()->where('documento_id', $suo->id)->sole();
    expect(Storage::disk('local')->get($versione->path))->toBe('%PDF-1.4 il suo di prima')
        ->and($versione->sostituita_da)->toBe($condomino->id);

    $this->actingAs($condomino)->delete(route('user.documenti.destroy', $suo))->assertRedirect();

    expect(Storage::disk('local')->exists($versione->path))->toBeFalse()
        ->and(Documento::query()->whereKey($suo->id)->exists())->toBeFalse();
});

// ── Decisione 559: le versioni anche nelle schede delle persone, dei fornitori e delle unità. ─────────────────────────────────────────

/** Un documento di un proprietario (persona, fornitore, unità), con il suo file sul disco finto. */
function vddDocumentoDi(\Illuminate\Database\Eloquent\Model $proprietario, int $autore, string $nome = 'Carta d\'identità'): Documento
{
    $percorso = 'documenti/di-' . class_basename($proprietario) . '-' . $proprietario->getKey() . '.pdf';
    Storage::disk('local')->put($percorso, '%PDF-1.4 il file di prima di ' . class_basename($proprietario));
    $documento = new Documento(['name' => $nome, 'description' => 'Di prova', 'created_by' => $autore, 'is_published' => true, 'is_approved' => true,
        'path' => $percorso, 'mime_type' => 'application/pdf', 'file_size' => 40]);
    $documento->documentable_type = $proprietario::class;
    $documento->documentable_id = $proprietario->getKey();
    $documento->save();

    return $documento;
}

it('nella scheda della persona: il file sostituito resta come versione, la pagina la mostra e la scarica, la riga del registro dice «File»', function () {
    $ida = \App\Models\Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12']);
    $documento = vddDocumentoDi($ida, $this->andrea->id);
    $prima = (int) RegistroModifica::query()->max('id');

    $this->actingAs($this->andrea)->put(route('admin.anagrafiche.documenti.update', [$ida, $documento]), [
        'name' => 'Carta d\'identità', 'description' => 'Di prova', 'is_published' => true, 'is_approved' => true,
        'file' => UploadedFile::fake()->create('nuova.pdf', 2, 'application/pdf'),
    ])->assertRedirect();

    $versione = DocumentoVersione::query()->where('documento_id', $documento->id)->sole();
    expect(Storage::disk('local')->get($versione->path))->toBe('%PDF-1.4 il file di prima di Anagrafica')
        ->and(RegistroModifica::query()->where('id', '>', $prima)->get()->contains(fn ($r) => collect($r->campi)->contains('chiave', 'file')))->toBeTrue();
    $this->actingAs($this->andrea)->get(route('admin.anagrafiche.documenti.edit', [$ida, $documento]))
        ->assertInertia(fn ($page) => $page->has('versioni', 1)->missing('versioni.0.path'));
    $scaricato = $this->actingAs($this->andrea)->get(route('admin.anagrafiche.documenti.versioni.download', [$ida, $documento, $versione]));
    expect($scaricato->streamedContent())->toBe('%PDF-1.4 il file di prima di Anagrafica');

    $this->actingAs($this->andrea)->delete(route('admin.anagrafiche.documenti.destroy', [$ida, $documento]))->assertRedirect();
    expect(Storage::disk('local')->exists($versione->path))->toBeFalse();
});

it('nella scheda del fornitore: il nuovo prima, il vecchio copiato, e se la transazione cade il vecchio resta (prima si cancellava)', function () {
    $fornitore = \App\Models\Fornitore::query()->create(['ragione_sociale' => 'Serramenti Marino']);
    $documento = vddDocumentoDi($fornitore, $this->andrea->id, 'DURC');
    $dati = ['name' => 'DURC', 'description' => 'Di prova', 'created_by' => $this->andrea->id, 'is_published' => true, 'is_approved' => true];
    Documento::updating(function () {
        throw new RuntimeException('Il database non risponde');
    });

    $this->actingAs($this->andrea)->put(route('admin.fornitori.documenti.update', [$fornitore, $documento]),
        $dati + ['file' => UploadedFile::fake()->create('nuovo.pdf', 2, 'application/pdf')])->assertRedirect();

    expect(Storage::disk('local')->get($documento->path))->toBe('%PDF-1.4 il file di prima di Fornitore')
        ->and(DocumentoVersione::query()->count())->toBe(0)
        // Il nuovo non resta: sul disco solo il suo file di prima e quello del documento dell'archivio della preparazione.
        ->and(Storage::disk('local')->allFiles('documenti'))->toEqualCanonicalizing([$documento->path, 'documenti/vecchio.pdf']);
});

it('nella scheda del fornitore: la versione resta, la pagina la mostra e la scarica, e togliendo il documento i file se ne vanno dopo il commit', function () {
    $fornitore = \App\Models\Fornitore::query()->create(['ragione_sociale' => 'Serramenti Marino']);
    $documento = vddDocumentoDi($fornitore, $this->andrea->id, 'DURC');

    $this->actingAs($this->andrea)->put(route('admin.fornitori.documenti.update', [$fornitore, $documento]), ['name' => 'DURC', 'description' => 'Di prova',
        'created_by' => $this->andrea->id, 'is_published' => true, 'is_approved' => true,
        'file' => UploadedFile::fake()->create('nuovo.pdf', 2, 'application/pdf')])->assertRedirect();

    $versione = DocumentoVersione::query()->where('documento_id', $documento->id)->sole();
    $this->actingAs($this->andrea)->get(route('admin.fornitori.documenti.edit', [$fornitore, $documento]))->assertInertia(fn ($page) => $page->has('versioni', 1));
    expect($this->actingAs($this->andrea)->get(route('admin.fornitori.documenti.versioni.download', [$fornitore, $documento, $versione]))->streamedContent())
        ->toBe('%PDF-1.4 il file di prima di Fornitore');

    $this->actingAs($this->andrea)->delete(route('admin.fornitori.documenti.destroy', [$fornitore, $documento]))->assertRedirect();
    expect(Storage::disk('local')->exists($versione->path))->toBeFalse()
        ->and(Storage::disk('local')->exists($documento->path))->toBeFalse();
});

it('nella scheda dell\'unità: la versione resta, la pagina la mostra e la scarica dentro il suo condominio, e togliendo il documento i file se ne vanno', function () {
    $condominio = \App\Models\Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $altro = \App\Models\Condominio::factory()->create(['nome' => 'Corso Italia 3']);
    $unita = \App\Models\Immobile::query()->create(['condominio_id' => $condominio->id, 'nome' => 'Appartamento', 'interno' => '7', 'codice_immobile' => 'VR-7']);
    $documento = vddDocumentoDi($unita, $this->andrea->id, 'Planimetria');

    $this->actingAs($this->andrea)->put(route('admin.gestionale.immobili.documenti.update', [$condominio, $unita, $documento]), ['name' => 'Planimetria',
        'is_published' => true, 'is_approved' => true, 'file' => UploadedFile::fake()->create('nuova.pdf', 2, 'application/pdf')])->assertRedirect();

    $versione = DocumentoVersione::query()->where('documento_id', $documento->id)->sole();
    $this->actingAs($this->andrea)->get(route('admin.gestionale.immobili.documenti.edit', [$condominio, $unita, $documento]))
        ->assertInertia(fn ($page) => $page->has('versioni', 1));
    expect($this->actingAs($this->andrea)->get(route('admin.gestionale.immobili.documenti.versioni.download', [$condominio, $unita, $documento, $versione]))
        ->streamedContent())->toBe('%PDF-1.4 il file di prima di Immobile');
    $this->actingAs($this->andrea)->get(route('admin.gestionale.immobili.documenti.versioni.download', [$altro, $unita, $documento, $versione]))->assertNotFound();

    $this->actingAs($this->andrea)->delete(route('admin.gestionale.immobili.documenti.destroy', [$condominio, $unita, $documento]))->assertRedirect();
    expect(Storage::disk('local')->exists($versione->path))->toBeFalse();
});

it('finché la transazione non è confermata il file di prima resta al suo posto: un processo interrotto a metà non lascia il documento senza file', function () {
    $persona = \App\Models\Anagrafica::query()->create(['nome' => 'Ida Romano', 'indirizzo' => 'Via Roma 12']);
    $fornitore = \App\Models\Fornitore::query()->create(['ragione_sociale' => 'Serramenti Marino']);
    $condominio = \App\Models\Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $unita = \App\Models\Immobile::query()->create(['condominio_id' => $condominio->id, 'tipo' => 'appartamento', 'codice_immobile' => 'AP-7', 'nome' => 'Interno 7',
        'interno' => '7']);
    // L'archivio e le tre schede passano dalla stessa sostituzione (decisione 559).
    foreach (['archivio' => $this->documento, 'persona' => vddDocumentoDi($persona, $this->andrea->id, 'Delega'),
        'fornitore' => vddDocumentoDi($fornitore, $this->andrea->id, 'DURC'), 'unità' => vddDocumentoDi($unita, $this->andrea->id, 'Planimetria')] as $dove => $documento) {
        $prima = (string) $documento->path;
        $contenuto = Storage::disk('local')->get($prima);
        $durante = null;
        \Illuminate\Support\Facades\DB::transaction(function () use ($documento, $prima, &$durante) {
            app(\App\Services\Documenti\VersioniDeiDocumenti::class)->sostituisci($documento, UploadedFile::fake()->create('nuovo.pdf', 2, 'application/pdf'));
            // Qui il processo potrebbe morire: il database tornerebbe al percorso di prima, e il file deve esserci.
            $durante = Storage::disk('local')->exists($prima);
        });

        $versione = DocumentoVersione::query()->where('documento_id', $documento->id)->sole();
        expect($durante)->toBeTrue($dove)
            ->and(Storage::disk('local')->exists($prima))->toBeFalse($dove)
            ->and(Storage::disk('local')->get($versione->path))->toBe($contenuto, $dove);
    }
});
