<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un bonifico segnalato dal portale e rimandato dall'amministratore con «Ricordamelo» resta nell'azione D della tesoreria («verificalo prima di
 * sollecitare»): la rata del condòmino resta in verifica, e la tesoreria non deve far sollecitare chi ha detto di aver pagato. I dati dell'azione dicono quanti
 * sono rimandati e il primo giorno; il compito resta nascosto nell'Inbox fino al giorno scelto, e quel giorno torna (non è più «rimandato»).
 */

use App\Models\Evento;
use App\Services\Treasury\DatiDelleAzioni;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../Dashboard/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

function brdBonifici($test): array
{
    $oggi = CarbonImmutable::parse(now('Europe/Rome')->toDateString());

    return app(DatiDelleAzioni::class)->perCondominio($test->condominio, null, $oggi, $oggi->addDays(30), [])['bonifici'];
}

it('un bonifico segnalato rimandato con «Ricordamelo» resta nell\'azione D, con il giorno; nell\'Inbox resta nascosto fino a quel giorno', function () {
    [$compito, $rata] = icsBonifico($this);
    expect(brdBonifici($this))->toMatchArray(['quanti' => 1, 'cifra' => 12345, 'rimandati' => 0, 'rimandatiAl' => null]);

    icsRispondi($this, $compito, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-27'])->assertSessionHasNoErrors();

    // La rata del condòmino resta «in verifica»: il denaro forse è già sul conto, e una lettera di sollecito andrebbe a chi ha pagato.
    expect(Evento::find($rata->id)->meta['status'])->toBe('reported')
        ->and(brdBonifici($this))->toBe(['quanti' => 1, 'cifra' => 12345, 'rimandati' => 1, 'rimandatiAl' => '2026-05-27', 'rimandatiGiorni' => 1])
        // Il «Ricordamelo» non si annulla: nell'Inbox il compito resta nascosto fino al giorno scelto.
        ->and(icsCasella($this))->not->toContain($compito->title);

    // Il giorno scelto il compito torna nell'Inbox, e per la tesoreria non è più rimandato.
    $this->travelTo(Carbon::parse('2026-05-27 10:00', 'Europe/Rome')->utc());
    expect(icsCasella($this))->toContain($compito->title)
        ->and(brdBonifici($this))->toMatchArray(['quanti' => 1, 'cifra' => 12345, 'rimandati' => 0, 'rimandatiAl' => null]);
});

it('il controllo: un bonifico chiuso con l\'incasso non conta più, rimandato o no', function () {
    [$compito] = icsBonifico($this);
    icsRispondi($this, $compito, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-27'])->assertSessionHasNoErrors();
    Evento::whereKey($compito->id)->update(['is_completed' => true]);

    expect(brdBonifici($this))->toMatchArray(['quanti' => 0, 'cifra' => 0, 'rimandati' => 0, 'rimandatiAl' => null]);
});
