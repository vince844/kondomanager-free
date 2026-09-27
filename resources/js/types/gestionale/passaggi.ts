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
  riferimento: {
    uscente_fino_al: string | null;
    entrante_dal: string;
    frase: string;
  };
  anagrafica: { frasi: string[]; pertinenze: string[] };
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
  cancello: { richiesto: boolean; motivi: string[] };
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
  sottotipo: 'costituzione' | 'estinzione' | null;
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
  nota: string | null;
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
