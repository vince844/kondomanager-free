<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14, tappa 6, Coda 754 (4) e (5) (decisione 696, D2 «Prepara la copertura»): il debito dell'esercizio precedente e la finestra «Configura nuova
 * voce di spesa», dal lato del server.
 *
 * Il caso: nel 2026 l'amministratore registra una fattura del 2025 di € 610,00 (€ 500,00 + IVA 22 %) che non era nel saldo d'apertura; la parte non coperta
 * apre la finestra, con l'origine dell'incarico e la strategia. Il corpo è quello che la pagina manda (`FatturaRegisterNew::doSubmit`), dalla rotta vera.
 *
 * - 754 (4): con «Usa fondo riserva» il programma prepara la copertura dal fondo, pianificata, come per la spesa fuori preventivo dell'anno (Coda 693): il
 *   fondo si muove solo con il giroconto che la conferma. La voce nasce a € 0,00, il costo resta sulla voce, e nessuna strada di un piano rate nuovo la
 *   chiede ai condòmini. Fino alla tappa 6 il server buttava i dati della finestra e non preparava niente (prova G4 della revisione delle guide).
 * - 754 (5): con l'urgenza lo sforo da ratificare ha l'importo vero (prima € 0,00), e il fondo copre una volta sola, con l'importo vero (prima una copertura
 *   da € 0,00 in più, prova G5). Con la delibera la fattura è approvata (prova G6).
 * - Il comando in sola lettura che trova le pregresse già scritte così.
 * - Il giro di correzioni dopo lo scettico della tappa: un piano della gestione senza la riga delle voci (i piani della 1.10) non prende la voce tecnica
 *   della pregressa, né generato né ricalcolato, con ognuna delle tre strategie (rilievo 2); la cassa del fondo con una copertura ancora da confermare non
 *   si elimina (rilievo 5); con «Attesa conguaglio» nessun compito «Emettere rate» (rilievo 10); il piano creato dalla rotta (rilievo 11).
 *
 * Cosa resta scoperto: la finestra e la pagina (i vitest `ModalSpesaImprevista.test.ts` e `FatturaRegisterNewPregressa.test.ts`); il carrello e la
 * Dashboard qui si leggono, non cambiano.
 */

use App\Actions\Cassa\RegistraAperturaCassaAction;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Assemblee\PropostaDelPunto;
use App\Services\CalcoloQuoteService;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\DoubleEntryValidator;
use App\Services\Gestionale\SaldoCassaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SpesaNonPrevistaScenario.php';

uses(RefreshDatabase::class);

/** Lo scenario delle Code 686 e 687 sulla gestione ordinaria, con un fondo di riserva libero da € 5.000,00. */
function b14pfScenario($t): array
{
    $r = snpScenario($t);
    $r['g'] = $r['s']->gestioni['Ordinaria 2026']['g'];
    $r['pc'] = $r['s']->gestioni['Ordinaria 2026']['pc'];
    $s = $r['s'];
    $conto = DB::table('conti_contabili')->insertGetId(['condominio_id' => $s->c->id, 'ruolo' => 'fondo_riserva', 'codice' => '1010.F754', 'nome' => 'Fondo di riserva',
        'tipo' => 'attivo', 'categoria' => 'liquidita', 'created_at' => now(), 'updated_at' => now()]);
    $r['fondo'] = Cassa::create(['condominio_id' => $s->c->id, 'nome' => 'Fondo di riserva', 'tipo' => 'fondo', 'sottotipo_fondo' => 'generico', 'conto_contabile_id' => $conto,
        'saldo_iniziale' => 500000, 'attiva' => true]);

    return $r;
}

/** I dati della finestra come li emette: con l'urgenza il giustificativo scritto. Il fondo è l'id del suo conto, come nel menu della finestra. */
function b14pfFinestra(array $r, string $origine, string $strategia, bool $conFondo = false): array
{
    $urgenza = $origine === 'gestione_corrente';

    return [
        'nome_voce' => 'Debito 2025 Idraulica', 'tabella_millesimale_id' => $r['s']->tabelle['Proprietà']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0,
        'percentuale_usufruttuario' => 0, 'show_advanced_percentages' => false, 'origine_decisionale' => $origine, 'data_assemblea' => $urgenza ? '' : '2026-10-15',
        'strategia_rientro' => $strategia, 'fondo_patrimoniale_id' => $conFondo ? $r['fondo']->conto_contabile_id : null,
        'motivazione_urgenza' => $urgenza ? 'Riparazione della colonna rimasta da pagare' : '', 'tipo_ripartizione' => 'millesimale', 'is_ordinario' => $urgenza,
        'richiede_copertura' => true, 'motivazione_sforo' => $urgenza ? 'Riparazione della colonna rimasta da pagare' : '',
    ];
}

/** La giustificazione dello sforo che la pagina aggiunge con l'urgenza (`sforoDellaFattura.ts`), con l'importo dato in centesimi. */
function b14pfSforo(array $finestra, int $importoCents): array
{
    return ['motivazione' => $finestra['motivazione_urgenza'], 'importo_sforo' => $importoCents, 'strategia_rientro' => $finestra['strategia_rientro'],
        'fondo_patrimoniale_id' => $finestra['fondo_patrimoniale_id']];
}

/** Il debito d'apertura verso il fornitore, in centesimi (il saldo del fornitore si scrive con il meno). */
function b14pfDebitoDApertura(array $r, int $cents): int
{
    return DB::table('saldi')->insertGetId(['condominio_id' => $r['s']->c->id, 'esercizio_id' => $r['s']->e->id, 'fornitore_id' => $r['fornitoreId'], 'saldo_iniziale' => -$cents,
        'created_at' => now(), 'updated_at' => now()]);
}

/** La pregressa da € 500,00 + IVA 22 % dalla rotta vera, con il periodo dell'ottobre 2025; restituisce la risposta. */
function b14pfRegistra($t, array $r, ?array $finestra, ?array $sforo = null, array $extra = [], string $numero = '12')
{
    $s = $r['s'];

    return $t->actingAs($s->admin)->post(route('admin.gestionale.fatture.store', [$s->c]), array_merge([
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => $numero,
        'data_documento' => '2025-10-31', 'data_scadenza' => '2025-11-30', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'approvata',
        'is_pregresso' => true, 'imponibile_pregresso' => 500.00, 'aliquota_iva_pregressa' => 22, 'coperture' => [], 'righe' => [],
        'competenza_dal' => '2025-10-01', 'competenza_al' => '2025-10-31', 'data_competenza_originaria' => '2025-10-31',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => $sforo, 'log_legale_sopravvenienza' => $finestra],
    ], $extra));
}

/** Le coperture della fattura: tipo, importo, stato, fondo e voce. */
function b14pfCoperture(FatturaPassiva $f): array
{
    return $f->coperture()->orderBy('id')->get()
        ->map(fn ($c) => [$c->tipo_copertura, (int) $c->importo, $c->stato, $c->fondo_id === null ? null : (int) $c->fondo_id])->all();
}

/** Le voci nella riga di un piano rate (`piano_rate_capitoli`), per nome, in ordine di voce. */
function b14pfVociDellaRiga(PianoRate $p): array
{
    return DB::table('piano_rate_capitoli')->join('conti', 'conti.id', '=', 'piano_rate_capitoli.conto_id')->where('piano_rate_capitoli.piano_rate_id', $p->id)
        ->orderBy('conti.id')->pluck('conti.nome')->all();
}

/** Il piano ordinario creato dalla rotta vera, «Dalle voci del preventivo» senza sceglierle, e generato subito: le voci della sua riga e le quote. */
function b14pfPianoDallaRotta($t, array $r, string $nome = 'Preventivo dalla rotta'): array
{
    $s = $r['s'];
    $t->actingAs($s->admin)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $r['g']->id, 'nome' => $nome, 'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => 1,
    ])->assertSessionHasNoErrors();
    $p = PianoRate::where('nome', $nome)->sole();

    return ['voci' => b14pfVociDellaRiga($p), 'quote' => snpPerPersona($r, $p)];
}

/** Le righe della scrittura di competenza della fattura: mastro, verso, importo, voce. */
function b14pfRighe(FatturaPassiva $f): array
{
    $sc = DB::table('fattura_scrittura')->where('fattura_passiva_id', $f->id)->where('tipo', 'competenza')->value('scrittura_contabile_id');

    return DB::table('righe_scritture')->join('conti_contabili', 'conti_contabili.id', '=', 'righe_scritture.conto_contabile_id')->where('scrittura_id', $sc)
        ->orderBy('righe_scritture.id')->get(['conti_contabili.ruolo', 'righe_scritture.tipo_riga', 'righe_scritture.importo', 'righe_scritture.voce_spesa_id'])
        ->map(fn ($x) => [$x->ruolo, $x->tipo_riga, (int) $x->importo, $x->voce_spesa_id === null ? null : (int) $x->voce_spesa_id])->all();
}

// --- 754 (4): «Usa fondo riserva» sulla pregressa -----------------------------------------------------------------------------------------------------

it('754 (4), G4 — delibera e «Usa fondo riserva»: nasce la copertura dal fondo di € 610,00, pianificata, con il fondo e la frase della delibera', function () {
    $r = b14pfScenario($this);

    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'fondo_riserva', true))->assertSessionHasNoErrors();

    $f = FatturaPassiva::sole();
    $voce = Conto::where('nome', 'Debito 2025 Idraulica')->sole();
    expect($f->stato_approvazione)->toBe('approvata')
        // Prima: una copertura «sopravvenienza» sola, che il carrello e la Dashboard offrono da mettere a rate. Ora quella parte la copre il fondo.
        ->and(b14pfCoperture($f))->toBe([['fondo_riserva', 61000, 'pianificata', $r['fondo']->conto_contabile_id]])
        ->and($f->coperture()->sole()->nota_amministratore)->toBe('Copertura del debito dell\'esercizio precedente «Debito 2025 Idraulica» (delibera del 15/10/2026)')
        // La copertura sa su quale voce sta il costo che copre: è la voce nata dalla finestra, sotto il capitolo tecnico.
        ->and((int) $f->coperture()->sole()->conto_id)->toBe($voce->id)
        // Il costo resta sulla voce (il rendiconto lo vede), e il debito verso il fornitore è quello di sempre.
        ->and(b14pfRighe($f))->toBe([['sopravvenienze_passive', 'dare', 61000, $voce->id], ['debiti_fornitori', 'avere', 61000, null]]);
});

it('754 (4), G2 con il fondo — € 200,00 dal saldo d\'apertura e € 410,00 dal fondo', function () {
    $r = b14pfScenario($this);
    $debito = b14pfDebitoDApertura($r, 20000);

    // Come la pagina: il debito d'apertura scelto diventa la copertura «rata_0» (`doSubmit`), la parte che resta apre la finestra.
    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'fondo_riserva', true), null, [
        'saldo_patrimoniale_id' => $debito, 'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 200.00, 'fonte_id' => $debito]],
    ])->assertSessionHasNoErrors();

    $f = FatturaPassiva::sole();
    $voce = Conto::where('nome', 'Debito 2025 Idraulica')->sole();
    expect(b14pfCoperture($f))->toBe([['rata_0', 20000, 'pianificata', null], ['fondo_riserva', 41000, 'pianificata', $r['fondo']->conto_contabile_id]])
        ->and((int) $voce->importo)->toBe(0)
        ->and(b14pfRighe($f))->toBe([['passate_gestioni', 'dare', 20000, null], ['sopravvenienze_passive', 'dare', 41000, $voce->id], ['debiti_fornitori', 'avere', 61000, null]]);
});

it('754 (4), la catena — la copertura in «Giroconti» e sulla scheda, il giroconto che la conferma, il fondo −€ 610,00, la frase del conguaglio', function () {
    $r = b14pfScenario($this);
    $s = $r['s'];
    $banca = $s->cassa();

    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'fondo_riserva', true))->assertSessionHasNoErrors();
    $f = FatturaPassiva::sole();

    // «Giroconti» propone la copertura di € 610,00 dal fondo; la scheda della fattura ha il banner «in attesa di conferma» (le coperture dal fondo positive).
    $inAttesa = collect($this->actingAs($s->admin)->get(route('admin.gestionale.giroconti.create', [$s->c]))->assertOk()->viewData('page')['props']['coperture_in_attesa'])
        ->map(fn ($c) => [$c['fattura_numero'], (int) $c['importo'], (int) $c['fondo_id']])->all();
    expect($inAttesa)->toBe([['12', 61000, $r['fondo']->conto_contabile_id]]);
    $scheda = collect($this->actingAs($s->admin)->get(route('admin.gestionale.fatture.show', [$s->c, $f]))->assertOk()->viewData('page')['props']['fattura']['coperture'])
        ->filter(fn ($c) => $c['tipo_copertura'] === 'fondo_riserva' && (int) $c['importo'] > 0 && $c['stato'] === 'pianificata');
    expect($scheda)->toHaveCount(1);

    // La conferma: il giroconto dal fondo alla banca, come lo propone la fattura (`RegistraGirocontoAction`).
    $copertura = $f->coperture()->where('tipo_copertura', 'fondo_riserva')->sole();
    $saldoPrima = app(SaldoCassaService::class)->saldoDisponibile($r['fondo']->fresh());
    $this->actingAs($s->admin)->post(route('admin.gestionale.giroconti.store', [$s->c]), [
        'cassa_origine_id' => $r['fondo']->id, 'cassa_destinazione_id' => $banca->id, 'importo' => 610.00, 'data_operazione' => now()->format('Y-m-d'),
        'causale' => 'Copertura del debito dell\'esercizio precedente', 'gestione_id' => $r['g']->id, 'esercizio_id' => $s->e->id, 'fattura_copertura_id' => $copertura->id,
    ])->assertSessionHasNoErrors();

    $gir = ScritturaContabile::where('condominio_id', $s->c->id)->where('tipo_movimento', 'giroconto')->sole();
    expect($copertura->fresh()->stato)->toBe('confermata')
        ->and(fn () => DoubleEntryValidator::validateOrFail($gir->id))->not->toThrow(\Throwable::class)
        ->and(app(SaldoCassaService::class)->saldoDisponibile($r['fondo']->fresh()))->toBe($saldoPrima - 61000);

    // Il conguaglio del rendiconto dice la copertura dal fondo della voce, con la cifra pagata dal fondo.
    $frasi = app(ConguaglioDelRendiconto::class)->copertureDaiFondi($s->e)[$r['g']->id] ?? [];
    expect($frasi)->toHaveCount(1)
        ->and($frasi[0])->toContain('Debito 2025 Idraulica: la fattura n. 12 indica la copertura dello sforo con il fondo «Fondo di riserva», € 610,00.')
        ->and($frasi[0])->toContain('Il fondo ha già pagato € 610,00.');
});

it('754 (4) — un piano della gestione senza voci (della 1.10) non la chiede ai condòmini: la voce nasce a € 0,00, il preventivo chiede le sue voci, il carrello non la offre, nessun promemoria «Emettere rate»', function () {
    $r = b14pfScenario($this);
    $s = $r['s'];
    // Una voce del preventivo, perché il piano abbia che cosa chiedere: € 1.000,00 sulla tabella di proprietà (600 e 400 millesimi).
    $s->voce('Ordinaria 2026', 'Pulizie scale', 100000, ['Proprietà' => 100]);

    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'fondo_riserva', true))->assertSessionHasNoErrors();
    $f = FatturaPassiva::sole();
    $voce = Conto::where('nome', 'Debito 2025 Idraulica')->sole();

    // Un piano senza la riga delle voci, come quelli della 1.10: il motore prende le voci radice della gestione (`CalcoloQuoteService::calcolaPerGestione`).
    // La creazione dalla rotta è nella prova qui sotto.
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026')->genera('Preventivo 2026');

    expect((int) $voce->importo)->toBe(0)
        // Il preventivo chiede € 1.000,00, cioè € 600,00 e € 400,00: non € 1.610,00, la voce coperta dal fondo non c'è.
        ->and($s->somme('Preventivo 2026'))->toBe(['ugo' => 60000, 'bice' => 40000])
        ->and(collect(snpCarrello($this, $r))->pluck('numero_documento')->all())->not->toContain('12')
        ->and(DB::table('eventi')->where('meta->context->fattura_id', $f->id)->where('meta->type', 'emissione_rata_sopravvenienza')->exists())->toBeFalse();
});

it('754 (4), rilievo 11 dello scettico — il piano creato dalla rotta, «Dalle voci del preventivo» senza sceglierle, chiede le sole voci del preventivo con ogni strategia', function (string $strategia) {
    $r = b14pfScenario($this);
    $s = $r['s'];
    $s->voce('Ordinaria 2026', 'Pulizie scale', 100000, ['Proprietà' => 100]);
    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', $strategia, $strategia === 'fondo_riserva'))->assertSessionHasNoErrors();

    // La creazione prende le voci radice visibili che nessun piano ha (`PianoRateController::capitoliOrfani`): il capitolo tecnico della pregressa no,
    // con l'importo della voce o senza. È la controprova del rilievo 2, che riguarda i soli piani senza la riga delle voci.
    expect(b14pfPianoDallaRotta($this, $r))->toBe(['voci' => ['Pulizie scale'], 'quote' => ['ugo' => 60000, 'bice' => 40000]]);
})->with(['rata_integrativa', 'conguaglio_fine_anno', 'fondo_riserva']);

it('754 (4), controprova — con «Attesa conguaglio» tutto come oggi: la voce con € 610,00, la copertura «sopravvenienza», nessuna dal fondo', function () {
    $r = b14pfScenario($this);

    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'conguaglio_fine_anno'))->assertSessionHasNoErrors();

    $f = FatturaPassiva::sole();
    $voce = Conto::where('nome', 'Debito 2025 Idraulica')->sole();
    expect($f->stato_approvazione)->toBe('approvata')
        ->and((int) $voce->importo)->toBe(61000)
        ->and($f->coperture()->get()->map(fn ($c) => [$c->tipo_copertura, (int) $c->importo, $c->conto_id === null ? null : (int) $c->conto_id])->all())
            ->toBe([['sopravvenienza', 61000, $voce->id]])
        ->and(collect(snpCarrello($this, $r))->pluck('numero_documento')->all())->toBe(['12']);
});

it('754 (4) — lo storno della pregressa coperta dal fondo: la nota toglie il costo dalla voce, «passate gestioni» non si muove, il fondo non resta impegnato', function () {
    $r = b14pfScenario($this);
    $s = $r['s'];
    $debito = b14pfDebitoDApertura($r, 20000);
    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'fondo_riserva', true), null, [
        'saldo_patrimoniale_id' => $debito, 'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 200.00, 'fonte_id' => $debito]],
    ])->assertSessionHasNoErrors();
    $f = FatturaPassiva::sole();
    $voce = Conto::where('nome', 'Debito 2025 Idraulica')->sole();
    expect($f->coperture()->where('tipo_copertura', 'fondo_riserva')->count())->toBe(1);

    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.storno', [$s->c, $f]))->assertSessionHasNoErrors();

    $nota = FatturaPassiva::where('tipo_documento', 'nota_credito')->sole();
    $netto = fn (string $ruolo, ?int $voceId = null) => (int) DB::table('righe_scritture')->join('conti_contabili', 'conti_contabili.id', '=', 'righe_scritture.conto_contabile_id')
        ->join('scritture_contabili', 'scritture_contabili.id', '=', 'righe_scritture.scrittura_id')->whereNull('scritture_contabili.deleted_at')
        ->where('scritture_contabili.condominio_id', $s->c->id)->where('conti_contabili.ruolo', $ruolo)
        ->when($voceId !== null, fn ($q) => $q->where('righe_scritture.voce_spesa_id', $voceId))
        ->selectRaw("COALESCE(SUM(CASE WHEN tipo_riga = 'dare' THEN importo ELSE -importo END), 0) as s")->value('s');
    $sc = DB::table('fattura_scrittura')->where('fattura_passiva_id', $nota->id)->value('scrittura_contabile_id');

    expect($f->fresh()->stato_pagamento->value ?? $f->fresh()->stato_pagamento)->toBe('stornata')
        ->and(fn () => DoubleEntryValidator::validateOrFail($sc))->not->toThrow(\Throwable::class)
        // Il costo sulla voce torna a zero, «passate gestioni» torna dove era (solo la parte del saldo d'apertura, andata e tornata), il debito pure.
        ->and($netto('sopravvenienze_passive', $voce->id))->toBe(0)
        ->and($netto('passate_gestioni'))->toBe(0)
        ->and($netto('debiti_fornitori'))->toBe(0)
        // Il giroconto proposto non c'è più (la fattura è stornata), e la nota non ha una copertura dal fondo da proporre.
        ->and(collect($this->actingAs($s->admin)->get(route('admin.gestionale.giroconti.create', [$s->c]))->viewData('page')['props']['coperture_in_attesa'])->all())->toBe([]);
});

it('754 (4) — l\'eliminazione della pregressa coperta dal fondo toglie anche la copertura e la voce a € 0,00, come con la «sopravvenienza»', function () {
    $r = b14pfScenario($this);
    $s = $r['s'];
    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'fondo_riserva', true))->assertSessionHasNoErrors();
    $f = FatturaPassiva::sole();
    $voce = Conto::where('nome', 'Debito 2025 Idraulica')->sole();

    $this->actingAs($s->admin)->delete(route('admin.gestionale.fatture.destroy', [$s->c, $f]))->assertSessionHasNoErrors();

    // La voce l'aveva creata la fattura, a € 0,00 e senza altri costi: se ne va con lei. Prima della correzione restava nel piano dei conti.
    expect(FatturaPassiva::count())->toBe(0)
        ->and(DB::table('fattura_coperture')->count())->toBe(0)
        ->and(Conto::find($voce->id))->toBeNull()
        ->and(Conto::where('nome', 'Integrazioni Straordinarie (Scudo Legale)')->exists())->toBeTrue();
});

it('754 (4), controprova — l\'eliminazione della pregressa con «Attesa conguaglio» toglie la voce come prima', function () {
    $r = b14pfScenario($this);
    $s = $r['s'];
    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'conguaglio_fine_anno'))->assertSessionHasNoErrors();
    $voce = Conto::where('nome', 'Debito 2025 Idraulica')->sole();

    $this->actingAs($s->admin)->delete(route('admin.gestionale.fatture.destroy', [$s->c, FatturaPassiva::sole()]))->assertSessionHasNoErrors();

    expect(Conto::find($voce->id))->toBeNull();
});

it('754 (4) — il periodo della pregressa resta obbligatorio con il fondo, e il rifiuto dice che quella parte la copre il fondo, non un piano rate', function () {
    $r = b14pfScenario($this);

    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'fondo_riserva', true), null, ['competenza_dal' => '', 'competenza_al' => ''])
        ->assertSessionHasErrors(['competenza_dal' => 'Fattura pregressa con una parte non coperta dai saldi iniziali: dichiara il periodo in cui il costo è maturato. '
            .'Quella parte la copre il fondo «Fondo di riserva». Dopo non si potrà più aggiungere: una pregressa si storna, non si modifica.']);
    expect(FatturaPassiva::count())->toBe(0);
});

it('754 (4), controprova — senza il fondo il rifiuto del periodo dice ancora il piano rate', function () {
    $r = b14pfScenario($this);

    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'conguaglio_fine_anno'), null, ['competenza_dal' => '', 'competenza_al' => ''])
        ->assertSessionHasErrors(['competenza_dal']);
    expect(session('errors')->first('competenza_dal'))->toContain('Quella parte finisce in un piano rate');
});

// --- 754 (5): la pregressa «da ratificare» con la scelta di partenza ----------------------------------------------------------------------------------

it('754 (5), G6 con la delibera — la fattura è approvata, e l\'assemblea non riceve la proposta di una ratifica', function () {
    $r = b14pfScenario($this);

    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'conguaglio_fine_anno'))->assertSessionHasNoErrors();

    $f = FatturaPassiva::sole();
    expect($f->stato_approvazione)->toBe('approvata')
        ->and(PropostaDelPunto::perLaScheda($f)['testo'])->not->toContain('Ratifica');
});

it('754 (5), G6 con l\'urgenza — da ratificare con l\'importo vero: lo sforo è € 610,00 anche se la pagina di prima mandava € 0,00', function () {
    $r = b14pfScenario($this);
    $finestra = b14pfFinestra($r, 'gestione_corrente', 'conguaglio_fine_anno');

    // La pagina di prima: la parte comune delle righe «Fuori preventivo», che la pregressa non ha.
    b14pfRegistra($this, $r, $finestra, b14pfSforo($finestra, 0))->assertSessionHasNoErrors();

    $f = FatturaPassiva::sole();
    expect($f->stato_approvazione)->toBe('sforo_motivato')
        ->and((int) $f->dati_extra['override_budget']['importo_sforo'])->toBe(61000)
        ->and(PropostaDelPunto::perLaScheda($f)['testo'])->toContain('Ratifica della spesa oltre il preventivo di € 610,00');
});

it('754 (5), G5 — l\'urgenza e il fondo: una copertura sola dal fondo, di € 610,00 (prima una da € 0,00)', function () {
    $r = b14pfScenario($this);
    $finestra = b14pfFinestra($r, 'gestione_corrente', 'fondo_riserva', true);

    b14pfRegistra($this, $r, $finestra, b14pfSforo($finestra, 0))->assertSessionHasNoErrors();

    $f = FatturaPassiva::sole();
    expect($f->stato_approvazione)->toBe('sforo_motivato')
        ->and(b14pfCoperture($f))->toBe([['fondo_riserva', 61000, 'pianificata', $r['fondo']->conto_contabile_id]])
        ->and($f->coperture()->sole()->nota_amministratore)
            ->toBe('Copertura del debito dell\'esercizio precedente «Debito 2025 Idraulica» (art. 1135 c.c.): Riparazione della colonna rimasta da pagare');
});

it('754 (5), G5 con la pagina nuova — lo sforo con l\'importo vero non fa nascere una seconda copertura dal fondo', function () {
    $r = b14pfScenario($this);
    $finestra = b14pfFinestra($r, 'gestione_corrente', 'fondo_riserva', true);

    b14pfRegistra($this, $r, $finestra, b14pfSforo($finestra, 61000))->assertSessionHasNoErrors();

    expect(b14pfCoperture(FatturaPassiva::sole()))->toBe([['fondo_riserva', 61000, 'pianificata', $r['fondo']->conto_contabile_id]]);
});

it('754 (5) — una pregressa tutta coperta dal saldo d\'apertura non diventa «da ratificare» per uno sforo di € 0,00 rimasto nel modulo', function () {
    $r = b14pfScenario($this);
    $debito = b14pfDebitoDApertura($r, 61000);
    $finestra = b14pfFinestra($r, 'gestione_corrente', 'conguaglio_fine_anno');

    b14pfRegistra($this, $r, $finestra, b14pfSforo($finestra, 0), [
        'saldo_patrimoniale_id' => $debito, 'coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 610.00, 'fonte_id' => $debito]],
    ])->assertSessionHasNoErrors();

    $f = FatturaPassiva::sole();
    expect($f->stato_approvazione)->toBe('approvata')
        ->and($f->dati_extra['override_budget'] ?? null)->toBeNull()
        ->and(b14pfCoperture($f))->toBe([['rata_0', 61000, 'pianificata', null]]);
});

// --- I dati già scritti: il comando in sola lettura ---------------------------------------------------------------------------------------------------

it('il comando in sola lettura elenca le pregresse da ratificare con lo sforo a € 0,00 e le coperture dal fondo da € 0,00, e non tocca niente', function () {
    $r = b14pfScenario($this);
    $s = $r['s'];
    // Due pregresse come le scriveva il codice di prima: lo sforo a € 0,00, e con il fondo anche la copertura da € 0,00.
    b14pfRegistra($this, $r, b14pfFinestra($r, 'gestione_corrente', 'conguaglio_fine_anno'), null, [], 'PREG-A');
    b14pfRegistra($this, $r, b14pfFinestra($r, 'gestione_corrente', 'fondo_riserva', true), null, [], 'PREG-B');
    $a = FatturaPassiva::where('numero_documento', 'PREG-A')->sole();
    $b = FatturaPassiva::where('numero_documento', 'PREG-B')->sole();
    foreach ([$a, $b] as $f) {
        $extra = $f->dati_extra;
        $extra['override_budget'] = ['motivazione' => 'Riparazione della colonna rimasta da pagare', 'importo_sforo' => 0, 'strategia_rientro' => 'conguaglio_fine_anno'];
        DB::table('fatture_passive')->where('id', $f->id)->update(['stato_approvazione' => 'sforo_motivato', 'dati_extra' => json_encode($extra)]);
    }
    DB::table('fattura_coperture')->where('fattura_passiva_id', $b->id)->delete();
    DB::table('fattura_coperture')->insert(['fattura_passiva_id' => $b->id, 'tipo_copertura' => 'fondo_riserva', 'importo' => 0, 'stato' => 'pianificata',
        'fondo_id' => $r['fondo']->conto_contabile_id, 'created_at' => now(), 'updated_at' => now()]);
    // Una pregressa giusta, che non compare.
    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'conguaglio_fine_anno'), null, [], 'PREG-C');

    $prima = [DB::table('fatture_passive')->orderBy('id')->get(['stato_approvazione', 'dati_extra'])->toArray(), DB::table('fattura_coperture')->count(),
        DB::table('righe_scritture')->count()];

    expect(Artisan::call('kondomanager:verifica-pregresse'))->toBe(0);
    $uscita = Artisan::output();
    expect($uscita)->toContain('sola lettura')
        ->and($uscita)->toContain('PREG-A')->and($uscita)->toContain('PREG-B')->and($uscita)->not->toContain('PREG-C')
        ->and($uscita)->toContain('Da ratificare con lo sforo a € 0,00')
        ->and($uscita)->toContain('Copertura dal fondo da € 0,00')
        ->and($uscita)->toContain('€ 610,00')
        // Che cosa fare a mano, e che cosa il comando non può trovare.
        ->and($uscita)->toContain('storna')
        ->and($uscita)->toContain('non si trovano');

    expect([DB::table('fatture_passive')->orderBy('id')->get(['stato_approvazione', 'dati_extra'])->toArray(), DB::table('fattura_coperture')->count(),
        DB::table('righe_scritture')->count()])->toEqual($prima);

    // Con --condominio guarda solo quel condominio.
    $this->artisan('kondomanager:verifica-pregresse', ['--condominio' => $s->c->id + 1000])->expectsOutputToContain('Nessuna pregressa')->assertExitCode(0);
});

// --- Il giro di correzioni dopo lo scettico della tappa 6 ---------------------------------------------------------------------------------------------

it('rilievo 2 — un piano della gestione senza voci (della 1.10), generato e poi ricalcolato dopo la pregressa, non prende la voce tecnica della pregressa', function (string $strategia) {
    $r = b14pfScenario($this);
    $s = $r['s'];
    $s->voce('Ordinaria 2026', 'Pulizie scale', 100000, ['Proprietà' => 100]);
    // Il piano della 1.10, senza la riga delle voci, nato prima della fattura.
    $s->pianoOrdinario('Piano 1.10', 'Ordinaria 2026');
    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', $strategia, $strategia === 'fondo_riserva'))->assertSessionHasNoErrors();
    $voce = Conto::where('nome', 'Debito 2025 Idraulica')->sole();
    expect(DB::table('piano_rate_capitoli')->count())->toBe(0)
        ->and((int) $voce->importo)->toBe($strategia === 'fondo_riserva' ? 0 : 61000);

    // La generazione con la riga vuota: il motore prende le voci radice della gestione. Prima prendeva anche il capitolo tecnico «Integrazioni
    // Straordinarie (Scudo Legale)» con la voce della pregressa: € 1.610,00 invece di € 1.000,00, cioè € 966,00 e € 644,00.
    $s->genera('Piano 1.10');
    expect($s->somme('Piano 1.10'))->toBe(['ugo' => 60000, 'bice' => 40000]);

    // Ciò che la guardia del già versato conta per il piano senza voci (la riga è ancora vuota): le sole voci che il motore gli fa prendere.
    expect(DB::table('piano_rate_capitoli')->count())->toBe(0)
        ->and(app(CalcoloQuoteService::class)->quantoChiedeSullaVoce($s->piano('Piano 1.10'), $voce))->toBe(0);

    // «Ricalcola» dalla rotta: il piano si scrive nella riga le voci che il motore prende (Coda 489), e da lì le impegna. Prima ci scriveva anche il
    // capitolo tecnico, con la voce a € 0,00 coperta dal fondo come con le altre.
    $s->ricalcola('Piano 1.10')->assertSessionHasNoErrors();
    expect(session('message')['type'] ?? null)->toBe('success')
        ->and(b14pfVociDellaRiga($s->piano('Piano 1.10')))->toBe(['Pulizie scale'])
        ->and($s->somme('Piano 1.10'))->toBe(['ugo' => 60000, 'bice' => 40000]);
})->with(['rata_integrativa', 'conguaglio_fine_anno', 'fondo_riserva']);

it('rilievo 5 — la cassa del fondo che copre la pregressa con il giroconto ancora da confermare non si elimina, e la frase dice la fattura, la cifra e la strada', function () {
    $r = b14pfScenario($this);
    $s = $r['s'];
    // L'apertura del fondo a giornale: senza, l'eliminazione si ferma già sul saldo d'apertura non registrato.
    expect(app(RegistraAperturaCassaAction::class)->execute($r['fondo'], '2026-01-01')->value)->toBe('registrata');
    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'fondo_riserva', true))->assertSessionHasNoErrors();

    $this->actingAs($s->admin)->delete(route('admin.gestionale.casse.destroy', [$s->c->id, $r['fondo']->id]))->assertRedirect();
    expect(Cassa::find($r['fondo']->id))->not->toBeNull()
        ->and(session('message')['type'] ?? null)->toBe('error')
        ->and(session('message')['message'] ?? null)
            ->toBe('Questa risorsa copre la fattura n. 12 di Idraulica Srl con € 610,00 ancora da confermare: conferma il giroconto o storna la fattura prima di eliminarla.');

    // L'apertura del fondo resta, e «Giroconti» propone ancora la copertura da un fondo che c'è.
    $inAttesa = collect($this->actingAs($s->admin)->get(route('admin.gestionale.giroconti.create', [$s->c]))->viewData('page')['props']['coperture_in_attesa'])
        ->map(fn ($c) => [$c['fattura_numero'], (int) $c['importo']])->all();
    expect($r['fondo']->fresh()->apertureValide())->toHaveCount(1)
        ->and($inAttesa)->toBe([['12', 61000]]);
});

it('rilievo 5 — lo stesso per la spesa fuori preventivo dell\'anno coperta dal fondo, e con più fatture la frase le nomina tutte', function () {
    $r = b14pfScenario($this);
    $s = $r['s'];
    expect(app(RegistraAperturaCassaAction::class)->execute($r['fondo'], '2026-01-01')->value)->toBe('registrata');
    $finestra = b14pfFinestra($r, 'delibera_assembleare', 'fondo_riserva', true);
    $finestra['nome_voce'] = 'Riparazione colonna';
    // La spesa fuori preventivo dell'anno (Coda 693): una riga «Fuori preventivo» comune da € 1.000,00 + IVA 22 %, con la finestra e il fondo.
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.store', [$s->c]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'FT-ROB',
        'data_documento' => '2026-10-20', 'data_scadenza' => '2026-11-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'approvata',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => $finestra],
        'righe' => [['descrizione' => 'Riparazione colonna', 'importo_imponibile' => 1000.00, 'aliquota_iva' => 22, 'conto_id' => null, 'is_sopravvenienza' => true]],
    ])->assertSessionHasNoErrors();
    expect(b14pfCoperture(FatturaPassiva::where('numero_documento', 'FT-ROB')->sole()))->toBe([['fondo_riserva', 122000, 'pianificata', $r['fondo']->conto_contabile_id]]);

    $this->actingAs($s->admin)->delete(route('admin.gestionale.casse.destroy', [$s->c->id, $r['fondo']->id]))->assertRedirect();
    expect(Cassa::find($r['fondo']->id))->not->toBeNull()
        ->and(session('message')['message'] ?? null)
            ->toBe('Questa risorsa copre la fattura n. FT-ROB di Idraulica Srl con € 1.220,00 ancora da confermare: conferma il giroconto o storna la fattura prima di eliminarla.');

    // Con la pregressa coperta dallo stesso fondo le fatture sono due.
    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'fondo_riserva', true))->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->delete(route('admin.gestionale.casse.destroy', [$s->c->id, $r['fondo']->id]))->assertRedirect();
    expect(Cassa::find($r['fondo']->id))->not->toBeNull()
        ->and(session('message')['message'] ?? null)
            ->toBe('Questa risorsa copre la fattura n. FT-ROB di Idraulica Srl con € 1.220,00 e la fattura n. 12 di Idraulica Srl con € 610,00 ancora da '
                .'confermare: conferma i giroconti o storna le fatture prima di eliminarla.');
});

it('rilievo 5, controprova — stornata la pregressa, la copertura non ferma più: il fondo si elimina e la sua apertura si storna', function () {
    $r = b14pfScenario($this);
    $s = $r['s'];
    expect(app(RegistraAperturaCassaAction::class)->execute($r['fondo'], '2026-01-01')->value)->toBe('registrata');
    b14pfRegistra($this, $r, b14pfFinestra($r, 'delibera_assembleare', 'fondo_riserva', true))->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.storno', [$s->c, FatturaPassiva::sole()]))->assertSessionHasNoErrors();

    $this->actingAs($s->admin)->delete(route('admin.gestionale.casse.destroy', [$s->c->id, $r['fondo']->id]))->assertRedirect();

    expect(Cassa::find($r['fondo']->id))->toBeNull()
        ->and(session('message')['type'] ?? null)->toBe('success')
        ->and(session('message')['message'] ?? '')->toContain('La sua apertura di € 5.000,00 del 01/01/2026 è stornata nel libro giornale');
});

it('rilievo 10 — il compito «Emettere rate» nasce solo con «Rata integrativa»: con «Attesa conguaglio» la spesa aspetta il rendiconto', function (string $origine, string $strategia, bool $compito) {
    $r = b14pfScenario($this);
    $finestra = b14pfFinestra($r, $origine, $strategia);

    b14pfRegistra($this, $r, $finestra, $origine === 'gestione_corrente' ? b14pfSforo($finestra, 61000) : null)->assertSessionHasNoErrors();

    $f = FatturaPassiva::sole();
    $tipi = DB::table('eventi')->where('meta->context->fattura_id', $f->id)->pluck('meta')->map(fn ($m) => json_decode($m, true)['type'] ?? null)->all();
    expect(in_array('emissione_rata_sopravvenienza', $tipi, true))->toBe($compito)
        ->and($tipi)->toContain('pagamento_fornitore')
        // La voce e la copertura «sopravvenienza» restano come prima: il carrello di «Spese fuori preventivo» la offre con tutte e due.
        ->and(b14pfCoperture($f))->toBe([['sopravvenienza', 61000, 'pianificata', null]]);
})->with([
    'delibera e «Attesa conguaglio»' => ['delibera_assembleare', 'conguaglio_fine_anno', false],
    'urgenza e «Attesa conguaglio»' => ['gestione_corrente', 'conguaglio_fine_anno', false],
    'delibera e «Rata integrativa»' => ['delibera_assembleare', 'rata_integrativa', true],
    'urgenza e «Rata integrativa»' => ['gestione_corrente', 'rata_integrativa', true],
]);
