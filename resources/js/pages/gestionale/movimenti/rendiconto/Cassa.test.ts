// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La scheda «Cassa» del rendiconto: le entrate e le uscite per cassa come le dà il server, il conto con dentro il fondo e le righe «di cui», la cassa che
 * apre il registro filtrato su quella cassa (con il ritorno), il fondo che apre il suo mastrino, il gruppo «Come si divide il conto» sotto il conto (i fondi
 * con il loro tipo in un badge, la parte libera con gli spostamenti verso i fondi, la riga «si può usare»), il «Totale delle casse», la frase sotto la
 * tabella, il raccordo.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generateRoute: (nome: string) => nome,
        generatePath: (p: string, parametri: Record<string, unknown> = {}) => `/${Object.entries(parametri).reduce((s, [k, v]) => s.replace(`:${k}`, String(v)), p)}`,
    }),
}));

const Pagina = (await import('./Cassa.vue')).default;

const cassa = {
    data: '2026-12-31', stato_esercizio: 'chiuso',
    riepilogo: {
        dal: '2026-01-01', al: '2026-12-31',
        conti: [{ cassa_id: 4, conto_contabile_id: 20, cassa: 'Banca principale', tipo: 'banca', iniziale: 500000, entrate: 394000, uscite: 372100, finale: 521900, negativa: false, minimo: null, minimo_il: null,
            senza_banca: false, interni: 0, di_cui: [] }],
        fra_le_casse: 0,
        totale: { iniziale: 500000, entrate: 394000, uscite: 372100, finale: 521900 }, stornate: { coppie: 0, importo: 0 },
    },
    raccordo: { righe: [{ voce: 'Risultato di gestione dell\'esercizio', natura: 'quote registrate in questo esercizio − costi', effetto: -2100 }], somma: 21900, variazione_liquidita: 21900, scarto: 0, quadra: true },
    risultato_gestione: { risultato: -2100 },
};

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

describe('la scheda «Cassa»', () => {
    test('le casse con il riporto, la cassa che apre il registro su quella cassa, il raccordo; la scheda accesa', () => {
        const slot = { template: '<div><slot /></div>' };
        w = mount(Pagina, {
            props: { condominio: { id: 3, nome: 'Via Roma' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [], cassa } as any,
            global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, PageHeaderGuide: true } },
        });
        const riga = w.find('[data-cassa="4"]');
        expect(riga.findAll('td').map((td) => td.text().replace(/\s+/g, ' ').trim()).slice(1)).toEqual(['€ 5.000,00', '€ 3.940,00', '€ 3.721,00', '€ 5.219,00']);
        expect(riga.find('a').attributes('href')).toBe('/gestionale/3/esercizi/9/registro-contabilita?cassa=4&da=cassa');
        expect(w.find('[data-test="raccordo"]').text()).toContain('Variazione della liquidità nell\'esercizio');
        expect(w.find('[data-scheda="cassa"]').attributes('aria-current')).toBe('page');
    });
});

// Il dimostrativo: il conto corrente con il fondo dentro. Conto 0 + 3.487,03 − 525,00 = 2.962,03; il fondo 0 + 1.500,00 = 1.500,00; libero 0 + 3.487,03 − 2.025,00
// = 1.462,03; 1.500,00 + 1.462,03 = 2.962,03. Gli spostamenti interni 1.500,00; nessun trasferimento fra le casse; un incasso stornato di 292,80.
const conFondo = {
    ...cassa,
    riepilogo: {
        dal: '2026-01-01', al: '2026-12-31',
        conti: [{ cassa_id: 7, conto_contabile_id: 30, cassa: 'Conto corrente condominiale', tipo: 'banca', iniziale: 0, entrate: 348703, uscite: 52500, finale: 296203,
            negativa: false, minimo: null, minimo_il: null, senza_banca: false, interni: 150000, di_cui: [
                { cassa_id: 8, conto_contabile_id: 31, cassa: 'Fondo lavori straordinari', tipo: 'fondo', iniziale: 0, entrate: 150000, uscite: 0, finale: 150000 },
                { cassa_id: 7, conto_contabile_id: 30, cassa: null, tipo: 'libero', iniziale: 0, entrate: 348703, uscite: 202500, finale: 146203, messi_nei_fondi: 150000, tornati_dai_fondi: 0 },
            ] }],
        fra_le_casse: 0,
        totale: { iniziale: 0, entrate: 348703, uscite: 52500, finale: 296203 }, stornate: { coppie: 1, importo: 29280 },
    },
};

describe('il conto con il fondo dentro', () => {
    test('il conto chiude con il saldo della banca; sotto, nel gruppo, il fondo (messi nel fondo, usati) con il suo mastrino e la parte libera con quanto ha messo nei fondi', () => {
        const slot = { template: '<div><slot /></div>' };
        w = mount(Pagina, {
            props: { condominio: { id: 3, nome: 'Dimostrativo' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [], cassa: conFondo } as any,
            global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, PageHeaderGuide: true } },
        });
        const celle = (sel: string) => w!.find(sel).findAll('td').map((td) => td.text().replace(/\s+/g, ' ').trim());
        expect(celle('[data-cassa="7"]').slice(1)).toEqual(['€ 0,00', '€ 3.487,03', '€ 525,00', '€ 2.962,03']);
        expect(celle('[data-di-cui="8"]')).toEqual(['in «Fondo lavori straordinari»', '€ 0,00', '€ 1.500,00 messi nel fondo', '€ 0,00 usati', '€ 1.500,00']);
        expect(celle('[data-di-cui="libero"]')).toEqual(['libero', '€ 0,00', '€ 3.487,03', '€ 2.025,00 di cui € 1.500,00 messi nei fondi', '€ 1.462,03']);
        expect(w.find('[data-cassa="7"] a').attributes('href')).toBe('/gestionale/3/esercizi/9/registro-contabilita?cassa=7&da=cassa');
        expect(w.find('[data-di-cui="8"] a').attributes('href')).toBe('/gestionale/3/esercizi/9/conti/31/movimenti');
        expect(w.find('[data-di-cui="libero"] a').exists()).toBe(false);
        // La frase sotto la tabella è corta: la tabella dice da sé come si divide il conto e gli spostamenti verso i fondi.
        const frase = w.find('[data-test="frase-cassa"]').text().replace(/\s+/g, ' ');
        expect(frase).toContain('Un movimento stornato nell\'esercizio (€ 292,80) non conta');
        expect(frase).toContain('La disponibilità iniziale è il riporto dell\'esercizio prima');
        for (const tolta of ['Ogni riga quadra da sé', 'Un fondo è una parte dello stesso conto corrente', 'Gli spostamenti fra la parte libera e i fondi', 'trasferimenti fra le casse',
            'non è nel conto di una banca']) {
            expect(frase, tolta).not.toContain(tolta);
        }
    });

    test('un fondo che non sta nel conto di una banca ha la sua riga, apre il suo mastrino, e la frase lo dice', () => {
        const slot = { template: '<div><slot /></div>' };
        const fuori = { ...cassa, riepilogo: { ...cassa.riepilogo, conti: [...cassa.riepilogo.conti,
            { cassa_id: 9, conto_contabile_id: 40, cassa: 'Fondo X', tipo: 'fondo', iniziale: 30000, entrate: 0, uscite: 0, finale: 30000, negativa: false, minimo: null, minimo_il: null,
                senza_banca: true, interni: 0, di_cui: [] }], fra_le_casse: 12000 } };
        w = mount(Pagina, {
            props: { condominio: { id: 3, nome: 'Via Roma' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [], cassa: fuori } as any,
            global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, PageHeaderGuide: true } },
        });
        const riga = w.find('[data-cassa="9"]');
        expect(riga.text()).toContain('non è nel conto di una banca');
        expect(riga.find('a').attributes('href')).toBe('/gestionale/3/esercizi/9/conti/40/movimenti');
        const frase = w.find('[data-test="frase-cassa"]').text().replace(/\s+/g, ' ');
        expect(frase).toContain('Un fondo con la sua riga non è nel conto di una banca');
        // Il passaggio da una cassa all'altra lo dice il totale, sotto il suo nome.
        expect(w.get('[data-test="totale-delle-casse"]').text().replace(/\s+/g, ' ')).toContain('la somma delle righe in grassetto, meno € 120,00 passati da una cassa all\'altra');
    });
});

describe('la parte libera sotto zero', () => {
    test('il conto con il fondo sopra zero e la parte libera sotto zero: la riga «di cui libero» lo dice, il conto no, e la frase spiega che cosa vuol dire', () => {
        const slot = { template: '<div><slot /></div>' };
        const libero = { ...conFondo, riepilogo: { ...conFondo.riepilogo, conti: [{ ...conFondo.riepilogo.conti[0], negativa: false, minimo: 50000, minimo_il: '2026-06-01',
            libero_negativo: true, libero_minimo: -30000, libero_minimo_il: '2026-06-01' }] } };
        w = mount(Pagina, {
            props: { condominio: { id: 3, nome: 'Dimostrativo' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [], cassa: libero } as any,
            global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, PageHeaderGuide: true } },
        });
        expect(w.find('[data-cassa="7"]').text()).not.toContain('sotto zero');
        expect(w.get('[data-di-cui="libero"] [data-test="libero-sotto-zero"]').text().replace(/\s+/g, ' ')).toBe('la parte libera sotto zero: € -300,00 il 01/06/2026');
        expect(w.get('[data-test="frase-cassa"]').text()).toContain('La parte libera di un conto sotto zero, con il conto e i suoi fondi sopra zero, vuol dire che un pagamento ha usato il denaro di un fondo');
    });

    test('il conto sotto zero, con il fondo dentro: la riga del conto dice il minimo della banca vera', () => {
        const slot = { template: '<div><slot /></div>' };
        const sotto = { ...conFondo, riepilogo: { ...conFondo.riepilogo, conti: [{ ...conFondo.riepilogo.conti[0], negativa: true, minimo: -50000, minimo_il: '2026-06-01', libero_negativo: false }] } };
        w = mount(Pagina, {
            props: { condominio: { id: 3, nome: 'Dimostrativo' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [], cassa: sotto } as any,
            global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, PageHeaderGuide: true } },
        });
        expect(w.get('[data-cassa="7"]').text().replace(/\s+/g, ' ')).toContain('sotto zero: € -500,00 il 01/06/2026');
        expect(w.find('[data-test="libero-sotto-zero"]').exists()).toBe(false);
    });
});

// Il tipo dei fondi: un fondo per tono, come li dà il server (`TipoDelFondo`). Libero 7.650 + generico 100 + sbloccato 500 = 8.250 si può usare.
const fondo = (id: number, nome: string, finale: number, testo: string, tono: string, usabile: boolean) => ({ cassa_id: id, conto_contabile_id: 100 + id, cassa: nome,
    tipo: 'fondo', iniziale: 0, entrate: finale, uscite: 0, finale, tipo_fondo: { testo, tono }, usabile });
const conTipi = {
    ...cassa,
    riepilogo: {
        dal: '2026-01-01', al: '2026-12-31',
        conti: [{ cassa_id: 7, conto_contabile_id: 30, cassa: 'Conto corrente', tipo: 'banca', iniziale: 1000000, entrate: 0, uscite: 0, finale: 1000000,
            negativa: false, minimo: null, minimo_il: null, senza_banca: false, interni: 235000, si_puo_usare: 825000, di_cui: [
                fondo(11, 'Generico', 10000, 'generico, si può usare', 'libero', true),
                fondo(12, 'Lavori', 20000, 'vincolato ai lavori: rifacimento della facciata', 'vincolato', false),
                fondo(13, 'Morosi', 40000, 'morosità', 'vincolato', false),
                fondo(14, 'Sbloccato', 50000, 'vincolato, sbloccato dall\'assemblea: urgenza approvata il 10/03', 'sbloccato', true),
                fondo(15, 'TFR portiere', 30000, 'TFR', 'vincolato', false),
                fondo(16, 'Vecchio', 60000, 'tipo non indicato: conta come vincolato', 'non_indicato', false),
                { cassa_id: 7, conto_contabile_id: 30, cassa: null, tipo: 'libero', iniziale: 1000000, entrate: 0, uscite: 235000, finale: 765000 },
            ] }],
        fra_le_casse: 0,
        totale: { iniziale: 1000000, entrate: 0, uscite: 0, finale: 1000000 }, stornate: { coppie: 0, importo: 0 },
    },
};

describe('il tipo dei fondi e «Si può usare»', () => {
    const monta = (dati: unknown) => mount(Pagina, {
        props: { condominio: { id: 3, nome: 'Via Roma' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [], cassa: dati } as any,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true } },
    });

    test('sotto il nome di ogni fondo un badge con il suo tipo: angoli rounded-md, mai una pillola, con i colori e il lucchetto del tono', () => {
        w = monta(conTipi);
        const badge = (id: number) => w!.get(`[data-di-cui="${id}"] [data-test="tipo-fondo"]`);
        expect([11, 12, 13, 14, 15, 16].map((id) => [badge(id).text().trim(), badge(id).attributes('data-tono')])).toEqual([
            ['generico, si può usare', 'libero'],
            ['vincolato ai lavori: rifacimento della facciata', 'vincolato'],
            ['morosità', 'vincolato'],
            ['vincolato, sbloccato dall\'assemblea: urgenza approvata il 10/03', 'sbloccato'],
            ['TFR', 'vincolato'],
            ['tipo non indicato: conta come vincolato', 'non_indicato'],
        ]);
        for (const id of [11, 12, 13, 14, 15, 16]) {
            const classi = badge(id).classes();
            expect(classi, String(id)).toContain('rounded-md');
            expect(classi, String(id)).not.toContain('rounded-full');
            // Con il tema scuro, e il testo lungo che va a capo dentro il badge.
            expect(classi.some((k) => k.startsWith('dark:bg-')), String(id)).toBe(true);
            expect(classi, String(id)).toContain('whitespace-normal');
        }
        const colore = (id: number) => badge(id).classes().find((k) => /^bg-[a-z]+-50$/.test(k));
        expect([11, 12, 14, 16].map(colore)).toEqual(['bg-emerald-50', 'bg-amber-50', 'bg-sky-50', 'bg-slate-50']);
        // Il lucchetto aperto per ciò che si può usare (il generico, lo sbloccato), chiuso per il resto.
        const lucchetto = (id: number) => badge(id).find('svg').classes().find((k) => k.startsWith('lucide-lock'));
        expect([11, 12, 14, 15, 16].map(lucchetto)).toEqual(['lucide-lock-open-icon', 'lucide-lock-icon', 'lucide-lock-open-icon', 'lucide-lock-icon', 'lucide-lock-icon']);
        // Al telefono il badge va a capo sotto il nome; da sm in su gli sta accanto.
        const contenitore = badge(12).element.parentElement!;
        expect(contenitore.className).toContain('flex-col');
        expect(contenitore.className).toContain('sm:flex-row');
        expect(w.find('[data-di-cui="libero"] [data-test="tipo-fondo"]').exists()).toBe(false);
    });

    test('sotto «libero» la riga «si può usare»: la frase piccola, la cifra solo nella disponibilità finale, il fondo del gruppo; la frase sotto la tabella la spiega', () => {
        w = monta(conTipi);
        const righe = w.findAll('[data-test="per-cassa"] tbody tr');
        const ultima = righe.findIndex((r) => r.attributes('data-di-cui') === 'libero');
        const riga = righe[ultima + 1];
        expect(riga.attributes('data-test')).toBe('si-puo-usare');
        expect(riga.findAll('td').map((td) => td.text().replace(/\s+/g, ' ').trim())).toEqual(['si può usare la parte libera e i fondi generici o sbloccati', '', '', '', '€ 8.250,00']);
        expect(riga.attributes('data-gruppo')).toBe('7');
        expect(riga.classes().some((k) => k.startsWith('border-t-2') || k === 'border-2')).toBe(false);
        expect(w.get('[data-test="frase-cassa"]').text().replace(/\s+/g, ' ')).toContain('La riga «si può usare» è la parte libera più i fondi generici e quelli sbloccati dall\'assemblea: i fondi vincolati ai lavori, il TFR, la morosità e i fondi senza tipo restano fuori.');
    });

    test('un conto senza fondi non ha la riga «Si può usare», e la frase non ne parla', () => {
        w = monta(cassa);
        expect(w.find('[data-test="si-puo-usare"]').exists()).toBe(false);
        expect(w.get('[data-test="frase-cassa"]').text().toLowerCase()).not.toContain('si può usare');
        expect(w.find('[data-test="come-si-divide"]').exists()).toBe(false);
    });

    test('un fondo che non sta nel conto di una banca ha il badge sotto il nome, nella sua riga', () => {
        const fuori = { ...cassa, riepilogo: { ...cassa.riepilogo, conti: [...cassa.riepilogo.conti, { ...fondo(9, 'Fondo X', 30000, 'TFR', 'vincolato', false),
            negativa: false, minimo: null, minimo_il: null, senza_banca: true, interni: 0, di_cui: [], si_puo_usare: null }] } };
        w = monta(fuori);
        const b = w.get('[data-cassa="9"] [data-test="tipo-fondo"]');
        expect(b.text().trim()).toBe('TFR');
        expect(b.classes()).toContain('rounded-md');
        expect(w.find('[data-test="si-puo-usare"]').exists()).toBe(false);
    });
});

// Il condominio dimostrativo di prova con due fondi: il conto € 2.962,03; «Fondo di riserva», generico, € 300,00; «Fondo lavori straordinari», vincolato,
// € 1.500,00; libero 0 + 3.487,03 − 2.325,00 = € 1.162,03, con € 1.800,00 messi nei fondi; si può usare 1.162,03 + 300,00 = € 1.462,03.
const dimostrativo = {
    ...cassa,
    riepilogo: {
        dal: '2026-01-01', al: '2026-12-31',
        conti: [{ cassa_id: 7, conto_contabile_id: 30, cassa: 'Conto corrente condominiale', tipo: 'banca', iniziale: 0, entrate: 348703, uscite: 52500, finale: 296203,
            negativa: false, minimo: null, minimo_il: null, senza_banca: false, interni: 180000, si_puo_usare: 146203, di_cui: [
                fondo(41, 'Fondo di riserva', 30000, 'generico, si può usare', 'libero', true),
                fondo(42, 'Fondo lavori straordinari', 150000, 'vincolato ai lavori: rifacimento del lastrico solare', 'vincolato', false),
                { cassa_id: 7, conto_contabile_id: 30, cassa: null, tipo: 'libero', iniziale: 0, entrate: 348703, uscite: 232500, finale: 116203, messi_nei_fondi: 180000, tornati_dai_fondi: 0 },
            ] }],
        fra_le_casse: 0,
        totale: { iniziale: 0, entrate: 348703, uscite: 52500, finale: 296203 }, stornate: { coppie: 1, importo: 29280 },
    },
};

describe('il gruppo «Come si divide il conto» e il «Totale delle casse»', () => {
    const monta = (dati: unknown) => mount(Pagina, {
        props: { condominio: { id: 3, nome: 'Dimostrativo' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [], cassa: dati } as any,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true } },
    });
    const testo = (el: { text: () => string }) => el.text().replace(/\s+/g, ' ').trim();

    test('sotto il conto la riga di testa del gruppo, poi i fondi, la parte libera e «si può usare»: grigio chiaro, un filetto a sinistra, il testo più piccolo', () => {
        w = monta(dimostrativo);
        const righe = w.findAll('[data-test="per-cassa"] tbody tr');
        expect(righe.map((r) => r.attributes('data-test') ?? r.attributes('data-di-cui') ?? (r.attributes('data-cassa') ? 'cassa' : '?')))
            .toEqual(['cassa', 'come-si-divide', '41', '42', 'libero', 'si-puo-usare']);
        const testa = w.get('[data-test="come-si-divide"]');
        expect(testo(testa)).toBe('Come si divide il conto · le righe qui sotto sono già nella riga del conto, non si sommano al totale');
        expect(testa.findAll('td')).toHaveLength(1);
        expect(testa.get('td').attributes('colspan')).toBe('5');
        for (const r of righe.slice(1)) {
            expect(r.attributes('data-gruppo'), r.html().slice(0, 60)).toBe('7');
            expect(r.classes(), 'il fondo grigio').toEqual(expect.arrayContaining(['bg-gray-50/50', 'dark:bg-slate-800/30']));
            expect(r.classes(), 'il testo più piccolo').toContain('text-[12px]');
            const prima = r.get('td').classes();
            expect(prima, 'il filetto').toEqual(expect.arrayContaining(['border-l-2', 'border-l-slate-300', 'dark:border-l-slate-600']));
            expect(prima.some((k) => k.startsWith('rounded')), 'senza angoli arrotondati').toBe(false);
        }
        // La riga del conto resta in grassetto, senza il fondo del gruppo.
        expect(righe[0].get('td a').classes()).toContain('font-semibold');
        expect(righe[0].classes()).not.toContain('bg-gray-50/50');
    });

    test('nei fondi «messi nel fondo» e «usati»; sotto le uscite della parte libera «di cui € 1.800,00 messi nei fondi»; nessun «accantonati»', () => {
        w = monta(dimostrativo);
        const celle = (sel: string) => w!.get(sel).findAll('td').map(testo);
        expect(celle('[data-di-cui="41"]').slice(2, 4)).toEqual(['€ 300,00 messi nel fondo', '€ 0,00 usati']);
        expect(celle('[data-di-cui="42"]').slice(2, 4)).toEqual(['€ 1.500,00 messi nel fondo', '€ 0,00 usati']);
        expect(celle('[data-di-cui="libero"]')).toEqual(['libero', '€ 0,00', '€ 3.487,03', '€ 2.325,00 di cui € 1.800,00 messi nei fondi', '€ 1.162,03']);
        expect(celle('[data-test="si-puo-usare"]')[4]).toBe('€ 1.462,03');
        expect(w.get('[data-test="per-cassa"]').text()).not.toContain('accantonati');
    });

    test('un giroconto dal fondo alla banca: sotto le entrate della parte libera «di cui € N tornati dai fondi»; senza, niente', () => {
        const conRitorno = { ...dimostrativo, riepilogo: { ...dimostrativo.riepilogo, conti: [{ ...dimostrativo.riepilogo.conti[0], di_cui: [
            ...dimostrativo.riepilogo.conti[0].di_cui.slice(0, 2),
            { cassa_id: 7, conto_contabile_id: 30, cassa: null, tipo: 'libero', iniziale: 0, entrate: 368703, uscite: 232500, finale: 136203, messi_nei_fondi: 180000, tornati_dai_fondi: 20000 },
        ] }] } };
        w = monta(conRitorno);
        expect(testo(w.get('[data-di-cui="libero"]').findAll('td')[2])).toBe('€ 3.687,03 di cui € 200,00 tornati dai fondi');
        w.unmount();
        w = monta(dimostrativo);
        expect(w.get('[data-di-cui="libero"]').text()).not.toContain('tornati');
    });

    test('«Totale delle casse», con la riga che dice che cosa somma, e un filetto più marcato sopra', () => {
        w = monta(dimostrativo);
        const piede = w.get('[data-test="totale-delle-casse"]');
        expect(testo(piede.findAll('td')[0])).toBe('Totale delle casse il denaro entrato e uscito dal condominio: la somma delle righe in grassetto');
        expect(piede.findAll('td').slice(1).map(testo)).toEqual(['€ 0,00', '€ 3.487,03', '€ 525,00', '€ 2.962,03']);
        expect(piede.classes()).toEqual(expect.arrayContaining(['border-t-2', 'border-slate-500', 'dark:border-slate-400']));
        expect(w.get('[data-test="per-cassa"]').text()).not.toContain('Tutte le casse, flussi con l\'esterno');
    });

    test('con più conti ogni conto ha il suo gruppo, i contanti senza fondi no; il totale è la somma delle righe in grassetto, meno il passaggio fra le casse', () => {
        const conto = dimostrativo.riepilogo.conti[0];
        const piu = { ...dimostrativo, riepilogo: { ...dimostrativo.riepilogo, conti: [
            conto,
            { ...conto, cassa_id: 50, conto_contabile_id: 60, cassa: 'Banca seconda', iniziale: 200000, entrate: 100000, uscite: 0, finale: 300000, interni: 40000, si_puo_usare: 300000,
                di_cui: [fondo(51, 'Fondo della seconda', 40000, 'generico, si può usare', 'libero', true),
                    { cassa_id: 50, conto_contabile_id: 60, cassa: null, tipo: 'libero', iniziale: 200000, entrate: 100000, uscite: 40000, finale: 260000, messi_nei_fondi: 40000, tornati_dai_fondi: 0 }] },
            { cassa_id: 70, conto_contabile_id: 80, cassa: 'Cassa contanti', tipo: 'contanti', iniziale: 0, entrate: 0, uscite: 0, finale: 0, negativa: false, minimo: null, minimo_il: null,
                senza_banca: false, interni: 0, di_cui: [], si_puo_usare: null },
        ], fra_le_casse: 100000, totale: { iniziale: 200000, entrate: 348703, uscite: 52500 - 100000 + 100000, finale: 596203 } } };
        w = monta(piu);
        expect(w.findAll('[data-test="come-si-divide"]').map((r) => r.attributes('data-gruppo'))).toEqual(['7', '50']);
        expect(w.findAll('[data-gruppo="70"]')).toHaveLength(0);
        expect(testo(w.get('[data-test="totale-delle-casse"]').findAll('td')[0])).toBe('Totale delle casse il denaro entrato e uscito dal condominio: la somma delle righe in grassetto, '
            + 'meno € 1.000,00 passati da una cassa all\'altra');
    });

    test('al telefono la tabella non scorre di lato: le intestazioni nascoste, ogni cifra con il nome della sua colonna, le celle vuote nascoste', () => {
        w = monta(dimostrativo);
        const tabella = w.get('[data-test="per-cassa"] table');
        expect(tabella.classes().some((k) => k.startsWith('min-w-'))).toBe(false);
        expect(w.get('[data-test="per-cassa"]').classes()).not.toContain('overflow-x-auto');
        expect(w.get('[data-test="per-cassa"] thead').classes()).toContain('max-sm:hidden');
        const etichette = w.get('[data-cassa="7"]').findAll('td').slice(1).map((td) => td.attributes('data-label'));
        expect(etichette).toEqual(['Disponibilità iniziale', 'Entrate', 'Uscite', 'Disponibilità finale']);
        for (const td of w.get('[data-cassa="7"]').findAll('td').slice(1)) {
            expect(td.classes()).toEqual(expect.arrayContaining(['max-sm:flex', 'max-sm:before:content-[attr(data-label)]']));
        }
        const vuote = w.get('[data-test="si-puo-usare"]').findAll('td').slice(1, 4);
        expect(vuote.every((td) => td.classes().includes('max-sm:hidden'))).toBe(true);
    });
});

describe('la guida della scheda', () => {
    test('il pulsante «Guida» dell\'intestazione, uno solo, apre la guida della scheda «Cassa»', async () => {
        w?.unmount();
        const slot = { template: '<div><slot /></div>' };
        w = mount(Pagina, {
            props: { condominio: { id: 3, nome: 'Via Roma' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [], cassa } as any,
            global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot,
                PageHeaderGuide: { props: { hasTextGuide: Boolean, textGuideTitle: String }, emits: ['open-text-guide'],
                template: '<div><button v-if="hasTextGuide" type="button" data-testid="apri-guida" @click="$emit(\'open-text-guide\')">{{ textGuideTitle }}</button><slot name="actions" /></div>' },
                RendicontoCassaGuide: { props: ['open'], template: '<div v-if="open" data-testid="guida-aperta" />' } } },
        });
        expect(w.find('[data-testid="guida-aperta"]').exists()).toBe(false);
        expect(w.findAll('button').filter((b) => b.text().trim() === 'Guida')).toHaveLength(1);
        const pulsante = w.get('[data-testid="apri-guida"]');
        expect(pulsante.text().trim()).toBe('Guida');
        await pulsante.trigger('click');
        expect(w.find('[data-testid="guida-aperta"]').exists()).toBe(true);
    });
});

describe('i colori delle cifre del gruppo e delle casse', () => {
    const monta = (dati: unknown) => mount(Pagina, {
        props: { condominio: { id: 3, nome: 'Dimostrativo' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [], cassa: dati } as any,
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, MovimentiLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true } },
    });
    const colori = (classi: string[]) => classi.filter((k) => /^(dark:)?text-(emerald|rose|blue|slate)-\d+$/.test(k));

    test('la riga «si può usare» ha l\'etichetta e la cifra azzurre, anche al buio, sul fondo grigio del gruppo; la frase sotto resta grigia', () => {
        w = monta(conTipi);
        const riga = w.get('[data-test="si-puo-usare"]');
        const etichetta = riga.get('[data-test="etichetta-si-puo-usare"]');
        expect(etichetta.text()).toBe('si può usare');
        expect(colori(etichetta.classes())).toEqual(['text-blue-700', 'dark:text-blue-400']);
        const cifra = riga.findAll('td').at(-1)!;
        expect(colori(cifra.classes())).toEqual(['text-blue-700', 'dark:text-blue-400']);
        expect(riga.classes()).toEqual(expect.arrayContaining(['bg-gray-50/50', 'dark:bg-slate-800/30']));
        expect(riga.get('td span.block').classes()).toEqual(expect.arrayContaining(['text-slate-500', 'dark:text-slate-400']));
    });

    test('una cifra a zero nelle colonne Entrate e Uscite è nel grigio del testo secondario; il verde e il rosso restano per le cifre diverse da zero', () => {
        w = monta(conFondo);
        const cella = (sel: string, colonna: 'Entrate' | 'Uscite') => w!.get(`${sel} td[data-label="${colonna}"]`);
        // Il fondo: € 1.500,00 messi nel fondo (verde), € 0,00 usati (grigio).
        expect(colori(cella('[data-di-cui="8"]', 'Entrate').classes())).toEqual(['text-emerald-700', 'dark:text-emerald-400']);
        expect(colori(cella('[data-di-cui="8"]', 'Uscite').classes())).toEqual(['text-slate-500', 'dark:text-slate-400']);
        expect(cella('[data-di-cui="8"]', 'Uscite').text().replace(/\s+/g, ' ')).toContain('€ 0,00 usati');
        // La parte libera e il conto: entrate e uscite diverse da zero.
        expect(colori(cella('[data-di-cui="libero"]', 'Uscite').classes())).toEqual(['text-rose-700', 'dark:text-rose-400']);
        expect(colori(cella('[data-cassa="7"]', 'Entrate').classes())).toEqual(['text-emerald-700', 'dark:text-emerald-400']);
        w.unmount();
        // Una cassa senza movimenti e il totale a zero: tutto grigio.
        const ferma = { ...conFondo, riepilogo: { ...conFondo.riepilogo,
            conti: [{ ...conFondo.riepilogo.conti[0], entrate: 0, uscite: 0, di_cui: [] }], totale: { iniziale: 0, entrate: 0, uscite: 0, finale: 0 } } };
        w = monta(ferma);
        for (const sel of ['[data-cassa="7"]', '[data-test="totale-delle-casse"]']) {
            expect(colori(cella(sel, 'Entrate').classes()), sel).toEqual(['text-slate-500', 'dark:text-slate-400']);
            expect(colori(cella(sel, 'Uscite').classes()), sel).toEqual(['text-slate-500', 'dark:text-slate-400']);
        }
    });
});
