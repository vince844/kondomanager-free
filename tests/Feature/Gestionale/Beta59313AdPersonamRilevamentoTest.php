<?php

/**
 * Coda 313 (1.11.0-beta.59) — il rilevamento delle emissioni già scritte con la spesa ad personam addebitata due volte. Decisione
 * 153.1 e 153.6 del registro (`docs/subentro_e_competenza_temporale.md`): dei dati già scritti, **solo il rilevamento**; la scrittura
 * del riallineamento va alla 1.12.
 *
 * Che cosa fissano questi test (progetto della Coda 313):
 * - `RiallineaCreditiService::rileva` ha un elenco in più, per rata e unità: la cifra addebitata due volte, il piano, la data
 *   dell'emissione. La cifra è, per rata e unità, la parte ad personam delle quote a giornale meno le chiusure già scritte (le righe
 *   su «Crediti verso condomini» con l'unità e la rata, senza persona);
 * - la card «Crediti da riallineare» della pagina «Incassi rate» lo mostra (la prop `riallineamento_crediti`), il comando
 *   `kondomanager:riallinea-crediti` lo elenca, con `--dry-run` e senza;
 * - **`esegui` non scrive niente per questo elenco**, e la conferma della card non lo tocca: il giornale resta com'è;
 * - i piani senza il registro del riparto (generati prima della 1.11.0-beta.29, le stabili 1.9 e 1.10): l'elenco li mostra solo se
 *   la ricostruzione torna con le quote registrate; altrimenti li dice fra quelli che non si possono calcolare.
 *
 * I dati già scritti si costruiscono con l'emissione della .58 (`b59apEmettiComePrima`): le righe della persona e la spesa intera
 * su «Gestione rate». Le cifre in centesimi, rifatte a mano dalla regola nel commento di ogni test.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59313AdPersonamScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b59apAdmin();
});

/** La prop della card nella pagina «Incassi rate». */
function b59apCard($test, array $s): ?array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['riallineamento_crediti'];
}

/** Tutto ciò che il riallineamento potrebbe scrivere: scritture e righe del condominio, legami delle quote. */
function b59apImpronta(array $s): array
{
    return [
        'scritture' => DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->orderBy('id')->get(['id', 'stato', 'scrittura_padre_id'])->map(fn ($r) => (array) $r)->all(),
        'righe' => DB::table('righe_scritture')->join('scritture_contabili as sc', 'sc.id', '=', 'righe_scritture.scrittura_id')->where('sc.condominio_id', $s['c']->id)
            ->orderBy('righe_scritture.id')->get(['righe_scritture.id', 'righe_scritture.conto_contabile_id', 'righe_scritture.tipo_riga', 'righe_scritture.importo'])->map(fn ($r) => (array) $r)->all(),
        'quote' => DB::table('rate_quote')->orderBy('id')->pluck('scrittura_contabile_id', 'id')->all(),
    ];
}

/** Le voci dell'elenco nella forma che i test confrontano: [numero della rata, unità, cifra, data, esercizio aperto]. */
function b59apVoci(?array $card): array
{
    // Senza la card (niente da riallineare) l'elenco è vuoto; con la card la chiave `ad_personam` c'è sempre, anche vuota: se cambiasse nome, un test che
    // si aspetta l'elenco vuoto si romperebbe invece di restare verde senza guardare niente.
    return $card === null ? [] : collect($card['ad_personam'])->map(fn ($v) => [$v['numero_rata'], $v['unita'], $v['importo'], $v['data'], $v['esercizio_aperto']])->all();
}

/** La fattura da € 1.220,00 ad personam su Interno 1, in un piano di due rate dalla pagina, emesse come le scriveva la .58. */
function b59apGiaScritte($test): array
{
    $s = b59apBase();
    $piano = b59apPiano($test, $s, b59apFattura($test, $s, [b59apRigaAdPersonam($s)]));
    b59apEmettiComePrima($s, $piano, 1, '2026-04-01');
    b59apEmettiComePrima($s, $piano, 2, '2026-05-01');

    return [$s, $piano];
}

test('Coda 313 — la card «Crediti da riallineare» elenca le emissioni già scritte con la spesa ad personam due volte: per rata e unità, la cifra, il piano e la data', function () {
    // A mano: le due emissioni della .58 hanno DARE Rossi 61000 e AVERE «Gestione rate» 61000 ciascuna, nessuna chiusura: per la rata
    // 1 e per la rata 2, su Interno 1, la parte ad personam a giornale (61000) meno le chiusure (0) = 61000. «Crediti» dell'unità oggi
    // 244000 = il doppio della fattura. Prima della Coda 313 la card non compariva (niente da scrivere, niente che resta).
    [$s, $piano] = b59apGiaScritte($this);
    expect(b59apCreditiUnita($s))->toBe(244000);

    $card = b59apCard($this, $s);
    expect($card)->not->toBeNull()
        ->and(b59apVoci($card))->toBe([[1, 'Int. 1', 61000, '01/04/2026', true], [2, 'Int. 1', 61000, '01/05/2026', true]])
        ->and(collect($card['ad_personam'])->pluck('piano')->unique()->values()->all())->toBe(['Balcone interno 1'])
        ->and(collect($card['ad_personam'])->pluck('importo_formattato')->all())->toBe(['€ 610,00', '€ 610,00'])
        ->and($card['ad_personam_non_calcolabili'])->toBe([])
        // Niente da scrivere: la card è in sola lettura (il pulsante si conta sulle compensazioni e sulle rate da scrivere).
        ->and($card['compensazioni'])->toBe([])
        ->and(collect($card['rate'])->where('azione', 'scrivi')->all())->toBe([]);

    // L'incasso non cambia niente: il doppio è nell'emissione, non nel pagamento.
    b59apIncassa($this, $s, $piano, $s['persone']['mario'], [1 => 61000, 2 => 61000]);
    expect(b59apVoci(b59apCard($this, $s)))->toBe([[1, 'Int. 1', 61000, '01/04/2026', true], [2, 'Int. 1', 61000, '01/05/2026', true]]);
});

test('Coda 313 — la conferma della card e il comando non scrivono niente per l\'elenco della spesa ad personam, e il messaggio dice il vero', function () {
    // Decisione 153.1: la scrittura del riallineamento va alla 1.12. Il giornale resta identico (l'impronta) dopo la conferma e dopo il
    // comando senza `--dry-run`. Il messaggio della conferma non dice più «i crediti sono già a posto» (prima lo diceva, con l'unità a
    // € 2.440,00).
    [$s] = b59apGiaScritte($this);
    $prima = b59apImpronta($s);

    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))
        ->assertSessionHas('message', fn ($m) => ! str_contains($m['message'], 'già a posto') && str_contains($m['message'], 'spesa ad personam'));
    expect(b59apImpronta($s))->toBe($prima);

    $this->artisan('kondomanager:riallinea-crediti', ['--condominio' => $s['c']->id])
        ->expectsOutputToContain('Spesa ad personam addebitata due volte')
        ->expectsOutputToContain('rata 1 (Balcone interno 1), Int. 1, emessa il 01/04/2026: € 610,00')
        ->doesntExpectOutputToContain('niente da riallineare')
        ->assertExitCode(0);
    expect(b59apImpronta($s))->toBe($prima);

    $this->artisan('kondomanager:riallinea-crediti', ['--condominio' => $s['c']->id, '--dry-run' => true])
        ->expectsOutputToContain('rata 2 (Balcone interno 1), Int. 1, emessa il 01/05/2026: € 610,00')
        ->assertExitCode(0);
    expect(b59apImpronta($s))->toBe($prima);
});

test('Coda 313 — controllo: le emissioni scritte da questa versione chiudono l\'anticipazione e non compaiono nell\'elenco', function () {
    // Controllo (verde anche prima, perché prima l'elenco non c'era): la cifra è la parte ad personam meno le chiusure già scritte,
    // quindi un'emissione con la chiusura dà zero. Con la chiusura tolta a mano dalla rata 2 l'elenco la trova (61000).
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s)]));
    b59apEmetti($this, $s, $piano, [1, 2])->assertSessionHas('message.type', 'success');
    expect(b59apCard($this, $s))->toBeNull();

    $emissione = b59apEmissione($piano, 2);
    $chiusura = DB::table('righe_scritture')->where('scrittura_id', $emissione)->whereNull('anagrafica_id')->where('conto_contabile_id', b59apConto($s, 'crediti_condomini'))->first();
    DB::table('righe_scritture')->where('id', $chiusura->id)->update(['conto_contabile_id' => b59apConto($s, 'gestione_rate'), 'immobile_id' => null, 'rata_id' => null]);
    expect(b59apVoci(b59apCard($this, $s)))->toBe([[2, 'Int. 1', 61000, '01/04/2026', true]]);
});

test('Coda 313 — in una rata con una spesa comune e una ad personam l\'elenco dice la sola parte ad personam, sull\'unità che l\'ha', function () {
    // Fattura: € 100,01 ad personam su Interno 1 e € 100,00 comuni sulla tabella 500/500; tre rate. La parte ad personam di Rossi con
    // la fettina della quota: 3334, 3334, 3333 (come nel test dell'emissione); Interno 2 di Bice non ha parte ad personam.
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.01, 0), b59apRigaComune(100.00)]), numeroRate: 3);
    foreach ([1, 2, 3] as $n) {
        b59apEmettiComePrima($s, $piano, $n, '2026-04-01');
    }

    expect(b59apVoci(b59apCard($this, $s)))->toBe([[1, 'Int. 1', 3334, '01/04/2026', true], [2, 'Int. 1', 3334, '01/04/2026', true], [3, 'Int. 1', 3333, '01/04/2026', true]]);
});

test('Coda 313 — un\'emissione in un esercizio chiuso resta nell\'elenco, e la voce dice che l\'esercizio è chiuso', function () {
    // Niente si scrive comunque; la voce dice l'esercizio, perché è il risultato di gestione di quell'esercizio a essere più alto.
    [$s] = b59apGiaScritte($this);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    DB::table('esercizi')->insert(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'stato' => 'aperto', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'created_at' => now(), 'updated_at' => now()]);

    $card = b59apCard($this, $s);
    expect(b59apVoci($card))->toBe([[1, 'Int. 1', 61000, '01/04/2026', false], [2, 'Int. 1', 61000, '01/05/2026', false]])
        ->and(collect($card['ad_personam'])->pluck('esercizio')->unique()->values()->all())->toBe(['Esercizio 2026']);
});

// ─── I piani senza il registro del riparto (le stabili 1.9 e 1.10) ────────────────────────────────────────────────────────────

test('Coda 313 — un piano senza il registro del riparto: l\'elenco lo mostra se la ricostruzione torna con le quote registrate', function () {
    // Il piano generato prima della 1.11.0-beta.29: niente righe in `righe_riparto` e quote senza la chiave. La ricostruzione
    // (`DettaglioRiparto::perPiano`, con le titolarità di oggi) dà Rossi 122000 ad personam su Interno 1 = la somma delle sue quote
    // pure (61000 + 61000): torna. La cifra per rata è la fettina: 61000 e 61000.
    [$s, $piano] = b59apGiaScritte($this);
    b59apSenzaChiave($piano);
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();

    $card = b59apCard($this, $s);
    expect(b59apVoci($card))->toBe([[1, 'Int. 1', 61000, '01/04/2026', true], [2, 'Int. 1', 61000, '01/05/2026', true]])
        ->and($card['ad_personam_non_calcolabili'])->toBe([]);
});

test('Coda 313 — un piano senza il registro la cui ricostruzione non torna con le quote sta fra quelli che non si possono calcolare, con il piano e il perché', function () {
    // Dopo la generazione Interno 1 è passato a Luca Verdi (la titolarità di oggi): la ricostruzione dà la spesa a Verdi, le quote sono
    // di Rossi, l'invariante del registro non torna. Nessuna cifra: il piano si nomina fra quelli da controllare.
    [$s, $piano] = b59apGiaScritte($this);
    b59apSenzaChiave($piano);
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    $verdi = \App\Models\Anagrafica::forceCreate(['nome' => 'Luca Verdi', 'email' => 'b59ap-verdi@test.it', 'indirizzo' => 'Via Po 3', 'codice_fiscale' => 'B59APVERDIXXXXXX']);
    $verdi->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->where('id', $s['righeTitolarita']['mario'])->update(['anagrafica_id' => $verdi->id]);

    $card = b59apCard($this, $s);
    expect($card)->not->toBeNull()
        ->and(b59apVoci($card))->toBe([])
        ->and(collect($card['ad_personam_non_calcolabili'])->pluck('piano')->all())->toBe(['Balcone interno 1'])
        ->and($card['ad_personam_non_calcolabili'][0]['motivo'])->toContain('registro del riparto')->toContain('non torna');
});

test('Coda 313 — la conferma, per un piano da controllare a mano, dice il perché intero: senza il registro, e la ricostruzione non torna', function () {
    // Lo scenario del test qui sopra (Interno 1 passato a Verdi dopo la generazione). La frase della conferma diceva solo «senza il registro
    // del riparto la cifra non si calcola», ma senza il registro il piano si ricostruisce: non si calcola perché la ricostruzione non torna.
    [$s, $piano] = b59apGiaScritte($this);
    b59apSenzaChiave($piano);
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    $verdi = \App\Models\Anagrafica::forceCreate(['nome' => 'Luca Verdi', 'email' => 'b59ap-verdi3@test.it', 'indirizzo' => 'Via Po 3', 'codice_fiscale' => 'B59APVERDIZZZZZZ']);
    $verdi->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->where('id', $s['righeTitolarita']['mario'])->update(['anagrafica_id' => $verdi->id]);

    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))
        ->assertSessionHas('message', fn ($m) => str_contains($m['message'], 'Il piano «Balcone interno 1» va controllato a mano: non ha il registro del riparto, e la ricostruzione con i titolari di oggi non torna con le sue quote, quindi la cifra non si calcola.'));
});

test('Coda 313 (decisione 154.2) — un piano senza il registro emesso dopo l\'aggiornamento chiude l\'anticipazione con la ricostruzione controllata, e l\'elenco resta vuoto', function () {
    // Prima della decisione 154.2 era un limite dichiarato (l'emissione senza chiave e senza registro scriveva come prima, 244000). La
    // decisione 154.2 dà all'emissione la ricostruzione del rilevamento (`RataQuote::registroRicostruito`): torna (Rossi 122000 ad personam
    // = le sue quote pure, 61000 + 61000), quindi ogni emissione chiude la fettina, 61000. Interno 1: 122000 + 2 × 61000 − 2 × 61000 = 122000;
    // «Gestione rate» 0; niente da elencare.
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s)]));
    b59apSenzaChiave($piano);
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    b59apEmetti($this, $s, $piano, [1, 2], '2026-04-01')->assertSessionHas('message.type', 'success');

    expect(b59apCreditiUnita($s))->toBe(122000)
        ->and(b59apGestioneRate($s))->toBe(0)
        ->and(b59apRighe(b59apEmissione($piano, 1)))->toBe([
            ['crediti_condomini', 'avere', 61000, null, (int) $s['unita']['1']->id],
            ['crediti_condomini', 'dare', 61000, (int) $s['persone']['mario']->id, (int) $s['unita']['1']->id],
        ])
        ->and(b59apVoci(b59apCard($this, $s)))->toBe([]);
});

test('Coda 313 (decisione 154.2) — un piano senza il registro la cui ricostruzione non torna si emette come prima, e la card lo nomina fra quelli da controllare', function () {
    // Interno 1 passato a Luca Verdi dopo la generazione (la titolarità di oggi): la ricostruzione dà la spesa a Verdi, le quote sono di
    // Rossi, non torna. L'emissione resta quella di prima: DARE Rossi 61000 / AVERE «Gestione rate» 61000, Interno 1 a 122000 + 2 × 61000 =
    // 244000; la card nomina il piano, senza cifra.
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s)]));
    b59apSenzaChiave($piano);
    DB::table('righe_riparto')->where('piano_rate_id', $piano->id)->delete();
    $verdi = \App\Models\Anagrafica::forceCreate(['nome' => 'Luca Verdi', 'email' => 'b59ap-verdi2@test.it', 'indirizzo' => 'Via Po 3', 'codice_fiscale' => 'B59APVERDIYYYYYY']);
    $verdi->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->where('id', $s['righeTitolarita']['mario'])->update(['anagrafica_id' => $verdi->id]);
    b59apEmetti($this, $s, $piano, [1, 2], '2026-04-01')->assertSessionHas('message.type', 'success');

    $card = b59apCard($this, $s);
    expect(b59apCreditiUnita($s))->toBe(244000)
        ->and(b59apGestioneRate($s))->toBe(122000)
        ->and(b59apVoci($card))->toBe([])
        ->and(collect($card['ad_personam_non_calcolabili'])->pluck('piano')->all())->toBe(['Balcone interno 1']);
});

test('Coda 313 — la cifra si conta per unità: con due unità ad personam nella stessa rata, la chiusura di una non copre l\'altra', function () {
    // Fattura: € 610,00 ad personam su Interno 1 (Rossi) e € 300,00 su Interno 2 (Bice), una rata, emessa da questa versione: due
    // chiusure, 61000 e 30000. Tolta a mano quella di Interno 1, l'elenco dice Interno 1 per 61000 e non Interno 2.
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s, 610.00, 0), b59apRigaAdPersonam($s, 300.00, 0, '2', 'Riparazione persiana')]), numeroRate: 1);
    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    DB::table('righe_scritture')->where('scrittura_id', b59apEmissione($piano, 1))->whereNull('anagrafica_id')->where('immobile_id', $s['unita']['1']->id)
        ->update(['conto_contabile_id' => b59apConto($s, 'gestione_rate'), 'immobile_id' => null, 'rata_id' => null]);

    expect(b59apVoci(b59apCard($this, $s)))->toBe([[1, 'Int. 1', 61000, '01/04/2026', true]]);
});
