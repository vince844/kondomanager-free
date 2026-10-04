import { describe, expect, test } from 'vitest';
import { blocchiMessaggio, messaggioArticolato } from './blocchiMessaggio';

describe('blocchiMessaggio — i rifiuti della pagina del piano in capoversi, elenchi e passi', () => {
    test('un messaggio di una riga resta un capoverso di testo, e non è articolato', () => {
        const c = blocchiMessaggio('La rata è tornata in stato di bozza.');
        expect(c).toEqual([[{ tipo: 'testo', testo: 'La rata è tornata in stato di bozza.' }]]);
        expect(messaggioArticolato(c)).toBe(false);
    });

    test('il rifiuto dell\'annullamento: intestazione con l\'elenco dei passaggi, un capoverso, i passi numerati', () => {
        const c = blocchiMessaggio([
            'Un passaggio di titolarità registrato dopo l\'emissione ha preso questo piano nel conguaglio:',
            '• Ugo → Elsa, Interno 1, dal 1 maggio 2026',
            '',
            'È un passaggio registrato prima della versione 1.11.0-beta.42.',
            '',
            'Per annullare l\'emissione:',
            '1. Annulla quel passaggio.',
            '2. Annulla l\'emissione.',
            '3. Solo a questo punto registra di nuovo il passaggio.',
        ].join('\n'));

        expect(c).toEqual([
            [{ tipo: 'testo', testo: 'Un passaggio di titolarità registrato dopo l\'emissione ha preso questo piano nel conguaglio:' }, { tipo: 'punti', voci: ['Ugo → Elsa, Interno 1, dal 1 maggio 2026'] }],
            [{ tipo: 'testo', testo: 'È un passaggio registrato prima della versione 1.11.0-beta.42.' }],
            [{ tipo: 'testo', testo: 'Per annullare l\'emissione:' }, { tipo: 'passi', voci: ['Annulla quel passaggio.', 'Annulla l\'emissione.', 'Solo a questo punto registra di nuovo il passaggio.'] }],
        ]);
        expect(messaggioArticolato(c)).toBe(true);
    });

    test('nello stesso capoverso: un elenco, poi testo, poi passi — ognuno nel suo blocco, nell\'ordine', () => {
        const c = blocchiMessaggio('Oggi il ricalcolo si rifiuta perché il piano:\n• ha già quote a giornale\n• ha un incasso su una sua quota\nPer procedere:\n1. Annulla quel movimento.\n2. Ricalcola il piano.');
        expect(c[0].map((b) => b.tipo)).toEqual(['testo', 'punti', 'testo', 'passi']);
        expect(c[0][1]).toEqual({ tipo: 'punti', voci: ['ha già quote a giornale', 'ha un incasso su una sua quota'] });
    });

    test('due capoversi di solo testo sono articolati (il suggerimento dopo l\'emissione)', () => {
        expect(messaggioArticolato(blocchiMessaggio('Sono state emesse 3 rate.\n\nPuoi compensare i crediti.'))).toBe(true);
    });

    test('revisione: una frase che comincia con un anno, o un «3.» isolato, resta testo; «1.» dopo una serie ne apre un\'altra', () => {
        expect(blocchiMessaggio('2026. L\'anno del piano.')).toEqual([[{ tipo: 'testo', testo: '2026. L\'anno del piano.' }]]);
        expect(blocchiMessaggio('3. Fuori serie')).toEqual([[{ tipo: 'testo', testo: '3. Fuori serie' }]]);
        expect(blocchiMessaggio('1. Uno\n2. Due\n4. Salto')[0]).toEqual([{ tipo: 'passi', voci: ['Uno', 'Due'] }, { tipo: 'testo', testo: '4. Salto' }]);
        expect(blocchiMessaggio('1. Uno\n2. Due\n1. Di nuovo')[0]).toEqual([{ tipo: 'passi', voci: ['Uno', 'Due'] }, { tipo: 'passi', voci: ['Di nuovo'] }]);
    });

    test('un numero dentro una frase non è un passo, e un messaggio vuoto non ha capoversi', () => {
        expect(blocchiMessaggio('Il piano 2026. è fermo')).toEqual([[{ tipo: 'testo', testo: 'Il piano 2026. è fermo' }]]);
        expect(blocchiMessaggio('')).toEqual([]);
        expect(blocchiMessaggio(undefined)).toEqual([]);
    });
});
