// @vitest-environment jsdom

/**
 * La pagina dell'incasso da terzi ha la sua guida: «Guida completa» nell'intestazione, come nella pagina dell'incasso delle rate, apre la guida nel
 * cassetto di lato. Le tre card della guida in alto restano.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import IncassoDaTerziNew from './IncassoDaTerziNew.vue';
import IncassoDaTerziGuide from '@/components/guides/IncassoDaTerziGuide.vue';

(globalThis as any).route = (nome: string) => `/${nome}`;

let pagina: VueWrapper | null = null;
afterEach(() => { pagina?.unmount(); pagina = null; });

function apri() {
    pagina = mount(IncassoDaTerziNew, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' }, condomini: [], esercizio: { id: 41, nome: 'Esercizio 2026' },
            voci: [{ id: 71, nome: 'Affitto antenna', label: 'Affitto antenna', preventivo: 24000 }], casse: [{ id: 5, nome: 'Banca principale', tipo: 'banca' }],
            anagrafiche: [], prossimo_protocollo: 'ENT-2026-00001', voce_scelta: null, da: 'incassi', link_piano_dei_conti: '/piano-dei-conti',
        } as never,
        global: {
            mocks: { route: (nome: string) => `/${nome}` },
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' },
                PageHeaderGuide: { name: 'PageHeaderGuide', props: ['guides'], template: '<div data-testid="intestazione"><slot name="actions" /></div>' },
                IncassoDaTerziGuide: { name: 'IncassoDaTerziGuide', props: ['open'], template: '<div data-testid="la-guida" :data-aperta="String(open)" />' },
                MoneyInput: { props: ['modelValue'], template: '<input :value="modelValue" />' },
            },
        },
    });

    return pagina;
}

describe('la guida dell\'incasso da terzi', () => {
    test('«Guida completa» sta nell\'intestazione, con l\'aspetto di «Guida» delle altre pagine, e apre la guida nel cassetto', async () => {
        const p = apri();
        const pulsante = p.get('[data-testid="intestazione"] [data-testid="guida-completa"]');
        expect(pulsante.text()).toBe('Guida completa');
        expect(pulsante.classes()).toEqual(expect.arrayContaining(['h-8', 'text-xs', 'font-bold', 'rounded-lg', 'bg-indigo-50', 'text-indigo-600']));
        expect(pulsante.classes()).not.toContain('h-9');
        expect(p.get('[data-testid="la-guida"]').attributes('data-aperta')).toBe('false');
        await pulsante.trigger('click');
        expect(p.get('[data-testid="la-guida"]').attributes('data-aperta')).toBe('true');
        expect(p.findComponent(IncassoDaTerziGuide).exists()).toBe(true);
    });

    test('le tre card della guida in alto restano', () => {
        const guide = apri().findComponent({ name: 'PageHeaderGuide' }).props('guides') as Array<{ title: string }>;
        expect(guide.map((g) => g.title)).toEqual(['Quando usarlo', 'Effetto contabile', 'Il registro']);
    });
});
