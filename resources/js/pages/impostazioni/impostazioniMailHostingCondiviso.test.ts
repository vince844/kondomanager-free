// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 720 (decisione 578, 2) — la guida della posta non manda più chi è su un hosting condiviso a cercare un server di posta esterno: su Altervista
 * le porte 465 e 587 sono chiuse in uscita (provato il 19/11/2026). La frase è nella scheda di Sendmail della guida della pagina.
 */

import { readFileSync } from 'node:fs';
import { describe, expect, test } from 'vitest';

const FRASE = 'Su un hosting condiviso come Altervista i server di posta esterni sono bloccati: scarica i PDF e mandali dal tuo programma di posta.';
const pagina = readFileSync('resources/js/components/guides/MailSetupGuide.vue', 'utf8').replace(/\s+/g, ' ');

describe('Coda 720 — la scheda di Sendmail', () => {
    test('dice che su un hosting condiviso i server esterni sono bloccati e che si scaricano i PDF', () => {
        expect(pagina).toContain(FRASE);
    });

    test('non consiglia più «un server di posta» come strada per gli hosting condivisi', () => {
        expect(pagina).not.toContain('lì serve un server di posta');
    });
});
