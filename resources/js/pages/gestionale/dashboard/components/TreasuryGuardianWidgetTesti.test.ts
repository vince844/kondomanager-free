/**
 * Il widget «Tesoreria a 30gg»: i testi sui fondi vincolati dicono quello che il calcolo fa.
 *
 * Si legge il sorgente, come nei test delle guide: conta la frase, non il disegno. Il servizio toglie
 * i fondi dal disponibile (`TreasuryGuardianService::calcolaFondiVincolati`); l'avviso compare quando il
 * condominio ha un fondo riserva ma senza somme accantonate. Non deve dire che il calcolo «non distingue
 * ancora» i fondi, né rimandare a una funzione («Voci Accantonamento») che nel programma non esiste.
 */
import { describe, expect, test } from 'vitest';
import fs from 'node:fs';

const sorgente = fs.readFileSync('resources/js/pages/gestionale/dashboard/components/TreasuryGuardianWidget.vue', 'utf8').replace(/\s+/g, ' ');

describe('TreasuryGuardianWidget — l\'avviso sul fondo riserva senza somme accantonate', () => {
    test('non dice che il calcolo non distingue ancora i fondi vincolati', () => {
        expect(sorgente).not.toContain('non distingue ancora');
        expect(sorgente).toContain('Il fondo riserva non ha somme accantonate');
    });

    test('non rimanda a una funzione che non esiste', () => {
        expect(sorgente).not.toContain('Voci Accantonamento');
        expect(sorgente).not.toContain('sarà disponibile');
    });
});
