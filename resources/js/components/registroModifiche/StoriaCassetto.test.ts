// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il cassetto della «Storia» (scena 1 della bozza, §8 del progetto della 1.12.0-beta.13) e la finestra di «Ripristina».
 *
 * - Aperto legge la rotta JSON della scheda e mostra il titolo, la riga sotto, le righe con la data, chi con il ruolo, il badge dell'azione (`rounded-md`),
 *   i dati «com'era → com'è»; «Ripristina» solo sui dati che il server dice ripristinabili; la frase di un'area senza ripristino.
 * - «Mostra altre» chiede la pagina dopo e aggiunge le righe.
 * - «Oscura i suoi dati nel registro» c'è solo quando il server lo dà (l'amministratore, una persona) ed emette `oscura` con la persona.
 * - «Ripristina» apre la finestra: «Rimettere l'email di prima?», «Adesso» e «Torna a», la frase sul registro degli invii; «Rimetti l'email di prima» manda
 *   la richiesta, e il cassetto si rilegge e dice che cosa è stato rimesso; un rifiuto della pagina (422) si legge nella finestra.
 *
 * Che cosa non copre: le regole del server (i test Pest della cartella `tests/Feature/RegistroModifiche`).
 */
import { afterEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

const axiosGet = vi.fn();
const axiosPost = vi.fn();
vi.mock('axios', () => ({ default: { get: (...a: unknown[]) => axiosGet(...a), post: (...a: unknown[]) => axiosPost(...a) } }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => nome }) }));
vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/vue3')>()), router: { visit: vi.fn(), reload: vi.fn(), get: vi.fn() } }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: Record<string, unknown>) => `/${nome}?${new URLSearchParams(Object.entries(p ?? {}).filter(([, v]) => v !== undefined).map(([k, v]) => [k, String(v)])).toString()}`;
(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Cassetto = (await import('./StoriaCassetto.vue')).default;

const riga = (id: number, extra: Record<string, unknown> = {}) => ({
    id, avvenuta_il: '16/12/2026 09:12', registrata_il: '16/12/2026 09:12',
    autore: { tipo: 'persona', nome: 'Marco Galli', ruolo: 'collaboratore', origine: 'pagina', dettaglio: null },
    area: 'persone', azione: 'modificata', oggetto: { tipo: 'anagrafica', id: 7, nome: 'Anagrafica di Ida Romano' }, scheda: { tipo: 'anagrafica', id: 7 },
    riepilogo: null, perche: null, rimando: null, oscurata: false, annullata_dal_ripristino_del: null, fuori_dal_programma: false, nota: null, apri: null,
    campi: [
        { chiave: 'email', etichetta: 'Indirizzo email primario', prima: 'ida.romano@libero.it', dopo: 'ida.romano@gmail.com', segreto: false },
        { chiave: 'pec', etichetta: 'Indirizzo email PEC', prima: null, dopo: 'ida.romano@pec.it', segreto: false },
    ],
    ripristina: { email: true, pec: false },
    ...extra,
});
const storia = (righe: unknown[], extra: Record<string, unknown> = {}) => ({
    titolo: 'Storia di Ida Romano', sottotitolo: 'Anagrafica · Condominio Via Roma 12 · 2 modifiche dal 01/11/2026', totale: righe.length, pagina: 1, altre: 0,
    righe, oscuramento: null, ...extra,
});

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; axiosGet.mockReset(); axiosPost.mockReset(); document.body.innerHTML = ''; });
const pulito = (t: string | null | undefined) => (t ?? '').replace(/\s+/g, ' ').trim();
const tutti = (sel: string) => [...document.body.querySelectorAll(sel)];
const monta = async () => {
    w = mount(Cassetto, { attachTo: document.body, props: { open: true, tipo: 'anagrafica', id: 7 } });
    await flushPromises();
};

describe('il cassetto della «Storia»', () => {
    test('legge la scheda e mostra il titolo, la riga sotto, chi, il badge e i dati com\'era → com\'è; «Ripristina» solo dove si può', async () => {
        axiosGet.mockResolvedValueOnce({ data: storia([riga(2), riga(1, { azione: 'creata', campi: [], riepilogo: 'Con 2 dati.', ripristina: {} })]) });
        await monta();

        expect(axiosGet.mock.calls[0][0]).toBe('/registro-modifiche.storia?tipo=anagrafica&id=7');
        expect(pulito(document.body.querySelector('[data-testid="titolo-storia"]')?.textContent)).toBe('Storia di Ida Romano');
        expect(pulito(document.body.querySelector('[data-testid="sottotitolo-storia"]')?.textContent)).toBe('Anagrafica · Condominio Via Roma 12 · 2 modifiche dal 01/11/2026');
        const prima = tutti('[data-testid="riga-storia"]')[0];
        expect([...prima.firstElementChild!.children].map((e) => pulito(e.textContent))).toEqual(['16/12/2026 09:12', '·', 'Marco Galli', 'collaboratore', 'Modificata']);
        expect(prima.querySelector('[data-testid="azione"]')!.className).toContain('rounded-md');
        // Il ruolo in un badge grigio, accanto al nome (prova a video, 19/11).
        const ruolo = prima.querySelector('[data-testid="ruolo"]')!;
        expect(pulito(ruolo.textContent)).toBe('collaboratore');
        expect(ruolo.className).toContain('rounded-md');
        expect(ruolo.className).toContain('bg-slate-50');
        // Dove: la scheda della riga, sotto chi l'ha fatta (prova a video, 19/11: «vedo chi ha fatto la modifica ma non dove»).
        expect(pulito(prima.querySelector('[data-testid="oggetto-riga"]')?.textContent)).toBe('Anagrafica di Ida Romano');
        // Il dato come nella tabella: il nome sopra, piccolo; sotto il valore di prima e «→ valore nuovo»; «Ripristina» a destra.
        expect(tutti('[data-testid="campo"]').map((c) => [
            pulito(c.querySelector('[data-testid="etichetta-dato"]')?.textContent),
            ...[...c.querySelector('[data-testid="valore-dato"]')!.children].map((e) => pulito(e.textContent)),
            pulito(c.querySelector('[data-testid="ripristina"]')?.textContent),
        ])).toEqual([
            ['Indirizzo email primario', 'ida.romano@libero.it', '→ ida.romano@gmail.com', 'Ripristina'],
            ['Indirizzo email PEC', 'vuoto', '→ ida.romano@pec.it', ''],
        ]);
        expect(tutti('[data-testid="valore-dato"]')[0].className).toContain('flex-wrap');
        expect(tutti('[data-testid="etichetta-dato"]')[0].className).toContain('text-xs');
        expect(tutti('[data-testid="ripristina"]')).toHaveLength(1);
        expect(pulito(tutti('[data-testid="riga-storia"]')[1].querySelector('[data-testid="azione"]')?.textContent)).toBe('Creata');
        expect(pulito(tutti('[data-testid="riga-storia"]')[1].textContent)).toContain('Con 2 dati.');
        expect(document.body.querySelector('[data-testid="oscura"]')).toBeNull();
    });

    test('in contabilità niente «Ripristina», e la riga dice come si cambia', async () => {
        axiosGet.mockResolvedValueOnce({ data: storia([riga(3, { area: 'contabilita', ripristina: { email: false, pec: false }, nota: 'In contabilità non si ripristina: si storna, dalla fattura.' })]) });
        await monta();

        expect(tutti('[data-testid="ripristina"]')).toHaveLength(0);
        expect(pulito(document.body.querySelector('[data-testid="nota"]')?.textContent)).toBe('In contabilità non si ripristina: si storna, dalla fattura.');
    });

    test('«Mostra altre» chiede la pagina dopo e aggiunge le righe', async () => {
        axiosGet.mockResolvedValueOnce({ data: storia([riga(30)], { totale: 21, altre: 1 }) })
            .mockResolvedValueOnce({ data: storia([riga(10)], { totale: 21, altre: 0, pagina: 2 }) });
        await monta();
        expect(pulito(document.body.querySelector('[data-testid="altre"]')?.textContent)).toBe('e un\'altra riga · Mostra altre');

        (document.body.querySelector('[data-testid="mostra-altre"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(axiosGet.mock.calls[1][0]).toBe('/registro-modifiche.storia?tipo=anagrafica&id=7&pagina=2');
        expect(tutti('[data-testid="riga-storia"]')).toHaveLength(2);
        expect(document.body.querySelector('[data-testid="altre"]')).toBeNull();
    });

    test('il punto di innesto dell\'oscuramento: il pulsante c\'è quando il server lo dà, ed emette `oscura` con la persona', async () => {
        const soggetto = { soggetto_tipo: 'anagrafica', soggetto_id: 7, nome: 'Ida Romano' };
        axiosGet.mockResolvedValueOnce({ data: storia([riga(2)], { oscuramento: soggetto }) });
        await monta();

        const pulsante = document.body.querySelector('[data-testid="oscura"]') as HTMLButtonElement;
        expect(pulito(pulsante.textContent)).toBe('Oscura i suoi dati nel registro');
        pulsante.click();
        expect(w!.emitted('oscura')).toEqual([[soggetto]]);
    });
});

describe('la riga senza la firma del programma', () => {
    test('nella «Storia» ha il badge rosso «Aggiunta fuori dal programma» e la frase che lo dice; le altre no', async () => {
        axiosGet.mockResolvedValueOnce({ data: storia([riga(3), riga(2, { fuori_dal_programma: true, ripristina: { email: false } })]) });
        await monta();
        const righe = document.body.querySelectorAll('[data-testid="riga-storia"]');
        expect(righe[0].querySelector('[data-testid="fuori-dal-programma"]')).toBeNull();
        const badge = righe[1].querySelector('[data-testid="fuori-dal-programma"]')!;
        expect(pulito(badge.textContent)).toBe('Aggiunta fuori dal programma');
        expect(badge.className).toContain('border-rose-200');
        expect(pulito(righe[1].querySelector('[data-testid="fuori-dal-programma-frase"]')?.textContent))
            .toBe('Questa riga non ha la firma del programma: è stata aggiunta o cambiata fuori dal programma, per esempio direttamente nel database.');
    });
});

describe('«Ripristina»', () => {
    const anteprima = {
        riga_id: 2, campo: 'email', etichetta: 'Indirizzo email primario', titolo: 'Rimettere l\'email di prima?',
        descrizione: 'Email di Ida Romano, cambiata da Marco Galli il 16/12/2026 alle 09:12.',
        adesso: 'ida.romano@gmail.com', torna_a: 'ida.romano@libero.it', gia_cosi: false, pulsante: 'Rimetti l\'email di prima',
        invii: 'Dal 16/12 a «ida.romano@gmail.com» è partita un\'email (la ricevuta n. INC-2026-00231, nel registro degli invii): il ripristino non la cambia e non la rimanda.',
    };

    test('la finestra della bozza; «Rimetti l\'email di prima» manda la richiesta, e il cassetto si rilegge e dice che cosa è stato rimesso', async () => {
        axiosGet.mockResolvedValueOnce({ data: storia([riga(2)]) }).mockResolvedValueOnce({ data: anteprima })
            .mockResolvedValueOnce({ data: storia([riga(5, { azione: 'ripristinata', ripristina: {} }), riga(2)]) });
        axiosPost.mockResolvedValueOnce({ data: { message: 'Rimesso: «Indirizzo email primario» torna a «ida.romano@libero.it». Il ripristino è una riga del registro, con il tuo nome.' } });
        await monta();

        (document.body.querySelector('[data-testid="ripristina"]') as HTMLButtonElement).click();
        await flushPromises();
        expect(axiosGet.mock.calls[1]).toEqual(['/registro-modifiche.ripristina.anteprima?riga=2', { params: { campo: 'email' } }]);
        const finestra = document.body.querySelector('[data-testid="finestra-ripristina"]')!;
        expect(pulito(finestra.textContent)).toContain('Rimettere l\'email di prima?');
        expect(pulito(finestra.textContent)).toContain('Email di Ida Romano, cambiata da Marco Galli il 16/12/2026 alle 09:12.');
        expect([...finestra.querySelector('[data-testid="adesso-torna-a"]')!.children].map((e) => pulito(e.textContent)))
            .toEqual(['Adesso', 'ida.romano@gmail.com', 'Torna a', 'ida.romano@libero.it']);
        expect(pulito(finestra.querySelector('[data-testid="invii"]')?.textContent)).toBe(anteprima.invii);
        expect(pulito(finestra.textContent)).toContain('passa dagli stessi controlli della scheda e diventa una riga del registro, con il tuo nome');

        const rimetti = finestra.querySelector('[data-testid="rimetti"]') as HTMLButtonElement;
        expect(pulito(rimetti.textContent)).toBe('Rimetti l\'email di prima');
        rimetti.click();
        await flushPromises();
        expect(axiosPost.mock.calls[0]).toEqual(['/registro-modifiche.ripristina?riga=2', { campo: 'email' }]);
        expect(pulito(document.body.querySelector('[data-testid="esito-ripristino"]')?.textContent))
            .toContain('Rimesso: «Indirizzo email primario» torna a «ida.romano@libero.it».');
        expect(tutti('[data-testid="riga-storia"]')).toHaveLength(2);
    });

    test('un rifiuto della pagina della scheda si legge nella finestra', async () => {
        axiosGet.mockResolvedValueOnce({ data: storia([riga(2)]) }).mockResolvedValueOnce({ data: anteprima });
        axiosPost.mockRejectedValueOnce({ response: { status: 422, data: { message: 'x', errors: { scadenza_documento: ['La scadenza documento deve essere una data successiva a oggi.'] } } } });
        await monta();
        (document.body.querySelector('[data-testid="ripristina"]') as HTMLButtonElement).click();
        await flushPromises();
        (document.body.querySelector('[data-testid="rimetti"]') as HTMLButtonElement).click();
        await flushPromises();

        expect(pulito(document.body.querySelector('[data-testid="errore-ripristino"]')?.textContent))
            .toBe('La scheda non passa i controlli della sua pagina: La scadenza documento deve essere una data successiva a oggi.');
    });
});
