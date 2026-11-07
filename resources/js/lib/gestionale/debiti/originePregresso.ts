/**
 * L'origine del pregresso dichiarata sul saldo: le voci del campo (un esercizio del condominio, «dovuto con questo piano», «prima del primo
 * esercizio») e come si leggono. Il campo non ha una risposta già scelta: vuoto è «origine non dichiarata».
 */

export interface EsercizioDellOrigine { id: number; nome: string }

export const FRASE_NESSUNA_SCELTA = 'Non dichiarata';

export const SPIEGAZIONE_VOCI =
    '«Dovuto con questo piano»: il debito si chiede adesso, con questo piano (per esempio un conguaglio); i giorni si contano dalla scadenza della rata, '
    + 'come per una rata. Un esercizio, o «prima '
    + 'del primo esercizio», dice di quando è il debito, non quando è scaduto: l\'elenco lo mette oltre 90 giorni, senza un numero di giorni.';

/** Le voci del campo, con il valore che le porta: `esercizio:ID`, `con_il_piano`, `prima_del_primo`. */
export function opzioniOrigine(esercizi: EsercizioDellOrigine[]): Array<{ value: string; text: string }> {
    return [
        ...esercizi.map((e) => ({ value: `esercizio:${e.id}`, text: e.nome })),
        { value: 'con_il_piano', text: 'Dovuto con questo piano' },
        { value: 'prima_del_primo', text: 'Prima del primo esercizio' },
    ];
}

/** La scelta del campo, come la vuole il server. Vuota: nessuna voce (il modulo del saldo); «Dichiara l'origine» la chiede sempre. */
export function daScelta(valore: string): { origine: string | null; esercizio_origine_id: number | null } {
    if (valore.startsWith('esercizio:')) {
        return { origine: 'esercizio', esercizio_origine_id: Number(valore.slice('esercizio:'.length)) };
    }
    if (valore === 'con_il_piano' || valore === 'prima_del_primo' || valore === 'non_dichiarata') {
        return { origine: valore, esercizio_origine_id: null };
    }

    return { origine: null, esercizio_origine_id: null };
}

/** Come si legge l'origine di un saldo. */
export function etichettaOrigine(s: { origine_dichiarata?: string | null; esercizio_origine_id?: number | null }, esercizi: EsercizioDellOrigine[]): string {
    switch (s.origine_dichiarata) {
        case 'esercizio':
            return `origine: ${esercizi.find((e) => e.id === s.esercizio_origine_id)?.nome ?? 'un esercizio che non c\'è più'}`;
        case 'con_il_piano':
            return 'origine: dovuto con questo piano';
        case 'prima_del_primo':
            return 'origine: prima del primo esercizio';
        default:
            return 'origine non dichiarata';
    }
}
