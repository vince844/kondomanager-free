<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * L'ordine del giorno di un'assemblea in preparazione (§3 P2; scena 1 della bozza della pagina, scena 2b della bozza delle scene della tappa 2): i punti con
 * il numero e i badge (la maggioranza, «Votano i conduttori», «Memo», «Solo la scala B», «Tabella «Ascensore B»»), «Aggiungi un punto», e per ogni punto
 * «Modifica», «Sposta su», «Sposta giù», «Togli». Sotto, i punti proposti che non ci sono ancora (le proposte, differite): spuntati di serie, «Aggiungi».
 *
 * Ogni gesto è una visita parziale che rilegge solo `ordine`, `proposte` e `passi` (`preserveScroll`), più il passo «Convocazione» o «Documenti» se la pagina
 * lo ha già letto (`rileggi`: dopo una stampa le lettere da ristampare si vedono subito). Dalla prima spedizione l'ordine del giorno non si cambia: i pulsanti
 * spariscono e lo dice la frase. Al telefono l'ordine del giorno va a righe piene. «Aggiungi un punto» porta anche i proposti, con la maggioranza scelta qui.
 */
import { computed, ref, watch } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { ArrowDown, ArrowUp, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import FinestraDelPunto from '@/components/assemblee/FinestraDelPunto.vue';
import StatoDiEsecuzioneDelPunto from '@/components/assemblee/seguito/StatoDiEsecuzioneDelPunto.vue';
import { usePermission } from '@/composables/permissions';
import { BADGE, CLASSI_DEL_BADGE, coloreDellaMaggioranza } from '@/lib/assemblee/assemblee';
import type { AssembleaDellaPagina, PuntoDellaPagina, ScelteDelleAssemblee } from '@/lib/assemblee/pagine';
import type { GruppoDiProposte, PuntoProposto } from '@/types/assemblee';

const props = withDefaults(defineProps<{
    condominioId: number;
    condominioNome: string;
    assemblea: AssembleaDellaPagina;
    ordine: PuntoDellaPagina[];
    scelte: ScelteDelleAssemblee;
    proposte?: GruppoDiProposte[] | null;
    gestisce: boolean;
    /** Il punto da evidenziare (`?punto=` dal registro o dal memo). */
    evidenzia?: number | null;
    /** Le prop della pagina già lette da rileggere con ogni gesto (`convocazione`, `fascicolo`). */
    rileggi?: string[];
}>(), { proposte: null, evidenzia: null, rileggi: () => [] });

const { generateRoute } = usePermission();
const siCambia = computed(() => props.gestisce && props.assemblea.in_preparazione);
const finestra = ref(false);
const inModifica = ref<PuntoDellaPagina | null>(null);
const daTogliere = ref<PuntoDellaPagina | null>(null);
const confermaTogli = ref(false);

const visita = computed(() => ({ only: ['ordine', 'proposte', 'passi', 'flash', ...props.rileggi], preserveScroll: true, preserveState: true }));
const nomeDellAssemblea = computed(() => `${props.assemblea.titolo}${props.assemblea.data ? ` del ${props.assemblea.data.split('-').reverse().join('/')}` : ''}`);

function aggiungi() {
    inModifica.value = null;
    finestra.value = true;
}

function modifica(p: PuntoDellaPagina) {
    inModifica.value = p;
    finestra.value = true;
}

function chiediDiTogliere(p: PuntoDellaPagina) {
    daTogliere.value = p;
    confermaTogli.value = true;
}

function togli() {
    if (!daTogliere.value) return;
    router.delete(route(generateRoute('gestionale.assemblee.punti.destroy'), { condominio: props.condominioId, assemblea: props.assemblea.id, punto: daTogliere.value.id }),
        { ...visita.value, onSuccess: () => { confermaTogli.value = false; } });
}

function sposta(p: PuntoDellaPagina, verso: -1 | 1) {
    const ids = props.ordine.map((x) => x.id);
    const i = ids.indexOf(p.id);
    const j = i + verso;
    if (j < 0 || j >= ids.length) return;
    [ids[i], ids[j]] = [ids[j], ids[i]];
    router.post(route(generateRoute('gestionale.assemblee.punti.riordina'), { condominio: props.condominioId, assemblea: props.assemblea.id }), { ordine: ids }, visita.value);
}

// Le proposte che non sono ancora nell'ordine del giorno: spuntate di serie, con la maggioranza da cambiare (scena 4 della bozza del memo).
const spuntate = ref<Record<string, boolean>>({});
const maggioranze = ref<Record<string, string | null>>({});
watch(() => props.proposte, (gruppi) => {
    const s: Record<string, boolean> = {};
    const m: Record<string, string | null> = {};
    (gruppi ?? []).forEach((g) => g.punti.forEach((p) => { s[p.chiave_fonte] = spuntate.value[p.chiave_fonte] ?? p.spuntato; m[p.chiave_fonte] = maggioranze.value[p.chiave_fonte] ?? p.maggioranza; }));
    spuntate.value = s;
    maggioranze.value = m;
}, { immediate: true });
const gruppiConPunti = computed(() => (props.proposte ?? []).filter((g) => g.punti.length > 0));
const quanteSpuntate = computed(() => Object.values(spuntate.value).filter(Boolean).length);
const maggioranzeDelMenu = computed(() => props.scelte.maggioranze);

function aggiungiLeProposte() {
    const chiavi = Object.entries(spuntate.value).filter(([, v]) => v).map(([k]) => k);
    if (chiavi.length === 0) return;
    router.post(route(generateRoute('gestionale.assemblee.punti.dalle-proposte'), { condominio: props.condominioId, assemblea: props.assemblea.id }),
        { proposte: chiavi, maggioranze: Object.fromEntries(chiavi.map((k) => [k, maggioranze.value[k] ?? null])) }, visita.value);
}

const fraseDellaProposta = (p: PuntoProposto) => p.frase ?? (p.spuntato ? null : 'Non spuntato: resta per la prossima assemblea.');
const pulsante = 'inline-flex h-8 items-center justify-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
</script>

<template>
    <section id="ordine-del-giorno" class="scroll-mt-4 rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="ordine-del-giorno">
        <header class="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
            <div>
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Ordine del giorno</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400">Proposto dal programma, dai tuoi memo e dalla nota sintetica; lo riscrivi come vuoi.</p>
            </div>
            <button v-if="siCambia" type="button" :class="pulsante" data-testid="aggiungi-punto" @click="aggiungi"><Plus class="h-3.5 w-3.5" /> Aggiungi un punto</button>
        </header>

        <p v-if="!assemblea.in_preparazione && assemblea.stato === 'convocata'" class="px-4 pt-3 text-xs text-slate-500 dark:text-slate-400">
            Dalla prima spedizione date e ordine del giorno non si cambiano più: si annulla e si riconvoca.
        </p>
        <p v-if="ordine.length === 0" class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400" data-testid="ordine-vuoto">
            Nessun punto: spunta quelli proposti o «Aggiungi un punto».
        </p>

        <ol v-else class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="(p, i) in ordine" :key="p.id" class="flex items-start gap-3 px-4 py-2.5"
                :class="{ 'bg-sky-50/60 dark:bg-sky-950/20': evidenzia === p.id }" data-testid="punto">
                <span class="w-6 shrink-0 pt-0.5 text-right text-sm font-semibold tabular-nums text-slate-500 dark:text-slate-400">{{ p.numero }}</span>
                <div class="min-w-0 flex-1">
                    <p class="break-words text-sm text-slate-900 dark:text-slate-100">{{ p.testo }}</p>
                    <StatoDiEsecuzioneDelPunto v-if="p.dati?.stato_esecuzione" :elenco="p.dati.stato_esecuzione" :stampa="p.dati.stampa_stato ?? null" />
                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                        <span v-if="p.conduttore_parola" :class="[BADGE, CLASSI_DEL_BADGE.viola]">{{ p.conduttore_parola }}</span>
                        <span v-if="p.perimetro_parola" :class="[BADGE, CLASSI_DEL_BADGE.viola]" data-testid="badge-perimetro">{{ p.perimetro_parola }}</span>
                        <span v-if="p.perimetro_parola && p.tabella_nome" :class="[BADGE, CLASSI_DEL_BADGE.neutro]" data-testid="badge-tabella">Tabella «{{ p.tabella_nome }}»</span>
                        <span v-if="p.da_memo" :class="[BADGE, CLASSI_DEL_BADGE.neutro]">Memo</span>
                        <span v-if="p.maggioranza_parola" :class="[BADGE, CLASSI_DEL_BADGE[coloreDellaMaggioranza(p.maggioranza)]]" data-testid="badge-maggioranza">{{ p.maggioranza_parola }}</span>
                        <span v-else :class="[BADGE, CLASSI_DEL_BADGE.ambra]">Maggioranza da scegliere</span>
                    </div>
                </div>
                <DropdownMenu v-if="siCambia">
                    <DropdownMenuTrigger as-child>
                        <button type="button" class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                            :aria-label="`Il punto ${p.numero}`" data-testid="menu-punto"><MoreHorizontal class="h-4 w-4" /></button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-48">
                        <DropdownMenuItem @select="modifica(p)"><Pencil class="mr-2 h-4 w-4" /> Modifica</DropdownMenuItem>
                        <DropdownMenuItem :disabled="i === 0" @select="sposta(p, -1)"><ArrowUp class="mr-2 h-4 w-4" /> Sposta su</DropdownMenuItem>
                        <DropdownMenuItem :disabled="i === ordine.length - 1" @select="sposta(p, 1)"><ArrowDown class="mr-2 h-4 w-4" /> Sposta giù</DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem class="text-rose-700 focus:text-rose-700 dark:text-rose-400" @select="chiediDiTogliere(p)"><Trash2 class="mr-2 h-4 w-4" /> Togli</DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </li>
        </ol>

        <!-- I punti proposti che aspettano: spuntati di serie; ciò che si toglie resta per la prossima. -->
        <div v-if="siCambia && gruppiConPunti.length" id="proposti" class="scroll-mt-4 space-y-3 border-t border-slate-100 px-4 py-3 dark:border-slate-800" data-testid="proposte-da-aggiungere">
            <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Proposti, non ancora nell'ordine del giorno</p>
            <div v-for="g in gruppiConPunti" :key="g.chiave" class="space-y-1.5">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ g.titolo }}</p>
                <div v-for="p in g.punti" :key="p.chiave_fonte" class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-2.5 gap-y-1.5 sm:grid-cols-[auto_minmax(0,1fr)_230px]">
                    <Checkbox v-model="spuntate[p.chiave_fonte]" class="mt-1 rounded" :aria-label="p.testo" />
                    <div class="min-w-0">
                        <p class="break-words text-sm text-slate-900 dark:text-slate-100">{{ p.testo }}</p>
                        <p v-if="!spuntate[p.chiave_fonte]" class="text-xs text-slate-500 dark:text-slate-400">Non spuntato: resta per la prossima assemblea.</p>
                        <p v-else-if="fraseDellaProposta(p)" class="text-xs text-slate-500 dark:text-slate-400">{{ fraseDellaProposta(p) }}</p>
                        <p v-if="p.avviso" class="text-xs text-amber-800 dark:text-amber-300">{{ p.avviso }}</p>
                        <StatoDiEsecuzioneDelPunto v-if="p.dati?.stato_esecuzione" :elenco="p.dati.stato_esecuzione" />
                    </div>
                    <div class="menu-campo col-start-2 sm:col-start-3">
                        <v-select v-model="maggioranze[p.chiave_fonte]" :options="maggioranzeDelMenu" label="nome" :reduce="(s: { valore: string }) => s.valore"
                            :clearable="true" :searchable="false" placeholder="Maggioranza" />
                    </div>
                </div>
            </div>
            <button type="button" :class="pulsante" :disabled="quanteSpuntate === 0" data-testid="aggiungi-proposte" @click="aggiungiLeProposte">
                <Plus class="h-3.5 w-3.5" /> {{ quanteSpuntate === 1 ? 'Aggiungi 1 punto' : `Aggiungi ${quanteSpuntate} punti` }}
            </button>
        </div>
        <p v-else-if="siCambia && proposte !== null && gruppiConPunti.length === 0" class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400"
            data-testid="niente-aspetta">Niente aspetta un'assemblea di {{ condominioNome }}.</p>

        <FinestraDelPunto v-model:open="finestra" :condominio-id="condominioId" :condominio-nome="condominioNome" :assemblea-id="assemblea.id"
            :assemblea-nome="nomeDellAssemblea" :tabella-dell-assemblea="assemblea.tabella?.nome ?? null" :punto="inModifica" :scelte="scelte" :proposte="proposte"
            :maggioranze-scelte="maggioranze" :rileggi="rileggi" />
        <ConfirmDialog v-model="confermaTogli" :title="daTogliere ? `Togliere il punto ${daTogliere.numero}?` : 'Togliere il punto?'" confirm-text="Togli il punto"
            :description="daTogliere?.da_memo ? 'Il memo torna nella memoria del condominio, e ti viene proposto alla prossima assemblea.' : 'Gli altri punti si rinumerano. La riga resta nel registro delle modifiche.'"
            @confirm="togli" />
    </section>
</template>
