// @vitest-environment jsdom

/**
 * La pagina del piano mostra l'avviso delle righe sovrapposte: un capitolo che ha la sua riga insieme a quelle dei suoi sottoconti.
 *
 * Il motore passa la quota del capitolo ai sottoconti e salta la loro riga: il piano chiede la somma di tutte le righe e ne ripartisce meno, «Ricalcola»
 * non lo corregge e «Sposta spesa» non offre più quelle voci come destinazione. Il server manda `avviso_righe_sovrapposte` (titolo, voci, situazione, strade:
 * `Beta52SpostaSpesaRegolaDelMotoreTest`) e la pagina lo mostra in un riquadro in cima, fisso: resta finché le righe sono sovrapposte. L'uscita che dice
 * (togliere il capitolo con «Rimuovi voce e ricalcola», dopo lo storno dello spostamento che gli ha dato budget) è quella che la pagina ha: il cestino della
 * voce porta alla conferma.
 *
 * Si monta la pagina intera, come `PianiRateShowAvvisoSpesaNegativa.test.ts` (si stubbano cornice, guida, selettore di date, pannello, modale di «Sposta
 * spesa» e avvisi dei destinatari; la conferma e il cestino sono veri) e si legge come la legge chi corregge.
 *
 * I test che cominciano con «controprova» proteggono ciò che l'avviso non deve fare: il riquadro non c'è senza righe sovrapposte, e non toglie né sposta
 * l'avviso della voce di spesa negativa.
 *
 * Cosa resta scoperto: il server (`Beta52SpostaSpesaRegolaDelMotoreTest`); le larghezze e il modo scuro (si guardano a video).
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const router = vi.hoisted(() => ({
    delete: vi.fn(),
    put: vi.fn(),
    post: vi.fn(),
    get: vi.fn(),
    reload: vi.fn((opzioni?: { onFinish?: () => void }) => opzioni?.onFinish?.()),
}));
const flash = vi.hoisted(() => ({ message: undefined as undefined | { type: string; message: string } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router,
    usePage: () => ({ props: { flash, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}?${JSON.stringify(parametri ?? {})}`;

const PianiRateShow = (await import('@/pages/gestionale/pianiRate/PianiRateShow.vue')).default;

type Avviso = { titolo: string; voci: string[]; situazione: string; strade: string[] };

const USCITA = 'Togli il capitolo «Manutenzione» dal piano con «Rimuovi voce e ricalcola»; se il capitolo ha ricevuto budget con «Sposta spesa», storna prima lo spostamento dallo storico (icona dell\'orologio, accanto all\'importo).';

const AVVISO: Avviso = {
    titolo: 'Nel piano un capitolo ha la sua riga insieme a quelle dei suoi sottoconti',
    voci: ['Il capitolo «Manutenzione» ha la sua riga e anche i suoi sottoconti «Ascensore» e «Caldaia» hanno la loro: il piano conta solo quella del capitolo.'],
    situazione: 'La somma delle righe non è quella che il piano ripartisce: «Ricalcola» non lo corregge, e «Sposta spesa» non offre queste voci come destinazione.',
    strade: [USCITA],
};

const AVVISO_SPESA_NEGATIVA: Avviso = {
    titolo: 'Il piano contiene una voce di spesa con un importo negativo',
    voci: ['La voce «Rimborso» ha un importo negativo (meno € 240,00) e il piano la addebita in positivo, per € 240,00.'],
    situazione: '«Ricalcola» si ferma finché nel piano c\'è una voce di spesa negativa: il piano resta com\'è, con la cifra sbagliata.',
    strade: ['Correggi l\'importo della voce nel piano dei conti.'],
};

let pagina: VueWrapper | null = null;

async function monta(avviso: Avviso | null | undefined, extra: Record<string, unknown> = {}) {
    pagina = mount(PianiRateShow, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, esercizio: { id: 1, nome: '2026' } as never,
            pianoRate: { id: 5, nome: 'Preventivo 2026', stato: 'bozza', tipo: 'ordinario', numero_rate: 12, totale_capitoli: 220000, budget_movements: [],
                capitoli: [{ id: 2, nome: 'Manutenzione', importo: 10000, importo_originale: 10000, is_parent: false }] },
            quotePerAnagrafica: [], quotePerImmobile: [], ratePure: [], needsMigration: false, sources: [], destinations: [], copertura: null, fermo_del_piano: null,
            ...(avviso === undefined ? {} : { avviso_righe_sovrapposte: avviso }), ...extra,
        },
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, VueDatePicker: true, PianoRateSpaccatoSheet: true, ModalSpostaSpesa: true, BudgetHistoryPopover: true, DestinatariWarning: true, DestinatariWarningDefunto: true, ScopertoWarning: true, Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' } }, mocks: { route: (n: string, p?: Record<string, unknown>) => `/${n}?${JSON.stringify(p ?? {})}` } },
    });
    await flushPromises();
}

const apriLeVoci = async () => {
    const intestazione = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.includes('Copertura spese')) as HTMLButtonElement;
    intestazione.click();
    await flushPromises();
    await new Promise((r) => setTimeout(r, 150));
    await flushPromises();
};

const cestino = () => document.body.querySelector('button[title="Rimuovi voce e ricalcola"]') as HTMLButtonElement | null;
const riquadro = () => [...document.body.querySelectorAll('[role="alert"]')].find((r) => r.textContent?.includes('riga insieme a quelle')) as HTMLElement | undefined;
const riquadroSpesaNegativa = () => [...document.body.querySelectorAll('[role="alert"]')].find((r) => r.textContent?.includes('importo negativo')) as HTMLElement | undefined;
const testoDi = (el: Element | undefined) => (el?.textContent ?? '').replace(/\s+/g, ' ').trim();

beforeEach(() => {
    router.delete.mockClear();
    flash.message = undefined;
});

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('l\'avviso in cima alla pagina', () => {
    test('nomina il capitolo e i sottoconti, dice che «Ricalcola» non lo corregge e indica l\'uscita', async () => {
        await monta(AVVISO);

        const testo = testoDi(riquadro());
        expect(testo).toContain(AVVISO.titolo);
        expect(testo).toContain(AVVISO.voci[0]);
        expect(testo).toContain(AVVISO.situazione);
        expect(testo).toContain('Come uscirne');
        expect(testo).toContain(USCITA);
        expect(riquadro()!.querySelectorAll('li').length).toBe(1);
    });

    test('non è un messaggio che passa: sta in pagina anche senza nessun messaggio di esito', async () => {
        flash.message = undefined;

        await monta(AVVISO);

        expect(riquadro()).toBeDefined();
    });

    test('con più capitoli li elenca una per riga, con la sua uscita', async () => {
        await monta({
            titolo: 'Nel piano 2 capitoli hanno la loro riga insieme a quelle dei loro sottoconti',
            voci: [AVVISO.voci[0], 'Il capitolo «Impianti» ha la sua riga e anche il suo sottoconto «Antenna» ha la sua: il piano conta solo quella del capitolo.'],
            situazione: AVVISO.situazione,
            strade: [USCITA, USCITA.replace('«Manutenzione»', '«Impianti»')],
        });

        const testo = testoDi(riquadro());
        expect(testo).toContain('Nel piano 2 capitoli hanno la loro riga');
        expect(testo).toContain('«Impianti»');
        expect(riquadro()!.querySelectorAll('li').length).toBe(2 + 2);
    });

    test('non ha pulsanti: la scelta è dell\'amministratore', async () => {
        await monta(AVVISO);

        expect(riquadro()!.querySelectorAll('button, a').length).toBe(0);
    });
});

describe('l\'uscita che il riquadro dice è quella che la pagina ha', () => {
    test('il cestino della voce si chiama come lo nomina il riquadro, è acceso in bozza e porta alla conferma', async () => {
        await monta(AVVISO);
        await apriLeVoci();

        expect(testoDi(riquadro())).toContain(`«${cestino()!.title}»`);
        expect(cestino()!.disabled).toBe(false);

        cestino()!.click();
        await flushPromises();
        const conferma = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.includes('Sì, rimuovi e ricalcola')) as HTMLButtonElement;
        expect(conferma).toBeTruthy();
        conferma.click();
        await flushPromises();

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(String(router.delete.mock.calls[0][0])).toContain('piani-rate.capitoli.detach');
    });
});

describe('dove l\'avviso non c\'è', () => {
    test.each([['nullo', null], ['assente', undefined]])('controprova — con l\'avviso %s il riquadro non compare', async (_nome, avviso) => {
        await monta(avviso as null | undefined);

        expect(riquadro()).toBeUndefined();
        expect(document.body.textContent).not.toContain('riga insieme a quelle');
    });

    test('controprova — con anche l\'avviso della voce di spesa negativa i due riquadri ci sono, ognuno con il suo testo', async () => {
        await monta(AVVISO, { avviso_spesa_negativa: AVVISO_SPESA_NEGATIVA });

        expect(riquadro()).toBeDefined();
        expect(riquadroSpesaNegativa()).toBeDefined();
        expect(testoDi(riquadro())).not.toContain('importo negativo');
        expect(testoDi(riquadroSpesaNegativa())).not.toContain('riga insieme a quelle');
    });
});
