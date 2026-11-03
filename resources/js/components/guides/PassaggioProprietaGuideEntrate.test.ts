// @vitest-environment jsdom

/**
 * La guida «Registrare un passaggio» (`PassaggioProprietaGuide.vue`), punto della vendita con riserva d'usufrutto, e le voci di entrata.
 *
 * Fra le voci che il modulo propone di spostare dal «Proprietario» all'«Usufruttuario» ci sono anche le entrate (`VociDaSpostare::candidate` non
 * filtra il tipo e lo porta nella voce), e il modulo le mostra con l'etichetta «Entrata» (`PassaggioNew.vue`, `v-if="v.tipo === 'entrata'"`). La
 * guida diceva soltanto che la proposta è la legge, l'usufruttuario per l'art. 1004 c.c., che riguarda le spese: per un affitto o un canone la ragione
 * è l'art. 984 c.c., e se il titolo dell'usufrutto dice altro vale fra le parti, come dice la guida «Ruoli e usufrutto».
 *
 * Si monta la guida con i segnaposto del foglio e si legge il testo con gli spazi normalizzati; la guida è solo in italiano.
 *
 * Cosa resta scoperto: la costituzione dell'usufrutto, che la guida rimanda allo stesso punto; la traduzione.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PassaggioProprietaGuide from './PassaggioProprietaGuide.vue';

function testoDellaGuida(): string {
    const w = mount(PassaggioProprietaGuide, {
        props: { open: true },
        global: {
            stubs: {
                Sheet: { template: '<div><slot /></div>' }, SheetContent: { template: '<div><slot /></div>' }, SheetHeader: { template: '<div><slot /></div>' },
                SheetTitle: { template: '<div><slot /></div>' }, SheetDescription: { template: '<div><slot /></div>' },
            },
        },
    });

    return w.text().replace(/\s+/g, ' ');
}

describe('PassaggioProprietaGuide — le voci di entrata nella riserva d\'usufrutto', () => {
    test('fra le voci da spostare ci sono anche le entrate, con l\'etichetta «Entrata», e per un affitto o un canone la ragione è l\'art. 984 c.c.', () => {
        expect(testoDellaGuida()).toContain('Fra le voci ci sono anche le entrate, con l\'etichetta «Entrata»: per un affitto o un canone la ragione è l\'art. 984 c.c., non l\'art. 1004, e se il titolo dell\'usufrutto dice altro vale fra le parti (guida «Ruoli e usufrutto»).');
    });

    test('controprova — la guida dice ancora che la proposta è la legge e che le voci sono elencate tutte spuntate', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('La proposta è la legge: l\'usufruttuario (art. 1004 c.c.).');
        expect(testo).toContain('Le voci sono elencate tutte spuntate: togli la spunta a quelle che devono andare al nudo proprietario.');
    });
});
