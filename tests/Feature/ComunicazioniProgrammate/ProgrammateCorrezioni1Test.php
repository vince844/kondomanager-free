<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Pubblicazione programmata delle comunicazioni, il giro di correzioni 1 (Coda 589)
 *
 * - **Le due date sono colonne `dateTime`, non `timestamp`** (P2): su MySQL un `timestamp` segue il fuso della connessione,
 *   e con un fuso a ora legale rifiuta l'ora che non esiste nella notte del cambio di marzo. I test girano su SQLite, che
 *   non distingue i due tipi: si legge il file della migrazione.
 * - **Programmando dalla modifica il messaggio dice per quando** (P3), come alla creazione.
 * - **La pagina ha il testo per chi sceglie «Programmata» senza l'ora** (P1): che si fermi lo prova il vitest
 *   `resources/js/pages/comunicazioni/ProgrammataSenzaOra.test.ts`.
 *
 * L'orologio è fermo al 12/11/2026, ore 10:00 UTC: le 11:00 a Roma.
 */

use Illuminate\Support\Facades\Lang;

require_once __DIR__ . '/ProgrammateScenario.php';

beforeEach(function () {
    cprPrepara($this);
});

/** Il messaggio rimasto in sessione dopo un salvataggio, comunque sia fatto il lampo. */
function cprMessaggi(): string
{
    return json_encode(session()->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// --- P2: il tipo delle colonne -------------------------------------------------------------------------

test('la migrazione crea le due date come `dateTime`, non come `timestamp`', function () {
    $sorgente = file_get_contents(database_path(CPR_MIGRAZIONE_COLONNE));

    expect($sorgente)->toMatch('/->dateTime\(\s*[\'"]programmata_per[\'"]\s*\)/')
        ->and($sorgente)->toMatch('/->dateTime\(\s*[\'"]pubblicata_il[\'"]\s*\)/')
        ->and($sorgente)->not->toMatch('/->timestamp\(/');
});

// --- P3: il messaggio dopo la modifica -----------------------------------------------------------------

test('programmando dalla modifica il messaggio dice per quando', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin, ['is_published' => false]);

    cprModifica($this, $this->admin, $c, cprModulo([$this->aurora], ['data_programmata' => '2027-01-15T09:00']))->assertSessionHasNoErrors();

    expect(cprMessaggi())->toContain('Comunicazione programmata per il 15/01/2027 alle 09:00.')
        ->and(cprMessaggi())->not->toContain(__('comunicazioni.success_update_communication'));
});

test('correggendo il testo di una programmata il messaggio ricorda per quando è, nel fuso dell\'installazione', function () {
    // Le 7:00 UTC di luglio sono le 9:00 di Roma, con l'ora legale.
    $c = cprProgrammata([$this->aurora], $this->admin, '2027-07-15 07:00:00');

    cprModifica($this, $this->admin, $c, cprModulo([$this->aurora], ['subject' => 'Chiusura dell\'acqua, giovedì']))->assertSessionHasNoErrors();

    expect(cprRiga($c)->programmata_per)->toBe('2027-07-15 07:00:00')
        ->and(cprMessaggi())->toContain('Comunicazione programmata per il 15/07/2027 alle 09:00.');
});

test('negli altri casi il messaggio dopo la modifica resta quello di sempre', function (array $campi) {
    $c = cprProgrammata([$this->aurora], $this->admin, '2027-01-15 08:00:00');

    cprModifica($this, $this->admin, $c, cprModulo([$this->aurora], $campi))->assertSessionHasNoErrors();

    expect(cprRiga($c)->programmata_per)->toBeNull()
        ->and(cprMessaggi())->toContain(__('comunicazioni.success_update_communication'))
        ->and(cprMessaggi())->not->toContain('Comunicazione programmata per il');
})->with([
    'riportata a bozza' => [['data_programmata' => null]],
    'pubblicata subito' => [['is_published' => true, 'data_programmata' => null]],
]);

// --- P1: il testo ----------------------------------------------------------------------------------------

test('«Scegli la data e l\'ora di pubblicazione» sta nei file di lingua, nelle quattro lingue', function () {
    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        expect(Lang::hasForLocale('comunicazioni.scheduling.date_required', $lingua))->toBeTrue("Manca comunicazioni.scheduling.date_required in {$lingua}");
    }

    expect(__('comunicazioni.scheduling.date_required', [], 'it'))->toBe('Scegli la data e l\'ora di pubblicazione.');
    foreach (['en', 'es', 'pt'] as $lingua) {
        expect(__('comunicazioni.scheduling.date_required', [], $lingua))->not->toBe(__('comunicazioni.scheduling.date_required', [], 'it'));
    }
});
