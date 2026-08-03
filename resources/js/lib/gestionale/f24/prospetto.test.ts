/**
 * Il prospetto della delega F24, lato interfaccia.
 *
 * Il periodo di riferimento è calcolato **due volte in due linguaggi** — qui e in
 * `RigaF24::periodoLeggibile()` — ed è esattamente lo schema che nella beta.35 è costato un
 * centesimo di divergenza sul netto da pagare: nessuna delle due copie era sbagliata da
 * sola. Il test PHP gemello sta in `tests/Feature/Gestionale/RigaF24PeriodoTest.php`.
 */

import { describe, expect, test } from 'vitest';
import {
    descrizioneMotivo,
    giorniAllaScadenza,
    periodoLeggibile,
    righeProspetto,
    testoScadenza,
    totaleDebito,
    urgenzaScadenza,
    type RigaDelega,
} from './prospetto';

const riga = (over: Partial<RigaDelega> = {}): RigaDelega => ({
    ordine: 1,
    codice_tributo: '1019',
    rateazione_mese_rif: '0003',
    anno_riferimento: '2026',
    importo_debito: 80_000,
    ...over,
});

/**
 * Il motivo arriva dal server come codice e si traduce qui. È la correzione di uno sbaglio
 * di progetto: la frase era salvata a database, quindi cambiare una parola — è successo con
 * il simbolo dell'euro nel posto sbagliato — lasciava le deleghe già calcolate con il testo
 * vecchio, e occupava il campo `note`, che è dell'amministratore.
 */
describe('motivo della scadenza', () => {
    test('ogni codice ha la sua spiegazione', () => {
        expect(descrizioneMotivo('soglia_raggiunta')).toContain('soglia di legge');
        expect(descrizioneMotivo('termine_di_legge')).toContain('sotto soglia');
        expect(descrizioneMotivo('ritenute_dicembre')).toContain('16 gennaio');
        expect(descrizioneMotivo('mensile_obbligatorio')).toContain('non è ammesso il rinvio');
    });

    /** Le deleghe calcolate prima di questa correzione non hanno il codice. */
    test('un codice assente non produce testo, non «undefined»', () => {
        expect(descrizioneMotivo(null)).toBe('');
        expect(descrizioneMotivo(undefined)).toBe('');
        expect(descrizioneMotivo('codice_mai_visto')).toBe('');
    });

    /** Nessuna frase deve contenere il simbolo dell'euro dopo il numero. */
    test('nessuna spiegazione scrive l importo col simbolo in coda', () => {
        for (const codice of ['soglia_raggiunta', 'termine_di_legge', 'ritenute_dicembre', 'mensile_obbligatorio']) {
            expect(descrizioneMotivo(codice)).not.toMatch(/\d\s*€/);
        }
    });
});

describe('periodo di riferimento', () => {
    test('«0003» e «2026» diventano 03/2026', () => {
        expect(periodoLeggibile('0003', '2026')).toBe('03/2026');
    });

    test('dicembre resta a due cifre', () => {
        expect(periodoLeggibile('0012', '2026')).toBe('12/2026');
    });

    /**
     * I primi due caratteri non sono il mese: la posizione ospita anche altre forme nel
     * tracciato, ed è la ragione per cui il campo è una stringa di quattro caratteri.
     * Prendere `slice(0, 2)` sembrerebbe funzionare su «0003» e sbaglierebbe su tutto il
     * resto.
     */
    test('legge la seconda coppia di caratteri, non la prima', () => {
        expect(periodoLeggibile('0101', '2026')).toBe('01/2026');
    });

    test('non esplode se i campi mancano', () => {
        expect(periodoLeggibile('', '')).toBe('/');
    });
});

describe('righe del prospetto', () => {
    test('escono nell ordine del modello, non per importo', () => {
        const righe = righeProspetto([
            riga({ ordine: 2, importo_debito: 500_00, rateazione_mese_rif: '0004' }),
            riga({ ordine: 1, importo_debito: 100_00 }),
        ]);

        expect(righe.map((r) => r.ordine)).toEqual([1, 2]);
    });

    test('ogni riga porta i campi da trascrivere', () => {
        const [r] = righeProspetto([riga()]);

        expect(r).toMatchObject({
            codiceTributo: '1019',
            rateazione: '0003',
            mese: '03',
            anno: '2026',
            periodo: '03/2026',
            importoDebito: 80_000,
        });
    });

    test('un elenco vuoto non produce righe', () => {
        expect(righeProspetto([])).toEqual([]);
    });
});

describe('totale a debito', () => {
    test('somma le righe', () => {
        expect(totaleDebito([riga({ importo_debito: 100_00 }), riga({ importo_debito: 250_50 })]))
            .toBe(350_50);
    });

    test('un elenco vuoto vale zero', () => {
        expect(totaleDebito([])).toBe(0);
    });
});

describe('scadenza', () => {
    const oggi = new Date(2026, 3, 10);   // 10 aprile 2026

    test('conta i giorni che mancano', () => {
        expect(giorniAllaScadenza('2026-04-16', oggi)).toBe(6);
    });

    test('una scadenza passata dà un numero negativo', () => {
        expect(giorniAllaScadenza('2026-04-01', oggi)).toBe(-9);
    });

    /**
     * L'ora del giorno non deve contare: una scadenza fiscale è una data, non un istante.
     * Confrontando i timestamp pieni, «oggi alle 18» e «oggi alle 8» darebbero due risultati
     * diversi per la stessa scadenza.
     */
    test('l ora del giorno non cambia il conteggio', () => {
        const mattina = new Date(2026, 3, 10, 8, 0);
        const sera = new Date(2026, 3, 10, 22, 0);

        expect(giorniAllaScadenza('2026-04-16', mattina)).toBe(giorniAllaScadenza('2026-04-16', sera));
    });

    test('classifica l urgenza', () => {
        expect(urgenzaScadenza('2026-04-01', oggi)).toBe('scaduta');
        expect(urgenzaScadenza('2026-04-16', oggi)).toBe('urgente');
        expect(urgenzaScadenza('2026-05-05', oggi)).toBe('prossima');
        expect(urgenzaScadenza('2026-12-16', oggi)).toBe('lontana');
    });

    test('il giorno stesso è urgente, non scaduto', () => {
        expect(urgenzaScadenza('2026-04-10', oggi)).toBe('urgente');
    });
});

describe('testo della scadenza', () => {
    const oggi = new Date(2026, 3, 10);

    test('usa il singolare quando serve', () => {
        expect(testoScadenza('2026-04-11', oggi)).toBe('Manca 1 giorno');
        expect(testoScadenza('2026-04-09', oggi)).toBe('Scaduta da 1 giorno');
    });

    test('usa il plurale altrimenti', () => {
        expect(testoScadenza('2026-04-16', oggi)).toBe('Mancano 6 giorni');
        expect(testoScadenza('2026-04-01', oggi)).toBe('Scaduta da 9 giorni');
    });

    test('oggi si dice «scade oggi»', () => {
        expect(testoScadenza('2026-04-10', oggi)).toBe('Scade oggi');
    });
});
