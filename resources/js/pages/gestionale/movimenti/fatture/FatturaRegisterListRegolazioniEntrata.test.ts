// @vitest-environment jsdom

/**
 * Coda 287 — il blocco della lista delle fatture passive con le regolazioni immediate scritte su una voce di entrata.
 *
 * Il server manda `regolazioniSuVoceDiEntrata` (protocollo, data, importo in centesimi, voce e id della voce, id della scrittura) per tutto il
 * condominio (`RegolazioniSuVoceDiEntrata::delCondominio`). La pagina le mostra in un blocco a sé, sopra il ponte delle regolazioni: quante sono e
 * su quante voci (le voci si contano per id: due voci con lo stesso nome sono due voci), che cosa hanno scritto (un'uscita di cassa al posto di
 * un'entrata), la strada (aprirle e premere «Storna», che per un esercizio chiuso si registra in un esercizio aperto: con più esercizi aperti il
 * programma non dice quale), e che l'incasso vero di un'entrata oggi non si registra. Non si nasconde: sparisce quando l'elenco è vuoto. Senza un
 * esercizio aperto non indica lo storno, che si rifiuterebbe (`StornaRegolazioneImmediataAction::execute`).
 *
 * Fonte dell'atteso: la decisione 163.2 (D2: un blocco a sé, per condominio, senza il nascondimento di sei mesi, che si spegne quando l'ultima è
 * stornata) e la riga della Coda 287 nella roadmap. Le cifre sono quelle del server (centesimi) scritte in euro con il formato della pagina.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';

// Ogni pagina montata si smonta alla fine del suo test.
enableAutoUnmount(afterEach);

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    router: { get: vi.fn(), visit: vi.fn() },
    usePage: () => ({
        props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: ['Accesso pannello amministratore'] } } },
    }),
}));
vi.mock('@/components/gestionale/movimenti/fatture/columns', () => ({ createColumns: () => [] }));

// Il nome della rotta con i parametri che contano, così il collegamento si legge nel test.
(globalThis as Record<string, unknown>).route = (nome: string, p: Record<string, unknown> = {}) =>
    `/${nome}/${p.condominio ?? ''}/${p.scrittura ?? p.esercizio ?? ''}`;

const FatturaRegisterList = (await import('./FatturaRegisterList.vue')).default;

const passa = { template: '<div><slot /></div>' };
const stubs = {
    GestionaleLayout: passa,
    MovimentiLayout: passa,
    PageHeaderGuide: true,
    FattureGuide: true,
    DataTable: true,
    Alert: true,
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
};

const PRIMA = { id: 501, numero_protocollo: 'REG-2025-0007', data: '10/03/2025', importo: 30000, voce: 'Affitto antenna', voce_id: 71 };
const SECONDA = { id: 502, numero_protocollo: 'REG-2026-0002', data: '02/04/2026', importo: 4550, voce: 'Interessi attivi', voce_id: 72 };

function pagina(regolazioni: unknown[] | undefined, esercizio: { id: number } | null = { id: 41 }, regolazioniImmediate = 0) {
    return mount(FatturaRegisterList, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' },
            condomini: [],
            fatture: { data: [], meta: {} },
            stats: { totale_aperte: 0, totale_sfori: 0, importo_da_pagare: 0 },
            filters: {},
            regolazioniImmediate,
            regolazioniSuVoceDiEntrata: regolazioni,
            esercizio,
        } as never,
        global: { stubs },
    });
}

const testo = (w: ReturnType<typeof pagina>, chi: string) => w.find(`[data-blocco="regolazioni-su-entrata"] [data-testo="${chi}"]`).text().replace(/\s+/g, ' ');

describe('Coda 287 — il blocco delle regolazioni su una voce di entrata', () => {
    test('compare con due regolazioni: il conteggio, il fatto, la strada e l\'entrata che non si registra', () => {
        const w = pagina([PRIMA, SECONDA]);

        expect(w.find('[data-blocco="regolazioni-su-entrata"]').exists()).toBe(true);
        expect(testo(w, 'titolo')).toBe('2 regolazioni immediate sono registrate su voci di entrata');
        expect(testo(w, 'fatto')).toBe("La regolazione immediata registra un'uscita di cassa: queste sono state scritte come spese, e la banca o la cassa è scesa dell'importo invece di salire.");
        expect(testo(w, 'strada')).toBe("Per correggere, apri ognuna e premi «Storna»: lo storno scrive la scrittura inversa e la banca o la cassa torna com'era. Se l'esercizio della regolazione è chiuso, lo storno si registra in un esercizio aperto.");
        expect(testo(w, 'entrata')).toBe("L'incasso vero di un'entrata, per esempio l'affitto dell'antenna, oggi non si registra nel programma.");
    });

    test('due regolazioni sulla stessa voce: il conteggio è al plurale e la voce è una sola, non «voci di entrata»', () => {
        const altra = { ...PRIMA, id: 503, numero_protocollo: 'REG-2026-0004', data: '15/05/2026', importo: 1200 };

        expect(testo(pagina([PRIMA, altra]), 'titolo')).toBe('2 regolazioni immediate sono registrate su una voce di entrata');
    });

    test('due regolazioni su due voci con lo stesso nome sono su voci di entrata: le voci si contano per id, non per nome', () => {
        const stessoNome = { ...SECONDA, voce: PRIMA.voce };

        expect(testo(pagina([PRIMA, stessoNome]), 'titolo')).toBe('2 regolazioni immediate sono registrate su voci di entrata');
    });

    test('con una regolazione sola il conteggio e le frasi sono al singolare', () => {
        const w = pagina([PRIMA]);

        expect(testo(w, 'titolo')).toBe('1 regolazione immediata è registrata su una voce di entrata');
        expect(testo(w, 'fatto')).toContain('questa è stata scritta come una spesa,');
        expect(testo(w, 'strada')).toContain('apri la regolazione e premi «Storna»');
    });

    test('una riga per regolazione, con protocollo, data, voce e importo in euro, e il collegamento alla sua scheda', () => {
        const righe = pagina([PRIMA, SECONDA]).findAll('[data-riga="regolazione-su-entrata"]');

        expect(righe).toHaveLength(2);
        // I pezzi della riga sono separati dallo spazio del layout (`gap-x-3`), non da un carattere: si leggono uno per uno.
        const pezzi = (i: number) => righe[i].findAll('span').map((s) => s.text().replace(/\s+/g, ' '));
        expect(pezzi(0)).toEqual(['REG-2025-0007', '10/03/2025', '«Affitto antenna»', '€ 300,00']);
        expect(pezzi(1)).toEqual(['REG-2026-0002', '02/04/2026', '«Interessi attivi»', '€ 45,50']);
        expect(righe.map((r) => r.find('a').attributes('href'))).toEqual(['/admin.gestionale.scritture.show/28/501', '/admin.gestionale.scritture.show/28/502']);
    });

    test('sparisce con l\'elenco vuoto e quando il server non lo manda', () => {
        expect(pagina([]).find('[data-blocco="regolazioni-su-entrata"]').exists()).toBe(false);
        expect(pagina(undefined).find('[data-blocco="regolazioni-su-entrata"]').exists()).toBe(false);
    });

    test('non ha un pulsante per nasconderlo, e resta anche quando il ponte delle regolazioni è nascosto', () => {
        window.localStorage.setItem('km.ponte-regolazioni-nascosto.28', String(Date.now()));
        const w = pagina([PRIMA], { id: 41 }, 3);
        const blocco = w.find('[data-blocco="regolazioni-su-entrata"]');

        expect(blocco.findAll('button')).toHaveLength(0);
        expect(blocco.find('[aria-label="Nascondi avviso"]').exists()).toBe(false);
        // Il ponte, nascosto da meno di sei mesi, non c'è; il blocco sì.
        expect(w.find('[aria-label="Nascondi avviso"]').exists()).toBe(false);
        expect(blocco.exists()).toBe(true);
        window.localStorage.removeItem('km.ponte-regolazioni-nascosto.28');
    });

    test('sta sopra il ponte delle regolazioni dell\'esercizio', () => {
        const html = pagina([PRIMA], { id: 41 }, 2).html();

        expect(html.indexOf('data-blocco="regolazioni-su-entrata"')).toBeGreaterThan(-1);
        expect(html.indexOf('data-blocco="regolazioni-su-entrata"')).toBeLessThan(html.indexOf('regolazioni immediate registrate'));
    });

    test('senza un esercizio aperto non indica lo storno, che si rifiuterebbe, e dice perché', () => {
        const w = pagina([PRIMA], null);

        expect(testo(w, 'strada')).toBe("Per correggere serve lo storno, che per una regolazione di un esercizio chiuso si registra in un esercizio aperto: oggi il condominio non ha un esercizio aperto, e lo storno si rifiuta.");
        expect(testo(w, 'strada')).not.toContain('premi «Storna»');
    });
});
