<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La fermata di chi era entrato con un passaggio già fermato (decisione 81.2), quando la fermata di prima è stata regolata dal Wallet con i saldi legati al
 * suo passaggio: la frase non dice più l'alternativa, nomina chi risponde.
 *
 * Lo scenario di `Beta53SaldoLegatoScenari::sl53Scenario`: la fine di Ines «Scrivi» il 1/7 (Ugo prende € 181,48), la vendita di Ugo a Elsa il 1/11 con la fermata
 * € 60,16 a carico di Ugo. L'amministratore la regola con le due righe legate alla vendita (`sl53DueLegate`: Ugo − 6016, Elsa + 6016). Poi Elsa vende a Carlo il
 * 1/12 (fermata € 30,57) o muore il 20/11 (successione a Dora con l'arretrato a nome della defunta e «Scrivi», fermata € 41,42). Fino alla 1.12.0-beta.14 il
 * pannello, il cancello e il registro dicevano «a carico di Ugo, o di Elsa se la fermata della vendita del 1 novembre 2026 è già stata regolata», anche con le
 * righe legate nel Wallet: il programma non le leggeva. Con le righe legate per la cifra della fermata, la parte è di Elsa; senza, resta com'era.
 *
 * Cosa NON copre: la pagina Vue; MySQL; le righe legate per una cifra diversa da quella della fermata (l'alternativa resta: il programma non indovina).
 */

use App\Models\Gestionale\Subentro;

require_once __DIR__ . '/Beta53SaldoLegatoScenari.php';
require_once __DIR__ . '/Support/ScenariFineLocazione.php';

beforeEach(function () {
    sl53Amministratore($this);
});

const FDRL_ALTERNATIVA = 'o di Elsa se la fermata della vendita del 1 novembre 2026 è già stata regolata';

/** La successione di Elsa a Dora il 20/11, con l'arretrato a nome della defunta e «Scrivi». */
function fdrlSuccessione(array &$s): array
{
    if (! isset($s['persone']['Dora'])) {
        fclPersona($s, 'Dora');
    }

    return ['tipo' => 'successione', 'tipologia' => 'proprietario', 'riga_uscente_id' => fds50Riga($s, 'Elsa', 'proprietario'), 'decorrenza' => '2026-11-20', 'quota' => 100,
        'eredi' => [['anagrafica_id' => $s['persone']['Dora']->id, 'quota' => 100]], 'arretrato' => 'defunto', 'conguaglio' => 'scrivi',
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 78', 'pertinenze' => []];
}

it('la seconda vendita dopo la fermata regolata con le righe legate: il pannello e il cancello dicono € 30,57 a carico di Elsa, senza l\'alternativa, e il registro lo scrive', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);
    $this->travel(1)->minutes();
    fclPersona($s, 'Carlo');
    $modulo = fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-12-01');

    $a = fds50Anteprima($this, $s, $modulo);
    $testi = implode(' | ', fds50Testi($a));

    expect($testi)->not->toContain(FDRL_ALTERNATIVA)
        ->and($a['cancello']['motivi'])->toContain('€ 30,57 dei giorni dopo l\'uscita di Ines restano a carico di Elsa (gestione Ordinaria 2026): il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Carlo con questo passaggio')
        ->and($testi)->toContain('Con i saldi del Wallet legati alla vendita del 1 novembre 2026 una parte, € 60,16, è passata a Elsa.')
        ->and($testi)->toContain('il programma non la sposta: € 30,57 restano a carico di Elsa.');

    $vendita = fds50Registra($this, $s, $modulo)['subentro'];
    $f = fds50Fermate($vendita)[0] ?? [];
    expect([$f['persona'] ?? null, (int) ($f['cifra'] ?? 0), $f['uscente_id'] ?? null, $f['strada'] ?? null])->toBe(['Elsa', 3057, null, 'legata']);
});

it('senza le righe legate la seconda vendita resta com\'era: a carico di Ugo, o di Elsa se la fermata è già stata regolata', function () {
    $s = sl53Scenario($this);
    fclPersona($s, 'Carlo');

    $a = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-12-01'));

    expect(implode(' | ', $a['cancello']['motivi']))->toContain('restano a carico di Ugo, ' . FDRL_ALTERNATIVA);
});

it('con una sola riga legata, per una cifra diversa dalla fermata, l\'alternativa resta', function () {
    $s = sl53Scenario($this);
    expect(sl53Errori(sl53Posta($this, $s, 'Elsa', 2000, (int) $s['vendita']->id)))->toBe([]);
    $this->travel(1)->minutes();
    fclPersona($s, 'Carlo');

    $a = fds50Anteprima($this, $s, fds50ModuloVendita($s, 'Elsa', 'Carlo', '2026-12-01'));

    expect(implode(' | ', $a['cancello']['motivi']))->toContain(FDRL_ALTERNATIVA);
});

it('la successione di Elsa dopo la fermata regolata: il motivo del cancello nomina Elsa, € 41,42, senza l\'alternativa', function () {
    $s = sl53Scenario($this);
    sl53DueLegate($this, $s);
    $this->travel(1)->minutes();

    $a = fds50Anteprima($this, $s, fdrlSuccessione($s));

    expect(implode(' | ', fds50Testi($a)))->not->toContain(FDRL_ALTERNATIVA)
        ->and($a['cancello']['motivi'])->toContain('€ 41,42 dei giorni dopo l\'uscita di Ines restano a carico di Elsa (gestione Ordinaria 2026): il conguaglio scritto alla fine locazione del 1 luglio 2026 non passa a Dora con questo passaggio');
});
