// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Spese del ritardo»: una spesa per il ritardo senza fattura, a una persona o a tante. Niente è già scelto (il tipo, la base, la voce); gli avvisi della
 * coppia tipo e base vengono dalla tabella del server e non bloccano; chi non ha un'unità lo dice; la richiesta ha una voce per persona con la sua unità.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Finestra = (await import('./AddebitaSpesaDialog.vue')).default;

const AVVISO = 'Le spese della causa si addebitano con la condanna alle spese: controlla la base.';
const proposta = {
    persone: [
        { anagrafica_id: 1, nome: 'Neri', immobile_id: 5, unita: 'U1', perche: null },
        { anagrafica_id: 2, nome: 'Bianchi', immobile_id: 6, unita: 'U2', perche: null },
        { anagrafica_id: 9, nome: 'Ines', immobile_id: null, unita: null, perche: 'Non è proprietario, nudo proprietario o usufruttuario di un\'unità di questo condominio: la spesa non va a lui.' },
    ],
    dove: { proposta: 2, scelte: [{ gestione_id: 2, esercizio_id: 1, nome: 'Ordinaria 2026 (Esercizio 2026)', voci: [{ id: 40, nome: 'Interessi di mora', entrata: true }, { id: 41, nome: 'Spese postali', entrata: false }] }] },
    tipi: [{ valore: 'sollecito', nome: 'Spese di sollecito' }, { valore: 'legali_giudiziali', nome: 'Spese legali della causa' }],
    basi: [{ valore: 'delibera', nome: 'una delibera dell\'assemblea' }, { valore: 'nessuna', nome: 'nessuna' }, { valore: 'provvedimento', nome: 'un provvedimento del giudice' }],
    avvisi: { sollecito: { delibera: [], nessuna: [], provvedimento: [] }, legali_giudiziali: { delibera: [AVVISO], nessuna: [AVVISO], provvedimento: [] } },
    oggi: '2026-06-10',
};

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); axiosPost.mockReset(); document.body.innerHTML = ''; });
const corpo = () => document.body;
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const scegli = async (gruppo: string, valore: string) => {
    const el = corpo().querySelector<HTMLInputElement>(`[data-testid="${gruppo}"] input[value="${valore}"]`)!;
    el.checked = true;
    el.dispatchEvent(new Event('change'));
    await flushPromises();
};
/** Una voce di un menu del programma (`v-select`): il menu si apre e si fa clic sulla voce con quel testo. */
const nelMenu = async (testid: string, testo: string) => {
    const menu = corpo().querySelector(`[data-testid="${testid}"] .v-select`) as HTMLElement;
    menu.querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
    await flushPromises();
    ([...menu.querySelectorAll('.vs__dropdown-option')].find((o) => pulito(o.textContent) === testo) as HTMLElement).click();
    await flushPromises();
};
const scrivi = async (sel: string, valore: string) => {
    const el = corpo().querySelector<HTMLInputElement | HTMLSelectElement>(sel)!;
    el.value = valore;
    el.dispatchEvent(new Event('input'));
    el.dispatchEvent(new Event('change'));
    await flushPromises();
};

describe('le spese per il ritardo', () => {
    test('niente è già scelto; gli avvisi della coppia; la causale dal tipo; una voce per persona con la sua unità, e chi non ha un\'unità resta fuori', async () => {
        axiosGet.mockResolvedValue({ data: proposta });
        w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, persone: [1, 2, 9] } });
        await flushPromises();
        expect(axiosGet.mock.calls[0][1]).toEqual({ params: { persone: [1, 2, 9] } });
        expect(pulito(corpo().querySelector('[data-testid="senza-unita"]')?.textContent)).toContain('Ines:');
        const registra = () => corpo().querySelector<HTMLButtonElement>('[data-testid="registra-spesa"]')!;
        expect(registra().disabled).toBe(true);
        expect(pulito(registra().textContent)).toBe('Addebita a 2 persone');

        await scegli('tipo', 'legali_giudiziali');
        await scegli('base', 'nessuna');
        expect([...corpo().querySelectorAll('[data-testid="avviso"]')].map((a) => pulito(a.textContent))).toEqual([AVVISO]);
        expect(corpo().querySelector<HTMLInputElement>('[data-testid="causale"]')!.value).toBe('Spese legali della causa');
        await scegli('base', 'provvedimento');
        expect(corpo().querySelectorAll('[data-testid="avviso"]')).toHaveLength(0);

        await scrivi('[data-testid="importo"]', '25,00');
        await scrivi('[data-testid="riferimento"]', 'sentenza n. 123/2026');
        // La gestione e la voce con i menu del programma; il menu della voce dice «(entrata)» per una voce di entrata, e all'apertura nessun menu è aperto.
        expect(corpo().querySelectorAll('.vs--open')).toHaveLength(0);
        expect(pulito(corpo().querySelector('[data-testid="gestione"] .vs__selected')?.textContent)).toBe('Ordinaria 2026 (Esercizio 2026)');
        await nelMenu('voce', 'Spese postali');
        expect(pulito(corpo().querySelector('[data-testid="voce"] .vs__selected')?.textContent)).toBe('Spese postali');
        expect(registra().disabled).toBe(false);
        axiosPost.mockResolvedValueOnce({ data: { rate: [7, 8], messaggio: '2 addebiti registrati: € 50,00.' } });
        registra().click();
        await flushPromises();
        expect(axiosPost.mock.calls[0][1]).toEqual({ gestione_id: 2, esercizio_id: 1, voci: [1, 2].map((id) => ({
            anagrafica_id: id, immobile_id: id === 1 ? 5 : 6, importo: '25,00', causale: 'Spese legali della causa', scadenza: '2026-06-10', natura: 'accessorio',
            tipo_accessorio: 'legali_giudiziali', base: 'provvedimento', riferimento: 'sentenza n. 123/2026', conto_id: 41,
        })) });
        expect(w.emitted('fatto')).toHaveLength(1);
    });

    test('tutte le persone dei filtri: il server le rilegge', async () => {
        axiosGet.mockResolvedValue({ data: { ...proposta, persone: [proposta.persone[0]] } });
        w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, persone: null, filtri: { mai_scritto: 1 } } });
        await flushPromises();
        expect(axiosGet.mock.calls[0][1]).toEqual({ params: { tutte: 1, filtri: { mai_scritto: 1 } } });
        expect(pulito(corpo().textContent)).toContain('Spese del ritardo per Neri');
    });
});
