// @vitest-environment jsdom

/**
 * Coda 466 — la modifica di un pagamento fornitore che il server rifiuta per il limite dei contanti: la finestra della pagina si apre.
 *
 * Il controller (`PagamentoFornitoreController::update`) risponde al rifiuto del limite dei contanti con l'errore `illegal_cash`, e `PagamentoEdit.vue`
 * ha la finestra «Pagamento Non Consentito» per quella chiave; ma la modifica non controllava il limite, e la finestra non si apriva mai. Ora il server
 * lo rifiuta quando il metodo diventa contanti (`tests/Feature/Gestionale/Beta1_112Giro3PreesistentiContantiModificaTest.php`, dalla rotta e con il
 * corpo che la pagina manda): qui si prova l'altra metà, che la pagina, ricevuto l'errore, apre la finestra giusta e non quella generica.
 *
 * Il corpo che la pagina manda, aperta e inviata senza toccare niente, è quello dei dati ricevuti anche per un pagamento in contanti già sopra soglia,
 * senza `fornitore_id`: la modifica di un pagamento così, della nota o della data, non è bloccata dal server e non deve cambiare niente per la pagina.
 */

import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Head: { template: '<span />' },
    usePage: () => ({ props: { auth: { user: { roles: ['amministratore'], permissions: [] } } } }),
}));

import PagamentoEdit from './PagamentoEdit.vue';

(globalThis as any).route = (nome: string) => `/${nome}`;
const mocks = { route: (nome: string) => `/${nome}` };
const stubs = {
    GestionaleLayout: { template: '<div><slot /></div>' },
    PageHeaderGuide: { template: '<div><slot /></div>' },
    Head: { template: '<span />' },
    Link: { template: '<a><slot /></a>' },
    'v-select': true,
    MoneyInput: { name: 'MoneyInput', props: ['modelValue'], template: '<input :value="modelValue" />' },
};

/** Un bonifico da € 6.000,00 portato a contanti, o un pagamento che in contanti lo era già: le prop come le manda `PagamentoFornitoreResource`. */
const pagamento = (metodo: string) => ({
    id: 7,
    fornitore: { id: 3, ragione_sociale: 'Idraulica Rossi S.r.l.' },
    conto_corrente: { id: 11, nome: 'Banca Intesa', iban: 'IT60X0542811101000000123456' },
    data_pagamento: '2026-07-15',
    metodo_pagamento: metodo,
    importo_commissione: 0,
    importo_lordo: 600_000,
    importo_ritenuta: 0,
    importo_netto: 600_000,
    bonifico_parlante: false,
});

let pagina: VueWrapper | null = null;

function apri(metodo: string) {
    pagina = mount(PagamentoEdit, {
        attachTo: document.body,
        props: {
            condominio: { id: 1, nome: 'Condominio Demo KM' },
            condomini: [{ id: 1, nome: 'Condominio Demo KM' }],
            esercizio: { id: 4, nome: '2026', stato: 'aperto' },
            fornitori: [pagamento(metodo).fornitore],
            banche: [{ id: 11, cassa_id: 2, nome: 'Banca Intesa', tipo: 'banca', saldo_attuale: 900_000 }],
            pagamento: pagamento(metodo),
        } as never,
        global: { stubs, mocks },
    });

    return pagina;
}

/** La richiesta: `form.transform(...).put(...)` con la risposta del server simulata (gli errori che Inertia passa a `onError`). */
function inviaConLaRispostaDelServer(errori: Record<string, string> | null) {
    const stato = (pagina!.vm as any);
    const corpi: Record<string, unknown>[] = [];
    let trasformazione: ((dati: Record<string, unknown>) => Record<string, unknown>) | null = null;

    stato.form.transform = (callback: typeof trasformazione) => { trasformazione = callback; return stato.form; };
    stato.form.put = (_url: string, opzioni: { onSuccess?: () => void; onError?: (e: Record<string, string>) => void }) => {
        corpi.push(trasformazione!(stato.form.data()));
        if (errori) opzioni.onError?.(errori); else opzioni.onSuccess?.();
    };
    stato.handleSubmit();

    return corpi;
}

const testoDelleFinestre = () => document.body.textContent ?? '';

afterEach(() => {
    pagina?.unmount();
    pagina = null;
    document.body.innerHTML = '';
});

describe('la finestra del limite dei contanti, in modifica (Coda 466)', () => {
    test('l\'errore del limite dei contanti apre la finestra «Pagamento Non Consentito», con il richiamo alla normativa e il metodo da cambiare', async () => {
        apri('contanti');

        inviaConLaRispostaDelServer({ illegal_cash: 'Pagamento in contanti di € 6.000,00 non consentito: è pari o superiore al limite di € 5.000,00 (D.Lgs. 231/2007 antiriciclaggio).' });
        await flushPromises();

        expect(testoDelleFinestre()).toContain('Pagamento Non Consentito');
        expect(testoDelleFinestre()).toContain('D.Lgs. 231/2007');
        expect(testoDelleFinestre()).toContain('Cambio Metodo di Pagamento');
    });

    test('la finestra dice la cifra del server, i soli metodi che la pagina offre e la soglia scritta come gli importi, senza una forchetta di sanzioni', async () => {
        apri('contanti');

        inviaConLaRispostaDelServer({ illegal_cash: 'Pagamento in contanti di € 6.000,00 non consentito: è pari o superiore al limite di € 5.000,00 (D.Lgs. 231/2007 antiriciclaggio).' });
        await flushPromises();

        const testo = testoDelleFinestre();
        expect(testo).toContain('Pagamento in contanti di € 6.000,00 non consentito');
        expect(testo).toContain('€ 5.000,00');
        expect(testo).not.toContain('5.000€');
        expect(testo).not.toContain('Carta di credito');
        expect(testo).not.toContain('50.000');
        expect(testo).toContain('Bonifico');
        expect(testo).toContain('Assegno');
    });

    test('non si apre insieme alla finestra generica del rifiuto: la chiave ha la sua finestra', async () => {
        apri('contanti');

        inviaConLaRispostaDelServer({ illegal_cash: 'Pagamento in contanti di € 6.000,00 non consentito.' });
        await flushPromises();

        expect(testoDelleFinestre()).not.toContain('Modifica non registrata');
    });

    test('controprova: con una modifica riuscita la finestra del limite non si apre', async () => {
        apri('bonifico');

        inviaConLaRispostaDelServer(null);
        await flushPromises();

        expect(testoDelleFinestre()).not.toContain('Pagamento Non Consentito');
    });
});

describe('aperta e inviata senza toccare niente, la pagina manda i dati ricevuti (Coda 466)', () => {
    test('un pagamento in contanti da € 6.000,00 già salvato: metodo, importi e data come sono, e nessun fornitore', () => {
        apri('contanti');

        const [corpo] = inviaConLaRispostaDelServer(null);

        expect(corpo).toMatchObject({
            esercizio_id: 4,
            conto_corrente_id: 11,
            data_pagamento: '2026-07-15',
            metodo_pagamento: 'contanti',
            importo_commissioni_cents: 0,
            importo_lordo_cents: 600_000,
            importo_ritenuta_cents: 0,
            importo_netto_cents: 600_000,
            bonifico_parlante: false,
            allow_overdraft: false,
            allow_overpayment: false,
        });
        expect(corpo).not.toHaveProperty('fornitore_id');
    });

    test('portato a «Contanti» dal menu dei metodi, il corpo cambia solo il metodo: gli importi restano quelli del bonifico', () => {
        apri('bonifico');
        (pagina!.vm as any).form.metodo_pagamento = 'contanti';

        const [corpo] = inviaConLaRispostaDelServer(null);

        expect(corpo).toMatchObject({ metodo_pagamento: 'contanti', importo_lordo_cents: 600_000, importo_netto_cents: 600_000 });
    });
});
