/**
 * Il credito della posizione in un incasso: quanto, su quali righe, e quanto paga il denaro (Coda 167, decisioni 30.7 e
 * 30.11, 1.11.0-beta.40). Tutto in centesimi interi: in euro decimali un residuo di 1e-14 bastava a creare una «parte in
 * più» di € 0,00 e a spegnere «Conferma incasso» (reperto S6 del rigiro della Fase 1-bis).
 *
 * Due funzioni:
 *
 * - `pianifica` è lo **specchio** di `App\Services\Gestionale\PianoCreditoIncasso::pianifica()`: date le righe che il
 *   modulo manda e i crediti impegnati, dice cosa farà il server. Le due copie leggono la stessa tabella di casi
 *   (`tests/Fixtures/piano_credito_incasso.json`).
 * - `pianificaCoperture` costruisce le righe da mandare: dai debiti a schermo (in ordine, il più vecchio prima), dal
 *   credito impegnato e dai soldi versati. Il credito si riserva sulle rate della sua gestione, salvo la scelta
 *   dell'amministratore (30.11); i soldi coprono il resto dalla rata più vecchia, come sempre. Vedi `piano()`.
 */

export interface Voce {
    chiave: number | string;
    importo: number;
    gestione: number | null;
}

export interface Piano {
    credito: Record<string, Record<string, number>>;
    contante: Record<string, number>;
    usato: number;
    serve_scelta: boolean;
}

const somma = (valori: number[]) => valori.reduce((a, b) => a + b, 0);
const chiaveGestione = (g: number | null) => (g === null ? '-' : String(g));

/** Il massimo credito che le righe possono assorbire: per gestione il minore fra credito e righe; fra gestioni, in tutto. */
function assegnabile(righe: Voce[], crediti: Voce[], fraGestioni: boolean): number {
    if (fraGestioni) {
        return Math.min(somma(crediti.map(c => c.importo)), somma(righe.map(r => r.importo)));
    }
    const perGestione = (voci: Voce[]) => voci.reduce<Record<string, number>>((acc, v) => {
        const g = chiaveGestione(v.gestione);
        acc[g] = (acc[g] ?? 0) + v.importo;
        return acc;
    }, {});
    const righeG = perGestione(righe);
    return Object.entries(perGestione(crediti)).reduce((tot, [g, k]) => tot + Math.min(k, righeG[g] ?? 0), 0);
}

/** Lo specchio del pianificatore del server: stessa regola, stesso ordine, stessi numeri. */
export function pianifica(righe: Voce[], crediti: Voce[], contante: number, creditoPrima: boolean, fraGestioni: boolean | null): Piano {
    const totaleRighe = somma(righe.map(r => r.importo));
    const scoperto = Math.max(0, totaleRighe - contante);
    const quanto = (fra: boolean) => creditoPrima
        ? assegnabile(righe, crediti, fra)
        : Math.min(assegnabile(righe, crediti, fra), scoperto);

    const serveScelta = quanto(true) > quanto(false);
    const fra = serveScelta && fraGestioni === true;
    let daAssegnare = quanto(fra);

    const ordine = creditoPrima ? righe : [...righe].reverse();
    const restoRiga: Record<string, number> = {};
    righe.forEach(r => { restoRiga[String(r.chiave)] = r.importo; });
    const restoCredito: Record<string, number> = {};
    crediti.forEach(c => { restoCredito[String(c.chiave)] = c.importo; });
    const credito: Record<string, Record<string, number>> = {};

    const passata = (soloSuaGestione: boolean) => {
        for (const c of crediti) {
            for (const r of ordine) {
                if (daAssegnare <= 0 || restoCredito[String(c.chiave)] <= 0) break;
                if (soloSuaGestione && r.gestione !== c.gestione) continue;
                const quota = Math.min(restoRiga[String(r.chiave)], restoCredito[String(c.chiave)], daAssegnare);
                if (quota <= 0) continue;
                credito[String(c.chiave)] ??= {};
                credito[String(c.chiave)][String(r.chiave)] = (credito[String(c.chiave)][String(r.chiave)] ?? 0) + quota;
                restoRiga[String(r.chiave)] -= quota;
                restoCredito[String(c.chiave)] -= quota;
                daAssegnare -= quota;
            }
        }
    };

    passata(true);
    if (fra) passata(false);

    const usato = somma(Object.values(credito).map(perRiga => somma(Object.values(perRiga))));

    return { credito, contante: restoRiga, usato, serve_scelta: serveScelta };
}

export interface Debito { chiave: number; residuo: number; gestione: number | null }
export interface CreditoImpegnato { chiave: number; disponibile: number; gestione: number | null }
export interface Coperture {
    /** Quanto coprire di ogni debito (credito più denaro), in centesimi. */
    copertura: Record<number, number>;
    /** Quanto credito prendere da ogni riga di credito, in centesimi. */
    creditoUsato: Record<number, number>;
    /** I soldi versati che avanzano: la parte in più. */
    eccedenza: number;
    /** Il credito di una gestione coprirebbe righe di un'altra: la scelta tocca all'amministratore (30.11). */
    serveScelta: boolean;
}

/**
 * Le coperture da mandare, per un ordine e una risposta sulle gestioni (la correzione verificata dal reperto T1 del terzo
 * giro della Fase 1-bis, su 40.000 casi a due gestioni):
 *
 * 1. il credito che le rate possono assorbire: per gestione il minore fra credito e debiti di quella gestione; fra
 *    gestioni, in tutto;
 * 2. il tetto dell'ordine: con «prima i soldi» solo lo scoperto (debiti meno soldi), con «prima il credito» tutto;
 * 3. il credito si riserva sulle rate della SUA gestione, dalla più vecchia, fino al tetto; fra gestioni, poi, sulle altre;
 * 4. i soldi vanno sul resto, dalla rata più vecchia, su tutte le gestioni; quello che avanza è la parte in più.
 *
 * Prima il denaro e il credito stavano in un unico budget per scadenza: una rata più vecchia di un'altra gestione si
 * prendeva i soldi, e il credito non arrivava alla rata della sua gestione — restava fermo, o chiedeva di passare di
 * gestione senza bisogno. Su una gestione sola le coperture sono le stesse di prima della beta.
 */
function piano(debiti: Debito[], crediti: CreditoImpegnato[], contante: number, creditoPrima: boolean, fra: boolean) {
    const totaleDebiti = somma(debiti.map(d => d.residuo));
    const perGestione = <T extends { gestione: number | null }>(voci: T[], importo: (v: T) => number) => voci.reduce<Record<string, number>>((acc, v) => {
        acc[chiaveGestione(v.gestione)] = (acc[chiaveGestione(v.gestione)] ?? 0) + importo(v);
        return acc;
    }, {});
    const debitiG = perGestione(debiti, d => d.residuo);
    const assorbibile = fra
        ? Math.min(somma(crediti.map(c => c.disponibile)), totaleDebiti)
        : Object.entries(perGestione(crediti, c => c.disponibile)).reduce((t, [g, k]) => t + Math.min(k, debitiG[g] ?? 0), 0);
    let daRiservare = creditoPrima ? assorbibile : Math.min(assorbibile, Math.max(0, totaleDebiti - contante));

    const resto: Record<number, number> = {};
    const dalCredito: Record<number, number> = {};
    debiti.forEach(d => { resto[d.chiave] = d.residuo; dalCredito[d.chiave] = 0; });
    const restoCredito: Record<number, number> = {};
    crediti.forEach(c => { restoCredito[c.chiave] = c.disponibile; });
    const creditoUsato: Record<number, number> = {};

    const riserva = (soloSuaGestione: boolean) => {
        for (const c of crediti) {
            for (const d of debiti) {
                if (daRiservare <= 0 || restoCredito[c.chiave] <= 0) break;
                if (soloSuaGestione && d.gestione !== c.gestione) continue;
                const q = Math.min(resto[d.chiave], restoCredito[c.chiave], daRiservare);
                if (q <= 0) continue;
                dalCredito[d.chiave] += q;
                resto[d.chiave] -= q;
                restoCredito[c.chiave] -= q;
                creditoUsato[c.chiave] = (creditoUsato[c.chiave] ?? 0) + q;
                daRiservare -= q;
            }
        }
    };
    riserva(true);
    if (fra) riserva(false);

    let soldi = contante;
    const copertura: Record<number, number> = {};
    for (const d of debiti) {
        const q = Math.max(0, Math.min(resto[d.chiave], soldi));
        copertura[d.chiave] = dalCredito[d.chiave] + q;
        soldi -= q;
    }

    return { copertura, creditoUsato, eccedenza: Math.max(0, soldi), usato: somma(Object.values(creditoUsato)) };
}

export function pianificaCoperture(
    debiti: Debito[],
    crediti: CreditoImpegnato[],
    contante: number,
    creditoPrima: boolean,
    fraGestioni: boolean | null,
): Coperture {
    const senza = piano(debiti, crediti, contante, creditoPrima, false);
    const con = piano(debiti, crediti, contante, creditoPrima, true);
    const serveScelta = con.usato > senza.usato;
    const scelto = serveScelta && fraGestioni === true ? con : senza;

    return {
        copertura: scelto.copertura,
        creditoUsato: scelto.creditoUsato,
        eccedenza: scelto.eccedenza,
        serveScelta,
    };
}
