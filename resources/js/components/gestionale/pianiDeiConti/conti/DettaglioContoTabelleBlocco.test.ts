// @vitest-environment jsdom

/**
 * Il pannello di dettaglio di una voce e il blocco dei piani approvati sulle tabelle.
 *
 * Una voce che un piano approvato tiene, con la sua riga o con quella di un capitolo sopra di lei, o che un piano straordinario approvato finanzia con una
 * spesa fuori preventivo registrata sulla voce, non cambia tabella né percentuali (`AssociaTabellaController`, `AggiornaTabellaController`, `DissociaTabellaController`,
 * `ContoController::update`, `Conto::pianiCheBloccanoLaVoce`, `Beta53BloccoDellaVoceTabelleTest`, `Beta53BloccoDellaVocePianoStraordinarioTest`). Il pannello
 * segue la stessa regola: il server manda `blocco`, e con la voce bloccata «Aggiungi», «Modifica» e «Rimuovi» sono spenti, e il riquadro dice la frase del
 * server, con la strada della bozza. `has_rate_emesse` non decide qui, come nella scheda della voce: conta anche le righe ordinarie di una fattura che un piano
 * straordinario finanzia, che il server non blocca.
 *
 * Cosa resta scoperto: il server (i test Pest citati); il tema scuro.
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

const DettaglioConto = (await import('./DettaglioConto.vue')).default;

const FRASE = 'L\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano finché il suo capitolo «Pulizie» è nel piano «Preventivo B», approvato: '
    + 'riporta prima il piano in bozza dalla sua pagina, poi modifica la voce. Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: '
    + 'annotali prima; con delle rate emesse, prima vanno annullate le emissioni.';

const voce: Record<string, unknown> = {
    id: 7, nome: 'Scale', tipo: 'spesa', parent_id: 3, is_capitolo: false, importo: '€ 500,00', importo_raw: 50000, budget_originale_raw: 50000, speso_raw: 0, impegnato: 0,
    percentuale_copertura: 0, sottoconti: [],
    tabelle_millesimali: [{ id: 1, conto_id: 7, tabella_id: 4, coefficiente: 60, tabella: { id: 4, nome: 'Proprietà' }, ripartizioni: [{ id: 1, soggetto: 'proprietario', percentuale: 100 }] }],
};

const monta = (conto: Record<string, unknown>) => mount(DettaglioConto, {
    props: { conto: conto as never, condominioId: 1, esercizioId: 1, gestioneId: 1 },
    global: { stubs: { Link: { template: '<a><slot /></a>' } } },
});

const bottoneAggiungi = (w: ReturnType<typeof monta>) => w.findAll('button').find((b) => b.text().includes('Aggiungi'))!;
const bottoniDellaRiga = (w: ReturnType<typeof monta>) => w.findAll('tbody button');
const testo = (w: ReturnType<typeof monta>) => w.text().replace(/\s+/g, ' ');

describe('le tabelle della voce che un piano approvato tiene', () => {
    test('«Aggiungi», «Modifica» e «Rimuovi» sono spenti, e il riquadro dice la frase del server', () => {
        const w = monta({ ...voce, blocco: { piani: [{ id: 9, nome: 'Preventivo B' }], frase: FRASE } });

        expect(bottoneAggiungi(w).attributes('disabled')).toBeDefined();
        expect(bottoniDellaRiga(w).length).toBe(2);
        expect(bottoniDellaRiga(w).every((b) => b.attributes('disabled') !== undefined)).toBe(true);
        expect(testo(w)).toContain(`Ripartizione bloccata. ${FRASE}`);
    });

    test('un sottoconto nato dopo il piano che tiene il suo capitolo: il blocco viene dal server anche senza `has_rate_emesse`', () => {
        const w = monta({ ...voce, has_rate_emesse: false, blocco: { piani: [{ id: 9, nome: 'Preventivo B' }], frase: FRASE } });

        expect(bottoniDellaRiga(w).every((b) => b.attributes('disabled') !== undefined)).toBe(true);
    });
});

describe('le tabelle della voce che un piano straordinario approvato finanzia', () => {
    const FRASE_STRAORDINARIO = 'L\'importo, il padre, il tipo, la tabella e le percentuali della voce non si cambiano finché la voce è nel piano «Facciata 2026», approvato: '
        + 'riporta prima il piano in bozza dalla sua pagina, poi modifica la voce. Riportandolo in bozza si azzerano il numero del verbale e la nota dell\'approvazione: '
        + 'annotali prima; con delle rate emesse, prima vanno annullate le emissioni.';

    test('«Aggiungi», «Modifica» e «Rimuovi» sono spenti, e il riquadro dice la frase del server', () => {
        const w = monta({ ...voce, has_rate_emesse: true, blocco: { piani: [{ id: 11, nome: 'Facciata 2026' }], frase: FRASE_STRAORDINARIO } });

        expect(bottoneAggiungi(w).attributes('disabled')).toBeDefined();
        expect(bottoniDellaRiga(w).length).toBe(2);
        expect(bottoniDellaRiga(w).every((b) => b.attributes('disabled') !== undefined)).toBe(true);
        expect(testo(w)).toContain(`Ripartizione bloccata. ${FRASE_STRAORDINARIO}`);
    });

    test('il segnale `has_rate_emesse` da solo non spegne i pulsanti: decide `blocco`, come nel salvataggio', () => {
        // La voce ordinaria di una fattura mista: `has_rate_emesse` conta ogni riga della fattura, il motore straordinario e il server solo quelle di
        // sopravvenienza. Il pannello che si spegnesse qui direbbe «Ripartizione bloccata» senza una frase, su una modifica che il server accetta.
        const w = monta({ ...voce, has_rate_emesse: true, blocco: null });

        expect(bottoneAggiungi(w).attributes('disabled')).toBeUndefined();
        expect(bottoniDellaRiga(w).every((b) => b.attributes('disabled') === undefined)).toBe(true);
        expect(testo(w)).not.toContain('Ripartizione bloccata');
    });
});

describe('le tabelle della voce che nessun piano approvato tiene', () => {
    test('le tabelle si modificano', () => {
        const w = monta({ ...voce, has_rate_emesse: false, blocco: null });

        expect(bottoneAggiungi(w).attributes('disabled')).toBeUndefined();
        expect(bottoniDellaRiga(w).every((b) => b.attributes('disabled') === undefined)).toBe(true);
        expect(testo(w)).not.toContain('Ripartizione bloccata');
    });
});
