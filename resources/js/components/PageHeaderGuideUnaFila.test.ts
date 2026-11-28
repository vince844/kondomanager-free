// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'intestazione delle pagine con `una-fila`: i pulsanti (il condominio, l'esercizio, «Guida», «Dashboard social») stanno su una riga che non va a capo, alti
 * uguali, e il nome di un condominio lungo si tronca con i puntini invece di spingere la fila. Da `xl` (lo schermo del portatile e del fisso, con la barra
 * laterale aperta): sotto, con la barra laterale aperta i quattro pulsanti non ci stanno, e la fila va a capo invece di far scorrere la pagina di lato. Senza
 * `una-fila` le altre pagine restano come sono.
 *
 * Con `una-fila-col-percorso` (solo la pagina del piano rate) la fila comprende il percorso: da `xl` non va a capo, nel percorso un nome lungo si accorcia
 * ma non sotto 9rem (per intero nel `title`), e «indietro» ha il testo solo se la fila ci sta per intero, altrimenti è la sola freccia con il `title`. Il
 * contenitore delle pagine è largo al massimo 80rem: sopra 1280 px la fila non si allarga, quindi lo spazio si misura e non si deduce dalla larghezza dello
 * schermo. La dashboard del condominio e le altre pagine non la chiedono e restano come sono.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, resolve } from 'node:path';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/admin/gestionale/7', props: {} }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n }) }));

const PageHeaderGuide = (await import('./PageHeaderGuide.vue')).default;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

const props = (altro: Record<string, unknown> = {}) => ({
    pageTitle: 'Dashboard gestionale', guides: [], hasTextGuide: true, textGuideTitle: 'Guida',
    condominio: { id: 7, nome: 'Condominio Residenza dei Giardini di Via Giuseppe Garibaldi 128' }, condomini: [], esercizio: { id: 1, nome: 'Esercizio 2026' }, esercizi: [],
    ...altro,
});
const fila = () => w!.get('[data-testid="pulsanti-intestazione"]');

describe('l\'intestazione con una fila sola', () => {
    test('con una-fila i pulsanti non vanno a capo, e il nome del condominio si tronca', () => {
        w = mount(PageHeaderGuide, { props: props({ unaFila: true }) as any });
        expect(fila().classes()).toContain('xl:flex-nowrap');
        const nome = w.get('[data-testid="nome-condominio"]');
        expect(nome.classes()).toEqual(expect.arrayContaining(['truncate']));
        expect(nome.attributes('title')).toBe('Condominio Residenza dei Giardini di Via Giuseppe Garibaldi 128');
        expect(w.text()).toContain('Guida');
    });

    test('senza una-fila le altre pagine restano come sono', () => {
        w = mount(PageHeaderGuide, { props: props() as any });
        expect(fila().classes()).toContain('flex-wrap');
        expect(fila().classes()).not.toContain('xl:flex-nowrap');
        expect(w.get('[data-testid="nome-condominio"]').classes()).not.toContain('truncate');
    });
});

describe('l\'intestazione della dashboard (decisione 574)', () => {
    test('con una-fila-sempre la fila non va a capo anche sotto xl, e il nome lungo si accorcia di più; il nome intero nel title', () => {
        w = mount(PageHeaderGuide, { props: props({ unaFila: true, unaFilaSempre: true }) as any });
        expect(fila().classes()).toEqual(expect.arrayContaining(['md:flex-nowrap']));
        const nome = w.get('[data-testid="nome-condominio"]');
        expect(nome.classes()).toEqual(expect.arrayContaining(['truncate', 'max-w-[11rem]', 'xl:max-w-[16rem]']));
        expect(nome.attributes('title')).toBe('Condominio Residenza dei Giardini di Via Giuseppe Garibaldi 128');
    });

    test('con esercizio-breve «Esercizio anno 2026» si legge «Esercizio 2026»; senza, il nome dell\'esercizio resta com\'è', () => {
        w = mount(PageHeaderGuide, { props: props({ unaFila: true, esercizioBreve: true, esercizio: { id: 1, nome: 'Esercizio anno 2026' } }) as any });
        expect(w.get('[data-testid="nome-esercizio"]').text()).toBe('Esercizio 2026');
        w.unmount();
        w = mount(PageHeaderGuide, { props: props({ unaFila: true, esercizio: { id: 1, nome: 'Esercizio anno 2026' } }) as any });
        expect(w.get('[data-testid="nome-esercizio"]').text()).toBe('Esercizio anno 2026');
    });
});

/** La pagina del piano: un percorso con un nome lungo, uno medio e voci corte, e «Torna alla lista». */
const PERCORSO = [
    { title: 'Gestionale', href: '/g' },
    { title: 'Condominio Via delle Acacie 12', href: '/c' },
    { title: 'Condominio Aurora', href: '/a' },
    { title: 'Piani rate', href: '/p' },
    { title: 'Dettaglio' },
];
const conPercorso = (altro: Record<string, unknown> = {}) => props({ breadcrumbs: PERCORSO, backUrl: '/lista', backText: 'Torna alla lista', ...altro });
const contenitore = () => fila().element.parentElement!.parentElement!;
const percorso = (testo: string) => w!.findAll('nav a, nav span').find((e) => e.text() === testo)!;
const indietro = () => w!.get('a[href="/lista"]');
const DEL_PIANO = { unaFila: true, unaFilaSempre: true, esercizioBreve: true, unaFilaColPercorso: true };

/**
 * Le larghezze, che jsdom non calcola: la riga larga `spazio`, ogni voce del percorso e ogni pulsante larghi quanto dice il loro testo (8 px a carattere),
 * le freccette e le icone 12 px. L'osservatore delle dimensioni è finto: `ridimensiona()` lo fa scattare.
 */
let spazio = 1232;
let osservatori: Array<() => void> = [];
const larghezzaFinta = (e: Element) => {
    if (e.tagName.toLowerCase() === 'svg') return 12;
    const testo = (e.textContent ?? '').trim();

    return testo.length * 8;
};
function preparaLeLarghezze() {
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) { return { width: larghezzaFinta(this) } as DOMRect; });
    Object.defineProperty(Element.prototype, 'scrollWidth', { configurable: true, get() { return larghezzaFinta(this as Element); } });
    Object.defineProperty(Element.prototype, 'clientWidth', { configurable: true, get() { return spazio; } });
    vi.stubGlobal('ResizeObserver', class { constructor(cb: () => void) { osservatori.push(cb); } observe() {} disconnect() {} });
}
async function ridimensiona(nuovo: number) {
    spazio = nuovo;
    osservatori.forEach((cb) => cb());
    await new Promise((r) => requestAnimationFrame(() => r(null)));
    await flushPromises();
}

describe('la fila unica con il percorso, della pagina del piano', () => {
    beforeEach(preparaLeLarghezze);
    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
        delete (Element.prototype as unknown as Record<string, unknown>).scrollWidth;
        delete (Element.prototype as unknown as Record<string, unknown>).clientWidth;
        osservatori = [];
        spazio = 1232;
    });

    test('da xl la fila non va a capo; nel percorso il nome lungo si accorcia, ma non sotto 9rem, con il nome intero nel title; gli altri restano interi', () => {
        w = mount(PageHeaderGuide, { props: conPercorso(DEL_PIANO) as any });

        expect(contenitore().classList.contains('xl:flex-nowrap')).toBe(true);
        // Anche i pulsanti vanno a capo sotto xl: su un tablet non fanno scorrere la pagina di lato.
        expect(fila().classes()).toContain('xl:flex-nowrap');
        expect(fila().classes()).not.toContain('md:flex-nowrap');
        // La colonna dei pulsanti non è mai più larga della riga: dentro vanno a capo.
        expect([...fila().element.parentElement!.classList]).toContain('md:max-w-full');
        const lungo = percorso('Condominio Via delle Acacie 12');
        expect(lungo.classes()).toEqual(expect.arrayContaining(['truncate', 'min-w-[9rem]']));
        expect(lungo.classes().filter((c) => c.includes('max-w') || c === 'min-w-0' || c.startsWith('2xl:'))).toEqual([]);
        expect(lungo.attributes('title')).toBe('Condominio Via delle Acacie 12');
        for (const intero of ['Gestionale', 'Condominio Aurora', 'Dettaglio']) {
            expect(percorso(intero).classes()).toContain('shrink-0');
            expect(percorso(intero).classes()).not.toContain('truncate');
        }
        // Perché la fila ci stia in 80rem con il nome del percorso a 9rem: il riquadro del percorso più stretto e il nome nel pulsante a 11rem.
        expect(w.get('nav').classes()).toEqual(expect.arrayContaining(['px-3', 'gap-1.5']));
        const nelPulsante = w.get('[data-testid="nome-condominio"]');
        expect(nelPulsante.classes()).toEqual(expect.arrayContaining(['max-w-[11rem]', 'truncate']));
        expect(nelPulsante.classes()).not.toContain('xl:max-w-[16rem]');
    });

    test('«Torna alla lista» si legge solo se la fila ci sta tutta; altrimenti è la sola freccia, con il testo per chi legge lo schermo e nel title', async () => {
        w = mount(PageHeaderGuide, { attachTo: document.body, props: conPercorso(DEL_PIANO) as any });
        await flushPromises();
        const testo = () => indietro().get('span');

        await ridimensiona(5000);
        expect(testo().classes()).not.toContain('md:sr-only');
        expect(indietro().attributes('title')).toBe('Torna alla lista');

        await ridimensiona(700);
        expect(testo().classes()).toContain('md:sr-only');
        expect(testo().text()).toBe('Torna alla lista');
        expect(indietro().attributes('title')).toBe('Torna alla lista');
        // Nessuna soglia legata allo schermo: sopra 1280 px la riga non si allarga.
        expect(testo().classes().filter((c) => c.startsWith('2xl:') || c.startsWith('xl:'))).toEqual([]);
    });

    test('la misura conta il nome per intero, anche quando è accorciato: lo spazio per il testo non si prende dal nome', async () => {
        w = mount(PageHeaderGuide, { attachTo: document.body, props: conPercorso(DEL_PIANO) as any });
        await flushPromises();
        // La larghezza che serve con il nome intero e il testo; un pixel in meno e il testo non c'è.
        const nav = [...w.element.querySelector('nav')!.children].reduce((s, e) => s + larghezzaFinta(e), 0);
        const link = indietro().element;
        const bottoni = [...fila().element.children].reduce((s, e) => s + (e === link ? 12 + larghezzaFinta(link.querySelector('span')!) : larghezzaFinta(e)), 0);

        await ridimensiona(nav + bottoni);
        expect(indietro().get('span').classes()).not.toContain('md:sr-only');
        await ridimensiona(nav + bottoni - 1);
        expect(indietro().get('span').classes()).toContain('md:sr-only');
    });

    test('sotto xl, quando la fila va a capo comunque, il testo si legge se ci stanno i pulsanti sulla loro riga', async () => {
        w = mount(PageHeaderGuide, { attachTo: document.body, props: conPercorso(DEL_PIANO) as any });
        await flushPromises();
        (contenitore() as HTMLElement).style.flexWrap = 'wrap';
        const link = indietro().element;
        const bottoni = [...fila().element.children].reduce((s, e) => s + (e === link ? 12 + larghezzaFinta(link.querySelector('span')!) : larghezzaFinta(e)), 0);

        await ridimensiona(bottoni);
        expect(indietro().get('span').classes()).not.toContain('md:sr-only');
        await ridimensiona(bottoni - 1);
        expect(indietro().get('span').classes()).toContain('md:sr-only');
    });

    test('se il testo farebbe andare a capo una fila che con la sola freccia sta su una riga, resta la freccia', async () => {
        w = mount(PageHeaderGuide, { attachTo: document.body, props: conPercorso(DEL_PIANO) as any });
        await flushPromises();
        (contenitore() as HTMLElement).style.flexWrap = 'wrap';
        const nav = [...w.element.querySelector('nav')!.children].reduce((s, e) => s + larghezzaFinta(e), 0);
        const link = indietro().element;
        const conLaFreccia = [...fila().element.children].reduce((s, e) => s + (e === link ? 12 : larghezzaFinta(e)), 0);

        await ridimensiona(nav + conLaFreccia + 1);

        expect(indietro().get('span').classes()).toContain('md:sr-only');
    });

    test.each([
        ['la dashboard del condominio', { unaFila: true, unaFilaSempre: true, esercizioBreve: true }],
        ['le pagine con una-fila', { unaFila: true }],
        ['le altre pagine', {}],
    ])('%s restano come prima: il percorso intero, «indietro» con il testo, la riga che va a capo', async (_chi, suoi) => {
        w = mount(PageHeaderGuide, { attachTo: document.body, props: conPercorso(suoi) as any });
        await flushPromises();
        await ridimensiona(100);

        expect(contenitore().classList.contains('md:flex-wrap')).toBe(true);
        expect(contenitore().classList.contains('xl:flex-nowrap')).toBe(false);
        for (const voce of PERCORSO) {
            const elemento = percorso(voce.title);
            expect(elemento.classes()).not.toContain('truncate');
            expect(elemento.classes()).not.toContain('shrink-0');
            expect(elemento.attributes('title')).toBeUndefined();
        }
        expect(indietro().attributes('title')).toBeUndefined();
        expect(indietro().get('span').classes().filter((c) => c.includes('sr-only'))).toEqual([]);
        expect(w.get('nav').classes()).toEqual(expect.arrayContaining(['px-4', 'gap-2']));
        expect([...fila().element.parentElement!.classList]).not.toContain('md:max-w-full');
        expect(osservatori).toEqual([]);
    });

    test('la chiede solo la pagina del piano; la dashboard del condominio no', () => {
        const radice = resolve(process.cwd(), 'resources/js');
        const vue = (cartella: string): string[] => readdirSync(cartella).flatMap((nome) => {
            const p = join(cartella, nome);

            return statSync(p).isDirectory() ? vue(p) : p.endsWith('.vue') ? [p] : [];
        });
        const chiedono = vue(radice).filter((f) => /\b(una-fila-col-percorso|:una-fila-col-percorso)\b/.test(readFileSync(f, 'utf8'))).map((f) => relative(radice, f));

        expect(chiedono).toEqual(['pages/gestionale/pianiRate/PianiRateShow.vue']);
    });
});
