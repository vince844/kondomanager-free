// @vitest-environment jsdom

/**
 * Cosa resta scoperto: il server (`Beta52FrasiRegolaWalletSaldoManualeTest.php`); la compilazione automatica del modale dalla fermata, che non c'è;
 * l'esercizio (il controller manda solo le righe dell'esercizio corrente) e l'unità (un pannello per unità); la prova a video sul modale.
 *
 * Il modale «Aggiungi credito» / «Aggiungi debito» del pannello dei saldi, con «Soggetto specifico»: chi si può scegliere.
 *
 * Il server conta come «già ha un saldo» solo le righe che un passaggio non ha scritto (`CreateSaldoRequest`: `->whereNull('subentro_id')`):
 * chi ha soltanto la riga di un conguaglio può avere accanto il suo saldo manuale, ed è la strada a mano che la fermata di un passaggio
 * indica (un credito a chi resta a carico, un debito a chi riceve). Il selettore, invece, contava anche le righe dei passaggi: con Ugo ed
 * Elsa che hanno solo le righe di una coppia offriva soltanto chi non ha righe (Luca), e la strada a mano non si faceva dall'interfaccia.
 *
 * Il caso è quello di una locazione con due fine: Ines (fine il 24/1, coppia di € 355,52 a Ugo), Luca (inquilino dal 8/2 al 17/4), la
 * vendita di Ugo a Elsa il 12/3 (coppia di € 51,55). Ugo ha due righe di passaggio, Elsa una, Ines una; Luca nessuna.
 *
 * Nessuno è già scelto quando i liberi sono più d'uno: decide l'amministratore. Con una sola persona libera la scelta è quella, e vale anche
 * se è libera grazie all'eccezione dei passaggi (Ugo con la sola riga di un passaggio, gli altri con una riga manuale).
 *
 * Una riga di passaggio non si modifica né si cancella da sola: ha il lucchetto, né la matita né il cestino. Il selettore la lascia fuori
 * dai «già assegnati» solo per la scelta della persona di una riga nuova; il server resta l'autorità.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import vSelect from 'vue-select';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn(), patch: vi.fn() },
}));

const SaldiDetailPanel = (await import('./SaldiDetailPanel.vue')).default;

const GESTIONE = { id: 7, nome: 'Ordinaria 2026', tipo: 'ordinaria', saldo_applicato: true };
const ALTRA_GESTIONE = { id: 8, nome: 'Straordinaria facciata', tipo: 'straordinaria', saldo_applicato: true };

/** Una riga di saldo come arriva dal payload: manuale, libera, della persona data. */
const riga = (id: number, persona: number, nome: string, centesimi: number, extra: Record<string, unknown> = {}) => ({
    id, saldo_iniziale: centesimi, is_applicato: false, e_bloccato: false, origine: 'manuale', gestione_id: GESTIONE.id, anagrafica_id: persona,
    piano_rate_id: null, piano_rate: null, gestione: GESTIONE, anagrafica: { id: persona, nome, cognome: '' }, ...extra,
});

/** Una riga scritta da un passaggio: porta `subentro_id`, `e_conguaglio` e il passaggio da cui viene. */
const dalPassaggio = (passaggio: number, decorrenza: string) => ({ subentro_id: passaggio, e_conguaglio: true, subentro: { id: passaggio, decorrenza, tipo_passaggio: 'vendita' } });

const INES = 10, UGO = 11, ELSA = 12, LUCA = 13;
const SOGGETTI = [
    { id: INES, nome: 'Ines', cognome: '', pivot: { id: 100, tipologia: 'inquilino', data_fine: '2026-01-23' } },
    { id: LUCA, nome: 'Luca', cognome: '', pivot: { id: 103, tipologia: 'inquilino', data_fine: '2026-04-17' } },
    { id: UGO, nome: 'Ugo', cognome: '', pivot: { id: 101, tipologia: 'proprietario', data_fine: '2026-03-11' } },
    { id: ELSA, nome: 'Elsa', cognome: '', pivot: { id: 102, tipologia: 'proprietario', data_fine: null } },
];

/** Le righe dei passaggi del caso: la coppia della fine di Ines (Ines a credito, Ugo a debito) e quella della vendita (Ugo a credito, Elsa a debito). */
const COPPIE = [
    riga(1, INES, 'Ines', -35552, dalPassaggio(1, '2026-01-24')), riga(2, UGO, 'Ugo', 35552, dalPassaggio(1, '2026-01-24')),
    riga(3, UGO, 'Ugo', -5155, dalPassaggio(3, '2026-03-12')), riga(4, ELSA, 'Elsa', 5155, dalPassaggio(3, '2026-03-12')),
];

function monta(saldi: unknown[], soggetti = SOGGETTI) {
    return mount(SaldiDetailPanel as never, {
        props: {
            immobile: { id: 1, nome: 'Interno 1', interno: '1', anagrafiche: soggetti, saldi },
            gestioni: [GESTIONE, ALTRA_GESTIONE], esercizio: { id: 41, nome: '2026' }, condominio: { id: 28, nome: 'Condominio Test' }, valoriInCentesimi: true,
        } as never,
        global: { stubs: { transition: false } },
    }) as VueWrapper;
}

/** Apre la gestione, «Aggiungi credito» (o «Aggiungi debito»), sceglie «Soggetto specifico»; restituisce la pagina. */
async function apriIlSelettore(saldi: unknown[], soggetti = SOGGETTI, pulsante = 'Aggiungi credito'): Promise<VueWrapper> {
    const w = monta(saldi, soggetti);
    await w.findAll('button').find((b) => b.text().includes('Ordinaria 2026'))!.trigger('click');
    await w.vm.$nextTick();
    await w.findAll('button').find((b) => b.text().includes(pulsante))!.trigger('click');
    await w.vm.$nextTick();
    await w.findAll('input[type="radio"]').find((r) => r.attributes('value') === 'personale')!.setValue(true);
    await w.vm.$nextTick();

    return w;
}

/** I nomi che il selettore offre, nell'ordine in cui li offre (quelli che si vedono aprendo la tendina). */
const offerti = (w: VueWrapper): string[] => w.findComponent(vSelect).exists() ? (w.findComponent(vSelect).props('options') as { nome: string }[]).map((a) => a.nome) : [];

describe('il selettore di «Soggetto specifico»: conta solo chi ha un saldo che un passaggio non ha scritto', () => {
    test('Ugo ed Elsa hanno solo righe di passaggio, e Ines pure: si possono scegliere tutti, con Luca che non ha righe', async () => {
        const w = await apriIlSelettore(COPPIE);

        expect(offerti(w)).toEqual(['Ines', 'Luca', 'Ugo', 'Elsa']);
        // Nessuno è già scelto: decide l'amministratore (con quattro persone libere nessuna è l'unica).
        expect((w.vm as unknown as { modalForm: { anagraficaId: number | null } }).modalForm.anagraficaId).toBeNull();
    });

    test('con «Aggiungi debito» il selettore offre le stesse persone di «Aggiungi credito»', async () => {
        const w = await apriIlSelettore([...COPPIE, riga(5, UGO, 'Ugo', -26820)], SOGGETTI, 'Aggiungi debito');

        expect(offerti(w)).toEqual(['Ines', 'Luca', 'Elsa']);
    });

    test('con le due righe manuali di Ugo ed Elsa accanto a quelle dei passaggi restano Ines e Luca', async () => {
        const w = await apriIlSelettore([...COPPIE, riga(5, UGO, 'Ugo', -26820), riga(6, ELSA, 'Elsa', 26820)]);

        expect(offerti(w)).toEqual(['Ines', 'Luca']);
    });

    test('chi ha una riga di passaggio e una manuale non si offre; chi ha solo il passaggio sì', async () => {
        const w = await apriIlSelettore([...COPPIE, riga(5, UGO, 'Ugo', -26820)]);

        expect(offerti(w)).toEqual(['Ines', 'Luca', 'Elsa']);
    });

    test('controprova — chi ha una riga di passaggio e una manuale non si offre (Ugo con le due righe, Luca senza)', async () => {
        const soloUgoELuca = SOGGETTI.filter((a) => a.id === UGO || a.id === LUCA);
        const w = await apriIlSelettore([riga(2, UGO, 'Ugo', 35552, dalPassaggio(1, '2026-01-24')), riga(5, UGO, 'Ugo', -26820)], soloUgoELuca);

        expect(offerti(w)).toEqual(['Luca']);
    });

    test('controprova — senza righe di passaggio, con le sole righe manuali di Ugo ed Elsa, restano Ines e Luca', async () => {
        const w = await apriIlSelettore([riga(5, UGO, 'Ugo', -26820), riga(6, ELSA, 'Elsa', 26820)]);

        expect(offerti(w)).toEqual(['Ines', 'Luca']);
    });

    test('controprova — con tutti i soggetti che hanno una riga manuale non si offre nessuno e il modale lo dice', async () => {
        const w = await apriIlSelettore([riga(5, INES, 'Ines', 100), riga(6, LUCA, 'Luca', 100), riga(7, UGO, 'Ugo', 100), riga(8, ELSA, 'Elsa', 100)]);

        expect(offerti(w)).toEqual([]);
        expect(w.text().replace(/\s+/g, ' ')).toContain('Tutti i soggetti associati hanno già un saldo per questa gestione.');
    });

    test('controprova — una riga senza la chiave `subentro_id` (un saldo di prima dei passaggi) conta come manuale', async () => {
        const w = await apriIlSelettore([riga(5, UGO, 'Ugo', 100)]);

        expect(offerti(w)).toEqual(['Ines', 'Luca', 'Elsa']);
    });

    test('controprova — le righe di un\'altra gestione non contano: conta la gestione del modale', async () => {
        const w = await apriIlSelettore([riga(9, UGO, 'Ugo', 100, { gestione_id: ALTRA_GESTIONE.id, gestione: ALTRA_GESTIONE })]);

        expect(offerti(w)).toEqual(['Ines', 'Luca', 'Ugo', 'Elsa']);
    });

    test('controprova — gli id di gestione che arrivano come stringa contano come i numeri: il selettore offre le stesse persone', async () => {
        const comeNumeri = await apriIlSelettore([riga(5, UGO, 'Ugo', -26820), riga(6, ELSA, 'Elsa', 26820)]);
        const comeStringhe = await apriIlSelettore([riga(5, UGO, 'Ugo', -26820, { gestione_id: String(GESTIONE.id) }), riga(6, ELSA, 'Elsa', 26820, { gestione_id: String(GESTIONE.id) })]);

        expect(offerti(comeStringhe)).toEqual(['Ines', 'Luca']);
        expect(offerti(comeStringhe)).toEqual(offerti(comeNumeri));
    });

    test('controprova — un solo libero resta preselezionato: con Ines, Ugo ed Elsa che hanno una riga manuale resta Luca', async () => {
        const w = await apriIlSelettore([riga(5, INES, 'Ines', 100), riga(6, UGO, 'Ugo', 100), riga(7, ELSA, 'Elsa', 100)]);

        expect(offerti(w)).toEqual(['Luca']);
        expect((w.vm as unknown as { modalForm: { anagraficaId: number | null } }).modalForm.anagraficaId).toBe(LUCA);
    });
});

describe('la preselezione con l\'eccezione dei passaggi', () => {
    test('Ugo ha solo la riga di un passaggio e gli altri una riga manuale: Ugo è l\'unico libero ed è già scelto', async () => {
        const w = await apriIlSelettore([riga(5, INES, 'Ines', 100), riga(6, LUCA, 'Luca', 100), riga(7, ELSA, 'Elsa', 100), riga(2, UGO, 'Ugo', 35552, dalPassaggio(1, '2026-01-24'))]);

        expect(offerti(w)).toEqual(['Ugo']);
        expect((w.vm as unknown as { modalForm: { anagraficaId: number | null } }).modalForm.anagraficaId).toBe(UGO);
    });

    test('con due liberi, uno dei quali grazie all\'eccezione, il selettore li offre tutti e due e nessuno è già scelto', async () => {
        const w = await apriIlSelettore([riga(5, INES, 'Ines', 100), riga(7, ELSA, 'Elsa', 100), riga(2, UGO, 'Ugo', 35552, dalPassaggio(1, '2026-01-24'))]);

        expect(offerti(w)).toEqual(['Luca', 'Ugo']);
        expect((w.vm as unknown as { modalForm: { anagraficaId: number | null } }).modalForm.anagraficaId).toBeNull();
    });
});

describe('la riga di un passaggio resta intoccabile dal pannello', () => {
    test('controprova — ha il lucchetto viola e non ha né la matita né il cestino', async () => {
        const w = monta(COPPIE);
        await w.findAll('button').find((b) => b.text().includes('Ordinaria 2026'))!.trigger('click');
        await w.vm.$nextTick();
        const icone = w.findAll('svg').map((s) => s.attributes('class') ?? '');

        expect(w.findAll('button').filter((b) => (b.attributes('title') ?? '').startsWith('Conguaglio del passaggio del')).length).toBe(4);
        expect(icone.some((c) => /pencil/.test(c))).toBe(false);
        expect(icone.some((c) => /trash/.test(c))).toBe(false);
    });

    test('controprova — una riga manuale accanto ha la matita e il cestino', async () => {
        const w = monta([...COPPIE, riga(5, UGO, 'Ugo', -26820)]);
        await w.findAll('button').find((b) => b.text().includes('Ordinaria 2026'))!.trigger('click');
        await w.vm.$nextTick();
        const icone = w.findAll('svg').map((s) => s.attributes('class') ?? '');

        expect(icone.filter((c) => /pencil/.test(c)).length).toBe(1);
        expect(icone.filter((c) => /trash/.test(c)).length).toBe(1);
    });
});
