// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * «Incolla da un foglio di calcolo», nella pagina delle quote di una tabella.
 *
 * I millesimi arrivano quasi sempre in un foglio: dal tecnico che ha redatto la tabella, o
 * dall'amministratore di prima. L'associazione in blocco porta le unità nell'elenco, ma i valori
 * si battevano poi uno a uno. Qui si incollano le colonne del foglio — l'unità e il valore — e
 * questa funzione dice, riga per riga, che cosa ha capito. **Non scrive niente**: riempie la
 * griglia, e il salvataggio resta quello della pagina.
 *
 * Il rischio non è fermarsi: è **capire male in silenzio**. Per questo, dove il testo incollato
 * si può leggere in due modi, la funzione non sceglie: lo dice (`colonnaDaScegliere`,
 * `convenzioneDaScegliere`) e non legge nessuna riga finché chi incolla non ha risposto.
 *
 * ## Quale colonna sono i valori
 *
 * La prima colonna è l'unità. Con una sola altra colonna piena, i valori sono quella. Con più
 * colonne piene («Unità | Proprietà | Scale») prendere l'ultima vorrebbe dire scrivere le scale
 * nella tabella di proprietà senza dirlo: la colonna la sceglie chi incolla.
 *
 * ## Il punto e la virgola
 *
 * `1.250` è milleduecentocinquanta in un foglio italiano e uno virgola venticinque in uno
 * inglese. Sui millesimi è raro, ma la pagina serve anche per metri cubi e kWh. La convenzione si
 * decide **su tutta la colonna**, non valore per valore:
 *
 * - un valore con punto **e** virgola dice da solo qual è il decimale (l'ultimo dei due);
 * - un valore con la virgola: la virgola è il decimale, e il punto è delle migliaia;
 * - nessuna virgola, e un valore col punto ha un numero di decimali diverso da tre (`94.91`):
 *   il punto è il decimale;
 * - nessuna virgola, e ogni valore col punto ha esattamente tre cifre dopo (`1.250`): non si può
 *   sapere, e si chiede. Lo stesso se due valori si contraddicono.
 *
 * ## Come si riconosce l'unità
 *
 * Dal **nome**, senza badare a maiuscole e spazi, oppure dai **dati catastali** scritti come
 * `particella/subalterno` (`658/10`): le tabelle dei tecnici sono spesso per subalterno. Si cerca
 * **prima fra le unità già nella tabella**, poi fra le altre: in un complesso con dieci palazzine
 * e un «Appartamento 1» in ciascuna, dopo «Associa in blocco» della palazzina giusta il nome
 * torna a dire una cosa sola. Un nome che corrisponde ancora a più unità è «ambiguo» e non si
 * indovina.
 */

export type EsitoRigaIncollata = 'riconosciuta' | 'non_trovata' | 'ambigua' | 'doppia' | 'valore_non_letto';

/** Qual è il separatore dei decimali: con `virgola` il punto è delle migliaia, e viceversa. */
export type ConvenzioneDecimale = 'virgola' | 'punto';

export interface UnitaPerIncolla {
    id: number;
    nome: string;
    particella_catasto?: string | null;
    subalterno_catasto?: string | null;
    palazzina?: { name?: string | null } | null;
}

export interface RigaIncollata {
    /** La riga del testo incollato, da 1: è quella che l'utente ritrova nel suo foglio. */
    riga: number;
    /** L'unità come è scritta nel foglio. */
    chiave: string;
    /** Il valore letto, col punto e al massimo cinque decimali; `null` se non si legge. */
    valore: string | null;
    /** L'unità riconosciuta, quando l'esito è `riconosciuta` o `doppia`. */
    immobileId: number | null;
    esito: EsitoRigaIncollata;
}

export interface ColonnaDelFoglio {
    /** La posizione nel foglio incollato, da 0: la colonna 0 è l'unità. */
    indice: number;
    /** L'intestazione, se la prima riga del foglio lo è. */
    intestazione: string | null;
    /** Il primo valore della colonna, come è scritto nel foglio. */
    esempio: string;
}

export interface OpzioniIncolla {
    /** Gli id delle unità già nella tabella: si cerca prima fra queste. */
    inTabella?: number[];
    /** La colonna dei valori scelta da chi incolla, quando il foglio ne ha più di una. */
    colonnaValori?: number | null;
    /** La convenzione scelta da chi incolla, quando il foglio non la dice. */
    convenzione?: ConvenzioneDecimale | null;
}

export interface EsitoIncolla {
    righe: RigaIncollata[];
    riconosciute: number;
    daGuardare: number;
    /** Vero quando la prima riga è stata presa per l'intestazione del foglio e saltata. */
    intestazioneSaltata: boolean;
    /** Le colonne piene oltre la prima: più di una, e la scelta è di chi incolla. */
    colonne: ColonnaDelFoglio[];
    colonnaValori: number | null;
    colonnaDaScegliere: boolean;
    convenzione: ConvenzioneDecimale | null;
    convenzioneDaScegliere: boolean;
    /** Un valore del foglio che si legge in due modi, con le due letture. */
    dubbio: { testo: string; conLaVirgola: string | null; colPunto: string | null } | null;
}

/** I decimali che la colonna conserva: `quote_tabella.valore` è `decimal(12,5)`. */
const DECIMALI_COLONNA = 5;

const normalizza = (s: string): string => s.toLocaleLowerCase('it').replace(/\s+/g, ' ').trim();

/** La cella senza spazi e senza il segno dell'unità di misura in coda. */
const pulisci = (cella: string): string => cella.replace(/[\s ]/g, '').replace(/[‰%]$/, '');

const sembraUnNumero = (cella: string): boolean => /^[\d.,]+$/.test(pulisci(cella)) && /\d/.test(cella);

/**
 * Il valore di una cella, col punto, letto con la convenzione data. `null` se non è un numero, se
 * è negativo (un millesimo negativo non esiste, e il server lo rifiuterebbe: `min:0`) o se il
 * separatore delle migliaia non sta ogni tre cifre: `67.5` in un foglio dove il punto è delle
 * migliaia non è né sessantasette e mezzo né seicentosettantacinque, e non si indovina.
 */
export function leggiValoreIncollato(cella: string, convenzione: ConvenzioneDecimale = 'virgola'): string | null {
    const s = pulisci(cella);
    if (s === '') return null;

    const [decimale, migliaia] = convenzione === 'virgola' ? [',', '.'] : ['.', ','];
    const pezzi = s.split(decimale);
    if (pezzi.length > 2) return null;

    let intera = pezzi[0];
    const frazione = pezzi[1] ?? '';
    if (intera.includes(migliaia)) {
        const gruppi = intera.split(migliaia);
        if (!/^\d{1,3}$/.test(gruppi[0]) || gruppi.slice(1).some((g) => !/^\d{3}$/.test(g))) return null;
        intera = gruppi.join('');
    }
    if (!/^\d*$/.test(intera) || !/^\d*$/.test(frazione) || (intera === '' && frazione === '')) return null;
    if (pezzi.length === 2 && frazione === '') return null;

    const n = Number(`${intera === '' ? '0' : intera}.${frazione === '' ? '0' : frazione}`);
    if (!Number.isFinite(n) || n < 0) return null;

    return String(Number(n.toFixed(DECIMALI_COLONNA)));
}

/**
 * La convenzione di una colonna intera. `null` quando nessun valore ha un separatore (e allora
 * non c'è niente da decidere), `'dubbio'` quando il foglio non la dice o si contraddice.
 */
function convenzioneDellaColonna(celle: string[]): { convenzione: ConvenzioneDecimale | 'dubbio' | null; esempio: string | null } {
    let virgola = 0;
    let punto = 0;
    let primoDeciso: Record<ConvenzioneDecimale, string | null> = { virgola: null, punto: null };
    let incerto: string | null = null;
    const vota = (c: ConvenzioneDecimale, cella: string) => {
        if (c === 'virgola') virgola++; else punto++;
        primoDeciso = { ...primoDeciso, [c]: primoDeciso[c] ?? cella };
    };

    for (const cella of celle) {
        const s = pulisci(cella);
        if (!/^[\d.,]+$/.test(s)) continue;
        const punti = s.split('.').length - 1;
        const virgole = s.split(',').length - 1;

        if (punti > 0 && virgole > 0) {
            vota(s.lastIndexOf(',') > s.lastIndexOf('.') ? 'virgola' : 'punto', cella);
        } else if (virgole > 0) {
            vota(virgole > 1 ? 'punto' : 'virgola', cella);
        } else if (punti > 1) {
            vota('virgola', cella);
        } else if (punti === 1) {
            if (s.length - s.indexOf('.') - 1 !== 3) vota('punto', cella);
            else incerto ??= cella;
        }
    }

    if (virgola > 0 && punto > 0) return { convenzione: 'dubbio', esempio: incerto ?? primoDeciso.punto };
    if (virgola > 0) return { convenzione: 'virgola', esempio: null };
    if (punto > 0) return { convenzione: 'punto', esempio: null };
    if (incerto !== null) return { convenzione: 'dubbio', esempio: incerto };

    return { convenzione: null, esempio: null };
}

/**
 * Il separatore delle colonne, deciso su tutto il testo: la tabulazione (il copia e incolla di un
 * foglio), il punto e virgola (un CSV), o niente — e allora vale l'ultimo spazio della riga.
 */
function inCelle(righe: string[]): string[][] {
    const separatore = righe.some((r) => r.includes('\t')) ? '\t' : righe.some((r) => r.includes(';')) ? ';' : null;

    return righe.map((riga) => {
        if (separatore !== null) return riga.split(separatore).map((c) => c.trim());
        const m = riga.trim().match(/^(.*\S)\s+(\S+)$/);

        return m ? [m[1], m[2]] : [riga.trim()];
    });
}

export function leggiIncolla(testo: string, unita: UnitaPerIncolla[], opzioni: OpzioniIncolla = {}): EsitoIncolla {
    const esito: EsitoIncolla = {
        righe: [], riconosciute: 0, daGuardare: 0, intestazioneSaltata: false,
        colonne: [], colonnaValori: null, colonnaDaScegliere: false,
        convenzione: null, convenzioneDaScegliere: false, dubbio: null,
    };

    // Le righe piene, ciascuna con il suo numero nel foglio.
    const piene = testo.split(/\r?\n/).map((grezza, i) => ({ grezza, riga: i + 1 })).filter((r) => r.grezza.trim() !== '');
    if (piene.length === 0) return esito;

    const tabella = inCelle(piene.map((r) => r.grezza));

    // «Unità ; Millesimi» in testa al foglio: una prima riga senza nessun numero dopo la prima colonna è l'intestazione.
    let intestazione: string[] | null = null;
    if (!tabella[0].slice(1).some(sembraUnNumero)) {
        intestazione = tabella[0];
        esito.intestazioneSaltata = true;
    }
    const dati = tabella.map((celle, i) => ({ celle, riga: piene[i].riga })).slice(intestazione ? 1 : 0);
    if (dati.length === 0) return esito;

    // Le colonne piene oltre la prima.
    const larghezza = Math.max(...dati.map((d) => d.celle.length));
    for (let indice = 1; indice < larghezza; indice++) {
        const prima = dati.find((d) => (d.celle[indice] ?? '') !== '');
        if (prima) {
            esito.colonne.push({ indice, intestazione: intestazione?.[indice] || null, esempio: prima.celle[indice] });
        }
    }

    if (esito.colonne.length === 1) {
        esito.colonnaValori = esito.colonne[0].indice;
    } else if (esito.colonne.length > 1) {
        const scelta = esito.colonne.find((c) => c.indice === opzioni.colonnaValori);
        if (!scelta) {
            esito.colonnaDaScegliere = true;
            return esito;
        }
        esito.colonnaValori = scelta.indice;
    }

    const cellaValore = (celle: string[]): string => (esito.colonnaValori === null ? '' : celle[esito.colonnaValori] ?? '');

    const dedotta = convenzioneDellaColonna(dati.map((d) => cellaValore(d.celle)));
    if (dedotta.convenzione === 'dubbio') {
        if (!opzioni.convenzione) {
            esito.convenzioneDaScegliere = true;
            esito.dubbio = dedotta.esempio === null ? null : {
                testo: dedotta.esempio.trim(),
                conLaVirgola: leggiValoreIncollato(dedotta.esempio, 'virgola'),
                colPunto: leggiValoreIncollato(dedotta.esempio, 'punto'),
            };
            return esito;
        }
        esito.convenzione = opzioni.convenzione;
    } else {
        esito.convenzione = dedotta.convenzione;
    }
    const convenzione: ConvenzioneDecimale = esito.convenzione ?? 'virgola';

    // Le unità per nome e per dati catastali.
    const perNome = new Map<string, number[]>();
    const perCatasto = new Map<string, number[]>();
    const aggiungi = (mappa: Map<string, number[]>, chiave: string, id: number) => {
        mappa.set(chiave, [...(mappa.get(chiave) ?? []), id]);
    };
    for (const u of unita) {
        aggiungi(perNome, normalizza(u.nome ?? ''), u.id);
        const particella = normalizza(String(u.particella_catasto ?? ''));
        const subalterno = normalizza(String(u.subalterno_catasto ?? ''));
        if (particella !== '' && subalterno !== '') {
            aggiungi(perCatasto, `${particella}/${subalterno}`, u.id);
        }
    }
    const inTabella = new Set(opzioni.inTabella ?? []);
    const cerca = (chiave: string): number[] => {
        const nome = normalizza(chiave);
        const catasto = nome.replace(/\s*\/\s*/, '/');
        const fra = (ammessa: (id: number) => boolean): number[] => {
            const perIlNome = (perNome.get(nome) ?? []).filter(ammessa);

            return perIlNome.length > 0 ? perIlNome : (perCatasto.get(catasto) ?? []).filter(ammessa);
        };
        const giaDentro = fra((id) => inTabella.has(id));

        return giaDentro.length > 0 ? giaDentro : fra((id) => !inTabella.has(id));
    };

    const giaViste = new Set<number>();
    for (const d of dati) {
        const chiave = d.celle[0] ?? '';
        const valore = leggiValoreIncollato(cellaValore(d.celle), convenzione);
        const riga: RigaIncollata = { riga: d.riga, chiave, valore, immobileId: null, esito: 'non_trovata' };
        const trovate = cerca(chiave);

        if (valore === null) {
            riga.esito = 'valore_non_letto';
        } else if (trovate.length > 1) {
            riga.esito = 'ambigua';
        } else if (trovate.length === 1) {
            riga.immobileId = trovate[0];
            riga.esito = giaViste.has(trovate[0]) ? 'doppia' : 'riconosciuta';
            giaViste.add(trovate[0]);
        }
        esito.righe.push(riga);
    }

    esito.riconosciute = esito.righe.filter((r) => r.esito === 'riconosciuta').length;
    esito.daGuardare = esito.righe.length - esito.riconosciute;

    return esito;
}
