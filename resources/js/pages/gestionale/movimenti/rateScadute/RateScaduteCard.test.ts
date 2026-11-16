// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le tre card in alto della pagina «Rate scadute», come nelle altre pagine: «Chi deve pagare» (ambra), «Lettere e azioni» (blu), «Le regole» (verde),
 * con un titolo, una frase, un'icona e un colore. Le frasi dicono che cosa fa la pagina: gli interessi si propongono dai suggerimenti, non dalla riga; la
 * spesa del ritardo si chiede alle persone scelte; «Recupero dei crediti» dà la tolleranza, il tasso e il termine delle lettere, non le spese.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { CalendarClock, Mail, Scale } from 'lucide-vue-next';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), reload: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}` }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Pagina = (await import('./RateScadute.vue')).default;
const PageHeaderGuide = (await import('@/components/PageHeaderGuide.vue')).default;

const proprieta = {
    condominio: { id: 3, nome: 'Via Verdi' }, condomini: [], esercizio: { id: 9 },
    righe: { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0, from: 0, to: 0 } },
    totale: 0, persone_totali: 0, oggi: '2026-06-29', non_emesse: null, gestioni: [], persona_della_porta: null, testo_norma: '«…»', frase_festivo: '',
    lettere_da_riprovare: 0, lettere_in_coda_da_tempo: 0, regole_del_recupero: '', suggerimenti: [],
    filtri: { alla: '2026-06-29', vista: 'scadute', giorni: 30, cerca: null, anagrafica_id: null, fascia: null, minimo: null, gestione_id: null, sei_mesi_entro: null,
        con_pregresso: false, ordine: 'scaduto', senza_lettere_da: null, mai_scritto: false, da_spedire: false, con_accordo: false },
};

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

describe('le tre card di «Rate scadute»', () => {
    test('l\'intestazione riceve le tre card, con i titoli, le frasi, le icone e i colori', () => {
        w = mount(Pagina, { props: proprieta as never, global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' },
            PageHeaderGuide: true, LettereDialog: true, AccordoRientroDialog: true, ProponiInteressiDialog: true, AddebitaSpesaDialog: true, RateScaduteGuide: true } } });
        const card = w.findComponent(PageHeaderGuide).props('guides') as Array<{ title: string; description: string; icon: unknown; colorVariant: string }>;
        expect(card.map((c) => [c.title, c.colorVariant])).toEqual([['Chi deve pagare', 'amber'], ['Lettere e azioni', 'blue'], ['Le regole', 'emerald']]);
        expect(card.map((c) => c.icon)).toEqual([CalendarClock, Mail, Scale]);
        expect(card[0].description).toBe('Le persone con rate scadute e non versate alla data scelta: lo scaduto, da quanti giorni, l\'ultima lettera e la data dei sei mesi per '
            + 'agire. Con «In scadenza», le rate che stanno per scadere.');
        expect(card[1].description).toBe('Scegli con i filtri e le caselle, poi prepara le lettere o le spese del ritardo. Dalla riga di una persona, una lettera a lei sola e '
            + 'l\'accordo di rientro; gli interessi si propongono dai suggerimenti in alto.');
        expect(card[2].description).toBe('La tolleranza, il tasso degli interessi e il termine delle lettere vengono da «Recupero dei crediti»; senza regole vale il tasso '
            + 'legale. I suggerimenti in alto ne tengono conto.');
    });

    test('la pagina non passa più un elenco vuoto, e la guida nel cassetto resta', () => {
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/movimenti/rateScadute/RateScadute.vue'), 'utf8');
        expect(sorgente).not.toContain(':guides="[]"');
        expect(sorgente).toContain(':guides="GUIDE_RATE_SCADUTE"');
        expect(sorgente).toContain('has-text-guide');
    });
});
