// @vitest-environment jsdom

/**
 * La situazione patrimoniale alla data: le fatture aperte stanno nella pagina, per l'esercizio aperto e per quello finito, con la riga «fatture
 * aperte + differenza = debiti verso fornitori» e i nomi della differenza; sotto l'Erario le ritenute delle fatture non pagate; dentro i crediti le
 * spese anticipate per singole unità; un blocco per gestione con il totale del condominio. Ogni numero arriva dal server: la pagina non somma.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generateRoute: (nome: string) => nome,
        generatePath: (p: string, parametri: Record<string, unknown> = {}) => `/${Object.entries(parametri).reduce((s, [k, v]) => s.replace(`:${k}`, String(v)), p)}`,
    }),
}));

const Pagina = (await import('./Show.vue')).default;

const fattura = (extra: Record<string, unknown>) => ({ id: 1, fornitore: 'Pulizie Srl', numero: 'FT-1', data: '2026-04-10', scadenza: '2026-04-10', residuo: 50000, nota_credito: false,
    contestata: false, pregressa: false, esercizio_id: 9, gestione_id: 1, ...extra });

const pagina = (extra: Record<string, unknown> = {}) => ({
    data: '2026-12-31', dal: '2026-01-01', stato_esercizio: 'chiuso',
    situazione: { attivo: { gruppi: [], totale: 0 }, passivo: { gruppi: [], totale: 0 }, costi_voci: [], ricavi_voci: [], liquidita_non_contabilizzata: 0, costi: 0, ricavi: 0, risultato_motore: 0, sbilancio: 0, quadra: true },
    sintesi: { liquidita: 521900, in_fondi: 0, crediti_condomini: 163000, debiti_fornitori: 183000, debiti_erario: 4000, spese_anticipate: 122000 },
    risultato_gestione: { quote_emesse: 411000, costi: 413100, risultato: -2100, cumulato: -2100 },
    riepilogo: { dal: '2026-01-01', al: '2026-12-31', stato_esercizio: 'chiuso', righe: [], totale: { iniziale: 0, entrate: 0, uscite: 0, finale: 0 }, giroconti: { entrate: 0, uscite: 0 },
        stornate: { coppie: 0, importo: 0 }, piede_quadra: true, casse_reali_negative: [] },
    liquidita: { liquidita_totale: 521900, libera: 521900, in_fondi: 0, vincolata: 0, generica: 0, altra: 0, altra_voci: [], fondi: [], vincolo_registrato: 0, vincolo_su_fondi: 0,
        vincolo_non_accantonato: 0, scoperto_fondi: 0, avanzo_registrato: 0, gia_speso_acconto: 0, esito_r8: 'quadra' },
    raccordo: { righe: [], somma: 0, variazione_liquidita: 0, scarto: 0, quadra: true },
    debiti: {
        alla: '2026-12-31',
        fatture: [fattura({}), fattura({ id: 2, numero: 'FT-2', fornitore: 'Idraulica Bianchi', residuo: 118000, scadenza: '2027-01-20' }), fattura({ id: 3, numero: 'FT-3', residuo: 15000, contestata: true })],
        totale_residui: 183000, saldo_fornitori: 171000, saldo_erario: 4000, erario_su_fatture_non_pagate: 4000, scarto: -12000,
        scarto_voci: [{ voce: 'pagato in più a un fornitore', importo: -12000, fatture: ['FT-9'] }],
    },
    per_gestione: {
        righe: [
            { gestione: { id: 1, nome: 'Ordinaria 2026', tipo: 'ordinaria' }, costi: 412850, quote_emesse: 411000, crediti: 41000, debiti: 85000, cumulato_opera: null },
            { gestione: { id: 2, nome: 'Facciata', tipo: 'straordinaria' }, costi: 120000, quote_emesse: 0, crediti: 0, debiti: 375000, cumulato_opera: { dal: '2025-01-01', consuntivo: 395000 } },
        ],
        senza_voce: 250, senza_gestione: { quote_emesse: 0, crediti: 0, debiti: 0 }, differenza_debiti: 0,
        totale: { costi: 533100, quote_emesse: 411000, crediti: 41000, debiti: 460000 }, fatture_altra_gestione: ['FX'],
        nota_altra_gestione: 'Una fattura ha voci di un\'altra gestione (FX): il costo sta nel blocco della voce, il debito e il pagato nel blocco della gestione della fattura.',
        nota_liquidita: 'La liquidità e i fondi non si dividono per gestione: la liquidità è del condominio.',
    },
    controlli: [],
    ...extra,
});

let w: VueWrapper | null = null;
const monta = (extra: Record<string, unknown> = {}) => {
    w?.unmount();
    w = mount(Pagina, {
        props: { condominio: { id: 3, nome: 'Via Roma' }, condomini: [], esercizio: { id: 9 }, esercizi: [], pagina: pagina(extra) } as any,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, DropdownMenu: true } },
    });

    return w;
};
afterEach(() => { w?.unmount(); w = null; });
const testo = (s: string) => s.replace(/\s+/g, ' ');

describe('la situazione patrimoniale alla data', () => {
    test('le fatture aperte stanno nella pagina anche per un esercizio finito, con la data della situazione, la contestata detta, e senza «Paga»', () => {
        // Oggi è il 15/02/2027 a Roma: la FT-2 (scadenza 20/01/2027) oggi è scaduta, al 31/12/2026 no.
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2027-02-15T09:00:00Z'));
        const sezione = monta().find('[data-test="fatture-aperte"]');
        vi.useRealTimers();
        expect(testo(sezione.text())).toContain('Fatture aperte al 31/12/2026');
        const righe = sezione.findAll('[data-test="fattura-aperta"]').map((r) => testo(r.text()));
        expect(righe).toHaveLength(3);
        expect(righe[2]).toContain('contestata');
        // La scadenza si confronta con la data della situazione: la FT-2 scade dopo il 31/12, non è scaduta a quella data.
        expect(righe[0]).toContain('scaduta');
        expect(righe[1]).not.toContain('scaduta');
        expect(sezione.text()).not.toContain('Paga');
        expect(monta({ stato_esercizio: 'aperto' }).find('[data-test="fatture-aperte"]').text()).toContain('Paga');
    });

    test('la riga con i debiti dice i nomi della differenza; sotto l\'Erario le ritenute delle fatture non pagate; la frase sui debiti che non sono fatture', () => {
        const t = testo(monta().find('[data-test="fatture-aperte"]').text());
        expect(testo(monta().find('[data-test="riga-debiti"]').text())).toBe('Fatture aperte € 1.830,00 − pagato in più a un fornitore (FT-9) € 120,00 = debiti verso fornitori a giornale € 1.710,00.');
        expect(t).toContain('di cui € 40,00 di ritenute su fatture non ancora pagate: fino al pagamento sono un debito verso il fornitore');
        expect(t).toContain('Contiene i debiti registrati come fatture');
        expect(testo(monta().find('[data-test="erario-non-pagate"]').text())).toContain('di cui € 40,00');
        expect(monta({ debiti: { ...pagina().debiti, erario_su_fatture_non_pagate: 0 } }).find('[data-test="erario-non-pagate"]').exists()).toBe(false);
    });

    test('dentro i crediti, le spese anticipate per singole unità; la didascalia dice tutte e due le cose', () => {
        const t = testo(monta().text());
        expect(t).toContain('quote emesse e non incassate, e spese anticipate per singole unità');
        expect(testo(monta().find('[data-test="spese-anticipate"]').text())).toContain('€ 1.220,00');
        expect(monta({ sintesi: { ...pagina().sintesi, spese_anticipate: 0 } }).find('[data-test="spese-anticipate"]').exists()).toBe(false);
    });

    test('un blocco per gestione, il totale del condominio, il cumulato dell\'opera, la fattura con le voci di un\'altra gestione, la liquidità del condominio', () => {
        const g = monta().find('[data-test="per-gestione"]');
        expect(testo(g.find('[data-gestione="2"]').text())).toContain('cumulato dell\'opera dal 01/01/2025: € 3.950,00');
        expect(g.find('tfoot').findAll('td').map((td) => testo(td.text()))).toEqual(['Condominio', '€ 5.331,00', '€ 4.110,00', '€ 410,00', '€ 4.600,00']);
        expect(testo(g.text())).toContain('(FX)');
        expect(g.text()).toContain('la liquidità è del condominio');
    });

    test('la scheda «Situazione patrimoniale» del rendiconto: accesa nella riga delle schede; i controlli e la cassa stanno nelle loro schede', () => {
        const p = monta();
        expect(p.find('[data-scheda="situazione"]').attributes('aria-current')).toBe('page');
        expect(p.findAll('[data-scheda]')).toHaveLength(6);
        expect(p.findAll('[data-test="altre-schede"] a').map((a) => a.attributes('href'))).toEqual(['/gestionale/3/esercizi/9/rendiconto/controlli', '/gestionale/3/esercizi/9/rendiconto/cassa']);
        expect(p.text()).not.toContain('Controlli di quadratura');
    });

    test('la guida del risultato di gestione parla come la stampa: l\'avanzo o il disavanzo, e che cosa farne lo decide l\'assemblea', () => {
        w?.unmount();
        w = mount(Pagina, {
            props: { condominio: { id: 3, nome: 'Via Roma' }, condomini: [], esercizio: { id: 9 }, esercizi: [], pagina: pagina() } as any,
            global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, DropdownMenu: true,
                PageHeaderGuide: { props: ['guides'], template: '<div><p v-for="g in guides" :key="g.title" data-guida>{{ g.title }}: {{ g.description }}</p></div>' } } },
        });
        const guida = testo(w.findAll('[data-guida]').map((g) => g.text()).find((t) => t.startsWith('Il risultato di gestione')) ?? '');
        expect(guida).toContain('l\'avanzo o il disavanzo; che cosa farne lo decide l\'assemblea');
        expect(guida).not.toContain('conguaglio');
    });

    test('nessuna delle parole che il rendiconto non dice mai di sé', () => {
        const t = monta().text().toLowerCase();
        for (const parola of ['pronto', 'conforme', 'corretto', 'approvabile', 'in regola', 'tutto in ordine']) {
            expect(t).not.toContain(parola);
        }
    });
});
