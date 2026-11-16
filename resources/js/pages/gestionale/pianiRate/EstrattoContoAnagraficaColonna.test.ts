// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La colonna di destra dell'estratto conto: «Unità immobiliari» e «Lettere».
 *
 * - La colonna non entra nell'altezza della fila della griglia: il suo posto è `lg:relative`, la colonna dentro è `lg:absolute lg:inset-0`. Le sei card
 *   tengono così la loro altezza, qualunque cosa ci sia a destra; sotto `lg` la colonna va sotto le card, nel flusso.
 * - «Unità immobiliari»: al massimo le prime due, una riga ciascuna con il nome che si tronca (il nome intero nel `title`) e i badge; con più unità
 *   «e altre N unità» (al singolare «e un'altra unità») apre una finestra con tutte, una per riga, con gli stessi badge. Niente scorrimento nella card.
 * - Le due card di destra hanno l'intestazione delle card di sinistra.
 *
 * Che cosa NON copre: le altezze vere e il tema scuro, che si guardano a video; la card «Lettere» nei suoi casi, in
 * `EstrattoContoAnagraficaCorrispondenza.test.ts`.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { shallowMount, type VueWrapper } from '@vue/test-utils';
import { Dialog } from '@/components/ui/dialog';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ url: '/admin/gestionale/28/anagrafiche/7/estratto-conto', props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;

const EstrattoContoAnagrafica = (await import('./EstrattoContoAnagrafica.vue')).default;

const unita = (n: number) => Array.from({ length: n }, (_, i) => ({
    id: 100 + i, nome: `Appartamento con un nome molto lungo numero ${i + 1}`, interno: String(i + 1), piano: i === 0 ? '2' : null,
    pivot: { tipologia: i === 4 ? 'usufruttuario' : 'proprietario', quota: i === 4 ? '50.00' : (i === 3 ? '33.33' : '100.00') },
}));

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; });

function monta(immobili: unknown[]) {
    w = shallowMount(EstrattoContoAnagrafica, {
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' } as never,
            esercizio: { id: 5, nome: 'Esercizio 2026' } as never,
            anagrafica: { id: 7, nome: 'Simona Lazzari', codice_fiscale: null, immobili } as never,
            timeline: [],
            stats: {
                totale_addebiti: '€ 0,00', totale_versamenti: '€ 0,00', saldo_finale: '€ 0,00', saldo_raw: 0, saldo_iniziale: '€ 0,00', saldo_iniziale_raw: 0,
                credito_disponibile: '€ 0,00', credito_disponibile_raw: 0, credito_per_gestione: [], compensabile: '€ 0,00', compensabile_raw: 0, compensabile_frase: '',
                compensabile_rata_id: null,
            },
            rimborso: { quote: [], casse: [], credito_in_saldi: 0 },
        },
        global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
    });

    return w;
}

const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const laFinestra = (p: VueWrapper) => p.findAllComponents(Dialog).find((d) => d.find('[data-testid="finestra-unita"]').exists())!;

describe('la colonna di destra', () => {
    test('non entra nell\'altezza della fila: il posto è `lg:relative`, la colonna `lg:absolute lg:inset-0`; sotto `lg` resta nel flusso', () => {
        const pagina = monta(unita(5));
        const posto = pagina.get('[data-testid="colonna-destra"]');
        expect(posto.classes()).toEqual(expect.arrayContaining(['lg:col-span-4', 'lg:relative']));
        expect(posto.element.previousElementSibling?.getAttribute('data-testid')).toBe('le-card');
        const colonna = posto.element.firstElementChild as HTMLElement;
        expect([...colonna.classList]).toEqual(expect.arrayContaining(['lg:absolute', 'lg:inset-0', 'flex', 'flex-col', 'gap-4']));
        expect([...colonna.classList].filter((c) => /^(absolute|inset-0)$/.test(c))).toEqual([]);
        // Le due card: «Unità immobiliari», poi «Lettere».
        expect([...colonna.children].map((c) => c.getAttribute('data-testid'))).toEqual(['card-unita', 'ultima-lettera-in-testa']);
    });

    test('le due card di destra hanno l\'intestazione delle card di sinistra', () => {
        const pagina = monta(unita(1));
        const aSinistra = pagina.get('[data-testid="le-card"] h3').classes();
        for (const card of ['card-unita', 'ultima-lettera-in-testa']) {
            expect(pagina.get(`[data-testid="${card}"] h3`).classes()).toEqual(expect.arrayContaining(aSinistra));
        }
    });
});

describe('«Unità immobiliari»', () => {
    test('con cinque unità: le prime due, una riga ciascuna con il nome che si tronca e il nome intero nel `title`; il numero in testa resta', () => {
        const pagina = monta(unita(5));
        const card = pagina.get('[data-testid="card-unita"]');
        expect(pulito(card.get('h3').text())).toBe('Unità immobiliari 5');
        const righe = card.findAll('[data-testid="unita-della-persona"] [data-testid="unita"]');
        expect(righe).toHaveLength(2);
        const nome = righe[0].get('[data-testid="nome-unita"]');
        expect(nome.classes()).toContain('truncate');
        expect(nome.attributes('title')).toBe('Int. 1 - P. 2');
        expect(nome.text()).toBe('Int. 1 - P. 2');
        // La quota all'italiana: la virgola, i decimali solo se servono, lo spazio che non va a capo prima di «%».
        expect(righe[0].findAll('badge-stub').map((b) => b.text())).toEqual(['proprietario', '100\u00a0%']);
        expect(righe[0].classes()).toEqual(expect.arrayContaining(['flex', 'items-center']));
    });

    test('«e altre 3 unità» apre la finestra con tutte e cinque, una per riga, con gli stessi badge', async () => {
        const pagina = monta(unita(5));
        const altre = pagina.get('[data-testid="altre-unita"]');
        expect(pulito(altre.text())).toBe('e altre 3 unità');
        expect(laFinestra(pagina).props('open')).toBe(false);
        await altre.trigger('click');
        expect(laFinestra(pagina).props('open')).toBe(true);
        const righe = pagina.findAll('[data-testid="finestra-unita"] [data-testid="unita"]');
        expect(righe).toHaveLength(5);
        expect(righe[4].findAll('badge-stub').map((b) => b.text())).toEqual(['usufruttuario', '50\u00a0%']);
        expect(righe[3].findAll('badge-stub').map((b) => b.text())).toEqual(['proprietario', '33,33\u00a0%']);
        expect(righe.map((r) => r.get('[data-testid="nome-unita"]').text())).toEqual(['Int. 1 - P. 2', 'Int. 2', 'Int. 3', 'Int. 4', 'Int. 5']);
    });

    test('con due unità nessun collegamento; con tre «e un\'altra unità»; con una, una riga', () => {
        expect(monta(unita(2)).find('[data-testid="altre-unita"]').exists()).toBe(false);
        expect(w!.findAll('[data-testid="unita-della-persona"] [data-testid="unita"]')).toHaveLength(2);
        w!.unmount();
        expect(pulito(monta(unita(3)).get('[data-testid="altre-unita"]').text())).toBe('e un\'altra unità');
        w!.unmount();
        expect(monta(unita(1)).findAll('[data-testid="unita-della-persona"] [data-testid="unita"]')).toHaveLength(1);
        expect(w!.find('[data-testid="altre-unita"]').exists()).toBe(false);
    });

    test('niente scorrimento dentro la card, né altezze imposte alla lista', () => {
        const card = monta(unita(5)).get('[data-testid="card-unita"]');
        const classi = [card.element, ...card.element.querySelectorAll('*')].flatMap((e) => [...e.classList]);
        expect(classi.filter((c) => /^(lg:)?(overflow-y-auto|overflow-auto|overflow-y-scroll|h-0|max-h-.*|min-h-\[.*)$/.test(c))).toEqual([]);
    });

    test('senza unità la card lo dice', () => {
        expect(monta([]).get('[data-testid="card-unita"]').text()).toContain('Nessuna unità associata.');
        expect(w!.find('[data-testid="altre-unita"]').exists()).toBe(false);
    });
});

describe('lo spazio fra le card e la colonna', () => {
    test('sotto `lg` la colonna sta sotto le card con lo stesso spazio che c\'è fra le card; da `lg` in su lo spazio di prima fra le due colonne', () => {
        const griglia = monta(unita(1)).get('[data-testid="colonna-destra"]').element.parentElement!;
        const classi = [...griglia.classList];
        expect(classi).toEqual(expect.arrayContaining(['gap-4', 'lg:gap-6']));
        expect(classi).not.toContain('gap-6');
        expect(w!.get('[data-testid="le-card"]').classes()).toContain('gap-4');
    });
});

describe('il tema scuro della legenda e della tabella', () => {
    test('la legenda, l\'intestazione, la colonna «Saldo» e le righe hanno le loro classi `dark:`', () => {
        const riga = { id: 1, data: '10/07/2026', data_registrazione: null, protocollo: 'INC-1', descrizione: 'Incasso', gestione: 'Ordinaria', dettagli: [{ type: 'rata', text: 'Rata n.1', status: 'pagata' }],
            note: null, tipo_icona: 'payment', dare: 0, avere: 50000, saldo: -100, breakdown: null, scrittura_id: 1, tipo_movimento: 'incasso_rata', stato_scrittura: 'registrata',
            stornabile: false, storna_annullata: false };
        const base = { ...(monta(unita(1)).props() as Record<string, unknown>) };
        w!.unmount();
        w = shallowMount(EstrattoContoAnagrafica, {
            props: { ...base, timeline: [riga] },
            global: { renderStubDefaultSlot: true, mocks: { route: (name: string) => `/${name}` } },
        });
        const classiDi = (sel: string) => [...w!.get(sel).element.classList];
        expect(classiDi('[data-testid="legenda"]')).toEqual(expect.arrayContaining(['dark:bg-slate-900/60', 'dark:border-slate-800', 'dark:text-slate-400']));
        expect(classiDi('[data-testid="tabella-movimenti"]')).toEqual(expect.arrayContaining(['dark:border-slate-800']));
        expect(classiDi('[data-testid="tabella-movimenti"] thead')).toEqual(expect.arrayContaining(['dark:bg-slate-900/60', 'dark:text-slate-400', 'dark:border-slate-800']));
        expect(classiDi('[data-testid="tabella-movimenti"] thead th:last-child')).toEqual(expect.arrayContaining(['dark:bg-slate-900', 'dark:text-slate-200']));
        expect(classiDi('[data-testid="tabella-movimenti"] tbody')).toEqual(expect.arrayContaining(['dark:divide-slate-800']));
        const ultimaCella = w!.findAll('[data-testid="tabella-movimenti"] tbody tr').at(-1)!.findAll('td').at(-1)!;
        expect(ultimaCella.classes()).toEqual(expect.arrayContaining(['dark:bg-slate-900/40', 'dark:border-slate-800']));
        // Il tema chiaro resta com'era.
        expect(classiDi('[data-testid="legenda"]')).toEqual(expect.arrayContaining(['bg-gray-50/80', 'border-gray-200']));
        expect(ultimaCella.classes()).toEqual(expect.arrayContaining(['bg-gray-50/30', 'border-gray-100']));
        // Nessuna classe chiara di sfondo o di testo grigio senza la sua compagna scura, nella legenda e nella tabella.
        for (const sel of ['[data-testid="legenda"]', '[data-testid="tabella-movimenti"]']) {
            const el = w!.get(sel).element;
            const tutte = [el, ...el.querySelectorAll('*')];
            const scoperte = tutte.filter((e) => [...e.classList].some((c) => /^(bg-gray-|text-gray-|border-gray-|divide-gray-)/.test(c))
                && ![...e.classList].some((c) => c.startsWith('dark:')));
            expect(scoperte.map((e) => e.outerHTML.slice(0, 120))).toEqual([]);
        }
    });
});
