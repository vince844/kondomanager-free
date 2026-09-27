import { describe, expect, it } from 'vitest';
import { erroreDelPeriodoPregressa } from './periodoPregressa';

describe('erroreDelPeriodoPregressa — la gemella della regola del server (decisione 26)', () => {
    it('senza una parte non coperta non chiede niente', () => {
        expect(erroreDelPeriodoPregressa({ periodoObbligatorio: false })).toBeNull();
    });

    it('con una parte non coperta il periodo è obbligatorio, tutti e due gli estremi', () => {
        expect(erroreDelPeriodoPregressa({ periodoObbligatorio: true, dal: '', al: '' })?.campo).toBe('competenza_dal');
        expect(erroreDelPeriodoPregressa({ periodoObbligatorio: true, dal: '2025-01-01', al: '' })?.campo).toBe('competenza_dal');
    });

    it('il periodo si chiude prima dell\'esercizio: fino al 31/12/2025 va bene, fino al 01/01/2026 no', () => {
        const base = { periodoObbligatorio: true, dal: '2025-01-01', inizioEsercizio: '2026-01-01' };
        expect(erroreDelPeriodoPregressa({ ...base, al: '2025-12-31' })).toBeNull();
        const e = erroreDelPeriodoPregressa({ ...base, al: '2026-01-01' });
        expect(e?.campo).toBe('competenza_al');
        expect(e?.messaggio).toContain('01/01/2026');
    });
});
