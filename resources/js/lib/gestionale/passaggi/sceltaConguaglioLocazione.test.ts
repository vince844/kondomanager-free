/**
 * 1.11.0-beta.50 — la scelta sul conguaglio alla fine di una locazione: le regole della pagina (decisioni 77 e 79, verbale
 * `docs/piano_esecutivo_beta50.md`, «Le scelte tecniche del progetto»).
 *
 * Alla fine di una locazione, con un nuovo inquilino (il cambio d'inquilino) o senza, la casella «Le parti hanno regolato il conguaglio
 * fra loro» lascia il posto alle due voci della successione della .48, «Scrivi il conguaglio» e «Non scriverlo», senza nessuna già
 * scelta: un vocabolario solo per tutta la locazione, una scelta sola per l'appartamento e le pertinenze spuntate. La scelta si chiede
 * quando il pannello propone delle coppie (un piano che non si riscrive più); con un coinquilino che resta il pannello non ne propone
 * (decisione 79: nessuna coppia e nessuna scelta). La vendita e l'usufrutto tengono la casella con la nota obbligatoria.
 *
 * Le funzioni nuove di `rinunciaConguaglio.ts`, le stesse per il modulo e per il pannello «Cosa cambierà»:
 * `passaggioConLaScelta(tipo)`, `sceltaConguaglioDelPassaggio(tipo, coppie, arretrato)`, `conguaglioNonScrittoNelPassaggio`,
 * `conguaglioDaScegliereNelPassaggio` e `coppieNonScritte(tipo, coppie, arretrato, spunta, scelta)` (la rinuncia che vale, con la
 * scelta o con la casella). Nella successione e nel legato devono dire quello che dicevano le funzioni della .48, che restano: il
 * confronto è su tutte le combinazioni.
 *
 * Cosa NON copre: il server (la richiesta, l'azione, il registro: `FineLocazioneSceltaTest`, `CambioInquilinoSceltaTest`,
 * `CoinquilinoCheRestaTest`); il pannello montato (`AnteprimaPassaggioLocazione.test.ts`); il modulo `PassaggioNew.vue`.
 */
import { describe, expect, test } from 'vitest';
import {
    conguaglioDaScegliere,
    conguaglioDaScegliereNelPassaggio,
    conguaglioNonScritto,
    conguaglioNonScrittoNelPassaggio,
    coppieNonScritte,
    passaggioConLaScelta,
    rinunciaEffettiva,
    sceltaConguaglioDelPassaggio,
    sceltaConguaglioRichiesta,
} from './rinunciaConguaglio';

describe('la scelta sul conguaglio alla fine di una locazione (1.11.0-beta.50, decisione 77)', () => {
    test('i passaggi con la scelta sono la successione e la fine di una locazione; la vendita, l\'usufrutto e l\'inizio tengono la casella o niente', () => {
        expect(passaggioConLaScelta('successione')).toBe(true);
        expect(passaggioConLaScelta('fine_locazione')).toBe(true);
        for (const tipo of ['vendita', 'usufrutto', 'inizio_locazione', undefined, null]) {
            expect(passaggioConLaScelta(tipo), String(tipo)).toBe(false);
        }
    });

    test('alla fine di una locazione, con o senza un nuovo inquilino, la scelta si chiede quando il pannello propone coppie, anche una sola', () => {
        expect(sceltaConguaglioDelPassaggio('fine_locazione', 1, null)).toBe(true);
        // L'appartamento con il box spuntato, o due persone al posto dell'inquilino: più coppie, una scelta sola.
        expect(sceltaConguaglioDelPassaggio('fine_locazione', 3, undefined)).toBe(true);
    });

    test('senza coppie la scelta non c\'è: un piano che si riscrive ancora, o un coinquilino che resta (decisione 79)', () => {
        expect(sceltaConguaglioDelPassaggio('fine_locazione', 0, null)).toBe(false);
    });

    test('nella vendita e nell\'usufrutto la scelta non c\'è, anche con le coppie: resta la casella', () => {
        expect(sceltaConguaglioDelPassaggio('vendita', 1, null)).toBe(false);
        expect(sceltaConguaglioDelPassaggio('usufrutto', 2, undefined)).toBe(false);
        expect(sceltaConguaglioDelPassaggio('inizio_locazione', 0, null)).toBe(false);
    });

    test('«non_scrivere» vale alla fine di una locazione con le coppie, «scrivi» e la scelta mancante no', () => {
        expect(conguaglioNonScrittoNelPassaggio('fine_locazione', 1, null, 'non_scrivere')).toBe(true);
        expect(conguaglioNonScrittoNelPassaggio('fine_locazione', 1, null, 'scrivi')).toBe(false);
        expect(conguaglioNonScrittoNelPassaggio('fine_locazione', 1, null, null)).toBe(false);
        // Un valore rimasto nel modulo dopo che le coppie sono sparite (il coinquilino, un'altra data) non vale.
        expect(conguaglioNonScrittoNelPassaggio('fine_locazione', 0, null, 'non_scrivere')).toBe(false);
    });

    test('finché la scelta manca il conguaglio è da scegliere; fatta la scelta, o senza coppie, no', () => {
        expect(conguaglioDaScegliereNelPassaggio('fine_locazione', 1, null, null)).toBe(true);
        expect(conguaglioDaScegliereNelPassaggio('fine_locazione', 1, null, undefined)).toBe(true);
        expect(conguaglioDaScegliereNelPassaggio('fine_locazione', 1, null, 'scrivi')).toBe(false);
        expect(conguaglioDaScegliereNelPassaggio('fine_locazione', 1, null, 'non_scrivere')).toBe(false);
        expect(conguaglioDaScegliereNelPassaggio('fine_locazione', 0, null, null)).toBe(false);
    });

    test('alla fine di una locazione la casella non conta più, anche spuntata: le coppie non si scrivono solo con «Non scriverlo»', () => {
        expect(coppieNonScritte('fine_locazione', 1, null, true, null)).toBe(false);
        expect(coppieNonScritte('fine_locazione', 1, null, true, 'scrivi')).toBe(false);
        expect(coppieNonScritte('fine_locazione', 1, null, false, 'non_scrivere')).toBe(true);
        expect(coppieNonScritte('fine_locazione', 0, null, true, 'non_scrivere')).toBe(false);
    });

    test('nella vendita e nell\'usufrutto vale la casella, come prima; una scelta rimasta nel modulo non conta', () => {
        expect(coppieNonScritte('vendita', 1, null, true, null)).toBe(true);
        expect(coppieNonScritte('vendita', 1, null, false, 'non_scrivere')).toBe(false);
        expect(coppieNonScritte('usufrutto', 2, undefined, true, 'scrivi')).toBe(true);
        expect(coppieNonScritte('vendita', 0, null, true, null)).toBe(false);
    });
});

describe('controllo: nella successione e nel legato le funzioni nuove dicono quello che dicevano quelle della .48', () => {
    test('su tutte le combinazioni: scelta richiesta, non scritto, da scegliere e rinuncia che vale', () => {
        for (const coppie of [0, 1, 4]) {
            for (const arretrato of [null, undefined, 'eredi', 'defunto']) {
                for (const scelta of [null, undefined, 'scrivi', 'non_scrivere'] as const) {
                    for (const spunta of [false, true]) {
                        const caso = `coppie ${coppie}, arretrato ${String(arretrato)}, scelta ${String(scelta)}, spunta ${spunta}`;
                        expect(sceltaConguaglioDelPassaggio('successione', coppie, arretrato), caso).toBe(sceltaConguaglioRichiesta(coppie, arretrato));
                        expect(conguaglioNonScrittoNelPassaggio('successione', coppie, arretrato, scelta), caso).toBe(conguaglioNonScritto(coppie, arretrato, scelta));
                        expect(conguaglioDaScegliereNelPassaggio('successione', coppie, arretrato, scelta), caso).toBe(conguaglioDaScegliere(coppie, arretrato, scelta));
                        // Nella successione la casella non c'è mai stata dopo la .48: conta solo la scelta (`PassaggioNew.vue` prima della .50).
                        expect(coppieNonScritte('successione', coppie, arretrato, spunta, scelta), caso).toBe(conguaglioNonScritto(coppie, arretrato, scelta));
                        // E nella vendita la casella, come `rinunciaEffettiva` senza arretrato.
                        expect(coppieNonScritte('vendita', coppie, null, spunta, scelta), caso).toBe(rinunciaEffettiva(coppie, spunta, null));
                    }
                }
            }
        }
    });
});
