// @vitest-environment jsdom

/**
 * La guida della pagina delle ricevute, nella finestra di lato.
 *
 * - Aperta, mostra le sue parti, da «Da dove parte la pagina» a «La ricevuta di un incasso solo»; chiusa non c'è.
 * - Ogni nome tra virgolette basse che la guida cita (un pulsante, una casella, un filtro, una frase della pagina) c'è davvero nei sorgenti della
 *   pagina e dei suoi pezzi: la guida non parla di comandi che la pagina non ha. «N» sta per il numero che la pagina scrive.
 * - Le parole che il programma non usa non ci sono.
 *
 * Che cosa NON copre: che ogni frase sia vera sul server; le frasi sono state controllate una per una sul codice quando la guida è stata scritta.
 */

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Guida = (await import('./RicevuteIncassiGuide.vue')).default;

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const sorgenteGuida = leggi('resources/js/components/guides/RicevuteIncassiGuide.vue');
const sorgentiPagina = [
    'resources/js/pages/gestionale/movimenti/incassi/RicevuteIncassi.vue',
    'resources/js/components/gestionale/movimenti/incassi/DataTableToolbar.vue',
    'resources/js/components/gestionale/movimenti/incassi/RicevutaDialog.vue',
    'resources/js/lib/gestionale/incassi/ricevute.ts',
].map(leggi).join('\n').replace(/\s+/g, ' ');

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    document.body.innerHTML = '';
});

describe('la guida delle ricevute', () => {
    test('aperta mostra le sue parti, chiusa non c\'è', async () => {
        w = mount(Guida, { attachTo: document.body, props: { open: false } });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="guida-ricevute"]')).toBeNull();

        await w.setProps({ open: true });
        await flushPromises();
        const guida = document.body.querySelector('[data-testid="guida-ricevute"]');
        expect(guida).not.toBeNull();
        expect([...guida!.querySelectorAll('h3')].map((h) => (h.textContent ?? '').trim())).toEqual([
            'Da dove parte la pagina', 'I filtri', 'La scelta', 'Nella finestra, prima di emettere', 'Stampa', 'PDF per persona', 'Invia per email',
            'Chi non ha un indirizzo', 'Gli avvisi in testa alla pagina', 'La ricevuta di un incasso solo',
        ]);
        expect(guida!.textContent).toContain('dei versamenti dal primo del mese scorso a oggi');
    });

    test('ogni nome tra virgolette basse c\'è nella pagina', () => {
        const citati = [...new Set([...sorgenteGuida.matchAll(/«([^»]+)»/g)].map((m) => m[1].replace(/\s+/g, ' ')))];
        expect(citati.length).toBeGreaterThan(20);
        // «N» è il numero che la pagina scrive con `{{ … }}`.
        const nellaPagina = (nome: string) => new RegExp(nome.split(' N ').map((p) => p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join(' \\{\\{[^}]+\\}\\} ')).test(sorgentiPagina);
        expect(citati.filter((nome) => !nellaPagina(nome))).toEqual([]);
    });

    test('nessuna parola che il programma non usa', () => {
        const testo = sorgenteGuida.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').toLowerCase();
        for (const parola of ['dovut', 'esigibil', 'pronto', 'pronta', 'conforme', 'regolare', 'fisiologic', 'consegnat', 'recapitat', 'notificat', 'garant']) {
            expect(testo, parola).not.toContain(parola);
        }
    });
});
