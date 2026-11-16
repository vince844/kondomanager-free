// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Le parole e i conti della finestra del fascicolo per il legale. L'anteprima arriva dal server una volta, all'apertura (`FascicoloLegale::anteprima`): ogni
 * pezzo, fatto e mancanza porta i suoi segni, la parte (`parte`, null: sempre) e le rate (`rate`, null: sempre; altrimenti c'è se almeno una è scelta). La
 * pagina filtra con questi segni, la stessa regola del server (`FascicoloLegale::dentro`): nessuna seconda regola, nessuna lettura in più a ogni spunta. Le
 * cifre sono quelle delle rate scelte. Mai che il fascicolo basta per agire: lo valuta il legale.
 */
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';

export type Parte = 'lettere' | 'estratti' | 'rendiconto' | 'accordo';
export type StatoImpronta = 'uguale' | 'diversa' | 'sparito' | 'senza';

export interface RataDelFascicolo { chiave: string; nome: string; unita: string | null; scadenza: string; residuo: number }
export interface EsercizioDelFascicolo { chiave: string; id: number | null; nome: string; data: string | null; rate: RataDelFascicolo[] }
export interface Impronta { stato: StatoImpronta; cifre: string | null; frase: string; breve: string }
export interface Segni { parte: Parte | null; rate: string[] | null }
export interface Pezzo extends Segni {
    tipo: 'indice' | 'prospetto' | 'estratto' | 'lettera' | 'prova' | 'rendiconto' | 'verbale' | 'accordo' | 'stampa_accordo';
    file: string | null;
    cosa: string;
    data: string | null;
    impronta: Impronta | null;
    invio_id?: number;
}
export interface Fatto extends Segni { frase: string }
export interface Mancanza extends Segni { frase: string; azione: { etichetta: string; url: string } | null }
export interface AnteprimaDelFascicolo {
    oggi: string;
    persona: { id: number; nome: string; unita: string[] };
    condominio: string;
    esercizi: EsercizioDelFascicolo[];
    parti: Parte[];
    pezzi: Pezzo[];
    fatti: Fatto[];
    mancanze: Mancanza[];
    ultimo: { giorno: string; utente: string } | null;
}

export const TITOLO = 'Fascicolo per il legale';
export const NOMI_DELLE_PARTI: Record<Parte, string> = {
    lettere: 'Le lettere e le prove',
    estratti: 'Gli estratti conto',
    rendiconto: 'Il rendiconto approvato e il verbale',
    accordo: 'L\'accordo di rientro',
};
export const FRASE_IN_FONDO = 'Questo indice dice che cosa il programma conserva per questa persona e che cosa non trova. Se basta per agire lo valuta il legale.';
export const FRASE_DATI_PERSONALI = 'Contiene dati personali di una persona: si consegna a chi la segue per il condominio.';
export const FRASE_PREPARATO = 'Fascicolo preparato: resta scritto nel registro della persona.';
export const FRASE_SCEGLI_UNA_RATA = 'Scegli almeno una rata.';
export const FRASE_TUTTO_SCELTO = 'Di serie c\'è tutto: togli un esercizio, una rata o una parte, e l\'indice e il prospetto dicono solo ciò che resta.';
export const FRASE_NESSUNA_MANCANZA = 'Il programma non trova mancanze fra ciò che conserva per queste rate.';
export const FRASE_NON_FATTO = 'Il fascicolo non è stato preparato. Riprova.';
export const FRASE_NON_LETTO = 'Il fascicolo non si è caricato. Riprova.';

/** Un pezzo, un fatto o una mancanza è dentro: la sua parte è scelta (o non ne ha), e almeno una delle sue rate è scelta (o non ne ha). */
export function dentro(x: Segni, scelte: Set<string>, parti: Set<Parte>): boolean {
    if (x.parte !== null && !parti.has(x.parte)) return false;

    return x.rate === null || x.rate.some((k) => scelte.has(k));
}

/** Le cifre delle rate scelte: il totale, quante, e per esercizio dei sei mesi le cifre di quelli rimasti. */
export function cifreScelte(esercizi: EsercizioDelFascicolo[], scelte: Set<string>): { totale: number; rate: number; esercizi: Array<{ nome: string; cifra: number; data: string | null }> } {
    const perEsercizio = esercizi
        .map((e) => ({ nome: e.nome, data: e.data, rate: e.rate.filter((r) => scelte.has(r.chiave)) }))
        .filter((e) => e.rate.length > 0)
        .map((e) => ({ nome: e.nome, data: e.data, cifra: e.rate.reduce((s, r) => s + r.residuo, 0), quante: e.rate.length }));

    return {
        totale: perEsercizio.reduce((s, e) => s + e.cifra, 0),
        rate: perEsercizio.reduce((s, e) => s + e.quante, 0),
        esercizi: perEsercizio.map(({ nome, cifra, data }) => ({ nome, cifra, data })),
    };
}

const anno = (nome: string) => nome.replace(/^esercizio\s+/i, '');
const rate = (n: number) => (n === 1 ? '1 rata' : `${n} rate`);

/** «Le rate scadute in questo fascicolo, al 20/05/2026: € 500,00». */
export const testoCifra = (totale: number, oggi: string): string => `Le rate scadute in questo fascicolo, al ${giorno(oggi)}: ${euroTesto(totale)}`;

/** «di cui dell'esercizio 2025: € 200,00 (data dei sei mesi: 30/06/2026)». */
export const testoDiCui = (e: { nome: string; cifra: number; data: string | null }): string => (e.data === null
    ? `di cui senza la data dei sei mesi: ${euroTesto(e.cifra)}`
    : `di cui dell'esercizio ${anno(e.nome)}: ${euroTesto(e.cifra)} (data dei sei mesi: ${giorno(e.data)})`);

/** «Rate non versate: 3 rate, € 1.240,00 al 14/11/2026». */
export const testoProspetto = (n: number, cifra: number, oggi: string): string => `Rate non versate: ${rate(n)}, ${euroTesto(cifra)} al ${giorno(oggi)}`;

/** «Esercizio 2025 · 3 rate · € 900,00»; con una parte delle rate tolta «Esercizio 2025 · 1 rata su 2 · € 100,00» (la cifra delle scelte). */
export function testoEsercizio(e: EsercizioDelFascicolo, scelte: Set<string>): string {
    const scelteQui = e.rate.filter((r) => scelte.has(r.chiave));
    const tutteOnessuna = scelteQui.length === 0 || scelteQui.length === e.rate.length;
    const cifra = (tutteOnessuna ? e.rate : scelteQui).reduce((s, r) => s + r.residuo, 0);

    return `${e.nome} · ${tutteOnessuna ? rate(e.rate.length) : `${scelteQui.length} ${scelteQui.length === 1 ? 'rata' : 'rate'} su ${e.rate.length}`} · ${euroTesto(cifra)}`;
}

/** «Preventivo 2026, rata 3 · scaduta il 05/03/2026 · € 100,00». */
export const testoRata = (r: RataDelFascicolo): string => `${r.nome} · scaduta il ${giorno(r.scadenza)} · ${euroTesto(r.residuo)}`;

/** La riga di un pezzo in «Che cosa c'è»: che cosa è, e per un file conservato lo stato dell'impronta in breve. */
export const testoPezzo = (p: Pezzo): string => (p.impronta && p.impronta.stato !== 'sparito' ? `${p.cosa} · ${p.impronta.breve}` : p.cosa);

/** «L'ultimo fascicolo è del 12/05/2026 (Vincenzo).» */
export const testoUltimo = (u: { giorno: string; utente: string }): string => `L'ultimo fascicolo è del ${giorno(u.giorno)}${u.utente ? ` (${u.utente})` : ''}.`;
