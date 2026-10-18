// @vitest-environment jsdom

/**
 * La guida della creazione del piano rate (`PianoRateGuide.vue`), scheda del budget, e le voci di entrata.
 *
 * Scegliendo delle voci, un'entrata porta l'etichetta «Entrata» nel menu (`PianiRateNew.vue`, `option.tipo === 'entrata'`) e il totale delle voci
 * scelte si chiama «Da ripartire», con le spese e le entrate fra parentesi, quando le entrate sono più di zero (`fraseDaRipartire` in
 * `totaliPerTipo.ts`: `totali.entrate === 0` dice «Totale richiesto»). L'importo per voce non prende il meno: il campo ha `disableNegative` e il
 * server lo rifiuta (`CreatePianoRateRequest`, `ImportoSenzaSegno::PER_IL_PIANO`).
 *
 * La creazione si ferma su una voce di spesa negativa che il piano prenderebbe, con le voci scelte o con tutto il preventivo
 * (`PianoRateController::store`, `SpeseNegativeNelPiano::trova` e `SpesaNegativaNelPianoException` con `allaCreazione`; con «genera subito» lo fa
 * `GeneratePianoRateAction`): il messaggio dice di correggere la voce nel piano dei conti (`SpeseNegativeNelPiano::frasiDiCorrezione`).
 *
 * Le schede si montano tutte, con i segnaposto, e si legge il testo con gli spazi normalizzati; la guida è solo in italiano.
 *
 * Cosa resta scoperto: le altre schede; la traduzione.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import PianoRateGuide from './PianoRateGuide.vue';

const passa = { template: '<div><slot /></div>' };

function testoDellaGuida(): string {
    const w = mount(PianoRateGuide, {
        props: { open: true },
        global: {
            stubs: {
                Sheet: passa, SheetContent: passa, SheetHeader: passa, SheetTitle: passa, SheetDescription: passa,
                Tabs: passa, TabsList: passa, TabsTrigger: passa, TabsContent: passa,
            },
        },
    });

    return w.text().replace(/\s+/g, ' ');
}

describe('PianoRateGuide — le voci di entrata nella scelta del budget', () => {
    test('l\'importo si scrive senza il segno, anche per un\'entrata, che ha l\'etichetta «Entrata» e si sottrae alle spese', () => {
        expect(testoDellaGuida()).toContain('L\'importo si scrive senza il segno, anche per una voce di entrata, come un affitto o un canone: ha l\'etichetta «Entrata» e si sottrae alle spese');
    });

    test('con delle entrate il totale delle voci scelte si chiama «Da ripartire», con le spese e le entrate fra parentesi', () => {
        expect(testoDellaGuida()).toContain('con delle entrate il totale delle voci scelte si chiama «Da ripartire», con le spese e le entrate fra parentesi.');
    });

    test('il piano non si crea se prenderebbe una voce di spesa negativa, e il messaggio dice quale voce correggere', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Le versioni precedenti permettevano una voce di spesa con un importo negativo, che il calcolo delle quote addebita in positivo');
        expect(testo).toContain('se il piano ne prenderebbe una, con le voci scelte o con tutto il preventivo, non si crea, e il messaggio dice quale voce correggere nel piano dei conti.');
    });

    test('controprova — la guida dice ancora che scegliendo delle voci si può chiedere un importo parziale', () => {
        expect(testoDellaGuida()).toContain('e per ciascuna puoi chiedere un importo parziale invece dell\'intero.');
    });
});
