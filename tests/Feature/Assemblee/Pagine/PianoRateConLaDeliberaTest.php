<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/../Motore/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §1.4 e R1 del progetto della tappa 2; la bozza approvata con la decisione 502): i piani rate con «Scegli la delibera», dalle
 * rotte vere.
 *
 * - Il nuovo piano su una gestione straordinaria con `delibera_id`: «Deliberata il» è il giorno della riunione, il numero del verbale è il suo, e il piano è
 *   legato alla delibera; la motivazione diventa facoltativa.
 * - «Approva» del piano in bozza con la delibera: la data e il verbale entrano per la strada di oggi, con il collegamento.
 * - Il piano già emesso con un'altra data, dalla rotta della delibera del piano: la data **resta**, il messaggio dice la differenza.
 * - Il piano d'urgenza «Riferito all'assemblea»: una comunicazione senza voto si sceglie per il riferimento, non per la delibera.
 * - Una delibera di un altro condominio è un 404 su tutte e tre le rotte (R18).
 *
 * Che cosa non copre: le pagine (la scelta nel menu, la frase della differenza nel riquadro e nell'elenco: si guardano a video); i sette lettori della data
 * (`SetteLettoriInvariatiTest`); il permesso «Gestisce le assemblee» tolto a un collaboratore; il messaggio «ricalcola» di un piano in bozza con le quote già
 * generate (lo provano i test di oggi di `updateStato`).
 */

beforeEach(fn () => ContestoDelRegistro::azzera());

/** Il nuovo piano dalla rotta: un piano sulle voci della gestione straordinaria «Facciata 2026», senza generare. */
function prcdNuovoPiano($test, $s, array $piu)
{
    return $test->actingAs($s->admin)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), $piu + [
        'tipo' => 'ordinario', 'gestione_id' => $s->gestioni['Facciata 2026']['g']->id, 'nome' => 'Facciata 2026 — rate', 'metodo_distribuzione' => 'tutte_rate',
        'numero_rate' => 6, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-12-05', 'genera_subito' => 0,
        'capitoli_config' => [['id' => $s->voci['Facciata']['conto']->id, 'importo' => '14.500,00']],
    ]);
}

it('il nuovo piano con la delibera scelta: il giorno della riunione, il suo verbale, il collegamento', function () {
    $s = ViaRoma12::conFacciata($this);
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');

    prcdNuovoPiano($this, $s, ['delibera_id' => $d->id, 'data_delibera_assemblea' => '2026-01-01'])->assertSessionHasNoErrors();

    $piano = \App\Models\Gestionale\PianoRate::query()->where('condominio_id', $s->c->id)->where('nome', 'Facciata 2026 — rate')->firstOrFail();
    expect($piano->data_delibera_assemblea->format('Y-m-d'))->toBe('2026-10-22')
        ->and($piano->numero_verbale)->toBe('41')
        ->and(CollegamentiDelleDelibere::delibera($piano, 'delibera')?->id)->toBe($d->id);
});

it('senza la delibera la data scritta a mano vale come prima, e niente si collega', function () {
    $s = ViaRoma12::conFacciata($this);

    prcdNuovoPiano($this, $s, ['data_delibera_assemblea' => '2026-10-20'])->assertSessionHasNoErrors();

    $piano = \App\Models\Gestionale\PianoRate::query()->where('condominio_id', $s->c->id)->where('nome', 'Facciata 2026 — rate')->firstOrFail();
    expect($piano->data_delibera_assemblea->format('Y-m-d'))->toBe('2026-10-20')
        ->and(CollegamentoAllaDelibera::query()->count())->toBe(0);
});

it('«Approva» del piano in bozza con la delibera: la data e il verbale della riunione, e il collegamento', function () {
    $s = ViaRoma12::conFacciata($this)->pianoOrdinario('Facciata 2026 — rate', 'Facciata 2026', bozza: true, capitoli: true);
    $piano = $s->piano('Facciata 2026 — rate');
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');

    $this->actingAs($s->admin)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $piano]),
        ['approvato' => true, 'delibera_id' => $d->id, 'nota_approvazione' => 'Approvato con la relazione dell\'impresa'])->assertSessionHasNoErrors();

    $piano->refresh();
    expect($piano->stato->value)->toBe('approvato')
        ->and($piano->data_delibera_assemblea->format('Y-m-d'))->toBe('2026-10-22')
        ->and($piano->numero_verbale)->toBe('41')
        ->and(CollegamentiDelleDelibere::delibera($piano, 'delibera')?->id)->toBe($d->id);
});

it('il piano emesso con un\'altra data: la delibera si collega, la data resta, il messaggio dice la differenza', function () {
    $s = ViaRoma12::conFacciata($this)->pianoStraordinario('Facciata 2026 — rate', 'Facciata 2026', ['Facciata' => 1450000], '2026-10-20')
        ->genera('Facciata 2026 — rate');
    $s->emetti('Facciata 2026 — rate', '2026-12-31');
    $piano = $s->piano('Facciata 2026 — rate')->fresh();
    expect($piano->eImmutabile())->toBeTrue();
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');

    $r = $this->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.piani-rate.delibera', [$s->c, $s->e, $piano]),
        ['delibera_id' => $d->id, 'ruolo' => 'delibera']);

    $r->assertSessionHasNoErrors();
    expect($piano->fresh()->data_delibera_assemblea->format('Y-m-d'))->toBe('2026-10-20')
        ->and(CollegamentiDelleDelibere::delibera($piano, 'delibera')?->id)->toBe($d->id)
        ->and((string) session('message.message'))->toContain('La delibera è del 22/10/2026, il piano dice 20/10/2026');
});

it('il piano d\'urgenza si riferisce all\'assemblea anche con una comunicazione senza voto; come delibera no', function () {
    $s = ViaRoma12::conFacciata($this)->pianoStraordinario('Lavori urgenti — rate', 'Facciata 2026', ['Facciata' => 180000], '2026-11-12');
    $piano = $s->piano('Lavori urgenti — rate');
    $piano->forceFill(['tipo_autorizzazione' => 'urgenza', 'data_delibera_assemblea' => null])->saveQuietly();
    $comunicazione = ViaRoma12::delibera($s, '2026-11-18', 'Comunicazione dei lavori urgenti alla colonna dell\'acqua', 'senza_voto', '42');

    $this->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.piani-rate.delibera', [$s->c, $s->e, $piano]),
        ['delibera_id' => $comunicazione->id, 'ruolo' => 'riferimento'])->assertSessionHasNoErrors();
    expect(CollegamentiDelleDelibere::delibera($piano, 'riferimento')?->id)->toBe($comunicazione->id)
        ->and($piano->fresh()->data_delibera_assemblea)->toBeNull();

    $this->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.piani-rate.delibera', [$s->c, $s->e, $piano]),
        ['delibera_id' => $comunicazione->id, 'ruolo' => 'delibera'])->assertSessionHasErrors('delibera_id');
    expect(CollegamentiDelleDelibere::delibera($piano, 'delibera'))->toBeNull();
});

it('una delibera di un altro condominio è un 404 sulla creazione, sull\'approvazione e sulla delibera del piano (R18)', function () {
    $s = ViaRoma12::conFacciata($this)->pianoOrdinario('Preventivo facciata', 'Facciata 2026', bozza: true, capitoli: true);
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $fuori = ViaRoma12::delibera($altro, '2026-10-22', 'Una delibera di Corso Italia', 'approvata', '7');
    $piano = $s->piano('Preventivo facciata');

    prcdNuovoPiano($this, $s, ['delibera_id' => $fuori->id])->assertNotFound();
    $this->actingAs($s->admin)->put(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $piano]), ['approvato' => true, 'delibera_id' => $fuori->id])
        ->assertNotFound();
    $this->actingAs($s->admin)->post(route('admin.gestionale.piani-rate.delibera', [$s->c, $s->e, $piano]), ['delibera_id' => $fuori->id, 'ruolo' => 'delibera'])
        ->assertNotFound();
    expect(CollegamentoAllaDelibera::query()->count())->toBe(0)
        ->and($piano->fresh()->stato->value)->toBe('bozza');
});

it('il numero del verbale scritto a mano resta, se la riunione della delibera non ne ha uno: dalla delibera del piano e dall\'«Approva»', function () {
    $s = ViaRoma12::conFacciata($this)->pianoOrdinario('Preventivo 2026', 'Facciata 2026', bozza: true, capitoli: true);
    $piano = $s->piano('Preventivo 2026');
    $piano->forceFill(['numero_verbale' => '41'])->saveQuietly();
    $senzaNumero = ViaRoma12::delibera($s, '2026-11-18', 'Approvazione del preventivo 2026', 'approvata');

    $this->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.piani-rate.delibera', [$s->c, $s->e, $piano]),
        ['delibera_id' => $senzaNumero->id, 'ruolo' => 'delibera'])->assertSessionHasNoErrors();
    expect($piano->fresh()->numero_verbale)->toBe('41')->and($piano->fresh()->data_delibera_assemblea->format('Y-m-d'))->toBe('2026-11-18');

    $this->actingAs($s->admin)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $piano]),
        ['approvato' => true, 'delibera_id' => $senzaNumero->id])->assertSessionHasNoErrors();
    expect($piano->fresh()->numero_verbale)->toBe('41');

    // Con il numero della riunione, vale quello.
    $conNumero = ViaRoma12::delibera($s, '2026-11-19', 'Approvazione del preventivo 2026', 'approvata', '42');
    $this->actingAs($s->admin)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s->c, $s->e, $piano]),
        ['approvato' => true, 'delibera_id' => $conNumero->id])->assertSessionHasNoErrors();
    expect($piano->fresh()->numero_verbale)->toBe('42');
});

it('senza «Gestisce le assemblee» il permesso viene prima del modulo: il piano con una delibera è 403, anche con un campo sbagliato o di un altro condominio', function () {
    $s = ViaRoma12::conFacciata($this);
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');
    $dAltrove = ViaRoma12::delibera($altro, '2026-10-22', 'Il portone', 'approvata', '7');
    $s->admin->revokePermissionTo(\App\Enums\Permission::MANAGE_ASSEMBLEE->value);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    prcdNuovoPiano($this, $s, ['delibera_id' => $d->id, 'numero_rate' => 'tante'])->assertForbidden();
    prcdNuovoPiano($this, $s, ['delibera_id' => $dAltrove->id])->assertForbidden();
    expect(\App\Models\Gestionale\PianoRate::query()->where('nome', 'Facciata 2026 — rate')->exists())->toBeFalse();
});
