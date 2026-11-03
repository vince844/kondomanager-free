// @vitest-environment jsdom

/**
 * La modifica di una titolarità (`gestionale/immobili/anagrafiche/AnagraficheEdit.vue`) con la persona come la manda il
 * server: della rubrica l'id e il nome, della riga l'id della persona, il nome e il legame con l'unità (`pivot`), Coda 408.
 * Aperta e salvata senza toccare niente, il modulo manda alla riga dell'indirizzo gli stessi dati che ha ricevuto.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const inviato = vi.hoisted(() => ({ put: vi.fn() }));

vi.mock('@inertiajs/vue3', async (importOriginal) => {
    const originale = await importOriginal<typeof import('@inertiajs/vue3')>();

    return {
        ...originale,
        Head: { render: () => null },
        usePage: () => ({ url: '/admin/gestionale/18/immobili/24/anagrafiche/7/edit', props: { flash: {}, auth: { user: { roles: ['collaboratore'], permissions: [] } } } }),
        useForm: ((...args: unknown[]) => {
            const form = (originale.useForm as (...a: unknown[]) => Record<string, unknown>)(...args);
            form.put = (url: string) => inviato.put(url, (form.data as () => Record<string, unknown>)());

            return form;
        }) as unknown as typeof originale.useForm,
    };
});
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['options', 'modelValue'], template: '<div data-tendina>{{ (options || []).find(o => o.id === modelValue)?.nome }}</div>' } }));

(globalThis as Record<string, unknown>).route = (nome: string, parametri: Record<string, unknown> = {}) =>
    `/${nome}?${new URLSearchParams(parametri as Record<string, string>).toString()}`;

const AnagraficheEdit = (await import('./AnagraficheEdit.vue')).default;

const passa = { template: '<div><slot /></div>' };

describe('la modifica di una titolarità con la persona ridotta', () => {
    test('aperta e salvata senza toccare niente, manda alla riga dell\'indirizzo i dati ricevuti', async () => {
        const riga = {
            id: 7, tipologia: 'proprietario', quota: '33.333', data_inizio: '2020-01-01', data_fine: null, attivo: true,
            note: "Quota dell'erede «Mario» & C. <da verificare>\nseconda riga",
        };
        const pagina = mount(AnagraficheEdit, {
            props: {
                condominio: { id: 18, nome: 'Condominio Aurora' } as never,
                immobile: { id: 24, nome: 'Interno 3', interno: '3' } as never,
                anagrafiche: [{ id: 41, nome: "Mario D'Àngelo" }, { id: 42, nome: 'Anna Verdi' }] as never,
                anagrafica: { id: 41, nome: "Mario D'Àngelo", pivot: riga } as never,
            },
            global: {
                stubs: {
                    GestionaleLayout: passa, ImmobileLayout: passa, PageHeaderGuide: true,
                    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
                    VueDatePicker: true, HoverCard: passa, HoverCardTrigger: passa, HoverCardContent: passa,
                },
            },
        });

        // La tendina bloccata mostra la persona della riga per nome.
        expect(pagina.find('[data-tendina]').text()).toBe("Mario D'Àngelo");

        await pagina.find('form').trigger('submit');

        expect(inviato.put).toHaveBeenCalledTimes(1);
        const [url, corpo] = inviato.put.mock.calls[0];
        expect(url).toBe('/admin.gestionale.immobili.anagrafiche.update?condominio=18&immobile=24&titolarita=7');
        expect(corpo).toEqual({
            tipologia: 'proprietario', data_inizio: '2020-01-01', data_fine: '', quota: '33.333',
            note: "Quota dell'erede «Mario» & C. <da verificare>\nseconda riga", anagrafica_id: 41,
        });
    });
});
