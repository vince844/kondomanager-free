<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Actions\Cassa\RegistraAperturaCassaAction;
use App\Models\Gestionale\Cassa;
use App\Models\RegistroModifica;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__ . '/../Gestionale/Beta52VoceEntrataScenari.php';
require_once __DIR__ . '/../Gestionale/RendicontoViaRomaScenario.php';
require_once __DIR__ . '/../Gestionale/Beta59313AdPersonamScenari.php';
require_once __DIR__ . '/../Gestionale/LetturaDebitiProva.php';

/**
 * 1.12.0-beta.13 (Coda 679, decisione 556): la riga di una scrittura registrata dice la cifra, «Scrittura registrata: Incasso
 * rata, € 120,00», come la fattura dice «Registrata, € 1.220,00». La riga si scrive alla fine della transazione che registra, quando le righe della
 * scrittura ci sono: un incasso, un pagamento a un fornitore e un giroconto fatto a mano, dalle loro pagine.
 *
 * Che cosa non copre: le scritture dentro un gesto (l'emissione, lo storno), che non hanno una riga loro e si contano nel lotto del gesto.
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

function asrScritture(int $prima)
{
    return RegistroModifica::query()->where('id', '>', $prima)->where('oggetto_tipo', 'scrittura_contabile')->orderBy('id')->get();
}

it('l\'incasso di € 120,00 dalla pagina: «Scrittura registrata: …, € 120,00», una riga sola, con il rimando alla scrittura', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ida', 1000]], 2, 'prima_rata', [], 24000);
    lcdEmetti($this, $s, [1, 2], '2026-01-02');
    $this->travelTo('2026-01-12 10:00:00');
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $id = lcdIncassa($this, $s, 'ida', [lcdQuota($s, 'ida', 1) => 12000], '2026-01-10');

    $righe = asrScritture($prima);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->riepilogo)->toStartWith('Scrittura registrata: ')
        ->and($righe[0]->riepilogo)->toEndWith(', € 120,00')
        ->and($righe[0]->rimando)->toMatchArray(['tipo' => 'scrittura_contabile', 'id' => $id])
        ->and($righe[0]->condomini())->toHaveKey($s['c']->id);
});

it('il pagamento di una fattura a un fornitore dalla pagina: la riga della scrittura dice la cifra pagata', function () {
    $ctx = setupPagamentiService();
    [$condominio, $esercizio, , $fornitore, $contoCorrenteId] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.store', $condominio), [
        'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id, 'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->toDateString(), 'metodo_pagamento' => \App\Enums\MetodoPagamento::BONIFICO->value, 'allow_overdraft' => true,
        'allocazioni' => [['fattura_id' => $fattura->id, 'tipo' => \App\Enums\TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => $fattura->netto_a_pagare]],
    ])->assertSessionHasNoErrors();

    $righe = asrScritture($prima);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->riepilogo)->toStartWith('Scrittura registrata: ')
        ->and($righe[0]->riepilogo)->toEndWith(', ' . \App\Helpers\MoneyHelper::format((int) $fattura->netto_a_pagare));
});

it('il giroconto fatto a mano di € 2.000,00 dalla banca al fondo: la riga della scrittura dice € 2.000,00', function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-01-02 10:00', 'Europe/Rome'));
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 1000.0);
    app(RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $this->actingAs($this->user)->post(route('admin.gestionale.casse.store', $s['c']), [
        'nome' => 'Facciata', 'tipo' => 'fondo', 'sottotipo_fondo' => 'vincolato_lavori', 'saldo_iniziale' => '10.000,00',
        'vincolo_descrizione' => 'Lavori deliberati',
    ])->assertSessionHasNoErrors();
    $fondo = Cassa::where('condominio_id', $s['c']->id)->where('nome', 'Facciata')->firstOrFail();
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-03-15 10:00', 'Europe/Rome'));
    $prima = RegistroModifica::query()->max('id') ?? 0;

    $this->actingAs($this->user)->post(route('admin.gestionale.giroconti.store', $s['c']), [
        'cassa_origine_id' => $s['cassa']->id, 'cassa_destinazione_id' => $fondo->id, 'importo' => 2000.00, 'data_operazione' => '2026-03-15',
        'causale' => 'Accantonamento per la facciata', 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id,
    ])->assertSessionHasNoErrors();

    $righe = asrScritture($prima);
    expect($righe->pluck('riepilogo')->all())->toBe(['Scrittura registrata: Accantonamento per la facciata, € 2.000,00']);
});
