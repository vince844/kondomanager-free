// @vitest-environment jsdom

/**
 * Cosa resta scoperto: il disegno a 375 px e il tema scuro (si guardano a video); la lingua (la finestra è solo italiana, come prima); l'icona e il
 * colore dell'etichetta (hanno il test dell'etichetta, `useEventStylingBeta52.test.ts`); il funzionamento del tasto Esc e del clic fuori dalla finestra
 * (sono della libreria: qui si prova la X, che è la via d'uscita che il rifiuto indica).
 *
 * La finestra della rata nel portale del condòmino con un credito sulla stessa rata.
 *
 * Il componente è quello vero (`EventDetailsDialog.vue`) e anche il `Dialog` è quello vero (reka-ui, montato nel documento con un
 * `ResizeObserver` finto): il contenuto sta in un portale e si legge da `document.body`. Il router di Inertia è un finto che registra le chiamate.
 *
 * Il rifiuto del server alla segnalazione «Ho pagato l'intera rata» su una rata senza niente da versare (`PaymentReportingController`) arriva
 * come errore sul campo `evento`: la finestra lo riceve in `onError`, lo scrive sopra i pulsanti e li spegne tutti (anche quelli del salvadanaio, che
 * chiamano la stessa rotta). La via d'uscita è la X di chiusura: esiste dopo il rifiuto e un clic chiude la finestra. Il rifiuto non passa al
 * promemoria successivo né resta alla riapertura: si azzera quando cambia il promemoria o la finestra si chiude.
 *
 * «In attesa della compensazione» è `partial` con restante zero (o a credito) e da compensare, su una rata emessa: su una rata non ancora emessa la
 * persona non ha niente da aspettare dall'amministratore e la finestra non lo dice. Un credito puro già pagato non è «Rata saldata»: non c'è un
 * pagamento registrato, c'è un credito.
 *
 * Il netto zero su una rata non ancora emessa (debito e credito della stessa rata uguali) non ha ricevuto versamenti e non ha la compensazione:
 * la finestra non dice che l'importo è «completamente compensato dal credito applicato» e non porta «Pagato parz.»; dice che non c'è niente da
 * versare e che la compensazione la registra l'amministratore dopo l'emissione. La frase di prima resta per un promemoria senza niente da
 * compensare.
 *
 * Il rifiuto del server è un avviso annunciato (`role="alert"`) e ha il colore chiaro anche sul fondo scuro.
 *
 * Un credito puro già usato o restituito per intero (`importo_originale` negativo, `importo_restante` zero) non è più un credito a favore della persona:
 * la finestra non dice «Credito a tuo favore» né «certifica un credito di € 0,00», dice «Nessun pagamento richiesto» con la frase del credito usato o
 * restituito, e non offre i pulsanti di invio. L'etichetta resta «Pagato», perché non c'è più niente da ricevere (`useEventStylingBeta52.test.ts`). Con il credito ancora a
 * favore (restante negativo) la finestra dice «Credito a tuo favore» e la cifra del credito, come prima.
 *
 * «Già versato» è `importo_versato`, il contante dato dalla persona, e non `importo_pagato`, che un credito usato su un'altra rata o restituito riduce:
 * la colonna torna con una riga per quel credito (versato più il credito uscito dalla rata meno il pagato). Un promemoria salvato prima non ha
 * `importo_versato`: vale il pagato, come allora.
 *
 * Il riquadro dei versamenti si apre per il contante versato, per il pagato e per il credito uscito dalla rata o entrato, e ogni cifra ha la sua riga: totale
 * rata calcolato, già versato, credito usato altrove o restituito, credito applicato da un'altra quota (le due righe non nominano la rata: il credito può
 * essere di un comproprietario sulla stessa rata), credito ancora a tuo favore (il pagato supera il netto e il restante si ferma a zero: il credito di un'altra
 * quota non è stato usato, o c'è uno strapagamento), residuo da pagare.
 *
 * Un credito misto (debito e credito sulla stessa rata, netto a credito) il cui credito è stato rimborsato o usato altrove può lasciare una quota da pagare:
 * con il restante positivo la finestra dice «Totale da versare», porta il titolo rosso a scadenza passata e offre i pulsanti di invio, come per ogni rata da
 * pagare, anche se `importo_originale` è negativo. «Nessun pagamento richiesto» con la frase del credito usato o restituito vale solo con il restante a zero;
 * il riquadro «Credito a tuo favore» solo con il credito ancora a favore (restante negativo).
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

const EventDetailsDialog = (await import('@/components/eventi/EventDetailsDialog.vue')).default;

const RIFIUTO = "Su questa rata non resta niente da versare: la compensazione di € 10,00 la registra l'amministratore. Non devi fare altro.";

const dettaglio = [
    { descrizione: 'Int. D', importo: -1000, componente_spesa: -1000, componente_saldo: 0 },
    { descrizione: 'Int. C', importo: 5000, componente_spesa: 5000, componente_saldo: 0 },
];

// La rata 1 di Bice con un credito oltre il debito: C +€ 50,00 e D −€ 75,00, netto −€ 25,00.
const dettaglioMisto = [
    { descrizione: 'Int. C', importo: 5000, componente_spesa: 5000, componente_saldo: 0 },
    { descrizione: 'Int. D', importo: -7500, componente_spesa: -7500, componente_saldo: 0 },
];
// Netto zero: C +€ 50,00 e D −€ 50,00.
const dettaglioNettoZero = [
    { descrizione: 'Int. C', importo: 5000, componente_spesa: 5000, componente_saldo: 0 },
    { descrizione: 'Int. D', importo: -5000, componente_spesa: -5000, componente_saldo: 0 },
];
// Una sola quota da € 50,00 (Ugo).
const dettaglioUnaQuota = [{ descrizione: 'Int. A', importo: 5000, componente_spesa: 5000, componente_saldo: 0 }];

function evento(meta: Record<string, unknown>, description = 'Gentile Bice, ti ricordiamo la scadenza della rata condominiale n. 1.', id = 1) {
    return {
        id, title: `Scadenza rata ${id} - Preventivo`, start_time: '2026-01-05T00:00:00', visibility: 'private', description,
        meta: { type: 'scadenza_rata_condomino', is_emitted: true, numero_rata: id, gestione: 'Ordinaria', condominio_nome: 'Condominio di prova', dettaglio_quote: dettaglio, ...meta },
    };
}

const daVersare = (id = 1, extra: Record<string, unknown> = {}) => evento({ status: 'pending', importo_originale: 4000, importo_pagato: 0, importo_restante: 4000, da_compensare: 1000, ...extra }, undefined, id);
const inAttesa = (id = 1) => evento({ status: 'partial', importo_originale: 4000, importo_pagato: 4000, importo_restante: 0, da_compensare: 1000 }, undefined, id);

let finestra: VueWrapper | null = null;

async function monta(e: unknown) {
    finestra = mount(EventDetailsDialog, { props: { isOpen: true, evento: e }, attachTo: document.body });
    await flushPromises();
    await new Promise((r) => setTimeout(r, 30));
    await flushPromises();

    return finestra;
}

const normalizza = (t: string | null | undefined) => (t ?? '').replace(/ /g, ' ').replace(/\s+/g, ' ').trim();
const testo = () => normalizza(document.body.textContent);
const pulsanti = () => [...document.body.querySelectorAll('button')] as HTMLButtonElement[];
const pulsante = (frammento: string) => pulsanti().find((b) => normalizza(b.textContent).includes(frammento));
const laX = () => pulsanti().find((b) => /close|chiudi/i.test(`${b.textContent} ${b.getAttribute('aria-label') ?? ''}`));
const pulsantiDiInvio = () => pulsanti().filter((b) => /Ho pagato|Sì, salda/.test(normalizza(b.textContent)));
const clicca = async (b: HTMLButtonElement | undefined) => {
    expect(b, 'il pulsante non c\'è').toBeDefined();
    b!.click();
    await flushPromises();
};
const rifiuta = async (messaggio = RIFIUTO) => {
    const opzioni = post.mock.calls.at(-1)![2] as { onError: (errori: Record<string, string>) => void };
    opzioni.onError({ evento: messaggio });
    await flushPromises();
};

beforeEach(() => post.mockClear());

afterEach(() => {
    finestra?.unmount();
    finestra = null;
    document.body.innerHTML = '';
});

describe('rata con un credito sulla stessa rata', () => {
    test('K3 dopo € 40,00 su C: «In attesa della compensazione», nessun pulsante, le cifre tornano (40 − 40 = 0)', async () => {
        await monta(inAttesa());
        const t = testo();

        expect(t).toContain('In attesa della compensazione');
        expect(t).toContain("Non devi versare altro. L'amministratore deve ancora registrare la compensazione di € 10,00 fra una tua quota da pagare e un tuo credito.");
        expect(t).not.toContain('completamente compensato dal credito applicato'); // non è vero: il credito non è applicato
        expect(pulsante("Ho pagato l'intera rata")).toBeUndefined();
        expect(t).toMatch(/Totale rata calcolato\s*€ 40,00/);
        expect(t).toMatch(/Residuo da pagare\s*€ 0,00/);
        expect(t).not.toContain('Credito ancora a tuo favore'); // il credito di € 10,00 è quello che la compensazione sta per usare: la colonna torna senza
    });

    test('controprova: K3 prima dell\'incasso: da versare € 40,00 e il pulsante c\'è e parte', async () => {
        await monta(daVersare());

        expect(testo()).toMatch(/Totale da versare\s*€ 40,00/);
        expect(testo()).not.toContain('In attesa della compensazione');
        expect(pulsante("Ho pagato l'intera rata")!.disabled).toBe(false);
        await clicca(pulsante("Ho pagato l'intera rata"));
        expect(post).toHaveBeenCalledTimes(1);
    });

    test('controprova: pagato meno del netto, € 20,00: restano € 20,00 da versare, il pulsante c\'è e la finestra non dice «in attesa»', async () => {
        await monta(evento({ status: 'partial', importo_originale: 4000, importo_pagato: 2000, importo_restante: 2000, da_compensare: 1000 }));
        const t = testo();

        expect(t).toMatch(/Residuo da pagare\s*€ 20,00/);
        expect(pulsante("Ho pagato l'intera rata")).toBeDefined();
        expect(t).not.toContain('In attesa della compensazione');
    });

    test('credito oltre il debito, rata emessa: la finestra dice «in attesa della compensazione» al posto di «Credito a tuo favore»', async () => {
        await monta(evento({ status: 'partial', importo_originale: -5000, importo_pagato: 0, importo_restante: -5000, da_compensare: 5000 }, 'Gentile Bice, per la rata n. 1 non devi versare altro.'));
        const t = testo();

        expect(t).toContain('In attesa della compensazione');
        expect(t).toContain('compensazione di € 50,00');
        expect(t).not.toContain('Questo documento certifica un credito a tuo favore');
        expect(pulsante("Ho pagato l'intera rata")).toBeUndefined();
    });

    test('controprova: credito oltre il debito su una rata non ancora emessa: la finestra non dice «deve ancora registrare la compensazione» (non c\'è niente da aspettare)', async () => {
        await monta(evento({ status: 'partial', is_emitted: false, importo_originale: -5000, importo_pagato: 0, importo_restante: -5000, da_compensare: 5000 }, 'Gentile Bice, per la rata n. 1 non devi versare altro.'));
        const t = testo();

        expect(t).not.toContain('deve ancora registrare la compensazione');
        expect(t).not.toContain('In attesa della compensazione');
    });

    test('controprova: dopo «Usa credito»: rata pagata, già versato € 40,00 (non € 50,00), «Rata Saldata»', async () => {
        await monta(evento({ status: 'paid', importo_originale: 4000, importo_pagato: 4000, importo_restante: 0, da_compensare: 0 }));
        const t = testo();

        expect(t).toContain('Rata Saldata');
        expect(t).not.toContain('In attesa della compensazione');
        expect(t).toMatch(/Già versato\s*- € 40,00/);
        expect(t).not.toMatch(/Già versato\s*- € 50,00/);
    });

    test('un credito puro già pagato non è «Rata Saldata»: nessun pagamento registrato e validato, c\'è un credito a favore della persona', async () => {
        await monta(evento({ status: 'paid', importo_originale: -2000, importo_pagato: 0, importo_restante: -2000, da_compensare: 0 }, 'Gentile Bice, questa voce rappresenta un credito a tuo favore registrato nella rata n. 1.'));
        const t = testo();

        expect(t).not.toContain('Rata Saldata');
        expect(t).not.toContain("registrato e validato dall'amministratore");
        expect(t).toContain('Credito a tuo favore');
        expect(t).not.toContain('In attesa della compensazione');
    });

    test('netto zero su una rata non ancora emessa: non «compensato dal credito applicato», e non «Pagato parz.»; la compensazione la registra l\'amministratore dopo l\'emissione', async () => {
        await monta(evento({ status: 'partial', is_emitted: false, importo_originale: 0, importo_pagato: 0, importo_restante: 0, da_compensare: 5000 },
            'Gentile Bice, per la rata n. 1 non devi versare altro. Restano € 50,00 di una tua quota da compensare con un tuo credito su un\'altra tua quota di questa rata: la compensazione la registra l\'amministratore.'));
        const t = testo();

        expect(t).not.toContain('completamente compensato dal credito applicato');
        expect(t).not.toContain('Pagato parz.');
        expect(t).not.toContain('Nessun pagamento richiesto');
        expect(t).toContain('Da compensare');
        expect(t).toContain("Non devi versare altro. Dopo l'emissione della rata, la compensazione di € 50,00 fra una tua quota da pagare e un tuo credito la registra l'amministratore.");
        expect(pulsante("Ho pagato l'intera rata")).toBeUndefined();
    });

    test('controprova: la stessa rata emessa dice «In attesa della compensazione», con la frase dell\'attesa', async () => {
        await monta(evento({ status: 'partial', is_emitted: true, importo_originale: 0, importo_pagato: 0, importo_restante: 0, da_compensare: 5000 }));
        const t = testo();

        expect(t).toContain('In attesa della compensazione');
        expect(t).toContain("L'amministratore deve ancora registrare la compensazione di € 50,00");
        expect(t).not.toContain("Dopo l'emissione della rata");
    });

    test('controprova: un promemoria di prima, a netto zero e senza niente da compensare, dice ancora che l\'importo è compensato', async () => {
        await monta(evento({ status: 'pending', is_emitted: false, importo_originale: 0, importo_pagato: 0, importo_restante: 0 }));
        const t = testo();

        expect(t).toContain('Nessun pagamento richiesto');
        expect(t).toContain("L'importo è stato completamente compensato dal credito applicato.");
        expect(t).not.toContain("Dopo l'emissione della rata");
    });

    test('un promemoria con una quota da compensare che non è «partial» (dato incoerente) non dice mai «compensato dal credito applicato»', async () => {
        await monta(evento({ status: 'pending', is_emitted: false, importo_originale: 0, importo_pagato: 0, importo_restante: 0, da_compensare: 5000 }));

        expect(testo()).not.toContain('completamente compensato dal credito applicato');
        expect(testo()).not.toContain('Nessun pagamento richiesto');
    });

    test('controprova: un promemoria salvato prima della .52, senza da_compensare, si legge come prima', async () => {
        await monta(evento({ status: 'partial', importo_originale: 4000, importo_pagato: 4000, importo_restante: 1000 }));

        expect(testo()).toMatch(/Residuo da pagare\s*€ 10,00/);
        expect(testo()).not.toContain('In attesa della compensazione');
    });
});

describe('il rifiuto del server alla segnalazione', () => {
    test('si legge nella finestra e il pulsante si spegne', async () => {
        await monta(daVersare());

        await clicca(pulsante("Ho pagato l'intera rata"));
        await rifiuta();

        expect(testo()).toContain(RIFIUTO);
        expect(pulsante("Ho pagato l'intera rata")!.disabled).toBe(true);
    });

    test('il rifiuto è un avviso annunciato e ha il colore chiaro sul fondo scuro', async () => {
        await monta(daVersare());
        await clicca(pulsante("Ho pagato l'intera rata"));
        await rifiuta();

        const avviso = [...document.body.querySelectorAll('[role="alert"]')].find((n) => normalizza(n.textContent) === RIFIUTO);
        expect(avviso, 'il rifiuto non è un avviso annunciato').toBeDefined();
        expect(avviso!.className).toContain('text-red-700');
        expect(avviso!.className).toContain('dark:text-red-400');
    });

    test('controprova: la via d\'uscita c\'è: dopo il rifiuto la X di chiusura esiste, è cliccabile e un clic chiude la finestra', async () => {
        const w = await monta(daVersare());
        await clicca(pulsante("Ho pagato l'intera rata"));
        await rifiuta();

        const x = laX();
        expect(x, 'dopo il rifiuto la X non c\'è').toBeDefined();
        expect(x!.disabled).toBe(false);
        await clicca(x);

        expect(w.emitted('close')).toHaveLength(1);
    });

    test('con il credito del salvadanaio, dopo il rifiuto tutti i pulsanti di invio sono spenti, non solo quello cliccato', async () => {
        await monta(daVersare(1, { credito_rata_zero: 3000 }));
        expect(pulsante('Ho pagato la differenza')!.disabled).toBe(false);
        expect(pulsante("Ho pagato l'intera rata")!.disabled).toBe(false);

        await clicca(pulsante("Ho pagato l'intera rata"));
        await rifiuta();

        const invio = pulsanti().filter((b) => /Ho pagato|Sì, salda/.test(normalizza(b.textContent)));
        expect(invio.map((b) => normalizza(b.textContent).startsWith('Ho pagato la differenza'))).toEqual([true, false]); // i due pulsanti: la differenza e l'intera rata
        expect(invio.map((b) => b.disabled)).toEqual([true, true]);
    });

    test('con un credito che copre l\'intera rata il pulsante «Sì, salda la rata con il credito» si spegne dopo il rifiuto', async () => {
        await monta(daVersare(1, { credito_rata_zero: 5000 }));

        await clicca(pulsante('Sì, salda la rata con il credito'));
        await rifiuta();

        expect(pulsante('Sì, salda la rata con il credito')!.disabled).toBe(true);
    });

    test('il rifiuto non passa al promemoria successivo: cambiando promemoria il testo sparisce e il pulsante è acceso', async () => {
        const w = await monta(daVersare(1));
        await clicca(pulsante("Ho pagato l'intera rata"));
        await rifiuta();
        expect(testo()).toContain(RIFIUTO);

        await w.setProps({ evento: daVersare(2) }); // stessa istanza della finestra, un altro promemoria
        await flushPromises();

        expect(testo()).not.toContain('non resta niente da versare');
        expect(pulsante("Ho pagato l'intera rata")!.disabled).toBe(false);
    });

    test('controprova: il rifiuto non resta alla riapertura: chiusa e riaperta sullo stesso promemoria la finestra riparte pulita', async () => {
        const w = await monta(daVersare(1));
        await clicca(pulsante("Ho pagato l'intera rata"));
        await rifiuta();

        await w.setProps({ isOpen: false });
        await flushPromises();
        await w.setProps({ isOpen: true });
        await flushPromises();
        await new Promise((r) => setTimeout(r, 30));

        expect(testo()).not.toContain('non resta niente da versare');
        expect(pulsante("Ho pagato l'intera rata")!.disabled).toBe(false);
    });

    test('quando arriva il promemoria nuovo (la pagina si ricarica dopo il rifiuto) la finestra aperta dice «In attesa della compensazione» e non offre più il pulsante', async () => {
        const w = await monta(daVersare(1));
        await clicca(pulsante("Ho pagato l'intera rata"));
        await rifiuta();

        await w.setProps({ evento: inAttesa(1) });
        await flushPromises();

        expect(testo()).toContain('In attesa della compensazione');
        expect(pulsante("Ho pagato l'intera rata")).toBeUndefined();
    });
});

describe('credito puro già usato o restituito', () => {
    // lo stato che il server scrive dopo il rimborso per intero di un credito puro di € 20,00
    const restituito = () => evento({ status: 'paid', importo_originale: -2000, importo_pagato: -2000, importo_versato: 0, importo_restante: 0, da_compensare: 0 },
        'Gentile Bice, per la rata n. 1 non resta niente da versare né da ricevere: il credito è stato usato o restituito.');

    test('non dice «Credito a tuo favore» né «certifica un credito di € 0,00»: dice che non c\'è niente da versare né da ricevere', async () => {
        await monta(restituito());
        const t = testo();

        expect(t).not.toContain('Credito a tuo favore');
        expect(t).not.toContain('certifica un credito');
        expect(t).not.toMatch(/Credito di questa rata\s*€ 0,00/);
        expect(t).toContain('Nessun pagamento richiesto');
        expect(t).toContain('Il credito di questa rata è stato usato o restituito: non resta niente da versare né da ricevere.');
        expect(t).not.toContain('completamente compensato dal credito applicato'); // non è vero: il credito è stato usato o restituito, non applicato a una quota
        expect(t).toMatch(/Totale da versare\s*€ 0,00/);
    });

    test('non offre nessun pulsante di invio (restringere solo la condizione del riquadro li farebbe comparire)', async () => {
        await monta(restituito());

        expect(pulsanti().filter((b) => /Ho pagato|Sì, salda/.test(normalizza(b.textContent)))).toHaveLength(0);
        expect(testo()).not.toContain('Totale rata corrente');
    });

    test('controprova: restituito in parte (restante −€ 15,00) dice ancora «Credito a tuo favore» con la cifra del credito', async () => {
        await monta(evento({ status: 'paid', importo_originale: -2000, importo_pagato: -500, importo_restante: -1500, da_compensare: 0 }, 'Gentile Bice, questa voce rappresenta un credito a tuo favore registrato nella rata n. 1.'));
        const t = testo();

        expect(t).toContain('Credito a tuo favore');
        expect(t).toContain('certifica un credito a tuo favore di € 15,00');
        expect(t).toMatch(/Credito di questa rata\s*€ -15,00/);
        expect(t).not.toContain('Nessun pagamento richiesto');
    });

    test('controprova: il credito intero ancora lì (restante −€ 20,00) dice «Credito a tuo favore» di € 20,00', async () => {
        await monta(evento({ status: 'paid', importo_originale: -2000, importo_pagato: 0, importo_restante: -2000, da_compensare: 0 }, 'Gentile Bice, questa voce rappresenta un credito a tuo favore registrato nella rata n. 1.'));

        expect(testo()).toContain('certifica un credito a tuo favore di € 20,00');
        expect(testo()).not.toContain('Nessun pagamento richiesto');
    });

    test('controprova: il credito oltre il debito, in attesa della compensazione, resta «In attesa della compensazione» (non il credito usato)', async () => {
        await monta(evento({ status: 'partial', importo_originale: -5000, importo_pagato: 0, importo_restante: -5000, da_compensare: 5000 }));

        expect(testo()).toContain('In attesa della compensazione');
        expect(testo()).not.toContain('è stato usato o restituito');
    });
});

describe('«Già versato» è il contante, e la colonna torna', () => {
    test('K3, € 5,00 del credito rimborsati e € 20,00 versati: «Già versato − € 20,00», il credito uscito + € 5,00 e il residuo di € 25,00', async () => {
        await monta(evento({ status: 'partial', importo_originale: 4000, importo_pagato: 1500, importo_versato: 2000, importo_restante: 2500, da_compensare: 500 },
            'Gentile Bice, per la rata n. 1 hai versato € 20,00 e restano € 25,00 da versare.'));
        const t = testo();

        expect(t).toMatch(/Totale rata calcolato\s*€ 40,00/);
        expect(t).toMatch(/Già versato\s*- € 20,00/);
        expect(t).not.toMatch(/Già versato\s*- € 15,00/);
        expect(t).toMatch(/Credito usato altrove o restituito\s*\+ € 5,00/);
        expect(t).toMatch(/Residuo da pagare\s*€ 25,00/);
    });

    test('€ 40,00 versati e poi il credito di € 10,00 rimborsato: «Già versato − € 40,00», non € 30,00, e il credito uscito + € 10,00', async () => {
        await monta(evento({ status: 'partial', importo_originale: 4000, importo_pagato: 3000, importo_versato: 4000, importo_restante: 1000, da_compensare: 0 }));
        const t = testo();

        expect(t).toMatch(/Già versato\s*- € 40,00/);
        expect(t).not.toMatch(/Già versato\s*- € 30,00/);
        expect(t).toMatch(/Credito usato altrove o restituito\s*\+ € 10,00/);
        expect(t).toMatch(/Residuo da pagare\s*€ 10,00/);
    });

    test('controprova: senza credito uscito dalla rata (versato = pagato) la riga del credito non c\'è', async () => {
        await monta(evento({ status: 'partial', importo_originale: 4000, importo_pagato: 2000, importo_versato: 2000, importo_restante: 2000, da_compensare: 1000 }));
        const t = testo();

        expect(t).toMatch(/Già versato\s*- € 20,00/);
        expect(t).not.toContain("Credito usato altrove o restituito");
        expect(t).not.toContain("Credito applicato da un'altra quota");
    });

    test('controprova: un promemoria salvato prima, senza `importo_versato`, legge il pagato: «Già versato − € 40,00» e nessuna riga del credito', async () => {
        await monta(evento({ status: 'paid', importo_originale: 4000, importo_pagato: 4000, importo_restante: 0, da_compensare: 0 }));
        const t = testo();

        expect(t).toMatch(/Già versato\s*- € 40,00/);
        expect(t).not.toContain("Credito usato altrove o restituito");
    });

    test('il credito di un\'altra rata usato su questa rata (pagato € 10,00, versato zero) non è «Già versato»: la riga dice che il credito è stato applicato', async () => {
        await monta(evento({ status: 'pending', importo_originale: 5000, importo_pagato: 1000, importo_versato: 0, importo_restante: 4000, da_compensare: 0 }));
        const t = testo();

        expect(t).not.toContain('Già versato');
        expect(t).toMatch(/Credito applicato da un'altra quota\s*- € 10,00/);
        expect(t).toMatch(/Residuo da pagare\s*€ 40,00/);
    });

    test('controprova: senza versato e senza pagato (rata da versare per intero) il riquadro dei versamenti non c\'è', async () => {
        await monta(daVersare());

        expect(testo()).not.toContain('Già versato');
        expect(testo()).not.toContain('Residuo da pagare');
    });
});

describe('credito misto rimborsato oltre il netto: la quota da pagare resta da versare', () => {
    const misto = (meta: Record<string, unknown>, description?: string) =>
        evento({ status: 'pending', importo_originale: -2500, importo_versato: 0, da_compensare: 0, dettaglio_quote: dettaglioMisto, ...meta }, description);

    test('il credito di D rimborsato per intero, restano € 50,00 da versare: «Totale da versare», il pulsante c\'è e la colonna torna (−25 + 75 = 50)', async () => {
        await monta(misto({ importo_pagato: -7500, importo_restante: 5000 }));
        const t = testo();

        expect(t).toMatch(/Totale da versare\s*€ 50,00/);
        expect(t).not.toContain('Nessun pagamento richiesto');
        expect(t).not.toContain('non resta niente da versare né da ricevere');
        expect(t).not.toContain('Credito a tuo favore');
        expect(t).not.toContain('Già versato');
        expect(t).toMatch(/Totale rata calcolato\s*€ -25,00/);
        expect(t).toMatch(/Credito usato altrove o restituito\s*\+ € 75,00/);
        expect(t).toMatch(/Residuo da pagare\s*€ 50,00/);
        expect(pulsante("Ho pagato l'intera rata")!.disabled).toBe(false);
    });

    test('con la scadenza passata il titolo ha l\'allarme rosso: c\'è un debito residuo anche se il netto è a credito', async () => {
        await monta({ ...misto({ importo_pagato: -7500, importo_restante: 5000 }), start_time: '2020-01-05T00:00:00' });
        const titolo = [...document.body.querySelectorAll('h2')].find((h) => normalizza(h.textContent).includes('Scadenza rata 1'));

        expect(titolo, 'il titolo non c\'è').toBeDefined();
        expect(titolo!.className).toContain('text-red-600');
    });

    test('rimborso di € 40,00 (restano € 15,00 da versare e € 35,00 da compensare): «Totale da versare € 15,00», non «Non devi versare altro»', async () => {
        await monta(misto({ importo_pagato: -4000, importo_restante: 1500, da_compensare: 3500 }));
        const t = testo();

        expect(t).toMatch(/Totale da versare\s*€ 15,00/);
        expect(t).not.toContain('In attesa della compensazione');
        expect(t).not.toContain('Non devi versare altro');
        expect(pulsante("Ho pagato l'intera rata")).toBeDefined();
    });

    test('netto zero (C +€ 50,00 e D −€ 50,00) e il credito di D rimborsato: restano € 50,00 da versare, non «completamente compensato dal credito applicato»', async () => {
        await monta(evento({ status: 'pending', importo_originale: 0, importo_pagato: -5000, importo_versato: 0, importo_restante: 5000, da_compensare: 0, dettaglio_quote: dettaglioNettoZero },
            'Gentile Bice, ti ricordiamo la scadenza della rata condominiale n. 1.'));
        const t = testo();

        expect(t).toMatch(/Totale da versare\s*€ 50,00/);
        expect(t).not.toContain('completamente compensato dal credito applicato');
        expect(t).not.toContain('Nessun pagamento richiesto');
        expect(pulsante("Ho pagato l'intera rata")).toBeDefined();
    });

    test('anche un solo centesimo da versare riapre il pagamento: il ramo del credito usato vale solo con il restante a zero', async () => {
        await monta(misto({ importo_pagato: -7500, importo_restante: 1 }));
        const t = testo();

        expect(t).not.toContain('Nessun pagamento richiesto');
        expect(t).toMatch(/Totale da versare\s*€ 0,01/);
        expect(pulsante("Ho pagato l'intera rata")).toBeDefined();
    });

    test('controprova: dopo il rimborso e il versamento di C (restante zero) la finestra dice che non c\'è niente da versare né da ricevere, e nessun pulsante', async () => {
        await monta(misto({ status: 'paid', importo_pagato: -2500, importo_versato: 5000, importo_restante: 0 }));
        const t = testo();

        expect(t).toContain('Nessun pagamento richiesto');
        expect(t).toContain('Il credito di questa rata è stato usato o restituito: non resta niente da versare né da ricevere.');
        expect(pulsantiDiInvio()).toHaveLength(0);
    });

    test('controprova: il misto con il credito rimborsato solo in parte (restante −€ 15,00) dice ancora «Credito a tuo favore» di € 15,00', async () => {
        await monta(misto({ status: 'paid', importo_pagato: -1000, importo_restante: -1500 }, 'Gentile Bice, questa voce rappresenta un credito a tuo favore registrato nella rata n. 1.'));
        const t = testo();

        expect(t).toContain('Credito a tuo favore');
        expect(t).toContain('certifica un credito a tuo favore di € 15,00');
        expect(t).not.toContain('Nessun pagamento richiesto');
        expect(pulsantiDiInvio()).toHaveLength(0);
    });
});

describe('la colonna dei versamenti torna sempre', () => {
    test('credito uscito dalla rata e niente versato (€ 5,00 del credito di D rimborsati): il riquadro c\'è, la riga spiega i € 5,00 e la colonna torna (40 + 5 = 45)', async () => {
        await monta(evento({ status: 'pending', importo_originale: 4000, importo_pagato: -500, importo_versato: 0, importo_restante: 4500, da_compensare: 500 }));
        const t = testo();

        expect(t).toMatch(/Totale da versare\s*€ 45,00/);
        expect(t).toMatch(/Totale rata calcolato\s*€ 40,00/);
        expect(t).toMatch(/Credito usato altrove o restituito\s*\+ € 5,00/);
        expect(t).toMatch(/Residuo da pagare\s*€ 45,00/);
        expect(t).not.toContain('Già versato');
    });

    test('contante versato con il pagato netto negativo (€ 5,00 rimborsati e poi € 1,00 su C): il riquadro si apre per il contante, «Già versato − € 1,00», la colonna torna (40 − 1 + 5 = 44)', async () => {
        await monta(evento({ status: 'partial', importo_originale: 4000, importo_pagato: -400, importo_versato: 100, importo_restante: 4400, da_compensare: 500 }));
        const t = testo();

        expect(t).toMatch(/Già versato\s*- € 1,00/);
        expect(t).toMatch(/Credito usato altrove o restituito\s*\+ € 5,00/);
        expect(t).toMatch(/Residuo da pagare\s*€ 44,00/);
    });

    test('contante versato con il pagato netto negativo anche a netto a credito (D rimborsato e poi C versato): «Già versato − € 50,00», il credito uscito + € 75,00 e il residuo zero (−25 − 50 + 75 = 0)', async () => {
        await monta(evento({ status: 'paid', importo_originale: -2500, importo_pagato: -2500, importo_versato: 5000, importo_restante: 0, da_compensare: 0, dettaglio_quote: dettaglioMisto }));
        const t = testo();

        expect(t).toMatch(/Totale rata calcolato\s*€ -25,00/);
        expect(t).toMatch(/Già versato\s*- € 50,00/);
        expect(t).toMatch(/Credito usato altrove o restituito\s*\+ € 75,00/);
        expect(t).toMatch(/Residuo da pagare\s*€ 0,00/);
    });

    test('un comproprietario usa il credito di Ugo sulla stessa rata: per Bice «Credito applicato da un\'altra quota − € 10,00», che non nomina un\'altra rata', async () => {
        await monta(evento({ status: 'pending', importo_originale: 4000, importo_pagato: 1000, importo_versato: 0, importo_restante: 3000, da_compensare: 0 }));
        const t = testo();

        expect(t).toMatch(/Credito applicato da un'altra quota\s*- € 10,00/);
        expect(t).toMatch(/Residuo da pagare\s*€ 30,00/);
        expect(t).not.toContain('un\'altra rata');
    });

    test('un comproprietario usa il credito di Ugo sulla stessa rata: per Ugo «Credito usato altrove o restituito + € 10,00», che non nomina un\'altra rata', async () => {
        await monta(evento({ status: 'paid', importo_originale: 5000, importo_pagato: 5000, importo_versato: 6000, importo_restante: 0, da_compensare: 0, dettaglio_quote: dettaglioUnaQuota }));
        const t = testo();

        expect(t).toMatch(/Già versato\s*- € 60,00/);
        expect(t).toMatch(/Credito usato altrove o restituito\s*\+ € 10,00/);
        expect(t).toMatch(/Residuo da pagare\s*€ 0,00/);
        expect(t).not.toContain('un\'altra rata');
        expect(t).not.toContain('Credito ancora a tuo favore');
    });

    test('K3, € 50,00 versati su C senza usare il credito di D: «Credito ancora a tuo favore + € 10,00» e la colonna torna (40 − 50 + 10 = 0)', async () => {
        await monta(evento({ status: 'paid', importo_originale: 4000, importo_pagato: 5000, importo_versato: 5000, importo_restante: 0, da_compensare: 0 }));
        const t = testo();

        expect(t).toMatch(/Totale rata calcolato\s*€ 40,00/);
        expect(t).toMatch(/Già versato\s*- € 50,00/);
        expect(t).toMatch(/Credito ancora a tuo favore\s*\+ € 10,00/);
        expect(t).toMatch(/Residuo da pagare\s*€ 0,00/);
    });

    test('uno strapagamento di € 10,00 su una quota da € 50,00: «Credito ancora a tuo favore + € 10,00», la colonna torna (50 − 60 + 10 = 0)', async () => {
        await monta(evento({ status: 'paid', importo_originale: 5000, importo_pagato: 6000, importo_versato: 6000, importo_restante: 0, da_compensare: 0, dettaglio_quote: dettaglioUnaQuota }));
        const t = testo();

        expect(t).toMatch(/Già versato\s*- € 60,00/);
        expect(t).toMatch(/Credito ancora a tuo favore\s*\+ € 10,00/);
        expect(t).toMatch(/Residuo da pagare\s*€ 0,00/);
    });

    test('un promemoria salvato prima, senza il versato: il pagato delle sole quote positive non dice un credito rimasto (il credito di D è già usato su C)', async () => {
        await monta(evento({ status: 'paid', importo_originale: 4000, importo_pagato: 5000, importo_restante: 0 }));
        const t = testo();

        expect(t).not.toContain('Credito ancora a tuo favore');
        expect(t).toMatch(/Residuo da pagare\s*€ 0,00/);
    });

    test('controprova: senza credito rimasto (pagato uguale al netto) la riga «Credito ancora a tuo favore» non c\'è', async () => {
        await monta(evento({ status: 'paid', importo_originale: 4000, importo_pagato: 4000, importo_versato: 4000, importo_restante: 0, da_compensare: 0 }));

        expect(testo()).not.toContain('Credito ancora a tuo favore');
    });

    test('controprova: con un residuo ancora da versare (pagato meno del netto) la riga «Credito ancora a tuo favore» non c\'è', async () => {
        await monta(evento({ status: 'partial', importo_originale: 4000, importo_pagato: 2000, importo_versato: 2000, importo_restante: 2000, da_compensare: 1000 }));

        expect(testo()).not.toContain('Credito ancora a tuo favore');
    });
});
