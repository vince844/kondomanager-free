// @vitest-environment jsdom

/**
 * La guida della scheda «Impostazioni» del condominio, nel cassetto di lato.
 *
 * - Aperta mostra le sue parti; chiusa non c'è.
 * - Dice che le impostazioni valgono solo per questo condominio, che cosa fa ogni scelta e che cosa cambia con l'altra, dove si vede l'effetto, e che il
 *   programma non manda niente da solo.
 * - Ogni frase porta, accanto nel sorgente, la riga di codice che la prova (`<!-- prova: file:riga «testo» -->`): la riga c'è e contiene quel testo. Se il
 *   codice cambia, la prova lo dice.
 * - Ogni nome fra virgolette basse che la guida cita (non le frasi di esempio, che finiscono con il punto) c'è davvero nei sorgenti delle pagine e dei pezzi che
 *   nomina.
 * - A video «la prossima azione», mai «il passo».
 */
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Guida = (await import('./ImpostazioniCondominioGuide.vue')).default;

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const sorgenteGuida = leggi('resources/js/components/guides/ImpostazioniCondominioGuide.vue');
const sorgenti = [
    'resources/js/pages/gestionale/struttura/ImpostazioniCondominio.vue',
    'resources/js/components/gestionale/seiMesi/SeiMesiPerAgireWidget.vue',
    'resources/js/components/gestionale/seiMesi/PassoMenu.vue',
    'resources/js/components/gestionale/seiMesi/SeiMesiCompito.vue',
    'resources/js/lib/gestionale/debiti/seiMesiPerAgire.ts',
    'resources/js/layouts/gestionale/StrutturaLayout.vue',
    'resources/js/pages/gestionale/movimenti/rateScadute/RateScadute.vue',
    'app/Services/Gestionale/Debiti/PassoProposto.php',
    'resources/js/pages/gestionale/struttura/RegoleRecupero.vue',
].map(leggi).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function apri() {
    w = mount(Guida, { attachTo: document.body, props: { open: true } });
    await flushPromises();

    return (document.body.textContent ?? '').replace(/\s+/g, ' ');
}

describe('la guida delle impostazioni del condominio', () => {
    test('aperta mostra le sue parti; chiusa non c\'è', async () => {
        const t = await apri();
        for (const parte of ['A che cosa servono', 'Dopo una lettera, quando scadono rate nuove', 'La prossima azione', 'La tolleranza', 'Che cosa non fanno']) {
            expect(t).toContain(parte);
        }
        w!.unmount();
        document.body.innerHTML = '';
        w = mount(Guida, { attachTo: document.body, props: { open: false } });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="guida-impostazioni-condominio"]')).toBeNull();
    });

    test('dice che valgono solo per questo condominio, i due interruttori accesi e spenti e la riga «Adesso», e che il programma non manda niente da solo', async () => {
        const t = await apri();
        expect(t).toContain('valgono solo per questo condominio');
        expect(t).toContain('Una rata nuova non fa tornare indietro');
        expect(t).toContain('Il programma suggerisce la prossima azione');
        expect(t).toContain('interruttore');
        expect(t).toContain('Acceso');
        expect(t).toContain('Spento');
        expect(t).toContain('Adesso');
        expect(t).toContain('Sei mesi per agire');
        expect(t).toContain('non manda niente da solo');
    });

    test('ogni frase ha la riga di codice che la prova, e la riga c\'è', () => {
        const prove = [...sorgenteGuida.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
        expect(prove.length).toBeGreaterThanOrEqual(12);
        const mancanti = prove.filter(([, file, riga, testo]) => {
            if (!existsSync(resolve(process.cwd(), file))) return true;
            const righe = leggi(file).split('\n');

            return !(righe[Number(riga) - 1] ?? '').replace(/\\'/g, '\'').includes(testo);
        }).map(([, file, riga, testo]) => `${file}:${riga} «${testo}»`);
        expect(mancanti).toEqual([]);
        // Ogni voce dell'elenco della guida ha la sua prova.
        const voci = sorgenteGuida.match(/<li class="flex gap-2">/g) ?? [];
        const voci_con_prova = sorgenteGuida.match(/<li class="flex gap-2"><!-- prova:/g) ?? [];
        expect(voci_con_prova.length).toBe(voci.length);
    });

    test('ogni nome fra virgolette basse c\'è nei sorgenti', async () => {
        const t = await apri();
        // I nomi (pulsanti, colonne, scelte, schede); non le frasi di esempio, che finiscono con il punto.
        const nomi = [...new Set([...t.matchAll(/«([^«»]+)»/g)].map((m) => m[1]))].filter((n) => !n.endsWith('.'));
        expect(nomi.length).toBeGreaterThan(5);
        expect(nomi.filter((n) => !sorgenti.includes(n))).toEqual([]);
    });

    test('a video «la prossima azione», mai «il passo»', async () => {
        const t = await apri();
        expect(t).not.toMatch(/\b[Pp]ass[oi]\b/);
    });
});
