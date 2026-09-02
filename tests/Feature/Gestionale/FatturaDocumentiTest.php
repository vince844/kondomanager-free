<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/**
 * Coda 102 (1.11.0-beta.12): l'allegato prende un percorso suo, fuori dalla
 * riscrittura contabile di aggiornaFattura().
 *
 * ⚠️ Corretta dopo la Fase 1-bis (revisione avversariale, stesso giorno): la prima
 * stesura non aveva nessun Gate::authorize, motivato con una premessa poi verificata
 * falsa («nessun'altra azione del controller passa dalla policy» — download() ci
 * passa già). Ripristinato con la stessa DocumentoPolicy di download(), più una
 * guardia sullo stato per l'eliminazione (motivoBloccoModifica(), asimmetrica
 * rispetto all'aggiunta — decisione di Vincenzo, 01/09/2026).
 *
 * ## Cosa questi test NON coprono
 *
 * - Il modulo di Modifica (FatturaRegisterEdit.vue): non ha più il campo file,
 *   verificato leggendo il file, non testato via browser.
 * - Il limite di dimensione/formato del file: già coperto da LimiteCaricamentoTest.php
 *   sulla stessa regola di validazione, riusata identica in StoreFatturaDocumentoRequest.
 */
beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();

    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $permessoCrea = Permission::firstOrCreate(['name' => 'Crea documenti archivio', 'guard_name' => 'web']);
    $permessoElimina = Permission::firstOrCreate(['name' => 'Elimina documenti archvio', 'guard_name' => 'web']);
    $permessoVedi = Permission::firstOrCreate(['name' => 'Visualizza documenti archivio', 'guard_name' => 'web']);

    // Il ruolo di test ha TUTTI e tre — come l'amministratore vero (Role::AMMINISTRATORE
    // prende PermissionEnum::cases() per intero, app/Enums/Role.php:61). Il test sul
    // collaboratore, più sotto, usa un ruolo suo con solo CREATE — è quello il caso
    // interessante da isolare, non il proprietario che può fare tutto.
    $ruoloAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruoloAdmin->givePermissionTo([$permessoAdmin, $permessoCrea, $permessoElimina, $permessoVedi]);

    $this->user = User::factory()->create();
    $this->user->assignRole($ruoloAdmin);

    Storage::fake('local');
});

/**
 * registraFatturaServiceTest($ctx) SENZA override di 'righe' usa un $capitolo mal
 * destrutturato al suo interno (punta al conto Fondo Riserva, non al capitolo di
 * spesa — bug preesistente, già noto in FatturaPassivaControllerTest.php). Stesso
 * aggiramento degli altri test in questo modulo: righe esplicite, sempre.
 */
function fatturaConAllegatoTest(array $ctx, array $override = [])
{
    [, , , , $capitolo] = $ctx;

    return registraFatturaServiceTest($ctx, array_merge([
        'righe' => [[
            'descrizione' => 'Servizio Test',
            'importo_imponibile' => 1000,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
            'is_sopravvenienza' => false,
        ]],
    ], $override));
}

it('allega un documento senza toccare le scritture contabili della fattura', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $fattura = fatturaConAllegatoTest($ctx);

    $scrittureIdsPrima = $fattura->scritture()->pluck('scritture_contabili.id')->sort()->values();

    $response = $this->actingAs($this->user)->post(
        route('admin.gestionale.fatture.documenti.store', [$condominio, $fattura]),
        ['file' => UploadedFile::fake()->create('fattura.pdf', 500, 'application/pdf')]
    );

    $response->assertRedirect();
    $response->assertSessionHas('message.type', 'success');

    $fattura->refresh();
    expect($fattura->documenti)->toHaveCount(1)
        ->and($fattura->documenti->first()->name)->toBe('fattura.pdf')
        ->and($fattura->scritture()->pluck('scritture_contabili.id')->sort()->values()->all())
            ->toBe($scrittureIdsPrima->all());
});

it('un secondo allegato si aggiunge, non cancella il primo — il difetto che la Coda 102 aveva misurato', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $fattura = fatturaConAllegatoTest($ctx);

    $this->actingAs($this->user)->post(
        route('admin.gestionale.fatture.documenti.store', [$condominio, $fattura]),
        ['file' => UploadedFile::fake()->create('fattura.pdf', 100, 'application/pdf')]
    );

    $this->actingAs($this->user)->post(
        route('admin.gestionale.fatture.documenti.store', [$condominio, $fattura]),
        ['file' => UploadedFile::fake()->create('quietanza.pdf', 100, 'application/pdf')]
    );

    $fattura->refresh();
    expect($fattura->documenti)->toHaveCount(2)
        ->and($fattura->documenti->pluck('name')->sort()->values()->all())
        ->toBe(['fattura.pdf', 'quietanza.pdf']);
});

it('allega un documento anche a esercizio chiuso — decisione di Vincenzo, 01/09/2026', function () {
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $fattura = fatturaConAllegatoTest($ctx);

    DB::table('esercizi')->where('id', $esercizio->id)->update(['stato' => 'chiuso']);

    // Controprova che l'esercizio chiuso blocca DAVVERO il modulo di Modifica —
    // altrimenti questo test proverebbe una guardia che non esiste.
    expect((new App\Services\Gestionale\FatturaPassivaService())->motivoBloccoModifica($fattura->fresh()))
        ->not->toBeNull();

    $response = $this->actingAs($this->user)->post(
        route('admin.gestionale.fatture.documenti.store', [$condominio, $fattura]),
        ['file' => UploadedFile::fake()->create('fattura.pdf', 100, 'application/pdf')]
    );

    $response->assertSessionHas('message.type', 'success');
    expect($fattura->fresh()->documenti)->toHaveCount(1);
});

it('NON elimina un documento su una fattura congelata — asimmetrico rispetto all\'aggiunta', function () {
    // Trovato dalla revisione avversariale: allegare a esercizio chiuso è la decisione
    // di Vincenzo del 01/09/2026 ("un documento è una prova"), ma *eliminare* no —
    // Documento non usa SoftDeletes, quindi sarebbe una cancellazione definitiva e
    // senza traccia di una prova su una fattura che prima non si poteva nemmeno più
    // aprire in Modifica.
    $ctx = setupContabile();
    [$condominio, $esercizio] = $ctx;
    $fattura = fatturaConAllegatoTest($ctx);

    $documento = (new App\Services\Gestionale\FatturaPassivaService())->aggiungiDocumento(
        $fattura,
        UploadedFile::fake()->create('fattura.pdf', 100, 'application/pdf')
    );

    DB::table('esercizi')->where('id', $esercizio->id)->update(['stato' => 'chiuso']);

    $response = $this->actingAs($this->user)->delete(
        route('admin.gestionale.fatture.documenti.destroy', [$condominio, $fattura->fresh(), $documento])
    );

    $response->assertSessionHas('message.type', 'error');
    expect($fattura->fresh()->documenti)->toHaveCount(1);
    Storage::disk('local')->assertExists($documento->path);
});

it('il collaboratore allega ma non elimina — applica la regola già esistente su DELETE_ARCHIVE_DOCUMENTS', function () {
    // Deciso con Vincenzo il 01/09/2026: non si tocca il permesso in questa beta (il
    // gestionale non ha permessi granulari propri, e ridisegnarli è materia della coda
    // aperta in roadmap). Si applica la regola che il prodotto ha già.
    $permessoAdmin = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $permessoCrea = Permission::firstOrCreate(['name' => 'Crea documenti archivio', 'guard_name' => 'web']);
    // DocumentoPolicy::delete() controlla anche DELETE_OWN_ARCHIVE_DOCUMENTS come
    // seconda via: la riga deve esistere (Spatie solleva se il permesso non è mai
    // stato creato), ma NON va assegnata — il collaboratore reale non ce l'ha
    // (app/Enums/Role.php:63-100, verificato: non compare nella sua lista).
    Permission::firstOrCreate(['name' => 'Elimina propri documenti archivio', 'guard_name' => 'web']);
    $ruoloCollaboratore = Role::firstOrCreate(['name' => 'collaboratore-test', 'guard_name' => 'web']);
    $ruoloCollaboratore->syncPermissions([$permessoAdmin, $permessoCrea]); // niente DELETE_ARCHIVE_DOCUMENTS

    $collaboratore = User::factory()->create();
    $collaboratore->assignRole($ruoloCollaboratore);

    $ctx = setupContabile();
    [$condominio] = $ctx;
    $fattura = fatturaConAllegatoTest($ctx);

    $upload = $this->actingAs($collaboratore)->post(
        route('admin.gestionale.fatture.documenti.store', [$condominio, $fattura]),
        ['file' => UploadedFile::fake()->create('fattura.pdf', 100, 'application/pdf')]
    );
    $upload->assertSessionHas('message.type', 'success');
    $documento = $fattura->fresh()->documenti->first();
    expect($documento)->not->toBeNull();

    $delete = $this->actingAs($collaboratore)->delete(
        route('admin.gestionale.fatture.documenti.destroy', [$condominio, $fattura, $documento])
    );
    $delete->assertForbidden();
    expect($fattura->fresh()->documenti)->toHaveCount(1);
});

it('rifiuta di allegare senza il permesso di creare documenti archivio', function () {
    $ruoloSenzaCrea = Role::firstOrCreate(['name' => 'senza-crea-test', 'guard_name' => 'web']);
    $ruoloSenzaCrea->syncPermissions([
        Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']),
    ]);
    $utente = User::factory()->create();
    $utente->assignRole($ruoloSenzaCrea);

    $ctx = setupContabile();
    [$condominio] = $ctx;
    $fattura = fatturaConAllegatoTest($ctx);

    $response = $this->actingAs($utente)->post(
        route('admin.gestionale.fatture.documenti.store', [$condominio, $fattura]),
        ['file' => UploadedFile::fake()->create('fattura.pdf', 100, 'application/pdf')]
    );

    $response->assertForbidden();
    expect($fattura->fresh()->documenti)->toHaveCount(0);
});

it('se il disco non scrive, non si salva una riga senza file dietro', function () {
    // Trovato dalla revisione avversariale con una sonda su una cartella non
    // scrivibile: senza il controllo su storeAs()===false, la riga si salvava lo
    // stesso con path='0' e il flash diceva "successo". Stesso scenario qui, con un
    // disco 'local' reale puntato su una cartella in sola lettura invece del fake.
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $fattura = fatturaConAllegatoTest($ctx);

    $radice = sys_get_temp_dir().'/km_test_disco_readonly_'.uniqid();
    mkdir($radice.'/documenti/'.$condominio->id, 0755, true);
    chmod($radice.'/documenti/'.$condominio->id, 0555); // sola lettura

    config(['filesystems.disks.local.root' => $radice]);
    app()->forgetInstance('filesystem');
    Storage::forgetDisk('local'); // il disco va ricreato con la nuova radice, non quello fake di beforeEach

    if (is_writable($radice.'/documenti/'.$condominio->id)) {
        // L'ambiente non rispetta i permessi POSIX (es. processo con privilegi che
        // ignorano chmod): la sonda non è verificabile qui, si salta invece di mentire.
        chmod($radice.'/documenti/'.$condominio->id, 0755);
        rmdir($radice.'/documenti/'.$condominio->id);
        rmdir($radice.'/documenti');
        rmdir($radice);
        expect(true)->toBeTrue();

        return;
    }

    $lanciata = null;
    try {
        (new App\Services\Gestionale\FatturaPassivaService())->aggiungiDocumento(
            $fattura,
            UploadedFile::fake()->create('fattura.pdf', 100, 'application/pdf')
        );
    } catch (\RuntimeException $e) {
        $lanciata = $e;
    }

    expect($lanciata)->not->toBeNull()
        ->and($fattura->fresh()->documenti)->toHaveCount(0);

    chmod($radice.'/documenti/'.$condominio->id, 0755);
    exec('rm -rf '.escapeshellarg($radice));
});

it('elimina un documento allegato', function () {
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $fattura = fatturaConAllegatoTest($ctx);

    $documento = (new App\Services\Gestionale\FatturaPassivaService())->aggiungiDocumento(
        $fattura,
        UploadedFile::fake()->create('fattura.pdf', 100, 'application/pdf')
    );

    $response = $this->actingAs($this->user)->delete(
        route('admin.gestionale.fatture.documenti.destroy', [$condominio, $fattura, $documento])
    );

    $response->assertSessionHas('message.type', 'success');
    expect($fattura->fresh()->documenti)->toHaveCount(0);
    Storage::disk('local')->assertMissing($documento->path);
});

it('rifiuta di eliminare un documento che appartiene a un\'altra fattura — anti-IDOR', function () {
    // La rotta 'gestionale.' ha scopeBindings() (routes/gestionale.php:75): Laravel
    // risolve {documento} DENTRO fattura->documenti() e risponde 404 prima ancora di
    // entrare nel controller — il controllo manuale in destroyDocumento() (identico a
    // quello già in download()) è difesa in profondità, non l'unica guardia. Verificato
    // qui che la protezione regge comunque, quale che sia il livello che la fa scattare.
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $fatturaA = fatturaConAllegatoTest($ctx, ['numero_documento' => 'FT-A']);
    $fatturaB = fatturaConAllegatoTest($ctx, ['numero_documento' => 'FT-B']);

    $service = new App\Services\Gestionale\FatturaPassivaService();
    $documentoDiA = $service->aggiungiDocumento($fatturaA, UploadedFile::fake()->create('a.pdf', 50, 'application/pdf'));

    // Prova a eliminarlo passando l'id della fattura B nella rotta.
    $response = $this->actingAs($this->user)->delete(
        route('admin.gestionale.fatture.documenti.destroy', [$condominio, $fatturaB, $documentoDiA])
    );

    expect($response->status())->toBeIn([403, 404]);
    expect($fatturaA->fresh()->documenti)->toHaveCount(1);
});

it('accetta la busta .p7m vera — mimes: la rifiutava sempre, extensions: la accetta', function () {
    // Trovato dalla revisione avversariale sulla fixture ufficiale del capitolo XML
    // (tests/Fixtures/fatturapa/): una busta CAdES è ASN.1 generico, finfo la vede
    // application/octet-stream, e mimes: (che guarda il contenuto) la rifiutava al
    // 100% dei tentativi — proprio il formato che il tracciato FatturaPA promette.
    $ctx = setupContabile();
    [$condominio] = $ctx;
    $fattura = fatturaConAllegatoTest($ctx);

    $percorso = base_path('tests/Fixtures/fatturapa/IT01234567890_FPR01.xml.p7m');
    $file = new UploadedFile($percorso, 'fattura.xml.p7m', null, null, true);

    $response = $this->actingAs($this->user)->post(
        route('admin.gestionale.fatture.documenti.store', [$condominio, $fattura]),
        ['file' => $file]
    );

    $response->assertSessionHas('message.type', 'success');
    expect($fattura->fresh()->documenti->first()->name)->toBe('fattura.xml.p7m');
});

it('un file inviato al PUT di modifica viene rifiutato, non buttato in silenzio', function () {
    // Scenario: un bundle vecchio ancora aperto in una scheda (o un'integrazione ferma
    // al contratto precedente) invia ancora 'file'. Prima della correzione spariva
    // sotto "Fattura aggiornata con successo"; ora la validazione lo dice.
    $ctx = setupContabile();
    [$condominio, $esercizio, $gestione, , $capitolo] = $ctx;
    $fattura = fatturaConAllegatoTest($ctx);

    $response = $this->actingAs($this->user)->put(
        route('admin.gestionale.fatture.update', [$condominio, $fattura]),
        [
            'gestione_id' => $gestione->id,
            'numero_documento' => $fattura->numero_documento,
            'data_documento' => $fattura->data_documento->format('Y-m-d'),
            'data_scadenza' => $fattura->data_scadenza->format('Y-m-d'),
            'modalita_pagamento' => 'bonifico',
            'righe' => [[
                'descrizione' => 'Servizio Test',
                'importo_imponibile' => 1000,
                'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
            ]],
            'file' => UploadedFile::fake()->create('fattura.pdf', 100, 'application/pdf'),
        ]
    );

    $response->assertSessionHasErrors(['file']);
    expect($fattura->fresh()->documenti)->toHaveCount(0);
});

it('aggiornaFattura non accetta più un file — la firma è cambiata, non solo il comportamento', function () {
    // Prova diretta sul metodo, non solo sulla rotta: se qualcuno reintroducesse un
    // terzo parametro $file, questo test lo segnalerebbe come cambio di firma da rivedere.
    $reflection = new ReflectionMethod(App\Services\Gestionale\FatturaPassivaService::class, 'aggiornaFattura');

    expect($reflection->getNumberOfParameters())->toBe(2);
});

it('modificare una fattura non tocca i suoi allegati esistenti', function () {
    $ctx = setupContabile();
    [, , , , $capitolo] = $ctx;
    $fattura = fatturaConAllegatoTest($ctx);

    (new App\Services\Gestionale\FatturaPassivaService())->aggiungiDocumento(
        $fattura,
        UploadedFile::fake()->create('fattura.pdf', 100, 'application/pdf')
    );

    (new App\Services\Gestionale\FatturaPassivaService())->aggiornaFattura($fattura->fresh(), [
        'numero_documento' => $fattura->numero_documento,
        'data_documento' => $fattura->data_documento->format('Y-m-d'),
        'data_scadenza' => $fattura->data_scadenza->format('Y-m-d'),
        'modalita_pagamento' => 'bonifico',
        'righe' => [[
            'descrizione' => 'Servizio Test aggiornato',
            'importo_imponibile' => 1200,
            'aliquota_iva' => 22,
            'conto_id' => $capitolo->id,
        ]],
    ]);

    expect($fattura->fresh()->documenti)->toHaveCount(1);
});
