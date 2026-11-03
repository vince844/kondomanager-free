import { describe, expect, it } from 'vitest';
import { cellaCategorie } from './categorie-cella';

/**
 * 1.11.0-beta.10 — la cella delle categorie di un documento.
 *
 * ## Perché questi test esistono
 *
 * La beta.10 rende plurale una cosa che era singolare, e la colonna dell'elenco è il posto dove
 * quel cambiamento si vede. Fino alla beta.9 la cella mostrava un'etichetta e basta: non c'era
 * niente da decidere, quindi niente da provare. Adesso decide **quante** mostrarne, **in che
 * ordine**, e cosa dire quando non ce n'è nessuna — e sono le tre cose che, sbagliate, non
 * assomigliano a un errore ma a una tabella un po' strana.
 *
 * ⚠️ **Nessun test lato server può vederle**: il server manda l'elenco completo e ha ragione. Il
 * riassunto avviene nel browser, ed è esattamente la famiglia di difetti che è costata la beta.9 —
 * l'eliminazione che non eliminava, verde su 2000 test Pest.
 *
 * ## Cosa questo file NON copre
 *
 * Non monta la colonna né la tabella: non prova che i `Badge` escano, che le classi siano quelle,
 * né che il `title` sia davvero leggibile passandoci sopra. Prova le **decisioni**; la resa si
 * guarda a video, ed è stata guardata.
 */
describe('cellaCategorie', () => {
  const cat = (id: number, name: string) => ({ id, name });

  it('una sola categoria si mostra e basta: niente contatore', () => {
    const cella = cellaCategorie([cat(1, 'Bilanci')]);

    expect(cella.vuoto).toBe(false);
    expect(cella.visibili.map((c) => c.name)).toEqual(['Bilanci']);
    expect(cella.restanti).toBe(0);
  });

  it('⚠️ l\'ordine è alfabetico, non quello in cui sono state attaccate', () => {
    // È la riga che tiene la colonna confrontabile: senza, la stessa coppia comparirebbe come
    // «Bilanci, Verbali» su una riga e «Verbali, Bilanci» su un'altra, e sembrerebbe un errore.
    const cella = cellaCategorie([cat(2, 'Verbali'), cat(1, 'Bilanci')]);

    expect(cella.visibili.map((c) => c.name)).toEqual(['Bilanci', 'Verbali']);
  });

  it('⚠️ non riordina l\'elenco che riceve', () => {
    // Arriva dai props di Inertia: `sort()` ordina sul posto, e riordinarlo cambierebbe il dato
    // sotto ad altri pezzi della pagina che leggono lo stesso oggetto.
    const originale = [cat(2, 'Verbali'), cat(1, 'Bilanci')];

    cellaCategorie(originale);

    expect(originale.map((c) => c.name)).toEqual(['Verbali', 'Bilanci']);
  });

  it('dalla terza in poi si contano, e il titolo le elenca TUTTE', () => {
    const cella = cellaCategorie([
      cat(3, 'Contratti'),
      cat(2, 'Verbali'),
      cat(1, 'Bilanci'),
      cat(4, 'Avvisi'),
    ]);

    expect(cella.visibili.map((c) => c.name)).toEqual(['Avvisi', 'Bilanci']);
    expect(cella.restanti).toBe(2);

    // ⚠️ Comprese le due già visibili: il titolo risponde a «quali sono?», non a «quali mancano».
    // Un elenco che parte dalla terza costringe chi legge a tenere a mente le prime due.
    expect(cella.titolo).toBe('Avvisi, Bilanci, Contratti, Verbali');
  });

  it('esattamente due non producono un «+0»', () => {
    // Il contatore a zero è una pillola che non dice niente e occupa spazio: la colonna la disegna
    // solo se `restanti > 0`, e questa riga è la ragione per cui può fidarsi del numero.
    const cella = cellaCategorie([cat(1, 'Bilanci'), cat(2, 'Verbali')]);

    expect(cella.visibili).toHaveLength(2);
    expect(cella.restanti).toBe(0);
  });

  it('⚠️ nessuna categoria è uno STATO, non una cella vuota', () => {
    // I documenti caricati su un fornitore, un\'unità o un\'anagrafica non hanno categoria **di
    // proposito**. Chi li vede in un elenco deve capire che è così e non che manca un dato.
    for (const niente of [[], null, undefined]) {
      const cella = cellaCategorie(niente);

      expect(cella.vuoto).toBe(true);
      expect(cella.visibili).toEqual([]);
      expect(cella.restanti).toBe(0);
      expect(cella.titolo).toBe('');
    }
  });

  it('il numero di etichette visibili si può cambiare senza toccare la cella', () => {
    const tutte = [cat(1, 'Bilanci'), cat(2, 'Verbali'), cat(3, 'Contratti')];

    expect(cellaCategorie(tutte, 1).restanti).toBe(2);
    expect(cellaCategorie(tutte, 3).restanti).toBe(0);
  });

  it('un massimo a zero mostra tutto invece di nascondere tutto', () => {
    // Controprova su un valore che nessuno passerebbe di proposito: la cella non deve poter
    // diventare un «+3» senza niente scritto accanto, che è il modo peggiore di sbagliare.
    const cella = cellaCategorie([cat(1, 'Bilanci'), cat(2, 'Verbali')], 0);

    expect(cella.visibili).toHaveLength(2);
    expect(cella.restanti).toBe(0);
  });

  it('ordina con le regole dell\'italiano, accenti compresi', () => {
    // `localeCompare(…, 'it')` e non un confronto fra stringhe: in ASCII «Àvvisi» finirebbe dopo
    // «Verbali», e l\'amministratore che scrive le proprie categorie ne mette di accentate.
    const cella = cellaCategorie([cat(1, 'Verbali'), cat(2, 'Àvvisi')], 5);

    expect(cella.visibili.map((c) => c.name)).toEqual(['Àvvisi', 'Verbali']);
  });
});
