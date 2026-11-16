// @vitest-environment jsdom

/**
 * Il pulsante che chiude le finestre e i cassetti ha l'etichetta per i lettori di schermo in italiano: «Chiudi». Il gestionale è solo in italiano.
 * Si aprono le tre forme (la finestra, la finestra che scorre, il cassetto) e si legge l'etichetta nascosta del pulsante.
 */
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { Dialog, DialogContent, DialogScrollContent, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Sheet, SheetContent, SheetTitle, SheetDescription } from '@/components/ui/sheet';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

const etichette = () => [...document.body.querySelectorAll('.sr-only')].map((e) => (e.textContent ?? '').trim());

describe('«Chiudi», non «Close»', () => {
    test.each([
        ['la finestra', { components: { Dialog, DialogContent, DialogTitle, DialogDescription }, template: '<Dialog open><DialogContent><DialogTitle>T</DialogTitle><DialogDescription>D</DialogDescription></DialogContent></Dialog>' }],
        ['la finestra che scorre', { components: { Dialog, DialogScrollContent, DialogTitle, DialogDescription }, template: '<Dialog open><DialogScrollContent><DialogTitle>T</DialogTitle><DialogDescription>D</DialogDescription></DialogScrollContent></Dialog>' }],
        ['il cassetto', { components: { Sheet, SheetContent, SheetTitle, SheetDescription }, template: '<Sheet open><SheetContent><SheetTitle>T</SheetTitle><SheetDescription>D</SheetDescription></SheetContent></Sheet>' }],
    ])('%s', async (_nome, componente) => {
        w = mount(componente, { attachTo: document.body });
        await flushPromises();
        expect(etichette()).toContain('Chiudi');
        expect(etichette()).not.toContain('Close');
    });
});
