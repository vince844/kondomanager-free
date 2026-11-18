// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scheda di una comunicazione: la disposizione (Coda 559, giro di correzioni 3; decisione 287).
 *
 * La scheda si dispone come quella della segnalazione: sopra il testo e i parametri, affiancati e alti uguali; sotto i
 * Commenti e gli Allegati, affiancati a metà, prima i Commenti. La pagina è la stessa per il pannello e per il portale.
 *
 * - La prima riga è `[data-riga="scheda"]`, la seconda `[data-riga="commenti-allegati"]`.
 * - Il riquadro Commenti ha `id="commenti"`: le email dei commenti portano a `…#commenti`.
 * - Nel portale, senza discussioni, il riquadro Commenti non c'è e gli Allegati prendono tutta la riga.
 *
 * `trans()` restituisce la chiave, e si legge la chiave. I componenti figli sono sostituiti: si guarda dove stanno.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';
import AllegatiRiquadro from '@/components/allegati/AllegatiRiquadro.vue';
import DiscussioniComunicazione from '@/components/commenti/DiscussioniComunicazione.vue';

const stato = vi.hoisted(() => ({ porta: 'admin' as 'admin' | 'user' }));

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, numero: number) => `${chiave}#${numero}`,
    wTrans: (chiave: string) => ({ value: chiave }),
    isLoaded: () => true,
    loadLanguageAsync: async () => undefined,
}));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generatePath: (p: string) => `/${p}`,
        generateRoute: (n: string) => `${stato.porta}.${n}`,
        getRolePrefix: () => stato.porta,
        hasPermission: () => true,
        hasRole: () => true,
    }),
}));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const ComunicazioniView = (await import('./ComunicazioniView.vue')).default;

// La discussione unica di una comunicazione ai palazzi, come la manda il server (decisione 458).
const unaDiscussione = [{ chiave: 'tutti', tipo: 'tutti', titolo: '', puo_scrivere: true, commenti: [] }];

function monta(porta: 'admin' | 'user', discussioni: unknown[], campi: Record<string, unknown> = {}, destinatari?: unknown) {
    stato.porta = porta;

    return shallowMount(ComunicazioniView, {
        props: {
            comunicazione: {
                id: 7, subject: 'Lavori al tetto', description: 'I lavori cominciano lunedì.', priority: 'media',
                is_private: false, is_published: true, is_approved: true, is_featured: false,
                can_comment: true, commenti_moderati: false, created_at: '10/11/2026',
                created_by: { user: { name: 'Studio Bianchi' } },
                ...campi,
            },
            allegati: [],
            allegati_config: { massimo: 10, puo_caricare: porta === 'admin', puo_impostare: porta === 'admin', moderati: false },
            commenti_config: { can_comment: true, commenti_moderati: false, puo_impostare: porta === 'admin', puo_moderare: porta === 'admin' },
            discussioni,
            ...(destinatari ? { destinatari } : {}),
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
    });
}

/** Vero se `prima` sta nel documento prima di `dopo`. */
function precede(prima: Element, dopo: Element): boolean {
    return Boolean(prima.compareDocumentPosition(dopo) & Node.DOCUMENT_POSITION_FOLLOWING);
}

describe('ComunicazioniView — la disposizione come la scheda della segnalazione', () => {
    test('la prima riga tiene il testo e i parametri, affiancati e alti uguali', () => {
        const wrapper = monta('admin', unaDiscussione);
        const riga = wrapper.find('[data-riga="scheda"]');

        expect(riga.exists()).toBe(true);
        expect(riga.classes()).toEqual(expect.arrayContaining(['grid', 'lg:grid-cols-3', 'items-stretch']));
        expect(riga.text()).toContain('Lavori al tetto');
        expect(riga.text()).toContain('I lavori cominciano lunedì.');
        expect(riga.text()).toContain('comunicazioni.label.priority');
        expect(riga.text()).toContain('comunicazioni.label.visibility');

        // I commenti e gli allegati non stanno più nella colonna del testo.
        expect(riga.find('#commenti').exists()).toBe(false);
        expect(riga.findComponent(AllegatiRiquadro).exists()).toBe(false);
        expect(riga.findComponent(DiscussioniComunicazione).exists()).toBe(false);
    });

    test('la seconda riga tiene i Commenti e gli Allegati affiancati, prima i Commenti', () => {
        const wrapper = monta('admin', unaDiscussione);
        const riga = wrapper.find('[data-riga="commenti-allegati"]');

        expect(riga.exists()).toBe(true);
        expect(riga.classes()).toEqual(expect.arrayContaining(['grid', 'lg:grid-cols-2']));

        const commenti = riga.find('#commenti');
        const allegati = riga.findComponent(AllegatiRiquadro);
        expect(commenti.exists()).toBe(true);
        expect(allegati.exists()).toBe(true);
        expect(commenti.text()).toContain('commenti.titolo');
        expect(commenti.findComponent(DiscussioniComunicazione).exists()).toBe(true);
        expect(precede(commenti.element, allegati.element)).toBe(true);

        // La seconda riga viene dopo la prima.
        expect(precede(wrapper.find('[data-riga="scheda"]').element, riga.element)).toBe(true);
    });

    test('nel pannello il riquadro Commenti c\'è anche senza discussioni', () => {
        const wrapper = monta('admin', []);
        const riga = wrapper.find('[data-riga="commenti-allegati"]');

        expect(riga.find('#commenti').exists()).toBe(true);
        expect(riga.classes()).toContain('lg:grid-cols-2');
    });

    test('nel portale, con una discussione, la disposizione è la stessa', () => {
        const wrapper = monta('user', unaDiscussione);
        const riga = wrapper.find('[data-riga="commenti-allegati"]');

        expect(wrapper.find('[data-riga="scheda"]').exists()).toBe(true);
        expect(riga.find('#commenti').exists()).toBe(true);
        expect(riga.findComponent(AllegatiRiquadro).exists()).toBe(true);
        expect(riga.classes()).toContain('lg:grid-cols-2');
    });

    test('nel portale, senza discussioni, il riquadro Commenti non c\'è e gli Allegati prendono tutta la riga', () => {
        const wrapper = monta('user', []);
        const riga = wrapper.find('[data-riga="commenti-allegati"]');

        expect(wrapper.find('#commenti').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('commenti.titolo');
        expect(riga.exists()).toBe(true);
        expect(riga.findComponent(AllegatiRiquadro).exists()).toBe(true);
        expect(riga.classes()).not.toContain('lg:grid-cols-2');
    });
});

describe('ComunicazioniView — «N in attesa» nell\'intestazione dei commenti (decisione 441)', () => {
    const conAttesa = [{
        chiave: 'tutti', tipo: 'tutti', titolo: '', puo_scrivere: true,
        commenti: [
            { id: 1, corpo: 'a', stato: 'pubblicato' },
            { id: 2, corpo: 'b', stato: 'in_attesa' },
            { id: 3, corpo: 'c', stato: 'in_attesa' },
        ],
    }];

    test('chi modera vede quanti commenti aspettano', () => {
        const testo = monta('admin', conAttesa).find('#commenti').text();

        expect(testo).toContain('2 commenti.in_attesa');
    });

    test('chi non modera non lo vede, nemmeno se i suoi commenti sono in attesa', () => {
        const testo = monta('user', conAttesa).find('#commenti').text();

        expect(testo).not.toContain('commenti.in_attesa');
    });
});

// Con la discussione unica (decisione 458) il numero accanto a «Commenti» è il solo numero della scheda: conta i pubblicati e gli in
// attesa, come il contatore dell'elenco, non i nascosti che chi modera legge in grigio (decisione 457).
describe('ComunicazioniView — il numero accanto a «Commenti» non conta i nascosti (decisione 457)', () => {
    test('due pubblicati, uno in attesa e uno nascosto fanno 3', () => {
        const conNascosto = [{
            chiave: 'tutti', tipo: 'tutti', titolo: '', puo_scrivere: true,
            commenti: [
                { id: 1, corpo: 'a', stato: 'pubblicato' },
                { id: 2, corpo: 'b', stato: 'pubblicato' },
                { id: 3, corpo: 'c', stato: 'in_attesa' },
                { id: 4, corpo: 'd', stato: 'nascosto' },
            ],
        }];

        expect(monta('admin', conNascosto).get('[data-totale-commenti]').text()).toBe('3');
    });
});


// Fase 1-bis della 1.12.0-beta.11: le etichette «Priorità» e «Visibilità» della scheda erano a 11 px, la misura che la decisione 441 ha tolto
// dal modulo dei commenti (standard 8: niente scritte sotto i 12 px).
describe('ComunicazioniView — nessuna scritta sotto i 12 px', () => {
    test('le etichette dei parametri sono a 12 px', () => {
        const riga = monta('admin', unaDiscussione).get('[data-riga="scheda"]');

        expect(riga.html()).not.toMatch(/text-\[(?:[0-9]|10|11)px\]/);
        const etichette = riga.findAll('p').filter((p) => ['comunicazioni.label.priority', 'comunicazioni.label.visibility'].includes(p.text().trim()));
        expect(etichette).toHaveLength(2);
        for (const e of etichette) expect(e.classes()).toContain('text-xs');
    });
});

// Decisione 467: la scheda della comunicazione come quella della segnalazione, il testo lungo con «Leggi tutto» e la card dei parametri con
// le interazioni.
describe('ComunicazioniView — «Leggi tutto» (decisione 467)', () => {
    test('un testo lungo si chiude a quattro righe e si riapre con «Leggi tutto», poi «Leggi meno»', async () => {
        const w = monta('admin', unaDiscussione, { description: 'Parola '.repeat(80) });
        const testo = w.get('[data-testo-comunicazione]');
        const pulsante = w.get('[data-leggi-tutto]');

        expect(testo.classes()).toContain('line-clamp-4');
        expect(pulsante.text()).toContain('comunicazioni.label.read_more');

        await pulsante.trigger('click');
        expect(w.get('[data-testo-comunicazione]').classes()).not.toContain('line-clamp-4');
        expect(w.get('[data-leggi-tutto]').text()).toContain('comunicazioni.label.read_less');
    });

    test('un testo corto non ha «Leggi tutto»', () => {
        const w = monta('admin', unaDiscussione);

        expect(w.find('[data-leggi-tutto]').exists()).toBe(false);
        expect(w.get('[data-testo-comunicazione]').classes()).not.toContain('line-clamp-4');
    });
});

// Decisione 470: il riquadro «Interazioni» della 467 ripeteva ciò che dice la card dei commenti; resta «In evidenza», una riga sotto la
// visibilità, solo quando lo è.
describe('ComunicazioniView — niente «Interazioni», «In evidenza» sotto la visibilità (decisione 470)', () => {
    test('in evidenza: la riga sotto la visibilità; nessun riquadro «Interazioni»', () => {
        const card = monta('admin', unaDiscussione, { can_comment: true, commenti_moderati: true, is_featured: true }).get('[data-riga="scheda"]');

        expect(card.text()).not.toContain('comunicazioni.label.interactions');
        expect(card.find('[data-interazione]').exists()).toBe(false);
        expect(card.get('[data-in-evidenza]').text()).toContain('comunicazioni.label.in_evidenza');
    });

    test('non in evidenza: nessuna riga', () => {
        const card = monta('user', unaDiscussione, { is_featured: false }).get('[data-riga="scheda"]');

        expect(card.find('[data-in-evidenza]').exists()).toBe(false);
    });
});

// Il riquadro «Commenti» comincia alla stessa altezza di quello degli allegati (Vincenzo, 17/11/2026 sera): il contenuto della card non ha il
// `pt-6` che lo spingeva 24 px più in basso (89 contro 65 dall'inizio della card, misurato a video); come gli allegati, parte da `pt-0`.
describe('ComunicazioniView — i Commenti alla stessa altezza degli Allegati', () => {
    test('il contenuto della card dei Commenti non ha uno spazio in alto in più', () => {
        expect(monta('admin', unaDiscussione).get('#commenti').html()).not.toMatch(/class="[^"]*\bpt-6\b/);
    });
});

describe('ComunicazioniView — la linea sotto l\'intestazione dei Commenti (decisione 469)', () => {
    test('l\'intestazione della card dei Commenti ha la linea sotto, come quella degli Allegati', () => {
        const intestazione = monta('admin', unaDiscussione).get('#commenti [data-intestazione-commenti-card]');

        expect(intestazione.classes()).toEqual(expect.arrayContaining(['border-b', 'pb-4']));
    });
});

// Standard 16: le azioni sui commenti della scheda chiedono indietro solo i commenti, il messaggio e il numero dell'Inbox.
describe('ComunicazioniView — le azioni sui commenti con una visita parziale', () => {
    test('passa alle discussioni le proprietà da rimandare', () => {
        expect(monta('admin', unaDiscussione).findComponent(DiscussioniComunicazione).props('ricarica')).toEqual(['discussioni', 'flash', 'inbox_count']);
    });
});

// Decisione 467: i destinatari nella card, solo nel pannello (il server non li manda al portale).
describe('ComunicazioniView — i destinatari nella card (decisione 467)', () => {
    const sei = ['Condominio Aurora', 'Condominio Borea', 'Condominio Cirro', 'Condominio Dafne', 'Condominio Eolo', 'Condominio Fauno'];

    test('sei condomìni: il numero, i primi tre nomi e «+3»', () => {
        const d = monta('admin', unaDiscussione, {}, { tipo: 'condomini', totale: 6, nomi: sei }).get('[data-destinatari]');

        expect(d.text()).toContain('comunicazioni.label.recipients');
        expect(d.text()).toContain('comunicazioni.label.destinatari_condomini#6');
        // La riga mostra i primi tre; gli altri stanno nel suggerimento di «+3» (che il montaggio di prova scrive nella pagina).
        expect(d.get('[data-destinatari-nomi]').text()).toBe('Condominio Aurora, Condominio Borea, Condominio Cirro');
        expect(d.get('[data-destinatari-altri]').text()).toBe('+3');
        // Con molti condomìni la riga dei nomi non va a capo: si tronca con i puntini, e «+3» resta intero accanto.
        expect(d.get('[data-destinatari-nomi]').classes()).toEqual(expect.arrayContaining(['truncate', 'min-w-0']));
        expect(d.get('[data-destinatari-altri]').classes()).toContain('shrink-0');
        expect(d.get('[data-destinatari-nomi]').element.parentElement!.className).toMatch(/\bflex\b/);
    });

    test('un condominio solo: il suo nome', () => {
        const d = monta('admin', unaDiscussione, {}, { tipo: 'condomini', totale: 1, nomi: ['Condominio Aurora'] }).get('[data-destinatari]');

        expect(d.text()).toContain('Condominio Aurora');
        expect(d.text()).not.toContain('destinatari_condomini');
        expect(d.find('[data-destinatari-altri]').exists()).toBe(false);
    });

    test('a persone: il numero e i nomi', () => {
        const d = monta('admin', unaDiscussione, {}, { tipo: 'persone', totale: 2, nomi: ['Mario Rossi', 'Paolo Neri'] }).get('[data-destinatari]');

        expect(d.text()).toContain('comunicazioni.label.destinatari_persone#2');
        expect(d.get('[data-destinatari-nomi]').text()).toBe('Mario Rossi, Paolo Neri');
    });

    test('privata: «Privata, con lo studio»', () => {
        const d = monta('admin', unaDiscussione, {}, { tipo: 'privata', totale: 0, nomi: [] }).get('[data-destinatari]');

        expect(d.text()).toContain('comunicazioni.label.destinatari_privata');
    });

    test('senza destinatari (il portale) la sezione non c\'è', () => {
        expect(monta('user', unaDiscussione).find('[data-destinatari]').exists()).toBe(false);
    });
});

