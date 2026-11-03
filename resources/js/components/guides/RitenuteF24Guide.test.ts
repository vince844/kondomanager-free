/**
 * La guida delle ritenute e dell'F24: le frasi che cambiano con le Code 314 e 326.
 *
 * Si legge il sorgente, come nei test delle finestre del fornitore: le schede non visibili di un `Tabs` non si rendono
 * montando il componente, e qui conta la frase, non il disegno.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const sorgente = fs.readFileSync('resources/js/components/guides/RitenuteF24Guide.vue', 'utf8').replace(/\s+/g, ' ');

describe('RitenuteF24Guide', () => {
    test('Coda 326 — accanto all\'art. 25-ter DPR 600/1973 la guida dice l\'articolo del testo unico dal 1° gennaio 2027', () => {
        // Art. 243 c. 1 D.Lgs. 33/2025, rinviato al 1° gennaio 2027 dall'art. 4 c. 4 del D.L. 200/2025; art. 40 = art. 25-ter.
        expect(sorgente).toContain('(art. 25-ter DPR 600/1973; dal 1° gennaio 2027 art. 40 del D.Lgs. 33/2025)');
    });

    test('Coda 314 — la guida dice che lo scadenziario raccoglie le ritenute di tutti gli esercizi e in quale esercizio va il versamento', () => {
        expect(sorgente).toContain('Lo scadenzario raccoglie le ritenute da versare e le deleghe non ancora versate di <strong>tutti gli esercizi</strong> del condominio');
        expect(sorgente).toContain('se ne registri il versamento, la scrittura va in quell\'esercizio');
    });

    test('Coda 314 — la guida dice che le ritenute degli esercizi chiusi mai messe in una delega ricompaiono, e che una già versata fuori dal programma il programma non la riconosce', () => {
        // Dalla 1.11.0-beta.59 ricompaiono, con la loro scadenza di legge anche passata (Coda 314, tutti gli esercizi);
        // «Annulla» le toglie dai contatori ma il pulsante «Aggiorna scadenze» le conta ancora e le rifà.
        expect(sorgente).toContain('Le ritenute degli esercizi chiusi che non sono mai finite in una delega ricompaiono qui');
        expect(sorgente).toContain('il programma non lo sa e non c\'è un modo per dirglielo: non versarla una seconda volta');
        expect(sorgente).toContain('il pulsante «Aggiorna scadenze» continua a contarla e la rifà al prossimo ricalcolo');
    });

    test('Coda 314 — la guida dice che lo storico dei vecchi esercizi resta nell\'elenco con il nome dell\'esercizio e che il «Versato» è quello dell\'esercizio corrente', () => {
        // Decisione 154.3: l'elenco mostra anche le versate, le stornate e le annullate dei vecchi esercizi; il «Versato» è dell'esercizio corrente.
        expect(sorgente).toContain('Le deleghe già versate, stornate o annullate dei vecchi esercizi restano nell\'elenco, con il nome del loro esercizio; il totale «Versato» è quello dell\'esercizio corrente');
        expect(sorgente).not.toContain('Le deleghe già versate, stornate o annullate e il totale «Versato» sono quelli dell\'esercizio corrente');
    });

    test('Coda 314 — la guida non promette che «Aggiorna scadenze» non fa mai danni: dice che le bozze di un esercizio chiuso passano a quello corrente e che le altre deleghe non si toccano', () => {
        // Dalla 1.11.0-beta.59 premerlo sposta una bozza del vecchio esercizio nel corrente, e con lei l'esercizio della scrittura del versamento.
        expect(sorgente).not.toContain('premerlo non fa mai danni');
        expect(sorgente).toContain('si rifanno da capo ogni volta, e quelle di un esercizio chiuso passano all\'esercizio corrente. Le deleghe confermate, versate, stornate o annullate non le tocca');
    });

    test('Coda 314 — la guida non lega il cambiamento al pulsante ambra: il colore dice solo che ci sono ritenute fuori da ogni delega', () => {
        // Con il pulsante a zero premerlo rifà comunque le bozze di un esercizio chiuso nel corrente (`GeneraDelegheF24Action::esegui`),
        // quindi «cioè quando premerlo cambia davvero qualcosa» è falso accanto alla frase che lo dice.
        expect(sorgente).not.toContain('quando premerlo cambia davvero qualcosa');
        expect(sorgente).toContain('quando ci sono ritenute operate che non stanno ancora in nessuna delega.');
    });
});
