// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La finestra «Oscura i dati di … nel registro» (1.12.0-beta.13, §10, bozza scena 4): riceve `{ soggetto_tipo, soggetto_id, nome }` (l'evento `oscura`
 * della «Storia»), carica da sé l'anteprima, dice quante righe e dal … al …, «Vedi le righe», la frase su che cosa resta, la nota sulla legge, le righe di
 * altre schede da scegliere una per una, il perché obbligatorio, «Oscura N righe» con N che conta le scelte; dopo, l'esito e «Chiudi».
 *
 * Che cosa non copre: la rotta (la prova `OscuramentoTest.php`); il montaggio nel cassetto della «Storia» (lo prova `StoriaCassetto.test.ts`); il tema
 * scuro a video.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const rete = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }));
vi.mock('axios', () => ({ default: rete }));
vi.mock('@/composables/permissions', () => ({ usePermission: () => ({ generateRoute: (nome: string) => `admin.${nome}`, hasPermission: () => true }) }));
(globalThis as unknown as { route: unknown }).route = (nome: string, p?: Record<string, unknown>) => `/${nome}/${p?.soggetto_tipo}/${p?.soggetto_id}`;

const Finestra = (await import('./FinestraOscuramento.vue')).default;

const ANTEPRIMA = {
    soggetto: { tipo: 'anagrafica', id: 57, nome: 'Carlo Fontana', parola: 'Anagrafica', condomini: 'Via Roma 12',
        descrizione: 'Ex condòmino: ha venduto l\'Int. 11 il 15/01/2027' },
    righe: {
        quante: 18, dal: '01/11/2026', al: '15/01/2027',
        elenco: [{ id: 101, quando: '20/12/2026 10:31', oggetto: 'Anagrafica di Carlo Fontana', azione: 'modificata', chi: 'Marco Galli', cosa: ['Indirizzo email primario'] }],
    },
    altre: { quante: 1, elenco: [{ id: 205, quando: '03/01/2027 11:00', oggetto: 'Anagrafica di Mario Rossi', azione: 'modificata', chi: 'Andrea Costa', cosa: ['Note aggiuntive'], contiene: 'carlo.fontana@libero.it' }] },
    oggi: '12/03/2027',
    sostituzione: 'oscurato il 12/03/2027',
};

let w: VueWrapper | null = null;
beforeEach(() => { rete.get.mockReset(); rete.post.mockReset(); });
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

const testo = () => document.body.textContent?.replace(/\s+/g, ' ') ?? '';
const monta = async () => {
    w = mount(Finestra, { props: { soggetto_tipo: 'anagrafica', soggetto_id: 57, nome: 'Carlo Fontana' }, attachTo: document.body });
    await flushPromises();

    return w;
};
const scrivi = async (selettore: string, valore: string) => {
    const campo = document.querySelector<HTMLTextAreaElement>(selettore)!;
    campo.value = valore;
    campo.dispatchEvent(new Event('input'));
    await flushPromises();
};
const manda = async () => {
    document.querySelector<HTMLFormElement>('form')!.dispatchEvent(new Event('submit'));
    await flushPromises();
};

describe('la finestra della bozza', () => {
    test('carica l\'anteprima e dice chi, quante righe, dal … al …, che cosa resta, la nota sulla legge e «Oscura 18 righe»', async () => {
        rete.get.mockResolvedValue({ data: ANTEPRIMA });
        await monta();

        expect(rete.get).toHaveBeenCalledWith('/admin.registro-modifiche.oscuramento.anteprima/anagrafica/57');
        expect(testo()).toContain('Oscura i dati di Carlo Fontana nel registro');
        // La riga sotto il titolo la compone il programma: qui arriva già scritta.
        expect(testo()).toContain('Ex condòmino: ha venduto l\'Int. 11 il 15/01/2027');
        expect(document.querySelector('[data-testid="quante"]')!.textContent).toContain('18 righe con i suoi dati, dal 01/11/2026 al 15/01/2027');
        expect(testo()).toContain('Nel registro restano chi, quando e su che cosa. I suoi dati, nei valori di prima e di dopo, diventano «oscurato il 12/03/2027».');
        expect(testo()).toContain('art. 17 par. 3 del regolamento europeo');
        expect(testo()).toContain('L\'oscuramento è a sua volta una riga del registro, e non si annulla.');
        expect(document.querySelector('[data-testid="oscura"]')!.textContent!.trim()).toBe('Oscura 18 righe');
    });

    test('«Vedi le righe» mostra le righe e che cosa si oscura in ognuna', async () => {
        rete.get.mockResolvedValue({ data: ANTEPRIMA });
        await monta();
        expect(document.querySelector('[data-testid="righe"]')).toBeNull();

        document.querySelector<HTMLButtonElement>('[data-testid="vedi-le-righe"]')!.click();
        await flushPromises();

        const righe = document.querySelector('[data-testid="righe"]')!.textContent!.replace(/\s+/g, ' ');
        expect(righe).toContain('20/12/2026 10:31');
        expect(righe).toContain('Si oscura: Indirizzo email primario');
        expect(righe).toContain('e altre 17 righe');
    });

    test('il perché è obbligatorio: senza, la finestra lo dice e non manda niente', async () => {
        rete.get.mockResolvedValue({ data: ANTEPRIMA });
        await monta();
        await manda();

        expect(testo()).toContain('Scrivi perché oscuri i suoi dati');
        expect(rete.post).not.toHaveBeenCalled();
    });

    test('le altre righe si scelgono una per una, il pulsante le conta, e vanno con il perché; dopo, l\'esito e «Chiudi»', async () => {
        rete.get.mockResolvedValue({ data: ANTEPRIMA });
        rete.post.mockResolvedValue({ data: { righe: 19, oscurato_il: '12/03/2027', riga_id: 900, esito: 'Fatto: 19 righe oscurate il 12/03/2027.' } });
        await monta();
        expect(testo()).toContain('contiene «carlo.fontana@libero.it»');

        document.querySelector<HTMLButtonElement>('[data-testid="altra-205"]')!.click();
        await flushPromises();
        expect(document.querySelector('[data-testid="oscura"]')!.textContent!.trim()).toBe('Oscura 19 righe');

        await scrivi('[data-testid="perche"]', 'Richiesta scritta del 10/03/2027, protocollo 41');
        await manda();

        expect(rete.post).toHaveBeenCalledWith('/admin.registro-modifiche.oscuramento.oscura/anagrafica/57',
            { perche: 'Richiesta scritta del 10/03/2027, protocollo 41', altre: [205] });
        expect(document.querySelector('[data-testid="esito"]')!.textContent).toContain('Fatto: 19 righe oscurate il 12/03/2027.');
        expect(w!.emitted('oscurato')![0]).toEqual([{ righe: 19, oscurato_il: '12/03/2027', riga_id: 900 }]);
        expect(testo()).toContain('Chiudi');
    });

    test('senza righe da oscurare, lo dice e il pulsante è spento', async () => {
        rete.get.mockResolvedValue({ data: { ...ANTEPRIMA, righe: { quante: 0, dal: null, al: null, elenco: [] }, altre: { quante: 0, elenco: [] } } });
        await monta();

        expect(testo()).toContain('Nessuna riga del registro ha ancora i suoi dati: non c\'è niente da oscurare.');
        expect(document.querySelector('[data-testid="oscura"]')!.hasAttribute('disabled')).toBe(true);
    });

    test('il messaggio del server, se rifiuta', async () => {
        rete.get.mockResolvedValue({ data: ANTEPRIMA });
        rete.post.mockRejectedValue({ response: { status: 422, data: { errors: { perche: ['Nel registro non ci sono righe con i suoi dati da oscurare.'] } } } });
        await monta();
        await scrivi('[data-testid="perche"]', 'Richiesta');
        await manda();

        expect(testo()).toContain('Nel registro non ci sono righe con i suoi dati da oscurare.');
    });

    test('nessuna scritta sotto i 12 pixel, pillole, bordi spessi a sinistra, <select>, v-html', () => {
        const sorgente = readFileSync(resolve(__dirname, 'FinestraOscuramento.vue'), 'utf8');
        expect(sorgente).not.toMatch(/text-\[(\d|10|11)px\]/);
        expect(sorgente).not.toContain('rounded-full');
        expect(sorgente).not.toMatch(/border-l-[2-8]/);
        expect(sorgente).not.toContain('<select');
        expect(sorgente).not.toContain('v-html');
    });
});

describe('il file di un ripristino rimasto', () => {
    test('la finestra dice che c\'è un file con le righe di prima e quando si toglie da solo; senza file non dice niente', async () => {
        rete.get.mockResolvedValueOnce({ data: { ...ANTEPRIMA, file_rimasti: ['C\'è un file del ripristino del 03/03/2027 con le righe di prima: si toglie da solo il 02/04/2027.'] } });
        await monta();
        expect(document.querySelector('[data-testid="file-rimasti"]')?.textContent?.replace(/\s+/g, ' ').trim())
            .toBe('C\'è un file del ripristino del 03/03/2027 con le righe di prima: si toglie da solo il 02/04/2027.');
        w?.unmount();
        document.body.innerHTML = '';

        rete.get.mockResolvedValueOnce({ data: { ...ANTEPRIMA, file_rimasti: [] } });
        await monta();
        expect(document.querySelector('[data-testid="file-rimasti"]')).toBeNull();
    });
});
