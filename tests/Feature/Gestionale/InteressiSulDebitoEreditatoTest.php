<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Decisioni 489, 493 e 494: gli interessi sul debito ereditato agli eredi dal decesso, ciascuno per la sua parte, finché quel debito non entra in una loro
 * rata (`DebitoEreditato`), con le otto scelte dell'amministratore (`RegoleDegliEredi`), le cifre cambiate prima di registrare, gli interessi del defunto
 * registrati dopo la successione agli eredi, e i difetti D2–D4 della prova della Coda 663. Il caso di Ettore Fabbri (`InteressiSulDebitoEreditatoScenario.php`):
 * la rata di aprile da € 300,00 scaduta il 30/04/2026, il 2 % l'anno dal 01/01/2026, il decesso il 17/11/2026, Elena e Marco al 50 %.
 *
 * Le cifre sono quelle del motore (capitale × tasso × giorni / 365, un arrotondamento per riga): € 150,00 × 2 % dal 17/11 al 31/12/2026, 45 giorni, € 0,37;
 * fino al 31/03/2027, 135 giorni, € 1,11; fino al 10/04/2027, 145 giorni, € 1,19; dal 17/11 al 20/11, 4 giorni, € 0,03; dal 17/11/2026 al 15/02/2027, 91
 * giorni, € 0,75; dal 01/01 al 31/03/2027, 90 giorni, € 0,74; dal 18/11 al 31/12, 44 giorni, € 0,36; dal 16/01 al 31/03/2027, 75 giorni, € 0,62.
 *
 * Nella prova Marco è l'erede che non riceve le bozze del defunto: Elena, l'erede di riferimento, ha anche la coppia del conguaglio a credito, e nel piano
 * che assorbe l'arretrato la sua quota è a credito (la riga ereditata esce lo stesso, e il credito la copre dall'emissione).
 *
 * Cosa NON copre: la pagina e la finestra (vitest `ProponiInteressiDialogEredi.test.ts`, `InteressiDegliEredi.test.ts`); le successioni a catena; un tasso
 * legale del 2027 (qui c'è un tasso scritto); più successioni dello stesso defunto; il piano che assorbe in una gestione diversa da quella del debito; MySQL.
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\Subentro;
use App\Models\ImpostazioniCondominio;
use App\Services\Gestionale\Accessori\RegoleDegliEredi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Le scelte del condominio, con quelle date cambiate. */
function ideRegoleCondominio(array $s, array $scelte): void
{
    $colonne = [];
    foreach ($scelte as $chiave => $valore) {
        $colonne[RegoleDegliEredi::SCELTE[$chiave][0]] = $valore;
    }
    ImpostazioniCondominio::updateOrCreate(['condominio_id' => $s['c']->id], $colonne);
}

/** Registra le righe date (per chiave) della proposta, con le cifre cambiate e il perché. */
function ideRegistraRighe($test, array $s, array $proposta, array $chiavi, array $importi = [], ?string $motivo = null)
{
    $righe = collect($proposta['persone'])->flatMap(fn ($p) => $p['righe'])->whereIn('chiave', $chiavi);

    return $test->actingAs($test->user)->postJson(route('admin.gestionale.addebiti.interessi.store', [$s['c']]), array_filter([
        'alla' => $proposta['alla'], 'righe' => $righe->pluck('chiave')->values()->all(), 'atteso' => (int) $righe->sum('interessi'),
        'voci' => collect($proposta['gestioni'])->mapWithKeys(fn ($g) => [$g['gestione_id'] => $s['interessi']->id])->all(),
        'importi' => $importi ?: null, 'motivo' => $motivo,
    ], fn ($v) => $v !== null));
}

it('al 31/12/2026 Elena e Marco hanno la riga del debito ereditato, € 150,00 dal 17/11 al 31/12, € 0,37; Ettore si ferma al 16/11, € 3,29, e la riga dice perché (D4)', function () {
    $s = ideCaso($this);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Elena', 'Marco'], '2026-12-31');
    foreach (['Elena', 'Marco'] as $chi) {
        $r = ideEreditata($p, $s, $chi);
        // Con il codice di prima: nessuna riga (la proposta legge solo il giornale).
        expect([$r['interessi'], $r['dal'], $r['al']])->toBe([37, '2026-11-17', '2026-12-31'])
            ->and($r['chiave'])->toBe($s['persone'][$chi]->id . '|' . lcdRata($s, 1) . '|' . $s['unita']['U1']->id . '|s' . $passaggio->id)
            ->and($r['ereditata'])->toMatchArray(['subentro_id' => $passaggio->id, 'da' => 'Ettore Fabbri', 'percentuale' => '50', 'decesso' => '2026-11-17', 'parte' => 15000])
            ->and($r['frase_ereditata'])->toBe('Di Ettore Fabbri, passata a te per il 50 % il 17/11/2026: interessi su € 150,00 dal 17/11/2026 al 31/12/2026.')
            ->and($r['tratti'][0]['causa'])->toBe('decesso');
    }
    $ettore = collect(accDi(ideProposta($this, $s, ['Neri'], '2026-12-31'), $s, 'Neri')['righe'])->firstWhere('rata_id', lcdRata($s, 1));
    expect([$ettore['interessi'], $ettore['al']])->toBe([329, '2026-11-16'])
        // D4: con il codice di prima nessuna frase.
        ->and($ettore['fino_al_decesso'])->toBe('Fino al 16/11/2026: dal 17/11/2026 il debito è passato a Elena e Marco.');
});

it('proporre per il solo Ettore porta anche Elena e Marco; per la sola Elena porta Ettore, non Marco (seconda prova della beta.12)', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    $nomi = fn (array $p) => collect($p['persone'])->pluck('nome')->sort()->values()->all();
    // Con il codice di prima: solo Ettore. Cambiata per scelta di chi coordina (osservazione della seconda prova): il coerede non c'entra con la proposta di
    // Elena; prima la sola Elena portava anche Marco con tutte le sue righe.
    expect($nomi(ideProposta($this, $s, ['Neri'], '2026-12-31')))->toBe(['Elena', 'Ettore Fabbri', 'Marco'])
        ->and($nomi(ideProposta($this, $s, ['Elena'], '2026-12-31')))->toBe(['Elena', 'Ettore Fabbri']);
});

it('il piano 2027 che assorbe l\'arretrato, emesso il 15/01/2027: al 30/04/2027 la riga ereditata si ferma al 31/03, € 1,11, e la rata 1/2027 conta dal 01/04', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    $piano = idePianoNuovo($this, $s);
    $p = ideProposta($this, $s, ['Marco'], '2027-04-30');
    $r = ideEreditata($p, $s, 'Marco');
    expect([$r['interessi'], $r['dal'], $r['al']])->toBe([111, '2026-11-17', '2027-03-31'])
        ->and($r['frase_ereditata'])->toBe('Di Ettore Fabbri, passata a te per il 50 % il 17/11/2026: interessi su € 150,00 dal 17/11/2026 al 31/03/2027. '
            . 'Dal 01/04/2027 il debito è nella tua Rata 1 — Preventivo 2027, con i suoi interessi.');
    $rata = collect(accDi($p, $s, 'Marco')['righe'])->firstWhere('rata_id', (int) DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', 1)->value('id'));
    $quota = (int) RataQuote::where('rata_id', $rata['rata_id'])->where('anagrafica_id', $s['persone']['Marco']->id)->value('importo');
    // La rata 1/2027 dal 01/04 sulla quota intera (l'arretrato dentro), come prima: nessun giorno contato due volte.
    expect([$rata['dal'], $rata['tratti'][0]['capitale']])->toBe(['2027-04-01', $quota]);
});

it('scelta 3: con 10 giorni di tolleranza il debito ereditato conta fino al 10/04/2027, € 1,19; con la tolleranza della rata nuova fino al 31/03, € 1,11', function () {
    $s = ideCaso($this, 10);
    ideRegistra($this, $s, ideSuccessione($s));
    idePianoNuovo($this, $s);
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2027-04-30'), $s, 'Marco');
    expect([$r['interessi'], $r['al']])->toBe([119, '2027-04-10']);
    ideRegoleCondominio($s, ['tolleranza_rata_nuova' => true]);
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2027-04-30'), $s, 'Marco');
    expect([$r['interessi'], $r['al']])->toBe([111, '2027-03-31']);
});

it('scelta 4: il piano divide l\'arretrato su quattro rate; rata per rata la riga ereditata scende a scalini, altrimenti si ferma tutta alla prima rata', function () {
    $s = ideCaso($this);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s));
    // L'arretrato di Marco è solo la sua parte della rata di aprile, € 150,00 (nella prova c'è anche la sua parte delle bozze): il piano la divide in quattro.
    DB::table('saldi')->whereIn('id', $passaggio->saldiDellArretrato())->where('anagrafica_id', $s['persone']['Marco']->id)->update(['saldo_iniziale' => 15000]);
    idePianoNuovo($this, $s, 'tutte_rate', 4, '2027-01-15', [1, 2, 3, 4]);
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2027-12-31'), $s, 'Marco');
    // € 37,50 escono il 31/03, il 30/06, il 30/09 e il 31/12/2027: 15000 dal 17/11/2026 al 31/03/2027 (135 g) € 1,1096; 11250 al 30/06 (91 g) € 0,5610;
    // 7500 al 30/09 (92 g) € 0,3781; 3750 al 31/12 (92 g) € 0,1890: € 2,24.
    expect([$r['interessi'], $r['al']])->toBe([224, '2027-12-31'])
        ->and(array_column($r['tratti'], 'causa'))->toContain('nel_piano');
    ideRegoleCondominio($s, ['rata_per_rata' => false]);
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2027-12-31'), $s, 'Marco');
    expect([$r['interessi'], $r['al']])->toBe([111, '2027-03-31']);
});

it('scelta 2: spenta, gli interessi degli eredi partono dal giorno dopo il decesso, 18/11–31/12, € 0,36', function () {
    $s = ideCaso($this);
    ideRegoleCondominio($s, ['dal_decesso' => false]);
    ideRegistra($this, $s, ideSuccessione($s));
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2026-12-31'), $s, 'Marco');
    expect([$r['interessi'], $r['dal']])->toBe([36, '2026-11-18']);
});

it('la scelta della successione vince sulle regole del condominio: il condominio dal decesso, la successione dal giorno dopo', function () {
    $s = ideCaso($this);
    $scelte = array_merge(RegoleDegliEredi::diSerie(), ['dal_decesso' => false]);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s, eredi: $scelte));
    // Seconda prova della beta.12: anche se la pagina manda le otto, la successione tiene solo quella cambiata (prima tutte e otto).
    expect($passaggio->interessi_eredi)->toBe(['dal_decesso' => false])->and($passaggio->registro['arretrato']['interessi_eredi'])->toBe(['dal_decesso' => false]);
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2026-12-31'), $s, 'Marco');
    // Con il condominio di serie (dal decesso) sarebbe [37, 17/11].
    expect([$r['interessi'], $r['dal']])->toBe([36, '2026-11-18']);
    // Lo storico lo dice; l'annullamento la toglie, e il registro la ricorda.
    $storico = collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']['U1']->fresh())['subentri'])->firstWhere('id', $passaggio->id);
    expect((string) $storico['arretrato']['frase'])->toContain('Gli interessi degli eredi hanno le scelte cambiate per questa successione: «Partono dal giorno del decesso» '
        . 'spento. Le altre seguono le regole del condominio, in «Recupero dei crediti».');
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Prova di annullamento'])->assertRedirect();
    $passaggio = Subentro::conAnnullati()->findOrFail($passaggio->id);
    expect($passaggio->interessi_eredi)->toBeNull()->and($passaggio->registro['arretrato']['interessi_eredi'])->toBe(['dal_decesso' => false]);
});

it('l\'anteprima della successione ha la card degli interessi degli eredi con il riassunto della bozza, e il server rifiuta scelte incomplete', function () {
    $s = ideCaso($this);
    $this->travelTo('2026-11-20 09:00:00');
    $a = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), ideSuccessione($s))->assertOk()->json('rate.arretrato');
    expect($a['frase_interessi_eredi'])->toBe('Elena e Marco pagano gli interessi sul debito di Ettore Fabbri dal 17/11/2026, solo sulle rate, fino a quando entra in una loro rata. '
        . 'Un loro versamento copre prima questo debito.')
        ->and($a['interessi_eredi'])->toBe(RegoleDegliEredi::diSerie())
        ->and(count($a['interessi_eredi_voci']))->toBe(8);
    // Seconda prova della beta.12: una scelta sola va bene (prima servivano tutte e otto); una voce che non c'è, o un valore che non è acceso o spento, no.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), ideSuccessione($s, eredi: ['dal_decesso' => false]))
        ->assertOk();
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), ideSuccessione($s, eredi: ['dal_decesso' => 'forse']))
        ->assertStatus(422)->assertJsonValidationErrors(['interessi_eredi' => 'Per gli interessi degli eredi cambia una o più delle otto voci, ognuna accesa o spenta.']);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), ideSuccessione($s, eredi: ['nessuna' => true]))
        ->assertStatus(422);
});

it('scelta 5: Marco versa € 150,00 il 20/11 su un debito suo più recente; di serie il versamento copre prima il debito ereditato (€ 0,03), spenta lo copre per ultimo', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    // Un debito di Marco sulla rata 3 di U1 (scadenza 30/09), emesso il 15/09, e € 150,00 a credito il 20/11.
    ideRigaNelGiornale($s, 'Marco', lcdRata($s, 3), '2026-09-15', 15000, 'emissione_rata');
    ideRigaNelGiornale($s, 'Marco', null, '2026-11-20', -15000, 'incasso_rata');
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2026-12-31'), $s, 'Marco');
    expect([$r['interessi'], $r['al']])->toBe([3, '2026-12-31']);
    ideRegoleCondominio($s, ['versamento_prima' => false]);
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2026-12-31'), $s, 'Marco');
    expect($r['interessi'])->toBe(37);
});

it('Marco paga la rata 1/2027 il 15/02/2027: la riga ereditata si ferma lì, € 0,75', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    $piano = idePianoNuovo($this, $s);
    $rata = (int) DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', 1)->value('id');
    $quota = RataQuote::where('rata_id', $rata)->where('anagrafica_id', $s['persone']['Marco']->id)->firstOrFail();
    $this->travelTo('2027-02-15 10:00:00');
    lcdIncassa($this, $s, 'Marco', [$quota->id => (int) $quota->importo], '2027-02-15');
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2027-04-30'), $s, 'Marco');
    expect($r['interessi'])->toBe(75);
});

it('con «continuano a suo nome» e con l\'arretrato a nome del defunto gli eredi non hanno righe; scelta 7: coprono il buco dall\'emissione alla scadenza, € 0,62', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s, interessi: Subentro::INTERESSI_CONTINUANO));
    idePianoNuovo($this, $s);
    expect(ideEreditata(ideProposta($this, $s, ['Marco'], '2027-04-30'), $s, 'Marco'))->toBeNull();
    ideRegoleCondominio($s, ['buco_continuano' => true]);
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2027-04-30'), $s, 'Marco');
    expect([$r['interessi'], $r['dal'], $r['al']])->toBe([62, '2027-01-16', '2027-03-31'])
        ->and($r['tratti'][0]['causa'])->toBe('dopo_l_emissione');

    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s, Subentro::ARRETRATO_AL_DEFUNTO));
    expect(ideEreditata(ideProposta($this, $s, ['Neri'], '2026-12-31'), $s, 'Marco'))->toBeNull();
});

it('gli interessi di Ettore registrati oltre il decesso prima della scelta: la riga ereditata parte dal giorno dopo', function () {
    $s = ideCaso($this);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s, interessi: Subentro::INTERESSI_CONTINUANO));
    $p = ideProposta($this, $s, ['Neri'], '2026-12-31');
    ideRegistraRighe($this, $s, $p, [$s['persone']['Neri']->id . '|' . lcdRata($s, 1) . '|' . $s['unita']['U1']->id])->assertCreated();
    // Lo stato di chi aggiornava con una successione registrata prima: la scelta arriva dopo, con la migrazione.
    DB::table('subentri')->where('id', $passaggio->id)->update(['interessi_del_defunto' => Subentro::INTERESSI_AL_DECESSO]);
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2027-01-31'), $s, 'Marco');
    // 15000 × 2 % × 31 / 365 = 25,48 → € 0,25: i giorni fino al 31/12 li ha già pagati Ettore.
    expect([$r['interessi'], $r['dal']])->toBe([25, '2027-01-01']);
});

it('il tetto: la parte di Marco non supera le sue righe dell\'arretrato', function () {
    $s = ideCaso($this);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s));
    DB::table('saldi')->whereIn('id', $passaggio->saldiDellArretrato())->where('anagrafica_id', $s['persone']['Marco']->id)->update(['saldo_iniziale' => 10000]);
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2026-12-31'), $s, 'Marco');
    // 10000 × 2 % × 45 / 365 = 24,66 → € 0,25.
    expect([$r['interessi'], $r['ereditata']['parte']])->toBe([25, 10000]);
});

it('un erede con una sua quota sulla stessa rata e la stessa unità: due righe, la sua e quella ereditata, con il suffisso del passaggio', function () {
    $s = ideCaso($this);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s));
    ideRigaNelGiornale($s, 'Marco', lcdRata($s, 1), '2026-01-15', 5000, 'emissione_rata');
    $righe = ideRighe(ideProposta($this, $s, ['Marco'], '2026-12-31'), $s, 'Marco');
    $base = $s['persone']['Marco']->id . '|' . lcdRata($s, 1) . '|' . $s['unita']['U1']->id;
    // La sua: 5000 × 2 % × 245 / 365 = 67,12 → € 0,67, dal 01/05.
    expect($righe[$base])->toBe([67, '2026-05-01', '2026-12-31'])->and($righe[$base . '|s' . $passaggio->id])->toBe([37, '2026-11-17', '2026-12-31']);
});

it('registrare la riga ereditata scrive l\'addebito con il legame alla successione; poi la proposta riparte dal giorno dopo: € 0,74 dal 01/01 al 31/03/2027', function () {
    $s = ideCaso($this);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Marco'], '2026-12-31');
    $chiave = ideEreditata($p, $s, 'Marco')['chiave'];
    ideRegistraRighe($this, $s, $p, [$chiave])->assertCreated();
    $quota = RataQuote::where('anagrafica_id', $s['persone']['Marco']->id)->where('tipo', RataQuote::TIPO_ACCESSORIO)->latest('id')->firstOrFail();
    $riga = \App\Services\Gestionale\Addebiti\AddebitiIndividuali::dati($quota)['interessi']['righe'][0];
    expect((int) $quota->importo)->toBe(37)->and($riga['ereditata']['subentro_id'])->toBe($passaggio->id)->and($riga['al'])->toBe('2026-12-31')
        // La scrittura: DARE «Crediti verso condomini» con Marco, l'unità e la rata dell'addebito, AVERE «Accessori da incassare».
        ->and(accEmissione((int) $quota->rata_id))->toBe([['crediti_condomini', 'dare', 37, true, true, true], ['accessori_da_incassare', 'avere', 37, false, false, false]]);
    idePianoNuovo($this, $s);
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2027-04-30'), $s, 'Marco');
    expect([$r['interessi'], $r['dal'], $r['al'], $r['gia_registrati_al']])->toBe([74, '2027-01-01', '2027-03-31', '2026-12-31']);
});

it('decisione 493 (7): la successione non si annulla finché un erede ha interessi ereditati registrati; annullato l\'addebito, si annulla', function () {
    $s = ideCaso($this);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Marco'], '2026-12-31');
    ideRegistraRighe($this, $s, $p, [ideEreditata($p, $s, 'Marco')['chiave']])->assertCreated();
    $rata = (int) RataQuote::where('anagrafica_id', $s['persone']['Marco']->id)->where('tipo', RataQuote::TIPO_ACCESSORIO)->latest('id')->value('rata_id');
    $annulla = fn () => $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]),
        ['nota_annullamento' => 'Prova di annullamento']);
    // Con il codice di prima l'annullamento si fermava con la frase delle rate emesse dopo il passaggio («Annulla l'emissione di quella rata»), che per un
    // addebito non è la strada.
    $annulla()->assertStatus(422)->assertJsonValidationErrors(['passaggio' => 'Marco ha interessi sul debito ereditato con questa successione già registrati: '
        . "«Interessi di mora al 31/12/2026 (2,00\u{00A0}% annuo, art. 12 del regolamento)» (€ 0,37). Annulla prima quell'addebito dal suo estratto conto, poi torna qui."]);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $rata]))->assertSuccessful();
    $annulla()->assertRedirect();
    expect(Subentro::conAnnullati()->findOrFail($passaggio->id)->annullato())->toBeTrue();
});

it('decisione 493 (10): le cifre cambiate prima di registrare, solo in meno e con il perché; i giorni restano chiusi e la differenza non si chiede più', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Marco'], '2026-12-31');
    $chiave = ideEreditata($p, $s, 'Marco')['chiave'];
    ideRegistraRighe($this, $s, $p, [$chiave], [$chiave => '0,38'], 'Accordo con l\'erede')->assertStatus(422)
        ->assertJsonValidationErrors(['importi' => 'Più della proposta no: è il massimo che le regole del condominio danno. Per un tasso diverso cambia la regola in «Recupero dei crediti».']);
    ideRegistraRighe($this, $s, $p, [$chiave], [$chiave => '0'], 'Accordo con l\'erede')->assertStatus(422)
        ->assertJsonValidationErrors(['importi' => 'Per non chiedere niente, togli la riga.']);
    ideRegistraRighe($this, $s, $p, [$chiave], [$chiave => '0,30'])->assertStatus(422)
        ->assertJsonValidationErrors(['motivo' => 'Scrivi perché cambi le cifre, in almeno 5 caratteri: resta scritto nell\'addebito.']);
    expect(RataQuote::where('anagrafica_id', $s['persone']['Marco']->id)->where('tipo', RataQuote::TIPO_ACCESSORIO)->exists())->toBeFalse();

    ideRegistraRighe($this, $s, $p, [$chiave], [$chiave => '0,30'], 'Accordo con l\'erede')->assertCreated();
    $quota = RataQuote::where('anagrafica_id', $s['persone']['Marco']->id)->where('tipo', RataQuote::TIPO_ACCESSORIO)->latest('id')->firstOrFail();
    $d = \App\Services\Gestionale\Addebiti\AddebitiIndividuali::dati($quota);
    expect((int) $quota->importo)->toBe(30)
        ->and([$d['interessi']['righe'][0]['interessi'], $d['interessi']['righe'][0]['proposti']])->toBe([30, 37])
        ->and($d['interessi']['cambiati']['motivo'])->toBe('Accordo con l\'erede')
        ->and(accEmissione((int) $quota->rata_id)[0][2])->toBe(30);
    $estratto = collect(app(\App\Services\Gestionale\Addebiti\AddebitiIndividuali::class)->dellaPersona((int) $s['c']->id, (int) $s['persone']['Marco']->id))->firstWhere('rata_id', $quota->rata_id);
    expect($estratto['cifre_cambiate'])->toBe('Proposti € 0,37, registrati € 0,30 il 31/12/2026: «Accordo con l\'erede».');
    // I giorni fino al 31/12 restano registrati: la proposta dopo riparte dal 01/01, senza il riallineo (il giornale non è cambiato).
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2027-01-31'), $s, 'Marco');
    expect([$r['dal'], $r['interessi'], $r['riallineo']])->toBe(['2027-01-01', 25, null]);
});

it('decisione 493 (13), scelta 8: gli interessi di Ettore fino al decesso registrati dopo la successione passano a Elena e Marco per quota; spenta restano a lui', function () {
    $s = ideCaso($this);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Neri'], '2026-12-31');
    expect(accDi($p, $s, 'Neri')['dopo_il_decesso'])->toBe('Registrati, gli interessi di Ettore Fabbri fino al decesso passano a Elena e Marco per quota, come quelli già addebitati prima della successione.');
    $chiave = $s['persone']['Neri']->id . '|' . lcdRata($s, 1) . '|' . $s['unita']['U1']->id;
    $risposta = ideRegistraRighe($this, $s, $p, [$chiave])->assertCreated();
    expect($risposta->json('messaggio'))->toContain('Gli interessi di Ettore Fabbri fino al decesso, € 3,29, sono passati a Elena e Marco per quota');
    $rata = (int) $risposta->json('rate.0');
    $quote = RataQuote::where('rata_id', $rata)->orderBy('id')->get();
    // Con il codice di prima: una quota sola, di Ettore, da € 3,29.
    expect($quote->pluck('importo', 'anagrafica_id')->all())->toBe([$s['persone']['Neri']->id => 0, $s['persone']['Elena']->id => 165, $s['persone']['Marco']->id => 164])
        ->and(collect($passaggio->fresh()->registro['arretrato']['accessori'])->firstWhere('quota_id', $quote->first()->id)['dopo_la_successione'])->toBe('2026-12-31');
    // Il giornale: il conto dei crediti sale di € 3,29, e la posizione di Ettore resta a zero su quella rata.
    $saldo = fn (int $persona) => (int) DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
        ->where('cc.ruolo', 'crediti_condomini')->where('rs.rata_id', $rata)->where('rs.anagrafica_id', $persona)->selectRaw("SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END) as s")->value('s');
    expect([$saldo($s['persone']['Neri']->id), $saldo($s['persone']['Elena']->id), $saldo($s['persone']['Marco']->id)])->toBe([0, 165, 164]);
    // La proposta di Ettore non ha più giorni da proporre: i suoi sono registrati.
    expect(accDi(ideProposta($this, $s, ['Neri'], '2026-12-31'), $s, 'Neri'))->toBeNull();

    $s = ideCaso($this);
    ideRegoleCondominio($s, ['interessi_defunto_agli_eredi' => false]);
    ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Neri'], '2026-12-31');
    expect(accDi($p, $s, 'Neri')['dopo_il_decesso'])->toBe('Registrati, gli interessi di Ettore Fabbri fino al decesso restano a suo nome («eredi di Ettore Fabbri»): la sua posizione, chiusa con la successione, si riapre.');
    $rata = (int) ideRegistraRighe($this, $s, $p, [$s['persone']['Neri']->id . '|' . lcdRata($s, 1) . '|' . $s['unita']['U1']->id])->assertCreated()->json('rate.0');
    expect(RataQuote::where('rata_id', $rata)->pluck('importo', 'anagrafica_id')->all())->toBe([$s['persone']['Neri']->id => 329]);
});

it('scelta 6: con l\'accordo di rientro di Ettore in regola fino al decesso, di serie gli eredi contano anche quel debito; spenta no', function () {
    $s = ideCaso($this);
    // L'accordo del 15/07 copre tutta la rata 1 (€ 300,00), in regola al decesso.
    $this->travelTo('2026-07-15 12:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone']['Neri']->id, 'data_accordo' => '2026-07-15', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '300,00']],
        'scadenze' => [['data' => '2027-07-31', 'importo' => '150,00'], ['data' => '2027-08-31', 'importo' => '150,00']], 'decadenza_rate' => 2, 'decadenza_giorni' => 15,
    ])->assertCreated();
    ideRegistra($this, $s, ideSuccessione($s));
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2026-12-31'), $s, 'Marco');
    expect([$r['interessi'], $r['ereditata']['parte']])->toBe([37, 15000]);
    ideRegoleCondominio($s, ['anche_accordo' => false]);
    expect(ideEreditata(ideProposta($this, $s, ['Marco'], '2026-12-31'), $s, 'Marco'))->toBeNull();
});

it('scelta 1 con un tasso alto: gli interessi addebitati a Ettore e passati agli eredi prendono interessi dal decesso, solo con la scelta accesa', function () {
    $s = ideCaso($this);
    \App\Models\Gestionale\RegolaRecupero::where('condominio_id', $s['c']->id)->update(['tasso_annuo' => '50.0000']);
    $p = ideProposta($this, $s, ['Neri'], '2026-10-31');
    ideRegistraRighe($this, $s, $p, [$s['persone']['Neri']->id . '|' . lcdRata($s, 1) . '|' . $s['unita']['U1']->id])->assertCreated();
    $addebito = (int) RataQuote::where('anagrafica_id', $s['persone']['Neri']->id)->where('tipo', RataQuote::TIPO_ACCESSORIO)->value('importo');
    ideRegistra($this, $s, ideSuccessione($s));
    $accessorio = fn () => collect(accDi(ideProposta($this, $s, ['Marco'], '2026-12-31'), $s, 'Marco')['righe'])->first(fn ($r) => ($r['ereditata']['accessorio'] ?? false) === true);
    // Con il codice di prima, e con la scelta spenta: nessuna riga sugli interessi passati.
    expect($accessorio())->toBeNull();
    ideRegoleCondominio($s, ['interessi_sugli_interessi' => true]);
    $r = $accessorio();
    // E8 della seconda prova della beta.12: l'addebito passa a Marco il giorno del decesso e conta dal giorno dopo; prima la riga diceva «dal 17/11» e il suo
    // tratto partiva il 18/11.
    expect($r['dal'])->toBe('2026-11-18')->and($r['interessi'])->toBeGreaterThan(0)->and($r['tratti'][0]['dal'])->toBe('2026-11-18')
        ->and($r['tratti'][0]['causa'])->toBe('dopo_il_decesso')
        ->and($r['frase_ereditata'])->toStartWith('Di Ettore Fabbri, passata a te per il 50 % il 17/11/2026: interessi su questo addebito')
        ->and($r['frase_ereditata'])->toContain('dal 18/11/2026 al 31/12/2026');
});

it('D2: la frase del riallineo dice la ragione vera, la successione, quando gli interessi registrati oltre il decesso tornano con «si fermano al decesso»', function () {
    $s = ideCaso($this);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s, interessi: Subentro::INTERESSI_CONTINUANO));
    $p = ideProposta($this, $s, ['Neri'], '2026-12-31');
    ideRegistraRighe($this, $s, $p, [$s['persone']['Neri']->id . '|' . lcdRata($s, 1) . '|' . $s['unita']['U1']->id])->assertCreated();
    DB::table('subentri')->where('id', $passaggio->id)->update(['interessi_del_defunto' => Subentro::INTERESSI_AL_DECESSO]);
    $r = collect(accDi(ideProposta($this, $s, ['Neri'], '2026-12-31'), $s, 'Neri')['righe'])->firstWhere('rata_id', lcdRata($s, 1));
    // Con il codice di prima: «… con il giornale di oggi quei giorni darebbero € 3,29 …».
    expect($r['riallineo'])->toBe('Gli interessi registrati al 31/12/2026 erano € 4,03; con la successione del 17/11/2026, che ferma la proposta al 16/11/2026, quei '
        . 'giorni darebbero € 3,29: per cambiarli, annulla l\'addebito e rifai la proposta.');
});

it('D3: l\'annullamento dice che la proposta del defunto torna a contare dopo il decesso e avvisa degli interessi registrati con la scelta', function () {
    $s = ideCaso($this);
    ideRegoleCondominio($s, ['interessi_defunto_agli_eredi' => false]);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Neri'], '2026-12-31');
    ideRegistraRighe($this, $s, $p, [$s['persone']['Neri']->id . '|' . lcdRata($s, 1) . '|' . $s['unita']['U1']->id])->assertCreated();
    $storico = collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']['U1']->fresh())['subentri'])->firstWhere('id', $passaggio->id);
    // Con il codice di prima nessuna delle due frasi.
    expect($storico['annullabile']['si'])->toBeTrue()->and($storico['annullabile']['effetti'])->toContain('La proposta degli interessi di Ettore Fabbri torna a contare i giorni dopo il decesso, dal '
        . '17/11/2026, e quella di Elena e Marco non conta più il debito ereditato.');
    expect($storico['annullabile']['avvisi'])->toContain('Gli interessi di Ettore Fabbri registrati con la scelta «si fermano al decesso» '
        . "(«Interessi di mora al 31/12/2026 (2,00\u{00A0}% annuo, art. 12 del regolamento)», € 3,29, fino al 16/11/2026) restano come sono: dopo l'annullamento la proposta offre i giorni dal 17/11/2026.");
});

it('«Rate scadute»: Elena e Marco con il debito ereditato al posto di Ettore, e il totale lo conta una volta sola', function () {
    $s = ideCaso($this);
    $elenco = app(\App\Services\Gestionale\Debiti\ElencoRateScadute::class);
    $righe = fn () => $elenco->righe($s['c'], $elenco->filtriNormalizzati(['alla' => '2026-11-20']))->keyBy('anagrafica_id');
    $this->travelTo('2026-11-20 08:00:00');
    expect($righe()[$s['persone']['Neri']->id]['importo'])->toBe(30000);
    ideRegistra($this, $s, ideSuccessione($s));
    $this->travelTo('2026-11-20 12:00:00');
    $dopo = $righe();
    $nonEmesse = $elenco->nonEmesse($s['c'], '2026-11-20', null);
    // Con il codice di prima: Ettore € 300,00, gli eredi nessuna riga, e i loro saldi dell'arretrato contati a parte, anche i € 300,00.
    expect($dopo->has($s['persone']['Neri']->id))->toBeFalse()
        ->and($dopo[$s['persone']['Elena']->id]['importo'])->toBe(15000)
        ->and($dopo[$s['persone']['Marco']->id]['importo'])->toBe(15000)
        ->and($dopo[$s['persone']['Marco']->id]['rate'][0]['ereditato'])->toMatchArray(['da' => 'Ettore Fabbri', 'successione_del' => '2026-11-17'])
        ->and($dopo[$s['persone']['Marco']->id]['rate'][0]['scadenza'])->toBe('2026-04-30');
    // Rischio 4 della strada della decisione 529 (lo scettico della 1-bis): i saldi a debito non ancora in un piano per persona, gestione e unità, come li
    // assorbirà il piano, e senza la parte già mostrata come «ereditato» nel suo gruppo. Riga per riga contavano anche la metà positiva delle coppie che si
    // annullano (il + € 826,02 del conguaglio di Ettore): € 1.652,04 invece di € 450,00 (Marco 73,97 + 526,03 − 150,00; Elena ed Ettore a credito).
    $perGruppo = DB::table('saldi')->where('condominio_id', $s['c']->id)->where('is_applicato', false)->get(['anagrafica_id', 'gestione_id', 'immobile_id', 'saldo_iniziale'])
        ->groupBy(fn ($r) => $r->anagrafica_id . '|' . $r->gestione_id . '|' . $r->immobile_id)->map(fn ($g) => (int) $g->sum('saldo_iniziale'));
    expect($nonEmesse['saldi'])->toBe(45000)
        ->and($perGruppo[$s['persone']['Marco']->id . '|' . $s['g']->id . '|' . $s['unita']['U1']->id])->toBe(60000);
    // Dopo il piano che assorbe e la sua emissione, il debito è nelle rate degli eredi: niente più righe «ereditato».
    idePianoNuovo($this, $s);
    $this->travelTo('2027-04-30 12:00:00');
    $tardi = $elenco->righe($s['c'], $elenco->filtriNormalizzati(['alla' => '2027-04-30']))->keyBy('anagrafica_id');
    // Decisione 528 (N1 della terza prova): Ettore resta con i suoi € 4,38 della rata nuova (i giorni da proprietario), scaduti dal 01/04/2027. Con il
    // codice di prima spariva: la contropartita passata agli eredi era tolta due volte.
    expect(collect($tardi[$s['persone']['Marco']->id]['rate'] ?? [])->filter(fn ($r) => $r['ereditato'] !== null)->all())->toBe([])
        ->and($tardi[$s['persone']['Neri']->id]['importo'] ?? null)->toBe(438);
});

it('le scelte del condominio si salvano da «Recupero dei crediti», con il permesso di modificare il condominio; la pagina le mostra', function () {
    $s = ideCaso($this);
    $scelte = array_merge(RegoleDegliEredi::diSerie(), ['dal_decesso' => false, 'buco_continuano' => true]);
    $permesso = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => \App\Enums\Permission::EDIT_CONDOMINI->value, 'guard_name' => 'web']);
    // `adbAdmin` ha già «Modifica condomini» dal ruolo (Coda 755 (2)): per provare il 403 si toglie al ruolo.
    $this->user->roles->first()->revokePermissionTo($permesso);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.interessi-eredi', [$s['c']]), ['scelte' => $scelte])->assertForbidden();
    $this->user->givePermissionTo($permesso);
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.interessi-eredi', [$s['c']]), ['scelte' => ['dal_decesso' => false]])->assertSessionHasErrors(['scelte']);
    $this->actingAs($this->user)->post(route('admin.gestionale.recupero-crediti.interessi-eredi', [$s['c']]), ['scelte' => $scelte])->assertSessionHasNoErrors();
    expect(RegoleDegliEredi::delCondominio((int) $s['c']->id))->toBe($scelte);
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.recupero-crediti.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect($pagina['puo_modificare_eredi'])->toBeTrue()
        ->and(collect($pagina['interessi_eredi']['voci'])->firstWhere('chiave', 'dal_decesso'))->toMatchArray(['valore' => false, 'adesso' => 'Adesso: gli interessi degli eredi partono dal giorno dopo il decesso.']);
});

it('decisione 493 (13) e D3: annullata la successione, gli interessi di Ettore passati agli eredi tornano a lui, e l\'annullamento lo ha detto prima', function () {
    $s = ideCaso($this);
    $passaggio = ideRegistra($this, $s, ideSuccessione($s));
    $p = ideProposta($this, $s, ['Neri'], '2026-12-31');
    $rata = (int) ideRegistraRighe($this, $s, $p, [$s['persone']['Neri']->id . '|' . lcdRata($s, 1) . '|' . $s['unita']['U1']->id])->assertCreated()->json('rate.0');
    $storico = collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']['U1']->fresh())['subentri'])->firstWhere('id', $passaggio->id);
    expect($storico['annullabile']['si'])->toBeTrue()
        ->and($storico['annullabile']['avvisi'])->toContain('Gli interessi di Ettore Fabbri fino al decesso registrati dopo la successione e passati agli eredi (€ 3,29) tornano a suo nome.');
    $this->travelTo('2027-01-02 10:00:00');
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertRedirect()->assertSessionHas('message');
    expect(RataQuote::where('rata_id', $rata)->pluck('importo', 'anagrafica_id')->all())->toBe([$s['persone']['Neri']->id => 329])
        // Il giornale della rata dell'addebito: solo l'emissione a Ettore.
        ->and((int) DB::table('righe_scritture as rs')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')->where('cc.ruolo', 'crediti_condomini')
            ->where('rs.rata_id', $rata)->selectRaw("SUM(CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END) as s")->value('s'))->toBe(329);
});

it('il riquadro dei sei mesi e la tesoreria leggono come «Rate scadute»: gli eredi al posto di Ettore, le cifre attese le stesse', function () {
    $s = ideCaso($this);
    $this->travelTo('2026-11-20 08:00:00');
    $tesoreria = fn () => app(\App\Services\Treasury\TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray();
    $prima = $tesoreria();
    ideRegistra($this, $s, ideSuccessione($s));
    $this->travelTo('2026-11-20 12:00:00');
    $dopo = $tesoreria();
    $chi = collect($dopo['morositaImpattanti'])->pluck('importoCents', 'anagraficaId');
    // Con il codice di prima Ettore restava fra i morosi con € 300,00, e gli eredi non c'erano.
    expect($chi->has($s['persone']['Neri']->id))->toBeFalse()
        ->and($chi[$s['persone']['Marco']->id] ?? null)->toBe(15000)
        ->and($dopo['incassiScadutiCents'])->toBe($prima['incassiScadutiCents']);
    $seiMesi = app(\App\Services\Gestionale\Debiti\SeiMesiPerAgire::class)->perCondominio($s['c']);
    $persone = collect($seiMesi['righe'])->flatMap(fn ($r) => $r['persone'] ?? [])->groupBy('anagrafica_id')->map(fn ($g) => (int) $g->sum('cifra'));
    expect($persone->has($s['persone']['Neri']->id))->toBeFalse()
        ->and($persone[$s['persone']['Marco']->id] ?? null)->toBe(15000);
});

it('D2: dopo un annullamento che riapre l\'accordo di rientro in regola, la frase del riallineo dice l\'accordo, non il giornale', function () {
    $s = ideCaso($this);
    ideRegoleCondominio($s, ['interessi_defunto_agli_eredi' => false]);
    $this->travelTo('2026-07-15 12:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone']['Neri']->id, 'data_accordo' => '2026-07-15', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '300,00']],
        'scadenze' => [['data' => '2027-07-31', 'importo' => '150,00'], ['data' => '2027-08-31', 'importo' => '150,00']], 'decadenza_rate' => 2, 'decadenza_giorni' => 15,
    ])->assertCreated();
    // La successione con l'accordo «vale come una decadenza»: i giorni dell'accordo tornano, fino al 16/11 (€ 3,29), e si registrano.
    $passaggio = ideRegistra($this, $s, ideSuccessione($s, accordo: \App\Models\Gestionale\AccordoRientro::SUCCESSIONE_DECADENZA));
    $p = ideProposta($this, $s, ['Neri'], '2026-12-31');
    ideRegistraRighe($this, $s, $p, [$s['persone']['Neri']->id . '|' . lcdRata($s, 1) . '|' . $s['unita']['U1']->id])->assertCreated();
    $this->travelTo('2026-12-31 11:00:00');
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertRedirect();
    $r = collect(accDi(ideProposta($this, $s, ['Neri'], '2026-12-31'), $s, 'Neri')['righe'])->firstWhere('rata_id', lcdRata($s, 1));
    // L'accordo è di nuovo in regola: i giorni registrati dal 15/07 al 16/11 sono nell'accordo. Con il codice di prima: «… con il giornale di oggi …».
    expect($r['riallineo'])->toBe('Gli interessi registrati al 31/12/2026 erano € 3,29; con l\'accordo di rientro in regola dal 15/07/2026, che tiene fuori i giorni da lì in '
        . 'poi, quei giorni darebbero € 1,23: per cambiarli, annulla l\'addebito e rifai la proposta.');
});
