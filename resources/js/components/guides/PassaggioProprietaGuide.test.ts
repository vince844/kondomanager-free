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

/**
 * Coda 366: dopo «Annulla il conguaglio» di un cambio d'inquilino e l'uscita «Scrivi» di chi era entrato, il conduttore entrato dopo rimborsa a chi è entrato
 * con il cambio la sua parte dei giorni, come dopo un cambio con il conguaglio scritto (Coda 364); la stampa dice «con il cambio d'inquilino del … regolato fra le parti».
 * Prima la guida non diceva la voce di chi entra dopo un cambio.
 */
describe('PassaggioProprietaGuide — il conduttore entrato dopo un cambio d\'inquilino (Code 364 e 366)', () => {
    test('la guida dice chi rimborsa i giorni di un cambio d\'inquilino, con il conguaglio e regolato fra le parti, e quando non li rimborsa nessuno', () => {
        expect(testoDellaGuida()).toContain('Dopo un cambio d\'inquilino con «Scrivi il conguaglio», un conduttore entrato dopo, anche chi era uscito e rientra, rimborsa a chi è entrato con il cambio '
            + 'la sua parte dei giorni che questi ha pagato con il conguaglio, e la sua voce dice «pagata da … con il conguaglio del cambio d\'inquilino del …»; non i coinquilini di chi è uscito, '
            + 'né chi ne ha preso il posto, che hanno i loro giorni nelle loro rate. Lo stesso se le parti avevano regolato quel cambio fra loro e chi era entrato è poi uscito con «Scrivi il '
            + 'conguaglio»: la voce dice «pagata da … con il cambio d\'inquilino del … regolato fra le parti». Senza quell\'uscita le parti hanno regolato fra loro, e non rimborsa nessuno: '
            + 'l\'accordo copre i giorni di chi era entrato con il cambio, anche in una voce che comincia dopo. Se chi era uscito aveva quei giorni con il conguaglio di un cambio prima, l\'accordo '
            + 'finisce quando chi era entrato esce con la sua fine o con un cambio verso chi era uscito: da lì, anche prima del rientro di chi era uscito o senza rientro, chi entra rimborsa la sua '
            + 'parte a chi ha pagato quei giorni, e se chi era uscito rientra, chi entra nei suoi giorni gli rimborsa la sua parte come senza l\'accordo. Se invece chi era entrato con il cambio '
            + 'passa i suoi giorni a un altro, l\'accordo prosegue con chi ne prende il posto, e finisce allo stesso modo quando questi esce con la sua fine o con un cambio verso chi era uscito: '
            + 'chi entra nei giorni dell\'accordo non rimborsa niente, chi entra dopo sì. Se chi era uscito aveva quei giorni nelle sue rate, restano lì, e chi entra non li rimborsa. L\'uscita dopo di chi è rientrato, quando '
            + 'chiede la scelta del conguaglio, si legge come ogni uscita: chi entra dopo rimborsa chi ha pagato quei giorni. '
            + 'Con «Non scriverlo» i giorni sono rimasti a chi è uscito, nelle sue rate o con il conguaglio che aveva, e chi entra li rimborsa a lui; se chi è entrato con quel cambio li aveva ceduti con un cambio prima ed esce poi con '
            + '«Scrivi il conguaglio», chi entra dopo la sua uscita rimborsa chi ne ha preso la coppia, e lui rimborsa a chi era uscito con «Non scriverlo» tutti i giorni dopo quell\'uscita.');
        // La forma di prima diceva una regola per tutti i cambi, falsa con «Non scriverlo», senza un'uscita dopo l'annullamento e per chi ha preso il posto di un coinquilino.
        expect(testoDellaGuida()).not.toContain('rimborsa a chi è entrato con il cambio la sua parte di quei giorni:');
        // Code 379, 380, 405 e 406: «non rimborsa nessuno» da solo era vero per i giorni dell'accordo ma non diceva fin dove arriva. L'accordo copre i giorni di chi era entrato con il
        // cambio (decisione 144), anche in una voce che comincia dopo (Coda 405). Se chi era uscito li aveva con il conguaglio, finisce alla sua uscita (Coda 406, `Beta55ProspettoCambioCoinquilinoTest`:
        // la fine di Zoe il 1/11, Elsa dal 5/11 rimborsa a Sara anche prima del rientro), e chi entra nei giorni del rientro rimborsa come senza l'accordo; se chi era entrato passa i suoi
        // giorni a un altro l'accordo prosegue con chi ne prende il posto (decisione 148.1, la storia Zoe → Elsa) e finisce con la sua uscita, come quella della parte: al rientro di chi era
        // uscito (decisioni 144 e 148.2) e con la sua fine (decisione 153.3, Coda 413: `Beta59ProspettoAccordoTest`, Teo rimborsa a Sara € 7,19 e € 30,58). Se li aveva nelle sue rate, restano
        // lì (il pezzo di chi ha la riga non si divide, `giorniDellaParte`; la domanda D-a, decisione 153.4, alla 1.12). L'uscita dopo il rientro si legge come ogni uscita (Coda 379); dopo «Non scriverlo» i giorni restano a chi è uscito, nelle sue rate (Coda 358) o con il conguaglio
        // che aveva (Code 365 e 380), e l'uscita «Scrivi» di chi aveva già ceduto quei giorni la segue il prospetto (Coda 380, decisione 145).
        expect(testoDellaGuida()).not.toContain('e non rimborsa nessuno; con «Non scriverlo»');
        // Le forme di prima: «Finito l'accordo» valeva anche per il cambio verso un altro e per chi aveva i giorni nelle sue rate; «nelle rate di chi è uscito» anche per chi li aveva con il conguaglio.
        expect(testoDellaGuida()).not.toContain('Finito l\'accordo');
        expect(testoDellaGuida()).not.toContain('i giorni sono rimasti nelle rate di chi è uscito');
        // Coda 413: la forma della 1.11.0-beta.58 diceva che dopo il cambio verso un altro l'accordo non finiva più.
        expect(testoDellaGuida()).not.toContain('nemmeno dopo l\'uscita di chi ne ha preso il posto o il rientro di chi era uscito');
    });

    test('la voce che matura dopo un cambio regolato fra le parti resta a chi era uscito fino alla sua prossima uscita con un conguaglio suo, nelle sue rate o con il conguaglio che aveva', () => {
        // Code 379 e 406 (`Beta55ProspettoCambioCoinquilinoTest`, «Coda 405 — il «Riscaldamento» dal 15/10…» e «Coda 406 — due voci…»): la voce di Sara resta a lei, con il conguaglio del 1/7, fino alla
        // sua fine del 1/12; chi entra dopo la fine dell'accordo le rimborsa la sua parte, dopo il suo rientro (Elsa € 9,46 del «Riscaldamento») e anche prima, senza rientro (dopo la fine di Zoe,
        // Elsa dal 5/11: € 34,62, nello stesso test). Se l'ha nelle sue rate, chi entra non rimborsa niente.
        expect(testoDellaGuida()).toContain(
            'salvo dopo un cambio che le parti hanno regolato fra loro senza un\'uscita con «Scrivi il conguaglio» di chi era entrato: lì resta a chi era uscito, nelle sue rate o, se l\'aveva ricevuta '
            + 'con il conguaglio di un cambio, con quel conguaglio, fino alla sua prossima uscita con un conguaglio suo. Se l\'ha con il conguaglio, chi entra dopo la fine dell\'accordo, prima o dopo '
            + 'il suo rientro, gli rimborsa la sua parte; '
            + 'nelle sue rate restano i giorni di chi ha la riga.',
        );
        // La forma di prima diceva solo «nelle sue rate», senza dove finisce e senza chi l'ha con il conguaglio.
        expect(testoDellaGuida()).not.toContain('di chi era entrato, dove resta nelle sue rate.');
        // E diceva solo chi entra dopo il rientro.
        expect(testoDellaGuida()).not.toContain('chi entra dopo il suo rientro gli rimborsa');
    });

    test('la guida dice come il prospetto legge un piano che il cambio d\'inquilino non ha preso, e il credito nel Wallet di chi aveva il pezzo regolato fra le parti', () => {
        // Coda 413 (`Beta59ProspettoAccordoTest`): il piano che il cambio non ha preso resta com'è (decisione 43); dopo un cambio regolato fra le parti la fine «Scrivi» di chi era entrato non ne ha la
        // coppia (`ProspettoOneriAccessori::regolatoConUnaFineDopo`: Teo non rimborsa a Ugo € 9,30); se non si ricalcola più (`PianoRate::eImmutabile`: emesso o incassato senza essere ricalcolato dopo il cambio, o preso poi
        // da un passaggio, anche di un altro) si legge come se il cambio non ci fosse (`ProspettoOneriAccessori::catenaDeiConduttori`, decisioni 156.2 e 158: Teo rimborsa a Ines € 9,30, o Luca € 4,20 e
        // Pia € 5,10). Il credito della coppia di chi aveva il pezzo regolato fra le parti si chiama «a suo credito nel Wallet» (decisione 156.4).
        expect(testoDellaGuida()).toContain('Un piano che il cambio d\'inquilino non ha preso, perché quel giorno si ricalcolava ancora, resta com\'è: le sue quote sono di chi le aveva, anche per i giorni '
            + 'di chi è entrato. Se le parti hanno regolato il cambio fra loro, l\'uscita con «Scrivi il conguaglio» di chi era entrato non riguarda quel piano. Altrimenti, se il piano non si ricalcola '
            + 'più (perché è stato emesso o incassato senza essere ricalcolato dopo il cambio, o perché l\'ha preso poi un passaggio, anche di un altro), il prospetto lo legge come se il cambio non ci fosse: le quote restano a chi le ha, e se chi le ha '
            + 'esce, chi entra dopo quell\'uscita rimborsa chi ha pagato i suoi giorni.');
        // Senza un'uscita di chi ha le quote nessuno rimborsa: «Un piano emesso in ottobre, che il cambio del 1/7 non aveva preso, con la fine di Sara» (`Beta59ProspettoAccordoTest`, «Coda 413 — dopo
        // il cambio Ines → Sara annullato…» e «…le guardie del secondo piano…»). La forma di prima faceva rimborsare chi entra dopo l'uscita di chi ha le quote anche dove quell'uscita non c'è.
        expect(testoDellaGuida()).not.toContain('come se il cambio non ci fosse, e chi entra dopo l\'uscita di chi ha quelle quote rimborsa');
        // Il credito di chi ha ceduto i giorni si chiama «a suo credito nel Wallet» e, dove un'entrata supera la spesa, «a suo debito nel Wallet» (la stampa dice le due cose).
        expect(testoDellaGuida()).toContain('«a suo credito nel Wallet»come sopra, se chi era entrato aveva ceduto quei giorni con un cambio e li aveva ripresi con «Non scriverlo»: la coppia '
            + 'della sua uscita gli ha scritto a credito i giorni che rimborsa a chi li ha pagati. Se in quei giorni un\'entrata supera la spesa la riga dice «a suo debito nel Wallet».');
    });
});
