// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le forme della pagina «Configurazione email» (decisione 572): le caselle dello studio come le scrive il server (`CaselleDelloStudio`), con lo stato in
 * una parola, la riga «Adesso», la card in alto e l'ultima prova. La password non c'è mai: solo se è impostata.
 */

/** Lo stato di una casella: «Da impostare», «Da provare», «Provata», «Prova non andata», «Spenta» (solo la posta), «Già pronta» (posta dell'installazione). */
export type StatoDellaCasella = 'da_impostare' | 'da_provare' | 'provata' | 'non_andata' | 'spenta' | 'gia_pronta';

/** Il campo che la pagina mette sotto il cursore per correggere il motivo di una prova non andata. */
export type CampoDaCorreggere = 'password' | 'porta' | 'server' | 'utente' | 'cifratura' | 'mittente';

export interface MotivoDellaProva {
    chiave: string;
    /** «Il gestore non ha accettato l'utente o la password.» */
    titolo: string;
    /** «Riscrivi la password della casella PEC e riprova. …» */
    fai: string;
    breve: string;
    azione: { campo: CampoDaCorreggere; etichetta: string } | null;
}

export interface ProvaDellaCasella {
    /** «il 19/11 alle 10:14» */
    quando: string | null;
    andata: boolean;
    destinatario: string | null;
    motivo: MotivoDellaProva | null;
    /** Il testo del server, per chi assiste lo studio. */
    testo: string | null;
}

export interface CardDellaCasella {
    valore: string;
    sotto: string;
}

interface CasellaComune {
    stato: StatoDellaCasella;
    parola: string;
    adesso: string;
    /** «andrea.costa@pec.studiocosta.it · smtps.pec.ilgestore.it, porta 465, SSL» */
    riga: string | null;
    card: CardDellaCasella;
    ultima_prova: ProvaDellaCasella | null;
}

export interface CasellaDellaPosta extends CasellaComune {
    /** La posta la prepara chi gestisce l'installazione: la card è di sola lettura. */
    sola_lettura: boolean;
    /** La posta mai provata (dopo l'aggiornamento): «Fai una prova quando puoi: gli invii continuano.». Null altrimenti. */
    consiglio?: string | null;
}

export interface CasellaPecDelloStudio extends CasellaComune {
    valori: { indirizzo: string | null; server: string | null; porta: number; cifratura: 'ssl' | 'tls'; utente: string | null };
    password_impostata: boolean;
    /** L'indirizzo PEC della carta dello studio (Impostazioni › Stampe), da proporre. */
    dalla_carta: string | null;
}

export interface InviiDelMese {
    /** «Gli invii di novembre» */
    titolo: string;
    /** «214 email» */
    valore: string;
    /** «2 non partite · nessuna PEC» */
    sotto: string;
    url: string;
}

/** Le impostazioni della posta come le riceve la pagina, senza la password. */
export interface ImpostazioniDellaPosta {
    mail_enabled?: boolean;
    mail_driver?: string;
    mail_host?: string | null;
    mail_port?: string | number | null;
    mail_username?: string | null;
    mail_encryption?: string | null;
    mail_from_address?: string | null;
    mail_from_name?: string | null;
}

/** La risposta di una prova non andata: il testo del server, il motivo in parole, e per la posta il suggerimento della porta rifiutata (Coda 121). */
export interface RispostaDellaProva {
    success?: boolean;
    message?: string;
    motivo?: MotivoDellaProva;
    suggerimento?: string;
    campo?: string;
    prova?: { quando: string | null; andata: boolean; destinatario: string };
}

/** «Promemoria per email delle convocazioni da spedire»: l'interruttore e la sua riga «Adesso», come le scrive il server. */
export interface PromemoriaDelleConvocazioni {
    acceso: boolean;
    adesso: string;
}
