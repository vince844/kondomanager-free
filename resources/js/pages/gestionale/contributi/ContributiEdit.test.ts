// @vitest-environment jsdom

/**
 * Coda 438 (decisione 183.1): la pagina del già versato chiede per quale esercizio vale («Vale per»), una scelta per voce come la natura.
 *
 * - aperta e salvata senza toccare niente manda ciò che ha ricevuto, anche l'esercizio, con dati scomodi (due righe sulla stessa unità, una senza persona,
 *   un importo di un centesimo, la liquidità già dichiarata su una cassa);
 * - senza una scelta (una voce nuova, o righe di esercizi diversi) non salva e lo dice; con righe di esercizi diversi dice quali sono;
 * - «Tutti gli esercizi» si manda come `tutti`.
 *
 * Le props sono quelle che manda `ContributoVersatoController::edit` (i test Pest di `Beta1_112GiaVersatoTest` provano il server). La pagina è quella vera:
 * si stubbano la cornice, la guida, la modale della liquidità e il campo degli importi.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

// `put` chiude come Inertia: il pulsante torna attivo (`onFinish`).
const router = vi.hoisted(() => ({ put: vi.fn((_u: string, _d: unknown, o?: { onFinish?: () => void }) => o?.onFinish?.()), get: vi.fn(), post: vi.fn() }));
const toast = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router,
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
vi.mock('@/components/ui/toast', () => ({ useToast: () => ({ toast }) }));

const ContributiEdit = (await import('@/pages/gestionale/contributi/ContributiEdit.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    StrutturaLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: true,
    GiaVersatoGuide: true,
    ModalLiquiditaGiaVersato: true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

const ESERCIZI = [{ id: 26, nome: 'Esercizio 2026', stato: 'aperto' }, { id: 27, nome: 'Esercizio 2027', stato: 'aperto' }];
const riga = (chiave: string, immobile: number, persona: number | null, versato: number) => ({
    chiave, immobile_id: immobile, anagrafica_id: persona, nome: `Interno ${immobile}`, interno: String(immobile), millesimi: 500, quota_lorda: 60000, quota_lorda_unita: 60000, gia_versato: versato,
});
const RIGHE = [riga('1:7', 1, 7, 12345), riga('1:0', 1, null, 678), riga('2:8', 2, 8, 1)];

let pagina: VueWrapper | null = null;

function monta(o: { vale_per?: 'tutti' | number | null; vale_per_diversi?: string[]; esercizi?: typeof ESERCIZI } = {}) {
    pagina = mount(ContributiEdit, {
        attachTo: document.body,
        props: {
            condominio: { id: 3, nome: 'Condominio Demo' } as never,
            voce: { id: 11, nome: 'Spese generali', importo_cents: 120000, gestione: 'Gestione ordinaria' },
            righe: RIGHE, titolari: { 1: [{ id: 7, nome: 'Ugo', tipologia: 'proprietario', quota: 100 }], 2: [{ id: 8, nome: 'Bice', tipologia: 'proprietario', quota: 100 }] },
            natura: 'avanzo', descrizione: 'Delibera del 12/05/2025', stima_semplificata: false, ripartizione_mista: false,
            liquidita_stato: 'registrata_in_cassa', cassa_id: 9, casse: [],
            esercizi: o.esercizi ?? ESERCIZI,
            vale_per: o.vale_per === undefined ? 27 : o.vale_per,
            vale_per_diversi: o.vale_per_diversi ?? [],
        },
        global: { stubs },
    });

    return pagina;
}

async function salva() {
    const bottone = pagina!.findAll('button').find(b => b.text().includes('Salva'));
    await bottone!.trigger('click');
    await flushPromises();
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    router.put.mockClear();
    toast.mockReset();
});

describe('Coda 438 — «Vale per» nella pagina del già versato', () => {
    test('aperta e salvata senza toccare niente: il corpo è uguale ai dati ricevuti, esercizio compreso', async () => {
        monta();
        await salva();

        expect(router.put).toHaveBeenCalledTimes(1);
        expect(router.put.mock.calls[0][1]).toEqual({
            natura: 'avanzo',
            vale_per: 27,
            descrizione: 'Delibera del 12/05/2025',
            righe: [
                { immobile_id: 1, anagrafica_id: 7, gia_versato: 12345 },
                { immobile_id: 1, anagrafica_id: null, gia_versato: 678 },
                { immobile_id: 2, anagrafica_id: 8, gia_versato: 1 },
            ],
            liquidita_stato: 'registrata_in_cassa',
            cassa_id: 9,
            nota_acconto: null,
        });
    });

    test('le scelte sono gli esercizi ricevuti, in quell\'ordine, e «Tutti gli esercizi»; «Tutti gli esercizi» si manda come «tutti»', async () => {
        monta({ vale_per: 'tutti' });
        const select = pagina!.find('#vale-per');
        expect(select.findAll('option').map(o => o.text())).toEqual(['Scegli l\'esercizio…', 'Esercizio 2026', 'Esercizio 2027', 'Tutti gli esercizi']);
        expect(document.body.textContent).toContain('La gestione di questa voce è in più esercizi');

        await select.setValue('26');
        await salva();
        expect(router.put.mock.calls[0][1].vale_per).toBe(26);

        await select.setValue('tutti');
        await salva();
        expect(router.put.mock.calls[1][1].vale_per).toBe('tutti');
    });

    test('senza una scelta non salva, e dice che cosa manca', async () => {
        monta({ vale_per: null });
        await salva();

        expect(router.put).not.toHaveBeenCalled();
        expect(toast).toHaveBeenCalledWith(expect.objectContaining({ description: 'Scegli per quale esercizio vale il già versato.' }));
    });

    test('le righe di esercizi diversi: la pagina dice quali sono, e che salvando varranno per quello scelto', async () => {
        monta({ vale_per: null, vale_per_diversi: ['Esercizio 2026', 'Tutti gli esercizi'] });

        expect(document.body.textContent).toContain('Le righe di questa voce valgono per esercizi diversi: Esercizio 2026, Tutti gli esercizi. Salvando, varranno tutte per l\'esercizio che scegli qui.');
    });

    test('il riquadro dell\'effetto dice per quali piani vale il già versato: quelli dell\'esercizio scelto, o tutti i piani della voce', async () => {
        monta({ vale_per: 26 });
        expect(document.body.textContent).toContain('Effetto sui piani rate di «Esercizio 2026»');
        expect(document.body.textContent).not.toContain('Effetto sul prossimo piano rate');

        await pagina!.find('select#vale-per').setValue('tutti');
        expect(document.body.textContent).toContain('Effetto sui piani rate della voce, in tutti gli esercizi');
    });

    test('con una gestione di un esercizio solo e «Tutti gli esercizi» il riquadro dice il prossimo piano rate', async () => {
        monta({ vale_per: 'tutti', esercizi: [ESERCIZI[0]] });

        expect(document.body.textContent).toContain('Effetto sul prossimo piano rate');
        expect(document.body.textContent).not.toContain('in tutti gli esercizi');
    });
});
