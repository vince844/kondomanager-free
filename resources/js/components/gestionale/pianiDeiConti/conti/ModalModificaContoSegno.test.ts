// @vitest-environment jsdom

/**
 * Il modulo «Modifica voce» e il segno dell'importo.
 *
 * Il server rifiuta un importo cambiato in negativo (`UpdateContoRequest`, `Beta52VoceEntrataImportoNegativoTest`) e lascia passare una voce già negativa
 * il cui importo non cambia: il modulo rimanda sempre l'importo, quindi una voce negativa si deve aprire con il suo segno (se il campo lo togliesse, al
 * salvataggio la voce cambierebbe segno in silenzio). Il campo di modifica non vieta il meno (`disableNegative` sta solo nel modulo nuovo): il rifiuto è
 * del server, compare sotto il campo, e il campo resta scrivibile; «Entrata» resta scegliibile, anche quando l'importo è bloccato da rate emesse.
 *
 * Il modulo di modifica non ha la riga che dice di scrivere l'importo senza il segno: quella sta nel modulo nuovo, dove il campo toglie il meno in silenzio. Qui il meno
 * non si toglie e il server rifiuta con il testo giusto sotto il campo, quindi una seconda spiegazione sarebbe una ripetizione.
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 *
 * Cosa resta scoperto: il server; il modulo nuovo (`ModalNuovoContoSegno.test.ts`); la conferma di conversione in capitolo.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: (dati: Record<string, unknown>) => reactive({ ...dati, errors: {}, processing: false, clearErrors: vi.fn(), reset: vi.fn(), put: vi.fn(), post: vi.fn() }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
vi.mock('vue-select', () => ({ default: { name: 'VSelectFinto', template: '<div />' } }));
vi.mock('@/composables/useCapitoliConti', () => ({
    useCapitoliConti: () => ({ capitoli: { value: [] }, isLoading: { value: false }, fetchCapitoliConti: vi.fn(), reset: vi.fn() }),
}));

const ModalModificaConto = (await import('@/components/gestionale/pianiDeiConti/conti/ModalModificaConto.vue')).default;

const voceNegativa = {
    id: 7, nome: 'Rimborso', tipo: 'spesa', parent_id: null, is_capitolo: false,
    importo: '€ -240,00', importo_raw: -24000, budget_originale_raw: -24000, speso_raw: 0, tabelle_millesimali: [], sottoconti: [],
};

let finestra: VueWrapper | null = null;

async function monta(conto: Record<string, unknown>) {
    finestra = mount(ModalModificaConto, {
        attachTo: document.body,
        props: { show: true, conto: conto as never, condominioId: 1, esercizioId: 1, pianoContoId: 1, tabelle: [], fornitori: [] },
    });
    await flushPromises();
    await new Promise((r) => setTimeout(r, 0));
}

const campo = () => document.querySelector('input#importo') as HTMLInputElement;
const modulo = () => (finestra!.vm as any).form;

beforeEach(() => {
    document.body.innerHTML = '';
});

afterEach(() => {
    finestra?.unmount();
    finestra = null;
    document.body.innerHTML = '';
});

describe('una voce già negativa', () => {
    test('controprova — si apre con il suo segno: il campo non lo capovolge', async () => {
        await monta(voceNegativa);

        expect(campo().value).toBe('-240,00');
    });

    test('controprova — «Entrata» si sceglie e l\'importo resta quello che il server ha, anche con l\'importo bloccato da rate emesse', async () => {
        await monta({ ...voceNegativa, has_rate_emesse: true });

        (document.querySelector('input#entrata') as HTMLInputElement).click();
        await flushPromises();

        expect(modulo().tipo).toBe('entrata');
        expect(campo().disabled).toBe(true);
        expect(campo().value).toBe('-240,00');
    });
});

describe('il rifiuto del server', () => {
    test('controprova — l\'errore sta sotto il campo e il campo si può riscrivere senza il segno', async () => {
        await monta({ ...voceNegativa, importo: '€ 1.200,00', importo_raw: 120000, budget_originale_raw: 120000 });
        modulo().errors = { importo: 'Una voce di spesa non può avere un importo negativo. Se è un provento, per esempio l\'affitto di un\'antenna, scegli «Entrata» e scrivi l\'importo senza il segno.' };
        await flushPromises();

        expect(document.body.textContent).toContain('Una voce di spesa non può avere un importo negativo.');
        expect(campo().disabled).toBe(false);

        campo().value = '300,00';
        campo().dispatchEvent(new Event('input', { bubbles: true }));
        await flushPromises();

        expect(campo().value).toBe('300,00');
    });
});

describe('il modulo di modifica non ha la riga del segno', () => {
    test('controprova — sotto il campo non c\'è la spiegazione del segno, né il suo testo né la sua chiave', async () => {
        await monta({ ...voceNegativa, importo: '€ 1.200,00', importo_raw: 120000, budget_originale_raw: 120000 });

        expect(document.body.textContent).not.toContain('Si scrive senza il segno');
        expect(document.body.textContent).not.toContain('amount_sign_hint');
    });
});
