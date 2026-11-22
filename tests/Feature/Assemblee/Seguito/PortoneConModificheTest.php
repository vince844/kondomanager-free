<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Services\Assemblee\Seguito\Effetti\SuiLavori;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * «Crea il piano straordinario» per il portone di Via Roma 12, deliberato su carta il 15/12/2026 per il 2027, con la cosa «entro il 15/01/2027»:
 *
 * - **approvato con modifiche** («Importo ridotto a € 13.000,00»): la finestra mette in testa «Che cosa ha cambiato l'assemblea» e lascia l'importo da
 *   scrivere; la cosa non porta la cifra di prima; il fondo speciale non confronta con la cifra di prima, che non è deliberata;
 * - **l'esercizio proposto** è quello aperto che contiene il giorno «entro il» della cosa (il 2027), non quello di oggi che si chiude fra quindici giorni;
 *   senza il giorno, quello di oggi come prima.
 *
 * Che cosa non copre: la finestra a video (`CreaIlPianoStraordinario.test.ts`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/**
 * Il portone su carta, con l'esito e le modifiche dati, e la cosa confermata con il giorno dato (o senza).
 *
 * @return array{s: Beta51Scenario, a: \App\Models\Assemblee\Assemblea, d: \App\Models\Assemblee\Delibera, cosa: CosaDaFareDellaDelibera, e27: Esercizio}
 */
function pcmPortone($t, string $esito = 'approvata', ?string $modifiche = null, ?string $entroIl = '2027-01-15'): array
{
    $s = ViaRoma12::nuovo($t)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'anna');
    $e27 = Esercizio::factory()->create(['condominio_id' => $s->c->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31',
        'stato' => 'aperto']);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Portone: € 14.500,00 con Serramenti Marino, fondo speciale', 'materia' => 'lavori', 'importo' => 1450000]],
        '2026-12-15', [1 => $esito]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    $chiave = $d->id . ':cosa:crea_piano_straordinario';
    SeguitoDiViaRoma12::conferma($t, $s, $a, null, array_filter(['modifiche' => $modifiche === null ? null : [$d->id => $modifiche],
        'cose' => [$chiave => array_filter(['entro_il' => $entroIl, 'a_chi' => 'me'])]]))->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->where('tipo', TipoCosaDaFare::CREA_PIANO_STRAORDINARIO->value)->firstOrFail();

    return ['s' => $s, 'a' => $a, 'd' => $d, 'cosa' => $cosa, 'e27' => $e27];
}

/** La proposta della finestra, dalla sua rotta. @param array<string, mixed> $x @return array<string, mixed> */
function pcmProposta($t, array $x): array
{
    return $t->actingAs($x['s']->admin)->getJson(route('admin.gestionale.assemblee.seguito.piano-straordinario.proposta',
        ['condominio' => $x['s']->c->id, 'assemblea' => $x['a']->id, 'cosa' => $x['cosa']->id]))->assertOk()->json();
}

it('catena · il portone approvato con modifiche → la finestra con «Che cosa ha cambiato l\'assemblea» e l\'importo da scrivere → il piano da € 13.000,00 → il fondo speciale senza la cifra di prima', function () {
    $x = pcmPortone($this, 'approvata_con_modifiche', 'Importo ridotto a € 13.000,00');
    ['s' => $s, 'a' => $a, 'd' => $d, 'cosa' => $cosa] = $x;

    // La cosa e il compito non portano la cifra di prima: non è più deliberata.
    expect($cosa->testo)->toBe('Crea il piano straordinario');

    // La finestra: la modifica in testa, l'importo da scrivere, nessun confronto con la delibera.
    $proposta = pcmProposta($this, $x);
    expect($proposta['modifiche'])->toBe('Importo ridotto a € 13.000,00')
        ->and($proposta['voce']['importo'])->toBeNull()
        ->and($proposta['delibera']['importo'])->toBeNull()
        ->and($proposta['a_carico'])->toBe('A carico del proprietario, come ogni spesa straordinaria.');

    // Andrea scrive € 13.000,00 e salva il piano con la delibera: il fondo speciale non confronta con € 14.500,00.
    $esito = $this->actingAs($s->admin)->postJson(route('admin.gestionale.assemblee.seguito.piano-straordinario',
        ['condominio' => $s->c->id, 'assemblea' => $a->id, 'cosa' => $cosa->id]), ['gestione_nome' => 'Portone d\'ingresso 2027', 'esercizio_id' => $x['e27']->id,
        'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'voce_nome' => 'Sostituzione del portone', 'importo' => '13.000,00',
        'tabella_id' => $s->tabelle['Proprietà']->id])->assertOk()->json();
    $gestione = Gestione::query()->where('condominio_id', $s->c->id)->where('tipo', 'straordinaria')->sole();
    $voce = Conto::query()->whereIn('piano_conto_id', PianoConto::query()->where('gestione_id', $gestione->id)->select('id'))->sole();
    expect((int) $voce->importo)->toBe(1300000);
    $this->actingAs($s->admin)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $x['e27']]), [
        'tipo' => 'ordinario', 'gestione_id' => $gestione->id, 'nome' => 'Portone d\'ingresso 2027 — rate', 'metodo_distribuzione' => 'tutte_rate',
        'numero_rate' => 1, 'giorno_scadenza' => 31, 'data_prima_scadenza' => '2027-01-31', 'genera_subito' => 0, 'delibera_id' => $d->id,
        'capitoli_config' => [['id' => $voce->id, 'importo' => '13.000,00']],
    ])->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('gestione_id', $gestione->id)->sole();

    expect(SuiLavori::fondoSpeciale($d->fresh()))->toBeNull()
        ->and($esito['creata'])->toBeTrue();
    $this->actingAs($s->admin)->get(route('admin.gestionale.esercizi.piani-rate.show', [$s->c, $x['e27'], $piano]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->where('fondo_speciale', null));
});

it('il portone approvato senza modifiche resta com\'era: la cifra deliberata nella finestra e nella cosa', function () {
    $x = pcmPortone($this);
    $proposta = pcmProposta($this, $x);

    expect($x['cosa']->testo)->toBe('Crea il piano straordinario (€ 14.500,00)')
        ->and($proposta['modifiche'])->toBeNull()
        ->and($proposta['voce']['importo'])->toBe(1450000)
        ->and($proposta['delibera']['importo'])->toBe(1450000)
        ->and($proposta['a_carico'])->toBe(\App\Services\Assemblee\Seguito\PianoStraordinario::A_CARICO);
});

it('l\'esercizio proposto è quello aperto che contiene il giorno «entro il» della cosa: «Portone 2027» nel 2027', function () {
    $x = pcmPortone($this);

    expect(pcmProposta($this, $x)['gestione'])->toMatchArray(['nome' => 'Portone 2027', 'esercizio_id' => $x['e27']->id, 'data_inizio' => '2027-01-01',
        'data_fine' => '2027-12-31']);
});

it('senza il giorno «entro il» l\'esercizio proposto resta quello di oggi, se è aperto', function () {
    $x = pcmPortone($this, entroIl: null);

    expect($x['cosa']->entro_il)->toBeNull()
        ->and(pcmProposta($this, $x)['gestione'])->toMatchArray(['nome' => 'Portone 2026', 'esercizio_id' => $x['s']->e->id]);
});
