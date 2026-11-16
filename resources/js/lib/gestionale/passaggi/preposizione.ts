// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «a» o «ad» davanti al nome di una persona, con la regola del server (`FrasiObbligati::a()`): «ad» davanti a un nome che comincia per
 * A, anche accentata o minuscola, «a» davanti a tutti gli altri, in ogni passaggio (decisione 100, punto 3). Le tabelle del pannello
 * compongono la frase nel template, e la regola deve essere la stessa.
 */
export function aNome(nome: string): string {
    return (/^[aàAÀ]/u.test(nome) ? 'ad ' : 'a ') + nome;
}
