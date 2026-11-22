<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\User;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DeliberaAMano;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;

require_once __DIR__ . '/../../Gestionale/SpesaNonPrevistaScenario.php';

/**
 * 1.12.0-beta.13 (Coda 679, §1.4 del progetto della tappa 2): la delibera nel modulo della fattura, dalla rotta. La **competenza** «Spesa deliberata il …»
 * (`delibera_competenza_id`) scrive il giorno della riunione nelle due date della testata e lega la fattura con il ruolo `competenza`; la **spesa imprevista**
 * con l'origine «Delibera assembleare» (la delibera arriva nei dati della finestra, `log_legale_sopravvenienza.delibera_id`) scrive il giorno della riunione
 * in `data_assemblea`, esce dai dati della fattura e lega con il ruolo `origine_della_spesa`. In modifica, la competenza senza la delibera toglie il
 * collegamento. Una delibera di un altro condominio è 404; senza «Gestisce le assemblee» 403, e niente si salva.
 *
 * Che cosa non copre: le due parti del modulo a video (`CompetenzaFattura.vue`, `ModalSpesaImprevista.vue`, con i loro test vitest); la spesa imprevista con
 * l'urgenza (non chiede una delibera, la provano i test di oggi); la modifica di una fattura con la delibera della spesa (la finestra c'è solo nella
 * registrazione).
 */

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-11-19 10:00', 'Europe/Rome'));
});

/** Lo scenario della spesa non prevista, con l'amministratore che gestisce le assemblee. */
function cosScenario($t): array
{
    $r = snpScenario($t);
    foreach ([Permesso::VIEW_ASSEMBLEE, Permesso::MANAGE_ASSEMBLEE] as $p) {
        Permission::firstOrCreate(['name' => $p->value, 'guard_name' => 'web']);
        $r['s']->admin->givePermissionTo($p->value);
    }
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    return $r;
}

/** La delibera registrata a mano: la riunione del 22/10/2026, verbale n. 41. */
function cosDelibera(array $r, string $testo = 'Riparazione della colonna di scarico')
{
    return DeliberaAMano::registra($r['s']->c, ['giorno' => '2026-10-22', 'testo' => $testo, 'esito' => 'approvata', 'numero_verbale' => '41'], null, $r['s']->admin);
}

/** Il corpo della registrazione con la riga fuori preventivo e i dati della finestra con la delibera. */
function cosCorpo(array $r, array $piu = [], ?int $deliberaSpesa = null): array
{
    $log = snpLogLegale($r)['log_legale_sopravvenienza'];
    $log['data_assemblea'] = '';
    if ($deliberaSpesa !== null) {
        $log['delibera_id'] = $deliberaSpesa;
    }

    return array_merge([
        'fornitore_id' => $r['fornitoreId'], 'esercizio_id' => $r['s']->e->id, 'gestione_id' => $r['g']->id, 'tipo_documento' => 'fattura', 'numero_documento' => 'FT-DEL',
        'data_documento' => '2026-10-20', 'data_scadenza' => '2026-11-20', 'modalita_pagamento' => 'bonifico', 'stato_approvazione' => 'approvata',
        'dati_extra' => ['fiscal' => [], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => $log],
        'righe' => [['descrizione' => 'Riparazione colonna', 'importo_imponibile' => 1000.00, 'aliquota_iva' => 22, 'conto_id' => null, 'is_sopravvenienza' => true]],
    ], $piu);
}

it('dalla rotta: la competenza e la spesa imprevista prendono il giorno della riunione, e la fattura si lega con i due ruoli', function () {
    $r = cosScenario($this);
    $d = cosDelibera($r);

    $this->actingAs($r['s']->admin)->post(route('admin.gestionale.fatture.store', [$r['s']->c]), cosCorpo($r, ['delibera_competenza_id' => $d->id], $d->id))
        ->assertSessionHasNoErrors();

    $f = FatturaPassiva::query()->where('condominio_id', $r['s']->c->id)->where('numero_documento', 'FT-DEL')->sole();
    expect($f->competenza_dal?->format('Y-m-d'))->toBe('2026-10-22')->and($f->competenza_al?->format('Y-m-d'))->toBe('2026-10-22')
        ->and($f->dati_extra['log_legale_sopravvenienza']['data_assemblea'])->toBe('2026-10-22')
        ->and($f->dati_extra['log_legale_sopravvenienza'])->not->toHaveKey('delibera_id')
        ->and(CollegamentiDelleDelibere::delibera($f, 'competenza')?->id)->toBe($d->id)
        ->and(CollegamentiDelleDelibere::delibera($f, 'origine_della_spesa')?->id)->toBe($d->id);
});

it('in modifica, la competenza senza la delibera toglie il collegamento; la stessa delibera di prima non cambia niente', function () {
    $r = cosScenario($this);
    $d = cosDelibera($r);
    $this->actingAs($r['s']->admin)->post(route('admin.gestionale.fatture.store', [$r['s']->c]), cosCorpo($r, ['delibera_competenza_id' => $d->id]))
        ->assertSessionHasNoErrors();
    $f = FatturaPassiva::query()->where('condominio_id', $r['s']->c->id)->where('numero_documento', 'FT-DEL')->sole();

    $this->actingAs($r['s']->admin)->put(route('admin.gestionale.fatture.update', [$r['s']->c, $f]),
        snpCorpoModifica($f, ['competenza_dal' => '2026-10-22', 'competenza_al' => '2026-10-22', 'delibera_competenza_id' => $d->id]))->assertSessionHasNoErrors();
    expect(CollegamentiDelleDelibere::delibera($f->fresh(), 'competenza')?->id)->toBe($d->id);

    $this->actingAs($r['s']->admin)->put(route('admin.gestionale.fatture.update', [$r['s']->c, $f]), snpCorpoModifica($f, ['delibera_competenza_id' => null]))
        ->assertSessionHasNoErrors();
    expect(CollegamentiDelleDelibere::delibera($f->fresh(), 'competenza'))->toBeNull()->and($f->fresh()->competenza_dal)->toBeNull();
});

it('una delibera di un altro condominio è 404, e la fattura non nasce', function () {
    $r = cosScenario($this);
    $altro = cosScenario($this);
    $dAltrove = cosDelibera($altro);

    $this->actingAs($r['s']->admin)->post(route('admin.gestionale.fatture.store', [$r['s']->c]), cosCorpo($r, ['delibera_competenza_id' => $dAltrove->id]))
        ->assertNotFound();
    $this->actingAs($r['s']->admin)->post(route('admin.gestionale.fatture.store', [$r['s']->c]), cosCorpo($r, [], $dAltrove->id))->assertNotFound();
    expect(FatturaPassiva::query()->where('condominio_id', $r['s']->c->id)->where('numero_documento', 'FT-DEL')->exists())->toBeFalse();
});

it('senza «Gestisce le assemblee» la delibera nel modulo è 403, e la fattura non nasce; senza la delibera la fattura si registra come prima', function () {
    $r = snpScenario($this);
    $d = cosDelibera($r);
    /** @var User $admin */
    $admin = $r['s']->admin;

    $this->actingAs($admin)->post(route('admin.gestionale.fatture.store', [$r['s']->c]), cosCorpo($r, ['delibera_competenza_id' => $d->id]))->assertForbidden();
    expect(FatturaPassiva::query()->where('condominio_id', $r['s']->c->id)->where('numero_documento', 'FT-DEL')->exists())->toBeFalse();

    $corpo = cosCorpo($r);
    $corpo['dati_extra']['log_legale_sopravvenienza']['data_assemblea'] = '2026-10-15';
    $this->actingAs($admin)->post(route('admin.gestionale.fatture.store', [$r['s']->c]), $corpo)->assertSessionHasNoErrors();
    expect(FatturaPassiva::query()->where('condominio_id', $r['s']->c->id)->where('numero_documento', 'FT-DEL')->sole()->dati_extra['log_legale_sopravvenienza']['data_assemblea'])
        ->toBe('2026-10-15');
});

it('senza «Gestisce le assemblee» il permesso viene prima del modulo: una delibera di un altro condominio è 403, non 404 né gli errori dei campi', function () {
    $r = snpScenario($this);
    $altro = cosScenario($this);
    $dAltrove = cosDelibera($altro);
    $corpo = cosCorpo($r, ['delibera_competenza_id' => $dAltrove->id, 'numero_documento' => '']);

    $this->actingAs($r['s']->admin)->post(route('admin.gestionale.fatture.store', [$r['s']->c]), $corpo)->assertForbidden();
});
