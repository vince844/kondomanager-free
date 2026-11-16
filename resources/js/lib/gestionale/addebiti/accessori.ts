// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
/**
 * Gli interessi di mora e le spese per il ritardo nella pagina: le frasi della proposta degli interessi (`ProponiInteressiDialog.vue`), della finestra
 * delle spese (`AddebitaSpesaDialog.vue`) e delle regole del recupero (`RegoleRecupero.vue`). Le cifre arrivano dal server in centesimi e i calcoli li
 * fa il server: qui si dicono, e si sommano solo le righe che l'amministratore tiene.
 */
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';

export interface Tratto {
    dal: string; al: string; giorni: number; capitale: number; tasso: string; fonte: string; divisore: number; esatti: number; causa: string;
}
export interface RigaInteressi {
    chiave: string; rata_id: number; immobile_id: number | null; unita: string | null; rata: string; piano: string; gestione_id: number; gestione: string;
    scadenza: string; dal: string | null; gia_registrati_al: string | null; tratti: Tratto[]; interessi: number; fermo: string | null;
    /** Gli interessi registrati che il giornale di oggi darebbe diversi: la frase, o niente. */
    riallineo?: string | null;
}
export interface PersonaInteressi {
    anagrafica_id: number; nome: string; righe: RigaInteressi[]; totale: number; crediti_sulle_rate: number; anticipi: number; quote_di_inquilino: number;
    /** Il debito coperto da un accordo di rientro in corso: la proposta lo lascia fuori. */
    in_accordo?: number;
}

/** Che cosa dice la proposta sotto il titolo. */
export const FRASE_PROPOSTA_INTERESSI = 'Il programma propone gli interessi riga per riga: niente si registra senza il tuo clic. Togli le righe o le persone '
    + 'che non vuoi; quelle che registri diventano una scadenza della persona, «Interessi di mora al …», e sono del condominio quando sono versate.';

/** Come si contano i giorni e si arrotonda: sotto la tabella. */
export const FRASE_CALCOLO = 'I giorni vanno dal giorno dopo la scadenza, più quelli che le regole del condominio concedono (o dal giorno dopo l\'emissione, '
    + 'se la rata è emessa più tardi), alla data della proposta compresa; un versamento conta dal giorno dopo. Gli interessi di ogni rata sono '
    + 'arrotondati al centesimo, rata per rata. Non si contano su interessi e spese già addebitati, e i crediti e gli anticipi della persona coprono '
    + 'prima le rate più vecchie.';

/** Perché comincia un tratto, detto a chi legge. */
export function testoCausa(causa: string): string {
    return ({
        scadenza: 'dalla scadenza',
        versamento: 'dopo un versamento',
        storno: 'dopo un versamento tornato indietro',
        credito: 'un credito o un anticipo copre una parte',
        anno: 'anno nuovo, tasso dell\'anno',
        regola: 'regola nuova del condominio',
        emissione: 'rata emessa',
    } as Record<string, string>)[causa] ?? causa;
}

/** «€ 0,3945»: gli interessi esatti di un tratto, in centesimi di centesimo, con quattro decimali. */
export function euroEsatti(centesimiDiCentesimo: number): string {
    const euro = centesimiDiCentesimo / 10000;

    return `€ ${new Intl.NumberFormat('it-IT', { minimumFractionDigits: 4, maximumFractionDigits: 4 }).format(euro)}`;
}

/** «dal 01/04/2026 al 30/04/2026, 30 giorni su € 300,00 al 1,60 % (tasso legale), su 365». */
export function testoTratto(t: Tratto): string {
    return `dal ${giorno(t.dal)} al ${giorno(t.al)}, ${t.giorni === 1 ? '1 giorno' : `${t.giorni} giorni`} su ${euroTesto(t.capitale)} al ${t.tasso} (${t.fonte}), su ${t.divisore}`;
}

/** Il totale delle righe tenute: solo quelle con una cifra, senza le righe ferme. */
export function totaleTenuto(persone: PersonaInteressi[], tenute: Set<string>): number {
    let totale = 0;
    for (const p of persone) {
        for (const r of p.righe) {
            if (r.fermo === null && tenute.has(r.chiave)) {
                totale += r.interessi;
            }
        }
    }

    return totale;
}

/** Accanto alla persona: i crediti e gli anticipi che hanno coperto, e le quote di inquilini che non si propongono. */
export function testoAccanto(p: PersonaInteressi): string[] {
    const frasi: string[] = [];
    if (p.crediti_sulle_rate > 0 || p.anticipi > 0) {
        frasi.push(`Alla data ha ${euroTesto(p.crediti_sulle_rate + p.anticipi)} di crediti e anticipi: coprono prima le rate più vecchie, e lì gli interessi non ci sono.`);
    }
    if ((p.in_accordo ?? 0) > 0) {
        frasi.push(`${euroTesto(p.in_accordo ?? 0)} sono in un accordo di rientro in corso: niente interessi su quelle rate finché l'accordo è in corso.`);
    }
    if (p.quote_di_inquilino > 0) {
        frasi.push(`Quote di inquilini scadute: ${euroTesto(p.quote_di_inquilino)}. Gli interessi non si propongono: se vuoi, addebitali al proprietario con «Spese del ritardo».`);
    }

    return frasi;
}

/** «Interessi già registrati fino al 29/06/2026: riparte dal giorno dopo.» */
export function testoGiaRegistrati(al: string | null): string | null {
    return al ? `Interessi già registrati fino al ${giorno(al)}: la proposta riparte dal giorno dopo.` : null;
}

/** I tipi e le basi di una spesa per il ritardo, come li dice la finestra. */
export interface Scelta { valore: string; nome: string }

/** Che cosa dice la finestra delle spese sotto il titolo. */
export const FRASE_SPESA = 'Una spesa per il ritardo chiesta a una persona: una sua scadenza, come una rata. Se lo studio l\'ha fatturata con la riga sull\'unità, '
    + 'addebitala dall\'estratto conto con «Nuovo addebito» e la sua riga di fattura: così il conguaglio la conta chiesta una volta sola. Qui va una spesa '
    + 'pagata dal condominio senza una riga sull\'unità (la raccomandata, la marca): diventa del condominio, sulla voce che scegli, quando è versata.';

/** La base mostrata e gli avvisi: mai un blocco. */
export const FRASE_BASE = 'La base si mostra a chi legge l\'addebito e non blocca niente: gli avvisi dicono dove una sentenza ha visto le cose in un altro modo.';

/** La causale proposta per un tipo di spesa. */
export function causaleDiSerie(tipo: string, tipi: Scelta[]): string {
    return tipi.find((t) => t.valore === tipo)?.nome ?? '';
}

/** Gli avvisi di una coppia tipo e base, dalla tabella del server. */
export function avvisiDi(avvisi: Record<string, Record<string, string[]>> | undefined, tipo: string, base: string): string[] {
    return tipo && base ? (avvisi?.[tipo]?.[base] ?? []) : [];
}

/** Una regola del recupero detta in una riga: da quando decorrono gli interessi, la tolleranza, il termine di serie delle lettere. */
export function testoDellaRegola(r: { giorni_decorrenza: number; giorni_tolleranza: number | null; termine_lettere_giorni: number | null }): string {
    const giorni = (n: number) => (n === 1 ? '1 giorno' : `${n} giorni`);
    const decorrenza = r.giorni_decorrenza > 0 ? `Interessi dal giorno dopo la scadenza più ${giorni(r.giorni_decorrenza)}` : 'Interessi dal giorno dopo la scadenza';
    const tolleranza = r.giorni_tolleranza === null ? 'tolleranza prima di sollecitare non scritta' : `tolleranza prima di sollecitare ${giorni(r.giorni_tolleranza)}`;
    const termine = r.termine_lettere_giorni === null ? 'termine delle lettere quello della finestra' : `termine di serie delle lettere ${giorni(r.termine_lettere_giorni)}`;

    return `${decorrenza} · ${tolleranza} · ${termine}.`;
}

/** Accanto al riferimento di un tasso scritto, nell'icona «i». */
export const SUGGERIMENTO_RIFERIMENTO = 'Scrivi da dove viene il tasso, così si può controllare: '
    + 'per esempio «art. 12 del regolamento contrattuale» o «assemblea del 10/03/2025, verbale n. 3, all\'unanimità». '
    + 'Si stampa nelle lettere e nell\'addebito degli interessi, accanto al tasso.';

/** Che cosa manca alla regola nuova per registrarla: la frase accanto al pulsante spento; null se non manca niente. */
export function cheCosaMancaAllaRegola(n: { dal: string | null; tasso_tipo: string; tasso_annuo: string; fonte: string; riferimento?: string }): string | null {
    if (!n.dal) {
        return 'Scrivi la data da cui vale';
    }
    if (n.tasso_tipo === '') {
        return 'Scegli il tasso';
    }
    if (n.tasso_tipo === 'scritto' && n.tasso_annuo.trim() === '') {
        return 'Scrivi il tasso annuo';
    }
    if (n.tasso_tipo === 'scritto' && n.fonte === '') {
        return 'Scegli da dove viene il tasso';
    }
    // Il riferimento si stampa accanto al tasso, nelle lettere e negli interessi: senza, il tasso scritto non si controlla.
    if (n.tasso_tipo === 'scritto' && (n.riferimento ?? '').trim() === '') {
        return 'Scrivi il riferimento del tasso';
    }

    return null;
}

/** Il tasso legale di un anno scritto a mano dallo studio, come lo manda la pagina. */
export interface TassoLegaleAMano {
    anno: number; tasso: string; tasso_annuo: string; fonte: string; scritto_da: string | null; scritto_il: string;
}

/** Che cosa manca al tasso legale scritto a mano per registrarlo; null se non manca niente. */
export function cheCosaMancaAlTassoLegale(t: { anno: number | null; tasso: string; fonte: string }): string | null {
    if (t.anno === null) {
        return 'Scegli l\'anno';
    }
    if (t.tasso.trim() === '') {
        return 'Scrivi il tasso';
    }
    if (t.fonte.trim() === '') {
        return 'Scrivi la fonte';
    }

    return null;
}

/** «scritto a mano da Mario Rossi il 16/12/2026»: chi e quando, per la tabella del tasso legale e per la finestra. */
export function testoScrittoAMano(t: { scritto_da: string | null; scritto_il: string }): string {
    return `scritto a mano${t.scritto_da ? ` da ${t.scritto_da}` : ''} il ${giorno(t.scritto_il)}`;
}
