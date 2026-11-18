// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scheda di una segnalazione nel pannello: la conferma di «Rendi pubblica» (Coda 304).
 *
 * «Rendi pubblica» toglie la privacy, non approva e non pubblica (decisione 127.2). La conferma dice
 * «la vedranno tutti i condòmini di <condominio>, con il nome di <autore>» (decisione 129.2) solo se la
 * segnalazione è già pubblicata e approvata; su una privata in moderazione o in bozza dice che la
 * vedranno quando sarà pubblicata e approvata. La stessa regola della mail all'autore e del messaggio
 * dopo il comando (`tests/Feature/Portale/RendiPubblicaInModerazioneTest.php`).
 *
 * Le traduzioni non sono caricate: `trans()` restituisce la chiave, e si legge la chiave.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import ConfirmDialog from '@/components/ConfirmDialog.vue';

const permessi = vi.hoisted(() => ({ puoApprovare: true }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => permessi.puoApprovare, hasRole: () => true }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const SegnalazioniView = (await import('./SegnalazioniView.vue')).default;
const ListaCommenti = (await import('@/components/commenti/ListaCommenti.vue')).default;
const { priorityConstants } = await import('@/lib/segnalazioni/constants');

function monta(campi: Record<string, unknown>, altreProps: Record<string, unknown> = {}) {
    return shallowMount(SegnalazioniView, {
        props: {
            ...altreProps,
            segnalazione: {
                id: 9, subject: 'Muffa in camera', description: 'Riguarda il mio appartamento', priority: 'alta', stato: 'aperta',
                is_private: true, is_locked: false, created_at: 'ieri',
                created_by: { user: { name: 'mrossi' }, anagrafica: { nome: 'Mario Rossi' } },
                condominio: { option: { value: 3, label: 'Condominio Aurora' }, full: { nome: 'Condominio Aurora' } },
                anagrafiche: [], commenti: [], commentiInAttesa: [],
                ...campi,
            },
            commenti_config: { can_comment: true, can_create: true, can_moderate: true, can_publish: true },
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

const descrizione = (campi: Record<string, unknown>, altreProps: Record<string, unknown> = {}) =>
    monta(campi, altreProps).findComponent(ConfirmDialog).props('description');

describe('SegnalazioniView — la conferma di «Rendi pubblica»', () => {
    test('su una privata in moderazione dice che la vedranno quando sarà pubblicata e approvata', () => {
        expect(descrizione({ is_published: false, is_approved: false })).toBe('segnalazioni.privacy.make_public_description_pending');
    });

    test('su una privata approvata e rimessa in bozza dice lo stesso', () => {
        expect(descrizione({ is_published: 0, is_approved: 1 })).toBe('segnalazioni.privacy.make_public_description_pending');
    });

    test('su una privata pubblicata e approvata dice che la vedranno tutti', () => {
        expect(descrizione({ is_published: true, is_approved: true })).toBe('segnalazioni.privacy.make_public_description');
    });
});

describe('SegnalazioniView — la conferma dice quando l\'autore non la vedrà più (Coda 304)', () => {
    // Il fatto lo calcola il server (`rendi_pubblica.autore_perde_la_scheda`, provato dalle rotte in
    // `tests/Feature/Portale/AvvisiAllAutoreCheNonLaApreTest.php`): la pagina aggiunge la frase.
    test('con l\'autore fuori dal palazzo la conferma aggiunge che dopo non la vedrà più dal portale', () => {
        expect(descrizione({ is_published: true, is_approved: true }, { rendi_pubblica: { autore_perde_la_scheda: true } }))
            .toBe('segnalazioni.privacy.make_public_description segnalazioni.privacy.make_public_author_loses_access');
        expect(descrizione({ is_published: false, is_approved: false }, { rendi_pubblica: { autore_perde_la_scheda: true } }))
            .toBe('segnalazioni.privacy.make_public_description_pending segnalazioni.privacy.make_public_author_loses_access');
    });

    test('con l\'autore che la vedrà ancora, o senza il fatto, la conferma non aggiunge niente', () => {
        expect(descrizione({ is_published: true, is_approved: true }, { rendi_pubblica: { autore_perde_la_scheda: false } }))
            .toBe('segnalazioni.privacy.make_public_description');
        expect(descrizione({ is_published: true, is_approved: true })).toBe('segnalazioni.privacy.make_public_description');
    });
});

describe('SegnalazioniView — «Rendi pubblica» chiede conferma prima del comando (decisione 127.4)', () => {
    // Il comando non si annulla: la conferma è l'unica protezione. Il pulsante apre la conferma; solo la
    // conferma chiama la rotta. `PageHeaderGuide` è sostituito da un contenitore che mostra le azioni.
    afterEach(() => {
        vi.restoreAllMocks();
        permessi.puoApprovare = true;
    });

    function montaConAzioni(campi: Record<string, unknown>) {
        return shallowMount(SegnalazioniView, {
            props: {
                segnalazione: {
                    id: 9, subject: "Muffa nella camera «grande»", description: "Riguarda l'appartamento\ndel terzo piano", priority: 'alta',
                    stato: 'aperta', is_private: true, is_published: true, is_approved: true, is_locked: false, created_at: 'ieri',
                    created_by: { user: { name: 'mrossi' }, anagrafica: { nome: "Mario D'Angelo" } },
                    condominio: { option: { value: 3, label: "Condominio L'Aurora" }, full: { nome: "Condominio L'Aurora" } },
                    anagrafiche: [], commenti: [], commentiInAttesa: [],
                    ...campi,
                },
                commenti_config: { can_comment: true, can_create: true, can_moderate: true, can_publish: true },
            },
            global: {
                renderStubDefaultSlot: true,
                mocks: { route: (n: string) => `/${n}` },
                stubs: { PageHeaderGuide: { template: '<div><slot name="actions" /></div>' } },
            },
        });
    }

    const pulsante = (pagina: ReturnType<typeof montaConAzioni>) =>
        pagina.findAll('button').find((b) => b.text().includes('segnalazioni.privacy.make_public'));

    test('il pulsante apre la conferma e non chiama la rotta; la conferma la chiama, una volta, per quella segnalazione', async () => {
        const put = vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
        const pagina = montaConAzioni({});

        expect(pagina.findComponent(ConfirmDialog).props('modelValue')).toBe(false);
        await pulsante(pagina)!.trigger('click');

        expect(pagina.findComponent(ConfirmDialog).props('modelValue')).toBe(true);
        expect(put).not.toHaveBeenCalled();

        pagina.findComponent(ConfirmDialog).vm.$emit('confirm');

        expect(put).toHaveBeenCalledTimes(1);
        expect(put.mock.calls[0][0]).toBe('/segnalazioni.rendi-pubblica');
    });

    test('chiusa la conferma senza confermare, la rotta non parte', async () => {
        const put = vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
        const pagina = montaConAzioni({});

        await pulsante(pagina)!.trigger('click');
        pagina.findComponent(ConfirmDialog).vm.$emit('cancel');
        pagina.findComponent(ConfirmDialog).vm.$emit('update:modelValue', false);

        expect(put).not.toHaveBeenCalled();
    });

    test('il pulsante c\'è solo su una privata, e solo per chi approva le segnalazioni', () => {
        expect(pulsante(montaConAzioni({}))).toBeDefined();
        expect(pulsante(montaConAzioni({ is_private: false }))).toBeUndefined();

        permessi.puoApprovare = false;
        expect(pulsante(montaConAzioni({}))).toBeUndefined();
    });
});

// Fase 2 della 1.12.0-beta.11 (Vincenzo, 17/11/2026 sera: «qui possiamo togliere l'icona»): il nome del condominio fra i parametri senza
// l'icona del palazzo; le etichette dei parametri a 12 px come nella scheda della comunicazione (standard 8: niente scritte sotto i 12 px).
describe('SegnalazioniView — i parametri', () => {
    test('il condominio è il nome, senza icona', () => {
        const condominio = monta({}).get('[data-parametro="condominio"]');

        expect(condominio.text()).toContain('Condominio Aurora');
        // Un figlio solo, il nome: con l'icona erano due.
        expect(condominio.element.children).toHaveLength(1);
        expect(condominio.element.children[0].tagName).toBe('SPAN');
    });

    test('nessuna etichetta dei parametri sotto i 12 px', () => {
        expect(monta({}).html()).not.toMatch(/text-\[(?:[0-9]|10|11)px\]/);
    });
});

// Accanto al titolo l'icona della priorità, con il suo colore, come nella scheda della comunicazione (Vincenzo, 17/11/2026 sera: «metterei
// l'icona del livello di priorità a seconda della priorità sia per le segnalazioni che per le comunicazioni»).
describe('SegnalazioniView — l\'icona accanto al titolo', () => {
    for (const p of priorityConstants) {
        test(`con la priorità «${p.value}» è l'icona di quella priorità, nel suo colore`, () => {
            // L'icona della priorità c'è anche fra i parametri: si cerca quella del titolo.
            const icona = monta({ priority: p.value }).findAllComponents(p.icon as never).find((c) => c.attributes('data-icona-titolo') !== undefined);

            expect(icona).toBeDefined();
            for (const c of String(p.colorClass).split(' ').filter(Boolean)) expect(icona!.classes()).toContain(c);
        });
    }
});

// Decisione 470: niente riquadro «Interazioni» (i commenti li dice la loro card), e «Bloccata» sotto la visibilità solo quando lo è.
describe('SegnalazioniView — niente «Interazioni», «Bloccata» solo quando lo è (decisione 470)', () => {
    test('aperta: né il riquadro, né la parola del lucchetto', () => {
        const w = monta({ is_locked: false });

        expect(w.text()).not.toContain('segnalazioni.details.interactions');
        expect(w.text()).not.toContain('segnalazioni.details.unlocked');
        expect(w.text()).not.toContain('segnalazioni.details.comments_enabled');
        expect(w.find('[data-bloccata]').exists()).toBe(false);
    });

    test('bloccata: la riga «Bloccata» con il lucchetto', () => {
        expect(monta({ is_locked: true }).get('[data-bloccata]').text()).toContain('segnalazioni.details.locked');
    });
});

// Standard 16: le azioni sui commenti della scheda chiedono indietro solo la segnalazione, il messaggio e il numero dell'Inbox.
describe('SegnalazioniView — le azioni sui commenti con una visita parziale', () => {
    test('passa alla lista dei commenti le proprietà da rimandare', () => {
        expect(monta({}).findComponent(ListaCommenti).props('ricarica')).toEqual(['segnalazione', 'flash', 'inbox_count']);
    });
});

// Decisione 470 (➕): senza «Interazioni» la card dei parametri non spinge più l'ultima riga in fondo, e l'ultima riga non ha la linea sotto.
describe('SegnalazioniView — la card dei parametri senza spazio vuoto', () => {
    test('le righe stanno in alto, e l\'ultima non ha la linea sotto', () => {
        const card = monta({}).get('[data-card-parametri]');
        const righe = Array.from(card.element.children);

        expect(card.classes()).not.toContain('justify-between');
        expect(righe[righe.length - 1].className).not.toMatch(/\bborder-b\b/);
    });
});

