<?php

/**
 * Lo studio in testa alle stampe, nelle impostazioni di stampa: la partita IVA, il codice fiscale, il sito e il logo, accanto ai dati che c'erano.
 *
 * - Le regole: la partita IVA ha 11 cifre; il codice fiscale 11 cifre o 16 lettere e cifre (l'omocodia mette lettere al posto delle cifre), e si
 *   salva in maiuscolo senza spazi; il sito fino a 150 caratteri, senza spazi.
 * - Il logo e la firma si riscrivono al caricamento in PNG, con il lato lungo al massimo 1.000 px; un PNG con la trasparenza la tiene (una firma
 *   sopra una carta intestata colorata non fa un riquadro bianco), gli altri escono senza. Ridotto a 1.000 px, un PNG con la trasparenza costa poco
 *   a ogni stampa; grande, mPDF lo decodifica tutto in memoria a ogni documento.
 * - Un'immagine di più di 4 milioni di pixel si rifiuta prima di aprirla (la misura la dà l'intestazione del file), con qualunque memoria del server:
 *   una di 4 milioni si riscrive anche con 128 MB e 35 già usati. L'SVG, il GIF e il WEBP non passano, e il rifiuto lo dice in italiano.
 * - Un JPG si riscrive girato come dice il suo orientamento.
 * - La firma caricata prima, più grande di 1.000 pixel, non si riscrive da sola: la pagina chiede di caricarla di nuovo (e di ridurla, se supera i
 *   4 milioni di pixel).
 * - Il file nuovo si salva prima di cancellare il vecchio: se il salvataggio non riesce, l'impostazione resta com'era, con il suo file.
 * - La pagina riceve i campi nuovi e l'indirizzo del logo; rimandati com'erano, le impostazioni non cambiano.
 */

use App\Models\User;
use App\Settings\PrintSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Pdf/Support/CartaDelloStudio.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']));
    $this->admin = User::factory()->create();
    $this->admin->assignRole($ruolo);
    Storage::fake('public');
});

/** Un PNG con la trasparenza: un quadrato blu al centro di un fondo trasparente. */
function cdiPngTrasparente(int $larghezza, int $altezza): string
{
    $im = imagecreatetruecolor($larghezza, $altezza);
    imagesavealpha($im, true);
    imagealphablending($im, false);
    imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imagefilledrectangle($im, (int) ($larghezza / 4), (int) ($altezza / 4), (int) ($larghezza * 3 / 4), (int) ($altezza * 3 / 4), imagecolorallocatealpha($im, 30, 58, 95, 0));
    ob_start();
    imagepng($im);
    imagedestroy($im);

    return (string) ob_get_clean();
}

/**
 * L'intestazione di un PNG che dice di essere largo e alto quanto si chiede, senza i pixel: chi la legge con `getimagesize` sa la misura, chi la
 * apre trova un file rotto.
 */
function cdiPngSoloIntestazione(int $larghezza, int $altezza): string
{
    $ihdr = pack('NNCCCCC', $larghezza, $altezza, 8, 2, 0, 0, 0);
    $pezzo = fn (string $tipo, string $dati) => pack('N', strlen($dati)).$tipo.$dati.pack('N', crc32($tipo.$dati));

    return "\x89PNG\r\n\x1a\n".$pezzo('IHDR', $ihdr).$pezzo('IDAT', gzcompress("\x00\x00\x00\x00")).$pezzo('IEND', '');
}

function cdiCarica(string $contenuto, string $nome = 'immagine.png', string $tipo = 'image/png'): UploadedFile
{
    $percorso = tempnam(sys_get_temp_dir(), 'cdi');
    file_put_contents($percorso, $contenuto);

    return new UploadedFile($percorso, $nome, $tipo, null, true);
}

function cdiSalva($test, array $campi)
{
    return $test->actingAs($test->admin)->post(route('impostazioni.stampe.store'), ['nota_legale_stampe' => 'Nota'] + $campi);
}

/** Il rifiuto del logo e della firma oltre i 4 milioni di pixel, con la misura scritta come la scrive la pagina («2.001 × 2.000»). */
function cdiTroppoGrande(string $misura): string
{
    return "L'immagine è di {$misura} pixel, più di 4 milioni: è troppo grande. Riducila (bastano 1.000 pixel sul lato lungo) e caricala di nuovo.";
}

/** Il tipo di colore di un PNG, dall'intestazione: 2 RGB, 6 RGB con la trasparenza, 3 a tavolozza. */
function cdiTipoColore(string $png): int
{
    return ord($png[25]);
}

it('la partita IVA, il codice fiscale e il sito si salvano; il codice fiscale in maiuscolo e senza spazi; la pagina li rilegge', function () {
    cdiSalva($this, ['studio_nome' => 'Studio Bianchi', 'studio_partita_iva' => '01234567890', 'studio_codice_fiscale' => ' bnc lra 80a01 f205x ',
        'studio_sito' => 'www.studiobianchi.it'])->assertSessionHasNoErrors();

    $s = app(PrintSettings::class)->refresh();
    expect([$s->studio_partita_iva, $s->studio_codice_fiscale, $s->studio_sito])->toBe(['01234567890', 'BNCLRA80A01F205X', 'www.studiobianchi.it']);
    $p = $this->actingAs($this->admin)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props'];
    expect($p['studio'])->toMatchArray(['partita_iva' => '01234567890', 'codice_fiscale' => 'BNCLRA80A01F205X', 'sito' => 'www.studiobianchi.it'])
        ->and($p['logo_stampe_url'])->toBeNull();

    // Svuotati, tornano vuoti.
    cdiSalva($this, ['studio_partita_iva' => '', 'studio_codice_fiscale' => '', 'studio_sito' => ''])->assertSessionHasNoErrors();
    $s = app(PrintSettings::class)->refresh();
    expect([$s->studio_partita_iva, $s->studio_codice_fiscale, $s->studio_sito])->toBe([null, null, null]);
});

it('le regole della partita IVA, del codice fiscale e del sito', function (string $campo, string $valore, bool $passa) {
    $r = cdiSalva($this, [$campo => $valore]);
    $passa ? $r->assertSessionHasNoErrors() : $r->assertSessionHasErrors($campo);
})->with([
    'la partita IVA di 11 cifre' => ['studio_partita_iva', '01234567890', true],
    'la partita IVA di 10 cifre' => ['studio_partita_iva', '0123456789', false],
    'la partita IVA con una lettera' => ['studio_partita_iva', '0123456789A', false],
    'la partita IVA con IT davanti' => ['studio_partita_iva', 'IT01234567890', false],
    'il codice fiscale di 16 lettere e cifre' => ['studio_codice_fiscale', 'BNCLRA80A01F205X', true],
    'il codice fiscale con l\'omocodia' => ['studio_codice_fiscale', 'BNCLRA80A0MF20RX', true],
    'il codice fiscale di 11 cifre (una società)' => ['studio_codice_fiscale', '01234567890', true],
    'il codice fiscale di 15 caratteri' => ['studio_codice_fiscale', 'BNCLRA80A01F205', false],
    'il codice fiscale con un trattino' => ['studio_codice_fiscale', 'BNCLRA80-01F205X', false],
    'il sito di 150 caratteri' => ['studio_sito', 'www.'.str_repeat('a', 143).'.it', true],
    'il sito di 151 caratteri' => ['studio_sito', 'www.'.str_repeat('a', 144).'.it', false],
    'il sito con uno spazio' => ['studio_sito', 'www.studio bianchi.it', false],
]);

it('il logo si riscrive al caricamento in PNG, con il lato lungo a 1.000 px; un PNG con la trasparenza la tiene: il fondo resta trasparente', function () {
    cdiSalva($this, ['logo_stampe' => cdiCarica(cdiPngTrasparente(2000, 1000))])->assertSessionHasNoErrors();

    $percorso = app(PrintSettings::class)->refresh()->logo_stampe_path;
    expect($percorso)->toStartWith('settings/logo/')->toEndWith('.png');
    $png = Storage::disk('public')->get($percorso);
    $im = imagecreatefromstring($png);
    $angolo = imagecolorsforindex($im, imagecolorat($im, 2, 2));
    $centro = imagecolorsforindex($im, imagecolorat($im, 500, 250));
    expect(cdiTipoColore($png))->toBe(6)
        ->and([imagesx($im), imagesy($im)])->toBe([1000, 500])
        ->and($angolo['alpha'])->toBe(127)
        ->and([$centro['red'], $centro['green'], $centro['blue'], $centro['alpha']])->toBe([30, 58, 95, 0]);
    $p = $this->actingAs($this->admin)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props'];
    expect($p['logo_stampe_url'])->toContain($percorso);
});

it('un PNG senza trasparenza si riscrive in PNG RGB, senza la trasparenza; un PNG a tavolozza con un colore trasparente la tiene', function () {
    cdiSalva($this, ['logo_stampe' => cdiCarica(cdsPng(1500, 600))])->assertSessionHasNoErrors();
    $png = Storage::disk('public')->get(app(PrintSettings::class)->refresh()->logo_stampe_path);
    expect(cdiTipoColore($png))->toBe(2)->and([imagesx(imagecreatefromstring($png)), imagesy(imagecreatefromstring($png))])->toBe([1000, 400]);

    cdiSalva($this, ['firma_stampe' => cdiCarica(cdiPngTavolozzaTrasparente(300, 100))])->assertSessionHasNoErrors();
    $png = Storage::disk('public')->get(app(PrintSettings::class)->refresh()->firma_stampe_path);
    $im = imagecreatefromstring($png);
    expect(cdiTipoColore($png))->toBe(6)
        ->and(imagecolorsforindex($im, imagecolorat($im, 290, 5))['alpha'])->toBe(127)
        ->and(imagecolorsforindex($im, imagecolorat($im, 0, 0))['alpha'])->toBe(0);
});

/** Un PNG RGB con un colore trasparente (il blocco `tRNS` su un PNG non a tavolozza, come lo scrivono i programmi che riducono i PNG): fondo bianco trasparente, un tratto blu. */
function cdiPngRgbConColoreTrasparente(int $larghezza, int $altezza): string
{
    $im = imagecreatetruecolor($larghezza, $altezza);
    imagefilledrectangle($im, 0, 0, $larghezza - 1, $altezza - 1, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, intdiv($larghezza, 4), intdiv($altezza * 5, 12), intdiv($larghezza * 3, 4), intdiv($altezza * 7, 12), imagecolorallocate($im, 30, 58, 95));
    ob_start();
    imagepng($im);
    $png = (string) ob_get_clean();
    imagedestroy($im);
    // Il colore trasparente di un PNG RGB: rosso, verde e blu, due byte l'uno. Il blocco va prima dei dati.
    $trns = "\x00\xFF\x00\xFF\x00\xFF";
    $idat = strpos($png, 'IDAT') - 4;

    return substr($png, 0, $idat).pack('N', strlen($trns)).'tRNS'.$trns.pack('N', crc32('tRNS'.$trns)).substr($png, $idat);
}

it('un PNG RGB con un colore trasparente si riscrive con la trasparenza, come logo e come firma: il fondo resta trasparente, il tratto pieno', function (string $campo, string $proprieta) {
    $png = cdiPngRgbConColoreTrasparente(1200, 600);
    expect(cdiTipoColore($png))->toBe(2);

    cdiSalva($this, [$campo => cdiCarica($png)])->assertSessionHasNoErrors();
    $salvato = Storage::disk('public')->get(app(PrintSettings::class)->refresh()->{$proprieta});
    $im = imagecreatefromstring($salvato);

    expect([imagesx($im), imagesy($im)])->toBe([1000, 500])
        ->and(cdiTipoColore($salvato))->toBe(6)
        ->and(imagecolorsforindex($im, imagecolorat($im, 5, 5))['alpha'])->toBe(127)
        ->and(imagecolorsforindex($im, imagecolorat($im, 500, 250)))->toBe(['red' => 30, 'green' => 58, 'blue' => 95, 'alpha' => 0]);
})->with([
    'il logo' => ['logo_stampe', 'logo_stampe_path'],
    'la firma' => ['firma_stampe', 'firma_stampe_path'],
]);

it('anche la firma si riscrive al caricamento, con la sua trasparenza; un\'immagine più piccola del tetto resta della sua misura; un JPG diventa un PNG', function () {
    cdiSalva($this, ['firma_stampe' => cdiCarica(cdiPngTrasparente(400, 150))])->assertSessionHasNoErrors();
    $png = Storage::disk('public')->get(app(PrintSettings::class)->refresh()->firma_stampe_path);
    $im = imagecreatefromstring($png);
    expect(cdiTipoColore($png))->toBe(6)
        ->and([imagesx($im), imagesy($im)])->toBe([400, 150])
        ->and(imagecolorsforindex($im, imagecolorat($im, 1, 1))['alpha'])->toBe(127);

    $jpg = imagecreatetruecolor(1200, 300);
    imagefill($jpg, 0, 0, imagecolorallocate($jpg, 200, 30, 30));
    ob_start();
    imagejpeg($jpg);
    cdiSalva($this, ['logo_stampe' => cdiCarica((string) ob_get_clean(), 'logo.jpg', 'image/jpeg')])->assertSessionHasNoErrors();
    $percorso = app(PrintSettings::class)->refresh()->logo_stampe_path;
    $png = Storage::disk('public')->get($percorso);
    expect($percorso)->toEndWith('.png')->and(substr($png, 1, 3))->toBe('PNG')->and(cdiTipoColore($png))->toBe(2)
        ->and([imagesx(imagecreatefromstring($png)), imagesy(imagecreatefromstring($png))])->toBe([1000, 250]);
});

it('un\'immagine di più di 4 milioni di pixel si rifiuta prima di aprirla, come logo e come firma, anche con la memoria delle prove; una di 4 milioni passa la misura', function () {
    $r = cdiSalva($this, ['logo_stampe' => cdiCarica(cdiPngSoloIntestazione(20000, 20000))]);
    $r->assertSessionHasErrors('logo_stampe');
    expect(session('errors')->first('logo_stampe'))->toBe(cdiTroppoGrande('20.000 × 20.000'));
    cdiSalva($this, ['firma_stampe' => cdiCarica(cdiPngSoloIntestazione(2001, 2000))])->assertSessionHasErrors(['firma_stampe' => cdiTroppoGrande('2.001 × 2.000')]);

    $s = app(PrintSettings::class)->refresh();
    expect($s->logo_stampe_path)->toBeNull()->and($s->firma_stampe_path)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    // 2.000 × 2.000 pixel: la misura passa; il file, che non ha i pixel, non si apre, e si rifiuta con un'altra frase.
    $r = cdiSalva($this, ['logo_stampe' => cdiCarica(cdiPngSoloIntestazione(2000, 2000))]);
    $r->assertSessionHasErrors('logo_stampe');
    expect(session('errors')->first('logo_stampe'))->toBe('L\'immagine non si apre: caricala di nuovo come PNG o JPG.');

    // Un file che comincia come un JPG e non ha la misura: per il tipo è un'immagine, la misura non si legge.
    $r = cdiSalva($this, ['firma_stampe' => cdiCarica("\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00".str_repeat("\x00", 64), 'firma.jpg', 'image/jpeg')]);
    $r->assertSessionHasErrors('firma_stampe');
    expect(session('errors')->first('firma_stampe'))->toBe('Il file non è un\'immagine che il programma sappia leggere: caricala come PNG o JPG.')
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('l\'SVG non passa, né come logo né come firma', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
    cdiSalva($this, ['logo_stampe' => cdiCarica($svg, 'logo.svg', 'image/svg+xml')])->assertSessionHasErrors('logo_stampe');
    cdiSalva($this, ['firma_stampe' => cdiCarica($svg, 'firma.svg', 'image/svg+xml')])->assertSessionHasErrors('firma_stampe');
    expect(app(PrintSettings::class)->refresh()->logo_stampe_path)->toBeNull();
});

it('un logo nuovo prende il posto del vecchio, e il vecchio si cancella; «Rimuovi» lo toglie', function () {
    cdiSalva($this, ['logo_stampe' => cdiCarica(cdsPng(600, 300))])->assertSessionHasNoErrors();
    $vecchio = app(PrintSettings::class)->refresh()->logo_stampe_path;
    cdiSalva($this, ['logo_stampe' => cdiCarica(cdsPng(300, 300))])->assertSessionHasNoErrors();
    $nuovo = app(PrintSettings::class)->refresh()->logo_stampe_path;
    expect($nuovo)->not->toBe($vecchio)
        ->and(Storage::disk('public')->exists($vecchio))->toBeFalse()
        ->and(Storage::disk('public')->exists($nuovo))->toBeTrue();

    cdiSalva($this, ['delete_logo_stampe' => true])->assertSessionHasNoErrors();
    expect(app(PrintSettings::class)->refresh()->logo_stampe_path)->toBeNull()
        ->and(Storage::disk('public')->exists($nuovo))->toBeFalse();
});

it('il file nuovo si salva prima di cancellare il vecchio: se il salvataggio non riesce, la firma e il logo restano quelli di prima, con il loro file', function (string $campo, string $proprieta, string $cartella) {
    $disco = Storage::disk('public');
    $disco->put("vecchie/{$campo}.png", cdsPng());
    $s = app(PrintSettings::class);
    $s->{$proprieta} = "vecchie/{$campo}.png";
    $s->save();
    // La cartella dove va il file nuovo non si può scrivere: il salvataggio non riesce.
    $disco->makeDirectory($cartella);
    chmod($disco->path($cartella), 0555);
    try {
        cdiSalva($this, [$campo => cdiCarica(cdsPng(300, 100))])->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'error');
    } finally {
        chmod($disco->path($cartella), 0755);
    }

    expect(app(PrintSettings::class)->refresh()->{$proprieta})->toBe("vecchie/{$campo}.png")
        ->and($disco->exists("vecchie/{$campo}.png"))->toBeTrue()
        ->and($disco->files($cartella))->toBe([]);
})->with([
    'la firma' => ['firma_stampe', 'firma_stampe_path', 'settings/signatures'],
    'il logo' => ['logo_stampe', 'logo_stampe_path', 'settings/logo'],
]);

it('se le impostazioni non si salvano, il logo nuovo già scritto si toglie, e restano il logo di prima e il suo file', function () {
    $disco = Storage::disk('public');
    $disco->put('vecchie/logo.png', cdsPng());
    $s = app(PrintSettings::class);
    $s->logo_stampe_path = 'vecchie/logo.png';
    $s->save();
    // Il database rifiuta di scrivere le impostazioni.
    \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER cdi_blocca_u BEFORE UPDATE ON settings BEGIN SELECT RAISE(ABORT, 'impostazioni bloccate'); END;");
    \Illuminate\Support\Facades\DB::unprepared("CREATE TRIGGER cdi_blocca_i BEFORE INSERT ON settings BEGIN SELECT RAISE(ABORT, 'impostazioni bloccate'); END;");

    cdiSalva($this, ['logo_stampe' => cdiCarica(cdsPng(300, 100))])->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'error');

    \Illuminate\Support\Facades\DB::unprepared('DROP TRIGGER cdi_blocca_u; DROP TRIGGER cdi_blocca_i;');
    expect(app(PrintSettings::class)->refresh()->logo_stampe_path)->toBe('vecchie/logo.png')
        ->and($disco->exists('vecchie/logo.png'))->toBeTrue()
        ->and($disco->files('settings/logo'))->toBe([]);
});

it('un file di prima che il disco non cancella resta sul disco: le impostazioni sono salvate con il file nuovo', function () {
    Storage::fake('public', ['throw' => true]);
    $disco = Storage::disk('public');
    $disco->put('vecchie/logo.png', cdsPng());
    $s = app(PrintSettings::class);
    $s->logo_stampe_path = 'vecchie/logo.png';
    $s->save();
    chmod($disco->path('vecchie'), 0555);
    try {
        cdiSalva($this, ['logo_stampe' => cdiCarica(cdsPng(300, 100))])->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'success');
    } finally {
        chmod($disco->path('vecchie'), 0755);
    }

    $nuovo = app(PrintSettings::class)->refresh()->logo_stampe_path;
    expect($nuovo)->toStartWith('settings/logo/')
        ->and($disco->exists($nuovo))->toBeTrue()
        ->and($disco->exists('vecchie/logo.png'))->toBeTrue();
});

it('senza il permesso delle impostazioni le impostazioni delle stampe rispondono 403 prima di guardare i dati (anche con una partita IVA sbagliata), e niente si scrive, nemmeno il logo sul disco', function (array $piu) {
    cdsStudioPieno(false);
    $prima = app(PrintSettings::class)->refresh()->toArray();
    $altro = User::factory()->create();
    $altro->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->actingAs($altro)->post(route('impostazioni.stampe.store'), ['nota_legale_stampe' => 'Nota di chi non può', 'studio_nome' => 'Studio di chi non può',
        'logo_stampe' => cdiCarica(cdsPng(300, 100)), 'firma_stampe' => cdiCarica(cdsPng(300, 100))] + $piu)->assertForbidden();

    expect(app(PrintSettings::class)->refresh()->toArray())->toBe($prima)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'con i dati giusti' => [[]],
    'con una partita IVA sbagliata' => [['studio_partita_iva' => 'non valida']],
]);

it('la pagina riceve lo studio com\'è salvato, con i caratteri scomodi; rimandato com\'è, le impostazioni non cambiano', function () {
    cdsStudioPieno(true, ['studio_nome' => 'Studio «Bianchi» & Figli <s.r.l.>', 'studio_indirizzo' => "Via dell'Ateneo 1/A, 20121 Milano"]);
    $prima = app(PrintSettings::class)->refresh()->toArray();

    $p = $this->actingAs($this->admin)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props'];
    $corpo = ['nota_legale_stampe' => $p['nota_legale_stampe'], 'delete_firma_stampe' => false, 'delete_logo_stampe' => false];
    foreach ($p['studio'] as $chiave => $valore) {
        $corpo['studio_'.$chiave] = $valore;
    }
    $this->actingAs($this->admin)->post(route('impostazioni.stampe.store'), $corpo)->assertSessionHasNoErrors();

    expect(app(PrintSettings::class)->refresh()->toArray())->toBe($prima)
        ->and($p['studio']['nome'])->toBe('Studio «Bianchi» & Figli <s.r.l.>')
        ->and($p['logo_stampe_url'])->toContain('settings/logo/logo-delle-prove.png');
});

it('un logo con la trasparenza di 4 milioni di pixel, caricato, si stampa con la trasparenza con 128 MB di memoria e 35 già usati; un PNG con la trasparenza grande, non riscritto, no', function () {
    cdiSalva($this, ['logo_stampe' => cdiCarica(cdiPngTrasparente(2000, 2000))])->assertSessionHasNoErrors();
    $riscritto = Storage::disk('public')->path(app(PrintSettings::class)->refresh()->logo_stampe_path);
    expect(cdiTipoColore((string) file_get_contents($riscritto)))->toBe(6);
    $grezzo = tempnam(sys_get_temp_dir(), 'cdi').'.png';
    file_put_contents($grezzo, cdiPngTrasparente(4000, 4000));

    // Un documento di tre pagine con il logo in ogni pagina, in un processo a parte con il tetto di memoria di un hosting piccolo e la memoria che una
    // richiesta ha già in uso.
    $stampa = function (string $logo): array {
        $codice = 'require getcwd()."/vendor/autoload.php"; $zavorra = str_repeat("x", 35 * 1048576); $m = new \Mpdf\Mpdf(["tempDir" => sys_get_temp_dir()]);'
            .' for ($i = 0; $i < 3; $i++) { if ($i) { $m->AddPage(); } $m->WriteHTML("<img src=\"'.$logo.'\" style=\"max-height: 14mm; max-width: 40mm;\">"); }'
            .' $m->Output("", "S"); echo "fatto";';
        $processo = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $codice], base_path());
        $processo->setTimeout(60)->run();

        return [$processo->getExitCode(), trim($processo->getOutput())];
    };

    expect($stampa($riscritto))->toBe([0, 'fatto'])
        ->and($stampa($grezzo)[1])->not->toBe('fatto');
    @unlink($grezzo);
});

/** Un PNG scritto a mano, con il tipo di colore che si chiede (4: grigio con la trasparenza), di pixel grigi. */
function cdiPngAMano(int $larghezza, int $altezza, int $tipoColore): string
{
    $byte = ['0' => 1, '2' => 3, '4' => 2, '6' => 4][(string) $tipoColore];
    $righe = str_repeat("\x00".str_repeat("\x80", $larghezza * $byte), $altezza);
    $pezzo = fn (string $tipo, string $dati) => pack('N', strlen($dati)).$tipo.$dati.pack('N', crc32($tipo.$dati));

    return "\x89PNG\r\n\x1a\n".$pezzo('IHDR', pack('NNCCCCC', $larghezza, $altezza, 8, $tipoColore, 0, 0, 0)).$pezzo('IDAT', gzcompress($righe)).$pezzo('IEND', '');
}

/** Un PNG a tavolozza con un colore trasparente (il blocco `tRNS`), come lo salvano molti programmi per le firme scansionate. */
function cdiPngTavolozzaTrasparente(int $larghezza, int $altezza): string
{
    $im = imagecreate($larghezza, $altezza);
    $bianco = imagecolorallocate($im, 255, 255, 255);
    imagecolortransparent($im, $bianco);
    imageline($im, 0, 0, $larghezza - 1, $altezza - 1, imagecolorallocate($im, 0, 0, 0));
    ob_start();
    imagepng($im);
    imagedestroy($im);

    return (string) ob_get_clean();
}

function cdiJpg(int $larghezza, int $altezza): string
{
    $im = imagecreatetruecolor($larghezza, $altezza);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
    ob_start();
    imagejpeg($im);
    imagedestroy($im);

    return (string) ob_get_clean();
}

/** La firma salvata com'era prima che il programma riscrivesse le immagini: il file così com'è, sul disco dei file pubblici. */
function cdiFirmaDiPrima(string $contenuto, string $estensione = 'png'): void
{
    Storage::disk('public')->put("settings/signatures/firma-di-prima.{$estensione}", $contenuto);
    $s = app(PrintSettings::class);
    $s->firma_stampe_path = "settings/signatures/firma-di-prima.{$estensione}";
    $s->save();
}

it('la firma caricata prima, più grande di 1.000 pixel (con lo sfondo trasparente o no): la pagina chiede di caricarla di nuovo e dice perché; ricaricata, la richiesta sparisce. Lo sfondo trasparente da solo non pesa: la richiesta non c\'è', function (string $contenuto, string $estensione, ?string $riga) {
    cdiFirmaDiPrima($contenuto, $estensione);

    $chiesta = $this->actingAs($this->admin)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props']['firma_da_ricaricare'];
    expect($chiesta)->toBe($riga);
    if ($riga === null) {
        return;
    }

    cdiSalva($this, ['firma_stampe' => cdiCarica($contenuto, "firma.{$estensione}", $estensione === 'jpg' ? 'image/jpeg' : 'image/png')])->assertSessionHasNoErrors();
    expect($this->actingAs($this->admin)->get(route('impostazioni.stampe'))->viewData('page')['props']['firma_da_ricaricare'])->toBeNull();
})->with(fn () => [
    'un PNG con la trasparenza di 400 × 150' => [cdiPngTrasparente(400, 150), 'png', null],
    'un PNG grigio con la trasparenza' => [cdiPngAMano(40, 20, 4), 'png', null],
    'un PNG a tavolozza con un colore trasparente' => [cdiPngTavolozzaTrasparente(300, 100), 'png', null],
    'un PNG senza trasparenza di 1.500 × 600' => [cdsPng(1500, 600), 'png',
        'La firma è di 1.500 × 600 pixel: ogni PDF con la firma la porta tutta, e pesa più del necessario. Caricala di nuovo: il programma la riduce a 1.000 pixel sul lato lungo.'],
    'un PNG con la trasparenza di 3.000 × 1.200' => [cdiPngTrasparente(3000, 1200), 'png',
        'La firma ha lo sfondo trasparente ed è di 3.000 × 1.200 pixel: a ogni stampa il programma la legge tutta in memoria, e i PDF con la firma sono più lenti e pesanti. Caricala di nuovo: il programma la riduce a 1.000 pixel sul lato lungo, e lo sfondo resta trasparente.'],
    'un JPG di 2.000 × 1.000' => [cdiJpg(2000, 1000), 'jpg',
        'La firma è di 2.000 × 1.000 pixel: ogni PDF con la firma la porta tutta, e pesa più del necessario. Caricala di nuovo: il programma la riduce a 1.000 pixel sul lato lungo.'],
    'un PNG senza trasparenza di 1.000 × 400' => [cdsPng(1000, 400), 'png', null],
    'un JPG di 800 × 300' => [cdiJpg(800, 300), 'jpg', null],
]);

it('la firma caricata prima, di più di 4 milioni di pixel: la pagina chiede di ridurla prima di caricarla di nuovo, perché così non si carica', function () {
    cdiFirmaDiPrima(cdiPngTrasparente(4000, 1500));

    expect($this->actingAs($this->admin)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props']['firma_da_ricaricare'])
        ->toBe('La firma ha lo sfondo trasparente ed è di 4.000 × 1.500 pixel: a ogni stampa il programma la legge tutta in memoria, e i PDF con la firma sono più lenti e pesanti. Riducila (bastano 1.000 pixel sul lato lungo) e caricala di nuovo: il programma non prende immagini di più di 4 milioni di pixel.');
    cdiSalva($this, ['firma_stampe' => cdiCarica(cdiPngTrasparente(4000, 1500))])->assertSessionHasErrors(['firma_stampe' => cdiTroppoGrande('4.000 × 1.500')]);
});

it('senza firma, o con una firma che il disco non ha, la pagina non chiede niente', function () {
    expect($this->actingAs($this->admin)->get(route('impostazioni.stampe'))->viewData('page')['props']['firma_da_ricaricare'])->toBeNull();
    $s = app(PrintSettings::class);
    $s->firma_stampe_path = 'settings/signatures/non-ce.png';
    $s->save();
    expect($this->actingAs($this->admin)->get(route('impostazioni.stampe'))->assertOk()->viewData('page')['props']['firma_da_ricaricare'])->toBeNull();
});

/**
 * `ImmagineDiStampa::rifiuto()` e, se l'immagine passa, `riscritta()`, in un processo a parte con il limite di memoria che si chiede. `$zavorra`: i MB
 * già usati prima, come in una richiesta vera (il programma ne ha già in memoria 30–40). Della riscritta dice la misura, il tipo di colore del PNG e la
 * trasparenza del pixel in alto a sinistra (127 trasparente, 0 pieno): «riscritta 1000x1000 6 127».
 */
function cdiRifiutoInUnProcesso(string $file, string $limite, int $zavorra = 0): array
{
    $codice = 'require getcwd()."/vendor/autoload.php"; $zavorra = str_repeat("x", (int) $argv[2] * 1048576); $r = \App\Services\PDF\ImmagineDiStampa::rifiuto($argv[1]);'
        .' if ($r === null) { $png = \App\Services\PDF\ImmagineDiStampa::riscritta($argv[1]); unset($zavorra); $im = imagecreatefromstring($png);'
        .' echo "riscritta ".imagesx($im)."x".imagesy($im)." ".ord($png[25])." ".imagecolorsforindex($im, imagecolorat($im, 0, 0))["alpha"]; } else { echo $r; }';
    $processo = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'memory_limit='.$limite, '-r', $codice, $file, (string) $zavorra], base_path());
    $processo->setTimeout(120)->run();

    return [$processo->getExitCode(), trim($processo->getOutput().$processo->getErrorOutput())];
}

it('con 128 MB e 35 già usati, un\'immagine di 4 milioni di pixel si riscrive (un PNG con la trasparenza la tiene, anche con le righe più care per la memoria), una di 4,1 milioni si rifiuta con la frase: mai un errore fatale', function (string $file, string $atteso) {
    [$uscita, $testo] = cdiRifiutoInUnProcesso($file, '128M', 35);
    @unlink($file);

    expect($testo)->not->toContain('Fatal error')->toBe($atteso)
        ->and($uscita)->toBe(0);
})->with(fn () => [
    // I pixel del file sono grigi a metà trasparenza: l'alfa di GD è 63 (0 pieno, 127 trasparente).
    'un PNG con la trasparenza di 2.000 × 2.000' => [cdiPngGrandeSuFile(2000, 2000, 6), 'riscritta 1000x1000 6 63'],
    // Una riga di 1.025 pixel con la trasparenza è di 4.100 byte: la memoria la conta per 8 KB (il doppio).
    'un PNG con la trasparenza di 1.025 × 3.902, le righe più care' => [cdiPngGrandeSuFile(1025, 3902, 6), 'riscritta 263x1000 6 63'],
    'un PNG grigio con la trasparenza di 2.000 × 2.000' => [cdiPngGrandeSuFile(2000, 2000, 4), 'riscritta 1000x1000 6 63'],
    // Un PNG RGB con un colore trasparente passa da due tele, una della sua misura: il caso più caro per la memoria (circa 61 MB con le righe più care).
    'un PNG RGB con un colore trasparente di 2.000 × 2.000' => [cdiPngGrandeSuFile(2000, 2000, 2, true), 'riscritta 1000x1000 6 127'],
    'un PNG RGB con un colore trasparente di 1.025 × 3.902, le righe più care' => [cdiPngGrandeSuFile(1025, 3902, 2, true), 'riscritta 263x1000 6 127'],
    'un PNG senza trasparenza di 2.000 × 2.000' => [cdiPngGrandeSuFile(2000, 2000, 2), 'riscritta 1000x1000 2 0'],
    'un JPG di 2.000 × 2.000' => [cdiJpgSuFile(2000, 2000), 'riscritta 1000x1000 2 0'],
    'un PNG con la trasparenza di 2.050 × 2.000 (4,1 milioni)' => [cdiPngGrandeSuFile(2050, 2000, 6), cdiTroppoGrande('2.050 × 2.000')],
    'un PNG con la trasparenza di 5.800 × 5.000' => [cdiPngGrandeSuFile(5800, 5000, 6), cdiTroppoGrande('5.800 × 5.000')],
]);

it('un PNG con la trasparenza di 4 milioni di pixel si riscrive con la sua trasparenza: dove era trasparente resta trasparente', function () {
    $file = tempnam(sys_get_temp_dir(), 'cdi').'.png';
    file_put_contents($file, cdiPngTrasparente(2000, 2000));
    [$uscita, $testo] = cdiRifiutoInUnProcesso($file, '128M', 35);
    @unlink($file);

    expect($testo)->toBe('riscritta 1000x1000 6 127')->and($uscita)->toBe(0);
});

it('il tetto è lo stesso con qualunque memoria: senza limite e con 2 GB, 4.000.001 pixel si rifiutano e 4 milioni passano la misura', function (string $limite) {
    $file = tempnam(sys_get_temp_dir(), 'cdi').'.png';
    file_put_contents($file, cdiPngSoloIntestazione(4000001, 1));
    [, $oltre] = cdiRifiutoInUnProcesso($file, $limite);
    file_put_contents($file, cdiPngSoloIntestazione(2000, 2000));
    [, $esatti] = cdiRifiutoInUnProcesso($file, $limite);
    @unlink($file);

    expect($oltre)->toBe(cdiTroppoGrande('4.000.001 × 1'))
        // 4 milioni esatti passano la misura; il file, che non ha i pixel, non si apre.
        ->and($esatti)->toContain('L\'immagine non si apre');
})->with(['senza limite' => ['-1'], 'con 2 GB' => ['2G']]);

it('un JPG fatto col telefono, con l\'orientamento scritto nei dati della foto, si riscrive girato come lo mostra il telefono', function () {
    // 400 × 200 con una banda rossa a sinistra; l'orientamento 6 dice: per vederla giusta, girala di 90 gradi in senso orario.
    $im = imagecreatetruecolor(400, 200);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 39, 199, imagecolorallocate($im, 220, 0, 0));
    ob_start();
    imagejpeg($im, null, 95);
    $jpg = (string) ob_get_clean();
    imagedestroy($im);
    $tiff = "MM\x00\x2A\x00\x00\x00\x08"."\x00\x01"."\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00"."\x00\x00\x00\x00";
    $app1 = "Exif\x00\x00".$tiff;
    $girato = substr($jpg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpg, 2);

    cdiSalva($this, ['logo_stampe' => cdiCarica($girato, 'foto.jpg', 'image/jpeg')])->assertSessionHasNoErrors();
    $png = imagecreatefromstring(Storage::disk('public')->get(app(PrintSettings::class)->refresh()->logo_stampe_path));
    $rosso = fn (int $x, int $y) => imagecolorsforindex($png, imagecolorat($png, $x, $y))['green'] < 80;
    expect([imagesx($png), imagesy($png)])->toBe([200, 400])
        ->and($rosso(100, 10))->toBeTrue()
        ->and($rosso(100, 390))->toBeFalse();
});

it('il rifiuto del logo e della firma dice in italiano che cosa caricare: PNG o JPG, e il limite; GIF, WEBP e SVG restano fuori', function (string $campo, string $frase, string $nome, string $tipo, string $contenuto) {
    $r = cdiSalva($this, [$campo => cdiCarica($contenuto, $nome, $tipo)]);
    $r->assertSessionHasErrors($campo);
    expect(session('errors')->get($campo))->toBe([$frase]);
})->with(function () {
    $limite = \App\Support\LimiteCaricamento::etichetta(2.0);
    $gif = base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
    $webp = base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEAAUAmJaQAA3AA/v89WAAAAA==');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>';
    $grande = cdsPng(20, 20).str_repeat("\x00", (\App\Support\LimiteCaricamento::regolaMax(2.0) + 64) * 1024);
    $casi = [];
    foreach (['logo_stampe' => ['Il logo va caricato come PNG o JPG.', "Il logo può essere grande al massimo {$limite}."],
        'firma_stampe' => ['La firma va caricata come PNG o JPG.', "La firma può essere grande al massimo {$limite}."]] as $campo => [$tipoSbagliato, $troppo]) {
        $casi["{$campo}: un GIF"] = [$campo, $tipoSbagliato, 'immagine.gif', 'image/gif', $gif];
        $casi["{$campo}: un WEBP"] = [$campo, $tipoSbagliato, 'immagine.webp', 'image/webp', $webp];
        $casi["{$campo}: un SVG"] = [$campo, $tipoSbagliato, 'immagine.svg', 'image/svg+xml', $svg];
        $casi["{$campo}: un testo chiamato .png"] = [$campo, $tipoSbagliato, 'immagine.png', 'image/png', 'non sono un\'immagine'];
        $casi["{$campo}: troppo grande"] = [$campo, $troppo, 'immagine.png', 'image/png', $grande];
    }

    return $casi;
});

it('la partita IVA scritta con gli spazi passa e si salva senza', function () {
    cdiSalva($this, ['studio_partita_iva' => '012 345 678 90'])->assertSessionHasNoErrors();
    expect(app(PrintSettings::class)->refresh()->studio_partita_iva)->toBe('01234567890');
});

/**
 * Un PNG grande scritto su un file a pezzi, una riga alla volta (senza tenerlo in memoria): grigio, con il tipo di colore che si chiede (2 RGB, 6 RGB
 * con la trasparenza). Con `$grigioTrasparente`, un PNG RGB con il blocco `tRNS` che fa trasparente quel grigio. Il percorso del file.
 */
function cdiPngGrandeSuFile(int $larghezza, int $altezza, int $tipoColore, bool $grigioTrasparente = false): string
{
    $byte = [0 => 1, 2 => 3, 4 => 2, 6 => 4][$tipoColore];
    $riga = "\x00".str_repeat("\x80", $larghezza * $byte);
    $flusso = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 1]);
    $dati = '';
    for ($y = 0; $y < $altezza; $y++) {
        $dati .= deflate_add($flusso, $riga, ZLIB_NO_FLUSH);
    }
    $dati .= deflate_add($flusso, '', ZLIB_FINISH);
    $pezzo = fn (string $tipo, string $contenuto) => pack('N', strlen($contenuto)).$tipo.$contenuto.pack('N', crc32($tipo.$contenuto));
    $file = tempnam(sys_get_temp_dir(), 'cdi').'.png';
    $trns = $grigioTrasparente ? $pezzo('tRNS', "\x00\x80\x00\x80\x00\x80") : '';
    file_put_contents($file, "\x89PNG\r\n\x1a\n".$pezzo('IHDR', pack('NNCCCCC', $larghezza, $altezza, 8, $tipoColore, 0, 0, 0)).$trns.$pezzo('IDAT', $dati).$pezzo('IEND', ''));

    return $file;
}

function cdiJpgSuFile(int $larghezza, int $altezza): string
{
    $file = tempnam(sys_get_temp_dir(), 'cdi').'.jpg';
    $im = imagecreatetruecolor($larghezza, $altezza);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
    imagejpeg($im, $file, 60);
    imagedestroy($im);

    return $file;
}

/** Un JPG 400 × 200 bianco, con un quadrato rosso in alto a sinistra e uno verde in alto a destra, e l'orientamento scritto nei dati della foto. */
function cdiJpgOrientato(int $orientamento): string
{
    $im = imagecreatetruecolor(400, 200);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagefilledrectangle($im, 0, 0, 59, 59, imagecolorallocate($im, 220, 0, 0));
    imagefilledrectangle($im, 340, 0, 399, 59, imagecolorallocate($im, 0, 180, 0));
    ob_start();
    imagejpeg($im, null, 95);
    $jpg = (string) ob_get_clean();
    imagedestroy($im);
    $tiff = "MM\x00\x2A\x00\x00\x00\x08"."\x00\x01"."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientamento)."\x00\x00"."\x00\x00\x00\x00";
    $app1 = "Exif\x00\x00".$tiff;

    return substr($jpg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpg, 2);
}

it('una foto specchiata si riscrive come la mostra il telefono: l\'orientamento 2 (specchiata) e il 5 (specchiata e girata)', function (int $orientamento, array $misura, array $rosso, array $verde) {
    cdiSalva($this, ['logo_stampe' => cdiCarica(cdiJpgOrientato($orientamento), 'foto.jpg', 'image/jpeg')])->assertSessionHasNoErrors();
    $png = imagecreatefromstring(Storage::disk('public')->get(app(PrintSettings::class)->refresh()->logo_stampe_path));
    $colore = function (array $punto) use ($png): string {
        $c = imagecolorsforindex($png, imagecolorat($png, $punto[0], $punto[1]));

        return $c['red'] > 150 && $c['green'] < 90 ? 'rosso' : ($c['green'] > 120 && $c['red'] < 90 ? 'verde' : 'altro');
    };

    expect([imagesx($png), imagesy($png)])->toBe($misura)
        ->and($colore($rosso))->toBe('rosso')
        ->and($colore($verde))->toBe('verde');
})->with([
    // 2: specchiata da sinistra a destra; il rosso va in alto a destra, il verde in alto a sinistra.
    'orientamento 2' => [2, [400, 200], [370, 30], [30, 30]],
    // 5: le righe della foto sono le colonne dell'immagine (specchiata e girata di 90 gradi); il rosso resta in alto a sinistra, il verde va in basso a sinistra.
    'orientamento 5' => [5, [200, 400], [30, 30], [30, 370]],
]);
