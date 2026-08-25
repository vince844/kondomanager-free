<?php

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Exceptions\Gestionale\DebitoNonDelPaganteException;
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
 * beta.48 — Non si alloca su un debito che non è del pagante.
 *
 * È la voce **⑧(a)**, aperta il 06/08/2026 a beta.46 verificata e riprodotta a video sul
 * condominio 31 «Collaudo 46».
 *
 * ## Il difetto
 *
 * Cercando **per immobile**, `SituazioneDebitoriaController:39-45` aggrega le quote di *tutti*
 * i comproprietari: una riga può portare il debito di Verdi mentre chi paga è Bianchi. Il
 * motore però tocca solo le quote del pagante (`:358`, `->where('anagrafica_id', pagante_id)`),
 * quindi allocando su quella riga:
 *
 * - **con il credito**: il lato DARE preleva il credito di Bianchi, il lato AVERE non trova
 *   nessun debito suo da chiudere, e il blocco di `:414` glielo restituisce. Due scritture che
 *   si annullano, un protocollo consumato, il debito di Verdi intatto — e l'anteprima intanto
 *   dichiara «PARZIALE, resta da pagare X»;
 * - **con il contante**: quello che non trova dove andare diventa `eccedenza` e finisce come
 *   **anticipo di Bianchi** (`:177` → `:192`). Il denaro non si perde, ma il debito che
 *   l'amministratore credeva di saldare resta aperto e niente lo dice.
 *
 * In entrambi i casi l'operazione riesce e non fa quello che dice.
 *
 * ## Cosa NON va rotto, ed è la parte delicata
 *
 * La beta.46 ha già chiuso la domanda simmetrica — *di chi è il **credito*** — e ha lasciato
 * aperto di proposito il ramo comproprietario: il credito di Verdi **può** pagare il debito di
 * Bianchi se i due condividono l'unità (`:281-297`). Quella strada deve continuare a
 * funzionare: qui si guarda chi ha il **debito**, non chi ha il credito.
 *
 * ## Cosa questo file NON copre
 *
 * - La metà **frontend** (`usePaymentDistribution.ts` lavora su `r.residuo`, l'aggregato di
 *   tutti i comproprietari): resta in 1.10.1 perché cambia la semantica della ricerca per
 *   immobile ed è una decisione di prodotto.
 * - La corsa fra il caricamento della pagina e il salvataggio, in cui il debito del pagante
 *   viene saldato da qualcun altro nel frattempo: lì non c'è niente da pagare ed è benigno,
 *   e la guardia lo lascia passare apposta. Nessun test lo esercita.
 */
function scenarioDueComproprietari(): object
{
    $condominio = Condominio::create([
        'nome' => 'Condominio Comproprietari',
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

    // Una sola unità, due comproprietari: è la configurazione in cui la ricerca per immobile
    // aggrega quote di persone diverse sotto la stessa riga.
    $immobile = Immobile::create([
        'condominio_id' => $condominio->id,
        'nome' => 'Int 1', 'descrizione' => 'Appartamento in comproprietà', 'interno' => '1',
        'foglio' => '1', 'particella' => '1', 'subalterno' => '1',
    ]);

    foreach ([$bianchi, $verdi] as $a) {
        $immobile->anagrafiche()->attach($a->id, [
            'tipologia' => 'proprietario', 'quota' => 50, 'attivo' => true,
            'data_inizio' => now()->subYear(),
        ]);
    }

    $pianoRate = PianoRate::create([
        'condominio_id' => $condominio->id,
        'gestione_id' => $gestione->id,
        'nome' => 'Piano 2026', 'numero_rate' => 2,
    ]);

    $rata1 = Rata::create([
        'piano_rate_id' => $pianoRate->id, 'numero_rata' => 1,
        'data_scadenza' => '2026-01-31', 'importo_totale' => 20000, 'stato' => 'emessa',
    ]);

    $rata2 = Rata::create([
        'piano_rate_id' => $pianoRate->id, 'numero_rata' => 2,
        'data_scadenza' => '2026-02-28', 'importo_totale' => 10000, 'stato' => 'emessa',
    ]);

    // Rata 1 — il credito di Bianchi: strapagata di € 100,00.
    $creditoBianchi = RataQuote::create([
        'rata_id' => $rata1->id, 'anagrafica_id' => $bianchi->id, 'immobile_id' => $immobile->id,
        'importo' => 10000, 'importo_pagato' => 20000, 'stato' => 'pagata',
        'data_scadenza' => '2026-01-31',
    ]);

    // Rata 2 — il debito, e non è di Bianchi: è di Verdi.
    $debitoVerdi = RataQuote::create([
        'rata_id' => $rata2->id, 'anagrafica_id' => $verdi->id, 'immobile_id' => $immobile->id,
        'importo' => 10000, 'importo_pagato' => 0, 'stato' => 'da_pagare',
        'data_scadenza' => '2026-02-28',
    ]);

    return (object) compact(
        'condominio', 'esercizio', 'gestione', 'cassa', 'immobile',
        'bianchi', 'verdi', 'rata1', 'rata2', 'creditoBianchi', 'debitoVerdi'
    );
}

test('il credito non si può allocare su un debito intestato a un altro comproprietario', function () {
    $s = scenarioDueComproprietari();

    // Bianchi paga, e usa il suo credito della rata 1 sulla rata 2 — dove però il debito è di
    // Verdi. È il clic che il widget rende possibile cercando per immobile.
    $errore = null;

    try {
        app(StoreIncassoRateAction::class)->execute([
            'pagante_id' => $s->bianchi->id,
            'cassa_id' => $s->cassa->id,
            'gestione_id' => $s->gestione->id,
            'data_pagamento' => '2026-03-01',
            'importo_totale' => 0.00,
            'descrizione' => 'Compensazione',
            'eccedenza' => 0,
            'dettaglio_pagamenti' => [
                ['rata_id' => $s->debitoVerdi->id, 'importo' => 100.00],
                ['rata_id' => $s->creditoBianchi->id, 'importo' => -100.00],
            ],
        ], $s->condominio, $s->esercizio);
    } catch (DebitoNonDelPaganteException $e) {
        $errore = $e->getMessage();
    }

    expect($errore)->not->toBeNull();

    // Il messaggio deve **nominare chi ha il debito** e dire cosa fare: un rifiuto su una riga
    // che a schermo sembra la propria, senza dire di chi è, sposta il vicolo cieco di un passo
    // invece di toglierlo (lezione della beta.45).
    expect($errore)
        ->toContain('Anna Verdi')
        ->toContain('rata n.2')
        ->toContain("scegli come pagante l'intestatario del debito");
});

test('il debito altrui resta intatto e non si consuma il credito del pagante', function () {
    $s = scenarioDueComproprietari();

    try {
        app(StoreIncassoRateAction::class)->execute([
            'pagante_id' => $s->bianchi->id,
            'cassa_id' => $s->cassa->id,
            'gestione_id' => $s->gestione->id,
            'data_pagamento' => '2026-03-01',
            'importo_totale' => 0.00,
            'descrizione' => 'Compensazione',
            'eccedenza' => 0,
            'dettaglio_pagamenti' => [
                ['rata_id' => $s->debitoVerdi->id, 'importo' => 100.00],
                ['rata_id' => $s->creditoBianchi->id, 'importo' => -100.00],
            ],
        ], $s->condominio, $s->esercizio);
    } catch (DebitoNonDelPaganteException) {
        // atteso
    }

    // La transazione è annullata per intero: non basta che sollevi, deve non lasciare traccia.
    // Prima della correzione qui restavano due scritture che si annullavano e un protocollo
    // consumato, con i saldi identici — l'operazione «riuscita» che non aveva fatto niente.
    $s->debitoVerdi->refresh();
    $s->creditoBianchi->refresh();

    expect($s->debitoVerdi->importo_pagato)->toBe(0)
        ->and($s->creditoBianchi->credito_disponibile)->toBe(10000);
});

test('nemmeno il contante si può allocare su un debito altrui', function () {
    $s = scenarioDueComproprietari();

    // Senza guardia questi € 100,00 diventavano un anticipo di Bianchi, lasciando aperto il
    // debito di Verdi che l'amministratore credeva di saldare.
    expect(fn () => app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s->bianchi->id,
        'cassa_id' => $s->cassa->id,
        'gestione_id' => $s->gestione->id,
        'data_pagamento' => '2026-03-01',
        'importo_totale' => 100.00,
        'descrizione' => 'Incasso',
        'eccedenza' => 0,
        'dettaglio_pagamenti' => [
            ['rata_id' => $s->debitoVerdi->id, 'importo' => 100.00],
        ],
    ], $s->condominio, $s->esercizio))->toThrow(DebitoNonDelPaganteException::class);
});

test('la guardia arriva come errore di validazione, non come pagina 500', function () {
    // ⚠️ **Il reperto della revisione avversariale della beta.48.** La prima versione della
    // guardia sollevava un `RuntimeException` generico, e `IncassoRateController::store()`
    // cattura **per tipo** — solo `TotaleIncassoNonCorrispondenteException`. Sarebbe uscita
    // all'amministratore come pagina 500, buttando via la distribuzione appena fatta a mano.
    //
    // È lo stesso difetto che la beta.43 aveva corretto per la guardia della quadratura, e che
    // il commento in `IncassoRateController:165-168` descrive parola per parola. Questo test
    // esiste perché non si ripeta una terza volta.
    $s = scenarioDueComproprietari();

    $utente = \App\Models\User::factory()->create();
    $ruolo = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'amministratore', 'guard_name' => 'web']);
    $utente->assignRole($ruolo);

    $risposta = $this->actingAs($utente)->post(
        "/admin/gestionale/{$s->condominio->id}/movimenti-rate",
        [
            'pagante_id' => $s->bianchi->id,
            'cassa_id' => $s->cassa->id,
            'gestione_id' => $s->gestione->id,
            'data_pagamento' => '2026-03-01',
            'importo_totale' => 100.00,
            'descrizione' => 'Incasso',
            'eccedenza' => 0,
            'dettaglio_pagamenti' => [
                ['rata_id' => $s->debitoVerdi->id, 'importo' => 100.00],
            ],
        ]
    );

    // Non 500: si torna indietro con l'errore sul campo da cambiare — il **pagante**, non
    // l'importo, perché il rimedio è scegliere l'intestatario giusto.
    $risposta->assertSessionHasErrors('pagante_id');
    expect($risposta->getStatusCode())->not->toBe(500);
});

test('il pagante può saldare il PROPRIO debito sulla stessa unità', function () {
    $s = scenarioDueComproprietari();

    // Controprova: la guardia non deve rendere più difficile il caso normale.
    $debitoBianchi = RataQuote::create([
        'rata_id' => $s->rata2->id, 'anagrafica_id' => $s->bianchi->id,
        'immobile_id' => $s->immobile->id,
        'importo' => 5000, 'importo_pagato' => 0, 'stato' => 'da_pagare',
        'data_scadenza' => '2026-02-28',
    ]);

    app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s->bianchi->id,
        'cassa_id' => $s->cassa->id,
        'gestione_id' => $s->gestione->id,
        'data_pagamento' => '2026-03-01',
        'importo_totale' => 50.00,
        'descrizione' => 'Incasso',
        'eccedenza' => 0,
        'dettaglio_pagamenti' => [
            ['rata_id' => $debitoBianchi->id, 'importo' => 50.00],
        ],
    ], $s->condominio, $s->esercizio);

    expect($debitoBianchi->refresh()->importo_pagato)->toBe(5000)
        // E il debito di Verdi sulla stessa rata non è stato toccato.
        ->and($s->debitoVerdi->refresh()->importo_pagato)->toBe(0);
});

test('il credito di un comproprietario può ancora pagare il debito del pagante', function () {
    $s = scenarioDueComproprietari();

    // È il ramo che la beta.46 ha aperto di proposito (`:281-297`): due persone che
    // condividono l'unità. La guardia nuova riguarda chi ha il DEBITO e non deve chiuderlo.
    $creditoVerdi = RataQuote::create([
        'rata_id' => $s->rata1->id, 'anagrafica_id' => $s->verdi->id,
        'immobile_id' => $s->immobile->id,
        'importo' => 10000, 'importo_pagato' => 20000, 'stato' => 'pagata',
        'data_scadenza' => '2026-01-31',
    ]);

    $debitoBianchi = RataQuote::create([
        'rata_id' => $s->rata2->id, 'anagrafica_id' => $s->bianchi->id,
        'immobile_id' => $s->immobile->id,
        'importo' => 10000, 'importo_pagato' => 0, 'stato' => 'da_pagare',
        'data_scadenza' => '2026-02-28',
    ]);

    app(StoreIncassoRateAction::class)->execute([
        'pagante_id' => $s->bianchi->id,
        'cassa_id' => $s->cassa->id,
        'gestione_id' => $s->gestione->id,
        'data_pagamento' => '2026-03-01',
        'importo_totale' => 0.00,
        'descrizione' => 'Compensazione fra comproprietari',
        'eccedenza' => 0,
        'dettaglio_pagamenti' => [
            ['rata_id' => $debitoBianchi->id, 'importo' => 100.00],
            ['rata_id' => $creditoVerdi->id, 'importo' => -100.00],
        ],
    ], $s->condominio, $s->esercizio);

    expect($debitoBianchi->refresh()->importo_pagato)->toBe(10000);
});
