// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Chi ha avuto questa unità» — «Correggi questa riga» sulla riga chiusa a mano, e la frase della quota a 0 %.
 *
 * Il server dice quali righe si correggono (`correggibile`, con l'indirizzo della modifica in `url_correggi`) e, per la riga di proprietà a quota 0, quanti
 * giorni nessuno paga per l'unità (`giorni_scoperti`): il pannello non ricalcola niente. Il pulsante c'è solo dove `correggibile`; la frase c'è solo dove i
 * giorni sono più di zero, e dice anche dove cambiare la quota; il cassetto «Che cosa cambia» c'è una volta per gruppo, solo con la frase.
 *
 * **Cosa resta scoperto**: la navigazione vera verso la pagina di modifica (è un collegamento Inertia, qui un'àncora) e la risposta del server, che provano
 * `StoricoCorreggiRigaChiusaTest` e `RigheAQuotaZeroTest`.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import TitolaritaSheet from './TitolaritaSheet.vue';

(globalThis as any).route = (n: string) => `/${n}`;

/** Una riga dello storico come la manda `StoricoTitolarita`, con le correzioni date. */
function riga(extra: Record<string, unknown> = {}): any {
    return {
        id: 1, anagrafica: { id: 10, nome: 'Edil Costruzioni Srl', codice_fiscale: null }, tipologia: 'proprietario', diritto: 'Proprietà', quota: 0, attivo: true,
        data_inizio: '2026-01-01', data_fine: '2026-09-15', in_corso: false, futuro: false, periodo: 'dal 1 gennaio 2026 al 15 settembre 2026', durata: '8 mesi', note: null,
        subentro: null, correggibile: true, url_correggi: '/condomini/3/immobili/5/anagrafiche/1/edit', giorni_scoperti: 258, ...extra,
    };
}

function monta(righe: any[]) {
    return mount(TitolaritaSheet, {
        props: { open: true, unita: 'Interno 5', condominioId: 3, immobileId: 5, storico: { passaggi: 0, periodi_chiusi: 1, righe, gruppi: [{ diritto: 'Proprietà', righe }], subentri: [] } },
        global: {
            stubs: {
                Sheet: { template: '<div><slot /></div>' }, SheetContent: { template: '<div><slot /></div>' }, SheetHeader: { template: '<div><slot /></div>' },
                SheetTitle: { template: '<div><slot /></div>' }, SheetDescription: { template: '<div><slot /></div>' },
                Accordion: { template: '<div><slot /></div>' }, AccordionItem: { template: '<div><slot /></div>' },
                AccordionTrigger: { template: '<div><slot /></div>' }, AccordionContent: { template: '<div><slot /></div>' },
                BadgeRuolo: true,
                Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
}

const mario = () => riga({ id: 2, anagrafica: { id: 11, nome: 'Mario Bianchi', codice_fiscale: null }, quota: 100, data_inizio: '2026-09-16', data_fine: null, in_corso: true,
    periodo: 'dal 16 settembre 2026 · in corso', durata: null, correggibile: false, url_correggi: null, giorni_scoperti: 0 });

describe('TitolaritaSheet — correggere la riga chiusa a mano', () => {
    test('il pulsante c\'è sulla riga correggibile, con l\'indirizzo del server, e non su quella in corso', () => {
        const w = monta([riga(), mario()]);

        const pulsanti = w.findAll('[data-testid="correggi-riga"]');
        expect(pulsanti).toHaveLength(1);
        expect(pulsanti[0].text()).toBe('Correggi questa riga');
        expect(pulsanti[0].attributes('href')).toBe('/condomini/3/immobili/5/anagrafiche/1/edit');
    });

    test('la frase della quota a 0 % c\'è solo sulla riga che lascia dei giorni senza pagante, con i giorni, e dice che lo 0 può essere voluto', () => {
        const w = monta([riga(), mario()]);

        const frasi = w.findAll('[data-testid="avviso-quota-zero"]');
        expect(frasi).toHaveLength(1);
        expect(frasi[0].text()).toContain('Con la quota a 0 % in questo periodo nessuno paga per questa unità: 258 giorni.');
        expect(frasi[0].text()).toContain('Se lo 0 è voluto (il regolamento esonera il costruttore), lascialo; se è un errore, correggi la quota.');
        // Il pulsante sta dentro la frase, come nella bozza.
        expect(frasi[0].find('[data-testid="correggi-riga"]').exists()).toBe(true);
    });

    test('un giorno solo si legge «1 giorno»', () => {
        const w = monta([riga({ giorni_scoperti: 1 })]);

        expect(w.get('[data-testid="avviso-quota-zero"]').text()).toContain('nessuno paga per questa unità: 1 giorno.');
    });

    test('la riga correggibile senza giorni scoperti ha il pulsante e non la frase; quella a quota 100 chiusa a mano lo stesso', () => {
        const w = monta([riga({ quota: 100, giorni_scoperti: 0 })]);

        expect(w.find('[data-testid="correggi-riga"]').exists()).toBe(true);
        expect(w.find('[data-testid="avviso-quota-zero"]').exists()).toBe(false);
        expect(w.find('[data-testid="che-cosa-cambia"]').exists()).toBe(false);
    });

    test('la riga chiusa da un passaggio non ha né il pulsante né la correzione: si annulla il passaggio', () => {
        const w = monta([riga({ correggibile: false, url_correggi: null, giorni_scoperti: 0, quota: 100 })]);

        expect(w.find('[data-testid="correggi-riga"]').exists()).toBe(false);
        expect(w.find('[data-testid="avviso-quota-zero"]').exists()).toBe(false);
    });

    test('il cassetto «Che cosa cambia» c\'è una volta sola, con le tre frasi, e solo con la frase della quota', () => {
        const w = monta([riga(), riga({ id: 3, anagrafica: { id: 12, nome: 'Altra Srl', codice_fiscale: null } }), mario()]);

        const cassetti = w.findAll('[data-testid="che-cosa-cambia"]');
        expect(cassetti).toHaveLength(1);
        expect(cassetti[0].find('summary').text()).toBe('Che cosa cambia se correggi la quota');
        expect(cassetti[0].findAll('li').map((l) => l.text())).toEqual([
            'Le rate già emesse non cambiano da sole.',
            'Un piano in bozza si ricalcola con la quota nuova.',
            'Un piano emesso: «Annulla emissione», bozza e «Ricalcola» (solo se non ha incassi).',
        ]);
    });

    test('senza i campi nuovi (un server di prima) niente pulsante e niente frase', () => {
        const senza = riga();
        delete senza.correggibile;
        delete senza.url_correggi;
        delete senza.giorni_scoperti;
        const w = monta([senza]);

        expect(w.find('[data-testid="correggi-riga"]').exists()).toBe(false);
        expect(w.find('[data-testid="avviso-quota-zero"]').exists()).toBe(false);
    });
});
