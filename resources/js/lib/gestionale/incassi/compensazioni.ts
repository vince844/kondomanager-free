/**
 * Le parole della sezione «Compensazioni senza avviso» della pagina delle ricevute: il credito che ha coperto le rate di una persona che non
 * riceve la ricevuta di quell'incasso. Le date arrivano dal server come `Y-m-d`.
 */
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';

export interface Compensazione {
    chiave: string;
    incasso_id: number;
    numero: string | null;
    data: string | null;
    persona: { id: number; nome: string };
    importo: number;
    quote: Array<{ rata: string; piano: string; unita: string | null; scadenza: string; importo: number }>;
    credito_di: string[];
    caso: 'senza_denaro' | 'fuori_dalla_ricevuta';
    ricevuta_di: string | null;
    ha_email: boolean;
    /** Gli indirizzi a cui va l'avviso per email: la primaria e la secondaria. */
    indirizzi?: string[];
    avvisata: boolean;
    ultimo: { canale: string; stato: string; quando: string | null; destinatario: string | null; errore: string | null } | null;
}

export const FRASE_SEZIONE = 'Il credito ha coperto le rate di queste persone senza un versamento nuovo, e la ricevuta dell\'incasso non le riguarda: '
    + 'nessun documento del programma glielo ha mandato. L\'avviso di compensazione sì.';

/** Quando ogni compensazione del periodo ha un avviso: stampato o mandato, non «arrivato» (il programma non lo sa). */
export const FRASE_TUTTE_AVVISATE = 'Tutte le compensazioni del periodo hanno un avviso stampato o mandato.';

/** Il bollo proposto dall'ultimo avviso: una scelta dell'amministratore, che si vede e si cambia. */
export const FRASE_BOLLO_RICORDATO = 'Lo stato del bollo è quello del tuo ultimo avviso in questo condominio: cambialo se per questi è diverso.';

/** Prima dell'invio: a chi vanno gli avvisi per email, con gli indirizzi. */
export function testoIndirizziAvvisi(righe: Array<Pick<Compensazione, 'persona' | 'ha_email' | 'indirizzi'>>): string | null {
    const con = righe.filter((c) => c.ha_email && (c.indirizzi ?? []).length > 0);

    return con.length ? `Per email: ${con.map((c) => `${c.persona.nome} (${(c.indirizzi ?? []).join(', ')})`).join('; ')}.` : null;
}

/** Gli avvisi oltre il tetto per volta: restano scelti, e si mandano con un altro clic. */
export function testoAvvisiRestanti(n: number): string | null {
    return n > 0 ? (n === 1 ? 'Ne resta 1: è ancora scelto, premi di nuovo per mandarlo.' : `Ne restano ${n}: sono ancora scelti, premi di nuovo per mandarli.`) : null;
}

/** Che cosa è successo, in una riga: di chi era il credito, quanto, e perché la ricevuta non basta. */
export function testoCompensazione(c: Compensazione): string {
    const credito = c.credito_di.length === 0 ? 'un credito' : c.credito_di.length === 1 && c.credito_di[0] === c.persona.nome ? 'il suo credito' : `il credito di ${c.credito_di.join(', ')}`;
    const perche = c.caso === 'senza_denaro' ? 'un incasso senza denaro, che non ha una ricevuta' : `la ricevuta di quell'incasso va a ${c.ricevuta_di ?? 'un\'altra persona'}`;

    return `${giorno(c.data)}, n. ${c.numero ?? ''}: ${credito} ha coperto ${euroTesto(c.importo)} delle rate di ${c.persona.nome} (${perche}).`;
}

/** L'ultimo avviso: stampato, in coda, partito («consegna non provata»), non partito con la frase. */
export function testoUltimoAvviso(u: Compensazione['ultimo']): string {
    if (!u) {
        return 'Nessun avviso';
    }
    const quando = u.quando ? `${giorno(u.quando.slice(0, 10))} alle ${u.quando.slice(11, 16)}` : '';
    switch (u.stato) {
        case 'stampata': return `Avviso stampato il ${quando}`;
        case 'in_coda': return `Avviso in coda dal ${quando}`;
        case 'inviata': return `Avviso inviato il ${quando}${u.destinatario ? ` a ${u.destinatario}` : ''}, consegna non provata`;
        case 'non_partita': return `Avviso non partito il ${quando}${u.errore ? `: ${u.errore}` : ''}`;
        default: return u.stato;
    }
}
