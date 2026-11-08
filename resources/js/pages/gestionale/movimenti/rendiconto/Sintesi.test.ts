// @vitest-environment jsdom

/**
 * La Sintesi del rendiconto: dice i numeri che il server le dà, senza sommarne; la percentuale dei costi dai millesimi; le fasce; ogni riga dell'esito
 * porta al suo controllo; il fascicolo con le parti nell'ordine fisso qualunque sia l'ordine delle spunte, e la scelta ricordata. Le sei schede.
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

const PARTI = ['riepilogo', 'situazione', 'cassa', 'condomini', 'registro', 'controlli'];
const sintesi = (extra: Record<string, unknown> = {}) => ({
    esercizio: { id: 9, nome: 'Esercizio 2026', dal: '2026-01-01', al: '2026-12-31' },
    stato: { finito: true, alla: '2026-12-31', frase: 'Esercizio finito al 31/12/2026: i numeri sono alla data di fine.' },
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
        { tipo: 'commissioni', importo: 250, numero: 1, frase: '€ 2,50 di commissioni senza voce: non stanno in nessun capitolo, e il conguaglio non le troverà.' },
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
        expect(p.find('[data-fascia="commissioni"]').text()).toContain('il conguaglio non le troverà');
        expect(p.find('[data-fascia="commissioni"] a').attributes('href')).toBe('/gestionale/3/esercizi/9/commissioni-senza-voce');
        expect(p.find('[data-fascia="contestate"] a').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto/riepilogo-finanziario');
        expect(monta({ fasce: [] }).find('[data-fascia]').exists()).toBe(false);
    });

    test('l\'esito: la frase, una riga per controllo che porta al controllo, la riga fissa', () => {
        const e = monta().find('[data-test="esito"]');
        expect(testo(e.html())).toContain('I controlli: 8 tornano, 1 non torna, 1 da leggere');
        expect(e.find('[data-controllo="AC"] a').attributes('href')).toBe('/gestionale/3/esercizi/9/rendiconto/controlli#controllo-AC');
        expect(testo(e.find('[data-controllo="AC"]').html())).toContain('non torna');
        expect(testo(e.find('[data-controllo="R8"]').html())).toContain('da leggere');
        expect(e.find('[data-test="riga-fissa"]').text()).toContain('tornino fra loro');
    });

    test('il prospetto della gestione: il risultato con i suoi due termini, e la riga che lega le quote quando sono diverse', () => {
        const p = monta();
        expect(testo(p.find('[data-test="risultato"]').html())).toBe('Risultato: quote emesse a giornale € 4.110,00 − costi € 4.131,00 € -21,00 (disavanzo)');
        expect(testo(monta({ prospetto: { ...sintesi().prospetto, risultato: 0 } }).find('[data-test="risultato"]').html())).toContain('€ 0,00 (in pareggio)');
        expect(testo(monta({ prospetto: { ...sintesi().prospetto, risultato: 500 } }).find('[data-test="risultato"]').html())).toContain('€ 5,00 (avanzo)');
        expect(testo(p.find('[data-test="raccordo-quote"]').html())).toContain('+ rate di questi piani emesse in un altro esercizio € 300,00');
        expect(monta({ prospetto: { ...sintesi().prospetto, quote_piani: 411000 } }).find('[data-test="raccordo-quote"]').exists()).toBe(false);
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
        const aperto = monta({ stato: { finito: false, alla: '2026-04-15', frase: 'Esercizio aperto: i numeri sono a oggi e cambiano fino alla chiusura.' } });
        expect(aperto.find('[data-test="sottotitolo"]').text()).toBe('Esercizio 2026 · Esercizio aperto: i numeri sono a oggi e cambiano fino alla chiusura.');
        expect(monta().find('[data-test="sottotitolo"]').text()).toBe('Esercizio 2026 · Esercizio finito al 31/12/2026: i numeri sono alla data di fine.');
    });

    test('le sei schede, con la Sintesi accesa; nessuna delle parole che il rendiconto non dice di sé', () => {
        const p = monta();
        const schede = p.findAll('[data-scheda]');
        expect(schede.map((s) => [s.attributes('data-scheda'), s.attributes('href')])).toEqual([
            ['sintesi', '/gestionale/3/esercizi/9/rendiconto'], ['riepilogo', '/gestionale/3/esercizi/9/rendiconto/riepilogo-finanziario'],
            ['situazione', '/gestionale/3/esercizi/9/stato-patrimoniale'], ['cassa', '/gestionale/3/esercizi/9/rendiconto/cassa'],
            ['condomini', '/gestionale/3/esercizi/9/rendiconto/condomini'], ['controlli', '/gestionale/3/esercizi/9/rendiconto/controlli'],
        ]);
        expect(p.find('[data-scheda="sintesi"]').attributes('aria-current')).toBe('page');
        const t = p.text().toLowerCase();
        for (const parola of ['pronto', 'conforme', 'corretto', 'approvabile', 'in regola', 'tutto in ordine']) {
            expect(t).not.toMatch(new RegExp(`(^|[^\\p{L}])${parola}($|[^\\p{L}])`, 'u'));
        }
    });
});
