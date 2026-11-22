// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La delega in «Scegli la delibera» (decisione 622 (3)): «In vigore fino al 31/12/2027 · Fino a € 2.000,00»; scaduta, il tetto soltanto (il perché lo dice il
 * menu, «non si sceglie: scaduta il 31/12/2027»); senza il tetto la frase del server; nessuna riga per una delibera che non è una delega.
 */
import { describe, expect, test } from 'vitest';
import type { DeliberaDelMenu } from '@/types/assemblee';
import { fraseDellaDelega } from './delega';

// La delibera del menu porta la delega nel suo tipo: nessun tipo a parte per «Scegli la delibera».
const base: DeliberaDelMenu = { id: 1, testo: 'Delega', esito: 'approvata', esito_parola: 'Approvata', deliberata_il: '2026-12-15', punto: null, assemblea_id: 1, frase: '',
    si_sceglie: true, perche: null, revocata: null, impugnata: null };
const conDelega = (delega: DeliberaDelMenu['delega']): DeliberaDelMenu => ({ ...base, delega });

describe('la delega nel menu delle delibere', () => {
    test('in vigore, con la scadenza e il tetto in euro dai centesimi', () => {
        expect(fraseDellaDelega(conDelega({ tetto: 200000, ambito: 'accordi_fornitori', ambito_parola: 'Accordi con i fornitori', fino_al: '2027-12-31', in_vigore: true,
            frase: '€ 2.000,00 fino al 31/12/2027' }))).toBe('In vigore fino al 31/12/2027 · Fino a € 2.000,00');
    });
    test('scaduta: il tetto soltanto; senza il tetto la frase del server; senza delega niente', () => {
        expect(fraseDellaDelega(conDelega({ tetto: 200000, ambito: 'accordi_fornitori', ambito_parola: null, fino_al: '2027-12-31', in_vigore: false, frase: '' })))
            .toBe('Fino a € 2.000,00');
        expect(fraseDellaDelega(conDelega({ tetto: null, ambito: 'accordi_fornitori', ambito_parola: null, fino_al: null, in_vigore: false,
            frase: 'senza tetto: non vale finché il tetto non è scritto nel punto' }))).toBe('Delega: senza tetto: non vale finché il tetto non è scritto nel punto');
        expect(fraseDellaDelega(base)).toBeNull();
    });
});
