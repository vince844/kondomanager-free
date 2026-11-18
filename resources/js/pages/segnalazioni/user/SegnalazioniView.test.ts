// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scheda di una segnalazione nel portale del condòmino: il riquadro «Chi la vede» (Code 306 e 198).
 *
 * La scheda la apre anche l'autore di una segnalazione non ancora pubblicata e approvata (Coda 263: la
 * propria in moderazione si apre per indirizzo). Lì il riquadro non può dire «la vedono i condòmini a cui
 * è indirizzata»: per ora non la vede nessun altro, a parte l'amministratore, e la vedranno quando sarà
 * pubblicata e approvata. Pubblicata e approvata, il riquadro segue `is_private`.
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave, e si legge la chiave.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['utente'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => false, hasRole: () => false }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const SegnalazioniView = (await import('./SegnalazioniView.vue')).default;
const ListaCommenti = (await import('@/components/commenti/ListaCommenti.vue')).default;
const { priorityConstants } = await import('@/lib/segnalazioni/constants');

function guidaChiLaVede(campi: Record<string, unknown>): string {
    const pagina = shallowMount(SegnalazioniView, {
        props: {
            segnalazione: {
                id: 9, subject: "Il cancello dell'ingresso «B»", description: "Non chiude\nda due giorni", priority: 'media',
                stato: 'aperta', is_private: false, is_published: true, is_approved: true, is_locked: false, created_at: 'ieri',
                can_comment: true, created_by: { user: { name: 'Mario Rossi' } },
                condominio: { option: { value: 3, label: 'Condominio Aurora' }, full: { nome: 'Condominio Aurora' } },
                commenti: [], commentiInAttesa: [],
                ...campi,
            },
            commenti_config: { can_comment: true, can_create: true, can_moderate: false, can_publish: true },
        } as never,
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
    const guide = pagina.findComponent(PageHeaderGuide).props('guides') as { title: string; description: string }[];
    const riquadro = guide.find((g) => g.title === 'segnalazioni.privacy.guide_title');
    expect(riquadro, 'il riquadro «Chi la vede» non c\'è').toBeDefined();

    return riquadro!.description;
}

describe('SegnalazioniView del portale — «Chi la vede» su una segnalazione non ancora pubblicata e approvata', () => {
    test.each([
        ['in moderazione', { is_published: false, is_approved: false }],
        ['scelta «Pubblicata» ma non approvata', { is_published: true, is_approved: false }],
        ['approvata e rimessa in bozza', { is_published: 0, is_approved: 1 }],
    ])('non privata, %s: per ora non la vede nessun altro, la vedranno quando sarà pubblicata e approvata', (_caso, stato) => {
        expect(guidaChiLaVede({ is_private: false, ...stato })).toBe('segnalazioni.privacy.guide_not_private_pending');
    });

    test('privata, in moderazione: lo stesso, con le persone a cui è indirizzata', () => {
        expect(guidaChiLaVede({ is_private: true, is_published: false, is_approved: false })).toBe('segnalazioni.privacy.guide_private_pending');
        expect(guidaChiLaVede({ is_private: 1, is_published: 1, is_approved: 0 })).toBe('segnalazioni.privacy.guide_private_pending');
    });
});

describe('SegnalazioniView del portale — «Chi la vede» su una segnalazione pubblicata e approvata', () => {
    test('segue `is_private`, come prima', () => {
        expect(guidaChiLaVede({ is_private: false })).toBe('segnalazioni.privacy.guide_not_private');
        expect(guidaChiLaVede({ is_private: true })).toBe('segnalazioni.privacy.guide_private');
        expect(guidaChiLaVede({ is_private: 0, is_published: 1, is_approved: 1 })).toBe('segnalazioni.privacy.guide_not_private');
    });
});

function montaPortale(campi: Record<string, unknown> = {}) {
    return shallowMount(SegnalazioniView, {
        props: {
            segnalazione: {
                id: 9, subject: 'Il cancello', description: 'Non chiude', priority: 'alta',
                stato: 'aperta', is_private: false, is_published: true, is_approved: true, is_locked: false, created_at: 'ieri',
                can_comment: true, created_by: { user: { name: 'Mario Rossi' } },
                condominio: { option: { value: 3, label: 'Condominio Aurora' }, full: { nome: 'Condominio Aurora' } },
                commenti: [], commentiInAttesa: [],
                ...campi,
            },
            commenti_config: { can_comment: true, can_create: true, can_moderate: false, can_publish: true },
        } as never,
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

// La scheda del portale come quella del pannello (decisioni 464 e 470): il condominio senza icona, le etichette a 12 px, l'icona della
// priorità accanto al titolo; niente riquadro «Interazioni», e «Bloccata» sotto i parametri solo quando lo è.
describe('SegnalazioniView del portale — i parametri come nel pannello (decisioni 464 e 470)', () => {
    test('il condominio è il nome, senza icona', () => {
        const condominio = montaPortale().get('[data-parametro="condominio"]');

        expect(condominio.element.children).toHaveLength(1);
        expect(condominio.text()).toContain('Condominio Aurora');
    });

    test('nessuna scritta sotto i 12 px', () => {
        expect(montaPortale().html()).not.toMatch(/text-\[(?:[0-9]|10|11)px\]/);
    });

    test('accanto al titolo l\'icona della priorità', () => {
        const p = priorityConstants.find((x) => x.value === 'alta')!;
        const icona = montaPortale().findAllComponents(p.icon as never).find((c) => c.attributes('data-icona-titolo') !== undefined);

        expect(icona).toBeDefined();
    });

    test('niente riquadro «Interazioni»; «Bloccata» solo quando lo è', () => {
        const aperta = montaPortale({ is_locked: false });
        expect(aperta.text()).not.toContain('segnalazioni.details.interactions');
        expect(aperta.text()).not.toContain('segnalazioni.details.unlocked');
        expect(aperta.find('[data-bloccata]').exists()).toBe(false);

        expect(montaPortale({ is_locked: true }).get('[data-bloccata]').text()).toContain('segnalazioni.details.locked');
    });
});

// Standard 16: le azioni sui commenti della scheda chiedono indietro solo la segnalazione, il messaggio e il numero dell'Inbox.
describe('SegnalazioniView — le azioni sui commenti con una visita parziale', () => {
    test('passa alla lista dei commenti le proprietà da rimandare', () => {
        expect(montaPortale().findComponent(ListaCommenti).props('ricarica')).toEqual(['segnalazione', 'flash', 'inbox_count']);
    });
});

// Decisione 470 (➕): senza «Interazioni» la card dei parametri non spinge più l'ultima riga in fondo, e l'ultima riga non ha la linea sotto.
describe('SegnalazioniView — la card dei parametri senza spazio vuoto', () => {
    test('le righe stanno in alto, e l\'ultima non ha la linea sotto', () => {
        const card = montaPortale().get('[data-card-parametri]');
        const righe = Array.from(card.element.children);

        expect(card.classes()).not.toContain('justify-between');
        expect(righe[righe.length - 1].className).not.toMatch(/\bborder-b\b/);
    });
});

