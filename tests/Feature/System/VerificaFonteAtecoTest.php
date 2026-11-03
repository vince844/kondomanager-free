<?php

/**
 * Il comando che chiede a ISTAT se ha pubblicato una revisione ATECO più recente della nostra.
 *
 * ## ⚠️ Chiede una cosa diversa dal gemello sui Comuni
 *
 * Sui Comuni si chiede «l'elenco è più fresco?», e ha senso: i comuni si fondono durante l'anno e
 * ISTAT ripubblica con una data. L'ATECO cambia per **revisione**, e nel file **una data non
 * esiste** — verificata cella per cella su entrambi i fogli. La domanda utile è «ne è uscita una
 * nuova?», e si risponde leggendo la pagina della documentazione.
 *
 * ## Il caso che conta più degli altri
 *
 * «La pagina non si legge più» **non deve** diventare «tutto a posto». È la classe di guasto che il
 * documento di processo raccoglie da tre beta — la guardia che si svuota senza far diventare rosso
 * niente — e qui ha un test suo.
 *
 * COSA NON COPRE: la risposta vera di ISTAT (le prove sono con `Http::fake`), e il caso di due
 * revisioni conviventi in tabella, che il comando di caricamento oggi non produce.
 */

use App\Models\CodiceAteco;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** Una pagina finta di ISTAT che elenca le revisioni indicate. */
function istatAtecoRisponde(array $revisioni, ?string $htmlPersonalizzato = null): void
{
    $html = $htmlPersonalizzato ?? implode("\n", array_map(
        fn (int $a) => "<a href=\"/wp-content/uploads/StrutturaATECO-{$a}-IT-EN.xlsx\">Struttura ATECO {$a} italiano inglese</a>",
        $revisioni,
    ));

    Http::fake([
        '*istat.it/classificazione*' => Http::response($html, 200),
        // La HEAD sul file è informativa: risponde, ma non decide niente.
        '*istat.it/wp-content*'      => Http::response('', 200, ['Content-Length' => '200704']),
    ]);
}

function caricaCodice(string $versione = 'ATECO 2025'): void
{
    CodiceAteco::create([
        'codice' => 'F', 'titolo' => 'Costruzioni', 'livello' => 1,
        'ordine' => 1, 'versione_fonte' => $versione,
    ]);
}

it('dice che siamo allineati quando ISTAT non pubblica una revisione più recente', function () {
    caricaCodice('ATECO 2025');
    istatAtecoRisponde([2002, 2007, 2022, 2025]);

    $esito = Artisan::call('kondomanager:verifica-fonte-ateco');

    expect($esito)->toBe(0)
        ->and(Artisan::output())->toContain('Siamo allineati');
});

it('avvisa quando ISTAT pubblica una revisione più recente', function () {
    // ⚠️ L'anno si calcola, non si scrive: il comando **scarta gli anni impossibili** (oltre
    // l'anno prossimo), perché un «2099» in una nota a piè di pagina manderebbe a rigenerare per
    // niente. Un anno fisso in questo test lo farebbe scattare — è successo scrivendolo, con 2031.
    // Il margine di un anno è quello vero: ATECO 2025 è stata pubblicata a dicembre 2024.
    $prossima = (int) date('Y') + 1;

    caricaCodice('ATECO 2025');
    istatAtecoRisponde([2007, 2025, $prossima]);

    $esito = Artisan::call('kondomanager:verifica-fonte-ateco');
    $uscita = Artisan::output();

    expect($esito)->toBe(1)
        ->and($uscita)->toContain("ATECO {$prossima}")
        ->and($uscita)->toContain('aggiorna-ateco')
        // Una revisione nuova rinomina e ritira codici: chi aggiorna deve saperlo prima, non dopo.
        ->and($uscita)->toContain('rinomina e ritira codici');
});

it('si ferma se dalla pagina non riconosce nessuna revisione, invece di dire che va tutto bene', function () {
    // ⚠️ Il test che conta. Se ISTAT rifà la pagina, questo comando diventa cieco: deve dirlo.
    // Un comando che tacesse resterebbe verde per sempre senza guardare più niente — è la classe
    // «guardia che si svuota in silenzio» che il flusso raccoglie da tre beta.
    caricaCodice('ATECO 2025');
    istatAtecoRisponde([], '<html><body><h1>Pagina rifatta, nessun elenco riconoscibile</h1></body></html>');

    $esito = Artisan::call('kondomanager:verifica-fonte-ateco');
    $uscita = Artisan::output();

    expect($esito)->toBe(1)
        ->and($uscita)->toContain('non riconosco nessuna revisione')
        ->and($uscita)->toContain('va aggiornato questo comando')
        ->and($uscita)->not->toContain('Siamo allineati');
});

it('non inventa una revisione futura da un anno qualsiasi trovato nella pagina', function () {
    // Un «2099» in una nota a piè di pagina manderebbe a rigenerare per niente.
    caricaCodice('ATECO 2025');
    istatAtecoRisponde([], '<a>Struttura ATECO 2025 italiano</a> <p>rif. delibera ATECO 2099</p>');

    $esito = Artisan::call('kondomanager:verifica-fonte-ateco');

    expect($esito)->toBe(0)
        ->and(Artisan::output())->toContain('Siamo allineati');
});

it('dice cosa fare quando la classificazione non è caricata', function () {
    istatAtecoRisponde([2025]);

    $esito = Artisan::call('kondomanager:verifica-fonte-ateco');

    expect($esito)->toBe(1)
        ->and(Artisan::output())->toContain('kondomanager:aggiorna-ateco');
});

it('non scambia un errore di rete per un allineamento', function () {
    caricaCodice('ATECO 2025');
    Http::fake(['*istat.it*' => Http::response('', 503)]);

    $esito = Artisan::call('kondomanager:verifica-fonte-ateco');

    expect($esito)->toBe(1)
        ->and(Artisan::output())->not->toContain('Siamo allineati');
});

it('non è pianificato: nessuna installazione esce in rete da sola per l\'ATECO', function () {
    // ⚠️ Stessa decisione dei Comuni, e per la stessa ragione: la classificazione viaggia col codice
    // **proprio** perché nessuna installazione debba dipendere dalla rete. Pianificare questo
    // comando la rimetterebbe dentro dalla finestra.
    $console = file_get_contents(base_path('routes/console.php'));

    expect($console)->not->toContain('verifica-fonte-ateco');
});
