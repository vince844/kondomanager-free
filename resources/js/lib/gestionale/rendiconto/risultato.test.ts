// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { describe, expect, test } from 'vitest';
import { comeRisultato } from './risultato';

describe('il risultato a parole (Coda 560)', () => {
    test('zero è in pareggio, sotto zero disavanzo, sopra zero avanzo', () => {
        expect(comeRisultato(0)).toBe('in pareggio');
        expect(comeRisultato(-1)).toBe('disavanzo');
        expect(comeRisultato(1)).toBe('avanzo');
    });
});
