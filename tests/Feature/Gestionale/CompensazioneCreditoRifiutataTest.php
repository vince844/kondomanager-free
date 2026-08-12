<?php

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Exceptions\Gestionale\CreditoDiAltroSoggettoException;
use App\Exceptions\Gestionale\CreditoInsufficienteException;
use App\Exceptions\Gestionale\CreditoNonPiuDisponibileException;
use App\Exceptions\Gestionale\IncassoNonRegistrabileException;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\Immobile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * beta.49 — Le tre compensazioni a credito rifiutate arrivano come errore, non come pagina 500.
 *
 * È la coda **⑬**, aperta dalla revisione avversariale della beta.48.
 *
 * ## Il difetto, e perché è la terza volta
 *
 * `IncassoRateController::store()` cattura **per tipo**. Ogni guardia che solleva un tipo non
 * elencato esce all'amministratore come pagina 500, e con lei se ne va la distribuzione appena
 * fatta a mano su decine di rate.
 *
 * Il progetto l'ha corretto due volte, ogni volta per la singola guardia in lavorazione: la
 * beta.43 per la quadratura, la beta.48 per il debito altrui. Restavano dentro tre
 * `RuntimeException` sulle compensazioni a credito — preesistenti, e più facili da incontrare
 * delle due già corrette, perché **due su tre scattano su dati che erano validi quando la pagina
 * è stata aperta**: basta che il credito venga speso altrove nel frattempo.
 *
 * La correzione non aggiunge il terzo, il quarto e il quinto `catch`: introduce
 * {@see IncassoNonRegistrabileException} e chiude **la classe invece del caso**. L'ultimo test
 * di questo file è quello che impedisce la quarta ripetizione.
 *
 * ## L'altra metà, che senza la prima non serve a niente
 *
 * `IncassoRateNew.vue` mostrava gli errori **solo** per `cassa_id` e `data_pagamento`. Tutte
 * queste guardie tornavano al browser e non comparivano da nessuna parte: il pulsante sembrava
 * non rispondere. Togliere la 500 senza aggiungere il posto dove il motivo si legge avrebbe
 * sostituito una pagina di errore con un silenzio — vedi la fascia di riepilogo aggiunta sopra
 * «Conferma incasso», e `IncassoRateNew.test.ts`.
 */
function scenarioCrediti(): object
{
    $condominio = Condominio::create([
        'nome' => 'Condominio Crediti',
        'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1',
        'citta' => 'Milano',
        'cap' => '20100',
        'provincia' => 'MI',
    ]);

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome' => '2026',
        'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31',
        'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Ordinaria',
        'tipo' => 'ordinaria',
        'data_inizio' => '2026-01-01',
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
    ContoContabile::create([
        'condominio_id' => $condominio->id, 'codice' => '20.10', 'nome' => 'Anticipi Condomini',
        'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti',
    ]);

    $cassa = Cassa::create([
        'condominio_id' => $condominio->id,
        'conto_contabile_id' => $contoBanca->id,
        'nome' => 'Cassa', 'tipo' => 'banca', 'attiva' => true,
    ]);

    $bianchi = Anagrafica::create([
        'condominio_id' => $condominio->id, 'nome' => 'Luca Bianchi',
        'email' => 'bianchi@test.it', 'indirizzo' => 'Via Verdi 10', 'cap' => '00100',
        'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'BNCLCU80A01H501U',
    ]);

    $verdi = Anagrafica::create([
        'condominio_id' => $condominio->id, 'nome' => 'Anna Verdi',
        'email' => 'verdi@test.it', 'indirizzo' => 'Via Verdi 10', 'cap' => '00100',
        'citta' => 'Roma', 'provincia' => 'RM', 'codice_fiscale' => 'VRDNNA80A41H501K',
    ]);

    // ⚠️ **Due unità distinte, un proprietario ciascuna.** È la differenza che conta rispetto
    // allo scenario della beta.48: lì i due erano comproprietari della stessa unità, e il
    // credito dell'uno sul debito dell'altro è **permesso**. Qui non condividono niente, ed è
    // il solo caso in cui il rifiuto scatta.
    $unitaBianchi = Immobile::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Int 1', 'descrizione' => 'Appartamento di Bianchi', 'interno' => '1',
        'foglio' => '1', 'particella' => '1', 'subalterno' => '1',
    ]);
    $unitaBianchi->anagrafiche()->attach($bianchi->id, [
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
        'data_inizio' => now()->subYear(),
    ]);

    $unitaVerdi = Immobile::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Int 2', 'descrizione' => 'Appartamento di Verdi', 'interno' => '2',
        'foglio' => '1', 'particella' => '1', 'subalterno' => '2',
    ]);
    $unitaVerdi->anagrafiche()->attach($verdi->id, [
        'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
        'data_inizio' => now()->subYear(),
    ]);

    $pianoRate = PianoRate::create([
        'condominio_id' => $condominio->id,
        'gestione_id' => $gestione->id,
        'nome' => 'Piano 2026', 'numero_rate' => 4,
    ]);

    $rata = fn (int $n, string $scadenza) => Rata::create([
        'piano_rate_id' => $pianoRate->id, 'numero_rata' => $n,
        'data_scadenza' => $scadenza, 'importo_totale' => 10000, 'stato' => 'emessa',
    ]);

    $rata1 = $rata(1, '2026-01-31');
    $rata2 = $rata(2, '2026-02-28');
    $rata3 = $rata(3, '2026-03-31');
    $rata4 = $rata(4, '2026-04-30');

    // Il debito da pagare, e **è di Bianchi**: la guardia della beta.48 non deve scattare prima,
    // altrimenti questi test verificherebbero un rifiuto diverso da quello che dichiarano.
    $debitoBianchi = RataQuote::create([
        'rata_id' => $rata2->id, 'anagrafica_id' => $bianchi->id, 'immobile_id' => $unitaBianchi->id,
        'importo' => 10000, 'importo_pagato' => 0, 'stato' => 'da_pagare',
        'data_scadenza' => '2026-02-28',
    ]);

    // Credito di Verdi, su un'unità che Bianchi non tocca: € 100,00 di strapagamento.
    $creditoVerdi = RataQuote::create([
        'rata_id' => $rata1->id, 'anagrafica_id' => $verdi->id, 'immobile_id' => $unitaVerdi->id,
        'importo' => 10000, 'importo_pagato' => 20000, 'stato' => 'pagata',
        'data_scadenza' => '2026-01-31',
    ]);

    // Quota di Bianchi saldata esatta: nessun credito da prelevare. È la forma che assume, a
    // database, un credito già speso da qualcun altro mentre la pagina era aperta.
    $saldataBianchi = RataQuote::create([
        'rata_id' => $rata3->id, 'anagrafica_id' => $bianchi->id, 'immobile_id' => $unitaBianchi->id,
        'importo' => 10000, 'importo_pagato' => 10000, 'stato' => 'pagata',
        'data_scadenza' => '2026-03-31',
    ]);

    // Credito di Bianchi, ma piccolo: € 50,00.
    $creditoPiccolo = RataQuote::create([
        'rata_id' => $rata4->id, 'anagrafica_id' => $bianchi->id, 'immobile_id' => $unitaBianchi->id,
        'importo' => 10000, 'importo_pagato' => 15000, 'stato' => 'pagata',
        'data_scadenza' => '2026-04-30',
    ]);

    return (object) compact(
        'condominio', 'esercizio', 'gestione', 'cassa',
        'bianchi', 'verdi', 'unitaBianchi', 'unitaVerdi',
        'debitoBianchi', 'creditoVerdi', 'saldataBianchi', 'creditoPiccolo'
    );
}

/** Compensazione pura: € 100,00 di debito coperti da 100,00 prelevati dalla quota indicata. */
function compensazione(object $s, int $quotaCreditoId): array
{
    return [
        'pagante_id' => $s->bianchi->id,
        'cassa_id' => $s->cassa->id,
        'gestione_id' => $s->gestione->id,
        'data_pagamento' => '2026-05-01',
        'importo_totale' => 0.00,
        'descrizione' => 'Compensazione',
        'eccedenza' => 0,
        'dettaglio_pagamenti' => [
            ['rata_id' => $s->debitoBianchi->id, 'importo' => 100.00],
            ['rata_id' => $quotaCreditoId, 'importo' => -100.00],
        ],
    ];
}

function amministratore(): \App\Models\User
{
    $utente = \App\Models\User::factory()->create();
    $utente->assignRole(
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web'])
    );

    return $utente;
}

test('il credito di chi non condivide l\'unità viene rifiutato con una via d\'uscita', function () {
    $s = scenarioCrediti();

    $errore = null;
    try {
        app(StoreIncassoRateAction::class)->execute(
            compensazione($s, $s->creditoVerdi->id), $s->condominio, $s->esercizio
        );
    } catch (CreditoDiAltroSoggettoException $e) {
        $errore = $e->getMessage();
    }

    // Non basta che rifiuti: deve dire **cosa fare**, altrimenti sposta il vicolo cieco di un
    // passo invece di toglierlo (lezione della beta.45).
    expect($errore)->not->toBeNull()
        ->and($errore)->toContain('registra separatamente il rimborso');
});

test('un credito già speso altrove dice di ricaricare, non un numero di riga', function () {
    $s = scenarioCrediti();

    $errore = null;
    try {
        app(StoreIncassoRateAction::class)->execute(
            compensazione($s, $s->saldataBianchi->id), $s->condominio, $s->esercizio
        );
    } catch (CreditoNonPiuDisponibileException $e) {
        $errore = $e->getMessage();
    }

    // Il messaggio precedente era `'Quota credito non trovata per rata_id: 4127'`: un numero
    // interno che a schermo non compare, dentro una pagina 500. Chi lo leggeva non sapeva né
    // cos'era successo né cosa fare — e la risposta, quasi sempre, è «ricarica».
    expect($errore)->not->toBeNull()
        ->and($errore)->toContain('Ricarica la pagina')
        ->and($errore)->not->toContain('rata_id');
});

test('il credito insufficiente dice quanto ce n\'è e quanto ne serve', function () {
    $s = scenarioCrediti();

    $errore = null;
    try {
        app(StoreIncassoRateAction::class)->execute(
            compensazione($s, $s->creditoPiccolo->id), $s->condominio, $s->esercizio
        );
    } catch (CreditoInsufficienteException $e) {
        $errore = $e->getMessage();
    }

    // Entrambe le cifre. Con la sola negazione l'amministratore non sa di quanto ridurre; con
    // il disponibile in chiaro la correzione è immediata.
    expect($errore)->not->toBeNull()
        ->and($errore)->toContain('50,00')
        ->and($errore)->toContain('100,00');
});

test('tutti e tre i rifiuti tornano al modulo compilato, non in pagina 500', function () {
    $s = scenarioCrediti();
    $utente = amministratore();

    // La frase attesa è indicata caso per caso: senza, `assertSessionHasErrors` passerebbe anche
    // se il modulo fosse stato respinto dalla **validazione** — un rifiuto giusto per il motivo
    // sbagliato, che lascerebbe il difetto in piedi con il test verde.
    $casi = [
        'credito altrui'         => [$s->creditoVerdi->id, 'rimborso fra i due soggetti'],
        'credito esaurito'       => [$s->saldataBianchi->id, 'Ricarica la pagina'],
        'credito troppo piccolo' => [$s->creditoPiccolo->id, 'al massimo € 50,00'],
    ];

    foreach ($casi as $etichetta => [$quotaId, $frase]) {
        $risposta = $this->actingAs($utente)->post(
            "/admin/gestionale/{$s->condominio->id}/movimenti-rate",
            compensazione($s, $quotaId)
        );

        // `assertNotSame`/`assertStringContainsString` e non `expect()`: il secondo argomento di
        // `toContain` è **un altro termine da cercare**, non un messaggio diagnostico. Passandogli
        // l'etichetta il test cercava anche quella nel messaggio e falliva per conto suo.
        $this->assertNotSame(500, $risposta->getStatusCode(), "«{$etichetta}» risponde 500");

        // Sul dettaglio dei pagamenti, che è la parte da correggere: il rimedio è cambiare la
        // riga di credito, non l'importo incassato né il pagante.
        $risposta->assertSessionHasErrors('dettaglio_pagamenti');

        $this->assertStringContainsString(
            $frase,
            session('errors')->get('dettaglio_pagamenti')[0],
            "«{$etichetta}» non spiega il motivo"
        );
    }
});

test('la compensazione legittima continua a passare', function () {
    $s = scenarioCrediti();

    // Controprova obbligatoria: le tre guardie non devono rendere più difficile il caso normale.
    // Bianchi copre € 50,00 del proprio debito con i propri € 50,00 di credito.
    app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s->bianchi->id,
        'cassa_id' => $s->cassa->id,
        'gestione_id' => $s->gestione->id,
        'data_pagamento' => '2026-05-01',
        'importo_totale' => 0.00,
        'descrizione' => 'Compensazione',
        'eccedenza' => 0,
        'dettaglio_pagamenti' => [
            ['rata_id' => $s->debitoBianchi->id, 'importo' => 50.00],
            ['rata_id' => $s->creditoPiccolo->id, 'importo' => -50.00],
        ],
    ], $s->condominio, $s->esercizio);

    expect($s->debitoBianchi->fresh()->importo_pagato)->toBe(5000)
        ->and($s->creditoPiccolo->fresh()->credito_disponibile)->toBe(0);
});

test('nessuna guardia dell\'incasso solleva più un\'eccezione generica', function () {
    // ⚠️ **È questo il test che chiude la coda ⑬.** Gli altri verificano i tre casi noti; questo
    // impedisce il quarto.
    //
    // Il difetto non è mai stato «manca un catch»: è che il controller cattura per tipo e
    // l'autore della guardia successiva non ha modo di accorgersene — la 500 si vede solo
    // eseguendo quel ramo, che è raro per costruzione. Tre volte in sei beta.
    //
    // Una guardia nuova che estende `IncassoNonRegistrabileException` è coperta senza toccare
    // il controller. Una che solleva un tipo generico no, e questo test la ferma subito,
    // indicando la strada invece di limitarsi a fallire.
    $sorgente = file_get_contents(app_path('Actions/Gestionale/Movimenti/StoreIncassoRateAction.php'));

    expect($sorgente)->not->toMatch('/throw new \\\\?(RuntimeException|Exception|LogicException)\b/');
});

test('le eccezioni di dominio dell\'incasso dichiarano tutte il campo da correggere', function () {
    // `campo()` è ciò che rende il rifiuto un'istruzione invece di una negazione: dice **quale
    // casella** l'amministratore deve cambiare. La base lo impone come astratto, quindi il
    // compilatore già garantisce che esista; qui si verifica che il valore sia sensato — una
    // stringa vuota o un nome inventato manderebbe il messaggio in una casella che non c'è.
    $eccezioni = [
        new CreditoDiAltroSoggettoException,
        new CreditoNonPiuDisponibileException(1),
        new CreditoInsufficienteException(5000, 10000),
        new \App\Exceptions\Gestionale\DebitoNonDelPaganteException(2, 'Anna Verdi'),
        new \App\Exceptions\Gestionale\TotaleIncassoNonCorrispondenteException(10000, 5000, 0),
    ];

    $campiDelModulo = array_keys((new \App\Http\Requests\Gestionale\Movimenti\StoreIncassoRateRequest)->rules());

    foreach ($eccezioni as $e) {
        expect($e)->toBeInstanceOf(IncassoNonRegistrabileException::class)
            ->and($campiDelModulo)->toContain($e->campo());
    }
});
