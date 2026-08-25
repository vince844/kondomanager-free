import { describe, it, expect } from 'vitest';
import { misuraLeggibile } from './misure';

/**
 * La cella dei dati tecnici scriveva quello che il database le passava, e il database passa
 * `decimal` come stringa con tutti i decimali dichiarati. Questi test fissano le due cose che
 * contano: gli zeri di coda non si vedono, e il separatore è **il punto** — la stessa scelta
 * fatta sui millesimi nella beta.61, perché così il valore che si vede è quello che si salva.
 *
 * ⚠️ **Cosa resta scoperto:** il modo in cui la cella scrive il *vuoto* (`—`, `-`, niente) è una
 * scelta della cella e non di questa funzione, quindi qui si verifica solo che il vuoto arrivi
 * come `null`. E non c'è nessun test sul separatore delle migliaia, perché la funzione non lo
 * mette: se un giorno servisse, servirà anche decidere cosa fare dell'ambiguità con la virgola.
 */
describe('misuraLeggibile', () => {
  it('toglie gli zeri di coda che il decimal si porta dietro', () => {
    // È il caso di tutte le unità già a database dopo la migrazione della beta.62: `numero_vani`
    // era `6` ed esce `"6.00"`. Senza questa riga la cella scriverebbe «6.00 vani».
    expect(misuraLeggibile('6.00')).toBe('6');
    expect(misuraLeggibile('456.00')).toBe('456');
  });

  it('scrive il decimale col punto: quello che si vede è quello che si salva', () => {
    // ⚠️ La prima stesura usava la virgola. Cambiata su indicazione di Vincenzo il 20/08/2026,
    // con l'argomento che vale più dell'eleganza: col punto la casella non ha bisogno di nessuna
    // conversione al salvataggio, e il numero che l'amministratore legge è letteralmente quello
    // che finisce in colonna. In ingresso la virgola resta accettata e raddrizzata in silenzio.
    expect(misuraLeggibile('6.50')).toBe('6.5');
    expect(misuraLeggibile('6.25')).toBe('6.25');
    expect(misuraLeggibile('90.5')).toBe('90.5');
  });

  it('non arrotonda: un valore più preciso del previsto si vede per intero', () => {
    // La regola della beta.61 sui millesimi, applicata qui: i decimali governano come il valore
    // si mostra, mai cosa viene conservato. Accorciare a video un numero arrivato da altrove è il
    // modo in cui si perde un dato senza che nessuno lo dica.
    expect(misuraLeggibile('6.333')).toBe('6.333');
  });

  it('accetta anche un numero, non solo la stringa del database', () => {
    expect(misuraLeggibile(6)).toBe('6');
    expect(misuraLeggibile(6.5)).toBe('6.5');
  });

  it('il vuoto resta vuoto, e non diventa zero', () => {
    // Un'unità senza vani dichiarati non ne ha «zero»: il dato manca. Confonderli è come
    // confondere la riga assente con il millesimo a zero — la distinzione che la beta.61 ha
    // dovuto scrivere in una guida perché costava un riparto sbagliato.
    expect(misuraLeggibile(null)).toBeNull();
    expect(misuraLeggibile(undefined)).toBeNull();
    expect(misuraLeggibile('')).toBeNull();
  });

  it('quello che non è un numero non diventa un numero', () => {
    expect(misuraLeggibile('abc')).toBeNull();
  });
});
