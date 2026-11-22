// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le versioni di prima di un documento (1.12.0-beta.13, §9 del registro delle modifiche): le parole e le cifre della sezione «Versioni di prima» della pagina
 * di modifica del documento. Il server manda le versioni già pronte (`VersioniDeiDocumenti::perLaPagina`): la data all'italiana, chi l'ha sostituita, la
 * grandezza in byte e l'impronta intera; qui si scrivono la grandezza e l'impronta breve, come le scrive la riga del registro.
 */

export interface VersioneDiPrima {
    id: number;
    nome: string;
    /** «16/12/2026 09:12», nel fuso di Roma. */
    sostituita_il: string;
    /** Il nome di chi ha caricato il file nuovo; `null` se l'utente non c'è più. */
    sostituita_da: string | null;
    /** In byte. */
    grandezza: number;
    /** sha256 del contenuto, 64 caratteri. */
    impronta: string;
}

export const TITOLO_VERSIONI = 'Versioni di prima';
export const DESCRIZIONE_VERSIONI = 'Quando sostituisci il file, quello di prima resta qui in sola lettura, con la sua impronta. Occupa spazio come gli altri file.';
export const VUOTO_VERSIONI = 'Il file non è mai stato sostituito.';

/** La grandezza all'italiana, come la riga del registro: «820 byte», «3 KB», «1,2 MB». */
export function grandezza(byte: number): string {
    if (!Number.isFinite(byte) || byte < 1024) {
        return `${Math.max(0, Math.round(Number.isFinite(byte) ? byte : 0))} byte`;
    }
    const [valore, unita] = byte < 1024 * 1024 ? [byte / 1024, 'KB'] : [byte / (1024 * 1024), 'MB'];
    const testo = valore.toLocaleString('it-IT', { minimumFractionDigits: 0, maximumFractionDigits: 1 });

    return `${testo} ${unita}`;
}

/** I primi dodici caratteri dell'impronta, come nella riga del registro: «3f2a9c1b04de…». */
export function improntaBreve(impronta: string): string {
    return impronta.length > 12 ? `${impronta.slice(0, 12)}…` : impronta;
}

/** La riga sotto il nome: «Sostituita il 16/12/2026 09:12 da Andrea Costa · 1,2 MB». */
export function rigaDellaVersione(v: VersioneDiPrima): string {
    const chi = v.sostituita_da === null ? ' (chi l\'ha sostituita non è più fra gli utenti)' : ` da ${v.sostituita_da}`;

    return `Sostituita il ${v.sostituita_il}${chi} · ${grandezza(v.grandezza)}`;
}
