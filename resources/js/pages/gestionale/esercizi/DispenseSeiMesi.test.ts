// @vitest-environment jsdom

/**
 * La pagina della dispensa dei sei mesi: le dispense registrate con le loro parole (la revocata dice «revocata il …»), e il modulo che non
 * parte senza la data, gli esercizi e la scelta «per tutte le persone» o «solo per alcune», che non è già fatta. Aperta e mandata senza toccare
 * niente, non manda niente.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: (...a: unknown[]) => post(...a) },
    usePage: () => ({ props: {} }),
}));
let precedente: string | null = null;
vi.mock('@/composables/useUrlPrecedente', () => ({ useUrlPrecedente: () => ({ urlPrecedente: () => precedente }) }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}` }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Pagina = (await import('./DispenseSeiMesi.vue')).default;

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; post.mockReset(); });

const monta = () => {
    w = mount(Pagina, {
        props: {
            condominio: { id: 3, nome: 'Condominio Via dei Pini' }, condomini: [], esercizio: null,
            esercizi: [{ id: 1, nome: 'Esercizio 2026', stato: 'aperto' }], persone: [{ id: 7, nome: 'Ugo' }],
            dispense: [
                { id: 1, data_delibera: '2027-03-20', numero_verbale: '12', nota: null, tutte_le_persone: true, esercizi: [{ id: 1, nome: 'Esercizio 2026' }], persone: [], revocata_il: '2027-06-01', nota_revoca: null, verbale: null },
            ],
            testo_norma: '«Salvo che…»', oggi: '2027-07-10',
        } as any,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true } },
    });

    return w;
};

describe('la pagina della dispensa', () => {
    test('la dispensa registrata dice la delibera, il verbale e la revoca', () => {
        const testo = monta().get('[data-testid="dispensa"]').text();
        expect(testo).toContain('Assemblea del 20/03/2027');
        expect(testo).toContain('verbale n. 12');
        expect(testo).toContain('revocata il 01/06/2027');
    });

    test('il modulo non parte senza la data, gli esercizi e la scelta delle persone, e la scelta non è già fatta', async () => {
        const pagina = monta();
        const pulsante = () => pagina.findAll('button').find((b) => b.text() === 'Registra la dispensa')!;
        expect(pulsante().attributes('disabled')).toBeDefined();
        expect((pagina.findAll('input[type="radio"]') as any[]).map((r) => (r.element as HTMLInputElement).checked)).toEqual([false, false]);
        await pulsante().trigger('click');
        expect(post).not.toHaveBeenCalled();
    });
});

describe('la pagina della dispensa, dopo il riordino', () => {
    test('l\'intestazione ha le tre schede di guida e «Indietro», che senza una pagina di partenza porta alle rate scadute', () => {
        const intestazione = monta().findComponent({ name: 'PageHeaderGuide' });
        expect((intestazione.props('guides') as Array<{ title: string }>).map((g) => g.title)).toEqual(['A che cosa serve', 'Che cosa cambia', 'Che cosa ti serve']);
        expect(intestazione.props('backUrl')).toBe('/gestionale.rate-scadute.index');
        expect(intestazione.props('backText')).toBe('Indietro');
    });

    test('il numero del verbale ha la sua spiegazione e un esempio', () => {
        const pagina = monta();
        expect(pagina.get('[data-testid="numero-verbale"]').attributes('placeholder')).toBe('per esempio 3/2027');
        expect(pagina.findAll('[data-testid="spiegazione-filtro"]').map((s) => s.attributes('aria-label')).join(' ')).toContain('Il numero scritto sul verbale dell\'assemblea');
    });

    test('il file si sceglie con un pulsante in italiano, il nome si legge accanto, si toglie, e parte con la dispensa', async () => {
        const pagina = monta();
        const campo = pagina.get('[data-testid="file-verbale"]');
        const clic = vi.spyOn(campo.element as HTMLInputElement, 'click');
        expect(pagina.get('[data-testid="scegli-file"]').text()).toBe('Scegli il file');
        expect(pagina.get('[data-testid="nome-file"]').text()).toBe('Nessun file scelto');
        await pagina.get('[data-testid="scegli-file"]').trigger('click');
        expect(clic).toHaveBeenCalled();

        const file = new File(['%PDF'], 'verbale-3-2027.pdf', { type: 'application/pdf' });
        Object.defineProperty(campo.element, 'files', { value: [file], configurable: true });
        await campo.trigger('change');
        expect(pagina.get('[data-testid="nome-file"]').text()).toBe('verbale-3-2027.pdf');
        expect(pagina.get('[data-testid="scegli-file"]').text()).toBe('Cambia il file');
        await pagina.get('[data-testid="togli-file"]').trigger('click');
        expect(pagina.get('[data-testid="nome-file"]').text()).toBe('Nessun file scelto');

        await campo.trigger('change');
        await pagina.get('input[type="date"]').setValue('2027-03-20');
        await pagina.findAll('[type="checkbox"], button[role="checkbox"]')[0].trigger('click');
        await pagina.findAll('input[type="radio"]')[0].setValue(true);
        await pagina.findAll('button').find((b) => b.text() === 'Registra la dispensa')!.trigger('click');
        expect(post).toHaveBeenCalledTimes(1);
        expect((post.mock.calls[0][1] as Record<string, unknown>).verbale).toBe(file);
    });

    test('«solo per alcune persone» si scelgono da un menu con la ricerca, e partono con la dispensa', async () => {
        const pagina = monta();
        await pagina.get('input[type="date"]').setValue('2027-03-20');
        await pagina.findAll('button[role="checkbox"]')[0].trigger('click');
        await pagina.findAll('input[type="radio"]')[1].setValue(true);
        await pagina.get('[data-testid="scelta-persone"] .vs__search').trigger('focus');
        await flushPromises();
        await pagina.findAll('[data-testid="scelta-persone"] .vs__dropdown-option').find((o) => o.text() === 'Ugo')!.trigger('click');
        await pagina.findAll('button').find((b) => b.text() === 'Registra la dispensa')!.trigger('click');
        expect(post).toHaveBeenCalledTimes(1);
        expect((post.mock.calls[0][1] as Record<string, unknown>).persone).toEqual([7]);
        expect((post.mock.calls[0][1] as Record<string, unknown>).per_tutti).toBe('0');
    });

    test('il pulsante «Guida» dell\'intestazione apre la guida di lato, con il testo della norma', async () => {
        const pagina = monta();
        const intestazione = pagina.findComponent({ name: 'PageHeaderGuide' });
        expect(intestazione.props('hasTextGuide')).toBe(true);
        expect(intestazione.props('textGuideTitle')).toBe('Guida');
        const guida = () => pagina.findComponent({ name: 'DispenseSeiMesiGuide' });
        expect(guida().props('open')).toBe(false);
        intestazione.vm.$emit('open-text-guide');
        await flushPromises();
        expect(guida().props('open')).toBe(true);
        expect(guida().props('testoNorma')).toBe('«Salvo che…»');
    });

    test('«Indietro» resta la pagina di partenza anche dopo una registrazione, che ricarica la pagina stessa', () => {
        precedente = '/gestionale/3/esercizi';
        expect(monta().findComponent({ name: 'PageHeaderGuide' }).props('backUrl')).toBe('/gestionale/3/esercizi');
        w!.unmount();
        // Dopo «Registra la dispensa» la pagina precedente è la pagina stessa (con o senza parametri).
        precedente = `${window.location.pathname}?ok=1`;
        expect(monta().findComponent({ name: 'PageHeaderGuide' }).props('backUrl')).toBe('/gestionale/3/esercizi');
        precedente = null;
    });
});

