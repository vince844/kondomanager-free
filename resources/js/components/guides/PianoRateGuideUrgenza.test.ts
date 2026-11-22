// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della creazione del piano rate (`PianoRateGuide.vue`), i lavori urgenti su una gestione straordinaria (Coda 688), una prova per frase:
 * - la scelta sola, uguale sulle due schede: `LaDeliberaOLUrgenza.vue`, montato in `PianiRateNew.vue` per la gestione straordinaria;
 * - il motivo obbligatorio: `CreatePianoRateRequest`, `motivazione_autorizzazione` con «Urgenza» su una gestione straordinaria;
 * - «Lavori ordinati il …», di serie oggi, su tutte e due le schede: `giorno_dei_lavori` con `oggiLocale()` nel modulo, `campiDellUrgenza`; chi era titolare
 *   quel giorno paga tutto (`GiornoDellUrgenza`, che lo legge da `piani_rate.giorno_dei_lavori`);
 * - sulle fatture, la fattura senza periodo prende il giorno del piano: `CalcoloQuoteService::calcolaDaFattureStraordinarie`, con la competenza del piano
 *   (`CompetenzaDelPiano`); quella con il suo periodo tiene il suo (`RisolutoreCompetenza::perStraordinaria`, il primo gradino);
 * - nessuna divisione a giorni: un giorno solo, `dal` uguale ad `al` su ogni voce;
 * - il piano nasce approvato: `PianoRateController::store`, il ramo dell'urgenza sulle voci;
 * - la prima assemblea: `ProposteDellOrdineDelGiorno`, «Comunicazione dei lavori urgenti …»;
 * - la fattura registrata dopo propone il giorno, e senza periodo il conguaglio usa quello del piano: `FatturaRegisterNew.vue`, `DeliberaDellOpera`.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import PianoRateGuide from './PianoRateGuide.vue';

const passa = { template: '<div><slot /></div>' };
function testo(): string {
    const w = mount(PianoRateGuide, { props: { open: true }, global: { stubs: { Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa,
        Tabs: passa, TabsList: passa, TabsTrigger: passa, TabsContent: passa } } });
    return w.get('[data-testid="guida-lavori-urgenti"]').text().replace(/\s+/g, ' ');
}

describe('PianoRateGuide — i lavori urgenti su una gestione straordinaria', () => {
    test('la scelta è una sola, uguale sulle due schede', () => {
        expect(testo()).toContain('scegli «Urgenza (art. 1135 c.c.)»: la scelta è una sola, uguale sulle due schede.');
    });
    test('il motivo è obbligatorio', () => { expect(testo()).toContain('Il motivo dell\'urgenza è obbligatorio.'); });
    test('il giorno dei lavori, di serie oggi, e chi paga', () => {
        expect(testo()).toContain('Su tutte e due le schede scrivi «Lavori ordinati il …», di serie oggi: chi era titolare quel giorno paga tutto, anche se poi vende.');
    });
    test('sulle fatture: la fattura senza periodo prende il giorno, quella con il suo periodo tiene il suo', () => {
        expect(testo()).toContain('Sulle fatture, la fattura senza periodo prende il giorno del piano; quella con il suo periodo tiene il suo.');
    });
    test('nessuna divisione a giorni', () => { expect(testo()).toContain('Il giorno decide chi paga, non quanto: nessuna divisione a giorni.'); });
    test('il piano nasce approvato', () => { expect(testo()).toContain('Le rate si chiedono subito: il piano nasce approvato.'); });
    test('la prima assemblea', () => { expect(testo()).toContain('Lo riferisci alla prima assemblea: il punto ti viene proposto quando la prepari.'); });
    test('la fattura dopo e il conguaglio', () => {
        expect(testo()).toContain('La fattura che registri dopo sulla voce propone lo stesso giorno; senza periodo, il conguaglio dell\'opera usa il giorno del piano.');
    });
});
