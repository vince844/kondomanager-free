// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il passo «Convocazione» della pagina dell'assemblea (scena 2 della bozza approvata, con «Entro quando spedire» della scena 3 delle scene della tappa 2),
 * con le persone di Via Roma 12: la tabella a colonne fisse con le parole degli stati e le prove, le prime otto righe e «Mostra tutti», «Stampa le lettere
 * (5)» e «Manda le PEC (11)», il pulsante delle PEC spento con il perché quando la casella dello studio non c'è, la riga sola delle PEC non comunicate, la
 * nota arancio del venduto, «Avviso anche in bacheca», «Stampa la prova», l'email semplice che non vale.
 * - In basso, senza spazi vuoti (la bozza approvata del passo): gli avvisi delle unità vendute sopra, a tutta larghezza; sotto, «Avviso anche in bacheca» e
 *   «La prova della convocazione» in due card affiancate e alte uguali, la seconda con «Stampa la prova», l'email semplice e «Annulla e riconvoca».
 *
 * Che cosa non copre: i dati (li calcola il server, `StatoDellaConvocazione`); il PDF vero che si apre nella scheda nuova (il file arriva da un POST e
 * `apriPdfInUnaScheda` ha le sue prove); le finestre di spedizione, consegna, foglio e riconvocazione (hanno le loro prove); la pagina che monta la scheda.
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const inertia = vi.hoisted(() => ({ patch: vi.fn(), post: vi.fn(), reload: vi.fn() }));
const file = vi.hoisted(() => ({ chiesto: [] as unknown[] }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia, Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } }));
vi.mock('axios', () => ({ default: { post: vi.fn(async (...a: unknown[]) => { file.chiesto.push(a); return { data: new Blob(['%PDF']), headers: {} }; }) } }));
vi.mock('@/lib/gestionale/incassi/ricevute', async (originale) => ({
    ...(await originale<typeof import('@/lib/gestionale/incassi/ricevute')>()),
    apriPdfInUnaScheda: vi.fn(async (chiedi: () => Promise<unknown>) => { await chiedi(); return { modo: 'scheda', nome: 'convocazioni.pdf' }; }),
}));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const { schedaDellaBozza } = await import('./viaRoma12');
const Scheda = (await import('./SchedaConvocazione.vue')).default;

let w: VueWrapper | null = null;
beforeEach(() => { inertia.post.mockClear(); inertia.reload.mockClear(); file.chiesto = []; });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function monta(convocazione: Record<string, unknown> = schedaDellaBozza() as unknown as Record<string, unknown>) {
    w = mount(Scheda, { attachTo: document.body, props: { convocazione } });
    await flushPromises();

    return w;
}
const testo = () => (document.body.textContent ?? '').replace(/\s+/g, ' ');
const pulsante = (etichetta: string) => w!.findAll('button').find((b) => b.text().replace(/\s+/g, ' ').trim() === etichetta);
const righeDellaTabella = () => w!.findAll('[data-testid="tabella-convocati"] [data-testid="riga-convocato"]');

describe('la scheda vuota', () => {
    test('senza le date dice che la convocazione non è ancora spedita, con parole semplici', async () => {
        await monta({});
        expect(w!.find('[data-testid="convocazione-vuota"]').text()).toContain('Non ancora spedita');
        expect(w!.find('[data-testid="tabella-convocati"]').exists()).toBe(false);
    });
});

describe('la tabella dei convocati', () => {
    test('il titolo con il numero, la descrizione, le parole degli stati con il dettaglio e le prove', async () => {
        await monta();
        const t = testo();
        expect(w!.find('[data-testid="titolo-convocati"]').text().replace(/\s+/g, ' ')).toBe('Convocati 27');
        expect(t).toContain('I due comproprietari dell\'interno 5 ricevono un avviso ciascuno.');
        const stati = w!.findAll('[data-testid="tabella-convocati"] [data-testid="stato-convocato"]').map((s) => s.text());
        expect(stati.slice(0, 7)).toEqual(['Consegnata', 'Consegnata', 'In giacenza', 'Spedita', 'Consegnata', 'Da consegnare', 'Non convocabile']);
        expect(t).toContain('avviso del 07/12: conta da qui');
        expect(t).toContain('04/12 · n. 61 2345 6789');
        expect(t).toContain('usufruttuaria');
        expect(t).toContain('conduttore, per il punto 5');
        expect(t).toContain('ha solo l\'email: aggiungi la PEC o l\'indirizzo');
        const ricevuta = w!.find('[data-testid="tabella-convocati"] a[href="/prova/1"]');
        expect(ricevuta.text()).toBe('Ricevuta');
        expect(w!.find('[data-testid="tabella-convocati"]').classes()).toContain('table-fixed');
    });

    test('«Vedi chi» di «Che cosa manca» (`#convocati`) mostra tutte le righe: chi manca può stare dopo l\'ottava', async () => {
        await monta();
        expect(righeDellaTabella()).toHaveLength(8);
        window.location.hash = '#convocati';
        window.dispatchEvent(new HashChangeEvent('hashchange'));
        await flushPromises();
        window.location.hash = '';
        expect(righeDellaTabella()).toHaveLength(27);
    });

    test('le prime otto righe, poi «E altri 19 · Mostra tutti», che le mostra tutte', async () => {
        await monta();
        expect(righeDellaTabella()).toHaveLength(8);
        expect(testo()).toContain('E altri 19');
        await pulsante('Mostra tutti')!.trigger('click');
        expect(righeDellaTabella()).toHaveLength(27);
        expect(pulsante('Mostra solo i primi 8')).toBeDefined();
    });
});

describe('i pulsanti', () => {
    test('«Stampa le lettere (5)» chiede il PDF con un POST e ricarica la scheda; «Manda le PEC (11)» manda con un POST', async () => {
        await monta();
        await pulsante('Stampa le lettere (5)')!.trigger('click');
        await flushPromises();
        expect(file.chiesto.at(-1)).toEqual(['/a/1/convocazione/lettere', { formato: 'pdf' }, { responseType: 'blob' }]);
        // La prima spedizione cambia lo stato dell'assemblea: la visita rilegge anche l'assemblea (il badge, le date, l'ordine del giorno) e i proposti.
        expect(inertia.reload).toHaveBeenCalledWith({ only: ['convocazione', 'passi', 'diario', 'assemblea', 'proposte'] });
        // La stampa non spedisce: la frase lo dice.
        expect(w!.get('[data-testid="esito-scheda"]').text()).toContain('Le lettere sono stampate, non ancora spedite: registra la spedizione quando le spedisci. '
            + 'L\'assemblea diventa «convocata» alla prima spedizione registrata.');
        await pulsante('Manda le PEC (11)')!.trigger('click');
        expect(inertia.post).toHaveBeenLastCalledWith('/a/1/convocazione/pec', {}, expect.objectContaining({ preserveScroll: true,
            only: ['convocazione', 'passi', 'diario', 'assemblea', 'proposte'] }));
    });

    test('con le lettere stampate prima che cambiassero date o punti: «Hai stampato le lettere il 26/11: ristampale»', async () => {
        const scheda = schedaDellaBozza() as unknown as Record<string, any>;
        scheda.lettere = { ...scheda.lettere, ristampa: 'Hai stampato le lettere il 26/11: ristampale, perché l\'avviso è cambiato.' };
        await monta(scheda);
        const nota = w!.get('[data-testid="ristampa"]');
        expect(nota.text()).toBe('Hai stampato le lettere il 26/11: ristampale, perché l\'avviso è cambiato.');
        w!.unmount();
        await monta();
        expect(w!.find('[data-testid="ristampa"]').exists()).toBe(false);
    });

    test('i perché dei pulsanti spenti stanno in una riga sotto l\'intestazione, larga quanto la scheda, e non schiacciano la descrizione', async () => {
        await monta(schedaDellaBozza({
            lettere: { quante: 2, attivo: false, perche: 'La spedizione non parte: l\'avviso deve dire dove si tiene l\'assemblea (art. 66 c. 3).' },
            pec: { quante: 1, attivo: false, perche: 'Prima imposta la casella PEC dello studio.' },
        }) as never);
        const riga = w!.get('[data-testid="perche-spedizione"]');
        expect(riga.find('[data-testid="perche-lettere"]').exists()).toBe(true);
        expect(riga.find('[data-testid="perche-pec"]').exists()).toBe(true);
        const pulsanti = w!.get('[data-testid="pulsanti-convocati"]');
        expect(pulsanti.find('[data-testid="perche-lettere"]').exists()).toBe(false);
        expect(pulsanti.find('[data-testid="perche-pec"]').exists()).toBe(false);
        expect(w!.get('[data-testid="descrizione-convocati"]').element.parentElement!.className).toContain('flex-1');
    });

    test('senza la casella PEC dello studio «Manda le PEC» è spento e dice perché', async () => {
        await monta(schedaDellaBozza({ pec: { quante: 11, attivo: false, perche: 'Prima imposta la casella PEC dello studio.' } }) as never);
        const pec = pulsante('Manda le PEC (11)')!;
        expect(pec.attributes('disabled')).toBeDefined();
        expect(w!.find('[data-testid="perche-pec"]').text()).toBe('Solo le PEC: Prima imposta la casella PEC dello studio.');
        expect(w!.find('[data-testid="perche-pec"] a').exists()).toBe(false);
        await pec.trigger('click');
        expect(inertia.post).not.toHaveBeenCalled();
    });

    test('la spedizione registrata fuori dai termini: la frase in arancio con «Sposta le date» (M3)', async () => {
        await monta(schedaDellaBozza({ tardiva: { frase: 'Spedita il 21/11: con la raccomandata arriva dopo il 16/11.', dal: '2026-12-03' } }) as never);
        expect(w!.get('[data-testid="spedizione-tardiva-scheda"]').text()).toContain('Spedita il 21/11: con la raccomandata arriva dopo il 16/11.');
        expect(w!.find('[data-testid="sposta-le-date-scheda"]').exists()).toBe(true);
    });

    test('senza ritardo la frase non c\'è', async () => {
        await monta(schedaDellaBozza({ tardiva: null }) as never);
        expect(w!.find('[data-testid="spedizione-tardiva-scheda"]').exists()).toBe(false);
    });

    test('le PEC non comunicate in una riga sola, sopra la tabella', async () => {
        await monta(schedaDellaBozza({ pec_non_comunicate: '11 PEC non risultano comunicate dalla persona: segnalo nella sua scheda, o scegli la raccomandata' }) as never);
        expect(w!.findAll('[data-testid="pec-non-comunicate"]')).toHaveLength(1);
        expect(testo()).toContain('11 PEC non risultano comunicate dalla persona: segnalo nella sua scheda, o scegli la raccomandata');
    });

    test('senza «Gestisce le assemblee» la scheda si legge, senza i pulsanti che cambiano qualcosa', async () => {
        await monta(schedaDellaBozza({ gestisce: false }) as never);
        expect(pulsante('Stampa le lettere (5)')).toBeUndefined();
        expect(pulsante('Manda le PEC (11)')).toBeUndefined();
        expect(w!.find('[data-testid="menu-riga"]').exists()).toBe(false);
        expect(pulsante('Annulla e riconvoca')).toBeUndefined();
    });
});

describe('sotto la tabella', () => {
    test('la nota del venduto, l\'avviso in bacheca con «Adesso», la prova della convocazione, l\'email semplice', async () => {
        await monta();
        const t = testo();
        expect(t).toContain('Dopo l\'invio, l\'interno 7 è stato venduto.');
        expect(t).toContain('Il passaggio da Gallo Enzo a Verdi Sara è registrato con decorrenza 10/12.');
        expect(t).toContain('Avviso anche in bacheca');
        expect(t).toContain('Adesso: pubblicato il 03/12.');
        expect(t).toContain('Chi ha ricevuto, quando e come; chi manca.');
        expect(t).toContain('L\'email semplice si può mandare come avviso in più, ma non vale come convocazione (art. 66 c. 3; Cass. 16399/2025).');
        await pulsante('Stampa la prova')!.trigger('click');
        await flushPromises();
        expect(file.chiesto.at(-1)).toEqual(['/a/1/convocazione/prova', {}, { responseType: 'blob' }]);
    });

    test('gli avvisi delle unità vendute sopra, a tutta larghezza; sotto due card affiancate e alte uguali: la bacheca e la prova, con «Annulla e riconvoca»', async () => {
        await monta();
        const venduti = w!.get('[data-testid="venduti"]');
        const due = w!.get('[data-testid="due-card"]');
        // I venduti non stanno in una colonna della griglia: sono sopra, a tutta larghezza.
        expect(due.element.contains(venduti.element)).toBe(false);
        expect(venduti.element.compareDocumentPosition(due.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(due.classes()).toEqual(expect.arrayContaining(['grid', 'items-stretch', 'lg:grid-cols-2']));
        const carte = due.element.children;
        expect(carte).toHaveLength(2);
        expect((carte[0] as HTMLElement).dataset.testid).toBe('card-bacheca');
        expect((carte[1] as HTMLElement).dataset.testid).toBe('card-prova');
        const bacheca = w!.get('[data-testid="card-bacheca"]').text().replace(/\s+/g, ' ');
        expect(bacheca).toContain('Avviso anche in bacheca');
        expect(bacheca).toContain('Adesso: pubblicato il 03/12.');
        const prova = w!.get('[data-testid="card-prova"]').text().replace(/\s+/g, ' ');
        expect(prova).toContain('La prova della convocazione');
        expect(prova).toContain('Chi ha ricevuto, quando e come; chi manca.');
        expect(prova).toContain('Stampa la prova');
        // Decisione 652 (2): la card tiene solo la prova; l'email semplice va sotto la tabella dei convocati, «Annulla e riconvoca» nel menu «Azioni».
        expect(prova).not.toContain('L\'email semplice');
        expect(prova).not.toContain('Annulla e riconvoca');
        expect(w!.get('[data-testid="testa-prova"]').classes()).toContain('sm:flex-nowrap');
        expect(w!.get('[data-testid="email-semplice"]').text()).toContain('L\'email semplice si può mandare come avviso in più, ma non vale come convocazione');
    });

    test('senza unità vendute non resta uno spazio: le due card stanno subito sotto la tabella', async () => {
        await monta(schedaDellaBozza({ venduti: [] }) as unknown as Record<string, unknown>);
        expect(w!.find('[data-testid="venduti"]').exists()).toBe(false);
        expect(w!.find('[data-testid="due-card"]').exists()).toBe(true);
    });

    test('«Annulla e riconvoca» (dal menu «Azioni» della pagina, con la prop `apriRiconvoca`) apre la finestra con il costo scritto', async () => {
        await monta();
        await w!.setProps({ apriRiconvoca: 1 } as never);
        await flushPromises();
        expect(document.body.querySelector('[data-testid="costo-riconvocazione"]')?.textContent)
            .toContain('Ventisette avvisi da rifare');
    });
});

describe('mentre partono, e la ricevuta trascinata', () => {
    test('«Manda le PEC»: mentre la visita lavora il pulsante dice «Sto mandando le PEC…», è spento, e un secondo clic non manda niente', async () => {
        await monta();
        await w!.find('[data-testid="manda-le-pec"]').trigger('click');
        expect(inertia.post).toHaveBeenCalledTimes(1);
        const pulsante = w!.find('[data-testid="manda-le-pec"]');
        expect(pulsante.text()).toContain('Sto mandando le PEC…');
        expect(pulsante.attributes('disabled')).toBeDefined();
        await pulsante.trigger('click');
        expect(inertia.post).toHaveBeenCalledTimes(1);
        // A visita finita il pulsante torna com'era.
        (inertia.post.mock.calls[0][2] as { onFinish: () => void }).onFinish();
        await flushPromises();
        expect(w!.find('[data-testid="manda-le-pec"]').text()).toContain('Manda le PEC (11)');
    });

    test('«Stampa le lettere»: mentre il PDF arriva il pulsante dice «Sto stampando le lettere…»', async () => {
        await monta();
        const attesa = w!.find('[data-testid="stampa-le-lettere"]').trigger('click');
        await attesa;
        expect(w!.find('[data-testid="stampa-le-lettere"]').text()).toContain('Sto stampando le lettere…');
        await flushPromises();
        expect(w!.find('[data-testid="stampa-le-lettere"]').text()).toContain('Stampa le lettere (5)');
    });

    test('in videoconferenza senza il consenso registrato una riga lo dice', async () => {
        await monta(schedaDellaBozza({ videoconferenza: 'Per la videoconferenza serve il consenso della maggioranza dei condòmini (art. 66 c. 6): nel passo «Date e ordine del giorno» non c\'è il giorno in cui è stato dato. Il programma non blocca: decidi tu.' }) as unknown as Record<string, unknown>);
        expect(w!.find('[data-testid="consenso-videoconferenza"]').text()).toContain('Il programma non blocca: decidi tu.');
    });

    test('la ricevuta PEC trascinata sulla riga di Rossi Mario va alla rotta della ricevuta di quell\'invio', async () => {
        await monta();
        const rossi = righeDellaTabella()[0];
        expect(rossi.text()).toContain('Rossi Mario');
        const ricevuta = new File(['From: posta-certificata@pec.gestore-prova.it'], 'ricevuta-consegna.eml', { type: 'message/rfc822' });
        await rossi.trigger('dragover', { dataTransfer: { files: [ricevuta] } });
        await rossi.trigger('drop', { dataTransfer: { files: [ricevuta] } });
        expect(inertia.post).toHaveBeenCalledWith('/a/1/invii/101/ricevuta', { ricevuta }, expect.objectContaining({ forceFormData: true, only: ['convocazione', 'passi', 'diario', 'assemblea', 'proposte'] }));
        // Su una riga senza la ricevuta (una raccomandata) il file non va da nessuna parte.
        inertia.post.mockClear();
        await righeDellaTabella()[3].trigger('drop', { dataTransfer: { files: [ricevuta] } });
        expect(inertia.post).not.toHaveBeenCalled();
    });
});

describe('ogni perché col suo pulsante (decisione 647)', () => {
    const LUOGO = 'Manca il luogo della prima e della seconda convocazione (art. 66 c. 3). Scrivilo nel passo «Date e ordine del giorno».';
    const LUNGO = 'La spedizione non parte: l\'avviso deve dire dove si tiene l\'assemblea (art. 66 c. 3). Manca il luogo della prima e della seconda convocazione: scrivilo nel passo «Date e ordine del giorno».';
    const CASELLA = 'Prima imposta la casella PEC dello studio.';

    test('il luogo che manca spegne tutti e due: una riga sola «Lettere e PEC» col link al passo delle date, e la casella PEC mancante è «Solo le PEC»', async () => {
        const scheda = schedaDellaBozza({
            lettere: { quante: 2, attivo: false, perche: LUNGO },
            pec: { quante: 1, attivo: false, perche: CASELLA, dove: '/impostazioni/mail?apri=pec' },
            comune: { perche: LUOGO, lungo: LUNGO },
        }) as unknown as Record<string, any>;
        scheda.rotte.date = '/a/1?scheda=date';
        await monta(scheda);
        const righe = w!.findAll('[data-testid="perche-spedizione"] p');
        expect(righe.map((r) => r.text().replace(/\s+/g, ' '))).toEqual([`Lettere e PEC: ${LUOGO}`, `Solo le PEC: ${CASELLA}`]);
        expect(w!.get('[data-testid="perche-comune"] a').attributes('href')).toBe('/a/1?scheda=date');
        expect(w!.get('[data-testid="perche-pec"] a').attributes('href')).toBe('/impostazioni/mail?apri=pec');
        // Una sola riga per il luogo: la frase lunga delle lettere non si ripete.
        expect(testo()).not.toContain('La spedizione non parte');
    });

    test('il `title` di ogni pulsante spento dice la stessa cosa della sua riga', async () => {
        const scheda = schedaDellaBozza({
            lettere: { quante: 2, attivo: false, perche: LUNGO },
            pec: { quante: 1, attivo: false, perche: CASELLA, dove: '/impostazioni/mail?apri=pec' },
            comune: { perche: LUOGO, lungo: LUNGO },
        }) as unknown as Record<string, any>;
        await monta(scheda);
        expect(pulsante('Stampa le lettere (5)')!.attributes('title')).toBe(`Lettere e PEC: ${LUOGO}`);
        expect(pulsante('Manda le PEC (11)')!.attributes('title')).toBe(`Lettere e PEC: ${LUOGO}`);
    });

    test('la stessa frase per tutti e due (l\'assemblea non è più da convocare) si scrive una volta sola', async () => {
        const stessa = 'L\'assemblea non è più da convocare.';
        await monta(schedaDellaBozza({ lettere: { quante: 2, attivo: false, perche: stessa }, pec: { quante: 1, attivo: false, perche: stessa } }) as never);
        expect(w!.findAll('[data-testid="perche-spedizione"] p').map((r) => r.text())).toEqual([`Lettere e PEC: ${stessa}`]);
    });

    test('un perché solo delle PEC non tocca le lettere: «Solo le PEC», e il pulsante delle lettere resta acceso e senza `title`', async () => {
        await monta(schedaDellaBozza({ pec: { quante: 11, attivo: false, perche: CASELLA } }) as never);
        expect(w!.findAll('[data-testid="perche-spedizione"] p')).toHaveLength(1);
        expect(pulsante('Stampa le lettere (5)')!.attributes('disabled')).toBeUndefined();
        expect(pulsante('Stampa le lettere (5)')!.attributes('title')).toBeUndefined();
        expect(pulsante('Manda le PEC (11)')!.attributes('title')).toBe(`Solo le PEC: ${CASELLA}`);
    });
});
