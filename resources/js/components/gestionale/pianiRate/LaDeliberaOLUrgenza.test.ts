// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Coda 688, «La delibera, o l'urgenza» nel nuovo piano di una gestione straordinaria (la proposta 4 e la scena 13 della bozza, con un giorno solo): un menu con
 * due scelte, **uguale sulle due schede**. Con la delibera, lo spazio di «Scegli la delibera»; con l'urgenza il motivo e «Lavori ordinati il» con la frase
 * «Chi era titolare il … paga tutto»; sulle fatture la fattura senza periodo prende il giorno, quella con il suo periodo tiene il suo. Il piano nasce approvato e si riferisce alla prima
 * assemblea. Gli errori sotto il campo; niente scritte sotto i 12 pixel.
 *
 * Che cosa non copre: il server (`UrgenzaSulleVociTest`); il calendario del giorno (`CampoGiorno`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], emits: ['update:modelValue'],
    template: '<button type="button" class="v-select-finto" :data-valore="modelValue" @click="$emit(\'update:modelValue\', \'urgenza\')">{{ options.map(o => o.nome).join(\' | \') }}</button>' } }));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Riquadro = (await import('./LaDeliberaOLUrgenza.vue')).default;
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const monta = async (props: Record<string, unknown>) => {
    w = mount(Riquadro, { attachTo: document.body, props: { tipo: 'delibera', motivo: '', giorno: '2026-03-02', scheda: 'voci', condominioNome: 'Via Roma 12', ...props },
        slots: { delibera: '<div data-testid="scegli-la-delibera">Scegli la delibera</div>' } });
    await flushPromises();
};
const q = (sel: string) => document.body.querySelector<HTMLElement>(sel);

describe('«La delibera, o l\'urgenza»', () => {
    test('il menu ha le due scelte; con la delibera lo spazio di «Scegli la delibera»; scegliere «Urgenza» lo dice alla pagina', async () => {
        await monta({});
        expect(q('[data-testid="scelta-autorizzazione"]')!.textContent).toBe('Delibera dell\'assemblea | Urgenza (art. 1135 c.c.)');
        expect(q('[data-testid="scegli-la-delibera"]')).not.toBeNull();
        expect(q('[data-testid="motivo-urgenza"]')).toBeNull();
        q('[data-testid="scelta-autorizzazione"]')!.click();
        await flushPromises();
        expect(w!.emitted('update:tipo')![0]).toEqual(['urgenza']);
    });

    test('sulle voci, con l\'urgenza: il motivo, «Lavori ordinati il» con la frase di chi paga, il piano che nasce approvato, la prima assemblea', async () => {
        await monta({ tipo: 'urgenza', motivo: 'Rottura della colonna', errori: { motivo: 'Scrivi il motivo dell\'urgenza.' } });
        const t = (q('[data-testid="la-delibera-o-l-urgenza"]')!.textContent ?? '').replace(/\s+/g, ' ');
        expect(q('[data-testid="scegli-la-delibera"]')).toBeNull();
        expect((q('[data-testid="motivo-urgenza"]') as HTMLTextAreaElement).value).toBe('Rottura della colonna');
        expect(t).toContain('Lavori ordinati il');
        expect(q('[data-testid="frase-del-giorno"]')!.textContent).toBe('Chi era titolare il 02/03/2026 paga tutto: il giorno decide chi paga, non quanto.');
        expect(t).toContain('Scrivi il motivo dell\'urgenza.');
        expect(q('[data-testid="nasce-approvato"]')!.textContent).toBe('Il piano nasce approvato: l\'autorizzazione è l\'urgenza.');
        expect(t).toContain('Lo riferisci alla prima assemblea di Via Roma 12: il punto ti viene proposto quando la prepari.');
        expect(q('[data-testid="la-delibera-o-l-urgenza"]')!.innerHTML).not.toMatch(/text-\[(10|11)px\]/);
        // Al telefono i campi vanno uno sotto l'altro.
        expect(q('[data-testid="la-delibera-o-l-urgenza"] .grid')!.className).toContain('grid-cols-1');

        const motivo = q('[data-testid="motivo-urgenza"]') as HTMLTextAreaElement;
        motivo.value = 'Rottura della colonna montante';
        motivo.dispatchEvent(new Event('input'));
        expect(w!.emitted('update:motivo')!.at(-1)).toEqual(['Rottura della colonna montante']);
    });

    test('sulle fatture, con l\'urgenza: il motivo e il giorno, come sulle voci; la fattura senza periodo prende il giorno, e quante lo prendono', async () => {
        await monta({ tipo: 'urgenza', scheda: 'fatture', fattureSenzaPeriodo: 2 });
        const t = (q('[data-testid="la-delibera-o-l-urgenza"]')!.textContent ?? '').replace(/\s+/g, ' ');
        expect(q('[data-testid="giorno-dei-lavori"]')).not.toBeNull();
        expect(t).toContain('Lavori ordinati il');
        expect(q('[data-testid="frase-del-giorno"]')!.textContent).toBe('Chi era titolare il 02/03/2026 paga tutto: il giorno decide chi paga, non quanto.');
        expect(q('[data-testid="periodo-sulle-fatture"]')!.textContent!.replace(/\s+/g, ' ').trim()).toBe('La fattura senza periodo prende questo giorno; quella con il suo '
            + 'periodo («Costo maturato dal … al …») tiene il suo. 2 fatture scelte non dicono il periodo: prendono il 02/03/2026.');
        expect(t).not.toContain('scrivilo prima di generare');
    });
});
