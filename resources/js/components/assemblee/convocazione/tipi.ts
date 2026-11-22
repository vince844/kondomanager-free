// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le forme che il server manda ai passi «Convocazione» e «Documenti» della pagina dell'assemblea: la prop `convocazione`
 * (`StatoDellaConvocazione::perLaPagina()`) e la prop `fascicolo` (`FascicoloDellAssemblea::perLaPagina()`). Le frasi arrivano già scritte dal server, con
 * le date all'italiana e le cifre in euro: la pagina le mostra come sono.
 */
import type { EsitoInvio, MezzoConvocazione } from '@/types/assemblee';

/** Il colore di un badge o di una nota, con il suo significato: verde fatto, ambra in attesa, azzurro in viaggio, grigio da fare, rosso un problema. */
export type Colore = 'em' | 'am' | 'sk' | 'sl' | 'ro' | 'or';

/** Un giorno della settimana del conto («gio 03 · spedisci · non conta»). */
export interface GiornoDelConto {
  giorno: string;
  nota: string;
  /** `no` non conta, `po` un giorno di posta, `ar` arriva in tempo, `tardi` arriva dopo il termine. */
  tipo: 'no' | 'po' | 'ar' | 'tardi';
}

/**
 * «Entro quando spedire» (scena 3 della bozza delle scene della tappa 2, decisione 565), con «Come conto» sempre accanto e le righe d'aiuto sotto il campo
 * (la bozza approvata del passo «Convocazione» senza spazi vuoti).
 */
export interface EntroQuandoSpedire {
  /** «Per la prima convocazione di lunedì 14/12 devono arrivare entro mercoledì 09/12: il giorno in cui arrivano conta, il 14/12 no.» */
  frase: string;
  /** La frase corta per il telefono: «Devono arrivare entro mercoledì 09/12.» */
  frase_breve: string;
  ultimo_giorno: string;
  ultimo_giorno_parole: string;
  /** Oggi a Roma (`aaaa-mm-gg`): il primo giorno che il campo lascia scegliere. */
  oggi: string;
  raccomandate: {
    quante: number;
    /** «Spedisci le raccomandate entro giovedì 03/12». */
    frase: string;
    entro: string;
    entro_parole: string;
    proposta: string;
    proposta_parole: string;
    scelta_da_te: boolean;
    /** «Torna alla proposta (gio 03/12)», con la data scelta; null con la proposta. */
    torna: string | null;
    /** «Il giorno è già passato: scegli oggi o un giorno dopo.», sotto una data salvata che è passata con le raccomandate ancora da spedire. */
    passata: string | null;
  } | null;
  /** La prima convocazione non ha più i cinque giorni dell'art. 66 c. 3 (contando da oggi): la frase con i due giorni, la stessa del passo 1; null se c'è il tempo. */
  vicina: string | null;
  /** «PEC (11) e consegne a mano (10)» → «entro mercoledì 09/12». */
  altri: { etichetta: string; entro_parole: string } | null;
  /** «Come conto giovedì 03/12»: sulla data che vale (la proposta o quella scelta), giorno per giorno, e se arriva in tempo. */
  conto: {
    titolo: string;
    frase: string;
    giorni: GiornoDelConto[];
    /** Le feste che non contano, quando ce ne sono: «Non contano … e martedì 08/12, l'Immacolata. …». */
    sotto: string | null;
    /** «Arriva in tempo» (verde) o «Arriva tardi» (arancio), in grassetto, e la frase che segue. */
    esito: { in_tempo: boolean; titolo: string; frase: string };
    piede: string;
  } | null;
  /** Le righe d'aiuto sotto il campo: con la proposta «Puoi scegliere …» e il compito con i giorni del promemoria; con la data scelta «Scelta da te il …». */
  righe: string[];
  /** «Che cosa segue la data che scegli»: il compito, il promemoria per email, le raccomandate stampate. Vuoto con la proposta. */
  segue: string[];
  /** «3 lavorativi», e dove si cambiano (l'impostazione delle assemblee) per chi può; null per gli altri. */
  giorni_di_posta: { adesso: string; dove: string | null };
  /** La data si cambia (gestisce le assemblee, e l'assemblea non è chiusa). */
  si_cambia: boolean;
  /** Con un punto «Modifica della destinazione d'uso»: venti giorni e l'affissione (art. 1117-ter), con le sue due date. */
  destinazione_uso: {
    frase: string;
    mancano: string[];
    affissione: { dal: string | null; al: string | null; si_cambia: boolean };
  } | null;
}

/** Lo stato di una persona nella tabella dei convocati: le cinque parole della bozza e le altre. */
export type StatoDelConvocato =
  | 'da_spedire'
  | 'stampata'
  | 'da_consegnare'
  | 'spedita'
  | 'non_partita'
  | 'da_verificare'
  | 'non_convocabile'
  | 'da_ristampare'
  | 'non_piu_convocato'
  | EsitoInvio;

/** Una riga della tabella «Convocati» (colonne fisse Persona, Unità, Mezzo, Stato, Prova). */
export interface RigaDelConvocato {
  chiave: string;
  anagrafica_id: number | null;
  nome: string;
  /** «usufruttuaria», «nudo proprietario», «conduttore, per il punto 5». */
  ruolo: string | null;
  /** «Int. 3, box 21». */
  unita: string;
  /** «½» per i comproprietari. */
  quota: string | null;
  mezzo: MezzoConvocazione | null;
  /** «PEC», «Raccomandata», «A mano», «Nessun mezzo». */
  mezzo_parola: string;
  /** Il mezzo proposto dai dati (la persona non lo ha scelto). */
  mezzo_proposto: boolean;
  pec_non_comunicata: boolean;
  stato: StatoDelConvocato;
  stato_parola: string;
  colore: Colore;
  /** «03/12 alle 10:14», «avviso del 07/12: conta da qui», «ha solo l'email: aggiungi la PEC o l'indirizzo». */
  dettaglio: string | null;
  /** «Ricevuta», «Foglio», «Avviso», «Cartolina». */
  prova: { nome: string; url: string } | null;
  invio_id: number | null;
  /** Il PDF dell'avviso conservato per questa persona. */
  pdf_url: string | null;
  /** Dentro i cinque giorni: vero, falso, o null finché non è ricevuta. */
  in_tempo: boolean | null;
  /** Che cosa si può fare sulla riga: anche il ritiro dopo la giacenza, e la ricevuta PEC trascinata sulla riga. */
  azioni: { spedizione: boolean; consegna: boolean; correggi: boolean; prova: boolean; ritiro: boolean; ricevuta: boolean };
  /** Per quale pulsante conta la riga: «Stampa le lettere» per chi si stampa, «Manda le PEC» per chi si manda; le spunte contano solo le righe del loro mezzo. */
  per: { lettere: boolean; pec: boolean };
  /**
   * Il mezzo si sceglie nella riga, per questa assemblea (decisione 647): `si_puo` finché l'avviso non è spedito, per chi gestisce le assemblee; la PEC solo con
   * `ha_pec`; `per_assemblea` dice che il mezzo mostrato è una scelta di questa assemblea; `della_scheda` è quello che c'è nella scheda della persona.
   */
  scelta: { si_puo: boolean; ha_pec: boolean; per_assemblea: boolean; della_scheda: MezzoConvocazione | null };
  /** I fatti della riga, per «Registra la consegna» e la correzione: l'esito di oggi, il giorno e l'ora. */
  esito: EsitoInvio | null;
  ricevuta_il: string | null;
  esito_il: string | null;
}

/** Un pulsante con il numero e il perché quando è spento. */
export interface PulsanteDellaScheda {
  quante: number;
  attivo: boolean;
  perche: string | null;
  /** Solo per le PEC, senza la casella: la pagina delle caselle dello studio, per chi la può aprire (decisione 572). */
  dove?: string | null;
  /** Solo per le PEC: l'ultima prova della casella non è andata, e la conferma lo dice prima di mandare. */
  avviso?: string | null;
  /** Solo per le lettere: «Hai stampato le lettere il 26/11: ristampale, perché l'avviso è cambiato.» */
  ristampa?: string | null;
}

/** Il passo «Convocazione» (prop `convocazione`): vuoto finché l'assemblea non ha le date. */
export interface SchedaDellaConvocazione {
  /** La spedizione registrata dopo l'ultimo giorno utile (art. 66 disp. att.), con il primo giorno valido; non blocca. */
  tardiva?: { frase: string; dal: string } | null;
  /** La frase della spedizione di oggi per canale, se arriverebbe tardi. */
  tardiva_oggi?: Record<string, string | null> | null;
  /** Lo stato in una parola per la pagina: non ancora spedita, in corso, spedita a tutti, annullata. */
  stato: 'non_spedita' | 'in_corso' | 'spedita' | 'annullata' | 'chiusa';
  entro: EntroQuandoSpedire | null;
  convocati: {
    totale: number;
    /** «26 condòmini, più l'usufruttuaria e il conduttore che il punto 5 chiama. I due comproprietari dell'interno 5 ricevono un avviso ciascuno.» */
    descrizione: string;
    /** «Si convoca chi risulta dall'anagrafe il giorno dell'invio…»: vero dopo il primo invio (l'elenco è fissato). */
    fissati: boolean;
    righe: RigaDelConvocato[];
  };
  lettere: PulsanteDellaScheda;
  pec: PulsanteDellaScheda;
  /** Ciò che spegne tutti e due i pulsanti (il luogo o la piattaforma che mancano): `perche` è la frase corta, `lungo` quella lunga che sta anche in `lettere.perche`. */
  comune?: { perche: string; lungo: string } | null;
  /** «11 PEC non risultano comunicate dalla persona: segnalo nella sua scheda, o scegli la raccomandata». */
  pec_non_comunicate: string | null;
  /** In videoconferenza senza il giorno del consenso della maggioranza (art. 66 c. 6): la scheda lo dice, senza bloccare. */
  videoconferenza: string | null;
  /** La nota arancio del venduto dopo l'invio. */
  venduti: { titolo: string; frase: string; anagrafica_id: number | null }[];
  bacheca: { acceso: boolean; adesso: string; si_puo: boolean; perche: string | null; url: string | null };
  prova: { attivo: boolean; perche: string | null };
  foglio: { quante: number };
  /** «L'email semplice si può mandare come avviso in più, ma non vale come convocazione (art. 66 c. 3; Cass. 16399/2025).» */
  email_semplice: string;
  riconvoca: { si_puo: boolean; perche: string | null; costo: string | null };
  gestisce: boolean;
  /** Gli indirizzi dei gesti della scheda, già con il condominio e l'assemblea. */
  rotte: {
    lettere: string;
    pec: string;
    spedizione: string;
    /** Il passo «Date e ordine del giorno». */
    date?: string;
    prova: string;
    foglio: string;
    foglio_firmato: string;
    bacheca: string;
    raccomandate_entro: string;
    riconvoca: string;
    /** La modifica dell'assemblea, per le due date dell'affissione. */
    affissione: string;
    /** Con `__INVIO__` al posto dell'id dell'invio. */
    consegna: string;
    prova_invio: string;
    ricevuta: string;
    /** Il mezzo di una persona per questa assemblea: PATCH con `anagrafica_id`, `mezzo` (vuoto: torna quello della scheda) e `ricorda`. */
    mezzo?: string;
  };
}

/** Una riga del fascicolo nel passo «Documenti» (colonne fisse #, Punto, Documento, Stato). */
/** Un documento di un punto, o proposto per un punto: il nome del file e le pagine (`null` se il file non si legge). */
export interface DocumentoDelPunto {
  id: number;
  nome: string;
  pagine: number | null;
}

/** I PDF che il programma propone per un punto, dalla stessa scheda: «Proposto dalla fattura n. 45», «Dalla fattura» al telefono. */
export interface PropostaDelPunto {
  da: string;
  breve: string;
  documenti: DocumentoDelPunto[];
  pagine: number | null;
}

export interface RigaDelFascicolo {
  /** «—» per la copertina e l'avviso, poi il numero del punto. */
  numero: string;
  /** L'id del punto; `null` per la copertina. */
  punto_id: number | null;
  punto: string;
  documento: string;
  /** Il documento in grigio: una spiegazione, non un file. */
  documento_muto: boolean;
  /** La riga grigia sotto il punto: la scheda da cui nasce («Fattura n. 45 · Idraulica Bianchi · € 1.800,00»). */
  sotto: string | null;
  stato: { parola: string; colore: Colore } | null;
  /** «Ferma il preventivo» al posto dello stato. */
  azione: 'ferma_preventivo' | null;
  /** Il punto prende documenti: tutti tranne la copertina, il rendiconto e il preventivo. */
  prende: boolean;
  /** I documenti aggiunti, nell'ordine in cui sono stati aggiunti. */
  documenti: DocumentoDelPunto[];
  /** Le proposte del programma, mai aggiunte da sole. */
  proposte: PropostaDelPunto[];
  /** «Aggiungi un documento» e «Togli»: chi gestisce, in preparazione. */
  aggiungi: boolean;
  togli: boolean;
}

/** Il fascicolo del passo «Documenti» (prop `fascicolo`). */
export interface SchedaDelFascicolo {
  righe: RigaDelFascicolo[];
  /** «Il preventivo non è ancora fermato: lo fermi prima di spedire, così resta che cosa hanno ricevuto i condòmini.» */
  frase: string | null;
  /** Il riepilogo del piede: i documenti, le loro pagine, il peso in MB sul tetto; `dire` dalla metà del tetto, `vicino` dall'ottanta per cento. */
  riepilogo: { documenti: number; pagine: number; mb: number; tetto: number; dire: boolean; vicino: boolean };
  /** Il nome del condominio: «Dai documenti di Via Roma 12». */
  condominio: string;
  /** I documenti dei punti si aggiungono e si tolgono: chi gestisce, in preparazione. */
  modificabile: boolean;
  componi: PulsanteDellaScheda;
  per_persona: PulsanteDellaScheda;
  ferma_preventivo: PulsanteDellaScheda;
  gestisce: boolean;
  /** `documenti`, `aggiungi` e `togli` con `__PUNTO__` e `__DOCUMENTO__` al posto degli id. */
  rotte: { componi: string; per_persona: string; ferma_preventivo: string; documenti: string; aggiungi: string; togli: string };
}

/** La finestra «Aggiungi un documento»: il JSON della rotta `documenti`. */
export interface FinestraDeiDocumenti {
  punto: { numero: number; testo: string; sotto: string | null };
  proposti: (DocumentoDelPunto & { da: string })[];
  condominio: {
    nome: string;
    /** I documenti dell'archivio del condominio (mai quelli di un'unità); `di_una_persona`: legato a una persona, arriva lo stesso a tutti i convocati. */
    documenti: (DocumentoDelPunto & { pdf: boolean; categorie: string; caricato_il: string | null; gia: boolean; di_una_persona?: boolean })[];
    /** Quanti altri ce ne sono oltre a quelli mostrati: si trovano con la ricerca. */
    altri: number;
  };
  /** Il tetto del file nuovo, «20 MB». */
  tetto: string;
}
