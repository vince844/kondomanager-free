// @vitest-environment jsdom

/**
 * 1.11.0-beta.51, Coda 255 (locale) — `FraseConImporti.vue`: mostra una frase del server con ogni importo in uno span
 * `whitespace-nowrap` (il simbolo e la cifra non vanno a capo separati), il punto finale fuori, e il testo identico. Nessun `v-html`: la frase
 * è testo, non markup.
 *
 * Il componente è `resources/js/components/gestionale/immobili/FraseConImporti.vue`. Il contratto che i test fissano: una prop `testo` (la frase
 * intera, come la manda il server), un elemento inline che contiene la frase.
 *
 * I controlli sul testo e sugli span non dipendono da come il componente è fatto dentro: guardano wrapper.text() e gli elementi
 * `.whitespace-nowrap`. Le note che scrive l'amministratore (la nota del conguaglio, la nota di annullamento) non si avvolgono perché
 * non passano di qui: nei punti in cui si mostrano restano un testo solo (lo provano i test di `Beta51ImportiSenzaACapo.test.ts`, nel cui JSON non
 * hanno importi).
 *
 * Cosa NON copre: dove il componente è usato (i test DOM dei tre componenti); il modo scuro e le larghezze.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import FraseConImporti from './FraseConImporti.vue';
// Il sorgente del componente come testo (`?raw`): `import.meta.url` nell'ambiente jsdom non è un file, e `node:fs` non è nei tipi del progetto.
import sorgente from './FraseConImporti.vue?raw';

const monta = (testo: string) => mount(FraseConImporti, { props: { testo } });
const span = (w: ReturnType<typeof monta>) => w.findAll('.whitespace-nowrap').map((s) => s.text());

describe('FraseConImporti — gli importi non vanno a capo (Coda 255)', () => {
    test('il testo non cambia: wrapper.text() è la frase intera, con il punto', () => {
        const frase = 'Il conguaglio dà € 127,04 a Elsa e € 1.127,04 a Ugo, che restano a loro carico.';

        expect(monta(frase).text()).toBe(frase);
    });

    test('ogni importo è in uno span whitespace-nowrap, e lo span contiene la sola cifra', () => {
        const w = monta('Il conguaglio dà € 127,04 a Elsa e € 1.127,04 a Ugo, che restano a loro carico.');

        expect(span(w)).toEqual(['€ 127,04', '€ 1.127,04']);
    });

    test('il punto finale sta fuori dallo span: «€ 127,04.» ha lo span «€ 127,04» e il punto accanto', () => {
        const w = monta('Elsa riceve € 127,04.');

        expect(span(w)).toEqual(['€ 127,04']);
        expect(w.text()).toBe('Elsa riceve € 127,04.');
    });

    test('una frase senza importi non ha span nowrap', () => {
        const w = monta('Nessun importo qui, 120 giorni a Ugo.');

        expect(span(w)).toEqual([]);
        expect(w.text()).toBe('Nessun importo qui, 120 giorni a Ugo.');
    });

    test('nessun v-html: un tag dentro la frase resta testo e non diventa un elemento', () => {
        const w = monta('Dà € 127,04 e <b>questo non è un tag</b>.');

        expect(w.find('b').exists()).toBe(false);
        expect(w.text()).toBe('Dà € 127,04 e <b>questo non è un tag</b>.');
    });

    test('il sorgente del componente non usa v-html', () => {
        expect(sorgente).not.toContain('v-html');
    });
});
