// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida dei passi «Convocazione» e «Documenti» della pagina dell'assemblea.
 *
 * - Aperta mostra le sue parti; chiusa non c'è. Dice che i cinque giorni si contano dalla ricezione, che per la raccomandata non ritirata conta l'avviso
 *   di giacenza, che l'email semplice non vale, che la prova non si toglie, e che i fornitori della posta e della PEC sono responsabili del trattamento.
 * - Ogni frase porta, accanto nel sorgente, la riga di codice che la prova (`<!-- prova: file:riga «testo» -->`), con il numero della riga: la riga c'è e
 *   contiene quel testo.
 * - Ogni nome fra virgolette basse c'è nei componenti dei due passi, nei passi che scrive il server e nel modulo della persona (commenti esclusi).
 * - Dice «passo» dove diceva «scheda» («In testa al passo», «la riga del passo «Convocazione»»): la scheda resta solo quella della persona.
 *
 * Che cosa non copre: le frasi che arrivano dal server (le prova il server). Il pulsante «Guida» della pagina che la apre lo prova
 * `AssembleaConLaConvocazione.test.ts`.
 */
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const senzaCommenti = (s: string) => s.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/(^|[^:'"`])\/\/.*$/gm, '$1 ');
const GUIDA = 'resources/js/components/guides/ConvocazioneGuide.vue';
const COMPONENTI = [
    'resources/js/components/assemblee/convocazione/SchedaConvocazione.vue',
    'resources/js/components/assemblee/convocazione/EntroQuandoSpedire.vue',
    'resources/js/components/assemblee/convocazione/TabellaDeiConvocati.vue',
    'resources/js/components/assemblee/convocazione/RegistraLaSpedizione.vue',
    'resources/js/components/assemblee/convocazione/RegistraLaConsegna.vue',
    'resources/js/components/assemblee/convocazione/FoglioDelleConsegne.vue',
    'resources/js/components/assemblee/convocazione/AvvisoInBacheca.vue',
    'resources/js/components/assemblee/convocazione/AnnullaERiconvoca.vue',
    'resources/js/components/assemblee/convocazione/ComeSiConvoca.vue',
    'resources/js/components/assemblee/fascicolo/SchedaFascicolo.vue',
    'resources/js/components/assemblee/fascicolo/FinestraDocumentiDelPunto.vue',
    'resources/js/pages/gestionale/assemblee/Assemblea.vue',
    'resources/js/components/assemblee/StradaDellAssemblea.vue',
    'app/Services/Assemblee/PassiDellAssemblea.php',
    // Le parole dello stato di ogni persona («Non più convocato») le scrive il server.
    'app/Services/Assemblee/Convocazione/StatoDellaConvocazione.php',
    // «Torna alla proposta» lo scrive il server, con il giorno della proposta.
    'app/Services/Assemblee/Convocazione/TerminiDellaConvocazione.php',
];

describe('la guida della convocazione e del fascicolo', () => {
    let w: VueWrapper | null = null;
    afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });
    async function apri(open = true) {
        const Guida = (await import('./ConvocazioneGuide.vue')).default;
        w = mount(Guida, { attachTo: document.body, props: { open } });
        await flushPromises();

        return (document.body.textContent ?? '').replace(/\s+/g, ' ');
    }

    test('aperta mostra le sue parti e dice le regole che contano; chiusa non c\'è', async () => {
        const t = await apri();
        for (const parte of ['Entro quando spedire', 'Chi si convoca, e con quale mezzo', 'Le lettere e le PEC', 'La consegna e la prova',
            'La bacheca e la riconvocazione', 'Il fascicolo', 'La privacy']) expect(t).toContain(parte);
        expect(t).toContain('almeno cinque giorni prima della prima convocazione, contati dalla ricezione');
        expect(t).toContain('conta l\'avviso di giacenza');
        expect(t).toContain('L\'email semplice non vale come convocazione');
        expect(t).toContain('non si toglie');
        expect(t).toContain('sono responsabili del trattamento (art. 28 del GDPR)');
        // Le cose nuove della 2b: la ricevuta PEC letta dal file, il ritiro, gli esiti del mezzo, la bacheca senza la frase dell'avviso.
        expect(t).toContain('il programma legge il giorno e l\'ora della consegna e la archivia sulla riga');
        expect(t).toContain('«Registra il ritiro»');
        expect(t).toContain('per la PEC consegnata o non consegnata');
        expect(t).toContain('mai la frase dell\'avviso');
        expect(t).not.toContain('Cambia nelle impostazioni');
        w!.unmount();
        document.body.innerHTML = '';
        await apri(false);
        expect(document.body.querySelector('[data-testid="guida-convocazione"]')).toBeNull();
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

    test('ogni nome fra virgolette basse c\'è nei componenti dei due passi, nei passi del server e nel modulo della persona', async () => {
        const t = await apri();
        const sorgenti = COMPONENTI.map((f) => senzaCommenti(leggi(f))).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');
        const nomi = [...new Set([...t.matchAll(/«([^«»]+)»/g)].map((m) => m[1]))].filter((n) => !n.endsWith('.'));
        expect(nomi.length).toBeGreaterThan(10);
        expect(nomi.filter((n) => !sorgenti.includes(n))).toEqual([]);
    });

    test('dice «passo» dove diceva «scheda»: la scheda resta quella della persona', async () => {
        const t = await apri();
        expect(t).toContain('In testa al passo «Convocazione», «Entro quando spedire»');
        expect(t).toContain('la riga del passo «Convocazione» seguono la tua data');
        expect(t).not.toContain('card dei termini');
        expect(t.match(/\bscheda\b[^.]*/g) ?? []).toEqual(['scheda della persona, in «Come si convoca»; se lo lasci vuoto, il programma lo propone dai dati: la PEC se c\'è, altrimenti la raccomandata all\'indirizzo']);
    });

    test('dice la regola di «spedito», le lettere da ristampare, un pulsante solo per la carta, la tabella che avvisa e non ferma la convocazione', async () => {
        const t = await apri();
        for (const frase of [
            'La stampa non spedisce e non blocca niente',
            'il passo dice di ristamparle',
            'Un pulsante solo per tutte le lettere su carta, raccomandate e a mano: il foglio delle firme delle consegne a mano resta a parte',
            'alla prima spedizione registrata (una raccomandata registrata spedita, una PEC partita, una consegna a mano registrata)',
            'si chiude quando tutti i convocati hanno il loro avviso spedito',
            'La tabella del valore che non copre tutte le unità non ferma la convocazione',
            'chi non ha un mezzo valido',
        ]) {
            expect(t, frase).toContain(frase);
        }
    });
});

describe('il passo senza spazi vuoti e il promemoria per email', () => {
    let w: VueWrapper | null = null;
    afterEach(() => { w?.unmount(); w = null; document.body.innerHTML = ''; });

    test('con la prima convocazione troppo vicina la riga, la strada e il compito dicono oggi, fuori dai cinque giorni, e il compito non è scaduto (decisione 665)', async () => {
        const Guida = (await import('./ConvocazioneGuide.vue')).default;
        w = mount(Guida, { attachTo: document.body, props: { open: true } });
        await flushPromises();
        const t = (document.body.textContent ?? '').replace(/\s+/g, ' ');
        expect(t).toContain('la riga di ogni persona, la strada dei passi e il compito dell\'Action inbox dicono oggi');
        expect(t).toContain('il compito non è scaduto e dice che è fuori dai cinque giorni dell\'art. 66');
    });

    test('dice «Come conto» sulla data che vale, il giorno passato che non si sceglie, il promemoria per email, le «i» al tocco, «Stampa la prova»', async () => {
        const Guida = (await import('./ConvocazioneGuide.vue')).default;
        w = mount(Guida, { attachTo: document.body, props: { open: true } });
        await flushPromises();
        const t = (document.body.textContent ?? '').replace(/\s+/g, ' ');
        for (const frase of [
            '«Come conto» si rifà sulla tua data, e sotto c\'è «Che cosa segue la data che scegli»',
            'e accanto si legge «scelta da te», con «Torna alla proposta»',
            'Un giorno già passato non si sceglie: nel calendario è spento, e il programma lo rifiuta con la sua frase, rossa sotto il campo.',
            'Con una data che arriva dopo il termine «Come conto» è arancio',
            'Al telefono «Come conto» sta nel cassetto della «i»',
            'a chi ha preparato l\'assemblea arriva un\'email due giorni lavorativi prima del giorno limite del compito e la mattina di quel giorno, se restano avvisi da spedire',
            'si spegne nelle impostazioni della posta',
            'compare subito al passaggio del mouse, e al tocco sul telefono',
            'la riga «Giorni di posta dello studio» li dice sotto il campo, e «Cambia» porta all\'impostazione',
            '«Stampa la prova», nella card «La prova della convocazione»',
        ]) {
            expect(t, frase).toContain(frase);
        }
        expect(t).not.toContain('«Stampa la prova della convocazione»');
    });
});

