// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra del punto nell'assemblea dei condòmini di un gruppo (Coda 734, scena 11 della bozza approvata; decisione 699 (3)):
 *
 * - «Per chi» è fermo su «Tutto il gruppo della tabella «Box»», con le sue 8 unità; «Con quale tabella» offre «Box» (la tabella del gruppo) e le tabelle di
 *   un gruppo che stanno dentro di lei, non le altre; una riga dice «In questa assemblea votano solo i condòmini della tabella «Box».»; niente riga arancio,
 *   niente proposta del segno, niente riquadro della convocazione a tutti.
 * - Un punto di tutto il condominio (il rendiconto): la riga rossa con il rischio, «Salva il punto» spento, e «Mettilo lo stesso», che salva il punto con la
 *   scelta (`mettilo_lo_stesso`); in «Prepara l'assemblea» la scelta resta nel punto scritto. Il punto che era già un rendiconto, riscritto, non chiede di
 *   nuovo.
 * - Fuori da un'assemblea di un gruppo la finestra è quella di sempre.
 *
 * Che cosa non copre: il fermo del server (`AssembleaDiUnGruppoTest`); i menu `v-select` aperti a mano.
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (n: string) => n, hasPermission: () => true }) }));
const post = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post, patch: vi.fn(), delete: vi.fn(), reload: vi.fn() } }));
vi.mock('vue-select', () => ({ default: { name: 'VSelect', props: ['modelValue', 'options', 'disabled'], template: '<div class="v-select-finto" />' } }));
(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./FinestraDelPunto.vue')).default;
const controllo = (conQuota: number, unita: number, dentro = false) => ({ frase: `${conQuota} unità su ${unita} · 1.000,00 millesimi`, copre: conQuota === unita,
    esatta: conQuota === unita, con_quota: conQuota, unita, dentro });
const scelte = { tipi: [], perimetri: [], modalita: [], materie: [{ valore: 'lavori', nome: 'Lavori' }, { valore: 'rendiconto', nome: 'Rendiconto' }], maggioranze: [],
    esiti: [], a_chi: [], unita: 34, palazzine: [], scale: [{ id: 2, nome: 'B', palazzina_id: null, unita: 14 }],
    tabelle: [
        { id: 5, nome: 'Box', unita: 8, di_un_gruppo: true, millesimi: '1.000,00', controlli: { condominio: controllo(8, 34), 'gruppo:5': controllo(8, 8, true) } },
        { id: 6, nome: 'Proprietà', unita: 26, di_un_gruppo: false, millesimi: '1.000,00', controlli: { condominio: controllo(26, 34), 'gruppo:5': controllo(1, 8) } },
        { id: 8, nome: 'Box fila A', unita: 2, di_un_gruppo: true, millesimi: '1.000,00', controlli: { condominio: controllo(2, 34), 'gruppo:5': controllo(2, 8, true) } },
        { id: 9, nome: 'Box e cantine', unita: 3, di_un_gruppo: true, millesimi: '1.000,00', controlli: { condominio: controllo(3, 34), 'gruppo:5': controllo(2, 8) } },
    ],
    maggioranze_proposte: {} };
const GRUPPO = { tabella_id: 5, nome: 'Box', unita: 8 };
const punto = (extra: Record<string, unknown> = {}) => ({
    id: 9, numero: 1, testo: 'Sostituzione della serranda dei box', testo_pubblico: null, materia: 'lavori', materia_parola: 'Lavori', maggioranza: 'meta_del_valore',
    maggioranza_parola: null, maggioranza_libera: null, avviso: null, amministrazione: 'straordinaria', conduttore: null, conduttore_parola: null,
    ambito: 'condominio', palazzina_id: null, scala_id: null, perimetro_parola: null, tabella_id: null, tabella_nome: null, tabella_controllo: null,
    esercizio_id: null, importo: null, dati: null, fonte: 'a_mano', chiave_fonte: null, da_memo: false, delibere: [], ...extra,
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; post.mockReset(); document.body.innerHTML = ''; });
async function apri(props: Record<string, unknown> = {}) {
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 3, condominioNome: 'Via Roma 12', assembleaId: 7,
        assembleaNome: 'Assemblea straordinaria del 15/12/2026', tabellaDellAssemblea: 'Box', punto: null, scelte, gruppo: GRUPPO, ...props } as never });
    await flushPromises();
}
const trova = (sel: string) => document.body.querySelector(sel);
const testo = (sel: string) => (trova(sel)?.textContent ?? '').replace(/\s+/g, ' ').trim();
type Opzione = { valore: string | null; nome: string; sotto?: string };
const menu = (prova: (o: Opzione[]) => boolean) => w!.findAllComponents({ name: 'VSelect' }).find((c) => prova((c.props('options') ?? []) as Opzione[]))!;
const perChi = () => menu((o) => o.some((x) => x.valore === 'condominio'));
const conQualeTabella = () => menu((o) => o.some((x) => x.nome === 'Box'));
async function scegliLaMateria(valore: string) {
    menu((o) => o.some((x) => x.valore === 'rendiconto')).vm.$emit('update:modelValue', valore);
    await flushPromises();
}
async function scriviIlTesto(t: string) {
    const area = trova('[data-testid="testo-punto"]') as HTMLTextAreaElement;
    area.value = t;
    area.dispatchEvent(new Event('input'));
    await flushPromises();
}

describe('la finestra del punto nell\'assemblea dei condòmini di un gruppo', () => {
    test('«Per chi» fermo sul gruppo, le tabelle del gruppo, la riga di chi vota; niente arancio né convocazione a tutti', async () => {
        await apri({ punto: punto() });
        expect(perChi().props('options')).toEqual([{ valore: 'condominio', nome: 'Tutto il gruppo della tabella «Box»', sotto: '8 unità' }]);
        expect(perChi().props('disabled')).toBe(true);
        expect((conQualeTabella().props('options') as Opzione[]).map((o) => [o.nome, o.sotto])).toEqual([['Box', 'la tabella del gruppo'],
            ['Box fila A', '2 unità su 8 · 1.000,00 millesimi']]);
        expect(testo('[data-testid="riga-del-gruppo"]')).toBe('In questa assemblea votano solo i condòmini della tabella «Box».');
        for (const via of ['avviso-tabella-parziale', 'proposta-del-gruppo', 'sala-di-una-parte', 'rischio-di-tutti', 'mettilo-lo-stesso']) {
            expect(trova(`[data-testid="${via}"]`), via).toBeNull();
        }
        expect(testo('[data-testid="adesso-punto"]')).toBe('Adesso: il punto è di tutto il gruppo, con la tabella «Box».');
    });

    test('con la tabella di un gruppo dentro «Box» il punto si conta fra le sue unità, e la convocazione non va a tutto il condominio', async () => {
        await apri({ punto: punto({ tabella_id: 8 }) });
        expect(testo('[data-testid="sala-di-una-parte"]')).toContain('In sala, per questo punto, votano solo i condòmini delle 2 unità della tabella «Box fila A».');
        expect(testo('[data-testid="sala-di-una-parte"]')).not.toContain('tutti i condòmini di Via Roma 12');
        expect(testo('[data-testid="adesso-punto"]')).toBe('Adesso: il punto 1 si vota fra le 2 unità della tabella «Box fila A»; gli altri punti restano di tutto il gruppo, '
            + 'con la tabella «Box».');
    });

    test('il rendiconto di tutto il condominio: la riga del rischio, «Salva il punto» spento, «Mettilo lo stesso» salva con la scelta', async () => {
        await apri();
        await scriviIlTesto('Approvazione del rendiconto 2026');
        await scegliLaMateria('rendiconto');
        expect(testo('[data-testid="rischio-di-tutti"]')).toBe('Il rendiconto è di tutto il condominio: con i soli condòmini del gruppo la delibera è annullabile da '
            + 'chi non è convocato.');
        expect(trova('[data-testid="rischio-di-tutti"]')!.className).toContain('text-rose-700');
        expect((trova('[data-testid="salva-punto"]') as HTMLButtonElement).disabled).toBe(true);
        (trova('[data-testid="mettilo-lo-stesso"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][1]).toMatchObject({ materia: 'rendiconto', mettilo_lo_stesso: true });
    });

    test('in «Prepara l\'assemblea» «Mettilo lo stesso» lascia il punto nella pagina con la scelta', async () => {
        await apri({ assembleaId: null, nellaPagina: true });
        await scriviIlTesto('Approvazione del rendiconto 2026');
        await scegliLaMateria('rendiconto');
        (trova('[data-testid="mettilo-lo-stesso"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(post).not.toHaveBeenCalled();
        expect(w!.emitted('salva')![0][0]).toMatchObject({ materia: 'rendiconto', mettilo_lo_stesso: true });
    });

    test('il punto che era già un rendiconto, riscritto, non chiede di nuovo', async () => {
        await apri({ punto: punto({ materia: 'rendiconto', testo: 'Approvazione del rendiconto 2026' }) });
        expect(trova('[data-testid="rischio-di-tutti"]')).toBeNull();
        expect((trova('[data-testid="salva-punto"]') as HTMLButtonElement).disabled).toBe(false);
    });

    test('fuori da un\'assemblea di un gruppo la finestra è quella di sempre', async () => {
        await apri({ gruppo: null, tabellaDellAssemblea: 'Proprietà', punto: punto({ materia: 'rendiconto' }) });
        expect((perChi().props('options') as Opzione[]).map((o) => o.valore)).toEqual(['condominio', 'scala:2']);
        expect(trova('[data-testid="riga-del-gruppo"]')).toBeNull();
        expect(trova('[data-testid="rischio-di-tutti"]')).toBeNull();
    });
});
