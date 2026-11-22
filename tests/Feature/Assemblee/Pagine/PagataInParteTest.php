<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\FatturaPassiva;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/../../Gestionale/Beta52VoceEntrataScenari.php';

/**
 * 1.12.0-beta.13: la fattura sbloccata per l'urgenza e pagata solo in parte dice «Pagata in parte per l'urgenza» fino al saldo, poi «Pagata per l'urgenza»,
 * nella pagina della fattura e nel punto proposto per la prossima assemblea. La catena: il pagamento di una parte → «Pagata in parte» → il saldo → «Pagata».
 *
 * Che cosa non copre: come la pagina della fattura e la finestra «Ratifica» disegnano le parole (i vitest).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    $this->withoutVite();
});
afterEach(fn () => Carbon::setTestNow());

it('pagata in parte per l\'urgenza fino al saldo, poi pagata per l\'urgenza: la pagina della fattura e il punto proposto', function () {
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    $this->travelTo(Carbon::parse('2026-11-13 10:00', 'Europe/Rome'));
    $this->user = be52Utente();
    $s = be52Scenario($this);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $fornitore = be52Fornitore();
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $fornitore, $s['spesa']->id, 900.00, [], 'FT-46'))
        ->assertSessionHasNoErrors();
    $fattura = FatturaPassiva::where('numero_documento', 'FT-46')->where('condominio_id', $s['c']->id)->firstOrFail();
    $fattura->forceFill(['stato_approvazione' => 'sforo_motivato', 'dati_extra' => array_merge((array) $fattura->dati_extra,
        ['override_budget' => ['importo_sforo' => 30000, 'motivazione' => 'Perdita dalla colonna dell\'acqua']])])->save();
    $this->post(route('admin.gestionale.fatture.approva-sforo', [$s['c'], $fattura]), ['note' => 'Perdita dalla colonna dell\'acqua'])->assertSessionHasNoErrors();
    $pagina = fn () => $this->get(route('admin.gestionale.fatture.show', [$s['c'], $fattura]))->assertOk()->viewData('page')['props']['ratifica']['sbloccata'];
    $frase = fn () => collect(ProposteDellOrdineDelGiorno::per($s['c']->refresh(), null))->flatMap(fn ($g) => $g['punti'])
        ->firstWhere('chiave_fonte', 'fattura:' . $fattura->id)['frase'] ?? null;
    $paga = fn (int $centesimi) => $this->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $fornitore->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => '2026-11-13',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'bonifico_parlante' => false,
        'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $fattura->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => $centesimi]],
    ])->assertSessionHasNoErrors();

    // Una parte: € 400,00 su € 900,00.
    $paga(40000);
    expect($fattura->refresh()->stato_pagamento->value ?? $fattura->stato_pagamento)->toBe('parziale')
        ->and($pagina())->toMatchArray(['pagata' => true, 'parola' => 'Pagata in parte'])
        ->and($frase())->toStartWith('Pagata in parte per l\'urgenza: va riferita alla prima assemblea');

    // Il saldo.
    $paga(50000);
    expect($fattura->refresh()->stato_pagamento->value ?? $fattura->stato_pagamento)->toBe('pagata')
        ->and($pagina())->toMatchArray(['pagata' => true, 'parola' => 'Pagata'])
        ->and($frase())->toStartWith('Pagata per l\'urgenza: va riferita alla prima assemblea');
});
