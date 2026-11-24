<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il voto di un punto (scena 3 della bozza approvata della sala; scena 10a della bozza delle scene della tappa 3; decisione 594 (2) e (4)).
 *
 * - **Prima di «Si vota»**: chi vota adesso, e sulla riga del nudo proprietario di un'unità in usufrutto, su un punto straordinario, «Vota l'usufruttuario»
 *   con il perché (lo sceglie il presidente; «Torna al nudo proprietario»). «Si vota» ferma il momento e gli intervenuti; il punto senza voto ha solo
 *   «Trattato».
 * - **Il voto**: «Tutti i presenti favorevoli» in un gesto, poi i contrari e gli astenuti con i tre pulsanti di ogni testa (e «Discorde» per i
 *   comproprietari); il segno «In conflitto» con la nota, che non toglie nessuno dal conto; i conteggi e la proposta contati sul posto.
 * - **La riga di ogni persona** (la prova a video del 22/11/2026, notte): i pulsanti alti 28 px, a 12, con poco spazio ai lati, su una riga anche con
 *   cinque («Favorevole», «Contrario», «Astenuto», «Discorde», «In conflitto»); la riga si dispone sulla larghezza dell'elenco (nella sala a due colonne è
 *   metà pagina): stretta, i pulsanti vanno tutti insieme sotto il nome. Sotto il nome chi è e per quale unità («rappresenta l'Int. 3 · 240,00»). Da otto
 *   persone in su, sopra l'elenco, «Cerca per nome o per unità», sul posto e senza rete.
 * - **L'esito per condizione**: sotto i conteggi le teste e il valore, una riga ciascuna, con la soglia e quanto manca («Teste: 9 su 11, più della metà:
 *   sì», «Valore: 292,00 millesimi, ne servono 333,34: mancano 41,34»): arrivano con i conteggi del server; con un gesto del voto ancora qui si contano sul
 *   posto, con lo stesso conto. Un'altra maggioranza non ha condizioni: resta la frase, «decide il presidente».
 * - **Accanto alla conferma**: l'avviso quando l'assemblea non risulta costituita («decide il presidente»), il conto della sola scala, il conflitto
 *   determinante; le note del presidente sul voto e su chi non ha votato, che vanno nel verbale; il motivo di «Un altro esito» (rilievo C55); la
 *   dichiarazione a verbale.
 * - **Il punto di una parte** (la sola scala, il gruppo di una tabella; Coda 734): sotto la regola la riga azzurra «Votano solo i condòmini della tabella
 *   «Box»: chi altro è in sala non vota su questo punto.». Un voto fermato nella beta.13 non ha la riga.
 * - **La riga del voto** (decisione 664 (4), rilievo C51): «Nota sul voto», «Nota su chi non ha votato», «Dichiarazione a verbale», «Un altro esito» (un
 *   pulsante), tutti alti 28 px e chiari; il gesto principale («Conferma: approvata», «Si vota») scuro e alto uguale, a destra; al telefono a tutta riga.
 * - **Le dichiarazioni a verbale** (decisione 664 (7), rilievo C53): ognuna in una riga sotto il punto, con chi l'ha fatta; «Modifica» (la finestra della
 *   dichiarazione, con il testo) e «Togli» (una conferma breve) finché il voto del punto non è confermato: i gesti `dichiarazione_cambia` e
 *   `dichiarazione_togli`, che passano per la coda come gli altri.
 * - **«Conferma: approvata»** con un clic, con l'esito proposto; «Un altro esito» con il motivo. Un voto confermato si corregge fino alla chiusura, con il
 *   perché. Una scelta aperta fra due versioni ferma la conferma. Un punto di nomina apre sempre la finestra, che chiede il nome del nuovo amministratore:
 *   la riga proposta del registro di nomina e revoca lo porta.
 * - **«La discussione»** sotto il voto: ciò che si è detto, scritto dal segretario; parte come gesto `testo` della sala pochi secondi dopo la pausa (o al
 *   più dopo 30 secondi), con la revisione da cui parte. La stessa si legge e si scrive nella pagina «Il verbale».
 */
import { computed, ref, watch } from 'vue';
import { AlertTriangle, Check, X } from 'lucide-vue-next';
import { BADGE, CLASSI_DEL_BADGE, coloreDellEsito, regolaDellaMaggioranza } from '@/lib/assemblee/assemblee';
import { millesimi, personePresenti, type Costituzione } from '@/lib/assemblee/sala/conti';
import type { PercheVotaLUsufruttuario, PuntoDellaSala, SalaDellAssemblea, SceltaDiVoto, TestaDellaFotografia, VotazioneDellaSala } from '@/types/assemblee';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { PICCOLO, PRINCIPALE_PICCOLO } from '@/components/assemblee/verbale/finestra';
import { idPerIlGesto, type NatoSenzaRete } from '@/lib/senzaRete/sala';
import CercaNellElenco from '@/components/assemblee/CercaNellElenco.vue';
import { DA_QUANTI_SI_CERCA, paroleDellaRicerca, rispondeAllaRicerca } from '@/lib/assemblee/sala/cerca';
import { useSala } from './contesto';
import {
    PERCHE_DELL_USUFRUTTUARIO, RUOLI, baseDellaVotazione, conteggiSulPosto, eDellaVotazione, elencoDeiNomi, laVotazioneDelGesto, oraDiRoma, parolaDellEsito,
    type Opzione, type VotoConLaCoda,
} from './sala';
import SuQuestoComputer from './rete/SuQuestoComputer.vue';
import FinestraDellUsufruttuario from './FinestraDellUsufruttuario.vue';
import FinestraDiTesto from './FinestraDiTesto.vue';
import FinestraDellaConferma from './FinestraDellaConferma.vue';
import DiscussioneDelPunto from '@/components/assemblee/verbale/DiscussioneDelPunto.vue';

const props = defineProps<{ punto: PuntoDellaSala; presenti: number[]; costituzione: Costituzione | null }>();
const { sala, segna, puoGestire, inCoda, nomeDi, dispositivo, scriviLaDiscussione } = useSala();

const sedutaId = computed(() => sala.value.seduta_id);
const inCorso = computed(() => sala.value.assemblea.stato === 'in_corso');
const gestisce = computed(() => puoGestire.value && inCorso.value && sedutaId.value !== null);
const senzaVoto = computed(() => props.punto.maggioranza === 'senza_voto');
const foto = computed(() => sala.value.fotografia?.punti.find((p) => p.punto_id === props.punto.id) ?? null);
const votazione = computed<VotazioneDellaSala | null>(() => props.punto.votazioni.find((v) => v.confermata_il === null) ?? props.punto.votazioni.at(-1) ?? null);
const confermata = computed(() => votazione.value?.confermata_il != null);
const rendiconto = computed(() => props.punto.materia === 'rendiconto');
/** Il rendiconto ha due votazioni: il rendiconto e il riparto. */
const ancoraDaVotare = computed(() => !senzaVoto.value && (props.punto.votazioni.length === 0
    || (rendiconto.value && props.punto.votazioni.length === 1 && confermata.value)));

/** La regola del punto, con le stesse parole della riga sotto il menu della maggioranza nella finestra del punto. */
const regola = computed(() => regolaDellaMaggioranza(props.punto.maggioranza));

// ─── Prima di «Si vota»: chi vota, e l'usufruttuario scelto dal presidente ──────────────────────────────────────────────────────────────────────────
/** Chi è presente per questo punto: la delega per alcuni punti vale solo per i suoi (la regola del server, `conti.ts`). */
const presentiDelPunto = computed(() => personePresenti(sala.value.presenze, sala.value.deleghe, props.punto.id));
const scelte = ref<Record<number, { perche: PercheVotaLUsufruttuario; detto: string }>>({});
const finestraUsufrutto = ref(false);
const unitaInScelta = ref<number | null>(null);
function personaDiRuolo(u: number, ruolo: string): number | null {
    return sala.value.fotografia?.unita[String(u)]?.titolari.find((t) => t.ruolo === ruolo)?.anagrafica_id ?? null;
}
const chiVota = computed(() => {
    if (!foto.value) return [];
    const usufrutto = props.punto.amministrazione === 'straordinaria' ? foto.value.usufrutto : [];

    return foto.value.teste.map((t: TestaDellaFotografia) => {
        const u = t.unita.find((x) => usufrutto.includes(x)) ?? null;
        const scelta = u !== null ? scelte.value[u] : undefined;
        const usufruttuario = u !== null ? personaDiRuolo(u, 'usufruttuario') : null;
        const persone = scelta && usufruttuario !== null ? [usufruttuario] : t.persone;
        const ruolo = scelta ? 'usufruttuario' : (t.unita.map((x) => foto.value!.ruoli[String(x)]).find(Boolean) ?? (t.persone.length > 1 ? 'rappresentante' : 'proprietario'));

        return { testa: t, unitaUsufrutto: u, scelta, nome: persone.map((p) => nomeDi(p)).join(' e '), ruolo, presente: persone.some((p) => presentiDelPunto.value.has(p)) };
    }).filter((r) => r.presente || r.unitaUsufrutto !== null);
});
const perLaFinestra = computed(() => {
    const u = unitaInScelta.value;
    if (u === null || !sala.value.fotografia) return null;
    const unita = sala.value.fotografia.unita[String(u)];

    return { unita: unita?.nome ?? '', usufruttuario: nomeDi(personaDiRuolo(u, 'usufruttuario')), nudo: nomeDi(personaDiRuolo(u, 'nuda_proprietario')), millesimi: unita?.millesimi ?? 0 };
});
function votaLUsufruttuario(u: number) {
    unitaInScelta.value = u;
    finestraUsufrutto.value = true;
}
function sceltoLUsufruttuario(perche: PercheVotaLUsufruttuario, detto: string) {
    if (unitaInScelta.value !== null) scelte.value = { ...scelte.value, [unitaInScelta.value]: { perche, detto } };
}
function tornaAlNudo(u: number) {
    const resto = { ...scelte.value };
    delete resto[u];
    scelte.value = resto;
}
const fraseDelPerche = (p: PercheVotaLUsufruttuario) => PERCHE_DELL_USUFRUTTUARIO.find((x) => x.valore === p)?.etichetta.toLowerCase() ?? '';

async function siVota() {
    if (sedutaId.value === null) return;
    const usufruttuari = Object.entries(scelte.value).map(([u, s]) => ({ immobile_id: Number(u), perche: s.perche }));
    const numero = props.punto.votazioni.length + 1;
    await segna('si_vota', {
        seduta_id: sedutaId.value, punto_id: props.punto.id,
        ...(numero > 1 ? { numero: 2 as const, oggetto: 'riparto' as const } : rendiconto.value ? { oggetto: 'rendiconto' as const } : {}),
        ...(usufruttuari.length ? { usufruttuari } : {}),
    });
    // Ciò che ha detto l'usufruttuario va nel verbale come dichiarazione.
    for (const [u, s] of Object.entries(scelte.value)) {
        if (!s.detto) continue;
        const chi = personaDiRuolo(Number(u), 'usufruttuario');
        if (chi !== null) await segna('dichiarazione', { seduta_id: sedutaId.value, punto_id: props.punto.id, anagrafica_id: chi, testo: s.detto });
    }
    scelte.value = {};
}
/** «Trattato» segnato e non ancora arrivato. («Si vota» senza rete apre subito la votazione su questo dispositivo, e i voti si segnano lo stesso.) */
const trattatoInCoda = computed(() => inCoda.value.some((g) => g.tipo === 'trattato' && (g.dati as { punto_id?: number }).punto_id === props.punto.id));
async function trattato() {
    if (sedutaId.value === null) return;
    await segna('trattato', { seduta_id: sedutaId.value, punto_id: props.punto.id });
}

// ─── Il voto ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
/** La votazione aperta senza rete su questo dispositivo: il numero lo darà il server. */
const nataQui = computed(() => (votazione.value?.id ?? 0) < 0);
const voti = computed<VotoConLaCoda[]>(() => (votazione.value ? votazione.value.voti.map((x) => ({ ...x,
    inCoda: sala.value.in_coda.includes(`voto:${votazione.value!.id}:${x.testa}`) })) : []));
const discordeAstenuto = computed(() => sala.value.impostazioni.voto_discorde_comproprietari !== 'decide_il_presidente');
const conteggi = computed(() => (votazione.value ? conteggiSulPosto(votazione.value, voti.value, discordeAstenuto.value) : null));
const inCodaQui = computed(() => votazione.value !== null && (nataQui.value
    || inCoda.value.some((g) => eDellaVotazione(g.dati as Record<string, unknown>, votazione.value!))));
const proposta = computed(() => (inCodaQui.value || !votazione.value ? conteggi.value?.esito ?? null : votazione.value.esito_proposto ?? conteggi.value?.esito ?? null));
/** Le due condizioni dell'esito: quelle del server; con un gesto del voto ancora qui (o nei conteggi scritti prima che ci fossero), quelle contate sul posto. */
const condizioni = computed(() => {
    const delServer = inCodaQui.value ? undefined : votazione.value?.conteggi?.condizioni;

    return delServer !== undefined ? delServer : conteggi.value?.condizioni ?? null;
});
const sceltaAperta = computed(() => votazione.value !== null && sala.value.conflitti.some((c) => c.su === `votazione:${votazione.value!.id}`));
const avvisoNumeroLegale = computed(() => (props.costituzione && !props.costituzione.costituita
    ? `L'assemblea non risulta costituita (${props.costituzione.barra}; ${props.costituzione.servono}): decide il presidente. Puoi scrivere una nota per il verbale.`
    : null));

/** I nomi delle unità come li scrive la sala, con la maiuscola: «Int. 3», «box 21». */
const nomiDelleUnita = (unita: number[]) => unita.map((u) => sala.value.fotografia?.unita[String(u)]?.nome ?? '').filter(Boolean);
/** Chi vota per la testa e per quale unità: «rappresenta l'Int. 3»; gli altri ruoli «nudo proprietario, Int. 9». Il proprietario non ha la riga. */
function chiEPerQualeUnita(ruolo: string, unita: number[]): string | null {
    if (ruolo === 'proprietario') return null;
    const nomi = nomiDelleUnita(unita);
    if (ruolo === 'rappresentante') return nomi.length ? `rappresenta l'${elencoDeiNomi(nomi)}` : 'rappresenta l\'unità';

    return `${RUOLI[ruolo] ?? ruolo}${nomi.length ? `, ${nomi.join(', ')}` : ''}`;
}
const righe = computed(() => (votazione.value?.intervenuti.teste ?? []).map((t) => {
    const v = voti.value.find((x) => x.testa === t.chiave) ?? null;
    const delega = t.chi.ruolo === 'delegato' ? `per delega a ${t.chi.nome}` : null;
    const scelta = votazione.value?.scelte?.usufruttuari?.find((s) => t.unita.includes(s.immobile_id));
    const nome = t.chi.ruolo === 'delegato' ? t.nome : t.chi.nome;

    return { testa: t, nome, nota: delega ?? (scelta ? `${scelta.frase}` : chiEPerQualeUnita(t.chi.ruolo, t.unita)), voto: v, comproprietari: t.persone.length > 1,
        // Dove cerca la ricerca: chi vota, la testa (anche i nomi di chi non vota), il delegato, le unità.
        perLaRicerca: [nome, t.nome, t.chi.nome, ...nomiDelleUnita(t.unita)] };
}));

// La ricerca sopra i voti, da otto persone in su: filtra le righe che la pagina ha già, sul posto e senza rete.
const cerca = ref('');
const conLaRicerca = computed(() => righe.value.length >= DA_QUANTI_SI_CERCA);
const cercando = computed(() => conLaRicerca.value && paroleDellaRicerca(cerca.value).length > 0);
const righeViste = computed(() => (cercando.value ? righe.value.filter((r) => rispondeAllaRicerca(cerca.value, r.perLaRicerca)) : righe.value));

const SCELTE: { valore: Exclude<SceltaDiVoto, 'discorde'>; etichetta: string; acceso: string }[] = [
    { valore: 'favorevole', etichetta: 'Favorevole', acceso: 'border-emerald-600 bg-emerald-600 text-white dark:border-emerald-500 dark:bg-emerald-600' },
    { valore: 'contrario', etichetta: 'Contrario', acceso: 'border-rose-600 bg-rose-600 text-white dark:border-rose-500 dark:bg-rose-600' },
    { valore: 'astenuto', etichetta: 'Astenuto', acceso: 'border-slate-700 bg-slate-700 text-white dark:border-slate-300 dark:bg-slate-300 dark:text-slate-900' },
];

async function tuttiFavorevoli() {
    if (!votazione.value) return;
    await segna('tutti_favorevoli', laVotazioneDelGesto(votazione.value));
}

// Un voto confermato si corregge con il perché.
const finestraPerche = ref(false);
const correzione = ref<{ testa: string; voto: SceltaDiVoto; decisione?: Exclude<SceltaDiVoto, 'discorde'> } | null>(null);
async function vota(testa: string, voto: SceltaDiVoto, decisione?: Exclude<SceltaDiVoto, 'discorde'>, perche?: string) {
    if (!votazione.value) return;
    const attuale = voti.value.find((x) => x.testa === testa);
    if (attuale && attuale.voto === voto && (attuale.decisione ?? undefined) === decisione) return;
    if (confermata.value && !perche) {
        correzione.value = { testa, voto, decisione };
        finestraPerche.value = true;
        return;
    }
    await segna('voto', { ...laVotazioneDelGesto(votazione.value), testa, voto, ...(decisione ? { decisione } : {}), ...(perche ? { perche } : {}) },
        { base: attuale?.versione ?? 0 });
}
function corretto(perche: string) {
    if (correzione.value) void vota(correzione.value.testa, correzione.value.voto, correzione.value.decisione, perche);
}

// Il discorde dei comproprietari: astenuto di serie, o come lo decide il presidente.
const finestraDiscorde = ref(false);
const testaDiscorde = ref<string | null>(null);
const DECISIONI: Opzione[] = [{ id: 1, etichetta: 'Conta favorevole' }, { id: 2, etichetta: 'Conta contrario' }, { id: 3, etichetta: 'Conta astenuto' }];
function discorde(testa: string) {
    if (discordeAstenuto.value) {
        void vota(testa, 'discorde');
        return;
    }
    testaDiscorde.value = testa;
    finestraDiscorde.value = true;
}
function decisoIlDiscorde(_t: string, chi: number | null) {
    const decisione = chi === 1 ? 'favorevole' : chi === 2 ? 'contrario' : 'astenuto';
    if (testaDiscorde.value) void vota(testaDiscorde.value, 'discorde', decisione);
}

// Il conflitto d'interessi: si segna con la nota, si toglie con un clic.
const finestraConflitto = ref(false);
const testaInConflitto = ref<string | null>(null);
function conflitto(r: (typeof righe.value)[number]) {
    if (!votazione.value || !r.voto) return;
    if (r.voto.in_conflitto) {
        void segna('conflitto', { ...laVotazioneDelGesto(votazione.value), testa: r.testa.chiave, in_conflitto: false });
        return;
    }
    testaInConflitto.value = r.testa.chiave;
    finestraConflitto.value = true;
}
function segnatoIlConflitto(nota: string) {
    if (votazione.value && testaInConflitto.value) {
        void segna('conflitto', { ...laVotazioneDelGesto(votazione.value), testa: testaInConflitto.value, in_conflitto: true, nota });
    }
}

// Le note del presidente (decisione 594 (2) e (4)).
const finestraNota = ref(false);
const notaSu = ref<'voto' | 'non_votato'>('voto');
function scriviLaNota(su: 'voto' | 'non_votato') {
    notaSu.value = su;
    finestraNota.value = true;
}
function salvataLaNota(testo: string) {
    if (votazione.value) void segna('nota', { ...laVotazioneDelGesto(votazione.value), su: notaSu.value, testo });
}

// La dichiarazione a verbale.
const finestraDichiarazione = ref(false);
const persone = computed<Opzione[]>(() => Object.entries(sala.value.fotografia?.persone ?? {}).map(([id, p]) => ({ id: Number(id), etichetta: p.nome }))
    .sort((a, b) => Number(props.presenti.includes(b.id)) - Number(props.presenti.includes(a.id)) || a.etichetta.localeCompare(b.etichetta, 'it')));
function salvataLaDichiarazione(testo: string, chi: number | null) {
    if (sedutaId.value === null || chi === null) return;
    void segna('dichiarazione', { seduta_id: sedutaId.value, punto_id: props.punto.id, anagrafica_id: chi, testo });
}
/** Le dichiarazioni del punto; quelle segnate qui e non ancora arrivate hanno un numero provvisorio (negativo). */
const dichiarazioni = computed(() => sala.value.dichiarazioni.filter((d) => d.punto_id === props.punto.id));
type Dichiarazione = SalaDellAssemblea['dichiarazioni'][number] & NatoSenzaRete;
/** «Modifica» e «Togli» finché il voto del punto non è confermato (la regola del server). */
const siCorreggono = computed(() => gestisce.value && !confermata.value);
const inModifica = ref<Dichiarazione | null>(null);
const finestraModifica = ref(false);
function modifica(d: Dichiarazione) {
    inModifica.value = d;
    finestraModifica.value = true;
}
function modificata(testo: string) {
    if (inModifica.value) void segna('dichiarazione_cambia', { dichiarazione_id: idPerIlGesto(inModifica.value) as number, testo });
}
const daTogliere = ref<Dichiarazione | null>(null);
function togli() {
    if (daTogliere.value) void segna('dichiarazione_togli', { dichiarazione_id: idPerIlGesto(daTogliere.value) as number });
    daTogliere.value = null;
}

// La conferma.
const finestraConferma = ref(false);
async function conferma(esito: 'approvata' | 'approvata_con_modifiche' | 'respinta' | 'rinviata', motivo = '', testo = '', amministratore = '') {
    if (!votazione.value) return;
    // Per la votazione aperta qui senza rete la versione non si controlla: l'ha aperta questo dispositivo, e il server la riconosce dal gesto «Si vota».
    await segna('conferma', { ...laVotazioneDelGesto(votazione.value), esito, ...(motivo ? { motivo } : {}), ...(testo ? { testo } : {}),
        ...(nomina.value && amministratore ? { amministratore } : {}) },
        { base: nataQui.value ? null : baseDellaVotazione(votazione.value, inCoda.value) });
}
/** Un punto di nomina: la finestra chiede il nome del nuovo amministratore, anche con l'esito proposto. */
const nomina = computed(() => props.punto.materia === 'nomina');
const nominato = computed(() => votazione.value?.scelte?.nomina?.amministratore ?? null);
function confermaLaProposta() {
    const p = proposta.value;
    if (!nomina.value && (p === 'approvata' || p === 'respinta')) void conferma(p);
    else finestraConferma.value = true;
}

// La discussione del punto: ogni cambio va sul disco del browser e parte dopo la pausa (`scriviLaDiscussione` della sala, la stessa tenuta del verbale);
// la base è la revisione arrivata dal server, la prima volta con la seduta, senza verbale.
const discussione = computed(() => sala.value.verbale?.discussioni?.[String(props.punto.id)] ?? null);
/** Scritto qui e non ancora arrivato: finché il server non dà una revisione nuova della discussione. */
const scrittoQui = ref(false);
watch(() => discussione.value?.revisione, () => { scrittoQui.value = false; });
function scriviIlTesto(documento: Parameters<typeof scriviLaDiscussione>[1]) {
    scrittoQui.value = true;
    scriviLaDiscussione(props.punto.id, documento);
}
const scritta = computed(() => (inCoda.value.some((g) => g.tipo === 'testo' && (g.dati as { parte?: string }).parte === `punto:${props.punto.id}`)
    ? { testo: 'Su questo computer', dettaglio: 'parte appena c\'è la rete' }
    : scrittoQui.value ? { testo: 'Su questo computer', dettaglio: 'parte pochi secondi dopo che smetti di scrivere' }
        : discussione.value ? { testo: 'Salvato', dettaglio: 'tutto sul server' } : null));

const pulsante = 'inline-flex h-8 items-center justify-center rounded-md border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
/** I pulsanti del voto di una persona: alti 28 px, a 12, con poco spazio ai lati, perché ne stiano cinque su una riga. */
const delVoto = 'inline-flex h-7 items-center justify-center whitespace-nowrap rounded-md border px-2 text-xs font-medium disabled:cursor-not-allowed';
const delVotoSpento = 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
const principale = 'inline-flex h-9 items-center justify-center rounded-md bg-slate-900 px-3 text-sm font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-slate-900';
</script>

<template>
    <section class="space-y-3 rounded-xl border border-slate-200 bg-white px-4 py-4 dark:border-slate-800 dark:bg-slate-950" data-testid="voto-del-punto">
        <div>
            <h3 class="break-words text-base font-semibold text-slate-900 dark:text-white">{{ punto.numero }}. {{ punto.testo }}</h3>
            <p v-if="punto.motivazione" class="mt-1 break-words text-[13px] text-slate-700 dark:text-slate-300" data-testid="motivazione-del-punto">{{ punto.motivazione }}</p>
            <p class="text-xs text-slate-600 dark:text-slate-400">{{ regola }}</p>
            <p v-if="punto.perimetro?.avviso" class="mt-1 text-xs text-amber-800 dark:text-amber-300">{{ punto.perimetro.avviso }}</p>
            <!-- Il punto di una parte (la sola scala, il gruppo di una tabella; Coda 734): chi vota, e chi altro è in sala non vota su questo punto. -->
            <p v-if="punto.perimetro?.chi_vota" class="mt-1.5 rounded-md border border-sky-200 bg-sky-50 px-2.5 py-1.5 text-xs text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200"
                data-testid="chi-vota-sul-punto">{{ punto.perimetro.chi_vota }}: chi altro è in sala non vota su questo punto.</p>
        </div>

        <!-- Il punto senza voto -->
        <div v-if="senzaVoto" class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm text-slate-700 dark:text-slate-300">
                {{ votazione ? `Trattato alle ${oraDiRoma(votazione.votata_il)}.` : 'Il punto si tratta, senza voto.' }}
            </p>
            <button v-if="gestisce && !votazione" type="button" :class="PRINCIPALE_PICCOLO" :disabled="trattatoInCoda" data-testid="trattato" @click="trattato">Trattato</button>
        </div>

        <!-- Prima di «Si vota» -->
        <template v-else-if="ancoraDaVotare">
            <p v-if="votazione && rendiconto" class="text-xs text-slate-600 dark:text-slate-400">Il rendiconto è confermato: adesso si vota il riparto.</p>
            <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-slate-800 dark:border-slate-800" data-testid="chi-vota">
                <li v-for="r in chiVota" :key="r.testa.chiave" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                    <span class="min-w-0">
                        <span class="font-medium text-slate-900 dark:text-white">{{ r.nome }}</span>
                        <span v-if="r.scelta" :class="[BADGE, CLASSI_DEL_BADGE.viola, 'ml-1.5']">Scelta del presidente</span>
                        <span class="block text-xs text-slate-500 dark:text-slate-400">
                            {{ RUOLI[r.ruolo] ?? r.ruolo }}<template v-if="r.unitaUsufrutto !== null">, {{ sala.fotografia?.unita[String(r.unitaUsufrutto)]?.nome }}:
                                {{ r.scelta ? fraseDelPerche(r.scelta.perche) : 'punto straordinario' }}</template>
                            · {{ millesimi(r.testa.millesimi) }}<template v-if="!r.presente"> · non è in sala</template>
                        </span>
                    </span>
                    <template v-if="gestisce && r.unitaUsufrutto !== null">
                        <button v-if="!r.scelta" type="button" :class="pulsante" data-testid="vota-usufruttuario" @click="votaLUsufruttuario(r.unitaUsufrutto)">Vota l'usufruttuario</button>
                        <button v-else type="button" :class="pulsante" data-testid="torna-al-nudo" @click="tornaAlNudo(r.unitaUsufrutto)">Torna al nudo proprietario</button>
                    </template>
                </li>
                <li v-if="chiVota.length === 0" class="px-3 py-2 text-xs text-slate-500 dark:text-slate-400">Nessuno di chi vota su questo punto è in sala.</li>
            </ul>
            <p v-if="avvisoNumeroLegale" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800
                dark:bg-amber-950/40 dark:text-amber-200"><AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ avvisoNumeroLegale }}</p>
            <div v-if="gestisce" class="flex justify-end">
                <button type="button" :class="[PRINCIPALE_PICCOLO, 'w-full sm:w-auto']" data-testid="si-vota" @click="siVota">{{ votazione && rendiconto ? 'Si vota il riparto' : 'Si vota' }}</button>
            </div>
        </template>

        <!-- Il voto -->
        <template v-else-if="votazione && conteggi">
            <p class="text-xs text-slate-500 dark:text-slate-400">
                {{ votazione.oggetto === 'riparto' ? 'Il riparto · ' : votazione.oggetto === 'rendiconto' ? 'Il rendiconto · ' : '' }}Votato alle {{ oraDiRoma(votazione.votata_il) }} ·
                {{ votazione.intervenuti.numero }} intervenuti<template v-if="confermata"> · confermato alle {{ oraDiRoma(votazione.confermata_il) }}</template>
                <SuQuestoComputer v-if="nataQui" class="ml-1" :dispositivo="dispositivo" />
            </p>
            <p v-if="nataQui" class="text-xs text-slate-600 dark:text-slate-400" data-testid="votato-senza-rete">
                Votato senza rete: «Si vota», i voti e la conferma partono da soli quando torna la rete, nell'ordine in cui li segni.
            </p>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4" data-testid="conteggi">
                <div class="rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-800"><p class="text-xs text-slate-500 dark:text-slate-400">Favorevoli</p>
                    <p class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{{ conteggi.favorevoli.teste }} · {{ millesimi(conteggi.favorevoli.valore) }}</p></div>
                <div class="rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-800"><p class="text-xs text-slate-500 dark:text-slate-400">Contrari</p>
                    <p class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{{ conteggi.contrari.teste }} · {{ millesimi(conteggi.contrari.valore) }}</p></div>
                <div class="rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-800"><p class="text-xs text-slate-500 dark:text-slate-400">Astenuti</p>
                    <p class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{{ conteggi.astenuti.teste }} · {{ millesimi(conteggi.astenuti.valore) }}</p></div>
                <div class="rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-800"><p class="text-xs text-slate-500 dark:text-slate-400">{{ confermata ? 'Esito' : 'Proposta' }}</p>
                    <p class="text-sm font-semibold"><span :class="[BADGE, CLASSI_DEL_BADGE[coloreDellEsito(confermata ? votazione.esito : proposta)]]">
                        {{ confermata ? parolaDellEsito(votazione.esito) : proposta ? parolaDellEsito(proposta) : 'Decide il presidente' }}</span></p></div>
            </div>
            <ul v-if="condizioni" class="space-y-0.5 text-xs text-slate-700 dark:text-slate-300" data-testid="condizioni">
                <li v-for="c in condizioni" :key="c.condizione" class="flex items-start gap-1.5" :data-passa="String(c.passa)">
                    <Check v-if="c.passa" class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                    <X v-else class="mt-0.5 h-3.5 w-3.5 shrink-0 text-rose-600 dark:text-rose-400" aria-hidden="true" />
                    <span>{{ c.frase }}</span>
                </li>
            </ul>
            <template v-else>
                <!-- Una maggioranza senza condizioni (decide il presidente): i conti dei favorevoli come gli altri punti, senza il sì o il no, e sotto la frase. -->
                <ul v-if="conteggi.condizioni === null" class="space-y-0.5 text-xs text-slate-700 dark:text-slate-300" data-testid="conti-senza-soglia">
                    <li v-for="c in conteggi.conti" :key="c.condizione" class="flex items-start gap-1.5">
                        <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-slate-400 dark:bg-slate-500" aria-hidden="true" />
                        <span>{{ c.frase }}</span>
                    </li>
                </ul>
                <p v-if="!inCodaQui && votazione.conteggi?.frase" class="text-xs text-slate-600 dark:text-slate-400">{{ votazione.conteggi.frase }}</p>
            </template>
            <p v-if="conteggi.non_votato.teste > 0" class="text-xs text-slate-600 dark:text-slate-400" data-testid="senza-voto">
                Senza voto, contati a parte: {{ conteggi.non_votato.nomi.map((n) => n.nome).join(', ') }}.
            </p>
            <p v-if="votazione.conflitto_determinante && votazione.conteggi?.conflitto" class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-900
                dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200">
                {{ votazione.conteggi.conflitto }} La delibera vale, ma è impugnabile se c'è un danno anche solo potenziale (Cass. 1853/2018). Il verbale lo scrive.
            </p>

            <div v-if="gestisce && !confermata" class="flex justify-stretch sm:justify-start">
                <button type="button" :class="[principale, 'w-full sm:w-auto']" data-testid="tutti-favorevoli" @click="tuttiFavorevoli">Tutti i presenti favorevoli</button>
            </div>
            <!-- I voti: la riga si dispone sulla larghezza dell'elenco (`@container`), non dello schermo: nella sala a due colonne c'è metà pagina. Da 36 rem
                 il nome a sinistra e i pulsanti a destra; più stretta, i pulsanti in una riga sotto il nome. -->
            <div class="@container space-y-2">
                <CercaNellElenco v-if="conLaRicerca" v-model="cerca" etichetta="Cerca per nome o per unità" testid="cerca-nei-voti" testid-svuota="svuota-ricerca-voti" />
                <p v-if="cercando && righeViste.length === 0" class="text-sm text-slate-600 dark:text-slate-300" role="status" data-testid="ricerca-vuota-voti">
                    Nessuno per «{{ cerca.trim() }}»: prova con il cognome o con il numero dell'unità.
                </p>
                <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-slate-800 dark:border-slate-800" :class="{ hidden: righeViste.length === 0 }"
                    data-testid="voti">
                    <li v-for="r in righeViste" :key="r.testa.chiave" class="flex flex-col gap-2 px-3 py-2 @xl:flex-row @xl:items-center @xl:justify-between"
                        :data-voto-testa="r.testa.chiave">
                        <span class="min-w-0 @xl:flex-1">
                            <span class="font-medium text-slate-900 dark:text-white">{{ r.nome }}</span>
                            <span v-if="r.voto?.in_conflitto" :class="[BADGE, CLASSI_DEL_BADGE.rosso, 'ml-1.5']">In conflitto</span>
                            <SuQuestoComputer v-if="r.voto?.inCoda && !nataQui" class="ml-1.5" :dispositivo="dispositivo" />
                            <span class="block text-xs text-slate-500 dark:text-slate-400">
                                <template v-if="r.nota">{{ r.nota }} · </template>{{ millesimi(r.testa.millesimi) }}
                                <template v-if="r.voto?.nota_conflitto"> · {{ r.voto.nota_conflitto }}</template>
                            </span>
                        </span>
                        <!-- Tutti i pulsanti in un gruppo: stretta, il gruppo intero va sotto il nome (e va a capo dentro di sé), mai a metà accanto al nome. -->
                        <span class="flex flex-wrap gap-1.5 @xl:shrink-0 @xl:flex-nowrap @xl:justify-end">
                            <button v-for="s in SCELTE" :key="s.valore" type="button" :class="[delVoto, r.voto?.voto === s.valore ? s.acceso : delVotoSpento]"
                                :aria-pressed="r.voto?.voto === s.valore ? 'true' : 'false'" :disabled="!gestisce" :data-testid="s.valore" @click="vota(r.testa.chiave, s.valore)">
                                {{ s.etichetta }}
                            </button>
                            <button v-if="r.comproprietari && gestisce" type="button" :class="[delVoto, delVotoSpento]" :aria-pressed="r.voto?.voto === 'discorde' ? 'true' : 'false'"
                                title="I comproprietari presenti votano in modo diverso" data-testid="discorde" @click="discorde(r.testa.chiave)">Discorde</button>
                            <button v-if="gestisce && r.voto && !confermata" type="button" :class="[delVoto, delVotoSpento]" :aria-pressed="r.voto.in_conflitto ? 'true' : 'false'"
                                data-testid="in-conflitto" @click="conflitto(r)">{{ r.voto.in_conflitto ? 'Togli il conflitto' : 'In conflitto' }}</button>
                        </span>
                    </li>
                </ul>
            </div>

            <!-- Le note e la dichiarazione -->
            <div class="space-y-1 text-xs text-slate-700 dark:text-slate-300">
                <p v-if="votazione.nota" data-testid="la-nota"><b class="font-semibold">Nota del presidente:</b> {{ votazione.nota }}</p>
                <p v-if="votazione.nota_non_votato" data-testid="la-nota-non-votato"><b class="font-semibold">Su chi non ha votato:</b> {{ votazione.nota_non_votato }}</p>
                <!-- Il motivo di «Un altro esito», con le parole della sala chiusa (rilievo C55). -->
                <p v-if="votazione.motivo" data-testid="il-motivo"><b class="font-semibold">Il motivo dell'esito:</b> {{ votazione.motivo }}</p>
                <p v-if="nomina && nominato" data-testid="nuovo-amministratore-detto"><b class="font-semibold">Il nuovo amministratore:</b> {{ nominato }}</p>
            </div>

            <!-- Le dichiarazioni a verbale: una riga ciascuna, con «Modifica» e «Togli» finché il voto non è confermato (decisione 664 (7)) -->
            <ul v-if="dichiarazioni.length > 0" class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-xs dark:divide-slate-800 dark:border-slate-800"
                data-testid="dichiarazioni-del-punto">
                <li v-for="d in dichiarazioni" :key="d.id" class="flex flex-col gap-1.5 px-3 py-2 sm:flex-row sm:items-start sm:justify-between" :data-dichiarazione="d.id">
                    <p class="min-w-0 break-words text-slate-700 dark:text-slate-300">
                        <b class="font-semibold text-slate-900 dark:text-white">Dichiarazione a verbale · {{ d.nome || nomeDi(d.anagrafica_id) }}:</b> «{{ d.testo }}»
                        <SuQuestoComputer v-if="d.id < 0" :dispositivo="dispositivo" />
                    </p>
                    <span v-if="siCorreggono" class="flex shrink-0 gap-1.5">
                        <button type="button" :class="PICCOLO" data-testid="modifica-dichiarazione" @click="modifica(d)">Modifica</button>
                        <button type="button" :class="PICCOLO" data-testid="togli-dichiarazione" @click="daTogliere = d">Togli</button>
                    </span>
                </li>
            </ul>

            <p v-if="avvisoNumeroLegale && !confermata" class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900
                dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200" data-testid="avviso-numero-legale">
                <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ avvisoNumeroLegale }}
            </p>
            <p v-if="sceltaAperta" class="text-xs text-amber-800 dark:text-amber-300">Due versioni su questo voto: finché non scegli, il punto non si conferma.</p>

            <!-- La riga del voto: tutti alti 28 px; la conferma scura a destra, al telefono a tutta riga in fondo (decisione 664 (4)) -->
            <div v-if="gestisce" class="flex flex-wrap items-center gap-1.5" data-testid="riga-del-voto">
                <button type="button" :class="PICCOLO" data-testid="nota-voto" @click="scriviLaNota('voto')">{{ votazione.nota ? 'Cambia la nota sul voto' : 'Nota sul voto' }}</button>
                <button v-if="conteggi.non_votato.teste > 0 || votazione.nota_non_votato" type="button" :class="PICCOLO" data-testid="nota-non-votato"
                    @click="scriviLaNota('non_votato')">Nota su chi non ha votato</button>
                <button type="button" :class="PICCOLO" data-testid="dichiarazione" @click="finestraDichiarazione = true">Dichiarazione a verbale</button>
                <template v-if="!confermata">
                    <button type="button" :class="PICCOLO" data-testid="altro-esito" @click="finestraConferma = true">Un altro esito</button>
                    <button type="button" :class="[PRINCIPALE_PICCOLO, 'w-full sm:ml-auto sm:w-auto']" :disabled="sceltaAperta" data-testid="conferma-voto" @click="confermaLaProposta">
                        {{ proposta === 'approvata' || proposta === 'respinta' ? `Conferma: ${parolaDellEsito(proposta).toLowerCase()}` : 'Conferma l\'esito…' }}
                    </button>
                </template>
            </div>
        </template>

        <!-- La discussione del punto, sotto il voto: la stessa della pagina «Il verbale». -->
        <DiscussioneDelPunto v-if="sala.verbale && (votazione || senzaVoto)" :numero="punto.numero" :documento="discussione?.documento ?? null"
            :solo-lettura="!gestisce" :scritta="scritta" @scrivi="scriviIlTesto" />

        <FinestraDellUsufruttuario v-if="perLaFinestra" v-model:open="finestraUsufrutto" :numero="punto.numero" :unita="perLaFinestra.unita"
            :usufruttuario="perLaFinestra.usufruttuario" :nudo="perLaFinestra.nudo" :millesimi="perLaFinestra.millesimi" @scelto="sceltoLUsufruttuario" />
        <FinestraDiTesto v-model:open="finestraConflitto" titolo="In conflitto d'interessi" etichetta="La nota (facoltativa)" :obbligatorio="false"
            descrizione="Il conflitto non toglie nessuno dal conto: il programma dice se il voto è stato determinante."
            esempio="Per esempio: titolare di uno dei preventivi." pulsante="Segna in conflitto" testid-finestra="finestra-conflitto" testid-testo="nota-conflitto"
            testid-conferma="conferma-conflitto" @salva="segnatoIlConflitto" />
        <FinestraDiTesto v-model:open="finestraNota" :titolo="notaSu === 'voto' ? 'La nota del presidente sul voto' : 'La nota del presidente su chi non ha votato'"
            etichetta="La nota" :testo="notaSu === 'voto' ? votazione?.nota : votazione?.nota_non_votato" :obbligatorio="false"
            descrizione="Va nel verbale. Il testo vuoto la toglie." :esempio="notaSu === 'voto' ? 'Per esempio: si vota con i presenti, lo decide il presidente.' : 'Per esempio: due persone erano uscite un momento.'"
            pulsante="Salva la nota" testid-finestra="finestra-nota" testid-testo="testo-nota" testid-conferma="salva-nota" @salva="salvataLaNota" />
        <FinestraDiTesto v-model:open="finestraDichiarazione" titolo="Dichiarazione a verbale" etichetta="La dichiarazione" :persone="persone"
            descrizione="Una breve dichiarazione, che il verbale riporta (art. 1130 n. 7 c.c.)." pulsante="Salva la dichiarazione" testid-finestra="finestra-dichiarazione"
            testid-testo="testo-dichiarazione" testid-conferma="salva-dichiarazione" testid-chi="chi-dichiara" @salva="salvataLaDichiarazione" />
        <FinestraDiTesto v-model:open="finestraModifica" titolo="Cambia la dichiarazione a verbale" etichetta="La dichiarazione" :testo="inModifica?.testo ?? ''"
            :descrizione="`Di ${inModifica?.nome || nomeDi(inModifica?.anagrafica_id ?? null)}. Il verbale la riporta com'è.`" pulsante="Salva la dichiarazione"
            testid-finestra="finestra-modifica-dichiarazione" testid-testo="testo-modifica-dichiarazione" testid-conferma="salva-modifica-dichiarazione"
            @salva="modificata" />
        <Dialog :open="daTogliere !== null" @update:open="(v: boolean) => { if (!v) daTogliere = null; }">
            <DialogContent class="sm:max-w-md" data-testid="finestra-togli-dichiarazione" @open-auto-focus.prevent>
                <DialogHeader>
                    <DialogTitle>Togliere la dichiarazione di {{ daTogliere?.nome || nomeDi(daTogliere?.anagrafica_id ?? null) }}?</DialogTitle>
                    <DialogDescription>Il verbale non la riporta più. Il diario lo scrive.</DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button variant="outline" size="sm" @click="daTogliere = null">Annulla</Button>
                    <Button size="sm" data-testid="conferma-togli-dichiarazione" @click="togli">Togli</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <FinestraDiTesto v-model:open="finestraPerche" titolo="Il voto è confermato" etichetta="Perché lo correggi" pulsante="Correggi il voto"
            descrizione="Il voto torna da confermare, e la conferma riallinea la stessa delibera." testid-finestra="finestra-perche" testid-testo="perche-correzione"
            testid-conferma="conferma-correzione" @salva="corretto" />
        <FinestraDiTesto v-model:open="finestraDiscorde" titolo="I comproprietari votano in modo diverso" etichetta="Il motivo (facoltativo)" :persone="DECISIONI" etichetta-chi="Come conta"
            :obbligatorio="false" descrizione="Decide il presidente come conta il voto dell'unità (impostazione dello studio)." pulsante="Segna"
            testid-finestra="finestra-discorde" testid-testo="motivo-discorde" testid-conferma="conferma-discorde" testid-chi="decisione-discorde" @salva="decisoIlDiscorde" />
        <FinestraDellaConferma v-model:open="finestraConferma" :numero="punto.numero" :proposto="proposta" :avviso="avvisoNumeroLegale"
            :conflitto-determinante="votazione?.conflitto_determinante ?? false" :nomina="nomina" @conferma="(e, m, t, n) => conferma(e, m, t, n)" />
    </section>
</template>
