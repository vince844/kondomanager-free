// @vitest-environment jsdom

/**
 * «Scaduta», «Mostra scadute», «Paga scadute» e il pregresso nel modulo dell'incasso: li decide il server.
 *
 * Il server manda per ogni riga `scaduta` (il predicato comune: il giorno della scadenza non è scaduto, nel giorno
 * dell'amministratore), `is_emitted` (una quota della riga ha la scrittura di emissione) e `pregresso` (la rata 0). Il modulo li
 * usa e non li ricalcola: fino alla 1.12.0-beta.2 il caricatore riscriveva `scaduta` con una chiave che il server non manda
 * (`data_scadenza`), e il badge «Scaduta» non compariva mai; il pregresso si riconosceva dalla descrizione.
 *
 * Le fixture sono la forma vera della risposta (`SituazioneDebitoriaController`): `scadenza_human`, mai `data_scadenza`.
 * Il denaro qui è in euro decimali, come lo manda il server.
 */

import { describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

const server = vi.hoisted(() => ({ rate: [] as any[] }));

vi.mock('axios', () => ({
    default: { get: vi.fn(async () => ({ data: { rate: server.rate } })) },
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import IncassoRateNew from './IncassoRateNew.vue';

(globalThis as any).route = (name: string) => `/${name}`;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div />' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    'v-select': true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

const GESTIONE = { id: 4, nome: 'Ordinaria 2026', tipo: 'ordinaria' };

function riga(o: Record<string, unknown>) {
    const residuo = (o.residuo as number) ?? 100;
    return {
        rata_padre_id: o.id,
        importo_totale: Math.abs(residuo),
        gestione_id: GESTIONE.id,
        gestione: GESTIONE.nome,
        intestatario: 'Rossi Mario',
        unita: 'Int. 1',
        is_emitted: true,
        is_published: true,
        pregresso: false,
        scaduta: false,
        dettaglio_quote: [{ unita: 'Int. 1', anagrafica: 'Rossi Mario', anagrafica_id: 5, ruolo: 'P', residuo, is_credito: residuo < 0 }],
        ...o,
        residuo,
    };
}

async function apri(rate: any[]) {
    server.rate = rate;
    window.history.replaceState({}, '', '/gestionale/incassi/nuovo?prefill_anagrafica_id=5');
    const wrapper = mount(IncassoRateNew, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' },
            risorse: [{ id: 2, nome: 'Banca Intesa', tipo: 'banca' }],
            condomini: [{ id: 5, nome: 'Rossi Mario' }],
            immobili: [{ id: 1, interno: '1', descrizione: 'Piano terra' }],
            gestioni: [GESTIONE],
        },
        global: { stubs, mocks: { route: (name: string) => `/${name}` } },
    });
    await flushPromises();
    await flushPromises();

    return wrapper;
}

const rigaDi = (wrapper: any, descrizione: string) => wrapper.findAll('tbody tr').find((tr: any) => tr.text().includes(descrizione));

describe('«Scaduta» nel modulo dell\'incasso', () => {
    test('una rata emessa che il server dice scaduta porta il badge, anche se la data a video è di domani per il browser', async () => {
        const wrapper = await apri([
            riga({ id: 1, descrizione: 'Rata 1', scadenza_human: '14/12/2026', scaduta: true }),
            riga({ id: 2, descrizione: 'Rata 2', scadenza_human: '01/01/2020', scaduta: false }),
        ]);

        expect(rigaDi(wrapper, 'Rata 1').text()).toContain('Scaduta');
        // La data a video è passata, ma il server dice di no: decide il server.
        expect(rigaDi(wrapper, 'Rata 2').text()).not.toContain('Scaduta');
    });

    test('una rata non emessa con la data passata dice «No emissione», non «Scaduta»', async () => {
        const wrapper = await apri([riga({ id: 4, descrizione: 'Rata 4', scadenza_human: '10/12/2026', scaduta: true, is_emitted: false })]);

        const testo = rigaDi(wrapper, 'Rata 4').text();
        expect(testo).toContain('No emissione');
        expect(testo).not.toContain('Scaduta');
    });
});

describe('«Mostra scadute» e «Paga scadute» leggono `scaduta` del server', () => {
    const RIGHE = () => [
        // Una bozza scaduta (la sua data è passata): sta in testa all'ordine, e «Paga scadute» la prende.
        riga({ id: 4, descrizione: 'Rata 4', scadenza_human: '10/12/2026', scaduta: true, is_emitted: false, residuo: 100 }),
        riga({ id: 1, descrizione: 'Rata 1', scadenza_human: '14/12/2026', scaduta: true, residuo: 30 }),
        // Per il server non è scaduta, qualunque cosa dica la data: resta fuori.
        riga({ id: 2, descrizione: 'Rata 2', scadenza_human: '01/01/2020', scaduta: false, residuo: 50 }),
        // Né la descrizione né la rata 0 fanno una riga scaduta: la rata 0 non ancora scaduta resta fuori.
        riga({ id: 3, descrizione: 'Saldo lavori', scadenza_human: '05/03/2027', scaduta: false, residuo: 70 }),
        riga({ id: 10, descrizione: 'Pregresso', scadenza_human: '05/03/2027', scaduta: false, pregresso: true, residuo: 90 }),
    ];

    test('«Paga scadute» e «Mostra scadute» dicono che prendono anche le rate non ancora emesse con la scadenza passata', async () => {
        const wrapper = await apri(RIGHE());
        const pulsante = wrapper.findAll('button').find((b: any) => b.text() === 'Paga scadute');
        const etichetta = wrapper.find('label[for="toggle-scadute"]');

        expect(pulsante!.attributes('title')).toBe('Le rate con la scadenza passata, anche quelle non ancora emesse');
        expect(etichetta.attributes('title')).toBe('Le rate con la scadenza passata, anche quelle non ancora emesse');
    });

    test('«Paga scadute» somma le righe che il server dice scadute', async () => {
        const wrapper = await apri(RIGHE());
        const pulsante = wrapper.findAll('button').find((b: any) => b.text() === 'Paga scadute');
        await pulsante!.trigger('click');
        await flushPromises();

        expect(Number((wrapper.vm as any).form.importo_totale)).toBe(130);
    });

    test('«Mostra scadute» tiene le righe che il server dice scadute, e nessun\'altra', async () => {
        const wrapper = await apri(RIGHE());
        await wrapper.find('#toggle-scadute').setValue(true);
        await flushPromises();
        await flushPromises();

        const testi = wrapper.findAll('tbody tr').map((tr: any) => tr.text());
        expect(testi.some((t: string) => t.includes('Rata 4'))).toBe(true);
        expect(testi.some((t: string) => t.includes('Rata 1'))).toBe(true);
        expect(testi.some((t: string) => t.includes('Rata 2'))).toBe(false);
        expect(testi.some((t: string) => t.includes('Saldo lavori'))).toBe(false);
        expect(testi.some((t: string) => t.includes('Pregresso'))).toBe(false);
    });
});

describe('il pregresso è la rata 0, detto dal server', () => {
    test('la rata 0 a debito è «DEBITO PREGRESSO» qualunque sia la sua descrizione; una rata con «saldo» nella descrizione non lo è', async () => {
        const wrapper = await apri([
            riga({ id: 10, descrizione: 'Prima scadenza', scadenza_human: '05/01/2026', pregresso: true, residuo: 80 }),
            riga({ id: 11, descrizione: 'Saldo lavori', scadenza_human: '05/02/2026', residuo: 60 }),
        ]);

        expect(rigaDi(wrapper, 'Prima scadenza').text()).toContain('DEBITO PREGRESSO');
        expect(rigaDi(wrapper, 'Saldo lavori').text()).not.toContain('PREGRESSO');
    });

    test('la rata 0 a credito è «CREDITO PREGRESSO»; un credito su un\'altra rata è «CREDITO»', async () => {
        const wrapper = await apri([
            riga({ id: 10, descrizione: 'Prima scadenza', scadenza_human: '05/01/2026', pregresso: true, residuo: -40 }),
            riga({ id: 12, descrizione: 'Saldo iniziale strapagato', scadenza_human: '05/03/2026', residuo: -20 }),
        ]);

        expect(rigaDi(wrapper, 'Prima scadenza').text()).toContain('CREDITO PREGRESSO');
        const altro = rigaDi(wrapper, 'Saldo iniziale strapagato').text();
        expect(altro).toContain('CREDITO');
        expect(altro).not.toContain('PREGRESSO');
    });
});
