<?php

/**
 * La tappa A della 1.12.0-beta.9: le Code 560–569, dieci difetti di testo del rendiconto. Via Roma (RendicontoViaRomaScenario) per le stampe e le pagine; per
 * i due esercizi della Coda 565 un piccolo giornale fatto a mano. Ogni prova rimette il difetto, a mano, e deve diventare rossa.
 */

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\AnnoACavallo;
use App\Services\Gestionale\ControlliDelRendiconto;
use App\Services\Gestionale\FascicoloDelRendiconto;
use App\Services\Gestionale\ParoleDellaStampa;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use App\Services\Gestionale\SintesiDelRendiconto;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** I file del prodotto (codice, viste, pagine, testi), senza i test e senza i changelog di prima: «file:riga» → testo della riga. */
function b9aRighe(): array
{
    static $letti = null;
    if ($letti !== null) {
        return $letti;
    }
    $radice = dirname(__DIR__, 3);
    $righe = [];
    foreach (['app', 'resources/js', 'resources/views', 'lang'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($radice.'/'.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $nome = $f->getPathname();
            if (preg_match('/\.(php|vue|ts)$/', $nome) && ! preg_match('/\.test\.ts$/', $nome)) {
                foreach (explode("\n", (string) file_get_contents($nome)) as $i => $riga) {
                    $righe[substr($nome, strlen($radice) + 1).':'.($i + 1)] = $riga;
                }
            }
        }
    }

    return $letti = $righe;
}

/** Le righe dei file del prodotto che contengono il testo (regex), «file:riga». */
function b9aCerca(string $regex): array
{
    return array_keys(array_filter(b9aRighe(), fn ($riga) => preg_match($regex, $riga) === 1));
}

/**
 * Come `b9aCerca`, ma solo nei file del rendiconto (le sue pagine, le sue stampe, i servizi che dicono le sue frasi, e la pagina delle rate scadute, che manda
 * all'elenco degli esercizi) e senza le righe di commento: sono i testi che l'amministratore e i condòmini leggono. Le parole delle promesse («arriverà», «potrà riportare») stanno anche altrove nel programma, in altri
 * discorsi, e lì non c'entrano.
 */
function b9aCercaNeiTesti(string $regex): array
{
    $ambito = '#(Gestionale/(AnnoACavallo|ParoleDellaStampa|SintesiDelRendiconto|RiepilogoPerCompetenza|StatoPatrimonialePaginaService|ControlliDelRendiconto|LiquiditaVincolataService)\.php'
        .'|/rendiconto/|/statoPatrimoniale/|esercizi/EserciziList\.vue|scritture/List\.vue|stato_patrimoniale\.blade\.php|rendiconto_[a-z]+\.blade\.php'
        .'|debiti/rateScadute\.ts|rateScadute/RateScadute\.vue)#';

    return array_keys(array_filter(b9aRighe(), fn ($riga, $dove) => preg_match($ambito, $dove) === 1 && preg_match('#^\s*(//|/\*|\*|\{\{--|<!--)#', $riga) !== 1
        && preg_match($regex, $riga) === 1, ARRAY_FILTER_USE_BOTH));
}

/** Il testo di una vista del fascicolo (frontespizio, riepilogo, …) con i dati che il programma le dà, ritoccati dalla prova. */
function b9aVista(string $vista, array $dati): string
{
    return vrmTesto(view($vista, $dati)->render());
}

// ─── Coda 560 e 568: il risultato ha tre parole e un nome solo ───────────────────────────────────────────────────────────────────────

it('Coda 560 — il risultato a parole è lo stesso per la pagina e per le stampe: zero «in pareggio», sotto zero «disavanzo», sopra «avanzo»', function () {
    expect(RiepilogoPerCompetenza::comeRisultato(0))->toBe('in pareggio')
        ->and(RiepilogoPerCompetenza::comeRisultato(-1))->toBe('disavanzo')
        ->and(RiepilogoPerCompetenza::comeRisultato(1))->toBe('avanzo');
    // Nessuna vista, nessuna pagina rimette il «se è meno di zero disavanzo, altrimenti avanzo» che dava «avanzo» a zero.
    $inline = array_filter(b9aCerca("/<\\s*0\\s*\\?\\s*'disavanzo'\\s*:\\s*'avanzo'/"), fn ($r) => ! str_contains($r, 'RiepilogoPerCompetenza.php') && ! str_contains($r, 'rendiconto/risultato.ts'));
    expect(array_values($inline))->toBe([]);
});

it('Coda 560 e 568 — la stampa del riepilogo e il frontespizio dicono «in pareggio» a zero, «avanzo» sopra e «disavanzo» sotto, con lo stesso nome del risultato', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $datiRiepilogo = $frontespizio = null;
    View::composer('pdf.gestionale.rendiconto_riepilogo', function ($v) use (&$datiRiepilogo) {
        $datiRiepilogo = $v->getData();
    });
    View::composer('pdf.gestionale.rendiconto_frontespizio', function ($v) use (&$frontespizio) {
        $frontespizio = $v->getData();
    });
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.riepilogo.stampa', [$s['c'], $s['e']]))->assertOk();
    app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['riepilogo']);

    $riepilogoCon = function (int $risultato) use ($datiRiepilogo) {
        $datiRiepilogo['riepilogo']['risultato']['risultato'] = $risultato;

        return b9aVista('pdf.gestionale.rendiconto_riepilogo', $datiRiepilogo);
    };
    $frontespizioCon = function (int $risultato) use ($frontespizio) {
        $frontespizio['sintesi']['prospetto']['risultato'] = $risultato;

        return b9aVista('pdf.gestionale.rendiconto_frontespizio', $frontespizio);
    };

    expect($riepilogoCon(0))->toContain('= € 0,00 (in pareggio).')->not->toContain('(avanzo)')
        ->and($riepilogoCon(500))->toContain('= € 5,00 (avanzo).')
        ->and($riepilogoCon(-500))->toContain('= € -5,00 (disavanzo).')
        ->and($frontespizioCon(0))->toContain('€ 0,00 (in pareggio)')
        ->and($frontespizioCon(500))->toContain('€ 5,00 (avanzo)')
        ->and($frontespizioCon(-500))->toContain('€ -5,00 (disavanzo)');

    // Il nome: lo stesso nelle due stampe e nella riga che lega le quote ai piani; mai «a giornale» davanti a un condòmino.
    expect($riepilogoCon(0))->toContain('Risultato: quote registrate in questo esercizio')->toContain('= quote registrate in questo esercizio')->not->toContain('a giornale')
        ->and($frontespizioCon(0))->toContain('Risultato: quote registrate in questo esercizio')->not->toContain('a giornale');
});

it('Coda 568 — il risultato si chiama «quote registrate in questo esercizio − costi» in tutti i testi: la pagina del riepilogo, la Sintesi e le due stampe', function () {
    // «quote emesse a giornale» (e «quote a giornale» nel raccordo) erano il nome della pagina; le stampe dicevano un'altra cosa per la stessa cifra.
    expect(b9aCerca('/quote emesse a giornale/'))->toBe([])
        ->and(array_values(array_filter(b9aCerca('/= quote a giornale|fra le quote a giornale/'), fn ($r) => str_contains($r, '.vue') || str_contains($r, '.blade.php'))))->toBe([]);
    expect(b9aCerca('/Risultato(<\/b>)?: quote registrate in questo esercizio/'))->toHaveCount(4);
});

// ─── Coda 561: i controlli sono sempre quattordici ─────────────────────────────────────────────────────────────────────────────────────────────

it('Coda 561 — i controlli sono sempre quattordici, e nessun testo dice che alcuni non compaiono', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $c = app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $s['e']);

    expect(collect($c['controlli'])->pluck('id')->all())->toBe(['PD', 'R6', 'R7', 'R8', 'R2', 'CN', 'AC', 'CR', 'CP', 'GE', 'CG', 'CU', 'CB', 'CA'])
        ->and($c['esito']['tornano'] + $c['esito']['non_tornano'] + $c['esito']['da_leggere'] + $c['esito']['non_calcolati'])->toBe(14);

    // Un condominio senza fondi, senza vincoli e senza una sola registrazione li ha quattordici lo stesso: nessuno è saltato perché il programma non può farlo.
    $vuoto = Condominio::factory()->create();
    $esercizio = Esercizio::factory()->create(['condominio_id' => $vuoto->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    expect(app(ControlliDelRendiconto::class)->perEsercizio($vuoto, $esercizio, '2027-01-15')['controlli'])->toHaveCount(14);

    expect(b9aCerca('/controlli che il programma non può eseguire|quelli che il programma non può eseguire|non può eseguire non compaiono/'))->toBe([]);
});

// ─── Coda 562: la quadratura è la regola del giornale sui saldi ───────────────────────────────────────────────────────────────────────

it('Coda 562 — la quadratura non dice «la stessa uguaglianza» del Libro Giornale: dice la stessa regola, dare uguale avere, applicata ai saldi dei conti', function () {
    expect(b9aCerca('/È la stessa uguaglianza del Libro Giornale/'))->toBe([]);
    expect(b9aCerca('/È la stessa regola del Libro Giornale, dare uguale avere, ma applicata ai saldi dei conti e non ai movimenti/'))->toHaveCount(1);

    // Il perché sta nel controllo «Partita doppia»: il totale del giornale (la somma dei movimenti) è più grande della quadratura (la somma dei saldi).
    [$condominio, $es2025] = b9aDueEsercizi();
    $pd = collect(app(StatoPatrimonialePaginaService::class)->costruisci($condominio, $es2025, oggi: '2026-09-12')['controlli'])->firstWhere('id', 'PD');
    $r6 = collect(app(StatoPatrimonialePaginaService::class)->costruisci($condominio, $es2025, oggi: '2026-09-12')['controlli'])->firstWhere('id', 'R6');
    expect($pd['sinistra'])->toBeGreaterThan($r6['sinistra'])->and($pd['nota'])->toContain('È la somma dei movimenti, non dei saldi');
});

// ─── Coda 563: la frase dell'esercizio finito è vera ───────────────────────────────────────────────────────────────────────────────────

it('Coda 563 — la frase dell\'esercizio finito dice che cosa è alla data di fine, che cosa conta le registrazioni dell\'esercizio e che i vincoli sono a oggi', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $finito = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e'])['stato']['frase'];

    expect($finito)->toBe('Esercizio finito al 31/12/2026: sono alla data di fine la situazione patrimoniale, le fatture aperte, il pagato e le quote dei piani; '
        .'costi, quote registrate, risultato ed entrate e uscite per cassa contano le registrazioni dell\'esercizio, anche con la data dopo la fine; i vincoli dei fondi sono a oggi.')
        ->and($finito)->not->toContain('i numeri sono alla data di fine')->not->toContain('scritte dopo la fine');

    // L'esercizio aperto non nomina una chiusura che il programma non ha, e non dice il contrario del ramo finito: i numeri cambiano a ogni registrazione.
    vrmGiorno($this, '2026-12-20');
    $aperto = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e'])['stato']['frase'];
    expect($aperto)->toBe('Esercizio aperto: i numeri sono a oggi e cambiano a ogni registrazione.')->not->toContain('chiusura');

    // Le tre parti sono vere nel codice: una registrazione dell'esercizio scritta dopo la fine conta nel risultato e nella cassa, non nella situazione alla data.
    [$condominio, $es2025] = b9aDueEsercizi();
    $conto = fn (string $ruolo) => DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', $ruolo)->value('id');
    b9aScrittura($condominio, $es2025, '2026-02-10', $conto('costi_servizi'), $conto('debiti_fornitori'), 70000, 'Fattura di dicembre registrata a febbraio');
    $pagina = app(StatoPatrimonialePaginaService::class)->costruisci($condominio, $es2025, oggi: '2026-09-12');
    expect($pagina['data'])->toBe('2025-12-31')
        ->and($pagina['risultato_gestione']['costi'])->toBe(150000 + 70000)
        ->and(collect($pagina['situazione']['passivo']['gruppi'])->firstWhere('gruppo', 'Debiti verso fornitori')['totale'])->toBe(50000);
});

// ─── Coda 564: lo stesso stato ha lo stesso nome ───────────────────────────────────────────────────────────────────────────────────────

it('Coda 564 — la situazione patrimoniale decide «finito» come la Sintesi, per data: la sola cosa che cambiava era la parola', function () {
    [$condominio, $es2025, $es2026] = b9aDueEsercizi();
    $oggi = '2026-09-12';
    $pagina = app(StatoPatrimonialePaginaService::class);
    $riepilogo = app(RiepilogoPerCompetenza::class);

    // 2025 è finito per data: la situazione dice 'chiuso' (il valore interno) e la Sintesi 'finito'; 2026 è aperto per tutte e due. Lo stato scritto
    // nell'esercizio ('chiuso' in 2025, 'aperto' in 2026 qui) non c'entra: si prova con lo stato 'aperto' anche per l'esercizio finito.
    DB::table('esercizi')->where('id', $es2025->id)->update(['stato' => 'aperto']);
    expect($pagina->costruisci($condominio, $es2025->fresh(), oggi: $oggi)['stato_esercizio'])->toBe('chiuso')
        ->and($riepilogo->perEsercizio($condominio, $es2025->fresh(), $oggi)['finito'])->toBeTrue()
        ->and($pagina->costruisci($condominio, $es2026, oggi: $oggi)['stato_esercizio'])->toBe('aperto')
        ->and($riepilogo->perEsercizio($condominio, $es2026, $oggi)['finito'])->toBeFalse();

    // Nessun testo del rendiconto chiama «chiuso» ciò che è finito per data.
    expect(b9aCerca('/esercizio chiuso: fotografia alla data di fine/'))->toBe([])
        ->and(b9aCerca('/(la data di fine|data di fine) per uno chiuso/'))->toBe([]);
});

// ─── Coda 565: le due righe del raccordo sono variazioni, non residui ────────────────────────────────────────────────────────────────────

/**
 * Due esercizi consecutivi, un giornale a mano. Numeri in euro:
 *   2025: apertura banca 5.000; rate emesse 3.000, incassate 2.000 (crediti a fine anno 1.000); fattura 1.500, pagata 1.000 (debiti 500).
 *   2026: rate emesse 1.500, incassate 2.200 (anche sulle rate di prima: i crediti scendono a 300); fattura 400, pagata 700 (anche quella di prima: i debiti scendono a 200).
 * Le variazioni del 2026 sono −700 sui crediti e −300 sui debiti: i residui a fine anno sono 300 e 200.
 */
function b9aDueEsercizi(): array
{
    $condominio = Condominio::factory()->create();
    $es2025 = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    $es2026 = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);
    foreach ([['conto_bancario', '1010', 'attivo', 'liquidita'], ['crediti_condomini', '1101', 'attivo', 'crediti'], ['debiti_fornitori', '2201', 'passivo', 'debiti'],
        ['gestione_rate', '3001', 'passivo', 'fondi'], ['passate_gestioni', '3002', 'passivo', 'fondi'], ['costi_servizi', '5001', 'costo', 'costi']] as [$ruolo, $codice, $tipo, $categoria]) {
        DB::table('conti_contabili')->insert(['condominio_id' => $condominio->id, 'ruolo' => $ruolo, 'codice' => $codice, 'nome' => ucfirst(str_replace('_', ' ', $ruolo)),
            'tipo' => $tipo, 'categoria' => $categoria, 'created_at' => now(), 'updated_at' => now()]);
    }
    $c = fn (string $ruolo) => (int) DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', $ruolo)->value('id');
    DB::table('casse')->insert(['condominio_id' => $condominio->id, 'conto_contabile_id' => $c('conto_bancario'), 'nome' => 'Conto corrente', 'tipo' => 'banca',
        'saldo_iniziale' => 0, 'attiva' => true, 'created_at' => now(), 'updated_at' => now()]);

    b9aScrittura($condominio, $es2025, '2025-01-01', $c('conto_bancario'), $c('passate_gestioni'), 500000, 'Apertura banca', $gestione->id, 'apertura');
    b9aScrittura($condominio, $es2025, '2025-03-01', $c('crediti_condomini'), $c('gestione_rate'), 300000, 'Emissione rate', $gestione->id);
    b9aScrittura($condominio, $es2025, '2025-04-01', $c('conto_bancario'), $c('crediti_condomini'), 200000, 'Incasso', $gestione->id);
    b9aScrittura($condominio, $es2025, '2025-06-01', $c('costi_servizi'), $c('debiti_fornitori'), 150000, 'Fattura', $gestione->id);
    b9aScrittura($condominio, $es2025, '2025-07-01', $c('debiti_fornitori'), $c('conto_bancario'), 100000, 'Pagamento', $gestione->id);
    b9aScrittura($condominio, $es2026, '2026-02-01', $c('crediti_condomini'), $c('gestione_rate'), 150000, 'Emissione rate 2026', $gestione->id);
    b9aScrittura($condominio, $es2026, '2026-03-01', $c('conto_bancario'), $c('crediti_condomini'), 220000, 'Incasso 2026', $gestione->id);
    b9aScrittura($condominio, $es2026, '2026-04-01', $c('costi_servizi'), $c('debiti_fornitori'), 40000, 'Fattura 2026', $gestione->id);
    b9aScrittura($condominio, $es2026, '2026-05-01', $c('debiti_fornitori'), $c('conto_bancario'), 70000, 'Pagamento 2026', $gestione->id);

    return [$condominio, $es2025, $es2026, $gestione];
}

function b9aScrittura(Condominio $condominio, Esercizio $esercizio, string $data, int $dare, int $avere, int $importo, string $causale, ?int $gestioneId = null, string $tipo = 'regolazione_immediata'): void
{
    $sc = ScritturaContabile::create(['condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $gestioneId ?? Gestione::where('condominio_id', $condominio->id)->value('id'),
        'data_registrazione' => $data, 'data_competenza' => $data, 'causale' => $causale, 'tipo_movimento' => $tipo, 'stato' => 'registrata']);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $dare, 'tipo_riga' => 'dare', 'importo' => $importo]);
    RigaScrittura::create(['scrittura_id' => $sc->id, 'conto_contabile_id' => $avere, 'tipo_riga' => 'avere', 'importo' => $importo]);
}

it('Coda 565 — su due esercizi le righe del raccordo sono variazioni dell\'esercizio, non residui: nel secondo il credito di prima, incassato, le fa scendere', function () {
    [$condominio, $es2025, $es2026] = b9aDueEsercizi();
    $servizio = app(StatoPatrimonialePaginaService::class);
    $residuo = fn (array $p, string $gruppo) => (int) (collect($p['situazione']['attivo']['gruppi'])->merge($p['situazione']['passivo']['gruppi'])->firstWhere('gruppo', $gruppo)['totale'] ?? 0);

    $p25 = $servizio->costruisci($condominio, $es2025, oggi: '2026-09-12');
    $p26 = $servizio->costruisci($condominio, $es2026, oggi: '2026-09-12');
    $e25 = collect($p25['raccordo']['righe'])->pluck('effetto', 'voce');
    $e26 = collect($p26['raccordo']['righe'])->pluck('effetto', 'voce');

    // Nel primo esercizio la variazione coincide con il residuo (sono uguali di cifra), nel secondo no: il residuo è 300 e 200, la variazione −700 e −300.
    expect($residuo($p25, 'Crediti verso i condòmini'))->toBe(100000)->and($e25['Variazione dei crediti verso i condòmini'])->toBe(-100000)
        ->and($residuo($p25, 'Debiti verso fornitori'))->toBe(50000)->and($e25['Variazione dei debiti verso i fornitori'])->toBe(50000)
        ->and($residuo($p26, 'Crediti verso i condòmini'))->toBe(30000)->and($e26['Variazione dei crediti verso i condòmini'])->toBe(70000)
        ->and($residuo($p26, 'Debiti verso fornitori'))->toBe(20000)->and($e26['Variazione dei debiti verso i fornitori'])->toBe(-30000)
        ->and($p25['raccordo']['quadra'])->toBeTrue()->and($p26['raccordo']['quadra'])->toBeTrue();
    // L'effetto sulla cassa è quello della variazione, mai il residuo (30000 e 20000 sarebbero i residui).
    expect(abs($e26['Variazione dei crediti verso i condòmini']))->not->toBe($residuo($p26, 'Crediti verso i condòmini'))
        ->and(abs($e26['Variazione dei debiti verso i fornitori']))->not->toBe($residuo($p26, 'Debiti verso fornitori'));

    // Il titolo resta vero nei due versi (una variazione può essere negativa); il vecchio nome da residuo non c'è più, né nel servizio né altrove.
    expect($e26->keys()->all())->not->toContain('Quote emesse e non ancora incassate')->not->toContain('Fatture registrate e non ancora pagate')
        ->and(b9aCerca('/Quote emesse e non ancora incassate|Fatture registrate e non ancora pagate/'))->toBe([]);
    $nature = collect($p26['raccordo']['righe'])->pluck('natura', 'voce');
    expect($nature['Variazione dei crediti verso i condòmini'])->toBe('più crediti, meno cassa; meno crediti, più cassa')
        ->and($nature['Variazione dei debiti verso i fornitori'])->toBe('più debiti, più cassa; meno debiti, meno cassa');
});

// ─── Coda 566: i testi non promettono ───────────────────────────────────────────────────────────────────────────────────────────────────

it('Coda 566 — nessun testo del rendiconto, dell\'elenco degli esercizi o delle frasi del servizio dice «in arrivo» o «finché non ci sarà»', function () {
    expect(b9aCerca('/\(in arrivo\)|in arrivo prossimamente|finché non ci sarà|diventerà definitiva|con quale modulo lo farà/'))->toBe([]);

    // Le frasi che il servizio dà alle pagine e alle stampe.
    expect(AnnoACavallo::FRASE_NORMA)->not->toContain('in arrivo')->not->toContain('arriva con la chiusura')->not->toContain('sposterà')
        ->toContain('Il programma non ha una chiusura guidata dell\'esercizio e non sposta le registrazioni da un esercizio all\'altro')
        ->and(StatoPatrimonialePaginaService::NOTA_VINCOLI_A_OGGI)->not->toContain('in arrivo')
        ->toContain('Il programma non dà il residuo vincolato di un fondo alla data di fine, perché i vincoli dichiarati non hanno una data: '
            .'il saldo di ogni cassa fondo a quella data è nella situazione patrimoniale, e i suoi movimenti nel mastrino del suo conto.');
});

it('Coda 566, SA11 — nei testi del rendiconto nessuna promessa, con qualunque parola: la chiusura guidata che riporterà, il modulo che arriverà, la chiusura che compenserà', function () {
    // Le forme che lo scettico ha trovato dopo la tappa (e che la prima ricerca non prendeva): un rimedio che manda alla chiusura guidata, una frase che dice
    // «che arriverà», la promessa «si compenseranno», e il «chiudi questo e apri il successivo» di una strada che dall'interfaccia non c'è.
    expect(b9aCercaNeiTesti('/(fino alla|con la|dalla|alla|dopo la) chiusura guidata|chiusura guidata, che|arriverà|potrà riportare|si compenseranno|resterà il conguaglio|sposterà/'))->toBe([])
        ->and(b9aCercaNeiTesti('/chiudi questo( esercizio)? e apri il successivo|dal giorno in cui chiudi|si apre il successivo/'))->toBe([])
        ->and(b9aCercaNeiTesti('/le nuove vanno lì|restano (qui|lì) fino/'))->toBe([]);
    // Il rimedio nuovo dei vincoli e le sue due fonti: il registro di contabilità non ha le casse fondo, i contributi versati non hanno una data.
    expect(b9aCercaNeiTesti('/lo ricavi dai contributi versati|dal registro di contabilità della cassa fondo/'))->toBe([]);
    // Le rate scadute (ST3): a gennaio la cella dell'esercizio che manca non manda a chiudere l'esercizio e ad aprire il successivo, e sotto la tabella non c'è il
    // collegamento all'elenco degli esercizi, dove non c'è niente da fare; la guida della pagina della situazione non dice «in questa versione» (ST7).
    expect(b9aCercaNeiTesti('/quando chiudi l.esercizio|e apri il successivo|Vai all.elenco degli esercizi|collegamento-esercizi/'))->toBe([])
        ->and(b9aCercaNeiTesti('/In questa versione|in questa versione/'))->toBe([]);
    // Nemmeno i commenti (il repository è pubblico): il residuo vincolato che «si leggerà», la posta che la chiusura «diventerà», il risultato come «quote emesse − costi».
    expect(b9aCerca('/si leggerà con il consuntivo dei fondi|con la chiusura \(v1\.17\)|diventerà una posta|`quote emesse − costi`/'))->toBe([]);
});

// ─── Coda 567: «Pagina» sta sopra i numeri ───────────────────────────────────────────────────────────────────────────────────────────

/** Le parole di una pagina di un PDF con il loro riquadro: [testo, xMin, xMax, yMin], da `pdftotext -bbox`. */
function b9aParole(string $pdf, int $pagina): array
{
    $file = tempnam(sys_get_temp_dir(), 'b9a').'.pdf';
    file_put_contents($file, $pdf);
    $xml = (string) shell_exec('pdftotext -bbox -f '.$pagina.' -l '.$pagina.' '.escapeshellarg($file).' - 2>/dev/null');
    @unlink($file);
    preg_match_all('/<word xMin="([\d.]+)" yMin="([\d.]+)" xMax="([\d.]+)" yMax="[\d.]+">([^<]*)<\/word>/', $xml, $m, PREG_SET_ORDER);

    return array_map(fn ($w) => [html_entity_decode($w[4]), (float) $w[1], (float) $w[3], (float) $w[2]], $m);
}

it('Coda 567 — nell\'indice del frontespizio l\'intestazione «Pagina» ha lo stesso bordo destro dei numeri sotto', function () {
    if (trim((string) shell_exec('command -v pdftotext')) === '') {
        $this->markTestSkipped('pdftotext non c\'è su questo computer.');
    }
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $f = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e'], ['riepilogo', 'cassa', 'controlli']);

    // La prova a occhio: con KM_PDF_PROVA=<cartella> la prima pagina si scrive lì (poi: pdftoppm -png -r 60 -f 1 -l 1).
    if (($cartella = getenv('KM_PDF_PROVA')) !== false && $cartella !== '') {
        file_put_contents(rtrim($cartella, '/').'/fascicolo-frontespizio.pdf', $f['pdf']);
    }

    $parole = b9aParole($f['pdf'], 1);
    // L'intestazione dell'indice è scritta in maiuscolo: la frase sopra («a pagina 5») e il piede («Frontespizio, pagina 1 di 1», «Pagina 1 di 11 del
    // fascicolo», Coda 573) non lo sono.
    $intestazione = collect($parole)->filter(fn ($p) => $p[0] === 'PAGINA')->sortByDesc(fn ($p) => $p[3])->first();
    expect($intestazione)->not->toBeNull();
    // I numeri dell'indice: le parole fatte di sole cifre, sotto «PAGINA» e sopra il piede (che comincia con «Documento emesso il»), in quella colonna (a destra
    // della metà della pagina), una per parte.
    $piede = collect($parole)->firstWhere(0, 'Documento')[3] ?? INF;
    $numeri = collect($parole)->filter(fn ($p) => preg_match('/^\d{1,3}$/', $p[0]) === 1 && $p[3] > $intestazione[3] && $p[3] < $piede && $p[2] > 400)->values();
    expect($numeri)->toHaveCount(count($f['indice']));
    foreach ($numeri as $n) {
        expect(abs($n[2] - $intestazione[2]))->toBeLessThan(1.0, 'il numero '.$n[0].' ha il bordo destro a '.$n[2].' e «Pagina» a '.$intestazione[2]);
    }
});

// ─── Coda 569: il preventivo della sintesi è al netto delle entrate da terzi ──────────────────────────────────────────────────────────

it('Coda 569 — la pagina della Sintesi dice, come la stampa, che il preventivo è le spese previste meno le entrate da terzi; il preventivo è davvero netto', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $sintesi = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e']);

    // Via Roma: 3.000 + 1.200 + 150 − 240 (l'affitto dell'antenna) = 4.110, non 4.350.
    expect($sintesi['prospetto']['preventivo'])->toBe(411000);
    $pagina = file_get_contents(dirname(__DIR__, 3).'/resources/js/pages/gestionale/movimenti/rendiconto/Sintesi.vue');
    $frontespizio = file_get_contents(dirname(__DIR__, 3).'/resources/views/pdf/gestionale/rendiconto_frontespizio.blade.php');
    expect($pagina)->toContain('Preventivo: le spese previste, meno le entrate da terzi.')
        ->and($frontespizio)->toContain('Preventivo: le spese previste, meno le entrate da terzi.');
});

// ─── Dopo lo scettico (SA1–SA12): le correzioni delle correzioni ───────────────────────────────────────────────────────────────────────

/** La stampa intera della situazione patrimoniale di Via Roma: i dati che la vista riceve, per rifarla con un numero ritoccato dalla prova. */
function b9aDatiStampaSituazione($test, array $s): array
{
    $dati = null;
    View::composer('pdf.gestionale.stato_patrimoniale', function ($v) use (&$dati) {
        $dati = $v->getData();
    });
    $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.stato-patrimoniale.print', [$s['c'], $s['e']]))->assertOk();

    return $dati;
}

it('Coda 562, SA1 — dal secondo esercizio la differenza fra le quote e i costi della situazione è il cumulato e non il risultato di gestione: la frase le tiene distinte', function () {
    [$condominio, , $es2026] = b9aDueEsercizi();
    $p = app(StatoPatrimonialePaginaService::class)->costruisci($condominio, $es2026, oggi: '2026-09-12');
    $quote = (int) collect($p['situazione']['passivo']['gruppi'])->firstWhere('gruppo', 'Quote emesse ai condòmini')['totale'];
    $costi = $p['situazione']['costi'];

    // 2025: rate 3.000, fattura 1.500. 2026: rate 1.500, fattura 400. Alla data le quote sono 4.500 e i costi 1.900: la differenza è 2.600, il cumulato; il
    // risultato di gestione dell'esercizio, 1.500 − 400, è 1.100. Nel primo esercizio coincidono, ed è per questo che la frase di prima sembrava vera.
    expect($quote)->toBe(450000)->and($costi)->toBe(190000)
        ->and($quote - $costi)->toBe($p['risultato_gestione']['cumulato'])->and($quote - $costi)->toBe(260000)
        ->and($p['risultato_gestione']['risultato'])->toBe(110000)->and($quote - $costi)->not->toBe($p['risultato_gestione']['risultato']);

    expect(b9aCerca('/la differenza fra le quote e i costi è il risultato di gestione/'))->toBe([])
        ->and(b9aCerca('/Quote e costi si accumulano dal primo esercizio: la loro differenza è il cumulato da conguagliare, in fondo alla pagina; il risultato di questo esercizio è nel riquadro del risultato di gestione, in cima\./'))->toHaveCount(1);
});

it('Coda 562, SA2 — la stampa della situazione, che va ai condòmini, non promette che la chiusura compenserà costi e quote: dice che si accumulano e quanto è il cumulato', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $dati = b9aDatiStampaSituazione($this, $s);
    $testo = vrmTesto(view('pdf.gestionale.stato_patrimoniale', $dati)->render());

    expect($testo)->not->toContain('si compenseranno')->not->toContain('resterà il conguaglio')->not->toContain('saranno conguagliati')
        ->toContain('I costi stanno fra le attività finché non sono conguagliati: sono spese sostenute per conto dei condòmini; le quote emesse stanno fra le passività come debito della gestione.')
        ->toMatch('/Quote e costi si accumulano dal primo esercizio, e la loro differenza è il cumulato da conguagliare, € -?[\d.]+,\d\d; il risultato di questo esercizio è nel riquadro del risultato di gestione, qui sopra\./');
    expect(b9aCerca('/si compenseranno|resterà il conguaglio/'))->toBe([]);
});

it('Coda 565, SA3 — la terza riga del raccordo, le ritenute, è una variazione come le altre due: nel secondo esercizio un F24 che versa quelle di prima la porta sotto zero', function () {
    [$condominio, $es2025, $es2026, $gestione] = b9aDueEsercizi();
    DB::table('conti_contabili')->insert(['condominio_id' => $condominio->id, 'ruolo' => 'debiti_erario_ritenute', 'codice' => '2202', 'nome' => 'Erario ritenute',
        'tipo' => 'passivo', 'categoria' => 'debiti', 'created_at' => now(), 'updated_at' => now()]);
    $c = fn (string $ruolo) => (int) DB::table('conti_contabili')->where('condominio_id', $condominio->id)->where('ruolo', $ruolo)->value('id');
    // 2025: una ritenuta di 200 maturata e non versata. 2026: l'F24 versa quei 200, e una ritenuta nuova di 50 resta da versare.
    b9aScrittura($condominio, $es2025, '2025-09-01', $c('costi_servizi'), $c('debiti_erario_ritenute'), 20000, 'Ritenuta maturata', $gestione->id);
    b9aScrittura($condominio, $es2026, '2026-01-16', $c('debiti_erario_ritenute'), $c('conto_bancario'), 20000, 'F24', $gestione->id);
    b9aScrittura($condominio, $es2026, '2026-06-01', $c('costi_servizi'), $c('debiti_erario_ritenute'), 5000, 'Ritenuta nuova', $gestione->id);
    $servizio = app(StatoPatrimonialePaginaService::class);
    $p25 = $servizio->costruisci($condominio, $es2025, oggi: '2026-09-12');
    $p26 = $servizio->costruisci($condominio, $es2026, oggi: '2026-09-12');
    $residuo = fn (array $p) => (int) (collect($p['situazione']['passivo']['gruppi'])->firstWhere('gruppo', 'Debiti verso l\'Erario')['totale'] ?? 0);
    $riga = fn (array $p) => collect($p['raccordo']['righe'])->firstWhere('voce', 'Variazione dei debiti verso l\'Erario');

    // Nel primo esercizio variazione e residuo coincidono (200); nel secondo il residuo è 50 e la riga vale −150: l'F24 ha versato più di quanto è maturato.
    expect($residuo($p25))->toBe(20000)->and($riga($p25)['effetto'])->toBe(20000)
        ->and($residuo($p26))->toBe(5000)->and($riga($p26)['effetto'])->toBe(-15000)
        ->and($riga($p26)['natura'])->toBe('più ritenute da versare, più cassa; ritenute versate, meno cassa')
        ->and($p25['raccordo']['quadra'])->toBeTrue()->and($p26['raccordo']['quadra'])->toBeTrue();
    // Il vecchio titolo e la vecchia natura («aumento» di una cosa che era scesa) non ci sono più.
    expect(collect($p26['raccordo']['righe'])->pluck('voce')->all())->not->toContain('Ritenute maturate e non ancora versate')
        ->and(b9aCerca('/Ritenute maturate e non ancora versate|aumento dei debiti verso l.Erario/'))->toBe([]);
});

it('Coda 566, SA4 — il rimedio dei vincoli non manda dove la cosa non c\'è: non al registro di contabilità (le casse fondo non ci sono) né ai contributi versati (non hanno una data)', function () {
    $nota = StatoPatrimonialePaginaService::NOTA_VINCOLI_A_OGGI;

    expect($nota)->not->toContain('registro di contabilità')->not->toContain('dai contributi versati')
        ->toContain('perché i vincoli dichiarati non hanno una data')
        ->toContain('il saldo di ogni cassa fondo a quella data è nella situazione patrimoniale, e i suoi movimenti nel mastrino del suo conto');
    // La copia scritta a mano nella pagina è la stessa frase della costante, parola per parola.
    $pagina = file_get_contents(dirname(__DIR__, 3).'/resources/js/pages/gestionale/movimenti/statoPatrimoniale/Show.vue');
    expect($pagina)->toContain($nota);
    // Il foglio dei condòmini ha la sua frase, che non manda da nessuna parte.
    expect(ParoleDellaStampa::frase($nota))->not->toContain('registro di contabilità')->not->toContain('contributi versati');
});

it('Coda 566, SA5 e SA12 — la pagina degli esercizi non invita a chiuderli per consolidare i saldi, né ad aprirne di nuovi; la card dice «Fine anno»', function () {
    expect(b9aCerca('/consolidare i saldi|Apri nuovi esercizi/'))->toBe([])
        ->and(b9aCerca("/title: 'Fine Anno'/"))->toBe([])
        ->and(b9aCerca("/title: 'Fine anno'/"))->toHaveCount(1)
        ->and(b9aCerca('/Il programma non ha il passaggio all.anno nuovo: non mettere lo stato su «Chiuso», tieni aperto l.esercizio in corso\./'))->toHaveCount(1);
});

it('Coda 566, SA6 — i sei rimedi dell\'anno a cavallo dicono che cosa succede oggi: nessuna chiusura guidata che riporterà, nessun «chiudi questo e apri il successivo»; sul foglio dei condòmini lo stesso', function () {
    $rimedi = ['RIMEDIO_QUI' => AnnoACavallo::RIMEDIO_QUI, 'RIMEDIO_QUI_APERTO' => AnnoACavallo::RIMEDIO_QUI_APERTO, 'RIMEDIO_ALTRO' => AnnoACavallo::RIMEDIO_ALTRO,
        'RIMEDIO_F24' => AnnoACavallo::RIMEDIO_F24, 'RIMEDIO_STORNO_INCASSO' => AnnoACavallo::RIMEDIO_STORNO_INCASSO, 'RIMEDIO_FUORI' => AnnoACavallo::RIMEDIO_FUORI];

    $conPromesse = [];
    foreach ($rimedi as $nome => $rimedio) {
        // La pagina (la costante) e il foglio (la costante com'è nella stampa): nessuna delle due promette, nessuna manda a una strada che non c'è.
        foreach (['pagina' => $rimedio, 'foglio' => ParoleDellaStampa::frase($rimedio)] as $dove => $testo) {
            if (preg_match('/chiusura guidata|potrà riportare|chiudi questo|apri il successivo|si apre il successivo|le nuove vanno lì|restano (qui|lì) fino/u', $testo) === 1) {
                $conPromesse[] = $nome.' sul '.$dove;
            }
        }
    }
    expect($conPromesse)->toBe([]);

    // Quello che dicono oggi: il programma non sposta; con l'esercizio aperto, non apre il successivo e le nuove con la data dell'anno dopo restano in questo.
    expect(AnnoACavallo::RIMEDIO_QUI)->toEndWith('il programma non le sposta, e restano qui.')
        ->and(AnnoACavallo::RIMEDIO_ALTRO)->toEndWith('il programma non le sposta, e restano lì.')
        ->and(AnnoACavallo::RIMEDIO_QUI_APERTO)->toBe('Questo esercizio è ancora aperto e il programma non apre il successivo: le nuove registrazioni con la data dopo la sua fine restano in questo, e l\'elenco le mostra.')
        ->and(AnnoACavallo::RIMEDIO_F24)->toBe('I versamenti F24 stanno nell\'esercizio della loro delega, anche con la data di un altro.')
        ->and(AnnoACavallo::RIMEDIO_STORNO_INCASSO)->toEndWith('che storna, anche con la data di un altro.')
        ->and(AnnoACavallo::RIMEDIO_FUORI)->toEndWith('Se sono registrazioni di un esercizio che nel programma non c\'è, il programma non le sposta e restano qui.')
        ->and(ParoleDellaStampa::frase(AnnoACavallo::RIMEDIO_FUORI))->toEndWith('Se sono registrazioni di un esercizio che nella contabilità non c\'è, restano qui.');
});

it('Coda 562, SA7 — la guida del Libro Giornale non dice che la quadratura è «la stessa uguaglianza» del dare = avere: dice che è la stessa regola, applicata ai saldi', function () {
    expect(b9aCerca('/la stessa uguaglianza del dare = avere/'))->toBe([])
        ->and(b9aCerca('/è la regola del dare uguale avere applicata ai saldi dei conti e non ai movimenti, per questo i suoi totali non sono quelli del riquadro «Partita doppia» accanto/'))->toHaveCount(1);
});

it('Coda 568, SA8 — il risultato ha lo stesso nome anche nella situazione patrimoniale: la pagina, la stampa e la riga del raccordo dicono «quote registrate in questo esercizio»', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $dati = b9aDatiStampaSituazione($this, $s);
    $testo = vrmTesto(view('pdf.gestionale.stato_patrimoniale', $dati)->render());
    $primaRiga = $dati['pagina']['raccordo']['righe'][0];

    expect($testo)->toMatch('/quote registrate in questo esercizio € -?[\d.]+,\d\d − costi € -?[\d.]+,\d\d/')->not->toMatch('/quote emesse € /')
        ->and($primaRiga['voce'])->toBe('Risultato di gestione dell\'esercizio')->and($primaRiga['natura'])->toBe('quote registrate in questo esercizio − costi');
    expect(b9aCerca('/quote emesse \{\{ (euro\(p\.risultato_gestione|\$fmt\(\$rg)/'))->toBe([])
        ->and(b9aCerca("/'natura' => 'quote emesse − costi'/"))->toBe([])
        ->and(b9aCerca('/quote registrate in questo esercizio \{\{ (euro\(p\.risultato_gestione|\$fmt\(\$rg)/'))->toHaveCount(2);

    // «Come leggere» della stampa (va ai condòmini) e la guida della pagina: il risultato è la differenza fra le quote registrate in questo esercizio e i costi di
    // questo esercizio. Le «quote emesse ai condòmini» della situazione sono il gruppo delle passività, di tutti gli esercizi: la loro differenza con i costi è il
    // cumulato (Coda 562, SA1), e non si può chiamare «risultato di gestione», con le stesse cifre o con altre parole.
    expect($testo)->toContain('Il risultato di gestione è la differenza fra le quote registrate in questo esercizio e i costi di questo esercizio — l\'avanzo o il disavanzo; che cosa farne lo decide l\'assemblea.')
        ->not->toContain('differenza fra le quote emesse ai condòmini e i costi');
    expect(b9aCerca('/differenza fra le quote emesse ai condòmini e i\s*(costi)?\s*$/'))->toBe([])
        ->and(b9aCerca('/Quote emesse ai condòmini meno (i )?costi/'))->toBe([])
        ->and(b9aCerca('/Quote registrate in questo esercizio meno i costi di questo esercizio:/'))->toHaveCount(1);
});

it('Coda 560, SA9 — la situazione patrimoniale a zero dice «in pareggio» come le altre: la stampa con le stesse tre parole', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $dati = b9aDatiStampaSituazione($this, $s);
    $con = function (int $risultato) use ($dati) {
        $dati['pagina']['risultato_gestione']['risultato'] = $risultato;

        return vrmTesto(view('pdf.gestionale.stato_patrimoniale', $dati)->render());
    };

    expect($con(0))->toContain('Risultato di gestione € 0,00 (in pareggio)')
        ->and($con(500))->toContain('Risultato di gestione € 5,00 (avanzo)')
        ->and($con(-500))->toContain('Risultato di gestione € -5,00 (disavanzo)');
    // Nessuna vista rimette le due parole a mano, con lo zero senza niente.
    expect(b9aCerca("/' \\(disavanzo\\)'|' \\(avanzo\\)'/"))->toBe([]);
});
