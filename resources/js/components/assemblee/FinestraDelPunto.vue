<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Un punto dell'ordine del giorno (§3 P2; scena 2 della bozza delle scene della tappa 2, decisione 568): il punto (la frase dell'avviso), la frase per la
 * bacheca in un cassetto, di che cosa parla, la maggioranza proposta per la materia (per tabelle e criteri di riparto nessuna, con l'avviso della nullità),
 * il conduttore, e **«Per chi» e «Con quale tabella», facoltativi**: di serie il punto è di tutta l'assemblea; scelta la scala, il programma propone la
 * tabella che ha tutte e sole le sue unità e la controlla come quella dell'assemblea, senza bloccare. Per la modifica della destinazione d'uso le parti comuni
 * che cambiano e la nuova destinazione, che a pena di nullità l'avviso deve dire (art. 1117-ter). Con una tabella che non comprende tutte le unità del
 * perimetro, la riga arancio «La tabella ha 8 unità su 26: le altre 18 contano come teste a zero millesimi.» (decisione 640 (2)): solo l'avviso.
 * **La tabella di un gruppo** (Coda 734, scene 2 e 3 della bozza approvata): senza il segno, sotto la riga arancio la proposta e il pulsante «Segna «Box» come
 * tabella di un gruppo» (la rotta del segno; la finestra resta aperta e rilegge le scelte); con il segno, niente riga arancio, e il riquadro azzurro dice
 * che sul punto votano solo i condòmini delle sue unità (art. 1123 c. 3 c.c.).
 *
 * Quando si aggiunge un punto (non quando si modifica), in cima i punti proposti che non sono ancora nell'ordine del giorno (le proposte differite della
 * pagina), con il loro gruppo, la loro frase e la maggioranza: «Aggiungi» li manda come il riquadro dei proposti (`punti.dalle-proposte`, con la maggioranza
 * scelta nel riquadro, o quella proposta). Sotto, «Oppure scrivi un punto nuovo» con il modulo. Senza proposti la finestra è il solo modulo.
 *
 * Il salvataggio è una visita parziale (`only: ordine, proposte, passi`, più il passo «Convocazione» o «Documenti» già letto, `preserveScroll`). Al telefono
 * la finestra va a tutta altezza.
 *
 * In «Prepara l'assemblea» (`nella-pagina`) l'assemblea non c'è ancora: la finestra è la stessa, ma non manda niente al server. «Aggiungi il punto» passa i
 * campi alla pagina (`salva`, come li manderebbe al server) e chiude; con `bozza` riparte dal punto scritto lì («Modifica il punto»), con gli errori che il
 * server ha dato su quel punto (`errori-del-punto`).
 *
 * **L'assemblea dei condòmini di un gruppo** (`gruppo`, o quello dell'assemblea della pagina; Coda 734, scena 11 della bozza approvata, decisione 699 (3)):
 * «Per chi» è fermo su «Tutto il gruppo della tabella «Box»», «Con quale tabella» offre «Box» e le tabelle di un gruppo che stanno dentro di lei, e una riga
 * dice chi vota. Un punto di tutto il condominio (il rendiconto, il preventivo, la nomina, la dispensa, le regole del recupero) si ferma con la riga rossa del
 * rischio: «Salva il punto» è spento, e «Mettilo lo stesso» lo salva con la scelta (`mettilo_lo_stesso`), che resta nel registro delle modifiche.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { AlertTriangle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import CampoGiorno from '@/components/CampoGiorno.vue';
import { Switch } from '@/components/ui/switch';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import SuggerimentoDellaI from '@/components/assemblee/convocazione/SuggerimentoDellaI.vue';
import { usePermission } from '@/composables/permissions';
import { BADGE, CLASSI_DEL_BADGE, importoNelCampo, MATERIE_DI_SPESA, regolaDellaMaggioranza } from '@/lib/assemblee/assemblee';
import {
    avvisoDellaTabellaParziale, chiaveDelPerimetro, rischioDelPunto, tabelleDelGruppo, tabellePerIlPerimetro, type AssembleaDellaPagina, type DatiDelPunto,
    type GruppoDellAssemblea, type PuntoDellaPagina, type ScelteDelleAssemblee,
} from '@/lib/assemblee/pagine';
import type { GruppoDiProposte, PuntoProposto } from '@/types/assemblee';

const props = defineProps<{
    open: boolean;
    condominioId: number;
    condominioNome: string;
    /** Senza, in «Prepara l'assemblea»: l'assemblea nasce dopo. */
    assembleaId?: number | null;
    /** «Assemblea ordinaria di Via Roma 12 del 15/12/2026». */
    assembleaNome: string;
    /** La tabella dell'assemblea, per la riga «Adesso: …». */
    tabellaDellAssemblea: string | null;
    punto: PuntoDellaPagina | null;
    scelte: ScelteDelleAssemblee;
    /** I punti proposti che non sono ancora nell'ordine del giorno (la prop differita `proposte` della pagina). */
    proposte?: GruppoDiProposte[] | null;
    /** La maggioranza scelta per ogni proposto nel riquadro dei proposti (`chiave_fonte` => maggioranza): «Aggiungi» manda quella. */
    maggioranzeScelte?: Record<string, string | null> | null;
    /** Le prop della pagina già lette da rileggere con il salvataggio (`convocazione`, `fascicolo`). */
    rileggi?: string[];
    /** In «Prepara l'assemblea»: il punto resta nella pagina (`salva`), niente va al server. */
    nellaPagina?: boolean;
    /** Il punto scritto nella pagina da modificare, con i campi come li passa `salva`. */
    bozza?: DatiDelPunto | null;
    /** Gli errori del server su quel punto, per campo, da mostrare aprendo la finestra. */
    erroriDelPunto?: Record<string, string> | null;
    /** L'assemblea dei condòmini di un gruppo (Coda 734); senza la prop, quello dell'assemblea della pagina. */
    gruppo?: GruppoDellAssemblea | null;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'salva', dati: DatiDelPunto): void }>();

interface Opzione { valore: string | null; nome: string; sotto?: string; gruppo?: boolean }

const { generateRoute } = usePermission();
/** Il gruppo dell'assemblea: dalla prop («Prepara l'assemblea»), o dall'assemblea della pagina. */
const gruppo = computed<GruppoDellAssemblea | null>(() => (props.gruppo !== undefined ? props.gruppo
    : (usePage().props as { assemblea?: AssembleaDellaPagina } | undefined)?.assemblea?.gruppo ?? null));
const testo = ref('');
const testoPubblico = ref('');
// La frase per la bacheca si scrive solo con l'interruttore acceso; spento, in bacheca va la riga neutra della materia.
const conFrasePubblica = ref(false);
const materia = ref('altro');
const maggioranza = ref<string | null>('ordinaria');
const conduttore = ref<string | null>(null);
const perChi = ref('condominio');
const tabella = ref<number | null>(null);
const importo = ref('');
const partiComuni = ref('');
const nuovaDestinazione = ref('');
// La delega all'amministratore (decisione 622 (3)): «Fino a» è l'importo del punto, «Per che cosa» e «Fino al» vanno nei dati del punto.
const delegaAmbito = ref<string | null>(null);
const delegaFinoAl = ref('');
const ambitiDellaDelega: Opzione[] = [{ valore: 'accordi_fornitori', nome: 'Accordi con i fornitori' }];
/** Le materie di spesa hanno il campo «Importo», facoltativo. */
const conImporto = computed(() => MATERIE_DI_SPESA.includes(materia.value));
const errori = ref<Record<string, string>>({});
const inCorso = ref(false);

/** Un punto nuovo: né un punto dell'assemblea né un punto scritto nella pagina da modificare. */
const nuovo = computed(() => !props.punto && !props.bozza);
/** Il punto dell'assemblea nella forma dei campi della finestra (l'importo dai centesimi al campo). */
const dalPunto = (p: PuntoDellaPagina): DatiDelPunto => ({ testo: p.testo, testo_pubblico: p.testo_pubblico, materia: p.materia, maggioranza: p.maggioranza,
    conduttore: p.conduttore, ambito: p.ambito ?? 'condominio', palazzina_id: p.palazzina_id, scala_id: p.scala_id, tabella_id: p.tabella_id,
    importo: importoNelCampo(p.importo), ...(p.dati ? { dati: p.dati } : {}) });

// Aprendo la finestra i campi prendono i valori del punto: il cambio della materia che ne segue non è una scelta, e non tocca la maggioranza né il conduttore.
let aprendo = false;
watch(() => props.open, (aperta) => {
    if (!aperta) return;
    aprendo = true;
    nextTick(() => { aprendo = false; });
    const p = props.bozza ?? (props.punto ? dalPunto(props.punto) : null);
    testo.value = p?.testo ?? '';
    testoPubblico.value = p?.testo_pubblico ?? '';
    conFrasePubblica.value = (p?.testo_pubblico ?? '').trim() !== '';
    materia.value = p?.materia ?? 'altro';
    maggioranza.value = p ? p.maggioranza : 'ordinaria';
    conduttore.value = p?.conduttore ?? null;
    perChi.value = chiaveDelPerimetro(p?.ambito, p?.palazzina_id, p?.scala_id);
    // Nell'assemblea di un gruppo la tabella del gruppo è «quella dell'assemblea».
    tabella.value = p?.tabella_id === gruppo.value?.tabella_id ? null : p?.tabella_id ?? null;
    importo.value = p?.importo ?? '';
    partiComuni.value = p?.dati?.parti_comuni ?? '';
    nuovaDestinazione.value = p?.dati?.nuova_destinazione ?? '';
    delegaAmbito.value = p?.dati?.delega?.ambito ?? (p ? null : 'accordi_fornitori');
    delegaFinoAl.value = p?.dati?.delega?.fino_al ?? '';
    errori.value = { ...(props.erroriDelPunto ?? {}) };
}, { immediate: true });

/** Cambiata la materia, la maggioranza e il conduttore proposti (per un punto nuovo, o se erano quelli proposti per la materia di prima). */
watch(materia, (nuova, vecchia) => {
    if (aprendo) return;
    const proposta = props.scelte.maggioranze_proposte[nuova];
    const diPrima = props.scelte.maggioranze_proposte[vecchia];
    if (proposta && (nuovo.value || maggioranza.value === (diPrima?.maggioranza ?? null))) {
        maggioranza.value = proposta.maggioranza;
    }
    if (proposta && (nuovo.value || conduttore.value === (diPrima?.conduttore ?? null))) {
        conduttore.value = proposta.conduttore ?? null;
    }
});

const avviso = computed(() => props.scelte.maggioranze_proposte[materia.value]?.avviso ?? null);
const materie = computed<Opzione[]>(() => props.scelte.materie.map((m) => ({ valore: m.valore, nome: m.nome })));
const maggioranze = computed<Opzione[]>(() => props.scelte.maggioranze.map((m) => ({ valore: m.valore, nome: m.nome })));
const conduttori: Opzione[] = [
    { valore: null, nome: 'Non vota il conduttore' }, { valore: 'vota', nome: 'Vota al posto del proprietario' }, { valore: 'interviene', nome: 'Interviene senza voto' },
];
const perimetri = computed<Opzione[]>(() => (gruppo.value ? [
    { valore: 'condominio', nome: `Tutto il gruppo della tabella «${gruppo.value.nome}»`, sotto: `${gruppo.value.unita} unità` },
] : [
    { valore: 'condominio', nome: 'Tutto il condominio', sotto: `${props.scelte.unita} unità · come l'assemblea` },
    ...props.scelte.palazzine.map((p) => ({ valore: `palazzina:${p.id}`, nome: `Solo la palazzina ${p.nome}`, sotto: `${p.unita} unità` })),
    ...props.scelte.scale.map((s) => ({ valore: `scala:${s.id}`, nome: `Solo la scala ${s.nome}`, sotto: `${s.unita} unità` })),
]));
const nomeDelPerimetro = computed(() => perimetri.value.find((p) => p.valore === perChi.value)?.nome ?? 'Tutto il condominio');
const diUnaParte = computed(() => perChi.value !== 'condominio');
const unitaDelPerimetro = computed(() => perimetri.value.find((p) => p.valore === perChi.value)?.sotto?.split(' ')[0] ?? '');
const tabelleDivise = computed(() => tabellePerIlPerimetro(props.scelte.tabelle, perChi.value));
/** Il perimetro dei controlli delle tabelle: per l'assemblea di un gruppo le unità del gruppo. */
const chiaveDeiControlli = computed(() => (gruppo.value ? `gruppo:${gruppo.value.tabella_id}` : perChi.value));
const tabelle = computed<Opzione[]>(() => (gruppo.value ? [
    { valore: null, nome: gruppo.value.nome, sotto: 'la tabella del gruppo' },
    ...tabelleDelGruppo(props.scelte.tabelle, gruppo.value.tabella_id).map((t) => ({ valore: String(t.id), nome: t.nome, gruppo: true,
        sotto: t.controlli[chiaveDeiControlli.value]?.frase ?? `${t.unita} unità` })),
] : [
    { valore: null, nome: diUnaParte.value ? 'Scegli la tabella' : 'Quella dell\'assemblea', sotto: props.tabellaDellAssemblea ? `«${props.tabellaDellAssemblea}»` : undefined },
    ...tabelleDivise.value.proposte.map((t) => ({ valore: String(t.id), nome: t.nome, gruppo: t.di_un_gruppo === true,
        sotto: `Proposta per ${nomeDelPerimetro.value.replace('Solo ', '')} · ${t.controlli[perChi.value]?.frase ?? ''} · ha tutte e sole le sue unità` })),
    ...tabelleDivise.value.altre.map((t) => ({ valore: String(t.id), nome: t.nome, gruppo: t.di_un_gruppo === true,
        sotto: t.controlli[perChi.value]?.frase ?? `${t.unita} unità` })),
]));
const tabellaScelta = computed(() => props.scelte.tabelle.find((t) => t.id === tabella.value) ?? null);
const controllo = computed(() => tabellaScelta.value?.controlli[chiaveDeiControlli.value] ?? null);
/**
 * Il punto di tutto il condominio nell'assemblea di un gruppo (decisione 699 (3)): la frase del rischio, finché l'amministratore non sceglie «Mettilo lo
 * stesso». Il punto che aveva già quella materia (messo lo stesso prima) non la chiede di nuovo.
 */
const rischio = computed(() => {
    if (!gruppo.value) return null;
    const prima = props.punto?.materia ?? (props.bozza?.mettilo_lo_stesso ? props.bozza.materia : null);
    return prima === materia.value ? null : rischioDelPunto(materia.value);
});
/** La tabella del punto segnata «di un gruppo» (Coda 734): sul punto votano solo i condòmini delle sue unità, le unità del perimetro con la quota. */
const delGruppo = computed(() => tabellaScelta.value?.di_un_gruppo === true);
const unitaDelGruppo = computed(() => controllo.value?.con_quota ?? tabellaScelta.value?.unita ?? 0);
/** La tabella del punto che non comprende tutte le unità del perimetro, senza il segno: le altre contano come teste a zero millesimi. */
const avvisoDellaTabella = computed(() => (delGruppo.value ? null : avvisoDellaTabellaParziale(controllo.value)));
/** Sotto la riga arancio, la proposta di segnarla (scena 2): mai per la tabella che comprende tutte le unità. Il segno vale per tutti i punti che la usano. */
const segnando = ref(false);
function segnaIlGruppo() {
    if (!tabellaScelta.value || segnando.value) return;
    segnando.value = true;
    router.post(route(generateRoute('gestionale.tabella-di-un-gruppo'), { condominio: props.condominioId }),
        { tabella_id: tabellaScelta.value.id, di_un_gruppo: true },
        { only: ['scelte', 'flash'], preserveScroll: true, preserveState: true, onFinish: () => { segnando.value = false; } });
}

/** Scelta la scala, la tabella proposta (tutte e sole le sue unità), se non ce n'era una. */
watch(perChi, (nuovo) => {
    if (nuovo === 'condominio') {
        tabella.value = null;
        return;
    }
    if (tabella.value === null || !tabellaScelta.value?.controlli[nuovo]?.esatta) {
        tabella.value = tabellePerIlPerimetro(props.scelte.tabelle, nuovo).proposte[0]?.id ?? null;
    }
});

const numero = computed(() => props.punto?.numero ?? null);
const adesso = computed(() => {
    const chi = numero.value ? `il punto ${numero.value}` : 'il punto';
    if (gruppo.value) {
        return delGruppo.value
            ? `Adesso: ${chi} si vota fra le ${unitaDelGruppo.value} unità della tabella «${tabellaScelta.value!.nome}»; gli altri punti restano di tutto il gruppo, con la tabella «${gruppo.value.nome}».`
            : `Adesso: il punto è di tutto il gruppo, con la tabella «${gruppo.value.nome}».`;
    }
    if (!diUnaParte.value && delGruppo.value) {
        return `Adesso: ${chi} si vota fra le ${unitaDelGruppo.value} unità della tabella «${tabellaScelta.value!.nome}»; gli altri punti restano di tutto il condominio`
            + `${props.tabellaDellAssemblea ? `, con la tabella «${props.tabellaDellAssemblea}»` : ''}.`;
    }
    if (!diUnaParte.value) {
        const conLaTabella = tabellaScelta.value?.nome ?? props.tabellaDellAssemblea;
        return `Adesso: il punto è di tutto il condominio${conLaTabella ? `, con la tabella «${conLaTabella}»` : ''}.`;
    }
    return `Adesso: ${chi} è ${nomeDelPerimetro.value.replace('Solo ', 'della sola ').replace('della sola la ', 'della sola ')}`
        + `${tabellaScelta.value ? `, con la tabella «${tabellaScelta.value.nome}»` : ''}. Gli altri punti restano di tutto il condominio`
        + `${props.tabellaDellAssemblea ? `, con la tabella «${props.tabellaDellAssemblea}»` : ''}.`;
});

// I proposti, solo quando si aggiunge: con il loro gruppo, nell'ordine dei gruppi.
const coloreDelGruppo: Record<string, keyof typeof CLASSI_DEL_BADGE> = { di_serie: 'neutro', da_te: 'viola', dal_programma: 'blu', dalla_nota: 'ambra' };
const proposti = computed(() => (!nuovo.value ? [] : (props.proposte ?? []).flatMap((g) => g.punti.map((p) => ({ punto: p, gruppo: g })))));
const aggiungendo = ref<string | null>(null);
const soloDaRileggere = computed(() => ['ordine', 'proposte', 'passi', 'flash', ...(props.rileggi ?? [])]);
/** La maggioranza del proposto: quella scelta nel riquadro, o la proposta. */
const maggioranzaDel = (p: PuntoProposto): string | null => (props.maggioranzeScelte && p.chiave_fonte in props.maggioranzeScelte
    ? props.maggioranzeScelte[p.chiave_fonte] ?? null : p.maggioranza);
const nomeDellaMaggioranza = (p: PuntoProposto) => props.scelte.maggioranze.find((m) => m.valore === maggioranzaDel(p))?.nome ?? null;
function aggiungiIlProposto(p: PuntoProposto) {
    if (aggiungendo.value !== null) return;
    aggiungendo.value = p.chiave_fonte;
    router.post(route(generateRoute('gestionale.assemblee.punti.dalle-proposte'), { condominio: props.condominioId, assemblea: props.assembleaId }),
        { proposte: [p.chiave_fonte], maggioranze: { [p.chiave_fonte]: maggioranzaDel(p) } },
        { only: soloDaRileggere.value, preserveScroll: true, preserveState: true,
            onError: (e: Record<string, string>) => { errori.value = e; }, onFinish: () => { aggiungendo.value = null; } });
}

/** Salva il punto; con `loStesso` il punto di tutto il condominio nell'assemblea di un gruppo, scelto con il rischio («Mettilo lo stesso»). */
function salva(loStesso = false) {
    const [ambito, id] = perChi.value.split(':');
    const dati: DatiDelPunto = {
        testo: testo.value, testo_pubblico: conFrasePubblica.value ? (testoPubblico.value.trim() || null) : null, materia: materia.value, maggioranza: maggioranza.value, conduttore: conduttore.value,
        ambito, palazzina_id: ambito === 'palazzina' ? Number(id) : null, scala_id: ambito === 'scala' ? Number(id) : null,
        tabella_id: tabella.value,
        importo: (materia.value === 'delega' || conImporto.value) ? (importo.value.trim() || null) : null,
        ...(materia.value === 'destinazione_uso' ? { dati: { parti_comuni: partiComuni.value || null, nuova_destinazione: nuovaDestinazione.value || null } } : {}),
        ...(materia.value === 'delega' ? { dati: { delega: { ambito: delegaAmbito.value, fino_al: delegaFinoAl.value || null } } } : {}),
        ...(loStesso ? { mettilo_lo_stesso: true } : {}),
    };
    // In «Prepara l'assemblea» il punto resta nella pagina: nasce con l'assemblea.
    if (props.nellaPagina) {
        emit('salva', dati);
        emit('update:open', false);
        return;
    }
    inCorso.value = true;
    const opzioni = {
        only: soloDaRileggere.value, preserveScroll: true, preserveState: true,
        onSuccess: () => emit('update:open', false),
        onError: (e: Record<string, string>) => { errori.value = e; },
        onFinish: () => { inCorso.value = false; },
    };
    if (props.punto) {
        router.patch(route(generateRoute('gestionale.assemblee.punti.update'), { condominio: props.condominioId, assemblea: props.assembleaId, punto: props.punto.id }), dati, opzioni);
    } else {
        router.post(route(generateRoute('gestionale.assemblee.punti.store'), { condominio: props.condominioId, assemblea: props.assembleaId }), dati, opzioni);
    }
}

const testoDelPulsante = computed(() => {
    if (props.nellaPagina) return props.bozza ? 'Salva le modifiche' : 'Aggiungi il punto';
    return inCorso.value ? 'Un momento…' : 'Salva il punto';
});
const etichetta = 'mb-1 flex items-center gap-1 text-xs font-medium text-slate-600 dark:text-slate-400';
</script>

<template>
    <Dialog :open="open" @update:open="$emit('update:open', $event)">
        <DialogContent class="max-h-[100dvh] overflow-y-auto sm:max-w-[620px]" data-testid="finestra-punto" @open-auto-focus.prevent>
            <DialogHeader>
                <DialogTitle>{{ punto ? `Il punto ${punto.numero}` : bozza ? 'Modifica il punto' : 'Aggiungi un punto' }}</DialogTitle>
                <DialogDescription>{{ assembleaNome }}</DialogDescription>
            </DialogHeader>
            <p v-if="nellaPagina" class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200"
                data-testid="punto-nella-pagina">Il punto resta in questa pagina e nasce con l'assemblea, quando premi «Prepara l'assemblea».</p>

            <section v-if="proposti.length" class="space-y-2" data-testid="proposti-nella-finestra">
                <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Proposti, non ancora nell'ordine del giorno</p>
                <ul class="divide-y divide-slate-100 rounded-md border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                    <li v-for="x in proposti" :key="x.punto.chiave_fonte" class="flex items-start gap-3 px-3 py-2.5" data-testid="proposto-nella-finestra">
                        <div class="min-w-0 flex-1">
                            <p class="break-words text-sm text-slate-900 dark:text-slate-100">{{ x.punto.testo }}</p>
                            <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                <span :class="[BADGE, CLASSI_DEL_BADGE[coloreDelGruppo[x.gruppo.chiave] ?? 'neutro']]" data-testid="gruppo-del-proposto">{{ x.gruppo.titolo }}</span>
                                <span v-if="nomeDellaMaggioranza(x.punto)" :class="[BADGE, CLASSI_DEL_BADGE.neutro]" data-testid="maggioranza-del-proposto">{{ nomeDellaMaggioranza(x.punto) }}</span>
                                <span v-if="x.punto.frase" class="min-w-0 break-words">{{ x.punto.frase }}</span>
                            </p>
                            <p v-if="x.punto.avviso" class="mt-1 text-xs text-amber-800 dark:text-amber-300">{{ x.punto.avviso }}</p>
                        </div>
                        <Button variant="outline" size="sm" class="h-8 shrink-0 text-xs" :disabled="aggiungendo !== null" @click="aggiungiIlProposto(x.punto)">
                            {{ aggiungendo === x.punto.chiave_fonte ? 'Un momento…' : 'Aggiungi' }}
                        </Button>
                    </li>
                </ul>
                <p class="border-t border-slate-100 pt-3 text-sm font-semibold text-slate-900 dark:border-slate-800 dark:text-white" data-testid="oppure-scrivi">Oppure scrivi un punto nuovo</p>
            </section>

            <div class="space-y-3.5">
                <label class="block"><span :class="etichetta">Il punto</span>
                    <textarea v-model="testo" rows="3" class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm dark:bg-slate-950" data-testid="testo-punto" />
                    <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">È la frase dell'avviso, che arriva a ogni condòmino.</span>
                    <span v-if="errori.testo" class="mt-1 block text-xs text-rose-700 dark:text-rose-400">{{ errori.testo }}</span>
                </label>
                <div class="space-y-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <div class="flex items-center justify-between gap-3">
                        <span class="flex items-center gap-1.5 text-sm font-medium text-slate-800 dark:text-slate-200">Una frase per la bacheca
                            <SuggerimentoDellaI testid="spiega-bacheca"
                                testo="La convocazione va anche in bacheca, che leggono tutti, inquilini compresi. Lì la frase dell'avviso non va mai, perché può avere nomi e cifre: il programma scrive solo di che cosa parla il punto, per esempio «Lavori nelle parti comuni». Accendi l'interruttore per scrivere tu la frase della bacheca, senza dati delle persone." />
                        </span>
                        <Switch v-model="conFrasePubblica" data-testid="frase-bacheca" />
                    </div>
                    <template v-if="conFrasePubblica">
                        <textarea v-model="testoPubblico" rows="2" class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm dark:bg-slate-950"
                            placeholder="Per esempio: Il cancello del cortile" data-testid="testo-bacheca" />
                        <span class="block text-xs text-slate-500 dark:text-slate-400">La leggono tutti: niente nomi, cifre di una persona o morosità.</span>
                    </template>
                    <p v-else class="text-xs text-slate-500 dark:text-slate-400" data-testid="bacheca-spenta">Spento: in bacheca va solo di che cosa parla il punto, mai la frase dell'avviso.</p>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="menu-campo"><span :class="etichetta">Di che cosa parla</span>
                        <v-select v-model="materia" :options="materie" label="nome" :reduce="(o: Opzione) => o.valore" :clearable="false" :searchable="materie.length > 8" />
                    </div>
                    <div class="menu-campo">
                        <span :class="etichetta">Maggioranza
                            <SuggerimentoDellaI testid="i-maggioranza"
                                testo="Il programma propone la maggioranza della materia: per lavori di notevole entità e per le innovazioni agevolate la metà del valore (art. 1136 c. 4), per le altre innovazioni due terzi (c. 5). La scegli tu." />
                        </span>
                        <v-select v-model="maggioranza" :options="maggioranze" label="nome" :reduce="(o: Opzione) => o.valore" :clearable="true" :searchable="false"
                            placeholder="Scegli la maggioranza" />
                        <!-- La regola scelta, sempre a video: le stesse parole della sala. -->
                        <span class="mt-1 block text-xs text-slate-600 dark:text-slate-300" data-testid="regola-maggioranza">{{ regolaDellaMaggioranza(maggioranza) }}</span>
                    </div>
                    <div class="menu-campo">
                        <span :class="etichetta">Per chi
                            <SuggerimentoDellaI testid="i-per-chi"
                                testo="Di serie il punto è di tutta l'assemblea. Per una spesa di una sola scala, scegli la scala: in sala per quel punto contano solo i suoi condòmini." />
                        </span>
                        <v-select v-model="perChi" :options="perimetri" label="nome" :reduce="(o: Opzione) => o.valore" :clearable="false" :searchable="false" data-testid="per-chi"
                            :disabled="gruppo !== null">
                            <template #option="o"><span class="block">{{ o.nome }}</span><span v-if="o.sotto" class="block text-xs opacity-70">{{ o.sotto }}</span></template>
                        </v-select>
                    </div>
                    <div class="menu-campo">
                        <span :class="etichetta">Con quale tabella
                            <SuggerimentoDellaI testid="i-tabella"
                                testo="Una tabella che non copre la scala si può scegliere lo stesso: il controllo sotto lo dice, come per l'assemblea, e non blocca." />
                        </span>
                        <v-select :model-value="tabella === null ? null : String(tabella)" :options="tabelle" label="nome" :reduce="(o: Opzione) => o.valore" :clearable="false"
                            :searchable="tabelle.length > 8" data-testid="con-quale-tabella" @update:model-value="(v: string | null) => (tabella = v === null ? null : Number(v))">
                            <template #option="o"><span class="flex items-center gap-1.5">{{ o.nome }}<span v-if="o.gruppo" :class="[BADGE, CLASSI_DEL_BADGE.blu]">Di un gruppo</span></span><span v-if="o.sotto" class="block text-xs opacity-70">{{ o.sotto }}</span></template>
                        </v-select>
                    </div>
                    <div class="menu-campo sm:col-span-2"><span :class="etichetta">Il conduttore</span>
                        <v-select v-model="conduttore" :options="conduttori" label="nome" :reduce="(o: Opzione) => o.valore" :clearable="false" :searchable="false" />
                    </div>
                </div>

                <label v-if="conImporto" class="block" data-testid="importo-del-punto-riga"><span :class="etichetta">Importo (facoltativo)</span>
                    <input v-model="importo" type="text" inputmode="decimal" class="h-9 w-full rounded-md border border-input bg-white px-3 text-sm dark:bg-slate-950 sm:w-60"
                        placeholder="14.500,00" data-testid="importo-del-punto" />
                    <span class="mt-1 block text-xs text-slate-600 dark:text-slate-300">Serve al seguito: «Crea il piano straordinario» lo propone e il fondo speciale lo confronta.</span>
                    <span v-if="errori.importo" class="mt-1 block text-xs text-rose-700 dark:text-rose-400">{{ errori.importo }}</span>
                </label>

                <div v-if="materia === 'destinazione_uso'" class="grid grid-cols-1 gap-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 dark:border-amber-800 dark:bg-amber-950/40"
                    data-testid="destinazione-uso-punto">
                    <p class="text-xs text-amber-900 dark:text-amber-200">L'avviso deve dire le due cose, a pena di nullità (art. 1117-ter).</p>
                    <label class="block"><span :class="etichetta">Le parti comuni che cambiano</span>
                        <input v-model="partiComuni" type="text" class="h-9 w-full rounded-md border border-input bg-white px-3 text-sm dark:bg-slate-950"
                            placeholder="Per esempio: il locale delle biciclette" data-testid="parti-comuni" />
                        <span v-if="errori['dati.parti_comuni']" class="mt-1 block text-xs text-rose-700 dark:text-rose-400">{{ errori['dati.parti_comuni'] }}</span>
                    </label>
                    <label class="block"><span :class="etichetta">La nuova destinazione d'uso</span>
                        <input v-model="nuovaDestinazione" type="text" class="h-9 w-full rounded-md border border-input bg-white px-3 text-sm dark:bg-slate-950"
                            placeholder="Per esempio: deposito dei passeggini" data-testid="nuova-destinazione" />
                        <span v-if="errori['dati.nuova_destinazione']" class="mt-1 block text-xs text-rose-700 dark:text-rose-400">{{ errori['dati.nuova_destinazione'] }}</span>
                    </label>
                </div>

                <!-- La delega all'amministratore (decisione 622 (3), la scena 4 della bozza approvata): il tetto, per che cosa, fino a quando. È la delibera
                     stessa: lo stralcio, nella sua beta, la legge da qui. -->
                <div v-if="materia === 'delega'" class="space-y-3 rounded-md border border-slate-200 px-3 py-2.5 dark:border-slate-700" data-testid="la-delega">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">La delega</p>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <label class="block"><span :class="etichetta">Fino a</span>
                            <input v-model="importo" type="text" inputmode="decimal" class="h-9 w-full rounded-md border border-input bg-white px-3 text-sm dark:bg-slate-950"
                                placeholder="2.000,00" data-testid="delega-tetto" />
                            <span v-if="errori.importo" class="mt-1 block text-xs text-rose-700 dark:text-rose-400">{{ errori.importo }}</span>
                        </label>
                        <div class="menu-campo"><span :class="etichetta">Per che cosa</span>
                            <v-select v-model="delegaAmbito" :options="ambitiDellaDelega" label="nome" :reduce="(o: Opzione) => o.valore" :clearable="false"
                                :searchable="false" data-testid="delega-ambito" />
                        </div>
                        <label class="block"><span :class="etichetta">Fino al</span>
                            <CampoGiorno v-model="delegaFinoAl" class="h-9 text-sm" svuotabile data-testid="delega-fino-al" />
                            <span v-if="errori['dati.delega.fino_al']" class="mt-1 block text-xs text-rose-700 dark:text-rose-400">{{ errori['dati.delega.fino_al'] }}</span>
                        </label>
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-300">«Accordi con i fornitori» è lo stralcio del debito: chiudere con un fornitore pagando meno del dovuto, entro il tetto.</p>
                    <p v-if="importo.trim() === ''" class="text-xs text-amber-800 dark:text-amber-300" data-testid="delega-senza-tetto">
                        Senza il tetto la delega non vale finché non lo scrivi: i poteri dell'amministratore li conferisce l'assemblea (art. 1131 c. 1 c.c.).</p>
                    <p class="text-xs text-slate-600 dark:text-slate-300">In bacheca e nella convocazione affissa va la riga neutra: «Delega all'amministratore per gli accordi con i fornitori.»</p>
                </div>

                <p v-if="avviso" class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200" data-testid="avviso-maggioranza">
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" /> {{ avviso }}
                </p>

                <p v-if="tabellaScelta && controllo" class="text-xs text-slate-600 dark:text-slate-300" data-testid="controllo-tabella-punto">
                    Tabella «{{ tabellaScelta.nome }}» · {{ controllo.frase }}{{ diUnaParte ? ` ${nomeDelPerimetro.replace('Solo la ', 'della ')}` : '' }}
                </p>
                <p v-if="avvisoDellaTabella" class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                    data-testid="avviso-tabella-parziale">
                    <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" /> {{ avvisoDellaTabella }}
                </p>
                <!-- La proposta del segno (Coda 734, scena 2): sotto la riga arancio, con il pulsante; la finestra resta aperta e rilegge le scelte. -->
                <div v-if="avvisoDellaTabella && tabellaScelta" class="flex flex-col gap-2 rounded-md border border-slate-200 px-3 py-2 text-xs text-slate-700 dark:border-slate-700 dark:text-slate-300 sm:flex-row sm:items-center sm:justify-between"
                    data-testid="proposta-del-gruppo">
                    <p>Se ciò che la tabella «{{ tabellaScelta.nome }}» divide è solo dei proprietari di quelle {{ controllo?.con_quota }} unità, segna «{{ tabellaScelta.nome }}» come tabella di un gruppo: sul punto voteranno solo loro, e si conteranno fra loro (art. 1123 c. 3 c.c.).</p>
                    <Button variant="outline" size="sm" class="h-8 shrink-0 text-xs" :disabled="segnando" data-testid="segna-il-gruppo" @click="segnaIlGruppo">{{ segnando ? 'Un momento…' : `Segna «${tabellaScelta.nome}» come tabella di un gruppo` }}</Button>
                </div>
                <p class="text-xs text-slate-600 dark:text-slate-300" data-testid="adesso-punto">{{ adesso }}</p>
                <p v-if="gruppo" class="text-xs text-slate-600 dark:text-slate-300" data-testid="riga-del-gruppo">In questa assemblea votano solo i condòmini della tabella «{{ gruppo.nome }}».</p>
                <!-- Il punto di tutto il condominio nell'assemblea di un gruppo (decisione 699 (3)): si ferma, e decide l'amministratore. -->
                <div v-if="rischio" class="space-y-2" data-testid="il-rischio">
                    <p class="text-xs text-rose-700 dark:text-rose-400" data-testid="rischio-di-tutti">{{ rischio }}</p>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-slate-600 dark:text-slate-300">Mettilo in un'assemblea di tutto il condominio, o mettilo lo stesso: la scelta resta nel registro delle modifiche, con il tuo nome.</p>
                        <Button variant="outline" size="sm" class="h-8 shrink-0 text-xs" :disabled="testo.trim() === '' || inCorso" data-testid="mettilo-lo-stesso" @click="salva(true)">Mettilo lo stesso</Button>
                    </div>
                </div>
                <div v-if="delGruppo" class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200" data-testid="sala-di-una-parte">
                    <p class="font-medium">In sala, per questo punto, votano solo i condòmini {{ diUnaParte ? `${nomeDelPerimetro.replace('Solo la ', 'della ')} che hanno una quota nella tabella «${tabellaScelta!.nome}»` : `delle ${unitaDelGruppo} unità della tabella «${tabellaScelta!.nome}»` }}.</p>
                    <p class="mt-0.5">Presenze, teste e millesimi si contano fra loro (art. 1123 c. 3 c.c.).<template v-if="!gruppo"> La convocazione va comunque a tutti i condòmini di {{ condominioNome }}, con l'ordine del giorno intero; sotto il punto, l'avviso dice chi vota.</template></p>
                </div>
                <div v-else-if="diUnaParte" class="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200" data-testid="sala-di-una-parte">
                    <p class="font-medium">In sala, per questo punto, contano solo i condòmini {{ nomeDelPerimetro.replace('Solo la ', 'della ') }}.</p>
                    <p class="mt-0.5">Presenze, teste e millesimi si contano fra le {{ unitaDelPerimetro }} unità {{ nomeDelPerimetro.replace('Solo la ', 'della ') }}{{ tabellaScelta ? `, sulla tabella «${tabellaScelta.nome}»` : '' }}.
                        La convocazione va comunque a tutti i condòmini di {{ condominioNome }}, con l'ordine del giorno intero.</p>
                </div>
                <p v-for="(m, k) in errori" :key="k" v-show="k !== 'testo'" class="text-xs text-rose-700 dark:text-rose-400">{{ m }}</p>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-3 sm:flex-row sm:justify-end dark:border-slate-800">
                <Button variant="outline" @click="$emit('update:open', false)">Annulla</Button>
                <Button :disabled="testo.trim() === '' || inCorso || rischio !== null" data-testid="salva-punto" @click="salva()">{{ testoDelPulsante }}</Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
