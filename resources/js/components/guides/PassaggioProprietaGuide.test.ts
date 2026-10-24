// @vitest-environment jsdom

/**
 * 1.11.0-beta.51 — la guida in-app «Registrare un passaggio» (`PassaggioProprietaGuide.vue`): il punto sulla fermata dice che un passaggio che
 * cambia chi paga al posto dell'inquilino si ferma sulla parte della fine «Scrivi» e che «conta solo il periodo in cui nessun conduttore era
 * registrato: i giorni di un conduttore li rimborsa lui, con il prospetto». Vero finché il conduttore c'è; DL7 lo smentisce quando esce (Coda 254): i
 * giorni che copriva tornano a chi paga al posto dell'inquilino da quel giorno, e se questo non è più chi ha preso la coppia la parte non si sposta da sola.
 * La guida deve dirlo, nel punto stesso, e il suo incipit deve dire che si ferma anche la fine di un conduttore che libera i giorni.
 *
 * Nessun test leggeva quella frase (misurato: grep su tests, resources, lang, docs: solo la guida e quattro documenti che la citano). Si monta la guida con i
 * segnaposto del foglio e si legge il testo; la guida è solo in italiano.
 *
 * Il testo che la guida deve avere: all'incipit del punto, «… un passaggio che cambia chi paga al posto dell'inquilino, o la fine di un
 * conduttore che ne libera i giorni, si ferma su quella parte»; dopo «lo storico del passaggio lo ricorda», «Lo stesso vale quando finisce la
 * locazione di un conduttore entrato dopo, anche se è la stessa persona uscita e poi rientrata: i giorni che copriva tornano a chi paga al posto
 * dell'inquilino da quel giorno, …», con l'esempio «da € 30,58 a € 61,15 con la fine di Luca». I test cercano questi pezzi, non la frase intera: le
 * parole fra l'uno e l'altro le sceglie chi scrive.
 *
 * La frase che c'era già («Conta solo il periodo in cui nessun conduttore era registrato») deve restare.
 *
 * Cosa NON copre: le altre guide; la traduzione (la guida è solo in italiano).
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PassaggioProprietaGuide from './PassaggioProprietaGuide.vue';

function testoDellaGuida(): string {
    const w = mount(PassaggioProprietaGuide, {
        props: { open: true },
        global: {
            stubs: {
                Sheet: { template: '<div><slot /></div>' }, SheetContent: { template: '<div><slot /></div>' }, SheetHeader: { template: '<div><slot /></div>' },
                SheetTitle: { template: '<div><slot /></div>' }, SheetDescription: { template: '<div><slot /></div>' },
            },
        },
    });

    return w.text().replace(/\s+/g, ' ');
}

describe('PassaggioProprietaGuide — la fine di un conduttore (Coda 254)', () => {
    test('controllo — la guida dice ancora che conta il periodo in cui nessun conduttore era registrato e che i giorni di un conduttore li rimborsa lui', () => {
        expect(testoDellaGuida()).toContain('Conta solo il periodo in cui nessun conduttore era registrato: i giorni di un conduttore li rimborsa lui, con il prospetto.');
    });

    test('l\'incipit del punto dice che si ferma anche la fine di un conduttore che libera i giorni', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('la fine di un conduttore che ne libera i giorni');
        expect(testo).toContain('si ferma su quella parte');
    });

    test('dice che lo stesso vale quando finisce la locazione di un conduttore entrato dopo, anche se è la stessa persona uscita e poi rientrata', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Lo stesso vale quando finisce la locazione di un conduttore entrato dopo');
        expect(testo).toContain('la stessa persona uscita e poi rientrata');
    });

    test('dice dove vanno i giorni (a chi paga al posto dell\'inquilino da quel giorno), che la parte non si sposta da sola, e che il pannello chiede la spunta', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('tornano a chi paga al posto dell\'inquilino da quel giorno');
        expect(testo).toContain('la parte non si sposta da sola');
        expect(testo).toContain('chiede la spunta');
    });

    test('dà l\'esempio della parte che sale (da € 30,58 a € 61,15 con la fine di Luca) quando per quei giorni c\'era già una fermata', () => {
        expect(testoDellaGuida()).toContain('da € 30,58 a € 61,15');
    });
});

/**
 * La chiusura dei subentri (1.11.0-beta.51): le frasi della guida che dicono ciò che la beta cambia oltre alla fine di un conduttore — il piano da
 * ricalcolare dopo un annullamento, la guardia dell'anteprima dell'inizio di una locazione, l'inizio che azzera una fermata, la nota di solidarietà
 * al netto della coppia, il cancello del defunto dopo i saldi e le parole del prospetto. Si cercano i pezzi, non le frasi intere.
 */
describe('PassaggioProprietaGuide — la chiusura dei subentri', () => {
    test('dopo l\'annullamento di una vendita, di un usufrutto o di una successione il piano va ricalcolato prima di emettere, incassare o rimborsare', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Dopo l\'annullamento di una vendita, di un usufrutto o di una successione');
        expect(testo).toContain('va ricalcolato prima di emettere, di incassare o di rimborsare');
        expect(testo).toContain('Ricalcola prima il piano …');
        expect(testo).toContain('La locazione resta fuori.');
    });

    test('l\'inizio di una locazione che sfora le quote si rifiuta già nell\'anteprima, con il testo della registrazione', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('non può superare 100');
        expect(testo).toContain('si rifiuta già nell\'anteprima');
    });

    test('l\'inizio che azzera una fermata è un\'informazione, senza una seconda spunta, e il residuo di più fermate è uno per persona', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('l\'inizio azzera la fermata');
        expect(testo).toContain('senza una seconda spunta');
        expect(testo).toContain('il residuo da regolare è uno solo per persona');
    });

    test('la nota di solidarietà è al netto della coppia, che sta nella stessa frase, e torna lorda se la coppia è assorbita da un secondo piano', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('è al netto della coppia del conguaglio');
        expect(testo).toContain('torna alla cifra lorda');
    });

    test('il cancello del defunto ritorna dopo i saldi con un\'altra frase, e con due successioni la cifra si divide per ruolo o decide l\'amministratore', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('i saldi ci sono già');
        expect(testo).toContain('la cifra si divide per ruolo');
        expect(testo).toContain('decide l\'amministratore');
    });

    test('il prospetto segue una voce che matura dopo un cambio d\'inquilino e legge un totale negativo come «a favore di»', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Una voce che matura tutta dopo un cambio d\'inquilino non è di chi è uscito');
        expect(testo).toContain('si legge «a favore di …» e non con il segno meno');
    });
});

describe('PassaggioProprietaGuide — lo storico della prima vendita dopo la seconda (decisione 108)', () => {
    test('dice quando lo storico della prima vendita dice quanto è passato: se la parte rivenduta viene da quella vendita sola, non con più acquisti o con una parte avuta con la successione nella stessa riga', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('lo storico della prima dice quanto è passato quando la parte rivenduta viene da quella vendita sola');
        expect(testo).toContain('non quando chi rivende ha comprato da più persone o ha nella stessa riga anche una parte avuta con la successione');
        expect(testo).toContain('lì la rivendita dice a carico di chi resta, tutte insieme, e il programma non divide la cifra fra loro');
    });

    test('controprova — non dice più, senza condizioni, che lo storico della prima dice quanto è passato', () => {
        expect(testoDellaGuida()).not.toContain('e lo storico della prima dice quanto è passato. Quando poi');
    });

    test('controprova — non dice più che la seconda vendita è sempre dell\'erede, con il Wallet, «come la fermata di ogni erede»', () => {
        const testo = testoDellaGuida();

        expect(testo).not.toContain('come la fermata di ogni erede');
        expect(testo).not.toContain('se chi rivende è l\'erede di chi gli aveva venduto, la seconda vendita è la sua');
    });
});

/**
 * 1.11.0-beta.55 — le frasi sull'erede di chi aveva venduto, riletta la guida sul codice di adesso. La seconda vendita dell'unico erede è «la sua, con il Wallet» solo se nella catena della sua
 * riga non c'è la fermata di un altro passaggio: con l'estinzione di un usufrutto che aveva fatto tornare pieno chi gli aveva venduto, o con la quota che questi aveva comprato da un'altra persona,
 * resta a carico anche di chi aveva lasciato quella fermata, senza il Wallet (`Beta55FermataRigaDelDefuntoTest`). Poi tre frasi che la beta ha cambiato: chi ricompra la sua stessa parte non fa
 * nascere una fermata, ma la seconda vendita dice quanto resta della prima; chi compra e fra le persone a carico ha la cifra detta per persona; e dove una riga è stata aperta o cambiata a mano
 * la frase non nomina nessuno e dice che il programma non sa nella riga di chi stanno i giorni.
 */
describe('PassaggioProprietaGuide — la seconda vendita dell\'erede e le righe a mano (1.11.0-beta.55)', () => {
    test('l\'unico erede ha la seconda vendita sua, con il Wallet, solo se nella catena della sua riga non c\'è la fermata di un altro passaggio; con quella resta a carico anche di chi l\'aveva lasciata, senza il Wallet', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Se chi rivende è l\'unico erede di chi gli aveva venduto, e nella catena della sua riga non c\'è la fermata di un altro passaggio (l\'estinzione di un usufrutto che aveva fatto tornare pieno '
            + 'chi gli aveva venduto, una quota che questi aveva comprato da un\'altra persona), la seconda vendita è la sua, con il Wallet, come la fermata dell\'erede che non ha comprato niente, e lo storico '
            + 'della prima non dice quanto è passato; se c\'è, resta a carico anche di chi aveva lasciato quella fermata, senza il Wallet.');
        expect(testo).toContain('Se gli eredi sono più d\'uno, resta a carico di tutti loro insieme, o di chi rivende se la fermata della prima vendita è già stata regolata, senza il Wallet.');
    });

    test('controprova — non dice più, senza la condizione, che la seconda vendita dell\'unico erede è la sua', () => {
        expect(testoDellaGuida()).not.toContain('Se chi rivende è l\'unico erede di chi gli aveva venduto, la seconda vendita è la sua');
    });

    test('chi ricompra la sua stessa parte non fa nascere una fermata: la seconda vendita dice quanto resta della prima, con la spunta, e lo storico della prima lo ricorda', () => {
        expect(testoDellaGuida()).toContain('Se a comprare è l\'unica persona a carico di quei giorni (chi ricompra la sua stessa parte, o il nudo proprietario quando si estingue l\'usufrutto '
            + 'che aveva costituito), non nasce una fermata: il passaggio dice quanto resta della fermata di prima, con la spunta se ne resta una parte, e lo storico della prima lo ricorda.');
    });

    test('chi compra e fra le persone a carico ha la cifra detta per persona, perché una parte dei giorni sta già nella sua riga', () => {
        expect(testoDellaGuida()).toContain('salvo che a comprare sia una delle persone a carico e la parte di ognuna si ricavi dai passaggi registrati: allora la frase dice la cifra per persona, '
            + 'perché una parte dei giorni sta già nella riga di chi compra');
    });

    test('dove una riga è stata aperta o cambiata a mano la frase non nomina nessuno e dice che il programma non sa nella riga di chi stanno i giorni, anche dove non nasce una fermata', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Dove la riga di chi vende è stata aperta a mano («Associa soggetto», «Modifica associazione») senza un passaggio registrato prima, nessun passaggio dice nella riga '
            + 'di chi stanno i suoi giorni: se più persone hanno la coppia, la frase non ne nomina nessuna e dice che il programma non lo sa, anche quando a comprare è una di loro e non nasce nessuna '
            + 'fermata; l\'erede che vende la parte avuta con la successione la tiene come sua, e dove un passaggio c\'è la frase legge quello.');
        // Controprova: la frase di prima valeva anche per una riga cambiata dopo un passaggio e per l'erede, dove la frase invece nomina chi è a carico.
        expect(testo).not.toContain('Dove la riga di chi vende è stata aperta o cambiata a mano');
    });
});

/**
 * 1.11.0-beta.55, Coda 245: dove la locazione continua con un coinquilino il conguaglio si sceglie come nelle altre fini, con la regola del piano; i
 * passaggi dopo sulla stessa unità dicono ognuno la sua differenza del piano (la fine di un altro conduttore scrive la differenza di ognuno, o la sola
 * coppia di chi esce se qualcuno era già uscito o chi riceve non ha quote, e dice il resto con la spunta e la strada del Wallet; il cambio d'inquilino dà
 * a chi entra la differenza di chi esce; l'inizio e la fine di un conduttore senza quote né righe sono informazioni); le fini delle beta con la fermata del coinquilino restano com'erano (decisione 111.2). Prima la guida diceva che con un coinquilino
 * il programma non propone il conguaglio e che chi paga lo decidono le parti: falso dalla 1.11.0-beta.55.
 */
describe('PassaggioProprietaGuide — il coinquilino che resta e i passaggi dopo (Coda 245)', () => {
    test('con un coinquilino che resta il conguaglio si sceglie, con la regola del piano; non dice più che il programma non lo propone', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Dove la locazione continua con un coinquilino (esce uno dei conduttori e l\'altro resta) il conguaglio si sceglie come nelle altre fini, e la regola è quella del piano: '
            + 'con «Scrivi il conguaglio» la parte dell\'inquilino si ridivide per quota e per giorni fra chi è stato conduttore nell\'anno, come in un piano generato dopo l\'uscita, e le righe in saldi '
            + 'portano ognuno alla sua parte, salvo chi era già uscito e chi non ha quote del piano né righe, la cui parte il pannello dice con la cifra (di norma chi esce ha un credito e chi resta un '
            + 'debito; chi paga al posto dell\'inquilino ha la parte dei giorni che nessun conduttore copre). Con «Non scriverlo» le rate restano come sono, e il prospetto degli oneri accessori dice '
            + 'chi rimborsa chi.');
        // Controprova (Fase 2 della .55, caso 8): detto senza condizione, il pannello invece la dice con la cifra dove una parte resta fuori.
        expect(testo).not.toContain('e le righe in saldi portano ognuno alla sua parte (di norma');
        expect(testo).not.toContain('il programma non propone il conguaglio dei giorni dopo l\'uscita e per quell\'unità non chiede la scelta');
        expect(testo).not.toContain('chi li paga lo decidono le parti, perché il programma non lo indovina');
        // Caso 1: «senza quote del piano» non basta a dire «senza righe in saldi»: chi è entrato con un cambio e «Scrivi» ha le righe del cambio (RegolaDelPiano::scrivibili, :726; $entrante, :542).
        expect(testo).toContain('Un conduttore entrato con l\'inizio di una locazione, o con un cambio d\'inquilino e «Non scriverlo», su un piano che non si ricalcola più non ha quote del piano né '
            + 'righe in saldi: la sua parte la regola con il prospetto; chi è entrato con un cambio e «Scrivi il conguaglio» ha le righe del cambio, e i passaggi dopo scrivono anche le sue.');
        expect(testo).not.toContain('Un conduttore entrato su un piano che non si ricalcola più, senza quote del piano, non ha righe in saldi');
    });

    test('«Scrivi il conguaglio» dice anche la regola del piano dove la locazione continua con un coinquilino', () => {
        expect(testoDellaGuida()).toContain('«Scrivi il conguaglio»: i giorni dopo l\'uscita passano a chi entra o, senza un nuovo inquilino, a chi paga al posto dell\'inquilino, con righe di saldo che '
            + 'entrano nel prossimo piano della stessa gestione; dove la locazione continua con un coinquilino la parte dell\'inquilino si ridivide con la regola del piano (qui sotto).');
    });

    test('i passaggi dopo: la fine di un altro conduttore scrive solo la sua coppia, il resto chiede la spunta; il cambio, l\'inizio e la fine senza quote', () => {
        const testo = testoDellaGuida();

        // Caso 6 della Fase 2: scrive la differenza di ognuno, come la prima fine, quando tutti possono avere una riga; la sola coppia di chi esce se qualcuno era già uscito o chi riceve non ha quote.
        expect(testo).toContain('Dopo una fine così, con l\'una o l\'altra scelta, i passaggi sulla stessa unità seguono la stessa regola, e ognuno dice la sua differenza del piano: la fine di un altro '
            + 'conduttore scrive la differenza di ognuno, come la prima fine, quando tutti possono avere una riga in saldi; quando qualcuno era già uscito (di norma chi è uscito con la fine di prima), o chi '
            + 'riceve i giorni non ha quote del piano, scrive solo la sua coppia, verso chi riceve i suoi giorni, e la parte che la regola del piano cambierebbe agli altri la dice il pannello, con la '
            + 'persona e la cifra, e chiede la spunta; la strada a mano sono i saldi dal Wallet legati al passaggio (se una di quelle persone non ha né quote del piano né righe in saldi è '
            + 'un\'informazione, e si regola con il prospetto).');
        expect(testo).not.toContain('la fine di un altro conduttore scrive solo la sua coppia, verso chi riceve i suoi giorni');
        expect(testo).not.toContain('se una di quelle persone non ha quote del piano è un\'informazione');
        // Rilievi 1 e 2: la fine senza righe è quella di chi è entrato con un inizio, o con un cambio e «Non scriverlo»; chi è entrato con un cambio e «Scrivi» ha le righe del cambio.
        expect(testo).toContain('Il cambio d\'inquilino dà a chi entra la differenza di chi esce. L\'inizio di un nuovo conduttore, e la fine di un conduttore senza quote del piano né righe in saldi '
            + '(entrato con un inizio, o con un cambio e «Non scriverlo»), non scrivono niente: il pannello dice le cifre del piano, e il prospetto chi rimborsa chi.');
        expect(testo).not.toContain('la fine di un conduttore senza quote del piano, non scrivono niente');
    });

    // Con tre conduttori la fine di dopo (Dora, con Pia che resta) è una prima fine con la regola del piano: non segue la regola dei giorni (decisioni 111.2 e 115.2).
    test('le fini registrate da una beta con il coinquilino che resta restano com\'erano; la fine di un altro conduttore dopo di loro dice la regola del piano e non scrive niente, e la guida dice come avere la regola del piano anche per la fine di prima', () => {
        const testo = testoDellaGuida();

        // Cambiato con la decisione 111.2 applicata anche alla fine con una coppia: dopo una fine delle beta la fine di dopo non scrive niente, neanche la coppia di chi esce, e il prospetto
        // resta alla regola dei giorni (prima: «quella segue la regola del piano … altrimenti la differenza la regolano le parti con il prospetto degli oneri accessori»).
        // Caso 9: non scrive niente solo se trova una fine con la fermata del coinquilino (le beta .50–.54, RegolaDelPiano::finiConLaFermataDelCoinquilino); una fine di prima della .50 non ha né la fermata né `conguaglio.piano`.
        expect(testo).toContain('Una fine con un coinquilino che resta registrata prima della 1.11.0-beta.55, senza la scelta, resta com\'era. Se è una fine con la fermata del coinquilino (dalla 1.11.0-beta.50 '
            + 'alla 1.11.0-beta.54) e dopo c\'è la fine di un altro conduttore con un coinquilino che resta, il pannello dice la regola del piano a partire dalle rate di allora e che cosa ne resterebbe fra le parti, con le persone e le cifre, ma nei saldi non scrive niente, neanche la '
            + 'coppia di chi esce, e il prospetto degli oneri accessori resta alla regola dei giorni: per avere la regola del piano anche per la fine di prima si annulla dallo storico dell\'unità la fine '
            + 'di prima e la si registra di nuovo, prima di quella di dopo (se quella di dopo c\'è già, si annulla prima lei: lo storico lo dice); altrimenti la differenza la regolano le parti. Dove non '
            + 'resta nessun coinquilino i passaggi dopo seguono la regola dei giorni.');
        expect(testo).not.toContain('altrimenti la differenza la regolano le parti con il prospetto degli oneri accessori. Dove non resta');
        expect(testo).not.toContain('resta com\'era, e i passaggi dopo seguono la regola dei giorni');
    });
});

/**
 * 1.11.0-beta.55, Coda 245: il prospetto degli oneri accessori sull'anello di una fine con la regola del piano. Dove la locazione è continuata con un
 * coinquilino il prospetto divide la voce con la regola del piano e dice chi rimborsa chi, con il nome e il passaggio; per le fini delle beta con la
 * fermata del coinquilino dice ancora che chi paga i giorni dopo l'uscita lo decidono le parti, con i saldi del Wallet legati a quella fine. Prima la
 * guida diceva per ogni fine con un coinquilino che il prospetto non indica chi paga quei giorni: falso dalla 1.11.0-beta.55.
 */
describe('PassaggioProprietaGuide — il prospetto con la regola del piano (Coda 245)', () => {
    test('dove la locazione è continuata con un coinquilino il prospetto divide la voce con la regola del piano e dice chi rimborsa chi; le fini delle beta restano com\'erano', () => {
        const testo = testoDellaGuida();

        // Caso 7 della Fase 2: si ridividono le voci che un passaggio qualunque ha cambiato (ProspettoRegolaDelPiano::cambiata), le altre restano come nelle rate.
        expect(testo).toContain('Dove la locazione è continuata con un coinquilino divide con la regola del piano le voci che un passaggio ha cambiato (le altre restano come nelle rate), per quota e '
            + 'per giorni sull\'intera voce, e accanto a ogni conduttore '
            + 'dice ciò che ne ha nelle sue rate, «con il conguaglio della fine locazione del …» ciò che un passaggio ha scritto in saldi e, con il nome e il passaggio, chi rimborsa chi; '
            + 'per le fini registrate prima della 1.11.0-beta.55 dice ancora che chi paga i giorni dopo l\'uscita lo decidono le parti, con i saldi del Wallet legati a quella fine.');
        expect(testo).not.toContain('Dove la locazione continua con un coinquilino non indica chi paga quei giorni, che lo decidono le parti.');
    });

    // Dalla decisione 115.1 il prospetto legge anche il piano generato dalla 1.10, senza il riparto registrato, con le quote delle rate; resta fuori solo dove la regola del piano non si ricalcola più.
    test('il piano generato dalla 1.10 si legge con le quote delle rate, e resta fuori solo dove la regola del piano non si ricalcola più', () => {
        expect(testoDellaGuida()).toContain('Un piano generato dalla 1.10, senza il riparto registrato, il prospetto lo legge con le quote delle rate; se dopo la fine un inquilino prende un altro ruolo '
            + 'sull\'unità (compra una quota), la regola del piano non si ricalcola più, e il prospetto lascia fuori quel piano e dice perché, con le cifre della fine.');
    });
});

describe('PassaggioProprietaGuide — la successione di chi aveva ricevuto e chi vende con una quota fermata (Code 354 e 356)', () => {
    test('la guida dice la successione dell\'unica persona a carico nelle due forme, e la rivendita di chi ha una sua riga e una quota che una fermata gli aveva lasciato nella riga di un altro', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Lo stesso con la successione di chi aveva ricevuto, quando l\'erede è l\'unica persona a carico: con l\'arretrato a nome del defunto e «Scrivi il conguaglio» '
            + 'restano da regolare con chi è morto i suoi giorni fino al decesso; con l\'arretrato agli eredi la sua posizione passa all\'erede, e non resta niente da regolare; con l\'arretrato a nome del defunto '
            + 'e «Non scriverlo» la successione non sposta niente della sua posizione, e la fermata di prima non si riduce.');
        // Caso 4: «a carico di» è chi ha i giorni nella sua riga e va rimborsato; qui la riga è di Ugo e i giorni di Elsa li deve la defunta, «da regolare» come dice il programma.
        expect(testo).not.toContain('restano a carico di chi è morto i suoi giorni fino al decesso');
        expect(testo).toContain('Se chi vende ha anche una sua riga della coppia e la riga che vende ha una quota che una fermata gli aveva lasciato nella riga di un altro (la nuda '
            + 'comprata e poi tornata piena con l\'estinzione dell\'usufrutto), la parte di chi compra si divide: quella della sua riga è a suo carico, con il Wallet; quella della '
            + 'quota resta a carico di chi era a carico di quella fermata, o di chi vende se è già stata regolata, senza il Wallet.');
    });
});
