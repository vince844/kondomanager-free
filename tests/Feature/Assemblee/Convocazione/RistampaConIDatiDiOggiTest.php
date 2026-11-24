<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Gestionale\RegistroInvio;
use App\Services\Assemblee\Convocazione\ElencoDeiConvocati;
use App\Services\Assemblee\Convocazione\StatoDellaConvocazione;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13: una lettera della convocazione solo stampata non è ancora partita, e «da ristampare» vale per ogni cambio che cambia l'avviso.
 *
 * - La ristampa rifà la lettera con i dati di oggi (il nome, l'indirizzo, le unità, i punti della persona), non con quelli della prima stampa: il conduttore
 *   convocato per un punto nuovo lo trova nella lettera ristampata, e «ristampale» si spegne.
 * - «Da ristampare» guarda l'avviso intero: la piattaforma, il tipo, la motivazione di un punto, l'indirizzo della persona; non solo le date e il testo dei punti.
 * - Chi non è più fra i convocati non ha la sua lettera stampata registrata spedita.
 * - «Hai stampato le lettere il …: ristampale» anche nel messaggio dopo «Salva le date», dopo il salvataggio dell'assemblea e dopo un punto.
 *
 * Che cosa non copre: la pagina a video (i vitest della scheda); la PEC della convocazione, che parte una volta sola e non si ristampa.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** La voce dell'elenco di una persona, letta adesso. */
function rstVoce($a, Beta51Scenario $s, string $chi): array
{
    return collect(app(ElencoDeiConvocati::class)->per($a->refresh()))->firstWhere('anagrafica_id', (int) $s->persone[$chi]->id);
}

/** Chi ha la lettera da ristampare, per nome. @return list<string> */
function rstDaRistampare($a): array
{
    return collect(app(ElencoDeiConvocati::class)->per($a->refresh()))->filter(fn ($v) => $v['da_ristampare'])->pluck('nome')->sort()->values()->all();
}

function rstStampa($test, Beta51Scenario $s, $a): void
{
    $test->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
}

it('la catena: un punto nuovo per il conduttore dopo la stampa, «ristampale», la ristampa con i dati di oggi, la raccomandata e la consegna registrate', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    rstStampa($this, $s, $a);
    expect(rstVoce($a, $s, 'luca')['punti'])->toBe([5]);

    // Il punto 7, con il conduttore che vota: le 5 lettere su carta sono di prima.
    $this->post(route('admin.gestionale.assemblee.punti.store', [$s->c, $a]), ['testo' => 'Videosorveglianza del cortile', 'materia' => 'varie', 'conduttore' => 'vota'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => str_contains((string) ($m['message'] ?? ''), 'Hai stampato le lettere il 26/11: ristampale'));
    expect(rstDaRistampare($a))->toBe(['Ferri Luca', 'Gallo Enzo', 'Neri Carla', 'Russo Anna', 'Russo Marco'])
        ->and(app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['lettere']['ristampa'])->toStartWith('Hai stampato le lettere il 26/11: ristampale')
        // La card del passo è da rifare: in arancio.
        ->and(collect(collect(\App\Http\Controllers\Assemblee\PerLePagine::passi($a->refresh()))->firstWhere('chiave', 'convocazione')['manca'])
            ->firstWhere('chiave', 'avviso'))->toMatchArray(['fatto' => false, 'rifare' => true]);

    // La ristampa: la lettera nuova di Luca ha i punti di oggi, e «ristampale» si spegne.
    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk()->assertHeader('X-Avvisi', '5');
    $luca = rstVoce($a, $s, 'luca');
    expect(array_column($luca['invio']->dati['punti'], 'numero'))->toBe([5, 7])
        ->and($luca['punti'])->toBe([5, 7])
        ->and($luca['da_ristampare'])->toBeFalse()
        ->and(rstDaRistampare($a))->toBe([])
        ->and(app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['lettere']['ristampa'])->toBeNull();

    // La raccomandata di Carla e la consegna a mano di Luca si registrano; l'assemblea è convocata.
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [ConvocazioneDiViaRoma12::invio($a, $s, 'carla')->id],
        'canale' => 'raccomandata', 'data' => '2026-11-26'])->assertSessionHasNoErrors();
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [ConvocazioneDiViaRoma12::invio($a, $s, 'luca')->id],
        'canale' => 'a_mano', 'data' => '2026-11-26'])->assertSessionHasNoErrors();
    expect(ConvocazioneDiViaRoma12::invio($a, $s, 'carla')->stato)->toBe(RegistroInvio::STATO_SPEDITA)
        ->and(rstVoce($a, $s, 'luca')['spedito'])->toBeTrue()
        ->and($a->refresh()->stato)->toBe(StatoAssemblea::CONVOCATA);
});

it('«da ristampare» dopo un cambio della piattaforma, del tipo, della motivazione di un punto', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);

    $mista = ConvocazioneDiViaRoma12::ordinaria($s, ['modalita' => 'mista', 'piattaforma' => 'Zoom']);
    rstStampa($this, $s, $mista);
    expect(rstDaRistampare($mista))->toBe([]);
    $this->patch(route('admin.gestionale.assemblee.update', [$s->c, $mista]), ['piattaforma' => 'Teams', 'modalita' => 'mista'])->assertSessionHasNoErrors();
    expect(rstDaRistampare($mista))->toHaveCount(5);

    $tipo = ConvocazioneDiViaRoma12::ordinaria($s);
    rstStampa($this, $s, $tipo);
    $this->patch(route('admin.gestionale.assemblee.update', [$s->c, $tipo]), ['tipo' => 'straordinaria'])->assertSessionHasNoErrors();
    expect(rstDaRistampare($tipo))->toHaveCount(5);

    $motivo = ConvocazioneDiViaRoma12::ordinaria($s);
    rstStampa($this, $s, $motivo);
    $motivo->punti()->where('materia', 'lavori')->first()->forceFill(['dati' => ['motivazione' => 'Il portone non chiude', 'motivazione_di' => 'urgenza']])->save();
    // Il conduttore è convocato per il punto 5, non per il 4: la sua lettera non cambia.
    expect(rstDaRistampare($motivo))->toBe(['Gallo Enzo', 'Neri Carla', 'Russo Anna', 'Russo Marco']);
});

it('l\'indirizzo cambiato fra la stampa e la ristampa: la lettera è da ristampare, e la ristampa porta l\'indirizzo di oggi', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    rstStampa($this, $s, $a);
    $s->persone['marco']->forceFill(['indirizzo' => 'Viale Monza 40, 20127 Milano'])->saveQuietly();

    expect(rstDaRistampare($a))->toBe(['Russo Marco'])
        ->and(rstVoce($a, $s, 'marco')['indirizzo'])->toBe('Viale Monza 40, 20127 Milano');
    $vecchia = ConvocazioneDiViaRoma12::invio($a, $s, 'marco');
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$vecchia->id], 'canale' => 'raccomandata',
        'data' => '2026-11-26'])->assertSessionHasErrors(['invii']);

    $this->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk()->assertHeader('X-Avvisi', '1');
    $nuova = ConvocazioneDiViaRoma12::invio($a, $s, 'marco');
    expect($nuova->destinatario)->toBe('Viale Monza 40, 20127 Milano')
        ->and($nuova->dati['persona']['indirizzo'])->toBe('Viale Monza 40, 20127 Milano')
        ->and(\App\Models\Assemblee\ConvocatoDellAssemblea::query()->where('assemblea_id', $a->id)->where('anagrafica_id', $s->persone['marco']->id)->value('indirizzo'))
        ->toBe('Viale Monza 40, 20127 Milano')
        ->and(rstDaRistampare($a))->toBe([]);
});

it('chi non è più fra i convocati: la sua lettera stampata non si registra spedita, e la riga lo dice', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    rstStampa($this, $s, $a);
    $lettera = ConvocazioneDiViaRoma12::invio($a, $s, 'luca');
    // Il punto 5 del conduttore esce dall'ordine del giorno: Luca non è più convocato.
    $this->delete(route('admin.gestionale.assemblee.punti.destroy', [$s->c, $a, $a->punti()->where('numero', 5)->first()]))->assertSessionHasNoErrors();

    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$lettera->id], 'canale' => 'a_mano', 'data' => '2026-11-26'])
        ->assertSessionHasErrors(['consegne' => 'La lettera di Ferri Luca è di una persona che non è più fra i convocati di questa assemblea: non spedirla.']);
    $riga = collect(app(StatoDellaConvocazione::class)->perLaPagina($a->refresh())['convocati']['righe'])->firstWhere('nome', 'Ferri Luca');
    expect($riga)->toMatchArray(['stato' => 'non_piu_convocato', 'stato_parola' => 'Non più fra i convocati'])
        ->and($riga['azioni']['spedizione'])->toBeFalse()
        ->and($lettera->refresh()->stato)->toBe(RegistroInvio::STATO_STAMPATA);
});

it('«ristampale» nel messaggio dopo «Salva le date» e dopo il salvataggio dell\'assemblea; senza lettere stampate il messaggio di sempre', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    $date = ['sedute' => [
        ['convocazione' => 'prima', 'numero' => 1, 'giorno' => '2026-12-15', 'ora' => '08:00'],
        ['convocazione' => 'seconda', 'numero' => 1, 'giorno' => '2026-12-16', 'ora' => '18:30'],
    ]];
    $this->actingAs($s->admin)->patch(route('admin.gestionale.assemblee.sedute', [$s->c, $a]), $date)->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => ! str_contains((string) ($m['message'] ?? ''), 'ristampale'));

    rstStampa($this, $s, $a);
    ConvocazioneDiViaRoma12::oggi('2026-11-27 10:00');
    $date['sedute'][0]['ora'] = '09:00';
    $this->patch(route('admin.gestionale.assemblee.sedute', [$s->c, $a]), $date)->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => str_contains((string) ($m['message'] ?? ''),
            'Date salvate. Hai stampato le lettere il 26/11: ristampale, perché l\'avviso è cambiato.'));
    $this->patch(route('admin.gestionale.assemblee.update', [$s->c, $a]), ['luogo' => 'Sala riunioni, Via Roma 12'])->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => str_contains((string) ($m['message'] ?? ''), 'Salvato. Hai stampato le lettere il 26/11: ristampale'));
});

it('la PEC comunicata dopo la stampa: la raccomandata stampata è di prima, «Manda le PEC» la convoca per PEC, e la lettera di prima non si spedisce', function () {
    ConvocazioneDiViaRoma12::conLaCasella();
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);
    rstStampa($this, $s, $a);
    $raccomandata = ConvocazioneDiViaRoma12::invio($a, $s, 'carla');
    $s->persone['carla']->forceFill(['pec' => 'carla.neri@pec.it', 'pec_comunicata_il' => '2026-11-26'])->saveQuietly();

    expect(rstVoce($a, $s, 'carla'))->toMatchArray(['da_ristampare' => true])
        ->and(rstVoce($a, $s, 'carla')['mezzo']['mezzo'])->toBe('pec');
    $this->post(route('admin.gestionale.assemblee.convocazione.pec', [$s->c, $a]))->assertRedirect();
    $carla = rstVoce($a, $s, 'carla');
    expect($carla['invio']->canale)->toBe(RegistroInvio::CANALE_PEC)
        ->and($carla['spedito'])->toBeTrue()
        ->and($carla['da_ristampare'])->toBeFalse();
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [$raccomandata->id], 'canale' => 'raccomandata',
        'data' => '2026-11-26'])->assertSessionHasErrors(['invii']);
});
