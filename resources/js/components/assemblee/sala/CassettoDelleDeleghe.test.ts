// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il cassetto delle deleghe ricevute (la scena 2 della bozza delle card dei passi e del cassetto): «Vedi le deleghe» della card del passo «In sala» lo
 * apre da destra, sopra la pagina, che resta dov'era.
 *
 * - In testa «Deleghe ricevute» e la riga dell'assemblea; nel corpo il conto («1 valida · 1 rifiutata · 1 revocata») con «Segna una delega ricevuta» in
 *   alto, poi le valide, le rifiutate e le revocate, in gruppi con il titolo piccolo e grigio: chi delega, a chi, l'unità e i millesimi, ricevuta il, «Apri
 *   la scansione», «Revoca», il motivo; poi il conto del quinto; nel piede fisso «Chiudi».
 * - I pulsanti piccoli del modulo, alti 28 pixel con il testo a 12, come «Revoca». Al telefono il cassetto prende tutta la larghezza.
 * - «Revoca» e «Segna una delega ricevuta» fanno ciò che fanno nella sezione del passo: è lo stesso componente, `ElencoDelleDeleghe`.
 * - La catena: la revoca nel cassetto → la visita parziale rilegge la sala → la sezione del passo dice la stessa cosa del cassetto.
 *
 * Che cosa non copre: il server (`CateneDelleCardDellaSalaTest`); la pagina che apre il cassetto (`AssembleaConLaSala.test.ts`).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h, ref } from 'vue';
import type { SalaDellAssemblea } from '@/types/assemblee';
import { delega, salaDaAprire } from './salaDiProva';

const inertia = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router: inertia,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('vue-select', async () => {
    const { defineComponent: componente, h: crea } = await import('vue');
    return { default: componente({ name: 'VSelect', props: ['modelValue', 'options'], setup: () => () => crea('div', { class: 'v-select-finto' }) }) };
});
(globalThis as Record<string, unknown>).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${p?.assemblea ?? ''}/${p?.delegaRicevuta ?? ''}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Cassetto = (await import('./CassettoDelleDeleghe.vue')).default;
const Passo = (await import('./PassoInSala.vue')).default;

/** Prima del giorno: una delega valida con la scansione, una rifiutata con il motivo, una revocata. */
function sala(piu: Partial<SalaDellAssemblea> = {}): SalaDellAssemblea {
    const s = salaDaAprire();
    return {
        ...s,
        sedute: s.sedute.map((x) => ({ ...x, esito: null })),
        da_aprire: { seduta_id: 1, convocazione: 'prima', numero: 1, si_apre: false, perche: 'La sala si apre il giorno della seduta, il 14/12/2026.' },
        controllo: null,
        deleghe: [
            delega(1, 3, 2, 'Bianchi Giorgio', { unita: [107], ricevuta_il: '2026-12-04', scansione: '/deleghe/1/scansione' }),
            delega(5, 1, 2, 'Bianchi Giorgio', { stato: 'rifiutata', ricevuta_il: '2026-12-10', motivo: 'Con questa delega Bianchi Giorgio andrebbe oltre un quinto del valore.' }),
            delega(6, 4, 2, 'Bianchi Giorgio', { stato: 'revocata', ricevuta_il: '2026-12-05', motivo: 'Viene di persona.' }),
        ],
        ...piu,
    };
}

let w: VueWrapper | null = null;
beforeEach(() => { inertia.post.mockClear(); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const qs = (sel: string) => document.body.querySelector(sel) as HTMLElement | null;
const tutti = (sel: string) => [...document.body.querySelectorAll(sel)] as HTMLElement[];
const testo = (el: Element | null) => (el?.textContent ?? '').replace(/\s+/g, ' ').trim();

async function monta(piu: Record<string, unknown> = {}) {
    w = mount(Cassetto as never, { attachTo: document.body, props: { open: true, sala: sala(), puoGestire: true, condominioId: 3, assembleaId: 9, ...piu } as never });
    await flushPromises();

    return w;
}

describe('il cassetto delle deleghe', () => {
    test('chiuso non c\'è; aperto: il titolo, l\'assemblea, il conto con «Segna una delega ricevuta» in alto, le valide poi le rifiutate e le revocate', async () => {
        await monta({ open: false });
        expect(qs('[data-testid="cassetto-deleghe"]')).toBeNull();
        await w!.setProps({ open: true });
        await flushPromises();
        const cassetto = qs('[data-testid="cassetto-deleghe"]')!;
        // Da destra, largo circa 560 pixel; al telefono tutta la larghezza.
        expect(cassetto.className).toContain('w-full');
        expect(cassetto.className).toContain('sm:max-w-[560px]');
        expect(testo(qs('[data-testid="titolo-deleghe"]'))).toBe('Deleghe ricevute');
        expect(testo(qs('[data-testid="sottotitolo-deleghe"]'))).toBe('Assemblea ordinaria del 15/12/2026 · valgono per tutte le sue date');
        const elenco = cassetto.querySelector('[data-testid="elenco-deleghe"]')!;
        expect(elenco.getAttribute('data-forma')).toBe('cassetto');
        expect(testo(elenco.querySelector('[data-testid="riepilogo-deleghe"]'))).toBe('1 valida · 1 rifiutata · 1 revocata');
        // «Segna una delega ricevuta» in alto, prima dei gruppi.
        const segna = elenco.querySelector('[data-testid="segna-delega-ricevuta"]')!;
        expect(segna.compareDocumentPosition(elenco.querySelector('[data-testid="gruppo-deleghe"]')!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(tutti('[data-testid="cassetto-deleghe"] [data-testid="gruppo-deleghe"]').map(testo)).toEqual(['Valide', 'Rifiutate', 'Revocate']);
        expect(tutti('[data-testid="cassetto-deleghe"] [data-testid="delega-del-cassetto"]').map((d) => d.getAttribute('data-delega'))).toEqual(['1', '5', '6']);
    });

    test('ogni delega: chi delega e a chi, l\'unità e i millesimi, ricevuta il, «Apri la scansione», «Revoca», il motivo; poi il conto del quinto; «Chiudi»', async () => {
        await monta();
        const valida = qs('[data-testid="delega-del-cassetto"][data-delega="1"]')!;
        expect(testo(valida)).toContain('Costa Teresa a Bianchi Giorgio');
        expect(testo(valida)).toContain('Valida');
        expect(testo(valida)).toContain('Int. 7');
        expect(testo(valida)).toContain('ricevuta il 04/12');
        const scansione = valida.querySelector('[data-testid="apri-scansione"]')!;
        expect([testo(scansione), scansione.getAttribute('href'), scansione.getAttribute('target'), scansione.getAttribute('rel')])
            .toEqual(['Apri la scansione', '/deleghe/1/scansione', '_blank', 'noopener']);
        const revoca = valida.querySelector('[data-testid="revoca-delega"]')!;
        expect(testo(revoca)).toBe('Revoca');
        // I pulsanti piccoli del modulo: alti 28 pixel, il testo a 12, il bordo e il fondo bianco.
        for (const pulsante of [scansione, revoca, qs('[data-testid="cassetto-deleghe"] [data-testid="segna-delega-ricevuta"]')!, qs('[data-testid="chiudi-deleghe"]')!]) {
            expect(pulsante.className).toContain('h-7');
            expect(pulsante.className).toContain('text-xs');
        }
        const rifiutata = qs('[data-testid="delega-del-cassetto"][data-delega="5"]')!;
        expect(testo(rifiutata)).toContain('Rifiutata');
        expect(testo(rifiutata)).toContain('oltre un quinto del valore');
        expect(rifiutata.querySelector('[data-testid="revoca-delega"]')).toBeNull();
        expect(testo(qs('[data-testid="delega-del-cassetto"][data-delega="6"]'))).toContain('Viene di persona.');
        expect(testo(qs('[data-testid="cassetto-deleghe"] [data-testid="conto-dei-delegati"]'))).toContain('Bianchi Giorgio');
        // I badge dello stato sono rettangoli con gli angoli arrotondati, non pillole.
        expect(valida.querySelector('[data-testid="stato-delega"]')!.className).toContain('rounded-md');

        qs('[data-testid="chiudi-deleghe"]')!.click();
        await flushPromises();
        expect(w!.emitted('update:open')).toEqual([[false]]);
    });

    test('«Revoca» nel cassetto chiede il motivo e manda la revoca, che rilegge la sala e i passi; «Segna una delega ricevuta» apre la stessa finestra', async () => {
        await monta();
        qs('[data-testid="delega-del-cassetto"][data-delega="1"] [data-testid="revoca-delega"]')!.click();
        await flushPromises();
        const motivo = qs('[data-testid="motivo-revoca"]') as HTMLTextAreaElement;
        motivo.value = 'Viene di persona.';
        motivo.dispatchEvent(new Event('input'));
        await flushPromises();
        qs('[data-testid="conferma-revoca"]')!.click();
        await flushPromises();
        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, corpo, opzioni] = inertia.post.mock.calls[0];
        expect(url).toBe('/admin.gestionale.assemblee.deleghe.revoca/9/1');
        expect(corpo).toEqual({ motivo: 'Viene di persona.' });
        expect(opzioni).toMatchObject({ preserveScroll: true, only: ['sala', 'passi', 'flash'] });

        qs('[data-testid="cassetto-deleghe"] [data-testid="segna-delega-ricevuta"]')!.click();
        await flushPromises();
        expect(qs('[data-testid="finestra-delega-ricevuta"]')).not.toBeNull();
    });

    test('senza la sala dice «Un momento…»; chi non gestisce le assemblee non vede «Segna» né «Revoca»', async () => {
        await monta({ sala: undefined });
        expect(testo(qs('[data-testid="cassetto-deleghe"]'))).toContain('Un momento…');
        w!.unmount();
        document.body.innerHTML = '';
        await monta({ puoGestire: false });
        expect(qs('[data-testid="cassetto-deleghe"] [data-testid="segna-delega-ricevuta"]')).toBeNull();
        expect(qs('[data-testid="cassetto-deleghe"] [data-testid="revoca-delega"]')).toBeNull();
        expect(qs('[data-testid="cassetto-deleghe"] [data-testid="apri-scansione"]')).not.toBeNull();
    });

    test('la catena: la revoca nel cassetto → la visita parziale rilegge la sala → la sezione del passo dice la stessa cosa del cassetto', async () => {
        const viva = ref(sala({ deleghe: [delega(1, 3, 2, 'Bianchi Giorgio')] }));
        const Pagina = defineComponent({
            setup: () => () => h('div', [
                h(Passo, { sala: viva.value, apriLaSala: { attiva: false, perche: '' }, puoGestire: true, condominioId: 3, assembleaId: 9 }),
                h(Cassetto, { open: true, sala: viva.value, puoGestire: true, condominioId: 3, assembleaId: 9 }),
            ]),
        });
        w = mount(Pagina, { attachTo: document.body });
        await flushPromises();
        const sezione = () => qs('#deleghe-ricevute')!;
        const cassetto = () => qs('[data-testid="cassetto-deleghe"]')!;
        for (const vista of [sezione(), cassetto()]) {
            expect(testo(vista.querySelector('[data-delega="1"] [data-testid="stato-delega"]'))).toContain('Valida');
            expect(vista.querySelector('[data-delega="1"] [data-testid="revoca-delega"]')).not.toBeNull();
        }

        // La revoca dal cassetto, poi la risposta del server: la sala con la delega revocata.
        cassetto().querySelector<HTMLElement>('[data-delega="1"] [data-testid="revoca-delega"]')!.click();
        await flushPromises();
        const motivo = qs('[data-testid="motivo-revoca"]') as HTMLTextAreaElement;
        motivo.value = 'Viene di persona.';
        motivo.dispatchEvent(new Event('input'));
        await flushPromises();
        qs('[data-testid="conferma-revoca"]')!.click();
        await flushPromises();
        expect(inertia.post.mock.calls[0][2]).toMatchObject({ only: ['sala', 'passi', 'flash'] });
        viva.value = sala({ deleghe: [delega(1, 3, 2, 'Bianchi Giorgio', { stato: 'revocata', motivo: 'Viene di persona.' })] });
        await flushPromises();

        for (const vista of [sezione(), cassetto()]) {
            expect(testo(vista.querySelector('[data-delega="1"] [data-testid="stato-delega"]'))).toContain('Revocata');
            expect(testo(vista.querySelector('[data-delega="1"]'))).toContain('Viene di persona.');
            expect(vista.querySelector('[data-delega="1"] [data-testid="revoca-delega"]')).toBeNull();
            expect(testo(vista.querySelector('[data-testid="riepilogo-deleghe"]'))).toBe('0 valide · 1 revocata');
        }
    });

    test('niente scritte sotto i 12 pixel, il tema scuro, mai v-html, nessun bordo spesso a sinistra', async () => {
        const { readFileSync } = await import('node:fs');
        const { resolve } = await import('node:path');
        for (const file of ['CassettoDelleDeleghe.vue', 'ElencoDelleDeleghe.vue']) {
            const sorgente = readFileSync(resolve(process.cwd(), `resources/js/components/assemblee/sala/${file}`), 'utf8');
            expect(sorgente, file).not.toMatch(/text-\[(\d|1[01])px\]/);
            expect(sorgente, file).not.toContain('v-html');
            expect(sorgente, file).toContain('dark:');
            expect(sorgente, file).not.toMatch(/border-l-[2-8]/);
            expect(sorgente, file).toContain('SPDX-License-Identifier: AGPL-3.0-or-later');
        }
    });
});
