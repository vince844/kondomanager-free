// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La card «Saldo previsto» della dashboard del condominio, con Chart.js finto: il titolo e la descrizione, i tre scenari affiancati con la
 * riga di che cosa conta lo scenario, la scritta in testa «sotto zero dal …» con lo stesso giorno del punto del grafico, il grafico caricato a parte (import
 * dinamico) con una serie a gradini e senza legenda, la descrizione per chi non vede il grafico, la tabella dei giorni in una finestra, i colori letti dal tema
 * e il grafico ridisegnato quando il tema passa fra chiaro e scuro. Sotto il grafico il pulsante delle azioni consigliate: «N azioni
 * consigliate», nessun pulsante senza azioni, «Simula il saldo» con «Scelgo io»; apre il cassetto. I tre pulsanti degli scenari hanno il testo su una riga
 * sola e la larghezza presa dal testo, non tre colonne uguali: «Con le rate in scadenza» non va su due righe.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const disegni = vi.hoisted(() => ({ montati: 0, ultimi: [] as Array<{ data: any; options: any }> }));
vi.mock('vue-chartjs', () => ({
    Line: defineComponent({
        name: 'LineFinto',
        props: ['data', 'options'],
        setup(props) {
            disegni.montati++;
            // Le props stesse, non una copia: dopo un cambio si leggono i dati di adesso.
            disegni.ultimi.push(props as any);

            return () => h('canvas', { 'data-testid': 'canvas' });
        },
    }),
}));
vi.mock('chart.js', () => {
    const Chart = { register: vi.fn() };

    return { Chart, LineController: {}, LineElement: {}, PointElement: {}, LinearScale: {}, CategoryScale: {}, Filler: {}, Tooltip: {} };
});

import SaldoPrevistoCard from './SaldoPrevistoCard.vue';
import { datiDelleAzioniDellEsempio, recuperoDellEsempio, saldoDellEsempio, tesoreria } from './tesoreriaDiProva';

const saldoPrevisto = (tesoreria() as any).saldoPrevisto;
const Cassetto = { name: 'AzioniConsigliate', props: ['open', 'saldoPrevisto', 'recupero', 'suggerisce', 'condominioId', 'oggi', 'datiDelleAzioni'], template: '<div v-if="open" data-testid="cassetto-finto" />' };
const stubs = { Dialog: { props: ['open'], template: '<div v-if="open" data-testid="finestra"><slot /></div>' }, DialogContent: { template: '<div><slot /></div>' },
    DialogHeader: { template: '<div><slot /></div>' }, DialogTitle: { template: '<h2><slot /></h2>' }, DialogDescription: { template: '<p><slot /></p>' },
    DialogFooter: { template: '<div><slot /></div>' }, DialogClose: { template: '<div><slot /></div>' }, AzioniConsigliate: Cassetto };
const pulito = (t: string) => t.replace(/\s+/g, ' ').trim();
/** Il grafico si carica con un import dinamico: si aspetta che arrivi. */
async function caricato() {
    await vi.dynamicImportSettled();
    await flushPromises();
}
const monta = (props: Record<string, unknown> = {}) => mount(SaldoPrevistoCard, { props: { saldoPrevisto, ...props } as any, global: { stubs } });

afterEach(() => {
    document.documentElement.classList.remove('dark');
    disegni.montati = 0;
    disegni.ultimi = [];
});

describe('la card «Saldo previsto»', () => {
    test('il titolo, la descrizione, la scritta in testa e il grafico caricato a parte', async () => {
        const w = monta();
        expect(pulito(w.text())).toContain('Saldo previsto');
        expect(pulito(w.text())).toContain('Il saldo a fine giornata, giorno per giorno, nei prossimi 30 giorni.');
        expect(pulito(w.get('[data-testid="in-testa"]').text())).toBe('sotto zero dal 18/11');
        expect(w.get('[data-testid="in-testa"]').classes().join(' ')).toContain('text-red-600');
        await caricato();
        expect(w.find('[data-testid="canvas"]').exists()).toBe(true);
        const { data, options } = disegni.ultimi.at(-1)!;
        expect(data.datasets).toHaveLength(1);
        expect(data.datasets[0].stepped).toBe(true);
        expect(options.plugins.legend.display).toBe(false);
        expect(data.labels[3]).toBe('18/11');
        expect(data.datasets[0].pointRadius[3]).toBeGreaterThan(0);
    });

    test('la descrizione per chi non vede il grafico e la tabella dei giorni in una finestra', async () => {
        const w = monta();
        await flushPromises();
        const grafico = w.get('[data-testid="grafico-saldo"]');
        expect(grafico.attributes('role')).toBe('img');
        expect(grafico.attributes('aria-label')).toBe(
            'Saldo previsto giorno per giorno, senza le rate: oggi € 4.480,38, fra 30 giorni € 280,38; sotto zero dal 18/11/2026, al più basso −€ 519,62.');
        expect(w.find('[data-testid="finestra"]').exists()).toBe(false);
        await w.get('[data-testid="vedi-i-giorni"]').trigger('click');
        const righe = w.findAll('[data-testid="finestra"] tbody tr').map((r) => r.findAll('td').map((c) => pulito(c.text())));
        expect(righe[0]).toEqual(['15/11/2026', '€ 4.480,38', 'Oggi']);
        expect(righe[1]).toEqual(['18/11/2026', '−€ 519,62', 'Scade: Edil Rossi srl — n. 45/2026, € 5.000,00']);
        expect(righe[2]).toEqual(['27/11/2026', '€ 280,38', 'Già registrato: Incasso, € 800,00']);
        expect(righe.at(-1)).toEqual(['15/12/2026', '€ 280,38', 'Fra 30 giorni']);
        expect(w.findAll('[data-testid="finestra"] th').map((c) => c.text())).toEqual(['Giorno', 'Saldo', 'Che cosa']);
    });

    test('sempre sopra zero: la scritta «mai sotto zero», in grigio', () => {
        const sopra = { ...saldoPrevisto, giorni: saldoPrevisto.giorni.map((g: any) => ({ ...g, saldo: 1000 })), primoSottoZero: null, minimoCents: 0, finaleCents: 1000 };
        const w = monta({ saldoPrevisto: sopra });
        expect(pulito(w.get('[data-testid="in-testa"]').text())).toBe('mai sotto zero');
        expect(w.get('[data-testid="in-testa"]').classes().join(' ')).toContain('text-slate-500');
    });

    test('i colori vengono dal tema, e il grafico si ridisegna quando il tema passa a scuro', async () => {
        const w = monta();
        await caricato();
        const prima = disegni.montati;
        expect(prima).toBeGreaterThan(0);
        document.documentElement.classList.add('dark');
        await new Promise((r) => setTimeout(r, 0));
        await flushPromises();
        expect(disegni.montati).toBeGreaterThan(prima);
        w.unmount();
    });
});

describe('i tre scenari', () => {
    test('tre scelte affiancate, non un menu; all\'apertura «Senza le rate», con la riga di che cosa conta', () => {
        const w = monta();
        const scelte = w.findAll('[data-testid="scenario"]');
        expect(scelte.map((s) => pulito(s.text()))).toEqual(['Senza le rate', 'Con le rate in scadenza', 'Se pagano tutti']);
        expect(scelte.every((s) => s.element.tagName === 'BUTTON')).toBe(true);
        expect(scelte.map((s) => s.attributes('aria-pressed'))).toEqual(['true', 'false', 'false']);
        expect(w.get('[data-testid="scenari"]').attributes('role')).toBe('group');
        expect(w.find('select').exists()).toBe(false);
        expect(pulito(w.get('[data-testid="frase-scenario"]').text())).toBe('Solo le fatture e i movimenti già registrati.');
    });

    test('il testo di ogni pulsante su una riga sola, la larghezza presa dal testo e non tre colonne uguali, il testo a 12 pixel', () => {
        const w = monta();
        const gruppo = w.get('[data-testid="scenari"]');
        expect(gruppo.classes()).not.toContain('grid-cols-3');
        expect(gruppo.classes()).toEqual(expect.arrayContaining(['flex', 'flex-wrap', 'text-xs']));
        for (const s of w.findAll('[data-testid="scenario"]')) {
            expect(s.classes()).toEqual(expect.arrayContaining(['whitespace-nowrap', 'flex-auto']));
        }
        // Nel sorgente: niente colonne uguali e niente scritte sotto i 12 pixel nel gruppo degli scenari.
        const sorgente = readFileSync(resolve(__dirname, 'SaldoPrevistoCard.vue'), 'utf8');
        const blocco = sorgente.slice(sorgente.indexOf('data-testid="scenari"'), sorgente.indexOf('data-testid="frase-scenario"'));
        expect(blocco).not.toMatch(/grid-cols-3|text-\[(8|9|10|11)px\]|truncate/);
        expect(blocco).toContain('whitespace-nowrap');
    });

    test('scegliendo uno scenario cambiano la linea, la scritta in testa con il punto, la riga e l\'aria-label; lo scenario esce verso la pagina', async () => {
        const w = mount(SaldoPrevistoCard, { props: { saldoPrevisto: saldoDellEsempio() } as any, global: { stubs } });
        await caricato();
        expect(pulito(w.get('[data-testid="in-testa"]').text())).toBe('sotto zero dal 20/11');
        expect(disegni.ultimi.at(-1)!.data.datasets[0].data[15]).toBe(-2368.77);

        await w.findAll('[data-testid="scenario"]')[1].trigger('click');
        await caricato();
        expect(w.emitted('update:scenario')?.at(-1)).toEqual(['con_rate']);
        expect(w.findAll('[data-testid="scenario"]').map((s) => s.attributes('aria-pressed'))).toEqual(['false', 'true', 'false']);
        expect(pulito(w.get('[data-testid="frase-scenario"]').text())).toBe('In più le rate dei condòmini che scadono nei 30 giorni, ognuna al suo giorno; le rate già scadute no.');
        expect(pulito(w.get('[data-testid="in-testa"]').text())).toBe('sotto zero dal 20/11');
        expect(disegni.ultimi.at(-1)!.data.datasets[0].data[15]).toBe(31.23);
        expect(w.get('[data-testid="grafico-saldo"]').attributes('aria-label')).toContain('con le rate in scadenza');

        await w.findAll('[data-testid="scenario"]')[2].trigger('click');
        await caricato();
        expect(pulito(w.get('[data-testid="in-testa"]').text())).toBe('mai sotto zero');
        expect(w.get('[data-testid="in-testa"]').classes().join(' ')).toContain('text-slate-500');
        expect(disegni.ultimi.at(-1)!.data.datasets[0].pointRadius.every((r: number) => r === 0)).toBe(true);
        expect(w.get('[data-testid="grafico-saldo"]').attributes('aria-label')).toContain('se pagano tutti');
    });

    test('lo scenario arriva anche dalla pagina', () => {
        const w = monta({ saldoPrevisto: saldoDellEsempio(), scenario: 'tutti' });
        expect(w.findAll('[data-testid="scenario"]').map((s) => s.attributes('aria-pressed'))).toEqual(['false', 'false', 'true']);
    });
});

describe('il pulsante delle azioni consigliate', () => {
    test('«2 azioni consigliate» apre il cassetto, con i dati della tesoreria', async () => {
        const w = monta({ saldoPrevisto: saldoDellEsempio(), recupero: recuperoDellEsempio(), suggerisce: true, condominioId: 7, oggi: '2026-11-15' });
        const pulsante = w.get('[data-testid="apri-azioni"]');
        expect(pulito(pulsante.text())).toBe('2 azioni consigliate');
        expect(w.find('[data-testid="cassetto-finto"]').exists()).toBe(false);
        await pulsante.trigger('click');
        expect(w.find('[data-testid="cassetto-finto"]').exists()).toBe(true);
        const cassetto = w.getComponent(Cassetto);
        expect(cassetto.props('suggerisce')).toBe(true);
        expect(cassetto.props('condominioId')).toBe(7);
        expect(cassetto.props('recupero')).toEqual(recuperoDellEsempio());
    });

    test('senza azioni nessun pulsante; con «Scelgo io» il pulsante dice «Simula il saldo»', () => {
        const sopra = { ...saldoPrevisto, giorni: saldoPrevisto.giorni.map((g: any) => ({ ...g, saldo: 1000 })), primoSottoZero: null, minimoCents: 0, finaleCents: 1000 };
        expect(monta({ saldoPrevisto: sopra, recupero: recuperoDellEsempio(), suggerisce: true }).find('[data-testid="apri-azioni"]').exists()).toBe(false);
        const scelgo = monta({ saldoPrevisto: saldoDellEsempio(), recupero: recuperoDellEsempio(), suggerisce: false });
        expect(pulito(scelgo.get('[data-testid="apri-azioni"]').text())).toBe('Simula il saldo');
    });

    test('il pulsante conta tutte le azioni, anche le sei in più, e passa i loro dati al cassetto', async () => {
        const dati = datiDelleAzioniDellEsempio();
        const w = monta({ saldoPrevisto: { ...saldoDellEsempio(), rateScaduteCents: 0 }, recupero: recuperoDellEsempio(), suggerisce: true, condominioId: 7, datiDelleAzioni: dati });
        expect(pulito(w.get('[data-testid="apri-azioni"]').text())).toBe('8 azioni consigliate');
        await w.get('[data-testid="apri-azioni"]').trigger('click');
        expect(w.getComponent(Cassetto).props('datiDelleAzioni')).toEqual(dati);
        // Con il saldo dell'esempio, se pagano tutti resta sopra zero: niente piano straordinario, sette.
        expect(pulito(monta({ saldoPrevisto: saldoDellEsempio(), recupero: recuperoDellEsempio(), datiDelleAzioni: dati }).get('[data-testid="apri-azioni"]').text()))
            .toBe('7 azioni consigliate');
        // Un saldo che non va mai sotto zero, ma i bonifici da verificare: un'azione, e il pulsante c'è.
        const sopra = { ...saldoPrevisto, giorni: saldoPrevisto.giorni.map((g: any) => ({ ...g, saldo: 1000 })), primoSottoZero: null, minimoCents: 0, finaleCents: 1000 };
        const soloBonifici = datiDelleAzioniDellEsempio({ rateDaEmettere: [], ritenute: { scadenze: [] }, conti: [], fondi: [] });
        expect(pulito(monta({ saldoPrevisto: sopra, recupero: recuperoDellEsempio(), datiDelleAzioni: soloBonifici }).get('[data-testid="apri-azioni"]').text()))
            .toBe('1 azione consigliata');
        // Con «Scelgo io» nessuna azione: il pulsante dice «Simula il saldo».
        expect(pulito(monta({ saldoPrevisto: saldoDellEsempio(), recupero: recuperoDellEsempio(), suggerisce: false, datiDelleAzioni: dati }).get('[data-testid="apri-azioni"]').text()))
            .toBe('Simula il saldo');
    });

    test('senza i dati del recupero (una tesoreria di prima) il pulsante non c\'è', () => {
        expect(monta().find('[data-testid="apri-azioni"]').exists()).toBe(false);
    });
});
