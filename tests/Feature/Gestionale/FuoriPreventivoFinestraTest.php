<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 693 (decisioni 532 e 533): la finestra «Configura nuova voce di spesa» del pulsante «Fuori preventivo», dal lato del server e della Dashboard.
 *
 * Sono le prove E1, E5, D, D bis e D ter della sessione del supporto del 18/11/2026 (scritte sulla 1.11.0 con i `dump`, in
 * `docs/riferimenti/supporto_roberto_2026-11-18/`), portate nella suite con le asserzioni del comportamento nuovo. Lo scenario è quello delle Code 686 e
 * 687 (`SpesaNonPrevistaScenario`): due appartamenti a 600 e 400 millesimi, la fattura da € 1.000,00 + IVA 22 % = € 1.220,00. Il corpo è quello che la
 * pagina manda dopo la correzione: la finestra emette anche `strategia_rientro`, `fondo_patrimoniale_id` e `motivazione_urgenza`, e con l'urgenza la
 * pagina aggiunge la giustificazione dello sforo (`override_budget`), con lo sforo delle altre righe quando ce n'è (difetto (d)).
 *
 * - E1, (a): l'urgenza con «Usa fondo riserva» e il giustificativo scritto si salva, con una copertura sola dal fondo; con il giustificativo vuoto il server
 *   la rifiuta ancora (controprova: la guardia è giusta, era la pagina a mandarlo vuoto).
 * - E5, (b): con la delibera la strategia e il fondo restano nella fattura, il fondo si usa (la copertura pianificata, poi il giroconto: DARE banca, AVERE
 *   fondo), la Dashboard dice la strategia e non «Finanzia spesa».
 * - D: le strade del pulsante, sulla gestione ordinaria e sulla straordinaria; D bis: dopo l'urgenza sull'ordinaria la spesa si mette a rate da «Spese fuori
 *   preventivo» (la frase della finestra, decisione 533), non da «Dalle voci del preventivo»; D ter, (f): la Dashboard riconosce la voce nata dal pulsante
 *   dal segno tecnico, non dal nome.
 * - (d), dal lato del server: con l'urgenza e lo sforo di un'altra riga la copertura dal fondo è di chi l'ha scelta, l'imprevisto o le voci, per la sua parte.
 *
 * Cosa resta scoperto: la finestra e la pagina (i vitest `ModalSpesaImprevista.test.ts`, `FatturaRegisterNewFuoriPreventivo.test.ts`,
 * `DashboardFuoriPreventivo.test.ts`); la conferma del giroconto dalla pagina dei giroconti (qui dalla sua rotta); le altre letture della strategia dello
 * sforo per voce (la pagina del piano rate, il selettore delle voci, il piano dei conti), che leggono ancora la sola giustificazione dello sforo.
 */

use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\DoubleEntryValidator;
use App\Services\Gestionale\SaldoCassaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SpesaNonPrevistaScenario.php';

uses(RefreshDatabase::class);

/** Lo scenario, con la gestione ordinaria al posto della straordinaria quando serve. */
function fp693Scenario($t, string $gestione = 'straordinaria'): array
{
    $r = snpScenario($t);
    if ($gestione === 'ordinaria') {
        $r['g'] = $r['s']->gestioni['Ordinaria 2026']['g'];
        $r['pc'] = $r['s']->gestioni['Ordinaria 2026']['pc'];
    }

    return $r;
}

/** Un fondo di riserva libero, con il suo saldo iniziale in centesimi: la cassa che la finestra offre nel menu del fondo. */
function fp693Fondo(array $r, int $saldoCents = 500000): Cassa
{
    $s = $r['s'];
    $conto = DB::table('conti_contabili')->insertGetId(['condominio_id' => $s->c->id, 'ruolo' => 'fondo_riserva', 'codice' => '1010.F693', 'nome' => 'Fondo di riserva',
        'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);

    return Cassa::create(['condominio_id' => $s->c->id, 'nome' => 'Fondo di riserva', 'tipo' => 'fondo', 'sottotipo_fondo' => 'generico', 'conto_contabile_id' => $conto,
        'saldo_iniziale' => $saldoCents, 'attiva' => true]);
}

/** I dati della finestra come li emette dopo la correzione: con l'urgenza il giustificativo scritto, con qualunque strategia. */
function fp693Finestra(array $r, string $origine, string $strategia, ?int $fondoId = null, string $nome = 'Riparazione colonna', string $motivazione = 'Perdita improvvisa dalla colonna di scarico'): array
{
    $urgenza = $origine === 'gestione_corrente';

    return [
        'nome_voce' => $nome, 'tabella_millesimale_id' => $r['s']->tabelle['Proprietà']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0,
        'percentuale_usufruttuario' => 0, 'show_advanced_percentages' => false, 'origine_decisionale' => $origine, 'data_assemblea' => $urgenza ? '' : '2026-10-15',
        'strategia_rientro' => $strategia, 'fondo_patrimoniale_id' => $fondoId, 'motivazione_urgenza' => $urgenza ? $motivazione : '',
        'tipo_ripartizione' => 'millesimale', 'is_ordinario' => $urgenza, 'richiede_copertura' => true, 'motivazione_sforo' => $urgenza ? $motivazione : '',
    ];
}

/** La giustificazione dello sforo che la pagina aggiunge con l'urgenza e il solo imprevisto (€ 1.220,00). */
function fp693SforoDellImprevisto(array $finestra, int $importoCents = 122000): array
{
    return ['motivazione' => $finestra['motivazione_urgenza'], 'importo_sforo' => $importoCents, 'strategia_rientro' => $finestra['strategia_rientro'],
        'fondo_patrimoniale_id' => $finestra['fondo_patrimoniale_id']];
}

/** La registrazione dalla rotta vera, con le righe date; restituisce la risposta. */
function fp693Registra($t, array $r, array $righe, array $datiExtra)
{
    $s = $r['s'];

    return $t->actingAs($s->admin)->post(route('admin.gestionale.fatture.store', [$s->c]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'FT-ROB',
        'data_documento' => '2026-10-20', 'data_scadenza' => '2026-11-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'approvata',
        'dati_extra' => array_merge(['fiscal' => [], 'competenza' => null, 'override_budget' => null], $datiExtra),
        'righe' => $righe,
    ]);
}

/** La riga «Fuori preventivo» da € 1.000,00 + IVA 22 %, comune. */
function fp693RigaImprevisto(): array
{
    return ['descrizione' => 'Riparazione colonna', 'importo_imponibile' => 1000.00, 'aliquota_iva' => 22, 'conto_id' => null, 'is_sopravvenienza' => true];
}

/** Una voce del preventivo della gestione dello scenario, con il suo importo in euro come lo scrive la pagina («100,00»). */
function fp693Voce($t, array $r, string $nome, string $importo): Conto
{
    $s = $r['s'];
    $t->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s->c, $s->e, $r['pc']]), [
        'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'spesa', 'importo' => $importo, 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $s->tabelle['Proprietà']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();

    return Conto::where('piano_conto_id', $r['pc']->id)->where('nome', $nome)->sole();
}

/** Le prop della Dashboard del gestionale. */
function fp693Dashboard($t, array $r): array
{
    return $t->actingAs($r['s']->admin)->get(route('admin.gestionale.index', [$r['s']->c]))->viewData('page')['props'];
}

/** Le coperture dal fondo della fattura: importo, fondo, stato. */
function fp693CopertureFondo(FatturaPassiva $f): array
{
    return $f->coperture()->where('tipo_copertura', 'fondo_riserva')->orderBy('id')->get()
        ->map(fn ($c) => ['importo' => (int) $c->importo, 'fondo_id' => (int) $c->fondo_id, 'stato' => $c->stato])->all();
}

// --- E1, difetto (a): l'urgenza con «Usa fondo riserva» ----------------------------------------------------------------------------------------------

it('E1, 693 (a) — urgenza con «Usa fondo riserva» e il giustificativo scritto: la fattura si salva, una copertura sola dal fondo, e ricorda la strategia', function () {
    $r = fp693Scenario($this);
    $fondo = fp693Fondo($r);
    $finestra = fp693Finestra($r, 'gestione_corrente', 'fondo_riserva', $fondo->conto_contabile_id);

    fp693Registra($this, $r, [fp693RigaImprevisto()], ['log_legale_sopravvenienza' => $finestra, 'override_budget' => fp693SforoDellImprevisto($finestra)])
        ->assertSessionHasNoErrors();

    $f = FatturaPassiva::sole();
    expect($f->stato_approvazione)->toBe('sforo_motivato')
        ->and(fp693CopertureFondo($f))->toBe([['importo' => 122000, 'fondo_id' => $fondo->conto_contabile_id, 'stato' => 'pianificata']])
        // La fattura ricorda ciò che la finestra ha scelto: prima si perdeva (il log non era nei dati della fattura).
        ->and($f->dati_extra['log_legale_sopravvenienza']['strategia_rientro'] ?? null)->toBe('fondo_riserva')
        ->and($f->dati_extra['log_legale_sopravvenienza']['fondo_patrimoniale_id'] ?? null)->toBe($fondo->conto_contabile_id)
        ->and($f->dati_extra['log_legale_sopravvenienza']['motivazione_urgenza'] ?? null)->toBe('Perdita improvvisa dalla colonna di scarico');
});

it('E1 — controprova: con il giustificativo vuoto (la pagina di prima con il fondo) il server rifiuta ancora, con il messaggio della motivazione', function () {
    $r = fp693Scenario($this);
    $fondo = fp693Fondo($r);
    $finestra = array_merge(fp693Finestra($r, 'gestione_corrente', 'fondo_riserva', $fondo->conto_contabile_id), ['motivazione_urgenza' => '', 'motivazione_sforo' => '']);

    fp693Registra($this, $r, [fp693RigaImprevisto()], ['log_legale_sopravvenienza' => $finestra, 'override_budget' => fp693SforoDellImprevisto($finestra)])
        ->assertSessionHasErrors(['dati_extra.override_budget.motivazione' => 'La motivazione è obbligatoria quando si supera il budget.']);
    expect(FatturaPassiva::count())->toBe(0);
});

// --- E5, difetto (b): la delibera con «Usa fondo riserva» -------------------------------------------------------------------------------------------

it('E5, 693 (b) — delibera con «Usa fondo riserva»: la copertura dal fondo nasce pianificata, la Dashboard dice «Fondo riserva», il giroconto scrive DARE banca e AVERE fondo', function () {
    $r = fp693Scenario($this);
    $s = $r['s'];
    $fondo = fp693Fondo($r);
    $banca = $s->cassa();

    fp693Registra($this, $r, [fp693RigaImprevisto()], ['log_legale_sopravvenienza' => fp693Finestra($r, 'delibera_assembleare', 'fondo_riserva', $fondo->conto_contabile_id)])
        ->assertSessionHasNoErrors();

    $f = FatturaPassiva::sole();
    // La delibera non è uno sforo da ratificare: la fattura resta approvata, e il fondo si usa lo stesso, per la parte comune della spesa.
    expect($f->stato_approvazione)->toBe('approvata')
        ->and(fp693CopertureFondo($f))->toBe([['importo' => 122000, 'fondo_id' => $fondo->conto_contabile_id, 'stato' => 'pianificata']])
        ->and($f->dati_extra['log_legale_sopravvenienza']['strategia_rientro'] ?? null)->toBe('fondo_riserva')
        ->and($f->dati_extra['log_legale_sopravvenienza']['fondo_patrimoniale_id'] ?? null)->toBe($fondo->conto_contabile_id);

    // La Dashboard: la fattura in sospeso ha la sua strategia (la pagina scrive «Coperta dal fondo patrimoniale», non «Finanzia spesa»).
    $sospese = collect(fp693Dashboard($this, $r)['fattureScoperte'])->map(fn ($x) => [$x['numero'], $x['strategia'], $x['totale_scoperto']])->all();
    expect($sospese)->toBe([['FT-ROB', 'fondo_riserva', 122000]]);

    // La conferma: il giroconto dal fondo alla banca, come lo propone la fattura. È la stessa strada dello sforo (`RegistraGirocontoAction`).
    $copertura = $f->coperture()->where('tipo_copertura', 'fondo_riserva')->sole();
    $saldoPrima = app(SaldoCassaService::class)->saldoDisponibile($fondo->fresh());
    $this->actingAs($s->admin)->post(route('admin.gestionale.giroconti.store', [$s->c]), [
        'cassa_origine_id' => $fondo->id, 'cassa_destinazione_id' => $banca->id, 'importo' => 1220.00, 'data_operazione' => now()->format('Y-m-d'),
        'causale' => 'Copertura della spesa fuori preventivo', 'gestione_id' => $r['g']->id, 'esercizio_id' => $s->e->id, 'fattura_copertura_id' => $copertura->id,
    ])->assertSessionHasNoErrors();

    $gir = ScritturaContabile::where('condominio_id', $s->c->id)->where('tipo_movimento', 'giroconto')->sole();
    $righe = DB::table('righe_scritture')->where('scrittura_id', $gir->id)->orderByDesc('tipo_riga')->get(['conto_contabile_id', 'tipo_riga', 'importo'])
        ->map(fn ($x) => [(int) $x->conto_contabile_id, $x->tipo_riga, (int) $x->importo])->all();
    expect($righe)->toBe([[$banca->conto_contabile_id, 'dare', 122000], [$fondo->conto_contabile_id, 'avere', 122000]])
        ->and(fn () => DoubleEntryValidator::validateOrFail($gir->id))->not->toThrow(\Throwable::class)
        ->and($copertura->fresh()->stato)->toBe('confermata')
        ->and(app(SaldoCassaService::class)->saldoDisponibile($fondo->fresh()))->toBe($saldoPrima - 122000);
});

it('E5, 693 (b) — delibera con «Usa fondo riserva» senza il fondo scelto: rifiutata con una frase, invece di salvarsi senza copertura', function () {
    $r = fp693Scenario($this);

    fp693Registra($this, $r, [fp693RigaImprevisto()], ['log_legale_sopravvenienza' => fp693Finestra($r, 'delibera_assembleare', 'fondo_riserva', null)])
        ->assertSessionHasErrors(['dati_extra.log_legale_sopravvenienza.fondo_patrimoniale_id' => 'Scegli il fondo di riserva che copre la spesa fuori preventivo.']);
    expect(FatturaPassiva::count())->toBe(0);
});

it('693 (b) — delibera con «Usa fondo riserva» e una riga addebitata a un\'unità: rifiutata come con l\'urgenza, perché il fondo ora si usa davvero', function () {
    $r = fp693Scenario($this);
    $fondo = fp693Fondo($r);
    $unita = ['descrizione' => 'Sostituzione rubinetto', 'importo_imponibile' => 100.00, 'aliquota_iva' => 22, 'conto_id' => null, 'immobile_id' => $r['s']->unita['A']->id, 'is_sopravvenienza' => false];

    fp693Registra($this, $r, [fp693RigaImprevisto(), $unita], ['log_legale_sopravvenienza' => fp693Finestra($r, 'delibera_assembleare', 'fondo_riserva', $fondo->conto_contabile_id)])
        ->assertSessionHasErrors(['dati_extra.log_legale_sopravvenienza.strategia_rientro']);
    expect(FatturaPassiva::count())->toBe(0);
});

it('693 (b) — controprova: con l\'urgenza lo stesso caso era già rifiutato (la giustificazione dello sforo con il fondo e una riga di un\'unità)', function () {
    $r = fp693Scenario($this);
    $fondo = fp693Fondo($r);
    $finestra = fp693Finestra($r, 'gestione_corrente', 'fondo_riserva', $fondo->conto_contabile_id);
    $unita = ['descrizione' => 'Sostituzione rubinetto', 'importo_imponibile' => 100.00, 'aliquota_iva' => 22, 'conto_id' => null, 'immobile_id' => $r['s']->unita['A']->id, 'is_sopravvenienza' => false];

    fp693Registra($this, $r, [fp693RigaImprevisto(), $unita], ['log_legale_sopravvenienza' => $finestra, 'override_budget' => fp693SforoDellImprevisto($finestra)])
        ->assertSessionHasErrors(['dati_extra.override_budget.strategia_rientro']);
});

// --- D: le strade del pulsante, strada per strada -------------------------------------------------------------------------------------------------------

it('D, 693 (b) — «Fuori preventivo» sull\'ordinaria e sulla straordinaria, con l\'urgenza o la delibera: la strategia resta nella fattura e la Dashboard la dice', function (string $gestione, string $origine, string $strategia) {
    $r = fp693Scenario($this, $gestione);
    $finestra = fp693Finestra($r, $origine, $strategia);
    $extra = ['log_legale_sopravvenienza' => $finestra];
    if ($origine === 'gestione_corrente') {
        $extra['override_budget'] = fp693SforoDellImprevisto($finestra);
    }

    fp693Registra($this, $r, [fp693RigaImprevisto()], $extra)->assertSessionHasNoErrors();

    $f = FatturaPassiva::sole();
    $riga = DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->sole();
    $voce = Conto::find($riga->conto_id);
    $sospese = collect(fp693Dashboard($this, $r)['fattureScoperte'])->map(fn ($x) => [$x['strategia'], $x['gestione_id'], $x['totale_scoperto']])->all();

    expect($f->stato_approvazione)->toBe($origine === 'gestione_corrente' ? 'sforo_motivato' : 'approvata')
        ->and((int) $f->gestione_id)->toBe($r['g']->id)
        // La voce nata dal pulsante: nella gestione della fattura, sotto il capitolo tecnico, con il segno tecnico (quello che la Dashboard legge, (f)).
        ->and([$voce->nome, $voce->parent?->nome, (bool) $voce->is_tecnico, (int) $voce->pianoConto->gestione_id])
            ->toBe(['Riparazione colonna', 'Integrazioni Straordinarie (Scudo Legale)', true, $r['g']->id])
        ->and($f->dati_extra['log_legale_sopravvenienza']['strategia_rientro'] ?? null)->toBe($strategia)
        // Prima, con la delibera, «nessuna»: la pagina proponeva «Finanzia spesa» qualunque cosa si fosse scelta.
        ->and($sospese)->toBe([[$strategia, $r['g']->id, 122000]])
        // Il carrello di «Spese fuori preventivo» della gestione della fattura la offre: è la strada della rata (la frase della finestra, decisione 533).
        ->and(collect(snpCarrello($this, $r))->pluck('numero_documento')->all())->toBe(['FT-ROB']);
})->with([
    'ordinaria, urgenza, conguaglio' => ['ordinaria', 'gestione_corrente', 'conguaglio_fine_anno'],
    'ordinaria, urgenza, rata' => ['ordinaria', 'gestione_corrente', 'rata_integrativa'],
    'ordinaria, delibera, rata' => ['ordinaria', 'delibera_assembleare', 'rata_integrativa'],
    'ordinaria, delibera, conguaglio' => ['ordinaria', 'delibera_assembleare', 'conguaglio_fine_anno'],
    'straordinaria, urgenza, rata' => ['straordinaria', 'gestione_corrente', 'rata_integrativa'],
    'straordinaria, delibera, rata' => ['straordinaria', 'delibera_assembleare', 'rata_integrativa'],
]);

it('D bis — controprova della frase della finestra: dopo l\'urgenza sull\'ordinaria la spesa non è fra «Dalle voci del preventivo», è nel carrello di «Spese fuori preventivo»', function (string $origine) {
    $r = fp693Scenario($this, 'ordinaria');
    $finestra = fp693Finestra($r, $origine, 'rata_integrativa');
    $extra = ['log_legale_sopravvenienza' => $finestra];
    if ($origine === 'gestione_corrente') {
        $extra['override_budget'] = fp693SforoDellImprevisto($finestra);
    }
    fp693Registra($this, $r, [fp693RigaImprevisto()], $extra)->assertSessionHasNoErrors();
    $voce = Conto::where('nome', 'Riparazione colonna')->sole();

    expect(collect(snpSelettore($this, $r))->pluck('id')->all())->not->toContain($voce->id)
        ->and(collect(snpCarrello($this, $r))->pluck('numero_documento')->all())->toBe(['FT-ROB'])
        ->and(collect(fp693Dashboard($this, $r)['fattureScoperte'])->pluck('gestione_id')->all())->toBe([$r['g']->id]);
})->with(['urgenza' => 'gestione_corrente', 'delibera' => 'delibera_assembleare']);

// --- D ter, difetto (f): la Dashboard riconosce la voce nata dal pulsante dal segno tecnico --------------------------------------------------------------

it('D ter, 693 (f) — la voce nata da «Fuori preventivo», anche rinominata, arriva alla Dashboard con il segno «fuori preventivo»', function (string $nome, string $origine) {
    $r = fp693Scenario($this, 'ordinaria');
    $finestra = fp693Finestra($r, $origine, 'rata_integrativa', null, $nome);
    $extra = ['log_legale_sopravvenienza' => $finestra];
    if ($origine === 'gestione_corrente') {
        $extra['override_budget'] = fp693SforoDellImprevisto($finestra);
    }
    fp693Registra($this, $r, [fp693RigaImprevisto()], $extra)->assertSessionHasNoErrors();

    $orfani = collect(fp693Dashboard($this, $r)['copertura']['orfani'])->map(fn ($o) => [$o['nome'], $o['fuori_preventivo'] ?? null, $o['importo']])->all();
    expect($orfani)->toBe([[$nome, true, 122000]]);
})->with([
    'il nome proposto' => ['Imprevisto - Idraulica Srl', 'gestione_corrente'],
    'rinominata, urgenza' => ['Riparazione colonna', 'gestione_corrente'],
    'rinominata, delibera' => ['Riparazione colonna', 'delibera_assembleare'],
]);

it('D ter, 693 (f) — una voce del preventivo che l\'amministratore ha chiamato «Imprevisto - …» non ha il segno: è uno sforo come gli altri', function () {
    $r = fp693Scenario($this, 'ordinaria');
    $voce = fp693Voce($this, $r, 'Imprevisto - Tetto', '100,00');
    // € 200,00 + IVA 22 % = € 244,00 su una voce da € 100,00, con la motivazione dello sforo: scoperti € 144,00.
    fp693Registra($this, $r, [['descrizione' => 'Riparazione tetto', 'importo_imponibile' => 200.00, 'aliquota_iva' => 22, 'conto_id' => $voce->id, 'is_sopravvenienza' => false]], [
        'override_budget' => ['motivazione' => 'Grondaia rotta dal temporale', 'importo_sforo' => 14400, 'strategia_rientro' => 'rata_integrativa', 'fondo_patrimoniale_id' => null],
    ])->assertSessionHasNoErrors();

    $orfani = collect(fp693Dashboard($this, $r)['copertura']['orfani'])->map(fn ($o) => [$o['nome'], $o['fuori_preventivo'] ?? null, $o['strategia']])->all();
    expect($orfani)->toBe([['Imprevisto - Tetto', false, 'rata_integrativa']]);
});

// --- (d), dal lato del server: l'urgenza e lo sforo di un'altra riga ----------------------------------------------------------------------------------

/** La fattura dell'urgenza con la riga «Fuori preventivo» (€ 1.220,00) e una riga su «Pulizie scale» (€ 100,00) da € 200,00 + IVA: sforo delle voci € 144,00. */
function fp693UrgenzaConSforoDelleVoci($t, array $r, array $finestra, array $voci)
{
    $voce = fp693Voce($t, $r, 'Pulizie scale', '100,00');

    // Come la compone la pagina dopo la correzione: lo sforo è di tutta la fattura (€ 1.220,00 + € 144,00), la strategia è quella scelta per le voci,
    // la motivazione tiene le due.
    return fp693Registra($t, $r, [fp693RigaImprevisto(),
        ['descrizione' => 'Pulizie straordinarie', 'importo_imponibile' => 200.00, 'aliquota_iva' => 22, 'conto_id' => $voce->id, 'is_sopravvenienza' => false]], [
        'log_legale_sopravvenienza' => $finestra,
        'override_budget' => ['motivazione' => $finestra['motivazione_urgenza'].' — '.$voci['motivazione'], 'importo_sforo' => 136400, 'budget_residuo_al_momento' => -14400,
            'timestamp' => '2026-10-20T10:00:00.000Z', 'strategia_rientro' => $voci['strategia'], 'fondo_patrimoniale_id' => $voci['fondo']],
    ]);
}

it('693 (d) — urgenza con il fondo per l\'imprevisto e il conguaglio per lo sforo delle voci: il fondo copre la sola spesa fuori preventivo (€ 1.220,00)', function () {
    $r = fp693Scenario($this, 'ordinaria');
    $fondo = fp693Fondo($r);
    $finestra = fp693Finestra($r, 'gestione_corrente', 'fondo_riserva', $fondo->conto_contabile_id);

    fp693UrgenzaConSforoDelleVoci($this, $r, $finestra, ['motivazione' => 'Pulizie dopo il cantiere', 'strategia' => 'conguaglio_fine_anno', 'fondo' => null])
        ->assertSessionHasNoErrors();

    $f = FatturaPassiva::sole();
    expect($f->stato_approvazione)->toBe('sforo_motivato')
        ->and((int) $f->dati_extra['override_budget']['importo_sforo'])->toBe(136400)
        ->and(fp693CopertureFondo($f))->toBe([['importo' => 122000, 'fondo_id' => $fondo->conto_contabile_id, 'stato' => 'pianificata']]);
});

it('693 (d) — urgenza con il conguaglio per l\'imprevisto e il fondo per lo sforo delle voci: il fondo copre il solo sforo delle voci (€ 144,00), e la Dashboard dice le due strategie', function () {
    $r = fp693Scenario($this, 'ordinaria');
    $fondo = fp693Fondo($r);
    $finestra = fp693Finestra($r, 'gestione_corrente', 'conguaglio_fine_anno');

    fp693UrgenzaConSforoDelleVoci($this, $r, $finestra, ['motivazione' => 'Pulizie dopo il cantiere', 'strategia' => 'fondo_riserva', 'fondo' => $fondo->conto_contabile_id])
        ->assertSessionHasNoErrors();

    $f = FatturaPassiva::sole();
    expect(fp693CopertureFondo($f))->toBe([['importo' => 14400, 'fondo_id' => $fondo->conto_contabile_id, 'stato' => 'pianificata']]);

    $props = fp693Dashboard($this, $r);
    $strategie = collect($props['copertura']['orfani'])->mapWithKeys(fn ($o) => [$o['nome'] => $o['strategia']])->all();
    // La voce nata dal pulsante ha la strategia dell'imprevisto, la voce del preventivo quella del suo sforo; la fattura in sospeso quella dell'imprevisto.
    expect($strategie['Riparazione colonna'] ?? 'coperta')->toBe('conguaglio')
        ->and(collect($props['fattureScoperte'])->pluck('strategia')->all())->toBe(['conguaglio_fine_anno']);
});

/*
 * Il rilievo basso della quarta prova della beta.12 sulla Coda 693 (§ 6.2): il server non accetta come copertura un fondo che la finestra non offre, e il
 * messaggio del giroconto non dice più «vincolato ()» per un fondo senza tipo.
 */

it('693, il rilievo basso della quarta prova — un fondo senza tipo (che la finestra non offre) si rifiuta, con la delibera e con l\'urgenza, e niente si scrive', function () {
    $r = fp693Scenario($this);
    $fondo = fp693Fondo($r);
    // Un fondo di prima che il tipo esistesse: la colonna vuota (il modello la riempirebbe con «generico» a ogni salvataggio).
    DB::table('casse')->where('id', $fondo->id)->update(['sottotipo_fondo' => null]);
    $frase = 'Il fondo «Fondo di riserva» non si può usare per questa spesa: non ha un tipo, e un fondo senza tipo conta come vincolato. Scegli un fondo generico, '
        . 'o sbloccalo con la deroga assembleare dalla scheda della cassa, con la motivazione.';
    // Con il codice di prima la fattura si registrava, con la copertura dal fondo pianificata, e il giroconto che la conferma poi la rifiutava.
    fp693Registra($this, $r, [fp693RigaImprevisto()], ['log_legale_sopravvenienza' => fp693Finestra($r, 'delibera_assembleare', 'fondo_riserva', $fondo->conto_contabile_id)])
        ->assertSessionHasErrors(['dati_extra.log_legale_sopravvenienza.fondo_patrimoniale_id' => $frase]);
    $finestra = fp693Finestra($r, 'gestione_corrente', 'fondo_riserva', $fondo->conto_contabile_id);
    fp693Registra($this, $r, [fp693RigaImprevisto()], ['log_legale_sopravvenienza' => $finestra, 'override_budget' => fp693SforoDellImprevisto($finestra)])
        ->assertSessionHasErrors(['dati_extra.override_budget.fondo_patrimoniale_id' => $frase]);
    expect(FatturaPassiva::count())->toBe(0);
    // Sbloccato dall'assemblea si può usare: la fattura si registra.
    DB::table('casse')->where('id', $fondo->id)->update(['is_override_assemblea' => true, 'motivazione_override' => 'Delibera del 15/10/2026']);
    fp693Registra($this, $r, [fp693RigaImprevisto()], ['log_legale_sopravvenienza' => fp693Finestra($r, 'delibera_assembleare', 'fondo_riserva', $fondo->conto_contabile_id)])
        ->assertSessionHasNoErrors();
});

it('693, il rilievo basso della quarta prova — il giroconto da un fondo senza tipo si rifiuta con una frase intera, non con «vincolato ()»', function () {
    $r = fp693Scenario($this);
    $s = $r['s'];
    $fondo = fp693Fondo($r);
    DB::table('casse')->where('id', $fondo->id)->update(['sottotipo_fondo' => null]);
    $this->actingAs($s->admin)->post(route('admin.gestionale.giroconti.store', [$s->c]), [
        'cassa_origine_id' => $fondo->id, 'cassa_destinazione_id' => $s->cassa()->id, 'importo' => 100.00, 'data_operazione' => now()->format('Y-m-d'),
        'causale' => 'Prova', 'gestione_id' => $r['g']->id, 'esercizio_id' => $s->e->id,
    ]);
    $errori = collect(session('errors')?->getBag('default')->all() ?? [])->implode(' ');
    expect($errori)->toContain('Il fondo «Fondo di riserva» non si può usare fuori dalla sua destinazione: non ha un tipo, e un fondo senza tipo conta come vincolato. Per usarlo serve la deroga assembleare')
        ->and($errori)->not->toContain('()');
});
