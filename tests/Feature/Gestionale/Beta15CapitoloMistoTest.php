<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un capitolo di spesa con una voce di entrata sotto. Il caso: capitolo «Servizi» (spesa) con le voci «Spese generali» (spesa) e «Affitto antenna»
 * (entrata, € 300,00 incassati da terzi). Il riepilogo per competenza teneva delle somme delle voci solo le colonne del capitolo: l'incassato della
 * voce di entrata spariva, e il controllo dei costi non tornava. Le voci già create si leggono in una riga loro fra le entrate («Servizi — entrate»);
 * le nuove il modulo le rifiuta.
 */

use App\Models\Gestionale\Conto;
use App\Services\Gestionale\BloccoDellaVoce;
use App\Services\Gestionale\RiepilogoPerCompetenza;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/Beta52VoceEntrataScenari.php';

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07 10:00', 'Europe/Rome'));
});

/** Il capitolo «Servizi» con sotto la spesa e l'entrata dello scenario, come lo lasciavano le versioni di prima; l'incasso dell'antenna dalla rotta. */
function b15cmScenario($test): array
{
    $s = be52Scenario($test);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $s['servizi'] = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Servizi', 'tipo' => 'spesa', 'importo' => 0, 'is_capitolo' => true]);
    DB::table('conti')->whereIn('id', [$s['spesa']->id, $s['entrata']->id])->update(['parent_id' => $s['servizi']->id]);
    $test->actingAs($test->user)->post(route('admin.gestionale.incassi-da-terzi.store', [$s['c']]), [
        'esercizio_id' => $s['e']->id, 'conto_id' => $s['entrata']->id, 'cassa_id' => $s['cassa']->id, 'data_operazione' => '2026-02-15',
        'importo' => 300.00, 'anagrafica_id' => null, 'causale' => 'Rimborso dell\'assicurazione',
    ])->assertSessionHasNoErrors()->assertRedirect();

    return $s;
}

it('l\'incasso di € 300,00 della voce di entrata sotto un capitolo di spesa sta fra le entrate, nella riga «Servizi — entrate», e il controllo dei costi torna', function () {
    $s = b15cmScenario($this);
    $r = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']);
    $b = $r['blocchi'][0];

    $misto = collect($b['entrate'])->firstWhere('nome', 'Servizi — entrate');
    expect($misto)->not->toBeNull()
        ->and($misto['tipo'])->toBe('entrata')
        ->and($misto['incassato'])->toBe(30000)
        ->and(collect($misto['voci'])->pluck('nome')->all())->toBe(['Affitto antenna'])
        ->and($b['totale_entrate']['incassato'])->toBe(30000);

    // Il capitolo di spesa tiene le sue voci di spesa, senza l'entrata.
    $capitolo = collect($b['spese'])->firstWhere('voce_id', $s['servizi']->id);
    expect(collect($capitolo['voci'])->pluck('nome')->all())->toBe(['Spese generali'])
        ->and($r['riconciliazione']['differenza'])->toBe(0);

    // La stampa dice la riga e la cifra.
    $html = view('pdf.gestionale.rendiconto_riepilogo', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'riepilogo' => $r, 'dettaglio' => false])->render();
    expect(vrmTesto($html))->toContain('Servizi — entrate')->toContain('€ 300,00');
});

it('la voce di entrata nuova sotto un capitolo di spesa è rifiutata dal modulo; sotto un capitolo di entrata passa', function () {
    $s = be52Scenario($this);
    $servizi = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Servizi', 'tipo' => 'spesa', 'importo' => 0, 'is_capitolo' => true]);
    $proventi = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Proventi', 'tipo' => 'entrata', 'importo' => 0, 'is_capitolo' => true]);
    $dati = fn (int $padre, string $tipo, string $nome) => [
        'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => $tipo, 'importo' => '0,00', 'descrizione' => '', 'note' => '',
        'parent_id' => $padre, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => true, 'richiedeGiaVersato' => false,
    ];
    $rotta = route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]);

    $this->actingAs($this->user)->post($rotta, $dati($servizi->id, 'entrata', 'Rimborso assicurazione'))
        ->assertSessionHasErrors(['tipo' => BloccoDellaVoce::rifiutoDelTipoSottoIlCapitolo('entrata', 'Servizi', true)]);
    expect(BloccoDellaVoce::rifiutoDelTipoSottoIlCapitolo('entrata', 'Servizi', true))
        ->toBe('Una voce di entrata va sotto un capitolo di entrata, e «Servizi» è un capitolo di spesa: cambia il tipo del capitolo in «Entrata» con «Modifica» dalla sua scheda, o crea la voce fuori dal capitolo.')
        ->and(Conto::where('nome', 'Rimborso assicurazione')->exists())->toBeFalse();

    $this->actingAs($this->user)->post($rotta, $dati($proventi->id, 'entrata', 'Rimborso assicurazione'))->assertSessionHasNoErrors();
    // «Proventi» ha ora una voce di entrata: cambiarne il tipo la lascerebbe sotto un capitolo di spesa, e la frase dice solo la strada che passa.
    $this->actingAs($this->user)->post($rotta, $dati($proventi->id, 'spesa', 'Spese della pratica'))
        ->assertSessionHasErrors(['tipo' => 'Una voce di spesa va sotto un capitolo di spesa, e «Proventi» è un capitolo di entrata: crea la voce fuori dal capitolo.']);
});

/** Il capitolo dalla sua scheda: «Modifica», il tipo dato, il resto com'è. */
function b15cmTipoDelCapitolo($test, array $s, Conto $capitolo, string $tipo)
{
    return $test->actingAs($test->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $capitolo]), [
        'nome' => $capitolo->nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => $tipo, 'importo' => null, 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null,
        'isCapitolo' => true, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ]);
}

it('le strade che la frase nomina passano: il capitolo vuoto nato «Spesa» si porta a «Entrata» anche con un piano approvato che non lo tiene, e la voce fuori dal capitolo si crea', function () {
    $s = be52Scenario($this);
    $piano = be52PianoDallaRotta($this, $s);
    DB::table('piani_rate')->where('id', $piano->id)->update(['stato' => 'approvato']);
    // Nato dopo il piano, come un capitolo importato tutto «Spesa»: il piano non lo tiene.
    $proventi = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Proventi', 'tipo' => 'spesa', 'importo' => 0, 'is_capitolo' => true]);
    $dati = fn (?int $padre, string $nome) => [
        'nome' => $nome, 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'entrata', 'importo' => '0,00', 'descrizione' => '', 'note' => '',
        'parent_id' => $padre, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => $padre !== null, 'richiedeGiaVersato' => false,
    ];
    $rotta = route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]);

    $this->actingAs($this->user)->post($rotta, $dati($proventi->id, 'Canone del ripetitore'))
        ->assertSessionHasErrors(['tipo' => BloccoDellaVoce::rifiutoDelTipoSottoIlCapitolo('entrata', 'Proventi', true)]);

    // La seconda strada: la voce fuori dal capitolo.
    $this->actingAs($this->user)->post($rotta, $dati(null, 'Contributo comunale'))->assertSessionHasNoErrors();
    expect(Conto::where('piano_conto_id', $s['pc']->id)->where('nome', 'Contributo comunale')->value('parent_id'))->toBeNull();

    // La prima: il tipo del capitolo, poi la voce sotto di lui.
    b15cmTipoDelCapitolo($this, $s, $proventi, 'entrata')->assertSessionHasNoErrors()->assertRedirect();
    expect($proventi->fresh()->tipo)->toBe('entrata');
    $this->actingAs($this->user)->post($rotta, $dati($proventi->id, 'Canone del ripetitore'))->assertSessionHasNoErrors();
    expect(Conto::where('piano_conto_id', $s['pc']->id)->where('nome', 'Canone del ripetitore')->value('parent_id'))->toBe($proventi->id);
});

it('la frase non nomina il tipo del capitolo quando non si cambia: un piano approvato tiene il capitolo, o il capitolo ha voci del suo tipo', function () {
    $s = be52Scenario($this);
    $dati = fn (int $padre) => [
        'nome' => 'Affitto antenna', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'entrata', 'importo' => '0,00', 'descrizione' => '', 'note' => '',
        'parent_id' => $padre, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => true, 'richiedeGiaVersato' => false,
    ];
    $rotta = route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $s['pc']]);
    $soloFuori = 'Una voce di entrata va sotto un capitolo di entrata, e «Proventi» è un capitolo di spesa: crea la voce fuori dal capitolo.';

    // Con una voce di spesa sotto (come le voci che l'importazione scrive tutte «Spesa»): il cambio la lascerebbe sotto un capitolo di entrata.
    $proventi = Conto::create(['piano_conto_id' => $s['pc']->id, 'nome' => 'Proventi', 'tipo' => 'spesa', 'importo' => 0, 'is_capitolo' => true]);
    DB::table('conti')->where('id', $s['spesa']->id)->update(['parent_id' => $proventi->id]);
    $this->actingAs($this->user)->post($rotta, $dati($proventi->id))->assertSessionHasErrors(['tipo' => $soloFuori]);

    // Tenuto da un piano approvato: il tipo non si cambia (lo dice il rifiuto della modifica), e il capitolo non prende voci nuove (lo dice `parent_id`).
    DB::table('conti')->where('id', $s['spesa']->id)->update(['parent_id' => null]);
    $piano = be52PianoDallaRotta($this, $s);
    DB::table('piani_rate')->where('id', $piano->id)->update(['stato' => 'approvato']);
    expect(Conto::pianiCheBloccanoLaVoce([(int) $proventi->id])[(int) $proventi->id] ?? [])->not->toBe([]);
    $this->actingAs($this->user)->post($rotta, $dati($proventi->id))->assertSessionHasErrors(['tipo' => $soloFuori, 'parent_id']);
    b15cmTipoDelCapitolo($this, $s, $proventi, 'entrata')->assertSessionHasErrors();
    expect($proventi->fresh()->tipo)->toBe('spesa');
});

it('la modifica resta com\'era: una voce di entrata portata sotto un capitolo di spesa passa, e il riepilogo la legge fra le entrate', function () {
    // Il cambio del tipo e il padre sono la strada delle spese scritte con il meno (le prove delle spese negative la percorrono): la modifica
    // non si rifiuta, e la cifra non sparisce più perché il riepilogo legge la voce nella riga «Servizi — entrate».
    $s = b15cmScenario($this);
    DB::table('conti')->where('id', $s['entrata']->id)->update(['parent_id' => null]);
    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $s['entrata']]), [
        'nome' => 'Affitto antenna', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'entrata', 'importo' => '240,00', 'descrizione' => '', 'note' => '',
        'parent_id' => $s['servizi']->id, 'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => true, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();

    $r = app(RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $s['e']);
    expect(collect($r['blocchi'][0]['entrate'])->firstWhere('nome', 'Servizi — entrate')['incassato'] ?? null)->toBe(30000);
});
