// @vitest-environment jsdom

/**
 * beta.44 — Il pannello dei saldi decide sul lucchetto CALCOLATO, non sul flag grezzo.
 *
 * Il difetto che questo file chiude era invisibile alla suite per costruzione. La beta.33
 * aveva spostato la soglia del blocco da «esiste un piano» a «il piano è emesso o incassato»
 * e l'aveva coperta con venticinque test — **tutti lato server**. Il pannello continuava a
 * guardare `is_applicato`, che `SaldoEsercizioService` accende alla *generazione* del piano:
 * fra generazione ed emissione il server accettava la correzione e l'interfaccia mostrava il
 * lucchetto. Nessun test JS toccava questo componente, quindi la divergenza fra le due metà
 * non aveva modo di accendere niente.
 *
 * È il motivo per cui il test monta il componente invece di provare una funzione: il difetto
 * vive in un `v-if` del template, e si vede solo arrivando fino ai pulsanti che l'utente
 * trova — o non trova — accanto alla riga.
 *
 * L'altra metà del contratto — che il server calcoli davvero `e_bloccato` da
 * `PianoRate::eImmutabile()` e non dal flag grezzo — è fissata da
 * `tests/Feature/Gestionale/SaldiLucchettoInterfacciaTest.php`. I due lati vanno letti
 * insieme, come nella beta.35.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount } from '@vue/test-utils';

// `router` viene toccato solo dagli handler (modifica, eliminazione, sblocco): al montaggio
// non serve, ma il modulo va comunque risolto.
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn() },
}));

const SaldiDetailPanel = (await import('./SaldiDetailPanel.vue')).default;

const GESTIONE = { id: 7, nome: 'Ordinaria 2026', tipo: 'ordinaria', saldo_applicato: true };

/**
 * Un saldo come arriva dal payload. `is_applicato` è **sempre acceso**: è lo stato che il
 * servizio lascia quando un piano assorbe i pregressi, e il senso di questi test è che non
 * sia più lui a decidere.
 */
function saldo(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        saldo_iniziale: 50000,
        is_applicato: true,
        e_bloccato: false,
        origine: 'manuale',
        gestione_id: GESTIONE.id,
        anagrafica_id: 11,
        piano_rate_id: 3,
        piano_rate: { id: 3, nome: 'Piano 2026' },
        gestione: GESTIONE,
        anagrafica: { id: 11, nome: 'Rossi', cognome: '' },
        ...overrides,
    };
}

function montaPannello(saldi: ReturnType<typeof saldo>[]) {
    return mount(SaldiDetailPanel, {
        props: {
            immobile: { id: 1, nome: 'Int 1', interno: '1', anagrafiche: [], saldi },
            gestioni: [GESTIONE],
            esercizio: { id: 41, nome: '2026' },
            condominio: { id: 28, nome: 'Condominio Test' },
            valoriInCentesimi: true,
        },
    });
}

/** I pulsanti di modifica ed eliminazione compaiono solo su un saldo davvero libero. */
function haPulsantiDiModifica(wrapper: ReturnType<typeof montaPannello>): boolean {
    return wrapper.findAll('button').some(b => b.attributes('class')?.includes('hover:text-indigo-500'));
}

function haLucchetto(wrapper: ReturnType<typeof montaPannello>): boolean {
    return wrapper.findAll('button').some(b => b.attributes('title') === 'Saldo Bloccato');
}

/**
 * Le gestioni nascono chiuse: l'avviso vive nel corpo del gruppo e si vede aprendolo, che è
 * il momento in cui l'amministratore sta guardando quei saldi.
 */
async function apriPrimaGestione(wrapper: ReturnType<typeof montaPannello>): Promise<void> {
    await wrapper.findAll('button').find(b => b.text().includes('Ordinaria 2026'))!.trigger('click');
    await wrapper.vm.$nextTick();
}

describe('il lucchetto segue e_bloccato, non is_applicato', () => {
    test('un saldo di un piano generato ma non emesso resta modificabile', async () => {
        // Il caso della voce: `is_applicato` acceso, ma il piano non è emesso. Prima qui
        // compariva il lucchetto e l'unica via d'uscita era cancellare il piano.
        const wrapper = montaPannello([saldo({ e_bloccato: false })]);
        await wrapper.vm.$nextTick();

        expect(haLucchetto(wrapper)).toBe(false);
        expect(haPulsantiDiModifica(wrapper)).toBe(true);
    });

    test('un saldo di un piano emesso mostra il lucchetto', async () => {
        const wrapper = montaPannello([saldo({ e_bloccato: true })]);
        await wrapper.vm.$nextTick();

        expect(haLucchetto(wrapper)).toBe(true);
        expect(haPulsantiDiModifica(wrapper)).toBe(false);
    });

    test('il flag grezzo da solo non blocca più niente', async () => {
        // La regressione che questo file esiste per impedire: se qualcuno tornasse a
        // leggere `is_applicato`, questo test si accenderebbe. I due saldi hanno lo stesso
        // flag grezzo e destini opposti.
        const wrapper = montaPannello([
            saldo({ id: 1, e_bloccato: false }),
            saldo({ id: 2, e_bloccato: true, saldo_iniziale: 30000 }),
        ]);
        await wrapper.vm.$nextTick();

        expect(wrapper.findAll('button').filter(b => b.attributes('title') === 'Saldo Bloccato')).toHaveLength(1);
    });
});

describe('l\'avviso sul ricalcolo', () => {
    test('compare quando un piano non emesso ha già assorbito i saldi', async () => {
        // Rendere di nuovo raggiungibili quei saldi senza dirlo sarebbe un regalo avvelenato:
        // le quote già generate restano quelle vecchie finché non si preme «Ricalcola».
        const wrapper = montaPannello([saldo({ e_bloccato: false, piano_rate_id: 3 })]);
        await apriPrimaGestione(wrapper);

        expect(wrapper.text()).toContain('Ricalcola');
    });

    test('non compare su un saldo che nessun piano ha toccato', async () => {
        // Un avviso che compare sempre è un avviso che si impara a non leggere.
        const wrapper = montaPannello([
            saldo({ e_bloccato: false, is_applicato: false, piano_rate_id: null, piano_rate: null }),
        ]);
        await apriPrimaGestione(wrapper);

        expect(wrapper.text()).not.toContain('Ricalcola');
    });
});
