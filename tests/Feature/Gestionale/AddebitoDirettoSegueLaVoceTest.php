<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'addebito diretto a una sola unità in usufrutto segue la voce su cui è scritto; il passaggio decide solo le righe senza voce.
 *
 * Lo scenario è quello di `OrdinariaDopoAttoTest`: Ugo proprietario al 100 % dal 2019, la voce «Spese generali» sulla tabella «Proprietà»,
 * la riserva d'usufrutto del 01/05/2026 (Ugo tiene l'usufrutto, Elsa ha la nuda proprietà) con la legge, una fattura del 02/01/2026 da
 * € 365,00 con la competenza del 2026, addebitata all'unità: 120 giorni fino al 30/04, € 120,00; 245 giorni dal 01/05, € 245,00.
 *
 * - Voce sul solo «Proprietario» (tenuta al passaggio togliendo la spunta, o un usufrutto scritto a mano): i € 245,00 al nudo proprietario.
 * - Voce sul solo «Usufruttuario»: all'usufruttuario, anche con un usufrutto scritto a mano o nato «come dice ogni voce».
 * - Riga senza voce, voce divisa fra «Proprietario» e «Usufruttuario», voce con l'«Inquilino»: decide il passaggio, come prima.
 * - Gestione straordinaria: al nudo proprietario, la voce sul «Proprietario» come di norma.
 *
 * La catena: il piano generato dopo il passaggio, il consuntivo per persona della stessa fattura senza piano e il conguaglio di un piano
 * generato prima danno la stessa persona e la stessa cifra.
 *
 * Che cosa non copre: MySQL (la suite gira su SQLite). Qui la riga con la voce e l'unità si scrive direttamente, con la voce in `conto_id` (le
 * righe scritte prima della 1.9.0); la riga scritta dalla pagina, con la voce in `conto_addebito_id`, la prova `AddebitoDirettoVoceRicordataTest`.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Enums\RuoloAnagraficaImmobile;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\Subentro;
use App\Models\User;
use App\Services\Gestionale\ConsuntivoPerPersona;
use App\Services\Riparto\RuoloDellaVoce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/**
 * Lo scenario con un piano da fatture in bozza sulla gestione ordinaria: la voce a importo zero, con il ruolo dato, e una fattura con una riga da
 * € 365,00 addebitata all'unità, sulla voce (o senza, con `$conVoce` falso). `$ripartizioni` sostituisce le percentuali della voce.
 */
function adsvScenario(string $soggetto = 'proprietario', bool $conVoce = true, ?array $ripartizioni = null, string $natura = 'ordinaria', int $comune = 0): array
{
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto, genera: false);
    $conto = Conto::whereHas('pianoConto', fn ($q) => $q->where('gestione_id', $s['g']->id))->firstOrFail();
    $conto->update(['importo' => 0]);
    $s['conto'] = $conto;
    $s['ctm'] = (int) DB::table('conto_tabella_millesimale')->where('conto_id', $conto->id)->value('id');
    if ($ripartizioni !== null) {
        DB::table('conto_tabella_ripartizioni')->where('conto_tabella_millesimale_id', $s['ctm'])->delete();
        foreach ($ripartizioni as $chi => $percentuale) {
            DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $s['ctm'], 'soggetto' => $chi, 'percentuale' => $percentuale, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    if ($natura !== 'ordinaria') {
        $s['g']->update(['tipo' => $natura]);
    }
    $s['piano']->update(['tipo' => 'straordinario', 'nome' => 'Spese da finanziare', 'stato' => 'bozza']);
    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Manutenzioni Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
        'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $fattura = FatturaPassiva::create([
        'condominio_id' => $s['c']->id, 'fornitore_id' => $fornitoreId, 'esercizio_id' => $s['e']->id, 'tipo_documento' => 'fattura', 'numero_documento' => "FT-ADSV{$s['unita']->id}",
        'data_documento' => '2026-01-02', 'data_scadenza' => '2026-02-01', 'is_pregresso' => false, 'importo_imponibile' => 36500 + $comune, 'importo_iva' => 0, 'importo_ritenuta' => 0,
        'totale_documento' => 36500 + $comune, 'netto_a_pagare' => 36500 + $comune, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico',
        'competenza_dal' => '2026-01-01', 'competenza_al' => '2026-12-31',
    ]);
    DB::table('righe_fattura')->insert([
        'fattura_passiva_id' => $fattura->id, 'conto_id' => $conVoce ? $conto->id : null, 'immobile_id' => $s['unita']->id,
        'descrizione' => 'Rifacimento balcone interno 1', 'aliquota_iva' => 0,
        'importo_imponibile' => 36500, 'importo_iva' => 0, 'is_sopravvenienza' => false, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    if ($comune > 0) {
        // Una riga della stessa fattura sulla stessa voce, per tutte le unità della tabella, fuori dal preventivo come la prende il piano.
        DB::table('righe_fattura')->insert([
            'fattura_passiva_id' => $fattura->id, 'conto_id' => $conto->id, 'immobile_id' => null, 'descrizione' => 'Pulizie', 'aliquota_iva' => 0,
            'importo_imponibile' => $comune, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $s['fattura'] = $fattura;

    return $s;
}

/** Collega la fattura al piano e lo genera. */
function adsvGenera(array $s): void
{
    $s['piano']->fatture()->syncWithoutDetaching([$s['fattura']->id => ['importo_collegato' => (int) $s['fattura']->totale_documento]]);
    $s['piano']->rate()->delete();
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']);
}

/** Le righe dell'addebito diretto del piano: [nome, importo, ruolo], nell'ordine dei tratti. */
function adsvAddebito(array $s): array
{
    $nomi = [$s['v']->id => 'Ugo', $s['a']->id => 'Elsa'];

    return DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'ad_personam')
        ->orderBy('titolarita_dal')->orderBy('id')->get(['anagrafica_id', 'importo', 'ruolo_risolto'])
        ->map(fn ($r) => [$nomi[(int) $r->anagrafica_id] ?? (string) $r->anagrafica_id, (int) $r->importo, $r->ruolo_risolto])->all();
}

/** L'usufrutto scritto a mano, senza passaggio: Ugo proprietario fino al 30/04, poi usufruttuario; Elsa nuda proprietaria dal 01/05. */
function adsvUsufruttoAMano(array $s): void
{
    DB::table('anagrafica_immobile')->where('id', $s['rigaV'])->update(['data_fine' => '2026-04-30']);
    foreach ([[$s['v']->id, 'usufruttuario'], [$s['a']->id, 'nuda_proprietario']] as [$chi, $ruolo]) {
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $chi, 'immobile_id' => $s['unita']->id, 'tipologia' => $ruolo, 'quota' => 100, 'attivo' => true,
            'data_inizio' => '2026-05-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
}

/** La fattura scritta a giornale nella gestione, come la scrive la registrazione: la legge il consuntivo per persona. */
function adsvAGiornale(array $s): void
{
    $scrittura = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id,
        'data_registrazione' => '2026-01-02', 'data_competenza' => '2026-01-02', 'numero_protocollo' => 'ADSV-' . $s['fattura']->id, 'causale' => 'fattura',
        'tipo_movimento' => 'fattura_acquisto', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('fattura_scrittura')->insert(['fattura_passiva_id' => $s['fattura']->id, 'scrittura_contabile_id' => $scrittura, 'importo_allocato' => 36500, 'tipo' => 'competenza',
        'created_at' => now(), 'updated_at' => now()]);
}

/** Le spese personali dell'unità nel consuntivo per persona: [nome => centesimi], solo le persone con una cifra. */
function adsvConsuntivo($test, array $s): array
{
    $test->travelTo('2026-12-31 12:00:00');
    $c = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $esito = [];
    foreach (['Ugo' => $s['v'], 'Elsa' => $s['a']] as $nome => $persona) {
        $importo = (int) collect($c['persone'][$s['g']->id][$persona->id][$s['unita']->id]['personali']['righe'] ?? [])->sum('importo');
        if ($importo !== 0) {
            $esito[$nome] = $importo;
        }
    }

    return $esito;
}

/** Quanto va a debito di una persona con le coppie del conguaglio dei passaggi. */
function adsvDebitoDi(array $s, $persona): int
{
    return (int) \App\Models\Saldo::whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $persona->id)->sum('saldo_iniziale');
}

// --- Il piano generato dopo il passaggio ----------------------------------------------------------------------------------

it('la voce tenuta sul «Proprietario» al passaggio: l\'addebito diretto su quella voce va al nudo proprietario dal giorno dell\'atto', function () {
    $s = adsvScenario();
    $passaggio = ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctm']]]));
    expect($passaggio->registro['voci_tenute'] ?? null)->toBe([$s['ctm']]);
    adsvGenera($s);

    // € 120,00 a Ugo proprietario fino al 30/04, € 245,00 a Elsa nuda proprietaria dal 01/05. Prima: € 245,00 a Ugo usufruttuario.
    expect(adsvAddebito($s))->toBe([['Ugo', 12000, 'proprietario'], ['Elsa', 24500, 'nuda_proprietario']]);
});

it('la voce spostata sull\'«Usufruttuario» al passaggio: l\'addebito diretto va all\'usufruttuario, come prima', function () {
    $s = adsvScenario();
    $passaggio = ruRegistra($this, $s, ruRiserva($s));
    expect(collect($passaggio->registro['voci_spostate'] ?? [])->pluck('conto')->all())->toBe(['Spese generali']);
    adsvGenera($s);

    expect(adsvAddebito($s))->toBe([['Ugo', 12000, 'proprietario'], ['Ugo', 24500, 'usufruttuario']]);
});

it('la riga senza voce: decide il passaggio, con la legge all\'usufruttuario, come prima', function () {
    $s = adsvScenario(conVoce: false);
    ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctm']]]));
    adsvGenera($s);

    expect(adsvAddebito($s))->toBe([['Ugo', 12000, 'proprietario'], ['Ugo', 24500, 'usufruttuario']]);
});

it('«come dice ogni voce»: la voce sull\'«Usufruttuario» porta l\'addebito all\'usufruttuario; prima andava al nudo proprietario', function (string $soggetto, array $atteso) {
    $s = adsvScenario($soggetto);
    ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => Subentro::ORDINARIA_COME_LA_VOCE]));
    adsvGenera($s);

    expect(adsvAddebito($s))->toBe($atteso);
})->with([
    'voce sull\'«Usufruttuario»' => ['usufruttuario', [['Ugo', 12000, 'proprietario'], ['Ugo', 24500, 'usufruttuario']]],
    'voce sul «Proprietario»' => ['proprietario', [['Ugo', 12000, 'proprietario'], ['Elsa', 24500, 'nuda_proprietario']]],
]);

it('l\'usufrutto scritto a mano: la voce decide anche lì', function (string $soggetto, array $atteso) {
    $s = adsvScenario($soggetto);
    adsvUsufruttoAMano($s);
    adsvGenera($s);

    expect(adsvAddebito($s))->toBe($atteso);
})->with([
    // La riga d'usufrutto scritta a mano non ha un predecessore nel ruolo: vale da sempre, e l'usufruttuario ha tutto l'anno.
    'voce sull\'«Usufruttuario»: all\'usufruttuario (prima al nudo)' => ['usufruttuario', [['Ugo', 36500, 'usufruttuario']]],
    'voce sul «Proprietario»: al nudo proprietario, come prima' => ['proprietario', [['Ugo', 12000, 'proprietario'], ['Elsa', 24500, 'nuda_proprietario']]],
]);

it('la voce divisa fra «Proprietario» e «Usufruttuario», o con l\'«Inquilino»: decide il passaggio, come prima', function (array $ripartizioni) {
    $s = adsvScenario(ripartizioni: $ripartizioni);
    ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctm']]]));
    adsvGenera($s);

    expect(adsvAddebito($s))->toBe([['Ugo', 12000, 'proprietario'], ['Ugo', 24500, 'usufruttuario']]);
})->with([
    'metà e metà' => [['proprietario' => 50, 'usufruttuario' => 50]],
    'proprietario e inquilino' => [['proprietario' => 70, 'inquilino' => 30]],
]);

it('la straordinaria: la voce sul «Proprietario» porta l\'addebito al nudo proprietario, come prima', function () {
    // La gestione è straordinaria già al passaggio: il pannello non elenca la voce, che resta sul «Proprietario».
    $s = adsvScenario(natura: 'straordinaria');
    ruRegistra($this, $s, ruRiserva($s));
    expect(RuoloDellaVoce::di($s['conto']->id))->toBe(RuoloAnagraficaImmobile::PROPRIETARIO);
    adsvGenera($s);

    expect(adsvAddebito($s))->toBe([['Ugo', 12000, 'proprietario'], ['Elsa', 24500, 'nuda_proprietario']]);
});

// --- La catena: piano, consuntivo per persona, conguaglio -------------------------------------------------------------------

it('la catena con la voce tenuta: il piano e il consuntivo per persona danno la stessa persona e la stessa cifra', function () {
    $s = adsvScenario();
    ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctm']]]));
    adsvAGiornale($s);

    // Senza piano: la spesa del consuntivo per persona.
    expect(adsvConsuntivo($this, $s))->toBe(['Ugo' => 12000, 'Elsa' => 24500]);

    // Con il piano: la stessa cifra nel piano e nel consuntivo, che la legge dal piano.
    adsvGenera($s);
    expect(adsvAddebito($s))->toBe([['Ugo', 12000, 'proprietario'], ['Elsa', 24500, 'nuda_proprietario']]);
});

it('la catena con «come dice ogni voce» e la voce sull\'«Usufruttuario»: piano generato prima, conguaglio, ricalcolo e consuntivo dicono Ugo', function () {
    $s = adsvScenario('usufruttuario');
    adsvAGiornale($s);
    adsvGenera($s);
    expect(adsvAddebito($s))->toBe([['Ugo', 36500, 'proprietario']]);
    ruEmetti($s, '2026-12-31');

    ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => Subentro::ORDINARIA_COME_LA_VOCE]));
    // Il conguaglio non passa niente a Elsa: la voce dice l'usufruttuario, e Ugo lo è dal 01/05. Prima: € 245,00 a Elsa.
    expect(adsvDebitoDi($s, $s['a']))->toBe(0);
});

it('la catena senza piano, con «come dice ogni voce»: il consuntivo per persona segue la voce come il piano generato dopo', function (string $soggetto, array $consuntivo, array $piano) {
    $s = adsvScenario($soggetto);
    ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => Subentro::ORDINARIA_COME_LA_VOCE]));
    adsvAGiornale($s);
    expect(adsvConsuntivo($this, $s))->toBe($consuntivo);

    adsvGenera($s);
    expect(adsvAddebito($s))->toBe($piano);
})->with([
    'voce sull\'«Usufruttuario»: Ugo (prima il consuntivo dava € 245,00 a Elsa)' => ['usufruttuario', ['Ugo' => 36500], [['Ugo', 12000, 'proprietario'], ['Ugo', 24500, 'usufruttuario']]],
    'voce sul «Proprietario»: Elsa dal 01/05, come prima' => ['proprietario', ['Ugo' => 12000, 'Elsa' => 24500], [['Ugo', 12000, 'proprietario'], ['Elsa', 24500, 'nuda_proprietario']]],
]);

it('la voce tenuta con la legge, su un piano generato prima del passaggio: il conguaglio lascia l\'ordinaria a Ugo, per la voce e per il suo addebito; il ricalcolo dà tutte e due a Elsa', function () {
    // Il piano chiede € 1.200,00 sulla voce e € 365,00 addebitati all'unità sulla stessa voce, tutti a Ugo, proprietario a gennaio.
    $s = adsvScenario(comune: 120000);
    adsvGenera($s);
    $s['piano']->update(['stato' => 'bozza']);
    ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctm']]]));
    expect(adsvDebitoDi($s, $s['a']))->toBe(0);

    ruRicalcola($s);
    $elsa = (int) DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('anagrafica_id', $s['a']->id)->whereIn('tipo', ['riparto', 'ad_personam'])->sum('importo');
    // 1.200,00 × 245/365 = 805,48 sulla voce, più i € 245,00 dell'addebito.
    expect(adsvAddebito($s))->toBe([['Ugo', 12000, 'proprietario'], ['Elsa', 24500, 'nuda_proprietario']])
        ->and($elsa)->toBe(80548 + 24500);
});

it('la catena con «come dice ogni voce» e la voce sul «Proprietario»: il conguaglio passa a Elsa i € 245,00, come il ricalcolo', function () {
    $s = adsvScenario();
    adsvGenera($s);
    ruEmetti($s, '2026-12-31');

    ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => Subentro::ORDINARIA_COME_LA_VOCE]));
    expect(adsvDebitoDi($s, $s['a']))->toBe(24500);
});


// --- La riga della pagina del piano -------------------------------------------------------------------------------------------

it('la pagina del piano: l\'addebito su una voce tenuta al passaggio ha la riga della scelta, come la voce', function () {
    $s = adsvScenario();
    ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctm']]]));
    adsvGenera($s);

    $righe = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']]))
        ->assertOk()->viewData('page')['props']['usufrutti_con_l_ordinaria_al_nudo'];
    expect($righe)->toBe([['immobile_id' => $s['unita']->id, 'frase' => 'Int. 1 è in usufrutto: la voce «Spese generali» resta sul «Proprietario» e va ad Acquirente Elsa, '
        .'nudo proprietario: è la scelta del passaggio del 01/05/2026, che l\'ha tenuta lì.']]);
});

it('la pagina del piano: l\'addebito senza voce, o su una voce divisa, non ha la riga (la voce non dice dove va)', function (bool $conVoce, ?array $ripartizioni) {
    $s = adsvScenario(conVoce: $conVoce, ripartizioni: $ripartizioni);
    adsvUsufruttoAMano($s);
    adsvGenera($s);

    expect(collect(adsvAddebito($s))->pluck(2)->all())->toContain('nuda_proprietario');
    $righe = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']]))
        ->assertOk()->viewData('page')['props']['usufrutti_con_l_ordinaria_al_nudo'];
    expect($righe)->toBe([]);
})->with([
    'senza voce' => [false, null],
    'voce metà e metà' => [true, ['proprietario' => 50, 'usufruttuario' => 50]],
]);
