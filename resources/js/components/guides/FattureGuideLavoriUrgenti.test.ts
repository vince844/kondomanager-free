// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La guida delle fatture (`FattureGuide.vue`), «La competenza dei lavori urgenti» (Coda 688), una prova per frase:
 * - il modo «Lavori ordinati il …» c'è quando una voce della fattura sta in un piano d'urgenza: `CompetenzaFattura.vue` con `lavoriUrgenti`, dato da
 *   `FatturaRegisterNew.vue` e `FatturaRegisterEdit.vue` (`lavoriUrgentiDelleVoci`);
 * - la fattura nuova lo propone, e si cambia: `giornoProposto` in `FatturaRegisterNew.vue`;
 * - senza periodo il conguaglio dell'opera usa il giorno del piano: `DeliberaDellOpera::delibere()`, `fraseDellUrgenza()`;
 * - in un piano d'urgenza dalla scheda delle fatture, la fattura senza periodo prende il giorno del piano: `CalcoloQuoteService::calcolaDaFattureStraordinarie`.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const leggi = (f: string) => fs.readFileSync(f, 'utf8').replace(/\s+/g, ' ');
const guida = leggi('resources/js/components/guides/FattureGuide.vue');

describe('FattureGuide — la competenza dei lavori urgenti', () => {
    test('il modo nuovo, quando c\'è', () => {
        expect(guida).toContain('data-testid="guida-lavori-urgenti-fattura"');
        expect(guida).toContain('Se una voce della fattura sta in un piano d\'urgenza, la competenza ha un modo in più: «Lavori ordinati il …».');
    });
    test('la proposta, e si cambia', () => {
        expect(guida).toContain('Una fattura nuova lo propone con il giorno del piano; lo cambi se la fattura dice altro.');
    });
    test('senza periodo', () => {
        expect(guida).toContain('Senza periodo, il conguaglio dell\'opera usa il giorno del piano, come le rate.');
    });
    test('la fattura in un piano d\'urgenza dalla scheda delle fatture', () => {
        expect(guida).toContain('Messa a rate con «Urgenza» da «Spese fuori preventivo», la fattura senza periodo prende il giorno del piano; quella con il suo periodo tiene il suo.');
    });
    test('niente scritte sotto i 12 pixel nella sezione', () => {
        const sezione = guida.slice(guida.indexOf('data-testid="guida-lavori-urgenti-fattura"'));
        expect(sezione.slice(0, sezione.indexOf('</section>'))).not.toMatch(/text-\[(9|10|10\.5|11)px\]/);
    });
});
