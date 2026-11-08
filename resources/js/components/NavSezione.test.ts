// @vitest-environment jsdom

/**
 * La barra di una sezione: con `unaRiga` (il menu dei Movimenti) da `md` in su le voci stanno su una riga che non va a capo e, se non ci stanno,
 * scorre di lato; una voce con il nome corto lo mostra nella riga e porta il nome intero al passaggio del mouse; sotto `md`, nella tendina, il nome
 * intero. Senza `unaRiga` la barra è quella di sempre: va a capo, nessun nome corto.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], setup: (p: { href: string }, { slots, attrs }: any) => () => h('a', { href: p.href, ...attrs }, slots.default?.()) } }));

const NavSezione = (await import('./NavSezione.vue')).default;
const Icona = { render: () => h('svg', { 'data-icona': '1' }) };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

const voci = [
    { icon: Icona, title: 'Incassi rate', breve: 'Incassi', href: '/admin/gestionale/7/movimenti-rate' },
    { icon: Icona, title: 'Rate scadute', href: '/admin/gestionale/7/rate-scadute' },
];

describe('la barra su una riga', () => {
    test('da md in su non va a capo e scorre di lato, con meno spazio fra le voci', () => {
        w = mount(NavSezione, { props: { items: voci, unaRiga: true } });
        const nav = w.get('nav').classes();
        expect(nav).toContain('md:flex-nowrap');
        expect(nav).toContain('md:overflow-x-auto');
        expect(nav).not.toContain('md:flex-wrap');
        expect(nav).toContain('md:gap-1');
        // Meno spazio anche dentro le voci: il pulsante più stretto, l'icona senza il margine in più.
        const voce = w.get('nav a');
        // Il pulsante con l'icona ha il suo margine (`has-[>svg]:px-3`): anche quello si stringe.
        expect(voce.classes()).toContain('md:has-[>svg]:px-2');
        expect(voce.get('[data-icona]').classes()).not.toContain('mr-1');
    });

    test('la voce con il nome corto: corto nella riga, intero nella tendina e al passaggio del mouse; le icone restano', () => {
        w = mount(NavSezione, { props: { items: voci, unaRiga: true } });
        const [incassi, scadute] = w.findAll('nav a');
        expect(incassi.attributes('title')).toBe('Incassi rate');
        expect(incassi.get('.hidden.md\\:inline').text()).toBe('Incassi');
        expect(incassi.get('.md\\:hidden').text()).toBe('Incassi rate');
        expect(incassi.find('[data-icona]').exists()).toBe(true);
        // Una voce senza nome corto non ha il nome due volte né un title.
        expect(scadute.attributes('title')).toBeUndefined();
        expect(scadute.text()).toBe('Rate scadute');
    });
});

describe('le altre barre non cambiano', () => {
    test('senza «una riga» la barra va a capo come prima', () => {
        w = mount(NavSezione, { props: { items: [{ title: 'Utenti', href: '/utenti' }, { title: 'Ruoli', href: '/ruoli' }] } });
        const nav = w.get('nav').classes();
        expect(nav).toContain('md:flex-wrap');
        expect(nav).not.toContain('md:flex-nowrap');
        expect(nav).not.toContain('md:overflow-x-auto');
        expect(nav).toContain('gap-2');
        expect(w.findAll('nav a').map((a) => a.attributes('title'))).toEqual([undefined, undefined]);
        expect(w.get('nav a').classes()).not.toContain('md:px-2');
    });
});

describe('una voce accesa anche su un altro indirizzo', () => {
    test('«anche»: la voce si accende sul suo indirizzo, sotto di lui e sugli indirizzi in più; non altrove', () => {
        const rendiconto = [{ title: 'Libro Giornale', href: '/g/7/esercizi/3/scritture' },
            { title: 'Rendiconto', href: '/g/7/esercizi/3/rendiconto', anche: ['/g/7/esercizi/3/stato-patrimoniale'] }];
        const accesa = (percorso: string) => {
            window.history.pushState({}, '', percorso);
            w?.unmount();
            w = mount(NavSezione, { props: { items: rendiconto, unaRiga: true } });
            return w.findAll('nav a').filter((a) => a.classes().includes('bg-muted')).map((a) => a.text());
        };
        expect(accesa('/g/7/esercizi/3/rendiconto')).toEqual(['Rendiconto']);
        expect(accesa('/g/7/esercizi/3/rendiconto/controlli')).toEqual(['Rendiconto']);
        expect(accesa('/g/7/esercizi/3/stato-patrimoniale')).toEqual(['Rendiconto']);
        expect(accesa('/g/7/esercizi/3/stato-patrimoniale-altro')).toEqual([]);
        expect(accesa('/g/7/esercizi/3/scritture')).toEqual(['Libro Giornale']);
        window.history.pushState({}, '', '/');
    });
});
