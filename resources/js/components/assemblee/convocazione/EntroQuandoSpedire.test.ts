// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * «Entro quando spedire» senza spazi vuoti (la bozza approvata del passo «Convocazione»), con l'ordinaria di Via Roma 12: la prima convocazione lunedì 14/12,
 * l'ultimo giorno di ricezione mercoledì 09/12, la proposta del programma giovedì 03/12, oggi giovedì 26/11.
 *
 * - La proposta: sullo schermo largo «Come conto giovedì 03/12» è sempre accanto, con la settimana e «Arriva in tempo, l'ultimo giorno utile»; sotto il campo
 *   le righe d'aiuto leggere (non più riquadri), il compito con i giorni del promemoria per email, «Giorni di posta dello studio: 3 lavorativi · Cambia».
 * - Al telefono «Come conto» resta nel cassetto della «i» del conto.
 * - La «i» della data ha il suggerimento del programma, che si apre al tocco; non più il `title` del browser.
 * - Il campo comincia da oggi: un giorno passato è spento nel calendario. Una data scelta si salva quando cambia; la data della proposta torna la proposta.
 * - Una data scelta: «scelta da te», «Torna alla proposta (gio 03/12)» accanto al campo, «Come conto» rifatto su quella data, «Che cosa segue la data che
 *   scegli»; più tardi della proposta, il riquadro arancio (al telefono anche sotto il campo). L'errore del server si legge rosso sotto il campo, senza icona;
 *   una data salvata che è passata si mostra com'è, con la frase.
 * - Senza «Gestisce le assemblee» la data si legge e non si cambia. La destinazione d'uso (art. 1117-ter) come prima.
 *
 * Che cosa non copre: il conto (lo fa il server, `TerminiDellaConvocazione`, e lo prova `ComeContoSullaDataSceltaTest`); il tema scuro e la larghezza vera a
 * 375 pixel (la prova a video).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { giornoMostrato, scegliIlGiorno } from '@/components/campoGiornoDiProva';

const inertia = vi.hoisted(() => ({ patch: vi.fn(), post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const { entroProposta, entroSceltaDaTe, entroSceltaPrima } = await import('./viaRoma12');
const Entro = (await import('./EntroQuandoSpedire.vue')).default;

const ROTTA = '/a/1/convocazione/raccomandate-entro';
let w: VueWrapper | null = null;
const matchMediaDiPrima = window.matchMedia;
beforeEach(() => { inertia.patch.mockClear(); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; window.matchMedia = matchMediaDiPrima; });

/** Lo schermo largo (dai 1024 pixel) o il telefono: la misura che la pagina chiede con `useMediaQuery`. */
function schermo(largo: boolean) {
    window.matchMedia = ((q: string) => ({
        matches: largo && /min-width:\s*1024px/.test(q), media: q, onchange: null,
        addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}, dispatchEvent: () => false,
    })) as unknown as typeof window.matchMedia;
}

async function monta(entro = entroProposta(), rottaAffissione = '', largo = true) {
    schermo(largo);
    w = mount(Entro, { attachTo: document.body, props: { entro, rotta: ROTTA, rottaAffissione } });
    await flushPromises();

    return w;
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');

describe('la data proposta: «Entro quando spedire» e «Come conto» affiancati', () => {
    test('sullo schermo largo «Come conto» è sempre accanto, con la settimana e se arriva in tempo, senza la «i» del conto', async () => {
        await monta();
        const conto = w!.get('[data-testid="come-conto"]');
        const t = conto.text().replace(/\s+/g, ' ');
        expect(t).toContain('Come conto giovedì 03/12');
        expect(t).toContain('La raccomandata deve arrivare entro mercoledì 09/12. Lo studio conta tre giorni di posta, dal lunedì al venerdì, dal giorno dopo la spedizione.');
        for (const g of ['gio 03', 'ven 04', 'sab 05', 'dom 06', 'lun 07', 'mar 08', 'mer 09']) expect(t).toContain(g);
        expect(w!.get('[data-testid="esito-conto"]').text().replace(/\s+/g, ' '))
            .toBe('Arriva in tempo, l\'ultimo giorno utile. Spedita venerdì 04/12 arriverebbe giovedì 10/12: un giorno dopo.');
        expect(w!.get('[data-testid="esito-conto"]').classes().join(' ')).toContain('emerald');
        expect(t).toContain('l\'Immacolata');
        expect(t).toContain('Conto prudente: spedire un giorno prima non costa niente.');
        expect(t).not.toContain('Che cosa segue la data che scegli');
        expect(w!.find('[data-testid="apri-conto"]').exists()).toBe(false);
    });

    test('la riga delle raccomandate, la data con «proposta dal programma», le righe d\'aiuto leggere e i giorni di posta con «Cambia»', async () => {
        await monta();
        const t = testo();
        expect(t).toContain('Entro quando spedire');
        expect(t).toContain('Per la prima convocazione di lunedì 14/12 devono arrivare entro mercoledì 09/12: il giorno in cui arrivano conta, il 14/12 no.');
        expect(t).toContain('Raccomandate (5)');
        expect(t).toContain('entro giovedì 03/12');
        expect(t).toContain('PEC (11) e consegne a mano (10)');
        expect(t).toContain('Spedisco le raccomandate entro');
        expect(w!.find('[data-testid="badge-data"]').text()).toBe('proposta dal programma');
        expect(giornoMostrato(w!.get('#raccomandate-entro'))).toBe('03/12/2026');
        const righe = w!.get('[data-testid="righe-aiuto"]');
        expect(righe.text().replace(/\s+/g, ' ')).toContain('Puoi scegliere un altro giorno, da oggi in poi: si salva quando lo cambi.');
        expect(righe.text().replace(/\s+/g, ' ')).toContain('Il compito «Spedire la convocazione entro il 03/12» è nell\'Action inbox; il promemoria per email arriva martedì 01/12 e giovedì 03/12.');
        // Righe leggere, non più riquadri grigi.
        expect(righe.findAll('p').every((p) => !p.classes().includes('border'))).toBe(true);
        expect(w!.get('[data-testid="giorni-di-posta"]').text().replace(/\s+/g, ' ')).toBe('Giorni di posta dello studio: 3 lavorativi · Cambia');
        expect(w!.get('[data-testid="giorni-di-posta"] a').attributes('href')).toBe('/impostazioni/assemblee');
        expect(t).not.toContain('Adesso:');
        expect(w!.find('[data-testid="torna-alla-proposta"]').exists()).toBe(false);
    });

    test('chi non può cambiare l\'impostazione legge i giorni di posta senza «Cambia»', async () => {
        await monta({ ...entroProposta(), giorni_di_posta: { adesso: '3 lavorativi', dove: null } });
        expect(w!.get('[data-testid="giorni-di-posta"]').text().replace(/\s+/g, ' ')).toBe('Giorni di posta dello studio: 3 lavorativi');
        expect(w!.find('[data-testid="giorni-di-posta"] a').exists()).toBe(false);
    });

    test('la «i» della data ha il suggerimento del programma, che si apre al tocco, non il title del browser', async () => {
        await monta();
        const i = w!.get('[data-testid="esempio-data"]');
        expect(i.attributes('title')).toBeUndefined();
        expect(document.body.querySelector('[data-testid="suggerimento"]')).toBeNull();
        await i.trigger('click');
        await flushPromises();
        // Il suggerimento del programma ripete il testo per chi legge lo schermo: si legge il primo paragrafo.
        expect(document.body.querySelector('[data-testid="suggerimento"] p')?.textContent?.replace(/\s+/g, ' ').trim())
            .toBe('La data è tua: il programma la usa per il compito e per le lettere. Esempio: se le porti in posta mercoledì 02/12, scrivi 02/12, e «Come conto» si rifà su quel giorno.');
    });

    test('il campo comincia da oggi: un giorno passato è spento; una data scelta si salva quando cambia, la data della proposta torna la proposta', async () => {
        await monta();
        await expect(scegliIlGiorno(w!.get('#raccomandate-entro'), '2026-11-25')).rejects.toThrow('è spento nel calendario');
        document.body.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await flushPromises();
        expect(inertia.patch).not.toHaveBeenCalled();
        await scegliIlGiorno(w!.get('#raccomandate-entro'), '2026-12-04');
        expect(inertia.patch).toHaveBeenLastCalledWith(ROTTA, { giorno: '2026-12-04' },
            expect.objectContaining({ preserveScroll: true, only: ['convocazione', 'passi', 'diario', 'assemblea', 'proposte'] }));
        expect(giornoMostrato(w!.get('#raccomandate-entro'))).toBe('04/12/2026');
        await scegliIlGiorno(w!.get('#raccomandate-entro'), '2026-12-03');
        expect(inertia.patch).toHaveBeenLastCalledWith(ROTTA, { giorno: null }, expect.anything());
        expect(inertia.patch).toHaveBeenCalledTimes(2);
    });
});

describe('al telefono', () => {
    test('«Come conto» resta nel cassetto della «i» del conto: non c\'è finché non si tocca, «Chiudi» lo chiude', async () => {
        await monta(entroProposta(), '', false);
        expect(w!.find('[data-testid="come-conto"]').exists()).toBe(false);
        expect(testo()).not.toContain('Come conto giovedì 03/12');
        await w!.get('[data-testid="apri-conto"]').trigger('click');
        await flushPromises();
        const t = testo();
        expect(t).toContain('Come conto giovedì 03/12');
        expect(t).toContain('Arriva in tempo, l\'ultimo giorno utile.');
        const chiudi = [...document.body.querySelectorAll('button')].find((b) => (b.textContent ?? '').trim() === 'Chiudi');
        chiudi!.click();
        await flushPromises();
        expect(testo()).not.toContain('Come conto giovedì 03/12');
    });

    test('con una data che arriva tardi, il riquadro arancio sta anche sotto il campo', async () => {
        await monta(entroSceltaDaTe(), '', false);
        expect(w!.get('[data-testid="esito-tardi"]').text().replace(/\s+/g, ' ')).toContain('Arriva tardi: spedita venerdì 04/12 arriverebbe giovedì 10/12');
    });
});

describe('una data scelta: «Come conto» segue la data', () => {
    test('prima della proposta: «scelta da te», «Torna alla proposta», «Come conto martedì 01/12» in tempo con il margine, e che cosa segue', async () => {
        await monta(entroSceltaPrima());
        expect(w!.find('[data-testid="badge-data"]').text()).toBe('scelta da te');
        expect(w!.get('[data-testid="torna-alla-proposta"]').text()).toBe('Torna alla proposta (gio 03/12)');
        const conto = w!.get('[data-testid="come-conto"]').text().replace(/\s+/g, ' ');
        expect(conto).toContain('Come conto martedì 01/12');
        expect(w!.get('[data-testid="esito-conto"]').text().replace(/\s+/g, ' ')).toBe('Arriva in tempo, con 5 giorni di margine sul 09/12.');
        expect(conto).toContain('Che cosa segue la data che scegli');
        expect(conto).toContain('Il compito nell\'Action inbox: «Spedire la convocazione entro il 01/12».');
        expect(conto).toContain('Il promemoria per email: venerdì 27/11 e martedì 01/12.');
        expect(conto).toContain('Le raccomandate stampate: nella tabella dei convocati, «entro il 01/12».');
        expect(conto).not.toContain('Conto prudente');
        expect(w!.get('[data-testid="righe-aiuto"]').text().replace(/\s+/g, ' ')).toContain('Scelta da te il 26/11/2026; la proposta del programma resta giovedì 03/12.');
        await w!.get('[data-testid="torna-alla-proposta"]').trigger('click');
        expect(inertia.patch).toHaveBeenLastCalledWith(ROTTA, { giorno: null }, expect.objectContaining({ preserveScroll: true }));
    });

    test('più tardi della proposta: «Come conto» arancio con il giorno in cui arriverebbe; la data resta scrivibile', async () => {
        await monta(entroSceltaDaTe());
        const esito = w!.get('[data-testid="esito-conto"]');
        expect(esito.text().replace(/\s+/g, ' ')).toBe('Arriva tardi: spedita venerdì 04/12 arriverebbe giovedì 10/12, un giorno dopo il termine del 09/12. Per chi la riceve dopo il 09/12 i cinque giorni non ci sono, e la delibera si può impugnare. Se tieni il 04/12, decidi tu.');
        expect(esito.classes().join(' ')).toContain('orange');
        // Sullo schermo largo il riquadro arancio sta solo in «Come conto»: sotto il campo non si ripete.
        expect(w!.find('[data-testid="esito-tardi"]').exists()).toBe(false);
        expect((w!.get('#raccomandate-entro').element as HTMLButtonElement).disabled).toBe(false);
    });

    test('l\'errore del server si legge rosso sotto il campo, senza icona; una data salvata che è passata si mostra con la frase', async () => {
        await monta();
        await scegliIlGiorno(w!.get('#raccomandate-entro'), '2026-12-04');
        (inertia.patch.mock.calls.at(-1)![2] as { onError: (e: Record<string, string>) => void }).onError({ giorno: 'Il giorno è già passato: scegli oggi o un giorno dopo.' });
        await flushPromises();
        const errore = w!.get('[data-testid="errore-data"]');
        expect(errore.text()).toBe('Il giorno è già passato: scegli oggi o un giorno dopo.');
        expect(errore.find('svg').exists()).toBe(false);
        w!.unmount();

        const passata = entroSceltaPrima();
        passata.raccomandate = { ...passata.raccomandate!, entro: '2026-11-25', passata: 'Il giorno è già passato: scegli oggi o un giorno dopo.' };
        await monta(passata);
        expect(giornoMostrato(w!.get('#raccomandate-entro'))).toBe('25/11/2026');
        expect(w!.get('[data-testid="errore-data"]').text()).toBe('Il giorno è già passato: scegli oggi o un giorno dopo.');
    });
});

describe('senza «Gestisce le assemblee»', () => {
    test('la data si legge e non si cambia', async () => {
        await monta({ ...entroSceltaDaTe(), si_cambia: false });
        expect((w!.get('#raccomandate-entro').element as HTMLButtonElement).disabled).toBe(true);
        expect(w!.find('[data-testid="torna-alla-proposta"]').exists()).toBe(false);
    });
});

describe('la destinazione d\'uso (art. 1117-ter)', () => {
    const conLaDestinazione = (siCambia: boolean) => ({
        ...entroProposta(),
        destinazione_uso: {
            frase: 'C\'è un punto sulla destinazione d\'uso delle parti comuni: l\'avviso arriva per raccomandata o PEC entro il 24/11, venti giorni prima, ed è affisso per almeno trenta giorni consecutivi (art. 1117-ter).',
            mancano: ['le date dell\'affissione (almeno trenta giorni consecutivi)'],
            affissione: { dal: null, al: null, si_cambia: siCambia },
        },
    });

    test('la nota dice che cosa manca, e in preparazione «Salva l\'affissione» manda le due date alla modifica dell\'assemblea', async () => {
        await monta(conLaDestinazione(true), '/a/1');
        expect(testo()).toContain('le date dell\'affissione (almeno trenta giorni consecutivi)');
        expect(giornoMostrato(w!.get('[data-testid="affissione-dal"]'))).toBe('gg/mm/aaaa');
        await scegliIlGiorno(w!.get('[data-testid="affissione-dal"]'), '2026-11-01');
        await scegliIlGiorno(w!.get('[data-testid="affissione-al"]'), '2026-11-30');
        expect(inertia.patch).not.toHaveBeenCalled();
        await w!.find('[data-testid="salva-affissione"]').trigger('click');
        expect(inertia.patch).toHaveBeenCalledWith('/a/1', { affissione_dal: '2026-11-01', affissione_al: '2026-11-30' }, expect.objectContaining({ only: ['convocazione', 'passi', 'diario', 'assemblea', 'proposte'] }));
    });

    test('da convocata le date non si scrivono più', async () => {
        await monta(conLaDestinazione(false), '/a/1');
        expect(w!.find('[data-testid="affissione"]').exists()).toBe(false);
    });
});

describe('la proposta non è mai un giorno passato (decisione 662)', () => {
    const FRASE = 'Con la prima convocazione del 14/12 non c\'è il tempo dei cinque giorni (art. 66 c. 3): per raccomandata può essere dal 20/12, per PEC o a mano dal 15/12. '
        + 'Si può convocare lo stesso, ma la delibera è annullabile da chi non è convocato in tempo. Per un lavoro urgente puoi agire subito e riferire alla prima assemblea (art. 1135 c. 2).';

    test('con la prima convocazione troppo vicina la scheda dice la frase e «Sposta le date», che il padre sente', async () => {
        const entro = { ...entroProposta(), vicina: FRASE };
        await monta(entro);
        expect(w!.get('[data-testid="cinque-giorni-vicina"]').text()).toContain(FRASE);
        await w!.get('[data-testid="sposta-le-date-vicina"]').trigger('click');
        expect(w!.emitted('sposta-le-date')).toHaveLength(1);
    });

    test('con il tempo non c\'è, e senza il permesso di cambiare «Sposta le date» non c\'è', async () => {
        await monta(entroProposta());
        expect(w!.find('[data-testid="cinque-giorni-vicina"]').exists()).toBe(false);
        w!.unmount();
        await monta({ ...entroProposta(), vicina: FRASE, si_cambia: false });
        expect(w!.find('[data-testid="cinque-giorni-vicina"]').exists()).toBe(true);
        expect(w!.find('[data-testid="sposta-le-date-vicina"]').exists()).toBe(false);
    });
});
