/**
 * Le parole della pagina «Rate scadute»: il totale e la differenza con la Tesoreria, le rate non ancora emesse, la data dell'elenco, le due
 * date dei sei mesi con i loro casi, la dispensa, il ritardo. Le date arrivano dal server come `Y-m-d`: qui si formattano e basta.
 */

export interface Dispensa { id: number; data_delibera: string; numero_verbale: string | null; revocata_il: string | null }
export interface DataSeiMesi {
    data: string | null;
    esercizio: { id: number; nome: string; data_fine: string } | null;
    stato: 'ok' | 'da_origine' | 'manca_esercizio' | 'prima_degli_esercizi' | 'senza_delibera' | 'senza_competenza' | 'origine_non_dichiarata' | 'prima_del_primo';
    doppio: boolean;
    dedotto: boolean;
    prima_della_scadenza: boolean;
    data_cercata: string | null;
    esercizio_da_chiudere: string | null;
    dispensa: Dispensa | null;
}
export interface SeiMesiDellaRata { dalla_scadenza: DataSeiMesi; dalla_competenza: DataSeiMesi; coincidono: boolean; piu_vicina: string | null; piu_vicina_dispensa: Dispensa | null }
export interface NonEmesse { persone: number; importo: number; saldi: number }

export const giorno = (d: string | null | undefined): string => (d ? d.slice(0, 10).split('-').reverse().join('/') : '');

/** «€ 1.744,10»: il punto delle migliaia anche con quattro cifre (l'italiano di `Intl`, di serie, scrive «1744,10»). */
export function euroTesto(centesimi: number): string {
    return `€ ${new Intl.NumberFormat('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2, useGrouping: 'always' } as Intl.NumberFormatOptions).format(centesimi / 100)}`;
}

/** Sotto il titolo: perché la Tesoreria della dashboard dà una cifra più bassa. */
export const RIGA_TESORERIA =
    'Lo scaduto di ognuno, senza togliere il suo credito: il credito si legge accanto. La riga «Rate scadute» della Tesoreria, nella dashboard, '
    + 'può dare una cifra più bassa: a ognuno toglie il suo credito nella stessa gestione.';

export const FRASE_DATA_PASSATA = 'Elenco a una data passata: il credito delle persone si conosce solo per oggi, e la colonna non c\'è.';

export const NOTA_PREGRESSO_NELLA_RATA = 'Questa rata contiene anche una parte di pregresso: la sua età è quella della rata.';

export const FRASE_RISERVATEZZA = 'Contiene dati personali: si mostra all\'assemblea e a chi ha diritto di conoscerlo, come il condòmino o il creditore non ancora '
    + 'pagato che lo chiedono. Non va esposto in bacheca né in altri spazi che vedono anche gli estranei.';

export function titoloVista(vista: string, giorni: number): string {
    return vista === 'in_scadenza' ? `In scadenza nei prossimi ${giorni} giorni` : 'Rate scadute';
}

/** Il totale dei filtri, sempre lordo. */
export function testoTotale(e: { persone: number; totale: number; vista: string }): string {
    const chi = e.persone === 1 ? '1 persona' : `${e.persone} persone`;
    const cosa = e.vista === 'in_scadenza' ? 'in scadenza' : 'scaduto';

    return `${chi} con questi filtri: ${cosa} ${euroTesto(e.totale)}, senza togliere il credito.`;
}

/** Le rate con la scadenza passata non ancora emesse e i saldi non ancora in un piano approvato: dovuti, ma non in questo elenco. */
export function testoNonEmesse(n: NonEmesse | null | undefined): string | null {
    if (!n || (n.importo <= 0 && n.saldi <= 0)) {
        return null;
    }
    const saldi = n.saldi > 0 ? `${euroTesto(n.saldi)} di saldi non ancora in un piano approvato` : '';
    if (n.importo <= 0) {
        return `${saldi.charAt(0).toUpperCase()}${saldi.slice(1)}: non sono in questo elenco. Si chiedono con un piano rate.`;
    }
    const chi = n.persone === 1 ? '1 persona ha' : `${n.persone} persone hanno`;

    return `${chi} ${euroTesto(n.importo)} su rate con la scadenza passata e non ancora emesse (il pregresso compreso)${saldi ? `, e ${saldi}` : ''}: `
        + (saldi ? 'non sono in questo elenco. Le rate si emettono dal piano rate; i saldi si chiedono mettendoli in un piano.' : 'non sono in questo elenco. Si emettono dal piano rate.');
}

export function testoDispensa(d: Dispensa | null | undefined): string | null {
    if (!d) {
        return null;
    }
    if (d.revocata_il) {
        return `dispensa revocata il ${giorno(d.revocata_il)}`;
    }

    return `dispensa dell'assemblea del ${giorno(d.data_delibera)}${d.numero_verbale ? ` (verbale n. ${d.numero_verbale})` : ''}`;
}

const nomeEsercizio = (nome: string) => nome.replace(/^esercizio\s+/i, '');

/**
 * La cella di una delle due date: la data, o perché non c'è. Mai un'azione che il programma rifiuterebbe: per l'esercizio che manca (ogni
 * gennaio, con il vecchio ancora aperto) si chiude l'esercizio vecchio prima di aprire il nuovo.
 */
export function testoData(v: DataSeiMesi, scadenzaRata?: string, dallaScadenza?: DataSeiMesi): string {
    switch (v.stato) {
        case 'manca_esercizio':
            return v.esercizio_da_chiudere
                ? `L'esercizio che contiene il ${giorno(v.data_cercata)} non c'è ancora: la data si calcola quando chiudi l'esercizio ${nomeEsercizio(v.esercizio_da_chiudere)} e apri il successivo.`
                : `L'esercizio che contiene il ${giorno(v.data_cercata)} non c'è ancora: la data si calcola quando c'è l'esercizio che la contiene.`;
        case 'prima_degli_esercizi':
            return `Il ${giorno(v.data_cercata)} viene prima del primo esercizio del programma: la data non si calcola.`;
        case 'senza_delibera':
            return 'Il piano straordinario non ha la data della delibera: la data dalla competenza non si calcola. Scrivila nel piano rate.';
        case 'senza_competenza':
            return 'Il piano non ha un esercizio di competenza: la data dalla competenza non si calcola.';
        case 'origine_non_dichiarata':
            return 'Origine non dichiarata: le date non si calcolano.';
        case 'prima_del_primo':
            return 'Pregresso di prima del primo esercizio: le date non si calcolano.';
        default:
            break;
    }
    if (v.prima_della_scadenza) {
        const altra = dallaScadenza?.data ? `: si mostra quella dalla scadenza (${giorno(dallaScadenza.data)})` : '';

        return `La data dalla competenza (${giorno(v.data)}) viene prima della scadenza della rata${scadenzaRata ? ` (${giorno(scadenzaRata)})` : ''}${altra}.`;
    }
    const segni = [
        v.stato === 'da_origine' && v.esercizio ? `dall'esercizio d'origine ${nomeEsercizio(v.esercizio.nome)}` : null,
        v.dedotto ? 'esercizio del piano dedotto' : null,
        v.doppio ? 'due esercizi contengono questa data: è la più vicina delle due' : null,
        testoDispensa(v.dispensa),
    ].filter(Boolean);

    return `${giorno(v.data)}${segni.length ? ` — ${segni.join('; ')}` : ''}`;
}

/** Il ritardo di una rata: i giorni, o le parole del pregresso che non ha un numero di giorni. */
export function testoGiorniDellaRata(r: { giorni_ritardo: number | null; pregresso: { etichetta: string } | null }): string {
    if (r.giorni_ritardo === null) {
        return r.pregresso?.etichetta ?? 'anzianità non nota';
    }

    return `${r.giorni_ritardo} ${r.giorni_ritardo === 1 ? 'giorno' : 'giorni'}`;
}

/** La data più vicina di una persona, con la dispensa e le rate senza data. */
export function testoSeiMesiDellaPersona(s: { data: string | null; dispensa: Dispensa | null; mancano: number }): string {
    const mancano = s.mancano > 0 ? (s.mancano === 1 ? '1 rata senza data' : `${s.mancano} rate senza data`) : null;
    if (!s.data) {
        return mancano ? `Non calcolata (${mancano})` : 'Non calcolata';
    }
    const dispensa = testoDispensa(s.dispensa);

    return [giorno(s.data), dispensa, mancano].filter(Boolean).join(' — ');
}
