// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * L'avviso della tabella parziale nella finestra del punto (decisione 640 (2)): quando la tabella scelta per il punto non comprende tutte le unità del
 * perimetro, una riga arancio dice quante ne ha e che le altre contano come teste a zero millesimi. In Via Roma 12 il punto «Rifacimento della pavimentazione
 * dei box» con la tabella «Box», 8 unità su 26: «La tabella ha 8 unità su 26: le altre 18 contano come teste a zero millesimi.». Solo l'avviso: il conto
 * della sala non cambia.
 *
 * - Nessun avviso con la tabella che comprende tutte le unità, né con quella dell'assemblea.
 * - Con «Solo la scala B» il perimetro è la scala: l'avviso conta le unità della scala, perché in sala contano solo i suoi condòmini.
 * - Un'unità sola fuori: «l'altra conta come testa a zero millesimi».
 *
 * La tabella di un gruppo (Coda 734, bozza approvata, scene 2 e 3):
 *
 * - senza il segno, sotto la riga arancio la proposta e il pulsante «Segna «Box» come tabella di un gruppo», che manda il segno e rilegge le scelte;
 * - con il segno, niente riga arancio: il riquadro azzurro dice che sul punto votano solo i condòmini delle 8 unità, e la riga «Adesso» il conto fra loro;
 * - nell'ordine del giorno il badge «Fra i condòmini della tabella «Box»».
 *
 * Che cosa non copre: le unità e le quote, che manda il server (`TabellaParzialeDelPuntoTest`, `CondominioParzialeTest`); i menu `v-select` aperti a mano.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { avvisoDellaTabellaParziale } from '@/lib/assemblee/pagine';

vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
const post = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post, patch: vi.fn(), delete: vi.fn(), reload: vi.fn() } }));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options'], template: '<div class="v-select-finto" />' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./FinestraDelPunto.vue')).default;
const Ordine = (await import('./OrdineDelGiorno.vue')).default;
const controllo = (conQuota: number, unita: number, millesimi: string) => ({ frase: `${conQuota} unità su ${unita} · ${millesimi} millesimi`,
    copre: conQuota === unita, esatta: conQuota === unita, con_quota: conQuota, unita });
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [{ valore: 'lavori', nome: 'Lavori' }], maggioranze: [], esiti: [], a_chi: [], unita: 26,
    palazzine: [], scale: [{ id: 2, nome: 'B', palazzina_id: null, unita: 10 }],
    tabelle: [
        { id: 5, nome: 'Box', unita: 8, controlli: { condominio: controllo(8, 26, '1.000,00'), 'scala:2': controllo(3, 10, '375,00') } },
        { id: 6, nome: 'Proprietà', unita: 26, controlli: { condominio: controllo(26, 26, '1.000,00'), 'scala:2': controllo(10, 26, '412,00') } },
        { id: 7, nome: 'Scala B', unita: 10, controlli: { condominio: controllo(10, 26, '1.000,00'), 'scala:2': controllo(10, 10, '1.000,00') } },
    ],
    maggioranze_proposte: {} };
const punto = (extra: Record<string, unknown> = {}) => ({
    id: 9, numero: 5, testo: 'Rifacimento della pavimentazione dei box', testo_pubblico: null, materia: 'lavori', materia_parola: 'Lavori',
    maggioranza: 'meta_del_valore', maggioranza_parola: null, maggioranza_libera: null, avviso: null, amministrazione: 'straordinaria', conduttore: null,
    conduttore_parola: null, ambito: 'condominio', palazzina_id: null, scala_id: null, perimetro_parola: null, tabella_id: 5, tabella_nome: 'Box',
    tabella_controllo: null, esercizio_id: null, importo: null, dati: null, fonte: 'a_mano', chiave_fonte: null, da_memo: false, delibere: [],
    ...extra,
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; post.mockReset(); document.body.innerHTML = ''; });
/** Le scelte con «Box» segnata «di un gruppo» (o no). */
const conIlSegno = (segno: boolean) => ({ ...scelte, tabelle: scelte.tabelle.map((t) => ({ ...t, di_un_gruppo: t.nome === 'Box' && segno })) });
async function apri(p: Record<string, unknown>, segno: boolean) {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, condominioNome: 'Via Roma 12', assembleaId: 7,
        assembleaNome: 'Assemblea ordinaria del 15/12/2026', tabellaDellAssemblea: 'Proprietà', punto: p, scelte: conIlSegno(segno) } as never });
    await flushPromises();
}
const testo = (sel: string) => (document.body.querySelector(sel)?.textContent ?? '').replace(/\s+/g, ' ').trim();
async function avviso(p: Record<string, unknown>): Promise<string | null> {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, condominioNome: 'Via Roma 12', assembleaId: 7,
        assembleaNome: 'Assemblea ordinaria del 15/12/2026', tabellaDellAssemblea: 'Proprietà', punto: p, scelte } as never });
    await flushPromises();
    const riga = document.body.querySelector('[data-testid="avviso-tabella-parziale"]');

    return riga === null ? null : (riga.textContent ?? '').replace(/\s+/g, ' ').trim();
}

describe('l\'avviso della tabella parziale nella finestra del punto', () => {
    test('la tabella «Box» con 8 unità su 26: le altre 18 contano come teste a zero millesimi', async () => {
        expect(await avviso(punto())).toBe('La tabella ha 8 unità su 26: le altre 18 contano come teste a zero millesimi.');
        // La riga arancio, con il segno dell'avviso, come gli altri avvisi della finestra.
        expect(document.body.querySelector('[data-testid="avviso-tabella-parziale"]')!.className).toContain('bg-amber-50');
    });

    test('nessun avviso con la tabella che comprende tutte le unità, né con la tabella dell\'assemblea', async () => {
        expect(await avviso(punto({ tabella_id: 6, tabella_nome: 'Proprietà' }))).toBeNull();
        w!.unmount();
        document.body.innerHTML = '';
        expect(await avviso(punto({ tabella_id: null, tabella_nome: null }))).toBeNull();
    });

    test('con «Solo la scala B» conta le unità della scala: con la sua tabella niente avviso, con «Box» 3 su 10', async () => {
        expect(await avviso(punto({ ambito: 'scala', scala_id: 2, tabella_id: 7, tabella_nome: 'Scala B' }))).toBeNull();
        w!.unmount();
        document.body.innerHTML = '';
        expect(await avviso(punto({ ambito: 'scala', scala_id: 2 }))).toBe('La tabella ha 3 unità su 10: le altre 7 contano come teste a zero millesimi.');
    });

    test('la frase: un\'unità sola fuori è «l\'altra»; niente avviso senza controllo o con tutte le unità', () => {
        expect(avvisoDellaTabellaParziale({ con_quota: 25, unita: 26 })).toBe('La tabella ha 25 unità su 26: l\'altra conta come testa a zero millesimi.');
        expect(avvisoDellaTabellaParziale({ con_quota: 0, unita: 4 })).toBe('La tabella ha 0 unità su 4: le altre 4 contano come teste a zero millesimi.');
        expect(avvisoDellaTabellaParziale({ con_quota: 26, unita: 26 })).toBeNull();
        expect(avvisoDellaTabellaParziale(null)).toBeNull();
    });
});

describe('la tabella di un gruppo nella finestra del punto (Coda 734)', () => {
    test('senza il segno: la riga arancio resta, e sotto la proposta con il pulsante che segna «Box» e rilegge le scelte', async () => {
        await apri(punto({ maggioranza: 'ordinaria', amministrazione: 'ordinaria' }), false);
        expect(testo('[data-testid="avviso-tabella-parziale"]')).toBe('La tabella ha 8 unità su 26: le altre 18 contano come teste a zero millesimi.');
        expect(testo('[data-testid="proposta-del-gruppo"]')).toContain('Se ciò che la tabella «Box» divide è solo dei proprietari di quelle 8 unità, segna «Box» '
            + 'come tabella di un gruppo: sul punto voteranno solo loro, e si conteranno fra loro (art. 1123 c. 3 c.c.).');
        expect(testo('[data-testid="segna-il-gruppo"]')).toBe('Segna «Box» come tabella di un gruppo');
        expect(testo('[data-testid="adesso-punto"]')).toBe('Adesso: il punto è di tutto il condominio, con la tabella «Box».');
        expect(document.body.querySelector('[data-testid="sala-di-una-parte"]')).toBeNull();
        (document.body.querySelector('[data-testid="segna-il-gruppo"]') as HTMLElement).click();
        await flushPromises();
        expect(post).toHaveBeenCalledTimes(1);
        const [url, dati, opzioni] = post.mock.calls[0];
        expect(url).toBe('/gestionale.tabella-di-un-gruppo');
        expect(dati).toEqual({ tabella_id: 5, di_un_gruppo: true });
        expect(opzioni.only).toEqual(['scelte', 'flash']);
        expect(opzioni.preserveState).toBe(true);
    });

    test('con il segno: niente riga arancio né proposta; il riquadro azzurro dice chi vota, e «Adesso» il conto fra le 8 unità', async () => {
        await apri(punto({ maggioranza: 'ordinaria', amministrazione: 'ordinaria' }), true);
        expect(document.body.querySelector('[data-testid="avviso-tabella-parziale"]')).toBeNull();
        expect(document.body.querySelector('[data-testid="proposta-del-gruppo"]')).toBeNull();
        expect(testo('[data-testid="sala-di-una-parte"]')).toContain('In sala, per questo punto, votano solo i condòmini delle 8 unità della tabella «Box».');
        expect(testo('[data-testid="sala-di-una-parte"]')).toContain('Presenze, teste e millesimi si contano fra loro (art. 1123 c. 3 c.c.). La convocazione va '
            + 'comunque a tutti i condòmini di Via Roma 12, con l\'ordine del giorno intero; sotto il punto, l\'avviso dice chi vota.');
        expect(testo('[data-testid="adesso-punto"]')).toBe('Adesso: il punto 5 si vota fra le 8 unità della tabella «Box»; gli altri punti restano di tutto il condominio, '
            + 'con la tabella «Proprietà».');
    });

    test('la proposta non c\'è con la tabella che comprende tutte le unità, né per la scala con la sua tabella', async () => {
        await apri(punto({ tabella_id: 6, tabella_nome: 'Proprietà' }), false);
        expect(document.body.querySelector('[data-testid="proposta-del-gruppo"]')).toBeNull();
        w!.unmount();
        document.body.innerHTML = '';
        await apri(punto({ ambito: 'scala', scala_id: 2, tabella_id: 7, tabella_nome: 'Scala B' }), false);
        expect(document.body.querySelector('[data-testid="proposta-del-gruppo"]')).toBeNull();
    });

    test('nell\'ordine del giorno il badge dice chi vota: «Fra i condòmini della tabella «Box»»', async () => {
        w = mount(Ordine, { attachTo: document.body, props: { condominioId: 3, condominioNome: 'Via Roma 12', scelte: conIlSegno(true), gestisce: true,
            assemblea: { id: 7, titolo: 'Assemblea ordinaria di Via Roma 12', data: '2026-12-15', in_preparazione: true, stato: 'in_preparazione', tabella: { nome: 'Proprietà' } },
            ordine: [punto({ gruppo_parola: 'Fra i condòmini della tabella «Box»' }), punto({ id: 10, numero: 6, tabella_id: null, tabella_nome: null })] } as never });
        await flushPromises();
        const badge = [...document.body.querySelectorAll('[data-testid="badge-gruppo"]')];
        expect(badge.map((b) => (b.textContent ?? '').trim())).toEqual(['Fra i condòmini della tabella «Box»']);
        expect(badge[0].className).toContain('rounded-md');
    });
});
