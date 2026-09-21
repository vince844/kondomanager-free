<?php

/**
 * # I documenti su un disco S3 (1.11.0-beta.33)
 *
 * Con `DOCUMENTI_DISK=s3` i documenti e la firma delle stampe passano dai dischi `documenti_s3` e
 * `pubblici_s3`. Qui il bucket è un `Storage::fake()`: si prova che **tutto il giro** — caricare,
 * scaricare, eliminare, la firma nelle stampe, l'anteprima della firma — usi il disco configurato
 * e non `local`/`public` scritti a mano. Il download su S3 non ha un percorso assoluto: è uno
 * stream, e la risposta lo dimostra. Il gemello «senza variabile = local» è `DiscoDocumentiTest`.
 *
 * ## Cosa il fake NON prova — e il gruppo «endpoint morto» in fondo sì, in parte
 *
 * `Storage::fake()` rimpiazza il disco con uno locale: non prova il prefisso `root`, la regione,
 * l'URL firmato vero, il `download()` dell'adapter AWS né — soprattutto — la modalità di guasto:
 * con `throw => false` le scritture su S3 **tacciono** (`storeAs()` → `false`) mentre `exists()`
 * e `get()` **sollevano**. Il gruppo in fondo usa il disco S3 vero puntato a un endpoint che non
 * risponde: il caricamento deve fallire con un messaggio d'errore e **nessuna riga** (prima della
 * revisione della beta.33 rispondeva «creato» e scriveva `path = "0"`), la stampa deve uscire senza
 * firma, la sonda di `verifica-persistenza` deve fermarsi. Ciò che resta fuori: R2/S3 veri.
 */

use App\Models\Documento;
use App\Models\User;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Documenti\SpazioDocumenti;
use App\Settings\PrintSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';

beforeEach(function () {
    config(['kondomanager.disco_documenti' => 'documenti_s3', 'kondomanager.disco_pubblici' => 'pubblici_s3']);
    Storage::fake('documenti_s3');
    Storage::fake('pubblici_s3');
    Storage::fake('local');
    Storage::fake('public');

    // Lo stesso amministratore di FatturaDocumentiTest: i tre permessi dell'archivio più il pannello.
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Crea documenti archivio', 'Elimina documenti archvio', 'Visualizza documenti archivio'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

it('carica, scarica ed elimina un allegato di fattura sul disco S3, senza toccare local', function () {
    $ctx = setupContabile();
    [$condominio, , , , $capitolo] = $ctx;
    $fattura = registraFatturaServiceTest($ctx, ['righe' => [[
        'descrizione' => 'Servizio', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
        'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
    ]]]);

    $this->actingAs($this->user)->post(
        route('admin.gestionale.fatture.documenti.store', [$condominio, $fattura]),
        ['file' => UploadedFile::fake()->create('quietanza.pdf', 12, 'application/pdf')]
    )->assertRedirect()->assertSessionHas('message.type', 'success');

    $documento = $fattura->fresh()->documenti->first();
    expect($documento)->not->toBeNull();
    Storage::disk('documenti_s3')->assertExists($documento->path);
    Storage::disk('local')->assertMissing($documento->path);
    expect($documento->path)->toStartWith('documenti/'.$condominio->id.'/');

    $this->actingAs($this->user)
        ->get(route('admin.gestionale.fatture.download', [$condominio, $fattura, $documento]))
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=quietanza.pdf');

    $this->actingAs($this->user)
        ->delete(route('admin.gestionale.fatture.documenti.destroy', [$condominio, $fattura, $documento]))
        ->assertRedirect();

    Storage::disk('documenti_s3')->assertMissing($documento->path);
    expect(Documento::find($documento->id))->toBeNull();
});

it('la firma delle stampe va sul disco pubblico S3, l\'anteprima è un URL firmato e mPDF riceve un file locale', function () {
    Storage::disk('pubblici_s3')->buildTemporaryUrlsUsing(fn ($path) => 'https://firmato.example/'.$path);

    $archivio = app(ArchivioDocumenti::class);
    $path = $archivio->salvaPubblico(UploadedFile::fake()->image('firma.png', 120, 40), 'settings/signatures');

    Storage::disk('pubblici_s3')->assertExists($path);
    Storage::disk('public')->assertMissing($path);

    expect($archivio->urlPubblico($path))->toBe('https://firmato.example/'.$path);

    $locale = $archivio->percorsoLocalePubblico($path);
    expect($locale)->not->toBeNull()
        ->and(is_file($locale))->toBeTrue()
        ->and(str_starts_with($locale, storage_path('framework/cache/pubblici')))->toBeTrue()
        ->and(filesize($locale))->toBe(Storage::disk('pubblici_s3')->size($path));

    $archivio->eliminaPubblico($path);
    Storage::disk('pubblici_s3')->assertMissing($path);
    @unlink($locale);
});

it('sul disco locale l\'anteprima della firma resta /storage/… e il percorso è il file stesso', function () {
    config(['kondomanager.disco_documenti' => 'local', 'kondomanager.disco_pubblici' => 'public']);
    $archivio = app(ArchivioDocumenti::class);

    $path = $archivio->salvaPubblico(UploadedFile::fake()->image('firma.png', 120, 40), 'settings/signatures');

    expect($archivio->urlPubblico($path))->toEndWith('/storage/'.$path)
        ->and($archivio->percorsoLocalePubblico($path))->toBe(Storage::disk('public')->path($path))
        ->and($archivio->locale())->toBeTrue();
});

/*
 * Endpoint morto: il disco S3 vero, nessun fake, un indirizzo che rifiuta la connessione.
 */
function discoS3Morto(): void
{
    config([
        'kondomanager.disco_documenti' => 'documenti_s3',
        'kondomanager.disco_pubblici' => 'pubblici_s3',
        'kondomanager.disco_documenti_richiesto' => 's3',
    ]);
    foreach (['documenti_s3', 'pubblici_s3'] as $disco) {
        config([
            "filesystems.disks.$disco.key" => 'x',
            "filesystems.disks.$disco.secret" => 'x',
            "filesystems.disks.$disco.region" => 'auto',
            "filesystems.disks.$disco.bucket" => 'kondo',
            "filesystems.disks.$disco.endpoint" => 'http://127.0.0.1:9',
            "filesystems.disks.$disco.use_path_style_endpoint" => true,
            "filesystems.disks.$disco.retries" => 0,
            "filesystems.disks.$disco.http" => ['connect_timeout' => 1, 'timeout' => 2],
        ]);
        Storage::forgetDisk($disco);
    }
}

it('con l\'endpoint morto il caricamento fallisce con un errore e non scrive nessuna riga', function () {
    discoS3Morto();
    $ctx = setupContabile();
    [$condominio, , , , $capitolo] = $ctx;
    $fattura = registraFatturaServiceTest($ctx, ['righe' => [[
        'descrizione' => 'Servizio', 'importo_imponibile' => 1000, 'aliquota_iva' => 22,
        'conto_id' => $capitolo->id, 'is_sopravvenienza' => false,
    ]]]);
    $documentiPrima = Documento::count();

    $this->actingAs($this->user)->post(
        route('admin.gestionale.fatture.documenti.store', [$condominio, $fattura]),
        ['file' => UploadedFile::fake()->create('quietanza.pdf', 12, 'application/pdf')]
    )->assertRedirect()->assertSessionHas('message.type', 'error');

    expect(Documento::count())->toBe($documentiPrima)
        ->and($fattura->fresh()->documenti)->toHaveCount(0);
});

it('con l\'endpoint morto la firma non ferma la stampa e la somma dello spazio resta quella della colonna', function () {
    discoS3Morto();
    $archivio = app(ArchivioDocumenti::class);
    app(PrintSettings::class)->firma_stampe_path = 'settings/signatures/firma.png';

    expect($archivio->percorsoLocalePubblico('settings/signatures/firma.png'))->toBeNull()
        ->and(app(SpazioDocumenti::class)->usatoByte())->toBe((int) Documento::sum('file_size'));
});

it('con l\'endpoint morto la sonda di verifica-persistenza si ferma con un errore', function () {
    discoS3Morto();

    expect(fn () => app(ArchivioDocumenti::class)->sonda())->toThrow(RuntimeException::class, 'non è utilizzabile');

    $this->artisan('kondomanager:verifica-persistenza')
        ->expectsOutputToContain('non è utilizzabile')
        ->assertFailed();
});
