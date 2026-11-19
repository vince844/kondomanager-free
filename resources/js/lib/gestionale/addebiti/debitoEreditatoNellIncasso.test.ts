// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Decisione 529: le regole della riga «Debito ereditato» nella pagina dell'incasso, sul caso di Elena, erede di Ettore Fabbri per € 150,00.
 *
 * Che cosa NON copre: la pagina montata (il campo, il pulsante, il tema scuro, il telefono: a video non provati); la distribuzione sulle rate dell'erede, che è
 * quella di sempre (`usePaymentDistribution`) sull'importo che resta.
 */

import { describe, expect, it } from 'vitest';
import {
    centesimiAlDebitoEreditato,
    debitoEreditatoDaMandare,
    fraseDelDebitoPassato,
    intestatariDellaRicevuta,
    perchéNonVaIlDebitoEreditato,
    scelteAgliEredi,
    spaccatoDellAllocato,
    tuttoNellaRiga,
    type DebitoEreditatoDellIncasso,
} from './debitoEreditatoNellIncasso';

const elena: DebitoEreditatoDellIncasso = { subentro_id: 7, gestione_id: 1, gestione: 'Ordinaria 2026', da: 'Ettore Fabbri', successione_del: '17/11/2026', aperto: 15000, aperto_formattato: '€ 150,00' };

describe('il debito ereditato nella pagina dell\'incasso', () => {
    it('la cifra scritta va al debito ereditato, e il resto dell\'importo alle rate', () => {
        expect(centesimiAlDebitoEreditato([elena], { '7|1': '150,00' })).toBe(15000);
        expect(centesimiAlDebitoEreditato([elena], { '7|1': '' })).toBe(0);
        expect(debitoEreditatoDaMandare([elena], { '7|1': '€ 100,5' })).toEqual([{ subentro_id: 7, gestione_id: 1, importo: '100.50' }]);
        expect(debitoEreditatoDaMandare([elena], { '7|1': '0' })).toEqual([]);
    });

    it('più della parte aperta, più dell\'importo versato, o una cifra che non è una cifra: la frase dice perché', () => {
        expect(perchéNonVaIlDebitoEreditato([elena], { '7|1': '150,01' }, 20000)).toBe('Il debito ereditato da Ettore Fabbri è € 150,00: più di così non si registra qui.');
        expect(perchéNonVaIlDebitoEreditato([elena], { '7|1': '150,00' }, 10000)).toContain('prende più dell\'importo versato');
        expect(perchéNonVaIlDebitoEreditato([elena], { '7|1': 'abc' }, 10000)).toContain('scrivi una cifra');
        expect(perchéNonVaIlDebitoEreditato([elena], { '7|1': '150,00' }, 15000)).toBeNull();
    });

    it('«Tutto» propone la parte aperta, al più quello che dell\'importo versato resta libero', () => {
        expect(tuttoNellaRiga(elena, [elena], {}, 0)).toBe('150,00');
        expect(tuttoNellaRiga(elena, [elena], {}, 10000)).toBe('100,00');
        const altra = { ...elena, subentro_id: 8, da: 'Gino' };
        expect(tuttoNellaRiga(elena, [elena, altra], { '8|1': '80,00' }, 20000)).toBe('120,00');
    });

    it('sulla rata del defunto: le due scelte con le conseguenze; con «Versato da» un erede, la sua parte', () => {
        const s = { subentro_id: 7, decesso: '17/11/2026', eredi: 'Elena e Marco', eredi_ids: [5, 6] };
        expect(fraseDelDebitoPassato('Ettore Fabbri', s)).toBe('Il debito di Ettore Fabbri è passato a Elena e Marco con la successione del 17/11/2026. Scegli che cosa copre questo versamento.');
        expect(scelteAgliEredi('Ettore Fabbri', s, null).map((x) => x.conseguenza)).toEqual([
            'Il versamento copre il debito di Elena e Marco, per quota, e il prossimo piano non lo chiede di nuovo.',
            'Il prossimo piano chiederà agli eredi l\'arretrato intero, e a Ettore Fabbri resterà un credito.',
        ]);
        expect(scelteAgliEredi('Ettore Fabbri', s, { id: 5, nome: 'Elena' })[0].conseguenza).toBe('Il versamento copre la parte di Elena, e il prossimo piano non gliela chiede di nuovo.');
        expect(scelteAgliEredi('Ettore Fabbri', s, { id: 9, nome: 'Luca' })[0].conseguenza).toContain('per quota');
    });

    it('dopo il piano: la frase dice dov\'è il debito, e la sola scelta è «Resta a Ettore Fabbri» con le conseguenze', () => {
        const s = { subentro_id: 7, decesso: '17/11/2026', eredi: 'Elena e Marco', eredi_ids: [5, 6], nel_piano: 'nella Rata 1 — Preventivo 2027', emessa: true };
        expect(fraseDelDebitoPassato('Ettore Fabbri', s)).toBe('Il debito di Ettore Fabbri è passato a Elena e Marco con la successione del 17/11/2026, ed è nella Rata 1 — '
            + 'Preventivo 2027 di Elena e Marco: incassalo da lì. Se questo versamento resta comunque a Ettore Fabbri, sceglilo qui sotto.');
        expect(fraseDelDebitoPassato('Ettore Fabbri', { ...s, emessa: false })).toContain('incassalo da lì quando è emessa.');
        expect(scelteAgliEredi('Ettore Fabbri', s, { id: 5, nome: 'Elena' })).toEqual([{ valore: false, titolo: 'Resta a Ettore Fabbri',
            conseguenza: 'Il versamento va sulla sua rata, gli eredi devono ancora la loro rata nuova, e a Ettore Fabbri resterà un credito.' }]);
    });
});

// Decisione 538 (3): «Totale allocato» contava solo le rate, e per un versamento tutto sul debito ereditato diceva € 0,00.
describe('lo spaccato accanto a «Totale allocato»', () => {
    it('le due parti in centesimi e il totale che le somma; le rate arrivano in euro, come la distribuzione della pagina', () => {
        expect(spaccatoDellAllocato(0, 3279)).toEqual({ ereditato: 3279, rate: 0, totale: 3279 });
        // 0,1 + 0,2 in euro è 0,30000000000000004: i centesimi si arrotondano una volta, qui.
        expect(spaccatoDellAllocato(0.1 + 0.2, 15000)).toEqual({ ereditato: 15000, rate: 30, totale: 15030 });
        expect(spaccatoDellAllocato(120.5, 15000)).toEqual({ ereditato: 15000, rate: 12050, totale: 27050 });
    });

    it('senza una parte sul debito ereditato niente spaccato: resta il totale di sempre', () => {
        expect(spaccatoDellAllocato(120.5, 0)).toBeNull();
        expect(spaccatoDellAllocato(0, Number.NaN)).toBeNull();
    });
});

// Decisione 539: «Intestatario della ricevuta» per il versamento dell'erede sul debito ereditato. Non copre il menu nella pagina
// (`IncassoRateNewIntestatarioRicevuta.test.ts`) né la ricevuta che il server ne fa.
describe('le voci di «Intestatario della ricevuta»', () => {
    const conDefunto = { ...elena, defunto: 'Ettore Fabbri', catena: false };

    it('con una cifra sul debito ereditato: l\'erede (di serie), gli eredi del defunto e il defunto, in quest\'ordine', () => {
        expect(intestatariDellaRicevuta('Elena', [conDefunto], { '7|1': '150,00' })).toEqual({
            defunto: 'Ettore Fabbri',
            voci: [
                { valore: 'erede', etichetta: 'Elena (chi ha pagato)' },
                { valore: 'eredi', etichetta: 'Eredi di Ettore Fabbri' },
                { valore: 'defunto', etichetta: 'Ettore Fabbri (il defunto)' },
            ],
        });
        // Una riga del server di prima, senza `defunto`: il nome di «da».
        expect(intestatariDellaRicevuta('Elena', [elena], { '7|1': '10' })?.defunto).toBe('Ettore Fabbri');
    });

    it('senza una cifra, con la catena o con due defunti il menu non c\'è: la ricevuta va all\'erede', () => {
        expect(intestatariDellaRicevuta('Elena', [conDefunto], {})).toBeNull();
        expect(intestatariDellaRicevuta('Elena', [conDefunto], { '7|1': '0' })).toBeNull();
        expect(intestatariDellaRicevuta('Luca', [{ ...conDefunto, da: 'Marco, che l\'aveva ereditato da Ettore Fabbri', defunto: 'Marco', catena: true }], { '7|1': '10' })).toBeNull();
        const gino = { ...conDefunto, subentro_id: 8, da: 'Gino', defunto: 'Gino' };
        expect(intestatariDellaRicevuta('Elena', [conDefunto, gino], { '7|1': '10', '8|1': '5' })).toBeNull();
        // Due righe, una sola con la cifra: il defunto è quello.
        expect(intestatariDellaRicevuta('Elena', [conDefunto, gino], { '8|1': '5' })?.defunto).toBe('Gino');
    });
});
