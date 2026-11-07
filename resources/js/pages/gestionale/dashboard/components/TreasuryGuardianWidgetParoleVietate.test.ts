// @vitest-environment jsdom

/**
 * Le parole vietate nel widget «Tesoreria a 30gg», letto montato (il testo che si vede, non il sorgente, dove «moros» c'è nei nomi delle
 * proprietà): nei tre livelli del semaforo, con le persone in ritardo, le fasce, le fatture, le azioni suggerite con le parole che il server
 * scrive, il fondo di riserva. La lista è quella delle pagine delle rate scadute e delle lettere. Anche il testo dei tooltip, che si vede al
 * passaggio del mouse (i componenti del tooltip resi con il loro contenuto), e i rami dei fondi vincolati e di «nessuna azione».
 */
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: vi.fn(), get: vi.fn() },
}));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${Object.values(p ?? {}).join('/')}`;

import TreasuryGuardianWidget from './TreasuryGuardianWidget.vue';

const VIETATE = ['moros', 'esigibile', 'diffidat', 'è in mora', 'costituito in mora', 'in mora dal', 'ingiungibil', 'ingiunzion', 'decadut',
    'termine scaduto', 'grave irregolarità', 'consegnata', 'recapitata', 'notificata', 'valore legale', 'novazione', 'transazione'];

const persona = (n: number, giorni: number | null, ritardoTesto: string) => ({
    anagraficaId: n, condomino: `Persona ${n}`, immobile: `Interno ${n}`, importoCents: 50000, giorniRitardo: giorni, ritardoTesto, creditoCents: n === 1 ? 2000 : 0,
    route: 'admin.gestionale.anagrafiche.estratto-conto', routeParams: { condominio: 7, anagrafica: n },
});
// Le azioni con le parole che il server scrive oggi (`TreasuryGuardianService::generaAzioniSuggerite`).
const azioni = [
    { tipo: 'sollecito_incassi', label: 'Vedi le rate scadute', descrizione: 'Chi deve ancora pagare, persona per persona: prima di scrivere, registra i versamenti già arrivati in banca',
        impattoCents: 25000, route: 'admin.gestionale.rate-scadute.index', routeParams: { condominio: 7 } },
    { tipo: 'emissione_rate', label: 'Emetti Nuove Rate', descrizione: 'Non ci sono incassi attesi sufficienti per coprire le uscite', impattoCents: 0,
        route: 'admin.gestionale.esercizi.index', routeParams: { condominio: 7 } },
    { tipo: 'gestione_pregressi', label: 'Gestisci Debiti Pregressi', descrizione: 'Hai esposizioni storiche che non rientrano nel budget corrente', impattoCents: 0,
        route: 'admin.gestionale.fatture.index', routeParams: { condominio: 7, pregresso: 1 } },
];
const tesoreria = (livello: 'verde' | 'giallo' | 'rosso') => ({
    liquiditaTotaleCents: 100000, liquiditaVincolataCents: 0, liquiditaDisponibileCents: 100000, uscitePredittiveCents: 150000,
    incassiAttesiCents: 25000, incassiScadutiCents: 15000, incassiInScadenzaCents: 10000, debitiPregressiScadutiCents: 30000,
    scenarioPessimisticoCents: -50000, scenarioOttimisticoCents: 125000, giornoScopertoPrevisto: livello === 'verde' ? null : '2026-11-20', scopertoMaxCents: -5000, livello,
    fattureInScadenza: [{ fornitore: 'Ascensori Srl', numero: '12', dataScadenza: '2026-11-15', importoCents: 150000 }],
    azioniSuggerite: azioni,
    fattureSenzaScadenza: [{ fornitore: 'Pulizie Snc', numero: '3', importoCents: 20000 }],
    hasFondoRiserva: true,
    morositaImpattanti: [persona(1, 74, 'in ritardo di 74 giorni'), persona(2, null, 'c\'è un pregresso di anzianità non nota'),
        persona(3, 12, 'ritardo massimo noto: 12 giorni; c\'è un pregresso di anzianità non nota')],
    morositaAltri: 4,
    scadutoPerFasce: { '1_30': 10000, '31_90': 20000, oltre_90: 50000 },
    elencoRateScadute: { route: 'admin.gestionale.rate-scadute.index', routeParams: { condominio: 7 } },
});

// I tooltip resi con il loro contenuto: il testo che compare al passaggio del mouse si legge come il resto.
const conITooltip = { global: { stubs: {
    TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' },
    TooltipTrigger: { template: '<span><slot /></span>' }, TooltipContent: { template: '<div data-testid="tooltip"><slot /></div>' },
} } };
const montato = (t: Record<string, unknown>) => mount(TreasuryGuardianWidget, { props: { treasury: t as any }, ...conITooltip });
const vietateIn = (testo: string) => VIETATE.filter((p) => testo.replace(/\s+/g, ' ').toLowerCase().includes(p));

describe('il widget della Tesoreria montato', () => {
    test.each(['verde', 'giallo', 'rosso'] as const)('livello %s: nessuna parola vietata nel testo che si vede', (livello) => {
        const w = montato(tesoreria(livello));
        // I tooltip ci sono: il semaforo, la simulazione, il fondo.
        expect(w.findAll('[data-testid="tooltip"]').length).toBeGreaterThanOrEqual(3);
        const testo = w.text().replace(/\s+/g, ' ').toLowerCase();
        // Il testo c'è davvero: le persone, le azioni e le fasce sono montate.
        expect(testo).toContain('persona 1');
        expect(testo).toContain('vedi tutte le rate scadute');
        expect(testo).toContain('gestisci debiti pregressi');
        expect(testo).toContain('1 fattura senza data di scadenza');
        expect(VIETATE.filter((p) => testo.includes(p))).toEqual([]);
        w.unmount();
    });

    test('i fondi vincolati e «nessuna azione»: nessuna parola vietata', () => {
        const vincolati = montato({ ...tesoreria('giallo'), liquiditaVincolataCents: 30000, liquiditaDisponibileCents: 70000 });
        expect(vincolati.text()).toContain('Riserve non disponibili per l\'ordinaria');
        expect(vietateIn(vincolati.text())).toEqual([]);
        vincolati.unmount();
        const senzaAzioni = montato({ ...tesoreria('verde'), azioniSuggerite: [] });
        expect(senzaAzioni.text()).toContain('Nessuna azione richiesta');
        expect(vietateIn(senzaAzioni.text())).toEqual([]);
        senzaAzioni.unmount();
    });
});
