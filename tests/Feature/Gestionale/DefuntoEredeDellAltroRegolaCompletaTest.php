<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 481 — il defunto che è anche erede di un altro defunto della stessa unità: la regola completa.
 *
 * Prima la voce del primo defunto si fermava nello stato «la divisione la decide l'amministratore» (la fermata della Coda 480), e la parte da erede del
 * secondo defunto restava a nome suo. Gli eredi rispondono dei debiti in proporzione alla quota ereditaria (art. 754 c.c.): la parte da erede del secondo
 * defunto passa ai suoi eredi, in proporzione alle loro quote di lui. La voce del primo defunto nomina gli eredi del secondo al posto suo, con le cifre e
 * la strada del Wallet; le righe legate si legano alla successione del primo defunto.
 *
 * Fonte delle cifre, la regola a mano. Spesa «Spese generali» € 3.650,00 (365000) sull'Interno A da 1000 millesimi, 1000 centesimi al giorno per il 100 %.
 * Ugo e Ada al 50 %. Ugo muore il 1/3/2026 con eredi Ada 25 e Anna 25; Ada muore il 1/5/2026 con eredi Anna 45 e Bruno 30 (la sua quota, 75).
 * - Ugo 0,5 × 59 × 1000 = 29500: la parte di Ada 14750 e quella di Anna 14750. La parte di Ada passa ai suoi eredi per 45/75 e 30/75: Anna 8850, Bruno 5900.
 *   La voce di Ugo: Anna 14750 + 8850 = 23600, Bruno 5900.
 * - Ada 0,5 × 120 × 1000 + 0,25 × 61 × 1000 = 75250: Anna 45150, Bruno 30100.
 * - Dopo i decessi Anna 0,25 × 61 × 1000 + 0,7 × 245 × 1000 = 186750, Bruno 0,3 × 245 × 1000 = 73500.
 * - Con le righe legate: Ugo 0, Ada 0, Anna 186750 + 23600 + 45150 = 255500 (70 %), Bruno 73500 + 5900 + 30100 = 109500 (30 %).
 *
 * La catena: le due successioni → la generazione (le voci) → il Wallet (le righe legate, dalla rotta) → il piano (le quote) → l'emissione → l'estratto
 * conto di Bruno.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2027, 6, 15)->setTime(10, 0));
});

/**
 * Interno A, «Spese generali» sulla gestione ordinaria del 2026 e del 2027 (aperto il 2027), il piano del 2026 non generato, le successioni dalla rotta.
 *
 * @param list<array{0: string, 1: string, 2: string, 3: array<string, float>, 4: string}> $morti
 * @return array{0: Beta51Scenario, 1: array<string, Subentro>}
 */
function drcScenario($test, array $titolari, array $morti): array
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 1000]);
    $es = [2026 => $s->e];
    $gestioni = [];
    foreach ([2026, 2027] as $anno) {
        $es[$anno] ??= Esercizio::create(['condominio_id' => $s->c->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
        $gestioni[] = app(CondominioService::class)->createDefaultGestione($s->c, $es[$anno])->id;
    }
    $g = \App\Models\Gestione::findOrFail($gestioni[0]);
    $s->gestioni['Ordinaria'] = ['g' => $g, 'tipo' => 'ordinaria', 'pc' => PianoConto::create(['condominio_id' => $s->c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti'])];
    $s->voce('Ordinaria', 'Spese generali', 365000, ['Proprietà' => 100]);
    foreach ($titolari as [$chi, $quota]) {
        $s->titolare('A', $chi, 'proprietario', $quota);
    }
    $es[2026]->update(['stato' => 'chiuso']);
    $s->e = $es[2026];
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', primaScadenza: '2026-01-05');
    $sub = [];
    foreach ($morti as [$d, $il, $eredi, $rif]) {
        $sub[$d] = $s->passaggio('successione', 'A', $d, null, $il, ['eredi' => $eredi, 'arretrato' => 'eredi', 'riferimento' => $rif]);
    }

    return [$s, $sub];
}

function drcAd1($test): array
{
    return drcScenario($test, [['ugo', 50], ['ada', 50]], [['ugo', '2026-03-01', ['ada' => 25, 'anna' => 25], 'ada'], ['ada', '2026-05-01', ['anna' => 45, 'bruno' => 30], 'anna']]);
}

function drcGenera($test, Beta51Scenario $s, bool $accetta = false): TestResponse
{
    return $test->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $s->piano('Preventivo 2026')]),
        $accetta ? ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: il cancello del defunto nella prova'] : []);
}

/** @return array<string, array<string, mixed>> */
function drcVoci(TestResponse $r): array
{
    return collect($r->getSession()->get('destinatari_warning') ?? [])->where('motivo', 'defunto_nel_piano')->keyBy(fn ($v) => $v['defunto']['nome'])->all();
}

function drcParti(array $voce): array
{
    return array_column($voce['eredi'], 'cifra', 'nome');
}

/** Una riga legata dalla porta del Wallet, nell'esercizio aperto: la porta la deve accettare. */
function drcWallet($test, Beta51Scenario $s, string $chi, int $cents, Subentro $passaggio): void
{
    $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => $cents,
        'passaggio_id' => $passaggio->id,
    ])->assertSessionHasNoErrors();
}

const DRC_APERTURA_UGO = 'La successione di Ugo (decesso del 1 marzo 2026) ha passato l\'arretrato agli eredi, ma questo piano calcola ancora per Ugo € 295,00 su Interno A: la competenza è anteriore al decesso e le rate nascono ora, fuori dall\'arretrato.';
const DRC_ADA_PASSA = 'Ada, erede di Ugo, ha a sua volta una successione su quest\'unità (decesso del 1 maggio 2026) con l\'arretrato passato agli eredi: la sua parte da erede, € 147,50, passa ai suoi eredi per le loro quote, Anna € 88,50 e Bruno € 59,00.';

it('Coda 481 — la voce di Ugo nomina al posto di Ada i suoi eredi, con le cifre (Anna € 236,00, Bruno € 59,00) e la strada del Wallet; nessuna voce dice «la decide l\'amministratore»', function () {
    [$s] = drcAd1($this);

    $voci = drcVoci(drcGenera($this, $s));

    expect(array_keys($voci))->toBe(['Ugo', 'Ada'])
        ->and($voci['Ugo']['cifra'])->toBe(29500)
        ->and(drcParti($voci['Ugo']))->toBe(['Anna' => 23600, 'Bruno' => 5900])
        ->and($voci['Ugo']['parti_da_decidere'])->toBeFalse()
        ->and($voci['Ugo']['frase'])->toStartWith(DRC_APERTURA_UGO . ' Ne rispondono gli eredi, ciascuno per la sua quota (art. 754 c.c.): Anna € 236,00 e Bruno € 59,00. ' . DRC_ADA_PASSA)
        ->toContain('registra dal Wallet i saldi legati alla successione del 1 marzo 2026, sulla stessa gestione: € 295,00 a credito di Ugo, € 236,00 a debito di Anna e € 59,00 a debito di Bruno');
    expect(drcParti($voci['Ada']))->toBe(['Anna' => 45150, 'Bruno' => 30100])
        ->and($voci['Ada']['parti_da_decidere'])->toBeFalse()
        ->and($voci['Ada']['frase'])->toContain('Ada è anche erede di Ugo su quest\'unità (decesso del 1 marzo 2026): la sua parte da erede, € 147,50, passa ai suoi eredi con la voce di Ugo.');
    foreach ($voci as $voce) {
        expect($voce['frase'])->not->toContain('la decide l\'amministratore')->not->toContain('il programma non la passa');
    }
});

it('Coda 481 — la catena: le righe legate dalla porta del Wallet (anche Bruno sulla successione di Ugo), le voci si chiudono, il piano dà Ada € 0,00, Anna € 2.555,00, Bruno € 1.095,00, e l\'estratto conto di Bruno le porta', function () {
    [$s, $sub] = drcAd1($this);

    drcWallet($this, $s, 'ugo', -29500, $sub['ugo']);
    drcWallet($this, $s, 'anna', 23600, $sub['ugo']);
    drcWallet($this, $s, 'bruno', 5900, $sub['ugo']);
    drcWallet($this, $s, 'ada', -75250, $sub['ada']);
    drcWallet($this, $s, 'anna', 45150, $sub['ada']);
    drcWallet($this, $s, 'bruno', 30100, $sub['ada']);

    $voci = drcVoci(drcGenera($this, $s));
    expect($voci['Ugo']['frase'])->toEndWith('Non serve registrare altro.')->and($voci['Ada']['frase'])->toEndWith('Non serve registrare altro.');
    drcGenera($this, $s, true)->assertSessionHasNoErrors();
    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'anna', 'bruno']))->toBe(['ugo' => 0, 'ada' => 0, 'anna' => 255500, 'bruno' => 109500]);

    $this->actingAs($s->admin)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s->c, $s->persona('bruno')]))->assertOk();
});

it('Coda 481 — con le righe di prima (la parte da erede legata ad Ada) la voce di Ugo dice che il saldo legato di Ada si toglie, e non dice «Non serve registrare altro»', function () {
    [$s, $sub] = drcAd1($this);
    drcWallet($this, $s, 'ugo', -29500, $sub['ugo']);
    drcWallet($this, $s, 'ada', 14750, $sub['ugo']);
    drcWallet($this, $s, 'anna', 23600, $sub['ugo']);
    drcWallet($this, $s, 'bruno', 5900, $sub['ugo']);

    $voci = drcVoci(drcGenera($this, $s));

    expect($voci['Ugo']['frase'])->toContain('Ada ha già un saldo legato alla successione del 1 marzo 2026 su quest\'unità (€ 147,50 a debito): la sua parte da erede passa ai suoi eredi, e quel saldo si toglie dal Wallet.')
        ->not->toContain('Non serve registrare altro');
});

it('Coda 481 — in profondità: Ugo, Ada erede di Ugo, Bruno erede di Ada e di Ugo, tutti defunti sulla stessa unità: le voci dividono fra i vivi (Anna e Carla), i centesimi tornano', function () {
    // Ugo muore il 1/3 (eredi Ada 25 e Bruno 25), Ada il 1/5 (eredi Bruno 50 e Anna 25, la sua quota 75), Bruno il 1/7 (eredi Carla 50 e Anna 25, la sua quota 75).
    [$s] = drcScenario($this, [['ugo', 50], ['ada', 50]], [
        ['ugo', '2026-03-01', ['ada' => 25, 'bruno' => 25], 'ada'],
        ['ada', '2026-05-01', ['bruno' => 50, 'anna' => 25], 'bruno'],
        ['bruno', '2026-07-01', ['carla' => 50, 'anna' => 25], 'carla'],
    ]);

    $voci = drcVoci(drcGenera($this, $s));

    expect(array_keys($voci))->toBe(['Ugo', 'Ada', 'Bruno']);
    foreach ($voci as $nome => $voce) {
        expect(array_keys(drcParti($voce)))->toEqualCanonicalizing(['Anna', 'Carla'])
            ->and(array_sum(drcParti($voce)))->toBe($voce['cifra'])
            ->and($voce['parti_da_decidere'])->toBeFalse();
    }
    // Ugo 29500: Ada 14750 (Bruno 9833, Anna 4917) e Bruno 14750; Bruno 14750 + 9833 = 24583 (Carla 16389, Anna 8194). Anna 4917 + 8194 = 13111, Carla 16389.
    expect(drcParti($voci['Ugo']))->toBe(['Anna' => 13111, 'Carla' => 16389]);
});
