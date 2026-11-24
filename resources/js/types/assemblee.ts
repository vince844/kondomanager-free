// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import type { DatiDelTesto, VerbaleDellaSala } from './verbale';

/**
 * Le assemblee (Coda 679, §1 del progetto della tappa 2): i tipi dei dati che il server manda alle pagine dell'assemblea, della cerniera, del memo, della
 * convocazione e della sala. Le date sono `YYYY-MM-DD`; le ore delle sedute sono «come si leggono» a Roma (`YYYY-MM-DD HH:MM:SS`); gli istanti della sala
 * (le entrate, i voti, i gesti) sono ISO 8601 in UTC; gli importi sono in centesimi, i millesimi della sala in centomillesimi (1.000,00 = 100.000.000).
 */

export type AmbitoAssemblea = 'condominio' | 'palazzina' | 'scala';
export type TipoAssemblea = 'ordinaria' | 'straordinaria';
export type OrigineAssemblea = 'programma' | 'a_mano';
export type StatoAssemblea = 'in_preparazione' | 'convocata' | 'annullata' | 'registrata_a_mano' | 'in_corso' | 'deserta' | 'chiusa';
export type ModalitaAssemblea = 'presenza' | 'videoconferenza' | 'mista';
export type ConvocazioneSeduta = 'prima' | 'seconda';

/** Lo specchio di `App\Enums\Assemblee\MateriaPunto`, con le materie della maggioranza fissata dalla legge (decisione 640 (1)). */
export type MateriaPunto =
  | 'rendiconto' | 'preventivo' | 'lavori' | 'innovazioni' | 'innovazioni_agevolate' | 'impianti_singoli' | 'videosorveglianza' | 'progetti_territorio'
  | 'ratifica' | 'comunicazione_urgenza' | 'dispensa' | 'accordo' | 'regole' | 'fondo' | 'nomina' | 'liti' | 'tabelle' | 'rettifica_tabelle' | 'riparto'
  | 'regolamento' | 'sito_internet' | 'riscaldamento' | 'destinazione_uso' | 'tutela_destinazione_uso' | 'comunicazione' | 'delega' | 'varie' | 'altro';
export type Maggioranza = 'ordinaria' | 'meta_del_valore' | 'due_terzi' | 'quattro_quinti' | 'unanimita' | 'libera' | 'senza_voto';
export type AmministrazionePunto = 'ordinaria' | 'straordinaria';
export type ConduttorePunto = 'vota' | 'interviene';
export type FontePunto = 'di_serie' | 'memo' | 'programma' | 'nota_sintetica' | 'a_mano';
export type RuoloOggettoPunto = 'origine' | 'parla_di' | 'documento';

export type EsitoDelibera = 'approvata' | 'approvata_con_modifiche' | 'respinta' | 'rinviata' | 'senza_voto';
export type RuoloCollegamento =
  | 'delibera' | 'riferimento' | 'ratifica' | 'competenza' | 'origine_della_spesa' | 'dispensa' | 'revoca_della_dispensa' | 'autorizzazione' | 'fonte'
  | 'base' | 'deroga' | 'nota' | 'causale' | 'approvazione' | 'delega';

export type StatoMemo = 'in_attesa' | 'nel_punto' | 'deliberato' | 'non_serve';
export type AChiMemo = 'me' | 'studio' | 'collaboratore';

export type MezzoConvocazione = 'pec' | 'raccomandata' | 'a_mano' | 'fax';
export type EsitoInvio = 'consegnata' | 'in_giacenza' | 'rifiutata' | 'non_consegnata';

/** Un oggetto di cui parla un punto: il nome stabile del registro, l'id, il ruolo. */
export type OggettoDelPunto = [tipo: string, id: number, ruolo: RuoloOggettoPunto];

export interface Seduta {
  id: number;
  assemblea_id: number;
  convocazione: ConvocazioneSeduta;
  numero: number;
  fissata_il: string;
  luogo: string | null;
}

export interface Assemblea {
  id: number;
  condominio_id: number;
  ambito: AmbitoAssemblea;
  palazzina_id: number | null;
  scala_id: number | null;
  tipo: TipoAssemblea;
  origine: OrigineAssemblea;
  stato: StatoAssemblea;
  /** Il giorno della riunione: la seconda convocazione se c'è, altrimenti la prima; per la riunione a mano il suo giorno. */
  data: string | null;
  luogo: string | null;
  modalita: ModalitaAssemblea;
  piattaforma: string | null;
  consenso_videoconferenza_il: string | null;
  consenso_videoconferenza_modo: string | null;
  tabella_id: number | null;
  numero_verbale: string | null;
  verbale_documento_id: number | null;
  comunicazione_id: number | null;
  affissione_dal: string | null;
  affissione_al: string | null;
  /** «Spedire le raccomandate entro (scelto da te)»: vuoto vuol dire la proposta del programma (decisione 565). */
  raccomandate_entro_il: string | null;
  riconvocata_da_id: number | null;
  annullata_il: string | null;
  annullata_motivo: string | null;
  annullata_da: number | null;
  sedute?: Seduta[];
  punti?: Punto[];
  delibere?: Delibera[];
}

export interface Punto {
  id: number;
  assemblea_id: number;
  numero: number;
  testo: string;
  testo_pubblico: string | null;
  materia: MateriaPunto;
  /** Vuota dove il programma non propone (tabelle e criteri di riparto). */
  maggioranza: Maggioranza | null;
  maggioranza_libera: { soglia?: string; norma?: string } | null;
  amministrazione: AmministrazionePunto;
  conduttore: ConduttorePunto | null;
  ambito: AmbitoAssemblea | null;
  palazzina_id: number | null;
  scala_id: number | null;
  tabella_id: number | null;
  esercizio_id: number | null;
  /** Centesimi. */
  importo: number | null;
  dati: Record<string, unknown> | null;
  fonte: FontePunto;
  chiave_fonte: string | null;
}

export interface Delibera {
  id: number;
  condominio_id: number;
  assemblea_id: number;
  punto_id: number;
  votazione: number;
  oggetto: 'rendiconto' | 'riparto' | null;
  testo: string;
  esito: EsitoDelibera;
  /** Il giorno della riunione. */
  deliberata_il: string;
  /** Centesimi. */
  importo: number | null;
  revocata_il: string | null;
  revocata_da_delibera_id: number | null;
  annullata_il: string | null;
  annullata_nota: string | null;
  impugnata_il: string | null;
  impugnata_nota: string | null;
}

export interface Memo {
  id: number;
  condominio_id: number;
  ambito: AmbitoAssemblea;
  palazzina_id: number | null;
  scala_id: number | null;
  testo: string;
  nota: string | null;
  origine_tipo: string | null;
  origine_id: number | null;
  /** Il nome dell'origine copiato: resta se l'origine sparisce. */
  origine_nome: string | null;
  assemblea_id: number | null;
  ricordami_dal: string | null;
  a_chi: AChiMemo;
  assegnato_a: number | null;
  evento_id: number | null;
  stato: StatoMemo;
  punto_id: number | null;
  chiuso_il: string | null;
  chiuso_motivo: string | null;
  created_at: string;
}

export interface Convocato {
  id: number;
  assemblea_id: number;
  anagrafica_id: number | null;
  nome: string;
  indirizzo: string | null;
  pec: string | null;
  unita: { immobile_id: number; nome: string; ruolo: string; quota: number | null }[];
  /** I punti per cui è convocato (il conduttore); vuoto vuol dire tutti. */
  punti: number[] | null;
  mezzo: MezzoConvocazione;
  fissato_il: string;
  invio_id: number | null;
}

export interface InvioDellaConvocazione {
  id: number;
  anagrafica_id: number | null;
  tipo: string;
  canale: 'stampa' | 'email' | 'pec' | 'nessuno';
  stato: string;
  destinatario: string | null;
  impronta: string | null;
  emesso_il: string;
  esito: EsitoInvio | null;
  esito_il: string | null;
  /** Il giorno che conta per i termini: il primo fra la consegna e l'avviso di giacenza. */
  ricevuta_il: string | null;
}

/** Un punto proposto dell'ordine del giorno (§5, `ProposteDellOrdineDelGiorno::per`). */
/** Una delibera nel punto «Stato di esecuzione delle delibere precedenti» (`StatoDiEsecuzione::elenco`): la frase pubblica del punto, mai un nome. */
export interface RigaDelloStatoDiEsecuzione {
  delibera_id: number;
  assemblea_id: number;
  assemblea: string | null;
  deliberata_il: string | null;
  punto: number | null;
  testo: string;
  eseguita_il: string | null;
  cose: { testo: string; stato: StatoCosaDaFare; fatta_il: string | null; entro_il: string | null }[];
}

/** L'elenco del punto, fermo al giorno in cui si prepara: le aperte di sempre e le eseguite dall'ultima ordinaria. */
export interface ElencoDelloStatoDiEsecuzione {
  al: string;
  ultima_ordinaria: string | null;
  aperte: RigaDelloStatoDiEsecuzione[];
  eseguite: RigaDelloStatoDiEsecuzione[];
}

export interface PuntoProposto {
  chiave_fonte: string;
  testo: string;
  testo_pubblico: string | null;
  /** La riga sotto: «Il rendiconto fermato va in allegato alla convocazione.», «Memo del 18/11 · fattura n. 45 · la tua nota: …». */
  frase: string | null;
  materia: MateriaPunto;
  maggioranza: Maggioranza | null;
  avviso: string | null;
  amministrazione: AmministrazionePunto;
  origine: 'di_serie' | 'da_te' | 'dal_programma' | 'dalla_nota';
  fonte: FontePunto;
  spuntato: boolean;
  memo_id: number | null;
  oggetti: OggettoDelPunto[];
  esercizio_id: number | null;
  importo: number | null;
  /** Il punto «Stato di esecuzione delle delibere precedenti» porta il suo elenco. */
  dati?: { stato_esecuzione?: ElencoDelloStatoDiEsecuzione } | null;
  /**
   * La proposta di un gruppo (Coda 734): la tabella del gruppo. Il memo del punto rinviato nell'assemblea dei condòmini di quel gruppo, il rendiconto e il
   * preventivo di una gestione che si divide solo fra loro: «Prepara l'assemblea» li mostra solo con «Per chi» quel gruppo.
   */
  gruppo_id?: number | null;
}

export interface GruppoDiProposte {
  chiave: 'di_serie' | 'da_te' | 'dal_programma' | 'dalla_nota';
  titolo: string;
  punti: PuntoProposto[];
}

/** Una delibera nel menu di «Scegli la delibera» (`DelibereDelCondominio::scegli`). */
/** La delega di una delibera nel menu (decisione 622 (3)), come la manda il server (`DelegheDellAmministratore::delega()`); il tetto in centesimi. */
export interface DelegaNelMenu {
  tetto: number | null;
  ambito: string | null;
  ambito_parola: string | null;
  fino_al: string | null;
  in_vigore: boolean;
  frase: string;
}

export interface DeliberaDelMenu {
  id: number;
  testo: string;
  esito: EsitoDelibera;
  esito_parola: string;
  deliberata_il: string;
  punto: { id: number; numero: number; testo: string } | null;
  assemblea_id: number;
  /** «Delibera del 22/10/2026, assemblea straordinaria, punto 1 (verbale n. 41)». */
  frase: string;
  si_sceglie: boolean;
  /** Perché non si sceglie: «respinta», «revocata il 15/12/2026». */
  perche: string | null;
  revocata: string | null;
  impugnata: string | null;
  livello?: 1 | 2 | 3 | 4;
  perche_proposta?: string | null;
  /** «Con questa delibera le rate del piano passano a chi era titolare il 22/10/2026». */
  conseguenza?: string | null;
  /** La delega all'amministratore, se la delibera è una delega: «In vigore fino al 31/12/2027 · Fino a € 2.000,00». */
  delega?: DelegaNelMenu | null;
}

export interface GruppoDiDelibere {
  assemblea: { id: number; nome: string; data: string | null; a_mano: boolean; numero_verbale: string | null; verbale_da_caricare: boolean; perimetro: string | null };
  delibere: DeliberaDelMenu[];
}

/** La risposta di «Scegli la delibera» (scene 1, 2, 3 e 5 della bozza approvata con la decisione 502). */
export interface PropostaDiScegliLaDelibera {
  proposte: DeliberaDelMenu[];
  gruppi: GruppoDiDelibere[];
  memo: { id: number; frase: string; stato: StatoMemo } | null;
  raccolta: { date: number; frase: string } | null;
}

/** L'esito di un collegamento: la data che entra nella scheda, o la differenza da mostrare (R1). */
export interface EsitoDelCollegamento {
  data_entrata: string | null;
  differenza: string | null;
  conseguenza: string | null;
  delibera_id: number;
  ruolo: RuoloCollegamento;
}

/** Una riga del «Diario» (`DiarioDellAssemblea::righe`). */
export interface RigaDelDiario {
  fonte: 'modifica' | 'invio';
  istante: string;
  giorno: string;
  ora: string;
  chi: string;
  frase: string;
  registro: Record<string, unknown> | null;
}

/** Il controllo delle date mentre si scrive (R11): gli stessi casi del server, `ControlloDelleDate::CASI`. */
/** I primi giorni della prima convocazione che hanno i cinque giorni dell'art. 66 c. 3, contando da oggi (`TerminiDellaConvocazione::primiGiorniDaOggi()`). */
export interface TerminiDellaPrima {
  oggi: string;
  dal_a_mano: string;
  dal_raccomandata: string;
}

export interface ControlloDelleDate {
  valide: boolean;
  frase: string;
}

// ─── La sala ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

export type EsitoSeduta = 'deserta' | 'tenuta' | 'sospesa' | 'rinviata';
export type ComePresente = 'in_sala' | 'in_collegamento';
export type ArrivoDellaDelega = 'in_sala' | 'carta_prima' | 'portale';
export type StatoDelega = 'valida' | 'rifiutata' | 'revocata';
export type RuoloDiVoto = 'proprietario' | 'usufruttuario' | 'nudo_proprietario' | 'conduttore' | 'rappresentante' | 'delegato';
export type SceltaDiVoto = 'favorevole' | 'contrario' | 'astenuto' | 'discorde';
export type PercheVotaLUsufruttuario = 'art_1006' | 'lavori_985_986' | 'semplice_godimento';
export type EsitoDelGesto = 'applicato' | 'doppio' | 'in_conflitto' | 'rifiutato';
export type TesteDelCasoMisto = 'una_testa' | 'due_teste';

/** Teste e valore (centomillesimi). */
export interface ConteggioDellaSala {
  teste: number;
  valore: number;
}

/** Una testa della fotografia: la chiave stabile nella seduta (`p-12`, `p-12-15`, `u-5`), il nome, le persone, le unità, i millesimi (centomillesimi). */
export interface TestaDellaFotografia {
  chiave: string;
  nome: string;
  persone: number[];
  unita: number[];
  millesimi: number;
}

export interface TitolareNellaFotografia {
  anagrafica_id: number;
  ruolo: 'proprietario' | 'nuda_proprietario' | 'usufruttuario' | 'inquilino';
  quota: number | null;
}

export interface UnitaNellaFotografia {
  nome: string;
  /** Della tabella dell'assemblea, in centomillesimi. */
  millesimi: number;
  titolari: TitolareNellaFotografia[];
  scala_id: number | null;
  palazzina_id: number | null;
}

export interface PuntoNellaFotografia {
  punto_id: number;
  numero: number;
  maggioranza: Maggioranza | null;
  amministrazione: AmministrazionePunto;
  conduttore: ConduttorePunto | null;
  /** «Solo la scala B», «Solo i condòmini della tabella «Box»», o null per tutto il perimetro dell'assemblea. */
  perimetro: string | null;
  /** La parte del punto («la scala B») e il gruppo della tabella segnata (Coda 734): assenti nelle fotografie della beta.13. */
  parte?: string | null;
  gruppo?: { tabella_id: number; nome: string } | null;
  tabella: { id: number; nome: string } | null;
  /** Unità del punto => centomillesimi della tabella del punto. */
  millesimi: Record<string, number>;
  totale_teste: number;
  totale_valore: number;
  /** Le teste del punto di serie (senza le scelte del presidente sull'usufrutto). */
  teste: TestaDellaFotografia[];
  /** Unità => il ruolo di chi vota per lei. */
  ruoli: Record<string, RuoloDiVoto>;
  /** Le unità con usufruttuario e nudo proprietario: il presidente può far votare l'usufruttuario sul punto straordinario. */
  usufrutto: number[];
}

/** La fotografia della seduta (fermata all'apertura, con l'impronta), o provvisoria prima dell'apertura. */
export interface FotografiaDellaSala {
  assemblea_id: number;
  seduta_id: number;
  giorno: string;
  convocazione: ConvocazioneSeduta;
  provvisoria: boolean;
  teste_caso_misto: TesteDelCasoMisto;
  /** Le scelte dello studio sulla sala, ferme all'apertura come le teste. */
  impostazioni?: { delega_oltre_il_quinto: 'rifiuta' | 'decide_il_presidente'; voto_discorde_comproprietari: 'astenuti' | 'decide_il_presidente' };
  tabella: { id: number; nome: string } | null;
  persone: Record<string, { nome: string }>;
  unita: Record<string, UnitaNellaFotografia>;
  costituzione: { totale_teste: number; totale_valore: number; teste: TestaDellaFotografia[] };
  punti: PuntoNellaFotografia[];
  avvisi: string[];
}

/** La costituzione, come la conta `ContiDellaSala` (e `conti.ts`). */
export interface CostituzioneDellaSala {
  costituita: boolean;
  servono_teste: number;
  servono_valore: number;
  mancano_teste: number;
  mancano_valore: number;
  barra: string;
  servono: string;
  mancano: string | null;
}

export interface BarraDellaSala extends CostituzioneDellaSala {
  /** Le persone che contano presenti (di persona o per delega). */
  presenti: number[];
}

export interface PersonaDellaSala {
  anagrafica_id: number | null;
  nome: string;
}

export interface SedutaDellaSala {
  id: number;
  convocazione: ConvocazioneSeduta;
  numero: number;
  giorno: string;
  ora: string;
  aperta_il: string | null;
  chiusa_il: string | null;
  esito: EsitoSeduta | null;
  presidente: PersonaDellaSala | null;
  segretario: PersonaDellaSala | null;
  dal_foglio: boolean;
  /**
   * La seduta aperta senza la scelta del presidente, perché la pagina non sapeva del controllo dell'art. 1136 c. 6 cambiato (senza rete): le frasi dei
   * problemi; il presidente decide dopo (gesto `apertura_decidi`). Null quando non c'è niente da decidere.
   */
  apertura_da_decidere?: string[] | null;
}

export interface TestaInSala extends TestaDellaFotografia {
  /** «Russo Marco», «Greco Paola, per delega», o null se assente. */
  presente: string | null;
  come: 'di_persona' | 'per_delega' | null;
  /** Con che cosa è convocata la testa, per la card delle persone prima dell'apertura: «raccomandata», «PEC», «raccomandata, una a testa»; null senza avviso. */
  convocato_con?: string | null;
}

export interface PresenzaDellaSala {
  id: number;
  /** `anagrafica:<id>`, o `delega:<id>` per un delegato fuori dall'anagrafica. */
  chiave: string;
  anagrafica_id: number | null;
  nome: string;
  come: ComePresente;
  entrata_il: string;
  uscita_il: string | null;
  caduto: boolean;
  rappresenta: number[] | null;
  motivo_collegamento: string | null;
  /** Gli altri comproprietari della testa entrati con chi la rappresenta: in sala, senza voto (art. 67 c. 2 disp. att.; rilievo C53). */
  insieme?: { anagrafica_id: number; nome: string }[] | null;
}

export interface DelegaDellaSala {
  id: number;
  delegante_anagrafica_id: number | null;
  delegante_nome: string;
  delegato_anagrafica_id: number | null;
  delegato_nome: string;
  /** La chiave della presenza del delegato: `anagrafica:<id>` o `delega:<id>`. */
  chiave_delegato: string;
  unita: number[];
  punti: number[] | null;
  arrivata_come: ArrivoDellaDelega;
  arrivata_parola: string;
  ricevuta_il: string;
  stato: StatoDelega;
  motivo: string | null;
  /** «3 condòmini su 5 · 125,70 su 200,00», o null sotto i ventuno. */
  quinto: string | null;
  /** Oltre il quinto con «Decide il presidente»: la decide all'apertura (gesto `delega_decidi`). */
  da_decidere: boolean;
  documento_id: number | null;
  /** L'indirizzo per aprire la scansione (`admin.gestionale.assemblee.deleghe.scansione`), o null senza scansione. */
  scansione?: string | null;
}

export interface ChiPortaLaTesta {
  anagrafica_id: number | null;
  nome: string;
  ruolo: RuoloDiVoto;
  delega_id?: number;
}

export interface TestaIntervenuta extends TestaDellaFotografia {
  chi: ChiPortaLaTesta;
}

/** Il conto della parte (la sola scala, il gruppo di una tabella) per un punto: avvisa e non blocca. */
export interface PerimetroDelPunto {
  nome: string;
  /**
   * La parte («la scala B»), il nome della tabella del gruppo e la riga di chi vota («Votano solo i condòmini della tabella «Box»», Coda 734): assenti, o
   * null, per un voto fermato nella beta.13.
   */
  parte?: string | null;
  gruppo?: string | null;
  chi_vota?: string | null;
  costituzione: CostituzioneDellaSala;
  avviso: string | null;
}

export interface IntervenutiDelVoto {
  teste: TestaIntervenuta[];
  numero: number;
  valore: number;
  totale_teste: number;
  totale_valore: number;
  convocazione: ConvocazioneSeduta;
  perimetro: PerimetroDelPunto | null;
}

export interface GruppoDiVoti {
  teste: number;
  valore: number;
  nomi: { nome: string; millesimi: number }[];
}

export interface ConteggiDelVoto {
  favorevoli: GruppoDiVoti;
  contrari: GruppoDiVoti;
  astenuti: GruppoDiVoti;
  non_votato: GruppoDiVoti;
  /** «Senza i suoi 39,60 millesimi i favorevoli sarebbero 466,80: …», o null. */
  conflitto: string | null;
  /** «Respinta: 9 favorevoli su 11 intervenuti, 292,00 millesimi; in seconda convocazione servono …». */
  frase: string;
  /**
   * L'esito per condizione, per la pagina del voto: «Teste: 9 su 11, più della metà: sì», «Valore: 292,00 millesimi, ne servono 333,34: mancano 41,34»;
   * null quando decide il presidente. Assente nei conteggi scritti prima che ci fosse.
   */
  condizioni?: { condizione: 'teste' | 'valore'; passa: boolean; frase: string }[] | null;
}

export interface VotoDellaSala {
  testa: string;
  anagrafica_id: number | null;
  nome: string;
  ruolo: RuoloDiVoto;
  millesimi: number;
  voto: SceltaDiVoto;
  decisione: SceltaDiVoto | null;
  in_conflitto: boolean;
  nota_conflitto: string | null;
  versione: number;
}

export interface SceltaDellUsufruttuario {
  immobile_id: number;
  unita: string;
  perche: PercheVotaLUsufruttuario;
  frase: string;
}

export interface VotazioneDellaSala {
  id: number;
  seduta_id: number;
  punto_id: number;
  numero: number;
  oggetto: 'rendiconto' | 'riparto' | null;
  maggioranza: Maggioranza | null;
  votata_il: string;
  versione: number;
  intervenuti: IntervenutiDelVoto;
  /** `nomina`: il nome del nuovo amministratore detto alla conferma di un punto di nomina. */
  scelte: { usufruttuari?: SceltaDellUsufruttuario[]; nomina?: { amministratore: string } } | null;
  conteggi: ConteggiDelVoto | null;
  esito_proposto: EsitoDelibera | null;
  esito: EsitoDelibera | null;
  motivo: string | null;
  confermata_il: string | null;
  conflitto_determinante: boolean;
  delibera_id: number | null;
  /** La nota del presidente sul voto (anche quando si vota senza che l'assemblea risulti costituita): va nel verbale. */
  nota: string | null;
  /** La nota del presidente su chi non ha votato: va nel verbale. */
  nota_non_votato: string | null;
  voti: VotoDellaSala[];
}

export interface PuntoDellaSala {
  id: number;
  numero: number;
  testo: string;
  materia: MateriaPunto | null;
  maggioranza: Maggioranza | null;
  maggioranza_parola: string | null;
  maggioranza_libera: { soglia?: string; norma?: string } | null;
  amministrazione: AmministrazionePunto | null;
  conduttore: ConduttorePunto | null;
  ambito: AmbitoAssemblea | null;
  /** Il motivo della spesa, già scritto: «Perché era urgente: …» o «Perché supera il preventivo: …» (decisione 581); senza un motivo, null. */
  motivazione?: string | null;
  /** Con la seduta aperta, il conto della parte per il punto della sola scala. */
  perimetro: PerimetroDelPunto | null;
  votazioni: VotazioneDellaSala[];
  esito: EsitoDelibera | null;
}

export interface ControlloDellAperturaDellaSala {
  problemi: { tipo: 'non_convocato' | 'fuori_tempo' | 'rifiutato'; anagrafica_id: number; nome: string; frase: string }[];
  da_guardare: { tipo: string; frase: string }[];
  serve_la_scelta: boolean;
  frase: string | null;
}

export interface DueVersioniDellaSala {
  gesto: string;
  tipo: TipoDiGesto;
  dispositivo: string;
  questa: string | null;
  quella: string | null;
  /** `votazione:12`, `presenza:3:anagrafica:7`. */
  su: string | null;
  segnato_il: string | null;
  /** Chi ha fatto la versione di adesso: il dispositivo e l'ora del gesto («Dal tablet di Marco Galli, segnato alle 19:44»). */
  quella_dispositivo?: string | null;
  quella_segnato_il?: string | null;
}

/** Com'è finita la sala, a sala chiusa: l'ora della chiusura, la convocazione, se era costituita e i presenti un attimo prima (valore in centomillesimi). */
export interface ChiusuraDellaSala {
  alle: string;
  convocazione: ConvocazioneSeduta;
  costituita: boolean;
  teste: number;
  totale_teste: number;
  valore: number;
  totale_valore: number;
}

/** La prop `sala` della pagina della sala, e la risposta di `admin.gestionale.assemblee.sala.stato`. */
export interface SalaDellAssemblea {
  assemblea: { id: number; nome: string; titolo: string; stato: StatoAssemblea; stato_parola: string; modalita: ModalitaAssemblea; piattaforma: string | null;
    consenso_videoconferenza_il: string | null; luogo: string | null };
  ora_del_server: string;
  sedute: SedutaDellaSala[];
  /** La seduta aperta, o null. */
  seduta_id: number | null;
  /** La prossima seduta da aprire, se l'assemblea è convocata o in corso. */
  da_aprire: { seduta_id: number; convocazione: ConvocazioneSeduta; numero: number; si_apre: boolean; perche: string | null } | null;
  fotografia: FotografiaDellaSala | null;
  impronta: string | null;
  teste: TestaInSala[];
  barra: BarraDellaSala | null;
  /** A sala chiusa, com'è finita; null prima e durante la seduta. */
  chiusura?: ChiusuraDellaSala | null;
  /**
   * A sala senza una seduta aperta, le presenze dell'ultima seduta tenuta, per leggere la sala com'era (decisione 660); null con la seduta aperta o se nessuna
   * è stata aperta. Con la discussione di ogni punto scritta in sala, per punto (l'id), come in `verbale.discussioni`.
   */
  ultima_seduta?: { seduta_id: number; presenze: PresenzaDellaSala[]; discussioni?: VerbaleDellaSala['discussioni'] | [] } | null;
  presenze: PresenzaDellaSala[];
  /** Chiave della presenza => versione (le entrate più le uscite): la base dei gesti `entra` ed `esce`. */
  versioni: Record<string, number>;
  deleghe: DelegaDellaSala[];
  punti: PuntoDellaSala[];
  dichiarazioni: { id: number; seduta_id: number; punto_id: number | null; anagrafica_id: number | null; nome: string; testo: string }[];
  controllo: ControlloDellAperturaDellaSala | null;
  /** Solo sul dispositivo: lo stato tenuto non ha un controllo letto oggi (o la sala proiettata dopo «Segna deserta»): lo rifà il server al ritorno della rete. */
  controllo_da_rifare?: boolean;
  conflitti: DueVersioniDellaSala[];
  /** Con una seduta aperta, le scelte ferme nella fotografia della sua apertura (`ferme_all_apertura`); prima, quelle di adesso. */
  impostazioni: { delega_oltre_il_quinto: 'rifiuta' | 'decide_il_presidente'; voto_discorde_comproprietari: 'astenuti' | 'decide_il_presidente';
    teste_caso_misto: TesteDelCasoMisto; ferme_all_apertura?: boolean };
  /** Il foglio della sala: quando è stato stampato l'ultima volta, e se una delega è cambiata dopo («ristampa il foglio»). */
  foglio?: { stampato_il: string | null; da_ristampare: boolean };
  /** Il verbale della seduta aperta, con la discussione di ogni punto: null senza una seduta aperta. */
  verbale?: VerbaleDellaSala | null;
  puo_gestire: boolean;
  /** `foglio_compilato`: il foglio con quello che c'è, solo a sala aperta (null altrimenti). */
  rotte: { gesti: string; stato: string; pagina: string; assemblea: string; deleghe: string; foglio?: string; foglio_compilato?: string | null; prospetto?: string;
    senza_rete?: string; verbale?: string; registro?: string };
}

// ─── I gesti della sala ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** La persona di un gesto: una persona dell'anagrafica, o il delegato fuori dall'anagrafica con la sua delega. */
export type PersonaDelGesto = { anagrafica_id: number } | { delega_id: number };

/**
 * La votazione di un gesto del voto: il suo numero, o, per una votazione aperta senza rete (il numero lo dà il server), la seduta, il punto e il numero della
 * votazione (1, o 2 per il riparto), con il gesto «Si vota» che l'ha aperta: se il punto si votava già da un altro dispositivo, il server non li mescola.
 */
export type LaVotazioneDelGesto = { votazione_id: number } | { seduta_id: number; punto_id: number; numero: number; aperta_dal_gesto?: string };

/** I dati di ogni tipo di gesto, come li legge il server. `base` è la versione da cui il gesto parte (presenza, voto, votazione). */
export interface DatiDeiGesti {
  /** `dichiarata_il`: l'ora di Roma «2026-12-14 08:40» in cui la seduta mai aperta è deserta, quando la si segna un altro giorno. */
  deserta: { seduta_id: number; dal_foglio?: boolean; dichiarata_il?: string };
  apri: { seduta_id: number; presidente: { anagrafica_id: number } | { nome: string }; segretario: { anagrafica_id: number } | { nome: string };
    scelta?: 'apri'; motivo?: string; dal_foglio?: boolean;
    /** Le persone con un problema nel controllo che la pagina mostrava (vuoto se era pulito, null se non l'aveva): il server sa se il controllo è cambiato. */
    controllo_visto?: number[] | null };
  apertura_decidi: { seduta_id: number; motivo: string; dal_foglio?: boolean };
  /** `promemoria`: l'email ai convocati e l'avviso in bacheca, acceso di serie; `false` lo spegne. */
  sospendi: { seduta_id: number; prosegue_seduta_id: number; promemoria?: boolean;
    /** L'avviso del promemoria in bacheca: pubblicato subito (di serie) o lasciato in bozza. */
    bacheca?: 'pubblica' | 'bozza' };
  rinvia: { motivo: string; sedute: { convocazione: ConvocazioneSeduta; numero?: number; fissata_il: string }[]; seduta_id?: number };
  chiudi: { seduta_id: number; non_trattati?: 'rinviata' };
  /** `con`: gli altri comproprietari della testa che entrano insieme, presenti senza voto (rilievo C53). */
  entra: { seduta_id: number; persona: PersonaDelGesto; come: ComePresente; motivo?: string; rappresenta?: number[]; con?: number[] };
  esce: { seduta_id: number; persona: PersonaDelGesto; caduto?: boolean };
  rappresenta: { seduta_id: number; persona: PersonaDelGesto; unita: number[] };
  delega: { delegante_anagrafica_id: number; delegato: { anagrafica_id: number } | { nome: string }; punti?: number[]; motivo?: string };
  delega_revoca: { delega_id: number; motivo: string };
  delega_decidi: { delega_id: number; accetta: boolean; motivo: string };
  si_vota: { seduta_id: number; punto_id: number; numero?: 1 | 2; oggetto?: 'rendiconto' | 'riparto';
    usufruttuari?: { immobile_id: number; perche: PercheVotaLUsufruttuario }[] };
  tutti_favorevoli: LaVotazioneDelGesto;
  voto: LaVotazioneDelGesto & { testa: string; voto: SceltaDiVoto; decisione?: Exclude<SceltaDiVoto, 'discorde'>; perche?: string };
  conflitto: LaVotazioneDelGesto & { testa: string; in_conflitto: boolean; nota?: string; perche?: string };
  /** `amministratore`: in un punto di nomina, il nome del nuovo amministratore (la riga proposta del registro di nomina e revoca lo porta). */
  conferma: LaVotazioneDelGesto & { esito: Exclude<EsitoDelibera, 'senza_voto'>; testo?: string; motivo?: string; amministratore?: string };
  trattato: { seduta_id: number; punto_id: number };
  dichiarazione: { seduta_id: number; punto_id?: number; anagrafica_id?: number; nome?: string; testo: string };
  /** La dichiarazione a verbale corretta e tolta (rilievo C53): finché il voto del punto non è confermato (senza punto, finché la seduta è aperta). */
  dichiarazione_cambia: { dichiarazione_id: number; testo: string };
  dichiarazione_togli: { dichiarazione_id: number };
  /** La nota del presidente sul voto o su chi non ha votato; il testo vuoto la toglie. */
  nota: LaVotazioneDelGesto & { su: 'voto' | 'non_votato'; testo: string };
  scegli: { gesto: string; scelta: 'questa' | 'quella' };
  /** Il testo del verbale (la discussione di un punto), accettato anche dalla sala: vedi `resources/js/types/verbale.ts`. */
  testo: DatiDelTesto;
}

export type TipoDiGesto = keyof DatiDeiGesti;

/** Un gesto come lo manda il dispositivo: il numero (`crypto.randomUUID()`), il tipo, i dati, la versione, l'ora del dispositivo e quella corretta. */
export interface GestoDellaSala<T extends TipoDiGesto = TipoDiGesto> {
  gesto: string;
  tipo: T;
  dati: DatiDeiGesti[T];
  base?: number | null;
  orologio?: string | null;
  segnato_il: string;
}

/** Il corpo di `POST admin.gestionale.assemblee.sala.gesti`: al più 500 gesti. */
export interface LottoDiGesti {
  dispositivo: string;
  gesti: GestoDellaSala[];
}

export interface RispostaAlGesto {
  gesto: string;
  tipo: string;
  esito: EsitoDelGesto;
  messaggio: string | null;
  /** La versione dopo il gesto: della presenza (`entra`, `esce`), del voto (`voto`), della votazione (gli altri gesti del voto). */
  versione: number | null;
  conflitto: { questa: string; quella: string; quella_dispositivo?: string; quella_segnato_il?: string } | null;
  votazione_id?: number;
  versione_votazione?: number;
  delibera_id?: number;
  delega_id?: number;
  dichiarazione_id?: number;
  assemblea_nuova_id?: number;
  /** Per un gesto `doppio`: l'esito della prima volta (e i numeri che il gesto aveva dato, la votazione, la delega, la dichiarazione). */
  esito_di_allora?: EsitoDelGesto;
}

/** La risposta dei gesti: l'ora del server (per lo scarto dell'orologio) e una risposta per gesto, nell'ordine. */
export interface RispostaDeiGesti {
  ora_del_server: string;
  risposte: RispostaAlGesto[];
}


// ── Il seguito delle delibere: «Che cosa cambia nel gestionale», il passo 6 «Delibere eseguite», le cose da fare (la prop `seguito` della pagina
// dell'assemblea, con una visita parziale; le rotte `admin.gestionale.assemblee.seguito.*` e `admin.gestionale.assemblee.delibere.{annullata,revocata,revoche}`).

export type TipoCosaDaFare =
  | 'crea_piano_straordinario' | 'prepara_ripianamento' | 'registra_dispensa' | 'scrivi_regola' | 'rifai_versione' | 'rimetti_piano'
  | 'avvisa_condomini' | 'fuori_dal_programma' | 'dopo_il_rifiuto';
export type StatoCosaDaFare = 'da_fare' | 'fatta' | 'non_serve';
export type AmbitoDellaDelega = 'accordi_fornitori';

/** Il tipo di una riga del riepilogo: un cambio adesso, una cosa da fare, la delega in vigore, un punto per la prossima assemblea, fuori, già fatta, una frase. */
export type TipoDellaRiga = 'applica' | 'cosa' | 'delega' | 'memo' | 'fuori' | 'gia_fatto' | 'nota';
/** Dove conta la riga nel conto in fondo. */
export type ContoDellaRiga = 'cambi' | 'cose' | 'comunicazioni' | 'deleghe' | 'punti' | 'fuori' | 'respinte';

export interface SchedaDellaRiga {
  tipo: string;
  id: number;
  nome: string;
}

/**
 * La differenza del piano dal preventivo approvato (decisione 622 (8)): le cifre in centesimi. `voci`: l'importo che il piano chiede per la voce (la sua copia
 * della voce, o la voce di oggi) contro quello approvato; `tabelle`: la voce su altre tabelle o con altri coefficienti; `rate`: le rate generate che non
 * tornano con le voci del piano.
 */
export interface DifferenzaDelPreventivo {
  frase: string;
  fermato: number;
  oggi: number;
  voci: { nome: string; prima: number; dopo: number; frase: string }[];
  tabelle: { nome: string; prima: string; dopo: string; frase: string }[];
  rate: { chiede: number; generate_il: string | null; frase: string } | null;
}

export interface DelegaDellaDelibera {
  /** Centesimi. */
  tetto: number | null;
  ambito: AmbitoDellaDelega | null;
  ambito_parola: string | null;
  fino_al: string | null;
  in_vigore: boolean;
  frase: string;
}

/** Una riga del riepilogo (R2): la chiave torna con «Conferma» se è spuntata; «applicata» è il collegamento che c'è (o la cosa che esiste). */
export interface RigaDelSeguito {
  chiave: string;
  delibera_id: number;
  punto: number | null;
  tipo: TipoDellaRiga;
  catena: string;
  titolo: string;
  frase: string;
  /** Di serie: la casella accesa. */
  spuntata: boolean;
  /** Si può spuntare (e applicare). */
  si_applica: boolean;
  applicata: boolean;
  perche_no: string | null;
  scheda: SchedaDellaRiga | null;
  ruolo: string | null;
  /** Per una cosa da fare proposta: il tipo e il testo; la data e la persona le sceglie l'amministratore. */
  cosa: { tipo: TipoCosaDaFare; parola: string; testo: string } | null;
  conto: ContoDellaRiga | null;
  dati: {
    differenza?: DifferenzaDelPreventivo;
    /** Una riga «fuori» che aspetta la scheda giusta (il piano diverso, la versione da fermare): tiene la delibera «Da eseguire» e sta nel passo 6. */
    aspetta?: boolean;
    delega?: DelegaDellaDelibera | null;
    /** «Portalo alla prossima assemblea»: la finestra del memo con l'origine nel punto e il testo delle dichiarazioni. */
    memo?: { testo: string; origine_tipo: 'punto'; origine_id: number };
  };
}

export interface CosaDaFare {
  id: number;
  delibera_id: number;
  tipo: TipoCosaDaFare;
  tipo_parola: string;
  testo: string;
  entro_il: string | null;
  a_chi: AChiMemo;
  assegnato_a: number | null;
  /** Il nome della persona: chi l'ha scritta, il collaboratore, «tutto lo studio». */
  chi: string | null;
  stato: StatoCosaDaFare;
  fatta_il: string | null;
  fatta_da: string | null;
  nota: string | null;
  chiusa_motivo: string | null;
  documento: { id: number; nome: string } | null;
  /** La scheda che l'ha eseguita (il piano, la dispensa, la regola, la comunicazione, il memo). */
  oggetto: SchedaDellaRiga | null;
  compito_id: number | null;
  /** La prossima azione, finché è da fare: «Crea il piano straordinario», «Registra la dispensa», «Avvisa i condòmini», «Apri la delibera». */
  azione: { testo: string; url: string | null } | null;
}

/** `risposta_data`: una delibera respinta con la risposta data («Respinta · risposta data il 17/12/2026»), mai «Eseguita». */
export type StatoDelSeguito = 'da_confermare' | 'da_eseguire' | 'eseguita' | 'risposta_data' | 'senza_seguito';

export interface SeguitoDellaDelibera {
  stato: StatoDelSeguito;
  /** «Da eseguire · 2 di 3», «Eseguita», «Da confermare», «Respinta · risposta data il 17/12/2026». */
  parola: string;
  eseguita_il: string | null;
  fatte: number;
  quante: number;
}

export interface PuntoDelRiepilogo {
  delibera_id: number;
  punto_id: number | null;
  numero: number | null;
  testo: string;
  votazione: number;
  oggetto: 'rendiconto' | 'riparto' | null;
  esito: EsitoDelibera;
  esito_parola: string;
  confermata: boolean;
  /** «Che cosa ha cambiato l'assemblea», scritto alla conferma (approvata con modifiche). */
  modifiche: string | null;
  righe: RigaDelSeguito[];
  cose: CosaDaFare[];
  seguito: SeguitoDellaDelibera;
}

export interface ContoDelRiepilogo {
  cambi: number;
  cose: number;
  comunicazioni: number;
  deleghe: number;
  punti: number;
  fuori: number;
  respinte: number;
}

export interface RiepilogoDelSeguito {
  aperto: boolean;
  perche_chiuso: string | null;
  /** L'assemblea tenuta su carta (o registrata a mano): il riepilogo si apre con gli esiti (decisione 622 (4)). */
  su_carta: boolean;
  verbale_da_caricare: boolean;
  confermato_il: string | null;
  da_confermare: number;
  punti: PuntoDelRiepilogo[];
  conto: ContoDelRiepilogo;
  /** «3 cambi nei conti · 2 cose da fare · 1 comunicazione da rileggere · 1 delega in vigore · 1 punto per la prossima assemblea». */
  frase_del_conto: string;
}

export interface TrentaGiorni {
  per_tutti: string | null;
  /** Il giorno da cui sono passati per tutti (il giorno dopo `per_tutti`). */
  dal: string | null;
  frase: string;
}

export interface FondoSpeciale {
  /** Centesimi. */
  chiesti: number;
  deliberati: number;
  pari: boolean;
  frase: string | null;
  legge: string;
}

export interface SegnoDellaDelibera {
  tipo: 'impugnata' | 'annullata' | 'revocata';
  frase: string;
}

export interface RigaDelPassoSei {
  delibera_id: number;
  punto: number | null;
  /** Il punto, per «Ferma il preventivo» sulla cosa «Correggi il preventivo e fermalo di nuovo». */
  punto_id: number | null;
  testo: string;
  esito: EsitoDelibera;
  esito_parola: string;
  seguito: SeguitoDellaDelibera;
  cose: CosaDaFare[];
  /** Le righe tolte dal riepilogo, con «Applica», e quelle «fuori» che aspettano (`dati.aspetta`), con «Apri» sulla loro scheda. */
  righe_da_applicare: RigaDelSeguito[];
  /** Il verbale del giorno è stato annullato dopo la conferma: la delibera resta nella tabella con le sue cose da fare. */
  verbale_annullato: boolean;
  /** Il memo delle varie o della comunicazione è già salvato: «Nella memoria del condominio». */
  memo_salvato?: boolean;
  segni: SegnoDellaDelibera[];
  fondo_speciale: FondoSpeciale | null;
  trenta_giorni: TrentaGiorni;
  /** Chi può impugnare (i contrari, gli astenuti, gli assenti), per «Da chi» di «Segna impugnata». */
  chi_puo_impugnare: { nome: string; perche: string }[];
  posti: { oggetto_tipo: string; oggetto_id: number; ruolo: string; parola: string; nome: string; data: string | null; nota: string | null; esiste: boolean }[];
}

export interface PassoSei {
  stato: 'chiuso' | 'adesso' | 'fatto';
  riga: string;
  perche: string | null;
  piede: string;
}

/** La prop `seguito` della pagina dell'assemblea. */
export interface PaginaDelSeguito {
  riepilogo: RiepilogoDelSeguito;
  tabella: RigaDelPassoSei[];
  da_eseguire: (CosaDaFare & { punto: number | null })[];
  trenta_giorni: TrentaGiorni;
  passo: PassoSei;
  puo: { gestire: boolean };
}

/** L'esito di una riga dopo «Conferma» o «Applica» (la prop `seguito_esiti`, o la risposta JSON). */
export interface EsitoDellaRiga {
  esito: 'applicata' | 'non_applicata' | 'gia_applicata' | 'sconosciuta';
  frase: string;
}

/** Il corpo di `POST admin.gestionale.assemblee.seguito.conferma`. */
export interface ConfermaDelSeguito {
  chiavi: string[];
  /** Per chiave di una cosa proposta: la data e la persona (e il testo, se cambiato). */
  cose?: Record<string, { entro_il?: string | null; a_chi?: AChiMemo; assegnato_a?: number | null; testo?: string }>;
  /** Le cose scritte («Firma con Serramenti Marino»). */
  altre?: { delibera_id: number; testo: string; entro_il?: string | null; a_chi?: AChiMemo; assegnato_a?: number | null }[];
  /** Per delibera approvata con modifiche: «che cosa ha cambiato l'assemblea». */
  modifiche?: Record<number, string>;
}
