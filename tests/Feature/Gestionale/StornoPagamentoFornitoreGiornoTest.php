<?php

/**
 * Lo storno di un pagamento a un fornitore prende il giorno dell'amministratore (Europe/Rome), come ogni data che il programma scrive da sé: alle 00:30 di Roma
 * del 01/01 è già il nuovo anno, anche se in UTC è ancora il 31/12. Con la situazione patrimoniale alla data, uno storno datato il giorno prima
 * cambierebbe le fatture aperte dell'anno chiuso.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/RendicontoViaRomaScenario.php';

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

it('alle 00:30 di Roma del 01/01/2027 lo storno di un pagamento è del 01/01/2027: al 31/12/2026 la fattura era pagata', function () {
    $s = vrmBase($this);
    vrmGiorno($this, '2026-12-10');
    $ft = vrmFattura($this, $s, $s['fornitore'], 'FT-DIC', [[$s['pulizie']->id, 300.00]], '2026-12-10');
    $pagamento = vrmPaga($this, $s, $s['fornitore'], [[$ft, 'pagamento', 30000]], '2026-12-10');
    $this->travelTo(Carbon::parse('2027-01-01 00:30', 'Europe/Rome'));
    expect(now('UTC')->format('Y-m-d'))->toBe('2026-12-31');

    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $pagamento]), ['motivo' => 'Bonifico respinto dalla banca'])->assertSessionHasNoErrors();

    $storno = \App\Models\Gestionale\PagamentoFornitore::where('pagamento_padre_id', $pagamento->id)->firstOrFail();
    $scrittura = DB::table('scritture_contabili')->where('id', $storno->scrittura_contabile_id)->first();
    expect(substr((string) $storno->data_pagamento, 0, 10))->toBe('2027-01-01')
        ->and(substr((string) $scrittura->data_competenza, 0, 10))->toBe('2027-01-01')
        ->and(substr((string) $scrittura->data_registrazione, 0, 10))->toBe('2027-01-01')
        ->and(app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e'])['debiti']['fatture'])->toBe([]);
});
