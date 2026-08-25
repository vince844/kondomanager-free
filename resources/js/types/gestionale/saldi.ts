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
    tipologia: string;
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