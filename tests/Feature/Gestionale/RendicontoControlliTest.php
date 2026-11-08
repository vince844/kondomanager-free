<?php

/**
 * I controlli del rendiconto: le registrazioni oltre il confine dell'esercizio nei due versi, la liquidità contro il prospetto per cassa con le
 * registrazioni oltre il confine tolte e nominate, «i costi tornano con il riepilogo», «i crediti tornano con le posizioni», le voci di un'altra
 * gestione, e l'esito «N tornano, M non tornano, K da leggere» con la riga fissa.
 *
 * Le cifre sono quelle del progetto (i due modi di lavorare dell'anno a cavallo, Via Roma, il caso con la fattura contestata e la regolazione), fatte a
 * mano. Centesimi; positivo = debito, negativo = credito.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\ContoContabile;
use App\Services\Gestionale\ControlliDelRendiconto;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/Beta59313AdPersonamScenari.php';

beforeEach(function () {
    $this->user = be52Utente();
    // Le schede si leggono dalla loro rotta: la pagina non dipende da una compilazione di Vite.
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** Una scrittura scritta a mano: righe [conto, dare|avere, centesimi, cassa]; `$extra` per i campi della scrittura (lo storno: `scrittura_padre_id`, la cancellazione). */
function rctScrittura(array $s, Esercizio $e, string $data, string $tipo, array $righe, array $extra = []): int
{
    $id = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'esercizio_id' => $e->id, 'gestione_id' => $s['g']->id, 'data_registrazione' => $data,
        'data_competenza' => $data, 'numero_protocollo' => 'RCT-'.uniqid(), 'causale' => "{$tipo} {$data}", 'tipo_movimento' => $tipo, 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()] + $extra);
    foreach ($righe as [$conto, $verso, $importo, $cassa]) {
        DB::table('righe_scritture')->insert(['scrittura_id' => $id, 'conto_contabile_id' => $conto, 'cassa_id' => $cassa, 'tipo_riga' => $verso, 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $id;
}

/** Le proprietà della scheda «Controlli», dalla sua rotta. */
function rctPagina($test, array $s, ?Esercizio $e = null, array $query = []): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.rendiconto.controlli', [$s['c'], $e ?? $s['e']]).($query ? '?'.http_build_query($query) : ''))
        ->assertOk()->viewData('page')['props'];
}

/** Le registrazioni di un esercizio con la data nel suo periodo, senza le aperture e le cancellate: il conteggio della regola, letto a parte. */
function rctNelPeriodo(Esercizio $e): int
{
    return DB::table('scritture_contabili')->where('esercizio_id', $e->id)->whereNull('deleted_at')->where('tipo_movimento', '<>', 'apertura')
        ->where('data_competenza', '>=', $e->data_inizio->format('Y-m-d'))->where('data_competenza', '<', $e->data_fine->copy()->addDay()->format('Y-m-d'))->count();
}

/** La frase dei numeri quando le registrazioni fuori da ogni esercizio sono più di quelle nel periodo. */
function rctFraseFuori(int $fuori, int $nelPeriodo): string
{
    return 'Scritte in questo esercizio con una data che non sta in nessun esercizio del condominio: '.$fuori.'; con la data nel suo periodo: '.$nelPeriodo
        .' (le aperture non contano; le fatture registrate come pregresse non contano fra quelle fuori). Le cause possibili sono due: le date dell\'esercizio sono sbagliate, oppure sono '
        .'registrazioni di un esercizio che nel programma non c\'è.';
}

/**
 * Via delle Acacie com'era (12/09/2026): l'esercizio creato come «2025» per le operazioni del 2026, l'apertura della banca al suo inizio (€ 5.000,00), tre
 * emissioni da € 300,00 e tre incassi da € 200,00 del 2026. Con `$fatturaDiDicembre` anche la fattura delle pulizie del 15/12/2025 (€ 600,00), registrata a
 * gennaio 2026: la spunta della pregressa non si accende, perché la data non è prima dell'inizio dell'esercizio aperto. Accanto: sette registrazioni
 * cancellate con la data nel periodo e una cancellata con la data del 2026; un altro condominio con il suo esercizio 2026 e, scritta lì, una registrazione
 * con una data del 2025 (il periodo di questo esercizio).
 */
function rctAcacie($test, bool $fatturaDiDicembre = false): array
{
    vrmGiorno($test, '2026-01-10');
    $s = be52Condominio();
    $s['e']->update(['data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'nome' => 'Esercizio 2025']);
    $s['cassa']->update(['saldo_iniziale' => 500000]);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $s['e'] = $s['e']->fresh();
    $banca = (int) $s['cassa']->conto_contabile_id;
    $crediti = rctConto($s, 'crediti_condomini');
    $rate = rctConto($s, 'gestione_rate');
    if ($fatturaDiDicembre) {
        rctScrittura($s, $s['e'], '2025-12-15', 'fattura_acquisto', [[rctConto($s, 'costi_servizi'), 'dare', 60000, null], [rctConto($s, 'debiti_fornitori'), 'avere', 60000, null]]);
    }
    foreach (['2026-02-01', '2026-03-01', '2026-04-01'] as $d) {
        rctScrittura($s, $s['e'], $d, 'emissione_rata', [[$crediti, 'dare', 30000, null], [$rate, 'avere', 30000, null]]);
        rctScrittura($s, $s['e'], $d, 'incasso_rata', [[$banca, 'dare', 20000, $s['cassa']->id], [$crediti, 'avere', 20000, null]]);
    }
    foreach (range(1, 7) as $i) {
        rctScrittura($s, $s['e'], '2025-06-0'.$i, 'incasso_rata', [[$banca, 'dare', 1000, $s['cassa']->id], [$crediti, 'avere', 1000, null]], ['deleted_at' => now()]);
    }
    $s['cancellata_fuori'] = rctScrittura($s, $s['e'], '2026-05-01', 'incasso_rata', [[$banca, 'dare', 1000, $s['cassa']->id], [$crediti, 'avere', 1000, null]], ['deleted_at' => now()]);
    $s['altro'] = be52Condominio();
    $s['altra_registrazione'] = rctScrittura($s['altro'], $s['altro']['e'], '2025-06-15', 'incasso_rata',
        [[(int) $s['altro']['cassa']->conto_contabile_id, 'dare', 7700, $s['altro']['cassa']->id], [rctConto($s['altro'], 'crediti_condomini'), 'avere', 7700, null]]);

    return $s;
}

/** Una fattura registrata come pregressa dalla rotta, nell'esercizio dato (la spunta che il modulo accende quando la data è prima dell'inizio dell'esercizio aperto). */
function rctPregressa($test, array &$s, Esercizio $e, string $data, float $euro, string $numero): void
{
    $s['fornitore'] ??= be52Fornitore();
    be52MastriPregresso($s);
    $sE = array_merge($s, ['e' => $e]);
    $debito = be52DebitoFornitore($sE, $s['fornitore'], (int) round(-$euro * 100));
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadPregressa($sE, $s['fornitore'], [
        'data_documento' => $data, 'data_scadenza' => $data, 'competenza_dal' => substr($data, 0, 8).'01', 'competenza_al' => $data,
        'data_competenza_originaria' => $data, 'imponibile_pregresso' => $euro, 'aliquota_iva_pregressa' => 0, 'saldo_patrimoniale_id' => $debito,
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => $euro, 'fonte_id' => $debito]],
    ], $numero))->assertSessionHasNoErrors();
}

/** Una pregressa di € 500,00 di cui € 200,00 coperti dai saldi iniziali: la parte non coperta (€ 300,00) diventa una sopravvenienza passiva, un costo vero dell'esercizio in cui si registra. */
function rctPregressaConParteNonCoperta($test, array &$s, Esercizio $e, string $data, string $numero): void
{
    $s['fornitore'] ??= be52Fornitore();
    be52MastriPregresso($s);
    \App\Models\Gestionale\PianoConto::firstOrCreate(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id], ['nome' => 'Piano dei conti']);
    $sE = array_merge($s, ['e' => $e]);
    $debito = be52DebitoFornitore($sE, $s['fornitore'], -50000);
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadPregressa($sE, $s['fornitore'], [
        'data_documento' => $data, 'data_scadenza' => $data, 'competenza_dal' => substr($data, 0, 8).'01', 'competenza_al' => $data,
        'data_competenza_originaria' => $data, 'imponibile_pregresso' => 500.00, 'aliquota_iva_pregressa' => 0, 'saldo_patrimoniale_id' => $debito,
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 200.00, 'fonte_id' => $debito]],
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => ['nome_voce' => 'Debito pregresso', 'origine_decisionale' => 'gestione_corrente',
            'tipo_ripartizione' => 'millesimale', 'is_ordinario' => true, 'richiede_copertura' => true, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100,
            'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0]],
    ], $numero))->assertSessionHasNoErrors();
}

/** Da gennaio ad aprile 2027 tutto si scrive nel 2026, che è ancora aperto (il 2027 non c'è ancora): 4 emissioni, 12 fatture, 80 incassi, 12 pagamenti. */
function rctGennaioAprile($test, array $s): void
{
    $banca = (int) $s['cassa']->conto_contabile_id;
    $crediti = rctConto($s, 'crediti_condomini');
    $rate = rctConto($s, 'gestione_rate');
    $debiti = rctConto($s, 'debiti_fornitori');
    foreach (['2027-01-05', '2027-02-05', '2027-03-05', '2027-04-05'] as $d) {
        rctScrittura($s, $s['e'], $d, 'emissione_rata', [[$crediti, 'dare', 110000, null], [$rate, 'avere', 110000, null]]);
    }
    foreach (range(1, 12) as $i) {
        vrmGiorno($test, '2027-01-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT));
        vrmFattura($test, $s, $s['fornitore'], 'F27-'.$i, [[$s['pulizie']->id, $i <= 8 ? 250.00 : 300.00]], now()->format('Y-m-d'));
    }
    foreach (range(1, 80) as $i) {
        rctScrittura($s, $s['e'], '2027-0'.(1 + $i % 3).'-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT), 'incasso_rata',
            [[$banca, 'dare', 5125, $s['cassa']->id], [$crediti, 'avere', 5125, null]]);
    }
    foreach (range(1, 12) as $i) {
        rctScrittura($s, $s['e'], '2027-03-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT), 'pagamento_fornitore', [[$debiti, 'dare', 25000, null], [$banca, 'avere', 25000, $s['cassa']->id]]);
    }
}

function rctConto(array $s, string $ruolo): int
{
    return (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', $ruolo)->value('id');
}

function rctControlli(array $s, ?Esercizio $e = null, array $filtri = []): array
{
    return app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $e ?? $s['e'], null, $filtri);
}

function rctControllo(array $c, string $id): array
{
    return collect($c['controlli'])->firstWhere('id', $id);
}

/** Il 2026 di Via Roma chiuso a gennaio, il 2027 aperto con la gestione riusata. */
function rctApriIl2027(array $s): Esercizio
{
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e2027, $s['g']->id);

    return $e2027;
}

/**
 * Il secondo modo di lavorare: il 2026 chiuso a gennaio. L'incasso del 28/12/2026 (€ 500,00), registrato il 05/01/2027, va nel 2027; il versamento F24
 * del 16/01/2027 (€ 40,00) resta nel 2026, l'esercizio della delega.
 */
function rctSecondoModo($test): array
{
    $s = vrmScenario($test);
    vrmGiorno($test, '2027-01-05');
    $s['e2027'] = rctApriIl2027($s);
    $banca = (int) $s['cassa']->conto_contabile_id;
    $s['incasso_cavallo'] = rctScrittura($s, $s['e2027'], '2026-12-28', 'incasso_rata', [[$banca, 'dare', 50000, $s['cassa']->id], [rctConto($s, 'crediti_condomini'), 'avere', 50000, null]]);
    $s['f24_cavallo'] = rctScrittura($s, $s['e'], '2027-01-16', 'pagamento_f24', [[rctConto($s, 'debiti_erario_ritenute'), 'dare', 4000, null], [$banca, 'avere', 4000, $s['cassa']->id]]);

    return $s;
}

/** La fattura delle pulizie di dicembre (€ 1.000,00), arrivata il 10/02/2027 e registrata nel 2027 come pregressa (la spunta che il modulo accende da sé). */
function rctPregressaDiDicembre($test, array &$s): void
{
    vrmGiorno($test, '2027-02-10');
    be52MastriPregresso($s);
    $s27 = array_merge($s, ['e' => $s['e2027']]);
    $debito = be52DebitoFornitore($s27, $s['fornitore'], -100000);
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadPregressa($s27, $s['fornitore'], [
        'data_documento' => '2026-12-15', 'data_scadenza' => '2027-01-15', 'competenza_dal' => '2026-12-01', 'competenza_al' => '2026-12-31',
        'data_competenza_originaria' => '2026-12-15', 'imponibile_pregresso' => 1000.00, 'aliquota_iva_pregressa' => 0, 'saldo_patrimoniale_id' => $debito,
        'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 1000.00, 'fonte_id' => $debito]],
    ], 'PUL-DIC'))->assertSessionHasNoErrors();
}

// ─── L'anno a cavallo: il 2026 tenuto aperto fino all'assemblea ─────────────────────────────────────────────────────────

it('il 2026 tenuto aperto fino all\'assemblea, con il suo anno di registrazioni: 108 nel verso A, 16 rosse (€ 4.400,00 di quote e € 3.200,00 di costi del 2027) e 92 da leggere; R2 torna e nomina il denaro oltre il confine; nessun consiglio sulle date', function () {
    $s = vrmScenario($this);
    // L'anno del 2026: oltre alle registrazioni di Via Roma, 60 incassi e 60 pagamenti da € 100,00, così che la banca al 31/12 resta € 5.219,00.
    $banca = (int) $s['cassa']->conto_contabile_id;
    foreach (range(1, 60) as $i) {
        $d = '2026-'.str_pad((string) (2 + $i % 10), 2, '0', STR_PAD_LEFT).'-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT);
        rctScrittura($s, $s['e'], $d, 'incasso_rata', [[$banca, 'dare', 10000, $s['cassa']->id], [rctConto($s, 'crediti_condomini'), 'avere', 10000, null]]);
        rctScrittura($s, $s['e'], $d, 'pagamento_fornitore', [[rctConto($s, 'debiti_fornitori'), 'dare', 10000, null], [$banca, 'avere', 10000, $s['cassa']->id]]);
    }
    rctGennaioAprile($this, $s);
    vrmGiorno($this, '2027-04-15');

    $c = rctControlli($s);
    $ac = $c['anno_a_cavallo'];
    $per = collect($ac['per_tipo'])->mapWithKeys(fn ($t) => [$t['verso'].'|'.$t['tipo'] => [$t['numero'], $t['importo'], $t['rosse']]])->all();

    expect(rctNelPeriodo($s['e']))->toBeGreaterThan(108)
        ->and($ac['numero'])->toBe(108)
        ->and($ac['versi']['A'])->toBe(['numero' => 108, 'rosse' => 16, 'da_leggere' => 92])
        ->and($ac['versi']['B'])->toBe(['numero' => 0, 'rosse' => 0, 'da_leggere' => 0])
        ->and($ac['effetto']['A']['quote'])->toBe(440000)->and($ac['effetto']['A']['costi'])->toBe(320000)
        ->and($ac['effetto']['A']['frase'])->toBe('Il riepilogo di questo esercizio contiene € 4.400,00 di quote e € 3.200,00 di costi con la data del 2027.')
        ->and($per)->toBe(['A|fattura' => [12, 320000, 12], 'A|emissione' => [4, 440000, 4], 'A|incasso' => [80, 410000, 0], 'A|pagamento' => [12, 300000, 0]])
        ->and(count($ac['righe']))->toBe(50)->and($ac['righe_filtrate'])->toBe(108)
        // La norma, detta una volta; il rimedio di chi tiene aperto l'esercizio vecchio; nessun consiglio sulle date dell'esercizio, anche se il 2027 non c'è.
        ->and($ac['frase_norma'])->toContain('ci saranno quasi ogni anno')
        ->and($ac['rimedi'])->toBe([\App\Services\Gestionale\AnnoACavallo::RIMEDIO_QUI.' '.\App\Services\Gestionale\AnnoACavallo::RIMEDIO_QUI_APERTO])
        ->and($ac['fuori_da_ogni_esercizio']['prevale'])->toBeFalse()
        // Le fatture del 2027 dicono il loro anno, non «in nessun esercizio»: il 2027 non è ancora stato creato.
        ->and(collect($ac['righe'])->firstWhere('categoria', 'fattura')['nota'])->toBe('costo del 2026 con la data del 2027, un esercizio non ancora creato');

    $controllo = rctControllo($c, 'AC');
    expect($controllo['esito'])->toBe('non_quadra')->and($controllo['sinistra'])->toBe(108)
        ->and($controllo['nota'])->toContain('€ 4.400,00 di quote e € 3.200,00 di costi con la data del 2027')
        ->and($controllo['nota'])->toContain('108 (16 in rosso)')
        ->and($controllo['nota'])->not->toContain('nessun esercizio del condominio')
        ->and($controllo['azione'])->toBe('anno-a-cavallo');

    // R2 toglie dal confronto il denaro di gennaio–aprile (+ € 4.100,00 − € 3.000,00) e lo nomina: la liquidità al 31/12 resta € 5.219,00.
    $r2 = rctControllo($c, 'R2');
    expect($r2['esito'])->toBe('quadra')->and($r2['nome'])->toBe('Liquidità e cassa')
        ->and($r2['titolo'])->toBe('Liquidità della situazione = disponibilità finale delle entrate e uscite per cassa, con le registrazioni oltre il confine al giorno della loro data')
        ->and($r2['sinistra'])->toBe(521900)->and($r2['destra'])->toBe(521900)->and($r2['finale_riepilogo'])->toBe(631900)
        ->and($r2['nota'])->toContain('è € 6.319,00; comprende € 7.100,00 di registrazioni con la data dopo la fine, fra entrate e uscite: vedi il controllo');

    // I filtri: le sole rosse; un tipo; il verso B (nessuna).
    $rosse = rctControlli($s, null, ['colore' => 'rosso'])['anno_a_cavallo'];
    $emissioni = rctControlli($s, null, ['tipo' => 'emissione'])['anno_a_cavallo'];
    expect($rosse['righe_filtrate'])->toBe(16)->and(collect($rosse['righe'])->every(fn ($r) => $r['rossa']))->toBeTrue()
        ->and($emissioni['righe_filtrate'])->toBe(4)->and($emissioni['righe'][0]['data'])->toBe('2027-01-05')
        ->and(rctControlli($s, null, ['verso' => 'B'])['anno_a_cavallo']['righe_filtrate'])->toBe(0);

    // Dalla rotta, letto ad aprile 2027: nessun «Modifica l'esercizio», R2 torna.
    $pagina = rctPagina($this, $s);
    expect(collect($pagina['controlli'])->firstWhere('id', 'AC')['azione'])->toBe('anno-a-cavallo')
        ->and(collect($pagina['controlli'])->firstWhere('id', 'R2')['esito'])->toBe('quadra');
});

it('gli 80 incassi del verso A si leggono tutti: la scheda li mostra a pagine, 50 e poi 30, con il filtro del tipo; un numero di pagina enorme scritto a mano è la pagina vuota dopo l\'ultima, non un errore', function () {
    $s = vrmScenario($this);
    rctGennaioAprile($this, $s);
    vrmGiorno($this, '2027-04-15');

    $prima = rctPagina($this, $s, null, ['tipo' => 'incasso'])['anno_a_cavallo'];
    $seconda = rctPagina($this, $s, null, ['tipo' => 'incasso', 'pagina_confine' => 2])['anno_a_cavallo'];
    $enorme = rctPagina($this, $s, null, ['tipo' => 'incasso', 'pagina_confine' => '9223372036854775807'])['anno_a_cavallo'];
    $insieme = array_merge(array_column($prima['righe'], 'scrittura_id'), array_column($seconda['righe'], 'scrittura_id'));

    $venti = rctPagina($this, $s, null, ['tipo' => 'incasso', 'per_page' => 20, 'pagina_confine' => 4])['anno_a_cavallo'];

    expect($venti['pagine'])->toMatchArray(['current_page' => 4, 'last_page' => 4, 'per_page' => 20, 'from' => 61, 'to' => 80])
        ->and($prima['righe_filtrate'])->toBe(80)->and(count($prima['righe']))->toBe(50)
        ->and($prima['pagine'])->toMatchArray(['current_page' => 1, 'last_page' => 2, 'per_page' => 50, 'total' => 80, 'from' => 1, 'to' => 50])
        ->and(count($seconda['righe']))->toBe(30)->and($seconda['pagine'])->toMatchArray(['current_page' => 2, 'from' => 51, 'to' => 80])
        ->and(count(array_unique($insieme)))->toBe(80)
        ->and($enorme['pagine']['current_page'])->toBe(3)->and($enorme['righe'])->toBe([]);
});

it('il primo esercizio con pochi mesi nel programma, tenuto aperto senza il 2027: le registrazioni fuori da ogni esercizio sono più di quelle nel periodo, e il controllo dice i numeri e le due cause; R2 non le toglie dal confronto', function () {
    $s = vrmScenario($this);
    rctGennaioAprile($this, $s);
    vrmGiorno($this, '2027-04-15');
    $nel = rctNelPeriodo($s['e']);

    $pagina = rctPagina($this, $s);
    $ac = collect($pagina['controlli'])->firstWhere('id', 'AC');
    $r2 = collect($pagina['controlli'])->firstWhere('id', 'R2');

    expect($nel)->toBeLessThan(108)
        ->and($pagina['anno_a_cavallo']['fuori_da_ogni_esercizio'])->toBe(['fuori' => 108, 'nel_periodo' => $nel, 'prevale' => true])
        ->and($ac['esito'])->toBe('non_quadra')->and($ac['nota'])->toStartWith(rctFraseFuori(108, $nel))
        ->and($ac['rimedio'])->toBe(\App\Services\Gestionale\AnnoACavallo::RIMEDIO_FUORI)->and($ac['azione'])->toBe('esercizio')
        // R2 non toglie le 108: la liquidità al 31/12 (€ 5.219,00) contro la finale del prospetto per cassa (€ 6.319,00), rosso con lo stesso rimando.
        ->and($r2['esito'])->toBe('non_quadra')->and($r2['sinistra'])->toBe(521900)->and($r2['destra'])->toBe(631900)
        ->and($r2['nota'])->toContain('vedi il controllo «Registrazioni oltre il confine dell\'esercizio»')
        ->and($r2['rimedio'])->toBe(\App\Services\Gestionale\AnnoACavallo::RIMEDIO_FUORI)->and($r2['azione'])->toBe('esercizio');
});

it('il fascicolo del 2026 tenuto aperto fino all\'assemblea parla ai condòmini: il risultato in prima pagina dice che comprende il 2027, la cassa che conta le scritture dopo la fine, i controlli senza ordini all\'amministratore', function () {
    $s = vrmScenario($this);
    $banca = (int) $s['cassa']->conto_contabile_id;
    foreach (range(1, 60) as $i) {
        $d = '2026-'.str_pad((string) (2 + $i % 10), 2, '0', STR_PAD_LEFT).'-'.str_pad((string) (1 + $i % 28), 2, '0', STR_PAD_LEFT);
        rctScrittura($s, $s['e'], $d, 'incasso_rata', [[$banca, 'dare', 10000, $s['cassa']->id], [rctConto($s, 'crediti_condomini'), 'avere', 10000, null]]);
        rctScrittura($s, $s['e'], $d, 'pagamento_fornitore', [[rctConto($s, 'debiti_fornitori'), 'dare', 10000, null], [$banca, 'avere', 10000, $s['cassa']->id]]);
    }
    rctGennaioAprile($this, $s);
    vrmGiorno($this, '2027-04-15');
    $t = vrmTestiDelFascicolo($s, $s['e'], [], false);

    expect($t['frontespizio']['testo'])->toContain('8 tornano, 1 non torna, 1 da leggere.')
        ->toContain('Registrazioni oltre il confine dell\'esercizio: non torna. Il riepilogo di questo esercizio contiene € 4.400,00 di quote e € 3.200,00 di costi con la data del 2027.')
        ->toContain('Risultato: quote registrate in questo esercizio € 8.510,00 − costi € 7.331,00')
        ->toContain('Il risultato comprende anche le registrazioni scritte in questo esercizio con una data fuori dal suo periodo: le cifre sono qui sopra, accanto al controllo '
            .'«Registrazioni oltre il confine dell\'esercizio».')
        ->not->toContain('Il risultato non comprende');
    expect($t['cassa']['testo'])->toContain('Conta le scritture di questo esercizio, anche quelle con una data dopo la fine: il saldo a una data è nella situazione patrimoniale.');
    expect($t['situazione']['testo'])->toContain('Risultato di gestione € 1.179,00 (avanzo)');
    expect($t['controlli']['testo'])
        ->toContain('Una registrazione va nell\'esercizio aperto quando la si scrive, non in quello della sua data (un versamento F24 va in quello della sua delega, lo storno di un incasso in quello dell\'incasso): '
            .'per questo alcune stanno oltre il confine quasi ogni anno, e l\'elenco dice dove.')
        ->not->toContain('Ogni registrazione va nell\'esercizio aperto')
        ->toContain('Che cosa si fa: '.\App\Services\Gestionale\AnnoACavallo::RIMEDIO_QUI.' Da quando questo esercizio si chiude e si apre il successivo, le nuove vanno lì.')
        ->toContain('Le prime 50 di 108; l\'elenco intero si chiede all\'amministratore.')
        ->toContain('La disponibilità finale delle entrate e uscite per cassa è € 6.319,00; comprende € 7.100,00 di registrazioni con la data dopo la fine, fra entrate e uscite: '
            .'vedi il controllo «Registrazioni oltre il confine dell\'esercizio». Il confronto con l\'estratto conto della banca si fa a parte: il programma non può farlo.')
        ->not->toContain('(in arrivo)')->not->toContain('Come rimediare')->not->toContain('chiudi questo')->not->toContain('controllo tuo')
        ->not->toContain('nella scheda «Controlli»');
    expect($t['controlli']['html'])->toContain('<span class="badge ko">cambia il riepilogo</span>')->not->toContain('>competenza</span>');
});

it('le stampe dell\'esercizio con le registrazioni fuori da ogni esercizio: le due cause senza ordini all\'amministratore', function () {
    $s = vrmScenario($this);
    rctGennaioAprile($this, $s);
    vrmGiorno($this, '2027-04-15');
    $t = vrmTestiDelFascicolo($s, $s['e'], ['controlli'], false);

    expect($t['controlli']['testo'])->toContain('Che cosa si fa: Se le date dell\'esercizio sono sbagliate, si correggono nei dati dell\'esercizio. Se sono registrazioni di un esercizio che nel '
        .'programma non c\'è, restano qui; se è l\'esercizio dopo, le nuove vanno lì da quando questo si chiude e si apre il successivo.')
        ->not->toContain('correggile')->not->toContain('Modifica l\'esercizio')->not->toContain('chiudi questo');
    // A video l'amministratore ha il pulsante: la scheda «Controlli» lo nomina ancora.
    expect(rctControllo(rctControlli($s), 'AC')['rimedio'])->toBe(\App\Services\Gestionale\AnnoACavallo::RIMEDIO_FUORI);
});

// ─── L'anno a cavallo: il 2026 chiuso a gennaio ──────────────────────────────────────────────────────────────────────────

it('il 2026 chiuso a gennaio, la pagina del 2026: la fattura di dicembre registrata come pregressa è rossa e dice il vero; il rimedio viene dallo stato degli esercizi, non dal verso; R2 torna con «€ 40,00 + € 500,00 di registrazioni oltre il confine»', function () {
    $s = rctSecondoModo($this);
    rctPregressaDiDicembre($this, $s);
    vrmGiorno($this, '2027-03-01');

    $c = rctControlli($s);
    $ac = $c['anno_a_cavallo'];
    $righe = collect($ac['righe'])->keyBy('categoria');

    expect($ac['versi']['A'])->toBe(['numero' => 1, 'rosse' => 0, 'da_leggere' => 1])
        ->and($ac['versi']['B'])->toBe(['numero' => 2, 'rosse' => 1, 'da_leggere' => 1])
        ->and($righe['fattura_pregressa']['rossa'])->toBeTrue()
        ->and($righe['fattura_pregressa']['nota'])->toBe('registrata come pregressa: nessun riepilogo la conta fra i costi')
        ->and($righe['fattura_pregressa']['esercizio'])->toBe('Esercizio 2027')->and($righe['fattura_pregressa']['data'])->toBe('2026-12-15')
        ->and($righe['incasso']['rossa'])->toBeFalse()->and($righe['f24']['rossa'])->toBeFalse()
        ->and($ac['effetto']['B']['frase'])->toBe('Il riepilogo di questo esercizio non contiene € 1.000,00 di costi con la data di questo esercizio, registrati in un altro (di cui € 1.000,00 registrati come pregresse: nessuno dei due riepiloghi li conta fra i costi).')
        // Il 2026 è chiuso: nessun «chiudi questo esercizio»; l'F24 sta nel 2026 per la regola della delega, non perché il 2026 fosse aperto.
        ->and($ac['rimedi'])->toBe([\App\Services\Gestionale\AnnoACavallo::RIMEDIO_ALTRO, \App\Services\Gestionale\AnnoACavallo::RIMEDIO_F24])
        ->and(implode(' ', $ac['rimedi']))->not->toContain('chiudi questo esercizio')->and(implode(' ', $ac['rimedi']))->not->toContain('le conta')
        ->and(rctControllo($c, 'AC')['esito'])->toBe('non_quadra');

    // La stampa dei controlli: la pregressa ha il suo segno, «pregressa», non «competenza».
    $stampa = view('pdf.gestionale.stato_patrimoniale', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'pagina' => $c['pagina'], 'parte' => 'controlli',
        'controlli' => $c['controlli'], 'esito' => $c['esito'], 'anno_a_cavallo' => $ac])->render();
    expect($stampa)->toContain('<span class="badge ko">pregressa</span>')->and($stampa)->not->toContain('<span class="badge ko">competenza</span>')->and($stampa)->toContain('<th class="n">In rosso</th>');

    // La pagina del 2026 intera: R2 torna, con le due registrazioni nominate (l'F24 dopo la fine, l'incasso di un esercizio dopo).
    $r2 = rctControllo($c, 'R2');
    expect($r2['esito'])->toBe('quadra')
        ->and($r2['nota'])->toContain('comprende € 40,00 di registrazioni con la data dopo la fine e non comprende € 500,00 di registrazioni scritte in un esercizio dopo con la data '
            .'di questo, fra entrate e uscite: vedi il controllo');

    // Il fascicolo del 2026: il preventivo che le voci non portano più (portano quello del 2027) sta nel verbale dell'assemblea.
    $t = vrmTestiDelFascicolo($s, $s['e'], ['riepilogo'], false);
    expect($t['frontespizio']['testo'])->toContain('Preventivo nel verbale dell\'assemblea')->not->toContain('non conservato')
        // Il risultato non comprende la pregressa di dicembre, scritta nel 2027; i controlli non sono fra le parti, e la pagina non si dice.
        ->toContain('Il risultato non comprende le registrazioni con la data di questo esercizio scritte in un altro: le cifre sono qui sopra, accanto al controllo '
            .'«Registrazioni oltre il confine dell\'esercizio».')
        ->not->toContain('Il risultato comprende')->not->toContain('Il perché di ognuno')
        ->and($t['riepilogo']['testo'])->toContain('Pulizie nel verbale dell\'assemblea')->not->toContain('non conservato');
});

it('il 2026 chiuso a gennaio, la pagina del 2027 aperto: la pregressa di dicembre e l\'incasso del 28/12 stanno qui perché il 2027 era l\'esercizio aperto; l\'F24 sta nel 2026 per la regola della delega; nessun «era già chiuso» e nessun «chiudi questo esercizio»', function () {
    $s = rctSecondoModo($this);
    rctPregressaDiDicembre($this, $s);
    vrmGiorno($this, '2027-03-01');

    $pagina = rctPagina($this, $s, $s['e2027']);
    $ac = $pagina['anno_a_cavallo'];
    $rimedi = implode(' ', $ac['rimedi']);

    // La pregressa sta qui e il 2027 non la conta come costo: da leggere. Resta rossa nella pagina del 2026, dove cambia la competenza.
    expect($ac['versi']['A'])->toBe(['numero' => 2, 'rosse' => 0, 'da_leggere' => 2])->and($ac['versi']['B'])->toBe(['numero' => 1, 'rosse' => 0, 'da_leggere' => 1])
        ->and($ac['rimedi'])->toBe([\App\Services\Gestionale\AnnoACavallo::RIMEDIO_QUI, \App\Services\Gestionale\AnnoACavallo::RIMEDIO_F24])
        ->and($rimedi)->not->toContain('era già chiuso')->and($rimedi)->not->toContain('chiudi questo esercizio')->and($rimedi)->not->toContain('le conta')
        ->and(collect($ac['righe'])->firstWhere('categoria', 'fattura_pregressa')['nota'])->toBe('registrata come pregressa: nessun riepilogo la conta fra i costi')
        ->and(collect($ac['righe'])->firstWhere('categoria', 'fattura_pregressa')['rossa'])->toBeFalse()
        ->and(collect($pagina['controlli'])->firstWhere('id', 'AC')['esito'])->toBe('segnalazione');
});

it('la fattura di dicembre registrata come pregressa nel 2027: rossa nella pagina del 2026, dove cambia la competenza, e da leggere in quella del 2027, che non la conta come costo; il segno «pregressa» c\'è in tutte e due, a video e in stampa', function () {
    $s = rctSecondoModo($this);
    rctPregressaDiDicembre($this, $s);
    vrmGiorno($this, '2027-03-01');
    $dellEsercizio = fn ($e) => rctControlli($s, $e);
    $stampa = fn (array $c, $e) => view('pdf.gestionale.stato_patrimoniale', ['condominio' => $s['c'], 'esercizio' => $e, 'pagina' => $c['pagina'], 'parte' => 'controlli',
        'controlli' => $c['controlli'], 'esito' => $c['esito'], 'anno_a_cavallo' => $c['anno_a_cavallo']])->render();

    $c26 = $dellEsercizio($s['e']);
    $c27 = $dellEsercizio($s['e2027']);
    $pre = fn (array $c) => collect($c['anno_a_cavallo']['righe'])->firstWhere('categoria', 'fattura_pregressa');
    $perTipo = fn (array $c, string $verso) => collect($c['anno_a_cavallo']['per_tipo'])->first(fn ($t) => $t['verso'] === $verso && $t['tipo'] === 'fattura_pregressa');

    // Il 2026: la pregressa ha la data qui e sta nel 2027 (verso B). Rossa: il 2026 non la conta, e la sua competenza cambia.
    expect($pre($c26)['verso'])->toBe('B')->and($pre($c26)['rossa'])->toBeTrue()
        ->and($perTipo($c26, 'B')['rosse'])->toBe(1)
        ->and($c26['anno_a_cavallo']['versi']['B']['rosse'])->toBe(1)
        ->and(rctControllo($c26, 'AC')['esito'])->toBe('non_quadra')->and(rctControllo($c26, 'AC')['nota'])->toContain('(1 in rosso)')
        ->and(collect(rctControlli($s, $s['e'], ['colore' => 'rosso'])['anno_a_cavallo']['righe'])->pluck('categoria')->all())->toContain('fattura_pregressa');
    expect($stampa($c26, $s['e']))->toContain('<span class="badge ko">pregressa</span>');

    // Il 2027: la pregressa sta qui con la data del 2026 (verso A). Da leggere: nessun riepilogo la conta fra i costi, e il 2027 non cambia competenza.
    expect($pre($c27)['verso'])->toBe('A')->and($pre($c27)['rossa'])->toBeFalse()->and($pre($c27)['nota'])->toBe('registrata come pregressa: nessun riepilogo la conta fra i costi')
        ->and($perTipo($c27, 'A')['rosse'])->toBe(0)->and($perTipo($c27, 'A')['numero'])->toBe(1)
        ->and($c27['anno_a_cavallo']['versi']['A']['rosse'])->toBe(0)
        ->and(rctControllo($c27, 'AC')['esito'])->toBe('segnalazione')->and(rctControllo($c27, 'AC')['nota'])->toContain('(0 in rosso)')
        ->and(collect(rctControlli($s, $s['e2027'], ['colore' => 'rosso'])['anno_a_cavallo']['righe'])->pluck('categoria')->all())->not->toContain('fattura_pregressa')
        ->and(collect(rctControlli($s, $s['e2027'], ['colore' => 'da_leggere'])['anno_a_cavallo']['righe'])->pluck('categoria')->all())->toContain('fattura_pregressa');
    $stampa27 = $stampa($c27, $s['e2027']);
    expect($stampa27)->toContain('<span class="badge warn">pregressa</span>')->and($stampa27)->not->toContain('<span class="badge ko">pregressa</span>');
});

it('lo storno di un incasso del 30/11/2026 fatto il 10/03/2027, con il 2026 chiuso: la rettifica sta nel 2026 chiuso con la data del 2027, e le due pagine dicono la regola dello storno, non «l\'esercizio aperto era»', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-05');
    $e2027 = rctApriIl2027($s);
    vrmGiorno($this, '2027-03-10');
    $this->actingAs($this->user)->from('/x')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $s['incasso_bice']]), ['natura_storno' => 'versamento_tornato'])->assertSessionHasNoErrors();
    vrmGiorno($this, '2027-03-11');

    // Dove sta la rettifica: nell'esercizio dell'incasso che storna, chiuso, con la data di oggi.
    $storno = DB::table('scritture_contabili as sc')->join('esercizi as e', 'e.id', '=', 'sc.esercizio_id')->where('sc.condominio_id', $s['c']->id)->where('sc.tipo_movimento', 'rettifica')
        ->get(['sc.esercizio_id', 'sc.data_competenza', 'e.stato'])->all();
    expect($storno)->toHaveCount(1)->and((int) $storno[0]->esercizio_id)->toBe($s['e']->id)->and($storno[0]->stato)->toBe('chiuso')->and(substr((string) $storno[0]->data_competenza, 0, 10))->toBe('2027-03-10');

    $regola = 'Lo storno di un incasso o di un rimborso sta nell\'esercizio dell\'incasso o del rimborso che storna, anche con la data di un altro: è la regola fino alla chiusura guidata.';
    $del2026 = rctPagina($this, $s)['anno_a_cavallo'];
    $del2027 = rctPagina($this, $s, $e2027)['anno_a_cavallo'];

    expect($del2026['versi']['A']['numero'])->toBe(1)->and($del2026['versi']['B']['numero'])->toBe(0)->and($del2026['rimedi'])->toBe([$regola])
        ->and($del2027['versi']['A']['numero'])->toBe(0)->and($del2027['versi']['B']['numero'])->toBe(1)->and($del2027['rimedi'])->toBe([$regola])
        ->and(implode(' ', $del2026['rimedi']).implode(' ', $del2027['rimedi']))->not->toContain('l\'esercizio aperto era');
});

it('la figlia dell\'emissione di uno storno sta nell\'esercizio dell\'emissione, anche con la data di un altro: dice la regola dello storno; la stessa emissione senza il legame con la sua scrittura resta una scritta qui con la data di un altro esercizio', function () {
    $s = rctSecondoModo($this);
    vrmGiorno($this, '2027-03-11');
    $crediti = rctConto($s, 'crediti_condomini');
    $rate = rctConto($s, 'gestione_rate');
    $emissione = rctScrittura($s, $s['e'], '2026-04-01', 'emissione_rata', [[$crediti, 'dare', 1000, null], [$rate, 'avere', 1000, null]]);
    // La figlia che lo storno scrive per il credito restituito: nell'esercizio dell'emissione (il 2026, chiuso), con la data dello storno.
    rctScrittura($s, $s['e'], '2027-03-10', 'emissione_rata', [[$crediti, 'dare', 500, null], [$rate, 'avere', 500, null]], ['scrittura_padre_id' => $emissione]);

    $regola = 'Lo storno di un incasso o di un rimborso sta nell\'esercizio dell\'incasso o del rimborso che storna, anche con la data di un altro: è la regola fino alla chiusura guidata.';
    $conPadre = rctPagina($this, $s)['anno_a_cavallo']['rimedi'];
    // L'F24 e l'incasso del 28/12 dello scenario stanno nei loro rimedi; la figlia nel suo, e non in quello di chi è «scritta qui».
    expect($conPadre)->toContain($regola)->and($conPadre)->toContain(\App\Services\Gestionale\AnnoACavallo::RIMEDIO_F24)
        ->and($conPadre)->not->toContain(\App\Services\Gestionale\AnnoACavallo::RIMEDIO_QUI);

    // Senza il legame con l'emissione è un'emissione qualunque scritta nel 2026 con la data del 2027: il rimedio di prima.
    DB::table('scritture_contabili')->where('scrittura_padre_id', $emissione)->update(['scrittura_padre_id' => null]);
    $senzaPadre = rctPagina($this, $s)['anno_a_cavallo']['rimedi'];
    expect($senzaPadre)->toContain(\App\Services\Gestionale\AnnoACavallo::RIMEDIO_QUI)->and($senzaPadre)->not->toContain($regola);
});

it('il 2026 chiuso a gennaio, la fattura di dicembre senza la spunta: rossa, «costo del 2027 con la data del 2026»; senza fatture l\'esito non dice «non torna» per l\'F24 e l\'incasso', function () {
    $s = rctSecondoModo($this);
    vrmGiorno($this, '2027-01-20');
    $senza = rctControlli($s);
    expect(rctControllo($senza, 'AC')['esito'])->toBe('segnalazione')
        ->and(rctControllo($senza, 'R2')['esito'])->toBe('quadra')
        ->and($senza['esito']['non_tornano'])->toBe(0);

    vrmGiorno($this, '2027-02-10');
    $s27 = array_merge($s, ['e' => $s['e2027']]);
    vrmFattura($this, $s27, $s['fornitore'], 'PUL-DIC', [[$s['pulizie']->id, 1000.00]], '2026-12-15');
    vrmGiorno($this, '2027-03-01');
    $c = rctControlli($s);
    $riga = collect($c['anno_a_cavallo']['righe'])->firstWhere('categoria', 'fattura');

    expect($riga['rossa'])->toBeTrue()->and($riga['verso'])->toBe('B')
        ->and($riga['nota'])->toBe('costo del 2027 con la data del 2026')
        ->and($c['anno_a_cavallo']['effetto']['B']['costi'])->toBe(100000)
        ->and(rctControllo($c, 'AC')['esito'])->toBe('non_quadra');
});

// ─── R2 e «Modifica l'esercizio» ─────────────────────────────────────────────────────────────────────────────────────────

it('la pagina del 2027 con l\'incasso del 28/12/2026 che sta nel 2026: R2 torna, e né R2 né il controllo propongono «Modifica l\'esercizio»', function () {
    $s = rctSecondoModo($this);
    vrmGiorno($this, '2027-02-15');
    $pagina = rctPagina($this, $s, $s['e2027']);
    $ac = collect($pagina['controlli'])->firstWhere('id', 'AC');
    $r2 = collect($pagina['controlli'])->firstWhere('id', 'R2');

    expect($pagina['anno_a_cavallo']['versi']['A']['numero'])->toBe(1)
        ->and($pagina['anno_a_cavallo']['fuori_da_ogni_esercizio'])->toBe(['fuori' => 0, 'nel_periodo' => 0, 'prevale' => false])
        ->and($ac['azione'])->not->toBe('esercizio')->and($ac['nota'])->not->toContain('nessun esercizio del condominio')
        ->and($r2['esito'])->toBe('quadra')->and($r2['azione'])->not->toBe('esercizio');
});

it('Via delle Acacie com\'era, dalla rotta: sei registrazioni fuori da ogni esercizio e nessuna nel periodo; il controllo dice i numeri e le due cause, R2 resta rosso con lo stesso rimando; le cancellate e l\'altro condominio non contano', function () {
    $s = rctAcacie($this);
    vrmGiorno($this, '2026-09-12');
    $pagina = rctPagina($this, $s);
    $ac = collect($pagina['controlli'])->firstWhere('id', 'AC');
    $r2 = collect($pagina['controlli'])->firstWhere('id', 'R2');
    $ids = array_column($pagina['anno_a_cavallo']['righe'], 'scrittura_id');

    expect($pagina['anno_a_cavallo']['fuori_da_ogni_esercizio'])->toBe(['fuori' => 6, 'nel_periodo' => 0, 'prevale' => true])
        ->and($ac['esito'])->toBe('non_quadra')->and($ac['nota'])->toStartWith(rctFraseFuori(6, 0))
        ->and($ac['rimedio'])->toBe(\App\Services\Gestionale\AnnoACavallo::RIMEDIO_FUORI)->and($ac['azione'])->toBe('esercizio')
        ->and($r2['esito'])->toBe('non_quadra')->and($r2['sinistra'])->toBe(500000)->and($r2['destra'])->toBe(560000)
        ->and($r2['rimedio'])->toBe(\App\Services\Gestionale\AnnoACavallo::RIMEDIO_FUORI)->and($r2['azione'])->toBe('esercizio')
        ->and(count($ids))->toBe(6)->and($ids)->not->toContain($s['cancellata_fuori'])->and($ids)->not->toContain($s['altra_registrazione']);
});

it('Via delle Acacie a metà: con la fattura di dicembre registrata a gennaio nel periodo sbagliato il consiglio resta, e R2 resta rosso', function () {
    $s = rctAcacie($this, fatturaDiDicembre: true);
    vrmGiorno($this, '2026-09-12');
    $pagina = rctPagina($this, $s);
    $ac = collect($pagina['controlli'])->firstWhere('id', 'AC');
    $r2 = collect($pagina['controlli'])->firstWhere('id', 'R2');

    expect($pagina['anno_a_cavallo']['fuori_da_ogni_esercizio'])->toBe(['fuori' => 6, 'nel_periodo' => 1, 'prevale' => true])
        // Dopo i numeri e le due cause, l'effetto sul riepilogo: le tre emissioni da € 300,00 del 2026 scritte in questo esercizio (gli incassi non portano quote).
        ->and($ac['nota'])->toBe(rctFraseFuori(6, 1).' Il riepilogo di questo esercizio contiene € 900,00 di quote con la data del 2026.')
        ->and($ac['azione'])->toBe('esercizio')
        ->and($r2['esito'])->toBe('non_quadra')->and($r2['azione'])->toBe('esercizio')
        ->and(implode(' ', $pagina['anno_a_cavallo']['rimedi']))->not->toContain('chiudi questo esercizio');
});

it('lo specchio di Via delle Acacie, dalla rotta: un esercizio 2027 con una scrittura del 2026 che non sta in nessun esercizio, letto dopo l\'inizio: R2 torna, e il controllo dice i numeri e le due cause', function () {
    $s = be52Condominio();
    $s['e']->update(['data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'nome' => 'Esercizio 2027']);
    $banca = (int) $s['cassa']->conto_contabile_id;
    $crediti = rctConto($s, 'crediti_condomini');
    rctScrittura($s, $s['e']->fresh(), '2026-02-01', 'incasso_rata', [[$banca, 'dare', 50000, $s['cassa']->id], [$crediti, 'avere', 50000, null]]);
    vrmGiorno($this, '2027-06-01');

    $pagina = rctPagina($this, $s, $s['e']->fresh());
    $ac = collect($pagina['controlli'])->firstWhere('id', 'AC');

    expect(collect($pagina['controlli'])->firstWhere('id', 'R2')['esito'])->toBe('quadra')
        ->and($pagina['anno_a_cavallo']['fuori_da_ogni_esercizio'])->toBe(['fuori' => 1, 'nel_periodo' => 0, 'prevale' => true])
        ->and($ac['esito'])->toBe('non_quadra')->and($ac['nota'])->toStartWith(rctFraseFuori(1, 0))->and($ac['azione'])->toBe('esercizio')
        ->and($pagina['anno_a_cavallo']['righe'][0]['nota'])->toBe('la data non sta in nessun esercizio del condominio');
});

it('il primo esercizio di un condominio migrato, con le sole fatture pregresse: nessun «Modifica l\'esercizio»; le pregresse sono da leggere e dicono il vero, e l\'esito non dice «non torna»; lo stesso con un incasso nel periodo', function () {
    vrmGiorno($this, '2026-01-20');
    $s = be52Condominio();
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    rctPregressa($this, $s, $s['e'], '2025-11-30', 500.00, 'PRE-1');
    rctPregressa($this, $s, $s['e'], '2025-06-30', 300.00, 'PRE-2');

    $soloPregresse = rctPagina($this, $s);
    $ac = collect($soloPregresse['controlli'])->firstWhere('id', 'AC');
    expect($soloPregresse['anno_a_cavallo']['fuori_da_ogni_esercizio'])->toBe(['fuori' => 0, 'nel_periodo' => 0, 'prevale' => false])
        ->and($ac['azione'])->not->toBe('esercizio')->and($ac['nota'])->not->toContain('Le cause possibili')
        ->and($ac['esito'])->toBe('segnalazione')
        ->and($soloPregresse['anno_a_cavallo']['versi']['A'])->toBe(['numero' => 2, 'rosse' => 0, 'da_leggere' => 2])
        ->and(collect($soloPregresse['anno_a_cavallo']['righe'])->pluck('rossa')->unique()->values()->all())->toBe([false])
        ->and(collect($soloPregresse['anno_a_cavallo']['righe'])->pluck('nota')->unique()->values()->all())->toBe(['registrata come pregressa: nessun riepilogo la conta fra i costi'])
        ->and($soloPregresse['anno_a_cavallo']['rimedi'])->toBe([]);

    // L'esito della Sintesi, dalla sua rotta: il controllo c'è e dice «da leggere»; nessun «non torna» per quelle righe.
    $sintesi = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.sintesi', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['sintesi']['esito'];
    expect(collect($sintesi['righe'])->firstWhere('id', 'AC')['esito'])->toBe('segnalazione')
        ->and(collect($sintesi['righe'])->where('esito', 'non_quadra')->pluck('id')->all())->not->toContain('AC')
        ->and($sintesi['non_tornano'])->toBe(0)->and($sintesi['frase'])->toContain('0 non tornano')->and($sintesi['frase'])->toContain('1 da leggere');

    vrmGiorno($this, '2026-02-10');
    rctScrittura($s, $s['e'], '2026-02-10', 'incasso_rata', [[(int) $s['cassa']->conto_contabile_id, 'dare', 10000, $s['cassa']->id], [rctConto($s, 'crediti_condomini'), 'avere', 10000, null]]);
    $conIncasso = rctPagina($this, $s);
    expect($conIncasso['anno_a_cavallo']['fuori_da_ogni_esercizio'])->toBe(['fuori' => 0, 'nel_periodo' => 1, 'prevale' => false])
        ->and(collect($conIncasso['controlli'])->firstWhere('id', 'AC')['azione'])->not->toBe('esercizio');
});

it('una pregressa con una parte non coperta dai saldi iniziali scrive un costo vero (la sopravvenienza passiva): nella pagina dell\'esercizio in cui sta resta rossa, e la frase dell\'effetto ne dice la cifra', function () {
    vrmGiorno($this, '2026-01-20');
    $s = be52Condominio();
    rctPregressaConParteNonCoperta($this, $s, $s['e'], '2025-11-30', 'PRE-SOP');
    rctPregressa($this, $s, $s['e'], '2025-06-30', 300.00, 'PRE-2');

    $ac = rctPagina($this, $s)['anno_a_cavallo'];
    $righe = collect($ac['righe'])->keyBy('protocollo');
    $conCosto = collect($ac['righe'])->firstWhere('data', '2025-11-30');
    $senzaCosto = collect($ac['righe'])->firstWhere('data', '2025-06-30');

    // Quella con il costo è rossa (l'esercizio lo conta, con la data del 2025); quella che scrive solo sulle passate gestioni è da leggere.
    expect($ac['versi']['A'])->toBe(['numero' => 2, 'rosse' => 1, 'da_leggere' => 1])
        ->and($conCosto['categoria'])->toBe('fattura_pregressa')->and($conCosto['rossa'])->toBeTrue()
        ->and($senzaCosto['categoria'])->toBe('fattura_pregressa')->and($senzaCosto['rossa'])->toBeFalse()
        ->and($ac['effetto']['A']['costi'])->toBe(30000)
        ->and($ac['effetto']['A']['frase'])->toBe('Il riepilogo di questo esercizio contiene € 300,00 di costi con la data del 2025.');
});

it('una pregressa con una parte non coperta: la nota della riga dice che quella parte è un costo dell\'esercizio in cui sta, e la pagina dell\'esercizio della sua data conta la fattura una volta sola', function () {
    vrmGiorno($this, '2026-01-20');
    $s = be52Condominio();
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'chiuso']);
    // € 500,00 registrati nel 2026 con la data del 30/11/2025: € 200,00 coperti dalle passate gestioni, € 300,00 di sopravvenienza passiva (un costo del 2026).
    rctPregressaConParteNonCoperta($this, $s, $s['e'], '2025-11-30', 'PRE-SOP');
    $nota = 'registrata come pregressa: nessun riepilogo la conta fra i costi, tranne la parte non coperta, € 300,00, che è un costo dell\'esercizio in cui sta';

    $qui = rctPagina($this, $s)['anno_a_cavallo'];
    $la = rctPagina($this, $s, $e2025)['anno_a_cavallo'];
    expect($qui['righe'][0]['nota'])->toBe($nota)
        ->and($la['righe'][0]['nota'])->toBe($nota)
        ->and($la['effetto']['B']['frase'])->toBe('Il riepilogo di questo esercizio non contiene € 500,00 di costi con la data di questo esercizio, registrati in un altro (di cui € 500,00 registrati '
            .'come pregresse: nessuno dei due riepiloghi li conta fra i costi, tranne la parte non coperta, € 300,00, che è un costo dell\'esercizio in cui stanno).')
        // Le cifre della pagina non cambiano: € 300,00 di costi e € 500,00 di pregresse.
        ->and(array_diff_key($la['effetto']['B'], ['frase' => 1]))->toBe(['quote' => 0, 'costi' => 30000, 'entrate' => 0, 'pregresse' => 50000]);
    // La parte non coperta sta fra le spese del riepilogo del 2026, come dice la stampa del riepilogo; i € 200,00 coperti no.
    $riepilogo = app(\App\Services\Gestionale\RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']);
    expect($riepilogo['riconciliazione']['capitoli_netti'])->toBe(30000)->and($riepilogo['risultato']['costi'])->toBe(30000);

    // Accanto, una pregressa di € 300,00 coperta per intero: la sua nota è quella di sempre, e il totale delle pregresse è € 800,00.
    rctPregressa($this, $s, $s['e'], '2025-06-30', 300.00, 'PRE-2');
    $la = rctPagina($this, $s, $e2025)['anno_a_cavallo'];
    expect(collect($la['righe'])->firstWhere('data', '2025-06-30')['nota'])->toBe(\App\Services\Gestionale\AnnoACavallo::NOTA_PREGRESSA)
        ->and($la['effetto']['B']['frase'])->toBe('Il riepilogo di questo esercizio non contiene € 800,00 di costi con la data di questo esercizio, registrati in un altro (di cui € 800,00 registrati '
            .'come pregresse: nessuno dei due riepiloghi li conta fra i costi, tranne la parte non coperta, € 300,00, che è un costo dell\'esercizio in cui stanno).');
});

it('una fattura (non pregressa) che nel giornale non ha nessuna riga di costo resta rossa nella pagina dell\'esercizio in cui sta: il rosso viene dall\'essere una fattura, e solo la pregressa fa eccezione', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-12');
    $fattura = vrmFattura($this, $s, $s['fornitore'], 'F27-SENZA-COSTO', [[$s['pulizie']->id, 250.00]], '2027-01-12');
    $scritturaId = (int) DB::table('fattura_scrittura')->where('fattura_passiva_id', $fattura->id)->where('tipo', 'competenza')->value('scrittura_contabile_id');
    $costi = DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('tipo', 'costo')->pluck('id');
    DB::table('righe_scritture')->where('scrittura_id', $scritturaId)->whereIn('conto_contabile_id', $costi)->update(['conto_contabile_id' => rctConto($s, 'passate_gestioni')]);

    $riga = collect(rctControlli($s)['anno_a_cavallo']['righe'])->firstWhere('scrittura_id', $scritturaId);

    expect($riga['categoria'])->toBe('fattura')->and($riga['verso'])->toBe('A')->and($riga['rossa'])->toBeTrue();
});

it('un esercizio nuovo e vuoto: nessuna registrazione oltre il confine, nessun consiglio', function () {
    vrmGiorno($this, '2026-01-05');
    $s = be52Condominio();
    $ac = collect(rctPagina($this, $s)['controlli'])->firstWhere('id', 'AC');

    expect($ac['esito'])->toBe('quadra')->and($ac['azione'])->toBeNull();
});

// ─── Il colore, le categorie e i confini ─────────────────────────────────────────────────────────────────────────────────

it('il colore viene dalle righe: una regolazione, la commissione di un pagamento e un incasso da terzi del 2027 nel 2026 aperto sono rossi; un pagamento senza commissione è da leggere; l\'incasso da terzi è un\'entrata, non un costo negativo', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['bancarie']->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => null,
        'data_operazione' => '2027-01-20', 'causale' => 'Bolli di gennaio', 'importo' => 12.00,
    ])->assertSessionHasNoErrors();
    vrmPaga($this, $s, $s['fornitore'], [[$s['ft1'], 'pagamento', 25000]], '2027-01-20', 250, $s['bancarie']->id);
    vrmPaga($this, $s, $s['fornitore'], [[$s['ft1'], 'pagamento', 25000]], '2027-01-20');
    $this->actingAs($this->user)->post(route('admin.gestionale.incassi-da-terzi.store', [$s['c']]), [
        'esercizio_id' => $s['e']->id, 'conto_id' => $s['antenna']->id, 'cassa_id' => $s['cassa']->id, 'data_operazione' => '2027-01-20',
        'importo' => 240.00, 'anagrafica_id' => null, 'causale' => 'Affitto antenna 2027',
    ])->assertSessionHasNoErrors();
    vrmGiorno($this, '2027-01-25');

    $ac = rctControlli($s)['anno_a_cavallo'];
    $per = collect($ac['per_tipo'])->mapWithKeys(fn ($t) => [$t['verso'].'|'.$t['tipo'] => [$t['numero'], $t['importo'], $t['rosse']]])->all();

    expect($per)->toBe(['A|regolazione' => [1, 1200, 1], 'A|incasso_da_terzi' => [1, 24000, 1], 'A|pagamento' => [2, 50250, 1]])
        ->and($ac['effetto']['A']['costi'])->toBe(1450)->and($ac['effetto']['A']['entrate'])->toBe(24000)
        ->and($ac['effetto']['A']['frase'])->toBe('Il riepilogo di questo esercizio contiene € 14,50 di costi e € 240,00 di entrate da terzi con la data del 2027.');

    // La stampa dei controlli chiama la regolazione con le parole di chi legge il foglio, nella tabella per tipo e nella riga; la pagina la chiama col suo nome.
    $stampa = vrmTestiDelFascicolo($s, $s['e'], ['controlli'], false)['controlli']['html'];
    expect(substr_count($stampa, 'Spese pagate subito, senza fattura'))->toBe(2)->and($stampa)->not->toContain('Regolazioni immediate')
        ->and(collect($ac['righe'])->firstWhere('categoria', 'regolazione')['etichetta'])->toBe('Regolazioni immediate');
});

it('il confine è il giorno: il 01/01 e il 31/12 dell\'esercizio stanno dentro, il giorno dopo la fine sta fuori, nei due versi', function () {
    vrmGiorno($this, '2026-01-02');
    $s = be52Condominio();
    $e2027 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'chiuso']);
    legaAEsercizio($e2027, $s['g']->id);
    $banca = (int) $s['cassa']->conto_contabile_id;
    $crediti = rctConto($s, 'crediti_condomini');
    $incasso = fn (Esercizio $e, string $d) => rctScrittura($s, $e, $d, 'incasso_rata', [[$banca, 'dare', 1000, $s['cassa']->id], [$crediti, 'avere', 1000, null]]);
    $primo = $incasso($s['e'], '2026-01-01');
    $ultimo = $incasso($s['e'], '2026-12-31');
    $dopoNel2026 = $incasso($s['e'], '2027-01-01');
    $fineNel2027 = $incasso($e2027, '2026-12-31');
    $inizioNel2027 = $incasso($e2027, '2027-01-01');
    vrmGiorno($this, '2027-01-02');

    $del2026 = collect(rctControlli($s)['anno_a_cavallo']['righe'])->mapWithKeys(fn ($r) => [$r['scrittura_id'] => $r['verso']])->all();
    $del2027 = collect(rctControlli($s, $e2027)['anno_a_cavallo']['righe'])->keyBy('scrittura_id');

    $aperto = rctControlli($s)['anno_a_cavallo']['rimedi'];
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $chiuso = rctControlli($s, $s['e']->fresh())['anno_a_cavallo']['rimedi'];

    // Il rimedio dallo stato: con il 2026 aperto e una registrazione dopo la sua fine, «chiudi e apri il successivo»; con il 2026 chiuso, no.
    expect($aperto)->toBe([\App\Services\Gestionale\AnnoACavallo::RIMEDIO_QUI.' '.\App\Services\Gestionale\AnnoACavallo::RIMEDIO_QUI_APERTO, \App\Services\Gestionale\AnnoACavallo::RIMEDIO_ALTRO])
        ->and($chiuso)->toBe([\App\Services\Gestionale\AnnoACavallo::RIMEDIO_QUI, \App\Services\Gestionale\AnnoACavallo::RIMEDIO_ALTRO]);

    expect($del2026)->toBe([$fineNel2027 => 'B', $dopoNel2026 => 'A'])
        ->and($del2027->keys()->sort()->values()->all())->toBe(collect([$fineNel2027, $dopoNel2026])->sort()->values()->all())
        ->and($del2027[$fineNel2027]['verso'])->toBe('A')->and($del2027[$fineNel2027]['senza_esercizio'])->toBeFalse()->and($del2027[$fineNel2027]['nota'])->toBeNull()
        ->and($del2027[$dopoNel2026]['verso'])->toBe('B')
        ->and(array_keys($del2026))->not->toContain($primo)->and(array_keys($del2026))->not->toContain($ultimo)->and($del2027->keys()->all())->not->toContain($inizioNel2027);
});

it('le categorie della tabella per tipo: la nota di credito, il giroconto, lo storno di un incasso fra gli storni, l\'uso di un credito fra gli incassi delle rate', function () {
    $s = vrmScenario($this);
    $banca = (int) $s['cassa']->conto_contabile_id;
    $crediti = rctConto($s, 'crediti_condomini');
    rctScrittura($s, $s['e'], '2027-01-10', 'nota_credito_fornitore', [[rctConto($s, 'debiti_fornitori'), 'dare', 5000, null], [rctConto($s, 'costi_servizi'), 'avere', 5000, null]]);
    rctScrittura($s, $s['e'], '2027-01-11', 'giroconto', [[$banca, 'dare', 1000, $s['cassa']->id], [$banca, 'avere', 1000, $s['cassa']->id]]);
    $incasso = rctScrittura($s, $s['e'], '2027-01-12', 'incasso_rata', [[$banca, 'dare', 2000, $s['cassa']->id], [$crediti, 'avere', 2000, null]]);
    rctScrittura($s, $s['e'], '2027-02-01', 'rettifica', [[$crediti, 'dare', 2000, null], [$banca, 'avere', 2000, $s['cassa']->id]], ['scrittura_padre_id' => $incasso]);
    rctScrittura($s, $s['e'], '2027-01-13', 'storno_credito', [[rctConto($s, 'anticipi_condomini'), 'dare', 3000, null], [$crediti, 'avere', 3000, null]]);
    vrmGiorno($this, '2027-02-10');

    $per = collect(rctControlli($s)['anno_a_cavallo']['per_tipo'])->mapWithKeys(fn ($t) => [$t['tipo'] => [$t['etichetta'], $t['numero'], $t['rosse']]])->all();

    expect($per)->toBe([
        'nota_credito' => ['Note di credito', 1, 1],
        'incasso' => ['Incassi delle rate', 2, 0],
        'giroconto' => ['Giroconti e accantonamenti', 1, 0],
        'storno' => ['Storni', 1, 0],
    ]);
});

// ─── I costi tornano con il riepilogo ────────────────────────────────────────────────────────────────────────────────────

it('i costi tornano con il riepilogo: Via Roma € 4.131,00 = € 3.978,50 + € 2,50 + € 150,00', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $cr = rctControllo(rctControlli($s), 'CR');

    expect($cr['esito'])->toBe('quadra')->and($cr['sinistra'])->toBe(413100)->and($cr['destra'])->toBe(413100)
        ->and($cr['nota'])->toContain('€ 4.131,00')->and($cr['nota'])->toContain('€ 3.978,50')
        ->and($cr['nota'])->toContain('€ 2,50')->and($cr['nota'])->toContain('€ 150,00');
});

it('i costi tornano con il riepilogo: € 1.514,50 = € 1.012,00 + € 2,50 + € 500,00 (una fattura pagata con la commissione senza voce, una contestata, una regolazione)', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-03-10');
    $fa = vrmFattura($this, $s, $s['fornitore'], 'FT-A', [[$s['manutenzioni']->id, 1000.00]], '2026-03-10');
    vrmFattura($this, $s, $s['fornitore'], 'FT-C', [[$s['manutenzioni']->id, 500.00]], '2026-03-10', ['stato_approvazione' => 'contestata']);
    $pag = vrmPaga($this, $s, $s['fornitore'], [[$fa, 'pagamento', 100000]], '2026-03-10', 250, $s['bancarie']->id);
    vrmCommissioneVecchia($s, $pag);
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['bancarie']->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => null,
        'data_operazione' => '2026-03-10', 'causale' => 'Bolli', 'importo' => 12.00,
    ])->assertSessionHasNoErrors();
    $cr = rctControllo(rctControlli($s), 'CR');

    expect($cr['esito'])->toBe('quadra')->and($cr['sinistra'])->toBe(151450)->and($cr['destra'])->toBe(151450)
        ->and($cr['nota'])->toContain('€ 1.012,00')->and($cr['nota'])->toContain('€ 2,50')->and($cr['nota'])->toContain('€ 500,00');
});

it('una riga di costo senza voce che non è una commissione: «costi non classificati € 75,00», e i costi non tornano', function () {
    $s = vrmScenario($this);
    $mastroPulizie = (int) DB::table('conti')->where('id', $s['pulizie']->id)->value('conto_contabile_id');
    rctScrittura($s, $s['e'], '2026-12-20', 'fattura_acquisto', [[$mastroPulizie, 'dare', 7500, null], [rctConto($s, 'debiti_fornitori'), 'avere', 7500, null]]);
    vrmGiorno($this, '2027-01-15');
    $cr = rctControllo(rctControlli($s), 'CR');

    expect($cr['esito'])->toBe('non_quadra')->and($cr['sinistra'])->toBe(420600)->and($cr['destra'])->toBe(413100)
        ->and($cr['nota'])->toContain('costi non classificati € 75,00')
        // Il programma dà una voce solo alle commissioni: per una riga di costo qualsiasi la strada è lo storno e una registrazione nuova con la voce.
        ->and($cr['rimedio'])->toContain('apri le scritture nel Libro Giornale')->and($cr['rimedio'])->toContain('stornando la registrazione e rifacendola con la voce')
        ->and($cr['rimedio'])->not->toContain('aprile')->and($cr['rimedio'])->not->toContain('assegna la voce alla spesa')->and($cr['azione'])->toBe('riepilogo');
});

// ─── I crediti tornano con le posizioni ──────────────────────────────────────────────────────────────────────────────────

it('i crediti tornano con le posizioni in tutte e quattro le righe: la fattura ad personam, le emissioni, l\'incasso, la seconda fattura', function () {
    $s = b59apBase();
    $s['g'] ??= \App\Models\Gestione::find(DB::table('esercizio_gestione')->where('esercizio_id', $s['e']->id)->value('gestione_id'));
    $ft = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $this->travelTo('2026-03-20 10:00:00');
    $cp = fn () => rctControllo(rctControlli($s), 'CP');
    $prima = $cp();
    expect($prima['esito'])->toBe('quadra')->and($prima['sinistra'])->toBe(122000)->and($prima['destra'])->toBe(122000);

    $piano = b59apPiano($this, $s, $ft);
    b59apEmetti($this, $s, $piano, [1, 2]);
    $this->travelTo('2026-04-05 10:00:00');
    expect($cp()['esito'])->toBe('quadra')->and($cp()['sinistra'])->toBe(122000);

    $this->travelTo('2026-04-15 10:00:00');
    b59apIncassa($this, $s, $piano, $s['persone']['Mario Rossi'] ?? array_values($s['persone'])[0], [1 => 61000]);
    $this->travelTo('2026-04-20 10:00:00');
    expect($cp()['esito'])->toBe('quadra')->and($cp()['sinistra'])->toBe(61000);

    b59apFattura($this, $s, [b59apRigaAdPersonam($s, 500.00, 0, '1', 'Serratura')], 'FT-314');
    $this->travelTo('2026-04-25 10:00:00');
    $dopo = $cp();
    expect($dopo['esito'])->toBe('quadra')->and($dopo['sinistra'])->toBe(111000)->and($dopo['destra'])->toBe(111000)
        ->and($dopo['nota'])->toContain('spese anticipate per singole unità € 500,00');
});

it('i crediti tornano con le posizioni anche con un anticipo di € 50,00, che sta nella sua riga con il conto «Anticipi»', function () {
    $s = vrmScenario($this);
    $anticipi = rctConto($s, 'anticipi_condomini');
    $sc = rctScrittura($s, $s['e'], '2026-12-20', 'incasso_rata', [[(int) $s['cassa']->conto_contabile_id, 'dare', 5000, $s['cassa']->id], [$anticipi, 'avere', 5000, null]]);
    DB::table('righe_scritture')->where('scrittura_id', $sc)->where('conto_contabile_id', $anticipi)->update(['anagrafica_id' => $s['persone']['ugo']->id]);
    vrmGiorno($this, '2027-01-15');
    $cp = rctControllo(rctControlli($s), 'CP');

    expect($cp['esito'])->toBe('quadra')->and($cp['sinistra'])->toBe(41000)->and($cp['destra'])->toBe(41000)
        ->and($cp['nota'])->toContain('Anticipi ricevuti € 50,00');
});

it('quando le righe del § 9 hanno una differenza, i crediti non tornano e il controllo dice quale e di quanto', function () {
    $crediti = ControlliDelRendiconto::crediti(['crediti' => 42000, 'posizioni' => 41000, 'spese_anticipate' => 0, 'differenza' => 1000], ['anticipi' => 0, 'conto' => 0, 'differenza' => 0]);
    $anticipi = ControlliDelRendiconto::crediti(['crediti' => 41000, 'posizioni' => 41000, 'spese_anticipate' => 0, 'differenza' => 0], ['anticipi' => 5000, 'conto' => 7000, 'differenza' => 2000]);

    expect($crediti['esito'])->toBe('non_quadra')->and($crediti['sinistra'])->toBe(42000)->and($crediti['destra'])->toBe(41000)
        ->and($crediti['nota'])->toContain('Differenza dei crediti € 10,00')->and($crediti['rimedio'])->not->toBeNull()->and($crediti['azione'])->toBe('condomini')
        ->and($anticipi['esito'])->toBe('non_quadra')->and($anticipi['nota'])->toContain('Differenza degli anticipi € 20,00')
        ->and($anticipi['nota'])->toContain('Anticipi ricevuti € 50,00 ≠ conto «Anticipi ricevuti dai condòmini» € 70,00');
});

// ─── L'esito ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('l\'esito di Via Roma finito: «9 tornano, 0 non tornano, 1 da leggere» con la riga fissa; R8 di un anno finito è da leggere con la sua frase', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-15');
    $c = rctControlli($s);
    $r8 = rctControllo($c, 'R8');

    expect($c['esito']['tornano'])->toBe(9)->and($c['esito']['non_tornano'])->toBe(0)->and($c['esito']['da_leggere'])->toBe(1)
        ->and($c['esito']['frase'])->toBe('9 tornano, 0 non tornano, 1 da leggere')
        ->and($c['esito']['riga_fissa'])->toBe('I controlli verificano che i numeri del programma tornino fra loro. Non verificano il saldo con l\'estratto conto della banca, né che ogni fattura e ogni entrata sia stata registrata.')
        ->and($r8['esito'])->toBe('segnalazione')
        ->and($r8['nota'])->toContain('il residuo vincolato dei fondi si legge con il consuntivo dei fondi (in arrivo)')
        ->and(collect($c['controlli'])->pluck('id')->all())->toBe(['PD', 'R6', 'R7', 'R8', 'R2', 'CN', 'AC', 'CR', 'CP', 'GE']);
});

it('una fattura con voci di un\'altra gestione è da leggere nei controlli, con il suo numero', function () {
    $s = vrmScenario($this);
    $facciata = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Facciata', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $facciata->id);
    $pcF = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $facciata->id, 'nome' => 'Piano facciata']);
    $ponteggio = be52Voce($this, $s, $pcF, 'Ponteggio', 'spesa', '5.000,00');
    vrmGiorno($this, '2026-12-20');
    vrmFattura($this, $s, $s['fornitore'], 'FF-ALTRA', [[$ponteggio->id, 100.00]], '2026-12-20');
    vrmGiorno($this, '2027-01-15');
    $ge = rctControllo(rctControlli($s), 'GE');

    expect($ge['esito'])->toBe('segnalazione')->and($ge['sinistra'])->toBe(1)->and($ge['nota'])->toContain('FF-ALTRA');
});

it('l\'esito al singolare: «1 torna, 1 non torna, 0 da leggere»', function () {
    $e = ControlliDelRendiconto::esito([['id' => 'A', 'nome' => 'a', 'esito' => 'quadra'], ['id' => 'B', 'nome' => 'b', 'esito' => 'non_quadra']]);

    expect($e['frase'])->toBe('1 torna, 1 non torna, 0 da leggere')
        ->and($e['righe'])->toBe([['id' => 'A', 'nome' => 'a', 'esito' => 'quadra', 'breve' => null], ['id' => 'B', 'nome' => 'b', 'esito' => 'non_quadra', 'breve' => null]]);
});

it('l\'accantonamento del già versato scritto alle 00:30 di Roma del 01/01/2027 ha la data del 01/01/2027, il giorno dell\'amministratore: sta oltre il confine del 2026', function () {
    $s = be52Condominio();
    be52MastriPregresso($s);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2027-01-01 00:30', 'Europe/Rome'));
    expect(app(\App\Actions\Cassa\RegistraContributoInCassaAction::class)->execute($s['cassa']->fresh(), 5000, 'Già versato, ancora in cassa'))->toBeTrue();
    $sc = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'accantonamento')->first();

    expect(substr((string) $sc->data_competenza, 0, 10))->toBe('2027-01-01')->and(substr((string) $sc->data_registrazione, 0, 10))->toBe('2027-01-01')
        ->and(collect(rctControlli($s)['anno_a_cavallo']['righe'])->pluck('categoria')->all())->toBe(['giroconto']);
});

it('l\'anno dopo l\'ultimo esercizio: il 31/12 è ancora «un esercizio non ancora creato», il giorno dopo non sta in nessun esercizio', function () {
    $s = vrmScenario($this);
    $fattura = fn (string $data) => rctScrittura($s, $s['e'], $data, 'fattura_acquisto', [[rctConto($s, 'costi_servizi'), 'dare', 1000, null], [rctConto($s, 'debiti_fornitori'), 'avere', 1000, null]]);
    $fattura('2027-12-31');
    $fattura('2028-01-01');
    vrmGiorno($this, '2027-01-15');
    $righe = collect(rctControlli($s)['anno_a_cavallo']['righe'])->keyBy('data');

    // L'ultimo esercizio finisce il 31/12/2026: l'anno dopo va dal 01/01/2027 al 31/12/2027.
    expect($righe['2027-12-31']['nota'])->toBe('costo del 2026 con la data del 2027, un esercizio non ancora creato')->and($righe['2027-12-31']['senza_esercizio'])->toBeTrue()
        ->and($righe['2028-01-01']['nota'])->toBe('la data non sta in nessun esercizio del condominio')->and($righe['2028-01-01']['senza_esercizio'])->toBeTrue();
});

it('una fattura registrata come pregressa nel 2027 con la data di dicembre, senza altre registrazioni oltre il confine: il 2026 chiuso dice che sta lì perché lì era l\'esercizio aperto, il 2027 aperto non ha rimedi', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-05');
    $s['e2027'] = rctApriIl2027($s);
    rctPregressaDiDicembre($this, $s);
    vrmGiorno($this, '2027-03-01');

    $del2026 = rctPagina($this, $s)['anno_a_cavallo'];
    $del2027 = rctPagina($this, $s, $s['e2027'])['anno_a_cavallo'];

    // La pregressa è la sola registrazione oltre il confine: nel 2026 (verso B) è «scritta in un altro»; nel 2027 (verso A) una pregressa non ha rimedio.
    expect(array_column($del2026['righe'], 'categoria'))->toBe(['fattura_pregressa'])->and($del2026['versi']['B'])->toBe(['numero' => 1, 'rosse' => 1, 'da_leggere' => 0])
        ->and($del2026['rimedi'])->toBe([\App\Services\Gestionale\AnnoACavallo::RIMEDIO_ALTRO])
        ->and(array_column($del2027['righe'], 'categoria'))->toBe(['fattura_pregressa'])->and($del2027['versi']['A'])->toBe(['numero' => 1, 'rosse' => 0, 'da_leggere' => 1])
        ->and($del2027['rimedi'])->toBe([]);
});

it('un anno scritto male (2062): la riga dice che la data non sta in nessun esercizio, anche per una fattura', function () {
    $s = vrmScenario($this);
    rctScrittura($s, $s['e'], '2062-03-01', 'fattura_acquisto', [[rctConto($s, 'costi_servizi'), 'dare', 1000, null], [rctConto($s, 'debiti_fornitori'), 'avere', 1000, null]]);
    vrmGiorno($this, '2027-01-15');
    $riga = collect(rctControlli($s)['anno_a_cavallo']['righe'])->firstWhere('data', '2062-03-01');

    expect($riga['nota'])->toBe('la data non sta in nessun esercizio del condominio')->and($riga['senza_esercizio'])->toBeTrue();
});

it('R2 rosso per il resto: una registrazione del 2027 con la data del 2025 (un anno scritto male) sta nella situazione del 2026 e non nelle sue entrate e uscite per cassa; la nota lo dice, e dice dove cercarla', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2027-01-05');
    $e2027 = rctApriIl2027($s);
    rctScrittura($s, $e2027, '2025-12-20', 'incasso_rata', [[(int) $s['cassa']->conto_contabile_id, 'dare', 10000, $s['cassa']->id], [rctConto($s, 'crediti_condomini'), 'avere', 10000, null]]);
    vrmGiorno($this, '2027-01-15');
    $r2 = rctControllo(rctControlli($s), 'R2');

    expect($r2['esito'])->toBe('non_quadra')->and($r2['sinistra'] - $r2['destra'])->toBe(10000)
        ->and($r2['nota'])->toContain('una scrittura di cassa non torna, o una registrazione di un esercizio successivo ha una data che non è dopo la fine di questo')
        ->and($r2['rimedio'])->toContain('la trovi nel Libro Giornale di quell\'esercizio, cercandola per data')->and($r2['azione'])->toBe('libro-giornale');
});
