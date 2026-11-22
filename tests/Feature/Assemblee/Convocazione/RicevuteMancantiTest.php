<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Evento;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (§4.6 del progetto della tappa 2; §13 rilievo 13): «Spedire la convocazione entro il 03/12» nasce con le date, con l'assemblea in
 * `meta.context`, e si chiude quando tutti hanno il loro avviso spedito (la lettera stampata non basta); «Ricevute mancanti» nasce il giorno dopo l'arrivo atteso (la spedizione più i giorni di
 * posta), dice l'ultimo giorno di ricezione e si chiude con l'esito; l'assemblea annullata chiude i suoi compiti.
 *
 * Che cosa non copre: la pagina dell'Action inbox; il compito spostato da una data cambiata (lo chiama la preparazione, coperto nei termini).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-12-02 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function convCompitiDellAssemblea(int $assemblea, string $tipo)
{
    return Evento::query()->where('meta->type', $tipo)->where('meta->context->assemblea_id', $assemblea)->orderBy('id')->get();
}

it('«Spedire la convocazione» nasce con le date, alle 9 del giorno delle raccomandate, e si chiude quando tutti hanno l\'avviso', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $s->persone['bianchi']->forceFill(['mezzo_convocazione' => 'a_mano'])->saveQuietly();
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $c = convCompitiDellAssemblea($a->id, 'spedire_convocazione');
    expect($c)->toHaveCount(1)->and($c[0]->title)->toBe('Spedire la convocazione entro il 03/12')
        ->and($c[0]->start_time->format('Y-m-d H:i'))->toBe('2026-12-03 09:00')
        ->and($c[0]->eventable_id)->toBeNull();

    // La lettera stampata non è spedita: dopo la stampa e dopo le PEC il compito resta, con il giorno delle raccomandate; si chiude con l'ultima spedizione.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    expect($c[0]->refresh()->is_completed)->toBeFalse()->and($c[0]->title)->toBe('Spedire la convocazione entro il 03/12');
    $this->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]));
    expect($c[0]->refresh()->is_completed)->toBeFalse();
    $invii = fn (array $chi) => array_map(fn ($x) => ConvocazioneDiViaRoma12::invio($a, $s, $x)->id, $chi);
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => $invii(['marco', 'carla', 'gallo']), 'canale' => 'raccomandata',
        'data' => '2026-12-02'])->assertSessionHasNoErrors();
    expect($c[0]->refresh()->is_completed)->toBeFalse()->and($c[0]->title)->toBe('Spedire la convocazione entro il 09/12');
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => $invii(['anna', 'luca', 'bianchi']), 'canale' => 'a_mano',
        'data' => '2026-12-02'])->assertSessionHasNoErrors();
    expect($c[0]->refresh()->is_completed)->toBeTrue();
});

it('«Ricevute mancanti» nasce il giorno dopo l\'arrivo atteso, dice il 09/12, e si chiude con l\'esito', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf', 'anagrafiche' => [$s->persone['carla']->id]]);
    $carla = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    expect(convCompitiDellAssemblea($a->id, 'ricevute_mancanti'))->toHaveCount(0);
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$carla->id], 'canale' => 'raccomandata', 'data' => '2026-12-02']);

    // Spedita mercoledì 02/12: arrivo atteso lunedì 07/12 (giovedì, venerdì, lunedì), il compito il giorno dopo.
    $r = convCompitiDellAssemblea($a->id, 'ricevute_mancanti');
    expect($r)->toHaveCount(1)->and($r[0]->title)->toBe('Ricevuta mancante: Neri Carla, entro il 09/12 si può ancora consegnare a mano')
        ->and($r[0]->start_time->format('Y-m-d H:i'))->toBe('2026-12-08 09:00')
        ->and($r[0]->meta['scadenza'])->toBe('2026-12-09');

    ConvocazioneDiViaRoma12::oggi('2026-12-08 11:00');
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, $carla]), ['esito' => 'consegnata', 'giorno' => '2026-12-08']);
    expect($r[0]->refresh()->is_completed)->toBeTrue();
});

it('l\'assemblea annullata e riconvocata chiude i compiti della vecchia', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $this->post(route('admin.gestionale.assemblee.riconvoca', [$s->c, $a]), ['motivo' => 'Le raccomandate non arrivano in tempo',
        'sedute' => [['convocazione' => 'prima', 'fissata_il' => '2026-12-21 08:00'], ['convocazione' => 'seconda', 'fissata_il' => '2026-12-22 18:30']]])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(convCompitiDellAssemblea($a->id, 'spedire_convocazione')->every(fn ($c) => $c->is_completed))->toBeTrue();
});
