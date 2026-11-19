// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La Coda 683 (decisione 495), le due parti che si vedono nella pagina:
 *
 * - (1) i quattro moduli di creazione passano al campo degli allegati il limite di un file e i formati che manda il server
 *   (`allegati_limite_file`, `allegati_formati`), come il riquadro della scheda: il suggerimento non dice più «10 MB» fisso;
 * - (4) nel riquadro della scheda «N / massimo» conta tutti i file dell'elemento (`config.totale`), come i posti rimasti; quando chi guarda
 *   ne vede meno, una riga lo dice («Ne vedi 1 su 3: gli altri non sono visibili a te.»). Senza `totale` (un server di prima) conta i
 *   file che vede, come prima.
 *
 * Che cosa non copre: il valore vero del limite (lo prova il Pest `tests/Feature/Allegati/AllegatiCoda683Test.php`), la traduzione delle
 * frasi (qui `trans()` restituisce la chiave).
 */

import { describe, expect, test, vi } from 'vitest';
import { mount, shallowMount } from '@vue/test-utils';

vi.mock('laravel-vue-i18n', () => ({
    trans: (chiave: string) => chiave,
    transChoice: (chiave: string, numero: number, argomenti?: Record<string, string>) => `${chiave}#${numero}${argomenti ? JSON.stringify(argomenti) : ''}`,
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
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasRole: () => true, hasPermission: () => true }),
}));
vi.mock('axios', () => ({ default: { get: vi.fn(async () => ({ data: [] })) } }));
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;

const AllegatiCampoModuli = (await import('./AllegatiCampoModuli.vue')).default;
const AllegatiRiquadro = (await import('./AllegatiRiquadro.vue')).default;

const PAGINE = {
    'comunicazioni/ComunicazioniNew': () => import('@/pages/comunicazioni/ComunicazioniNew.vue'),
    'comunicazioni/user/ComunicazioniNew': () => import('@/pages/comunicazioni/user/ComunicazioniNew.vue'),
    'segnalazioni/SegnalazioniNew': () => import('@/pages/segnalazioni/SegnalazioniNew.vue'),
    'segnalazioni/user/SegnalazioniNew': () => import('@/pages/segnalazioni/user/SegnalazioniNew.vue'),
};

describe('Coda 683 (1) — i moduli di creazione dicono il limite vero', () => {
    for (const [nome, carica] of Object.entries(PAGINE)) {
        test(`${nome} passa al campo il limite e i formati del server`, async () => {
            const Pagina = (await carica()).default;
            const pagina = shallowMount(Pagina, {
                props: {
                    condomini: [], anagrafiche: [], allegati_massimo: 6, allegati_limite_file: '20 MB', allegati_formati: ['jpg', 'pdf'],
                    commenti_moderati_di_serie: true, commenti_segnalazioni_moderati_di_serie: true,
                } as never,
                global: { renderStubDefaultSlot: true, mocks: { route: (n: string) => `/${n}` } },
            });
            const campo = pagina.findComponent(AllegatiCampoModuli);

            expect(campo.exists(), 'il campo degli allegati non c\'è').toBe(true);
            expect(campo.props('limiteFile')).toBe('20 MB');
            expect(campo.props('formati')).toEqual(['jpg', 'pdf']);
            expect(campo.props('massimo')).toBe(6);
        });
    }
});

function allegato(id: number) {
    return {
        id, nome: `file-${id}.jpg`, mime_type: 'image/jpeg', dimensione: 2048, stato: 'pubblicato', autore: 'Mario Rossi',
        caricato_il: '2026-11-08T10:00:00.000Z', url_apri: `/user/allegati/${id}/apri`, url_scarica: `/user/allegati/${id}/scarica`,
        puo_modificare: false, puo_eliminare: false, puo_approvare: false,
    };
}

function riquadro(visibili: number, config: Record<string, unknown>) {
    return mount(AllegatiRiquadro, {
        props: {
            tipo: 'segnalazioni', elementoId: 5, porta: 'user', allegati: Array.from({ length: visibili }, (_, i) => allegato(i + 1)),
            config: { accesso: 'aperti', moderati: true, puo_caricare: true, puo_impostare: false, massimo: 5, limite_file: '20 MB', formati: ['jpg'], ...config },
        } as never,
        global: { mocks: { route: (globalThis as Record<string, unknown>).route } },
    });
}

const contatore = (w: ReturnType<typeof riquadro>) => w.get('[data-intestazione-allegati]').text().replace(/\s+/g, ' ');

describe('Coda 683 (4) — «N / massimo» e i posti rimasti dicono la stessa cosa', () => {
    test('il condòmino che vede 1 file su 3 legge «3 / 5», i 2 posti rimasti, e una riga che lo spiega', () => {
        const w = riquadro(1, { totale: 3, rimasti: 2 });

        expect(contatore(w)).toContain('3 / 5');
        const riga = w.get('[data-allegati-non-visibili]');
        expect(riga.text()).toContain('allegati.counter.hidden#1');
        expect(riga.text()).toContain('"visibili":"1"');
        expect(riga.text()).toContain('"totale":"3"');
        // I posti della zona di caricamento sono gli stessi: 3 + 2 = 5.
        expect(w.findComponent(AllegatiCampoModuli).props('rimasti')).toBe(2);
    });

    test('chi vede tutti i file legge «3 / 5» e nessuna riga in più', () => {
        const w = riquadro(3, { totale: 3, rimasti: 2 });

        expect(contatore(w)).toContain('3 / 5');
        expect(w.find('[data-allegati-non-visibili]').exists()).toBe(false);
    });

    test('senza `totale` dal server conta i file che vede, come prima', () => {
        const w = riquadro(2, { rimasti: 3 });

        expect(contatore(w)).toContain('2 / 5');
        expect(w.find('[data-allegati-non-visibili]').exists()).toBe(false);
    });
});
