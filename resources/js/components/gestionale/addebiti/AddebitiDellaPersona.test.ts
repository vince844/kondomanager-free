// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Gli addebiti di una persona nella pagina del suo estratto conto: l'elenco c'è solo con almeno un addebito, con il titolo e la frase; la causale, la
 * natura, l'unità, l'importo e il versato, la scadenza; «Annulla» solo per quelli senza un incasso, con la conferma che dice che cosa succede; la
 * richiesta va all'addebito giusto. «Nuovo addebito» e «Spese del ritardo» chiedono alla pagina le sue finestre: l'elenco non ne ha di sue.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri: Record<string, unknown> = {}) =>
    `/${nome}?${new URLSearchParams(Object.entries(parametri).map(([k, v]) => [k, String(v)])).toString()}`;

const Elenco = (await import('./AddebitiDellaPersona.vue')).default;

const addebito = (extra: Record<string, unknown> = {}) => ({ rata_id: 11, causale: 'Quota non assegnata: Pulizie, Int. U3, 31 giorni senza titolare', unita: 'Int. U3',
    importo: 5096, versato: 0, scadenza: '2026-12-20', natura: 'contributo', spesa_fatturata: false, piano: 'Addebiti individuali – Ordinaria 2026', annullabile: true, ...extra });

let w: VueWrapper | null = null;
const monta = (addebiti: unknown[]) => {
    w = mount(Elenco, { attachTo: document.body, props: { condominioId: 3, persona: { id: 4, nome: 'Verdi' }, addebiti: addebiti as never } });

    return w;
};
afterEach(() => { w?.unmount(); w = null; axiosPost.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();

describe('gli addebiti della persona', () => {
    test('una riga per addebito con la sua natura; «Annulla» solo per quelli che si annullano, e per gli altri il perché', () => {
        monta([addebito(), addebito({ rata_id: 12, causale: 'Spese di sollecito', unita: 'Int. U1', importo: 2500, versato: 1000, natura: 'accessorio', spesa_fatturata: true, annullabile: false,
            perche_resta: 'Questo addebito ha già un incasso, o un credito usato: storna prima l\'incasso dalla pagina degli incassi, poi annulla l\'addebito.' })]);
        const righe = [...document.body.querySelectorAll('[data-testid="addebito"]')];
        const pezzi = (r: Element) => [...r.children].map((c) => pulito(c.textContent));
        expect(pezzi(righe[0])).toEqual(['Quota non assegnata: Pulizie, Int. U3, 31 giorni senza titolareContributo · Int. U3 · Addebiti individuali – Ordinaria 2026',
            '€ 50,96', 'scade il 20/12/2026', 'Annulla']);
        expect(pezzi(righe[1])).toEqual(['Spese di sollecitoSpesa per il ritardo · Int. U1 · Addebiti individuali – Ordinaria 2026', '€ 25,00versati € 10,00',
            'scade il 20/12/2026', 'Questo addebito ha già un incasso, o un credito usato: storna prima l\'incasso dalla pagina degli incassi, poi annulla l\'addebito.']);
        expect(document.body.querySelectorAll('[data-testid="annulla-addebito"]')).toHaveLength(1);
    });

    test('senza addebiti l\'elenco non c\'è: né il titolo, né i pulsanti (stanno nella card e nel menu della pagina)', () => {
        monta([]);
        expect(document.body.querySelector('[data-testid="addebiti-della-persona"]')).toBeNull();
        expect(pulito(document.body.textContent)).toBe('');
    });

    test('con un addebito c\'è: «Addebiti personali», la frase, una riga; i due pulsanti chiedono alla pagina le sue finestre', async () => {
        const elenco = monta([addebito()]);
        const sezione = document.body.querySelector('[data-testid="addebiti-della-persona"]')!;
        expect(pulito(sezione.querySelector('h3')?.textContent)).toBe('Addebiti personali');
        expect(pulito(sezione.querySelector('[data-testid="frase-addebiti"]')?.textContent)).toBe('Somme chieste solo a questa persona, fuori dal riparto');
        expect(sezione.querySelectorAll('[data-testid="addebito"]')).toHaveLength(1);
        expect(pulito(sezione.querySelector('[data-testid="spese-del-ritardo"]')?.textContent)).toBe('Spese del ritardo');
        await elenco.get('[data-testid="nuovo-addebito"]').trigger('click');
        await elenco.get('[data-testid="spese-del-ritardo"]').trigger('click');
        expect(elenco.emitted('nuovo')).toHaveLength(1);
        expect(elenco.emitted('spese')).toHaveLength(1);
        // Le finestre sono della pagina: qui non ci sono.
        expect(elenco.findComponent({ name: 'NuovoAddebitoDialog' }).exists()).toBe(false);
        expect(elenco.findComponent({ name: 'AddebitaSpesaDialog' }).exists()).toBe(false);
    });

    test('«Annulla» chiede conferma, poi manda la richiesta per quell\'addebito', async () => {
        monta([addebito()]);
        (document.body.querySelector('[data-testid="annulla-addebito"]') as HTMLElement).click();
        await flushPromises();
        expect(pulito(document.body.textContent)).toContain('L\'addebito si toglie dal giornale e la sua scadenza non c\'è più.');
        axiosPost.mockResolvedValueOnce({ data: { messaggio: 'Addebito annullato: la sua scrittura è tolta dal giornale e la scadenza non c\'è più.' } });
        const conferma = [...document.body.querySelectorAll('button')].find((b) => pulito(b.textContent) === 'Annulla l\'addebito') as HTMLElement;
        conferma.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][0]).toBe('/gestionale.addebiti.annulla?condominio=3&addebito=11');
        expect(w!.emitted('fatto')).toHaveLength(1);
    });
});

describe('gli interessi e le spese per il ritardo', () => {
    test('la riga dice che cos\'è, la base e gli avvisi; «Spese del ritardo» chiede la sua finestra alla pagina', async () => {
        const interessi = { rata_id: 21, causale: 'Interessi di mora al 29/06/2026', unita: 'U1', importo: 118, versato: 0, scadenza: '2026-06-29', natura: 'accessorio',
            spesa_fatturata: false, piano: 'Addebiti individuali – Ordinaria 2026', annullabile: true, tipo: 'interessi', base: 'la legge, artt. 1224 e 1284 c.c.', avvisi: [] };
        const legali = { ...interessi, rata_id: 22, causale: 'Spese legali della causa', tipo: 'legali_giudiziali', base: 'nessuna',
            avvisi: ['Le spese della causa si addebitano con la condanna alle spese: controlla la base.'] };
        const elenco = mount(Elenco, { attachTo: document.body, props: { condominioId: 3, persona: { id: 1, nome: 'Neri' }, addebiti: [interessi, legali] } });
        const righe = elenco.findAll('[data-testid="addebito"]');
        expect(righe[0].text()).toContain('Interessi di mora · U1');
        expect(righe[0].get('[data-testid="base"]').text()).toBe('Base: la legge, artt. 1224 e 1284 c.c.');
        expect(righe[1].text()).toContain('Spesa per il ritardo · U1');
        expect(righe[1].get('[data-testid="avviso"]').text()).toBe('Le spese della causa si addebitano con la condanna alle spese: controlla la base.');
        await elenco.get('[data-testid="spese-del-ritardo"]').trigger('click');
        expect(elenco.emitted('spese')).toHaveLength(1);
        expect(elenco.emitted('nuovo')).toBeUndefined();
        elenco.unmount();
    });
});
