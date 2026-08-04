/**
 * Da dove parte il calendario delle rate, lato interfaccia.
 *
 * È il gemello di `tests/Feature/Gestionale/CalendarioPartenzaTest.php`, che fissa la stessa
 * regola in PHP. Lo stesso valore è calcolato due volte in due linguaggi — qui per mostrarlo
 * prima di salvare, là per generare davvero le date — ed è lo schema che nella beta.35 è
 * costato un centesimo di divergenza sul netto da pagare: nessuna delle due copie era
 * sbagliata da sola.
 *
 * Se un domani cambia questo lato, è il test PHP ad accendersi e a ricordare che esiste anche
 * l'altro. E viceversa.
 */

import { describe, expect, it } from 'vitest';
import { partenzaCalendario, partenzaEreditata } from './calendario';

describe('partenzaCalendario', () => {
    it('usa la data scelta dall amministratore quando c è', () => {
        expect(partenzaCalendario('2026-09-30', '2026-01-01')).toBe('2026-09-30');
    });

    it('eredita l inizio della gestione quando la data non è stata scelta', () => {
        expect(partenzaCalendario(null, '2026-01-01')).toBe('2026-01-01');
    });

    /**
     * Il campo svuotato produce una stringa vuota, non `null`: se le due cose non fossero
     * trattate allo stesso modo, cancellare la data lascerebbe il piano fermo su una partenza
     * che a schermo non si vede più.
     */
    it('tratta vuoto, null e undefined nello stesso modo', () => {
        expect(partenzaCalendario('', '2026-01-01')).toBe('2026-01-01');
        expect(partenzaCalendario(null, '2026-01-01')).toBe('2026-01-01');
        expect(partenzaCalendario(undefined, '2026-01-01')).toBe('2026-01-01');
        expect(partenzaCalendario('   ', '2026-01-01')).toBe('2026-01-01');
    });

    /**
     * Il server manda le date con l'orario attaccato. Tagliare la stringa invece di costruire
     * un `Date` non è pigrizia: `new Date('2026-01-01T00:00:00Z')` in un fuso a ovest di
     * Greenwich torna indietro di un giorno, e la prima rata scadrebbe il 31 dicembre.
     */
    it('ignora la parte oraria senza spostare il giorno', () => {
        expect(partenzaCalendario('2026-09-30T00:00:00.000000Z', null)).toBe('2026-09-30');
        expect(partenzaCalendario(null, '2026-01-01 00:00:00')).toBe('2026-01-01');
    });

    it('senza nemmeno l inizio gestione restituisce null, non una data inventata', () => {
        expect(partenzaCalendario(null, null)).toBeNull();
        expect(partenzaCalendario('', '')).toBeNull();
    });

    it('rifiuta ciò che non è una data invece di propagarlo', () => {
        expect(partenzaCalendario('domani', '2026-01-01')).toBe('2026-01-01');
        expect(partenzaCalendario('30/09/2026', '2026-01-01')).toBe('2026-01-01');
    });
});

describe('partenzaEreditata', () => {
    /**
     * La distinzione serve all'interfaccia: una data ereditata va nel **segnaposto**, non nel
     * campo. Scriverla nel campo la trasformerebbe, al primo salvataggio, in una scelta
     * esplicita che nessuno ha fatto — e il piano smetterebbe di seguire la gestione.
     */
    it('è vera solo quando l amministratore non ha scelto', () => {
        expect(partenzaEreditata(null)).toBe(true);
        expect(partenzaEreditata('')).toBe(true);
        expect(partenzaEreditata(undefined)).toBe(true);
        expect(partenzaEreditata('2026-09-30')).toBe(false);
    });
});
