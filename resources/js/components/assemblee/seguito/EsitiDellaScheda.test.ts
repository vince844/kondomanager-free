// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Gli esiti delle delibere sulle schede di arrivo (P4): un componente solo, che ogni scheda monta con una riga, e che chiede al server le frasi della scheda.
 *
 * - «Respinto dall'assemblea del 15/12/2026, punto 2 (verbale n. 42).» con il badge e «Apri l'assemblea»; «Rinviata», «Revocata», «Impugnata».
 * - Senza esiti, o senza il permesso di vedere le assemblee, il componente non mostra niente.
 * - Le otto schede lo montano: il rendiconto, il piano, la fattura, l'accordo, la dispensa, le regole, il fondo, la gestione.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import EsitiDellaScheda from './EsitiDellaScheda.vue';

const rete = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('axios', () => ({ default: rete }));
vi.mock('@inertiajs/vue3', () => ({ Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
const permessi = vi.hoisted(() => ({ vede: true }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => permessi.vede }) }));
(globalThis as Record<string, unknown>).route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}?${new URLSearchParams(p as Record<string, string>)}`;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; rete.get.mockReset(); });

describe('gli esiti sulle schede di arrivo', () => {
    test('«Respinto dall\'assemblea …» con il badge e «Apri l\'assemblea»; un segno con il suo badge', async () => {
        rete.get.mockResolvedValue({ data: { esiti: [
            { tipo: 'respinta', frase: 'Respinto dall\'assemblea del 15/12/2026, punto 2 (verbale n. 42).', delibera_id: 5, assemblea_id: 9 },
            { tipo: 'revocata', frase: 'Revocata il 20/05/2027 dalla delibera del 20/05/2027, punto 6.', delibera_id: 6, assemblea_id: 9 },
        ] } });
        w = mount(EsitiDellaScheda, { props: { condominioId: 3, tipo: 'piano_rate', id: 12 } });
        await flushPromises();
        expect(rete.get).toHaveBeenCalledWith(expect.stringContaining('gestionale.esiti-nelle-schede'));
        expect(rete.get.mock.calls[0][0]).toContain('tipo=piano_rate');
        const righe = w.findAll('[data-testid="esito-della-delibera"]');
        expect(righe).toHaveLength(2);
        expect(righe[0].text()).toContain('Respinta');
        expect(righe[0].text()).toContain('Respinto dall\'assemblea del 15/12/2026, punto 2 (verbale n. 42).');
        expect(righe[0].get('a').text()).toBe('Apri l\'assemblea');
        expect(righe[0].get('a').attributes('href')).toContain('assemblea=9');
        expect(righe[1].text()).toContain('Revocata');
    });

    test('senza esiti, o senza il permesso, non mostra niente; chi non vede le assemblee non chiede niente', async () => {
        rete.get.mockResolvedValue({ data: { esiti: [] } });
        w = mount(EsitiDellaScheda, { props: { condominioId: 3, tipo: 'piano_rate', id: 12 } });
        await flushPromises();
        expect(w.find('[data-testid="esiti-della-scheda"]').exists()).toBe(false);
        w.unmount();
        rete.get.mockRejectedValue({ response: { status: 403 } });
        w = mount(EsitiDellaScheda, { props: { condominioId: 3, tipo: 'piano_rate', id: 12 } });
        await flushPromises();
        expect(w.find('[data-testid="esiti-della-scheda"]').exists()).toBe(false);
        w.unmount();
        rete.get.mockClear();
        permessi.vede = false;
        w = mount(EsitiDellaScheda, { props: { condominioId: 3, tipo: 'piano_rate', id: 12 } });
        await flushPromises();
        expect(rete.get).not.toHaveBeenCalled();
        permessi.vede = true;
    });

    test.each([
        ['resources/js/pages/gestionale/pianiRate/PianiRateShow.vue', 'piano_rate'],
        ['resources/js/pages/gestionale/movimenti/fatture/FatturaShow.vue', 'fattura_passiva'],
        ['resources/js/pages/gestionale/casse/CasseEdit.vue', 'cassa'],
        ['resources/js/pages/gestionale/esercizi/DispenseSeiMesi.vue', 'dispensa_sei_mesi'],
        ['resources/js/pages/gestionale/struttura/RegoleRecupero.vue', 'regola_recupero'],
        ['resources/js/components/gestionale/accordi/AccordoRientroDialog.vue', 'accordo_rientro'],
        ['resources/js/pages/gestionale/gestioni/GestioniEdit.vue', 'gestione'],
        ['resources/js/components/gestionale/movimenti/rendiconto/RendicontoPerLAssemblea.vue', 'rendiconto_versione'],
    ])('%s monta gli esiti della sua scheda (%s)', (file, tipo) => {
        const s = readFileSync(resolve(process.cwd(), file), 'utf8');
        expect(s).toContain('import EsitiDellaScheda from \'@/components/assemblee/seguito/EsitiDellaScheda.vue\';');
        expect(s).toMatch(new RegExp(`<EsitiDellaScheda[^>]*tipo="${tipo}"`));
    });
});
