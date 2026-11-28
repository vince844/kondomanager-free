// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La card «Assemblee» delle impostazioni dello studio (scena 9 della bozza delle scene della tappa 3), solo all'amministratore: i giorni di posta per una
 * raccomandata, «La delega oltre il quinto si rifiuta» e «Il voto discorde dei comproprietari conta astenuto», interruttori accesi di serie, ognuno con la
 * «i», la spiegazione di acceso e spento e la riga «Adesso: …» che cambia appena lo si muove. «Salva» manda le tre scelte insieme. Nell'elenco delle
 * impostazioni la card c'è solo per l'amministratore.
 *
 * Che cosa non copre: il salvataggio e il registro (`ImpostazioniDellaSalaTest`, `PagineDellaSalaTest`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const inertia = vi.hoisted(() => ({ patch: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    router: inertia,
    Head: { render: () => null },
    usePage: () => ({ props: { flash: {}, errors: {} } }),
}));
vi.mock('vue-select', async () => {
    const { defineComponent, h } = await import('vue');
    return {
        default: defineComponent({
            name: 'VSelect', props: ['modelValue', 'options', 'reduce', 'label'], emits: ['update:modelValue'],
            setup(p, { emit, attrs }) {
                return () => h('div', { class: 'v-select-finto', 'data-testid': attrs['data-testid'] }, (p.options ?? []).map((o: unknown, i: number) => h('button', {
                    type: 'button', 'data-opzione': i, onClick: () => emit('update:modelValue', p.reduce ? p.reduce(o) : o),
                }, String((o as Record<string, unknown>)[p.label ?? 'label']))));
            },
        }),
    };
});
(globalThis as Record<string, unknown>).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const passa = { template: '<div><slot /></div>' };
const stubs = {
    AppLayout: passa,
    PageHeaderGuide: { props: ['pageTitle', 'pageSubtitle'], template: '<header><h1>{{ pageTitle }}</h1><p>{{ pageSubtitle }}</p></header>' },
    Switch: { props: ['modelValue', 'disabled'], emits: ['update:modelValue'],
        template: '<button type="button" role="switch" :aria-checked="String(modelValue)" v-bind="$attrs" @click="$emit(\'update:modelValue\', !modelValue)" />' },
    TooltipProvider: passa, Tooltip: passa, TooltipTrigger: passa, TooltipContent: passa,
};

const Pagina = (await import('./ImpostazioniAssemblee.vue')).default;
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; inertia.patch.mockClear(); });

async function monta(impostazioni = { delega_oltre_il_quinto: 'rifiuta', voto_discorde_comproprietari: 'astenuti', giorni_di_posta: 3 }) {
    w = mount(Pagina as never, { attachTo: document.body, props: { impostazioni } as never, global: { stubs } });
    await flushPromises();

    return w;
}
const testo = (sel: string) => (document.body.querySelector(sel)?.textContent ?? '').replace(/\s+/g, ' ').trim();

describe('le impostazioni delle assemblee', () => {
    test('le tre righe, di serie: «Adesso: …» con «Di serie.», e la «i» con l\'esempio', async () => {
        await monta();
        expect(testo('h1')).toBe('Le assemblee');
        expect(testo('[data-riga="posta"] [data-testid="adesso"]')).toContain('3 giorni lavorativi');
        expect(testo('[data-riga="quinto"] [data-testid="adesso"]')).toContain('si registra rifiutata, con l\'art. 67 c. 1 disp. att. Di serie.');
        expect(testo('[data-riga="discorde"] [data-testid="adesso"]')).toContain('l\'unità conta astenuta');
        expect(document.body.querySelectorAll('[data-testid^="info-"]')).toHaveLength(3);
        expect((document.body.querySelector('[data-testid="salva"]') as HTMLButtonElement).disabled).toBe(true);
    });

    test('muovere un interruttore cambia «Adesso» prima di salvare; «Salva» manda le tre scelte', async () => {
        await monta();
        (document.body.querySelector('[data-testid="interruttore-quinto"]') as HTMLElement).click();
        await flushPromises();
        expect(testo('[data-riga="quinto"] [data-testid="adesso"]')).toContain('decide il presidente');
        expect(testo('[data-riga="quinto"] [data-testid="adesso"]')).not.toContain('Di serie.');
        (document.body.querySelector('[data-testid="giorni-di-posta"] [data-opzione="4"]') as HTMLElement).click();
        await flushPromises();
        (document.body.querySelector('[data-testid="salva"]') as HTMLElement).click();
        expect(inertia.patch).toHaveBeenCalledWith('/impostazioni.assemblee.update', { delega_oltre_il_quinto: 'decide_il_presidente',
            voto_discorde_comproprietari: 'astenuti', giorni_di_posta: 5 }, expect.anything());
    });

    test('dice che le scelte valgono da quando salvi, che una sala già aperta tiene quelle della sua apertura, e che la scelta delle teste è di ogni condominio', async () => {
        await monta();
        expect(document.body.textContent).toContain('Valgono da quando salvi');
        expect(document.body.textContent).toContain('Una sala già aperta tiene le scelte della sua apertura, fino alla chiusura.');
        expect(document.body.textContent).not.toContain('anche per le deleghe e i voti che si segnano dopo in una sala già aperta');
        expect(document.body.textContent).toContain('una testa o due teste si sceglie in ogni condominio');
    });

    test('nell\'elenco delle impostazioni la card «Assemblee» c\'è solo per l\'amministratore', () => {
        const s = readFileSync(resolve(process.cwd(), 'resources/js/pages/impostazioni/impostazioni.vue'), 'utf8');
        // La card c'è per chi apre la sua rotta (il ruolo amministratore): lo decide il server, nell'elenco delle schede della pagina.
        expect(s).toMatch(/mostra\('assemblee'\)[\s\S]*?assemblee_title[\s\S]*?\/impostazioni\/assemblee/);
        const schede = readFileSync(resolve(process.cwd(), 'app/Http/Controllers/Impostazioni/ImpostazioniController.php'), 'utf8');
        expect(schede).toContain("'assemblee'     => Role::AMMINISTRATORE");
        const lingua = readFileSync(resolve(process.cwd(), 'lang/it/impostazioni.php'), 'utf8');
        expect(lingua).toContain('\'assemblee_title\' => \'Assemblee\'');
    });

    test('le tre «i» si aprono anche al tocco: usano il suggerimento del modulo, non il solo passaggio del mouse', () => {
        const s = readFileSync(resolve(process.cwd(), 'resources/js/pages/impostazioni/ImpostazioniAssemblee.vue'), 'utf8');
        for (const chiave of ['posta', 'quinto', 'discorde']) {
            expect(s).toMatch(new RegExp(`<SuggerimentoDellaI[^>]*RIGHE\\.${chiave}\\.esempio[^>]*testid="info-${chiave}"`));
        }
        expect(s).not.toContain('<TooltipTrigger');
    });
});

// La prova a video di Vincenzo, 21/11/2026 sera: «in questa pagina mancano le breadcrumbs e i pulsanti come nelle altre pagine delle impostazioni». La testa
// è quella delle stampe e del cron: il percorso nella testa della pagina (non nella barra in alto), «Impostazioni» per tornare, l'icona, la «Storia».
describe('la testa come nelle altre pagine delle impostazioni', () => {
    test('il percorso, il ritorno alle impostazioni, l\'icona e la storia', async () => {
        const visti: Record<string, unknown> = {};
        const conLaTesta = { ...stubs,
            AppLayout: { props: ['breadcrumbs'], setup(p: { breadcrumbs: unknown }) { visti.barra = p.breadcrumbs; }, template: '<div><slot /></div>' },
            PageHeaderGuide: { props: ['pageTitle', 'breadcrumbs', 'backUrl', 'backText', 'icon', 'storia'],
                setup(p: Record<string, unknown>) { Object.assign(visti, { percorso: p.breadcrumbs, ritorno: p.backUrl, parola: p.backText, icona: p.icon, storia: p.storia }); },
                template: '<header />' } };
        w = mount(Pagina as never, { attachTo: document.body, props: { impostazioni: { delega_oltre_il_quinto: 'rifiuta', voto_discorde_comproprietari: 'astenuti', giorni_di_posta: 3 } } as never,
            global: { stubs: conLaTesta } });
        await flushPromises();
        expect(visti.barra).toEqual([]);
        expect((visti.percorso as { title: string }[]).map((b) => b.title)).toEqual(['Impostazioni', 'Le assemblee']);
        expect(visti.ritorno).toBe('/impostazioni');
        expect(visti.parola).toBe('Impostazioni');
        expect(visti.icona).toBeTruthy();
        expect(visti.storia).toEqual({ tipo: 'impostazioni_assemblee' });
    });
});
