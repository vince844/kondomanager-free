// @vitest-environment jsdom

/**
 * Le lettere nella pagina «Rate scadute»: la colonna «Ultima lettera» (e quella in coda o non partita sotto, a parte), i filtri «Nessuna lettera
 * negli ultimi N giorni» e «Mai scritto» nell'indirizzo, «Prepara le lettere» per le selezionate o per tutte le N dei filtri, «Prepara una
 * lettera» dalla riga, l'avviso delle lettere da rifare. E le parole vietate nel testo che si vede, nella pagina, nella finestra delle lettere
 * con l'anteprima, nel registro delle lettere, nella pagina della dispensa e nelle frasi delle librerie, con le due eccezioni (il testo
 * dell'art. 1129 c. 9 e il nome del tipo «Messa in mora»).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const routerGet = vi.fn();
const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: (...a: unknown[]) => routerGet(...a), reload: vi.fn(), post: vi.fn(), delete: vi.fn() },
    usePage: () => ({ props: { errors: {} } }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}` }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri: Record<string, unknown> = {}) =>
    `/${nome}?${new URLSearchParams(Object.entries(parametri).flatMap(([k, v]) => (v === undefined ? [] : [[k, String(v)]]))).toString()}`;

const Pagina = (await import('./RateScadute.vue')).default;
const LettereDialog = (await import('@/components/gestionale/lettere/LettereDialog.vue')).default;
const RegistroLettere = (await import('@/components/gestionale/lettere/RegistroLettere.vue')).default;
const Dispense = (await import('@/pages/gestionale/esercizi/DispenseSeiMesi.vue')).default;
const librerie = [await import('@/lib/gestionale/lettere/lettere'), await import('@/lib/gestionale/debiti/rateScadute'), await import('@/lib/gestionale/debiti/originePregresso')];

const NORMA = '«Salvo che non sia stato espressamente dispensato dall\'assemblea, l\'amministratore è tenuto ad agire per la riscossione forzosa del credito esigibile…»';
const VIETATE = ['moros', 'esigibile', 'diffidat', 'è in mora', 'costituito in mora', 'in mora dal', 'ingiungibil', 'ingiunzion', 'decadut', 'termine scaduto', 'grave irregolarità',
    'consegnata', 'recapitata', 'notificata', 'valore legale', 'novazione', 'transazione'];
const vietateIn = (t: string) => {
    const pulito = t.replaceAll(NORMA, ' ').replaceAll('Messa in mora', ' ').toLowerCase();

    return VIETATE.filter((p) => pulito.includes(p));
};

const riga = (extra: Record<string, unknown> = {}) => ({
    anagrafica_id: 7, nome: 'Ugo Bianchi', unita: ['Interno 1'], importo: 15000, credito: 0,
    fasce: { '1_30': 5000, '31_90': 10000, oltre_90: 0 }, ritardo_testo: 'in ritardo di 43 giorni', sei_mesi: { data: '2027-06-30', dispensa: null, mancano: 0 },
    con_pregresso: false, origine_non_dichiarata: false, rate: [],
    ultima_lettera: { tipo: 'sollecito', data: '2026-03-10', canale: 'email', stato: 'inviata', destinatari: ['ugo@x.it'] },
    lettera_in_sospeso: { tipo: 'diffida', data: '2026-03-18', canale: 'stampa', stato: 'in_coda' },
    ...extra,
});
const proprieta = (extra: Record<string, unknown> = {}) => ({
    condominio: { id: 3, nome: 'Condominio Via dei Pini' }, condomini: [], esercizio: { id: 9 },
    righe: { data: [riga()], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 } },
    totale: 15000, persone_totali: 1,
    filtri: { alla: '2026-03-20', vista: 'scadute', giorni: 30, cerca: null, anagrafica_id: null, fascia: null, minimo: null, gestione_id: null, sei_mesi_entro: null, con_pregresso: false,
        ordine: 'scaduto', senza_lettere_da: null, mai_scritto: false },
    filtri_ricordati: false, oggi: '2026-03-20', non_emesse: null, gestioni: [], persona_della_porta: null, testo_norma: NORMA,
    lettere_da_riprovare: 2, lettere_in_coda_da_tempo: 1,
    ...extra,
});

let w: VueWrapper | null = null;
const stubs = { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true };
const monta = (extra: Record<string, unknown> = {}) => {
    w = mount(Pagina, { props: proprieta(extra) as any, global: { stubs: { ...stubs, LettereDialog: true } } });

    return w;
};
afterEach(() => { w?.unmount(); w = null; routerGet.mockReset(); axiosGet.mockReset(); axiosPost.mockReset(); document.body.innerHTML = ''; vi.useRealTimers(); });
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();

describe('le lettere nell\'elenco', () => {
    test('la colonna «Ultima lettera»: l\'ultima che conta, e sotto quella in coda; l\'avviso delle lettere da rifare', () => {
        const t = pulito(monta().get('[data-testid="ultima-lettera"]').text());
        expect(t).toContain('Sollecito inviato il 10/03/2026 a ugo@x.it, consegna non provata');
        expect(t).toContain('poi: Diffida in coda dal 18/03/2026');
        expect(pulito(w!.get('[data-testid="lettere-da-riprovare"]').text())).toContain('2 lettere per email non sono partite.');
        expect(pulito(w!.get('[data-testid="lettere-in-coda"]').text())).toBe('1 lettera per email è in coda da più di un\'ora: non è ancora partita. Di solito vuol '
            + 'dire che sul server non gira il servizio che manda le email in coda: chi gestisce l\'installazione controlla che sia attivo (SCHEDULE_QUEUE_WORKER nel '
            + 'file .env, o un processo queue:work).');
        expect(pulito(monta({ righe: { data: [riga({ ultima_lettera: null, lettera_in_sospeso: null })], meta: proprieta().righe.meta } }).get('[data-testid="ultima-lettera"]').text()))
            .toContain('Nessuna lettera');
    });

    test('i filtri «Nessuna lettera negli ultimi N giorni» e «Mai scritto» vanno nell\'indirizzo', async () => {
        vi.useFakeTimers();
        const p = monta();
        await p.get('[data-testid="filtro-senza-lettere"]').setValue('30');
        await p.get('[data-testid="filtro-mai-scritto"] button').trigger('click');
        vi.advanceTimersByTime(400);
        const indirizzo = routerGet.mock.calls.at(-1)![0] as string;
        expect(indirizzo).toContain('senza_lettere_da=30');
        expect(indirizzo).toContain('mai_scritto=1');
    });

    test('«Prepara le lettere» apre la finestra per tutte le N dei filtri; «Prepara una lettera» per la persona della riga', async () => {
        const p = monta();
        await p.get('[data-testid="prepara-lettere"]').trigger('click');
        let finestra = p.findComponent({ name: 'LettereDialog' });
        expect(finestra.props('open')).toBe(true);
        expect(finestra.props('selezione')).toEqual({ tutte: true, ids: [] });
        expect(finestra.props('persona')).toBeNull();
        await p.get('[data-testid="scrivi-7"]').trigger('click');
        finestra = p.findComponent({ name: 'LettereDialog' });
        expect(finestra.props('persona')).toEqual({ id: 7, nome: 'Ugo Bianchi' });
    });
});

describe('le parole vietate nel testo che si vede', () => {
    test('la pagina, la finestra con l\'anteprima, il registro, la pagina della dispensa e le frasi delle librerie', async () => {
        const testi: Record<string, string> = {};
        const pagina = mount(Pagina, { props: proprieta({ righe: { data: [riga({ con_pregresso: true, origine_non_dichiarata: true })], meta: proprieta().righe.meta } }) as any, global: { stubs } });
        testi.pagina = pagina.text();
        pagina.unmount();

        axiosGet.mockResolvedValue({ data: {
            tipi: [{ tipo: 'promemoria', nome: 'Promemoria' }, { tipo: 'sollecito', nome: 'Sollecito' }, { tipo: 'diffida', nome: 'Diffida' }, { tipo: 'messa_in_mora', nome: 'Messa in mora' }],
            testi: { promemoria: '', sollecito: '', diffida: '', messa_in_mora: '' }, conti: [], scelta_ricordata: { termini: {}, cassa_id: null, indirizzi: null },
            studio_compilato: false, senza_server_posta: true, puo_salvare_il_testo: true } });
        axiosPost.mockResolvedValue({ data: { tipo: 'messa_in_mora', conteggi: { email: 0, stampa: 1, zip: 1 }, credito: [{ nome: 'Ugo', credito: 100 }], senza_niente: ['Bice'], oltre_tetto: true, tetto: 200, senza_server_posta: true,
            persone: [{ id: 1, nome: 'Ugo', totale: 100, righe: 1, credito: 100, canale: 'stampa', canali: ['stampa', 'zip'], indirizzi: [], senza_email: true, solo_pec: true, senza_indirizzo_postale: true }] } });
        const finestra = mount(LettereDialog, { attachTo: document.body, props: { open: false, condominioId: 3, oggi: '2026-03-20', selezione: { tutte: true, ids: [] } } });
        await finestra.setProps({ open: true });
        await flushPromises();
        (document.body.querySelector('input[value="messa_in_mora"]') as HTMLInputElement).click();
        await flushPromises();
        (document.body.querySelector('[data-testid="anteprima"]') as HTMLButtonElement).click();
        await flushPromises();
        testi.finestra = document.body.textContent ?? '';
        finestra.unmount();

        const registro = mount(RegistroLettere, { props: { condominioId: 3, oggi: '2026-03-20', lettere: [
            { lettera: 'a', tipo: 'messa_in_mora', emessa_il: '2026-03-20', ora: '10:00', emesso_da: 'Anna', totale: 100, residuo_oggi: 0, alla: '2026-03-20', termine: 15, stato: 'spedita',
                righe: [{ id: 1, canale: 'stampa', formato: 'zip', destinatario: null, stato: 'spedita', quando: '2026-03-20 10:00', errore: null, spedizione: { canale: 'pec', data: '2026-03-21' }, spedibile: false, rimandabile: false },
                    { id: 2, canale: 'email', formato: null, destinatario: 'u@x.it', stato: 'inviata', quando: '2026-03-20 10:00', errore: null, spedizione: null, spedibile: false, rimandabile: true }],
                pdf: { invio_id: 1, nome: 'm.pdf', impronta: 'x', verificato: false }, prove: [{ id: 3, invio_id: 1, nome: 'p.eml', aggiunta_il: '2026-03-22', rimovibile: true }],
                eventi: [{ evento: 'spedizione', il: '2026-03-21T10:00:00Z' }, { evento: 'prova_tolta', il: '2026-03-22T10:00:00Z' }] },
        ] as any } });
        testi.registro = registro.text();
        registro.unmount();

        const dispense = mount(Dispense, { props: { condominio: { id: 3, nome: 'C' }, condomini: [], esercizio: null, esercizi: [{ id: 1, nome: 'Esercizio 2026', stato: 'aperto' }],
            persone: [{ id: 7, nome: 'Ugo' }], dispense: [{ id: 1, data_delibera: '2027-03-20', numero_verbale: '12', nota: null, tutte_le_persone: true, esercizi: [{ id: 1, nome: 'Esercizio 2026' }],
                persone: [], revocata_il: '2027-06-01', nota_revoca: null, verbale: null }], testo_norma: NORMA, oggi: '2027-07-10' } as any,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true } } });
        testi.dispense = dispense.text();
        dispense.unmount();

        for (const [i, lib] of librerie.entries()) {
            testi[`libreria ${i}`] = Object.values(lib).filter((v) => typeof v === 'string').join(' ');
        }

        const trovate = Object.entries(testi).flatMap(([dove, t]) => vietateIn(t).map((p) => `${dove}: ${p}`));
        expect(Object.keys(testi)).toHaveLength(7);
        expect(testi.finestra).toContain('Messa in mora');
        expect(trovate).toEqual([]);
        // L'eccezione c'è davvero: la norma citata, fuori dall'eccezione, farebbe scattare la regola.
        expect(vietateIn(NORMA)).toEqual([]);
        expect(vietateIn('il credito esigibile')).toEqual(['esigibile']);
        expect(testi.pagina).toContain(NORMA);
    });
});
