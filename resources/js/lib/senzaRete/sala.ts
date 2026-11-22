// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

import { barra, esito as esitoProposto, personePresenti } from '@/lib/assemblee/sala/conti';
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import type {
    ConteggiDelVoto,
    DelegaDellaSala,
    GruppoDiVoti,
    PresenzaDellaSala,
    PuntoDellaSala,
    SalaDellAssemblea,
    SceltaDiVoto,
    TestaIntervenuta,
    VotazioneDellaSala,
} from '@/types/assemblee';
import { eUnRiferimento, type EsitoDiUnGesto, type Riferimento, type Ritrovati } from './coda';
import type { VoceInCoda } from './deposito';

/**
 * La sala vista dal dispositivo: **lo stato del server più i gesti in coda**, contati sul posto con `conti.ts` (decisione 514 (5): senza rete la barra conta
 * lo stesso). Quando i gesti arrivano il server rifà i conti da sé, e vale il suo.
 *
 * - {@link proiettaLaSala}: applica i gesti in coda allo stato del server, nell'ordine: entrate e uscite (con le loro versioni), deleghe, votazioni aperte senza
 *   rete (con un numero provvisorio e il {@link Riferimento}; i gesti del voto di una votazione aperta senza rete la dicono con la seduta, il punto e il
 *   numero), voti, conflitti, note del presidente, conferme, dichiarazioni (anche corrette e tolte), la discussione di un punto (il testo del verbale scritto in sala), apertura,
 *   «Segna deserta» (la prima non aperta porta «da aprire» sulla seconda; dopo l'appello, anche della seconda), sospensione e chiusura (le presenze si
 *   chiudono all'ora del gesto); poi la barra e i conteggi dei voti. Ogni riga toccata ha la sua chiave in `in_coda`: la pagina ci mette «Su questo
 *   computer».
 * - **Il calendario**: «da aprire» si ricalcola dal giorno della seduta e dall'ora di adesso (lo stato tenuto può essere del giorno prima), e senza un
 *   controllo dell'apertura letto oggi la sala dice che lo rifà il server (`controllo_da_rifare`).
 * - {@link fraseDeiRitrovati}: «Ho ritrovato 4 gesti che non erano partiti: 3 sono arrivati, 1 ha due versioni.», con le ore e che cosa erano.
 * - {@link cercaNelloStato}: il numero di una votazione o di una delega che il server non ridice (un gesto arrivato «doppio»), cercato nello stato della sala.
 */

/** Un oggetto nato senza rete: ha un numero provvisorio (negativo) e il riferimento al gesto che lo ha fatto nascere. */
export interface NatoSenzaRete {
    riferimento?: Riferimento;
}

export type SalaConLaCoda = SalaDellAssemblea & {
    /** Le righe con un gesto non ancora arrivato al server: `presenza:anagrafica:7`, `voto:12:p-7`, `votazione:-1`, `dichiarazione:-2`, `delega:-3`, `seduta:4`. */
    in_coda: string[];
};

/** Il numero da mettere in un gesto: quello vero, o il riferimento per ciò che è nato senza rete. */
export function idPerIlGesto(oggetto: { id: number } & NatoSenzaRete): number | Riferimento {
    return oggetto.riferimento ?? oggetto.id;
}

const copia = <T>(x: T): T => JSON.parse(JSON.stringify(x)) as T;

const gruppo = (): GruppoDiVoti => ({ teste: 0, valore: 0, nomi: [] });

/** I voti di una votazione contati sul posto: favorevoli, contrari, astenuti e chi non ha votato, con i nomi e i millesimi. */
export function contaIVoti(v: VotazioneDellaSala): ConteggiDelVoto {
    const conteggi: ConteggiDelVoto = { favorevoli: gruppo(), contrari: gruppo(), astenuti: gruppo(), non_votato: gruppo(), conflitto: null, frase: '' };
    const votate = new Set<string>();
    for (const voto of v.voti) {
        const scelta: SceltaDiVoto = voto.voto === 'discorde' ? (voto.decisione ?? 'astenuto') : voto.voto;
        const g = scelta === 'favorevole' ? conteggi.favorevoli : scelta === 'contrario' ? conteggi.contrari : conteggi.astenuti;
        g.teste += 1;
        g.valore += voto.millesimi;
        g.nomi.push({ nome: voto.nome, millesimi: voto.millesimi });
        votate.add(voto.testa);
    }
    for (const t of v.intervenuti.teste) {
        if (votate.has(t.chiave)) continue;
        conteggi.non_votato.teste += 1;
        conteggi.non_votato.valore += t.millesimi;
        conteggi.non_votato.nomi.push({ nome: t.chi?.nome ?? t.nome, millesimi: t.millesimi });
    }

    return conteggi;
}

/** «La sala si apre il giorno della seduta, il 15/12/2026.» */
const siApreIl = (giorno: string) => `La sala si apre il giorno della seduta, il ${giorno.slice(8, 10)}/${giorno.slice(5, 7)}/${giorno.slice(0, 4)}.`;

/** Un gesto arrivato e applicato dal server, con la sua risposta (i numeri e le versioni). */
export interface GestoArrivato {
    voce: VoceInCoda;
    risposta?: Record<string, unknown>;
}

/**
 * Lo stato del server più i gesti in coda; `adesso` è l'ora corretta del dispositivo (l'orologio della coda). `numeri`: ciò che il server ha già risposto ai
 * gesti (per gesto, `{votazione_id: 99}`), per un riferimento a un gesto arrivato che lo stato non ha più fra i gesti da contare (il voto ancora in coda sulla
 * votazione aperta senza rete, quando lo stato porta la votazione con il suo numero vero). `arrivati`: i gesti già applicati dal server che lo stato non ha
 * ancora (la rilettura non è tornata): si contano prima di quelli in coda, senza la chiave «su questo computer», con i numeri e le versioni che il server ha
 * risposto (la votazione 99, la versione 4 della votazione): il gesto dopo parte dalla versione che il server ha, non da quella dello stato tenuto.
 */
export function proiettaLaSala(sala: SalaDellAssemblea, voci: VoceInCoda[], adesso: Date = new Date(), numeri: Record<string, Record<string, unknown>> = {},
    arrivati: GestoArrivato[] = []): SalaConLaCoda {
    const s = copia(sala) as SalaConLaCoda;
    const oggi = giornoDiRoma(adesso);
    /** La seduta da aprire dopo questa: la seconda dopo la prima, la prosecuzione dopo la seduta sospesa; null se non ce n'è. */
    const daAprireDopo = (seduta: { id: number; convocazione: string; numero: number }, prosegue?: number) => {
        const prossima = prosegue !== undefined ? s.sedute.find((x) => x.id === prosegue)
            : seduta.convocazione === 'prima' ? s.sedute.find((x) => x.convocazione === 'seconda' && x.numero === 1) : undefined;
        if (!prossima || prossima.aperta_il !== null || prossima.esito !== null) {
            s.da_aprire = null;
            return;
        }
        s.da_aprire = { seduta_id: prossima.id, convocazione: prossima.convocazione, numero: prossima.numero, si_apre: true, perche: null };
        // Il controllo dell'apertura vale per la seduta per cui è stato letto: per la prossima lo rifà il server.
        s.controllo = null;
        s.controllo_da_rifare = true;
        if (s.fotografia && prossima.numero === 1) s.fotografia.convocazione = prossima.convocazione;
    };
    /** Chi è ancora dentro esce all'ora del gesto (la seduta deserta, sospesa o chiusa). */
    const esconoTutti = (alle: string) => {
        for (const p of s.presenze) if (p.uscita_il === null) p.uscita_il = alle;
    };
    const inCoda = new Set<string>();
    const provvisori = new Map<string, number>();
    let prossimo = -1;
    const toccate = new Set<VotazioneDellaSala>();
    /** La risposta di un gesto arrivato (vuota per un gesto in coda). */
    let risposta: Record<string, unknown> = {};
    const numeroDato = (campo: string): number | undefined => (typeof risposta[campo] === 'number' ? (risposta[campo] as number) : undefined);
    const risposto = (r: Riferimento): number | undefined => (typeof numeri[r.$gesto]?.[r.campo] === 'number' ? (numeri[r.$gesto][r.campo] as number) : undefined);
    const id = (x: unknown): number | undefined => (eUnRiferimento(x) ? (provvisori.get(x.$gesto) ?? risposto(x)) : typeof x === 'number' ? x : undefined);
    const nomeDi = (anagrafica: number | null | undefined): string =>
        anagrafica == null ? '' : (s.fotografia?.persone?.[String(anagrafica)]?.nome ?? '');
    const votazioni = (): VotazioneDellaSala[] => s.punti.flatMap((p) => p.votazioni);
    /** La votazione di un gesto del voto: dal suo numero, o (votata senza rete) dalla seduta, dal punto e dal numero della votazione. */
    const laVotazione = (d: Record<string, any>) => (d.votazione_id !== undefined && d.votazione_id !== null
        ? votazioni().find((v) => v.id === id(d.votazione_id))
        : votazioni().find((v) => v.seduta_id === Number(d.seduta_id) && v.punto_id === Number(d.punto_id) && v.numero === Number(d.numero ?? 1)));
    const ilPunto = (votazione: VotazioneDellaSala): PuntoDellaSala | undefined => s.punti.find((p) => p.id === votazione.punto_id);
    const chiaveDi = (persona: Record<string, unknown> | undefined): { chiave: string; anagrafica_id: number | null; nome: string } => {
        if (persona && 'anagrafica_id' in persona) {
            const a = Number(persona.anagrafica_id);
            return { chiave: `anagrafica:${a}`, anagrafica_id: a, nome: nomeDi(a) };
        }
        const delega = s.deleghe.find((d) => d.id === id(persona?.delega_id));

        return { chiave: delega?.chiave_delegato ?? `delega:${id(persona?.delega_id)}`, anagrafica_id: delega?.delegato_anagrafica_id ?? null,
            nome: delega?.delegato_nome ?? '' };
    };

    const applica = (v: VoceInCoda) => {
        const d = v.dati as Record<string, any>;
        switch (v.tipo) {
            case 'entra': {
                const chi = chiaveDi(d.persona);
                inCoda.add(`presenza:${chi.chiave}`);
                // Già dentro: il server risponderà «Era già così».
                if (s.presenze.some((p) => p.chiave === chi.chiave && p.uscita_il === null)) break;
                // Gli altri contitolari entrati con chi rappresenta l'unità (rilievo C53): nella riga, presenti senza voto; la barra conta la testa una volta.
                const insieme = Array.isArray(d.con) && d.con.length > 0 ? (d.con as unknown[]).map((x) => ({ anagrafica_id: Number(x), nome: nomeDi(Number(x)) })) : null;
                s.presenze.push({ id: prossimo--, chiave: chi.chiave, anagrafica_id: chi.anagrafica_id, nome: chi.nome, come: d.come ?? 'in_sala', entrata_il: v.segnato_il,
                    uscita_il: null, caduto: false, rappresenta: d.rappresenta ?? null, motivo_collegamento: d.motivo ?? null, insieme } as PresenzaDellaSala);
                s.versioni[chi.chiave] = (s.versioni[chi.chiave] ?? 0) + 1;
                inCoda.add(`presenza:${chi.chiave}`);
                break;
            }
            case 'esce': {
                const chi = chiaveDi(d.persona);
                const dentro = [...s.presenze].reverse().find((p) => p.chiave === chi.chiave && p.uscita_il === null);
                if (dentro) {
                    dentro.uscita_il = v.segnato_il;
                    dentro.caduto = Boolean(d.caduto);
                }
                s.versioni[chi.chiave] = (s.versioni[chi.chiave] ?? 0) + 1;
                inCoda.add(`presenza:${chi.chiave}`);
                break;
            }
            case 'delega': {
                const nuovo = numeroDato('delega_id') ?? prossimo--;
                provvisori.set(v.gesto, nuovo);
                const delegato = d.delegato ?? {};
                const delegatoId = delegato.anagrafica_id != null ? Number(delegato.anagrafica_id) : null;
                s.deleghe.push({ id: nuovo, delegante_anagrafica_id: Number(d.delegante_anagrafica_id), delegante_nome: nomeDi(d.delegante_anagrafica_id),
                    delegato_anagrafica_id: delegatoId, delegato_nome: delegatoId !== null ? nomeDi(delegatoId) : String(delegato.nome ?? ''),
                    chiave_delegato: delegatoId !== null ? `anagrafica:${delegatoId}` : `delega:${nuovo}`, unita: [], punti: d.punti ?? null, arrivata_come: 'in_sala',
                    arrivata_parola: 'In sala', ricevuta_il: v.segnato_il.slice(0, 10), stato: 'valida', motivo: d.motivo ?? null, quinto: null, da_decidere: false,
                    documento_id: null, ...(nuovo < 0 ? { riferimento: { $gesto: v.gesto, campo: 'delega_id' } } : {}) } as DelegaDellaSala & NatoSenzaRete);
                inCoda.add(`delega:${nuovo}`);
                break;
            }
            case 'delega_revoca':
            case 'delega_decidi': {
                const delega = s.deleghe.find((x) => x.id === id(d.delega_id));
                if (delega) {
                    delega.stato = v.tipo === 'delega_revoca' ? 'revocata' : d.accetta ? 'valida' : 'rifiutata';
                    delega.da_decidere = false;
                    inCoda.add(`delega:${delega.id}`);
                }
                break;
            }
            case 'si_vota': {
                const punto = s.punti.find((p) => p.id === Number(d.punto_id));
                const voce = s.fotografia?.punti.find((p) => p.punto_id === Number(d.punto_id));
                if (!punto || !voce) break;
                const nuovo = numeroDato('votazione_id') ?? prossimo--;
                provvisori.set(v.gesto, nuovo);
                const presenti = personePresenti(s.presenze, s.deleghe, punto.id);
                const teste: TestaIntervenuta[] = voce.teste.filter((t) => t.persone.some((p) => presenti.has(p))).map((t) => {
                    const chi = t.persone.find((p) => presenti.has(p))!;
                    return { ...t, chi: { anagrafica_id: chi, nome: nomeDi(chi) || t.nome, ruolo: voce.ruoli?.[String(t.unita[0])] ?? 'proprietario' } };
                });
                const votazione = { id: nuovo, seduta_id: Number(d.seduta_id), punto_id: punto.id, numero: Number(d.numero ?? 1), oggetto: d.oggetto ?? (Number(d.numero) === 2 ? 'riparto' : null),
                    maggioranza: punto.maggioranza, votata_il: v.segnato_il, versione: numeroDato('versione') ?? 0,
                    intervenuti: { teste, numero: teste.length, valore: teste.reduce((x, t) => x + t.millesimi, 0), totale_teste: voce.totale_teste,
                        totale_valore: voce.totale_valore, convocazione: s.fotografia!.convocazione, perimetro: null },
                    scelte: null, conteggi: null, esito_proposto: null, esito: null, motivo: null, confermata_il: null, conflitto_determinante: false, delibera_id: null, voti: [],
                    ...(nuovo < 0 ? { riferimento: { $gesto: v.gesto, campo: 'votazione_id' } } : {}) } as VotazioneDellaSala & NatoSenzaRete;
                punto.votazioni.push(votazione);
                toccate.add(votazione);
                inCoda.add(`votazione:${nuovo}`);
                break;
            }
            case 'tutti_favorevoli': {
                const votazione = laVotazione(d);
                if (!votazione) break;
                for (const t of votazione.intervenuti.teste) {
                    if (votazione.voti.some((x) => x.testa === t.chiave)) continue;
                    votazione.voti.push({ testa: t.chiave, anagrafica_id: t.chi?.anagrafica_id ?? null, nome: t.chi?.nome ?? t.nome, ruolo: t.chi?.ruolo ?? 'proprietario',
                        millesimi: t.millesimi, voto: 'favorevole', decisione: null, in_conflitto: false, nota_conflitto: null, versione: 1 });
                    inCoda.add(`voto:${votazione.id}:${t.chiave}`);
                }
                toccate.add(votazione);
                break;
            }
            case 'voto': {
                const votazione = laVotazione(d);
                if (!votazione) break;
                const gia = votazione.voti.find((x) => x.testa === d.testa);
                if (gia) {
                    gia.voto = d.voto;
                    gia.decisione = d.decisione ?? null;
                    gia.versione += 1;
                } else {
                    const t = votazione.intervenuti.teste.find((x) => x.chiave === d.testa);
                    votazione.voti.push({ testa: String(d.testa), anagrafica_id: t?.chi?.anagrafica_id ?? null, nome: t?.chi?.nome ?? t?.nome ?? '', ruolo: t?.chi?.ruolo ?? 'proprietario',
                        millesimi: t?.millesimi ?? 0, voto: d.voto, decisione: d.decisione ?? null, in_conflitto: false, nota_conflitto: null, versione: 1 });
                }
                if (votazione.confermata_il !== null) votazione.esito = null;
                toccate.add(votazione);
                inCoda.add(`voto:${votazione.id}:${d.testa}`);
                break;
            }
            case 'conflitto': {
                const votazione = laVotazione(d);
                const voto = votazione?.voti.find((x) => x.testa === d.testa);
                if (votazione && voto) {
                    voto.in_conflitto = Boolean(d.in_conflitto);
                    voto.nota_conflitto = d.nota ?? null;
                    toccate.add(votazione);
                    inCoda.add(`voto:${votazione.id}:${d.testa}`);
                }
                break;
            }
            case 'conferma': {
                const votazione = laVotazione(d);
                if (!votazione) break;
                votazione.esito = d.esito;
                votazione.motivo = d.motivo ?? null;
                votazione.confermata_il = v.segnato_il;
                const punto = ilPunto(votazione);
                if (punto) punto.esito = d.esito;
                inCoda.add(`votazione:${votazione.id}`);
                break;
            }
            case 'nota': {
                const votazione = laVotazione(d);
                if (!votazione) break;
                const testo = String(d.testo ?? '').trim();
                if (d.su === 'non_votato') votazione.nota_non_votato = testo || null;
                else votazione.nota = testo || null;
                inCoda.add(`votazione:${votazione.id}`);
                break;
            }
            case 'trattato': {
                const punto = s.punti.find((p) => p.id === Number(d.punto_id));
                if (punto) punto.esito = 'senza_voto';
                inCoda.add(`punto:${d.punto_id}`);
                break;
            }
            case 'dichiarazione': {
                const nuovo = numeroDato('dichiarazione_id') ?? prossimo--;
                provvisori.set(v.gesto, nuovo);
                s.dichiarazioni.push({ id: nuovo, seduta_id: Number(d.seduta_id), punto_id: d.punto_id != null ? Number(d.punto_id) : null,
                    anagrafica_id: d.anagrafica_id != null ? Number(d.anagrafica_id) : null, nome: d.nome ?? nomeDi(d.anagrafica_id), testo: String(d.testo ?? ''),
                    ...(nuovo < 0 ? { riferimento: { $gesto: v.gesto, campo: 'dichiarazione_id' } } : {}) } as SalaDellAssemblea['dichiarazioni'][number] & NatoSenzaRete);
                inCoda.add(`dichiarazione:${nuovo}`);
                break;
            }
            // La dichiarazione corretta o tolta (rilievo C53): com'è su questo computer, finché il server non risponde.
            case 'dichiarazione_cambia':
            case 'dichiarazione_togli': {
                const quale = id(d.dichiarazione_id);
                const dichiarazione = s.dichiarazioni.find((x) => x.id === quale);
                if (!dichiarazione) break;
                if (v.tipo === 'dichiarazione_togli') s.dichiarazioni = s.dichiarazioni.filter((x) => x !== dichiarazione);
                else dichiarazione.testo = String(d.testo ?? '');
                inCoda.add(`dichiarazione:${dichiarazione.id}`);
                break;
            }
            case 'deserta':
            case 'apri':
            case 'apertura_decidi':
            case 'sospendi':
            case 'chiudi': {
                const seduta = s.sedute.find((x) => x.id === Number(d.seduta_id));
                if (!seduta) break;
                if (v.tipo === 'deserta') {
                    seduta.esito = 'deserta';
                    // Dopo l'appello: chi è in sala esce all'ora del gesto, e l'appello resta per il verbale.
                    if (s.seduta_id === seduta.id) {
                        esconoTutti(v.segnato_il);
                        seduta.chiusa_il = v.segnato_il;
                        s.seduta_id = null;
                    }
                    if (seduta.convocazione === 'seconda') {
                        // Deserte la prima e la seconda: l'assemblea è deserta, e non c'è più niente da aprire.
                        s.assemblea.stato = 'deserta';
                        s.da_aprire = null;
                        s.controllo = null;
                    } else {
                        s.assemblea.stato = 'convocata';
                        daAprireDopo(seduta);
                    }
                }
                if (v.tipo === 'apri') {
                    seduta.aperta_il = v.segnato_il;
                    seduta.presidente = { anagrafica_id: d.presidente?.anagrafica_id ?? null, nome: d.presidente?.nome ?? nomeDi(d.presidente?.anagrafica_id) };
                    seduta.segretario = { anagrafica_id: d.segretario?.anagrafica_id ?? null, nome: d.segretario?.nome ?? nomeDi(d.segretario?.anagrafica_id) };
                    s.seduta_id = seduta.id;
                    s.da_aprire = null;
                    s.controllo = null;
                    s.controllo_da_rifare = false;
                    s.assemblea.stato = 'in_corso';
                    if (s.fotografia) s.fotografia.convocazione = seduta.convocazione;
                }
                if (v.tipo === 'apertura_decidi') seduta.apertura_da_decidere = null;
                if (v.tipo === 'sospendi' || v.tipo === 'chiudi') {
                    esconoTutti(v.segnato_il);
                    seduta.esito = v.tipo === 'sospendi' ? 'sospesa' : 'tenuta';
                    seduta.chiusa_il = v.segnato_il;
                    s.seduta_id = null;
                    if (v.tipo === 'chiudi') s.assemblea.stato = 'chiusa';
                    else daAprireDopo(seduta, Number(d.prosegue_seduta_id));
                }
                inCoda.add(`seduta:${seduta.id}`);
                break;
            }
            case 'scegli':
                s.conflitti = s.conflitti.filter((c) => c.gesto !== d.gesto);
                break;
            case 'testo': {
                // La discussione di un punto scritta qui e non ancora arrivata: la sala la mostra com'è sul computer.
                const punto = /^punto:(\d+)$/.exec(String(d.parte ?? ''))?.[1];
                if (punto !== undefined && s.verbale && d.documento) {
                    s.verbale.discussioni[punto] = { parte: d.parte, documento: d.documento, revisione: Number(v.base ?? s.verbale.discussioni[punto]?.revisione ?? 0),
                        impronta: '' };
                    inCoda.add(`discussione:${punto}`);
                }
                break;
            }
            default:
                inCoda.add(`gesto:${v.gesto}`);
        }
    };
    /** Le versioni che il server ha risposto a un gesto arrivato: la presenza, il voto, la votazione (un «Era già così» non le cambia). */
    const allinea = (v: VoceInCoda) => {
        const d = v.dati as Record<string, any>;
        const versione = numeroDato('versione');
        if (v.tipo === 'entra' || v.tipo === 'esce') {
            if (versione !== undefined) s.versioni[chiaveDi(d.persona).chiave] = versione;
            return;
        }
        if (!['si_vota', 'tutti_favorevoli', 'voto', 'conflitto', 'conferma', 'nota'].includes(v.tipo)) return;
        const votazione = v.tipo === 'si_vota' ? votazioni().find((x) => x.id === provvisori.get(v.gesto)) : laVotazione(d);
        if (!votazione) return;
        if (v.tipo === 'voto') {
            const voto = votazione.voti.find((x) => x.testa === d.testa);
            if (voto && versione !== undefined) voto.versione = versione;
            if (numeroDato('versione_votazione') !== undefined) votazione.versione = numeroDato('versione_votazione')!;
        } else if (versione !== undefined) {
            votazione.versione = versione;
        }
    };
    // I gesti arrivati (già applicati dal server, non ancora nello stato tenuto) si contano come quelli in coda, con i numeri e le versioni del server, ma
    // non sono «su questo computer».
    for (const a of arrivati) {
        risposta = a.risposta ?? {};
        applica(a.voce);
        allinea(a.voce);
    }
    risposta = {};
    inCoda.clear();
    for (const v of voci) applica(v);

    // Il calendario: lo stato tenuto può essere del giorno prima. «Si apre» dal giorno della seduta e dall'ora di adesso; un controllo letto un altro giorno
    // non vale più: lo rifà il server.
    if (s.da_aprire) {
        const seduta = s.sedute.find((x) => x.id === s.da_aprire!.seduta_id);
        if (seduta) {
            const siApre = oggi >= seduta.giorno.slice(0, 10);
            s.da_aprire = { ...s.da_aprire, si_apre: siApre, perche: siApre ? null : siApreIl(seduta.giorno) };
        }
        if (s.controllo && giornoDiRoma(new Date(s.ora_del_server)) !== oggi) {
            s.controllo = null;
            s.controllo_da_rifare = true;
        }
        if (!s.controllo && s.da_aprire.si_apre && s.controllo_da_rifare === undefined) s.controllo_da_rifare = true;
    }
    if (s.fotografia) {
        const presenti = personePresenti(s.presenze, s.deleghe);
        s.barra = { ...barra(s.fotografia, presenti), presenti: [...presenti] };
    }
    for (const votazione of toccate) {
        const conteggi = contaIVoti(votazione);
        votazione.conteggi = conteggi;
        const i = votazione.intervenuti;
        votazione.esito_proposto = esitoProposto(votazione.maggioranza, i.convocazione, i.totale_teste, i.totale_valore, i.numero, conteggi.favorevoli.teste,
            conteggi.favorevoli.valore) as VotazioneDellaSala['esito_proposto'];
    }
    s.in_coda = [...inCoda];

    return s;
}

/**
 * Il numero che il server non ridice: per una votazione aperta (`votazione_id`), quella dello stesso punto, numero e seduta; per una delega (`delega_id`),
 * l'ultima dello stesso delegante.
 */
export function cercaNelloStato(sala: SalaDellAssemblea | null, voce: VoceInCoda | undefined, campo: string): number | undefined {
    if (!sala || !voce) return undefined;
    const d = voce.dati as Record<string, any>;
    if (campo === 'votazione_id' && voce.tipo === 'si_vota') {
        return sala.punti.flatMap((p) => p.votazioni).find((v) => v.punto_id === Number(d.punto_id) && v.seduta_id === Number(d.seduta_id)
            && v.numero === Number(d.numero ?? 1))?.id;
    }
    if (campo === 'delega_id' && voce.tipo === 'delega') {
        return [...sala.deleghe].reverse().find((x) => x.delegante_anagrafica_id === Number(d.delegante_anagrafica_id) && x.arrivata_come === 'in_sala')?.id;
    }

    return undefined;
}

/** L'ora di un istante come si legge in sala: «19:41». */
export function ora(istante: string, fuso = 'Europe/Rome'): string {
    return new Intl.DateTimeFormat('it-IT', { hour: '2-digit', minute: '2-digit', timeZone: fuso }).format(new Date(istante));
}

/** Il giorno di un istante: «15/12/2026». */
export function giorno(istante: string, fuso = 'Europe/Rome'): string {
    return new Intl.DateTimeFormat('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: fuso }).format(new Date(istante));
}

/** Che cosa era un gesto, in parole: `{cosa, cose, chi, punto}` («il voto», «i voti», «De Luca Gianni», 4). */
export function descriviIlGesto(voce: VoceInCoda, sala: SalaDellAssemblea | null): { cosa: string; cose: string; chi: string; punto: number | null } {
    const d = voce.dati as Record<string, any>;
    const persone = sala?.fotografia?.persone ?? {};
    const nome = (a: unknown) => (a != null ? (persone[String(a)]?.nome ?? '') : '');
    const votazione = sala?.punti.flatMap((p) => p.votazioni).find((v) => v.id === d.votazione_id);
    const puntoDi = (puntoId: unknown) => sala?.punti.find((p) => p.id === Number(puntoId))?.numero ?? null;
    const punto = votazione ? puntoDi(votazione.punto_id) : puntoDi(d.punto_id);
    const testa = () => {
        const t = votazione?.intervenuti.teste.find((x) => x.chiave === d.testa) ?? sala?.fotografia?.costituzione.teste.find((x) => x.chiave === d.testa);
        return (t as TestaIntervenuta | undefined)?.chi?.nome ?? t?.nome ?? '';
    };
    const chiDellaPersona = () => (d.persona?.anagrafica_id != null ? nome(d.persona.anagrafica_id) : sala?.deleghe.find((x) => x.id === d.persona?.delega_id)?.delegato_nome ?? '');

    switch (voce.tipo) {
        case 'voto':
            return { cosa: 'il voto', cose: 'i voti', chi: testa(), punto };
        case 'conflitto':
            return { cosa: 'il conflitto d\'interessi', cose: 'i conflitti d\'interessi', chi: testa(), punto };
        case 'entra':
            return { cosa: 'l\'entrata', cose: 'le entrate', chi: chiDellaPersona(), punto: null };
        case 'esce':
            return { cosa: 'l\'uscita', cose: 'le uscite', chi: chiDellaPersona(), punto: null };
        case 'dichiarazione':
            return { cosa: 'la dichiarazione a verbale', cose: 'le dichiarazioni a verbale', chi: d.nome ?? nome(d.anagrafica_id), punto: puntoDi(d.punto_id) };
        case 'dichiarazione_cambia':
        case 'dichiarazione_togli': {
            const di = sala?.dichiarazioni.find((x) => x.id === d.dichiarazione_id);
            const cosa = voce.tipo === 'dichiarazione_cambia' ? 'la correzione della dichiarazione a verbale' : 'la dichiarazione a verbale tolta';
            const cose = voce.tipo === 'dichiarazione_cambia' ? 'le correzioni delle dichiarazioni a verbale' : 'le dichiarazioni a verbale tolte';
            return { cosa, cose, chi: di?.nome ?? '', punto: puntoDi(di?.punto_id) };
        }
        case 'delega':
            return { cosa: 'la delega', cose: 'le deleghe', chi: nome(d.delegante_anagrafica_id), punto: null };
        case 'si_vota':
            return { cosa: 'l\'apertura del voto', cose: 'le aperture del voto', chi: '', punto };
        case 'tutti_favorevoli':
            return { cosa: '«Tutti i presenti favorevoli»', cose: '«Tutti i presenti favorevoli»', chi: '', punto };
        case 'conferma':
            return { cosa: 'la conferma dell\'esito', cose: 'le conferme dell\'esito', chi: '', punto };
        case 'apri':
            return { cosa: 'l\'apertura dell\'assemblea', cose: 'le aperture', chi: '', punto: null };
        case 'testo': {
            const delPunto = puntoDi(/^punto:(\d+)$/.exec(String(d.parte ?? ''))?.[1]);
            const cosa = delPunto !== null ? `la discussione del punto ${delPunto}` : 'il testo del verbale';
            return { cosa, cose: cosa, chi: '', punto: null };
        }
        default:
            return { cosa: 'un gesto', cose: 'altri gesti', chi: '', punto };
    }
}

/** «Rossi», «Rossi e Neri», «Rossi, Neri e Russo». */
function elenco(voci: string[]): string {
    return voci.length <= 1 ? (voci[0] ?? '') : voci.slice(0, -1).join(', ') + ' e ' + voci[voci.length - 1];
}

/** «i voti di De Luca Gianni e di Lombardi Sara al punto 4 e la dichiarazione a verbale di Romano Ida». */
export function cheCosaErano(voci: VoceInCoda[], sala: SalaDellAssemblea | null): string {
    const gruppi: { cosa: string; cose: string; chi: string[]; punto: number | null }[] = [];
    for (const v of voci) {
        const g = descriviIlGesto(v, sala);
        const ultimo = gruppi[gruppi.length - 1];
        if (ultimo && ultimo.cosa === g.cosa && ultimo.punto === g.punto) {
            if (g.chi) ultimo.chi.push(g.chi);
        } else {
            gruppi.push({ cosa: g.cosa, cose: g.cose, chi: g.chi ? [g.chi] : [], punto: g.punto });
        }
    }

    return elenco(gruppi.map((g) => (g.chi.length > 1 ? g.cose : g.cosa)
        + (g.chi.length > 0 ? ' ' + elenco(g.chi.map((c) => 'di ' + c)) : '') + (g.punto !== null ? ' al punto ' + g.punto : '')));
}

/** Le frasi del lavoro ritrovato: il titolo, che cosa è arrivato e quando, che cosa aspetta una scelta, che cosa non è stato accettato. */
export function fraseDeiRitrovati(r: Ritrovati, sala: SalaDellAssemblea | null, fuso = 'Europe/Rome'): { titolo: string; righe: string[] } {
    const gesti = r.quanti === 1 ? '1 gesto che non era partito' : `${r.quanti} gesti che non erano partiti`;
    const quando = `Del ${giorno(r.dal, fuso)}, ${r.dal === r.al ? 'alle ' + ora(r.dal, fuso) : 'dalle ' + ora(r.dal, fuso) + ' alle ' + ora(r.al, fuso)}`;
    if (!r.finito) {
        return { titolo: `Ho ritrovato ${gesti}: partono da soli quando torna la rete.`, righe: [`${quando}: ${cheCosaErano(r.voci, sala)}.`] };
    }
    const per = (esiti: EsitoDiUnGesto['esito'][]) => r.esiti.filter((e) => esiti.includes(e.esito));
    const arrivati = per(['applicato', 'doppio']);
    const due = per(['in_conflitto']);
    const no = per(['rifiutato']);
    const pezzi = [`${arrivati.length} ${arrivati.length === 1 ? 'è arrivato' : 'sono arrivati'}`];
    if (due.length > 0) pezzi.push(`${due.length} ${due.length === 1 ? 'ha' : 'hanno'} due versioni`);
    if (no.length > 0) pezzi.push(`${no.length} non ${no.length === 1 ? 'è stato accettato' : 'sono stati accettati'}`);
    const righe: string[] = [];
    if (arrivati.length > 0) {
        righe.push(`${quando}: ${cheCosaErano(arrivati.map((e) => e.voce), sala)} ${arrivati.length === 1 ? 'è arrivato' : 'sono arrivati'}`
            + (r.arrivati_alle ? ` alle ${ora(r.arrivati_alle, fuso)}.` : '.'));
    }
    for (const e of due) {
        const g = descriviIlGesto(e.voce, sala);
        righe.push(`${g.cosa.charAt(0).toUpperCase()}${g.cosa.slice(1)}${g.chi ? ' di ' + g.chi : ''}${g.punto !== null ? ' al punto ' + g.punto : ''} aspetta la tua scelta.`);
    }
    for (const e of no) {
        const g = descriviIlGesto(e.voce, sala);
        righe.push(`${g.cosa.charAt(0).toUpperCase()}${g.cosa.slice(1)}${g.chi ? ' di ' + g.chi : ''}: ${e.messaggio ?? 'non accettato'}`);
    }

    return { titolo: `Ho ritrovato ${gesti}: ${elenco(pezzi)}.`, righe };
}

/** Il dispositivo in parole: «computer», «telefono», «tablet», da come il browser si presenta (`navigator.userAgent`). */
export function tipoDiDispositivo(presentazione: string = globalThis.navigator?.userAgent ?? ''): 'computer' | 'telefono' | 'tablet' {
    if (/iPad|Tablet|(Android(?!.*Mobile))/i.test(presentazione)) return 'tablet';
    if (/Mobi|iPhone|Android/i.test(presentazione)) return 'telefono';

    return 'computer';
}
