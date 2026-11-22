// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/** Il fondo speciale in testa al piano (decisione 622 (2)), come lo manda il server (`FondoSpecialeDelPiano::perLaPagina()`); le cifre in centesimi. */
export interface FondoSpecialeDelPiano {
    frase: string;
    legge: string;
    chiesti: number;
    deliberati: number;
    delibera_id: number;
    assemblea_id: number;
}

/** I lavori urgenti in testa al piano (Coda 688), come li manda il server (`GiornoDellUrgenza::perLaPagina()`). */
export interface UrgenzaDelPiano {
    /** «gg/mm/aaaa», o null per il piano d'urgenza delle fatture, dove il periodo lo dice ogni fattura. */
    giorno: string | null;
    motivo: string | null;
    frase: string;
    assemblea: string;
}
