/**
 * Il portale del condòmino: una rata non ancora emessa non è mai «Scaduta».
 *
 * Il promemoria della rata nasce all'approvazione del piano, prima dell'emissione, ed è visibile al condòmino. L'etichetta
 * guardava solo la data: una rata mai emessa, con la data passata, diceva «Scaduta» per un importo che nessuno aveva ancora
 * chiesto. Ora dice «In attesa di emissione», in grigio, prima e dopo la data: un fatto che il programma sa (la rata non è emessa nel
 * programma), con le parole della finestra del portale; che non sia stata chiesta il programma non lo sa. Un promemoria senza il campo
 * `is_emitted` (scritto prima che il campo esistesse) resta com'era.
 */
import { describe, expect, test } from 'vitest';
import { useEventStyling } from '@/composables/useEventStyling';

const { getEventStyle } = useEventStyling();
const rata = (start: string, meta: Record<string, unknown>) => ({
    start_time: start,
    meta: { type: 'scadenza_rata_condomino', status: 'pending', numero_rata: 1, importo_originale: 10000, importo_restante: 10000, ...meta },
});
const fra = (giorni: number) => new Date(Date.now() + giorni * 86400000).toISOString();

describe('una rata non emessa nel portale', () => {
    test('con la data passata dice «In attesa di emissione», non «Scaduta»', () => {
        const s = getEventStyle(rata('2020-01-10T00:00:00Z', { is_emitted: false }));

        expect(s.label).toBe('In attesa di emissione');
        expect(s.color).not.toContain('red');
    });

    test('emessa, con la data passata, è «Scaduta»', () => {
        expect(getEventStyle(rata('2020-01-10T00:00:00Z', { is_emitted: true })).label).toBe('Scaduta');
    });

    test('un promemoria di prima, senza `is_emitted`, resta com\'era', () => {
        expect(getEventStyle(rata('2020-01-10T00:00:00Z', {})).label).toBe('Scaduta');
    });

    test('non emessa e in scadenza fra tre o dieci giorni: «In attesa di emissione», in grigio, non il rosso o il giallo della scadenza', () => {
        for (const giorni of [3, 10]) {
            const s = getEventStyle(rata(fra(giorni), { is_emitted: false }));
            expect(s.label).toBe('In attesa di emissione');
            expect(s.color).not.toMatch(/red|yellow/);
        }
    });

    test('nessuna etichetta del portale per una rata non emessa contiene «richiesta»', () => {
        for (const giorni of [-40, -1, 0, 1, 10, 20]) {
            expect(getEventStyle(rata(fra(giorni), { is_emitted: false })).label).not.toContain('richiesta');
        }
    });

    // Dalla 1.12.0-beta.4 la rata 0 non emessa dice anche lei «In attesa di emissione»: prima diceva «Debito Pregresso» di un importo che il
    // programma non ha ancora chiesto. Emessa, resta in ambra con «Debito Pregresso».
    test('la rata 0 non emessa dice «In attesa di emissione», non «Debito Pregresso»', () => {
        expect(getEventStyle(rata('2020-01-10T00:00:00Z', { is_emitted: false, numero_rata: 0 })).label).toBe('In attesa di emissione');
    });

    test('la rata 0 emessa resta «Debito Pregresso», e un promemoria di prima senza `is_emitted` anche', () => {
        expect(getEventStyle(rata('2020-01-10T00:00:00Z', { is_emitted: true, numero_rata: 0 })).label).toBe('Debito Pregresso');
        expect(getEventStyle(rata('2020-01-10T00:00:00Z', { numero_rata: 0 })).label).toBe('Debito Pregresso');
    });
});
