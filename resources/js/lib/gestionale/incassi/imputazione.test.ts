import { describe, expect, test } from 'vitest';
import { firmaOrdine, fraseFuoriOrdine, imputazioneMancante, SCELTE_IMPUTAZIONE, type RispostaOrdine } from './imputazione';

/**
 * L'imputazione dichiarata nel modulo dell'incasso (1.12.0-beta.3, decisioni 217 e 218). Il server dice se la distribuzione segue l'ordine
 * delle scadenze e, se no, la rata più vecchia che resta aperta; il modulo chiede chi ha scelto: «indicata da chi ha versato» o «scelta
 * da te», tutte e due con la nota facoltativa (decisione 219): la prima va sulla ricevuta, la seconda resta interna. Niente è già scelto, salvo l'arrivo dal collegamento del portale.
 */

const euro = (c: number) => `€ ${(c / 100).toFixed(2).replace('.', ',')}`;
const fuori: RispostaOrdine = {
    esito: 'fuori_ordine', parte_in_piu: false,
    rata_aperta: { rata_id: 9, numero_rata: 1, descrizione: 'Rata n.1 - Preventivo 2026', scadenza: '2026-01-05', emessa: true, gestione: 'Ordinaria 2026', unita: 'Int. G', resta: 4000 },
};

describe('le due dichiarazioni', () => {
    test('nell\'ordine, con il loro invito alla nota', () => {
        // La nota è facoltativa per tutte e due (decisione 219); l'invito e la riga sotto il campo dicono quale va sulla ricevuta.
        expect(SCELTE_IMPUTAZIONE.map((s) => [s.valore, s.titolo, s.notaObbligatoria])).toEqual([
            ['indicata_dal_versante', 'Indicata da chi ha versato', false],
            ['scelta_amministratore', 'Scelta da te', false],
        ]);
        expect(SCELTE_IMPUTAZIONE.map((s) => [s.invito, s.dove])).toEqual([
            ['Nota, se vuoi: va sulla ricevuta (per esempio la causale del bonifico)', 'Si stampa sulla ricevuta: scrivi le parole di chi ha versato, non le tue.'],
            ['Perché, se vuoi, in una riga: resta interna, non va sulla ricevuta', 'Resta qui: non va sulla ricevuta.'],
        ]);
    });
});

describe('la frase della rata che resta aperta', () => {
    test('dice la rata, la scadenza gg/mm/aaaa, l\'unità, la gestione e quanto resta', () => {
        expect(fraseFuoriOrdine(fuori, euro)).toBe('Questo versamento non segue l\'ordine delle scadenze: resta aperta Rata n.1 - Preventivo 2026 (scadenza 05/01/2026, Int. G, Ordinaria 2026) per € 40,00.');
    });

    test('con la parte in più che diventa credito lo dice', () => {
        expect(fraseFuoriOrdine({ ...fuori, parte_in_piu: true }, euro)).toBe('La parte in più resta a credito mentre è ancora aperta Rata n.1 - Preventivo 2026 (scadenza 05/01/2026, Int. G, Ordinaria 2026) per € 40,00.');
    });

    test('con «Versato da» la parte in più va su una rata di chi ha versato: non dice che resta a credito', () => {
        expect(fraseFuoriOrdine({ ...fuori, parte_in_piu: true }, euro, undefined, 'Bice')).toBe('La parte in più va su una rata di Bice (ne paga quanto resta da pagare; il resto diventa un suo credito), mentre resta aperta Rata n.1 - Preventivo 2026 (scadenza 05/01/2026, Int. G, Ordinaria 2026) per € 40,00.');
        expect(fraseFuoriOrdine(fuori, euro, undefined, 'Bice')).toBe(fraseFuoriOrdine(fuori, euro));
    });

    test('una rata non ancora emessa lo dice; fuori dalle righe a schermo dice come vederla', () => {
        const bozza: RispostaOrdine = { ...fuori, rata_aperta: { ...fuori.rata_aperta!, emessa: false } };

        expect(fraseFuoriOrdine(bozza, euro)).toBe('Questo versamento non segue l\'ordine delle scadenze: resta aperta Rata n.1 - Preventivo 2026 (scadenza 05/01/2026, non ancora emessa, Int. G, Ordinaria 2026) per € 40,00.');
        expect(fraseFuoriOrdine(fuori, euro, () => false)).toBe('Questo versamento non segue l\'ordine delle scadenze: resta aperta Rata n.1 - Preventivo 2026 (scadenza 05/01/2026, Int. G, Ordinaria 2026) per € 40,00. Non è fra le righe a schermo: per vederla togli il filtro della gestione o cerca per persona.');
        expect(fraseFuoriOrdine(fuori, euro, () => true)).toBe(fraseFuoriOrdine(fuori, euro));
    });

    test('secondo l\'ordine, o senza risposta: niente', () => {
        expect(fraseFuoriOrdine({ esito: 'ordine_scadenze', rata_aperta: null, parte_in_piu: false }, euro)).toBeNull();
        expect(fraseFuoriOrdine(null, euro)).toBeNull();
    });
});

describe('che cosa manca prima di confermare', () => {
    test('fuori ordine senza la scelta', () => {
        expect(imputazioneMancante(fuori, null, '')).toBe('Il versamento non segue l\'ordine delle scadenze: scegli chi ha deciso a che cosa va.');
    });

    test('«scelta da te» basta da sola: la nota è facoltativa (decisione 219)', () => {
        expect(imputazioneMancante(fuori, 'scelta_amministratore', '   ')).toBeNull();
        expect(imputazioneMancante(fuori, 'scelta_amministratore', 'Accordo in assemblea')).toBeNull();
    });

    test('«indicata da chi ha versato» basta da sola', () => {
        expect(imputazioneMancante(fuori, 'indicata_dal_versante', '')).toBeNull();
    });

    test('secondo l\'ordine, compensazione, o nessuna risposta: non manca niente', () => {
        expect(imputazioneMancante({ esito: 'ordine_scadenze', rata_aperta: null, parte_in_piu: false }, null, '')).toBeNull();
        expect(imputazioneMancante({ esito: 'compensazione', rata_aperta: null, parte_in_piu: false }, null, '')).toBeNull();
        expect(imputazioneMancante(null, null, '')).toBeNull();
    });
});

describe('la firma della distribuzione', () => {
    const base = { pagante_id: 5, versato_da_id: null, gestione_id: 4, importo_totale: 300, eccedenza: 0, credito_prima: null, credito_fra_gestioni: null,
        dettaglio_pagamenti: [{ rata_id: 1, importo: 100 }, { rata_id: 2, importo: 200 }] };

    test('cambia con le righe, la persona, l\'importo e le scelte sul credito; non con la nota o la causale', () => {
        const firma = firmaOrdine(base);

        expect(firmaOrdine({ ...base })).toBe(firma);
        expect(firmaOrdine({ ...base, dettaglio_pagamenti: [{ rata_id: 2, importo: 200 }, { rata_id: 1, importo: 100 }] })).not.toBe(firma);
        expect(firmaOrdine({ ...base, pagante_id: 6 })).not.toBe(firma);
        expect(firmaOrdine({ ...base, importo_totale: 301 })).not.toBe(firma);
        expect(firmaOrdine({ ...base, credito_prima: true })).not.toBe(firma);
        expect(firmaOrdine({ ...base, imputazione_nota: 'x', descrizione: 'Bonifico' } as any)).toBe(firma);
    });
});
