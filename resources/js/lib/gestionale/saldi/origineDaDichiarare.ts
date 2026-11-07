/**
 * «Dichiarala dai saldi», dall'elenco delle rate scadute: la pagina dei saldi apre «Dichiara l'origine» con i saldi della persona già scelti.
 * Sono i suoi saldi a debito dei condòmini (non dei fornitori), di tutte le unità, senza un'origine dichiarata. Si vedono e si tolgono nella
 * finestra: niente si dichiara senza la scelta dell'amministratore.
 */
interface SaldoDellaPagina { id: number; anagrafica_id?: number | null; anagrafica?: { id?: number } | null; saldo_iniziale?: number | null; fornitore_id?: number | null;
    origine_dichiarata?: string | null }

export function saldiDellaPersonaSenzaOrigine(immobili: Array<{ saldi?: SaldoDellaPagina[] | null }>, persona: number): number[] {
    if (!Number.isInteger(persona) || persona < 1) {
        return [];
    }

    return immobili.flatMap((i) => i.saldi ?? [])
        .filter((s) => (s.anagrafica_id ?? s.anagrafica?.id ?? null) === persona && (s.saldo_iniziale ?? 0) > 0 && !s.fornitore_id
            && (s.origine_dichiarata === null || s.origine_dichiarata === undefined || s.origine_dichiarata === 'non_dichiarata'))
        .map((s) => s.id);
}
