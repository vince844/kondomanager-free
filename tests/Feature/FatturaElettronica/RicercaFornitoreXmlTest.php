<?php

use App\Models\Fornitore;
use App\Services\FatturaElettronica\RicercaFornitoreXml;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Decisione 3 di apertura della beta.14 (`docs/lettura_xml_fatture_passive.md`): il fornitore
 * si aggancia, non si indovina. Solo identificativi fiscali esatti, mai somiglianza sul nome.
 */
uses(RefreshDatabase::class);

function fornitoreTest(array $override = []): Fornitore
{
    return Fornitore::create(array_merge([
        'ragione_sociale' => 'Fornitore Test Srl',
        'partita_iva' => null,
        'codice_fiscale' => null,
    ], $override));
}

it('trova un solo fornitore per partita IVA esatta', function () {
    $vero = fornitoreTest(['partita_iva' => '01234567897']);
    fornitoreTest(['partita_iva' => '99999999999']);

    $trovati = (new RicercaFornitoreXml())->cerca('01234567897', null, null);

    expect($trovati)->toHaveCount(1)
        ->and($trovati->first()->id)->toBe($vero->id);
});

it('trova per codice fiscale quando la partita IVA non combacia — il cedente persona fisica', function () {
    $vero = fornitoreTest(['ragione_sociale' => 'Mario Rossi', 'codice_fiscale' => 'RSSMRA80A01H501Z']);

    $trovati = (new RicercaFornitoreXml())->cerca(null, null, 'RSSMRA80A01H501Z');

    expect($trovati)->toHaveCount(1)
        ->and($trovati->first()->id)->toBe($vero->id);
});

it('ignora maiuscole e spazi nel confronto', function () {
    $vero = fornitoreTest(['partita_iva' => '01234567897']);

    $trovati = (new RicercaFornitoreXml())->cerca('  01234567897  ', null, null);

    expect($trovati)->toHaveCount(1)
        ->and($trovati->first()->id)->toBe($vero->id);
});

it('restituisce più di un fornitore quando la partita IVA non è univoca', function () {
    // ⚠️ Misurato: partita_iva è indicizzata ma non UNIQUE, e in Demo KM un fornitore
    // compare su più studi. Più di un risultato è un esito legittimo, non un bug di query.
    $a = fornitoreTest(['ragione_sociale' => 'Studio A', 'partita_iva' => '01234567897']);
    $b = fornitoreTest(['ragione_sociale' => 'Studio B', 'partita_iva' => '01234567897']);

    $trovati = (new RicercaFornitoreXml())->cerca('01234567897', null, null);

    expect($trovati)->toHaveCount(2)
        ->and($trovati->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

it('restituisce una collezione vuota quando nessun fornitore combacia', function () {
    fornitoreTest(['partita_iva' => '01234567897']);

    $trovati = (new RicercaFornitoreXml())->cerca('99999999999', null, 'ALTROCF00A01H501Z');

    expect($trovati)->toHaveCount(0);
});

it('non cerca mai per somiglianza sulla ragione sociale', function () {
    // Due entità diverse che un fuzzy match confonderebbe.
    fornitoreTest(['ragione_sociale' => 'Ditta Pulizia De Filippo', 'partita_iva' => '01234567897']);
    $altra = fornitoreTest(['ragione_sociale' => 'Pulizie De Filippo S.r.l.', 'partita_iva' => '99999999999']);

    $trovati = (new RicercaFornitoreXml())->cerca('99999999999', null, null);

    expect($trovati)->toHaveCount(1)
        ->and($trovati->first()->id)->toBe($altra->id);
});

it('un IdPaese estero non aggancia un fornitore italiano con le stesse cifre', function () {
    // Trovato dalla revisione avversariale della beta.14. IdFiscaleIVA è IdPaese +
    // IdCodice: un cedente francese con IdCodice="01234567897" non è la stessa entità
    // di un fornitore italiano con partita_iva="01234567897", anche se le cifre
    // coincidono — partita_iva in fornitori non porta il prefisso paese, quindi senza
    // questo controllo l'aggancio sarebbe silenzioso e sbagliato.
    fornitoreTest(['ragione_sociale' => 'Ditta Italiana Srl', 'partita_iva' => '01234567897']);

    $trovati = (new RicercaFornitoreXml())->cerca('01234567897', 'FR', null);

    expect($trovati)->toHaveCount(0);
});

it('IdPaese=IT (dichiarato esplicitamente) cerca normalmente', function () {
    // Controprova incrociata: il controllo non deve rifiutare il caso domestico
    // dichiarato esplicitamente, solo quello effettivamente estero.
    $vero = fornitoreTest(['partita_iva' => '01234567897']);

    $trovati = (new RicercaFornitoreXml())->cerca('01234567897', 'IT', null);

    expect($trovati)->toHaveCount(1)
        ->and($trovati->first()->id)->toBe($vero->id);
});

it('un IdPaese estero non impedisce comunque la ricerca per codice fiscale', function () {
    // Il blocco riguarda solo la partita IVA: se il file dichiara anche un codice
    // fiscale (raro per un cedente estero, ma lo schema lo ammette), quella ricerca
    // resta valida — non è lei ad aver causato il falso positivo.
    $vero = fornitoreTest(['ragione_sociale' => 'Mario Rossi', 'codice_fiscale' => 'RSSMRA80A01H501Z']);

    $trovati = (new RicercaFornitoreXml())->cerca('01234567897', 'FR', 'RSSMRA80A01H501Z');

    expect($trovati)->toHaveCount(1)
        ->and($trovati->first()->id)->toBe($vero->id);
});

it('senza nessun identificativo non cerca niente, non restituisce tutti i fornitori', function () {
    fornitoreTest();
    fornitoreTest();

    expect((new RicercaFornitoreXml())->cerca(null, null, null))->toHaveCount(0);
    expect((new RicercaFornitoreXml())->cerca('', null, ''))->toHaveCount(0);
    expect((new RicercaFornitoreXml())->cerca('   ', null, null))->toHaveCount(0);
});
