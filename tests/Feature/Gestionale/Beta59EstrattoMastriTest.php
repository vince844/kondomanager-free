<?php

/**
 * 1.11.0-beta.59, Code 288 e 317 (1) — l'estratto conto per persona conta solo le righe della posizione di condòmino.
 *
 * Il ciclo passivo scrive l'anagrafica del primo rappresentante del fornitore (`$fornitore->referenti()->first()`) su righe che non sono
 * della persona: il costo della regolazione immediata e il suo storno, i debiti verso fornitori (2201) e verso l'Erario per le ritenute
 * (2202) della fattura, della sua modifica, della nota di credito e dello storno, i debiti del pagamento e della sua modifica. Quando quel
 * rappresentante è anche un condòmino, il suo estratto conto (pagina e PDF, `EstrattoContoAnagraficaController::buildLedger`) le sommava:
 * la fattura come un versamento, la regolazione e il pagamento come addebiti.
 *
 * La regola (decisione 153.2): l'estratto conto per persona conta solo le righe sui mastri «Crediti verso condomini» (1101), «Anticipi»
 * (2101) e «Passate gestioni» (2301), una lista che entra; i dati già scritti si leggono giusti all'apertura della pagina, senza migrazione.
 * Nell'esempio della riga 288 della roadmap (rata € 500,00, regolazione € 16,68, fattura € 1.220,00 e il suo pagamento) la persona vede
 * € 500,00 di saldo, € 500,00 di addebiti e € 0,00 versati, invece di € 516,68, € 1.736,68 e € 1.220,00.
 *
 * Fonte dell'atteso: la decisione 153.2 (le tre cifre dell'esempio); per le varianti, la stessa regola applicata a mano, scritta in ogni
 * test. Ogni operazione passa dalla rotta della sua pagina; la pagina e il PDF si leggono dalle loro rotte, da amministratore e da
 * collaboratore (i ruoli di serie).
 *
 * Che cosa NON copre: il nome della controparte nel Registro e nel dettaglio della voce, che resta la persona (Coda 293, 1.12); la data e
 * l'ordine delle righe (`Beta59EstrattoDataTest.php`); MySQL.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59EstrattoScenari.php';

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/** Le tre cifre dell'esempio della riga 288, dopo la correzione (decisione 153.2): la sola rata da € 500,00. */
const B59E_SOLO_LA_RATA = ['€ 500,00', '€ 500,00', '€ 0,00'];

it('Coda 288 — Mario Rossi, condòmino e rappresentante del fornitore: rata € 500,00, regolazione € 16,68, fattura € 1.220,00 e il suo pagamento; pagina e PDF dicono € 500,00, € 500,00 e € 0,00', function () {
    $s = b59eScenario($this);
    $impianti = b59eFornitore($s, 'Rossi Impianti s.r.l.');

    b59eEmetti($s, [1], '2026-01-05');
    b59eRegolazione($s, $impianti, '2026-03-10', 16.68);
    $fattura = b59eFattura($s, $impianti, 'FT-2026/1', '2026-04-10', 1000.00);
    b59ePaga($s, $impianti, $fattura, '2026-05-10');

    // Le righe del fornitore portano davvero la persona (è il dato che l'estratto deve scartare): la regolazione sul costo, la fattura su 2201, il pagamento su 2201.
    $suoiFuori = DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
        ->where('rs.anagrafica_id', $s['mario']->id)->whereNotIn('cc.ruolo', ['crediti_condomini', 'anticipi_condomini', 'passate_gestioni'])
        ->orderBy('rs.id')->pluck('rs.importo')->map(fn ($i) => (int) $i)->all();
    expect($suoiFuori)->toBe([1668, 122000, 122000])
        ->and((int) $fattura->totale_documento)->toBe(122000);

    $pagina = b59ePagina($s);
    $pdf = b59ePdf($s);

    // A mano: saldo iniziale 0 + rata 50000 = 50000; addebiti la sola rata; nessun versamento.
    expect(['pagina' => b59eCifre($pagina), 'pdf' => b59eCifre($pdf)])->toBe(['pagina' => B59E_SOLO_LA_RATA, 'pdf' => B59E_SOLO_LA_RATA])
        ->and($pagina['saldo'])->toBe(50000)
        ->and(array_column($pagina['timeline'], 'tipo_movimento'))->toBe(['emissione_rata'])
        ->and($pdf['saldo'])->toBe(50000)
        ->and(count($pdf['timeline']))->toBe(1)
        ->and($pdf['testo'])->not->toContain('FT-2026/1')
        ->and($pdf['testo'])->not->toContain('Imposta di bollo')
        ->and(b59eCifre(b59ePagina($s, $s['collaboratore'])))->toBe(B59E_SOLO_LA_RATA);
});

it('Coda 288 — la fattura con la ritenuta di € 200,00 (2201 € 1.020,00 e 2202 € 200,00, tutte e due con la persona) e il pagamento del netto restano fuori: € 500,00, € 500,00 e € 0,00', function () {
    $s = b59eScenario($this);
    $impianti = b59eFornitore($s, 'Rossi Impianti s.r.l.');
    $consulenze = b59eFornitore($s, 'Rossi Consulenze', ritenuta: 20);

    b59eEmetti($s, [1], '2026-01-05');
    b59eRegolazione($s, $impianti, '2026-03-10', 16.68);
    $fattura = b59eFattura($s, $consulenze, 'PRO-7', '2026-04-10', 1000.00, ['applica_ritenuta' => true]);
    b59ePaga($s, $consulenze, $fattura, '2026-05-10');

    // Il dato: imponibile 100000 al 20 % = 20000 di ritenuta, netto 122000 − 20000 = 102000; le due righe AVERE e il DARE del pagamento portano la persona.
    expect((int) $fattura->importo_ritenuta)->toBe(20000)
        ->and((int) $fattura->netto_a_pagare)->toBe(102000)
        ->and(DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')->where('rs.anagrafica_id', $s['mario']->id)
            ->whereIn('cc.ruolo', ['debiti_fornitori', 'debiti_erario_ritenute'])->orderBy('rs.id')->get(['cc.ruolo', 'rs.tipo_riga', 'rs.importo'])
            ->map(fn ($r) => [$r->ruolo, $r->tipo_riga, (int) $r->importo])->all())
        ->toBe([['debiti_fornitori', 'avere', 102000], ['debiti_erario_ritenute', 'avere', 20000], ['debiti_fornitori', 'dare', 102000]]);

    expect(['pagina' => b59eCifre(b59ePagina($s)), 'pdf' => b59eCifre(b59ePdf($s))])->toBe(['pagina' => B59E_SOLO_LA_RATA, 'pdf' => B59E_SOLO_LA_RATA]);
});

it('Coda 288 — lo storno del pagamento (righe senza la persona, il pagamento originale resta «registrata») non lascia il pagamento nell\'estratto: € 500,00, € 500,00 e € 0,00', function () {
    $s = b59eScenario($this);
    $impianti = b59eFornitore($s, 'Rossi Impianti s.r.l.');

    b59eEmetti($s, [1], '2026-01-05');
    b59eRegolazione($s, $impianti, '2026-03-10', 16.68);
    $fattura = b59eFattura($s, $impianti, 'FT-2026/1', '2026-04-10', 1000.00);
    $pagamento = b59ePaga($s, $impianti, $fattura, '2026-05-10');
    $this->actingAs($s['admin'])->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $pagamento]), [
        'esercizio_id' => $s['e']->id, 'data_storno' => '2026-06-10', 'motivo' => 'Bonifico respinto dalla banca del fornitore',
    ])->assertSessionHasNoErrors();

    // Il dato del difetto: le righe dello storno non hanno la persona, e la scrittura del pagamento non è annullata.
    $storno = ScritturaContabile::where('condominio_id', $s['c']->id)->where('tipo_movimento', 'storno_pagamento_fornitore')->sole();
    expect($storno->righe()->whereNotNull('anagrafica_id')->count())->toBe(0)
        ->and($pagamento->fresh()->scrittura->stato)->toBe('registrata');

    expect(['pagina' => b59eCifre(b59ePagina($s)), 'pdf' => b59eCifre(b59ePdf($s))])->toBe(['pagina' => B59E_SOLO_LA_RATA, 'pdf' => B59E_SOLO_LA_RATA]);
});

it('Coda 288 — la regolazione immediata stornata (la riga dello storno copia la persona) non entra nei totali: € 500,00, € 500,00 e € 0,00', function () {
    $s = b59eScenario($this);
    $impianti = b59eFornitore($s, 'Rossi Impianti s.r.l.');

    b59eEmetti($s, [1], '2026-01-05');
    $regolazione = b59eRegolazione($s, $impianti, '2026-03-10', 16.68);
    $this->actingAs($s['admin'])->post(route('admin.gestionale.regolazioni-immediate.storno', [$s['c'], $regolazione]), ['motivo' => 'Importo sbagliato nella registrazione'])
        ->assertSessionHasNoErrors();

    $storno = ScritturaContabile::where('scrittura_padre_id', $regolazione)->sole();
    expect($storno->righe()->where('anagrafica_id', $s['mario']->id)->pluck('importo')->map(fn ($i) => (int) $i)->all())->toBe([1668]);

    expect(['pagina' => b59eCifre(b59ePagina($s)), 'pdf' => b59eCifre(b59ePdf($s))])->toBe(['pagina' => B59E_SOLO_LA_RATA, 'pdf' => B59E_SOLO_LA_RATA]);
});

it('Coda 288 — la nota di credito da € 244,00 (righe invertite con la persona) e lo storno della fattura restano fuori: € 500,00, € 500,00 e € 0,00', function () {
    $s = b59eScenario($this);
    $impianti = b59eFornitore($s, 'Rossi Impianti s.r.l.');

    b59eEmetti($s, [1], '2026-01-05');
    b59eRegolazione($s, $impianti, '2026-03-10', 16.68);
    b59eFattura($s, $impianti, 'FT-2026/1', '2026-04-10', 1000.00);
    b59eFattura($s, $impianti, 'NC-2026/1', '2026-04-20', 200.00, ['tipo_documento' => 'nota_credito']);
    $seconda = b59eFattura($s, $impianti, 'FT-2026/2', '2026-04-25', 300.00);
    $this->actingAs($s['admin'])->post(route('admin.gestionale.fatture.storno', [$s['c'], $seconda]))->assertSessionHasNoErrors();

    // Il dato: la nota e lo storno scrivono DARE 2201 con la persona (24400 e 36600), la fattura AVERE 2201 (122000 e 36600).
    expect(DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')->where('rs.anagrafica_id', $s['mario']->id)
        ->where('cc.ruolo', 'debiti_fornitori')->orderBy('rs.id')->get(['rs.tipo_riga', 'rs.importo'])->map(fn ($r) => [$r->tipo_riga, (int) $r->importo])->all())
        ->toBe([['avere', 122000], ['dare', 24400], ['avere', 36600], ['dare', 36600]]);

    expect(['pagina' => b59eCifre(b59ePagina($s)), 'pdf' => b59eCifre(b59ePdf($s))])->toBe(['pagina' => B59E_SOLO_LA_RATA, 'pdf' => B59E_SOLO_LA_RATA]);
});

it('Coda 288 — la modifica della fattura e quella del pagamento riscrivono le righe con la persona, e restano fuori: € 500,00, € 500,00 e € 0,00', function () {
    $s = b59eScenario($this);
    $impianti = b59eFornitore($s, 'Rossi Impianti s.r.l.');

    b59eEmetti($s, [1], '2026-01-05');
    $fattura = b59eFattura($s, $impianti, 'FT-2026/1', '2026-04-10', 1000.00);
    $this->actingAs($s['admin'])->put(route('admin.gestionale.fatture.update', [$s['c'], $fattura]), [
        'gestione_id' => $s['g']->id, 'numero_documento' => 'FT-2026/1', 'data_documento' => '2026-04-10', 'data_scadenza' => '2026-04-10', 'modalita_pagamento' => 'bonifico',
        'righe' => [['descrizione' => 'Riparazione', 'importo_imponibile' => 1500.00, 'aliquota_iva' => 22, 'conto_id' => $s['voce']->id]],
    ])->assertSessionHasNoErrors();
    $fattura->refresh();
    $pagamento = b59ePaga($s, $impianti, $fattura, '2026-05-10');
    $this->actingAs($s['admin'])->put(route('admin.gestionale.pagamenti-fornitori.update', [$s['c'], $pagamento]), [
        'data_pagamento' => '2026-05-10', 'metodo_pagamento' => 'bonifico', 'conto_corrente_id' => $s['cassa']->conto_contabile_id,
        'importo_lordo_cents' => 183000, 'importo_ritenuta_cents' => 0, 'importo_netto_cents' => 183000, 'importo_commissioni_cents' => 0,
        'note_override' => 'Riferimento bancario aggiunto dopo', 'iban_confermato_manualmente' => true, 'allow_overdraft' => true, 'conferma_duplicato_verificato' => true,
    ])->assertSessionHasNoErrors();

    // Il dato: dopo le due modifiche le righe 2201 con la persona sono quelle nuove, da 183000 (€ 1.500,00 + IVA 22 %).
    expect(DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')->where('rs.anagrafica_id', $s['mario']->id)
        ->where('cc.ruolo', 'debiti_fornitori')->orderBy('rs.id')->get(['rs.tipo_riga', 'rs.importo'])->map(fn ($r) => [$r->tipo_riga, (int) $r->importo])->all())
        ->toBe([['avere', 183000], ['dare', 183000]]);

    expect(['pagina' => b59eCifre(b59ePagina($s)), 'pdf' => b59eCifre(b59ePdf($s))])->toBe(['pagina' => B59E_SOLO_LA_RATA, 'pdf' => B59E_SOLO_LA_RATA]);
});

it('Coda 288 — un rappresentante legato al condominio senza unità (un ex condòmino) non trova nel suo estratto le righe del fornitore: nessuna riga e € 0,00', function () {
    $s = b59eScenario($this);
    $luca = Anagrafica::forceCreate(['nome' => 'Luca Bianchi', 'email' => "b59e-luca{$s['seq']}@test.it", 'indirizzo' => 'Via dei Mille 2']);
    $luca->condomini()->syncWithoutDetaching([$s['c']->id]);
    $giardini = b59eFornitore($s, 'Bianchi Giardini s.r.l.', rappresentante: $luca);

    b59eRegolazione($s, $giardini, '2026-03-10', 16.68);
    $fattura = b59eFattura($s, $giardini, 'GI-1', '2026-04-10', 1000.00);
    b59ePaga($s, $giardini, $fattura, '2026-05-10');

    // A mano: Luca non ha quote né righe sui tre mastri; il ciclo passivo gli ha scritto 1668, 122000 e 122000 su 6001 e 2201.
    $pagina = b59ePagina($s, di: $luca);
    expect(['righe' => $pagina['timeline'], 'cifre' => b59eCifre($pagina)])->toBe(['righe' => [], 'cifre' => ['€ 0,00', '€ 0,00', '€ 0,00']]);
});

it('controprova — le righe della persona sui tre mastri della lista restano tutte: il rimborso da «Passate gestioni», la rata e l\'incasso su «Crediti verso condomini», l\'anticipo su «Anticipi»', function () {
    // Un credito riportato di € 600,00 assorbito dalla rata 1 (metodo «prima rata»): la quota 1 è −10000 (50000 di spesa − 60000 di riporto),
    // non a giornale. Il rimborso di € 100,00 di quel credito esce da «Passate gestioni» (DARE 2301 con la persona). La rata 2 emessa il 05/02
    // (DARE 1101 50000), l'incasso di € 520,00 con € 20,00 di eccedenza (AVERE 1101 50000 e 2000). Un anticipo di € 30,00 su «Anticipi» come lo
    // scriveva la 1.8.0 (`StoreIncassoRateAction`: AVERE 2101 con il pagante, senza una quota d'appoggio), e come lo scrive ancora il ramo
    // senza quota.
    // A mano: saldo iniziale −60000 (il riporto nelle quote), + 10000 (rimborso) + 50000 (rata 2) − 50000 − 2000 (incasso) − 3000 (anticipo)
    // = −55000; addebiti 10000 + 50000 = € 600,00; versato 50000 + 2000 + 3000 = € 550,00.
    $s = b59eScenario($this, saldo: -60000, metodo: 'prima_rata');
    $quota1 = b59eQuota($s, 1);
    expect((int) DB::table('rate_quote')->where('id', $quota1)->value('importo'))->toBe(-10000);

    $this->actingAs($s['admin'])->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['mario']]), [
        'rata_quote_id' => $quota1, 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-01-20', 'importo' => '100,00', 'nota' => 'Bonifico del 20 gennaio',
    ])->assertSessionHasNoErrors();
    b59eEmetti($s, [2], '2026-02-05');
    b59eIncassa($s, 2, '2026-02-10', '520.00', '20.00');
    $anticipo = ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'data_registrazione' => '2026-03-01',
        'data_competenza' => '2026-03-01', 'numero_protocollo' => 'INC-LEGACY-1', 'causale' => 'Anticipo', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata']);
    $anticipo->righe()->create(['conto_contabile_id' => $s['cassa']->conto_contabile_id, 'cassa_id' => $s['cassa']->id, 'tipo_riga' => 'dare', 'importo' => 3000]);
    $anticipo->righe()->create(['conto_contabile_id' => DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'anticipi_condomini')->value('id'),
        'anagrafica_id' => $s['mario']->id, 'tipo_riga' => 'avere', 'importo' => 3000, 'note' => 'Anticipo / Eccedenza']);

    $ruoli = DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')->where('rs.anagrafica_id', $s['mario']->id)
        ->orderBy('rs.id')->pluck('cc.ruolo')->unique()->values()->all();
    expect($ruoli)->toBe(['passate_gestioni', 'crediti_condomini', 'anticipi_condomini']);

    $pagina = b59ePagina($s);
    $pdf = b59ePdf($s);
    expect([$pagina['saldo'], $pagina['addebiti'], $pagina['versato']])->toBe([-55000, '€ 600,00', '€ 550,00'])
        ->and(count($pagina['timeline']))->toBe(5)
        ->and([$pdf['saldo'], $pdf['addebiti'], $pdf['versato']])->toBe([-55000, '€ 600,00', '€ 550,00']);
});

it('Coda 288 — la regolazione su una voce del professionista (mastro «compensi_professionisti») del fornitore di Mario resta fuori: la lista dei mastri entra, non esce', function () {
    $s = b59eScenario($this);
    $studio = b59eFornitore($s, 'Studio Rossi');
    $this->actingAs($s['admin'])->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]), [
        'nome' => 'Compenso del revisore', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'professionista', 'tipo' => 'spesa', 'importo' => '600,00',
        'descrizione' => '', 'note' => '', 'parent_id' => null, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100,
        'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0, 'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();
    $voce = Conto::where('piano_conto_id', $s['pc']->id)->where('nome', 'Compenso del revisore')->firstOrFail();

    b59eEmetti($s, [1], '2026-01-05');
    $this->actingAs($s['admin'])->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $voce->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => $studio->id,
        'data_operazione' => '2026-03-10', 'causale' => 'Acconto al revisore', 'importo' => 73.20,
    ])->assertSessionHasNoErrors();

    // Il presupposto: la riga del fornitore, € 73,20 con la persona, sta sul mastro dei compensi dei professionisti, un mastro che nessuna lista di
    // esclusione dei conti del ciclo passivo (costi, debiti verso fornitori e verso l'Erario) nomina.
    $ruolo = DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')->where('rs.anagrafica_id', $s['mario']->id)
        ->where('rs.importo', 7320)->value('cc.ruolo');
    expect($ruolo)->toBe('compensi_professionisti');

    // A mano (decisione 153.2): la lista dei mastri della posizione di condòmino entra, e la riga del compenso non ne fa parte. L'estratto di Mario è
    // la sola rata: saldo 50000, addebiti 50000, versato 0.
    $pagina = b59ePagina($s);
    expect(b59eCifre($pagina))->toBe(B59E_SOLO_LA_RATA)
        ->and(array_column($pagina['timeline'], 'tipo_movimento'))->toBe(['emissione_rata'])
        ->and(b59eCifre(b59ePdf($s)))->toBe(B59E_SOLO_LA_RATA);
});
