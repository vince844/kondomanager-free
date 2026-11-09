/**
 * Le parole delle lettere: l'ultima lettera di una persona nell'elenco (con «consegna non provata» per l'email, la spedizione con il suo canale,
 * il PDF per persona, l'accordo del tipo con il genere), le righe del registro, il totale di allora e il residuo di oggi, le persone entrate
 * dopo l'anteprima al singolare e al plurale, il credito accanto, le persone di un canale tolte le escluse.
 */
import { describe, expect, test } from 'vitest';
import {
    FRASE_STUDIO_NON_COMPILATO, FRASE_STUDIO_SULLA_CARTA, fraseDelloStudio, personeDelCanale, personeViste, testoAggiunte, testoConteggi, testoCredito, testoGestione, testoOltreTetto, testoRigaDelRegistro, testoRiprova, testoTotaleEResiduo,
    testoUltimaLettera, testoUscite, type PersonaDellAnteprima,
} from './lettere';

describe('l\'ultima lettera nell\'elenco', () => {
    test('l\'email dice «consegna non provata»; la stampa, il PDF per persona e la spedizione dicono come', () => {
        expect(testoUltimaLettera({ tipo: 'sollecito', data: '2026-03-10', canale: 'email', stato: 'inviata', destinatari: ['ugo@x.it'] }))
            .toBe('Sollecito inviato il 10/03/2026 a ugo@x.it, consegna non provata');
        // Una lettera solo stampata o scaricata non conta per «Mai scritto»: la colonna la mostra e dice che la spedizione non è registrata.
        expect(testoUltimaLettera({ tipo: 'sollecito', data: '2026-03-10', canale: 'stampa', stato: 'stampata' })).toBe('Sollecito stampato il 10/03/2026; spedizione non registrata');
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-03-10', canale: 'zip', stato: 'stampata' })).toBe('Diffida scaricata il 10/03/2026 in un PDF per persona; spedizione non registrata');
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-03-10', canale: 'stampa', stato: 'spedita', spedizione: { canale: 'raccomandata', data: '2026-03-11', numero: 'RA1' } }))
            .toBe('Diffida spedita il 11/03/2026 con raccomandata A/R n. RA1');
        expect(testoUltimaLettera({ tipo: 'messa_in_mora', data: '2026-03-10', canale: 'stampa', stato: 'spedita', spedizione: { canale: 'a_mano', data: '2026-03-12' } }))
            .toBe('Messa in mora data a mano il 12/03/2026');
        expect(testoUltimaLettera(null)).toBe('Nessuna lettera');
    });

    test('una lettera in coda o non partita lo dice, con il genere del tipo', () => {
        expect(testoUltimaLettera({ tipo: 'promemoria', data: '2026-03-10', canale: 'email', stato: 'in_coda' })).toBe('Promemoria in coda dal 10/03/2026');
        expect(testoUltimaLettera({ tipo: 'sollecito', data: '2026-03-10', canale: 'email', stato: 'non_partita' })).toBe('Sollecito non partito il 10/03/2026');
        expect(testoUltimaLettera({ tipo: 'diffida', data: '2026-03-10', canale: 'email', stato: 'non_partita' })).toBe('Diffida non partita il 10/03/2026');
    });
});

describe('il registro', () => {
    test('le righe: inviata con «consegna non provata», stampata e scaricata senza spedizione, spedita, non partita con la frase', () => {
        const r = { canale: 'email', formato: null, destinatario: 'ugo@x.it', quando: '2026-03-20 10:00', errore: null, spedizione: null };
        expect(testoRigaDelRegistro({ ...r, stato: 'inviata' })).toBe('Inviata il 20/03/2026 alle 10:00 a ugo@x.it, consegna non provata');
        expect(testoRigaDelRegistro({ ...r, stato: 'non_partita', errore: 'Il debito della lettera è cambiato prima dell\'invio: rivedila e mandala di nuovo.' }))
            .toBe('Non partita il 20/03/2026 alle 10:00, per ugo@x.it: Il debito della lettera è cambiato prima dell\'invio: rivedila e mandala di nuovo.');
        expect(testoRigaDelRegistro({ ...r, canale: 'stampa', destinatario: null, stato: 'stampata' })).toBe('Stampata il 20/03/2026 alle 10:00; spedizione non registrata');
        expect(testoRigaDelRegistro({ ...r, canale: 'stampa', formato: 'zip', destinatario: null, stato: 'stampata' })).toBe('Scaricata il 20/03/2026 alle 10:00 in un PDF per persona; spedizione non registrata');
        expect(testoRigaDelRegistro({ ...r, canale: 'stampa', destinatario: null, stato: 'spedita', spedizione: { canale: 'posta', data: '2026-03-21' } }))
            .toBe('Stampata il 20/03/2026 alle 10:00; spedita il 21/03/2026 per posta ordinaria');
    });

    test('il totale di allora e il residuo di oggi delle stesse rate', () => {
        expect(testoTotaleEResiduo({ totale: 18000, residuo_oggi: 12000, alla: '2026-03-20' })).toBe('Nella lettera € 180,00 (situazione al 20/03/2026); sulle stesse rate oggi € 120,00.');
        expect(testoTotaleEResiduo({ totale: 18000, residuo_oggi: 18000, alla: null })).toBe('Nella lettera € 180,00; sulle stesse rate oggi lo stesso.');
    });
});

describe('le frasi della finestra', () => {
    test('le persone entrate dopo l\'anteprima e quelle uscite, al singolare e al plurale (mai «le 1»)', () => {
        expect(testoAggiunte(0)).toBeNull();
        expect(testoAggiunte(1)).toBe('1 persona è entrata nell\'elenco dopo l\'anteprima: non ha avuto la lettera. Rifai l\'anteprima per scriverle.');
        expect(testoAggiunte(3)).toContain('3 persone sono entrate');
        expect(testoUscite(1)).toContain('1 persona vista nell\'anteprima');
        expect(testoUscite(2)).toContain('2 persone viste');
        expect(testoRiprova({ messaggi: 1, saldate: 1 })).toBe('1 lettera rifatta con le cifre di oggi; 1 non serve più: in quella lettera non c\'è più niente da scrivere.');
        expect(testoConteggi({ email: 1, stampa: 2, zip: 0 })).toBe('Lettere: 1 per email, 2 da stampare.');
    });

    test('il credito accanto: compensare è una scelta dell\'amministratore', () => {
        expect(testoCredito({ nome: 'Bice', credito: 2000 })).toBe('Bice ha un credito di € 20,00: puoi usarlo prima di scrivere.');
    });

    test('le persone di un canale, tolte quelle escluse; le viste sono tutte meno le escluse', () => {
        const p = (id: number, canale: 'email' | 'stampa'): PersonaDellAnteprima => ({ id, nome: `P${id}`, totale: 100, righe: 1, credito: 0, canale, canali: ['email', 'stampa', 'zip'],
            indirizzi: [], senza_email: false, solo_pec: false, senza_indirizzo_postale: false });
        const persone = [p(1, 'email'), p(2, 'email'), p(3, 'stampa')];
        expect(personeDelCanale(persone, { 2: 'zip' }, [1], 'email').map((x) => x.id)).toEqual([]);
        expect(personeDelCanale(persone, { 2: 'zip' }, [], 'zip').map((x) => x.id)).toEqual([2]);
        expect(personeViste(persone, [3])).toEqual([1, 2]);
    });
});

describe('«riprova», il tetto e la gestione', () => {
    test('«riprova»: le lettere rifatte (una lettera a due indirizzi è una), quelle che non servono più, i promemoria diventati scaduti, quelle che restano', () => {
        expect(testoRiprova({ messaggi: 2, lettere: 1, saldate: 0 })).toBe('1 lettera rifatta con le cifre di oggi.');
        expect(testoRiprova({ messaggi: 0, lettere: 0, saldate: 3, promemoria_scaduti: 1 }))
            .toBe('0 lettere rifatte con le cifre di oggi; 2 non servono più: in quelle lettere non c\'è più niente da scrivere; 1 promemoria non serve più: le rate sono scadute. Per quella persona prepara un sollecito dall\'elenco.');
        expect(testoRiprova({ messaggi: 0, lettere: 0, saldate: 2, promemoria_scaduti: 2 }))
            .toBe('0 lettere rifatte con le cifre di oggi; 2 promemoria non servono più: le rate sono scadute. Per quelle persone prepara un sollecito dall\'elenco.');
        expect(testoRiprova({ messaggi: 50, lettere: 50, saldate: 0, tetto_messaggi: 50, restanti: { lettere: 1, messaggi: 1 } }))
            .toBe('50 lettere rifatte con le cifre di oggi. Ne resta 1 da rifare: ne partono al massimo 50 messaggi per volta, premi di nuovo per rifarla.');
    });

    test('il tetto del PDF conta chi si stampa e chi si scarica, non chi va per email, e dice come fare il resto', () => {
        expect(testoOltreTetto({ email: 240, stampa: 10, zip: 0 }, 200)).toBeNull();
        expect(testoOltreTetto({ email: 0, stampa: 201, zip: 0 }, 200)).toBe('Le lettere da stampare sono 201: in un PDF ne stanno al massimo 200. '
            + 'Scegli le persone di una pagina dell\'elenco e prepara le loro, poi passa alla pagina dopo: stampare o scaricare le lettere non cambia l\'ordine dell\'elenco. '
            + 'Se registri la spedizione e l\'elenco ha il filtro «Mai scritto» o «Nessuna lettera negli ultimi N giorni», chi l\'ha avuta esce dall\'elenco: '
            + 'le altre persone ricominciano dalla pagina 1.');
        expect(testoOltreTetto({ email: 0, stampa: 0, zip: 250 }, 200)).toContain('Le lettere da scaricare sono 250: in un .zip ne stanno al massimo 200.');
    });

    test('la gestione delle lettere', () => {
        expect(testoGestione({ id: 7, nome: 'Ordinaria 2026' })).toBe('Solo la gestione «Ordinaria 2026»: le rate delle altre gestioni non sono nelle lettere.');
        expect(testoGestione(null)).toBeNull();
    });
});

describe('lo studio nella lettera', () => {
    test('con il nome dello studio nessuna frase; senza, a campi la lettera porta il solo condominio; con la carta intestata delle lettere la frase dice la carta, non il solo condominio', () => {
        expect(fraseDelloStudio({ studio_compilato: true, carta_intestata: false })).toBeNull();
        expect(fraseDelloStudio({ studio_compilato: true, carta_intestata: true })).toBeNull();
        expect(fraseDelloStudio({ studio_compilato: false, carta_intestata: false })).toBe(FRASE_STUDIO_NON_COMPILATO);
        expect(FRASE_STUDIO_NON_COMPILATO).toBe('I dati dello studio non sono compilati: la lettera porta il solo condominio. Li scrivi nelle impostazioni di stampa.');
        expect(fraseDelloStudio({ studio_compilato: false, carta_intestata: true })).toBe(FRASE_STUDIO_SULLA_CARTA);
        expect(FRASE_STUDIO_SULLA_CARTA).toBe('La lettera esce sulla carta intestata, che porta lo studio. Il nome dello studio nelle impostazioni di stampa è vuoto: '
            + 'se la carta non si legge, la lettera esce con il solo condominio.');
        // Senza il dato (una risposta di prima) la frase di sempre.
        expect(fraseDelloStudio({ studio_compilato: false })).toBe(FRASE_STUDIO_NON_COMPILATO);
    });
});
