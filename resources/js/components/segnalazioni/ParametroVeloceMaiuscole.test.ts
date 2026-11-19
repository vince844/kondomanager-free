// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * 1.12.0-beta.12 (H3): nel menu veloce dello stato della scheda della segnalazione «In lavorazione» si legge con la l minuscola. La classe `capitalize`
 * (ogni parola con la maiuscola) scriveva «In Lavorazione»: le etichette hanno già la maiuscola all'inizio, nelle quattro lingue, e la classe non c'è più.
 *
 * Che cosa non copre: l'aspetto (jsdom non applica le regole dei caratteri: si prova la classe); la scheda del condòmino, `SegnalazioniView.vue` del portale,
 * corretta allo stesso modo e guardata a video.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { CircleCheck, CircleX, History } from 'lucide-vue-next';

vi.mock('laravel-vue-i18n', () => ({ trans: (chiave: string) => chiave }));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const ParametroVeloce = (await import('./ParametroVeloce.vue')).default;

const OPZIONI = [
    { value: 'aperta', label: 'segnalazioni.status.open', icon: CircleCheck, colorClass: 'text-green-500' },
    { value: 'in lavorazione', label: 'segnalazioni.status.in_progress', icon: History, colorClass: 'text-yellow-500' },
    { value: 'chiusa', label: 'segnalazioni.status.closed', icon: CircleX, colorClass: 'text-gray-500' },
];

const OPZIONI_CON = OPZIONI;

describe('le etichette del menu veloce', () => {
    test('né il valore né le voci hanno la maiuscola su ogni parola', async () => {
        const w = mount(ParametroVeloce, { attachTo: document.body,
            props: { campo: 'stato', opzioni: OPZIONI_CON, valore: 'in lavorazione', comando: 'Cambia lo stato lavorazione', vuoto: 'Nessuno', puoModificare: true } });
        const valore = w.get('[data-parametro-veloce="stato"]');
        // Con il codice di prima: `capitalize`, «In Lavorazione».
        expect(valore.html()).not.toContain('capitalize');
        await valore.trigger('keydown', { key: 'Enter' });
        await flushPromises();
        expect(document.body.querySelector('[data-voce="in lavorazione"]')!.innerHTML).not.toContain('capitalize');
        w.unmount();
        const sola = mount(ParametroVeloce, { attachTo: document.body,
            props: { campo: 'stato', opzioni: OPZIONI_CON, valore: 'in lavorazione', comando: 'Cambia lo stato lavorazione', vuoto: 'Nessuno', puoModificare: false } });
        expect(sola.html()).not.toContain('capitalize');
        sola.unmount();
    });
});
