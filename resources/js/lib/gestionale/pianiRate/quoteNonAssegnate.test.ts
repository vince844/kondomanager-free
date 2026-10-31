/**
 * Cosa resta scoperto: il disegno del riquadro e del badge (il colore, la larghezza a 375 px, il tema scuro) si guarda a video; il collegamento
 * con la pagina (il badge, la riga sotto la voce, «Mostra tutte») lo prova `PianiRateShowQuoteNonAssegnate.test.ts`, che monta la pagina vera; le
 * chiavi per voce che il server manda le prova `Beta59PianoQuoteNonAssegnateTest`.
 *
 * Coda 433 (decisioni 162 e 163): la quota aperta con tante unità.
 *
 * - Il badge conta le unità e non le quote: un'unità su tre voci dice «(Cantina 1)», sei unità «(6 unità)»; le quote senza unità (voce senza
 *   tabella, tabella senza millesimi, tabelle sotto il 100 %) contano come voci intere. La cifra è con il segno, uguale allo scarto: una spesa da
 *   € 75,00 e un'entrata da € 30,00 dicono € 45,00, non € 105,00. Il millesimo non compilato non ha cifra e non entra.
 * - La riga sotto la voce nomina le prime due unità e «altre N», con la causa quando è la stessa per tutte; trova le quote dei sottoconti con le
 *   chiavi che il server manda per la voce.
 * - Il riquadro raggruppa per causa («Nessun proprietario — 6 unità»): la causa si confronta sul motivo, non sul testo; una riga per unità e voce,
 *   con la cifra sommata e con il segno; i giorni diversi sulla riga dell'unità; il millesimo non compilato in un gruppo a sé, senza cifra.
 * - I nomi in ordine di lettura: «Cantina 2» prima di «Cantina 10» (`Intl.Collator('it', { numeric: true })`).
 * - Due voci sono due voci anche se hanno lo stesso nome (si distinguono per `conto_id`); il pulsante «Mostra tutte» conta le righe e, quando le
 *   righe non sono le unità o le voci che l'intestazione conta, lo dice; «altre N» dice che cosa sono le altre (unità o voci intere); la frase dei
 *   titolari fuori competenza è al plurale per più voci; «unità» è sempre un'unità distinta, anche nella frase del millesimo non compilato.
 *
 * Le cifre vengono dalla regola: la quota di un'unità è quella che il motore registra (qui scritta nel dato), la cifra di una riga è la somma
 * delle sue quote con il segno della voce, il badge la somma di tutte.
 */

import { describe, expect, it } from 'vitest';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import {
    causaDelGruppo,
    chiaveDellaCausa,
    etichettaBadge,
    fraseUnitaSenzaMillesimo,
    gruppiPerCausa,
    RIGHE_VISIBILI,
    rigaDellaVoce,
    totaleConSegno,
    type QuotaNonAssegnata,
} from '@/lib/gestionale/pianiRate/quoteNonAssegnate';

const { euro } = useCurrencyFormatter({ spacing: 'normal' });

let n = 0;
const quota = (o: Partial<QuotaNonAssegnata> = {}): QuotaNonAssegnata => ({
    chiave: `k${++n}`, motivo: null, immobile_id: null, immobile_nome: null, conto_id: 1, conto_nome: 'Riscaldamento', tabella_nome: 'Riscaldamento',
    ruolo_richiesto: 'proprietario', importo_cents: 7500, giorni: null, riga_descrizione: null, segno: 1, ...o,
});
/** La Cantina i senza proprietario sulla voce del riscaldamento, € 75,00. */
const cantina = (i: number, o: Partial<QuotaNonAssegnata> = {}) => quota({ immobile_id: 100 + i, immobile_nome: `Cantina ${i}`, ...o });
const sei = () => [1, 2, 3, 4, 5, 6].map((i) => cantina(i));
const ASCENSORE = (o: Partial<QuotaNonAssegnata> = {}) => quota({
    motivo: 'conto_senza_tabella', conto_id: 4, conto_nome: 'Ascensore', tabella_nome: null, ruolo_richiesto: null, importo_cents: 60000, ...o,
});
/** La Cantina 1 su tre voci: riscaldamento € 75,00, pulizie € 30,00, ascensore € 18,00. */
const treVoci = () => [
    cantina(1),
    cantina(1, { conto_id: 2, conto_nome: 'Pulizie', tabella_nome: 'Generale', importo_cents: 3000 }),
    cantina(1, { conto_id: 3, conto_nome: 'Ascensore', tabella_nome: 'Scala A', importo_cents: 1800 }),
];
const testoDelleRighe = (g: { righe: { nome: string; resto: string }[] }) => g.righe.map((r) => `${r.nome}${r.resto}`);

describe('il badge «Quota aperta»: le unità, non le quote, e la cifra con il segno (Coda 433)', () => {
    it('sei unità su una voce, € 75,00 ciascuna: «(6 unità)» e € 450,00', () => {
        expect(etichettaBadge(sei(), euro)).toBe('Quota aperta · € 450,00 (6 unità)');
    });

    it('un\'unità su tre voci: il suo nome, non «3 quote»; la cifra è la somma, € 75,00 + € 30,00 + € 18,00 = € 123,00', () => {
        expect(etichettaBadge(treVoci(), euro)).toBe('Quota aperta · € 123,00 (Cantina 1)');
    });

    it('una spesa da € 75,00 e un\'entrata da € 30,00 dicono € 45,00, uguale allo scarto, non € 105,00 (decisione 163, D1)', () => {
        const quote = [cantina(1), cantina(1, { conto_id: 2, conto_nome: 'Antenna', importo_cents: 3000, segno: -1 })];

        expect(totaleConSegno(quote)).toBe(4500);
        expect(etichettaBadge(quote, euro)).toBe('Quota aperta · € 45,00 (Cantina 1)');
    });

    it('le sole entrate: la cifra è negativa, come lo scarto', () => {
        expect(etichettaBadge([cantina(1, { conto_id: 2, conto_nome: 'Antenna', importo_cents: 3000, segno: -1 })], euro)).toBe('Quota aperta · € -30,00 (Cantina 1)');
    });

    it('una voce senza tabella, che non ha un\'unità: «(la voce Ascensore)», non «(0 unità)»', () => {
        expect(etichettaBadge([ASCENSORE()], euro)).toBe('Quota aperta · € 600,00 (la voce Ascensore)');
    });

    it('sei unità e una voce senza tabella: «(6 unità e 1 voce intera)», € 450,00 + € 600,00 = € 1.050,00', () => {
        expect(etichettaBadge([...sei(), ASCENSORE()], euro)).toBe('Quota aperta · € 1.050,00 (6 unità e 1 voce intera)');
    });

    it('un\'unità e una voce intera: l\'unità per nome; due voci intere: quante sono', () => {
        expect(etichettaBadge([cantina(1), ASCENSORE()], euro)).toBe('Quota aperta · € 675,00 (Cantina 1 e 1 voce intera)');
        const giardino = quota({ motivo: 'tabella_senza_millesimi', conto_id: 5, conto_nome: 'Giardino', tabella_nome: 'Giardino', ruolo_richiesto: null, importo_cents: 30000 });
        expect(etichettaBadge([ASCENSORE(), giardino], euro)).toBe('Quota aperta · € 900,00 (2 voci intere)');
    });

    it('due tabelle senza millesimi sulla stessa voce sono una voce intera', () => {
        const fetta = (tabella: string, cents: number) => quota({ motivo: 'tabella_senza_millesimi', conto_id: 5, conto_nome: 'Giardino', tabella_nome: tabella, ruolo_richiesto: null, importo_cents: cents });

        expect(etichettaBadge([fetta('Giardino', 20000), fetta('Verde', 10000)], euro)).toBe('Quota aperta · € 300,00 (la voce Giardino)');
    });

    it('due voci intere con lo stesso nome (due sottoconti «Pulizie» di capitoli diversi, senza tabella) sono due voci intere: € 600,00 + € 600,00 = € 1.200,00', () => {
        const pulizie = (conto: number) => ASCENSORE({ conto_id: conto, conto_nome: 'Pulizie' });

        expect(etichettaBadge([pulizie(20), pulizie(21)], euro)).toBe('Quota aperta · € 1.200,00 (2 voci intere)');
    });

    it('la stessa unità con due quote sulla stessa voce (due tabelle) è un\'unità: € 75,00 + € 25,00 = € 100,00', () => {
        expect(etichettaBadge([cantina(1), cantina(1, { tabella_nome: 'Generale', importo_cents: 2500 })], euro)).toBe('Quota aperta · € 100,00 (Cantina 1)');
    });

    it('il millesimo non compilato non ha cifra e non entra nel badge, né nella cifra né nelle unità', () => {
        const senzaMillesimo = [2, 3, 4].map((i) => cantina(i, { motivo: 'millesimo_non_compilato', ruolo_richiesto: null, importo_cents: 0 }));

        expect(etichettaBadge([cantina(1), ...senzaMillesimo], euro)).toBe('Quota aperta · € 75,00 (Cantina 1)');
    });
});

describe('la riga sotto la voce: le prime due unità e «altre N», la causa quando è la stessa (Coda 433)', () => {
    const RISCALDAMENTO = (quote: QuotaNonAssegnata[], cents?: number) => ({
        non_assegnati_cents: cents ?? quote.reduce((s, q) => s + q.importo_cents, 0), non_assegnati_chiavi: quote.map((q) => q.chiave),
    });

    it('sei unità con la stessa causa: «Cantina 1, Cantina 2 e altre 4: nessun proprietario»', () => {
        const quote = sei();

        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 450,00 (Cantina 1, Cantina 2 e altre 4: nessun proprietario): restano fuori dalle rate.');
    });

    it('un\'unità sola: il nome e la causa', () => {
        const quote = [cantina(1)];

        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 75,00 (Cantina 1: nessun proprietario): restano fuori dalle rate.');
    });

    it('tre unità: tutte per nome («altre 1» non dice niente in più)', () => {
        const quote = [1, 2, 3].map((i) => cantina(i));

        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 225,00 (Cantina 1, Cantina 2 e Cantina 3: nessun proprietario): restano fuori dalle rate.');
    });

    it('due cause sulla stessa voce: le unità senza la causa, che sta nel riquadro', () => {
        const quote = [cantina(1), cantina(2, { ruolo_richiesto: 'inquilino', importo_cents: 3000 })];

        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 105,00 (Cantina 1 e Cantina 2): restano fuori dalle rate.');
    });

    it('lo stesso motivo con giorni diversi è la stessa causa: la riga la dice senza i giorni', () => {
        const quote = [cantina(1, { motivo: 'giorni_senza_titolare', giorni: 184 }), cantina(2, { motivo: 'giorni_senza_titolare', giorni: 90, importo_cents: 3000 })];

        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 105,00 (Cantina 1 e Cantina 2: per una parte del periodo di competenza nessun titolare è in vigore): restano fuori dalle rate.');
    });

    it('la quota di un sottoconto: la voce del piano la trova con le sue chiavi, e nomina l\'unità; una quota fuori dalle chiavi non c\'entra', () => {
        const caldaia = cantina(1, { conto_id: 7, conto_nome: 'Caldaia', importo_cents: 3000 });
        const pulizie = cantina(2, { conto_id: 2, conto_nome: 'Pulizie' });
        const manutenzione = { non_assegnati_cents: 3000, non_assegnati_chiavi: [caldaia.chiave] };

        expect(rigaDellaVoce([caldaia, pulizie], manutenzione, euro)).toBe('Non assegnati € 30,00 (Cantina 1: nessun proprietario): restano fuori dalle rate.');
    });

    it('una voce di entrata: i non assegnati sono un credito che nessuno riceve', () => {
        const antenna = [cantina(1, { conto_id: 2, conto_nome: 'Antenna', importo_cents: 3000, segno: -1 })];

        expect(rigaDellaVoce(antenna, RISCALDAMENTO(antenna), euro)).toBe('Non assegnati € 30,00 (Cantina 1: nessun proprietario): restano fuori dalle rate, e nessuno li riceve come credito.');
    });

    it('un capitolo con un sottoconto di spesa e uno di entrata: la riga dice che cosa resta di ognuno', () => {
        const caldaia = cantina(1, { conto_id: 7, conto_nome: 'Caldaia', importo_cents: 3000 });
        const rimborso = cantina(2, { conto_id: 8, conto_nome: 'Rimborso', importo_cents: 1000, segno: -1 });
        const misto = { non_assegnati_cents: 4000, non_assegnati_chiavi: [caldaia.chiave, rimborso.chiave] };

        expect(rigaDellaVoce([caldaia, rimborso], misto, euro)).toBe('Non assegnati € 40,00 (Cantina 1 e Cantina 2: nessun proprietario): restano fuori dalle rate, e quelli delle entrate nessuno li riceve come credito.');
    });

    // Le «altre» dicono che cosa sono: le unità che restano, le voci intere che restano, o le due cose.
    const VOCE_INTERA = (conto: number, nome: string, cents: number) => quota({
        motivo: 'conto_senza_tabella', conto_id: conto, conto_nome: nome, tabella_nome: null, ruolo_richiesto: null, importo_cents: cents,
    });

    it('tre unità e una voce intera sotto lo stesso capitolo: le «altre» sono un\'unità e una voce intera, non «altre 2»', () => {
        const quote = [1, 2, 3].map((i) => cantina(i, { conto_id: 7, conto_nome: 'Caldaia' })).concat([VOCE_INTERA(8, 'Bruciatore', 1000)]);

        // 3 × € 75,00 + € 10,00 = € 235,00; le cause sono due (nessun proprietario, nessuna tabella): la riga non ne dice nessuna
        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 235,00 (Cantina 1, Cantina 2, un\'altra unità e una voce intera): restano fuori dalle rate.');
    });

    it('quattro unità e una voce intera: «altre 2 unità e una voce intera»', () => {
        const quote = [1, 2, 3, 4].map((i) => cantina(i)).concat([VOCE_INTERA(8, 'Bruciatore', 1000)]);

        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 310,00 (Cantina 1, Cantina 2, altre 2 unità e una voce intera): restano fuori dalle rate.');
    });

    it('tre unità e due voci intere: «un\'altra unità e 2 voci intere»', () => {
        const quote = [1, 2, 3].map((i) => cantina(i)).concat([VOCE_INTERA(8, 'Bruciatore', 1000), VOCE_INTERA(9, 'Citofono', 2000)]);

        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 255,00 (Cantina 1, Cantina 2, un\'altra unità e 2 voci intere): restano fuori dalle rate.');
    });

    it('due unità e due voci intere: le altre sono le due voci', () => {
        const quote = [1, 2].map((i) => cantina(i)).concat([ASCENSORE(), ASCENSORE({ conto_id: 8, conto_nome: 'Citofono', importo_cents: 12000 })]);

        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 870,00 (Cantina 1, Cantina 2 e altre 2 voci intere): restano fuori dalle rate.');
    });

    it('un\'unità e tre voci intere: i due nomi sono l\'unità e la prima voce, le altre sono due voci intere', () => {
        const quote = [cantina(1), ASCENSORE(), ASCENSORE({ conto_id: 8, conto_nome: 'Citofono', importo_cents: 12000 }), ASCENSORE({ conto_id: 9, conto_nome: 'Balconi', importo_cents: 5000 })];

        // € 75,00 + € 600,00 + € 120,00 + € 50,00; le voci intere in ordine di nome: Ascensore, Balconi, Citofono
        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 845,00 (Cantina 1, Ascensore e altre 2 voci intere): restano fuori dalle rate.');
    });

    it('quattro voci intere con la stessa causa: «altre 2 voci intere», e la causa', () => {
        const quote = [ASCENSORE(), ASCENSORE({ conto_id: 8, conto_nome: 'Citofono', importo_cents: 12000 }), ASCENSORE({ conto_id: 9, conto_nome: 'Balconi', importo_cents: 5000 }), ASCENSORE({ conto_id: 10, conto_nome: 'Tetto', importo_cents: 8000 })];

        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 850,00 (Ascensore, Balconi e altre 2 voci intere: le voci non hanno una tabella millesimale collegata): restano fuori dalle rate.');
    });

    // Con più di tre posti e al più due unità, le voci intere sono almeno due oltre ai due nomi detti: «altre N voci intere» con N di almeno 2.
    it('più di tre posti e al più due unità: le altre sono sempre almeno due voci intere, per ogni numero di unità e di voci', () => {
        for (let unita = 0; unita <= 2; unita++) {
            for (let voci = 4 - unita; voci <= 9; voci++) {
                const quote = [
                    ...Array.from({ length: unita }, (_, i) => cantina(i + 1)),
                    ...Array.from({ length: voci }, (_, i) => VOCE_INTERA(30 + i, `Voce ${i + 1}`, 1000)),
                ];
                // I due nomi sono le unità e poi le prime voci; le altre sono le voci che non hanno un nome nella riga.
                const nomi = [...Array.from({ length: unita }, (_, i) => `Cantina ${i + 1}`), ...Array.from({ length: voci }, (_, i) => `Voce ${i + 1}`)];
                const altre = voci - (2 - unita);
                const riga = rigaDellaVoce(quote, RISCALDAMENTO(quote), euro);

                expect(altre, `${unita} unità e ${voci} voci`).toBeGreaterThanOrEqual(2);
                expect(riga, `${unita} unità e ${voci} voci`).toContain(`(${nomi[0]}, ${nomi[1]} e altre ${altre} voci intere`);
                expect(riga).not.toContain('un\'altra voce intera');
            }
        }
    });

    it('i titolari fuori dai giorni di competenza su più voci sotto lo stesso capitolo: «delle voci»', () => {
        const quote = [cantina(1, { motivo: 'titolari_fuori_competenza', conto_id: 7, conto_nome: 'Caldaia' }), cantina(2, { motivo: 'titolari_fuori_competenza', conto_id: 8, conto_nome: 'Bruciatore', importo_cents: 3000 })];

        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 105,00 (Cantina 1 e Cantina 2: nessun titolare del ruolo richiesto è in vigore nei giorni di competenza delle voci): restano fuori dalle rate.');
    });

    it('«Cantina 2» viene prima di «Cantina 10», qualunque sia l\'ordine del motore', () => {
        const quote = [10, 2, 11, 1].map((i) => cantina(i));

        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 300,00 (Cantina 1, Cantina 2 e altre 2: nessun proprietario): restano fuori dalle rate.');
    });

    it('una voce senza tabella: la riga nomina la voce e dice perché', () => {
        const quote = [ASCENSORE()];

        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 600,00 (Ascensore: la voce non ha una tabella millesimale collegata): restano fuori dalle rate.');
    });

    it('il millesimo non compilato della stessa voce non è fra i non assegnati, e non cambia la causa', () => {
        const quote = [cantina(1), cantina(2, { motivo: 'millesimo_non_compilato', ruolo_richiesto: null, importo_cents: 0 })];

        expect(rigaDellaVoce(quote, RISCALDAMENTO(quote), euro)).toBe('Non assegnati € 75,00 (Cantina 1: nessun proprietario): restano fuori dalle rate.');
    });
});

describe('il riquadro: i gruppi per causa (Coda 433)', () => {
    it('sei unità su una voce: un gruppo, «Nessun proprietario — 6 unità», la voce nell\'intestazione e una riga per unità', () => {
        const [g, ...altri] = gruppiPerCausa(sei(), euro);

        expect(altri).toEqual([]);
        expect(g.intestazione).toBe('Nessun proprietario — 6 unità sulla voce Riscaldamento');
        expect(testoDelleRighe(g)).toEqual(['Cantina 1: € 75,00', 'Cantina 2: € 75,00', 'Cantina 3: € 75,00', 'Cantina 4: € 75,00', 'Cantina 5: € 75,00', 'Cantina 6: € 75,00']);
        expect(RIGHE_VISIBILI).toBe(5);
    });

    it('un\'unità su tre voci: «1 unità», e la voce su ogni riga, in ordine di nome', () => {
        const [g] = gruppiPerCausa(treVoci(), euro);

        expect(g.intestazione).toBe('Nessun proprietario — 1 unità');
        expect(testoDelleRighe(g)).toEqual(['Cantina 1 · Ascensore: € 18,00', 'Cantina 1 · Pulizie: € 30,00', 'Cantina 1 · Riscaldamento: € 75,00']);
    });

    it('la stessa unità con due quote sulla stessa voce (due tabelle) è una riga, con la cifra sommata: € 75,00 + € 25,00 = € 100,00', () => {
        const gruppi = gruppiPerCausa([cantina(1), cantina(1, { tabella_nome: 'Generale', importo_cents: 2500 })], euro);

        expect(gruppi.map((g) => g.intestazione)).toEqual(['Nessun proprietario — 1 unità sulla voce Riscaldamento']);
        expect(testoDelleRighe(gruppi[0])).toEqual(['Cantina 1: € 100,00']);
    });

    it('cause diverse: un gruppo per causa, nell\'ordine del motore; la voce senza tabella conta come voce', () => {
        const gruppi = gruppiPerCausa([cantina(1), ASCENSORE(), cantina(2, { importo_cents: 3000 })], euro);

        expect(gruppi.map((g) => g.intestazione)).toEqual([
            'Nessun proprietario — 2 unità sulla voce Riscaldamento',
            'La voce non ha una tabella millesimale collegata — 1 voce',
        ]);
        expect(gruppi.map(testoDelleRighe)).toEqual([['Cantina 1: € 75,00', 'Cantina 2: € 30,00'], ['Ascensore: € 600,00']]);
    });

    it('lo stesso motivo con giorni diversi è un gruppo: i giorni stanno sulla riga dell\'unità', () => {
        const gruppi = gruppiPerCausa([cantina(1, { motivo: 'giorni_senza_titolare', giorni: 184 }), cantina(2, { motivo: 'giorni_senza_titolare', giorni: 90, importo_cents: 3000 })], euro);

        expect(gruppi.map((g) => g.intestazione)).toEqual(['Per una parte del periodo di competenza nessun titolare è in vigore — 2 unità sulla voce Riscaldamento']);
        expect(testoDelleRighe(gruppi[0])).toEqual(['Cantina 1: € 75,00 (184 giorni)', 'Cantina 2: € 30,00 (90 giorni)']);
    });

    it('controprova — gli stessi giorni per tutte: li dice l\'intestazione, e le righe no', () => {
        const gruppi = gruppiPerCausa([1, 2].map((i) => cantina(i, { motivo: 'giorni_senza_titolare', giorni: 184 })), euro);

        expect(gruppi.map((g) => g.intestazione)).toEqual(['Per 184 giorni del periodo di competenza nessun titolare è in vigore — 2 unità sulla voce Riscaldamento']);
        expect(testoDelleRighe(gruppi[0])).toEqual(['Cantina 1: € 75,00', 'Cantina 2: € 75,00']);
    });

    it('la causa è il motivo, non il testo: due ruoli con i giorni senza titolare fanno un gruppo; «nessun proprietario» e «nessun inquilino» due', () => {
        const giorni = gruppiPerCausa([cantina(1, { motivo: 'giorni_senza_titolare', giorni: 184 }), cantina(2, { motivo: 'giorni_senza_titolare', giorni: 184, ruolo_richiesto: 'inquilino' })], euro);
        expect(giorni).toHaveLength(1);

        const ruoli = gruppiPerCausa([cantina(1), cantina(2, { ruolo_richiesto: 'inquilino' }), cantina(3)], euro);
        expect(ruoli.map((g) => g.intestazione)).toEqual(['Nessun proprietario — 2 unità sulla voce Riscaldamento', 'Nessun inquilino — 1 unità sulla voce Riscaldamento']);
    });

    it('«nessun proprietario» su tre voci con tre tabelle diverse è una causa sola', () => {
        expect(gruppiPerCausa(treVoci(), euro)).toHaveLength(1);
        expect(chiaveDellaCausa(treVoci()[0])).toBe(chiaveDellaCausa(treVoci()[1]));
    });

    it('la tabella è nella causa quando la frase la nomina: due tabelle senza millesimi sono due gruppi', () => {
        const fetta = (tabella: string, conto: number, nome: string) => quota({ motivo: 'tabella_senza_millesimi', conto_id: conto, conto_nome: nome, tabella_nome: tabella, ruolo_richiesto: null, importo_cents: 30000 });
        const gruppi = gruppiPerCausa([fetta('Giardino', 5, 'Giardino'), fetta('Verde', 6, 'Potature')], euro);

        expect(gruppi.map((g) => g.intestazione)).toEqual([
            'La tabella «Giardino» non ha millesimi utilizzabili — 1 voce',
            'La tabella «Verde» non ha millesimi utilizzabili — 1 voce',
        ]);
        expect(gruppi.map(testoDelleRighe)).toEqual([['Giardino: € 300,00'], ['Potature: € 300,00']]);
    });

    it('due voci senza tabella: la frase al plurale', () => {
        const gruppi = gruppiPerCausa([ASCENSORE(), ASCENSORE({ conto_id: 8, conto_nome: 'Citofono', importo_cents: 12000 })], euro);

        expect(gruppi.map((g) => g.intestazione)).toEqual(['Le voci non hanno una tabella millesimale collegata — 2 voci']);
        expect(testoDelleRighe(gruppi[0])).toEqual(['Ascensore: € 600,00', 'Citofono: € 120,00']);
    });

    it('una voce di entrata: la riga ha la cifra con il segno, come il badge', () => {
        const [g] = gruppiPerCausa([cantina(1), cantina(1, { conto_id: 2, conto_nome: 'Antenna', importo_cents: 3000, segno: -1 })], euro);

        expect(testoDelleRighe(g)).toEqual(['Cantina 1 · Antenna: € -30,00', 'Cantina 1 · Riscaldamento: € 75,00']);
    });

    it('il millesimo non compilato: un gruppo a sé, dopo le quote che restano fuori dalle rate, senza cifra', () => {
        const senzaMillesimo = [3, 2].map((i) => cantina(i, { motivo: 'millesimo_non_compilato', ruolo_richiesto: null, importo_cents: 0 }));
        const gruppi = gruppiPerCausa([...senzaMillesimo, cantina(1)], euro);

        expect(gruppi.map((g) => g.intestazione)).toEqual([
            'Nessun proprietario — 1 unità sulla voce Riscaldamento',
            'Non hanno un millesimo nella tabella «Riscaldamento»: la loro parte è stata ripartita fra le altre unità — 2 unità sulla voce Riscaldamento',
        ]);
        expect(testoDelleRighe(gruppi[1])).toEqual(['Cantina 2', 'Cantina 3']);
    });

    it('«Cantina 2» viene prima di «Cantina 10» anche nelle righe', () => {
        const [g] = gruppiPerCausa([10, 2, 1].map((i) => cantina(i)), euro);

        expect(g.righe.map((r) => r.nome)).toEqual(['Cantina 1', 'Cantina 2', 'Cantina 10']);
    });

    it('le spese a carico di più unità: la frase al plurale; una sola, al singolare', () => {
        const personale = (i: number) => cantina(i, { motivo: 'ad_personam_senza_titolare', ruolo_richiesto: null, tabella_nome: null, conto_id: null, conto_nome: null, importo_cents: 5000 });

        expect(causaDelGruppo([personale(1)])).toBe('una spesa a suo carico, senza proprietario, nudo proprietario o usufruttuario attivo');
        expect(gruppiPerCausa([personale(1), personale(2)], euro).map((g) => g.intestazione)).toEqual(['Spese a loro carico, senza proprietario, nudo proprietario o usufruttuario attivo — 2 unità']);
    });

    // Due voci si distinguono per `conto_id`, non per il nome: due sottoconti «Pulizie» di capitoli diversi sono due voci.
    it('due voci con lo stesso nome (due sottoconti «Pulizie» di capitoli diversi): la voce sta su ogni riga, non nell\'intestazione', () => {
        const pulizie = (conto: number, i: number, cents: number) => cantina(i, { conto_id: conto, conto_nome: 'Pulizie', tabella_nome: 'Generale', importo_cents: cents });
        const [g, ...altri] = gruppiPerCausa([pulizie(20, 1, 3000), pulizie(21, 2, 2000)], euro);

        expect(altri).toEqual([]);
        expect(g.intestazione).toBe('Nessun proprietario — 2 unità');
        expect(testoDelleRighe(g)).toEqual(['Cantina 1 · Pulizie: € 30,00', 'Cantina 2 · Pulizie: € 20,00']);
    });

    it('due voci intere con lo stesso nome (senza tabella): «2 voci» e due righe, una per voce, ognuna con la sua cifra', () => {
        const pulizie = (conto: number, cents: number) => ASCENSORE({ conto_id: conto, conto_nome: 'Pulizie', importo_cents: cents });
        const [g, ...altri] = gruppiPerCausa([pulizie(20, 60000), pulizie(21, 12000)], euro);

        expect(altri).toEqual([]);
        expect(g.intestazione).toBe('Le voci non hanno una tabella millesimale collegata — 2 voci');
        expect(testoDelleRighe(g)).toEqual(['Pulizie: € 600,00', 'Pulizie: € 120,00']);
        expect(g.mostraTutte).toBe('Mostra tutte (2)');
    });

    it('i titolari fuori dai giorni di competenza su due voci con lo stesso nome: «delle voci», non «della voce»', () => {
        const fuori = (i: number, conto: number) => cantina(i, { motivo: 'titolari_fuori_competenza', conto_id: conto, conto_nome: 'Pulizie' });
        const quote = [fuori(1, 20), fuori(2, 21)];

        expect(gruppiPerCausa(quote, euro).map((g) => g.intestazione)).toEqual(['Nessun titolare del ruolo richiesto è in vigore nei giorni di competenza delle voci — 2 unità']);
        expect(causaDelGruppo(quote)).toBe('nessun titolare del ruolo richiesto è in vigore nei giorni di competenza delle voci');
    });

    it('la stessa unità su due voci con lo stesso nome: due righe, una per voce, ognuna con la sua cifra', () => {
        const pulizie = (conto: number, cents: number) => cantina(1, { conto_id: conto, conto_nome: 'Pulizie', tabella_nome: 'Generale', importo_cents: cents });
        const [g] = gruppiPerCausa([pulizie(20, 3000), pulizie(21, 2000)], euro);

        expect(g.intestazione).toBe('Nessun proprietario — 1 unità');
        expect(testoDelleRighe(g)).toEqual(['Cantina 1 · Pulizie: € 30,00', 'Cantina 1 · Pulizie: € 20,00']);
    });

    it('controprova — quote che non portano l\'id della voce: la voce è il suo nome, due nomi sono due voci e lo stesso nome una voce', () => {
        const senzaId = (i: number, nome: string, cents: number) => cantina(i, { conto_id: null, conto_nome: nome, importo_cents: cents });
        const [due] = gruppiPerCausa([senzaId(1, 'Pulizie', 3000), senzaId(2, 'Giardino', 2000)], euro);
        const [una] = gruppiPerCausa([senzaId(1, 'Pulizie', 3000), senzaId(2, 'Pulizie', 2000)], euro);

        expect(due.intestazione).toBe('Nessun proprietario — 2 unità');
        expect(testoDelleRighe(due)).toEqual(['Cantina 1 · Pulizie: € 30,00', 'Cantina 2 · Giardino: € 20,00']);
        expect(una.intestazione).toBe('Nessun proprietario — 2 unità sulla voce Pulizie');
    });

    // «Mostra tutte» conta le righe del gruppo; l'intestazione conta le unità (o le voci): le due cifre non si contraddicono.
    it('sei unità su tre voci: l\'intestazione dice 6 unità, le righe sono 18 e il pulsante dice 18 righe', () => {
        const quote = [1, 2, 3, 4, 5, 6].flatMap((i) => [
            cantina(i), cantina(i, { conto_id: 2, conto_nome: 'Pulizie', importo_cents: 3000 }), cantina(i, { conto_id: 3, conto_nome: 'Ascensore', importo_cents: 1800 }),
        ]);
        const [g] = gruppiPerCausa(quote, euro);

        expect(g.intestazione).toBe('Nessun proprietario — 6 unità');
        expect(g.righe).toHaveLength(18);
        expect(g.mostraTutte).toBe('Mostra tutte (18 righe)');
    });

    it('sei unità su una voce: le righe sono le unità, il pulsante dice solo 6; sei voci intere: 6', () => {
        expect(gruppiPerCausa(sei(), euro)[0].mostraTutte).toBe('Mostra tutte (6)');

        const voci = [1, 2, 3, 4, 5, 6].map((i) => ASCENSORE({ conto_id: 30 + i, conto_nome: `Voce ${i}` }));
        const [g] = gruppiPerCausa(voci, euro);
        expect(g.intestazione).toBe('Le voci non hanno una tabella millesimale collegata — 6 voci');
        expect(g.mostraTutte).toBe('Mostra tutte (6)');
    });

    // La frase dei titolari fuori competenza parla «della voce» solo se la voce è una.
    it('i titolari fuori dai giorni di competenza su due voci: «delle voci»; su una voce sola, «della voce»', () => {
        const fuori = (i: number, o: Partial<QuotaNonAssegnata> = {}) => cantina(i, { motivo: 'titolari_fuori_competenza', ...o });
        const dueVoci = gruppiPerCausa([fuori(1), fuori(2, { conto_id: 2, conto_nome: 'Pulizie', importo_cents: 3000 })], euro);

        expect(dueVoci.map((g) => g.intestazione)).toEqual(['Nessun titolare del ruolo richiesto è in vigore nei giorni di competenza delle voci — 2 unità']);
        expect(testoDelleRighe(dueVoci[0])).toEqual(['Cantina 1 · Riscaldamento: € 75,00', 'Cantina 2 · Pulizie: € 30,00']);

        const unaVoce = gruppiPerCausa([fuori(1), fuori(2, { importo_cents: 3000 })], euro);
        expect(unaVoce.map((g) => g.intestazione)).toEqual(['Nessun titolare del ruolo richiesto è in vigore nei giorni di competenza della voce — 2 unità sulla voce Riscaldamento']);
    });

    it('i titolari fuori dai giorni di competenza: la stessa unità su due voci è «1 unità» e le voci sono due', () => {
        const fuori = (conto: number, nome: string) => cantina(1, { motivo: 'titolari_fuori_competenza', conto_id: conto, conto_nome: nome });

        expect(gruppiPerCausa([fuori(1, 'Riscaldamento'), fuori(2, 'Pulizie')], euro).map((g) => g.intestazione))
            .toEqual(['Nessun titolare del ruolo richiesto è in vigore nei giorni di competenza delle voci — 1 unità']);
    });
});

describe('la frase del millesimo non compilato conta le unità, non le quote (Coda 433)', () => {
    const senzaMillesimo = (i: number, tabella: string) => cantina(i, { motivo: 'millesimo_non_compilato', ruolo_richiesto: null, tabella_nome: tabella, importo_cents: 0 });

    it('un\'unità senza millesimo in due tabelle è un\'unità: «un\'unità non ha un millesimo: la sua parte»', () => {
        expect(fraseUnitaSenzaMillesimo([senzaMillesimo(2, 'Riscaldamento'), senzaMillesimo(2, 'Generale')])).toBe('un\'unità non ha un millesimo: la sua parte');
    });

    it('due unità, ognuna in due tabelle, sono due unità (non quattro)', () => {
        const quote = [senzaMillesimo(2, 'Riscaldamento'), senzaMillesimo(2, 'Generale'), senzaMillesimo(3, 'Riscaldamento'), senzaMillesimo(3, 'Generale')];

        expect(fraseUnitaSenzaMillesimo(quote)).toBe('2 unità non hanno un millesimo: la loro parte');
        expect(gruppiPerCausa(quote, euro).map((g) => g.intestazione)).toEqual([
            'Non hanno un millesimo nella tabella «Riscaldamento»: la loro parte è stata ripartita fra le altre unità — 2 unità sulla voce Riscaldamento',
            'Non hanno un millesimo nella tabella «Generale»: la loro parte è stata ripartita fra le altre unità — 2 unità sulla voce Riscaldamento',
        ]);
    });

    it('due unità con lo stesso nome (due scale con un «Interno 1») sono due unità: si contano per identificativo, non per nome', () => {
        const interno1 = (id: number) => quota({
            motivo: 'millesimo_non_compilato', immobile_id: id, immobile_nome: 'Interno 1', ruolo_richiesto: null, tabella_nome: 'Riscaldamento', importo_cents: 0,
        });

        expect(fraseUnitaSenzaMillesimo([interno1(201), interno1(202)])).toBe('2 unità non hanno un millesimo: la loro parte');
    });

    it('le quote che hanno una cifra non entrano nella frase', () => {
        expect(fraseUnitaSenzaMillesimo([cantina(1), senzaMillesimo(2, 'Riscaldamento')])).toBe('un\'unità non ha un millesimo: la sua parte');
    });
});
