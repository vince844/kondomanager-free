// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Le finestre del passo «Convocazione», con le persone di Via Roma 12:
 *
 * - «Registra la consegna»: per una riga senza esito l'esito, il giorno, l'ora e la prova vanno al server in un modulo con il file; per una riga che ha
 *   già un esito è una correzione, e «Correggi» resta spento finché non si scrive perché (la correzione tiene il fatto di prima). Gli esiti sono quelli
 *   del mezzo; per una PEC la ricevuta del gestore va al server appena scelta; «Registra il ritiro» è un fatto in più, senza perché.
 * - «Registra la spedizione»: le persone spuntate, il canale, il giorno e il numero di ogni raccomandata in una richiesta sola.
 * - Il foglio delle consegne a mano: una scansione per tutte le persone spuntate, ognuna con il suo giorno.
 * - «Annulla e riconvoca»: il motivo è obbligatorio, e le due date nuove vanno al server come sedute.
 *
 * I giorni si scelgono come a video, nel calendario di `CampoGiorno` (`scegliIlGiorno`), non più scritti con un evento su un campo del browser. Il
 * calendario spegne i giorni dopo oggi (`:max="oggi"`, come il server): le prove fissano oggi al 10/12/2026, dopo i giorni di dicembre che scelgono.
 *
 * Che cosa non copre: le regole del server (il giorno non dopo oggi, la prova che non si toglie, i soli mezzi validi); il menu `v-select` aperto a mano
 * (l'esito di serie è quello della riga); il PDF del foglio (passa da `apriPdfInUnaScheda`, che ha le sue prove).
 */
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { scegliIlGiorno } from '@/components/campoGiornoDiProva';

const inertia = vi.hoisted(() => ({ patch: vi.fn(), post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({ router: inertia }));
vi.mock('axios', () => ({ default: { post: vi.fn() } }));
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const { riga, righeDellaBozza } = await import('./viaRoma12');
const Consegna = (await import('./RegistraLaConsegna.vue')).default;
const Spedizione = (await import('./RegistraLaSpedizione.vue')).default;
const Foglio = (await import('./FoglioDelleConsegne.vue')).default;
const Riconvoca = (await import('./AnnullaERiconvoca.vue')).default;

let w: VueWrapper | null = null;
beforeEach(() => {
    inertia.post.mockClear();
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-12-10T10:00:00'));
});
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; vi.useRealTimers(); });

const nelDialogo = <T extends Element = HTMLElement>(selettore: string) => document.body.querySelector<T>(selettore);
const pulsanteNelDialogo = (testid: string) => nelDialogo<HTMLButtonElement>(`[data-testid="${testid}"]`)!;
async function scrivi(el: HTMLInputElement | HTMLTextAreaElement, valore: string) {
    el.value = valore;
    el.dispatchEvent(new Event('input'));
    await flushPromises();
}

describe('«Registra la consegna»', () => {
    const ROTTA = '/a/1/invii/__INVIO__/consegna';

    test('una riga senza esito: il giorno, l\'ora e la prova vanno al server in un modulo con il file, senza perché', async () => {
        const neri = righeDellaBozza()[3];
        w = mount(Consegna, { attachTo: document.body, props: { open: true, riga: neri, rotta: ROTTA } });
        await flushPromises();
        expect(document.body.textContent).toContain('Registra la consegna');
        expect(nelDialogo('[data-testid="perche-correzione"]')).toBeNull();
        // La «i» ha il suggerimento del programma, che si apre al tocco: non più il title del browser.
        const i = nelDialogo('[data-testid="esempio-consegna"]')!;
        expect(i.getAttribute('title')).toBeNull();
        i.click();
        await flushPromises();
        expect(nelDialogo('[data-testid="suggerimento"] p')?.textContent).toContain('Per una raccomandata in giacenza scrivi il giorno dell\'avviso: conta da lì.');
        i.click();
        await flushPromises();
        await scegliIlGiorno(nelDialogo('#consegna-giorno')!, '2026-12-07');
        await scrivi(nelDialogo<HTMLInputElement>('#consegna-ora')!, '10:14');
        const cartolina = new File(['%PDF'], 'cartolina.pdf', { type: 'application/pdf' });
        const campo = nelDialogo<HTMLInputElement>('[data-testid="file-prova"]')!;
        Object.defineProperty(campo, 'files', { value: [cartolina] });
        campo.dispatchEvent(new Event('change'));
        await flushPromises();
        pulsanteNelDialogo('registra-consegna').click();
        expect(inertia.post).toHaveBeenCalledWith('/a/1/invii/104/consegna',
            { esito: 'consegnata', giorno: '2026-12-07', ora: '10:14', prova: cartolina },
            expect.objectContaining({ forceFormData: true, preserveScroll: true, only: ['convocazione', 'passi', 'diario', 'assemblea', 'proposte'] }));
    });

    test('una riga con un esito è una correzione: «Correggi» resta spento finché non scrivi perché, poi manda il perché', async () => {
        const marco = righeDellaBozza()[2];
        w = mount(Consegna, { attachTo: document.body, props: { open: true, riga: marco, rotta: ROTTA } });
        await flushPromises();
        expect(document.body.textContent).toContain('Correggi la consegna');
        expect(document.body.textContent).toContain('Adesso: In giacenza, avviso del 07/12: conta da qui.');
        const correggi = pulsanteNelDialogo('registra-consegna');
        expect(correggi.textContent?.trim()).toBe('Correggi');
        expect(correggi.disabled).toBe(true);
        correggi.click();
        expect(inertia.post).not.toHaveBeenCalled();
        await scrivi(nelDialogo<HTMLTextAreaElement>('[data-testid="perche-correzione"]')!, 'Sulla cartolina il giorno è il 09/12.');
        expect(correggi.disabled).toBe(false);
        correggi.click();
        expect(inertia.post).toHaveBeenCalledWith('/a/1/invii/103/consegna',
            { esito: 'in_giacenza', giorno: '2026-12-07', perche: 'Sulla cartolina il giorno è il 09/12.' }, expect.objectContaining({ forceFormData: true }));
    });
});

describe('«Registra la consegna»: gli esiti del mezzo, il ritiro, la ricevuta PEC', () => {
    const ROTTA = '/a/1/invii/__INVIO__/consegna';
    const RICEVUTA = '/a/1/invii/__INVIO__/ricevuta';
    const esiti = (w: VueWrapper) => (w.vm as unknown as { $: { setupState: { esiti: { valore: string }[] } } }).$.setupState.esiti.map((e) => e.valore);

    test('per una PEC gli esiti sono «Consegnata» e «Non consegnata»; a mano «Consegnata» e «Rifiutata»; per la raccomandata tutti', async () => {
        const [rossi, anna, , neri] = righeDellaBozza();
        w = mount(Consegna, { attachTo: document.body, props: { open: true, riga: { ...rossi, esito: null }, rotta: ROTTA, rottaRicevuta: RICEVUTA } });
        await flushPromises();
        expect(esiti(w)).toEqual(['consegnata', 'non_consegnata']);
        w.unmount();
        w = mount(Consegna, { attachTo: document.body, props: { open: true, riga: { ...anna, esito: null }, rotta: ROTTA } });
        await flushPromises();
        expect(esiti(w)).toEqual(['consegnata', 'rifiutata']);
        w.unmount();
        w = mount(Consegna, { attachTo: document.body, props: { open: true, riga: neri, rotta: ROTTA } });
        await flushPromises();
        expect(esiti(w)).toEqual(['consegnata', 'in_giacenza', 'rifiutata', 'non_consegnata']);
    });

    test('per una PEC la ricevuta del gestore (.eml) va al server appena scelta, che ne legge il giorno e l\'ora', async () => {
        const rossi = { ...righeDellaBozza()[0], esito: null, ricevuta_il: null };
        w = mount(Consegna, { attachTo: document.body, props: { open: true, riga: rossi, rotta: ROTTA, rottaRicevuta: RICEVUTA } });
        await flushPromises();
        expect(nelDialogo('[data-testid="ricevuta-pec"]')!.textContent).toContain('Il programma legge il giorno e l\'ora della consegna.');
        const ricevuta = new File(['From: posta-certificata@pec.gestore-prova.it'], 'ricevuta-consegna.eml', { type: 'message/rfc822' });
        const campo = nelDialogo<HTMLInputElement>('[data-testid="file-ricevuta"]')!;
        expect(campo.getAttribute('accept')).toBe('.eml');
        Object.defineProperty(campo, 'files', { value: [ricevuta] });
        campo.dispatchEvent(new Event('change'));
        await flushPromises();
        expect(inertia.post).toHaveBeenCalledWith('/a/1/invii/101/ricevuta', { ricevuta }, expect.objectContaining({ forceFormData: true, only: ['convocazione', 'passi', 'diario', 'assemblea', 'proposte'] }));
    });

    test('per una raccomandata non c\'è la ricevuta PEC', async () => {
        w = mount(Consegna, { attachTo: document.body, props: { open: true, riga: righeDellaBozza()[3], rotta: ROTTA, rottaRicevuta: RICEVUTA } });
        await flushPromises();
        expect(nelDialogo('[data-testid="ricevuta-pec"]')).toBeNull();
    });

    test('«Registra il ritiro» su una giacenza: un fatto in più, senza perché, con l\'esito «ritirata»', async () => {
        const marco = righeDellaBozza()[2];
        w = mount(Consegna, { attachTo: document.body, props: { open: true, riga: marco, rotta: ROTTA, ritiro: true } });
        await flushPromises();
        expect(document.body.textContent).toContain('Registra il ritiro');
        expect(nelDialogo('[data-testid="nota-ritiro"]')!.textContent).toContain('Il giorno che conta resta l\'avviso di giacenza, del 07/12');
        expect(nelDialogo('[data-testid="perche-correzione"]')).toBeNull();
        await scegliIlGiorno(nelDialogo('#consegna-giorno')!, '2026-12-08');
        pulsanteNelDialogo('registra-consegna').click();
        expect(inertia.post).toHaveBeenCalledWith('/a/1/invii/103/consegna', { esito: 'ritirata', giorno: '2026-12-08' }, expect.objectContaining({ forceFormData: true }));
    });
});

describe('«Registra la spedizione»', () => {
    test('per le lettere del verbale la finestra parla delle lettere, non delle convocazioni (rilievo C61)', async () => {
        const righe = [riga('Neri Carla', { invio_id: 104, azioni: { spedizione: true, consegna: false, correggi: false, prova: false } })];
        w = mount(Spedizione, { attachTo: document.body, props: { open: true, righe, scelte: null, rotta: '/a/1/verbali/1/spedizione', cosa: 'verbale' } });
        await flushPromises();
        const descrizione = document.body.querySelector('[role="dialog"]')?.textContent ?? '';
        expect(descrizione).toContain('Le lettere del verbale stampate che hai spedito o consegnato');
        expect(descrizione).not.toContain('convocazioni');
    });

    test('le persone spuntate, la raccomandata, il giorno e il numero di ognuna, in una richiesta sola', async () => {
        const righe = [
            riga('Neri Carla', { invio_id: 104, azioni: { spedizione: true, consegna: false, correggi: false, prova: false } }),
            riga('Russo Marco', { invio_id: 103, azioni: { spedizione: true, consegna: false, correggi: false, prova: false } }),
        ];
        w = mount(Spedizione, { attachTo: document.body, props: { open: true, righe, scelte: null, rotta: '/a/1/convocazione/spedizione' } });
        await flushPromises();
        await scegliIlGiorno(nelDialogo('#spedizione-giorno')!, '2026-12-03');
        await scrivi(nelDialogo<HTMLInputElement>('[aria-label="Numero della raccomandata di Neri Carla"]')!, '61 2345 6789');
        const registra = pulsanteNelDialogo('registra-spedizione');
        expect(registra.textContent?.trim()).toBe('Registra la spedizione (2)');
        registra.click();
        expect(inertia.post).toHaveBeenCalledWith('/a/1/convocazione/spedizione',
            { invii: [104, 103], canale: 'raccomandata', data: '2026-12-03', numeri: { 104: '61 2345 6789' }, nota: undefined },
            expect.objectContaining({ preserveScroll: true, only: ['convocazione', 'passi', 'diario', 'assemblea', 'proposte'] }));
    });

    test('il giorno proposto è quello di Roma, dove il programma conta i giorni: alle 00:30 del 22/11 a Roma (23:30 del 21/11 a Londra) propone il 22/11 e lo lascia scegliere (rilievo C54)', async () => {
        vi.setSystemTime(new Date('2026-11-21T23:30:00Z'));
        const righe = [riga('Neri Carla', { invio_id: 104, azioni: { spedizione: true, consegna: false, correggi: false, prova: false } })];
        w = mount(Spedizione, { attachTo: document.body, props: { open: true, righe, scelte: null, rotta: '/a/1/convocazione/spedizione' } });
        await flushPromises();
        pulsanteNelDialogo('registra-spedizione').click();
        expect(inertia.post).toHaveBeenCalledWith('/a/1/convocazione/spedizione', expect.objectContaining({ data: '2026-11-22' }), expect.anything());
    });

    test('aperta dal pulsante del passo sono spuntate tutte le stampate non ancora spedite, e «Tutte» e «Nessuna» in testa all\'elenco cambiano la scelta', async () => {
        const righe = Array.from({ length: 15 }, (_, i) => riga(`Persona ${i + 1}`, { invio_id: 200 + i, chiave: `p${i}`, azioni: { spedizione: true, consegna: false, correggi: false, prova: false } }));
        w = mount(Spedizione, { attachTo: document.body, props: { open: true, righe, scelte: null, rotta: '/a/1/convocazione/spedizione' } });
        await flushPromises();
        expect(pulsanteNelDialogo('registra-spedizione').textContent?.trim()).toBe('Registra la spedizione (15)');
        pulsanteNelDialogo('spedizione-nessuna').click();
        await flushPromises();
        expect(pulsanteNelDialogo('registra-spedizione').textContent?.trim()).toBe('Registra la spedizione (0)');
        expect(pulsanteNelDialogo('registra-spedizione').disabled).toBe(true);
        pulsanteNelDialogo('spedizione-tutte').click();
        await flushPromises();
        expect(pulsanteNelDialogo('registra-spedizione').textContent?.trim()).toBe('Registra la spedizione (15)');
    });

    test('la spedizione di oggi dopo l\'ultimo giorno utile: la frase del canale, «Sposta le date», e si registra lo stesso (M3)', async () => {
        const righe = [riga('Neri Carla', { invio_id: 104 })];
        const tardive = { raccomandata: 'Spedita il 10/12: con la raccomandata arriva dopo il 09/12, l\'ultimo giorno utile per la prima convocazione del 14/12 (art. 66 disp. att.). '
            + 'Per stare nei termini la prima convocazione può essere dal 18/12.', fax: null, a_mano: null };
        w = mount(Spedizione, { attachTo: document.body, props: { open: true, righe, scelte: null, rotta: '/a/1/convocazione/spedizione', tardive } });
        await flushPromises();
        expect(nelDialogo('[data-testid="spedizione-tardiva"]')!.textContent).toContain('Per stare nei termini la prima convocazione può essere dal 18/12.');
        pulsanteNelDialogo('sposta-le-date').click();
        expect(w.emitted('sposta-le-date')).toHaveLength(1);
        const registra = pulsanteNelDialogo('registra-spedizione');
        expect(registra.disabled).toBe(false);
        registra.click();
        expect(inertia.post).toHaveBeenCalled();
    });

    test('senza ritardo la frase non c\'è', async () => {
        w = mount(Spedizione, { attachTo: document.body, props: { open: true, righe: [riga('Neri Carla', { invio_id: 104 })], scelte: null, rotta: '/a/1/c/spedizione',
            tardive: { raccomandata: null, fax: null, a_mano: null } } });
        await flushPromises();
        expect(nelDialogo('[data-testid="spedizione-tardiva"]')).toBeNull();
    });

    test('aperta dal menu di una riga, è spuntata solo quella', async () => {
        const righe = [riga('Neri Carla', { invio_id: 104 }), riga('Russo Marco', { invio_id: 103 })];
        w = mount(Spedizione, { attachTo: document.body, props: { open: true, righe, scelte: [103], rotta: '/a/1/convocazione/spedizione' } });
        await flushPromises();
        expect(pulsanteNelDialogo('registra-spedizione').textContent?.trim()).toBe('Registra la spedizione (1)');
    });
});

describe('il foglio delle consegne a mano', () => {
    test('una scansione per le persone spuntate, ognuna con il giorno in cui ha firmato', async () => {
        const righe = [
            riga('Ferri Luca', { anagrafica_id: 6, invio_id: 106, mezzo: 'a_mano', mezzo_parola: 'A mano', stato: 'da_consegnare' }),
            riga('Russo Anna', { anagrafica_id: 2, invio_id: 102, mezzo: 'a_mano', mezzo_parola: 'A mano', stato: 'da_consegnare' }),
        ];
        w = mount(Foglio, { attachTo: document.body, props: { open: true, righe, rotte: { foglio: '/f', foglio_firmato: '/f/firmato' } } });
        await flushPromises();
        const carica = pulsanteNelDialogo('carica-foglio');
        expect(carica.disabled).toBe(true);
        const scansione = new File(['%PDF'], 'foglio-firmato.pdf', { type: 'application/pdf' });
        const campo = nelDialogo<HTMLInputElement>('[data-testid="file-foglio"]')!;
        Object.defineProperty(campo, 'files', { value: [scansione] });
        campo.dispatchEvent(new Event('change'));
        await scegliIlGiorno(nelDialogo('#foglio-giorno-106')!, '2026-12-04');
        await scegliIlGiorno(nelDialogo('#foglio-giorno-102')!, '2026-12-05');
        expect(carica.disabled).toBe(false);
        carica.click();
        expect(inertia.post).toHaveBeenCalledWith('/f/firmato',
            { foglio: scansione, consegne: [{ invio_id: 106, giorno: '2026-12-04' }, { invio_id: 102, giorno: '2026-12-05' }] },
            expect.objectContaining({ forceFormData: true }));
    });
});

describe('«Annulla e riconvoca»', () => {
    test('il motivo è obbligatorio; le due date nuove vanno al server come sedute', async () => {
        w = mount(Riconvoca, { attachTo: document.body, props: { open: true, riconvoca: { si_puo: true, perche: null, costo: 'Ventisette avvisi da rifare.' }, rotta: '/a/1/riconvoca' } });
        await flushPromises();
        const conferma = pulsanteNelDialogo('conferma-riconvocazione');
        await scegliIlGiorno(nelDialogo('[aria-label="Il giorno della prima convocazione"]')!, '2026-12-21');
        await scegliIlGiorno(nelDialogo('[aria-label="Il giorno della seconda convocazione"]')!, '2026-12-22');
        expect(conferma.disabled).toBe(true);
        await scrivi(nelDialogo<HTMLTextAreaElement>('[data-testid="motivo-riconvocazione"]')!, 'Va aggiunto il punto del portone.');
        expect(conferma.disabled).toBe(false);
        conferma.click();
        expect(inertia.post).toHaveBeenCalledWith('/a/1/riconvoca', {
            motivo: 'Va aggiunto il punto del portone.',
            sedute: [
                { convocazione: 'prima', numero: 1, fissata_il: '2026-12-21 08:00' },
                { convocazione: 'seconda', numero: 1, fissata_il: '2026-12-22 18:30' },
            ],
        }, expect.anything());
    });

    // Vincenzo, 21/11/2026 sera: «non riesco a annullare se non imposto anche la seconda convocazione, ma questa credo che debba essere opzionale».
    // La seconda è facoltativa (come in «Prepara l'assemblea»); le date si controllano mentre si scrivono; «Chiudi» chiude senza annullare niente.
    test('la seconda convocazione è facoltativa; le date si controllano mentre scrivi; «Chiudi» non si confonde con «Annulla»', async () => {
        w = mount(Riconvoca, { attachTo: document.body, props: { open: true, riconvoca: { si_puo: true, perche: null, costo: null }, rotta: '/a/1/riconvoca' } });
        await flushPromises();
        const conferma = pulsanteNelDialogo('conferma-riconvocazione');
        await scrivi(nelDialogo<HTMLTextAreaElement>('[data-testid="motivo-riconvocazione"]')!, 'Va aggiunto il punto del portone.');
        await scegliIlGiorno(nelDialogo('[aria-label="Il giorno della prima convocazione"]')!, '2026-12-21');
        expect(conferma.disabled).toBe(false);
        expect(nelDialogo('[data-testid="date-riconvocazione"]')?.textContent).toContain('Solo la prima convocazione');
        await scegliIlGiorno(nelDialogo('[aria-label="Il giorno della seconda convocazione"]')!, '2026-12-20');
        expect(nelDialogo('[data-testid="date-riconvocazione"]')?.textContent).toContain('La seconda convocazione viene dopo la prima');
        expect(conferma.disabled).toBe(true);
        // Solo la seconda è svuotabile: la sua «×» la toglie.
        (nelDialogo<HTMLElement>('[aria-label="Togli il giorno"]')!).click();
        await flushPromises();
        expect(conferma.disabled).toBe(false);
        conferma.click();
        expect(inertia.post).toHaveBeenLastCalledWith('/a/1/riconvoca', {
            motivo: 'Va aggiunto il punto del portone.',
            sedute: [{ convocazione: 'prima', numero: 1, fissata_il: '2026-12-21 08:00' }],
        }, expect.anything());
        expect(pulsanteNelDialogo('chiudi-riconvocazione').textContent?.trim()).toBe('Chiudi');
    });
});
