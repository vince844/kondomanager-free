<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'azione dei bonifici segnalati («N bonifici segnalati, € …: verificali prima di sollecitare») conta solo il denaro che arriva sul conto. La richiesta del
 * condòmino di saldare la rata con tutto il suo credito (nessun bonifico, € 0,00, come la scrive `PaymentReportingController`) è una compensazione: non è un
 * bonifico da verificare. Un credito usato in parte con un bonifico per la differenza resta un bonifico, con la sua cifra.
 */

use App\Enums\EventoTipo;
use App\Services\Gestionale\InboxService;
use App\Services\Treasury\TreasuryGuardianService;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Gestionale/LetturaDebitiProva.php';
require_once __DIR__.'/../Gestionale/SeiMesiPerAgireScenario.php';

beforeEach(function () {
    $this->user = smaUtente();
    $this->withoutVite();
});

function ccbSegnalato(array $s, int $userId, string $rata, int $dichiarato, bool $conIlCredito): void
{
    InboxService::createTask(tipo: EventoTipo::VERIFICA_PAGAMENTO, title: 'Verifica Incasso: ' . $rata, description: 'Segnalato dal portale',
        scadenza: Carbon::parse('2026-11-16 09:00'), createdByUserId: $userId, condominioId: (int) $s['c']->id,
        extraMeta: ['intent_usa_credito' => $conIlCredito, 'importo_dichiarato' => $dichiarato]);
}

it('la richiesta di saldare la rata con tutto il credito non è un bonifico segnalato; un bonifico, anche con una parte di credito, sì', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['rossi', 1000]], 12, 'prima_rata', [], 120000);
    lcdEmetti($this, $s, range(1, 12), '2026-01-02');
    $this->travelTo('2026-11-16 10:00:00');
    $bonifici = fn () => app(TreasuryGuardianService::class)->perCondominio((int) $s['c']->id)->toArray()['datiDelleAzioni']['bonifici'];

    // Rata 11 saldata con il credito: nessun incasso bancario previsto.
    ccbSegnalato($s, $this->user->id, 'Rata 11', 0, true);
    expect($bonifici())->toBe(['quanti' => 0, 'cifra' => 0, 'rimandati' => 0, 'rimandatiAl' => null, 'rimandatiGiorni' => 0]);

    // Rata 12: € 60,00 di credito e € 40,00 in banca; e un bonifico intero di € 100,00 senza credito.
    ccbSegnalato($s, $this->user->id, 'Rata 12', 4000, true);
    ccbSegnalato($s, $this->user->id, 'Rata 10', 10000, false);
    expect($bonifici())->toBe(['quanti' => 2, 'cifra' => 14000, 'rimandati' => 0, 'rimandatiAl' => null, 'rimandatiGiorni' => 0]);
});
