/**
 * `useImportaFatturaXml` — beta.14, decisione 1 di apertura. Il composable non decide
 * niente da solo (fornitore, tipo documento, ecc.): impacchetta la chiamata e restituisce
 * quello che il controller ha già deciso, o un messaggio quando fallisce.
 */
import { describe, expect, test, vi } from 'vitest';

const axios = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('axios', () => ({ default: axios }));

(globalThis as any).route = (name: string, params: Record<string, unknown>) =>
    `/${name}/${JSON.stringify(params)}`;

import { useImportaFatturaXml } from './useImportaFatturaXml';

const ESITO_OK = {
    documento: {
        tipo_documento: 'fattura',
        numero_documento: 'FT-1',
        data_documento: '2026-06-10',
        data_scadenza: null,
        modalita_pagamento: null,
        iban_fornitore: null,
    },
    righe: [{ descrizione: 'Servizio', importo_imponibile: 35, aliquota_iva: 22 }],
    fornitore: {
        esito: 'non_trovato',
        candidati: [],
        letto_da_xml: { denominazione: 'Giulia Bianchi', partita_iva: '01234567897', codice_fiscale: null },
    },
    avvisi: { lotto_con_altri_documenti: 0, righe_non_quadrano_col_riepilogo: false, scarto_righe_riepilogo_cents: 0 },
};

describe('importa()', () => {
    test('restituisce i dati e azzera isLoading a richiesta conclusa', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_OK });
        const { importa, isLoading, errore } = useImportaFatturaXml();

        const file = new File(['<xml/>'], 'fattura.xml', { type: 'text/xml' });
        const promessa = importa(28, file);

        expect(isLoading.value).toBe(true);

        const esito = await promessa;

        expect(isLoading.value).toBe(false);
        expect(errore.value).toBeNull();
        expect(esito).toEqual(ESITO_OK);
    });

    test('invia il file dentro un FormData, non come JSON', async () => {
        axios.post.mockResolvedValueOnce({ data: ESITO_OK });
        const { importa } = useImportaFatturaXml();

        const file = new File(['<xml/>'], 'fattura.xml');
        await importa(28, file);

        const [, corpo] = axios.post.mock.calls[0];
        expect(corpo).toBeInstanceOf(FormData);
        // jsdom non garantisce l'identità dell'oggetto File attraverso FormData:
        // si confronta il contenuto, non il riferimento.
        const inviato = corpo.get('file') as File;
        expect(inviato.name).toBe(file.name);
        expect(inviato.size).toBe(file.size);
    });

    test('un 422 porta il messaggio di dominio in errore, non lo nasconde', async () => {
        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { errore: 'File XML malformato: righe non chiuse' } } });
        const { importa, errore } = useImportaFatturaXml();

        const esito = await importa(28, new File(['x'], 'rotto.xml'));

        expect(esito).toBeNull();
        expect(errore.value).toBe('File XML malformato: righe non chiuse');
    });

    test('un 422 di validazione (es. file troppo grande) legge errors.file, non solo errore', async () => {
        // Trovato dalla revisione avversariale della beta.14: ImportaFatturaXmlRequest valida
        // la dimensione PRIMA che il parser veda un byte, e quel 422 ha data.errors.file, non
        // data.errore — la forma che il composable leggeva. Il messaggio del server («il file
        // supera i 10 MB») finiva scartato in favore del generico sotto.
        axios.post.mockRejectedValueOnce({
            response: { status: 422, data: { errors: { file: ['Il campo file non deve essere più grande di 10240 kilobyte.'] } } },
        });
        const { importa, errore } = useImportaFatturaXml();

        const esito = await importa(28, new File(['x'], 'grande.xml'));

        expect(esito).toBeNull();
        expect(errore.value).toBe('Il campo file non deve essere più grande di 10240 kilobyte.');
    });

    test('un guasto senza risposta strutturata mostra un messaggio generico, non un errore tecnico', async () => {
        axios.post.mockRejectedValueOnce(new Error('Network Error'));
        const { importa, errore } = useImportaFatturaXml();

        await importa(28, new File(['x'], 'fattura.xml'));

        expect(errore.value).toContain('Impossibile leggere il file');
    });

    test('reset() pulisce l\'errore di un tentativo precedente', async () => {
        axios.post.mockRejectedValueOnce({ response: { status: 422, data: { errore: 'guasto' } } });
        const { importa, errore, reset } = useImportaFatturaXml();

        await importa(28, new File(['x'], 'rotto.xml'));
        expect(errore.value).not.toBeNull();

        reset();
        expect(errore.value).toBeNull();
    });

    test('isLoading torna false anche quando la richiesta fallisce', async () => {
        axios.post.mockRejectedValueOnce(new Error('boom'));
        const { importa, isLoading } = useImportaFatturaXml();

        await importa(28, new File(['x'], 'fattura.xml'));

        expect(isLoading.value).toBe(false);
    });
});
