// @vitest-environment jsdom

/**
 * Le parole vietate nella «Tesoreria a 30 giorni», letta montata (il testo che si vede, non il sorgente, dove «moros» c'è nei nomi delle proprietà): nei tre
 * livelli del semaforo, con i due cassetti aperti (le fatture e le persone, con le frasi della regola dei sei mesi), le fasce, le fatture senza scadenza e
 * quelle pregresse, il fondo senza somme, e «Da dove viene» montato con le sue frasi. La lista è quella delle pagine delle rate scadute e delle lettere. Anche
 * il testo dei tooltip, che si vede al passaggio del mouse (i componenti del tooltip resi con il loro contenuto). E nessun «passo» a video.
 */
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: vi.fn(), get: vi.fn(), reload: vi.fn() },
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, hasPermission: () => true }) }));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${Object.values(p ?? {}).join('/')}`;

import TreasuryGuardianWidget from './TreasuryGuardianWidget.vue';
import { persona, tesoreria } from './tesoreriaDiProva';

const VIETATE = ['moros', 'esigibile', 'diffidat', 'è in mora', 'costituito in mora', 'in mora dal', 'ingiungibil', 'ingiunzion', 'decadut',
    'termine scaduto', 'grave irregolarità', 'consegnata', 'recapitata', 'notificata', 'valore legale', 'novazione', 'transazione'];

// Le azioni con le parole che il server scrive oggi (`TreasuryGuardianService::generaAzioniSuggerite`): la riga delle rate scadute non si mostra più (c'è il
// cassetto), le altre due diventano collegamenti nell'avviso e nelle fatture pregresse.
const azioni = [
    { tipo: 'sollecito', label: 'Vedi le rate scadute', descrizione: 'Chi deve ancora pagare, persona per persona: prima di scrivere, registra i versamenti già arrivati in banca',
        impattoCents: 25000, route: 'admin.gestionale.rate-scadute.index', routeParams: { condominio: 7 } },
    { tipo: 'emissione_rate', label: 'Emetti Nuove Rate', descrizione: 'Non ci sono incassi attesi sufficienti per coprire le uscite', impattoCents: 0,
        route: 'admin.gestionale.esercizi.index', routeParams: { condominio: 7 } },
    { tipo: 'gestione_pregressi', label: 'Gestisci Debiti Pregressi', descrizione: 'Hai esposizioni storiche che non rientrano nel budget corrente', impattoCents: 0,
        route: 'admin.gestionale.fatture.index', routeParams: { condominio: 7, pregresso: 1 } },
];
const frase = (caso: string, passo: string | null, testo: string) => ({ caso, passo, frase: testo, frase_dei_fatti: testo, note: [], rata_compensa: null });
const tesoreriaDelLivello = (livello: 'verde' | 'giallo' | 'rosso', altro: Record<string, unknown> = {}) => tesoreria({
    livello, giornoScopertoPrevisto: livello === 'verde' ? null : '2026-11-18', debitiPregressiScadutiCents: 30000, azioniSuggerite: azioni,
    fattureSenzaScadenza: [{ fornitore: 'Pulizie Snc', numero: '3', importoCents: 20000 }],
    morositaImpattanti: [
        persona(1, { creditoCents: 2000, passo: frase('R9', 'fascicolo', 'Diffida spedita il 03/10/2026: il termine del 18/10/2026 è passato.') }),
        persona(2, { giorniRitardo: null, ritardoTesto: 'c\'è un pregresso di anzianità non nota', passo: frase('R3', null, 'Accordo di rientro del 10/01/2026, fino al 30/09/2026: una rata non versata.') }),
        persona(3, { passo: frase('R15', 'sollecito', 'Nessuna lettera.') }),
        persona(4, { passo: frase('dispensa', null, 'Dispensa dell\'assemblea del 01/03/2026: niente solleciti, come hai scelto.') }),
    ],
    morositaPersone: 9, morositaAltri: 5, morositaAltriCents: 41520, scadutoPerFasce: { '1_30': 10000, '31_90': 20000, oltre_90: 50000 },
    ...altro,
});

// I tooltip resi con il loro contenuto: il testo che compare al passaggio del mouse si legge come il resto; «Da dove viene» montato dentro il riquadro.
const conITooltip = { global: { stubs: {
    TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' },
    TooltipTrigger: { template: '<span><slot /></span>' }, TooltipContent: { template: '<div data-testid="tooltip"><slot /></div>' },
    Popover: { template: '<div><slot /></div>' }, PopoverTrigger: { template: '<div><slot /></div>' }, PopoverContent: { template: '<div><slot /></div>' },
    LettereDialog: true,
} } };
async function montato(t: Record<string, unknown>) {
    const w = mount(TreasuryGuardianWidget, { props: { treasury: t as any }, ...conITooltip });
    for (const c of w.findAll('[aria-expanded="false"]')) {
        await c.trigger('click');
    }

    return w;
}
const vietateIn = (testo: string) => VIETATE.filter((p) => testo.replace(/\s+/g, ' ').toLowerCase().includes(p));

describe('la tesoreria montata', () => {
    test.each(['verde', 'giallo', 'rosso'] as const)('livello %s: nessuna parola vietata nel testo che si vede, e nessun «passo»', async (livello) => {
        const w = await montato(tesoreriaDelLivello(livello));
        // I tooltip ci sono: la tesoreria, le entrate attese, le uscite previste.
        expect(w.findAll('[data-testid="tooltip"]').length).toBeGreaterThanOrEqual(3);
        const testo = w.text().replace(/\s+/g, ' ').toLowerCase();
        // Il testo c'è davvero: le persone, le frasi della regola, «Da dove viene», le fasce, le anomalie.
        expect(testo).toContain('persona 1');
        expect(testo).toContain('diffida spedita il 03/10/2026');
        expect(testo).toContain('vedi tutte le rate scadute');
        expect(testo).toContain('disponibile oggi: da dove viene');
        expect(testo).toContain('fatture pregresse non pagate');
        expect(testo).toContain('1 fattura senza data di scadenza');
        expect(VIETATE.filter((p) => testo.includes(p))).toEqual([]);
        expect(testo).not.toMatch(/\bpass[oi]\b/);
        w.unmount();
    });

    test('il fondo senza somme e la tesoreria senza persone: nessuna parola vietata', async () => {
        const senzaSomme = await montato(tesoreriaDelLivello('giallo', { disponibile: { ...(tesoreria().disponibile as object), righe: [
            { voce: 'Banca Intesa', tipo: 'banca', saldo: 448038, cassa_id: 1, conto_contabile_id: 11, attiva: true, etichetta: null },
            { voce: 'Fondo di riserva', tipo: 'fondo', saldo: 0, cassa_id: 6, conto_contabile_id: 16, attiva: true, etichetta: { testo: 'tipo non indicato: conta come vincolato', tono: 'non_indicato' } },
        ], tolte: [], totale: 448038, cifra: 448038 }, liquiditaVincolataCents: 0 }));
        expect(senzaSomme.text()).toContain('Il fondo riserva non ha somme accantonate');
        expect(vietateIn(senzaSomme.text())).toEqual([]);
        senzaSomme.unmount();
        const senzaPersone = await montato(tesoreriaDelLivello('verde', { morositaImpattanti: [], morositaPersone: 0, morositaAltri: 0, azioniSuggerite: [] }));
        expect(senzaPersone.find('[data-testid="cassetto-persone"]').exists()).toBe(false);
        expect(vietateIn(senzaPersone.text())).toEqual([]);
        senzaPersone.unmount();
    });
});
