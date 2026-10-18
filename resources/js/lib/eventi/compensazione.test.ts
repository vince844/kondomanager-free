/**
 * Cosa resta scoperto: la regola lato server (la provano i test Pest dei promemoria); i valori in euro (qui centesimi, come il `meta`); il caso di una
 * rata non ancora emessa nella finestra (ha il suo test: `EventDetailsDialogBeta52.test.ts`).
 *
 * Lo stato «In attesa della compensazione» del promemoria (decisione 87.4).
 *
 * Il promemoria è in questo stato quando la persona non ha altro da versare (restante zero, o a credito) ma una sua quota aspetta
 * il credito di un'altra sulla stessa rata: `status` `partial`, `da_compensare` maggiore di zero, `importo_restante` zero o meno. La
 * regola la scrive `EventiRataCondomino::stato()`; qui la leggono insieme la finestra del condòmino e l'etichetta dell'elenco.
 *
 * Su una rata non ancora emessa la persona non ha niente da aspettare, ma può avere un netto zero con una quota da compensare (debito e credito
 * della stessa rata uguali): `compensazioneDopoLEmissione` lo dice, e la finestra e l'etichetta lo dicono con parole che non fanno credere né
 * un versamento né una compensazione già avvenuta.
 */

import { describe, expect, it } from 'vitest';
import { compensazioneDopoLEmissione, inAttesaDellaCompensazione } from '@/lib/eventi/compensazione';

describe('inAttesaDellaCompensazione', () => {
    it('K3 dopo € 40,00 su C: partial, restante zero, € 10,00 da compensare → sì', () => {
        expect(inAttesaDellaCompensazione({ status: 'partial', importo_restante: 0, da_compensare: 1000 })).toBe(true);
    });

    it('credito oltre il debito: partial, restante a credito (−€ 50,00), € 50,00 da compensare → sì', () => {
        expect(inAttesaDellaCompensazione({ status: 'partial', importo_restante: -5000, da_compensare: 5000 })).toBe(true);
    });

    it('pagato meno del netto: restano € 20,00 da versare → no, la persona deve ancora versare', () => {
        expect(inAttesaDellaCompensazione({ status: 'partial', importo_restante: 2000, da_compensare: 1000 })).toBe(false);
    });

    it('rata da versare con un credito sulla stessa rata, nulla pagato → no', () => {
        expect(inAttesaDellaCompensazione({ status: 'pending', importo_restante: 4000, da_compensare: 1000 })).toBe(false);
    });

    it('rata pagata, segnalata o rifiutata → no, qualunque sia da_compensare', () => {
        for (const status of ['paid', 'reported', 'rejected']) {
            expect(inAttesaDellaCompensazione({ status, importo_restante: 0, da_compensare: 1000 })).toBe(false);
        }
    });

    it('una rata non ancora emessa non aspetta niente dall\'amministratore: is_emitted falso → no, anche con il credito oltre il debito', () => {
        expect(inAttesaDellaCompensazione({ status: 'partial', importo_restante: -5000, da_compensare: 5000, is_emitted: false })).toBe(false);
        expect(inAttesaDellaCompensazione({ status: 'partial', importo_restante: -5000, da_compensare: 5000, is_emitted: true })).toBe(true);
    });

    it('un promemoria di prima, senza da_compensare, e un meta assente → no', () => {
        expect(inAttesaDellaCompensazione({ status: 'partial', importo_restante: 0 })).toBe(false);
        expect(inAttesaDellaCompensazione(undefined)).toBe(false);
        expect(inAttesaDellaCompensazione(null)).toBe(false);
    });
});

describe('compensazioneDopoLEmissione', () => {
    it('netto zero su una rata non ancora emessa, con € 50,00 da compensare → sì', () => {
        expect(compensazioneDopoLEmissione({ status: 'partial', importo_restante: 0, da_compensare: 5000, is_emitted: false })).toBe(true);
    });

    it('la stessa rata emessa → no: è «in attesa della compensazione»', () => {
        expect(compensazioneDopoLEmissione({ status: 'partial', importo_restante: 0, da_compensare: 5000, is_emitted: true })).toBe(false);
        expect(inAttesaDellaCompensazione({ status: 'partial', importo_restante: 0, da_compensare: 5000, is_emitted: true })).toBe(true);
    });

    it('un\'altra rata non emessa → no: con qualcosa da versare, con un credito oltre il debito, senza niente da compensare, o già pagata', () => {
        expect(compensazioneDopoLEmissione({ status: 'pending', importo_restante: 4000, da_compensare: 1000, is_emitted: false })).toBe(false);
        expect(compensazioneDopoLEmissione({ status: 'partial', importo_restante: -5000, da_compensare: 5000, is_emitted: false })).toBe(false);
        expect(compensazioneDopoLEmissione({ status: 'partial', importo_restante: 0, da_compensare: 0, is_emitted: false })).toBe(false);
        expect(compensazioneDopoLEmissione({ status: 'paid', importo_restante: 0, da_compensare: 5000, is_emitted: false })).toBe(false);
        expect(compensazioneDopoLEmissione({ status: 'reported', importo_restante: 0, da_compensare: 5000, is_emitted: false })).toBe(false);
    });

    it('un promemoria senza `is_emitted` (salvato prima) o senza meta → no', () => {
        expect(compensazioneDopoLEmissione({ status: 'partial', importo_restante: 0, da_compensare: 5000 })).toBe(false);
        expect(compensazioneDopoLEmissione(undefined)).toBe(false);
        expect(compensazioneDopoLEmissione(null)).toBe(false);
    });
});
