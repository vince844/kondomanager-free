<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Gestionale\InboxService;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (decisione 561; §0.4 del progetto della tappa 2): il compito «Convocare l'assemblea per la spesa urgente» della fattura n. 45 si chiude quando
 * parte la convocazione con il punto di quella fattura, con la frase; il pagamento non cambia. Il compito della ratifica resta: lo chiude la delibera.
 *
 * Che cosa non copre: la ratifica (è del motore, `RatificaConLaDeliberaTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi('2026-12-02 10:00');
});
afterEach(fn () => Carbon::setTestNow());

it('parte la convocazione con il punto della fattura n. 45: il compito «convocazione_urgenza» si chiude, quello della ratifica no', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $urgenza = InboxService::createTask(EventoTipo::CONVOCAZIONE_URGENZA, 'Convocare l\'assemblea per la spesa urgente', 'Fattura n. 45', Carbon::now(), null,
        (int) $s->c->id, ['fattura_id' => (int) $fattura->id]);
    $ratifica = InboxService::createTask(EventoTipo::RATIFICA_SFORO, 'Ratifica in assemblea della fattura n. 45', 'Fattura n. 45', Carbon::now(), null,
        (int) $s->c->id, ['fattura_id' => (int) $fattura->id]);
    $a = ConvocazioneDiViaRoma12::ordinaria($s, [], [
        ['testo' => 'Ratifica della spesa urgente di € 1.800,00 (fattura n. 45 di Idraulica Bianchi)', 'materia' => 'ratifica', 'chiave_fonte' => 'fattura:' . $fattura->id,
            'oggetti' => [['fattura_passiva', (int) $fattura->id, 'origine']]],
        ['testo' => 'Varie ed eventuali', 'materia' => 'varie'],
    ]);

    // La stampa non spedisce: il compito si chiude con la prima spedizione registrata, qui la raccomandata di Neri Carla.
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.convocazione.lettere', [$s->c, $a]), ['formato' => 'pdf'])->assertOk();
    expect($urgenza->refresh()->is_completed)->toBeFalse();
    $this->post(route('admin.gestionale.assemblee.convocazione.spedizione', [$s->c, $a]), ['invii' => [ConvocazioneDiViaRoma12::invio($a, $s, 'carla')->id],
        'canale' => 'raccomandata', 'data' => '2026-12-02'])->assertSessionHasNoErrors();
    expect($urgenza->refresh()->is_completed)->toBeTrue()->and($ratifica->refresh()->is_completed)->toBeFalse()
        ->and(\App\Models\Gestionale\RispostaPromemoria::query()->where('evento_id', $urgenza->id)->value('nota'))
        ->toBe('Chiuso: è partita la convocazione dell\'assemblea del 15/12/2026, con il punto 1.')
        ->and($fattura->refresh()->stato_approvazione)->toBe('sforo_motivato');
});
