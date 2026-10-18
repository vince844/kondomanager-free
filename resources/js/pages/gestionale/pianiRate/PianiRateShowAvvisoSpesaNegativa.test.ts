// @vitest-environment jsdom

/**
 * La pagina del piano mostra l'avviso della voce di spesa negativa, in ogni stato del piano, senza spegnere le vie d'uscita.
 *
 * Con incassi, rate emesse o un passaggio che ha conguagliato il piano il ricalcolo, la rimozione di una voce e l'eliminazione rifiutano già: il fermo di una
 * voce di spesa negativa non si raggiunge, e nessuno dice che la voce è stata addebitata in positivo. Il server manda `avviso_spesa_negativa` (titolo, voci,
 * situazione, strade: `Beta52VoceEntrataAvvisoTest`) e la pagina lo mostra in un riquadro in cima, fisso: non è un messaggio che passa, resta finché la voce è negativa.
 * Si monta la pagina intera, come `PianiRateShowSpesaNegativa.test.ts` (si stubbano cornice, guida, selettore di date, pannello, modale di «Sposta spesa» e avvisi dei
 * destinatari; la conferma e il cestino sono veri) e si legge come la legge chi corregge.
 *
 * L'avviso non spegne il cestino «Rimuovi voce e ricalcola»: con il piano in bozza resta acceso e porta alla conferma; approvato o fermo resta spento per le sue
 * ragioni di prima, e l'avviso dice che cosa fare. Nessuna strada ha un pulsante: la scelta è dell'amministratore. Il server manda l'avviso e il fermo del piano
 * insieme (rate emesse): il riquadro c'è anche allora, e i test lo montano con i due.
 *
 * I test che cominciano con «controprova» proteggono ciò che l'avviso non deve fare: il riquadro non c'è senza voci negative, e il cestino non cambia.
 *
 * Cosa resta scoperto: il server (`Beta52VoceEntrataAvvisoTest`); le larghezze e il modo scuro (si guardano a video).
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

const AVVISO_BOZZA: Avviso = {
    titolo: 'Il piano contiene una voce di spesa con un importo negativo',
    voci: ['La voce «Rimborso» ha un importo negativo (meno € 240,00) e il piano la addebita in positivo, per € 240,00.'],
    situazione: '«Ricalcola» si ferma finché nel piano c\'è una voce di spesa negativa: il piano resta com\'è, con la cifra sbagliata.',
    strade: [
        'Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno.',
        'Il piano tiene una copia dell\'importo della voce e correggere la voce non la cambia: togli la voce dal piano con «Rimuovi voce e ricalcola» e riaggiungila con «Sincronizza». Togliendola si perdono i suoi tratti di competenza, se ne ha: annotali prima e riscrivili ricreando il piano.',
    ],
};

const COSTO_DELLA_BOZZA = 'si azzerano il numero del verbale e la nota dell\'approvazione: annotali prima';

/** Il fermo che il server manda con le rate emesse: la pagina lo legge per spegnere il cestino, l'avviso è un'altra proprietà. */
const FERMO_CON_RATE_EMESSE = { ragioni: ['ha già quote a giornale'], passaggi: [], rimedi: ['annulla le emissioni delle rate'] };

const AVVISO_FERMO: Avviso = {
    titolo: 'Il piano contiene una voce di spesa con un importo negativo',
    voci: AVVISO_BOZZA.voci,
    situazione: 'Il piano ha già quote a giornale: «Ricalcola», «Rimuovi voce e ricalcola» ed «Elimina» lo rifiutano, quindi nessuno dei tre arriva a togliere la voce negativa dalle quote.',
    strade: [
        `Per riscrivere il piano annulla le emissioni delle rate e riportalo in bozza (${COSTO_DELLA_BOZZA}), poi correggi come segue.`,
        ...AVVISO_BOZZA.strade,
        'Se il piano non si può più riscrivere (incassi o crediti usati da tenere, o un passaggio di titolarità che l\'ha conguagliato), regola la differenza con un saldo sulle unità, da «Saldi iniziali»: questo piano non lo legge più, entra nel prossimo piano della gestione che applica i saldi, o nell\'esercizio dopo. Il Wallet tiene un saldo per persona, gestione, unità ed esercizio, senza contare quelli scritti da un passaggio: dove la persona ne ha già uno, si corregge quello, se nessun piano l\'ha ancora assorbito o se il piano che l\'ha assorbito si può ancora riscrivere; se l\'ha già assorbito un piano che non si riscrive più, il Wallet non accetta né un secondo saldo né la correzione, e per quella persona la differenza va nell\'esercizio dopo. Se il saldo è fra quelli da cui una successione ha calcolato l\'arretrato agli eredi, il Wallet lo dice e indica la strada.',
    ],
};

let pagina: VueWrapper | null = null;

async function monta(stato: 'bozza' | 'approvato', avviso: Avviso | null | undefined, extra: Record<string, unknown> = {}) {
    pagina = mount(PianiRateShow, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, esercizio: { id: 1, nome: '2026' } as never,
            pianoRate: { id: 5, nome: 'Preventivo 2026', stato, tipo: 'ordinario', numero_rate: 12, totale_capitoli: 144000, budget_movements: [],
                capitoli: [{ id: 2, nome: 'Rimborso', importo: -24000, importo_originale: -24000, is_parent: false }] },
            quotePerAnagrafica: [], quotePerImmobile: [], ratePure: [], needsMigration: false, sources: [], destinations: [], copertura: null, fermo_del_piano: null,
            ...(avviso === undefined ? {} : { avviso_spesa_negativa: avviso }), ...extra,
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
const riquadro = () => [...document.body.querySelectorAll('[role="alert"]')].find((r) => r.textContent?.includes('importo negativo')) as HTMLElement | undefined;
const testoDi = (el: Element | undefined) => (el?.textContent ?? '').replace(/\s+/g, ' ').trim();

beforeEach(() => {
    router.delete.mockClear();
    router.put.mockClear();
    flash.message = undefined;
});

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('l\'avviso in cima alla pagina', () => {
    test('in bozza nomina la voce con la cifra, dice che «Ricalcola» si ferma ed elenca come correggere', async () => {
        await monta('bozza', AVVISO_BOZZA);

        const testo = testoDi(riquadro());
        expect(testo).toContain('Il piano contiene una voce di spesa con un importo negativo');
        expect(testo).toContain(AVVISO_BOZZA.voci[0]);
        expect(testo).toContain(AVVISO_BOZZA.situazione);
        AVVISO_BOZZA.strade.forEach((s) => expect(testo).toContain(s));
        expect(riquadro()!.querySelectorAll('li').length).toBe(AVVISO_BOZZA.strade.length);
    });

    test('non è un messaggio che passa: sta in pagina anche senza nessun messaggio di esito', async () => {
        flash.message = undefined;

        await monta('bozza', AVVISO_BOZZA);

        expect(riquadro()).toBeDefined();
    });

    test('con più voci le elenca una per riga, e il titolo dice quante sono', async () => {
        await monta('bozza', {
            ...AVVISO_BOZZA,
            titolo: 'Il piano contiene 2 voci di spesa con un importo negativo',
            voci: [AVVISO_BOZZA.voci[0], 'La voce «Rimborso B» ha un importo negativo (meno € 100,00) e il piano la addebita in positivo, per € 100,00.'],
        });

        expect(testoDi(riquadro())).toContain('Il piano contiene 2 voci di spesa con un importo negativo');
        expect(testoDi(riquadro())).toContain('«Rimborso B»');
        expect(riquadro()!.querySelectorAll('li').length).toBe(2 + AVVISO_BOZZA.strade.length);
    });

    test('con rate emesse o incassi dice che le strade rifiutano e indica il saldo sulle unità, senza pulsanti che lo facciano da soli', async () => {
        await monta('approvato', AVVISO_FERMO, { ratePure: [{ id: 9, numero_rata: 1, is_emessa: true, is_published: true, totale_rata: 1200 }], fermo_del_piano: FERMO_CON_RATE_EMESSE });

        const testo = testoDi(riquadro());
        expect(testo).toContain('«Ricalcola», «Rimuovi voce e ricalcola» ed «Elimina» lo rifiutano');
        expect(testo).toContain('annulla le emissioni delle rate');
        expect(testo).toContain('regola la differenza con un saldo sulle unità');
        // dice dove il saldo entra e che cosa fare dove la persona ne ha già uno
        expect(testo).toContain('entra nel prossimo piano della gestione che applica i saldi, o nell\'esercizio dopo');
        expect(testo).toContain('dove la persona ne ha già uno, si corregge quello, se nessun piano l\'ha ancora assorbito o se il piano che l\'ha assorbito si può ancora riscrivere');
        expect(riquadro()!.querySelectorAll('button, a').length).toBe(0);
    });

    test('con il fermo del piano il riquadro c\'è lo stesso, e il cestino è spento per il fermo: sono due proprietà diverse', async () => {
        await monta('bozza', AVVISO_FERMO, { ratePure: [{ id: 9, numero_rata: 1, is_emessa: true, is_published: true, totale_rata: 1200 }], fermo_del_piano: FERMO_CON_RATE_EMESSE });
        await apriLeVoci();

        expect(riquadro()).toBeDefined();
        expect(testoDi(riquadro())).toContain('«Ricalcola», «Rimuovi voce e ricalcola» ed «Elimina» lo rifiutano');
        expect(cestino()).not.toBeNull();
        expect(cestino()!.disabled).toBe(true);
    });
});

describe('le vie d\'uscita restano com\'erano', () => {
    test('controprova — con il piano in bozza il cestino della voce è acceso e porta alla conferma, con l\'avviso in pagina', async () => {
        await monta('bozza', AVVISO_BOZZA);
        await apriLeVoci();

        expect(riquadro()).toBeDefined();
        expect(cestino()).not.toBeNull();
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

    test('controprova — con il piano approvato il cestino resta spento per la sua ragione e l\'avviso dice di riportare prima il piano in bozza', async () => {
        const avviso = { ...AVVISO_BOZZA, situazione: 'Il piano è approvato: «Ricalcola» si ferma finché nel piano c\'è una voce di spesa negativa, e per correggerlo va prima riportato in bozza.', strade: [`Riporta il piano in bozza (${COSTO_DELLA_BOZZA}).`, ...AVVISO_BOZZA.strade] };
        await monta('approvato', avviso);
        await apriLeVoci();

        expect(testoDi(riquadro())).toContain(`Riporta il piano in bozza (${COSTO_DELLA_BOZZA}).`);
        expect(cestino()).not.toBeNull();
        expect(cestino()!.disabled).toBe(true);
    });

    test('controprova — l\'interruttore «Approvato» resta acceso e riporta il piano in bozza: è la strada che l\'avviso indica', async () => {
        await monta('approvato', { ...AVVISO_BOZZA, strade: [`Riporta il piano in bozza (${COSTO_DELLA_BOZZA}).`, ...AVVISO_BOZZA.strade] });

        const interruttore = document.body.querySelector('button[role="switch"]') as HTMLButtonElement;
        expect(interruttore.disabled).toBe(false);
        interruttore.click();
        await flushPromises();

        expect(router.put).toHaveBeenCalledTimes(1);
        expect(router.put.mock.calls[0][1]).toMatchObject({ approvato: false });
    });
});

describe('dove l\'avviso non c\'è', () => {
    test.each([['nullo', null], ['assente', undefined]])('controprova — con l\'avviso %s il riquadro non compare e il cestino è acceso', async (_nome, avviso) => {
        await monta('bozza', avviso as null | undefined);
        await apriLeVoci();

        expect(riquadro()).toBeUndefined();
        expect(document.body.textContent).not.toContain('con un importo negativo');
        expect(cestino()!.disabled).toBe(false);
    });
});
