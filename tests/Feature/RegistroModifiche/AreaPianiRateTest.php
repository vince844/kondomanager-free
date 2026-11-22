<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\PianoRate;
use App\Models\RegistroModifica;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/../Gestionale/Beta51PianoRateScenario.php';

/**
 * 1.12.0-beta.13 (Coda 679, gesto 2 e §13 (3) del progetto): la generazione, la rigenerazione, l'emissione, l'annullamento dell'emissione e l'eliminazione
 * di un piano sono una riga sola ciascuna, con il rimando al piano; le rate, le quote e le scritture si contano nel lotto (le quote nascono con `insert`
 * a blocchi, e si contano dal database), mai una riga per rata o per riga di giornale.
 *
 * Che cosa non copre: il piano straordinario e la scelta delle fatture (stesso gesto della generazione); i piani degli addebiti (il loro gesto è negli
 * addebiti individuali).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
});

function aprSolo(int $prima, ?string $tipo = null)
{
    return RegistroModifica::query()->where('id', '>', $prima)->when($tipo !== null, fn ($q) => $q->where('oggetto_tipo', $tipo))->orderBy('id')->get();
}

it('il piano creato e generato dalla rotta: «creata» con i suoi dati, poi una riga sola per la generazione, con le rate e le quote', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 600, 'B' => 400])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->titolare('A', 'ugo')->titolare('B', 'anna');
    $prima = RegistroModifica::query()->max('id') ?? 0;
    ContestoDelRegistro::svuotaScrittureFuoriGesto();

    $this->actingAs($s->admin)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), [
        'tipo' => 'ordinario', 'gestione_id' => $s->gestioni['Ordinaria 2026']['g']->id, 'nome' => 'Ordinario 2027', 'metodo_distribuzione' => 'prima_rata',
        'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => true,
    ])->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('nome', 'Ordinario 2027')->firstOrFail();

    $gesti = aprSolo($prima, 'piano_rate')->where('azione', 'gesto')->values();
    expect(aprSolo($prima, 'piano_rate')->pluck('azione')->first())->toBe('creata')
        ->and($gesti)->toHaveCount(1)
        ->and($gesti[0]->riepilogo)->toBe('Piano «Ordinario 2027» generato: 12 rate, 24 quote')
        ->and($gesti[0]->rimando)->toMatchArray(['tipo' => 'piano_rate', 'id' => $piano->id])
        ->and($gesti[0]->lotto['conteggi'])->toHaveKey('rata.creata')
        ->and(aprSolo($prima)->whereIn('oggetto_tipo', ['rata', 'rata_quote', 'riga_riparto']))->toHaveCount(0)
        ->and(ContestoDelRegistro::scrittureFuoriGesto())->toBe([]);
});

it('la rigenerazione è una riga sola; l\'emissione e il suo annullamento una ciascuno, con le scritture nel lotto; l\'eliminazione dice che cosa sparisce', function () {
    $s = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['A' => 1000])->gestione('Ordinaria 2026')
        ->voce('Ordinaria 2026', 'Spese generali', 120000, ['Proprietà' => 100])->titolare('A', 'ugo')
        ->pianoOrdinario('Ordinario 2027', 'Ordinaria 2026')->genera('Ordinario 2027');
    $piano = $s->piano('Ordinario 2027');

    $prima = RegistroModifica::query()->max('id');
    $s->ricalcola('Ordinario 2027')->assertSessionHasNoErrors();
    expect(aprSolo($prima)->pluck('riepilogo')->all())->toBe(['Piano «Ordinario 2027» rigenerato: 12 rate, 12 quote']);

    $prima = RegistroModifica::query()->max('id');
    ContestoDelRegistro::svuotaScrittureFuoriGesto();
    $s->emetti('Ordinario 2027', '2026-02-28')->assertSessionHasNoErrors();
    $emissione = aprSolo($prima)->where('azione', 'gesto')->values();
    expect($emissione)->toHaveCount(1)
        ->and($emissione[0]->riepilogo)->toStartWith('Rate del piano «Ordinario 2027» emesse:')
        ->and(aprSolo($prima, 'scrittura_contabile'))->toHaveCount(0)
        ->and(ContestoDelRegistro::scrittureFuoriGesto())->toBe([]);

    $rata = \App\Models\Gestionale\Rata::query()->where('piano_rate_id', $piano->id)->where('stato', 'emessa')->orderBy('numero_rata')->firstOrFail();
    $prima = RegistroModifica::query()->max('id');
    $this->actingAs($s->admin)->from('/piano')->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s->c, $piano, $rata]))
        ->assertSessionHasNoErrors();
    expect(aprSolo($prima)->pluck('riepilogo')->all())->toBe(['Emissione della rata n. ' . $rata->numero_rata
        . ' del piano «Ordinario 2027» annullata: la rata torna in bozza']);

    \App\Models\Gestionale\Rata::query()->where('piano_rate_id', $piano->id)->where('stato', 'emessa')->get()
        ->each(fn ($r) => $this->actingAs($s->admin)->from('/piano')->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s->c, $piano, $r])));
    // Un piano approvato non si elimina: prima torna in bozza (la pagina lo dice).
    $piano->fresh()->update(['stato' => 'bozza']);
    $prima = RegistroModifica::query()->max('id');
    $this->actingAs($s->admin)->from('/piano')->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $piano]))
        ->assertSessionHasNoErrors();
    expect(PianoRate::query()->whereKey($piano->id)->exists())->toBeFalse()
        ->and(aprSolo($prima)->pluck('riepilogo')->all())->toBe(['Piano «Ordinario 2027» eliminato: 12 rate, 12 quote']);
});
