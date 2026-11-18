<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'accordo di rientro di un defunto che era in regola, chiuso dalla successione con l'arretrato agli eredi. Di serie l'accordo resta in regola fino
 * al decesso: nella proposta degli interessi del defunto i giorni dell'accordo restano fuori, e dal giorno del decesso il defunto non matura più
 * interessi (il debito passa agli eredi). Nell'anteprima della successione l'amministratore può scegliere che la chiusura valga come una decadenza: allora
 * tornano i giorni dell'accordo, come quando un accordo decade. La scelta si salva con il passaggio, nel suo registro, e l'annullamento la toglie.
 *
 * Tasso legale 2026 1,60 %; in centesimi capitale × 16.000 × giorni / 365.000.000; un versamento conta dal giorno dopo. Il caso: Neri, rata 1 di € 300,00
 * scaduta il 31/03/2026, € 100,00 versati il 30/04 (`AccessoriScenario.php`); l'accordo del 15/07; la successione di U1 dal 20/08/2026 (il decesso) ad Anna
 * e Bruno al 50 %, con l'arretrato agli eredi, registrata il 25/08.
 */

use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** L'accordo del 15/07 sulla rata 1 di Neri, per l'importo e con il calendario dati. */
function adrAccordo($test, array $s, string $importo, array $calendario, array $date = ['2026-07-31', '2026-08-31']): AccordoRientro
{
    $test->travelTo('2026-07-15 12:00:00');
    $test->actingAs($test->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone']['Neri']->id, 'data_accordo' => '2026-07-15', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => $importo]],
        'scadenze' => aclCalendario($calendario, $date), 'decadenza_rate' => 2, 'decadenza_giorni' => 15,
    ])->assertCreated();

    return AccordoRientro::latest('id')->firstOrFail();
}

function adrErede(array $s, string $nome): \App\Models\Anagrafica
{
    $email = strtolower($nome) . '-adr-' . $s['c']->id . '@test.it';
    if (($gia = \App\Models\Anagrafica::where('email', $email)->first()) !== null) {
        return $gia;
    }
    $p = \App\Models\Anagrafica::forceCreate(['nome' => $nome, 'email' => $email, 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'ADR' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** I dati della successione di U1 dal 20/08 con l'arretrato agli eredi; con la scelta sull'accordo del defunto, se data. */
function adrSuccessione(array $s, ?string $accordo = null): array
{
    $anna = adrErede($s, 'Anna');
    $bruno = adrErede($s, 'Bruno');

    return ['tipo' => 'successione', 'riga_uscente_id' => $s['righe']['Neri'], 'decorrenza' => '2026-08-20', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $anna->id, 'quota' => 50], ['anagrafica_id' => $bruno->id, 'quota' => 50]], 'arretrato' => 'eredi', 'erede_di_riferimento' => $anna->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta']
        + ($accordo !== null ? ['accordo_del_defunto' => $accordo] : []);
}

/** Il caso: € 100,00 il 30/04, l'accordo del 15/07 per l'importo dato. */
function adrCaso($test, string $importo = '200,00', array $calendario = ['100,00', '100,00'], array $date = ['2026-07-31', '2026-08-31']): array
{
    $s = accViaVerdi($test);
    $test->travelTo('2026-04-30 10:00:00');
    lcdIncassa($test, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');

    return [$s, adrAccordo($test, $s, $importo, $calendario, $date)];
}

/** La riga della rata 1 di Neri nella proposta alla data: [interessi, al]; null senza riga. */
function adrRiga($test, array $s, string $alla): ?array
{
    $neri = accDi(accProposta($test, $s, ['Neri'], $alla)->assertOk()->json(), $s, 'Neri');
    $r = collect($neri['righe'] ?? [])->firstWhere('rata_id', lcdRata($s, 1));

    return $r === null ? null : [$r['interessi'], $r['al']];
}

function adrRegistra($test, array $s, array $dati): Subentro
{
    $test->travelTo('2026-08-25 10:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $dati)->assertSessionHasNoErrors();

    return Subentro::where('immobile_id', $s['unita']['U1']->id)->latest('id')->firstOrFail();
}

it('di serie l\'accordo in regola resta in regola fino al decesso: nella proposta di Neri i soli giorni prima dell\'accordo, € 1,05, e niente dopo il decesso', function () {
    [$s, $a] = adrCaso($this);
    // Il 31/07 Neri versa la prima rata del calendario: l'accordo è in regola.
    aclVersa($this, $s, [lcdQuota($s, 'Neri', 1) => 10000], '2026-07-31');
    $this->travelTo('2026-08-20 10:00:00');
    expect(adrRiga($this, $s, '2026-08-20'))->toBe([105, '2026-07-14']);

    $passaggio = adrRegistra($this, $s, adrSuccessione($s));
    $a = $a->fresh();
    expect($a->chiuso_il->format('Y-m-d'))->toBe('2026-08-20')
        ->and($a->chiusura_per_successione)->toBe(AccordoRientro::SUCCESSIONE_IN_REGOLA)
        ->and($passaggio->registro['arretrato']['accordi'])->toBe([['id' => (int) $a->id, 'chiuso_il' => '2026-08-20', 'scelta' => 'in_regola']]);
    // 39,452055 (01/04–30/04, € 300,00) + 65,753425 (01/05–14/07, € 200,00) = 105,205480 → € 1,05, al 25/08 come a fine anno.
    expect(adrRiga($this, $s, '2026-08-25'))->toBe([105, '2026-07-14']);
    $this->travelTo('2026-12-31 10:00:00');
    expect(adrRiga($this, $s, '2026-12-31'))->toBe([105, '2026-07-14']);
});

it('scelto «vale come una decadenza», tornano i giorni dell\'accordo, come la decadenza: € 1,31 al 25/08', function () {
    [$s, $a] = adrCaso($this);
    aclVersa($this, $s, [lcdQuota($s, 'Neri', 1) => 10000], '2026-07-31');

    $passaggio = adrRegistra($this, $s, adrSuccessione($s, 'decadenza'));
    expect($a->fresh()->chiusura_per_successione)->toBe(AccordoRientro::SUCCESSIONE_DECADENZA)
        ->and($passaggio->registro['arretrato']['accordi'][0]['scelta'])->toBe('decadenza');
    // 39,452055 + 20.000 × 92 giorni (01/05–31/07) 80,657534 + 10.000 × 25 giorni (01/08–25/08) 10,958904 = 131,068493 → € 1,31.
    expect(adrRiga($this, $s, '2026-08-25'))->toBe([131, '2026-08-25']);
});

it('con la parte fuori che continua, di serie la riga di Neri arriva al giorno prima del decesso: € 1,13', function () {
    // L'accordo copre € 150,00 dei € 200,00; gli interessi continuano sui € 50,00 fuori. Al 25/08: 39,452055 + 65,753425 + 5.000 × 36 giorni
    // (15/07–19/08) 7,890411 = 113,095891 → € 1,13. Senza la regola, € 1,42 (tutti i giorni sui € 200,00 fino al 25/08).
    [$s] = adrCaso($this, '150,00', ['75,00', '75,00']);
    adrRegistra($this, $s, adrSuccessione($s));
    expect(adrRiga($this, $s, '2026-08-25'))->toBe([113, '2026-08-19']);
});

it('un accordo che al decesso non era in regola (il calendario finito con rate non versate) non tiene fuori niente: tornano tutti i giorni', function () {
    // Il calendario finisce il 10/08 e nessuna rata è versata: al decesso l'accordo non reggeva più. 39,452055 + 20.000 × 117 giorni (01/05–25/08)
    // 102,575342 = 142,027397 → € 1,42.
    [$s] = adrCaso($this, '200,00', ['100,00', '100,00'], ['2026-07-31', '2026-08-10']);
    $this->travelTo('2026-08-25 09:00:00');
    $anteprima = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), adrSuccessione($s))
        ->assertOk()->json('rate.arretrato');
    expect($anteprima['accordi_del_defunto'][0]['in_regola'])->toBeFalse()
        ->and($anteprima['frase_accordo_del_defunto'])->toBe('L\'accordo di rientro di Neri del 15/07/2026 non era in regola al decesso (il calendario è finito con rate non versate): '
            . 'nella proposta degli interessi di Neri i giorni dell\'accordo tornano comunque.');
    adrRegistra($this, $s, adrSuccessione($s));
    expect(adrRiga($this, $s, '2026-08-25'))->toBe([142, '2026-08-25']);
});

it('l\'anteprima dice la scelta, di serie «resta in regola fino al decesso»; un valore che non c\'è si ferma; l\'annullamento toglie la scelta', function () {
    [$s, $a] = adrCaso($this);
    aclVersa($this, $s, [lcdQuota($s, 'Neri', 1) => 10000], '2026-07-31');
    $this->travelTo('2026-08-25 09:00:00');
    $anteprima = fn (array $dati) => $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), $dati);

    $arretrato = $anteprima(adrSuccessione($s))->assertOk()->json('rate.arretrato');
    expect($arretrato['accordo_del_defunto'])->toBe('in_regola')
        ->and($arretrato['accordi_del_defunto'])->toBe([['id' => (int) $a->id, 'data_accordo' => '2026-07-15', 'in_regola' => true]])
        ->and($arretrato['frase_accordo_del_defunto'])->toBe('Per gli interessi l\'accordo di rientro di Neri del 15/07/2026 resta in regola fino al decesso: nella proposta degli '
            . 'interessi di Neri i giorni dell\'accordo restano fuori, e dal 20/08/2026 non ne maturano altri.');
    expect($anteprima(adrSuccessione($s, 'decadenza'))->assertOk()->json('rate.arretrato.frase_accordo_del_defunto'))
        ->toBe('Per gli interessi l\'accordo di rientro di Neri del 15/07/2026 vale come una decadenza dal 20/08/2026: nella proposta degli interessi di Neri tornano anche i '
            . 'giorni dell\'accordo.');
    $anteprima(adrSuccessione($s, 'forse'))->assertStatus(422)->assertJsonValidationErrors(['accordo_del_defunto']);

    $passaggio = adrRegistra($this, $s, adrSuccessione($s, 'decadenza'));
    $storico = collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']['U1']->fresh())['subentri'])->firstWhere('id', $passaggio->id);
    expect((string) $storico['arretrato']['frase'])->toContain('L\'accordo di rientro di Neri del 15/07/2026 si è chiuso per la successione: per gli interessi vale come una decadenza.');

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertRedirect();
    $a = $a->fresh();
    expect($a->chiuso_il)->toBeNull()->and($a->chiusura_per_successione)->toBeNull();
});
