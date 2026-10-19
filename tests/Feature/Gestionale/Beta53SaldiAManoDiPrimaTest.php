<?php

/**
 * 1.11.0-beta.53 — annullare un passaggio dopo cui sono stati scritti a mano saldi non legati (i saldi della strada a mano scritti prima che
 * il legame esistesse).
 *
 * La coincidenza non prova che una riga sia di quel passaggio: l'annullamento non la tocca, ma la nomina. Cerca, sulle unità del passaggio (il
 * capo e i box passati con lui), i saldi a mano non legati a nessun passaggio e non scritti da un passaggio, di una persona che ha o ha avuto
 * una riga su quell'unità, diversi da zero e scritti dalla registrazione del passaggio in poi; li dice con la persona, la cifra a parole («a
 * credito di», «a debito di») e la gestione, e con il piano se uno li ha già assorbiti. Vale per ogni passaggio: la fermata dopo «Scrivi»,
 * quella della 31.11, il defunto, i passaggi di prima della .50 senza le chiavi della fermata nel registro. Senza righe da nominare non dice
 * niente: il messaggio è un successo. Gli avvisi di prima sul registro della fermata («se per questo hai registrato un saldo manuale dal
 * Wallet… Toglilo dal Wallet») non ci sono più.
 *
 * Fra un passo e l'altro l'orologio avanza di un minuto: la ricerca guarda la data di creazione, al secondo.
 *
 * Che cosa NON copre: un saldo scritto prima del passaggio e modificato dopo (non si nomina: la data di modifica cambia anche quando un piano
 * lo assorbe, e non dice chi l'ha cambiato); una riga scritta nello stesso secondo della registrazione; le righe legate (in
 * `Beta53AnnullaConRigheLegateTest`); MySQL.
 */

use App\Models\Gestionale\Subentro;
use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Beta51PianoRateScenario.php';
require_once __DIR__ . '/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    sl53Amministratore($this);
});

const SL53M_VENDITA = 'Dalla registrazione di questo passaggio sono stati scritti a mano dal Wallet, su Interno 1, saldi non legati a nessun passaggio: € 60,16 a credito di Ugo e € 60,16 a debito di Elsa (gestione Ordinaria 2026). Se erano per questo passaggio, annullarlo non li toglie: rivedili dal Wallet, prima o dopo.';

/** Le due righe della strada a mano scritte come prima del legame, dopo la vendita: senza passare dalla porta. */
function sl53mDueRigheDiPrima(array $s): array
{
    return [(int) sl53SaldoDiPrima($s, 'Ugo', -6016)->id, (int) sl53SaldoDiPrima($s, 'Elsa', 6016)->id];
}

it('vendita con la strada a mano di prima: prima di confermare e nel messaggio l\'annullamento nomina le due righe, non il riporto, e non le tocca', function () {
    $s = sl53Scenario($this, ['Ugo' => 12345]);
    $ids = sl53mDueRigheDiPrima($s);
    $prima = sl53Righe($s);

    $avvisi = fds50Storico($s, $s['vendita'])['annullabile']['avvisi'] ?? [];
    expect(in_array(SL53M_VENDITA, $avvisi, true))->toBeTrue('Gli avvisi non nominano le due righe: ' . implode(' | ', $avvisi));

    $m = sl53Messaggio(sl53Annulla($this, $s, $s['vendita']));

    expect($m['type'])->toBe('warning')
        ->and(str_contains($m['message'], SL53M_VENDITA))->toBeTrue($m['message'])
        ->and(str_contains($m['message'], '€ 123,45'))->toBeFalse($m['message'])
        ->and(str_contains($m['message'], 'Toglilo dal Wallet'))->toBeFalse($m['message'])
        ->and(Saldo::whereIn('id', $ids)->orderBy('id')->pluck('saldo_iniziale')->map(fn ($c) => (int) $c)->all())->toBe([-6016, 6016])
        ->and(collect(sl53Righe($s))->whereIn(0, $ids)->values()->all())->toBe(collect($prima)->whereIn(0, $ids)->values()->all());
});

it('la fermata della 31.11 (quote di un piano della 1.7, senza le chiavi della fermata nel registro): l\'annullamento nomina la riga di € 15,00', function () {
    // Lo scenario della 31.11 (`ConguaglioQuoteSenzaComposizioneTest`): il riporto di € 300,00 del venditore è scritto prima e non si nomina.
    $s = ruScenario('prima_rata', 30000);
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->update(['regole_calcolo' => null]);
    ruEmetti($s);
    $this->travel(1)->minutes();
    $sub = ruRegistra($this, $s, ['tipo' => 'vendita', 'riga_uscente_id' => $s['rigaV'], 'anagrafica_entrante_id' => $s['a']->id, 'decorrenza' => '2026-05-01', 'quota' => 100,
        'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-06', 'estremi_titolo' => 'rep. 9', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Vendita letta']);
    expect(array_keys($sub->registro))->not->toContain('fermata_dopo_scrivi');
    $this->travel(1)->minutes();
    $this->actingAs($this->user)->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $s['a']->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 1500,
    ])->assertSessionHasNoErrors();

    $r = $this->actingAs($this->user)->from('/storico')->delete(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $sub]), ['nota_annullamento' => 'Rogito non stipulato, annullo la vendita']);
    $m = sl53Messaggio($r);

    expect($m['type'])->toBe('warning')
        ->and(str_contains($m['message'], 'Dalla registrazione di questo passaggio'))->toBeTrue($m['message'])
        ->and(str_contains($m['message'], '€ 15,00 a debito di Acquirente Elsa'))->toBeTrue($m['message'])
        ->and(str_contains($m['message'], '€ 300,00'))->toBeFalse($m['message'])
        ->and(Saldo::where('anagrafica_id', $s['a']->id)->where('saldo_iniziale', 1500)->count())->toBe(1);
});

it('un passaggio senza le chiavi della fermata nel registro (come quelli di prima della .50): l\'avviso c\'è lo stesso', function () {
    $s = sl53Scenario($this);
    $registro = $s['vendita']->registro;
    unset($registro['fermata_dopo_scrivi']);
    $s['vendita']->forceFill(['registro' => $registro])->save();
    sl53mDueRigheDiPrima($s);

    $avvisi = fds50Storico($s, $s['vendita'])['annullabile']['avvisi'] ?? [];

    expect(in_array(SL53M_VENDITA, $avvisi, true))->toBeTrue('Gli avvisi non nominano le due righe: ' . implode(' | ', $avvisi));
});

it('cancello del defunto: annullando la successione si nominano le tre righe scritte a mano dopo, con le cifre e il verso', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria 2026', applicaSaldi: true);
    $s->titolare('A', 'ugo');
    $this->travel(1)->minutes();
    $succ = $s->passaggio('successione', 'A', 'ugo', null, '2026-05-01', ['eredi' => ['anna' => 60, 'bruno' => 40], 'arretrato' => 'eredi', 'riferimento' => 'anna']);
    $this->travel(1)->minutes();
    $s->saldo('ugo', 'A', 'Ordinaria 2026', -39452);
    $s->saldo('anna', 'A', 'Ordinaria 2026', 23671);
    $s->saldo('bruno', 'A', 'Ordinaria 2026', 15781);

    $r = $s->t->actingAs($s->admin)->from('/storico')->delete(route('admin.gestionale.immobili.passaggi.annulla', [$s->c, $s->unita['A'], $succ]), ['nota_annullamento' => 'Successione registrata per sbaglio']);
    $m = sl53Messaggio($r);

    expect($m['type'])->toBe('warning')
        ->and(str_contains($m['message'], 'Dalla registrazione di questo passaggio sono stati scritti a mano dal Wallet, su Interno A'))->toBeTrue($m['message'])
        ->and(str_contains($m['message'], '€ 394,52 a credito di Ugo'))->toBeTrue($m['message'])
        ->and(str_contains($m['message'], '€ 236,71 a debito di Anna'))->toBeTrue($m['message'])
        ->and(str_contains($m['message'], '€ 157,81 a debito di Bruno'))->toBeTrue($m['message'])
        ->and(Saldo::whereNull('subentro_id')->where('immobile_id', $s->unita['A']->id)->count())->toBe(3);
});

it('niente da dire, niente avviso: senza righe a mano dopo la vendita il messaggio è un successo e non nomina il Wallet', function () {
    $s = sl53Scenario($this, ['Ugo' => 12345]);

    $avvisi = fds50Storico($s, $s['vendita'])['annullabile']['avvisi'] ?? [];
    $m = sl53Messaggio(sl53Annulla($this, $s, $s['vendita']));

    expect($avvisi)->toBe([])
        ->and($m['type'])->toBe('success')
        ->and(str_contains($m['message'], 'Wallet'))->toBeFalse($m['message']);
});

it('una riga a mano già assorbita da un piano si nomina con il suo piano', function () {
    $s = sl53Scenario($this, vendita: ['rinuncia_conguaglio' => true, 'nota_conguaglio' => 'Le parti hanno regolato fra loro il conguaglio della vendita']);
    sl53mDueRigheDiPrima($s);
    sl53SecondoPiano($s);

    $avvisi = implode(' | ', fds50Storico($s, $s['vendita'])['annullabile']['avvisi'] ?? []);

    expect(str_contains($avvisi, '€ 60,16 a debito di Elsa, già nel piano «Conguaglio 2026»'))->toBeTrue($avvisi)
        ->and(str_contains($avvisi, '€ 60,16 a credito di Ugo, già nel piano «Conguaglio 2026»'))->toBeTrue($avvisi);
});

it('una riga scritta a mano sul box passato con la vendita si nomina con il nome del box', function () {
    // La ricerca guarda tutte le unità del passaggio, non solo quella del capo.
    $s = sl53Scenario($this, box: true);
    sl53SaldoDiPrima($s, 'Elsa', 1000, immobileId: (int) $s['box']->id);

    $avvisi = implode(' | ', fds50Storico($s, $s['vendita'])['annullabile']['avvisi'] ?? []);

    expect(str_contains($avvisi, 'Box 1'))->toBeTrue($avvisi)
        ->and(str_contains($avvisi, '€ 10,00 a debito di Elsa'))->toBeTrue($avvisi)
        ->and(str_contains($avvisi, 'non legat'))->toBeTrue($avvisi);
});
