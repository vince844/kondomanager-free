// @vitest-environment jsdom

import { beforeEach, describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';

const pagina = vi.hoisted(() => ({
    url: '/admin/allegati',
    props: {
        auth: {
            user: {
                id: 1,
                name: 'Amministratore',
                roles: ['amministratore'],
                permissions: [
                    'Accesso pannello amministratore',
                    'Visualizza allegati segnalazioni',
                    'Approva allegati segnalazioni',
                    'Elimina allegati segnalazioni',
                ],
            },
        },
        flash: {},
        allegati_in_attesa: 1,
    } as Record<string, unknown>,
}));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => pagina,
}));

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));

const chiamateRoute: Array<{ nome: string; parametri?: unknown }> = [];
const routeMock = (nome: string, parametri?: unknown) => {
    chiamateRoute.push({ nome, parametri });
    if (parametri && typeof parametri === 'object') {
        const qs = new URLSearchParams(
            Object.entries(parametri as Record<string, unknown>)
                .filter(([, v]) => v !== undefined && v !== null && v !== '')
                .map(([k, v]) => [k, String(v)])
        ).toString();
        return `/${nome.replace(/\./g, '/')}${qs ? '?' + qs : ''}`;
    }
    if (parametri !== undefined && parametri !== null) {
        return `/${nome.replace(/\./g, '/')}/${parametri}`;
    }
    return `/${nome.replace(/\./g, '/')}`;
};
(globalThis as unknown as { route: unknown }).route = routeMock;

const AllegatiList = (await import('./AllegatiList.vue')).default;

function montaPagina(filtriOverride = {}) {
    return mount(AllegatiList, {
        props: {
            allegati: {
                data: [
                    {
                        id: 10,
                        nome: 'preventivo.pdf',
                        tipo: 'segnalazioni' as const,
                        elemento: 'Perdita in cantina',
                        url_elemento: '/admin/segnalazioni/5',
                        condomini: ['Condominio Aurora'],
                        autore: 'Mario Rossi',
                        caricato_il: '2026-11-08T10:00:00.000Z',
                        stato: 'in_attesa' as const,
                        dimensione: 2048,
                        url_apri: '/admin/allegati/10/apri',
                        url_scarica: '/admin/allegati/10/scarica',
                        puo_approvare: true,
                        puo_eliminare: true,
                    },
                ],
                current_page: 1,
                last_page: 1,
                per_page: 10,
                total: 1,
                links: [],
            },
            filtri: {
                stato: '',
                tipo: '',
                condominio_id: null,
                autore: '',
                dal: '',
                al: '',
                ...filtriOverride,
            },
            condomini: [{ id: 1, nome: 'Condominio Aurora' }],
        },
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
            },
        },
    });
}

beforeEach(() => {
    vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
    vi.spyOn(router, 'get').mockImplementation(() => undefined as never);
    vi.mocked(router.post).mockClear();
    vi.mocked(router.get).mockClear();
});

describe('AllegatiList — pagina allegati del pannello (Coda 460)', () => {
    test('mostra i dati della riga, i collegamenti a scheda/apri/scarica e il collegamento allo ZIP con i filtri', () => {
        const wrapper = montaPagina({ condominio_id: 1, stato: 'in_attesa' });

        expect(wrapper.text()).toContain('preventivo.pdf');
        expect(wrapper.text()).toContain('Perdita in cantina');
        expect(wrapper.text()).toContain('Condominio Aurora');
        expect(wrapper.text()).toContain('Mario Rossi');
        expect(wrapper.text()).toContain('allegati.pending_badge');

        const linkZip = wrapper.find('a[href*="/admin/allegati/zip"]');
        expect(linkZip.exists()).toBe(true);
        expect(linkZip.attributes('href')).toContain('stato=in_attesa');
        expect(linkZip.attributes('href')).toContain('condominio_id=1');
        expect(chiamateRoute.some((c) => c.nome === 'admin.allegati.zip')).toBe(true);
    });

    test('le azioni di approvazione ed eliminazione usano le rotte nominate con route()', async () => {
        chiamateRoute.length = 0;
        vi.spyOn(router, 'delete').mockImplementation(() => undefined as never);
        const wrapper = montaPagina();
        const vm = wrapper.vm as unknown as {
            approvaSingolo: (riga: unknown) => void;
            applicaFiltri: () => void;
            cambiaPerPage: (n: number) => void;
        };

        const riga = {
            id: 10,
            nome: 'preventivo.pdf',
            tipo: 'segnalazioni' as const,
            elemento: 'Perdita in cantina',
            url_elemento: '/admin/segnalazioni/5',
            condomini: ['Condominio Aurora'],
            autore: 'Mario Rossi',
            caricato_il: '2026-11-08T10:00:00.000Z',
            stato: 'in_attesa' as const,
            dimensione: 2048,
            url_apri: '/admin/allegati/10/apri',
            url_scarica: '/admin/allegati/10/scarica',
            puo_approvare: true,
            puo_eliminare: true,
        };

        vm.approvaSingolo(riga);
        expect(vi.mocked(router.post)).toHaveBeenCalledWith(
            '/admin/allegati/approva/10',
            {},
            expect.objectContaining({ preserveScroll: true })
        );
        expect(chiamateRoute.some((c) => c.nome === 'admin.allegati.approva' && c.parametri === 10)).toBe(true);

        vm.applicaFiltri();
        expect(chiamateRoute.some((c) => c.nome === 'admin.allegati.index')).toBe(true);
    });
});

