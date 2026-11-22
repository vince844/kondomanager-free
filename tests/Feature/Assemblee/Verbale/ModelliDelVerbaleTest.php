<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Condominio;
use App\Models\ModelloTesto;
use App\Models\User;
use App\Services\Assemblee\Verbale\ModelliDelVerbale;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * I modelli del verbale (V12, P4, scena 9): nella pagina «Modelli delle lettere», sezione «Il verbale dell'assemblea»: il testo del programma (non si modifica),
 * i modelli dello studio con i due testi («Prima dei punti», «Dopo i punti») nella struttura dell'editor, il nome unico per «vale per», niente parti «Dati della
 * sala» nel modello; li cambia chi gestisce le impostazioni di stampa. «Genera» propone il modello del condominio, se c'è, se no l'ultimo usato.
 */
beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $pannello = Permission::firstOrCreate(['name' => \App\Enums\Permission::ACCESS_ADMIN_PANEL->value, 'guard_name' => 'web']);
    $impostazioni = Permission::firstOrCreate(['name' => \App\Enums\Permission::MANAGE_GENERAL_SETTINGS->value, 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo([$pannello, $impostazioni]);
    $collaboratore = Role::firstOrCreate(['name' => 'collaboratore di prova', 'guard_name' => 'web']);
    $collaboratore->givePermissionTo($pannello);
    $this->admin = User::factory()->create(['name' => 'Andrea Costa']);
    $this->admin->assignRole($ruolo);
    $this->collaboratore = User::factory()->create();
    $this->collaboratore->assignRole($collaboratore);
    $this->roma = Condominio::factory()->create(['nome' => 'Via Roma 12']);
    $this->verdi = Condominio::factory()->create(['nome' => 'Via Verdi 3']);
});

function mdvTesti(string $prima = 'Il giorno [giorno], alle ore [ora], in [convocazione].', string $dopo = 'Chiusa alle [ora_di_chiusura].'): array
{
    return ['prima' => StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo($prima)]),
        'dopo' => StrutturaDelVerbale::documento([StrutturaDelVerbale::paragrafo($dopo)])];
}

it('un modello si salva con i due testi; il nome è unico per «vale per»; le parti «Dati della sala» non stanno nel modello; la pagina lo mostra con il testo del programma', function () {
    $this->actingAs($this->admin)->post(route('impostazioni.stampe.modelli-verbale.store'), ['nome' => 'Verbale ordinario dello studio', 'condominio_id' => null]
        + mdvTesti())->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post(route('impostazioni.stampe.modelli-verbale.store'), ['nome' => ' verbale ordinario  dello studio', 'condominio_id' => null]
        + mdvTesti())->assertSessionHasErrors(['nome' => ModelliDelVerbale::NOME_DOPPIO]);
    $this->actingAs($this->admin)->post(route('impostazioni.stampe.modelli-verbale.store'), ['nome' => 'Con i dati', 'condominio_id' => null,
        'prima' => StrutturaDelVerbale::documento([StrutturaDelVerbale::datiDellaSala('voto:1', ['x'])]), 'dopo' => mdvTesti()['dopo']])
        ->assertSessionHasErrors(['prima' => 'Le parti «Dati della sala» le mette il programma: nel modello non si scrivono.']);
    $this->actingAs($this->admin)->post(route('impostazioni.stampe.modelli-verbale.store'), ['nome' => 'Ostile', 'condominio_id' => null,
        'prima' => ['type' => 'doc', 'content' => [['type' => 'image']]], 'dopo' => mdvTesti()['dopo']])
        ->assertSessionHasErrors(['prima' => 'Il testo del verbale ha una parte che il programma non conosce: «image».']);

    $modello = ModelloTesto::query()->where('ambito', 'verbale')->firstOrFail();
    $pagina = $this->actingAs($this->admin)->get(route('impostazioni.stampe.modelli-lettere'))->assertOk()->viewData('page')['props']['verbale'];
    expect($modello->tipo)->toBe('verbale')->and(ModelliDelVerbale::testi($modello)['prima'])->toBe(mdvTesti()['prima'])
        ->and(collect($pagina['modelli'])->pluck('nome')->all())->toBe(['Verbale ordinario dello studio'])
        ->and(StrutturaDelVerbale::testo($pagina['testo_del_programma']['prima']))->toContain('[convocazione]')
        ->and(array_keys($pagina['campi']))->toBe(['[condominio]', '[tipo]', '[giorno]', '[ora]', '[luogo]', '[convocazione]', '[presidente]', '[segretario]', '[ora_di_chiusura]']);
});

it('cambiare ed eliminare un modello è di chi gestisce le impostazioni di stampa; i modelli delle lettere non sono del verbale', function () {
    $this->actingAs($this->collaboratore)->post(route('impostazioni.stampe.modelli-verbale.store'), ['nome' => 'X', 'condominio_id' => null] + mdvTesti())->assertForbidden();
    $lettera = ModelloTesto::create(['ambito' => 'lettera', 'tipo' => 'sollecito', 'nome' => 'Sollecito', 'testo' => 'x']);
    $this->actingAs($this->admin)->delete(route('impostazioni.stampe.modelli-verbale.destroy', $lettera->id))->assertNotFound();
    $this->actingAs($this->admin)->post(route('impostazioni.stampe.modelli-verbale.store'), ['nome' => 'Mio', 'condominio_id' => null] + mdvTesti())->assertSessionHasNoErrors();
    $mio = ModelloTesto::query()->where('ambito', 'verbale')->firstOrFail();
    $this->actingAs($this->admin)->put(route('impostazioni.stampe.modelli-verbale.update', $mio->id), ['nome' => 'Mio cambiato', 'condominio_id' => null] + mdvTesti('Nuovo [giorno].'))
        ->assertSessionHasNoErrors();
    expect($mio->refresh()->nome)->toBe('Mio cambiato')->and(StrutturaDelVerbale::testo(ModelliDelVerbale::testi($mio)['prima']))->toBe('Nuovo [giorno].');
    $this->actingAs($this->admin)->delete(route('impostazioni.stampe.modelli-verbale.destroy', $mio->id))->assertSessionHasNoErrors();
    expect(ModelloTesto::query()->where('ambito', 'verbale')->count())->toBe(0);
});

it('il menu di «Genera» propone il modello del condominio, se c\'è; quello di un altro condominio non si vede e non si usa', function () {
    $m = app(ModelliDelVerbale::class);
    $tutti = $m->salva(null, ['nome' => 'Per tutti', 'condominio_id' => null] + mdvTesti(), $this->admin);
    $verdi = $m->salva(null, ['nome' => 'Di Via Verdi', 'condominio_id' => $this->verdi->id] + mdvTesti(), $this->admin);
    $menu = ModelliDelVerbale::perIlMenu($this->roma);
    expect(collect($menu['modelli'])->map(fn ($x) => [$x['nome'], $x['gruppo']])->all())->toBe([['Per tutti', 'tutti'], ['Testo del programma', 'programma']])
        ->and($menu['proposto'])->toBeNull();
    $roma = $m->salva(null, ['nome' => 'Di Via Roma', 'condominio_id' => $this->roma->id] + mdvTesti(), $this->admin);
    expect(ModelliDelVerbale::perIlMenu($this->roma)['proposto'])->toBe($roma->id)
        ->and(fn () => ModelliDelVerbale::scelto($this->roma, $verdi->id))->toThrow(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class)
        ->and(ModelliDelVerbale::scelto($this->roma, $tutti->id)['nome'])->toBe('Per tutti')
        ->and(ModelliDelVerbale::scelto($this->roma, null)['nome'])->toBe('Testo del programma');
});
