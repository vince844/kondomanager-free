<?php

/**
 * I file caricati come prova della spedizione, come accordo di rientro firmato e come verbale della dispensa: un PDF, un JPG o un PNG devono
 * esserlo davvero (il contenuto, non solo il nome), un `.eml` e un `.p7m` si riconoscono dal nome; sul disco il file prende l'estensione
 * dichiarata, non quella che il contenuto fa indovinare. I file sono caricati veri: il tipo si legge dal contenuto, come in produzione.
 */

use App\Models\Documento;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\DispensaSeiMesi;
use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LettereScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->user->assignRole($ruolo);
});

/** Un file caricato vero: il tipo lo legge `finfo` dal contenuto. */
function cinFile(string $nome, string $contenuto): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'cin');
    file_put_contents($tmp, $contenuto);

    return new UploadedFile($tmp, $nome, null, null, true);
}

const CIN_HTML = "<!DOCTYPE html>\n<html><script>alert(1)</script></html>\n";
const CIN_PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 10 10]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

test('la prova della spedizione: un HTML o un PHP chiamato .pdf, o un HTML chiamato .png, si rifiutano; un PDF vero, un .eml e un .p7m entrano con l\'estensione dichiarata', function () {
    $s = lttScenario($this);
    $riga = lttRigaScritta($s, 'ugo', 'diffida', 'stampata', 0);
    $carica = fn (UploadedFile $f) => $this->actingAs($this->user)->post(lttRotta($s, 'prove.store', ['invio' => $riga->id]), ['file' => $f]);

    foreach (['finto.pdf' => CIN_HTML, 'codice.pdf' => "<?php\nsystem(\$_GET['c']);\n", 'foto.png' => '<html></html>'] as $nome => $contenuto) {
        $carica(cinFile($nome, $contenuto))->assertSessionHasErrors(['file' => \App\Support\FileComeIlNome::FRASE]);
    }
    // Un PDF o un'immagine veri con l'estensione sbagliata: la frase dice che cosa sono e come rinominarli.
    $carica(cinFile('foto.jpg', CIN_PDF))->assertSessionHasErrors(['file' => 'Il file «foto.jpg» è un PDF: rinominalo con l\'estensione .pdf e caricalo di nuovo.']);
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $carica(cinFile('ricevuta.jpg', $png))->assertSessionHasErrors(['file' => 'Il file «ricevuta.jpg» è un PNG: rinominalo con l\'estensione .png e caricalo di nuovo.']);
    expect(Documento::where('documentable_type', RegistroInvio::class)->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    foreach (['avviso.pdf' => CIN_PDF, 'ricevuta.eml' => "From: a@pec.it\r\nContent-Type: text/html\r\n\r\n<html></html>", 'firmato.P7M' => random_bytes(32)] as $nome => $contenuto) {
        $carica(cinFile($nome, $contenuto))->assertSessionHasNoErrors();
    }
    expect(Documento::where('documentable_type', RegistroInvio::class)->orderBy('id')->pluck('path')->map(fn ($p) => pathinfo($p, PATHINFO_EXTENSION))->all())
        ->toBe(['pdf', 'eml', 'p7m']);
});

test('l\'accordo di rientro firmato: un HTML chiamato .pdf si rifiuta e non registra l\'accordo; un PDF vero entra con l\'estensione pdf', function () {
    $s = lttScenario($this);
    $corpo = fn (UploadedFile $f) => [
        'anagrafica_id' => $s['persone']['ugo']->id, 'data_accordo' => '2026-03-20', 'fino_al' => '2026-09-30', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => $s['immobili']['A']->id, 'importo' => '60,00']], 'documento' => $f,
    ];
    $rotta = route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]);
    $this->actingAs($this->user)->postJson($rotta, $corpo(cinFile('accordo.pdf', CIN_HTML)))->assertStatus(422)->assertJsonValidationErrors('documento');
    expect(AccordoRientro::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);

    $this->actingAs($this->user)->postJson($rotta, $corpo(cinFile('accordo.pdf', CIN_PDF)))->assertCreated();
    expect(pathinfo(Documento::where('documentable_type', AccordoRientro::class)->sole()->path, PATHINFO_EXTENSION))->toBe('pdf');
});

test('il verbale della dispensa dei sei mesi: un HTML chiamato .png si rifiuta e non registra la dispensa; un PNG vero entra con l\'estensione png', function () {
    $s = lttScenario($this);
    $rotta = route('admin.gestionale.dispense.store', ['condominio' => $s['c']->id]);
    $corpo = fn (UploadedFile $f) => ['data_delibera' => '2026-03-01', 'esercizi' => [(int) $s['e']->id], 'per_tutti' => '1', 'verbale' => $f];
    $this->actingAs($this->user)->post($rotta, $corpo(cinFile('verbale.png', CIN_HTML)))->assertSessionHasErrors(['verbale' => \App\Support\FileComeIlNome::FRASE]);
    expect(DispensaSeiMesi::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $this->actingAs($this->user)->post($rotta, $corpo(cinFile('verbale.png', $png)))->assertSessionHasNoErrors();
    expect(pathinfo(Documento::where('documentable_type', DispensaSeiMesi::class)->sole()->path, PATHINFO_EXTENSION))->toBe('png');
});
