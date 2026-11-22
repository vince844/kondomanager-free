// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I conti piccoli delle pagine della sala, sul posto.
 *
 * - **L'ora di Roma**: le ore della sala si leggono a Roma («19:05»), gli istanti viaggiano in UTC; dal foglio della sala l'ora scritta a mano diventa
 *   l'istante del gesto, anche nei giorni del cambio dell'ora.
 * - **Sulla sala vista dal dispositivo** (lo stato del server più i gesti in coda, `resources/js/lib/senzaRete/sala.ts`): la barra contata con `conti.ts`,
 *   i conteggi e l'esito proposto del voto. Quando i gesti arrivano vale il conto del server.
 * - **La versione da cui parte la conferma** (`base`): quella del server più i gesti in coda sulla stessa votazione, così la conferma segnata dopo i voti
 *   senza rete non è in conflitto con loro; **la votazione nei gesti**, anche per una votazione aperta senza rete.
 * - **Il quinto del delegato** mentre si segna la delega: le stesse regole del server (`DelegheDellAssemblea::quintoDi()`).
 */
import { barra, condizioni, contiSenzaSoglia, esito as esitoProposto, personePresenti, quinto, type CondizioneDelVoto, type CondizioneSenzaSoglia, type Costituzione, type Quinto } from '@/lib/assemblee/sala/conti';
import type {
    DelegaDellaSala, FotografiaDellaSala, GestoDellaSala, LaVotazioneDelGesto, PresenzaDellaSala, SceltaDiVoto, VotazioneDellaSala, VotoDellaSala,
} from '@/types/assemblee';

export const FUSO = 'Europe/Rome';

const due = (n: number) => String(n).padStart(2, '0');

/** I numeri dell'ora di Roma di un istante. */
function partiDiRoma(istante: Date): { anno: number; mese: number; giorno: number; ora: number; minuto: number } {
    const parti = new Intl.DateTimeFormat('en-GB', { timeZone: FUSO, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
        hourCycle: 'h23' }).formatToParts(istante);
    const di = (t: string) => Number(parti.find((p) => p.type === t)?.value ?? 0);

    return { anno: di('year'), mese: di('month'), giorno: di('day'), ora: di('hour') % 24, minuto: di('minute') };
}

/** «19:05» da un istante ISO; vuoto senza. */
export function oraDiRoma(iso: string | null | undefined): string {
    if (!iso) return '';
    const p = partiDiRoma(new Date(iso));

    return `${due(p.ora)}:${due(p.minuto)}`;
}

/** Il giorno di Roma di un istante, `aaaa-mm-gg`. */
export function giornoDiRoma(istante: Date = new Date()): string {
    const p = partiDiRoma(istante);

    return `${p.anno}-${due(p.mese)}-${due(p.giorno)}`;
}

/** L'istante ISO in UTC di un giorno e di un'ora scritti a Roma («2026-12-15», «19:05» → «2026-12-15T18:05:00.000Z»). */
export function istanteDiRoma(giorno: string, ora: string): string {
    const [anno, mese, g] = giorno.slice(0, 10).split('-').map(Number);
    const [h, m] = ora.split(':').map(Number);
    const voluto = Date.UTC(anno, mese - 1, g, h, m);
    let istante = voluto;
    for (let i = 0; i < 2; i++) {
        const p = partiDiRoma(new Date(istante));
        istante += voluto - Date.UTC(p.anno, p.mese - 1, p.giorno, p.ora, p.minuto);
    }

    return new Date(istante).toISOString();
}

const GIORNI = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];

/** «giovedì 17/12/2026» da «2026-12-17». */
export function giornoDellaSeduta(giorno: string): string {
    const [anno, mese, g] = giorno.slice(0, 10).split('-').map(Number);

    return `${GIORNI[new Date(Date.UTC(anno, mese - 1, g)).getUTCDay()]} ${due(g)}/${due(mese)}/${anno}`;
}

export type PresenzaConLaCoda = PresenzaDellaSala & { inCoda: boolean };

/**
 * La barra della costituzione sul posto: le presenze (anche quelle in coda) e le deleghe che contano, contate da `conti.ts` (`personePresenti`, la regola del
 * server: la delega per alcuni punti porta presente chi ha delegato, quella «da decidere» no).
 */
export function barraSulPosto(fotografia: Pick<FotografiaDellaSala, 'convocazione' | 'costituzione'>, presenze: PresenzaDellaSala[],
    deleghe: DelegaDellaSala[]): Costituzione {
    return barra(fotografia, personePresenti(presenze, deleghe));
}

export type VotoConLaCoda = VotoDellaSala & { inCoda: boolean };

export interface GruppoSulPosto {
    teste: number;
    valore: number;
    nomi: { nome: string; millesimi: number }[];
}

export interface ConteggiSulPosto {
    favorevoli: GruppoSulPosto;
    contrari: GruppoSulPosto;
    astenuti: GruppoSulPosto;
    non_votato: GruppoSulPosto;
    /** L'esito proposto, contato come il server; null quando decide il presidente. */
    esito: string | null;
    /** L'esito per condizione, le teste e il valore, scritto come il server; null quando decide il presidente e per il punto senza voto. */
    condizioni: CondizioneDelVoto[] | null;
    /** Quando la maggioranza non ha condizioni (decide il presidente): le teste e il valore dei favorevoli come gli altri punti, senza soglia. */
    conti: CondizioneSenzaSoglia[];
}

/** I conteggi del voto sul posto: il discorde conta astenuto (di serie) o come lo ha deciso il presidente; chi non ha votato è contato a parte. */
export function conteggiSulPosto(v: VotazioneDellaSala, voti: VotoDellaSala[], discordeAstenuto: boolean): ConteggiSulPosto {
    const vuoto = (): GruppoSulPosto => ({ teste: 0, valore: 0, nomi: [] });
    const c = { favorevoli: vuoto(), contrari: vuoto(), astenuti: vuoto(), non_votato: vuoto() };
    const votate = new Set<string>();
    for (const x of voti) {
        votate.add(x.testa);
        const conta: SceltaDiVoto = x.voto === 'discorde' ? (discordeAstenuto || x.decisione === null ? 'astenuto' : x.decisione) : x.voto;
        const g = conta === 'favorevole' ? c.favorevoli : conta === 'contrario' ? c.contrari : c.astenuti;
        g.teste += 1;
        g.valore += x.millesimi;
        g.nomi.push({ nome: x.nome, millesimi: x.millesimi });
    }
    for (const t of v.intervenuti.teste) {
        if (votate.has(t.chiave)) continue;
        c.non_votato.teste += 1;
        c.non_votato.valore += t.millesimi;
        c.non_votato.nomi.push({ nome: t.nome, millesimi: t.millesimi });
    }
    const i = v.intervenuti;

    return { ...c, esito: esitoProposto(v.maggioranza, i.convocazione, i.totale_teste, i.totale_valore, i.numero, c.favorevoli.teste, c.favorevoli.valore),
        condizioni: condizioni(v.maggioranza, i.convocazione, i.totale_teste, i.totale_valore, i.numero, c.favorevoli.teste, c.favorevoli.valore),
        conti: contiSenzaSoglia(i.totale_valore, i.numero, c.favorevoli.teste, c.favorevoli.valore) };
}

/** Un gesto del voto riguarda questa votazione: con il suo numero, o (segnato senza rete) con la seduta, il punto e il numero della votazione. */
export function eDellaVotazione(dati: Record<string, unknown>, v: Pick<VotazioneDellaSala, 'id' | 'seduta_id' | 'punto_id' | 'numero'>): boolean {
    if (dati.votazione_id !== undefined && dati.votazione_id !== null) return dati.votazione_id === v.id;

    return Number(dati.seduta_id) === v.seduta_id && Number(dati.punto_id) === v.punto_id && Number(dati.numero ?? 1) === v.numero;
}

/**
 * La versione della votazione da cui parte la conferma: quella del server più i gesti in coda che la cambiano (i voti, «tutti favorevoli», il conflitto, la
 * conferma; la nota no).
 */
export function baseDellaVotazione(v: VotazioneDellaSala, inCoda: GestoDellaSala[]): number {
    return v.versione + inCoda.filter((g) => ['tutti_favorevoli', 'voto', 'conflitto', 'conferma'].includes(g.tipo)
        && eDellaVotazione(g.dati as Record<string, unknown>, v)).length;
}

/**
 * La votazione nei gesti del voto: il numero del server; per una votazione aperta senza rete (numero provvisorio, negativo) la seduta, il punto, il numero
 * della votazione e il gesto «Si vota» che l'ha aperta, che il server usa al posto del numero.
 */
export function laVotazioneDelGesto(v: VotazioneDellaSala & { riferimento?: { $gesto: string } }): LaVotazioneDelGesto {
    if (v.id > 0) return { votazione_id: v.id };

    return { seduta_id: v.seduta_id, punto_id: v.punto_id, numero: v.numero, ...(v.riferimento ? { aperta_dal_gesto: v.riferimento.$gesto } : {}) };
}

const normale = (testo: string) => testo.replace(/\s+/g, ' ').trim().toLowerCase();
const chiDelDelegato = (id: number | null | undefined, nome: string | null | undefined) => (id ? `anagrafica:${id}` : `nome:${normale(nome ?? '')}`);

/**
 * Il quinto del delegato con questa delega: le altre deleghe valide allo stesso delegato (senza quella di prima di chi delega, che questa sostituisce) più
 * questa; la quota del delegato non conta. Le regole del server.
 */
export function quintoDelDelegato(fotografia: Pick<FotografiaDellaSala, 'costituzione'>, deleghe: DelegaDellaSala[], deleganteId: number,
    delegato: { anagrafica_id?: number | null; nome?: string | null }): Quinto {
    const chi = chiDelDelegato(delegato.anagrafica_id, delegato.nome);
    const deleganti = new Set<number>([deleganteId]);
    for (const d of deleghe) {
        if (d.stato === 'valida' && d.delegante_anagrafica_id !== null && d.delegante_anagrafica_id !== deleganteId
            && chiDelDelegato(d.delegato_anagrafica_id, d.delegato_nome) === chi) deleganti.add(d.delegante_anagrafica_id);
    }
    let teste = 0;
    let valore = 0;
    for (const t of fotografia.costituzione.teste) {
        if (delegato.anagrafica_id && t.persone.includes(delegato.anagrafica_id)) continue;
        if (t.persone.some((p) => deleganti.has(p))) {
            teste += 1;
            valore += t.millesimi;
        }
    }

    return quinto(fotografia.costituzione.totale_teste, fotografia.costituzione.totale_valore, teste, valore);
}

/** «Rossi», «Rossi e Neri», «Rossi, Neri e Russo». */
export function elencoDeiNomi(nomi: string[]): string {
    return nomi.length <= 1 ? (nomi[0] ?? '') : `${nomi.slice(0, -1).join(', ')} e ${nomi[nomi.length - 1]}`;
}

/** Una voce di un menu della sala: l'id (una persona) e come si legge. */
export interface Opzione {
    id: number;
    etichetta: string;
}

/** I ruoli di chi vota, come si leggono sulla riga. */
export const RUOLI: Record<string, string> = {
    proprietario: 'proprietario', usufruttuario: 'usufruttuario', nudo_proprietario: 'nudo proprietario', conduttore: 'conduttore',
    rappresentante: 'rappresentante', delegato: 'per delega',
};

/** I tre perché dell'art. 67 c. 7 disp. att. per far votare l'usufruttuario su un punto straordinario. */
export const PERCHE_DELL_USUFRUTTUARIO = [
    { valore: 'art_1006', etichetta: 'Si avvale dell\'art. 1006 c.c.', nota: 'lo dichiara l\'usufruttuario in assemblea' },
    { valore: 'lavori_985_986', etichetta: 'Lavori od opere degli artt. 985 e 986 c.c.', nota: 'i lavori che la legge lascia all\'usufruttuario' },
    { valore: 'semplice_godimento', etichetta: 'Un affare di semplice godimento delle cose comuni',
        nota: 'anche un regolamento che riguarda solo l\'uso (art. 67 c. 6)' },
] as const;

/** Le parole degli esiti da confermare. */
export const ESITI_DA_CONFERMARE = [
    { valore: 'approvata', etichetta: 'Approvata' },
    { valore: 'approvata_con_modifiche', etichetta: 'Approvata con modifiche' },
    { valore: 'respinta', etichetta: 'Respinta' },
    { valore: 'rinviata', etichetta: 'Rinviata' },
] as const;

/** «Approvata», «Respinta»… dall'esito. */
export function parolaDellEsito(esito: string | null | undefined): string {
    if (esito === 'senza_voto') return 'Trattato';
    return ESITI_DA_CONFERMARE.find((e) => e.valore === esito)?.etichetta ?? '';
}
