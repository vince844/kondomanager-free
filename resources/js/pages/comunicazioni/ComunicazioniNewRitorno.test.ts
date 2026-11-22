// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La pagina della nuova comunicazione aperta da un'assemblea (decisione 646): il ritorno lo dice il server (`ritorno`), e «Torna all'assemblea» in testa e
 * «Annulla» portano lì; senza `ritorno` restano all'elenco. «Salva» lo riporta il server, con il messaggio (`RitornoDellAvvisoTest`).
 *
 * Che cosa non copre: il pulsante a video.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, test } from 'vitest';

const pagina = readFileSync(resolve(process.cwd(), 'resources/js/pages/comunicazioni/ComunicazioniNew.vue'), 'utf8');

describe('il ritorno all\'assemblea', () => {
    test('la pagina riceve `ritorno` dal server e non lo prende dall\'indirizzo', () => {
        expect(pagina).toContain('ritorno?: { url: string; testo: string } | null;');
        expect(pagina).not.toContain('window.location');
        expect(pagina).not.toContain('URLSearchParams');
    });

    test('«Torna all\'assemblea» in testa e «Annulla» portano all\'assemblea; senza ritorno, all\'elenco', () => {
        expect(pagina).toContain(":back-url=\"ritorno?.url ?? route(generateRoute('comunicazioni.index'))\"");
        expect(pagina).toContain(':back-text="ritorno?.testo ?? trans(\'comunicazioni.actions.back\')"');
        expect(pagina).toContain(":href=\"ritorno?.url ?? route(generateRoute('comunicazioni.index'))\"");
    });
});
