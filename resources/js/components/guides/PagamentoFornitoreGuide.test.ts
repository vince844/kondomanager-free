// @vitest-environment jsdom

/**
 * La guida del pagamento a fornitore dice la regola delle voci della commissione come la applicano il modulo (`vociOfferte` in
 * `resources/js/lib/gestionale/pagamenti/voceCommissione.ts`) e il server (`VoceDellaCommissione::verifica()`): una voce di spesa della gestione delle
 * fatture, o di una gestione ordinaria dello stesso esercizio (Coda 553: la commissione dei lavori sulle spese bancarie dell'ordinaria); la proposta è la
 * voce dell'ultima commissione di quella gestione. Le guide sono solo in italiano.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PagamentoFornitoreGuide from './PagamentoFornitoreGuide.vue';

const passa = { template: '<div><slot /></div>' };

function testo(): string {
    const w = mount(PagamentoFornitoreGuide as never, {
        props: { open: true } as never,
        global: {
            stubs: {
                Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa,
                Tabs: passa, TabsList: passa, TabsTrigger: passa, TabsContent: passa,
            },
        },
    });
    const t = (w.text() ?? '').replace(/\s+/g, ' ');
    w.unmount();

    return t;
}

describe('la guida del pagamento a fornitore', () => {
    test('la commissione va su una voce di spesa della gestione delle fatture o di un\'ordinaria dello stesso esercizio, non solo della gestione delle fatture', () => {
        const t = testo();

        expect(t).toContain('La commissione va sulla voce di spesa che scegli: una voce della gestione delle fatture, o di una gestione ordinaria dello stesso esercizio');
        expect(t).toContain('per esempio le spese bancarie dell\'ordinaria, anche per i lavori di una straordinaria');
        expect(t).toContain('Il modulo propone la voce dell\'ultima commissione di quella gestione.');
        expect(t).not.toContain('della gestione delle fatture: entra nel consuntivo');
    });
});
