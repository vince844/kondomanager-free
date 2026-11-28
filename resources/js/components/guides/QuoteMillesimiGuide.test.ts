// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La voce «Incolla da un foglio» della guida a cassetto della pagina delle quote.
 *
 * La finestra chiede due cose invece di indovinarle e non salva: sono le tre cose che chi incolla
 * deve sapere prima, e la guida le dice con un caso. Se una sparisce dalla guida, questa prova lo
 * dice.
 */

import { afterEach, describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import QuoteMillesimiGuide from './QuoteMillesimiGuide.vue';

afterEach(() => {
    document.body.innerHTML = '';
});

describe('la guida della pagina delle quote', () => {
    test('ha la voce «Incolla da un foglio», con un caso e le tre cose da sapere', async () => {
        mount(QuoteMillesimiGuide, { props: { open: true }, attachTo: document.body });
        await nextTick();

        const voce = document.body.querySelector('[data-guida="incolla-da-un-foglio"]');
        expect(voce).not.toBeNull();
        const testo = voce!.textContent!.replace(/\s+/g, ' ');

        expect(testo).toContain('Incolla da un foglio');
        expect(testo).toContain('Un caso');
        expect(testo).toContain('quale è quella di questa tabella');
        expect(testo).toContain('le due letture');
        expect(testo).toContain('«Applica» non salva');
        expect(testo).toContain('Salva quote');
    });
});
