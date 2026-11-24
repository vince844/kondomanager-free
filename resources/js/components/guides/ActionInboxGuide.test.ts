// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida dell'Action inbox, nel cassetto di lato.
 *
 * - Aperta mostra le sue parti; chiusa non c'è.
 * - Dice ciò che la pagina fa davvero: le cinque card e la regola di «scaduto» (un compito di oggi non è scaduto), i bonifici segnalati a parte, i due grafici
 *   che filtrano, i gruppi dell'elenco, la prossima azione del tipo e «Fatto», le risposte del menu, i sei mesi nella colonna e nel cassetto, «Dove si
 *   accumulano», il condominio scelto che vale per tutta la pagina.
 * - Ogni frase porta, accanto nel sorgente, la riga di codice che la prova (`<!-- prova: file:riga «testo» -->`): la riga c'è e contiene quel testo.
 * - Ogni nome fra virgolette basse che la guida cita (non le frasi di esempio, che finiscono con il punto) c'è davvero nei sorgenti della pagina.
 * - A video «la prossima azione», mai «il passo».
 */
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Guida = (await import('./ActionInboxGuide.vue')).default;

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const sorgenteGuida = leggi('resources/js/components/guides/ActionInboxGuide.vue');
const sorgenti = [
    'resources/js/pages/dashboard/ActionInbox.vue',
    'resources/js/components/inbox/CardDellInbox.vue',
    'resources/js/components/inbox/RigaDelCompito.vue',
    'resources/js/lib/inbox/actionInbox.ts',
    'resources/js/lib/inbox/risposteDelCompito.ts',
    'resources/js/components/gestionale/seiMesi/SeiMesiColonna.vue',
    'resources/js/components/gestionale/seiMesi/SeiMesiCassetto.vue',
    'resources/js/components/gestionale/seiMesi/SeiMesiDelloStudio.vue',
    'resources/js/lib/gestionale/debiti/seiMesiDelloStudio.ts',
    'app/Services/Gestionale/Inbox/TipiDeiCompiti.php',
    'app/Services/Gestionale/Inbox/RiepilogoDellInbox.php',
    'app/Services/Gestionale/RispostePromemoria.php',
    'app/Services/Gestionale/Inbox/CompitoDellErrore.php',
    'resources/js/components/gestionale/seiMesi/RigaDelloStudio.vue',
].map(leggi).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function apri() {
    w = mount(Guida, { attachTo: document.body, props: { open: true } });
    await flushPromises();

    return (document.body.textContent ?? '').replace(/\s+/g, ' ');
}

describe('la guida dell\'Action inbox', () => {
    test('aperta mostra le sue parti; chiusa non c\'è', async () => {
        const t = await apri();
        for (const parte of ['A che cosa serve', 'Le sei card', 'I due grafici', 'L\'elenco dei compiti', 'La prossima azione', 'Sei mesi per agire', 'Dove si accumulano']) {
            expect(t).toContain(parte);
        }
        w!.unmount();
        document.body.innerHTML = '';
        w = mount(Guida, { attachTo: document.body, props: { open: false } });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="guida-action-inbox"]')).toBeNull();
    });

    test('dice la regola di «scaduto», i bonifici a parte, il condominio scelto per tutta la pagina e che «Fatto» apre la risposta', async () => {
        const t = await apri();
        expect(t).toContain('un compito di oggi non è scaduto');
        expect(t).toContain('Bonifici segnalati');
        expect(t).toContain('tutta la pagina');
        expect(t).toContain('Fatto');
        expect(t).toContain('Ricordamelo');
        expect(t).toContain('Apri l\'elenco');
    });

    test('dice che «Ricordamelo il…» su un errore avvisato scrive in agenda «Riprendi un errore avvisato», senza il codice, il nome e la nota', async () => {
        const t = await apri();
        expect(t).toContain('«Ricordamelo il…» su un errore avvisato scrive nell\'agenda il promemoria «Riprendi un errore avvisato», senza il codice, il nome e la nota');
    });

    test('ogni frase ha la riga di codice che la prova, e la riga c\'è', () => {
        const prove = [...sorgenteGuida.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
        expect(prove.length).toBeGreaterThanOrEqual(14);
        const mancanti = prove.filter(([, file, riga, testo]) => {
            if (!existsSync(resolve(process.cwd(), file))) return true;
            const righe = leggi(file).split('\n');

            return !(righe[Number(riga) - 1] ?? '').replace(/\\'/g, '\'').includes(testo);
        }).map(([, file, riga, testo]) => `${file}:${riga} «${testo}»`);
        expect(mancanti).toEqual([]);
        const voci = sorgenteGuida.match(/<li class="flex gap-2">/g) ?? [];
        const conProva = sorgenteGuida.match(/<li class="flex gap-2"><!-- prova:/g) ?? [];
        expect(conProva.length).toBe(voci.length);
        // Ogni prova ha il numero della riga: una prova senza il numero non la controlla nessuno, e resterebbe verde anche se la riga si spostasse.
        expect(prove.length).toBe(voci.length);
    });

    test('ogni nome fra virgolette basse c\'è nei sorgenti', async () => {
        const t = await apri();
        const nomi = [...new Set([...t.matchAll(/«([^«»]+)»/g)].map((m) => m[1]))].filter((n) => !n.endsWith('.'));
        expect(nomi.length).toBeGreaterThan(5);
        expect(nomi.filter((n) => !sorgenti.includes(n))).toEqual([]);
    });

    test('a video «la prossima azione», mai «il passo»', async () => {
        const t = await apri();
        expect(t).not.toMatch(/\b[Pp]ass[oi]\b/);
    });
});
