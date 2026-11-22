// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida delle pagine delle assemblee (1.12.0-beta.13): l'archivio, «Prepara l'assemblea», la pagina dell'assemblea e la raccolta hanno «Guida»
 * nell'intestazione, che apre questa guida nel cassetto.
 *
 * - Aperta mostra le sue parti; chiusa non c'è.
 * - Ogni frase porta, accanto nel sorgente, la riga di codice che la prova (`<!-- prova: file:riga «testo» -->`), con il numero della riga: la riga c'è e
 *   contiene quel testo.
 * - Ogni nome fra virgolette basse c'è a video nelle pagine e nei componenti delle assemblee, o nei passi che scrive il server (commenti esclusi).
 * - Spiega la strada dei sei passi: i cerchi, il lucchetto, «Che cosa manca», «Avanti», la strada che comincia in «Prepara l'assemblea», i punti proposti
 *   che aspettano, il passo 6 «Delibere eseguite» che si apre con il verbale registrato; non parla più di card né di schede della pagina. Il diario non sta più sulla strada: «Diario» nel menu «Azioni», in un cassetto.
 * - Dice l'intestazione: il menu «Azioni», «Assemblee» che torna all'elenco, «Tenuta su carta» (non più «Tenuta fuori dal programma»).
 * - Dice che i punti si scrivono già in «Prepara l'assemblea», con la finestra di «Aggiungi un punto», in «Scritti da te», prima di «Varie ed eventuali»; non
 *   dice più che si aggiungono solo nella pagina dell'assemblea.
 */
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const senzaCommenti = (s: string) => s.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:'"`])\/\/.*$/gm, '$1 ');
const PAGINE = ['Archivio', 'Prepara', 'Assemblea', 'Raccolta'].map((p) => `resources/js/pages/gestionale/assemblee/${p}.vue`);
const COMPONENTI = ['resources/js/components/assemblee', 'resources/js/components/assemblee/sala'].flatMap((c) => readdirSync(resolve(process.cwd(), c))
    .filter((f) => f.endsWith('.vue')).map((f) => join(c, f)));
const GUIDA = 'resources/js/components/guides/AssembleaGuide.vue';
/** I nomi dei passi li scrive il server: anche loro sono a video. */
const PASSI = 'app/Services/Assemblee/PassiDellAssemblea.php';

describe('le pagine delle assemblee hanno «Guida»', () => {
    test.each(PAGINE)('%s: l\'intestazione ha «Guida», che apre la guida nel cassetto', (pagina) => {
        const s = leggi(pagina);
        expect(s).toMatch(/<PageHeaderGuide[\s\S]*?has-text-guide[\s\S]*?@open-text-guide="mostraGuida = true"/);
        expect(s).toContain('import AssembleaGuide from \'@/components/guides/AssembleaGuide.vue\';');
        // La pagina dell'assemblea apre la guida della convocazione quando la scheda aperta è la convocazione o il fascicolo (v-if / v-else).
        expect(s).toMatch(/<AssembleaGuide (v-else )?v-model:open="mostraGuida" \/>/);
    });
});

describe('la guida delle assemblee', () => {
    let w: VueWrapper | null = null;
    afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
    async function apri(open = true) {
        const Guida = (await import('./AssembleaGuide.vue')).default;
        w = mount(Guida, { attachTo: document.body, props: { open } });
        await flushPromises();

        return (document.body.textContent ?? '').replace(/\s+/g, ' ');
    }

    test('aperta mostra le sue parti; chiusa non c\'è', async () => {
        const t = await apri();
        for (const parte of ['L\'archivio', 'La strada dei passi', 'Le date e la tabella', 'L\'ordine del giorno', 'Le delibere nelle pagine', 'Il memo e il «Diario»']) {
            expect(t).toContain(parte);
        }
        w!.unmount();
        document.body.innerHTML = '';
        await apri(false);
        expect(document.body.querySelector('[data-testid="guida-assemblee"]')).toBeNull();
    });

    test('ogni frase ha la riga di codice che la prova, con il numero, e la riga c\'è', () => {
        const sorgente = leggi(GUIDA);
        const voci = sorgente.match(/<li class="flex gap-2">/g) ?? [];
        const prove = [...sorgente.matchAll(/<!-- prova: ([^\s:]+):(\d+) «([^»]+)» -->/g)];
        expect(voci.length).toBeGreaterThanOrEqual(10);
        expect(prove.length).toBe(voci.length);
        const mancanti = prove.filter(([, file, riga, testo]) => !existsSync(resolve(process.cwd(), file))
            || !(leggi(file).split('\n')[Number(riga) - 1] ?? '').replace(/\\'/g, '\'').includes(testo)).map(([, f, r, t]) => `${f}:${r} «${t}»`);
        expect(mancanti).toEqual([]);
    });

    test('il memo dalla dashboard: la card «Per la prossima assemblea» con il menu dei punti e «Aggiungi un punto», il menu «Azioni»', async () => {
        const t = await apri();
        for (const frase of ['«Per la prossima assemblea»', '«Modifica»', '«Non serve più»', '«Aggiungi un punto»', '«Azioni»']) {
            expect(t).toContain(frase);
        }
    });

    test('ogni nome fra virgolette basse c\'è a video nelle pagine o nei componenti delle assemblee', async () => {
        const t = await apri();
        const sorgenti = [...PAGINE, ...COMPONENTI, PASSI].map((f) => senzaCommenti(leggi(f))).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');
        const nomi = [...new Set([...t.matchAll(/«([^«»]+)»/g)].map((m) => m[1]))].filter((n) => !n.endsWith('.'));
        expect(nomi.length).toBeGreaterThan(5);
        expect(nomi.filter((n) => !sorgenti.includes(n))).toEqual([]);
    });

    test('spiega la strada dei passi, dalla preparazione, con i proposti che aspettano; non parla più di card né di schede della pagina', async () => {
        const t = await apri();
        for (const frase of ['cerchio verde', 'lucchetto', '«Che cosa manca»', '«Avanti»', '«Diario» nel menu «Azioni» lo apre in un cassetto a destra', '«Documenti»', 'già in «Prepara l\'assemblea»',
            'punti proposti', '«Vedili»', '«Oppure scrivi un punto nuovo»', 'Passo 3 di 6', 'una strada di sei passi', '«Delibere eseguite»',
            'si apre con il verbale registrato', 'il riepilogo di che cosa cambia nel gestionale']) {
            expect(t, frase).toContain(frase);
        }
        expect(t).not.toMatch(/\bcard\b(?! «Per la prossima assemblea»)/);
        expect(t).not.toMatch(/\bschede «/);
        expect(t).not.toMatch(/\bla scheda «(Convocazione|Fascicolo|Diario|Date)/);
    });

    test('l\'intestazione: il menu «Azioni» con «Diario», «Fascicolo», «Apri la sala», «Tenuta su carta»; «Assemblee» torna all\'elenco', async () => {
        const t = await apri();
        for (const frase of [
            'il menu «Azioni» e «Assemblee», che torna all\'elenco delle assemblee del condominio',
            '«Fascicolo» nel menu «Azioni» compone il fascicolo',
            '«Apri la sala» sta nel menu «Azioni»: spenta, dice sotto la voce perché',
            '«Tenuta su carta» nel menu «Azioni» registra il giorno e gli esiti dal verbale di carta',
            'Prende il posto della storia della scheda',
        ]) {
            expect(t, frase).toContain(frase);
        }
        expect(t).not.toContain('Tenuta fuori dal programma');
        expect(t).not.toContain('in alto a destra della strada');
    });

    test('i punti si scrivono già in «Prepara l\'assemblea»: la finestra di «Aggiungi un punto», «Scritti da te», prima di «Varie ed eventuali»', async () => {
        const t = await apri();
        for (const frase of [
            'I tuoi punti li scrivi già in «Prepara l\'assemblea»',
            'apre la stessa finestra della pagina dell\'assemblea',
            'nasce con l\'assemblea, quando premi «Prepara l\'assemblea»',
            'stanno in «Scritti da te», con «Modifica» e «Togli»',
            'li vedi insieme ai proposti spuntati',
            'con le frecce «Sposta su» e «Sposta giù» cambi l\'ordine',
            '«Varie ed eventuali» resta in fondo',
            'l\'assemblea non nasce finché non lo correggi',
        ]) {
            expect(t, frase).toContain(frase);
        }
        expect(t).not.toContain('li aggiungi nella pagina dell\'assemblea');
    });

    test('i cinque giorni dell\'art. 66 si dicono già sotto le date, senza bloccare, con il lavoro urgente', async () => {
        const t = await apri();
        for (const frase of ['troppo vicina per i cinque giorni dell\'avviso (art. 66 c. 3)', 'la raccomandata e per la PEC o la consegna a mano, contati da oggi',
            'Non blocca: puoi convocare lo stesso', 'riferire alla prima assemblea (art. 1135 c. 2)']) {
            expect(t, frase).toContain(frase);
        }
    });

    test('dopo «Prepara l\'assemblea» si apre «Documenti» se il primo passo è fatto, altrimenti «Date e ordine del giorno»', async () => {
        const t = await apri();
        expect(t).toContain('se il primo passo è fatto (le date valgono e c\'è almeno un punto), la pagina dell\'assemblea apre «Documenti»');
        expect(t).toContain('se manca qualcosa resta su «Date e ordine del giorno»');
        expect(t).not.toContain('la pagina dell\'assemblea apre «Date e ordine del giorno», e gli altri');
    });

    test('dice la regola di «spedito», la tabella che avvisa e non blocca, il perché dei passi che vengono dopo, i proposti nella voce dell\'ordine del giorno', async () => {
        const t = await apri();
        for (const frase of [
            'la tabella che non copre tutte le unità e il luogo che manca avvisano e non bloccano',
            'La stampa delle lettere non spedisce e non blocca niente',
            'alla prima spedizione registrata: una raccomandata registrata spedita, una PEC partita, una consegna a mano registrata',
            'si chiude quando tutti i convocati hanno il loro avviso spedito',
            'Il cerchio grigio con il numero è un passo che viene dopo',
            '«Che cosa manca» dice che cosa serve per andare avanti, una voce per riquadro, ognuna con il pulsante in fondo',
            'arancio da fare o da rifare',
            '«Vedi le deleghe» le apre in un cassetto a destra, senza lasciare la pagina',
            'la voce «L\'ordine del giorno» li conta',
            'si aprono quando il primo passo è fatto',
            'chi non ha un mezzo valido tiene il passo aperto',
        ]) {
            expect(t, frase).toContain(frase);
        }
        expect(t).not.toContain('e la tabella copre tutte le unità');
        expect(t).not.toContain('ognuna con il suo collegamento');
    });

    test('il passo «In sala»: si apre dalla prima spedizione con le deleghe ricevute e il foglio; «Apri la sala» dal giorno della prima convocazione', async () => {
        const t = await apri();
        expect(t).not.toContain('La sala si apre il giorno della prima convocazione; il verbale');
        for (const frase of [
            'Il passo «In sala» si apre dalla prima spedizione registrata',
            '«Segna una delega ricevuta»',
            'il quinto si conta mentre la segni',
            '«Dal portale dei condòmini»',
            '«Stampa il foglio», nella sezione «Il foglio della sala»',
            '«Apri la sala» si accende il giorno della prima convocazione, anche se la convocazione non è completa',
            '«Segna deserta la prima convocazione»',
            '«Registra dal foglio della sala»',
            '«Le presenze e i voti»',
        ]) {
            expect(t, frase).toContain(frase);
        }
    });
});
