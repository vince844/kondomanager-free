<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Services\Assemblee\Sala\FoglioDellaSala;
use App\Services\Assemblee\Sala\PresenzeDellaSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use App\Settings\AssembleeSettings;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Le deleghe nei conti della sala (art. 67 c. 1 disp. att.):
 *
 * - **la delega oltre il quinto «da decidere»** (con «Decide il presidente») non conta, né nella barra né nel voto, finché il presidente non decide; accettata
 *   con il motivo conta da quel momento, rifiutata mai; il foglio della sala la scrive «da decidere dal presidente»;
 * - **la delega di un proprietario «per alcuni punti»** lo porta presente nella costituzione (è rappresentato in assemblea), e vota solo sui suoi punti; quella
 *   del conduttore resta fuori dalla costituzione, perché il conduttore non ne fa parte.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la delega oltre il quinto «da decidere» non conta finché il presidente non decide; accettata con il motivo, conta da allora', function () {
    $impostazioni = app(AssembleeSettings::class);
    $impostazioni->delega_oltre_il_quinto = AssembleeSettings::DECIDE_IL_PRESIDENTE;
    $impostazioni->save();
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    foreach (['c31', 'c32', 'c33', 'c34', 'c35', 'c36'] as $chi) {
        $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone[$chi]->id,
            'delegato' => ['anagrafica_id' => $s->persone['c30']->id], 'ricevuta_il' => '2026-12-10'])->assertSessionHasNoErrors();
    }
    $sesta = DelegaDellAssemblea::query()->latest('id')->first();
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['c30']);
    $conto = fn () => PresenzeDellaSala::conteggio((array) $seduta->refresh()->fotografia['costituzione']['teste'], PresenzeDellaSala::presenti($seduta))['teste'];
    $p1 = $a->punti()->where('numero', 1)->value('id');
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $p1], '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));

    expect($sesta->refresh()->dati['da_decidere'] ?? false)->toBeTrue()
        ->and($conto())->toBe(6)
        ->and(VotazioneDelPunto::query()->findOrFail($v['votazione_id'])->voti()->count())->toBe(6);

    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('delega_decidi', ['delega_id' => $sesta->id, 'accetta' => true,
        'motivo' => 'Condomino 36 è malato e si fida solo di lui'], '2026-12-15 19:05'));
    $p2 = $a->punti()->where('numero', 2)->value('id');
    $v2 = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $p2], '2026-12-15 19:10'));
    expect($conto())->toBe(7)
        ->and(VotazioneDelPunto::query()->findOrFail($v2['votazione_id'])->intervenuti['numero'])->toBe(7)
        ->and(PresenzeDellaSala::conteggio((array) $seduta->fotografia['costituzione']['teste'],
            PresenzeDellaSala::presenti($seduta, \Carbon\CarbonImmutable::parse('2026-12-15 19:02', 'Europe/Rome')))['teste'])->toBe(6);
});

it('il foglio della sala scrive la delega oltre il quinto «da decidere dal presidente», non come una delega valida', function () {
    $impostazioni = app(AssembleeSettings::class);
    $impostazioni->delega_oltre_il_quinto = AssembleeSettings::DECIDE_IL_PRESIDENTE;
    $impostazioni->save();
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    foreach (['c31', 'c32', 'c33', 'c34', 'c35', 'c36'] as $chi) {
        $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone[$chi]->id,
            'delegato' => ['anagrafica_id' => $s->persone['c30']->id], 'ricevuta_il' => '2026-12-10'])->assertSessionHasNoErrors();
    }

    $dati = app(FoglioDellaSala::class)->dati($a->refresh());
    expect(collect($dati['teste'])->firstWhere('nome', 'Condomino 36')['delega'])->toBe('da decidere dal presidente: delega a Condomino 30, oltre il quinto')
        ->and(collect($dati['teste'])->firstWhere('nome', 'Condomino 35')['delega'])->toBe('delega a Condomino 30')
        ->and(collect($dati['deleghe'])->firstWhere('chi', 'Condomino 36 a Condomino 30')['stato'])->toBe('da decidere dal presidente');
});

it('la delega di un proprietario «per alcuni punti» lo porta presente nella costituzione; sugli altri punti non vota', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $p1 = $a->punti()->where('numero', 1)->value('id');
    $p2 = $a->punti()->where('numero', 2)->value('id');
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone['rossi']->id,
        'delegato' => ['anagrafica_id' => $s->persone['c30']->id], 'ricevuta_il' => '2026-12-10', 'punti' => [$p1]])->assertSessionHasNoErrors();
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['c30']);

    $conto = PresenzeDellaSala::conteggio((array) $seduta->refresh()->fotografia['costituzione']['teste'], PresenzeDellaSala::presenti($seduta));
    $v1 = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $p1], '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v1['votazione_id']], '2026-12-15 19:01'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v1['votazione_id'], 'esito' => 'respinta'], '2026-12-15 19:02'));
    $v2 = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $p2], '2026-12-15 19:10'));

    expect($conto['teste'])->toBe(2)
        ->and(VotazioneDelPunto::query()->findOrFail($v1['votazione_id'])->intervenuti['numero'])->toBe(2)
        ->and(VotazioneDelPunto::query()->findOrFail($v2['votazione_id'])->intervenuti['numero'])->toBe(1);
});

it('la delega oltre il quinto accettata dal presidente nella prima resta accettata quando si apre la seconda: non si chiede di nuovo', function () {
    $impostazioni = app(AssembleeSettings::class);
    $impostazioni->delega_oltre_il_quinto = AssembleeSettings::DECIDE_IL_PRESIDENTE;
    $impostazioni->save();
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 10:00');
    foreach (['c31', 'c32', 'c33', 'c34', 'c35', 'c36'] as $chi) {
        $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone[$chi]->id,
            'delegato' => ['anagrafica_id' => $s->persone['c30']->id], 'ricevuta_il' => '2026-12-10'])->assertSessionHasNoErrors();
    }
    $sesta = DelegaDellAssemblea::query()->latest('id')->first();
    $prima = SalaDiViaRoma12::seduta($a, 'prima');
    ConvocazioneDiViaRoma12::oggi('2026-12-14 08:45');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => $prima->id, 'presidente' => ['anagrafica_id' => $s->persone['rossi']->id],
        'segretario' => ['nome' => 'L\'amministratore']], '2026-12-14 08:05'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('delega_decidi', ['delega_id' => $sesta->id, 'accetta' => true,
        'motivo' => 'Nessuno si oppone'], '2026-12-14 08:10'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('deserta', ['seduta_id' => $prima->id], '2026-12-14 08:40'));

    ConvocazioneDiViaRoma12::oggi('2026-12-15 21:00');
    $seconda = SalaDiViaRoma12::seduta($a);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => $seconda->id, 'presidente' => ['anagrafica_id' => $s->persone['rossi']->id],
        'segretario' => ['nome' => 'L\'amministratore']], '2026-12-15 18:42'));
    SalaDiViaRoma12::entrano($this, $s, $a, ['c30']);
    expect($sesta->refresh()->dati['da_decidere'] ?? false)->toBeFalse()
        ->and(PresenzeDellaSala::conteggio((array) $seconda->refresh()->fotografia['costituzione']['teste'], PresenzeDellaSala::presenti($seconda))['teste'])->toBe(7);
});
