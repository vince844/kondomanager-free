<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 687 (decisione 507): il controllo in sola lettura delle fatture che una modifica, fino alla 1.12.0-beta.11, ha già toccato, e la correzione che
 * l'amministratore comanda (il comando `kondomanager:verifica-gestione-fatture --correggi`, o il pulsante dell'elenco delle fatture). Mai automatica.
 *
 * Lo stato toccato si costruisce scrivendo nel database quello che la modifica di prima lasciava, provato sul codice di allora dalla prova del supporto
 * (B4: la scrittura di competenza passa dalla straordinaria all'ordinaria, e la colonna nuova la segue come la riempie la migrazione; B1: la riga resta
 * sulla voce tecnica con il segno spento). Con il codice di oggi la modifica non lo produce più (`SpesaNonPrevistaARateTest`).
 *
 * Dalla Fase 1-bis della beta.12: le note di credito spostate con la loro fattura (D4, la fattura si corregge con le sue note o non si corregge), la
 * spogliata di un esercizio chiuso (S2) e le prove che uccidono le mutazioni sopravvissute della lente P (M-F8, M-F3, F4, F6).
 *
 * Cosa non copre: la pagina dell'elenco delle fatture montata (il blocco e il pulsante: `FatturaRegisterListToccate.test.ts`); una nota collegata alla
 * fattura dopo la correzione; MySQL (sqlite in memoria).
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\FattureToccateDallaModifica;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/SpesaNonPrevistaScenario.php';

uses(RefreshDatabase::class);

/** La fattura come la lasciava «Modifica» della 1.11.0 sulla gestione sbagliata (B4): la scrittura di competenza, e la colonna come la riempie la migrazione. */
function fttSpostaSullOrdinaria(array $r, FatturaPassiva $f): void
{
    $ordinaria = $r['s']->gestioni['Ordinaria 2026']['g']->id;
    $competenza = DB::table('fattura_scrittura')->where('fattura_passiva_id', $f->id)->where('tipo', 'competenza')->pluck('scrittura_contabile_id');
    DB::table('scritture_contabili')->whereIn('id', $competenza)->update(['gestione_id' => $ordinaria]);
    DB::table('fatture_passive')->where('id', $f->id)->update(['gestione_id' => $ordinaria]);
}

/** La riga come la lasciava «Modifica» della 1.11.0 (B1): sulla voce tecnica, con il segno spento. */
function fttSpoglia(FatturaPassiva $f): void
{
    DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->update(['is_sopravvenienza' => false]);
}

function fttGestioneCompetenza(FatturaPassiva $f): int
{
    return (int) DB::table('fattura_scrittura')->join('scritture_contabili', 'scritture_contabili.id', '=', 'fattura_scrittura.scrittura_contabile_id')
        ->where('fattura_scrittura.fattura_passiva_id', $f->id)->where('fattura_scrittura.tipo', 'competenza')->value('scritture_contabili.gestione_id');
}

it('elenca la fattura spostata sull\'ordinaria e quella spogliata di «Fuori preventivo», con la correzione proposta, e il comando di serie non cambia niente', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    $spostata = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-SPOSTATA');
    $spogliata = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r), false, null, 'FT-SPOGLIATA');
    $sana = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-SANA');
    fttSpostaSullOrdinaria($r, $spostata);
    fttSpoglia($spogliata);

    $elenco = app(FattureToccateDallaModifica::class)->perCondominio((int) $r['s']->c->id);
    expect(array_column($elenco['spostate'], 'id'))->toBe([$spostata->id])
        ->and($elenco['spostate'][0])->toMatchArray(['gestione_attuale' => 'Ordinaria 2026', 'gestione_delle_voci' => 'Spesa fuori bilancio', 'voci' => ['Riparazione colonna'],
            'correggibile' => true, 'motivo' => null, 'totale_testo' => '€ 1.220,00'])
        ->and(array_column($elenco['spogliate'], 'id'))->toBe([$spogliata->id])
        ->and($elenco['spogliate'][0]['correggibile'])->toBeTrue();

    $this->artisan('kondomanager:verifica-gestione-fatture', ['--condominio' => $r['s']->c->id])
        ->expectsOutputToContain('sola lettura, niente viene modificato')
        ->expectsOutputToContain('2 fatture da controllare')
        ->assertSuccessful();

    expect(fttGestioneCompetenza($spostata))->toBe($r['s']->gestioni['Ordinaria 2026']['g']->id)
        ->and((bool) DB::table('righe_fattura')->where('fattura_passiva_id', $spogliata->id)->value('is_sopravvenienza'))->toBeFalse()
        ->and(fttGestioneCompetenza($sana))->toBe($r['g']->id);
});

it('con --correggi la spostata torna sulla gestione delle voci e la spogliata riprende il segno: la fattura torna nel carrello, e la scrittura quadra uguale', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    $spostata = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-SPOSTATA');
    $spogliata = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r), false, null, 'FT-SPOGLIATA');
    fttSpostaSullOrdinaria($r, $spostata);
    fttSpoglia($spogliata);
    expect(snpCarrello($this, $r))->toBe([]);
    $righePrima = DB::table('righe_scritture')->whereIn('scrittura_id', DB::table('fattura_scrittura')->where('fattura_passiva_id', $spostata->id)->pluck('scrittura_contabile_id'))
        ->orderBy('id')->get(['conto_contabile_id', 'tipo_riga', 'importo'])->map(fn ($x) => (array) $x)->all();

    $this->artisan('kondomanager:verifica-gestione-fatture', ['--condominio' => $r['s']->c->id, '--correggi' => true])->assertSuccessful();

    expect(fttGestioneCompetenza($spostata))->toBe($r['g']->id)
        ->and($spostata->fresh()->gestione_id)->toBe($r['g']->id)
        ->and(DB::table('righe_scritture')->whereIn('scrittura_id', DB::table('fattura_scrittura')->where('fattura_passiva_id', $spostata->id)->pluck('scrittura_contabile_id'))
            ->orderBy('id')->get(['conto_contabile_id', 'tipo_riga', 'importo'])->map(fn ($x) => (array) $x)->all())->toBe($righePrima)
        ->and((bool) DB::table('righe_fattura')->where('fattura_passiva_id', $spogliata->id)->value('is_sopravvenienza'))->toBeTrue()
        ->and(collect(snpCarrello($this, $r))->pluck('id')->all())->toBe([$spogliata->id]);

    $this->artisan('kondomanager:verifica-gestione-fatture', ['--condominio' => $r['s']->c->id])->expectsOutputToContain('Nessuna fattura da correggere')->assertSuccessful();
});

it('una spostata già pagata non si corregge da qui: il motivo dice lo storno, e --correggi la lascia com\'è', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-PAGATA');
    fttSpostaSullOrdinaria($r, $f);
    // Pagata dopo la modifica: il pagamento prende la gestione dalla scrittura della fattura, cioè l'ordinaria.
    $this->actingAs($s->admin)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s->c]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'conto_corrente_id' => $s->cassa->conto_contabile_id, 'data_pagamento' => '2026-10-25', 'metodo_pagamento' => 'bonifico',
        'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 0, 'bonifico_parlante' => false, 'allow_overdraft' => true, 'iban_confermato_manualmente' => true,
        'conferma_duplicato_verificato' => true, 'allocazioni' => [['fattura_id' => $f->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => (int) $f->fresh()->netto_a_pagare]],
    ])->assertSessionHasNoErrors();

    $voceElenco = app(FattureToccateDallaModifica::class)->perCondominio((int) $s->c->id)['spostate'][0];
    expect($voceElenco['correggibile'])->toBeFalse()->and($voceElenco['motivo'])->toContain('ha già un pagamento');

    $this->artisan('kondomanager:verifica-gestione-fatture', ['--condominio' => $s->c->id, '--correggi' => true])->assertSuccessful();
    expect(fttGestioneCompetenza($f))->toBe($s->gestioni['Ordinaria 2026']['g']->id);

    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.riporta-come-era', [$s->c, $f]))->assertSessionHas('message');
    expect(session('message')['type'])->toBe('error')->and(fttGestioneCompetenza($f))->toBe($s->gestioni['Ordinaria 2026']['g']->id);
});

it('l\'elenco delle fatture mostra il controllo, e «Riporta com\'era» corregge solo la fattura scelta', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $voce = snpVoce($this, $r, '1.220,00');
    $prima = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-UNO');
    $seconda = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-DUE');
    fttSpostaSullOrdinaria($r, $prima);
    fttSpostaSullOrdinaria($r, $seconda);

    $this->actingAs($s->admin)->get(route('admin.gestionale.fatture.index', [$s->c]))->assertOk()
        ->assertInertia(fn ($p) => $p->has('fattureToccateDallaModifica.spostate', 2)->has('fattureToccateDallaModifica.spogliate', 0));

    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.riporta-come-era', [$s->c, $prima]))->assertSessionHas('message');
    expect(session('message'))->toMatchArray(['type' => 'success', 'message' => 'La fattura n. FT-UNO è riportata sulla gestione «Spesa fuori bilancio».'])
        ->and(fttGestioneCompetenza($prima))->toBe($r['g']->id)
        ->and(fttGestioneCompetenza($seconda))->toBe($s->gestioni['Ordinaria 2026']['g']->id);
});

it('controprova: una fattura sana, e la nota di credito dello storno di una «Fuori preventivo», non sono nell\'elenco', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $voce = snpVoce($this, $r, '1.220,00');
    snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-SANA');
    $fuori = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r), false, null, 'FT-FUORI');
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.storno', [$s->c, $fuori]), ['motivo' => 'Registrata due volte'])->assertSessionHasNoErrors();
    $nota = FatturaPassiva::where('condominio_id', $s->c->id)->where('tipo_documento', 'nota_credito')->sole();
    // La nota dello storno porta la riga sulla voce tecnica con il segno spento: è il caso che il controllo non deve leggere come «spogliata».
    expect(DB::table('righe_fattura')->where('fattura_passiva_id', $nota->id)->whereNotNull('conto_id')->where('is_sopravvenienza', false)->count())->toBe(1)
        ->and($nota->gestione_id)->toBe($r['g']->id);

    expect(app(FattureToccateDallaModifica::class)->perCondominio((int) $s->c->id))->toBe(['spostate' => [], 'spogliate' => []]);
});

// --- Fase 1-bis della beta.12, D4: la fattura si corregge con le sue note di credito, o non si corregge ---------------------------------------------

/** La nota di credito del fornitore, € 200,00 + IVA sulla voce data, collegata alla fattura, registrata dalla rotta vera sulla gestione della fattura. */
function fttNota($t, array $r, FatturaPassiva $f, int $contoId, string $numero = 'NC-1'): FatturaPassiva
{
    $s = $r['s'];
    $t->actingAs($s->admin)->post(route('admin.gestionale.fatture.store', [$s->c]), [
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $s->e->id, 'gestione_id' => $f->fresh()->gestioneDellaFattura(), 'tipo_documento' => 'nota_credito',
        'numero_documento' => $numero, 'fattura_rettificata_id' => $f->id, 'conferma_avviso_nota' => true,
        'data_documento' => '2026-10-22', 'data_scadenza' => '2026-11-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'approvata',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => [['descrizione' => 'Sconto', 'conto_id' => $contoId, 'importo_imponibile' => 200.00, 'aliquota_iva' => 22]],
    ])->assertSessionHasNoErrors();

    return FatturaPassiva::where('condominio_id', $s->c->id)->where('numero_documento', $numero)->sole();
}

/** Il costo di una voce su una gestione, dal giornale (dare − avere sul suo mastro), in centesimi. */
function fttCostoDellaVoce(Conto $voce, int $gestioneId): int
{
    $mastro = (int) DB::table('conti')->where('id', $voce->id)->value('conto_contabile_id');

    return (int) DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')->whereNull('sc.deleted_at')
        ->where('sc.gestione_id', $gestioneId)->where('rs.conto_contabile_id', $mastro)
        ->selectRaw("COALESCE(SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END), 0) as n")->value('n');
}

/*
 * D4 della lente del denaro: fattura e nota spostate tutte e due dalla «Modifica» della 1.11.0. Con il codice di prima il controllo leggeva le sole fatture:
 * `--correggi` spostava la fattura e lasciava la nota sull'ordinaria (− € 244,00 di costo su una voce che lì non c'è), e poi diceva «Nessuna fattura da
 * correggere». Che cosa non copre: le note collegate dopo la correzione (la correzione le trova solo se sono già collegate).
 */
it('D4 — fattura e nota di credito spostate tutte e due: una voce sola, la proposta nomina la nota, e --correggi le riporta insieme; le gestioni quadrano', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-CON-NOTA');
    $nota = fttNota($this, $r, $f, $voce->id);
    fttSpostaSullOrdinaria($r, $f);
    fttSpostaSullOrdinaria($r, $nota);
    $ordinaria = $s->gestioni['Ordinaria 2026']['g']->id;

    $elenco = app(FattureToccateDallaModifica::class)->perCondominio((int) $s->c->id);
    expect(array_column($elenco['spostate'], 'id'))->toBe([$f->id])
        ->and(array_column($elenco['spostate'][0]['note'] ?? [], 'id'))->toBe([$nota->id])
        ->and($elenco['spostate'][0]['correggibile'])->toBeTrue()
        ->and($elenco['spostate'][0]['proposta'])->toContain('insieme alla nota di credito n. NC-1');

    $this->artisan('kondomanager:verifica-gestione-fatture', ['--condominio' => $s->c->id, '--correggi' => true])->assertSuccessful();

    expect(fttGestioneCompetenza($f))->toBe($r['g']->id)
        ->and(fttGestioneCompetenza($nota))->toBe($r['g']->id)
        ->and($nota->fresh()->gestione_id)->toBe($r['g']->id)
        // Il costo della voce, IVA compresa: € 1.220,00 della fattura − € 244,00 della nota, tutto sulla straordinaria; niente sull'ordinaria.
        ->and(fttCostoDellaVoce($voce, $r['g']->id))->toBe(97600)
        ->and(fttCostoDellaVoce($voce, $ordinaria))->toBe(0);
    $this->artisan('kondomanager:verifica-gestione-fatture', ['--condominio' => $s->c->id])->expectsOutputToContain('Nessuna fattura da correggere')->assertSuccessful();
});

it('D4 — «Riporta com\'era» sulla fattura riporta anche la sua nota, e il messaggio lo dice', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-CON-NOTA');
    $nota = fttNota($this, $r, $f, $voce->id);
    fttSpostaSullOrdinaria($r, $f);
    fttSpostaSullOrdinaria($r, $nota);

    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.riporta-come-era', [$s->c, $f]))->assertSessionHas('message');

    expect(session('message'))->toMatchArray(['type' => 'success',
        'message' => 'La fattura n. FT-CON-NOTA è riportata sulla gestione «Spesa fuori bilancio», insieme alla nota di credito n. NC-1.'])
        ->and(fttGestioneCompetenza($f))->toBe($r['g']->id)
        ->and(fttGestioneCompetenza($nota))->toBe($r['g']->id);
});

it('D4 — se la nota non si può spostare (compensata), non si sposta nemmeno la fattura: il motivo nomina la nota, e niente cambia', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-CON-NOTA');
    $nota = fttNota($this, $r, $f, $voce->id);
    fttSpostaSullOrdinaria($r, $f);
    fttSpostaSullOrdinaria($r, $nota);
    // La nota già compensata con un pagamento (lo stato che il pagamento lascia: «pagata» su una nota vuol dire compensata).
    DB::table('fatture_passive')->where('id', $nota->id)->update(['stato_pagamento' => 'pagata']);
    $ordinaria = $s->gestioni['Ordinaria 2026']['g']->id;

    $voceElenco = app(FattureToccateDallaModifica::class)->perCondominio((int) $s->c->id)['spostate'][0];
    expect($voceElenco['id'])->toBe($f->id)
        ->and($voceElenco['correggibile'])->toBeFalse()
        ->and($voceElenco['motivo'])->toContain('nota di credito n. NC-1')->and($voceElenco['motivo'])->toContain('compensata');

    $this->artisan('kondomanager:verifica-gestione-fatture', ['--condominio' => $s->c->id, '--correggi' => true])->assertSuccessful();
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.riporta-come-era', [$s->c, $f]))->assertSessionHas('message');

    expect(session('message')['type'])->toBe('error')
        ->and(fttGestioneCompetenza($f))->toBe($ordinaria)
        ->and(fttGestioneCompetenza($nota))->toBe($ordinaria);
});

it('D4 — una nota registrata sull\'altra gestione con le voci di quella: la fattura spostata non si corregge da sola, il motivo lo dice', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-CON-NOTA');
    $nota = fttNota($this, $r, $f, $voce->id);
    fttSpostaSullOrdinaria($r, $f);
    fttSpostaSullOrdinaria($r, $nota);
    // La nota sull'ordinaria e su una voce dell'ordinaria (registrata lì, con la voce di lì): non è «spostata», ma separarla dalla fattura cambierebbe le
    // due gestioni. Lo stato si scrive nel database: la registrazione vera vuole una voce con il suo mastro, che lo scenario dell'ordinaria non ha.
    $s->voce('Ordinaria 2026', 'Pulizie scale', 10000, ['Proprietà' => 100]);
    DB::table('righe_fattura')->where('fattura_passiva_id', $nota->id)->update(['conto_id' => $s->voci['Pulizie scale']['conto']->id]);
    $ordinaria = $s->gestioni['Ordinaria 2026']['g']->id;
    expect(fttGestioneCompetenza($nota))->toBe($ordinaria);

    $voceElenco = app(FattureToccateDallaModifica::class)->perCondominio((int) $s->c->id)['spostate'][0];
    expect($voceElenco['correggibile'])->toBeFalse()->and($voceElenco['motivo'])->toContain('nota di credito n. NC-1');
    app(FattureToccateDallaModifica::class)->correggi((int) $s->c->id);

    expect(fttGestioneCompetenza($f))->toBe($ordinaria)->and(fttGestioneCompetenza($nota))->toBe($ordinaria);
});

it('D4 — una nota spostata, con la sua fattura già sulla gestione giusta, si elenca da sola e si corregge da sola', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-SANA');
    $nota = fttNota($this, $r, $f, $voce->id);
    fttSpostaSullOrdinaria($r, $nota);

    $elenco = app(FattureToccateDallaModifica::class)->perCondominio((int) $s->c->id);
    expect(array_column($elenco['spostate'], 'id'))->toBe([$nota->id])
        ->and($elenco['spostate'][0])->toMatchArray(['tipo_documento' => 'nota_credito', 'correggibile' => true, 'note' => []])
        ->and($elenco['spostate'][0]['proposta'])->toStartWith('Riportare la nota di credito sulla gestione «Spesa fuori bilancio»');

    app(FattureToccateDallaModifica::class)->correggi((int) $s->c->id);
    expect(fttGestioneCompetenza($nota))->toBe($r['g']->id)->and(fttGestioneCompetenza($f))->toBe($r['g']->id)
        ->and(fttCostoDellaVoce($voce, $s->gestioni['Ordinaria 2026']['g']->id))->toBe(0);
});

it('D4 — controprova: la nota dello storno di una fattura spostata non si elenca da sola (è la fattura annullata, sulla sua gestione)', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-STORNATA');
    fttSpostaSullOrdinaria($r, $f);
    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.storno', [$s->c, $f]), ['motivo' => 'Registrata sulla gestione sbagliata'])->assertSessionHasNoErrors();
    $storno = FatturaPassiva::where('condominio_id', $s->c->id)->where('tipo_documento', 'nota_credito')->sole();
    // La nota dello storno ha le righe sulla voce della straordinaria e la scrittura sull'ordinaria, come la fattura che annulla.
    expect(fttGestioneCompetenza($storno))->toBe($s->gestioni['Ordinaria 2026']['g']->id);

    expect(app(FattureToccateDallaModifica::class)->perCondominio((int) $s->c->id))->toBe(['spostate' => [], 'spogliate' => []]);
});

// --- Fase 1-bis della beta.12, S2: la spogliata pagata resta correggibile; quella di un esercizio chiuso no ----------------------------------------------

it('S2 — una «Fuori preventivo» pagata e spogliata si corregge: torna nel carrello (il fornitore si paga prima, i condòmini rimborsano con il piano)', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $f = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r), true, null, 'FT-PAGATA');
    expect($f->stato_pagamento?->value ?? $f->stato_pagamento)->not->toBe('aperta');
    fttSpoglia($f);
    expect(snpCarrello($this, $r))->toBe([]);

    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.riporta-come-era', [$s->c, $f]))->assertSessionHasNoErrors();

    expect(session('message')['type'])->toBe('success')->and(collect(snpCarrello($this, $r))->pluck('id')->all())->toBe([$f->id]);
});

/*
 * S2 della lente della sicurezza, come ridotto dallo scettico: in un esercizio chiuso il segno cambiava, e il messaggio prometteva il carrello, che è per
 * esercizio e una fattura di un esercizio chiuso non la offre. Con il codice di prima: elencata «correggibile», e il segno tornava dal pulsante e dal comando.
 * Che cosa non copre: un esercizio chiuso e poi riaperto (vale lo stato di oggi dell'esercizio).
 */
it('S2 — una spogliata di un esercizio chiuso si elenca ma non si corregge: il motivo lo dice, e né il pulsante né --correggi cambiano la riga', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $f = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r), true, null, 'FT-CHIUSA');
    fttSpoglia($f);
    DB::table('esercizi')->where('id', $s->e->id)->update(['stato' => 'chiuso']);

    $voceElenco = app(FattureToccateDallaModifica::class)->perCondominio((int) $s->c->id)['spogliate'][0];
    expect($voceElenco['id'])->toBe($f->id)->and($voceElenco['correggibile'])->toBeFalse()->and($voceElenco['motivo'])->toContain('chiuso');

    $this->actingAs($s->admin)->post(route('admin.gestionale.fatture.riporta-come-era', [$s->c, $f]))->assertSessionHas('message');
    expect(session('message')['type'])->toBe('error');
    $this->artisan('kondomanager:verifica-gestione-fatture', ['--condominio' => $s->c->id, '--correggi' => true])->assertSuccessful();

    expect((bool) DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->value('is_sopravvenienza'))->toBeFalse();
});

// --- Fase 1-bis della beta.12, P3: le prove che uccidono le mutazioni sopravvissute del controllo (lente P: M-F8, M-F3; F4 e F6 nuove) -----------------

it('P3 (M-F8) — righe su voci di due gestioni: la fattura non si corregge da sola, e --correggi non svuota la gestione della scrittura', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-DUE');
    fttSpostaSullOrdinaria($r, $f);
    $scrittura = (int) DB::table('fattura_scrittura')->where('fattura_passiva_id', $f->id)->where('tipo', 'competenza')->value('scrittura_contabile_id');
    $r['s']->voce('Ordinaria 2026', 'Pulizie scale', 10000, ['Proprietà' => 100]);
    $riga = (array) DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->first();
    unset($riga['id']);
    DB::table('righe_fattura')->insert(['conto_id' => $r['s']->voci['Pulizie scale']['conto']->id] + $riga);

    $voceElenco = app(FattureToccateDallaModifica::class)->perCondominio((int) $r['s']->c->id)['spostate'][0];
    expect($voceElenco['correggibile'])->toBeFalse()->and($voceElenco['motivo'])->toContain('gestioni diverse');
    app(FattureToccateDallaModifica::class)->correggi((int) $r['s']->c->id);
    // Senza la guardia la fattura risultava correggibile e la correzione scriveva `gestione_id = null` sulla scrittura di competenza e sulla fattura.
    expect((int) DB::table('scritture_contabili')->where('id', $scrittura)->value('gestione_id'))->toBe($r['s']->gestioni['Ordinaria 2026']['g']->id)
        ->and($f->fresh()->gestione_id)->toBe($r['s']->gestioni['Ordinaria 2026']['g']->id);
});

it('P3 (M-F3) — una spostata di un esercizio chiuso non si corregge: la contabilità di un anno chiuso non si riscrive', function () {
    $r = snpScenario($this);
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-CHIUSO');
    fttSpostaSullOrdinaria($r, $f);
    DB::table('esercizi')->where('id', $r['s']->e->id)->update(['stato' => 'chiuso']);

    $voceElenco = app(FattureToccateDallaModifica::class)->perCondominio((int) $r['s']->c->id)['spostate'][0];
    expect($voceElenco['correggibile'])->toBeFalse()->and($voceElenco['motivo'])->toContain('chiuso');
    app(FattureToccateDallaModifica::class)->correggi((int) $r['s']->c->id);
    expect(fttGestioneCompetenza($f))->toBe($r['s']->gestioni['Ordinaria 2026']['g']->id);
});

it('P3 (F4) — una spostata già in un piano rate non si corregge: il motivo nomina il piano, e la scrittura di una fattura a rate non si sposta', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $voce = snpVoce($this, $r, '1.220,00');
    $f = snpFattura($this, $r, ['conto_id' => $voce->id, 'is_sopravvenienza' => false], [], false, null, 'FT-A-RATE');
    fttSpostaSullOrdinaria($r, $f);
    // Un piano che contiene la fattura (il collegamento come lo scrive la creazione del piano straordinario).
    $piano = DB::table('piani_rate')->insertGetId(['condominio_id' => $s->c->id, 'gestione_id' => $r['g']->id, 'nome' => 'Colonna a rate', 'tipo' => 'straordinario',
        'metodo_distribuzione' => 'tutte_rate', 'numero_rate' => 3, 'stato' => 'bozza', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('piano_rate_fatture')->insert(['piano_rate_id' => $piano, 'fattura_passiva_id' => $f->id, 'importo_collegato' => 122000, 'created_at' => now(), 'updated_at' => now()]);

    $voceElenco = app(FattureToccateDallaModifica::class)->perCondominio((int) $s->c->id)['spostate'][0];
    expect($voceElenco['correggibile'])->toBeFalse()->and($voceElenco['motivo'])->toContain('«Colonna a rate»');
    app(FattureToccateDallaModifica::class)->correggi((int) $s->c->id);
    expect(fttGestioneCompetenza($f))->toBe($s->gestioni['Ordinaria 2026']['g']->id);
});

it('P3 (F6) — una riga senza «Fuori preventivo» su una voce tecnica fuori da «Integrazioni Straordinarie (Scudo Legale)» non è una spogliata, e il segno non si accende', function () {
    $r = snpScenario($this);
    $s = $r['s'];
    $f = snpFattura($this, $r, ['conto_id' => null, 'is_sopravvenienza' => true], snpLogLegale($r), false, null, 'FT-ALTRO-CAPITOLO');
    fttSpoglia($f);
    // La voce della riga sotto un altro capitolo tecnico (non quello della registrazione «Fuori preventivo»).
    $voce = (int) DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->value('conto_id');
    DB::table('conti')->where('id', DB::table('conti')->where('id', $voce)->value('parent_id'))->update(['nome' => 'Arrotondamenti di sistema']);

    expect(app(FattureToccateDallaModifica::class)->perCondominio((int) $s->c->id)['spogliate'])->toBe([]);
    app(FattureToccateDallaModifica::class)->correggi((int) $s->c->id);
    expect((bool) DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->value('is_sopravvenienza'))->toBeFalse();
});
