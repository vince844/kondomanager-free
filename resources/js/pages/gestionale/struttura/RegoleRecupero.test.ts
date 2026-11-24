// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La pagina delle regole del recupero: la regola di oggi, la storia con il tasso e i giorni, l'avviso di un tasso da una delibera, «Elimina» solo per
 * una regola mai usata; senza regole, la frase della legge. «Scrivi una regola»: la data nel formato del programma; il tasso come due schede da
 * scegliere, nessuna già scelta, ognuna con la sua riga; i tre giorni con l'etichetta sopra e la spiegazione sotto; il pulsante spento dice che cosa
 * manca; un tasso scritto vuole il riferimento, con il suggerimento accanto al campo, e una regola scritta prima senza riferimento lo dice nella storia.
 * La tabella del tasso legale: l'anno che manca, il tasso scritto a mano con chi, quando e la fonte, la cifra del programma che vince; «Scrivi il tasso
 * di un anno che manca», «Correggi» e «Togli» solo per chi gestisce le impostazioni generali. Il pulsante «Guida» dell'intestazione apre la guida.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import VueDatePicker from '@vuepic/vue-datepicker';

const post = vi.fn();
const del = vi.fn();
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: (...a: unknown[]) => post(...a), delete: (...a: unknown[]) => del(...a) },
    usePage: () => ({ props: {} }),
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome, generatePath: (p: string) => `/${p}` }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const Pagina = (await import('./RegoleRecupero.vue')).default;

const SENZA = 'Senza una regola vale la legge: il tasso legale dell\'anno (artt. 1224, 1282 e 1284 c.c.), dal giorno dopo la scadenza.';
const AVVISO = 'Un tasso diverso dal legale vale se lo prevede il regolamento contrattuale o un voto unanime; una delibera a maggioranza può non bastare: controlla la fonte.';
const AVVISO_REGOLAMENTO = 'Un tasso diverso dal legale vale se lo prevede il regolamento contrattuale o un voto unanime: controlla che il regolamento sia contrattuale.';
const FONTE = 'D.M. 10/12/2026, G.U. n. 290 del 14/12/2026';
const regola = (id: number, dal: string, altro: Record<string, unknown> = {}) => ({ id, dal, tasso_tipo: 'legale', tasso: 'Tasso legale dell\'anno', fonte: null, riferimento: null,
    giorni_decorrenza: 0, giorni_tolleranza: null, termine_lettere_giorni: null, avviso: null, creata_da: 'Vincenzo', usata: null, ...altro });

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; post.mockReset(); del.mockReset(); });
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const monta = (regole: unknown[], diOggi: number | null, altro: Record<string, unknown> = {}) => {
    w = mount(Pagina, {
        props: { condominio: { id: 3, nome: 'Via Verdi' }, condomini: [], esercizio: null, regole, di_oggi: diOggi, tasso_di_oggi: '3,00 % annuo, art. 12 del regolamento',
            tabella_legale: [{ anno: 2026, tasso: '1,60 %', a_mano_diverso: null }, { anno: 2025, tasso: '2,00 %', a_mano_diverso: null }],
            legali_a_mano: [], anni_mancanti: [{ anno: 2027, frase: 'non ancora: il decreto esce entro il 15 dicembre 2026' }], anni_da_scrivere: [2027],
            puo_scrivere_tasso_legale: true, puo_modificare_regole: true,
            fonti: [{ valore: 'regolamento', nome: 'regolamento' }, { valore: 'delibera', nome: 'delibera dell\'assemblea' }, { valore: 'altro', nome: 'altro' }],
            frase_senza_regole: SENZA, avvisi_fonti: { regolamento: AVVISO_REGOLAMENTO, delibera: AVVISO }, oggi: '2026-11-15', ...altro } as any,
        global: { stubs: {
            GestionaleLayout: { template: '<div><slot /></div>' }, StrutturaLayout: { template: '<div><slot /></div>' },
            PageHeaderGuide: { props: { hasTextGuide: Boolean, textGuideTitle: String }, emits: ['open-text-guide'],
                template: '<button v-if="hasTextGuide" type="button" data-testid="apri-guida" @click="$emit(\'open-text-guide\')">{{ textGuideTitle }}</button>' },
            RegoleRecuperoGuide: { props: ['open'], template: '<div v-if="open" data-testid="guida-aperta" />' },
            TassoLegaleAManoDialog: { props: ['open', 'anno', 'anni'], template: '<div v-if="open" data-testid="finestra-tasso">{{ anno }} di {{ anni.join(",") }}</div>' },
        } },
    });

    return w;
};

describe('le regole del recupero', () => {
    test('la storia: il tasso, i giorni, l\'avviso della delibera; «Elimina» solo per una regola mai usata', () => {
        const pagina = monta([
            regola(2, '2026-07-01', { tasso_tipo: 'scritto', tasso: '3,00 % annuo, delibera del 14/03/2026', fonte: 'delibera', giorni_decorrenza: 10, giorni_tolleranza: 15, avviso: AVVISO }),
            regola(1, '2026-01-01', { usata: '2026-06-29' }),
        ], 2);
        const righe = pagina.findAll('[data-testid="regola"]');
        expect(righe[0].text()).toContain('Dal 01/07/2026');
        expect(righe[0].text()).toContain('vale oggi');
        expect(righe[0].text()).toContain('Interessi dal giorno dopo la scadenza più 10 giorni · tolleranza prima di sollecitare 15 giorni');
        expect(righe[0].get('[data-testid="avviso"]').text()).toBe(AVVISO);
        expect(righe[0].find('[data-testid="elimina-regola"]').exists()).toBe(true);
        expect(righe[1].find('[data-testid="elimina-regola"]').exists()).toBe(false);
        expect(righe[1].text()).toContain('usata per gli interessi registrati al 29/06/2026: resta');
        expect(pagina.get('[data-testid="di-oggi"]').text()).toContain('3,00 % annuo, art. 12 del regolamento');
        expect(pagina.find('[data-testid="senza-regole"]').exists()).toBe(false);
        expect(pulito(pagina.get('[data-testid="tabella-legale"]').text())).toContain('2026 1,60 %');
    });

    test('«Scrivi una regola»: la data nel formato del programma, il tasso come due schede con la loro riga, nessuna già scelta', () => {
        const pagina = monta([], null);
        expect(pagina.get('[data-testid="senza-regole"]').text()).toBe(SENZA);
        expect(pagina.get('[data-testid="nessuna-regola"]').text()).toContain('vale la legge');
        const sezione = pagina.get('[data-testid="regola-nuova"]');
        expect(sezione.find('input[type="radio"]').exists()).toBe(false);
        expect(sezione.find('input[type="date"]').exists()).toBe(false);
        const data = pagina.getComponent(VueDatePicker);
        expect(data.props('format')).toBe('dd/MM/yyyy');
        expect(data.props('modelType')).toBe('yyyy-MM-dd');
        expect(pulito(sezione.get('[data-testid="etichetta-dal"]').text())).toBe('Da quando vale');

        const schede = sezione.findAll('[role="radio"][data-testid^="scheda-"]');
        expect(schede.map((s) => s.attributes('data-testid'))).toEqual(['scheda-legale', 'scheda-scritto']);
        expect(schede.map((s) => s.attributes('aria-checked'))).toEqual(['false', 'false']);
        expect(pulito(schede[0].get('[data-testid="titolo"]').text())).toBe('Il tasso legale dell\'anno');
        expect(pulito(schede[0].get('[data-testid="riga"]').text())).toBe('Cambia ogni 1° gennaio. Senza una regola vale questo.');
        expect(pulito(schede[1].get('[data-testid="titolo"]').text())).toBe('Un tasso scritto, con la sua fonte');
        expect(pulito(schede[1].get('[data-testid="riga"]').text())).toBe('Dal regolamento contrattuale o da un voto all\'unanimità.');
        expect(sezione.find('[data-testid="tasso-scritto"]').exists()).toBe(false);
    });

    test('«I giorni»: tre caselle della stessa larghezza, l\'etichetta corta sopra e la spiegazione sotto, niente nel segnaposto', () => {
        const pagina = monta([], null);
        const caselle = pagina.findAll('[data-testid="giorni"] > [data-testid="casella-giorni"]');
        expect(pagina.get('[data-testid="giorni"]').classes()).toEqual(expect.arrayContaining(['grid', 'sm:grid-cols-3']));
        expect(caselle.map((c) => [pulito(c.get('label').text()), pulito(c.get('[data-testid="spiegazione"]').text())])).toEqual([
            ['Interessi dopo la scadenza', '0: dal giorno dopo la scadenza'],
            ['Tolleranza prima del sollecito', 'Vuoto: nessun suggerimento'],
            ['Termine scritto nelle lettere', 'Vuoto: quello che scegli nella finestra'],
        ]);
        for (const c of caselle) {
            const casella = c.get('input');
            expect(casella.attributes('placeholder')).toBeUndefined();
            expect(casella.classes()).toContain('w-full');
            // L'etichetta, la casella e la spiegazione in quest'ordine.
            expect(c.element.children[0].tagName).toBe('LABEL');
            expect(c.element.children[c.element.children.length - 1].getAttribute('data-testid')).toBe('spiegazione');
        }
    });

    test('il pulsante in fondo a destra dice che cosa manca finché è spento; con un tasso scritto vuole la misura, la fonte e il riferimento, e la scheda scelta ha il bordo', async () => {
        const pagina = monta([], null);
        const pulsante = () => pagina.get('[data-testid="registra-regola"]');
        const manca = () => pagina.find('[data-testid="cosa-manca"]');
        expect(pagina.get('[data-testid="piede"]').classes()).toEqual(expect.arrayContaining(['border-t', 'justify-end']));
        expect(pulsante().attributes('disabled')).toBeDefined();
        expect(manca().text()).toBe('Scrivi la data da cui vale');

        pagina.getComponent(VueDatePicker).vm.$emit('update:modelValue', '2027-01-01');
        await pagina.vm.$nextTick();
        expect(manca().text()).toBe('Scegli il tasso');

        await pagina.get('[data-testid="scheda-scritto"]').trigger('click');
        expect(pagina.get('[data-testid="scheda-scritto"]').attributes('aria-checked')).toBe('true');
        expect(pagina.get('[data-testid="scheda-scritto"]').classes()).toContain('ring-1');
        expect(pagina.get('[data-testid="scheda-legale"]').classes()).not.toContain('ring-1');
        expect(pulsante().attributes('disabled')).toBeDefined();
        expect(manca().text()).toBe('Scrivi il tasso annuo');
        await pagina.get('[data-testid="tasso"]').setValue('3,5');
        expect(manca().text()).toBe('Scegli da dove viene il tasso');
        await pagina.get('[data-testid="fonte-regolamento"]').trigger('click');
        expect(pagina.get('[data-testid="avviso-fonte"]').text()).toBe(AVVISO_REGOLAMENTO);
        await pagina.get('[data-testid="fonte-delibera"]').trigger('click');
        expect(pagina.get('[data-testid="fonte-delibera"]').attributes('aria-checked')).toBe('true');
        expect(pagina.get('[data-testid="avviso-fonte"]').text()).toBe(AVVISO);
        // Il riferimento è obbligatorio: senza, o con soli spazi, il pulsante resta spento e la frase lo dice.
        expect(pulsante().attributes('disabled')).toBeDefined();
        expect(manca().text()).toBe('Scrivi il riferimento del tasso');
        await pagina.get('[data-testid="riferimento"]').setValue('   ');
        expect(manca().text()).toBe('Scrivi il riferimento del tasso');
        await pulsante().trigger('click');
        expect(post).not.toHaveBeenCalled();
        await pagina.get('[data-testid="riferimento"]').setValue('assemblea del 10/03/2025, verbale n. 3, all\'unanimità');
        expect(pulsante().attributes('disabled')).toBeUndefined();
        expect(manca().exists()).toBe(false);
        await pulsante().trigger('click');
        expect(post.mock.calls[0][0]).toBe('/gestionale.recupero-crediti.store');
        expect(post.mock.calls[0][1]).toEqual({ dal: '2027-01-01', tasso_tipo: 'scritto', tasso_annuo: '3,5', fonte: 'delibera',
            riferimento: 'assemblea del 10/03/2025, verbale n. 3, all\'unanimità', giorni_decorrenza: 0, giorni_tolleranza: null, termine_lettere_giorni: null });

        // Il tasso legale dell'anno non chiede altro.
        await pagina.get('[data-testid="scheda-legale"]').trigger('click');
        expect(pagina.find('[data-testid="tasso-scritto"]').exists()).toBe(false);
        expect(manca().exists()).toBe(false);
    });
});

describe('il riferimento del tasso scritto', () => {
    const SUGGERIMENTO = 'Scrivi da dove viene il tasso, così si può controllare: per esempio «art. 12 del regolamento contrattuale» o «assemblea del '
        + '10/03/2025, verbale n. 3, all\'unanimità». Si stampa nelle lettere e nell\'addebito degli interessi, accanto al tasso.';

    test('accanto al campo il suggerimento, con l\'icona «i»; il campo tiene al più i caratteri che il server accetta', async () => {
        const pagina = monta([], null, { riferimento_massimo: 150 });
        await pagina.get('[data-testid="scheda-scritto"]').trigger('click');
        const sezione = pagina.get('[data-testid="tasso-scritto"]');
        const suggerimento = sezione.get('[data-testid="suggerimento-riferimento"]');
        expect(suggerimento.attributes('aria-label')).toBe(SUGGERIMENTO);
        expect(suggerimento.attributes('tabindex')).toBe('0');
        // L'icona sta nell'etichetta del campo, accanto al nome.
        expect(pulito(sezione.get('label[for="regola-riferimento"]').text())).toBe('Il riferimento');
        expect(sezione.get('label[for="regola-riferimento"]').element.parentElement?.contains(suggerimento.element)).toBe(true);
        expect(sezione.get('[data-testid="riferimento"]').attributes('maxlength')).toBe('150');
    });

    test('una regola scritta prima senza riferimento resta, e la storia la segna «senza riferimento»; le altre no', () => {
        const pagina = monta([
            regola(3, '2026-09-01', { tasso_tipo: 'scritto', tasso: '4,00 % annuo, regolamento', fonte: 'regolamento', riferimento: null }),
            regola(2, '2026-07-01', { tasso_tipo: 'scritto', tasso: '3,00 % annuo, art. 12 del regolamento contrattuale', fonte: 'regolamento', riferimento: 'art. 12 del regolamento contrattuale' }),
            regola(1, '2026-01-01'),
        ], 3);
        const righe = pagina.findAll('[data-testid="regola"]');
        expect(pulito(righe[0].get('[data-testid="senza-riferimento"]').text())).toBe('senza riferimento');
        expect(righe[0].find('[data-testid="elimina-regola"]').exists()).toBe(true);
        expect(righe[1].find('[data-testid="senza-riferimento"]').exists()).toBe(false);
        expect(righe[2].find('[data-testid="senza-riferimento"]').exists()).toBe(false);
    });
});

describe('la guida', () => {
    test('il pulsante «Guida» dell\'intestazione apre la guida della pagina', async () => {
        const pagina = monta([], null);
        expect(pagina.find('[data-testid="guida-aperta"]').exists()).toBe(false);
        const pulsante = pagina.get('[data-testid="apri-guida"]');
        expect(pulito(pulsante.text())).toBe('Guida');
        await pulsante.trigger('click');
        expect(pagina.find('[data-testid="guida-aperta"]').exists()).toBe(true);
    });
});

describe('il tasso legale', () => {
    test('l\'anno che manca si dice; chi gestisce le impostazioni generali vede «Scrivi il tasso di un anno che manca», che apre la finestra sul primo anno che manca', async () => {
        const pagina = monta([], null);
        const tabella = pagina.get('[data-testid="tabella-legale"]');
        expect(pulito(tabella.get('[data-testid="anno-mancante"]').text())).toBe('2027 non ancora: il decreto esce entro il 15 dicembre 2026');
        expect(pagina.find('[data-testid="finestra-tasso"]').exists()).toBe(false);
        await tabella.get('[data-testid="scrivi-tasso-legale"]').trigger('click');
        expect(pulito(pagina.get('[data-testid="finestra-tasso"]').text())).toBe('2027 di 2027');
    });

    test('il tasso scritto a mano con chi l\'ha scritto, quando e la fonte; «Correggi» e «Togli»; la cifra del programma che vince si dice', async () => {
        const pagina = monta([], null, {
            tabella_legale: [{ anno: 2027, tasso: '2,50 %', a_mano_diverso: 'Per il 2027 lo studio aveva scritto a mano 2,25 % (Mario Rossi, il 16/12/2026): il programma ha 2,50 %, e vale quello del programma.' },
                { anno: 2026, tasso: '1,60 %', a_mano_diverso: null }],
            legali_a_mano: [{ anno: 2028, tasso: '2,10 %', tasso_annuo: '2,1', fonte: FONTE, scritto_da: 'Mario Rossi', scritto_il: '2027-12-16' }],
            anni_mancanti: [], anni_da_scrivere: [2028],
        });
        const tabella = pagina.get('[data-testid="tabella-legale"]');
        const aMano = tabella.get('[data-testid="legale-a-mano"]');
        expect(pulito(aMano.text())).toContain('2028 2,10 %');
        expect(pulito(aMano.text())).toContain(`scritto a mano da Mario Rossi il 16/12/2027 · fonte: ${FONTE}`);
        expect(pulito(tabella.get('[data-testid="a-mano-diverso"]').text())).toBe('Per il 2027 lo studio aveva scritto a mano 2,25 % (Mario Rossi, il 16/12/2026): il programma ha 2,50 %, e vale quello del programma.');
        // Nessun anno manca: il pulsante non c'è, «Correggi» sì.
        expect(tabella.find('[data-testid="scrivi-tasso-legale"]').exists()).toBe(false);
        await aMano.get('[data-testid="correggi-tasso-legale"]').trigger('click');
        expect(pulito(pagina.get('[data-testid="finestra-tasso"]').text())).toBe('2028 di 2028');
        await aMano.get('[data-testid="togli-tasso-legale"]').trigger('click');
        expect(del.mock.calls[0][0]).toBe('/gestionale.recupero-crediti.tasso-legale.destroy');
        expect(del.mock.calls[0][1]).toMatchObject({ data: { anno: 2028 } });
    });

    test('senza il permesso delle impostazioni generali: niente pulsante, niente «Correggi» né «Togli»', () => {
        const pagina = monta([], null, {
            puo_scrivere_tasso_legale: false,
            legali_a_mano: [{ anno: 2027, tasso: '2,25 %', tasso_annuo: '2,25', fonte: FONTE, scritto_da: 'Mario Rossi', scritto_il: '2026-12-16' }],
            anni_mancanti: [{ anno: 2028, frase: 'non ancora: il decreto esce entro il 15 dicembre 2027' }], anni_da_scrivere: [2027, 2028],
        });
        const tabella = pagina.get('[data-testid="tabella-legale"]');
        expect(pulito(tabella.get('[data-testid="legale-a-mano"]').text())).toContain('scritto a mano da Mario Rossi il 16/12/2026');
        expect(tabella.find('[data-testid="scrivi-tasso-legale"]').exists()).toBe(false);
        expect(tabella.find('[data-testid="correggi-tasso-legale"]').exists()).toBe(false);
        expect(tabella.find('[data-testid="togli-tasso-legale"]').exists()).toBe(false);
    });
});

describe('chi non può modificare il condominio (Coda 755 (2))', () => {
    test('con il solo pannello la storia delle regole si legge, ma non c\'è «Scrivi una regola» né «Elimina»; la regola usata dice ancora che resta', () => {
        monta([regola(1, '2026-09-01'), regola(2, '2026-01-01', { usata: '2026-06-29' })], 1, { puo_modificare_regole: false });

        expect(w!.findAll('[data-testid="regola"]')).toHaveLength(2);
        expect(w!.find('[data-testid="regola-nuova"]').exists()).toBe(false);
        expect(w!.find('[data-testid="elimina-regola"]').exists()).toBe(false);
        expect(pulito(w!.text())).not.toContain('Scrivi una regola');
        expect(pulito(w!.text())).toContain('resta');
    });

    test('con il permesso il riquadro e il pulsante ci sono', () => {
        monta([regola(1, '2026-09-01')], 1, { puo_modificare_regole: true });

        expect(w!.find('[data-testid="regola-nuova"]').exists()).toBe(true);
        expect(w!.find('[data-testid="elimina-regola"]').exists()).toBe(true);
    });
});
