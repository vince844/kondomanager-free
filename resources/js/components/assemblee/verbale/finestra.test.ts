// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il menu di una tendina dentro una finestra (rilievo C43): si apre fuori dal corpo che scorre, sopra tutto, sotto il campo o sopra se sotto non c'è
 * posto, e si può toccare anche con la finestra modale (che spegne i tocchi fuori da sé).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { menuFuoriDallaFinestra } from './finestra';

const campo = (rettangolo: Partial<DOMRect>) => {
    const el = document.createElement('div');
    el.getBoundingClientRect = () => ({ top: 0, bottom: 0, left: 0, right: 0, width: 200, height: 32, x: 0, y: 0, toJSON: () => ({}), ...rettangolo }) as DOMRect;
    el.style.fontSize = '14px';
    document.body.appendChild(el);
    return el;
};
const lista = (altezza: number) => {
    const ul = document.createElement('ul');
    Object.defineProperty(ul, 'scrollHeight', { value: altezza });
    document.body.appendChild(ul);
    return ul;
};

afterEach(() => { document.body.innerHTML = ''; vi.restoreAllMocks(); });

describe('il menu di una tendina dentro una finestra', () => {
    test('sotto il campo quando c\'è posto: fisso, sopra la finestra, largo come il campo, si tocca', () => {
        vi.spyOn(window, 'innerHeight', 'get').mockReturnValue(800);
        const ul = lista(120);
        menuFuoriDallaFinestra(ul, { $refs: { toggle: campo({ top: 300, bottom: 332, left: 40 }) } }, { width: '200px' });
        expect(ul.style.position).toBe('fixed');
        expect(ul.style.top).toBe('332px');
        expect(ul.style.left).toBe('40px');
        expect(ul.style.width).toBe('200px');
        expect(Number(ul.style.zIndex)).toBeGreaterThan(50);
        expect(ul.style.pointerEvents).toBe('auto');
        expect(ul.style.fontSize).toBe('14px');
    });

    test('sopra il campo quando sotto non c\'è posto, come «A chi» in fondo alla finestra del memo', () => {
        vi.spyOn(window, 'innerHeight', 'get').mockReturnValue(800);
        const ul = lista(160);
        menuFuoriDallaFinestra(ul, { $refs: { toggle: campo({ top: 700, bottom: 732, left: 40 }) } }, { width: '200px' });
        expect(ul.style.top).toBe('auto');
        expect(ul.style.bottom).toBe('100px');
    });

    test('segue il campo quando il corpo della finestra scorre, e smette quando il menu si chiude', () => {
        vi.spyOn(window, 'innerHeight', 'get').mockReturnValue(800);
        const ul = lista(80);
        const rettangolo = { top: 300, bottom: 332, left: 40 };
        const el = campo(rettangolo);
        const smetti = menuFuoriDallaFinestra(ul, { $refs: { toggle: el } }, { width: '200px' });
        el.getBoundingClientRect = () => ({ ...rettangolo, top: 250, bottom: 282, right: 240, width: 200, height: 32, x: 40, y: 250, toJSON: () => ({}) }) as DOMRect;
        window.dispatchEvent(new Event('scroll'));
        expect(ul.style.top).toBe('282px');
        smetti();
        el.getBoundingClientRect = () => ({ ...rettangolo, top: 100, bottom: 132, right: 240, width: 200, height: 32, x: 40, y: 100, toJSON: () => ({}) }) as DOMRect;
        window.dispatchEvent(new Event('scroll'));
        expect(ul.style.top).toBe('282px');
    });
});
