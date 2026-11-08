// @vitest-environment jsdom

/**
 * La scheda «Controlli» del rendiconto: i controlli con i loro numeri, un conteggio dove il controllo conta; le registrazioni oltre il confine nei due
 * versi, con la tabella per tipo, l'elenco e «Apri nel Libro Giornale» sul giornale dell'esercizio in cui la registrazione sta; i filtri chiedono al
 * server; nessun pulsante «correggi».
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';

const get = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: (...a: unknown[]) => get(...a) },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generateRoute: (nome: string) => nome,
        generatePath: (p: string, parametri: Record<string, unknown> = {}) => `/${Object.entries(parametri).reduce((s, [k, v]) => s.replace(`:${k}`, String(v)), p)}`,
    }),
}));

const Pagina = (await import('./Controlli.vue')).default;

const controlli = [
    { id: 'R2', nome: 'Liquidità e riepilogo', titolo: 'Liquidità = finale', sinistra: 750000, destra: 750000, esito: 'quadra', nota: '€ 40,00 + € 500,00 di registrazioni oltre il confine: vedi il controllo.', rimedio: null, azione: null },
    { id: 'AC', nome: 'Registrazioni oltre il confine dell\'esercizio', titolo: 'Nei due versi', sinistra: 3, destra: 0, esito: 'non_quadra', nota: 'Una cambia la competenza.', rimedio: 'Le registrazioni del verso B…', azione: 'anno-a-cavallo' },
    { id: 'CR', nome: 'I costi tornano con il riepilogo', titolo: 'Costi = …', sinistra: 420600, destra: 413100, esito: 'non_quadra', nota: 'costi non classificati € 75,00', rimedio: 'Assegna la voce.', azione: 'riepilogo' },
    { id: 'GE', nome: 'Voci e gestione delle fatture', titolo: '…', sinistra: 1, destra: 0, esito: 'segnalazione', nota: 'FF-ALTRA', rimedio: 'Correggila.', azione: null },
];
const riga = (extra: Record<string, unknown>) => ({ scrittura_id: 1, verso: 'B', data: '2026-12-28', esercizio_id: 12, esercizio: 'Esercizio 2027', protocollo: 'INC-2027-00001', tipo: 'incasso_rata', categoria: 'incasso',
    etichetta: 'Incassi delle rate', causale: null, importo: 50000, rossa: false, senza_esercizio: false, nota: null, ...extra });
const anno = (extra: Record<string, unknown> = {}) => ({
    dal: '2026-01-01', al: '2026-12-31', numero: 3, rosse: 1, da_leggere: 2,
    versi: { A: { numero: 1, rosse: 0, da_leggere: 1 }, B: { numero: 2, rosse: 1, da_leggere: 1 } },
    effetto: { A: { quote: 0, costi: 0, pregresse: 0, frase: null }, B: { quote: 0, costi: 0, pregresse: 100000, frase: 'Il riepilogo di questo esercizio non contiene € 1.000,00 di costi.' } },
    per_tipo: [{ verso: 'A', tipo: 'f24', etichetta: 'Versamenti F24', numero: 1, importo: 4000, rosse: 0 }, { verso: 'B', tipo: 'fattura_pregressa', etichetta: 'Fatture registrate come pregresse', numero: 1, importo: 100000, rosse: 1 }],
    filtri: { verso: null, tipo: null, colore: null }, righe_filtrate: 3,
    righe: [riga({}), riga({ scrittura_id: 2, verso: 'A', data: '2027-01-16', esercizio_id: 9, esercizio: 'Esercizio 2026', protocollo: 'F24-2027-00001', categoria: 'f24', etichetta: 'Versamenti F24', importo: 4000 }),
        riga({ scrittura_id: 3, data: '2026-12-15', protocollo: null, categoria: 'fattura_pregressa', etichetta: 'Fatture registrate come pregresse', importo: 100000, rossa: true, nota: 'registrata come pregressa: nessun riepilogo la conta fra i costi' }),
        riga({ scrittura_id: 4, data: '2026-12-20', protocollo: 'FAT-2027-00002', categoria: 'fattura', etichetta: 'Fatture', importo: 20000, rossa: true, nota: 'costo del 2027 con la data del 2026' })],
    pagine: { current_page: 1, last_page: 1, per_page: 50, total: 3, from: 1, to: 3, nome: 'pagina_confine' },
    fuori_da_ogni_esercizio: { fuori: 0, nel_periodo: 0, prevale: false }, frase_norma: 'Finché non c\'è la chiusura guidata dell\'esercizio (in arrivo)…', rimedi: ['Le registrazioni del verso A…', 'Le registrazioni del verso B…'], ...extra,
});

let w: VueWrapper | null = null;
const monta = (a: Record<string, unknown> = {}, conGuide = false) => {
    w?.unmount();
    const slot = { template: '<div><slot /></div>' };
    const guide = { props: ['guides'], template: '<div><p v-for="g in guides" :key="g.title" data-guida>{{ g.title }}: {{ g.description }}</p></div>' };
    w = mount(Pagina, {
        props: { condominio: { id: 3, nome: 'Via Roma' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [], controlli, esito: { frase: '1 torna, 2 non tornano, 1 da leggere', riga_fissa: 'I controlli verificano…' },
            anno_a_cavallo: anno(a) } as any,
        global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, PageHeaderGuide: conGuide ? guide : true } },
    });

    return w;
};
const testo = (s: string) => s.replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
afterEach(() => { w?.unmount(); w = null; get.mockReset(); });

describe('la scheda «Controlli»', () => {
    test('i controlli: un\'uguaglianza con i suoi due numeri, un conteggio dove il controllo conta, il rimedio con il suo collegamento', () => {
        const p = monta();
        expect(testo(p.find('[data-controllo="R2"]').html())).toContain('€ 7.500,00 = € 7.500,00');
        expect(testo(p.find('[data-controllo="AC"]').html())).toContain('3 registrazioni oltre il confine');
        expect(testo(p.find('[data-controllo="GE"]').html())).toContain('1 fattura con voci di un\'altra gestione');
        expect(testo(p.find('[data-controllo="CR"]').html())).toContain('€ 4.206,00 ≠ € 4.131,00');
        expect(p.find('[data-controllo="CR"] a').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto/riepilogo-finanziario');
        expect(p.find('[data-controllo="AC"] a').attributes('href')).toBe('#anno-a-cavallo');
        expect(p.find('[data-test="riga-fissa"]').text()).toContain('I controlli verificano');
    });

    test('oltre il confine: i due versi, la tabella per tipo, la norma detta una volta, i rimedi; nessun pulsante «correggi»', () => {
        const a = monta().find('[data-test="anno-a-cavallo"]');
        expect(testo(a.find('[data-verso="B"]').html())).toContain('2 con la data di questo esercizio, scritte in un altro: 1 in rosso, 1 da leggere.');
        expect(testo(a.find('[data-test="per-tipo"] thead').html())).toContain('In rosso');
        expect(testo(a.find('[data-verso="B"]').html())).toContain('non contiene € 1.000,00 di costi');
        expect(a.findAll('[data-test="norma"]')).toHaveLength(1);
        expect(a.findAll('[data-test="rimedio"]')).toHaveLength(2);
        expect(testo(a.find('[data-test="per-tipo"]').html())).toContain('B Fatture registrate come pregresse 1 1 € 1.000,00');
        expect(a.text().toLowerCase()).not.toContain('correggi');
    });

    test('«Apri nel Libro Giornale» porta al giornale dell\'esercizio in cui la registrazione sta, cercato sul suo protocollo; senza protocollo, alla scrittura', () => {
        const link = monta().findAll('[data-test="apri-giornale"]').map((l) => l.attributes('href'));
        expect(link).toEqual(['/gestionale/3/esercizi/12/scritture?search=INC-2027-00001', '/gestionale/3/esercizi/9/scritture?search=F24-2027-00001', '/gestionale/3/scritture/3',
            '/gestionale/3/esercizi/12/scritture?search=FAT-2027-00002']);
    });

    test('la riga di una fattura registrata come pregressa, vista dall\'esercizio della sua data (verso B), è in rosso e dice il vero: «pregressa», non «cambia la competenza»; una fattura sì', () => {
        const p = monta();
        const pregressa = testo(p.find('[data-scrittura="3"]').html());
        expect(pregressa).toContain('pregressa');
        expect(pregressa).toContain('nessun riepilogo la conta fra i costi');
        expect(pregressa).not.toContain('cambia la competenza');
        expect(testo(p.find('[data-scrittura="4"]').html())).toContain('cambia la competenza');
    });

    test('una fattura registrata come pregressa ha il suo segno in tutti e due i versi: in rosso nella pagina dell\'esercizio della sua data (verso B), da leggere, senza il fondo rosso, in quella dell\'esercizio in cui sta (verso A)', () => {
        const nota = 'registrata come pregressa: nessun riepilogo la conta fra i costi';
        const pregressa = { categoria: 'fattura_pregressa', etichetta: 'Fatture registrate come pregresse', protocollo: null, importo: 100000, nota };
        const p = monta({ numero: 4, righe_filtrate: 4, righe: [
            riga({ ...pregressa, scrittura_id: 10, verso: 'A', data: '2025-11-30', esercizio_id: 9, esercizio: 'Esercizio 2026', rossa: false }),
            riga({ ...pregressa, scrittura_id: 11, verso: 'B', data: '2026-12-15', rossa: true }),
            riga({ scrittura_id: 12, verso: 'B', data: '2026-12-20', categoria: 'fattura', etichetta: 'Fatture', rossa: true, nota: 'costo del 2027 con la data del 2026' }),
            riga({ scrittura_id: 13, verso: 'B', data: '2026-12-28' }),
        ] });
        const segno = (id: number) => p.find(`[data-scrittura="${id}"] span.uppercase`);
        const daLeggere = p.find('[data-scrittura="10"]');
        const inRosso = p.find('[data-scrittura="11"]');

        // Il segno «pregressa» c'è su tutte e due, con la stessa nota, e nessuna dice «cambia la competenza».
        for (const id of [10, 11]) {
            expect(segno(id).text()).toBe('pregressa');
            expect(testo(p.find(`[data-scrittura="${id}"]`).html())).toContain(nota);
            expect(testo(p.find(`[data-scrittura="${id}"]`).html())).not.toContain('cambia la competenza');
        }
        expect(segno(12).text()).toBe('cambia la competenza');
        // Un\'altra riga da leggere (l\'incasso del 28/12) non ha segno: il segno è delle righe in rosso e della pregressa.
        expect(p.find('[data-scrittura="13"]').exists()).toBe(true);
        expect(segno(13).exists()).toBe(false);

        // Il rosso è solo della riga del verso B: il fondo e il segno. La riga da leggere ha il segno neutro, e ogni colore ha la sua coppia per il tema scuro.
        expect(inRosso.classes()).toContain('bg-rose-50/40');
        expect(segno(11).classes()).toEqual(expect.arrayContaining(['text-rose-700', 'dark:text-rose-400']));
        expect(daLeggere.classes()).not.toContain('bg-rose-50/40');
        expect(segno(10).classes()).not.toContain('text-rose-700');
        expect(segno(10).classes()).toEqual(expect.arrayContaining(['text-slate-500', 'dark:text-slate-400']));
    });

    test('la guida dice dove la fattura registrata come pregressa è in rosso e dove è da leggere', () => {
        const guida = monta({}, true).findAll('[data-guida]').map((g) => g.text()).find((t) => t.startsWith('Oltre il confine dell\'esercizio'));
        expect(guida).toBe('Oltre il confine dell\'esercizio: Le registrazioni con la data del fatto in un esercizio e scritte in un altro. In rosso quelle che cambiano che cosa il riepilogo per competenza conta; '
            + 'da leggere il denaro che attraversa il confine. Una fattura registrata come pregressa è in rosso nell\'esercizio della sua data, dove cambia la competenza, '
            + 'e da leggere in quello in cui sta, che non la conta come costo; se ha una parte non coperta dai saldi iniziali, quella parte è un costo dell\'esercizio in cui sta, e la riga resta rossa anche lì.');
    });

    test('i filtri chiedono al server le righe; quando sono più di una pagina, le pagine con i filtri scelti', async () => {
        const p = monta({ righe_filtrate: 120, filtri: { verso: 'A', tipo: 'incasso', colore: null }, pagine: { current_page: 2, last_page: 3, per_page: 50, total: 120, from: 51, to: 100, nome: 'pagina_confine' } });
        await p.find('[data-filtro="colore"]').setValue('rosso');
        expect(get).toHaveBeenCalledWith('/gestionale/3/esercizi/9/rendiconto/controlli', { verso: 'A', tipo: 'incasso', colore: 'rosso' }, expect.objectContaining({ only: ['anno_a_cavallo'] }));
        const pagine = p.find('[data-test="pagine-confine"]');
        expect(testo(pagine.html())).toContain('Righe 51–100 di 120');
        expect(pagine.findAll('a').map((l) => [l.text(), l.attributes('href')])).toEqual([
            ['Precedenti', '/gestionale/3/esercizi/9/rendiconto/controlli?verso=A&tipo=incasso&per_page=50&pagina_confine=1'],
            ['Successive', '/gestionale/3/esercizi/9/rendiconto/controlli?verso=A&tipo=incasso&per_page=50&pagina_confine=3'],
        ]);
        expect(p.text()).not.toContain('con i filtri si leggono le altre');
        expect(monta().find('[data-test="pagine-confine"]').exists()).toBe(false);
    });

    test('le pagine portano le righe per pagina scelte: con 20 righe per pagina «Successive» apre la pagina 2 da 20, non da 50', () => {
        const p = monta({ righe_filtrate: 80, pagine: { current_page: 1, last_page: 4, per_page: 20, total: 80, from: 1, to: 20, nome: 'pagina_confine' } });
        expect(p.find('[data-test="pagine-confine"]').findAll('a').map((l) => [l.text(), l.attributes('href')])).toEqual([
            ['Successive', '/gestionale/3/esercizi/9/rendiconto/controlli?per_page=20&pagina_confine=2'],
        ]);
    });

    test('una pagina dopo l\'ultima lo dice, con il collegamento alla prima, e non dice che non ci sono registrazioni; una pagina vuota per i filtri sì', () => {
        // Con più pagine: la pagina 4 di 3 (le righe 31–80 non esistono più), 30 righe per pagina.
        const oltre = monta({ righe: [], righe_filtrate: 80, pagine: { current_page: 4, last_page: 3, per_page: 30, total: 80, from: null, to: null, nome: 'pagina_confine' } });
        expect(testo(oltre.find('[data-test="elenco"]').html())).toContain('Questa pagina è dopo l\'ultima.');
        expect(testo(oltre.text())).not.toContain('Nessuna registrazione con questi filtri');
        expect(testo(oltre.find('[data-test="pagine-confine"]').html())).not.toContain('Righe');
        expect(oltre.find('[data-test="dopo-l-ultima"] a').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto/controlli?per_page=30&pagina_confine=1');
        expect(oltre.find('[data-test="pagine-confine"]').findAll('a').map((l) => l.text())).toEqual(['Precedenti']);

        // Con una pagina sola (la barra non c'è): la riga lo dice lo stesso.
        const una = monta({ righe: [], righe_filtrate: 30, filtri: { verso: 'A', tipo: null, colore: null }, pagine: { current_page: 2, last_page: 1, per_page: 50, total: 30, from: null, to: null, nome: 'pagina_confine' } });
        expect(testo(una.find('[data-test="elenco"]').html())).toContain('Questa pagina è dopo l\'ultima.');
        expect(una.find('[data-test="dopo-l-ultima"] a').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto/controlli?verso=A&per_page=50&pagina_confine=1');

        // Nessuna riga per i filtri, alla prima pagina: è vero che non ce ne sono.
        const vuota = monta({ righe: [], righe_filtrate: 0, filtri: { verso: 'B', tipo: 'f24', colore: null }, pagine: { current_page: 1, last_page: 1, per_page: 50, total: 0, from: null, to: null, nome: 'pagina_confine' } });
        expect(testo(vuota.find('[data-test="elenco"]').html())).toContain('Nessuna registrazione con questi filtri');
        expect(vuota.find('[data-test="dopo-l-ultima"]').exists()).toBe(false);
    });
});
