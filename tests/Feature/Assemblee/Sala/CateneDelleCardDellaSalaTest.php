<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\DelegaDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/SalaDiViaRoma12.php';

/**
 * Le catene delle card del passo «In sala» prima del giorno, con il cassetto delle deleghe. Il cassetto e la sezione del passo mandano le stesse richieste
 * (la rotta della delega, la rotta della revoca) e rileggono le stesse prop (`sala`, `passi`): qui la parte del server.
 *
 * 1. Il foglio stampato → una delega ricevuta dal cassetto → la card «Deleghe ricevute» la conta → la card del foglio è da rifare, in arancio, con
 *    «Ristampa il foglio» → la ristampa → la card torna verde, con «Stampa di nuovo».
 * 2. La revoca dal cassetto → la prop `sala` della pagina ha la delega revocata, con il motivo (la stessa che legge la sezione del passo) → la card non la
 *    conta più fra le valide.
 *
 * Che cosa non copre: il cassetto a video (`CassettoDelleDeleghe.test.ts`, `AssembleaConLaSala.test.ts`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function cdcSala(\App\Models\Assemblee\Assemblea $a): array
{
    return collect(collect(\App\Http\Controllers\Assemblee\PerLePagine::passi($a->refresh()))->firstWhere('chiave', 'sala')['manca'])->keyBy('chiave')->all();
}

it('catena 1: il foglio stampato → la delega dal cassetto → la card la conta → il foglio da ristampare in arancio → la ristampa → la card torna verde', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-12 09:00');
    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.sala.foglio', [$s->c, $a]))->assertOk();
    expect(cdcSala($a)['deleghe'])->toMatchArray(['dettaglio' => 'nessuna, per ora', 'collegamento' => ['testo' => 'Segna una delega ricevuta', 'url' => null,
        'passo' => null, 'apre' => 'segna_delega']])
        ->and(cdcSala($a)['foglio'])->toMatchArray(['fatto' => true, 'informa' => false]);

    // Il corpo come lo manda «Segna una delega ricevuta» del cassetto.
    ConvocazioneDiViaRoma12::oggi('2026-12-12 10:30');
    $this->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone['c30']->id,
        'delegato' => ['anagrafica_id' => $s->persone['rossi']->id], 'ricevuta_il' => '2026-12-12'])->assertSessionHasNoErrors();
    $card = cdcSala($a);
    expect($card['deleghe'])->toMatchArray(['dettaglio' => '1 valida', 'collegamento' => ['testo' => 'Vedi le deleghe', 'url' => null, 'passo' => null,
        'apre' => 'deleghe']])
        ->and($card['foglio'])->toMatchArray(['fatto' => false, 'informa' => false, 'rifare' => true,
            'dettaglio' => 'una delega è cambiata dopo la stampa del 12/12 alle 09:00: ristampalo'])
        ->and($card['foglio']['collegamento']['testo'])->toBe('Ristampa il foglio');

    // «Ristampa il foglio» apre la stessa stampa: la card torna verde.
    ConvocazioneDiViaRoma12::oggi('2026-12-12 10:45');
    $this->get($card['foglio']['collegamento']['url'])->assertOk();
    expect(cdcSala($a)['foglio'])->toMatchArray(['fatto' => true, 'informa' => false, 'dettaglio' => 'stampato il 12/12 alle 10:45, con le deleghe ricevute fino ad allora'])
        ->and(cdcSala($a)['foglio']['collegamento']['testo'])->toBe('Stampa di nuovo');
});

it('catena 2: la revoca dal cassetto → la prop della sala ha la delega revocata con il motivo, come la legge la sezione del passo → la card non la conta', function () {
    $s = SalaDiViaRoma12::nuovo($this);
    $a = SalaDiViaRoma12::convoca(SalaDiViaRoma12::ordinaria($s), $s);
    ConvocazioneDiViaRoma12::oggi('2026-12-10 11:00');
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.deleghe.store', [$s->c, $a]), ['delegante_anagrafica_id' => $s->persone['c30']->id,
        'delegato' => ['anagrafica_id' => $s->persone['rossi']->id], 'ricevuta_il' => '2026-12-10'])->assertSessionHasNoErrors();
    $delega = DelegaDellAssemblea::query()->firstOrFail();

    $this->post(route('admin.gestionale.assemblee.deleghe.revoca', [$s->c, $a, $delega]), ['motivo' => 'Viene di persona.'])->assertSessionHasNoErrors();
    $this->get(route('admin.gestionale.assemblee.show', [$s->c, $a, 'scheda' => 'sala']))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->reloadOnly('sala', fn (AssertableInertia $r) => $r->where('sala.deleghe.0.stato', 'revocata')
            ->where('sala.deleghe.0.motivo', fn ($m) => str_contains((string) $m, 'Viene di persona.'))));
    expect(cdcSala($a)['deleghe'])->toMatchArray(['dettaglio' => 'Nessuna valida · 1 revocata', 'collegamento' => ['testo' => 'Vedi le deleghe', 'url' => null, 'passo' => null,
        'apre' => 'deleghe']]);
});
