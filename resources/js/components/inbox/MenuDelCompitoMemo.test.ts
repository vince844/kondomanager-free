// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il menu del compito con le assemblee (1.12.0-beta.13; la scena 3 della bozza del memo): il compito del memo «Da portare in
 * assemblea» ha «Apri la fattura n. 45», «Ricordamelo il…» e «Non serve più», **senza «Fatto»**; gli altri compiti restano come sono.
 *
 * Che cosa non copre: «Portalo in assemblea» a video (la finestra del memo ha il suo test), il rifiuto della rotta (`MemoDallaFinestraTest`).
 */
import { describe, expect, test } from 'vitest';
import { vociDelMenu } from '@/lib/inbox/risposteDelCompito';

describe('le voci del menu del compito', () => {
    test('il compito del memo: «Apri …», «Ricordamelo il…», «Non serve più», niente «Fatto»', () => {
        const voci = vociDelMenu({ id: 4, type: 'memo_assemblea', title: 'Da portare in assemblea: ratifica', context: { action_url: '/admin/gestionale/3/fatture/45', apri: 'Apri la fattura n. 45' } });
        expect(voci.map((v) => [v.tipo, v.etichetta])).toEqual([['apri', 'Apri la fattura n. 45'], ['ricordamelo', 'Ricordamelo il…'], ['non_serve', 'Non serve più']]);
        expect(vociDelMenu({ id: 4, type: 'memo_assemblea', title: 'Il portone' }).map((v) => v.tipo)).toEqual(['ricordamelo', 'non_serve']);
    });

    test('gli altri compiti come prima', () => {
        expect(vociDelMenu({ id: 5, type: 'scadenza', title: 'Rata' }).map((v) => v.tipo)).toEqual(['fatto', 'non_serve', 'ricordamelo']);
    });
});
