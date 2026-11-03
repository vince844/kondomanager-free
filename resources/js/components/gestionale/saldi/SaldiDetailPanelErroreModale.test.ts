// @vitest-environment jsdom

/**
 * Cosa resta scoperto: il server (`Beta52FrasiRegolaWalletSaldoManualeTest.php` e `CreateSaldoRequest`); la modifica inline e l'eliminazione di una riga
 * (il loro rifiuto compare nel riquadro in cima alla pagina, chiave `saldo`); la prova a video, il tema scuro e i 375 px.
 *
 * Il modale «Aggiungi credito» / «Aggiungi debito» del pannello dei saldi: il rifiuto del server si legge dentro il modale, e «Intero immobile» non
 * si offre dove la gestione ha già un saldo per l'intero immobile.
 *
 * Il server rifiuta un secondo saldo per l'intero immobile sulla stessa gestione («Esiste già un saldo solidale per questo immobile in questa
 * gestione. Modifica quello esistente.», sul campo `anagrafica_id`) e un secondo saldo della stessa persona. Una richiesta senza `onError` lascerebbe il
 * modale aperto senza dire niente, e «Intero immobile» è la scelta con cui il modale si apre: il rifiuto sarebbe a un clic di distanza.
 *
 * Il modale mostra il rifiuto, qualunque campo lo dia (`anagrafica_id`, `saldo_iniziale`, un altro), e lo toglie quando si riapre o si riprova.
 * Come il selettore delle persone, spegne «Intero immobile» dove un saldo per l'intero immobile c'è già e lo dice accanto; il modale si apre allora
 * su «Soggetto specifico». Conta come per il server (`CreateSaldoRequest`): la stessa gestione e nessuna riga scritta da un passaggio.
 *
 * `router.post` è finto: il test legge le opzioni con cui parte la richiesta e ne chiama `onError` e `onSuccess` come farebbe Inertia.
 */

import { describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn(), patch: vi.fn() },
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const SaldiDetailPanel = (await import('./SaldiDetailPanel.vue')).default;

const GESTIONE = { id: 7, nome: 'Ordinaria 2026', tipo: 'ordinaria', saldo_applicato: true };
const ALTRA_GESTIONE = { id: 8, nome: 'Straordinaria facciata', tipo: 'straordinaria', saldo_applicato: true };
const RIFIUTO_SOLIDALE = 'Esiste già un saldo solidale per questo immobile in questa gestione. Modifica quello esistente.';
const PERCHE = 'ha già un saldo per l\'intero immobile';

const UGO = 11;
const SOGGETTI = [{ id: UGO, nome: 'Ugo', cognome: '', pivot: { id: 101, tipologia: 'proprietario', data_fine: null } }];

/** Un saldo per l'intero immobile (solidale): senza persona. */
const solidale = (id: number, extra: Record<string, unknown> = {}) => ({
    id, saldo_iniziale: 5000, is_applicato: false, e_bloccato: false, origine: 'manuale', gestione_id: GESTIONE.id, anagrafica_id: null,
    piano_rate_id: null, piano_rate: null, gestione: GESTIONE, anagrafica: null, ...extra,
});
const personale = (id: number, extra: Record<string, unknown> = {}) => ({ ...solidale(id), anagrafica_id: UGO, anagrafica: { id: UGO, nome: 'Ugo', cognome: '' }, ...extra });

function monta(saldi: unknown[]) {
    return mount(SaldiDetailPanel as never, {
        props: {
            immobile: { id: 1, nome: 'Interno 1', interno: '1', anagrafiche: SOGGETTI, saldi },
            gestioni: [GESTIONE, ALTRA_GESTIONE], esercizio: { id: 41, nome: '2026' }, condominio: { id: 28, nome: 'Condominio Test' }, valoriInCentesimi: true,
        } as never,
        global: { stubs: { transition: false } },
    }) as VueWrapper;
}

const modulo = (w: VueWrapper) => (w.vm as unknown as { modalForm: { targetType: string; importo: string; anagraficaId: number | null } }).modalForm;

/** Apre la gestione e il modale «Aggiungi debito». */
async function apriIlModale(saldi: unknown[]): Promise<VueWrapper> {
    const w = monta(saldi);
    await w.findAll('button').find((b) => b.text().includes('Ordinaria 2026'))!.trigger('click');
    await w.vm.$nextTick();
    await w.findAll('button').find((b) => b.text().includes('Aggiungi debito'))!.trigger('click');
    await w.vm.$nextTick();

    return w;
}

const radioSolidale = (w: VueWrapper) => w.findAll('input[type="radio"]').find((r) => r.attributes('value') === 'solidale')!;
const testo = (w: VueWrapper) => w.text().replace(/\s+/g, ' ');

/** Scrive un importo e preme «Salva Debito»; restituisce le opzioni con cui è partita la richiesta. */
async function salva(w: VueWrapper): Promise<{ onError?: (e: Record<string, string>) => void; onSuccess?: () => void }> {
    vi.mocked(router.post).mockClear();
    modulo(w).importo = '50,00';
    await w.vm.$nextTick();
    await w.findAll('button').find((b) => b.text().includes('Salva Debito'))!.trigger('click');
    await w.vm.$nextTick();
    expect(router.post).toHaveBeenCalledTimes(1);

    return vi.mocked(router.post).mock.calls[0][2] as never;
}

describe('il rifiuto del server si legge dentro il modale', () => {
    test('un secondo saldo per l\'intero immobile (campo `anagrafica_id`): il modale resta aperto e dice perché', async () => {
        const w = await apriIlModale([]);
        const opzioni = await salva(w);

        expect(typeof opzioni.onError).toBe('function');
        opzioni.onError!({ anagrafica_id: RIFIUTO_SOLIDALE });
        await w.vm.$nextTick();

        expect(testo(w)).toContain(RIFIUTO_SOLIDALE);
        expect(testo(w)).toContain('Salva Debito');
        expect(w.find('[role="alert"]').exists()).toBe(true);
    });

    test('un importo rifiutato (campo `saldo_iniziale`) si legge allo stesso modo', async () => {
        const w = await apriIlModale([]);
        const opzioni = await salva(w);
        opzioni.onError!({ saldo_iniziale: 'L\'importo deve essere un numero intero (centesimi).' });
        await w.vm.$nextTick();

        expect(testo(w)).toContain('L\'importo deve essere un numero intero (centesimi).');
    });

    test('un rifiuto su un altro campo non si perde: compare anche quello', async () => {
        const w = await apriIlModale([]);
        const opzioni = await salva(w);
        opzioni.onError!({ error: 'Nessun esercizio aperto per questo condominio.' });
        await w.vm.$nextTick();

        expect(testo(w)).toContain('Nessun esercizio aperto per questo condominio.');
    });

    test('riprovando, il rifiuto di prima sparisce', async () => {
        const w = await apriIlModale([]);
        const opzioni = await salva(w);
        opzioni.onError!({ anagrafica_id: RIFIUTO_SOLIDALE });
        await w.vm.$nextTick();
        expect(testo(w)).toContain(RIFIUTO_SOLIDALE);

        await salva(w);

        expect(testo(w)).not.toContain(RIFIUTO_SOLIDALE);
    });

    test('riaprendo il modale, il rifiuto di prima non c\'è', async () => {
        const w = await apriIlModale([]);
        const opzioni = await salva(w);
        opzioni.onError!({ anagrafica_id: RIFIUTO_SOLIDALE });
        await w.vm.$nextTick();
        await w.findAll('button').find((b) => b.text() === 'Annulla')!.trigger('click');
        await w.vm.$nextTick();
        await w.findAll('button').find((b) => b.text().includes('Aggiungi debito'))!.trigger('click');
        await w.vm.$nextTick();

        expect(testo(w)).toContain('Salva Debito');
        expect(testo(w)).not.toContain(RIFIUTO_SOLIDALE);
    });

    test('controprova — a richiesta riuscita il modale si chiude e non resta nessun rifiuto', async () => {
        const w = await apriIlModale([]);
        const opzioni = await salva(w);
        opzioni.onSuccess!();
        await w.vm.$nextTick();
        await new Promise((r) => setTimeout(r, 30));

        expect((w.vm as unknown as { showAddModal: boolean }).showAddModal).toBe(false);
        expect(testo(w)).not.toContain('Salva Debito');
        expect(w.find('[role="alert"]').exists()).toBe(false);
    });

    test('controprova — la richiesta porta ancora i dati di sempre: persona vuota per l\'intero immobile, importo in centesimi', async () => {
        const w = await apriIlModale([]);
        await salva(w);

        expect(router.post).toHaveBeenCalledWith('/admin.gestionale.saldi.store', { anagrafica_id: null, immobile_id: 1, gestione_id: 7, saldo_iniziale: 5000 }, expect.anything());
    });
});

describe('«Intero immobile» si spegne dove la gestione ha già un saldo per l\'intero immobile', () => {
    test('c\'è già un saldo per l\'intero immobile: la scelta è spenta, il perché è scritto accanto e il modale si apre su «Soggetto specifico»', async () => {
        const w = await apriIlModale([solidale(1)]);

        expect(radioSolidale(w).attributes('disabled')).toBeDefined();
        expect(testo(w)).toContain(PERCHE);
        expect(modulo(w).targetType).toBe('personale');
    });

    test('controprova — senza un saldo per l\'intero immobile la scelta è accesa, senza il perché, ed è quella di partenza', async () => {
        const w = await apriIlModale([personale(1)]);

        expect(radioSolidale(w).attributes('disabled')).toBeUndefined();
        expect(testo(w)).not.toContain(PERCHE);
        expect(modulo(w).targetType).toBe('solidale');
    });

    test('controprova — il saldo per l\'intero immobile di un\'altra gestione non spegne la scelta', async () => {
        const w = await apriIlModale([solidale(1, { gestione_id: ALTRA_GESTIONE.id, gestione: ALTRA_GESTIONE })]);

        expect(radioSolidale(w).attributes('disabled')).toBeUndefined();
        expect(modulo(w).targetType).toBe('solidale');
    });

    test('un id di gestione che arriva come stringa conta come il numero', async () => {
        const w = await apriIlModale([solidale(1, { gestione_id: '7' })]);

        expect(radioSolidale(w).attributes('disabled')).toBeDefined();
    });

    test('una riga per l\'intero immobile scritta da un passaggio non occupa il posto, come per il server', async () => {
        const w = await apriIlModale([solidale(1, { subentro_id: 3, e_conguaglio: true, subentro: { id: 3, decorrenza: '2026-03-12', tipo_passaggio: 'vendita' } })]);

        expect(radioSolidale(w).attributes('disabled')).toBeUndefined();
        expect(modulo(w).targetType).toBe('solidale');
    });
});
