// @vitest-environment jsdom

/**
 * Le frasi delle lettere, dell'elenco «Rate scadute», dell'accordo, dell'origine del pregresso e dei modelli, come le legge l'amministratore: vere
 * sul codice e in italiano semplice. Il participio si accorda con il tipo della lettera; il tetto dello .zip non è il tetto di un PDF; il programma
 * non prepara un sollecito al posto dell'amministratore; il nome «Supercondominio» non riceve la parola davanti.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';
import { FRASE_TESTO_DEL_PROGRAMMA, domani, testoOltreTetto, testoRiprova, testoSpedizione, testoUltimaLettera, testoUscite } from './lettere';
import { riempiEsempio } from './modelli';
import { FRASE_RISERVATEZZA, RIGA_TESORERIA } from '@/lib/gestionale/debiti/rateScadute';
import { FRASE_AUTORIZZAZIONE, FRASE_NON_SCADUTE } from '@/lib/gestionale/debiti/accordiRientro';
import { SPIEGAZIONE_VOCI } from '@/lib/gestionale/debiti/originePregresso';

const sorgente = (f: string) => fs.readFileSync(f, 'utf8').replace(/\s+/g, ' ');

describe('le lettere', () => {
    test('la spedizione si accorda con il tipo della lettera; gli estremi della PEC senza «n.»', () => {
        expect(testoUltimaLettera({ tipo: 'sollecito', data: '2026-03-10', canale: 'stampa', stato: 'spedita',
            spedizione: { canale: 'raccomandata', data: '2026-03-11', numero: 'RA1' } })).toBe('Sollecito spedito il 11/03/2026 con raccomandata A/R n. RA1');
        expect(testoUltimaLettera({ tipo: 'promemoria', data: '2026-03-10', canale: 'stampa', stato: 'spedita', spedizione: { canale: 'a_mano', data: '2026-03-12' } }))
            .toBe('Promemoria dato a mano il 12/03/2026');
        expect(testoUltimaLettera({ tipo: 'sollecito', data: '2026-03-10', canale: 'stampa', stato: 'spedita', spedizione: { canale: 'pec', data: '2026-03-12', numero: 'X' } }))
            .toBe('Sollecito mandato per PEC il 12/03/2026, estremi X');
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-03-10', canale: 'stampa', stato: 'spedita', spedizione: { canale: 'raccomandata', data: '2026-03-11', numero: 'RA1' } }))
            .toBe('Diffida spedita il 11/03/2026 con raccomandata A/R n. RA1');
        // Senza il tipo davanti è la lettera: al femminile.
        expect(testoSpedizione({ canale: 'pec', data: '2026-03-12', numero: 'X' })).toBe('mandata per PEC il 12/03/2026, estremi X');
    });

    test('il tetto dello .zip è dello .zip, quello della stampa è del PDF', () => {
        expect(testoOltreTetto({ email: 0, stampa: 0, zip: 250 }, 200)).toContain('Le lettere da scaricare sono 250: in un .zip ne stanno al massimo 200.');
        expect(testoOltreTetto({ email: 0, stampa: 201, zip: 0 }, 200)).toContain('Le lettere da stampare sono 201: in un PDF ne stanno al massimo 200.');
    });

    test('il promemoria che non serve più: il sollecito lo prepara l\'amministratore', () => {
        expect(testoRiprova({ messaggi: 0, lettere: 0, saldate: 1, promemoria_scaduti: 1 }))
            .toBe('0 lettere rifatte con le cifre di oggi; 1 promemoria non serve più: le rate sono scadute. Per quella persona prepara un sollecito dall\'elenco.');
        expect(testoRiprova({ messaggi: 0, lettere: 0, saldate: 2, promemoria_scaduti: 2 }))
            .toBe('0 lettere rifatte con le cifre di oggi; 2 promemoria non servono più: le rate sono scadute. Per quelle persone prepara un sollecito dall\'elenco.');
    });

    test('chi esce dall\'anteprima non ha più rate da mettere nella lettera', () => {
        expect(testoUscite(1)).toBe('1 persona vista nell\'anteprima adesso non ha più rate da mettere nella lettera: non l\'ha avuta.');
        expect(testoUscite(2)).toBe('2 persone viste nell\'anteprima adesso non hanno più rate da mettere nella lettera: non l\'hanno avuta.');
    });

    test('la riga del testo di partenza di diffida e messa in mora dice che sono neutri e differiscono nel tono', () => {
        expect(FRASE_TESTO_DEL_PROGRAMMA).toBe('È il testo di partenza del programma: neutro, senza articoli di legge, interessi o spese; diffida e messa in mora differiscono '
            + 'solo nel tono. Controllalo, e cambialo se serve, prima di mandarlo.');
    });

    test('la data del termine si sceglie da domani: il server rifiuta oggi', () => {
        expect(domani('2026-03-20')).toBe('2026-03-21');
        expect(domani('2026-02-28')).toBe('2026-03-01');
        expect(domani('2026-12-31')).toBe('2027-01-01');
        const finestra = sorgente('resources/js/components/gestionale/lettere/LettereDialog.vue');
        expect(finestra).toContain(':min="domani(oggi)"');
        expect(finestra).not.toContain('in alto ci sono «Gentile»');
    });

    test('«Supercondominio Le Torri» non riceve la parola davanti, come «Condominio Le Rose»', () => {
        const esempio = { nome: 'Mario Rossi', totale: '€ 10,00', termine: '15 giorni', condominio: 'Supercondominio Le Torri' };
        expect(riempiEsempio('del condominio [condominio].', esempio)).toBe('del Supercondominio Le Torri.');
        expect(riempiEsempio('del condominio [condominio].', { ...esempio, condominio: 'Le Torri' })).toBe('del condominio Le Torri.');
    });
});

describe('l\'elenco, l\'accordo, l\'origine', () => {
    test('a chi si mostra l\'elenco, e la Tesoreria che «può dare» una cifra più bassa', () => {
        expect(FRASE_RISERVATEZZA).toBe('Contiene dati personali: si mostra all\'assemblea e a chi ha diritto di conoscerlo, come il condòmino o il creditore non ancora '
            + 'pagato che lo chiedono. Non va esposto in bacheca né in altri spazi che vedono anche gli estranei.');
        expect(RIGA_TESORERIA).toContain('La riga «Rate scadute» della Tesoreria, nella dashboard, può dare una cifra più bassa: a ognuno toglie il suo credito nella stessa gestione.');
    });

    test('l\'accordo: l\'autorizzazione la registra l\'amministratore; il giorno dell\'accordo nuovo è quello della chiusura', () => {
        expect(FRASE_AUTORIZZAZIONE).toContain('diventa «sì» quando registri la delibera.');
        expect(FRASE_NON_SCADUTE).toBe('Le rate non ancora scadute non entrano. Quando scadono, chiudi questo accordo e registrane uno nuovo con tutte le rate, con la data '
            + 'della chiusura.');
    });

    test('«dovuto con questo piano» è una dichiarazione, non per forza un conguaglio', () => {
        expect(SPIEGAZIONE_VOCI).toContain('«Dovuto con questo piano»: il debito si chiede adesso, con questo piano (per esempio un conguaglio); i giorni si contano dalla '
            + 'scadenza della rata, come per una rata.');
    });

    test('le frasi delle pagine: la coda ferma dice che cosa controllare, la data in evidenza è la più vicina senza una dispensa, i due filtri, il registro', () => {
        const elenco = sorgente('resources/js/pages/gestionale/movimenti/rateScadute/RateScadute.vue');
        expect(elenco).toContain('sono in coda da più di un\'ora: non sono ancora partite.` }} Di solito vuol dire che sul server non gira il servizio che manda le email in coda');
        expect(elenco).toContain('La più vicina senza una dispensa è in evidenza;');
        expect(elenco).toContain('Mai scritto (contano le lettere partite o spedite)');
        expect(elenco).toContain('Stampate o scaricate, spedizione da registrare');
        expect(sorgente('resources/js/components/gestionale/lettere/RegistroLettere.vue')).toContain('<h3 class="text-sm font-semibold">Lettere</h3>');
        expect(sorgente('resources/js/components/gestionale/accordi/AccordoRientroDialog.vue')).toContain('Le rate che può coprire: quelle già scadute al');
        const origine = sorgente('resources/js/components/gestionale/saldi/DichiaraOrigineDialog.vue');
        expect(origine).toContain('L\\\'origine non è stata dichiarata. Riprova.');
        expect(origine).toContain('Togli l\\\'origine da');
        expect(sorgente('resources/js/pages/import/ImportAnteprima.vue')).toContain('non sono esercizi di questo file: li cerco fra gli esercizi del condominio quando importi');
        expect(sorgente('resources/js/components/guides/FattureGuide.vue')).toContain('storna prima il pagamento, da Pagamenti.');
    });
});
