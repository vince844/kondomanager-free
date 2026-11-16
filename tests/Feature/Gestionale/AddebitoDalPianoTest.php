<?php

/**
 * L'addebito dal riquadro delle quote non assegnate del piano: la finestra propone la persona (chi era titolare prima dei giorni senza titolare, e
 * accanto chi dopo; per una straordinaria chi era titolare il giorno della delibera; mai l'inquilino), l'importo che resta e la causale; le quote
 * senza un'unità (la voce senza tabella, la tabella senza millesimi) non si addebitano; il compito «Quote non assegnate» si chiude quando gli addebiti
 * le coprono, e si riapre se un annullamento le scopre.
 *
 * Il caso: Via Verdi con U3 senza titolare a dicembre (Verdi esce il 30/11): le quote non assegnate di U3 sono Pulizie € 50,96 e Manutenzioni € 16,99,
 * € 67,95 in tutto.
 */

use App\Enums\EventoTipo;
use App\Models\Anagrafica;
use App\Models\Evento;
use App\Models\Gestionale\PianoRate;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use App\Services\Gestionale\Addebiti\PersoneDellAddebito;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AddebitiScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** La proposta della finestra per le quote date del piano. */
function adbPropostaDelPiano($test, array $s, array $chiavi)
{
    return $test->actingAs($test->user)->getJson(route('admin.gestionale.addebiti.proposta', [$s['c']]) . '?' . http_build_query(['piano_rate_id' => $s['piano']->id, 'chiavi' => $chiavi]));
}

/** Una persona nuova del condominio, titolare o inquilina di un'unità dal giorno al giorno. */
function adbTitolare(array $s, string $nome, string $unita, string $ruolo, string $dal, ?string $al = null): Anagrafica
{
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . '-' . $s['c']->id . '@test.it', 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => strtoupper(substr($nome, 0, 3)) . 'ADB' . str_pad((string) ($s['c']->id * 10 + strlen($nome)), 10, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $p->id, 'immobile_id' => $s['unita'][$unita]->id, 'tipologia' => $ruolo, 'quota' => 100, 'attivo' => true,
        'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);

    return $p;
}

it('la finestra propone Verdi, titolare fino al 30/11 prima dei giorni senza titolare, gli importi che restano e le causali; addebitate, la stessa quota non si addebita oltre', function () {
    $s = adbVerdiFinoANovembre($this);
    $quote = adbQuoteNonAssegnate($s);
    $chiavi = [$quote['Pulizie']['chiave'], $quote['Manutenzioni']['chiave']];
    $this->travelTo('2026-12-20 10:00:00');

    $p = adbPropostaDelPiano($this, $s, $chiavi)->assertOk()->json();
    expect($p['unita'])->toBe(['id' => (int) $s['unita']['U3']->id, 'nome' => 'Int. U3'])
        ->and($p['persone'][0])->toBe(['anagrafica_id' => (int) $s['persone']['Verdi']->id, 'nome' => 'Verdi', 'perche' => 'titolare fino al 30/11/2026, prima dei giorni senza titolare'])
        ->and(count($p['persone']))->toBe(1)
        ->and(collect($p['voci'])->map(fn ($v) => [$v['importo_cents'], $v['resta_cents'], $v['causale']])->all())->toBe([
            [5096, 5096, 'Quota non assegnata: Pulizie, Int. U3, 31 giorni senza titolare'],
            [1699, 1699, 'Quota non assegnata: Manutenzioni, Int. U3, 31 giorni senza titolare'],
        ])
        ->and($p['dove']['proposta'])->toBe(['gestione_id' => (int) $s['g']->id, 'esercizio_id' => (int) $s['e']->id, 'nome' => 'Ordinaria 2026 (Esercizio 2026)',
            'inizio' => '2026-01-01', 'fine' => '2026-12-31']);

    // Una parte di Pulizie, poi il resto più un centesimo: rifiutato con quanto resta.
    adbRegistra($this, $s, [['chi' => 'Verdi', 'unita' => 'U3', 'euro' => '30,00', 'chiave' => $quote['Pulizie']['chiave'], 'piano_origine_id' => $s['piano']->id]])->assertCreated();
    expect(adbPropostaDelPiano($this, $s, [$quote['Pulizie']['chiave']])->json('voci.0.resta_cents'))->toBe(2096);
    adbRegistra($this, $s, [['chi' => 'Verdi', 'unita' => 'U3', 'euro' => '20,97', 'chiave' => $quote['Pulizie']['chiave'], 'piano_origine_id' => $s['piano']->id]])
        ->assertStatus(422)->assertJsonPath('errors.addebiti.0', 'Della quota non assegnata si possono addebitare al più € 20,96.');
    // Su un'altra unità no.
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '1,00', 'chiave' => $quote['Pulizie']['chiave'], 'piano_origine_id' => $s['piano']->id]])
        ->assertStatus(422)->assertJsonPath('errors.addebiti.0', 'La quota non assegnata è di un\'altra unità.');
});

it('la persona proposta: chi era titolare prima e chi dopo i giorni senza titolare; alla delibera per una straordinaria; mai l\'inquilino', function () {
    $s = cpcViaVerdi($this);
    $u3 = (int) $s['unita']['U3']->id;
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Verdi'])->update(['data_fine' => '2026-11-30']);
    adbTitolare($s, 'Gialli', 'U3', 'proprietario', '2026-12-16');
    adbTitolare($s, 'Ines', 'U3', 'inquilino', '2026-01-01');
    $persone = app(PersoneDellAddebito::class);

    // Fra il 01/12 e il 15/12 U3 non ha un titolare: prima Verdi, dopo Gialli; Ines è inquilina e non c'è.
    expect($persone->proposte($u3, '2026-01-01', '2026-12-31', null, '2026-12-20'))->toBe([
        ['anagrafica_id' => (int) $s['persone']['Verdi']->id, 'nome' => 'Verdi', 'perche' => 'titolare fino al 30/11/2026, prima dei giorni senza titolare'],
        ['anagrafica_id' => (int) Anagrafica::where('nome', 'Gialli')->value('id'), 'nome' => 'Gialli', 'perche' => 'titolare dal 16/12/2026, dopo i giorni senza titolare'],
    ])
        // La straordinaria: chi era titolare il giorno della delibera, poi chi lo è oggi.
        ->and(array_column($persone->proposte($u3, null, null, '2026-03-14', '2026-12-20'), 'perche'))
        ->toBe(['titolare il giorno della delibera, 14/03/2026', 'titolare oggi'])
        ->and($persone->puoRicevere((int) Anagrafica::where('nome', 'Ines')->value('id'), $u3))->toBeFalse()
        ->and($persone->puoRicevere((int) $s['persone']['Verdi']->id, $u3))->toBeTrue()
        ->and($persone->puoRicevere((int) $s['persone']['Neri']->id, $u3))->toBeFalse();
});

it('le quote senza un\'unità (la voce senza tabella, la tabella senza millesimi) non si addebitano: la finestra e la registrazione lo dicono', function () {
    $s = adbVerdiFinoANovembre($this);
    $voci = $s['piano']->quote_non_assegnate['voci'];
    $voci[] = ['chiave' => 'conto_senza_tabella||9||||', 'motivo' => 'conto_senza_tabella', 'immobile_id' => null, 'immobile_nome' => null, 'conto_id' => 9,
        'conto_nome' => 'Ascensore', 'tabella_id' => null, 'tabella_nome' => null, 'ruolo_richiesto' => null, 'importo_cents' => 12000, 'giorni' => null,
        'riga_descrizione' => null, 'segno' => 1, 'accettata' => true];
    $voci[] = ['chiave' => 'tabella_senza_millesimi||9|4|||', 'motivo' => 'tabella_senza_millesimi', 'immobile_id' => null, 'immobile_nome' => null, 'conto_id' => 9,
        'conto_nome' => 'Ascensore', 'tabella_id' => 4, 'tabella_nome' => 'Scala B', 'ruolo_richiesto' => null, 'importo_cents' => 3000, 'giorni' => null,
        'riga_descrizione' => null, 'segno' => 1, 'accettata' => true];
    $s['piano']->forceFill(['quote_non_assegnate' => ['voci' => $voci] + $s['piano']->quote_non_assegnate])->save();
    $this->travelTo('2026-12-20 10:00:00');

    $daAddebitare = collect(app(AddebitiIndividuali::class)->quoteDaAddebitare($s['piano']->fresh()))->mapWithKeys(fn ($v) => [$v['motivo'] . ' ' . ($v['conto_nome'] ?? '') => $v['addebitabile']])->all();
    expect($daAddebitare)->toBe(['giorni_senza_titolare Pulizie' => true, 'giorni_senza_titolare Manutenzioni' => true, 'conto_senza_tabella Ascensore' => false, 'tabella_senza_millesimi Ascensore' => false]);
    foreach (['conto_senza_tabella||9||||', 'tabella_senza_millesimi||9|4|||'] as $chiave) {
        adbPropostaDelPiano($this, $s, [$chiave])->assertStatus(422)->assertJsonPath('errors.chiavi.0', AddebitiIndividuali::FRASE_SENZA_UNITA);
        adbRegistra($this, $s, [['chi' => 'Verdi', 'unita' => 'U3', 'euro' => '10,00', 'chiave' => $chiave, 'piano_origine_id' => $s['piano']->id]])
            ->assertStatus(422)->assertJsonPath('errors.addebiti.0', AddebitiIndividuali::FRASE_SENZA_UNITA);
    }
    expect(PianoRate::degliAddebiti()->exists())->toBeFalse();
});

it('il compito «Quote non assegnate» del piano resta aperto con quanto manca, si chiude quando gli addebiti coprono € 67,95, si riapre se uno si annulla', function () {
    $s = adbVerdiFinoANovembre($this);
    $quote = adbQuoteNonAssegnate($s);
    $compito = fn () => Evento::where('tipo', EventoTipo::SCOPERTO_DOCUMENTATO->value)->where('meta->context->piano_rate_id', $s['piano']->id)->firstOrFail();
    expect($compito()->is_completed)->toBeFalse()->and($compito()->meta['context']['importo_cents'])->toBe(6795);
    $partenza = $compito()->description;
    $this->travelTo('2026-12-20 10:00:00');

    adbRegistra($this, $s, [['chi' => 'Verdi', 'unita' => 'U3', 'euro' => '50,96', 'chiave' => $quote['Pulizie']['chiave'], 'piano_origine_id' => $s['piano']->id]])->assertCreated();
    expect($compito()->is_completed)->toBeFalse()
        ->and($compito()->description)->toBe($partenza . ' Coperti da addebiti € 50,96: mancano € 16,99.');

    $rata = adbRegistra($this, $s, [['chi' => 'Verdi', 'unita' => 'U3', 'euro' => '16,99', 'chiave' => $quote['Manutenzioni']['chiave'], 'piano_origine_id' => $s['piano']->id]])
        ->assertCreated()->json('rate.0');
    expect($compito()->is_completed)->toBeTrue()
        ->and($compito()->meta['requires_action'])->toBeFalse()
        ->and($compito()->description)->toBe($partenza . ' Coperto da addebiti: € 67,95.');

    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $rata]))->assertOk();
    expect($compito()->is_completed)->toBeFalse()
        ->and($compito()->meta['requires_action'])->toBeTrue()
        ->and($compito()->description)->toBe($partenza . ' Coperti da addebiti € 50,96: mancano € 16,99.');
});
