// @vitest-environment jsdom

/**
 * Il widget «Tesoreria a 30gg» e l'elenco delle rate scadute: «Vedi tutte le rate scadute» nel blocco delle persone, sempre, anche con il
 * semaforo verde; il ritardo con le parole del server (un pregresso di anzianità non nota non è mai «in ritardo di N giorni»); il titolo del
 * livello rosso parla della liquidità, senza parole di effetto legale. Il testo si legge montato, non nel sorgente.
 */
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${Object.values(p ?? {}).join('/')}`;

import TreasuryGuardianWidget from './TreasuryGuardianWidget.vue';

const base = {
    liquiditaTotaleCents: 100000, liquiditaVincolataCents: 0, liquiditaDisponibileCents: 100000, uscitePredittiveCents: 0,
    incassiAttesiCents: 25000, incassiScadutiCents: 15000, incassiInScadenzaCents: 10000, debitiPregressiScadutiCents: 0,
    scenarioPessimisticoCents: 100000, scenarioOttimisticoCents: 125000, giornoScopertoPrevisto: null, scopertoMaxCents: 0, livello: 'verde' as const,
    fattureInScadenza: [], azioniSuggerite: [], fattureSenzaScadenza: [], hasFondoRiserva: false,
    morositaImpattanti: [] as any[], morositaAltri: 0, scadutoPerFasce: { '1_30': 0, '31_90': 0, oltre_90: 50000 },
    elencoRateScadute: { route: 'admin.gestionale.rate-scadute.index', routeParams: { condominio: 7 } },
};
const persona = (n: number, giorni: number | null, ritardoTesto: string) => ({
    anagraficaId: n, condomino: `Persona ${n}`, immobile: `Interno ${n}`, importoCents: 50000, giorniRitardo: giorni, ritardoTesto,
    route: 'admin.gestionale.anagrafiche.estratto-conto', routeParams: { condominio: 7, anagrafica: n },
});
const monta = (treasury: Record<string, unknown>) => mount(TreasuryGuardianWidget, { props: { treasury: { ...base, ...treasury } as any } });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('«Vedi tutte le rate scadute»', () => {
    test('c\'è nel blocco delle persone anche con il semaforo verde, e porta all\'elenco del condominio', () => {
        const w = monta({ morositaImpattanti: [persona(1, 74, 'in ritardo di 74 giorni')] });
        const link = w.get('[data-testid="vedi-tutte"]');
        expect(link.text()).toBe('Vedi tutte le rate scadute');
        expect(link.attributes('href')).toBe('/admin.gestionale.rate-scadute.index/7');
    });
});

describe('il ritardo con le parole del server', () => {
    test('un pregresso di anzianità non nota non è «in ritardo di N giorni»; con una rata di 12 giorni, il ritardo massimo noto', () => {
        const testo = pulito(monta({ morositaImpattanti: [
            persona(1, null, 'c\'è un pregresso di anzianità non nota'),
            persona(2, 12, 'ritardo massimo noto: 12 giorni; c\'è un pregresso di anzianità non nota'),
        ] }).text());
        expect(testo).toContain('Persona 1 — Interno 1c\'è un pregresso di anzianità non nota');
        expect(testo).toContain('ritardo massimo noto: 12 giorni; c\'è un pregresso di anzianità non nota');
        expect(testo).not.toMatch(/in ritardo di (null|0) giorni/);
    });
});

describe('il titolo del livello rosso', () => {
    test('parla della liquidità, e nel widget montato non c\'è «ingiunzione» né «moros»', () => {
        const testo = pulito(monta({ livello: 'rosso', giornoScopertoPrevisto: '2026-11-20', scopertoMaxCents: -5000, morositaImpattanti: [persona(1, 74, 'in ritardo di 74 giorni')] }).text());
        expect(testo).toContain('Liquidità insufficiente');
        expect(testo.toLowerCase()).not.toContain('ingiunzion');
        expect(testo.toLowerCase()).not.toContain('moros');
    });
});
