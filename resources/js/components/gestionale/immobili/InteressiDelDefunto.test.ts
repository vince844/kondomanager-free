// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Decisione 483 (Coda 663): nella successione, sotto la scelta dell'arretrato, la card «Gli interessi del defunto dopo il decesso». Due voci, «si fermano al
 * decesso» e «continuano a suo nome»; di serie la prima con l'arretrato agli eredi e la seconda con l'arretrato a nome del defunto; finché l'amministratore
 * non tocca il menu (valore `null`) la card segue la strada, toccata resta; la «i» dice il caso della bozza; la frase la scrive il server.
 *
 * Cosa NON copre: la pagina che monta la card e manda la scelta al server (`PassaggioNewInteressiDelDefunto.test.ts`); la frase stessa, che scrive il server
 * (`InteressiDelDefuntoDopoIlDecessoTest.php`).
 */
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import InteressiDelDefunto from './InteressiDelDefunto.vue';
import { interessiCheValgono, interessiDiSerie } from '@/lib/gestionale/passaggi/interessiDelDefunto';

const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

const scelta = () => pulito(document.body.querySelector('[data-testid="interessi-del-defunto"] .vs__selected')?.textContent);

describe('gli interessi del defunto dopo il decesso nella successione', () => {
    test('agli eredi, di serie «si fermano al decesso»; il menu ha le due voci, e la scelta parte; la «i» dice il caso; la frase del server', async () => {
        const frase = 'Dal 17/11/2026 la proposta degli interessi di Ettore Fabbri non conta altri giorni: il debito è passato a Elena e Marco, e gli interessi li propone la loro posizione, sulle loro rate.';
        w = mount(InteressiDelDefunto, { attachTo: document.body, props: { strada: 'eredi', frase, modelValue: null } });
        expect(pulito(document.body.querySelector('[data-testid="interessi-del-defunto"] p')?.textContent)).toBe('Gli interessi del defunto dopo il decesso');
        expect(scelta()).toBe('si fermano al decesso');
        const esempio = document.body.querySelector('[data-testid="info-interessi-del-defunto"]')?.getAttribute('aria-label') ?? '';
        expect(esempio).toContain('Per esempio:');
        expect(esempio).toContain('dal 01/05 al 16/11, 200 giorni, € 3,29');
        expect(esempio).toContain('fino al 31/12, 245 giorni, € 4,03');
        expect(pulito(document.body.querySelector('[data-testid="frase-interessi-del-defunto"]')?.textContent)).toBe(frase);

        const menu = document.body.querySelector('[data-testid="interessi-del-defunto"] .v-select') as HTMLElement;
        menu.querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
        await flushPromises();
        const voci = [...menu.querySelectorAll('.vs__dropdown-option')];
        expect(voci.map((o) => pulito(o.textContent))).toEqual(['si fermano al decesso', 'continuano a suo nome']);
        (voci[1] as HTMLElement).click();
        await flushPromises();
        expect(w.emitted('update:modelValue')?.[0]).toEqual(['continuano']);
    });

    test('a nome del defunto, di serie «continuano a suo nome»', () => {
        w = mount(InteressiDelDefunto, { attachTo: document.body, props: { strada: 'defunto', frase: null, modelValue: null } });
        expect(scelta()).toBe('continuano a suo nome');
        expect(document.body.querySelector('[data-testid="frase-interessi-del-defunto"]')).toBeNull();
    });

    test('cambiando la strada, la scelta non toccata segue il di serie della strada nuova; quella toccata resta', async () => {
        w = mount(InteressiDelDefunto, { attachTo: document.body, props: { strada: 'eredi', frase: null, modelValue: null } });
        expect(scelta()).toBe('si fermano al decesso');
        await w.setProps({ strada: 'defunto' });
        expect(scelta()).toBe('continuano a suo nome');
        await w.setProps({ strada: 'eredi' });
        expect(scelta()).toBe('si fermano al decesso');
        // Toccata: «continuano a suo nome» con l'arretrato agli eredi, poi la strada cambia due volte.
        await w.setProps({ modelValue: 'continuano' });
        expect(scelta()).toBe('continuano a suo nome');
        await w.setProps({ strada: 'defunto' });
        expect(scelta()).toBe('continuano a suo nome');
        await w.setProps({ modelValue: 'al_decesso' });
        await w.setProps({ strada: 'eredi' });
        expect(scelta()).toBe('si fermano al decesso');
        await w.setProps({ strada: 'defunto' });
        expect(scelta()).toBe('si fermano al decesso');
    });

    test('la regola del di serie, come quella del server', () => {
        expect(interessiDiSerie('eredi')).toBe('al_decesso');
        expect(interessiDiSerie('defunto')).toBe('continuano');
        expect(interessiCheValgono(null, 'eredi')).toBe('al_decesso');
        expect(interessiCheValgono(null, 'defunto')).toBe('continuano');
        expect(interessiCheValgono('continuano', 'eredi')).toBe('continuano');
        expect(interessiCheValgono('al_decesso', 'defunto')).toBe('al_decesso');
    });

    test('importa lo stile di vue-select da sé', async () => {
        const { readFileSync } = await import('node:fs');
        const { resolve } = await import('node:path');
        const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/components/gestionale/immobili/InteressiDelDefunto.vue'), 'utf8');
        expect(sorgente).toContain("import 'vue-select/dist/vue-select.css';");
    });
});
