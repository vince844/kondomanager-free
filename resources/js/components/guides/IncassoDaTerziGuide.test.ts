// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della pagina «Incasso da terzi», nella finestra di lato.
 *
 * - Aperta, mostra le sue parti: quando si usa, i campi, che cosa scrive nei conti, dove si vede, come si annulla, dopo la registrazione; chiusa non c'è.
 * - Dice le cose che contano: le voci solo di entrata, le casse solo banca e contanti (perché il registro non vedrebbe un fondo), la causale di tre
 *   lettere, il dare e l'avere, lo storno con le sue due nature, «Registra e nuovo» e il ritorno.
 * - Ogni nome tra virgolette basse che la guida cita c'è davvero nei sorgenti della pagina e delle pagine e dei servizi che nomina.
 * - Le parole che il programma non usa non ci sono, e nemmeno le etichette a pillola.
 *
 * Che cosa NON copre: che ogni frase sia vera sul server; le frasi sono state controllate una per una sul codice quando la guida è stata scritta.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Guida = (await import('./IncassoDaTerziGuide.vue')).default;

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const sorgenteGuida = leggi('resources/js/components/guides/IncassoDaTerziGuide.vue');
const sorgentiPagina = [
    'resources/js/pages/gestionale/movimenti/incassiDaTerzi/IncassoDaTerziNew.vue',
    'resources/js/components/gestionale/movimenti/incassi/DataTableToolbar.vue',
    'resources/js/pages/gestionale/movimenti/rendiconto/RiepilogoFinanziario.vue',
    'resources/js/pages/gestionale/movimenti/scritture/Show.vue',
    'app/Services/Gestionale/RegistroContabilitaService.php',
].map(leggi).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

describe('la guida dell\'incasso da terzi', () => {
    test('aperta mostra le sue parti, chiusa non c\'è', async () => {
        w = mount(Guida, { attachTo: document.body, props: { open: false } });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="guida-incasso-da-terzi"]')).toBeNull();
        await w.setProps({ open: true });
        await flushPromises();
        const guida = document.body.querySelector('[data-testid="guida-incasso-da-terzi"]')!;
        expect([...guida.querySelectorAll('h3')].map((h) => (h.textContent ?? '').trim()))
            .toEqual(['Quando si usa', 'I campi', 'Che cosa scrive nei conti', 'Dove si vede', 'Come si annulla', 'Dopo la registrazione']);
    });

    test('dice le cose che contano', async () => {
        w = mount(Guida, { attachTo: document.body, props: { open: true } });
        await flushPromises();
        const testo = (document.body.querySelector('[data-testid="guida-incasso-da-terzi"]')!.textContent ?? '').replace(/\s+/g, ' ');
        expect(testo).toContain('per il denaro che entra da chi non è un condòmino');
        expect(testo).toContain('solo le voci di entrata del condominio, delle gestioni dell\'esercizio aperto');
        expect(testo).toContain('solo le banche e le casse contanti attive');
        expect(testo).toContain('un\'entrata scritta su un fondo non ci comparirebbe');
        expect(testo).toContain('almeno tre lettere, al più 255');
        expect(testo).toContain('in dare la banca o la cassa, in avere il conto della voce di entrata');
        expect(testo).toContain('fra le «Entrate da terzi», con chi ha versato come controparte');
        expect(testo).toContain('una registrazione sbagliata');
        expect(testo).toContain('un incasso tornato indietro');
        expect(testo).toContain('«Registra e nuovo» registra e riapre il modulo vuoto');
    });

    test('ogni nome tra virgolette basse c\'è nei sorgenti', () => {
        const citati = [...new Set([...sorgenteGuida.matchAll(/«([^»]+)»/g)].map((m) => m[1].replace(/\s+/g, ' ')))];
        expect(citati.length).toBeGreaterThan(15);
        expect(citati.filter((nome) => !sorgentiPagina.includes(nome))).toEqual([]);
    });

    test('nessuna parola che il programma non usa, e nessuna etichetta a pillola', () => {
        const testo = sorgenteGuida.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').toLowerCase();
        for (const parola of ['dovut', 'esigibil', 'pronto', 'pronta', 'conforme', 'regolare', 'fisiologic', 'consegnat', 'recapitat', 'notificat', 'garant']) {
            expect(testo, parola).not.toContain(parola);
        }
        expect(sorgenteGuida).not.toContain('rounded-full');
    });
});
