// @vitest-environment jsdom

/**
 * Il pulsante della guida nel cassetto ha un aspetto solo: quello di «Guida» dell'intestazione (`PageHeaderGuide.vue` con `has-text-guide`). Le otto
 * pagine con «Guida completa» usano lo stesso pulsante, con il loro nome; il clic apre ciò che apriva.
 *
 * Una guardia sui sorgenti: nessuna delle otto pagine ha più «Guida completa» dentro un `<Button>` (le altezze `h-9` e `size="sm"` e le icone diverse).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ url: '/admin/gestionale/3/saldi', props: {} }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n }) }));

const PulsanteGuida = (await import('./PulsanteGuida.vue')).default;
const PageHeaderGuide = (await import('./PageHeaderGuide.vue')).default;

const CLASSI = ['inline-flex', 'h-8', 'items-center', 'justify-center', 'gap-2', 'text-xs', 'font-bold', 'px-3', 'rounded-lg', 'bg-indigo-50', 'text-indigo-600',
    'hover:bg-indigo-100', 'border', 'border-indigo-100', 'transition-colors', 'dark:bg-indigo-950/30', 'dark:text-indigo-400'];

const OTTO_PAGINE = [
    'resources/js/pages/gestionale/movimenti/incassi/IncassoRateNew.vue',
    'resources/js/pages/gestionale/movimenti/pagamenti/PagamentoNew.vue',
    'resources/js/pages/gestionale/contributi/ContributiList.vue',
    'resources/js/pages/gestionale/movimenti/incassiDaTerzi/IncassoDaTerziNew.vue',
    'resources/js/pages/gestionale/movimenti/fatture/FatturaRegisterNew.vue',
    'resources/js/pages/gestionale/movimenti/regolazioni/RegolazioneImmediataNew.vue',
    'resources/js/pages/gestionale/movimenti/giroconti/GirocontoNew.vue',
    'resources/js/pages/gestionale/movimenti/giroconti/GirocontiList.vue',
];
const senzaCommenti = (s: string) => s.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|\s)\/\/.*$/gm, '$1');

describe('il pulsante della guida', () => {
    test('ha l\'aspetto di «Guida» dell\'intestazione: alto 8, il testo piccolo in grassetto, l\'indaco chiaro, il tema scuro, il libro', () => {
        const w = mount(PulsanteGuida, { props: { testo: 'Guida completa' }, attrs: { 'data-testid': 'guida-completa' } });
        expect(w.element.tagName).toBe('BUTTON');
        expect(w.attributes('type')).toBe('button');
        expect(w.classes()).toEqual(expect.arrayContaining(CLASSI));
        expect(w.text()).toBe('Guida completa');
        expect(w.attributes('data-testid')).toBe('guida-completa');
        const libro = w.get('svg');
        expect(libro.classes()).toEqual(expect.arrayContaining(['lucide-book-open-icon', 'w-3.5', 'h-3.5']));
        expect(mount(PulsanteGuida).text()).toBe('Guida');
    });

    test('il clic arriva a chi lo usa', async () => {
        const clic = vi.fn();
        const w = mount(PulsanteGuida, { attrs: { onClick: clic } });
        await w.trigger('click');
        expect(clic).toHaveBeenCalledTimes(1);
    });

    test('l\'intestazione usa lo stesso pulsante per «Guida»', async () => {
        const w = mount(PageHeaderGuide, { props: { pageTitle: 'Saldi', guides: [], hasTextGuide: true, textGuideTitle: 'Guida' } });
        const pulsante = w.findComponent(PulsanteGuida);
        expect(pulsante.exists()).toBe(true);
        expect(pulsante.classes()).toEqual(expect.arrayContaining(CLASSI));
        await pulsante.trigger('click');
        expect(w.emitted('open-text-guide')).toHaveLength(1);
    });

    test.each(OTTO_PAGINE)('%s: «Guida completa» è il pulsante della guida, non un <Button> con un aspetto suo', (file) => {
        const sorgente = senzaCommenti(readFileSync(resolve(process.cwd(), file), 'utf8'));
        expect(sorgente).toMatch(/<PulsanteGuida\b[^>]*testo="Guida completa"/);
        expect(sorgente).not.toMatch(/<Button\b(?:(?!<\/Button>)[\s\S])*Guida completa(?:(?!<\/Button>)[\s\S])*<\/Button>/);
    });
});
