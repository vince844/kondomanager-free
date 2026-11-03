/**
 * La competenza per voce (B2, S6): il preset della stagione tagliato sull'esercizio, la base che il
 * motore userebbe, e le tre regole della Request dette prima di salvare. Gemello di
 * `tests/Feature/Gestionale/CompetenzaCapitoloStoreTest.php` per le regole, e del test del motore
 * «due tratti (zona E)» per la forma dei tratti.
 */

import { describe, expect, it } from 'vitest';
import {
    descriviTratti,
    periodoGestione,
    presetDaiTratti,
    trattiDelPreset,
    trattiStagione,
    verificaTratti,
} from './competenzaCapitolo';

const solare = { data_inizio: '2026-01-01T00:00:00.000000Z', data_fine: '2026-12-31T00:00:00.000000Z' };
const scavalcato = { data_inizio: '2025-07-01', data_fine: '2026-06-30' };

describe('trattiStagione', () => {
    it('su un esercizio solare fa due tratti, senza toccare il 15 aprile dell altro', () => {
        expect(trattiStagione(solare)).toEqual([
            { dal: '2026-01-01', al: '2026-04-15' },
            { dal: '2026-10-15', al: '2026-12-31' },
        ]);
    });

    it('su un esercizio 1/7–30/6 la stessa stagione è un tratto solo', () => {
        expect(trattiStagione(scavalcato)).toEqual([{ dal: '2025-10-15', al: '2026-04-15' }]);
    });

    it('su un esercizio che parte il 1° maggio fa due tratti diversi da quelli solari', () => {
        expect(trattiStagione({ data_inizio: '2026-05-01', data_fine: '2027-04-30' })).toEqual([
            { dal: '2026-10-15', al: '2027-04-15' },
        ]);
    });

    it('senza le date dell esercizio non inventa niente', () => {
        expect(trattiStagione({ data_inizio: null, data_fine: '2026-12-31' })).toEqual([]);
    });
});

describe('periodoGestione', () => {
    it('è gestione ∩ esercizio quando la gestione ha le sue date', () => {
        expect(periodoGestione({ data_inizio: '2025-06-01', data_fine: '2026-05-31' }, solare)).toEqual({ dal: '2026-01-01', al: '2026-05-31' });
    });

    it('è l esercizio quando la gestione non ha date', () => {
        expect(periodoGestione({ data_inizio: null, data_fine: null }, solare)).toEqual({ dal: '2026-01-01', al: '2026-12-31' });
    });

    it('è null se l intersezione è vuota', () => {
        expect(periodoGestione({ data_inizio: '2027-01-01', data_fine: '2027-12-31' }, solare)).toBeNull();
    });
});

describe('trattiDelPreset e presetDaiTratti', () => {
    it('gestione non produce tratti; esercizio ne produce uno; si rileggono al contrario', () => {
        expect(trattiDelPreset('gestione', solare)).toEqual([]);
        expect(trattiDelPreset('esercizio', solare)).toEqual([{ dal: '2026-01-01', al: '2026-12-31' }]);
        expect(presetDaiTratti([], solare)).toBe('gestione');
        expect(presetDaiTratti([{ dal: '2026-01-01', al: '2026-12-31' }], solare)).toBe('esercizio');
        expect(presetDaiTratti([{ dal: '2026-10-15', al: '2026-12-31' }, { dal: '2026-01-01', al: '2026-04-15' }], solare)).toBe('stagione');
        expect(presetDaiTratti([{ dal: '2026-03-01', al: '2026-03-31' }], solare)).toBe('manuale');
    });
});

describe('verificaTratti', () => {
    it('passa la stagione e i tratti a mano ben formati', () => {
        expect(verificaTratti(trattiStagione(solare), solare)).toBeNull();
        expect(verificaTratti([{ dal: '2026-03-01', al: '2026-03-31' }], solare)).toBeNull();
    });

    it('rifiuta il giorno in comune, la fine prima dell inizio, il tratto fuori esercizio, la data mancante', () => {
        expect(verificaTratti([{ dal: '2026-01-01', al: '2026-04-15' }, { dal: '2026-04-15', al: '2026-12-31' }], solare)).toContain('giorno in comune');
        expect(verificaTratti([{ dal: '2026-04-15', al: '2026-01-01' }], solare)).toContain('non può precedere');
        expect(verificaTratti([{ dal: '2025-10-15', al: '2026-04-15' }], solare)).toContain('dentro l’esercizio');
        expect(verificaTratti([{ dal: '2026-01-01', al: '' }], solare)).toContain('tutte e due le date');
    });
});

describe('descriviTratti', () => {
    it('omette l anno quando è uno solo e lo tiene quando i tratti lo attraversano', () => {
        expect(descriviTratti(trattiStagione(solare))).toBe('01/01 – 15/04 + 15/10 – 31/12');
        expect(descriviTratti(trattiStagione(scavalcato))).toBe('15/10/2025 – 15/04/2026');
        expect(descriviTratti([])).toBe('—');
        expect(descriviTratti([{ dal: '', al: '' }])).toBe('—');
    });
});
