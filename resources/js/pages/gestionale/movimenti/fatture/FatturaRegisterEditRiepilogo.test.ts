// @vitest-environment jsdom

/**
 * Il modulo di MODIFICA di una fattura mostra ogni rifiuto del server nel riepilogo in testa, con il nome del campo.
 *
 * La modifica ha tre rifiuti che non hanno un campo proprio: il conto di addebito (il menu «Conto addebito» sta nel pannello laterale), la chiave generica `error`
 * del server (una scrittura contabile che non si compone) e le righe (`righe.N.conto_id`, `righe.N.immobile_id`). Senza il riepilogo il pulsante si riabilita e
 * non succede niente di visibile. Il riepilogo è lo stesso componente della registrazione (`FormErrorSummary`), con le etichette «Conto addebito» e «Riga N»;
 * le chiavi che hanno già la loro finestra (`modifica_vietata`, `avviso_nota`, `fattura_rettificata_id`) non si ripetono nel riepilogo.
 *
 * Dopo un salvataggio rifiutato il riepilogo si porta in vista e prende il fuoco: il pulsante sta in fondo alla pagina e il riquadro in testa.
 *
 * Si monta la pagina vera e si legge come la legge chi salva: il riepilogo è il riquadro «Il salvataggio è stato rifiutato». La via d'uscita del conto di addebito
 * rifiutato è il menu «Conto Addebito» (quello vero): offre le banche del condominio, se ne sceglie una e il rifiuto sparisce.
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 *
 * Cosa resta scoperto: il server; la scelta della voce dopo il rifiuto (`FatturaRegisterEditVoceEntrata.test.ts`); il riepilogo della registrazione
 * (`FatturaRegisterNewRiepilogo.test.ts`).
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axios = vi.hoisted(() => ({ get: vi.fn(async () => ({ data: [] })) }));
vi.mock('axios', () => ({ default: axios }));

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

(globalThis as unknown as { route: unknown }).route = (nome: string) => `/${nome}`;

const FatturaRegisterEdit = (await import('@/pages/gestionale/movimenti/fatture/FatturaRegisterEdit.vue')).default;

const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

const SPESA = { id: 55, nome: 'Pulizie', tipo: 'spesa', residuo_budget: 100000, is_capiente: true, parent_nome: null };

const fattura = {
    id: 91, fornitore_id: 10, fornitore: { id: 10, ragione_sociale: 'Mario Rossi Impianti', soggetto_ritenuta: false, regime_forfetario: false },
    esercizio_id: 41, gestione_id: 35, tipo_documento: 'fattura', is_pregresso: false, numero_documento: 'FT-2026-0044', numero_protocollo: 'FTP-2026-00009',
    data_documento: '2026-07-10', data_scadenza: '2026-08-09', conto_corrente_id: null, modalita_pagamento: 'bonifico', iban_fornitore: null, stato_approvazione: 'approvata',
    importo_imponibile: 30000, importo_iva: 0, importo_ritenuta: 0, totale_documento: 30000, netto_a_pagare: 30000,
    dati_extra: { fiscal: {}, competenza: null, override_budget: null },
    righe: [{ id: 300, descrizione: 'Servizio', conto_id: SPESA.id, immobile_id: null, importo_imponibile: 30000, importo_iva: 0, aliquota_iva: '0.00', concorre_base_ritenuta: true }],
    documenti: [], coperture: [],
};

let pagina: VueWrapper | null = null;

async function monta(contoCorrenteId: number | null = null) {
    pagina = mount(FatturaRegisterEdit, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' }, condomini: [{ id: 28, nome: 'Condominio Demo KM' }], esercizio: { id: 41, nome: '2026', stato: 'aperto' },
            esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }], gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', esercizi: [{ id: 41 }] }] as never, fornitori: [fattura.fornitore],
            conti: [SPESA] as never, banche: [{ id: 7, nome: 'Banca principale', saldo_attuale: 500000 }], immobili: [], debiti_patrimoniali: [], fatture_pregresse_registrate: [], fondi_riserva: [],
            capienza_rata_zero: 0, incassato_rata_zero: 0, nota_storno_originale: null, fattura: { ...fattura, conto_corrente_id: contoCorrenteId },
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
}

const form = () => (pagina!.vm as any).form;

/** Il riquadro del riepilogo dei rifiuti, se c'è. */
const riepilogo = () => [...document.body.querySelectorAll('[role="alert"]')].find((r) => r.textContent?.includes('Il salvataggio è stato rifiutato')) as HTMLElement | undefined;
const testoDel = (el: HTMLElement | undefined) => (el?.textContent ?? '').replace(/\s+/g, ' ').trim();
const occorrenze = (testo: string) => (document.body.textContent ?? '').split(testo).length - 1;

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il riepilogo dei rifiuti', () => {
    test('il conto di addebito rifiutato compare con il suo nome e il suo messaggio', async () => {
        await monta();

        form().errors = { conto_corrente_id: 'Il conto di addebito scelto non appartiene a questo condominio.' };
        await flushPromises();

        expect(testoDel(riepilogo())).toContain('Conto addebito');
        expect(testoDel(riepilogo())).toContain('Il conto di addebito scelto non appartiene a questo condominio.');
    });

    test('scegliendo una banca del condominio il rifiuto del conto di addebito sparisce', async () => {
        await monta(9999);
        form().errors = { conto_corrente_id: 'Il conto di addebito scelto non appartiene a questo condominio.' };
        await flushPromises();
        expect(testoDel(riepilogo())).toContain('Il conto di addebito scelto non appartiene a questo condominio.');

        const menu = [...document.body.querySelectorAll('.v-select')].find((v) => v.textContent?.includes('9999')) as HTMLElement;
        (menu.querySelector('.vs__dropdown-toggle') as HTMLElement).dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        await flushPromises();
        const banche = [...document.body.querySelectorAll('li.vs__dropdown-option')];
        expect(banche.map((o) => (o.textContent ?? '').replace(/\s+/g, ' ').trim().split('€')[0].trim())).toEqual(['Banca principale']);
        banche[0].dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        banche[0].dispatchEvent(new MouseEvent('click', { bubbles: true }));
        await flushPromises();

        expect(form().conto_corrente_id).toBe(7);
        expect(riepilogo()).toBeUndefined();
    });

    test('il rifiuto generico del server compare nel riepilogo', async () => {
        await monta();

        form().errors = { error: 'Integrità compromessa: Impossibile allocare la riga (nessun immobile e nessun conto associato).' };
        await flushPromises();

        expect(testoDel(riepilogo())).toContain('Impossibile allocare la riga');
    });

    test('il rifiuto di una riga compare con il numero della riga', async () => {
        await monta();

        form().errors = { 'righe.0.conto_id': 'La voce scelta non appartiene a questo condominio.' };
        await flushPromises();

        expect(testoDel(riepilogo())).toContain('Riga 1');
        expect(testoDel(riepilogo())).toContain('La voce scelta non appartiene a questo condominio.');
    });

    test('controprova — senza rifiuti il riepilogo non c\'è', async () => {
        await monta();

        expect(riepilogo()).toBeUndefined();
    });
});

describe('le chiavi che hanno già la loro finestra', () => {
    test('controprova — «modifica_vietata» si legge una volta sola, nella sua finestra, e non anche nel riepilogo', async () => {
        await monta();
        const messaggio = 'Questa fattura non si modifica più: è stata pagata, usa lo storno.';
        form().post = vi.fn((_url: string, opzioni: { onError: (e: Record<string, string>) => void }) => {
            form().errors = { modifica_vietata: messaggio };
            opzioni.onError({ modifica_vietata: messaggio });
        });

        (pagina!.vm as any).doSubmit();
        await flushPromises();

        expect(occorrenze(messaggio)).toBe(1);
        expect(riepilogo()).toBeUndefined();
    });

    test('controprova — l\'avviso della nota collegata si legge una volta sola, nella sua finestra', async () => {
        await monta();
        const avviso = 'La fattura è nel piano rate «Cornicione 2026», che ha già incassato rate.';
        form().post = vi.fn((_url: string, opzioni: { onError: (e: Record<string, string>) => void }) => {
            form().errors = { avviso_nota: avviso };
            opzioni.onError({ avviso_nota: avviso });
        });

        (pagina!.vm as any).doSubmit();
        await flushPromises();

        expect(occorrenze(avviso)).toBe(1);
        expect(riepilogo()).toBeUndefined();
    });
});

describe('dopo un salvataggio rifiutato', () => {
    const messaggio = 'La voce scelta non appartiene a questo condominio.';

    /** Il salvataggio finto che il server rifiuta con un errore su una riga: chiama `onError` come fa Inertia. */
    const rifiutaIlSalvataggio = () => {
        form().post = vi.fn((_url: string, opzioni: { onError: (e: Record<string, string>) => void }) => {
            form().errors = { 'righe.0.conto_id': messaggio };
            opzioni.onError({ 'righe.0.conto_id': messaggio });
        });
    };

    test('il riepilogo si porta in vista e prende il fuoco: il pulsante sta in fondo alla pagina e il riepilogo in testa', async () => {
        await monta();
        const scorri = vi.fn();
        (Element.prototype as unknown as { scrollIntoView: unknown }).scrollIntoView = scorri;
        rifiutaIlSalvataggio();

        (pagina!.vm as any).doSubmit();
        await flushPromises();
        await new Promise((r) => setTimeout(r, 20));

        expect(scorri).toHaveBeenCalledTimes(1);
        expect(scorri.mock.instances[0]).toBe(document.activeElement);
        expect(document.activeElement?.getAttribute('tabindex')).toBe('-1');
        expect(testoDel(document.activeElement as HTMLElement)).toContain('Il salvataggio è stato rifiutato');
        expect(testoDel(document.activeElement as HTMLElement)).toContain(messaggio);
    });

    test('controprova — senza rifiuti il riepilogo non si porta in vista', async () => {
        await monta();
        const scorri = vi.fn();
        (Element.prototype as unknown as { scrollIntoView: unknown }).scrollIntoView = scorri;
        form().post = vi.fn((_url: string, opzioni: { onError: (e: Record<string, string>) => void }) => opzioni.onError({}));

        (pagina!.vm as any).doSubmit();
        await flushPromises();
        await new Promise((r) => setTimeout(r, 20));

        expect(scorri).not.toHaveBeenCalled();
    });
});
