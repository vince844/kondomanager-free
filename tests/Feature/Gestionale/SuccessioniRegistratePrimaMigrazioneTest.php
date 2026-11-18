<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La migrazione dei dati delle successioni registrate con la 1.12.0-beta.10 (`2026_11_17_150000_riallinea_le_successioni_registrate_prima`). Con la beta.10
 * la successione con l'arretrato agli eredi chiudeva la quota del sollecito di Neri, ma il suo promemoria restava «€ 16,01 da versare» e gli eredi non
 * avevano il loro; l'accordo di rientro di Neri si chiudeva senza la scelta per gli interessi. La prova rifà quello stato dopo una successione di oggi e
 * lancia la migrazione: il promemoria di Neri dice «pagato», Anna e Bruno hanno il loro (€ 8,01 e € 8,00), l'accordo prende il di serie. Rilanciata non
 * cambia niente.
 */

use App\Models\Evento;
use App\Models\Gestionale\AccordoRientro;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

function srpErede(array $s, string $nome): \App\Models\Anagrafica
{
    $p = \App\Models\Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . '-srp-' . $s['c']->id . '@test.it', 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'SRP' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** I promemoria della rata, per persona: anagrafica => [stato, restante]. */
function srpPromemoria(int $rataId): array
{
    return Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->context->rata_id', $rataId)->with('anagrafiche:id')->get()
        ->mapWithKeys(fn (Evento $e) => [(int) $e->anagrafiche->first()->id => [$e->meta['status'], (int) $e->meta['importo_restante']]])->sortKeys()->all();
}

it('le successioni della beta.10: i promemoria seguono le quote e l\'accordo del defunto prende il di serie; rilanciata non cambia niente', function () {
    $s = accViaVerdi($this);
    $neri = (int) $s['persone']['Neri']->id;
    $this->travelTo('2026-07-10 10:00:00');
    $rataId = (int) adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '16,01', 'causale' => 'Spese di sollecito', 'scadenza' => '2026-07-10',
        'natura' => 'accessorio', 'tipo_accessorio' => 'sollecito', 'base' => 'delibera', 'riferimento' => 'del 14/03/2026, verbale n. 1', 'conto_id' => $s['postali']->id]])
        ->assertCreated()->json('rate.0');
    aclRegistra($this, $s, [['rata_id' => lcdRata($s, 1), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '300,00']],
        aclCalendario(['150,00', '150,00'], ['2026-07-31', '2026-08-31']))->assertCreated();
    $accordo = AccordoRientro::latest('id')->firstOrFail();
    $anna = srpErede($s, 'Anna');
    $bruno = srpErede($s, 'Bruno');
    $prima = Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->context->rata_id', $rataId)->firstOrFail();
    $metaDiPrima = $prima->meta;

    $this->travelTo('2026-09-10 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), [
        'tipo' => 'successione', 'riga_uscente_id' => $s['righe']['Neri'], 'decorrenza' => '2026-09-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $anna->id, 'quota' => 50], ['anagrafica_id' => $bruno->id, 'quota' => 50]], 'arretrato' => 'eredi', 'erede_di_riferimento' => $anna->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta',
    ])->assertSessionHasNoErrors();
    $oggi = srpPromemoria($rataId);
    expect($oggi)->toBe([$neri => ['paid', 0], (int) $anna->id => ['pending', 801], (int) $bruno->id => ['pending', 800]]);

    // Lo stato che lasciava la beta.10: il promemoria di Neri com'era prima, nessuno per gli eredi, l'accordo chiuso senza la scelta.
    $prima->fresh()->forceFill(['meta' => $metaDiPrima])->save();
    Evento::where('tipo', \App\Enums\EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->context->rata_id', $rataId)->where('id', '!=', $prima->id)->delete();
    $accordo->forceFill(['chiusura_per_successione' => null])->save();
    expect(srpPromemoria($rataId))->toBe([$neri => ['pending', 1601]]);

    $migrazione = require database_path('migrations/2026_11_17_150000_riallinea_le_successioni_registrate_prima.php');
    $migrazione->up();
    expect(srpPromemoria($rataId))->toBe($oggi)
        ->and($accordo->fresh()->chiusura_per_successione)->toBe(AccordoRientro::SUCCESSIONE_IN_REGOLA);

    $quanti = Evento::count();
    $migrazione->up();
    expect(srpPromemoria($rataId))->toBe($oggi)->and(Evento::count())->toBe($quanti);
});

it('una successione annullata non si tocca', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-07-10 10:00:00');
    $rataId = (int) adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '16,01', 'causale' => 'Spese di sollecito', 'scadenza' => '2026-07-10',
        'natura' => 'accessorio', 'tipo_accessorio' => 'sollecito', 'base' => 'delibera', 'riferimento' => 'del 14/03/2026, verbale n. 1', 'conto_id' => $s['postali']->id]])
        ->assertCreated()->json('rate.0');
    $anna = srpErede($s, 'Anna');
    $bruno = srpErede($s, 'Bruno');
    $this->travelTo('2026-09-10 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), [
        'tipo' => 'successione', 'riga_uscente_id' => $s['righe']['Neri'], 'decorrenza' => '2026-09-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $anna->id, 'quota' => 50], ['anagrafica_id' => $bruno->id, 'quota' => 50]], 'arretrato' => 'eredi', 'erede_di_riferimento' => $anna->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta',
    ])->assertSessionHasNoErrors();
    $passaggio = \App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']['U1']->id)->latest('id')->firstOrFail();
    $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Prova di annullamento'])
        ->assertRedirect();
    $dopo = srpPromemoria($rataId);

    (require database_path('migrations/2026_11_17_150000_riallinea_le_successioni_registrate_prima.php'))->up();
    expect(srpPromemoria($rataId))->toBe($dopo);
});
