// @vitest-environment jsdom

/**
 * Il movimento dei fondi nella situazione patrimoniale: una riga per fondo con l'iniziale, l'accantonato, l'utilizzato, il finale, il vincolo e lo scoperto, la
 * riga del totale e le note che arrivano dal server. La pagina non somma e non calcola: ogni cifra è quella del server. Senza fondi la sezione non c'è; per un
 * esercizio finito lo scoperto non si misura e la cella dice «—».
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

const NOTA = 'Per ogni fondo, le sue entrate e uscite per cassa dell\'esercizio: accantonato è quanto è entrato nel fondo, utilizzato quanto ne è uscito, senza dire per quale opera.';

// Il caso: «Facciata» 10.000,00 + 2.000,00 − 0,00 = 12.000,00, vincolo 10.000,00, scoperto 0,00; «Ascensore» 3.000,00 − 3.500,00 = −500,00, vincolo 1.000,00,
// scoperto 1.000,00 − (−500,00) = 1.500,00 (quanto manca al saldo per arrivare al vincolo). Il totale è la somma delle righe, che arriva fatta.
const facciata = { cassa_id: 7, cassa: 'Facciata', conto_contabile_id: 70, iniziale: 1000000, accantonato: 200000, utilizzato: 0, finale: 1200000, saldo_alla_data: 1200000, vincolo: 1000000, scoperto: 0 };
const ascensore = { cassa_id: 8, cassa: 'Ascensore', conto_contabile_id: 80, iniziale: 300000, accantonato: 0, utilizzato: 350000, finale: -50000, saldo_alla_data: -50000, vincolo: 100000, scoperto: 150000 };
const movimento = (extra: Record<string, unknown> = {}) => ({
    righe: [facciata, ascensore],
    totale: { iniziale: 1300000, accantonato: 200000, utilizzato: 350000, finale: 1150000, vincolo: 1100000, scoperto: 150000 },
    nota: NOTA, nota_vincoli: null, nota_giroconti: null, nota_saldo: null,
    ...extra,
});

const pagina = (extra: Record<string, unknown> = {}) => ({
    data: '2026-09-12', dal: '2026-01-01', stato_esercizio: 'aperto',
    situazione: { attivo: { gruppi: [], totale: 0 }, passivo: { gruppi: [], totale: 0 }, costi_voci: [], ricavi_voci: [], liquidita_non_contabilizzata: 0, costi: 0, ricavi: 0, risultato_motore: 0, sbilancio: 0, quadra: true },
    sintesi: { liquidita: 2000000, in_fondi: 1150000, crediti_condomini: 0, debiti_fornitori: 0, debiti_erario: 0, spese_anticipate: 0 },
    risultato_gestione: { quote_emesse: 0, costi: 0, risultato: 0, cumulato: 0 },
    riepilogo: { dal: '2026-01-01', al: '2026-09-12', stato_esercizio: 'aperto', righe: [], totale: { iniziale: 0, entrate: 0, uscite: 0, finale: 0 }, giroconti: { entrate: 0, uscite: 0 },
        stornate: { coppie: 0, importo: 0 }, piede_quadra: true, casse_reali_negative: [] },
    liquidita: { liquidita_totale: 2000000, libera: 850000, in_fondi: 1150000, vincolata: 1150000, generica: 0, altra: 0, altra_voci: [], fondi: [], vincolo_registrato: 1100000,
        vincolo_su_fondi: 1100000, vincolo_non_accantonato: 0, scoperto_fondi: 100000, avanzo_registrato: 0, gia_speso_acconto: 0, esito_r8: 'scoperto' },
    movimento_fondi: movimento(),
    raccordo: { righe: [], somma: 0, variazione_liquidita: 0, scarto: 0, quadra: true },
    debiti: { alla: '2026-09-12', fatture: [], totale_residui: 0, saldo_fornitori: 0, saldo_erario: 0, erario_su_fatture_non_pagate: 0, scarto: 0, scarto_voci: [] },
    per_gestione: { righe: [], senza_voce: 0, senza_gestione: { quote_emesse: 0, crediti: 0, debiti: 0 }, differenza_debiti: 0, totale: { costi: 0, quote_emesse: 0, crediti: 0, debiti: 0 },
        fatture_altra_gestione: [], nota_altra_gestione: null, nota_liquidita: 'La liquidità e i fondi non si dividono per gestione: la liquidità è del condominio.' },
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
const testo = (s: string) => s.replace(/\s+/g, ' ').trim();
/** Le celle di una riga della tabella, una dopo l'altra con uno spazio. */
const celle = (r: { findAll: (s: string) => { text: () => string }[] }) => r.findAll('td').map((td) => testo(td.text())).join(' ');

describe('il movimento dei fondi', () => {
    test('una riga per fondo, con le sei cifre del server, il nome che apre il mastrino e la riga del totale', () => {
        const sezione = monta().find('[data-test="movimento-fondi"]');
        expect(sezione.exists()).toBe(true);
        expect(testo(sezione.find('h3').text())).toBe('Movimento dei fondi');
        expect(testo(sezione.text())).toContain('dal 01/01/2026 al 12/09/2026');

        const righe = sezione.findAll('[data-test="movimento-fondo"]');
        expect(righe.map((r) => celle(r))).toEqual([
            'Facciata € 10.000,00 € 2.000,00 € 0,00 € 12.000,00 € 10.000,00 € 0,00',
            'Ascensore € 3.000,00 € 0,00 € 3.500,00 € -500,00 € 1.000,00 € 1.500,00',
        ]);
        expect(righe[0].find('a').attributes('href')).toBe('/gestionale/3/esercizi/9/conti/70/movimenti');
        // Il finale sotto zero e lo scoperto si vedono in rosso.
        expect(righe[1].findAll('td')[4].classes()).toContain('text-rose-600');
        expect(righe[1].findAll('td')[6].classes()).toContain('text-rose-600');
        expect(righe[0].findAll('td')[6].classes()).not.toContain('text-rose-600');

        expect(celle(sezione.find('[data-test="movimento-fondi-totale"]'))).toBe('Fondi € 13.000,00 € 2.000,00 € 3.500,00 € 11.500,00 € 11.000,00 € 1.500,00');
        expect(testo(sezione.text())).toContain(NOTA);
        expect(sezione.find('[data-test="movimento-fondi-vincoli"]').exists()).toBe(false);
        expect(sezione.find('[data-test="movimento-fondi-saldo"]').exists()).toBe(false);
    });

    test('la sezione viene dopo la liquidità', () => {
        const html = monta().html();
        expect(html.indexOf('data-test="movimento-fondi"')).toBeGreaterThan(html.indexOf('Liquidità: libera e accantonata'));
    });

    test('esercizio finito: lo scoperto non si misura, la cella dice «—», e la nota lo spiega', () => {
        const finito = movimento({
            righe: [{ ...facciata, scoperto: null }],
            totale: { iniziale: 1000000, accantonato: 200000, utilizzato: 0, finale: 1200000, vincolo: 1000000, scoperto: null },
            nota_vincoli: 'L\'esercizio è finito: il vincolo è quello dichiarato oggi, e lo scoperto non si misura, perché i vincoli dichiarati non hanno una data.',
        });
        const sezione = monta({ stato_esercizio: 'chiuso', movimento_fondi: finito }).find('[data-test="movimento-fondi"]');

        expect(celle(sezione.find('[data-test="movimento-fondo"]'))).toBe('Facciata € 10.000,00 € 2.000,00 € 0,00 € 12.000,00 € 10.000,00 —');
        expect(celle(sezione.find('[data-test="movimento-fondi-totale"]'))).toMatch(/ —$/);
        expect(testo(sezione.find('[data-test="movimento-fondi-vincoli"]').text())).toBe('L\'esercizio è finito: il vincolo è quello dichiarato oggi, e lo scoperto non si misura, perché i vincoli dichiarati non hanno una data.');
    });

    test('la nota del saldo alla data diverso dal finale, quando il server la manda', () => {
        const nota = 'Il saldo al 12/09/2026 è diverso dal finale per «Ascensore» (€ 0,00): il finale conta le scritture dell\'esercizio e il saldo di apertura non ancora registrato a giornale, il saldo alla data le registrazioni a giornale fino a quel giorno.';
        const sezione = monta({ movimento_fondi: movimento({ nota_saldo: nota }) }).find('[data-test="movimento-fondi"]');
        expect(testo(sezione.find('[data-test="movimento-fondi-saldo"]').text())).toBe(nota);
    });

    test('i giroconti fra due fondi nelle loro colonne, quando ce ne sono; senza, le colonne di prima', () => {
        const riserva = { ...facciata, cassa: 'Riserva', accantonato: 0, giroconti_entrata: 0, giroconti_uscita: 300000, utilizzato: 0, finale: 700000 };
        const asc = { ...ascensore, iniziale: 0, accantonato: 0, giroconti_entrata: 300000, giroconti_uscita: 0, utilizzato: 100000, finale: 200000 };
        const conGiro = movimento({ righe: [riserva, asc], con_giroconti: true,
            totale: { iniziale: 1000000, accantonato: 0, giroconti_entrata: 300000, giroconti_uscita: 300000, utilizzato: 100000, finale: 900000, vincolo: 1100000, scoperto: 150000 } });
        const sezione = monta({ movimento_fondi: conGiro }).find('[data-test="movimento-fondi"]');
        expect(sezione.find('thead').findAll('th').map((th) => testo(th.text())).join(' | ')).toBe('Fondo | Iniziale | Accantonato | Giroconti in entrata | Giroconti in uscita | Utilizzato | Finale | Vincolo | Scoperto');
        expect(sezione.find('[data-test="movimento-fondi-totale"]').findAll('td').map((td) => testo(td.text())).join(' | '))
            .toBe('Fondi | € 10.000,00 | € 0,00 | € 3.000,00 | € 3.000,00 | € 1.000,00 | € 9.000,00 | € 11.000,00 | € 1.500,00');
        expect(monta().find('[data-test="giroconti-entrata"]').exists()).toBe(false);
    });

    test('la nota dei giroconti fra due fondi, quando il server la manda; senza, non c\'è', () => {
        const nota = 'I giroconti da un fondo a un altro, € 3.000,00, stanno nelle loro colonne: non sono denaro entrato nei fondi né speso.';
        const sezione = monta({ movimento_fondi: movimento({ nota_giroconti: nota }) }).find('[data-test="movimento-fondi"]');
        expect(testo(sezione.find('[data-test="movimento-fondi-giroconti"]').text())).toBe(nota);
        expect(monta({ movimento_fondi: movimento({ nota_giroconti: null }) }).find('[data-test="movimento-fondi-giroconti"]').exists()).toBe(false);
    });

    test('la liquidità di un esercizio finito non dice «mancano»: il vincolo assegnato resta, il confronto no', () => {
        const fondo = { cassa_id: 7, cassa: 'Facciata', sottotipo: 'vincolato_lavori', sottotipo_label: 'vincolato ai lavori', saldo: 1200000, vincolato: true, vincolo_registrato: 1500000, scoperto: 300000 };
        const liquidita = { ...pagina().liquidita, fondi: [fondo] };
        const aperto = monta({ liquidita }).find('[data-test="vincolo-fondo"]');
        expect(testo(aperto.text())).toBe('vincolo assegnato € 15.000,00 — mancano € 3.000,00');
        expect(aperto.classes()).toContain('text-rose-600');
        const finito = monta({ stato_esercizio: 'chiuso', liquidita: { ...liquidita, esito_r8: 'segnalazione' } }).find('[data-test="vincolo-fondo"]');
        expect(testo(finito.text())).toBe('vincolo assegnato € 15.000,00');
        expect(finito.classes()).not.toContain('text-rose-600');
    });

    test('senza fondi la sezione non c\'è', () => {
        const vuoto = movimento({ righe: [], totale: { iniziale: 0, accantonato: 0, utilizzato: 0, finale: 0, vincolo: 0, scoperto: 0 } });
        const w2 = monta({ movimento_fondi: vuoto });
        expect(w2.find('[data-test="movimento-fondi"]').exists()).toBe(false);
        expect(w2.text()).not.toContain('Movimento dei fondi');
    });
});
