<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Le persone della sala (scene 2 e 4 della bozza approvata della sala; scena 8 al telefono; decisione 664 (1) e (2)): una riga per testa, «Una persona è
 * una testa, anche con più unità; l'unità in comproprietà vale una testa con il suo rappresentante».
 *
 * - **Prima di «Apri l'assemblea»** (rilievo C48) è l'elenco dei convocati: «Convocati (26 teste)», le unità, i millesimi, «Convocato con» (il mezzo della
 *   convocazione, che il server aggiunge alla lettura della sala: «raccomandata», «PEC», «a mano», «raccomandata, una a testa» per i comproprietari con un
 *   avviso ciascuno); tutte le teste, senza «Assente» e senza gesti; in fondo, grigio, «Le presenze si segnano dopo «Apri l'assemblea».».
 * - **Aperta** (rilievo C49) ogni persona sta su una riga: lo stato con l'ora («Presente dalle 18:35», «In collegamento dalle …», «Per delega a …», «Uscita
 *   alle …», «Non in sala»), poi i gesti: per chi non è in sala «Entra», piccolo e scuro, e il «⋯» del programma con «In collegamento», «Delega» e l'entrata
 *   del delegato; per chi c'è «Esce», piccolo e chiaro (con il «⋯» per «Collegamento caduto» e, se due della stessa testa sono dentro, chi vota). Le unità e
 *   i gesti non vanno a capo. Chi entra con gli altri comproprietari della testa (presenti senza voto, art. 67 c. 2 disp. att.) li ha accanto: «con Russo
 *   Anna, senza voto». Una riga segnata qui e non ancora arrivata al server ha «Su questo computer». Di serie si vedono le teste che sono o sono state in
 *   sala; «Mostra tutti» le altre. Dal tablet in su una tabella, al telefono una scheda per persona con i gesti in fondo.
 *
 * Sopra, «Cerca per nome o per unità»: filtra tutte le teste, anche quelle che «Mostra tutti» tiene nascoste, per il nome, le persone, il conduttore e le
 * unità («Colombo», «colo», «12»). Filtra ciò che la pagina ha già e non chiede niente al server: vale anche senza rete. La × lo svuota; una ricerca senza
 * risultati lo dice.
 */
import { computed, ref } from 'vue';
import { MoreHorizontal, Search, X } from 'lucide-vue-next';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { BADGE, CLASSI_DEL_BADGE } from '@/lib/assemblee/assemblee';
import { millesimi } from '@/lib/assemblee/sala/conti';
import { paroleDellaRicerca, rispondeAllaRicerca } from '@/lib/assemblee/sala/cerca';
import type { ComePresente, DelegaDellaSala, PersonaDelGesto, TestaDellaFotografia } from '@/types/assemblee';
import { PICCOLO, PRINCIPALE_PICCOLO } from '@/components/assemblee/verbale/finestra';
import { useSala } from './contesto';
import { elencoDeiNomi, oraDiRoma, type Opzione, type PresenzaConLaCoda } from './sala';
import SuQuestoComputer from './rete/SuQuestoComputer.vue';
import { idPerIlGesto, type NatoSenzaRete } from '@/lib/senzaRete/sala';
import FinestraDellEntrata from './FinestraDellEntrata.vue';
import FinestraDellaDelega from './FinestraDellaDelega.vue';

const props = defineProps<{ presenze: PresenzaConLaCoda[]; presenti: number[] }>();
const { sala, segna, puoGestire, nomeDi, dispositivo } = useSala();

type Stato = 'in_sala' | 'in_collegamento' | 'per_delega' | 'uscita' | 'assente';

interface RigaDellaTesta {
    testa: TestaDellaFotografia;
    unita: string;
    stato: Stato;
    chi: string | null;
    ora: string;
    inCoda: boolean;
    note: string[];
    candidati: Opzione[];
    presente: PresenzaConLaCoda | null;
    delega: DelegaDellaSala | null;
    delegatoDaFar: DelegaDellaSala | null;
    /** Con che cosa è convocata la testa («raccomandata», «PEC», «raccomandata, una a testa»), prima dell'apertura. */
    convocatoCon: string | null;
    /** Dove cerca la ricerca: il nome della testa, le sue persone, il conduttore, le unità. */
    perLaRicerca: string[];
}

const aperta = computed(() => sala.value.seduta_id !== null);
const aperte = computed(() => props.presenze.filter((p) => p.uscita_il === null));
const deleghe = computed(() => sala.value.deleghe.filter((d) => d.stato === 'valida'));
const mezzi = computed(() => new Map(sala.value.teste.map((t) => [t.chiave, t.convocato_con ?? null])));

function riga(t: TestaDellaFotografia): RigaDellaTesta {
    const foto = sala.value.fotografia!;
    const unita = t.unita.map((u) => foto.unita[String(u)]?.nome ?? `unità ${u}`);
    const conduttori = t.unita.flatMap((u) => (foto.unita[String(u)]?.titolari ?? []).filter((x) => x.ruolo === 'inquilino').map((x) => x.anagrafica_id));
    const candidati = [...t.persone.map((p) => ({ id: p, etichetta: nomeDi(p) })),
        ...conduttori.filter((p) => !t.persone.includes(p)).map((p) => ({ id: p, etichetta: `${nomeDi(p)}, conduttore` }))];
    const diPersona = aperte.value.find((p) => p.anagrafica_id !== null && t.persone.includes(p.anagrafica_id)) ?? null;
    const delega = deleghe.value.find((d) => d.delegante_anagrafica_id !== null && t.persone.includes(d.delegante_anagrafica_id)) ?? null;
    const delegatoDentro = delega ? aperte.value.find((p) => p.chiave === delega.chiave_delegato) ?? null : null;
    const passate = props.presenze.filter((p) => p.anagrafica_id !== null && t.persone.includes(p.anagrafica_id));
    const note: string[] = [];
    if (t.persone.length > 1) {
        const chiRappresenta = aperte.value.find((p) => p.anagrafica_id !== null && t.persone.includes(p.anagrafica_id) && (p.rappresenta ?? []).length > 0);
        // Chi vota per l'unità, con il nome intero: una parola sola dava «rappresenta Verdi» per «Luca Verdi», o il nome per «Russo Anna».
        note.push(`comproprietari${chiRappresenta ? ` · vota ${chiRappresenta.nome}` : ''}`);
    } else if (t.unita.length > 1) {
        note.push('una testa per più unità');
    }
    // Gli altri comproprietari entrati con chi rappresenta l'unità: in sala, senza voto (art. 67 c. 2 disp. att.).
    if (diPersona?.insieme?.length) note.push(`con ${elencoDeiNomi(diPersona.insieme.map((x) => x.nome))}, senza voto`);
    const rappresentati = deleghe.value.filter((d) => d.delegato_anagrafica_id !== null && t.persone.includes(d.delegato_anagrafica_id));
    if (rappresentati.length) note.push(`rappresenta ${elencoDeiNomi(rappresentati.map((d) => d.delegante_nome))}`);
    if (diPersona?.caduto === false && diPersona.come === 'in_collegamento' && diPersona.motivo_collegamento) note.push(diPersona.motivo_collegamento);
    const ultima = passate.at(-1);
    if (!diPersona && ultima?.caduto) note.push(`collegamento caduto alle ${oraDiRoma(ultima.uscita_il)}`);
    if (conduttori.length && !t.persone.some((p) => conduttori.includes(p))) note.push(`conduttore: ${conduttori.map((p) => nomeDi(p)).join(', ')}`);
    // Chi è entrato dopo un voto non conta per quel punto (gli intervenuti sono fermati al momento del voto).
    if (diPersona) {
        const dopoIlVoto = sala.value.punti.flatMap((p) => p.votazioni.map((v) => ({ p, v })))
            .filter(({ v }) => v.seduta_id === sala.value.seduta_id && v.votata_il < diPersona.entrata_il && !v.intervenuti.teste.some((x) => x.chiave === t.chiave)).at(-1);
        if (dopoIlVoto) {
            note.push(`entrato alle ${oraDiRoma(diPersona.entrata_il)}, dopo il voto del punto ${dopoIlVoto.p.numero} (${oraDiRoma(dopoIlVoto.v.votata_il)}): `
                + `per il punto ${dopoIlVoto.p.numero} non conta`);
        }
    }

    let stato: Stato = 'assente';
    let chi: string | null = null;
    let ora = '';
    if (diPersona) {
        stato = diPersona.come;
        chi = diPersona.nome;
        ora = oraDiRoma(diPersona.entrata_il);
    } else if (delega && delegatoDentro) {
        stato = 'per_delega';
        chi = delega.delegato_nome;
        ora = oraDiRoma(delegatoDentro.entrata_il);
    } else if (ultima) {
        stato = 'uscita';
        ora = oraDiRoma(ultima.uscita_il);
    }
    const inCodaQui = passate.some((p) => p.inCoda) || (delegatoDentro?.inCoda ?? false);

    return { testa: t, unita: unita.join(', '), stato, chi, ora, inCoda: inCodaQui, note, candidati, presente: diPersona, delega,
        delegatoDaFar: delega && !delegatoDentro && !t.persone.includes(delega.delegato_anagrafica_id ?? -1) ? delega : null,
        convocatoCon: mezzi.value.get(t.chiave) ?? null,
        perLaRicerca: [t.nome, ...t.persone.map((p) => nomeDi(p)), ...conduttori.map((p) => nomeDi(p)), ...unita] };
}

const righe = computed(() => (sala.value.fotografia?.costituzione.teste ?? []).map(riga));
const tutti = ref(false);
// Prima dell'apertura si vedono tutti i convocati: le presenze non ci sono ancora.
const viste = computed(() => (tutti.value || !aperta.value ? righe.value : righe.value.filter((r) => r.stato !== 'assente' || r.inCoda)));
const nascoste = computed(() => righe.value.length - viste.value.length);

// La ricerca: sul posto, fra tutte le teste, senza rete.
const cerca = ref('');
const campoDellaRicerca = ref<HTMLInputElement | null>(null);
const cercando = computed(() => paroleDellaRicerca(cerca.value).length > 0);
const trovate = computed(() => righe.value.filter((r) => rispondeAllaRicerca(cerca.value, r.perLaRicerca)));
const mostrate = computed(() => (cercando.value ? trovate.value : viste.value));
function svuota() {
    cerca.value = '';
    campoDellaRicerca.value?.focus();
}

const COLORE: Record<Stato, keyof typeof CLASSI_DEL_BADGE> = { in_sala: 'verde', in_collegamento: 'blu', per_delega: 'viola', uscita: 'neutro', assente: 'neutro' };
/** Lo stato con l'ora: «Presente dalle 18:35», «Per delega a Bianchi Giorgio», «Non in sala». */
function statoDi(r: RigaDellaTesta): string {
    switch (r.stato) {
        case 'in_sala': return `Presente dalle ${r.ora}`;
        case 'in_collegamento': return `In collegamento dalle ${r.ora}`;
        case 'per_delega': return `Per delega a ${r.chi ?? ''}`;
        case 'uscita': return `Uscita alle ${r.ora}`;
        default: return 'Non in sala';
    }
}
const fuori = (r: RigaDellaTesta) => r.stato === 'assente' || r.stato === 'uscita';

const conVideo = computed(() => ['videoconferenza', 'mista'].includes(sala.value.assemblea.modalita));
const chiaveDi = (p: PersonaDelGesto) => ('anagrafica_id' in p ? `anagrafica:${p.anagrafica_id}` : (sala.value.deleghe
    .find((d) => d.id === p.delega_id || (d as NatoSenzaRete).riferimento === (p.delega_id as unknown))?.chiave_delegato ?? `delega:${p.delega_id}`));
/** Il delegato fuori dall'anagrafica, per la sua delega: il numero del server, o il riferimento a una delega segnata senza rete (lo scioglie la coda). */
const perLaDelega = (d: DelegaDellaSala): PersonaDelGesto => ({ delega_id: idPerIlGesto(d) as number });

/** `con`: gli altri comproprietari della testa che entrano insieme, presenti senza voto (rilievo C53). */
async function entra(persona: PersonaDelGesto, come: ComePresente, motivo = '', con: number[] = []) {
    if (sala.value.seduta_id === null) return;
    await segna('entra', { seduta_id: sala.value.seduta_id, persona, come, ...(motivo ? { motivo } : {}), ...(con.length ? { con } : {}) },
        { base: sala.value.versioni[chiaveDi(persona)] ?? 0 });
}
async function esce(persona: PersonaDelGesto, caduto = false) {
    if (sala.value.seduta_id === null) return;
    await segna('esce', { seduta_id: sala.value.seduta_id, persona, ...(caduto ? { caduto: true } : {}) },
        { base: sala.value.versioni[chiaveDi(persona)] ?? 0 });
}

// L'entrata con la finestra: chi entra (più persone: chi rappresenta l'unità, e gli altri comproprietari presenti senza voto), o il motivo del collegamento.
const finestra = ref(false);
const perLaFinestra = ref<{ candidati: Opzione[]; persone: number[]; unita: string; come: ComePresente; serveIlMotivo: boolean; conTestaSua: number[] }>({
    candidati: [], persone: [], unita: '', come: 'in_sala', serveIlMotivo: false, conTestaSua: [] });
function chiediDiEntrare(r: RigaDellaTesta, come: ComePresente) {
    const serveIlMotivo = come === 'in_collegamento' && !conVideo.value;
    if (r.candidati.length === 1 && !serveIlMotivo) {
        void entra({ anagrafica_id: r.candidati[0].id }, come);
        return;
    }
    // Chi della testa ha anche un'unità tutta sua entra da sé, con la sua testa: la finestra non gli offre «insieme» (revisione del 22/11).
    const conTestaSua = r.testa.persone.filter((id) => sala.value.teste.some((t) => t.chiave !== r.testa.chiave && t.persone.includes(id)));
    perLaFinestra.value = { candidati: r.candidati, persone: [...r.testa.persone], unita: r.unita, come, serveIlMotivo, conTestaSua };
    finestra.value = true;
}
function entrato(chi: number, motivo: string, con: number[] = []) {
    void entra({ anagrafica_id: chi }, perLaFinestra.value.come, motivo, con);
}

// Chi rappresenta la comproprietà, quando sono presenti in più (due righe della stessa testa).
function rappresenta(r: RigaDellaTesta, chi: number) {
    if (sala.value.seduta_id === null) return;
    const inComune = r.testa.unita.filter((u) => (sala.value.fotografia?.unita[String(u)]?.titolari ?? []).filter((t) => t.ruolo !== 'inquilino').length > 1);
    void segna('rappresenta', { seduta_id: sala.value.seduta_id, persona: { anagrafica_id: chi }, unita: inComune });
}
const presentiDellaTesta = (r: RigaDellaTesta) => aperte.value.filter((p) => p.anagrafica_id !== null && r.testa.persone.includes(p.anagrafica_id))
    .map((p) => ({ id: p.anagrafica_id!, etichetta: p.nome }));
/** Il «⋯» di chi è in sala: «Collegamento caduto», e chi vota fra due della stessa testa. */
const conAltriGesti = (r: RigaDellaTesta) => r.stato === 'in_collegamento' || presentiDellaTesta(r).length > 1;

// La delega in sala.
const finestraDelega = ref(false);
const delegante = ref<Opzione | null>(null);
function delega(r: RigaDellaTesta) {
    delegante.value = r.candidati[0] ?? null;
    finestraDelega.value = true;
}

const voce = 'cursor-pointer text-xs';
const treP = 'inline-flex h-7 w-7 items-center justify-center rounded-md border border-slate-200 bg-white text-slate-600 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800';
</script>

<template>
    <section class="space-y-3" data-testid="presenze">
        <p class="text-xs text-slate-600 dark:text-slate-400">
            Una persona è una testa, anche con più unità; l'unità in comproprietà vale una testa con il suo rappresentante. Chi vota dipende dalla materia del punto.
        </p>

        <!-- La ricerca: a tutta riga al telefono; filtra sul posto, anche senza rete. -->
        <div v-if="righe.length > 0" class="relative w-full sm:max-w-xs">
            <Search class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400 dark:text-slate-500" aria-hidden="true" />
            <input ref="campoDellaRicerca" v-model="cerca" type="text" inputmode="search" enterkeyhint="search" autocomplete="off" spellcheck="false"
                placeholder="Cerca per nome o per unità" aria-label="Cerca per nome o per unità"
                class="h-10 w-full rounded-md border border-slate-200 bg-white pl-8 pr-10 text-base text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 sm:h-9 sm:text-sm dark:border-slate-700 dark:bg-slate-900 dark:text-white dark:placeholder:text-slate-500"
                data-testid="cerca-in-sala" @keydown.esc="svuota" />
            <button v-if="cerca" type="button" aria-label="Svuota la ricerca" data-testid="svuota-ricerca" @click="svuota"
                class="absolute right-1 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100">
                <X class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>
        <p v-if="cercando && trovate.length === 0" class="text-sm text-slate-600 dark:text-slate-300" role="status" data-testid="ricerca-vuota">
            Nessuno per «{{ cerca.trim() }}»: prova con il cognome o con il numero dell'unità.
        </p>

        <!-- Dal tablet in su: la tabella -->
        <div v-if="!cercando || trovate.length > 0" class="hidden overflow-x-auto rounded-xl border border-slate-200 bg-white sm:block dark:border-slate-800 dark:bg-slate-950"
            data-testid="presenze-tabella">
            <table class="w-full text-sm">
                <thead class="border-b border-slate-100 text-left text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <!-- Prima dell'apertura: l'elenco dei convocati -->
                    <tr v-if="!aperta">
                        <th class="px-3 py-2 font-medium">Convocati ({{ righe.length }} {{ righe.length === 1 ? 'testa' : 'teste' }})</th>
                        <th class="px-3 py-2 font-medium">Unità</th>
                        <th class="px-3 py-2 text-right font-medium">Millesimi</th>
                        <th class="px-3 py-2 font-medium">Convocato con</th>
                    </tr>
                    <tr v-else>
                        <th class="px-3 py-2 font-medium">Condòmino</th>
                        <th class="px-3 py-2 font-medium">Unità</th>
                        <th class="px-3 py-2 text-right font-medium">Millesimi</th>
                        <th class="px-3 py-2 font-medium">Come partecipa</th>
                        <th v-if="puoGestire" class="px-3 py-2 font-medium"><span class="sr-only">Azioni</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <tr v-for="r in mostrate" :key="r.testa.chiave" :data-testa="r.testa.chiave">
                        <td class="px-3 py-2 align-top">
                            <p class="font-medium text-slate-900 dark:text-white">{{ r.testa.nome }}</p>
                            <p v-for="n in r.note" :key="n" class="text-xs text-slate-500 dark:text-slate-400">{{ n }}</p>
                        </td>
                        <td class="whitespace-nowrap px-3 py-2 align-top text-slate-700 dark:text-slate-300" data-testid="unita-della-persona">{{ r.unita }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right align-top tabular-nums text-slate-700 dark:text-slate-300">{{ millesimi(r.testa.millesimi) }}</td>
                        <td v-if="!aperta" class="px-3 py-2 align-top text-slate-700 dark:text-slate-300" data-testid="convocato-con">{{ r.convocatoCon ?? '—' }}</td>
                        <template v-else>
                            <td class="whitespace-nowrap px-3 py-2 align-top">
                                <span :class="[BADGE, CLASSI_DEL_BADGE[COLORE[r.stato]]]" data-testid="stato-della-persona">{{ statoDi(r) }}</span>
                                <SuQuestoComputer v-if="r.inCoda" class="ml-1" :dispositivo="dispositivo" />
                            </td>
                            <td v-if="puoGestire" class="px-3 py-2 align-top">
                                <div class="flex flex-nowrap items-center justify-end gap-1.5" data-testid="gesti-della-persona">
                                    <template v-if="fuori(r)">
                                        <button type="button" :class="PRINCIPALE_PICCOLO" data-testid="entra" @click="chiediDiEntrare(r, 'in_sala')">Entra</button>
                                        <DropdownMenu :modal="false">
                                            <DropdownMenuTrigger as-child>
                                                <button type="button" :class="treP" :aria-label="`Altri gesti per ${r.testa.nome}`" data-testid="altri-gesti-apri"><MoreHorizontal class="h-4 w-4" /></button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end" class="w-56" data-testid="altri-gesti">
                                                <DropdownMenuItem :class="voce" data-testid="in-collegamento" @select="chiediDiEntrare(r, 'in_collegamento')">In collegamento</DropdownMenuItem>
                                                <DropdownMenuItem :class="voce" data-testid="delega" @select="delega(r)">Delega</DropdownMenuItem>
                                                <DropdownMenuItem v-if="r.delegatoDaFar" :class="voce" data-testid="entra-delegato"
                                                    @select="entra(r.delegatoDaFar.delegato_anagrafica_id ? { anagrafica_id: r.delegatoDaFar.delegato_anagrafica_id } : perLaDelega(r.delegatoDaFar), 'in_sala')">
                                                    Entra {{ r.delegatoDaFar.delegato_nome }}, con la delega
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </template>
                                    <template v-else-if="r.presente">
                                        <button type="button" :class="PICCOLO" data-testid="esce" @click="esce({ anagrafica_id: r.presente.anagrafica_id! })">Esce</button>
                                        <DropdownMenu v-if="conAltriGesti(r)" :modal="false">
                                            <DropdownMenuTrigger as-child>
                                                <button type="button" :class="treP" :aria-label="`Altri gesti per ${r.testa.nome}`" data-testid="altri-gesti-apri"><MoreHorizontal class="h-4 w-4" /></button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end" class="w-56" data-testid="altri-gesti">
                                                <DropdownMenuItem v-if="r.stato === 'in_collegamento'" :class="voce" data-testid="collegamento-caduto"
                                                    @select="esce({ anagrafica_id: r.presente.anagrafica_id! }, true)">Collegamento caduto</DropdownMenuItem>
                                                <DropdownMenuItem v-for="o in (presentiDellaTesta(r).length > 1 ? presentiDellaTesta(r) : [])" :key="o.id" :class="voce"
                                                    data-testid="chi-rappresenta" @select="rappresenta(r, o.id)">Vota per l'unità: {{ o.etichetta }}</DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </template>
                                    <button v-else-if="r.stato === 'per_delega' && r.delega && r.delega.delegato_anagrafica_id === null" type="button" :class="PICCOLO"
                                        data-testid="esce-delegato" @click="esce(perLaDelega(r.delega))">Esce {{ r.chi }}</button>
                                </div>
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Al telefono: una scheda per persona, con i gesti in fondo -->
        <ul class="space-y-2 sm:hidden" data-testid="presenze-card">
            <li v-for="r in mostrate" :key="r.testa.chiave" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 dark:border-slate-800 dark:bg-slate-950"
                :data-testa="r.testa.chiave">
                <div class="flex items-start justify-between gap-2">
                    <p class="min-w-0 break-words font-medium text-slate-900 dark:text-white">{{ r.testa.nome }}</p>
                    <span v-if="aperta" class="flex shrink-0 flex-wrap justify-end gap-1">
                        <span v-if="r.inCoda" :class="[BADGE, CLASSI_DEL_BADGE.ambra]">Su questo telefono</span>
                        <span :class="[BADGE, CLASSI_DEL_BADGE[COLORE[r.stato]]]">{{ statoDi(r) }}</span>
                    </span>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    {{ r.unita }} · {{ millesimi(r.testa.millesimi) }}<template v-if="!aperta"> · {{ r.convocatoCon ?? 'nessun avviso' }}</template>
                </p>
                <p v-for="n in r.note" :key="n" class="text-xs text-slate-500 dark:text-slate-400">{{ n }}</p>
                <div v-if="puoGestire && aperta && (fuori(r) || r.presente)" class="mt-2 flex items-center gap-1.5" data-testid="gesti-della-persona">
                    <template v-if="fuori(r)">
                        <button type="button" :class="[PRINCIPALE_PICCOLO, 'flex-1']" @click="chiediDiEntrare(r, 'in_sala')">Entra</button>
                        <DropdownMenu :modal="false">
                            <DropdownMenuTrigger as-child>
                                <button type="button" :class="treP" :aria-label="`Altri gesti per ${r.testa.nome}`"><MoreHorizontal class="h-4 w-4" /></button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-56">
                                <DropdownMenuItem :class="voce" @select="chiediDiEntrare(r, 'in_collegamento')">In collegamento</DropdownMenuItem>
                                <DropdownMenuItem :class="voce" @select="delega(r)">Delega</DropdownMenuItem>
                                <DropdownMenuItem v-if="r.delegatoDaFar" :class="voce"
                                    @select="entra(r.delegatoDaFar.delegato_anagrafica_id ? { anagrafica_id: r.delegatoDaFar.delegato_anagrafica_id } : perLaDelega(r.delegatoDaFar), 'in_sala')">
                                    Entra {{ r.delegatoDaFar.delegato_nome }}, con la delega
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>
                    <template v-else-if="r.presente">
                        <button type="button" :class="[PICCOLO, 'flex-1']" data-testid="esce" @click="esce({ anagrafica_id: r.presente.anagrafica_id! })">Esce</button>
                        <button v-if="r.stato === 'in_collegamento'" type="button" :class="[PICCOLO, 'flex-1']" @click="esce({ anagrafica_id: r.presente.anagrafica_id! }, true)">
                            Collegamento caduto
                        </button>
                    </template>
                </div>
            </li>
        </ul>

        <button v-if="aperta && !cercando && (nascoste > 0 || tutti)" type="button" class="text-xs font-medium text-slate-700 underline underline-offset-2 dark:text-slate-300"
            data-testid="mostra-tutti" @click="tutti = !tutti">
            {{ tutti ? 'Mostra solo chi è o è stato in sala' : `E altri ${nascoste} · Mostra tutti` }}
        </button>
        <p v-if="!aperta" class="text-xs text-slate-500 dark:text-slate-400" data-testid="presenze-dopo-l-apertura">Le presenze si segnano dopo «Apri l'assemblea».</p>

        <FinestraDellEntrata v-model:open="finestra" :candidati="perLaFinestra.candidati" :persone="perLaFinestra.persone" :unita="perLaFinestra.unita"
            :come="perLaFinestra.come" :serve-il-motivo="perLaFinestra.serveIlMotivo" :con-testa-sua="perLaFinestra.conTestaSua" @entra="entrato" />
        <FinestraDellaDelega v-model:open="finestraDelega" :delegante="delegante" :presenti="presenti" />
    </section>
</template>
