// @vitest-environment jsdom

/**
 * Il dettaglio di un incasso, sulla colonna «tipo» di ogni rata coperta (reperti S3 e S16 del rigiro della Fase 1-bis
 * della 1.11.0-beta.40).
 *
 * S3: la riga «Parte in più, sulla rata di …» era finita fra il `v-if` del contante e il suo `v-else`, e il `v-else` si
 * agganciava a lei: ogni rata pagata in contanti, in qualunque incasso, mostrava anche «Credito pregresso». Una
 * regressione su tutti gli incassi, nata da una riga aggiunta per la Coda 167.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import IncassoRateShow from './IncassoRateShow.vue';

(globalThis as any).route = (name: string) => `/${name}`;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div />' },
    Head: { template: '<span />' },
};

function apri(dettagli: any[]) {
    return mount(IncassoRateShow, {
        props: {
            condominio: { data: { id: 28, nome: 'Condominio Demo' } },
            esercizio: { id: 1 },
            condomini: [],
            incasso: { id: 7, numero_protocollo: 'INC-2026-00007', stato: 'registrata', causale: 'Bonifico', data_competenza: '2026-10-01', updated_at: '2026-10-01T10:00:00Z' },
            incassoFormatted: {
                dettagli_rate: dettagli,
                pagante: { principale: 'Ugo Venditore', altri_count: 0, lista_completa: 'Ugo Venditore', ruolo: 'Proprietario', versato_da: 'Elsa Acquirente' },
                cassa_nome: 'Banca', cassa_tipo_label: 'Conto corrente', importo_totale_formatted: '€ 350,00', gestione_nome: 'Ordinaria',
            },
            utenteCreatore: 'Admin',
            utenteStornatore: null,
        },
        global: { stubs, mocks: { route: (name: string) => `/${name}` } },
    });
}

/** La cella «tipo» (la quarta) della riga della rata n. */
function cellaTipo(wrapper: any, numero: number) {
    const riga = wrapper.findAll('tbody tr').find((tr: any) => tr.text().includes(`Rata ${numero}`));
    if (!riga) throw new Error(`La rata ${numero} non è in tabella.`);
    return riga.findAll('td')[3].text();
}

describe('la colonna del tipo, nel dettaglio di un incasso', () => {
    const rate = [
        { numero: 3, scadenza: '31/03/2026', immobile: 'Interno 3', importo_formatted: '€ 300,00', tipo: 'contanti', credito_di: null },
        { numero: 5, scadenza: '31/05/2026', immobile: 'Interno 3', importo_formatted: '€ 50,00', tipo: 'contanti', credito_di: 'Elsa Acquirente' },
        { numero: 1, scadenza: '31/01/2026', immobile: 'Interno 3', importo_formatted: '€ 100,00', tipo: 'credito', credito_di: null },
    ];

    test('una rata pagata in contanti non dice «Credito pregresso» (S3)', () => {
        const testo = cellaTipo(apri(rate), 3);

        expect(testo).toContain('Cash / Bonifico');
        expect(testo).not.toContain('Credito Pregresso');
    });

    test('la rata della parte in più dice su quale rata va, e non che è un credito pregresso (S3, S16)', () => {
        const testo = cellaTipo(apri(rate), 5);

        expect(testo).toContain('Cash / Bonifico');
        expect(testo).toContain('Parte in più, sulla rata di Elsa Acquirente');
        expect(testo).not.toContain('Credito Pregresso');
    });

    test('una rata coperta col credito resta «Credito pregresso»', () => {
        const testo = cellaTipo(apri(rate), 1);

        expect(testo).toContain('Credito Pregresso');
        expect(testo).not.toContain('Cash / Bonifico');
    });
});
