<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Services\Assemblee\Convocazione\ElencoDeiConvocati;
use App\Services\Assemblee\Convocazione\EmissioneDelleConvocazioni;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679): l'avviso dice tutto ciò che la legge chiede.
 *
 * - Sotto il punto della spesa urgente l'avviso stampa il motivo, con «Perché era urgente:»; sotto quello della spesa oltre il preventivo «Perché supera il
 *   preventivo:» (il motivo è in `assemblee_punti.dati`, con `motivazione_di`).
 * - Senza il luogo di una seduta in presenza, o senza la piattaforma in videoconferenza, la spedizione non parte e dice che cosa manca (art. 66 c. 3:
 *   «luogo e ora»; per la videoconferenza «la piattaforma e l'ora»).
 * - In videoconferenza senza il giorno del consenso della maggioranza (art. 66 c. 6) la scheda lo dice, senza bloccare.
 *
 * Che cosa non copre: l'aspetto del PDF (le immagini del rapporto).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('sotto il punto della spesa urgente l\'avviso stampa «Perché era urgente:», sotto quello oltre il preventivo «Perché supera il preventivo:»', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    PuntoDellOrdineDelGiorno::query()->where('assemblea_id', $a->id)->where('numero', 3)->first()->forceFill(['dati' => [
        'motivazione' => 'Perdita dalla colonna dell\'acqua: riparata il 12/11 per non allagare gli appartamenti sotto', 'motivazione_di' => 'urgenza']])->save();
    PuntoDellOrdineDelGiorno::query()->where('assemblea_id', $a->id)->where('numero', 4)->first()->forceFill(['dati' => [
        'motivazione' => 'Il portone non si chiude più', 'motivazione_di' => 'sforo']])->save();
    $voce = collect(app(ElencoDeiConvocati::class)->per($a->refresh()))->firstWhere('nome', 'Rossi Mario');

    $punti = collect(app(EmissioneDelleConvocazioni::class)->avviso($a, $voce, 'pec')['punti'])->keyBy('numero');

    expect($punti[3]['note'])->toContain('Perché era urgente: Perdita dalla colonna dell\'acqua: riparata il 12/11 per non allagare gli appartamenti sotto.')
        ->and($punti[4]['note'])->toContain('Perché supera il preventivo: Il portone non si chiude più.')
        ->and($punti[1]['note'])->toBe([]);
});

it('senza il luogo la stampa delle lettere non parte e dice che cosa manca; in videoconferenza senza la piattaforma lo stesso', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $a->forceFill(['luogo' => null])->save();

    $r = $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf']);
    $r->assertStatus(422);
    expect($r->json('errors.convocazione.0'))->toBe('La spedizione non parte: l\'avviso deve dire dove si tiene l\'assemblea (art. 66 c. 3). '
        . 'Manca il luogo della prima convocazione e della seconda convocazione: scrivilo nel passo «Date e ordine del giorno».');

    $b = ConvocazioneDiViaRoma12::ordinaria($s);
    $b->forceFill(['modalita' => 'videoconferenza', 'piattaforma' => null])->save();
    $r = $this->postJson(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $b]), ['formato' => 'pdf']);
    $r->assertStatus(422);
    expect($r->json('errors.convocazione.0'))->toBe('La spedizione non parte: in videoconferenza l\'avviso deve dire la piattaforma (art. 66 c. 3). '
        . 'Scrivila nel passo «Date e ordine del giorno».');
});

it('con il luogo della sola seconda seduta manca quello della prima; con la piattaforma e il luogo la stampa parte', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $a->forceFill(['luogo' => null, 'modalita' => 'mista', 'piattaforma' => 'Meet dello studio'])->save();
    $a->sedute()->where('convocazione', 'seconda')->first()->forceFill(['luogo' => 'Sala del bar, Via Roma 10'])->save();

    $r = $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf']);
    expect($r->status())->toBe(422)->and($r->json('errors.convocazione.0'))->toContain('Manca il luogo della prima convocazione:');

    $a->forceFill(['luogo' => 'Sala condominiale, Via Roma 12'])->save();
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
});

it('in videoconferenza senza il giorno del consenso il passo lo dice, e la stampa parte lo stesso', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $a->forceFill(['modalita' => 'mista', 'piattaforma' => 'Meet dello studio', 'consenso_videoconferenza_il' => null])->save();
    $this->actingAs($s->admin);

    $scheda = app(StatoDellaConvocazione::class)->perLaPagina($a->refresh());
    expect($scheda['videoconferenza'])->toBe('Per la videoconferenza serve il consenso della maggioranza dei condòmini (art. 66 c. 6): nel passo '
        . '«Date e ordine del giorno» non c\'è il giorno in cui è stato dato. Il programma non blocca: decidi tu.');
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();

    $a->forceFill(['consenso_videoconferenza_il' => '2026-10-01'])->save();
    expect(app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['videoconferenza'])->toBeNull();
});
