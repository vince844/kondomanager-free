// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I dati del riquadro «Ratifica dell'assemblea» della fattura (decisione 642): uno per riga, con l'etichetta sopra; il punto proposto senza il motivo, che ha il
 * suo dato; la pagina li disegna senza ricomporre le frasi.
 *
 * Che cosa non copre: come la pagina li impagina (le prove sul testo di `FatturaShow.vue`, e la prova a video).
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';
import { datiDellaRatifica } from './ratifica';

const sforoVuoto = { oltre: null, voce: null };
const sbloccata = { il: '21/11/2026', da: 'Vincenzo Rossi', motivo: 'Perdita dalla colonna montante', pagata: false, parola: 'Sbloccata' };

describe('i dati della fattura sbloccata per l\'urgenza', () => {
    test('quattro dati, uno per riga: Sbloccata, Nell\'assemblea, Perché era urgente, Il punto proposto', () => {
        const dati = datiDellaRatifica({
            sbloccata, sforo: sforoVuoto,
            in_assemblea: { frase: 'Proposta fra i punti…', testo: 'Comunicazione dei lavori urgenti e ratifica della spesa di € 1.708,00', proposta: true, numero: null, il: null, assemblea_id: null },
        });
        expect(dati.map((d) => d.etichetta)).toEqual(['Sbloccata', 'Nell\'assemblea', 'Perché era urgente', 'Il punto proposto']);
        expect(dati[0].valore).toBe('il 21/11/2026, da Vincenzo Rossi');
        expect(dati[1].valore).toBe('Proposta fra i punti della prossima assemblea');
        expect(dati[1].assemblea_id).toBeNull();
        expect(dati[2].valore).toBe('«Perdita dalla colonna montante»');
        // Il punto senza il motivo: il motivo non si ripete, e il testo non lo porta incollato.
        expect(dati[3].valore).toBe('Comunicazione dei lavori urgenti e ratifica della spesa di € 1.708,00');
        expect(dati[3].valore).not.toContain('Perché era urgente');
    });

    test('con il punto in un\'assemblea: «Punto 2 dell\'assemblea del 23/11/2026» e il rimando per aprirla', () => {
        const dati = datiDellaRatifica({
            sbloccata, sforo: sforoVuoto,
            in_assemblea: { frase: '…', testo: 'Ratifica', proposta: false, numero: 2, il: '23/11/2026', assemblea_id: 7 },
        });
        const dove = dati.find((d) => d.id === 'nell-assemblea')!;
        expect(dove.valore).toBe('Punto 2 dell\'assemblea del 23/11/2026');
        expect(dove.assemblea_id).toBe(7);
    });
});

describe('i dati della fattura solo in sforo', () => {
    test('«La motivazione» al posto di «Perché era urgente», e niente «Sbloccata»', () => {
        const dati = datiDellaRatifica({
            sbloccata: null, sforo: { oltre: 30000, voce: 'Ascensore', motivazione: 'Rottura imprevista' },
            in_assemblea: { frase: '…', testo: 'Ratifica della spesa oltre il preventivo', proposta: true, numero: null, il: null, assemblea_id: null },
        });
        expect(dati.map((d) => d.etichetta)).toEqual(['Nell\'assemblea', 'La motivazione', 'Il punto proposto']);
        expect(dati[1].valore).toBe('«Rottura imprevista»');
    });

    test('quello che non c\'è non si scrive', () => {
        expect(datiDellaRatifica({ sbloccata: null, sforo: sforoVuoto, in_assemblea: null })).toEqual([]);
    });
});

describe('il riquadro nella pagina', () => {
    const pagina = readFileSync(resolve(process.cwd(), 'resources/js/pages/gestionale/movimenti/fatture/FatturaShow.vue'), 'utf8');
    const ramo = pagina.slice(pagina.indexOf('data-testid="da-ratificare"'), pagina.indexOf('<PagaPerLUrgenza'));

    test('i badge nella testa, la frase breve, poi i dati con l\'etichetta sopra', () => {
        expect(pagina).toContain('data-testid="badge-sbloccata"');
        expect(pagina).toContain("{{ parolaDellSblocco }} per l'urgenza</span>");
        expect(ramo).toContain('La paghi adesso; la riferisci alla prima assemblea e la ratifica arriva con la sua delibera.');
        expect(ramo).toContain("Serve una decisione dell'assemblea: la spesa supera il preventivo");
        expect(ramo).toContain('<dt class="text-xs text-slate-500 dark:text-slate-400">');
        expect(ramo).toContain('<dd class="text-sm text-slate-800 dark:text-slate-200">');
    });

    test('il motivo non è più scritto due volte né il punto è tagliato nel componente', () => {
        expect(ramo).not.toContain('Perché era urgente');
        expect(ramo).not.toContain('in_assemblea.frase');
        expect(ramo).not.toContain('charAt(0)');
    });
});
