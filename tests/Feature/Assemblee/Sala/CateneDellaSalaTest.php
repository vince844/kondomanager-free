<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Http\Controllers\Assemblee\PerLePagine;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\Assemblee\VotazioneDelPunto;
use App\Models\Scala;
use App\Models\Tabella;
use App\Services\Assemblee\DeliberaInParole;
use App\Services\Assemblee\Sala\LetturaDellaSala;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Le catene della sala, ognuna da un capo all'altro: da dove parte, dove arriva, che cosa vede l'amministratore ai due capi.
 *
 * 1. La delega ricevuta prima («Deleghe ricevute», nel passo «In sala») → il conto del quinto → la sala che la trova già segnata.
 * 2. La presenza e il voto in sala → l'esito del punto → la delibera e ciò che il verbale e il seguito delle delibere leggono ({@see LetturaDellaSala::perIlVerbale()}).
 * 3. L'usufrutto e la comproprietà dall'anagrafica e dalle unità → chi vota su quel punto.
 * 4. La scelta del condominio «due teste» (di serie) / «una testa» → il conto della costituzione.
 * 5. Il punto della sola scala con la sua tabella → la riga della scala nella barra, che avvisa e non blocca.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('catena 1: la delega ricevuta prima → il quinto al momento → la sala la trova già segnata, e il delegato porta il delegante', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 11:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone['rossi']->id,
        'delegato' => ['nome' => 'Bianchi Giorgio'], 'ricevuta_il' => '2026-12-10'])->assertSessionHasNoErrors();
    // Il primo capo: il passo «In sala» la conta, con il quinto al momento.
    $passo = collect(PerLePagine::passi($a->refresh()))->firstWhere('chiave', 'sala');
    expect($passo['riga'])->toBe('1 delega · dal 14/12')
        ->and(DelegaDellAssemblea::query()->first()->dati['quinto']['frase'])->toBe('1 condòmino su 5 · 65,00 su 200,00');

    // L'altro capo: in sala la delega c'è già; entra il delegato, e la testa di Rossi è presente per delega.
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect($sala['deleghe'][0])->toMatchArray(['delegante_nome' => 'Rossi Mario', 'delegato_nome' => 'Bianchi Giorgio', 'arrivata_come' => 'carta_prima',
        'stato' => 'valida', 'quinto' => '1 condòmino su 5 · 65,00 su 200,00']);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('entra', ['seduta_id' => $seduta->id, 'persona' => ['delega_id' => $sala['deleghe'][0]['id']],
        'come' => 'in_sala'], '2026-12-15 18:50'));
    $sala = app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin);
    expect(collect($sala['teste'])->firstWhere('nome', 'Rossi Mario')['presente'])->toBe('Bianchi Giorgio, per delega')
        ->and($sala['barra']['barra'])->toBe('1 su 26 · 65,00 su 1.000,00');
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $a->punti()->where('numero', 1)->value('id')],
        '2026-12-15 19:00'));
    expect(VotazioneDelPunto::query()->find($v['votazione_id'])->intervenuti['teste'][0]['chi'])
        ->toMatchArray(['anagrafica_id' => null, 'nome' => 'Bianchi Giorgio', 'ruolo' => 'delegato']);
});

it('catena 2: la presenza e il voto in sala → l\'esito del punto → la delibera, e ciò che il verbale e il seguito delle delibere leggono', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'marco', 'rita', ...SalaDiViaRoma12::altri(8)]);
    $p1 = $a->punti()->where('numero', 1)->first();
    $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $p1->id], '2026-12-15 19:00'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('voto', ['votazione_id' => $v['votazione_id'], 'testa' => 'p-' . $s->persone['c30']->id,
        'voto' => 'contrario'], '2026-12-15 19:02', 1));
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $v['votazione_id'], 'esito' => 'approvata'], '2026-12-15 19:03', 3));

    // L'esito del punto è la delibera, con il giorno della seduta: le schede e la frase la leggono come ogni delibera.
    $delibera = Delibera::query()->where('punto_id', $p1->id)->firstOrFail();
    expect($delibera->esito->value)->toBe('approvata')->and($delibera->deliberata_il->format('Y-m-d'))->toBe('2026-12-15')
        ->and(DeliberaInParole::frase($delibera))->toContain('15/12/2026')
        ->and(collect(PerLePagine::ordine($a))->firstWhere('numero', 1)['delibere'][0]['esito'])->toBe('approvata');

    // Ciò che il verbale legge: la seduta con le presenze e l'impronta, il voto con i nomi e i millesimi, l'esito, la delibera.
    $verbale = app(LetturaDellaSala::class)->perIlVerbale($a->refresh());
    $sedutaV = collect($verbale['sedute'])->firstWhere('id', $seduta->id);
    $puntoV = collect($verbale['punti'])->firstWhere('numero', 1);
    expect(array_keys($verbale))->toBe(['assemblea_id', 'stato', 'assemblea', 'convocazione', 'sedute', 'punti', 'deleghe'])
        ->and($sedutaV['impronta_fotografia'])->toBe($seduta->refresh()->impronta_fotografia)
        ->and(collect($sedutaV['presenze'])->pluck('nome')->all())->toContain('Rossi Mario', 'Colombo Rita')
        ->and($sedutaV['presidente'])->toBe(['anagrafica_id' => $s->persone['rossi']->id, 'nome' => 'Rossi Mario'])
        ->and($puntoV['votazioni'][0])->toMatchArray(['esito' => 'approvata', 'esito_proposto' => 'approvata', 'delibera_id' => $delibera->id])
        ->and($puntoV['votazioni'][0]['conteggi']['contrari']['nomi'])->toBe([['nome' => 'Condomino 30', 'millesimi' => 2775000]])
        ->and(collect($puntoV['votazioni'][0]['voti'])->firstWhere('nome', 'Colombo Rita'))->toMatchArray(['millesimi' => 9000000, 'voto' => 'favorevole'])
        ->and($puntoV['delibere'][0])->toMatchArray(['id' => $delibera->id, 'esito' => 'approvata', 'deliberata_il' => '2026-12-15', 'votazione' => 1]);
});

it('catena 3: l\'usufrutto e la comproprietà dall\'anagrafica e dalle unità → chi vota su ogni punto, con quale ruolo', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['carla', 'paolo', 'anna', 'marco', 'rita', 'luca', ...SalaDiViaRoma12::altri(8)]);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('rappresenta', ['seduta_id' => $seduta->id, 'persona' => ['anagrafica_id' => $s->persone['marco']->id],
        'unita' => [$s->unita['5']->id]], '2026-12-15 18:55'));
    $ruoli = function (int $numero) use ($s, $a, $seduta) {
        $v = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $a->punti()->where('numero', $numero)->value('id')],
            '2026-12-15 19:00'));
        SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $v['votazione_id']], '2026-12-15 19:01'));

        return VotazioneDelPunto::query()->findOrFail($v['votazione_id'])->voti()->get()->filter(fn ($x) => ! str_starts_with($x->nome, 'Condomino'))
            ->mapWithKeys(fn ($x) => [$x->nome => $x->ruolo->value])->sortKeys()->all();
    };

    expect($ruoli(1))->toBe(['Colombo Rita' => 'proprietario', 'Neri Carla' => 'usufruttuario', 'Russo Marco' => 'rappresentante'])
        ->and($ruoli(4))->toBe(['Colombo Rita' => 'proprietario', 'Neri Paolo' => 'nudo_proprietario', 'Russo Marco' => 'rappresentante'])
        ->and($ruoli(5))->toBe(['Ferri Luca' => 'conduttore', 'Neri Carla' => 'usufruttuario', 'Russo Marco' => 'rappresentante']);
});

it('catena 4: «due teste» di serie o «una testa» del condominio → il conto della costituzione nella sala', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    // Russo Marco ha anche l'Int. 30 da solo: il caso misto.
    DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['30']->id)->update(['anagrafica_id' => $s->persone['marco']->id]);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    $totale = fn () => app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin)['barra']['barra'];
    expect($totale())->toBe('0 su 26 · 0,00 su 1.000,00');

    SalaDiViaRoma12::puoModificareIlCondominio($s);
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.teste', [$s->c]), ['assemblee_teste' => 'una_testa'])->assertRedirect()
        ->assertSessionHasNoErrors();
    expect($totale())->toBe('0 su 25 · 0,00 su 1.000,00');

    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['marco']);
    expect($seduta->refresh()->fotografia['teste_caso_misto'])->toBe('una_testa')
        ->and($totale())->toBe('1 su 25 · 97,75 su 1.000,00');
});

it('catena 5: il punto della sola scala con la sua tabella → la riga della scala nella barra, che avvisa e non blocca', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $scala = Scala::query()->create(['condominio_id' => $s->c->id, 'name' => 'B']);
    $ascensore = Tabella::create(['condominio_id' => $s->c->id, 'nome' => 'Ascensore B', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    foreach (range(30, 39) as $i) {
        $s->unita[(string) $i]->forceFill(['scala_id' => $scala->id])->saveQuietly();
        DB::table('quote_tabella')->insert(['tabella_id' => $ascensore->id, 'immobile_id' => $s->unita[(string) $i]->id, 'valore' => 100, 'created_at' => now(),
            'updated_at' => now()]);
    }
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s, [
        ['testo' => 'Rendiconto 2025', 'materia' => 'rendiconto'],
        ['testo' => 'Funi dell\'ascensore della scala B, € 8.200,00', 'materia' => 'lavori', 'maggioranza' => 'ordinaria', 'ambito' => 'scala',
            'scala_id' => $scala->id, 'tabella_id' => $ascensore->id],
    ]), $s);
    $seduta = SalaDiViaRoma12::apriLaSeconda($this, $s, $a);
    SalaDiViaRoma12::entrano($this, $s, $a, ['rossi', 'marco', 'rita', 'bianchi', 'gallo', 'c30', 'c31', 'c32', 'c40', 'c41', 'c42']);

    // Il primo capo: la barra della sala, per il punto 2, ha la riga della scala.
    $punto = collect(app(LetturaDellaSala::class)->perLaPagina($a->refresh(), $s->admin)['punti'])->firstWhere('numero', 2);
    expect($punto['perimetro'])->toMatchArray(['nome' => 'Solo la scala B',
        'avviso' => 'Solo la scala B: 3 su 10 · 300,00 su 1.000,00; servono 4 e 333,34. Il conto della parte non basta: decide il presidente, e il verbale lo scrive.']);

    // L'altro capo: il voto si apre lo stesso, con l'avviso, e la conferma lo scrive nel registro.
    $r = SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('si_vota', ['seduta_id' => $seduta->id, 'punto_id' => $punto['id']], '2026-12-15 19:10'));
    expect($r['messaggio'])->toBe($punto['perimetro']['avviso']);
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('tutti_favorevoli', ['votazione_id' => $r['votazione_id']], '2026-12-15 19:11'));
    $riga = ViaRoma12::ultimaRiga();
    SalaDiViaRoma12::applica($this, $s, $a, SalaDiViaRoma12::gesto('conferma', ['votazione_id' => $r['votazione_id'], 'esito' => 'approvata',
        'motivo' => 'Il presidente fa votare lo stesso: i lavori sono urgenti'], '2026-12-15 19:12', 2));
    expect(ViaRoma12::righe($riga)->last()->perche)->toContain('Solo la scala B: 3 su 10');
});
