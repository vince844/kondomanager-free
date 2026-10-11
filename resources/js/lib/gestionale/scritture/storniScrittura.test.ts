import { describe, expect, it } from 'vitest';
import { eStornoDelPadre, figlieCheStornano, stornaIlPadre, STORNI_DI_SCRITTURA } from './storniScrittura';

/**
 * 1.11.0-beta.49 (P4 e P9 del verbale; sonda S1 dello scettico del comando, mancanza 3 del critico): la pagina della scrittura
 * (`resources/js/pages/gestionale/movimenti/scritture/Show.vue`) decide i due riquadri nel template:
 * - «Attenzione: movimento annullato» con `v-if="scrittura.figlie?.length > 0"` (:299): ogni figlia è uno storno;
 * - «Movimento di storno … inverte e annulla» con `v-if="scrittura.padre"` (:279): ogni scrittura con un padre è uno storno.
 *
 * Sono falsi per tre figlie che non annullano niente: la compensazione di un incasso valido (`storno_credito`, figlia dell'incasso
 * da sempre, StoreIncassoRateAction), la rettifica del riallineamento dei crediti (figlia della compensazione, contrasto 3 del
 * critico) e la scrittura che lo storno B fa figlia dell'emissione (`emissione_rata`). La regola si sposta in
 * `resources/js/lib/gestionale/scritture/storniScrittura.ts`, con il criterio del registro di contabilità
 * (`RegistroContabilitaService::stornataSql`, :509-545): una figlia annulla il padre se è uno storno di scrittura
 * (`TipoMovimentoContabile::isStornoDiScrittura()`: il padre resta «registrata»), oppure se è la rettifica viva di un padre
 * «annullata» (lo storno di un incasso sigilla l'originale e tutta la sua famiglia, la rettifica del riallineamento compresa).
 * Il controller della pagina passa `tipo_movimento` e `stato` di padre e figlie (test PHP R23 di RiallineaCreditiTest).
 */

const scrittura = (id: number, tipo_movimento: string, stato = 'registrata') => ({ id, tipo_movimento, stato });

describe('figlieCheStornano — le figlie che il riquadro «movimento annullato» elenca', () => {
    it('S1: la compensazione con la rettifica del riallineamento come figlia non è annullata', () => {
        const compensazione = { ...scrittura(5, 'storno_credito'), figlie: [scrittura(6, 'rettifica')] };
        expect(figlieCheStornano(compensazione)).toEqual([]);
    });

    it('un incasso valido con la sua compensazione come figlia non è annullato (oggi il riquadro lo dice)', () => {
        const incasso = { ...scrittura(4, 'incasso_rata'), figlie: [scrittura(5, 'storno_credito')] };
        expect(figlieCheStornano(incasso)).toEqual([]);
    });

    it('un incasso stornato: lo annulla la sua rettifica, non la compensazione (sigillata anche lei)', () => {
        const incasso = { ...scrittura(4, 'incasso_rata', 'annullata'), figlie: [scrittura(5, 'storno_credito', 'annullata'), scrittura(7, 'rettifica')] };
        expect(figlieCheStornano(incasso).map((f) => f.id)).toEqual([7]);
    });

    it('R7: la compensazione stornata dopo il riallineamento; la annulla il suo specchio, non la rettifica del riallineamento (sigillata con lei)', () => {
        const compensazione = { ...scrittura(5, 'storno_credito', 'annullata'), figlie: [scrittura(6, 'rettifica', 'annullata'), scrittura(8, 'rettifica')] };
        expect(figlieCheStornano(compensazione).map((f) => f.id)).toEqual([8]);
    });

    it('un giroconto stornato resta «registrata»: lo annulla la figlia di tipo storno', () => {
        const giroconto = { ...scrittura(10, 'giroconto'), figlie: [scrittura(11, 'storno_giroconto')] };
        expect(figlieCheStornano(giroconto).map((f) => f.id)).toEqual([11]);
    });

    it('un\'emissione con la figlia dello storno B (credito restituito fra i crediti) non è annullata', () => {
        const emissione = { ...scrittura(3, 'emissione_rata'), figlie: [scrittura(9, 'emissione_rata')] };
        expect(figlieCheStornano(emissione)).toEqual([]);
    });

    it('un giroconto con una rettifica del riallineamento dei fondi (RiallineaFondiService) non è annullato', () => {
        const giroconto = { ...scrittura(10, 'giroconto'), figlie: [scrittura(12, 'rettifica')] };
        expect(figlieCheStornano(giroconto)).toEqual([]);
    });

    it('senza figlie: niente', () => {
        expect(figlieCheStornano({ ...scrittura(1, 'incasso_rata'), figlie: [] })).toEqual([]);
    });
});

describe('eStornoDelPadre — il riquadro «Movimento di storno» della figlia', () => {
    it('S1: la rettifica del riallineamento non inverte la compensazione, che resta valida', () => {
        expect(eStornoDelPadre({ ...scrittura(6, 'rettifica'), padre: scrittura(5, 'storno_credito') })).toBe(false);
    });

    it('la compensazione non è lo storno del suo incasso (oggi il riquadro lo dice)', () => {
        expect(eStornoDelPadre({ ...scrittura(5, 'storno_credito'), padre: scrittura(4, 'incasso_rata') })).toBe(false);
    });

    it('la rettifica di un incasso stornato è il suo storno', () => {
        expect(eStornoDelPadre({ ...scrittura(7, 'rettifica'), padre: scrittura(4, 'incasso_rata', 'annullata') })).toBe(true);
    });

    it('lo storno di un giroconto è il suo storno, anche con il padre «registrata»', () => {
        expect(eStornoDelPadre({ ...scrittura(11, 'storno_giroconto'), padre: scrittura(10, 'giroconto') })).toBe(true);
    });

    it('la scrittura dello storno B, figlia dell\'emissione, non la inverte', () => {
        expect(eStornoDelPadre({ ...scrittura(9, 'emissione_rata'), padre: scrittura(3, 'emissione_rata') })).toBe(false);
    });

    it('senza padre: no', () => {
        expect(eStornoDelPadre({ ...scrittura(1, 'rettifica'), padre: null })).toBe(false);
    });

    it('la rettifica del riallineamento sigillata da uno storno non è lei lo storno della compensazione', () => {
        expect(stornaIlPadre(scrittura(6, 'rettifica', 'annullata'), scrittura(5, 'storno_credito', 'annullata'))).toBe(false);
    });
});

describe('STORNI_DI_SCRITTURA — lo stesso elenco di TipoMovimentoContabile::isStornoDiScrittura()', () => {
    it('cinque tipi, nessuno di quelli che non annullano', () => {
        expect([...STORNI_DI_SCRITTURA].sort()).toEqual(['storno_fattura', 'storno_giroconto', 'storno_pagamento_f24', 'storno_pagamento_fornitore', 'storno_regolazione_immediata']);
        expect(STORNI_DI_SCRITTURA).not.toContain('storno_credito');
        expect(STORNI_DI_SCRITTURA).not.toContain('rettifica');
    });
});
