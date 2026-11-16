// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il compito dei sei mesi nell'Inbox operativa e le tre finestre delle risposte: la riga come quelle dei compiti di oggi (la clessidra, il titolo, la
 * descrizione, il cartellino maiuscolo con il punto colorato), «Apri le persone →» e il menu «⋯» con «Fatto, con una nota», «Non serve, con il motivo»,
 * «Ricordamelo il…». Le finestre «Non serve» e «Ricordamelo» con le loro frasi; «Fatto» con le caselle (la sua prova è `SeiMesiFattoDialog.test.ts`): chi si
 * toglie resta nel riquadro, e la finestra lo dice.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: (...a: unknown[]) => post(...a) },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}`, hasPermission: () => true }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Compito = (await import('./SeiMesiCompito.vue')).default;
const Risposte = (await import('./SeiMesiRisposte.vue')).default;
const { titoloDelCompito, descrizioneDelCompito, cartellinoDelCompito } = await import('@/lib/gestionale/debiti/seiMesiPerAgire');

const compito = {
    chiave: 'sei-mesi-25', argomento: 'sei_mesi', esercizio: { id: 25, nome: 'Esercizio 2025' }, data: '2026-06-30', giorni: 41, colore: 'vicina', quante: 4, cifra: 324000,
    cartellini: { senza_lettera: 2, sollecito: 0, diffida_spedita: 1, diffida_da_spedire: 0, accordo: 0, dispensata: 0 }, tornato: null, ancora: 'sei-mesi-25',
    tutte: [
        { anagrafica_id: 1, nome: 'Rossi', cifra: 124000, situazione: 'senza_lettera' }, { anagrafica_id: 2, nome: 'Bianchi', cifra: 90000, situazione: 'diffida' },
        { anagrafica_id: 3, nome: 'Verdi', cifra: 64000, situazione: 'accordo' }, { anagrafica_id: 4, nome: 'Neri', cifra: 46000, situazione: 'senza_lettera' },
    ],
};

const slot = { template: '<div><slot /></div>' };
const MENU = { DropdownMenu: slot, DropdownMenuTrigger: slot, DropdownMenuContent: slot, DropdownMenuItem: slot, DropdownMenuLabel: slot, DropdownMenuSeparator: slot };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; post.mockReset(); });
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

describe('le frasi del compito', () => {
    test('il titolo, la descrizione, il cartellino', () => {
        expect(titoloDelCompito(compito as never)).toBe('Sei mesi per agire: esercizio 2025');
        expect(descrizioneDelCompito(compito as never)).toBe('Entro il 30/06/2026: 4 persone, € 3.240,00 di rate di questo esercizio non versate. 2 senza lettera, 1 con la diffida spedita.');
        expect([41, 0, -12, 1, -1].map((g) => cartellinoDelCompito(g))).toEqual(['FRA 41 GIORNI', 'OGGI', 'DATA PASSATA DA 12 GIORNI', 'DOMANI', 'DATA PASSATA IERI']);
        expect(titoloDelCompito({ argomento: 'rendiconto', esercizio: { id: 25, nome: 'Esercizio 2025' }, data: '2026-06-29' } as never))
            .toBe('Rendiconto 2025: convocare l\'assemblea entro il 29/06/2026');
        expect(descrizioneDelCompito({ argomento: 'rendiconto', esercizio: { id: 25, nome: 'Esercizio 2025' }, data: '2026-06-29', frase: 'Bozza: le cifre cambiano a ogni registrazione.' } as never))
            .toBe('Il rendiconto è ancora una bozza: le cifre cambiano a ogni registrazione.');
    });
});

describe('la riga del compito', () => {
    test('la clessidra, il titolo, il cartellino, «Apri le persone →» e il menu delle tre risposte', async () => {
        w = mount(Compito, { attachTo: document.body, props: { compito: compito as never, puoRispondere: true }, global: { stubs: MENU } });
        await flushPromises();
        expect(testo()).toContain('Sei mesi per agire: esercizio 2025');
        expect(testo()).toContain('FRA 41 GIORNI');
        expect(testo()).toContain('Apri le persone');
        expect(document.body.querySelector('[data-testid="apri-persone"]')!.getAttribute('href')).toBe('#sei-mesi-25');
        // La freccia è l'icona, come in «Risolvi» dei compiti di oggi.
        expect(document.body.querySelector('[data-testid="apri-persone"] svg')).not.toBeNull();
        expect(document.body.querySelector('[data-testid="apri-persone"]')!.textContent).not.toContain('→');
        expect(document.body.querySelector('[data-testid="menu-risposte"]')).not.toBeNull();
        expect(document.body.querySelector('[data-testid="icona-compito"]')!.getAttribute('class')).toContain('text-amber-500');
    });

    test('senza il permesso il menu non c\'è', async () => {
        w = mount(Compito, { attachTo: document.body, props: { compito: compito as never, puoRispondere: false }, global: { stubs: MENU } });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="menu-risposte"]')).toBeNull();
        expect(testo()).toContain('Apri le persone');
    });

    test('con la data passata il titolo è rosso, come i compiti scaduti', async () => {
        w = mount(Compito, { attachTo: document.body, props: { compito: { ...compito, giorni: -12, colore: 'passata' } as never, puoRispondere: true }, global: { stubs: MENU } });
        await flushPromises();
        expect(testo()).toContain('DATA PASSATA DA 12 GIORNI');
        expect(document.body.querySelector('h3')!.className).toContain('text-red-700');
    });
});

describe('le tre finestre', () => {
    async function apri(richiesta: Record<string, unknown>) {
        w = mount(Risposte, { attachTo: document.body, props: { condominioId: 3, oggi: '2026-05-20' } });
        (w.vm as unknown as { apri: (r: unknown) => void }).apri(richiesta);
        await flushPromises();
    }

    test('«Non serve»: il titolo, il campo «Perché», le due frasi e la dispensa', async () => {
        await apri({ tipo: 'non_serve', argomento: 'sei_mesi', esercizio: compito.esercizio, data: compito.data });
        expect(testo()).toContain('Non serve: esercizio 2025');
        expect(testo()).toContain('Perché');
        expect(testo()).toContain('Il compito si chiude. La riga resta nel riquadro, in grigio, con il motivo: si riapre da lì.');
        expect(testo()).toContain('Se l\'assemblea ha deciso di non agire, registra la dispensa: è un\'altra cosa.');
        expect(testo()).toContain('Registra la dispensa dell\'assemblea');
    });

    test('«Ricordamelo»: il giorno, la nota, l\'agenda, e il giorno dopo la data dei sei mesi lo dice senza vietarlo', async () => {
        await apri({ tipo: 'ricordamelo', argomento: 'sei_mesi', esercizio: compito.esercizio, data: compito.data });
        expect(testo()).toContain('Ricordamelo');
        expect(testo()).toContain('Il giorno');
        expect(testo()).toContain('Il giorno va nell\'agenda del condominio.');
        const giorno = document.body.querySelector('[data-testid="giorno"]') as HTMLInputElement;
        giorno.value = '2026-07-15';
        giorno.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(testo()).toContain('Il 15/07/2026 viene dopo la data dei sei mesi, il 30/06/2026.');
        (document.body.querySelector('[data-testid="conferma"]') as HTMLButtonElement).click();
        expect(post).toHaveBeenCalledWith('/gestionale.sei-mesi.risposta', expect.objectContaining({ tipo: 'ricordamelo', giorno: '2026-07-15', esercizio_id: 25 }), expect.anything());
    });

    test('«Fatto»: le persone tutte spuntate; chi si toglie resta nel riquadro, e la finestra lo dice', async () => {
        await apri({ tipo: 'fatto', argomento: 'sei_mesi', esercizio: compito.esercizio, data: compito.data, persone: compito.tutte });
        expect(testo()).toContain('Fatto: esercizio 2025');
        expect(testo()).toContain('Registra per 4 persone');
        expect(testo()).not.toContain('Chi non spunti resta nel riquadro');
        (document.body.querySelector('[data-testid="persona-2"] button[role="checkbox"]') as HTMLElement).click();
        await flushPromises();
        expect(testo()).toContain('Chi non spunti resta nel riquadro, e il compito resta aperto per lui.');
        expect(testo()).toContain('Registra per 3 persone');
    });

    test('«Registra che hai agito»: la persona, l\'esercizio, la cifra, il segnaposto, la frase', async () => {
        await apri({ tipo: 'agito', argomento: 'sei_mesi', esercizio: compito.esercizio, data: compito.data, persona: compito.tutte[0] });
        expect(testo()).toContain('Registra che hai agito');
        expect(testo()).toContain('Rossi · esercizio 2025 · € 1.240,00 di questo esercizio');
        expect((document.body.querySelector('textarea') as HTMLTextAreaElement).placeholder).toBe('Per esempio: pratica data all\'avv. Gallo; ricorso depositato il 12/03/2026.');
        expect(testo()).toContain('Rossi esce dal riquadro per l\'esercizio 2025. La riga resta nel registro. Torna se le rate di questo esercizio salgono.');
    });

    test('il compito del rendiconto con «In agenda il …» lo dice come un collegamento all\'agenda', async () => {
        w = mount(Compito, { attachTo: document.body, props: { compito: { chiave: 'rendiconto-25', argomento: 'rendiconto', esercizio: { id: 25, nome: 'Esercizio 2025' }, data: '2026-06-29',
            giorni: 40, colore: 'vicina', frase: 'Bozza: le cifre cambiano a ogni registrazione.', url: '/r', agenda: { giorno: '2026-06-20' }, tornato: null, ancora: 'rendiconto-25' } as never,
            puoRispondere: true }, global: { stubs: MENU } });
        await flushPromises();
        const agenda = document.body.querySelector('[data-testid="in-agenda"]')!;
        expect(agenda.tagName).toBe('A');
        expect(agenda.textContent).toContain('In agenda il 20/06/2026');
        w.unmount();
        w = null;
        document.body.innerHTML = '';
    });

    test('«Mettilo in agenda» per il rendiconto: di serie il giorno dei 180 giorni', async () => {
        await apri({ tipo: 'in_agenda', argomento: 'rendiconto', esercizio: compito.esercizio, data: '2026-06-29' });
        expect(testo()).toContain('Mettilo in agenda');
        expect((document.body.querySelector('[data-testid="giorno"]') as HTMLInputElement).value).toBe('2026-06-29');
    });
});
