<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Gli interessi e le spese del defunto nella successione (Coda 652, l'accessorio con un pagamento segnalato): i casi che le prime prove non coprivano.
 * Neri con le spese di sollecito su U1 (senza fattura), la successione dal 01/09/2026 ad Anna e Bruno al 50 %, registrata il 10/09.
 *
 * - Il promemoria del defunto, dopo lo storno di un suo versamento di prima, dice quello che la sua quota deve, non l'importo di prima.
 * - L'annullamento rimette la quota del defunto con lo stato che dicono l'importo e il versato di oggi.
 * - L'accessorio rimasto al defunto per la segnalazione, o passato comunque agli eredi, resta scritto nel registro del passaggio e nello storico.
 * - La frase dell'arretrato non dice che la posizione del defunto si chiude quando un accessorio segnalato resta a suo nome.
 * - Passato comunque, il pagamento vero si registra dal compito della segnalazione, sulla quota di ogni erede con «Versato da» il defunto.
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

function sacErede(array $s, string $nome): \App\Models\Anagrafica
{
    $p = \App\Models\Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . '-sac-' . $s['c']->id . '@test.it', 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'SAC' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** Neri con il sollecito dato, versato per i centesimi dati il 12/07; la successione pronta. [scenario, rata, Anna, Bruno, corpo, incasso di Neri]. */
function sacSollecito($test, string $euro, int $versati): array
{
    $s = accViaVerdi($test);
    $test->travelTo('2026-07-10 10:00:00');
    $rataId = (int) adbRegistra($test, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => $euro, 'causale' => 'Spese di sollecito', 'scadenza' => '2026-07-10',
        'natura' => 'accessorio', 'tipo_accessorio' => 'sollecito', 'base' => 'delibera', 'riferimento' => 'del 14/03/2026, verbale n. 1', 'conto_id' => $s['postali']->id]])
        ->assertCreated()->json('rate.0');
    $incasso = null;
    if ($versati > 0) {
        $test->travelTo('2026-07-12 10:00:00');
        $incasso = lcdIncassa($test, $s, 'Neri', [adbQuota($rataId)->id => $versati], '2026-07-12');
    }
    $anna = sacErede($s, 'Anna');
    $bruno = sacErede($s, 'Bruno');
    $test->travelTo('2026-09-10 10:00:00');
    $corpo = ['tipo' => 'successione', 'riga_uscente_id' => $s['righe']['Neri'], 'decorrenza' => '2026-09-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $anna->id, 'quota' => 50], ['anagrafica_id' => $bruno->id, 'quota' => 50]], 'arretrato' => 'eredi', 'erede_di_riferimento' => $anna->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta'];

    return [$s, $rataId, $anna, $bruno, $corpo, $incasso];
}

function sacPromemoria(int $rataId, int $persona): \App\Models\Evento
{
    return \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->context->rata_id', $rataId)
        ->whereHas('anagrafiche', fn ($q) => $q->where('anagrafica_id', $persona))->firstOrFail();
}

/** Neri segnala dal portale il pagamento del sollecito, il 20/07; poi si torna al 10/09. */
function sacSegnala($test, array $s, int $rataId): void
{
    $portale = \App\Models\User::factory()->create(['email_verified_at' => now()]);
    $portale->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => \App\Enums\Permission::VIEW_EVENTS->value, 'guard_name' => 'web']));
    $s['persone']['Neri']->update(['user_id' => $portale->id]);
    $test->travelTo('2026-07-20 10:00:00');
    $test->actingAs($portale)->post(route('user.eventi.report_payment', sacPromemoria($rataId, (int) $s['persone']['Neri']->id)))->assertSessionHas('success');
    $test->travelTo('2026-09-10 10:00:00');
}

function sacPassaggio(array $s): Subentro
{
    return Subentro::where('immobile_id', $s['unita']['U1']->id)->latest('id')->firstOrFail();
}

/** La frase dell'arretrato nello storico dell'unità, per il passaggio dato. */
function sacStorico(array $s, Subentro $passaggio): string
{
    $voce = collect(app(\App\Services\Subentro\StoricoTitolarita::class)->perImmobile($s['unita']['U1']->fresh())['subentri'])->firstWhere('id', $passaggio->id);

    return (string) ($voce['arretrato']['frase'] ?? '');
}

it('lo storno, dopo la successione, del versamento di € 8,99 che Neri aveva fatto prima: il suo promemoria dice € 8,99 da versare, come la sua quota', function () {
    [$s, $rataId, , , $corpo, $incasso] = sacSollecito($this, '25,00', 899);
    $neri = (int) $s['persone']['Neri']->id;
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();

    $this->travelTo('2026-09-15 10:00:00');
    lcdStorna($this, $s, $incasso);
    $quota = RataQuote::where('rata_id', $rataId)->where('anagrafica_id', $neri)->first();
    expect([(int) $quota->importo, (int) $quota->importo_pagato])->toBe([899, 0]);
    $meta = sacPromemoria($rataId, $neri)->fresh()->meta;
    expect([$meta['status'], $meta['importo_restante']])->toBe(['pending', 899]);
});

it('annullata la successione dopo quello storno, la quota di Neri torna a € 25,00 «da pagare»: con niente versato non è «parzialmente pagata»', function () {
    [$s, $rataId, , , $corpo, $incasso] = sacSollecito($this, '25,00', 899);
    $neri = (int) $s['persone']['Neri']->id;
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();
    $this->travelTo('2026-09-15 10:00:00');
    lcdStorna($this, $s, $incasso);

    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], sacPassaggio($s)]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertRedirect();
    $quota = RataQuote::where('rata_id', $rataId)->where('anagrafica_id', $neri)->first();
    expect([$quota->stato, (int) $quota->importo, (int) $quota->importo_pagato])->toBe(['da_pagare', 2500, 0])
        ->and(array_intersect_key(sacPromemoria($rataId, $neri)->fresh()->meta, array_flip(['status', 'importo_restante'])))->toBe(['status' => 'pending', 'importo_restante' => 2500]);
});

it('senza lo storno, l\'annullamento rimette la quota di Neri com\'era: € 25,00 con € 8,99 versati, «parzialmente pagata»', function () {
    [$s, $rataId, , , $corpo] = sacSollecito($this, '25,00', 899);
    $neri = (int) $s['persone']['Neri']->id;
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], sacPassaggio($s)]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertRedirect();
    $quota = RataQuote::where('rata_id', $rataId)->where('anagrafica_id', $neri)->first();
    expect([$quota->stato, (int) $quota->importo, (int) $quota->importo_pagato])->toBe(['parzialmente_pagata', 2500, 899]);
});

it('di serie l\'accessorio segnalato rimasto a Neri resta scritto nel registro del passaggio, e lo storico lo dice', function () {
    [$s, $rataId, , , $corpo] = sacSollecito($this, '16,01', 0);
    sacSegnala($this, $s, $rataId);
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();

    $passaggio = sacPassaggio($s);
    expect($passaggio->registro['arretrato']['restati_al_defunto'] ?? null)->toBe([['quota_id' => (int) adbQuota($rataId)->id, 'causale' => 'Spese di sollecito', 'aperto' => 1601]])
        ->and($passaggio->registro['arretrato']['accessori'] ?? null)->toBeNull()
        ->and(sacStorico($s, $passaggio))->toContain('«Spese di sollecito» (€ 16,01) è rimasto a nome di Neri: aveva un pagamento segnalato dal portale, da verificare.');
});

it('passato comunque agli eredi, il registro del passaggio dice che l\'accessorio era segnalato, e lo storico lo dice', function () {
    [$s, $rataId, , , $corpo] = sacSollecito($this, '16,01', 0);
    sacSegnala($this, $s, $rataId);
    $corpo['accessori_segnalati_agli_eredi'] = [(int) adbQuota($rataId)->id];
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();

    $passaggio = sacPassaggio($s);
    expect($passaggio->registro['arretrato']['accessori'][0]['segnalato'] ?? null)->toBeTrue()
        ->and($passaggio->registro['arretrato']['restati_al_defunto'] ?? null)->toBeNull()
        ->and(sacStorico($s, $passaggio))->toContain('«Spese di sollecito» (€ 16,01) è passato agli eredi con un pagamento segnalato dal portale ancora da verificare: l\'ha scelto chi ha registrato il passaggio.');
});

it('di serie la frase dell\'arretrato non dice che la posizione di Neri si chiude senza dire che il sollecito segnalato resta a suo nome', function () {
    [$s, $rataId, , , $corpo] = sacSollecito($this, '16,01', 0);
    sacSegnala($this, $s, $rataId);

    $rate = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), $corpo)->assertOk()->json('rate');
    expect((string) $rate['arretrato']['frase'])->toEndWith('e la posizione di Neri si chiude, salvo «Spese di sollecito» (€ 16,01), che resta a suo nome finché la segnalazione non è verificata.');

    // Spuntato «passa comunque», la frase torna quella di sempre.
    $corpo['accessori_segnalati_agli_eredi'] = [(int) adbQuota($rataId)->id];
    $rate = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), $corpo)->assertOk()->json('rate');
    expect((string) $rate['arretrato']['frase'])->toEndWith('e la posizione di Neri si chiude.');
});

it('passato comunque: la frase dice come si registra il pagamento vero, e la strada funziona: dal compito, sulla quota di ogni erede con «Versato da» Neri', function () {
    [$s, $rataId, $anna, $bruno, $corpo] = sacSollecito($this, '16,01', 0);
    $neri = (int) $s['persone']['Neri']->id;
    sacSegnala($this, $s, $rataId);
    $corpo['accessori_segnalati_agli_eredi'] = [(int) adbQuota($rataId)->id];
    $frasi = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), $corpo)->assertOk()->json('rate.frasi');
    expect($frasi)->toContain('«Spese di sollecito» (€ 16,01) ha un pagamento segnalato dal portale e non ancora verificato: come hai scelto, passa comunque agli eredi per '
        . 'quota, e la segnalazione resta da verificare. Se il pagamento risulta arrivato, registralo con «Registra l\'incasso» dal compito del bonifico segnalato, '
        . 'sulla quota di ogni erede con «Versato da» Neri: il primo incasso chiude il compito.');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $corpo)->assertSessionHasNoErrors();

    // Il bonifico di € 16,01 è arrivato. Dal compito: la posizione di Anna, «Versato da» Neri, la sua quota; poi quella di Bruno.
    $compito = \App\Models\Evento::where('tipo', \App\Enums\EventoTipo::VERIFICA_PAGAMENTO->value)->latest('id')->firstOrFail();
    $this->travelTo('2026-09-12 10:00:00');
    foreach ([[$anna, 801, true], [$bruno, 800, false]] as [$erede, $cents, $dalCompito]) {
        $quota = (int) RataQuote::where('rata_id', $rataId)->where('anagrafica_id', $erede->id)->value('id');
        $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
            'pagante_id' => $erede->id, 'versato_da_id' => $neri, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-09-12',
            'importo_totale' => number_format($cents / 100, 2, '.', ''), 'descrizione' => 'Bonifico di Neri', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id,
            'dettaglio_pagamenti' => [['rata_id' => $quota, 'importo' => number_format($cents / 100, 2, '.', '')]],
        ] + ($dalCompito ? ['related_task_id' => $compito->id] : []))->assertSessionHasNoErrors();
    }

    expect($compito->fresh()->is_completed)->toBeTrue()
        ->and(sacPromemoria($rataId, $neri)->fresh()->meta['status'])->toBe('paid')
        ->and(sacPromemoria($rataId, (int) $anna->id)->fresh()->meta['status'])->toBe('paid')
        ->and(sacPromemoria($rataId, (int) $bruno->id)->fresh()->meta['status'])->toBe('paid')
        ->and(RataQuote::where('rata_id', $rataId)->whereIn('anagrafica_id', [$anna->id, $bruno->id])->pluck('stato')->unique()->values()->all())->toBe(['pagata']);
});
