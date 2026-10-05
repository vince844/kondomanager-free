/**
 * «Registra passaggio» (1.11.0-beta.31, B2 del progetto sul subentro): i tipi che la pagina, il
 * pannello «Cosa cambierà» e lo storico ricevono dal server.
 *
 * Il pannello **non calcola nulla**: ogni frase e ogni importo arrivano da
 * `App\Services\Subentro\AnteprimaPassaggio` (`POST …/passaggi/anteprima`). Qui c'è solo la forma.
 */

export type TipoPassaggio = 'vendita' | 'inizio_locazione' | 'fine_locazione' | 'usufrutto';
export type SottotipoUsufrutto = 'costituzione' | 'estinzione';

/** Una riga di titolarità in corso, come «Registra passaggio» la propone in «Chi esce». */
export interface TitolareAttuale {
  /** L'id della riga di `anagrafica_immobile`, non della persona. */
  id: number;
  anagrafica: { id: number; nome: string; codice_fiscale: string | null };
  tipologia: string;
  quota: number | string;
  data_inizio: string | null;
  data_fine: string | null;
}

export interface PersonaDelCondominio {
  id: number;
  nome: string;
  codice_fiscale: string | null;
  indirizzo: string | null;
}

export interface PertinenzaCollegata {
  id: number;
  nome: string;
  etichetta: string;
  titolari: { nome: string | null; tipologia: string }[];
}

export interface RataEmessa {
  rata: number;
  scadenza: string;
  importo: number;
  importo_formattato: string;
  intestatario: string;
  piano: string;
  natura: string;
}

/** Il conguaglio delle quote emesse a chi esce (D9), calcolato da `ConguaglioPassaggio`: lo stesso che la registrazione scrive. */
export interface ConguaglioDati {
  stato: 'calcolato';
  anagrafica_uscente_id: number;
  anagrafica_entrante_id: number | null;
  quote: {
    rata_quote_id: number; immobile_id: number; immobile_nome: string; piano_rate_id: number; piano: string;
    gestione_id: number; gestione: string | null; natura: 'ordinaria' | 'straordinaria';
    rata: number; scadenza: string; importo: number; importo_formattato: string;
    quota_pura: number; pregresso: number; gradino: string | null; periodo: { dal: string; al: string }[] | null;
    giorni_uscente: number | null; giorni_entrante: number | null; giorni_periodo: number | null;
    uscente: number; entrante: number; entrante_formattato: string; non_risolta: boolean; esclusa: boolean;
  }[];
  per_gestione: {
    gestione_id: number; gestione: string | null; immobile_id: number; immobile_nome: string;
    natura: 'ordinaria' | 'straordinaria'; gradino: string[]; periodo: { dal: string; al: string }[] | null;
    /** Sull'ordinaria, voci dichiarate accanto ad altre: la competenza si legge voce per voce (R5 della Fase 1-bis). */
    voce_per_voce?: boolean;
    quote: number; quota_pura: number; pregressi: number;
    giorni_uscente: number | null; giorni_entrante: number | null; giorni_periodo: number | null;
    importo: number; importo_formattato: string; non_risolte: number; escluse: number; esercizio_id: number | null;
    /** Fra le non risolte, quelle ferme perché la parte che passa non si separa (quote della 1.7.x, catene di passaggi). */
    non_separabili?: number;
    /** Decisione 35 (beta.42): fra le non risolte, le quote di un predecessore che il suo passaggio non ha fatto passare. */
    mai_passate?: number;
    /** Decisione 25 (B3a): la parte di chi entra sull'intero piano, e il preventivo delle bozze che passano a lui. `importo` è la differenza. */
    importo_lordo: number; importo_lordo_formattato: string;
    bozze_passate: number; bozze_passate_importo: number; bozze_passate_formattato: string;
  }[];
  /** Una per (gestione, unità), solo se ≠ 0: è ciò che finisce in `saldi`. */
  /** Chi entra sta dentro la coppia: con più nudi proprietari all'estinzione dell'usufrutto ogni coppia ha il suo (S8-30). */
  coppie: { gestione_id: number; gestione: string | null; immobile_id: number; esercizio_id: number | null; importo: number; importo_formattato?: string; anagrafica_entrante_id?: number; entrante_nome?: string }[];
  totale_entrante: number;
  totale_entrante_formattato: string;
  /** Con le bozze che passano la coppia può rovesciarsi: il verso lo dice il segno di `totale_entrante`. */
  totale_entrante_assoluto_formattato: string;
  /** Decisione 25 (B3a): le bozze che passano a chi entra, riepilogate per piano. */
  riassegnazione: { piano_rate_id: number; piano: string; n: number; quote: number; dal: string; al: string; preventivo: number; preventivo_formattato: string; pregresso: number }[];
  pregressi: number;
  non_risolte: { piano: string; motivo: string }[];
  frasi: string[];
}

/** La risposta di `POST …/passaggi/anteprima`. */
export interface AnteprimaPassaggioDati {
  /**
   * Decisione 57 (1.11.0-beta.43, D2): all'estinzione, i nudi che tornano pieni (righe di `anagrafica_immobile`) e da dove
   * viene la scelta: tutti i nudi, il registro del passaggio da cui l'usufrutto è nato, l'amministratore, il consolidamento di
   * legge, o tutti ciascuno per la sua quota (decisione 62). `consolida`: riga → la quota che torna piena, quando non è tutta la
   * riga. `null` negli altri passaggi. Quando il programma si ferma (decisione 61) l'anteprima torna 422 sulla chiave `estinzione`.
   */
  nudi: { da: 'tutti' | 'registro' | 'scelta' | 'consolidamento' | 'per_quota'; righe: number[]; consolida: Record<string, number> } | null;
  riferimento: {
    uscente_fino_al: string | null;
    entrante_dal: string;
    frase: string;
  };
  anagrafica: {
    frasi: string[];
    pertinenze: string[];
  };
  rate: {
    /** `nessuno` quando non c'è chi esce o non ha quote emesse; `calcolato` quando il conguaglio è stato calcolato (S5). */
    stato: 'nessuno' | 'calcolato';
    /** Le quote emesse **a chi esce**: quelle che il conguaglio riguarda. */
    emesse: RataEmessa[];
    piani_distinti: number;
    totale_emesso: number;
    totale_emesso_formattato: string;
    /** Le quote degli altri titolari dell'unità: restano a chi le ha ricevute, si contano soltanto. */
    altre: { quote: number; intestatari: string[] };
    morosita: { importo: number; importo_formattato: string; intestatario: string | null } | null;
    /** `null` quando non c'è niente da conguagliare: il pannello non mostra mai zero al suo posto. */
    conguaglio: ConguaglioDati | null;
    frasi: string[];
  };
  obbligati: { frasi: string[]; copia_autentica_mancante: boolean };
  invarianti: { frasi: string[] };
  /**
   * Il cancello (1) della decisione 14. `motivi` chiedono la spunta; `informazioni` (beta.38, decisione del 29/09/2026) sono
   * le quote che il passaggio non tocca — restano per legge a chi le ha, o la parte di chi entra è zero (decisione 28.8 c) —
   * e si mostrano senza spunta.
   */
  // `avvisi` (rilievo T4 della .43): le informazioni senza spunta che non riguardano quote, come la frase della decisione 58.
  cancello: { richiesto: boolean; motivi: string[]; informazioni?: string[]; avvisi?: string[] };
  /** Decisioni 31.5 e 31.6 (beta.41): chi paga l'ordinaria dal giorno dell'atto, alla costituzione e alla riserva d'usufrutto. */
  ordinaria: OrdinariaDopoAtto;
}

/**
 * Alla costituzione e alla riserva d'usufrutto l'amministratore sceglie chi paga l'ordinaria dal giorno dell'atto:
 * `usufruttuario` (art. 1004 c.c., la proposta di legge) o `voce` (come dice ogni voce). Con la legge le `voci` sul
 * «Proprietario» delle gestioni ordinarie passano all'«Usufruttuario», salvo quelle a cui si toglie la spunta.
 */
export interface OrdinariaDopoAtto {
  applicabile: boolean;
  scelta: 'usufruttuario' | 'voce' | null;
  usufruttuario: string | null;
  nudo: string | null;
  voci: VoceDaSpostare[];
  /** Le conseguenze della scelta, scritte dal server. */
  frasi: string[];
  /** Le frasi delle voci bloccate, con il rimedio vero per il piano che le blocca: il riquadro del lucchetto le mostra. */
  frasi_bloccate: string[];
  /** Decisione 33: gli usufrutti della gestione nati con la scelta opposta, anche finiti, e cosa la scelta cambia per i loro giorni. */
  frasi_altri_usufrutti: string[];
  /** L'impronta dell'elenco delle voci mostrato: la registrazione la confronta con quello di adesso (rilievo S1). */
  impronta: string | null;
  /** All'estinzione, la scelta dell'usufrutto che si chiude, ereditata dal passaggio da cui era nato (rilievo D4). */
  ereditata: { scelta: 'voce'; subentro_id: number; decorrenza: string } | null;
}

export interface VoceDaSpostare {
  /** L'associazione voce × tabella: è l'id che va in `voci_da_tenere`. */
  id: number;
  conto_id: number;
  conto: string;
  tabella: string;
  gestione: string;
  /** La parte della voce sul «Proprietario», in percentuale. */
  percentuale: number;
  /** Decisione 31.8: compresa in un piano approvato, la sua ripartizione è bloccata e non si sposta. */
  bloccata: boolean;
  /** Perché: un piano con la voce fra i capitoli (`piano`), o un piano senza capitoli della gestione (`piano_globale`, 31.9). */
  bloccata_da: 'piano' | 'piano_globale' | null;
  /** I piani che la bloccano: se hanno rate a giornale e se vengono da fatture decidono il rimedio. */
  piani_bloccanti?: { id: number; nome: string; a_giornale: boolean; da_fatture: boolean }[];
  spostata: boolean;
  /** Le altre unità della tabella con un usufruttuario: per loro cambia chi paga. */
  altre_unita: {
    immobile_id: number;
    immobile: string;
    usufruttuari: string;
    nudi: string;
    /** Quanto l'ultimo piano della gestione ha dato al nudo proprietario su questa voce; `null` se non c'è un piano. */
    importo: number | null;
    importo_formattato: string | null;
  }[];
}

/** Una riga dello storico «Chi ha avuto questa unità» (`StoricoTitolarita`). */
export interface RigaStorico {
  id: number;
  anagrafica: { id: number | null; nome: string | null; codice_fiscale: string | null };
  tipologia: string;
  diritto: string;
  quota: number | string;
  attivo: boolean;
  data_inizio: string | null;
  data_fine: string | null;
  in_corso: boolean;
  futuro: boolean;
  /** «dal 3 marzo 2019 al 30 aprile 2026», «dal 1 maggio 2026 · in corso». */
  periodo: string;
  /** «7 anni», «8 mesi», «20 giorni». */
  durata: string | null;
  note: string | null;
  subentro: {
    tipo_passaggio: string;
    /** Lo stesso di `PassaggioRegistrato.sottotipo`: la riga si chiama come il suo passaggio (testi T7 della beta.38). */
    sottotipo: 'costituzione' | 'estinzione' | 'riserva_usufrutto' | null;
    decorrenza: string | null;
    estremi_titolo: string | null;
    copia_autentica_il: string | null;
    ruolo_nel_passaggio: 'uscente' | 'entrante' | 'continuazione';
    /** Il PDF del titolo allegato al passaggio, se c'è (solo per l'amministratore). */
    documento_url: string | null;
    /** Compilata se l'amministratore ha rinunciato al conguaglio proposto. */
    nota_conguaglio: string | null;
  } | null;
}

/** Un passaggio registrato (`subentri`, S6): il vademecum «chi resta obbligato» ricalcolato dai fatti. */
export interface PassaggioRegistrato {
  id: number;
  tipo_passaggio: 'vendita' | 'inizio_locazione' | 'fine_locazione' | 'usufrutto' | string;
  /** Costituzione o estinzione dell'usufrutto; nella vendita, la riserva d'usufrutto (beta.38). */
  sottotipo: 'costituzione' | 'estinzione' | 'riserva_usufrutto' | null;
  decorrenza: string | null;
  decorrenza_a_parole: string | null;
  registrato_il: string | null;
  uscente: string | null;
  entrante: string | null;
  estremi_titolo: string | null;
  copia_autentica_il: string | null;
  copia_autentica_a_parole: string | null;
  /** Vendita senza copia autentica: la frase «finché non la ricevi» è accesa e si può registrare la data. */
  copia_autentica_attesa: boolean;
  documento_url: string | null;
  pertinenze: string[];
  conguaglio: {
    stato: 'proposto' | 'rinunciato' | 'annullato' | 'nessuno';
    importo: number;
    importo_formattato: string;
    applicato: boolean;
    nota: string | null;
    nota_annullamento: string | null;
    annullato_il: string | null;
  };
  /** Le frasi del vademecum, senza imperativi né futuro. */
  obbligati: string[];
  /**
   * Chi paga l'ordinaria dal giorno dell'atto (decisioni 31.5–31.7), dal registro del passaggio; `null` se il passaggio non
   * ne parla (vendita piena, locazione, passaggi anteriori alla beta.41). Con la legge, `voci` sono le voci spostate con i
   * coefficienti di prima e di dopo.
   */
  ordinaria: { scelta: 'usufruttuario' | 'voce'; testo: string; voci: string[] } | null;
  nota: string | null;
  /** 1.11.0-beta.37: un passaggio annullato resta nello storico, annullato. */
  annullato: boolean;
  annullato_il: string | null;
  /** Chi l'ha annullato (decisione 27.4); dal registro se l'utente non c'è più. */
  annullato_da: string | null;
  nota_annullamento: string | null;
  /**
   * La stessa regola del server (`AnnullaPassaggioAction::motivoBlocco`): se no, perché; se sì, che cosa torna come prima
   * (`effetti`, solo ciò che il passaggio ha toccato) e che cosa resta da fare (`avvisi`).
   */
  annullabile: { si: boolean; motivo: string | null; avvisi: string[]; effetti: string[] };
}

export interface StoricoTitolaritaDati {
  /** I passaggi registrati (`subentri`); con ripiego alle righe chiuse solo per lo storico pre-beta.31. */
  passaggi: number;
  /** Le righe con una data di fine, qualunque ne sia l'origine. */
  periodi_chiusi?: number;
  righe: RigaStorico[];
  gruppi: { diritto: string; righe: RigaStorico[] }[];
  /** I passaggi registrati sull'unità, uno per passaggio, dal più recente (S6). */
  subentri?: PassaggioRegistrato[];
}
