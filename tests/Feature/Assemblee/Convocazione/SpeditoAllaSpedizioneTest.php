<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Evento;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Convocazione\ElencoDeiConvocati;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Assemblee\OrdineDelGiorno;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13: una regola sola di «spedito», per il compito «Spedire la convocazione entro il …», per il passo «Convocazione» e per lo stato
 * dell'assemblea.
 *
 * - La stampa delle lettere non spedisce e non blocca niente: l'assemblea resta in preparazione, date e punti si cambiano, il compito resta aperto, e il
 *   registro dice «lettere stampate», non «convocazione spedita».
 * - L'assemblea diventa «convocata», con date e punti bloccati, alla prima spedizione registrata: una raccomandata registrata spedita, una PEC partita, una
 *   consegna a mano registrata.
 * - Il compito si chiude quando tutti i convocati hanno il loro avviso spedito.
 * - Dopo una stampa, con date o punti cambiati, la pagina dice «Hai stampato le lettere il …: ristampale»; «Stampa le lettere» le ristampa, e la spedizione
 *   di una lettera di prima non si registra.
 * - «Stampa le lettere (N)» è un pulsante solo, con tutte le lettere su carta (raccomandate e a mano); il foglio delle firme resta a parte.
 *
 * Che cosa non copre: la pagina a video (i vitest della scheda e della strada); le catene intere (`CateneDeiPassiTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Il compito «Spedire la convocazione» aperto dell'assemblea, o null. */
function spedCompito(int $assemblea): ?Evento
{
    return Evento::query()->where('meta->type', 'spedire_convocazione')->where('meta->context->assemblea_id', $assemblea)->where('is_completed', false)->first();
}

/** Gli id degli ultimi invii delle persone date. @param list<string> $chi @return list<int> */
function spedInvii($a, Beta51Scenario $s, array $chi): array
{
    return array_map(fn ($x) => (int) ConvocazioneDiViaRoma12::invio($a, $s, $x)->id, $chi);
}

it('la stampa non spedisce: l\'assemblea resta in preparazione, le date si cambiano, il compito resta aperto, il registro dice «lettere stampate»', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $prima = ViaRoma12::ultimaRiga();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk()
        ->assertHeader('X-Avvisi', '5');

    expect($a->refresh()->stato)->toBe(StatoAssemblea::IN_PREPARAZIONE)
        ->and(spedCompito($a->id)?->title)->toBe('Spedire la convocazione entro il 03/12')
        ->and(spedCompito($a->id)?->description)->toBe('Assemblea ordinaria del 15/12/2026: 9 avvisi da spedire.')
        ->and(ConvocazioneDiViaRoma12::righeDelRegistro($prima)->pluck('riepilogo')->all())->toContain('Lettere della convocazione stampate: 5')
        ->not->toContain('Assemblea ordinaria del 15/12/2026 convocata');

    $this->patch(route('admin.gestionale.assemblee.sedute', [$s->c, $a]), ['sedute' => [
        ['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-15', 'ora' => '08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2026-12-16', 'ora' => '18:30'],
    ]])->assertSessionHasNoErrors();
    expect($a->refresh()->sedute()->orderBy('fissata_il')->first()->fissata_il->format('d/m'))->toBe('15/12');
});

it('la prima spedizione registrata fa «convocata»: una raccomandata registrata spedita; da allora le date non si cambiano', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    expect($a->refresh()->stato)->toBe(StatoAssemblea::IN_PREPARAZIONE);
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => spedInvii($a, $s, ['carla']), 'canale' => 'raccomandata',
        'data' => '2026-11-26'])->assertSessionHasNoErrors();

    expect($a->refresh()->stato)->toBe(StatoAssemblea::CONVOCATA);
    $this->patch(route('admin.gestionale.assemblee.sedute', [$s->c, $a]), ['sedute' => [
        ['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-15', 'ora' => '08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2026-12-16', 'ora' => '18:30'],
    ]])->assertSessionHasErrors(['assemblea' => OrdineDelGiorno::FRASE_NON_SI_CAMBIA]);
});

it('la prima spedizione registrata fa «convocata»: una consegna a mano registrata con il foglio firmato', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    expect($a->refresh()->stato)->toBe(StatoAssemblea::IN_PREPARAZIONE);
    $this->post(route('admin.gestionale.assemblee.convocazione.foglio-firmato', [$s->c, $a]), [
        'foglio' => UploadedFile::fake()->create('foglio-firmato.pdf', 50, 'application/pdf'),
        'consegne' => [['invio_id' => spedInvii($a, $s, ['anna'])[0], 'giorno' => '2026-11-26']],
    ])->assertSessionHasNoErrors();

    expect($a->refresh()->stato)->toBe(StatoAssemblea::CONVOCATA);
});

it('la prima spedizione registrata fa «convocata»: una consegna a mano registrata con «Registra la spedizione»', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    expect($a->refresh()->stato)->toBe(StatoAssemblea::IN_PREPARAZIONE);
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => spedInvii($a, $s, ['luca']), 'canale' => 'a_mano',
        'data' => '2026-11-26'])->assertSessionHasNoErrors();

    expect($a->refresh()->stato)->toBe(StatoAssemblea::CONVOCATA);
});

it('la prima spedizione registrata fa «convocata»: una consegna a mano registrata con «Registra la consegna» sulla riga', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    expect($a->refresh()->stato)->toBe(StatoAssemblea::IN_PREPARAZIONE);
    $this->post(route('admin.gestionale.assemblee.invii.consegna', [$s->c, $a, ConvocazioneDiViaRoma12::invio($a, $s, 'anna')]),
        ['esito' => 'consegnata', 'giorno' => '2026-11-26'])->assertSessionHasNoErrors();

    expect($a->refresh()->stato)->toBe(StatoAssemblea::CONVOCATA);
});

it('la prima spedizione registrata fa «convocata»: una PEC partita', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();

    expect($a->refresh()->stato)->toBe(StatoAssemblea::CONVOCATA);
});

it('il compito «Spedire la convocazione» si chiude quando tutti hanno il loro avviso spedito, non quando le lettere sono stampate', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $s->persone['bianchi']->forceFill(['indirizzo' => 'Via Roma 12, 20121 Milano'])->saveQuietly();
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    expect(spedCompito($a->id)?->description)->toBe('Assemblea ordinaria del 15/12/2026: 6 avvisi da spedire.');

    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => spedInvii($a, $s, ['marco', 'carla', 'gallo', 'bianchi']),
        'canale' => 'raccomandata', 'data' => '2026-11-26'])->assertSessionHasNoErrors();
    expect(spedCompito($a->id)?->description)->toBe('Assemblea ordinaria del 15/12/2026: 2 avvisi da spedire.');

    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => spedInvii($a, $s, ['anna', 'luca']), 'canale' => 'a_mano',
        'data' => '2026-11-26'])->assertSessionHasNoErrors();
    expect(spedCompito($a->id))->toBeNull()
        ->and(Evento::query()->where('meta->type', 'spedire_convocazione')->where('meta->context->assemblea_id', $a->id)->value('is_completed'))->toBeTruthy();
});

it('dopo una stampa, con le date cambiate: «Hai stampato le lettere il 26/11: ristampale»; «Stampa le lettere» le ristampa; la lettera di prima non si spedisce', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    $vecchia = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    $scheda = fn () => app(StatoDellaConvocazione::class)->perLaPagina($a->refresh());
    expect($scheda()['lettere'])->toMatchArray(['quante' => 0, 'attivo' => false, 'ristampa' => null]);

    ConvocazioneDiViaRoma12::oggi('2026-11-27 10:00');
    $this->patch(route('admin.gestionale.assemblee.sedute', [$s->c, $a]), ['sedute' => [
        ['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-15', 'ora' => '08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2026-12-16', 'ora' => '18:30'],
    ]])->assertSessionHasNoErrors();

    $dopo = $scheda();
    $carla = collect($dopo['convocati']['righe'])->firstWhere('nome', 'Neri Carla');
    expect($dopo['lettere'])->toMatchArray(['quante' => 5, 'attivo' => true, 'ristampa' => 'Hai stampato le lettere il 26/11: ristampale, perché l\'avviso è cambiato.'])
        ->and($carla)->toMatchArray(['stato' => 'da_ristampare', 'stato_parola' => 'Da ristampare'])
        ->and($carla['azioni']['spedizione'])->toBeFalse();

    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$vecchia->id], 'canale' => 'raccomandata',
        'data' => '2026-11-27'])->assertSessionHasErrors(['invii']);
    expect($vecchia->refresh()->stato)->toBe(RegistroInvio::STATO_STAMPATA)->and($a->refresh()->stato)->toBe(StatoAssemblea::IN_PREPARAZIONE);

    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk()->assertHeader('X-Avvisi', '5');
    $nuova = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    expect($nuova->id)->not->toBe($vecchia->id)
        ->and(collect($nuova->dati['sedute'])->pluck('quando')->first())->toContain('15/12/2026')
        ->and($scheda()['lettere'])->toMatchArray(['quante' => 0, 'ristampa' => null]);
});

it('«Stampa le lettere (N)» è un pulsante solo: raccomandate e a mano insieme; il foglio delle firme resta a parte', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin);
    $scheda = app(StatoDellaConvocazione::class)->perLaPagina($a);
    $daStampare = collect(app(ElencoDeiConvocati::class)->per($a))->filter(fn ($v) => $v['oggi'] && $v['mezzo']['valido']
        && in_array($v['mezzo']['mezzo'], ['raccomandata', 'a_mano', 'fax'], true))->pluck('mezzo.mezzo')->countBy()->all();

    expect($scheda['lettere']['quante'])->toBe(5)->and($daStampare)->toBe(['a_mano' => 2, 'raccomandata' => 3]);
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk()->assertHeader('X-Avvisi', '5');
    $this->post(route('admin.gestionale.assemblee.convocazione.foglio', [$s->c, $a]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(RegistroInvio::query()->where('documentabile_id', $a->id)->where('tipo', 'convocazione')->count())->toBe(5);
});

it('una lettera a mano stampata non fa nascere «Ricevuta mancante»: non è spedita, e la ricorda il compito della spedizione', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();

    expect(Evento::query()->where('meta->type', 'ricevute_mancanti')->where('meta->context->assemblea_id', $a->id)->count())->toBe(0)
        ->and(spedCompito($a->id))->not->toBeNull();
});
