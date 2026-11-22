// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Elimina» su una delibera con le cose da fare (la riunione tenuta su carta di Via Roma 12):
 *
 * - la finestra elenca le cose che se ne vanno con la delibera, quelle fatte con il giorno, la persona e il documento;
 * - chiede il motivo, sempre obbligatorio, e dice dove resta tutto; la richiesta porta il motivo;
 * - una cosa ancora da fare tiene la delibera: il riquadro arancio lo dice, ed «Elimina» resta spento;
 * - nella pagina della riunione, «Elimina» di una delibera con le cose apre questa finestra; senza, la conferma di sempre.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { cosa } from './seguitoDiProva';
import EliminaLaDelibera from './EliminaLaDelibera.vue';
import RiunioneRegistrata from '@/components/assemblee/RiunioneRegistrata.vue';

const inertia = vi.hoisted(() => ({ delete: vi.fn(), post: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia, useForm: () => ({ verbale: null, errors: {}, post: vi.fn() }) }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n }) }));
(globalThis as Record<string, unknown>).route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}?${new URLSearchParams(p as Record<string, string>)}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; inertia.delete.mockReset(); });

const el = (sel: string) => document.body.querySelector(sel) as HTMLElement;
const fatta = cosa({ id: 32, tipo: 'fuori_dal_programma', testo: 'Firma con Serramenti Marino', stato: 'fatta', fatta_il: '2026-12-16', fatta_da: 'Andrea Costa',
    documento: { id: 4, nome: 'contratto firmato.pdf' }, azione: null });

describe('«Elimina» con il motivo', () => {
    test('elenca le cose che se ne vanno, chiede il motivo, dice dove resta tutto; la richiesta porta il motivo', async () => {
        w = mount(EliminaLaDelibera, { attachTo: document.body, props: { open: true, condominioId: 3, delibera: { id: 11, testo: 'Portone: firma del contratto', punto: 1 },
            cose: [fatta] } });
        await flushPromises();
        expect(el('[data-testid="cosa-che-se-ne-va"]').textContent).toBe('Firma con Serramenti Marino · fatta il 16/12/2026 da Andrea Costa · contratto firmato.pdf');
        expect(el('[data-testid="dove-resta"]').textContent?.replace(/\s+/g, ' ')).toContain('Resta tutto nel diario dell\'assemblea e nel registro delle modifiche');
        expect((el('[data-testid="conferma-elimina"]') as HTMLButtonElement).disabled).toBe(true);
        const motivo = el('[data-testid="motivo-elimina"]') as HTMLTextAreaElement;
        motivo.value = 'Esito sbagliato, la registro di nuovo';
        motivo.dispatchEvent(new Event('input'));
        await flushPromises();
        el('[data-testid="conferma-elimina"]').click();
        expect(inertia.delete).toHaveBeenCalledWith(expect.stringContaining('gestionale.delibere.destroy'),
            expect.objectContaining({ data: { motivo: 'Esito sbagliato, la registro di nuovo', con_la_riunione: false } }));
    });

    test('una cosa ancora da fare tiene la delibera: il riquadro arancio, «Elimina» spento', async () => {
        w = mount(EliminaLaDelibera, { attachTo: document.body, props: { open: true, condominioId: 3, delibera: { id: 11, testo: 'Portone', punto: 1 }, cose: [cosa({})] } });
        await flushPromises();
        expect(el('[data-testid="ancora-da-fare"]').textContent).toContain('Una cosa è ancora da fare: segnala «Non serve più»');
        const motivo = el('[data-testid="motivo-elimina"]') as HTMLTextAreaElement;
        motivo.value = 'Sbagliata';
        motivo.dispatchEvent(new Event('input'));
        await flushPromises();
        expect((el('[data-testid="conferma-elimina"]') as HTMLButtonElement).disabled).toBe(true);
    });

    test('nella pagina della riunione: con le cose «Elimina» apre la finestra del motivo', async () => {
        const assemblea = { id: 9, nome: 'Assemblea ordinaria del 15/12/2026', a_mano: false, tenuta_fuori: true, verbale: null, data: '2026-12-15' };
        const ordine = [{ id: 40, numero: 1, testo: 'Portone: firma del contratto', delibere: [{ id: 11, testo: 'Portone: firma del contratto', esito: 'approvata',
            esito_parola: 'Approvata', deliberata_il: '2026-12-15', frase: '', posti: [], usata: null }] }];
        w = mount(RiunioneRegistrata as never, { attachTo: document.body, props: { condominioId: 3, assemblea, ordine, esiti: [], riunioni: [], gestisce: true,
            registraIlVerbale: true, cose: { 11: [fatta] } } as never, global: { stubs: {
                DropdownMenu: { template: '<div><slot /></div>' }, DropdownMenuTrigger: { template: '<div><slot /></div>' }, DropdownMenuContent: { template: '<div><slot /></div>' },
                DropdownMenuItem: { emits: ['select'], template: '<div role="menuitem" @click="$emit(\'select\')"><slot /></div>' } } } });
        await flushPromises();
        expect(document.body.textContent).toContain('Carica il verbale firmato');
        expect(el('[data-testid="verbale-di-carta"]').textContent).toContain('Quando il presidente te lo porta firmato, caricalo');
        ([...document.body.querySelectorAll('[role="menuitem"]')].find((m) => m.textContent?.includes('Elimina')) as HTMLElement).click();
        await flushPromises();
        expect(el('[data-testid="elimina-la-delibera"]')).not.toBeNull();
        expect(el('[data-testid="cosa-che-se-ne-va"]').textContent).toContain('Firma con Serramenti Marino');
    });
});
