/**
 * Le frasi e le scelte della tesoreria a 30 giorni, fuori dal componente: la regola delle 8, lo stato con la sua scritta, le scadenze delle
 * fatture, la barra delle fasce in proporzione, le azioni di ogni persona («Registra l'incasso» o «Compensa», «Manda un sollecito» solo quando
 * la regola lo suggerisce, il rimando ai sei mesi per le altre; con «Scelgo io la prossima azione» «Scrivi una lettera» per tutti), «Da dove viene».
 */
import { describe, expect, test } from 'vitest';
import {
    azioniDellaPersona, fasceDellaBarra, statoDellaTesoreria, testoCassetto, testoFuturi, testoMancanoFatture, testoMancanoPersone, testoNotaBanca, testoScadenzaFattura,
    nomeDellaRiga, FRASE_ESTRATTO, FRASE_FONDI,
} from './tesoreria';

describe('la regola delle 8', () => {
    test('oltre 8 le prime 5 e quante mancano, con la cifra; fino a 8 niente', () => {
        expect(testoMancanoFatture({ quante: 4, cifra: 230000 }, 5)).toBe('Qui le 5 con la scadenza più vicina; mancano 4 fatture, € 2.300,00');
        expect(testoMancanoFatture(null, 8)).toBeNull();
        expect(testoMancanoPersone(4, 41520, 5)).toBe('Qui le 5 con lo scaduto più alto; mancano 4 persone, € 415,20');
        expect(testoMancanoPersone(0, 0, 8)).toBeNull();
        // Mai «e altri 1»: con una persona sola che manca, il singolare.
        expect(testoMancanoPersone(1, 5000, 5)).toBe('Qui le 5 con lo scaduto più alto; manca 1 persona, € 50,00');
    });

    test('la testata del cassetto: il nome, quante, la cifra', () => {
        expect(testoCassetto('fatture', 3, 230000)).toBe('Fatture in scadenza · 3 · € 2.300,00');
        expect(testoCassetto('persone', 6, 124000)).toBe('Rate scadute dei condòmini · 6 persone · € 1.240,00');
        expect(testoCassetto('persone', 1, 5000)).toBe('Rate scadute dei condòmini · 1 persona · € 50,00');
    });
});

describe('lo stato si dice con la scritta, non solo con il colore', () => {
    test('i tre livelli', () => {
        expect(statoDellaTesoreria('verde').etichetta).toBe('Le uscite sono coperte');
        expect(statoDellaTesoreria('giallo').etichetta).toBe('Coperte solo con le entrate attese');
        expect(statoDellaTesoreria('rosso').etichetta).toBe('Le uscite non sono coperte');
        expect(statoDellaTesoreria('rosso').frase).toContain('anche se arrivano le entrate attese');
    });
});

describe('le fatture', () => {
    test('«scade il» o «scaduta il», con la data intera', () => {
        expect(testoScadenzaFattura({ dataScadenza: '2026-11-20', scaduta: false })).toBe('scade il 20/11/2026');
        expect(testoScadenzaFattura({ dataScadenza: '2026-11-02', scaduta: true })).toBe('scaduta il 02/11/2026');
    });
});

describe('la barra delle fasce in proporzione', () => {
    test('le larghezze in percentuale dello scaduto; a zero spente', () => {
        expect(fasceDellaBarra({ '1_30': 10000, '31_90': 30000, oltre_90: 60000 }).map((f) => [f.chiave, f.percentuale, f.spenta, f.rossa])).toEqual([
            ['1_30', 10, false, false], ['31_90', 30, false, false], ['oltre_90', 60, false, true],
        ]);
        expect(fasceDellaBarra({ '1_30': 0, '31_90': 5000, oltre_90: 0 }).map((f) => [f.percentuale, f.spenta, f.rossa])).toEqual([[0, true, false], [100, false, false], [0, true, false]]);
    });
});

describe('le azioni di ogni persona', () => {
    const persona = (passo: string | null, caso = 'R15') => ({ passo: { caso, passo, frase: 'Nessuna lettera.', frase_dei_fatti: 'Nessuna lettera.', note: [], rata_compensa: passo === 'compensa' ? 77 : null } });

    test('il sollecito solo quando la regola lo suggerisce; «Registra l\'incasso» sempre, «Compensa» al suo posto con R2', () => {
        expect(azioniDellaPersona(persona('sollecito'), true)).toEqual({ incasso: 'incasso', lettera: 'sollecito', neiSeiMesi: false });
        expect(azioniDellaPersona(persona('compensa', 'R2'), true)).toEqual({ incasso: 'compensa', lettera: null, neiSeiMesi: false });
        expect(azioniDellaPersona(persona(null, 'R8'), true)).toEqual({ incasso: 'incasso', lettera: null, neiSeiMesi: false });
    });

    test('la diffida, la spedizione, il fascicolo, l\'accordo, «hai agito»: la prossima azione è nei sei mesi', () => {
        for (const passo of ['diffida', 'spedizione', 'fascicolo', 'accordo', 'agito']) {
            expect(azioniDellaPersona(persona(passo, 'R11'), true)).toEqual({ incasso: 'incasso', lettera: null, neiSeiMesi: true });
        }
    });

    test('con «Scelgo io la prossima azione» «Scrivi una lettera» per tutti, e niente «Compensa» suggerito', () => {
        expect(azioniDellaPersona(persona(null), false)).toEqual({ incasso: 'incasso', lettera: 'scrivi', neiSeiMesi: false });
        expect(azioniDellaPersona({ passo: null }, false)).toEqual({ incasso: 'incasso', lettera: 'scrivi', neiSeiMesi: false });
        expect(azioniDellaPersona({ passo: null }, true)).toEqual({ incasso: 'incasso', lettera: null, neiSeiMesi: false });
    });
});

describe('«Da dove viene»', () => {
    test('il nome di ogni riga con il suo tipo', () => {
        expect(nomeDellaRiga({ voce: 'Banca Intesa', tipo: 'banca', attiva: true })).toBe('Banca Intesa (conto corrente)');
        expect(nomeDellaRiga({ voce: 'Vecchio conto', tipo: 'banca', attiva: false })).toBe('Vecchio conto (conto corrente, disattivata)');
        expect(nomeDellaRiga({ voce: 'Cassa contanti', tipo: 'contanti', attiva: true })).toBe('Cassa contanti');
        expect(nomeDellaRiga({ voce: 'Piccola cassa', tipo: 'contanti', attiva: true })).toBe('Piccola cassa (contanti)');
        expect(nomeDellaRiga({ voce: 'PayPal', tipo: 'virtuale', attiva: true })).toBe('PayPal (cassa virtuale)');
        expect(nomeDellaRiga({ voce: '1020 Banca Posta', tipo: 'senza_cassa', attiva: true })).toBe('1020 Banca Posta (conto senza cassa)');
        expect(nomeDellaRiga({ voce: 'Saldi di apertura non ancora registrati a giornale', tipo: 'non_registrati', attiva: true })).toBe('Saldi di apertura non ancora registrati a giornale');
        expect(nomeDellaRiga({ voce: 'Fondo di riserva', tipo: 'fondo', attiva: true })).toBe('Fondo di riserva');
    });

    test('i movimenti dopo oggi: quanti, la cifra, il primo; quelli nei 30 giorni entrano nel flusso', () => {
        expect(testoFuturi({ quanti: 2, cifra: 30000, primo: '2026-11-20', nel_flusso_quanti: 2, nel_flusso_cifra: 30000 })).toBe(
            '2 movimenti con una data dopo oggi, € 300,00 (il primo il 20/11/2026): non sono nella cifra di oggi; entrano nel saldo previsto al loro giorno.');
        expect(testoFuturi({ quanti: 1, cifra: -9000, primo: '2026-12-25', nel_flusso_quanti: 0, nel_flusso_cifra: 0 })).toBe(
            '1 movimento con una data dopo oggi, −€ 90,00 (il 25/12/2026): non è nella cifra di oggi, e cade dopo i 30 giorni del saldo previsto.');
        expect(testoFuturi({ quanti: 3, cifra: 20000, primo: '2026-11-20', nel_flusso_quanti: 2, nel_flusso_cifra: 13000 })).toBe(
            '3 movimenti con una data dopo oggi, € 200,00 (il primo il 20/11/2026): non sono nella cifra di oggi; 2, € 130,00, entrano nel saldo previsto al loro giorno.');
        expect(testoFuturi({ quanti: 0, cifra: 0, primo: null, nel_flusso_quanti: 0, nel_flusso_cifra: 0 })).toBeNull();
    });

    test('il saldo della banca scritto nella nota, con la sua data e il rendiconto; le due frasi fisse', () => {
        expect(testoNotaBanca({ cassa: 'Banca Intesa', data: '2025-12-31', saldo: 470000, esercizio: 'Esercizio 2025' })).toBe(
            'Nella nota del rendiconto «Esercizio 2025» hai scritto il saldo dell\'estratto conto di «Banca Intesa» al 31/12/2025: € 4.700,00.');
        expect(FRASE_ESTRATTO).toBe('Secondo le registrazioni fino a oggi: non è il saldo dell\'estratto conto della banca.');
        expect(FRASE_FONDI).toBe('I fondi sono parti del conto corrente: l\'estratto della banca li comprende.');
    });
});
