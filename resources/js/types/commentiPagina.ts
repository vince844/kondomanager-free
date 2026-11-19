// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

// La pagina «Commenti» del pannello (Coda 657, decisione 442).

export type CommentoStatoPagina = 'pubblicato' | 'in_attesa' | 'nascosto';
export type CommentoTipoElemento = 'segnalazioni' | 'comunicazioni';

export interface CommentoRigaPagina {
    id: number;
    testo: string;
    tipo: CommentoTipoElemento;
    elemento: string;
    url_elemento: string;
    url_commenti: string;
    // Sempre un condominio (decisione 479 (2)): il palazzo; nella discussione unica di una comunicazione ai palazzi, quello di chi scrive
    // (decisione 458); in una discussione a persone, quello di chi scrive o i condomìni della persona.
    condominio: string;
    // La persona della discussione di una comunicazione a persone, sotto il titolo; altrimenti null.
    discussione_con: string | null;
    autore: string;
    // Il giorno e l'ora di Roma, scritti dal server: `aaaa-mm-gg hh:mm`.
    creato_il: string | null;
    modificato: boolean;
    stato: CommentoStatoPagina;
    puo_rispondere: boolean;
    // Dove scrive il modulo «Rispondi»: la rotta della scheda e i campi in più (la discussione di una comunicazione).
    rispondi: { url: string; dati: Record<string, string> } | null;
    puo_modificare: boolean;
    puo_approvare: boolean;
    puo_nascondere: boolean;
    puo_mostrare: boolean;
    puo_eliminare: boolean;
}

export interface CommentiFiltri {
    stato: string;
    tipo: string;
    condominio_id: number | null;
    autore: string;
    // Un elemento solo (la segnalazione o la comunicazione) e il suo nome, da «Modera» dell'Action inbox.
    elemento?: number | null;
    elemento_nome?: string | null;
    dal: string;
    al: string;
}
