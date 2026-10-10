/**
 * «a» o «ad» davanti al nome di una persona, con la regola del server (`FrasiObbligati::a()`): «ad» davanti a un nome che comincia per
 * A, anche accentata o minuscola, «a» davanti a tutti gli altri (1.11.0-beta.48, rilievo R5 della Fase 1-bis, decisione 73 punto 4). Le
 * tabelle del pannello compongono la frase nel template, e la regola deve essere la stessa.
 */
export function aNome(nome: string): string {
    return (/^[aàAÀ]/u.test(nome) ? 'ad ' : 'a ') + nome;
}
