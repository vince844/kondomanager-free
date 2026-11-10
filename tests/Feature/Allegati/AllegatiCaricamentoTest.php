<?php

/**
 * # Allegati: il caricamento (Coda 460, decisioni 239.7 e 241)
 *
 * Foto JPG, PNG, WebP e PDF, fino a cinque per segnalazione o comunicazione, con il limite di dimensione dei
 * documenti (`LimiteCaricamento::regolaMax()`). Il contenuto deve essere quello che il nome dice. Il file va
 * sul disco dei documenti con un nome casuale, nella cartella dell'elemento (`documenti/allegati/<tipo>/<id>/`),
 * con un percorso relativo che è lo stesso sul disco locale e su un disco S3: database e file passano insieme
 * da un'installazione all'altra. Il nome originale, il tipo letto dal contenuto, il peso e l'autore stanno nella
 * riga. Dove c'è un limite di spazio (`LIMITE_SPAZIO_MB`) e lo spazio è finito, gli allegati nuovi si rifiutano.
 *
 * Lo stato: pubblicato subito per chi può approvare; per chi ha «Pubblica» solo se l'elemento non chiede la
 * moderazione; in attesa in ogni altro caso. Il valore di partenza della moderazione di un elemento nuovo viene
 * dall'impostazione generale, di serie sì.
 *
 * Cosa non copre: due caricamenti nello stesso istante che insieme superano i cinque file (il progetto chiede il
 * blocco della riga dell'elemento; un test con due richieste parallele qui non si scrive); i file HEIC degli
 * iPhone (il browser li converte in JPG quando il campo chiede immagini; un HEIC vero si rifiuta come ogni altro
 * formato).
 */

use App\Models\Documento;
use App\Models\Segnalazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Documenti\SpazioDocumenti;
use App\Support\LimiteCaricamento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/AllegatiScenario.php';

beforeEach(function () {
    algPrepara($this);
    $this->withoutVite();
    $this->mario = algUtente([$this->aurora->id], 'Mario Rossi');
    $this->segnalazione = algSegnalazione($this->aurora, $this->mario);
});

test('il condòmino carica una foto JPG, un PNG, un WebP e un PDF sulla segnalazione del suo palazzo: quattro righe, nella cartella della segnalazione, con il nome casuale', function () {
    algCarica($this, $this->mario, $this->segnalazione, [
        algFoto('cancello.jpg'), algFoto('serratura.png'), algFoto('ingresso.webp'), algPdf('preventivo fabbro.pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $righe = algRighe($this->segnalazione);
    expect($righe)->toHaveCount(4)
        ->and($righe->pluck('name')->all())->toBe(['cancello.jpg', 'serratura.png', 'ingresso.webp', 'preventivo fabbro.pdf'])
        ->and($righe->pluck('mime_type')->all())->toBe(['image/jpeg', 'image/png', 'image/webp', 'application/pdf']);

    $disco = app(ArchivioDocumenti::class)->disco();
    foreach ($righe as $riga) {
        expect($riga->path)->toStartWith("documenti/allegati/segnalazioni/{$this->segnalazione->id}/")
            // Il nome sul disco è casuale: niente nomi di persone nel bucket, nessun indirizzo da indovinare.
            ->and(basename($riga->path))->not->toContain(pathinfo($riga->name, PATHINFO_FILENAME))
            ->and(pathinfo($riga->path, PATHINFO_EXTENSION))->toBe(pathinfo($riga->name, PATHINFO_EXTENSION))
            ->and((int) $riga->created_by)->toBe($this->mario->id)
            ->and((int) $riga->file_size)->toBe(strlen($disco->get($riga->path)))
            ->and((bool) $riga->is_published)->toBeTrue();
        $disco->assertExists($riga->path);
    }
});

test('formati e contenuti che non sono una foto o un PDF si rifiutano, e non resta niente né a database né sul disco', function (string $nome, string $contenuto) {
    $risposta = algCarica($this, $this->mario, $this->segnalazione, [algFile($nome, $contenuto)]);

    expect(algErroriAllegati($risposta))->not->toBeEmpty("«{$nome}» doveva essere rifiutato")
        ->and(algRighe($this->segnalazione))->toHaveCount(0)
        ->and(app(ArchivioDocumenti::class)->disco()->allFiles('documenti/allegati'))->toBe([]);
})->with([
    'un SVG con uno script' => ['disegno.svg', ALG_SVG],
    'una GIF vera' => ['animata.gif', algBytesImmagine('gif')],
    'un HTML chiamato .jpg' => ['foto.jpg', ALG_HTML],
    'un PHP chiamato .pdf' => ['preventivo.pdf', "<?php\nsystem(\$_GET['c']);\n"],
    'un PNG chiamato .webp' => ['foto.webp', algBytesImmagine('png')],
    'un PDF chiamato .png' => ['foto.png', ALG_PDF],
    'un file senza estensione' => ['foto', algBytesImmagine('jpg')],
    'un HTML chiamato .html' => ['pagina.html', ALG_HTML],
]);

test('al massimo cinque allegati per elemento, contando quelli già caricati di ogni stato; un caricamento che li supera si rifiuta intero', function () {
    algAllegatoSalvato($this->segnalazione, $this->mario, 'uno.jpg');
    algAllegatoSalvato($this->segnalazione, $this->mario, 'due.jpg', approvato: false);
    algAllegatoSalvato($this->segnalazione, $this->admin, 'tre.pdf');

    $troppi = algCarica($this, $this->mario, $this->segnalazione, [algFoto('a.jpg'), algFoto('b.jpg'), algFoto('c.jpg')]);
    expect(algErroriAllegati($troppi))->toContain('allegati')
        ->and(algRighe($this->segnalazione))->toHaveCount(3);

    algCarica($this, $this->mario, $this->segnalazione, [algFoto('a.jpg'), algFoto('b.jpg')])->assertSessionHasNoErrors();
    expect(algRighe($this->segnalazione))->toHaveCount(5);

    $sesto = algCarica($this, $this->mario, $this->segnalazione, [algFoto('f.jpg')]);
    expect(algErroriAllegati($sesto))->toContain('allegati')
        ->and(algRighe($this->segnalazione))->toHaveCount(5);
});

test('sei file in un caricamento solo, su un elemento vuoto, si rifiutano tutti', function () {
    $risposta = algCarica($this, $this->mario, $this->segnalazione, array_map(fn ($i) => algFoto("f{$i}.jpg"), range(1, 6)));

    expect(algErroriAllegati($risposta))->not->toBeEmpty()
        ->and(algRighe($this->segnalazione))->toHaveCount(0);
});

test('un file più grande del limite dei documenti si rifiuta', function () {
    $byte = LimiteCaricamento::regolaMax() * 1024 + 4096;
    $grande = algFile('verbale.pdf', ALG_PDF . str_repeat("% riempimento\n", (int) ceil($byte / 14)));

    $risposta = algCarica($this, $this->mario, $this->segnalazione, [$grande]);

    expect(algErroriAllegati($risposta))->not->toBeEmpty()
        ->and(algRighe($this->segnalazione))->toHaveCount(0);
});

test('dove c\'è un limite di spazio ed è finito, gli allegati nuovi si rifiutano con una frase sullo spazio; senza limite si caricano', function () {
    // Un documento dell'archivio che occupa già tutto lo spazio concesso (1 MB).
    DB::table('documenti')->insert([
        'name' => 'Regolamento', 'path' => 'documenti/regolamento.pdf', 'mime_type' => 'application/pdf',
        'file_size' => 1024 * 1024, 'created_by' => $this->admin->id, 'is_published' => true, 'is_approved' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['kondomanager.limite_spazio_mb' => 1]);

    foreach ([$this->mario, $this->admin] as $chi) {
        $porta = $chi->is($this->admin) ? 'admin' : 'user';
        $risposta = algCarica($this, $chi, $this->segnalazione, [algFoto('cancello.jpg')], $porta);
        expect(algErroriAllegati($risposta))->toContain('allegati')
            ->and(session('errors')->getBag('default')->first('allegati'))->toContain('spazio');
    }
    expect(algRighe($this->segnalazione))->toHaveCount(0);

    config(['kondomanager.limite_spazio_mb' => 0]);
    algCarica($this, $this->mario, $this->segnalazione, [algFoto('cancello.jpg')])->assertSessionHasNoErrors();
    expect(algRighe($this->segnalazione))->toHaveCount(1);
});

test('un allegato conta nello spazio usato, come un documento', function () {
    $prima = app(SpazioDocumenti::class)->usatoByte();

    algCarica($this, $this->mario, $this->segnalazione, [algPdf()])->assertSessionHasNoErrors();

    expect(app(SpazioDocumenti::class)->usatoByte())->toBe($prima + strlen(ALG_PDF));
});

test('su un disco S3 il file va nel bucket e il percorso salvato è lo stesso del disco locale, relativo e senza prefisso', function () {
    config(['kondomanager.disco_documenti' => 'documenti_s3']);
    Storage::fake('documenti_s3');
    Storage::fake('local');

    algCarica($this, $this->mario, $this->segnalazione, [algFoto('cancello.jpg')])->assertSessionHasNoErrors();

    $riga = algRighe($this->segnalazione)->sole();
    expect($riga->path)->toStartWith("documenti/allegati/segnalazioni/{$this->segnalazione->id}/");
    Storage::disk('documenti_s3')->assertExists($riga->path);
    Storage::disk('local')->assertMissing($riga->path);
});

test('lo stato alla nascita: in moderazione dove l\'elemento la chiede, salvo per chi approva; pubblicato per chi ha «Pubblica» dove non la chiede; in attesa per chi non ha «Pubblica»', function () {
    $collaboratore = algUtente([$this->aurora->id], 'Carla Neri', 'collaboratore');
    $senzaPubblica = algUtente([$this->aurora->id], 'Paolo Galli', algRuolo('Carica senza pubblicare', [
        'Visualizza segnalazioni', ALG_PERMESSI['segnalazioni']['carica'], ALG_PERMESSI['segnalazioni']['visualizza'],
    ]));

    $moderata = algSegnalazione($this->aurora, $this->mario, ['allegati_moderati' => true]);
    $libera = algSegnalazione($this->aurora, $this->mario, ['allegati_moderati' => false]);

    $casi = [
        [$this->mario, 'user', $moderata, false],
        [$this->mario, 'user', $libera, true],
        [$this->admin, 'admin', $moderata, true],
        [$collaboratore, 'admin', $moderata, false],
        [$collaboratore, 'admin', $libera, true],
        [$senzaPubblica, 'user', $libera, false],
    ];
    foreach ($casi as $i => [$chi, $porta, $elemento, $pubblicato]) {
        algCarica($this, $chi, $elemento, [algFoto("caso{$i}.jpg")], $porta)->assertSessionHasNoErrors();
        $riga = algRighe($elemento)->firstWhere('name', "caso{$i}.jpg");
        expect($riga)->not->toBeNull("{$chi->name}: il caricamento non ha scritto la riga")
            ->and((bool) $riga->is_approved)->toBe($pubblicato, "{$chi->name}, elemento " . ($elemento->allegati_moderati ? 'in moderazione' : 'libero'));
    }
});

test('una segnalazione nuova prende la moderazione degli allegati dall\'impostazione generale (di serie sì) e nasce con gli allegati aperti', function () {
    $impostazioni = app(\App\Settings\GeneralSettings::class);
    expect($impostazioni->allegati_moderati_di_serie)->toBeTrue();

    $nuova = fn (string $oggetto) => $this->actingAs($this->mario)->post(route('user.segnalazioni.store'), [
        'subject' => $oggetto, 'description' => 'Si è rotto.', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'is_private' => false,
    ]);

    $nuova('La luce delle scale');
    $prima = Segnalazione::where('subject', 'La luce delle scale')->sole();
    expect((bool) $prima->allegati_moderati)->toBeTrue()
        ->and($prima->allegati_accesso)->toBe('aperti');

    $impostazioni->allegati_moderati_di_serie = false;
    $impostazioni->save();

    $nuova('Il citofono');
    expect((bool) Segnalazione::where('subject', 'Il citofono')->sole()->allegati_moderati)->toBeFalse();
});

test('il modulo «Nuova segnalazione» del portale accetta gli allegati: la segnalazione nasce con le sue foto', function () {
    $this->actingAs($this->mario)->post(route('user.segnalazioni.store'), [
        'subject' => 'Infiltrazione in cantina', 'description' => 'Macchia sul soffitto.', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'is_private' => false,
        'allegati' => [algFoto('macchia.jpg'), algFoto('soffitto.png')],
    ])->assertSessionHasNoErrors();

    $s = Segnalazione::where('subject', 'Infiltrazione in cantina')->sole();
    expect(algRighe($s)->pluck('name')->all())->toBe(['macchia.jpg', 'soffitto.png'])
        ->and(algRighe($s)->every(fn ($r) => str_starts_with($r->path, "documenti/allegati/segnalazioni/{$s->id}/")))->toBeTrue();
});

test('il modulo «Nuova segnalazione» con un file sbagliato non crea né la segnalazione né il file', function () {
    $risposta = $this->actingAs($this->mario)->post(route('user.segnalazioni.store'), [
        'subject' => 'Infiltrazione in cantina', 'description' => 'Macchia sul soffitto.', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'is_private' => false,
        'allegati' => [algFoto('macchia.jpg'), algFile('disegno.svg', ALG_SVG)],
    ]);

    expect(algErroriAllegati($risposta))->not->toBeEmpty()
        ->and(Segnalazione::where('subject', 'Infiltrazione in cantina')->exists())->toBeFalse()
        ->and(app(ArchivioDocumenti::class)->disco()->allFiles('documenti/allegati'))->toBe([]);
});

test('il modulo «Nuova segnalazione» del portale con i file, per chi non può caricare, non crea niente', function () {
    $senzaCarica = algUtente([$this->aurora->id], 'Paolo Galli', algRuolo('Segnala senza allegati', [
        'Crea segnalazioni', 'Visualizza segnalazioni',
    ]));

    $this->actingAs($senzaCarica)->post(route('user.segnalazioni.store'), [
        'subject' => 'Il portone', 'description' => 'Cigola.', 'priority' => 'media', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'is_private' => false, 'allegati' => [algFoto('portone.jpg')],
    ]);

    expect(Segnalazione::where('subject', 'Il portone')->exists())->toBeFalse()
        ->and(Documento::query()->whereNotNull('documentable_type')->count())->toBe(0)
        ->and(app(ArchivioDocumenti::class)->disco()->allFiles('documenti/allegati'))->toBe([]);
});

test('il modulo «Nuova segnalazione» del pannello e «Nuova comunicazione» del pannello e del portale accettano gli allegati', function () {
    $this->actingAs($this->admin)->post(route('admin.segnalazioni.store'), [
        'subject' => 'Ascensore fermo', 'description' => 'Fermo al terzo piano.', 'priority' => 'alta', 'stato' => 'aperta',
        'can_comment' => true, 'is_featured' => false, 'is_published' => true, 'condominio_id' => $this->aurora->id, 'is_private' => false,
        'allegati' => [algPdf('rapporto tecnico.pdf')],
    ])->assertSessionHasNoErrors();
    expect(algRighe(Segnalazione::where('subject', 'Ascensore fermo')->sole())->pluck('name')->all())->toBe(['rapporto tecnico.pdf']);

    $this->actingAs($this->admin)->post(route('admin.comunicazioni.store'), [
        'subject' => 'Assemblea ordinaria', 'description' => 'Convocazione.', 'priority' => 'media',
        'can_comment' => false, 'is_featured' => false, 'is_published' => true, 'condomini_ids' => [$this->aurora->id],
        'allegati' => [algPdf('convocazione.pdf'), algPdf('bilancio.pdf')],
    ])->assertSessionHasNoErrors();
    $comunicazione = \App\Models\Comunicazione::where('subject', 'Assemblea ordinaria')->sole();
    expect(algRighe($comunicazione)->pluck('name')->all())->toBe(['convocazione.pdf', 'bilancio.pdf'])
        ->and(algRighe($comunicazione)->every(fn ($r) => str_starts_with($r->path, "documenti/allegati/comunicazioni/{$comunicazione->id}/")))->toBeTrue();

    $autore = algUtente([$this->aurora->id], 'Lucia Bassi', algRuolo('Scrive comunicazioni', [
        'Crea comunicazioni', 'Visualizza comunicazioni', 'Pubblica comunicazioni',
        ALG_PERMESSI['comunicazioni']['carica'], ALG_PERMESSI['comunicazioni']['visualizza'],
    ]));
    $this->actingAs($autore)->post(route('user.comunicazioni.store'), [
        'subject' => 'Festa del cortile', 'description' => 'Sabato alle 18.', 'priority' => 'bassa',
        'is_featured' => false, 'is_published' => true, 'is_approved' => true, 'is_private' => false, 'created_by' => $autore->id,
        'condomini_ids' => [$this->aurora->id], 'allegati' => [algFoto('volantino.png')],
    ])->assertSessionHasNoErrors();
    expect(algRighe(\App\Models\Comunicazione::where('subject', 'Festa del cortile')->sole())->pluck('name')->all())->toBe(['volantino.png']);
});
