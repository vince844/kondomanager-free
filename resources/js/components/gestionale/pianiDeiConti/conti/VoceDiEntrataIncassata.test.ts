// @vitest-environment jsdom

/**
 * La voce di entrata con un incasso da terzi nel piano dei conti: la scheda della voce e l'albero.
 *
 * Il server manda per una voce di entrata `speso_raw` zero e `incassato_raw` positivo (l'affitto dell'antenna, € 240,00): la voce dice
 * «Incassato € 240,00», mai uno speso di −€ 240,00, non è uno sforo e non ha un «residuo» (il programma non ha un credito verso chi deve
 * ancora versare). Una voce di spesa resta com'era: «Consuntivo», il residuo, lo sforo.
 *
 * Il lato del server è in `tests/Feature/Gestionale/VoceDiEntrataIncassataTest.php`.
 */

import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';

const DettaglioConto = (await import('./DettaglioConto.vue')).default;
const AlberoDeiConti = (await import('./AlberoDeiConti.vue')).default;

const antenna = {
    id: 8, nome: 'Affitto antenna', tipo: 'entrata', parent_id: null, is_capitolo: false, importo: '€ 240,00', importo_raw: 24000,
    budget_originale_raw: 24000, speso_raw: 0, incassato_raw: 24000, impegnato: 0, percentuale_copertura: 0, tabelle_millesimali: [], sottoconti: [],
};

const valoriPerEtichetta = (wrapper: ReturnType<typeof mount>): Record<string, string> =>
    Object.fromEntries(
        wrapper.findAll('label').map((l) => [
            l.text().trim(),
            (l.element.parentElement?.querySelector('p')?.textContent ?? '').replace(/\u00a0/g, ' ').trim(),
        ]),
    );

describe('la scheda di una voce di entrata incassata', () => {
    const scheda = () => mount(DettaglioConto, {
        props: { conto: antenna, condominioId: 1, esercizioId: 1, gestioneId: 1 },
        global: { stubs: { Link: { template: '<a><slot /></a>' } } },
    });

    test('dice «Incassato € 240,00», non un consuntivo negativo', () => {
        const valori = valoriPerEtichetta(scheda());

        expect(valori['Incassato']).toBe('€ 240,00');
        expect(valori).not.toHaveProperty('Consuntivo');
        expect(Object.values(valori).join(' ')).not.toContain('-€');
    });

    test('non ha un residuo e non un\'eccedenza', () => {
        const valori = valoriPerEtichetta(scheda());

        expect(valori).not.toHaveProperty('Residuo');
        expect(valori).not.toHaveProperty('Eccedenza');
    });
});

describe('l\'albero con una voce di entrata incassata', () => {
    test('la colonna del consuntivo mostra l\'incassato, € 240,00, senza il triangolo dello sforo', () => {
        const albero = mount(AlberoDeiConti, {
            props: { conti: [antenna], selectedId: null },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });
        // Il pulsante della colonna del consuntivo (apre i movimenti): la colonna del preventivo, a fianco, dice anche lei € 240,00.
        const colonna = albero.find('button.w-24');

        expect(colonna.exists()).toBe(true);
        expect(colonna.text().replace(/\u00a0/g, ' ')).toBe('€ 240,00');
        expect(albero.find('.text-red-600').exists()).toBe(false);
    });

    test('il suggerimento della colonna dice «Incassato € 240,00»', () => {
        const albero = mount(AlberoDeiConti, {
            props: { conti: [antenna], selectedId: null },
            global: { stubs: { Link: { template: '<a><slot /></a>' }, TooltipContent: { template: '<div class="suggerimento"><slot /></div>' } } },
        });

        expect(albero.find('[data-testo="incassato"]').text().replace(/\u00a0/g, ' ')).toBe('Incassato € 240,00');
    });

    test('controprova: una voce di entrata con i dati di prima (una fattura da € 500,00) si legge come prima, con lo speso', () => {
        const albero = mount(AlberoDeiConti, {
            props: { conti: [{ ...antenna, speso_raw: 50000, incassato_raw: 0 }], selectedId: null },
            global: { stubs: { Link: { template: '<a><slot /></a>' } } },
        });

        expect(albero.find('button.w-24').text().replace(/\u00a0/g, ' ')).toBe('€ 500,00');
        expect(albero.find('[data-testo="incassato"]').exists()).toBe(false);
    });
});
