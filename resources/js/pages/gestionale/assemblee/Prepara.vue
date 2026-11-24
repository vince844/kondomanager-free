<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Prepara l'assemblea» (§3 P2; scena 4 della bozza approvata con la decisione 501): il tipo, per chi, le date con il controllo mentre si scrivono, dove, la
 * tabella del valore (di serie la tabella di proprietà generale), e l'ordine del giorno proposto in una lista sola, per gruppi («Di serie», «Da te», «Dal
 * programma», «Dalla nota sintetica»): **spuntato di serie**, e ciò che si toglie resta per la prossima («Non spuntato: resta per la prossima assemblea»). La
 * maggioranza proposta si cambia con il menu. Aperta da un memo (`?memo=`), il suo punto è in evidenza.
 *
 * In cima la stessa strada dei cinque passi della pagina dell'assemblea (`StradaDellAssemblea`): il passo 1 «Date e ordine del giorno» aperto, con la riga
 * che segue le date e i punti spuntati; gli altri chiusi «dopo «Prepara l'assemblea»». Il modulo è il contenuto del passo 1, e nel piede «Prepara
 * l'assemblea» prende il posto di «Avanti». Sotto il titolo le tre card della guida; «Assemblee» nell'intestazione torna all'elenco.
 *
 * Sotto i proposti «Aggiungi un punto» apre la finestra della pagina dell'assemblea (`FinestraDelPunto`, `nella-pagina`): l'assemblea non c'è ancora, e il
 * punto resta qui, nel gruppo «Scritti da te», con «Modifica» e «Togli». Con «Prepara l'assemblea» i punti scritti partono in `punti`, nell'ordine della
 * pagina, e nascono con l'assemblea per la strada di «Aggiungi un punto»: dopo i proposti spuntati, prima di «Varie ed eventuali». Un punto che il server
 * rifiuta (`punti.1.testo`) ha l'errore sotto di sé e nella finestra, riaprendolo; la riga e il piede del passo contano anche i punti scritti.
 *
 * «L'ordine del giorno» sotto i proposti elenca i punti che entreranno, nell'ordine in cui entreranno (i proposti spuntati, poi i punti scritti, «Varie ed
 * eventuali» in fondo), con le frecce «Sposta su» e «Sposta giù» della pagina dell'assemblea (decisione 661). L'ordine parte con «Prepara l'assemblea» in
 * `ordine`: le chiavi dei proposti e `scritto:N` per il punto scritto N; «Varie ed eventuali» resta sempre l'ultimo e non si sposta.
 *
 * I proposti partono come le loro chiavi: il server rilegge le proposte (R18). Al telefono i campi stanno uno sotto l'altro. **I condòmini di un gruppo**
 * (`gruppo:<id>`, Coda 734): la tabella del valore è quella del gruppo, ferma; le proposte di tutto il condominio non si offrono; la finestra sa il gruppo.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Head, router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { ArrowDown, ArrowUp, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import DateDellAssemblea from '@/components/assemblee/DateDellAssemblea.vue';
import FinestraDelPunto from '@/components/assemblee/FinestraDelPunto.vue';
import StradaDellAssemblea from '@/components/assemblee/StradaDellAssemblea.vue';
import AssembleaGuide from '@/components/guides/AssembleaGuide.vue';
import { usePermission } from '@/composables/permissions';
import { BADGE, CLASSI_DEL_BADGE, coloreDellaMaggioranza } from '@/lib/assemblee/assemblee';
import { cardDellaPreparazione } from '@/lib/assemblee/cardDellaGuida';
import { controllaLeDate } from '@/lib/assemblee/date';
import {
    datiDelModulo, gruppoDelPerimetro, materiaDiTutti, rigaDelleDate, rigaDellaTabellaDelGruppo, seduteDaControllare, type DatiDelPunto, type ModuloDelleDate, type PassoDellaStrada, type ScelteDelleAssemblee,
} from '@/lib/assemblee/pagine';
import type { Building } from '@/types/buildings';
import type { GruppoDiProposte, PuntoProposto, TerminiDellaPrima } from '@/types/assemblee';

const props = defineProps<{
    condominio: Building;
    condomini: Building[];
    esercizio: any;
    proposte: GruppoDiProposte[];
    scelte: ScelteDelleAssemblee;
    tabella_di_proprieta: { id: number; nome: string } | null;
    memo: { id: number; testo: string } | null;
    /** I giorni da cui la prima convocazione ha i cinque giorni (art. 66 c. 3), contando da oggi: la nota sotto le date. */
    termini_della_prima?: TerminiDellaPrima | null;
    /** La strada: il passo 1 aperto, gli altri chiusi (`PassiDellAssemblea::dellaPreparazione()`). */
    passi: PassoDellaStrada[];
}>();

const { generatePath, generateRoute } = usePermission();
const mostraGuida = ref(false);
const modulo = ref<ModuloDelleDate>({
    tipo: 'ordinaria', perimetro: 'condominio', luogo: '', modalita: 'presenza', piattaforma: '', consenso_videoconferenza_il: '', consenso_videoconferenza_modo: '',
    sedute: [{ convocazione: 'prima', numero: 1, giorno: '', ora: '08:00' }, { convocazione: 'seconda', numero: 1, giorno: '', ora: '18:30' }],
});
const tabella = ref<number | null>(props.tabella_di_proprieta?.id ?? null);
// «Segna come tabella di proprietà generale»: solo se il condominio non ne ha una, per il condominio intero e con una tabella scelta.
const segnaProprieta = ref(false);
const spuntate = ref<Record<string, boolean>>(Object.fromEntries(props.proposte.flatMap((g) => g.punti.map((p) => [p.chiave_fonte, p.spuntato]))));
// Un'assemblea di una palazzina o di una scala: i sospesi di tutto il condominio (di serie, dal programma, dalla nota) si tolgono la spunta, e la
// riprendono tornando a tutto il condominio; i memo e «Varie ed eventuali» restano come sono. Decide l'amministratore.
watch(() => modulo.value.perimetro, (nuovo, vecchio) => {
    const diUnaParte = nuovo !== 'condominio';
    if (diUnaParte === (vecchio !== 'condominio')) return;
    props.proposte.filter((g) => g.chiave !== 'da_te').flatMap((g) => g.punti).filter((p) => p.chiave_fonte !== 'varie')
        .forEach((p) => { spuntate.value[p.chiave_fonte] = diUnaParte ? false : p.spuntato; });
});
const maggioranze = ref<Record<string, string | null>>(Object.fromEntries(props.proposte.flatMap((g) => g.punti.map((p) => [p.chiave_fonte, p.maggioranza]))));
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);

// I condòmini di un gruppo (Coda 734): la tabella è il gruppo, ferma; le proposte di tutto il condominio non si offrono (e non restano spuntate). Le proposte
// di un gruppo (`gruppo_id`: il memo del punto rinviato nella sua assemblea, il rendiconto e il preventivo della sua gestione) ci sono solo con «Per chi»
// quel gruppo: il suo memo spuntato, la gestione no; i memo di tutto il condominio, per i condòmini di un gruppo, non spuntati.
const gruppo = computed(() => gruppoDelPerimetro(props.scelte, modulo.value.perimetro));
const diTutti = (p: PuntoProposto) => p.gruppo_id == null && gruppo.value !== null && materiaDiTutti(p.materia);
const diUnAltroGruppo = (p: PuntoProposto) => p.gruppo_id != null && p.gruppo_id !== gruppo.value?.tabella_id;
watch(() => gruppo.value?.tabella_id, (id, prima) => {
    tabella.value = id ?? (prima !== undefined ? props.tabella_di_proprieta?.id ?? null : tabella.value);
    props.proposte.flatMap((g) => g.punti).filter(diTutti).forEach((p) => { spuntate.value[p.chiave_fonte] = false; });
    props.proposte.forEach((g) => g.punti.filter((p) => p.gruppo_id != null || g.chiave === 'da_te').forEach((p) => {
        spuntate.value[p.chiave_fonte] = p.gruppo_id != null ? g.chiave === 'da_te' && p.gruppo_id === id : id === undefined && p.spuntato;
    }));
});
const gruppi = computed(() => props.proposte.map((g) => ({ ...g, punti: g.punti.filter((p) => !diTutti(p) && !diUnAltroGruppo(p)) })).filter((g) => g.punti.length > 0));

// I punti scritti qui: restano nella pagina e nascono con l'assemblea. La chiave resta la stessa se un altro punto si toglie, e tiene gli errori del server.
interface PuntoScritto { chiave: number; dati: DatiDelPunto }
const scritti = ref<PuntoScritto[]>([]);
const erroriDeiPunti = ref<Record<number, Record<string, string>>>({});
let prossimaChiave = 1;
const finestra = ref(false);
const inModifica = ref<PuntoScritto | null>(null);
function aggiungiUnPunto() {
    inModifica.value = null;
    finestra.value = true;
}
function modificaIlPunto(p: PuntoScritto) {
    inModifica.value = p;
    finestra.value = true;
}
function togliIlPunto(p: PuntoScritto) {
    scritti.value = scritti.value.filter((x) => x.chiave !== p.chiave);
    delete erroriDeiPunti.value[p.chiave];
}
/** Il punto dalla finestra: nuovo in fondo ai punti scritti, o al posto di quello in modifica; corretto, i suoi errori vanno via. */
function tieniIlPunto(dati: DatiDelPunto) {
    const inCorrezione = inModifica.value;
    if (inCorrezione) {
        scritti.value = scritti.value.map((x) => (x.chiave === inCorrezione.chiave ? { chiave: x.chiave, dati } : x));
        delete erroriDeiPunti.value[inCorrezione.chiave];
    } else {
        scritti.value = [...scritti.value, { chiave: prossimaChiave++, dati }];
    }
}
/** I badge del punto scritto, come quelli dell'ordine del giorno nella pagina dell'assemblea. */
function badgeDel(d: DatiDelPunto): { testo: string; colore: keyof typeof CLASSI_DEL_BADGE }[] {
    const nome = (elenco: { valore: string; nome: string }[], v: string | null) => elenco.find((x) => x.valore === v)?.nome ?? null;
    const perimetro = d.ambito === 'scala' ? props.scelte.scale.find((x) => x.id === d.scala_id)?.nome
        : d.ambito === 'palazzina' ? props.scelte.palazzine.find((x) => x.id === d.palazzina_id)?.nome : null;
    const maggioranza = nome(props.scelte.maggioranze, d.maggioranza);
    return [
        ...(nome(props.scelte.materie, d.materia) ? [{ testo: nome(props.scelte.materie, d.materia)!, colore: 'neutro' as const }] : []),
        ...(perimetro ? [{ testo: `Solo la ${d.ambito} ${perimetro}`, colore: 'viola' as const }] : []),
        ...(d.conduttore ? [{ testo: d.conduttore === 'vota' ? 'Votano i conduttori' : 'Intervengono i conduttori', colore: 'viola' as const }] : []),
        maggioranza ? { testo: maggioranza, colore: coloreDellaMaggioranza(d.maggioranza) } : { testo: 'Maggioranza da scegliere', colore: 'ambra' as const },
    ];
}
// L'ordine dei punti che entreranno: `posizioni` è l'ordine scelto con le frecce (le chiavi dei proposti e `#<chiave>` dei punti scritti); ciò che non
// nomina (una spunta nuova, un punto appena scritto) viene dopo, nell'ordine di sempre; «Varie ed eventuali» è sempre l'ultimo.
const CHIAVE_VARIE = 'varie';
const posizioni = ref<string[]>([]);
interface VoceDellOrdine { token: string; testo: string; gruppo: string | null; scritto: boolean }
const vociDiSerie = computed<VoceDellOrdine[]>(() => [
    ...props.proposte.flatMap((g) => g.punti.filter((p) => spuntate.value[p.chiave_fonte]).map((p) => ({ token: p.chiave_fonte, testo: p.testo, gruppo: g.titolo, scritto: false }))),
    ...scritti.value.map((p) => ({ token: `#${p.chiave}`, testo: p.dati.testo, gruppo: null, scritto: true })),
]);
const voci = computed<VoceDellOrdine[]>(() => {
    const note = new Map(vociDiSerie.value.map((v) => [v.token, v]));
    const scelte = posizioni.value.filter((t) => note.has(t));
    const messe = new Set(scelte);
    const tutte = [...scelte, ...vociDiSerie.value.map((v) => v.token).filter((t) => !messe.has(t))].map((t) => note.get(t)!);
    return [...tutte.filter((v) => v.token !== CHIAVE_VARIE), ...tutte.filter((v) => v.token === CHIAVE_VARIE)];
});
const ultimaPrimaDelleVarie = computed(() => voci.value.filter((v) => v.token !== CHIAVE_VARIE).length - 1);
function sposta(i: number, verso: -1 | 1) {
    const t = voci.value.map((v) => v.token);
    const j = i + verso;
    if (i < 0 || j < 0 || j > ultimaPrimaDelleVarie.value || i > ultimaPrimaDelleVarie.value) return;
    [t[i], t[j]] = [t[j], t[i]];
    posizioni.value = t;
}
/** L'ordine come parte al server: le chiavi dei proposti e `scritto:N` (N = il posto del punto fra i punti scritti mandati). */
function ordineDaMandare(): string[] {
    return voci.value.map((v) => {
        if (!v.scritto) return v.token;
        return `scritto:${scritti.value.findIndex((p) => `#${p.chiave}` === v.token)}`;
    });
}

const nomeDellaTabella = computed(() => props.scelte.tabelle.find((t) => t.id === tabella.value)?.nome
    ?? (props.tabella_di_proprieta && props.tabella_di_proprieta.id === tabella.value ? props.tabella_di_proprieta.nome : null));
const puoiSegnare = computed(() => props.tabella_di_proprieta === null && tabella.value !== null && modulo.value.perimetro === 'condominio' && nomeDellaTabella.value !== null);
const conErroriNeiPunti = computed(() => Object.keys(erroriDeiPunti.value).length > 0);

const quante = computed(() => Object.values(spuntate.value).filter(Boolean).length + scritti.value.length);
const controllo = computed(() => controllaLeDate(seduteDaControllare(modulo.value.sedute)));
const tabelle = computed(() => [{ valore: null as number | null, nome: 'Nessuna, la scelgo dopo' }, ...props.scelte.tabelle.map((t) => ({
    valore: t.id as number | null, nome: t.nome, sotto: t.controlli[modulo.value.perimetro]?.frase ?? '' }))]);
const memoMesso = (p: PuntoProposto) => props.memo !== null && p.memo_id === props.memo.id;

const intestazione = computed(() => {
    const prima = modulo.value.sedute.find((s) => s.convocazione === 'prima' && s.numero === 1)?.giorno;
    const seconda = modulo.value.sedute.find((s) => s.convocazione === 'seconda' && s.numero === 1)?.giorno;
    const it = (d?: string) => (d ? d.split('-').reverse().join('/') : '');
    return `Assemblea ${modulo.value.tipo}${gruppo.value ? ` dei condòmini della tabella «${gruppo.value.nome}»` : ''} di ${props.condominio.nome}${prima ? ` · prima convocazione ${it(prima)}` : ''}${seconda ? `, seconda ${it(seconda)}` : ''}`;
});

// Lasciare la pagina con i punti scritti non ancora preparati li perde: il browser chiede conferma prima di chiudere la scheda, e un clic verso un'altra
// pagina del programma chiede «vuoi uscire lo stesso?». Non mentre l'assemblea si prepara (quella visita li porta con sé).
const DOMANDA_USCITA = 'Hai dei punti scritti non ancora salvati: vuoi uscire lo stesso?';
const conPuntiDaSalvare = () => scritti.value.length > 0 && !inCorso.value;
function primaDiChiudere(e: BeforeUnloadEvent) {
    if (!conPuntiDaSalvare()) return;
    e.preventDefault();
    e.returnValue = '';
}
let togliAscolto: (() => void) | null = null;
onMounted(() => {
    window.addEventListener('beforeunload', primaDiChiudere);
    togliAscolto = router.on('before', (evento) => {
        const visita = (evento as unknown as { detail: { visit: { method: string } } }).detail.visit;
        if (visita.method !== 'get' || !conPuntiDaSalvare()) return;
        if (!window.confirm(DOMANDA_USCITA)) evento.preventDefault();
    });
});
onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', primaDiChiudere);
    togliAscolto?.();
});

// Gli errori dei campi che mancano si accendono al primo clic su «Prepara l'assemblea», non all'apertura della pagina.
const tentato = ref(false);

function prepara() {
    tentato.value = true;
    if (!controllo.value.valide) {
        document.querySelector('[data-testid="prepara-date"]')?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
        return;
    }
    inCorso.value = true;
    const chiavi = Object.entries(spuntate.value).filter(([, v]) => v).map(([k]) => k);
    const inviati = scritti.value.map((p) => p.chiave);
    router.post(route(generateRoute('gestionale.assemblee.store'), { condominio: props.condominio.id }), {
        ...datiDelModulo(modulo.value), tabella_id: tabella.value, proposte: chiavi, ordine: ordineDaMandare(), segna_di_proprieta: puoiSegnare.value && segnaProprieta.value,
        maggioranze: Object.fromEntries(chiavi.map((k) => [k, maggioranze.value[k] ?? null])),
        punti: scritti.value.map((p) => p.dati),
    }, { onError: (e: Record<string, string>) => {
        // «punti.1.testo» è l'errore del secondo punto scritto mandato: resta sul suo punto anche se poi un altro si toglie. Gli altri restano in fondo.
        const perPunto: Record<number, Record<string, string>> = {};
        const resto: Record<string, string> = {};
        for (const [k, m] of Object.entries(e)) {
            const trovato = /^punti\.(\d+)\.(.+)$/.exec(k);
            const chiave = trovato ? inviati[Number(trovato[1])] : undefined;
            if (trovato && chiave !== undefined) (perPunto[chiave] ??= {})[trovato[2]] = m;
            else resto[k] = m;
        }
        erroriDeiPunti.value = perPunto;
        errori.value = resto;
    }, onFinish: () => { inCorso.value = false; } });
}

/** Il passo 1 dice le date e i punti spuntati mentre si scrivono; il piede, quanti punti e che niente parte. */
const passiDellaPagina = computed<PassoDellaStrada[]>(() => props.passi.map((p) => (p.chiave !== 'date' ? p : {
    ...p, riga: rigaDelleDate(modulo.value.sedute, quante.value),
    piede: `Adesso: ${quante.value === 1 ? '1 punto' : `${quante.value} punti`} nell'ordine del giorno. ${p.piede}`,
})));
const elenco = computed(() => route(generateRoute('gestionale.assemblee.index'), { condominio: props.condominio.id }));

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Assemblee', href: elenco.value },
    { title: 'Prepara l\'assemblea' },
]);
const coloreDelGruppo: Record<string, keyof typeof CLASSI_DEL_BADGE> = { di_serie: 'neutro', da_te: 'viola', dal_programma: 'blu', dalla_nota: 'ambra' };
const BOTTONE = 'inline-flex h-8 items-center justify-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 text-xs font-medium hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800';
const pulsante = `${BOTTONE} text-slate-700 dark:text-slate-200`;
const frecciaClassi = 'inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800';
const pulsanteTogli = `${BOTTONE} text-rose-700 dark:text-rose-400`;
</script>

<template>
    <Head title="Prepara l'assemblea" />
    <GestionaleLayout>
        <div class="space-y-4 px-4 py-6 sm:px-6 sm:py-8">
            <PageHeaderGuide
                page-title="Prepara l'assemblea"
                :page-subtitle="`Le date, dove, la tabella e i punti che aspettano un'assemblea di ${condominio.nome}. Fino alla convocazione cambi tutto.`"
                :guides="cardDellaPreparazione()"
                :breadcrumbs="(breadcrumbs as any)"
                :video-url="null"
                :condominio="condominio"
                :condomini="condomini"
                :back-url="elenco"
                back-text="Assemblee"
                has-text-guide
                text-guide-title="Guida"
                una-fila
                @open-text-guide="mostraGuida = true"
            />

            <!-- La strada: il passo 1 aperto, con il modulo di sempre; gli altri si aprono dopo «Prepara l'assemblea» -->
            <StradaDellAssemblea :passi="passiDellaPagina" aperto="date">
                <div class="space-y-4">
                    <section class="space-y-3.5 rounded-xl border border-slate-200 bg-white px-4 py-4 dark:border-slate-800 dark:bg-slate-950" data-testid="prepara-date">
                        <DateDellAssemblea v-model="modulo" :scelte="scelte" :mostra-mancanti="tentato" :termini="termini_della_prima ?? null" />
                        <div class="menu-campo max-w-xl"><span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">La tabella del valore</span>
                            <v-select v-model="tabella" :options="tabelle" label="nome" :reduce="(o: { valore: number | null }) => o.valore" :clearable="false" :searchable="tabelle.length > 8" :disabled="gruppo !== null">
                                <template #option="o"><span class="block">{{ o.nome }}</span><span v-if="o.sotto" class="block text-xs opacity-70">{{ o.sotto }}</span></template>
                            </v-select>
                            <span v-if="gruppo" class="mt-1 block text-xs text-slate-600 dark:text-slate-300" data-testid="tabella-del-gruppo">{{ rigaDellaTabellaDelGruppo(scelte, gruppo) }}</span><span v-else-if="!tabella_di_proprieta" class="mt-1 block text-xs text-slate-500 dark:text-slate-400" data-testid="nota-tabella">Nessuna tabella di {{ condominio.nome }} è segnata come di proprietà generale.</span>
                            <!-- La tabella scelta qui vale per questa assemblea; la casella la segna anche per il condominio, così è già scelta nelle prossime. -->
                            <label v-if="puoiSegnare" class="mt-2 flex items-start gap-2 text-xs text-slate-700 dark:text-slate-300" data-testid="segna-proprieta">
                                <Checkbox v-model="segnaProprieta" class="mt-px rounded" />
                                <span>Segna «{{ nomeDellaTabella }}» come tabella di proprietà generale di {{ condominio.nome }}
                                    <span class="block text-slate-500 dark:text-slate-400">Così è già scelta nelle prossime assemblee. Si cambia nella pagina della tabella.</span></span>
                            </label>
                        </div>
                    </section>

                    <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="ordine-proposto">
                        <!-- «Aggiungi un punto» in alto a destra, come nell'ordine del giorno della pagina dell'assemblea. -->
                        <header class="flex flex-col gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800 sm:flex-row sm:items-start sm:justify-between" data-testid="intestazione-ordine">
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Ordine del giorno proposto</h3>
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ intestazione }}. Spunta i punti proposti. I tuoi li scrivi qui con «Aggiungi un punto», o dopo nella pagina dell'assemblea.</p>
                            </div>
                            <button type="button" :class="[pulsante, 'shrink-0']" data-testid="aggiungi-punto" @click="aggiungiUnPunto"><Plus class="h-3.5 w-3.5" /> Aggiungi un punto</button>
                        </header>
                        <p v-if="gruppi.length === 0" class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">Niente aspetta un'assemblea di {{ condominio.nome }}.</p>
                        <div v-for="g in gruppi" :key="g.chiave" class="border-b border-slate-100 px-4 py-3 last:border-b-0 dark:border-slate-800" data-testid="gruppo-proposte">
                            <p class="mb-2"><span :class="[BADGE, CLASSI_DEL_BADGE[coloreDelGruppo[g.chiave] ?? 'neutro']]">{{ g.titolo }}</span></p>
                            <div v-for="p in g.punti" :key="p.chiave_fonte" class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-2.5 gap-y-1.5 py-1.5 sm:grid-cols-[auto_minmax(0,1fr)_230px]"
                                :class="{ 'rounded-md bg-sky-50/60 px-1 dark:bg-sky-950/20': memoMesso(p) }" data-testid="proposta">
                                <Checkbox v-model="spuntate[p.chiave_fonte]" class="mt-1 rounded" :aria-label="p.testo" />
                                <div class="min-w-0">
                                    <p class="break-words text-sm text-slate-900 dark:text-slate-100">{{ p.testo }}</p>
                                    <p v-if="!spuntate[p.chiave_fonte]" class="text-xs text-slate-500 dark:text-slate-400">Non spuntato: resta per la prossima assemblea.</p>
                                    <p v-else-if="p.frase" class="text-xs text-slate-500 dark:text-slate-400">{{ p.frase }}</p>
                                    <p v-if="p.avviso" class="text-xs text-amber-800 dark:text-amber-300">{{ p.avviso }}</p>
                                </div>
                                <div class="menu-campo col-start-2 sm:col-start-3">
                                    <v-select v-model="maggioranze[p.chiave_fonte]" :options="scelte.maggioranze" label="nome" :reduce="(s: { valore: string }) => s.valore"
                                        :clearable="true" :searchable="false" placeholder="Maggioranza" />
                                </div>
                            </div>
                        </div>

                        <!-- I punti scritti qui: nella pagina finché l'assemblea nasce. -->
                        <div v-if="scritti.length" class="border-b border-slate-100 px-4 py-3 dark:border-slate-800" data-testid="scritti-da-te">
                            <p class="mb-1"><span :class="[BADGE, CLASSI_DEL_BADGE.verde]">Scritti da te</span></p>
                            <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">Di serie vengono dopo i punti proposti spuntati, prima di «Varie ed eventuali»; li sposti con le frecce in «L'ordine del giorno», qui sotto.</p>
                            <div v-for="p in scritti" :key="p.chiave" class="flex flex-col gap-2 py-1.5 sm:flex-row sm:items-start sm:justify-between" data-testid="punto-scritto">
                                <div class="min-w-0">
                                    <p class="break-words text-sm text-slate-900 dark:text-slate-100">{{ p.dati.testo }}</p>
                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        <span v-for="b in badgeDel(p.dati)" :key="b.testo" :class="[BADGE, CLASSI_DEL_BADGE[b.colore]]">{{ b.testo }}</span>
                                    </div>
                                    <p v-for="(m, campo) in erroriDeiPunti[p.chiave] ?? {}" :key="campo" class="mt-1 text-xs text-rose-700 dark:text-rose-400" data-testid="errore-punto-scritto">{{ m }}</p>
                                </div>
                                <div class="flex shrink-0 gap-1.5">
                                    <button type="button" :class="pulsante" data-testid="modifica-punto-scritto" @click="modificaIlPunto(p)"><Pencil class="h-3.5 w-3.5" /> Modifica</button>
                                    <button type="button" :class="pulsanteTogli" data-testid="togli-punto-scritto" @click="togliIlPunto(p)"><Trash2 class="h-3.5 w-3.5" /> Togli</button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- L'ordine in cui i punti entreranno, con le frecce della pagina dell'assemblea; si salva con «Prepara l'assemblea». -->
                    <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="ordine-del-giorno-preparato">
                        <header class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">L'ordine del giorno</h3>
                            <p class="text-xs text-slate-500 dark:text-slate-400">I punti che entreranno, in quest'ordine; li sposti con le frecce. «Varie ed eventuali» resta in fondo.</p>
                        </header>
                        <p v-if="voci.length === 0" class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400" data-testid="ordine-preparato-vuoto">
                            Nessun punto: spunta quelli proposti o «Aggiungi un punto».
                        </p>
                        <ol v-else class="divide-y divide-slate-100 dark:divide-slate-800">
                            <li v-for="(v, i) in voci" :key="v.token" class="flex items-start gap-3 px-4 py-2.5" data-testid="voce-dell-ordine">
                                <span class="w-6 shrink-0 pt-0.5 text-right text-sm font-semibold tabular-nums text-slate-500 dark:text-slate-400" data-testid="numero-della-voce">{{ i + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="break-words text-sm text-slate-900 dark:text-slate-100" data-testid="testo-della-voce">{{ v.testo }}</p>
                                    <p class="mt-1"><span :class="[BADGE, v.scritto ? CLASSI_DEL_BADGE.verde : CLASSI_DEL_BADGE.neutro]">{{ v.scritto ? 'Scritto da te' : v.gruppo }}</span></p>
                                </div>
                                <div v-if="v.token !== CHIAVE_VARIE" class="flex shrink-0 gap-1.5">
                                    <button type="button" :class="frecciaClassi" :disabled="i === 0" :aria-label="`Sposta su il punto ${i + 1}`" title="Sposta su" data-testid="sposta-su"
                                        @click="sposta(i, -1)"><ArrowUp class="h-4 w-4" /></button>
                                    <button type="button" :class="frecciaClassi" :disabled="i >= ultimaPrimaDelleVarie" :aria-label="`Sposta giù il punto ${i + 1}`" title="Sposta giù" data-testid="sposta-giu"
                                        @click="sposta(i, 1)"><ArrowDown class="h-4 w-4" /></button>
                                </div>
                            </li>
                        </ol>
                    </section>
                    <p v-if="conErroriNeiPunti" class="text-sm text-rose-700 dark:text-rose-400" data-testid="errore-dei-punti-scritti">
                        L'assemblea non è ancora nata: correggi il punto segnato in rosso con «Modifica», poi di nuovo «Prepara l'assemblea».
                    </p>
                    <p v-for="(m, k) in errori" :key="k" class="text-sm text-rose-700 dark:text-rose-400" data-testid="errore-della-pagina">{{ m }}</p>
                </div>
                <template #azione>
                    <button type="button" class="inline-flex h-8 w-full items-center justify-center rounded-lg bg-slate-900 px-3 text-xs font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-55 sm:w-auto dark:bg-white dark:text-slate-900"
                        :disabled="inCorso" data-testid="prepara-assemblea" @click="prepara">{{ inCorso ? 'Un momento…' : 'Prepara l\'assemblea' }}</button>
                </template>
            </StradaDellAssemblea>
        </div>
        <FinestraDelPunto v-model:open="finestra" nella-pagina :condominio-id="condominio.id" :condominio-nome="condominio.nome" :assemblea-nome="intestazione"
            :tabella-dell-assemblea="nomeDellaTabella" :gruppo="gruppo" :punto="null" :bozza="inModifica?.dati ?? null"
            :errori-del-punto="inModifica ? erroriDeiPunti[inModifica.chiave] ?? null : null" :scelte="scelte" @salva="tieniIlPunto" />
        <AssembleaGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
