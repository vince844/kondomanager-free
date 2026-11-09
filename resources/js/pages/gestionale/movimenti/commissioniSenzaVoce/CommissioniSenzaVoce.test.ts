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

    test('lo storno di una commissione che era su una gestione che l\'esercizio non ha si sceglie, con il suo motivo, e la voce si sceglie solo fra quelle delle ordinarie dell\'esercizio', async () => {
        const vm = await apri({
            esercizio: { id: 42, nome: 'Esercizio 2027', stato: 'aperto', aperto: true },
            commissioni: [commissione(4, '2027-02-10', -250, { gestione_id: 20, gestione: 'Tetto', solo_ordinarie: true,
                motivo: 'È lo storno di una commissione dell\'esercizio «Esercizio 2026», che era sulla voce «Spese bancarie» della gestione «Gestione ordinaria»: questo esercizio non ha quella gestione. Si assegna a una voce di un\'ordinaria di questo esercizio, che ne riceve il rimborso; senza voce resta in una riga sua del rendiconto.' })],
            totale: { numero: 1, importo: -250 },
            voci: [
                { id: 9, label: 'Bancarie del tetto', gestione_id: 20, gestione_nome: 'Tetto', gestione_tipo: 'straordinaria', esercizi: [41, 42] },
                { id: 5, label: 'Spese bancarie', gestione_id: 10, gestione_nome: 'Gestione ordinaria', gestione_tipo: 'ordinaria', esercizi: [41] },
                { id: 7, label: 'Spese bancarie 2027', gestione_id: 30, gestione_nome: 'Ordinaria 2027', gestione_tipo: 'ordinaria', esercizi: [42] },
            ],
            proposte: [{ gestione_id: 20, voce_id: 9 }],
        });
        const riga = pagina!.find('[data-riga="commissione"]');
        expect(riga.text()).toContain('Si assegna a una voce di un\'ordinaria di questo esercizio');
        await riga.find('input[type="checkbox"]').setValue(true);
        await nextTick();

        expect(vm.vociDellaScelta.map((v: { id: number }) => v.id)).toEqual([7]);
        // La proposta della gestione del pagamento (la bancaria del tetto) non è fra quelle offerte: il campo resta vuoto.
        expect(vm.voceId).toBeNull();
    });

    test('con lo storno di una regolazione di un esercizio chiuso il titolo, il totale, la frase, la casella e l\'anteprima nominano lo storno', async () => {
        const vm = await apri({
            esercizio: { id: 42, nome: 'Esercizio 2027', stato: 'aperto', aperto: true },
            commissioni: [
                commissione(1, '2027-01-20', 250, { gestione_id: 10 }),
                commissione(4, '2027-02-10', -9600, { gestione_id: 10, natura: 'registrazione', voce_di_entrata: false, solo_ordinarie: true, fornitore: null }),
            ],
            totale: { numero: 2, importo: -9350 },
            voci: [{ id: 7, label: 'Spese generali 2027', gestione_id: 30, gestione_nome: 'Ordinaria 2027', gestione_tipo: 'ordinaria', esercizi: [42] }],
            proposte: [],
        });

        expect(vm.titolo).toBe('Commissioni e storni senza voce');
        expect(pagina!.find('[data-testo="totale"]').text().replace(/\u00a0/g, ' ')).toBe('1 commissione e 1 storno senza voce, per € -93,50.');
        expect(pagina!.find('[data-testo="storni"]').text()).toContain('di spesa per la regolazione e di entrata per l\'incasso');
        const caselle = pagina!.findAll('[data-riga="commissione"] input[type="checkbox"]');
        expect(caselle[1].attributes('aria-label')).toBe('Scegli lo storno PAG-2026-00004');

        await caselle[1].setValue(true);
        vm.voceId = 7;
        vm.mostraAnteprima = true;
        await nextTick();
        expect(pagina!.find('[data-blocco="anteprima"]').text().replace(/\u00a0/g, ' '))
            .toContain('1 storno per € -96,00 prende la voce «Spese generali 2027»: il consuntivo della voce cambia di € -96,00 nell\'esercizio Esercizio 2027.');
    });

    test('senza storni il titolo resta «Commissioni senza voce»', async () => {
        const vm = await apri();
        expect(vm.titolo).toBe('Commissioni senza voce');
        expect(pagina!.find('[data-testo="storni"]').exists()).toBe(false);
    });

    test('lo storno di un incasso da terzi si assegna a una voce di entrata delle ordinarie dell\'esercizio, e l\'anteprima dice l\'incassato; scelto con un\'altra riga, la pagina dice che vanno in due volte e non manda niente', async () => {
        const vm = await apri({
            esercizio: { id: 42, nome: 'Esercizio 2027', stato: 'aperto', aperto: true },
            commissioni: [
                commissione(4, '2027-02-10', 24000, { gestione_id: 10, natura: 'registrazione', voce_di_entrata: true, solo_ordinarie: true, fornitore: null }),
                commissione(5, '2027-02-10', -9600, { gestione_id: 10, natura: 'registrazione', voce_di_entrata: false, solo_ordinarie: true, fornitore: null }),
            ],
            totale: { numero: 2, importo: 14400 },
            voci: [{ id: 7, label: 'Spese generali 2027', gestione_id: 30, gestione_nome: 'Ordinaria 2027', gestione_tipo: 'ordinaria', esercizi: [42] }],
            voci_di_entrata: [
                { id: 8, label: 'Affitto antenna 2027', gestione_id: 30, gestione_nome: 'Ordinaria 2027', gestione_tipo: 'ordinaria', esercizi: [42] },
                { id: 3, label: 'Affitto antenna', gestione_id: 10, gestione_nome: 'Gestione ordinaria', gestione_tipo: 'ordinaria', esercizi: [41] },
            ],
            proposte: [],
        });
        vm.scelte = [4];
        await nextTick();
        expect(vm.vociDellaScelta.map((v: { id: number }) => v.id)).toEqual([8]);
        vm.voceId = 8;
        vm.mostraAnteprima = true;
        await nextTick();
        expect(pagina!.find('[data-blocco="anteprima"]').text().replace(/\u00a0/g, ' '))
            .toContain('1 storno per € 240,00 prende la voce «Affitto antenna 2027»: l\'incassato della voce cambia di € -240,00');

        vm.scelte = [4, 5];
        await nextTick();
        expect(pagina!.find('[data-testo="due-tipi"]').text()).toBe('Lo storno di un incasso da terzi va su una voce di entrata, le commissioni e gli altri storni su una voce di spesa: si assegnano in due volte.');
        expect(vm.puoAssegnare).toBe(false);
        vm.conferma();
        expect(post).not.toHaveBeenCalled();
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
