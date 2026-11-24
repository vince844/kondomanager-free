// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/** Il piè di pagina dell'area privata: una riga sola, la nota di licenza con la versione a sinistra e le due icone a destra. */
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

const pagina = vi.hoisted(() => ({ props: { version: '1.12.0-beta.13' } as Record<string, unknown> }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    usePage: () => pagina,
}));

import Footer from './Footer.vue';
import NotaDiLicenza from './NotaDiLicenza.vue';

const testo = (w: { element: Element }) => w.element.textContent!.replace(/\s*·\s*/g, ' · ').replace(/\s+/g, ' ').trim();

describe('Footer', () => {
    test('la nota compare una volta sola, con la versione, e la frase vecchia non c\'è più', () => {
        const f = mount(Footer);

        expect(f.findAll('[data-nota-di-licenza]')).toHaveLength(1);
        expect(testo(f.get('[data-nota-di-licenza]'))).toContain('© Vincenzo Vecchio — KondoManager v1.12.0-beta.13 · Licenza GNU AGPL-3.0 · Note legali · Codice sorgente');
        expect(f.text()).not.toContain('software open source per il condominio');
    });

    test('le due icone restano, Twitter e GitHub', () => {
        const f = mount(Footer);

        expect(f.find('a[href="https://x.com/kondomanager"]').exists()).toBe(true);
        expect(f.find('a[href="https://github.com/vince844/kondomanager-free"]').exists()).toBe(true);
    });

    test('senza la prop la nota non dice la versione (pagine d\'accesso)', () => {
        const n = mount(NotaDiLicenza);

        expect(testo(n.get('[data-nota-di-licenza]'))).toContain('© Vincenzo Vecchio — KondoManager · Licenza');
        expect(n.text()).not.toContain('v1.12.0');
    });
});
