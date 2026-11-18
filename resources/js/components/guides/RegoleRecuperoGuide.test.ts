// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della pagina «Recupero dei crediti», nella finestra di lato.
 *
 * - Aperta, mostra le sue parti, da «Come si usano le regole» a «Il tasso legale»; chiusa non c'è.
 * - Dice che non c'è una regola da scegliere, che in un giorno ne vale una sola per tutto il condominio, che senza regole vale la legge e che gli
 *   interessi registrati non cambiano; e che il riferimento di un tasso scritto è obbligatorio e si stampa.
 * - Ogni nome tra virgolette basse che la guida cita (un pulsante, un campo, una scelta, una frase della pagina) c'è davvero nei sorgenti della pagina, dei
 *   suoi pezzi e dell'elenco «Rate scadute», o nel modello che manda alla pagina i nomi delle fonti: la guida non parla di comandi che la pagina non ha.
 * - Le parole che il programma non usa non ci sono.
 *
 * Che cosa NON copre: che ogni frase sia vera sul server; le frasi sono state controllate una per una sul codice quando la guida è stata scritta.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Guida = (await import('./RegoleRecuperoGuide.vue')).default;

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const sorgenteGuida = leggi('resources/js/components/guides/RegoleRecuperoGuide.vue');
// Nei sorgenti l'apostrofo dentro le stringhe è `\'`: si legge com'è a video.
const sorgentiPagina = [
    'resources/js/pages/gestionale/struttura/RegoleRecupero.vue',
    'resources/js/components/gestionale/addebiti/TassoLegaleAManoDialog.vue',
    'resources/js/lib/gestionale/addebiti/accessori.ts',
    'resources/js/pages/gestionale/movimenti/rateScadute/RateScadute.vue',
    'resources/js/lib/gestionale/debiti/rateScadute.ts',
    'app/Models/Gestionale/RegolaRecupero.php',
].map(leggi).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    document.body.innerHTML = '';
});

describe('la guida del recupero dei crediti', () => {
    test('aperta mostra le sue parti, chiusa non c\'è', async () => {
        w = mount(Guida, { attachTo: document.body, props: { open: false } });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="guida-recupero-crediti"]')).toBeNull();

        await w.setProps({ open: true });
        await flushPromises();
        const guida = document.body.querySelector('[data-testid="guida-recupero-crediti"]');
        expect(guida).not.toBeNull();
        expect([...guida!.querySelectorAll('h3')].map((h) => (h.textContent ?? '').trim())).toEqual([
            'Come si usano le regole', 'Dove entrano', 'I campi della regola', 'Le fonti di un tasso scritto', 'Il tasso legale',
        ]);
        const testo = (guida!.textContent ?? '').replace(/\s+/g, ' ');
        expect(testo).toContain('Non c\'è una regola da scegliere. In un giorno vale una regola sola, per tutto il condominio: la stessa per tutte le persone.');
        expect(testo).toContain('Ogni regola vale dal suo giorno al giorno prima della regola dopo.');
        expect(testo).toContain('Senza nessuna regola vale la legge');
        expect(testo).toContain('il tasso legale dell\'anno (artt. 1224, 1282 e 1284 c.c.), dal giorno dopo la scadenza (o dopo l\'emissione, se la rata è emessa più tardi).');
        expect(testo).toContain('se la rata è emessa più tardi, il giorno dopo l\'emissione: prima di essere chiesta, la rata non è in ritardo.');
        expect(testo).toContain('con 0 gli interessi contano dal giorno dopo la scadenza (o dopo l\'emissione, se la rata è emessa più tardi)');
        expect(testo).toContain('Gli interessi già registrati non cambiano.');
        // Decisione 438: con un accordo di rientro in regola restano fuori i giorni dalla data dell'accordo, quelli prima restano nella proposta.
        expect(testo).toContain('la proposta lascia fuori i giorni dalla data dell\'accordo in poi: i giorni prima dell\'accordo restano nella proposta');
        expect(testo).toContain('Se registri la decadenza, o il calendario finisce con rate non versate, tornano anche i giorni dell\'accordo.');
        // Decisione 450: su una rata coperta in parte gli interessi continuano sulla parte fuori dall'accordo, salvo la scelta dell'accordo.
        expect(testo).toContain('Su una rata coperta solo in parte, sulla parte fuori dall\'accordo gli interessi continuano; se nell\'accordo hai scelto che restino '
            + 'sospesi finché l\'accordo regge, dalla data dell\'accordo resta fuori tutta la rata.');
        expect(testo).toContain('con un tasso scritto è obbligatorio, al più 150 caratteri');
        expect(testo).toContain('Si stampa per intero accanto al tasso, nelle lettere e negli interessi registrati.');
        expect(testo).toContain('una delibera a maggioranza può non bastare');
        expect(testo).toContain('Scrivi il tasso di un anno che manca');
    });

    test('ogni nome tra virgolette basse c\'è nella pagina', () => {
        const citati = [...new Set([...sorgenteGuida.matchAll(/«([^»]+)»/g)].map((m) => m[1].replace(/\s+/g, ' ')))];
        expect(citati.length).toBeGreaterThan(20);
        expect(citati.filter((nome) => !sorgentiPagina.includes(nome))).toEqual([]);
    });

    test('nessuna parola che il programma non usa', () => {
        const testo = sorgenteGuida.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').toLowerCase();
        for (const parola of ['dovut', 'esigibil', 'pronto', 'pronta', 'conforme', 'regolare', 'fisiologic', 'consegnat', 'recapitat', 'notificat', 'garant']) {
            expect(testo, parola).not.toContain(parola);
        }
    });
});
