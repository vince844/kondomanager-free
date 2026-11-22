<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Evento;
use App\Models\Gestionale\RegistroInvio;
use App\Notifications\Assemblee\PromemoriaDellAssembleaNotification;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/VerbaleDiViaRoma12.php';

/**
 * La sospensione (decisione 595 (1)): «Sospendi» verso la data di prosecuzione dell'avviso manda di serie un promemoria — un'email a ognuno dei convocati
 * che ha l'indirizzo, con i punti che restano, e un avviso in bacheca per tutti, senza nomi — e lascia il compito «Riapri la sala giovedì 17/12», che si
 * chiude quando la sala si riapre. Con l'interruttore spento non parte nulla. La seduta sospesa è una riunione del verbale: alla sospensione i suoi testi
 * fanno una versione «scritta in sala: la discussione del punto 1».
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
    Notification::fake();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La seconda convocazione aperta il 15/12, il punto 1 votato, una prosecuzione fissata il 17/12 alle 18:30; tre convocati con l'email. */
function sepSala($t): array
{
    $s = SalaDiViaRoma12::nuovo($t);
    $a = SalaDiViaRoma12::ordinaria($s);
    \App\Models\Assemblee\SedutaDellAssemblea::create(['assemblea_id' => $a->id, 'convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => '2026-12-17 18:30']);
    Anagrafica::query()->whereNotNull('email')->update(['email' => null]);
    foreach (['rossi' => 'mario.rossi@example.org', 'c30' => 'c30@example.org', 'c31' => 'non-è-un-indirizzo'] as $chi => $email) {
        $s->persone[$chi]->forceFill(['email' => $email])->saveQuietly();
    }
    $a = SalaDiViaRoma12::convoca($a, $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($t, $s, $a);
    SalaDiViaRoma12::entrano($t, $s, $a, ['rossi', 'marco', 'rita', 'gallo', ...array_map(fn ($i) => 'c' . $i, range(30, 36))]);
    VerbaleDiViaRoma12::tuttiFavorevoli($t, $s, $a, 1, '2026-12-15 19:00');

    return [$s, $a, $seduta, SalaDiViaRoma12::seduta($a, 'seconda', 2)];
}

it('«Sospendi» manda un\'email ai convocati con l\'indirizzo e un avviso in bacheca senza nomi, e lascia «Riapri la sala»', function () {
    [$s, $a, $seduta, $prosegue] = sepSala($this);
    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $seduta->id, 'prosegue_seduta_id' => $prosegue->id],
        '2026-12-15 20:30'));
    expect($r['messaggio'])->toBe('Promemoria mandato: 2 email e l\'avviso in bacheca.');

    $email = RegistroInvio::query()->where('tipo', 'promemoria_assemblea')->orderBy('id')->get();
    expect($email->pluck('destinatario')->all())->toBe(['mario.rossi@example.org', 'c30@example.org'])
        ->and($email[0]->dati['oggetto'])->toBe('Via Roma 12: l\'assemblea prosegue giovedì 17/12 alle 18:30')
        ->and($email[0]->dati['righe'][0])->toContain('è stata sospesa alle 20:30 e prosegue giovedì 17 dicembre 2026 alle ore 18:30')
        ->and($email[0]->dati['righe'][1])->toStartWith('Restano da trattare: 2. ')
        ->and($email[0]->dati['righe'][2])->toContain('Questo è un promemoria');
    Notification::assertSentTimes(PromemoriaDellAssembleaNotification::class, 2);
    Notification::assertSentTo(new AnonymousNotifiable, PromemoriaDellAssembleaNotification::class,
        fn ($n, $canali, $chi) => $chi->routes['mail'] === 'mario.rossi@example.org');

    $bacheca = Comunicazione::query()->latest('id')->first();
    expect($bacheca->subject)->toBe('Assemblea ordinaria: prosegue giovedì 17/12 alle 18:30')
        ->and($bacheca->is_published)->toBeTruthy()->and($bacheca->condomini()->pluck('condomini.id')->all())->toBe([$s->c->id])
        ->and($bacheca->description)->toContain('Restano i punti 2, 3, 4, 5 e 6')->and($bacheca->description)->toContain('non vale come convocazione')
        ->and($bacheca->description)->not->toContain('Rossi')->and($bacheca->description)->not->toContain('Condomino');

    $riapri = Evento::query()->where('meta->type', 'riaprire_sala')->get();
    expect($riapri)->toHaveCount(1)->and($riapri[0]->title)->toBe('Riapri la sala giovedì 17/12 alle 18:30')
        ->and($riapri[0]->start_time->format('Y-m-d H:i'))->toBe('2026-12-17 09:00')->and($riapri[0]->is_completed)->toBeFalsy();

    ConvocazioneDiViaRoma12::oggi('2026-12-17 18:40');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('apri', ['seduta_id' => $prosegue->id,
        'presidente' => ['anagrafica_id' => $s->persone['rossi']->id], 'segretario' => ['nome' => 'L\'amministratore']], '2026-12-17 18:35'));
    expect(Evento::query()->where('meta->type', 'riaprire_sala')->first()->is_completed)->toBeTruthy();
});

it('con l\'interruttore spento non parte nulla, né email né bacheca; il compito resta', function () {
    [$s, $a, $seduta, $prosegue] = sepSala($this);
    $bacheca = Comunicazione::query()->count();
    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $seduta->id, 'prosegue_seduta_id' => $prosegue->id,
        'promemoria' => false], '2026-12-15 20:30'));
    expect($r['messaggio'])->toBeNull()->and(RegistroInvio::query()->where('tipo', 'promemoria_assemblea')->count())->toBe(0)
        ->and(Comunicazione::query()->count())->toBe($bacheca);
    Notification::assertNothingSent();
    expect(Evento::query()->where('meta->type', 'riaprire_sala')->count())->toBe(1);
});

it('alla sospensione i testi del verbale della seduta fanno una versione «scritta in sala»', function () {
    [$s, $a, $seduta, $prosegue] = sepSala($this);
    $verbale = \App\Services\Assemblee\Verbale\VerbaliDellAssemblea::perLaSeduta($seduta, $s->admin);
    $doc = \App\Services\Assemblee\Verbale\StrutturaDelVerbale::documento([\App\Services\Assemblee\Verbale\StrutturaDelVerbale::paragrafo('La discussione sul bilancio.')]);
    VerbaleDiViaRoma12::manda($this, $s, $a, [VerbaleDiViaRoma12::testo($verbale->id, 'punto:' . VerbaleDiViaRoma12::punto($a, 1), $doc, '2026-12-15 19:05', 0)]);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $seduta->id, 'prosegue_seduta_id' => $prosegue->id,
        'promemoria' => false], '2026-12-15 20:30'));
    $ultima = $verbale->versioni()->reorder()->orderByDesc("id")->first();
    expect($ultima->perche)->toBe("scritta in sala: la discussione del punto 1")->and($verbale->versioni()->count())->toBe(1)->and($ultima->parte)->toBe('punto:' . VerbaleDiViaRoma12::punto($a, 1));
});
