<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\Assemblea;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/../Motore/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (decisione 661, rilievo C40):
 *
 * - Dopo «Prepara l'assemblea» si apre «Documenti» se il passo 1 è fatto (le date valgono e c'è almeno un punto); altrimenti la pagina resta su «Date e
 *   ordine del giorno», e «Documenti» resta chiuso finché il passo 1 non è fatto.
 * - L'ordine dei punti si manda con «Prepara l'assemblea» (`ordine`: le chiavi dei proposti e `scritto:N` per ogni punto scritto): il server lo rispetta,
 *   ignora le chiavi che non conosce, ripete nessuno e tiene «Varie ed eventuali» per ultimo.
 *
 * Che cosa non copre: la pagina a video (`PreparaOrdine.test.ts`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->withoutVite();
});

function pod_prepara($t, Beta51Scenario $s, array $piu = [])
{
    return $t->actingAs($s->admin)->post(route('admin.gestionale.assemblee.store', $s->c), $piu + [
        'tipo' => 'ordinaria', 'ambito' => 'condominio', 'luogo' => 'Sala condominiale, Via Roma 12',
        'sedute' => [['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-14', 'ora' => '08:00'],
            ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2026-12-15', 'ora' => '18:30']],
    ]);
}

function pod_punto(string $testo): array
{
    return ['testo' => $testo, 'testo_pubblico' => null, 'materia' => 'altro', 'maggioranza' => 'ordinaria', 'conduttore' => null, 'ambito' => 'condominio', 'palazzina_id' => null,
        'scala_id' => null, 'tabella_id' => null, 'importo' => null];
}

it('con il passo 1 fatto, dopo «Prepara l\'assemblea» si apre «Documenti»', function () {
    $s = ViaRoma12::nuovo($this);
    $r = pod_prepara($this, $s, ['proposte' => ['varie']])->assertSessionHasNoErrors();
    $a = Assemblea::query()->where('condominio_id', $s->c->id)->firstOrFail();

    $r->assertRedirect(route('admin.gestionale.assemblee.show', ['condominio' => $s->c->id, 'assemblea' => $a->id, 'scheda' => 'documenti']))
        ->assertSessionHas('message', ['type' => 'success', 'message' => 'Assemblea preparata: ' . $a->nome() . '. Adesso i documenti.']);
});

it('con un punto scritto soltanto il passo 1 è fatto lo stesso e si apre «Documenti»', function () {
    $s = ViaRoma12::nuovo($this);
    $r = pod_prepara($this, $s, ['punti' => [pod_punto('Il portone')]])->assertSessionHasNoErrors();
    $a = Assemblea::query()->where('condominio_id', $s->c->id)->firstOrFail();

    $r->assertRedirect(route('admin.gestionale.assemblee.show', ['condominio' => $s->c->id, 'assemblea' => $a->id, 'scheda' => 'documenti']));
});

it('senza nessun punto il passo 1 non è fatto: la pagina resta su «Date e ordine del giorno», e «Documenti» è chiuso', function () {
    $s = ViaRoma12::nuovo($this);
    $r = pod_prepara($this, $s)->assertSessionHasNoErrors();
    $a = Assemblea::query()->where('condominio_id', $s->c->id)->firstOrFail();

    $r->assertRedirect(route('admin.gestionale.assemblee.show', ['condominio' => $s->c->id, 'assemblea' => $a->id, 'scheda' => 'date']))
        ->assertSessionHas('message', ['type' => 'success', 'message' => 'Assemblea preparata: ' . $a->nome() . '. Adesso è in preparazione; la convocazione parte quando la spedisci.']);
    $passi = collect(\App\Http\Controllers\Assemblee\PerLePagine::passi($a->refresh()))->keyBy('chiave');
    expect($passi['date']['stato'])->toBe('adesso')->and($passi['documenti']['stato'])->toBe('dopo')
        ->and($passi['documenti']['perche'])->toBe('si apre quando «Date e ordine del giorno» è fatto');
});

it('il messaggio dice anche la tabella di proprietà, aprendo «Documenti»', function () {
    $s = ViaRoma12::nuovo($this)->tabella('Proprietà', ['A' => 600, 'B' => 400]);
    pod_prepara($this, $s, ['proposte' => ['varie'], 'tabella_id' => $s->tabelle['Proprietà']->id, 'segna_di_proprieta' => true])->assertSessionHasNoErrors();
    $a = Assemblea::query()->where('condominio_id', $s->c->id)->firstOrFail();

    expect(session('message')['message'])->toBe('Assemblea preparata: ' . $a->nome() . '. Adesso i documenti. «Proprietà» è la tabella di proprietà generale di ' . $s->c->nome . '.');
});

function pod_con_ordine($t, array $ordine): array
{
    $s = ViaRoma12::nuovo($t);
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone che non si chiude'], $s->admin);
    pod_prepara($t, $s, ['proposte' => ['memo:' . $memo->id, 'varie'], 'punti' => [pod_punto('Scritto zero'), pod_punto('Scritto uno')],
        'ordine' => array_map(fn ($c) => str_replace('MEMO', 'memo:' . $memo->id, $c), $ordine)])->assertSessionHasNoErrors();
    $a = Assemblea::query()->where('condominio_id', $s->c->id)->firstOrFail();

    return [$a->punti()->orderBy('numero')->get()->pluck('testo')->all(), $a->punti()->orderBy('numero')->pluck('numero')->all()];
}

it('l\'ordine mandato diventa i numeri dei punti, con «Varie ed eventuali» sempre per ultimo', function () {
    [$testi, $numeri] = pod_con_ordine($this, ['scritto:1', 'MEMO', 'scritto:0', 'varie']);
    expect($testi)->toBe(['Scritto uno', 'Il portone che non si chiude', 'Scritto zero', 'Varie ed eventuali'])->and($numeri)->toBe([1, 2, 3, 4]);

    [$testi] = pod_con_ordine($this, ['varie', 'scritto:0', 'MEMO', 'scritto:1']);
    expect($testi)->toBe(['Scritto zero', 'Il portone che non si chiude', 'Scritto uno', 'Varie ed eventuali']);
});

it('senza «ordine» resta l\'ordine di prima: i proposti, i punti scritti, «Varie ed eventuali»', function () {
    $s = ViaRoma12::nuovo($this);
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone che non si chiude'], $s->admin);
    pod_prepara($this, $s, ['proposte' => ['memo:' . $memo->id, 'varie'], 'punti' => [pod_punto('Scritto zero')]])->assertSessionHasNoErrors();
    $a = Assemblea::query()->where('condominio_id', $s->c->id)->firstOrFail();

    expect($a->punti()->orderBy('numero')->pluck('testo')->all())->toBe(['Il portone che non si chiude', 'Scritto zero', 'Varie ed eventuali']);
});

it('l\'ordine non si fida: le chiavi che non conosce e i doppioni non contano, e ciò che manca va in fondo', function () {
    [$testi, $numeri] = pod_con_ordine($this, ['scritto:7', 'memo:999999', 'scritto:1', 'scritto:1', 'altro']);
    expect($testi)->toBe(['Scritto uno', 'Il portone che non si chiude', 'Scritto zero', 'Varie ed eventuali'])->and($numeri)->toBe([1, 2, 3, 4]);
});

it('l\'ordine con una voce che non è testo è un errore della richiesta', function () {
    $s = ViaRoma12::nuovo($this);
    pod_prepara($this, $s, ['proposte' => ['varie'], 'ordine' => [['x' => 1]]])->assertSessionHasErrors('ordine.0');
    expect(Assemblea::query()->where('condominio_id', $s->c->id)->count())->toBe(0);
});
