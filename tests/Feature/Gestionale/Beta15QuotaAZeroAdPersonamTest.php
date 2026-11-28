<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 426 — la quota a zero con una parte ad personam chiude l'anticipazione della sua unità.
 *
 * Il caso: Interno 1 ha una spesa ad personam (la fattura l'ha scritta DARE «Crediti verso condomini» con l'unità, senza persona) e una
 * rettifica comune negativa che porta la quota della rata a € 0,00. La quota non ha righe a nome della persona, ma la sua parte ad
 * personam va chiusa lo stesso: AVERE «Crediti verso condomini» con l'unità e la rata, e la contropartita DARE su «Gestione rate» (la
 * rettifica comune che la quota porta). Fino a questa versione la quota a zero si saltava: la rata andava a giornale per gli altri (nessuno
 * «Sbilancio»: `RataQuote::scritturaDiEmissione` le dava `anticipazione` 0), oppure, con sole quote a zero, non andava a giornale per niente;
 * in tutti e due i casi l'anticipazione dell'unità restava aperta per sempre.
 *
 * Gli scrittori dell'emissione sono tre (l'emissione, il riallineamento dei crediti, lo storno di un incasso con la quota rimasta fuori),
 * più l'addebito a una persona che usa la stessa scrittura: qui l'emissione e il riallineamento; lo storno passa solo per le quote a
 * credito (`importo < 0`) a cui ha restituito credito, che hanno sempre righe, e lo copre `Beta59313AdPersonamEmissioneTest`.
 *
 * Le cifre in centesimi, rifatte a mano nel commento di ogni test. Prefisso `b15qz`.
 */

use App\Models\Gestionale\RataQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59313AdPersonamScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b59apAdmin();
});

test('Coda 426 — una rata con la sola quota a zero e la parte ad personam va a giornale e chiude l\'anticipazione', function () {
    // Fattura: € 100,00 ad personam su Interno 1 (Rossi), − € 200,00 comuni sulla tabella 500/500, € 300,00 ad personam su Interno 2
    // (Bice); una rata. Un piano con un totale a zero il modulo non lo accetta: la rata con la sola quota di Rossi si ottiene togliendo
    // dalla rata la quota di Bice dopo la generazione. A mano: Rossi 10000 − 10000 = 0, parte ad personam 10000. L'emissione: AVERE
    // Interno 1 10000 (la chiusura, senza persona), DARE «Gestione rate» 10000 (la rettifica comune). Interno 1 a zero. Prima: la rata
    // «non ha quote da pagare», nessuna scrittura, Interno 1 a 10000.
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.00, 0), b59apRigaComune(-200.00, 0, 'Rettifica'), b59apRigaAdPersonam($s, 300.00, 0, '2', 'Persiana')]), numeroRate: 1);
    DB::table('rate_quote')->where('rata_id', b59apRata($piano, 1)->id)->where('anagrafica_id', $s['persone']['bice']->id)->delete();
    expect(RataQuote::where('rata_id', b59apRata($piano, 1)->id)->pluck('importo')->map(fn ($i) => (int) $i)->all())->toBe([0]);

    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    $emissione = b59apEmissione($piano, 1);
    expect($emissione)->toBeGreaterThan(0)
        ->and(b59apRighe($emissione))->toBe([
            ['crediti_condomini', 'avere', 10000, null, (int) $s['unita']['1']->id],
            ['gestione_rate', 'dare', 10000, null, null],
        ])
        ->and((int) b59apQuota($piano, 1, $s['persone']['mario'])->scrittura_contabile_id)->toBe($emissione)
        ->and(b59apCreditiUnita($s, '1'))->toBe(0)
        ->and(b59apGestioneRate($s))->toBe(-10000)
        ->and(DB::table('righe_scritture')->where('scrittura_id', $emissione)->where('note', RataQuote::NOTA_CHIUSURA_ANTICIPAZIONE.b59apRata($piano, 1)->descrizione)->count())->toBe(1);
});

test('Coda 426 — la quota a zero con altre quote nella stessa rata: l\'emissione riesce, prende la scrittura e chiude l\'anticipazione; dopo l\'incasso lo stato patrimoniale è a zero', function () {
    // Fattura: € 100,00 ad personam su Interno 1 (Rossi), − € 200,00 comuni sulla tabella 500/500, € 300,00 ad personam su Interno 2
    // (Bice); una rata. A mano: Rossi 10000 − 10000 = 0 (parte ad personam 10000); Bice 30000 − 10000 = 20000 (parte ad personam
    // 30000). L'emissione: DARE Bice 20000, AVERE Interno 2 30000, AVERE Interno 1 10000, DARE «Gestione rate» 10000 + 10000 = 20000.
    // Interno 1 a 0, Interno 2 a 20000 (il debito di Bice); dopo il suo incasso «Crediti verso condomini» è a zero. Prima: la quota di
    // Rossi senza scrittura, Interno 1 a 10000 per sempre, «Gestione rate» a − 10000.
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.00, 0), b59apRigaComune(-200.00, 0, 'Rettifica'), b59apRigaAdPersonam($s, 300.00, 0, '2', 'Persiana')]), numeroRate: 1);
    expect((int) b59apQuota($piano, 1, $s['persone']['mario'])->importo)->toBe(0);

    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    $emissione = b59apEmissione($piano, 1);
    expect(b59apRighe($emissione))->toBe([
        ['crediti_condomini', 'avere', 10000, null, (int) $s['unita']['1']->id],
        ['crediti_condomini', 'avere', 30000, null, (int) $s['unita']['2']->id],
        ['crediti_condomini', 'dare', 20000, (int) $s['persone']['bice']->id, (int) $s['unita']['2']->id],
        ['gestione_rate', 'dare', 20000, null, null],
    ])
        ->and((int) b59apQuota($piano, 1, $s['persone']['mario'])->scrittura_contabile_id)->toBe($emissione)
        ->and(b59apCreditiUnita($s, '1'))->toBe(0)
        ->and(b59apCreditiUnita($s, '2'))->toBe(20000)
        ->and(b59apGestioneRate($s))->toBe(-20000)
        // Niente da riallineare: la card non compare.
        ->and($this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['riallineamento_crediti'])->toBeNull();

    // La catena fino allo stato patrimoniale: incassata la quota di Bice, nessun credito verso condomini resta aperto, e il debito di Rossi
    // è zero (la sua quota non chiede niente).
    b59apIncassa($this, $s, $piano, $s['persone']['bice'], [1 => 20000]);
    expect(b59apStatoPatrimoniale($this, $s)['crediti'])->toBe(0)
        ->and(b59apCreditiUnita($s, '2'))->toBe(0)
        ->and(b59apEstratto($this, $s, $s['persone']['mario']))->toBe(0);
});

test('Coda 426 — l\'annullamento dell\'emissione porta via la chiusura della quota a zero, e la nuova emissione la scrive una volta sola', function () {
    // Stesse cifre del test di sopra. Annullata l'emissione la quota di Rossi torna senza scrittura e Interno 1 a 10000 (la fattura);
    // riemessa, di nuovo una sola chiusura da 10000.
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.00, 0), b59apRigaComune(-200.00, 0, 'Rettifica'), b59apRigaAdPersonam($s, 300.00, 0, '2', 'Persiana')]), numeroRate: 1);
    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');

    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $piano, b59apRata($piano, 1)]))->assertSessionHasNoErrors();
    expect(b59apQuota($piano, 1, $s['persone']['mario'])->scrittura_contabile_id)->toBeNull()
        ->and(b59apCreditiUnita($s, '1'))->toBe(10000);

    b59apEmetti($this, $s, $piano, [1])->assertSessionHas('message.type', 'success');
    expect(b59apCreditiUnita($s, '1'))->toBe(0)->and(b59apGestioneRate($s))->toBe(-20000);
});

test('Coda 426 — il riallineamento dei crediti porta a giornale la quota a zero rimasta fuori da un\'emissione di prima, con la chiusura', function () {
    // L'emissione della 1.10 (`b59apEmettiComeLa110`) salta le quote ≤ 0: Rossi resta fuori. A mano, la quota di Rossi come la
    // scrive oggi l'emissione: AVERE Interno 1 10000 (senza persona), DARE «Gestione rate» 10000; nessuna riga a nome di Rossi. Interno 1
    // torna a 0. Prima: il riallineamento non la vedeva (nessuna riga da scrivere) e Interno 1 restava a 10000.
    $s = b59apBase();
    $piano = b59apPiano($this, $s, b59apFattura($this, $s, [b59apRigaAdPersonam($s, 100.00, 0), b59apRigaComune(-200.00, 0, 'Rettifica'), b59apRigaAdPersonam($s, 300.00, 0, '2', 'Persiana')]), numeroRate: 1);
    b59apEmettiComeLa110($s, $piano, 1);
    $rossi = b59apQuota($piano, 1, $s['persone']['mario']);
    expect($rossi->scrittura_contabile_id)->toBeNull()->and(b59apCreditiUnita($s, '1'))->toBe(10000);

    $card = $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['riallineamento_crediti'];
    $voce = collect($card['rate'] ?? [])->firstWhere('rata_id', b59apRata($piano, 1)->id);
    expect($voce)->not->toBeNull()->and($voce['azione'])->toBe('scrivi')
        ->and($voce['persone'])->toBe([['nome' => 'Mario Rossi', 'spesa' => 0, 'credito' => 0, 'ad_personam' => 10000]]);

    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertSessionHas('message.type', 'success');
    $scrittura = (int) $rossi->fresh()->scrittura_contabile_id;
    expect($scrittura)->toBeGreaterThan(0)
        ->and(b59apRighe($scrittura))->toBe([
            ['crediti_condomini', 'avere', 10000, null, (int) $s['unita']['1']->id],
            ['gestione_rate', 'dare', 10000, null, null],
        ])
        ->and(b59apCreditiUnita($s, '1'))->toBe(0);
});
