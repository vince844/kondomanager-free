// @vitest-environment jsdom

/**
 * Cosa resta scoperto: il server (la porta e i campi che manda sono in `Beta53SaldoLegatoPortaTest.php`, `Beta53SaldoLegatoWalletTest.php` e
 * `Beta53SaldoLegatoCasiInPiuTest.php`); le misure a video (jsdom non calcola dove il menu si apre né che cosa copre); il tema scuro e i 375 px.
 *
 * Il modale «Aggiungi credito» / «Aggiungi debito» con «Legato a un passaggio», nei casi che `SaldiDetailPanelLegata.test.ts` non costruisce.
 *
 * Il menu delle persone non sta dentro un contenitore che taglia o fa scorrere ciò che esce (`overflow` diverso da visibile, su un asse o su
 * tutti e due): un menu dentro il contenuto che scorre si vedrebbe tagliato al fondo del modale. Acceso l'interruttore, la persona che il modo
 * di sempre aveva già scelto (l'unica senza saldo) si azzera: niente è già scelto. Con la persona e senza passaggio «Salva» resta spento e il
 * clic non manda niente. Tornati a «Intero immobile» il POST non porta il passaggio. Senza passaggi sull'unità il riquadro di chi ha già un
 * saldo non indica «Legato a un passaggio», che lì non c'è. Una riga non legata non ha il segno «Passaggio».
 *
 * I campi che decide il server si scrivono a mano con la forma che `Beta53SaldoLegatoWalletTest.php` prova che il server manda:
 * `passaggi_legabili` (id, parole, persone, con_parte_a_mano) per unità, `e_legata` e `passaggio_in_parole` per riga, e `passaggio_id`.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn(), patch: vi.fn() },
}));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const SaldiDetailPanel = (await import('./SaldiDetailPanel.vue')).default;

const GESTIONE = { id: 7, nome: 'Ordinaria 2026', tipo: 'ordinaria', saldo_applicato: true };
const INES = 10, UGO = 11, ELSA = 12, LUCA = 13;
const VENDITA = 2;
const SOGGETTI = [
    { id: INES, nome: 'Ines', cognome: '', pivot: { id: 100, tipologia: 'inquilino', data_fine: '2026-06-30' } },
    { id: UGO, nome: 'Ugo', cognome: '', pivot: { id: 101, tipologia: 'proprietario', data_fine: '2026-10-31' } },
    { id: ELSA, nome: 'Elsa', cognome: '', pivot: { id: 102, tipologia: 'proprietario', data_fine: null } },
    { id: LUCA, nome: 'Luca', cognome: '', pivot: { id: 103, tipologia: 'inquilino', data_fine: null } },
];
const LEGABILI = [{ id: VENDITA, parole: 'la vendita del 1 novembre 2026', persone: ['Ugo', 'Elsa'], con_parte_a_mano: true }];

/** Una riga di saldo come arriva dal payload: manuale, libera, non legata, della persona data. */
const riga = (id: number, persona: number, nome: string, centesimi: number) => ({
    id, saldo_iniziale: centesimi, is_applicato: false, e_bloccato: false, origine: 'manuale', gestione_id: GESTIONE.id, anagrafica_id: persona,
    piano_rate_id: null, piano_rate: null, gestione: GESTIONE, anagrafica: { id: persona, nome, cognome: '' }, subentro_id: null,
    passaggio_id: null, e_legata: false, passaggio_in_parole: null,
});

let montati: VueWrapper[] = [];
afterEach(() => {
    montati.forEach((w) => w.unmount());
    montati = [];
    document.body.innerHTML = '';
});

function monta(saldi: unknown[], legabili: unknown[] = LEGABILI): VueWrapper {
    const w = mount(SaldiDetailPanel as never, {
        attachTo: document.body,
        props: {
            immobile: { id: 1, nome: 'Interno 1', interno: '1', anagrafiche: SOGGETTI, saldi, passaggi_legabili: legabili },
            gestioni: [GESTIONE], esercizio: { id: 41, nome: '2026' }, condominio: { id: 28, nome: 'Condominio Test' }, valoriInCentesimi: true,
        } as never,
        global: { stubs: { transition: false } },
    }) as VueWrapper;
    montati.push(w);

    return w;
}

type Modulo = { targetType: string; importo: string; anagraficaId: number | null; legato?: boolean; passaggioId?: number | null };
const modulo = (w: VueWrapper) => (w.vm as unknown as { modalForm: Modulo }).modalForm;
const testo = (w: VueWrapper) => w.text().replace(/\s+/g, ' ');

/** Apre la gestione, «Aggiungi credito» e sceglie «Soggetto specifico». */
async function apri(saldi: unknown[], legabili: unknown[] = LEGABILI): Promise<VueWrapper> {
    const w = monta(saldi, legabili);
    await w.findAll('button').find((b) => b.text().includes('Ordinaria 2026'))!.trigger('click');
    await w.vm.$nextTick();
    await w.findAll('button').find((b) => b.text().includes('Aggiungi credito'))!.trigger('click');
    await w.vm.$nextTick();
    await w.findAll('input[type="radio"]').find((r) => r.attributes('value') === 'personale')!.setValue(true);
    await w.vm.$nextTick();

    return w;
}

/** Accende «Legato a un passaggio»: la casella dentro la label che ne porta il nome. */
async function accendi(w: VueWrapper): Promise<void> {
    const label = w.findAll('label').filter((e) => e.text().replace(/\s+/g, ' ').includes('Legato a un passaggio')).pop();
    expect(label, 'Nessun interruttore «Legato a un passaggio» nel modale').toBeDefined();
    await label!.find('input[type="checkbox"]').setValue(true);
    await w.vm.$nextTick();
}

/** Il selettore delle persone. */
const selettorePersone = (w: VueWrapper) =>
    w.findAllComponents(vSelect).find((c) => ((c.props('options') as Array<Record<string, unknown>>) ?? []).some((o) => 'nome' in o && !('parole' in o)));

/** Gli antenati che tagliano o fanno scorrere ciò che esce (`overflow` hidden, clip, auto o scroll, anche su un solo asse), fino al corpo della pagina. */
function cheTaglianoOScorrono(e: Element): string[] {
    const out: string[] = [];
    for (let p = e.parentElement; p !== null && p !== document.body; p = p.parentElement) {
        if (Array.from(p.classList).some((c) => /^overflow(-[xy])?-(hidden|clip|auto|scroll)$/.test(c))) {
            out.push(`<${p.tagName.toLowerCase()} class="${p.className}">`);
        }
    }

    return out;
}

describe('«Legato a un passaggio»: i casi in più', () => {
    test('il menu delle persone, aperto, non sta dentro un contenitore che lo taglia o lo fa scorrere', async () => {
        const w = await apri([]);
        const ricerca = selettorePersone(w)!.find('.vs__search');
        await ricerca.trigger('focus');
        await ricerca.trigger('mousedown');
        await w.vm.$nextTick();

        const menu = document.body.querySelector('.vs__dropdown-menu');

        expect(menu, 'Il menu delle persone non si è aperto').not.toBeNull();
        expect(menu!.querySelectorAll('li').length).toBe(4);
        expect(cheTaglianoOScorrono(menu!)).toEqual([]);
    });

    test('acceso l\'interruttore, la persona che il modo di sempre aveva già scelto si azzera', async () => {
        // Ugo, Elsa e Luca hanno un saldo: nel modo di sempre l'unica persona libera, Ines, è già scelta.
        const w = await apri([riga(5, UGO, 'Ugo', 12345), riga(6, ELSA, 'Elsa', 7000), riga(7, LUCA, 'Luca', 300)]);
        expect(modulo(w).anagraficaId).toBe(INES);

        await accendi(w);

        expect(modulo(w).legato).toBe(true);
        expect(modulo(w).anagraficaId).toBeNull();
        expect(modulo(w).passaggioId ?? null).toBeNull();
    });

    test('acceso, con la persona scelta e senza passaggio «Salva» resta spento e il clic non manda niente', async () => {
        const w = await apri([]);
        await accendi(w);
        selettorePersone(w)!.vm.$emit('update:modelValue', UGO);
        modulo(w).importo = '60,16';
        await w.vm.$nextTick();
        vi.mocked(router.post).mockClear();

        const salva = w.findAll('button').find((b) => b.text().includes('Salva Credito'))!;
        await salva.trigger('click');

        expect(modulo(w).anagraficaId).toBe(UGO);
        expect(salva.attributes('disabled')).toBeDefined();
        expect(router.post).not.toHaveBeenCalled();
    });

    test('acceso e poi «Intero immobile»: il POST non porta il passaggio', async () => {
        const w = await apri([]);
        await accendi(w);
        await w.find('select').setValue(String(VENDITA));
        selettorePersone(w)!.vm.$emit('update:modelValue', UGO);
        await w.vm.$nextTick();
        await w.findAll('input[type="radio"]').find((r) => r.attributes('value') === 'solidale')!.setValue(true);
        modulo(w).importo = '60,16';
        await w.vm.$nextTick();
        vi.mocked(router.post).mockClear();

        await w.findAll('button').find((b) => b.text().includes('Salva Credito'))!.trigger('click');

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({ anagrafica_id: null, immobile_id: 1, gestione_id: 7, saldo_iniziale: -6016 });
    });

    test('senza passaggi sull\'unità, il riquadro di chi ha già un saldo non indica «Legato a un passaggio»', async () => {
        const w = await apri([riga(5, INES, 'Ines', 100), riga(6, UGO, 'Ugo', 12345), riga(7, ELSA, 'Elsa', 7000), riga(8, LUCA, 'Luca', 300)], []);

        expect(testo(w)).toContain('Tutti i soggetti associati hanno già un saldo per questa gestione.');
        expect(testo(w)).toContain('Su questa unità non ci sono passaggi a cui legare un saldo.');
        expect(testo(w)).not.toContain('attiva «Legato a un passaggio»');
    });
});

describe('le righe del Wallet', () => {
    test('una riga non legata non ha il segno «Passaggio»', async () => {
        const w = monta([riga(5, UGO, 'Ugo', 12345)]);
        await w.findAll('button').find((b) => b.text().includes('Ordinaria 2026'))!.trigger('click');
        await w.vm.$nextTick();

        expect(testo(w)).toContain('Ugo');
        expect(w.findAll('[title]').filter((e) => (e.attributes('title') ?? '').startsWith('Legato al passaggio:'))).toHaveLength(0);
    });
});
