/**
 * `destinazioniAmmesse`: a una sorgente di un tipo il modale «Sposta spesa» offre solo le voci dello stesso tipo.
 *
 * `destinazioniAmmesse` sta in `budgetMovements.ts` accanto a `saldoNettoMovimenti`, così il filtro si prova senza rendere il modale (il modale vero lo prova
 * `ModalSpostaSpesaTipo.test.ts`). Non cambia l'elenco che riceve.
 *
 * Il tipo manca sulle sorgenti dei piani straordinari (sono fatture): lì passa tutto. Una destinazione senza tipo passa sempre. Il tipo è quello del segno con
 * cui il motore distribuisce la voce, e un capitolo con spese ed entrate insieme arriva come «misto»: una sorgente «misto» non ha destinazioni e una
 * destinazione «misto» non si offre a nessuno.
 *
 * Cosa resta scoperto: il server (`BudgetMovementService::moveBudget` rifiuta lo stesso caso e non si fida del filtro); il modale (`ModalSpostaSpesaTipo.test.ts`).
 */

import { describe, expect, test } from 'vitest';
import { destinazioniAmmesse, TIPO_MISTO } from '@/lib/gestionale/pianiRate/budgetMovements';

const voci = [
    { id: 1, nome: 'Spese generali', tipo: 'spesa' },
    { id: 2, nome: 'Affitto antenna', tipo: 'entrata' },
    { id: 3, nome: 'Pulizie', tipo: 'spesa' },
    { id: 4, nome: 'Senza tipo' },
];

describe('destinazioniAmmesse', () => {
    test('una sorgente di spesa ammette le spese e le voci senza tipo', () => {
        expect(destinazioniAmmesse(voci, { tipo: 'spesa' }).map((v) => v.id)).toEqual([1, 3, 4]);
    });

    test('una sorgente di entrata ammette le entrate e le voci senza tipo', () => {
        expect(destinazioniAmmesse(voci, { tipo: 'entrata' }).map((v) => v.id)).toEqual([2, 4]);
    });

    test('senza il tipo sulla sorgente (fattura di un piano straordinario) passa tutto', () => {
        expect(destinazioniAmmesse(voci, {}).map((v) => v.id)).toEqual([1, 2, 3, 4]);
        expect(destinazioniAmmesse(voci, null).map((v) => v.id)).toEqual([1, 2, 3, 4]);
        expect(destinazioniAmmesse(voci, undefined).map((v) => v.id)).toEqual([1, 2, 3, 4]);
    });

    test('non cambia l\'elenco che riceve', () => {
        const copia = voci.map((v) => ({ ...v }));
        destinazioniAmmesse(voci, { tipo: 'entrata' });
        expect(voci).toEqual(copia);
    });

    test('una sorgente «misto» non ha destinazioni', () => {
        expect(destinazioniAmmesse(voci, { tipo: TIPO_MISTO })).toEqual([]);
    });

    test('una destinazione «misto» non si offre né alle spese né alle entrate', () => {
        const conMisto = [...voci, { id: 5, nome: 'Antenna', tipo: TIPO_MISTO }];

        expect(destinazioniAmmesse(conMisto, { tipo: 'spesa' }).map((v) => v.id)).toEqual([1, 3, 4]);
        expect(destinazioniAmmesse(conMisto, { tipo: 'entrata' }).map((v) => v.id)).toEqual([2, 4]);
    });

    test('controprova — senza il tipo sulla sorgente passa tutto, anche una destinazione «misto»', () => {
        const conMisto = [...voci, { id: 5, nome: 'Antenna', tipo: TIPO_MISTO }];

        expect(destinazioniAmmesse(conMisto, {}).map((v) => v.id)).toEqual([1, 2, 3, 4, 5]);
    });
});
