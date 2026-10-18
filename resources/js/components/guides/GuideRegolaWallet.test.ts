// @vitest-environment jsdom

/**
 * Cosa resta scoperto: le frasi che il codice genera (il pannello, il cancello: sono vere, e le fissano altri test); i changelog; `docs/`;
 * la regola detta con parole diverse da queste nelle altre guide (la guardia per modelli è `Beta52FrasiRegolaWalletTest.php`).
 *
 * La regola del Wallet nelle guide in-app: «un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio».
 *
 * Il codice conta solo i saldi che un passaggio non ha scritto (`->whereNull('subentro_id')` in `FermataDopoScrivi::conUnSaldo` e in
 * `CreateSaldoRequest`): chi ha la riga di un conguaglio può avere accanto il suo saldo manuale. Cinque punti delle guide dicevano la regola
 * senza l'eccezione: `PassaggioProprietaGuide.vue` in tre punti (la strada a mano della fermata, il coinquilino che resta, il cancello del
 * defunto), `SaldiGuide.vue` (le righe che il programma non sposta) e `PianoRateGuide.vue` (il cancello del defunto, nel testo che va a capo).
 * Si leggono con gli spazi normalizzati, perché la frase di `PianoRateGuide.vue` va a capo nel sorgente. La condizione si dice su gestione, unità ed
 * esercizio, le tre colonne di `conUnSaldo` (una gestione può stare su più esercizi), e per il coinquilino che resta vale solo se chi esce non ha un saldo:
 * se ce l'ha, `AnteprimaPassaggio::fraseDelCoinquilino` dice «fra le parti» per tutti.
 *
 * Nello stesso punto di `PassaggioProprietaGuide.vue` la fine di un conduttore dice «i giorni che copriva tornano a chi paga al posto
 * dell'inquilino da quel giorno», non «i suoi giorni», che si legge come se da quel giorno fossero ancora suoi.
 *
 * Le guide sono solo in italiano. La guardia che cerca la formula senza l'eccezione in tutti i testi, anche in inglese e in portoghese, è
 * `Beta52FrasiRegolaWalletTest.php`.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PassaggioProprietaGuide from './PassaggioProprietaGuide.vue';
import PianoRateGuide from './PianoRateGuide.vue';
import SaldiGuide from './SaldiGuide.vue';

const passa = { template: '<div><slot /></div>' };

function testo(componente: unknown): string {
    const w = mount(componente as never, {
        props: { open: true } as never,
        global: {
            stubs: {
                Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa,
                Tabs: passa, TabsList: passa, TabsTrigger: passa, TabsContent: passa,
            },
        },
    });

    return w.text().replace(/\s+/g, ' ');
}

describe('la regola del Wallet nelle guide', () => {
    test('PassaggioProprietaGuide — la strada a mano della fermata: conta solo un saldo che non sia stato scritto da un passaggio', () => {
        const t = testo(PassaggioProprietaGuide);

        expect(t).toContain('né chi resta a carico né chi riceve ha già, su quella gestione, quell\'unità e quell\'esercizio, un saldo che non sia stato scritto da un passaggio');
        expect(t).toContain('il Wallet accetta un solo saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio');
        expect(t).toContain('chi ha già la riga di un conguaglio può avere accanto il suo saldo manuale');
        // `FermataDopoScrivi::stradaAMano`: una riga a debito per ognuno di chi riceve, non una sola.
        expect(t).toContain('il pannello indica un saldo manuale dal Wallet, con righe di segno opposto, una per persona');
        expect(t).not.toContain('con una riga a credito e una a debito');
    });

    test('PassaggioProprietaGuide — il coinquilino che resta: lo stesso, e se il saldo ce l\'ha chi esce la parte si regola fra le parti per tutti', () => {
        // `AnteprimaPassaggio::fraseDelCoinquilino`: con chi esce fra `con_un_saldo` la frase dice «fra le parti» anche per chi non ha un saldo.
        expect(testo(PassaggioProprietaGuide)).toContain('un saldo manuale dal Wallet per chi non ha già, su quella gestione, quell\'unità e quell\'esercizio, un saldo che non sia stato scritto da un passaggio, se non ce l\'ha chi esce; altrimenti la parte si regola fra le parti');
    });

    test('PassaggioProprietaGuide — il cancello del defunto: lo stesso', () => {
        expect(testo(PassaggioProprietaGuide)).toContain('il saldo manuale dal Wallet, se nessuno ha già, su quella gestione, quell\'unità e quell\'esercizio, un saldo che non sia stato scritto da un passaggio; altrimenti la parte si regola fra le parti');
    });

    test('SaldiGuide — le righe che il programma non sposta: la riga di un conguaglio non occupa il posto, e la riga a mano si aggiunge accanto', () => {
        const t = testo(SaldiGuide);

        expect(t).toContain('ha già lì un saldo che non sia stato scritto da un passaggio');
        // Le persone delle righe possono essere più di due (il defunto e ogni erede, chi resta a carico e ognuno di chi riceve): `conUnSaldo` le guarda tutte.
        expect(t).toContain('solo se nessuna delle persone coinvolte ha già lì un saldo');
        expect(t).toContain('con righe di segno opposto da qui, sulla stessa gestione, unità ed esercizio, una per persona');
        expect(t).not.toContain('nessuna delle due persone');
        expect(t).toContain('il Wallet accetta un solo saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio');
        expect(t).toContain('la riga di un conguaglio non occupa il posto, e la riga a mano si aggiunge accanto');
    });

    test('PianoRateGuide — il cancello del defunto, nel testo che va a capo: lo stesso', () => {
        expect(testo(PianoRateGuide)).toContain('un saldo manuale dal Wallet, se nessuno ha già, su quella gestione, quell\'unità e quell\'esercizio, un saldo che non sia stato scritto da un passaggio, altrimenti la parte si regola fra le parti');
    });

    test('controprova — in tutti e cinque i punti, altrimenti, la parte si regola fra le parti', () => {
        expect(testo(PassaggioProprietaGuide).match(/altrimenti (?:dice che )?la parte si regola fra le parti/g)?.length).toBeGreaterThanOrEqual(3);
        expect(testo(SaldiGuide)).toContain('altrimenti dicono che la parte si regola fra le parti');
        expect(testo(PianoRateGuide)).toContain('altrimenti la parte si regola fra le parti');
    });

    test('la fine di un conduttore: «i giorni che copriva tornano a chi paga al posto dell\'inquilino da quel giorno», non «i suoi giorni»', () => {
        const t = testo(PassaggioProprietaGuide);

        expect(t).not.toContain('i suoi giorni tornano');
        expect(t).toContain('i giorni che copriva tornano a chi paga al posto dell\'inquilino da quel giorno');
    });

    test('controprova — la guida dice ancora che i giorni tornano a chi paga al posto dell\'inquilino da quel giorno', () => {
        expect(testo(PassaggioProprietaGuide)).toContain('tornano a chi paga al posto dell\'inquilino da quel giorno');
    });
});
