// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I casi speciali del registro delle modifiche (1.12.0-beta.13, §10 e §11 del progetto, decisioni 518 e 550): le parole della durata delle righe, della
 * sospensione della cancellazione per condominio e della finestra dell'oscuramento, come le dice la bozza approvata (scena 4). Le cifre e le date arrivano
 * dal server già all'italiana («12/03/2035»); qui si compongono le frasi, e le provano i test in `node`.
 */

/** Una sospensione attiva della cancellazione, di un condominio che c'è o che è stato eliminato (il nome copiato dalla riga). */
export interface SospensioneAttiva {
    condominio_id: number;
    nome: string;
    /** «03/03/2035». */
    dal: string;
    motivo: string | null;
    da: string;
    eliminato: boolean;
}

export interface StatoConservazione {
    anni: number;
    scelte: number[];
    /** «10 anni». */
    adesso: string;
    /** «le righe del 2026 si tolgono da sole nel 2036». */
    frase: string;
    puo_cambiare: boolean;
    /** Con il condominio nella richiesta: la sua sospensione, se c'è. */
    sospensione?: { dal: string; motivo: string | null } | null;
    /** Le sospensioni attive di tutto lo studio (decisione 558: una ferma la cancellazione per tutti). */
    sospensioni?: SospensioneAttiva[];
}

export interface StatoSospensione {
    sospesa: boolean;
    /** «12/03/2035». */
    dal: string | null;
    motivo: string | null;
    da: string | null;
    puo_cambiare: boolean;
}

export const ETICHETTA_DURATA = 'Per quanto tempo si tengono le righe';
export const TITOLO_CARTA_DURATA = 'Il registro delle modifiche';
export const DESCRIZIONE_CARTA_DURATA = 'Impostazioni dello studio. Il registro non si spegne: un registro spento manca proprio il giorno in cui serve.';

/** Le cifre all'italiana con il punto delle migliaia anche a quattro cifre («1.250»: il formato italiano del browser scriverebbe «1250»). */
export function cifra(n: number): string {
    return String(Math.trunc(n)).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

export function testoDegliAnni(anni: number): string {
    return `${anni} anni`;
}

/**
 * La frase delle sospensioni (decisione 558): «La cancellazione è ferma per tutto lo studio finché è sospesa Via Roma 12 (dal 03/03/2035: causa Rossi).»;
 * con più condomini «… finché sono sospese Via Roma 12 (…) e Corso Italia 3 (…).»; un condominio eliminato lo dice fra parentesi. Vuota senza sospensioni.
 */
export function fraseDelleSospensioni(sospensioni: SospensioneAttiva[]): string {
    if (!sospensioni.length) {
        return '';
    }
    const una = (s: SospensioneAttiva) => `${s.nome} (${s.eliminato ? 'eliminato, ' : ''}dal ${s.dal}${s.motivo ? `: ${s.motivo}` : ''})`;
    const nomi = sospensioni.map(una);
    const elenco = nomi.length === 1 ? nomi[0] : `${nomi.slice(0, -1).join(', ')} e ${nomi[nomi.length - 1]}`;

    return `La cancellazione è ferma per tutto lo studio finché ${nomi.length === 1 ? 'è sospesa' : 'sono sospese'} ${elenco}.`;
}

/** La riga sotto la scelta, con «Adesso: …» (standard 13) e, se ci sono, le sospensioni che fermano la cancellazione (decisione 558). */
export function rigaAdesso(stato: Pick<StatoConservazione, 'adesso' | 'sospensioni'>): string {
    const sospensioni = fraseDelleSospensioni(stato.sospensioni ?? []);

    return `Come le scritture e i documenti giustificativi del condominio (art. 1130-bis c.c.). Adesso: ${stato.adesso}.${sospensioni ? ` ${sospensioni}` : ''}`;
}

/** L'esempio della «i» accanto alla scelta: «Esempio: con 10 anni, le righe del 2026 si tolgono da sole nel 2036.» */
export function esempioDellaDurata(anni: number, annoDiOggi: number): string {
    return `Esempio: con ${testoDegliAnni(anni)}, le righe del ${annoDiOggi} si tolgono da sole nel ${annoDiOggi + anni}.`;
}

/** L'anno di oggi letto dalla frase del server («le righe del 2026 …»), così l'esempio usa lo stesso anno del server (il fuso di Roma). */
export function annoDellaFrase(frase: string): number {
    const trovato = /le righe del (\d{4})/.exec(frase);

    return trovato ? Number(trovato[1]) : new Date().getFullYear();
}

/** «Cancellazione sospesa dal 12/03/2035: causa Rossi, tribunale di Napoli» (la decisione 550, lo stato detto a parole). */
export function testoSospesa(s: { dal: string | null; motivo: string | null }): string {
    const motivo = s.motivo ? `: ${s.motivo}` : '';

    return `Cancellazione sospesa dal ${s.dal ?? '—'}${motivo}`;
}

/** La frase di quante righe ha la persona nel registro: «18 righe con i suoi dati, dal 01/11/2026 al 15/01/2027». */
export function testoRigheDellaPersona(quante: number, dal: string | null, al: string | null): string {
    if (quante === 0) {
        return 'Nessuna riga del registro ha ancora i suoi dati: non c\'è niente da oscurare.';
    }
    const righe = quante === 1 ? '1 riga con i suoi dati' : `${cifra(quante)} righe con i suoi dati`;
    if (!dal) {
        return righe;
    }

    return dal === al ? `${righe}, del ${dal}` : `${righe}, dal ${dal} al ${al}`;
}

/** Il pulsante della finestra: «Oscura 18 righe», «Oscura 1 riga». */
export function testoPulsanteOscura(quante: number): string {
    return quante === 1 ? 'Oscura 1 riga' : `Oscura ${cifra(quante)} righe`;
}

/** L'esito dopo l'oscuramento: «Fatto: 18 righe oscurate il 12/03/2027.» */
export function testoEsitoOscuramento(quante: number, giorno: string): string {
    return quante === 1 ? `Fatto: 1 riga oscurata il ${giorno}.` : `Fatto: ${cifra(quante)} righe oscurate il ${giorno}.`;
}

/** Il messaggio di un errore della richiesta, con le parole dell'amministratore. */
export function messaggioDiErrore(e: unknown, campo?: string): string {
    const risposta = (e as { response?: { status?: number; data?: { errors?: Record<string, string[]>; message?: string } } })?.response;
    if (risposta?.status === 422) {
        const errori = risposta.data?.errors ?? {};
        const primo = (campo && errori[campo]?.[0]) || Object.values(errori)[0]?.[0];

        return primo ?? 'Controlla i dati e riprova.';
    }
    if (risposta?.status === 403) {
        return 'Lo può fare solo l\'amministratore.';
    }
    if (risposta?.status === 401 || risposta?.status === 419) {
        return 'La sessione è finita: ricarica la pagina e rientra.';
    }

    return 'Non è andata: riprova fra poco.';
}
