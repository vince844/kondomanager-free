// @vitest-environment jsdom

/**
 * La pagina del riepilogo finanziario per competenza: dice i numeri che il server le dà, senza sommarne; quando una colonna non ha un numero dice
 * perché («non conservato», «non disponibile», «solo il deliberato»); il dettaglio per spesa si apre sulla voce; porta all'incasso da terzi e alle commissioni senza voce;
 * le date nascono `Y-m-d` e la pagina le formatta. Nessuna delle parole che il rendiconto non dice mai di sé.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generateRoute: (nome: string) => nome,
        generatePath: (p: string, parametri: Record<string, unknown> = {}) => `/${Object.entries(parametri).reduce((s, [k, v]) => s.replace(`:${k}`, String(v)), p)}`,
    }),
}));

const Pagina = (await import('./RiepilogoFinanziario.vue')).default;

const voce = (extra: Record<string, unknown>) => ({
    tipo: 'spesa', preventivo: 300000, preventivo_stato: 'conservato', deliberato: 300000, deliberato_stato: 'conservato', consuntivo: 290000, anno_prima: null,
    scostamento: -10000, scostamento_preventivo: -10000, pagato: 240000, da_pagare: 50000, ritenute_da_versare: 0, dettaglio: [], ...extra,
});

const riepilogo = (extra: Record<string, unknown> = {}) => ({
    esercizio: { id: 9, nome: 'Esercizio 2026', dal: '2026-01-01', al: '2026-12-31' },
    alla: '2026-12-31',
    finito: true,
    anno_prima: null,
    blocchi: [{
        gestione: { id: 1, nome: 'Gestione ordinaria', tipo: 'ordinaria' },
        spese: [
            voce({ voce_id: 11, nome: 'Pulizie', dettaglio: [{ tipo: 'fattura', numero: 'FT-1', fornitore: 'Pulizie Srl', descrizione: 'Scale e androne', data: '2026-04-10', consuntivo: 290000, pagato: 240000, da_pagare: 50000 }] }),
            voce({ voce_id: 12, nome: 'Manutenzioni', preventivo: null, preventivo_stato: 'non_conservato', deliberato: null, deliberato_stato: 'non_conservato', consuntivo: 122000, scostamento: null,
                pagato: 118000, da_pagare: 4000, ritenute_da_versare: 4000 }),
            voce({ voce_id: 13, nome: 'Impianti', consuntivo: 80000, pagato: 0, da_pagare: 80000, pagato_in_piu: 12000, voci: [
                voce({ voce_id: 13, nome: 'Scritto sul capitolo', proprio: true, preventivo: null, consuntivo: 60000, pagato: 0, da_pagare: 60000,
                    dettaglio: [{ tipo: 'fattura', numero: 'FT-P', fornitore: 'Pulizie Srl', descrizione: null, data: '2026-03-10', consuntivo: 60000, pagato: 0, da_pagare: 60000 }] }),
                voce({ voce_id: 15, nome: 'Scale', consuntivo: 20000, pagato: 0, da_pagare: 20000 }),
            ] }),
        ],
        entrate: [{ voce_id: 14, nome: 'Affitto antenna', tipo: 'entrata', preventivo: 24000, preventivo_stato: 'conservato', deliberato: 24000, deliberato_stato: 'conservato', incassato: 0, spese_sulla_voce: 50000, anno_prima: null,
            consuntivo: 50000, pagato: 0, da_pagare: 50000, pagato_in_piu: 0, dettaglio: [{ tipo: 'fattura', numero: 'FT-VECCHIA', fornitore: 'Fornitore Srl', descrizione: null, data: '2026-03-10', consuntivo: 50000, pagato: 0, da_pagare: 50000 }] }],
        quote: [{ riga: 'ordinarie', deliberate: 411000, emesse: 411000, pagate: 375000, non_pagate: 41000, scadute: 41000, crediti: 5000, credito_riportato: 0, emesse_altrove: 0 },
            { riga: 'esercizi_precedenti', deliberate: null, emesse: 0, pagate: 0, non_pagate: 0, scadute: 0, crediti: 15000, credito_riportato: 15000, emesse_altrove: 0 }],
        totale_spese: {},
        cumulato_opera: null,
    }],
    fuori_capitoli: {
        contestate: { consuntivo: 15000, pagato: 0, da_pagare: 15000, numero: 1 },
        commissioni_senza_voce: { consuntivo: 250, pagato: 250, da_pagare: 0, numero: 1 },
        non_classificati: { consuntivo: 0, pagato: 0, da_pagare: 0, numero: 0 },
    },
    quote: { totale: { riga: 'ordinarie', deliberate: 411000, emesse: 411000, pagate: 375000, non_pagate: 41000, scadute: 41000, crediti: 20000, credito_riportato: 15000, netto: 21000, versamenti_registro: 370000 } },
    risultato: { quote_emesse: 411000, costi: 413100, risultato: -2100 },
    raccordo_quote: { quote_piani: 411000, quote_giornale: 401000, piani_emesse_altrove: 0, altri_emesse_qui: 0, altre_differenze: 10000 },
    riconciliazione: { costi: 413100, capitoli_netti: 397850, commissioni_senza_voce: 250, contestate: 15000, non_classificati: 0, differenza: 0 },
    totali: { da_pagare: 65000, pagato_in_piu: 12000 },
    avvertenze: { perimetro_da_pagare: 'Il «da pagare» è delle fatture di questo esercizio.', pagato_diviso: 'Il pagato si divide in proporzione.', anno_prima: 'primo esercizio nel programma', deliberato: null, data: 'alla data del 31/12/2026' },
    ...extra,
});

let w: VueWrapper | null = null;
const monta = (extra: Record<string, unknown> = {}) => {
    w?.unmount();
    w = mount(Pagina, {
        props: { condominio: { id: 3, nome: 'Via Roma' }, condomini: [], esercizio: { id: 9 }, esercizi: [], riepilogo: riepilogo(extra) } as any,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true } },
    });

    return w;
};
afterEach(() => { w?.unmount(); w = null; });

const testo = (s: string) => s.replace(/ /g, ' ');

describe('il riepilogo finanziario', () => {
    test('dice i numeri del server, con l\'euro prima, e quando non ne ha uno dice perché', () => {
        const pagina = monta();
        const pulizie = testo(pagina.find('[data-voce="11"]').text());
        const manutenzioni = testo(pagina.find('[data-voce="12"]').text());

        expect(pulizie).toContain('€ 3.000,00');
        expect(pulizie).toContain('€ 2.900,00');
        expect(pulizie).toContain('€ 500,00');
        expect(manutenzioni).toContain('nel verbale dell\'assemblea');
        expect(manutenzioni).not.toContain('non conservato');
        expect(manutenzioni).toContain('non disponibile');
        expect(manutenzioni).toContain('di cui ritenute da versare € 40,00');
        expect(testo(pagina.find('[data-test="riconciliazione"]').text())).toContain('€ 4.131,00 = spese per capitolo meno entrate € 3.978,50');
        expect(testo(pagina.find('[data-test="risultato"]').text())).toContain('disavanzo');
        expect(testo(pagina.find('[data-test="come-leggere"]').text())).toContain('primo esercizio nel programma');
    });

    test('il deliberato che il programma non ha conservato dice «non disponibile» in ogni cella, anche nelle quote, con la frase che spiega perché una volta sola; il preventivo dice ancora il suo', () => {
        const frase = 'Il deliberato con le rate non è disponibile per i piani generati prima che il programma ne conservasse il dettaglio: è nel verbale dell\'assemblea che li ha approvati.';
        const quote = riepilogo().blocchi[0].quote.map((q) => (q.riga === 'ordinarie' ? { ...q, deliberate: null } : q));
        const pagina = monta({ avvertenze: { ...riepilogo().avvertenze, deliberato: frase }, blocchi: [{ ...riepilogo().blocchi[0], quote }] });
        // Il testo con uno spazio fra una cella e l'altra, come si legge a video.
        const parole = (el: { html: () => string }) => el.html().replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
        const manutenzioni = parole(pagina.find('[data-voce="12"]'));

        // La voce non ha il deliberato (non disponibile) e non ha il preventivo (la voce porta già quello dell'anno dopo): due colonne, due cose diverse.
        expect(manutenzioni).toContain('nel verbale dell\'assemblea non disponibile € 1.220,00');
        expect(parole(pagina.find('[data-test="quote"]'))).toContain('Quote ordinarie non disponibile € 4.110,00');
        expect(parole(pagina.find('[data-test="quote"]'))).toContain('Esercizi precedenti — € 0,00');
        expect(testo(pagina.find('[data-test="deliberato-non-disponibile"]').text())).toBe(frase);
        expect(pagina.findAll('[data-test="deliberato-non-disponibile"]')).toHaveLength(1);
        expect(testo(pagina.text())).not.toContain('Quote ordinarie non conservato');

        // Con il deliberato di ogni voce la frase non c'è, e nessuna cella dice «non disponibile».
        const spese = riepilogo().blocchi[0].spese.map((v: any) => (v.voce_id === 12 ? { ...v, deliberato: 120000, deliberato_stato: 'conservato' } : v));
        const con = monta({ blocchi: [{ ...riepilogo().blocchi[0], spese }] });
        expect(con.find('[data-test="deliberato-non-disponibile"]').exists()).toBe(false);
        expect(parole(con.find('[data-test="quote"]'))).toContain('Quote ordinarie € 4.110,00 € 4.110,00');
        expect(testo(con.text())).not.toContain('non disponibile');
    });

    test('il dettaglio per spesa si apre sulla voce, con la data formattata dalla pagina', async () => {
        const pagina = monta();
        expect(pagina.findAll('[data-test="dettaglio"]')).toHaveLength(0);

        await pagina.find('[data-voce="11"] button').trigger('click');
        const riga = testo(pagina.find('[data-test="dettaglio"]').text());

        expect(riga).toContain('10/04/2026');
        expect(riga).toContain('FT-1');
        expect(riga).toContain('Pulizie Srl');
    });

    test('porta all\'incasso da terzi per una voce di entrata senza incassi, e alle commissioni senza voce', () => {
        const pagina = monta();
        const link = pagina.findAll('a').map((a) => [a.text(), a.attributes('href')]);

        expect(link).toContainEqual(['Registra l\'incasso', '/gestionale/3/incassi-da-terzi/create?voce=14']);
        expect(link).toContainEqual(['Assegna la voce', '/gestionale/3/esercizi/9/commissioni-senza-voce']);
        expect(link).toContainEqual(['Situazione patrimoniale', '/gestionale/3/esercizi/9/stato-patrimoniale']);
    });

    test('le quote pagate accanto ai versamenti dei condòmini del registro, con il perché possono essere diversi', () => {
        const pagina = monta();
        const riga = testo(pagina.find('[data-test="pagate-e-registro"]').text());

        expect(riga).toContain('sono € 3.750,00');
        expect(riga).toContain('di questo esercizio sono € 3.700,00');
        expect(riga).toContain('una compensazione non passa dalla cassa');
        expect(testo(pagina.find('[data-test="quote"]').text())).toContain('di cui scadute € 410,00');
    });

    test('la spesa scritta sul capitolo ha la sua riga, con il suo dettaglio; il pagato in più sta a parte', async () => {
        const pagina = monta();
        expect(testo(pagina.find('[data-voce="13"]').text())).toContain('pagato in più € 120,00');

        await pagina.find('[data-voce="13"] button').trigger('click');
        const proprio = testo(pagina.find('[data-proprio="13"]').text());
        expect(proprio).toContain('Scritto sul capitolo');
        expect(proprio).toContain('€ 600,00');
        expect(pagina.findAll('[data-test="dettaglio"]').map((d) => testo(d.text())).join(' ')).toContain('FT-P');
    });

    test('le spese scritte su una voce di entrata si vedono, con il loro da pagare', () => {
        const riga = testo(monta().find('[data-spese-entrata="14"]').text());

        expect(riga).toContain('Spese scritte sulla voce di entrata: € 500,00, pagate € 0,00, da pagare € 500,00');
        expect(riga).toContain('FT-VECCHIA');
    });

    test('il credito riportato è una colonna sua, e la frase delle pagate lo dice fuori dalle pagate', () => {
        const pagina = monta();
        expect(testo(pagina.find('[data-test="quote"]').text())).toContain('€ 150,00');
        const frase = testo(pagina.find('[data-test="pagate-e-registro"]').text());
        expect(frase).toContain('Il credito riportato da un esercizio prima (€ 150,00) non è fra le pagate');
        expect(frase).toContain('gli storni degli incassi');
    });

    test('le altre differenze del raccordo delle quote hanno un nome, e le pregresse la loro nota', () => {
        const pagina = monta();
        expect(testo(pagina.find('[data-test="raccordo-quote"]').text())).toContain('€ 100,00 di debiti riportati dentro le rate');
        expect(testo(pagina.find('[data-test="pregresse"]').text())).toContain('non sono costi di questo esercizio');
    });

    test('nessuna delle parole che il rendiconto non dice di sé', () => {
        const tutto = monta().text().toLowerCase();

        for (const parola of ['pronto', 'conforme', 'corretto', 'approvabile', 'in regola', 'tutto in ordine']) {
            expect(tutto).not.toContain(parola);
        }
    });
});
