// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della pagina «Rate scadute», nella finestra di lato.
 *
 * - Aperta, mostra le sue parti, dalla riga alla stampa e ai filtri, con quelle degli interessi e delle spese per il ritardo; chiusa non c'è; il testo della
 *   norma arriva dalla pagina.
 * - Dice che gli interessi si propongono solo dal riquadro dei suggerimenti, a tratti con le regole, che la spesa per il ritardo è «Spese del ritardo», che
 *   «Dispense dei sei mesi» è in alto, e l'ordine dell'incasso (art. 1194 c.c.).
 * - Ogni nome tra virgolette basse che la guida cita (un pulsante, un filtro, una scelta, una frase della pagina) c'è davvero nei sorgenti della pagina e dei
 *   suoi pezzi: le finestre degli interessi, delle spese, delle lettere e dell'accordo, l'incasso. «N» sta per il numero che la pagina scrive.
 * - Le parole che il programma non usa non ci sono.
 *
 * Che cosa NON copre: che ogni frase sia vera sul server; le frasi sono state controllate una per una sul codice quando la guida è stata scritta.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Guida = (await import('./RateScaduteGuide.vue')).default;

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const sorgenteGuida = leggi('resources/js/components/guides/RateScaduteGuide.vue');
// Nei sorgenti l'apostrofo dentro le stringhe è `\'`: si legge com'è a video.
const sorgentiPagina = [
    'resources/js/pages/gestionale/movimenti/rateScadute/RateScadute.vue',
    'resources/js/lib/gestionale/debiti/rateScadute.ts',
    'resources/js/components/gestionale/addebiti/ProponiInteressiDialog.vue',
    'resources/js/components/gestionale/addebiti/AddebitaSpesaDialog.vue',
    'resources/js/components/gestionale/addebiti/AddebitiDellaPersona.vue',
    'resources/js/lib/gestionale/addebiti/accessori.ts',
    'resources/js/components/gestionale/lettere/LettereDialog.vue',
    'resources/js/lib/gestionale/lettere/lettere.ts',
    'resources/js/components/gestionale/accordi/AccordoRientroDialog.vue',
    'resources/js/lib/gestionale/debiti/accordiRientro.ts',
    'resources/js/pages/gestionale/movimenti/incassi/IncassoRateNew.vue',
    'resources/js/lib/gestionale/incassi/imputazione.ts',
].map(leggi).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    document.body.innerHTML = '';
});

describe('la guida di «Rate scadute»', () => {
    test('aperta mostra le sue parti e il testo della norma, chiusa non c\'è', async () => {
        w = mount(Guida, { attachTo: document.body, props: { open: false, testoNorma: 'Art. 63 disp. att. c.c.' } });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="guida-rate-scadute"]')).toBeNull();

        await w.setProps({ open: true });
        await flushPromises();
        const guida = document.body.querySelector('[data-testid="guida-rate-scadute"]');
        expect(guida).not.toBeNull();
        expect([...guida!.querySelectorAll('h3')].map((h) => (h.textContent ?? '').trim())).toEqual([
            'Che cosa dice una riga', 'Le due viste e la data dell\'elenco', 'Che cosa non è nell\'elenco', 'I suggerimenti', 'Gli interessi di mora',
            'Spese del ritardo', 'Le date dei sei mesi', 'Le lettere', 'L\'accordo di rientro', 'Quando la persona versa', 'La stampa', 'I filtri',
        ]);
        expect(guida!.querySelector('[data-testid="guida-norma"]')?.textContent?.trim()).toBe('Art. 63 disp. att. c.c.');
        const testo = (guida!.textContent ?? '').replace(/\s+/g, ' ');
        expect(testo).toContain('«Proponi gli interessi» c\'è solo lì');
        expect(testo).toContain('La riga si divide in tratti');
        expect(testo).toContain('«Spese del ritardo» chiede una spesa per il ritardo');
        expect(testo).toContain('«Dispense dei sei mesi», in alto accanto a «Guida»');
        expect(testo).toContain('(art. 1194 c.c.)');
        expect(testo).toContain('La decadenza la registri tu, con «Registra la decadenza»');
        expect(testo).toContain('l\'accordo si chiude per la successione');
        expect(testo).toContain('la proposta degli interessi di mora lascia fuori le rate che copre');
        // Una rata emessa dopo la scadenza conta dal giorno dopo l'emissione: prima di essere chiesta non è in ritardo.
        expect(testo).toContain('I giorni vanno dal giorno dopo la scadenza, più quelli che le regole del condominio concedono (o dal giorno dopo l\'emissione, se la rata è emessa più tardi), alla data della proposta compresa.');
        // Il denaro sulle rate dell'accordo conta per l'accordo con la scelta o senza; la scelta dice dove va prima.
        expect(testo).toContain('Il denaro che arriva sulle rate coperte va all\'accordo, con «Versamento dell\'accordo» o senza, e copre le rate del calendario in ordine');
        expect(testo).toContain('senza, l\'ordine proposto lo manda alle rate fuori dall\'accordo');
    });

    test('ogni nome tra virgolette basse c\'è nella pagina', () => {
        const citati = [...new Set([...sorgenteGuida.matchAll(/«([^»]+)»/g)].map((m) => m[1].replace(/\s+/g, ' ')))];
        expect(citati.length).toBeGreaterThan(25);
        // «N» è il numero che la pagina scrive con `{{ … }}` o `${ … }`.
        const nellaPagina = (nome: string) => new RegExp(nome.split(' N ').map((p) => p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
            .join(' (?:\\{\\{[^}]+\\}\\}|\\$\\{[^}]+\\}) ')).test(sorgentiPagina);
        expect(citati.filter((nome) => !nellaPagina(nome))).toEqual([]);
    });

    test('nessuna parola che il programma non usa', () => {
        const testo = sorgenteGuida.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').toLowerCase();
        for (const parola of ['dovut', 'esigibil', 'pronto', 'pronta', 'conforme', 'regolare', 'fisiologic', 'consegnat', 'recapitat', 'notificat', 'garant']) {
            expect(testo, parola).not.toContain(parola);
        }
    });
});
