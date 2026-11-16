// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * La guida dell'estratto conto di una persona, nella finestra di lato.
 *
 * - Aperta, mostra le sue parti: il menu «Azioni», le sei card, gli addebiti personali con le tre porte e il libro, quando un addebito non si annulla,
 *   il credito, gli accordi, i versamenti per conto di altri, chi risponde in solido, le lettere, la tabella con la legenda, la stampa; chiusa non c'è.
 * - Dice le cose che la pagina fa oggi: «Spese del ritardo» (non più il nome di prima), le sei card con «Rate emesse e addebiti», le tre porte, i perché
 *   di un addebito che non si annulla.
 * - Ogni nome tra virgolette basse che la guida cita (un pulsante, una card, una scheda, un conto, un bollino) c'è davvero nei sorgenti della pagina, dei
 *   suoi pezzi, delle altre due porte e dei servizi che scrivono quei nomi. «N» sta per il numero che la pagina scrive.
 * - Le parole che il programma non usa non ci sono.
 *
 * Che cosa NON copre: che ogni frase sia vera sul server; le frasi sono state controllate una per una sul codice quando la guida è stata scritta.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, test } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';

(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };

const Guida = (await import('./EstrattoContoGuide.vue')).default;

const leggi = (file: string) => readFileSync(resolve(process.cwd(), file), 'utf8');
const sorgenteGuida = leggi('resources/js/components/guides/EstrattoContoGuide.vue');
// Nei sorgenti l'apostrofo dentro le stringhe è `\'`: si legge com'è a video.
const sorgentiPagina = [
    'resources/js/pages/gestionale/pianiRate/EstrattoContoAnagrafica.vue',
    'resources/js/lib/gestionale/pianiRate/statoRigaEstratto.ts',
    'resources/js/components/gestionale/addebiti/AddebitiDellaPersona.vue',
    'resources/js/components/gestionale/addebiti/NuovoAddebitoDialog.vue',
    'resources/js/components/gestionale/addebiti/AddebitaSpesaDialog.vue',
    'resources/js/lib/gestionale/addebiti/addebiti.ts',
    'resources/js/components/gestionale/accordi/AccordiDellaPersona.vue',
    'resources/js/components/gestionale/fascicolo/FascicoliDellaPersona.vue',
    'resources/js/components/gestionale/fascicolo/FascicoloLegaleDialog.vue',
    'resources/js/lib/gestionale/debiti/accordiRientro.ts',
    'resources/js/components/gestionale/movimenti/incassi/RicevutaDialog.vue',
    'resources/js/pages/gestionale/movimenti/rateScadute/RateScadute.vue',
    'resources/js/pages/gestionale/pianiRate/PianiRateShow.vue',
    'resources/js/pages/gestionale/movimenti/rendiconto/Conguaglio.vue',
    'resources/js/components/gestionale/movimenti/rendiconto/RendicontoSchede.vue',
    'app/Services/CondominioService.php',
    'app/Services/Gestionale/Accessori/AccessoriDaIncassare.php',
    'app/Services/Gestionale/Addebiti/AddebitiIndividuali.php',
].map(leggi).join('\n').replace(/\\'/g, '\'').replace(/\s+/g, ' ');

let w: VueWrapper | null = null;
afterEach(() => {
    w?.unmount();
    w = null;
    document.body.innerHTML = '';
});

describe('la guida dell\'estratto conto', () => {
    test('aperta mostra le sue parti, chiusa non c\'è', async () => {
        w = mount(Guida, { attachTo: document.body, props: { open: false } });
        await flushPromises();
        expect(document.body.querySelector('[data-testid="guida-estratto-conto"]')).toBeNull();

        await w.setProps({ open: true });
        await flushPromises();
        const guida = document.body.querySelector('[data-testid="guida-estratto-conto"]');
        expect(guida).not.toBeNull();
        expect([...guida!.querySelectorAll('h3')].map((h) => (h.textContent ?? '').trim())).toEqual([
            'Il menu «Azioni»', 'Le sei card', 'Gli addebiti personali', 'Quando un addebito non si annulla', 'Il credito: «Compensa» e «Rimborsa il credito»',
            'Gli accordi di rientro', 'I versamenti per conto di altri', 'Chi risponde in solido', 'Le lettere e la corrispondenza', 'La tabella dei movimenti',
            'La stampa',
        ]);
        expect([...guida!.querySelectorAll('h4')].map((h) => (h.textContent ?? '').trim())).toEqual(['Le tre porte di «Nuovo addebito»', 'Nel libro dei movimenti']);
    });

    test('dice le sei card, i quattro addebiti, le tre porte, i perché di un addebito che resta, con il nome nuovo delle spese', async () => {
        w = mount(Guida, { attachTo: document.body, props: { open: true } });
        await flushPromises();
        const testo = (document.body.querySelector('[data-testid="guida-estratto-conto"]')!.textContent ?? '').replace(/\s+/g, ' ');
        for (const card of ['Saldo iniziale.', 'Totale addebiti.', 'Totale versato.', 'Saldo finale.', 'Credito disponibile.', 'Addebiti personali.']) {
            expect(testo).toContain(card);
        }
        expect(testo).toContain('per questo la card di «Totale addebiti» dice «Rate emesse e addebiti»');
        for (const tipo of ['Il contributo.', 'La spesa di una sola unità già fatturata.', 'Le spese del ritardo.', 'Gli interessi di mora.']) {
            expect(testo).toContain(tipo);
        }
        expect(testo).toContain('Questa pagina, per un contributo o una spesa già fatturata');
        expect(testo).toContain('in «Da addebitare a una persona», «Addebita a una persona»');
        expect(testo).toContain('La scheda «Conguaglio» del rendiconto, in «Le uscite che nessuno paga»');
        expect(testo).toContain('Con un incasso, o un credito usato.');
        expect(testo).toContain('In un accordo di rientro, in corso o chiuso, che lo riconosce');
        expect(testo).toContain('Con l\'esercizio chiuso.');
        expect(testo).toContain('«Spese del ritardo»');
        expect(testo).not.toContain('Addebita una spesa');
    });

    test('ogni nome tra virgolette basse c\'è nella pagina, nei suoi pezzi o nei servizi che lo scrivono', () => {
        const citati = [...new Set([...sorgenteGuida.matchAll(/«([^»]+)»/g)].map((m) => m[1].replace(/\s+/g, ' ')))];
        expect(citati.length).toBeGreaterThan(40);
        // «N» è il numero che la pagina scrive con `{{ … }}`.
        const nellaPagina = (nome: string) => new RegExp(nome.split(/\bN\b/).map((p) => p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
            .join('(?:\\{\\{[^}]+\\}\\}|\\$\\{[^}]+\\})')).test(sorgentiPagina);
        expect(citati.filter((nome) => !nellaPagina(nome))).toEqual([]);
    });

    test('nessuna parola che il programma non usa, e nessuna etichetta a pillola', () => {
        const testo = sorgenteGuida.replace(/<!--[\s\S]*?-->/g, ' ').replace(/\/\*[\s\S]*?\*\//g, ' ').toLowerCase();
        for (const parola of ['dovut', 'esigibil', 'pronto', 'pronta', 'conforme', 'regolare', 'fisiologic', 'consegnat', 'recapitat', 'notificat', 'garant']) {
            expect(testo, parola).not.toContain(parola);
        }
        expect(sorgenteGuida).not.toContain('rounded-full');
    });
});
