// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I conti della sala sul posto: la barra della costituzione, l'esito proposto, il conflitto determinante, il quinto delle deleghe. **Sono i conti del
 * server** (`app/Services/Assemblee/Sala/ContiDellaSala.php`), scritti due volte perché senza rete la barra conta sul posto; tutti e due leggono lo stesso
 * file di casi (`tests/Fixtures/sala/conti.json`). Quando la rete torna vale il conto del server, che ricalcola da sé.
 *
 * I millesimi sono **centomillesimi interi** (1.000,00 millesimi = 100.000.000) e le soglie si scrivono con moltiplicazioni intere, mai divisioni:
 * - prima convocazione: `3 × v ≥ 2 × T` e `2 × t > P`; seconda: `3 × v ≥ T` e `3 × t ≥ P`;
 * - «Ordinaria»: `2 × f > i` e metà del valore in prima, un terzo in seconda; «Metà del valore»: `2 × f > i` e `2 × v ≥ T`; «Due terzi»: `2 × f > i` e
 *   `3 × v ≥ 2 × T`; «Quattro quinti»: `5 × f ≥ 4 × P` e `5 × v ≥ 4 × T`; «Unanimità»: tutti; un'altra maggioranza o nessuna: decide il presidente.
 * - il quinto delle deleghe: con più di venti condòmini, `5 × t ≤ P` e `5 × v ≤ T` (art. 67 c. 1 disp. att.).
 *
 * L'esito per condizione ({@link condizioni}) dice a parole i numeri dell'esito, una riga per le teste e una per il valore, con la soglia e quanto manca:
 * non rifà il conto, e lo stesso file di casi lo tiene uguale a quello del server.
 */
import type { ConteggioDellaSala, FotografiaDellaSala, TestaDellaFotografia } from '@/types/assemblee';

export type Convocazione = 'prima' | 'seconda';

export interface Costituzione {
    costituita: boolean;
    servono_teste: number;
    servono_valore: number;
    mancano_teste: number;
    mancano_valore: number;
    /** «11 su 26 · 484,60 su 1.000,00». */
    barra: string;
    /** «servono 9 e 333,34». */
    servono: string;
    /** «mancano 3 condòmini e 182,07 millesimi», o null. */
    mancano: string | null;
}

export interface Conflitto {
    determinante: boolean;
    esito: string | null;
    esito_senza: string | null;
    favorevoli_senza_teste: number;
    favorevoli_senza_valore: number;
    frase: string | null;
}

export interface Quinto {
    si_applica: boolean;
    oltre: boolean;
    oltre_teste: boolean;
    oltre_valore: boolean;
    limite_teste: number;
    limite_valore: number;
    frase: string | null;
}

/** Una condizione dell'esito: le teste o il valore, se passa, e la riga a parole. */
export interface CondizioneDelVoto {
    condizione: 'teste' | 'valore';
    passa: boolean;
    /** «Teste: 9 su 11, più della metà: sì», «Valore: 292,00 millesimi, ne servono 333,34: mancano 41,34». */
    frase: string;
}

/** Una riga dei conti senza soglia: come {@link CondizioneDelVoto}, ma non dice sì o no. */
export interface CondizioneSenzaSoglia {
    condizione: 'teste' | 'valore';
    passa: null;
    /** «Teste: 12 su 12», «Valore: 732,60 millesimi su 1.000,00». */
    frase: string;
}

/** Il quoziente per eccesso di due interi positivi. */
function perEccesso(a: number, b: number): number {
    return Math.floor((a + b - 1) / b);
}

/** Centesimi di millesimo come «1.000,00». */
function dueDecimali(centesimi: number): string {
    const segno = centesimi < 0 ? '-' : '';
    const assoluti = Math.abs(centesimi);
    const interi = Math.floor(assoluti / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return `${segno}${interi},${String(assoluti % 100).padStart(2, '0')}`;
}

/** I centomillesimi come si leggono, a due decimali arrotondati: 48.460.000 → «484,60». */
export function millesimi(centomillesimi: number): string {
    const centesimi = Math.floor((Math.abs(centomillesimi) + 500) / 1000) * (centomillesimi < 0 ? -1 : 1);

    return dueDecimali(centesimi);
}

/** I centomillesimi che servono, per eccesso: 33.333.334 → «333,34». */
export function millesimiPerEccesso(centomillesimi: number): string {
    return dueDecimali(perEccesso(Math.max(0, centomillesimi), 1000));
}

/** «1 condòmino», «3 condòmini» (rilievo C44: mai «1 condòmini»). */
export function condomini(n: number): string {
    return n === 1 ? '1 condòmino' : `${n} condòmini`;
}

/** «mancano 3 condòmini e 182,07 millesimi», «manca 1 condòmino» (anche con i millesimi: il verbo segue la prima cosa), «mancano 0,01 millesimi»; null quando non manca niente. */
export function mancano(teste: number, valore: number): string | null {
    const pezzi: string[] = [];
    if (teste > 0) pezzi.push(condomini(teste));
    if (valore > 0) pezzi.push(`${millesimiPerEccesso(valore)} millesimi`);
    if (pezzi.length === 0) return null;

    return `${teste === 1 ? 'manca' : 'mancano'} ${pezzi.join(' e ')}`;
}

/** La costituzione per convocazione. */
export function costituzione(convocazione: Convocazione | string, totaleTeste: number, totaleValore: number, teste: number, valore: number): Costituzione {
    const prima = convocazione === 'prima';
    const servonoTeste = prima ? Math.floor(totaleTeste / 2) + 1 : perEccesso(totaleTeste, 3);
    const servonoValore = prima ? perEccesso(2 * totaleValore, 3) : perEccesso(totaleValore, 3);
    const testeOk = prima ? 2 * teste > totaleTeste : 3 * teste >= totaleTeste;
    const valoreOk = prima ? 3 * valore >= 2 * totaleValore : 3 * valore >= totaleValore;
    const mancanoTeste = testeOk ? 0 : Math.max(0, servonoTeste - teste);
    const mancanoValore = valoreOk ? 0 : Math.max(0, servonoValore - valore);

    return {
        costituita: testeOk && valoreOk,
        servono_teste: servonoTeste,
        servono_valore: servonoValore,
        mancano_teste: mancanoTeste,
        mancano_valore: mancanoValore,
        barra: `${teste} su ${totaleTeste} · ${millesimi(valore)} su ${millesimi(totaleValore)}`,
        servono: `servono ${servonoTeste} e ${millesimiPerEccesso(servonoValore)}`,
        mancano: mancano(mancanoTeste, mancanoValore),
    };
}

/** L'esito proposto: `approvata`, `respinta`, `senza_voto`; null quando decide il presidente. */
export function esito(maggioranza: string | null, convocazione: Convocazione | string, totaleTeste: number, totaleValore: number, intervenuti: number,
    favorevoliTeste: number, favorevoliValore: number): string | null {
    if (maggioranza === 'senza_voto') return 'senza_voto';
    const degliIntervenuti = 2 * favorevoliTeste > intervenuti;
    let passa: boolean;
    switch (maggioranza) {
        case 'ordinaria':
            passa = degliIntervenuti && (convocazione === 'prima' ? 2 * favorevoliValore >= totaleValore : 3 * favorevoliValore >= totaleValore);
            break;
        case 'meta_del_valore':
            passa = degliIntervenuti && 2 * favorevoliValore >= totaleValore;
            break;
        case 'due_terzi':
            passa = degliIntervenuti && 3 * favorevoliValore >= 2 * totaleValore;
            break;
        case 'quattro_quinti':
            passa = 5 * favorevoliTeste >= 4 * totaleTeste && 5 * favorevoliValore >= 4 * totaleValore;
            break;
        case 'unanimita':
            passa = totaleTeste > 0 && favorevoliTeste >= totaleTeste && favorevoliValore >= totaleValore;
            break;
        default:
            return null;
    }

    return passa ? 'approvata' : 'respinta';
}

/**
 * L'esito per condizione, per la pagina del voto: le teste e il valore, una riga ciascuna, con la soglia e quanto manca. I segni sono quelli dell'esito
 * ({@link esito}, con le stesse moltiplicazioni intere); le soglie per eccesso, come la regola del verbale. Null quando decide il presidente (un'altra
 * maggioranza, o nessuna) e per il punto senza voto.
 */
export function condizioni(maggioranza: string | null, convocazione: Convocazione | string, totaleTeste: number, totaleValore: number, intervenuti: number,
    favorevoliTeste: number, favorevoliValore: number): CondizioneDelVoto[] | null {
    const f = favorevoliTeste;
    const v = favorevoliValore;
    let teste: { su: number; servono: number; passa: boolean; regola: string };
    let valore: { servono: number; passa: boolean };
    switch (maggioranza) {
        case 'ordinaria':
        case 'meta_del_valore':
        case 'due_terzi':
            teste = { su: intervenuti, servono: Math.floor(intervenuti / 2) + 1, passa: 2 * f > intervenuti, regola: 'più della metà' };
            valore = maggioranza === 'due_terzi' ? { servono: perEccesso(2 * totaleValore, 3), passa: 3 * v >= 2 * totaleValore }
                : maggioranza === 'ordinaria' && convocazione !== 'prima' ? { servono: perEccesso(totaleValore, 3), passa: 3 * v >= totaleValore }
                    : { servono: perEccesso(totaleValore, 2), passa: 2 * v >= totaleValore };
            break;
        case 'quattro_quinti':
            teste = { su: totaleTeste, servono: perEccesso(4 * totaleTeste, 5), passa: 5 * f >= 4 * totaleTeste, regola: 'quattro quinti dei condòmini' };
            valore = { servono: perEccesso(4 * totaleValore, 5), passa: 5 * v >= 4 * totaleValore };
            break;
        case 'unanimita':
            teste = { su: totaleTeste, servono: totaleTeste, passa: totaleTeste > 0 && f >= totaleTeste, regola: 'tutti' };
            valore = { servono: totaleValore, passa: v >= totaleValore };
            break;
        default:
            return null;
    }
    const mancanoTeste = Math.max(0, teste.servono - f);
    const no = mancanoTeste === 0 ? 'no' : `no, ${mancanoTeste === 1 ? 'manca 1' : `mancano ${mancanoTeste}`}`;

    return [
        { condizione: 'teste', passa: teste.passa, frase: `Teste: ${f} su ${teste.su}, ${teste.regola}: ${teste.passa ? 'sì' : no}` },
        { condizione: 'valore', passa: valore.passa, frase: `Valore: ${millesimi(v)} millesimi, ne servono ${millesimiPerEccesso(valore.servono)}: `
            + (valore.passa ? 'sì' : `mancano ${millesimiPerEccesso(valore.servono - v)}`) },
    ];
}

/**
 * I conti dei favorevoli quando la maggioranza non ha condizioni (un'altra maggioranza, o nessuna: decide il presidente): le teste sugli intervenuti e il
 * valore sul totale, come le righe delle altre maggioranze ma senza soglia, perché non c'è né un sì né un no da dire (B1, simulazione del 21/11). `passa` è
 * sempre null.
 */
export function contiSenzaSoglia(totaleValore: number, intervenuti: number, favorevoliTeste: number, favorevoliValore: number): CondizioneSenzaSoglia[] {
    return [
        { condizione: 'teste', passa: null, frase: `Teste: ${favorevoliTeste} su ${intervenuti}` },
        { condizione: 'valore', passa: null, frase: `Valore: ${millesimi(favorevoliValore)} millesimi su ${millesimi(totaleValore)}` },
    ];
}

/** Il conflitto d'interessi determinante: l'esito cambia togliendo dai favorevoli le teste e i millesimi segnati in conflitto. */
export function conflitto(maggioranza: string | null, convocazione: Convocazione | string, totaleTeste: number, totaleValore: number, intervenuti: number,
    favorevoliTeste: number, favorevoliValore: number, conflittoTeste: number, conflittoValore: number): Conflitto {
    const prima = esito(maggioranza, convocazione, totaleTeste, totaleValore, intervenuti, favorevoliTeste, favorevoliValore);
    if (conflittoTeste === 0) {
        return { determinante: false, esito: prima, esito_senza: prima, favorevoli_senza_teste: favorevoliTeste, favorevoli_senza_valore: favorevoliValore,
            frase: null };
    }
    const senzaTeste = Math.max(0, favorevoliTeste - conflittoTeste);
    const senzaValore = Math.max(0, favorevoliValore - conflittoValore);
    const senza = esito(maggioranza, convocazione, totaleTeste, totaleValore, intervenuti, senzaTeste, senzaValore);
    const determinante = prima !== null && senza !== prima;

    return {
        determinante,
        esito: prima,
        esito_senza: senza,
        favorevoli_senza_teste: senzaTeste,
        favorevoli_senza_valore: senzaValore,
        frase: `Senza ${conflittoTeste === 1 ? 'i suoi' : 'i loro'} ${millesimi(conflittoValore)} millesimi i favorevoli sarebbero ${millesimi(senzaValore)}: `
            + (determinante ? 'il voto in conflitto è determinante.' : 'l\'esito non cambia.'),
    };
}

/** Il quinto delle deleghe di un delegato, senza la sua quota: «3 condòmini su 5 · 125,70 su 200,00». */
export function quinto(totaleTeste: number, totaleValore: number, teste: number, valore: number): Quinto {
    const siApplica = totaleTeste > 20;
    const oltreTeste = siApplica && 5 * teste > totaleTeste;
    const oltreValore = siApplica && 5 * valore > totaleValore;
    const limiteTeste = Math.floor(totaleTeste / 5);
    const limiteValore = Math.floor(totaleValore / 5);

    return {
        si_applica: siApplica,
        oltre: oltreTeste || oltreValore,
        oltre_teste: oltreTeste,
        oltre_valore: oltreValore,
        limite_teste: limiteTeste,
        limite_valore: limiteValore,
        frase: siApplica ? `${condomini(teste)} su ${limiteTeste} · ${millesimi(valore)} su ${millesimi(limiteValore)}` : null,
    };
}

/** Una presenza come la manda il server (o come la segna la coda): chi, e se è uscito. */
export interface PresenzaPerIConti {
    chiave: string;
    anagrafica_id: number | null;
    uscita_il: string | null;
}

/**
 * Una delega come la manda il server: chi delega, il delegato (persona dell'anagrafica o fuori, con la chiave `delega:<id>`), lo stato, i punti (null: tutti)
 * e se è oltre il quinto ancora da decidere.
 */
export interface DelegaPerIConti {
    id: number;
    delegante_anagrafica_id: number | null;
    delegato_anagrafica_id: number | null;
    stato: string;
    punti?: number[] | null;
    da_decidere?: boolean;
}

/**
 * Quando una delega valida conta (la regola del server, `PresenzeDellaSala::delegaConta()`, sugli stessi casi di `conti.json`): la delega oltre il quinto
 * ancora «da decidere» mai; quella per alcuni punti nella costituzione sì (chi ha delegato è rappresentato in assemblea; il conduttore resta fuori comunque,
 * perché non è fra le teste della costituzione), sul voto solo per i suoi punti.
 */
export function delegaConta(punti: number[] | null | undefined, daDecidere: boolean | undefined, puntoId: number | null | undefined): boolean {
    if (daDecidere) return false;
    if (!punti || punti.length === 0 || puntoId == null) return true;

    return punti.includes(puntoId);
}

/**
 * Le persone che contano presenti adesso: chi è in sala o in collegamento e non è uscito, più chi ha delegato con una delega valida un delegato presente
 * (il delegato dell'anagrafica con la sua chiave `anagrafica:<id>`, quello fuori con `delega:<id>`). Il delegante presente conta da sé. Con `puntoId`, per il
 * voto di quel punto: la delega per alcuni punti vale solo per i suoi ({@link delegaConta}).
 */
export function personePresenti(presenze: PresenzaPerIConti[], deleghe: DelegaPerIConti[], puntoId: number | null = null): Set<number> {
    const dentro = new Set(presenze.filter((p) => p.uscita_il === null).map((p) => p.chiave));
    const persone = new Set<number>();
    for (const p of presenze) {
        if (p.uscita_il === null && p.anagrafica_id !== null) persone.add(p.anagrafica_id);
    }
    for (const d of deleghe) {
        if (d.stato !== 'valida' || d.delegante_anagrafica_id === null || !delegaConta(d.punti, d.da_decidere, puntoId)) continue;
        const chiave = d.delegato_anagrafica_id !== null ? `anagrafica:${d.delegato_anagrafica_id}` : `delega:${d.id}`;
        if (dentro.has(chiave)) persone.add(d.delegante_anagrafica_id);
    }

    return persone;
}

/**
 * Chi è in sala senza voto (rilievo C53): gli altri contitolari entrati con chi rappresenta la loro unità (`insieme` della riga aperta, art. 67 c. 2 disp.
 * att.). Non sono fra le persone presenti di {@link personePresenti}: la testa la porta chi la rappresenta, e la sala conta una testa sola.
 */
export function presentiSenzaVoto(presenze: (Pick<PresenzaPerIConti, 'uscita_il'> & { insieme?: { anagrafica_id: number }[] | null })[]): Set<number> {
    const persone = new Set<number>();
    for (const p of presenze) {
        if (p.uscita_il === null) for (const x of p.insieme ?? []) persone.add(x.anagrafica_id);
    }

    return persone;
}

/** Le teste presenti e il loro valore: una testa è presente quando c'è almeno una delle sue persone. */
export function testePresenti(teste: Pick<TestaDellaFotografia, 'persone' | 'millesimi'>[], presenti: Set<number>): ConteggioDellaSala {
    let quante = 0;
    let valore = 0;
    for (const t of teste) {
        if (t.persone.some((p) => presenti.has(p))) {
            quante += 1;
            valore += t.millesimi;
        }
    }

    return { teste: quante, valore };
}

/** La barra della costituzione dalla fotografia e da chi è presente. */
export function barra(fotografia: Pick<FotografiaDellaSala, 'convocazione' | 'costituzione'>, presenti: Set<number>): Costituzione {
    const c = testePresenti(fotografia.costituzione.teste, presenti);

    return costituzione(fotografia.convocazione, fotografia.costituzione.totale_teste, fotografia.costituzione.totale_valore, c.teste, c.valore);
}
