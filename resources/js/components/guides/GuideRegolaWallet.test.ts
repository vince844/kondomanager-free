// @vitest-environment jsdom

/**
 * Cosa resta scoperto: le frasi che il codice genera (il pannello, il cancello, l'annullamento: le fissano i test PHP della strada legata); i
 * changelog; `docs/`; la regola detta con parole diverse da queste nelle altre guide (la guardia per modelli è `Beta53FrasiRegolaWalletLegataTest.php`).
 *
 * La strada a mano nelle guide in-app: il Wallet, con i saldi legati al passaggio.
 *
 * Il posto del Wallet (un saldo per persona, gestione, unità ed esercizio) non conta né le righe scritte da un passaggio né quelle legate a un
 * passaggio dal modale («Legato a un passaggio»): la strada a mano di una fermata, del coinquilino che resta e del cancello del defunto è sempre il
 * Wallet, con le righe di segno opposto legate al passaggio, una per persona, anche accanto a un riporto. Le guide non dicono più «altrimenti la
 * parte si regola fra le parti» per chi ha già un saldo. Annullare il passaggio toglie i saldi legati a lui; un saldo legato già assorbito da un
 * piano ferma l'annullamento finché quel piano non è eliminato (il lucchetto si riapre); i saldi scritti a mano senza legame restano, e l'annullamento li nomina.
 *
 * Restano come prima, perché non hanno un passaggio a cui legare la riga: l'estinzione dell'usufrutto registrata a mano («se serve, si scrive con
 * un saldo manuale dal Wallet sulla stessa gestione») e la seconda vendita dopo una fermata («senza il Wallet»).
 *
 * Nello stesso punto di `PassaggioProprietaGuide.vue` la fine di un conduttore dice «i giorni che copriva tornano a chi paga al posto
 * dell'inquilino da quel giorno», non «i suoi giorni», che si legge come se da quel giorno fossero ancora suoi.
 *
 * Le guide sono solo in italiano.
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

describe('la strada a mano nelle guide: il Wallet, con i saldi legati al passaggio', () => {
    test('PassaggioProprietaGuide — la strada a mano della fermata è il Wallet, con i saldi legati al passaggio, anche accanto a un riporto', () => {
        const t = testo(PassaggioProprietaGuide);

        expect(t).toContain('La strada a mano è il Wallet: dal modale «Aggiungi credito» o «Aggiungi debito» si scelgono il passaggio, la persona e la cifra, con righe di segno opposto, una per persona. Un saldo legato a un passaggio non occupa il posto (il Wallet tiene un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio né quelli legati a un passaggio), quindi si registra anche accanto a un riporto.');
        expect(t).not.toContain('La strada a mano dipende dai saldi');
        expect(t).not.toContain('perché il Wallet accetta un solo saldo per persona');
        // Il cambio d'inquilino in due passi che azzera la fermata.
        expect(t).toContain('e ricorda di correggere i saldi legati alla fermata, se li avevi registrati');
    });

    test('PassaggioProprietaGuide — le fermate della 31.11 e dell\'urgenza: il conguaglio si scrive dal Wallet con saldi legati al passaggio', () => {
        expect(testo(PassaggioProprietaGuide)).toContain('In ognuno di questi casi, se serve un conguaglio, si scrive dal Wallet con saldi legati al passaggio, sulla stessa gestione: non occupano il posto di un riporto e si tolgono se annulli il passaggio.');
    });

    test('PassaggioProprietaGuide — il coinquilino che resta: la strada della fermata, i saldi legati al passaggio', () => {
        expect(testo(PassaggioProprietaGuide)).toContain('la strada a mano è quella della fermata qui sopra: i saldi dal Wallet legati al passaggio, una riga a credito di chi esce e una a debito di chi li paga.');
    });

    test('PassaggioProprietaGuide — il cancello del defunto: i saldi legati alla successione o di prima che sono la parte ci sono già; con saldi di altra cifra, o con il piano che non li applica, il programma non sa', () => {
        const t = testo(PassaggioProprietaGuide);

        expect(t).toContain('dice la strada (i saldi dal Wallet legati alla successione)');
        expect(t).toContain('Se il piano applica i saldi del Wallet e i saldi, legati alla successione o di prima, sono la parte di ognuno, il cancello dice «i saldi ci sono già … non serve registrare altro»; con saldi non legati di altra cifra dice che il programma non sa se comprendono la parte. Se il piano non applica i saldi non legge quelli non legati: con saldi non legati dice che il programma non sa se comprendono la parte, e che se la comprendono basta creare di nuovo il piano, che li assorbe. I saldi legati alla successione li legge anche lì, e non li chiede di nuovo a chi li ha: il piano creato di nuovo li assorbe.');
        expect(t).not.toContain('con saldi di prima non legati, dice che il programma non sa se comprendono la parte');
        expect(t).toContain('I saldi legati alla successione li legge anche lì, e non li chiede di nuovo a chi li ha: il piano creato di nuovo li assorbe. Non li chiede di nuovo nemmeno se un altro piano della stessa gestione, non ancora emesso, li ha già assorbiti: dice in quale piano sono e le due strade, eliminare quel piano, così tornano liberi nel Wallet, o registrare quelli di questo piano dopo aver emesso quell\'altro. Se la parte si regola fra le parti, i saldi legati alla successione ancora liberi nel Wallet si tolgono: il prossimo piano della stessa gestione che applica i saldi li assorbirebbe.');
    });

    test('PassaggioProprietaGuide — annullare la vendita toglie i saldi legati a lei, e nomina quelli scritti a mano senza legame', () => {
        const t = testo(PassaggioProprietaGuide);

        expect(t).toContain('prima di confermare il programma dice la fermata e quanto sommano oggi i saldi del Wallet legati alla vendita, la cui parte senza il conguaglio è zero, e lo storico dice che la fermata non vale più.');

        expect(t).toContain('Annullare la vendita toglie i saldi del Wallet legati a lei; se un piano ne ha già assorbito uno, la vendita non si annulla finché quel piano non è eliminato (il lucchetto si riapre). I saldi scritti a mano senza legarli al passaggio restano: l\'annullamento li nomina, e si tolgono dal Wallet.');
        expect(t).not.toContain('Annullare la vendita non toglie il saldo manuale');
    });

    test('SaldiGuide — una parte che il programma non sposta: il punto della coppia rimanda alla sezione sul saldo legato', () => {
        const t = testo(SaldiGuide);

        expect(t).toContain('Una parte che il programma non sposta (un passaggio che si ferma su una parte, il defunto che un piano calcola ancora) si passa a mano con saldi legati al passaggio: vedi «Il saldo legato a un passaggio», qui sotto.');
        expect(t).not.toContain('sull\'altro saldo legato della persona');
        expect(t).not.toContain('Il pannello e il cancello la indicano solo se nessuna delle persone coinvolte');
    });

    test('SaldiGuide — la sezione sul saldo legato a un passaggio: a cosa serve, l\'esempio di Ugo ed Elsa, il posto, l\'annullamento, la parte che cambia', () => {
        const t = testo(SaldiGuide);

        expect(t).toContain('Il saldo legato a un passaggio');
        expect(t).toContain('Quella parte la passi tu, da qui, con due saldi legati al passaggio: un credito a chi la deve avere e un debito della stessa cifra a chi la deve pagare.');
        expect(t).toContain('€ 60,16 restano a carico di Ugo, e il pannello della vendita lo dice. Se toccano a Elsa, da qui apri «Aggiungi credito», scegli «Soggetto specifico» e spunta «Legato a un passaggio»: la vendita del 1 novembre, Ugo, € 60,16. Poi «Aggiungi debito»: la stessa vendita, Elsa, € 60,16.');
        expect(t).toContain('Non occupa il posto del riporto. Il Wallet tiene un solo saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio né quelli legati a un passaggio: lo scrivi anche a chi ha già il suo.');
        expect(t).toContain('Se annulli il passaggio, si tolgono con lui. Se un piano rate li ha già assorbiti, il passaggio non si annulla finché quel piano non è eliminato (il lucchetto si riapre).');
        expect(t).toContain('il programma non tocca i saldi: la frase del passaggio dice, per ogni persona, quanto sommano oggi i suoi saldi legati e quanto vale la sua parte, e quali non si modificano più (in un piano che non si riscrive più, o in un esercizio che non è quello aperto). Chi ha un saldo legato che si modifica lo corregge qui; chi non ne ha uno registra qui la differenza con un saldo legato in più.');
        expect(t).toContain('Se la frase non sa a chi spetta la parte (una seconda vendita dopo una fermata: a chi ha le righe della coppia, o a chi esce se la fermata di prima è già stata regolata) li nomina tutti e due; se la parte non si legge da sola (per esempio una seconda vendita dopo una fermata insieme a una fermata normale sulle stesse persone), non la dice e indica che cosa correggere se i saldi non sono la parte.');
    });

    test('SaldiGuide — un conguaglio che un piano ha già emesso: resta un saldo manuale di segno opposto, legato a quel passaggio', () => {
        expect(testo(SaldiGuide)).toContain('altrimenti resta un saldo manuale di segno opposto, legato a quel passaggio');
    });

    test('PianoRateGuide — il cancello del defunto: i saldi legati alla successione o di prima che sono la parte ci sono già, se il piano li applica', () => {
        expect(testo(PianoRateGuide)).toContain('e la strada — i saldi dal Wallet legati alla successione. Lo ripete a ogni ricalcolo: se il piano applica i saldi del Wallet e i saldi, legati alla successione o di prima, sono la parte di ognuno, dice che ci sono già; con saldi non legati di altra cifra dice che il programma non sa se la comprendono. Se il piano non applica i saldi non legge quelli non legati, e la strada passa da un piano creato di nuovo, che li assorbe; quelli legati alla successione li legge anche lì, e non li chiede di nuovo a chi li ha.');
        expect(testo(PianoRateGuide)).toContain('Se un altro piano della stessa gestione, non ancora emesso, li ha già assorbiti, non li chiede di nuovo: dice in quale piano sono e le due strade, eliminare quel piano o registrare quelli di questo dopo aver emesso quell\'altro.');
    });

    test('nessuna delle tre guide dice più «altrimenti la parte si regola fra le parti» per chi ha già un saldo', () => {
        expect(testo(PassaggioProprietaGuide).match(/altrimenti (?:dice che )?la parte si regola fra le parti/g)).toBeNull();
        expect(testo(SaldiGuide)).not.toContain('altrimenti dicono che la parte si regola fra le parti');
        expect(testo(PianoRateGuide)).not.toContain('altrimenti la parte si regola fra le parti');
    });

    test('controprova — l\'estinzione registrata a mano e la seconda vendita dopo una fermata restano come prima: non c\'è un passaggio a cui legare (verde anche prima)', () => {
        const t = testo(PassaggioProprietaGuide);

        expect(t).toContain('Non c\'è conguaglio automatico: se serve, si scrive con un saldo manuale dal Wallet sulla stessa gestione.');
        expect(t).toContain('la seconda vendita dice la sua parte come la prima, ma senza il Wallet');
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
