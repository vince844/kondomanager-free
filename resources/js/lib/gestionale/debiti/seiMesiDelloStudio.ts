// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * La lista dello studio dei sei mesi per agire, nell'Action inbox: la colonna «Sei mesi per agire», il cassetto con le tre sezioni («Da seguire adesso»,
 * «Rendiconto in assemblea», «Più avanti») e la card «Sei mesi», per tutti i condomini o per quello scelto, le date più vicine prima. Ogni condominio arriva
 * con una richiesta sua, in fila, dopo che la pagina si è aperta (`SeiMesiStudioController`, il riassunto del riquadro di `SeiMesiPerAgire`): le persone
 * aperte, le cifre, il compito e la risposta arrivano già decisi, gli stessi del riquadro del condominio. Qui si mettono insieme, si ordinano e si scrivono le
 * frasi.
 */
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';
import { piuGiorni } from '@/lib/giorno';
import { annoDi, type Colore } from '@/lib/gestionale/debiti/seiMesiPerAgire';

export interface CondominioBreve { id: number; nome: string }
export interface RispostaDelloStudio { stato: string | null; ricordami_il: string | null; frase: string | null }
export interface PersonaDelloStudio { anagrafica_id: number; nome: string; cifra: number }
export interface RigaDelloStudio {
    chiave: string;
    esercizio: { id: number; nome: string };
    data: string;
    giorni: number;
    colore: Colore;
    quante: number;
    cifra: number;
    persone: PersonaDelloStudio[];
    chiuse: { agito: number; dispensa: number };
    compito: boolean;
    compito_tornato: string | null;
    risposta: RispostaDelloStudio | null;
}
export interface RendicontoDelloStudio {
    chiave: string;
    esercizio: { id: number; nome: string };
    data: string;
    giorni: number;
    colore: Colore;
    stato: string;
    breve: string;
    frase: string;
    passo: 'apri' | 'approvazione' | null;
    url: string;
    compito: boolean;
    risposta: RispostaDelloStudio | null;
}
export interface CondominioDelloStudio {
    condominio: CondominioBreve;
    oggi: string;
    troppe: { frase: string } | null;
    righe: RigaDelloStudio[];
    rendiconti: RendicontoDelloStudio[];
}
/** Una riga della lista con il suo condominio e il giorno di oggi del server. */
export type ConIlCondominio<T> = T & { condominio: CondominioBreve; oggi: string };
/** I condomini arrivati e a che punto è la fila (`useSeiMesiDelloStudio`). */
export interface StatoDelloStudio { esiti: CondominioDelloStudio[]; totale: number; fatti: number; inCorso: boolean; daRiprovare: CondominioBreve[] }

export const TITOLO_STUDIO = 'Sei mesi per agire · tutti i condomini';
export const SOTTOTITOLO_STUDIO = 'Le date più vicine prima. Si calcolano a ogni apertura.';
export const FRASE_SUGGERIMENTO_STUDIO = 'Per le rate non versate l\'art. 1129 c. 9 c.c. dà all\'amministratore sei mesi dalla chiusura dell\'esercizio in cui sono '
    + 'comprese: una rata dell\'esercizio chiuso il 31/12/2025 ha la data del 30/06/2026. Una riga per esercizio di ogni condominio con qualcuno da seguire; '
    + 'la freccia apre le persone, «Apri le persone» porta al riquadro del condominio, dove scegli la prossima azione.';
export const FRASE_NON_VERSATO_STUDIO = 'Le rate di quell\'esercizio non versate dalle persone da seguire: per esempio € 3.240,00 di 4 persone. Chi ha la dispensa '
    + 'dell\'assemblea, o per cui hai registrato che hai agito, non è nella cifra.';
export const SOTTOTITOLO_RENDICONTI = 'Rendiconto in assemblea';
export const DESCRIZIONE_RENDICONTI = 'I rendiconti non approvati con la data entro 60 giorni o già passata: la data è la fine dell\'esercizio più 180 giorni.';
export const FRASE_RENDICONTI = 'La legge chiede di convocare l\'assemblea che approva il rendiconto entro 180 giorni dalla fine dell\'esercizio (art. 1130 n. 10 '
    + 'c.c.): per l\'esercizio chiuso il 31/12/2025, entro il 29/06/2026. Il programma sa quando il rendiconto è fermato e quando è approvato, non quando '
    + 'l\'assemblea è convocata.';
export const FRASE_VUOTO_STUDIO = 'Nessun condominio ha rate scadute da seguire per i sei mesi.';
/** La colonna e il cassetto dell'Action inbox. */
export const TITOLO_COLONNA = 'Sei mesi per agire';
export const DESCRIZIONE_CASSETTO = 'Una riga per esercizio di ogni condominio con qualcuno da seguire. Le date più vicine prima; si calcolano a ogni apertura.';
export const SEZIONE_ADESSO = 'Da seguire adesso';
export const SEZIONE_AVANTI = 'Più avanti';
export const FRASE_NIENTE_ADESSO = 'Nessuna rata da seguire entro 60 giorni.';
export const FRASE_NIENTE_ADESSO_CASSETTO = 'Nessuna data passata o entro 60 giorni.';
export const FRASE_NORMA_CARD = 'Art. 1129 e 1130 c.c.';
/** Le colonne delle righe dei sei mesi, e quelle del cassetto delle persone. */
export const COLONNE_STUDIO = ['Data', 'Condominio', 'Non versato', 'Stato', 'Prossima azione'] as const;
export const COLONNE_PERSONE_STUDIO = ['Persona', 'Non versato'] as const;

/** Fino a 8 righe si mostrano tutte; oltre, le prime 5 e la riga di fondo (niente scorrimento dentro la carta). */
export const TUTTE_FINO_A = 8;
export const MOSTRATE_OLTRE = 5;

const piu = (n: number, uno: string, tanti: string) => (n === 1 ? `1 ${uno}` : `${n} ${tanti}`);
export const testoCondomini = (n: number): string => piu(n, 'condominio', 'condomini');

/** «Calcolo i condomini: 12 su 40…». */
export const testoCaricamento = (fatti: number, di: number): string => `Calcolo i condomini: ${fatti} su ${di}…`;

/** Le righe di tutti i condomini arrivati, le date più vicine prima (prima le passate, dalla più vecchia); a pari data per nome del condominio. */
export function ordinaPerData<T extends { data: string; condominio: CondominioBreve; esercizio: { id: number } }>(righe: readonly T[]): T[] {
    return [...righe].sort((a, b) => a.data.localeCompare(b.data)
        || a.condominio.nome.localeCompare(b.condominio.nome, 'it')
        || a.condominio.id - b.condominio.id
        || a.esercizio.id - b.esercizio.id);
}

/** Il titolo del cassetto: «Sei mesi per agire · tutti i condomini», o con il nome del condominio scelto. */
export const titoloDelCassetto = (condominio: string | null): string => (condominio ? `${TITOLO_COLONNA} · ${condominio}` : TITOLO_STUDIO);

/** La riga sotto il titolo della colonna. */
export const descrizioneDellaColonna = (condominio: string | null): string => `${condominio ?? 'Tutti i condomini'}, le date più vicine prima.`;

/** Le righe da seguire adesso (la data passata o entro 60 giorni) e quelle più avanti. */
export const righeAdesso = <T extends { colore: string }>(righe: readonly T[]): T[] => righe.filter((r) => r.colore !== 'lontana');
export const righeAvanti = <T extends { colore: string }>(righe: readonly T[]): T[] => righe.filter((r) => r.colore === 'lontana');

/** Il numero della card «Sei mesi»: le righe da seguire adesso e i rendiconti da portare in assemblea, dei condomini arrivati. */
export function contoSeiMesi(esiti: readonly CondominioDelloStudio[]): { adesso: number; rendiconti: number } {
    return { adesso: righeAdesso(righeDelloStudio(esiti)).length, rendiconti: rendicontiDelloStudio(esiti).length };
}

/** «19 condomini più avanti, il primo il 30/06/2027»; vuota se non ce ne sono. Le righe sono già in ordine di data. */
export function testoPiuAvanti(avanti: ReadonlyArray<{ data: string; condominio: CondominioBreve }>): string {
    if (!avanti.length) return '';
    const n = quantiCondomini(avanti);

    return n === 1 ? `1 condominio più avanti, il ${giorno(avanti[0].data)}` : `${n} condomini più avanti, il primo il ${giorno(avanti[0].data)}`;
}

/** «Più avanti · 19 condomini». */
export const titoloPiuAvanti = (avanti: ReadonlyArray<{ condominio: CondominioBreve }>): string => `${SEZIONE_AVANTI} · ${testoCondomini(quantiCondomini(avanti))}`;

/** Le righe dei sei mesi di tutti i condomini arrivati, con il loro condominio, in ordine. */
export function righeDelloStudio(esiti: readonly CondominioDelloStudio[]): Array<ConIlCondominio<RigaDelloStudio>> {
    return ordinaPerData(esiti.flatMap((e) => e.righe.filter((r) => r.quante > 0).map((r) => ({ ...r, condominio: e.condominio, oggi: e.oggi }))));
}

/** I rendiconti in assemblea di tutti i condomini arrivati, in ordine. */
export function rendicontiDelloStudio(esiti: readonly CondominioDelloStudio[]): Array<ConIlCondominio<RendicontoDelloStudio>> {
    return ordinaPerData(esiti.flatMap((e) => e.rendiconti.map((r) => ({ ...r, condominio: e.condominio, oggi: e.oggi }))));
}

/** I condomini che hanno qualcosa nella lista: una riga, un rendiconto, o troppe rate per calcolare. */
export function condominiNellaLista(esiti: readonly CondominioDelloStudio[]): number {
    return esiti.filter((e) => e.troppe || e.rendiconti.length || e.righe.some((r) => r.quante > 0)).length;
}

/** La regola delle 8: fino a 8 tutte; oltre, le prime 5 e quelle che mancano (tutte, se l'amministratore ha chiesto «Mostra tutte»). */
export function finoAOtto<T>(righe: readonly T[], tutte = false): { mostrate: T[]; mancano: T[] } {
    if (tutte || righe.length <= TUTTE_FINO_A) return { mostrate: [...righe], mancano: [] };

    return { mostrate: righe.slice(0, MOSTRATE_OLTRE), mancano: righe.slice(MOSTRATE_OLTRE) };
}

/**
 * La regola delle 8 per due liste nella stessa card (le righe da seguire e i rendiconti della colonna dell'Action inbox): conta la card, non ogni lista.
 * Fino a 8 in tutto, tutte; oltre, 5 in tutto, prima la prima lista, con almeno 2 posti per la seconda se ne ha. Una lista sola fa come `finoAOtto`.
 */
export function finoAOttoInDue<A, B>(prime: readonly A[], seconde: readonly B[]): [{ mostrate: A[]; mancano: A[] }, { mostrate: B[]; mancano: B[] }] {
    if (prime.length + seconde.length <= TUTTE_FINO_A) return [{ mostrate: [...prime], mancano: [] }, { mostrate: [...seconde], mancano: [] }];
    const quanteSeconde = Math.min(seconde.length, Math.max(2, MOSTRATE_OLTRE - prime.length));
    const quantePrime = Math.min(prime.length, MOSTRATE_OLTRE - quanteSeconde);
    const resto = Math.min(seconde.length, MOSTRATE_OLTRE - quantePrime);

    return [
        { mostrate: prime.slice(0, quantePrime), mancano: prime.slice(quantePrime) },
        { mostrate: seconde.slice(0, resto), mancano: seconde.slice(resto) },
    ];
}

const quantiCondomini = (righe: ReadonlyArray<{ condominio: CondominioBreve }>) => new Set(righe.map((r) => r.condominio.id)).size;

/** «Qui le 5 più vicine; mancano 9 righe, in 7 condomini». */
export const testoOltreRighe = (mancano: ReadonlyArray<{ condominio: CondominioBreve }>): string =>
    `Qui le ${MOSTRATE_OLTRE} più vicine; mancano ${piu(mancano.length, 'riga', 'righe')}, in ${testoCondomini(quantiCondomini(mancano))}`;

/** «Qui i 5 più vicini; mancano 3 rendiconti, in 3 condomini». */
export const testoOltreRendiconti = (mancano: ReadonlyArray<{ condominio: CondominioBreve }>): string =>
    `Qui i ${MOSTRATE_OLTRE} più vicini; mancano ${piu(mancano.length, 'rendiconto', 'rendiconti')}, in ${testoCondomini(quantiCondomini(mancano))}`;

/** «4 persone». */
export const testoPersone = (n: number): string => piu(n, 'persona', 'persone');

/** «esercizio 2025», «rendiconto 2025». */
export const testoEsercizio = (r: { esercizio: { nome: string } }): string => `esercizio ${annoDi(r.esercizio.nome)}`;
export const testoRendiconto = (r: { esercizio: { nome: string } }): string => `rendiconto ${annoDi(r.esercizio.nome)}`;

/** Lo stato della riga, come nel riquadro: il compito nell'Inbox, «Non serve», il giorno del promemoria. Vuoto se non c'è niente da dire. */
export function statoDellaRiga(r: { compito: boolean; risposta: RispostaDelloStudio | null; oggi: string }): string {
    if (r.compito) return 'compito aperto';
    if (r.risposta?.stato === 'non_serve') return 'Non serve';
    if (r.risposta?.stato === 'ricordamelo' && r.risposta.ricordami_il && r.risposta.ricordami_il > r.oggi) return `Ti ricordo il ${giorno(r.risposta.ricordami_il)}`;

    return '';
}

/** I giorni prima della data in cui il compito arriva nell'Inbox (`SeiMesiPerAgire::GIORNI_COMPITO`). */
export const GIORNI_COMPITO = 60;

/** Per una riga ancora lontana, senza compito né risposta: il giorno in cui il compito arriva nell'Inbox, «compito dal 01/05/2027». */
export function testoCompitoDal(r: { data: string }): string {
    return `compito dal ${giorno(piuGiorni(r.data.slice(0, 10), -GIORNI_COMPITO))}`;
}

/** La prossima azione di un rendiconto, come nel riquadro: aprire la bozza, o registrare l'approvazione del fermato. */
export const testoAzioneRendiconto = (r: { passo: RendicontoDelloStudio['passo'] }): string =>
    (r.passo === 'approvazione' ? 'Registra l\'approvazione' : 'Apri il rendiconto');

/** Chi è chiuso nella riga, in una frase: «Hai registrato che hai agito per 1 persona; 1 è dispensata». Vuota se nessuno. */
export function testoChiuseStudio(c: { agito: number; dispensa: number }): string {
    if (!c.agito && !c.dispensa) return '';
    const dispensate = c.dispensa === 1 ? '1 è dispensata' : `${c.dispensa} sono dispensate`;
    if (!c.agito) return c.dispensa === 1 ? '1 persona è dispensata' : `${c.dispensa} persone sono dispensate`;

    return `Hai registrato che hai agito per ${testoPersone(c.agito)}` + (c.dispensa ? `; ${dispensate}` : '');
}

/** Il cassetto con più di 8 persone: «Qui le 5 con la cifra più alta; mancano 4 persone, € 820,00». */
export const testoOltrePersone = (mancano: ReadonlyArray<{ cifra: number }>): string =>
    `Qui le ${MOSTRATE_OLTRE} con la cifra più alta; mancano ${testoPersone(mancano.length)}, ${euroTesto(mancano.reduce((s, p) => s + p.cifra, 0))}`;

/** I condomini che non si sono potuti calcolare, per nome. */
export const testoNonCalcolati = (nomi: readonly string[]): string => `Non ho potuto calcolare ${testoCondomini(nomi.length)}: ${nomi.join(', ')}.`;

/**
 * Chiede i condomini uno alla volta, in fila, e dice dopo ognuno a che punto è. Un condominio che non c'è più (404) si salta; uno che non risponde va fra
 * quelli da riprovare. Con il segnale interrotto (la pagina lasciata) la fila si ferma.
 */
export async function chiediInFila(
    condomini: readonly CondominioBreve[],
    chiedi: (c: CondominioBreve, segnale: AbortSignal) => Promise<CondominioDelloStudio>,
    segnale: AbortSignal,
    dopoOgni: (esito: { condominio: CondominioBreve; dati: CondominioDelloStudio | null; nonRiuscito: boolean }) => void,
): Promise<void> {
    for (const c of condomini) {
        if (segnale.aborted) return;
        try {
            const dati = await chiedi(c, segnale);
            if (segnale.aborted) return;
            dopoOgni({ condominio: c, dati, nonRiuscito: false });
        } catch (e) {
            if (segnale.aborted) return;
            const stato = (e as { response?: { status?: number } })?.response?.status;
            dopoOgni({ condominio: c, dati: null, nonRiuscito: stato !== 404 });
        }
    }
}

export { euroTesto, giorno };
