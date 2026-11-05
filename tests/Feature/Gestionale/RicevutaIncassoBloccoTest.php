<?php

use App\Models\Anagrafica;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Ricevute\EmissioneRicevute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * L'anteprima del blocco e la pagina delle ricevute: l'anteprima dice chi riceve che cosa (per persona, con gli indirizzi e i numeri delle
 * ricevute), quanti messaggi partono davvero con il tetto, quante selezionate hanno una causale, come dividere in più PDF quando sono più del
 * tetto; la pagina dice quante mai emesse sono di incassi registrati da poco con un versamento più vecchio del periodo; le emissioni portano lo
 * stato del bollo scelto. La finestra di un incasso dice a quali quote sarà imputato il versamento.
 *
 * Che cosa NON copre: i testi a video (nei test JavaScript).
 */
function anteprimaDelBlocco($test, object $s, array $campi, $utente = null): array
{
    return ($test->actingAs($utente ?? amministratoreRicevute()))
        ->postJson(route('admin.gestionale.ricevute.anteprima', ['condominio' => $s->condominio->id]), $campi)->assertOk()->json();
}

/** Incassi scritti a mano, con il denaro in banca e, se si dice, la persona della posizione (la riga in AVERE). */
function incassiScrittiAMano(object $s, int $quanti, ?Anagrafica $persona, int $dal = 1, string $data = '2026-02-01'): array
{
    $righe = [];
    for ($i = $dal; $i < $dal + $quanti; $i++) {
        $righe[] = [
            'condominio_id' => $s->condominio->id, 'esercizio_id' => $s->esercizio->id, 'gestione_id' => $s->gestione->id,
            'data_registrazione' => $data, 'data_competenza' => $data, 'numero_protocollo' => sprintf('INC-2026-%05d', $i),
            'causale' => 'Incasso', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now(),
        ];
    }
    DB::table('scritture_contabili')->insert($righe);
    $ids = DB::table('scritture_contabili')->where('condominio_id', $s->condominio->id)->whereIn('numero_protocollo', array_column($righe, 'numero_protocollo'))->pluck('id')->all();
    DB::table('righe_scritture')->insert(array_map(fn ($id) => [
        'scrittura_id' => $id, 'conto_contabile_id' => $s->banca->conto_contabile_id, 'cassa_id' => $s->banca->id, 'tipo_riga' => 'dare',
        'importo' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ], $ids));
    if ($persona !== null) {
        DB::table('righe_scritture')->insert(array_map(fn ($id) => [
            'scrittura_id' => $id, 'conto_contabile_id' => $s->banca->conto_contabile_id, 'tipo_riga' => 'avere', 'anagrafica_id' => $persona->id,
            'importo' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ], $ids));
    }

    return $ids;
}

test('l\'anteprima del blocco dice chi riceve che cosa: per persona, con gli indirizzi, i numeri delle ricevute e i messaggi', function () {
    $s = scenarioRicevutaIncasso();
    $s->anna->update(['pec' => 'anna@pec.prova.it']);
    $quotaDiCarlo = quotaDaPagareDiCarlo($s, 3, 10000);
    $uno = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $due = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50);
    $perCarlo = incassaPerRicevuta($s, $s->carlo, [[$quotaDiCarlo, 100.00]], '2026-02-02', 100.00);
    $ugo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-03', 60.00);

    $a = anteprimaDelBlocco($this, $s, ['incassi' => [$uno->id, $due->id, $perCarlo->id, $ugo->id], 'indirizzi' => ['email', 'email_secondaria']]);

    expect($a['persone'])->toBe([
        ['id' => $s->anna->id, 'nome' => 'Anna Bruni',
            'indirizzi' => [['tipo' => 'email', 'indirizzo' => $s->anna->email], ['tipo' => 'email_secondaria', 'indirizzo' => $s->anna->email_secondaria]],
            'ricevute' => [['numero' => $uno->numero_protocollo, 'per_conto_di' => null], ['numero' => $due->numero_protocollo, 'per_conto_di' => null]], 'messaggi' => 2],
        ['id' => $s->carlo->id, 'nome' => 'Carlo Bruni',
            'indirizzi' => [['tipo' => 'email', 'indirizzo' => $s->carlo->email]],
            'ricevute' => [['numero' => $perCarlo->numero_protocollo, 'per_conto_di' => null]], 'messaggi' => 1],
    ])
        // Ugo non ha un indirizzo: non riceve, ed è fra quelle da stampare.
        ->and($a['senza_indirizzo']['persone'])->toBe(['Ugo Neri'])
        ->and($a['email'])->toMatchArray(['persone' => 2, 'ricevute' => 3, 'indirizzi' => 3, 'messaggi' => 3]);

    // Con la PEC scelta, Anna riceve a tre indirizzi.
    $conPec = anteprimaDelBlocco($this, $s, ['incassi' => [$uno->id], 'indirizzi' => ['email', 'pec']]);
    expect(collect($conPec['persone'][0]['indirizzi'])->pluck('tipo')->all())->toBe(['email', 'pec']);
});

test('chi ha versato per un altro, se l\'amministratore lo sceglie, compare con la ricevuta e per conto di chi ha versato', function () {
    $s = scenarioRicevutaIncasso();
    quotaDaPagareDiCarlo($s, 3, 10000);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00, ['versato_da_id' => $s->carlo->id]);

    $senza = anteprimaDelBlocco($this, $s, ['incassi' => [$incasso->id], 'indirizzi' => ['email']]);
    $con = anteprimaDelBlocco($this, $s, ['incassi' => [$incasso->id], 'indirizzi' => ['email'], 'anche_chi_ha_versato' => 1]);

    expect(collect($senza['persone'])->pluck('nome')->all())->toBe(['Anna Bruni'])
        ->and(collect($con['persone'])->pluck('nome')->all())->toBe(['Anna Bruni', 'Carlo Bruni'])
        ->and($con['persone'][1]['ricevute'])->toBe([['numero' => $incasso->numero_protocollo, 'per_conto_di' => 'Anna Bruni']]);
});

test('«Mandane una a me» usa la prima persona che resta, se l\'amministratore ne ha tolta qualcuna dall\'invio', function () {
    config(['mail.default' => 'array']);
    $s = scenarioRicevutaIncasso();
    $uno = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $quotaDiCarlo = quotaDaPagareDiCarlo($s, 3, 10000);
    $perCarlo = incassaPerRicevuta($s, $s->carlo, [[$quotaDiCarlo, 100.00]], '2026-02-02', 100.00);
    $campi = ['incassi' => [$uno->id, $perCarlo->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta', 'bollo_sotto' => 'assolta'];
    $prova = fn (array $extra) => $this->actingAs(amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.prova', ['condominio' => $s->condominio->id]), $campi + $extra)->assertOk()->json('persona');

    expect($prova([]))->toBe('Anna Bruni')
        ->and($prova(['solo_persone' => [$s->carlo->id]]))->toBe('Carlo Bruni');

    // E l'invio con la stessa restrizione manda solo a quella persona.
    $this->actingAs(amministratoreRicevute())->postJson(route('admin.gestionale.ricevute.invia', ['condominio' => $s->condominio->id]), $campi + ['solo_persone' => [$s->carlo->id]])
        ->assertOk()->assertJson(['persone' => 1, 'messaggi' => 1]);
    expect(RegistroInvio::pluck('destinatario')->all())->toBe([$s->carlo->email]);
});

test('con il tetto dei messaggi l\'anteprima dice quanti partono adesso, contando le persone intere: 48 su 51, non 50', function () {
    Notification::fake();
    config(['queue.default' => 'sync']);
    $s = scenarioRicevutaIncasso();
    $ids = incassiDiPiuPersoneConDueIndirizzi($s, 17);
    Anagrafica::where('nome', 'like', 'Persona %')->get()->each(fn ($p) => $p->update(['pec' => "p{$p->id}@pec.prova.it"]));
    $campi = ['incassi' => $ids, 'indirizzi' => ['email', 'email_secondaria', 'pec'], 'bollo_sotto' => 'non_dovuta'];

    $anteprima = anteprimaDelBlocco($this, $s, $campi);
    $esito = $this->actingAs(amministratoreRicevute())->postJson(route('admin.gestionale.ricevute.invia', ['condominio' => $s->condominio->id]), $campi)->assertOk()->json();

    expect($anteprima['email']['messaggi'])->toBe(51)
        ->and($anteprima['email']['messaggi_adesso'])->toBe(48)
        ->and($esito['messaggi'])->toBe(48);

    // Con il servizio della coda partono tutti.
    config(['queue.default' => 'database']);
    expect(anteprimaDelBlocco($this, $s, $campi)['email']['messaggi_adesso'])->toBe(51);
});

test('l\'anteprima dice quante selezionate hanno una causale e quali, perché l\'amministratore veda che cosa stamperebbe «Stampa la causale»', function () {
    $s = scenarioRicevutaIncasso();
    $uno = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00, ['descrizione' => 'Bonifico CRO 123 — verificare con Carlo']);
    $due = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50, ['descrizione' => 'Bonifico CRO 123 — verificare con Carlo']);
    $ugo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-03', 60.00, ['descrizione' => 'Contanti']);
    ScritturaContabile::where('id', $ugo->id)->update(['causale' => '']);

    $a = anteprimaDelBlocco($this, $s, ['incassi' => [$uno->id, $due->id, $ugo->id], 'indirizzi' => ['email']]);

    expect($a['con_causale'])->toBe(2)
        ->and($a['causali'])->toBe([['testo' => 'Bonifico CRO 123 — verificare con Carlo', 'quante' => 2]]);
});

test('più ricevute del tetto di un PDF si dividono in parti che non spezzano una persona; la pagina offre una parte per volta', function () {
    $s = scenarioRicevutaIncasso();
    $anna = incassiScrittiAMano($s, 150, $s->anna);
    $carlo = incassiScrittiAMano($s, 55, $s->carlo, 151);

    $a = anteprimaDelBlocco($this, $s, ['tutte' => 1, 'filtri' => ['ricevuta' => 'tutte']]);

    expect($a['ricevute'])->toBe(205)
        ->and($a['oltre_tetto'])->toBeTrue()
        ->and(max(array_map('count', $a['parti_pdf'])))->toBeLessThanOrEqual(EmissioneRicevute::TETTO_PDF);
    // Le persone nell'ordine dell'elenco (dal versamento più recente): ognuna intera in una parte.
    $insiemi = collect($a['parti_pdf'])->map(fn (array $parte) => collect($parte)->sort()->values()->all())->sortBy(fn (array $parte) => count($parte))->values()->all();
    expect($insiemi)->toBe([collect($carlo)->sort()->values()->all(), collect($anna)->sort()->values()->all()]);

    // Una sola persona con più ricevute del tetto si divide da sé.
    $solaPersona = scenarioRicevutaIncasso('Condominio Corso Italia');
    incassiScrittiAMano($solaPersona, 250, $solaPersona->anna, 1);
    $b = anteprimaDelBlocco($this, $solaPersona, ['tutte' => 1, 'filtri' => ['ricevuta' => 'tutte']]);
    expect(array_map('count', $b['parti_pdf']))->toBe([200, 50]);

    // Sotto il tetto non c'è niente da dividere.
    $piccolo = scenarioRicevutaIncasso('Condominio Via Roma');
    incassiScrittiAMano($piccolo, 3, $piccolo->anna, 1);
    $c = anteprimaDelBlocco($this, $piccolo, ['tutte' => 1, 'filtri' => ['ricevuta' => 'tutte']]);
    expect($c['oltre_tetto'])->toBeFalse()->and($c['parti_pdf'])->toBe([]);
});

test('la pagina conta le mai emesse di incassi registrati da poco con un versamento più vecchio del periodo, e dice da quando', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $periodo = \App\Services\Gestionale\Ricevute\ElencoRicevute::periodoDiApertura();
    $vecchio = now()->setTimezone(config('app.user_timezone'))->subMonths(3)->format('Y-m-d');
    $piuVecchio = now()->setTimezone(config('app.user_timezone'))->subMonths(5)->format('Y-m-d');
    // Registrato adesso, con un versamento di tre mesi fa; e uno di cinque mesi fa, registrato adesso.
    $recente = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], $vecchio, 150.00);
    $altro = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], $piuVecchio, 120.50);
    // Uno registrato prima del periodo non è «di recente», e uno già emesso non conta.
    $registratoPrima = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], $piuVecchio, 60.00);
    ScritturaContabile::where('id', $registratoPrima->id)->update(['created_at' => now()->subMonths(4)]);
    $emesso = incassaPerRicevuta($s, $s->ugo, [[$s->ugo2, 60.00]], $vecchio, 60.00);
    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$emesso->id], 'bollo_sotto' => 'non_dovuta'])->assertOk();

    $pagina = $this->actingAs($u)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id]))->assertOk()->viewData('page')['props'];

    expect($pagina['incassi']['data'])->toBe([])
        ->and($pagina['registrati_di_recente'])->toBe(['numero' => 2, 'dal' => $piuVecchio]);

    // Con un periodo che li comprende non c'è niente da segnalare.
    $tutto = $this->actingAs($u)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id, 'ricevuta' => 'mai', 'data_da' => $piuVecchio, 'data_a' => $periodo['data_a']]))
        ->viewData('page')['props'];
    expect($tutto['registrati_di_recente'])->toBeNull()
        ->and($recente->id)->not->toBe($altro->id);
});

test('le emissioni dicono lo stato del bollo scelto, nell\'elenco e nella pagina dell\'incasso', function () {
    $s = scenarioRicevutaIncasso();
    $u = amministratoreRicevute();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id], 'bollo' => 'assolta', 'modo_bollo' => 'virtuale', 'estremi_bollo' => 'aut. 3'])->assertOk();

    $elenco = $this->actingAs($u)->get(route('admin.gestionale.ricevute.index', ['condominio' => $s->condominio->id, 'ricevuta' => 'tutte']))->viewData('page')['props']['incassi']['data'][0]['ultima'];
    $pagina = $this->actingAs($u)->get(route('admin.gestionale.movimenti-rate.show', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))->viewData('page')['props']['emissioni'][0];

    expect($elenco['bollo'])->toBe('assolta')
        ->and($elenco['modo_bollo'])->toBe('virtuale')
        ->and($pagina['bollo'])->toBe('assolta')
        ->and($pagina['modo_bollo'])->toBe('virtuale');
});

test('la finestra di un incasso dice a quali quote sarà imputato il versamento, com\'è registrato nell\'incasso', function () {
    $s = scenarioRicevutaIncasso();
    // € 50,00 già versati sulla rata 1; poi € 180,50: € 100,00 chiudono la rata 1, € 80,50 vanno sulla rata 2 (€ 120,50), che resta con € 40,00.
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 50.00]], '2026-01-10', 50.00);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 100.00], [$s->anna2, 80.50]], '2026-04-15', 180.50);

    $opzioni = $this->actingAs(amministratoreRicevute())
        ->getJson(route('admin.gestionale.ricevute.opzioni', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))->assertOk()->json();

    expect(array_map(fn ($q) => [$q['rata'], $q['piano'], $q['unita'], $q['importo'], $q['imputato'], $q['dopo']], $opzioni['quote']))->toBe([
        ['Rata 1', 'Preventivo 2026', 'Int. 4', 15000, 10000, 0],
        ['Rata 2', 'Preventivo 2026', 'Int. 4', 12050, 8050, 4000],
    ])
        ->and($opzioni['parte_in_piu'])->toBe([])
        ->and($opzioni['credito_usato'])->toBe(0);
});
