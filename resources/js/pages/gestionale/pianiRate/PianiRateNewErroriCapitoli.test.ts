// @vitest-environment jsdom

/**
 * La schermata di creazione del piano mostra il rifiuto dell'importo di una voce nella configurazione, accanto a «Salva piano rate».
 *
 * Il server rifiuta un importo negativo sulla voce di un piano (`capitoli_config.N.importo`, `Beta52VoceEntrataImportoNegativoCasiTest`). La pagina mostra,
 * sopra il pulsante, il messaggio del server e gli errori delle fatture nel carrello (`fatture_config…`): un errore di un'altra chiave non compare da nessuna
 * parte e «Salva piano rate» si riabilita senza dire perché. Il campo dell'importo non permette il segno, quindi il rifiuto arriva solo da una richiesta
 * costruita, ma il rifiuto nuovo ha una via per essere letto: la pagina mostra anche gli errori di `capitoli_config`.
 *
 * Il rifiuto della creazione per una voce di spesa negativa arriva come messaggio del server («Il piano non si crea …»): sta nello stesso riquadro, sopra «Salva piano
 * rate», e il pulsante resta acceso per rimandare il modulo dopo aver corretto la voce nel piano dei conti, come il testo dice.
 *
 * Si monta la pagina vera (con la cornice, la guida e i moduli di contorno sostituiti) e si scrive l'errore sul modulo come fa Inertia dopo un rifiuto.
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 *
 * Cosa resta scoperto: il server; il resto della schermata di creazione (le fatture, i saldi, la ricorrenza, le competenze), provato a video.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })), post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));
const flash = vi.hoisted(() => ({ message: undefined as undefined | { type: string; message: string } }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { flash, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const PianiRateNew = (await import('@/pages/gestionale/pianiRate/PianiRateNew.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    PianoRateGuide: true,
    ScopertoWarning: true,
    DestinatariWarning: true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
};

let pagina: VueWrapper | null = null;

async function monta() {
    pagina = mount(PianiRateNew, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never, esercizio: { id: 41, nome: '2026' } as never,
            gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', tipo: 'ordinaria', data_inizio: '2026-01-01' }] as never,
            saldoInfo: { saldo: 0, applicabile: false, motivo: '' }, anagraficheDisponibili: [],
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
}

const form = () => (pagina!.vm as any).form;

afterEach(() => {
    flash.message = undefined;
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('gli errori sopra «Salva piano rate»', () => {
    test('l\'importo negativo di una voce della configurazione si legge, con il suo messaggio', async () => {
        await monta();

        form().errors = { 'capitoli_config.1.importo': 'L\'importo di una voce nel piano non può essere negativo. Scrivi l\'importo senza il segno: lo decide il tipo della voce.' };
        await flushPromises();

        expect(document.body.textContent).toContain('L\'importo di una voce nel piano non può essere negativo. Scrivi l\'importo senza il segno: lo decide il tipo della voce.');
    });

    test('controprova — l\'errore di una fattura del carrello si legge ancora', async () => {
        await monta();

        form().errors = { 'fatture_config.0.id': 'La fattura FT-12 è stata stornata: toglila dal carrello.' };
        await flushPromises();

        expect(document.body.textContent).toContain('La fattura FT-12 è stata stornata: toglila dal carrello.');
    });
});

describe('il rifiuto della creazione per una voce di spesa negativa', () => {
    const RIFIUTO = 'Il piano non si crea: la voce di spesa «Rimborso» ha un importo negativo (meno € 240,00) e il piano la addebiterebbe in positivo, per € 240,00. Correggi l\'importo della voce nel piano dei conti: se è un provento, scegli «Entrata» e scrivi l\'importo senza il segno. Poi rimanda il modulo.';

    test('controprova — il rifiuto sta sopra «Salva piano rate» e il pulsante resta acceso per rimandare il modulo', async () => {
        flash.message = { type: 'error', message: RIFIUTO };
        await monta();

        expect(document.body.textContent).toContain('Il piano non si crea: la voce di spesa «Rimborso» ha un importo negativo');
        const salva = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.includes('Salva piano rate')) as HTMLButtonElement;
        expect(salva).toBeTruthy();
        expect(salva.disabled).toBe(false);
        expect(document.body.textContent!.indexOf('Il piano non si crea')).toBeLessThan(document.body.textContent!.indexOf('Salva piano rate'));
    });
});
