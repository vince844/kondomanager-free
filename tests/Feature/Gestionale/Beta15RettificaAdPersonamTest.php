<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 313 — la rettifica delle emissioni già scritte con la spesa ad personam addebitata due volte.
 *
 * Le emissioni fino alla 1.11.0-beta.58 (e quelle della 1.9 e della 1.10) hanno scritto la spesa di una sola unità due volte su «Crediti
 * verso condomini» (con la fattura, con l'unità; con l'emissione, a nome della persona) e l'hanno contata fra le entrate di «Gestione
 * rate». Il riallineamento dei crediti le elencava soltanto; ora, dopo l'anteprima e la conferma, scrive per ogni rata e unità la
 * rettifica: DARE «Gestione rate», AVERE «Crediti verso condomini» con l'unità e la rata, senza persona, con la nota della chiusura
 * dell'anticipazione. Il debito di nessun condòmino cambia (la rettifica non ha persona).
 *
 * Dove si scrive: nell'esercizio dell'emissione, alla sua data, se è aperto; se è chiuso, nell'esercizio aperto che contiene la data di
 * oggi, con la data di oggi e la frase che dice da quale esercizio viene; l'esercizio chiuso non si tocca. Senza un esercizio aperto alla
 * data di oggi la voce resta elencata e non si scrive.
 *
 * I dati già scritti si costruiscono con l'emissione della .58 (`b59apEmettiComePrima`). Cifre in centesimi. Prefisso `b15ra`.
 */

use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\RiallineaCreditiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59313AdPersonamScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->user = b59apAdmin();
});

/** La prop della card nella pagina «Incassi rate». */
function b15raCard($test, array $s): ?array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['riallineamento_crediti'];
}

/** Tutto ciò che il riallineamento potrebbe scrivere: scritture, righe e legami delle quote. */
function b15raImpronta(array $s): array
{
    return [
        'scritture' => DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->orderBy('id')->get(['id', 'esercizio_id', 'stato', 'scrittura_padre_id'])->map(fn ($r) => (array) $r)->all(),
        'righe' => DB::table('righe_scritture')->join('scritture_contabili as sc', 'sc.id', '=', 'righe_scritture.scrittura_id')->where('sc.condominio_id', $s['c']->id)
            ->orderBy('righe_scritture.id')->get(['righe_scritture.id', 'righe_scritture.conto_contabile_id', 'righe_scritture.tipo_riga', 'righe_scritture.importo'])->map(fn ($r) => (array) $r)->all(),
        'quote' => DB::table('rate_quote')->orderBy('id')->pluck('scrittura_contabile_id', 'id')->all(),
    ];
}

/** Le rettifiche scritte: le scritture con la sola riga di chiusura senza persona e la contropartita su «Gestione rate». */
function b15raRettifiche(array $s): \Illuminate\Support\Collection
{
    return DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('causale', 'like', RiallineaCreditiService::MARCATORE.' — chiusura%')
        ->orderBy('id')->get();
}

/** La fattura da € 1.220,00 ad personam su Interno 1, in un piano di due rate, emesse come le scriveva la .58. */
function b15raGiaScritte($test): array
{
    $s = b59apBase();
    $piano = b59apPiano($test, $s, b59apFattura($test, $s, [b59apRigaAdPersonam($s)]));
    b59apEmettiComePrima($s, $piano, 1, '2026-04-01');
    b59apEmettiComePrima($s, $piano, 2, '2026-05-01');

    return [$s, $piano];
}

test('Coda 313 — anteprima, conferma e seconda conferma: la rettifica chiude l\'anticipazione nell\'esercizio dell\'emissione, una volta sola, e il debito di Rossi non cambia', function () {
    // A mano: le due emissioni della .58 hanno DARE Rossi 61000 e AVERE «Gestione rate» 61000 ciascuna, nessuna chiusura. «Crediti» di
    // Interno 1 = 122000 (la fattura) + 2 × 61000 = 244000, «Gestione rate» 122000, Rossi deve 122000. Le rettifiche: per la rata 1 e per
    // la rata 2, DARE «Gestione rate» 61000 / AVERE Interno 1 61000, senza persona, alla data dell'emissione (01/04 e 01/05), nell'esercizio
    // 2026 (aperto). Dopo: Interno 1 = 122000 (quanto deve Rossi), «Gestione rate» 0, Rossi deve ancora 122000. La seconda conferma non
    // scrive niente: le chiusure già scritte si sottraggono.
    [$s, $piano] = b15raGiaScritte($this);
    $rossi = $s['persone']['mario'];
    expect(b59apCreditiUnita($s))->toBe(244000)->and(b59apGestioneRate($s))->toBe(122000)->and(b59apEstratto($this, $s, $rossi))->toBe(122000);

    // L'anteprima: la card dice la cifra e l'unità, e il pulsante c'è (la rettifica è una scrittura da fare).
    $card = b15raCard($this, $s);
    expect(collect($card['ad_personam'])->map(fn ($v) => [$v['numero_rata'], $v['unita'], $v['importo'], $v['azione'], $v['nell_esercizio'], $v['data_rettifica']])->all())
        ->toBe([[1, 'Int. 1', 61000, 'scrivi', 'Esercizio 2026', '01/04/2026'], [2, 'Int. 1', 61000, 'scrivi', 'Esercizio 2026', '01/05/2026']])
        ->and(app(RiallineaCreditiService::class)->daScrivere(app(RiallineaCreditiService::class)->rileva($s['c'])))->toBe(2);

    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))
        ->assertSessionHas('message.type', 'success')
        ->assertSessionHas('message', fn ($m) => str_contains($m['message'], '2 chiusure dell\'anticipazione ad personam'));

    $rettifiche = b15raRettifiche($s);
    $u1 = (int) $s['unita']['1']->id;
    expect($rettifiche)->toHaveCount(2)
        ->and($rettifiche->pluck('esercizio_id')->map(fn ($id) => (int) $id)->unique()->values()->all())->toBe([(int) $s['e']->id])
        ->and($rettifiche->map(fn ($r) => substr((string) $r->data_competenza, 0, 10))->all())->toBe(['2026-04-01', '2026-05-01'])
        ->and($rettifiche->pluck('scrittura_padre_id')->map(fn ($id) => (int) $id)->all())->toBe([b59apEmissione($piano, 1), b59apEmissione($piano, 2)])
        ->and(b59apRighe((int) $rettifiche[0]->id))->toBe([
            ['crediti_condomini', 'avere', 61000, null, $u1],
            ['gestione_rate', 'dare', 61000, null, null],
        ])
        ->and(DB::table('righe_scritture')->where('scrittura_id', $rettifiche[0]->id)->whereNull('anagrafica_id')->where('immobile_id', $u1)->value('rata_id'))->toEqual(b59apRata($piano, 1)->id)
        ->and(DB::table('righe_scritture')->where('scrittura_id', $rettifiche[0]->id)->where('immobile_id', $u1)->value('note'))->toBe(RataQuote::NOTA_CHIUSURA_ANTICIPAZIONE.b59apRata($piano, 1)->descrizione)
        ->and(b59apCreditiUnita($s))->toBe(122000)
        ->and(b59apGestioneRate($s))->toBe(0)
        ->and(b59apEstratto($this, $s, $rossi))->toBe(122000)
        ->and(b15raCard($this, $s))->toBeNull();

    // Idempotente: la seconda conferma, e il comando, non scrivono niente.
    $dopo = b15raImpronta($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertSessionHas('message.type', 'info');
    $this->artisan('kondomanager:riallinea-crediti', ['--condominio' => $s['c']->id])->expectsOutputToContain('niente da riallineare')->assertExitCode(0);
    expect(b15raImpronta($s))->toBe($dopo);

    // La catena fino allo stato patrimoniale: incassate le due rate, crediti a zero, «Gestione rate» e il risultato senza l'avanzo di
    // 122000 che non esisteva.
    b59apIncassa($this, $s, $piano, $rossi, [1 => 61000, 2 => 61000]);
    expect(b59apStatoPatrimoniale($this, $s))->toBe(['crediti' => 0, 'quote_emesse' => 0, 'risultato' => 0])
        ->and(b59apEstratto($this, $s, $rossi))->toBe(0);
});

test('Coda 313 — il comando con --dry-run elenca la rettifica e non scrive; senza --dry-run scrive come la card', function () {
    [$s] = b15raGiaScritte($this);
    $prima = b15raImpronta($s);

    $this->artisan('kondomanager:riallinea-crediti', ['--condominio' => $s['c']->id, '--dry-run' => true])
        ->expectsOutputToContain('chiusura dell\'anticipazione ad personam della rata 1 (Balcone interno 1), Int. 1, emessa il 01/04/2026: € 610,00')
        ->expectsOutputToContain('Prova: nessuna scrittura creata.')
        ->assertExitCode(0);
    expect(b15raImpronta($s))->toBe($prima)->and(b15raRettifiche($s))->toHaveCount(0);

    $this->artisan('kondomanager:riallinea-crediti', ['--condominio' => $s['c']->id])
        ->expectsOutputToContain('scritte 2 chiusure dell\'anticipazione ad personam')
        ->assertExitCode(0);
    expect(b15raRettifiche($s))->toHaveCount(2)->and(b59apCreditiUnita($s))->toBe(122000)->and(b59apGestioneRate($s))->toBe(0);
});

test('Coda 313 — l\'emissione in un esercizio chiuso: la rettifica va nell\'esercizio aperto, con la data di oggi e la frase dell\'esercizio da cui viene; l\'esercizio chiuso non si tocca', function () {
    // L'esercizio 2026 delle emissioni si chiude, il 2027 è aperto, oggi è il 15/02/2027. A mano: due rettifiche da 61000, nell'esercizio
    // 2027, con data 15/02/2027; nessuna scrittura né riga nuova nell'esercizio 2026; «Crediti» di Interno 1 da 244000 a 122000.
    [$s, $piano] = b15raGiaScritte($this);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $nuovo = (int) DB::table('esercizi')->insertGetId(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'stato' => 'aperto', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'created_at' => now(), 'updated_at' => now()]);
    legaAEsercizio(\App\Models\Esercizio::findOrFail($nuovo), (int) $s['g']->id);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2027-02-15 10:00:00', 'Europe/Rome'));

    $delChiuso = fn () => [
        DB::table('scritture_contabili')->where('esercizio_id', $s['e']->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        DB::table('righe_scritture')->whereIn('scrittura_id', DB::table('scritture_contabili')->where('esercizio_id', $s['e']->id)->pluck('id'))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
    ];
    $primaDelChiuso = $delChiuso();

    $card = b15raCard($this, $s);
    expect(collect($card['ad_personam'])->map(fn ($v) => [$v['numero_rata'], $v['esercizio_aperto'], $v['azione'], $v['nell_esercizio'], $v['data_rettifica']])->all())
        ->toBe([[1, false, 'scrivi', 'Esercizio 2027', '15/02/2027'], [2, false, 'scrivi', 'Esercizio 2027', '15/02/2027']]);

    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertSessionHas('message.type', 'success');
    $rettifiche = b15raRettifiche($s);
    expect($rettifiche)->toHaveCount(2)
        ->and($rettifiche->pluck('esercizio_id')->map(fn ($id) => (int) $id)->unique()->values()->all())->toBe([$nuovo])
        ->and($rettifiche->map(fn ($r) => substr((string) $r->data_competenza, 0, 10))->unique()->values()->all())->toBe(['2027-02-15'])
        ->and((string) $rettifiche[0]->note)->toContain('«Esercizio 2026»')
        ->and((string) $rettifiche[0]->note)->toContain('chiuso')
        ->and($delChiuso())->toBe($primaDelChiuso)
        ->and(b59apCreditiUnita($s))->toBe(122000)
        ->and(b59apEstratto($this, $s, $s['persone']['mario']))->toBe(122000);

    // Idempotente anche qui.
    $dopo = b15raImpronta($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]));
    expect(b15raImpronta($s))->toBe($dopo);
});

test('Coda 313 — l\'emissione in un esercizio chiuso senza un esercizio aperto alla data di oggi: la voce resta elencata, con il perché, e non si scrive niente', function () {
    [$s] = b15raGiaScritte($this);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $prima = b15raImpronta($s);

    $card = b15raCard($this, $s);
    expect(collect($card['ad_personam'])->pluck('azione')->unique()->values()->all())->toBe(['resta'])
        ->and(app(RiallineaCreditiService::class)->daScrivere(app(RiallineaCreditiService::class)->rileva($s['c'])))->toBe(0);

    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))
        ->assertSessionHas('message', fn ($m) => str_contains($m['message'], 'nessun esercizio aperto'));
    expect(b15raImpronta($s))->toBe($prima);
});

test('Coda 313 — l\'annullamento dell\'emissione porta via la sua rettifica, e la nuova emissione chiude l\'anticipazione una volta sola', function () {
    // Dopo la rettifica della rata 2, annullata la sua emissione: se ne vanno l'emissione e la rettifica (figlia); «Crediti» di Interno 1
    // = 122000 + 61000 (rata 1) − 61000 (la sua rettifica) = 122000. Riemessa con la rotta di oggi: DARE Rossi 61000, AVERE Interno 1
    // 61000, di nuovo 122000, e nessuna voce da riallineare.
    [$s, $piano] = b15raGiaScritte($this);
    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]))->assertSessionHas('message.type', 'success');

    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $piano, b59apRata($piano, 2)]))->assertSessionHasNoErrors();
    expect(b15raRettifiche($s))->toHaveCount(1)->and(b59apCreditiUnita($s))->toBe(122000);

    b59apEmetti($this, $s, $piano, [2], '2026-05-01')->assertSessionHas('message.type', 'success');
    expect(b59apCreditiUnita($s))->toBe(122000)->and(b59apGestioneRate($s))->toBe(0)->and(b15raCard($this, $s))->toBeNull();
});
