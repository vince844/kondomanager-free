// @vitest-environment jsdom

/**
 * La frase sotto i totali del piano dei conti per una voce di spesa scritta con il meno che sta in tre piani, e la coda della frase secondo quali
 * pagine hanno l'avviso.
 *
 * Con più di due piani i nomi si elencano con la virgola e l'ultimo con «e» («A», «B» e «C»), come negli altri elenchi del programma; ogni nome porta
 * alla pagina del suo piano. La coda rimanda solo alle pagine dei piani il cui avviso nomina la voce (`avviso`), con il loro nome: l'avviso c'è dove il
 * piano ne tiene la copia negativa o ne legge l'importo, non dove ne tiene una copia positiva. Senza nessuna pagina con l'avviso la frase nomina i
 * piani e dice che la addebitano in positivo, senza la cifra: è quella della copia del piano.
 *
 * La pagina riceve `speseNegative` dal server (id, nome, importo scritto in centesimi, piani che la addebitano, in ordine di id, ciascuno con `avviso`):
 * qui è scritto a mano, e `Beta53SpesaNegativaTestiTest` (T297.15-ter, con due piani) prova che il server manda la lista dei piani così; con tre piani
 * la lista ha la stessa forma.
 *
 * Si monta la pagina vera, come in `ContiNewSpesaNegativa.test.ts` (cornice, modali, albero e dettaglio della voce sostituiti).
 *
 * Cosa NON copre: le frasi con uno o due piani tutti con l'avviso e senza piani (`ContiNewSpesaNegativa.test.ts`); il server; il colore e la larghezza
 * a 375 px.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const indirizzo = (nome: string, parametri?: unknown) => `/${nome}?${JSON.stringify(parametri ?? {})}`;

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { delete: vi.fn(), post: vi.fn(), get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string, q?: unknown) => `/${p}?${JSON.stringify(q ?? {})}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
(globalThis as unknown as { route: unknown }).route = indirizzo;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const ContiNew = (await import('@/pages/gestionale/pianiDeiConti/conti/ContiNew.vue')).default;

let pagina: VueWrapper | null = null;

async function monta(extra: Record<string, unknown>) {
    pagina = mount(ContiNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, esercizio: { id: 4, nome: '2026' } as never, esercizi: [], condomini: [],
            pianoConti: { id: 1, nome: 'Piano dei conti', stato: 'bozza' } as never, conti: [], fornitori: [], tabelle: [],
            totalePreventivo: 144000, totaleEntrate: 24000, totaleDaRipartire: 120000, totaleSopravvenienze: 0, totaleConsuntivo: 0, ...extra,
        } as never,
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, ModalNuovoConto: true, ModalModificaConto: true, AlberoDeiConti: true, MovimentiVoceDialog: true,
                DettaglioConto: true, ConfirmDialog: true, ModalAssociaTabella: true, RipartoUsufruttoGuide: true, OperazioniContiGuide: true, GiaVersatoGuide: true,
                Head: { template: '<span />' }, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
            },
            mocks: { route: indirizzo },
        },
    });
    await flushPromises();
}

const normalizza = (t: string | null | undefined) => (t ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
const testo = () => normalizza(document.body.textContent);
const collegamento = (nome: string) => [...document.body.querySelectorAll('a')].find((a) => normalizza(a.textContent) === nome) as HTMLAnchorElement | undefined;

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('la frase della voce di spesa negativa in tre piani', () => {
    test('i nomi con la virgola e l\'ultimo con «e», ognuno con il collegamento alla sua pagina', async () => {
        await monta({ speseNegative: [{ id: 3, nome: 'Rimborso vecchio', importo: -24000, piani: [
            { id: 7, nome: 'Ordinario 2026', avviso: true }, { id: 9, nome: 'Integrativo 2026', avviso: true }, { id: 11, nome: 'Conguaglio 2026', avviso: true },
        ] }] });

        expect(testo()).toContain('il preventivo la conta in positivo, per € 240,00. È nei piani «Ordinario 2026», «Integrativo 2026» e «Conguaglio 2026»: le pagine dei piani dicono che cosa fare.');
        expect(testo()).not.toContain('«Ordinario 2026» e «Integrativo 2026» e');
        for (const [nome, id] of [['Ordinario 2026', '7'], ['Integrativo 2026', '9'], ['Conguaglio 2026', '11']] as const) {
            const a = collegamento(nome);
            expect(a, `il nome di «${nome}» non porta alla sua pagina`).toBeDefined();
            expect(a!.getAttribute('href')).toContain(`"pianoRate":${id}`);
        }
    });
});

describe('la coda della frase rimanda solo alle pagine con l\'avviso', () => {
    const voce = (piani: Array<{ id: number, nome: string, avviso: boolean }>) => ({ speseNegative: [{ id: 3, nome: 'Rimborso vecchio', importo: -24000, piani }] });
    const ordinario = { id: 7, nome: 'Ordinario 2026', avviso: true };
    const integrativo = { id: 9, nome: 'Integrativo 2026', avviso: false };
    const conguaglio = { id: 11, nome: 'Conguaglio 2026', avviso: true };

    test('un piano senza l\'avviso: lo nomina con il collegamento e dice che la addebita in positivo, e non dice che la sua pagina dice che cosa fare', async () => {
        await monta(voce([integrativo]));

        expect(testo()).toContain('il preventivo la conta in positivo, per € 240,00. È nel piano «Integrativo 2026», che la addebita in positivo.');
        expect(testo()).not.toContain('che cosa fare');
        expect(testo()).not.toContain('scegli «Entrata»');
        expect(collegamento('Integrativo 2026')?.getAttribute('href')).toContain('"pianoRate":9');
    });

    test('due piani, l\'avviso su uno: rimanda alla pagina di quello, con il suo nome', async () => {
        await monta(voce([ordinario, integrativo]));

        expect(testo()).toContain('il preventivo la conta in positivo, per € 240,00. È nei piani «Ordinario 2026» e «Integrativo 2026»: la pagina di «Ordinario 2026» dice che cosa fare.');
        expect(testo()).not.toContain('le pagine dei piani');
    });

    test('tre piani, l\'avviso su due: rimanda alle pagine di quei due, con i loro nomi', async () => {
        await monta(voce([ordinario, integrativo, conguaglio]));

        expect(testo()).toContain('È nei piani «Ordinario 2026», «Integrativo 2026» e «Conguaglio 2026»: le pagine di «Ordinario 2026» e «Conguaglio 2026» dicono che cosa fare.');
    });

    test('due piani senza l\'avviso: li nomina e dice che la addebitano in positivo', async () => {
        await monta(voce([{ ...ordinario, avviso: false }, integrativo]));

        expect(testo()).toContain('il preventivo la conta in positivo, per € 240,00. È nei piani «Ordinario 2026» e «Integrativo 2026», che la addebitano in positivo.');
        expect(testo()).not.toContain('che cosa fare');
    });
});
