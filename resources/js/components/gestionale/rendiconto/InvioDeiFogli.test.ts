// @vitest-environment jsdom

/**
 * «Invia per email» dei fogli del conguaglio: la finestra parte dall'ultimo oggetto, dagli ultimi indirizzi e dall'ultimo modello scelto (un modello
 * che non c'è più lo dice); la frase della proposta e l'ora del calcolo stanno fuori dal testo; «Salva come modello…» salva un modello dell'email dei
 * fogli. L'anteprima porta le scelte del riquadro e il modello, e mostra il messaggio della prima persona, gli indirizzi, chi non ne ha uno con il
 * suo foglio da scaricare, chi ha un foglio «non disponibile», il tetto contato sulle persone rimaste. L'invio porta le persone viste e rimaste e
 * tutte quelle dell'anteprima; dopo, il pulsante si spegne e la scheda rilegge lo stato dal server; «Invia le altre» manda chi resta. Una risposta
 * che non arriva non dice «non sono partiti». Accanto alla persona, l'ultimo invio e «Invia di nuovo, con le cifre di oggi»; una pagina nuova torna
 * ai suoi dati. I fogli in coda da più di un'ora si dicono.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
const axiosPut = vi.fn();
const pagina: { props: Record<string, any> } = { props: {} };
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a), put: (...a: unknown[]) => axiosPut(...a) } }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => pagina }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p: Record<string, unknown> = {}) =>
    `/${nome}${p.persona ? `/${p.persona}` : ''}${p.invio ? `/${p.invio}` : ''}${p.modello ? `/${p.modello}` : ''}`;

const InvioDeiFogli = (await import('./InvioDeiFogli.vue')).default;
const UltimoInvioDelFoglio = (await import('./UltimoInvioDelFoglio.vue')).default;
const { dimenticaStato, FRASE_ESITO_INCERTO } = await import('@/lib/gestionale/rendiconto/invioDeiFogli');

const PROPOSTA = 'È una proposta di conguaglio, da approvare dall\'assemblea: non è una richiesta di pagamento.';
const opzioni = (extra: Record<string, unknown> = {}) => ({
    oggetto: '[condominio] — il foglio', oggetto_di_serie: '[condominio] — Consuntivo e proposta di conguaglio, [esercizio]', testo_di_serie: 'In allegato il suo foglio.',
    modelli: [{ id: 21, tipo: 'foglio_conguaglio', nome: 'Foglio cortese', testo: 'Ecco il suo foglio, [nome].', per_tutti: false },
        { id: 22, tipo: 'foglio_conguaglio', nome: 'Per tutti', testo: 'Il foglio di [esercizio].', per_tutti: true }],
    modello_ricordato: { id: 21, nome: 'Foglio cortese', mancante: false }, puo_salvare_il_testo: true, frase_proposta: PROPOSTA,
    campi: {}, indirizzi: ['email', 'pec'], senza_server_posta: false, tetto_messaggi: 50, tetto_fogli: 200, ...extra,
});
const persona = (id: number, nome: string, extra: Record<string, unknown> = {}) => ({
    id, nome, indirizzi: [{ tipo: 'email', indirizzo: `${nome.toLowerCase()}@prova.it` }], senza_email: false, solo_pec: false, ultimo: null, ...extra,
});
const anteprima = (extra: Record<string, unknown> = {}) => ({
    persone: [persona(2, 'Bianchi'), persona(1, 'Neri', { indirizzi: [{ tipo: 'email', indirizzo: 'neri@prova.it' }, { tipo: 'pec', indirizzo: 'neri@pec.it' }] }),
        persona(4, 'Verdi', { indirizzi: [], senza_email: true, solo_pec: false, non_disponibile: true })],
    messaggi: 3, senza_indirizzo: ['Verdi'], non_disponibili: 1, partono: { persone: 2, messaggi: 3 }, tetto_messaggi: 50, tetto_fogli: 200,
    data: { frase: 'Alla fine dell\'esercizio, il 31/12/2026; calcolato il 10/01/2027 alle 11:00.', calcolato_il: '10/01/2027 alle 11:00' },
    esempio: { persona: 'Bianchi', oggetto: 'Via Verdi — il foglio', saluto: 'Gentile Bianchi,', righe: ['Ecco il suo foglio, Bianchi.', PROPOSTA,
        'Le cifre del foglio sono alla fine dell\'esercizio, il 31/12/2026: calcolate il 10/01/2027 alle 11:00.'], chiusura: 'Cordiali saluti, Via Verdi' },
    senza_server_posta: false,
    ...extra,
});
const riepilogo = { persone: 2, messaggi: 3, inviate: 0, in_coda: 3, non_partite: 0, errori: [], senza_indirizzo: ['Verdi'], restanti: null, aggiunte: 0, uscite: 0 };
const ultimo = (stato: string, extra: Record<string, unknown> = {}) => ({ quando: '2027-01-10 11:00', stato, righe: [], da_rimandare: false, pdf: 9, ...extra });
const paginaDellaScheda = (invio: Record<string, unknown> = {}) => ({
    invio_fogli: { ultimi: {}, da_rimandare: 0, in_coda_da_tempo: 0, ...invio },
    conguaglio: { righe: [{ anagrafica_id: 1, subtotale: false }, { anagrafica_id: 2, subtotale: false }, { anagrafica_id: 1, subtotale: true }] },
});

let w: VueWrapper | null = null;
let w2: VueWrapper | null = null;
const el = (id: string) => document.body.querySelector(`[data-test="${id}"]`) as HTMLElement | null;
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const ultimaChiamata = () => axiosPost.mock.calls.at(-1) as [string, Record<string, any>];
const monta = () => {
    w = mount(InvioDeiFogli, { attachTo: document.body, props: { condominioId: 3, esercizioId: 9, chi: 'a_debito', pagina: 2, quante: 3 } });
};

async function apri(o = opzioni()) {
    axiosGet.mockResolvedValueOnce({ data: o });
    monta();
    el('invia-per-email')!.click();
    await flushPromises();
}
async function conAnteprima(dati = anteprima()) {
    await apri();
    axiosPost.mockResolvedValueOnce({ data: dati });
    el('anteprima')!.click();
    await flushPromises();
}

beforeEach(() => {
    axiosGet.mockReset();
    axiosPost.mockReset();
    axiosPut.mockReset();
    dimenticaStato();
    pagina.props = paginaDellaScheda();
});
afterEach(() => {
    w?.unmount();
    w2?.unmount();
    w = null;
    w2 = null;
    document.body.innerHTML = '';
});

describe('la finestra', () => {
    test('parte dall\'ultimo oggetto, dagli ultimi indirizzi e dall\'ultimo modello; la frase della proposta e l\'ora del calcolo sono fuori dal testo', async () => {
        await apri();
        expect((el('oggetto') as HTMLInputElement).value).toBe('[condominio] — il foglio');
        expect((el('modello') as HTMLSelectElement).value).toBe('21');
        expect((el('testo') as HTMLTextAreaElement).value).toBe('Ecco il suo foglio, [nome].');
        expect(el('frase-fissa')!.textContent).toContain(PROPOSTA);
        expect(el('frase-fissa')!.textContent).toContain('la data e l\'ora del calcolo');
        // Il menu: il testo del programma, poi i modelli del condominio, poi quelli per tutti.
        await (async () => {
            const s = el('modello') as HTMLSelectElement;
            s.value = '';
            s.dispatchEvent(new Event('change'));
            await flushPromises();
        })();
        expect((el('testo') as HTMLTextAreaElement).value).toBe('In allegato il suo foglio.');
    });

    test('un modello ricordato che non c\'è più: la finestra parte dal testo del programma, e lo dice', async () => {
        await apri(opzioni({ modello_ricordato: { id: null, nome: 'Foglio vecchio', mancante: true } }));
        expect((el('testo') as HTMLTextAreaElement).value).toBe('In allegato il suo foglio.');
        expect(el('modello-mancante')!.textContent).toContain('Il modello «Foglio vecchio»');
    });

    test('«Salva come modello…»: un modello dell\'email dei fogli con la strada dei modelli delle lettere; il menu si rilegge', async () => {
        await apri();
        el('salva-modello')!.click();
        await flushPromises();
        const nome = el('nome-modello') as HTMLInputElement;
        nome.value = 'Breve';
        nome.dispatchEvent(new Event('input'));
        (document.body.querySelector('input[name="vale-per-fogli"][value="tutti"]') as HTMLInputElement).click();
        await flushPromises();
        axiosPost.mockResolvedValueOnce({ data: { modello: { id: 30, tipo: 'foglio_conguaglio', nome: 'Breve', testo: 'Ecco il suo foglio, [nome].', per_tutti: true } } });
        axiosGet.mockResolvedValueOnce({ data: opzioni() });
        el('conferma-modello')!.click();
        await flushPromises();
        expect(ultimaChiamata()).toEqual(['/gestionale.lettere.modelli.store', { tipo: 'foglio_conguaglio', nome: 'Breve', testo: 'Ecco il suo foglio, [nome].', per_tutti: true }]);
        expect(axiosGet.mock.calls.at(-1)![0]).toBe('/gestionale.esercizi.rendiconto.conguaglio.invio.opzioni');
        expect(testo()).toContain('Salvato il modello «Breve», per tutti i condomini.');
    });

    test('l\'anteprima porta le scelte del riquadro e il modello; mostra il messaggio della prima persona, chi non ha un indirizzo, chi ha un foglio «non disponibile»', async () => {
        await conAnteprima();
        expect(ultimaChiamata()).toEqual(['/gestionale.esercizi.rendiconto.conguaglio.invio.anteprima',
            { chi: 'a_debito', pagina: 2, indirizzi: ['email', 'pec'], oggetto: '[condominio] — il foglio', testo: 'Ecco il suo foglio, [nome].', modello_id: 21 }]);
        expect(el('esempio')!.textContent).toContain('Gentile Bianchi,');
        expect(el('esempio')!.textContent).toContain(PROPOSTA);
        expect(el('conteggi')!.textContent).toContain('3 email a 2 persone');
        expect(el('senza-indirizzo')!.querySelector('a')!.getAttribute('href')).toBe('/gestionale.esercizi.rendiconto.conguaglio.foglio/4');
        expect(el('non-disponibili')!.textContent).toContain('Il foglio di 1 persona ha una parte non ancora calcolata: partono lo stesso, e la loro email lo dice in una riga.');
        expect(el('persona-non-disponibile')!.textContent).toBe('il suo foglio ha una parte non ancora calcolata, e l\'email lo dice');
        expect(el('persona-non-disponibile')).not.toBeNull();
        expect(el('tetto')).toBeNull();
        expect(el('invia')!.textContent).toContain('Invia 3 email');
    });

    test('il tetto si conta sulle persone rimaste, e il pulsante dice quante email partono con questo clic', async () => {
        await conAnteprima(anteprima({ tetto_messaggi: 2 }));
        expect(el('invia')!.textContent).toContain('Invia 1 email');
        expect(el('tetto')!.textContent).toContain('Con questo clic partono 1 email a 1 persona: senza il servizio della coda partono al massimo 2 email per volta');
        // Tolta Bianchi, parte Neri da sola, intera: nessun tetto da dire.
        (document.body.querySelector('[aria-label="Togli Bianchi"]') as HTMLElement).click();
        await flushPromises();
        expect(el('invia')!.textContent).toContain('Invia 2 email');
        expect(el('tetto')).toBeNull();
    });
});

describe('l\'invio', () => {
    test('porta le persone viste e rimaste e tutte quelle dell\'anteprima; poi il pulsante si spegne e lo stato si rilegge dal server', async () => {
        await conAnteprima();
        (document.body.querySelector('[aria-label="Togli Bianchi"]') as HTMLElement).click();
        await flushPromises();
        axiosPost.mockResolvedValueOnce({ data: riepilogo });
        axiosGet.mockResolvedValueOnce({ data: { ultimi: { 1: ultimo('in_coda') }, da_rimandare: 0, in_coda_da_tempo: 0 } });
        w2 = mount(UltimoInvioDelFoglio, { attachTo: document.body, props: { condominioId: 3, esercizioId: 9, persona: 1 } });
        expect(el('ultimo-invio')).toBeNull();
        el('invia')!.click();
        await flushPromises();
        const [rotta, corpo] = axiosPost.mock.calls.at(-1) as [string, Record<string, any>];
        expect(rotta).toBe('/gestionale.esercizi.rendiconto.conguaglio.invio');
        expect(corpo.viste).toEqual([1]);
        expect(corpo.in_anteprima).toEqual([2, 1, 4]);
        expect((el('invia') as HTMLButtonElement).disabled).toBe(true);
        expect(testo()).toContain('Email in coda 3.');
        // Lo stato riletto: le persone della pagina, una volta ciascuna.
        expect(axiosGet.mock.calls.at(-1)).toEqual(['/gestionale.esercizi.rendiconto.conguaglio.invio.stato', { params: { persone: [1, 2] } }]);
        expect(el('ultimo-invio')!.textContent).toContain('Foglio in coda dal 10/01/2027 alle 11:00.');
    });

    test('chi resta oltre il tetto parte con «Invia le altre»', async () => {
        await conAnteprima();
        axiosPost.mockResolvedValueOnce({ data: { ...riepilogo, restanti: { persone: [7, 8], messaggi: 2 } } });
        axiosGet.mockResolvedValue({ data: { ultimi: [], da_rimandare: 0, in_coda_da_tempo: 0 } });
        el('invia')!.click();
        await flushPromises();
        expect(testo()).toContain('Restano 2 persone: premi «Invia le altre».');
        axiosPost.mockResolvedValueOnce({ data: { ...riepilogo, restanti: null } });
        el('invia-le-altre')!.click();
        await flushPromises();
        expect((axiosPost.mock.calls.at(-1) as [string, Record<string, any>])[1].viste).toEqual([7, 8]);
        expect(el('invia-le-altre')).toBeNull();
    });

    test('dopo un invio senza risposta, o con un errore del server, la frase dell\'incertezza e lo stato riletto accanto alle persone', async () => {
        for (const errore of [new Error('Network Error'), { response: { status: 500, data: { message: 'Server Error' } } }]) {
            await conAnteprima();
            w2 = mount(UltimoInvioDelFoglio, { attachTo: document.body, props: { condominioId: 3, esercizioId: 9, persona: 1 } });
            axiosPost.mockRejectedValueOnce(errore);
            axiosGet.mockResolvedValueOnce({ data: { ultimi: { 1: ultimo('in_coda') }, da_rimandare: 0, in_coda_da_tempo: 0 } });
            el('invia')!.click();
            await flushPromises();
            expect(el('errore')!.textContent).toBe(FRASE_ESITO_INCERTO);
            expect(el('errore')!.textContent).not.toContain('Server Error');
            expect(axiosGet.mock.calls.at(-1)![0]).toBe('/gestionale.esercizi.rendiconto.conguaglio.invio.stato');
            expect(el('ultimo-invio')!.textContent).toContain('Foglio in coda dal 10/01/2027 alle 11:00.');
            w?.unmount();
            w2?.unmount();
            w = null;
            w2 = null;
            document.body.innerHTML = '';
            dimenticaStato();
        }
    });

    test('«Rimanda» e «Invia di nuovo» senza risposta: la frase dell\'incertezza, e lo stato riletto', async () => {
        pagina.props = paginaDellaScheda({ da_rimandare: 1, ultimi: { 1: ultimo('non_partito', { da_rimandare: true }) } });
        monta();
        axiosPost.mockRejectedValueOnce(new Error('Network Error'));
        axiosGet.mockResolvedValueOnce({ data: { ultimi: { 1: ultimo('in_coda') }, da_rimandare: 0, in_coda_da_tempo: 0 } });
        el('rimanda-non-partiti')!.click();
        await flushPromises();
        expect(el('esito-rinvio')!.textContent).toBe(FRASE_ESITO_INCERTO);
        expect(axiosGet).toHaveBeenCalledTimes(1);
        w?.unmount();
        w = null;
        dimenticaStato();
        w2 = mount(UltimoInvioDelFoglio, { attachTo: document.body, props: { condominioId: 3, esercizioId: 9, persona: 1 } });
        axiosPost.mockRejectedValueOnce({ response: { status: 500, data: { message: 'Server Error' } } });
        axiosGet.mockResolvedValueOnce({ data: { ultimi: { 1: ultimo('in_coda') }, da_rimandare: 0, in_coda_da_tempo: 0 } });
        el('invia-di-nuovo')!.click();
        await flushPromises();
        expect(axiosGet).toHaveBeenCalledTimes(2);
        expect(el('ultimo-invio')!.textContent).toContain('Foglio in coda dal 10/01/2027 alle 11:00.');
        expect(el('ultimo-invio')!.textContent).toContain(FRASE_ESITO_INCERTO);
    });

    test('un errore del server si legge com\'è; una risposta che non arriva dice di guardare l\'ultimo invio prima di riprovare', async () => {
        await conAnteprima();
        axiosPost.mockRejectedValueOnce({ response: { status: 422, data: { message: 'Nessun server di posta configurato: i fogli non partono per email.' } } });
        el('invia')!.click();
        await flushPromises();
        expect(el('errore')!.textContent).toBe('Nessun server di posta configurato: i fogli non partono per email.');
        expect((el('invia') as HTMLButtonElement).disabled).toBe(false);
        axiosGet.mockResolvedValue({ data: { ultimi: [], da_rimandare: 0, in_coda_da_tempo: 0 } });
        axiosPost.mockRejectedValueOnce(new Error('Network Error'));
        el('invia')!.click();
        await flushPromises();
        expect(el('errore')!.textContent).toBe(FRASE_ESITO_INCERTO);
    });
});

describe('lo stato accanto alle persone', () => {
    test('«Rimanda i fogli non partiti, con le cifre di oggi»: il numero viene dalla pagina, poi dallo stato riletto', async () => {
        pagina.props = paginaDellaScheda({ da_rimandare: 1 });
        monta();
        expect(el('rimanda-non-partiti')!.textContent).toContain('con le cifre di oggi (1)');
        axiosPost.mockResolvedValueOnce({ data: { fogli: 1, persone: 1, messaggi: 1, inviate: 1, in_coda: 0, non_partite: 0, errori: [], senza_indirizzo: [], restanti: null } });
        axiosGet.mockResolvedValueOnce({ data: { ultimi: [], da_rimandare: 0, in_coda_da_tempo: 0 } });
        el('rimanda-non-partiti')!.click();
        await flushPromises();
        expect((axiosPost.mock.calls.at(-1) as [string])[0]).toBe('/gestionale.esercizi.rendiconto.conguaglio.invio.riprova');
        expect(testo()).toContain('1 foglio rifatto con le cifre di oggi.');
        expect(el('rimanda-non-partiti')).toBeNull();
    });

    test('accanto alla persona: lo stato con l\'errore in chiaro, il PDF del foglio, «Invia di nuovo, con le cifre di oggi»; dopo, lo stato riletto', async () => {
        pagina.props = paginaDellaScheda({ ultimi: { 1: ultimo('in_parte', { da_rimandare: true, pdf: 12, righe: [
            { id: 11, destinatario: 'neri@prova.it', tipo: 'email', stato: 'inviata', quando: '2027-01-10 11:00', errore: null },
            { id: 12, destinatario: 'neri.casa@prova.it', tipo: 'email_secondaria', stato: 'non_partita', quando: '2027-01-10 11:00', errore: 'Il server di posta non ha accettato il messaggio (codice 550).' },
        ] }) } });
        w2 = mount(UltimoInvioDelFoglio, { attachTo: document.body, props: { condominioId: 3, esercizioId: 9, persona: 1 } });
        expect(el('ultimo-invio')!.textContent).toContain('non partito, per neri.casa@prova.it: il server di posta non ha accettato il messaggio (codice 550)');
        expect(el('foglio-inviato')!.getAttribute('href')).toBe('/gestionale.esercizi.rendiconto.conguaglio.invio.pdf/12');
        expect(el('invia-di-nuovo')!.textContent).toBe('Invia di nuovo, con le cifre di oggi');
        axiosPost.mockResolvedValueOnce({ data: { fogli: 1, persone: 1, messaggi: 1, inviate: 1, in_coda: 0, non_partite: 0, errori: [], senza_indirizzo: [], restanti: null } });
        axiosGet.mockResolvedValueOnce({ data: { ultimi: { 1: ultimo('inviato', { quando: '2027-01-11 10:00', pdf: 13 }) }, da_rimandare: 0, in_coda_da_tempo: 0 } });
        el('invia-di-nuovo')!.click();
        await flushPromises();
        expect((axiosPost.mock.calls.at(-1) as [string, Record<string, any>])[1]).toEqual({ anagrafica_id: 1 });
        expect(el('ultimo-invio')!.textContent).toContain('Foglio inviato il 11/01/2027 alle 10:00, consegna non provata.');
        expect(el('invia-di-nuovo')).toBeNull();
    });

    test('lo stato riletto vale solo per la sua pagina: riaperta la scheda (dati nuovi) o in un altro esercizio, valgono i dati della pagina', async () => {
        await conAnteprima();
        axiosPost.mockResolvedValueOnce({ data: riepilogo });
        axiosGet.mockResolvedValueOnce({ data: { ultimi: { 1: ultimo('in_coda') }, da_rimandare: 0, in_coda_da_tempo: 0 } });
        el('invia')!.click();
        await flushPromises();
        w?.unmount();
        w = null;
        // La scheda riaperta: il server dice «non partito», da rimandare 1.
        pagina.props = paginaDellaScheda({ ultimi: { 1: ultimo('non_partito', { da_rimandare: true }) }, da_rimandare: 1 });
        monta();
        w2 = mount(UltimoInvioDelFoglio, { attachTo: document.body, props: { condominioId: 3, esercizioId: 9, persona: 1 } });
        await flushPromises();
        expect(el('ultimo-invio')!.textContent).toContain('Foglio non partito');
        expect(el('invia-di-nuovo')).not.toBeNull();
        expect(el('rimanda-non-partiti')!.textContent).toContain('(1)');
        w2.unmount();
        // Un altro esercizio, con i dati della pagina di prima: la persona non ha invii lì.
        pagina.props = paginaDellaScheda();
        w2 = mount(UltimoInvioDelFoglio, { attachTo: document.body, props: { condominioId: 3, esercizioId: 8, persona: 1 } });
        expect(el('ultimo-invio')).toBeNull();
    });

    test('i fogli in coda da più di un\'ora si dicono, con che cosa fare; se la pagina non li porta, si chiedono una volta', async () => {
        pagina.props = paginaDellaScheda({ in_coda_da_tempo: 3 });
        monta();
        expect(el('in-coda-da-tempo')!.textContent).toContain('3 fogli per email sono in coda da più di un\'ora');
        expect(el('in-coda-da-tempo')!.textContent).toContain('SCHEDULE_QUEUE_WORKER');
        expect(axiosGet).not.toHaveBeenCalled();
        w?.unmount();
        const { in_coda_da_tempo: _, ...senza } = paginaDellaScheda().invio_fogli;
        pagina.props = { ...paginaDellaScheda(), invio_fogli: senza };
        axiosGet.mockResolvedValueOnce({ data: { ultimi: [], da_rimandare: 0, in_coda_da_tempo: 1 } });
        monta();
        await flushPromises();
        expect(axiosGet.mock.calls.at(-1)![0]).toBe('/gestionale.esercizi.rendiconto.conguaglio.invio.stato');
        expect(el('in-coda-da-tempo')!.textContent).toContain('1 foglio per email è in coda da più di un\'ora');
    });

    test('senza un invio accanto alla persona non c\'è niente', () => {
        w2 = mount(UltimoInvioDelFoglio, { attachTo: document.body, props: { condominioId: 3, esercizioId: 9, persona: 5 } });
        expect(el('ultimo-invio')).toBeNull();
    });
});
