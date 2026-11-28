// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il modale dello sforo con la «Rata integrativa» su una voce con il già versato dice la cifra che chiederà il piano integrativo, senza lo sforo
 * barrato accanto, e dice che il piano base va ricalcolato.
 *
 * Il caso: «Spese generali» € 1.200,00, fattura € 6.100,00, € 300,00 di già versato. Lo sforo è € 4.900,00; il piano integrativo chiede € 4.659,02
 * (`IntegrativoDopoSforoTest.php`). Prima il modale barrava € 4.900,00 e diceva «chiederà solo il residuo netto — non l'intero sforo lordo» accanto
 * a € 5.800,00, una cifra che nessun piano chiede; e il piano base, che aveva tolto tutto il già versato, non si nominava.
 */
import { afterEach, describe, expect, test } from 'vitest';
import { enableAutoUnmount, mount } from '@vue/test-utils';

import ModalOverrideBudget from './ModalOverrideBudget.vue';

enableAutoUnmount(afterEach);
afterEach(() => { document.body.innerHTML = ''; });

async function apri() {
    const w = mount(ModalOverrideBudget, {
        attachTo: document.body,
        props: {
            show: true, sforoTotale: 490_000, hasSpesePrivate: false, fondiRiserva: [], isProcessing: false,
            vociInSforo: [{ id: 55, nome: 'Spese generali', sforoLordoCents: 490_000, giaVersatoCents: 30_000, residuoNettoStimatoCents: 465_902 }],
        },
    });
    const radio = document.body.querySelector<HTMLInputElement>('input[type="radio"][value="rata_integrativa"]')!;
    radio.checked = true;
    radio.dispatchEvent(new Event('change'));
    await w.vm.$nextTick();
    await new Promise((r) => setTimeout(r, 0));

    return w;
}
const testo = (sel: string) => (document.body.querySelector(sel)?.textContent ?? '').replace(/\s+/g, ' ').trim();

describe('il modale dello sforo e il piano integrativo', () => {
    test('dice la cifra che chiede il piano integrativo, € 4.659,02, senza lo sforo barrato', async () => {
        await apri();

        expect(testo('[data-testid="integrativo-gia-versato"]')).toBe('Questa voce ha un già versato: il piano integrativo lo toglie solo nella parte che chiede della voce, e chiede circa:');
        expect(document.body.textContent).toContain('4.659,02');
        expect(document.body.querySelector('.line-through')).toBeNull();
        expect(document.body.textContent).not.toContain('non l\'intero sforo');
    });

    test('dice che il piano base va ricalcolato', async () => {
        await apri();

        expect(testo('[data-testid="integrativo-ricalcola-base"]')).toBe('Il piano base va ricalcolato: ha tolto il già versato sulla voce di prima, e senza il ricalcolo una parte della spesa non la chiede nessun piano.');
    });
});
