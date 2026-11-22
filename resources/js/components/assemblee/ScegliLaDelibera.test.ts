// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Scegli la delibera» (§3 P4 del progetto della 1.12.0-beta.13; la bozza approvata con la decisione 502).
 *
 * - Aperto legge il JSON con la scheda della pagina e il ruolo; in testa «Proposte per questo piano» con «Proposta perché…» e la conseguenza; sotto
 *   «Tutte le delibere di Via Roma 12» per assemblea; la respinta spenta («non si sceglie»); il badge «A mano».
 * - **Propone, non sceglie**: aprire il menu non sceglie niente; il clic su una delibera la sceglie (emette `update:modelValue`), e la scelta mostra
 *   «Apri l'assemblea» e «Cambia».
 * - Vuoto (scena 5): la frase della pagina e le due strade; senza «Vede le assemblee» il campo lo dice e non chiede niente al server.
 *
 * Che cosa non copre: le regole delle proposte (i test Pest del motore), il cassetto del telefono.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const permessi = { lista: ['Vede le assemblee', 'Gestisce le assemblee'] as string[] };
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: vi.fn() } }));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: (p: string[]) => p.some((x) => permessi.lista.includes(x)) }),
}));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()), router: { visit: vi.fn(), reload: vi.fn() } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Scegli = (await import('./ScegliLaDelibera.vue')).default;

const delibera = (id: number, extra: Record<string, unknown> = {}) => ({
    id, testo: 'Rifacimento della facciata: approvazione dei lavori, impresa Edil Verdi, piano di riparto', esito: 'approvata', esito_parola: 'Approvata',
    deliberata_il: '2026-10-22', punto: { id: 10 + id, numero: id, testo: 'Rifacimento della facciata' }, assemblea_id: 5,
    frase: 'Delibera del 22/10/2026, assemblea straordinaria, punto 1 (verbale n. 41)', si_sceglie: true, perche: null, revocata: null, impugnata: null, ...extra,
});
const risposta = (extra: Record<string, unknown> = {}) => ({
    proposte: [delibera(1, { livello: 2, perche_proposta: 'Proposta perché parla della gestione «Facciata 2026»',
        conseguenza: 'Con questa delibera le rate del piano passano a chi era titolare il 22/10/2026' })],
    gruppi: [
        { assemblea: { id: 5, nome: 'Straordinaria del 22/10/2026', data: '2026-10-22', a_mano: false, numero_verbale: '41', verbale_da_caricare: false, perimetro: 'Tutto il condominio' },
            delibere: [delibera(1), delibera(4, { testo: 'Installazione delle telecamere nell\'androne', esito: 'respinta', esito_parola: 'Respinta', si_sceglie: false, perche: 'respinta' })] },
        { assemblea: { id: 2, nome: 'Riunione del 12/03/2024', data: '2024-03-12', a_mano: true, numero_verbale: '3/2024', verbale_da_caricare: true, perimetro: 'Tutto il condominio' },
            delibere: [delibera(9, { testo: 'Sostituzione dell\'ascensore', assemblea_id: 2 })] },
    ],
    memo: null, raccolta: null, ...extra,
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); permessi.lista = ['Vede le assemblee', 'Gestisce le assemblee']; document.body.innerHTML = ''; });
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const monta = async (props: Record<string, unknown> = {}) => {
    w = mount(Scegli, { attachTo: document.body, props: { modelValue: null, condominioId: 3, condominioNome: 'Via Roma 12', ruolo: 'delibera', oggettoTipo: 'piano_rate',
        oggettoId: 12, perQuesto: 'questo piano', ...props } });
    await flushPromises();
};

describe('«Scegli la delibera»', () => {
    test('aperto mostra le proposte con il perché e la conseguenza, poi le altre per assemblea; la respinta è spenta; niente è scelto da solo', async () => {
        axiosGet.mockResolvedValue({ data: risposta() });
        await monta();
        await w!.get('[data-testid="apri-menu"]').trigger('click');
        await flushPromises();
        const t = testo();
        expect(axiosGet).toHaveBeenLastCalledWith('/gestionale.delibere.scegli', { params: { oggetto_tipo: 'piano_rate', oggetto_id: 12, ruolo: 'delibera', cerca: undefined } });
        expect(t).toContain('Proposte per questo piano');
        expect(t).toContain('Proposta perché parla della gestione «Facciata 2026».');
        expect(t).toContain('Con questa delibera le rate del piano passano a chi era titolare il 22/10/2026.');
        expect(t).toContain('Approvata 22/10/2026 · punto 1 · verbale n. 41');
        expect(t).toContain('Tutte le delibere di Via Roma 12');
        expect(t).toContain('Assemblea straordinaria del 22/10/2026 · verbale n. 41');
        expect(t).toContain('Riunione del 12/03/2024 · registrata a mano · verbale n. 3/2024 · verbale da caricare');
        expect(t).toContain('non si sceglie: respinta');
        expect(w!.emitted('update:modelValue')).toBeUndefined();
        const spenta = w!.findAll('[data-testid="scegli-delibera"]').find((b) => b.text().includes('telecamere'))!;
        expect(spenta.attributes('disabled')).toBeDefined();
        expect(w!.find('.rounded-full').exists()).toBe(false);

        await w!.get('[data-testid="scegli-proposta"]').trigger('click');
        expect(w!.emitted('update:modelValue')![0][0]).toMatchObject({ id: 1 });
    });

    test('la delibera scelta mostra il testo, la riunione, «Apri l\'assemblea» e «Cambia»', async () => {
        axiosGet.mockResolvedValue({ data: risposta() });
        await monta({ modelValue: delibera(1) });
        const t = testo();
        expect(t).toContain('Delibera del 22/10/2026, assemblea straordinaria, punto 1 (verbale n. 41) · approvata');
        expect(w!.find('[data-testid="apri-assemblea"]').exists()).toBe(true);
        expect(w!.find('[data-testid="cambia"]').exists()).toBe(true);
    });

    test('vuoto: la frase della pagina con le due strade; senza «Vede le assemblee» il campo lo dice e non chiede niente', async () => {
        axiosGet.mockResolvedValue({ data: risposta({ proposte: [], gruppi: [] }) });
        await monta({ vuoto: 'Nessuna assemblea di Via Roma 12 ha deliberato sulla gestione «Facciata 2026»' });
        await w!.get('[data-testid="apri-menu"]').trigger('click');
        await flushPromises();
        expect(testo()).toContain('Nessuna assemblea di Via Roma 12 ha deliberato sulla gestione «Facciata 2026»');
        expect(w!.find('[data-testid="vuoto-memo"]').exists()).toBe(true);
        expect(w!.find('[data-testid="vuoto-a-mano"]').exists()).toBe(true);
        w!.unmount();
        w = null;
        axiosGet.mockReset();

        permessi.lista = [];
        await monta();
        expect(testo()).toContain('Per scegliere la delibera serve il permesso «Vede le assemblee»');
        expect(axiosGet).not.toHaveBeenCalled();
    });
});
