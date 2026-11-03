/**
 * Cosa resta scoperto: il colore e l'icona dell'etichetta (si guardano a video); la lunghezza dell'etichetta a 375 px nel badge della finestra e
 * nella riga dell'elenco; le etichette degli altri stati, che hanno i loro casi nei componenti.
 *
 * L'etichetta dell'elenco per lo stato «In attesa della compensazione» (decisione 87.4), e per i due casi vicini che direbbero il falso.
 *
 * Un credito puro già saldato non è «Pagato»: non c'è un pagamento, c'è un credito che resta a favore della persona, e l'etichetta dice «A credito»
 * come la descrizione accanto. Quando il credito è stato restituito per intero non resta niente da ricevere e l'etichetta resta «Pagato».
 *
 * Una rata non ancora emessa a netto zero (debito e credito della stessa rata uguali) non ha ricevuto nessun versamento e non ha ancora la
 * compensazione: l'etichetta è «Da compensare», e non «Pagato parz.».
 *
 * Senza il ramo nuovo un promemoria `partial` con restante zero porta «Pagato parz.» (il caso K3 dopo i € 40,00) e uno con restante a credito
 * porta «A credito» (credito oltre il debito): nessuno dei due dice che l'amministratore deve ancora compensare. Lo stato lo decide la stessa
 * funzione che usa la finestra (`inAttesaDellaCompensazione`): una segnalazione della persona resta «In verifica», e una rata non ancora emessa non
 * aspetta niente dall'amministratore, quindi non porta l'etichetta nuova.
 *
 * L'etichetta segue lo stato che il server scrive: dopo un versamento in contanti con il pagato netto a zero (il credito della rata è stato rimborsato
 * prima) lo stato è `partial` e l'etichetta «Pagato parz.», come la frase «hai versato»; con un credito misto rimborsato oltre il netto il restante è
 * positivo, e la rata scaduta porta «Scaduta» e non «A credito» né «Pagato».
 */

import { describe, expect, it } from 'vitest';
import { useEventStyling } from '@/composables/useEventStyling';

const { getEventStyle } = useEventStyling();

const promemoria = (meta: Record<string, unknown>) => ({ start_time: '2026-01-05T00:00:00', meta: { type: 'scadenza_rata_condomino', numero_rata: 1, is_emitted: true, ...meta } });

describe('getEventStyle e lo stato «In attesa della compensazione»', () => {
    it('K3 dopo € 40,00: partial, restante zero, da compensare → «In attesa della compensazione»', () => {
        expect(getEventStyle(promemoria({ status: 'partial', importo_originale: 4000, importo_pagato: 4000, importo_restante: 0, da_compensare: 1000 })).label).toBe('In attesa della compensazione');
    });

    it('credito oltre il debito: restante a credito e da compensare → «In attesa della compensazione», non «A credito»', () => {
        expect(getEventStyle(promemoria({ status: 'partial', importo_originale: -5000, importo_pagato: 0, importo_restante: -5000, da_compensare: 5000 })).label).toBe('In attesa della compensazione');
    });

    it('controprova: pagato meno del netto: resta «Pagato parz.»', () => {
        expect(getEventStyle(promemoria({ status: 'partial', importo_originale: 4000, importo_pagato: 2000, importo_restante: 2000, da_compensare: 1000 })).label).toBe('Pagato parz.');
    });

    it('controprova: un promemoria di prima, senza da_compensare, non cambia: «Pagato parz.»', () => {
        expect(getEventStyle(promemoria({ status: 'partial', importo_originale: 4000, importo_pagato: 4000, importo_restante: 1000 })).label).toBe('Pagato parz.');
    });

    it('controprova: una segnalazione del portale resta «In verifica» anche se c\'è un credito da compensare', () => {
        expect(getEventStyle(promemoria({ status: 'reported', importo_originale: 4000, importo_restante: 0, da_compensare: 1000 })).label).toBe('In verifica');
    });

    it('controprova: una rata non ancora emessa con un credito oltre il debito non aspetta niente dall\'amministratore: niente etichetta nuova', () => {
        const etichetta = getEventStyle(promemoria({ status: 'partial', is_emitted: false, importo_originale: -5000, importo_pagato: 0, importo_restante: -5000, da_compensare: 5000 })).label;

        expect(etichetta).not.toBe('In attesa della compensazione');
    });
});

describe('getEventStyle: l\'etichetta di un credito puro già saldato', () => {
    it('credito puro «paid» con il credito ancora lì (restante −€ 20,00) → «A credito», non «Pagato»', () => {
        expect(getEventStyle(promemoria({ status: 'paid', importo_originale: -2000, importo_pagato: 0, importo_restante: -2000, da_compensare: 0 })).label).toBe('A credito');
    });

    it('controprova: un credito restituito per intero (restante zero) resta «Pagato»: non c\'è più niente da ricevere', () => {
        expect(getEventStyle(promemoria({ status: 'paid', importo_originale: -2000, importo_pagato: -2000, importo_restante: 0, da_compensare: 0 })).label).toBe('Pagato');
    });

    it('controprova: una rata a debito pagata resta «Pagato»', () => {
        expect(getEventStyle(promemoria({ status: 'paid', importo_originale: 4000, importo_pagato: 4000, importo_restante: 0, da_compensare: 0 })).label).toBe('Pagato');
    });

    it('controprova: un credito oltre il debito su una rata emessa resta «In attesa della compensazione»', () => {
        expect(getEventStyle(promemoria({ status: 'partial', importo_originale: -5000, importo_pagato: 0, importo_restante: -5000, da_compensare: 5000 })).label).toBe('In attesa della compensazione');
    });
});

describe('getEventStyle: netto zero su una rata non ancora emessa', () => {
    it('partial, restante zero, € 50,00 da compensare, rata non emessa → «Da compensare», non «Pagato parz.»', () => {
        expect(getEventStyle(promemoria({ status: 'partial', is_emitted: false, importo_originale: 0, importo_pagato: 0, importo_restante: 0, da_compensare: 5000 })).label).toBe('Da compensare');
    });

    it('controprova: la stessa rata emessa → «In attesa della compensazione»', () => {
        expect(getEventStyle(promemoria({ status: 'partial', is_emitted: true, importo_originale: 0, importo_pagato: 0, importo_restante: 0, da_compensare: 5000 })).label).toBe('In attesa della compensazione');
    });

    it('controprova: un credito oltre il debito su una rata non emessa resta «A credito»', () => {
        expect(getEventStyle(promemoria({ status: 'partial', is_emitted: false, importo_originale: -5000, importo_pagato: 0, importo_restante: -5000, da_compensare: 5000 })).label).toBe('A credito');
    });

    it('controprova: pagato in parte su una rata non emessa (un promemoria di prima, senza da_compensare) resta «Pagato parz.»', () => {
        expect(getEventStyle(promemoria({ status: 'partial', is_emitted: false, importo_originale: 4000, importo_pagato: 2000, importo_restante: 2000 })).label).toBe('Pagato parz.');
    });
});

describe('getEventStyle: gli stati che il server scrive con il credito rimborsato', () => {
    it('contante versato e pagato netto zero (€ 5,00 del credito rimborsati, poi € 5,00 su C): partial → «Pagato parz.»', () => {
        expect(getEventStyle(promemoria({ status: 'partial', importo_originale: 4000, importo_pagato: 0, importo_versato: 500, importo_restante: 4000, da_compensare: 500 })).label).toBe('Pagato parz.');
    });

    it('credito misto rimborsato oltre il netto (restante € 50,00, nessun versamento) su una rata scaduta → «Scaduta», non «A credito» né «Pagato»', () => {
        const e = { ...promemoria({ status: 'pending', importo_originale: -2500, importo_pagato: -7500, importo_versato: 0, importo_restante: 5000, da_compensare: 0 }), start_time: '2020-01-05T00:00:00' };

        expect(getEventStyle(e).label).toBe('Scaduta');
    });

    it('controprova: il credito misto rimborsato solo in parte (restante −€ 15,00, niente da compensare) resta «A credito»', () => {
        expect(getEventStyle(promemoria({ status: 'partial', importo_originale: -2500, importo_pagato: -1000, importo_restante: -1500, da_compensare: 0 })).label).toBe('A credito');
    });
});
