// @vitest-environment jsdom

/**
 * Il cancello (2) con le pregresse senza periodo (decisione 26, 1.11.0-beta.35) — R1 e R12 della Fase 1-bis.
 *
 * R1: il consiglio «storna la fattura e registrala di nuovo» è sicuro solo alla creazione, dove la generazione si annulla
 * e la fattura non resta agganciata. Su un piano che esiste già la fattura è nel piano: stornata e basta, le rate la
 * chiedevano ancora. Lì il testo dice il percorso completo — e lo storno, da questa beta, lo pretende.
 *
 * R12: con le sole pregresse nessun titolare cambia, e la nota non può chiedere «Ho letto chi cambia».
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import DestinatariWarning, { type DestinatarioCambiato } from './DestinatariWarning.vue';

const PREGRESSA: DestinatarioCambiato = {
    immobile_id: null, immobile_nome: null, tipologia: '', conto_id: null, conto_nome: null,
    motivo: 'pregressa_senza_periodo', fattura_id: 303, fattura_numero: 'PREG-2025-17', gradino: 'esercizio',
    periodo: [{ dal: '2026-01-01', al: '2026-12-31' }],
};

const COPPIA: DestinatarioCambiato = {
    immobile_id: 7, immobile_nome: 'Interno 1C', tipologia: 'proprietario', conto_id: 3, conto_nome: 'Manutenzioni',
    motivo: 'pro_rata_giorni', gradino: 'esercizio', periodo: [{ dal: '2026-01-01', al: '2026-12-31' }],
    righe: [{ riga_id: 1, giorni: 120, anagrafica_id: 1, anagrafica_nome: 'Rossi', data_inizio: '2020-01-01', data_fine: '2026-04-30', quota: 100 }],
};

const monta = (destinatari: DestinatarioCambiato[], pianoEsistente?: boolean) =>
    mount(DestinatariWarning, { props: { destinatari, ...(pianoEsistente === undefined ? {} : { pianoEsistente }) } });

describe('DestinatariWarning — la pregressa senza periodo', () => {
    test('R1: su un piano che esiste già dice il percorso completo — eliminare il piano prima dello storno', () => {
        const testo = monta([PREGRESSA], true).text();

        expect(testo).toContain('elimina questo piano (se è approvato, prima riportalo in bozza)');
        expect(testo).toContain('storna la fattura');
        expect(testo).toContain('crea di nuovo il piano');
        expect(testo).not.toContain('e poi crea il piano');
    });

    test('R1: alla creazione la generazione si annulla, e basta stornare, registrare di nuovo e poi creare il piano', () => {
        const testo = monta([PREGRESSA]).text();

        expect(testo).toContain('storna la fattura, registrala di nuovo con il periodo e poi crea il piano');
        // La frase vera della variante da escludere: «eliminalo» non compare in nessuna delle due, e il test non provava niente.
        expect(testo).not.toContain('elimina questo piano');
    });

    test('R12: con le sole pregresse la nota non chiede «chi cambia» e l\'esempio non parla di un rogito', () => {
        const wrapper = monta([PREGRESSA], true);

        expect(wrapper.find('label[for="nota_destinatari"]').text()).toBe('Ho letto come si ripartisce la pregressa. Perché procedo così?');
        expect(wrapper.find('#nota_destinatari').attributes('placeholder')).not.toContain('rogito');
    });

    test('R12: con le coppie resta «Ho letto chi cambia», e con tutte e due le cose la nota le nomina entrambe', () => {
        expect(monta([COPPIA]).find('label[for="nota_destinatari"]').text()).toBe('Ho letto chi cambia. Perché procedo così?');
        expect(monta([COPPIA, PREGRESSA]).find('label[for="nota_destinatari"]').text()).toBe('Ho letto chi cambia e come si ripartisce la pregressa. Perché procedo così?');
    });

    test('con il gradino «delibera» la seconda frase non dice «una parte»: la regola è a gradino, come nel carrello', () => {
        const testo = monta([{ ...PREGRESSA, gradino: 'delibera', periodo: [{ dal: '2026-06-15', al: '2026-06-15' }] }], true).text();

        expect(testo).toContain('la pagherebbe per intero chi era titolare alla delibera');
        expect(testo).not.toContain('una parte la pagherebbe');
    });

    test('richiesta di Vincenzo (27/09): la pregressa si riconosce subito — fornitore e importo accanto al numero, e il numero apre il dettaglio con il collegamento', async () => {
        const conDettaglio: DestinatarioCambiato = {
            ...PREGRESSA,
            fattura: {
                id: 303, numero: 'PREG-2025-17', fornitore: 'Manutenzioni Prova Srl', data_documento: '20/11/2025',
                totale_formattato: '€ 600,00', nel_piano_formattato: '€ 600,00', voce: 'Spese generali',
                url: '/admin/gestionale/159/fatture/303',
            },
        };
        const wrapper = mount(DestinatariWarning, {
            props: { destinatari: [conDettaglio], pianoEsistente: true },
            global: {
                stubs: {
                    Dialog: { props: ['open'], template: '<div v-if="open" data-finestra><slot /></div>' },
                    DialogContent: { template: '<div><slot /></div>' }, DialogHeader: { template: '<div><slot /></div>' },
                    DialogTitle: { template: '<h2><slot /></h2>' }, DialogDescription: { template: '<p><slot /></p>' },
                },
            },
        });

        expect(wrapper.text()).toContain('Manutenzioni Prova Srl');
        expect(wrapper.text()).toContain('€ 600,00');
        expect(wrapper.find('[data-finestra]').exists()).toBe(false);

        await wrapper.find('button[data-fattura="303"]').trigger('click');

        const finestra = wrapper.find('[data-finestra]');
        expect(finestra.exists()).toBe(true);
        expect(finestra.text()).toContain('20/11/2025');
        expect(finestra.text()).toContain('Spese generali');
        expect(finestra.find('a[href="/admin/gestionale/159/fatture/303"]').attributes('target')).toBe('_blank');
    });
});
