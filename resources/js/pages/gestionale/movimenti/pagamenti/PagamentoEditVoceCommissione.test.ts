// @vitest-environment jsdom

/**
 * La voce della commissione nella modifica di un pagamento al fornitore.
 *
 * Il server manda la voce della commissione del pagamento (`commissione.voce_id`), la gestione del pagamento e se il pagamento è stato scritto
 * senza voce (`commissione.senza_voce`), con le voci di spesa del condominio (`voci_commissione`, ognuna con la sua gestione). La pagina offre solo
 * le voci della gestione del pagamento. Aperta e inviata senza toccare niente, manda la voce che ha ricevuto; un pagamento scritto prima senza voce
 * lo dice accanto al campo. Se il server dice che la modifica lo può lasciare così (`commissione.puo_restare_senza_voce`), fra le scelte c'è «Lascia senza
 * voce, come era», finché la commissione non cambia; altrimenti, con una commissione più di zero, non si invia finché la voce non c'è (la stessa frase del
 * server, sul campo).
 *
 * Il lato del server è in `tests/Feature/Gestionale/CommissioneConVoceTest.php`.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
// `vue-select` non ha un nome: uno stub per nome non lo sostituisce, e la prova legge le sue `options` sul componente vero.
import vSelect from 'vue-select';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import PagamentoEdit from './PagamentoEdit.vue';
import { LASCIA_SENZA_VOCE } from '@/lib/gestionale/pagamenti/voceCommissione';

(globalThis as any).route = (nome: string) => `/${nome}`;
const mocks = { route: (nome: string) => `/${nome}` };
const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

const FRASE = 'Scegli la voce della commissione: senza una voce la commissione resta fuori dal consuntivo delle voci.';

const pagamento = {
    id: 7,
    fornitore: { id: 3, ragione_sociale: 'Idraulica Rossi S.r.l.' },
    conto_corrente: { id: 11, nome: 'Banca Intesa', iban: 'IT60X0542811101000000123456' },
    data_pagamento: '2026-04-10',
    metodo_pagamento: 'bonifico',
    importo_commissione: 250,
    importo_lordo: 100_000,
    importo_ritenuta: 0,
    importo_netto: 100_000,
    bonifico_parlante: false,
};

const voci = [
    { id: 5, nome: 'Spese bancarie', label: 'Spese bancarie', gestione_id: 10 },
    { id: 6, nome: 'Bolli', label: 'Bolli', gestione_id: 10 },
    { id: 9, nome: 'Bancarie del tetto', label: 'Bancarie del tetto', gestione_id: 20 },
];

let pagina: VueWrapper | null = null;

function apri(commissione: { voce_id: number | null; gestione_id: number; senza_voce: boolean; puo_restare_senza_voce?: boolean; esercizio_chiuso?: boolean }, sovrascrivi: Record<string, unknown> = {}) {
    pagina = mount(PagamentoEdit, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo KM' },
            condomini: [{ id: 1, nome: 'Condominio Demo KM' }],
            esercizio: { id: 4, nome: '2026', stato: 'aperto' },
            fornitori: [pagamento.fornitore],
            banche: [{ id: 11, cassa_id: 2, nome: 'Banca Intesa', tipo: 'banca', saldo_attuale: 900_000 }],
            pagamento: { ...pagamento, ...sovrascrivi },
            voci_commissione: voci,
            commissione,
        } as never,
        global: { stubs, mocks },
    });

    return pagina;
}

/** Il campo della voce, letto sul componente vero. */
function selezione() {
    return pagina!.findAllComponents(vSelect as never).find((c) => (c.props('options') as any[])?.some?.((o: any) => o.label === 'Spese bancarie'));
}

function opzioni(): Array<{ id: number; label: string }> | undefined {
    return selezione()?.props('options') as Array<{ id: number; label: string }> | undefined;
}

function invia() {
    const stato = (pagina!.vm as any);
    const corpi: Record<string, unknown>[] = [];
    let trasformazione: ((dati: Record<string, unknown>) => Record<string, unknown>) | null = null;
    stato.form.transform = (callback: typeof trasformazione) => { trasformazione = callback; return stato.form; };
    stato.form.put = (_url: string, opzioni: { onSuccess?: () => void }) => {
        corpi.push(trasformazione!(stato.form.data()));
        opzioni.onSuccess?.();
    };
    stato.handleSubmit();

    return corpi;
}

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('la voce della commissione in modifica', () => {
    test('aperta e inviata senza toccare niente manda la voce ricevuta e la commissione in centesimi, senza la casella in euro', () => {
        apri({ voce_id: 5, gestione_id: 10, senza_voce: false });

        const [corpo] = invia();

        expect(corpo).toMatchObject({ voce_commissione_id: 5, importo_commissioni_cents: 250 });
        expect(Object.keys(corpo).filter((k) => k.includes('euro'))).toEqual([]);
    });

    test('offre solo le voci della gestione del pagamento', () => {
        apri({ voce_id: 5, gestione_id: 10, senza_voce: false });

        const scelta = pagina!.findAllComponents(vSelect as never).find((c) => (c.props('options') as any[])?.some?.((o: any) => o.label === 'Spese bancarie'));
        expect((scelta!.props('options') as any[]).map((o) => o.id)).toEqual([5, 6]);
    });

    test('un pagamento senza voce che la modifica non può lasciare così lo dice, e con la commissione non si invia senza la voce', async () => {
        apri({ voce_id: null, gestione_id: 10, senza_voce: true, puo_restare_senza_voce: false });

        expect(pagina!.find('[data-test="frase-senza-voce"]').text()).toBe('Questo pagamento è stato registrato senza la voce della commissione. Per salvarlo con questa commissione scegli la voce: la commissione entrerà nel suo consuntivo.');
        // Coda 553: la via d'uscita (commissione a zero e regolazione immediata) non c'è più; sotto il campo si dice che cosa offre.
        expect(document.body.textContent).toContain('In cima le voci della gestione del pagamento; sotto, quelle dell\'ordinaria dello stesso esercizio, per esempio le spese bancarie.');
        expect(pagina!.find('[data-test="regolazione-immediata"]').exists()).toBe(false);
        expect(opzioni()!.map((o) => o.label)).toEqual(['Spese bancarie', 'Bolli']);
        expect(invia()).toEqual([]);
        await nextTick();
        expect(document.body.textContent).toContain(FRASE);
    });

    test('un pagamento scritto prima senza voce, con la commissione com\'era: la scelta «Lascia senza voce, come era» c\'è in cima, è quella di partenza e parte come nessuna voce', async () => {
        apri({ voce_id: null, gestione_id: 10, senza_voce: true, puo_restare_senza_voce: true });

        expect(opzioni()!.map((o) => o.label)).toEqual(['Lascia senza voce, come era', 'Spese bancarie', 'Bolli']);
        expect((pagina!.vm as any).form.voce_commissione_id).toBe(LASCIA_SENZA_VOCE);
        expect(pagina!.find('[data-test="frase-senza-voce"]').text()).toBe('Questo pagamento è stato registrato senza la voce della commissione, che resta fuori dal consuntivo delle voci. Puoi lasciarlo così, finché non cambi la commissione, o scegliere la voce.');
        // Il campo non si svuota: lasciarlo com'era è una scelta, non un campo vuoto.
        expect(selezione()!.props('clearable')).toBe(false);

        const [corpo] = invia();
        expect(corpo).toMatchObject({ voce_commissione_id: null, importo_commissioni_cents: 250 });
        await nextTick();
        expect(document.body.textContent).not.toContain(FRASE);
    });

    test('se la commissione cambia la scelta sparisce e la voce si chiede; se torna com\'era, la scelta torna', async () => {
        apri({ voce_id: null, gestione_id: 10, senza_voce: true, puo_restare_senza_voce: true });
        const form = (pagina!.vm as any).form;

        form.importo_commissioni_euro = 3;
        await nextTick();
        expect(opzioni()!.map((o) => o.label)).toEqual(['Spese bancarie', 'Bolli']);
        expect(form.voce_commissione_id).toBeNull();
        expect(pagina!.find('[data-test="frase-senza-voce"]').text()).toBe('Questo pagamento è stato registrato senza la voce della commissione. Per salvarlo con questa commissione scegli la voce: la commissione entrerà nel suo consuntivo.');
        expect(selezione()!.props('clearable')).toBe(true);
        expect(invia()).toEqual([]);
        await nextTick();
        expect(document.body.textContent).toContain(FRASE);

        // Un centesimo di differenza basta.
        form.importo_commissioni_euro = 2.51;
        await nextTick();
        expect(opzioni()!.map((o) => o.label)).not.toContain('Lascia senza voce, come era');

        form.importo_commissioni_euro = 2.5;
        await nextTick();
        expect(opzioni()!.map((o) => o.label)).toContain('Lascia senza voce, come era');
        expect(form.voce_commissione_id).toBe(LASCIA_SENZA_VOCE);
        expect(invia()[0]).toMatchObject({ voce_commissione_id: null, importo_commissioni_cents: 250 });
    });

    test('con la commissione tolta non c\'è niente da lasciare: la voce non serve e parte nessuna voce', async () => {
        apri({ voce_id: null, gestione_id: 10, senza_voce: true, puo_restare_senza_voce: true });
        (pagina!.vm as any).form.importo_commissioni_euro = 0;
        await nextTick();

        expect(invia()[0]).toMatchObject({ voce_commissione_id: null, importo_commissioni_cents: 0 });
    });

    test('un pagamento che aveva la voce non ha la scelta, e la voce che ha resta quella di partenza', () => {
        apri({ voce_id: 5, gestione_id: 10, senza_voce: false, puo_restare_senza_voce: false });

        expect(opzioni()!.map((o) => o.label)).toEqual(['Spese bancarie', 'Bolli']);
        expect((pagina!.vm as any).form.voce_commissione_id).toBe(5);
        expect(pagina!.find('[data-test="frase-senza-voce"]').exists()).toBe(false);
    });

    test('scegliendo una voce vera al posto di «Lascia senza voce» si invia con la voce', () => {
        apri({ voce_id: null, gestione_id: 10, senza_voce: true, puo_restare_senza_voce: true });
        (pagina!.vm as any).form.voce_commissione_id = 5;

        expect(invia()[0]).toMatchObject({ voce_commissione_id: 5 });
    });

    test('scelta la voce, si invia con la voce', async () => {
        apri({ voce_id: null, gestione_id: 10, senza_voce: true });
        (pagina!.vm as any).form.voce_commissione_id = 6;

        expect(invia()[0]).toMatchObject({ voce_commissione_id: 6 });
    });

    test('in un esercizio chiuso non chiede la voce, non offre la scelta e non lo dice: il server risponde che si storna', async () => {
        apri({ voce_id: null, gestione_id: 10, senza_voce: true, puo_restare_senza_voce: true, esercizio_chiuso: true });

        expect(pagina!.find('[data-test="frase-senza-voce"]').exists()).toBe(false);
        expect(opzioni()!.map((o) => o.label)).toEqual(['Spese bancarie', 'Bolli']);
        expect(document.body.textContent).not.toContain('Questo pagamento è stato registrato senza la voce della commissione');
        expect(invia()).toHaveLength(1);
        await nextTick();
        expect(document.body.textContent).not.toContain(FRASE);
    });

    test('senza commissione la voce non serve: si invia anche vuota', () => {
        apri({ voce_id: null, gestione_id: 10, senza_voce: false }, { importo_commissione: 0 });

        expect(invia()[0]).toMatchObject({ importo_commissioni_cents: 0, voce_commissione_id: null });
    });
});
