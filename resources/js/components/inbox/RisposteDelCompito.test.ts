// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le tre risposte sui compiti dell'agenda delle due Inbox: il menu «⋯» (al posto dei due pulsanti quadrati di oggi) con «Fatto, con una nota», «Non serve, con
 * il motivo» (sul bonifico segnalato: «Non serve: rifiuta la segnalazione»), «Ricordamelo il…» e, se ci sono risposte, «Il registro»; e le finestre con le loro
 * frasi. «Fatto» non chiede la nota, e Invio conferma.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({ router: { post: (...a: unknown[]) => post(...a) }, usePage: () => ({ props: {} }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: unknown) => `/${nome}${p !== undefined ? `/${typeof p === 'object' ? JSON.stringify(p) : p}` : ''}`;

const Menu = (await import('./MenuDelCompito.vue')).default;
const Risposte = (await import('./RisposteDelCompito.vue')).default;
const { vociDelMenu } = await import('@/lib/inbox/risposteDelCompito');

const slot = { template: '<div><slot /></div>' };
const MENU = { DropdownMenu: slot, DropdownMenuTrigger: slot, DropdownMenuContent: slot, DropdownMenuItem: slot, DropdownMenuSeparator: slot };

const compito = { id: 12, type: 'controllo_incassi', title: 'Verifica incassi - Rata 3', promemoria_a_parte: false, risposta: null };
const bonifico = { id: 11, type: 'verifica_pagamento', title: 'Verifica pagamento di Mario', promemoria_a_parte: false, risposta: null };
const fattura = { id: 13, type: 'pagamento_fornitore', title: 'Pagare Edil Rossi', promemoria_a_parte: true, scadenza: '2026-06-30',
    risposta: { tornato: null, registro: [{ tipo: 'ricordamelo', quando: '20/05/2026 10:00', utente: 'Vincenzo', nota: '', dati: { giorno: '2026-06-15' } }] } };
const bonificoInVerifica = { ...bonifico, fatto_spento: 'Prima registra l\'incasso con «Registra l\'incasso», o rifiuta la segnalazione con «Non serve».' };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; post.mockReset(); });
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

describe('le voci del menu', () => {
    test('le tre risposte; sul bonifico «Non serve» rifiuta la segnalazione; «Il registro» solo con le risposte', () => {
        expect(vociDelMenu(compito as never).map((v) => v.etichetta)).toEqual(['Fatto, con una nota', 'Non serve, con il motivo', 'Ricordamelo il…']);
        expect(vociDelMenu(bonifico as never).map((v) => v.etichetta)).toEqual(['Fatto, con una nota', 'Non serve: rifiuta la segnalazione', 'Ricordamelo il…']);
        expect(vociDelMenu(fattura as never).map((v) => v.etichetta)).toEqual(['Fatto, con una nota', 'Non serve, con il motivo', 'Ricordamelo il…', 'Il registro']);
    });

    test('il compito di una segnalazione di guasto ha solo «Ricordamelo» (e «Il registro» con le risposte): si chiude con la segnalazione', () => {
        const segnalazione = { ...compito, type: 'segnalazione_guasto' };
        expect(vociDelMenu(segnalazione as never).map((v) => v.etichetta)).toEqual(['Ricordamelo il…']);
        expect(vociDelMenu({ ...fattura, type: 'segnalazione_guasto' } as never).map((v) => v.etichetta)).toEqual(['Ricordamelo il…', 'Il registro']);
    });

    test('sul bonifico ancora in verifica «Fatto» è spento, con la frase che dice che cosa fare', async () => {
        expect(vociDelMenu(bonificoInVerifica as never)[0]).toMatchObject({ tipo: 'fatto', spenta: bonificoInVerifica.fatto_spento });
        w = mount(Menu, { attachTo: document.body, props: { compito: bonificoInVerifica as never }, global: { stubs: { ...MENU, DropdownMenuItem: { props: ['disabled'],
            template: '<div :data-disabled="disabled ? \'si\' : null"><slot /></div>' } } } });
        const fatto = document.body.querySelector('[data-testid="voce-fatto"]') as HTMLElement;
        expect(fatto.getAttribute('data-disabled')).toBe('si');
        expect(fatto.textContent).toContain('Prima registra l\'incasso con «Registra l\'incasso», o rifiuta la segnalazione con «Non serve».');
        expect((document.body.querySelector('[data-testid="voce-non_serve"]') as HTMLElement).getAttribute('data-disabled')).toBeNull();
    });

    test('il pulsante «⋯» quadrato apre il menu e sceglie la risposta', async () => {
        w = mount(Menu, { attachTo: document.body, props: { compito: compito as never, grande: false }, global: { stubs: MENU } });
        const pulsante = document.body.querySelector('[data-testid="menu-del-compito"]') as HTMLElement;
        expect(pulsante.className).toContain('w-7');
        expect(pulsante.getAttribute('aria-label')).toBe('Le risposte al compito');
        (document.body.querySelector('[data-testid="voce-ricordamelo"]') as HTMLElement).dispatchEvent(new Event('select'));
        await flushPromises();
        expect(w.emitted('scegli')).toEqual([['ricordamelo']]);
    });
});

describe('le finestre', () => {
    async function apri(tipo: string, c: unknown) {
        w = mount(Risposte, { attachTo: document.body, props: { oggi: '2026-05-20' } });
        (w.vm as unknown as { apri: (t: string, c: unknown) => void }).apri(tipo, c);
        await flushPromises();
    }

    test('«Fatto»: il titolo, il compito in grigio, la nota facoltativa; Invio conferma senza la nota', async () => {
        await apri('fatto', compito);
        expect(testo()).toContain('Fatto');
        expect(testo()).toContain('Verifica incassi - Rata 3');
        expect(testo()).toContain('Una nota, se vuoi');
        (document.body.querySelector('textarea') as HTMLTextAreaElement).dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        await flushPromises();
        expect(post).toHaveBeenCalledWith('/admin.inbox.risposta/12', { tipo: 'fatto', nota: '' }, expect.anything());
    });

    test('«Fatto» del bonifico dice che l\'incasso si registra con «Registra l\'incasso»', async () => {
        await apri('fatto', bonifico);
        expect(testo()).toContain('Chiude il compito. L\'incasso si registra con «Registra l\'incasso».');
    });

    test('«Non serve»: il campo «Perché» e «Registra»; per il bonifico «Rifiuta la segnalazione», con la frase per il condòmino', async () => {
        await apri('non_serve', compito);
        expect(testo()).toContain('Non serve');
        expect(testo()).toContain('Perché');
        expect(document.body.querySelector('[data-testid="conferma"]')!.textContent).toContain('Registra');
        w!.unmount();
        document.body.innerHTML = '';
        await apri('non_serve', bonifico);
        expect(testo()).toContain('Rifiuta la segnalazione');
        expect(testo()).toContain('Il motivo lo legge il condòmino nel portale.');
        expect(document.body.querySelector('[data-testid="conferma"]')!.textContent).toContain('Rifiuta la segnalazione');
    });

    test('«Ricordamelo»: il giorno da domani, la nota, l\'agenda; per i tipi con la scadenza, che il compito resta al suo giorno', async () => {
        await apri('ricordamelo', compito);
        expect(testo()).toContain('Ricordamelo');
        expect(testo()).toContain('Il giorno va nell\'agenda.');
        expect(testo()).not.toContain('Il compito resta nell\'agenda al suo giorno');
        expect((document.body.querySelector('[data-testid="giorno"]') as HTMLInputElement).value).toBe('2026-05-21');
        expect((document.body.querySelector('[data-testid="giorno"]') as HTMLInputElement).min).toBe('2026-05-21');
        (document.body.querySelector('[data-testid="conferma"]') as HTMLButtonElement).click();
        expect(post).toHaveBeenCalledWith('/admin.inbox.risposta/12', { tipo: 'ricordamelo', giorno: '2026-05-21', nota: '' }, expect.anything());
        w!.unmount();
        document.body.innerHTML = '';
        await apri('ricordamelo', fattura);
        expect(testo()).toContain('Il compito resta nell\'agenda al suo giorno (la scadenza): il promemoria è un evento a parte.');
        expect(testo()).not.toContain('dopo la scadenza');
        const giorno = document.body.querySelector('[data-testid="giorno"]') as HTMLInputElement;
        giorno.value = '2026-07-02';
        giorno.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(testo()).toContain('Il giorno scelto viene dopo la scadenza del 30/06/2026: fino a quel giorno il compito non è nell\'Inbox.');
    });

    test('«Ricordamelo» sul commento: torna prima se ne arriva uno nuovo, e il promemoria è a parte', async () => {
        await apri('ricordamelo', { id: 14, type: 'commento', title: 'Nuovo commento — Ascensore', promemoria_a_parte: true, risposta: null });
        expect(testo()).toContain('Torna prima se arriva un commento nuovo. Il promemoria è un evento a parte, nell\'agenda.');
        expect(testo()).not.toContain('(la scadenza)');
    });

    test('«Il registro»: le risposte, la più recente prima', async () => {
        await apri('registro', fattura);
        expect(testo()).toContain('20/05/2026 10:00');
        expect(testo()).toContain('Vincenzo');
        expect(testo()).toContain('Ricordamelo il 15/06/2026');
    });
});
