// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il seguito delle delibere nelle pagine: le parole e i conti piccoli del riepilogo «Che cosa cambia nel gestionale» e del passo 6 «Delibere eseguite».
 * I numeri e le frasi lunghe arrivano dal server (`SeguitoDelleDelibere`); qui si conta solo ciò che cambia mentre l'amministratore toglie una spunta, e si
 * compone il corpo di «Conferma», che il server ricalcola comunque.
 */
import type {
    AChiMemo, ConfermaDelSeguito, ContoDelRiepilogo, CosaDaFare, EsitoDellaRiga, PuntoDelRiepilogo, RigaDelPassoSei, RigaDelSeguito,
} from '@/types/assemblee';
import { giornoItaliano } from '@/lib/giorno';

/** Le schede che hanno una pagina da aprire («Apri», «Apri il piano»): le altre, come la versione del preventivo, si correggono dal passo. */
export const SCHEDE_CON_LA_PAGINA = ['piano_rate', 'gestione', 'rendiconto_versione', 'fattura_passiva', 'cassa', 'accordo_rientro', 'dispensa_sei_mesi',
    'regola_recupero', 'comunicazione'];

/** Le righe che contano anche senza spunta: la delega in vigore, il punto per la prossima assemblea, ciò che resta fuori. */
const CONTANO_SEMPRE = ['delega', 'memo', 'fuori'];

/** La spunta di una riga che si applica: quella scelta adesso, o quella di serie. */
export const eSpuntata = (r: RigaDelSeguito, spunte: Record<string, boolean>): boolean => r.si_applica && !r.applicata && (spunte[r.chiave] ?? r.spuntata);

/** Il conto in fondo al riepilogo, con le spunte di adesso e le cose scritte: la stessa regola del server, sulle delibere ancora da confermare. */
export function contoDelRiepilogo(punti: PuntoDelRiepilogo[], spunte: Record<string, boolean>, altre: number): ContoDelRiepilogo {
    const conto: ContoDelRiepilogo = { cambi: 0, cose: altre, comunicazioni: 0, deleghe: 0, punti: 0, fuori: 0, respinte: 0 };
    for (const p of punti.filter((x) => !x.confermata)) {
        for (const r of p.righe) {
            const conta = r.si_applica && !r.applicata ? (spunte[r.chiave] ?? r.spuntata) : (r.spuntata || CONTANO_SEMPRE.includes(r.tipo));
            if (r.conto && conta) {
                conto[r.conto]++;
            }
        }
    }

    return conto;
}

/** «3 cambi nei conti · 2 cose da fare · 1 comunicazione da rileggere · 1 delega in vigore · 1 punto per la prossima assemblea». */
export function fraseDelConto(c: ContoDelRiepilogo): string {
    const parti = [c.cambi === 1 ? '1 cambio nei conti' : `${c.cambi} cambi nei conti`];
    if (c.fuori > 0) parti.push(c.fuori === 1 ? '1 resta fuori' : `${c.fuori} restano fuori`);
    parti.push(c.cose === 1 ? '1 cosa da fare' : `${c.cose} cose da fare`);
    if (c.comunicazioni > 0) parti.push(c.comunicazioni === 1 ? '1 comunicazione da rileggere' : `${c.comunicazioni} comunicazioni da rileggere`);
    if (c.respinte > 0) parti.push(c.respinte === 1 ? '1 respinta da decidere' : `${c.respinte} respinte da decidere`);
    if (c.deleghe > 0) parti.push(c.deleghe === 1 ? '1 delega in vigore' : `${c.deleghe} deleghe in vigore`);
    if (c.punti > 0) parti.push(c.punti === 1 ? '1 punto per la prossima assemblea' : `${c.punti} punti per la prossima assemblea`);

    return parti.join(' · ');
}

/** La data e la persona di una cosa da fare, come le sceglie l'amministratore. */
export interface QuandoEChi { entro_il: string; a_chi: AChiMemo; assegnato_a: number | null }

/** Una cosa scritta a mano sotto un punto («Firma con Serramenti Marino»). */
export interface AltraCosa extends QuandoEChi { delibera_id: number; testo: string }

/**
 * Il corpo di «Conferma»: le chiavi spuntate delle delibere da confermare; per le cose fra quelle, la data (vuota: niente compito) e la persona; le cose
 * scritte con un testo; «che cosa ha cambiato l'assemblea» per le delibere approvate con modifiche.
 */
export function corpoDellaConferma(punti: PuntoDelRiepilogo[], spunte: Record<string, boolean>, cose: Record<string, Partial<QuandoEChi>>, altre: AltraCosa[],
    modifiche: Record<number, string>): Required<ConfermaDelSeguito> {
    const daConfermare = punti.filter((p) => !p.confermata);
    const righe = daConfermare.flatMap((p) => p.righe).filter((r) => eSpuntata(r, spunte));
    const chiavi = righe.map((r) => r.chiave);
    const corpoCose: Required<ConfermaDelSeguito>['cose'] = {};
    for (const r of righe.filter((x) => x.tipo === 'cosa')) {
        const c = cose[r.chiave];
        if (!c) continue;
        corpoCose[r.chiave] = { entro_il: c.entro_il || null, a_chi: c.a_chi ?? 'me', assegnato_a: c.a_chi === 'collaboratore' ? (c.assegnato_a ?? null) : null };
    }
    const conModifiche = new Set(daConfermare.filter((p) => p.esito === 'approvata_con_modifiche').map((p) => p.delibera_id));
    const corpoModifiche: Record<number, string> = {};
    for (const [id, testo] of Object.entries(modifiche)) {
        if (conModifiche.has(Number(id)) && testo.trim() !== '') corpoModifiche[Number(id)] = testo.trim();
    }

    return {
        chiavi,
        cose: corpoCose,
        altre: altre.filter((a) => a.testo.trim() !== '').map((a) => ({ delibera_id: a.delibera_id, testo: a.testo.trim(), entro_il: a.entro_il || null,
            a_chi: a.a_chi, assegnato_a: a.a_chi === 'collaboratore' ? a.assegnato_a : null })),
        modifiche: corpoModifiche,
    };
}

/** Le righe di un punto nel riepilogo: i cambi e le frasi, le cose da fare con la data e la persona, «Avvisa i condòmini» (il pulsante con la casella). */
export function righeDelPunto(p: PuntoDelRiepilogo): { cambi: RigaDelSeguito[]; cose: RigaDelSeguito[]; avviso: RigaDelSeguito | null } {
    const avviso = p.righe.find((r) => r.tipo === 'cosa' && r.cosa?.tipo === 'avvisa_condomini') ?? null;

    return {
        cambi: p.righe.filter((r) => r.tipo !== 'cosa'),
        cose: p.righe.filter((r) => r.tipo === 'cosa' && r !== avviso),
        avviso,
    };
}

/** «08/01/2027» da «2027-01-08». */
const giorno = (g: string | null): string => giornoItaliano(g);

/**
 * La riga di una cosa nel passo 6: «Firma con Serramenti Marino: il 08/01/2027, segnata da Marco Galli», «Avviso in bacheca: pubblicato il 17/12/2026»,
 * «Crea il piano straordinario: entro il 15/01/2027, Andrea Costa», «…: non serve più dal 20/12/2026». Fatta dal programma, dice la scheda che la esegue.
 */
export function rigaDellaCosa(c: CosaDaFare): string {
    if (c.stato === 'fatta') {
        if (c.tipo === 'avvisa_condomini') return `Avviso in bacheca: pubblicato il ${giorno(c.fatta_il)}`;
        return `${c.testo}: il ${giorno(c.fatta_il)}${c.oggetto ? ` · ${c.oggetto.nome}` : c.fatta_da ? `, segnata da ${c.fatta_da}` : ''}`;
    }
    if (c.stato === 'non_serve') return `${c.testo}: non serve più dal ${giorno(c.fatta_il)}`;

    return `${c.testo}${c.entro_il ? `: entro il ${giorno(c.entro_il)}` : ''}${c.chi ? `${c.entro_il ? ',' : ':'} ${c.chi}` : ''}`;
}

/**
 * La riga breve di una cosa ancora da fare, nella tabella: «Crea il piano straordinario · a Vincenzo Rossi», «Avvisa i condòmini · a Vincenzo Rossi», con la
 * data se c'è («· entro il 15/01/2027»). Senza il titolo del punto e senza i due punti: il punto è già nella riga. Una cosa scritta a mano dice quello che c'è scritto.
 */
export function rigaBreveDellaCosa(c: CosaDaFare): string {
    const nome = c.tipo === 'fuori_dal_programma' ? c.testo : c.tipo_parola;

    return [nome, c.entro_il ? `entro il ${giorno(c.entro_il)}` : null, c.chi ? `a ${c.chi}` : null].filter(Boolean).join(' · ');
}

/** Il nome del pulsante nella riga: «Crea il piano straordinario» è «Crea il piano», «Prepara il piano di ripianamento» è «Prepara il piano»; il nome intero resta nella card «Da fare adesso». */
export function nomeDelPulsante(azione: string): string {
    if (azione === 'Prepara il piano di ripianamento') return 'Prepara il piano';

    return azione === 'Crea il piano straordinario' ? 'Crea il piano' : azione;
}

/**
 * I conti della card «Le delibere» (che erano nel badge giallo): «1 da eseguire · 3 eseguite» e, sotto, «1 respinta · 1 senza voto · 2 cose da fare aperte».
 * Solo i numeri che non sono zero.
 */
export function contiDelleDelibere(tabella: RigaDelPassoSei[], coseAperte: number): { principale: string; altre: string } {
    const quante = (f: (r: RigaDelPassoSei) => boolean) => tabella.filter(f).length;
    const pezzo = (n: number, uno: string, piu: string) => (n === 0 ? null : n === 1 ? `1 ${uno}` : `${n} ${piu}`);
    const principale = [pezzo(quante((r) => r.seguito.stato === 'da_eseguire'), 'da eseguire', 'da eseguire'), pezzo(quante((r) => r.seguito.stato === 'eseguita'), 'eseguita', 'eseguite')]
        .filter(Boolean).join(' · ');
    const altre = [pezzo(quante((r) => r.esito === 'respinta'), 'respinta', 'respinte'), pezzo(quante((r) => r.esito === 'rinviata'), 'rinviata', 'rinviate'),
        pezzo(quante((r) => r.esito === 'senza_voto'), 'senza voto', 'senza voto'), pezzo(coseAperte, 'cosa da fare aperta', 'cose da fare aperte')].filter(Boolean).join(' · ');

    return { principale: principale || (tabella.length === 0 ? 'nessuna delibera' : 'niente da eseguire'), altre };
}

/** Il dettaglio accanto a «Eseguita»: «il 16/12 · Piano «Ordinario 2027»»; per le altre niente (le cose stanno sotto). */
export function dettaglioDelSeguito(r: RigaDelPassoSei): string {
    if (r.seguito.stato !== 'eseguita' || !r.seguito.eseguita_il) return '';
    const posti = r.posti.filter((p) => p.esiste);

    return `il ${giorno(r.seguito.eseguita_il).slice(0, 5)}${posti.length ? ` · ${posti[0].nome}${posti.length > 1 ? ` e altre ${posti.length - 1}` : ''}` : ''}`;
}

/** Al telefono le delibere da eseguire stanno in cima; le altre restano nell'ordine dei punti. */
export function ordinePerIlTelefono(tabella: RigaDelPassoSei[]): RigaDelPassoSei[] {
    return [...tabella.filter((r) => r.seguito.stato === 'da_eseguire'), ...tabella.filter((r) => r.seguito.stato !== 'da_eseguire')];
}

export type VoceDelMenu = 'applica' | 'eseguita' | 'non_serve' | 'nuova' | 'impugnata' | 'annullata' | 'revocata';

/** Le finestre delle cose da fare e dei segni. */
export type ModoDellaCosa = 'eseguita' | 'non_serve' | 'chiudi' | 'nuova';
export type ModoDelSegno = 'impugnata' | 'annullata' | 'revocata';

/**
 * Le voci del menu «⋯» di una riga del passo 6, ognuna con la riga che dice a che cosa serve. «Applica» solo con una riga tolta dal riepilogo che si applica;
 * «Segna eseguita» e «Non serve più» con una cosa aperta; i segni e «Un'altra cosa da fare» solo su una delibera approvata; una delibera annullata o revocata
 * non si segna di nuovo.
 */
export function vociDelMenu(r: RigaDelPassoSei, condominio: string): { voce: VoceDelMenu; etichetta: string; riga: string }[] {
    const approvata = r.esito === 'approvata' || r.esito === 'approvata_con_modifiche';
    const chiusa = r.segni.some((s) => s.tipo === 'annullata' || s.tipo === 'revocata');
    const aperte = r.cose.filter((c) => c.stato === 'da_fare' && c.tipo !== 'dopo_il_rifiuto');
    const voci: { voce: VoceDelMenu; etichetta: string; riga: string }[] = [];
    if (r.righe_da_applicare.some((x) => x.si_applica && !x.applicata)) voci.push({ voce: 'applica', etichetta: 'Applica', riga: 'Solo per una riga tolta dal riepilogo' });
    if (aperte.length) {
        voci.push({ voce: 'eseguita', etichetta: 'Segna eseguita', riga: 'Per ciò che fai fuori dal programma' });
        voci.push({ voce: 'non_serve', etichetta: 'Non serve più', riga: 'Con il motivo, che resta nel registro' });
    }
    if (!approvata) return voci;
    voci.push({ voce: 'nuova', etichetta: 'Un\'altra cosa da fare', riga: 'Con la data diventa un compito' });
    if (!r.segni.some((s) => s.tipo === 'impugnata')) voci.push({ voce: 'impugnata', etichetta: 'Segna impugnata', riga: 'Qualcuno ha fatto causa: non ferma niente' });
    if (!chiusa) {
        voci.push({ voce: 'annullata', etichetta: 'Segna annullata dal giudice', riga: 'Con il provvedimento: le schede lo dicono' });
        voci.push({ voce: 'revocata', etichetta: 'Segna revocata', riga: `Da una delibera successiva di ${condominio}` });
    }

    return voci;
}

/** Gli esiti dopo «Conferma» o «Applica»: quante righe sono applicate (anche prima), e il perché di ognuna che non si è applicata. */
export function esitiDaMostrare(esiti: Record<string, EsitoDellaRiga>): { applicate: number; non: string[] } {
    const tutte = Object.values(esiti);

    return {
        applicate: tutte.filter((e) => e.esito === 'applicata' || e.esito === 'gia_applicata').length,
        non: tutte.filter((e) => e.esito === 'non_applicata' || e.esito === 'sconosciuta').map((e) => e.frase),
    };
}
