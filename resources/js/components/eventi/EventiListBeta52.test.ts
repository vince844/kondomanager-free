// @vitest-environment jsdom

/**
 * Cosa resta scoperto: l'agenda del condòmino (`pages/eventi/user/EventiList.vue`), che mostra la descrizione del promemoria su due righe e non
 * ha un modo di leggerla tutta: la parte nuova delle frasi sta in coda e si guarda a video (con una riga in più o un «Mostra tutto»); il cruscotto
 * del condòmino montato; il disegno a 375 px.
 *
 * L'elenco dei promemoria del cruscotto del condòmino e la finestra che si apre da una riga.
 *
 * L'elenco tiene l'identificativo del promemoria aperto e ricava da `eventi` quello che la finestra mostra: quando la pagina si ricarica (dopo
 * la segnalazione rifiutata, o un incasso registrato dall'amministratore) la finestra aperta dice lo stato di adesso e non quello di quando è stata
 * aperta. Il rifiuto della segnalazione non passa al promemoria successivo, e la X di chiusura (la via d'uscita) è quella vera della libreria.
 *
 * Il testo di un promemoria pagato dice «registrato e verificato dall'amministratore» solo se c'è un pagamento: un credito puro già saldato non ha
 * pagamenti, l'elenco mostra la sua descrizione. Lo stato «In attesa della compensazione» è l'etichetta della riga.
 *
 * I componenti sono quelli veri, anche il `Dialog` della libreria (montato nel documento con un `ResizeObserver` finto); il router di Inertia è un finto.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const post = vi.fn();
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    router: { post },
}));
(globalThis as unknown as { route: unknown }).route = (name: string) => `/${name}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const EventiList = (await import('@/components/eventi/EventiList.vue')).default;

const RIFIUTO = "Su questa rata non resta niente da versare: la compensazione di € 10,00 la registra l'amministratore. Non devi fare altro.";

const dettaglio = [
    { descrizione: 'Int. D', importo: -1000, componente_spesa: -1000, componente_saldo: 0 },
    { descrizione: 'Int. C', importo: 5000, componente_spesa: 5000, componente_saldo: 0 },
];
const ev = (id: number, meta: Record<string, unknown>, description = `Gentile Bice, ti ricordiamo la scadenza della rata condominiale n. ${id}.`) => ({
    id, title: `Scadenza rata ${id} - Preventivo`, start_time: '2026-01-05T00:00:00', visibility: 'private', description,
    categoria: { name: 'Scadenze' }, condomini: [],
    meta: { type: 'scadenza_rata_condomino', is_emitted: true, numero_rata: id, gestione: 'Ordinaria', condominio_nome: 'Condominio di prova', dettaglio_quote: dettaglio, ...meta },
});
const daVersare = (id: number) => ev(id, { status: 'pending', importo_originale: 4000, importo_pagato: 0, importo_restante: 4000, da_compensare: 1000 });
const inAttesa = (id: number) => ev(id, { status: 'partial', importo_originale: 4000, importo_pagato: 4000, importo_restante: 0, da_compensare: 1000 });

let elenco: VueWrapper | null = null;

async function monta(eventi: unknown[]) {
    elenco = mount(EventiList, { props: { eventi: eventi as never }, attachTo: document.body });
    await flushPromises();

    return elenco;
}

const normalizza = (t: string | null | undefined) => (t ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
const testo = () => normalizza(document.body.textContent);
const pulsanti = () => [...document.body.querySelectorAll('button')] as HTMLButtonElement[];
const pulsante = (frammento: string) => pulsanti().find((b) => normalizza(b.textContent).includes(frammento));
const riga = (n: number) => [...document.body.querySelectorAll('li > div')][n] as HTMLElement;
const apri = async (n: number) => {
    riga(n).click();
    await flushPromises();
    await new Promise((r) => setTimeout(r, 30));
    await flushPromises();
};
const rifiuta = async () => {
    (post.mock.calls.at(-1)![2] as { onError: (errori: Record<string, string>) => void }).onError({ evento: RIFIUTO });
    await flushPromises();
};

beforeEach(() => post.mockClear());

afterEach(() => {
    elenco?.unmount();
    elenco = null;
    document.body.innerHTML = '';
});

describe('la finestra aperta da una riga', () => {
    test('dopo il rifiuto la pagina porta il promemoria nuovo e la finestra aperta lo mostra: «In attesa della compensazione», senza il pulsante', async () => {
        const w = await monta([daVersare(1)]);
        await apri(0);
        expect(testo()).toMatch(/Totale da versare\s*€ 40,00/);
        expect(pulsante("Ho pagato l'intera rata")).toBeDefined();

        await w.setProps({ eventi: [inAttesa(1)] as never }); // Inertia ricarica la pagina dopo il redirect del rifiuto
        await flushPromises();

        expect(testo()).toContain("Non devi versare altro. L'amministratore deve ancora registrare la compensazione di € 10,00");
        expect(testo()).not.toMatch(/Totale da versare\s*€ 40,00/);
        expect(pulsante("Ho pagato l'intera rata")).toBeUndefined();
    });

    test('il rifiuto non passa al promemoria successivo: chiusa la finestra con la X e aperto un altro promemoria da versare, nessun rifiuto e il pulsante è acceso', async () => {
        await monta([daVersare(1), daVersare(2)]);
        await apri(0);
        (pulsante("Ho pagato l'intera rata") as HTMLButtonElement).click();
        await flushPromises();
        await rifiuta();
        expect(testo()).toContain(RIFIUTO);

        const x = pulsanti().find((b) => /close|chiudi/i.test(`${b.textContent} ${b.getAttribute('aria-label') ?? ''}`));
        expect(x, 'dopo il rifiuto la X non c\'è').toBeDefined();
        x!.click();
        await flushPromises();
        await new Promise((r) => setTimeout(r, 50));
        await apri(1);

        expect(testo()).toContain('Scadenza rata 2');
        expect(testo()).not.toContain('non resta niente da versare');
        expect(pulsante("Ho pagato l'intera rata")!.disabled).toBe(false);
    });
});

describe('le righe dell\'elenco', () => {
    test('K3 dopo € 40,00: l\'etichetta della riga è «In attesa della compensazione»', async () => {
        await monta([inAttesa(1)]);

        expect(testo()).toContain('In attesa della compensazione');
        expect(testo()).not.toContain('Pagato parz.');
    });

    test('un credito puro già pagato non dice «registrato e verificato dall\'amministratore»: l\'elenco mostra la sua descrizione', async () => {
        await monta([ev(1, { status: 'paid', importo_originale: -2000, importo_pagato: 0, importo_restante: -2000, da_compensare: 0 },
            "Gentile Bice, questa voce rappresenta un credito a tuo favore registrato nella rata n. 1.\n\nNon è richiesto alcun pagamento: il credito resta a tuo favore. L'amministratore può usarlo per compensare una tua quota da pagare o restituirtelo.")]);

        expect(testo()).not.toContain("registrato e verificato dall'amministratore");
        expect(testo()).toContain("il credito resta a tuo favore. L'amministratore può usarlo");
    });

    test('controprova: una rata pagata con un versamento dice, come prima, che il pagamento è stato registrato e verificato', async () => {
        await monta([ev(1, { status: 'paid', importo_originale: 4000, importo_pagato: 4000, importo_restante: 0, da_compensare: 0 })]);

        expect(testo()).toContain("Il pagamento di questa rata è stato registrato e verificato dall'amministratore.");
    });
});
