// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida della dashboard del condominio, nel cassetto di lato.
 *
 * - Aperta mostra le sue parti, una per riquadro; chiusa non c'è.
 * - Dice ciò che la pagina fa davvero: «Sei mesi per agire», la tesoreria con le quattro cifre, «Da dove viene» e i due cassetti, il saldo previsto con i tre
 *   scenari e un esempio con le cifre, il semaforo che non segue lo scenario, il cassetto delle azioni consigliate con la simulazione e le regole di ogni azione,
 *   la copertura del bilancio, l'Inbox operativa con le risposte, gli altri riquadri.
 * - Ogni frase porta, accanto nel sorgente, la riga di codice che la prova (`<!-- prova: file:riga «testo» -->`): la riga c'è e contiene quel testo.
 * - Ogni nome fra virgolette basse che la guida cita (non le frasi di esempio, che finiscono con il punto) c'è davvero nei sorgenti della pagina, fuori dai
 *   commenti: un nome che c'è solo in un commento a video non c'è.
 * - A video «la prossima azione», mai «il passo».
 */
import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Guida = (await import('./DashboardCondominioGuide.vue')).default;

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
// Il testo che la pagina mostra, senza i commenti: un nome che c'è solo in un commento non è a video.
const senzaCommenti = (s: string) => s.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:'"`])\/\/.*$/gm, '$1 ');
const sorgenteGuida = leggi('resources/js/components/guides/DashboardCondominioGuide.vue');
const sorgenti = [
    'resources/js/pages/gestionale/dashboard/Dashboard.vue',
    'resources/js/pages/gestionale/dashboard/components/TreasuryGuardianWidget.vue',
    'resources/js/pages/gestionale/dashboard/components/DaDoveViene.vue',
    'resources/js/pages/gestionale/dashboard/components/SaldoPrevistoCard.vue',
    'resources/js/pages/gestionale/dashboard/components/AzioniConsigliate.vue',
    'resources/js/pages/gestionale/dashboard/components/EmissioneRapidaDialog.vue',
    'resources/js/pages/gestionale/dashboard/components/CreditiDaCompensareWidget.vue',
    'resources/js/pages/gestionale/dashboard/components/ControlliPostImportWidget.vue',
    'resources/js/lib/gestionale/tesoreria/saldoPrevisto.ts',
    'resources/js/lib/gestionale/tesoreria/azioniConsigliate.ts',
    'resources/js/lib/gestionale/tesoreria/emissioneRapida.ts',
    'resources/js/lib/gestionale/tesoreria/tesoreria.ts',
    'resources/js/components/gestionale/seiMesi/SeiMesiPerAgireWidget.vue',
    'resources/js/lib/gestionale/debiti/seiMesiPerAgire.ts',
    'resources/js/lib/inbox/risposteDelCompito.ts',
    'resources/js/lib/inbox/inboxDelCondominio.ts',
    'app/Services/Gestionale/Inbox/TipiDeiCompiti.php',
    // Le pagine che la guida nomina: il menu «⋯» del compito, «Ritenute e F24», la scheda «Cassa» del rendiconto, «Registra giroconto».
    'resources/js/components/inbox/MenuDelCompito.vue',
    'resources/js/layouts/gestionale/MovimentiLayout.vue',
    'resources/js/components/gestionale/movimenti/rendiconto/RendicontoSchede.vue',
    'resources/js/pages/gestionale/movimenti/giroconti/GirocontoNew.vue',
    'resources/js/pages/gestionale/dashboard/components/RigaCreditoDaCompensare.vue',
    'resources/js/pages/gestionale/struttura/ImpostazioniCondominio.vue',
].map((f) => senzaCommenti(leggi(f))).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');

let w: VueWrapper | null = null;
afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

async function apri() {
    w = mount(Guida, { attachTo: document.body, props: { open: true } });
    await flushPromises();

    return (document.body.textContent ?? '').replace(/\s+/g, ' ');
}

describe('la guida della dashboard del condominio', () => {
    test('aperta mostra una parte per riquadro; chiusa non c\'è', async () => {
        const t = await apri();
        for (const parte of ['A che cosa serve', 'Sei mesi per agire', 'Tesoreria a 30 giorni', 'Saldo previsto', 'Le azioni consigliate', 'Copertura bilancio',
            'Inbox operativa', 'Gli altri riquadri']) {
            expect(t).toContain(parte);
        }
        w!.unmount();
        document.body.innerHTML = '';
        w = mount(Guida, { attachTo: document.body, props: { open: false } });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="guida-dashboard-condominio"]')).toBeNull();
    });

    test('i tre scenari con un esempio con le cifre, il semaforo che non li segue, quanto basta e le regole delle due azioni', async () => {
        const t = await apri();
        for (const frase of ['Senza le rate', 'Con le rate in scadenza', 'Se pagano tutti', '€ 1.462,03', '€ 2.368,77', 'il semaforo', 'quanto basta',
            'Recupera almeno', 'Chiedi di pagare le fatture', 'le approva l\'assemblea', 'Simula il saldo', 'Da dove viene']) {
            expect(t).toContain(frase);
        }
    });

    test('la finestra «Emetti le rate»: le spunte di serie, il riepilogo con un esempio, gli stessi controlli della pagina del piano, «Apri il piano»', async () => {
        const t = await apri();
        for (const frase of ['«Emetti la rata» apre la finestra «Emetti le rate»', 'spuntate quelle già scadute e quelle dei prossimi 30 giorni', '3 rate, 4 condòmini, € 3.588,94',
            'con gli stessi controlli', 'il motivo si legge nella finestra e nessuna rata va a giornale', '«Apri il piano»']) {
            expect(t).toContain(frase);
        }
        expect(t).not.toContain('«Emetti la rata» porta alla pagina del piano');
    });

    test('l\'Inbox con la prossima azione del tipo e le risposte; le segnalazioni che non scadono', async () => {
        const t = await apri();
        for (const frase of ['Segna come fatto', 'Fatto', 'Non serve', 'Ricordamelo', 'aperta il', 'urgente']) {
            expect(t).toContain(frase);
        }
    });

    test('ogni frase ha la riga di codice che la prova, e la riga c\'è', () => {
        const prove = [...sorgenteGuida.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
        expect(prove.length).toBeGreaterThanOrEqual(30);
        const frasi = [...sorgenteGuida.matchAll(/<li class="flex gap-2">/g)];
        expect(prove.length).toBe(frasi.length);
        const mancanti = prove.filter(([, file, riga, testo]) => {
            if (!existsSync(resolve(process.cwd(), file))) return true;
            const righe = leggi(file).split('\n');

            return !(righe[Number(riga) - 1] ?? '').replace(/\\'/g, '\'').includes(testo);
        }).map(([, file, riga, testo]) => `${file}:${riga} «${testo}»`);
        expect(mancanti).toEqual([]);
    });

    test('i nomi fra virgolette basse che la guida cita ci sono nei sorgenti della pagina', async () => {
        const t = await apri();
        const nomi = [...t.matchAll(/«([^»]+)»/g)].map((m) => m[1]).filter((n) => !/[.!?]$/.test(n));
        expect(nomi.length).toBeGreaterThan(10);
        // «⋯» è disegnato con un'icona (`MoreHorizontal`), non scritto.
        const disegnati: Record<string, string> = { '⋯': 'MoreHorizontal' };
        const assenti = [...new Set(nomi)].filter((n) => !sorgenti.includes(n) && !(disegnati[n] && sorgenti.includes(disegnati[n])));
        expect(assenti).toEqual([]);
    });

    test('a video «la prossima azione», mai «il passo»', async () => {
        const t = await apri();
        expect(t).toContain('prossima azione');
        expect(t).not.toMatch(/\bpass[oi]\b/i);
    });
});
