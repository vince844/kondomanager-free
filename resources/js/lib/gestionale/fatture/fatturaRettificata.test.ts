/**
 * I testi della fattura che una nota di credito rettifica (Coda 165, 1.11.0-beta.36). Le regole le decide il server;
 * qui si prova che il modulo dica quello che il server ha deciso, e che nessuna proposta nasca dove il file è ambiguo.
 */
import { describe, expect, test } from 'vitest';
import { dettaglioCandidata, esitoCorrente, etichettaBreve, etichettaCandidata, euro, giorno, righeNotaPerCandidate, statoScelta, testoEsitoXml, type FatturaRettificabile } from './fatturaRettificata';

const candidata = (extra: Partial<FatturaRettificabile> = {}): FatturaRettificabile => ({
    id: 7,
    numero_documento: 'FT-28',
    data_documento: '2025-12-22',
    totale_documento: 100000,
    esercizio_nome: 'Esercizio 2025',
    is_pregresso: false,
    gia_rettificato: 0,
    motivo_blocco_nota: null,
    avviso_nota: null,
    ...extra,
});

describe('euro e giorno', () => {
    test('il simbolo prima e il punto delle migliaia anche sotto i diecimila', () => {
        expect(euro(100000)).toBe('€ 1.000,00');
        expect(euro(30000)).toBe('€ 300,00');
        expect(euro(123456789)).toBe('€ 1.234.567,89');
        expect(euro(5)).toBe('€ 0,05');
        expect(euro(-30000)).toBe('€ -300,00');
    });

    test('la data in formato italiano, e quello che non è una data resta com\'è', () => {
        expect(giorno('2025-12-22')).toBe('22/12/2025');
        expect(giorno(null)).toBe('');
        expect(giorno('boh')).toBe('boh');
    });
});

describe('etichettaCandidata', () => {
    test('numero, data, totale ed esercizio', () => {
        expect(etichettaCandidata(candidata())).toBe('n. FT-28 del 22/12/2025 — € 1.000,00 · Esercizio 2025');
    });

    test('dice quanto è già stato rettificato da altre note', () => {
        expect(etichettaCandidata(candidata({ gia_rettificato: 30000, esercizio_nome: null })))
            .toBe('n. FT-28 del 22/12/2025 — € 1.000,00 (già rettificata per € 300,00)');
    });
});

describe('testoEsitoXml', () => {
    test('nessuna fattura dichiarata: niente da dire', () => {
        expect(testoEsitoXml(null)).toBeNull();
        expect(testoEsitoXml({ esito: 'nessuna_dichiarata', proposta: null, dichiarate: [] })).toBeNull();
    });

    test('la proposta si dichiara come proposta, da controllare', () => {
        const t = testoEsitoXml({ esito: 'proposta', proposta: candidata(), dichiarate: [{ numero: 'FT-28', data: '2025-12-22' }] });
        expect(t).toContain('n. FT-28 del 22/12/2025');
        expect(t).toContain('Controlla');
    });

    test('più fatture dichiarate: nessuna proposta, e le nomina tutte', () => {
        const t = testoEsitoXml({ esito: 'piu_dichiarate', proposta: null, dichiarate: [{ numero: 'FT-28', data: null }, { numero: 'FT-29', data: null }] });
        expect(t).toContain('2 fatture');
        expect(t).toContain('n. FT-28, n. FT-29');
        expect(t).toContain('nessuna proposta');
    });

    test('senza data non si propone, e dice perché', () => {
        expect(testoEsitoXml({ esito: 'senza_data', proposta: null, dichiarate: [{ numero: 'FT-28', data: null }] })).toContain('gennaio');
    });

    test('non trovata e fornitore da scegliere dicono che cosa fare', () => {
        expect(testoEsitoXml({ esito: 'non_trovata', proposta: null, dichiarate: [{ numero: 'FT-28', data: '2025-12-22' }] })).toContain('registrala prima');
        expect(testoEsitoXml({ esito: 'fornitore_da_scegliere', proposta: null, dichiarate: [{ numero: 'FT-28', data: '2025-12-22' }] })).toContain('Scegli prima il fornitore');
    });
});

describe('statoScelta', () => {
    test('niente scelto: niente motivo né avviso', () => {
        expect(statoScelta([candidata()], null)).toEqual({ scelta: null, motivo: null, avviso: null });
    });

    test('il motivo del server blocca, e allora l\'avviso non si mostra', () => {
        const c = candidata({ motivo_blocco_nota: 'La fattura è nel piano…', avviso_nota: 'resta' });
        expect(statoScelta([c], 7)).toMatchObject({ motivo: 'La fattura è nel piano…', avviso: null });
    });

    test('l\'avviso di un piano che ha incassato arriva quando non c\'è blocco', () => {
        expect(statoScelta([candidata({ avviso_nota: 'Le rate restano' })], 7)).toMatchObject({ motivo: null, avviso: 'Le rate restano' });
    });

    test('un id che non è fra le candidate non si inventa una scelta', () => {
        expect(statoScelta([candidata()], 99).scelta).toBeNull();
    });
});

describe('etichettaBreve e dettaglioCandidata: le due righe del menu del modulo', () => {
    test('la prima identifica la fattura, la seconda la descrive', () => {
        const c = candidata({ gia_rettificato: 5000 });
        expect(etichettaBreve(c)).toBe('n. FT-28 del 22/12/2025');
        expect(dettaglioCandidata(c)).toBe('€ 1.000,00 · già rettificata per € 50,00 · Esercizio 2025');
    });
});

describe('esitoCorrente: l\'esito del file rifatto sulle candidate fresche (R8 della Fase 1-bis)', () => {
    const letto = { esito: 'non_trovata' as const, proposta: null, dichiarate: [{ numero: 'FT-28', data: '2025-12-22' }] };

    test('la fattura registrata dopo la lettura del file si propone', () => {
        const e = esitoCorrente(letto, [candidata({ corrisponde_al_file: true })], { fornitoreScelto: true, caricamento: false });
        expect(e?.esito).toBe('proposta');
        expect(e?.proposta?.id).toBe(7);
    });

    test('due fatture con quel numero e quella data: ambigua, e il testo non dice che non c\'è', () => {
        const e = esitoCorrente(letto, [candidata({ corrisponde_al_file: true }), candidata({ id: 8, corrisponde_al_file: true })], { fornitoreScelto: true, caricamento: false });
        expect(e?.esito).toBe('ambigua');
        expect(testoEsitoXml(e)).toContain('più d\'una');
    });

    test('mentre carica non dice niente; senza fornitore chiede il fornitore', () => {
        expect(esitoCorrente(letto, [], { fornitoreScelto: true, caricamento: true })).toBeNull();
        expect(esitoCorrente(letto, [], { fornitoreScelto: false, caricamento: false })?.esito).toBe('fornitore_da_scegliere');
    });

    test('quello che dipende solo dal file resta com\'era', () => {
        const piu = { esito: 'piu_dichiarate' as const, proposta: null, dichiarate: [{ numero: 'A', data: null }, { numero: 'B', data: null }] };
        expect(esitoCorrente(piu, [candidata({ corrisponde_al_file: true })], { fornitoreScelto: true, caricamento: false })).toBe(piu);
    });
});

describe('righeNotaPerCandidate (R4 della Fase 1-bis)', () => {
    test('la nota pregressa vale la testata, non attribuibile', () => {
        expect(righeNotaPerCandidate({ isPregresso: true, totaleCents: -61000, righe: [] })).toEqual([{ conto_id: null, immobile_id: null, riduzione: 61000 }]);
    });

    test('una riga su un\'unità non porta la voce; le righe a zero si tolgono', () => {
        expect(righeNotaPerCandidate({ isPregresso: false, totaleCents: 0, righe: [
            { conto_id: 5, immobile_id: 12, lordoCents: 3000 },
            { conto_id: 5, immobile_id: null, lordoCents: 2000 },
            { conto_id: 6, immobile_id: null, lordoCents: 0 },
        ] })).toEqual([
            { conto_id: null, immobile_id: 12, riduzione: 3000 },
            { conto_id: 5, immobile_id: null, riduzione: 2000 },
        ]);
    });
});

describe('esitoCorrente: errore di caricamento e campo vuoto (verifica delle correzioni)', () => {
    const letto = { esito: 'proposta' as const, proposta: null, dichiarate: [{ numero: 'FT-28', data: '2025-12-22' }] };

    test('se il caricamento fallisce non dice «non ce n\'è una»', () => {
        expect(esitoCorrente(letto, [], { fornitoreScelto: true, caricamento: false, errore: true })).toBeNull();
    });

    test('la fattura dichiarata c\'è ma nel campo no: non dice «è proposta qui sopra»', () => {
        const e = esitoCorrente(letto, [candidata({ corrisponde_al_file: true })], { fornitoreScelto: true, caricamento: false, sceltaId: null });
        expect(e?.esito).toBe('dichiarata_non_scelta');
        expect(testoEsitoXml(e)).toContain('nel campo non c\'è');
    });
});

test('W10 [terzo giro] — senza fornitore, anche con più fatture o senza data, prima si sceglie il fornitore', () => {
    const senzaData = { esito: 'senza_data' as const, proposta: null, dichiarate: [{ numero: 'FT-28', data: null }] };
    expect(esitoCorrente(senzaData, [], { fornitoreScelto: false, caricamento: false })?.esito).toBe('fornitore_da_scegliere');
    expect(esitoCorrente(senzaData, [], { fornitoreScelto: true, caricamento: false })?.esito).toBe('senza_data');
});

test('quarto giro — senza fornitore, con più fatture dichiarate, le nomina tutte', () => {
    const piu = { esito: 'piu_dichiarate' as const, proposta: null, dichiarate: [{ numero: 'FT-28', data: '2025-12-22' }, { numero: 'FT-29', data: null }] };
    const e = esitoCorrente(piu, [], { fornitoreScelto: false, caricamento: false });
    expect(testoEsitoXml(e)).toContain('2 fatture (n. FT-28, n. FT-29)');
});
