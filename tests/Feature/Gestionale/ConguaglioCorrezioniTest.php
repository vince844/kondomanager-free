<?php

/**
 * Il conguaglio del rendiconto, i casi che una prima stesura sbagliava: la percentuale dei costi sul deliberato delle sole voci; il foglio della
 * persona con i millesimi anche da solo; il già versato non usato una volta per unità e mai all'inquilino; le righe non disponibili con la loro
 * spesa detta; la riga delle spese personali dopo il totale delle spese; l'avviso dell'anno prima con un credito; il già versato per tutti gli
 * esercizi; i giorni dalle sole righe attive; il totale del deliberato «non disponibile» con un blocco che lo è; chi è a debito su almeno
 * un'unità; il saldo con le rate non ancora emesse; i motivi delle uscite che nessuno paga in parole.
 *
 * Le cifre sono rifatte a mano dalla regola, in centesimi; positivo = debito.
 */

use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\RigaRiparto;
use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\FascicoloDelRendiconto;
use App\Services\Gestionale\FogliDelConguaglio;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use App\Services\Gestionale\SintesiDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';
require_once __DIR__ . '/RendicontoViaRomaScenario.php';
require_once __DIR__ . '/ConguaglioMisuraScenario.php';
require_once __DIR__ . '/Beta59313AdPersonamScenari.php';
require_once __DIR__ . '/../Pdf/Support/CartaDelloStudio.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    foreach (['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
});

/** La riga della scheda di una persona su un'unità, fra le righe di una gestione. */
function cgcRiga(array $scheda, array $s, string $chi, string $unita): array
{
    foreach ($scheda['gestioni'][0]['righe'] as $r) {
        if (! $r['subtotale'] && $r['anagrafica_id'] === $s['persone'][$chi]->id && $r['immobile_id'] === $s['unita'][$unita]->id) {
            return $r;
        }
    }
    throw new RuntimeException("nessuna riga per {$chi}");
}

it('la percentuale dei costi della Sintesi sta sul deliberato delle voci: una spesa personale chiesta con le rate non la abbassa; il riquadro del deliberato la comprende', function () {
    // Costi € 27.654,29 (13.333,32 + 14.320,97), un piano con le righe per voce uguali ai costi: 100,0 %. Con una spesa personale di € 3.000,00 chiesta
    // con le rate la percentuale resta 100,0 %; il deliberato con le rate è € 30.654,29.
    $s = cgmCondominio(false, 0, 10, 2);
    $piano = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Preventivo 2026',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 4, 'giorno_scadenza' => 15, 'data_prima_scadenza' => '2026-01-15', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    foreach ($s['voci'] as $i => $voce) {
        RigaRiparto::create(['piano_rate_id' => $piano->id, 'tipo' => RigaRiparto::TIPO_RIPARTO, 'anagrafica_id' => null, 'immobile_id' => $s['unita'][0], 'conto_id' => $voce,
            'importo' => [1333332, 1432097][$i], 'versione_calcolo' => 1]);
    }
    $aid = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita'][1])->value('anagrafica_id');
    RigaRiparto::create(['piano_rate_id' => $piano->id, 'tipo' => RigaRiparto::TIPO_AD_PERSONAM, 'anagrafica_id' => $aid, 'immobile_id' => $s['unita'][1], 'conto_id' => null,
        'riga_descrizione' => 'Balcone', 'importo' => 300000, 'versione_calcolo' => 1]);
    $this->travelTo('2027-01-10 10:00:00');
    $x = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e']->fresh(), '2027-01-10');

    expect($x['riquadri']['costi']['costi'])->toBe(2765429)
        ->and($x['riquadri']['costi']['permille'])->toBe(1000)
        ->and($x['riquadri']['costi']['base'])->toBe('deliberato_voci')
        ->and($x['riquadri']['deliberato']['deliberato'])->toBe(3065429);
});

it('il foglio della persona scaricato da solo ha i millesimi, come quello dell\'archivio', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $dati = null;
    View::creator('pdf.gestionale.foglio_conguaglio', function ($v) use (&$dati) {
        $dati = $v->getData()['foglio'];
    });
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.foglio', [$s['c'], $s['e'], $s['persone']['Bianchi']->id]))->assertOk();

    expect(array_column($dati['gestioni'][0]['unita'][0]['voci'], 'millesimi'))->toBe(['300,00 su 1.000,00', '300,00 su 1.000,00']);
});

it('il già versato non usato dell\'unità va una volta sola, ai titolari di diritto reale: non sul foglio dell\'inquilino, una nota sola nella scheda', function () {
    // U1 ha versato € 2.000,00 per le Pulizie, il piano ne usa 1.500,00: € 500,00 non usati. Gialli inquilino di U1 al 30 % fino al 30/06: niente.
    $s = cpcViaVerdi($this, ['gia_versato' => [['U1', 'pulizie', 200000, null]], 'inquilino' => ['U1', 'Gialli', 30, '2020-01-01', '2026-06-30']]);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $c = app(ConguaglioDelRendiconto::class);
    $f = app(FogliDelConguaglio::class);
    $letto = $c->leggi($s['e'], [], true);

    expect($f->datiDelFoglio($s['c'], $s['e'], $letto, $s['persone']['Gialli']->id)['spiegano']['gia_versato_non_usato'])->toBe(0)
        ->and($f->datiDelFoglio($s['c'], $s['e'], $letto, $s['persone']['Neri']->id)['spiegano']['gia_versato_non_usato'])->toBe(50000);
    $note = [];
    foreach ($c->righe($s['c'], $s['e'], $letto)['gestioni'][0]['righe'] as $r) {
        foreach ($r['note'] as $n) {
            if (str_contains($n, 'Già versato')) {
                $note[] = $r['nome'] . ': ' . $n;
            }
        }
    }
    expect($note)->toBe(['Neri: Già versato registrato per questo esercizio e non usato dai suoi piani: € 500,00. Non entra nel conguaglio, e il programma non lo sposta da solo: '
        .'che cosa farne lo decidi tu (per esempio con un piano che lo usa).']);
});

it('il già versato per tutti gli esercizi non usato lo dice, senza confonderlo con quello dell\'esercizio', function () {
    $s = cpcViaVerdi($this);
    ContributoVersato::create(['condominio_id' => $s['c']->id, 'target_type' => Conto::class, 'target_id' => $s['manutenzioni']->id, 'immobile_id' => $s['unita']['U3']->id,
        'anagrafica_id' => null, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione', 'esercizio_id' => null]);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $c = app(ConguaglioDelRendiconto::class);
    $verdi = cgcRiga($c->righe($s['c'], $s['e'], $c->leggi($s['e'])), $s, 'Verdi', 'U3');

    expect($verdi['note'])->toContain('Già versato registrato per tutti gli esercizi e non usato dai piani di questo: € 300,00. Un piano di un altro esercizio può ancora usarlo; non entra nel conguaglio.');
});

it('con righe non disponibili la scheda e le due stampe dicono la loro spesa dell\'esercizio: i due totali tornano', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $passaggio = cpcPassaggio($this, $s, ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Regolato nel rogito del 01/07/2026, notaio Gialli']);
    $registro = $passaggio->registro;
    unset($registro['regolato_fuori']);
    DB::table('subentri')->where('id', $passaggio->id)->update(['registro' => json_encode($registro)]);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $c = app(ConguaglioDelRendiconto::class);
    $letto = $c->leggi($s['e'], [], true);
    $righe = $c->righe($s['c'], $s['e'], $letto, null, true);
    $tabelle = $c->perTabella($s['c'], $s['e'], $letto);

    // Bianchi 624,82 e Rossi 635,18 non disponibili: 1.260,00; le righe disponibili 2.100,00 + 840,00 = 2.940,00; per tabella 4.200,00.
    expect($righe['condominio']['a'])->toBe(294000)
        ->and($righe['condominio']['a_non_disponibili'])->toBe(126000)
        ->and($tabelle['gestioni'][0]['totale'])->toBe($righe['condominio']['a'] + $righe['condominio']['a_non_disponibili'])
        ->and($tabelle['gestioni'][0]['a_non_disponibili'])->toBe(126000);
    $persona = vrmTesto(view('pdf.gestionale.conguaglio_per_persona', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'conguaglio' => $righe])->render());
    $tabella = vrmTesto(view('pdf.gestionale.consuntivo_per_tabella', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'consuntivo' => $tabelle])->render());
    expect($persona)->toContain('Le righe con la proposta non ancora calcolata (2, spese dell\'esercizio € 1.260,00) non sono nei totali: l\'amministratore le completerà.')
        ->and($tabella)->toContain('di cui nelle righe del conguaglio non disponibili: € 1.260,00');
});

it('nel riepilogo le spese personali chieste con le rate vengono dopo «Totale spese», e «= Quote deliberate» le somma', function () {
    // La facciata € 500,00 e il balcone di Interno 1 € 100,00 chiesti con le rate: Totale spese € 500,00, + spese personali € 100,00, = quote deliberate € 600,00.
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaComune(500.00), b59apRigaAdPersonam($s, 100.00, 0)]);
    $piano = b59apPiano($this, $s, $fattura, 2, '600,00');
    b59apEmetti($this, $s, $piano, [1, 2]);
    $this->travelTo('2026-12-31 12:00:00');
    $html = view('pdf.gestionale.rendiconto_riepilogo', ['condominio' => $s['c'], 'esercizio' => $s['e'],
        'riepilogo' => app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']), 'dettaglio' => false])->render();
    $t = vrmTesto($html);

    expect($t)->toContain('Totale spese € 500,00')
        ->and(strpos($t, '+ Spese personali chieste con le rate'))->toBeGreaterThan(strpos($t, 'Totale spese'))
        ->and($t)->toContain('= Quote deliberate € 600,00');
});

it('il foglio della persona: le righe del conguaglio e del saldo di un\'unità stanno insieme, in una tabella che non si spezza', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $c = app(ConguaglioDelRendiconto::class);
    $dati = app(FogliDelConguaglio::class)->datiDelFoglio($s['c'], $s['e'], $c->leggi($s['e'], [], true), $s['persone']['Neri']->id);
    $html = view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'foglio' => $dati])->render();

    expect(preg_match('#<table[^>]*page-break-inside: avoid[^>]*>(?:(?!</table>).)*= Proposta di conguaglio, da approvare(?:(?!</table>).)*= Saldo se l\'assemblea approva#su', $html))->toBe(1);
});

it('l\'avviso dell\'anno prima con un credito, o con due cifre che si annullano: le cifre con il loro segno', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    [$e2025, $g2025] = cpcAnnoPrima($s);
    cpcRataNonPagata($s, $e2025, $g2025, 'Verdi');
    // La rata del 2025 di Verdi ancora aperta (€ 200,00) e un rimborso del 2025 sulla sua unità non ancora chiesto (€ −200,00): le due cifre si annullano.
    cpcSpesaPersonale(array_merge($s, ['g' => $g2025]), 'U3', -20000, '2025-11-20', $e2025);
    cpcSaldoDaAnnoPrima($s, $e2025, 'Verdi', 'U3', 5000);
    $this->travelTo('2027-01-10 10:00:00');
    $verdi = cpcDi(app(ConguaglioDelRendiconto::class)->leggi($s['e']), $s, 'Verdi', 'U3');

    expect($verdi['avviso_anno_prima'])->not->toBeNull()
        ->and($verdi['avviso_anno_prima']['frase'])->toBe('Il saldo iniziale scritto a mano di € 50,00 viene da «Esercizio 2025»: se comprende già la posizione ancora aperta di altre gestioni '
            . '(€ 200,00) o le spese personali di esercizi prima non ancora chieste (€ -200,00), la stessa cifra conta due volte. Controllalo nei saldi iniziali dell\'esercizio, e togli la parte che è già nell\'altra cifra.');
});

it('i giorni della scheda vengono dalle sole righe attive, come il motore', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    // Una riga spenta di Neri su U1, dal 01/07: il motore non la guarda; i giorni di Neri restano tutto l'anno (nessun numero accanto).
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Neri']->id, 'immobile_id' => $s['unita']['U1']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => false, 'data_inizio' => '2026-07-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Neri'])->update(['data_fine' => '2026-06-30']);
    $this->travelTo('2027-01-10 10:00:00');
    $giorni = app(ConguaglioDelRendiconto::class)->giorniDiTitolarita($s['e'], [$s['unita']['U1']->id]);

    expect($giorni[$s['persone']['Neri']->id][$s['unita']['U1']->id] ?? null)->toBe(181);
});

it('il totale del deliberato è «non disponibile» quando lo è un blocco, anche senza rate emesse', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = vrmBase($this);
    $gs = app(\App\Services\CondominioService::class)->createDefaultGestione($s['c'], $s['e'], 'straordinaria');
    $pcs = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $gs->id, 'nome' => 'Piano dei conti straordinario']);
    be52Voce($this, $s, $pcs, 'Facciata', 'spesa', '1.000,00');
    \App\Models\Gestionale\PianoRate::create(['gestione_id' => $gs->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Facciata 2026', 'stato' => 'approvato',
        'tipo' => 'straordinario', 'numero_rate' => 2, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-04-10', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false]);
    $r = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']);

    expect(collect($r['blocchi'])->firstWhere('gestione.id', $gs->id)['deliberato_stato'])->toBe('non_conservato')
        ->and($r['quote']['totale']['deliberate'])->toBeNull();
});

it('«con il conguaglio a debito» sceglie chi è a debito su almeno un\'unità, senza un netto fra le unità', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s, 200000, 90000);
    // Neri ha anche U3 a metà con Verdi: Pulizie 2.000,00 → U1 1.000,00 contro 1.500,00 chiesti (−500,00), U3 a metà.
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']['U3']->id)->update(['quota' => 50]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Neri']->id, 'immobile_id' => $s['unita']['U3']->id, 'tipologia' => 'proprietario', 'quota' => 50,
        'attivo' => true, 'data_inizio' => '2020-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    cpcSpesaPersonale($s, 'U3', 20000, '2026-09-10');
    $this->travelTo('2027-01-10 10:00:00');
    $letto = app(ConguaglioDelRendiconto::class)->leggi($s['e']);
    $neri = $letto['persone'][$s['g']->id][$s['persone']['Neri']->id];
    // U1: A 1.000,00 + 450,00, B 2.000,00: −550,00. U3 a metà: A 200,00 + 90,00, la spesa personale di € 200,00 a metà (100,00), nessuna quota: +390,00.
    // La somma è −160,00, a credito; ma Neri è a debito su U3.
    expect($neri[$s['unita']['U1']->id]['conguaglio'])->toBeLessThan(0)
        ->and($neri[$s['unita']['U3']->id]['conguaglio'])->toBeGreaterThan(0)
        ->and($neri[$s['unita']['U1']->id]['conguaglio'] + $neri[$s['unita']['U3']->id]['conguaglio'])->toBeLessThan(0)
        ->and(FogliDelConguaglio::persone($letto)[$s['persone']['Neri']->id])->toBeTrue();
});

it('il saldo con rate non ancora emesse lo dice accanto: Neri saldo +100,00, più la rata non ancora emessa € 500,00', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s, [1, 2, 3], ['Neri' => [1 => null, 2 => null, 3 => null]]);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $c = app(ConguaglioDelRendiconto::class);
    $letto = $c->leggi($s['e'], [], true);
    $righe = $c->righe($s['c'], $s['e'], $letto, null, true);
    $neri = cgcRiga($righe, $s, 'Neri', 'U1');

    expect([$neri['conguaglio'], $neri['saldo'], $neri['non_emesse']])->toBe([10000, 10000, 50000]);
    $persona = vrmTesto(view('pdf.gestionale.conguaglio_per_persona', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'conguaglio' => $righe])->render());
    $foglio = vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $s['e'],
        'foglio' => app(FogliDelConguaglio::class)->datiDelFoglio($s['c'], $s['e'], $letto, $s['persone']['Neri']->id)])->render());
    // Chieste le rate che mancano, il saldo è 100,00 + 500,00 = 600,00.
    expect($persona)->toContain('€ +100,00 quando saranno chieste le altre rate: € +600,00')
        ->and($persona)->toContain('Le quote dei piani comprendono anche le rate non ancora chieste')
        ->and($foglio)->toContain('= Saldo se l\'assemblea approva € +100,00 Rate dei piani non ancora chieste: quando saranno chieste, il saldo se l\'assemblea approva diventa da versare € 600,00 € 500,00');
});

it('i motivi delle uscite che nessuno paga sono frasi, con che cosa fare: nessun codice del motore resta senza la sua', function () {
    // I codici dello scoperto: quelli scritti nelle righe dello scoperto del motore (`scopertiAccumulati[] = [...]`, `pesiScoperti[] = [...]`), quello di
    // ripiego del consuntivo e la voce che non c'è più.
    $motore = (string) file_get_contents(app_path('Services/CalcoloQuoteService.php'));
    preg_match_all('/(?:scopertiAccumulati\\[\\]|pesiScoperti\\[\\])\\s*=\\s*\\[(.*?)\\];/s', $motore, $blocchi);
    $codici = [];
    foreach ($blocchi[1] as $blocco) {
        preg_match_all("/'motivo'\\s*=>[^,]*/", $blocco, $motivi);
        foreach ($motivi[0] as $riga) {
            preg_match_all("/'([a-z]+_[a-z_]+)'/", $riga, $nomi);
            array_push($codici, ...$nomi[1]);
        }
    }
    preg_match_all("/\\['motivo'\\] \\?\\? '([a-z_]+)'/", $motore, $ripiego);
    $codici = array_values(array_unique([...$codici, ...$ripiego[1], 'voce_non_trovata']));
    expect(count($codici))->toBeGreaterThan(6);
    foreach ($codici as $codice) {
        expect(ConguaglioDelRendiconto::MOTIVI)->toHaveKey($codice);
        expect(ConguaglioDelRendiconto::MOTIVI[$codice])->toMatch('/^[A-Z].*\.$/u')->not->toContain('_');
    }
    // Un codice nuovo, che il motore un giorno scrivesse, non esce come codice.
    expect(ConguaglioDelRendiconto::motivo(['qualcosa_di_nuovo' => 100]))->not->toContain('_');

    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $varie = cpmVoce($s, 'Varie', [], 'spesa', 0);
    cpmSpesa($s, $s['giornale'], $varie, 12345);
    $this->travelTo('2027-01-10 10:00:00');
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];
    expect($p['conguaglio']['gestioni'][0]['non_attribuite'][0]['motivo'])->toBe('La voce non ha una tabella millesimale. Collega una tabella con i millesimi alla voce.')
        ->and(json_encode($p['conguaglio'], JSON_UNESCAPED_UNICODE))->not->toMatch('/"motivo":"[a-z]+_[a-z_]+"/');
});

it('il riquadro della Sintesi dice anche le spese non attribuite a nessuno, con la loro cifra', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $varie = cpmVoce($s, 'Varie', [], 'spesa', 0);
    cpmSpesa($s, $s['giornale'], $varie, 12345);
    $this->travelTo('2027-01-10 10:00:00');
    $r = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e']->fresh())['riquadri']['conguaglio'];

    expect($r['cifre']['non_attribuite'])->toBe(12345)
        ->and($r['non_attribuite'])->toBe('€ 123,45 di spese non attribuite a nessuno: le dice la scheda Conguaglio, con il perché.');
});

it('il fascicolo mette la lettura del conguaglio nella stima, prima di farla: oltre il tetto non legge', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $stima = FascicoloDelRendiconto::righeDelConguaglio($s['e']);

    // Via Verdi: 3 unità × una tabella; le righe stimate dalle titolarità della gestione (Neri, Bianchi, Verdi: 3), le celle 3 × (1 + 9).
    expect($stima)->toBe(['conguaglio_lettura' => 3, 'conguaglio_persona' => 3, 'conguaglio_tabella' => 30])
        ->and(FascicoloDelRendiconto::stima(['conguaglio_lettura' => 100000])['secondi'])->toBeGreaterThan(FascicoloDelRendiconto::SECONDI_BASE + 10);
});

it('le cinque stampe sulla carta del riepilogo: una tabella che non si spezza non si rimpicciolisce, e una cifra con la nota sotto non tiene la nota su una riga', function () {
    // mPDF rimpicciolisce fino a 1,4 volte una tabella con `page-break-inside: avoid` che non sta nel resto della pagina, invece di portarla alla pagina dopo:
    // `autosize="1"` lo vieta. Una cella `nowrap` tiene anche la nota su una riga, e mPDF rimpicciolisce la tabella per farcela stare.
    $male = [];
    foreach (['conguaglio_per_persona', '_conguaglio_per_persona_riga', 'consuntivo_per_tabella', '_consuntivo_per_tabella_riga', 'foglio_conguaglio', 'rendiconto_posizioni',
        'rendiconto_crediti'] as $vista) {
        $sorgente = (string) file_get_contents(resource_path("views/pdf/gestionale/{$vista}.blade.php"));
        preg_match_all('#<table[^>]*page-break-inside: avoid[^>]*>#', $sorgente, $tabelle);
        foreach ($tabelle[0] as $t) {
            if (! str_contains($t, 'autosize="1"')) {
                $male[] = "{$vista}: {$t}";
            }
        }
        preg_match_all('#<td class="n">(?:(?!</td>).)*<br><span class="d">#s', $sorgente, $celle);
        foreach ($celle[0] as $c) {
            $male[] = "{$vista}: una cifra con la nota in una cella nowrap";
        }
    }

    expect($male)->toBe([]);
});

// ─── Le regole più strette: la base della percentuale, il tetto del consuntivo per tabella, la stima e la lettura nel fascicolo, la firma del foglio,
// il già versato per tutti gli esercizi, le rate non ancora emesse, il già versato senza titolare, l'ordine dei titolari, i giorni sommati ───────

/** Una carta intestata in PDF di due pagine, come quella delle prove della carta: per vedere dove va a finire la firma del foglio. */
function cgcCartaIntestata(): void
{
    $o = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $figli = [];
    foreach (['CARTA PRIMA PAGINA', 'CARTA PAGINE SEGUENTI'] as $i => $testo) {
        $n = 4 + 2 * $i;
        $figli[] = "{$n} 0 R";
        $o[$n] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 3 0 R >> >> /Contents ".($n + 1).' 0 R >>';
        $c = "BT /F1 18 Tf 72 761.89 Td ({$testo}) Tj ET";
        $o[$n + 1] = '<< /Length '.strlen($c).">>\nstream\n{$c}\nendstream";
    }
    $o[2] = '<< /Type /Pages /Kids ['.implode(' ', $figli).'] /Count 2 >>';
    ksort($o);
    $pdf = "%PDF-1.4\n";
    $posti = [];
    foreach ($o as $n => $c) {
        $posti[$n] = strlen($pdf);
        $pdf .= "{$n} 0 obj\n{$c}\nendobj\n";
    }
    $x = strlen($pdf);
    $pdf .= "xref\n0 8\n0000000000 65535 f \n";
    for ($k = 1; $k < 8; $k++) {
        $pdf .= sprintf("%010d 00000 n \n", $posti[$k]);
    }
    \Illuminate\Support\Facades\Storage::disk('public')->put('settings/carte/carta-del-foglio.pdf', $pdf."trailer\n<< /Size 8 /Root 1 0 R >>\nstartxref\n{$x}\n%%EOF\n");
    $carta = \App\Models\CartaStampa::create(['nome' => 'Carta della tipografia', 'aspetto' => 'carta', 'mostra_logo' => true, 'colore' => null,
        'carta_path' => 'settings/carte/carta-del-foglio.pdf', 'carta_tipo' => 'pdf', 'carta_pagine' => 2, 'predefinita' => false,
        'margine_alto' => 45, 'margine_basso' => 22, 'margine_sinistro' => 20, 'margine_destro' => 18]);
    $impostazioni = app(\App\Settings\PrintSettings::class);
    $impostazioni->carta_per_gruppo = ['rendiconto_e_assemblea' => $carta->id, 'per_la_persona' => $carta->id];
    \Illuminate\Support\Facades\Storage::disk('public')->put('settings/firma-del-foglio.png', cdsPng(300, 120));
    $impostazioni->firma_stampe_path = 'settings/firma-del-foglio.png';
    $impostazioni->save();
    app()->forgetInstance(\App\Services\PDF\CartaDiStampa::class);
}

it('la percentuale dei costi dice la sua base: sul deliberato delle voci, con la cifra, quando ci sono spese personali chieste con le rate; sul deliberato con le rate quando non ce ne sono', function () {
    $s = cgmCondominio(false, 0, 10, 2);
    $piano = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $s['g']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Preventivo 2026',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 4, 'giorno_scadenza' => 15, 'data_prima_scadenza' => '2026-01-15', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    foreach ($s['voci'] as $i => $voce) {
        RigaRiparto::create(['piano_rate_id' => $piano->id, 'tipo' => RigaRiparto::TIPO_RIPARTO, 'anagrafica_id' => null, 'immobile_id' => $s['unita'][0], 'conto_id' => $voce,
            'importo' => [1333332, 1432097][$i], 'versione_calcolo' => 1]);
    }
    $aid = (int) DB::table('anagrafica_immobile')->where('immobile_id', $s['unita'][1])->value('anagrafica_id');
    $personale = RigaRiparto::create(['piano_rate_id' => $piano->id, 'tipo' => RigaRiparto::TIPO_AD_PERSONAM, 'anagrafica_id' => $aid, 'immobile_id' => $s['unita'][1], 'conto_id' => null,
        'riga_descrizione' => 'Balcone', 'importo' => 300000, 'versione_calcolo' => 1]);
    $this->travelTo('2027-01-10 10:00:00');
    $con = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e']->fresh(), '2027-01-10')['riquadri']['costi'];
    $personale->delete();
    $senza = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $s['e']->fresh(), '2027-01-10')['riquadri']['costi'];

    // Con la spesa personale: 27.654,29 su 27.654,29 delle voci (il deliberato con le rate è 30.654,29); senza, il deliberato con le rate è lo stesso.
    expect([$con['permille'], $con['base'], $con['base_importo']])->toBe([1000, 'deliberato_voci', 2765429])
        ->and([$senza['permille'], $senza['base'], $senza['base_importo']])->toBe([1000, 'deliberato', 2765429]);
});

it('il già versato per tutti gli esercizi che il piano dell\'esercizio prima ha già usato non torna «non usato» nell\'esercizio dopo della stessa gestione', function () {
    // € 2.000,00 registrati per tutti gli esercizi sulle Pulizie di U1; il piano del 2026 ne usa 1.500,00. Nel 2026 e nel 2027 restano € 500,00.
    $s = cpcViaVerdi($this, ['gia_versato' => [['U1', 'pulizie', 200000, null]]]);
    DB::table('contributi_versati')->where('condominio_id', $s['c']->id)->update(['esercizio_id' => null]);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $e27 = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e27, $s['g']->id);
    $this->travelTo('2027-06-10 10:00:00');
    $c = app(ConguaglioDelRendiconto::class);
    $neri = fn (\App\Models\Esercizio $e) => $c->leggi($e)['persone'][$s['g']->id][$s['persone']['Neri']->id][$s['unita']['U1']->id]['gia_versato_non_usato'] ?? null;

    expect(-(int) DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'netting')->sum('importo'))->toBe(150000)
        ->and($neri($s['e']))->toBe(['questo_esercizio' => 0, 'tutti_gli_esercizi' => 50000])
        ->and($neri($e27))->toBe(['questo_esercizio' => 0, 'tutti_gli_esercizi' => 50000]);
});

it('nel foglio le rate non ancora emesse hanno una cifra sola: la riga che spiega dice quella accanto al saldo, con le spese personali chieste con le rate', function () {
    // La straordinaria di € 600,00 in due rate, la prima emessa: € 500,00 divisi e € 100,00 della spesa personale, € 50,00 in ogni rata. Non emesse:
    // € 125,00 di quote e € 50,00 della spesa personale, € 175,00.
    $this->travelTo('2026-03-20 10:00:00');
    $s = b59apBase(['Mario Rossi' => 100]);
    $fattura = b59apFattura($this, $s, [b59apRigaComune(500.00), b59apRigaAdPersonam($s, 100.00, 0)]);
    $piano = b59apPiano($this, $s, $fattura, 2, '600,00');
    b59apEmetti($this, $s, $piano, [1]);
    $this->travelTo('2026-12-31 12:00:00');
    $c = app(ConguaglioDelRendiconto::class);
    $letto = $c->leggi($s['e'], [], true);
    $aid = (int) collect($letto['persone'])->flatMap(fn ($g) => array_keys(array_filter($g, fn ($u) => collect($u)->contains(fn ($r) => ($r['personali']['chieste_non_emesse'] ?? 0) !== 0))))->first();
    $f = app(FogliDelConguaglio::class)->datiDelFoglio($s['c'], $s['e'], $letto, $aid);
    $testo = vrmTesto(view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'foglio' => $f])->render());

    expect([$f['totale']['non_emesse'], $f['spiegano']['non_emesse'], $f['spiegano']['non_emesse_personali']])->toBe([17500, 17500, 5000])
        ->and($testo)->toContain('quando saranno chieste le rate che mancano (€ 175,00)')
        ->and($testo)->toContain('non ancora chieste: sono nelle quote, non in quello che è già da versare € 175,00 di cui spese personali chieste con le rate € 50,00');
});

it('il già versato non usato di un\'unità senza un titolare alla data è nella scheda, in una riga della gestione', function () {
    // U3: € 700,01 registrati per le Pulizie; Verdi esce il 30/09 e alla data l'unità non ha un titolare: il non usato resta della gestione.
    $s = cpcViaVerdi($this, ['gia_versato' => [['U3', 'pulizie', 70001, null]]]);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Verdi'])->update(['data_fine' => '2026-09-30']);
    $this->travelTo('2027-01-10 10:00:00');
    $c = app(ConguaglioDelRendiconto::class);
    $letto = $c->leggi($s['e']);
    $usato = -(int) DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'netting')->where('immobile_id', $s['unita']['U3']->id)->sum('importo');
    $resta = 70001 - $usato;
    $g = $c->righe($s['c'], $s['e'], $letto)['gestioni'][0];

    expect($resta)->toBeGreaterThan(0)
        ->and($letto['gestioni'][$s['g']->id]['gia_versato_non_usato_senza_titolare'])->toBe([$s['unita']['U3']->id => $resta])
        ->and($g['gia_versato_senza_titolare'])->toBe('Già versato registrato e non usato di unità senza un titolare di diritto reale alla data (Int. U3): '
            .'€ '.number_format($resta / 100, 2, ',', '.').'. Resta della gestione: non è nel conguaglio di nessuno.');
});

it('il già versato non usato di un\'unità con nuda proprietà e usufrutto, su una voce ordinaria, va all\'usufruttuario, come il saldo pregresso', function () {
    // U2: € 1.000,00 registrati per le Pulizie (ordinaria); Bianchi nudo proprietario, Viola usufruttuaria: il non usato a Viola, niente a Bianchi.
    $s = cpcViaVerdi($this, ['gia_versato' => [['U2', 'pulizie', 100000, null]]]);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Bianchi'])->update(['tipologia' => 'nuda_proprietario']);
    $viola = \App\Models\Anagrafica::forceCreate(['nome' => 'Viola', 'email' => 'viola-cgc@test.it', 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => 'VLIVLI80A01H501Z']);
    $viola->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $viola->id, 'immobile_id' => $s['unita']['U2']->id, 'tipologia' => 'usufruttuario', 'quota' => 100, 'attivo' => true,
        'data_inizio' => '2020-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $this->travelTo('2027-01-10 10:00:00');
    $letto = app(ConguaglioDelRendiconto::class)->leggi($s['e']);
    $usato = -(int) DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'netting')->where('immobile_id', $s['unita']['U2']->id)->sum('importo');
    $di = fn (int $aid) => array_sum($letto['persone'][$s['g']->id][$aid][$s['unita']['U2']->id]['gia_versato_non_usato'] ?? []);

    expect(100000 - $usato)->toBeGreaterThan(0)
        ->and($di($viola->id))->toBe(100000 - $usato)
        ->and($di($s['persone']['Bianchi']->id))->toBe(0);
});

it('le rate non ancora emesse anche nei totali: della gestione e del condominio, nella stampa per persona e nella scheda', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s, [1, 2, 3], ['Neri' => [1 => null, 2 => null, 3 => null]]);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $c = app(ConguaglioDelRendiconto::class);
    $righe = $c->righe($s['c'], $s['e'], $c->leggi($s['e'], [], true), null, true);
    $nonEmesse = (int) array_sum(array_map(fn ($r) => $r['subtotale'] ? 0 : (int) $r['non_emesse'], $righe['gestioni'][0]['righe']));
    $html = view('pdf.gestionale.conguaglio_per_persona', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'conguaglio' => $righe])->render();

    // La rata 4 non emessa: € 1.000,00 per tutte e tre le unità (500,00 + 300,00 + 200,00).
    expect($nonEmesse)->toBe(100000)
        ->and($righe['gestioni'][0]['totali']['non_emesse'])->toBe(100000)
        ->and($righe['condominio']['non_emesse'])->toBe(100000)
        // Accanto al saldo del totale della gestione e a quello del condominio: il saldo quando saranno chieste, cioè più € 1.000,00.
        ->and(substr_count(vrmTesto($html), 'quando saranno chieste le altre rate: € '.(($q = $righe['condominio']['saldo'] + 100000) > 0 ? '+' : '').number_format($q / 100, 2, ',', '.')))->toBe(2);
});

it('il foglio con la carta intestata: la firma non va da sola sulla pagina dopo, sta con le righe che spiegano', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    $s = cpcViaVerdi($this, ['gia_versato' => [['U1', 'pulizie', 200000, null]]]);
    cpcRateEIncassi($this, $s);
    cpcPassaggio($this, $s);
    cpcSpese($s);
    cpcSpesaPersonale($s, 'U3', 8000, '2026-09-10');
    [$e2025, $g2025] = cpcAnnoPrima($s);
    cpcRataNonPagata($s, $e2025, $g2025, 'Verdi');
    $this->travelTo('2027-01-10 10:00:00');
    cdsStudioPieno(true);
    cgcCartaIntestata();
    $pdf = (string) $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio.foglio', [$s['c'], $s['e'], $s['persone']['Verdi']->id]))->assertOk()->getContent();
    $ultima = cdsTesto($pdf, cdsNumeroPagine($pdf));

    expect($ultima)->toContain('Le righe che spiegano')->toContain('AMMINISTRATORE');
});

it('il fascicolo stima le righe del conguaglio per gestione: con l\'ordinaria, il riscaldamento e una straordinaria la stima sta sopra le righe delle due stampe', function () {
    $s = cpcViaVerdi($this, ['riscaldamento' => true]);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $pcR = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['riscaldamento']->id, 'nome' => 'PC risc']);
    $sR = array_merge($s, ['pianoConto' => $pcR, 'g' => $s['riscaldamento'], 'gestione' => $s['riscaldamento']]);
    cpmSpesa($sR, $s['giornale'], cpmVoce($sR, 'Gasolio', [[$s['generali'], 100]], 'spesa', 100000), 150000);
    $gs = app(\App\Services\CondominioService::class)->createDefaultGestione($s['c'], $s['e'], 'straordinaria');
    $pcS = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $gs->id, 'nome' => 'PC str']);
    $sS = array_merge($s, ['pianoConto' => $pcS, 'g' => $gs, 'gestione' => $gs]);
    cpmSpesa($sS, $s['giornale'], cpmVoce($sS, 'Facciata', [[$s['generali'], 100]], 'spesa', 500000), 400000);
    $this->travelTo('2027-01-10 10:00:00');
    $stima = FascicoloDelRendiconto::righeDelConguaglio($s['e']);
    $c = app(ConguaglioDelRendiconto::class);
    $letto = $c->leggi($s['e'], [], true);
    $persona = \App\Http\Controllers\Gestionale\Movimenti\RendicontoConguaglioController::contaRighe($c->righe($s['c'], $s['e'], $letto, null, true));
    $tabella = (int) array_sum(array_map(fn ($g) => count($g['righe']), $c->perTabella($s['c'], $s['e'], $letto)['gestioni']));

    expect(count($letto['gestioni']))->toBe(3)
        ->and($persona)->toBeGreaterThan(3)
        ->and($stima['conguaglio_persona'])->toBeGreaterThanOrEqual($persona)
        ->and($stima['conguaglio_tabella'])->toBeGreaterThanOrEqual($tabella);
});

it('il fascicolo oltre il tetto non legge il conguaglio: la lettura è nella stima prima di farla', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $contatore = new class extends \App\Services\Gestionale\ConsuntivoPerPersona {
        public static int $letture = 0;

        public function perEsercizio(\App\Models\Esercizio $esercizio, array $righePer = [], ?string $alla = null, bool $perTabella = false): array
        {
            self::$letture++;

            return parent::perEsercizio($esercizio, $righePer, $alla, $perTabella);
        }
    };
    $contatore::$letture = 0;
    app()->instance(\App\Services\Gestionale\ConsuntivoPerPersona::class, $contatore);
    // Un secondo di tempo: nessuna parte ci sta.
    (new ReflectionProperty(FascicoloDelRendiconto::class, 'limiteTempo'))->setValue(null, 1);
    try {
        $r = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e']->fresh(), ['conguaglio']);
    } finally {
        (new ReflectionProperty(FascicoloDelRendiconto::class, 'limiteTempo'))->setValue(null, null);
    }

    expect($r)->toHaveKey('troppo')->and($contatore::$letture)->toBe(0);
});

it('il fascicolo con il conguaglio: i controlli lo ricevono dalla stessa lettura, letta una volta, e i quattro del conguaglio non sono da leggere', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $this->travelTo('2027-01-10 10:00:00');
    $contatore = new class extends \App\Services\Gestionale\ConsuntivoPerPersona {
        public static int $letture = 0;

        public function perEsercizio(\App\Models\Esercizio $esercizio, array $righePer = [], ?string $alla = null, bool $perTabella = false): array
        {
            self::$letture++;

            return parent::perEsercizio($esercizio, $righePer, $alla, $perTabella);
        }
    };
    $contatore::$letture = 0;
    app()->instance(\App\Services\Gestionale\ConsuntivoPerPersona::class, $contatore);
    $controlli = null;
    View::composer('pdf.gestionale.stato_patrimoniale', function ($v) use (&$controlli) {
        if (($v->getData()['parte'] ?? null) === 'controlli') {
            $controlli = $v->getData()['controlli'];
        }
    });
    $r = app(FascicoloDelRendiconto::class)->genera($s['c'], $s['e']->fresh(), ['conguaglio', 'controlli']);

    expect($r)->toHaveKey('pdf')->and($contatore::$letture)->toBe(1)
        ->and(array_column(array_filter($controlli, fn ($x) => in_array($x['id'], ['CG', 'CU', 'CB'], true)), 'esito'))->not->toContain('segnalazione');
});

it('i giorni di due righe attive della stessa persona sulla stessa unità si sommano: con la quota cambiata il 01/07 è l\'anno intero, come il motore', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Neri'])->update(['data_fine' => '2026-06-30']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $s['persone']['Neri']->id, 'immobile_id' => $s['unita']['U1']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2026-07-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $this->travelTo('2027-01-10 10:00:00');
    $giorni = app(ConguaglioDelRendiconto::class)->giorniDiTitolarita($s['e'], [$s['unita']['U1']->id]);

    // L'anno intero non ha i giorni accanto (181 + 184 = 365): con la regola del massimo sarebbero 184.
    expect($giorni[$s['persone']['Neri']->id][$s['unita']['U1']->id] ?? null)->toBeNull();
});
