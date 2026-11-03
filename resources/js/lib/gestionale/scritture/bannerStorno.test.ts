import { describe, expect, test } from 'vitest';
import { STORNI_DI_SCRITTURA, eUnoStorno, figlieCheLaStornano } from './bannerStorno';

/**
 * La pagina della scrittura (`resources/js/pages/gestionale/movimenti/scritture/Show.vue`) e i suoi due riquadri:
 * «Attenzione: movimento annullato» sulla scrittura che uno storno ha annullato (oggi `v-if="scrittura.figlie?.length > 0"`,
 * :299) e «Movimento di storno» sulla scrittura che ne annulla un'altra (oggi `v-if="scrittura.padre"`, :279).
 *
 * 1.11.0-beta.49, voce P4 del verbale: oggi i due riquadri compaiono per ogni figlia e per ogni padre, e una scrittura ha
 * figlie che non la annullano. La compensazione di un incasso valido è sua figlia (`storno_credito`, StoreIncassoRateAction);
 * la rettifica del riallineamento dei fondi e quella del riallineamento dei crediti sono figlie di una scrittura che resta
 * valida; con lo storno della .49 l'emissione di una rata ha una figlia (`emissione_rata`) che riporta il credito restituito.
 *
 * Il criterio è quello del registro (`RegistroContabilitaService::stornataSql`): una figlia annulla la scrittura se è uno degli
 * storni di scrittura (`TipoMovimentoContabile::storniDiScrittura()`: giroconto, regolazione immediata, F24, pagamento al
 * fornitore e fattura lasciano l'originale «registrata»), oppure se è una rettifica e la scrittura è «annullata» (lo storno di
 * un incasso o di un rimborso). La frase del verbale, «solo per le figlie di tipo rettifica di una scrittura annullata», presa
 * alla lettera toglierebbe il riquadro agli storni di giroconto, regolazione immediata, F24 e pagamento: qui sotto sono guardie.
 *
 * Il modulo vuole dal server, per ogni figlia, il `tipo_movimento` (oggi passa solo l'etichetta) e, per il padre, il suo
 * `stato` e il suo `tipo_movimento` (CreditiStornoTest, «la pagina della scrittura riceve il tipo delle figlie»).
 */

const figlia = (tipo_movimento: string, id = 1) => ({ id, tipo_movimento });

describe('figlieCheLaStornano: il riquadro «movimento annullato» solo per chi annulla davvero', () => {
    test('un incasso valido con la sua compensazione: nessun riquadro (oggi compare)', () => {
        const incasso = { stato: 'registrata', figlie: [figlia('storno_credito')] };

        expect(figlieCheLaStornano(incasso)).toEqual([]);
    });

    test('un incasso stornato: solo la rettifica lo annulla, non la compensazione (anch\'essa annullata, ma figlia di un altro fatto)', () => {
        const incasso = { stato: 'annullata', figlie: [figlia('storno_credito', 10), figlia('rettifica', 11)] };

        expect(figlieCheLaStornano(incasso).map((f) => f.id)).toEqual([11]);
    });

    test('l\'emissione con la figlia dello storno della .49: nessun riquadro, l\'emissione resta valida', () => {
        const emissione = { stato: 'registrata', figlie: [figlia('emissione_rata')] };

        expect(figlieCheLaStornano(emissione)).toEqual([]);
    });

    test('la compensazione della 1.10 riallineata: la rettifica del riallineamento non la annulla', () => {
        const compensazione = { stato: 'registrata', figlie: [figlia('rettifica')] };

        expect(figlieCheLaStornano(compensazione)).toEqual([]);
    });

    test('un rimborso stornato: la rettifica lo annulla', () => {
        const rimborso = { stato: 'annullata', figlie: [figlia('rettifica', 7)] };

        expect(figlieCheLaStornano(rimborso).map((f) => f.id)).toEqual([7]);
    });

    test.each([
        ['giroconto', 'storno_giroconto'],
        ['regolazione_immediata', 'storno_regolazione_immediata'],
        ['pagamento_f24', 'storno_pagamento_f24'],
        ['pagamento_fornitore', 'storno_pagamento_fornitore'],
    ])('(guardia) %s stornato lascia l\'originale «registrata»: il riquadro resta per %s', (_originale, storno) => {
        const scrittura = { stato: 'registrata', figlie: [figlia(storno, 3)] };

        expect(figlieCheLaStornano(scrittura).map((f) => f.id)).toEqual([3]);
    });

    test('senza figlie, o con le figlie che il server non manda: nessun riquadro', () => {
        expect(figlieCheLaStornano({ stato: 'registrata', figlie: [] })).toEqual([]);
        expect(figlieCheLaStornano({ stato: 'annullata', figlie: undefined })).toEqual([]);
    });
});

describe('eUnoStorno: il riquadro «movimento di storno» solo sulla scrittura che ne annulla un\'altra', () => {
    test('la rettifica di un incasso annullato è uno storno', () => {
        expect(eUnoStorno({ tipo_movimento: 'rettifica', padre: { stato: 'annullata', tipo_movimento: 'incasso_rata' } })).toBe(true);
    });

    test('la compensazione di un incasso valido non è uno storno (oggi il riquadro dice «inverte e annulla»)', () => {
        expect(eUnoStorno({ tipo_movimento: 'storno_credito', padre: { stato: 'registrata', tipo_movimento: 'incasso_rata' } })).toBe(false);
    });

    test('la figlia dell\'emissione che riporta il credito restituito non è uno storno', () => {
        expect(eUnoStorno({ tipo_movimento: 'emissione_rata', padre: { stato: 'registrata', tipo_movimento: 'emissione_rata' } })).toBe(false);
    });

    test('la rettifica di un riallineamento, figlia di una scrittura valida, non è uno storno', () => {
        expect(eUnoStorno({ tipo_movimento: 'rettifica', padre: { stato: 'registrata', tipo_movimento: 'storno_credito' } })).toBe(false);
    });

    test('(guardia) lo storno di un giroconto è uno storno, con l\'originale «registrata»', () => {
        expect(eUnoStorno({ tipo_movimento: 'storno_giroconto', padre: { stato: 'registrata', tipo_movimento: 'giroconto' } })).toBe(true);
    });

    test('una scrittura senza padre non è uno storno', () => {
        expect(eUnoStorno({ tipo_movimento: 'rettifica', padre: null })).toBe(false);
    });
});

describe('lo specchio del server', () => {
    test('STORNI_DI_SCRITTURA è l\'elenco di TipoMovimentoContabile::isStornoDiScrittura()', async () => {
        const fs = await import('node:fs');
        const sorgente = fs.readFileSync('app/Enums/TipoMovimentoContabile.php', 'utf8');
        const valori = new Map([...sorgente.matchAll(/case\s+([A-Z0-9_]+)\s*=\s*'([a-z0-9_]+)'/g)].map((m) => [m[1], m[2]]));
        const corpo = sorgente.match(/function isStornoDiScrittura\(\)[^{]*\{([\s\S]*?)\n {4}\}/)?.[1] ?? '';
        const nomi = [...corpo.matchAll(/self::([A-Z0-9_]+)/g)].map((m) => m[1]);

        expect(nomi.length, 'il corpo di isStornoDiScrittura() non è stato trovato: il test sarebbe verde senza guardare').toBeGreaterThan(3);
        expect([...STORNI_DI_SCRITTURA].sort()).toEqual(nomi.map((n) => valori.get(n)).sort());
    });
});
