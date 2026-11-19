<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # La posizione si toglie dalle foto in ogni caricamento del programma (decisione 535)
 *
 * La decisione 531 l'aveva detto per gli allegati di segnalazioni e comunicazioni; la 535 lo estende a ogni caricamento di un'immagine. La strada è
 * una sola, all'ingresso: ogni file caricato in una richiesta del programma che è una foto JPG, PNG o WebP (il tipo letto dal contenuto) passa da
 * `FotoSenzaDati` prima che la richiesta arrivi al suo controller (`App\Http\Middleware\TogliIDatiDelleFoto`, nel gruppo `web`). Così il file che
 * ogni parte salva (l'archivio dei documenti con `ArchivioDocumenti`, la carta dello studio, il logo e la firma delle stampe, gli allegati) è già
 * senza i dati, e il peso che ogni parte legge con `getSize()` è quello del file pulito. Resta il verso; una foto che non si sa pulire si rifiuta,
 * con la frase, sotto il campo che l'ha portata.
 *
 * Qui due parti diverse dagli allegati, dalle rotte vere: un documento della fattura (l'archivio dei documenti) e la prova di una lettera.
 *
 * Che cosa non copre: le immagini dentro un file che non è un'immagine (un PDF, un `.eml`, una fattura XML con gli allegati); i formati che nessun
 * caricamento accetta (HEIC, TIFF, GIF); i caricamenti fuori da una richiesta (i comandi, l'import di un backup); MySQL (sqlite in memoria).
 */

use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/FotoConIDatiScenario.php';
require_once __DIR__ . '/../Gestionale/SpesaNonPrevistaScenario.php';
require_once __DIR__ . '/../Gestionale/LettereScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});

/** Lo scenario delle Code 686 e 687, con il permesso di allegare un documento a una fattura (la regola di `DocumentoPolicy::create`). */
function fsdScenarioFattura($test): array
{
    $r = snpScenario($test);
    $permesso = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => \App\Enums\Permission::CREATE_ARCHIVE_DOCUMENTS->value, 'guard_name' => 'web']);
    $r['s']->admin->givePermissionTo($permesso);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $r['f'] = snpFattura($test, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r), false, null, 'FT-FOTO');

    return $r;
}

/** I documenti di una fattura, a database. */
function fsdDocumentiDellaFattura(\App\Models\Gestionale\FatturaPassiva $f): \Illuminate\Support\Collection
{
    return DB::table('documenti')->where('documentable_type', $f->getMorphClass())->where('documentable_id', $f->id)->orderBy('id')->get();
}

it('il documento di una fattura: una foto JPG con la posizione si salva senza, con il verso, gli stessi pixel e il peso del file salvato', function () {
    $r = fsdScenarioFattura($this);
    $f = $r['f'];
    $foto = fsdJpg(6);

    $this->actingAs($r['s']->admin)->post(route('admin.gestionale.fatture.documenti.store', [$r['s']->c, $f]), ['file' => fsdFile('scontrino.jpg', $foto)])
        ->assertSessionHasNoErrors()->assertSessionHas('message.type', 'success');
    $riga = fsdDocumentiDellaFattura($f)->sole();
    $salvato = (string) app(ArchivioDocumenti::class)->disco()->get($riga->path);
    // Con il codice di prima i byte salvati erano quelli caricati: la latitudine, «Via Roma 1» e l'XMP c'erano.
    expect(fsdSenzaDati($salvato))->toBeTrue()
        ->and(@exif_read_data('data://image/jpeg;base64,' . base64_encode($salvato))['Orientation'] ?? null)->toBe(6)
        ->and(fsdPixel($salvato))->toBe(fsdPixel($foto))
        ->and((int) $riga->file_size)->toBe(strlen($salvato))
        ->and((int) $riga->file_size)->toBeLessThan(strlen($foto));
});

it('la prova di una lettera: un PNG con `eXIf`, i testi e la data si salva senza; il peso è quello salvato', function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create(['name' => 'Anna Studio', 'email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
    $s = lttScenario($this);
    $lettera = lttRigaScritta($s, 'ugo', 'diffida', 'stampata', 0);

    $this->actingAs($this->user)->post(lttRotta($s, 'prove.store', ['invio' => $lettera->id]), ['file' => fsdFile('ricevuta.png', fsdPng())])
        ->assertSessionHasNoErrors();

    $riga = DB::table('documenti')->where('documentable_type', RegistroInvio::class)->where('documentable_id', $lettera->id)->sole();
    $salvato = (string) app(ArchivioDocumenti::class)->disco()->get($riga->path);
    expect(fsdSenzaDati($salvato))->toBeTrue()
        ->and(str_contains($salvato, 'eXIf'))->toBeFalse()->and(str_contains($salvato, 'tEXt'))->toBeFalse()->and(str_contains($salvato, 'tIME'))->toBeFalse()
        ->and(fsdPixel($salvato))->toBe(fsdPixel(fsdPng()))
        ->and((int) $riga->file_size)->toBe(strlen($salvato));
});

it('una foto che non si sa pulire (un JPG tagliato) si rifiuta sotto il suo campo, con la frase; dalla pagina e da una richiesta JSON; non si salva niente', function () {
    $r = fsdScenarioFattura($this);
    $f = $r['f'];
    $tagliata = substr(fsdJpg(), 0, -40);
    $rotta = route('admin.gestionale.fatture.documenti.store', [$r['s']->c, $f]);

    $this->actingAs($r['s']->admin)->from(route('admin.gestionale.fatture.index', [$r['s']->c]))->post($rotta, ['file' => fsdFile('tagliata.jpg', $tagliata)])
        ->assertSessionHasErrors(['file' => FotoSenzaDatiFrase('tagliata.jpg')]);
    $this->actingAs($r['s']->admin)->postJson($rotta, ['file' => fsdFile('tagliata.jpg', $tagliata)])
        ->assertStatus(422)->assertJsonPath('errors.file.0', FotoSenzaDatiFrase('tagliata.jpg'));

    expect(fsdDocumentiDellaFattura($f))->toHaveCount(0)
        ->and(app(ArchivioDocumenti::class)->disco()->allFiles())->toBe([]);
});

it('controprova: un PDF e una foto già senza dati restano byte per byte com\'erano', function () {
    $r = fsdScenarioFattura($this);
    $f = $r['f'];
    $pdf = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 10 10]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    $pulita = fsdImmagine('png');

    foreach ([['preventivo.pdf', $pdf], ['schema.png', $pulita]] as [$nome, $byte]) {
        $this->actingAs($r['s']->admin)->post(route('admin.gestionale.fatture.documenti.store', [$r['s']->c, $f]), ['file' => fsdFile($nome, $byte)])->assertSessionHasNoErrors();
    }

    expect(fsdDocumentiDellaFattura($f)->map(fn ($d) => (string) app(ArchivioDocumenti::class)->disco()->get($d->path))->all())->toBe([$pdf, $pulita]);
});

/** La frase del rifiuto, quella del programma. */
function FotoSenzaDatiFrase(string $nome): string
{
    return \App\Support\Allegati\FotoSenzaDati::frase($nome);
}
