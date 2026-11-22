// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La sezione «Le assemblee» delle impostazioni del condominio (scena 9 della bozza delle scene della tappa 3; decisione 590, T3): l'interruttore «Una testa
 * sola per chi ha anche una comproprietà», spento di serie (due teste: le comunioni nate da titoli diversi restano distinte), con la «i», la spiegazione e la riga «Adesso: …» con l'esempio vero del condominio (la manda il
 * server, per le due posizioni); «Da sapere» a schema; con un'assemblea in corso l'interruttore è fermo e dice perché. «Salva» manda la scelta alla sua
 * rotta, a parte dal recupero dei crediti.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

const inertia = vi.hoisted(() => ({ post: vi.fn(), patch: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: inertia.post, patch: inertia.patch, on: () => () => undefined },
    usePage: () => ({ props: { flash: {} } }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, generatePath: (p: string) => `/${p}` }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${p?.condominio ?? ''}`;

const Pagina = (await import('./ImpostazioniCondominio.vue')).default;
const passa = { template: '<div><slot /></div>' };
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; inertia.patch.mockReset(); });
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();

const ADESSO = {
    una_testa: 'Adesso: una testa. Moretti Elisa e Moretti Franco contano una volta, con 84,00 millesimi (Int. 10 e Int. 11); il condominio ha 26 condòmini.',
    due_teste: 'Adesso: due teste. Moretti Franco per l\'Int. 11, e l\'Int. 10 a parte con il suo rappresentante; il condominio ha 27 condòmini. Se a rappresentare l\'Int. 10 è Moretti Franco, conta per due.',
};
function monta(assemblee: Record<string, unknown> = { teste: 'due_teste', ferma: null, adesso: ADESSO }, puo = true) {
    w = mount(Pagina, {
        props: { condominio: { id: 16, nome: 'Via Verdi 12' }, condomini: [], esercizio: null, impostazioni: { recupero_salita: 'avanti', recupero_suggerisce_azione: true },
            puo_modificare: puo, assemblee } as never,
        global: { stubs: {
            GestionaleLayout: passa, StrutturaLayout: passa, PageHeaderGuide: { template: '<header />' }, ImpostazioniCondominioGuide: true,
            TooltipProvider: passa, Tooltip: passa, TooltipTrigger: passa, TooltipContent: { template: '<div data-testid="suggerimento"><slot /></div>' },
            Switch: { props: ['modelValue', 'disabled'], emits: ['update:modelValue'],
                template: '<button type="button" role="switch" v-bind="$attrs" :aria-checked="modelValue ? \'true\' : \'false\'" :disabled="disabled" @click="$emit(\'update:modelValue\', !modelValue)" />' },
        } },
    });

    return w;
}

describe('la sezione «Le assemblee» delle impostazioni del condominio', () => {
    test('l\'interruttore spento di serie (due teste), la «i», «Adesso» con l\'esempio del condominio e «Di serie»; la regola dei titoli diversi', () => {
        const p = monta();
        const s = p.get('[data-testid="sezione-assemblee"]');
        expect(pulito(s.find('h3').text())).toBe('Le assemblee');
        expect(pulito(s.get('[data-testid="titolo-teste"]').text())).toBe('Una testa sola per chi ha anche una comproprietà');
        expect(s.get('[data-testid="interruttore-teste"]').attributes('aria-checked')).toBe('false');
        expect(pulito(s.get('[data-testid="adesso-teste"]').text())).toBe(`${ADESSO.due_teste} Di serie.`);
        expect(s.find('[data-testid="info-teste"]').exists()).toBe(true);
        expect(pulito(s.get('[data-testid="da-sapere"]').text())).toContain('Le comunioni nate da titoli diversi restano distinte anche nell\'assemblea');
        expect(pulito(s.get('[data-testid="da-sapere"]').text())).not.toContain('Non c\'è una sentenza');
        expect(pulito(s.get('[data-testid="da-sapere"]').text())).toContain('Cass. 25558/2020');
        expect(pulito(s.get('[data-testid="da-sapere"]').text())).toContain('art. 67 c. 2 disp. att.');
    });

    test('acceso dice una testa, senza «Di serie»: la scelta del condominio con una regola sua; «Salva» manda la scelta alla sua rotta', async () => {
        const p = monta();
        await p.get('[data-testid="interruttore-teste"]').trigger('click');
        expect(pulito(p.get('[data-testid="adesso-teste"]').text())).toBe(ADESSO.una_testa);
        await p.get('[data-testid="salva-teste"]').trigger('click');
        expect(inertia.patch).toHaveBeenCalledWith('/admin.gestionale.assemblee.teste/16', { assemblee_teste: 'una_testa' }, expect.objectContaining({ preserveScroll: true }));
    });

    test('con un\'assemblea in corso l\'interruttore è fermo e dice perché; chi non modifica il condominio non salva', () => {
        const p = monta({ teste: 'una_testa', ferma: 'Un\'assemblea del condominio è in corso: le teste si cambiano dopo la chiusura.', adesso: ADESSO });
        expect(p.get('[data-testid="interruttore-teste"]').attributes('disabled')).toBeDefined();
        expect(pulito(p.get('[data-testid="ferma-teste"]').text())).toContain('le teste si cambiano dopo la chiusura');
        w!.unmount();
        const q = monta(undefined, false);
        expect(q.get('[data-testid="interruttore-teste"]').attributes('disabled')).toBeDefined();
        expect(q.find('[data-testid="salva-teste"]').exists()).toBe(false);
    });
});
