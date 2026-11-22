// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra dell'addebito quando il server non propone nessuno (1.12.0-beta.13, decisione 322 con P17 del progetto della tappa 1): con due delibere
 * diverse dell'opera nello stesso esercizio la risposta ha `nessuna_proposta`, la frase del perché. La finestra la mostra, non sceglie nessuna persona e non
 * scrive «(la proposta)» accanto a nessuno: i titolari restano fra cui scegliere, e «Registra l'addebito» si accende solo dopo la scelta. Decide
 * l'amministratore.
 *
 * Che cosa non copre: la regola della delibera, provata dal server (`tests/Feature/Gestionale/AddebitiDeliberaDellOperaTest.php`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: vi.fn() } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Finestra = (await import('./NuovoAddebitoDialog.vue')).default;

const FRASE = 'Nell\'esercizio del primo piano dell\'opera ci sono delibere con date diverse: il programma non sceglie a chi addebitare. '
    + 'Scegli la persona, o scrivi la competenza sulla fattura.';
const d2026 = { gestione_id: 2, esercizio_id: 1, nome: 'Tetto (Esercizio 2026)', inizio: '2026-01-01', fine: '2026-12-31' };
const persone = [
    { anagrafica_id: 4, nome: 'Ida Romano', perche: 'titolare oggi' },
    { anagrafica_id: 5, nome: 'Mario Rossi', perche: 'è stato titolare dal 01/01/2019 al 31/03/2026' },
];
const dalConguaglio = (nessuna: string | null) => ({
    unita: [{ id: 3, nome: 'Int. 7' }], immobile_id: 3, persone, nessuna_proposta: nessuna, causale: 'Spese dei giorni senza titolare, Tetto',
    origine: { gestione_id: 2, esercizio_id: 1 }, dove: { proposta: d2026, scelte: [d2026] }, scadenza: '2026-12-20', oggi: '2026-12-20',
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const monta = async (risposta: unknown) => {
    axiosGet.mockResolvedValue({ data: risposta });
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, origine: { tipo: 'conguaglio', gestioneId: 2, esercizioId: 1, importo: 12000 } as never } });
    await flushPromises();
};

describe('nessuna proposta: due delibere diverse dell\'opera', () => {
    test('la finestra dice il perché, non sceglie nessuno e non scrive «(la proposta)»; la scelta è dell\'amministratore', async () => {
        await monta(dalConguaglio(FRASE));

        const nota = document.body.querySelector('[data-testid="nessuna-proposta"]');
        expect(pulito(nota?.textContent)).toContain('Nessuna persona proposta.');
        expect(pulito(nota?.textContent)).toContain(FRASE);
        const righe = [...document.body.querySelectorAll('[data-testid="persona"]')];
        expect(righe.map((r) => pulito(r.textContent))).toEqual(['Ida Romano — titolare oggi', 'Mario Rossi — è stato titolare dal 01/01/2019 al 31/03/2026']);
        expect(righe.map((r) => (r.querySelector('input') as HTMLInputElement).checked)).toEqual([false, false]);
        expect(document.body.querySelector('[data-testid="nessuna-persona"]')).toBeNull();
        expect((document.body.querySelector('[data-testid="registra-addebito"]') as HTMLButtonElement).disabled).toBe(true);

        (righe[1].querySelector('input') as HTMLInputElement).click();
        await flushPromises();
        expect((righe[1].querySelector('input') as HTMLInputElement).checked).toBe(true);
        expect((document.body.querySelector('[data-testid="registra-addebito"]') as HTMLButtonElement).disabled).toBe(false);
    });

    test('con una proposta la finestra resta com\'era: la prima persona scelta, con «(la proposta)», e nessuna frase in più', async () => {
        await monta(dalConguaglio(null));

        expect(document.body.querySelector('[data-testid="nessuna-proposta"]')).toBeNull();
        const righe = [...document.body.querySelectorAll('[data-testid="persona"]')];
        expect(pulito(righe[0].textContent)).toBe('Ida Romano — titolare oggi (la proposta)');
        expect((righe[0].querySelector('input') as HTMLInputElement).checked).toBe(true);
    });
});
