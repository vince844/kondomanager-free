// @vitest-environment jsdom

/**
 * Il widget «Tesoreria a 30gg» con lo scaduto dei condòmini: gli incassi attesi divisi in rate già scadute e in scadenza, le
 * persone in ritardo (le prime cinque, con il collegamento al loro estratto conto) e «e altri N» con lo scaduto per fasce; le
 * fatture dei fornitori scadute da prima si chiamano per quello che sono. Parole: «scaduto», «in ritardo di N giorni».
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
    morositaImpattanti: [] as any[], morositaAltri: 0, scadutoPerFasce: { '1_30': 0, '31_90': 0, oltre_90: 0 },
};
const persona = (n: number, importo: number, giorni: number) => ({
    anagraficaId: n, condomino: `Persona ${n}`, immobile: `Interno ${n}`, importoCents: importo, giorniRitardo: giorni,
    route: 'admin.gestionale.anagrafiche.estratto-conto', routeParams: { condominio: 7, anagrafica: n },
});
const monta = (treasury: Record<string, unknown>) => mount(TreasuryGuardianWidget, { props: { treasury: { ...base, ...treasury } as any } });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('gli incassi attesi, divisi', () => {
    test('le rate già scadute e quelle in scadenza entro 30 giorni, ognuna con la sua cifra', () => {
        const testo = pulito(monta({}).text());

        expect(testo).toMatch(/Rate scadute\s*al netto del credito di ognuno nella stessa gestione\s*\+€ 150,00/);
        expect(testo).toMatch(/Rate in scadenza\s*\+€ 100,00/);
        expect(testo).not.toContain('Rate in Arr.');
    });
});

describe('le persone in ritardo', () => {
    test('ognuna con l\'unità, lo scaduto, i giorni di ritardo e il collegamento al suo estratto conto; poi «e altri N» e le fasce', () => {
        const w = monta({
            morositaImpattanti: [persona(1, 15000, 74), persona(2, 5000, 1)],
            morositaAltri: 3,
            scadutoPerFasce: { '1_30': 6000, '31_90': 15000, oltre_90: 2000 },
        });
        const testo = pulito(w.text());

        expect(testo).toContain('Persona 1 — Interno 1');
        expect(testo).toContain('€ 150,00');
        expect(testo).toContain('in ritardo di 74 giorni');
        expect(testo).toContain('in ritardo di 1 giorno');
        expect(w.findAll('a').map((a) => a.attributes('href'))).toContain('/admin.gestionale.anagrafiche.estratto-conto/7/1');
        expect(testo).toContain('e altri 3');
        expect(testo).toContain('1–30 giorni € 60,00');
        expect(testo).toContain('31–90 giorni € 150,00');
        expect(testo).toContain('oltre 90 giorni € 20,00');
        expect(testo.toLowerCase()).not.toMatch(/moros|esigibil|diffidat|ingiungibil/);
    });

    test('senza persone in ritardo la sezione non c\'è', () => {
        expect(pulito(monta({}).text())).not.toContain('in ritardo di');
    });
});

describe('le fatture dei fornitori scadute da prima', () => {
    test('si chiamano «Fatture pregresse non pagate»', () => {
        const testo = pulito(monta({ debitiPregressiScadutiCents: 150000 }).text());

        expect(testo).toContain('Fatture pregresse non pagate');
        expect(testo).not.toContain('Pregressi non pagati');
    });
});

describe('le due cifre dello scaduto non si contraddicono', () => {
    test('«Rate scadute» dice che è al netto dei crediti; l\'elenco dice che è lordo e mostra il credito di ognuno', () => {
        const w = monta({
            incassiScadutiCents: 0,
            morositaImpattanti: [{ ...persona(1, 20000, 74), creditoCents: 20000 }, { ...persona(2, 5000, 1), creditoCents: 0 }],
        });
        const testo = pulito(w.text());

        expect(testo).toContain('al netto del credito di ognuno nella stessa gestione');
        expect(testo).toContain('Lo scaduto di ognuno, senza togliere il suo credito');
        expect(testo).toContain('credito € 200,00');
        // Chi non ha credito non ha la riga.
        expect(testo.match(/credito € /g)).toHaveLength(1);
    });

    test('«e altri N» dice dove si vede lo scaduto di tutti', () => {
        const testo = pulito(monta({ morositaImpattanti: [persona(1, 15000, 74)], morositaAltri: 3 }).text());

        expect(testo).toContain('e altri 3: lo scaduto di tutti è nelle fasce qui sotto');
    });
});
