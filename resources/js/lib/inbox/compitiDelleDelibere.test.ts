// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I due compiti del seguito delle delibere nell'Action inbox: «Delibere da eseguire» e «Delibere respinte», con l'icona delle assemblee e il loro menu.
 *
 * - «Delibere da eseguire»: «Segna eseguita, con una nota», «Ricordamelo il…», «Non serve più, con il motivo» (le risposte passano dalla cosa da fare);
 * - «Delibere respinte»: «Chiudi con il motivo» (chiude anche i compiti dell'origine) e «Ricordamelo il…»; niente «Fatto», la risposta è una scelta.
 */
import { describe, expect, test } from 'vitest';
import { aspettoDelTipo } from './actionInbox';
import { vociDelMenu } from './risposteDelCompito';

const compito = (type: string) => ({ id: 1, type, title: 'Delibera da eseguire: firma con Serramenti Marino' });

describe('i compiti delle delibere', () => {
    test('hanno l\'icona delle assemblee', () => {
        expect(aspettoDelTipo('delibera_da_eseguire').icona).toBe('assemblea');
        expect(aspettoDelTipo('delibera_respinta').icona).toBe('assemblea');
    });

    test('«Delibere da eseguire»: «Segna eseguita», «Ricordamelo», «Non serve più»', () => {
        expect(vociDelMenu(compito('delibera_da_eseguire'))).toEqual([
            { tipo: 'fatto', etichetta: 'Segna eseguita, con una nota' },
            { tipo: 'ricordamelo', etichetta: 'Ricordamelo il…' },
            { tipo: 'non_serve', etichetta: 'Non serve più, con il motivo' },
        ]);
    });

    test('«Delibere respinte»: «Chiudi con il motivo» e «Ricordamelo», senza «Fatto»', () => {
        expect(vociDelMenu(compito('delibera_respinta'))).toEqual([
            { tipo: 'non_serve', etichetta: 'Chiudi con il motivo' },
            { tipo: 'ricordamelo', etichetta: 'Ricordamelo il…' },
        ]);
    });
});
