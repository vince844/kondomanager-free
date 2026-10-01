import { describe, expect, test } from 'vitest';
import tabella from '../../../../../tests/Fixtures/piano_credito_incasso.json';
import { pianifica, pianificaCoperture } from './pianoCreditoIncasso';

/**
 * Lo specchio nel modulo del pianificatore del server (`App\Services\Gestionale\PianoCreditoIncasso`), sulla stessa
 * tabella di casi (Coda 167, decisioni 30.7 e 30.11). Se i due smettono di calcolare uguale, uno dei due test è rosso.
 */
describe('pianifica: la stessa risposta del server, sulla tabella condivisa', () => {
    for (const caso of tabella.casi) {
        test(caso.nome, () => {
            const piano = pianifica(caso.righe, caso.crediti, caso.contante, caso.credito_prima, caso.fra_gestioni);

            expect(piano.credito).toEqual(caso.atteso.credito);
            expect(piano.contante).toEqual(caso.atteso.contante);
            expect(piano.usato).toBe(caso.atteso.usato);
            expect(piano.serve_scelta).toBe(caso.atteso.serve_scelta);
        });
    }
});

/** Quello che il server farà con ciò che il modulo manda: deve coincidere con quello che il modulo ha mostrato. */
function rigiocaSulServer(
    debiti: Array<{ chiave: number; gestione: number | null }>,
    crediti: Array<{ chiave: number; gestione: number | null }>,
    contante: number,
    creditoPrima: boolean,
    fraGestioni: boolean | null,
    esito: ReturnType<typeof pianificaCoperture>,
) {
    const righe = debiti
        .filter(d => (esito.copertura[d.chiave] ?? 0) > 0)
        .map(d => ({ chiave: d.chiave, importo: esito.copertura[d.chiave], gestione: d.gestione }));
    const usati = crediti
        .filter(c => (esito.creditoUsato[c.chiave] ?? 0) > 0)
        .map(c => ({ chiave: c.chiave, importo: esito.creditoUsato[c.chiave], gestione: c.gestione }));

    return pianifica(righe, usati, contante, creditoPrima, fraGestioni);
}

describe('pianificaCoperture: le righe che il modulo manda', () => {
    const ORD = 1;
    const TETTO = 2;

    test('prima i soldi, una gestione: il credito copre lo scoperto, le rate dalla più vecchia', () => {
        const debiti = [{ chiave: 1, residuo: 10000, gestione: ORD }, { chiave: 2, residuo: 10000, gestione: ORD }, { chiave: 3, residuo: 10000, gestione: ORD }];
        const crediti = [{ chiave: 9, disponibile: 5000, gestione: ORD }];

        const esito = pianificaCoperture(debiti, crediti, 15000, false, null);

        expect(esito.copertura).toEqual({ 1: 10000, 2: 10000, 3: 0 });
        expect(esito.creditoUsato).toEqual({ 9: 5000 });
        expect(esito.eccedenza).toBe(0);
        expect(esito.serveScelta).toBe(false);
    });

    test('prima i soldi, e i soldi bastano: il credito non si usa', () => {
        const esito = pianificaCoperture([{ chiave: 1, residuo: 30000, gestione: ORD }], [{ chiave: 9, disponibile: 10000, gestione: ORD }], 30000, false, null);

        expect(esito.copertura).toEqual({ 1: 30000 });
        expect(esito.creditoUsato).toEqual({});
        expect(esito.eccedenza).toBe(0);
    });

    test('prima il credito: copre per primo, e quello che avanza dei soldi è la parte in più', () => {
        const esito = pianificaCoperture([{ chiave: 1, residuo: 30000, gestione: ORD }], [{ chiave: 9, disponibile: 10000, gestione: ORD }], 30000, true, null);

        expect(esito.copertura).toEqual({ 1: 30000 });
        expect(esito.creditoUsato).toEqual({ 9: 10000 });
        expect(esito.eccedenza).toBe(10000);
    });

    test('T1: il tetto più vecchio prende i soldi, il credito dell\'ordinaria chiude l\'ordinaria, senza nessuna domanda', () => {
        // Debito B del tetto (il più vecchio) € 100, debito A dell'ordinaria € 100, credito ordinario € 100, versati € 50.
        // Il budget unico per scadenza dava i soldi al tetto e lasciava il credito fermo (reperto T1 del terzo giro).
        const debiti = [{ chiave: 1, residuo: 10000, gestione: TETTO }, { chiave: 2, residuo: 10000, gestione: ORD }];
        const crediti = [{ chiave: 9, disponibile: 10000, gestione: ORD }];

        const soldiPrima = pianificaCoperture(debiti, crediti, 5000, false, null);
        expect(soldiPrima.copertura).toEqual({ 1: 5000, 2: 10000 });
        expect(soldiPrima.creditoUsato).toEqual({ 9: 10000 });
        expect(soldiPrima.serveScelta).toBe(false);

        expect(pianificaCoperture(debiti, crediti, 0, false, null).copertura).toEqual({ 1: 0, 2: 10000 });

        const creditoPrima = pianificaCoperture(debiti, crediti, 5000, true, null);
        expect(creditoPrima.creditoUsato).toEqual({ 9: 10000 });
        expect(creditoPrima.eccedenza).toBe(0);
    });

    test('T1, il controcaso: l\'ordinaria più vecchia col credito, il tetto coi soldi, nessuna domanda', () => {
        // Difende dalla correzione sbagliata del reperto (prima i soldi dalla più vecchia, poi il credito sul residuo):
        // qui i soldi andrebbero sull'ordinaria e il credito resterebbe fermo, o passerebbe di gestione.
        const debiti = [{ chiave: 1, residuo: 10000, gestione: ORD }, { chiave: 2, residuo: 10000, gestione: TETTO }];
        const crediti = [{ chiave: 9, disponibile: 10000, gestione: ORD }];

        const esito = pianificaCoperture(debiti, crediti, 10000, false, null);

        expect(esito.creditoUsato).toEqual({ 9: 10000 });
        expect(esito.copertura).toEqual({ 1: 10000, 2: 10000 });
        expect(esito.serveScelta).toBe(false);
    });

    test('due gestioni, rata del tetto più vecchia: il credito dell\'ordinaria resta sull\'ordinaria senza la scelta', () => {
        // Tetto € 300 a gennaio, ordinaria € 100 a marzo, credito ordinario € 100, versati € 250. Le rate dalla più
        // vecchia metterebbero tutto il budget sul tetto, che il credito non può pagare: il modulo non deve chiedere
        // al server una copertura che il denaro non regge.
        const debiti = [{ chiave: 1, residuo: 30000, gestione: TETTO }, { chiave: 2, residuo: 10000, gestione: ORD }];
        const crediti = [{ chiave: 9, disponibile: 10000, gestione: ORD }];

        const esito = pianificaCoperture(debiti, crediti, 25000, true, false);
        const server = rigiocaSulServer(debiti, crediti, 25000, true, false, esito);

        const coperto = Object.values(esito.copertura).reduce((a, b) => a + b, 0);
        const usato = Object.values(esito.creditoUsato).reduce((a, b) => a + b, 0);
        // Il credito chiude l'ordinaria, i soldi vanno sul tetto: niente resta fermo (T1).
        expect(esito.copertura).toEqual({ 1: 25000, 2: 10000 });
        expect(esito.creditoUsato).toEqual({ 9: 10000 });
        expect(coperto - usato + esito.eccedenza).toBe(25000);
        expect(server.usato).toBe(usato);
        expect(Object.values(server.contante).reduce((a, b) => a + b, 0)).toBe(25000 - esito.eccedenza);
    });

    test('due gestioni: senza la scelta il credito resta nella sua, e la scelta serve', () => {
        const debiti = [{ chiave: 1, residuo: 5000, gestione: ORD }, { chiave: 2, residuo: 30000, gestione: TETTO }];
        const crediti = [{ chiave: 9, disponibile: 10000, gestione: ORD }];

        const senza = pianificaCoperture(debiti, crediti, 30000, true, null);
        const no = pianificaCoperture(debiti, crediti, 30000, true, false);
        const si = pianificaCoperture(debiti, crediti, 30000, true, true);

        expect(senza.serveScelta).toBe(true);
        expect(no.creditoUsato).toEqual({ 9: 5000 });
        expect(no.eccedenza).toBe(0);
        expect(si.creditoUsato).toEqual({ 9: 10000 });
        expect(si.eccedenza).toBe(5000);
        expect(rigiocaSulServer(debiti, crediti, 30000, true, true, si).usato).toBe(10000);
    });

    test('lo scoperto si rigioca uguale sul server, prima i soldi, anche con più gestioni', () => {
        const debiti = [{ chiave: 1, residuo: 30000, gestione: ORD }, { chiave: 2, residuo: 5000, gestione: TETTO }, { chiave: 3, residuo: 7000, gestione: ORD }];
        const crediti = [{ chiave: 9, disponibile: 4000, gestione: ORD }, { chiave: 8, disponibile: 3000, gestione: TETTO }];

        const esito = pianificaCoperture(debiti, crediti, 33000, false, null);
        const server = rigiocaSulServer(debiti, crediti, 33000, false, null, esito);

        const usato = Object.values(esito.creditoUsato).reduce((a, b) => a + b, 0);
        expect(esito.copertura).toEqual({ 1: 30000, 2: 5000, 3: 5000 });
        expect(esito.serveScelta).toBe(false);
        expect(server.usato).toBe(usato);
        expect(server.serve_scelta).toBe(false);
        for (const [k, coperto] of Object.entries(esito.copertura)) {
            const dalCredito = Object.values(server.credito).reduce((s, perRiga) => s + (perRiga[k] ?? 0), 0);
            expect(dalCredito + (server.contante[k] ?? 0)).toBe(coperto);
        }
    });

    test('centesimi interi: niente residui in virgola mobile', () => {
        const esito = pianificaCoperture([{ chiave: 1, residuo: 10014, gestione: ORD }], [{ chiave: 9, disponibile: 3210, gestione: ORD }], 6804, true, null);

        expect(esito.copertura).toEqual({ 1: 10014 });
        expect(esito.creditoUsato).toEqual({ 9: 3210 });
        expect(esito.eccedenza).toBe(0);
    });
});

describe('pianificaCoperture: a tappeto', () => {
    test('«solo sulla sua gestione» e «prima i soldi»: il credito usato è sempre min(Σ min(credito, debiti) per gestione, scoperto)', () => {
        // Un generatore deterministico (niente casualità nei test): tutte le combinazioni di tre debiti su due gestioni,
        // due crediti, e il contante, su una griglia piccola.
        const valori = [0, 3000, 7000, 10000];
        let casi = 0;
        for (const d1 of valori) for (const d2 of valori) for (const d3 of valori) {
            for (const k1 of valori) for (const k2 of [0, 4000]) for (const c of [0, 5000, 12000, 30000]) {
                const debiti = [
                    { chiave: 1, residuo: d1, gestione: 2 },
                    { chiave: 2, residuo: d2, gestione: 1 },
                    { chiave: 3, residuo: d3, gestione: 2 },
                ].filter(d => d.residuo > 0);
                const crediti = [{ chiave: 9, disponibile: k1, gestione: 1 }, { chiave: 8, disponibile: k2, gestione: 2 }].filter(k => k.disponibile > 0);
                const perG = (g: number) => debiti.filter(d => d.gestione === g).reduce((s, d) => s + d.residuo, 0);
                const totale = debiti.reduce((s, d) => s + d.residuo, 0);
                const atteso = Math.min(Math.min(k1, perG(1)) + Math.min(k2, perG(2)), Math.max(0, totale - c));

                const esito = pianificaCoperture(debiti, crediti, c, false, false);
                const usato = Object.values(esito.creditoUsato).reduce((a, b) => a + b, 0);
                expect(usato).toBe(atteso);
                casi++;
            }
        }
        expect(casi).toBeGreaterThan(500);
    });
});
