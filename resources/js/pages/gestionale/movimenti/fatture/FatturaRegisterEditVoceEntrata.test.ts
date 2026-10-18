// @vitest-environment jsdom

/**
 * Il modulo di MODIFICA di una fattura non fa scegliere una voce di entrata, mostra il rifiuto del server sotto il campo
 * che lo causa e lascia la via d'uscita.
 *
 * Il server rifiuta una riga su una voce di entrata (`righe.N.conto_id`) e una voce o un'unità che non sono del condominio (`righe.N.conto_id`,
 * `righe.N.immobile_id`): `Beta52VoceEntrataFattureTest`, `Beta52VoceEntrataPerimetroFattureTest`. Il modulo di modifica mostra l'uno e l'altro errore sotto il
 * campo che li causa, cioè come fratello del menu di quella riga (il riepilogo in testa ripete il messaggio, quindi non basta che il testo stia nella pagina):
 * senza, il rifiuto è muto. Qui si monta la pagina con il `vue-select` vero (non lo stub di `FatturaRegisterEdit.test.ts`: uno stub non
 * prova che la voce di spesa si possa scegliere dopo il rifiuto) e si legge il menu come lo legge chi modifica: la voce di entrata si vede, con la dicitura
 * «Entrata», e non si sceglie; la voce di spesa si sceglie, e l'errore di quella riga sparisce.
 *
 * Una voce senza `tipo` (le liste che il server manda senza il campo) resta scegliibile: l'assenza del campo vale «spesa».
 *
 * «Salva modifiche» manda la voce scelta: la richiesta si legge dalla trasformazione che il modulo applica ai dati prima dell'invio, con l'invio sostituito da un finto.
 * Una riga agganciata a una voce che il menu non offre (di un altro condominio, il varco di prima) si corregge scegliendo una voce del condominio: l'errore sparisce.
 *
 * I test che cominciano con «controprova» proteggono ciò che la correzione non deve rompere: sono verdi prima e dopo.
 *
 * Cosa resta scoperto: il server; il riepilogo dei rifiuti (`FatturaRegisterEditRiepilogo.test.ts`); il modulo di registrazione (il server non gli manda le entrate:
 * `Beta52VoceEntrataFattureTest`); la nota di credito; la tastiera.
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
const ENTRATA = { id: 56, nome: 'Affitto antenna', tipo: 'entrata', residuo_budget: 24000, is_capiente: true, parent_nome: null };
const SENZA_TIPO = { id: 57, nome: 'Giardinaggio', residuo_budget: 50000, is_capiente: true, parent_nome: null };

const fattura = (contoId: number, immobileId: number | null = null) => ({
    id: 91, fornitore_id: 10, fornitore: { id: 10, ragione_sociale: 'Mario Rossi Impianti', soggetto_ritenuta: false, regime_forfetario: false },
    esercizio_id: 41, gestione_id: 35, tipo_documento: 'fattura', is_pregresso: false, numero_documento: 'FT-2026-0044', numero_protocollo: 'FTP-2026-00009',
    data_documento: '2026-07-10', data_scadenza: '2026-08-09', conto_corrente_id: null, modalita_pagamento: 'bonifico', iban_fornitore: null, stato_approvazione: 'approvata',
    importo_imponibile: 30000, importo_iva: 0, importo_ritenuta: 0, totale_documento: 30000, netto_a_pagare: 30000,
    dati_extra: { fiscal: {}, competenza: null, override_budget: null },
    righe: [{ id: 300, descrizione: 'Servizio', conto_id: contoId, immobile_id: immobileId, importo_imponibile: 30000, importo_iva: 0, aliquota_iva: '0.00', concorre_base_ritenuta: true }],
    documenti: [], coperture: [],
});

let pagina: VueWrapper | null = null;

async function monta(contoId: number, conti: Record<string, unknown>[] = [SPESA, ENTRATA], immobili: Record<string, unknown>[] = []) {
    const f = fattura(contoId);
    pagina = mount(FatturaRegisterEdit, {
        attachTo: document.body,
        props: {
            condominio: { id: 28, nome: 'Condominio Demo KM' }, condomini: [{ id: 28, nome: 'Condominio Demo KM' }], esercizio: { id: 41, nome: '2026', stato: 'aperto' },
            esercizi: [{ id: 41, nome: '2026', stato: 'aperto' }], gestioni: [{ id: 35, nome: 'Gestione ordinaria 2026', esercizi: [{ id: 41 }] }] as never, fornitori: [f.fornitore],
            conti: conti as never, banche: [], immobili: immobili as never, debiti_patrimoniali: [], fatture_pregresse_registrate: [], fondi_riserva: [],
            capienza_rata_zero: 0, incassato_rata_zero: 0, nota_storno_originale: null, fattura: f,
        },
        global: { stubs, mocks: { route: (n: string) => `/${n}` } },
    });
    await flushPromises();
}

const form = () => (pagina!.vm as any).form;

/** Sostituisce l'invio del modulo con un finto e restituisce chi legge i dati che sarebbero partiti (dopo la trasformazione del modulo). */
function catturaInvio() {
    const f = form();
    let trasforma: ((dati: any) => any) | null = null;
    f.transform = (cb: (dati: any) => any) => { trasforma = cb; return f; };
    f.post = vi.fn();
    return () => ({ invii: f.post.mock.calls.length, dati: trasforma ? trasforma(f.data()) : null });
}

const salva = async () => {
    const pulsante = [...document.body.querySelectorAll('button')].find((b) => b.textContent?.includes('Salva Modifiche')) as HTMLButtonElement;
    pulsante.click();
    await flushPromises();
};
const menuDi = (testo: string) => [...document.body.querySelectorAll('.v-select')].find((v) => v.textContent?.includes(testo));
/** Il messaggio che sta subito sotto il menu: il riepilogo in testa lo ripete, quindi non basta che il testo stia da qualche parte nella pagina. */
const messaggioSottoIlMenu = (messaggio: string) => [...document.body.querySelectorAll('.v-select + p')].find((p) => p.textContent?.includes(messaggio));

async function apriIlMenuDelCapitolo(selezionata: string) {
    const menu = menuDi(selezionata);
    const toggle = menu?.querySelector('.vs__dropdown-toggle') as HTMLElement;
    toggle.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    await flushPromises();
}

/** Le voci del menu aperto: il testo che si legge e se si può sceglierla. */
const opzioni = () => [...document.body.querySelectorAll('li.vs__dropdown-option')].map((o) => ({ testo: (o.textContent ?? '').replace(/\s+/g, ' ').trim(), spenta: o.classList.contains('vs__dropdown-option--disabled') }));
const opzione = (nome: string) => opzioni().find((o) => o.testo.includes(nome));

async function scegli(testo: string) {
    const o = [...document.body.querySelectorAll('li.vs__dropdown-option')].find((x) => x.textContent?.includes(testo)) as HTMLElement;
    o.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
    o.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    await flushPromises();
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('il rifiuto del server nel modulo di modifica', () => {
    test('l\'errore della voce sta sotto il menu del capitolo', async () => {
        await monta(ENTRATA.id);

        form().errors = { 'righe.0.conto_id': 'Questa riga è sulla voce di entrata «Affitto antenna»: il capitolo di una fattura è una voce di spesa. Scegli una voce di spesa per salvare la modifica.' };
        await flushPromises();

        expect(messaggioSottoIlMenu('Questa riga è sulla voce di entrata «Affitto antenna»')).toBeDefined();
        expect(messaggioSottoIlMenu('Questa riga è sulla voce di entrata «Affitto antenna»')!.previousElementSibling).toBe(menuDi('Affitto antenna'));
    });

    test('l\'errore dell\'unità sta sotto il menu dell\'unità', async () => {
        await monta(SPESA.id, [SPESA, ENTRATA], [{ id: 5, label: 'Int. 1 — Rossi' }]);

        form().errors = { 'righe.0.immobile_id': 'L\'unità scelta non appartiene a questo condominio.' };
        await flushPromises();

        expect(messaggioSottoIlMenu('L\'unità scelta non appartiene a questo condominio.')).toBeDefined();
    });

    test('controprova — senza errori nessun messaggio sta sotto i menu della riga', async () => {
        await monta(SPESA.id, [SPESA, ENTRATA], [{ id: 5, label: 'Int. 1 — Rossi' }]);

        expect([...document.body.querySelectorAll('.v-select + p')].map((p) => p.textContent?.trim()).filter(Boolean)).toEqual([]);
    });
});

describe('il menu del capitolo', () => {
    test('la voce di entrata si vede con la dicitura «Entrata» e non si sceglie; la voce di spesa e quella senza tipo sì', async () => {
        await monta(SPESA.id, [SPESA, ENTRATA, SENZA_TIPO]);

        await apriIlMenuDelCapitolo('Pulizie');

        expect(opzioni().map((o) => o.testo.split('€')[0].trim())).toEqual(['Pulizie', 'Affitto antenna Entrata', 'Giardinaggio']);
        expect(opzione('Pulizie')?.spenta).toBe(false);
        expect(opzione('Giardinaggio')?.spenta).toBe(false);
        expect(opzione('Affitto antenna')?.spenta).toBe(true);
    });

    test('controprova — una voce di entrata che la riga ha già resta il valore attuale del menu', async () => {
        await monta(ENTRATA.id);

        expect(menuDi('Affitto antenna')?.querySelector('.vs__selected')?.textContent).toContain('Affitto antenna');
    });

    test('la via d\'uscita: dal rifiuto si sceglie una voce di spesa, la riga cambia e l\'errore di quella riga sparisce', async () => {
        await monta(ENTRATA.id);
        form().errors = { 'righe.0.conto_id': 'Questa riga è sulla voce di entrata «Affitto antenna»: il capitolo di una fattura è una voce di spesa. Scegli una voce di spesa per salvare la modifica.' };
        await flushPromises();
        expect(document.body.textContent).toContain('Questa riga è sulla voce di entrata «Affitto antenna»');

        await apriIlMenuDelCapitolo('Affitto antenna');
        await scegli('Pulizie');

        expect(form().righe[0].conto_id).toBe(SPESA.id);
        expect(document.body.textContent).not.toContain('Questa riga è sulla voce di entrata');
    });

    test('la voce di entrata, se la si prova a scegliere, non entra nella riga', async () => {
        await monta(SPESA.id);

        await apriIlMenuDelCapitolo('Pulizie');
        await scegli('Affitto antenna');

        expect(form().righe[0].conto_id).toBe(SPESA.id);
    });
});

describe('«Salva modifiche» dopo il rifiuto', () => {
    test('controprova — manda la voce di spesa scelta al posto di quella di entrata', async () => {
        await monta(ENTRATA.id);
        form().errors = { 'righe.0.conto_id': 'Questa riga è sulla voce di entrata «Affitto antenna»: il capitolo di una fattura è una voce di spesa. Scegli una voce di spesa per salvare la modifica.' };
        await flushPromises();
        const invio = catturaInvio();

        await apriIlMenuDelCapitolo('Affitto antenna');
        await scegli('Pulizie');
        await salva();

        expect(invio().invii).toBe(1);
        expect(invio().dati.righe.map((r: { conto_id: number | null }) => r.conto_id)).toEqual([SPESA.id]);
    });

    test('una riga su una voce che il menu non offre: il menu offre solo le voci del condominio, se ne sceglie una e l\'errore sparisce', async () => {
        const VOCE_ALTRUI = 9999;
        await monta(VOCE_ALTRUI);
        form().errors = { 'righe.0.conto_id': 'La voce scelta non appartiene a questo condominio.' };
        await flushPromises();
        expect(document.body.textContent).toContain('La voce scelta non appartiene a questo condominio.');
        const invio = catturaInvio();

        await apriIlMenuDelCapitolo(String(VOCE_ALTRUI)); // il menu mostra il valore che non riconosce
        expect(opzioni().map((o) => o.testo.split('€')[0].trim())).toEqual(['Pulizie', 'Affitto antenna Entrata']);
        await scegli('Pulizie');
        await salva();

        expect(form().righe[0].conto_id).toBe(SPESA.id);
        expect(document.body.textContent).not.toContain('La voce scelta non appartiene a questo condominio.');
        expect(invio().dati.righe.map((r: { conto_id: number | null }) => r.conto_id)).toEqual([SPESA.id]);
    });
});
