// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il valore della card dei parametri che si cambia con un clic (Coda 671), con il menu a tendina vero (reka-ui), non uno stub.
 *
 * - Dalla tastiera: Invio sul valore apre il menu (`aria-haspopup="menu"`, `aria-expanded`), e le voci sono `menuitemradio` con il valore di adesso
 *   segnato (`aria-checked`).
 * - Una voce scelta esce con `scegli`; il componente non salva da sé.
 * - Senza il permesso il valore è testo: niente pulsante e niente menu.
 *
 * Che cosa non copre: il tocco vero su un telefono e l'aspetto (si guardano a video), il salvataggio (lo prova la pagina,
 * `resources/js/pages/segnalazioni/SegnalazioniViewAzioniVeloci.test.ts`, e il Pest `tests/Feature/Segnalazioni/AzioniVelociSchedaTest.php`).
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

let w: VueWrapper | null = null;
function monta(puoModificare: boolean) {
    w = mount(ParametroVeloce, {
        attachTo: document.body,
        props: { campo: 'stato', opzioni: OPZIONI, valore: 'aperta', comando: 'Cambia lo stato lavorazione', vuoto: 'Nessuno', puoModificare },
    });
    return w;
}

afterEach(() => {
    w?.unmount();
    w = null;
    document.body.innerHTML = '';
});

describe('ParametroVeloce con il menu vero', () => {
    test('Invio apre il menu; le voci sono menuitemradio con quella di adesso segnata; una voce scelta esce con «scegli»', async () => {
        const p = monta(true);
        const valore = p.get('[data-parametro-veloce="stato"]');
        expect(valore.attributes('aria-label')).toBe('Cambia lo stato lavorazione: segnalazioni.status.open');

        await valore.trigger('keydown', { key: 'Enter' });
        await flushPromises();

        expect(valore.attributes('aria-haspopup')).toBe('menu');
        expect(valore.attributes('aria-expanded')).toBe('true');
        const voci = Array.from(document.body.querySelectorAll<HTMLElement>('[data-voce]'));
        expect(voci.map((v) => [v.dataset.voce, v.getAttribute('role'), v.getAttribute('aria-checked')])).toEqual([
            ['aperta', 'menuitemradio', 'true'],
            ['in lavorazione', 'menuitemradio', 'false'],
            ['chiusa', 'menuitemradio', 'false'],
        ]);

        voci[2].click();
        await flushPromises();

        expect(p.emitted('scegli')).toEqual([['chiusa']]);
    });

    test('senza il permesso il valore è testo, senza menu', () => {
        const p = monta(false);

        expect(p.find('[data-parametro-veloce]').exists()).toBe(false);
        expect(p.find('button').exists()).toBe(false);
        expect(p.get('[data-parametro="stato"]').text()).toContain('segnalazioni.status.open');
    });
});
