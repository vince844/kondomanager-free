<?php

/**
 * Coda 313 (1.11.0-beta.59), decisione 160 del registro (`docs/subentro_e_competenza_temporale.md`): lo storno di una fattura con una spesa
 * ad personam che una rata emessa ha già chiesto, e le note di credito del fornitore su quella spesa, **si rifiutano** e dicono la strada:
 * stornare gli incassi del piano, annullarne le emissioni, riportarlo in bozza ed eliminarlo, poi lo storno o la nota, e un piano nuovo dal
 * carrello. Il programma non scrive nessun credito al posto delle persone: la chiusura dell'anticipazione nell'emissione (decisione 153.1)
 * e lo storno toglievano due volte la stessa cifra dall'unità, e la forma che la portava a credito su «Anticipi» (decisione 154.1) leggeva
 * l'unità intera come se avesse una fattura sola.
 *
 * Dopo uno storno o una nota passati di lì con le versioni di prima (le rate in bozza restano nel piano), l'emissione di una rata con quella
 * spesa si rifiuta con la stessa strada (`RataQuote::motivoBloccoEmissioneAdPersonam`): il piano con una fattura stornata non si ricalcola, e
 * quello con una nota collegata dopo la generazione chiede più di quanto la fattura vale.
 *
 * L'esempio: fattura FT-313 da € 1.220,00 (imponibile € 1.000,00 + IVA 22 %) su Interno 1 di Mario Rossi, piano straordinario «Balcone
 * interno 1» in due rate da € 610,00. Ogni emissione scrive DARE Rossi 61000 e AVERE Interno 1 senza persona 61000 (la chiusura). Le cifre
 * in centesimi, rifatte a mano dalla regola nel commento di ogni test; positivo debito, negativo credito.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59313AdPersonamScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b59apAdmin();
});

/** «Crediti verso condomini» dell'unità senza persona: l'anticipazione ancora aperta (DARE − AVERE). */
function b59sfSenzaPersona(array $s, string $interno = '1'): int
{
    return (int) DB::table('righe_scritture')->join('scritture_contabili', 'scritture_contabili.id', '=', 'righe_scritture.scrittura_id')
        ->whereNull('scritture_contabili.deleted_at')->where('righe_scritture.conto_contabile_id', b59apConto($s, 'crediti_condomini'))
        ->where('righe_scritture.immobile_id', $s['unita'][$interno]->id)->whereNull('righe_scritture.anagrafica_id')
        ->selectRaw("COALESCE(SUM(CASE WHEN righe_scritture.tipo_riga = 'dare' THEN righe_scritture.importo ELSE -righe_scritture.importo END), 0) AS n")->value('n');
}

/** «Anticipi» a nome della persona (DARE − AVERE: negativo = credito della persona). */
function b59sfAnticipi(array $s, Anagrafica $chi): int
{
    return (int) DB::table('righe_scritture')->join('scritture_contabili', 'scritture_contabili.id', '=', 'righe_scritture.scrittura_id')
        ->whereNull('scritture_contabili.deleted_at')->where('righe_scritture.conto_contabile_id', b59apConto($s, 'anticipi_condomini'))
        ->where('righe_scritture.anagrafica_id', $chi->id)
        ->selectRaw("COALESCE(SUM(CASE WHEN righe_scritture.tipo_riga = 'dare' THEN righe_scritture.importo ELSE -righe_scritture.importo END), 0) AS n")->value('n');
}

/** Il giornale e i documenti del condominio, per dire che un rifiuto non ha scritto niente: le scritture, le righe e le fatture. */
function b59sfGiornale(array $s): array
{
    $scritture = DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->whereNull('deleted_at')->pluck('id');

    return [
        'scritture' => $scritture->count(),
        'righe' => DB::table('righe_scritture')->whereIn('scrittura_id', $scritture)->count(),
        'documenti' => DB::table('fatture_passive')->where('condominio_id', $s['c']->id)->count(),
    ];
}

/** Lo storno dalla pagina della fattura (`fatture.storno`) che la rotta accetta; restituisce la scrittura della nota nata dallo storno, e in `$messaggio` il messaggio. */
function b59sfStorna($test, array $s, FatturaPassiva $fattura, ?string &$messaggio = null): int
{
    session()->forget(['message', 'errors']);
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.storno', [$s['c']->id, $fattura->id]))->assertSessionHasNoErrors();
    $messaggio = (string) session('message.message');
    $nota = FatturaPassiva::findOrFail($fattura->fresh()->dati_extra['stornata_da_id']);

    return (int) DB::table('fattura_scrittura')->where('fattura_passiva_id', $nota->id)->value('scrittura_contabile_id');
}

/** Lo storno che la rotta rifiuta: restituisce il motivo (l'errore `storno_vietato`, quello che la pagina mostra). */
function b59sfStornoRifiutato($test, array $s, FatturaPassiva $fattura): string
{
    session()->forget(['message', 'errors']);
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.storno', [$s['c']->id, $fattura->id]))->assertSessionHasErrors('storno_vietato');

    return (string) session('errors')->first('storno_vietato');
}

/** Il corpo della nota del fornitore dal modulo della fattura; `$fattura` null: la nota senza la fattura che rettifica. Con `$capitolo` la riga dell'unità porta un capitolo (la riga che il modulo di modifica accetta). */
function b59sfCorpoNota(array $s, ?FatturaPassiva $fattura, float $imponibile, string $numero, string $data, ?int $capitolo = null): array
{
    $riga = b59apRigaAdPersonam($s, $imponibile, 22, '1', 'Sconto riparazione');
    if ($capitolo !== null) {
        $riga = ['conto_id' => $capitolo, 'is_sopravvenienza' => false] + $riga;
    }
    $corpo = datiBase([$s['c'], $s['e'], $s['g'], $s['fornitore']], [
        'tipo_documento' => 'nota_credito', 'numero_documento' => $numero, 'data_documento' => $data, 'data_scadenza' => '2026-07-10',
        'stato_approvazione' => 'approvata', 'applica_ritenuta' => false, 'righe' => [$riga],
        'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo'], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => null],
    ]);

    return $fattura === null ? $corpo : ['fattura_rettificata_id' => $fattura->id, 'conferma_avviso_nota' => true] + $corpo;
}

/** La nota dal modulo della fattura (`fatture.store`), come la manda la pagina; restituisce la risposta. */
function b59sfNota($test, array $s, ?FatturaPassiva $fattura, float $imponibile = 500.00, string $numero = 'NC-313', string $data = '2026-06-10', ?int $capitolo = null)
{
    session()->forget(['message', 'errors']);

    return $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', $s['c']->id), b59sfCorpoNota($s, $fattura, $imponibile, $numero, $data, $capitolo));
}

function b59sfScritturaDi(FatturaPassiva $documento): int
{
    return (int) DB::table('fattura_scrittura')->where('fattura_passiva_id', $documento->id)->value('scrittura_contabile_id');
}

/** Un capitolo «Manutenzioni» del piano dei conti: la riga della nota con un capitolo è quella che il modulo di modifica accetta. */
function b59sfCapitolo(array $s): int
{
    $costo = DB::table('conti_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'codice' => '50.10', 'nome' => 'Manutenzioni', 'tipo' => 'attivo',
        'categoria' => 'crediti', 'created_at' => now(), 'updated_at' => now()]);

    return (int) DB::table('conti')->insertGetId(['piano_conto_id' => $s['pc']->id, 'conto_contabile_id' => $costo, 'nome' => 'Manutenzioni', 'tipo' => 'spesa',
        'importo' => 0, 'is_tecnico' => false, 'created_at' => now(), 'updated_at' => now()]);
}

/**
 * La strada che i rifiuti dicono, dalle rotte delle pagine: lo storno degli incassi dati («Incassi rate»), l'annullamento delle emissioni del
 * piano dall'ultima rata, il piano riportato in bozza e poi eliminato dalla sua pagina.
 *
 * @param list<int> $incassi
 */
function b59sfViaIlPiano($test, array $s, PianoRate $piano, array $incassi = []): void
{
    foreach ($incassi as $incasso) {
        $test->actingAs($test->user)->from('/incassi-rate')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();
    }
    $emesse = DB::table('rate')->where('piano_rate_id', $piano->id)->orderByDesc('numero_rata')->pluck('id')
        ->filter(fn ($id) => DB::table('rate_quote')->where('rata_id', $id)->whereNotNull('scrittura_contabile_id')->exists());
    foreach ($emesse as $rataId) {
        session()->forget(['message', 'errors']);
        $test->actingAs($test->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $piano->id, 'rata' => $rataId]))
            ->assertSessionHas('message.type', 'success');
    }
    $test->actingAs($test->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => false])->assertSessionHasNoErrors();
    session()->forget(['message', 'errors']);
    $test->actingAs($test->user)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $piano]))
        ->assertSessionHas('message.type', 'success');
    expect(PianoRate::find($piano->id))->toBeNull();
}

/**
 * I dati che le installazioni hanno già: lo storno o la nota collegata registrati con le versioni di prima, quando il piano aveva incassato e
 * le rate erano già emesse (lo storno passava con l'avviso «le rate del piano restano»; la nota con la conferma). La rotta di oggi li
 * rifiuta: si stacca per un momento la fattura dal piano, si fa il passo dalla rotta, e la si rimette con lo stesso importo collegato.
 */
function b59sfComeConLeVersioniDiPrima(PianoRate $piano, FatturaPassiva $fattura, callable $passo): void
{
    $pivot = (array) DB::table('piano_rate_fatture')->where('piano_rate_id', $piano->id)->where('fattura_passiva_id', $fattura->id)->first();
    DB::table('piano_rate_fatture')->where('piano_rate_id', $piano->id)->where('fattura_passiva_id', $fattura->id)->delete();
    $passo();
    DB::table('piano_rate_fatture')->insert($pivot);
}

const B59SF_VIA_STORNO = 'Storna prima gli incassi registrati sulle sue rate, annulla le emissioni, riporta il piano in bozza ed eliminalo, poi storna la fattura; '
    . 'le altre fatture del piano, se ce ne sono, tornano disponibili per un piano nuovo.';

// ─── Lo storno della fattura ───────────────────────────────────────────────────────────────────────────────────────────────────

test('Coda 313 — lo storno della fattura ad personam con le rate emesse e incassate si rifiuta, dice il piano e le rate, e non scrive niente', function (array $pagate, int $saldoAtteso) {
    // Decisione 160: le rate 1 e 2 emesse chiedono la spesa ad personam della fattura; lo storno la toglierebbe una seconda volta dall'unità.
    // Rifiuto, nessuna scrittura. Il giornale resta: l'unità senza persona 122000 − 61000 − 61000 = 0, «Anticipi» 0, Rossi le rate (122000)
    // meno quanto ha versato: 0 con tutto versato, 61000 con la sola rata 1, 92000 con € 300,00 sulla rata 1.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $piano, $rossi, $pagate);
    $prima = b59sfGiornale($s);

    expect(b59sfStornoRifiutato($this, $s, $fattura))->toBe('Il piano rate «Balcone interno 1» ha già emesso le rate n. 1 e 2 con la spesa ad personam della '
        . 'fattura n. FT-313 su «Int. 1»: lo storno la toglierebbe dall\'unità dopo che quelle rate l\'hanno chiesta ai condòmini. ' . B59SF_VIA_STORNO)
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and($fattura->fresh()->dati_extra['is_stornata'] ?? false)->toBeFalse()
        ->and(b59sfSenzaPersona($s))->toBe(0)
        ->and(b59sfAnticipi($s, $rossi))->toBe(0)
        ->and(b59apEstratto($this, $s, $rossi))->toBe($saldoAtteso)
        ->and($fattura->fresh()->motivo_blocco_storno)->toStartWith('Il piano rate «Balcone interno 1» ha già emesso le rate n. 1 e 2');
})->with([
    'incasso pieno' => [[1 => 61000, 2 => 61000], 0],
    'incassata la sola rata 1' => [[1 => 61000], 61000],
    'incasso parziale dentro la rata 1' => [[1 => 30000], 92000],
]);

test('Coda 313 — la strada del rifiuto dello storno, percorsa dalle rotte fino in fondo: l\'unità torna a zero e nessuno ha un credito', function () {
    // Stornati gli incassi (Rossi: 122000 di rate − 122000 + 122000 = 122000), annullate le emissioni (le due chiusure se ne vanno: l'unità
    // 122000; Rossi 0), il piano in bozza ed eliminato, lo storno passa e gira tutta l'anticipazione: AVERE Interno 1 122000, DARE «Debiti
    // verso fornitori» 122000. L'unità 122000 − 122000 = 0, «Anticipi» 0, Rossi 0, «Gestione rate» 0; il messaggio di sempre.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    $incasso = b59apIncassa($this, $s, $piano, $rossi, [1 => 61000, 2 => 61000]);
    b59sfStornoRifiutato($this, $s, $fattura);

    b59sfViaIlPiano($this, $s, $piano, [$incasso]);
    expect(b59sfSenzaPersona($s))->toBe(122000)->and(b59apEstratto($this, $s, $rossi))->toBe(0);
    $nc = b59sfStorna($this, $s, $fattura->fresh(), $messaggio);

    expect(b59apRighe($nc))->toBe([
        ['crediti_condomini', 'avere', 122000, null, (int) $s['unita']['1']->id],
        ['debiti_fornitori', 'dare', 122000, null, null],
    ])
        ->and(b59sfSenzaPersona($s))->toBe(0)
        ->and(b59sfAnticipi($s, $rossi))->toBe(0)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(0)
        ->and(b59apGestioneRate($s))->toBe(0)
        ->and($messaggio)->toBe('Storno eseguito! Nota di Credito n. NC-' . date('Y') . '-0001 generata.');
});

test('Coda 313 — due comproprietari, uno solo ha pagato: lo storno si rifiuta e nessuno dei due ha un credito', function () {
    // Interno 1 di Mario Rossi e Luca Verdi al 50 %: ogni rata chiede 30500 a testa. Verdi ha versato le sue due rate: il piano ha incassato,
    // e la guardia di prima lasciava passare lo storno. Ora il rifiuto: Rossi 61000 (le sue due rate, non pagate), Verdi 0, l'unità 0.
    $s = b59apBase(['Mario Rossi' => 50, 'Luca Verdi' => 50]);
    [$rossi, $verdi] = [$s['persone']['mario'], $s['persone']['luca']];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $piano, $verdi, [1 => 30500, 2 => 30500]);
    $prima = b59sfGiornale($s);

    expect(b59sfStornoRifiutato($this, $s, $fattura))->toContain('ha già emesso le rate n. 1 e 2 con la spesa ad personam della fattura n. FT-313 su «Int. 1»')
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and(b59sfSenzaPersona($s))->toBe(0)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(61000)
        ->and(b59apEstratto($this, $s, $verdi))->toBe(0);
});

test('Coda 313 — con la rata 2 in bozza basta la rata 1 emessa e incassata: lo storno si rifiuta e nomina la sola rata 1', function () {
    // Emessa e incassata la rata 1 (chiusura 61000): l'unità 122000 − 61000 = 61000, la parte della rata 2 ancora aperta; Rossi 0.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $piano, $rossi, [1 => 61000]);
    $prima = b59sfGiornale($s);

    expect(b59sfStornoRifiutato($this, $s, $fattura))->toBe('Il piano rate «Balcone interno 1» ha già emesso la rata n. 1 con la spesa ad personam della '
        . 'fattura n. FT-313 su «Int. 1»: lo storno la toglierebbe dall\'unità dopo che quella rata l\'ha chiesta ai condòmini. ' . B59SF_VIA_STORNO)
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and(b59sfSenzaPersona($s))->toBe(61000)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(0);
});

test('Coda 313 — le rate emesse prima di questa versione, senza la riga di chiusura, fermano lo storno lo stesso', function () {
    // Le emissioni della .58 non hanno la chiusura (la parte ad personam su «Gestione rate»: + 122000), ma le rate hanno chiesto la spesa a
    // Rossi lo stesso: la parte si legge dal registro del riparto del piano (`RataQuote::partiAdPersonam`). Rifiuto; l'unità resta a 122000.
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmettiComePrima($s, $piano, 1);
    b59apEmettiComePrima($s, $piano, 2);
    b59apSenzaChiave($piano);
    b59apIncassa($this, $s, $piano, $s['persone']['mario'], [1 => 61000, 2 => 61000]);
    $prima = b59sfGiornale($s);

    expect(b59sfStornoRifiutato($this, $s, $fattura))->toContain('ha già emesso le rate n. 1 e 2 con la spesa ad personam della fattura n. FT-313 su «Int. 1»')
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and(b59sfSenzaPersona($s))->toBe(122000)
        ->and(b59apGestioneRate($s))->toBe(122000);
});

test('Coda 313 — controllo: prima di un incasso lo storno si rifiuta con la scala di sempre, e la strada dalle rotte porta via le chiusure con le emissioni', function () {
    // Il piano ha le rate emesse e nessun incasso: `FatturaPassiva::viaDalPiano` rifiuta, con la strada. Annullate le emissioni, il piano in
    // bozza ed eliminato dalla sua pagina, lo storno gira tutta l'anticipazione: AVERE Interno 1 122000; l'unità senza persona a 0.
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');

    expect(b59sfStornoRifiutato($this, $s, $fattura))->toBe('La fattura è nel piano rate «Balcone interno 1», che ha già rate emesse: dopo lo storno quelle rate la '
        . 'chiederebbero ancora. Annulla le emissioni, riporta il piano in bozza ed eliminalo, poi storna la fattura; le altre fatture del piano, se ce ne sono, '
        . 'tornano disponibili per un piano nuovo.');

    b59sfViaIlPiano($this, $s, $piano);
    $nc = b59sfStorna($this, $s, $fattura->fresh());
    expect(b59apRighe($nc))->toBe([
        ['crediti_condomini', 'avere', 122000, null, (int) $s['unita']['1']->id],
        ['debiti_fornitori', 'dare', 122000, null, null],
    ])->and(b59sfSenzaPersona($s))->toBe(0);
});

test('Coda 313 — dopo una vendita, con la rata 1 di Rossi e la rata 2 di Anna emesse e pagate, lo storno si rifiuta', function () {
    // Rossi paga la rata 1 e vende ad Anna dal 1° maggio: la bozza della rata 2 passa ad Anna, che la paga. Le due rate hanno chiesto la
    // spesa: rifiuto, e gli estratti conto restano quelli di prima (il conguaglio del passaggio non è di questa Coda); l'unità 0, «Anticipi»
    // 0 per tutti e due. Il piano da fatture sta su una gestione ordinaria, come in `RiassegnazioneBozzeTest`.
    $s = b59apBase(tipoGestione: 'ordinaria');
    $rossi = $s['persone']['mario'];
    $anna = Anagrafica::forceCreate(['nome' => 'Anna Neri', 'email' => 'b59sf-anna@test.it', 'indirizzo' => 'Via Po 2', 'codice_fiscale' => 'B59SFANNAXXXXXXX']);
    $anna->condomini()->syncWithoutDetaching([$s['c']->id]);
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $piano, $rossi, [1 => 61000]);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['1']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['righeTitolarita']['mario'], 'anagrafica_entrante_id' => $anna->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ])->assertSessionHasNoErrors();
    b59apEmetti($this, $s, $piano, [2], '2026-05-10')->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $piano, $anna, [2 => 61000], '2026-05-20');
    $prima = b59sfGiornale($s);
    $estratti = [b59apEstratto($this, $s, $rossi), b59apEstratto($this, $s, $anna)];

    expect(b59sfStornoRifiutato($this, $s, $fattura))->toContain('ha già emesso le rate n. 1 e 2 con la spesa ad personam della fattura n. FT-313 su «Int. 1»')
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and(b59sfSenzaPersona($s))->toBe(0)
        ->and([b59apEstratto($this, $s, $rossi), b59apEstratto($this, $s, $anna)])->toBe($estratti)
        ->and(b59sfAnticipi($s, $rossi))->toBe(0)
        ->and(b59sfAnticipi($s, $anna))->toBe(0);
});

test('Coda 313 — due fatture ad personam della stessa unità nello stesso piano: lo storno di una si rifiuta con la rata che ha chiesto la sua parte', function () {
    // FT-313-A e FT-313-B da € 610,00 ciascuna su Interno 1, nel piano «Due fatture» di due rate: ogni rata chiede 61000 ad personam, metà per
    // fattura. Emessa e incassata la rata 1: lo storno di FT-313-A si rifiuta. L'unità 122000 − 61000 = 61000, Rossi 0.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $f1 = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 500.00, 22)], 'FT-313-A');
    $f2 = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 500.00, 22, '1', 'Riparazione ringhiera')], 'FT-313-B');
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c']->id, $s['e']->id]), [
        'gestione_id' => $s['g']->id, 'nome' => 'Due fatture', 'tipo' => 'straordinario',
        'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera del 1° febbraio 2026', 'data_delibera_assemblea' => '2026-02-01',
        'fatture_config' => [['id' => $f1->id, 'importo' => '610,00'], ['id' => $f2->id, 'importo' => '610,00']],
        'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-04-10',
        'capitoli_ids' => [], 'genera_subito' => true, 'accetta_destinatari' => true, 'nota_destinatari' => 'Delibera del 1° febbraio 2026',
    ])->assertSessionHasNoErrors();
    $piano = PianoRate::where('condominio_id', $s['c']->id)->where('nome', 'Due fatture')->firstOrFail();
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2026-02-01'])
        ->assertSessionHasNoErrors();
    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $piano, $rossi, [1 => 61000]);
    $prima = b59sfGiornale($s);

    expect(b59sfStornoRifiutato($this, $s, $f1))->toStartWith('Il piano rate «Due fatture» ha già emesso la rata n. 1 con la spesa ad personam della fattura n. FT-313-A su «Int. 1»')
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and(b59sfSenzaPersona($s))->toBe(61000)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(0);
});

// ─── Due piani sulla stessa unità ──────────────────────────────────────────────────────────────────────────────────────────────

test('Coda 313 — un\'altra fattura ad personam della stessa unità in un piano non ancora emesso: lo storno si rifiuta, e la sua strada arriva a conti giusti anche per l\'altra fattura', function () {
    // FT-SER € 610,00 su Interno 1 nel piano «Serratura» (una rata, approvato, non emesso); FT-313 nel «Balcone» (due rate). Rata 1 del
    // Balcone emessa e incassata: l'unità 61000 (FT-SER) + 122000 − 61000 = 122000. Lo storno di FT-313 e la nota collegata si rifiutano.
    // La strada: stornato l'incasso, annullata la rata 1, il Balcone eliminato (l'unità 183000, Rossi 0), lo storno gira FT-313 intera:
    // l'unità 61000, la sola FT-SER. Emessa la Serratura (chiusura 61000): l'unità 0, Rossi 61000, «Anticipi» 0. Eliminata la nota dello
    // storno, FT-313 torna aperta e fuori da ogni piano: l'unità 122000, Rossi 61000.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $f0 = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 500.00, 22, '1', 'Serratura')], 'FT-SER');
    $p0 = b59apPiano($this, $s, $f0, 1, null, 'Serratura');
    $f1 = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $p1 = b59apPiano($this, $s, $f1);
    b59apEmetti($this, $s, $p1, [1])->assertSessionHas('message.type', 'success');
    $incasso = b59apIncassa($this, $s, $p1, $rossi, [1 => 61000]);
    $prima = b59sfGiornale($s);

    expect(b59sfStornoRifiutato($this, $s, $f1))->toStartWith('Il piano rate «Balcone interno 1» ha già emesso la rata n. 1 con la spesa ad personam della fattura n. FT-313')
        ->and(b59sfNota($this, $s, $f1)->assertSessionHasErrors('fattura_rettificata_id'))->not->toBeNull()
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and(b59sfSenzaPersona($s))->toBe(122000);

    b59sfViaIlPiano($this, $s, $p1, [$incasso]);
    $nc = b59sfStorna($this, $s, $f1->fresh());
    expect(b59sfSenzaPersona($s))->toBe(61000)->and(b59apEstratto($this, $s, $rossi))->toBe(0);

    b59apEmetti($this, $s, $p0, [1], '2026-06-01')->assertSessionHas('message.type', 'success');
    expect(b59sfSenzaPersona($s))->toBe(0)->and(b59apEstratto($this, $s, $rossi))->toBe(61000)->and(b59sfAnticipi($s, $rossi))->toBe(0);

    $notaStorno = (int) DB::table('fattura_scrittura')->where('scrittura_contabile_id', $nc)->value('fattura_passiva_id');
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->delete(route('admin.gestionale.fatture.destroy', [$s['c']->id, $notaStorno]))->assertSessionHas('message.type', 'success');
    expect(b59sfSenzaPersona($s))->toBe(122000)->and(b59apEstratto($this, $s, $rossi))->toBe(61000)->and(b59sfAnticipi($s, $rossi))->toBe(0);
});

test('Coda 313 — con l\'altra fattura della stessa unità già emessa, lo storno si rifiuta; stornato l\'incasso, l\'annullamento della rata passa e l\'unità resta aperta della sola fattura del piano annullato', function () {
    // FT-400 € 610,00 su Interno 1 nel piano «Finestra interno 1» (una rata) emessa: chiusura 61000. FT-313 rata 1 emessa e incassata. Lo
    // storno di FT-313 si rifiuta. Stornato l'incasso e annullata la rata 1 (nessun credito da giustificare: l'annullamento passa), l'unità
    // 61000 + 122000 − 61000 = 122000, la sola FT-313 aperta; Rossi 61000, la rata di FT-400; «Anticipi» 0.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $f1 = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $p1 = b59apPiano($this, $s, $f1);
    $f2 = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 500.00, 22, '1', 'Finestra')], 'FT-400');
    $p2 = b59apPiano($this, $s, $f2, 1, null, 'Finestra interno 1');
    b59apEmetti($this, $s, $p2, [1])->assertSessionHas('message.type', 'success');
    b59apEmetti($this, $s, $p1, [1])->assertSessionHas('message.type', 'success');
    $incasso = b59apIncassa($this, $s, $p1, $rossi, [1 => 61000]);

    expect(b59sfStornoRifiutato($this, $s, $f1))->toStartWith('Il piano rate «Balcone interno 1» ha già emesso la rata n. 1 con la spesa ad personam della fattura n. FT-313');
    $this->actingAs($this->user)->from('/incassi-rate')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $p1->id, 'rata' => b59apRata($p1, 1)->id]))
        ->assertSessionHas('message.type', 'success');

    expect(b59sfSenzaPersona($s))->toBe(122000)
        ->and(b59sfAnticipi($s, $rossi))->toBe(0)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(61000);
});

test('Coda 313 — con la rata 1 emessa come la .58 e un\'altra fattura aperta sulla stessa unità, lo storno si rifiuta lo stesso', function () {
    // La rata 1 emessa come la .58 (senza chiusura) e pagata; FT-400 € 610,00 su Interno 1 registrata e in nessun piano. La guardia legge
    // la parte dal registro del riparto: rifiuto. L'unità 122000 + 61000 = 183000 (nessuna chiusura), Rossi 0.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $f1 = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $p1 = b59apPiano($this, $s, $f1);
    b59apFattura($this, $s, [b59apRigaAdPersonam($s, 500.00, 22, '1', 'Finestra')], 'FT-400');
    b59apEmettiComePrima($s, $p1, 1);
    b59apIncassa($this, $s, $p1, $rossi, [1 => 61000]);
    $prima = b59sfGiornale($s);

    expect(b59sfStornoRifiutato($this, $s, $f1))->toContain('ha già emesso la rata n. 1 con la spesa ad personam della fattura n. FT-313 su «Int. 1»')
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and(b59sfSenzaPersona($s))->toBe(183000)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(0);
});

test('Coda 313 — con un piano della stessa unità emesso come la .58 e pagato, lo storno della fattura del piano nuovo si rifiuta', function () {
    // La Serratura (FT-OLD € 610,00, una rata) emessa come la .58 e pagata: l'unità resta a 61000, il residuo che la card elenca. FT-313,
    // rata 1 emessa (chiusura 61000) e incassata: l'unità 61000 + 122000 − 61000 = 122000. Rifiuto; Rossi 0.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $f0 = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 500.00, 22, '1', 'Serratura')], 'FT-OLD');
    $p0 = b59apPiano($this, $s, $f0, 1, null, 'Serratura');
    b59apEmettiComePrima($s, $p0, 1);
    b59apIncassa($this, $s, $p0, $rossi, [1 => 61000]);
    $f1 = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $p1 = b59apPiano($this, $s, $f1);
    b59apEmetti($this, $s, $p1, [1])->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $p1, $rossi, [1 => 61000]);
    $prima = b59sfGiornale($s);

    expect(b59sfStornoRifiutato($this, $s, $f1))->toStartWith('Il piano rate «Balcone interno 1» ha già emesso la rata n. 1')
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and(b59sfSenzaPersona($s))->toBe(122000)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(0);
});

test('Coda 313 — stornato l\'incasso, l\'annullamento della rata della fattura che non si è potuta stornare passa, e i conti tornano', function () {
    // Rata 1 emessa e incassata, lo storno si rifiuta. Stornato l'incasso (Rossi 61000) e annullata la rata 1 (la chiusura se ne va):
    // l'unità 122000, Rossi 0, «Anticipi» 0.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1]);
    $incasso = b59apIncassa($this, $s, $piano, $rossi, [1 => 61000]);
    b59sfStornoRifiutato($this, $s, $fattura);
    $this->actingAs($this->user)->from('/incassi-rate')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();

    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $piano->id, 'rata' => b59apRata($piano, 1)->id]))
        ->assertSessionHas('message.type', 'success');
    expect(b59sfSenzaPersona($s))->toBe(122000)
        ->and(b59sfAnticipi($s, $rossi))->toBe(0)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(0);
});

// ─── L'emissione dopo uno storno o una nota delle versioni di prima ───────────────────────────────────────────────────────────

test('Coda 313 — una fattura stornata con le versioni di prima e rimasta nel piano: la rata 2 non si emette, il piano non si ricalcola, e la strada arriva a conti giusti', function () {
    // La rata 1 emessa come la .58 (senza chiusura) e pagata; lo storno di allora (il piano aveva incassato): AVERE Interno 1 122000, l'unità
    // 122000 − 122000 = 0. La rata 2 emessa ora chiuderebbe 61000 di una spesa che lo storno ha già tolto: l'unità a − 61000 senza persona.
    // Rifiuto, nessuna scrittura. Il ricalcolo, con il piano di nuovo modificabile (stornato l'incasso, annullata la rata 1), si rifiuta per la
    // fattura stornata (`FatturaStornataNelPianoException`). Il piano in bozza ed eliminato: l'unità 0, Rossi 0.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmettiComePrima($s, $piano, 1);
    $incasso = b59apIncassa($this, $s, $piano, $rossi, [1 => 61000]);
    b59sfComeConLeVersioniDiPrima($piano, $fattura, fn () => b59sfStorna($this, $s, $fattura));
    expect(b59sfSenzaPersona($s))->toBe(0);
    $prima = b59sfGiornale($s);

    b59apEmetti($this, $s, $piano, [2], '2026-06-01')->assertSessionHas('message.type', 'error')->assertSessionHas('message.message',
        'Il piano «Balcone interno 1» contiene la fattura n. FT-313, che è stata stornata: la rata n. 2 chiederebbe ancora ai condòmini la sua spesa ad '
        . 'personam su «Int. 1», che lo storno ha già tolto dall\'unità. Un piano con una fattura stornata non si ricalcola: storna prima gli incassi '
        . 'registrati sulle sue rate, annulla le emissioni, riporta il piano in bozza ed eliminalo, poi crealo di nuovo; nel carrello le fatture stornate '
        . 'non compaiono più. Nessuna rata di questa richiesta è stata emessa.');
    expect(b59sfGiornale($s))->toBe($prima)->and(b59sfSenzaPersona($s))->toBe(0)->and(b59apRata($piano, 2)->stato)->not->toBe('emessa');

    $this->actingAs($this->user)->from('/incassi-rate')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $piano->id, 'rata' => b59apRata($piano, 1)->id]))
        ->assertSessionHas('message.type', 'success');
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), ['accetta_destinatari' => true, 'nota_destinatari' => 'Delibera del 1° febbraio 2026'])
        ->assertSessionHas('message.type', 'error');
    expect((string) session('message.message'))->toContain('contiene la fattura n. FT-313, che è stata stornata');

    b59sfViaIlPiano($this, $s, $piano);
    expect(b59sfSenzaPersona($s))->toBe(0)->and(b59apEstratto($this, $s, $rossi))->toBe(0)->and(b59sfAnticipi($s, $rossi))->toBe(0);
});

test('Coda 313 — una nota collegata con le versioni di prima, dopo la generazione del piano: la rata 2 non si emette, e il piano nuovo dal carrello chiede la fattura al netto', function () {
    // La rata 1 emessa come la .58 (senza chiusura) e incassata; la nota da € 610,00 (500 + IVA 22 %) sulla riga di Interno 1, collegata allora
    // con la conferma: AVERE Interno 1 61000, l'unità 122000 − 61000 = 61000. Il piano chiede per FT-313 su Interno 1 122000 (il registro del
    // riparto, scritto prima della nota), al netto della nota la fattura ne ha 61000: la rata 2 chiederebbe ancora una parte che la nota ha
    // tolto. Rifiuto, nessuna scrittura. Il ricalcolo, con il piano modificabile, si rifiuta (chiede € 1.220,00 per una fattura che al netto ne
    // vale € 610,00). La strada: il piano eliminato (l'unità 61000, Rossi 0), un piano nuovo dal carrello con € 610,00, due rate da 30500:
    // l'unità 0, Rossi 61000; pagate, Rossi 0, «Anticipi» 0.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmettiComePrima($s, $piano, 1);
    $incasso = b59apIncassa($this, $s, $piano, $rossi, [1 => 61000]);
    b59sfComeConLeVersioniDiPrima($piano, $fattura, fn () => b59sfNota($this, $s, $fattura)->assertSessionHasNoErrors());
    expect(b59sfSenzaPersona($s))->toBe(61000);
    $prima = b59sfGiornale($s);

    b59apEmetti($this, $s, $piano, [2], '2026-06-01')->assertSessionHas('message.type', 'error')->assertSessionHas('message.message',
        'Il piano «Balcone interno 1» chiede per la fattura n. FT-313 € 1.220,00 di spesa ad personam su «Int. 1», ma al netto della nota di credito '
        . 'n. NC-313 la fattura ne ha € 610,00 su quell\'unità: la rata n. 2 chiederebbe ai condòmini una parte che la nota ha già tolto dall\'unità. '
        . 'Storna prima gli incassi registrati sulle sue rate, annulla le emissioni, riporta il piano in bozza ed eliminalo, poi crealo di nuovo: nel '
        . 'carrello la fattura compare al netto della nota. Nessuna rata di questa richiesta è stata emessa.');
    expect(b59sfGiornale($s))->toBe($prima);

    $this->actingAs($this->user)->from('/incassi-rate')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $piano->id, 'rata' => b59apRata($piano, 1)->id]))
        ->assertSessionHas('message.type', 'success');
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $piano]), ['accetta_destinatari' => true, 'nota_destinatari' => 'Delibera del 1° febbraio 2026'])
        ->assertSessionHas('message.type', 'error');
    expect((string) session('message.message'))->toContain('non si ricalcola');

    b59sfViaIlPiano($this, $s, $piano);
    expect(b59sfSenzaPersona($s))->toBe(61000)->and(b59apEstratto($this, $s, $rossi))->toBe(0);
    $nuovo = b59apPiano($this, $s, $fattura->fresh(), 2, '610,00', 'Balcone al netto');
    b59apEmetti($this, $s, $nuovo, [1, 2])->assertSessionHas('message.type', 'success');
    expect(b59sfSenzaPersona($s))->toBe(0)->and(b59apEstratto($this, $s, $rossi))->toBe(61000);
    b59apIncassa($this, $s, $nuovo, $rossi, [1 => 30500, 2 => 30500], '2026-07-15');
    expect(b59apEstratto($this, $s, $rossi))->toBe(0)->and(b59sfAnticipi($s, $rossi))->toBe(0);
});

test('Coda 313 — una nota non collegata che ha tolto la spesa dall\'unità prima delle emissioni: la rata che porterebbe l\'unità sotto zero non si emette, e la strada arriva a conti giusti', function () {
    // Il piano approvato, nessuna rata emessa: la nota da € 610,00 su Interno 1 senza la fattura passa (nessuna rata emessa chiede la spesa
    // dell'unità) e gira sull'unità: 122000 − 61000 = 61000. La rata 1 chiude 61000: l'unità 0. La rata 2 chiuderebbe altri 61000: l'unità a
    // − 61000 senza persona. Rifiuto, nessuna scrittura. La strada: annullata la rata 1, il piano eliminato, la nota collegata a FT-313, un
    // piano nuovo dal carrello con € 610,00: due rate da 30500, l'unità 61000 − 30500 − 30500 = 0, Rossi 61000.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59sfNota($this, $s, null, 500.00, 'NC-LIBERA')->assertSessionHasNoErrors();
    $nota = FatturaPassiva::where('condominio_id', $s['c']->id)->where('numero_documento', 'NC-LIBERA')->firstOrFail();
    expect(b59sfSenzaPersona($s))->toBe(61000);
    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    expect(b59sfSenzaPersona($s))->toBe(0);
    $prima = b59sfGiornale($s);

    b59apEmetti($this, $s, $piano, [2], '2026-06-01')->assertSessionHas('message.type', 'error')->assertSessionHas('message.message',
        'L\'emissione chiuderebbe € 610,00 della spesa ad personam di «Int. 1», ma sull\'unità ne restano aperti € 0,00: la nota di credito n. NC-LIBERA, '
        . 'non collegata a una fattura, l\'ha già tolta dall\'unità. Se rettifica una fattura di questo piano, annulla le emissioni, riporta il piano in '
        . 'bozza ed eliminalo, collega la nota a quella fattura («Collega a una fattura») e crea di nuovo il piano: nel carrello la fattura compare al netto '
        . 'della nota. Nessuna rata di questa richiesta è stata emessa.');
    expect(b59sfGiornale($s))->toBe($prima)->and(b59sfSenzaPersona($s))->toBe(0);

    b59sfViaIlPiano($this, $s, $piano);
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.collega-fattura', [$s['c']->id, $nota->id]), ['fattura_rettificata_id' => $fattura->id, 'conferma_avviso_nota' => true])
        ->assertSessionHas('message.type', 'success');
    $nuovo = b59apPiano($this, $s, $fattura->fresh(), 2, '610,00', 'Balcone al netto');
    b59apEmetti($this, $s, $nuovo, [1, 2])->assertSessionHas('message.type', 'success');
    expect(b59sfSenzaPersona($s))->toBe(0)->and(b59apEstratto($this, $s, $rossi))->toBe(61000)->and(b59sfAnticipi($s, $rossi))->toBe(0);
});

// ─── La nota del fornitore ─────────────────────────────────────────────────────────────────────────────────────────────────────

test('Coda 313 — la nota collegata dopo l\'incasso pieno si rifiuta; la sua strada dalle rotte arriva a un piano nuovo al netto, e nessuno ha un credito', function () {
    // Le rate 1 e 2 hanno chiesto la spesa ad personam di FT-313: la nota da € 610,00 sulla riga di Interno 1 si rifiuta, la nota non c'è.
    // La strada: stornato l'incasso, annullate le emissioni, il piano eliminato (l'unità 122000, Rossi 0); la nota passa (AVERE Interno 1
    // 61000: l'unità 61000); il piano nuovo dal carrello con € 610,00 e due rate da 30500 chiude 61000: l'unità 0; Rossi paga 61000: 0.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1, 2]);
    $incasso = b59apIncassa($this, $s, $piano, $rossi, [1 => 61000, 2 => 61000]);
    $prima = b59sfGiornale($s);

    b59sfNota($this, $s, $fattura)->assertSessionHasErrors(['fattura_rettificata_id' => 'Il piano rate «Balcone interno 1» ha già emesso le rate n. 1 e 2 con la '
        . 'spesa ad personam della fattura n. FT-313 su «Int. 1»: la nota la toglierebbe dall\'unità dopo che quelle rate l\'hanno chiesta ai condòmini. Storna '
        . 'prima gli incassi registrati sulle sue rate, annulla le emissioni, riporta il piano in bozza ed eliminalo, poi registra la nota; nel carrello del piano '
        . 'nuovo la fattura compare al netto della nota.']);
    expect(b59sfGiornale($s))->toBe($prima)->and(FatturaPassiva::where('numero_documento', 'NC-313')->exists())->toBeFalse();

    b59sfViaIlPiano($this, $s, $piano, [$incasso]);
    b59sfNota($this, $s, $fattura->fresh())->assertSessionHasNoErrors();
    expect(b59sfSenzaPersona($s))->toBe(61000);
    $nuovo = b59apPiano($this, $s, $fattura->fresh(), 2, '610,00', 'Balcone al netto');
    b59apEmetti($this, $s, $nuovo, [1, 2])->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $nuovo, $rossi, [1 => 30500, 2 => 30500], '2026-07-15');
    expect(b59sfSenzaPersona($s))->toBe(0)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(0)
        ->and(b59sfAnticipi($s, $rossi))->toBe(0)
        ->and(b59apGestioneRate($s))->toBe(0);
});

test('Coda 313 — la nota collegata con la rata 2 in bozza si rifiuta per la rata 1 emessa', function () {
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1]);
    b59apIncassa($this, $s, $piano, $s['persone']['mario'], [1 => 61000]);
    $prima = b59sfGiornale($s);

    b59sfNota($this, $s, $fattura)->assertSessionHasErrors('fattura_rettificata_id');
    expect((string) session('errors')->first('fattura_rettificata_id'))->toStartWith('Il piano rate «Balcone interno 1» ha già emesso la rata n. 1 con la spesa ad personam')
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and(b59sfSenzaPersona($s))->toBe(61000);
});

test('Coda 313 — controllo: la nota collegata che toglie solo dalla parte comune della fattura non tocca l\'unità e passa', function () {
    // Fattura mista (€ 610,00 ad personam su Interno 1, € 610,00 comuni), le due rate emesse e la rata 1 incassata. La nota da € 100,00 + IVA
    // 22 % su una riga con un capitolo e senza unità non toglie niente dall'unità, quindi la guardia della spesa ad personam non la riguarda:
    // passa con la conferma, e l'unità resta a 61000 − 30500 − 30500 = 0.
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 500.00, 22), b59apRigaComune(500.00, 22)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $piano, $s['persone']['mario'], [1 => b59apQuota($piano, 1, $s['persone']['mario'])->importo]);
    $corpo = b59sfCorpoNota($s, $fattura, 100.00, 'NC-COMUNE', '2026-06-10');
    $corpo['righe'] = [['conto_id' => b59sfCapitolo($s), 'is_sopravvenienza' => false] + b59apRigaComune(100.00, 22, 'Sconto ponteggio')];

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', $s['c']->id), $corpo)->assertSessionHasNoErrors();
    expect(FatturaPassiva::where('numero_documento', 'NC-COMUNE')->exists())->toBeTrue()->and(b59sfSenzaPersona($s))->toBe(0);
});

test('Coda 313 — «Collega a una fattura» di una nota con la riga dell\'unità si rifiuta quando una rata emessa ha chiesto la spesa della fattura, e il collegamento resta com\'era', function () {
    // La nota senza fattura registrata prima delle emissioni (passa: nessuna rata emessa chiede la spesa dell'unità): l'unità 61000. La rata 1
    // emessa (chiusura 61000: l'unità 0) e incassata. «Collega a una fattura» con FT-313: rifiuto con la strada; la nota resta senza fattura,
    // il giornale com'era.
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59sfNota($this, $s, null, 500.00, 'NC-LIBERA')->assertSessionHasNoErrors();
    $nota = FatturaPassiva::where('condominio_id', $s['c']->id)->where('numero_documento', 'NC-LIBERA')->firstOrFail();
    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $piano, $s['persone']['mario'], [1 => 61000]);
    $prima = b59sfGiornale($s);

    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.collega-fattura', [$s['c']->id, $nota->id]), ['fattura_rettificata_id' => $fattura->id, 'conferma_avviso_nota' => true])
        ->assertSessionHasErrors(['collega_vietato' => 'Il piano rate «Balcone interno 1» ha già emesso la rata n. 1 con la spesa ad personam della fattura n. FT-313 '
            . 'su «Int. 1»: collegata, la nota la toglierebbe dalla fattura dopo che quella rata l\'ha chiesta ai condòmini. Storna prima gli incassi registrati '
            . 'sulle sue rate, annulla le emissioni, riporta il piano in bozza ed eliminalo, poi collega la nota; nel carrello del piano nuovo la fattura compare '
            . 'al netto della nota.']);
    expect($nota->fresh()->fattura_rettificata_id)->toBeNull()->and(b59sfGiornale($s))->toBe($prima)->and(b59sfSenzaPersona($s))->toBe(0);
});

test('Coda 313 — la nota senza fattura con la riga di un\'unità a cui una rata emessa ha chiesto la spesa ad personam si rifiuta, e dice di scegliere la fattura', function () {
    // Le due rate emesse e incassate chiedono la spesa ad personam di Interno 1. La nota da € 610,00 senza la fattura che rettifica: il
    // programma non sa se toglie una spesa che le rate hanno già chiesto, e non la sceglie al posto dell'amministratore. Rifiuto, nessuna nota.
    // Se la nota rettifica FT-313, collegata si rifiuterebbe finché il piano c'è: il messaggio lo dice subito (FS-1 della forma
    // semplice). La strada fino in fondo: stornato l'incasso, annullate le emissioni, il piano in bozza ed eliminato, la nota collegata passa;
    // l'unità senza persona 122000 − 61000 = 61000 (FT-313 al netto della nota, che il piano nuovo dal carrello chiederà), Rossi 0.
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1, 2]);
    $incasso = b59apIncassa($this, $s, $piano, $s['persone']['mario'], [1 => 61000, 2 => 61000]);
    $prima = b59sfGiornale($s);

    b59sfNota($this, $s, null, 500.00, 'NC-LIBERA')->assertSessionHasErrors(['fattura_rettificata_id' => 'La nota non dice quale fattura rettifica, e su «Int. 1» il '
        . 'piano rate «Balcone interno 1» ha già emesso le rate n. 1 e 2 con una spesa ad personam: senza la fattura il programma non sa se la nota toglie '
        . 'dall\'unità una spesa che quelle rate hanno già chiesto ai condòmini. Scegli la fattura che la nota rettifica («Fattura che la nota rettifica») e '
        . 'registrala collegata a quella fattura; se è una fattura del piano rate «Balcone interno 1», prima storna gli incassi registrati sulle sue rate, '
        . 'annulla le emissioni, riporta il piano in bozza ed eliminalo.']);
    expect(b59sfGiornale($s))->toBe($prima)->and(FatturaPassiva::where('numero_documento', 'NC-LIBERA')->exists())->toBeFalse();

    b59sfViaIlPiano($this, $s, $piano, [$incasso]);
    b59sfNota($this, $s, $fattura, 500.00, 'NC-LIBERA')->assertSessionHasNoErrors();
    expect(b59sfSenzaPersona($s))->toBe(61000)->and(b59apEstratto($this, $s, $s['persone']['mario']))->toBe(0);
});

test('Coda 313 — due comproprietari: la nota senza fattura sulla loro unità si rifiuta, e nessuno dei due ha un credito', function () {
    // Interno 1 di Mario Rossi (70 %) e Luca Verdi (30 %), le due rate emesse e pagate da tutti e due (Rossi 85400, Verdi 36600). Rifiuto: Rossi
    // 0, Verdi 0, l'unità 0.
    $s = b59apBase(['Mario Rossi' => 70, 'Luca Verdi' => 30]);
    [$rossi, $verdi] = [$s['persone']['mario'], $s['persone']['luca']];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $piano, $rossi, [1 => 42700, 2 => 42700]);
    b59apIncassa($this, $s, $piano, $verdi, [1 => 18300, 2 => 18300]);
    $prima = b59sfGiornale($s);

    b59sfNota($this, $s, null, 500.00, 'NC-LIBERA')->assertSessionHasErrors('fattura_rettificata_id');
    expect(b59sfGiornale($s))->toBe($prima)
        ->and(b59sfSenzaPersona($s))->toBe(0)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(0)
        ->and(b59apEstratto($this, $s, $verdi))->toBe(0);
});

test('Coda 313 — la nota senza fattura, aperta e inviata senza toccare niente, si salva con il giornale di prima; con un importo più alto sull\'unità si rifiuta', function () {
    // La nota da € 610,00 registrata prima delle emissioni (passa): AVERE Interno 1 61000, DARE «Debiti» 61000; poi la rata 1 emessa. La
    // modifica con una data nuova riscrive la stessa scrittura. Con € 700,00 la nota toglierebbe dall'unità più di prima, dove una rata
    // emessa chiede la spesa: rifiuto, la nota resta quella di prima.
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    $capitolo = b59sfCapitolo($s);
    b59sfNota($this, $s, null, 500.00, 'NC-LIBERA', '2026-03-20', $capitolo)->assertSessionHasNoErrors();
    $nota = FatturaPassiva::where('condominio_id', $s['c']->id)->where('numero_documento', 'NC-LIBERA')->firstOrFail();
    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    $righe = [
        ['crediti_condomini', 'avere', 61000, null, (int) $s['unita']['1']->id],
        ['debiti_fornitori', 'dare', 61000, null, null],
    ];
    expect(b59apRighe(b59sfScritturaDi($nota)))->toBe($righe);

    $corpo = b59sfCorpoNota($s, null, 500.00, 'NC-LIBERA', '2026-03-22', $capitolo);
    unset($corpo['tipo_documento'], $corpo['stato_approvazione']);
    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s['c']->id, $nota->id]), $corpo)->assertSessionHasNoErrors();
    expect($nota->fresh()->data_documento->format('Y-m-d'))->toBe('2026-03-22')->and(b59apRighe(b59sfScritturaDi($nota)))->toBe($righe);

    $corpo = b59sfCorpoNota($s, null, 700.00, 'NC-LIBERA', '2026-03-22', $capitolo);
    unset($corpo['tipo_documento'], $corpo['stato_approvazione']);
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s['c']->id, $nota->id]), $corpo)
        ->assertSessionHasErrors(['fattura_rettificata_id' => 'La nota non dice quale fattura rettifica, e su «Int. 1» il piano rate «Balcone interno 1» ha già '
            . 'emesso la rata n. 1 con una spesa ad personam: la modifica toglierebbe dall\'unità più di prima, e senza la fattura il programma non sa se toglie '
            . 'una spesa che quella rata ha già chiesto ai condòmini. Collega prima la nota alla fattura che rettifica («Collega a una fattura»), poi modificala; '
            . 'se è una fattura del piano rate «Balcone interno 1», prima annulla le emissioni, riporta il piano in bozza ed eliminalo.']);
    expect(b59apRighe(b59sfScritturaDi($nota)))->toBe($righe)->and((int) $nota->fresh()->totale_documento)->toBe(-61000);

    // La strada fino in fondo (FS-1): annullata la rata 1, il piano in bozza ed eliminato, «Collega a una fattura» con FT-313, poi la modifica
    // da € 700,00 passa: AVERE Interno 1 85400, l'unità senza persona 122000 − 85400 = 36600.
    b59sfViaIlPiano($this, $s, $piano);
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.collega-fattura', [$s['c']->id, $nota->id]), ['fattura_rettificata_id' => $fattura->id, 'conferma_avviso_nota' => true])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s['c']->id, $nota->id]), $corpo)->assertSessionHasNoErrors();
    expect((int) $nota->fresh()->totale_documento)->toBe(-85400)->and(b59sfSenzaPersona($s))->toBe(36600);
});

test('Coda 313 — la nota collegata con le versioni di prima, aperta e inviata senza toccare niente, si salva; con un importo più alto sull\'unità si rifiuta', function () {
    // Le due rate emesse come la .58 e incassate; la nota da € 610,00 collegata allora con la conferma: AVERE Interno 1 61000, DARE «Debiti»
    // 61000. La modifica con una data nuova non riduce di più la fattura e passa, con la stessa scrittura; con € 800,00 la ridurrebbe di più
    // sull'unità dove le rate hanno chiesto la spesa: rifiuto.
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmettiComePrima($s, $piano, 1);
    b59apEmettiComePrima($s, $piano, 2);
    b59apIncassa($this, $s, $piano, $s['persone']['mario'], [1 => 61000, 2 => 61000]);
    $capitolo = b59sfCapitolo($s);
    b59sfComeConLeVersioniDiPrima($piano, $fattura, fn () => b59sfNota($this, $s, $fattura, 500.00, 'NC-313', '2026-06-10', $capitolo)->assertSessionHasNoErrors());
    $nota = FatturaPassiva::where('condominio_id', $s['c']->id)->where('numero_documento', 'NC-313')->firstOrFail();
    $righe = [
        ['crediti_condomini', 'avere', 61000, null, (int) $s['unita']['1']->id],
        ['debiti_fornitori', 'dare', 61000, null, null],
    ];
    expect(b59apRighe(b59sfScritturaDi($nota)))->toBe($righe);

    $corpo = b59sfCorpoNota($s, $fattura, 500.00, 'NC-313', '2026-06-12', $capitolo);
    unset($corpo['tipo_documento'], $corpo['stato_approvazione'], $corpo['fattura_rettificata_id']);
    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s['c']->id, $nota->id]), $corpo)->assertSessionHasNoErrors();
    expect($nota->fresh()->data_documento->format('Y-m-d'))->toBe('2026-06-12')->and(b59apRighe(b59sfScritturaDi($nota)))->toBe($righe);

    $corpo = b59sfCorpoNota($s, $fattura, 800.00, 'NC-313', '2026-06-12', $capitolo);
    unset($corpo['tipo_documento'], $corpo['stato_approvazione'], $corpo['fattura_rettificata_id']);
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s['c']->id, $nota->id]), $corpo)->assertSessionHasErrors('fattura_rettificata_id');
    expect((string) session('errors')->first('fattura_rettificata_id'))->toStartWith('Il piano rate «Balcone interno 1» ha già emesso le rate n. 1 e 2 con la spesa ad personam della fattura n. FT-313')
        ->and((string) session('errors')->first('fattura_rettificata_id'))->toContain('poi modifica la nota')
        ->and(b59apRighe(b59sfScritturaDi($nota)))->toBe($righe);
});

test('Coda 313 — la nota collegata con le versioni di prima e poi scollegata: lo storno si rifiuta lo stesso', function () {
    // Le due rate emesse come la .58 e incassate, la nota collegata allora; «Scollega» (il rifiuto dello storno con la nota collegata lo
    // propone): lo storno resta rifiutato, perché le rate hanno chiesto la spesa. Nessun credito: Rossi 0, «Anticipi» 0, l'unità 122000 − 61000
    // = 61000 (le emissioni della .58 non hanno chiusura).
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmettiComePrima($s, $piano, 1);
    b59apEmettiComePrima($s, $piano, 2);
    b59apIncassa($this, $s, $piano, $rossi, [1 => 61000, 2 => 61000]);
    b59sfComeConLeVersioniDiPrima($piano, $fattura, fn () => b59sfNota($this, $s, $fattura)->assertSessionHasNoErrors());
    $nota = FatturaPassiva::where('condominio_id', $s['c']->id)->where('numero_documento', 'NC-313')->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.scollega-fattura', [$s['c']->id, $nota->id]))->assertSessionHas('message.type', 'success');
    $prima = b59sfGiornale($s);

    expect(b59sfStornoRifiutato($this, $s, $fattura))->toStartWith('Il piano rate «Balcone interno 1» ha già emesso le rate n. 1 e 2')
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and(b59sfAnticipi($s, $rossi))->toBe(0)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(0)
        ->and(b59sfSenzaPersona($s))->toBe(61000);
});

// ─── Le varianti vicine ────────────────────────────────────────────────────────────────────────────────────────────────────────

test('Coda 313 — la nota collegata contestata si rifiuta lo stesso: il giornale la scrive anche se il netto non la conta', function () {
    // Le due rate emesse e incassate. La nota da € 610,00 sulla riga di Interno 1, «contestata»: fuori dalla scala del netto (V3), ma la sua
    // scrittura toglierebbe 61000 dall'unità dopo le chiusure. Rifiuto, nessuna nota.
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1, 2]);
    b59apIncassa($this, $s, $piano, $s['persone']['mario'], [1 => 61000, 2 => 61000]);
    $prima = b59sfGiornale($s);
    $corpo = ['stato_approvazione' => 'contestata'] + b59sfCorpoNota($s, $fattura, 500.00, 'NC-313', '2026-06-10');

    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', $s['c']->id), $corpo)->assertSessionHasErrors('fattura_rettificata_id');
    expect((string) session('errors')->first('fattura_rettificata_id'))->toStartWith('Il piano rate «Balcone interno 1» ha già emesso le rate n. 1 e 2')
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and(b59sfSenzaPersona($s))->toBe(0);
});

test('Coda 313 — un piano senza il registro del riparto, con una nota collegata con le versioni di prima: la rata 2 non si emette', function () {
    // Il piano come lo generavano la 1.9 e la 1.10: niente `righe_riparto`, niente chiave nelle quote; la parte ad personam viene dalla
    // ricostruzione controllata (decisione 154.2). La rata 1 emessa come la .58 e incassata, la nota da € 610,00 collegata allora: l'unità
    // 122000 − 61000 = 61000. Senza il registro il piano conta tutta la riga, 122000, più dei 61000 che la fattura ha al netto: rifiuto.
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmettiComePrima($s, $piano, 1);
    b59apIncassa($this, $s, $piano, $s['persone']['mario'], [1 => 61000]);
    b59sfComeConLeVersioniDiPrima($piano, $fattura, fn () => b59sfNota($this, $s, $fattura)->assertSessionHasNoErrors());
    b59apSenzaChiave($piano);
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    $prima = b59sfGiornale($s);

    b59apEmetti($this, $s, $piano, [2], '2026-06-01')->assertSessionHas('message.type', 'error');
    expect((string) session('message.message'))->toStartWith('Il piano «Balcone interno 1» chiede per la fattura n. FT-313 € 1.220,00 di spesa ad personam su «Int. 1»')
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and(b59sfSenzaPersona($s))->toBe(61000);
});

test('Coda 313 — controllo: la nota senza fattura sull\'unità che le rate emesse non chiedono ad personam passa', function () {
    // Fattura mista: € 610,00 ad personam su Interno 1, € 610,00 comuni. Le rate emesse: Bice, su Interno 2, paga la sola spesa comune, con la
    // parte ad personam a zero. La nota senza fattura sulla riga di Interno 2 non tocca una spesa che le rate hanno chiesto ad personam: passa.
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 500.00, 22), b59apRigaComune(500.00, 22)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    expect(b59apQuota($piano, 1, $s['persone']['bice'])->scrittura_contabile_id)->not->toBeNull()
        ->and(\App\Models\Gestionale\RataQuote::partiAdPersonam([b59apQuota($piano, 1, $s['persone']['bice'])]))->toBe([(int) b59apQuota($piano, 1, $s['persone']['bice'])->id => 0]);
    $corpo = b59sfCorpoNota($s, null, 100.00, 'NC-INT2', '2026-06-10');
    $corpo['righe'][0]['immobile_id'] = $s['unita']['2']->id;

    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', $s['c']->id), $corpo)->assertSessionHasNoErrors();
    expect(FatturaPassiva::where('numero_documento', 'NC-INT2')->exists())->toBeTrue();
});

test('Coda 313 — con l\'altra fattura della stessa unità emessa, eliminato il piano lo storno passa: la sua nota non è una nota senza fattura, e la sua modifica di una data si salva', function () {
    // FT-400 nel suo piano, emesso (chiusura 61000); FT-313 nel «Balcone», nessuna rata emessa: il piano in bozza ed eliminato. Lo storno di
    // FT-313 non trova più rate che chiedano la sua spesa: passa e gira tutto, AVERE Interno 1 122000. L'unità 122000 + 61000 − 61000 − 122000
    // = 0; Rossi 61000, la rata di FT-400. La nota dello storno modificata in una data: la stessa scrittura.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $f1 = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $p1 = b59apPiano($this, $s, $f1);
    $f2 = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 500.00, 22, '1', 'Finestra')], 'FT-400');
    $p2 = b59apPiano($this, $s, $f2, 1, null, 'Finestra interno 1');
    b59apEmetti($this, $s, $p2, [1])->assertSessionHas('message.type', 'success');
    b59sfViaIlPiano($this, $s, $p1);

    $nc = b59sfStorna($this, $s, $f1->fresh());
    $righe = [
        ['crediti_condomini', 'avere', 122000, null, (int) $s['unita']['1']->id],
        ['debiti_fornitori', 'dare', 122000, null, null],
    ];
    expect(b59apRighe($nc))->toBe($righe)->and(b59sfSenzaPersona($s))->toBe(0)->and(b59apEstratto($this, $s, $rossi))->toBe(61000);

    $nota = FatturaPassiva::findOrFail($f1->fresh()->dati_extra['stornata_da_id']);
    $corpo = datiBase([$s['c'], $s['e'], $s['g'], $s['fornitore']], [
        'numero_documento' => $nota->numero_documento, 'data_documento' => '2026-06-20', 'data_scadenza' => '2026-07-20', 'applica_ritenuta' => false,
        'righe' => [['descrizione' => '[STORNO] Riparazione balcone', 'importo_imponibile' => 1000.00, 'aliquota_iva' => 22, 'conto_id' => null,
            'immobile_id' => $s['unita']['1']->id, 'is_sopravvenienza' => false, 'concorre_base_ritenuta' => true]],
        'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo'], 'competenza' => null, 'override_budget' => null],
    ]);
    unset($corpo['tipo_documento']);
    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s['c']->id, $nota->id]), $corpo)->assertSessionHasNoErrors();
    expect($nota->fresh()->data_documento->format('Y-m-d'))->toBe('2026-06-20')->and(b59apRighe(b59sfScritturaDi($nota)))->toBe($righe);
});

test('Coda 313 — la nota collegata sulla sola riga di Interno 2 di una fattura ad personam su due unità: il rifiuto nomina l\'unità della nota', function () {
    // FT-313 con € 610,00 ad personam su Interno 1 (Rossi) e € 610,00 su Interno 2 (Bice), due rate emesse e la rata 1 incassata. La nota da
    // € 100,00 + IVA 22 % sulla riga di Interno 2: le rate hanno chiesto la spesa anche lì, rifiuto; la frase dice «Int. 2», l'unità che la
    // nota riduce, non tutte quelle della fattura.
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 500.00, 22), b59apRigaAdPersonam($s, 500.00, 22, '2', 'Riparazione persiana')]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $piano, $s['persone']['bice'], [1 => 30500]);
    $corpo = b59sfCorpoNota($s, $fattura, 100.00, 'NC-INT2', '2026-06-10');
    $corpo['righe'][0]['immobile_id'] = $s['unita']['2']->id;

    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', $s['c']->id), $corpo)->assertSessionHasErrors('fattura_rettificata_id');
    expect((string) session('errors')->first('fattura_rettificata_id'))->toStartWith('Il piano rate «Balcone interno 1» ha già emesso le rate n. 1 e 2 con la spesa ad personam della fattura n. FT-313 su «Int. 2»: la nota');
});

test('Coda 313 (decisione 160, limite L4) — la nota collegata con la riga su un\'unità dove la fattura non ha righe, e una rata emessa ne ha chiesto la spesa ad personam, si rifiuta e dice la strada', function () {
    // FT-400 da € 1.220,00 ad personam su Interno 1, nel piano «Serratura» in una rata emessa: la chiusura porta Interno 1 senza persona a
    // 122000 − 122000 = 0. FT-313 da € 366,00 (300 + IVA 22 %) sulla sola riga di Interno 2, in nessun piano. La nota del fornitore collegata a
    // FT-313 con la riga da € 122,00 su Interno 1: FT-313 lì non ha niente, quindi la riga toglierebbe 12200 dall'anticipazione di FT-400, che
    // la rata ha già chiuso (Interno 1 a − 12200 senza persona). Rifiuto, nessuna scrittura. La strada: la riga sull'unità della fattura
    // (Interno 2: 36600 − 12200 = 24400, Interno 1 resta 0), oppure la nota collegata alla fattura che rettifica davvero, FT-400, che si
    // rifiuta con la strada del suo piano.
    $s = b59apBase();
    $f400 = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 1000.00, 22)], 'FT-400');
    $serratura = b59apPiano($this, $s, $f400, 1, null, 'Serratura');
    b59apEmetti($this, $s, $serratura, [1])->assertSessionHas('message.type', 'success');
    $f313 = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 300.00, 22, '2', 'Riparazione persiana')]);
    expect([b59sfSenzaPersona($s, '1'), b59sfSenzaPersona($s, '2')])->toBe([0, 36600]);
    $prima = b59sfGiornale($s);

    b59sfNota($this, $s, $f313, 100.00, 'NC-L4')->assertSessionHasErrors('fattura_rettificata_id');
    expect((string) session('errors')->first('fattura_rettificata_id'))->toBe('La nota ha una riga su «Int. 1», dove la fattura n. FT-313 non ha righe, e su '
        . 'quell\'unità il piano rate «Serratura» ha già emesso la rata n. 1 con una spesa ad personam: la nota la toglierebbe dall\'unità dopo che quella '
        . 'rata l\'ha chiesta ai condòmini. Se la nota rettifica la fattura n. FT-313, metti la riga sull\'unità della fattura, «Int. 2»; se rettifica '
        . 'un\'altra fattura, collegala a quella.')
        ->and(b59sfGiornale($s))->toBe($prima)
        ->and([b59sfSenzaPersona($s, '1'), b59sfSenzaPersona($s, '2')])->toBe([0, 36600]);

    // La seconda strada: collegata a FT-400, la nota si rifiuta con la scala del piano «Serratura» (decisione 26, punti 5 e 6), che non ha incassato.
    b59sfNota($this, $s, $f400, 100.00, 'NC-L4')->assertSessionHasErrors('fattura_rettificata_id');
    expect((string) session('errors')->first('fattura_rettificata_id'))
        ->toStartWith('La fattura è nel piano rate «Serratura», che ha già rate emesse: quelle rate sono già calcolate e non si aggiornano da sole. Annulla le emissioni');

    // La prima strada, fino in fondo: la riga sull'unità della fattura.
    $corpo = b59sfCorpoNota($s, $f313, 100.00, 'NC-L4', '2026-06-10');
    $corpo['righe'][0]['immobile_id'] = $s['unita']['2']->id;
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', $s['c']->id), $corpo)->assertSessionHasNoErrors();
    expect([b59sfSenzaPersona($s, '1'), b59sfSenzaPersona($s, '2')])->toBe([0, 24400]);
});

test('Coda 313 (decisione 160, limite L4) — controllo: la riga della nota collegata su un\'unità fuori dalla fattura passa se nessuna rata emessa ne ha chiesto una spesa ad personam', function () {
    // Come sopra, con il piano «Serratura» generato e approvato ma non emesso: nessuna rata ha chiesto la spesa di Interno 1, la forma semplice
    // non rifiuta (la decisione 26, punto 6: la riga fuori dalle unità della fattura è un'eccedenza non attribuibile nel netto). Il giornale:
    // Interno 1 122000 − 12200 = 109800, come prima della forma semplice.
    $s = b59apBase();
    $f400 = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 1000.00, 22)], 'FT-400');
    b59apPiano($this, $s, $f400, 1, null, 'Serratura');
    $f313 = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 300.00, 22, '2', 'Riparazione persiana')]);

    b59sfNota($this, $s, $f313, 100.00, 'NC-L4')->assertSessionHasNoErrors();
    expect([b59sfSenzaPersona($s, '1'), b59sfSenzaPersona($s, '2')])->toBe([109800, 36600]);
});

test('Coda 313 (decisione 160, limite L4) — la nota collegata che toglie da un\'unità più di quanto la fattura le addebita, dove una rata emessa ha chiesto una spesa ad personam, si rifiuta per la parte in più, anche in modifica', function () {
    // FT-400 da € 1.220,00 su Interno 1 nel piano «Serratura», la rata emessa: Interno 1 senza persona a 0. FT-313 da € 622,00: € 122,00
    // (100 + IVA 22 %) su Interno 1 e € 500,00 di spesa comune, in nessun piano: Interno 1 a 12200. A mano: una nota collegata da € 366,00 su
    // Interno 1 toglierebbe 36600, 24400 più dei 12200 di FT-313, e la parte in più verrebbe dall'anticipazione di FT-400, già chiusa (Interno 1
    // a − 24400): rifiuto, nessuna scrittura. La strada del messaggio: la riga a non più di € 122,00 passa (Interno 1 a 12200 − 12200 = 0).
    // Poi una seconda nota da € 122,00 su Interno 1: al netto della prima FT-313 lì non ha più niente, rifiuto con «togli la riga». E la prima
    // nota modificata a € 366,00 dalla sua pagina: la stessa regola, con la nota che si modifica fuori dalle «altre».
    $s = b59apBase();
    $f400 = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 1000.00, 22)], 'FT-400');
    b59apEmetti($this, $s, b59apPiano($this, $s, $f400, 1, null, 'Serratura'), [1])->assertSessionHas('message.type', 'success');
    $f313 = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.00, 22, '1', 'Maniglia'), b59apRigaComune(500.00)]);
    expect(b59sfSenzaPersona($s))->toBe(12200);
    $capitolo = b59sfCapitolo($s);
    $prima = b59sfGiornale($s);
    $coda = ', e su quell\'unità il piano rate «Serratura» ha già emesso la rata n. 1 con una spesa ad personam: la parte in più la toglierebbe dall\'unità '
        . 'dopo che quella rata l\'ha chiesta ai condòmini. Se la nota rettifica la fattura n. FT-313, ';

    b59sfNota($this, $s, $f313, 300.00, 'NC-L4B', '2026-06-10', $capitolo)->assertSessionHasErrors('fattura_rettificata_id');
    expect((string) session('errors')->first('fattura_rettificata_id'))->toBe('La nota toglie da «Int. 1» € 366,00, più di quanto la fattura n. FT-313 ha su '
        . 'quell\'unità (€ 122,00)' . $coda . 'riduci la riga su «Int. 1» a non più di € 122,00; se rettifica un\'altra fattura, collegala a quella.')
        ->and(b59sfGiornale($s))->toBe($prima)->and(b59sfSenzaPersona($s))->toBe(12200);

    b59sfNota($this, $s, $f313, 100.00, 'NC-L4B', '2026-06-10', $capitolo)->assertSessionHasNoErrors();
    expect(b59sfSenzaPersona($s))->toBe(0);

    b59sfNota($this, $s, $f313, 100.00, 'NC-L4C', '2026-06-10', $capitolo)->assertSessionHasErrors('fattura_rettificata_id');
    expect((string) session('errors')->first('fattura_rettificata_id'))->toBe('La nota toglie da «Int. 1» € 122,00, più di quanto, al netto delle altre note '
        . 'collegate, la fattura n. FT-313 ha su quell\'unità (€ 0,00)' . $coda . 'togli la riga da «Int. 1»; se rettifica un\'altra fattura, collegala a quella.');

    $nota = FatturaPassiva::where('condominio_id', $s['c']->id)->where('numero_documento', 'NC-L4B')->firstOrFail();
    $corpo = b59sfCorpoNota($s, $f313, 300.00, 'NC-L4B', '2026-06-10', $capitolo);
    unset($corpo['tipo_documento'], $corpo['stato_approvazione'], $corpo['fattura_rettificata_id']);
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s['c']->id, $nota->id]), $corpo)->assertSessionHasErrors('fattura_rettificata_id');
    expect((string) session('errors')->first('fattura_rettificata_id'))->toBe('La nota toglie da «Int. 1» € 366,00, più di quanto la fattura n. FT-313 ha su '
        . 'quell\'unità (€ 122,00)' . $coda . 'riduci la riga su «Int. 1» a non più di € 122,00; se rettifica un\'altra fattura, collegala a quella.')
        ->and(b59sfSenzaPersona($s))->toBe(0)->and((int) $nota->fresh()->totale_documento)->toBe(-12200);
});

// ─── Il piano preso nel conguaglio di un passaggio ────────────────────────────────────────────────

test('Coda 313 — dopo una vendita che ha preso il piano nel conguaglio, il piano non si elimina: il rifiuto dello storno e della nota rimanda ai passi del pulsante «Ricalcola», e quei passi portano allo storno', function () {
    // Rossi paga la rata 1, vende ad Anna dal 1° maggio (il passaggio prende il piano nel conguaglio: `PianoRate::conguagliato`), Anna paga la
    // rata 2. La strada «annulla le emissioni, riporta il piano in bozza ed eliminalo» si ferma all'eliminazione (decisione 38, `PianoRateController::destroy`):
    // la frase rimanda ai passi che il pulsante «Ricalcola» elenca sulla pagina del piano (`PianoRate::rimediDelFermo`). Percorsi: stornati i due
    // incassi, annullate le emissioni, il piano in bozza, annullato il passaggio, il piano eliminato; lo storno passa e gira tutto com'era (unità 0,
    // Rossi 0, Anna 0, «Anticipi» 0: l'anticipazione di FT-313, 122000, la chiude per intero).
    $s = b59apBase(tipoGestione: 'ordinaria');
    $rossi = $s['persone']['mario'];
    $anna = Anagrafica::forceCreate(['nome' => 'Anna Neri', 'email' => 'b59sf-anna2@test.it', 'indirizzo' => 'Via Po 2', 'codice_fiscale' => 'B59SFANNAYYYYYYY']);
    $anna->condomini()->syncWithoutDetaching([$s['c']->id]);
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    $incassoRossi = b59apIncassa($this, $s, $piano, $rossi, [1 => 61000]);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['1']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['righeTitolarita']['mario'], 'anagrafica_entrante_id' => $anna->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ])->assertSessionHasNoErrors();
    b59apEmetti($this, $s, $piano, [2], '2026-05-10')->assertSessionHas('message.type', 'success');
    $incassoAnna = b59apIncassa($this, $s, $piano, $anna, [2 => 61000], '2026-05-20');
    expect($piano->fresh()->conguagliato())->toBeTrue();
    $prima = b59sfGiornale($s);

    // Il rifiuto dello storno e quello della nota non promettono i passi che si fermano: rimandano al pulsante.
    $passiDelPulsante = 'riapri il piano con i passi che il pulsante «Ricalcola» elenca sulla sua pagina (un passaggio di titolarità tiene fermo il piano, '
        . 'e finché c\'è non si elimina), riportalo in bozza ed eliminalo';
    $motivo = b59sfStornoRifiutato($this, $s, $fattura);
    expect($motivo)->toContain('Il piano rate «Balcone interno 1» ha già emesso le rate n. 1 e 2 con la spesa ad personam della fattura n. FT-313 su «Int. 1»')
        ->and($motivo)->toContain(ucfirst($passiDelPulsante).', poi storna la fattura; le altre fatture del piano, se ce ne sono, tornano disponibili per un piano nuovo.')
        ->and($motivo)->not->toContain('annulla le emissioni, riporta il piano in bozza ed eliminalo');
    b59sfNota($this, $s, $fattura)->assertSessionHasErrors(['fattura_rettificata_id']);
    expect(session('errors')->first('fattura_rettificata_id'))->toContain(ucfirst($passiDelPulsante).', poi registra la nota; nel carrello del piano nuovo la fattura compare al netto della nota.')
        ->and(b59sfGiornale($s))->toBe($prima);
    // E la nota senza la fattura che rettifica: «se è una fattura del piano», prima i passi del pulsante.
    b59sfNota($this, $s, null, 500.00, 'NC-LIBERA')->assertSessionHasErrors(['fattura_rettificata_id']);
    expect(session('errors')->first('fattura_rettificata_id'))->toContain('registrala collegata a quella fattura; se è una fattura del piano rate «Balcone interno 1», prima '.$passiDelPulsante.'.')
        ->and(FatturaPassiva::where('numero_documento', 'NC-LIBERA')->exists())->toBeFalse()
        ->and(b59sfGiornale($s))->toBe($prima);

    // Il pulsante li elenca davvero (la prop `fermo_del_piano` della pagina del piano), e la strada di prima si ferma all'eliminazione: è il motivo della frase.
    expect($piano->fresh()->fermoPerLaPagina()['rimedi'])->toContain('annulla quel passaggio dallo storico della sua unità («Passaggi registrati», dall\'ultimo) e registralo di nuovo');
    foreach ([$incassoAnna, $incassoRossi] as $incasso) {
        $this->actingAs($this->user)->from('/incassi-rate')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]))->assertSessionHasNoErrors();
    }
    foreach ([2, 1] as $numero) {
        $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $piano->id, 'rata' => b59apRata($piano, $numero)->id]))
            ->assertSessionHas('message.type', 'success');
    }
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => false])->assertSessionHasNoErrors();
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $piano]))
        ->assertSessionHas('message.type', 'error');
    expect(session('message.message'))->toContain('Un passaggio di titolarità ha preso questo piano nel conguaglio')->and(PianoRate::find($piano->id))->not->toBeNull();

    // I passi del pulsante: annullato il passaggio, il piano si elimina e lo storno passa.
    $passaggio = \App\Models\Gestionale\Subentro::where('condominio_id', $s['c']->id)->latest('id')->firstOrFail();
    $this->actingAs($this->user)->from('/storico')->delete(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['1'], $passaggio]), ['nota_annullamento' => 'Rogito annullato'])
        ->assertSessionHas('message.type', 'success');
    $this->actingAs($this->user)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $piano]))->assertSessionHas('message.type', 'success');
    expect(PianoRate::find($piano->id))->toBeNull();
    b59sfStorna($this, $s, $fattura);
    expect(b59sfSenzaPersona($s))->toBe(0)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(0)
        ->and(b59apEstratto($this, $s, $anna))->toBe(0)
        ->and(b59sfAnticipi($s, $rossi))->toBe(0)
        ->and(b59sfAnticipi($s, $anna))->toBe(0);
});

test('Coda 313 — una fattura stornata con le versioni di prima e rimasta in un piano che un passaggio ha preso nel conguaglio: la rata 2 non si emette, e la frase rimanda ai passi del pulsante «Ricalcola»', function () {
    // Come l'emissione dopo lo storno di prima, con una vendita registrata dopo l'emissione della rata 1: il piano è preso nel conguaglio del passaggio
    // (`PianoRate::conguagliato`) e non si elimina finché il passaggio c'è (decisione 38). La rata 2 chiederebbe ancora 61000 di una spesa che lo storno
    // ha già tolto dall'unità: rifiuto, nessuna scrittura, e la strada non promette l'eliminazione.
    $s = b59apBase(tipoGestione: 'ordinaria');
    $rossi = $s['persone']['mario'];
    $anna = Anagrafica::forceCreate(['nome' => 'Anna Neri', 'email' => 'b59sf-anna3@test.it', 'indirizzo' => 'Via Po 2', 'codice_fiscale' => 'B59SFANNAZZZZZZZ']);
    $anna->condomini()->syncWithoutDetaching([$s['c']->id]);
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmettiComePrima($s, $piano, 1);
    b59apIncassa($this, $s, $piano, $rossi, [1 => 61000]);
    b59sfComeConLeVersioniDiPrima($piano, $fattura, fn () => b59sfStorna($this, $s, $fattura));
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['1']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['righeTitolarita']['mario'], 'anagrafica_entrante_id' => $anna->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ])->assertSessionHasNoErrors();
    expect($piano->fresh()->conguagliato())->toBeTrue();
    $prima = b59sfGiornale($s);

    b59apEmetti($this, $s, $piano, [2], '2026-06-01')->assertSessionHas('message.type', 'error')->assertSessionHas('message.message',
        'Il piano «Balcone interno 1» contiene la fattura n. FT-313, che è stata stornata: la rata n. 2 chiederebbe ancora ai condòmini la sua spesa ad '
        . 'personam su «Int. 1», che lo storno ha già tolto dall\'unità. Un piano con una fattura stornata non si ricalcola: riapri il piano con i passi che '
        . 'il pulsante «Ricalcola» elenca sulla sua pagina (un passaggio di titolarità tiene fermo il piano, e finché c\'è non si elimina), riportalo in '
        . 'bozza ed eliminalo, poi crealo di nuovo; nel carrello le fatture stornate non compaiono più. Nessuna rata di questa richiesta è stata emessa.');
    expect(b59sfGiornale($s))->toBe($prima)->and(b59apRata($piano, 2)->stato)->not->toBe('emessa');
});

// ─── Una fattura divisa in due piani ──────────────────────────────────────────────────────────────

/** FT-313 € 1.220,00 divisa in due piani da € 610,00, «Balcone A» e «Balcone B», una rata ciascuno, emessi: ogni emissione chiude 61000 (la 153.1), l'unità va a 0. */
function b59sfDuePiani($test, array $s, FatturaPassiva $fattura): array
{
    $a = b59apPiano($test, $s, $fattura, 1, '610,00', 'Balcone A');
    $b = b59apPiano($test, $s, $fattura, 1, '610,00', 'Balcone B');
    b59apEmetti($test, $s, $a, [1])->assertSessionHas('message.type', 'success');
    b59apEmetti($test, $s, $b, [1])->assertSessionHas('message.type', 'success');

    return [$a, $b];
}

test('Coda 313 — una fattura ad personam divisa in due piani, tutti e due emessi e incassati: lo storno si rifiuta nominando i due piani, e la strada per ciascuno porta allo storno', function () {
    // Ogni piano chiede € 610,00 di spesa ad personam sull'unità e li chiude all'emissione: l'unità 122000 − 2 × 61000 = 0. Rossi paga le due rate: 0.
    // Lo storno toglierebbe 122000 dall'unità una seconda volta: rifiuto che nomina tutti e due i piani e le loro rate, nessuna scrittura. La strada
    // per ciascuno (incassi, emissioni, bozza, eliminazione) porta l'unità a 122000, e lo storno la riporta a 0.
    $s = b59apBase();
    $rossi = $s['persone']['mario'];
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    [$a, $b] = b59sfDuePiani($this, $s, $fattura);
    $incassoA = b59apIncassa($this, $s, $a, $rossi, [1 => 61000]);
    $incassoB = b59apIncassa($this, $s, $b, $rossi, [1 => 61000]);
    expect(b59sfSenzaPersona($s))->toBe(0)->and(b59apEstratto($this, $s, $rossi))->toBe(0);
    $prima = b59sfGiornale($s);

    expect(b59sfStornoRifiutato($this, $s, $fattura))->toBe('I piani rate «Balcone A» (la rata n. 1) e «Balcone B» (la rata n. 1) hanno già emesso rate con la spesa ad personam '
        . 'della fattura n. FT-313 su «Int. 1»: lo storno la toglierebbe dall\'unità dopo che quelle rate l\'hanno chiesta ai condòmini. Per ciascuno di quei piani '
        . 'storna prima gli incassi registrati sulle sue rate, se ce ne sono, annulla le emissioni, riportalo in bozza ed eliminalo, poi storna la fattura; le altre '
        . 'fatture di quei piani, se ce ne sono, tornano disponibili per un piano nuovo.');
    expect(b59sfGiornale($s))->toBe($prima);

    b59sfViaIlPiano($this, $s, $a, [$incassoA]);
    expect(b59sfStornoRifiutato($this, $s, $fattura))->toContain('Il piano rate «Balcone B» ha già emesso la rata n. 1');
    b59sfViaIlPiano($this, $s, $b, [$incassoB]);
    expect(b59sfSenzaPersona($s))->toBe(122000);
    b59sfStorna($this, $s, $fattura);
    expect(b59sfSenzaPersona($s))->toBe(0)->and(b59apEstratto($this, $s, $rossi))->toBe(0)->and(b59sfAnticipi($s, $rossi))->toBe(0);
});

test('Coda 313 — una fattura ad personam divisa in due piani che una vendita ha preso nel conguaglio: lo storno si rifiuta e dice che i due piani non si eliminano finché il passaggio c\'è', function () {
    // Rossi paga le due rate 1 e vende ad Anna dal 1° maggio: il passaggio prende i due piani nel conguaglio (`PianoRate::conguagliato`). La strada di
    // prima si fermerebbe all'eliminazione (decisione 38): la frase dice di quali piani, e rimanda ai passi del pulsante «Ricalcola» di ognuno.
    $s = b59apBase(tipoGestione: 'ordinaria');
    $rossi = $s['persone']['mario'];
    $anna = Anagrafica::forceCreate(['nome' => 'Anna Neri', 'email' => 'b59sf-anna4@test.it', 'indirizzo' => 'Via Po 2', 'codice_fiscale' => 'B59SFANNAWWWWWWW']);
    $anna->condomini()->syncWithoutDetaching([$s['c']->id]);
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    [$a, $b] = b59sfDuePiani($this, $s, $fattura);
    b59apIncassa($this, $s, $a, $rossi, [1 => 61000]);
    b59apIncassa($this, $s, $b, $rossi, [1 => 61000]);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['1']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['righeTitolarita']['mario'], 'anagrafica_entrante_id' => $anna->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ])->assertSessionHasNoErrors();
    expect($a->fresh()->conguagliato())->toBeTrue()->and($b->fresh()->conguagliato())->toBeTrue();
    $prima = b59sfGiornale($s);

    expect(b59sfStornoRifiutato($this, $s, $fattura))->toContain('Per ciascuno di quei piani storna prima gli incassi registrati sulle sue rate, se ce ne sono, annulla le '
        . 'emissioni, riportalo in bozza ed eliminalo; i piani rate «Balcone A» e «Balcone B», tenuti fermi da un passaggio di titolarità, non si eliminano finché il '
        . 'passaggio c\'è: i passi per riaprirli sono sul pulsante «Ricalcola» della pagina di ciascuno, poi storna la fattura;');
    expect(b59sfGiornale($s))->toBe($prima);

    // E la nota senza la fattura che rettifica: se è una fattura di uno di quei piani, la stessa frase.
    b59sfNota($this, $s, null, 500.00, 'NC-LIBERA')->assertSessionHasErrors(['fattura_rettificata_id']);
    expect(session('errors')->first('fattura_rettificata_id'))->toContain('; se è una fattura di uno di quei piani, prima per quel piano storna gli incassi registrati sulle sue rate, se ce ne sono, '
        . 'annulla le emissioni, riportalo in bozza ed eliminalo; i piani rate «Balcone A» e «Balcone B», tenuti fermi da un passaggio di titolarità, non si eliminano finché il '
        . 'passaggio c\'è: i passi per riaprirli sono sul pulsante «Ricalcola» della pagina di ciascuno.')
        ->and(b59sfGiornale($s))->toBe($prima);
});

test('Coda 313 — la fattura in un piano emesso e non incassato che una vendita ha preso nel conguaglio: lo storno si rifiuta con i passi del pulsante «Ricalcola», e quei passi portano allo storno', function () {
    // Senza incassi la scala di sempre (`FatturaPassiva::viaDalPiano`) manda ad «annullare le emissioni, riportare il piano in bozza ed eliminarlo»; con
    // un passaggio che ha preso il piano nel conguaglio l'eliminazione si rifiuta (decisione 38). Annullata l'emissione della rata 1 e il passaggio, il
    // piano si elimina; lo storno gira tutto (l'unità 122000 − 122000 = 0, Rossi 0).
    $s = b59apBase(tipoGestione: 'ordinaria');
    $rossi = $s['persone']['mario'];
    $anna = Anagrafica::forceCreate(['nome' => 'Anna Neri', 'email' => 'b59sf-anna5@test.it', 'indirizzo' => 'Via Po 2', 'codice_fiscale' => 'B59SFANNAVVVVVVV']);
    $anna->condomini()->syncWithoutDetaching([$s['c']->id]);
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s)]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['1']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $s['righeTitolarita']['mario'], 'anagrafica_entrante_id' => $anna->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'atto notaio Verdi, rep. 12345',
        'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto, bozze a chi compra',
    ])->assertSessionHasNoErrors();
    expect($piano->fresh()->conguagliato())->toBeTrue()->and($piano->fresh()->haIncassiRegistrati())->toBeFalse();
    $prima = b59sfGiornale($s);

    expect(b59sfStornoRifiutato($this, $s, $fattura))->toBe('La fattura è nel piano rate «Balcone interno 1», che ha già rate emesse: dopo lo storno quelle rate la chiederebbero ancora. '
        . 'Riapri il piano con i passi che il pulsante «Ricalcola» elenca sulla sua pagina (un passaggio di titolarità tiene fermo il piano, e finché c\'è non si elimina), '
        . 'riportalo in bozza ed eliminalo, poi storna la fattura; le altre fatture del piano, se ce ne sono, tornano disponibili per un piano nuovo.');
    expect(b59sfGiornale($s))->toBe($prima);

    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', ['condominio' => $s['c']->id, 'pianoRate' => $piano->id, 'rata' => b59apRata($piano, 1)->id]))
        ->assertSessionHas('message.type', 'success');
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => false])->assertSessionHasNoErrors();
    $passaggio = \App\Models\Gestionale\Subentro::where('condominio_id', $s['c']->id)->latest('id')->firstOrFail();
    $this->actingAs($this->user)->from('/storico')->delete(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['1'], $passaggio]), ['nota_annullamento' => 'Rogito annullato'])
        ->assertSessionHas('message.type', 'success');
    $this->actingAs($this->user)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s['c'], $s['e'], $piano]))->assertSessionHas('message.type', 'success');
    b59sfStorna($this, $s, $fattura);
    expect(b59sfSenzaPersona($s))->toBe(0)->and(b59apEstratto($this, $s, $rossi))->toBe(0)->and(b59apEstratto($this, $s, $anna))->toBe(0);
});

test('Coda 313 — la nota collegata con le versioni di prima spostata da un\'unità a un\'altra dove una rata emessa ha chiesto la spesa: la modifica si rifiuta, e nomina la nuova unità', function () {
    // FT-313 con due righe ad personam da € 610,00 (500 + IVA 22 %): Interno 1 di Rossi e Interno 2 di Bice, piano in due rate da € 305,00 per unità, emesse
    // (ogni chiusura toglie 30500 dall'unità: Interno 1 61000 − 2 × 30500 = 0, Interno 2 0). La nota da € 122,00 (100 + IVA 22 %) collegata allora sulla riga di
    // Interno 1 (AVERE Interno 1 12200: − 12200). Spostata sulla riga di Interno 2 la modifica toglierebbe 12200 da un'unità dove prima non toglieva niente,
    // e le sue rate hanno chiesto la spesa: rifiuto, e le due unità restano a − 12200 e a 0. Mutazione: contare solo le unità che la nota toglieva già.
    $s = b59apBase();
    $fattura = b59apFattura($this, $s, [b59apRigaAdPersonam($s, 500.00, 22), b59apRigaAdPersonam($s, 500.00, 22, '2', 'Persiana')]);
    $piano = b59apPiano($this, $s, $fattura);
    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    b59apIncassa($this, $s, $piano, $s['persone']['bice'], [1 => 30500]);
    $capitolo = b59sfCapitolo($s);
    b59sfComeConLeVersioniDiPrima($piano, $fattura, fn () => b59sfNota($this, $s, $fattura, 100.00, 'NC-SPOSTA', '2026-06-10', $capitolo)->assertSessionHasNoErrors());
    $nota = FatturaPassiva::where('condominio_id', $s['c']->id)->where('numero_documento', 'NC-SPOSTA')->firstOrFail();
    expect([b59sfSenzaPersona($s, '1'), b59sfSenzaPersona($s, '2')])->toBe([-12200, 0]);
    $scrittura = b59apRighe(b59sfScritturaDi($nota));

    $corpo = b59sfCorpoNota($s, $fattura, 100.00, 'NC-SPOSTA', '2026-06-10', $capitolo);
    $corpo['righe'][0]['immobile_id'] = $s['unita']['2']->id;
    unset($corpo['tipo_documento'], $corpo['stato_approvazione'], $corpo['fattura_rettificata_id']);
    session()->forget(['message', 'errors']);
    $this->actingAs($this->user)->put(route('admin.gestionale.fatture.update', [$s['c']->id, $nota->id]), $corpo)->assertSessionHasErrors('fattura_rettificata_id');

    expect((string) session('errors')->first('fattura_rettificata_id'))->toContain('con la spesa ad personam della fattura n. FT-313 su «Int. 2»')
        ->and((string) session('errors')->first('fattura_rettificata_id'))->toContain('poi modifica la nota')
        ->and(b59apRighe(b59sfScritturaDi($nota)))->toBe($scrittura)
        ->and([b59sfSenzaPersona($s, '1'), b59sfSenzaPersona($s, '2')])->toBe([-12200, 0]);
});
