<?php

/**
 * La carta dello studio e il gruppo della stampa.
 *
 * - `PdfService::generate()` pretende il gruppo della carta (`gruppo_carta`, un `GruppoCarta`): una stampa che non lo dice è un'eccezione, così una
 *   stampa nuova senza gruppo è un rosso nel suo test della rotta.
 * - I cinque gruppi di documenti e «nessuno» (il modello F24), con il nome per la pagina.
 * - `CartaDiStampa`: lo studio pulito (i vuoti sono nulli), «c'è lo studio» quando c'è il nome, il logo letto una volta per richiesta.
 * - La rete dello spazio (il margine alto che si allunga sulla testata) solo quando c'è lo studio: senza, la configurazione di oggi.
 */

use App\Services\Gestionale\Lettere\EmissioneLettere;
use App\Services\PDF\CartaDiStampa;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\PdfService;
use App\Settings\PrintSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/Support/CartaDelloStudio.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

it('generate() senza il gruppo della carta è un\'eccezione, e così un gruppo che non è un GruppoCarta', function () {
    expect(fn () => app(PdfService::class)->generate('pdf.base', []))
        ->toThrow(InvalidArgumentException::class, 'pdf.base')
        ->and(fn () => app(PdfService::class)->generate('pdf.base', ['gruppo_carta' => 'lettere']))
        ->toThrow(InvalidArgumentException::class);
    expect(app(PdfService::class)->generate('pdf.base', ['gruppo_carta' => GruppoCarta::REGISTRI_E_ELENCHI]))->toBeInstanceOf(\Mpdf\Mpdf::class);
});

it('i cinque gruppi di documenti e «nessuno», ognuno con il suo nome', function () {
    expect(array_map(fn (GruppoCarta $g) => [$g->value, $g->nome()], GruppoCarta::cases()))->toBe([
        ['lettere', 'Lettere ai condòmini'],
        ['ricevute_e_avvisi', 'Ricevute e avvisi di compensazione'],
        ['rendiconto_e_assemblea', 'Rendiconto e assemblea'],
        ['per_la_persona', 'Estratti conto e prospetti per la persona'],
        ['registri_e_elenchi', 'Registri ed elenchi dello studio'],
        ['nessuno', 'Nessuna carta (il modello F24)'],
    ]);
});

it('lo studio pulito: i campi vuoti o di soli spazi sono nulli; c\'è lo studio quando c\'è il nome; EmissioneLettere legge lo stesso studio', function () {
    cdsStudioPieno(false, ['studio_pec' => '   ', 'studio_indirizzo' => '  Via Garibaldi 10  ']);
    $studio = CartaDiStampa::studio();
    expect($studio)->toBe(['nome' => 'Studio Bianchi Amministrazioni', 'indirizzo' => 'Via Garibaldi 10', 'email' => 'studio@bianchi.it', 'pec' => null,
        'telefono' => '02 1234567', 'comune' => null, 'partita_iva' => '01234567890', 'codice_fiscale' => 'BNCLRA80A01F205X', 'sito' => 'www.studiobianchi.it'])
        ->and(EmissioneLettere::studio())->toBe($studio)
        ->and(app(CartaDiStampa::class)->perGruppo(GruppoCarta::LETTERE)->haStudio())->toBeTrue();

    $s = app(PrintSettings::class);
    $s->studio_nome = ' ';
    $s->save();
    expect(app(CartaDiStampa::class)->perGruppo(GruppoCarta::LETTERE)->haStudio())->toBeFalse()
        ->and(app(CartaDiStampa::class)->perGruppo(GruppoCarta::LETTERE)->logo())->toBeNull();
});

it('le righe dello studio sotto il nome: l\'indirizzo, «P.IVA … · C.F. …», «Tel. … · Email … · PEC … · sito»; un campo vuoto non lascia il separatore', function () {
    cdsStudioPieno(false);
    expect(app(CartaDiStampa::class)->perGruppo(GruppoCarta::RENDICONTO_E_ASSEMBLEA)->righe())->toBe([
        'Via Garibaldi 10, 20121 Milano',
        "P.IVA\u{00A0}01234567890\u{00A0}· C.F.\u{00A0}BNCLRA80A01F205X",
        "Tel.\u{00A0}02 1234567\u{00A0}· Email\u{00A0}studio@bianchi.it\u{00A0}· PEC\u{00A0}studio@pec.bianchi.it\u{00A0}· www.studiobianchi.it",
    ]);

    cdsStudioPieno(false, ['studio_indirizzo' => null, 'studio_partita_iva' => null, 'studio_telefono' => null, 'studio_pec' => null]);
    expect(app(CartaDiStampa::class)->perGruppo(GruppoCarta::RENDICONTO_E_ASSEMBLEA)->righe())->toBe([
        "C.F.\u{00A0}BNCLRA80A01F205X",
        "Email\u{00A0}studio@bianchi.it\u{00A0}· www.studiobianchi.it",
    ]);
});

it('il logo si legge una volta per richiesta: la seconda carta non torna al disco', function () {
    cdsStudioPieno(true);
    $prima = app(CartaDiStampa::class)->perGruppo(GruppoCarta::LETTERE)->logo();
    expect($prima)->not->toBeNull()->and(is_file($prima))->toBeTrue();

    // Sul disco il file non c'è più: la carta lo ha già letto in questa richiesta.
    Storage::disk('public')->delete('settings/logo/logo-delle-prove.png');
    expect(app(CartaDiStampa::class)->perGruppo(GruppoCarta::REGISTRI_E_ELENCHI)->logo())->toBe($prima);
});

it('un logo che il disco non ha: la carta si fa senza logo, lo studio resta', function () {
    cdsStudioPieno(false);
    $s = app(PrintSettings::class);
    $s->logo_stampe_path = 'settings/logo/non-ce.png';
    $s->save();
    $carta = app(CartaDiStampa::class)->perGruppo(GruppoCarta::LETTERE);
    expect($carta->haStudio())->toBeTrue()->and($carta->logo())->toBeNull();
});

it('la rete dello spazio con lo studio, e senza lo studio solo sulla testata comune: senza testata, la configurazione di oggi; il modello F24 non ha la carta neanche con lo studio', function () {
    $senza = app(PdfService::class)->generate('pdf.base', ['gruppo_carta' => GruppoCarta::REGISTRI_E_ELENCHI, 'intestazione_propria' => true], ['margin_top' => 32]);
    $comune = app(PdfService::class)->generate('pdf.base', ['gruppo_carta' => GruppoCarta::REGISTRI_E_ELENCHI], ['margin_top' => 32]);
    expect($senza->setAutoTopMargin)->toBeFalse()->and($senza->tMargin)->toEqual(32)
        ->and($comune->setAutoTopMargin)->toBe('stretch')->and($comune->autoMarginPadding)->toEqual(0)->and($comune->tMargin)->toEqual(32);

    cdsStudioPieno(true);
    $con = app(PdfService::class)->generate('pdf.base', ['gruppo_carta' => GruppoCarta::REGISTRI_E_ELENCHI], ['margin_top' => 32]);
    $f24 = app(PdfService::class)->generate('pdf.base', ['gruppo_carta' => GruppoCarta::NESSUNO], ['margin_top' => 32]);
    expect($con->setAutoTopMargin)->toBe('stretch')->and($con->autoMarginPadding)->toEqual(0)
        ->and($f24->setAutoTopMargin)->toBeFalse();
});

it('il comune dello studio accanto all\'indirizzo, se l\'indirizzo non lo dice già dopo l\'ultima virgola (senza badare alle maiuscole, agli spazi, agli accenti e agli apostrofi)', function (?string $indirizzo, ?string $comune, string $prima) {
    cdsStudioPieno(false, ['studio_indirizzo' => $indirizzo, 'studio_comune' => $comune]);
    expect(app(CartaDiStampa::class)->perGruppo(GruppoCarta::RENDICONTO_E_ASSEMBLEA)->righe()[0])->toBe($prima);
})->with([
    'l\'indirizzo senza il comune' => ['Via Garibaldi 10, 20121', 'Milano', 'Via Garibaldi 10, 20121, Milano'],
    'l\'indirizzo con il comune' => ['Via Garibaldi 10, 20121 Milano', 'Milano', 'Via Garibaldi 10, 20121 Milano'],
    'il comune in minuscolo, con gli spazi' => ['Via Garibaldi 10, 20121 MILANO', '  milano ', 'Via Garibaldi 10, 20121 MILANO'],
    'il comune di due parole, con uno spazio in più nell\'indirizzo' => ['Via Emilia 3, 20097 San  Donato Milanese', 'San Donato Milanese', 'Via Emilia 3, 20097 San  Donato Milanese'],
    'il comune all\'inizio di un\'altra parola, dopo la virgola' => ['Via Roma 1, 12042 Braida', 'Bra', 'Via Roma 1, 12042 Braida, Bra'],
    'il comune alla fine di un\'altra parola, dopo la virgola' => ['Via Roma 1, 38062 Marco', 'Arco', 'Via Roma 1, 38062 Marco, Arco'],
    'senza indirizzo' => [null, 'Milano', 'Milano'],
    'senza comune' => ['Via Garibaldi 10', null, 'Via Garibaldi 10'],
    'il comune con l\'accento, scritto senza' => ['Via Roma 1, 47121 Forlì', 'Forli', 'Via Roma 1, 47121 Forlì'],
    'il comune senza l\'accento, scritto con' => ['Via Roma 1, 47121 Forli', 'Forlì', 'Via Roma 1, 47121 Forli'],
    'l\'apostrofo tipografico nell\'indirizzo' => ['Corso Federico II 1, 67100 L’Aquila', 'L\'Aquila', 'Corso Federico II 1, 67100 L’Aquila'],
    'una via con il nome della città, e la città dopo la virgola che manca' => ['Via Milano 12, 20900', 'Milano', 'Via Milano 12, 20900, Milano'],
    'una via con il nome della città, e la città dopo la virgola' => ['Via Milano 12, 20900 Monza', 'Monza', 'Via Milano 12, 20900 Monza'],
]);

it('il «·» fra i dati dello studio resta attaccato alla parola di prima, e il nome di un dato al suo valore: quegli spazi non vanno a capo', function () {
    cdsStudioPieno(false);
    $righe = app(CartaDiStampa::class)->perGruppo(GruppoCarta::LETTERE)->righe();
    expect($righe[1])->toBe("P.IVA\u{00A0}01234567890\u{00A0}· C.F.\u{00A0}BNCLRA80A01F205X")
        ->and(substr_count($righe[2], "\u{00A0}· "))->toBe(3)
        ->and(str_contains($righe[2], ' · '))->toBeFalse()
        ->and($righe[2])->toStartWith("Tel.\u{00A0}02 1234567")->toContain("PEC\u{00A0}studio@pec");
});

it('un logo che il disco non legge: il registro dice che è un\'immagine delle stampe, con il percorso', function () {
    cdsStudioPieno(false);
    $s = app(PrintSettings::class);
    $s->logo_stampe_path = 'settings/logo/irraggiungibile.png';
    $s->save();
    $rotto = Mockery::mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
    $rotto->shouldReceive('exists')->andThrow(new RuntimeException('il disco non risponde'));
    Storage::set('rotto', $rotto);
    config(['kondomanager.disco_pubblici' => 'rotto']);
    \Illuminate\Support\Facades\Log::spy();

    expect(app(CartaDiStampa::class)->perGruppo(GruppoCarta::LETTERE)->logo())->toBeNull();
    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn (string $frase, array $contesto) => str_starts_with($frase, 'Immagine delle stampe non leggibile')
        && ! str_contains($frase, 'firma') && ($contesto['percorso'] ?? null) === 'settings/logo/irraggiungibile.png')->once();
});
