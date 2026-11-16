// @vitest-environment jsdom

/**
 * Il registro di contabilità filtrato per cassa: il ritorno alla scheda «Cassa» del rendiconto sotto la testata, solo quando si arriva da lì; la riga che
 * dice di quale cassa sono le card; la riga che spiega la differenza con la scheda, come la manda il server. La testata non ha un pulsante in più.
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

const Pagina = (await import('./List.vue')).default;

const riepilogo = {
    totale_entrate: 377983, totale_uscite: 81780, saldo_finale: 296203, saldo_alla_data: '2026-11-20',
    saldi_per_cassa: [{ cassa: 'Conto corrente condominiale', saldo: 296203, movimenti: 9, riporto: 0, entrate: 377983, uscite: 81780 }],
    riporto: { totale: 0, per_cassa: [], etichetta: 'Saldo di apertura' }, totali_per_tipo: [], saldi_fine_mese: [],
};
const casse = [{ id: 7, nome: 'Conto corrente condominiale', tipo: 'banca' }, { id: 8, nome: 'Cassa contanti', tipo: 'contanti' }];
const frase = 'Per tutto l\'esercizio, la scheda Cassa del rendiconto chiude «Conto corrente condominiale» allo stesso saldo, € 2.962,03.';

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

function monta(filters: Record<string, unknown>, confronto: unknown = null) {
    const slot = { template: '<div><slot /></div>' };
    w = mount(Pagina, {
        props: { condominio: { id: 86, nome: 'Dimostrativo' }, condomini: [], esercizio: { id: 100, nome: 'Esercizio 2026' }, esercizi: [],
            righe: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } }, riepilogo, filters, casse, confronto_scheda: confronto } as any,
        global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, DataTable: true, Alert: true, Dialog: slot, DialogContent: slot, DialogHeader: slot,
            DialogTitle: slot, DialogDescription: slot, DropdownMenu: slot, DropdownMenuTrigger: slot, DropdownMenuContent: slot, DropdownMenuItem: slot,
            PageHeaderGuide: { template: '<header data-test="testata"><slot name="actions" /></header>' } } },
    });
    return w;
}

describe('il registro filtrato per cassa', () => {
    test('arrivando dalla scheda Cassa: un ritorno solo, il pulsante della testata che riporta alla scheda; la cassa delle card; la riga del confronto con la scheda', () => {
        const p = monta({ cassa: 7, da: 'cassa' }, { cassa: 'Conto corrente condominiale', differenza: 0, frase });
        const ritorno = p.get('[data-test="testata"] [data-test="torna-al-rendiconto"]');
        expect(ritorno.text()).toBe('Torna alla scheda Cassa');
        expect(ritorno.attributes('href')).toBe('/gestionale/86/esercizi/100/rendiconto/cassa');
        // Un ritorno solo: niente secondo collegamento sotto la testata.
        expect(p.find('[data-test="torna-alla-scheda-cassa"]').exists()).toBe(false);
        expect(p.findAll('a').filter((a) => a.text().startsWith('Torna'))).toHaveLength(1);
        expect(p.get('[data-test="cassa-filtrata"]').text()).toBe('Solo «Conto corrente condominiale»: il riporto, le entrate, le uscite e il saldo sono di questa cassa.');
        expect(p.get('[data-test="confronto-scheda"]').text()).toContain(frase);
        // Con la sola cassa, entrate e uscite non sono «parziali».
        expect(p.text()).not.toContain('(parziale)');
    });

    test('il filtro della cassa senza arrivare dalla scheda: niente ritorno; senza filtro niente riga della cassa né confronto', () => {
        const p = monta({ cassa: 8 });
        expect(p.find('[data-test="torna-alla-scheda-cassa"]').exists()).toBe(false);
        expect(p.get('[data-test="torna-al-rendiconto"]').text()).toBe('Torna al rendiconto');
        expect(p.get('[data-test="cassa-filtrata"]').text()).toContain('«Cassa contanti»');
        w?.unmount();
        const q = monta({});
        expect(q.find('[data-test="cassa-filtrata"]').exists()).toBe(false);
        expect(q.find('[data-test="confronto-scheda"]').exists()).toBe(false);
        expect(q.find('[data-test="torna-alla-scheda-cassa"]').exists()).toBe(false);
    });

    test('con un periodo, entrate e uscite restano «parziali» anche per una cassa', () => {
        const p = monta({ cassa: 7, data_da: '2026-06-01' });
        expect(p.text()).toContain('Totale entrate (parziale)');
    });
});

describe('«Come è composta», il saldo della cassa filtrata', () => {
    // Il fondo aperto con un saldo suo, un conto in banca solo: i movimenti della banca € 5.219,00 più il saldo iniziale del fondo € 300,00 = € 5.519,00.
    const composto = { ...riepilogo, saldo_finale: 551900, saldo_alla_data: '2026-12-16',
        composizione: { movimenti: 521900, voce_movimenti: 'I movimenti di «Conto corrente condominiale» nel registro', saldo: 551900,
            parti: [{ voce: 'Il saldo iniziale di «Fondo riserva»', importo: 30000, quando: 'inizio' }] } };

    function montaComposto(r: unknown) {
        const slot = { template: '<div><slot /></div>' };
        w = mount(Pagina, {
            props: { condominio: { id: 86, nome: 'Dimostrativo' }, condomini: [], esercizio: { id: 100, nome: 'Esercizio 2026' }, esercizi: [],
                righe: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 20 } }, riepilogo: r, filters: { cassa: 7, da: 'cassa' }, casse, confronto_scheda: null } as any,
            global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, DataTable: true, Alert: true, Dialog: slot, DialogContent: slot, DialogHeader: slot,
                DialogTitle: slot, DialogDescription: slot, DropdownMenu: slot, DropdownMenuTrigger: slot, DropdownMenuContent: slot, DropdownMenuItem: slot,
                PageHeaderGuide: { template: '<header data-test="testata"><slot name="actions" /></header>' } } },
        });
        return w;
    }

    test('chiuso di serie; aperto dice le parti, ognuna con il suo nome e la sua cifra, che si sommano al saldo', async () => {
        const p = montaComposto(composto);
        const bottone = p.get('[data-test="come-e-composta"]');
        expect(bottone.text()).toBe('Come è composta');
        expect(bottone.attributes('aria-expanded')).toBe('false');
        expect(p.find('[data-test="composizione"]').exists()).toBe(false);
        // Accanto alla cifra, nella card del saldo, non nella testata.
        expect(p.get('[data-test="testata"]').find('[data-test="come-e-composta"]').exists()).toBe(false);
        await bottone.trigger('click');
        const pannello = p.get('[data-test="composizione"]');
        expect(pannello.find('h3').text()).toBe('Come è composto il saldo di «Conto corrente condominiale»');
        const riga = (sel: string) => p.findAll(sel).map((li) => li.findAll('span').map((x) => x.text().replace(/\s+/g, ' ').trim()));
        expect(riga('[data-test="parte-movimenti"]')).toEqual([['I movimenti di «Conto corrente condominiale» nel registro', '€ 5.219,00']]);
        expect(riga('[data-test="parte"]')).toEqual([['Il saldo iniziale di «Fondo riserva»', '€ 300,00']]);
        expect(riga('[data-test="parte-saldo"]')).toEqual([['Saldo di cassa al 16/12/2026', '€ 5.519,00']]);
        expect(pannello.text()).toContain('Le altre voci sono denaro dei fondi che non è passato da questa cassa');
        await bottone.trigger('click');
        expect(p.find('[data-test="composizione"]').exists()).toBe(false);
    });

    test('senza parti dei fondi il saldo viene tutto dai movimenti, e la riga lo dice; senza il filtro di una cassa il pulsante non c\'è', async () => {
        const p = montaComposto({ ...riepilogo, composizione: { movimenti: 296203, voce_movimenti: 'I movimenti di «Conto corrente condominiale» nel registro', saldo: 296203, parti: [] } });
        await p.get('[data-test="come-e-composta"]').trigger('click');
        expect(p.findAll('[data-test="parte"]')).toHaveLength(0);
        expect(p.get('[data-test="composizione"]').text()).toContain('Tutto il saldo viene dai movimenti di questa cassa');
        w?.unmount();
        const q = monta({});
        expect(q.find('[data-test="come-e-composta"]').exists()).toBe(false);
    });
});
