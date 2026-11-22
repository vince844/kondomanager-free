// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le finestre del passo 6, con il portone di Via Roma 12:
 *
 * - «Segna eseguita»: quando (non dopo oggi), la nota, il documento facoltativo; la richiesta con il file; il piede dice che la delibera resta da eseguire
 *   finché mancano le altre cose;
 * - «Non serve più» chiede il motivo; «Un'altra cosa da fare» manda il testo, la data e la persona;
 * - «Segna impugnata»: il giorno, chi (il menu propone chi può impugnare) e l'atto; il piede dice che niente si ferma;
 * - «Segna annullata dal giudice»: il giorno e il provvedimento; «Segna revocata»: la delibera che la revoca, letta dal server.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import vSelect from 'vue-select';
import { confermato } from './seguitoDiProva';
import FinestraDellaCosa from './FinestraDellaCosa.vue';
import FinestraDelSegno from './FinestraDelSegno.vue';

const inertia = vi.hoisted(() => ({ post: vi.fn() }));
const rete = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia }));
vi.mock('axios', () => ({ default: rete }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n }) }));
(globalThis as Record<string, unknown>).route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}?${new URLSearchParams(p as Record<string, string>)}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

let w: VueWrapper | null = null;
// Oggi è il 10/01/2027 a Roma: le finestre propongono il giorno di Roma (rilievo C54), non più quello del computer.
beforeEach(() => { vi.useFakeTimers({ toFake: ['Date'] }); vi.setSystemTime(new Date('2027-01-10T10:00:00+01:00')); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; inertia.post.mockReset(); rete.get.mockReset(); vi.useRealTimers(); });

const portone = () => confermato().tabella[2];
const el = (sel: string) => document.body.querySelector(sel) as HTMLElement;
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
async function scrivi(sel: string, valore: string) {
    const campo = el(sel) as HTMLInputElement;
    campo.value = valore;
    campo.dispatchEvent(new Event('input'));
    await flushPromises();
}

describe('le finestre delle cose da fare', () => {
    test('«Segna eseguita» manda il giorno, la nota e il documento, con il file; il piede dice che la delibera resta da eseguire', async () => {
        w = mount(FinestraDellaCosa, { attachTo: document.body, props: { open: true, modo: 'eseguita', condominioId: 3, assembleaId: 9, delibera: portone() } });
        await flushPromises();
        expect(testo()).toContain('Segna eseguita: crea il piano straordinario (€ 14.500,00)');
        expect(testo()).toContain('Resta nel registro delle modifiche con il tuo nome.');
        await scrivi('textarea[aria-label="La nota"]', 'Contratto firmato');
        const file = new File(['%PDF'], 'contratto.pdf', { type: 'application/pdf' });
        w.findComponent({ name: 'CampoFile' }).vm.$emit('update:modelValue', file);
        await flushPromises();
        el('[data-testid="conferma-della-cosa"]').click();
        expect(inertia.post).toHaveBeenCalledWith(expect.stringContaining('gestionale.assemblee.seguito.cose.eseguita'),
            { giorno: '2027-01-10', nota: 'Contratto firmato', documento: file }, expect.objectContaining({ forceFormData: true }));
        expect(inertia.post.mock.calls[0][0]).toContain('cosa=31');
    });

    test('«Non serve più» chiede il motivo prima di mandare', async () => {
        w = mount(FinestraDellaCosa, { attachTo: document.body, props: { open: true, modo: 'non_serve', condominioId: 3, assembleaId: 9, delibera: portone() } });
        await flushPromises();
        expect((el('[data-testid="conferma-della-cosa"]') as HTMLButtonElement).disabled).toBe(true);
        await scrivi('[data-testid="motivo"]', 'Il fornitore ha rinunciato');
        el('[data-testid="conferma-della-cosa"]').click();
        expect(inertia.post).toHaveBeenCalledWith(expect.stringContaining('gestionale.assemblee.seguito.cose.non-serve'), { motivo: 'Il fornitore ha rinunciato' },
            expect.anything());
    });

    test('«Un\'altra cosa da fare» manda il testo, la data (con la data è un compito) e la persona', async () => {
        rete.get.mockResolvedValue({ data: { collaboratori: [{ id: 7, nome: 'Marco Galli' }] } });
        w = mount(FinestraDellaCosa, { attachTo: document.body, props: { open: true, modo: 'nuova', condominioId: 3, assembleaId: 9, delibera: portone() } });
        await flushPromises();
        await scrivi('[data-testid="testo"]', 'Sopralluogo con l\'impresa');
        expect(testo()).toContain('Senza data resta nel passo 6 e nella dashboard.');
        el('[data-testid="conferma-della-cosa"]').click();
        expect(inertia.post).toHaveBeenCalledWith(expect.stringContaining('gestionale.assemblee.seguito.cose.store'),
            { testo: 'Sopralluogo con l\'impresa', entro_il: null, a_chi: 'me', assegnato_a: null }, expect.anything());
    });
});

describe('le finestre dei segni', () => {
    test('«Segna impugnata»: il menu propone chi può impugnare; la nota dice chi e l\'atto; niente si ferma', async () => {
        const r = { ...confermato().tabella[0] };
        w = mount(FinestraDelSegno, { attachTo: document.body, props: { open: true, modo: 'impugnata', condominioId: 3, condominioNome: 'Via Roma 12', assembleaId: 9,
            delibera: r } });
        await flushPromises();
        expect(testo()).toContain('La delibera resta in vigore: l\'impugnazione non sospende l\'esecuzione');
        expect(testo()).toContain('Il segno va sulla delibera e su 1 scheda. Niente si ferma.');
        expect(w.findComponent(vSelect).props('options')).toEqual(['Ricci Elena']);
        w.findComponent(vSelect).vm.$emit('update:modelValue', 'Ricci Elena');
        await scrivi('[data-testid="atto"]', 'Atto di citazione davanti al Tribunale di Milano');
        el('[data-testid="conferma-del-segno"]').click();
        expect(inertia.post).toHaveBeenCalledWith(expect.stringContaining('gestionale.assemblee.delibere.impugnata'),
            { giorno: '2027-01-10', nota: 'Ricci Elena · Atto di citazione davanti al Tribunale di Milano' }, expect.objectContaining({ forceFormData: true }));
    });

    test('«Segna annullata dal giudice»: il giorno e il provvedimento; le schede che la usano lo dicono', async () => {
        w = mount(FinestraDelSegno, { attachTo: document.body, props: { open: true, modo: 'annullata', condominioId: 3, condominioNome: 'Via Roma 12', assembleaId: 9,
            delibera: confermato().tabella[0] } });
        await flushPromises();
        expect(testo()).toContain('La scheda che la usa dice «Annullata»: le rivedi tu.');
        await scrivi('[data-testid="provvedimento"]', 'Sentenza n. 1187/2028 del Tribunale di Milano');
        el('[data-testid="conferma-del-segno"]').click();
        expect(inertia.post).toHaveBeenCalledWith(expect.stringContaining('gestionale.assemblee.delibere.annullata'),
            { giorno: '2027-01-10', provvedimento: 'Sentenza n. 1187/2028 del Tribunale di Milano' }, expect.anything());
    });

    test('«Segna revocata»: le delibere che possono revocarla arrivano dal server; la prima è proposta', async () => {
        rete.get.mockResolvedValue({ data: { delibere: [{ id: 77, frase: 'Delibera del 20/05/2027, assemblea ordinaria, punto 6', testo: 'Revoca della delega' }] } });
        w = mount(FinestraDelSegno, { attachTo: document.body, props: { open: true, modo: 'revocata', condominioId: 3, condominioNome: 'Via Roma 12', assembleaId: 9,
            delibera: confermato().tabella[0] } });
        await flushPromises();
        expect(rete.get).toHaveBeenCalledWith(expect.stringContaining('gestionale.assemblee.delibere.revoche'));
        expect(testo()).toContain('La delibera non vale più dal 20/05/2027.');
        el('[data-testid="conferma-del-segno"]').click();
        expect(inertia.post).toHaveBeenCalledWith(expect.stringContaining('gestionale.assemblee.delibere.revocata'), { revocata_da_delibera_id: 77 }, expect.anything());
    });
});
