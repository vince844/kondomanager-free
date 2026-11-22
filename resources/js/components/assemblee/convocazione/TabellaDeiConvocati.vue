<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La tabella dei convocati del passo «Convocazione» (scena 2 della bozza approvata della pagina dell'assemblea): colonne fisse Persona, Unità, Mezzo,
 * Stato, Prova. Lo stato è un badge con la sua parola (Consegnata, In giacenza, Spedita, Da consegnare, Non convocabile, e Rifiutata, Non consegnata,
 * Da spedire, Stampata, Non partita, Da verificare) e il dettaglio accanto; la prova è il nome del documento con il collegamento. Il menu «⋯» di una riga ha
 * i gesti che la riga permette. Al telefono una card per persona, con lo stato e il mezzo.
 *
 * Sulla riga di una PEC si trascina la ricevuta del gestore (il file `.eml`): il programma ne legge il giorno e l'ora (decisione 577). La stessa cosa con
 * «Carica la ricevuta PEC» del menu, e al telefono con il pulsante.
 *
 * Decisione 647: ogni riga che si stampa o si manda ha la sua spunta (di serie tutte spuntate; «Tutti» e «Nessuno» in testa), e il menu del mezzo, per questa
 * assemblea, con «Ricordalo nella sua scheda» sotto. Le spunte contano solo le righe del mezzo dei due pulsanti della scheda. Al telefono la spunta sta a
 * sinistra e il menu del mezzo sotto il nome.
 */
import { computed, ref } from 'vue';
import { MoreHorizontal } from 'lucide-vue-next';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { classiBadge } from './aiuti';
import type { MezzoConvocazione, RigaDelConvocato } from './tipi';

const props = withDefaults(defineProps<{
    righe: RigaDelConvocato[];
    gestisce: boolean;
    /** Le righe di tutta la scheda (la tabella ne mostra le prime otto): le spunte di «Tutti» e «Nessuno» valgono per tutte. */
    tutte?: RigaDelConvocato[];
    /** Le chiavi di chi è stato tolto dalla spunta: di serie nessuna, quindi tutti spuntati. */
    nonScelte?: ReadonlySet<string>;
}>(), { tutte: undefined, nonScelte: () => new Set<string>() });
const emit = defineEmits<{
    (e: 'azione', tipo: 'spedizione' | 'consegna' | 'correggi' | 'prova' | 'ritiro' | 'ricevuta', riga: RigaDelConvocato): void;
    (e: 'ricevuta', riga: RigaDelConvocato, file: File): void;
    (e: 'spunta', riga: RigaDelConvocato, valore: boolean): void;
    (e: 'tutti'): void;
    (e: 'nessuno'): void;
    (e: 'mezzo', riga: RigaDelConvocato, mezzo: MezzoConvocazione | null, ricorda: boolean): void;
}>();

/** Una riga con la spunta: chi si stampa o si manda adesso. */
const spuntabile = (r: RigaDelConvocato) => r.per.lettere || r.per.pec;
const conSpunte = computed(() => props.gestisce && (props.tutte ?? props.righe).some(spuntabile));
const quanteSpuntate = computed(() => (props.tutte ?? props.righe).filter((r) => spuntabile(r) && !props.nonScelte.has(r.chiave)).length);
const quanteSpuntabili = computed(() => (props.tutte ?? props.righe).filter(spuntabile).length);

const MEZZI: { valore: MezzoConvocazione; etichetta: string }[] = [
    { valore: 'pec', etichetta: 'PEC' },
    { valore: 'raccomandata', etichetta: 'Raccomandata' },
    { valore: 'a_mano', etichetta: 'A mano' },
    { valore: 'fax', etichetta: 'Fax' },
];
/** La PEC solo se la persona ha una PEC: senza, la voce c'è ma non si sceglie. */
const sceglibile = (r: RigaDelConvocato) => (m: { valore: MezzoConvocazione }) => m.valore !== 'pec' || r.scelta.ha_pec;
/** «Ricordalo nella sua scheda» si offre quando il mezzo della riga non è già quello della scheda. */
const siRicorda = (r: RigaDelConvocato) => r.scelta.si_puo && r.mezzo !== null && r.mezzo !== r.scelta.della_scheda;

/** La riga ha un gesto nel menu: un'azione permessa, o l'avviso conservato da aprire. */
function haMenu(r: RigaDelConvocato, gestisce: boolean): boolean {
    return !!r.pdf_url || (gestisce && (r.azioni.spedizione || r.azioni.consegna || r.azioni.correggi || r.azioni.prova || r.azioni.ritiro || r.azioni.ricevuta));
}

// La ricevuta trascinata sulla riga: la riga sotto il file si colora, e al rilascio il file va alla scheda.
const sopra = ref<string | null>(null);
function siTrascina(r: RigaDelConvocato, gestisce: boolean, e: DragEvent) {
    if (!gestisce || !r.azioni.ricevuta) return;
    e.preventDefault();
    sopra.value = r.chiave;
}
function rilasciata(r: RigaDelConvocato, gestisce: boolean, e: DragEvent) {
    sopra.value = null;
    const file = e.dataTransfer?.files?.[0];
    if (!gestisce || !r.azioni.ricevuta || !file) return;
    e.preventDefault();
    emit('ricevuta', r, file);
}
</script>

<template>
    <div>
        <!-- Chi si stampa o si manda adesso: di serie tutti spuntati; «Tutti» e «Nessuno» valgono per tutte le righe, non solo per quelle che si vedono. -->
        <div v-if="conSpunte" class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-slate-100 px-4 py-2 text-xs text-slate-600 dark:border-slate-800 dark:text-slate-300 sm:px-5"
            data-testid="spunte-in-testa">
            <span>Stampa o manda a: <b class="tabular-nums" data-testid="spunte-quante">{{ quanteSpuntate }} su {{ quanteSpuntabili }}</b></span>
            <button type="button" class="underline underline-offset-2 hover:text-slate-900 dark:hover:text-slate-100" data-testid="spunte-tutti" @click="emit('tutti')">Tutti</button>
            <button type="button" class="underline underline-offset-2 hover:text-slate-900 dark:hover:text-slate-100" data-testid="spunte-nessuno" @click="emit('nessuno')">Nessuno</button>
        </div>
        <table class="hidden w-full table-fixed text-sm md:table" data-testid="tabella-convocati">
            <colgroup>
                <col v-if="conSpunte" class="w-10" />
                <col class="w-[26%]" />
                <col class="w-[104px]" />
                <col class="w-[184px]" />
                <col />
                <col class="w-[104px]" />
                <col class="w-10" />
            </colgroup>
            <thead>
                <tr class="bg-slate-50 text-left text-xs font-semibold text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                    <th v-if="conSpunte" class="px-2 py-2"><span class="sr-only">Chi stampare o mandare</span></th>
                    <th class="whitespace-nowrap px-4 py-2 font-semibold">Persona</th>
                    <th class="whitespace-nowrap px-2 py-2 font-semibold">Unità</th>
                    <th class="whitespace-nowrap px-2 py-2 font-semibold">Mezzo</th>
                    <th class="whitespace-nowrap px-2 py-2 font-semibold">Stato</th>
                    <th class="whitespace-nowrap px-4 py-2 text-right font-semibold">Prova</th>
                    <th class="px-2 py-2"><span class="sr-only">Che cosa fare</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in righe" :key="r.chiave" class="border-t border-slate-100 align-top dark:border-slate-800" data-testid="riga-convocato"
                    :class="sopra === r.chiave ? 'bg-sky-50 outline-2 -outline-offset-2 outline-dashed outline-sky-400 dark:bg-sky-950/40' : ''"
                    @dragover="siTrascina(r, gestisce, $event)" @dragleave="sopra = null" @drop="rilasciata(r, gestisce, $event)">
                    <td v-if="conSpunte" class="px-2 py-2.5 text-center">
                        <Checkbox v-if="spuntabile(r)" :model-value="!nonScelte.has(r.chiave)" :aria-label="`Includi ${r.nome}`" data-testid="spunta-riga"
                            @update:model-value="(v: boolean | 'indeterminate') => emit('spunta', r, v === true)" />
                    </td>
                    <td class="px-4 py-2.5">
                        <span class="text-slate-900 dark:text-slate-100">{{ r.nome }}</span>
                        <span v-if="r.ruolo" class="ml-1 text-xs text-slate-500 dark:text-slate-400">{{ r.ruolo }}</span>
                    </td>
                    <td class="px-2 py-2.5 text-slate-700 dark:text-slate-300">
                        {{ r.unita }} <span v-if="r.quota" class="text-xs text-slate-500 dark:text-slate-400">{{ r.quota }}</span>
                    </td>
                    <td class="px-2 py-2.5">
                        <div v-if="gestisce && r.scelta.si_puo" data-testid="mezzo-della-riga">
                            <div class="menu-campo menu-campo-piccolo w-40 max-w-full text-xs">
                                <v-select :options="MEZZI" label="etichetta" :model-value="r.mezzo" :selectable="sceglibile(r)" :searchable="false" :clearable="false"
                                    :reduce="(m: { valore: MezzoConvocazione }) => m.valore" placeholder="Nessun mezzo" :aria-label="`Mezzo per ${r.nome}`"
                                    @update:model-value="(v: MezzoConvocazione | null) => emit('mezzo', r, v ?? null, false)" />
                            </div>
                            <label v-if="siRicorda(r)" class="mt-1 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                <Checkbox :model-value="false" :aria-label="`Ricorda il mezzo nella scheda di ${r.nome}`" data-testid="ricordalo"
                                    @update:model-value="(v: boolean | 'indeterminate') => v === true && emit('mezzo', r, r.mezzo, true)" />
                                Ricordalo nella sua scheda
                            </label>
                            <span v-if="r.pec_non_comunicata" class="block text-xs text-amber-700 dark:text-amber-300">non comunicata</span>
                            <span v-else-if="r.scelta.per_assemblea" class="block text-xs text-slate-500 dark:text-slate-400">solo per questa assemblea</span>
                            <span v-else-if="r.mezzo_proposto" class="block text-xs text-slate-500 dark:text-slate-400">proposto</span>
                        </div>
                        <template v-else>
                            <span :class="r.mezzo ? 'text-slate-700 dark:text-slate-300' : 'text-rose-700 dark:text-rose-300'">{{ r.mezzo_parola }}</span>
                            <span v-if="r.pec_non_comunicata" class="block text-xs text-amber-700 dark:text-amber-300">non comunicata</span>
                            <span v-else-if="r.mezzo_proposto" class="block text-xs text-slate-500 dark:text-slate-400">proposto</span>
                        </template>
                    </td>
                    <td class="px-2 py-2.5">
                        <span :class="classiBadge(r.colore)" data-testid="stato-convocato">{{ r.stato_parola }}</span>
                        <span v-if="r.dettaglio" class="ml-1.5 text-xs text-slate-500 dark:text-slate-400">{{ r.dettaglio }}</span>
                    </td>
                    <td class="px-4 py-2.5">
                        <div class="flex items-center justify-end">
                            <a v-if="r.prova" :href="r.prova.url" target="_blank" rel="noopener" class="text-xs text-slate-700 underline underline-offset-2 dark:text-slate-300">{{ r.prova.nome }}</a>
                            <span v-else-if="gestisce && r.azioni.ricevuta" class="text-xs text-slate-500 dark:text-slate-400" data-testid="trascina-ricevuta">trascina qui la ricevuta</span>
                            <span v-else class="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400" data-testid="nessuna-prova">Nessuna, per ora</span>
                        </div>
                    </td>
                    <td class="px-2 py-2.5 text-right">
                        <div class="flex items-center justify-end">
                            <DropdownMenu v-if="haMenu(r, gestisce)">
                                <DropdownMenuTrigger as-child>
                                    <button type="button" class="inline-flex h-7 w-7 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                                        :aria-label="`Che cosa fare per ${r.nome}`" data-testid="menu-riga">
                                        <MoreHorizontal class="h-4 w-4" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" class="w-56">
                                    <DropdownMenuItem v-if="gestisce && r.azioni.spedizione" @click="emit('azione', 'spedizione', r)">Registra la spedizione</DropdownMenuItem>
                                    <DropdownMenuItem v-if="gestisce && r.azioni.ricevuta" @click="emit('azione', 'ricevuta', r)">Carica la ricevuta PEC</DropdownMenuItem>
                                    <DropdownMenuItem v-if="gestisce && r.azioni.consegna" @click="emit('azione', 'consegna', r)">Registra la consegna</DropdownMenuItem>
                                    <DropdownMenuItem v-if="gestisce && r.azioni.ritiro" @click="emit('azione', 'ritiro', r)">Registra il ritiro</DropdownMenuItem>
                                    <DropdownMenuItem v-if="gestisce && r.azioni.correggi" @click="emit('azione', 'correggi', r)">Correggi la consegna</DropdownMenuItem>
                                    <DropdownMenuItem v-if="gestisce && r.azioni.prova" @click="emit('azione', 'prova', r)">Allega la prova</DropdownMenuItem>
                                    <DropdownMenuItem v-if="r.pdf_url" as-child>
                                        <a :href="r.pdf_url" target="_blank" rel="noopener">Apri l'avviso</a>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>

        <!-- Al telefono una card per persona: il nome, lo stato e il mezzo, poi il dettaglio, la prova e i gesti. -->
        <ul class="divide-y divide-slate-100 md:hidden dark:divide-slate-800" data-testid="card-convocati">
            <li v-for="r in righe" :key="r.chiave" class="px-4 py-3 text-sm">
                <div class="flex items-start gap-3">
                    <Checkbox v-if="conSpunte && spuntabile(r)" class="mt-0.5 shrink-0" :model-value="!nonScelte.has(r.chiave)" :aria-label="`Includi ${r.nome}`" data-testid="spunta-card"
                        @update:model-value="(v: boolean | 'indeterminate') => emit('spunta', r, v === true)" />
                    <div class="flex min-w-0 flex-1 items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-slate-900 dark:text-slate-100">{{ r.nome }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ r.unita }}<template v-if="r.quota"> {{ r.quota }}</template><template v-if="r.ruolo"> · {{ r.ruolo }}</template>
                            </p>
                        </div>
                        <span :class="classiBadge(r.colore)" class="shrink-0">{{ r.stato_parola }}</span>
                    </div>
                </div>
                <div v-if="gestisce && r.scelta.si_puo" class="mt-1.5" :class="conSpunte && spuntabile(r) ? 'pl-7' : ''" data-testid="mezzo-della-card">
                    <div class="menu-campo menu-campo-piccolo w-40 max-w-full text-xs">
                        <v-select :options="MEZZI" label="etichetta" :model-value="r.mezzo" :selectable="sceglibile(r)" :searchable="false" :clearable="false"
                            :reduce="(m: { valore: MezzoConvocazione }) => m.valore" placeholder="Nessun mezzo" :aria-label="`Mezzo per ${r.nome}`"
                            @update:model-value="(v: MezzoConvocazione | null) => emit('mezzo', r, v ?? null, false)" />
                    </div>
                    <label v-if="siRicorda(r)" class="mt-1 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                        <Checkbox :model-value="false" :aria-label="`Ricorda il mezzo nella scheda di ${r.nome}`" @update:model-value="(v: boolean | 'indeterminate') => v === true && emit('mezzo', r, r.mezzo, true)" />
                        Ricordalo nella sua scheda
                    </label>
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" :class="conSpunte && spuntabile(r) ? 'pl-7' : ''">
                    <template v-if="!(gestisce && r.scelta.si_puo)"><span :class="r.mezzo ? '' : 'text-rose-700 dark:text-rose-300'">{{ r.mezzo_parola }}</span>
                    <template v-if="r.pec_non_comunicata"> · </template></template>
                    <span v-if="r.pec_non_comunicata" class="text-amber-700 dark:text-amber-300">non comunicata</span>
                    <template v-if="r.dettaglio"><template v-if="!(gestisce && r.scelta.si_puo) || r.pec_non_comunicata"> · </template>{{ r.dettaglio }}</template>
                </p>
                <div v-if="r.prova || haMenu(r, gestisce)" class="mt-2 flex flex-wrap items-center gap-2" :class="conSpunte && spuntabile(r) ? 'pl-7' : ''">
                    <a v-if="r.prova" :href="r.prova.url" target="_blank" rel="noopener" class="text-xs text-slate-700 underline underline-offset-2 dark:text-slate-300">{{ r.prova.nome }}</a>
                    <button v-if="gestisce && r.azioni.spedizione" type="button" class="h-8 rounded-md border border-slate-200 px-2.5 text-xs dark:border-slate-700" @click="emit('azione', 'spedizione', r)">Registra la spedizione</button>
                    <button v-if="gestisce && r.azioni.ricevuta" type="button" class="h-8 rounded-md border border-slate-200 px-2.5 text-xs dark:border-slate-700" @click="emit('azione', 'ricevuta', r)">Carica la ricevuta PEC</button>
                    <button v-if="gestisce && r.azioni.consegna" type="button" class="h-8 rounded-md border border-slate-200 px-2.5 text-xs dark:border-slate-700" @click="emit('azione', 'consegna', r)">Registra la consegna</button>
                    <button v-if="gestisce && r.azioni.ritiro" type="button" class="h-8 rounded-md border border-slate-200 px-2.5 text-xs dark:border-slate-700" @click="emit('azione', 'ritiro', r)">Registra il ritiro</button>
                    <button v-if="gestisce && r.azioni.correggi" type="button" class="h-8 rounded-md border border-slate-200 px-2.5 text-xs dark:border-slate-700" @click="emit('azione', 'correggi', r)">Correggi la consegna</button>
                    <button v-if="gestisce && r.azioni.prova" type="button" class="h-8 rounded-md border border-slate-200 px-2.5 text-xs dark:border-slate-700" @click="emit('azione', 'prova', r)">Allega la prova</button>
                    <a v-if="r.pdf_url" :href="r.pdf_url" target="_blank" rel="noopener" class="text-xs text-slate-700 underline underline-offset-2 dark:text-slate-300">Apri l'avviso</a>
                </div>
            </li>
        </ul>
    </div>
</template>
