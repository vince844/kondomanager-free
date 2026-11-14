// @vitest-environment jsdom

/**
 * La Sintesi del rendiconto: dice i numeri che il server le dà, senza sommarne; la percentuale dei costi dai millesimi; le fasce; ogni riga dell'esito
 * porta al suo controllo; il fascicolo con le parti nell'ordine fisso qualunque sia l'ordine delle spunte, e la scelta ricordata. Le sette schede; il riquadro del conguaglio, con le cifre o il rimando alla scheda.
 * Nessuna delle parole che il rendiconto non dice mai di sé.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';

/** Il messaggio che il server lascia in sessione (`flash.message`), come lo rimanda Inertia; ogni prova lo fissa da sé. */
const flashFinto = vi.hoisted(() => ({ valore: undefined as unknown }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<span />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn() },
    usePage: () => ({ props: { auth: { user: { id: 5 } }, flash: flashFinto.valore } }),
}));
vi.mock('@/composables/permissions', () => ({
    usePermission: () => ({
        generateRoute: (nome: string) => nome,
        generatePath: (p: string, parametri: Record<string, unknown> = {}) => `/${Object.entries(parametri).reduce((s, [k, v]) => s.replace(`:${k}`, String(v)), p)}`,
    }),
}));

const Pagina = (await import('./Sintesi.vue')).default;

const PARTI = ['riepilogo', 'situazione', 'cassa', 'condomini', 'conguaglio', 'registro', 'controlli'];
const sintesi = (extra: Record<string, unknown> = {}) => ({
    esercizio: { id: 9, nome: 'Esercizio 2026', dal: '2026-01-01', al: '2026-12-31' },
    stato: { finito: true, alla: '2026-12-31', frase: 'Esercizio finito al 31/12/2026: sono alla data di fine la situazione patrimoniale, le fatture aperte, il pagato e le quote dei piani; costi, quote registrate, risultato ed entrate e uscite per cassa contano le registrazioni dell\'esercizio, anche con la data dopo la fine; i vincoli dei fondi sono a oggi.' },
    esito: {
        tornano: 8, non_tornano: 1, da_leggere: 1, frase: '8 tornano, 1 non torna, 1 da leggere', riga_fissa: 'I controlli verificano che i numeri del programma tornino fra loro.',
        righe: [{ id: 'R2', nome: 'Liquidità e riepilogo', esito: 'quadra' }, { id: 'AC', nome: 'Registrazioni oltre il confine dell\'esercizio', esito: 'non_quadra' }, { id: 'R8', nome: 'Vincoli e casse fondo', esito: 'segnalazione' }],
    },
    riquadri: {
        deliberato: { deliberato: 411000, preventivo: null, solo_ordinarie: false },
        costi: { costi: 413100, per_capitolo: 397850, permille: 1005, base: 'deliberato' },
        quote: { pagate: 370000, emesse: 411000 },
        liquidita: { liquidita: 521900, alla: '2026-12-31', frase: 'Secondo la contabilità: non è il saldo dell\'estratto conto della banca.' },
    },
    fasce: [
        { tipo: 'commissioni', importo: 250, numero: 1, da_assegnare: 1, frase: '€ 2,50 di commissioni senza voce: non stanno in nessun capitolo, e il conguaglio non le divide.' },
        { tipo: 'contestate', importo: 15000, numero: 1, frase: '€ 150,00 di fatture contestate.' },
    ],
    prospetto: {
        preventivo: null, deliberato: 411000, avvertenza_deliberato: null, quote_piani: 441000, quote_pagate: 370000, quote_non_pagate: 41000, costi: 413100, quote_giornale: 411000, risultato: -2100, in_fondi: 0,
        raccordo_quote: { quote_piani: 441000, quote_giornale: 411000, piani_emesse_altrove: 30000, altri_emesse_qui: 0, altre_differenze: 0 },
    },
    ...extra,
});

let w: VueWrapper | null = null;
const monta = (extra: Record<string, unknown> = {}) => {
    w?.unmount();
    const slot = { template: '<div><slot /></div>' };
    w = mount(Pagina, {
        props: { condominio: { id: 3, nome: 'Via Roma' }, condomini: [], esercizio: { id: 9, nome: 'Esercizio 2026' }, esercizi: [], sintesi: sintesi(extra), parti: PARTI } as any,
        global: { stubs: { GestionaleLayout: slot, MovimentiLayout: slot, PageHeaderGuide: { props: ['pageSubtitle'], template: '<div><p data-test="sottotitolo">{{ pageSubtitle }}</p><slot name="actions" /></div>' }, Popover: slot, PopoverTrigger: slot, PopoverContent: slot } },
    });

    return w;
};
/** Il testo di un elemento, con uno spazio fra un blocco e l'altro (come si legge a video). */
const testo = (s: string) => s.replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
beforeEach(() => { flashFinto.valore = undefined; window.localStorage.clear(); (globalThis as any).route = (nome: string, p: Record<string, number>) => `/${nome}/${p.condominio}/${p.esercizio}`; });
afterEach(() => { w?.unmount(); w = null; });

describe('la Sintesi del rendiconto', () => {
    test('i quattro riquadri dicono i numeri del server, la percentuale dai millesimi, «nel verbale dell\'assemblea» dove il preventivo non c\'è', () => {
        const p = monta();
        expect(testo(p.find('[data-test="riquadro-deliberato"]').html())).toContain('€ 4.110,00 preventivo nel verbale dell\'assemblea');
        expect(testo(p.find('[data-test="riquadro-costi"]').html())).toContain('€ 4.131,00 100,5 %');
        expect(testo(p.find('[data-test="riquadro-costi"]').html())).toContain('la percentuale è sul deliberato con le rate');
        expect(testo(p.find('[data-test="riquadro-costi"]').html())).toContain('di cui per capitolo € 3.978,50');
        expect(testo(p.find('[data-test="riquadro-quote"]').html())).toContain('€ 3.700,00 su € 4.110,00 emesse');
        expect(testo(p.find('[data-test="riquadro-liquidita"]').html())).toContain('€ 5.219,00');
        expect(testo(p.find('[data-test="riquadro-liquidita"]').html())).toContain('Secondo la contabilità');
        expect(testo(monta({ riquadri: { ...sintesi().riquadri, costi: { costi: 100, per_capitolo: 100, permille: null, base: null } } }).find('[data-test="riquadro-costi"]').html())).not.toContain('%');
        // Con le spese personali chieste con le rate la base è il deliberato delle voci, con la sua cifra: non quello del riquadro accanto.
        expect(testo(monta({ riquadri: { ...sintesi().riquadri, costi: { costi: 2765429, per_capitolo: 2765429, permille: 1000, base: 'deliberato_voci', base_importo: 2765429 } } })
            .find('[data-test="base-percentuale"]').html())).toBe('la percentuale è sul deliberato delle voci, € 27.654,29 (senza le spese personali chieste con le rate)');
    });

    test('il deliberato che il programma non ha conservato dice «non disponibile» nel riquadro e nel prospetto, con la frase che spiega perché, una sola volta; con il deliberato la frase non c\'è', () => {
        const frase = 'Il deliberato con le rate non è disponibile per i piani generati prima che il programma ne conservasse il dettaglio: è nel verbale dell\'assemblea che li ha approvati.';
        const senza = monta({ riquadri: { ...sintesi().riquadri, deliberato: { deliberato: null, preventivo: 411000, solo_ordinarie: false } }, prospetto: { ...sintesi().prospetto, preventivo: 411000, deliberato: null, avvertenza_deliberato: frase } });

        expect(testo(senza.find('[data-test="riquadro-deliberato"]').html())).toContain('non disponibile preventivo € 4.110,00');
        expect(testo(senza.find('[data-test="prospetto"]').html())).toContain('Deliberato con le rate non disponibile');
        expect(testo(senza.find('[data-test="deliberato-non-disponibile"]').html())).toBe(frase);
        expect(senza.findAll('[data-test="deliberato-non-disponibile"]')).toHaveLength(1);
        expect(testo(senza.html())).not.toContain('non conservato');

        const con = monta();
        expect(con.find('[data-test="deliberato-non-disponibile"]').exists()).toBe(false);
        expect(testo(con.html())).not.toContain('non disponibile');
    });

    test('il deliberato ricostruito dei piani di prima (Coda 557): la cifra con «ricostruito» nel riquadro e la frase sotto il prospetto', () => {
        const ricostruita = 'Per i piani generati prima che il programma ne conservasse il dettaglio il deliberato con le rate è ricostruito.';
        const p = monta({ riquadri: { ...sintesi().riquadri, deliberato: { ...sintesi().riquadri.deliberato, ricostruito: true } },
            prospetto: { ...sintesi().prospetto, avvertenza_deliberato_ricostruito: ricostruita } });

        expect(testo(p.find('[data-test="riquadro-deliberato"]').html())).toContain('€ 4.110,00');
        expect(p.find('[data-test="riquadro-deliberato-ricostruito"]').text()).toBe('ricostruito');
        expect(testo(p.find('[data-test="deliberato-ricostruito"]').html())).toBe(ricostruita);
        expect(p.find('[data-test="deliberato-non-disponibile"]').exists()).toBe(false);
        expect(monta().find('[data-test="riquadro-deliberato-ricostruito"]').exists()).toBe(false);
    });

    test('il riquadro del conguaglio: le cifre del condominio dalla scheda, con il più sul conguaglio a debito; sopra la soglia la frase che rimanda alla scheda', () => {
        const cifre = { a: 420000, personali: 8000, b: 400000, conguaglio: 28000, saldo: 58000, non_disponibili: 0 };
        const con = monta({ riquadri: { ...sintesi().riquadri, conguaglio: { cifre, non_attribuite: '€ 123,45 di spese non attribuite a nessuno: le dice la scheda Conguaglio, con il perché.', frase: 'Proposta di conguaglio, da approvare dall\'assemblea: diventa una richiesta solo con l\'approvazione.', data: { frase: 'Alla fine dell\'esercizio, il 31/12/2026.' } } } });
        const t = testo(con.find('[data-test="riquadro-conguaglio"]').html());
        expect(t).toContain('€ +280,00');
        expect(testo(con.find('[data-test="riquadro-conguaglio-non-attribuite"]').html())).toBe('€ 123,45 di spese non attribuite a nessuno: le dice la scheda Conguaglio, con il perché.');
        expect(t).toContain('spese dell\'esercizio € 4.200,00 + spese personali non chieste € 80,00 − quote dei piani € 4.000,00');
        expect(t).toContain('Proposta di conguaglio, da approvare dall\'assemblea');
        expect(con.find('[data-test="riquadro-conguaglio"] a').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto/conguaglio');

        const rimanda = monta({ riquadri: { ...sintesi().riquadri, conguaglio: { cifre: null, frase: 'Si calcola nella scheda Conguaglio: con questo condominio la lettura chiede troppo tempo per la Sintesi.' } } });
        expect(rimanda.find('[data-test="riquadro-conguaglio-rimanda"]').text()).toBe('Si calcola nella scheda Conguaglio: con questo condominio la lettura chiede troppo tempo per la Sintesi.');
        expect(testo(rimanda.find('[data-test="riquadro-conguaglio"]').html())).not.toContain('€');
    });

    test('la percentuale tiene il segno anche fra 0 e −1 %; il preventivo delle sole gestioni ordinarie lo dice', () => {
        const negativa = monta({ riquadri: { ...sintesi().riquadri, costi: { costi: -2000, per_capitolo: -2000, permille: -5, base: 'deliberato' } } });
        expect(testo(negativa.find('[data-test="riquadro-costi"]').html())).toContain('-0,5 %');
        expect(testo(monta({ riquadri: { ...sintesi().riquadri, costi: { costi: -20000, per_capitolo: -20000, permille: -1234, base: 'deliberato' } } }).find('[data-test="riquadro-costi"]').html())).toContain('-123,4 %');
        const ordinarie = monta({ riquadri: { ...sintesi().riquadri, deliberato: { deliberato: null, preventivo: 411000, solo_ordinarie: true } }, prospetto: { ...sintesi().prospetto, preventivo: 411000, preventivo_solo_ordinarie: true } });
        expect(testo(ordinarie.find('[data-test="riquadro-deliberato"]').html())).toContain('preventivo delle gestioni ordinarie € 4.110,00');
        expect(testo(ordinarie.find('[data-test="prospetto"]').html())).toContain('Preventivo delle gestioni ordinarie € 4.110,00');
    });

    test('le fasce: le commissioni portano ad assegnare la voce, le contestate al riepilogo', () => {
        const p = monta();
        expect(p.find('[data-fascia="commissioni"]').text()).toContain('il conguaglio non le divide');
        expect(p.find('[data-fascia="commissioni"] a').attributes('href')).toBe('/gestionale/3/esercizi/9/commissioni-senza-voce');
        expect(p.find('[data-fascia="contestate"] a').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto/riepilogo-finanziario');
        expect(p.find('[data-fascia="commissioni"] a').text()).toBe('Assegna la voce');
        expect(monta({ fasce: [] }).find('[data-fascia]').exists()).toBe(false);
    });

    test('gli storni senza voce di registrazioni di un esercizio chiuso: la fascia porta all\'elenco, e invita ad assegnare la voce solo se l\'elenco ne ha da assegnare', () => {
        const frase = '€ -96,00 di storni di registrazioni di un esercizio chiuso, senza voce: non stanno in nessun capitolo, e il conguaglio non li divide.';
        const f = monta({ fasce: [{ tipo: 'storni', importo: -9600, numero: 1, da_assegnare: 1, frase }] }).find('[data-fascia="storni"]');
        expect(f.text()).toContain(frase);
        expect(f.find('a').attributes('href')).toBe('/gestionale/3/esercizi/9/commissioni-senza-voce');
        expect(f.find('a').text()).toBe('Assegna la voce');
        expect(monta({ fasce: [{ tipo: 'storni', importo: -9600, numero: 1, da_assegnare: 0, frase }] }).find('[data-fascia="storni"] a').text()).toBe('Vedi l\'elenco');
    });

    test('le commissioni senza voce che non si assegnano (nessuna da assegnare): la fascia porta all\'elenco, che dice perché, e non invita ad assegnare la voce', () => {
        const frase = '€ -2,50 di commissioni senza voce, che qui non si assegnano: non stanno in nessun capitolo, e il conguaglio non le divide. L\'elenco dice perché.';
        const f = monta({ fasce: [{ tipo: 'commissioni', importo: -250, numero: 1, da_assegnare: 0, frase }] }).find('[data-fascia="commissioni"]');

        expect(f.text()).toContain(frase);
        expect(f.text()).not.toContain('Assegna la voce');
        expect(f.find('a').text()).toBe('Vedi l\'elenco');
        expect(f.find('a').attributes('href')).toBe('/gestionale/3/esercizi/9/commissioni-senza-voce');
    });

    test('il «non disponibile» nomina sotto la sua frase i piani che, rifatti con i dati di oggi, non tornano', () => {
        const frase = 'Il deliberato con le rate non è disponibile per i piani generati prima che il programma ne conservasse il dettaglio: è nel verbale dell\'assemblea che li ha approvati.';
        const piani = ['Il piano «Ordinario 2026», rifatto con i dati di oggi, non torna con le quote emesse.', 'Il piano «Pulizie», rifatto con i dati di oggi, non torna con le quote emesse.'];
        const p = monta({ prospetto: { ...sintesi().prospetto, deliberato: null, avvertenza_deliberato: frase, avvertenza_deliberato_piani: piani } });

        expect(p.findAll('[data-test="deliberato-piani"]').map((e) => e.text())).toEqual(piani);
        expect(monta().find('[data-test="deliberato-piani"]').exists()).toBe(false);
    });

    test('l\'esito: la frase, una riga per controllo che porta al controllo, la riga fissa', () => {
        const e = monta().find('[data-test="esito"]');
        expect(testo(e.html())).toContain('I controlli: 8 tornano, 1 non torna, 1 da leggere');
        expect(e.find('[data-controllo="AC"] a').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto/controlli#controllo-AC');
        expect(testo(e.find('[data-controllo="AC"]').html())).toContain('non torna');
        expect(testo(e.find('[data-controllo="R8"]').html())).toContain('da leggere');
        expect(e.find('[data-test="riga-fissa"]').text()).toContain('tornino fra loro');
    });

    test('un controllo che la Sintesi non ha calcolato dice «non calcolato», non «da leggere»', () => {
        const esito = { ...sintesi().esito, righe: [...sintesi().esito.righe, { id: 'CG', nome: 'Le spese divise fanno le spese per capitolo', esito: 'non_calcolato' }] };
        const riga = monta({ esito }).find('[data-controllo="CG"]');
        expect(testo(riga.html())).toContain('non calcolato');
        expect(testo(riga.html())).not.toContain('da leggere');
        expect(testo(riga.html())).not.toContain('undefined');
    });

    test('il prospetto della gestione: il risultato con i suoi due termini, e la riga che lega le quote quando sono diverse', () => {
        const p = monta();
        expect(testo(p.find('[data-test="risultato"]').html())).toBe('Risultato: quote registrate in questo esercizio € 4.110,00 − costi € 4.131,00 € -21,00 (disavanzo)');
        expect(testo(monta({ prospetto: { ...sintesi().prospetto, risultato: 0 } }).find('[data-test="risultato"]').html())).toContain('€ 0,00 (in pareggio)');
        expect(testo(monta({ prospetto: { ...sintesi().prospetto, risultato: 500 } }).find('[data-test="risultato"]').html())).toContain('€ 5,00 (avanzo)');
        expect(testo(p.find('[data-test="raccordo-quote"]').html())).toContain('+ rate di questi piani emesse in un altro esercizio € 300,00');
        expect(monta({ prospetto: { ...sintesi().prospetto, quote_piani: 411000 } }).find('[data-test="raccordo-quote"]').exists()).toBe(false);
    });

    test('Coda 568 — il risultato e la riga che lega le quote dei piani dicono «quote registrate in questo esercizio», come le stampe: mai «a giornale»', () => {
        const p = monta();

        expect(testo(p.find('[data-test="risultato"]').html())).toContain('quote registrate in questo esercizio € 4.110,00');
        expect(testo(p.find('[data-test="raccordo-quote"]').html())).toContain('= quote registrate in questo esercizio € 4.110,00');
        expect(testo(p.find('[data-test="prospetto"]').html())).not.toContain('a giornale');
    });

    test('Coda 569 — sotto il prospetto la pagina dice, come la stampa, che il preventivo è le spese previste meno le entrate da terzi', () => {
        expect(testo(monta().find('[data-test="preventivo-netto"]').html())).toBe('Preventivo: le spese previste, meno le entrate da terzi.');
        expect(testo(monta({ prospetto: { ...sintesi().prospetto, preventivo: 411000, preventivo_solo_ordinarie: true } }).find('[data-test="prospetto"]').html()))
            .toContain('Preventivo delle gestioni ordinarie € 4.110,00')
            .toContain('Preventivo: le spese previste, meno le entrate da terzi.');
    });

    test('il fascicolo: le parti escono nell\'ordine fisso qualunque sia l\'ordine delle spunte, con il dettaglio a scelta; la scelta si ricorda', async () => {
        const apri = vi.spyOn(window, 'open').mockImplementation(() => null);
        const p = monta();
        for (const parte of PARTI) {
            await p.find(`[data-parte="${parte}"]`).setValue(false);
        }
        await p.find('[data-parte="controlli"]').setValue(true);
        await p.find('[data-parte="riepilogo"]').setValue(true);
        await p.find('[data-test="dettaglio"]').setValue(true);
        await p.find('[data-test="scarica-fascicolo"]').trigger('click');
        expect(decodeURIComponent(apri.mock.calls[0][0] as string)).toBe('/gestionale.esercizi.rendiconto.fascicolo/3/9?parti[]=riepilogo&parti[]=controlli&dettaglio=1');
        expect(JSON.parse(window.localStorage.getItem('rendiconto-fascicolo-5') ?? '{}')).toEqual({ parti: ['controlli', 'riepilogo'], dettaglio: true });
        const di = monta();
        expect((di.find('[data-parte="cassa"]').element as HTMLInputElement).checked).toBe(false);
        expect((di.find('[data-parte="riepilogo"]').element as HTMLInputElement).checked).toBe(true);
        expect(testo(di.html())).toContain('La scelta resta su questo computer');
        apri.mockRestore();
    });

    test('l\'ultima lettera di sollecito nel fascicolo: la casella è spenta ogni volta, c\'è solo con i condòmini fra le parti, e accesa chiede la colonna', async () => {
        const apri = vi.spyOn(window, 'open').mockImplementation(() => null);
        const p = monta();
        expect((p.find('[data-test="lettere"]').element as HTMLInputElement).checked).toBe(false);
        expect(testo(p.find('[data-test="lettere-nota"]').html())).toContain('di serie la colonna non c\'è');
        await p.find('[data-test="scarica-fascicolo"]').trigger('click');
        expect(decodeURIComponent(apri.mock.calls[0][0] as string)).not.toContain('lettere');
        // Il pulsante si spegne per un minuto dopo un fascicolo: la seconda prova su una pagina nuova.
        const con = monta();
        await con.find('[data-test="lettere"]').setValue(true);
        await con.find('[data-test="scarica-fascicolo"]').trigger('click');
        const chieste = apri.mock.calls.map((c) => decodeURIComponent(c[0] as string));
        apri.mockRestore();
        expect(chieste[1]).toContain('parti[]=condomini').toContain('&lettere=1');
        await con.find('[data-parte="condomini"]').setValue(false);
        expect(con.find('[data-test="lettere"]').exists()).toBe(false);
        // Non si ricorda: la pagina riaperta ha la casella spenta.
        await con.find('[data-parte="condomini"]').setValue(true);
        expect(JSON.parse(window.localStorage.getItem('rendiconto-fascicolo-5') ?? '{}')).not.toHaveProperty('lettere');
        expect((monta().find('[data-test="lettere"]').element as HTMLInputElement).checked).toBe(false);
    });

    test('il fascicolo non si chiede due volte mentre si prepara: il pulsante si spegne con la frase, e torna dopo un minuto', async () => {
        vi.useFakeTimers();
        const apri = vi.spyOn(window, 'open').mockImplementation(() => null);
        try {
            const p = monta();
            await p.find('[data-test="scarica-fascicolo"]').trigger('click');
            await p.find('[data-test="scarica-fascicolo"]').trigger('click');
            expect(apri).toHaveBeenCalledTimes(1);
            expect((p.find('[data-test="scarica-fascicolo"]').element as HTMLButtonElement).disabled).toBe(true);
            expect(testo(p.find('[data-test="fascicolo-in-preparazione"]').html())).toBe('Il fascicolo si apre nell\'altra scheda; con molti condòmini può volerci più di un minuto. Se è troppo grande per il server, lì trovi quale parte togliere.');
            await vi.advanceTimersByTimeAsync(60_000);
            expect((p.find('[data-test="scarica-fascicolo"]').element as HTMLButtonElement).disabled).toBe(false);
            expect(p.find('[data-test="fascicolo-in-preparazione"]').exists()).toBe(false);
        } finally {
            apri.mockRestore();
            vi.useRealTimers();
        }
    });

    test('il fascicolo che non parte torna alla Sintesi con la frase nel messaggio: la pagina la mostra, e un errore non si chiude da sé', async () => {
        vi.useFakeTimers();
        try {
            const frase = 'Con la memoria che il server concede a una stampa il fascicolo non si può fare: stampa le parti una per una, ognuna dalla sua scheda.';
            flashFinto.valore = { message: { type: 'error', message: frase } };
            const p = monta();
            await nextTick();
            expect(testo(p.html())).toContain(frase);
            await vi.advanceTimersByTimeAsync(30_000);
            expect(testo(p.html())).toContain(frase);

            // Senza il messaggio la pagina non mostra nessun avviso.
            flashFinto.valore = undefined;
            const senza = monta();
            await nextTick();
            expect(testo(senza.html())).not.toContain('il fascicolo non si può fare');
            expect(senza.find('[role="alert"]').exists()).toBe(false);
        } finally {
            vi.useRealTimers();
        }
    });

    test('il sottotitolo separa il nome dell\'esercizio dalla frase dello stato con un punto in mezzo, non con i due punti: la frase comincia già con «Esercizio aperto:»', () => {
        const aperto = monta({ stato: { finito: false, alla: '2026-04-15', frase: 'Esercizio aperto: i numeri sono a oggi e cambiano a ogni registrazione.' } });
        expect(aperto.find('[data-test="sottotitolo"]').text()).toBe('Esercizio 2026 · Esercizio aperto: i numeri sono a oggi e cambiano a ogni registrazione.');
        expect(monta().find('[data-test="sottotitolo"]').text()).toBe('Esercizio 2026 · Esercizio finito al 31/12/2026: sono alla data di fine la situazione patrimoniale, le fatture aperte, il pagato e le quote dei piani; costi, quote registrate, risultato ed entrate e uscite per cassa contano le registrazioni dell\'esercizio, anche con la data dopo la fine; i vincoli dei fondi sono a oggi.');
    });

    test('le sette schede, con la Sintesi accesa; nessuna delle parole che il rendiconto non dice di sé', () => {
        const p = monta();
        const schede = p.findAll('[data-scheda]');
        expect(schede.map((s) => [s.attributes('data-scheda'), s.attributes('href')])).toEqual([
            ['sintesi', '/gestionale/3/esercizi/9/rendiconto'], ['riepilogo', '/gestionale/3/esercizi/9/rendiconto/riepilogo-finanziario'],
            ['situazione', '/gestionale/3/esercizi/9/stato-patrimoniale'], ['cassa', '/gestionale/3/esercizi/9/rendiconto/cassa'],
            ['condomini', '/gestionale/3/esercizi/9/rendiconto/condomini'], ['conguaglio', '/gestionale/3/esercizi/9/rendiconto/conguaglio'],
            ['controlli', '/gestionale/3/esercizi/9/rendiconto/controlli'],
        ]);
        expect(p.find('[data-scheda="sintesi"]').attributes('aria-current')).toBe('page');
        const t = p.text().toLowerCase();
        for (const parola of ['pronto', 'conforme', 'corretto', 'approvabile', 'in regola', 'tutto in ordine']) {
            expect(t).not.toMatch(new RegExp(`(^|[^\\p{L}])${parola}($|[^\\p{L}])`, 'u'));
        }
    });
});
