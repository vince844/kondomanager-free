<?php

/**
 * Dove va il denaro di un interesse addebitato a una persona: al condominio, sulla voce scelta, quando è versato. Il caso è quello di Via Roma
 * (`RendicontoViaRomaScenario.php`), con € 0,92 di interessi di mora addebitati a Bice il 20/12/2026 sulla voce di entrata «Interessi di mora».
 *
 * - All'addebito: DARE «Crediti verso condomini» con Bice, l'unità e la rata, AVERE «Accessori da incassare». Le spese, le quote dei piani, il raccordo
 *   delle quote, i costi e il risultato non cambiano: la frase del frontespizio resta «entrate da terzi € 240,00».
 * - Al versamento (Bice versa € 0,92 il 28/12): DARE «Accessori da incassare», AVERE il mastro della voce, con la voce. La voce ha € 0,92 di «versato
 *   dai condòmini per interessi e spese», fuori dalle entrate da terzi; i costi scendono di € 0,92 e il risultato sale; le spese divise fra le persone
 *   scendono di € 0,92, € 0,46 a testa (500 e 500 millesimi).
 * - Lo storno del versamento (tornato indietro il 30/12) rovescia anche la coppia: tutto come all'addebito.
 */

use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\Accessori\AccessoriDaIncassare;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\ConsuntivoPerPersona;
use App\Services\Gestionale\ControlliDelRendiconto;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use App\Services\Gestionale\SintesiDelRendiconto;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/RendicontoViaRomaScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
});

/** Le cifre del rendiconto di Via Roma che un interesse può toccare. */
function avtCifre(array $s): array
{
    $r = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']->fresh());
    $letto = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $c = app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $s['e']->fresh());

    return [
        'frase' => SintesiDelRendiconto::speseLegate($r),
        'costi' => $r['risultato']['costi'], 'risultato' => $r['risultato']['risultato'], 'capitoli_netti' => $r['riconciliazione']['capitoli_netti'],
        'differenza' => $r['riconciliazione']['differenza'], 'quote_piani' => $r['raccordo_quote']['quote_piani'], 'altre_differenze' => $r['raccordo_quote']['altre_differenze'],
        'righe_quote' => array_column($r['blocchi'][0]['quote'], 'riga'), 'spese' => $r['blocchi'][0]['totale_spese']['consuntivo'],
        'incassato' => $r['blocchi'][0]['totale_entrate']['incassato'], 'versato_accessori' => $r['blocchi'][0]['versato_accessori'],
        'diviso' => ConguaglioDelRendiconto::totaliDelCondominio($letto)['a'], 'da_pagare' => $r['totali']['da_pagare'],
        'controlli' => collect($c['controlli'])->mapWithKeys(fn ($k) => [$k['id'] => $k['esito']])->all(),
    ];
}

it('l\'interesse addebitato non cambia le spese di nessuno; versato va alla voce come «versato dai condòmini per interessi e spese»; lo storno lo rovescia', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    $voce = be52Voce($this, $s, $s['pc'], 'Interessi di mora', 'entrata', '0,00');
    $prima = avtCifre($s);
    expect($prima['frase'])->toContain('entrate da terzi € 240,00')->and($prima['versato_accessori'])->toBe([]);

    $rata = app(AddebitiIndividuali::class)->registra($s['c'], $s['g'], $s['e'], [[
        'anagrafica_id' => $s['persone']['bice']->id, 'immobile_id' => $s['unita']['C']->id, 'importo' => 92, 'causale' => 'Interessi di mora al 20/12/2026',
        'scadenza' => '2026-12-20', 'natura' => 'accessorio', 'tipo_accessorio' => 'interessi', 'base' => 'legge', 'riferimento' => 'artt. 1224 e 1284 c.c.', 'conto_id' => $voce->id,
    ]], $this->user->id, '2026-12-20')[0];
    $quota = RataQuote::where('rata_id', $rata->id)->firstOrFail();

    // L'emissione: il credito e la sua contropartita, niente sulla «Gestione rate».
    $emissione = DB::table('righe_scritture as r')->join('conti_contabili as c', 'c.id', '=', 'r.conto_contabile_id')->where('r.scrittura_id', $quota->scrittura_contabile_id)
        ->orderBy('r.id')->get(['c.ruolo', 'r.tipo_riga', 'r.importo'])->map(fn ($r) => [$r->ruolo, $r->tipo_riga, (int) $r->importo])->all();
    expect($emissione)->toBe([['crediti_condomini', 'dare', 92], [AccessoriDaIncassare::RUOLO, 'avere', 92]]);

    // Le cifre dopo l'addebito sono quelle di prima: nessuna spesa scende per un interesse che non è versato.
    expect(avtCifre($s))->toBe($prima);

    // Bice versa € 0,92 il 28/12.
    vrmGiorno($this, '2026-12-28');
    $incasso = vrmIncassa($this, $s, 'bice', [$quota->id => 92], '2026-12-28');
    $coppia = DB::table('righe_scritture as r')->join('conti_contabili as c', 'c.id', '=', 'r.conto_contabile_id')->where('r.scrittura_id', $incasso)
        ->whereNotIn('c.ruolo', ['crediti_condomini', 'cassa', 'banca'])->orderBy('r.id')->get(['c.ruolo', 'r.tipo_riga', 'r.importo', 'r.voce_spesa_id'])
        ->map(fn ($r) => [$r->ruolo, $r->tipo_riga, (int) $r->importo, $r->voce_spesa_id === null ? null : (int) $r->voce_spesa_id])->all();
    // Il mastro della voce è quello che il piano dei conti le ha dato («Costi per servizi»), con la voce.
    $mastro = DB::table('conti_contabili')->where('id', $voce->conto_contabile_id)->value('ruolo');
    expect($coppia)->toBe([[AccessoriDaIncassare::RUOLO, 'dare', 92, null], [$mastro, 'avere', 92, $voce->id]]);

    $dopo = avtCifre($s);
    // 4.131,00 di costi meno 0,92; il risultato sale di 0,92; le spese per capitolo meno le entrate scendono di 0,92 e le spese divise fra le persone
    // anche (0,46 a testa); le spese dei capitoli e l'incassato da terzi non cambiano.
    expect($dopo['costi'])->toBe($prima['costi'] - 92)->and($dopo['risultato'])->toBe($prima['risultato'] + 92)
        ->and($dopo['capitoli_netti'])->toBe($prima['capitoli_netti'] - 92)->and($dopo['differenza'])->toBe($prima['differenza'])
        ->and($dopo['spese'])->toBe($prima['spese'])->and($dopo['incassato'])->toBe(24000)
        ->and($dopo['versato_accessori'])->toBe([['voce_id' => $voce->id, 'nome' => 'Interessi di mora', 'importo' => 92]])
        ->and($dopo['diviso'])->toBe($prima['diviso'] - 92)
        ->and($dopo['quote_piani'])->toBe($prima['quote_piani'])->and($dopo['altre_differenze'])->toBe($prima['altre_differenze'])
        ->and($dopo['controlli'])->toBe($prima['controlli'])->and($dopo['da_pagare'])->toBe($prima['da_pagare'])
        ->and(app(SpesaPerVoceService::class)->perTipo(app(SpesaPerVoceService::class)->perEsercizio($s['e']), $s['e'])['accessori'])->toBe([$voce->id => 92])
        ->and($dopo['frase'])->toContain('− entrate da terzi € 240,00 − interessi e spese versati dai condòmini € 0,92 = ');

    $persone = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh())['persone'][$s['g']->id];
    $suVoce = fn (string $chi, string $u) => (int) ($persone[$s['persone'][$chi]->id][$s['unita'][$u]->id]['voci'][$voce->id] ?? 0);
    expect([$suVoce('ugo', 'A'), $suVoce('bice', 'C')])->toBe([-46, -46]);

    // Lo storno del versamento rovescia anche la coppia.
    vrmGiorno($this, '2026-12-30');
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $incasso]), stornoVersamentoTornato())->assertSessionHasNoErrors();
    expect(avtCifre($s))->toBe($prima);
});

it('il versamento oltre l\'accessorio resta un credito sulla sua rata: alla voce va solo la parte che lo copre', function () {
    $s = vrmScenario($this);
    vrmGiorno($this, '2026-12-20');
    $voce = be52Voce($this, $s, $s['pc'], 'Interessi di mora', 'entrata', '0,00');
    $rata = app(AddebitiIndividuali::class)->registra($s['c'], $s['g'], $s['e'], [[
        'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['unita']['A']->id, 'importo' => 500, 'causale' => 'Spese postali della raccomandata',
        'scadenza' => '2026-12-20', 'natura' => 'accessorio', 'tipo_accessorio' => 'sollecito', 'base' => 'regolamento', 'riferimento' => 'art. 20', 'conto_id' => $voce->id,
    ]], $this->user->id, '2026-12-20')[0];
    $quota = RataQuote::where('rata_id', $rata->id)->firstOrFail();

    vrmGiorno($this, '2026-12-28');
    vrmIncassa($this, $s, 'ugo', [$quota->id => 500], '2026-12-28', 300);
    expect(app(SpesaPerVoceService::class)->accessoriPerEsercizio($s['e']))->toBe([$voce->id => 500])
        ->and((int) $quota->fresh()->importo_pagato)->toBe(800);
});
