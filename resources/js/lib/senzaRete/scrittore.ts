// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import type { Deposito } from './deposito';
import { nuovoNumero } from './numero';
import { creaIlDispositivo } from './striscia';

/**
 * Chi scrive, per il server (il verbale e la sala): **il dispositivo** e **la scheda del browser**.
 *
 * - **Il dispositivo è di chi usa il browser**: si tiene nel deposito con la chiave `dispositivo:<utente>`, così Marco sul browser di Andrea è «computer di
 *   Marco Galli», non «computer di Andrea Costa», e i suoi salvataggi da una base vecchia fanno due versioni con quelli di Andrea. La sala e il verbale lo
 *   leggono dallo stesso posto: sullo stesso computer sono lo stesso dispositivo.
 * - **La scheda**: un numero tenuto in `sessionStorage`, che vive quanto la scheda (e sopravvive al ricaricamento e alle visite di Inertia, dalla sala al
 *   verbale). Va nei dati di ogni testo (`dati.scheda`): il server conta due schede dello stesso browser come due scrittori, e la scheda ferma al testo delle
 *   21:00 non cancella la frase scritta alle 21:02 nell'altra. Un testo ritrovato dopo uno spegnimento porta la scheda di allora.
 */
export const CHIAVE_DELLA_SCHEDA = 'kondomanager:scheda';

let schedaInMemoria: string | null = null;

/** Il numero della scheda del browser (otto lettere): da `sessionStorage`, o nuovo; senza `sessionStorage` (una finestra che lo nega) vale per la pagina. */
export function schedaDelBrowser(archivio: Pick<Storage, 'getItem' | 'setItem'> | undefined = globalThis.sessionStorage): string {
    try {
        const tenuta = archivio?.getItem(CHIAVE_DELLA_SCHEDA);
        if (tenuta && /^[A-Za-z0-9-]{1,40}$/.test(tenuta)) return tenuta;
        const nuova = nuovoNumero().replace(/-/g, '').slice(0, 8);
        archivio?.setItem(CHIAVE_DELLA_SCHEDA, nuova);
        return nuova;
    } catch {
        schedaInMemoria ??= nuovoNumero().replace(/-/g, '').slice(0, 8);
        return schedaInMemoria;
    }
}

/** La chiave del dispositivo nel deposito: di chi usa il browser. */
export const chiaveDelDispositivo = (utente: number | string | null | undefined) => (utente != null && utente !== '' ? `dispositivo:${utente}` : 'dispositivo');

/** Il dispositivo di chi usa il browser: tenuto nel deposito, o nuovo («computer di Andrea Costa#a1b2c3d4»). */
export async function dispositivoDellUtente(deposito: Deposito, utente: number | string | null | undefined, tipo: string, nome: string,
    numero: () => string = nuovoNumero): Promise<string> {
    const chiave = chiaveDelDispositivo(utente);
    const tenuto = await deposito.leggi<string>(chiave);
    if (tenuto) return tenuto;
    const nuovo = creaIlDispositivo(tipo, nome, numero());
    await deposito.scrivi(chiave, nuovo);

    return nuovo;
}
