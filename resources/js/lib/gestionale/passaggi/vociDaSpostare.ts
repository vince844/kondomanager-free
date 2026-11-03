import type { VoceDaSpostare } from '@/types/gestionale/passaggi';

/**
 * Decisioni 31.6 e 31.8 (1.11.0-beta.41): le voci che, scegliendo al passaggio «l'ordinaria all'usufruttuario», passano dal
 * «Proprietario» all'«Usufruttuario». Il modulo le mostra tutte spuntate e tiene solo quelle a cui l'amministratore toglie
 * la spunta (`voci_da_tenere`); una voce bloccata da un piano approvato non si sposta e non si spunta. Il server ripete
 * tutto: qui si decide solo cosa mostra il modulo.
 */

/** La voce è spuntata, cioè passerà sull'«Usufruttuario»? Una voce bloccata non lo è mai. */
export function voceSpuntata(voce: Pick<VoceDaSpostare, 'id' | 'bloccata'>, daTenere: readonly number[]): boolean {
    return !voce.bloccata && !daTenere.includes(voce.id);
}

/** Toglie o rimette la spunta: restituisce il nuovo elenco delle voci da tenere, senza toccare quello dato. */
export function cambiaSpunta(daTenere: readonly number[], id: number): number[] {
    return daTenere.includes(id) ? daTenere.filter(x => x !== id) : [...daTenere, id];
}

/** Le voci che si possono spuntare e quelle bloccate, nell'ordine in cui arrivano. */
export function dividiVoci<T extends Pick<VoceDaSpostare, 'bloccata'>>(voci: readonly T[]): { libere: T[]; bloccate: T[] } {
    return { libere: voci.filter(v => !v.bloccata), bloccate: voci.filter(v => v.bloccata) };
}

/**
 * I nomi delle voci, una volta sola: più voci con lo stesso nome (gli imprevisti di uno stesso fornitore) si contano,
 * «Imprevisto (3 voci)». Lo stesso fa il server (`AnteprimaPassaggio::nomiVoci()`).
 */
export function nomiVoci(voci: readonly Pick<VoceDaSpostare, 'conto'>[]): string {
    const conteggio = new Map<string, number>();
    voci.forEach(v => conteggio.set(v.conto, (conteggio.get(v.conto) ?? 0) + 1));
    return [...conteggio].map(([nome, n]) => (n > 1 ? `${nome} (${n} voci)` : nome)).join(', ');
}
