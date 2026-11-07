<?php

/**
 * La stampa dell'elenco «Rate scadute» con molte persone. Con una tabella sola mPDF tiene in memoria tutta la tabella, e con 128M la stampa
 * moriva di fatal error già a 300 persone: pagina bianca, nessun messaggio. Ora la tabella è a blocchi, come il Libro Giornale, le celle
 * hanno classi invece degli stili scritti in ogni cella (l'HTML è un terzo, e mPDF ne fa copie grandi quattro volte), la vista riceve le
 * righe senza il dettaglio delle rate, e il tetto di persone è misurato su questo modello, non preso dal Libro Giornale.
 *
 * Le misure stanno accanto al tetto, nel controller.
 */

use App\Http\Controllers\Gestionale\Movimenti\RateScaduteController;
use App\Models\Condominio;
use App\Models\User;
use App\Services\PDF\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Il tetto di persone della stampa con un `memory_limit` dato: la formula si prova con la stringa, senza abbassare la memoria del processo. */
function rsmTetto(string $limite): int
{
    return (new ReflectionMethod(RateScaduteController::class, 'personeStampabiliCon'))->invoke(null, $limite);
}

/** N righe con la forma di quelle dell'elenco, con i testi più lunghi che la stampa scrive. */
function rsmRighe(int $n): \Illuminate\Support\Collection
{
    return collect(range(1, $n))->map(fn ($i) => [
        'anagrafica_id' => $i, 'nome' => "Persona di prova numero {$i}", 'unita' => ["Interno {$i}", "Box {$i}"], 'importo' => 123456 + $i, 'credito' => $i % 3 ? 0 : 2000,
        'fasce' => ['1_30' => 6000, '31_90' => 12000, 'oltre_90' => 105456 + $i], 'ritardo_testo' => 'ritardo massimo noto: 74 giorni; c\'è un pregresso di anzianità non nota',
        'origine_non_dichiarata' => true, 'sei_mesi' => ['data' => '2027-06-30', 'dispensa' => ['id' => 1, 'data_delibera' => '2027-03-20', 'numero_verbale' => '12', 'revocata_il' => null], 'mancano' => 2],
    ]);
}

function rsmDati(Condominio $c, \Illuminate\Support\Collection $righe): array
{
    return ['condominio' => $c, 'titolo' => 'Rate scadute al 20/03/2026', 'titolo_stampa' => 'Rate scadute al 20/03/2026', 'righe' => $righe, 'in_scadenza' => false, 'oggi' => true,
        'totale' => (int) $righe->sum('importo'), 'non_emesse' => null, 'selezione' => false, 'senza_firma' => true];
}

it('il tetto della stampa è misurato su questo modello: 3.234 persone con 128M, 9.021 con 256M, nessun tetto senza limite, nessuna persona sotto la base; non è quello del Libro Giornale', function () {
    $libroGiornale = (new ReflectionMethod(\App\Http\Controllers\Gestionale\Movimenti\ScritturaContabileController::class, 'righeStampabiliCon'))->invoke(null, '128M');

    expect(rsmTetto('128M'))->toBe(3234)
        ->and(rsmTetto('256M'))->toBe(9021)
        ->and(rsmTetto('-1'))->toBe(PHP_INT_MAX)
        ->and(rsmTetto('56M'))->toBe(0)
        ->and(rsmTetto('128M'))->not->toBe($libroGiornale);
});

it('la tabella è a blocchi di 100 persone, con un salto di pagina fra un blocco e l\'altro e le larghezze sulle intestazioni', function () {
    $c = Condominio::factory()->create();
    $html = View::make('pdf.gestionale.rate_scadute', rsmDati($c, rsmRighe(250)))->render();

    expect(substr_count($html, '<thead>'))->toBe(3)
        ->and(substr_count($html, '<pagebreak'))->toBe(2)
        ->and(substr_count($html, 'Persona di prova numero'))->toBe(250)
        ->and($html)->toContain('<th class="t" style="width: 18%;">Persona</th>')
        // Nessuno stile scritto in ogni cella: le celle hanno una classe.
        ->and(substr_count($html, '<td class="c'))->toBe(250 * 9);
});

it('con 600 persone la resa resta nella misura: il picco non cresce con la tabella intera', function () {
    $c = Condominio::factory()->create();
    $righe = rsmRighe(600);
    gc_collect_cycles();
    $prima = memory_get_usage();
    memory_reset_peak_usage();
    $pdf = app(PdfService::class)->generate('pdf.gestionale.rate_scadute', rsmDati($c, $righe), ['orientation' => 'L', 'margin_top' => 32])
        ->Output('x.pdf', \Mpdf\Output\Destination::STRING_RETURN);
    $picco = (memory_get_peak_usage() - $prima) / 1048576;

    // Misurato: con la tabella sola circa 110 MB (0,18 MB a persona); a blocchi con le classi circa 25 MB.
    expect(substr($pdf, 0, 4))->toBe('%PDF')
        ->and($picco)->toBeLessThan(45.0);
});

it('la vista della stampa riceve le righe senza il dettaglio delle rate: la memoria della resa non dipende da quante rate ha ognuno', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 600], 'B' => ['bice', 400]], 12, 'prima_rata', [], 120000);
    lcdEmetti($this, $s, [1, 2, 3], '2026-01-02');
    $this->travelTo('2026-03-20 10:00:00');
    $ricevute = null;
    View::composer('pdf.gestionale.rate_scadute', function ($v) use (&$ricevute) {
        $ricevute = $v->getData()['righe'];
    });

    $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.stampa', ['condominio' => $s['c']->id, 'tutte' => 1]))->assertOk();

    expect($ricevute)->toHaveCount(2)
        ->and($ricevute->every(fn ($r) => ! array_key_exists('rate', $r)))->toBeTrue()
        ->and($ricevute->pluck('importo')->all())->toBe([18000, 12000]);
});

it('oltre il tetto la stampa non parte: torna all\'elenco con gli stessi filtri e la frase «Restringi i filtri»', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 600], 'B' => ['bice', 400]], 12, 'prima_rata', [], 120000);
    lcdEmetti($this, $s, [1], '2026-01-02');
    $this->travelTo('2026-03-20 10:00:00');
    // Il memory_limit del processo non si abbassa sotto quello che la suite usa già: si fissa quello che il controller legge. Con 56M la
    // memoria che resta dopo la base (56 × 0,85 − 48 = −0,4 MB) non basta per nessuna persona.
    $limite = new ReflectionProperty(RateScaduteController::class, 'limiteMemoria');
    $limite->setValue(null, '56M');

    try {
        $r = $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.stampa', ['condominio' => $s['c']->id, 'tutte' => 1, 'minimo' => '10', 'ordine' => 'sei_mesi']));
    } finally {
        $limite->setValue(null, null);
    }

    $r->assertRedirect(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id, 'minimo' => '10', 'ordine' => 'sei_mesi']));
    expect(session('message')['message'] ?? '')->toContain('Restringi i filtri');
});
