// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 647 (21/11/2026), punti 2 e 3, nella tabella dei convocati: ogni riga che si stampa o si manda ha la sua spunta (di serie tutte, «Tutti» e
 * «Nessuno» in testa); «Stampa le lettere (n)» e «Manda le PEC (n)» contano e mandano solo le righe spuntate del loro mezzo, con «(1 scelta)» quando non sono
 * tutte, e al server vanno gli id scelti (senza id, come prima: tutti); il menu del mezzo nella riga vale per questa assemblea e «Ricordalo nella sua scheda»
 * lo scrive anche nella scheda; la PEC si sceglie solo con una PEC; chi non gestisce non vede né spunte né menu.
 *
 * Che cosa non copre: il menu `v-select` vero aperto a mano (qui un segnaposto che emette la scelta); le regole del server (`MezzoNellaRigaTest`).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const inertia = vi.hoisted(() => ({ patch: vi.fn(), post: vi.fn(), reload: vi.fn() }));
const file = vi.hoisted(() => ({ chiesto: [] as unknown[] }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
vi.mock('axios', () => ({ default: { post: vi.fn(async (...a: unknown[]) => { file.chiesto.push(a); return { data: new Blob(['%PDF']), headers: {} }; }) } }));
vi.mock('@/lib/gestionale/incassi/ricevute', async (originale) => ({
    ...(await originale<typeof import('@/lib/gestionale/incassi/ricevute')>()),
    apriPdfInUnaScheda: vi.fn(async (chiedi: () => Promise<unknown>) => { await chiedi(); return { modo: 'scheda', nome: 'convocazioni.pdf' }; }),
}));
// Il menu `vue-select`: un segnaposto con le sue opzioni (e quali si possono scegliere), che emette la scelta come il vero.
vi.mock('vue-select', () => ({
    default: {
        name: 'VSelect',
        props: ['modelValue', 'options', 'selectable', 'placeholder'],
        emits: ['update:modelValue'],
        template: '<div class="v-select-finto" :data-valore="modelValue ?? \'\'"><span v-for="o in options" :key="o.valore" class="opzione" :data-valore="o.valore" '
            + ':data-sceglibile="String(selectable(o))" @click="selectable(o) && $emit(\'update:modelValue\', o.valore)">{{ o.etichetta }}</span></div>',
    },
}));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const { schedaDellaBozza } = await import('./viaRoma12');
const Scheda = (await import('./SchedaConvocazione.vue')).default;

let w: VueWrapper | null = null;
beforeEach(() => { inertia.post.mockReset().mockImplementation((_u: string, _d: unknown, o?: { onFinish?: () => void }) => o?.onFinish?.()); inertia.post.mockClear(); inertia.patch.mockClear(); inertia.reload.mockClear(); file.chiesto = []; });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function monta(scheda: Record<string, unknown> = schedaDellaBozza() as unknown as Record<string, unknown>) {
    w = mount(Scheda, { attachTo: document.body, props: { convocazione: scheda } });
    await flushPromises();

    return w;
}
const pulsante = (etichetta: string) => w!.findAll('button').find((b) => b.text().replace(/\s+/g, ' ').trim() === etichetta);
const spunte = () => w!.findAll('[data-testid="tabella-convocati"] [data-testid="spunta-riga"]');
const caselle = (r: { element: Element }) => r.element.querySelector('button[role="checkbox"], [role="checkbox"]') as HTMLElement;
const clic = async (el: Element | null | undefined) => { (el as HTMLElement).click(); await flushPromises(); };
async function tuttiTranne(chi: string[]) { // toglie la spunta alle righe date, per nome (con «Mostra tutti», perché le righe sono più di otto)
    const mostra = pulsante('Mostra tutti');
    if (mostra) await mostra.trigger('click');
    for (const r of w!.findAll('[data-testid="tabella-convocati"] [data-testid="riga-convocato"]')) {
        if (chi.some((n) => new RegExp(`${n}(?!\\d)`).test(r.text()))) await clic(r.find('[data-testid="spunta-riga"]').element);
    }
}

describe('le spunte', () => {
    test('di serie tutte spuntate, solo sulle righe che si stampano o si mandano; in testa «Stampa o manda a: 16 su 16», «Tutti» e «Nessuno»', async () => {
        await monta();
        expect(w!.get('[data-testid="spunte-quante"]').text()).toBe('16 su 16');
        // Le prime otto righe: le sette della scena (nessuna si stampa o si manda) e la prima delle altre; la spunta c'è solo dove serve.
        expect(spunte()).toHaveLength(1);
        expect(w!.findAll('[data-testid="spunta-riga"]').every((s) => s.attributes('data-state') === 'checked')).toBe(true);
        expect(pulsante('Stampa le lettere (5)')).toBeDefined();
        expect(pulsante('Manda le PEC (11)')).toBeDefined();
    });

    test('«Nessuno» le toglie tutte, anche quelle che non si vedono, e spegne i due pulsanti; «Tutti» le rimette', async () => {
        await monta();
        await clic(w!.get('[data-testid="spunte-nessuno"]').element);
        expect(w!.get('[data-testid="spunte-quante"]').text()).toBe('0 su 16');
        expect(pulsante('Stampa le lettere (0 scelte)')!.attributes('disabled')).toBeDefined();
        expect(pulsante('Manda le PEC (0 scelte)')!.attributes('disabled')).toBeDefined();
        await clic(w!.get('[data-testid="spunte-tutti"]').element);
        expect(w!.get('[data-testid="spunte-quante"]').text()).toBe('16 su 16');
        expect(pulsante('Stampa le lettere (5)')!.attributes('disabled')).toBeUndefined();
    });

    test('con una persona tolta dal conto il numero dice «(1 scelta)» o «(2 scelte)» e non più tutte; riaccesa, torna il numero', async () => {
        await monta();
        await tuttiTranne(['Persona 2', 'Persona 3', 'Persona 4', 'Persona 5']); // restano le lettere di Persona 1: una sola
        expect(pulsante('Stampa le lettere (1 scelta)')).toBeDefined();
        expect(pulsante('Manda le PEC (11)')).toBeDefined(); // le PEC non c'entrano con le lettere
    });
});

describe('«Stampa le lettere» e «Manda le PEC» agiscono solo sulle righe spuntate del loro mezzo', () => {
    test('con tutte spuntate non vanno id (il server fa come oggi); con alcune tolte vanno gli id delle spuntate, e solo del loro mezzo', async () => {
        await monta();
        await pulsante('Stampa le lettere (5)')!.trigger('click');
        await flushPromises();
        expect(file.chiesto.at(-1)).toEqual(['/a/1/convocazione/lettere', { formato: 'pdf' }, { responseType: 'blob' }]);

        await tuttiTranne(['Persona 1', 'Persona 2']); // due lettere tolte
        await pulsante('Stampa le lettere (3 scelte)')!.trigger('click');
        await flushPromises();
        expect(file.chiesto.at(-1)).toEqual(['/a/1/convocazione/lettere', { formato: 'pdf', anagrafiche: [102, 103, 104] }, { responseType: 'blob' }]);
    });

    test('le PEC vanno con gli id delle sole righe spuntate per PEC: togliere una lettera non cambia le PEC', async () => {
        await monta();
        await tuttiTranne(['Persona 1']); // una lettera tolta: le PEC restano undici e senza id
        await pulsante('Manda le PEC (11)')!.trigger('click');
        expect(inertia.post).toHaveBeenLastCalledWith('/a/1/convocazione/pec', {}, expect.objectContaining({ preserveScroll: true }));

        await tuttiTranne(['Persona 6']); // una PEC tolta
        await pulsante('Manda le PEC (10 scelte)')!.trigger('click');
        const ids = (inertia.post.mock.calls.at(-1)![1] as { anagrafiche: number[] }).anagrafiche;
        expect(ids).toHaveLength(10);
        expect(ids).not.toContain(105);
        expect(ids.every((id) => id >= 105 && id <= 115)).toBe(true);
    });
});

describe('il mezzo nella riga', () => {
    const primaRiga = () => w!.findAll('[data-testid="tabella-convocati"] [data-testid="mezzo-della-riga"]')[0];

    test('il menu vale per questa assemblea: PATCH con la persona e il mezzo, senza «ricorda»; la PEC non si sceglie senza PEC', async () => {
        await monta();
        const menu = primaRiga();
        expect(menu.find('.opzione[data-valore="pec"]').attributes('data-sceglibile')).toBe('false'); // Persona 1 non ha una PEC
        expect(menu.find('.opzione[data-valore="a_mano"]').attributes('data-sceglibile')).toBe('true');
        await clic(menu.find('.opzione[data-valore="pec"]').element);
        expect(inertia.patch).not.toHaveBeenCalled();
        await clic(menu.find('.opzione[data-valore="a_mano"]').element);
        expect(inertia.patch).toHaveBeenLastCalledWith('/a/1/convocazione/mezzo', { anagrafica_id: 100, mezzo: 'a_mano', ricorda: false },
            expect.objectContaining({ preserveScroll: true, preserveState: true, only: ['convocazione', 'passi', 'diario', 'assemblea', 'proposte'] }));
    });

    test('«Ricordalo nella sua scheda» si offre sotto il menu e manda lo stesso mezzo con «ricorda»', async () => {
        await monta();
        const casella = primaRiga().find('[data-testid="ricordalo"]');
        expect(primaRiga().text()).toContain('Ricordalo nella sua scheda');
        await clic(casella.element);
        expect(inertia.patch).toHaveBeenLastCalledWith('/a/1/convocazione/mezzo', { anagrafica_id: 100, mezzo: 'raccomandata', ricorda: true }, expect.anything());
    });

    test('un errore del server (la PEC senza PEC, l\'avviso già spedito) si dice sotto la tabella', async () => {
        await monta();
        await clic(primaRiga().find('.opzione[data-valore="a_mano"]').element);
        const opzioni = inertia.patch.mock.calls.at(-1)![2] as { onError: (e: Record<string, string>) => void };
        opzioni.onError({ mezzo: 'L\'avviso di questa persona è già spedito: il mezzo non si cambia.' });
        await flushPromises();
        expect(w!.get('[data-testid="problema-scheda"]').text()).toBe('L\'avviso di questa persona è già spedito: il mezzo non si cambia.');
    });

    test('chi non gestisce le assemblee non vede né le spunte né il menu', async () => {
        await monta(schedaDellaBozza({ gestisce: false }) as never);
        expect(w!.find('[data-testid="spunte-in-testa"]').exists()).toBe(false);
        expect(w!.find('[data-testid="mezzo-della-riga"]').exists()).toBe(false);
        expect(w!.find('[data-testid="spunta-riga"]').exists()).toBe(false);
    });

    test('al telefono la spunta sta a sinistra e il menu del mezzo sotto il nome', async () => {
        await monta();
        const card = w!.findAll('[data-testid="card-convocati"] > li').find((li) => li.text().includes('Persona 1'))!;
        const spunta = card.get('[data-testid="spunta-card"]').element;
        const nome = card.get('p').element;
        const menu = card.get('[data-testid="mezzo-della-card"]').element;
        expect(spunta.compareDocumentPosition(nome) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(nome.compareDocumentPosition(menu) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });
});

describe('la colonna «Prova» (decisione 649)', () => {
    test('senza prova dice «Nessuna, per ora», non la lineetta; una PEC spedita senza ricevuta dice ancora «trascina qui la ricevuta»', async () => {
        const scheda = schedaDellaBozza() as unknown as Record<string, any>;
        scheda.convocati.righe[7].azioni = { ...scheda.convocati.righe[7].azioni, ricevuta: true };
        await monta(scheda);
        const tabella = w!.get('[data-testid="tabella-convocati"]');
        expect(tabella.findAll('[data-testid="nessuna-prova"]').every((x) => x.text() === 'Nessuna, per ora')).toBe(true);
        expect(tabella.findAll('[data-testid="nessuna-prova"]').length).toBeGreaterThan(0);
        expect(tabella.text()).not.toContain('—');
        expect(tabella.get('[data-testid="trascina-ricevuta"]').text()).toBe('trascina qui la ricevuta');
    });

    test('il menu «⋯» sta in una cella sua, l\'ultima, senza intestazione, e non dentro la cella della prova', async () => {
        await monta();
        const riga = w!.findAll('[data-testid="tabella-convocati"] [data-testid="riga-convocato"]').find((r) => r.find('[data-testid="menu-riga"]').exists())!;
        const celle = riga.findAll('td');
        expect(celle.at(-1)!.find('[data-testid="menu-riga"]').exists()).toBe(true);
        expect(celle.at(-2)!.find('[data-testid="menu-riga"]').exists()).toBe(false);
        const intestazioni = w!.findAll('[data-testid="tabella-convocati"] thead th');
        expect(intestazioni.at(-1)!.find('.sr-only').exists()).toBe(true);
    });
});
