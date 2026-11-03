// @vitest-environment jsdom

/**
 * La pagina del piano dopo il rifiuto del ricalcolo per una voce di spesa negativa: la via d'uscita è raggiungibile.
 *
 * Il ricalcolo si ferma su una voce di spesa con l'importo negativo e dice di toglierla dal piano («Rimuovi voce e ricalcola») e di riaggiungerla
 * (`Beta52VoceEntrataFermoTest`). La via d'uscita sta nella pagina del piano: l'elenco «Copertura spese» con il cestino accanto alla voce, spento se il piano è
 * approvato o fermo. Si monta la pagina intera (si stubbano la cornice, la guida, il selettore di date, il pannello laterale, il modale di «Sposta spesa» e gli
 * avvisi dei destinatari; il riquadro dell'avviso e la conferma sono veri) e si legge come la legge chi corregge:
 * il rifiuto sta in cima in un riquadro che non si chiude da solo; con il piano in bozza il cestino è acceso e porta alla conferma, che invia la rimozione
 * della voce giusta; con il piano approvato il cestino è spento, ed è per questo che il rifiuto dice di riportare prima il piano in bozza.
 *
 * La conferma del cestino dice che la rimozione cancella i tratti di competenza della voce, che si riscrivono solo ricreando il piano.
 *
 * Con il piano approvato il rifiuto dice di riportarlo prima in bozza: l'interruttore «Approvato» è acceso (non ci sono rate emesse) e il suo clic invia
 * `piani-rate.update-stato` con `approvato: false`, cioè la strada che il testo indica.
 *
 * Il fermo non entra in `fermo_del_piano`: spegnerebbe anche il cestino, cioè la via d'uscita. La pagina qui riceve `fermo_del_piano: null` scritto a mano, quindi
 * quella garanzia sta sul server: `Beta52VoceEntrataFermoTest` legge la proprietà che la pagina del piano con una voce negativa manda davvero.
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 *
 * Cosa resta scoperto: il server (`Beta52VoceEntrataFermoTest`); il pulsante «Sincronizza» che riaggiunge la voce (esiste già e lo prova il flusso del piano); la
 * conferma di approvazione con la data della delibera; le larghezze e il modo scuro.
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

const RIFIUTO = 'Il piano non si ricalcola: la voce di spesa «Rimborso» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00. Il piano tiene una copia dell\'importo della voce e correggere la voce non la cambia: togli la voce dal piano con «Rimuovi voce e ricalcola» e riaggiungila con «Sincronizza». Togliendola si perdono i suoi tratti di competenza, se ne ha: annotali prima e riscrivili ricreando il piano.';

let pagina: VueWrapper | null = null;

async function monta(stato: 'bozza' | 'approvato') {
    pagina = mount(PianiRateShow, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, esercizio: { id: 1, nome: '2026' } as never,
            pianoRate: { id: 5, nome: 'Preventivo 2026', stato, tipo: 'ordinario', numero_rate: 12, totale_capitoli: 144000, budget_movements: [],
                capitoli: [{ id: 2, nome: 'Rimborso', importo: -24000, importo_originale: -24000, is_parent: false }] },
            quotePerAnagrafica: [], quotePerImmobile: [], ratePure: [], needsMigration: false, sources: [], destinations: [], copertura: null, fermo_del_piano: null,
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

beforeEach(() => {
    router.delete.mockClear();
    router.put.mockClear();
    flash.message = { type: 'error', message: RIFIUTO };
});

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('dopo il rifiuto del ricalcolo', () => {
    test('controprova — il rifiuto sta in cima alla pagina e con il piano in bozza il cestino della voce è acceso', async () => {
        await monta('bozza');
        await apriLeVoci();

        expect(document.body.textContent).toContain('togli la voce dal piano con «Rimuovi voce e ricalcola» e riaggiungila con «Sincronizza»');
        expect(cestino()).not.toBeNull();
        expect(cestino()!.disabled).toBe(false);
    });

    test('controprova — il cestino porta alla conferma e la conferma invia la rimozione della voce giusta', async () => {
        await monta('bozza');
        await apriLeVoci();

        cestino()!.click();
        await flushPromises();
        const conferma = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.includes('Sì, rimuovi e ricalcola')) as HTMLButtonElement;
        expect(conferma).toBeTruthy();
        conferma.click();
        await flushPromises();

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(String(router.delete.mock.calls[0][0])).toContain('piani-rate.capitoli.detach');
        expect(String(router.delete.mock.calls[0][0])).toContain('"capitolo":2');
    });

    test('la conferma del cestino dice che i tratti di competenza della voce si perdono con la rimozione, e che si riscrivono solo ricreando il piano', async () => {
        await monta('bozza');
        await apriLeVoci();

        cestino()!.click();
        await flushPromises();
        const testo = (document.body.textContent ?? '').replace(/\s+/g, ' ');

        expect(testo).toContain('Rimuovere voce dal piano?');
        expect(testo).toContain('Se la voce ha dei tratti di competenza, con la rimozione si perdono: annotali prima, perché si riscrivono solo ricreando il piano.');
    });

    test('controprova — con il piano approvato il cestino è spento: per questo il rifiuto dice di riportarlo prima in bozza', async () => {
        await monta('approvato');
        await apriLeVoci();

        expect(cestino()).not.toBeNull();
        expect(cestino()!.disabled).toBe(true);
    });

    test('controprova — con il piano approvato l\'interruttore «Approvato» è acceso e il suo clic riporta il piano in bozza', async () => {
        await monta('approvato');

        const interruttore = document.body.querySelector('button[role="switch"]') as HTMLButtonElement;
        expect(interruttore.disabled).toBe(false);
        expect(interruttore.getAttribute('aria-checked')).toBe('true');
        expect(document.body.textContent).toContain('Approvato');

        interruttore.click();
        await flushPromises();

        expect(router.put).toHaveBeenCalledTimes(1);
        expect(String(router.put.mock.calls[0][0])).toContain('piani-rate.update-stato');
        expect(router.put.mock.calls[0][1]).toMatchObject({ approvato: false });
    });
});
