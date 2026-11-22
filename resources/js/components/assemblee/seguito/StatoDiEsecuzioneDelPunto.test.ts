// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
// @vitest-environment jsdom

/**
 * Il punto «Stato di esecuzione delle delibere precedenti» (scena 11 della bozza della tappa), il 28/04/2027 preparando l'ordinaria di maggio di Via Roma 12:
 *
 * - due gruppi: prima le aperte di sempre (la facciata della straordinaria del 22/10/2026, con ciò che resta da fare), poi le eseguite dall'ultima ordinaria;
 * - l'elenco fermo al giorno in cui si prepara: l'avviso e il verbale portano le stesse righe;
 * - le righe sono quelle del server, con la frase pubblica del punto: nessun nome di persona;
 * - l'ordine del giorno lo mostra sotto il punto, e sotto la proposta.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';
import { mount } from '@vue/test-utils';
import StatoDiEsecuzioneDelPunto from './StatoDiEsecuzioneDelPunto.vue';

const elenco = {
    al: '2027-04-28', ultima_ordinaria: '2026-12-15',
    aperte: [{ delibera_id: 1, assemblea_id: 2, assemblea: 'Assemblea straordinaria del 22/10/2026', deliberata_il: '2026-10-22', punto: 1,
        testo: 'Rifacimento della facciata', eseguita_il: null, cose: [{ testo: 'Crea il piano straordinario', stato: 'da_fare', fatta_il: null, entro_il: '2027-05-15' }] }],
    eseguite: [
        { delibera_id: 5, assemblea_id: 9, assemblea: 'Assemblea ordinaria del 15/12/2026', deliberata_il: '2026-12-15', punto: 5, testo: 'Orari del riscaldamento',
            eseguita_il: '2026-12-17', cose: [{ testo: 'Avvisa i condòmini', stato: 'fatta', fatta_il: '2026-12-17', entro_il: null }] },
        { delibera_id: 4, assemblea_id: 9, assemblea: 'Assemblea ordinaria del 15/12/2026', deliberata_il: '2026-12-15', punto: 4, testo: 'Sostituzione del portone',
            eseguita_il: '2027-01-12', cose: [] },
    ],
};

describe('lo stato di esecuzione nel punto', () => {
    test('due gruppi, le aperte prima; ciò che resta da fare; le eseguite con il giorno; l\'elenco fermo al 28/04/2027', () => {
        const w = mount(StatoDiEsecuzioneDelPunto, { props: { elenco } });
        const gruppi = w.findAll('[data-testid="gruppo-dello-stato"]');
        expect(gruppi).toHaveLength(2);
        expect(gruppi[0].text().replace(/\s+/g, ' ')).toContain('Ancora da eseguire · 1');
        expect(gruppi[0].text().replace(/\s+/g, ' ')).toContain('Assemblea straordinaria del 22/10/2026, punto 1 · Rifacimento della facciata: crea il piano straordinario, entro il 15/05/2027');
        expect(gruppi[1].text().replace(/\s+/g, ' ')).toContain('Eseguite dall\'ultima ordinaria, il 15/12/2026 · 2');
        expect(gruppi[1].text().replace(/\s+/g, ' ')).toContain('Assemblea ordinaria del 15/12/2026, punto 5 · Orari del riscaldamento: eseguita il 17/12/2026');
        expect(w.get('[data-testid="fermo-al"]').text()).toContain('L\'elenco si ferma al 28/04/2027: l\'avviso e il verbale portano le stesse righe.');
    });

    test('senza aperte resta solo il gruppo delle eseguite', () => {
        const w = mount(StatoDiEsecuzioneDelPunto, { props: { elenco: { ...elenco, aperte: [] } } });
        expect(w.findAll('[data-testid="gruppo-dello-stato"]')).toHaveLength(1);
    });

    test('l\'ordine del giorno lo mostra sotto il punto e sotto la proposta', () => {
        const s = readFileSync(resolve(process.cwd(), 'resources/js/components/assemblee/OrdineDelGiorno.vue'), 'utf8');
        expect(s).toContain('<StatoDiEsecuzioneDelPunto v-if="p.dati?.stato_esecuzione" :elenco="p.dati.stato_esecuzione"');
        expect(s).toContain(':stampa="p.dati.stampa_stato ?? null"');
        expect(s.match(/<StatoDiEsecuzioneDelPunto /g)).toHaveLength(2);
    });
});
