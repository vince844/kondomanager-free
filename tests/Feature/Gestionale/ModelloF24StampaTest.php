<?php

/**
 * Il modello F24 stampabile: il quadro che finisce sul foglio.
 *
 * Il modello ministeriale era stato rimandato dicendo che servivano campi rimandati alla
 * v1.11 — firmatario, codice carica, intermediario, IBAN di addebito. Guardando il modulo
 * pubblicato dall'Agenzia quei campi **sul foglio non ci sono**: appartengono al tracciato
 * telematico e ai dichiarativi. Quel che il foglio chiede è già tutto in casa, e questi test
 * fissano che ci resti.
 *
 * Le tre regole che le avvertenze dell'Agenzia impongono sugli importi, e che valgono più di
 * ogni altra cosa qui dentro, sono nei primi test: due decimali sempre, nessun separatore di
 * migliaia, mai il segno meno.
 */

use App\Enums\Fiscale\StatoDelegaF24;
use App\Helpers\MoneyHelper;
use App\Models\Condominio;
use App\Models\Gestionale\DelegaF24;
use App\Models\Gestionale\RigaF24;
use App\Services\Gestionale\ModelloF24Service;

/**
 * Una delega non salvata con le sue relazioni già in mano.
 *
 * Il quadro è una trasformazione pura: non tocca il database, quindi il test non ha motivo
 * di farlo. Restano modelli in memoria, e i test partono in millisecondi.
 */
function delegaDiProva(array $righe, array $sovrascrivi = [], array $datiCondominio = []): DelegaF24
{
    $condominio = new Condominio(array_merge([
        'nome' => 'Condominio Via Verdi 12',
        'codice_fiscale' => '97123456789',
        'comune' => 'Milano',
        'provincia' => 'mi',
        'indirizzo' => 'Via Verdi 12',
    ], $datiCondominio));

    // `id` sta in `$guarded`, quindi il mass assignment lo scarterebbe in silenzio: va
    // assegnato a parte, o il nome del file uscirebbe senza il suo discriminante.
    $id = $sovrascrivi['id'] ?? null;
    unset($sovrascrivi['id']);

    $delega = new DelegaF24(array_merge([
        'cf_contribuente' => '97123456789',
        'denominazione_contribuente' => 'Condominio Via Verdi 12',
        'stato' => StatoDelegaF24::BOZZA,
        'data_scadenza' => '2026-06-16',
        'totale_debito' => array_sum(array_column($righe, 'importo_debito')),
    ], $sovrascrivi));

    if ($id !== null) {
        $delega->id = $id;
    }

    $delega->setRelation('condominio', $condominio);
    $delega->setRelation('righe', collect($righe)->map(fn ($r) => new RigaF24($r)));

    return $delega;
}

// ── Gli importi, come li vuole l'Agenzia ─────────────────────────────────────

test('i centesimi sono sempre due cifre, anche quando sono zero', function () {
    expect(MoneyHelper::perModelloF24(106000))->toEqual(['euro' => '1060', 'centesimi' => '00']);
    expect(MoneyHelper::perModelloF24(5270))->toEqual(['euro' => '52', 'centesimi' => '70']);
    expect(MoneyHelper::perModelloF24(5205))->toEqual(['euro' => '52', 'centesimi' => '05']);
});

/**
 * La colonna degli euro sul modulo è una griglia di caselle, non un numero scritto: un punto
 * di migliaia lì dentro occuperebbe una casella e sfalserebbe tutte le cifre a destra.
 * `MoneyHelper::format()` lo mette — è la ragione per cui questa conversione è una funzione a
 * parte e non un parametro di quella.
 */
test('la casella degli euro non porta il separatore di migliaia', function () {
    expect(MoneyHelper::perModelloF24(123456789)['euro'])->toEqual('1234567')
        ->and(MoneyHelper::format(123456789))->toContain('.');
});

/**
 * Le due colonne del modulo — debito e credito — portano già il verso. Un meno stampato
 * dentro la casella dell'importo renderebbe la delega irricevibile allo sportello.
 */
test('nella casella non finisce mai il segno meno', function () {
    expect(MoneyHelper::perModelloF24(-25000))->toEqual(['euro' => '250', 'centesimi' => '00']);
});

test('lo zero si scrive 0,00 e non resta vuoto', function () {
    expect(MoneyHelper::perModelloF24(0))->toEqual(['euro' => '0', 'centesimi' => '00']);
});

// ── Il quadro ────────────────────────────────────────────────────────────────

test('il codice fiscale del condominio si incolonna nelle sedici caselle', function () {
    $quadro = (new ModelloF24Service)->quadro(delegaDiProva([
        ['ordine' => 1, 'codice_tributo' => '1019', 'rateazione_mese_rif' => '0003', 'anno_riferimento' => '2026', 'importo_debito' => 32000],
    ]));

    $caselle = $quadro['contribuente']['codice_fiscale'];

    // Undici cifre a sinistra, cinque caselle vuote a destra: si compila così anche a penna.
    expect($caselle)->toHaveCount(16)
        ->and(implode('', array_slice($caselle, 0, 11)))->toEqual('97123456789')
        ->and(array_slice($caselle, 11))->toEqual(['', '', '', '', '']);
});

test('senza codice fiscale la griglia resta di sedici caselle vuote', function () {
    $quadro = (new ModelloF24Service)->quadro(delegaDiProva(
        [['ordine' => 1, 'codice_tributo' => '1019', 'rateazione_mese_rif' => '0003', 'anno_riferimento' => '2026', 'importo_debito' => 100]],
        ['cf_contribuente' => null],
        ['codice_fiscale' => null],
    ));

    expect($quadro['contribuente']['codice_fiscale'])->toHaveCount(16)
        ->and(array_filter($quadro['contribuente']['codice_fiscale']))->toBeEmpty();
});

/**
 * La griglia della sezione Erario ha sei righe stampate sul modulo. Accorciarla quando le
 * righe compilate sono meno non farebbe un foglio più pulito: farebbe un foglio diverso da
 * quello che chi lo riceve si aspetta.
 */
test('la sezione Erario esce sempre con sei righe', function () {
    $quadro = (new ModelloF24Service)->quadro(delegaDiProva([
        ['ordine' => 1, 'codice_tributo' => '1019', 'rateazione_mese_rif' => '0003', 'anno_riferimento' => '2026', 'importo_debito' => 32000],
        ['ordine' => 2, 'codice_tributo' => '1020', 'rateazione_mese_rif' => '0004', 'anno_riferimento' => '2026', 'importo_debito' => 24000],
    ]));

    expect($quadro['erario'])->toHaveCount(6)
        ->and($quadro['erario'][0]['codice_tributo'])->toEqual('1019')
        ->and($quadro['erario'][1]['codice_tributo'])->toEqual('1020')
        ->and($quadro['erario'][2]['codice_tributo'])->toBeNull()
        ->and($quadro['erario'][5]['debito'])->toBeNull();
});

test('le righe escono nell ordine del modello, non per importo', function () {
    $quadro = (new ModelloF24Service)->quadro(delegaDiProva([
        ['ordine' => 2, 'codice_tributo' => '1020', 'rateazione_mese_rif' => '0004', 'anno_riferimento' => '2026', 'importo_debito' => 90000],
        ['ordine' => 1, 'codice_tributo' => '1019', 'rateazione_mese_rif' => '0003', 'anno_riferimento' => '2026', 'importo_debito' => 1000],
    ]));

    expect($quadro['erario'][0]['codice_tributo'])->toEqual('1019')
        ->and($quadro['erario'][1]['codice_tributo'])->toEqual('1020');
});

/**
 * Sul foglio TOTALE A è per definizione la somma della colonna che ha sopra. Stampare il
 * campo `totale_debito` della testata, se i due divergessero, scriverebbe un totale che non
 * torna con i suoi addendi — e chi lo controlla allo sportello se ne accorge prima di noi.
 */
test('TOTALE A somma le righe e non legge la testata', function () {
    $quadro = (new ModelloF24Service)->quadro(delegaDiProva(
        [
            ['ordine' => 1, 'codice_tributo' => '1019', 'rateazione_mese_rif' => '0003', 'anno_riferimento' => '2026', 'importo_debito' => 32000],
            ['ordine' => 2, 'codice_tributo' => '1020', 'rateazione_mese_rif' => '0004', 'anno_riferimento' => '2026', 'importo_debito' => 24000],
        ],
        ['totale_debito' => 999999],
    ));

    expect($quadro['totale_a'])->toEqual(['euro' => '560', 'centesimi' => '00'])
        ->and($quadro['saldo'])->toEqual(['euro' => '560', 'centesimi' => '00']);
});

test('il domicilio fiscale arriva dall anagrafica del condominio, con la provincia in maiuscolo', function () {
    $quadro = (new ModelloF24Service)->quadro(delegaDiProva([
        ['ordine' => 1, 'codice_tributo' => '1019', 'rateazione_mese_rif' => '0003', 'anno_riferimento' => '2026', 'importo_debito' => 100],
    ]));

    expect($quadro['contribuente']['comune'])->toEqual('Milano')
        ->and($quadro['contribuente']['provincia'])->toEqual('MI')
        ->and($quadro['contribuente']['via'])->toEqual('Via Verdi 12');
});

// ── Quel che manca, detto prima dello sportello ──────────────────────────────

test('i campi vuoti dell anagrafica vengono elencati, senza bloccare la stampa', function () {
    $servizio = new ModelloF24Service;

    $delega = delegaDiProva(
        [['ordine' => 1, 'codice_tributo' => '1019', 'rateazione_mese_rif' => '0003', 'anno_riferimento' => '2026', 'importo_debito' => 100]],
        [],
        ['comune' => null, 'indirizzo' => ''],
    );

    expect($servizio->campiMancanti($delega))
        ->toEqual(['Comune del domicilio fiscale', 'Via e numero civico del domicilio fiscale']);

    // E il quadro si compone lo stesso: la decisione se il documento è pronto resta
    // dell'amministratore, non nostra.
    expect($servizio->quadro($delega)['totale_a']['euro'])->toEqual('1');
});

test('con l anagrafica completa non manca niente', function () {
    expect((new ModelloF24Service)->campiMancanti(delegaDiProva([
        ['ordine' => 1, 'codice_tributo' => '1019', 'rateazione_mese_rif' => '0003', 'anno_riferimento' => '2026', 'importo_debito' => 100],
    ])))->toBeEmpty();
});

// ── Stato e identità del documento ───────────────────────────────────────────

/**
 * Una delega stornata o annullata resta consultabile — va conservata dieci anni — quindi
 * resta ristampabile. Senza una parola sopra sarebbe indistinguibile da una viva, e pagarla
 * due volte è un errore che il gestionale può evitare da solo.
 */
test('le deleghe che non si pagano più escono con la filigrana', function (string $stato, ?string $atteso) {
    $delega = delegaDiProva(
        [['ordine' => 1, 'codice_tributo' => '1019', 'rateazione_mese_rif' => '0003', 'anno_riferimento' => '2026', 'importo_debito' => 100]],
        ['stato' => $stato],
    );

    expect((new ModelloF24Service)->filigrana($delega))->toEqual($atteso);
})->with([
    ['bozza', null],
    ['confermata', null],
    ['versata', null],
    ['stornata', 'STORNATA'],
    ['annullata', 'ANNULLATA'],
]);

/**
 * Il protocollo è previsto dalla Fase 2 del design e **oggi non lo scrive nessuno**: sta in
 * colonna e resta nullo. Il nome quindi lo dà sempre la scadenza, che è il modo in cui un
 * adempimento fiscale si chiama davvero.
 */
test('il file prende il nome dal protocollo, o dalla scadenza se non c è ancora', function () {
    $servizio = new ModelloF24Service;
    $righe = [['ordine' => 1, 'codice_tributo' => '1019', 'rateazione_mese_rif' => '0003', 'anno_riferimento' => '2026', 'importo_debito' => 100]];

    expect($servizio->nomeFile(delegaDiProva($righe, ['protocollo' => 'F24/2026/0007'])))
        ->toEqual('F24-F24-2026-0007.pdf')
        ->and($servizio->nomeFile(delegaDiProva($righe, ['id' => 6])))
        ->toEqual('F24-scad-2026-06-16-6.pdf');
});

/**
 * Due deleghe possono legittimamente avere la **stessa scadenza**: i due plafond maturano
 * insieme il 16 dicembre, e una delega di più di sei righe si spezza in due modelli per la
 * stessa data. A database ce ne sono già due così.
 *
 * Se il nome del file non le distinguesse, scaricandole la seconda sovrascriverebbe la prima —
 * e su un adempimento fiscale è il modo più silenzioso di perdere un documento.
 */
test('due deleghe con la stessa scadenza non producono lo stesso nome di file', function () {
    $servizio = new ModelloF24Service;
    $righe = [['ordine' => 1, 'codice_tributo' => '1019', 'rateazione_mese_rif' => '0003', 'anno_riferimento' => '2026', 'importo_debito' => 100]];

    $primo = $servizio->nomeFile(delegaDiProva($righe, ['id' => 6, 'data_scadenza' => '2026-12-16']));
    $secondo = $servizio->nomeFile(delegaDiProva($righe, ['id' => 7, 'data_scadenza' => '2026-12-16']));

    expect($primo)->not->toEqual($secondo);
});

/**
 * Tre copie, e solo la prima porta l'autorizzazione all'addebito in conto: sul modulo
 * ministeriale la sezione IBAN compare sulla sola copia che resta alla banca.
 */
test('le copie sono tre e l IBAN sta solo sulla prima', function () {
    $copie = (new ModelloF24Service)->copie();

    expect($copie)->toHaveCount(3)
        ->and($copie[0]['iban'])->toBeTrue()
        ->and($copie[1]['iban'])->toBeFalse()
        ->and($copie[2]['iban'])->toBeFalse()
        ->and($copie[2]['titolo'])->toEqual('COPIA PER IL SOGGETTO CHE EFFETTUA IL VERSAMENTO');
});

/**
 * Il numero di righe della sezione Erario non è una scelta di impaginazione: è quanto ne
 * stanno sul modulo, ed è la ragione per cui la delega si spezza a sei. Se le due costanti
 * divergessero, una delega da sette righe perderebbe la settima **senza dirlo**.
 */
test('la griglia del foglio e il taglio delle deleghe contano lo stesso numero di righe', function () {
    expect(ModelloF24Service::RIGHE_SEZIONE_ERARIO)
        ->toEqual(App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action::MAX_RIGHE_PER_DELEGA);
});
