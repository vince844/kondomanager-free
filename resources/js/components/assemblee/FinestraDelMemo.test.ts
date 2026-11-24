// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra veloce del memo «Da portare in assemblea» (§3 P8 del progetto della 1.12.0-beta.13; scena 2 della bozza approvata con la decisione 501).
 *
 * - Aperta legge la proposta dal contesto: il punto, «Da dove nasce» con «Apri», «il programma propone già …»; la riga «Adesso: …» dice dove te lo ritrovi.
 * - Con «Ricordamelo» la riga dice il giorno e a chi. Il giorno si sceglie nel calendario di `CampoGiorno`, che spegne i giorni prima di oggi (`:min="oggi"`):
 *   un giorno passato non si sceglie più, si trova solo in un memo scritto prima, e lì **la finestra lo dice** e «Salva il memo» resta spento. Le prove
 *   fissano oggi al 20/11/2026: il campo non è più quello del browser, su cui si scriveva un giorno qualsiasi, e il calendario dipende da oggi.
 * - «Salva il memo» manda il punto, l'origine, il giorno solo con «Ricordamelo», a chi; poi la finestra si chiude ed emette la frase.
 *
 * Che cosa non copre: le regole del server (`MemoDallaFinestraTest`), il menu «A chi» aperto.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { apriAlGiorno, calendarioAperto, scegliIlGiorno } from '@/components/campoGiornoDiProva';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
const axiosPatch = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a), patch: (...a: unknown[]) => axiosPatch(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./FinestraDelMemo.vue')).default;
const proposta = {
    testo: 'Ratifica della spesa oltre il preventivo di € 1.800,00 per la riparazione della colonna dell\'acqua (fattura n. 45 di Idraulica Bianchi)',
    origine: { tipo: 'fattura_passiva', id: 45, nome: 'Fattura n. 45 · Idraulica Bianchi · € 1.800,00', apri: '/admin/gestionale/3/fatture/45' },
    assemblee: [], collaboratori: [{ id: 8, nome: 'Marco Galli' }],
    gia_proposto: 'Il programma propone già questo punto fra quelli della prossima assemblea: il tuo memo diventa lo stesso punto, con la tua nota.',
    esistente: null, condominio: 'Via Roma 12',
};

let w: VueWrapper | null = null;
beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-11-20T10:00:00'));
});
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); axiosPost.mockReset(); axiosPatch.mockReset(); document.body.innerHTML = ''; vi.useRealTimers(); });
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const campo = (id: string) => document.body.querySelector(`[data-testid="${id}"]`) as HTMLInputElement | HTMLButtonElement;
const monta = async () => {
    axiosGet.mockResolvedValue({ data: proposta });
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, origineTipo: 'fattura_passiva', origineId: 45 } });
    await flushPromises();
};

describe('la finestra del memo', () => {
    test('lo standard delle finestre (decisione 608): niente esce a destra, il contenuto sta dentro e scorre, il riepilogo e i pulsanti fissi in fondo', async () => {
        await monta();
        const finestra = campo('finestra-memo');
        const corpo = campo('corpo-memo');
        // Il contenuto scorre dentro la finestra, che non scorre da sé e sta ancorata in alto; il corpo non allarga la colonna (`min-w-0`).
        expect(finestra.className).toContain('overflow-hidden');
        expect(finestra.className).toContain('top-[5vh]');
        expect(corpo.className).toContain('overflow-y-auto');
        expect(corpo.className).toContain('min-w-0');
        // Il menu «Per quale assemblea» sta a tutta larghezza della finestra, senza allargarla.
        expect(campo('menu-assemblea').className).toContain('min-w-0');
        expect(campo('menu-assemblea').className).toContain('w-full');
        // I menu si aprono fuori dal corpo che scorre (rilievo C43: «A chi», in fondo, restava nascosto sotto il piede).
        const selettori = Array.from(finestra.querySelectorAll('.v-select'));
        expect(selettori.length).toBeGreaterThan(0);
        for (const s of selettori) {
            (s.querySelector('.vs__dropdown-toggle') as HTMLElement).dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
            await flushPromises();
            const aperto = document.body.querySelector('.vs__dropdown-menu') as HTMLElement;
            expect(aperto).not.toBeNull();
            expect(corpo.contains(aperto)).toBe(false);
            expect(aperto.style.position).toBe('fixed');
            (s.querySelector('.vs__dropdown-toggle') as HTMLElement).dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
            await flushPromises();
        }
        // Il riepilogo «Adesso: …» è nel piede, accanto a «Salva il memo».
        const piede = campo('piede-memo');
        expect(piede.contains(campo('adesso-memo'))).toBe(true);
        expect(piede.contains(campo('salva-memo'))).toBe(true);
    });

    test('scegliere una voce nel menu aperto fuori non chiude la finestra (rilievo C43)', async () => {
        await monta();
        await new Promise((r) => setTimeout(r, 0));
        const toggle = document.body.querySelector('[data-testid="menu-assemblea"] .vs__dropdown-toggle') as HTMLElement;
        toggle.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        await flushPromises();
        const voce = document.body.querySelector('.vs__dropdown-menu .vs__dropdown-option') as HTMLElement;
        expect(voce).not.toBeNull();
        voce.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }));
        await flushPromises();
        expect(w!.emitted('update:open')).toBeUndefined();
    });

    test('la proposta dal contesto: il punto, da dove nasce con «Apri», il programma che la propone già, e «Adesso: …»', async () => {
        await monta();
        expect(axiosGet).toHaveBeenCalledWith('/gestionale.memo.proposta', { params: { origine_tipo: 'fattura_passiva', origine_id: 45 } });
        expect((campo('testo-memo') as HTMLInputElement).value).toBe(proposta.testo);
        const t = testo();
        expect(t).toContain('Da portare in assemblea');
        expect(t).toContain('Fattura n. 45 · Idraulica Bianchi · € 1.800,00');
        expect(t).toContain('Il programma propone già questo punto');
        expect(t).toContain('Adesso: lo trovi proposto quando prepari la prossima assemblea di Via Roma 12.');
    });

    test('il punto dell\'accordo di rientro non ha il nome; la nota arriva già scritta con il nome, solo nella nota dello studio (749)', async () => {
        axiosGet.mockResolvedValue({ data: { ...proposta, testo: 'Autorizzazione di un accordo di rientro di € 1.200,00 in 6 rate, fino al 31/03/2027',
            nota: 'Accordo con Gallo Enzo del 01/10/2026.', origine: { tipo: 'accordo_rientro', id: 9, nome: 'Accordo di Gallo Enzo', apri: null }, gia_proposto: null } });
        w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, origineTipo: 'accordo_rientro', origineId: 9 } });
        await flushPromises();
        expect((campo('testo-memo') as HTMLInputElement).value).toBe('Autorizzazione di un accordo di rientro di € 1.200,00 in 6 rate, fino al 31/03/2027');
        expect((campo('testo-memo') as HTMLInputElement).value).not.toContain('Gallo');
        expect((campo('nota-memo') as HTMLInputElement).value).toBe('Accordo con Gallo Enzo del 01/10/2026.');
    });

    test('con «Ricordamelo» i giorni prima di oggi sono spenti nel calendario; un giorno buono si salva con a chi', async () => {
        await monta();
        (campo('ricordamelo') as HTMLButtonElement).click();
        await flushPromises();
        expect((campo('giorno-memo').textContent ?? '').trim()).toBe('27/11/2026');
        const ieri = await apriAlGiorno(campo('giorno-memo'), '2026-11-19');
        expect(ieri.hasAttribute('data-disabled')).toBe(true);
        expect(document.body.querySelector('[data-value="2026-11-20"]')!.hasAttribute('data-disabled')).toBe(false);
        ieri.click();
        await flushPromises();
        expect(calendarioAperto()).not.toBeNull();
        expect(testo()).not.toContain('Il giorno è già passato');

        await scegliIlGiorno(campo('giorno-memo'), '2026-11-25');
        expect(testo()).toContain('Adesso: dal 25/11/2026 nell\'Action inbox e nell\'agenda, a te.');
        axiosPost.mockResolvedValue({ data: { frase: 'Adesso: dal 25/11/2026 nell\'Action inbox e nell\'agenda, a te.' } });
        (campo('salva-memo') as HTMLButtonElement).click();
        await flushPromises();
        expect(axiosPost).toHaveBeenCalledWith('/gestionale.memo.store', expect.objectContaining({
            testo: proposta.testo, origine_tipo: 'fattura_passiva', origine_id: 45, ricordami: true, ricordami_dal: '2026-11-25', a_chi: 'me', assegnato_a: null,
        }));
        expect(w!.emitted('salvato')![0][0]).toBe('Memo salvato. Adesso: dal 25/11/2026 nell\'Action inbox e nell\'agenda, a te.');
        expect(w!.emitted('update:open')![0][0]).toBe(false);
    });
});

describe('la finestra del memo in modifica (decisione 574, dal menu del punto nella card)', () => {
    test('un memo con un giorno già passato lo dice, e «Salva il memo» resta spento finché non si sceglie oggi o un giorno dopo', async () => {
        axiosGet.mockResolvedValue({ data: { ...proposta, esistente: { id: 12, frase: 'Già nella memoria dal 02/11/2026: «Ratifica»' } } });
        const memo = { id: 12, testo: 'Ratifica della colonna', nota: null, assemblea_id: null, ricordami_dal: '2026-11-02', a_chi: 'me',
            assegnato_a: null, origine_tipo: 'fattura_passiva', origine_id: 45 };
        w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, memo } });
        await flushPromises();
        expect((campo('giorno-memo').textContent ?? '').trim()).toBe('02/11/2026');
        expect(testo()).toContain('Il giorno è già passato: scegli oggi o un giorno dopo.');
        expect((campo('salva-memo') as HTMLButtonElement).disabled).toBe(true);
        await scegliIlGiorno(campo('giorno-memo'), '2026-11-20');
        expect(testo()).not.toContain('Il giorno è già passato');
        expect((campo('salva-memo') as HTMLButtonElement).disabled).toBe(false);
    });

    test('si apre con i campi del memo, senza le righe per un memo nuovo, e «Salva il memo» lo cambia', async () => {
        axiosGet.mockResolvedValue({ data: { ...proposta, esistente: { id: 12, frase: 'Già nella memoria dal 18/11/2026: «Ratifica»' } } });
        const memo = { id: 12, testo: 'Ratifica della colonna', nota: 'Con la relazione', assemblea_id: null, ricordami_dal: '2099-11-25', a_chi: 'collaboratore',
            assegnato_a: 8, origine_tipo: 'fattura_passiva', origine_id: 45 };
        w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, memo } });
        await flushPromises();
        expect(axiosGet).toHaveBeenCalledWith('/gestionale.memo.proposta', { params: { origine_tipo: 'fattura_passiva', origine_id: 45 } });
        expect((campo('testo-memo') as HTMLInputElement).value).toBe('Ratifica della colonna');
        const t = testo();
        expect(t).toContain('Modifica il memo');
        expect(t).toContain('Adesso: dal 25/11/2099 nell\'Action inbox e nell\'agenda, a Marco Galli.');
        expect(t).not.toContain('Un memo nuovo sulla stessa scheda');
        expect(t).not.toContain('Il programma propone già');

        axiosPatch.mockResolvedValue({ data: { frase: 'Adesso: dal 25/11/2099 nell\'Action inbox e nell\'agenda, a Marco Galli.' } });
        (campo('salva-memo') as HTMLButtonElement).click();
        await flushPromises();
        expect(axiosPost).not.toHaveBeenCalled();
        expect(axiosPatch).toHaveBeenCalledWith('/gestionale.memo.update', expect.objectContaining({
            testo: 'Ratifica della colonna', nota: 'Con la relazione', ricordami: true, ricordami_dal: '2099-11-25', a_chi: 'collaboratore', assegnato_a: 8,
        }));
        expect(w!.emitted('salvato')![0][0]).toBe('Memo cambiato. Adesso: dal 25/11/2099 nell\'Action inbox e nell\'agenda, a Marco Galli.');
    });
});
