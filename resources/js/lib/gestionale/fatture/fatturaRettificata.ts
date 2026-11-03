/**
 * La fattura che una nota di credito del fornitore rettifica (Coda 165, 1.11.0-beta.36; decisione 26, punto 6 in
 * `docs/subentro_e_competenza_temporale.md` §9).
 *
 * Qui stanno solo i **testi** e le etichette: le regole — quale fattura si può scegliere, quando la nota non si registra,
 * di quanto chiederebbero in più le rate — le calcola il server (`FatturaPassiva::motivoBloccoNotaCollegata`,
 * `avvisoNotaCollegata`, `FattureRettificabili`) e arrivano già scritte con ogni candidata. Il modulo non decide, legge:
 * due regole, una qui e una là, sono due schermate che si contraddicono.
 */

export interface FatturaRettificabile {
    id: number;
    numero_documento: string;
    /** `YYYY-MM-DD` */
    data_documento: string | null;
    /** Centesimi, lordo (imponibile più IVA). */
    totale_documento: number;
    esercizio_nome: string | null;
    is_pregresso: boolean;
    /** Centesimi già rettificati da altre note collegate. */
    gia_rettificato: number;
    /** Perché la nota non si può collegare a questa fattura, con la via — o `null`. */
    motivo_blocco_nota: string | null;
    /** Che cosa resta se la fattura sta in un piano che ha già incassato — o `null`. */
    avviso_nota: string | null;
    /** La fattura che numero e data del file dichiarano (il server la segna a ogni caricamento, R8 della Fase 1-bis). */
    corrisponde_al_file?: boolean;
}

/** Una riga della nota per il server: dove riduce (unità o voce) e di quanto, lorda in centesimi (R4 della Fase 1-bis). */
export interface RigaNotaPerCandidate {
    conto_id: number | null;
    immobile_id: number | null;
    riduzione: number;
}

export type EsitoXmlFatturaRettificata =
    | 'proposta'
    | 'nessuna_dichiarata'
    | 'piu_dichiarate'
    | 'senza_data'
    | 'fornitore_da_scegliere'
    | 'non_trovata'
    | 'ambigua'
    | 'dichiarata_non_scelta';

export interface FatturaRettificataDaXml {
    esito: EsitoXmlFatturaRettificata;
    proposta: FatturaRettificabile | null;
    dichiarate: Array<{ numero: string; data: string | null }>;
}

/**
 * `€ 1.000,00`, come `MoneyHelper::format` (anche il negativo: `€ -1.000,00`). Il punto delle migliaia anche sotto i
 * diecimila, che `Intl.NumberFormat('it-IT')` non mette.
 */
export function euro(cents: number): string {
    const negativo = cents < 0;
    const [interi, decimali] = (Math.abs(cents) / 100).toFixed(2).split('.');
    const conPunti = interi.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return `€ ${negativo ? '-' : ''}${conPunti},${decimali}`;
}

/** `2025-12-22` → `22/12/2025`; quello che non riconosce lo lascia com'è. */
export function giorno(iso: string | null): string {
    if (!iso) return '';
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);

    return m ? `${m[3]}/${m[2]}/${m[1]}` : iso;
}

/** L'etichetta di una candidata nel selettore: numero, data, totale, e l'esercizio quando c'è. */
export function etichettaCandidata(c: FatturaRettificabile): string {
    const parti = [`n. ${c.numero_documento}`];
    if (c.data_documento) parti.push(`del ${giorno(c.data_documento)}`);
    let testo = `${parti.join(' ')} — ${euro(c.totale_documento)}`;
    if (c.gia_rettificato > 0) testo += ` (già rettificata per ${euro(c.gia_rettificato)})`;
    if (c.esercizio_nome) testo += ` · ${c.esercizio_nome}`;

    return testo;
}

/**
 * Le due righe di una candidata nel menu a tendina del modulo: la prima la identifica, la seconda la descrive. Su una
 * riga sola il menu, largo quanto la colonna, tagliava proprio la parte che conta («già rettificata per € 5…», visto a
 * video il 27/09/2026). L'etichetta intera resta per la ricerca e per il selettore della finestra «Collega».
 */
export function etichettaBreve(c: FatturaRettificabile): string {
    return `n. ${c.numero_documento}${c.data_documento ? ` del ${giorno(c.data_documento)}` : ''}`;
}

export function dettaglioCandidata(c: FatturaRettificabile): string {
    const parti = [euro(c.totale_documento)];
    if (c.gia_rettificato > 0) parti.push(`già rettificata per ${euro(c.gia_rettificato)}`);
    if (c.esercizio_nome) parti.push(c.esercizio_nome);

    return parti.join(' · ');
}

/**
 * Che cosa dice il modulo sotto il campo dopo aver letto l'XML — o `null` quando non c'è niente da dire (il file non
 * dichiara nessuna fattura). La proposta si dichiara come proposta: il campo resta modificabile, e chi registra la
 * controlla.
 */
export function testoEsitoXml(x: FatturaRettificataDaXml | null): string | null {
    if (!x) return null;
    const prima = x.dichiarate[0];
    const dichiarata = prima ? `n. ${prima.numero}${prima.data ? ` del ${giorno(prima.data)}` : ''}` : '';

    switch (x.esito) {
        case 'proposta':
            return `Il file dichiara di rettificare la fattura ${dichiarata}: è proposta qui sopra. Controlla che sia quella giusta.`;
        case 'piu_dichiarate':
            return `Il file dichiara di rettificare ${x.dichiarate.length} fatture (${x.dichiarate.map(d => `n. ${d.numero}`).join(', ')}): `
                + 'nessuna proposta. Una nota si collega a una fattura sola: scegli tu quale, o lascia vuoto.';
        case 'senza_data':
            return `Il file dichiara la fattura ${dichiarata} senza la data: con il solo numero non si propone, perché molti fornitori `
                + 'ricominciano la numerazione a gennaio. Sceglila tu, se è registrata.';
        case 'fornitore_da_scegliere':
            // Con più fatture dichiarate si nominano tutte, non solo la prima (testuale del quarto giro).
            return x.dichiarate.length > 1
                ? `Il file dichiara di rettificare ${x.dichiarate.length} fatture (${x.dichiarate.map(d => `n. ${d.numero}`).join(', ')}). Scegli prima il fornitore.`
                : `Il file dichiara la fattura ${dichiarata}. Scegli prima il fornitore, poi la fattura.`;
        case 'dichiarata_non_scelta':
            return `Il file dichiara di rettificare la fattura ${dichiarata}, ma nel campo non c'è: sceglila, se è quella.`;
        case 'ambigua':
            return `Il file dichiara la fattura ${dichiarata}, ma fra le fatture di questo fornitore ce n'è più d'una con quel `
                + 'numero e quella data: nessuna proposta, scegli tu quale.';
        case 'non_trovata':
            return `Il file dichiara la fattura ${dichiarata}, ma fra le fatture di questo fornitore non ce n'è una con quel numero e `
                + 'quella data. Se è registrata con un numero diverso, sceglila tu; se non è ancora registrata, registrala prima.';
        default:
            return null;
    }
}

/**
 * L'esito della fattura dichiarata dal file, **rifatto sulle candidate appena caricate**: nel lotto la nota si legge
 * insieme alla fattura che rettifica, prima che sia registrata, e l'esito della lettura restava «non trovata» anche
 * dopo (R8 della Fase 1-bis). Quello che dipende solo dal file (più fatture, niente data) resta com'era; il resto lo
 * decide il server, che segna la corrispondenza in `corrisponde_al_file`. Durante il caricamento non si dice niente.
 */
export function esitoCorrente(
    x: FatturaRettificataDaXml | null,
    candidate: FatturaRettificabile[],
    stato: { fornitoreScelto: boolean; caricamento: boolean; errore?: boolean; sceltaId?: number | null },
): FatturaRettificataDaXml | null {
    if (!x || x.esito === 'nessuna_dichiarata') return x;
    // Senza fornitore il campo non c'è: prima il fornitore, anche quando il file dichiara più fatture o una senza data
    // (W10 del terzo giro — il testo invitava a scegliere in un campo che non si vedeva).
    if (!stato.fornitoreScelto) return { ...x, esito: 'fornitore_da_scegliere', proposta: null };
    if (['piu_dichiarate', 'senza_data'].includes(x.esito)) return x;
    // Mentre carica, o se il caricamento è fallito, non si sa: niente testo (il modulo dice già dell'errore). Prima un
    // errore faceva leggere «non ce n'è una con quel numero», falso (verifica delle correzioni).
    if (stato.caricamento || stato.errore) return null;
    const segnate = candidate.filter(c => c.corrisponde_al_file);
    if (segnate.length === 1) {
        // «È proposta qui sopra» solo se nel campo c'è davvero: tolta a mano, o proposta mai fatta, si dice diversamente.
        return stato.sceltaId === undefined || stato.sceltaId === segnate[0].id
            ? { ...x, esito: 'proposta', proposta: segnate[0] }
            : { ...x, esito: 'dichiarata_non_scelta', proposta: null };
    }

    return { ...x, esito: segnate.length > 1 ? 'ambigua' : 'non_trovata', proposta: null };
}

/**
 * Le righe della nota che si sta scrivendo, per il server: la nota pregressa non ha righe e vale la sua testata, non
 * attribuibile; una riga su un'unità non ha voce (il servizio la toglie). Lorde con l'IVA per riga, come il modulo le
 * mostra: il server, che registra con le righe vere, resta l'ultimo a decidere.
 */
export function righeNotaPerCandidate(
    nota: { isPregresso: boolean; totaleCents: number; righe: Array<{ conto_id?: number | null; immobile_id?: number | null; lordoCents: number }> },
): RigaNotaPerCandidate[] {
    if (nota.isPregresso) {
        return nota.totaleCents ? [{ conto_id: null, immobile_id: null, riduzione: Math.abs(nota.totaleCents) }] : [];
    }

    return nota.righe
        .filter(r => r.lordoCents !== 0)
        .map(r => ({
            conto_id: r.immobile_id ? null : (r.conto_id ?? null),
            immobile_id: r.immobile_id ?? null,
            riduzione: r.lordoCents,
        }));
}

/** La candidata scelta, se c'è, con il motivo che la blocca e l'avviso che chiede conferma. */
export function statoScelta(
    candidate: FatturaRettificabile[],
    id: number | null,
): { scelta: FatturaRettificabile | null; motivo: string | null; avviso: string | null } {
    const scelta = id === null ? null : candidate.find(c => c.id === id) ?? null;

    return {
        scelta,
        motivo: scelta?.motivo_blocco_nota ?? null,
        avviso: scelta?.motivo_blocco_nota ? null : scelta?.avviso_nota ?? null,
    };
}
