<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Services\Assemblee\GuardieDiCancellazione;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R15 del progetto della tappa 2): il condominio con una delibera non si elimina e la frase lo dice; con le sole assemblee in
 * preparazione si elimina, e se ne vanno con lui; la palazzina, la scala e la tabella usate da un'assemblea non si eliminano; l'esercizio con il punto del
 * suo rendiconto è trattenuto; l'annullamento di un'importazione vede le assemblee come lavoro fatto; il condominio di prova se ne va con tutto.
 *
 * Che cosa non copre: le pagine che mostrano le frasi (passano dai controller di oggi, con il loro messaggio).
 */

it('dalla rotta: il condominio con una delibera resta, con la frase; con la sola assemblea in preparazione si elimina', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->withoutVite();
    $andrea = \App\Models\User::factory()->create(['name' => 'Andrea Costa', 'email_verified_at' => now()]);
    $andrea->assignRole(\App\Enums\Role::AMMINISTRATORE->value);
    $s = ViaRoma12::nuovo($this);
    ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata');
    $this->actingAs($andrea)->from('/condomini')->delete(route('condomini.destroy', $s->c))->assertSessionHas('message.type', 'error');
    expect(Condominio::query()->whereKey($s->c->id)->exists())->toBeTrue()
        ->and(GuardieDiCancellazione::perIlCondominio($s->c))->toBe('«Via Roma 12» ha 1 assemblea convocata, tenuta o registrata a mano, 1 delibera, e per questo non può essere eliminato: le assemblee e le delibere restano nell\'archivio delle assemblee.');

    $t = ViaRoma12::nuovo($this, 'Corso Italia 3');
    ViaRoma12::ordinaria($t);
    expect(GuardieDiCancellazione::perIlCondominio($t->c))->toBeNull();
    $this->actingAs($andrea)->from('/condomini')->delete(route('condomini.destroy', $t->c))->assertSessionHas('message.type', 'success');
    expect(Condominio::query()->whereKey($t->c->id)->exists())->toBeFalse()->and(Assemblea::query()->where('condominio_id', $t->c->id)->count())->toBe(0);
});

it('palazzina, scala e tabella usate; l\'esercizio del punto; i segni di lavoro dell\'importazione', function () {
    $s = ViaRoma12::nuovo($this)->tabella('Proprietà', ['A' => 1000]);
    $palazzina = \App\Models\Palazzina::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    ViaRoma12::ordinaria($s, [['testo' => 'Rendiconto 2026', 'materia' => 'rendiconto', 'esercizio_id' => $s->e->id]],
        ['ambito' => 'palazzina', 'palazzina_id' => $palazzina->id, 'tabella_id' => $s->tabelle['Proprietà']->id]);

    expect(GuardieDiCancellazione::perLaPalazzina($palazzina))->toBe('La palazzina è usata da 1 assemblea, e per questo non può essere eliminata: cambiala prima nelle assemblee.')
        ->and(GuardieDiCancellazione::perLaTabella($s->tabelle['Proprietà']))->toStartWith('La tabella è usata da 1 assemblea')
        ->and(GuardieDiCancellazione::perLEsercizio($s->e->id))->toBe(['1 punto dell\'ordine del giorno di un\'assemblea (il rendiconto o il preventivo di questo esercizio)'])
        ->and((new ReflectionMethod(\App\Services\Import\AnnullamentoImportazione::class, 'impedimenti'))->invoke(app(\App\Services\Import\AnnullamentoImportazione::class), $s->c))
            ->toMatchArray(['assemblee' => 1]);
});

it('il condominio di prova se ne va con le sue assemblee e delibere', function () {
    $s = ViaRoma12::nuovo($this);
    $s->c->forceFill(['is_demo' => true])->saveQuietly();
    ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata');
    ViaRoma12::ordinaria($s);

    app(\App\Actions\Condominio\CreaCondominioDimostrativoAction::class)->rimuovi($s->c->fresh());
    expect(Condominio::query()->whereKey($s->c->id)->exists())->toBeFalse()
        ->and(\App\Models\Assemblee\Delibera::query()->count())->toBe(0);
});
