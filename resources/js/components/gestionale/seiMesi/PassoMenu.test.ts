// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il pulsante della prossima azione di una persona nel riquadro dei sei mesi: con «Il programma suggerisce la prossima azione» un pulsante diviso, a sinistra
 * l'azione suggerita (piena), a destra la freccia che apre il menu: in testa l'azione con «suggerita», una linea, le altre possibili. Con «Scelgo io la prossima
 * azione», o senza un'azione, «Scegli l'azione» con il bordo e lo stesso menu, senza la testa. Tutti e due larghi quanto la colonna. È un menu di azioni, non una
 * scelta da un elenco: niente vSelect, niente `<select>`.
 */
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const PassoMenu = (await import('./PassoMenu.vue')).default;
const { NOMI_DEI_PASSI } = await import('@/lib/gestionale/debiti/seiMesiPerAgire');

const slot = { template: '<div><slot /></div>' };
const MENU = { DropdownMenu: slot, DropdownMenuTrigger: slot, DropdownMenuContent: slot, DropdownMenuItem: slot, DropdownMenuLabel: slot, DropdownMenuSeparator: slot };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

async function monta(passo: string | null, proposto = true, possibili = ['sollecito', 'diffida', 'fascicolo', 'agito']) {
    w = mount(PassoMenu, { attachTo: document.body, props: { passo: { caso: 'R11', passo, frase: '', note: [], possibili, rata_compensa: null, termine: null }, proposto,
        disponibili: ['sollecito', 'diffida', 'spedizione', 'fascicolo', 'accordo', 'compensa', 'agito'] }, global: { stubs: MENU } });
    await flushPromises();

    return w;
}
/** Il menu si monta aperto, come nelle prove delle azioni delle righe: le sue parti diventano semplici contenitori. */
async function apriMenu() {
    expect(document.body.querySelector('[data-testid="freccia-passi"]')).not.toBeNull();
    await flushPromises();
}

describe('i nomi dei passi', () => {
    test('come sui pulsanti', () => {
        expect(NOMI_DEI_PASSI).toEqual({
            sollecito: 'Manda un sollecito', diffida: 'Manda la diffida', spedizione: 'Registra la spedizione', fascicolo: 'Prepara il fascicolo per il legale',
            accordo: 'Vedi l\'accordo', compensa: 'Compensa', agito: 'Registra che hai agito',
        });
    });
});

describe('il pulsante diviso', () => {
    test('il passo proposto pieno, la freccia, e nel menu «proposto» in testa e gli altri sotto', async () => {
        const m = await monta('diffida');
        expect(document.body.querySelector('[data-testid="passo-proposto"]')!.textContent?.trim()).toBe('Manda la diffida');
        await apriMenu();
        expect(testo()).toContain('suggerita');
        expect(testo()).toContain('Manda un sollecito');
        expect(testo()).toContain('Registra che hai agito');
        // Il fascicolo ha la sua finestra: è fra le azioni che si aprono.
        expect(testo()).toContain('Prepara il fascicolo per il legale');
        (document.body.querySelector('[data-testid="passo-proposto"]') as HTMLElement).click();
        expect(m.emitted('scegli')![0]).toEqual(['diffida']);
    });

    test('«Scelgo io il passo»: «Scegli il passo» con il bordo, nessun passo segnato, gli stessi passi', async () => {
        await monta('diffida', false);
        expect(document.body.querySelector('[data-testid="passo-proposto"]')).toBeNull();
        expect(document.body.querySelector('[data-testid="scegli-passo"]')!.textContent).toContain('Scegli l\'azione');
        await apriMenu();
        expect(testo()).not.toContain('suggerita');
        expect(testo()).toContain('Manda la diffida');
        expect(testo()).toContain('Manda un sollecito');
    });

    test('senza un passo (l\'accordo in corso, il termine da aspettare) il menu dei possibili', async () => {
        await monta(null, true, ['sollecito', 'diffida', 'fascicolo', 'accordo', 'agito']);
        expect(document.body.querySelector('[data-testid="passo-proposto"]')).toBeNull();
        await apriMenu();
        expect(testo()).toContain('Vedi l\'accordo');
    });

    test('«Scegli il passo» apre i passi, senza un\'intestazione in più', async () => {
        await monta(null);
        expect(testo()).not.toContain('I passi possibili');
    });

    test('il pulsante diviso e «Scegli l\'azione» sono larghi quanto la colonna, a ogni larghezza; la freccia dice «Le altre azioni»', async () => {
        await monta('diffida');
        expect((document.body.querySelector('[data-testid="passo-menu"]') as HTMLElement).className).toContain('w-full');
        expect((document.body.querySelector('[data-testid="passo-menu"]') as HTMLElement).className).not.toContain('sm:w-auto');
        expect(document.body.querySelector('[data-testid="freccia-passi"]')!.getAttribute('aria-label')).toBe('Le altre azioni');
        w!.unmount();
        document.body.innerHTML = '';
        await monta('diffida', false);
        expect((document.body.querySelector('[data-testid="scegli-passo"]') as HTMLElement).className).toContain('w-full');
        expect((document.body.querySelector('[data-testid="scegli-passo"]') as HTMLElement).className).not.toContain('sm:w-auto');
    });

    test('nessun menu a tendina del browser', async () => {
        await monta('diffida');
        expect(document.body.querySelector('select')).toBeNull();
    });
});
