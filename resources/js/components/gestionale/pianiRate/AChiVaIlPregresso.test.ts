// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La issue 58 (Coda 174, decisione 674) nella finestra «Riparto saldi iniziali»: il blocco «A chi va» di un saldo dell'unità che tocca un
 * passaggio, con le parole della bozza approvata (`docs/design/beta14_pregresso_passaggio_bozza.html`), sulle anteprime che il server
 * manda (`GenerateSaldiAction::anteprimaSolidale()`, provata in `Beta14PregressoPassaggioTest`).
 *
 * Che cosa NON copre: la pagina intera (la riga arancio e il riepilogo della finestra hanno le loro prove in
 * `PianiRateNewPregressoPassaggio.test.ts`), il disegno a video.
 */

import { afterEach, describe, expect, test } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import AChiVaIlPregresso, { aChiVa, delGiorno, giornoInParole, sceltaInVigore, toccaUnPassaggio, type RipartoPrevisto } from './AChiVaIlPregresso.vue';

const ugo = { anagrafica_id: 1, nome: 'Ugo Rossi', quota: 100, importo: 18000 };
const elsa = { anagrafica_id: 2, nome: 'Elsa Bianchi', quota: 100, importo: 18000 };

/** La scena 1 della bozza: vendita piena dell'1/4/2026, il saldo all'1/1/2026, la regola su Ugo. */
const venditaPiena: RipartoPrevisto = {
    risolvibile: true, ruolo: 'proprietario', ruolo_label: 'Proprietario', motivo: null, quote: [ugo],
    riferito_al: '2026-01-01', titolari_al: '2026-01-01', esercizio: 'Esercizio anno 2026', natura: 'ordinaria', successione: null,
    passaggi: [{ data: '2026-04-01', tipo: 'vendita', etichetta: 'Vendita', riserva: false, uscenti: ['Ugo Rossi'], entranti: ['Elsa Bianchi'] }],
    scelte: [
        { alla_data: '2026-01-01', regola: true, etichetta: 'titolare al 1 gennaio 2026 · la regola', ruolo: 'proprietario', ruolo_label: 'Proprietario', quote: [ugo] },
        { alla_data: '2026-04-01', regola: false, etichetta: 'titolare dal 1 aprile 2026', ruolo: 'proprietario', ruolo_label: 'Proprietario', quote: [elsa] },
    ],
};

/** La scena 3: riserva d'usufrutto dell'1/5/2026 sulla straordinaria. */
const riserva: RipartoPrevisto = {
    ...venditaPiena,
    natura: 'straordinaria',
    passaggi: [{ data: '2026-05-01', tipo: 'vendita', etichetta: 'Vendita con riserva d\'usufrutto', riserva: true, uscenti: ['Ugo Rossi'], entranti: ['Elsa Bianchi'] }],
    scelte: [
        { alla_data: '2026-01-01', regola: true, etichetta: 'con la piena proprietà al 1 gennaio 2026 · la regola', ruolo: 'proprietario', ruolo_label: 'Proprietario', quote: [ugo] },
        { alla_data: '2026-05-01', regola: false, etichetta: 'con la nuda proprietà dal 1 maggio 2026', ruolo: 'nuda_proprietario', ruolo_label: 'Nudo proprietario', quote: [elsa] },
    ],
};

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

const monta = (riparto: RipartoPrevisto, modo: 'automatica' | 'manuale' = 'automatica', allaData: string | null = null) =>
    (w = mount(AChiVaIlPregresso, { props: { riparto, modo, allaData }, global: { stubs: { 'v-select': true } } }));
const testo = (sel: string) => w!.get(`[data-testid="${sel}"]`).text().replace(/\s+/g, ' ');

describe('le parole', () => {
    test('le date in parole, con l\'apostrofo davanti a un numero che comincia per vocale', () => {
        expect(giornoInParole('2026-04-01')).toBe('1 aprile 2026');
        expect(delGiorno('2026-04-01')).toBe('dell\'1 aprile 2026');
        expect(delGiorno('2025-12-15')).toBe('del 15 dicembre 2025');
        expect(delGiorno('2026-03-08')).toBe('dell\'8 marzo 2026');
    });

    test('scena 1 — il passaggio in una riga, e il giorno a cui il saldo si riferisce con chi era titolare', () => {
        monta(venditaPiena);
        expect(testo('il-passaggio')).toContain('Vendita dell\'1 aprile 2026: Ugo Rossi → Elsa Bianchi.')
            .toContain('Il saldo è al 1 gennaio 2026, primo giorno del suo esercizio: quel giorno era titolare Ugo Rossi.');
        // Rilievo 16: il nome dell'esercizio è quello che gli ha dato lo studio («Esercizio anno 2026»), e nella frase non ci sta.
        expect(testo('il-passaggio')).not.toContain('Esercizio anno');
    });

    test('scena 1 — tre scelte affiancate, la regola già scelta e detta come tale, con la cifra; la frase della solidarietà sotto; il perché in un cassetto', () => {
        monta(venditaPiena);
        expect(w!.findAll('[role="radio"]')).toHaveLength(3);
        expect(w!.get('[data-testid="scelta-2026-01-01"]').attributes('aria-checked')).toBe('true');
        expect(testo('scelta-2026-01-01')).toContain('Ugo Rossi').toContain('titolare al 1 gennaio 2026 · la regola').toContain('180,00');
        expect(testo('scelta-2026-04-01')).toContain('Elsa Bianchi').toContain('titolare dal 1 aprile 2026');
        expect(testo('scelta-manuale')).toContain('Divido a mano').toContain('fra le persone che scegli');
        // Rilievo 15: senza genere, «scegli il suo nome», non «sceglilo».
        expect(testo('frase-sotto-le-scelte')).toBe('Elsa Bianchi ne risponde comunque in solido verso il condominio (art. 63 disp. att. c.c.). Se nell\'atto il debito è passato a Elsa Bianchi, scegli il suo nome qui.');
        expect(testo('perche-questa-regola')).toContain('Perché questa regola').toContain('Quel giorno era titolare Ugo Rossi: il debito è suo.')
            .toContain('Chi compra risponde in solido per l\'anno in corso e il precedente');
    });

    test('scena 2 — l\'amministratore sceglie Elsa: la scelta si segna, e la frase dice che resta scritta con il giorno', async () => {
        monta(venditaPiena);
        await w!.get('[data-testid="scelta-2026-04-01"]').trigger('click');
        expect(w!.emitted('scegli')![0]).toEqual([{ modo: 'automatica', alla_data: '2026-04-01' }]);

        await w!.setProps({ allaData: '2026-04-01' });
        expect(w!.get('[data-testid="scelta-2026-04-01"]').attributes('aria-checked')).toBe('true');
        expect(testo('frase-sotto-le-scelte')).toBe('Hai scelto diversamente dalla regola: nel piano resta scritto, con il giorno dei titolari che hai scelto (1 aprile 2026).');

        // Tornare alla regola non manda un giorno: la regola non si scrive.
        await w!.get('[data-testid="scelta-2026-01-01"]').trigger('click');
        expect(w!.emitted('scegli')![1]).toEqual([{ modo: 'automatica', alla_data: null }]);
        await w!.get('[data-testid="scelta-manuale"]').trigger('click');
        expect(w!.emitted('scegli')![2]).toEqual([{ modo: 'manuale', alla_data: null }]);
    });

    test('scena 3 — la riserva: la nuda proprietà a Elsa, l\'usufrutto resta a Ugo; Ugo aveva la piena proprietà; la frase dell\'art. 1005', () => {
        monta(riserva);
        expect(testo('il-passaggio')).toContain('Vendita con riserva d\'usufrutto dell\'1 maggio 2026: la nuda proprietà a Elsa Bianchi, l\'usufrutto resta a Ugo Rossi.')
            .toContain('quel giorno Ugo Rossi aveva la piena proprietà.');
        expect(testo('scelta-2026-05-01')).toContain('con la nuda proprietà dal 1 maggio 2026');
        expect(testo('frase-sotto-le-scelte')).toBe('Dopo l\'atto la straordinaria è del nudo proprietario (art. 1005 c.c.); il debito di prima resta di chi era proprietario.');
    });

    test('scena 4 — la vendita prima dell\'inizio dell\'esercizio: la regola è Elsa, Ugo fino al giorno prima, «Divido a mano» se una parte è di prima', () => {
        monta({
            ...venditaPiena, quote: [elsa],
            passaggi: [{ data: '2025-12-15', tipo: 'vendita', etichetta: 'Vendita', riserva: false, uscenti: ['Ugo Rossi'], entranti: ['Elsa Bianchi'] }],
            scelte: [
                { alla_data: '2026-01-01', regola: true, etichetta: 'titolare al 1 gennaio 2026 · la regola', ruolo: 'proprietario', ruolo_label: 'Proprietario', quote: [elsa] },
                { alla_data: '2025-12-14', regola: false, etichetta: 'titolare fino al 14 dicembre 2025', ruolo: 'proprietario', ruolo_label: 'Proprietario', quote: [ugo] },
            ],
        });
        expect(testo('il-passaggio')).toContain('Vendita del 15 dicembre 2025: Ugo Rossi → Elsa Bianchi.').toContain('quel giorno era già titolare Elsa Bianchi.');
        expect(testo('scelta-manuale')).toContain('se una parte è di prima della vendita');
        expect(testo('frase-sotto-le-scelte')).toBe('Il saldo di un esercizio è di chi è titolare quando l\'esercizio comincia. Se una parte è di Ugo Rossi, dividi a mano.');
    });

    test('la vendita datata il primo giorno (decisione 685 (2)): conta chi era titolare il giorno prima', () => {
        monta({ ...venditaPiena, titolari_al: '2025-12-31', passaggi: [{ ...venditaPiena.passaggi![0], data: '2026-01-01' }] });
        expect(testo('giorno-del-saldo')).toContain('il passaggio è di quel giorno, e conta chi era titolare il giorno prima, Ugo Rossi.');
    });
});

/**
 * Il giro di correzioni dopo lo scettico della tappa 1 della 1.12.0-beta.14 (rilievi 4, 7, 8, 15, 16): le frasi che si contraddicevano o
 * che comparivano fuori posto. Le anteprime hanno la forma di quelle del server (`GenerateSaldiAction::anteprimaSolidale()`, provate in
 * `Beta14PregressoPassaggioTest`).
 */
describe('le frasi al loro posto', () => {
    /** La vendita a Elsa datata il primo giorno dell'esercizio: la regola guarda il 31/12, Ugo; Elsa è la seconda scelta. */
    const primoGiorno: RipartoPrevisto = {
        ...venditaPiena, titolari_al: '2025-12-31',
        passaggi: [{ ...venditaPiena.passaggi![0], data: '2026-01-01' }],
        scelte: [
            { ...venditaPiena.scelte![0], alla_data: '2025-12-31', etichetta: 'titolare al 31 dicembre 2025 · la regola' },
            { ...venditaPiena.scelte![1], alla_data: '2026-01-01', etichetta: 'titolare dal 1 gennaio 2026' },
        ],
    };

    /** La successione di Ugo dell'1/4/2026 con «l'arretrato agli eredi»: la regola dà il pregresso ad Anna e Bruno, a metà. */
    const anna = { anagrafica_id: 3, nome: 'Anna Verdi', quota: 50, importo: 9000 };
    const bruno = { anagrafica_id: 4, nome: 'Bruno Verdi', quota: 50, importo: 9000 };
    const agliEredi: RipartoPrevisto = {
        ...venditaPiena, quote: [anna, bruno],
        successione: { defunti: ['Ugo Rossi'], data: '2026-04-01', titolari: ['Ugo Rossi'], arretrato: 'eredi' },
        passaggi: [{ data: '2026-04-01', tipo: 'successione', etichetta: 'Successione', riserva: false, uscenti: ['Ugo Rossi'], entranti: ['Anna Verdi', 'Bruno Verdi'] }],
        scelte: [{ alla_data: '2026-01-01', regola: true, etichetta: 'eredi di Ugo Rossi · la regola', ruolo: 'proprietario', ruolo_label: 'Proprietario', quote: [anna, bruno] }],
    };

    test('rilievo 7 — la vendita datata il primo giorno: sotto le scelte la solidarietà di Elsa, non «la regola dà il pregresso a chi era titolare il primo giorno»; il cassetto dice il giorno prima', () => {
        monta(primoGiorno);

        expect(testo('frase-sotto-le-scelte')).toBe('Elsa Bianchi ne risponde comunque in solido verso il condominio (art. 63 disp. att. c.c.). Se nell\'atto il debito è passato a Elsa Bianchi, scegli il suo nome qui.');
        expect(testo('perche-questa-regola')).toContain('Il passaggio è del primo giorno: il debito è di chi era titolare il giorno prima, Ugo Rossi.')
            .not.toContain('Quel giorno era titolare');
        expect(w!.text()).not.toContain('a chi era titolare il primo giorno del suo esercizio');
    });

    test('rilievo 7 — con una scelta sola la frase della regola dice il giorno prima, non il primo giorno', () => {
        monta({ ...primoGiorno, scelte: [primoGiorno.scelte![0]] });

        expect(testo('frase-sotto-le-scelte')).toBe('Il passaggio è del primo giorno dell\'esercizio: la regola dà il pregresso a chi era titolare il giorno prima.');
    });

    test('rilievo 4 — la successione con «l\'arretrato agli eredi»: quel giorno il titolare era Ugo, e l\'arretrato è passato agli eredi; nessuna frase del compratore', () => {
        monta(agliEredi);

        expect(testo('giorno-del-saldo')).toBe('Il saldo è al 1 gennaio 2026, primo giorno del suo esercizio: quel giorno era titolare Ugo Rossi; con la successione dell\'1 aprile 2026 l\'arretrato è passato agli eredi.');
        expect(testo('scelta-2026-01-01')).toContain('eredi di Ugo Rossi · la regola').toContain('Anna Verdi').toContain('Bruno Verdi');
        expect(testo('frase-sotto-le-scelte')).toBe('Con la successione l\'arretrato è passato agli eredi: il pregresso va a loro, ciascuno per la sua quota.');
        expect(testo('perche-questa-regola')).toContain('Quel giorno era titolare Ugo Rossi; con la successione l\'arretrato è passato agli eredi, ciascuno per la sua quota.')
            .not.toContain('Chi compra').not.toContain('i titolari erano');
    });

    test('rilievo 4 — l\'erede che era già comproprietaria: quel giorno erano titolari Ugo ed Elsa, e l\'arretrato di Ugo è passato agli eredi', () => {
        monta({ ...agliEredi, successione: { defunti: ['Ugo Rossi'], data: '2026-04-01', titolari: ['Ugo Rossi', 'Elsa Bianchi'], arretrato: 'eredi' } });

        expect(testo('giorno-del-saldo')).toContain('quel giorno erano titolari Ugo Rossi ed Elsa Bianchi; con la successione dell\'1 aprile 2026 l\'arretrato di Ugo Rossi è passato agli eredi.');
    });

    test('decisione 689 (2) — Ugo muore il 15/12/2025 con «l\'arretrato a nome del defunto»: quel giorno erano titolari gli eredi, l\'arretrato è rimasto a nome di Ugo, e gli eredi sono l\'altra scelta', () => {
        monta({
            ...venditaPiena,
            successione: { defunti: ['Ugo Rossi'], data: '2025-12-15', titolari: ['Anna Verdi', 'Bruno Verdi'], arretrato: 'defunto' },
            passaggi: [{ data: '2025-12-15', tipo: 'successione', etichetta: 'Successione', riserva: false, uscenti: ['Ugo Rossi'], entranti: ['Anna Verdi', 'Bruno Verdi'] }],
            scelte: [
                { alla_data: '2026-01-01', regola: true, etichetta: 'a nome del defunto · la regola', ruolo: 'proprietario', ruolo_label: 'Proprietario', quote: [ugo] },
                { alla_data: '2025-12-15', regola: false, etichetta: 'titolari dal 15 dicembre 2025', ruolo: 'proprietario', ruolo_label: 'Proprietario', quote: [anna, bruno] },
            ],
        });

        expect(testo('giorno-del-saldo')).toBe('Il saldo è al 1 gennaio 2026, primo giorno del suo esercizio: quel giorno erano titolari Anna Verdi e Bruno Verdi; con la successione del 15 dicembre 2025 l\'arretrato è rimasto a nome di Ugo Rossi.');
        expect(testo('frase-sotto-le-scelte')).toBe('Con la successione l\'arretrato è rimasto a nome del defunto: il pregresso va a Ugo Rossi, e gli eredi sono l\'altra scelta.');
        expect(testo('scelta-manuale')).toContain('se una parte è di prima del passaggio');
        expect(testo('perche-questa-regola')).toContain('Quel giorno erano titolari Anna Verdi e Bruno Verdi; con la successione l\'arretrato è rimasto a nome di Ugo Rossi: il debito è suo.')
            .not.toContain('Chi compra');
    });

    test('rilievo 8 — il cassetto dice la regola della successione quando c\'è una successione, e solo allora', () => {
        monta(agliEredi);
        expect(testo('perche-questa-regola')).toContain('Con una successione: con l\'arretrato agli eredi va agli eredi per le loro quote, con l\'arretrato a nome del defunto resta al defunto.');
        w!.unmount(); w = null;

        monta(venditaPiena);
        expect(testo('perche-questa-regola')).not.toContain('Con una successione');
    });

    test('rilievo 16 — la frase della solidarietà solo se c\'è da scegliere e il saldo è un debito', () => {
        // Un credito dell'unità: nessuno «ne risponde in solido».
        const credito = (q: typeof ugo) => ({ ...q, importo: -q.importo });
        monta({ ...venditaPiena, quote: [credito(ugo)], scelte: venditaPiena.scelte!.map((c) => ({ ...c, quote: c.quote.map(credito) })) });
        expect(testo('frase-sotto-le-scelte')).not.toContain('in solido');
        w!.unmount(); w = null;

        // Elsa non è fra le scelte (quel giorno la catena non trova nessuno): niente da scegliere, niente «scegli il suo nome».
        monta({ ...venditaPiena, scelte: [venditaPiena.scelte![0]] });
        expect(testo('frase-sotto-le-scelte')).toBe('La regola dà il pregresso a chi era titolare il primo giorno del suo esercizio.');
    });

    test('rilievo 16 — la frase dell\'art. 1005 solo sulla straordinaria; sull\'ordinaria quella dell\'art. 1004', () => {
        monta(riserva);
        expect(testo('frase-sotto-le-scelte')).toContain('art. 1005');
        w!.unmount(); w = null;

        monta({
            ...riserva, natura: 'ordinaria',
            passaggi: [{ data: '2026-05-01', tipo: 'usufrutto', etichetta: 'Costituzione d\'usufrutto', riserva: false, uscenti: ['Ugo Rossi'], entranti: ['Elsa Bianchi'] }],
            scelte: [riserva.scelte![0], { ...riserva.scelte![1], etichetta: 'con l\'usufrutto dal 1 maggio 2026', ruolo: 'usufruttuario', ruolo_label: 'Usufruttuario' }],
        });
        expect(testo('frase-sotto-le-scelte')).toBe('Dopo l\'atto l\'ordinaria è dell\'usufruttuario (art. 1004 c.c.); il debito di prima resta di chi era proprietario.')
            .not.toContain('1005');
    });

    test('oltre tre scelte, il menu (vSelect) al posto delle scelte affiancate', () => {
        const scelte = ['2026-01-01', '2026-03-01', '2026-06-01', '2026-09-01'].map((d, i) => ({ ...venditaPiena.scelte![1], alla_data: d, regola: i === 0 }));
        monta({ ...venditaPiena, scelte });
        expect(w!.findAll('[role="radio"]')).toHaveLength(0);
        expect(w!.find('[data-testid="scelta-menu"]').exists()).toBe(true);
    });
});

describe('le funzioni della pagina', () => {
    test('toccaUnPassaggio: solo con passaggi e scelte, e con il pregresso che ha su chi cadere', () => {
        expect(toccaUnPassaggio(venditaPiena)).toBe(true);
        expect(toccaUnPassaggio({ ...venditaPiena, passaggi: [], scelte: [] })).toBe(false);
        expect(toccaUnPassaggio({ ...venditaPiena, risolvibile: false })).toBe(false);
        expect(toccaUnPassaggio(null)).toBe(false);
    });

    test('sceltaInVigore e aChiVa: la regola senza giorno, la scelta con il giorno, a mano con «Divido a mano»', () => {
        expect(sceltaInVigore(venditaPiena, 'automatica', null)?.alla_data).toBe('2026-01-01');
        expect(aChiVa(venditaPiena, 'automatica', null)).toBe('Ugo Rossi, la regola');
        expect(aChiVa(venditaPiena, 'automatica', '2026-04-01')).toBe('Elsa Bianchi, scelta tua');
        expect(aChiVa(venditaPiena, 'manuale', null)).toBe('diviso a mano');
    });
});
