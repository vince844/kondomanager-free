export interface Gestione {
  id: number;
  nome: string;
  tipo: string;
}

export interface Saldo {
  id: number;
  saldo_iniziale: number;
  /**
   * Il flag **grezzo**, acceso alla generazione del piano. Non usarlo per decidere se
   * mostrare il lucchetto: fra generazione ed emissione è acceso mentre il saldo è ancora
   * correggibile. Serve solo a distinguere i lucchetti orfani, che non hanno un piano.
   */
  is_applicato: boolean;
  /**
   * Il lucchetto **calcolato**, che è l'autorità: con un piano risponde a «il piano è emesso
   * o ha incassi», senza piano ripiega su `is_applicato`. Lo calcola il server una volta per
   * piano — vedi `SaldoInizialeController::esponiLucchettoCalcolato()`.
   */
  e_bloccato: boolean;
  /** Una riga del conguaglio di un passaggio di titolarità (B2), una coppia per ogni persona che entra: si tolgono insieme, mai una sola. */
  e_conguaglio?: boolean;
  /** 1.11.0-beta.44: una delle righe dell'arretrato di una successione (decisione 65). */
  e_arretrato?: boolean;
  /** Una gamba della coppia di una successione con l'arretrato agli eredi: si toglie solo con la successione (giro sulle correzioni, GC8). */
  e_conguaglio_con_arretrato?: boolean;
  /** Una riga fra le fonti dell'arretrato di una successione: si corregge annullando prima la successione (ultima revisione, UE7). */
  e_fonte_arretrato?: boolean;
  /** Rilievo V9 (1.11.0-beta.42): perché il piano che ha assorbito il saldo non si riscrive più, e come riaprirlo. */
  fermo_del_piano?: { perche: string | null; rimedi: string[]; ragioni: Array<'scrittura' | 'incasso' | 'credito' | 'conguaglio'> } | null;
  subentro_id?: number | null;
  subentro?: { id: number; decorrenza: string; tipo_passaggio: string } | null;
  origine: string;
  gestione_id: number;
  anagrafica_id: number | null;
  /** Il piano rate che ha assorbito questo saldo, cioè chi tiene il lucchetto. */
  piano_rate_id: number | null;
  piano_rate?: { id: number; nome: string } | null;
  gestione: Gestione;
  anagrafica: { 
    id: number;
    nome: string;
    cognome: string;
  } | null; 
}

export interface AnagraficaConSaldi {
  id: number;
  nome: string;
  cognome: string;
  saldi?: Saldo[]; 
  pivot?: {
    id?: number;
    tipologia: string;
    /** B2: il periodo della riga; `data_fine` compilata = titolare uscito (la riga resta per i suoi saldi). */
    data_inizio?: string | null;
    data_fine?: string | null;
  };
}

export interface ImmobileConSaldi {
  id: number;
  nome: string;
  interno: string | null;
  scala: { name: string } | null;
  palazzina: { name: string } | null;
  anagrafiche: AnagraficaConSaldi[];
  saldi: Saldo[]; 
}