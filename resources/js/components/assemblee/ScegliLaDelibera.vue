<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Scegli la delibera» (§3 P4; la bozza approvata con la decisione 502, scene 1, 2, 3 e 5): un componente solo, uguale nelle dodici pagine dove oggi la
 * delibera si scrive a mano e nella ratifica.
 *
 * - **Propone, non sceglie** (decisione 500): in testa «Proposte per questo piano» con «Proposta perché…» e, dove la delibera muove chi paga, la conseguenza;
 *   sotto «Tutte le delibere di Via Roma» per assemblea, dalla più recente, con la ricerca; le respinte spente («non si sceglie»), il badge «A mano».
 * - **Niente si collega senza il clic** (R3): scegliere cambia solo il campo; il collegamento lo fa la pagina quando salva.
 * - In fondo «Registra una delibera a mano» (la finestra, che dopo il clic su «Registra la delibera» la sceglie) e «Da portare in assemblea» (il memo).
 * - Vuoto (scena 5): la frase e le due strade; senza «Vede le assemblee» il campo lo dice e resta la nota.
 * - Al telefono il menu si apre in un cassetto dal basso con «Chiudi». Il menu non scorre dentro di sé: le assemblee in più arrivano con «Mostra le altre».
 *
 * Le delibere arrivano da un JSON (`axios`, standard 16: lo stesso componente in tredici posti); la scheda passa come tipo e id, e il server la cerca nel
 * condominio (R18).
 */
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, Search } from 'lucide-vue-next';
import SuggerimentoDellaI from '@/components/assemblee/convocazione/SuggerimentoDellaI.vue';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import RegistraDeliberaAMano from '@/components/assemblee/RegistraDeliberaAMano.vue';
import FinestraDelMemo from '@/components/assemblee/FinestraDelMemo.vue';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { BADGE, CLASSI_DEL_BADGE, coloreDellEsito, giornoItaliano, messaggioDellErrore } from '@/lib/assemblee/assemblee';
import type { DeliberaDelMenu, GruppoDiDelibere, PropostaDiScegliLaDelibera, RuoloCollegamento } from '@/types/assemblee';
import { fraseDellaDelega } from '@/lib/assemblee/delega';

const props = withDefaults(defineProps<{
    modelValue: DeliberaDelMenu | null;
    condominioId: number;
    condominioNome: string;
    ruolo: RuoloCollegamento;
    oggettoTipo?: string | null;
    oggettoId?: number | null;
    /** «questo piano», «questa fattura»: «Proposte per questo piano». */
    perQuesto?: string;
    /** La frase del menu vuoto: «Nessuna assemblea di Via Roma 12 ha deliberato sulla gestione «Facciata 2026»». */
    vuoto?: string | null;
    vuotoSotto?: string | null;
    etichetta?: string;
    info?: string | null;
    /** Ciò di cui parla la scheda, per la delibera registrata a mano: `[[tipo, id], …]`. */
    parlaDi?: [string, number][];
    /** Il testo proposto per la delibera a mano e per il memo. */
    testoProposto?: string | null;
    conMemo?: boolean;
    disabilitato?: boolean;
}>(), {
    oggettoTipo: null, oggettoId: null, perQuesto: 'questa scheda', vuoto: null, vuotoSotto: null, etichetta: 'La delibera', info: null,
    parlaDi: () => [], testoProposto: null, conMemo: true, disabilitato: false,
});
const emit = defineEmits<{
    (e: 'update:modelValue', v: DeliberaDelMenu | null): void;
    (e: 'registrata', v: DeliberaDelMenu): void;
    (e: 'memo', frase: string): void;
}>();

const { generateRoute, hasPermission } = usePermission();
const vede = computed(() => hasPermission([Permission.VIEW_ASSEMBLEE, Permission.MANAGE_ASSEMBLEE]));
const gestisce = computed(() => hasPermission([Permission.MANAGE_ASSEMBLEE]));

const aperto = ref(false);
const alTelefono = ref(false);
const cerca = ref('');
const dati = ref<PropostaDiScegliLaDelibera | null>(null);
const caricamento = ref(false);
const errore = ref<string | null>(null);
const gruppiMostrati = ref(3);
const finestraAMano = ref(false);
const finestraMemo = ref(false);
let lettura = 0;

/** Le assemblee per id, per scrivere il verbale accanto a una proposta. */
const assembleePerId = computed(() => new Map((dati.value?.gruppi ?? []).map((g) => [g.assemblea.id, g.assemblea])));
const gruppi = computed<GruppoDiDelibere[]>(() => dati.value?.gruppi ?? []);
const proposte = computed<DeliberaDelMenu[]>(() => dati.value?.proposte ?? []);
const titoloDelleAltre = computed(() => (proposte.value.length > 0 || !props.oggettoTipo ? 'Tutte le delibere di ' : 'Altre delibere di ') + props.condominioNome);
const fraseVuota = computed(() => props.vuoto ?? `Nessuna assemblea di ${props.condominioNome} ha ancora parlato di ${props.perQuesto}.`);
const fraseVuotaSotto = computed(() => props.vuotoSotto
    ?? 'Se l\'assemblea deve ancora decidere, portala alla prossima: il punto ti viene proposto quando la prepari.');

async function leggi() {
    const numero = ++lettura;
    caricamento.value = true;
    errore.value = null;
    try {
        const r = await axios.get(route(generateRoute('gestionale.delibere.scegli'), { condominio: props.condominioId }), {
            params: { oggetto_tipo: props.oggettoTipo ?? undefined, oggetto_id: props.oggettoId ?? undefined, ruolo: props.ruolo, cerca: cerca.value || undefined },
        });
        if (numero === lettura) {
            dati.value = r.data as PropostaDiScegliLaDelibera;
        }
    } catch (e) {
        if (numero === lettura) {
            errore.value = messaggioDellErrore(e, 'Le delibere non si sono caricate. Riprova.');
        }
    } finally {
        if (numero === lettura) {
            caricamento.value = false;
        }
    }
}

let attesa: ReturnType<typeof setTimeout> | null = null;
watch(cerca, () => {
    if (attesa) clearTimeout(attesa);
    attesa = setTimeout(() => leggi(), 300);
});

function apri() {
    if (props.disabilitato) return;
    alTelefono.value = typeof window !== 'undefined' && typeof window.matchMedia === 'function' && window.matchMedia('(max-width: 639px)').matches;
    aperto.value = !aperto.value;
    if (aperto.value) {
        gruppiMostrati.value = 3;
        leggi();
    }
}

function scegli(d: DeliberaDelMenu) {
    if (!d.si_sceglie) return;
    emit('update:modelValue', d);
    aperto.value = false;
}

function registrata(d: DeliberaDelMenu) {
    emit('registrata', d);
    if (d.si_sceglie) {
        emit('update:modelValue', d);
    }
    aperto.value = false;
    leggi();
}

function memoSalvato(frase: string) {
    emit('memo', frase);
    aperto.value = false;
    leggi();
}

/** «Approvata 22/10/2026 · punto 1 · verbale n. 41». */
function riga(d: DeliberaDelMenu, conGiorno = true): string {
    const verbale = assembleePerId.value.get(d.assemblea_id)?.numero_verbale;
    return [conGiorno ? `${d.esito_parola} ${giornoItaliano(d.deliberata_il)}` : d.esito_parola, d.punto ? `punto ${d.punto.numero}` : null,
        conGiorno && verbale ? `verbale n. ${verbale}` : null].filter(Boolean).join(' · ');
}

/** Il nome del gruppo: «Assemblea ordinaria del 14/05/2026 · verbale n. 40», «Riunione del 12/03/2024 · registrata a mano · verbale n. 3/2024». */
function nomeDelGruppo(g: GruppoDiDelibere): string {
    const a = g.assemblea;
    const nome = a.a_mano ? a.nome : `Assemblea ${a.nome.charAt(0).toLowerCase()}${a.nome.slice(1)}`;
    return [nome, a.a_mano ? 'registrata a mano' : null, a.numero_verbale ? `verbale n. ${a.numero_verbale}` : null,
        a.verbale_da_caricare ? 'verbale da caricare' : null].filter(Boolean).join(' · ');
}

const indirizzoDellAssemblea = (d: DeliberaDelMenu) => route(generateRoute('gestionale.assemblee.show'), {
    condominio: props.condominioId, assemblea: d.assemblea_id, punto: d.punto?.id ?? undefined,
});
const indirizzoDellaRaccolta = computed(() => route(generateRoute('gestionale.delibere.raccolta'), { condominio: props.condominioId }));

onMounted(() => {
    // La memoria e la raccolta si leggono anche a menu chiuso: «Già nella memoria dal 18/11» sta sotto il campo.
    if (vede.value && props.oggettoTipo && props.oggettoId) {
        leggi();
    }
});

const campo = 'flex h-9 w-full items-center justify-between rounded-md border border-input bg-transparent px-3 text-left text-sm shadow-xs dark:bg-slate-950';
const pulsante = 'inline-flex h-8 items-center justify-center rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
</script>

<template>
    <div class="space-y-1.5" data-testid="scegli-la-delibera">
        <span class="flex items-center gap-1 text-xs font-medium text-slate-600 dark:text-slate-400">
            {{ etichetta }}
            <SuggerimentoDellaI v-if="info" :testo="info" testid="i-delibera" />
        </span>

        <!-- Senza «Vede le assemblee»: il campo lo dice, e resta la nota della pagina. -->
        <p v-if="!vede" class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
            data-testid="senza-permesso">
            Per scegliere la delibera serve il permesso «Vede le assemblee»: intanto scrivi il riferimento nella nota.
        </p>

        <template v-else>
            <!-- La delibera scelta (scena 3): il testo, la riunione, «Apri l'assemblea» e «Cambia». -->
            <div v-if="modelValue" class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900" data-testid="delibera-scelta">
                <p class="break-words text-sm font-medium text-slate-900 dark:text-slate-100">{{ modelValue.testo }}</p>
                <p class="mt-0.5 break-words text-xs text-slate-500 dark:text-slate-400">{{ modelValue.frase }} · {{ modelValue.esito_parola.toLowerCase() }}</p>
                <p v-if="modelValue.revocata" class="mt-1 text-xs text-rose-700 dark:text-rose-300">{{ modelValue.revocata }}</p>
                <p v-if="modelValue.impugnata" class="mt-1 text-xs text-amber-800 dark:text-amber-300">{{ modelValue.impugnata }}: non ferma niente da sola.</p>
                <p v-if="fraseDellaDelega(modelValue)" class="mt-1 text-xs text-slate-600 dark:text-slate-300" data-testid="delega-scelta">{{ fraseDellaDelega(modelValue) }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    <Link :href="indirizzoDellAssemblea(modelValue)" :class="pulsante" data-testid="apri-assemblea">Apri l'assemblea</Link>
                    <button v-if="!disabilitato" type="button" :class="pulsante" data-testid="cambia" @click="apri">Cambia</button>
                </div>
            </div>
            <button v-else type="button" :class="campo" :disabled="disabilitato" data-testid="apri-menu" @click="apri">
                <span class="text-slate-500 dark:text-slate-400">Scegli la delibera…</span>
                <ChevronUp v-if="aperto" class="h-4 w-4 text-slate-400 dark:text-slate-500" /><ChevronDown v-else class="h-4 w-4 text-slate-400 dark:text-slate-500" />
            </button>

            <p v-if="dati?.memo && !modelValue" class="text-xs text-slate-500 dark:text-slate-400" data-testid="gia-nella-memoria">{{ dati.memo.frase }}.</p>
            <p v-if="!aperto && !modelValue && dati && proposte.length === 0 && oggettoTipo" class="text-xs text-slate-500 dark:text-slate-400" data-testid="nessuna-ne-ha-parlato">
                {{ fraseVuota }}
            </p>

            <!-- Il menu: nel riquadro sotto il campo; al telefono un cassetto dal basso. -->
            <template v-if="aperto && !alTelefono">
                <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-sm dark:border-slate-700 dark:bg-slate-950" data-testid="menu-delibere">
                        <div class="space-y-3">
                            <label class="relative block">
                                <Search class="pointer-events-none absolute left-2.5 top-2 h-4 w-4 text-slate-400 dark:text-slate-500" />
                                <input v-model="cerca" type="search" placeholder="Cerca per parola, data o numero del verbale" data-testid="cerca"
                                    class="h-8 w-full rounded-md border border-input bg-transparent pl-8 pr-2 text-sm placeholder:text-slate-400 dark:bg-slate-950" />
                            </label>
                            <p v-if="caricamento && !dati" class="text-xs text-slate-500">Un momento…</p>
                            <p v-if="errore" class="text-xs text-rose-700 dark:text-rose-400" data-testid="errore">{{ errore }}</p>

                            <template v-if="dati">
                                <div v-if="proposte.length" class="space-y-1.5" data-testid="proposte">
                                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Proposte per {{ perQuesto }}</p>
                                    <div v-for="d in proposte" :key="`p-${d.id}`" data-testid="proposta"
                                        class="flex items-start gap-2 rounded-md border border-sky-200 bg-sky-50/60 px-3 py-2 dark:border-sky-900 dark:bg-sky-950/30">
                                        <button type="button" class="min-w-0 flex-1 text-left" data-testid="scegli-proposta" @click="scegli(d)">
                                            <span class="block break-words text-sm font-medium text-slate-900 dark:text-slate-100">{{ d.testo }}</span>
                                            <span class="block text-xs text-slate-600 dark:text-slate-300">{{ riga(d) }}</span>
                                            <span v-if="d.perche_proposta" class="block text-xs text-sky-800 dark:text-sky-300">{{ d.perche_proposta }}.</span>
                                            <span v-if="d.conseguenza" class="block text-xs text-slate-600 dark:text-slate-300">{{ d.conseguenza }}.</span>
                                            <span v-if="d.impugnata" class="block text-xs text-amber-800 dark:text-amber-300">{{ d.impugnata }}</span>
                                            <span v-if="fraseDellaDelega(d)" class="block text-xs text-slate-600 dark:text-slate-300" data-testid="delega-proposta">{{ fraseDellaDelega(d) }}</span>
                                        </button>
                                        <Link :href="indirizzoDellAssemblea(d)" class="shrink-0 text-xs font-medium text-sky-700 underline dark:text-sky-300">Apri</Link>
                                    </div>
                                </div>

                                <div v-else-if="oggettoTipo" class="rounded-md border border-dashed border-slate-300 px-3 py-3 dark:border-slate-700" data-testid="vuoto">
                                    <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ fraseVuota }}</p>
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ fraseVuotaSotto }}</p>
                                    <div v-if="gestisce" class="mt-2 flex flex-wrap gap-2">
                                        <button v-if="conMemo" type="button" :class="pulsante" data-testid="vuoto-memo" @click="finestraMemo = true">Da portare in assemblea</button>
                                        <button type="button" :class="pulsante" data-testid="vuoto-a-mano" @click="finestraAMano = true">Registra una delibera a mano</button>
                                    </div>
                                </div>

                                <div v-if="gruppi.length" class="space-y-2.5" data-testid="tutte">
                                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ titoloDelleAltre }}</p>
                                    <div v-for="g in gruppi.slice(0, gruppiMostrati)" :key="`g-${g.assemblea.id}`" class="space-y-1" data-testid="gruppo">
                                        <p class="text-xs font-medium text-slate-700 dark:text-slate-300">{{ nomeDelGruppo(g) }}</p>
                                        <div v-for="d in g.delibere" :key="`d-${d.id}`" class="flex items-start gap-2 rounded-md px-3 py-1.5" data-testid="delibera"
                                            :class="d.si_sceglie ? 'hover:bg-slate-50 dark:hover:bg-slate-900' : 'opacity-60'">
                                            <button type="button" class="min-w-0 flex-1 text-left" :class="{ 'cursor-not-allowed': !d.si_sceglie }" :disabled="!d.si_sceglie"
                                                data-testid="scegli-delibera" @click="scegli(d)">
                                                <span class="block break-words text-sm text-slate-900 dark:text-slate-100">{{ d.testo }}</span>
                                                <span class="flex flex-wrap items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                                                    <span :class="[BADGE, CLASSI_DEL_BADGE[coloreDellEsito(d.esito)]]">{{ d.esito_parola }}</span>
                                                    <span v-if="g.assemblea.a_mano" :class="[BADGE, CLASSI_DEL_BADGE.neutro]">A mano</span>
                                                    <span v-if="d.punto">punto {{ d.punto.numero }}</span>
                                                    <span v-if="!d.si_sceglie" data-testid="non-si-sceglie">· non si sceglie{{ d.perche ? `: ${d.perche}` : '' }}</span>
                                                </span>
                                                <span v-if="fraseDellaDelega(d)" class="block text-xs text-slate-600 dark:text-slate-300" data-testid="delega-nel-menu">{{ fraseDellaDelega(d) }}</span>
                                            </button>
                                            <Link :href="indirizzoDellAssemblea(d)" class="shrink-0 text-xs font-medium text-sky-700 underline dark:text-sky-300">Apri</Link>
                                        </div>
                                    </div>
                                    <button v-if="gruppi.length > gruppiMostrati" type="button" class="text-xs font-medium text-slate-600 underline dark:text-slate-300"
                                        data-testid="mostra-altre" @click="gruppiMostrati += 5">
                                        Mostra le altre {{ gruppi.length - gruppiMostrati }} riunioni
                                    </button>
                                </div>
                                <p v-else-if="cerca" class="text-xs text-slate-500">Nessuna delibera con «{{ cerca }}».</p>

                                <p v-if="dati.raccolta" class="text-xs text-slate-600 dark:text-slate-300" data-testid="raccolta">
                                    {{ dati.raccolta.frase.replace(/: raccoglile$/, '') }}: <Link :href="indirizzoDellaRaccolta" class="font-medium underline">raccoglile</Link>
                                </p>

                                <div v-if="gestisce && (proposte.length || !oggettoTipo)" class="flex flex-wrap gap-2 border-t border-slate-100 pt-2.5 dark:border-slate-800">
                                    <button type="button" :class="pulsante" data-testid="a-mano" @click="finestraAMano = true">Registra una delibera a mano</button>
                                    <button v-if="conMemo" type="button" :class="pulsante" data-testid="memo" @click="finestraMemo = true">Da portare in assemblea</button>
                                </div>
                                <div v-else-if="gestisce && gruppi.length" class="flex flex-wrap gap-2 border-t border-slate-100 pt-2.5 dark:border-slate-800">
                                    <button type="button" :class="pulsante" @click="finestraAMano = true">Registra una delibera a mano</button>
                                </div>
                            </template>
                        </div>
                </div>
            </template>

            <Sheet v-if="alTelefono" :open="aperto" @update:open="aperto = $event">
                <SheetContent side="bottom" class="max-h-[90vh] overflow-y-auto" data-testid="cassetto-delibere">
                    <SheetHeader>
                        <SheetTitle>Scegli la delibera</SheetTitle>
                        <SheetDescription class="sr-only">Le delibere di {{ condominioNome }}</SheetDescription>
                    </SheetHeader>
                    <div class="space-y-3 px-4 pb-4">
                        <input v-model="cerca" type="search" placeholder="Cerca per parola, data o verbale"
                            class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm placeholder:text-slate-400 dark:bg-slate-950" />
                        <p v-if="errore" class="text-xs text-rose-700 dark:text-rose-400">{{ errore }}</p>
                        <template v-if="dati">
                            <div v-if="proposte.length" class="space-y-1.5">
                                <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Proposte per {{ perQuesto }}</p>
                                <button v-for="d in proposte" :key="`tp-${d.id}`" type="button" class="block w-full rounded-md border border-sky-200 bg-sky-50/60 px-3 py-2 text-left dark:border-sky-900 dark:bg-sky-950/30" @click="scegli(d)">
                                    <span class="block break-words text-sm font-medium text-slate-900 dark:text-slate-100">{{ d.testo }}</span>
                                    <span class="block text-xs text-slate-600 dark:text-slate-300">{{ riga(d) }}</span>
                                    <span v-if="d.perche_proposta" class="block text-xs text-sky-800 dark:text-sky-300">{{ d.perche_proposta }}.</span>
                                </button>
                            </div>
                            <div v-else-if="oggettoTipo" class="rounded-md border border-dashed border-slate-300 px-3 py-3 dark:border-slate-700">
                                <p class="text-sm font-medium text-slate-800 dark:text-slate-200">{{ fraseVuota }}</p>
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ fraseVuotaSotto }}</p>
                            </div>
                            <div v-for="g in gruppi.slice(0, gruppiMostrati)" :key="`tg-${g.assemblea.id}`" class="space-y-1">
                                <p class="text-xs font-medium text-slate-700 dark:text-slate-300">{{ nomeDelGruppo(g) }}</p>
                                <button v-for="d in g.delibere" :key="`td-${d.id}`" type="button" :disabled="!d.si_sceglie" class="block w-full rounded-md px-2 py-1.5 text-left"
                                    :class="d.si_sceglie ? '' : 'opacity-60'" @click="scegli(d)">
                                    <span class="block break-words text-sm text-slate-900 dark:text-slate-100">{{ d.testo }}</span>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ riga(d, false) }}{{ d.si_sceglie ? '' : ' · non si sceglie' }}</span>
                                </button>
                            </div>
                            <button v-if="gruppi.length > gruppiMostrati" type="button" class="text-xs font-medium underline" @click="gruppiMostrati += 5">
                                Mostra le altre {{ gruppi.length - gruppiMostrati }} riunioni
                            </button>
                            <div v-if="gestisce" class="grid gap-2 pt-1">
                                <button type="button" :class="[pulsante, 'h-9 w-full']" @click="finestraAMano = true">Registra una delibera a mano</button>
                                <button v-if="conMemo" type="button" :class="[pulsante, 'h-9 w-full']" @click="finestraMemo = true">Da portare in assemblea</button>
                            </div>
                        </template>
                    </div>
                </SheetContent>
            </Sheet>
        </template>

        <RegistraDeliberaAMano v-if="gestisce" v-model:open="finestraAMano" :condominio-id="condominioId" :ruolo="ruolo" :oggetto-tipo="oggettoTipo"
            :oggetto-id="oggettoId" :parla-di="parlaDi" :testo="testoProposto" @registrata="registrata" />
        <FinestraDelMemo v-if="gestisce && conMemo" v-model:open="finestraMemo" :condominio-id="condominioId" :origine-tipo="oggettoTipo" :origine-id="oggettoId"
            @salvato="memoSalvato" />
    </div>
</template>
