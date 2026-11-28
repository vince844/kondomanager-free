// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'elenco dei piani che cambiano dopo una quota corretta (`AnagraficheList.vue`, prop `piani_che_cambiano` da `PianiCheCambiano`), come la bozza approvata
 * `beta15_piani_che_cambiano_bozza.html`: la riga salvata, quanti piani chiedono cifre diverse, per ogni piano chi paga quanto prima e dopo, il totale del
 * piano, la strada secondo lo stato, «Apri il piano», «Crea l'addebito» solo con incassi, e la guida a cassetto.
 *
 * Cosa NON copre: il server che legge i piani (`QuotaCorrettaPianiCheCambianoTest.php`); la finestra dell'addebito; il modo scuro e le larghezze.
 */

import { describe, expect, test, vi } from 'vitest';
import { shallowMount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));

(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const AnagraficheList = (await import('./AnagraficheList.vue')).default;

const PREVENTIVO = {
    piano_rate_id: 3, nome: 'Preventivo 2026', esercizio_id: 1, stato: 'emesso',
    righe: [
        { anagrafica_id: 11, nome: 'Rossi Costruzioni', prima: 0, dopo: 35342, differenza: 35342 },
        { anagrafica_id: 12, nome: 'Neri Bice', prima: 64658, dopo: 64658, differenza: 0 },
    ],
    totale: { prima: 64658, dopo: 100000, differenza: 35342 }, addebiti: [],
};
const INTEGRATIVO = {
    piano_rate_id: 4, nome: 'Integrativo 2026', esercizio_id: 1, stato: 'con_incassi',
    righe: [{ anagrafica_id: 11, nome: 'Rossi Costruzioni', prima: 0, dopo: 4210, differenza: 4210 }],
    totale: { prima: 10000, dopo: 14210, differenza: 4210 }, addebiti: [{ anagrafica_id: 11, nome: 'Rossi Costruzioni', importo: 4210 }],
};
const RIGA = { unita: 'Interno 5', persona: 'Rossi Costruzioni', tipologia: 'proprietario', dal: '2026-01-01', al: '2026-09-15', quota_prima: 0, quota_dopo: 100 };

function monta(dati: unknown) {
    return shallowMount(AnagraficheList, {
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never,
            immobile: { id: 5, nome: 'Interno 5', interno: '5', anagrafiche: [] } as never,
            storico: { passaggi: 0, periodi_chiusi: 0, righe: [], gruppi: [], subentri: [] } as never,
            oggi: '2026-11-24',
            prospettoOneri: null,
            piani_che_cambiano: dati as never,
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });
}

const testo = (w: ReturnType<typeof monta>, sel: string) => w.find(sel).text().replace(/\s+/g, ' ').replace(/ /g, ' ');
/** Le righe della tabella di un piano, cella per cella: «Rossi Costruzioni | € 0,00 | € 353,42 | + € 353,42». */
const righe = (w: ReturnType<typeof monta>) => w.findAll('[data-testid="piano-che-cambia"] tbody tr').map((tr) => tr.findAll('td').map((td) => td.text().replace(/ /g, ' ')).join(' | '));

describe('AnagraficheList — i piani che cambiano dopo una quota corretta', () => {
    test('la riga salvata e quanti piani chiedono cifre diverse', () => {
        const t = testo(monta({ riga: RIGA, piani: [PREVENTIVO, INTEGRATIVO] }), '[data-testid="piani-che-cambiano"]');

        expect(t).toContain('Quota salvata: Interno 5');
        expect(t).toContain('Rossi Costruzioni, proprietà fino al 15/09/2026: da 0 % a 100 %');
        // Il confronto è il ricalcolo intero con i dati di oggi (`PianiCheCambiano::confronta`), non la sola quota: la frase lo dice.
        expect(t).toContain('2 piani chiederebbero cifre diverse se si ricalcolassero oggi.');
        expect(t).toContain('Il confronto rifà il calcolo con i dati di oggi: oltre alla quota salvata conta ogni altra cosa cambiata dopo la generazione del piano, come le voci, i millesimi o i saldi.');
        expect(t).not.toContain('con la quota di oggi');
        expect(t).toContain('Il programma non cambia niente da solo: per ogni piano c\'è la strada.');
    });

    test('emesso senza incassi: chi paga quanto, il totale del piano e la strada; niente addebito', () => {
        const w = monta({ riga: RIGA, piani: [PREVENTIVO] });
        const t = testo(w, '[data-testid="piano-che-cambia"]');

        expect(t).toContain('Emesso, senza incassi');
        expect(righe(w)).toEqual([
            'Rossi Costruzioni | € 0,00 | € 353,42 | + € 353,42',
            'Neri Bice | € 646,58 | € 646,58 | € 0,00',
            'Totale del piano | € 646,58 | € 1.000,00 | + € 353,42',
        ]);
        expect(t).toContain('Strada: annulla l\'emissione delle rate, poi «Ricalcola», poi emetti di nuovo.');
        expect(t).toContain('Apri il piano');
        expect(w.find('[data-testid="crea-addebito"]').exists()).toBe(false);
    });

    test('con incassi: l\'addebito individuale per la differenza, e il pulsante che lo crea', () => {
        const w = monta({ riga: RIGA, piani: [INTEGRATIVO] });

        expect(testo(w, '[data-testid="piano-che-cambia"]')).toContain('Gli incassi registrati non si toccano. Strada: un addebito individuale di € 42,10 a Rossi Costruzioni.');
        expect(w.find('[data-testid="crea-addebito"]').text()).toBe('Crea l\'addebito');
    });

    test('in bozza: «Ricalcola»', () => {
        const t = testo(monta({ riga: RIGA, piani: [{ ...PREVENTIVO, stato: 'bozza' }] }), '[data-testid="piano-che-cambia"]');

        expect(t).toContain('In bozza');
        expect(t).toContain('Strada: «Ricalcola» dalla pagina del piano.');
    });

    test('la guida a cassetto si apre con le quattro righe', async () => {
        const w = monta({ riga: RIGA, piani: [PREVENTIVO] });
        expect(w.find('[data-testid="guida-quota-corretta"]').exists()).toBe(false);
        await w.findAll('button').find((b) => b.text() === 'Guida · Una quota corretta')!.trigger('click');

        const t = testo(w, '[data-testid="guida-quota-corretta"]');
        expect(t).toContain('Che cosa confronta: le quote scritte nel piano e il ricalcolo con i dati di oggi, la quota salvata e tutto il resto.');
        expect(t).toContain('Con incassi: un addebito individuale per la differenza.');
    });

    test('senza piani che cambiano l\'elenco non c\'è', () => {
        expect(monta(null).find('[data-testid="piani-che-cambiano"]').exists()).toBe(false);
        expect(monta({ riga: RIGA, piani: [] }).find('[data-testid="piani-che-cambiano"]').exists()).toBe(false);
    });
});
