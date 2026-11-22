<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Evento;
use App\Models\Fornitore;
use App\Models\Saldo;
use App\Services\Assemblee\PropostaDelPunto;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../Motore/ViaRoma12.php';

/**
 * B4 (simulazione del 21/11/2026): il punto proposto del deficit ereditato da ripianare dice anche verso chi, nel testo dello studio («Ripianamento del
 * deficit di € 1.200,00 (debito ereditato verso Idraulica Urgenze Demo s.n.c.)»): il fornitore è quello del saldo da cui nasce il compito, o, se il saldo non
 * c'è, quello del titolo del compito. La frase per la bacheca non lo nomina.
 *
 * Che cosa non copre: la scheda a video.
 */
function dvcCompito(\App\Models\Condominio $c, array $meta, string $titolo): Evento
{
    $compito = Evento::create(['title' => $titolo, 'start_time' => now(), 'end_time' => now()->addHour(), 'visibility' => 'hidden', 'is_approved' => true,
        'meta' => ['type' => 'pianifica_ripianamento_deficit', 'requires_action' => true, 'importo_buco' => 120000] + $meta]);
    $compito->condomini()->attach($c->id);

    return $compito;
}

it('il fornitore si legge dal saldo del compito: nel testo dello studio sì, nella frase pubblica no', function () {
    $s = ViaRoma12::nuovo($this);
    $fornitore = Fornitore::create(['ragione_sociale' => 'Idraulica Urgenze Demo s.n.c.', 'partita_iva' => null, 'codice_fiscale' => null]);
    $saldo = Saldo::create(['esercizio_id' => $s->e->id, 'condominio_id' => $s->c->id, 'fornitore_id' => $fornitore->id, 'saldo_iniziale' => 120000, 'origine' => 'manuale',
        'is_applicato' => false]);
    // Il titolo dice un altro nome: vale il saldo.
    $compito = dvcCompito($s->c, ['context' => ['saldo_id' => $saldo->id]], 'Deficit Cassa Ereditato: Altro nome');

    $p = PropostaDelPunto::perLaScheda($compito);
    expect($p['testo'])->toBe('Ripianamento del deficit di € 1.200,00 (debito ereditato verso Idraulica Urgenze Demo s.n.c.)')
        ->and($p['testo_pubblico'])->toBe('Ripianamento del deficit di € 1.200,00');
});

it('senza il saldo il fornitore si legge dal titolo del compito; senza nemmeno quello il testo resta com\'era', function () {
    $s = ViaRoma12::nuovo($this);

    $dalTitolo = dvcCompito($s->c, ['context' => ['saldo_id' => 999999]], 'Deficit Cassa Ereditato: Idraulica Urgenze Demo s.n.c.');
    expect(PropostaDelPunto::perLaScheda($dalTitolo)['testo'])->toBe('Ripianamento del deficit di € 1.200,00 (debito ereditato verso Idraulica Urgenze Demo s.n.c.)');

    $senza = dvcCompito($s->c, [], 'Deficit');
    expect(PropostaDelPunto::perLaScheda($senza)['testo'])->toBe('Ripianamento del deficit di € 1.200,00');
});

it('nella lista dei punti proposti dall\'ordine del giorno il testo porta il fornitore', function () {
    $s = ViaRoma12::nuovo($this);
    $compito = dvcCompito($s->c, [], 'Deficit Cassa Ereditato: Idraulica Urgenze Demo s.n.c.');

    $punti = collect(\App\Services\Assemblee\MemoriaDelCondominio::perLaDashboard($s->c)['punti'])->keyBy('chiave_fonte');
    expect($punti['compito:' . $compito->id]['testo'])->toContain('(debito ereditato verso Idraulica Urgenze Demo s.n.c.)');
});
