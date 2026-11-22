<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il corpo della pagina del registro delle modifiche (scena 2 della bozza, §14 del progetto della tappa 1), lo stesso per il condominio e per lo studio.
 *
 * - **Le tre card** (standard 4): il mese con quante modifiche e da quante persone; l'integrità con l'ultima verifica; la conservazione, che la pagina
 *   mette nello slot `conservazione`.
 * - **I filtri** «Chi», «Su che cosa», «Che cosa è successo», «Periodo» (e «Condominio» nello studio), tutti `v-select`, e «Cerca»: **senza ricaricare la
 *   pagina**, una visita parziale delle sole righe e del riepilogo (`only`, `preserveState`, `preserveScroll`; standard 16).
 * - Una riga senza la firma del programma ha il badge rosso «Aggiunta fuori dal programma» e la frase che lo dice.
 * - **La tabella a colonne fisse** «Quando | Chi | Su che cosa | Che cosa è cambiato | ·», con «Storia» (il cassetto della scheda) o «Apri»; la riga annullata
 *   da un ripristino con il badge «Annullata dal ripristino del …». Al telefono ogni riga va in colonna, senza scorrimento di lato.
 * - **Le pagine delle altre tabelle** (`PagineDelRegistro.vue`): il conto delle righe, «Righe per pagina» (20, 50, 100), «Pagina 2 di 7» e le quattro
 *   frecce. Una freccia o le righe per pagina chiedono le sole righe (una visita parziale, `only: ['righe']`); un filtro cambiato torna alla prima pagina e
 *   tiene le righe per pagina.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { CalendarClock, Clock, ShieldCheck } from 'lucide-vue-next';
import StoriaCassetto from '@/components/registroModifiche/StoriaCassetto.vue';
import PagineDelRegistro from '@/components/registroModifiche/PagineDelRegistro.vue';
import { testoModifiche, valore, type FiltriDelRegistro, type PaginaDelleRighe, type RigaDellaPagina } from '@/lib/registroModifiche/registro';
import { RIGHE_PER_PAGINA } from '@/lib/registroModifiche/stampa';

interface Scelta { valore: string; nome: string }
interface Riepilogo {
    mese: { nome: string; modifiche: number; frase: string };
    integrita: { stato: 'mai' | 'integro' | 'non_torna'; parola: string; frase: string };
}

const props = defineProps<{
    righe: PaginaDelleRighe;
    riepilogo: Riepilogo;
    scelte: { chi: Scelta[]; aree: Scelta[]; azioni: Scelta[]; mesi: Scelta[]; condomini: Scelta[] };
    filtri: FiltriDelRegistro;
    delloStudio: boolean;
}>();

const f = ref({
    chi: props.filtri.chi ?? '',
    area: props.filtri.area ?? '',
    azione: props.filtri.azione ?? '',
    mese: props.filtri.mese,
    cerca: props.filtri.cerca ?? '',
    condominio: props.filtri.condominio !== null ? String(props.filtri.condominio) : '',
});

/** I filtri nell'indirizzo: solo quelli scelti; le righe per pagina solo se non sono quelle di serie. */
const parametri = (perPagina: number = props.righe.per_page) => ({
    ...Object.fromEntries(Object.entries({ ...f.value }).filter(([, v]) => v !== '' && v !== null)),
    ...(perPagina && perPagina !== RIGHE_PER_PAGINA[0] ? { per_page: perPagina } : {}),
});

/** Un filtro cambiato: le righe, il riepilogo e i filtri, dalla prima pagina (senza `page`), con le stesse righe per pagina. */
function applica() {
    router.get(window.location.pathname, parametri(), {
        only: ['righe', 'riepilogo', 'filtri'], preserveState: true, preserveScroll: true, replace: true,
    });
}

/** Le pagine: le sole righe, con i filtri di adesso. Le righe per pagina cambiate tornano alla prima pagina. */
const opzioniDellaPagina = { only: ['righe'], preserveState: true, preserveScroll: true, replace: true };
const vaiAllaPagina = (pagina: number) => router.get(window.location.pathname, { ...parametri(), page: pagina }, opzioniDellaPagina);
const cambiaRighePerPagina = (righe: number) => router.get(window.location.pathname, parametri(righe), opzioniDellaPagina);

let attesa: ReturnType<typeof setTimeout> | null = null;
watch(() => [f.value.chi, f.value.area, f.value.azione, f.value.mese, f.value.condominio], () => applica());
watch(() => f.value.cerca, () => {
    if (attesa) clearTimeout(attesa);
    attesa = setTimeout(() => applica(), 350);
});

// Il cassetto della «Storia» della riga scelta: uno solo per la pagina.
const storia = ref<{ tipo: string; id: number | null } | null>(null);
const cassettoAperto = ref(false);
function apriStoria(r: RigaDellaPagina) {
    storia.value = r.storia;
    cassettoAperto.value = true;
}

/** Un solo dato cambiato: «Telefono: 333 → 347», con il valore di prima barrato come nella bozza. */
const unCampo = (r: RigaDellaPagina) => (r.azione === 'modificata' || r.azione === 'ripristinata') && r.campi.length === 1 ? r.campi[0] : null;

const coloreIntegrita = computed(() => ({
    integro: 'text-emerald-700 dark:text-emerald-400',
    non_torna: 'text-rose-700 dark:text-rose-400',
    mai: 'text-slate-500 dark:text-slate-400',
}[props.riepilogo.integrita.stato]));

const etichette = 'mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400';
const pulsante = 'inline-flex h-7 w-[84px] items-center justify-center rounded-md border border-slate-200 bg-white text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800';
</script>

<template>
    <div class="space-y-4">
        <!-- Le tre card -->
        <div class="grid grid-cols-1 gap-3.5 md:grid-cols-3" data-testid="card-registro">
            <div class="flex flex-col rounded-xl border border-slate-200 bg-white px-4 py-3.5 dark:border-slate-800 dark:bg-slate-950" data-testid="card-mese">
                <span class="flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400"><Clock class="h-3.5 w-3.5" /> {{ riepilogo.mese.nome }}</span>
                <span class="mt-1 text-xl font-bold tabular-nums text-slate-900 dark:text-white">{{ testoModifiche(riepilogo.mese.modifiche) }}</span>
                <span class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ riepilogo.mese.frase }}</span>
            </div>
            <div class="flex flex-col rounded-xl border border-slate-200 bg-white px-4 py-3.5 dark:border-slate-800 dark:bg-slate-950" data-testid="card-integrita">
                <span class="flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400"><ShieldCheck class="h-3.5 w-3.5" /> Integrità</span>
                <span class="mt-1 text-xl font-bold" :class="coloreIntegrita">{{ riepilogo.integrita.parola }}</span>
                <span class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ riepilogo.integrita.frase }}</span>
            </div>
            <div class="flex flex-col rounded-xl border border-slate-200 bg-white px-4 py-3.5 dark:border-slate-800 dark:bg-slate-950" data-testid="card-conservazione">
                <span class="flex items-center gap-1.5 text-xs font-semibold text-slate-500 dark:text-slate-400"><CalendarClock class="h-3.5 w-3.5" /> Conservazione</span>
                <!-- La conservazione: la durata delle righe e la frase «le righe del 2026 si tolgono da sole nel 2036». -->
                <slot name="conservazione">
                    <span class="mt-1 text-xl font-bold text-slate-900 dark:text-white">Tutte le righe</span>
                    <span class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">nessuna riga si toglie da sola</span>
                </slot>
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
            <!-- I filtri, senza ricaricare la pagina -->
            <div class="grid grid-cols-1 gap-2.5 border-b border-slate-100 px-4 py-3 sm:grid-cols-2 dark:border-slate-800"
                :class="delloStudio ? 'lg:grid-cols-[repeat(5,minmax(0,1fr))_minmax(0,1.4fr)]' : 'lg:grid-cols-[repeat(4,minmax(0,1fr))_minmax(0,1.4fr)]'" data-testid="filtri">
                <div class="menu-campo" data-testid="filtro-chi"><span :class="etichette">Chi</span>
                    <v-select v-model="f.chi" :options="scelte.chi" label="nome" :reduce="(s: Scelta) => s.valore" :clearable="false" :searchable="scelte.chi.length > 8" class="w-full" />
                </div>
                <div class="menu-campo" data-testid="filtro-area"><span :class="etichette">Su che cosa</span>
                    <v-select v-model="f.area" :options="scelte.aree" label="nome" :reduce="(s: Scelta) => s.valore" :clearable="false" :searchable="false" class="w-full" />
                </div>
                <div class="menu-campo" data-testid="filtro-azione"><span :class="etichette">Che cosa è successo</span>
                    <v-select v-model="f.azione" :options="scelte.azioni" label="nome" :reduce="(s: Scelta) => s.valore" :clearable="false" :searchable="false" class="w-full" />
                </div>
                <div class="menu-campo" data-testid="filtro-mese"><span :class="etichette">Periodo</span>
                    <v-select v-model="f.mese" :options="scelte.mesi" label="nome" :reduce="(s: Scelta) => s.valore" :clearable="false" :searchable="false" class="w-full" />
                </div>
                <div v-if="delloStudio" class="menu-campo" data-testid="filtro-condominio"><span :class="etichette">Condominio</span>
                    <v-select v-model="f.condominio" :options="scelte.condomini" label="nome" :reduce="(s: Scelta) => s.valore" :clearable="false" :searchable="scelte.condomini.length > 8" class="w-full" />
                </div>
                <label class="block" data-testid="filtro-cerca"><span :class="etichette">Cerca</span>
                    <input v-model="f.cerca" type="search" placeholder="Un nome, un dato, un valore"
                        class="h-8 w-full rounded-md border border-input bg-transparent px-2.5 text-sm shadow-xs placeholder:text-slate-400 dark:bg-slate-950" />
                </label>
            </div>

            <p v-if="righe.data.length === 0" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400" data-testid="nessuna-riga">
                Nessuna riga con questi filtri. Prova un altro periodo, o «Tutti i mesi».
            </p>

            <template v-else>
                <!-- La tabella a colonne fisse (dal tablet in su) -->
                <table class="hidden w-full table-fixed text-sm md:table" data-testid="tabella-registro">
                    <colgroup>
                        <col class="w-[118px]" /><col class="w-[150px]" /><col /><col class="w-[45%]" /><col class="w-[100px]" />
                    </colgroup>
                    <thead class="bg-slate-50 text-xs font-semibold text-slate-500 dark:bg-slate-900/60 dark:text-slate-400">
                        <tr>
                            <th class="whitespace-nowrap px-4 py-2 text-left">Quando</th>
                            <th class="whitespace-nowrap px-3 py-2 text-left">Chi</th>
                            <th class="whitespace-nowrap px-3 py-2 text-left">Su che cosa</th>
                            <th class="whitespace-nowrap px-3 py-2 text-left">Che cosa è cambiato</th>
                            <th class="px-3 py-2"><span class="sr-only">Azioni</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in righe.data" :key="r.id" class="border-t border-slate-100 align-top dark:border-slate-800" data-testid="riga-registro">
                            <td class="px-4 py-2.5 tabular-nums text-slate-700 dark:text-slate-300">
                                <span class="block">{{ r.avvenuta_il.slice(0, 10) }}</span><span class="block text-xs text-slate-500 dark:text-slate-400">{{ r.avvenuta_il.slice(11) }}</span>
                            </td>
                            <td class="px-3 py-2.5">
                                <span v-if="r.autore.tipo === 'programma'" class="inline-flex items-center rounded-md border border-slate-200 bg-slate-50 px-2 py-px text-xs font-medium text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">Il programma</span>
                                <template v-else>
                                    <span class="block break-words text-slate-900 dark:text-slate-100">{{ r.autore.nome }}</span>
                                    <span v-if="r.autore.ruolo" class="mt-0.5 inline-flex items-center rounded-md border border-slate-200 bg-slate-50 px-2 py-px text-xs font-medium text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300" data-testid="ruolo">{{ r.autore.ruolo }}</span>
                                </template>
                            </td>
                            <td class="px-3 py-2.5">
                                <span class="block break-words text-slate-900 dark:text-slate-100">{{ r.oggetto.nome }}</span>
                                <span v-if="delloStudio && r.condomini.length" class="block text-xs text-slate-500 dark:text-slate-400">{{ r.condomini.join(', ') }}</span>
                            </td>
                            <td class="px-3 py-2.5 text-slate-800 dark:text-slate-200">
                                <!-- Un dato solo: il nome sopra, piccolo; sotto il valore di prima barrato e «→ valore nuovo», che va a capo intero quando non ci sta
                                     (prima il valore nuovo andava a capo da solo, staccato dalla freccia: prova a video, 19/11). -->
                                <template v-if="unCampo(r)">
                                    <span class="block text-xs text-slate-500 dark:text-slate-400" data-testid="etichetta-dato">{{ unCampo(r)!.etichetta }}</span>
                                    <span class="flex flex-wrap items-baseline gap-x-1.5" data-testid="valore-dato">
                                        <span class="min-w-0 break-words text-slate-500 line-through decoration-slate-400 dark:text-slate-400">{{ valore(unCampo(r)!.prima) }}</span>
                                        <span class="min-w-0 break-words">→&nbsp;{{ valore(unCampo(r)!.dopo) }}</span>
                                    </span>
                                </template>
                                <span v-else class="break-words">{{ r.cambiato }}</span>
                                <span v-if="r.perche" class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">Perché: {{ r.perche }}</span>
                                <span v-if="r.nota" class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{{ r.nota }}</span>
                                <!-- I segni della riga su una riga loro, sotto il valore: attaccati al valore si leggevano come parte del dato (prova a video, 19/11). -->
                                <div v-if="r.annullata_dal_ripristino_del || r.fuori_dal_programma" class="mt-1.5 space-y-1" data-testid="segni-della-riga">
                                    <div v-if="r.annullata_dal_ripristino_del">
                                        <span class="inline-flex items-center rounded-md border border-orange-200 bg-orange-50 px-2 py-px text-xs font-medium text-orange-800 dark:border-orange-800 dark:bg-orange-950/40 dark:text-orange-300" data-testid="annullata">Annullata dal ripristino del {{ r.annullata_dal_ripristino_del }}</span>
                                    </div>
                                    <template v-if="r.fuori_dal_programma">
                                        <div>
                                            <span class="inline-flex items-center rounded-md border border-rose-200 bg-rose-50 px-2 py-px text-xs font-medium text-rose-800 dark:border-rose-900 dark:bg-rose-950/20 dark:text-rose-300" data-testid="fuori-dal-programma">Aggiunta fuori dal programma</span>
                                        </div>
                                        <p class="text-xs text-rose-800 dark:text-rose-300" data-testid="fuori-dal-programma-frase">Questa riga non ha la firma del programma: è stata aggiunta o cambiata fuori dal programma, per esempio direttamente nel database.</p>
                                    </template>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <a v-if="r.apri" :href="r.apri" :class="pulsante" data-testid="apri-riga">Apri</a>
                                <button v-else-if="r.storia" type="button" :class="pulsante" data-testid="storia-riga" @click="apriStoria(r)">Storia</button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Al telefono: una riga sotto l'altra, senza scorrimento di lato -->
                <ul class="divide-y divide-slate-100 md:hidden dark:divide-slate-800" data-testid="elenco-registro">
                    <li v-for="r in righe.data" :key="`t-${r.id}`" class="space-y-1 px-4 py-3 text-sm">
                        <div class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500 dark:text-slate-400">
                            <span class="tabular-nums">{{ r.avvenuta_il }}</span><span>·</span><span>{{ r.autore.tipo === 'programma' ? 'Il programma' : r.autore.nome }}</span>
                        </div>
                        <p class="break-words font-medium text-slate-900 dark:text-slate-100">{{ r.oggetto.nome }}</p>
                        <div v-if="unCampo(r)" class="text-slate-700 dark:text-slate-300">
                            <span class="block text-xs text-slate-500 dark:text-slate-400" data-testid="etichetta-dato">{{ unCampo(r)!.etichetta }}</span>
                            <span class="flex flex-wrap items-baseline gap-x-1.5" data-testid="valore-dato">
                                <span class="min-w-0 break-words text-slate-500 line-through decoration-slate-400 dark:text-slate-400">{{ valore(unCampo(r)!.prima) }}</span>
                                <span class="min-w-0 break-words">→&nbsp;{{ valore(unCampo(r)!.dopo) }}</span>
                            </span>
                        </div>
                        <p v-else class="break-words text-slate-700 dark:text-slate-300">{{ r.cambiato }}</p>
                        <p v-if="r.perche" class="text-xs text-slate-500 dark:text-slate-400">Perché: {{ r.perche }}</p>
                        <span v-if="r.annullata_dal_ripristino_del" class="inline-flex items-center rounded-md border border-orange-200 bg-orange-50 px-2 py-px text-xs font-medium text-orange-800 dark:border-orange-800 dark:bg-orange-950/40 dark:text-orange-300">Annullata dal ripristino del {{ r.annullata_dal_ripristino_del }}</span>
                        <template v-if="r.fuori_dal_programma">
                            <span class="inline-flex items-center rounded-md border border-rose-200 bg-rose-50 px-2 py-px text-xs font-medium text-rose-800 dark:border-rose-900 dark:bg-rose-950/20 dark:text-rose-300" data-testid="fuori-dal-programma">Aggiunta fuori dal programma</span>
                            <p class="text-xs text-rose-800 dark:text-rose-300">Questa riga non ha la firma del programma: è stata aggiunta o cambiata fuori dal programma, per esempio direttamente nel database.</p>
                        </template>
                        <div class="pt-1">
                            <a v-if="r.apri" :href="r.apri" :class="pulsante">Apri</a>
                            <button v-else-if="r.storia" type="button" :class="pulsante" @click="apriStoria(r)">Storia</button>
                        </div>
                    </li>
                </ul>

                <!-- Le pagine, come nelle altre tabelle -->
                <PagineDelRegistro :pagina="righe.current_page" :pagine="righe.last_page" :per-pagina="righe.per_page" :totale="righe.total"
                    @pagina="vaiAllaPagina" @per-pagina="cambiaRighePerPagina" />
            </template>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            «Stampa» apre la finestra per scegliere il periodo dal … al … e i filtri, e dice quante righe usciranno prima del PDF, con l'impronta: per
            un'impugnazione o per il passaggio di consegne. I filtri e le pagine non ricaricano la pagina.
        </p>

        <StoriaCassetto v-if="storia" v-model:open="cassettoAperto" :tipo="storia.tipo" :id="storia.id" />
    </div>
</template>
