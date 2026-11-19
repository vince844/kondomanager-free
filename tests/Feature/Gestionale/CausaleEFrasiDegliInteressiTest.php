<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il giro A delle correzioni della 1.12.0-beta.12, le frasi degli interessi: la causale con il tasso abbassato da «Cambia» (decisione 526), N2 e N3 della terza
 * prova, D5 e D6 della lente del denaro della 1-bis. Sul caso di Ettore Fabbri (`InteressiSulDebitoEreditatoScenario.php`, il 2 % l'anno «art. 12 del
 * regolamento» dal 01/01/2026) e su Bianchi, condòmino di U2 senza successione, con la rata 1 (€ 180,00, scaduta il 30/04/2026) e la rata 2 non versate.
 *
 * Che cosa NON copre: l'estratto conto a video e la stampa (la causale è la stessa riga); il tasso legale e la BCE più i punti nella causale (stessa funzione,
 * `RegoleDelRecupero::tassiNellaCausale`); MySQL.
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Registra le righe date della proposta, con i tassi cambiati e il perché. */
function cfiRegistra($test, array $s, array $proposta, array $chiavi, array $tassi = [], ?string $motivo = null)
{
    $righe = collect($proposta['persone'])->flatMap(fn ($p) => $p['righe'])->whereIn('chiave', $chiavi);

    return $test->actingAs($test->user)->postJson(route('admin.gestionale.addebiti.interessi.store', [$s['c']]), array_filter([
        'alla' => $proposta['alla'], 'righe' => $righe->pluck('chiave')->values()->all(), 'atteso' => (int) $righe->sum('interessi'),
        'voci' => collect($proposta['gestioni'])->mapWithKeys(fn ($g) => [$g['gestione_id'] => $s['interessi']->id])->all(),
        'tassi' => $tassi ?: null, 'motivo' => $motivo,
    ], fn ($v) => $v !== null));
}

/** La causale dell'ultimo addebito degli interessi di una persona. */
function cfiCausale(array $s, string $chi): string
{
    $q = RataQuote::where('anagrafica_id', $s['persone'][$chi]->id)->where('tipo', RataQuote::TIPO_ACCESSORIO)->latest('id')->firstOrFail();

    // Lo spazio che non va a capo fra la cifra e il «%», come uno spazio.
    return str_replace("\u{00A0}", ' ', (string) DB::table('rate')->where('id', $q->rata_id)->value('descrizione'));
}

/** Bianchi con la rata 2 emessa il 20/05/2026: due rate sue su U2 non versate. */
function cfiBianchi($test): array
{
    $s = ideCaso($test);
    $test->travelTo('2026-05-20 10:00:00');
    lcdEmetti($test, $s, [2], '2026-05-20');

    return $s;
}

it('decisione 526: con il tasso abbassato da «Cambia» la causale dice chi l\'ha scelto e da quale tasso è ridotto, come lo direbbe la causale senza «Cambia»', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Elena'], '2026-12-31');
    $r = ideEreditata($p, $s, 'Elena');
    cfiRegistra($this, $s, $p, [$r['chiave']], [$r['chiave'] => '1,00'], 'Accordo con l\'erede')->assertCreated();
    // Con il codice di prima: «(1,00 % annuo, scelto dall'amministratore)».
    expect(cfiCausale($s, 'Elena'))->toBe('Interessi di mora al 31/12/2026 (1,00 % annuo, scelto dall\'amministratore, ridotto dal 2,00 % annuo, art. 12 del regolamento)');
});

it('N2: due righe dello stesso addebito, una con il tasso abbassato e una al tasso delle regole, negli stessi giorni: la causale non dice «poi»', function () {
    $s = cfiBianchi($this);
    $p = ideProposta($this, $s, ['Bianchi'], '2026-12-31');
    $righe = collect(accDi($p, $s, 'Bianchi')['righe']);
    expect($righe)->toHaveCount(2);
    $prima = $righe->firstWhere('rata_id', lcdRata($s, 1));
    cfiRegistra($this, $s, $p, $righe->pluck('chiave')->all(), [$prima['chiave'] => '1,00'], 'Accordo con il condòmino')->assertCreated();
    // Con il codice di prima: «(1,00 % annuo, scelto dall'amministratore; poi 2,00 % annuo, art. 12 del regolamento)», come se il tasso cambiasse nel tempo.
    expect(cfiCausale($s, 'Bianchi'))->toBe('Interessi di mora al 31/12/2026 (1,00 % annuo, scelto dall\'amministratore, ridotto dal 2,00 % annuo, art. 12 del regolamento; '
        . '2,00 % annuo, art. 12 del regolamento)');
});

it('decisione 526: oltre la lunghezza massima della causale la riduzione si dice con il solo tasso, e la registrazione non si rifiuta', function () {
    $s = ideCaso($this);
    $riferimento = 'Regolamento di condominio approvato dalla delibera dell\'assemblea straordinaria del 12/03/2019, articolo 12, comma 3, lettera b, punto 2, modificato';
    expect(mb_strlen($riferimento))->toBeLessThanOrEqual(150);
    DB::table('regole_recupero')->where('condominio_id', $s['c']->id)->update(['riferimento' => $riferimento]);
    ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Elena'], '2026-12-31');
    $r = ideEreditata($p, $s, 'Elena');
    // Con il codice di prima la causale con il riferimento intero e «ridotto dal» superava i 245 caratteri, e niente si registrava.
    cfiRegistra($this, $s, $p, [$r['chiave']], [$r['chiave'] => '1,00'], 'Accordo con l\'erede')->assertCreated();
    expect(cfiCausale($s, 'Elena'))->toBe('Interessi di mora al 31/12/2026 (1,00 % annuo, scelto dall\'amministratore, ridotto dal 2,00 %)');
});

it('D5: «Cambia» con un tasso più basso che dà la stessa cifra (1,99 % invece del 2,00 %, € 0,37): il tasso scelto resta scritto e la causale lo dice', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Elena'], '2026-12-31');
    $r = ideEreditata($p, $s, 'Elena');
    cfiRegistra($this, $s, $p, [$r['chiave']], [$r['chiave'] => '1,99'], 'Accordo con l\'erede')->assertCreated();
    $q = RataQuote::where('anagrafica_id', $s['persone']['Elena']->id)->where('tipo', RataQuote::TIPO_ACCESSORIO)->latest('id')->firstOrFail();
    $d = \App\Services\Gestionale\Addebiti\AddebitiIndividuali::dati($q);
    // Con il codice di prima il tasso non restava, e la causale diceva il 2,00 %.
    expect((int) $q->importo)->toBe(37)
        ->and($d['interessi']['righe'][0]['tasso']['e4'] ?? null)->toBe(19900)
        ->and(cfiCausale($s, 'Elena'))->toStartWith('Interessi di mora al 31/12/2026 (1,99 % annuo, scelto dall\'amministratore, ridotto dal 2,00 %');
});

it('N3: registrata la rata 1 fino al 31/12, il tratto del 01/01/2027 della rata 2 dice «anno», non «credito»', function () {
    $s = cfiBianchi($this);
    $p = ideProposta($this, $s, ['Bianchi'], '2026-12-31');
    $prima = collect(accDi($p, $s, 'Bianchi')['righe'])->firstWhere('rata_id', lcdRata($s, 1));
    cfiRegistra($this, $s, $p, [$prima['chiave']])->assertCreated();
    DB::table('esercizi')->where('id', $s['e']->id)->update(['data_fine' => '2027-12-31']);
    $dopo = ideProposta($this, $s, ['Bianchi'], '2027-01-31');
    $seconda = collect(accDi($dopo, $s, 'Bianchi')['righe'])->firstWhere('rata_id', lcdRata($s, 2));
    $delGennaio = collect($seconda['tratti'])->firstWhere('dal', '2027-01-01');
    // Con il codice di prima: «credito» («un credito o un anticipo copre una parte»): l'addebito degli interessi del 31/12 è un movimento di quel giorno.
    expect($delGennaio['causa'] ?? null)->toBe('anno')
        ->and($delGennaio['capitale'])->toBe(collect($seconda['tratti'])->first()['capitale']);
});

it('D6: lo storico di una successione registrata prima della beta dice che «si fermano al decesso» l\'ha messo l\'aggiornamento, non una scelta', function () {
    $s = ideCaso($this);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s));
    $storico = fn () => (string) (collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']['U1']->fresh())['subentri'])->firstWhere('id', $passaggio->id)['arretrato']['frase'] ?? '');
    // Registrata con la scelta: lo storico la dice e basta.
    expect($storico())->toContain('Gli interessi di Ettore Fabbri si fermano al decesso')->not->toContain('aggiornamento');
    // Come prima della beta: la scelta non è nel registro, la colonna vuota; poi la migrazione 170100.
    $registro = $passaggio->registro;
    unset($registro['arretrato']['interessi_del_defunto']);
    DB::table('subentri')->where('id', $passaggio->id)->orWhere('subentro_padre_id', $passaggio->id)->update(['interessi_del_defunto' => null, 'registro' => json_encode($registro)]);
    (require base_path('database/migrations/2026_11_18_170100_riallinea_gli_interessi_delle_successioni_registrate.php'))->up();
    // Con il codice di prima lo storico diceva la stessa frase, come se fosse stata la scelta di chi ha registrato la successione.
    expect($storico())->toContain('Gli interessi di Ettore Fabbri si fermano al decesso')
        ->toContain('Non è una scelta fatta registrando la successione: è il valore di serie che l\'aggiornamento del programma ha dato alle successioni registrate prima.');
});

it('Coda 678: il collegamento della finestra della proposta apre il piano dei conti della gestione dove si scrive, non l\'elenco dei piani', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Elena'], '2026-12-31');
    $scrive = $p['gestioni'][0]['scrive'];
    $piano = (int) DB::table('piani_conti')->where('condominio_id', $s['c']->id)->where('gestione_id', $scrive['gestione_id'])->orderBy('id')->value('id');
    expect($scrive['piano_conto_id'])->toBe($piano)->and($piano)->toBeGreaterThan(0);
    // La pagina che il collegamento apre (`ProponiInteressiDialog.vue`, `indirizzoPianoDeiConti`) è quella del piano, con le sue voci.
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-conti.show', ['condominio' => $s['c']->id, 'esercizio' => $scrive['esercizio_id'], 'pianoConto' => $piano]))
        ->assertOk()->assertInertia(fn ($x) => $x->where('pianoConti.id', $piano)->etc());
});
