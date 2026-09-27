import { describe, expect, test } from 'vitest';
import { avvisoFatturaNelCarrello, notaCollegataNelCarrello, senzaCompetenzaScelte } from './avvisoCarrello';

const ORDINARIA = { gestioneStraordinaria: false, urgenza: false };
const DELIBERA = { gestioneStraordinaria: true, urgenza: false };
const URGENZA = { gestioneStraordinaria: true, urgenza: true };
const PREGRESSA_SENZA = { ha_competenza: false, senza_periodo: true, selezionata: true };

describe('avvisoFatturaNelCarrello — la pregressa senza periodo segue la gestione (R3 della Fase 1-bis)', () => {
    test('sull\'ordinaria: i giorni di quest\'anno', () => {
        expect(avvisoFatturaNelCarrello(PREGRESSA_SENZA, ORDINARIA)?.testo).toContain('giorni di quest\'anno');
    });

    test('sulla straordinaria con la delibera: tutto a chi è titolare quel giorno, non i giorni dell\'anno', () => {
        const a = avvisoFatturaNelCarrello(PREGRESSA_SENZA, DELIBERA);
        expect(a?.testo).toContain('alla data della delibera');
        expect(a?.testo).not.toContain('giorni di quest\'anno');
    });

    test('con «Urgenza» l\'arresto torna a vedersi, con la via: storno e nuova registrazione', () => {
        const a = avvisoFatturaNelCarrello(PREGRESSA_SENZA, URGENZA);
        expect(a?.testo).toContain('il riparto si fermerà su questa fattura');
        expect(a?.testo).toContain('si storna e si registra di nuovo');
        expect(a?.testo).not.toContain('giorni di quest\'anno');
    });

    test('il segno «senza periodo dichiarato» resta in tutti e tre i casi (decisione 26, punto 3)', () => {
        for (const c of [ORDINARIA, DELIBERA, URGENZA]) {
            expect(avvisoFatturaNelCarrello(PREGRESSA_SENZA, c)?.testo).toContain('Pregressa senza periodo dichiarato');
        }
    });
});

describe('avvisoFatturaNelCarrello — il resto non cambia', () => {
    test('una corrente senza competenza, scelta, con «Urgenza»: il riparto si fermerà', () => {
        expect(avvisoFatturaNelCarrello({ ha_competenza: false, selezionata: true }, URGENZA)?.testo)
            .toBe('Senza competenza dichiarata: il riparto si fermerà su questa fattura.');
    });

    test('una corrente senza competenza fuori dall\'urgenza, o non scelta: niente', () => {
        expect(avvisoFatturaNelCarrello({ ha_competenza: false, selezionata: true }, DELIBERA)).toBeNull();
        expect(avvisoFatturaNelCarrello({ ha_competenza: false, selezionata: false }, URGENZA)).toBeNull();
    });

    test('con la competenza dichiarata la riga la mostra, in grigio', () => {
        expect(avvisoFatturaNelCarrello({ ha_competenza: true, competenza: '01/01/2025 – 31/12/2025' }, ORDINARIA))
            .toEqual({ tono: 'nota', testo: 'Competenza dichiarata: 01/01/2025 – 31/12/2025' });
    });
});

describe('avvisoFatturaNelCarrello — il periodo dentro l\'esercizio (R2 della Fase 1-bis)', () => {
    test('una pregressa con la data dell\'assemblea di quest\'anno come periodo: l\'avviso accanto alla competenza', () => {
        const a = avvisoFatturaNelCarrello({ ha_competenza: true, competenza: 'deliberata il 10/03/2026', periodo_nell_esercizio: true }, ORDINARIA);
        expect(a?.tono).toBe('avviso');
        expect(a?.testo).toContain('deliberata il 10/03/2026');
        expect(a?.testo).toContain('non si chiude prima di quest\'esercizio');
        expect(a?.testo).toContain('si storna e si registra di nuovo');
    });
});

describe('senzaCompetenzaScelte — il riquadro dell\'urgenza conta a parte le pregresse (R20 della Fase 1-bis)', () => {
    test('le correnti si dichiarano, le pregresse si stornano: due conti diversi', () => {
        expect(senzaCompetenzaScelte([
            { ha_competenza: false, selezionata: true },
            { ha_competenza: false, selezionata: true, senza_periodo: true },
            { ha_competenza: false, selezionata: false, senza_periodo: true },
            { ha_competenza: true, selezionata: true },
        ])).toEqual({ correnti: 1, pregresse: 1 });
    });
});

describe('notaCollegataNelCarrello (Coda 165, 1.11.0-beta.36)', () => {
    test('senza note collegate non dice niente', () => {
        expect(notaCollegataNelCarrello({ totale_straordinario: 1000, totale_netto: 1000, note_collegate: [] })).toBeNull();
        expect(notaCollegataNelCarrello({ totale_straordinario: 1000 })).toBeNull();
    });

    test('la fattura al netto dice di quanto e perché', () => {
        expect(notaCollegataNelCarrello({ totale_straordinario: 1000, totale_netto: 700, note_collegate: [{ numero: 'NC-PAR', importo: 300 }] }))
            .toBe('Al netto della nota di credito n. NC-PAR: la fattura vale € 700,00 per il piano, invece di € 1.000,00.');
    });

    test('più note si nominano tutte', () => {
        expect(notaCollegataNelCarrello({ totale_straordinario: 1000, totale_netto: 500, note_collegate: [{ numero: 'A', importo: 200 }, { numero: 'B', importo: 300 }] }))
            .toContain('delle note di credito n. A e n. B');
    });

    test('una nota che non riduce la parte del piano lo dice, senza dire dove sta (a preventivo o non attribuibile)', () => {
        expect(notaCollegataNelCarrello({ totale_straordinario: 700, totale_netto: 700, note_collegate: [{ numero: 'NC-P', importo: 100 }] }))
            .toBe('La nota di credito n. NC-P non riduce la parte che il piano finanzia, che resta € 700,00.');
    });
});
