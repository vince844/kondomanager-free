// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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

    test('Coda 717 — la guida dice le tre regole del versamento: il totale del mese, il confronto di ogni soglia, il 20 agosto', () => {
        // Casi A–E di t=202: € 100 esatti di un professionista si rinviano, € 500 esatti di appalti bastano; € 120 + € 30 ad aprile insieme a maggio.
        expect(sorgente).toContain('Il totale si guarda <strong>sul mese</strong>: tutte le ritenute operate nel mese, più quelle rinviate dai mesi prima.');
        expect(sorgente).toContain('Quando la soglia scatta, si versa <strong>tutto il mese</strong> entro il 16 del mese dopo');
        expect(sorgente).toContain('in cui il totale <strong>raggiunge</strong> la soglia: {{ soglia(soglie.appalti_cents) }} esatti bastano.');
        expect(sorgente).toContain('solo quando il totale <strong>supera</strong> la soglia: {{ soglia(soglie.professionisti_cents) }} esatti si rinviano ancora.');
        expect(sorgente).toContain('Le scadenze <strong>dal 1° al 20 agosto</strong> valgono fino al <strong>20 agosto</strong>');
        // «Stessa meccanica» non è più vero: il confronto della soglia del 20 % non è quello degli appalti.
        expect(sorgente).not.toContain('Stessa meccanica');
    });

    test('Coda 718 — la guida dice il promemoria per delega e il «Prepara le deleghe F24», non più un promemoria per pagamento', () => {
        expect(sorgente).toContain('nella tua Inbox c\'è <strong>un promemoria solo</strong> per condominio, «Prepara le deleghe F24», con la data del cumulo');
        expect(sorgente).toContain('<strong>un promemoria per ogni delega</strong>, «F24 da versare», con la sua scadenza e «Apri la delega»');
        expect(sorgente).toContain('«Segna come fatto» resta: il promemoria torna solo se la scadenza cambia.');
        expect(sorgente).not.toContain('Registrando un pagamento con ritenuta compare un promemoria nella tua Inbox');
    });

    test('Coda 754 (10) — la guida dice a che cosa serve «Conferma la delega» e come si torna indietro', () => {
        expect(sorgente).toContain('«Conferma la delega»');
        expect(sorgente).toContain('«Aggiorna scadenze» non la rifà più');
        expect(sorgente).toContain('Non scrive niente in contabilità');
        expect(sorgente).toContain('«Riporta in bozza»');
    });

    test('Coda 754 (10) — la guida dice che i pagamenti di una delega confermata non si stornano né cambiano data finché è confermata', () => {
        expect(sorgente).toContain('Finché è confermata, i pagamenti che porta non si stornano e non cambiano data:');
        expect(sorgente).toContain('il programma si ferma e ti chiede di riportarla in bozza prima.');
    });

    test('Coda 754 (10), decisione 704 — la guida dice l\'avviso della ritenuta già versata, non un blocco', () => {
        expect(sorgente).toContain('Se storni un pagamento la cui ritenuta è già stata versata, il programma te lo dice;');
        expect(sorgente).toContain('la delega del pagamento rifatto porta l\'avviso «non versarla di nuovo».');
        expect(sorgente).not.toContain('storna prima il versamento con «Storna» nella scheda della delega');
    });
});
