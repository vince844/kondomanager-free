// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import type { NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import type { VerbaleDellaPagina } from '@/types/verbale';
import type { EsitoDiUnGesto, StatoDellaCoda } from './coda';
import type { VoceInCoda } from './deposito';
import { giorno, ora } from './sala';
import { nomeDelDispositivo } from './striscia';

/**
 * Il verbale visto dal dispositivo, e le parole della tenuta senza rete (le scene 3 e 4 della bozza approvata dell'editor, decisioni 514, 515, 519):
 *
 * - {@link vistaDelleParti}: il testo di ogni parte come lo vede chi scrive: quello ancora in memoria, poi l'ultimo gesto in coda (scritto su questo
 *   computer e non ancora arrivato), poi l'ultimo arrivato dalle sue risposte, poi quello del server;
 * - {@link scrittaDelSalvataggio} (N4): «Salvato alle 20:14 · tutto sul server», «Salvataggio… · pochi secondi», «Non salvato: senza connessione. Lo tengo
 *   qui, nel browser. · 3 modifiche in attesa», «Di nuovo in linea: salvato alle 20:19 · le 3 modifiche sono partite»;
 * - {@link fraseDelLavoroRitrovato} (N5): «Ho ritrovato 3 modifiche che non erano partite, e le ho mandate. Del 15/12/2026, dalle 20:16 alle 20:18: due
 *   correzioni alla discussione del punto 4. Arrivate alle 20:31.»;
 * - {@link dueVersioniAperte} (N6): «La discussione del punto 4 è stata cambiata anche dal tablet di Marco Galli, alle 20:24. Non sovrascrivo niente: …».
 */

/** Una modifica del testo partita (o da partire) da questo computer: la parte e l'ora dell'ultima lettera. */
export interface ModificaDelTesto {
    parte: string;
    il: string;
}

/** Le modifiche non ancora arrivate, tenute nel deposito: sopravvivono a uno spegnimento, e dicono il lavoro ritrovato. */
export interface ModificheInAttesa {
    modifiche: ModificaDelTesto[];
    /** La coda è stata senza rete (o ferma) da quando aspettano: quando arrivano, la scritta dice «Di nuovo in linea». */
    ferma: boolean;
}

/** Il testo di una parte come lo vede chi scrive. */
export interface ParteVista {
    documento: NodoDelVerbale;
    /** La revisione del server da cui parte il testo (la `base` del prossimo gesto). */
    revisione: number;
    /** Il testo è su questo computer e non ancora arrivato al server. */
    qui: boolean;
}

type PartiDelServer = Record<string, { documento: NodoDelVerbale; revisione: number }>;

/**
 * Il testo di ogni parte: in memoria > l'ultimo gesto in coda > l'ultimo arrivato (le risposte) > il server. La revisione è quella da cui parte il testo:
 * per ciò che è su questo computer, la `base` del suo gesto; per ciò che è arrivato, la revisione della risposta, o quella del server se è più nuova.
 */
export function vistaDelleParti(server: PartiDelServer, inCoda: VoceInCoda[], arrivati: Record<string, { documento: NodoDelVerbale; revisione: number }>,
    inMemoria: Record<string, NodoDelVerbale> = {}): Record<string, ParteVista> {
    const vista: Record<string, ParteVista> = {};
    for (const [parte, p] of Object.entries(server)) vista[parte] = { documento: p.documento, revisione: p.revisione, qui: false };
    for (const [parte, a] of Object.entries(arrivati)) {
        if (!vista[parte] || a.revisione >= vista[parte].revisione) vista[parte] = { documento: a.documento, revisione: a.revisione, qui: false };
    }
    for (const v of [...inCoda].sort((x, y) => x.ordine - y.ordine)) {
        if (v.tipo !== 'testo') continue;
        const d = v.dati as { parte?: string; documento?: NodoDelVerbale };
        if (!d.parte || !d.documento) continue;
        // Il testo scritto qui parte dalla revisione del suo gesto, mai da quella del server: un'altra scrittura arrivata intanto non c'è dentro.
        vista[d.parte] = { documento: d.documento, revisione: Number(v.base ?? 0), qui: true };
    }
    for (const [parte, documento] of Object.entries(inMemoria)) {
        vista[parte] = { documento, revisione: vista[parte]?.revisione ?? 0, qui: true };
    }

    return vista;
}

/** I numeri piccoli in parole, come si dicono: «una», «due»… «dieci»; dopo, le cifre. */
export function quanteInParole(n: number): string {
    return ['zero', 'una', 'due', 'tre', 'quattro', 'cinque', 'sei', 'sette', 'otto', 'nove', 'dieci'][n] ?? String(n);
}

/** «la discussione del punto 4», «il verbale». */
export function nomeDellaParte(parte: string, punti: { punto_id: number; numero: number }[] = []): string {
    const id = /^punto:(\d+)$/.exec(parte)?.[1];
    if (id === undefined) return 'il verbale';
    const numero = punti.find((p) => p.punto_id === Number(id))?.numero;

    return numero !== undefined ? `la discussione del punto ${numero}` : 'la discussione di un punto';
}

/** «due correzioni alla discussione del punto 4», «una correzione al verbale». */
export function correzioni(parte: string, quante: number, punti: { punto_id: number; numero: number }[] = []): string {
    const nome = nomeDellaParte(parte, punti);
    const dove = nome === 'il verbale' ? 'al verbale' : 'alla ' + nome.slice(3);

    return `${quanteInParole(quante)} ${quante === 1 ? 'correzione' : 'correzioni'} ${dove}`;
}

/** «a, b e c». */
function elenco(voci: string[]): string {
    return voci.length <= 1 ? (voci[0] ?? '') : voci.slice(0, -1).join(', ') + ' e ' + voci[voci.length - 1];
}

const modifiche = (n: number) => (n === 1 ? '1 modifica' : `${n} modifiche`);

export type StatoDelSalvataggio = 'salvato' | 'salvataggio' | 'senza_rete' | 'di_nuovo_in_linea' | 'sessione_scaduta' | 'non_puoi';

export interface ScrittaDelSalvataggio {
    stato: StatoDelSalvataggio;
    titolo: string;
    dettaglio: string | null;
}

/**
 * La scritta accanto agli strumenti (N4), uno stato alla volta: la sessione scaduta e il permesso prima di tutto (il testo resta qui finché non si rientra);
 * poi senza rete (o il server che non risponde) con le modifiche in attesa; poi il salvataggio in corso; poi «Di nuovo in linea» dopo una caduta; poi
 * «Salvato alle …».
 */
export function scrittaDelSalvataggio(x: {
    stato: StatoDellaCoda;
    serverFermo: boolean;
    /** C'è un testo scritto che non è ancora partito, o in coda. */
    daMandare: boolean;
    /** Le modifiche in attesa (quelle che la scritta conta). */
    inAttesa: number;
    /** L'ora dell'ultimo salvataggio arrivato al server. */
    salvatoAlle: string | null;
    /** Dopo una caduta, le modifiche arrivate quando la rete è tornata. */
    partite: { quante: number; alle: string } | null;
    fuso?: string;
}): ScrittaDelSalvataggio {
    const fuso = x.fuso ?? 'Europe/Rome';
    const attesa = x.inAttesa > 0 ? `${modifiche(x.inAttesa)} in attesa` : null;
    if (x.stato === 'sessione_scaduta') {
        return { stato: 'sessione_scaduta', titolo: 'Non salvato: la sessione è scaduta. Lo tengo qui, nel browser.', dettaglio: attesa ? `${attesa} · rientra` : 'rientra' };
    }
    if (x.stato === 'non_puoi') {
        return { stato: 'non_puoi', titolo: 'Non salvato: per scrivere il verbale serve «Gestisce le assemblee». Lo tengo qui, nel browser.', dettaglio: attesa };
    }
    if (x.stato === 'senza_rete') {
        return { stato: 'senza_rete', titolo: x.serverFermo ? 'Non salvato: il server non risponde. Lo tengo qui, nel browser.'
            : 'Non salvato: senza connessione. Lo tengo qui, nel browser.', dettaglio: attesa };
    }
    if (x.stato === 'invio' || x.daMandare) return { stato: 'salvataggio', titolo: 'Salvataggio…', dettaglio: 'pochi secondi' };
    if (x.partite) {
        return { stato: 'di_nuovo_in_linea', titolo: `Di nuovo in linea: salvato alle ${ora(x.partite.alle, fuso)}`,
            dettaglio: x.partite.quante === 1 ? 'la modifica è partita' : `le ${x.partite.quante} modifiche sono partite` };
    }

    return x.salvatoAlle ? { stato: 'salvato', titolo: `Salvato alle ${ora(x.salvatoAlle, fuso)}`, dettaglio: 'tutto sul server' }
        : { stato: 'salvato', titolo: 'Tutto sul server', dettaglio: null };
}

/** Il lavoro ritrovato del verbale: le modifiche di una pagina di prima (il computer riacceso, il browser riaperto), e che cosa ne è stato. */
export interface LavoroRitrovatoDelVerbale {
    modifiche: ModificaDelTesto[];
    /** Tutte hanno avuto la risposta. */
    finito: boolean;
    arrivate_alle: string | null;
    /** Le risposte ai gesti ritrovati. */
    esiti: EsitoDiUnGesto[];
}

/**
 * Le modifiche ritrovate: quelle tenute nel deposito, più una per ogni parte che ha un gesto ritrovato e nessuna modifica tenuta (la pagina chiusa prima
 * della pausa: il testo era sul disco, la modifica non ancora contata).
 */
export function modificheRitrovate(tenute: ModificaDelTesto[], voci: VoceInCoda[]): ModificaDelTesto[] {
    const conte = new Set(tenute.map((m) => m.parte));
    const piu = voci.filter((v) => v.tipo === 'testo' && typeof (v.dati as { parte?: unknown }).parte === 'string' && !conte.has((v.dati as { parte: string }).parte))
        .map((v) => ({ parte: (v.dati as { parte: string }).parte, il: v.segnato_il }));
    const viste = new Set<string>();

    return [...tenute, ...piu.filter((m) => (viste.has(m.parte) ? false : (viste.add(m.parte), true)))].sort((a, b) => a.il.localeCompare(b.il));
}

/**
 * Le frasi del lavoro ritrovato (N5), come la scena 4: «Ho ritrovato 3 modifiche che non erano partite, e le ho mandate.», «Del 15/12/2026, dalle 20:16 alle
 * 20:18: due correzioni alla discussione del punto 4.», «Arrivate alle 20:31.»; prima che partano, «partono da sole quando torna la rete»; con due versioni,
 * quale parte aspetta la scelta; con un rifiuto, il perché.
 */
export function fraseDelLavoroRitrovato(r: LavoroRitrovatoDelVerbale, punti: { punto_id: number; numero: number }[] = [], fuso = 'Europe/Rome'):
    { titolo: string; righe: string[] } {
    const quante = r.modifiche.length;
    const che = quante === 1 ? '1 modifica che non era partita' : `${quante} modifiche che non erano partite`;
    const ore = r.modifiche.map((m) => m.il).sort();
    const dal = ore[0];
    const al = ore[ore.length - 1];
    const quando = dal === undefined ? '' : `Del ${giorno(dal, fuso)}, ${ora(dal, fuso) === ora(al, fuso) ? 'alle ' + ora(dal, fuso) : 'dalle ' + ora(dal, fuso) + ' alle ' + ora(al, fuso)}`;
    const perParte = new Map<string, number>();
    for (const m of r.modifiche) perParte.set(m.parte, (perParte.get(m.parte) ?? 0) + 1);
    const cosa = elenco([...perParte].map(([parte, n]) => correzioni(parte, n, punti)));
    const righe = quando !== '' ? [`${quando}: ${cosa}.`] : [];
    if (!r.finito) {
        return { titolo: `Ho ritrovato ${che}: ${quante === 1 ? 'parte da sola' : 'partono da sole'} quando torna la rete.`, righe };
    }
    for (const e of r.esiti.filter((x) => x.esito === 'in_conflitto')) {
        const nome = nomeDellaParte(String((e.voce.dati as { parte?: string }).parte ?? ''), punti);
        righe.push(`${nome.charAt(0).toUpperCase()}${nome.slice(1)} ha due versioni: non sovrascrivo niente, scegli tu.`);
    }
    for (const e of r.esiti.filter((x) => x.esito === 'rifiutato')) {
        const nome = nomeDellaParte(String((e.voce.dati as { parte?: string }).parte ?? ''), punti);
        righe.push(`${nome.charAt(0).toUpperCase()}${nome.slice(1)}: ${e.messaggio ?? 'non accettata'} Il testo resta su questo computer.`);
    }
    if (r.arrivate_alle) righe.push(`${quante === 1 ? 'Arrivata' : 'Arrivate'} alle ${ora(r.arrivate_alle, fuso)}.`);

    return { titolo: `Ho ritrovato ${che}, e ${quante === 1 ? 'l\'ho mandata' : 'le ho mandate'}.`, righe };
}

/** Due versioni aperte di una parte (N6): la versione arrivata e quella di adesso, con chi ha scritto e quando, e la frase. */
export interface DueVersioniDelTesto {
    parte: string;
    /** «La discussione del punto 4». */
    nome: string;
    /** La versione in attesa (da scegliere con `questa`). */
    versione_id: number;
    arrivata: { documento: NodoDelVerbale; dispositivo: string | null; il: string | null; daQui: boolean };
    /** Il testo di adesso sul server (da tenere con `quella`). */
    adesso: { documento: NodoDelVerbale; dispositivo: string | null; il: string | null; daQui: boolean };
    /** «La discussione del punto 4 è stata cambiata anche dal tablet di Marco Galli, alle 20:24. Non sovrascrivo niente: …» */
    frase: string;
}

const NON_SOVRASCRIVO = 'Non sovrascrivo niente: le due versioni restano, e scegli tu quale tenere, o le unisci.';

/** «dal tablet di Marco Galli», «dal programma». */
function dalDispositivo(dispositivo: string | null): string {
    const nome = nomeDelDispositivo(dispositivo ?? '');

    return nome === '' ? 'da un altro dispositivo' : `dal ${nome}`;
}

/**
 * Le scelte aperte fra due versioni del verbale, dalla prop della pagina: per ognuna la versione arrivata (in attesa) e il testo di adesso, e la frase detta
 * da questo dispositivo («cambiata anche dal tablet…»: l'altro dispositivo è quello che non è questo).
 */
export function dueVersioniAperte(verbale: VerbaleDellaPagina | null, mioDispositivo: string, fuso = 'Europe/Rome'): DueVersioniDelTesto[] {
    if (!verbale) return [];

    return verbale.in_attesa.filter((v) => v.attesa === 'due_versioni' && v.scelta === null).map((v) => {
        const adesso = verbale.parti[v.parte];
        const arrivataQui = v.dispositivo === mioDispositivo;
        const altro: { dispositivo: string | null; il: string | null } = arrivataQui ? { dispositivo: adesso?.dispositivo ?? null, il: adesso?.scritta_il ?? null }
            : { dispositivo: v.dispositivo, il: v.segnata_il };
        const nome = (v.nome_della_parte || 'il verbale').replace(/^./, (c) => c.toUpperCase());

        return {
            parte: v.parte,
            nome,
            versione_id: v.id,
            arrivata: { documento: v.documento, dispositivo: v.dispositivo, il: v.segnata_il, daQui: arrivataQui },
            adesso: { documento: adesso?.documento ?? { type: 'doc' }, dispositivo: adesso?.dispositivo ?? null, il: adesso?.scritta_il ?? null,
                daQui: (adesso?.dispositivo ?? null) === mioDispositivo },
            frase: `${nome} è stata cambiata anche ${dalDispositivo(altro.dispositivo)}${altro.il ? ', alle ' + ora(altro.il, fuso) : ''}. ${NON_SOVRASCRIVO}`,
        };
    });
}

/** Una versione è «di questo computer», «dal tablet di Marco Galli, alle 20:24». */
export function chiEQuando(x: { dispositivo: string | null; il: string | null; daQui: boolean }, fuso = 'Europe/Rome'): string {
    const chi = x.daQui ? 'Da questo computer' : dalDispositivo(x.dispositivo).replace(/^d/, 'D');

    return chi + (x.il ? `, alle ${ora(x.il, fuso)}` : '');
}
