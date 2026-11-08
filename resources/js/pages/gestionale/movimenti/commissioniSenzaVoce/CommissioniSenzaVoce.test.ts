// @vitest-environment jsdom

/**
 * L'elenco delle commissioni senza voce e «Assegna la voce» in blocco: una gestione alla volta, la scelta di una, di alcune o di tutte, la voce
 * proposta, l'anteprima con il numero e l'importo, e il corpo con gli id scelti (il server li ricontrolla). In un esercizio chiuso le commissioni
 * si vedono e non si scelgono. Le date arrivano `aaaa-mm-gg` e si scrivono gg/mm/aaaa; gli importi sono in centesimi.
 *
 * Il lato del server è in `tests/Feature/Gestionale/CommissioniSenzaVoceTest.php`.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';

const post = vi.fn();
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    router: { post: (...a: unknown[]) => post(...a), visit: vi.fn() },
    usePage: () => ({ props: { flash: {}, errors: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import CommissioniSenzaVoce from './CommissioniSenzaVoce.vue';

(globalThis as any).route = (nome: string, p: Record<string, unknown> = {}) => `/${nome}/${p.condominio ?? ''}/${p.esercizio ?? ''}`;
const mocks = { route: (globalThis as any).route };
const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, Head: { template: '<span />' } };

const commissione = (id: number, data: string, importo = 250, extra: Record<string, unknown> = {}) => ({
    id, righe: [{ riga_id: id, scrittura_id: id + 1000, protocollo: `PAG-2026-0000${id}`, data, importo, tipo: 'commissione' }],
    fornitore: 'Fornitore Test Srl', gestione_id: 10, gestione: 'Ordinaria', importo, assegnabile: true, motivo: null, ...extra,
});

let pagina: VueWrapper | null = null;

async function apri(sovrascrivi: Record<string, unknown> = {}) {
    post.mockReset();
    pagina = mount(CommissioniSenzaVoce, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' },
            condomini: [],
            esercizio: { id: 41, nome: 'Esercizio 2026', stato: 'aperto', aperto: true },
            commissioni: [commissione(1, '2026-04-10'), commissione(2, '2026-05-02'), commissione(3, '2026-06-18')],
            totale: { numero: 3, importo: 750 },
            frase_chiuso: null,
            voci: [{ id: 5, label: 'Spese bancarie', gestione_id: 10 }, { id: 9, label: 'Bancarie del tetto', gestione_id: 20 }],
            proposte: [{ gestione_id: 10, voce_id: 5 }],
            ...sovrascrivi,
        } as never,
        global: { stubs, mocks },
    });
    await nextTick();

    return pagina.vm as any;
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('le commissioni senza voce', () => {
    test('una riga per commissione, con la data scritta per esteso e l\'importo in euro; il totale', async () => {
        await apri();
        const righe = pagina!.findAll('[data-riga="commissione"]');

        expect(righe).toHaveLength(3);
        expect(righe[0].text().replace(/ /g, ' ')).toContain('10/04/2026');
        expect(righe[0].text().replace(/ /g, ' ')).toContain('€ 2,50');
        expect(pagina!.find('[data-testo="totale"]').text().replace(/ /g, ' ')).toBe('3 commissioni senza voce, per € 7,50.');
    });

    test('«tutte le N», la voce proposta e l\'anteprima con il numero e l\'importo; la conferma manda gli id scelti', async () => {
        const stato = await apri();
        stato.scegliTutte();
        await nextTick();

        expect(stato.voceId).toBe(5);
        stato.mostraAnteprima = true;
        await nextTick();
        expect(pagina!.find('[data-blocco="anteprima"]').text().replace(/ /g, ' ')).toContain('3 commissioni per € 7,50 prendono la voce «Spese bancarie»');

        stato.conferma();
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe('/admin.gestionale.esercizi.commissioni-senza-voce.assegna/28/41');
        expect(post.mock.calls[0][1]).toEqual({ commissioni: [1, 2, 3], voce_id: 5 });
    });

    test('«tutte le N» sceglie solo quelle che si possono assegnare: uno storno che non si offre resta fuori dal corpo', async () => {
        const stato = await apri({
            commissioni: [commissione(1, '2026-04-10'), commissione(2, '2026-05-02'), commissione(4, '2026-06-01', -250, { assegnabile: false, motivo: 'Storno di un altro esercizio.' })],
            totale: { numero: 3, importo: 250 },
        });
        stato.scegliTutte();
        stato.mostraAnteprima = true;
        await nextTick();

        expect(pagina!.find('[data-blocco="anteprima"]').text().replace(/\u00a0/g, ' ')).toContain('2 commissioni per € 5,00');
        stato.conferma();
        expect(post.mock.calls[0][1]).toEqual({ commissioni: [1, 2], voce_id: 5 });
    });

    test('senza commissioni scelte o senza voce non si assegna', async () => {
        const stato = await apri({ proposte: [] });
        stato.conferma();
        expect(post).not.toHaveBeenCalled();
        stato.scelte = [1];
        stato.conferma();
        expect(post).not.toHaveBeenCalled();
    });

    test('in un esercizio chiuso le commissioni si vedono, non si scelgono, e la frase lo dice', async () => {
        await apri({
            esercizio: { id: 41, nome: 'Esercizio 2026', stato: 'chiuso', aperto: false },
            commissioni: [commissione(1, '2026-04-10', 250, { assegnabile: false })],
            totale: { numero: 1, importo: 250 },
            frase_chiuso: 'L\'esercizio «Esercizio 2026» è chiuso: le commissioni senza voce restano come sono.',
        });

        expect(pagina!.find('[data-testo="chiuso"]').text()).toContain('è chiuso');
        expect(pagina!.findAll('input[type="checkbox"]')).toHaveLength(0);
        expect(pagina!.text()).not.toContain('Assegna la voce');
    });

    test('uno storno che non si offre porta il suo motivo, e non ha la casella', async () => {
        await apri({
            commissioni: [commissione(4, '2027-02-10', -250, { assegnabile: false, motivo: 'È lo storno di una commissione dell\'esercizio «Esercizio 2026», che è chiuso.' })],
            totale: { numero: 1, importo: -250 },
        });
        const riga = pagina!.find('[data-riga="commissione"]');

        expect(riga.text()).toContain('È lo storno di una commissione');
        expect(riga.find('input[type="checkbox"]').exists()).toBe(false);
    });
});
