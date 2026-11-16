<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'accordo di rientro di chi muore, nella successione di U1 con l'arretrato agli eredi (Anna e Bruno, metà ciascuno), sul caso di Neri
 * (`AccordoCalendarioScenario.php`): il debito che l'accordo copre passa agli eredi con l'arretrato, per quota, e l'accordo si chiude «per la
 * successione» il giorno del passaggio, 01/09/2026. Il calendario non conta come versato quello che è passato agli eredi (gli interessi e il sollecito,
 * € 25,92, escono dalle quote di Neri senza un versamento): nessuna rata versata. L'anteprima lo dice; annullato il passaggio, l'accordo si riapre. Con
 * l'arretrato a nome del defunto l'accordo resta.
 */

use App\Models\Anagrafica;
use App\Models\Gestionale\AccordoRientro;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

function ansErede(array $s, string $nome): Anagrafica
{
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . '-ans-' . $s['c']->id . '@test.it', 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'ANS' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** I dati della successione di U1 dal 01/09/2026, con l'arretrato agli eredi o a nome del defunto. */
function ansDati(array $s, Anagrafica $anna, Anagrafica $bruno, string $arretrato): array
{
    return ['tipo' => 'successione', 'riga_uscente_id' => $s['righe']['Neri'], 'decorrenza' => '2026-09-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $anna->id, 'quota' => 50], ['anagrafica_id' => $bruno->id, 'quota' => 50]], 'arretrato' => $arretrato, 'erede_di_riferimento' => $anna->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta']
        + ($arretrato === 'defunto' ? ['conguaglio' => \App\Models\Gestionale\Subentro::SCRIVI_IL_CONGUAGLIO] : []);
}

it('con l\'arretrato agli eredi l\'accordo di Neri si chiude per la successione il 01/09; il calendario non conta come versato quello che è passato agli eredi; annullato il passaggio si riapre', function () {
    [$s, , , $a] = aclAccordo($this);
    $anna = ansErede($s, 'Anna');
    $bruno = ansErede($s, 'Bruno');
    $this->travelTo('2026-09-10 10:00:00');
    $dati = ansDati($s, $anna, $bruno, 'eredi');

    $frasi = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), $dati)->assertOk()->json('rate.frasi');
    expect($frasi)->toContain('Neri ha un accordo di rientro aperto su U1, del 15/07/2026: con l\'arretrato agli eredi l\'accordo si chiude per la successione, dal 01/09/2026, '
        . 'e il debito che resta passa agli eredi con l\'arretrato, per quota; con ognuno puoi registrare un accordo nuovo.')
        ->and(collect($frasi)->filter(fn ($f) => str_contains($f, 'non tocca l\'accordo'))->all())->toBe([]);

    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $dati)->assertSessionHasNoErrors();
    $a = $a->fresh();
    expect($a->chiuso_il->format('Y-m-d'))->toBe('2026-09-01')
        ->and($a->decadenza_registrata_il)->toBeNull()
        ->and($a->nota_chiusura)->toBe('Per la successione di Neri, dal 01/09/2026: il debito che resta passa agli eredi con l\'arretrato, per quota, e con ognuno si può registrare un accordo nuovo.');
    $letto = aclLetto($this, $s)['accordi'][0];
    expect($letto['in_corso'])->toBeFalse()
        // Nessun versamento: nessuna rata del calendario versata, anche se gli interessi e il sollecito sono usciti dalle quote di Neri.
        ->and(aclRate(['accordi' => [$letto]]))->toBe([[1, 0, 7531, false], [2, 0, 7531, false], [3, 0, 7530, false]]);

    // Annullato il passaggio, l'accordo torna in corso.
    $subentro = \App\Models\Gestionale\Subentro::where('immobile_id', $s['unita']['U1']->id)->whereNull('subentro_padre_id')->orderByDesc('id')->firstOrFail();
    $this->actingAs($this->user)->delete(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $subentro]), ['nota_annullamento' => 'Successione registrata per errore'])
        ->assertSessionHasNoErrors();
    expect($a->fresh()->chiuso_il)->toBeNull()->and($a->fresh()->nota_chiusura)->toBeNull();
});

it('con l\'arretrato a nome del defunto l\'accordo resta, e l\'anteprima dice che le rate coperte restano a suo nome', function () {
    [$s, , , $a] = aclAccordo($this);
    $anna = ansErede($s, 'Anna');
    $bruno = ansErede($s, 'Bruno');
    $this->travelTo('2026-09-10 10:00:00');
    $dati = ansDati($s, $anna, $bruno, 'defunto');
    $frasi = $this->actingAs($this->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']['U1']]), $dati)->assertOk()->json('rate.frasi');
    expect($frasi)->toContain('Neri ha un accordo di rientro aperto su U1, del 15/07/2026: le rate che copre restano a suo nome.');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), $dati)->assertSessionHasNoErrors();
    expect($a->fresh()->chiuso_il)->toBeNull();
});
