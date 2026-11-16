// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * La finestra di «Ricordamelo» dice che cosa resta nell'agenda quando il promemoria è un evento a parte: la scadenza per i compiti che ne hanno una; per la
 * segnalazione e il bonifico segnalato, che una scadenza non hanno, il giorno da cui aspettano.
 */
import { describe, expect, test } from 'vitest';
import { fraseDelPromemoriaAParte } from './risposteDelCompito';

describe('la frase del promemoria a parte', () => {
    test('la segnalazione e il bonifico segnalato non parlano di una scadenza', () => {
        expect(fraseDelPromemoriaAParte('segnalazione_guasto')).toBe('La segnalazione resta aperta dal giorno in cui è arrivata: il promemoria è un evento a parte, nell\'agenda.');
        expect(fraseDelPromemoriaAParte('verifica_pagamento')).toBe('Il bonifico resta segnalato dal giorno in cui è arrivato: il promemoria è un evento a parte, nell\'agenda.');
        for (const tipo of ['segnalazione_guasto', 'verifica_pagamento']) expect(fraseDelPromemoriaAParte(tipo)).not.toContain('scadenza');
    });

    test('un compito con la scadenza la nomina', () => {
        expect(fraseDelPromemoriaAParte('pagamento_fornitore')).toContain('(la scadenza)');
    });
});
