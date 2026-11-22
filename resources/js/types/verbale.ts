// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le forme del verbale dell'assemblea, come le mandano il server e le pagine: la prop `pagina` della pagina «Il verbale»
 * (`admin.gestionale.assemblee.verbale.pagina`, e la lettura periodica `…verbale.stato`), i gesti del testo e le loro risposte, la discussione nella
 * sala, il registro dei verbali e quello di nomina e revoca. Il testo è la struttura dell'editor di `resources/js/lib/assemblee/verbale/struttura.ts`.
 *
 * Gli istanti sono ISO 8601 in UTC («2026-12-15T19:42:00Z»); i giorni «2026-12-15»; i millesimi in centomillesimi interi solo nei dati della sala,
 * qui già in parole nelle righe.
 */

import type { NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';

export type StatoDelVerbale = 'in_sala' | 'bozza' | 'registrato' | 'annullato';

/** `intero` (il verbale generato) o `punto:<id del punto>` (la discussione scritta sotto il voto, in sala o nella pagina). */
export type ParteDelVerbale = 'intero' | `punto:${number}`;

// ─── I gesti del testo ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Il gesto `testo`: la parte intera, come la vede l'editor. Si manda a `admin.gestionale.assemblee.verbale.gesti` o, dalla sala, insieme agli altri a
 * `admin.gestionale.assemblee.sala.gesti`. `verbale_id`, o `seduta_id` per la prima discussione di una seduta che non ha ancora il verbale (nasce
 * «in sala»). `base` è la `revisione` da cui l'editor è partito (0 per la prima volta).
 */
export interface DatiDelTesto {
  verbale_id?: number;
  seduta_id?: number;
  parte: ParteDelVerbale;
  documento: NodoDelVerbale;
}

export interface GestoDelTesto {
  /** Il numero del gesto (UUID), lo stesso se si rimanda: due volte conta una. */
  gesto: string;
  tipo: 'testo';
  dati: DatiDelTesto;
  base: number;
  /** L'orologio del dispositivo e l'ora del gesto, ISO 8601: il server tiene l'ora del gesto (non più di 120 secondi nel futuro). */
  orologio: string;
  segnato_il: string;
}

export interface InvioDeiGestiDelTesto {
  dispositivo: string;
  gesti: GestoDelTesto[];
}

export type EsitoDelGestoDelTesto = 'applicato' | 'doppio' | 'in_conflitto' | 'rifiutato';

/** Le due versioni: un altro dispositivo ha scritto la parte dopo la `base`, o il verbale è registrato (la versione resta «in attesa»). */
export interface ConflittoDelTesto {
  tipo: 'due_versioni' | 'dopo_la_registrazione';
  versione_id: number;
  revisione_di_adesso: number;
  dispositivo_di_adesso: string | null;
  scritta_il_di_adesso: string | null;
}

export interface RispostaAlGestoDelTesto {
  gesto: string;
  tipo: 'testo';
  esito: EsitoDelGestoDelTesto;
  messaggio: string | null;
  verbale_id: number | null;
  parte: ParteDelVerbale | null;
  /** La revisione di adesso: la `base` del prossimo gesto della parte. */
  revisione: number | null;
  impronta: string | null;
  /** La versione fatta dalla pausa (il testo di prima), se il gesto ne ha fatta una. */
  versione: number | null;
  versione_id?: number | null;
  conflitto?: ConflittoDelTesto | null;
}

export interface RispostaDeiGestiDelTesto {
  ora_del_server: string;
  risposte: RispostaAlGestoDelTesto[];
}

// ─── La discussione nella sala ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** `sala.verbale`: il verbale della seduta aperta, con la discussione di ogni punto già scritto (la chiave è l'id del punto). */
export interface VerbaleDellaSala {
  seduta_id: number;
  /** Nullo finché nessuno scrive: il primo testo manda `seduta_id`. */
  id: number | null;
  stato: StatoDelVerbale | null;
  discussioni: Record<string, { parte: ParteDelVerbale; documento: NodoDelVerbale; revisione: number; impronta: string }>;
}

// ─── La pagina «Il verbale» ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

export interface RiunioneDelVerbale {
  seduta_id: number;
  giorno: string;
  convocazione: 'prima' | 'seconda';
  numero: number;
  aperta_il: string | null;
  chiusa_il: string | null;
  esito: 'tenuta' | 'sospesa' | 'rinviata' | 'deserta' | null;
  verbale: { id: number; stato: StatoDelVerbale; numero: number | null } | null;
}

export interface VoceDellaVersione {
  id: number;
  parte: ParteDelVerbale;
  numero: number;
  /** «correzioni», «scritta in sala: la discussione del punto 4», «prima di Ripristina la versione 2». */
  perche: string | null;
  impronta: string;
  dispositivo: string | null;
  /** L'ora in cui il testo è stato scritto; `arrivata_il` l'ora in cui è arrivato (diverse per ciò che arriva senza rete). */
  segnata_il: string | null;
  arrivata_il: string | null;
  chi: string | null;
  in_attesa: boolean;
  /** `dopo_la_generazione`: la discussione di un punto arrivata dopo «Genera», da mettere nel punto o lasciare fra le versioni. */
  attesa: 'due_versioni' | 'dopo_la_registrazione' | 'dopo_la_generazione' | null;
  scelta: 'questa' | 'quella' | 'uniscile' | 'vista' | 'metti' | null;
  scelta_il: string | null;
  ripristina?: boolean;
}

/** Una cifra della parte «Dati della sala» che non torna con i voti registrati. */
export interface CifraCheNonTorna {
  chiave: string;
  punto: number | null;
  votazione_id: number | null;
  occorrenza: number;
  nel_testo: string;
  adesso: string;
  /** «Nel punto 4 il verbale dice «343,70», i voti registrati dicono 343,75.» */
  frase: string;
  rimetti: string;
  vai_al_voto: string | null;
}

export interface StatoDiChiScrive {
  parte: ParteDelVerbale;
  scrive: { nome: string; dispositivo: string; sono_io: boolean; dal: string } | null;
  richiesta: { nome: string; dispositivo: string; mia: boolean; per_me: boolean; secondi: number } | null;
  esito_della_richiesta: { esito: 'lasciato' | 'continua' | 'scaduta'; di: string; il: number } | null;
  aperto_da: { dispositivo: string; nome: string; parte: ParteDelVerbale }[];
}

export interface TerminiDiUnaPersona {
  punti: number[];
  /** `assente`, `fuori`, `astenuto`, `contrario`, o «aggiunto» dall'amministratore. */
  perche: string;
  dal: string | null;
  /** «Può impugnare fino al»: il conto prudente (trenta giorni dal giorno dopo la consegna, agosto escluso, la festa al primo giorno lavorativo). */
  fino_al: string | null;
  da_consegnare: boolean;
  /** Per chi era assente, da quale giorno: `consegna`, `ritiro` (la raccomandata in giacenza poi ritirata), `compiuta_giacenza` (non ritirata). */
  dal_come?: 'consegna' | 'ritiro' | 'compiuta_giacenza' | null;
}

export interface DestinatarioDelVerbale {
  anagrafica_id: number;
  nome: string;
  unita: string;
  gruppo: 'mai_entrati' | 'fuori' | 'cera' | 'aggiunti' | 'tutti';
  perche: string;
  punti_assente: number[];
  /** Per numero del punto, come ha contato: `favorevole`, `contrario`, `astenuto`, `non_votato` (vuoto: mai entrato). */
  voti: Record<string, string> | [];
  proposto: boolean;
  riceve: boolean;
  tolto: { perche: string; il: string; da: string } | null;
  aggiunto: { punti: number[]; perche: string; il: string; da: string } | null;
  convocato: boolean;
  mezzo: { mezzo: 'pec' | 'raccomandata' | 'mano' | 'email' | string; parola: string; dalla_convocazione: boolean; indirizzo: string | null; perche: string | null };
  invio: { id: number; canale: string; mezzo: string | null; stato: string; esito: string | null; esito_parola: string | null; esito_il: string | null;
    ricevuta_il: string | null; spedito: boolean; errore: string | null;
    /** L'ora in cui la lettera o la PEC è nata con la copia di allora: dopo «Sostituisci la copia» dice chi ha quella di prima. */
    creato_il?: string | null } | null;
  termini: TerminiDiUnaPersona[];
}

export interface TrasmissioneDelVerbale {
  /** In videoconferenza o mista il verbale va a tutti i convocati. */
  videoconferenza: boolean;
  punti: { punto_id: number; numero: number; testo: string; votazione_id: number; votata_il: string | null; delibera_id: number | null; esito: string;
    deliberata_il: string | null }[];
  persone: DestinatarioDelVerbale[];
  quanti: number;
  spediti: number;
  consegnati: number;
  per_mezzo: Record<string, number>;
}

export interface TerminiDellaDelibera {
  delibera_id: number;
  punto: number;
  esito: string;
  deliberata_il: string;
  chi: { anagrafica_id: number; nome: string; perche: string; dal: string | null; fino_al: string | null }[];
  /** L'ultimo dei «fino al», quando ogni assente ha ricevuto il verbale; null finché manca una consegna. */
  per_tutti: string | null;
}

export interface VerbaleDellaPagina {
  id: number;
  seduta_id: number;
  stato: StatoDelVerbale;
  stato_parola: string;
  registro: { chiave: string; nome: string; scala_id: number | null; palazzina_id: number | null;
    partenza: { scelta: 'libro' | 'da_uno'; ultimo: number | null; del: string | null; si_cambia: boolean } | null; numero_proposto: number | null };
  numero: number | null;
  modello: { id: number | null; nome: string } | null;
  generato_il: string | null;
  /** «I dati della sala sono cambiati alle 21:20: aggiorna le parti segnate». */
  sala_cambiata: { alle: string | null } | null;
  cifre: CifraCheNonTorna[];
  /** Le parti dei voti cambiate a mano senza toccare le cifre: un avviso leggero, che non ferma la stampa. */
  a_mano?: { chiave: string; punto: number | null; frase: string }[];
  /**
   * La bozza stampata da firmare; `cambiata` è il perché se il testo o i dati della sala sono cambiati dopo («Il testo è cambiato dopo la bozza stampata alle
   * 21:02 (versione 3): ristampala…»): la pagina dice «Cambiato dopo la stampa», e «Registra» si ferma.
   */
  stampata: { versione: number; impronta_testo: string; impronta_sala?: string; impronta: string; il: string; da: number; da_nome: string;
    cambiata?: string | null } | null;
  sottoscritto_il: string | null;
  registrato_il: string | null;
  registrato_da: string | null;
  /** `sostituita_il`: l'ultima volta che la copia è stata sostituita; chi ha l'invio partito prima ha la copia di prima. */
  copia: { id: number; nome: string; peso: number; url: string; sostituita_il?: string | null } | null;
  annullato: { il: string; motivo: string; da: string | null } | null;
  /** Il numero del verbale annullato che questo sostituisce. */
  sostituisce: number | null;
  nel_libro_il: string | null;
  parti: Record<string, { documento: NodoDelVerbale; revisione: number; impronta: string; caratteri: number; dispositivo: string | null; scritta_il: string | null }>;
  punti: { punto_id: number; numero: number; testo: string; parte: ParteDelVerbale; votazioni: { id: number; esito: string | null; riga: string }[];
    dichiarazioni: { id: number; nome: string; testo: string; punto_id: number | null }[] }[];
  versioni: VoceDellaVersione[];
  in_attesa: (VoceDellaVersione & { nome_della_parte: string; documento: NodoDelVerbale })[];
  /** Per parte; null quando la lettura non dice il dispositivo. */
  chi_scrive: Record<string, StatoDiChiScrive> | null;
  trasmissione: TrasmissioneDelVerbale | null;
  termini: { delibere: TerminiDellaDelibera[]; per_tutti: string | null } | null;
}

export interface ModelloNelMenu {
  /** Null per il testo del programma. */
  id: number | null;
  nome: string;
  gruppo: 'condominio' | 'tutti' | 'programma';
  ultimo_usato: boolean;
  cambiato_da: string | null;
  cambiato_il: string | null;
}

export interface RotteDelVerbale {
  pagina: string; stato: string; gesti: string; genera: string; sala: string; assemblea: string; registro: string;
  aggiorna_sala: string | null; bozza: string | null; registra: string | null; copia: string | null; annulla: string | null; libro: string | null;
  libro_pagina: string | null; scrivo: string | null; chiedi: string | null; rispondi: string | null; togli: string | null; aggiungi: string | null;
  pec: string | null; lettere: string | null; spedizione: string | null; prova: string | null;
}

/** La prop della pagina «Il verbale», e la risposta della lettura periodica. */
export interface PaginaDelVerbale {
  assemblea: { id: number; nome: string; titolo: string; stato: string; stato_parola: string; modalita: string | null; tipo: string | null };
  ora_del_server: string;
  riunioni: RiunioneDelVerbale[];
  /** La riunione che la pagina mostra, con i suoi punti e la riga della sala: anche prima che il verbale nasca con la prima discussione. */
  seduta_id: number | null;
  punti: VerbaleDellaPagina['punti'];
  verbale: VerbaleDellaPagina | null;
  modelli: { modelli: ModelloNelMenu[]; proposto: number | null; ultimo_usato: number | null };
  puo: { gestire: boolean; registrare: boolean };
  rotte: RotteDelVerbale;
}

// ─── Il registro dei verbali e quello di nomina e revoca ───────────────────────────────────────────────────────────────────────────────────────────────

export interface PerimetroDelRegistro {
  /** `condominio`, `scala:<id>`, `palazzina:<id>`. */
  chiave: string;
  nome: string;
  scala_id: number | null;
  palazzina_id: number | null;
}

export interface RigaDelRegistroDeiVerbali {
  tipo: 'verbale' | 'annotazione' | 'a_mano';
  id: number;
  numero: number | string | null;
  giorno: string | null;
  assemblea: { id: number; nome: string; parola: string; perimetro: string | null };
  stato: string;
  /** «Sottoscritto», «Annullato», «Mancata costituzione», «A mano». */
  parola: string;
  /** «5 delibere · 1 dichiarazione». */
  contenuto: string;
  sottoscritto_il?: string | null;
  registrato_il?: string | null;
  registrato_da?: string | null;
  annullato?: { il: string; da: string | null; motivo: string } | null;
  sostituisce?: number | null;
  nel_libro_il?: string | null;
  documento?: { id: number; nome: string } | null;
  verbale_caricato?: boolean;
}

export interface RigaDiNomina {
  id: number;
  evento: 'nomina' | 'revoca';
  evento_parola: string;
  amministratore: string;
  dal: string;
  da_dove: 'carta' | 'verbale' | 'decreto';
  estremi: string | null;
  decreto: { autorita: string; numero: string; data: string } | null;
  delibera_id: number | null;
  verbale: { id: number; numero: number | null } | null;
  documento: { id: number; nome: string } | null;
  nota: string | null;
  stato: 'proposta' | 'confermata' | 'annullata';
  stato_parola: string;
  annullata_motivo: string | null;
}

/** Il passo 5 della pagina dell'assemblea, quando si apre: le riunioni con il loro verbale e l'indirizzo della sua pagina, e le rotte. */
export interface VerbaleDelPasso {
  riunioni: (RiunioneDelVerbale & { url: string })[];
  rotte: RotteDelVerbale;
}

/** La prop della pagina del registro dei verbali (`admin.gestionale.assemblee.registro`, `?registro=scala:5&anno=2026`). */
export interface PaginaDelRegistroDeiVerbali {
  perimetro: PerimetroDelRegistro;
  registri: (PerimetroDelRegistro & { partenza: VerbaleDellaPagina['registro']['partenza']; prossimo_numero: number })[];
  partenza: VerbaleDellaPagina['registro']['partenza'];
  prossimo_numero: number;
  anni: number[];
  anno: number | null;
  righe: RigaDelRegistroDeiVerbali[];
  nomine: RigaDiNomina[];
  /** Vero finché il registro di nomina e revoca non ha la nomina in corso: la prima volta si scrive a mano. */
  senza_la_nomina_in_corso: boolean;
  amministratore_di_oggi: string | null;
  puo: { registrare: boolean };
  rotte: { partenza: string; stampa: string; libro: string; nomine: string };
}
