<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Role as Ruolo;
use App\Models\ImpostazioniCondominio;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\AssembleeSettings;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/SalaDiViaRoma12.php';
require_once __DIR__ . '/../Pagine/RotteDelleAssemblee.php';

/**
 * Le impostazioni della sala. **Del condominio**: le teste nel caso misto, di serie «due teste», l'alternativa «una testa», ferma mentre un'assemblea è in
 * corso, con la riga nel registro delle modifiche. **Dello studio**, solo all'amministratore: la delega oltre il quinto (di serie «si rifiuta») e il voto
 * discorde dei comproprietari (di serie «astenuti»), con la riga nel registro.
 *
 * Che cosa non copre: la card a video (i vitest delle impostazioni).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('di serie: due teste, la delega oltre il quinto si rifiuta, il voto discorde conta astenuto', function () {
    $s = SalaDiViaRoma12::nuovo($this, false);
    expect(\App\Services\Assemblee\Sala\ImpostazioniDellaSala::perLaSala((int) $s->c->id))
        ->toBe(['delega_oltre_il_quinto' => 'rifiuta', 'voto_discorde_comproprietari' => 'astenuti', 'teste_caso_misto' => 'due_teste', 'ferme_all_apertura' => false]);
});

it('le teste del condominio si cambiano con la riga nel registro, e non mentre un\'assemblea è in corso', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    SalaDiViaRoma12::puoModificareIlCondominio($s);
    $altro = apUtente(null, [\App\Enums\Permission::MANAGE_ASSEMBLEE]);
    $this->actingAs($altro)->patch(route('admin.gestionale.assemblee.teste', [$s->c]), ['assemblee_teste' => 'una_testa'])->assertForbidden();
    $riga = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.teste', [$s->c]), ['assemblee_teste' => 'una_testa'])->assertRedirect()
        ->assertSessionHasNoErrors();
    $ultima = ViaRoma12::righe($riga)->last();
    expect(ImpostazioniCondominio::delCondominio((int) $s->c->id)->assemblee_teste)->toBe('una_testa')
        ->and(collect($ultima->campi)->firstWhere('chiave', 'assemblee_teste'))->toMatchArray(['etichetta' => 'Le teste nel caso misto',
            'dopo' => 'Una testa per le persone legate da una comproprietà']);

    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.teste', [$s->c]), ['assemblee_teste' => 'due_teste'])
        ->assertSessionHasErrors(['assemblee_teste' => 'Un\'assemblea del condominio è in corso: le teste si cambiano dopo la chiusura.']);
    expect(ImpostazioniCondominio::delCondominio((int) $s->c->id)->assemblee_teste)->toBe('una_testa');
});

it('le scelte dello studio le cambia solo l\'amministratore, con la riga nel registro', function () {
    $s = SalaDiViaRoma12::nuovo($this, false);
    $collaboratore = apUtente(Ruolo::COLLABORATORE->value, [\App\Enums\Permission::MANAGE_ASSEMBLEE]);
    $this->actingAs($collaboratore)->patch(route('impostazioni.assemblee.update'), ['delega_oltre_il_quinto' => 'decide_il_presidente',
        'voto_discorde_comproprietari' => 'astenuti'])->assertForbidden();
    $this->actingAs($collaboratore)->get(route('impostazioni.assemblee'))->assertForbidden();

    $s->admin->assignRole(Role::firstOrCreate(['name' => Ruolo::AMMINISTRATORE->value, 'guard_name' => 'web']));
    $riga = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->patch(route('impostazioni.assemblee.update'), ['delega_oltre_il_quinto' => 'decide_il_presidente',
        'voto_discorde_comproprietari' => 'astenuti'])->assertSessionHasNoErrors();
    expect(app(AssembleeSettings::class)->delega_oltre_il_quinto)->toBe('decide_il_presidente')
        ->and(collect(ViaRoma12::righe($riga)->last()->campi)->pluck('etichetta')->all())->toContain('La delega oltre un quinto');
    $this->get(route('impostazioni.assemblee'))->assertOk()
        ->assertInertia(fn ($p) => $p->component('impostazioni/ImpostazioniAssemblee', false)->where('impostazioni.delega_oltre_il_quinto', 'decide_il_presidente'));
});
