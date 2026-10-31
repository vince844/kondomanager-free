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

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { compile } from 'tailwindcss';
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

/**
 * Decisione 170: la parte due volte (Coda 332, decisione 164.1) in una frase sola e una tabella. Il server manda la frase con i nomi e la strada e la
 * chiave `due_serie`, una riga per persona: la serie legata alla successione e quella non legata, ognuna con la cifra già detta con il verso, gli
 * esercizi e se il Wallet la mostra, e `esercizio` quando le due stanno nello stesso esercizio. Il componente non ricalcola niente: mette le cifre
 * e gli esercizi nelle colonne e dice, dai due «nel Wallet» del server, quale serie c'è nel Wallet. Le frasi delle varianti sono provate in PHP
 * (`Beta60ChiusuraDefuntoDueSerieTest`); qui le colonne, adattate alle varianti. Decisione 171: «tutte e due» al posto di «sì» quando nel Wallet ci sono
 * tutte e due; «ed» davanti a «Esercizio» quando due esercizi si dicono insieme; sotto la larghezza `sm` una scheda per persona al posto della tabella
 * (più sotto, nel suo `describe`).
 */
type RigaDueSerie = NonNullable<DestinatarioCambiato['due_serie']>[number];

const serie = (cifra_formattata: string, esercizi: string[], nel_wallet: boolean) => ({ cifra_formattata, esercizi, nel_wallet });

const FRASE_DUE_SERIE = FRASE + ' Le parti di Ugo, Anna e Bruno entrano due volte. Togline una per ognuno dal Wallet: il piano assorbe quella che resta alla prossima generazione.';

// La forma scelta da Vincenzo: le sei serie nello stesso esercizio, tutte nel Wallet.
const NEL_2027: RigaDueSerie[] = [
    { id: 5, nome: 'Ugo', legata: serie('€ 394,52 a credito', ['Esercizio 2027'], true), non_legata: serie('€ 394,52 a credito', ['Esercizio 2027'], true), esercizio: 'Esercizio 2027' },
    { id: 6, nome: 'Anna', legata: serie('€ 236,71 a debito', ['Esercizio 2027'], true), non_legata: serie('€ 236,71 a debito', ['Esercizio 2027'], true), esercizio: 'Esercizio 2027' },
    { id: 7, nome: 'Bruno', legata: serie('€ 157,81 a debito', ['Esercizio 2027'], true), non_legata: serie('€ 157,81 a debito', ['Esercizio 2027'], true), esercizio: 'Esercizio 2027' },
];

// Le varianti: le serie in esercizi diversi, una sola nel Wallet (la legata o la non legata), nessuna nel Wallet, una serie in due esercizi.
const MISTE: RigaDueSerie[] = [
    { id: 5, nome: 'Ugo', legata: serie('€ 394,52 a credito', ['Esercizio 2027'], true), non_legata: serie('€ 394,52 a credito', ['Esercizio 2025', 'Esercizio 2026'], false), esercizio: null },
    { id: 6, nome: 'Anna', legata: serie('€ 236,71 a debito', ['Esercizio 2026'], false), non_legata: serie('€ 236,71 a debito', ['Esercizio 2027'], true), esercizio: null },
    { id: 7, nome: 'Bruno', legata: serie('€ 157,81 a debito', ['Esercizio 2026'], false), non_legata: serie('€ 157,81 a debito', ['Esercizio 2026'], false), esercizio: 'Esercizio 2026' },
];

const conDueSerie = (righe: RigaDueSerie[], frase = FRASE_DUE_SERIE) => ({ ...DEFUNTO, frase, due_serie: righe }) as unknown as DestinatarioCambiato;

const tabellaDueSerie = (wrapper: ReturnType<typeof monta>) => wrapper.find('[data-due-serie] table');
const celle = (riga: { findAll: (s: string) => { text: () => string }[] }) => riga.findAll('td').map(td => td.text());

describe('DestinatariWarning — la parte due volte, in una tabella (decisione 170)', () => {
    test('le serie nello stesso esercizio: la frase del server intera, e la tabella persona, legata alla successione, non legata, esercizio, nel Wallet', () => {
        const wrapper = monta([conDueSerie(NEL_2027)]);
        const tabella = tabellaDueSerie(wrapper);

        expect(wrapper.find('[data-defunto] p').text()).toBe(FRASE_DUE_SERIE);
        expect(tabella.exists()).toBe(true);
        expect(tabella.findAll('th').map(th => th.text())).toEqual(['Persona', 'Legata alla successione', 'Non legata', 'Esercizio', 'Nel Wallet']);
        const righe = tabella.findAll('tbody tr').map(celle);
        expect(righe).toEqual([
            ['Ugo', '€ 394,52 a credito', '€ 394,52 a credito', 'Esercizio 2027', 'tutte e due'],
            ['Anna', '€ 236,71 a debito', '€ 236,71 a debito', 'Esercizio 2027', 'tutte e due'],
            ['Bruno', '€ 157,81 a debito', '€ 157,81 a debito', 'Esercizio 2027', 'tutte e due'],
        ]);
        // L'esercizio è detto una volta per riga, nella sua colonna, e non sotto le cifre.
        expect(tabella.find('[data-esercizi]').exists()).toBe(false);
    });

    test('le serie in esercizi diversi: niente colonna dell\'esercizio, che sta sotto la cifra di ogni serie (due esercizi detti «ed» davanti a «Esercizio»); il Wallet dice quale serie c\'è, o nessuna', () => {
        const tabella = tabellaDueSerie(monta([conDueSerie(MISTE)]));

        expect(tabella.findAll('th').map(th => th.text())).toEqual(['Persona', 'Legata alla successione', 'Non legata', 'Nel Wallet']);
        const righe = tabella.findAll('tbody tr').map(r => r.findAll('td'));
        const laSerie = (td: (typeof righe)[number][number]) => [td.find('span').text(), td.find('[data-esercizi]').text()];
        expect(righe.map(td => td.length)).toEqual([4, 4, 4]);
        expect(righe.map(td => td[0].text())).toEqual(['Ugo', 'Anna', 'Bruno']);
        expect(righe.map(td => laSerie(td[1]))).toEqual([
            ['€ 394,52 a credito', 'Esercizio 2027'],
            ['€ 236,71 a debito', 'Esercizio 2026'],
            ['€ 157,81 a debito', 'Esercizio 2026'],
        ]);
        expect(righe.map(td => laSerie(td[2]))).toEqual([
            ['€ 394,52 a credito', 'Esercizio 2025 ed Esercizio 2026'],
            ['€ 236,71 a debito', 'Esercizio 2027'],
            ['€ 157,81 a debito', 'Esercizio 2026'],
        ]);
        expect(righe.map(td => td[3].text())).toEqual(['solo la legata', 'solo la non legata', 'no']);
    });

    test('la tabella delle due serie viene subito dopo la frase, prima di quella degli eredi', () => {
        const voce = monta([conDueSerie(NEL_2027)]).find('[data-defunto]');
        const figli = voce.element.children;

        expect(figli[0].tagName).toBe('P');
        expect((figli[1] as HTMLElement).hasAttribute('data-due-serie')).toBe(true);
        expect(figli[2].textContent).toContain('Quota dell\'unità');
    });

    test('la cifra di ogni serie passa da FraseConImporti: il simbolo e la cifra stanno in uno span che non va a capo', () => {
        const importi = tabellaDueSerie(monta([conDueSerie(NEL_2027)])).findAll('span.whitespace-nowrap').map(s => s.text());

        expect(importi).toEqual(['€ 394,52', '€ 394,52', '€ 236,71', '€ 236,71', '€ 157,81', '€ 157,81']);
    });

    test('senza la parte due volte (chiave assente o vuota) non c\'è una seconda tabella: solo quella degli eredi', () => {
        for (const voce of [DEFUNTO, conDueSerie([], FRASE)]) {
            const wrapper = monta([voce]);

            expect(wrapper.find('[data-due-serie]').exists()).toBe(false);
            expect(wrapper.findAll('table')).toHaveLength(1);
            expect(wrapper.find('table').text()).toContain('Quota dell\'unità');
        }
    });

    test('due defunti sulla stessa unità: la tabella sta nella sola voce che la porta', () => {
        const ada = { ...DEFUNTO, defunto: { id: 9, nome: 'Ada' }, due_serie: [] } as unknown as DestinatarioCambiato;
        const voci = monta([conDueSerie([NEL_2027[1]]), ada]).findAll('[data-defunto]');

        expect(voci[0].find('[data-due-serie]').exists()).toBe(true);
        expect(voci[0].find('[data-due-serie] tbody').findAll('tr')).toHaveLength(1);
        expect(voci[1].find('[data-due-serie]').exists()).toBe(false);
    });

    test('la tabella delle due serie non cambia i conteggi del pannello: il titolo conta i defunti, e la nota resta quella delle quote del defunto', () => {
        const wrapper = monta([conDueSerie(NEL_2027)]);

        expect(wrapper.find('h3').text()).toBe('Il piano calcola ancora per un defunto');
        expect(wrapper.find('label[for="nota_destinatari"]').text()).toBe('Ho letto le quote del defunto. Perché procedo così?');
    });
});

/**
 * Decisione 171: due esercizi si dicono con «ed» davanti a un nome che comincia per «e» (quelli di serie, «Esercizio 2025»), con «e» davanti al resto
 * («2025/2026», «Anno 2026»); con tre, i primi con la virgola e l'ultimo con la congiunzione. I nomi degli esercizi sono liberi.
 */
describe('DestinatariWarning — gli esercizi di una serie, detti insieme (decisione 171)', () => {
    const esercizioSotto = (esercizi: string[]) => {
        const riga: RigaDueSerie = {
            id: 5, nome: 'Ugo', legata: serie('€ 394,52 a credito', ['Esercizio 2027'], true), non_legata: serie('€ 394,52 a credito', esercizi, false), esercizio: null,
        };

        return tabellaDueSerie(monta([conDueSerie([riga])])).findAll('td')[2].find('[data-esercizi]').text();
    };

    test.each([
        [['Esercizio 2025', 'Esercizio 2026'], 'Esercizio 2025 ed Esercizio 2026'],
        [['Gestione 2025', 'esercizio 2026'], 'Gestione 2025 ed esercizio 2026'],
        [['Esercizio 2025', 'Esercizio 2026', 'Esercizio 2027'], 'Esercizio 2025, Esercizio 2026 ed Esercizio 2027'],
        [['2025/2026', '2026/2027'], '2025/2026 e 2026/2027'],
        [['Anno 2026', 'Anno 2027'], 'Anno 2026 e Anno 2027'],
        [['Esercizio 2025', '2026'], 'Esercizio 2025 e 2026'],
        [['2025', 'Esercizio 2026', '2027'], '2025, Esercizio 2026 e 2027'],
        [['Esercizio 2027'], 'Esercizio 2027'],
    ])('gli esercizi %j si leggono «%s»', (esercizi, atteso) => {
        expect(esercizioSotto(esercizi)).toBe(atteso);
    });
});

/**
 * Decisione 171: sul telefono la tabella delle cinque colonne non ci sta (circa 436 px contro circa 291). Sotto la larghezza `sm` di Tailwind (640 px) una
 * scheda per persona, con i quattro valori uno sotto l'altro e ognuno con la sua etichetta (Legata alla successione, Non legata, Esercizio, Nel Wallet);
 * da `sm` in su la tabella. I dati sono gli stessi: il server non manda niente di diverso, e la scheda dice quello che dice la tabella.
 *
 * In jsdom nessun foglio di stile è caricato: le due viste ci sono tutte e due nel documento, e che una si veda solo sotto `sm` e l'altra solo da `sm` in
 * su lo dicono le classi `sm:hidden` e `hidden sm:block` (in Tailwind 4 `sm` è `min-width: 40rem`: `--breakpoint-sm` in `tailwindcss/theme.css`, provato dal test che compila le classi).
 */
describe('DestinatariWarning — la parte due volte, sul telefono: una scheda per persona (decisione 171)', () => {
    const schedeDi = (wrapper: ReturnType<typeof monta>) => wrapper.findAll('[data-due-serie] [data-scheda]');
    const persona = (scheda: ReturnType<typeof schedeDi>[number]) => scheda.find('[data-persona]').text();
    const etichette = (scheda: ReturnType<typeof schedeDi>[number]) => scheda.findAll('dt').map(dt => dt.text());
    const valori = (scheda: ReturnType<typeof schedeDi>[number]) => scheda.findAll('dd').map(dd => dd.text());

    test('le serie nello stesso esercizio: una scheda per persona, con le quattro etichette e i quattro valori nell\'ordine della tabella', () => {
        const schede = schedeDi(monta([conDueSerie(NEL_2027)]));

        expect(schede.map(persona)).toEqual(['Ugo', 'Anna', 'Bruno']);
        expect(schede.map(etichette)).toEqual(Array(3).fill(['Legata alla successione', 'Non legata', 'Esercizio', 'Nel Wallet']));
        expect(schede.map(valori)).toEqual([
            ['€ 394,52 a credito', '€ 394,52 a credito', 'Esercizio 2027', 'tutte e due'],
            ['€ 236,71 a debito', '€ 236,71 a debito', 'Esercizio 2027', 'tutte e due'],
            ['€ 157,81 a debito', '€ 157,81 a debito', 'Esercizio 2027', 'tutte e due'],
        ]);
        // L'esercizio è detto una volta per scheda, nella sua riga, e non sotto le cifre.
        expect(schede.map(s => s.find('[data-esercizi]').exists())).toEqual([false, false, false]);
    });

    test('le serie in esercizi diversi: nessuna riga dell\'esercizio, che sta sotto la cifra di ogni serie; il Wallet dice quale serie c\'è, o nessuna', () => {
        const schede = schedeDi(monta([conDueSerie(MISTE)]));

        expect(schede.map(persona)).toEqual(['Ugo', 'Anna', 'Bruno']);
        expect(schede.map(etichette)).toEqual(Array(3).fill(['Legata alla successione', 'Non legata', 'Nel Wallet']));
        const laSerie = (dd: (typeof schede)[number]) => [dd.find('span').text(), dd.find('[data-esercizi]').text()];
        const cifreEdEsercizi = schede.map(s => [laSerie(s.findAll('dd')[0]), laSerie(s.findAll('dd')[1])]);
        expect(cifreEdEsercizi).toEqual([
            [['€ 394,52 a credito', 'Esercizio 2027'], ['€ 394,52 a credito', 'Esercizio 2025 ed Esercizio 2026']],
            [['€ 236,71 a debito', 'Esercizio 2026'], ['€ 236,71 a debito', 'Esercizio 2027']],
            [['€ 157,81 a debito', 'Esercizio 2026'], ['€ 157,81 a debito', 'Esercizio 2026']],
        ]);
        expect(schede.map(s => s.findAll('dd')[2].text())).toEqual(['solo la legata', 'solo la non legata', 'no']);
    });

    test('la scheda dice le stesse cose della tabella, riga per riga, nelle serie nello stesso esercizio e in quelle in esercizi diversi', () => {
        for (const righe of [NEL_2027, MISTE]) {
            const wrapper = monta([conDueSerie(righe)]);
            const dellaTabella = tabellaDueSerie(wrapper).findAll('tbody tr').map(tr => tr.findAll('td').map(td => td.text()));
            const dellaScheda = schedeDi(wrapper).map(s => [persona(s), ...s.findAll('dd').map(dd => dd.text())]);

            expect(dellaScheda).toEqual(dellaTabella);
        }
    });

    test('le due serie con cifre diverse (il centesimo di resto di una persona): la scheda e la tabella mettono ogni cifra nella sua serie', () => {
        const wrapper = monta([conDueSerie([
            { id: 5, nome: 'Ugo', legata: serie('€ 394,52 a credito', ['Esercizio 2027'], true), non_legata: serie('€ 394,52 a credito', ['Esercizio 2027'], true), esercizio: 'Esercizio 2027' },
            { id: 6, nome: 'Anna', legata: serie('€ 236,71 a debito', ['Esercizio 2027'], true), non_legata: serie('€ 236,72 a debito', ['Esercizio 2027'], true), esercizio: 'Esercizio 2027' },
        ])]);

        expect(schedeDi(wrapper).map(s => s.findAll('dd').slice(0, 2).map(dd => dd.text()))).toEqual([
            ['€ 394,52 a credito', '€ 394,52 a credito'],
            ['€ 236,71 a debito', '€ 236,72 a debito'],
        ]);
        expect(tabellaDueSerie(wrapper).findAll('tbody tr').map(tr => tr.findAll('td').slice(1, 3).map(td => td.text()))).toEqual([
            ['€ 394,52 a credito', '€ 394,52 a credito'],
            ['€ 236,71 a debito', '€ 236,72 a debito'],
        ]);
    });

    test('sotto sm si vedono le schede e non la tabella; da sm in su la tabella e non le schede', () => {
        const wrapper = monta([conDueSerie(NEL_2027)]);
        const schede = wrapper.find('[data-due-serie-schede]');
        const tabella = wrapper.find('[data-due-serie-tabella]');

        expect(schede.classes()).toContain('sm:hidden');
        expect(schede.classes()).not.toContain('hidden');
        expect(tabella.classes()).toContain('hidden');
        expect(tabella.classes()).toContain('sm:block');
        // Le schede sono dentro la stessa voce e subito prima della tabella; la tabella è quella delle due serie, non quella degli eredi.
        expect(tabella.find('table').exists()).toBe(true);
        expect(schede.find('table').exists()).toBe(false);
        expect(wrapper.find('[data-due-serie]').element.children[0]).toBe(schede.element);
        expect(wrapper.find('[data-due-serie]').element.children[1]).toBe(tabella.element);
    });

    test('fatto di libreria: in Tailwind sm è 40rem (640 px), sm:hidden e sm:block valgono da lì in su e hidden sempre; il progetto non cambia i punti di rottura', async () => {
        const dalla = (percorso: string) => readFileSync(fileURLToPath(new URL(percorso, import.meta.url)), 'utf8');
        const css = await compile('@import "tailwindcss/theme.css" layer(theme);\n@tailwind utilities;', {
            base: '/',
            loadStylesheet: async (id, base) => ({ path: id, base, content: dalla('../../../../../node_modules/tailwindcss/theme.css') }),
        });
        const generato = css.build(['hidden', 'sm:hidden', 'sm:block']);
        const daSm = generato.slice(generato.indexOf('@media (width >= 40rem)'));

        expect(generato).toContain('@media (width >= 40rem)');
        expect(generato.slice(0, generato.indexOf('@media'))).toMatch(/\.hidden \{\s*display: none;\s*\}/);
        expect(daSm).toMatch(/\.sm\\:block \{\s*display: block;\s*\}/);
        expect(daSm).toMatch(/\.sm\\:hidden \{\s*display: none;\s*\}/);
        expect(daSm).not.toContain('.hidden {');
        for (const foglio of ['app.css', 'custom.css']) {
            expect(dalla(`../../../../css/${foglio}`)).not.toMatch(/--breakpoint-/);
        }
    });

    test('la cifra di ogni serie passa da FraseConImporti anche nella scheda: il simbolo e la cifra stanno in uno span che non va a capo', () => {
        const importi = monta([conDueSerie(NEL_2027)]).find('[data-due-serie-schede]').findAll('span.whitespace-nowrap').map(s => s.text());

        expect(importi).toEqual(['€ 394,52', '€ 394,52', '€ 236,71', '€ 236,71', '€ 157,81', '€ 157,81']);
    });

    test('due defunti sulla stessa unità: le schede stanno nella sola voce che porta le due serie', () => {
        const ada = { ...DEFUNTO, defunto: { id: 9, nome: 'Ada' }, due_serie: [] } as unknown as DestinatarioCambiato;
        const voci = monta([conDueSerie([NEL_2027[1]]), ada]).findAll('[data-defunto]');

        expect(voci[0].findAll('[data-scheda]').map(persona)).toEqual(['Anna']);
        expect(voci[1].find('[data-due-serie-schede]').exists()).toBe(false);
        expect(voci[1].find('[data-scheda]').exists()).toBe(false);
    });

    test('senza la parte due volte (chiave assente o vuota) non ci sono schede', () => {
        for (const voce of [DEFUNTO, conDueSerie([], FRASE)]) {
            const wrapper = monta([voce]);

            expect(wrapper.find('[data-due-serie-schede]').exists()).toBe(false);
            expect(wrapper.find('[data-scheda]').exists()).toBe(false);
        }
    });
});
