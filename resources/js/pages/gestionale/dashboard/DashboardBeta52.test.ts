// @vitest-environment jsdom

/**
 * Cosa resta scoperto: i widget del cruscotto (tesoreria, controlli dopo l'importazione, crediti da compensare), che non leggono la copertura; il
 * colore, la larghezza a 375 px e il tema scuro (si guardano a video); lo stato «Disallineato» e l'elenco dei piani da ricalcolare (li decide il
 * server: i test Pest del cruscotto); la finestra delle voci non coperte.
 *
 * Il riquadro della copertura nel cruscotto, con le voci di entrata.
 *
 * Il riquadro in alto a sinistra si chiamava «Fabbisogno Condiviso» e leggeva `copertura.preventivo`; la barra e la percentuale dividevano per lo
 * stesso numero. Con un'entrata quel numero somma l'entrata come una spesa. Ora la copertura porta `spese`, `entrate` e `da_ripartire` (centesimi):
 * con almeno un'entrata il riquadro si chiama «Da ripartire» e mostra il netto, e il suggerimento dice al netto di quali entrate; senza entrate
 * resta «Fabbisogno Condiviso» con la stessa cifra e lo stesso suggerimento di prima. La barra e la percentuale dividono per il netto: con un
 * netto che non è maggiore di zero (la sola entrata) non c'è niente da coprire, e la percentuale non si calcola: è 100,0 % (barra piena) se il
 * piano non chiede più del netto, cioè il delta non è positivo, e 0,0 % (barra vuota) se ne chiede meno. Un punto che resta sulla chiave tolta
 * mostrerebbe «NaN %» senza che nessun altro test se ne accorga: per questo si monta la pagina vera. La percentuale si legge dal suo riquadro e si
 * confronta per intero: «0.0%» non deve passare per «100.0%», né «50.0%» per «150.0%».
 *
 * Le frasi che dicono come si ottiene il netto («da ripartire … (spese …, entrate …)») si firmano con la clausola del motore, letta alla lettera:
 * `in_array($conto->tipo, ['spesa', 'uscita']) ? abs($importo) : -abs($importo)` (`CalcoloQuoteService.php:1500-1502` e `:1586-1589`): le voci di
 * un tipo diverso da spesa e uscita si sottraggono.
 *
 * La pagina è quella vera. Si stubbano la cornice, la guida, i widget e i suggerimenti a comparsa (che con lo stub mostrano sempre il loro testo,
 * così si legge senza passare dal passaggio del mouse).
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    InfiniteScroll: { template: '<div><slot /></div>' },
    router: { post: vi.fn(), get: vi.fn(), patch: vi.fn(), reload: vi.fn() },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Dashboard = (await import('@/pages/gestionale/dashboard/Dashboard.vue')).default;

let pagina: VueWrapper | null = null;

const copertura = (parti: Record<string, unknown>) => ({
    virtuale: 0, addebiti_personali: 0, uscite_totali: 0, scoperto: 0, is_completo: true, has_sforo: false, orfani: [], scoperto_count: 0, ...parti,
});

async function monta(c: Record<string, unknown>) {
    pagina = mount(Dashboard, {
        attachTo: document.body,
        props: { condominio: { id: 1, nome: 'Condominio Demo' } as never, condomini: [], esercizio: { id: 1, nome: '2026' } as never, esercizi: [], copertura: copertura(c) as never, pianiDisallineati: [], fattureScoperte: [], inboxTasks: { total: 0, data: [] }, widgets: {} },
        global: {
            stubs: {
                GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, TreasuryGuardianWidget: true, ControlliPostImportWidget: true, CreditiDaCompensareWidget: true,
                TooltipProvider: { template: '<div><slot /></div>' }, Tooltip: { template: '<div><slot /></div>' }, TooltipTrigger: { template: '<div><slot /></div>' }, TooltipContent: { template: '<div><slot /></div>' },
                Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' },
            },
            mocks: { route: (n: string) => `/${n}` },
        },
    });
    await flushPromises();
}

const testo = () => (document.body.textContent ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
const normalizza = (t: string | null | undefined) => (t ?? '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();

/** Il riquadro in alto a sinistra: l'etichetta, la cifra grande e il suggerimento (quello che si legge passando il mouse sull'icona). */
function riquadro() {
    const etichetta = [...document.body.querySelectorAll('p')].find((p) => ['Da ripartire', 'Fabbisogno Condiviso'].includes(normalizza(p.textContent)));
    expect(etichetta, 'il riquadro non c\'è').toBeDefined();
    const scheda = etichetta!.closest('div.flex-col.justify-center') as HTMLElement;

    return {
        etichetta: normalizza(etichetta!.textContent),
        valore: normalizza(scheda.querySelector('p.text-base')?.textContent),
        suggerimento: normalizza(scheda.querySelector('p.text-xs')?.textContent),
    };
}

const larghezzaBarra = () => (document.body.querySelector('div.h-full.transition-all') as HTMLElement | null)?.getAttribute('style') ?? '';

/** La percentuale di avanzamento, letta per intero dal suo riquadro («100.0%», e non un pezzo del testo della pagina). */
function percentuale(): string {
    const etichetta = [...document.body.querySelectorAll('span')].find((s) => normalizza(s.textContent) === 'Avanzamento Coperture Condivise');
    expect(etichetta, 'il riquadro dell\'avanzamento non c\'è').toBeDefined();

    return normalizza(etichetta!.nextElementSibling?.textContent);
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il riquadro in alto a sinistra', () => {
    test('con un\'entrata si chiama «Da ripartire», mostra € 960,00 e il suggerimento dice al netto di quali entrate', async () => {
        await monta({ spese: 120000, entrate: 24000, da_ripartire: 96000, pianificato: 96000, delta: 0, percentuale: 100 });

        expect(riquadro()).toEqual({
            etichetta: 'Da ripartire', valore: '€ 960,00',
            suggerimento: 'Fabbisogno reale condominiale (preventivo + sfori autorizzati), al netto delle entrate di € 240,00. Esclude categoricamente le spese ad personam.',
        });
        expect(testo()).not.toContain('Fabbisogno Condiviso');
    });

    test('controprova: senza entrate resta «Fabbisogno Condiviso» con la cifra di sempre e il suggerimento di sempre (la risposta porta anche la chiave di prima, come un server non ancora aggiornato)', async () => {
        await monta({ preventivo: 120000, spese: 120000, entrate: 0, da_ripartire: 120000, pianificato: 120000, delta: 0, percentuale: 100 });

        expect(riquadro()).toEqual({
            etichetta: 'Fabbisogno Condiviso', valore: '€ 1.200,00',
            suggerimento: 'Fabbisogno reale condominiale (preventivo + sfori autorizzati). Esclude categoricamente le spese ad personam.',
        });
        expect(testo()).not.toContain('Da ripartire');
    });

    // Cambiata l\'attesa del test (prima «0.0%» e barra vuota): un piano di sola entrata generato per intero è «allineato», e il riquadro accanto dice che
    // il piano copre esattamente il preventivo. Una percentuale di 0,0 % con la barra vuota nello stesso riquadro si contraddiceva; non c\'è niente da
    // coprire e il piano non chiede più del netto, quindi la copertura è completa.
    test('con la sola entrata il netto è negativo: € -240,00, nessuna percentuale da calcolare, e un piano che copre il netto dice 100,0 % con la barra piena', async () => {
        await monta({ spese: 0, entrate: 24000, da_ripartire: -24000, pianificato: -24000, delta: 0, percentuale: 0 });

        expect(riquadro()).toMatchObject({ etichetta: 'Da ripartire', valore: '€ -240,00' });
        expect(percentuale()).toBe('100.0%');
        expect(testo()).not.toContain('NaN');
        expect(larghezzaBarra()).toContain('width: 100%');
        expect(testo()).toContain('Il piano rate copre esattamente il preventivo di spesa originale.');
    });

    test('con la sola entrata fuori dal piano (il piano chiede zero, più del netto di −240,00): ancora 100,0 %, perché non c\'è niente da coprire', async () => {
        await monta({ spese: 0, entrate: 24000, da_ripartire: -24000, pianificato: 0, delta: -24000, percentuale: 0 });

        expect(percentuale()).toBe('100.0%');
        expect(larghezzaBarra()).toContain('width: 100%');
    });

    test('con la sola entrata e un piano che accredita più del netto (delta positivo): 0,0 % e barra vuota', async () => {
        await monta({ spese: 0, entrate: 24000, da_ripartire: -24000, pianificato: -48000, delta: 24000, percentuale: 0 });

        expect(percentuale()).toBe('0.0%');
        expect(larghezzaBarra()).toContain('width: 0%');
    });

    test('un netto zero (spese e entrate uguali) con un piano a zero: 100,0 %', async () => {
        await monta({ spese: 24000, entrate: 24000, da_ripartire: 0, pianificato: 0, delta: 0, percentuale: 0 });

        expect(percentuale()).toBe('100.0%');
        expect(larghezzaBarra()).toContain('width: 100%');
    });
});

describe('la barra e la percentuale dividono per il netto', () => {
    test('rate emesse per € 480,00 su € 960,00 da ripartire, con una voce non coperta: 50,0 % e barra a metà, non «NaN %»', async () => {
        await monta({
            spese: 120000, entrate: 24000, da_ripartire: 96000, pianificato: 48000, delta: 48000, percentuale: 50,
            orfani: [{ id: 9, nome: 'Manutenzione ascensore', importo: 48000, strategia: 'nuovo', gestione: 'Gestione ordinaria' }], scoperto_count: 1,
        });

        expect(percentuale()).toBe('50.0%');
        expect(testo()).not.toContain('NaN');
        expect(larghezzaBarra()).toContain('width: 50%');
    });

    test('controprova: senza entrate la stessa copertura dà la stessa percentuale di prima (la risposta porta anche la chiave di prima)', async () => {
        await monta({
            preventivo: 96000, spese: 96000, entrate: 0, da_ripartire: 96000, pianificato: 48000, delta: 48000, percentuale: 50,
            orfani: [{ id: 9, nome: 'Manutenzione ascensore', importo: 48000, strategia: 'nuovo', gestione: 'Gestione ordinaria' }], scoperto_count: 1,
        });

        expect(percentuale()).toBe('50.0%');
        expect(larghezzaBarra()).toContain('width: 50%');
    });
});
