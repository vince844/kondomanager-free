/**
 * La voce della commissione nel modulo del pagamento al fornitore.
 *
 * Le voci offerte sono le voci di spesa della gestione del pagamento (quella delle fatture scelte); la voce proposta è quella dell'ultima
 * commissione del condominio in quella gestione, che il server manda per gestione. Se la voce ricordata non è più fra quelle offerte (cancellata,
 * o di un'altra gestione) il campo resta vuoto: mai una voce sbagliata. Cambiando le fatture, e quindi la gestione, una voce dell'altra gestione
 * si toglie. Con una commissione più di zero e senza voce il modulo non si invia, e dice perché con la stessa frase del server. La modifica di un
 * pagamento scritto prima senza voce può lasciarlo così («Lascia senza voce, come era»), finché la commissione non cambia.
 *
 * Fonte dell'atteso: la voce della commissione è dell'amministratore, il programma la propone dall'ultima volta, per condominio e gestione.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';
import {
    ETICHETTA_LASCIA_SENZA_VOCE, FRASE_ALTRA_GESTIONE, FRASE_SENZA_VOCE, LASCIA_SENZA_VOCE, gestioneDelPagamento, mancaLaVoce, puoLasciareSenzaVoce, voceDaInviare, voceDopoIlCambio,
    vocePropostaPer, vociDellaGestione,
} from './voceCommissione';

const ORDINARIA = 10;
const TETTO = 20;
const voci = [
    { id: 1, label: 'Spese bancarie', gestione_id: ORDINARIA },
    { id: 2, label: 'Bolli', gestione_id: ORDINARIA },
    { id: 3, label: 'Bancarie del tetto', gestione_id: TETTO },
];

describe('le voci della gestione del pagamento', () => {
    test('solo quelle della gestione, nell\'ordine del server', () => {
        expect(vociDellaGestione(voci, ORDINARIA).map((v) => v.id)).toEqual([1, 2]);
        expect(vociDellaGestione(voci, TETTO).map((v) => v.id)).toEqual([3]);
    });

    test('senza gestione (nessuna fattura scelta) nessuna voce', () => {
        expect(vociDellaGestione(voci, null)).toEqual([]);
    });
});

describe('la gestione del pagamento', () => {
    test('è quella delle fatture scelte', () => {
        expect(gestioneDelPagamento([{ gestione_id: ORDINARIA }, { gestione_id: ORDINARIA }])).toBe(ORDINARIA);
    });

    test('con fatture di due gestioni non c\'è (il server rifiuta il pagamento), né senza fatture', () => {
        expect(gestioneDelPagamento([{ gestione_id: ORDINARIA }, { gestione_id: TETTO }])).toBeNull();
        expect(gestioneDelPagamento([])).toBeNull();
        expect(gestioneDelPagamento([{ gestione_id: undefined }])).toBeNull();
    });

    test('una fattura senza gestione accanto a una con la gestione: la gestione non si sa', () => {
        expect(gestioneDelPagamento([{ gestione_id: ORDINARIA }, { gestione_id: undefined }])).toBeNull();
        expect(gestioneDelPagamento([{ gestione_id: ORDINARIA }, { gestione_id: null }])).toBeNull();
    });
});

describe('la voce proposta', () => {
    const proposte = [{ gestione_id: ORDINARIA, voce_id: 2 }, { gestione_id: TETTO, voce_id: null }];

    test('l\'ultima della gestione', () => {
        expect(vocePropostaPer(proposte, voci, ORDINARIA)).toBe(2);
    });

    test('nessuna se la gestione non ha commissioni con la voce', () => {
        expect(vocePropostaPer(proposte, voci, TETTO)).toBeNull();
        expect(vocePropostaPer([], voci, ORDINARIA)).toBeNull();
    });

    test('la voce ricordata cancellata, o di un\'altra gestione, lascia il campo vuoto', () => {
        expect(vocePropostaPer([{ gestione_id: ORDINARIA, voce_id: 99 }], voci, ORDINARIA)).toBeNull();
        expect(vocePropostaPer([{ gestione_id: ORDINARIA, voce_id: 3 }], voci, ORDINARIA)).toBeNull();
    });
});

describe('quando cambia la gestione', () => {
    const proposte = [{ gestione_id: ORDINARIA, voce_id: 1 }, { gestione_id: TETTO, voce_id: 3 }];

    test('una voce già scelta della stessa gestione resta', () => {
        expect(voceDopoIlCambio(2, voci, proposte, ORDINARIA)).toBe(2);
    });

    test('una voce dell\'altra gestione lascia il posto alla proposta della nuova', () => {
        expect(voceDopoIlCambio(1, voci, proposte, TETTO)).toBe(3);
    });

    test('un campo vuoto prende la proposta; senza gestione resta vuoto', () => {
        expect(voceDopoIlCambio(null, voci, proposte, ORDINARIA)).toBe(1);
        expect(voceDopoIlCambio(1, voci, proposte, null)).toBeNull();
    });
});

describe('il modulo non si invia senza la voce quando la commissione è più di zero', () => {
    test('manca con una commissione e nessuna voce', () => {
        expect(mancaLaVoce(2.5, null)).toBe(true);
    });

    test('non manca senza commissione, o con la voce', () => {
        expect(mancaLaVoce(0, null)).toBe(false);
        expect(mancaLaVoce(2.5, 1)).toBe(false);
    });

    test('la frase è la stessa del server', () => {
        expect(FRASE_SENZA_VOCE).toBe('Scegli la voce della commissione: senza una voce la commissione resta fuori dal consuntivo delle voci.');
    });

    test('ed è la costante del server, letta dal sorgente: due copie dello stesso testo, una fonte', () => {
        const sorgente = readFileSync(resolve(__dirname, '../../../../../app/Services/Gestionale/VoceDellaCommissione.php'), 'utf8');
        const costante = sorgente.match(/public const SENZA_VOCE = '([^']+)';/)?.[1];

        expect(costante, 'la costante SENZA_VOCE non è stata trovata nel sorgente PHP').toBeDefined();
        expect(FRASE_SENZA_VOCE).toBe(costante);
    });

    test('la via d\'uscita per un\'altra gestione è la costante del server, letta dal sorgente', () => {
        const sorgente = readFileSync(resolve(__dirname, '../../../../../app/Services/Gestionale/VoceDellaCommissione.php'), 'utf8');
        const parti = sorgente.match(/public const ALTRA_GESTIONE = '((?:[^'\\]|\\.)*)'\s*\.\s*'((?:[^'\\]|\\.)*)';/);

        expect(parti, 'la costante ALTRA_GESTIONE non è stata trovata nel sorgente PHP').not.toBeNull();
        expect(FRASE_ALTRA_GESTIONE).toBe((parti![1] + parti![2]).replace(/\\'/g, '\''));
        expect(FRASE_ALTRA_GESTIONE).toBe('Per metterla su una voce di un\'altra gestione, per esempio le spese bancarie dell\'ordinaria, lascia la commissione a zero e registrala con una regolazione immediata.');
    });
});

describe('«Lascia senza voce, come era» nella modifica di un pagamento scritto prima senza voce', () => {
    test('c\'è solo se il pagamento era senza voce e la commissione è quella registrata, in centesimi', () => {
        expect(puoLasciareSenzaVoce(true, 250, 2.5)).toBe(true);
        expect(puoLasciareSenzaVoce(true, 250, 3)).toBe(false);
        expect(puoLasciareSenzaVoce(true, 250, 2.51)).toBe(false);
        expect(puoLasciareSenzaVoce(true, 250, 0)).toBe(false);
        expect(puoLasciareSenzaVoce(false, 250, 2.5)).toBe(false);
        // Una commissione registrata a zero non ha niente da lasciare.
        expect(puoLasciareSenzaVoce(true, 0, 0)).toBe(false);
        // La casella è un numero con i decimali della tastiera: 1,1 euro sono 110 centesimi.
        expect(puoLasciareSenzaVoce(true, 110, 1.1)).toBe(true);
    });

    test('non è una voce: al server parte come nessuna voce; una voce vera parte com\'è', () => {
        expect(ETICHETTA_LASCIA_SENZA_VOCE).toBe('Lascia senza voce, come era');
        expect(voceDaInviare(LASCIA_SENZA_VOCE)).toBeNull();
        expect(voceDaInviare(null)).toBeNull();
        expect(voceDaInviare(7)).toBe(7);
    });

    test('la scelta non è mai l\'id di una voce vera', () => {
        expect(voci.map((v) => v.id)).not.toContain(LASCIA_SENZA_VOCE);
    });
});
