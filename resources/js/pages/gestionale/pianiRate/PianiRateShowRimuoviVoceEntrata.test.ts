// @vitest-environment jsdom

/**
 * La conferma di «Rimuovi voce» nella pagina del piano rate, per una voce di entrata.
 *
 * La conferma diceva per ogni voce «Stai per eliminare la voce di spesa:» e «Il totale del piano diminuirà dell'importo indicato.»: per un'entrata è il
 * contrario, togliere un provento fa salire ciò che si chiede ai condòmini. Ora, con una voce di entrata (anche un capitolo di sole entrate), dice «Stai
 * per eliminare la voce di entrata:» e «Il totale da ripartire aumenterà dell'importo indicato.» (la parola «da ripartire» è quella del netto, dove ci
 * sono entrate). «L'importo indicato» è una grandezza: con una copia scritta col meno (un dato di prima della 1.11.0-beta.52) la conferma lo mostra senza
 * il segno, altrimenti «−€ 240,00» e «aumenterà dell'importo indicato» si leggerebbero al contrario. Per un gruppo la conferma non mostra un importo
 * accanto al nome, e la frase dice «dell'importo del gruppo».
 *
 * La pagina riceve per ogni voce il `tipo` e l'`importo` (la copia del piano): qui sono scritti a mano, e `Beta53RimuoviVoceTipoTest` prova che il
 * server li manda così (anche «entrata» per un capitolo di soli sottoconti di entrata, e −24000 per una copia scritta col meno).
 *
 * Si monta la pagina intera (si stubbano la cornice, la guida, il selettore di date, il pannello laterale, il modale di «Sposta spesa», lo storico dei
 * movimenti e gli avvisi dei destinatari; la conferma è vera): si apre «Copertura spese», si preme il cestino della voce e si legge la conferma.
 *
 * I test che cominciano con «controprova» sono verdi prima e dopo la correzione.
 *
 * Cosa resta scoperto: il server (il test Pest citato); il capitolo con sottoconti di spesa e di entrata (la frase segue il tipo del capitolo); il piano
 * straordinario (le voci sono fatture, senza tipo: la frase resta quella della spesa); il tema scuro.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    router: { delete: vi.fn(), put: vi.fn(), post: vi.fn(), get: vi.fn(), reload: vi.fn((o?: { onFinish?: () => void }) => o?.onFinish?.()) },
    usePage: () => ({ props: { flash: {}, auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({ generatePath: (p: string) => `/${p}`, generateRoute: (n: string) => n, hasPermission: () => true, hasRole: () => true }),
}));
vi.mock('laravel-vue-i18n', () => ({ trans: (c: string) => c }));
(globalThis as unknown as { route: unknown }).route = (nome: string, parametri?: Record<string, unknown>) => `/${nome}?${JSON.stringify(parametri ?? {})}`;

const PianiRateShow = (await import('@/pages/gestionale/pianiRate/PianiRateShow.vue')).default;

let pagina: VueWrapper | null = null;

async function monta(capitolo: Record<string, unknown>) {
    pagina = mount(PianiRateShow, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo' } as never, esercizio: { id: 1, nome: '2026' } as never,
            pianoRate: { id: 5, nome: 'Ordinario 2026', stato: 'bozza', tipo: 'ordinario', numero_rate: 12, totale_spese: 120000, totale_entrate: 24000, totale_da_ripartire: 96000,
                budget_movements: [], capitoli: [{ is_frazionato: false, note: null, figli_names: '', ...capitolo }] },
            quotePerAnagrafica: [], quotePerImmobile: [], ratePure: [], needsMigration: false, sources: [], destinations: [], copertura: null, fermo_del_piano: null,
        },
        global: { stubs: { GestionaleLayout: { template: '<div><slot /></div>' }, PageHeaderGuide: true, VueDatePicker: true, PianoRateSpaccatoSheet: true, ModalSpostaSpesa: true, BudgetHistoryPopover: true, DestinatariWarning: true, DestinatariWarningDefunto: true, ScopertoWarning: true, Head: { template: '<span />' }, Link: { template: '<a><slot /></a>' } }, mocks: { route: (n: string, p?: Record<string, unknown>) => `/${n}?${JSON.stringify(p ?? {})}` } },
    });
    await flushPromises();
}

const normalizza = (t: string | null | undefined) => (t ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();

/** Apre «Copertura spese», preme il cestino della voce e dà il testo della conferma. */
async function conferma(): Promise<string> {
    const intestazione = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.includes('Copertura spese')) as HTMLButtonElement;
    intestazione.click();
    await flushPromises();
    await new Promise((r) => setTimeout(r, 150));
    await flushPromises();
    const cestino = document.body.querySelector('button[title="Rimuovi voce e ricalcola"]') as HTMLButtonElement | null;
    expect(cestino, 'il cestino della voce non c\'è').not.toBeNull();
    cestino!.click();
    await flushPromises();
    const titolo = [...document.body.querySelectorAll('*')].find((e) => e.children.length === 0 && normalizza(e.textContent) === 'Rimuovere voce dal piano?') as HTMLElement | undefined;
    expect(titolo, 'la conferma non si è aperta').toBeDefined();

    return normalizza(titolo!.closest('[role="dialog"], [role="alertdialog"]')?.textContent ?? document.body.textContent);
}

/** L'importo indicato nella conferma accanto al nome della voce. */
function importoIndicato(nome: string): string {
    const etichetta = [...document.body.querySelectorAll('span')].filter((e) => normalizza(e.textContent) === nome).pop() as HTMLElement | undefined;
    expect(etichetta, `la conferma non nomina «${nome}»`).toBeDefined();

    return normalizza(etichetta!.nextElementSibling?.textContent);
}

// Le voci come le manda `PianoRateResource` (`Beta53RimuoviVoceTipoTest`): `tipo` dalla regola del segno, `importo` la copia del piano.
const ENTRATA = { id: 2, nome: 'Affitto antenna', importo: 24000, importo_originale: 24000, is_parent: false, tipo: 'entrata' };
const SPESA = { id: 1, nome: 'Spese generali', importo: 120000, importo_originale: 120000, is_parent: false, tipo: 'spesa' };

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('la conferma di «Rimuovi voce»', () => {
    test('su una voce di entrata dice «voce di entrata» e che il totale da ripartire aumenterà', async () => {
        await monta(ENTRATA);

        const t = await conferma();

        expect(t).toContain('Stai per eliminare la voce di entrata:');
        expect(t).toContain('Il totale da ripartire aumenterà dell\'importo indicato.');
        expect(t).not.toContain('voce di spesa');
        expect(t).not.toContain('diminuirà');
        expect(importoIndicato('Affitto antenna')).toBe('€ 240,00');
    });

    test('su un capitolo di sole entrate la seconda frase è quella dell\'entrata, con l\'importo del gruppo', async () => {
        await monta({ id: 4, nome: 'Proventi', importo: 50000, importo_originale: 50000, is_parent: true, figli_names: 'Antenna A, Antenna B', tipo: 'entrata' });

        const t = await conferma();

        expect(t).toContain('Stai rimuovendo il gruppo Proventi.');
        expect(t).toContain('Il totale da ripartire aumenterà dell\'importo del gruppo.');
        expect(t).not.toContain('importo indicato');
        expect(t).not.toContain('diminuirà');
    });

    test('su un capitolo di spese la conferma non mostra un importo, e la frase dice l\'importo del gruppo', async () => {
        await monta({ id: 6, nome: 'Manutenzione', importo: 70000, importo_originale: 70000, is_parent: true, figli_names: 'Ascensore, Cancello', tipo: 'spesa' });

        const t = await conferma();

        expect(t).toContain('Stai rimuovendo il gruppo Manutenzione.');
        expect(t).toContain('Il totale del piano diminuirà dell\'importo del gruppo.');
        expect(t).not.toContain('importo indicato');
        expect(t).not.toContain('€ 700,00');
    });

    test('con una copia scritta col meno l\'importo indicato è senza il segno, per un\'entrata e per una spesa', async () => {
        for (const voce of [{ ...ENTRATA, importo: -24000, importo_originale: -24000 }, { id: 3, nome: 'Rimborso vecchio', importo: -24000, importo_originale: -24000, is_parent: false, tipo: 'spesa' }]) {
            await monta(voce);

            const t = await conferma();

            expect(importoIndicato(voce.nome)).toBe('€ 240,00');
            expect(t).not.toContain('-240,00');
            expect(t).not.toContain('−240,00');
            pagina?.unmount();
            pagina = null;
            document.body.innerHTML = '';
        }
    });

    test('controprova — su una voce di spesa dice ancora «voce di spesa» e che il totale del piano diminuirà', async () => {
        await monta(SPESA);

        const t = await conferma();

        expect(t).toContain('Stai per eliminare la voce di spesa:');
        expect(t).toContain('Il totale del piano diminuirà dell\'importo indicato.');
        expect(importoIndicato('Spese generali')).toBe('€ 1.200,00');
        expect(t).not.toContain('aumenterà');
    });
});
