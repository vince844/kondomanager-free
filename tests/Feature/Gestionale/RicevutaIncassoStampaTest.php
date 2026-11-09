<?php

use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * La stampa della ricevuta dell'incasso, una o tante (decisione 200): la risposta è un PDF, e ogni ricevuta scrive la sua riga del registro con
 * le scelte di quella emissione (bollo, nota) e l'impronta del PDF. Un incasso di un altro condominio, o che non è un incasso di rate, non si
 * stampa (404, come la pagina dell'incasso). Il bollo lo sceglie l'amministratore: senza la scelta non si stampa.
 *
 * Che cosa NON copre: l'aspetto del PDF su carta (verifica a video), la busta.
 */
function stampaRicevute($test, object $s, array $campi)
{
    return $test->actingAs(amministratoreRicevute())
        ->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), $campi);
}

function pagineDelPdf(string $pdf): int
{
    return preg_match_all('/\/Type\s*\/Page[^s]/', $pdf);
}

test('la stampa di una ricevuta risponde con il PDF e scrive una riga del registro con il bollo, la nota e l\'impronta', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    $risposta = stampaRicevute($this, $s, ['incassi' => [$incasso->id], 'bollo' => 'assolta', 'nota' => 'Consegnata a mano']);

    $risposta->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $pdf = $risposta->getContent();
    expect(str_starts_with($pdf, '%PDF'))->toBeTrue()
        ->and($risposta->headers->get('Content-Disposition'))->toContain('ricevuta-')
        ->and($risposta->headers->get('Content-Disposition'))->toContain(strtolower($incasso->numero_protocollo));

    $riga = RegistroInvio::sole();
    expect($riga->tipo)->toBe('ricevuta')
        ->and($riga->canale)->toBe('stampa')
        ->and($riga->stato)->toBe('stampata')
        ->and($riga->condominio_id)->toBe($s->condominio->id)
        ->and($riga->anagrafica_id)->toBe($s->anna->id)
        ->and($riga->documentabile_type)->toBe(ScritturaContabile::class)
        ->and($riga->documentabile_id)->toBe($incasso->id)
        ->and($riga->numero)->toBe($incasso->numero_protocollo)
        ->and($riga->destinatario)->toBeNull()
        ->and($riga->dati)->toMatchArray(['bollo' => 'assolta', 'nota' => 'Consegnata a mano', 'soglia' => 'sopra'])
        ->and($riga->impronta)->toBe(hash('sha256', $pdf))
        ->and($riga->emesso_da)->not->toBeNull();
});

test('la stampa in blocco fa un PDF solo con una ricevuta per pagina, una riga per ricevuta, e il bollo scelto per chi sta sopra e sotto la soglia', function () {
    $s = scenarioRicevutaIncasso();
    $sopra = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $sotto = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-02', 60.00);

    $risposta = stampaRicevute($this, $s, ['incassi' => [$sopra->id, $sotto->id], 'bollo_sopra' => 'da_assolvere', 'bollo_sotto' => 'non_dovuta']);

    $risposta->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(pagineDelPdf($risposta->getContent()))->toBe(2);

    $righe = RegistroInvio::orderBy('id')->get();
    expect($righe)->toHaveCount(2)
        ->and($righe->firstWhere('documentabile_id', $sopra->id)->dati['bollo'])->toBe('da_assolvere')
        ->and($righe->firstWhere('documentabile_id', $sotto->id)->dati['bollo'])->toBe('non_dovuta')
        ->and($righe->pluck('impronta')->unique()->all())->toBe([hash('sha256', $risposta->getContent())]);
});

test('la stampa in blocco tiene insieme le ricevute della stessa persona, nell\'ordine delle persone, con l\'indirizzo sulla prima', function () {
    $s = scenarioRicevutaIncasso();
    $ugo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-01-05', 60.00);
    $anna2 = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50);
    $anna1 = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    $voci = app(EmissioneRicevute::class)->perBusta(collect([$ugo, $anna2, $anna1])->map(fn ($i) => app(RicevutaIncasso::class)->per($i->fresh())));

    expect(collect($voci)->map(fn ($v) => $v['contenuto']['incasso_id'])->all())->toBe([$anna1->id, $anna2->id, $ugo->id])
        ->and(collect($voci)->map(fn ($v) => $v['indirizzo_per_posta'])->all())->toBe([true, false, true]);
});

test('un incasso annullato si stampa, e la ricevuta lo dice in testa', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    app(StornoIncassoRateAction::class)->execute($incasso->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio, \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);

    stampaRicevute($this, $s, ['incassi' => [$incasso->id], 'bollo' => 'non_dovuta'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(RegistroInvio::count())->toBe(1);

    $html = View::make('pdf.gestionale.ricevute_incasso', [
        'condominio' => $s->condominio,
        'ricevute'   => [['contenuto' => app(RicevutaIncasso::class)->per($incasso->fresh()), 'bollo' => 'non_dovuta', 'nota' => null, 'emessa_il' => '05/11/2026', 'indirizzo_per_posta' => false]],
    ])->render();

    expect($html)->toContain('INCASSO ANNULLATO')
        ->and($html)->toContain('RET-')
        ->and($html)->toContain('RICEVUTA DI PAGAMENTO')
        ->and($html)->toContain('Non dovuta');
});

test('la ricevuta scrive le quote con il già versato prima e il residuo, chi ha versato per conto di chi, e la nota', function () {
    $s = scenarioRicevutaIncasso();
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 50.00]], '2026-01-10', 50.00);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 100.00], [$s->anna2, 80.50]], '2026-04-15', 180.50, ['versato_da_id' => $s->carlo->id]);

    $html = View::make('pdf.gestionale.ricevute_incasso', [
        'condominio' => $s->condominio,
        'ricevute'   => [['contenuto' => app(RicevutaIncasso::class)->per($incasso->fresh()), 'bollo' => 'assolta', 'nota' => 'Bonifico del 15 aprile', 'emessa_il' => '05/11/2026', 'indirizzo_per_posta' => true]],
    ])->render();

    expect($html)->toContain('€ 180,50')
        ->and($html)->toContain('€ 50,00')
        ->and($html)->toContain('€ 40,00')
        ->and($html)->toContain('Carlo Bruni')
        ->and($html)->toContain('Per conto di')
        ->and($html)->toContain('Bonifico del 15 aprile')
        ->and($html)->toContain('Via dei Pini 12, 10100 Torino')
        ->and($html)->toContain('Assolta')
        ->and($html)->not->toContain('a saldo');
});

test('l\'incasso di un altro condominio non si stampa: 404, da solo o dentro una lista, e il registro resta vuoto', function () {
    $s = scenarioRicevutaIncasso();
    $altro = scenarioRicevutaIncasso('Condominio Corso Italia');
    $mio = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $suo = incassaPerRicevuta($altro, $altro->anna, [[$altro->anna1, 150.00]], '2026-02-01', 150.00);

    stampaRicevute($this, $s, ['incassi' => [$suo->id], 'bollo' => 'assolta'])->assertNotFound();
    stampaRicevute($this, $s, ['incassi' => [$mio->id, $suo->id], 'bollo_sopra' => 'assolta', 'bollo_sotto' => 'assolta'])->assertNotFound();

    expect(RegistroInvio::count())->toBe(0);
});

test('una scrittura che non è un incasso di rate non si stampa: 404', function () {
    $s = scenarioRicevutaIncasso();
    $giroconto = ScritturaContabile::create([
        'condominio_id' => $s->condominio->id, 'esercizio_id' => $s->esercizio->id, 'gestione_id' => $s->gestione->id,
        'data_registrazione' => '2026-02-01', 'data_competenza' => '2026-02-01', 'causale' => 'Giroconto', 'tipo_movimento' => 'giroconto', 'stato' => 'registrata',
    ]);

    stampaRicevute($this, $s, ['incassi' => [$giroconto->id], 'bollo' => 'assolta'])->assertNotFound();
    expect(RegistroInvio::count())->toBe(0);
});

test('senza la scelta del bollo, o con uno stato che non esiste, la stampa si rifiuta e non scrive niente', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    stampaRicevute($this, $s, ['incassi' => [$incasso->id]])->assertStatus(422);
    stampaRicevute($this, $s, ['incassi' => [$incasso->id], 'bollo' => 'esente'])->assertStatus(422);
    // Il bollo di chi sta sotto la soglia non vale per una ricevuta sopra la soglia.
    stampaRicevute($this, $s, ['incassi' => [$incasso->id], 'bollo_sotto' => 'non_dovuta'])->assertStatus(422);

    expect(RegistroInvio::count())->toBe(0);
});

test('oltre il tetto di ricevute per PDF la stampa si rifiuta e chiede di restringere i filtri', function () {
    $s = scenarioRicevutaIncasso();
    $righe = [];
    for ($i = 1; $i <= EmissioneRicevute::TETTO_PDF + 1; $i++) {
        $righe[] = [
            'condominio_id' => $s->condominio->id, 'esercizio_id' => $s->esercizio->id, 'gestione_id' => $s->gestione->id,
            'data_registrazione' => '2026-02-01', 'data_competenza' => '2026-02-01', 'numero_protocollo' => sprintf('INC-2026-%05d', $i),
            'causale' => 'Incasso', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now(),
        ];
    }
    DB::table('scritture_contabili')->insert($righe);
    // Ognuno con il denaro entrato in banca: un incasso senza denaro non è una ricevuta di pagamento, e non conterebbe.
    DB::table('righe_scritture')->insert(DB::table('scritture_contabili')->where('condominio_id', $s->condominio->id)->pluck('id')->map(fn ($id) => [
        'scrittura_id' => $id, 'conto_contabile_id' => $s->banca->conto_contabile_id, 'cassa_id' => $s->banca->id, 'tipo_riga' => 'dare',
        'importo' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ])->all());

    $risposta = stampaRicevute($this, $s, ['tutte' => 1, 'filtri' => ['ricevuta' => 'tutte'], 'bollo_sopra' => 'assolta', 'bollo_sotto' => 'non_dovuta']);

    $risposta->assertStatus(422);
    expect($risposta->getContent())->toContain((string) EmissioneRicevute::TETTO_PDF)
        ->and(RegistroInvio::count())->toBe(0);
});

test('la prima emissione è l\'originale; ogni emissione dopo è una copia, anche nello stesso secondo, e la ricevuta lo dice in testa', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);

    stampaRicevute($this, $s, ['incassi' => [$incasso->id], 'bollo' => 'assolta'])->assertOk();
    stampaRicevute($this, $s, ['incassi' => [$incasso->id], 'bollo' => 'assolta'])->assertOk();

    [$originale, $copia] = RegistroInvio::orderBy('id')->get()->all();
    $oggi = now()->setTimezone(config('app.user_timezone'))->format('Y-m-d');
    expect($originale->dati)->not->toHaveKey('copia_del')
        ->and($copia->dati['copia_del'])->toBe($oggi);

    $voce = fn (?string $copiaDel) => ['contenuto' => app(RicevutaIncasso::class)->per($incasso->fresh()), 'bollo' => 'assolta', 'modo_bollo' => 'contrassegno', 'estremi_bollo' => '0123',
        'nota' => null, 'emessa_il' => '05/11/2026', 'copia_del' => $copiaDel, 'indirizzo_per_posta' => false];
    $html = fn (?string $copiaDel) => View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s->condominio, 'ricevute' => [$voce($copiaDel)]])->render();

    expect($html('01/11/2026'))->toContain('COPIA')
        ->and($html('01/11/2026'))->toContain('L\'originale è del')
        ->and($html(null))->not->toContain('COPIA')
        ->and($html(null))->toContain('<strong>Assolta</strong> — contrassegno n. 0123');
});

test('lo storno di un incasso con la ricevuta già uscita lo dice nella conferma; la pagina delle ricevute la mostra fra le annullate da comunicare finché non si ristampa', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $utente = amministratoreRicevute();
    $anteprima = fn () => $this->actingAs($utente)
        ->getJson(route('admin.gestionale.movimenti-rate.storno.anteprima', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))->json('ricevuta');

    expect($anteprima())->toBeNull();

    stampaRicevute($this, $s, ['incassi' => [$incasso->id], 'bollo' => 'assolta'])->assertOk();
    expect($anteprima())->toStartWith('La ricevuta di questo incasso è stata stampata il ');

    $this->travel(1)->minutes();
    app(StornoIncassoRateAction::class)->execute($incasso->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio, \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    $daComunicare = fn () => $this->actingAs($utente)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id]))
        ->viewData('page')['props']['annullate_da_comunicare'];
    expect(collect($daComunicare())->pluck('id')->all())->toBe([$incasso->id]);

    $this->travel(1)->minutes();
    stampaRicevute($this, $s, ['incassi' => [$incasso->id], 'bollo' => 'assolta'])->assertOk();
    expect($daComunicare())->toBe([]);
});

test('l\'ordine della stampa si sceglie (persona, unità, data del versamento), e le ricevute della stessa persona restano insieme', function () {
    $s = scenarioRicevutaIncasso();
    $ugo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-01-05', 60.00);
    $anna2 = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50);
    $anna1 = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $contenuti = collect([$ugo, $anna2, $anna1])->map(fn ($i) => app(RicevutaIncasso::class)->per($i->fresh()));
    $ordine = fn (string $o) => collect(app(EmissioneRicevute::class)->perBusta($contenuti, $o))->map(fn ($v) => $v['contenuto']['incasso_id'])->all();

    // Per data: Ugo ha versato per primo (05/01); le due di Anna restano insieme, in ordine di versamento.
    expect($ordine('data'))->toBe([$ugo->id, $anna1->id, $anna2->id])
        // Per unità: l'interno 4 di Anna prima del 7 di Ugo.
        ->and($ordine('unita'))->toBe([$anna1->id, $anna2->id, $ugo->id])
        ->and($ordine('persona'))->toBe([$anna1->id, $anna2->id, $ugo->id]);
});

test('la copia riproduce l\'originale: dopo lo storno di un incasso precedente la ristampa ha le cifre della prima emissione, e la finestra dice che la situazione è cambiata', function () {
    $s = scenarioRicevutaIncasso();
    $a = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 60.00]], '2026-02-01', 60.00);
    $b = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 40.00]], '2026-03-01', 40.00);
    $utente = amministratoreRicevute();
    $opzioni = fn () => $this->actingAs($utente)
        ->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $b->id]))->assertOk()->json();

    stampaRicevute($this, $s, ['incassi' => [$b->id], 'bollo' => 'non_dovuta'])->assertOk();
    $originale = RegistroInvio::sole();
    expect(cifreDellaQuota($originale->dati['contenuto']['quote'][0]))->toBe([15000, 6000, 4000, 5000])
        ->and($opzioni()['copia'])->toBe(['del' => now()->setTimezone(config('app.user_timezone'))->format('Y-m-d'), 'cambiata' => false]);

    app(StornoIncassoRateAction::class)->execute($a->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio, \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    // Oggi le cifre della quota sono altre: il primo versamento non c'è più.
    expect(cifreDellaQuota(app(RicevutaIncasso::class)->per($b->fresh())['quote'][0]))->toBe([15000, 0, 4000, 11000]);

    stampaRicevute($this, $s, ['incassi' => [$b->id], 'bollo' => 'non_dovuta'])->assertOk();
    $copia = RegistroInvio::orderByDesc('id')->first();
    expect($copia->dati['copia_del'])->not->toBeNull()
        ->and(cifreDellaQuota($copia->dati['contenuto']['quote'][0]))->toBe([15000, 6000, 4000, 5000])
        ->and($opzioni()['copia']['cambiata'])->toBeTrue()
        ->and($opzioni()['importo'])->toBe(4000);
});

test('la copia di un incasso annullato dopo l\'originale porta le cifre dell\'originale e, in testa, l\'annullamento', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    stampaRicevute($this, $s, ['incassi' => [$incasso->id], 'bollo' => 'assolta'])->assertOk();
    $originale = RegistroInvio::sole()->dati['contenuto'];

    app(StornoIncassoRateAction::class)->execute($incasso->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio, \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    $emessa = app(RicevutaIncasso::class)->daIstantanea($originale, $incasso->fresh());

    expect($emessa['annullato'])->toBeTrue()
        ->and($emessa['storno']['protocollo'])->toStartWith('RET-')
        ->and($emessa['quote'])->toBe($originale['quote']);
});

test('il numero del contrassegno vale per una ricevuta sola: non si ripropone, né lo stato «assolta» che lo accompagna, e in blocco su più ricevute si rifiuta; l\'autorizzazione del modo virtuale si ripropone e vale per tutte', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $uno = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $due = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50);
    $tre = incassaPerRicevuta($s, $s->anna, [[$s->anna3, 150.00]], '2026-04-01', 150.00);
    $stampa = fn (array $campi) => $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), $campi);
    $ricordata = fn () => app(EmissioneRicevute::class)->sceltaRicordata($s->condominio, $u);

    $stampa(['incassi' => [$uno->id], 'bollo' => 'assolta', 'modo_bollo' => 'contrassegno', 'estremi_bollo' => '01170512345678'])->assertOk();
    expect(RegistroInvio::sole()->dati)->toMatchArray(['bollo' => 'assolta', 'modo_bollo' => 'contrassegno', 'estremi_bollo' => '01170512345678'])
        // La marca del contrassegno è su un foglio solo: dopo un contrassegno non si propone né «assolta» né il modo (decisione 204).
        ->and($ricordata())->toMatchArray(['bollo_sopra' => null, 'modo_bollo' => null, 'estremi_bollo' => null]);

    $stampa(['incassi' => [$due->id, $tre->id], 'bollo_sopra' => 'assolta', 'modo_bollo' => 'contrassegno', 'estremi_bollo' => '01170512345678'])
        ->assertStatus(422)->assertSee('Il numero del contrassegno è diverso per ogni ricevuta', false);
    // Gli estremi senza dire come è stato assolto non si stampano.
    $stampa(['incassi' => [$due->id], 'bollo' => 'assolta', 'estremi_bollo' => 'n. 123'])->assertStatus(422);
    expect(RegistroInvio::count())->toBe(1);

    $stampa(['incassi' => [$due->id, $tre->id], 'bollo_sopra' => 'assolta', 'modo_bollo' => 'virtuale', 'estremi_bollo' => 'autorizzazione n. 12 del 10/01/2020'])->assertOk();
    expect(RegistroInvio::orderByDesc('id')->take(2)->get()->pluck('dati.estremi_bollo')->unique()->all())->toBe(['autorizzazione n. 12 del 10/01/2020'])
        ->and($ricordata())->toMatchArray(['modo_bollo' => 'virtuale', 'estremi_bollo' => 'autorizzazione n. 12 del 10/01/2020']);

    $html = fn (array $voce) => View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s->condominio, 'ricevute' => [$voce + [
        'contenuto' => app(RicevutaIncasso::class)->per($uno->fresh()), 'nota' => null, 'emessa_il' => '05/11/2026', 'indirizzo_per_posta' => false]]])->render();
    expect($html(['bollo' => 'assolta', 'modo_bollo' => 'contrassegno', 'estremi_bollo' => '01170512345678']))->toContain('<strong>Assolta</strong> — contrassegno n. 01170512345678')
        ->and($html(['bollo' => 'assolta', 'modo_bollo' => 'virtuale', 'estremi_bollo' => 'autorizzazione n. 12']))->toContain('<strong>Assolta</strong> in modo virtuale — autorizzazione n. 12');
});

/**
 * Il PDF di una vista della ricevuta con i caratteri di base, che si leggono: le stesse regole di impaginazione della stampa vera (margini,
 * interruzioni di pagina), con le posizioni in millimetri di `testiDellePagineDelPdf()`.
 */
function pdfLeggibileDellaRicevuta(string $html): array
{
    $mpdf = new \Mpdf\Mpdf(['mode' => 'c', 'format' => 'A4', 'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 14, 'margin_bottom' => 15,
        'margin_header' => 10, 'margin_footer' => 5, 'tempDir' => storage_path('framework/cache/mpdf')]);
    $mpdf->SetCompression(false);
    $mpdf->WriteHTML($html);

    return testiDellePagineDelPdf($mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN));
}

test('la testata è una lettera: il condominio in alto a sinistra, a chi è intestata la ricevuta in alto a destra (con le righe allineate al margine destro), il titolo subito sotto; la seconda ricevuta della stessa persona non ha il blocco', function () {
    $s = scenarioRicevutaIncasso();
    $anna = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $anna2 = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 50.00]], '2026-03-01', 50.00);
    $carlo = quotaDaPagareDiCarlo($s);
    $perCarlo = incassaPerRicevuta($s, $s->carlo, [[$carlo, 100.00]], '2026-02-02', 100.00);
    $voci = app(EmissioneRicevute::class)->perBusta(collect([$anna, $anna2, $perCarlo])->map(fn ($i) => app(RicevutaIncasso::class)->per($i->fresh())));
    expect(array_column($voci, 'indirizzo_per_posta'))->toBe([true, false, true]);
    $html = View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s->condominio,
        'ricevute' => array_map(fn ($v) => $v + ['bollo' => 'non_dovuta', 'nota' => null, 'emessa_il' => '05/11/2026'], $voci)])->render();
    $pagine = pdfLeggibileDellaRicevuta($html);

    expect($pagine)->toHaveCount(3);
    foreach ([[0, 'Anna Bruni', 'Via dei Pini 12, 10100 Torino'], [2, 'Carlo Bruni', 'Corso Francia 3, 10138 Torino']] as [$n, $nome, $indirizzo]) {
        [[$x, $y]] = doveStaNellaPagina($pagine[$n], $nome);
        [[$xCondominio, $yCondominio]] = doveStaNellaPagina($pagine[$n], 'Condominio Via dei Pini');
        [[$xTitolo, $yTitolo]] = doveStaNellaPagina($pagine[$n], 'RICEVUTA DI PAGAMENTO');
        // A destra, nella colonna del destinatario, con le righe allineate al margine destro: una riga più corta comincia più a destra (dove finiscono
        // lo misura `MittenteDelloStudioTest`, con i caratteri delle stampe); in alto, sulla stessa fascia del condominio, a sinistra.
        $xGentile = doveStaNellaPagina($pagine[$n], 'Gentile')[0][0];
        $xIndirizzo = doveStaNellaPagina($pagine[$n], $indirizzo)[0][0];
        expect($xIndirizzo)->toBeGreaterThan(\App\Services\PDF\Carta::DESTINATARIO)
            ->and($x)->toBeGreaterThan($xIndirizzo)
            ->and($xGentile)->toBeGreaterThan($x)
            ->and($y)->toBeGreaterThan(14.0)->toBeLessThan(30.0)
            ->and($xCondominio)->toBe(12.0)
            ->and(abs($yCondominio - $y))->toBeLessThan(5.0)
            // Il titolo è subito sotto la testata, non 50 mm più in basso.
            ->and($xTitolo)->toBe(12.0)
            ->and($yTitolo)->toBeGreaterThan($y)->toBeLessThan(50.0);
    }
    // La seconda ricevuta di Anna ha il condominio e il titolo nello stesso posto, e nessun blocco a destra.
    expect(doveStaNellaPagina($pagine[1], 'Condominio Via dei Pini')[0])->toBe(doveStaNellaPagina($pagine[0], 'Condominio Via dei Pini')[0])
        ->and(doveStaNellaPagina($pagine[1], 'Gentile'))->toBe([])
        ->and(doveStaNellaPagina($pagine[1], 'Anna Bruni')[0][0])->toBeLessThan(112.0)
        ->and(doveStaNellaPagina($pagine[1], 'RICEVUTA DI PAGAMENTO')[0][1])->toBeLessThan(doveStaNellaPagina($pagine[0], 'RICEVUTA DI PAGAMENTO')[0][1] + 0.1);
});

test('la testata del condominio c\'è su ogni ricevuta con nome, indirizzo e codice fiscale insieme, e anche senza il blocco dell\'indirizzo (anteprima, email)', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $html = fn (bool $conIndirizzo) => View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s->condominio, 'ricevute' => [[
        'contenuto' => app(RicevutaIncasso::class)->per($incasso->fresh()), 'bollo' => 'non_dovuta', 'nota' => null, 'emessa_il' => '05/11/2026',
        'indirizzo_per_posta' => $conIndirizzo, 'etichetta' => 'ANTEPRIMA']]])->render();

    foreach ([true, false] as $conIndirizzo) {
        $pagine = pdfLeggibileDellaRicevuta($html($conIndirizzo));
        expect($pagine)->toHaveCount(1);
        // Le righe del condominio sono una sotto l'altra, tutte a sinistra, nell'ordine di un indirizzo (a destra c'è l'indirizzo di Anna, che comincia nello stesso modo).
        $aSinistra = fn (string $riga) => array_values(array_filter(doveStaNellaPagina($pagine[0], $riga), fn ($pos) => $pos[0] === 12.0));
        $y = [];
        foreach (['Condominio Via dei Pini', 'Via dei Pini 12', '10100 Torino', 'C.F. 90012345678'] as $riga) {
            expect($aSinistra($riga))->toHaveCount(1, "«{$riga}» manca a sinistra");
            $y[] = $aSinistra($riga)[0][1];
        }
        expect($y)->toBe(collect($y)->sort()->values()->all());
        expect(doveStaNellaPagina($pagine[0], 'Gentile') !== [])->toBe($conIndirizzo);
    }
});

test('la ricevuta non porta «Documento emesso il …» nel piè di pagina, ma la nota legale sì; le altre stampe lo portano ancora, e la loro intestazione', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $dati = ['condominio' => $s->condominio, 'nota_legale_stampe' => 'Professione esercitata ai sensi della legge 14 gennaio 2013, n.4', 'data_emissione_stampe' => now()];

    $ricevuta = View::make('pdf.gestionale.ricevute_incasso', $dati + ['ricevute' => [[
        'contenuto' => app(RicevutaIncasso::class)->per($incasso->fresh()), 'bollo' => 'non_dovuta', 'nota' => null, 'emessa_il' => '05/11/2026', 'indirizzo_per_posta' => false]]])->render();
    expect($ricevuta)->not->toContain('Documento emesso il')
        ->and($ricevuta)->toContain('Professione esercitata ai sensi della legge 14 gennaio 2013, n.4')
        ->and($ricevuta)->toContain('Emessa il <strong>05/11/2026</strong>')
        ->and($ricevuta)->not->toContain('class="header"');

    // Lo stesso layout, senza i due segni della ricevuta, come per ogni altra stampa.
    $altra = View::make('pdf.base', $dati)->render();
    expect($altra)->toContain('Documento emesso il ' . now()->format('d/m/Y'))
        ->and($altra)->toContain('Professione esercitata ai sensi della legge 14 gennaio 2013, n.4')
        ->and($altra)->toContain('class="header"')
        ->and($altra)->toContain('Condominio Via dei Pini');
});

test('la tabella delle quote ha titoli brevi (Quota, Già versato, Imputato, Resta), il codice fiscale si legge e il bollo sta vicino alla sua etichetta', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $html = View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s->condominio, 'ricevute' => [[
        'contenuto' => app(RicevutaIncasso::class)->per($incasso->fresh()), 'bollo' => 'non_dovuta', 'nota' => null, 'emessa_il' => '05/11/2026', 'indirizzo_per_posta' => false]]])->render();
    $cf = $s->anna->codice_fiscale;

    expect($html)->toContain('>Quota</th>')->toContain('>Già versato</th>')->toContain('>Imputato</th>')->toContain('>Resta</th>')
        ->and($html)->not->toContain('Importo della quota')->not->toContain('Già versato prima')->not->toContain('Imputato con questo incasso')->not->toContain('Residuo dopo')
        // Il codice fiscale è scritto con la stessa grandezza e lo stesso colore delle altre righe: non grigio e piccolo.
        ->and($html)->toContain('C.F. ' . $cf)
        ->and($html)->not->toMatch('/font-size: 8pt;">\s*— C\.F\./');

    $pagine = pdfLeggibileDellaRicevuta($html);
    // «Non dovuta» comincia a 50 mm dal bordo, a 38 mm dall'etichetta «Imposta di bollo» che sta a 12 mm: non a metà pagina.
    expect(doveStaNellaPagina($pagine[0], 'Imposta di bollo')[0][0])->toBe(12.0)
        ->and(doveStaNellaPagina($pagine[0], 'Non dovuta')[0][0])->toBe(50.0)
        ->and(doveStaNellaPagina($pagine[0], 'Data del versamento')[0][1])->toBeLessThan(60.0);
});

test('«Stampa la causale»: la ricevuta la scrive solo se l\'amministratore la sceglie, anche nell\'invio in blocco, e la scelta si ripropone', function () {
    \Illuminate\Support\Facades\Notification::fake();
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00, ['descrizione' => 'Bonifico CRO 77881']);

    // La finestra mostra il testo che la casella stamperebbe.
    expect($this->actingAs($u)->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))->json('causale'))
        ->toBe('Bonifico CRO 77881');
    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id], 'bollo' => 'assolta', 'stampa_causale' => 1])->assertOk();
    expect(RegistroInvio::sole()->dati['stampa_causale'])->toBeTrue()
        ->and(app(EmissioneRicevute::class)->sceltaRicordata($s->condominio, $u)['stampa_causale'])->toBeTrue();

    $html = fn (bool $causale) => View::make('pdf.gestionale.ricevute_incasso', ['condominio' => $s->condominio, 'ricevute' => [[
        'contenuto' => app(RicevutaIncasso::class)->per($incasso->fresh()), 'bollo' => 'assolta', 'nota' => null, 'emessa_il' => '05/11/2026',
        'stampa_causale' => $causale, 'indirizzo_per_posta' => false]]])->render();
    expect($html(true))->toContain('Causale')->toContain('Bonifico CRO 77881')
        ->and($html(false))->not->toContain('Bonifico CRO 77881');

    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.invia', ['condominio' => $s->condominio->id]),
        ['incassi' => [$incasso->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta', 'stampa_causale' => 0])->assertOk();
    $riga = RegistroInvio::orderByDesc('id')->first();
    expect($riga->dati['stampa_causale'])->toBeFalse()
        ->and(app(EmissioneRicevute::class)->sceltaRicordata($s->condominio, $u)['stampa_causale'])->toBeFalse();
});
