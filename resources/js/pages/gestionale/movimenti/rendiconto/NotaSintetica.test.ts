// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scheda «Nota sintetica»: i fatti proposti, nessuno tenuto di serie; tieni, togli, riscrivi e «Tieni tutti»; il fatto riscritto che oggi direbbe
 * altro; le cause accanto alle voci; il controllo che chiede la spiegazione; la copia che mostra prima che cosa porta (mai i fatti); il contratto scaduto;
 * i fornitori suggeriti; il salvataggio con le cifre in euro; nessuna parola che il programma non scrive. Il saldo della banca e la spiegazione che si
 * stampano senza «Tieni»; le cifre scritte con il punto; la copia da un altro condominio; la dichiarazione e i dipendenti da confermare; il testo
 * dell'amministratore come testo; il riquadro delle cose da completare; i pulsanti sotto le schede; «Genera la nota» e il suo registro.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { nextTick } from 'vue';
import { flushPromises, mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils';

const { put, reload, axiosGet, axiosPost, axiosPut, axiosDelete } = vi.hoisted(() => ({ put: vi.fn(), reload: vi.fn(), axiosGet: vi.fn(), axiosPost: vi.fn(), axiosPut: vi.fn(), axiosDelete: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: {} }),
    router: { put, reload },
}));
vi.mock('axios', () => ({ default: { get: axiosGet, post: axiosPost, put: axiosPut, delete: axiosDelete } }));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generateRoute: (nome: string) => nome,
        generatePath: (p: string, parametri: Record<string, unknown> = {}) => `/${Object.entries(parametri).reduce((s, [k, v]) => s.replace(`:${k}`, String(v)), p)}`,
    }),
}));
(globalThis as any).route = (nome: string) => `/${nome}`;

const Pagina = (await import('./NotaSintetica.vue')).default;

const NB = ' ';
const fatto = (chiave: string, parte: string, frase: string, extra: Record<string, unknown> = {}) => ({
    chiave, parte, frase, righe: [], coda: null, fonte: 'Il prospetto', scheda: 'sintesi', voto: null, spiegazione: false, cause: false, stato: 'proposto',
    riscritto: null, riscritto_il: null, proposto_allora: null, cambiato: false, testo_di_oggi: frase, ...extra,
});
const vuoto = () => ({
    parti: { criteri: { testo: '' }, gestione: { testo: '' }, rapporti: { testo: '' }, questioni: { testo: '' }, altre: { testo: '' } }, fatti: {}, cause: {}, spiegazioni: {},
    contratti: [], dipendenti: { stato: null, testo: '' }, debiti_certi: [],
    dichiarazione: { liti: { stato: null, testo: '' }, impugnazioni: { stato: null, testo: '' }, sinistri: { stato: null, testo: '' } }, da_decidere: [],
});
const G1 = `Le spese dell'esercizio sono €${NB}4.200,00 e le quote chieste ai condòmini con le rate sono €${NB}4.000,00: le spese superano le quote di €${NB}200,00 (disavanzo).`;
const G2 = fatto('G2', 'gestione', 'Le voci che si sono scostate da quanto l\'assemblea ha approvato con le rate:', {
    righe: [{ voce_id: 12, testo: `Pulizie: €${NB}3.300,00 contro €${NB}3.000,00 (€${NB}300,00 in più).`, scostamento: 30000 }], cause: true, scheda: 'riepilogo',
    testo_di_oggi: `Le voci che si sono scostate da quanto l'assemblea ha approvato con le rate:\n– Pulizie: €${NB}3.300,00 contro €${NB}3.000,00 (€${NB}300,00 in più).`,
});
const nota = (contenuto = vuoto(), extra: Record<string, unknown> = {}) => ({
    parti: [
        { chiave: 'criteri', titolo: '1. I criteri del rendiconto', testo: '', fatti: [fatto('K1', 'criteri', 'Il registro di contabilità è per cassa.')] },
        { chiave: 'gestione', titolo: '2. La gestione dell\'anno', testo: '', fatti: [fatto('G1', 'gestione', G1, { voto: null }), G2,
            fatto('G9:R8', 'gestione', 'Il controllo «Vincoli e casse fondo» è da leggere.', { spiegazione: true, controllo: 'R8', scheda: 'controlli' }),
            fatto('G7', 'gestione', 'La proposta di conguaglio …', { voto: `La proposta di conguaglio: €${NB}280,00 in tutto.` })] },
        { chiave: 'rapporti', titolo: '3. I rapporti in corso', testo: '', fatti: [] },
        { chiave: 'questioni', titolo: '4. Le questioni pendenti', testo: '', fatti: [] },
        { chiave: 'altre', titolo: '5. Altre informazioni', testo: '', fatti: [] },
    ],
    non_proposti: [{ chiave: 'G3', perche: 'È il primo esercizio nel programma.' }], spariti: [], contenuto,
    fornitori: [{ categoria: 'Servizi di pulizia', fornitore: 'Pulizie Lucenti' }], mancanze: ['La dichiarazione sulle liti, le impugnazioni e i sinistri non è fatta.'],
    parole: { parole: 0, pagine: 0 }, vuota: true, oggi: '2027-03-01', ...extra,
});
const proprieta = (n = nota(), extra: Record<string, unknown> = {}) => ({
    condominio: { id: 3, nome: 'Via Verdi' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026', data_fine: '2026-12-31' }, esercizi: [], nota: n,
    anno_prima: { id: 8, nome: 'Esercizio 2025' }, altri: [{ esercizio_id: 21, esercizio: 'Esercizio 2026', condominio_id: 5, condominio: 'Via Gialli' }],
    testi_studio: [{ id: 1, tipo: 'altre', nome: 'Documenti in studio', testo: 'I documenti si vedono in studio.', per_tutti: true }], puo_salvare_testi: true,
    tipi_contratto: { contratto: 'Contratto', polizza: 'Polizza', verifica: 'Verifica periodica' },
    dichiarazione: { liti: { domanda: 'Cause in corso', nessuna: 'non ci sono cause', ci_sono: 'Cause in corso' },
        impugnazioni: { domanda: 'Delibere impugnate', nessuna: 'nessuna delibera', ci_sono: 'Delibere impugnate' },
        sinistri: { domanda: 'Sinistri aperti', nessuna: 'non ci sono sinistri', ci_sono: 'Sinistri aperti' } },
    lunghezza: 5000, righe_elenco: 30, ...extra,
});

let w: VueWrapper | null = null;
const monta = (p = proprieta()) => {
    const slot = { template: '<div><slot /><slot name="actions" /></div>' };
    w = mount(Pagina, { props: p as any, global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, PageHeaderGuide: slot } } });
    return w;
};
/** Un menu del programma (`v-select`): aperto, le sue voci; e la scelta di una voce con un clic. */
const vociDelMenu = async (menu: DOMWrapper<Element>) => {
    await menu.find('.vs__search').trigger('focus');
    return menu.findAll('.vs__dropdown-option').map((o) => o.text().replace(/\s+/g, ' ').trim());
};
const scegliNelMenu = async (menu: DOMWrapper<Element>, testo: string) => {
    await vociDelMenu(menu);
    await menu.findAll('.vs__dropdown-option').find((o) => o.text().replace(/\s+/g, ' ').trim() === testo)!.trigger('click');
    await flushPromises();
};
const statoDi = (k: string) => w!.find(`[data-fatto="${k}"]`).attributes('data-stato');
const contenutoSalvato = () => put.mock.calls.at(-1)![1].contenuto;

beforeEach(() => { put.mockReset(); reload.mockReset(); axiosGet.mockReset(); axiosPost.mockReset(); axiosPut.mockReset(); axiosDelete.mockReset(); });
afterEach(() => { w?.unmount(); w = null; });

describe('la scheda «Nota sintetica»', () => {
    test('i fatti proposti, nessuno tenuto; tieni, togli, rimetti; «Tieni tutti i fatti di questa parte»; la scheda accesa', async () => {
        monta();
        expect(w!.find('[data-scheda="nota"]').attributes('aria-current')).toBe('page');
        expect(['K1', 'G1', 'G2', 'G9:R8', 'G7'].map(statoDi)).toEqual(['proposto', 'proposto', 'proposto', 'proposto', 'proposto']);
        expect(w!.find('[data-fatto="G1"] [data-test="frase"]').text()).toBe(G1);
        await w!.find('[data-fatto="G1"] [data-test="tieni"]').trigger('click');
        await w!.find('[data-fatto="K1"] [data-test="togli"]').trigger('click');
        expect(statoDi('G1')).toBe('tenuto');
        expect(statoDi('K1')).toBe('tolto');
        await w!.find('[data-fatto="K1"] [data-test="rimetti"]').trigger('click');
        expect(statoDi('K1')).toBe('proposto');
        await w!.find('[data-parte="gestione"] [data-test="tieni-tutti-parte"]').trigger('click');
        expect(['K1', 'G1', 'G2', 'G9:R8', 'G7'].map(statoDi)).toEqual(['proposto', 'tenuto', 'tenuto', 'tenuto', 'tenuto']);
        // I fatti tenuti che chiedono un voto vanno fra le cose da decidere.
        expect(w!.find('[data-test="da-decidere"]').text()).toContain(`La proposta di conguaglio: €${NB}280,00 in tutto.`);
        expect(w!.find('[data-parte="gestione"] [data-test="non-proposti"]').text()).toContain('È il primo esercizio nel programma.');
    });

    test('«Tieni tutti i fatti proposti» non rimette quelli tolti; riscrivi: la frase dell\'amministratore, con quella del programma di quel giorno', async () => {
        monta();
        await w!.find('[data-fatto="K1"] [data-test="togli"]').trigger('click');
        await w!.find('[data-test="tieni-tutti"]').trigger('click');
        expect(statoDi('K1')).toBe('tolto');
        expect(statoDi('G1')).toBe('tenuto');
        await w!.find('[data-fatto="G1"] [data-test="riscrivi"]').trigger('click');
        await w!.find('[data-fatto="G1"] [data-test="riscrittura"] textarea').setValue('Abbiamo speso € 200,00 più delle quote.');
        await w!.find('[data-fatto="G1"] [data-test="conferma-riscrittura"]').trigger('click');
        expect(w!.find('[data-fatto="G1"] [data-test="frase"]').text()).toBe('Abbiamo speso € 200,00 più delle quote.');
        await w!.find('[data-test="salva-nota"]').trigger('click');
        expect(put).toHaveBeenCalledWith('/gestionale.esercizi.rendiconto.nota.update', expect.anything(), expect.anything());
        expect(contenutoSalvato().fatti.G1).toEqual({ stato: 'tenuto', riscritto: 'Abbiamo speso € 200,00 più delle quote.', proposto: G1, il: '2027-03-01' });
        expect(contenutoSalvato().fatti.K1).toEqual({ stato: 'tolto' });
    });

    test('il fatto riscritto quando oggi il programma direbbe altro: la pagina lo dice, con la data', () => {
        const c = vuoto();
        (c.fatti as any).G1 = { stato: 'tenuto', riscritto: 'La mia frase.', proposto: 'La frase di allora.', il: '2027-02-10' };
        const n = nota(c);
        n.parti[1].fatti[0] = { ...n.parti[1].fatti[0], cambiato: true, testo_di_oggi: 'La frase di oggi.' } as any;
        monta(proprieta(n));
        expect(w!.find('[data-fatto="G1"] [data-test="cambiato"]').text()).toBe('Hai riscritto questo fatto il 10/02/2027; oggi il programma direbbe: «La frase di oggi.»');
    });

    test('la causa accanto alla voce scostata e la spiegazione del controllo vanno nel contenuto', async () => {
        monta();
        await w!.find('[data-fatto="G2"] [data-test="cause"] textarea').setValue('Il contratto è cambiato a marzo.');
        await w!.find('[data-fatto="G9:R8"] [data-test="spiegazione"] textarea').setValue('I vincoli si leggono a oggi.');
        await w!.find('[data-test="salva-nota"]').trigger('click');
        expect(contenutoSalvato().cause).toEqual({ 12: 'Il contratto è cambiato a marzo.' });
        expect(contenutoSalvato().spiegazioni).toEqual({ R8: 'I vincoli si leggono a oggi.' });
        expect(w!.find('[data-fatto="G9:R8"] [data-test="spiegazione"]').text()).toContain('con la spiegazione il fatto si stampa, senza no');
    });

    test('gli elenchi: il contratto scaduto prima della fine dell\'esercizio, il fornitore suggerito, le cifre in euro mandate con il punto', async () => {
        const c = vuoto();
        (c.contratti as any) = [{ tipo: 'contratto', cosa: 'Pulizie', con_chi: 'Lucenti', importo: 300000, scadenza: '2026-06-30', rinnovo: '', note: '' }];
        (c.debiti_certi as any) = [{ creditore: 'Studio legale', importo: 350050, fonte: 'sentenza', scadenza: null }];
        monta(proprieta(nota(c)));
        expect(w!.find('[data-contratto="0"] input[placeholder="Importo in euro"]').element).toHaveProperty('value', '3.000,00');
        expect(w!.find('[data-test="contratto-scaduto"]').text()).toBe('Scaduto il 30/06/2026, prima della fine dell\'esercizio: aggiorna la data o togli la riga.');
        expect(w!.find('[data-test="fornitori"]').text()).toContain('Servizi di pulizia · Pulizie Lucenti');
        await w!.find('[data-test="aggiungi-fornitore"]').trigger('click');
        await w!.find('[data-contratto="1"] input[placeholder="Importo in euro"]').setValue('1.234,50');
        await w!.find('[data-test="salva-nota"]').trigger('click');
        expect(contenutoSalvato().contratti.map((r: any) => [r.cosa, r.con_chi, r.importo, r.scadenza])).toEqual([
            ['Pulizie', 'Lucenti', '3000.00', '2026-06-30'], ['Servizi di pulizia', 'Pulizie Lucenti', '1234.50', null]]);
        expect(contenutoSalvato().debiti_certi[0].importo).toBe('3500.50');
    });

    test('«Copia dall\'anno prima»: prima si vede che cosa arriva, e i fatti non arrivano mai', async () => {
        const c = vuoto();
        (c.fatti as any).G1 = { stato: 'tenuto' };
        monta(proprieta(nota(c)));
        const copiato = vuoto();
        copiato.parti.altre.testo = 'I documenti si vedono in studio.';
        (copiato.contratti as any) = [{ tipo: 'polizza', cosa: 'Globale fabbricati', con_chi: 'Prova', importo: 120000, scadenza: '2027-06-30', rinnovo: '', note: '' }];
        axiosGet.mockResolvedValue({ data: { da: 'la bozza della nota di «Esercizio 2025»', contenuto: copiato } });
        await w!.find('[data-test="copia-anno-prima"]').trigger('click');
        await flushPromises();
        expect(axiosGet).toHaveBeenCalledWith('/gestionale.esercizi.rendiconto.nota.copia?da=8');
        expect(w!.find('[data-test="copia"]').text()).toContain('i fatti non si copiano mai');
        await w!.find('[data-test="applica-copia"]').trigger('click');
        await w!.find('[data-test="salva-nota"]').trigger('click');
        expect(contenutoSalvato().parti.altre.testo).toBe('I documenti si vedono in studio.');
        expect(contenutoSalvato().contratti[0]).toMatchObject({ tipo: 'polizza', importo: '1200.00' });
        expect(contenutoSalvato().fatti).toEqual({ G1: { stato: 'tenuto' } });
    });

    test('il saldo secondo la banca: la riga che lo spiega, il saldo registrato accanto, «Coincidono» o la differenza con il verso, la spiegazione; si salva in euro', async () => {
        const banche = [{ cassa_id: 4, cassa: 'Banca principale', data: '2026-12-31', estratto: null, contabilita: 465000, differenza: null, esito: 'non_calcolato', spiegazione: '' }];
        monta(proprieta(nota(vuoto(), { banche })));
        const riga = () => w!.find('[data-banca="4"]');
        const riquadro = w!.find('[data-test="banche"]').text().replace(/\s+/g, ' ');
        expect(riquadro).toContain('Il saldo secondo la banca (dal suo estratto conto)');
        expect(w!.find('[data-test="spiega-saldo-banca"]').text()).toContain('È il saldo che leggi sull\'estratto conto che ti manda la banca');
        expect(riquadro).toContain('Saldo secondo la banca');
        expect(riquadro).toContain('registrato in KondoManager al 31/12/2026');
        expect(riquadro).not.toMatch(/Saldo dell'estratto|Data dell'estratto|nell'estratto/);
        expect(riga().find('[data-test="contabilita"]').text()).toBe(`€${NB}4.650,00`);
        await riga().find('[data-test="saldo-banca"]').setValue('4.650,00');
        expect(riga().find('[data-test="esito-banca"]').text()).toBe('Coincidono.');
        expect(riga().find('[data-testo="spiegazione"]').exists()).toBe(false);
        await riga().find('[data-test="saldo-banca"]').setValue('4.700,00');
        expect(riga().find('[data-test="esito-banca"]').text()).toBe(`La differenza è €${NB}50,00, in più secondo la banca.`);
        await riga().find('[data-testo="spiegazione"] textarea').setValue('Un versamento non ancora registrato.');
        await w!.find('[data-test="salva-nota"]').trigger('click');
        expect(contenutoSalvato().banca).toEqual({ 4: { data: '2026-12-31', saldo: '4700.00', spiegazione: 'Un versamento non ancora registrato.' } });
    });

    test('le mancanze, le parole della nota, i testi dello studio; nessuna parola che il programma non scrive', async () => {
        monta();
        expect(w!.find('[data-test="mancanze"]').text()).toContain('La dichiarazione sulle liti, le impugnazioni e i sinistri non è fatta.');
        expect(w!.find('[data-test="parole"]').text()).toContain('circa 0 parole');
        // G1 tenuto: 27 parole, contate a mano (il simbolo dell'euro, staccato dalla cifra dallo spazio che non va a capo, conta come una parola).
        await w!.find('[data-fatto="G1"] [data-test="tieni"]').trigger('click');
        expect(w!.find('[data-test="parole"]').text()).toContain('circa 27 parole, circa 1 pagina');
        const altre = w!.find('[data-parte="altre"] [data-testo="altre"]');
        await scegliNelMenu(altre.find('[data-test="usa-testo"]'), 'Documenti in studio');
        expect((altre.find('textarea').element as HTMLTextAreaElement).value).toBe('I documenti si vedono in studio.');
        const t = w!.text().toLowerCase();
        for (const parola of ['pronto', 'conforme', 'corretto', 'approvabile', 'in regola', 'regolare', 'esigibile', 'dovuto', 'nessuna lite']) {
            expect(t).not.toMatch(new RegExp(`(^|[^\\p{L}])${parola}($|[^\\p{L}])`, 'u'));
        }
    });
    test('il saldo della banca scritto e la spiegazione scritta: la pagina dice che si stampano senza «Tieni», e li conta fra le parole', async () => {
        const c = vuoto();
        (c as any).banca = { 4: { data: '2026-12-31', saldo: 465000, spiegazione: '' } };
        const n = nota(c, { banche: [{ cassa_id: 4, cassa: 'Banca principale', data: '2026-12-31', estratto: 465000, contabilita: 465000, differenza: 0, esito: 'quadra', spiegazione: '' }] });
        n.parti[1].fatti.push(fatto('G6B:4', 'gestione', `Il conto «Banca principale» al 31/12/2026: secondo la banca (dal suo estratto conto) €${NB}4.650,00, secondo la contabilità €${NB}4.650,00: coincidono.`, { banca: '4', scheda: 'situazione' }) as any);
        monta(proprieta(n));
        expect(w!.find('[data-fatto="G6B:4"] [data-test="stato-fatto"]').text()).toBe('Si stampa: hai scritto il saldo. «Togli» per non stamparlo');
        expect(w!.find('[data-fatto="G9:R8"] [data-test="stato-fatto"]').text()).toBe('Proposto: non si stampa finché non lo tieni');
        const prima = Number(/circa (\d+) parole/.exec(w!.find('[data-test="parole"]').text())![1]);
        expect(prima).toBeGreaterThan(0);
        await w!.find('[data-fatto="G9:R8"] [data-test="spiegazione"] textarea').setValue('I vincoli si leggono a oggi.');
        expect(w!.find('[data-fatto="G9:R8"] [data-test="stato-fatto"]').text()).toBe('Si stampa: hai scritto la spiegazione. «Togli» per non stamparlo');
        expect(Number(/circa (\d+) parole/.exec(w!.find('[data-test="parole"]').text())![1])).toBeGreaterThan(prima);
        await w!.find('[data-fatto="G6B:4"] [data-test="togli"]').trigger('click');
        expect(w!.find('[data-fatto="G6B:4"] [data-test="stato-fatto"]').text()).toBe('Tolto');
    });

    test('le cifre scritte con il punto dei decimali restano quelle: «1500.00» si salva 1500.00, non 150000', async () => {
        monta();
        await w!.find('[data-test="aggiungi-contratto"]').trigger('click');
        await w!.find('[data-contratto="0"] input[placeholder="Importo in euro"]').setValue('1500.00');
        await w!.find('[data-test="aggiungi-debito"]').trigger('click');
        await w!.find('[data-test="debiti-certi"] input[placeholder="Importo in euro"]').setValue('4700.50');
        await w!.find('[data-test="salva-nota"]').trigger('click');
        expect(contenutoSalvato().contratti[0].importo).toBe('1500.00');
        expect(contenutoSalvato().debiti_certi[0].importo).toBe('4700.50');
    });

    test('la copia da un altro condominio: di serie i testi e i contratti; i debiti, le cose da decidere e la dichiarazione no; le righe si vedono', async () => {
        monta();
        const copiato = vuoto();
        copiato.parti.altre.testo = 'Il testo dell\'altro palazzo.';
        (copiato.contratti as any) = [{ tipo: 'contratto', cosa: 'Ascensore', con_chi: 'Ascensori Prova', importo: null, scadenza: null, rinnovo: '', note: '' }];
        (copiato.debiti_certi as any) = [{ creditore: 'Studio legale dell\'altro', importo: 350000, fonte: 'sentenza', scadenza: null }];
        (copiato as any).da_decidere = ['Il tetto dell\'altro palazzo'];
        (copiato.dichiarazione as any).liti = { stato: 'nessuna', testo: '', da_confermare: true };
        axiosGet.mockResolvedValue({ data: { da: 'la bozza della nota di «Esercizio 2026», condominio «Via Gialli»', contenuto: copiato, altro_condominio: true } });
        await scegliNelMenu(w!.find('[data-test="copia-altro"]'), 'Via Gialli · Esercizio 2026');
        await flushPromises();
        const finestra = w!.find('[data-test="copia"]');
        expect(finestra.find('[data-test="copia-altro-avviso"]').exists()).toBe(true);
        expect((finestra.find('[data-cosa="testi"] input').element as HTMLInputElement).checked).toBe(true);
        expect((finestra.find('[data-cosa="contratti"] input').element as HTMLInputElement).checked).toBe(true);
        for (const k of ['debiti_certi', 'da_decidere', 'dichiarazione']) expect((finestra.find(`[data-cosa="${k}"] input`).element as HTMLInputElement).checked).toBe(false);
        expect(finestra.text()).toContain('Contratto · Ascensore · Ascensori Prova');
        expect(finestra.text()).toContain('Studio legale dell\'altro');
        expect(finestra.text()).toContain('Il tetto dell\'altro palazzo');
        expect(finestra.find('[data-cosa="testi"]').text()).toContain('Altre informazioni');
        await w!.find('[data-test="applica-copia"]').trigger('click');
        await w!.find('[data-test="salva-nota"]').trigger('click');
        expect(contenutoSalvato().parti.altre.testo).toBe('Il testo dell\'altro palazzo.');
        expect(contenutoSalvato().contratti[0]).toMatchObject({ cosa: 'Ascensore', importo: null });
        expect(contenutoSalvato().debiti_certi).toEqual([]);
        expect(contenutoSalvato().da_decidere).toEqual([]);
        expect(contenutoSalvato().dichiarazione.liti.stato).toBe(null);
    });

    test('la dichiarazione e i dipendenti copiati: da confermare; «Confermo» o una scelta toccata li fanno stampare', async () => {
        const c = vuoto();
        (c.dichiarazione as any) = { liti: { stato: 'nessuna', testo: '', da_confermare: true }, impugnazioni: { stato: 'nessuna', testo: '', da_confermare: true },
            sinistri: { stato: null, testo: '', da_confermare: false } };
        (c as any).dipendenti = { stato: 'nessuno', testo: '', da_confermare: true };
        monta(proprieta(nota(c)));
        expect(w!.findAll('[data-test="da-confermare"]')).toHaveLength(2);
        expect(w!.find('[data-test="dipendenti-da-confermare"]').text()).toContain('non si stampa finché non la confermi');
        await w!.find('[data-domanda="liti"] [data-test="conferma-dichiarazione"]').trigger('click');
        await w!.find('[data-domanda="impugnazioni"] input[value="ci_sono"]').setValue(true);
        await w!.find('[data-test="conferma-dipendenti"]').trigger('click');
        expect(w!.findAll('[data-test="da-confermare"]')).toHaveLength(0);
        await w!.find('[data-test="salva-nota"]').trigger('click');
        expect(contenutoSalvato().dichiarazione.liti).toMatchObject({ stato: 'nessuna', da_confermare: false });
        expect(contenutoSalvato().dichiarazione.impugnazioni).toMatchObject({ stato: 'ci_sono', da_confermare: false });
        expect(contenutoSalvato().dipendenti).toMatchObject({ stato: 'nessuno', da_confermare: false });
    });

    test('il testo dell\'amministratore è testo: un tag scritto in una frase riscritta si vede com\'è', () => {
        const c = vuoto();
        (c.fatti as any).G1 = { stato: 'tenuto', riscritto: '<b>x</b> e <i>y</i>', proposto: G1, il: '2027-03-01' };
        monta(proprieta(nota(c)));
        const frase = w!.find('[data-fatto="G1"] [data-test="frase"]');
        expect(frase.text()).toBe('<b>x</b> e <i>y</i>');
        expect(frase.find('b').exists()).toBe(false);
    });

    test('le cose da completare: il titolo dice che non fermano niente; con la nota intatta una riga sola, che dice dove sono «Tieni tutti» e «Genera la nota», senza pulsanti doppi', async () => {
        const intatta = 'Nessuna parte ha ancora un fatto tenuto o un testo: in cima alla scheda «Nota sintetica», «Tieni tutti i fatti proposti» o «Genera la nota».';
        monta(proprieta(nota(vuoto(), { mancanze: [intatta, 'La dichiarazione sulle liti, le impugnazioni e i sinistri non è fatta: la trovi nella parte «Le questioni pendenti» della nota.'],
            intatta: true, frase_intatta: intatta })));
        const riquadro = w!.find('[data-test="mancanze"]');
        expect(riquadro.text()).toContain('Da completare in questa nota: puoi fermarla e approvarla lo stesso');
        expect(riquadro.text()).not.toContain('Che cosa manca');
        expect(riquadro.find('[data-test="intatta"]').text()).toContain(intatta);
        // I pulsanti sono in cima alla scheda: il riquadro non li ripete.
        expect(riquadro.findAll('button')).toHaveLength(0);
        expect(w!.findAll('[data-test="tieni-tutti"]')).toHaveLength(1);
        expect(w!.findAll('[data-test="genera"]')).toHaveLength(1);
    });

    test('«Stampa la nota» e «Salva la nota» una volta sola, in fondo, della misura dei pulsanti delle altre schede; la testata senza pulsanti suoi', () => {
        const testata = { template: '<header data-test="testata"><slot name="actions" /></header>' };
        const slot = { template: '<div><slot /></div>' };
        w = mount(Pagina, { props: proprieta() as any, global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, PageHeaderGuide: testata } } });
        expect(w.find('[data-test="testata"]').text()).toBe('');
        expect(w.find('[data-test="pulsanti-in-cima"]').exists()).toBe(false);
        expect(w.findAll('button').filter((b) => ['Stampa la nota', 'Salva la nota'].includes(b.text()))).toHaveLength(2);
        const pulsanti = w.findAll('[data-test="pulsanti-in-fondo"] button');
        expect(pulsanti.map((b) => b.text())).toEqual(['Stampa la nota', 'Salva la nota']);
        pulsanti.forEach((b) => expect(b.classes()).toEqual(expect.arrayContaining(['h-8', 'px-3'])));
    });

    test('«Genera la nota» ha un suggerimento: che cosa fa da sola, e che la nota a modo proprio si fa nelle parti sotto; con modifiche da salvare, aspetta il salvataggio', async () => {
        const slot = { template: '<div><slot /><slot name="actions" /></div>' };
        const suggerimento = { template: '<div data-tooltip><slot /></div>' };
        w = mount(Pagina, { props: proprieta() as any, global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, PageHeaderGuide: slot,
            TooltipProvider: slot, Tooltip: slot, TooltipTrigger: slot, TooltipContent: suggerimento } } });
        const testo = () => w!.find('[data-tooltip]').text().replace(/\s+/g, ' ');
        expect(testo()).toContain('Genera la nota in automatico');
        expect(testo()).toContain('Per farla a modo tuo, usa le parti qui sotto');
        expect(testo()).not.toContain('Salva prima la nota');
        await w!.find('[data-fatto="G1"] [data-test="tieni"]').trigger('click');
        expect(w!.find('[data-test="genera"]').attributes('disabled')).toBeDefined();
        expect(testo()).toContain('Salva prima la nota: «Genera la nota» parte da quella salvata.');
    });

    test('«Genera la nota»: la finestra chiede la nota del registro, manda, dice che cosa manca e ricarica la scheda; il registro si vede', async () => {
        const registro = [{ tipo: 'genera', quando: '01/03/2027 10:00', utente: 'Anna Rossi', nota: 'La bozza per il consiglio', dati: { mancanze: ['La dichiarazione non è fatta.'], tenuti: 5 } }];
        monta(proprieta(nota(vuoto(), { registro })));
        expect(w!.find('[data-test="registro"]').text()).toContain('01/03/2027 10:00 · Anna Rossi · «Genera la nota» · «La bozza per il consiglio»');
        expect(w!.find('[data-test="registro"]').text()).toContain('Mancava: La dichiarazione non è fatta.');
        await w!.find('[data-test="genera"]').trigger('click');
        expect(w!.find('[data-test="finestra-genera"]').text()).toContain('restano da confermare');
        await w!.find('[data-test="genera-nota"]').setValue('Per il consiglio di marzo');
        axiosPost.mockResolvedValue({ data: { mancanze: ['La dichiarazione copiata da un\'altra nota è da confermare.'], tenuti: 12, copiato_da: 'la bozza della nota di «Esercizio 2025»' } });
        await w!.find('[data-test="genera-conferma"]').trigger('click');
        await flushPromises();
        expect(axiosPost).toHaveBeenCalledWith('/gestionale.esercizi.rendiconto.nota.genera', { nota: 'Per il consiglio di marzo' });
        expect(reload).toHaveBeenCalled();
        expect(w!.find('[data-test="genera-fatto"]').text()).toBe('Fatto: 12 fatti tenuti; i testi e gli elenchi che mancavano copiati da qui: la bozza della nota di «Esercizio 2025».');
        expect(w!.findAll('[data-test="genera-mancanza"]').map((m) => m.text())).toEqual(['La dichiarazione copiata da un\'altra nota è da confermare.']);
        // Con modifiche non salvate «Genera la nota» non si usa.
        await w!.find('[data-fatto="G1"] [data-test="tieni"]').trigger('click');
        expect(w!.find('[data-test="genera"]').attributes('disabled')).toBeDefined();
    });
    test('i testi dello studio di un campo si rinominano e si eliminano dalla nota, da chi li può salvare', async () => {
        monta();
        const altre = () => w!.find('[data-parte="altre"] [data-testo="altre"]');
        await altre().find('[data-test="gestisci-testi"]').trigger('click');
        const riga = altre().find('[data-testo-studio="1"]');
        await riga.find('input').setValue('Documenti dallo studio');
        axiosPut.mockResolvedValue({ data: { testi: [{ id: 1, tipo: 'altre', nome: 'Documenti dallo studio', testo: 'I documenti si vedono in studio.', per_tutti: true }] } });
        await riga.find('[data-test="rinomina-testo"]').trigger('click');
        await flushPromises();
        expect(axiosPut).toHaveBeenCalledWith('/gestionale.nota-sintetica.testi.update', { nome: 'Documenti dallo studio' });
        expect(await vociDelMenu(altre().find('[data-test="usa-testo"]'))).toEqual(['Documenti dallo studio']);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        axiosDelete.mockResolvedValue({ data: { testi: [] } });
        await altre().find('[data-testo-studio="1"] [data-test="elimina-testo"]').trigger('click');
        await flushPromises();
        expect(axiosDelete).toHaveBeenCalledWith('/gestionale.nota-sintetica.testi.destroy');
        expect(altre().find('[data-test="usa-testo"]').exists()).toBe(false);
        // Senza il permesso, niente.
        w!.unmount();
        monta(proprieta(nota(), { puo_salvare_testi: false }));
        expect(w!.find('[data-test="gestisci-testi"]').exists()).toBe(false);
    });

    test('la differenza con la banca porta alle possibili cause nei controlli; il registro dice le cause ignorate e riaperte, con la frase e la nota', () => {
        const c = { ...vuoto(), banca: { 4: { data: '2026-12-31', saldo: 465000, spiegazione: '' } } };
        const registro = [
            { tipo: 'pista_riaperta', quando: '03/03/2027 09:00', utente: 'Anna', nota: '', dati: { frase: 'Può dipendere da un movimento in transito.' } },
            { tipo: 'pista_ignorata', quando: '02/03/2027 10:00', utente: 'Anna', nota: 'Assegno non ancora incassato', dati: { frase: 'Può dipendere da un movimento in transito.' } },
        ];
        monta(proprieta(nota(c, { registro, banche: [{ cassa_id: 4, cassa: 'Banca principale', data: '2026-12-31', estratto: 465000, contabilita: 459000, differenza: 6000,
            esito: 'segnalazione', spiegazione: '' }] })));
        expect(w!.find('[data-test="cause-banca"]').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto/controlli#controllo-BC');
        expect(w!.find('[data-test="contabilita-fondi"]').exists()).toBe(false);
        const righe = w!.findAll('[data-test="riga-registro"]').map((r) => r.text().replace(/\s+/g, ' '));
        expect(righe[1]).toContain('02/03/2027 10:00 · Anna · Causa della differenza con la banca ignorata · «Assegno non ancora incassato»');
        expect(righe[1]).toContain('Può dipendere da un movimento in transito.');
        expect(righe[1]).not.toContain('Mancava');
        expect(righe[0]).toContain('Causa della differenza con la banca riaperta');
    });

    test('dal controllo della banca («Scrivi il saldo della banca», `?banca=4`) la pagina si apre sul campo del saldo di quel conto', async () => {
        window.history.replaceState(null, '', '/gestionale/3/esercizi/9/rendiconto/nota?banca=4');
        try {
            const banche = [{ cassa_id: 2, cassa: 'Banca prima', data: '2026-12-31', estratto: null, contabilita: 100, differenza: null, esito: 'non_calcolato', spiegazione: '' },
                { cassa_id: 4, cassa: 'Banca principale', data: '2026-12-31', estratto: null, contabilita: 465000, differenza: null, esito: 'non_calcolato', spiegazione: '' }];
            const slot = { template: '<div><slot /><slot name="actions" /></div>' };
            w = mount(Pagina, { props: proprieta(nota(vuoto(), { banche })) as any, attachTo: document.body,
                global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, PageHeaderGuide: slot } } });
            await nextTick();
            await nextTick();
            const campo = w.find('[data-banca="4"] [data-test="saldo-banca"]');
            const input = (campo.element.tagName === 'INPUT' ? campo.element : campo.element.querySelector('input')) as HTMLInputElement;
            expect(document.activeElement).toBe(input);
        } finally {
            window.history.replaceState(null, '', '/');
        }
    });

    test('con i fondi, la contabilità del conto dice le sue due parti', () => {
        monta(proprieta(nota({ ...vuoto(), banca: {} }, { banche: [{ cassa_id: 4, cassa: 'Banca principale', data: '2026-12-31', estratto: null, contabilita: 465000, sul_conto: 415000,
            nei_fondi: 50000, differenza: null, esito: 'non_calcolato', spiegazione: '' }] })));
        expect(w!.find('[data-test="contabilita-fondi"]').text().replace(/\s+/g, ' ')).toContain('(€ 4.150,00 sul conto e € 500,00 nei fondi, che stanno sullo stesso conto corrente)');
    });
});

describe('la guida della scheda', () => {
    test('il pulsante «Guida» dell\'intestazione, uno solo, apre la guida della scheda «Nota sintetica»', async () => {
        w?.unmount();
        const slot = { template: '<div><slot /></div>' };
        w = mount(Pagina, {
            props: proprieta() as any,
            global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot,
                PageHeaderGuide: { props: { hasTextGuide: Boolean, textGuideTitle: String }, emits: ['open-text-guide'],
                template: '<div><button v-if="hasTextGuide" type="button" data-testid="apri-guida" @click="$emit(\'open-text-guide\')">{{ textGuideTitle }}</button><slot name="actions" /></div>' },
                RendicontoNotaGuide: { props: ['open'], template: '<div v-if="open" data-testid="guida-aperta" />' } } },
        });
        expect(w.find('[data-testid="guida-aperta"]').exists()).toBe(false);
        expect(w.findAll('button').filter((b) => b.text().trim() === 'Guida')).toHaveLength(1);
        const pulsante = w.get('[data-testid="apri-guida"]');
        expect(pulsante.text().trim()).toBe('Guida');
        await pulsante.trigger('click');
        expect(w.find('[data-testid="guida-aperta"]').exists()).toBe(true);
    });
});
