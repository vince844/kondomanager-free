// @vitest-environment jsdom

/**
 * La finestra dei movimenti di una voce di entrata incassata: la testa dice «Incassato € 240,00», e le righe e il totale lo dicono con lo stesso
 * segno, non come uno storno da −€ 240,00. Il server manda i movimenti con il segno del costo (dare meno avere: l'incasso è −24000); per una voce
 * che ha solo incassi la finestra li gira, e la nota parla di incassi, non di storni. Una voce di spesa resta com'era.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const MovimentiVoceDialog = (await import('./MovimentiVoceDialog.vue')).default;

const passa = { template: '<div><slot /></div>' };
const stubs = { Dialog: passa, DialogContent: passa, DialogHeader: passa, DialogTitle: passa, DialogDescription: passa, Link: { template: '<a><slot /></a>' } };

let finestra: VueWrapper | null = null;

async function apri(conto: Record<string, unknown>, movimenti: Array<Record<string, unknown>>, totale: number) {
    vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ movimenti, totale, troncato: false, limite: 200 }) })));
    finestra = mount(MovimentiVoceDialog, { props: { open: false, conto, condominioId: 1, esercizioId: 1 } as never, global: { stubs } });
    await finestra.setProps({ open: true });
    await flushPromises();

    return finestra.text().replace(/ /g, ' ');
}

afterEach(() => {
    finestra?.unmount();
    finestra = null;
    vi.unstubAllGlobals();
});

const incasso = { id: 1, scrittura_id: 10, data: '2026-02-15', causale: 'Affitto antenna 2026', descrizione: null, tipo_movimento: 'incasso_diverso',
    tipo_movimento_label: 'Incasso da terzi', controparte: 'Torri Spa', importo: -24000 };

describe('la finestra dei movimenti di una voce di entrata', () => {
    test('la testa, la riga e il totale dicono € 240,00 con lo stesso segno, e la testa si chiama «Incassato»', async () => {
        const testo = await apri({ id: 8, nome: 'Affitto antenna', tipo: 'entrata', budget_originale_raw: 24000, speso_raw: 0, incassato_raw: 24000 }, [incasso], -24000);

        expect(testo).toContain('Incassato');
        expect(testo).not.toContain('−€ 240,00');
        expect(testo).not.toContain('-€ 240,00');
        expect(finestra!.find('tfoot').text().replace(/ /g, ' ')).toContain('€ 240,00');
        expect(testo).not.toContain('Gli storni compaiono con importo negativo');
        expect(testo).toContain('Gli incassi compaiono in positivo');
    });

    test('controprova: una voce di spesa resta com\'era, con gli storni in negativo', async () => {
        const testo = await apri({ id: 7, nome: 'Pulizie', tipo: 'spesa', budget_originale_raw: 300000, speso_raw: 290000, incassato_raw: 0 },
            [{ ...incasso, tipo_movimento: 'storno_regolazione_immediata', tipo_movimento_label: 'Storno regolazione immediata', importo: -10000 }], 290000);

        expect(testo).toContain('Consuntivo');
        expect(testo).toContain('−€ 100,00');
        expect(testo).toContain('Gli storni compaiono con importo negativo');
    });
});
