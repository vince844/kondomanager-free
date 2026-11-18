// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 454: nella successione con l'arretrato agli eredi, l'accordo di rientro del defunto che la successione chiude. Per gli interessi del defunto
 * l'accordo che reggeva resta in regola fino al decesso, di serie; l'amministratore può scegliere «vale come una decadenza». Il menu c'è solo se un accordo
 * reggeva; la frase la scrive il server; la «i» dice un esempio.
 */
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import AccordoDelDefunto from './AccordoDelDefunto.vue';

const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

describe('l\'accordo di rientro del defunto nella successione', () => {
    test('di serie «resta in regola fino al decesso»; il menu offre «vale come una decadenza»; la «i» e la frase del server', async () => {
        w = mount(AccordoDelDefunto, { attachTo: document.body, props: { accordi: [{ id: 7, data_accordo: '2026-07-15', in_regola: true }], defunto: 'Neri',
            frase: 'Per gli interessi l\'accordo di rientro di Neri del 15/07/2026 resta in regola fino al decesso.', modelValue: 'in_regola' } });
        const menu = document.body.querySelector('[data-testid="accordo-del-defunto"] .v-select') as HTMLElement;
        expect(pulito(menu.querySelector('.vs__selected')?.textContent)).toBe('resta in regola fino al decesso');
        expect(document.body.querySelector('[data-testid="info-accordo-del-defunto"]')?.getAttribute('aria-label')).toContain('Per esempio:');
        expect(pulito(document.body.querySelector('[data-testid="frase-accordo-del-defunto"]')?.textContent))
            .toBe('Per gli interessi l\'accordo di rientro di Neri del 15/07/2026 resta in regola fino al decesso.');
        menu.querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
        await flushPromises();
        const voci = [...menu.querySelectorAll('.vs__dropdown-option')];
        expect(voci.map((o) => pulito(o.textContent))).toEqual(['resta in regola fino al decesso', 'vale come una decadenza']);
        (voci[1] as HTMLElement).click();
        await flushPromises();
        expect(w.emitted('update:modelValue')?.[0]).toEqual(['decadenza']);
    });

    test('nessun accordo in regola: niente menu, solo la frase; senza accordi niente', () => {
        w = mount(AccordoDelDefunto, { attachTo: document.body, props: { accordi: [{ id: 7, data_accordo: '2026-07-15', in_regola: false }], defunto: 'Neri',
            frase: 'L\'accordo di rientro di Neri del 15/07/2026 non era in regola al decesso.', modelValue: 'in_regola' } });
        expect(document.body.querySelector('[data-testid="accordo-del-defunto"] .v-select')).toBeNull();
        expect(pulito(document.body.querySelector('[data-testid="frase-accordo-del-defunto"]')?.textContent)).toBe('L\'accordo di rientro di Neri del 15/07/2026 non era in regola al decesso.');
        w.unmount();
        w = mount(AccordoDelDefunto, { attachTo: document.body, props: { accordi: [], defunto: 'Neri', frase: null, modelValue: 'in_regola' } });
        expect(document.body.querySelector('[data-testid="accordo-del-defunto"]')).toBeNull();
    });

    test('importa lo stile di vue-select da sé', async () => {
        const { readFileSync } = await import('node:fs');
        const { resolve } = await import('node:path');
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/components/gestionale/immobili/AccordoDelDefunto.vue'), 'utf8');
        expect(sorgente).toContain("import 'vue-select/dist/vue-select.css';");
    });
});
