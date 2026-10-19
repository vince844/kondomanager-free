// @vitest-environment jsdom

/**
 * La guida del piano dei conti (`OperazioniContiGuide.vue`) e il blocco dei piani approvati.
 *
 * Una voce che un piano rate approvato tiene, con la sua riga o con quella di un capitolo sopra di lei, non cambia importo, capitolo padre, tipo, tabella né percentuali
 * (`ContoController::update`, `Conto::pianiCheBloccanoLaVoce`, `Beta53BloccoDellaVoceTest`), e la scheda della voce li tiene fermi
 * (`ModalModificaContoBlocco.test.ts`). La guida lo dice nel riquadro del preventivo e in quello delle tabelle.
 *
 * Si monta la guida con i segnaposto del foglio e si legge il testo con gli spazi normalizzati; la guida è solo in italiano.
 *
 * Cosa resta scoperto: la traduzione; il tema scuro.
 */
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

import OperazioniContiGuide from './OperazioniContiGuide.vue';

function testoDellaGuida(): string {
    const w = mount(OperazioniContiGuide, {
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

describe('OperazioniContiGuide — il blocco dei piani approvati', () => {
    test('nel riquadro del preventivo: con la voce, o un capitolo sopra di lei, in un piano approvato, o con una spesa fuori preventivo sulla voce in un piano straordinario approvato, importo, capitolo padre, tipo, tabella e percentuali non si cambiano', () => {
        expect(testoDellaGuida()).toContain('Se la voce, o un capitolo sopra di lei, è in un piano rate approvato, o se un piano straordinario approvato finanzia una spesa fuori '
            + 'preventivo registrata sulla voce o su un capitolo sopra di lei, o la copertura di una spesa pregressa sulla voce, l\'importo, il capitolo padre, il tipo, la tabella e le percentuali non si cambiano finché il piano non torna in bozza dalla sua pagina; '
            + 'lo stesso vale per un capitolo che un piano approvato tiene: non torna voce, e sotto di lui non si aggiungono né si spostano voci. La scheda lo dice.');
    });

    test('nel riquadro delle tabelle: la strada è riportare il piano in bozza dalla sua pagina, non annullare il piano', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('Se la voce, o un capitolo sopra di lei, è in un piano rate approvato, o se un piano straordinario approvato finanzia una spesa fuori preventivo registrata '
            + 'sulla voce o su un capitolo sopra di lei, o la copertura di una spesa pregressa sulla voce, le tabelle e le percentuali non si possono più modificare: cambiarle renderebbe i riparti del piano diversi da quelli approvati. Per intervenire '
            + 'occorre prima riportare il piano in bozza dalla sua pagina; con delle rate emesse, prima vanno annullate le emissioni.');
        expect(testo).not.toContain('annullare il piano rate associato');
    });

    test('la voce al terzo livello si sposta sotto un capitolo di primo livello: la guida non propone di eliminarla, perché con delle righe di fattura non si elimina', () => {
        const testo = testoDellaGuida();

        expect(testo).toContain('è finita al terzo livello quando era ancora possibile');
        expect(testo).toContain('Spostala sotto un capitolo di primo livello. Per trovarle tutte:');
        expect(testo).not.toContain('oppure eliminala');
    });
});
