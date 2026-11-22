// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Le parole della consegna di una lettera mandata per PEC dalla casella dello studio (decisioni 577 e 588). «Consegnata» si dice solo qui, e solo con la
 * consegna provata: la ricevuta di consegna del gestore letta dal file, o il giorno e l'ora scritti a mano con la prova allegata. Le altre parole delle lettere
 * non lo dicono mai (l'email, la stampa, la raccomandata: il programma non sa se sono arrivate), e la prova delle parole vietate lo controlla. Qui c'è anche
 * l'avviso di mancata consegna del gestore, che la dice «non consegnata». Il badge e la
 * frase della riga li scrive il server (`ConsegnaDellaPec`); qui c'è quello che la pagina compone da sé.
 */
import { giorno } from '@/lib/gestionale/debiti/rateScadute';

/** La consegna provata di una lettera per PEC, come la dà il server: il giorno, e se è scritta a mano con la prova. */
export interface ConsegnaDellaPec { giorno: string; a_mano: boolean }

/** Lo stato della PEC di una lettera con l'avviso di mancata consegna, come lo manda il server (`RegistroInvio::ESITO_NON_CONSEGNATA`). */
export const MANCATA_CONSEGNA = 'non_consegnata';

/** Il badge di una PEC con la consegna provata: lo stesso del server. */
export const BADGE_CONSEGNATA = 'Consegnata';

/**
 * «PEC non consegnata, avviso del gestore del 21/03/2026», per la colonna «Ultima lettera»: l'avviso di mancata consegna trascinato sulla riga. La lettera non
 * conta fra le arrivate, e il termine non corre.
 */
export function testoMancataConsegna(giornoDellAvviso: string): string {
    return `PEC non consegnata, avviso del gestore del ${giorno(giornoDellAvviso)}`;
}

/** «PEC consegnata il 04/12/2026», per la colonna «Ultima lettera»; con la consegna scritta a mano lo dice. */
export function testoPecDellUltimaLettera(c: ConsegnaDellaPec): string {
    return `PEC consegnata il ${giorno(c.giorno)}${c.a_mano ? ', scritta a mano' : ''}`;
}
