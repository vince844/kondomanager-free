<?php

/**
 * Lo studio nelle lettere, nelle ricevute e negli avvisi di compensazione, e nel rapporto dell'importazione, dalla rotta di ognuno.
 *
 * Nelle lettere, nelle ricevute e negli avvisi la colonna di destra è del destinatario, con le righe allineate al margine destro (la colonna comincia
 * a 112 mm dal bordo del foglio, e quella di sinistra va a capo 8 mm prima): lo
 * studio con il logo sta in alto a sinistra e sotto di lui «Amministratore del Condominio …» con indirizzo e codice fiscale, come nelle lettere;
 * nella ricevuta il conto in cui è entrato il versamento è «del condominio». Senza lo studio resta la testata di oggi: il condominio a sinistra, con
 * il suo nome come titolo. Il rapporto dell'importazione ha la testata dello studio come le altre stampe.
 */

use App\Models\Condominio;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\PDF\Carta;
use App\Services\PDF\GruppoCarta;
use App\Services\PDF\ProvaDellaCarta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__.'/../Gestionale/LettereScenario.php';
require_once __DIR__.'/../Gestionale/RicevutaIncassoScenario.php';
require_once __DIR__.'/Support/CartaDelloStudio.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** L'anteprima delle lettere di Ugo e di Bice (un PDF con due lettere), con il condominio che ha indirizzo e codice fiscale noti. */
function mdsLettere($test, string $nome = 'Le Rose'): array
{
    $s = lttScenario($test);
    $s['c']->update(['nome' => $nome, 'indirizzo' => 'Via delle Rose 4', 'cap' => '20100', 'comune' => 'Milano', 'codice_fiscale' => '90012345678']);
    $spia = cdsSpia();
    $r = $test->actingAs($test->user)->post(lttRotta($s, 'pdf-anteprima'), lttScelte($s));
    $r->assertOk();

    return [(string) $r->getContent(), $spia->stampe[0] ?? null, $s];
}

/** Il margine destro delle stampe senza la carta intestata, in mm dal bordo sinistro del foglio: 210 meno i 12 mm del margine (`PdfService`). */
const MDS_MARGINE_DESTRO = 198.0;

/** Dove finisce, in mm dal bordo sinistro del foglio, la riga che comincia con il testo, nella prima pagina del PDF che la ha. */
function mdsFine(string $pdf, string $testo): float
{
    for ($p = 1; $p <= cdsNumeroPagine($pdf); $p++) {
        if (cdsDove($pdf, $p, $testo) !== null) {
            return cdsRiga($pdf, $p, $testo)[2];
        }
    }
    \PHPUnit\Framework\Assert::fail("Il testo «{$testo}» non è nel PDF.");
}

/**
 * Dove finisce la colonna di sinistra della testata, nella pagina che ha il destinatario: la fine più a destra delle righe che cominciano prima
 * della colonna del destinatario, dalla sua prima riga (quella che comincia con `$destinatario`) al titolo, la prima riga a 14 punti.
 */
function mdsSinistraFinisce(string $pdf, string $destinatario): float
{
    for ($p = 1; $p <= cdsNumeroPagine($pdf) && cdsDove($pdf, $p, $destinatario) === null; $p++);
    $righe = cdsRighe($pdf, $p);
    $da = cdsRiga($pdf, $p, $destinatario)[3] - 6.0;
    $titolo = min(array_column(array_filter($righe, fn ($r) => $r[4] >= 14.0 && $r[3] > $da), 3));
    $sinistra = array_filter($righe, fn ($r) => $r[1] < Carta::DESTINATARIO && $r[3] > $da && $r[3] < $titolo - 1.0);
    \PHPUnit\Framework\Assert::assertNotEmpty($sinistra, 'nessuna riga nella colonna di sinistra');

    return max(array_map(fn ($r) => $r[2] ?? INF, $sinistra));
}

/**
 * Dove finisce, al più, la colonna di sinistra: 8 mm prima della colonna del destinatario (con un centesimo di millimetro per gli arrotondamenti).
 * Gli 8 mm sono scritti qui, non letti da `Carta`: uno spazio più stretto nel codice fa cadere il test.
 */
function mdsSpazio(): float
{
    return Carta::DESTINATARIO - 8.0 + 0.01;
}

it('la lettera con lo studio: in alto a sinistra il logo, lo studio con i suoi dati e sotto «Amministratore del Condominio …» con il C.F.; il destinatario allineato al margine destro; ogni lettera del PDF ha il suo blocco', function () {
    cdsStudioPieno(true);
    [$pdf, $stampa] = mdsLettere($this);

    expect($stampa['dati']['gruppo_carta'] ?? null)->toBe(GruppoCarta::LETTERE)
        ->and(cdsNumeroPagine($pdf))->toBe(2);
    foreach ([1, 2] as $p) {
        expect(cdsTesto($pdf, $p))->toContain('Studio Bianchi Amministrazioni')->toContain('P.IVA 01234567890 · C.F. BNCLRA80A01F205X')
            ->toContain('Amministratore del Condominio Le Rose')->toContain('Via delle Rose 4')->toContain('C.F. 90012345678')
            ->and(cdsHaImmagine($pdf, $p))->toBeTrue("pagina {$p}: il logo");
    }
    // Il destinatario, una lettera per pagina: il nome finisce al margine destro.
    expect(mdsFine($pdf, 'Ugo'))->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3)->and(mdsFine($pdf, 'Bice'))->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3);
});

it('la lettera senza lo studio: il condominio con il C.F. a sinistra, il destinatario allineato al margine destro, niente logo', function () {
    cdsSenzaStudio(true);
    [$pdf] = mdsLettere($this);

    $testo = cdsTesto($pdf, 1);
    expect($testo)->toContain('C.F. 90012345678')->not->toContain('Amministratore del')->not->toContain('P.IVA')
        // In cima solo l'etichetta dell'anteprima, poi il condominio.
        ->and(cdsInCima($pdf, 1, 'Le Rose', 14.0))->toBeTrue()
        ->and(cdsHaImmagine($pdf, 1))->toBeFalse()
        ->and(mdsFine($pdf, 'Ugo'))->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3);
});

it('la ricevuta, «Entrato in»: quando il nome del conto è il suo tipo o comincia con il suo tipo («Conto corrente», «Conto corrente BPER», con le maiuscole o senza), la parola non si ripete; con un altro nome il tipo resta fra parentesi', function (string $nome, string $conStudio, string $senzaStudio) {
    $s = scenarioRicevutaIncasso();
    $s->banca->update(['nome' => $nome]);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $u = amministratoreRicevute();
    $stampa = fn () => cdsTesto((string) $this->actingAs($u)->post(route('admin.gestionale.ricevute.pdf-anteprima', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id]])
        ->assertOk()->getContent(), 1);

    cdsStudioPieno(false);
    $con = $stampa();
    cdsSenzaStudio();
    $senza = $stampa();

    expect($con)->toContain('Entrato in '.$conStudio)
        ->and($senza)->toContain('Entrato in '.$senzaStudio);
    if ($nome !== 'Conto BPER') {
        expect($con)->not->toContain('(conto corrente del condominio)')->and($senza)->not->toContain('(Conto corrente)');
    }
})->with([
    'il conto si chiama «Conto corrente»' => ['Conto corrente', 'Conto corrente del condominio', 'Conto corrente'],
    'il conto si chiama «CONTO CORRENTE»' => ['CONTO CORRENTE', 'CONTO CORRENTE del condominio', 'CONTO CORRENTE'],
    'il conto si chiama «Conto BPER»' => ['Conto BPER', 'Conto BPER (conto corrente del condominio)', 'Conto BPER (Conto corrente)'],
    'il nome del conto comincia con il suo tipo, «Conto corrente BPER»' => ['Conto corrente BPER', 'Conto corrente BPER del condominio', 'Conto corrente BPER'],
    'il nome del conto comincia con il suo tipo, senza le maiuscole' => ['conto corrente Intesa', 'conto corrente Intesa del condominio', 'conto corrente Intesa'],
]);

it('la ricevuta con lo studio: lo studio con il logo in alto a sinistra e sotto «Amministratore del Condominio …» con l\'indirizzo e il C.F., come nelle lettere; il versamento è entrato nel conto corrente del condominio; senza lo studio, il condominio come oggi', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $u = amministratoreRicevute();

    cdsStudioPieno(true);
    $spia = cdsSpia();
    $con = (string) $this->actingAs($u)->post(route('admin.gestionale.ricevute.pdf-anteprima', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id]])->assertOk()->getContent();
    expect($spia->stampe[0]['dati']['gruppo_carta'] ?? null)->toBe(GruppoCarta::RICEVUTE_E_AVVISI)
        ->and(cdsTesto($con, 1))->toContain('Studio Bianchi Amministrazioni')->toContain('P.IVA 01234567890')->toContain('Amministratore del Condominio Via dei Pini')
        ->toContain('C.F. 90012345678')->toContain('Conto BPER (conto corrente del condominio)')->not->toContain('(Conto corrente)')
        ->and(cdsHaImmagine($con, 1))->toBeTrue();
    $studio = cdsDove($con, 1, 'Studio Bianchi');
    $condominio = cdsDove($con, 1, 'Amministratore del');
    expect($studio[1])->toBeLessThan($condominio[1]);

    cdsSenzaStudio(true);
    $senza = (string) $this->actingAs($u)->post(route('admin.gestionale.ricevute.pdf-anteprima', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id]])->assertOk()->getContent();
    expect(cdsTesto($senza, 1))->not->toContain('Studio Bianchi')->not->toContain('P.IVA')->not->toContain('Amministratore del')
        ->toContain('Conto BPER (Conto corrente)')->not->toContain('del condominio)')
        ->and(cdsHaImmagine($senza, 1))->toBeFalse();
    // Sopra il condominio c'è solo l'etichetta dell'anteprima.
    expect(cdsInCima($senza, 1, 'Condominio Via dei Pini', 14.0))->toBeTrue();
});

it('l\'avviso di compensazione con lo studio: lo studio e sotto «Amministratore del Condominio …» a sinistra, con l\'indirizzo e il C.F.; «Gentile» e il destinatario allineati al margine destro; senza lo studio, il condominio come oggi', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $this->travelTo('2026-03-02 10:00:00');
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], '2026-03-01', 0.00);

    cdsStudioPieno(true);
    $spia = cdsSpia();
    $pdf = (string) $this->actingAs($u)->post(route('admin.gestionale.ricevute.avvisi-compensazione.stampa', ['condominio' => $s->condominio->id]),
        ['avvisi' => [$incasso->id.':'.$s->anna->id], 'bollo' => 'non_dovuta'])->assertOk()->getContent();

    expect($spia->stampe[0]['dati']['gruppo_carta'] ?? null)->toBe(GruppoCarta::RICEVUTE_E_AVVISI)
        ->and(cdsTesto($pdf, 1))->toContain('Studio Bianchi Amministrazioni')->toContain('P.IVA 01234567890')->toContain('Amministratore del Condominio Via dei Pini')
        ->toContain('C.F. 90012345678')
        ->and(cdsHaImmagine($pdf, 1))->toBeTrue()
        ->and(cdsRiga($pdf, 1, 'Gentile')[2])->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3);

    cdsSenzaStudio(true);
    $senza = (string) $this->actingAs($u)->post(route('admin.gestionale.ricevute.avvisi-compensazione.stampa', ['condominio' => $s->condominio->id]),
        ['avvisi' => [$incasso->id.':'.$s->anna->id], 'bollo' => 'non_dovuta'])->assertOk()->getContent();
    expect(cdsTesto($senza, 1))->not->toContain('Amministratore del')->not->toContain('Studio Bianchi')
        ->and(cdsInCima($senza, 1, 'Condominio Via dei Pini', 14.0))->toBeTrue();
});

/** Un'importazione arrivata in fondo, con un avviso, per il rapporto in PDF. */
function mdsLotto(): ImportBatch
{
    $condominio = Condominio::factory()->create(['nome' => 'Condominio Le Querce', 'codice_fiscale' => '90087654321']);

    return ImportBatch::create(['sorgente' => 'danea', 'stato' => ImportBatch::STATO_COMPLETATO, 'condominio_id' => $condominio->id, 'completato_at' => now(),
        'rapporto' => ['livelli' => [['livello' => 'tabelle', 'etichetta' => 'Tabelle millesimali', 'creati' => 1, 'uniti' => 0, 'saltati' => 0, 'riuscito' => true,
            'gia_a_posto' => false, 'prerequisiti_mancanti' => [], 'avvisi' => []]], 'generato_il' => now()->toIso8601String()]]);
}

function mdsUtenteImportazioni(): User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore', 'Importa dati'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }
    $u = User::factory()->create();
    $u->givePermissionTo('Crea condomini');
    $u->givePermissionTo('Importa dati');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

it('il rapporto dell\'importazione ha la testata dello studio, come i registri; senza lo studio quella di oggi', function () {
    $lotto = mdsLotto();
    $u = mdsUtenteImportazioni();

    cdsStudioPieno(true);
    $spia = cdsSpia();
    $con = (string) $this->actingAs($u)->get(route('import.rapporto', $lotto->uuid))->assertOk()->getContent();
    expect($spia->stampe[0]['dati']['gruppo_carta'] ?? null)->toBe(GruppoCarta::REGISTRI_E_ELENCHI)
        ->and(cdsTesto($con, 1))->toContain('Studio Bianchi Amministrazioni')->toContain('P.IVA 01234567890')->toContain('Condominio Le Querce')->toContain('C.F. 90087654321')
        ->and(cdsHaImmagine($con, 1))->toBeTrue();

    cdsSenzaStudio(true);
    $senza = (string) $this->actingAs($u)->get(route('import.rapporto', $lotto->uuid))->assertOk()->getContent();
    expect(cdsTesto($senza, 1))->not->toContain('P.IVA')
        ->and(cdsInCima($senza, 1, 'Condominio Le Querce'))->toBeTrue()
        ->and(cdsHaImmagine($senza, 1))->toBeFalse();
});

/** Lo studio delle prove con una PEC di 78 caratteri e un sito di 80, senza spazi. */
const MDS_RECAPITI_LUNGHI = ['studio_pec' => 'amministrazione.studiobianchirossiverdiassociati@pec.ordineavvocatimilano.it',
    'studio_sito' => 'https://www.studioassociatobianchirossiverdiamministrazionicondominiali.it/home'];

/** La ricevuta di un incasso (una sola: con l'indirizzo per la posta) e l'avviso di una compensazione, dalle loro rotte; il condominio con un altro nome, se è dato. */
function mdsRicevutaEAvviso($test, ?string $nome = null): array
{
    $s = scenarioRicevutaIncasso();
    if ($nome !== null) {
        $s->condominio->update(['nome' => $nome]);
    }
    $u = amministratoreRicevute();
    $test->travelTo('2026-03-02 10:00:00');
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    $compensazione = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], '2026-03-01', 0.00);
    $ricevuta = (string) $test->actingAs($u)->post(route('admin.gestionale.ricevute.pdf-anteprima', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id]])->assertOk()->getContent();
    $avviso = (string) $test->actingAs($u)->post(route('admin.gestionale.ricevute.avvisi-compensazione.stampa', ['condominio' => $s->condominio->id]),
        ['avvisi' => [$compensazione->id.':'.$s->anna->id], 'bollo' => 'non_dovuta'])->assertOk()->getContent();

    return [$ricevuta, $avviso];
}

it('con una PEC di 78 caratteri e un sito di 80, nelle lettere lo studio va a capo 8 mm prima della colonna del destinatario, e il destinatario finisce al margine destro', function () {
    cdsStudioPieno(true, MDS_RECAPITI_LUNGHI);
    [$lettere] = mdsLettere($this);

    expect(mdsFine($lettere, 'Ugo'))->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3)->and(mdsFine($lettere, 'Bice'))->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3)
        ->and(mdsSinistraFinisce($lettere, 'Ugo'))->toBeLessThanOrEqual(mdsSpazio())->and(mdsSinistraFinisce($lettere, 'Bice'))->toBeLessThanOrEqual(mdsSpazio());
});

it('con una PEC di 78 caratteri e un sito di 80, nella ricevuta e nell\'avviso lo studio va a capo 8 mm prima della colonna del destinatario, e il destinatario finisce al margine destro', function () {
    cdsStudioPieno(true, MDS_RECAPITI_LUNGHI);
    [$ricevuta, $avviso] = mdsRicevutaEAvviso($this);

    foreach (['la ricevuta' => $ricevuta, 'l\'avviso' => $avviso] as $quale => $pdf) {
        expect(cdsRiga($pdf, 1, 'Gentile')[2])->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3, $quale)
            ->and(mdsSinistraFinisce($pdf, 'Gentile'))->toBeLessThanOrEqual(mdsSpazio(), $quale);
    }
});

it('la ricevuta con lo studio: il destinatario allineato al margine destro', function () {
    cdsStudioPieno(true);
    [$ricevuta] = mdsRicevutaEAvviso($this);

    expect(cdsRiga($ricevuta, 1, 'Gentile')[2])->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3);
});

/** Il nome del condominio, nella prima pagina che ha il destinatario: la misura del suo carattere uguale a quella del nome del destinatario, in grassetto. */
function mdsNomeDelCondominio(string $pdf, string $destinatario, string $nome, string $quale): void
{
    $p = cdsDove($pdf, 1, $destinatario) !== null ? 1 : 2;
    [, , , , $punti, $grassetto] = cdsRiga($pdf, $p, 'Condominio di Prova');
    expect($punti)->toBe(11.0, $quale)->and($grassetto)->toBeTrue($quale)->and(cdsRiga($pdf, $p, $nome)[4])->toBe(11.0, $quale);
}

it('le lettere senza lo studio: il nome del destinatario finisce al margine destro; il condominio dal nome lungo, alla misura del nome del destinatario (11 punti, in grassetto), va a capo almeno 8 mm prima della sua colonna', function () {
    cdsSenzaStudio(true);
    [$lettere] = mdsLettere($this, ProvaDellaCarta::CONDOMINIO);

    expect(mdsFine($lettere, 'Ugo'))->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3)->and(mdsFine($lettere, 'Bice'))->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3)
        ->and(mdsSinistraFinisce($lettere, 'Ugo'))->toBeLessThanOrEqual(mdsSpazio())->and(mdsSinistraFinisce($lettere, 'Bice'))->toBeLessThanOrEqual(mdsSpazio());
    mdsNomeDelCondominio($lettere, 'Ugo', 'Ugo', 'la lettera di Ugo');
    mdsNomeDelCondominio($lettere, 'Bice', 'Bice', 'la lettera di Bice');
});

it('la ricevuta e l\'avviso senza lo studio: «Gentile», il nome e l\'indirizzo del destinatario finiscono al margine destro; il condominio dal nome lungo, alla misura del nome del destinatario, va a capo almeno 8 mm prima della sua colonna', function () {
    cdsSenzaStudio(true);
    [$ricevuta, $avviso] = mdsRicevutaEAvviso($this, ProvaDellaCarta::CONDOMINIO);

    foreach (['la ricevuta' => $ricevuta, 'l\'avviso' => $avviso] as $quale => $pdf) {
        expect(cdsRiga($pdf, 1, 'Gentile')[2])->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3, $quale)
            ->and(cdsRiga($pdf, 1, 'Anna')[2])->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3, $quale)
            ->and(cdsRiga($pdf, 1, 'Via dei Pini 12, 10100')[2])->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3, $quale)
            ->and(mdsSinistraFinisce($pdf, 'Gentile'))->toBeLessThanOrEqual(mdsSpazio(), $quale);
        mdsNomeDelCondominio($pdf, 'Gentile', 'Anna', $quale);
    }
});

it('nella lettera, fra i dati dello studio e «Amministratore del Condominio …» c\'è lo stesso spazio della ricevuta e dell\'avviso', function () {
    cdsStudioPieno(true);
    [$pdf] = mdsLettere($this);

    $amministratore = cdsDove($pdf, 1, 'Amministratore del')[1] ?? null;
    $nome = cdsDove($pdf, 1, 'Studio Bianchi')[1] ?? null;
    // L'ultima riga dello studio: la più bassa fra il nome e «Amministratore del», nella colonna di sinistra.
    $righe = array_filter(cdsPagine($pdf)[0], fn ($x) => $x[1] < 100 && $x[2] > $nome && $x[2] < $amministratore - 0.5);
    $ultima = max(array_column($righe, 2));
    // Da riga di base a riga di base: l'ultima riga dello studio a 8pt e quella sotto a 9pt sono a 4,1 mm; con i 6 px di spazio, a 5,7.
    expect($amministratore - $ultima)->toBeGreaterThanOrEqual(5.4);
});

it('il comune dello studio sta nel blocco della lettera accanto all\'indirizzo', function () {
    cdsStudioPieno(true, ['studio_indirizzo' => 'Via Garibaldi 10, 20121', 'studio_comune' => 'Monza']);
    [$pdf] = mdsLettere($this);

    expect(cdsTesto($pdf, 1))->toContain('Via Garibaldi 10, 20121, Monza');
});

it('un logo largo (4:1) nella lettera è largo al massimo 40 mm', function () {
    cdsStudioPieno(false);
    Storage::disk('public')->put('settings/logo/largo.png', cdsPng(800, 200));
    $impostazioni = app(\App\Settings\PrintSettings::class);
    $impostazioni->logo_stampe_path = 'settings/logo/largo.png';
    $impostazioni->save();
    [$pdf] = mdsLettere($this);

    [$larghezza, $altezza] = cdsImmagini($pdf, 1)[0] ?? [null, null];
    expect($larghezza)->toEqualWithDelta(40.0, 0.3)->and($altezza)->toEqualWithDelta(10.0, 0.3);
});

/** Lo studio delle prove con un'email di 37 caratteri, che sta in una riga, e la PEC di 78, che non ci sta. */
const MDS_EMAIL_37 = 'segreteria.amministrazioni@bianchi.it';

/** Il testo della prima pagina ha l'email intera, senza caratteri invisibili, e la PEC, che va a capo dove la colonna finisce; il destinatario al margine destro. */
function mdsTestoIntero(string $pdf, string $destinatario): void
{
    $testo = cdsTesto($pdf, 1);
    expect(mb_strlen(MDS_EMAIL_37))->toBe(37)
        ->and(str_contains($testo, "\u{200B}"))->toBeFalse('un carattere invisibile nel testo')
        ->and($testo)->toContain('Email '.MDS_EMAIL_37)
        // Lette di seguito, le due righe della PEC la danno intera.
        ->and(str_replace(' ', '', $testo))->toContain(MDS_RECAPITI_LUNGHI['studio_pec'])
        ->and(mdsFine($pdf, $destinatario))->toEqualWithDelta(MDS_MARGINE_DESTRO, 0.3)
        ->and(mdsSinistraFinisce($pdf, $destinatario))->toBeLessThanOrEqual(mdsSpazio());
}

it('con un\'email di 37 caratteri e una PEC di 78, il testo della lettera ha l\'email intera e uguale, senza caratteri invisibili; la PEC va a capo solo dove la colonna finisce; il destinatario finisce al margine destro', function () {
    cdsStudioPieno(true, ['studio_email' => MDS_EMAIL_37, 'studio_pec' => MDS_RECAPITI_LUNGHI['studio_pec']]);
    [$lettere] = mdsLettere($this);

    mdsTestoIntero($lettere, 'Ugo');
});

it('con un\'email di 37 caratteri e una PEC di 78, il testo della ricevuta e dell\'avviso ha l\'email intera e uguale, senza caratteri invisibili; il destinatario finisce al margine destro', function () {
    cdsStudioPieno(true, ['studio_email' => MDS_EMAIL_37, 'studio_pec' => MDS_RECAPITI_LUNGHI['studio_pec']]);
    [$ricevuta, $avviso] = mdsRicevutaEAvviso($this);

    mdsTestoIntero($ricevuta, 'Gentile');
    mdsTestoIntero($avviso, 'Gentile');
});
