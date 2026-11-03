<?php

/**
 * Coda 313 (1.11.0-beta.59) — le spese ad personam rateizzate in un piano straordinario non si addebitano più due volte su
 * «Crediti verso condomini». Riga della roadmap e decisione 153.1 del registro (`docs/subentro_e_competenza_temporale.md`): la
 * correzione delle emissioni nuove; dei dati già scritti, solo il rilevamento (in `Beta59313AdPersonamRilevamentoTest`).
 *
 * La fattura con una riga su una sola unità scrive DARE «Crediti verso condomini» con l'unità, senza persona (`FatturaPassivaService`,
 * «Anticipazione spesa ad personam (Art. 63)»). Il piano straordinario addebita quella spesa al proprietario dentro la sua quota.
 * Fino alla .58 l'emissione scriveva di nuovo DARE «Crediti verso condomini» per la quota intera e AVERE «Gestione rate» per la
 * spesa intera, con l'ad personam: nessuno chiudeva l'anticipazione, e restavano un credito e un avanzo che nessuno ha prodotto.
 *
 * La forma attesa (progetto della Coda 313, decisione 153.1): per una quota con una parte ad personam `a` e quota pura `Q`,
 * DARE «Crediti» a nome della persona per `Q` (invariato), AVERE «Gestione rate» per `Q − a`, AVERE «Crediti» con l'unità e la rata,
 * senza persona, per `a`. Il criterio: dopo l'incasso pieno «Crediti verso condomini» dell'unità è a zero, «Gestione rate» e il
 * risultato di gestione non hanno l'ad personam, nessuna persona paga una cifra diversa.
 *
 * Le cifre si rifanno dalla regola, a mano, prima di leggere il programma: ogni test lo dice nel commento. Centesimi.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\RataQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59313AdPersonamScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b59apAdmin();
});

test('Coda 313 — la spesa ad personam rateizzata in due rate lascia «Crediti verso condomini» dell\'unità a zero dopo l\'incasso, e «Gestione rate» e il risultato di gestione senza l\'ad personam', function () {
    // L'esempio del rapporto: fattura € 1.220,00 (1.000,00 + IVA 22 %) tutta ad personam su Interno 1 (Mario Rossi, unico
    // proprietario), due rate da € 610,00. A mano, con la regola: dopo la fattura 1101 dell'unità = +122000 (l'anticipazione);
    // ogni emissione scrive DARE Rossi 61000 e AVERE unità 61000 → 1101 resta 122000, «Gestione rate» 0; l'incasso di 122000 →
    // 1101 dell'unità 0. Rossi deve 122000 dopo le due emissioni e 0 dopo l'incasso. Prima della correzione: 244000, 122000 e
    // «Gestione rate» 122000 (le colonne del rapporto della 0.2).
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    expect((int) $fattura->totale_documento)->toBe(122000)
        ->and(b59apCreditiUnita($s))->toBe(122000);

    $piano = b59apPiano($this, $s, $fattura);
    expect(RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->orderBy('id')->pluck('importo')->all())->toBe([61000, 61000]);

    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    expect(b59apCreditiUnita($s))->toBe(122000)
        ->and(b59apGestioneRate($s))->toBe(0)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(122000);

    b59apIncassa($this, $s, $piano, $rossi, [1 => 61000, 2 => 61000]);
    $sp = b59apStatoPatrimoniale($this, $s);
    expect(b59apCreditiUnita($s))->toBe(0)
        ->and(b59apGestioneRate($s))->toBe(0)
        ->and($sp)->toBe(['crediti' => 0, 'quote_emesse' => 0, 'risultato' => 0])
        ->and(b59apEstratto($this, $s, $rossi))->toBe(0)
        ->and((int) RataQuote::whereIn('rata_id', $piano->rate()->pluck('id'))->sum('importo_pagato'))->toBe(122000);
});

test('Coda 313 — con una rata sola l\'emissione chiude l\'anticipazione con l\'unità e la rata, senza persona, e non scrive niente su «Gestione rate»', function () {
    // A mano: Q = a = 122000. Righe: DARE «Crediti» Rossi Interno 1 122000; AVERE «Crediti» Interno 1 senza persona 122000.
    // Nessuna riga su «Gestione rate» (Q − a = 0). Prima: DARE Rossi 122000 e AVERE «Gestione rate» 122000.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s)]), numeroRate: 1);

    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    $u1 = $s['unita']['1']->id;
    $emissione = b59apEmissione($piano, 1);
    expect(b59apRighe($emissione))->toBe([
        ['crediti_condomini', 'avere', 122000, null, $u1],
        ['crediti_condomini', 'dare', 122000, (int) $rossi->id, $u1],
    ]);
    $chiusura = DB::table('righe_scritture')->where('scrittura_id', $emissione)->whereNull('anagrafica_id')->first();
    expect((int) $chiusura->rata_id)->toBe((int) b59apRata($piano, 1)->id)
        ->and($chiusura->note)->toContain('Chiusura anticipazione spesa ad personam');

    b59apIncassa($this, $s, $piano, $rossi, [1 => 122000]);
    expect(b59apCreditiUnita($s))->toBe(0)->and(b59apGestioneRate($s))->toBe(0);
});

test('Coda 313 — fattura mista con il resto dei centesimi su tre rate: «Gestione rate» riceve la sola spesa comune, le chiusure sommano l\'ad personam al centesimo', function () {
    // Fattura: € 100,01 ad personam su Interno 1 e € 100,00 di spesa comune sulla tabella «Proprietà» (500/500), senza IVA; tre rate.
    // A mano: Rossi 10001 + 5000 = 15001, Bice 5000. La fettina della quota (GenerateRateQuotesAction, il resto sulle prime
    // rate): Rossi 5001, 5000, 5000; Bice 1667, 1667, 1666; la parte ad personam di Rossi con la stessa fettina: 3334, 3334, 3333.
    // «Gestione rate» per rata = (Q Rossi − a) + Q Bice = 3334, 3333, 3333 → 10000, la spesa comune. Chiusure 3334 + 3334 + 3333 = 10001.
    // Dopo l'incasso pieno: «Crediti» di Interno 1 = 10001 + 15001 − 10001 − 15001 = 0, di Interno 2 = 0. Prima: Interno 1 a 10001
    // e «Gestione rate» a 20001.
    $s = b59apBase();
    [$rossi, $bice] = [$s['persone']['mario'], $s['persone']['bice']];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.01, 0), b59apRigaComune(100.00)]);
    $piano = b59apPiano($this, $s, $fattura, numeroRate: 3);
    expect(array_map(fn ($n) => (int) b59apQuota($piano, $n, $rossi)->importo, [1, 2, 3]))->toBe([5001, 5000, 5000])
        ->and(array_map(fn ($n) => (int) b59apQuota($piano, $n, $bice)->importo, [1, 2, 3]))->toBe([1667, 1667, 1666]);

    b59apEmetti($this, $s, $piano, [1, 2, 3])->assertSessionHas('message.type', 'success');
    $perRata = fn (string $ruolo, ?bool $senzaPersona = null) => array_map(fn ($n) => (int) DB::table('righe_scritture')->where('scrittura_id', b59apEmissione($piano, $n))
        ->where('conto_contabile_id', b59apConto($s, $ruolo))->where('tipo_riga', 'avere')
        ->when($senzaPersona, fn ($q) => $q->whereNull('anagrafica_id'))->sum('importo'), [1, 2, 3]);
    expect($perRata('gestione_rate'))->toBe([3334, 3333, 3333])
        ->and($perRata('crediti_condomini', true))->toBe([3334, 3334, 3333]);

    b59apIncassa($this, $s, $piano, $rossi, [1 => 5001, 2 => 5000, 3 => 5000]);
    b59apIncassa($this, $s, $piano, $bice, [1 => 1667, 2 => 1667, 3 => 1666]);
    expect(b59apCreditiUnita($s, '1'))->toBe(0)
        ->and(b59apCreditiUnita($s, '2'))->toBe(0)
        ->and(b59apGestioneRate($s))->toBe(10000);
});

test('Coda 313 — un piano che finanzia una parte della riga (importo del carrello) chiude solo quella parte: il resto dell\'anticipazione resta aperto sull\'unità', function () {
    // Il carrello finanzia € 610,00 della fattura da € 1.220,00: due quote da 30500. A mano: dopo le emissioni «Crediti» di
    // Interno 1 = 122000 + 61000 − 61000 = 122000; dopo l'incasso di 61000 = 61000, la parte che nessun piano ha ancora chiesto.
    // «Gestione rate» 0. Prima: 183000, poi 122000, e «Gestione rate» 61000.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s)]), importo: '610,00');
    expect(array_map(fn ($n) => (int) b59apQuota($piano, $n, $rossi)->importo, [1, 2]))->toBe([30500, 30500]);

    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    expect(b59apCreditiUnita($s))->toBe(122000)->and(b59apGestioneRate($s))->toBe(0);

    b59apIncassa($this, $s, $piano, $rossi, [1 => 30500, 2 => 30500]);
    expect(b59apCreditiUnita($s))->toBe(61000)->and(b59apGestioneRate($s))->toBe(0);
});

test('Coda 313 — due comproprietari dell\'unità: ognuno ha la sua metà dell\'ad personam, e una riga di chiusura per unità per rata chiude le due metà', function () {
    // Interno 1 di Mario Rossi e Luca Verdi al 50 %: l'ad personam di 122000 va 61000 a testa (`addebitaDiretto`, resti maggiori);
    // due rate da 30500 a testa. A mano: per rata una riga di chiusura su Interno 1 da 61000 (30500 + 30500). Dopo le emissioni
    // «Crediti» di Interno 1 = 122000; dopo che pagano tutti e due = 0. Prima: 244000 e 122000.
    $s = b59apBase(['Mario Rossi' => 50, 'Luca Verdi' => 50]);
    [$rossi, $verdi] = [$s['persone']['mario'], $s['persone']['luca']];
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s)]));
    expect((int) b59apQuota($piano, 1, $rossi)->importo)->toBe(30500)->and((int) b59apQuota($piano, 1, $verdi)->importo)->toBe(30500);

    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    foreach ([1, 2] as $n) {
        expect(DB::table('righe_scritture')->where('scrittura_id', b59apEmissione($piano, $n))->whereNull('anagrafica_id')
            ->where('conto_contabile_id', b59apConto($s, 'crediti_condomini'))->get(['tipo_riga', 'importo', 'immobile_id'])->map(fn ($r) => (array) $r)->all())
            ->toBe([['tipo_riga' => 'avere', 'importo' => 61000, 'immobile_id' => $s['unita']['1']->id]]);
    }
    expect(b59apCreditiUnita($s))->toBe(122000);

    b59apIncassa($this, $s, $piano, $rossi, [1 => 30500, 2 => 30500]);
    b59apIncassa($this, $s, $piano, $verdi, [1 => 30500, 2 => 30500]);
    expect(b59apCreditiUnita($s))->toBe(0)->and(b59apGestioneRate($s))->toBe(0);
});

test('Coda 313 — controllo: un piano straordinario senza righe ad personam e un piano ordinario non scrivono righe di chiusura, e «Gestione rate» riceve tutta la quota', function () {
    // Controllo della guardia (verde anche prima): la chiusura c'è solo per la parte ad personam. Fattura comune € 1.000,00 sulla
    // tabella 500/500, due rate: per rata «Gestione rate» 50000 (25000 + 25000), nessuna riga su «Crediti» senza persona.
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaComune(1000.00)]));
    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    expect(b59apGestioneRate($s))->toBe(100000)
        ->and(DB::table('righe_scritture')->whereIn('scrittura_id', [b59apEmissione($piano, 1), b59apEmissione($piano, 2)])->whereNull('anagrafica_id')
            ->where('conto_contabile_id', b59apConto($s, 'crediti_condomini'))->count())->toBe(0);

    // Il piano ordinario: preventivo € 1.200,00 su una voce della tabella, due rate da 30000 a testa → «Gestione rate» 60000 per rata.
    $o = b59apBase([], 'ordinaria');
    $voce = \App\Models\Gestionale\Conto::create(['piano_conto_id' => $o['pc']->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voce->id, 'tabella_id' => $o['tabella']->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('quote_tabella')->where('tabella_id', $o['tabella']->id)->where('immobile_id', $o['unita']['1']->id)->update(['valore' => 500.0]);
    $mario = \App\Models\Anagrafica::forceCreate(['nome' => 'Mario Rossi', 'email' => 'b59ap-ord@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'B59APORDXXXXXXXX']);
    $mario->condomini()->syncWithoutDetaching([$o['c']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $mario->id, 'immobile_id' => $o['unita']['1']->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2019-01-01', 'created_at' => now(), 'updated_at' => now()]);
    $ordinario = \App\Models\Gestionale\PianoRate::create(['gestione_id' => $o['g']->id, 'condominio_id' => $o['c']->id, 'esercizio_id' => $o['e']->id, 'nome' => 'Preventivo 2026',
        'stato' => 'approvato', 'tipo' => 'ordinario', 'numero_rate' => 2, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => false]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($ordinario, esercizio: $o['e']);
    b59apEmetti($this, $o, $ordinario, [1, 2])->assertSessionHas('message.type', 'success');
    expect(b59apGestioneRate($o))->toBe(120000)
        ->and(DB::table('righe_scritture')->whereIn('scrittura_id', [b59apEmissione($ordinario, 1), b59apEmissione($ordinario, 2)])->whereNull('anagrafica_id')
            ->where('conto_contabile_id', b59apConto($o, 'crediti_condomini'))->count())->toBe(0);
});

test('Coda 313 — incasso parziale: «Crediti verso condomini» dell\'unità scende di quanto è incassato, e l\'estratto conto di Rossi dice il resto della rata', function () {
    // Emessa la sola rata 1 (61000), Rossi paga € 300,00. A mano: «Crediti» di Interno 1 = 122000 + 61000 − 61000 − 30000 = 92000;
    // Rossi deve 61000 − 30000 = 31000. Prima: 153000.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s)]));
    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $piano, $rossi, [1 => 30000]);

    expect(b59apCreditiUnita($s))->toBe(92000)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(31000)
        ->and(b59apGestioneRate($s))->toBe(0);
});

test('Coda 313 — lo storno dell\'incasso rimette il debito di Rossi e lascia la chiusura dell\'anticipazione: «Crediti» dell\'unità torna a € 1.220,00, non a € 2.440,00', function () {
    // A mano: dopo le emissioni 122000; dopo l'incasso 0; lo storno è lo specchio dell'incasso (AVERE Rossi 122000 → DARE): 122000.
    // Prima: 244000, 122000, 244000.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s)]));
    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    $incasso = b59apIncassa($this, $s, $piano, $rossi, [1 => 61000, 2 => 61000]);

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();
    expect(b59apCreditiUnita($s))->toBe(122000)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(122000)
        ->and(b59apGestioneRate($s))->toBe(0);
});

test('Coda 313 — l\'annullamento dell\'emissione porta via la riga di chiusura con la scrittura, e la nuova emissione la scrive una volta sola', function () {
    // A mano: annullata l'emissione della rata 1 resta la sola fattura (122000) e nessuna riga con la rata 1; emessa di nuovo,
    // 122000 + 61000 − 61000 = 122000, con una sola riga di chiusura da 61000. Prima, dopo la nuova emissione: 183000.
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s)]));
    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    $rata = b59apRata($piano, 1);

    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $piano->id, 'rata' => $rata->id]))
        ->assertSessionHas('message.type', 'success');
    expect(DB::table('righe_scritture')->where('rata_id', $rata->id)->count())->toBe(0)
        ->and(b59apCreditiUnita($s))->toBe(122000);

    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    expect(b59apCreditiUnita($s))->toBe(122000)
        ->and(DB::table('righe_scritture')->where('rata_id', $rata->id)->whereNull('anagrafica_id')->pluck('importo')->map(fn ($i) => (int) $i)->all())->toBe([61000]);
});

// ─── Gli altri due scrittori dell'emissione: il riallineamento dei crediti e lo storno con la quota rimasta fuori ───────────────

/**
 * La quota a credito con la spesa ad personam: Rossi ha € 610,00 ad personam su Interno 1 in due rate (30500 a rata) e un saldo
 * riportato a credito di € 1.000,00 sulla prima rata → rata 1 = 30500 − 100000 = −69500 (spesa 30500, riporto −100000), rata 2 =
 * 30500. Bice ha € 300,00 ad personam su Interno 2 (15000 a rata): la rata 1 va a giornale per lei. La rata 1 è emessa come la 1.10,
 * che saltava le quote ≤ 0: la quota di Rossi resta fuori (DC2-b).
 */
function b59apQuotaACreditoFuori($test): array
{
    $s = b59apBase(saldi: ['mario' => -100000]);
    $fattura = b59apFattura($test, $s, [b59apRigaAdPersonam($s, 610.00, 0), b59apRigaAdPersonam($s, 300.00, 0, '2', 'Riparazione persiana')]);
    $piano = b59apPianoConSaldi($s, $fattura);
    $q1 = b59apQuota($piano, 1, $s['persone']['mario']);
    expect((int) $q1->importo)->toBe(-69500)->and($q1->componenti())->toBe(['spesa' => 30500, 'riporto' => -100000, 'dettaglio' => true]);
    b59apEmettiComeLa110($s, $piano, 1);
    expect($q1->fresh()->scrittura_contabile_id)->toBeNull();

    return [$s, $piano, $q1];
}

test('Coda 313 — il riallineamento dei crediti scrive la quota a credito rimasta fuori chiudendo l\'anticipazione: niente ad personam su «Gestione rate»', function () {
    // Lo scrittore di `RiallineaCreditiService::esegui` (DC2-b), dalla conferma della card. A mano, con la regola dell'emissione
    // (decisione 71 e 153.1): DARE Rossi 30500 (spesa), AVERE Rossi 100000 (riporto); AVERE Interno 1 senza persona 30500 (la parte ad
    // personam, Q − a = 0 su «Gestione rate»); DARE «Passate gestioni» 100000 (netto − spesa = −69500 − 30500). Prima: AVERE «Gestione
    // rate» 30500 al posto della chiusura. «Crediti» di Interno 1 dopo: 61000 (la fattura) + 30500 − 100000 − 30500 = −39000: il credito
    // che resta a Rossi sulla rata 1 (−69500) più la parte ad personam della rata 2, non ancora emessa (30500).
    [$s, $piano, $q1] = b59apQuotaACreditoFuori($this);

    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertSessionHas('message.type', 'success');
    $scrittura = (int) $q1->fresh()->scrittura_contabile_id;
    $u1 = $s['unita']['1']->id;
    $rossi = (int) $s['persone']['mario']->id;
    expect($scrittura)->toBeGreaterThan(0)
        ->and(b59apRighe($scrittura))->toBe([
            ['crediti_condomini', 'avere', 30500, null, $u1],
            ['crediti_condomini', 'avere', 100000, $rossi, $u1],
            ['crediti_condomini', 'dare', 30500, $rossi, $u1],
            ['passate_gestioni', 'dare', 100000, null, null],
        ])
        ->and(b59apCreditiUnita($s))->toBe(-39000);
});

test('Coda 313 — lo storno di una compensazione scrive la quota a credito rimasta fuori chiudendo l\'anticipazione: niente ad personam su «Gestione rate»', function () {
    // Lo scrittore di `StornoIncassoRateAction::scriviLaQuotaRimastaFuori`. La rata 2 si emette dalla pagina; Rossi usa € 305,00 del
    // credito della rata 1 (ancora senza scrittura: esce da «Passate gestioni») per pagarla; lo storno della compensazione rimette il
    // credito fra le «Passate gestioni» e, con la rata 1 a giornale, la figlia dell'emissione scrive la quota con le regole
    // dell'emissione. A mano: le stesse righe del riallineamento (DARE Rossi 30500, AVERE Rossi 100000, AVERE Interno 1 senza persona
    // 30500, DARE «Passate gestioni» 100000). Prima: AVERE «Gestione rate» 30500 al posto della chiusura.
    [$s, $piano, $q1] = b59apQuotaACreditoFuori($this);
    $rossi = $s['persone']['mario'];
    b59apEmetti($this, $s, $piano, [2])->assertSessionHas('message.type', 'success');
    $q2 = b59apQuota($piano, 2, $rossi);
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $rossi->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-05-02', 'importo_totale' => '0.00', 'descrizione' => 'Compensazione', 'eccedenza' => '0.00',
        'dettaglio_pagamenti' => [['rata_id' => $q2->id, 'importo' => '305.00'], ['rata_id' => $q1->id, 'importo' => '-305.00']],
    ])->assertSessionHasNoErrors();
    $incasso = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();
    $figlia = (int) $q1->fresh()->scrittura_contabile_id;
    $u1 = $s['unita']['1']->id;
    expect($figlia)->toBeGreaterThan(0)
        ->and(b59apRighe($figlia))->toBe([
            ['crediti_condomini', 'avere', 30500, null, $u1],
            ['crediti_condomini', 'avere', 100000, (int) $rossi->id, $u1],
            ['crediti_condomini', 'dare', 30500, (int) $rossi->id, $u1],
            ['passate_gestioni', 'dare', 100000, null, null],
        ]);
});

// ─── I passaggi: la chiave segue la quota ─────────────────────────────────────────────────────────────────────────────────────

test('Coda 313 — un passaggio prima dell\'emissione: la parte ad personam passa con le bozze a chi entra, la quota del pregresso che resta a chi esce ha zero, e l\'emissione chiude l\'anticipazione', function () {
    // Piano da fatture su una gestione ordinaria (come in `RiassegnazioneBozzeTest`): € 1.200,00 ad personam su Interno 1 in 12 rate
    // (10000 a rata), con il saldo riportato di Rossi a debito di € 180,00 spalmato (1500 a rata). Emesse le rate 1–4, Rossi vende ad
    // Anna Neri dal 1° maggio: le bozze 5–12 passano ad Anna per la spesa dell'anno, il pregresso resta a Rossi in una quota sua
    // (`RegistraSubentroAction::riassegnaBozze`, che riscrive `importi` per intero). A mano, con la regola (progetto, «i passaggi tengono
    // la chiave per l'entrante e danno zero alla gemella del pregresso»): ogni bozza di Anna ha `addebito_diretto` 10000, ogni quota del
    // pregresso di Rossi 0. Emesse le rate 5–12: chiusure 8 × 10000; «Crediti» di Interno 1 = 120000 (fattura) + 4 × 11500 + 8 × 10000 +
    // 8 × 1500 − 12 × 10000 = 138000, quanto le quote chiedono alle persone: Rossi 46000 + 12000 = 58000, Anna 80000. «Gestione rate» 0.
    // Gli estratti conto contano anche la coppia del conguaglio (decisione 25), che non è nel giornale: Rossi ha avuto 4 × 10000
    // per i suoi 120 giorni, 120000 × 120/365 = 39452 → € 5,48 a credito a Rossi e a debito ad Anna: 58000 − 548 = 57452, 80000 + 548 = 80548.
    $s = b59apBase(tipoGestione: 'ordinaria', saldi: ['mario' => 18000]);
    $rossi = $s['persone']['mario'];
    $anna = Anagrafica::forceCreate(['nome' => 'Anna Neri', 'email' => 'b59ap-anna@test.it', 'indirizzo' => 'Via Po 2', 'codice_fiscale' => 'B59APANNAXXXXXXX']);
    $anna->condomini()->syncWithoutDetaching([$s['c']->id]);
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 1200.00, 0)]);
    $piano = b59apPianoConSaldi($s, $fattura, 12, 'tutte_rate');
    expect((int) b59apQuota($piano, 5, $rossi)->importo)->toBe(11500);
    b59apEmetti($this, $s, $piano, [1, 2, 3, 4], '2026-01-05')->assertSessionHas('message.type', 'success');

    $vendita = ['tipo' => 'vendita', 'riga_uscente_id' => $s['righeTitolarita']['mario'], 'anagrafica_entrante_id' => $anna->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra'];
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['1']]), $vendita)->assertOk()->json();
    expect($anteprima['rate']['conguaglio']['bozze_riassegnate'])->toHaveCount(8);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['1']]), $vendita)->assertSessionHasNoErrors();

    foreach (range(5, 12) as $n) {
        $diAnna = b59apQuota($piano, $n, $anna);
        $delPregresso = b59apQuota($piano, $n, $rossi);
        expect((int) $diAnna->importo)->toBe(10000)
            ->and($diAnna->regole_calcolo['importi']['addebito_diretto'] ?? null)->toBe(10000)
            ->and((int) $delPregresso->importo)->toBe(1500)
            ->and(array_key_exists('addebito_diretto', $delPregresso->regole_calcolo['importi'] ?? []))->toBeTrue()
            ->and($delPregresso->regole_calcolo['importi']['addebito_diretto'])->toBe(0);
    }

    b59apEmetti($this, $s, $piano, range(5, 12), '2026-05-05')->assertSessionHas('message.type', 'success');
    expect(b59apCreditiUnita($s))->toBe(138000)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(57452)
        ->and(b59apEstratto($this, $s, $anna))->toBe(80548)
        ->and(b59apGestioneRate($s))->toBe(0);
});

// ─── Le quote generate prima dell'aggiornamento ───────────────────────────────────────────────────────────────────────────────

test('Coda 313 — quote generate prima dell\'aggiornamento e non ancora emesse: la parte ad personam si ricava dal registro del riparto, e l\'emissione chiude l\'anticipazione', function () {
    // Le quote senza la chiave nello snapshot (generate dalla 1.11.0-beta.29 alla .58: il registro `righe_riparto` c'è). A mano: la
    // riga `ad_personam` di Rossi su Interno 1 vale 122000; con la fettina della quota (rata 1 e 2 di 2) 61000 a rata, come la
    // chiave. Dopo le emissioni «Crediti» di Interno 1 = 122000, dopo l'incasso 0; «Gestione rate» 0. Prima: 244000 e 122000.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s)]));
    b59apSenzaChiave($piano);
    expect(DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->where('tipo', 'ad_personam')->sum('importo'))->toEqual(122000)
        ->and(array_key_exists('addebito_diretto', b59apQuota($piano, 1, $rossi)->regole_calcolo['importi']))->toBeFalse();

    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    expect(b59apCreditiUnita($s))->toBe(122000)->and(b59apGestioneRate($s))->toBe(0);
    b59apIncassa($this, $s, $piano, $rossi, [1 => 61000, 2 => 61000]);
    expect(b59apCreditiUnita($s))->toBe(0);
});

test('Coda 313 — quote generate prima dell\'aggiornamento e passate a chi entra: il registro si legge di chi aveva il riparto (`righe_di`), e l\'emissione chiude l\'anticipazione', function () {
    // Lo scenario del passaggio, con le quote senza la chiave: `riassegnaBozze` riscrive `importi` (c'è il pregresso) e non ha una chiave
    // da portare. La parte ad personam della bozza di Anna si legge dalla riga del registro di Rossi (`riassegnazione.righe_di`): 120000
    // in 12 rate, 10000. A mano, come sopra: dopo le emissioni 5–12 «Crediti» di Interno 1 = 138000 e «Gestione rate» 0.
    $s = b59apBase(tipoGestione: 'ordinaria', saldi: ['mario' => 18000]);
    $rossi = $s['persone']['mario'];
    $anna = Anagrafica::forceCreate(['nome' => 'Anna Neri', 'email' => 'b59ap-anna2@test.it', 'indirizzo' => 'Via Po 2', 'codice_fiscale' => 'B59APANNA2XXXXXX']);
    $anna->condomini()->syncWithoutDetaching([$s['c']->id]);
    $piano = b59apPianoConSaldi($s, b59apFattura($this, $s, [b59apRigaAdPersonam($s, 1200.00, 0)]), 12, 'tutte_rate');
    b59apSenzaChiave($piano);
    b59apEmetti($this, $s, $piano, [1, 2, 3, 4], '2026-01-05')->assertSessionHas('message.type', 'success');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['1']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['righeTitolarita']['mario'], 'anagrafica_entrante_id' => $anna->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ])->assertSessionHasNoErrors();
    $diAnna = b59apQuota($piano, 5, $anna);
    expect((int) $diAnna->importo)->toBe(10000)
        ->and(array_key_exists('addebito_diretto', $diAnna->regole_calcolo['importi']))->toBeFalse()
        ->and((int) $diAnna->regole_calcolo['riassegnazione']['righe_di'])->toBe((int) $rossi->id);

    b59apEmetti($this, $s, $piano, range(5, 12), '2026-05-05')->assertSessionHas('message.type', 'success');
    expect(b59apCreditiUnita($s))->toBe(138000)->and(b59apGestioneRate($s))->toBe(0);
});

// ─── Il segno della parte ad personam ─────────────────────────────────────────────────────────────────────────────────────────

test('Coda 313 — una quota con la spesa comune negativa chiude lo stesso tutta la parte ad personam: «Gestione rate» porta la sola rettifica comune', function () {
    // Fattura: € 100,00 ad personam su Interno 1 e una riga comune negativa di − € 20,00 (una rettifica) sulla tabella 500/500; una
    // rata. A mano: Rossi 10000 − 1000 = 9000 (Q), con a = 10000 > Q; Bice − 1000. Regola del progetto: AVERE «Gestione rate» per Q − a
    // = − 1000 (cioè DARE 1000) per Rossi, la chiusura per a = 10000; Bice: AVERE Bice 1000, DARE «Gestione rate» 1000. «Gestione rate» =
    // − 2000, la rettifica comune. Dopo che Rossi paga 9000: «Crediti» di Interno 1 = 10000 + 9000 − 10000 − 9000 = 0. Con la chiusura
    // tenuta sotto la quota pura (9000) l'unità restava a 1000 e «Gestione rate» a − 1000.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.00, 0), b59apRigaComune(-20.00, 0, 'Rettifica ponteggio')]), numeroRate: 1);
    expect((int) b59apQuota($piano, 1, $rossi)->importo)->toBe(9000)
        ->and((int) b59apQuota($piano, 1, $s['persone']['bice'])->importo)->toBe(-1000);

    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    expect(DB::table('righe_scritture')->where('scrittura_id', b59apEmissione($piano, 1))->whereNull('anagrafica_id')->where('conto_contabile_id', b59apConto($s, 'crediti_condomini'))->sum('importo'))->toEqual(10000)
        ->and(b59apGestioneRate($s))->toBe(-2000);

    b59apIncassa($this, $s, $piano, $rossi, [1 => 9000]);
    expect(b59apCreditiUnita($s))->toBe(0);
});

test('Coda 313 — limite dichiarato: una parte ad personam negativa resta com\'è, senza riga di chiusura', function () {
    // Fattura: − € 50,00 ad personam su Interno 1 (una riga in diminuzione: la fattura la scrive in AVERE sull'unità) e € 200,00 comuni
    // sulla tabella 500/500; una rata. A mano: Rossi − 5000 + 10000 = 5000, parte ad personam − 5000. Il progetto («una parte ad personam
    // negativa resta com'è: un limite scritto»): nessuna chiusura, «Gestione rate» riceve la quota intera di tutti e due, 5000 + 10000 =
    // 15000, come prima.
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s, -50.00, 0, '1', 'Rimborso danno'), b59apRigaComune(200.00)]), numeroRate: 1);
    expect((int) b59apQuota($piano, 1, $s['persone']['mario'])->importo)->toBe(5000);

    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    expect(DB::table('righe_scritture')->where('scrittura_id', b59apEmissione($piano, 1))->whereNull('anagrafica_id')->where('conto_contabile_id', b59apConto($s, 'crediti_condomini'))->count())->toBe(0)
        ->and(b59apGestioneRate($s))->toBe(15000);
});

test('Coda 313 — l\'annullamento del passaggio riporta a chi era uscito le quote con la loro parte ad personam, e l\'emissione la chiude', function () {
    // Variante del passaggio: annullato dalla sua rotta prima di emettere le rate 5–12, le bozze tornano a Rossi com'erano
    // (`AnnullaPassaggioAction` rimette `regole_calcolo` di prima), con `addebito_diretto` 10000 e il pregresso dentro (11500), e la
    // quota del pregresso se ne va. A mano: emessa la rata 5, chiusura 10000 su Interno 1; «Crediti» di Interno 1 = 120000 + 5 × 11500 −
    // 5 × 10000 = 127500, quanto Rossi deve per le cinque rate emesse.
    $s = b59apBase(tipoGestione: 'ordinaria', saldi: ['mario' => 18000]);
    $rossi = $s['persone']['mario'];
    $anna = Anagrafica::forceCreate(['nome' => 'Anna Neri', 'email' => 'b59ap-anna3@test.it', 'indirizzo' => 'Via Po 2', 'codice_fiscale' => 'B59APANNA3XXXXXX']);
    $anna->condomini()->syncWithoutDetaching([$s['c']->id]);
    $piano = b59apPianoConSaldi($s, b59apFattura($this, $s, [b59apRigaAdPersonam($s, 1200.00, 0)]), 12, 'tutte_rate');
    b59apEmetti($this, $s, $piano, [1, 2, 3, 4], '2026-01-05')->assertSessionHas('message.type', 'success');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['1']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['righeTitolarita']['mario'], 'anagrafica_entrante_id' => $anna->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ])->assertSessionHasNoErrors();
    $subentro = \App\Models\Gestionale\Subentro::latest('id')->firstOrFail();

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['1'], $subentro]), ['nota_annullamento' => 'Rogito registrato due volte'])
        ->assertRedirect();
    $diRossi = RataQuote::where('rata_id', b59apRata($piano, 5)->id)->get();
    expect($diRossi->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all())->toBe([(int) $rossi->id])
        ->and((int) $diRossi->first()->importo)->toBe(11500)
        ->and($diRossi->first()->regole_calcolo['importi']['addebito_diretto'])->toBe(10000);

    b59apEmetti($this, $s, $piano, [5], '2026-05-05')->assertSessionHas('message.type', 'success');
    expect(b59apCreditiUnita($s))->toBe(127500)->and(b59apGestioneRate($s))->toBe(0);
});

test('Coda 313 (Coda 426, limite dichiarato) — una quota della rata a zero esatto non scrive niente, la rata va a giornale per gli altri e la sua parte ad personam resta aperta', function () {
    // Fattura: € 100,00 ad personam su Interno 1 (Rossi), − € 200,00 comuni sulla tabella 500/500, € 300,00 ad personam su Interno 2 (Bice);
    // una rata. Rossi: 10000 − 10000 = 0, la quota senza righe (`RataQuote::scritturaDiEmissione` le dà `anticipazione` 0); Bice: 30000 −
    // 10000 = 20000, DARE Bice 20000 / AVERE Interno 2 30000 / DARE «Gestione rate» 10000. Interno 1 resta a 10000 (la fattura) e «Gestione
    // rate» a − 10000 invece di − 20000: è il limite della decisione 157 (Coda 426, alla 1.12), e il rilevamento non lo elenca.
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.00, 0), b59apRigaComune(-200.00, 0, 'Rettifica'), b59apRigaAdPersonam($s, 300.00, 0, '2', 'Persiana')]), numeroRate: 1);
    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');

    expect(b59apRighe(b59apEmissione($piano, 1)))->toBe([
        ['crediti_condomini', 'avere', 30000, null, (int) $s['unita']['2']->id],
        ['crediti_condomini', 'dare', 20000, (int) $s['persone']['bice']->id, (int) $s['unita']['2']->id],
        ['gestione_rate', 'dare', 10000, null, null],
    ])
        ->and(b59apQuota($piano, 1, $s['persone']['mario'])->scrittura_contabile_id)->toBeNull()
        ->and(b59apCreditiUnita($s, '1'))->toBe(10000)
        ->and(b59apCreditiUnita($s, '2'))->toBe(20000)
        ->and(b59apGestioneRate($s))->toBe(-10000)
        ->and($this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['riallineamento_crediti'])->toBeNull();
});

/** La nota del fornitore collegata da € 610,00 sulla riga di Interno 1, dal modulo della fattura, con la conferma dell'avviso: la risposta. */
function b59apNotaSuInterno1($test, array $s, \App\Models\Gestionale\FatturaPassiva $fattura)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', $s['c']->id), datiBase([$s['c'], $s['e'], $s['g'], $s['fornitore']], [
        'tipo_documento' => 'nota_credito', 'fattura_rettificata_id' => $fattura->id, 'conferma_avviso_nota' => true,
        'numero_documento' => 'NC-313-R', 'data_documento' => '2026-04-20', 'data_scadenza' => '2026-05-20', 'stato_approvazione' => 'approvata',
        'applica_ritenuta' => false, 'righe' => [b59apRigaAdPersonam($s, 610.00, 0, '1', 'Sconto riparazione')],
        'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo'], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => null],
    ]));
}

test('Coda 313 (decisione 160) — la quota a credito rimasta fuori da una rata emessa ha chiesto la spesa: la nota collegata si rifiuta, e il riallineamento chiude senza correzioni', function () {
    // Lo scenario della quota a credito rimasta fuori; Bice paga la sua rata 1 (il piano ha incassato). La rata 1 è emessa, e la quota di Rossi,
    // fuori dal giornale, ha la sua parte ad personam (30500): il riallineamento la porterà a giornale con la chiusura. La nota da € 610,00 su
    // Interno 1 si rifiuta (`RataQuote::rateEmesseConAdPersonam` conta anche la quota rimasta fuori). Il riallineamento scrive la quota di Rossi
    // con le righe di sempre (il test qui sopra) e nient'altro: l'unità senza persona 61000 − 30500 = 30500, la parte della rata 2.
    [$s, $piano, $q1] = b59apQuotaACreditoFuori($this);
    b59apIncassa($this, $s, $piano, $s['persone']['bice'], [1 => 15000]);
    $fattura = \App\Models\Gestionale\FatturaPassiva::where('condominio_id', $s['c']->id)->where('numero_documento', 'FT-313')->firstOrFail();
    b59apNotaSuInterno1($this, $s, $fattura)->assertSessionHasErrors('fattura_rettificata_id');
    expect((string) session('errors')->first('fattura_rettificata_id'))
        ->toStartWith('Il piano rate «Balcone con saldi» ha già emesso la rata n. 1 con la spesa ad personam della fattura n. FT-313 su «Int. 1»');

    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertSessionHas('message.type', 'success');

    $scrittura = (int) $q1->fresh()->scrittura_contabile_id;
    $u1 = $s['unita']['1']->id;
    $rossi = (int) $s['persone']['mario']->id;
    expect(b59apRighe($scrittura))->toBe([
        ['crediti_condomini', 'avere', 30500, null, $u1],
        ['crediti_condomini', 'avere', 100000, $rossi, $u1],
        ['crediti_condomini', 'dare', 30500, $rossi, $u1],
        ['passate_gestioni', 'dare', 100000, null, null],
    ])
        ->and(DB::table('scritture_contabili')->where('scrittura_padre_id', $scrittura)->exists())->toBeFalse()
        ->and((int) DB::table('righe_scritture')->join('scritture_contabili as sc', 'sc.id', '=', 'righe_scritture.scrittura_id')->whereNull('sc.deleted_at')
            ->where('conto_contabile_id', b59apConto($s, 'crediti_condomini'))->where('immobile_id', $u1)->whereNull('anagrafica_id')
            ->sum(DB::raw("CASE WHEN tipo_riga = 'dare' THEN importo ELSE -importo END")))->toBe(30500);
});

test('Coda 313 (decisione 160) — dopo una compensazione la nota collegata si rifiuta per le rate emesse, e lo storno della compensazione chiude senza correzioni', function () {
    // Lo scenario dello storno della compensazione: la rata 2 emessa (chiusura 30500), la compensazione (il piano ha incassato). La nota da
    // € 610,00 su Interno 1 si rifiuta: la rata 1 (la quota rimasta fuori) e la rata 2 hanno chiesto la spesa. Lo storno della compensazione
    // scrive la quota della rata 1 con la chiusura (AVERE Interno 1 30500) e nessuna figlia in più; «Anticipi» di Rossi 0, l'unità senza
    // persona 61000 − 30500 − 30500 = 0.
    [$s, $piano, $q1] = b59apQuotaACreditoFuori($this);
    $rossi = $s['persone']['mario'];
    b59apEmetti($this, $s, $piano, [2])->assertSessionHas('message.type', 'success');
    $q2 = b59apQuota($piano, 2, $rossi);
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $rossi->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-05-02', 'importo_totale' => '0.00', 'descrizione' => 'Compensazione', 'eccedenza' => '0.00',
        'dettaglio_pagamenti' => [['rata_id' => $q2->id, 'importo' => '305.00'], ['rata_id' => $q1->id, 'importo' => '-305.00']],
    ])->assertSessionHasNoErrors();
    $incasso = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
    $fattura = \App\Models\Gestionale\FatturaPassiva::where('condominio_id', $s['c']->id)->where('numero_documento', 'FT-313')->firstOrFail();
    b59apNotaSuInterno1($this, $s, $fattura)->assertSessionHasErrors('fattura_rettificata_id');
    expect((string) session('errors')->first('fattura_rettificata_id'))->toStartWith('Il piano rate «Balcone con saldi» ha già emesso le rate n. 1 e 2');

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();

    $figlia = (int) $q1->fresh()->scrittura_contabile_id;
    $anticipiRossi = (int) DB::table('righe_scritture')->join('scritture_contabili as sc', 'sc.id', '=', 'righe_scritture.scrittura_id')->whereNull('sc.deleted_at')
        ->where('conto_contabile_id', b59apConto($s, 'anticipi_condomini'))->where('anagrafica_id', $rossi->id)
        ->sum(DB::raw("CASE WHEN tipo_riga = 'dare' THEN importo ELSE -importo END"));
    expect(collect(b59apRighe($figlia))->where(3, null)->where(0, 'crediti_condomini')->values()->all())->toBe([['crediti_condomini', 'avere', 30500, null, (int) $s['unita']['1']->id]])
        ->and(DB::table('scritture_contabili')->where('scrittura_padre_id', $figlia)->exists())->toBeFalse()
        ->and($anticipiRossi)->toBe(0)
        ->and((int) DB::table('righe_scritture')->join('scritture_contabili as sc', 'sc.id', '=', 'righe_scritture.scrittura_id')->whereNull('sc.deleted_at')
            ->where('conto_contabile_id', b59apConto($s, 'crediti_condomini'))->where('immobile_id', $s['unita']['1']->id)->whereNull('anagrafica_id')
            ->sum(DB::raw("CASE WHEN tipo_riga = 'dare' THEN importo ELSE -importo END")))->toBe(0);
});

// ─── Limite L1 della forma semplice: i due scrittori della quota rimasta fuori con uno storno delle versioni di prima ─────────────

/**
 * Lo storno di FT-313, o la nota del fornitore collegata sulla riga di Interno 1 (`b59apNotaSuInterno1`), come li faceva la 1.11.0-beta.58
 * con il piano che aveva incassato: la rotta di oggi li rifiuta, quindi si stacca per un momento la fattura dal piano e la si rimette con lo
 * stesso importo collegato (la forma che i dati hanno nelle installazioni).
 */
function b59apComeLa58($test, array $s, \App\Models\Gestionale\PianoRate $piano, string $come = 'storno'): void
{
    $fattura = \App\Models\Gestionale\FatturaPassiva::where('condominio_id', $s['c']->id)->where('numero_documento', 'FT-313')->firstOrFail();
    $pivot = (array) DB::table('piano_rate_fatture')->where('piano_rate_id', $piano->id)->where('fattura_passiva_id', $fattura->id)->first();
    DB::table('piano_rate_fatture')->where('piano_rate_id', $piano->id)->where('fattura_passiva_id', $fattura->id)->delete();
    if ($come === 'storno') {
        $test->actingAs($test->user)->post(route('admin.gestionale.fatture.storno', [$s['c']->id, $fattura->id]))->assertSessionHasNoErrors();
    } else {
        b59apNotaSuInterno1($test, $s, $fattura)->assertSessionHasNoErrors();
    }
    DB::table('piano_rate_fatture')->insert($pivot);
}

/** «Crediti verso condomini» dell'unità senza persona (l'anticipazione ancora aperta). */
function b59apSenzaPersona(array $s, string $interno): int
{
    return (int) DB::table('righe_scritture')->join('scritture_contabili as sc', 'sc.id', '=', 'righe_scritture.scrittura_id')->whereNull('sc.deleted_at')
        ->where('conto_contabile_id', b59apConto($s, 'crediti_condomini'))->where('immobile_id', $s['unita'][$interno]->id)->whereNull('anagrafica_id')
        ->sum(DB::raw("CASE WHEN tipo_riga = 'dare' THEN importo ELSE -importo END"));
}

test('Coda 313 (decisione 160, limite L1) — dopo lo storno o la nota delle versioni di prima il riallineamento non porta a giornale la quota a credito rimasta fuori con la sua spesa ad personam, e la card la elenca con la strada', function (string $come, string $perche) {
    // Lo scenario della quota a credito rimasta fuori; Bice paga la sua rata 1 (il piano ha incassato), poi, come con la .58, lo storno di
    // FT-313 o la nota del fornitore da € 610,00 collegata sulla riga di Interno 1. A mano: la fattura DARE Interno 1 61000 e Interno 2 30000
    // senza persona; la rata 1 come la 1.10 non chiude niente. Lo storno gira tutto (AVERE 61000 e 30000: le due unità a 0); la nota toglie
    // 61000 da Interno 1 (a 0) e lascia Interno 2 a 30000. La quota di Rossi (spesa 30500, tutta ad personam di FT-313 su Interno 1) scritta
    // con la chiusura porterebbe Interno 1 a 0 − 30500 = − 30500 senza persona (il limite L1). La chiusura toglierebbe una seconda
    // volta una spesa già tolta: la quota resta fuori, con gli stessi controlli dell'emissione delle rate in bozza ((a) lo storno, (b) la nota),
    // e la card dice perché e la strada. Il giornale non cambia.
    [$s, $piano, $q1] = b59apQuotaACreditoFuori($this);
    $incasso = b59apIncassa($this, $s, $piano, $s['persone']['bice'], [1 => 15000]);
    b59apComeLa58($this, $s, $piano, $come);
    $unita = [b59apSenzaPersona($s, '1'), b59apSenzaPersona($s, '2')];
    expect($unita)->toBe($come === 'storno' ? [0, 0] : [0, 30000]);

    $card = $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['riallineamento_crediti'];
    expect(collect($card['rate'])->where('azione', 'scrivi')->all())->toBe([])
        ->and(collect($card['restano'])->pluck('motivo')->filter(fn ($m) => str_contains($m, 'Mario Rossi'))->values()->all())->toBe([
            'la quota di Mario Rossi sulla rata 1 (Balcone con saldi) non si porta a giornale: ' . $perche,
        ]);
    $righe = DB::table('righe_scritture')->count();

    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]));

    expect($q1->fresh()->scrittura_contabile_id)->toBeNull()
        ->and(DB::table('righe_scritture')->count())->toBe($righe)
        ->and([b59apSenzaPersona($s, '1'), b59apSenzaPersona($s, '2')])->toBe($unita);

    // La strada della card, dalle rotte: stornato l'incasso di Bice, annullata la rata 1, il piano in bozza ed eliminato. Le unità senza persona
    // restano quelle di prima (l'emissione della 1.10 non le chiudeva), e la card non elenca più la quota.
    $this->actingAs($this->user)->from('/incassi-rate')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $piano->id, 'rata' => b59apRata($piano, 1)->id]))
        ->assertSessionHas('message.type', 'success');
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => false])->assertSessionHasNoErrors();
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $piano]))->assertSessionHas('message.type', 'success');
    $card = $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['riallineamento_crediti'];
    expect(\App\Models\Gestionale\PianoRate::find($piano->id))->toBeNull()
        ->and([b59apSenzaPersona($s, '1'), b59apSenzaPersona($s, '2')])->toBe($unita)
        ->and(collect($card['restano'] ?? [])->pluck('motivo')->filter(fn ($m) => str_contains($m, 'Mario Rossi'))->all())->toBe([]);
})->with([
    'lo storno' => ['storno', 'il piano «Balcone con saldi» contiene la fattura n. FT-313, che è stata stornata: la rata n. 1 chiederebbe ancora ai condòmini la sua '
        . 'spesa ad personam su «Int. 1», che lo storno ha già tolto dall\'unità. Un piano con una fattura stornata non si ricalcola: storna prima gli incassi '
        . 'registrati sulle sue rate, annulla le emissioni, riporta il piano in bozza ed eliminalo, poi crealo di nuovo; nel carrello le fatture stornate non compaiono più'],
    'la nota collegata' => ['nota', 'il piano «Balcone con saldi» chiede per la fattura n. FT-313 € 610,00 di spesa ad personam su «Int. 1», ma al netto della nota di '
        . 'credito n. NC-313-R la fattura ne ha € 0,00 su quell\'unità: la rata n. 1 chiederebbe ai condòmini una parte che la nota ha già tolto dall\'unità. Storna '
        . 'prima gli incassi registrati sulle sue rate, annulla le emissioni, riporta il piano in bozza ed eliminalo, poi crealo di nuovo: nel carrello la fattura '
        . 'compare al netto della nota'],
]);

test('Coda 313 (decisione 160, limite L1) — con lo storno delle versioni di prima lo storno della compensazione non porta a giornale la quota a credito rimasta fuori con la sua spesa ad personam', function () {
    // La rata 2 emessa come la .58 (Rossi 30500 e Bice 15000 su «Gestione rate», nessuna chiusura); Rossi paga la rata 2 con € 305,00 del
    // credito della rata 1 (la quota è fuori dal giornale: escono da «Passate gestioni»); FT-313 si storna come con la .58. A mano: le due
    // unità senza persona 61000 − 61000 = 0 e 30000 − 30000 = 0. Lo storno della compensazione rimette il credito fra le «Passate
    // gestioni» (lo specchio); la figlia che porta a giornale la quota di Rossi con la chiusura (AVERE Interno 1 30500) lo porterebbe a
    // − 30500 senza persona. Con lo storno di prima la quota resta fuori, come per una quota senza il dettaglio del calcolo: basta lo
    // specchio, e la card la elenca. L'unità resta a 0.
    [$s, $piano, $q1] = b59apQuotaACreditoFuori($this);
    $rossi = $s['persone']['mario'];
    b59apEmettiComePrima($s, $piano, 2);
    $q2 = b59apQuota($piano, 2, $rossi);
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $rossi->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-05-02', 'importo_totale' => '0.00', 'descrizione' => 'Compensazione', 'eccedenza' => '0.00',
        'dettaglio_pagamenti' => [['rata_id' => $q2->id, 'importo' => '305.00'], ['rata_id' => $q1->id, 'importo' => '-305.00']],
    ])->assertSessionHasNoErrors();
    $incasso = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
    b59apComeLa58($this, $s, $piano);
    expect([b59apSenzaPersona($s, '1'), b59apSenzaPersona($s, '2')])->toBe([0, 0]);

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();

    $card = $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['riallineamento_crediti'];
    expect($q1->fresh()->scrittura_contabile_id)->toBeNull()
        ->and([b59apSenzaPersona($s, '1'), b59apSenzaPersona($s, '2')])->toBe([0, 0])
        ->and(collect($card['rate'])->where('azione', 'scrivi')->all())->toBe([])
        ->and(collect($card['restano'])->pluck('motivo')->contains(fn ($m) => str_starts_with($m, 'la quota di Mario Rossi sulla rata 1 (Balcone con saldi) non si porta a giornale: il piano «Balcone con saldi» contiene la fattura n. FT-313')))->toBeTrue();
});

test('Coda 313 (decisione 160, limite L1) — il riallineamento rilegge l\'anticipazione aperta dell\'unità dopo ogni rata: due quote rimaste fuori non la portano sotto zero', function () {
    // Rossi ha il saldo a credito di € 1.000,00 spalmato sulle due rate: ogni sua quota è 30500 − 50000 = − 19500, fuori dalle emissioni della
    // 1.10 di tutte e due le rate (Bice a giornale). Prima delle emissioni, la nota del fornitore da € 305,00 senza la fattura che rettifica,
    // sulla riga di Interno 1 (si registra: nessuna rata emessa chiedeva la spesa). A mano: Interno 1 senza persona 61000 − 30500 = 30500. Il
    // rilevamento guarda ogni rata da sola: la quota della rata 1 chiude 30500 ≤ 30500, quella della rata 2 anche. Scritta la prima, l'unità è a
    // 0; la seconda la porterebbe a − 30500 senza persona: il controllo (c) dell'emissione, riletto sotto blocco, la lascia fuori e lo dice.
    $s = b59apBase(saldi: ['mario' => -100000]);
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 610.00, 0), b59apRigaAdPersonam($s, 300.00, 0, '2', 'Riparazione persiana')]);
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', $s['c']->id), datiBase([$s['c'], $s['e'], $s['g'], $s['fornitore']], [
        'tipo_documento' => 'nota_credito', 'numero_documento' => 'NC-LIBERA', 'data_documento' => '2026-03-20', 'data_scadenza' => '2026-04-20', 'stato_approvazione' => 'approvata',
        'applica_ritenuta' => false, 'righe' => [b59apRigaAdPersonam($s, 305.00, 0, '1', 'Sconto riparazione')],
        'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo'], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => null],
    ]))->assertSessionHasNoErrors();
    $piano = b59apPianoConSaldi($s, $fattura, metodo: 'tutte_rate');
    $rossi = $s['persone']['mario'];
    expect([(int) b59apQuota($piano, 1, $rossi)->importo, (int) b59apQuota($piano, 2, $rossi)->importo])->toBe([-19500, -19500]);
    b59apEmettiComeLa110($s, $piano, 1);
    b59apEmettiComeLa110($s, $piano, 2);
    expect(b59apSenzaPersona($s, '1'))->toBe(30500);

    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertSessionHas('message.type', 'success');

    expect(b59apQuota($piano, 1, $rossi)->scrittura_contabile_id)->not->toBeNull()
        ->and(b59apQuota($piano, 2, $rossi)->scrittura_contabile_id)->toBeNull()
        ->and(b59apSenzaPersona($s, '1'))->toBe(0)
        ->and((string) session('message.message'))->toContain('la quota di Mario Rossi sulla rata 2 (Balcone con saldi) non si porta a giornale: l\'emissione chiuderebbe € 305,00 della spesa ad personam di «Int. 1», ma sull\'unità ne restano aperti € 0,00: la nota di credito n. NC-LIBERA, non collegata a una fattura, l\'ha già tolta dall\'unità');
});
