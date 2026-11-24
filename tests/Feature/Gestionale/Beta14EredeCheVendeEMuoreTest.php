<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14 (Coda 484) — l'erede che vende la quota ereditata e poi muore, con la successione registrata su un'altra unità: la fermata.
 *
 * Il cancello del defunto (`DefuntoNelPiano`) cercava le successioni sulle unità delle cifre, e quelle degli eredi sulle stesse unità. Ada, erede di Ugo
 * sull'Interno A, vende la sua quota di A e muore: la sua successione si registra solo sull'Interno B, l'unica unità dove ha ancora una riga. La voce di
 * Ugo su A chiedeva «€ 73,75 a debito di Ada» e, registrate le righe, diceva «Non serve registrare altro»; i giorni di Ada su A, € 263,75, non avevano
 * nessuna voce: € 337,50 restavano a nome di una defunta. Ora, come per la Coda 480 (decisione 190.1), la fermata: la voce di Ugo è nello stato della
 * divisione da decidere e dice dov'è la successione di Ada, e una voce nuova dice la cifra di Ada su A, che la sua successione su B non passa ai suoi eredi.
 * Le somme del piano non cambiano: la regola completa, la parte da erede che passa agli eredi di Ada, è della Coda 481.
 *
 * Fonte delle cifre, la regola a mano (lo scenario SF6 della prova dello scettico della fermata della 1.12.0-beta.1). «Spese generali» € 3.650,00 (365000) sulla
 * tabella «Proprietà», Interno A e Interno B da 500 millesimi: 500 centesimi al giorno per il 100 % di un'unità; 365 giorni nel 2026 (59 fino al 1/3, 31 fino al
 * 1/4, 30 fino al 1/5). Ugo e Ada al 50 % di A, Ada al 100 % di B. Ugo muore il 1/3/2026 (eredi Ada 25 e Anna 25); Ada vende il suo 75 % di A a Carlo il 1/4
 * e muore il 1/5 (successione su B, erede Bruno).
 * - Ugo su A 0,5 × 59 × 500 = 14750, le parti Ada 7375 e Anna 7375.
 * - Ada su A 0,5 × 59 × 500 + 0,75 × 31 × 500 = 14750 + 11625 = 26375; su B 120 × 500 = 60000.
 * - Carlo su A 0,75 × 275 × 500 = 103125; Anna su A 0,25 × 306 × 500 = 38250; Bruno su B 245 × 500 = 122500.
 * - Somma 14750 + 26375 + 60000 + 103125 + 38250 + 122500 = 365000.
 *
 * La catena: le due successioni e la vendita dalle rotte del passaggio → la generazione del piano dalla sua pagina → le voci del cancello → le somme.
 *
 * Prefisso `b14ev`: Pest carica tutti i file nello stesso processo.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Services\CondominioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    // Giugno 2027: il 2026 è chiuso, il 2027 è l'esercizio aperto, dove la porta del Wallet scrive.
    $this->travelTo(now()->setDate(2027, 6, 15)->setTime(10, 0));
});

/** La chiusura delle voci della fermata della Coda 480: la divisione è dell'amministratore, e le righe del Wallet non chiudono la voce. */
const B14EV_CHIUSURA = ' La divisione fra gli eredi la decide l\'amministratore: il programma non ne propone le parti né le righe del Wallet, e nessuna riga del Wallet chiude questa voce, che torna a ogni generazione.';

/**
 * Il caso SF6: Interno A e Interno B da 500 millesimi, «Spese generali» € 3.650,00 sulla gestione ordinaria del 2026 e del 2027 (aperto il 2027), il piano
 * del 2026 non generato; la successione di Ugo, la vendita di Ada a Carlo e la successione di Ada su B dalle rotte del passaggio.
 *
 * @return array{0: Beta51Scenario, 1: array<string, Subentro>}
 */
function b14evCaso($test): array
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['A' => 500, 'B' => 500]);
    $es = [2026 => $s->e];
    $gestioni = [];
    foreach ([2026, 2027] as $anno) {
        $es[$anno] ??= Esercizio::create(['condominio_id' => $s->c->id, 'nome' => "Esercizio {$anno}", 'data_inizio' => "{$anno}-01-01", 'data_fine' => "{$anno}-12-31", 'stato' => 'aperto']);
        $gestioni[] = app(CondominioService::class)->createDefaultGestione($s->c, $es[$anno])->id;
    }
    expect(array_unique($gestioni))->toHaveCount(1);
    $g = \App\Models\Gestione::findOrFail($gestioni[0]);
    $s->gestioni['Ordinaria'] = ['g' => $g, 'tipo' => 'ordinaria', 'pc' => PianoConto::create(['condominio_id' => $s->c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti'])];
    $s->voce('Ordinaria', 'Spese generali', 365000, ['Proprietà' => 100]);
    $s->titolare('A', 'ugo', 'proprietario', 50)->titolare('A', 'ada', 'proprietario', 50)->titolare('B', 'ada', 'proprietario', 100);
    $es[2026]->update(['stato' => 'chiuso']);
    $s->e = $es[2026];
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', primaScadenza: '2026-01-05');
    $sub = [];
    $sub['ugo'] = $s->passaggio('successione', 'A', 'ugo', null, '2026-03-01', ['eredi' => ['ada' => 25, 'anna' => 25], 'riferimento' => 'ada']);
    $s->passaggio('vendita', 'A', 'ada', 'carlo', '2026-04-01', ['quota' => 75]);
    $sub['ada'] = $s->passaggio('successione', 'B', 'ada', null, '2026-05-01', ['eredi' => ['bruno' => 100], 'riferimento' => 'bruno']);

    return [$s, $sub];
}

/** La generazione dalla pagina del piano, con o senza la presa d'atto del cancello. */
function b14evGenera($test, Beta51Scenario $s, bool $accetta = false): TestResponse
{
    return $test->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s->c, $s->e, $s->piano('Preventivo 2026')]),
        $accetta ? ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: il cancello dell\'erede che vende e muore nella prova'] : []);
}

/** Le voci dei defunti del cancello, per «nome del defunto · unità». @return array<string, array<string, mixed>> */
function b14evVoci(TestResponse $r): array
{
    return collect($r->getSession()->get('destinatari_warning') ?? [])->where('motivo', 'defunto_nel_piano')
        ->keyBy(fn ($v) => $v['defunto']['nome'] . ' · ' . $v['immobile_nome'])->all();
}

/** Una riga dalla porta del Wallet sull'Interno A, nell'esercizio aperto, legata alla successione data. */
function b14evWallet($test, Beta51Scenario $s, string $chi, int $cents, Subentro $passaggio): void
{
    $test->actingAs($s->admin)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s->c]), [
        'anagrafica_id' => $s->persona($chi)->id, 'immobile_id' => $s->unita['A']->id, 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'saldo_iniziale' => $cents,
        'passaggio_id' => $passaggio->id,
    ])->assertSessionHasNoErrors();
}

const B14EV_VOCE_DI_UGO = 'La successione di Ugo (decesso del 1 marzo 2026) ha passato l\'arretrato agli eredi, ma questo piano calcola ancora per Ugo € 147,50 su Interno A: '
    . 'la competenza è anteriore al decesso e le rate nascono ora, fuori dall\'arretrato.'
    . ' Ada, erede di Ugo, ha a sua volta una successione su Interno B (decesso del 1 maggio 2026) con l\'arretrato passato agli eredi: la sua parte da erede, € 73,75, '
    . 'il programma non la passa ai suoi eredi, Bruno.' . B14EV_CHIUSURA;

const B14EV_VOCE_DI_ADA_SU_A = 'Il piano calcola ancora per Ada € 263,75 su Interno A, per i giorni in cui ne era titolare. Il decesso è del 1 maggio 2026 e la successione è '
    . 'registrata su Interno B: quella successione non li passa ai suoi eredi. Decidi tu come dividerli; le righe del Wallet non chiudono questa voce.'
    . ' Ada è anche erede di Ugo su quest\'unità (decesso del 1 marzo 2026): la sua parte da erede, € 73,75, il programma non la passa ai suoi eredi, Bruno.';

test('Coda 484 — la vendita scrive le righe che il caso vuole: Ada chiusa su A al 31/03, Carlo dal 01/04 con il 75 %, la successione di Ada solo su B', function () {
    [$s, $sub] = b14evCaso($this);

    $righe = \Illuminate\Support\Facades\DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['A']->id)->orderBy('id')
        ->get(['anagrafica_id', 'quota', 'data_inizio', 'data_fine'])
        ->map(fn ($r) => [(int) $r->anagrafica_id, (float) $r->quota, substr((string) $r->data_inizio, 0, 10), $r->data_fine === null ? null : substr((string) $r->data_fine, 0, 10)])->all();
    $ada = (int) $s->persona('ada')->id;
    expect(collect($righe)->where(0, $ada)->sortByDesc(2)->first())->toBe([$ada, 75.0, '2026-03-01', '2026-03-31'])
        ->and(collect($righe)->firstWhere(0, (int) $s->persona('carlo')->id))->toBe([(int) $s->persona('carlo')->id, 75.0, '2026-04-01', null])
        ->and((int) $sub['ada']->immobile_id)->toBe((int) $s->unita['B']->id)
        ->and(Subentro::where('anagrafica_uscente_id', $ada)->where('tipo_passaggio', 'successione')->count())->toBe(1);
});

test('Coda 484 — la voce di Ugo su A dice che Ada, sua erede, ha la successione su Interno B, e la divisione la decide l\'amministratore: niente «a debito di Ada»', function () {
    [$s] = b14evCaso($this);

    $voci = b14evVoci(b14evGenera($this, $s));

    expect($voci['Ugo · Interno A']['cifra'])->toBe(14750)
        ->and($voci['Ugo · Interno A']['frase'])->toBe(B14EV_VOCE_DI_UGO)
        ->and($voci['Ugo · Interno A']['parti_da_decidere'])->toBeTrue()
        ->and(array_column($voci['Ugo · Interno A']['eredi'], 'cifra', 'nome'))->toBe(['Ada' => null, 'Anna' => null]);
    expect($voci['Ugo · Interno A']['frase'])->not->toContain('a debito di Ada')->not->toContain('registra dal Wallet')->not->toContain('Non serve registrare altro');
});

test('Coda 484 — una voce nuova per Ada su A: € 263,75 dei suoi giorni, che la successione su Interno B non passa ai suoi eredi', function () {
    [$s, $sub] = b14evCaso($this);

    $voci = b14evVoci(b14evGenera($this, $s));

    expect(array_keys($voci))->toBe(['Ugo · Interno A', 'Ada · Interno A', 'Ada · Interno B']);
    $suA = $voci['Ada · Interno A'];
    expect($suA['cifra'])->toBe(26375)
        ->and($suA['immobile_id'])->toBe((int) $s->unita['A']->id)
        ->and($suA['defunto']['id'])->toBe((int) $s->persona('ada')->id)
        ->and($suA['passaggio_id'])->toBe((int) $sub['ada']->id)
        ->and($suA['decesso'])->toBe('2026-05-01')
        ->and($suA['parti_da_decidere'])->toBeTrue()
        ->and(array_column($suA['eredi'], 'cifra', 'nome'))->toBe(['Bruno' => null])
        ->and($suA['due_serie'])->toBe([])
        ->and($suA['frase'])->toBe(B14EV_VOCE_DI_ADA_SU_A);
    // La voce di Ada su B, dove la successione c'è, resta quella di sempre: € 600,00 a Bruno, con la strada del Wallet.
    expect($voci['Ada · Interno B']['cifra'])->toBe(60000)
        ->and(array_column($voci['Ada · Interno B']['eredi'], 'cifra', 'nome'))->toBe(['Bruno' => 60000])
        ->and($voci['Ada · Interno B']['parti_da_decidere'])->toBeFalse();
});

test('Coda 484 — con le righe che la voce di Ugo chiedeva registrate dal Wallet le voci tornano uguali, e nessuna dice «Non serve registrare altro»', function () {
    [$s, $sub] = b14evCaso($this);
    $prima = b14evVoci(b14evGenera($this, $s));

    b14evWallet($this, $s, 'ugo', -14750, $sub['ugo']);
    b14evWallet($this, $s, 'ada', 7375, $sub['ugo']);
    b14evWallet($this, $s, 'anna', 7375, $sub['ugo']);
    $dopo = b14evVoci(b14evGenera($this, $s));

    expect($dopo['Ugo · Interno A']['frase'])->toBe($prima['Ugo · Interno A']['frase'])
        ->and($dopo['Ada · Interno A']['frase'])->toBe($prima['Ada · Interno A']['frase']);
    foreach (['Ugo · Interno A', 'Ada · Interno A'] as $chiave) {
        expect($dopo[$chiave]['frase'])->not->toContain('Non serve registrare altro')->not->toContain('I saldi ci sono già')->and($dopo[$chiave]['parti_da_decidere'])->toBeTrue();
    }
});

test('Coda 484 — la fermata dice, non sposta denaro: con la presa d\'atto le somme del piano sono quelle dei giorni (in tutto € 3.650,00)', function () {
    [$s] = b14evCaso($this);

    b14evGenera($this, $s, true)->assertSessionHasNoErrors();

    expect($s->somme('Preventivo 2026', ['ugo', 'ada', 'carlo', 'anna', 'bruno']))->toBe(['ugo' => 14750, 'ada' => 86375, 'carlo' => 103125, 'anna' => 38250, 'bruno' => 122500])
        ->and(array_sum($s->somme('Preventivo 2026')))->toBe(365000);
});

test('Coda 484, controprova — Ada non vende: la successione è anche su A, e il caso resta quello della Coda 480 sulla stessa unità, senza voci nuove', function () {
    // Ugo e Ada al 50 % di A, Ada al 100 % di B; Ugo muore il 1/3 (eredi Ada 25 e Anna 25), Ada il 1/5 con la successione su A (il suo 75 %) e su B (erede Bruno).
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 500, 'B' => 500]);
    $e2027 = Esercizio::create(['condominio_id' => $s->c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $g = app(CondominioService::class)->createDefaultGestione($s->c, $s->e);
    app(CondominioService::class)->createDefaultGestione($s->c, $e2027);
    $s->gestioni['Ordinaria'] = ['g' => $g, 'tipo' => 'ordinaria', 'pc' => PianoConto::create(['condominio_id' => $s->c->id, 'gestione_id' => $g->id, 'nome' => 'Piano dei conti'])];
    $s->voce('Ordinaria', 'Spese generali', 365000, ['Proprietà' => 100]);
    $s->titolare('A', 'ugo', 'proprietario', 50)->titolare('A', 'ada', 'proprietario', 50)->titolare('B', 'ada', 'proprietario', 100);
    $s->e->update(['stato' => 'chiuso']);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', primaScadenza: '2026-01-05');
    $s->passaggio('successione', 'A', 'ugo', null, '2026-03-01', ['eredi' => ['ada' => 25, 'anna' => 25], 'riferimento' => 'ada']);
    $s->passaggio('successione', 'A', 'ada', null, '2026-05-01', ['eredi' => ['bruno' => 75], 'riferimento' => 'bruno']);
    $s->passaggio('successione', 'B', 'ada', null, '2026-05-01', ['eredi' => ['bruno' => 100], 'riferimento' => 'bruno']);

    $voci = b14evVoci(b14evGenera($this, $s));

    expect(array_keys($voci))->toBe(['Ugo · Interno A', 'Ada · Interno A', 'Ada · Interno B'])
        ->and($voci['Ugo · Interno A']['frase'])->toContain('Ada, erede di Ugo, ha a sua volta una successione su quest\'unità (decesso del 1 maggio 2026)')
        ->and($voci['Ada · Interno A']['frase'])->toStartWith('La successione di Ada (decesso del 1 maggio 2026) ha passato l\'arretrato agli eredi')
        ->toContain('Ada è anche erede di Ugo su quest\'unità')
        ->and($voci['Ada · Interno B']['parti_da_decidere'])->toBeFalse();
});
