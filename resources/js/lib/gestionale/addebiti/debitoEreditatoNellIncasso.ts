// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Decisione 529: il pagamento di un erede si registra a nome suo e copre il suo debito ereditato. Le regole della pagina dell'incasso (`IncassoRateNew.vue`):
 * la riga «Debito ereditato da Ettore Fabbri» dell'erede pagante, quanto del versamento va lì e quanto resta per le sue rate, che cosa parte al server, e
 * la domanda sulla rata del defunto. Le cifre arrivano dal server in centesimi; l'amministratore le scrive in euro, e al server partono in euro: i centesimi
 * li fa il server, una volta sola.
 */

import { centesimiScritti } from './interessiDegliEredi';

/** Una riga del debito ereditato dell'erede, come la manda il server (`IncassoRateController::debitoEreditato`). */
export interface DebitoEreditatoDellIncasso {
    subentro_id: number;
    gestione_id: number;
    gestione: string;
    da: string;
    successione_del: string;
    aperto: number;
    aperto_formattato: string;
    /** Decisione 539: il defunto della successione, senza la catena («Massimo Bagnara»), per «Intestatario della ricevuta». */
    defunto?: string;
    /** Decisione 539: una parte del debito arriva da una successione di prima (la catena): la ricevuta va all'erede, senza scelta. */
    catena?: boolean;
}

/** Una successione in cui il pagante è il defunto, con l'arretrato agli eredi non ancora in un piano. */
export interface SuccessioneDelDefunto {
    subentro_id: number;
    decesso: string;
    eredi: string;
    eredi_ids: number[];
    /** Dopo che un piano ha preso l'arretrato: dov'è il debito degli eredi («nella Rata 1 — Preventivo 2027»), e se quella rata è emessa. */
    nel_piano?: string | null;
    emessa?: boolean;
}

/** La chiave di una riga: la successione e la gestione. */
export function chiaveEreditato(d: Pick<DebitoEreditatoDellIncasso, 'subentro_id' | 'gestione_id'>): string {
    return `${d.subentro_id}|${d.gestione_id}`;
}

/** I centesimi scritti in ogni riga (le righe vuote o non valide contano zero). */
export function centesimiDelleRighe(righe: DebitoEreditatoDellIncasso[], scritti: Record<string, string>): Record<string, number> {
    const esito: Record<string, number> = {};
    for (const r of righe) {
        esito[chiaveEreditato(r)] = centesimiScritti(scritti[chiaveEreditato(r)] ?? '') ?? 0;
    }

    return esito;
}

/** Quanto del versamento va al debito ereditato, in centesimi. */
export function centesimiAlDebitoEreditato(righe: DebitoEreditatoDellIncasso[], scritti: Record<string, string>): number {
    return Object.values(centesimiDelleRighe(righe, scritti)).reduce((s, c) => s + c, 0);
}

/**
 * Perché le righe del debito ereditato non vanno: una cifra che non è una cifra, più della parte aperta, più dell'importo versato. Null se vanno. Le stesse
 * regole del server, che però controlla da sé.
 */
export function perchéNonVaIlDebitoEreditato(righe: DebitoEreditatoDellIncasso[], scritti: Record<string, string>, versatoCentesimi: number): string | null {
    for (const r of righe) {
        const testo = (scritti[chiaveEreditato(r)] ?? '').trim();
        if (testo === '') {
            continue;
        }
        const c = centesimiScritti(testo);
        if (c === null) {
            return `Per il debito ereditato da ${r.da} scrivi una cifra, per esempio «150,00».`;
        }
        if (c > r.aperto) {
            return `Il debito ereditato da ${r.da} è ${r.aperto_formattato}: più di così non si registra qui.`;
        }
    }
    if (centesimiAlDebitoEreditato(righe, scritti) > versatoCentesimi) {
        return 'Il debito ereditato prende più dell\'importo versato: abbassa una riga, o scrivi l\'importo intero del versamento.';
    }

    return null;
}

/** Il pulsante «Tutto» di una riga: la parte aperta, al più quello che dell'importo versato resta libero dalle altre righe. In euro, come nel campo. */
export function tuttoNellaRiga(riga: DebitoEreditatoDellIncasso, righe: DebitoEreditatoDellIncasso[], scritti: Record<string, string>, versatoCentesimi: number): string {
    const altre = Object.entries(centesimiDelleRighe(righe, scritti)).filter(([k]) => k !== chiaveEreditato(riga)).reduce((s, [, c]) => s + c, 0);
    const libero = Math.max(0, versatoCentesimi - altre);
    const c = versatoCentesimi > 0 ? Math.min(riga.aperto, libero) : riga.aperto;

    return (c / 100).toFixed(2).replace('.', ',');
}

/** Le righe che partono al server: solo quelle con una cifra, in euro come le ha scritte l'amministratore. */
export function debitoEreditatoDaMandare(righe: DebitoEreditatoDellIncasso[], scritti: Record<string, string>): Array<{ subentro_id: number; gestione_id: number; importo: string }> {
    return righe
        .map((r) => ({ r, c: centesimiScritti(scritti[chiaveEreditato(r)] ?? '') ?? 0 }))
        .filter(({ c }) => c > 0)
        .map(({ r, c }) => ({ subentro_id: r.subentro_id, gestione_id: r.gestione_id, importo: (c / 100).toFixed(2) }));
}

/**
 * La domanda sulla rata del defunto (decisione 529): il debito è passato agli eredi; il versamento copre il loro debito («Per il debito degli eredi»: di chi ha
 * versato, se è un erede, altrimenti di tutti per quota) o resta al defunto. Le due scelte con la frase delle conseguenze, come la dice il server.
 */
export function scelteAgliEredi(defunto: string, s: SuccessioneDelDefunto, versatoDa: { id: number; nome: string } | null): Array<{ valore: boolean; titolo: string; conseguenza: string }> {
    // Dopo il piano il debito è nella rata nuova degli eredi: il versamento non lo copre più, e resta solo da decidere se va comunque al defunto.
    if (s.nel_piano) {
        return [{ valore: false, titolo: `Resta a ${defunto}`, conseguenza: `Il versamento va sulla sua rata, gli eredi devono ancora la loro rata nuova, e a ${defunto} resterà un credito.` }];
    }
    const erede = versatoDa !== null && s.eredi_ids.includes(versatoDa.id) ? versatoDa.nome : null;

    return [
        {
            valore: true,
            titolo: 'Per il debito degli eredi',
            conseguenza: erede !== null
                ? `Il versamento copre la parte di ${erede}, e il prossimo piano non gliela chiede di nuovo.`
                : `Il versamento copre il debito di ${s.eredi}, per quota, e il prossimo piano non lo chiede di nuovo.`,
        },
        {
            valore: false,
            titolo: `Resta a ${defunto}`,
            conseguenza: `Il prossimo piano chiederà agli eredi l'arretrato intero, e a ${defunto} resterà un credito.`,
        },
    ];
}

/** La frase sopra la domanda. */
export function fraseDelDebitoPassato(defunto: string, s: SuccessioneDelDefunto): string {
    if (s.nel_piano) {
        return `Il debito di ${defunto} è passato a ${s.eredi} con la successione del ${s.decesso}, ed è ${s.nel_piano} di ${s.eredi}: incassalo da lì${s.emessa ? '' : ' quando è emessa'}. `
            + `Se questo versamento resta comunque a ${defunto}, sceglilo qui sotto.`;
    }

    return `Il debito di ${defunto} è passato a ${s.eredi} con la successione del ${s.decesso}. Scegli che cosa copre questo versamento.`;
}

/**
 * Decisione 538: accanto a «Totale allocato», quando una parte del versamento va al debito ereditato, lo spaccato «Sul debito ereditato» / «Sulle sue rate» e
 * il totale che li somma. A video, per un versamento di € 32,79 tutto sul debito ereditato, «Totale allocato» diceva € 0,00: contava solo le rate.
 *
 * In centesimi. La parte delle rate arriva in euro, come la distribuzione della pagina, e si converte qui, una volta; il debito ereditato è già in centesimi.
 * Null senza una parte sul debito ereditato: la pagina dice il «Totale allocato» di sempre.
 */
export function spaccatoDellAllocato(sulleRateEuro: number, ereditatoCentesimi: number): { ereditato: number; rate: number; totale: number } | null {
    if (!(ereditatoCentesimi > 0)) {
        return null;
    }
    const rate = Math.max(0, Math.round((Number(sulleRateEuro) || 0) * 100));

    return { ereditato: ereditatoCentesimi, rate, totale: ereditatoCentesimi + rate };
}

/** Decisione 539: a chi è intestata la ricevuta del versamento di un erede sul debito ereditato. */
export type IntestatarioDellaRicevuta = 'erede' | 'eredi' | 'defunto';

/**
 * Decisione 539: le voci di «Intestatario della ricevuta» quando l'erede pagante versa sul debito ereditato: prima lui (la scelta di serie), poi gli eredi del
 * defunto («Eredi di Massimo Bagnara», una ricevuta unica) e il defunto (un bonifico dal suo conto), con il nome del defunto per l'esempio. Solo con una cifra
 * sul debito ereditato di un defunto solo e senza la catena: con due defunti, o con il debito che arriva da un erede morto a sua volta, «Eredi di …» non
 * direbbe di chi, e la ricevuta va all'erede. Null quando il menu non c'è.
 */
export function intestatariDellaRicevuta(erede: string, righe: DebitoEreditatoDellIncasso[], scritti: Record<string, string>): { defunto: string; voci: Array<{ valore: IntestatarioDellaRicevuta; etichetta: string }> } | null {
    const conCifra = righe.filter((r) => (centesimiScritti(scritti[chiaveEreditato(r)] ?? '') ?? 0) > 0);
    if (conCifra.length === 0 || conCifra.some((r) => r.catena === true)) {
        return null;
    }
    const defunti = [...new Set(conCifra.map((r) => r.defunto ?? r.da))];
    if (defunti.length !== 1) {
        return null;
    }
    const defunto = defunti[0];

    return {
        defunto,
        voci: [
            { valore: 'erede', etichetta: `${erede} (chi ha pagato)` },
            { valore: 'eredi', etichetta: `Eredi di ${defunto}` },
            { valore: 'defunto', etichetta: `${defunto} (il defunto)` },
        ],
    };
}
