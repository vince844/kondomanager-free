<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\FatturaPassiva;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__ . '/../Gestionale/GestionaleTestHelpers.php';

/**
 * 1.12.0-beta.13 (Coda 679, §5 «La contabilità» e gesti 5–6 del progetto): la fattura è una scheda («Registrata, € …»); lo storno e l'eliminazione
 * dalla rotta sono un gesto solo, con le scritture (anche quelle cancellate per sempre) nel lotto, mai una riga per riga di giornale; la scrittura della
 * registrazione dice la sua riga «Scrittura registrata». In contabilità non c'è «Ripristina»: si storna.
 *
 * Che cosa non copre: gli incassi, i pagamenti e i giroconti dalle loro pagine, con la cifra nella riga (in `AreaScrittureRegistrateTest`); la
 * modifica della fattura dalla pagina (`FatturaPassivaService::aggiornaFattura`, lo stesso gesto, provato solo nel servizio dalle suite della fattura).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->andrea = User::factory()->create(['name' => 'Andrea Costa']);
    $this->andrea->assignRole($ruolo);
    $this->ctx = setupContabile();
});

function acontFattura(array $ctx, string $numero): FatturaPassiva
{
    return registraFatturaServiceTest($ctx, ['numero_documento' => $numero, 'data_documento' => '2026-03-01', 'righe' => [['descrizione' => 'Servizio',
        'importo_imponibile' => 1000, 'aliquota_iva' => 22, 'conto_id' => $ctx[4]->id, 'is_sopravvenienza' => false]]]);
}

it('la fattura registrata dice «Registrata» e la sua scrittura «Scrittura registrata»; lo storno dalla rotta è una riga sola', function () {
    $this->actingAs($this->andrea);
    $prima = RegistroModifica::query()->max('id') ?? 0;
    $fattura = acontFattura($this->ctx, 'FT-88');
    $registrazione = RegistroModifica::query()->where('id', '>', $prima)->get();
    expect($registrazione->firstWhere('oggetto_tipo', 'fattura_passiva')->riepilogo)->toBe('Registrata, € 1.220,00')
        ->and($registrazione->where('oggetto_tipo', 'scrittura_contabile')->pluck('riepilogo')->first())->toStartWith('Scrittura registrata:');

    $prima = RegistroModifica::query()->max('id');
    ContestoDelRegistro::svuotaScrittureFuoriGesto();
    $this->actingAs($this->andrea)->post(route('admin.gestionale.fatture.storno', [$this->ctx[0], $fattura]))->assertSessionHasNoErrors();

    $storno = RegistroModifica::query()->where('id', '>', $prima)->get();
    expect($storno)->toHaveCount(1)
        ->and($storno[0]->riepilogo)->toStartWith('Fattura n. FT-88 di ')
        ->and($storno[0]->riepilogo)->toContain('stornata: nota di credito ')
        ->and($storno[0]->rimando)->toMatchArray(['tipo' => 'fattura_passiva', 'id' => $fattura->id])
        ->and(array_keys($storno[0]->lotto['conteggi'] ?? []))->toContain('scrittura_contabile.creata')
        ->and(ContestoDelRegistro::scrittureFuoriGesto())->toBe([]);
});

it('la fattura eliminata dalla rotta: una riga sola, con le scritture tolte per sempre contate nel lotto', function () {
    $this->actingAs($this->andrea);
    $fattura = acontFattura($this->ctx, 'FT-89');
    $prima = RegistroModifica::query()->max('id');

    $this->actingAs($this->andrea)->delete(route('admin.gestionale.fatture.destroy', [$this->ctx[0], $fattura]))->assertSessionHasNoErrors();

    $righe = RegistroModifica::query()->where('id', '>', $prima)->get();
    expect(FatturaPassiva::query()->whereKey($fattura->id)->exists())->toBeFalse()
        ->and($righe)->toHaveCount(1)
        ->and($righe[0]->azione)->toBe('eliminata')
        ->and($righe[0]->riepilogo)->toStartWith('Fattura n. FT-89 di ')
        ->and($righe[0]->riepilogo)->toEndWith('eliminata, € 1.220,00: 1 scritture tolte');
});
