<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Anagrafica;
use App\Models\Assemblee\ConvocatoDellAssemblea;
use App\Models\Gestionale\RegistroInvio;
use App\Notifications\Assemblee\PromemoriaDellAssembleaNotification;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * Il promemoria della sospensione (decisione 595 (1)) e chi è convocato solo per alcuni punti (decisione 566): la catena «la sospensione → il promemoria →
 * solo i punti di ciascuno».
 *
 * - Ferri Luca, conduttore dell'Int. 12, è convocato per il solo punto 5 (il riscaldamento): la sua email dice solo il punto 5, mai il portone da € 14.500,00
 *   né la ratifica di € 1.800,00; chi è convocato per tutti riceve tutti i punti che restano.
 * - Se i suoi punti sono già trattati non riceve niente.
 * - Senza un server di posta nessuna email e nessuna riga «inviata»: il messaggio lo dice, e l'avviso è in bacheca.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Notification::fake();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La sala aperta il 15/12 con la prosecuzione del 17/12, il conduttore Ferri Luca e Rossi Mario con l'email; i punti votati prima di «Sospendi». */
function pspSala($t, array $votati = [1]): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::ordinaria($s);
    \App\Models\Assemblee\SedutaDellAssemblea::create(['assemblea_id' => $a->id, 'convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => '2026-12-17 18:30']);
    Anagrafica::query()->whereNotNull('email')->update(['email' => null]);
    $luca = Anagrafica::query()->where('nome', 'Ferri Luca')->firstOrFail();
    $luca->forceFill(['email' => 'ferri.luca@example.org'])->saveQuietly();
    $s->persone['rossi']->forceFill(['email' => 'mario.rossi@example.org'])->saveQuietly();
    $a = SalaDiViaRoma12::convoca($a, $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    SalaDiViaRoma12::entrano($t, $s, $a, ['rossi', 'marco', 'rita', 'gallo', ...array_map(fn ($i) => 'c' . $i, range(30, 36))]);
    foreach ($votati as $n) {
        VerbaleDiViaRoma12::tuttiFavorevoli($t, $s, $a, $n, '2026-12-15 19:0' . $n);
    }

    return [$s, $a, $seduta, SalaDiViaRoma12::seduta($a, 'seconda', 2), $luca];
}

it('al conduttore convocato per il solo punto 5 il promemoria dice solo il punto 5; agli altri tutti i punti che restano', function () {
    [$s, $a, $seduta, $prosegue, $luca] = pspSala($this);
    expect(ConvocatoDellAssemblea::query()->where('assemblea_id', $a->id)->where('anagrafica_id', $luca->id)->value('punti'))->toBe([5]);
    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $seduta->id, 'prosegue_seduta_id' => $prosegue->id],
        '2026-12-15 20:30'));
    expect($r['messaggio'])->toBe('Promemoria mandato: 2 email e l\'avviso in bacheca.');

    $delConduttore = RegistroInvio::query()->where('tipo', 'promemoria_assemblea')->where('anagrafica_id', $luca->id)->firstOrFail();
    $restano = collect((array) $delConduttore->dati['righe'])->first(fn ($r) => str_starts_with($r, 'Restano da trattare'));
    expect($restano)->toStartWith('Restano da trattare: 5. ')->not->toContain('2. ')->not->toContain('4. ')->not->toContain('14.500')
        ->not->toContain('1.800');
    $diRossi = RegistroInvio::query()->where('tipo', 'promemoria_assemblea')->where('anagrafica_id', $s->persone['rossi']->id)->firstOrFail();
    expect(collect((array) $diRossi->dati['righe'])->first(fn ($r) => str_starts_with($r, 'Restano da trattare')))->toContain('2. ')->toContain('5. ');
});

it('il conduttore con il suo punto già trattato non riceve il promemoria', function () {
    [$s, $a, $seduta, $prosegue, $luca] = pspSala($this, [1, 5]);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $seduta->id, 'prosegue_seduta_id' => $prosegue->id],
        '2026-12-15 20:30'));
    expect(RegistroInvio::query()->where('tipo', 'promemoria_assemblea')->where('anagrafica_id', $luca->id)->exists())->toBeFalse()
        ->and(RegistroInvio::query()->where('tipo', 'promemoria_assemblea')->where('anagrafica_id', $s->persone['rossi']->id)->exists())->toBeTrue();
});

it('senza un server di posta nessuna email e nessuna riga «inviata»: il messaggio lo dice, e l\'avviso è in bacheca', function () {
    config(['mail.default' => 'log']);
    [$s, $a, $seduta, $prosegue] = pspSala($this);
    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $seduta->id, 'prosegue_seduta_id' => $prosegue->id],
        '2026-12-15 20:30'));
    expect($r['messaggio'])->toBe('Promemoria: senza un server di posta le email non partono; l\'avviso è in bacheca.')
        ->and(RegistroInvio::query()->where('tipo', 'promemoria_assemblea')->count())->toBe(0)
        ->and(\App\Models\Comunicazione::query()->latest('id')->value('subject'))->toBe('Assemblea ordinaria: prosegue giovedì 17/12 alle 18:30');
    Notification::assertNothingSent();
});
