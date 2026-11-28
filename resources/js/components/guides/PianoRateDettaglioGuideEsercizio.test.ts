// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della pagina del piano (`PianoRateDettaglioGuide.vue`), scheda «Stampe e altro»: il riquadro dell'esercizio con «Cambia»
 * (`PianoRateController::updateEsercizio`, solo senza rate emesse, incassi o conguaglio, e con il tetto sulle voci nell'esercizio di arrivo) e la riga dell'unità in usufrutto
 * (`UsufruttiConLOrdinariaAlNudo`), che non blocca.
 */
import { describe, expect, test } from 'vitest';
import { readFileSync } from 'node:fs';
import { mount } from '@vue/test-utils';

import PianoRateDettaglioGuide from './PianoRateDettaglioGuide.vue';

const passa = { template: '<div><slot /></div>' };

function sezione(id: string): string {
    const w = mount(PianoRateDettaglioGuide, {
        props: { open: true },
        global: { stubs: { Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa, Tabs: passa, TabsList: passa, TabsTrigger: passa, TabsContent: passa } },
    });

    return w.get(`[data-testid="${id}"]`).text().replace(/\s+/g, ' ');
}

describe('PianoRateDettaglioGuide — l\'esercizio del piano e l\'unità in usufrutto', () => {
    test('il riquadro in alto ha il nome che la pagina gli dà, «Esercizio del piano»', () => {
        expect(sezione('guida-esercizio-del-piano')).toContain('In alto il riquadro «Esercizio del piano» dice di quale anno è il piano: il suo elenco, lo speso e il già versato sono di quell\'anno.');
        expect(readFileSync('resources/js/pages/gestionale/pianiRate/PianiRateShow.vue', 'utf8')).toContain('Esercizio del piano: ');
    });

    test('«Cambia» sposta il piano fra gli esercizi aperti, solo senza rate emesse, incassi o conguaglio, e poi si ricalcola', () => {
        const testo = sezione('guida-esercizio-del-piano');

        expect(testo).toContain('si sposta con «Cambia», fra gli esercizi aperti della sua gestione.');
        expect(testo).toContain('Solo senza rate emesse, incassi o il conguaglio di un passaggio: gli altri si rifanno, e il riquadro lo dice.');
        expect(testo).toContain('il ricalcolo toglie il già versato dell\'esercizio nuovo.');
    });

    test('«Cambia» non porta il piano dove le sue voci sono già chieste per intero, e le scadenze restano quelle del piano', () => {
        const testo = sezione('guida-esercizio-del-piano');

        expect(testo).toContain('Non si sposta in un esercizio dove un altro piano chiede già le stesse voci per intero: il menu lo dice accanto all\'esercizio, con la voce e quanto resta.');
        expect(testo).toContain('Le scadenze delle rate restano quelle del piano: se servono altre date, rifallo.');
    });

    test('la riga dell\'usufrutto dice a chi vanno le voci e come darle all\'usufruttuario, senza bloccare', () => {
        const testo = sezione('guida-usufrutto-del-piano');

        expect(testo).toContain('in alto una riga dice che vanno al nudo proprietario.');
        expect(testo).toContain('mettile su «Usufruttuario» nella tabella della voce e ricalcola; se il piano non si ricalcola più, vale per i piani che verranno. La riga non blocca niente.');
        expect(testo).toContain('Per una voce che al passaggio hai tenuto sul «Proprietario», la riga dice che è la scelta di allora.');
    });
});
