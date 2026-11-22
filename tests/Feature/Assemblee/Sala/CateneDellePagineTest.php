<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Delibera;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Le catene delle pagine della sala, da un capo all'altro, con le richieste nella forma in cui le mandano le pagine (`SegnaUnaDelegaRicevuta`, la coda di
 * `Sala.vue`, la sezione «Le assemblee» delle impostazioni del condominio) e le prop che le pagine leggono all'altro capo:
 *
 * 1. la delega segnata prima nel passo «In sala» → la pagina dell'assemblea la elenca con il quinto → la pagina della sala la trova, e il delegato porta il
 *    delegante;
 * 2. il voto in sala (con le due note del presidente) → l'esito del punto nella sala → la delibera e la lettura per il verbale, con le note;
 * 3. «Sospendi, prosegue il …» → la strada dice «sospesa · prosegue …» e la sala la prosecuzione da aprire quel giorno;
 * 4. «Una testa sola per chi ha anche una comproprietà» acceso (di serie è spento: due teste) → la fotografia della sala conta una testa → la barra.
 *
 * Che cosa non copre: le pagine a video (i vitest della sala, del passo e delle impostazioni, che leggono queste stesse prop).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La prop `sala` della pagina della sala, dalla rotta vera. @return array<string, mixed> */
function cdpSala($t, $s, $a): array
{
    $sala = null;
    $t->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala', [$s->c, $a]))->assertOk()
        ->assertInertia(function (AssertableInertia $p) use (&$sala) {
            $p->component('gestionale/assemblee/Sala', false);
            $sala = $p->toArray()['props']['sala'];
        });

    return $sala;
}

it('catena 1: la delega segnata prima nel passo «In sala» → la pagina dell\'assemblea la elenca con il quinto → la sala la trova, e il delegato porta il delegante', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 11:00');
    // Il corpo come lo manda «Segna una delega ricevuta».
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone['c30']->id,
        'delegato' => ['anagrafica_id' => $s->persone['rossi']->id], 'ricevuta_il' => '2026-12-10'])->assertSessionHasNoErrors();

    $this->get(route('admin.gestionale.assemblee.show', [$s->c, $a, 'scheda' => 'sala']))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('passi.3.riga', '1 delega · dal 14/12')
            ->reloadOnly('sala', fn (AssertableInertia $r) => $r->where('sala.deleghe.0.delegante_nome', 'Condomino 30')
                ->where('sala.deleghe.0.delegato_nome', 'Rossi Mario')->where('sala.deleghe.0.stato', 'valida')
                ->where('sala.deleghe.0.quinto', '1 condòmino su 5 · 27,75 su 200,00')));

    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $sala = cdpSala($this, $s, $a);
    expect($sala['deleghe'][0])->toMatchArray(['delegante_nome' => 'Condomino 30', 'chiave_delegato' => 'anagrafica:' . $s->persone['rossi']->id]);
    // Il gesto come lo manda la riga di Rossi Mario: «Entra», con la versione della presenza.
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['rossi']->id],
        'come' => 'in_sala'], '2026-12-15 18:50', $sala['versioni']['anagrafica:' . $s->persone['rossi']->id] ?? 0));
    $sala = cdpSala($this, $s, $a);
    expect(collect($sala['teste'])->firstWhere('nome', 'Condomino 30')['presente'])->toBe('Rossi Mario, per delega')
        ->and($sala['barra']['presenti'])->toContain($s->persone['c30']->id);
});

it('catena 2: il voto in sala con le note del presidente → l\'esito del punto nella sala → la delibera e la lettura per il verbale, con le note', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $p1 = $a->punti()->where('numero', 1)->firstOrFail();
    // I gesti come li manda la coda della pagina: «Si vota», «Tutti i presenti favorevoli», un contrario con la sua versione, le due note, «Conferma».
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $p1->id, 'oggetto' => 'rendiconto'],
        '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
    $votazione = collect(cdpSala($this, $s, $a)['punti'])->firstWhere('numero', 1)['votazioni'][0];
    $testa = 'p-' . $s->persone['c30']->id;
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v['votazione_id'], 'testa' => $testa, 'voto' => 'contrario'],
        '2026-12-15 19:02', collect($votazione['voti'])->firstWhere('testa', $testa)['versione']));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('nota', ['votazione_id' => $v['votazione_id'], 'su' => 'voto',
        'testo' => 'Discussione lunga sul fondo di riserva.'], '2026-12-15 19:03'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('nota', ['votazione_id' => $v['votazione_id'], 'su' => 'non_votato', 'testo' => 'Hanno votato tutti.'],
        '2026-12-15 19:03'));
    $votazione = collect(cdpSala($this, $s, $a)['punti'])->firstWhere('numero', 1)['votazioni'][0];
    expect($votazione['esito_proposto'])->toBe('approvata')
        ->and($votazione['nota'])->toBe('Discussione lunga sul fondo di riserva.');
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v['votazione_id'], 'esito' => 'approvata'], '2026-12-15 19:04',
        $votazione['versione']));

    // L'altro capo: la sala mostra l'esito sul punto, la delibera c'è, il verbale legge il voto con le note.
    expect(collect(cdpSala($this, $s, $a)['punti'])->firstWhere('numero', 1)['esito'])->toBe('approvata')
        ->and(Delibera::query()->where('punto_id', $p1->id)->value('deliberata_il')?->format('Y-m-d'))->toBe('2026-12-15');
    $verbale = collect(app(LetturaDellaSala::class)->perIlVerbale($a->refresh())['punti'])->firstWhere('numero', 1);
    expect($verbale['votazioni'][0])->toMatchArray(['esito' => 'approvata', 'nota' => 'Discussione lunga sul fondo di riserva.', 'nota_non_votato' => 'Hanno votato tutti.'])
        ->and($verbale['votazioni'][0]['conteggi']['contrari']['nomi'][0]['nome'])->toBe('Condomino 30')
        ->and($verbale['delibere'][0]['esito'])->toBe('approvata');
});

it('catena 3: «Sospendi, prosegue il …» → la strada dice «sospesa · prosegue …» e la sala la prosecuzione da aprire quel giorno', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::ordinaria($s);
    \App\Models\Assemblee\SedutaDellAssemblea::create(['assemblea_id' => $a->id, 'convocazione' => 'seconda', 'numero' => 2, 'fissata_il' => '2026-12-17 18:30']);
    $a = SalaDiViaRoma12::convoca($a, $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $prosegue = SalaDiViaRoma12::seduta($a, 'seconda', 2);
    // Il gesto come lo manda «Sospendi l'assemblea»: la data di prosecuzione scelta fra quelle dell'avviso.
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('sospendi', ['seduta_id' => $seduta->id, 'prosegue_seduta_id' => $prosegue->id], '2026-12-15 20:30'));

    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.show', [$s->c, $a]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('passi.3.chiave', 'sala')->where('passi.3.riga', 'sospesa · prosegue giovedì 17/12 alle 18:30')
            ->where('assemblea.apri_la_sala.testo', 'Torna in sala'));
    $sala = cdpSala($this, $s, $a);
    expect($sala['seduta_id'])->toBeNull()
        ->and($sala['da_aprire'])->toMatchArray(['seduta_id' => (int) $prosegue->id, 'numero' => 2, 'si_apre' => false])
        ->and(collect($sala['sedute'])->firstWhere('id', $seduta->id)['esito'])->toBe('sospesa');
});

it('catena 4: «Una testa sola per chi ha anche una comproprietà» acceso → la fotografia della sala conta una testa → la barra', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $s->c->forceFill(['tabella_proprieta_id' => $s->tabelle['Proprietà']->id])->saveQuietly();
    DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['30']->id)->update(['anagrafica_id' => $s->persone['marco']->id]);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    SalaDiViaRoma12::puoModificareIlCondominio($s);
    // Di serie due teste (le comunioni nate da titoli diversi restano distinte): Russo Marco per l'Int. 30, e l'Int. 5 a parte.
    expect(cdpSala($this, $s, $a)['fotografia']['costituzione']['totale_teste'])->toBe(26);

    // Il corpo come lo manda «Salva» della sezione «Le assemblee».
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.teste', [$s->c]), ['assemblee_teste' => 'una_testa'])->assertSessionHasNoErrors();
    $this->get(route('admin.gestionale.impostazioni.index', [$s->c]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('assemblee.teste', 'una_testa'));
    $sala = cdpSala($this, $s, $a);
    expect($sala['fotografia']['costituzione']['totale_teste'])->toBe(25)
        ->and($sala['fotografia']['teste_caso_misto'])->toBe('una_testa')
        ->and($sala['impostazioni']['teste_caso_misto'])->toBe('una_testa');

    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['marco']);
    expect(cdpSala($this, $s, $a)['barra']['barra'])->toBe('1 su 25 · 97,75 su 1.000,00')
        ->and($seduta->refresh()->fotografia['teste_caso_misto'])->toBe('una_testa');
});
