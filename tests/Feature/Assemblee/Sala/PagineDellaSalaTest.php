<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Ciò che le pagine della sala leggono dal server, fuori dalla sala stessa:
 *
 * - **la pagina dell'assemblea** apre il passo «In sala» con `?scheda=sala`, e la sala (le deleghe ricevute con il quinto, la fotografia provvisoria, le
 *   rotte) arriva quando il passo si apre, con una visita parziale, come la convocazione e il fascicolo;
 * - **le impostazioni del condominio** dicono la scelta delle teste nel caso misto con un esempio vero del condominio («Adesso: …») per le due posizioni, e se
 *   l'interruttore è fermo perché un'assemblea è in corso.
 *
 * Che cosa non copre: le pagine a video (i vitest della sala, del passo e delle impostazioni); il salvataggio delle teste (`ImpostazioniDellaSalaTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la pagina dell\'assemblea apre il passo «In sala» con ?scheda=sala, e la sala arriva quando il passo si apre', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone['rossi']->id,
        'delegato' => ['nome' => 'Bianchi Giorgio'], 'ricevuta_il' => '2026-11-26'])->assertSessionHasNoErrors();

    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.show', [$s->c, $a, 'scheda' => 'sala']))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('gestionale/assemblee/Assemblea')->where('scheda', 'sala')->missing('sala')
            ->reloadOnly('sala', fn (AssertableInertia $r) => $r->where('sala.deleghe.0.delegante_nome', 'Rossi Mario')
                ->where('sala.deleghe.0.quinto', '1 condòmino su 5 · 65,00 su 200,00')
                ->where('sala.fotografia.provvisoria', true)
                ->where('sala.rotte.deleghe', route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]))));
});

it('le impostazioni del condominio: la scelta delle teste con un esempio vero, nelle due posizioni', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->c->forceFill(['tabella_proprieta_id' => $s->tabelle['Proprietà']->id])->saveQuietly();
    // Russo Marco ha anche l'Int. 30 da solo: il caso misto.
    DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['30']->id)->update(['anagrafica_id' => $s->persone['marco']->id]);
    SalaDiViaRoma12::puoModificareIlCondominio($s);

    $this->actingAs($s->admin)->get(route('admin.gestionale.impostazioni.index', [$s->c]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('gestionale/struttura/ImpostazioniCondominio')
            ->where('assemblee.teste', 'due_teste')
            ->where('assemblee.ferma', null)
            ->where('assemblee.adesso.una_testa', 'Adesso: una testa. Russo Anna e Russo Marco contano una volta, con 97,75 millesimi (Int. 5 e Int. 30); il '
                . 'condominio ha 25 condòmini.')
            ->where('assemblee.adesso.due_teste', 'Adesso: due teste. Russo Marco per l\'Int. 30, e l\'Int. 5 a parte con il suo rappresentante; il condominio ha '
                . '26 condòmini. Se a rappresentare l\'Int. 5 è Russo Marco, conta per due.'));
});

it('le impostazioni del condominio senza il caso misto lo dicono; con un\'assemblea in corso l\'interruttore è fermo', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    SalaDiViaRoma12::puoModificareIlCondominio($s);

    $this->actingAs($s->admin)->get(route('admin.gestionale.impostazioni.index', [$s->c]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('assemblee.adesso.una_testa', 'Adesso: una testa. In questo condominio nessuno ha un\'unità da solo '
            . 'e un\'altra in comproprietà: le due scelte contano allo stesso modo, 26 condòmini.'));

    SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $this->actingAs($s->admin)->get(route('admin.gestionale.impostazioni.index', [$s->c]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('assemblee.ferma', 'Un\'assemblea del condominio è in corso: le teste si cambiano dopo la chiusura.'));
});

it('le impostazioni dello studio: anche i giorni di posta, da 1 a 10, con la riga nel registro; senza, restano com\'erano', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->admin->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => \App\Enums\Role::AMMINISTRATORE->value, 'guard_name' => 'web']));
    $riga = ViaRoma12::ultimaRiga();
    $this->actingAs($s->admin)->patch(route('impostazioni.assemblee.update'), ['delega_oltre_il_quinto' => 'rifiuta', 'voto_discorde_comproprietari' => 'astenuti',
        'giorni_di_posta' => 5])->assertSessionHasNoErrors();
    expect(app(\App\Settings\AssembleeSettings::class)->giorni_di_posta)->toBe(5)
        ->and(collect(ViaRoma12::righe($riga)->last()->campi)->pluck('etichetta')->all())->toContain('Giorni della posta per una raccomandata');

    $this->actingAs($s->admin)->patch(route('impostazioni.assemblee.update'), ['delega_oltre_il_quinto' => 'decide_il_presidente',
        'voto_discorde_comproprietari' => 'astenuti'])->assertSessionHasNoErrors();
    expect(app(\App\Settings\AssembleeSettings::class)->giorni_di_posta)->toBe(5);

    $this->actingAs($s->admin)->patch(route('impostazioni.assemblee.update'), ['delega_oltre_il_quinto' => 'rifiuta', 'voto_discorde_comproprietari' => 'astenuti',
        'giorni_di_posta' => 0])->assertSessionHasErrors(['giorni_di_posta' => 'I giorni di posta vanno da 1 a 10.']);
});

it('l\'archivio filtra le assemblee in corso, tenute in sala e deserte', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $per = fn (string $stato) => $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.index', [$s->c, 'stato' => $stato]))->assertOk()
        ->viewData('page')['props']['assemblee']['data'];
    expect(array_column($per('in_corso'), 'id'))->toBe([(int) $a->id])
        ->and($per('chiusa'))->toBe([])
        ->and($per('deserta'))->toBe([]);
});
