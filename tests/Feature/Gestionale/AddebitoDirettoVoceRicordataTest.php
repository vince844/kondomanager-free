<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La fattura ricorda la voce di una riga addebitata a una sola unità (`righe_fattura.conto_addebito_id`), e l'addebito diretto la segue.
 *
 * La riga di un'unità resta fuori dalla voce comune (`conto_id` nullo, come dalla 1.9.0): la voce scelta nel modulo sta nella colonna sua. Le
 * fatture si registrano e si modificano dalle rotte vere, come dalla pagina; il passaggio dal suo modulo.
 *
 * Lo scenario è quello di `AddebitoDirettoSegueLaVoceTest`: Ugo proprietario al 100 % dal 2019, la voce «Spese generali» sulla tabella
 * «Proprietà», la riserva d'usufrutto del 01/05/2026 (Ugo tiene l'usufrutto, Elsa ha la nuda proprietà), una fattura del 02/01/2026 da
 * € 365,00 con la competenza del 2026 addebitata all'interno 1 sulla voce: 120 giorni fino al 30/04, € 120,00; 245 giorni dal 01/05, € 245,00.
 *
 * Che cosa non copre: MySQL (la suite gira su SQLite, e la migrazione su MySQL la lancia chi coordina).
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\Subentro;
use App\Models\User;
use App\Services\Gestionale\ConsuntivoPerPersona;
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
 * Lo scenario con i mastri del condominio, un fornitore e il piano da fatture in bozza sulla gestione ordinaria. La voce a importo zero, sul ruolo
 * dato. La fattura non c'è: la registra la prova dalla rotta (`avrRegistra`).
 */
function avrScenario(string $soggetto = 'proprietario'): array
{
    // Il compito «Emettere rate» della fattura registrata non è di questa prova (chiede le categorie degli eventi), come in `setupContabile`.
    \Illuminate\Support\Facades\Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    $s = ruScenario('prima_rata', 0, soggetto: $soggetto, genera: false);
    $conto = Conto::whereHas('pianoConto', fn ($q) => $q->where('gestione_id', $s['g']->id))->firstOrFail();
    $conto->update(['importo' => 0]);
    $s['conto'] = $conto;
    $s['ctm'] = (int) DB::table('conto_tabella_millesimale')->where('conto_id', $conto->id)->value('id');
    $s['piano']->update(['tipo' => 'straordinario', 'nome' => 'Spese da finanziare', 'stato' => 'bozza']);
    foreach (['debiti_fornitori', 'crediti_condomini', 'debiti_erario_ritenute', 'passate_gestioni', 'sopravvenienze_passive'] as $ruolo) {
        if (! DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', $ruolo)->exists()) {
            DB::table('conti_contabili')->insert(['condominio_id' => $s['c']->id, 'ruolo' => $ruolo, 'codice' => strtoupper($ruolo) . '-' . $s['c']->id, 'nome' => ucfirst(str_replace('_', ' ', $ruolo)),
                'tipo' => 'passivo', 'categoria' => 'debiti', 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    $s['fornitore'] = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Manutenzioni Srl', 'soggetto_ritenuta' => false, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
        'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $s;
}

/** La riga del modulo: € 365,00 sull'interno 1, sulla voce data (o senza). */
function avrRiga(array $s, ?int $contoId, array $extra = []): array
{
    return array_merge(['descrizione' => 'Rifacimento balcone interno 1', 'importo_imponibile' => 365.00, 'aliquota_iva' => 0, 'conto_id' => $contoId,
        'immobile_id' => $s['unita']->id, 'is_sopravvenienza' => false], $extra);
}

/** Registra la fattura dalla rotta, come la pagina: la risposta è un rinvio senza errori. */
function avrRegistra($test, array $s, array $righe, string $numero = 'FT-AVR-1'): FatturaPassiva
{
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), [
        'fornitore_id' => $s['fornitore'], 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'tipo_documento' => 'fattura',
        'numero_documento' => $numero, 'data_documento' => '2026-01-02', 'data_scadenza' => '2026-02-01', 'modalita_pagamento' => 'bonifico',
        'competenza_dal' => '2026-01-01', 'competenza_al' => '2026-12-31', 'stato_approvazione' => 'approvata',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => $righe,
    ])->assertRedirect()->assertSessionHasNoErrors();

    return FatturaPassiva::where('condominio_id', $s['c']->id)->where('numero_documento', $numero)->firstOrFail();
}

/** Modifica la fattura dalla rotta, come la pagina. */
function avrModifica($test, array $s, FatturaPassiva $f, array $righe)
{
    return $test->actingAs($test->user)->put(route('admin.gestionale.fatture.update', [$s['c'], $f]), [
        'gestione_id' => $s['g']->id, 'numero_documento' => $f->numero_documento, 'data_documento' => '2026-01-02', 'data_scadenza' => '2026-02-01',
        'competenza_dal' => '2026-01-01', 'competenza_al' => '2026-12-31', 'modalita_pagamento' => 'bonifico', 'righe' => $righe,
    ]);
}

/** Le colonne della voce delle righe della fattura: [conto_id, conto_addebito_id] per riga. */
function avrVoci(FatturaPassiva $f): array
{
    return DB::table('righe_fattura')->where('fattura_passiva_id', $f->id)->orderBy('id')->get(['conto_id', 'conto_addebito_id'])
        ->map(fn ($r) => [$r->conto_id === null ? null : (int) $r->conto_id, $r->conto_addebito_id === null ? null : (int) $r->conto_addebito_id])->all();
}

/** Collega la fattura al piano e lo genera. */
function avrGenera(array $s, FatturaPassiva $f): void
{
    $s['piano']->fatture()->syncWithoutDetaching([$f->id => ['importo_collegato' => (int) $f->totale_documento]]);
    $s['piano']->rate()->delete();
    app(GeneratePianoRateAction::class)->execute($s['piano']->fresh(), forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']);
}

/** Le righe dell'addebito diretto del piano: [nome, importo, ruolo], nell'ordine dei tratti. */
function avrAddebito(array $s): array
{
    $nomi = [$s['v']->id => 'Ugo', $s['a']->id => 'Elsa'];

    return DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'ad_personam')
        ->orderBy('titolarita_dal')->orderBy('id')->get(['anagrafica_id', 'importo', 'ruolo_risolto'])
        ->map(fn ($r) => [$nomi[(int) $r->anagrafica_id] ?? (string) $r->anagrafica_id, (int) $r->importo, $r->ruolo_risolto])->all();
}

/** Le spese personali dell'unità nel consuntivo per persona: [nome => centesimi], solo le persone con una cifra. */
function avrConsuntivo($test, array $s): array
{
    $test->travelTo('2026-12-31 12:00:00');
    $c = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $test->travelBack();
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
function avrDebitoDi(array $s, $persona): int
{
    return (int) \App\Models\Saldo::whereNotNull('subentro_id')->where('immobile_id', $s['unita']->id)->where('anagrafica_id', $persona->id)->sum('saldo_iniziale');
}

// --- La registrazione -----------------------------------------------------------------------------------------------------

it('la riga di un\'unità registrata dalla pagina ricorda la voce scelta, fuori dalla voce comune; la riga comune no', function () {
    $s = avrScenario();
    $f = avrRegistra($this, $s, [
        avrRiga($s, $s['conto']->id),
        avrRiga($s, $s['conto']->id, ['immobile_id' => null, 'descrizione' => 'Pulizie', 'importo_imponibile' => 10.00]),
    ]);

    // Prima: [[null, null], …], la voce scelta per l'unità si perdeva.
    expect(avrVoci($f))->toBe([[null, $s['conto']->id], [$s['conto']->id, null]]);
    // La riga dell'unità resta sull'unità a giornale, non sul mastro della voce.
    expect(DB::table('righe_scritture')->whereIn('scrittura_id', $f->scritture()->pluck('scritture_contabili.id'))->whereNotNull('immobile_id')->value('voce_spesa_id'))->toBeNull();
});

it('una voce che non è di spesa, o di un\'altra gestione dello stesso condominio, non diventa la voce dell\'addebito', function () {
    $s = avrScenario();
    $entrata = Conto::create(['piano_conto_id' => $s['conto']->piano_conto_id, 'nome' => 'Affitto antenna', 'tipo' => 'entrata', 'importo' => 0]);
    $altraGestione = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Facciata', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($s['e'], $altraGestione->id);
    $pcAltra = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $altraGestione->id, 'nome' => 'PC facciata']);
    $voceAltra = Conto::create(['piano_conto_id' => $pcAltra->id, 'nome' => 'Rifacimento facciata', 'tipo' => 'spesa', 'importo' => 0]);

    $f = avrRegistra($this, $s, [avrRiga($s, $entrata->id), avrRiga($s, $voceAltra->id, ['descrizione' => 'Altra gestione'])]);

    expect(avrVoci($f))->toBe([[null, null], [null, null]]);
});

it('una voce di un altro condominio sulla riga di un\'unità si rifiuta sul campo della voce, e la fattura non nasce', function () {
    $s = avrScenario();
    $altro = avrScenario();

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), [
        'fornitore_id' => $s['fornitore'], 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id, 'tipo_documento' => 'fattura',
        'numero_documento' => 'FT-AVR-ALTRO', 'data_documento' => '2026-01-02', 'data_scadenza' => '2026-02-01', 'modalita_pagamento' => 'bonifico',
        'stato_approvazione' => 'approvata', 'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
        'righe' => [avrRiga($s, $altro['conto']->id)],
    ])->assertSessionHasErrors(['righe.0.conto_id' => 'La voce scelta non appartiene a questo condominio.']);

    expect(FatturaPassiva::where('numero_documento', 'FT-AVR-ALTRO')->exists())->toBeFalse();
});

// --- Il caso della decisione: piano, consuntivo per persona, conguaglio ---------------------------------------------------

it('la voce tenuta sul «Proprietario» al passaggio, fattura dalla pagina: € 120,00 a Ugo e € 245,00 a Elsa nel consuntivo per persona e nel piano', function () {
    $s = avrScenario();
    $f = avrRegistra($this, $s, [avrRiga($s, $s['conto']->id)]);
    ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctm']]]));

    // Senza piano: il consuntivo per persona. Prima (la voce persa): Ugo € 365,00.
    expect(avrConsuntivo($this, $s))->toBe(['Ugo' => 12000, 'Elsa' => 24500]);

    // Il piano generato dopo il passaggio. Prima: € 245,00 a Ugo usufruttuario.
    avrGenera($s, $f);
    expect(avrAddebito($s))->toBe([['Ugo', 12000, 'proprietario'], ['Elsa', 24500, 'nuda_proprietario']]);
    // La riga del riparto non porta la voce, come la riga di fattura: nessun lettore delle voci la conta.
    expect(DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'ad_personam')->whereNotNull('conto_id')->count())->toBe(0);
});

it('la stessa fattura con la riga senza voce (tolta in modifica): decide il passaggio, come prima (Ugo usufruttuario)', function () {
    // Alla registrazione la pagina chiede la voce anche alla riga di un'unità (`StoreFatturaRequest`); la modifica la lascia togliere.
    $s = avrScenario();
    $f = avrRegistra($this, $s, [avrRiga($s, $s['conto']->id)]);
    avrModifica($this, $s, $f, [avrRiga($s, null)])->assertRedirect()->assertSessionHasNoErrors();
    expect(avrVoci($f))->toBe([[null, null]]);
    ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctm']]]));

    expect(avrConsuntivo($this, $s))->toBe(['Ugo' => 36500]);
    avrGenera($s, $f);
    expect(avrAddebito($s))->toBe([['Ugo', 12000, 'proprietario'], ['Ugo', 24500, 'usufruttuario']]);
});

it('il conguaglio di un piano generato prima, con «come dice ogni voce», segue la voce ricordata dalla fattura', function (string $soggetto, int $aElsa) {
    $s = avrScenario($soggetto);
    $f = avrRegistra($this, $s, [avrRiga($s, $s['conto']->id)]);
    avrGenera($s, $f);
    expect(avrAddebito($s))->toBe([['Ugo', 36500, 'proprietario']]);
    ruEmetti($s, '2026-12-31');

    ruRegistra($this, $s, ruRiserva($s, extra: ['ordinaria_dopo_atto' => Subentro::ORDINARIA_COME_LA_VOCE]));
    expect(avrDebitoDi($s, $s['a']))->toBe($aElsa);
})->with([
    // La voce sul «Proprietario»: i € 245,00 dopo l'atto passano a Elsa, nuda proprietaria, come farebbe il ricalcolo.
    'voce sul «Proprietario»: € 245,00 a Elsa' => ['proprietario', 24500],
    // La voce sull'«Usufruttuario»: Ugo lo è dal 01/05, niente passa. Prima (la voce persa, l'addebito dalla parte del «Proprietario»): € 245,00 a Elsa.
    'voce sull\'«Usufruttuario»: niente a Elsa' => ['usufruttuario', 0],
]);

it('la pagina del piano: l\'addebito della fattura dalla pagina sulla voce tenuta ha la riga della scelta del passaggio', function () {
    $s = avrScenario();
    $f = avrRegistra($this, $s, [avrRiga($s, $s['conto']->id)]);
    ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctm']]]));
    avrGenera($s, $f);

    $righe = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s['c'], $s['e'], $s['piano']]))
        ->assertOk()->viewData('page')['props']['usufrutti_con_l_ordinaria_al_nudo'];
    expect($righe)->toBe([['immobile_id' => $s['unita']->id, 'frase' => 'Int. 1 è in usufrutto: la voce «Spese generali» resta sul «Proprietario» e va ad Acquirente Elsa, '
        .'nudo proprietario: è la scelta del passaggio del 01/05/2026, che l\'ha tenuta lì.']]);
});

// --- La modifica, lo storno, la voce eliminata ----------------------------------------------------------------------------

it('il modulo della modifica riceve la voce ricordata, e la modifica dalla pagina la tiene, la cambia o la toglie', function () {
    $s = avrScenario();
    $f = avrRegistra($this, $s, [avrRiga($s, $s['conto']->id)]);

    $riga = $this->actingAs($this->user)->get(route('admin.gestionale.fatture.edit', [$s['c'], $f]))->assertOk()->viewData('page')['props']['fattura']['righe'][0];
    expect([$riga['conto_id'], $riga['conto_addebito_id'], $riga['immobile_id']])->toBe([null, $s['conto']->id, $s['unita']->id]);

    // La pagina rimanda la voce che mostra (il modulo la legge da `conto_addebito_id`): una modifica della descrizione la tiene.
    avrModifica($this, $s, $f, [avrRiga($s, $s['conto']->id, ['descrizione' => 'Rifacimento balcone, int. 1'])])->assertRedirect()->assertSessionHasNoErrors();
    expect(avrVoci($f))->toBe([[null, $s['conto']->id]]);

    // Un'altra voce della gestione.
    $altra = Conto::create(['piano_conto_id' => $s['conto']->piano_conto_id, 'nome' => 'Manutenzioni', 'tipo' => 'spesa', 'importo' => 0]);
    avrModifica($this, $s, $f, [avrRiga($s, $altra->id)])->assertRedirect()->assertSessionHasNoErrors();
    expect(avrVoci($f))->toBe([[null, $altra->id]]);

    // Senza voce.
    avrModifica($this, $s, $f, [avrRiga($s, null)])->assertRedirect()->assertSessionHasNoErrors();
    expect(avrVoci($f))->toBe([[null, null]]);
});

it('la modifica dalla pagina: il caso di Ugo ed Elsa dopo che la voce è stata tolta e poi scelta di nuovo in modifica', function () {
    $s = avrScenario();
    $f = avrRegistra($this, $s, [avrRiga($s, $s['conto']->id)]);
    avrModifica($this, $s, $f, [avrRiga($s, null)])->assertRedirect()->assertSessionHasNoErrors();
    avrModifica($this, $s, $f, [avrRiga($s, $s['conto']->id)])->assertRedirect()->assertSessionHasNoErrors();
    ruRegistra($this, $s, ruRiserva($s, extra: ['voci_da_tenere' => [$s['ctm']]]));

    expect(avrConsuntivo($this, $s))->toBe(['Ugo' => 12000, 'Elsa' => 24500]);
    avrGenera($s, $f->fresh());
    expect(avrAddebito($s))->toBe([['Ugo', 12000, 'proprietario'], ['Elsa', 24500, 'nuda_proprietario']]);
});

it('lo storno porta la voce dell\'addebito sulla riga della nota di credito', function () {
    $s = avrScenario();
    $f = avrRegistra($this, $s, [avrRiga($s, $s['conto']->id)]);

    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.storno', [$s['c'], $f]))->assertRedirect()->assertSessionHasNoErrors();
    $nota = FatturaPassiva::where('condominio_id', $s['c']->id)->where('tipo_documento', 'nota_credito')->latest('id')->firstOrFail();

    expect(avrVoci($nota))->toBe([[null, $s['conto']->id]]);
});

it('eliminata la voce, la riga torna senza voce: la chiave esterna la azzera, e decide il passaggio', function () {
    $s = avrScenario();
    $voce = Conto::create(['piano_conto_id' => $s['conto']->piano_conto_id, 'nome' => 'Manutenzioni', 'tipo' => 'spesa', 'importo' => 0]);
    $f = avrRegistra($this, $s, [avrRiga($s, $voce->id)]);
    expect(avrVoci($f))->toBe([[null, $voce->id]]);

    // La voce non ha righe di fattura sue (`conto_id`) né movimenti a giornale: l'eliminazione dal piano dei conti non è inibita.
    $this->actingAs($this->user)->delete(route('admin.gestionale.esercizi.piani-conti.conti.destroy', [$s['c'], $s['e'], $voce->piano_conto_id, $voce]))
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(Conto::find($voce->id))->toBeNull()
        ->and(avrVoci($f))->toBe([[null, null]]);
});
