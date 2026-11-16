// @vitest-environment jsdom

/**
 * L'intestazione e le card dell'estratto conto di una persona.
 *
 * - «Azioni»: un menu solo al posto di «Lettere» e «Stampe», con tre gruppi e le loro etichette (Lettere, Addebiti, Stampe) e cinque voci a due righe; i
 *   `data-testid` delle voci di prima restano. Ogni voce apre la cosa giusta: la finestra delle lettere, l'elenco «Rate scadute» con questa persona,
 *   le due finestre degli addebiti, il PDF.
 * - «Guida» nell'intestazione apre la guida nel cassetto di lato; le tre card della guida in alto restano.
 * - Sei card: la sesta, «Addebiti personali», ha il totale e quanti sono, dalla stessa lista dell'elenco, e due pulsanti che aprono le finestre giuste;
 *   «Totale addebiti» dice che dentro ci sono anche gli addebiti.
 * - Le due finestre degli addebiti sono una volta sola, nella pagina: le aprono il menu, la card e l'elenco; dopo un addebito si ricaricano l'elenco,
 *   la tabella e le cifre.
 *
 * Perché `shallowMount` con l'intestazione che rende lo spazio dei pulsanti: i menu e le finestre sono componenti della libreria, e il test legge le
 * proprietà che la pagina passa (`open`, `origine`, `persone`). Che cosa NON copre: come appare il menu aperto (lo apre la libreria) e la larghezza della
 * fila dei pulsanti, che si guarda a video; la card «Lettere», in `EstrattoContoAnagraficaCorrispondenza.test.ts`.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { nextTick } from 'vue';
import { shallowMount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import NuovoAddebitoDialog from '@/components/gestionale/addebiti/NuovoAddebitoDialog.vue';
import AddebitaSpesaDialog from '@/components/gestionale/addebiti/AddebitaSpesaDialog.vue';
import AddebitiDellaPersona from '@/components/gestionale/addebiti/AddebitiDellaPersona.vue';
import LettereDialog from '@/components/gestionale/lettere/LettereDialog.vue';
import EstrattoContoGuide from '@/components/guides/EstrattoContoGuide.vue';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ url: '/admin/gestionale/28/anagrafiche/7/estratto-conto', props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (name: string, parametri: Record<string, unknown> = {}) =>
    `/${name}?${new URLSearchParams(Object.entries(parametri).map(([k, v]) => [k, String(v)])).toString()}`;

const EstrattoContoAnagrafica = (await import('./EstrattoContoAnagrafica.vue')).default;

const addebito = (extra: Record<string, unknown> = {}) => ({ rata_id: 11, causale: 'Quota non assegnata: Pulizie, Int. U3', unita: 'Int. U3', importo: 5096, versato: 0,
    scadenza: '2026-12-20', natura: 'contributo', spesa_fatturata: false, piano: 'Addebiti individuali – Ordinaria 2026', annullabile: true, ...extra });

// L'intestazione rende lo spazio dei pulsanti, come quella vera: il menu «Azioni» sta lì.
const Intestazione = {
    name: 'PageHeaderGuide',
    props: { pageTitle: String, pageSubtitle: String, guides: Array, breadcrumbs: Array, condominio: Object, esercizio: Object, backUrl: String, backText: String,
        hasTextGuide: Boolean, textGuideTitle: String },
    emits: ['open-text-guide'],
    template: '<div data-testid="intestazione"><slot name="actions" /></div>',
};

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; vi.restoreAllMocks(); });

function monta(altro: Record<string, unknown> = {}) {
    w = shallowMount(EstrattoContoAnagrafica, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never,
            esercizio: { id: 5, nome: 'Esercizio 2026' } as never,
            anagrafica: { id: 7, nome: 'Giuseppe Rossi', codice_fiscale: null, immobili: [] } as never,
            timeline: [],
            stats: {
                totale_addebiti: '€ 2.341,84', totale_versamenti: '€ 0,00', saldo_finale: '€ 2.341,84', saldo_raw: 234184, saldo_iniziale: '€ 0,00', saldo_iniziale_raw: 0,
                credito_disponibile: '€ 0,00', credito_disponibile_raw: 0, credito_per_gestione: [], compensabile: '€ 0,00', compensabile_raw: 0, compensabile_frase: '',
                compensabile_rata_id: null,
            },
            rimborso: { quote: [], casse: [], credito_in_saldi: 0 },
            addebiti: [addebito(), addebito({ rata_id: 12, causale: 'Spese di sollecito', importo: 2500, natura: 'accessorio', tipo: 'sollecito' })] as never,
            ...altro,
        },
        global: { renderStubDefaultSlot: true, stubs: { PageHeaderGuide: Intestazione }, mocks: { route: (name: string) => `/${name}` } },
    });

    return w;
}

const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const aperte = (p: VueWrapper) => ({
    addebito: p.findComponent(NuovoAddebitoDialog).props('open'),
    spese: p.findComponent(AddebitaSpesaDialog).props('open'),
    lettera: p.findComponent(LettereDialog).props('open'),
});

describe('il menu «Azioni» dell\'intestazione', () => {
    test('un menu solo, con tre gruppi e cinque voci a due righe; «Lettere» e «Stampe» non sono più menu a sé', () => {
        const pagina = monta();
        const intestazione = pagina.get('[data-testid="intestazione"]');
        expect(intestazione.get('[data-testid="menu-azioni"]').text()).toBe('Azioni');
        expect(intestazione.find('[data-testid="menu-lettere"]').exists()).toBe(false);
        const voci = intestazione.get('[data-testid="voci-azioni"]');
        expect(voci.findAll('dropdown-menu-label-stub').map((l) => pulito(l.text()))).toEqual(['Lettere', 'Addebiti', 'Stampe']);
        expect(voci.findAll('dropdown-menu-separator-stub')).toHaveLength(2);
        const righe = [...voci.element.querySelectorAll('.text-xs.font-medium')].map((t) => [pulito(t.textContent), pulito(t.nextElementSibling?.textContent)]);
        expect(righe).toEqual([
            ['Prepara una lettera', 'Promemoria, sollecito, diffida, messa in mora'],
            ['Rate scadute', 'L\'elenco, con questa persona sola'],
            ['Nuovo addebito', 'Un contributo, o una spesa di una sola unità già fatturata'],
            ['Spese del ritardo', 'Le spese per un pagamento in ritardo'],
            ['Estratto conto', 'PDF riepilogativo per anagrafica'],
        ]);
        // L'ordine dei gruppi nel menu: ogni etichetta prima delle sue voci.
        const ordine = [...voci.element.querySelectorAll('dropdown-menu-label-stub, [data-testid]')].map((e) => e.getAttribute('data-testid') ?? pulito(e.textContent));
        expect(ordine).toEqual(['Lettere', 'prepara-una-lettera', 'rate-scadute-della-persona', 'Addebiti', 'voce-nuovo-addebito', 'voce-spese-del-ritardo', 'Stampe',
            'stampa-estratto-conto']);
    });

    test('ogni voce apre la cosa giusta: la lettera, l\'elenco con questa persona, le due finestre degli addebiti, il PDF in un\'altra scheda', async () => {
        const apri = vi.spyOn(window, 'open').mockImplementation(() => null);
        const pagina = monta();
        expect(aperte(pagina)).toEqual({ addebito: false, spese: false, lettera: false });

        await pagina.get('[data-testid="prepara-una-lettera"]').trigger('click');
        expect(aperte(pagina)).toEqual({ addebito: false, spese: false, lettera: true });
        expect(pagina.get('[data-testid="rate-scadute-della-persona"]').attributes('href')).toBe('/gestionale.rate-scadute.index?condominio=28&anagrafica_id=7');

        await pagina.get('[data-testid="voce-nuovo-addebito"]').trigger('click');
        expect(pagina.findComponent(NuovoAddebitoDialog).props('open')).toBe(true);
        expect(pagina.findComponent(NuovoAddebitoDialog).props('origine')).toEqual({ tipo: 'persona', anagraficaId: 7 });
        expect(pagina.findComponent(AddebitaSpesaDialog).props('open')).toBe(false);

        await pagina.get('[data-testid="voce-spese-del-ritardo"]').trigger('click');
        expect(pagina.findComponent(AddebitaSpesaDialog).props('open')).toBe(true);
        expect(pagina.findComponent(AddebitaSpesaDialog).props('persone')).toEqual([7]);

        await pagina.get('[data-testid="stampa-estratto-conto"]').trigger('click');
        expect(apri).toHaveBeenCalledWith('/admin.gestionale.anagrafiche.estratto-conto.print?condominio=28&anagrafica=7', '_blank');
    });
});

describe('«Guida» nell\'intestazione', () => {
    test('c\'è, e apre la guida della pagina nel cassetto; le tre card della guida in alto restano', async () => {
        const pagina = monta();
        const intestazione = pagina.findComponent(PageHeaderGuide);
        expect(intestazione.props('hasTextGuide')).toBe(true);
        expect(intestazione.props('textGuideTitle')).toBe('Guida');
        expect((intestazione.props('guides') as Array<{ title: string }>).map((g) => g.title)).toEqual(['Saldo Iniziale', 'Addebiti', 'Pagamenti']);
        expect(pagina.findComponent(EstrattoContoGuide).props('open')).toBe(false);
        intestazione.vm.$emit('open-text-guide');
        await nextTick();
        expect(pagina.findComponent(EstrattoContoGuide).props('open')).toBe(true);
    });
});

describe('le sei card', () => {
    test('sei, in quest\'ordine; la sesta con il totale e quanti sono; «Totale addebiti» dice che dentro ci sono gli addebiti', () => {
        const pagina = monta();
        expect(pagina.get('[data-testid="le-card"]').findAll('h3').map((h) => h.text())).toEqual(['Saldo iniziale', 'Totale addebiti', 'Totale Versato', 'Saldo Finale',
            'Credito disponibile', 'Addebiti personali']);
        expect(pulito(pagina.get('[data-testid="card-totale-addebiti"] p').text())).toBe('Rate emesse e addebiti');
        const card = pagina.get('[data-testid="card-addebiti-personali"]');
        expect(card.get('[data-testid="totale-addebiti-personali"]').text()).toBe('€ 75,96');
        expect(card.get('[data-testid="quanti-addebiti-personali"]').text()).toBe('2 addebiti · nel totale addebiti');
        expect(card.findAll('button').map((b) => pulito(b.text()))).toEqual(['Nuovo', 'Spese del ritardo']);
        // Le etichette e i pulsanti piccoli sono a rettangolo arrotondato, come `Badge`: niente pillole, né qui né nella card «Lettere».
        expect(card.html()).not.toContain('rounded-full');
        expect(pagina.get('[data-testid="ultima-lettera-in-testa"]').html()).not.toContain('rounded-full');
    });

    test('i due pulsanti della card aprono le finestre giuste, per questa persona', async () => {
        const pagina = monta();
        await pagina.get('[data-testid="card-nuovo-addebito"]').trigger('click');
        expect(aperte(pagina)).toEqual({ addebito: true, spese: false, lettera: false });
        pagina.findComponent(NuovoAddebitoDialog).vm.$emit('update:open', false);
        await nextTick();
        await pagina.get('[data-testid="card-spese-del-ritardo"]').trigger('click');
        expect(aperte(pagina)).toEqual({ addebito: false, spese: true, lettera: false });
        expect(pagina.findComponent(AddebitaSpesaDialog).props('persone')).toEqual([7]);
    });

    test('senza addebiti la card dice zero e che non ce ne sono, e i due pulsanti restano', () => {
        const card = monta({ addebiti: [] }).get('[data-testid="card-addebiti-personali"]');
        expect(card.get('[data-testid="totale-addebiti-personali"]').text()).toBe('€ 0,00');
        expect(card.get('[data-testid="quanti-addebiti-personali"]').text()).toBe('Nessun addebito personale');
        expect(card.find('[data-testid="card-nuovo-addebito"]').exists()).toBe(true);
        expect(card.find('[data-testid="card-spese-del-ritardo"]').exists()).toBe(true);
    });
});

describe('le finestre degli addebiti, una volta sola', () => {
    test('una finestra per tipo nella pagina; l\'elenco le chiede alla pagina; dopo un addebito si ricaricano l\'elenco, la tabella e le cifre', async () => {
        const ricarica = vi.spyOn(router, 'reload').mockImplementation(() => undefined as never);
        const pagina = monta();
        expect(pagina.findAllComponents(NuovoAddebitoDialog)).toHaveLength(1);
        expect(pagina.findAllComponents(AddebitaSpesaDialog)).toHaveLength(1);
        const elenco = pagina.findComponent(AddebitiDellaPersona);
        expect(elenco.props('addebiti')).toHaveLength(2);

        elenco.vm.$emit('nuovo');
        await nextTick();
        expect(aperte(pagina)).toEqual({ addebito: true, spese: false, lettera: false });
        pagina.findComponent(NuovoAddebitoDialog).vm.$emit('update:open', false);
        elenco.vm.$emit('spese');
        await nextTick();
        expect(aperte(pagina)).toEqual({ addebito: false, spese: true, lettera: false });

        for (const origine of [pagina.findComponent(NuovoAddebitoDialog), pagina.findComponent(AddebitaSpesaDialog), elenco]) {
            ricarica.mockClear();
            origine.vm.$emit('fatto');
            expect(ricarica).toHaveBeenCalledWith({ only: ['addebiti', 'timeline', 'stats'] });
        }
    });
});
