<?php

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Exceptions\Gestionale\DebitoNonDelPaganteException;
use App\Exceptions\Gestionale\ParteInPiuSenzaRateException;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Services\Gestionale\VersamentiPerContoDiAltri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Coda 167, 1.11.0-beta.40 — chi versa per il debito di un altro (decisione 30 in
 * `docs/subentro_e_competenza_temporale.md`).
 *
 * Dal forum p=573: chi paga può essere chiunque, e all'incasso «oltre a chi versa, si sceglie su
 * quale posizione lo si imputa» (Gabriele). La posizione resta il pagante di sempre, cioè il
 * debitore, e il motore degli incassi non cambia; il campo nuovo `versato_da_id` dice chi ha
 * versato davvero, e il legame si scrive nella nota e nel campo `riferimento` delle righe.
 *
 * ## Cosa questo file NON copre
 *
 * - Le righe divise per intestatario nella ricerca per unità, e quindi un incasso solo per più
 *   posizioni: sono la metà frontend della voce ⑧, collocata alla beta.42 (decisione 30.6).
 * - Il modulo `IncassoRateNew.vue`: le due scelte (credito della posizione, rata della parte in più) e gli avvisi
 *   stanno in `resources/js/pages/gestionale/movimenti/incassi/IncassoRateNewVersatoDa.test.ts` (vitest).
 */
function scenarioPerContoDi(): object
{
    $condominio = Condominio::create([
        'nome' => 'Condominio Via dei Tigli', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via dei Tigli 3', 'citta' => 'Roma', 'cap' => '00100', 'provincia' => 'RM',
    ]);
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);
    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => 'Ordinaria', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01',
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $contoBanca = ContoContabile::create([
        'condominio_id' => $condominio->id, 'codice' => '10.10', 'nome' => 'Banca',
        'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita',
    ]);
    ContoContabile::create([
        'condominio_id' => $condominio->id, 'codice' => '10.20', 'nome' => 'Crediti vs Condomini',
        'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti',
    ]);
    $cassa = Cassa::create([
        'condominio_id' => $condominio->id, 'conto_contabile_id' => $contoBanca->id,
        'nome' => 'Banca', 'tipo' => 'banca', 'attiva' => true,
    ]);

    $persona = fn (string $nome, string $cf) => Anagrafica::create([
        'nome' => $nome, 'email' => Str::slug($nome) . '@test.it', 'indirizzo' => 'Via dei Tigli 3',
        'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => $cf,
    ]);

    // Chi vende ha lasciato una rata non pagata: la posizione, cioè il debitore.
    $venditore = $persona('Marco Neri', 'NRIMRC80A01H501U');
    // Chi compra: da quando è entrato ha le sue rate, e salda l'arretrato di chi vende.
    $compratore = $persona('Lidia Ferri', 'FRRLDI80A41H501K');
    // Il figlio di chi vende: associato al condominio senza unità, quindi senza rate.
    $figlio = $persona('Paolo Neri', 'NRIPLA05A01H501X');

    $immobile = Immobile::create([
        'condominio_id' => $condominio->id, 'nome' => 'Interno 3', 'descrizione' => 'Appartamento',
        'interno' => '3', 'foglio' => '1', 'particella' => '1', 'subalterno' => '3',
    ]);
    $immobile->anagrafiche()->attach($venditore->id, [
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => false,
        'data_inizio' => '2020-01-01', 'data_fine' => '2026-04-30',
    ]);
    $immobile->anagrafiche()->attach($compratore->id, [
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2026-05-01',
    ]);
    // Tutti e tre nel pivot del condominio, come nel prodotto (l'anagrafica e il passaggio ce li scrivono): è lì che
    // la rotta dell'estratto conto risolve la persona.
    foreach ([$venditore, $compratore, $figlio] as $p) {
        $p->condomini()->attach($condominio->id);
    }

    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id, 'nome' => 'Piano 2026', 'numero_rate' => 4,
    ]);
    $rata = fn (int $n, string $scadenza) => Rata::create([
        'piano_rate_id' => $piano->id, 'numero_rata' => $n, 'data_scadenza' => $scadenza,
        'importo_totale' => 30000, 'stato' => 'emessa',
    ]);
    $rata1 = $rata(1, '2026-01-31');
    $rata3 = $rata(3, '2026-03-31');
    $rata5 = $rata(5, '2026-05-31');

    $debitoVenditore = RataQuote::create([
        'rata_id' => $rata3->id, 'anagrafica_id' => $venditore->id, 'immobile_id' => $immobile->id,
        'importo' => 30000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-03-31',
    ]);

    return (object) compact(
        'condominio', 'esercizio', 'gestione', 'cassa', 'immobile', 'piano',
        'venditore', 'compratore', 'figlio', 'rata1', 'rata3', 'rata5', 'debitoVenditore'
    );
}

function incassaPerConto(object $s, array $campi): void
{
    app(StoreIncassoRateAction::class)->execute(array_merge([
        'pagante_id' => $s->venditore->id,
        'cassa_id' => $s->cassa->id,
        'gestione_id' => $s->gestione->id,
        'data_pagamento' => '2026-06-10',
        'importo_totale' => 300.00,
        'descrizione' => 'Bonifico',
        'eccedenza' => 0,
        'dettaglio_pagamenti' => [['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00]],
    ], $campi), $s->condominio, $s->esercizio);
}

function rigaCassa(): RigaScrittura
{
    return RigaScrittura::whereNotNull('cassa_id')->firstOrFail();
}

test('senza «Versato da» l\'incasso è quello di sempre', function () {
    $s = scenarioPerContoDi();

    incassaPerConto($s, []);

    $chiusura = RigaScrittura::where('anagrafica_id', $s->venditore->id)->where('tipo_riga', 'avere')->firstOrFail();

    expect($s->debitoVenditore->refresh()->importo_pagato)->toBe(30000)
        ->and(rigaCassa()->note)->toBe('Versamento rate Marco Neri')
        ->and($chiusura->note)->toBe('Incasso rata n.3')
        ->and($chiusura->riferimento_id)->toBeNull();
});

test('«Versato da» uguale al pagante non cambia niente', function () {
    $s = scenarioPerContoDi();

    incassaPerConto($s, ['versato_da_id' => $s->venditore->id]);

    $chiusura = RigaScrittura::where('anagrafica_id', $s->venditore->id)->where('tipo_riga', 'avere')->firstOrFail();

    expect(rigaCassa()->note)->toBe('Versamento rate Marco Neri')
        ->and($chiusura->note)->toBe('Incasso rata n.3')
        ->and($chiusura->riferimento_id)->toBeNull();
});

test('con «Versato da» la quota della posizione si salda e resta intestata al debitore', function () {
    $s = scenarioPerContoDi();

    incassaPerConto($s, ['versato_da_id' => $s->compratore->id]);

    $s->debitoVenditore->refresh();
    expect($s->debitoVenditore->anagrafica_id)->toBe($s->venditore->id)
        ->and($s->debitoVenditore->importo_pagato)->toBe(30000)
        ->and($s->debitoVenditore->stato)->toBe('pagata');
});

test('la riga di cassa dice chi ha versato e per conto di chi', function () {
    $s = scenarioPerContoDi();

    incassaPerConto($s, ['versato_da_id' => $s->compratore->id]);

    expect(rigaCassa()->note)->toBe('Versamento rate Lidia Ferri per conto di Marco Neri');
});

test('la riga che chiude il debito nomina chi ha versato e lo lega nel riferimento', function () {
    // Il legame non vive nella causale libera: sta sulla riga che l'estratto conto del debitore
    // mostra, e nel campo `riferimento` da cui si ricostruisce l'estratto conto di chi ha versato.
    $s = scenarioPerContoDi();

    incassaPerConto($s, ['versato_da_id' => $s->compratore->id]);

    $chiusura = RigaScrittura::where('anagrafica_id', $s->venditore->id)->where('tipo_riga', 'avere')->firstOrFail();

    expect($chiusura->note)->toBe('Incasso rata n.3, versato da Lidia Ferri')
        ->and($chiusura->riferimento_type)->toBe(Anagrafica::class)
        ->and($chiusura->riferimento_id)->toBe($s->compratore->id)
        ->and($chiusura->riferimento->nome)->toBe('Lidia Ferri');
});

test('la parte in più va a chi ha versato, se ha rate nel condominio, non al debitore', function () {
    // Decisione 30.3, dalla risposta di Fresco. Senza correzione il motore appoggia l'eccedenza
    // sull'«ultima quota toccata», che qui è del debitore: il venditore si trovava un credito
    // con i soldi di chi ha comprato.
    $s = scenarioPerContoDi();
    $quotaCompratore = RataQuote::create([
        'rata_id' => $s->rata5->id, 'anagrafica_id' => $s->compratore->id, 'immobile_id' => $s->immobile->id,
        'importo' => 30000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-05-31',
    ]);

    // La schermata manda la parte in più nel campo `eccedenza`: la riga non supera mai il residuo.
    incassaPerConto($s, [
        'versato_da_id' => $s->compratore->id,
        'importo_totale' => 350.00,
        'eccedenza' => 50.00,
    ]);

    $avereVenditore = (int) RigaScrittura::where('anagrafica_id', $s->venditore->id)->where('tipo_riga', 'avere')->sum('importo');
    $rigaParteInPiu = RigaScrittura::where('anagrafica_id', $s->compratore->id)->where('tipo_riga', 'avere')->firstOrFail();

    expect($s->debitoVenditore->refresh()->importo_pagato)->toBe(30000)
        ->and($avereVenditore)->toBe(30000)
        ->and($rigaParteInPiu->importo)->toBe(5000)
        ->and($rigaParteInPiu->note)->toBe('Anticipo / Eccedenza, dal versamento per conto di Marco Neri')
        ->and($quotaCompratore->refresh()->importo_pagato)->toBe(5000);
});

test('se chi ha versato non ha rate nel condominio, la parte in più non si registra: rifiutato, niente scritto', function () {
    $s = scenarioPerContoDi();

    expect(fn () => incassaPerConto($s, [
        'versato_da_id' => $s->figlio->id,
        'importo_totale' => 350.00,
        'eccedenza' => 50.00,
    ]))->toThrow(ParteInPiuSenzaRateException::class, 'Paolo Neri non ha rate emesse in questo condominio');

    expect($s->debitoVenditore->refresh()->importo_pagato)->toBe(0)
        ->and(ScritturaContabile::count())->toBe(0);
});

test('chi versa senza rate nel condominio può pagare il debito esatto', function () {
    $s = scenarioPerContoDi();

    incassaPerConto($s, ['versato_da_id' => $s->figlio->id]);

    expect($s->debitoVenditore->refresh()->importo_pagato)->toBe(30000)
        ->and(rigaCassa()->note)->toBe('Versamento rate Paolo Neri per conto di Marco Neri');
});

test('il credito del debitore si usa insieme al denaro di chi versa, e solo la parte in denaro porta il legame', function () {
    // Il credito è della posizione, cioè del debitore: usarlo sul suo debito è la compensazione di
    // sempre. Chi ha versato ha messo solo il denaro, e solo le righe pagate col denaro lo nominano.
    $s = scenarioPerContoDi();
    $creditoVenditore = RataQuote::create([
        'rata_id' => $s->rata1->id, 'anagrafica_id' => $s->venditore->id, 'immobile_id' => $s->immobile->id,
        'importo' => 10000, 'importo_pagato' => 20000, 'stato' => 'pagata', 'data_scadenza' => '2026-01-31',
    ]);

    incassaPerConto($s, [
        'versato_da_id' => $s->compratore->id,
        'importo_totale' => 200.00,
        'dettaglio_pagamenti' => [
            ['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00],
            ['rata_id' => $creditoVenditore->id, 'importo' => -100.00],
        ],
    ]);

    $collegate = RigaScrittura::where('riferimento_id', $s->compratore->id)->where('riferimento_type', Anagrafica::class)->get();

    expect($s->debitoVenditore->refresh()->importo_pagato)->toBe(30000)
        ->and($creditoVenditore->refresh()->credito_disponibile)->toBe(0)
        ->and($collegate)->toHaveCount(1)
        ->and($collegate->first()->importo)->toBe(20000);
});

test('il messaggio della guardia indica la via per chi paga il debito di un altro', function () {
    $s = scenarioPerContoDi();

    expect(fn () => incassaPerConto($s, ['pagante_id' => $s->compratore->id]))
        ->toThrow(DebitoNonDelPaganteException::class, '«Versato da»');
});

test('il riquadro di chi ha versato elenca i versamenti per conto di altri, e dice quando sono stornati', function () {
    $s = scenarioPerContoDi();

    incassaPerConto($s, ['versato_da_id' => $s->compratore->id]);

    $riquadro = app(VersamentiPerContoDiAltri::class)->per($s->condominio, $s->compratore);

    expect($riquadro)->toHaveCount(1)
        ->and($riquadro[0]['per_conto_di'])->toBe('Marco Neri')
        ->and($riquadro[0]['importo_formattato'])->toBe('€ 300,00')
        ->and($riquadro[0]['stornato'])->toBeFalse()
        // Il debitore non ha versato niente per nessuno: il suo legame è la nota della riga.
        ->and(app(VersamentiPerContoDiAltri::class)->per($s->condominio, $s->venditore))->toBe([]);

    app(StornoIncassoRateAction::class)->execute(ScritturaContabile::where('tipo_movimento', 'incasso_rata')->firstOrFail(), $s->condominio);

    expect(app(VersamentiPerContoDiAltri::class)->per($s->condominio, $s->compratore)[0]['stornato'])->toBeTrue();
});

test('«Versato da» si sceglie fra le persone del condominio, non fra quelle di un altro', function () {
    // Lente sicurezza (decisione 30.4): le anagrafiche sono comuni a tutti i condomìni, e un id
    // qualunque legherebbe un incasso di questo condominio a una persona di un altro.
    $s = scenarioPerContoDi();
    $altro = Condominio::create([
        'nome' => 'Altro condominio', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $estraneo = Anagrafica::create([
        'nome' => 'Anna Estranea', 'email' => 'estranea@test.it', 'indirizzo' => 'Via Roma 1',
        'cap' => '20100', 'citta' => 'Milano', 'provincia' => 'MI', 'codice_fiscale' => 'STRNNA80A41F205X',
    ]);
    $estraneo->condomini()->attach($altro->id);

    $utente = \App\Models\User::factory()->create();
    $utente->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']));

    $dati = [
        'pagante_id' => $s->venditore->id, 'cassa_id' => $s->cassa->id, 'gestione_id' => $s->gestione->id,
        'data_pagamento' => '2026-06-10', 'importo_totale' => 300.00, 'descrizione' => 'Bonifico', 'eccedenza' => 0,
        'dettaglio_pagamenti' => [['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00]],
    ];

    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", $dati + ['versato_da_id' => $estraneo->id])
        ->assertSessionHasErrors('versato_da_id');
    expect(ScritturaContabile::count())->toBe(0);

    // Controprova sulla rotta vera: il figlio, associato al condominio senza unità, si accetta.
    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", $dati + ['versato_da_id' => $s->figlio->id])
        ->assertSessionHasNoErrors();
    expect(RigaScrittura::where('riferimento_id', $s->figlio->id)->count())->toBe(1);
});

function utenteDellEstrattoPerConto(): \App\Models\User
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente = \App\Models\User::factory()->create();
    $utente->assignRole($ruolo);

    return $utente;
}

test('l\'estratto conto di chi ha versato porta il riquadro, a schermo e nel PDF; quello del debitore porta la nota', function () {
    $s = scenarioPerContoDi();
    incassaPerConto($s, ['versato_da_id' => $s->compratore->id]);
    $utente = utenteDellEstrattoPerConto();

    // A schermo, chi ha versato: il riquadro, e nel libro nessuna riga per quel versamento (non era suo debito).
    $props = $this->actingAs($utente)
        ->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s->condominio, $s->compratore]))
        ->assertOk()->viewData('page')['props'];
    expect($props['versamenti_per_altri'])->toHaveCount(1)
        ->and($props['versamenti_per_altri'][0]['per_conto_di'])->toBe('Marco Neri')
        ->and(collect($props['timeline'])->where('scrittura_id', $props['versamenti_per_altri'][0]['scrittura_id']))->toBeEmpty();

    // A schermo, il debitore: la riga che chiude la sua rata dice chi ha versato.
    $propsDebitore = $this->actingAs($utente)
        ->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s->condominio, $s->venditore]))
        ->assertOk()->viewData('page')['props'];
    expect(collect($propsDebitore['timeline'])->pluck('note')->filter()->implode(' | '))->toContain('versato da Lidia Ferri')
        ->and($propsDebitore['versamenti_per_altri'])->toBe([]);

    // Nel PDF: i dati che il controller passa al modello, e il testo che il modello ne stampa.
    $dati = null;
    $pdf = Mockery::mock(\Mpdf\Mpdf::class);
    $pdf->shouldReceive('SetHeader');
    $pdf->shouldReceive('Output')->andReturn('%PDF-finto');
    $this->mock(\App\Services\PDF\PdfService::class, function ($m) use (&$dati, $pdf) {
        $m->shouldReceive('generate')->once()->andReturnUsing(function ($vista, $d) use (&$dati, $pdf) {
            $dati = $d;
            return $pdf;
        });
    });
    $this->actingAs($utente)
        ->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$s->condominio, $s->compratore]))
        ->assertOk();

    $html = view('pdf.gestionale.estratto_conto_anagrafica', $dati)->render();
    expect($html)->toContain('Versamenti per conto di altri')
        ->and($html)->toContain('Versato il 10/06/2026: € 300,00 per conto di Marco Neri');
});

test('l\'elenco e il dettaglio degli incassi mostrano la posizione e chi ha versato', function () {
    $s = scenarioPerContoDi();
    incassaPerConto($s, ['versato_da_id' => $s->compratore->id]);

    $servizio = app(\App\Services\Gestionale\IncassoRateService::class);
    $riga = $servizio->getIncassiQuery($s->condominio)->get()->map(fn ($m) => $servizio->formatMovimentoForFrontend($m))->sole();

    expect($riga['pagante']['principale'])->toBe('Marco Neri')
        ->and($riga['pagante']['versato_da'])->toBe('Lidia Ferri');
});

test('senza «Versato da» l\'elenco non nomina nessun altro', function () {
    $s = scenarioPerContoDi();
    incassaPerConto($s, []);

    $servizio = app(\App\Services\Gestionale\IncassoRateService::class);
    $riga = $servizio->getIncassiQuery($s->condominio)->get()->map(fn ($m) => $servizio->formatMovimentoForFrontend($m))->sole();

    expect($riga['pagante']['versato_da'])->toBeNull();
});

test('la parte in più va sulla quota di chi ha versato nella stessa rata, prima che altrove', function () {
    // Il compratore dopo il passaggio ha quasi sempre la sua quota sulla rata del venditore: lì la parte in più è
    // naturale, e non su una rata più lontana.
    $s = scenarioPerContoDi();
    $stessaRata = RataQuote::create([
        'rata_id' => $s->rata3->id, 'anagrafica_id' => $s->compratore->id, 'immobile_id' => $s->immobile->id,
        'importo' => 5000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-03-31',
    ]);
    $piuLontana = RataQuote::create([
        'rata_id' => $s->rata5->id, 'anagrafica_id' => $s->compratore->id, 'immobile_id' => $s->immobile->id,
        'importo' => 30000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-05-31',
    ]);

    incassaPerConto($s, ['versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00]);

    expect($stessaRata->refresh()->importo_pagato)->toBe(5000)
        ->and($piuLontana->refresh()->importo_pagato)->toBe(0);
});

test('le rate di chi ha versato in un altro condominio non contano: la parte in più si rifiuta', function () {
    // Lente sicurezza: la quota su cui appoggiare il credito si cerca solo in questo condominio. Senza il filtro, il
    // denaro versato qui diventava un credito su una rata di un altro condominio amministrato.
    $s = scenarioPerContoDi();
    $altro = Condominio::create([
        'nome' => 'Altro condominio', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $gestioneAltra = Gestione::create(['condominio_id' => $altro->id, 'nome' => 'Ordinaria', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01']);
    $pianoAltro = PianoRate::create(['condominio_id' => $altro->id, 'gestione_id' => $gestioneAltra->id, 'nome' => 'Piano', 'numero_rate' => 1]);
    $rataAltra = Rata::create(['piano_rate_id' => $pianoAltro->id, 'numero_rata' => 1, 'data_scadenza' => '2026-07-31', 'importo_totale' => 10000, 'stato' => 'emessa']);
    $quotaAltrove = RataQuote::create([
        'rata_id' => $rataAltra->id, 'anagrafica_id' => $s->figlio->id,
        'importo' => 10000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-07-31',
    ]);

    expect(fn () => incassaPerConto($s, ['versato_da_id' => $s->figlio->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00]))
        ->toThrow(ParteInPiuSenzaRateException::class);

    expect($quotaAltrove->refresh()->importo_pagato)->toBe(0);
});

test('il modulo riceve le persone del condominio, e sa chi ha rate', function () {
    $s = scenarioPerContoDi();
    RataQuote::create([
        'rata_id' => $s->rata5->id, 'anagrafica_id' => $s->compratore->id, 'immobile_id' => $s->immobile->id,
        'importo' => 30000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-05-31',
    ]);

    // Il figlio ha una quota solo su una rata in bozza: non gli è ancora stata chiesta, quindi non conta.
    $bozza = Rata::create(['piano_rate_id' => $s->piano->id, 'numero_rata' => 9, 'data_scadenza' => '2026-09-30', 'importo_totale' => 30000, 'stato' => 'bozza']);
    RataQuote::create([
        'rata_id' => $bozza->id, 'anagrafica_id' => $s->figlio->id,
        'importo' => 10000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-09-30',
    ]);

    $persone = collect($this->actingAs(utenteDellEstrattoPerConto())
        ->get("/admin/gestionale/{$s->condominio->id}/movimenti-rate/create")
        ->assertOk()->viewData('page')['props']['persone'])->keyBy('nome');

    // Il figlio è associato senza unità: nell'elenco dei condòmini con unità non c'è, fra le persone sì.
    expect($persone->keys()->all())->toContain('Paolo Neri', 'Lidia Ferri', 'Marco Neri')
        ->and($persone['Lidia Ferri']['ha_rate'])->toBeTrue()
        ->and($persone['Paolo Neri']['ha_rate'])->toBeFalse();
});

test('senza una quota sulla stessa rata, la parte in più resta nella stessa gestione anche se altrove c\'è una rata più recente', function () {
    $s = scenarioPerContoDi();
    $straordinaria = Gestione::create(['condominio_id' => $s->condominio->id, 'nome' => 'Tetto', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01']);
    $pianoTetto = PianoRate::create(['condominio_id' => $s->condominio->id, 'gestione_id' => $straordinaria->id, 'nome' => 'Tetto', 'numero_rate' => 1]);
    $rataTetto = Rata::create(['piano_rate_id' => $pianoTetto->id, 'numero_rata' => 1, 'data_scadenza' => '2026-09-30', 'importo_totale' => 50000, 'stato' => 'emessa']);
    $quotaTetto = RataQuote::create([
        'rata_id' => $rataTetto->id, 'anagrafica_id' => $s->compratore->id, 'immobile_id' => $s->immobile->id,
        'importo' => 50000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-09-30',
    ]);
    $quotaOrdinaria = RataQuote::create([
        'rata_id' => $s->rata5->id, 'anagrafica_id' => $s->compratore->id, 'immobile_id' => $s->immobile->id,
        'importo' => 30000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-05-31',
    ]);

    incassaPerConto($s, ['versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00]);

    expect($quotaOrdinaria->refresh()->importo_pagato)->toBe(5000)
        ->and($quotaTetto->refresh()->importo_pagato)->toBe(0);
});

test('il riquadro di chi ha versato guarda solo questo condominio', function () {
    // Una persona può stare in due condomìni amministrati dallo stesso studio: i versamenti fatti nell'altro non sono
    // affare di questo estratto conto.
    $s = scenarioPerContoDi();
    incassaPerConto($s, ['versato_da_id' => $s->compratore->id]);

    $altro = Condominio::create([
        'nome' => 'Altro condominio', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $scritturaAltrove = ScritturaContabile::create([
        'condominio_id' => $altro->id, 'esercizio_id' => $s->esercizio->id, 'gestione_id' => $s->gestione->id,
        'data_registrazione' => now(), 'data_competenza' => '2026-06-11', 'causale' => 'Bonifico',
        'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata',
    ]);
    $scritturaAltrove->righe()->create([
        'conto_contabile_id' => ContoContabile::first()->id, 'anagrafica_id' => $s->venditore->id,
        'tipo_riga' => 'avere', 'importo' => 7000,
        'riferimento_type' => Anagrafica::class, 'riferimento_id' => $s->compratore->id,
    ]);

    $riquadro = app(VersamentiPerContoDiAltri::class)->per($s->condominio, $s->compratore);

    expect($riquadro)->toHaveCount(1)
        ->and($riquadro[0]['importo_cents'])->toBe(30000);
});

test('se chi ha versato ha solo rate in bozza, la parte in più si rifiuta e la bozza non si tocca', function () {
    // Un pagamento su una quota in bozza fa scattare `haIncassiRegistrati()` e blocca il ricalcolo di quel piano: la
    // parte in più di un versamento per conto di altri non deve chiudere un piano che nessuno ha chiesto di chiudere.
    // «Ha rate» vuol dire rate emesse, quelle che gli sono state chieste (precisazione della decisione 30.3).
    $s = scenarioPerContoDi();
    $bozza = Rata::create([
        'piano_rate_id' => $s->piano->id, 'numero_rata' => 9, 'data_scadenza' => '2026-09-30',
        'importo_totale' => 30000, 'stato' => 'bozza',
    ]);
    $quotaBozza = RataQuote::create([
        'rata_id' => $bozza->id, 'anagrafica_id' => $s->compratore->id, 'immobile_id' => $s->immobile->id,
        'importo' => 30000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-09-30',
    ]);

    expect(fn () => incassaPerConto($s, ['versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00]))
        ->toThrow(ParteInPiuSenzaRateException::class, 'Lidia Ferri non ha rate emesse in questo condominio');

    expect($quotaBozza->refresh()->importo_pagato)->toBe(0)
        ->and(ScritturaContabile::count())->toBe(0);
});

test('nel registro di contabilità la controparte è chi ha versato, per conto di chi', function () {
    // Decisione di Vincenzo del 30/09/2026 notte: il registro è dove si controlla chi ha messo il denaro in cassa.
    $s = scenarioPerContoDi();
    incassaPerConto($s, ['versato_da_id' => $s->compratore->id]);

    $registro = app(\App\Services\Gestionale\RegistroContabilitaService::class)->registro($s->esercizio);

    expect(collect($registro)->pluck('controparte')->all())->toBe(['Lidia Ferri per conto di Marco Neri']);
});

test('senza «Versato da» il registro dice il debitore, come sempre', function () {
    $s = scenarioPerContoDi();
    incassaPerConto($s, []);

    $registro = app(\App\Services\Gestionale\RegistroContabilitaService::class)->registro($s->esercizio);

    expect(collect($registro)->pluck('controparte')->all())->toBe(['Marco Neri']);
});

test('nel mastrino dei crediti la riga del debitore dice chi ha versato, la parte in più di chi ha versato no', function () {
    $s = scenarioPerContoDi();
    RataQuote::create([
        'rata_id' => $s->rata5->id, 'anagrafica_id' => $s->compratore->id, 'immobile_id' => $s->immobile->id,
        'importo' => 30000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-05-31',
    ]);
    incassaPerConto($s, ['versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00]);

    $crediti = ContoContabile::where('ruolo', 'crediti_condomini')->firstOrFail();
    $righe = collect(app(\App\Services\Gestionale\RegistroContabilitaService::class)->mastrino($s->esercizio, $crediti)['righe']);

    expect($righe->pluck('controparte')->sort()->values()->all())
        ->toBe(['Lidia Ferri', 'Lidia Ferri per conto di Marco Neri']);
});

/*
|--------------------------------------------------------------------------
| Fase 1-bis della beta.40 — i reperti confermati e le decisioni 30.7–30.10
|--------------------------------------------------------------------------
*/

function datiIncassoPerConto(object $s, array $campi = []): array
{
    return array_merge([
        'pagante_id' => $s->venditore->id, 'cassa_id' => $s->cassa->id, 'gestione_id' => $s->gestione->id,
        'data_pagamento' => '2026-06-10', 'importo_totale' => 300.00, 'descrizione' => 'Bonifico', 'eccedenza' => 0,
        'dettaglio_pagamenti' => [['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00]],
    ], $campi);
}

/** Il credito del venditore come saldo iniziale a credito: una quota negativa, che il motore sa consumare e restituire. */
function creditoDelVenditore(object $s, int $cents): RataQuote
{
    return RataQuote::create([
        'rata_id' => $s->rata1->id, 'anagrafica_id' => $s->venditore->id, 'immobile_id' => $s->immobile->id,
        'importo' => -$cents, 'importo_pagato' => 0, 'stato' => 'pagata', 'data_scadenza' => '2026-01-31',
    ]);
}

function quotaDelCompratore(object $s, Rata $rata, int $importo = 30000, int $pagato = 0): RataQuote
{
    $quota = RataQuote::create([
        'rata_id' => $rata->id, 'anagrafica_id' => $s->compratore->id, 'immobile_id' => $s->immobile->id,
        'importo' => $importo, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => $rata->data_scadenza,
    ]);

    // Un pagamento vero, nel pivot: `ricalcolaStato()` rilegge da lì, e un `importo_pagato` scritto a mano sparirebbe
    // al primo incasso che tocca la quota.
    if ($pagato > 0) {
        $pagamento = ScritturaContabile::create([
            'condominio_id' => $s->condominio->id, 'esercizio_id' => $s->esercizio->id, 'gestione_id' => $rata->pianoRate->gestione_id,
            'data_registrazione' => now(), 'data_competenza' => '2026-02-01', 'causale' => 'Rata pagata prima',
            'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata',
        ]);
        $quota->pagamenti()->attach($pagamento->id, ['importo_pagato' => $pagato, 'data_pagamento' => '2026-02-01']);
        $quota->ricalcolaStato();
    }

    return $quota;
}

test('un id mandato come elenco è un errore, non l\'anagrafica n.1 di un altro palazzo (R5)', function () {
    // `exists` controlla ogni elemento di un array, e poi `(int)` di un array non vuoto vale 1: senza `integer` l'incasso
    // si legava alla prima anagrafica del database. Qui la n.1 è un'estranea, creata prima dello scenario.
    $estranea = Anagrafica::create([
        'nome' => 'Anna Estranea', 'email' => 'estranea@test.it', 'indirizzo' => 'Via Roma 1',
        'cap' => '20100', 'citta' => 'Milano', 'provincia' => 'MI', 'codice_fiscale' => 'STRNNA80A41F205X',
    ]);
    $s = scenarioPerContoDi();
    expect($estranea->id)->toBe(1);

    $this->actingAs(utenteDellEstrattoPerConto())
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, ['versato_da_id' => [$s->compratore->id]]))
        ->assertSessionHasErrors('versato_da_id');
    // Sul pagante l'estranea n.1 veniva poi fermata dalla guardia della beta.48, per caso: il rifiuto deve venire dalla
    // regola sull'id, non dal fatto che la n.1 non ha debiti qui.
    $this->actingAs(utenteDellEstrattoPerConto())
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, ['pagante_id' => [$s->venditore->id]]))
        ->assertSessionHasErrors('pagante_id');
    expect(session('errors')->first('pagante_id'))->not->toContain('Non puoi incassare');

    expect(ScritturaContabile::count())->toBe(0)
        ->and(RigaScrittura::where('riferimento_id', $estranea->id)->count())->toBe(0);
});

test('con «Versato da» e un credito della posizione in gioco, senza la scelta dell\'amministratore l\'incasso non si registra (30.7)', function () {
    $s = scenarioPerContoDi();
    $credito = creditoDelVenditore($s, 10000);

    $this->actingAs(utenteDellEstrattoPerConto())
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, [
            'versato_da_id' => $s->compratore->id, 'importo_totale' => 200.00,
            'dettaglio_pagamenti' => [['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00], ['rata_id' => $credito->id, 'importo' => -100.00]],
        ]))
        ->assertSessionHasErrors('credito_prima');

    expect(ScritturaContabile::count())->toBe(0);
});

test('senza «Versato da» la compensazione di sempre non chiede nessuna scelta (30.7)', function () {
    $s = scenarioPerContoDi();
    $credito = creditoDelVenditore($s, 10000);

    $this->actingAs(utenteDellEstrattoPerConto())
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, [
            'importo_totale' => 200.00,
            'dettaglio_pagamenti' => [['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00], ['rata_id' => $credito->id, 'importo' => -100.00]],
        ]))
        ->assertSessionHasNoErrors();

    expect($s->debitoVenditore->refresh()->importo_pagato)->toBe(30000);
});

test('una compensazione a solo credito non chiede la scelta e non scrive nessun «versato da» (30.7, R13)', function () {
    // Senza denaro nessuno ha versato niente: il campo, se arriva, non lascia traccia.
    $s = scenarioPerContoDi();
    $credito = creditoDelVenditore($s, 30000);

    $this->actingAs(utenteDellEstrattoPerConto())
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, [
            'versato_da_id' => $s->compratore->id, 'importo_totale' => 0,
            'dettaglio_pagamenti' => [['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00], ['rata_id' => $credito->id, 'importo' => -300.00]],
        ]))
        ->assertSessionHasNoErrors();

    expect($s->debitoVenditore->refresh()->importo_pagato)->toBe(30000)
        ->and(RigaScrittura::whereNotNull('riferimento_id')->count())->toBe(0)
        ->and(RigaScrittura::where('note', 'like', '%versato da%')->count())->toBe(0);
});

test('«Resta a lui»: prima i soldi versati, e il credito resta al debitore (30.7 a)', function () {
    $s = scenarioPerContoDi();
    $credito = creditoDelVenditore($s, 10000);
    $quotaCompratore = quotaDelCompratore($s, $s->rata5);

    incassaPerConto($s, ['versato_da_id' => $s->compratore->id, 'credito_prima' => false]);

    expect($s->debitoVenditore->refresh()->importo_pagato)->toBe(30000)
        ->and($credito->refresh()->credito_disponibile)->toBe(10000)
        ->and($quotaCompratore->refresh()->importo_pagato)->toBe(0);
});

test('«Resta a lui» vale anche se il modulo manda la riga del credito: il server usa prima i soldi versati (30.7 a)', function () {
    $s = scenarioPerContoDi();
    $credito = creditoDelVenditore($s, 10000);
    $quotaCompratore = quotaDelCompratore($s, $s->rata5);

    incassaPerConto($s, [
        'versato_da_id' => $s->compratore->id, 'credito_prima' => false, 'importo_totale' => 300.00, 'eccedenza' => 100.00,
        'dettaglio_pagamenti' => [['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00], ['rata_id' => $credito->id, 'importo' => -100.00]],
    ]);

    expect($s->debitoVenditore->refresh()->importo_pagato)->toBe(30000)
        ->and($credito->refresh()->credito_disponibile)->toBe(10000)
        ->and($quotaCompratore->refresh()->importo_pagato)->toBe(0);
});

test('«Si usa adesso»: il credito non copre più di quanto la riga chiede, il resto torna al debitore (30.7 b)', function () {
    // Una riga da € 100,00 su un debito di € 300,00, con € 200,00 di credito impegnati: il credito ne copre € 100,00,
    // gli altri tornano al debitore, e la quota non si trova pagata più di quanto l'amministratore ha chiesto.
    $s = scenarioPerContoDi();
    $credito = creditoDelVenditore($s, 20000);
    quotaDelCompratore($s, $s->rata5);

    incassaPerConto($s, [
        'versato_da_id' => $s->compratore->id, 'credito_prima' => true, 'importo_totale' => 50.00, 'eccedenza' => 150.00,
        'dettaglio_pagamenti' => [['rata_id' => $s->debitoVenditore->id, 'importo' => 100.00], ['rata_id' => $credito->id, 'importo' => -200.00]],
    ]);

    expect($s->debitoVenditore->refresh()->importo_pagato)->toBe(10000)
        ->and($credito->refresh()->credito_disponibile)->toBe(10000);
});

test('«Si usa adesso»: prima il credito del debitore, i soldi versati coprono il resto e la parte in più va a chi ha versato (30.7 b)', function () {
    $s = scenarioPerContoDi();
    $credito = creditoDelVenditore($s, 10000);
    $quotaCompratore = quotaDelCompratore($s, $s->rata5);

    incassaPerConto($s, [
        'versato_da_id' => $s->compratore->id, 'credito_prima' => true, 'importo_totale' => 300.00, 'eccedenza' => 100.00,
        'dettaglio_pagamenti' => [['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00], ['rata_id' => $credito->id, 'importo' => -100.00]],
    ]);

    $versatoPerLui = (int) RigaScrittura::where('riferimento_type', Anagrafica::class)->where('riferimento_id', $s->compratore->id)->sum('importo');

    expect($s->debitoVenditore->refresh()->importo_pagato)->toBe(30000)
        ->and($credito->refresh()->credito_disponibile)->toBe(0)
        ->and($versatoPerLui)->toBe(20000)
        ->and($quotaCompratore->refresh()->importo_pagato)->toBe(10000)
        ->and(ScritturaContabile::where('tipo_movimento', 'storno_credito')->count())->toBe(1);
});

test('«Si usa adesso» con un credito che copre tutto il debito: il versamento resta intero a chi l\'ha fatto (30.7 b)', function () {
    $s = scenarioPerContoDi();
    $credito = creditoDelVenditore($s, 40000);
    $quotaCompratore = quotaDelCompratore($s, $s->rata5);

    incassaPerConto($s, [
        'versato_da_id' => $s->compratore->id, 'credito_prima' => true, 'importo_totale' => 300.00, 'eccedenza' => 300.00,
        'dettaglio_pagamenti' => [['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00], ['rata_id' => $credito->id, 'importo' => -300.00]],
    ]);

    // Niente dei suoi soldi è andato al venditore: la riga di cassa non dice «per conto di», e nessuna riga porta il
    // legame — per il riquadro, l'elenco e il registro è un versamento di Lidia Ferri per sé.
    expect($s->debitoVenditore->refresh()->importo_pagato)->toBe(30000)
        ->and($credito->refresh()->credito_disponibile)->toBe(10000)
        ->and($quotaCompratore->refresh()->importo_pagato)->toBe(30000)
        ->and(rigaCassa()->note)->toBe('Versamento rate Lidia Ferri')
        ->and(RigaScrittura::whereNotNull('riferimento_id')->count())->toBe(0)
        // Neanche la riga della parte in più dice «per conto di»: niente è andato al debitore (S14).
        ->and(RigaScrittura::where('anagrafica_id', $s->compratore->id)->where('tipo_riga', 'avere')->value('note'))->toBe('Anticipo / Eccedenza');
});

test('la parte in più va sulla prima rata ancora da pagare di chi ha versato, nella gestione dell\'incasso (30.8)', function () {
    // La regola della Fase 1 prendeva la scadenza più lontana: la parte in più riduceva la rata di luglio mentre quella
    // di maggio restava aperta.
    $s = scenarioPerContoDi();
    $rata7 = Rata::create(['piano_rate_id' => $s->piano->id, 'numero_rata' => 7, 'data_scadenza' => '2026-07-31', 'importo_totale' => 30000, 'stato' => 'emessa']);
    quotaDelCompratore($s, $s->rata1, 30000, 30000);
    $maggio = quotaDelCompratore($s, $s->rata5);
    $luglio = quotaDelCompratore($s, $rata7);

    incassaPerConto($s, ['versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00]);

    expect($maggio->refresh()->importo_pagato)->toBe(5000)
        ->and($luglio->refresh()->importo_pagato)->toBe(0);
});

test('se le sue rate della gestione sono tutte pagate, la parte in più diventa credito sull\'ultima (30.8)', function () {
    $s = scenarioPerContoDi();
    $gennaio = quotaDelCompratore($s, $s->rata1, 30000, 30000);
    $maggio = quotaDelCompratore($s, $s->rata5, 30000, 30000);

    incassaPerConto($s, ['versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00]);

    expect($maggio->refresh()->credito_disponibile)->toBe(5000)
        ->and($gennaio->refresh()->credito_disponibile)->toBe(0);
});

test('con rate solo in un\'altra gestione la parte in più non si sposta da sola; scelta dall\'amministratore, sì, e la nota lo dice (30.8)', function () {
    $s = scenarioPerContoDi();
    $tetto = Gestione::create(['condominio_id' => $s->condominio->id, 'nome' => 'Tetto', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01']);
    $pianoTetto = PianoRate::create(['condominio_id' => $s->condominio->id, 'gestione_id' => $tetto->id, 'nome' => 'Tetto', 'numero_rate' => 1]);
    $rataTetto = Rata::create(['piano_rate_id' => $pianoTetto->id, 'numero_rata' => 1, 'data_scadenza' => '2026-09-30', 'importo_totale' => 50000, 'stato' => 'emessa']);
    $quotaTetto = quotaDelCompratore($s, $rataTetto, 50000);

    expect(fn () => incassaPerConto($s, ['versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00]))
        ->toThrow(\App\Exceptions\Gestionale\RataDellaParteInPiuDaScegliereException::class, 'Lidia Ferri non ha rate nella gestione di questo incasso');
    expect(ScritturaContabile::count())->toBe(0)
        ->and($quotaTetto->refresh()->importo_pagato)->toBe(0);

    incassaPerConto($s, ['versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00, 'quota_parte_in_piu_id' => $quotaTetto->id]);

    $riga = RigaScrittura::where('anagrafica_id', $s->compratore->id)->where('tipo_riga', 'avere')->firstOrFail();
    expect($quotaTetto->refresh()->importo_pagato)->toBe(5000)
        ->and($riga->note)->toContain('gestione Tetto, scelta dall\'amministratore');
});

test('la rata scelta dall\'amministratore vince sulla prima da pagare (30.8)', function () {
    $s = scenarioPerContoDi();
    $rata7 = Rata::create(['piano_rate_id' => $s->piano->id, 'numero_rata' => 7, 'data_scadenza' => '2026-07-31', 'importo_totale' => 30000, 'stato' => 'emessa']);
    $gennaio = quotaDelCompratore($s, $s->rata1);
    $maggio = quotaDelCompratore($s, $s->rata5);
    $luglio = quotaDelCompratore($s, $rata7);

    incassaPerConto($s, ['versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00, 'quota_parte_in_piu_id' => $maggio->id]);

    expect($maggio->refresh()->importo_pagato)->toBe(5000)
        ->and($gennaio->refresh()->importo_pagato)->toBe(0)
        ->and($luglio->refresh()->importo_pagato)->toBe(0);
});

test('la rata della parte in più deve essere di chi ha versato, emessa e di questo condominio (30.8, lente sicurezza)', function () {
    $s = scenarioPerContoDi();
    quotaDelCompratore($s, $s->rata5);
    $bozza = Rata::create(['piano_rate_id' => $s->piano->id, 'numero_rata' => 9, 'data_scadenza' => '2026-09-30', 'importo_totale' => 30000, 'stato' => 'bozza']);
    $inBozza = quotaDelCompratore($s, $bozza);
    $altro = Condominio::create([
        'nome' => 'Altro condominio', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $gestioneAltra = Gestione::create(['condominio_id' => $altro->id, 'nome' => 'Ordinaria', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01']);
    $pianoAltro = PianoRate::create(['condominio_id' => $altro->id, 'gestione_id' => $gestioneAltra->id, 'nome' => 'Piano', 'numero_rate' => 1]);
    $rataAltra = Rata::create(['piano_rate_id' => $pianoAltro->id, 'numero_rata' => 1, 'data_scadenza' => '2026-07-31', 'importo_totale' => 10000, 'stato' => 'emessa']);
    $altrove = quotaDelCompratore($s, $rataAltra, 10000);
    $utente = utenteDellEstrattoPerConto();

    foreach ([$s->debitoVenditore->id, $inBozza->id, $altrove->id] as $quotaId) {
        $this->actingAs($utente)
            ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, [
                'versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00, 'quota_parte_in_piu_id' => $quotaId,
            ]))
            ->assertSessionHasErrors('quota_parte_in_piu_id');
    }

    expect(ScritturaContabile::count())->toBe(0);
});

test('i promemoria delle rate si allineano per ogni persona toccata, anche per chi riceve la parte in più (R3)', function () {
    $s = scenarioPerContoDi();
    $utente = utenteDellEstrattoPerConto();
    $categoria = \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze rate'], ['description' => 'Test', 'color' => '#000000', 'icon' => 'test']);
    $quotaCompratore = quotaDelCompratore($s, $s->rata5);
    // Gennaio è pagata: la parte in più va sulla prima ancora da pagare, maggio (30.8).
    $gennaioCompratore = quotaDelCompratore($s, $s->rata1, 30000, 30000);
    $eventi = app(\App\Services\Gestionale\EventiRataCondomino::class);
    $promemoria = fn (Rata $rata, Anagrafica $chi, $quote) => $eventi->crea($s->piano, $rata, $chi, collect($quote), $s->condominio, $utente->id, 'Ordinaria', $categoria->id);

    $delVenditore = $promemoria($s->rata3, $s->venditore, [$s->debitoVenditore]);
    $delCompratore = $promemoria($s->rata5, $s->compratore, [$quotaCompratore]);
    // Controprova: un promemoria che il condòmino ha segnalato come pagato, su una rata che l'incasso non tocca.
    $segnalato = $promemoria($s->rata1, $s->compratore, [$gennaioCompratore]);
    $segnalato->update(['meta' => array_merge($segnalato->meta, ['status' => 'reported'])]);
    // E la controprova che smaschera il prodotto rate × persone: il venditore ha una quota anche sulla rata di maggio,
    // che l'incasso tocca per il compratore e non per lui. Il suo promemoria segnalato deve restare com'è.
    $maggioVenditore = RataQuote::create([
        'rata_id' => $s->rata5->id, 'anagrafica_id' => $s->venditore->id, 'immobile_id' => $s->immobile->id,
        'importo' => 1000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-05-31',
    ]);
    $segnalatoVenditore = $promemoria($s->rata5, $s->venditore, [$maggioVenditore]);
    $segnalatoVenditore->update(['meta' => array_merge($segnalatoVenditore->meta, ['status' => 'reported'])]);

    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, [
            'versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00,
        ]))
        ->assertSessionHasNoErrors();

    expect($delVenditore->refresh()->meta['status'])->toBe('paid')
        ->and($delCompratore->refresh()->meta['status'])->toBe('partial')
        ->and($delCompratore->meta['importo_pagato'])->toBe(5000)
        ->and($delCompratore->meta['importo_restante'])->toBe(25000)
        ->and($segnalato->refresh()->meta['status'])->toBe('reported')
        ->and($segnalatoVenditore->refresh()->meta['status'])->toBe('reported');
});

test('l\'attività collegata all\'incasso si chiude solo se è di questo condominio (lente sicurezza)', function () {
    $s = scenarioPerContoDi();
    $utente = utenteDellEstrattoPerConto();
    $altro = Condominio::create([
        'nome' => 'Altro condominio', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $attivita = fn (Condominio $c) => \App\Services\Gestionale\InboxService::createTask(
        tipo: \App\Enums\EventoTipo::VERIFICA_PAGAMENTO, title: 'Verifica incasso', description: 'Prova',
        scadenza: now(), createdByUserId: $utente->id, condominioId: $c->id,
    );
    $diUnAltroPalazzo = $attivita($altro);
    $diQuesto = $attivita($s->condominio);

    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, ['related_task_id' => $diUnAltroPalazzo->id]))
        ->assertSessionHasNoErrors();
    expect($diUnAltroPalazzo->refresh()->is_completed)->toBeFalse();

    $s->debitoVenditore->update(['importo' => 60000]);
    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, ['related_task_id' => $diQuesto->id]))
        ->assertSessionHasNoErrors();
    expect($diQuesto->refresh()->is_completed)->toBeTrue();
});

test('chi compare come «chi ha versato» non si elimina, neanche dopo averlo tolto dal condominio (R15)', function () {
    $s = scenarioPerContoDi();
    incassaPerConto($s, ['versato_da_id' => $s->figlio->id]);
    $s->figlio->condomini()->detach();

    $risposta = $this->actingAs(utenteDellEstrattoPerConto())->delete(route('admin.anagrafiche.destroy', ['anagrafica' => $s->figlio->id]));

    expect($risposta->getSession()->get('message')['type'])->toBe('error')
        ->and(Anagrafica::find($s->figlio->id))->not->toBeNull();
});

test('con la parte in più, elenco e dettaglio mostrano la sola posizione e dicono di chi è la parte in più (R6)', function () {
    $s = scenarioPerContoDi();
    quotaDelCompratore($s, $s->rata5);
    incassaPerConto($s, ['versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00]);

    $servizio = app(\App\Services\Gestionale\IncassoRateService::class);
    $riga = $servizio->getIncassiQuery($s->condominio)->get()->map(fn ($m) => $servizio->formatMovimentoForFrontend($m))->sole();
    $rate = collect($riga['dettagli_rate'])->keyBy('numero');

    expect($riga['pagante']['principale'])->toBe('Marco Neri')
        ->and($riga['pagante']['altri_count'])->toBe(0)
        ->and($riga['pagante']['lista_completa'])->toBe('Marco Neri')
        ->and($riga['pagante']['versato_da'])->toBe('Lidia Ferri')
        ->and($riga['anagrafica_id_principale'])->toBe($s->venditore->id)
        ->and($rate[5]['credito_di'])->toBe('Lidia Ferri')
        ->and($rate[5]['tipo'])->toBe('contanti')
        ->and($rate[3]['credito_di'])->toBeNull();
});

test('l\'elenco incassi trova l\'incasso cercando chi ha versato, anche senza parte in più (R7)', function () {
    $s = scenarioPerContoDi();
    incassaPerConto($s, ['versato_da_id' => $s->compratore->id]);

    $servizio = app(\App\Services\Gestionale\IncassoRateService::class);

    expect($servizio->getIncassiQuery($s->condominio, 'Ferri')->count())->toBe(1)
        ->and($servizio->getIncassiQuery($s->condominio, 'Neri')->count())->toBe(1)
        ->and($servizio->getIncassiQuery($s->condominio, 'Paolo')->count())->toBe(0);
});

test('nel registro lo storno di un versamento per conto di altri ha la controparte dell\'incasso (R8)', function () {
    // Lo storno è l'annullamento di una registrazione, non una restituzione: deve dire chi ha versato come l'incasso.
    $s = scenarioPerContoDi();
    incassaPerConto($s, ['versato_da_id' => $s->compratore->id]);
    app(StornoIncassoRateAction::class)->execute(ScritturaContabile::where('tipo_movimento', 'incasso_rata')->firstOrFail(), $s->condominio);

    $servizio = app(\App\Services\Gestionale\RegistroContabilitaService::class);
    $crediti = ContoContabile::where('ruolo', 'crediti_condomini')->firstOrFail();

    expect(collect($servizio->registro($s->esercizio))->pluck('controparte')->all())
        ->toBe(['Lidia Ferri per conto di Marco Neri', 'Lidia Ferri per conto di Marco Neri'])
        ->and(collect($servizio->mastrino($s->esercizio, $crediti)['righe'])->pluck('controparte')->unique()->values()->all())
        ->toBe(['Lidia Ferri per conto di Marco Neri']);
});

test('lo storno della compensazione resta del debitore: il credito era suo (R8)', function () {
    $s = scenarioPerContoDi();
    $credito = creditoDelVenditore($s, 10000);
    incassaPerConto($s, [
        'versato_da_id' => $s->compratore->id, 'importo_totale' => 200.00,
        'dettaglio_pagamenti' => [['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00], ['rata_id' => $credito->id, 'importo' => -100.00]],
    ]);
    app(StornoIncassoRateAction::class)->execute(ScritturaContabile::where('tipo_movimento', 'incasso_rata')->firstOrFail(), $s->condominio);

    $crediti = ContoContabile::where('ruolo', 'crediti_condomini')->firstOrFail();
    $righe = collect(app(\App\Services\Gestionale\RegistroContabilitaService::class)->mastrino($s->esercizio, $crediti)['righe']);
    $compensazioneERettifica = $righe->filter(fn ($r) => ! str_contains((string) $r['controparte'], 'per conto di'));

    expect($compensazioneERettifica->pluck('controparte')->unique()->values()->all())->toBe(['Marco Neri'])
        ->and($compensazioneERettifica)->toHaveCount(4)
        ->and($righe->filter(fn ($r) => str_contains((string) $r['controparte'], 'per conto di')))->toHaveCount(2);
});

test('padre e figlio con lo stesso nome: nel registro il «per conto di» resta (R16)', function () {
    $s = scenarioPerContoDi();
    $omonimo = Anagrafica::create([
        'nome' => 'Marco Neri', 'email' => 'marco.neri.padre@test.it', 'indirizzo' => 'Via dei Tigli 3',
        'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'NRIMRC50A01H501Z',
    ]);
    $omonimo->condomini()->attach($s->condominio->id);

    incassaPerConto($s, ['versato_da_id' => $omonimo->id]);

    expect(collect(app(\App\Services\Gestionale\RegistroContabilitaService::class)->registro($s->esercizio))->pluck('controparte')->all())
        ->toBe(['Marco Neri per conto di Marco Neri']);
});

test('il dettaglio dell\'incasso non manda le anagrafiche intere, nemmeno con la parte in più (R12, S12)', function () {
    // Con la parte in più chi ha versato ha anche una riga intestata a sé, e l'anagrafica di quella riga partiva intera.
    $s = scenarioPerContoDi();
    quotaDelCompratore($s, $s->rata5);
    incassaPerConto($s, ['versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00]);
    $incasso = ScritturaContabile::where('tipo_movimento', 'incasso_rata')->firstOrFail();

    $props = $this->actingAs(utenteDellEstrattoPerConto())
        ->get("/admin/gestionale/{$s->condominio->id}/movimenti-rate/{$incasso->id}")
        ->assertOk()->viewData('page')['props'];

    expect(array_keys($props['incasso']))->toEqualCanonicalizing(['id', 'numero_protocollo', 'stato', 'causale', 'data_competenza', 'updated_at'])
        ->and($props['incassoFormatted']['pagante']['versato_da'])->toBe('Lidia Ferri')
        ->and(json_encode($props))->not->toContain('FRRLDI80A41H501K')
        ->and(json_encode($props))->not->toContain('NRIMRC80A01H501U');
});

test('il modulo riceve le rate emesse di chi ha versato, per scegliere dove va la parte in più (30.8)', function () {
    $s = scenarioPerContoDi();
    $gennaio = quotaDelCompratore($s, $s->rata1, 30000, 30000);
    $maggio = quotaDelCompratore($s, $s->rata5, 30000, 10000);
    $bozza = Rata::create(['piano_rate_id' => $s->piano->id, 'numero_rata' => 9, 'data_scadenza' => '2026-09-30', 'importo_totale' => 30000, 'stato' => 'bozza']);
    quotaDelCompratore($s, $bozza);
    $altro = Condominio::create([
        'nome' => 'Altro condominio', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $gestioneAltra = Gestione::create(['condominio_id' => $altro->id, 'nome' => 'Ordinaria', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01']);
    $pianoAltro = PianoRate::create(['condominio_id' => $altro->id, 'gestione_id' => $gestioneAltra->id, 'nome' => 'Piano', 'numero_rate' => 1]);
    $rataAltra = Rata::create(['piano_rate_id' => $pianoAltro->id, 'numero_rata' => 1, 'data_scadenza' => '2026-07-31', 'importo_totale' => 10000, 'stato' => 'emessa']);
    quotaDelCompratore($s, $rataAltra, 10000);

    $rate = $this->actingAs(utenteDellEstrattoPerConto())
        ->getJson(route('admin.gestionale.rate-di-chi-ha-versato', ['condominio' => $s->condominio->id, 'anagrafica_id' => $s->compratore->id]))
        ->assertOk()->json('rate');

    expect(collect($rate)->pluck('id')->all())->toBe([$gennaio->id, $maggio->id])
        ->and($rate[0]['pagata'])->toBeTrue()
        ->and($rate[1]['pagata'])->toBeFalse()
        ->and($rate[1]['residuo'])->toEqual(200)
        ->and($rate[1]['gestione_id'])->toBe($s->gestione->id)
        ->and($rate[1]['gestione'])->toBe('Ordinaria')
        ->and(array_keys($rate[1]))->toEqualCanonicalizing(['id', 'numero_rata', 'gestione_id', 'gestione', 'scadenza', 'residuo', 'pagata']);
});

test('registro e mastrino danno anche chi ha versato e il debitore separati, per la cella a due righe (R9)', function () {
    // La controparte intera resta per il PDF e per la ricerca; a schermo la cella la spezza, perché tagliata coi puntini
    // perdeva proprio il nome del debitore.
    $s = scenarioPerContoDi();
    incassaPerConto($s, ['versato_da_id' => $s->compratore->id]);
    $servizio = app(\App\Services\Gestionale\RegistroContabilitaService::class);
    $crediti = ContoContabile::where('ruolo', 'crediti_condomini')->firstOrFail();

    $riga = collect($servizio->registro($s->esercizio))->sole();
    $rigaMastrino = collect($servizio->mastrino($s->esercizio, $crediti)['righe'])->sole();

    expect($riga['controparte'])->toBe('Lidia Ferri per conto di Marco Neri')
        ->and($riga['versato_da'])->toBe('Lidia Ferri')
        ->and($riga['per_conto_di'])->toBe('Marco Neri')
        ->and($rigaMastrino['versato_da'])->toBe('Lidia Ferri')
        ->and($rigaMastrino['per_conto_di'])->toBe('Marco Neri');
});

test('senza «Versato da» i due campi della cella restano vuoti (R9)', function () {
    $s = scenarioPerContoDi();
    incassaPerConto($s, []);

    $riga = collect(app(\App\Services\Gestionale\RegistroContabilitaService::class)->registro($s->esercizio))->sole();

    expect($riga['versato_da'])->toBeNull()
        ->and($riga['per_conto_di'])->toBeNull();
});

/** L'attività globale «verifica incassi» di una rata, come la crea l'emissione. */
function verificaIncassi(object $s, Rata $rata, int $utenteId): \App\Models\Evento
{
    return \App\Services\Gestionale\InboxService::createTask(
        tipo: \App\Enums\EventoTipo::CONTROLLO_INCASSI, title: "Verifica incassi rata {$rata->numero_rata}", description: 'Prova',
        scadenza: now(), createdByUserId: $utenteId, condominioId: $s->condominio->id, context: ['rata_id' => $rata->id],
    );
}

test('la verifica incassi si chiude solo se ogni quota della rata è pagata, non se la somma torna (S7)', function () {
    // La parte in più di Lidia sulla sua rata 5, già pagata, faceva tornare la somma della rata mentre Sara doveva ancora.
    $s = scenarioPerContoDi();
    $utente = utenteDellEstrattoPerConto();
    $sara = Anagrafica::create(['nome' => 'Sara Blu', 'email' => 'sara@test.it', 'indirizzo' => 'Via dei Tigli 3', 'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'BLUSRA80A41H501Q']);
    quotaDelCompratore($s, $s->rata5, 30000, 30000);
    RataQuote::create(['rata_id' => $s->rata5->id, 'anagrafica_id' => $sara->id, 'importo' => 3000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-05-31']);
    $verifica5 = verificaIncassi($s, $s->rata5, $utente->id);

    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, [
            'versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00,
        ]))
        ->assertSessionHasNoErrors();

    expect($verifica5->refresh()->is_completed)->toBeFalse();
});

test('il gemello senza «Versato da»: un credito su una rata non chiude la verifica se un altro deve ancora (S7)', function () {
    $s = scenarioPerContoDi();
    $utente = utenteDellEstrattoPerConto();
    $sara = Anagrafica::create(['nome' => 'Sara Blu', 'email' => 'sara@test.it', 'indirizzo' => 'Via dei Tigli 3', 'cap' => '00100', 'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'BLUSRA80A41H501Q']);
    RataQuote::create(['rata_id' => $s->rata3->id, 'anagrafica_id' => $sara->id, 'importo' => 3000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-03-31']);
    $verifica3 = verificaIncassi($s, $s->rata3, $utente->id);

    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, ['importo_totale' => 330.00, 'eccedenza' => 30.00]))
        ->assertSessionHasNoErrors();

    expect($verifica3->refresh()->is_completed)->toBeFalse();
});

test('con ogni quota positiva pagata la verifica si chiude, anche se nella rata c\'è un credito (S7, controprova)', function () {
    $s = scenarioPerContoDi();
    $utente = utenteDellEstrattoPerConto();
    RataQuote::create(['rata_id' => $s->rata3->id, 'anagrafica_id' => $s->compratore->id, 'importo' => -2000, 'importo_pagato' => 0, 'stato' => 'pagata', 'data_scadenza' => '2026-03-31']);
    $verifica3 = verificaIncassi($s, $s->rata3, $utente->id);

    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s))
        ->assertSessionHasNoErrors();

    expect($verifica3->refresh()->is_completed)->toBeTrue();
});

test('lo storno riallinea i promemoria delle sole persone che l\'incasso aveva toccato, e non riapre una rata che resta saldata (S8)', function () {
    $s = scenarioPerContoDi();
    $utente = utenteDellEstrattoPerConto();
    $categoria = \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze rate'], ['description' => 'Test', 'color' => '#000000', 'icon' => 'test']);
    $maggioCompratore = quotaDelCompratore($s, $s->rata5, 30000, 30000);
    $maggioVenditore = RataQuote::create([
        'rata_id' => $s->rata5->id, 'anagrafica_id' => $s->venditore->id, 'immobile_id' => $s->immobile->id,
        'importo' => 1000, 'importo_pagato' => 1000, 'stato' => 'pagata', 'data_scadenza' => '2026-05-31',
    ]);
    $eventi = app(\App\Services\Gestionale\EventiRataCondomino::class);
    $promemoria = fn (Rata $rata, Anagrafica $chi, $quote) => $eventi->crea($s->piano, $rata, $chi, collect($quote), $s->condominio, $utente->id, 'Ordinaria', $categoria->id);
    $delVenditore = $promemoria($s->rata3, $s->venditore, [$s->debitoVenditore]);
    // Il venditore ha segnalato dal portale la sua quota di maggio: l'incasso non la tocca, lo storno nemmeno.
    $segnalatoVenditore = $promemoria($s->rata5, $s->venditore, [$maggioVenditore]);
    $segnalatoVenditore->update(['meta' => array_merge($segnalatoVenditore->meta, ['status' => 'reported'])]);
    $verifica5 = verificaIncassi($s, $s->rata5, $utente->id);
    $verifica5->update(['is_completed' => true, 'completed_at' => now()]);

    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, [
            'versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00,
        ]))
        ->assertSessionHasNoErrors();
    $incasso = ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();

    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate/{$incasso->id}/storno")
        ->assertSessionHasNoErrors();

    expect($delVenditore->refresh()->meta['status'])->toBe('pending')
        ->and($segnalatoVenditore->refresh()->meta['status'])->toBe('reported')
        ->and($maggioCompratore->refresh()->importo_pagato)->toBe(30000)
        ->and($verifica5->refresh()->is_completed)->toBeTrue();
});

test('se la rata scelta per la parte in più non è più valida l\'incasso si ferma, non ripiega in silenzio sulla proposta (S9)', function () {
    $s = scenarioPerContoDi();
    $maggio = quotaDelCompratore($s, $s->rata5);

    // La scelta è validata nella richiesta; qui arriva una quota che non è di chi ha versato, come se fosse cambiata fra
    // la validazione e la transazione.
    expect(fn () => incassaPerConto($s, [
        'versato_da_id' => $s->compratore->id, 'importo_totale' => 350.00, 'eccedenza' => 50.00,
        'quota_parte_in_piu_id' => $s->debitoVenditore->id,
    ]))->toThrow(\App\Exceptions\Gestionale\RataDellaParteInPiuDaScegliereException::class, 'non è più disponibile');

    expect($maggio->refresh()->importo_pagato)->toBe(0)
        ->and(ScritturaContabile::count())->toBe(0);
});

test('la rotta delle rate di chi ha versato rifiuta un id che non sia un numero (S13)', function () {
    $s = scenarioPerContoDi();

    $this->actingAs(utenteDellEstrattoPerConto())
        ->getJson(route('admin.gestionale.rate-di-chi-ha-versato', ['condominio' => $s->condominio->id]) . '?anagrafica_id[]=' . $s->compratore->id)
        ->assertStatus(422);
});

/** Una seconda gestione con una rata emessa e la quota del venditore su di essa. */
function gestioneTetto(object $s, int $debitoVenditore = 30000): object
{
    $tetto = Gestione::create(['condominio_id' => $s->condominio->id, 'nome' => 'Tetto', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01']);
    $tetto->esercizi()->attach($s->esercizio->id, ['attiva' => true]);
    $piano = PianoRate::create(['condominio_id' => $s->condominio->id, 'gestione_id' => $tetto->id, 'nome' => 'Tetto', 'numero_rate' => 1]);
    $rata = Rata::create(['piano_rate_id' => $piano->id, 'numero_rata' => 1, 'data_scadenza' => '2026-06-30', 'importo_totale' => $debitoVenditore, 'stato' => 'emessa']);
    $quota = RataQuote::create([
        'rata_id' => $rata->id, 'anagrafica_id' => $s->venditore->id, 'immobile_id' => $s->immobile->id,
        'importo' => $debitoVenditore, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-06-30',
    ]);

    return (object) compact('tetto', 'piano', 'rata', 'quota');
}

test('«si usa adesso» con debiti di due gestioni: senza la scelta fra gestioni l\'incasso si ferma (30.11)', function () {
    // Ugo deve € 50 sull'ordinaria (qui € 300 ridotti a € 50) e € 300 sul tetto, con € 100 di credito ordinario; Elsa
    // versa € 300. Il credito dell'ordinaria passerebbe al tetto: lo decide l'amministratore, non il programma.
    $s = scenarioPerContoDi();
    $s->debitoVenditore->update(['importo' => 5000]);
    $t = gestioneTetto($s);
    $credito = creditoDelVenditore($s, 10000);
    quotaDelCompratore($s, $s->rata5);

    expect(fn () => incassaPerConto($s, [
        'versato_da_id' => $s->compratore->id, 'credito_prima' => true, 'importo_totale' => 300.00, 'eccedenza' => 50.00,
        'gestione_id' => $s->gestione->id,
        'dettaglio_pagamenti' => [
            ['rata_id' => $credito->id, 'importo' => -100.00],
            ['rata_id' => $s->debitoVenditore->id, 'importo' => 50.00],
            ['rata_id' => $t->quota->id, 'importo' => 300.00],
        ],
    ]))->toThrow(\App\Exceptions\Gestionale\CreditoFraGestioniDaScegliereException::class, 'Tetto');

    expect(ScritturaContabile::count())->toBe(0);
});

test('«solo sulla sua gestione»: il credito copre l\'ordinaria, il resto resta a Ugo, nessun travaso (30.11)', function () {
    $s = scenarioPerContoDi();
    $s->debitoVenditore->update(['importo' => 5000]);
    $t = gestioneTetto($s);
    $credito = creditoDelVenditore($s, 10000);
    $quotaElsa = quotaDelCompratore($s, $s->rata5);

    incassaPerConto($s, [
        'versato_da_id' => $s->compratore->id, 'credito_prima' => true, 'credito_fra_gestioni' => false,
        'importo_totale' => 300.00, 'eccedenza' => 0, 'gestione_id' => $s->gestione->id,
        'dettaglio_pagamenti' => [
            ['rata_id' => $credito->id, 'importo' => -50.00],
            ['rata_id' => $s->debitoVenditore->id, 'importo' => 50.00],
            ['rata_id' => $t->quota->id, 'importo' => 300.00],
        ],
    ]);

    expect($s->debitoVenditore->refresh()->importo_pagato)->toBe(5000)
        ->and($t->quota->refresh()->importo_pagato)->toBe(30000)
        ->and($credito->refresh()->credito_disponibile)->toBe(5000)
        ->and($quotaElsa->refresh()->importo_pagato)->toBe(0)
        ->and(RigaScrittura::where('note', 'like', '%cross-gestione%')->count())->toBe(0);
});

test('«anche sulle altre gestioni»: il credito passa al tetto, la nota lo dice e la parte in più va a Elsa (30.11)', function () {
    $s = scenarioPerContoDi();
    $s->debitoVenditore->update(['importo' => 5000]);
    $t = gestioneTetto($s);
    $credito = creditoDelVenditore($s, 10000);
    $quotaElsa = quotaDelCompratore($s, $s->rata5);

    incassaPerConto($s, [
        'versato_da_id' => $s->compratore->id, 'credito_prima' => true, 'credito_fra_gestioni' => true,
        'importo_totale' => 300.00, 'eccedenza' => 50.00, 'gestione_id' => $s->gestione->id,
        'dettaglio_pagamenti' => [
            ['rata_id' => $credito->id, 'importo' => -100.00],
            ['rata_id' => $s->debitoVenditore->id, 'importo' => 50.00],
            ['rata_id' => $t->quota->id, 'importo' => 300.00],
        ],
    ]);

    expect($t->quota->refresh()->importo_pagato)->toBe(30000)
        ->and($credito->refresh()->credito_disponibile)->toBe(0)
        ->and($quotaElsa->refresh()->importo_pagato)->toBe(5000)
        ->and(RigaScrittura::where('note', 'like', '%cross-gestione confermata%')->count())->toBe(1);
});

test('anche senza «Versato da» il credito di una gestione non copre lo scoperto di un\'altra senza la scelta (30.11, motore di sempre)', function () {
    // Il caso del motore di sempre: prima i soldi, il credito sullo scoperto — che qui è sul tetto.
    $s = scenarioPerContoDi();
    $s->debitoVenditore->update(['importo' => 5000]);
    $t = gestioneTetto($s);
    $credito = creditoDelVenditore($s, 10000);

    $dati = [
        'importo_totale' => 250.00, 'eccedenza' => 0, 'gestione_id' => $s->gestione->id,
        'dettaglio_pagamenti' => [
            ['rata_id' => $credito->id, 'importo' => -100.00],
            ['rata_id' => $s->debitoVenditore->id, 'importo' => 50.00],
            ['rata_id' => $t->quota->id, 'importo' => 300.00],
        ],
    ];

    expect(fn () => incassaPerConto($s, $dati))->toThrow(\App\Exceptions\Gestionale\CreditoFraGestioniDaScegliereException::class);

    incassaPerConto($s, $dati + ['credito_fra_gestioni' => true]);
    expect($t->quota->refresh()->importo_pagato)->toBe(30000)
        ->and($s->debitoVenditore->refresh()->importo_pagato)->toBe(5000)
        ->and($credito->refresh()->credito_disponibile)->toBe(0);
});

test('un incasso su rate di due gestioni senza la gestione scelta si ferma; scelta, è quella (30.12)', function () {
    $s = scenarioPerContoDi();
    $t = gestioneTetto($s);
    $dati = [
        'importo_totale' => 600.00, 'gestione_id' => null,
        'dettaglio_pagamenti' => [
            ['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00],
            ['rata_id' => $t->quota->id, 'importo' => 300.00],
        ],
    ];

    expect(fn () => incassaPerConto($s, $dati))->toThrow(\App\Exceptions\Gestionale\GestioneIncassoDaScegliereException::class, 'Tetto');
    expect(ScritturaContabile::count())->toBe(0);

    incassaPerConto($s, ['gestione_id' => $t->tetto->id] + $dati);
    expect(ScritturaContabile::where('tipo_movimento', 'incasso_rata')->sole()->gestione_id)->toBe($t->tetto->id);
});

test('la gestione dell\'incasso deve essere una di quelle delle rate pagate (30.12)', function () {
    $s = scenarioPerContoDi();
    $t = gestioneTetto($s);

    expect(fn () => incassaPerConto($s, ['gestione_id' => $t->tetto->id]))
        ->toThrow(\App\Exceptions\Gestionale\GestioneIncassoDaScegliereException::class, 'non è fra');
});

test('con una gestione sola, senza filtro, la gestione dell\'incasso è quella delle rate (30.12)', function () {
    // Si paga solo il tetto, che NON è la prima gestione dell'esercizio: il ripiego sulla prima gestione darebbe
    // l'ordinaria.
    $s = scenarioPerContoDi();
    $t = gestioneTetto($s);

    incassaPerConto($s, ['gestione_id' => null, 'dettaglio_pagamenti' => [['rata_id' => $t->quota->id, 'importo' => 300.00]]]);

    expect(ScritturaContabile::where('tipo_movimento', 'incasso_rata')->sole()->gestione_id)->toBe($t->tetto->id);
});

/** Un credito del venditore nel tetto: una quota negativa su una rata del tetto più vecchia di quella da pagare. */
function creditoNelTetto(object $s, object $t, int $cents): RataQuote
{
    $rata = Rata::create(['piano_rate_id' => $t->piano->id, 'numero_rata' => 0, 'data_scadenza' => '2026-01-31', 'importo_totale' => 0, 'stato' => 'emessa']);

    return RataQuote::create([
        'rata_id' => $rata->id, 'anagrafica_id' => $s->venditore->id, 'immobile_id' => $s->immobile->id,
        'importo' => -$cents, 'importo_pagato' => 0, 'stato' => 'pagata', 'data_scadenza' => '2026-01-31',
    ]);
}

test('le rate coperte dal credito contano per la gestione dell\'incasso: denaro sull\'ordinaria e credito sul tetto chiedono la scelta (30.12, T4)', function () {
    // Ugo deve € 300 all'ordinaria e € 100 al tetto, dove ha anche € 100 di credito; versa € 300, senza filtro. Il
    // denaro paga solo l'ordinaria, ma la gestione dell'incasso intesta anche la compensazione del tetto: si chiede.
    $s = scenarioPerContoDi();
    $t = gestioneTetto($s, 10000);
    $credito = creditoNelTetto($s, $t, 10000);
    $dati = [
        'importo_totale' => 300.00, 'eccedenza' => 0, 'gestione_id' => null,
        'dettaglio_pagamenti' => [
            ['rata_id' => $credito->id, 'importo' => -100.00],
            ['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00],
            ['rata_id' => $t->quota->id, 'importo' => 100.00],
        ],
    ];

    expect(fn () => incassaPerConto($s, $dati))->toThrow(\App\Exceptions\Gestionale\GestioneIncassoDaScegliereException::class, 'Tetto');
    expect(ScritturaContabile::count())->toBe(0);

    incassaPerConto($s, ['gestione_id' => $t->tetto->id] + $dati);
    expect(ScritturaContabile::where('tipo_movimento', 'incasso_rata')->sole()->gestione_id)->toBe($t->tetto->id)
        ->and(ScritturaContabile::where('tipo_movimento', 'storno_credito')->sole()->gestione_id)->toBe($t->tetto->id)
        ->and($s->debitoVenditore->refresh()->importo_pagato)->toBe(30000)
        ->and($t->quota->refresh()->importo_pagato)->toBe(10000)
        ->and($credito->refresh()->credito_disponibile)->toBe(0);
});

test('una compensazione pura sul solo tetto, senza filtro, va al tetto e non alla prima gestione dell\'esercizio (30.12, T4)', function () {
    $s = scenarioPerContoDi();
    $t = gestioneTetto($s, 10000);
    $credito = creditoNelTetto($s, $t, 10000);

    incassaPerConto($s, [
        'importo_totale' => 0, 'eccedenza' => 0, 'gestione_id' => null,
        'dettaglio_pagamenti' => [
            ['rata_id' => $credito->id, 'importo' => -100.00],
            ['rata_id' => $t->quota->id, 'importo' => 100.00],
        ],
    ]);

    expect(ScritturaContabile::count())->toBeGreaterThan(0)
        ->and(ScritturaContabile::where('gestione_id', '!=', $t->tetto->id)->count())->toBe(0)
        ->and(ScritturaContabile::where('tipo_movimento', 'storno_credito')->sole()->gestione_id)->toBe($t->tetto->id)
        ->and($t->quota->refresh()->importo_pagato)->toBe(10000);
});

test('i promemoria non si toccano sulla rata da cui viene il credito: la quota di credito non è una coppia (R3)', function () {
    $s = scenarioPerContoDi();
    $utente = utenteDellEstrattoPerConto();
    $categoria = \App\Models\CategoriaEvento::firstOrCreate(['name' => 'Scadenze rate'], ['description' => 'Test', 'color' => '#000000', 'icon' => 'test']);
    $credito = creditoDelVenditore($s, 10000);
    // Sulla rata 1 Ugo ha anche una quota da pagare, e l'ha segnalata come pagata dal portale.
    $apertaRata1 = RataQuote::create([
        'rata_id' => $s->rata1->id, 'anagrafica_id' => $s->venditore->id, 'immobile_id' => $s->immobile->id,
        'importo' => 5000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => '2026-01-31',
    ]);
    $segnalato = app(\App\Services\Gestionale\EventiRataCondomino::class)
        ->crea($s->piano, $s->rata1, $s->venditore, collect([$apertaRata1]), $s->condominio, $utente->id, 'Ordinaria', $categoria->id);
    $segnalato->update(['meta' => array_merge($segnalato->meta, ['status' => 'reported'])]);

    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, [
            'importo_totale' => 200.00,
            'dettaglio_pagamenti' => [['rata_id' => $s->debitoVenditore->id, 'importo' => 300.00], ['rata_id' => $credito->id, 'importo' => -100.00]],
        ]))
        ->assertSessionHasNoErrors();

    expect($credito->refresh()->credito_disponibile)->toBe(0)
        ->and($segnalato->refresh()->meta['status'])->toBe('reported');
});

test('gestione, cassa e rate mandate come elenco sono un errore, non la gestione n.1 di un altro palazzo (T2, Coda 208)', function () {
    // Il condominio estraneo nasce per primo: la sua gestione è la n.1, e `(int)` di un elenco vale 1.
    $estraneo = Condominio::create([
        'nome' => 'Altro condominio', 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);
    $gestioneEstranea = Gestione::create(['condominio_id' => $estraneo->id, 'nome' => 'Ordinaria estranea', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01']);
    $s = scenarioPerContoDi();
    expect($gestioneEstranea->id)->toBe(1);
    $utente = utenteDellEstrattoPerConto();
    $credito = creditoDelVenditore($s, 10000);

    // Solo credito, come nel reperto: la validazione passava e le scritture finivano sulla gestione n.1.
    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, [
            'gestione_id' => [$s->gestione->id], 'importo_totale' => 0, 'eccedenza' => 0,
            'dettaglio_pagamenti' => [['rata_id' => $s->debitoVenditore->id, 'importo' => 100.00], ['rata_id' => $credito->id, 'importo' => -100.00]],
        ]))
        ->assertSessionHasErrors('gestione_id');
    expect(session('errors')->first('gestione_id'))->not->toContain('non è fra');

    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, ['cassa_id' => [$s->cassa->id]]))
        ->assertSessionHasErrors('cassa_id');
    $this->actingAs($utente)
        ->post("/admin/gestionale/{$s->condominio->id}/movimenti-rate", datiIncassoPerConto($s, [
            'dettaglio_pagamenti' => [['rata_id' => [$s->debitoVenditore->id], 'importo' => 300.00]],
        ]))
        ->assertSessionHasErrors('dettaglio_pagamenti.0.rata_id');

    expect(ScritturaContabile::count())->toBe(0);
});
