<?php

use App\Services\Treasury\TreasuryGuardianService;
use App\Services\Treasury\TreasuryTimelineBuilder;
use App\Support\Treasury\TreasuryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\Condominio;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    
    // Inseriamo un conto contabile di tipo liquidità
    $this->contoLiquiditaId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'nome' => 'Banca',
        'categoria' => 'liquidita',
        'tipo' => 'attivo',
        'codice' => '1001',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Aggiungiamo un fornitore finto per le query
    $this->fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Enel',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Gestione ed esercizio finti per poter creare piani rate
    $this->esercizioId = DB::table('esercizi')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'nome' => '2024',
        'data_inizio' => now()->startOfYear(),
        'data_fine' => now()->endOfYear(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->gestioneId = DB::table('gestioni')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'nome' => 'Ordinaria',
        'attiva' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->pianoRateId = DB::table('piani_rate')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'gestione_id' => $this->gestioneId,
        'nome' => 'Piano Base',
        'tipo' => 'ordinario',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->service = new TreasuryGuardianService(new TreasuryTimelineBuilder(), app(\App\Services\Gestionale\Debiti\LetturaDebiti::class), app(\App\Services\Gestionale\CreditoService::class));
});

/**
 * Helper per inserire liquidità iniziale in modo veloce
 */
function assegnaLiquidita($condominioId, $contoId, $importoCents, $gestioneId) {
    global $testEsercizioId;
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $condominioId,
        'gestione_id' => $gestioneId,
        'esercizio_id' => 1, // hardcoded from setup
        'data_registrazione' => now(),
        'data_competenza' => now(),
        'numero_protocollo' => 'PROT-' . rand(1000, 9999),
        'causale' => 'Versamento iniziale test',
        'tipo_movimento' => 'versamento',
        'stato' => 'registrata'
    ]);
    
    DB::table('righe_scritture')->insert([
        'scrittura_id' => $scritturaId,
        'conto_contabile_id' => $contoId,
        'tipo_riga' => 'dare', // Dare su un conto liquidità aumenta il saldo
        'importo' => $importoCents,
    ]);
}

/**
 * Helper per creare una fattura passiva
 */
function creaFattura(
    $condominioId, $fornitoreId, $esercizioId, $importoCents, $pagatoCents = 0, $statoPag = 'aperta', 
    $statoAppr = 'approvata', $scadenza = null, $isPregresso = false
) {
    $fatturaId = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $condominioId,
        'esercizio_id' => $esercizioId,
        'fornitore_id' => $fornitoreId,
        'tipo_documento' => 'fattura',
        'numero_documento' => 'FATT-' . rand(1000, 9999),
        'netto_a_pagare' => $importoCents,
        'totale_documento' => $importoCents,
        'importo_imponibile' => $importoCents,
        'importo_iva' => 0,
        'data_scadenza' => $scadenza ?? now(),
        'stato_pagamento' => $statoPag,
        'stato_approvazione' => $statoAppr,
        'is_pregresso' => $isPregresso,
        'data_documento' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($pagatoCents > 0) {
        $scritturaId = DB::table('scritture_contabili')->insertGetId([
            'condominio_id' => $condominioId,
            'gestione_id' => 1,
            'esercizio_id' => 1,
            'data_registrazione' => now(),
            'data_competenza' => now(),
            'numero_protocollo' => 'PROT-' . rand(1000, 9999),
            'causale' => 'Pagamento fornitore',
            'tipo_movimento' => 'pagamento_fornitore',
            'stato' => 'registrata'
        ]);
        
        DB::table('fattura_scrittura')->insert([
            'fattura_passiva_id' => $fatturaId,
            'scrittura_contabile_id' => $scritturaId,
            'importo_allocato' => $pagatoCents,
            'tipo' => 'pagamento'
        ]);
    }

    return $fatturaId;
}

/**
 * Helper per creare una rata emessa.
 *
 * Dalla 1.12.0-beta.3 gli incassi attesi vengono dalla lettura comune dei debiti, che legge le quote e il giornale come li scrive il
 * programma: una persona con un'unità, la sua quota sulla rata, la scrittura di emissione (DARE «Crediti verso condomini» a nome della
 * persona, con la rata) e, se c'è un pagato, l'incasso (AVERE sullo stesso conto). Prima bastava `rate.importo_totale`, che la lettura
 * non guarda.
 */
function creaRata($pianoRateId, $importoCents, $pagatoCents = 0, $stato = 'emessa', $scadenza = null) {
    $piano = DB::table('piani_rate')->where('id', $pianoRateId)->first();
    $esercizioId = (int) DB::table('esercizi')->where('condominio_id', $piano->condominio_id)->value('id');
    $crediti = DB::table('conti_contabili')->where('condominio_id', $piano->condominio_id)->where('ruolo', 'crediti_condomini')->value('id')
        ?? DB::table('conti_contabili')->insertGetId(['condominio_id' => $piano->condominio_id, 'nome' => 'Crediti verso condomini', 'categoria' => 'crediti',
            'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'codice' => '1201', 'created_at' => now(), 'updated_at' => now()]);
    $n = DB::table('rate')->count() + 1;
    $persona = \App\Models\Anagrafica::forceCreate(['nome' => "Condomino {$n}", 'email' => "tgs-{$n}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'TGSCND' . str_pad((string) $n, 10, '0', STR_PAD_LEFT)]);
    $immobile = \App\Models\Immobile::create(['condominio_id' => $piano->condominio_id, 'tipo' => 'appartamento', 'codice_immobile' => "TGS-{$n}", 'nome' => "Interno {$n}", 'interno' => (string) $n]);

    $rataId = DB::table('rate')->insertGetId([
        'piano_rate_id' => $pianoRateId,
        'importo_totale' => $importoCents,
        'numero_rata' => rand(1, 10),
        'stato' => $stato,
        'data_scadenza' => $scadenza,
        'data_emissione' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $scrittura = function (string $tipo) use ($piano, $esercizioId) {
        return DB::table('scritture_contabili')->insertGetId([
            'condominio_id' => $piano->condominio_id, 'gestione_id' => $piano->gestione_id, 'esercizio_id' => $esercizioId,
            'data_registrazione' => now()->toDateString(), 'data_competenza' => now()->toDateString(), 'numero_protocollo' => 'PROT-' . rand(1000, 99999),
            'causale' => $tipo, 'tipo_movimento' => $tipo, 'stato' => 'registrata',
        ]);
    };
    $riga = fn (int $scritturaId, string $tipo, int $importo) => DB::table('righe_scritture')->insert([
        'scrittura_id' => $scritturaId, 'conto_contabile_id' => $crediti, 'anagrafica_id' => $persona->id, 'immobile_id' => $immobile->id, 'rata_id' => $rataId,
        'tipo_riga' => $tipo, 'importo' => $importo,
    ]);

    $emissione = null;
    if ($stato === 'emessa') {
        $emissione = $scrittura('emissione_rata');
        $riga($emissione, 'dare', $importoCents);
    }
    DB::table('rate_quote')->insert([
        'rata_id' => $rataId, 'anagrafica_id' => $persona->id, 'immobile_id' => $immobile->id, 'importo' => $importoCents, 'importo_pagato' => $pagatoCents,
        'stato' => $pagatoCents > 0 ? 'parzialmente_pagata' : 'da_pagare', 'data_scadenza' => $scadenza, 'scrittura_contabile_id' => $emissione,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    if ($pagatoCents > 0) {
        $riga($scrittura('incasso_rata'), 'avere', $pagatoCents);
    }

    return $rataId;
}


test('Scenario verde: cassa abbondante, niente fatture o uscite sotto controllo', function () {
    assegnaLiquidita($this->condominio->id, $this->contoLiquiditaId, 500000, $this->gestioneId); // 5.000€
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'aperta', 'approvata', now()->addDays(10)); // Uscita 1.000€

    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->livello)->toBe('verde')
        ->and($status->liquiditaTotaleCents)->toBe(500000)
        ->and($status->uscitePredittiveCents)->toBe(100000)
        ->and($status->giornoScopertoPrevisto)->toBeNull()
        ->and($status->scenarioPessimisticoCents)->toBe(400000);
});

test('Scenario rosso: liquidità insufficiente per fatture imminenti senza incassi', function () {
    assegnaLiquidita($this->condominio->id, $this->contoLiquiditaId, 50000, $this->gestioneId); // 500€
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'aperta', 'approvata', now()->addDays(5)); // Uscita 1.000€

    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->livello)->toBe('rosso')
        ->and($status->liquiditaTotaleCents)->toBe(50000)
        ->and($status->uscitePredittiveCents)->toBe(100000)
        ->and($status->giornoScopertoPrevisto)->not->toBeNull()
        ->and($status->scenarioPessimisticoCents)->toBe(-50000);
});

test('Scenario giallo: cassa non basta ma incassi attesi la salvano', function () {
    assegnaLiquidita($this->condominio->id, $this->contoLiquiditaId, 50000, $this->gestioneId); // 500€
    
    creaRata($this->pianoRateId, 100000, 0, 'emessa', now()->addDays(2)); // +1.000€
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'aperta', 'approvata', now()->addDays(5)); // -1.000€

    $status = $this->service->perCondominio($this->condominio->id);

    // Scenario pessimistico (no incassi) = 500 - 1000 = -500 (negativo)
    // Scenario ottimistico (incassi pagati) = 500 + 1000 - 1000 = +500 (positivo)
    // Un pessimistico negativo e un ottimistico positivo generano il livello giallo!
    expect($status->livello)->toBe('giallo')
        ->and($status->scenarioPessimisticoCents)->toBe(-50000)
        ->and($status->scenarioOttimisticoCents)->toBe(50000);
});

test('Residuo corretto: la timeline calcola il netto ancora da pagare o da incassare', function () {
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 40000, 'parziale', 'approvata', now()->addDays(5)); // Residuo: 60.000
    creaRata($this->pianoRateId, 50000, 20000, 'emessa', now()->addDays(5)); // Residuo: 30.000

    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->uscitePredittiveCents)->toBe(60000)
        ->and($status->incassiAttesiCents)->toBe(30000);
});

test('Esclusione di fatture fuori dalla finestra dei 30 giorni', function () {
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'aperta', 'approvata', now()->addDays(40));

    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->uscitePredittiveCents)->toBe(0);
});

test('Debiti pregressi scaduti: evitano l\'alert fatigue e si contano a parte', function () {
    // Fattura di 1 anno fa
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 150000, 0, 'aperta', 'approvata', now()->subDays(300), true);

    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->debitiPregressiScadutiCents)->toBe(150000)
        ->and($status->uscitePredittiveCents)->toBe(0) // Non entra nella previsione a 30gg!
        ->and($status->scenarioPessimisticoCents)->toBe(0); // Non toglie cassa corrente nella previsione!
});

test('Fatture scadute ma non pregresse vengono clampate ad "oggi" (Giorno 0)', function () {
    // Alle 00:30 di Roma è già il giorno dopo di quello di Greenwich: «oggi» è il giorno dell'amministratore, come lo usa il servizio.
    $this->travelTo(\Carbon\Carbon::parse('2026-11-07 00:30:00', 'Europe/Rome'));
    // Fattura ordinaria appena scaduta (es. scaduta ieri)
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'aperta', 'approvata', now()->subDays(2), false);

    $status = $this->service->perCondominio($this->condominio->id);

    // Essendo non pregresso, va nelle uscite predittive
    expect($status->uscitePredittiveCents)->toBe(100000)
        ->and($status->giornoScopertoPrevisto)->toBe(\App\Helpers\DateHelper::oggiUtente()); // Lo scoperto si calcola da "oggi", il giorno dell'amministratore
});


test('Fatture stornate non partecipano alla prediction', function () {
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'stornata', 'approvata', now()->addDays(5));

    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->uscitePredittiveCents)->toBe(0);
});

test('Filtro per gestione_id aggrega liquidita e rate per quella gestione', function () {
    assegnaLiquidita($this->condominio->id, $this->contoLiquiditaId, 50000, $this->gestioneId); // 500€
    // Dobbiamo aggiornare la scrittura per averla su quella gestione:
    DB::table('scritture_contabili')->update(['gestione_id' => $this->gestioneId]);

    // Rata emessa sulla gestione_id
    creaRata($this->pianoRateId, 100000, 0, 'emessa', now()->addDays(5));

    $status = $this->service->perCondominio($this->condominio->id, $this->gestioneId);

    expect($status->liquiditaTotaleCents)->toBe(50000)
        ->and($status->incassiAttesiCents)->toBe(100000);
});

test('Fattura senza scadenza: segnalata come anomalia, non conteggiata nelle uscite (§6.2)', function () {
    // TODO: Richiede migration per rendere data_scadenza nullable su fatture_passive.
    //       Da implementare quando servirà supportare fatture senza scadenza (import parziali, ecc.)
})->skip('§6.2 richiede data_scadenza nullable — feature futura');

test('Storno di pagamento: la fattura torna come uscita con residuo corretto (§6.8)', function () {
    // 1. Crea fattura da 100.000 cents con pagamento di 100.000 (pagata)
    $fatturaId = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'esercizio_id' => $this->esercizioId,
        'fornitore_id' => $this->fornitoreId,
        'tipo_documento' => 'fattura',
        'numero_documento' => 'FATT-STORNO',
        'netto_a_pagare' => 100000,
        'totale_documento' => 100000,
        'importo_imponibile' => 100000,
        'importo_iva' => 0,
        'data_scadenza' => now()->addDays(10),
        'stato_pagamento' => 'aperta',  // Lo storno la riapre
        'stato_approvazione' => 'approvata',
        'is_pregresso' => false,
        'data_documento' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Simula il pagamento (pivot positivo)
    $scritturaIdPag = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'gestione_id' => $this->gestioneId,
        'esercizio_id' => $this->esercizioId,
        'data_registrazione' => now(),
        'data_competenza' => now(),
        'numero_protocollo' => 'PROT-PAG',
        'causale' => 'Pagamento fornitore',
        'tipo_movimento' => 'pagamento_fornitore',
        'stato' => 'registrata',
    ]);

    DB::table('fattura_scrittura')->insert([
        'fattura_passiva_id' => $fatturaId,
        'scrittura_contabile_id' => $scritturaIdPag,
        'importo_allocato' => 100000,  // +100.000
        'tipo' => 'pagamento',
    ]);

    // 3. Simula lo storno (pivot negativo, stesso tipo — pattern del codebase)
    $scritturaIdStorno = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'gestione_id' => $this->gestioneId,
        'esercizio_id' => $this->esercizioId,
        'data_registrazione' => now(),
        'data_competenza' => now(),
        'numero_protocollo' => 'PROT-STO',
        'causale' => 'Storno pagamento',
        'tipo_movimento' => 'storno_pagamento_fornitore',
        'stato' => 'registrata',
    ]);

    DB::table('fattura_scrittura')->insert([
        'fattura_passiva_id' => $fatturaId,
        'scrittura_contabile_id' => $scritturaIdStorno,
        'importo_allocato' => -100000,  // -100.000 (lo storno è importo negativo, stesso tipo)
        'tipo' => 'pagamento',
    ]);

    // Il residuo netto è: 100.000 - (100.000 + -100.000) = 100.000
    // La fattura torna completamente scoperta
    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->uscitePredittiveCents)->toBe(100000)
        ->and($status->fattureInScadenza)->toHaveCount(1)
        ->and($status->fattureInScadenza[0]['importoCents'])->toBe(100000);
});

/*
|--------------------------------------------------------------------------
| Le righe d'azione del widget si aprono davvero
|--------------------------------------------------------------------------
|
| `TreasuryActionRow.vue` chiama `route(azione.route, azione.routeParams)` di Ziggy: con un nome che non
| esiste, o con un parametro che la rotta pretende e non c'è, Ziggy solleva, il componente prende
| l'eccezione e la riga diventa un testo che non porta da nessuna parte. Le tre azioni chiamavano
| `gestionale.rate.index`, `gestionale.piani-rate.index` e `gestionale.fatture.index`: nomi senza il
| prefisso `admin.` (e il primo non è mai esistito), senza il condominio e, per i piani rate, senza
| l'esercizio. La guardia sui nomi di rotta delle schermate legge solo i sorgenti `.vue` e `.ts`, e i
| nomi che il server manda dentro un payload non li vede.
*/

/**
 * Prova a costruire l'indirizzo come lo costruirebbe Ziggy: il nome deve esistere, e i parametri devono
 * bastare. Laravel solleva `UrlGenerationException` dove Ziggy solleva in JavaScript.
 */
function indirizzoDellAzione(array $azione): string
{
    expect(Route::has($azione['route']))->toBeTrue("la rotta «{$azione['route']}» non esiste");

    return route($azione['route'], $azione['routeParams'] ?? []);
}

test('Azioni suggerite: «Emetti Nuove Rate» e «Gestisci Debiti Pregressi» puntano a rotte che esistono, con i parametri che pretendono', function () {
    assegnaLiquidita($this->condominio->id, $this->contoLiquiditaId, 50000, $this->gestioneId);
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'aperta', 'approvata', now()->addDays(5));
    // Un debito pregresso già scaduto, che sta a parte e fa comparire la terza riga.
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 20000, 0, 'aperta', 'approvata', now()->subDays(10), true);

    $azioni = collect($this->service->perCondominio($this->condominio->id)->toArray()['azioniSuggerite'])->keyBy('tipo');

    expect($azioni->keys()->all())->toBe(['emissione_rate', 'gestione_pregressi']);

    $piani = indirizzoDellAzione($azioni['emissione_rate']);
    expect($piani)->toEndWith("/gestionale/{$this->condominio->id}/esercizi/{$this->esercizioId}/piani-rate");

    $fatture = indirizzoDellAzione($azioni['gestione_pregressi']);
    expect($fatture)->toContain("/gestionale/{$this->condominio->id}/fatture");
});

test('Azioni suggerite: senza un esercizio aperto «Emetti Nuove Rate» apre l\'elenco degli esercizi, non una rotta che manca di parametri', function () {
    DB::table('esercizi')->where('id', $this->esercizioId)->update(['stato' => 'chiuso']);
    assegnaLiquidita($this->condominio->id, $this->contoLiquiditaId, 50000, $this->gestioneId);
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'aperta', 'approvata', now()->addDays(5));

    $azioni = $this->service->perCondominio($this->condominio->id)->toArray()['azioniSuggerite'];

    expect($azioni)->toHaveCount(1)
        ->and($azioni[0]['tipo'])->toBe('emissione_rate')
        ->and(indirizzoDellAzione($azioni[0]))->toEndWith("/gestionale/{$this->condominio->id}/esercizi");
});

// Dalla 1.12.0-beta.4 la riga porta all'elenco delle rate scadute, e il nome non promette un sollecito: prima portava agli incassi con
// «Verifica o Sollecita Incassi», un nome che prometteva ciò che la pagina non faceva (la pagina del sito sulla Tesoreria si allinea).
test('Azioni suggerite: la riga sugli incassi apre l\'elenco delle rate scadute e non promette solleciti né promemoria', function () {
    assegnaLiquidita($this->condominio->id, $this->contoLiquiditaId, 50000, $this->gestioneId);
    creaRata($this->pianoRateId, 100000, 0, 'emessa', now()->addDays(2));
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'aperta', 'approvata', now()->addDays(5));

    $azioni = $this->service->perCondominio($this->condominio->id)->toArray()['azioniSuggerite'];

    expect($azioni)->toHaveCount(1)
        ->and($azioni[0]['tipo'])->toBe('sollecito')
        ->and(indirizzoDellAzione($azioni[0]))->toEndWith("/gestionale/{$this->condominio->id}/rate-scadute")
        ->and($azioni[0]['label'])->toBe('Vedi le rate scadute')
        ->and($azioni[0]['label'] . ' ' . $azioni[0]['descrizione'])->not->toMatch('/sollecit|promemoria|ritardatar/i');
});
