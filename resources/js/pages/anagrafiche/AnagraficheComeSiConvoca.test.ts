// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il mezzo dell'art. 66 nella scheda della persona: nei moduli «Nuova» e «Modifica» anagrafica il campo «Come si convoca» (PEC, Raccomandata, A mano,
 * Fax; vuoto lo propone il programma dai dati), con la «i» che dice che l'email semplice non vale e l'esempio di Mario Rossi, e «PEC comunicata dalla persona
 * il»; nella scheda della persona le due righe in sola lettura.
 *
 * Mario Rossi (Int. 3 di Via Roma 12) ha dato per scritto la sua PEC il 02/03/2026: il modulo di modifica manda `mezzo_convocazione` = `pec` e
 * `pec_comunicata_il` = `2026-03-02`; la scheda dice «PEC» e «02/03/2026». Senza scelta, la scheda dice «Lo propone il programma dai dati».
 *
 * Il giorno della PEC si sceglie nel calendario di `CampoGiorno` (un pulsante «gg/mm/aaaa», non più il campo del browser) e si toglie con la «×»: il modulo
 * manda il giorno scelto, e senza giorno `null`.
 *
 * Che cosa non copre: la validazione e il registro delle modifiche (sono del server); il menu `v-select` aperto a mano (qui è un segnaposto, come negli altri
 * test di queste pagine). Le traduzioni non sono caricate: `trans()` restituisce la chiave.
 */
import { beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { router } from '@inertiajs/core';
import { giornoMostrato, scegliIlGiorno } from '@/components/campoGiornoDiProva';

const chiGuarda = vi.hoisted(() => ({ ruoli: [] as string[], permessi: [] as string[] }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { render: () => null },
    usePage: () => ({
        url: '/admin/anagrafiche/7',
        props: { flash: {}, auth: { user: { get roles() { return chiGuarda.ruoli; }, get permissions() { return chiGuarda.permessi; } } } },
    }),
}));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options', 'placeholder'], template: '<div class="v-select-finto">{{ placeholder }}</div>' } }));

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}${parametri?.id ? `/${parametri.id}` : ''}`;
(globalThis as Record<string, unknown>).route = route;

const AnagraficheNew = (await import('./AnagraficheNew.vue')).default;
const AnagraficheEdit = (await import('./AnagraficheEdit.vue')).default;
const AnagraficheView = (await import('./AnagraficheView.vue')).default;

const passa = { template: '<div><slot /></div>' };
const stubs = {
    AppLayout: passa, AnagraficaLayout: passa,
    PageHeaderGuide: { template: '<header><slot name="actions" /></header>' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    VueDatePicker: true,
    HoverCard: passa, HoverCardTrigger: passa, HoverCardContent: passa,
    TooltipProvider: passa, Tooltip: passa, TooltipTrigger: passa, TooltipContent: passa,
};
const monta = (pagina: unknown, props: Record<string, unknown>) => mount(pagina as never, { props: props as never, global: { mocks: { route }, stubs } });

const mario = (piu: Record<string, unknown> = {}) => ({
    id: 7, nome: 'Rossi Mario', indirizzo: 'Via Roma 12, int. 3', codice_fiscale: 'RSSMRA80A01H501U', email: 'mario@example.com', email_secondaria: null,
    pec: 'mario.rossi@pec.it', telefono: null, cellulare: null, note: null, condomini: [{ id: 3, nome: 'Via Roma 12' }], user: null, ...piu,
});

beforeEach(() => {
    chiGuarda.ruoli = ['amministratore'];
    chiGuarda.permessi = ['Accesso pannello amministratore', 'Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche'];
});

describe('i moduli «Nuova» e «Modifica» anagrafica', () => {
    test('hanno «Come si convoca» con la «i» e «PEC comunicata dalla persona il»', () => {
        for (const pagina of [monta(AnagraficheNew, { condomini: [] }), monta(AnagraficheEdit, { anagrafica: mario(), condomini: [] })]) {
            const t = pagina.text().replace(/\s+/g, ' ');
            expect(t).toContain('Come si convoca');
            expect(t).toContain('Lo propone il programma dai dati');
            expect(t).toContain('L\'email semplice non vale come convocazione (art. 66 c. 3; Cass. 16399/2025).');
            expect(t).toContain('Esempio: Mario Rossi ti ha dato per scritto la sua PEC il 02/03/2026: scegli PEC e scrivi quella data.');
            expect(t).toContain('PEC comunicata dalla persona il');
            expect(pagina.find('input[type="date"]').exists()).toBe(false);
            expect(giornoMostrato(pagina.get('button#pec_comunicata_il'))).toBe('gg/mm/aaaa');
        }
    });

    test('il modulo di modifica manda il mezzo e il giorno della PEC comunicata', () => {
        const spia = vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
        const pagina = monta(AnagraficheEdit, { anagrafica: mario({ mezzo_convocazione: 'pec', pec_comunicata_il: '2026-03-02' }), condomini: [] });
        expect(giornoMostrato(pagina.get('button#pec_comunicata_il'))).toBe('02/03/2026');
        (pagina.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();
        const corpo = spia.mock.calls.at(-1)![1] as Record<string, unknown>;
        spia.mockRestore();
        expect(corpo.mezzo_convocazione).toBe('pec');
        expect(corpo.pec_comunicata_il).toBe('2026-03-02');
    });

    test('il giorno della PEC si sceglie nel calendario e si toglie con la «×»: il modulo manda il giorno scelto, poi null', async () => {
        const spia = vi.spyOn(router, 'put').mockImplementation(() => undefined as never);
        const pagina = mount(AnagraficheEdit as never, {
            attachTo: document.body, props: { anagrafica: mario({ mezzo_convocazione: 'pec', pec_comunicata_il: '2026-03-02' }), condomini: [] } as never,
            global: { mocks: { route }, stubs },
        });
        const invia = () => {
            (pagina.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();

            return spia.mock.calls.at(-1)![1] as Record<string, unknown>;
        };
        await scegliIlGiorno(pagina.get('button#pec_comunicata_il'), '2026-03-05');
        expect(giornoMostrato(pagina.get('button#pec_comunicata_il'))).toBe('05/03/2026');
        expect(invia().pec_comunicata_il).toBe('2026-03-05');
        await pagina.get('[aria-label="Togli il giorno"]').trigger('click');
        await flushPromises();
        expect(giornoMostrato(pagina.get('button#pec_comunicata_il'))).toBe('gg/mm/aaaa');
        expect(invia().pec_comunicata_il).toBeNull();
        spia.mockRestore();
        pagina.unmount();
        document.body.innerHTML = '';
    });

    test('il modulo nuovo parte senza mezzo e senza giorno: lo propone il programma', () => {
        const spia = vi.spyOn(router, 'post').mockImplementation(() => undefined as never);
        const pagina = monta(AnagraficheNew, { condomini: [] });
        (pagina.vm as unknown as { $: { setupState: { submit: () => void } } }).$.setupState.submit();
        const corpo = spia.mock.calls.at(-1)![1] as Record<string, unknown>;
        spia.mockRestore();
        expect(corpo.mezzo_convocazione).toBeNull();
        expect(corpo.pec_comunicata_il).toBeNull();
    });
});

describe('la scheda della persona', () => {
    test('dice come si convoca e il giorno della PEC comunicata', () => {
        const t = monta(AnagraficheView, { anagrafica: mario({ mezzo_convocazione: 'pec', pec_comunicata_il: '2026-03-02' }), immobili: [], documenti_count: 0 })
            .text().replace(/\s+/g, ' ');
        expect(t).toContain('Come si convoca');
        expect(t).toMatch(/PEC comunicata dalla persona il\s*02\/03\/2026/);
        expect(t).toMatch(/Come si convoca\s*PEC/);
    });

    test('senza una scelta dice che lo propone il programma, e senza il giorno lo dice', () => {
        const t = monta(AnagraficheView, { anagrafica: mario(), immobili: [], documenti_count: 0 }).text().replace(/\s+/g, ' ');
        expect(t).toContain('Come si convoca');
        expect(t).toContain('Lo propone il programma dai dati');
        expect(t).toMatch(/PEC comunicata dalla persona il\s*non registrato/);
    });
});

describe('la guida delle pagine della persona', () => {
    // La guida in alto (le card di `PageHeaderGuide`) dice «Come si convoca»: nei moduli con la chiave tradotta, nella scheda con la frase.
    const conLaGuida = { ...stubs, PageHeaderGuide: { props: ['guides'], template: '<header><p v-for="g in guides" :key="g.title" class="guida-finta">{{ g.description }}</p><slot name="actions" /></header>' } };
    const guide = (pagina: unknown, props: Record<string, unknown>) => mount(pagina as never, { props: props as never, global: { mocks: { route }, stubs: conLaGuida } })
        .findAll('.guida-finta').map((g) => g.text()).join(' ');

    test('nei moduli «Nuova» e «Modifica» la prima card dice come si convoca', () => {
        expect(guide(AnagraficheNew, { condomini: [] })).toContain('anagrafiche.guides.come_si_convoca_desc');
        expect(guide(AnagraficheEdit, { anagrafica: mario(), condomini: [] })).toContain('anagrafiche.guides.come_si_convoca_desc');
    });

    test('nella scheda la card dei recapiti dice «Come si convoca» e il giorno della PEC comunicata', () => {
        const t = guide(AnagraficheView, { anagrafica: mario(), immobili: [], documenti_count: 0 });
        expect(t).toContain('«Come si convoca» dice il mezzo delle convocazioni e il giorno in cui ti ha comunicato la PEC');
    });
});
