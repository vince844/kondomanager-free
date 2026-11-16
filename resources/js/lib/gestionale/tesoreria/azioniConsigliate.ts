// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Le azioni consigliate della tesoreria, fuori dal componente e senza Chart.js: regole fisse e verificabili, calcolate dal saldo previsto
 * giorno per giorno (`saldoPrevisto.ts`) e dalle persone del recupero che manda il server (`TreasuryGuardianService::recupero()`).
 *
 * - **Quanto basta**: la cifra che, entrata il giorno prima del primo giorno sotto zero dello scenario «Con le rate in scadenza» (oggi, se è già sotto zero),
 *   tiene quello scenario a zero o sopra per tutti i 30 giorni: il suo giorno più basso, cambiato di segno.
 * - **La simulazione**: lo scenario «Con le rate in scadenza» con la parte recuperata delle rate già scadute, entrata quel giorno; il punto più basso, i giorni
 *   sotto zero, la cifra fra 30 giorni.
 * - **(A) Il recupero**: «Recupera almeno € … delle rate scadute entro il …» quando lo scenario va sotto zero e ci sono rate scadute; le persone che bastano
 *   sono le rate scadute più alte prima, finché la somma copre la cifra (il server le ha già scelte). Quando quanto basta è più delle rate scadute, «Recupera
 *   tutte le rate scadute» e la frase dice che da solo non basta. Il pulsante è il sollecito (fino a 8 persone) o «Apri le rate scadute»; sotto, sempre,
 *   «Registra gli incassi», perché prima di sollecitare si registrano i versamenti già arrivati.
 * - **(B) Le fatture**: «Chiedi di pagare le fatture del … dopo il …» quando il saldo torna sopra zero con un'entrata di rate, dopo, e resta sopra fino alla
 *   fine; le fatture sono quelle che scadono nei giorni sotto zero, e spostate dopo quell'entrata tengono il saldo sopra zero ogni giorno.
 * - Le scadenze delle rate non sono un'azione: le approva l'assemblea con il piano rate.
 *
 * Le altre sei, dai dati che il server manda con la tesoreria (`DatiDelleAzioni`), ognuna solo quando la sua regola è vera:
 * - **(H) Le ritenute**: le ritenute d'acconto da versare con l'F24 nei 30 giorni o già scadute (le deleghe da versare e le ritenute non ancora in una delega,
 *   come la pagina «Ritenute e F24»): «Paga per prime le ritenute, € … entro il 16/…». Non sono nel saldo previsto.
 * - **(D) I bonifici segnalati** dal portale, da verificare: quanti e quanto, anche quelli rimandati con «Ricordamelo» (quanti e a quando), «Verifica i
 *   bonifici» verso l'Action inbox del condominio su quel tipo.
 * - **(C) Le rate non ancora emesse** che scadono nei 30 giorni o sono già scadute, una azione per piano: la rata, la cifra (per persona la sua parte da
 *   pagare, mai sotto zero), la scadenza. Il saldo previsto conta solo le rate emesse: la cifra è in più, e la frase lo dice. Con più di 8 rate il titolo dice
 *   «dal primo giorno in poi», e il verbo guarda l'ultima rata del piano nella finestra.
 * - **(G) Un conto sotto zero oggi** (banca o contanti) mentre un altro ha denaro: «Sposta € … da … a …», la cifra che lo riporta a zero o tutto ciò che
 *   l'altro può dare; «Fai un giroconto» apre il giroconto con i due conti e la cifra già scelti (si registra solo con la conferma dell'amministratore). Una
 *   banca è sotto zero quando lo dice la banca, con i fondi del suo conto corrente dentro (`saldo`), come la scheda «Cassa»: un fondo vincolato più grande della
 *   parte libera non è un caso da giroconto. Un conto dà a un altro solo la sua parte libera (`libero`), mai il denaro dei fondi.
 * - **(F) Un fondo vincolato** (ai lavori, o senza tipo; mai il TFR, e non il fondo morosità) che coprirebbe il punto più basso di «Senza le rate»:
 *   l'assemblea può deliberarne lo sblocco, che si registra nella pagina del fondo. La scelta resta dell'assemblea.
 * - **(E) Anche se pagano tutti** il conto va sotto zero: «Manca € … anche con tutte le rate», verso un piano rate straordinario; un deficit ereditato aperto
 *   si ricorda nella frase. «Tutte le rate» sono anche quelle approvate e non ancora emesse (C), al loro giorno: se coprono il buco il piano straordinario non
 *   c'è; se ne coprono una parte, la cifra che manca le conta e il titolo lo dice.
 *
 * L'ordine: prima ciò che la legge impone (H), il denaro già arrivato o annunciato (D), quello non ancora chiesto (C), il recupero (A, con «Registra gli
 * incassi»), i tempi (B), gli spostamenti interni (G), le decisioni dell'assemblea (F, E).
 */
import { SCENARI, euroSegno, giornoBreve, giornoIntero, saldiDelloScenario, type FatturaDelGiorno, type SaldoPrevisto } from './saldoPrevisto';

export interface PersonaDelRecupero {
    anagraficaId: number;
    nome: string;
    /** «Int. 4», o più unità separate dalla virgola. */
    interno: string;
    /** Le sue rate scadute da versare, al netto del credito, in centesimi. */
    cifra: number;
    /** L'ultima lettera (partita, stampata o spedita), o nessuna. */
    lettera: { tipo: string; data: string } | null;
}

/** Le persone del recupero, dal server: le prime quante bastano (regola delle 8), quante servono e la loro cifra, quante hanno rate scadute e la cifra di tutte. */
export interface Recupero {
    persone: PersonaDelRecupero[];
    servono: number;
    servonoCents: number;
    quante: number;
    cifra: number;
}

/** Un pezzo di frase: il rosso dice sotto zero, il grassetto una cifra o un nome. */
export interface Pezzo {
    testo: string;
    tono?: 'rosso' | 'forte';
}

/** Una rata non ancora emessa, con la cifra che porterebbe: per persona la sua parte da pagare, mai sotto zero. */
export interface RataDaEmettere {
    rataId: number;
    numero: number;
    scadenza: string;
    cifra: number;
}

/** Le rate non ancora emesse di un piano approvato, fino a 8 (le prime per scadenza), con quante sono e la loro cifra. */
export interface PianoDaEmettere {
    pianoId: number;
    piano: string;
    esercizioId: number | null;
    rate: RataDaEmettere[];
    quante: number;
    cifra: number;
    /** Le scadenze di tutte le rate da emettere del piano nella finestra, anche oltre le 8 nominate, per giorno e in ordine. */
    scadenze?: Array<{ giorno: string; cifra: number }>;
}

/** I dati delle sei azioni in più, dal server con la tesoreria (`DatiDelleAzioni`). Le cifre in centesimi. */
export interface DatiDelleAzioni {
    rateDaEmettere: PianoDaEmettere[];
    /**
     * I bonifici segnalati dal portale, da verificare: quanti, e la somma delle cifre segnalate. Contano anche quelli rimandati con «Ricordamelo»: quanti
     * sono, il primo giorno a cui sono rimandati e in quanti giorni diversi.
     */
    bonifici: { quanti: number; cifra: number; rimandati?: number; rimandatiAl?: string | null; rimandatiGiorni?: number };
    /** Le ritenute da versare con l'F24 nei 30 giorni o già scadute, per giorno di scadenza, in ordine. */
    ritenute: { scadenze: Array<{ giorno: string; cifra: number }> };
    /** I deficit ereditati da ripianare (i compiti aperti): quanti, e la somma dei loro scoperti. */
    deficit: { quanti: number; cifra: number };
    /** L'esercizio aperto di oggi, dove si crea un piano rate. */
    esercizioId: number | null;
    /**
     * Le banche e i contanti, con il saldo di oggi: per una banca quello che dice la banca, con i fondi del suo conto corrente dentro; `libero` è la parte
     * libera, ciò che il conto può dare a un altro (senza, il saldo).
     */
    conti: Array<{ cassaId: number; nome: string; tipo: string; saldo: number; libero?: number; attiva: boolean }>;
    /** I fondi che non si possono usare, con il saldo sopra zero, il tipo e il vincolo. */
    fondi: Array<{ cassaId: number; nome: string; saldo: number; sottotipo: string | null; vincolo: string | null }>;
}

export interface AzioneConsigliata {
    chiave: 'ritenute' | 'bonifici' | 'emissione' | 'recupero' | 'fatture' | 'giroconto' | 'fondo' | 'straordinario';
    titolo: string;
    frase: Pezzo[];
    /** Le righe che si leggono solo aprendo «Mostra le rate» (oggi le rate non emesse di un piano): il titolo resta corto. */
    dettagli?: string[];
    pulsante: {
        testo: string;
        tipo: 'lettera' | 'rate_scadute' | 'fattura' | 'fatture' | 'f24' | 'inbox' | 'emissione' | 'giroconto' | 'fondo' | 'piano_straordinario';
        persone?: Array<{ id: number; nome: string }>;
        fatture?: number[];
        pianoId?: number;
        esercizioId?: number | null;
        cassaId?: number;
        origine?: number;
        destinazione?: number;
        importo?: number;
    };
    /** Il recupero ha anche «Registra gli incassi», a contorno sotto il primo, con la riga che dice perché. */
    secondo?: { testo: string; tipo: 'incassi' };
    nota?: string;
}

/** Il secondo pulsante del recupero e la sua riga: i versamenti già arrivati si registrano prima di sollecitare. */
export const REGISTRA_GLI_INCASSI = { testo: 'Registra gli incassi', tipo: 'incassi' } as const;
export const PRIMA_DI_SOLLECITARE = 'Prima di sollecitare, controlla di aver registrato i versamenti già arrivati.';

/** Fino a 8 persone o fatture si dicono tutte; oltre, le prime 5 e quante mancano. */
const TUTTE_FINO_A = 8;
const MOSTRATE_OLTRE = 5;

export const RIGA_DELLE_SCADENZE = 'Le scadenze delle rate non sono fra le azioni: le approva l\'assemblea con il piano rate.';

export const testoDiPezzi = (pezzi: Pezzo[]): string => pezzi.map((p) => p.testo).join('');

const PAROLE = ['', 'una', 'due', 'tre', 'quattro', 'cinque', 'sei', 'sette', 'otto'];
const piu = (centesimi: number) => (centesimi > 0 ? `+${euroSegno(centesimi)}` : euroSegno(centesimi));
const inEuro = (centesimi: number) => Math.round(centesimi) / 100;
const conRate = (sp: SaldoPrevisto) => saldiDelloScenario(sp, 'con_rate');
const primoSottoZero = (valori: number[]): number | null => {
    const i = valori.findIndex((v) => v < 0);

    return i < 0 ? null : i;
};
const giorno = (sp: SaldoPrevisto, i: number) => giornoBreve(sp.giorni[i].giorno);
const rateDelGiorno = (sp: SaldoPrevisto, i: number) => sp.giorni[i]?.rate_in_scadenza ?? 0;

/** Quanto basta perché lo scenario «Con le rate in scadenza» non vada mai sotto zero nei 30 giorni. */
export function quantoBasta(sp: SaldoPrevisto): number {
    return Math.max(0, -Math.min(0, ...conRate(sp)));
}

/** Il giorno in cui entra il recupero: il giorno prima del primo giorno sotto zero, oggi se è già sotto zero o se non ci va mai. */
export function indiceDelRecupero(sp: SaldoPrevisto): number {
    const p = primoSottoZero(conRate(sp));

    return p === null || p === 0 ? 0 : p - 1;
}

/** La domanda sopra il cursore. */
export function domandaDelRecupero(sp: SaldoPrevisto): string {
    const p = primoSottoZero(conRate(sp));
    if (p === null) return 'Quanto recuperi delle rate già scadute?';
    if (p === 0) return 'Quanto recuperi delle rate già scadute oggi?';

    return `Quanto recuperi delle rate già scadute prima del ${giorno(sp, p)}?`;
}

/** Lo scenario «Con le rate in scadenza» con il recupero, giorno per giorno, in centesimi. */
export function simulazione(sp: SaldoPrevisto, recuperato: number): number[] {
    const dal = indiceDelRecupero(sp);

    return conRate(sp).map((v, i) => (i >= dal ? v + recuperato : v));
}

/** Le tre cifre sotto il cursore. */
export function cifreDellaSimulazione(valori: number[]): { minimo: number; giorniSottoZero: number; fine: number } {
    return { minimo: Math.min(...valori), giorniSottoZero: valori.filter((v) => v < 0).length, fine: valori.at(-1) ?? 0 };
}

export const testoGiorniSottoZero = (n: number): string => (n === 0 ? 'nessuno' : n === 1 ? '1 giorno' : `${n} giorni`);

/** «36 %», all'italiana con lo spazio. */
export function percentuale(parte: number, tutto: number): string {
    return `${tutto > 0 ? Math.round((parte / tutto) * 100) : 0} %`;
}

/** La tacca di quanto basta sul cursore: la posizione in percentuale e la scritta; in fondo quando è più delle rate scadute; nessuna senza bisogno. */
export function tacca(basta: number, totale: number): { posizione: number; oltre: boolean; testo: string } | null {
    if (basta <= 0) return null;
    if (basta > totale) return { posizione: 100, oltre: true, testo: `quanto basta: ${euroSegno(basta)}, più delle rate scadute` };

    return { posizione: (basta / totale) * 100, oltre: false, testo: `quanto basta: ${euroSegno(basta)} (${percentuale(basta, totale)})` };
}

/** Il pulsante della card: «2 azioni consigliate», «1 azione consigliata»; nessuno senza azioni; con «Scelgo io» «Simula il saldo». */
export function testoDelPulsante(azioni: number, suggerisce: boolean): string | null {
    if (!suggerisce) return 'Simula il saldo';
    if (azioni === 0) return null;

    return azioni === 1 ? '1 azione consigliata' : `${azioni} azioni consigliate`;
}

/** Perché il saldo scende quel giorno, se lo si sa. */
function motivo(sp: SaldoPrevisto, i: number): string {
    const g = sp.giorni[i];
    if (g.uscite > 0) return i === 0 ? ', per le fatture da pagare oggi' : ', per le fatture che scadono quel giorno';
    if (g.registrati < 0) return ', per i pagamenti già registrati quel giorno';

    return '';
}

/** La situazione in una frase, in testa al cassetto. */
export function situazione(sp: SaldoPrevisto, recupero: Recupero): Pezzo[] {
    const con = conRate(sp);
    const senza = saldiDelloScenario(sp, 'senza');
    const pezzi: Pezzo[] = [];
    const p = primoSottoZero(con);

    if (p !== null) {
        if (p === 0) {
            pezzi.push({ testo: 'Il conto è ' }, { testo: `sotto zero già oggi, di ${euroSegno(-con[0])}`, tono: 'rosso' }, { testo: `${motivo(sp, 0)}.` });
        } else {
            pezzi.push({ testo: 'Il conto va ' }, { testo: `sotto zero il ${giorno(sp, p)} di ${euroSegno(-con[p])}`, tono: 'rosso' }, { testo: `${motivo(sp, p)}.` });
        }
        const minimo = Math.min(...con);
        if (minimo < con[p]) pezzi.push({ testo: ` Il punto più basso è il ${giorno(sp, con.indexOf(minimo))}, ${euroSegno(minimo)}.` });
        const r = sp.giorni.findIndex((_, i) => i > p && rateDelGiorno(sp, i) > 0);
        if (r >= 0) {
            pezzi.push({ testo: ` Il ${giorno(sp, r)} entrano ${euroSegno(rateDelGiorno(sp, r))} di rate.` });
        } else {
            pezzi.push({ testo: p > 0 && sp.giorni.some((_, i) => rateDelGiorno(sp, i) > 0) ? ` Dopo il ${giorno(sp, p)} non scadono altre rate nei 30 giorni.`
                : ' Nei 30 giorni non scadono rate.' });
        }
    } else {
        const ps = primoSottoZero(senza);
        if (ps !== null) {
            pezzi.push({ testo: ps === 0 ? 'Senza le rate il conto è ' : 'Senza le rate il conto va ' },
                { testo: ps === 0 ? `sotto zero già oggi, di ${euroSegno(-senza[0])}` : `sotto zero il ${giorno(sp, ps)} di ${euroSegno(-senza[ps])}`, tono: 'rosso' },
                { testo: `${motivo(sp, ps)}.` });
            const giorniRate = sp.giorni.map((_, i) => i).filter((i) => i <= ps && rateDelGiorno(sp, i) > 0);
            const cifra = giorniRate.reduce((t, i) => t + rateDelGiorno(sp, i), 0);
            const quando = giorniRate.length === 1 ? `il ${giorno(sp, giorniRate[0])}` : `fra il ${giorno(sp, giorniRate[0])} e il ${giorno(sp, giorniRate.at(-1)!)}`;
            pezzi.push({ testo: ` Con le rate che scadono nei 30 giorni resta sopra zero, se arrivano in tempo: ${quando} entrano ${euroSegno(cifra)}.` });
        } else {
            const minimo = Math.min(...senza);
            const i = senza.indexOf(minimo);
            pezzi.push({ testo: `Il conto resta sopra zero per tutti i 30 giorni, anche senza le rate: il punto più basso è ${euroSegno(minimo)}, ${i === 0 ? 'oggi' : `il ${giorno(sp, i)}`}.` });
        }
    }

    if (recupero.cifra > 0) {
        pezzi.push({ testo: ' Intanto ci sono ' }, { testo: `${euroSegno(recupero.cifra)} di rate già scadute`, tono: 'forte' }, { testo: '.' });
    } else {
        pezzi.push({ testo: ' Non ci sono rate già scadute.' });
    }
    const pt = primoSottoZero(saldiDelloScenario(sp, 'tutti'));
    if (pt !== null) {
        pezzi.push({ testo: pt === 0 ? ' Anche se pagano tutti, oggi il conto è sotto zero.' : ` Anche se pagano tutti, il conto va sotto zero il ${giorno(sp, pt)}.` });
    }

    return pezzi;
}

/** «int. 4» dentro una frase. */
const internoInFrase = (interno: string) => interno.replace(/^Int\./, 'int.');

const ARTICOLI: Record<string, string> = { promemoria: 'un promemoria', sollecito: 'un sollecito', diffida: 'una diffida', messa_in_mora: 'una messa in mora' };

/** Una persona nella frase: il nome in grassetto, l'interno, la cifra, l'ultima lettera. */
function pezziDellaPersona(p: PersonaDelRecupero): Pezzo[] {
    const lettera = p.lettera === null ? 'nessuna lettera'
        : `come ultima lettera ${ARTICOLI[p.lettera.tipo] ?? `un ${p.lettera.tipo}`} del ${giornoIntero(p.lettera.data)}`;

    return [{ testo: p.nome, tono: 'forte' }, { testo: `${p.interno ? `, ${internoInFrase(p.interno)}` : ''}, ha ${euroSegno(p.cifra)} di rate scadute e ${lettera}` }];
}

/** Le persone che bastano a coprire la cifra, dalla lista ordinata del server: le rate scadute più alte prima, finché la somma la copre. */
export function personeCheBastano(persone: PersonaDelRecupero[], cifra: number): PersonaDelRecupero[] {
    const esito: PersonaDelRecupero[] = [];
    let coperto = 0;
    for (const p of persone) {
        if (coperto >= cifra) break;
        esito.push(p);
        coperto += p.cifra;
    }

    return esito;
}

/** L'elenco delle persone nella frase, con la regola delle 8. */
function elencoDellePersone(apertura: (n: number, parola: string) => string, recupero: Recupero, mostrate: PersonaDelRecupero[]): Pezzo[] {
    const oltre = recupero.servono > TUTTE_FINO_A;
    const quante = oltre ? recupero.servono : mostrate.length;
    const pezzi: Pezzo[] = [{ testo: apertura(quante, PAROLE[quante] ?? String(quante)) }];
    if (oltre) pezzi.push({ testo: `le ${mostrate.length} con più rate scadute sono ` });
    mostrate.forEach((p, i) => {
        if (i > 0) pezzi.push({ testo: '; ' });
        pezzi.push(...pezziDellaPersona(p));
    });
    if (oltre) {
        const restano = recupero.servono - mostrate.length;
        const cifra = recupero.servonoCents - mostrate.reduce((t, p) => t + p.cifra, 0);
        pezzi.push({ testo: `; e altre ${restano} ${restano === 1 ? 'persona' : 'persone'}, ${euroSegno(cifra)}` });
    }
    pezzi.push({ testo: '.' });

    return pezzi;
}

function azioneDelRecupero(sp: SaldoPrevisto, recupero: Recupero, basta: number): AzioneConsigliata {
    const con = conRate(sp);
    const p = primoSottoZero(con) ?? 0;
    const dal = indiceDelRecupero(sp);
    const entro = dal === 0 ? 'entro oggi' : `entro il ${giorno(sp, dal)}`;
    const mostrate = recupero.servono > TUTTE_FINO_A ? recupero.persone.slice(0, MOSTRATE_OLTRE) : personeCheBastano(recupero.persone, Math.min(basta, recupero.cifra));
    const bastaIlRecupero = basta <= recupero.cifra;

    let titolo: string;
    const frase: Pezzo[] = [];
    if (bastaIlRecupero) {
        titolo = `Recupera almeno ${euroSegno(basta)} delle rate scadute ${entro}`;
        const minimo = Math.min(...con);
        const iMinimo = con.indexOf(minimo);
        frase.push({ testo: iMinimo === p ? (p === 0 ? 'È quanto manca oggi per non andare sotto zero.' : `È quanto manca il ${giorno(sp, p)} per non andare sotto zero.`)
            : `È quanto serve per non andare sotto zero nei 30 giorni: il punto più basso è il ${giorno(sp, iMinimo)}, ${euroSegno(minimo)}.` });
        frase.push(...elencoDellePersone((n, parola) => (recupero.servono > TUTTE_FINO_A ? ` Servono ${n} persone: ` : n === 1 ? ' Basta una persona: ' : ` Bastano ${parola} persone: `),
            recupero, mostrate));
    } else {
        titolo = `Recupera tutte le rate scadute, ${euroSegno(recupero.cifra)}, ${entro}`;
        const conTutte = simulazione(sp, recupero.cifra);
        const pt = primoSottoZero(conTutte) ?? 0;
        frase.push({ testo: `Da solo non basta: anche con tutte le rate scadute il conto ${pt === 0 ? 'resta sotto zero già oggi' : `va sotto zero il ${giorno(sp, pt)}`}, `
            + `fino a ${euroSegno(Math.min(...conTutte))}.` });
        frase.push(...elencoDellePersone((n, parola) => (recupero.servono > TUTTE_FINO_A ? ` Le rate scadute sono di ${n} persone: `
            : n === 1 ? ' Le rate scadute sono di una persona: ' : ` Le rate scadute sono di ${parola} persone: `), recupero, mostrate));
    }

    const pulsante: AzioneConsigliata['pulsante'] = recupero.servono > TUTTE_FINO_A || mostrate.length === 0
        ? { testo: 'Apri le rate scadute', tipo: 'rate_scadute' }
        : { testo: mostrate.length === 1 ? 'Manda un sollecito' : 'Manda i solleciti', tipo: 'lettera', persone: mostrate.map((m) => ({ id: m.anagraficaId, nome: m.nome })) };

    return { chiave: 'recupero', titolo, frase, pulsante, secondo: { ...REGISTRA_GLI_INCASSI }, nota: PRIMA_DI_SOLLECITARE };
}

/** Le fatture dei giorni dati, come le manda il server; da un server di prima, le voci del giorno. */
function fattureDeiGiorni(sp: SaldoPrevisto, giorni: number[]): { elenco: FatturaDelGiorno[]; quante: number; cifra: number } {
    const elenco = giorni.flatMap((i) => {
        const g = sp.giorni[i];

        return g.fatture?.length ? g.fatture : g.voci_uscite.map((v, k) => ({ id: 0, fornitore: v.voce, numero: String(k), importo: v.importo }));
    });

    return { elenco, quante: giorni.reduce((t, i) => t + sp.giorni[i].quante_uscite, 0), cifra: giorni.reduce((t, i) => t + sp.giorni[i].uscite, 0) };
}

function azioneDelleFatture(sp: SaldoPrevisto): AzioneConsigliata | null {
    const con = conRate(sp);
    const p = primoSottoZero(con);
    if (p === null) return null;
    // Il giorno in cui il saldo torna sopra zero: deve essere un'entrata di rate, e il saldo deve restare sopra fino alla fine.
    const r = con.findIndex((v, i) => i > p && v >= 0);
    if (r < 0 || rateDelGiorno(sp, r) <= 0 || con.slice(r).some((v) => v < 0)) return null;
    // Le fatture che scadono nei giorni sotto zero; spostate dopo l'entrata, il saldo resta sopra zero ogni giorno.
    const giorni = sp.giorni.map((_, i) => i).filter((i) => i >= p && i < r && sp.giorni[i].uscite > 0);
    if (giorni.length === 0) return null;
    let spostate = 0;
    for (let i = p; i < r; i++) {
        if (giorni.includes(i)) spostate += sp.giorni[i].uscite;
        if (con[i] + spostate < 0) return null;
    }

    const fatture = fattureDeiGiorni(sp, giorni);
    const tutte = fatture.quante <= TUTTE_FINO_A && fatture.elenco.length === fatture.quante;
    const dette = tutte ? fatture.elenco : fatture.elenco.slice(0, MOSTRATE_OLTRE);
    const restano = fatture.quante - dette.length;
    const cifraRestano = fatture.cifra - dette.reduce((t, f) => t + f.importo, 0);
    const elenco = dette.map((f) => `${f.fornitore} ${euroSegno(f.importo)}`).join(', ') + (restano > 0 ? `, e altre ${restano} fatture, ${euroSegno(cifraRestano)}` : '');
    const del = giorni.length === 1 ? `del ${giorno(sp, giorni[0])}` : `dal ${giorno(sp, giorni[0])} al ${giorno(sp, giorni.at(-1)!)}`;
    const ids = fatture.elenco.map((f) => f.id).filter((id) => id > 0);

    return {
        chiave: 'fatture',
        titolo: `Chiedi di pagare le fatture ${del} dopo il ${giorno(sp, r)}`,
        frase: [{ testo: `Il ${giorno(sp, r)} entrano ${euroSegno(rateDelGiorno(sp, r))} di rate: se i fornitori accettano, il conto resta sopra zero. Le fatture: ${elenco}.` }],
        pulsante: fatture.quante === 1 && ids.length === 1 ? { testo: 'Apri la fattura', tipo: 'fattura', fatture: ids } : { testo: 'Apri le fatture', tipo: 'fatture', fatture: ids },
    };
}

const somma = (voci: Array<{ cifra: number }>) => voci.reduce((t, v) => t + v.cifra, 0);
const oggiDi = (sp: SaldoPrevisto) => sp.giorni[0]?.giorno ?? '';
/** «4», «4 e 5», «4, 5 e 6». */
const elencoItaliano = (voci: string[]) => (voci.length <= 1 ? voci.join('') : `${voci.slice(0, -1).join(', ')} e ${voci.at(-1)}`);
/** Il giorno di una data «AAAA-MM-GG» nella frase: «oggi» o «il 20/11». */
const ilGiorno = (sp: SaldoPrevisto, i: number) => (i === 0 ? 'oggi' : `il ${giorno(sp, i)}`);

/** (H) Le ritenute da versare con l'F24, nei 30 giorni o già scadute. */
function azioneDelleRitenute(sp: SaldoPrevisto, dati: DatiDelleAzioni): AzioneConsigliata | null {
    const scadenze = (dati.ritenute?.scadenze ?? []).filter((s) => s.cifra > 0);
    if (scadenze.length === 0) return null;
    const oggi = oggiDi(sp);
    const scadute = scadenze.filter((s) => s.giorno < oggi);
    const avanti = scadenze.filter((s) => s.giorno >= oggi);
    let titolo: string;
    let poi: typeof scadenze;
    if (scadute.length > 0) {
        titolo = `Paga per prime le ritenute, ${euroSegno(somma(scadute))}: ${scadute.length === 1 ? `la scadenza era il ${giornoBreve(scadute[0].giorno)}`
            : `le scadenze erano dal ${giornoBreve(scadute[0].giorno)}`}`;
        poi = avanti;
    } else {
        titolo = `Paga per prime le ritenute, ${euroSegno(avanti[0].cifra)} entro il ${giornoBreve(avanti[0].giorno)}`;
        poi = avanti.slice(1);
    }
    const dopo = poi.length ? ` Poi ${elencoItaliano(poi.map((p) => `${euroSegno(p.cifra)} entro il ${giornoBreve(p.giorno)}`))}.` : '';

    return {
        chiave: 'ritenute',
        titolo,
        frase: [{ testo: `Le ritenute d'acconto dei fornitori si versano all'Erario con l'F24, alle scadenze della legge, e non sono nel saldo previsto.${dopo}` }],
        pulsante: { testo: 'Prepara l\'F24', tipo: 'f24' },
    };
}

/** (D) I bonifici segnalati dai condòmini, da verificare. */
function azioneDeiBonifici(dati: DatiDelleAzioni): AzioneConsigliata | null {
    const { quanti, cifra, rimandati = 0, rimandatiAl = null, rimandatiGiorni = 0 } = dati.bonifici ?? { quanti: 0, cifra: 0 };
    if (quanti <= 0) return null;
    const uno = quanti === 1;
    // I rimandati con «Ricordamelo» restano qui (la rata è ancora in verifica): la frase dice quanti e a quando; con più giorni, il primo.
    const rimandatiDaDire = rimandati > 0 && rimandatiAl ? rimandati : 0;
    const quando = rimandatiAl ? giornoBreve(rimandatiAl) : '';
    const unGiorno = rimandatiGiorni <= 1;
    const diCui = rimandatiDaDire === 0 ? ''
        : uno ? `, rimandato al ${quando}`
            : `, di cui ${rimandatiDaDire} ${rimandatiDaDire === 1 ? 'rimandato' : 'rimandati'}${unGiorno ? ` al ${quando}` : `, il primo al ${quando}`}`;
    const tornano = rimandatiDaDire === 0 ? ''
        : rimandatiDaDire === 1 ? ` Rimandato con «Ricordamelo»: nell'Action inbox torna il ${quando}.`
            : ` Rimandati con «Ricordamelo»: nell'Action inbox tornano ${unGiorno ? 'il' : 'dal'} ${quando}.`;

    return {
        chiave: 'bonifici',
        titolo: uno ? `1 bonifico segnalato, ${euroSegno(cifra)}${diCui}: verificalo prima di sollecitare`
            : `${quanti} bonifici segnalati, ${euroSegno(cifra)}${diCui}: verificali prima di sollecitare`,
        frase: [{ testo: (uno
            ? 'Un condòmino lo ha segnalato dal portale. Se è arrivato sul conto, registra l\'incasso: la rata risulta pagata e non va sollecitata.'
            : 'I condòmini li hanno segnalati dal portale. Se sono arrivati sul conto, registra l\'incasso: le rate risultano pagate e non vanno sollecitate.') + tornano }],
        pulsante: { testo: uno ? 'Verifica il bonifico' : 'Verifica i bonifici', tipo: 'inbox' },
    };
}

/** (C) Le rate non ancora emesse di un piano, che scadono nei 30 giorni o sono già scadute. */
function azioneDellEmissione(sp: SaldoPrevisto, piano: PianoDaEmettere): AzioneConsigliata | null {
    if (piano.rate.length === 0 || piano.cifra <= 0) return null;
    const oggi = oggiDi(sp);
    const quando = (r: RataDaEmettere) => (r.scadenza.slice(0, 10) < oggi ? `scadeva il ${giornoBreve(r.scadenza)}` : `scade il ${giornoBreve(r.scadenza)}`);
    const ultima = piano.rate.at(-1)!;
    // Il verbo guarda l'ultima rata del piano nella finestra, anche oltre le 8 nominate.
    const passata = (piano.scadenze?.at(-1)?.giorno ?? ultima.scadenza).slice(0, 10) < oggi;
    const una = piano.quante === 1;
    const prima = piano.rate[0];
    const restano = piano.quante - piano.rate.length;
    const titolo = una
        ? `Emetti la rata ${ultima.numero} del piano «${piano.piano}»: porterebbe ${euroSegno(piano.cifra)}${passata ? `, e scadeva il ${giornoBreve(ultima.scadenza)}`
            : ` entro il ${giornoBreve(ultima.scadenza)}`}`
        : `Emetti ${piano.quante} rate del piano «${piano.piano}»: porterebbero ${euroSegno(piano.cifra)}, `
            + `${passata ? 'scadevano' : 'scadono'} ${restano > 0 ? `dal ${giornoBreve(prima.scadenza)} in poi` : `dal ${giornoBreve(prima.scadenza)} al ${giornoBreve(ultima.scadenza)}`}`;
    const dettagli = una ? undefined : [
        ...piano.rate.map((r) => `Rata ${r.numero}, ${euroSegno(r.cifra)}, ${quando(r)}`),
        ...(restano > 0 ? [`E altre ${restano} rate, ${euroSegno(piano.cifra - somma(piano.rate))}`] : []),
    ];

    return {
        chiave: 'emissione',
        titolo,
        frase: [{ testo: `${una ? 'Non è nel saldo previsto' : 'Non sono nel saldo previsto'}, che conta solo le rate emesse.` }],
        dettagli,
        pulsante: { testo: una ? 'Emetti la rata' : 'Emetti le rate', tipo: 'emissione', pianoId: piano.pianoId, esercizioId: piano.esercizioId },
    };
}

/** Ciò che un conto può dare a un altro: la sua parte libera, e mai più di quanto dice la banca. */
const puoDare = (c: DatiDelleAzioni['conti'][number]) => Math.min(c.saldo, c.libero ?? c.saldo);

/**
 * (G) Un conto sotto zero oggi mentre un altro ha denaro: il giroconto che lo riporta a zero, o tutto ciò che l'altro può dare. «Sotto zero» è il saldo come
 * lo dice la banca, con i fondi dentro; l'altro conto dà solo la sua parte libera.
 */
function azioneDelGiroconto(dati: DatiDelleAzioni): AzioneConsigliata | null {
    const conti = dati.conti ?? [];
    const sotto = [...conti].filter((c) => c.saldo < 0).sort((a, b) => a.saldo - b.saldo || a.cassaId - b.cassaId)[0];
    if (!sotto) return null;
    const da = [...conti].filter((c) => puoDare(c) > 0 && c.attiva !== false && c.cassaId !== sotto.cassaId)
        .sort((a, b) => puoDare(b) - puoDare(a) || a.cassaId - b.cassaId)[0];
    if (!da) return null;
    const ha = puoDare(da);
    const manca = -sotto.saldo;
    const cifra = Math.min(manca, ha);
    const inizio = `Oggi ${sotto.nome} è sotto zero di ${euroSegno(manca)} e ${da.nome} ha ${euroSegno(ha)}${ha < da.saldo ? ' fuori dai fondi' : ''}: `;

    return {
        chiave: 'giroconto',
        titolo: `Sposta ${euroSegno(cifra)} da ${da.nome} a ${sotto.nome}`,
        frase: [{ testo: cifra === manca ? `${inizio}con il giroconto ${sotto.nome} torna a zero.`
            : `${inizio}è tutto quello che può dare, e ${sotto.nome} resta sotto zero di ${euroSegno(manca - cifra)}.` }],
        pulsante: { testo: 'Fai un giroconto', tipo: 'giroconto', origine: da.cassaId, destinazione: sotto.cassaId, importo: cifra },
    };
}

/** I tipi di fondo il cui sblocco si propone: vincolato ai lavori, o senza tipo. Mai il TFR; il fondo morosità no, finché non ha le sue regole. */
export const FONDI_DA_SBLOCCARE: ReadonlyArray<string | null> = ['vincolato_lavori', null];

/** «Il fondo «Riserva»», o «Fondo facciata» quando il nome lo dice già. */
const nomeDelFondo = (nome: string, inizio: boolean) => (/^fondo\b/i.test(nome.trim()) ? `«${nome}»` : `${inizio ? 'Il' : 'il'} fondo «${nome}»`);

/** (F) Un fondo vincolato che coprirebbe il punto più basso di «Senza le rate»: l'assemblea può deliberarne lo sblocco. */
function azioneDelFondo(sp: SaldoPrevisto, dati: DatiDelleAzioni): AzioneConsigliata | null {
    const senza = saldiDelloScenario(sp, 'senza');
    const minimo = Math.min(0, ...senza);
    if (minimo >= 0) return null;
    const buco = -minimo;
    const candidati = (dati.fondi ?? []).filter((f) => FONDI_DA_SBLOCCARE.includes(f.sottotipo ?? null) && f.saldo >= buco)
        .sort((a, b) => a.saldo - b.saldo || a.cassaId - b.cassaId);
    if (candidati.length === 0) return null;
    const [f, ...altri] = candidati;
    const tipo = f.sottotipo === 'vincolato_lavori' ? `vincolato ai lavori${f.vincolo ? ` (${f.vincolo})` : ''}` : 'senza un tipo, e conta come vincolato';
    const anche = altri.length ? ` Basterebbe${altri.length > 1 ? 'ro' : ''} anche ${elencoItaliano(altri.slice(0, TUTTE_FINO_A).map((a) => `${nomeDelFondo(a.nome, false)}, ${euroSegno(a.saldo)}`))}.` : '';

    return {
        chiave: 'fondo',
        titolo: `${nomeDelFondo(f.nome, true)} ha ${euroSegno(f.saldo)}: l'assemblea può deliberarne lo sblocco`,
        frase: [{ testo: `Senza le rate il conto va sotto zero di ${euroSegno(buco)} al punto più basso, ${ilGiorno(sp, senza.indexOf(minimo))}: il fondo basterebbe a coprirlo. `
            + `È ${tipo}: il suo denaro si può spendere solo se l'assemblea ne delibera lo sblocco, e la delibera si registra nella pagina del fondo.${anche}` }],
        pulsante: { testo: 'Registra lo sblocco', tipo: 'fondo', cassaId: f.cassaId },
        ...(f.sottotipo === null ? { nota: 'Il fondo non ha un tipo: nella sua pagina scegli il tipo prima di registrare lo sblocco.' } : {}),
    };
}

/** L'indice del giorno in cui entra una rata da emettere: oggi le già scadute; null oltre la finestra. */
function indiceDelGiorno(sp: SaldoPrevisto, scadenza: string): number | null {
    const g = scadenza.slice(0, 10);
    if (g < oggiDi(sp)) return 0;
    const i = sp.giorni.findIndex((x) => x.giorno >= g);

    return i >= 0 ? i : null;
}

/**
 * Le rate non ancora emesse (C), giorno per giorno, come entrerebbero emesse e pagate: ognuna al suo giorno (le scadenze di tutte, che manda il server; senza,
 * le 8 nominate al loro giorno e le altre al giorno dell'ottava).
 */
function rateDaEmettereNeiGiorni(sp: SaldoPrevisto, dati: DatiDelleAzioni): number[] {
    const inPiu = sp.giorni.map(() => 0);
    for (const p of dati.rateDaEmettere ?? []) {
        const scadenze = p.scadenze ?? [...p.rate.map((r) => ({ giorno: r.scadenza, cifra: r.cifra })),
            ...(p.rate.length && p.cifra > somma(p.rate) ? [{ giorno: p.rate.at(-1)!.scadenza, cifra: p.cifra - somma(p.rate) }] : [])];
        for (const s of scadenze) {
            const i = indiceDelGiorno(sp, s.giorno);
            if (i !== null && s.cifra > 0) inPiu[i] += s.cifra;
        }
    }

    return inPiu;
}

/**
 * (E) Anche se pagano tutti il conto va sotto zero: serve un versamento straordinario. «Tutti» paga anche le rate approvate e non ancora emesse, al loro
 * giorno: se coprono il buco, basta emetterle (C).
 */
function azioneDelloStraordinario(sp: SaldoPrevisto, dati: DatiDelleAzioni): AzioneConsigliata | null {
    const daEmettere = rateDaEmettereNeiGiorni(sp, dati);
    const conLeDaEmettere = daEmettere.some((c) => c > 0);
    let entrate = 0;
    const tutti = saldiDelloScenario(sp, 'tutti').map((v, i) => v + (entrate += daEmettere[i]));
    const minimo = Math.min(...tutti);
    if (!(minimo < 0)) return null;
    const p = primoSottoZero(tutti) ?? 0;
    const { quanti, cifra } = dati.deficit ?? { quanti: 0, cifra: 0 };
    const deficit = quanti > 0 ? ` ${quanti === 1 ? 'C\'è anche un debito ereditato' : `Ci sono anche ${quanti} debiti ereditati`} da ripianare, ${euroSegno(cifra)}: `
        + `${quanti === 1 ? 'nessuna rata lo chiede' : 'nessuna rata li chiede'}.` : '';

    return {
        chiave: 'straordinario',
        titolo: `Manca ${euroSegno(-minimo)} anche con tutte le rate${conLeDaEmettere ? ', comprese quelle da emettere' : ''}: serve un versamento straordinario`,
        frase: [{ testo: `Anche se pagano tutti, rate già scadute${conLeDaEmettere ? ' e rate da emettere' : ''} comprese, il conto va sotto zero ${ilGiorno(sp, p)}; `
            + `il punto più basso è ${ilGiorno(sp, tutti.indexOf(minimo))}, `
            + `${euroSegno(minimo)}. Un piano rate straordinario chiede ai condòmini la cifra che manca, e lo delibera l'assemblea.${deficit}` }],
        pulsante: { testo: 'Prepara un piano straordinario', tipo: 'piano_straordinario', esercizioId: dati.esercizioId },
        // Il piano rate nasce in un esercizio aperto: senza, il pulsante non c'è e la riga dice da dove si parte.
        ...(dati.esercizioId ? {} : { nota: 'Il condominio non ha un esercizio aperto: aprilo dagli esercizi, poi prepara il piano straordinario.' }),
    };
}

/**
 * Le azioni consigliate, nell'ordine: le ritenute (H), i bonifici segnalati (D), le rate da emettere (C, una per piano), il recupero (A), le fatture (B), il
 * giroconto (G), il fondo (F), il piano straordinario (E). Ognuna c'è solo quando la sua regola è vera. Senza i dati del server (una tesoreria di prima), solo
 * il recupero e le fatture.
 */
export function azioniConsigliate(sp: SaldoPrevisto, recupero: Recupero, dati: DatiDelleAzioni | null = null): AzioneConsigliata[] {
    const basta = quantoBasta(sp);
    const azioni: Array<AzioneConsigliata | null> = [];
    if (dati) {
        azioni.push(azioneDelleRitenute(sp, dati), azioneDeiBonifici(dati), ...(dati.rateDaEmettere ?? []).map((p) => azioneDellEmissione(sp, p)));
    }
    if (basta > 0 && recupero.cifra > 0) azioni.push(azioneDelRecupero(sp, recupero, basta));
    azioni.push(azioneDelleFatture(sp));
    if (dati) {
        azioni.push(azioneDelGiroconto(dati), azioneDelFondo(sp, dati), azioneDelloStraordinario(sp, dati));
    }

    return azioni.filter((a): a is AzioneConsigliata => a !== null);
}

/** I fatti scritti sul grafico: il recupero (sopra), le fatture del primo giorno sotto zero (sotto), le rate che entrano dopo (sopra). */
export function fattiDelGrafico(sp: SaldoPrevisto, recuperato: number): Array<{ indice: number; testo: string; sopra: boolean }> {
    const p = primoSottoZero(conRate(sp));
    const fatti: Array<{ indice: number; testo: string; sopra: boolean }> = [];
    if (recuperato > 0) {
        const dal = indiceDelRecupero(sp);
        fatti.push({ indice: dal, testo: `${giorno(sp, dal)} · recupero ${piu(recuperato)}`, sopra: true });
    }
    const maggiore = (valore: (i: number) => number, da = 0) => sp.giorni.reduce((m, _, i) => (i >= da && valore(i) > 0 && (m < 0 || valore(i) > valore(m)) ? i : m), -1);
    const f = p !== null && sp.giorni[p].uscite > 0 ? p : maggiore((i) => sp.giorni[i].uscite);
    if (f >= 0) fatti.push({ indice: f, testo: `${giorno(sp, f)} · fatture ${euroSegno(-sp.giorni[f].uscite)}`, sopra: false });
    const r = p !== null ? sp.giorni.findIndex((_, i) => i > p && rateDelGiorno(sp, i) > 0) : maggiore((i) => rateDelGiorno(sp, i));
    if (r >= 0) fatti.push({ indice: r, testo: `${giorno(sp, r)} · rate ${piu(rateDelGiorno(sp, r))}`, sopra: true });

    return fatti;
}

/** I colori del grafico con tre linee, letti dal tema quando si disegna. */
export interface ColoriDelGraficoGrande {
    linea: string;
    zona: string;
    zonaSopra: string;
    verde: string;
    grigio: string;
    testo: string;
    griglia: string;
    zero: string;
    sfondo: string;
    bordo: string;
    scritta: string;
}

/**
 * I dati per Chart.js del grafico del cassetto, con tre linee a gradini (l'eccezione alla serie sola): la simulazione piena in indaco, con il
 * rosso solo sotto zero; «Se pagano tutti» tratteggiata verde e «Senza le rate» tratteggiata grigia, i due limiti.
 */
export function datiDelGraficoGrande(sp: SaldoPrevisto, recuperato: number, colori: ColoriDelGraficoGrande) {
    const linea = (label: string, valori: number[], altro: Record<string, unknown>) => ({ label, data: valori.map(inEuro), stepped: true as const, pointRadius: 0, tension: 0, ...altro });

    return {
        labels: sp.giorni.map((g) => giornoBreve(g.giorno)),
        datasets: [
            linea('Simulazione', simulazione(sp, recuperato), { borderColor: colori.linea, borderWidth: 2.5, pointHoverRadius: 4,
                fill: { target: { value: 0 }, below: colori.zona, above: colori.zonaSopra } }),
            linea(SCENARI[2].etichetta, saldiDelloScenario(sp, 'tutti'), { borderColor: colori.verde, borderWidth: 1.5, borderDash: [5, 4], pointHoverRadius: 3, fill: false }),
            linea(SCENARI[0].etichetta, saldiDelloScenario(sp, 'senza'), { borderColor: colori.grigio, borderWidth: 1.5, borderDash: [5, 4], pointHoverRadius: 3, fill: false }),
        ],
    };
}

/** Le opzioni del grafico del cassetto: la legenda è nella pagina, le date in basso, lo zero marcato, il riquadro con le tre linee. */
export function opzioniDelGraficoGrande(sp: SaldoPrevisto, colori: ColoriDelGraficoGrande) {
    const font = { size: 12 };
    const eZero = (ctx: { tick: { value: number } }) => ctx.tick.value === 0;
    const asse = (euro: number) => `${euro < 0 ? '−' : ''}€ ${new Intl.NumberFormat('it-IT', { maximumFractionDigits: 0, useGrouping: 'always' } as Intl.NumberFormatOptions).format(Math.abs(Math.round(euro)))}`;

    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false as const,
        layout: { padding: { top: 12, bottom: 4 } },
        interaction: { mode: 'index' as const, intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                titleFont: font,
                bodyFont: font,
                callbacks: {
                    title: (voci: Array<{ dataIndex: number }>) => (voci.length ? giornoIntero(sp.giorni[voci[0].dataIndex].giorno) : ''),
                    label: (voce: { dataset: { label?: string }; parsed: { y: number } }) => `${voce.dataset.label}: ${euroSegno(Math.round(voce.parsed.y * 100))}`,
                },
            },
        },
        scales: {
            x: { position: 'bottom' as const, grid: { display: false }, border: { color: colori.griglia },
                ticks: { color: colori.testo, font, maxRotation: 0, autoSkip: true, maxTicksLimit: 7 } },
            y: {
                beginAtZero: true,
                grace: '5%',
                border: { display: false },
                grid: {
                    color: (ctx: { tick: { value: number } }) => (eZero(ctx) ? colori.zero : colori.griglia),
                    lineWidth: (ctx: { tick: { value: number } }) => (eZero(ctx) ? 2 : 1),
                },
                ticks: { color: colori.testo, font, maxTicksLimit: 6, callback: (valore: number | string) => asse(Number(valore)) },
            },
        },
    };
}

/** I fatti scritti sul grafico, come un pezzo di Chart.js: un cartellino arrotondato sopra o sotto il punto della simulazione, dentro il grafico. */
export function pluginDeiFatti(fatti: () => Array<{ indice: number; testo: string; sopra: boolean }>, colori: () => ColoriDelGraficoGrande) {
    return {
        id: 'fattiDelGrafico',
        afterDatasetsDraw(chart: { ctx: CanvasRenderingContext2D; chartArea: { left: number; right: number; top: number; bottom: number };
            getDatasetMeta: (i: number) => { data: Array<{ x: number; y: number }> } }) {
            const ctx = chart.ctx;
            const punti = chart.getDatasetMeta(0).data;
            const c = colori();
            ctx.save();
            ctx.font = '600 12px system-ui, sans-serif';
            for (const f of fatti()) {
                const punto = punti[f.indice];
                if (!punto) continue;
                const larghezza = ctx.measureText(f.testo).width + 14;
                const altezza = 22;
                const x = Math.min(Math.max(punto.x - larghezza / 2, chart.chartArea.left), chart.chartArea.right - larghezza);
                const y = Math.min(Math.max(f.sopra ? punto.y - altezza - 10 : punto.y + 10, chart.chartArea.top), chart.chartArea.bottom - altezza);
                ctx.fillStyle = c.sfondo;
                ctx.strokeStyle = c.bordo;
                ctx.lineWidth = 1;
                ctx.beginPath();
                if (typeof ctx.roundRect === 'function') ctx.roundRect(x, y, larghezza, altezza, 6);
                else ctx.rect(x, y, larghezza, altezza);
                ctx.fill();
                ctx.stroke();
                ctx.fillStyle = c.scritta;
                ctx.textBaseline = 'middle';
                ctx.fillText(f.testo, x + 7, y + altezza / 2);
            }
            ctx.restore();
        },
    };
}

/** La descrizione del grafico del cassetto per chi non lo vede (`aria-label`). */
export function descrizioneDelGraficoGrande(sp: SaldoPrevisto, recuperato: number): string {
    const sim = cifreDellaSimulazione(simulazione(sp, recuperato));

    return `Il saldo nei prossimi 30 giorni in tre linee. La simulazione, con ${euroSegno(recuperato)} recuperati delle rate scadute: al più basso ${euroSegno(sim.minimo)}, `
        + `fra 30 giorni ${euroSegno(sim.fine)}. Se pagano tutti: fra 30 giorni ${euroSegno(saldiDelloScenario(sp, 'tutti').at(-1) ?? 0)}. `
        + `Senza le rate: fra 30 giorni ${euroSegno(saldiDelloScenario(sp, 'senza').at(-1) ?? 0)}.`;
}
