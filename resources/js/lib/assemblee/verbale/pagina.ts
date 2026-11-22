// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le parole della pagina «Il verbale» e del registro dei verbali: i punti in parole («punti 4–6»), i trenta giorni di ognuno (art. 1137 c. 2 c.c., con il
 * calcolo prudente del server: dal giorno dopo, agosto non conta, la festa al primo giorno lavorativo), i gruppi della lista di chi riceve il verbale, chi ha
 * la copia di prima dopo «Sostituisci la copia», le discussioni già scritte, il giorno entro cui registrare, chi scrive.
 */
import { giornoItaliano } from '@/lib/giorno';
import { ora as oraDiRoma } from '@/lib/senzaRete/sala';
import type { NodoDelVerbale } from './struttura';
import type { DestinatarioDelVerbale, ParteDelVerbale, StatoDiChiScrive, TrasmissioneDelVerbale } from '@/types/verbale';

/** La frase accanto a ogni «fino al»: il conto è quello prudente. */
export const CALCOLO_PRUDENTE = 'calcolato in modo prudente';

/** I giorni fra la chiusura della sala e il compito «Registrare il verbale entro il …» (`CompitiDelVerbale::GIORNI`). */
export const GIORNI_PER_REGISTRARE = 7;

export const ora = (istante: string | null | undefined) => (istante ? oraDiRoma(istante) : '');
export const giorno = (g: string | null | undefined) => (g ? giornoItaliano(g.slice(0, 10)) : '');

/** «punto 1», «punti 2 e 5», «punti 4–6», «punti 1, 3 e 4». */
export function puntiInParole(punti: number[]): string {
    const p = [...new Set(punti)].sort((a, b) => a - b);
    if (p.length === 0) return '';
    if (p.length === 1) return `punto ${p[0]}`;
    if (p.length >= 3 && p.every((n, i) => i === 0 || n === p[i - 1] + 1)) return `punti ${p[0]}–${p[p.length - 1]}`;
    return `punti ${p.slice(0, -1).join(', ')} e ${p[p.length - 1]}`;
}

const MAIUSCOLA = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

/**
 * Da quale giorno contano i trenta giorni di chi era assente, quando non è la consegna: « (dal ritiro del 28/12/2026)» per la raccomandata in giacenza poi
 * ritirata, « (dalla compiuta giacenza, il 28/12/2026)» per quella non ritirata (l'avviso più dieci giorni). Come `DestinatariDelVerbale::daQuando()`.
 */
export function daQuando(come: string | null | undefined, dal: string | null | undefined): string {
    if (!dal) return '';
    if (come === 'ritiro') return ` (dal ritiro del ${giorno(dal)})`;
    if (come === 'compiuta_giacenza') return ` (dalla compiuta giacenza, il ${giorno(dal)})`;
    return '';
}

/**
 * Le righe dei trenta giorni di una persona, come si leggono sotto «Può impugnare»: per i punti in cui era assente «dalla consegna» (o la data), per gli
 * altri dalla delibera; i favorevoli niente. Con la virgola: «punti 1–3, fino al 21/12/2026», «punto 4, contrario, fino al 14/01/2027».
 */
export function righeDeiTrentaGiorni(p: DestinatarioDelVerbale): string[] {
    const righe = p.termini.map((t) => {
        const come = { assente: '', tolto: 'tolto dalla lista, conta astenuto, ', contrario: 'contrario, ', astenuto: 'astenuto, ', non_votato: 'non ha votato, conta astenuto, ' }[t.perche] ?? '';
        const quando = t.fino_al ? `fino al ${giorno(t.fino_al)}${daQuando(t.dal_come, t.dal)}` : 'dalla consegna';
        return `${puntiInParole(t.punti)}, ${come}${quando}`;
    });
    const voti = Array.isArray(p.voti) ? {} : p.voti;
    const favorevoli = Object.entries(voti).filter(([, v]) => v === 'favorevole').map(([n]) => Number(n));
    if (favorevoli.length) righe.push(`${puntiInParole(favorevoli)}, favorevole, nessun termine`);
    return righe;
}

export interface GruppiDeiDestinatari {
    mai_entrati: DestinatarioDelVerbale[];
    fuori: DestinatarioDelVerbale[];
    aggiunti: DestinatarioDelVerbale[];
    tutti: DestinatarioDelVerbale[];
    cera: DestinatarioDelVerbale[];
    tolti: DestinatarioDelVerbale[];
}

/** La lista proposta in gruppi (scena 6): chi riceve, chi è stato tolto con il perché, chi c'era (con i suoi trenta giorni dalla delibera). */
export function gruppiDeiDestinatari(t: TrasmissioneDelVerbale): GruppiDeiDestinatari {
    const g: GruppiDeiDestinatari = { mai_entrati: [], fuori: [], aggiunti: [], tutti: [], cera: [], tolti: [] };
    for (const p of t.persone) {
        if (p.tolto && !p.riceve) g.tolti.push(p);
        else if (!p.riceve) g.cera.push(p);
        else g[p.gruppo === 'cera' ? 'aggiunti' : p.gruppo].push(p);
    }
    return g;
}

/**
 * Chi ha già la copia di prima: l'invio è partito prima che la copia fosse sostituita (`sostituitaIl`). Sono quelli a cui rimandarla («N hanno la copia
 * di prima: rimandala»).
 */
export function conLaCopiaDiPrima(t: TrasmissioneDelVerbale | null, sostituitaIl: string | null | undefined): DestinatarioDelVerbale[] {
    if (!t || !sostituitaIl) return [];
    const quando = Date.parse(sostituitaIl);
    return t.persone.filter((p) => {
        const creato = (p.invio as { creato_il?: string | null } | null)?.creato_il;
        return p.riceve && p.invio?.spedito && creato && Date.parse(creato) < quando;
    });
}

/**
 * Le lettere stampate con la copia di prima e non ancora spedite: la copia è stata sostituita dopo la stampa. Si ristampano prima di spedirle («4 lettere
 * stampate con la copia di prima: ristampale prima di spedirle»); «Registra la spedizione» le ferma con la stessa frase.
 */
export function stampateConLaCopiaDiPrima(t: TrasmissioneDelVerbale | null, sostituitaIl: string | null | undefined): DestinatarioDelVerbale[] {
    if (!t || !sostituitaIl) return [];
    const quando = Date.parse(sostituitaIl);
    return t.persone.filter((p) => {
        const creato = (p.invio as { creato_il?: string | null } | null)?.creato_il;
        return p.riceve && p.invio?.stato === 'stampata' && creato && Date.parse(creato) < quando;
    });
}

/** Le lettere stampate e non ancora spedite: sostituita la copia, andranno ristampate. */
export function lettereSoloStampate(t: TrasmissioneDelVerbale | null): DestinatarioDelVerbale[] {
    return (t?.persone ?? []).filter((p) => p.riceve && p.invio?.stato === 'stampata' && !p.invio?.spedito);
}

/** «le discussioni di 4 punti su 8 già scritte». */
export function discussioniScritte(parti: Record<string, { caratteri: number }>, punti: number): string {
    const scritte = Object.entries(parti).filter(([k, v]) => k.startsWith('punto:') && v.caratteri > 0).length;
    if (scritte === 0) return `nessuna discussione scritta, su ${punti} punti`;
    return `le discussioni di ${scritte === 1 ? '1 punto' : `${scritte} punti`} su ${punti} già scritte`;
}

/** Il giorno entro cui registrare il verbale: sette giorni dopo la chiusura, «22/12». */
export function entroIl(chiusaIl: string | null | undefined): string {
    if (!chiusaIl) return '';
    const g = new Date(Date.parse(chiusaIl));
    const roma = new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Rome', year: 'numeric', month: '2-digit', day: '2-digit' }).format(g);
    const d = new Date(`${roma}T12:00:00Z`);
    d.setUTCDate(d.getUTCDate() + GIORNI_PER_REGISTRARE);
    return `${String(d.getUTCDate()).padStart(2, '0')}/${String(d.getUTCMonth() + 1).padStart(2, '0')}`;
}

/** Il numero del punto di una parte («punto:12» → il numero del punto con quell'id). */
export function numeroDellaParte(parte: ParteDelVerbale, punti: { punto_id: number; numero: number }[]): number | null {
    const id = /^punto:(\d+)$/.exec(parte)?.[1];
    return id === undefined ? null : punti.find((p) => p.punto_id === Number(id))?.numero ?? null;
}

/** La frase di chi scrive, per chi legge: «Andrea Costa sta scrivendo il verbale.», «Andrea Costa scrive il punto 4.», «Il verbale è aperto anche da …». */
export function fraseDiChiScrive(s: StatoDiChiScrive | null | undefined, numeroDelPunto: number | null = null): string | null {
    if (!s) return null;
    if (s.scrive && !s.scrive.sono_io) {
        return numeroDelPunto !== null ? `${s.scrive.nome} scrive il punto ${numeroDelPunto}.` : `${s.scrive.nome} sta scrivendo il verbale.`;
    }
    const altri = s.aperto_da.map((a) => a.nome).filter((n, i, tutti) => n && tutti.indexOf(n) === i);
    if (altri.length === 0) return null;
    return `Il verbale è aperto anche da ${altri.length === 1 ? altri[0] : `${altri.slice(0, -1).join(', ')} e ${altri[altri.length - 1]}`}.`;
}

/** Il nome del mezzo con la maiuscola, per le card al telefono. */
export const parolaDelMezzo = (p: DestinatarioDelVerbale) => MAIUSCOLA(p.mezzo.parola);

/** Il mezzo nella forma della consegna della convocazione (`a_mano`). */
export const mezzoDellaConsegna = (m: string) => (m === 'mano' ? 'a_mano' : m);

/**
 * «Rimetti 506,40»: nella parte «Dati della sala» con la chiave della cifra, la `occorrenza`-esima volta che il testo dice la cifra sbagliata diventa quella
 * dei voti registrati. Il resto del documento non cambia; una chiave che non c'è lascia il documento com'è.
 */
export function rimettiLaCifra(doc: NodoDelVerbale, c: { chiave: string; occorrenza: number; nel_testo: string; adesso: string }): NodoDelVerbale {
    let vista = 0;
    let fatto = false;
    const nelBlocco = (n: NodoDelVerbale): NodoDelVerbale => {
        if (fatto) return n;
        if (n.type === 'text' && n.text) {
            let t = n.text;
            let da = 0;
            for (;;) {
                const i = t.indexOf(c.nel_testo, da);
                if (i < 0) break;
                vista += 1;
                if (vista === c.occorrenza) {
                    t = t.slice(0, i) + c.adesso + t.slice(i + c.nel_testo.length);
                    fatto = true;
                    break;
                }
                da = i + c.nel_testo.length;
            }
            return t === n.text ? n : { ...n, text: t };
        }
        return n.content ? { ...n, content: n.content.map(nelBlocco) } : n;
    };
    if (!doc.content) return doc;
    return { ...doc, content: doc.content.map((b) => (b.type === 'datiDellaSala' && b.attrs?.chiave === c.chiave ? nelBlocco(b) : b)) };
}
