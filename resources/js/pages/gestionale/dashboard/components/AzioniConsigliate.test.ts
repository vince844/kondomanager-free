// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il cassetto «Tesoreria: che cosa puoi fare», con le cifre dell'esempio della guida e Chart.js finto. Dall'alto: la situazione in una frase; il grafico
 * con tre linee e la legenda; la simulazione con il cursore, la tacca di «quanto basta» e le tre cifre che lo seguono; le azioni consigliate, ognuna con i suoi
 * numeri e il suo pulsante; la riga sulle scadenze delle rate. Con «Scelgo io» niente azioni. «Chiudi» scritto. «Giorni sotto zero: nessuno» è grigio, come
 * ogni zero; i giorni sotto zero sono rossi.
 *
 * Le sei in più, con i dati del server: nell'ordine (le ritenute, i bonifici, le rate da emettere, il recupero, le fatture, il giroconto, il fondo, il piano
 * straordinario), ognuna con il pulsante verso la pagina dove la cosa si fa e quello che serve già scelto; il TFR mai; senza il permesso dell'agenda i bonifici
 * senza pulsante; con «Scelgo io» nessuna.
 *
 * Il recupero con le persone in una tabella sotto la frase (al telefono una card per persona); «Prepara l'F24» verso la delega della scadenza, quando ce n'è
 * una sola.
 *
 * Le rate da emettere: «Emetti la rata» apre la finestra «Emetti le rate» sul piano dell'azione; dopo l'emissione l'esito si legge in testa alle azioni, con il
 * suggerimento dei crediti in un capoverso suo, finché il cassetto resta aperto.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { defineComponent, h } from 'vue';

const disegni = vi.hoisted(() => ({ ultimi: [] as Array<{ data: any; options: any; plugins: any }> }));
vi.mock('vue-chartjs', () => ({
    Line: defineComponent({
        name: 'LineFinto',
        props: ['data', 'options', 'plugins'],
        setup(props) {
            // Le props stesse, non una copia: dopo un cambio si leggono i dati di adesso.
            disegni.ultimi.push(props as any);

            return () => h('canvas', { 'data-testid': 'canvas' });
        },
    }),
}));
vi.mock('chart.js', () => ({ Chart: { register: vi.fn() }, LineController: {}, LineElement: {}, PointElement: {}, LinearScale: {}, CategoryScale: {}, Filler: {}, Tooltip: {} }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: vi.fn(), reload: vi.fn() },
}));
const permessi = vi.hoisted(() => ({ agenda: true }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, hasPermission: () => permessi.agenda }) }));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}${p ? '?' + new URLSearchParams(p as Record<string, string>).toString() : ''}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const AzioniConsigliate = (await import('./AzioniConsigliate.vue')).default;
const { datiDelleAzioniDellEsempio, recuperoDellEsempio, saldoDellEsempio } = await import('./tesoreriaDiProva');

const Lettere = { name: 'LettereDialog', props: ['open', 'condominioId', 'persona', 'selezione', 'tipoIniziale', 'oggi'], template: '<div v-if="open" data-testid="lettere-finte" />' };
const Emissione = { name: 'EmissioneRapidaDialog', props: ['open', 'condominioId', 'pianoId', 'oggi'], emits: ['update:open', 'emesse'],
    template: '<div v-if="open" data-testid="emissione-finta" :data-piano="pianoId" :data-condominio="condominioId" :data-oggi="oggi" />' };
let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; disegni.ultimi = []; permessi.agenda = true; });

async function apri(props: Record<string, unknown> = {}) {
    w = mount(AzioniConsigliate, {
        attachTo: document.body,
        props: { open: true, saldoPrevisto: saldoDellEsempio(), recupero: recuperoDellEsempio(), suggerisce: true, condominioId: 7, oggi: '2026-11-15', ...props } as any,
        global: { stubs: { LettereDialog: Lettere, EmissioneRapidaDialog: Emissione } },
    });
    await vi.dynamicImportSettled();
    await flushPromises();
}
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const q = (id: string) => document.body.querySelector(`[data-testid="${id}"]`) as HTMLElement | null;
const tutti = (id: string) => [...document.body.querySelectorAll(`[data-testid="${id}"]`)] as HTMLElement[];
const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/dashboard/components/AzioniConsigliate.vue'), 'utf8');

describe('il cassetto delle azioni consigliate', () => {
    test('il titolo, la situazione in una frase con il rosso e il grassetto, «Chiudi» scritto', async () => {
        await apri();
        expect(pulito(q('cassetto-azioni')?.textContent)).toContain('Tesoreria: che cosa puoi fare');
        expect(pulito(q('situazione')?.textContent)).toBe('Il conto va sotto zero il 20/11 di € 2.368,77, per le fatture che scadono quel giorno. '
            + 'Il 30/11 entrano € 2.400,00 di rate. Intanto ci sono € 6.592,97 di rate già scadute.');
        expect(pulito(q('situazione')?.querySelector('.text-red-600')?.textContent)).toBe('sotto zero il 20/11 di € 2.368,77');
        expect(pulito(q('chiudi-cassetto')?.textContent)).toBe('Chiudi');
    });

    test('il grafico con tre linee, la legenda e la descrizione per chi non lo vede', async () => {
        await apri();
        expect(pulito(q('legenda')?.textContent)).toBe('Simulazione Se pagano tutti Senza le rate');
        expect(q('grafico-azioni')?.getAttribute('role')).toBe('img');
        expect(q('grafico-azioni')?.getAttribute('aria-label')).toContain('Il saldo nei prossimi 30 giorni in tre linee.');
        const { data } = disegni.ultimi.at(-1)!;
        expect(data.datasets.map((s: any) => s.label)).toEqual(['Simulazione', 'Se pagano tutti', 'Senza le rate']);
    });

    test('la simulazione: senza recupero, poi con quanto basta; le tre cifre seguono il cursore, e il grafico con loro', async () => {
        await apri();
        expect(pulito(q('domanda-recupero')?.textContent)).toBe('Quanto recuperi delle rate già scadute prima del 20/11?');
        expect(pulito(q('recuperato')?.textContent)).toBe('€ 0,00 (0 %)');
        expect(pulito(q('tacca-testo')?.textContent)).toBe('quanto basta: € 2.368,77 (36 %)');
        expect(pulito(q('tutte')?.textContent)).toBe('tutte, € 6.592,97');
        expect(tutti('cifra-simulazione').map((c) => pulito(c.textContent))).toEqual(['Il punto più basso −€ 2.368,77', 'Giorni sotto zero 10 giorni', 'Fra 30 giorni € 31,23']);
        expect((tutti('cifra-simulazione')[1].querySelectorAll('p')[1] as HTMLElement).className).toContain('text-red-600');

        const cursore = q('cursore') as HTMLInputElement;
        expect(cursore.type).toBe('range');
        expect(cursore.min).toBe('0');
        expect(cursore.max).toBe('659297');
        cursore.value = '236877';
        cursore.dispatchEvent(new Event('input'));
        await flushPromises();
        expect(pulito(q('recuperato')?.textContent)).toBe('€ 2.368,77 (36 %)');
        expect(tutti('cifra-simulazione').map((c) => pulito(c.textContent))).toEqual(['Il punto più basso € 0,00', 'Giorni sotto zero nessuno', 'Fra 30 giorni € 2.400,00']);
        // «nessuno» è uno zero: grigio, non verde (lo stato lo dice la scritta).
        const giorniSottoZero = tutti('cifra-simulazione')[1].querySelectorAll('p')[1] as HTMLElement;
        expect(giorniSottoZero.className).toContain('text-slate-500');
        expect(giorniSottoZero.className).not.toContain('emerald');
        expect(disegni.ultimi.at(-1)!.data.datasets[0].data[4]).toBe(3830.8);
    });

    test('«quanto basta» porta il cursore alla sua cifra', async () => {
        await apri();
        (q('tacca-testo') as HTMLButtonElement).click();
        await flushPromises();
        expect((q('cursore') as HTMLInputElement).value).toBe('236877');
        expect(pulito(q('recuperato')?.textContent)).toBe('€ 2.368,77 (36 %)');
    });

    test('le due azioni con i numeri e il loro pulsante; la riga sulle scadenze delle rate', async () => {
        await apri();
        const azioni = tutti('azione-consigliata');
        expect(azioni).toHaveLength(2);
        expect(pulito(azioni[0].textContent)).toContain('Recupera almeno € 2.368,77 delle rate scadute entro il 19/11');
        // La frase finisce ai due punti; la persona sta nella tabella sotto.
        expect(pulito(azioni[0].querySelector('[data-testid="frase-azione"]')?.textContent)).toBe('È quanto manca il 20/11 per non andare sotto zero. Basta una persona:');
        expect(pulito(azioni[0].querySelector('[data-testid="riga-persona"] [data-colonna="chi"]')?.textContent)).toBe('Giulia Esposito');
        expect(pulito(azioni[1].textContent)).toContain('Chiedi di pagare le fatture del 20/11 dopo il 30/11');
        const fatture = azioni[1].querySelector('a[data-testid="pulsante-azione"]') as HTMLAnchorElement;
        expect(pulito(fatture.textContent)).toBe('Apri le fatture');
        expect(fatture.getAttribute('href')).toBe('/admin.gestionale.fatture.index?condominio=7&stato_pagamento=aperta');
        expect(pulito(q('riga-scadenze')?.textContent)).toBe('Le scadenze delle rate non sono fra le azioni: le approva l\'assemblea con il piano rate.');
    });

    test('«Manda un sollecito» apre la lettera sulla persona, con il sollecito', async () => {
        await apri();
        expect(q('lettere-finte')).toBeNull();
        const sollecito = tutti('azione-consigliata')[0].querySelector('button[data-testid="pulsante-azione"]') as HTMLButtonElement;
        expect(pulito(sollecito.textContent)).toBe('Manda un sollecito');
        sollecito.click();
        await flushPromises();
        const lettere = w!.getComponent(Lettere);
        expect(lettere.props('open')).toBe(true);
        expect(lettere.props('persona')).toEqual({ id: 4, nome: 'Giulia Esposito' });
        expect(lettere.props('tipoIniziale')).toBe('sollecito');
        expect(lettere.props('condominioId')).toBe(7);
    });

    test('con più persone la lettera parte con tutte, spuntate', async () => {
        const recupero = { ...recuperoDellEsempio(), persone: [{ anagraficaId: 4, nome: 'Giulia Esposito', interno: 'Int. 4', cifra: 200000, lettera: null },
            { anagraficaId: 5, nome: 'Marco Bruno', interno: 'Int. 5', cifra: 100000, lettera: { tipo: 'sollecito', data: '2026-11-02' } }], servono: 2, servonoCents: 300000 };
        await apri({ recupero });
        (tutti('azione-consigliata')[0].querySelector('button[data-testid="pulsante-azione"]') as HTMLButtonElement).click();
        await flushPromises();
        const lettere = w!.getComponent(Lettere);
        expect(lettere.props('persona')).toBeNull();
        expect(lettere.props('selezione')).toEqual({ tutte: false, ids: [4, 5] });
    });

    test('con «Scelgo io» la situazione, il grafico e la simulazione, senza le azioni', async () => {
        await apri({ suggerisce: false });
        expect(q('situazione')).not.toBeNull();
        expect(q('cursore')).not.toBeNull();
        expect(q('grafico-azioni')).not.toBeNull();
        expect(tutti('azione-consigliata')).toHaveLength(0);
        expect(pulito(document.body.textContent)).not.toContain('Azioni consigliate');
    });

    test('il recupero ha anche «Registra gli incassi», a contorno, verso la registrazione degli incassi del condominio, con la riga sotto il testo', async () => {
        await apri();
        const recupero = tutti('azione-consigliata')[0];
        const incassi = recupero.querySelector('a[data-testid="pulsante-incassi"]') as HTMLAnchorElement;
        expect(pulito(incassi.textContent)).toBe('Registra gli incassi');
        expect(incassi.getAttribute('href')).toBe('/admin.gestionale.movimenti-rate.create?condominio=7');
        // Il primario resta il sollecito, pieno; l'altro è a contorno. Larghi uguali, nella stessa colonna.
        const sollecito = recupero.querySelector('button[data-testid="pulsante-azione"]') as HTMLButtonElement;
        expect(sollecito.classList.contains('bg-slate-900')).toBe(true);
        expect(incassi.classList.contains('border-slate-200')).toBe(true);
        expect(incassi.classList.contains('bg-slate-900')).toBe(false);
        expect(sollecito.className).toContain('w-full');
        expect(incassi.className).toContain('w-full');
        expect(sollecito.parentElement).toBe(incassi.parentElement);
        expect(pulito(recupero.querySelector('[data-testid="prima-di-sollecitare"]')?.textContent)).toBe('Prima di sollecitare, controlla di aver registrato i versamenti già arrivati.');
        // Le fatture non lo hanno.
        expect(tutti('azione-consigliata')[1].querySelector('[data-testid="pulsante-incassi"]')).toBeNull();
        expect(tutti('azione-consigliata')[1].querySelector('[data-testid="prima-di-sollecitare"]')).toBeNull();
    });

    test('il secondo pulsante c\'è anche quando il primo è «Apri le rate scadute» (più di 8 persone); con «Scelgo io» no', async () => {
        const persone = Array.from({ length: 9 }, (_, i) => ({ anagraficaId: 10 + i, nome: `Persona ${i + 1}`, interno: `Int. ${i + 1}`, cifra: 50000, lettera: null }));
        await apri({ recupero: { ...recuperoDellEsempio(), persone, servono: 9, servonoCents: 450000 } });
        const recupero = tutti('azione-consigliata')[0];
        expect(pulito(recupero.querySelector('a[data-testid="pulsante-azione"]')?.textContent)).toBe('Apri le rate scadute');
        expect(pulito(recupero.querySelector('a[data-testid="pulsante-incassi"]')?.textContent)).toBe('Registra gli incassi');
        w?.unmount();
        await apri({ suggerisce: false });
        expect(q('pulsante-incassi')).toBeNull();
        expect(q('prima-di-sollecitare')).toBeNull();
    });

    test('le sei in più, nell\'ordine, ognuna con il pulsante verso la sua pagina e quello che serve già scelto', async () => {
        await apri({ saldoPrevisto: { ...saldoDellEsempio(), rateScaduteCents: 0 }, datiDelleAzioni: datiDelleAzioniDellEsempio() });
        const azioni = tutti('azione-consigliata');
        expect(azioni.map((a) => a.dataset.chiave)).toEqual(['ritenute', 'bonifici', 'emissione', 'recupero', 'fatture', 'giroconto', 'fondo', 'straordinario']);
        const dove = (i: number) => (azioni[i].querySelector('a[data-testid="pulsante-azione"]') as HTMLAnchorElement | null);
        expect([0, 1, 5, 6, 7].map((i) => [pulito(dove(i)?.textContent), dove(i)?.getAttribute('href')])).toEqual([
            ['Prepara l\'F24', '/admin.gestionale.f24.index?condominio=7'],
            ['Verifica i bonifici', '/admin.inbox?condominio_id=7&tipo=verifica_pagamento'],
            ['Fai un giroconto', '/admin.gestionale.giroconti.create?condominio=7&origine=2&destinazione=1&importo=30000'],
            ['Registra lo sblocco', '/admin.gestionale.casse.edit?condominio=7&cassa=6'],
            ['Prepara un piano straordinario', '/admin.gestionale.esercizi.piani-rate.create?condominio=7&esercizio=25&tipo=straordinario'],
        ]);
        expect(pulito(azioni[0].textContent)).toContain('Paga per prime le ritenute, € 300,00 entro il 16/11');
        expect(pulito(azioni[1].textContent)).toContain('2 bonifici segnalati, € 350,00: verificali prima di sollecitare');
        expect(pulito(azioni[2].textContent)).toContain('Emetti la rata 6 del piano «Preventivo 2026»: porterebbe € 1.000,00 entro il 30/11');
        // Le rate da emettere non portano a una pagina: il pulsante apre la finestra dell'emissione.
        expect(dove(2)).toBeNull();
        expect(pulito(azioni[2].querySelector('button[data-testid="pulsante-azione"]')?.textContent)).toBe('Emetti la rata');
        expect(pulito(azioni[5].textContent)).toContain('Sposta € 300,00 da Cassa contanti a Banca Intesa');
        expect(pulito(azioni[6].textContent)).toContain('«Fondo facciata» ha € 3.000,00: l\'assemblea può deliberarne lo sblocco');
        // La rata 6 da emettere entra il 30/11, dopo il punto più basso del 20/11: manca ancora, e il titolo dice che la conta.
        expect(pulito(azioni[7].textContent)).toContain('Manca € 2.368,77 anche con tutte le rate, comprese quelle da emettere: serve un versamento straordinario');
        expect(pulito(azioni[7].textContent)).toContain('C\'è anche un debito ereditato da ripianare, € 500,00');
        // Il TFR non si propone mai; «Registra gli incassi» resta solo nel recupero.
        expect(pulito(document.body.textContent)).not.toContain('TFR portiere');
        expect(tutti('pulsante-incassi')).toHaveLength(1);
        expect(azioni[3].querySelector('[data-testid="pulsante-incassi"]')).not.toBeNull();
    });

    test('«Emetti la rata» apre la finestra «Emetti le rate» sul piano dell\'azione; dopo l\'emissione l\'esito in testa alle azioni, fino al «Chiudi» o alla chiusura del cassetto', async () => {
        await apri({ datiDelleAzioni: datiDelleAzioniDellEsempio() });
        expect(q('emissione-finta')).toBeNull();
        const emissione = tutti('azione-consigliata').find((a) => a.dataset.chiave === 'emissione')!;
        (emissione.querySelector('button[data-testid="pulsante-azione"]') as HTMLButtonElement).click();
        await flushPromises();
        const finestra = q('emissione-finta')!;
        expect([finestra.dataset.piano, finestra.dataset.condominio, finestra.dataset.oggi]).toEqual(['9', '7', '2026-11-15']);
        expect(q('lettere-finte')).toBeNull();

        // L'emissione è fatta: la finestra passa l'esito e si chiude.
        const stub = w!.findComponent({ name: 'EmissioneRapidaDialog' });
        stub.vm.$emit('emesse', { titolo: 'Emissione completata', testo: 'Sono state emesse 3 rate.\n\nNota: Rossi ha € 50,00 di credito spendibile subito.' });
        stub.vm.$emit('update:open', false);
        await flushPromises();
        expect(q('emissione-finta')).toBeNull();
        const esito = q('esito-emissione')!;
        expect(esito.getAttribute('role')).toBe('status');
        expect(pulito(esito.querySelector('p')?.textContent)).toBe('Emissione completata');
        expect(tutti('capoverso-esito').map((c) => pulito(c.textContent))).toEqual(['Sono state emesse 3 rate.', 'Nota: Rossi ha € 50,00 di credito spendibile subito.']);
        // L'esito sta in testa alle azioni, prima della prima.
        const sezione = q('sezione-azioni')!;
        expect([...sezione.querySelectorAll('[data-testid="esito-emissione"], [data-testid="azione-consigliata"]')][0].getAttribute('data-testid')).toBe('esito-emissione');
        (q('chiudi-esito') as HTMLButtonElement).click();
        await flushPromises();
        expect(q('esito-emissione')).toBeNull();

        // Chiuso e riaperto il cassetto, l'esito di prima non c'è più.
        stub.vm.$emit('emesse', { titolo: 'Emissione completata', testo: 'È stata emessa 1 rata.' });
        await flushPromises();
        expect(q('esito-emissione')).not.toBeNull();
        await w!.setProps({ open: false });
        await w!.setProps({ open: true });
        await flushPromises();
        expect(q('esito-emissione')).toBeNull();
    });

    test('un fondo senza tipo ha la sua riga; senza il permesso dell\'agenda i bonifici restano, senza il pulsante; con «Scelgo io» nessuna delle sei', async () => {
        await apri({ datiDelleAzioni: datiDelleAzioniDellEsempio({ fondi: [{ cassaId: 7, nome: 'Riserva', saldo: 500000, sottotipo: null, vincolo: null }] }) });
        const fondo = tutti('azione-consigliata').find((a) => a.dataset.chiave === 'fondo')!;
        expect(pulito(fondo.querySelector('[data-testid="nota-azione"]')?.textContent)).toBe('Il fondo non ha un tipo: nella sua pagina scegli il tipo prima di registrare lo sblocco.');
        expect(fondo.querySelector('[data-testid="prima-di-sollecitare"]')).toBeNull();
        w?.unmount();
        permessi.agenda = false;
        await apri({ datiDelleAzioni: datiDelleAzioniDellEsempio() });
        const bonifici = tutti('azione-consigliata').find((a) => a.dataset.chiave === 'bonifici')!;
        expect(pulito(bonifici.textContent)).toContain('2 bonifici segnalati');
        expect(bonifici.querySelector('[data-testid="pulsante-azione"]')).toBeNull();
        w?.unmount();
        permessi.agenda = true;
        await apri({ suggerisce: false, datiDelleAzioni: datiDelleAzioniDellEsempio() });
        expect(tutti('azione-consigliata')).toHaveLength(0);
        expect(pulito(document.body.textContent)).not.toContain('Paga per prime le ritenute');
    });

    test('le persone del recupero in una tabella sotto la frase: «Chi» in grassetto, le unità una volta sola, le rate scadute a destra, «nessuna» in un badge grigio', async () => {
        const recupero = { ...recuperoDellEsempio(), servono: 3, servonoCents: 18311, persone: [
            { anagraficaId: 31, nome: 'Tobia Cecchino', interno: 'Int. 1C, Int. 1B', cifra: 12904, lettera: { tipo: 'sollecito', data: '2026-11-07' } },
            { anagraficaId: 32, nome: 'Cecilia Angeli', interno: 'Int. 1B, Int. 1A, Int. 1C', cifra: 3388, lettera: null },
            { anagraficaId: 33, nome: 'Danilo Allegretti', interno: 'Int. 1A', cifra: 2019, lettera: null },
        ] };
        await apri({ recupero });
        const azione = tutti('azione-consigliata')[0];
        // La frase finisce ai due punti; la tabella viene dopo, e prima della riga «Prima di sollecitare».
        expect(pulito(azione.querySelector('[data-testid="frase-azione"]')?.textContent)).toBe('È quanto manca il 20/11 per non andare sotto zero. Bastano tre persone:');
        const tabella = azione.querySelector('table[data-testid="tabella-persone"]') as HTMLTableElement;
        expect(tabella).not.toBeNull();
        expect(tabella.compareDocumentPosition(azione.querySelector('[data-testid="prima-di-sollecitare"]')!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        const intestazioni = [...tabella.querySelectorAll('th')];
        expect(intestazioni.map((t) => pulito(t.textContent))).toEqual(['Chi', 'Unità', 'Rate scadute', 'Ultima lettera']);
        expect(intestazioni[2].className).toContain('text-right');
        const valori = (colonna: string) => [...tabella.querySelectorAll(`[data-colonna="${colonna}"] [data-valore]`)].map((c) => pulito(c.textContent));
        expect(valori('chi')).toEqual(['Tobia Cecchino', 'Cecilia Angeli', 'Danilo Allegretti']);
        expect(valori('unita')).toEqual(['Int. 1C, 1B', 'Int. 1B, 1A, 1C', 'Int. 1A']);
        expect(valori('rate')).toEqual(['€ 129,04', '€ 33,88', '€ 20,19']);
        expect(valori('lettera')).toEqual(['sollecito del 07/11/2026', 'nessuna', 'nessuna']);
        expect(tabella.querySelector('[data-colonna="chi"] [data-valore]')!.className).toContain('font-bold');
        expect((tabella.querySelector('td[data-colonna="rate"]') as HTMLElement).className).toContain('md:text-right');
        const badge = tabella.querySelector('[data-testid="nessuna-lettera"]') as HTMLElement;
        expect(badge.className).toContain('rounded-md');
        expect(badge.className).not.toContain('rounded-full');
        expect(badge.className).toContain('bg-slate-');
        expect(tabella.querySelector('[data-testid="altre-persone"]')).toBeNull();
        // La lettera dei solleciti resta com'è: le tre persone, spuntate.
        (azione.querySelector('button[data-testid="pulsante-azione"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(w!.getComponent(Lettere).props('selezione')).toEqual({ tutte: false, ids: [31, 32, 33] });
    });

    test('oltre le 8 persone: le prime 5 nella tabella e la riga «e altre 4 persone, € …»', async () => {
        const persone = Array.from({ length: 5 }, (_, i) => ({ anagraficaId: 10 + i, nome: `Persona ${i + 1}`, interno: `Int. ${i + 1}`, cifra: 50000, lettera: null }));
        await apri({ recupero: { ...recuperoDellEsempio(), persone, servono: 9, servonoCents: 450000 } });
        const azione = tutti('azione-consigliata')[0];
        expect(pulito(azione.querySelector('[data-testid="frase-azione"]')?.textContent)).toMatch(/ Servono 9 persone:$/);
        expect(azione.querySelectorAll('[data-testid="riga-persona"]')).toHaveLength(5);
        expect(pulito(azione.querySelector('[data-testid="altre-persone"]')?.textContent)).toBe('e altre 4 persone, € 2.000,00');
    });

    test('al telefono ogni persona è una card, senza scorrimento di lato; da 768 pixel una tabella', async () => {
        await apri();
        const tabella = q('tabella-persone')!;
        expect(tabella.className).toContain('block');
        expect(tabella.className).toContain('md:table');
        expect(tabella.querySelector('thead')!.className).toContain('hidden');
        expect(tabella.querySelector('thead')!.className).toContain('md:table-header-group');
        const riga = tabella.querySelector('[data-testid="riga-persona"]') as HTMLElement;
        for (const c of ['block', 'rounded-lg', 'border', 'md:table-row']) expect(riga.className.split(/\s+/)).toContain(c);
        // Nella card le colonne si leggono con il loro nome, che nella tabella sta in testa.
        expect([...riga.querySelectorAll('[data-etichetta]')].map((e) => [pulito(e.textContent), e.className.includes('md:hidden')]))
            .toEqual([['Unità', true], ['Rate scadute', true], ['Ultima lettera', true]]);
        expect(sorgente).not.toMatch(/overflow-x-(auto|scroll)/);
    });

    test('«Prepara l\'F24» apre la delega della scadenza quando il server ne conosce una sola; altrimenti l\'elenco', async () => {
        await apri({ datiDelleAzioni: datiDelleAzioniDellEsempio({ ritenute: { scadenze: [{ giorno: '2026-11-16', cifra: 30000, delega: 19 }] } }) });
        const f24 = tutti('azione-consigliata').find((a) => a.dataset.chiave === 'ritenute')!.querySelector('a[data-testid="pulsante-azione"]') as HTMLAnchorElement;
        expect(pulito(f24.textContent)).toBe('Prepara l\'F24');
        expect(f24.getAttribute('href')).toBe('/admin.gestionale.f24.show?condominio=7&delega=19');
        w?.unmount();
        await apri({ datiDelleAzioni: datiDelleAzioniDellEsempio({ ritenute: { scadenze: [{ giorno: '2026-11-16', cifra: 30000, delega: null }] } }) });
        expect((tutti('azione-consigliata').find((a) => a.dataset.chiave === 'ritenute')!.querySelector('a[data-testid="pulsante-azione"]') as HTMLAnchorElement)
            .getAttribute('href')).toBe('/admin.gestionale.f24.index?condominio=7');
    });

    test('il disegno: niente scritte sotto i 12 pixel, nessun menu del browser, il tema scuro, nessuna parola del lavoro', () => {
        expect(sorgente).not.toContain('v-html');
        expect(sorgente).not.toMatch(/text-\[(8|9|10|11)px\]/);
        expect(sorgente).not.toContain('<select');
        expect(sorgente).toContain('dark:');
        expect(sorgente).not.toMatch(/\bpasso\b/i);
    });
});
