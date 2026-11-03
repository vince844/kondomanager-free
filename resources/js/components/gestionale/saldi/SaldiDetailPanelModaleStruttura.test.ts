// @vitest-environment jsdom

/**
 * Cosa resta scoperto: le misure a video (jsdom non calcola altezze né sovrapposizioni: si fissa la struttura che le produce); il tema scuro;
 * le larghezze del modale (1440 e 620 px) a cui i due difetti si vedevano.
 *
 * La struttura del modale «Aggiungi credito» / «Aggiungi debito», che a video aveva due difetti.
 *
 * Primo: la frase «Questa gestione ha già un saldo per l'intero immobile: modifica quello esistente.» stava nella cella della prima scelta, dopo
 * il riquadro che occupa tutta l'altezza della cella (`h-full`): sporgeva sotto la riga della griglia, e la copriva il campo delle persone (a
 * 1440 px) o la seconda scelta (a 620 px). La frase sta in una riga sua della griglia, o dentro il riquadro: nella cella che la contiene, ogni
 * elemento `h-full` la contiene.
 *
 * Secondo: il menu di «Soggetto specifico» con quattro persone sporgeva di 24 px dal fondo del modale, che tagliava ciò che usciva
 * (`overflow-hidden`): l'ultima persona era tagliata e il menu copriva «Annulla» e «Salva Credito». Nessun contenitore del menu aperto taglia
 * ciò che esce: il contenuto del modale scorre, o il menu sta fuori dal modale. Vale anche con «Legato a un passaggio» acceso, che allunga
 * lo stesso modale.
 *
 * `passaggi_legabili` ha la forma che `Beta53SaldoLegatoWalletTest.php` prova che il server manda.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import vSelect from 'vue-select';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn(), patch: vi.fn() },
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const SaldiDetailPanel = (await import('./SaldiDetailPanel.vue')).default;

const GESTIONE = { id: 7, nome: 'Ordinaria 2026', tipo: 'ordinaria', saldo_applicato: true };
const QUATTRO = [
    { id: 10, nome: 'Ines', cognome: '', pivot: { id: 100, tipologia: 'inquilino', data_fine: '2026-01-23' } },
    { id: 13, nome: 'Luca', cognome: '', pivot: { id: 103, tipologia: 'inquilino', data_fine: '2026-04-17' } },
    { id: 11, nome: 'Ugo', cognome: '', pivot: { id: 101, tipologia: 'proprietario', data_fine: '2026-03-11' } },
    { id: 12, nome: 'Elsa', cognome: '', pivot: { id: 102, tipologia: 'proprietario', data_fine: null } },
];
const LEGABILI = [{ id: 2, parole: 'la vendita del 12 marzo 2026', persone: ['Ugo', 'Elsa'], con_parte_a_mano: true }];
const FRASE = 'Questa gestione ha già un saldo per l\'intero immobile: modifica quello esistente.';

/** Un saldo per l'intero immobile (solidale) sulla gestione: con lui la prima scelta è spenta e la frase compare. */
const solidale = { id: 1, saldo_iniziale: 5000, is_applicato: false, e_bloccato: false, origine: 'manuale', gestione_id: GESTIONE.id, anagrafica_id: null,
    piano_rate_id: null, piano_rate: null, gestione: GESTIONE, anagrafica: null, subentro_id: null, passaggio_id: null, e_legata: false, passaggio_in_parole: null };

let montati: VueWrapper[] = [];
afterEach(() => {
    montati.forEach((w) => w.unmount());
    montati = [];
    document.body.innerHTML = '';
});

async function apri(saldi: unknown[]): Promise<VueWrapper> {
    const w = mount(SaldiDetailPanel as never, {
        attachTo: document.body,
        props: {
            immobile: { id: 1, nome: 'Interno 1', interno: '1', anagrafiche: QUATTRO, saldi, passaggi_legabili: LEGABILI },
            gestioni: [GESTIONE], esercizio: { id: 41, nome: '2026' }, condominio: { id: 28, nome: 'Condominio Test' }, valoriInCentesimi: true,
        } as never,
        global: { stubs: { transition: false } },
    }) as VueWrapper;
    montati.push(w);
    await w.findAll('button').find((b) => b.text().includes('Ordinaria 2026'))!.trigger('click');
    await w.vm.$nextTick();
    await w.findAll('button').find((b) => b.text().includes('Aggiungi credito'))!.trigger('click');
    await w.vm.$nextTick();

    return w;
}

/** L'elemento più interno che porta la frase. */
function laFrase(): Element | undefined {
    const tutti = Array.from(document.body.querySelectorAll('*')).filter((e) => (e.textContent ?? '').replace(/\s+/g, ' ').includes(FRASE));

    return tutti[tutti.length - 1];
}

/** La cella della griglia delle due scelte che contiene l'elemento: il suo antenato figlio diretto di un elemento `grid`. */
function cellaDellaGriglia(e: Element): Element | null {
    let figlio: Element = e;
    for (let p = e.parentElement; p !== null; figlio = p, p = p.parentElement) {
        if (p.classList.contains('grid')) {
            return figlio;
        }
    }

    return null;
}

/** Gli antenati di un elemento che tagliano ciò che esce (`overflow-hidden`, anche su un solo asse), fino al corpo della pagina. */
function cheTagliano(e: Element): string[] {
    const out: string[] = [];
    for (let p = e.parentElement; p !== null && p !== document.body; p = p.parentElement) {
        if (Array.from(p.classList).some((c) => /^overflow(-[xy])?-hidden$/.test(c))) {
            out.push(`<${p.tagName.toLowerCase()} class="${p.className}">`);
        }
    }

    return out;
}

/** Apre il menu del selettore delle persone (il primo che offre persone) e lo restituisce. */
async function apriIlMenu(w: VueWrapper): Promise<Element | null> {
    const sel = w.findAllComponents(vSelect).find((c) => ((c.props('options') as Array<Record<string, unknown>>) ?? []).some((o) => 'nome' in o && !('parole' in o)));
    expect(sel, 'Nessun selettore delle persone').toBeDefined();
    const ricerca = sel!.find('.vs__search');
    await ricerca.trigger('focus');
    await ricerca.trigger('mousedown');
    await w.vm.$nextTick();

    return document.body.querySelector('.vs__dropdown-menu');
}

describe('la frase del saldo per l\'intero immobile non sporge dalla sua riga della griglia', () => {
    test('nella cella che la contiene ogni elemento a tutta altezza (h-full) la contiene', async () => {
        await apri([solidale]);
        const frase = laFrase();
        expect(frase, 'La frase non c\'è').toBeDefined();
        const cella = cellaDellaGriglia(frase!);
        expect(cella, 'La frase non sta nella griglia delle scelte').not.toBeNull();

        const fuori = Array.from(cella!.querySelectorAll('.h-full')).concat(cella!.classList.contains('h-full') ? [cella!] : []).filter((h) => !h.contains(frase!));

        expect(fuori.map((h) => `<${h.tagName.toLowerCase()} class="${h.className}">`)).toEqual([]);
    });

    test('controprova — il testo della frase non cambia', async () => {
        await apri([solidale]);

        expect(document.body.textContent?.replace(/\s+/g, ' ')).toContain(FRASE);
    });
});

describe('il menu di «Soggetto specifico» non è tagliato dal modale', () => {
    test('con quattro persone, nessun contenitore del menu aperto ha overflow-hidden', async () => {
        const w = await apri([]);
        await w.findAll('input[type="radio"]').find((r) => r.attributes('value') === 'personale')!.setValue(true);
        await w.vm.$nextTick();

        const menu = await apriIlMenu(w);

        expect(menu, 'Il menu delle persone non si è aperto').not.toBeNull();
        expect(menu!.querySelectorAll('li').length).toBeGreaterThanOrEqual(4);
        expect(cheTagliano(menu!)).toEqual([]);
    });

    test('anche con «Legato a un passaggio» acceso, nessun contenitore del menu delle persone ha overflow-hidden', async () => {
        const w = await apri([]);
        await w.findAll('input[type="radio"]').find((r) => r.attributes('value') === 'personale')!.setValue(true);
        await w.vm.$nextTick();
        const candidati = w.findAll('label, button, [role="switch"]').filter((e) => e.text().replace(/\s+/g, ' ').includes('Legato a un passaggio'));
        expect(candidati.length, 'Nessun interruttore «Legato a un passaggio»').toBeGreaterThan(0);
        const i = candidati[candidati.length - 1];
        const casella = i.find('input[type="checkbox"]');
        if (casella.exists()) {
            await casella.setValue(true);
        } else {
            await i.trigger('click');
        }
        await w.vm.$nextTick();

        const menu = await apriIlMenu(w);

        expect(menu, 'Il menu delle persone non si è aperto').not.toBeNull();
        expect(cheTagliano(menu!)).toEqual([]);
    });
});
