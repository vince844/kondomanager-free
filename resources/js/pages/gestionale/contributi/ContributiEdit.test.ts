// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
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
 *
 * «Vale per» e «Versato da» sono `v-select`, come i menu del resto del programma: si scelgono come li sceglie la persona.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import vSelect from 'vue-select';

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

function monta(o: { vale_per?: 'tutti' | number | null; vale_per_diversi?: string[]; esercizi?: typeof ESERCIZI; righe?: typeof RIGHE; titolari?: Record<number, unknown[]>; esercizio_di_calcolo?: number | null; importo?: number; senza_titolare?: Record<number, Record<number, number>> } = {}) {
    pagina = mount(ContributiEdit, {
        attachTo: document.body,
        props: {
            condominio: { id: 3, nome: 'Condominio Demo' } as never,
            voce: { id: 11, nome: 'Spese generali', importo_cents: o.importo ?? 120000, gestione: 'Gestione ordinaria' },
            righe: o.righe ?? RIGHE,
            titolari: (o.titolari ?? { 1: [{ id: 7, nome: 'Ugo', tipologia: 'proprietario', quota: 100 }], 2: [{ id: 8, nome: 'Bice', tipologia: 'proprietario', quota: 100 }] }) as never,
            natura: 'avanzo', descrizione: 'Delibera del 12/05/2025', stima_semplificata: false, ripartizione_mista: false,
            liquidita_stato: 'registrata_in_cassa', cassa_id: 9, casse: [],
            esercizi: o.esercizi ?? ESERCIZI,
            vale_per: o.vale_per === undefined ? 27 : o.vale_per,
            vale_per_diversi: o.vale_per_diversi ?? [],
            esercizio_di_calcolo: o.esercizio_di_calcolo ?? null,
            senza_titolare: o.senza_titolare ?? {},
        },
        global: { stubs },
    });

    return pagina;
}

type Voce = { valore: number | string; etichetta: string };
const menu = () => pagina!.findAllComponents(vSelect);
const menuValePer = () => menu().find((m) => m.props('inputId') === 'vale-per')!;
const menuDiRiga = (i = 0) => menu().filter((m) => m.props('inputId') !== 'vale-per')[i];
const vociDi = (m: ReturnType<typeof menuValePer>) => m.props('options') as Voce[];
/** Sceglie dal menu la voce con quel valore, come un clic. */
async function scegli(m: ReturnType<typeof menuValePer>, valore: number | string) {
    (m.vm as unknown as { select: (o: unknown) => void }).select(vociDi(m).find((o) => o.valore === valore));
    await flushPromises();
}
const scelto = (m: ReturnType<typeof menuValePer>) => m.find('.vs__selected').exists() ? m.get('.vs__selected').text().replace(/\s+/g, ' ') : null;

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
        expect(vociDi(menuValePer()).map(o => o.etichetta)).toEqual(['Esercizio 2026', 'Esercizio 2027', 'Tutti gli esercizi']);
        expect(scelto(menuValePer())).toBe('Tutti gli esercizi');
        expect(document.body.textContent).toContain('La gestione di questa voce è in più esercizi');

        await scegli(menuValePer(), 26);
        await salva();
        expect(router.put.mock.calls[0][1].vale_per).toBe(26);

        await scegli(menuValePer(), 'tutti');
        await salva();
        expect(router.put.mock.calls[1][1].vale_per).toBe('tutti');
    });

    test('senza una scelta non salva, e dice che cosa manca', async () => {
        monta({ vale_per: null });
        expect(scelto(menuValePer())).toBeNull();
        expect(menuValePer().get('input').attributes('placeholder')).toBe('Scegli l\'esercizio…');
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

        await scegli(menuValePer(), 'tutti');
        expect(document.body.textContent).toContain('Effetto sui piani rate della voce, in tutti gli esercizi');
    });

    test('con una gestione di un esercizio solo e «Tutti gli esercizi» il riquadro dice il prossimo piano rate', async () => {
        monta({ vale_per: 'tutti', esercizi: [ESERCIZI[0]] });

        expect(document.body.textContent).toContain('Effetto sul prossimo piano rate');
        expect(document.body.textContent).not.toContain('in tutti gli esercizi');
    });
});

describe('«Versato da» offre anche chi ha venduto', () => {
    // Come le manda `ContributoVersatoController::edit`: prima il titolare di oggi, poi chi lo è stato, con i suoi periodi.
    const TITOLARI = {
        1: [
            { id: 7, nome: 'Elsa', tipologia: 'proprietario', quota: 100, chiusa_il: null, periodi: [], in_corso: true },
            { id: 9, nome: 'Ugo', tipologia: 'proprietario', quota: 100, chiusa_il: '2024-07-01', periodi: [{ dal: '2019-01-01', al: '2024-07-01' }], in_corso: false },
            { id: 10, nome: 'Rita', tipologia: 'usufruttuario', quota: 50, chiusa_il: '2018-12-31',
                periodi: [{ dal: null, al: '2009-12-31' }, { dal: '2012-03-05', al: '2018-12-31' }], in_corso: false },
        ],
    };
    const scelte = () => vociDi(menuDiRiga()).map(o => o.etichetta.replace(/\s+/g, ' '));

    test('accanto a chi non è più titolare c\'è il suo periodo, anche se sono due', () => {
        monta({ righe: [riga('1:7', 1, 7, 0)], titolari: TITOLARI });

        expect(scelte()).toEqual([
            'L\'unità (senza persona)',
            'Elsa · proprietario 100 %',
            'Ugo · proprietario 100 % · dal 01/01/2019 al 01/07/2024',
            'Rita · usufruttuario 50 % · fino al 31/12/2009 e dal 05/03/2012 al 31/12/2018',
        ]);
    });

    test('con la riga del titolare di oggi già presente, «+ persona» aggiunge chi ha venduto, e il salvataggio lo manda', async () => {
        monta({ righe: [riga('1:7', 1, 7, 12000)], titolari: TITOLARI });
        const piu = pagina!.findAll('button').find(b => b.text() === '+ persona');
        expect(piu).toBeDefined();

        await piu!.trigger('click');
        await flushPromises();
        await salva();

        expect(router.put.mock.calls[0][1].righe).toEqual([
            { immobile_id: 1, anagrafica_id: 7, gia_versato: 12000 },
            { immobile_id: 1, anagrafica_id: 9, gia_versato: 0 },
        ]);
    });

    test('scelto dal menu, chi ha venduto va al salvataggio con la sua cifra', async () => {
        monta({ righe: [riga('1:7', 1, 7, 50000)], titolari: TITOLARI });

        await scegli(menuDiRiga(), 9);
        await salva();

        expect(router.put.mock.calls[0][1].righe).toEqual([{ immobile_id: 1, anagrafica_id: 9, gia_versato: 50000 }]);
    });

    test('«L\'unità (senza persona)», con la maiuscola, si sceglie e va senza persona', async () => {
        monta({ righe: [riga('1:7', 1, 7, 500)], titolari: TITOLARI });
        expect(scelto(menuDiRiga())).toBe('Elsa · proprietario 100 %');

        await scegli(menuDiRiga(), 0);
        expect(scelto(menuDiRiga())).toBe('L\'unità (senza persona)');
        expect(menuDiRiga().element.parentElement!.classList.contains('w-64')).toBe(true);
        await salva();

        expect(router.put.mock.calls[0][1].righe).toEqual([{ immobile_id: 1, anagrafica_id: null, gia_versato: 500 }]);
    });

    test('una persona che ha già la sua riga sull\'unità si rifiuta, e il menu resta sulla scelta di prima', async () => {
        monta({ righe: [riga('1:7', 1, 7, 100), riga('1:9', 1, 9, 200)], titolari: TITOLARI });

        await scegli(menuDiRiga(1), 7);

        expect(toast).toHaveBeenCalledWith(expect.objectContaining({ title: 'Persona già presente' }));
        expect(scelto(menuDiRiga(1))).toBe('Ugo · proprietario 100 % · dal 01/01/2019 al 01/07/2024');
    });

    test('una persona salvata che non è fra le scelte lascia il menu vuoto, senza scrivere il suo numero', () => {
        monta({ righe: [riga('1:99', 1, 99, 100)], titolari: TITOLARI });

        expect(scelto(menuDiRiga())).toBeNull();
    });

    test('il menu della tabella si apre fuori dalla tabella, che scorre di lato: non lo taglia', () => {
        monta({ righe: [riga('1:7', 1, 7, 0)], titolari: TITOLARI });

        expect(menuDiRiga().props('appendToBody')).toBe(true);
        expect(menuValePer().props('appendToBody')).toBe(false);
        expect(pagina!.find('select').exists()).toBe(false);
    });
});

describe('L\'anteprima conta i giorni e la spesa dell\'unità una volta sola, come il piano', () => {
    // «Pulizie» € 700,00 sull'Interno 1; il 2024 di 366 giorni: Ugo fino al 01/07 (183), Elsa dal 02/07 (183). Le parti come le manda il server.
    const ES = [{ id: 24, nome: 'Esercizio 2024', stato: 'aperto' }];
    const TITOLARI = {
        1: [
            { id: 7, nome: 'Elsa', tipologia: 'proprietario', quota: 100, chiusa_il: null, periodi: [], in_corso: true, parti: { 24: 0.5 } },
            { id: 9, nome: 'Ugo', tipologia: 'proprietario', quota: 100, chiusa_il: '2024-07-01', periodi: [{ dal: '2019-01-01', al: '2024-07-01' }], in_corso: false, parti: { 24: 0.5 } },
        ],
    };
    const r700 = (chiave: string, persona: number | null, versato: number, lorda = 70000) => ({ ...riga(chiave, 1, persona, versato), quota_lorda: lorda, quota_lorda_unita: 70000 });
    const opzioni = { esercizi: ES, vale_per: 'tutti' as const, esercizio_di_calcolo: 24, titolari: TITOLARI, importo: 70000 };
    const celle = () => pagina!.findAll('tbody tr').map(tr => tr.findAll('td').map(td => td.text().replace(/\s+/g, ' ').trim()));
    const piede = () => pagina!.find('tfoot tr').findAll('td').map(td => td.text().replace(/\s+/g, ' ').trim());

    test('con la sola riga di Ugo a € 500,00: la sua quota è € 350,00, € 150,00 in eccesso, e si chiedono € 350,00 agli altri titolari', () => {
        monta({ ...opzioni, righe: [r700('1:9', 9, 50000, 70000)] });

        const [ugo, resto] = celle();
        expect(ugo[3]).toContain('350,00');
        expect(ugo[5]).toContain('0,00');
        expect(ugo[5]).toContain('150,00 in eccesso');
        expect(resto[1]).toBe('Gli altri titolari dell\'unità, per la loro parte');
        expect(resto[2]).toContain('350,00');
        expect(resto[4]).toContain('350,00');
        // Quota dovuta € 700,00, versato € 500,00, da chiedere € 350,00: le cifre del piano.
        expect(piede()[2]).toContain('700,00');
        expect(piede()[3]).toContain('500,00');
        expect(piede()[4]).toContain('350,00');
    });

    test('con le righe di Elsa e di Ugo sulla stessa unità la quota dovuta è € 700,00, non € 1.400,00', () => {
        monta({ ...opzioni, righe: [r700('1:7', 7, 0), r700('1:9', 9, 50000)] });

        expect(pagina!.find('[data-testid="resto-dell-unita"]').exists()).toBe(false);
        expect(piede()[2]).toContain('700,00');
        expect(piede()[2]).not.toContain('1.400,00');
        expect(piede()[4]).toContain('350,00');
    });

    test('«l\'unità» € 200,00 e Ugo € 300,00: € 250,00 a testa, Ugo a zero con € 50,00 in eccesso, a Elsa € 250,00', () => {
        monta({ ...opzioni, righe: [r700('1:0', null, 20000), r700('1:9', 9, 30000)] });

        const [unita, ugo] = celle();
        expect(ugo[3]).toContain('350,00');
        expect(ugo[5]).toContain('50,00 in eccesso');
        expect(unita[5]).toContain('250,00');
        expect(piede()[2]).toContain('700,00');
        expect(piede()[4]).toContain('250,00');
    });

    test('con un buco fra Ugo ed Elsa i giorni senza titolare non si chiedono a nessuno: «l\'unità» € 100,00 sconta solo la parte di chi c\'è', () => {
        // Ugo fino al 30/06, Elsa dal 15/07, nel 2024 di 366 giorni: le parti del motore 182/366 e 170/366, e 14/366 senza titolare (€ 26,78).
        // Si chiedono € 700,00 − € 26,78 − € 100,00 = € 573,22, come il piano (€ 348,09 + € 325,13 − € 100,00).
        const tit = { 1: [{ ...TITOLARI[1][0], parti: { 24: 0.464481 } }, { ...TITOLARI[1][1], parti: { 24: 0.497268 } }] };
        monta({ ...opzioni, titolari: tit, senza_titolare: { 1: { 24: 0.038251 } }, righe: [r700('1:0', null, 10000)] });

        const senza = pagina!.find('[data-testid="senza-titolare-dell-unita"]');
        expect(senza.exists()).toBe(true);
        expect(senza.text()).toContain('Giorni senza titolare');
        expect(senza.text()).toContain('26,78');
        expect(celle()[0][3]).toContain('673,22');
        expect(piede()[2]).toContain('700,00');
        expect(piede()[4]).toContain('573,22');
    });

    test('«Ripartisci per millesimi» dà a un\'unità con due righe il peso dei suoi millesimi una volta sola', async () => {
        // Due unità da 500 millesimi; sull'Interno 1 Elsa e Ugo, sull'Interno 2 Bice. € 1.000,00 raccolti: € 500,00 per unità.
        const tit = { ...TITOLARI, 2: [{ id: 8, nome: 'Bice', tipologia: 'proprietario', quota: 100, chiusa_il: null, periodi: [], in_corso: true, parti: { 24: 1 } }] };
        monta({ ...opzioni, titolari: tit, righe: [r700('1:7', 7, 0), r700('1:9', 9, 0), { ...riga('2:8', 2, 8, 0), quota_lorda: 70000, quota_lorda_unita: 70000 }] });
        await pagina!.findAllComponents({ name: 'MoneyInput' })[0].vm.$emit('update:modelValue', '1000,00');
        await pagina!.findAll('button').find(b => b.text().includes('Ripartisci per millesimi'))!.trigger('click');
        await salva();

        expect(router.put.mock.calls[0][1].righe).toEqual([
            { immobile_id: 1, anagrafica_id: 7, gia_versato: 25000 },
            { immobile_id: 1, anagrafica_id: 9, gia_versato: 25000 },
            { immobile_id: 2, anagrafica_id: 8, gia_versato: 50000 },
        ]);
    });
});
