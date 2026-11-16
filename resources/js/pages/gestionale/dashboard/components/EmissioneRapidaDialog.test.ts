// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra «Emetti le rate» del cassetto della tesoreria. All'apertura legge i dati con un caricamento parziale; il piano dell'azione in un menu del
 * programma, che non si apre da solo; le rate spuntate di serie (le già scadute e quelle dei prossimi 30 giorni); la data di oggi; «Avvisa i condòmini» acceso;
 * il riepilogo; «Emetti N rate», spento senza spunte; «Apri il piano». Il cambio di piano. L'invio alla rotta della pagina del piano con la visita parziale della
 * sola tesoreria; dopo l'emissione la finestra si chiude e passa l'esito; un rifiuto, un esito senza rate emesse e gli errori si leggono nella finestra, che
 * resta. Il messaggio non resta in cima alla pagina.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';

const inertia = vi.hoisted(() => ({ reload: vi.fn(), post: vi.fn(), pagina: null as unknown as { props: Record<string, any> } }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/vue3')>()),
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { reload: (...a: unknown[]) => inertia.reload(...a), post: (...a: unknown[]) => inertia.post(...a) },
    usePage: () => inertia.pagina,
}));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}` }) }));
(globalThis as any).route = (nome: string, p?: Record<string, unknown>) => `/${nome}${p ? '?' + new URLSearchParams(p as Record<string, string>).toString() : ''}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Finestra = (await import('./EmissioneRapidaDialog.vue')).default;

const datiDellEsempio = () => ({
    oggi: '2026-11-16', fino: '2026-12-16', piani: [
        { pianoId: 9, piano: 'Preventivo 2026', esercizioId: 25, persone: 4, rate: [
            { rataId: 91, numero: 9, scadenza: '2026-09-30', cifra: 120000, persone: 4, chi: null },
            { rataId: 92, numero: 10, scadenza: '2026-10-31', cifra: 120000, persone: 4, chi: null },
            { rataId: 93, numero: 11, scadenza: '2026-11-30', cifra: 118894, persone: 4, chi: null },
            { rataId: 94, numero: 12, scadenza: '2026-12-31', cifra: 120000, persone: 4, chi: null },
        ] },
        { pianoId: 12, piano: 'Facciata 2026', esercizioId: 26, persone: 3, rate: [
            { rataId: 121, numero: 1, scadenza: '2026-11-20', cifra: 50000, persone: 2, chi: [1, 2] },
            { rataId: 122, numero: 2, scadenza: '2026-12-10', cifra: 30000, persone: 2, chi: [2, 3] },
            { rataId: 123, numero: 3, scadenza: '2027-01-20', cifra: 20000, persone: 1, chi: [3] },
        ] },
    ],
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; inertia.reload.mockReset(); inertia.post.mockReset(); });
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const q = (id: string) => document.body.querySelector(`[data-testid="${id}"]`) as HTMLElement | null;
const tutti = (id: string) => [...document.body.querySelectorAll(`[data-testid="${id}"]`)] as HTMLElement[];
const spuntata = (r: HTMLElement) => r.querySelector('button[role="checkbox"]')?.getAttribute('data-state') === 'checked';
const sorgente = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/dashboard/components/EmissioneRapidaDialog.vue'), 'utf8');

/** La finestra aperta sul piano dell'azione; i dati arrivano subito, o quando il test li manda. */
async function apri(props: Record<string, unknown> = {}, dati: unknown = datiDellEsempio(), subito = true) {
    inertia.pagina = reactive({ props: { flash: { message: { type: 'success', message: 'Un messaggio di prima.' } } } });
    inertia.reload.mockImplementation((o: any) => {
        if (subito) {
            o.onSuccess?.({ props: { emissioneRapida: dati } });
            o.onFinish?.();
        }
    });
    w = mount(Finestra, { attachTo: document.body, props: { open: true, condominioId: 7, pianoId: 9, oggi: '2026-11-16', ...props } as any });
    await flushPromises();

    return w;
}
/** Una voce del menu del piano: il menu si apre e si fa clic sulla voce con quel testo. */
async function nelMenu(testo: string) {
    const menu = q('menu-piano')!.querySelector('.v-select') as HTMLElement;
    menu.querySelector('.vs__search')!.dispatchEvent(new FocusEvent('focus'));
    await flushPromises();
    ([...menu.querySelectorAll('.vs__dropdown-option')].find((o) => pulito(o.textContent) === testo) as HTMLElement).click();
    await flushPromises();
}
/** La risposta del server all'invio: la pagina che torna, con il flash. */
async function risponde(flash: Record<string, unknown>, errori: Record<string, string> | null = null) {
    const [, , o] = inertia.post.mock.calls.at(-1)!;
    if (errori) {
        o.onError?.(errori);
    } else {
        inertia.pagina.props.flash = flash;
        o.onSuccess?.({ props: { flash } });
    }
    o.onFinish?.();
    await flushPromises();
}

describe('la finestra «Emetti le rate»', () => {
    test('all\'apertura legge i dati con un caricamento parziale, e mentre arrivano le righe grigie', async () => {
        await apri({}, datiDellEsempio(), false);
        expect(inertia.reload).toHaveBeenCalledTimes(1);
        const [opzioni] = inertia.reload.mock.calls[0];
        expect(opzioni.only).toEqual(['emissioneRapida']);
        expect(q('caricamento')).not.toBeNull();
        expect((q('emetti') as HTMLButtonElement).disabled).toBe(true);
        opzioni.onSuccess({ props: { emissioneRapida: datiDellEsempio() } });
        opzioni.onFinish();
        await flushPromises();
        expect(q('caricamento')).toBeNull();
        expect(tutti('rata-finestra')).toHaveLength(4);
    });

    test('il piano dell\'azione; spuntate le rate già scadute e quelle dei prossimi 30 giorni; la data di oggi; l\'avviso acceso; il riepilogo e il pulsante', async () => {
        await apri();
        expect(pulito(q('finestra-emissione')?.textContent)).toContain('Emetti le rate');
        expect(pulito(q('menu-piano')?.querySelector('.vs__selected')?.textContent)).toBe('Preventivo 2026 (4 rate da emettere)');
        const righe = tutti('rata-finestra');
        expect(righe.map((r) => r.dataset.rata)).toEqual(['91', '92', '93', '94']);
        expect(righe.map(spuntata)).toEqual([true, true, true, false]);
        expect(righe.map((r) => pulito(r.querySelector('[data-testid="quando"]')?.textContent))).toEqual(['scaduta da 47 giorni', 'scaduta da 16 giorni', 'fra 14 giorni', 'fra 45 giorni']);
        expect(righe[0].querySelector('[data-testid="quando"]')!.className).toContain('text-red-600');
        expect(righe[2].querySelector('[data-testid="quando"]')!.className).toContain('text-amber-700');
        expect(righe[3].querySelector('[data-testid="quando"]')!.className).toContain('text-slate-500');
        expect(righe.map((r) => pulito(r.querySelector('[data-testid="cifra-rata"]')?.textContent))).toEqual(['€ 1.200,00', '€ 1.200,00', '€ 1.188,94', '€ 1.200,00']);
        expect(pulito(q('spuntate-di-serie')?.textContent)).toBe('Spuntate di serie le rate già scadute e quelle che scadono entro il 16/12/2026, come nell\'azione.');
        expect((q('data-emissione') as HTMLInputElement).value).toBe('2026-11-16');
        expect(q('interruttore-avvisa')?.getAttribute('data-state')).toBe('checked');
        expect(pulito(q('adesso-avvisa')?.textContent)).toBe('Adesso: i condòmini delle rate spuntate vedono la scadenza nella loro area. Di serie, come nella pagina del piano.');
        expect(pulito(q('riepilogo-cifre')?.textContent)).toBe('3 rate, 4 condòmini, € 3.588,94');
        expect(pulito(q('emetti')?.textContent)).toBe('Emetti 3 rate');
        expect((q('emetti') as HTMLButtonElement).disabled).toBe(false);
        expect(q('apri-il-piano')?.getAttribute('href')).toBe('/admin.gestionale.esercizi.piani-rate.show?condominio=7&esercizio=25&pianoRate=9');
        expect(pulito(q('apri-il-piano')?.textContent)).toBe('Apri il piano');
    });

    test('senza spunte il pulsante si spegne e il riepilogo lo dice; una spunta e «Tutte» cambiano il riepilogo', async () => {
        await apri();
        (q('nessuna') as HTMLButtonElement).click();
        await flushPromises();
        expect((q('emetti') as HTMLButtonElement).disabled).toBe(true);
        expect(pulito(q('emetti')?.textContent)).toBe('Emetti le rate');
        expect(pulito(q('nessuna-spunta')?.textContent)).toBe('Spunta almeno una rata.');
        (tutti('rata-finestra')[3].querySelector('button[role="checkbox"]') as HTMLElement).click();
        await flushPromises();
        expect(pulito(q('riepilogo-cifre')?.textContent)).toBe('1 rata, 4 condòmini, € 1.200,00');
        expect(pulito(q('emetti')?.textContent)).toBe('Emetti 1 rata');
        (q('tutte') as HTMLButtonElement).click();
        await flushPromises();
        expect(pulito(q('riepilogo-cifre')?.textContent)).toBe('4 rate, 4 condòmini, € 4.788,94');
    });

    test('il cambio di piano: le rate del piano scelto con le sue spunte di serie; i condòmini contati una volta; «Apri il piano» segue', async () => {
        await apri();
        await nelMenu('Facciata 2026 (3 rate da emettere)');
        const righe = tutti('rata-finestra');
        expect(righe.map((r) => r.dataset.rata)).toEqual(['121', '122', '123']);
        expect(righe.map(spuntata)).toEqual([true, true, false]);
        expect(pulito(q('riepilogo-cifre')?.textContent)).toBe('2 rate, 3 condòmini, € 800,00');
        expect(q('apri-il-piano')?.getAttribute('href')).toBe('/admin.gestionale.esercizi.piani-rate.show?condominio=7&esercizio=26&pianoRate=12');
    });

    test('il piano dell\'azione non ha più rate da emettere: la finestra apre il primo; senza piani lo dice, e niente si emette', async () => {
        await apri({ pianoId: 77 });
        expect(pulito(q('menu-piano')?.querySelector('.vs__selected')?.textContent)).toBe('Preventivo 2026 (4 rate da emettere)');
        w?.unmount();
        await apri({}, { oggi: '2026-11-16', fino: '2026-12-16', piani: [] });
        expect(pulito(q('niente-da-emettere')?.textContent)).toBe('Nei piani approvati di questo condominio non ci sono rate da emettere.');
        expect((q('emetti') as HTMLButtonElement).disabled).toBe(true);
        expect(q('apri-il-piano')).toBeNull();
    });

    test('«Emetti» manda alla rotta della pagina del piano le rate spuntate, la data, la descrizione, l\'avviso e il ritorno alla dashboard, con la visita parziale della sola tesoreria', async () => {
        await apri();
        const data = q('data-emissione') as HTMLInputElement;
        data.value = '2026-11-14';
        data.dispatchEvent(new Event('input'));
        const descrizione = q('descrizione') as HTMLInputElement;
        descrizione.value = 'Rate di novembre';
        descrizione.dispatchEvent(new Event('input'));
        (q('interruttore-avvisa') as HTMLElement).click();
        await flushPromises();
        expect(pulito(q('adesso-avvisa')?.textContent)).toBe('Adesso: le rate vanno a giornale e restano nascoste ai condòmini; le pubblichi con «Pubblica nascoste» nella pagina del piano.');
        (q('emetti') as HTMLButtonElement).click();
        await flushPromises();

        expect(inertia.post).toHaveBeenCalledTimes(1);
        const [url, dati, opzioni] = inertia.post.mock.calls[0];
        expect(url).toBe('/admin.gestionale.piani-rate.emetti?condominio=7&pianoRate=9');
        expect(dati).toEqual({ rate_ids: [91, 92, 93], data_emissione: '2026-11-14', descrizione_personalizzata: 'Rate di novembre', invia_notifiche: false, da: 'dashboard' });
        // La tesoreria, il messaggio, l'Inbox operativa (l'emissione toglie i compiti «Emettere la rata») e i sei mesi (le rate emesse sono debito nuovo).
        expect(opzioni).toMatchObject({ only: ['widgets', 'flash', 'inboxTasks', 'inboxSchede', 'inboxScaduti', 'seiMesi', 'compitiSeiMesi'], reset: ['inboxTasks'],
            preserveScroll: true, preserveState: true });
        // Mentre va, il pulsante aspetta: un secondo clic non manda niente.
        expect((q('emetti') as HTMLButtonElement).disabled).toBe(true);
        (q('emetti') as HTMLButtonElement).click();
        expect(inertia.post).toHaveBeenCalledTimes(1);

        await risponde({ message: { type: 'success', message: 'Rate emesse in modalità silenziosa.' }, esito_emissione: { titolo: 'Emissione completata', testo: 'Sono state emesse 3 rate.' },
            suggerimento_crediti: 'Nota: Rossi ha € 50,00 di credito spendibile subito.' });
        expect(w!.emitted('emesse')).toEqual([[{ titolo: 'Emissione completata', testo: 'Sono state emesse 3 rate.\n\nNota: Rossi ha € 50,00 di credito spendibile subito.' }]]);
        expect(w!.emitted('update:open')).toEqual([[false]]);
        // Il messaggio l'ha letto il cassetto: in cima alla dashboard non resta.
        expect(inertia.pagina.props.flash.message).toBeUndefined();
    });

    test('un rifiuto del server si legge nella finestra, con i passaggi in elenco e i capoversi; la finestra resta, e niente è chiuso', async () => {
        await apri();
        (q('emetti') as HTMLButtonElement).click();
        await flushPromises();
        await risponde({ message: { type: 'error', message: 'Le quote di questo piano sono state calcolate prima di questo passaggio:\n• Rossi → Elsa, Interno A, dal 1 febbraio 2026\n\nRicalcola il piano prima di emettere.' } });
        const rifiuto = q('rifiuto')!;
        expect(rifiuto.getAttribute('role')).toBe('alert');
        expect(pulito(rifiuto.querySelector('p')?.textContent)).toBe('Le rate non sono state emesse');
        expect([...rifiuto.querySelectorAll('li')].map((l) => pulito(l.textContent))).toEqual(['Rossi → Elsa, Interno A, dal 1 febbraio 2026']);
        expect(pulito(rifiuto.textContent)).toContain('Ricalcola il piano prima di emettere.');
        expect(w!.emitted('update:open')).toBeUndefined();
        expect(w!.emitted('emesse')).toBeUndefined();
        expect(tutti('rata-finestra').map(spuntata)).toEqual([true, true, true, false]);
        expect(inertia.pagina.props.flash.message).toBeUndefined();
        // Un altro piano: il rifiuto del piano di prima non vale più.
        await nelMenu('Facciata 2026 (3 rate da emettere)');
        expect(q('rifiuto')).toBeNull();
    });

    test('gli errori della richiesta si leggono nella finestra, una volta ognuno; nessuna rata a giornale è un avviso, e le rate si rileggono', async () => {
        await apri();
        (q('emetti') as HTMLButtonElement).click();
        await flushPromises();
        await risponde({}, { 'rate_ids.1': 'Una delle rate scelte non è di questo piano: scegli di nuovo le rate.', 'rate_ids.2': 'Una delle rate scelte non è di questo piano: scegli di nuovo le rate.' });
        expect([...q('errori')!.querySelectorAll('li')].map((l) => pulito(l.textContent))).toEqual(['Una delle rate scelte non è di questo piano: scegli di nuovo le rate.']);
        expect(w!.emitted('update:open')).toBeUndefined();

        (q('emetti') as HTMLButtonElement).click();
        await flushPromises();
        expect(q('errori')).toBeNull();
        await risponde({ message: { type: 'warning', message: 'Le rate 9, 10 e 11 erano già emesse.' }, esito_emissione: { titolo: 'Rate già emesse', testo: 'Le rate 9, 10 e 11 erano già emesse.' } });
        expect([...q('avviso')!.querySelectorAll('p')].map((p) => pulito(p.textContent))).toEqual(['Rate già emesse', 'Le rate 9, 10 e 11 erano già emesse.']);
        expect(q('avviso')?.getAttribute('role')).toBe('status');
        expect(w!.emitted('update:open')).toBeUndefined();
        // Le rate si rileggono dopo l'errore delle rate e dopo l'avviso.
        expect(inertia.reload).toHaveBeenCalledTimes(3);
        expect(inertia.reload.mock.calls[1][0].only).toEqual(['emissioneRapida']);
        expect(inertia.reload.mock.calls[2][0].only).toEqual(['emissioneRapida']);
    });

    test('il menu del piano è un menu del programma che nella finestra non si apre da solo; «Chiudi» scritto; niente scritte sotto i 12 pixel né bordi spessi', async () => {
        await apri();
        expect(document.body.querySelector('select')).toBeNull();
        expect(q('menu-piano')?.querySelector('.v-select')).not.toBeNull();
        expect(q('menu-piano')?.querySelector('.vs--open')).toBeNull();
        expect(sorgente).toMatch(/<DialogContent[^>]*@open-auto-focus\.prevent/);
        expect(pulito(q('chiudi-finestra')?.textContent)).toBe('Chiudi');
        expect(sorgente).not.toMatch(/text-\[(?:[0-9]|10|11)px\]/);
        expect(sorgente).not.toMatch(/border-l-(?:2|4|8|\[)/);
        (q('chiudi-finestra') as HTMLElement).click();
        await flushPromises();
        expect(w!.emitted('update:open')).toEqual([[false]]);
    });
});
