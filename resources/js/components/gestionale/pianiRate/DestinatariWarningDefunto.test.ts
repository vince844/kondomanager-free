// @vitest-environment jsdom

/**
 * Il cancello (2) con la voce del defunto (§6.2 della 1.11.0-beta.51, la parte della Coda 226).
 *
 * Dopo una successione con l'arretrato agli eredi, un piano generato o ricalcolato calcola ancora per il defunto i suoi giorni fino al
 * decesso: il server aggiunge al cancello una voce `defunto_nel_piano` con la frase già scritta (il defunto, la data, la cifra, la
 * parte di ogni erede, la strada della decisione 80) e la tabella degli eredi con le cifre già formattate. Il componente non ricalcola
 * niente: mostra la frase e la tabella erede / quota / parte, e tiene la voce fuori dalle coppie e dalle pregresse — non è una coppia
 * che cambia, e non conta nelle unità del titolo.
 *
 * Il contratto di `DestinatariWarning.vue`: coppie e pregresse escludono le voci del defunto (anche il conteggio delle unità); una sezione con la
 * frase del server e una tabella erede / quota / parte (cifre già formattate dal server); titolo e lettoCosa («chi cambia, come si ripartisce la
 * pregressa e le quote del defunto»). Le varianti della strada (V1, V2, V3, V4) sono testo del server, provate in PHP.
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import DestinatariWarning, { type DestinatarioCambiato } from './DestinatariWarning.vue';

const FRASE = 'La successione di Ugo (decesso del 1 maggio 2026) ha passato l\'arretrato agli eredi, ma questo piano calcola ancora per Ugo € 394,52 su Interno 2D: '
    + 'la competenza è anteriore al decesso e le rate nascono ora, fuori dall\'arretrato. Ne rispondono gli eredi, ciascuno per la sua quota (art. 754 c.c.): Anna € 236,71 e Bruno € 157,81.';

// La voce come la manda il server: motivo, unità, defunto, decesso, cifra, eredi, la strada del Wallet e la frase.
const DEFUNTO = {
    immobile_id: 8, immobile_nome: 'Interno 2D', tipologia: 'proprietario', conto_id: null, conto_nome: null, motivo: 'defunto_nel_piano', gradino: null, periodo: [],
    passaggio_id: 12, defunto: { id: 5, nome: 'Ugo' }, decesso: '2026-05-01', cifra: 39452, cifra_formattata: '€ 394,52',
    eredi: [
        { id: 6, nome: 'Anna', quota: 60, cifra: 23671, cifra_formattata: '€ 236,71' },
        { id: 7, nome: 'Bruno', quota: 40, cifra: 15781, cifra_formattata: '€ 157,81' },
    ],
    wallet: true, piano_applica_saldi: false, con_un_saldo: [], frase: FRASE,
} as unknown as DestinatarioCambiato;

const COPPIA: DestinatarioCambiato = {
    immobile_id: 7, immobile_nome: 'Interno 1C', tipologia: 'proprietario', conto_id: 3, conto_nome: 'Manutenzioni',
    motivo: 'pro_rata_giorni', gradino: 'esercizio', periodo: [{ dal: '2026-01-01', al: '2026-12-31' }],
    righe: [{ riga_id: 1, giorni: 120, anagrafica_id: 1, anagrafica_nome: 'Rossi', data_inizio: '2020-01-01', data_fine: '2026-04-30', quota: 100 }],
};

const PREGRESSA: DestinatarioCambiato = {
    immobile_id: null, immobile_nome: null, tipologia: '', conto_id: null, conto_nome: null,
    motivo: 'pregressa_senza_periodo', fattura_id: 303, fattura_numero: 'PREG-2025-17', gradino: 'esercizio',
    periodo: [{ dal: '2026-01-01', al: '2026-12-31' }],
};

const monta = (destinatari: DestinatarioCambiato[]) => mount(DestinatariWarning, { props: { destinatari } });

describe('DestinatariWarning — la voce del defunto', () => {
    test('mostra la frase scritta dal server, intera', () => {
        const testo = monta([DEFUNTO]).text();

        expect(testo).toContain('La successione di Ugo (decesso del 1 maggio 2026) ha passato l\'arretrato agli eredi');
        expect(testo).toContain('Ne rispondono gli eredi, ciascuno per la sua quota (art. 754 c.c.): Anna € 236,71 e Bruno € 157,81.');
    });

    test('la tabella degli eredi ha le colonne erede, quota e parte, e le cifre arrivano già formattate dal server', () => {
        const wrapper = monta([DEFUNTO]);
        const tabella = wrapper.findAll('table').find(t => t.text().includes('Anna'));

        expect(tabella).toBeDefined();
        const intestazioni = tabella!.findAll('th').map(th => th.text().toLowerCase());
        expect(intestazioni).toContain('erede');
        expect(intestazioni).toContain('quota dell\'unità');
        expect(intestazioni).toContain('parte');
        const testo = tabella!.text();
        expect(testo).toContain('Anna');
        expect(testo).toContain('€ 236,71');
        expect(testo).toContain('Bruno');
        expect(testo).toContain('€ 157,81');
    });

    test('la voce del defunto non è una coppia che cambia: non sta nella tabella delle coppie', () => {
        const wrapper = monta([COPPIA, DEFUNTO]);
        const coppie = wrapper.findAll('table').find(t => t.text().includes('Voce di spesa'));

        expect(coppie).toBeDefined();
        expect(coppie!.findAll('tbody tr')).toHaveLength(1);
        expect(coppie!.text()).toContain('Interno 1C');
        expect(coppie!.text()).not.toContain('Interno 2D');
    });

    test('il titolo conta le sole unità delle coppie: una coppia e un defunto su un\'altra unità sono «1 unità», non due', () => {
        const testo = monta([COPPIA, DEFUNTO]).text();

        expect(testo).toContain('cambia chi paga su 1 unità');
        expect(testo).not.toContain('cambia chi paga su 2 unità');
    });

    test('con la sola voce del defunto il titolo non parla di fatture pregresse che non ci sono', () => {
        const testo = monta([DEFUNTO]).text();

        expect(testo).not.toContain('0 fatture pregresse');
    });

    test('la nota dice cosa si è letto: chi cambia, la pregressa e le quote del defunto', () => {
        const nota = monta([COPPIA, PREGRESSA, DEFUNTO]).find('label[for="nota_destinatari"]').text();

        expect(nota).toBe('Ho letto chi cambia, come si ripartisce la pregressa e le quote del defunto. Perché procedo così?');
    });

    test('con la sola voce del defunto il titolo dice che il piano calcola ancora per un defunto, non «cambia chi paga»', () => {
        const testo = monta([DEFUNTO]).find('h3').text();

        expect(testo).toBe('Il piano calcola ancora per un defunto');
    });

    test('la quota di ogni erede si legge in percentuale, e un credito del defunto arriva già detto dal server («a credito»)', () => {
        const credito = {
            ...DEFUNTO, cifra: -39452, cifra_formattata: '€ 394,52 a credito',
            eredi: [
                { id: 6, nome: 'Anna', quota: 60, cifra: -23671, cifra_formattata: '€ 236,71 a credito' },
                { id: 7, nome: 'Bruno', quota: 33.34, cifra: -15781, cifra_formattata: '€ 157,81 a credito' },
            ],
        } as unknown as DestinatarioCambiato;
        const righe = monta([credito]).findAll('tbody tr').map(r => r.text());

        expect(righe).toHaveLength(2);
        expect(righe[0]).toContain('60 %');
        expect(righe[0]).toContain('€ 236,71 a credito');
        expect(righe[1]).toContain('33,34 %');
    });

    test('la colonna delle quote dice che è la quota ereditata sull\'unità, non la parte con cui si divide la cifra (Anna 17 % ha € 67,07 su € 134,14, non il 17 % della cifra)', () => {
        const meta = {
            ...DEFUNTO, cifra: 13414, cifra_formattata: '€ 134,14',
            eredi: [
                { id: 6, nome: 'Anna', quota: 17, cifra: 6707, cifra_formattata: '€ 67,07' },
                { id: 7, nome: 'Bruno', quota: 17, cifra: 6707, cifra_formattata: '€ 67,07' },
            ],
        } as unknown as DestinatarioCambiato;
        const tabella = monta([meta]).findAll('table')[0];

        expect(tabella.findAll('th').map(th => th.text())).toContain('Quota dell\'unità');
        expect(tabella.text()).not.toMatch(/Quota\s*Parte/);
        expect(tabella.findAll('tbody tr')[0].text()).toContain('17 %');
    });

    test('la frase passa da FraseConImporti: ogni importo sta in uno span che non va a capo, e il testo resta quello del server', () => {
        const wrapper = monta([DEFUNTO]);
        const frase = wrapper.find('[data-defunto] p');
        const importi = frase.findAll('span.whitespace-nowrap').map(s => s.text());

        expect(importi).toEqual(['€ 394,52', '€ 236,71', '€ 157,81']);
        expect(frase.text()).toBe(FRASE);
    });

    test('se la divisione fra gli eredi la decide l\'amministratore gli eredi hanno la quota e nessuna parte: la cella dice «—», non «null»', () => {
        const daDecidere = {
            ...DEFUNTO, parti_da_decidere: true,
            eredi: [
                { id: 6, nome: 'Anna', quota: 30, cifra: null, cifra_formattata: null },
                { id: 8, nome: 'Carla', quota: 50, cifra: null, cifra_formattata: null },
            ],
        } as unknown as DestinatarioCambiato;
        const righe = monta([daDecidere]).findAll('tbody tr').map(r => r.text());

        expect(righe).toHaveLength(2);
        expect(righe[0]).toContain('30 %');
        expect(righe[0]).toContain('—');
        expect(righe[0]).not.toContain('null');
    });

    test('il segnaposto della nota non suggerisce una strada: vale per il Wallet come per l\'accordo fra le parti', () => {
        const segnaposto = monta([DEFUNTO]).find('#nota_destinatari').attributes('placeholder') ?? '';

        expect(segnaposto).not.toContain('si regola fra le parti');
        expect(segnaposto).toContain('strada indicata');
    });

    test('con due unità del defunto (l\'appartamento e il box) ci sono due frasi e due tabelle, ognuna con i suoi eredi', () => {
        const box = {
            ...DEFUNTO, immobile_id: 9, immobile_nome: 'Box BX', frase: 'La successione di Ugo… su Box BX.',
            eredi: [{ id: 6, nome: 'Anna', quota: 100, cifra: 3945, cifra_formattata: '€ 39,45' }],
        } as unknown as DestinatarioCambiato;
        const wrapper = monta([DEFUNTO, box]);

        expect(wrapper.findAll('[data-defunto]')).toHaveLength(2);
        expect(wrapper.findAll('table')).toHaveLength(2);
        expect(wrapper.findAll('[data-defunto]')[1].text()).toContain('€ 39,45');
        // Le due voci sono dello stesso defunto: il titolo conta le persone, non le voci.
        expect(wrapper.find('h3').text()).toBe('Il piano calcola ancora per un defunto');
    });

    test('con due defunti diversi il titolo e la nota vanno al plurale', () => {
        const altro = { ...DEFUNTO, immobile_id: 9, defunto: { id: 9, nome: 'Rita' } } as unknown as DestinatarioCambiato;
        const wrapper = monta([DEFUNTO, altro]);

        expect(wrapper.find('h3').text()).toBe('Il piano calcola ancora per 2 defunti');
        expect(wrapper.find('label[for="nota_destinatari"]').text()).toBe('Ho letto le quote dei defunti. Perché procedo così?');
    });

    test('la nota nomina le quote del defunto anche senza coppie né pregresse, e con una coppia sola', () => {
        expect(monta([DEFUNTO]).find('label[for="nota_destinatari"]').text()).toContain('quote del defunto');
        expect(monta([COPPIA, DEFUNTO]).find('label[for="nota_destinatari"]').text()).toContain('quote del defunto');
        expect(monta([COPPIA, DEFUNTO]).find('label[for="nota_destinatari"]').text()).toContain('chi cambia');
    });
});
