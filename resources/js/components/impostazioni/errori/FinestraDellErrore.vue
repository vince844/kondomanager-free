<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<!--
  Il dettaglio di un errore (1.12.0-beta.14, decisione 698 (2) e (3); bozza approvata docs/design/beta14_pagine_errore_grafica_bozza.html, scene 8 e 9), allo
  standard delle finestre (decisione 608): ancorata in alto, i gruppi con il titolo piccolo e grigio, il contenuto che scorre dentro, il riepilogo e i
  pulsanti fissi in fondo, il pulsante principale nero. Gli avvisi in cima (chi, il ruolo, il condominio, quando, la nota); che cosa è successo; dove; le
  ultime dieci volte, quella cercata per prima ed evidenziata; il programma; la traccia, chiusa. «Copia il rapporto» e «Scarica il rapporto»: il rapporto è
  senza password, dati dei moduli e valori delle query, ma i nomi scritti nei messaggi possono restare, e la riga in fondo lo dice (lo scrive il server,
  `RapportoDellErrore`, `Ripulitura`). Al telefono a tutto schermo.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { Check, ChevronDown, Copy, Download, MessageSquareText, Send, ShieldCheck } from 'lucide-vue-next';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { badgeDelloStato, testoVolte, type DettaglioErrore } from '@/lib/impostazioni/errori';

const props = defineProps<{ dettaglio: DettaglioErrore | null; open: boolean }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const tracciaAperta = ref(false);
const copiato = ref(false);
const d = computed(() => props.dettaglio);
const badge = computed(() => (d.value ? badgeDelloStato(d.value.stato) : null));
const dove = computed(() => {
    const v = d.value?.volta;
    if (!v) return null;

    return v.pagina ? `${v.metodo ?? ''} ${v.pagina}`.trim() : (v.azione ?? '—');
});

/** «Copia il rapporto»: gli appunti del browser, o il ripiego su un indirizzo http della rete dello studio. */
async function copiaIlRapporto() {
    const testo = d.value?.rapporto ?? '';
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(testo);
        } else {
            const area = document.createElement('textarea');
            area.value = testo;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            document.body.removeChild(area);
        }
        copiato.value = true;
        setTimeout(() => (copiato.value = false), 2000);
    } catch {
        copiato.value = false;
    }
}
</script>

<template>
    <Dialog :open="open && d !== null" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent v-if="d" data-testid="finestra-errore" :show-close-button="false"
            class="top-0 flex h-[100dvh] max-h-[100dvh] w-full max-w-full translate-y-0 flex-col gap-0 overflow-hidden rounded-none p-0 sm:top-[6vh] sm:h-auto sm:max-h-[88vh] sm:max-w-[740px] sm:rounded-xl">
            <div class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 pb-3 pt-4 dark:border-slate-800">
                <div class="min-w-0">
                    <DialogTitle class="flex flex-wrap items-center gap-2 text-[17px] font-semibold">
                        Errore {{ d.volta?.codice }}
                        <span v-if="badge" class="inline-flex items-center gap-1 rounded-md border px-1.5 py-px text-xs font-medium" :class="badge.classi">
                            <Send v-if="d.stato === 'avvisato'" class="h-3 w-3" />{{ badge.testo }}
                        </span>
                    </DialogTitle>
                    <DialogDescription class="mt-0.5 text-[13px] text-slate-500 dark:text-slate-400">
                        {{ d.classe.split('\\').pop() }} · {{ testoVolte(d.volte_in_tutto) }} dal {{ d.prima_volta }}, l'ultima il {{ d.ultima_volta }}
                    </DialogDescription>
                </div>
                <button type="button" class="inline-flex h-8 items-center rounded-md px-2 text-xs font-medium text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" @click="emit('update:open', false)">Chiudi</button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 pb-2">
                <section v-if="d.avvisi.length" data-gruppo="avvisi" class="border-b border-slate-100 py-3.5 dark:border-slate-800">
                    <h3 class="mb-2 text-xs font-semibold text-slate-500 dark:text-slate-400">Gli avvisi</h3>
                    <div class="space-y-2">
                        <div v-for="a in d.avvisi" :key="a.codice" class="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2.5 dark:border-amber-900 dark:bg-amber-950/30">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-amber-200 bg-white text-amber-700 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                <MessageSquareText class="h-3.5 w-3.5" />
                            </span>
                            <div class="min-w-0 text-[13px]">
                                <p class="font-semibold text-slate-900 dark:text-white">{{ a.nome }}
                                    <span class="font-normal text-slate-500 dark:text-slate-400">· {{ a.chi }}<template v-if="a.condominio">, {{ a.condominio }}</template> · {{ a.quando }} · {{ a.codice }}</span>
                                </p>
                                <p v-if="a.nota" class="mt-1 break-words text-slate-700 dark:text-slate-200">«{{ a.nota }}»</p>
                                <p v-else class="mt-1 text-slate-500 dark:text-slate-400">Senza una nota.</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section data-gruppo="che-cosa" class="border-b border-slate-100 py-3.5 dark:border-slate-800">
                    <h3 class="mb-2 text-xs font-semibold text-slate-500 dark:text-slate-400">Che cosa è successo</h3>
                    <p class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-[13px] leading-relaxed text-slate-900 [overflow-wrap:anywhere] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">{{ d.messaggio }}</p>
                    <p v-if="d.volta?.causa" class="mt-2 text-xs text-slate-500 [overflow-wrap:anywhere] dark:text-slate-400">Causa: {{ d.volta.causa }}</p>
                </section>

                <section v-if="d.volta" data-gruppo="dove" class="border-b border-slate-100 py-3.5 dark:border-slate-800">
                    <h3 class="mb-2 text-xs font-semibold text-slate-500 dark:text-slate-400">Dove</h3>
                    <dl class="grid grid-cols-1 gap-x-3.5 gap-y-1.5 text-[13px] sm:grid-cols-[8rem_minmax(0,1fr)]">
                        <dt class="text-slate-500 dark:text-slate-400">Pagina</dt><dd class="[overflow-wrap:anywhere]">{{ dove }}</dd>
                        <template v-if="d.volta.azione && d.volta.pagina"><dt class="text-slate-500 dark:text-slate-400">Azione</dt><dd>{{ d.volta.azione }}</dd></template>
                        <template v-if="d.volta.file"><dt class="text-slate-500 dark:text-slate-400">Nel programma</dt><dd class="[overflow-wrap:anywhere]">{{ d.volta.file }}<template v-if="d.volta.riga">, riga {{ d.volta.riga }}</template></dd></template>
                        <dt class="text-slate-500 dark:text-slate-400">Chi</dt><dd>{{ d.volta.chi }}</dd>
                    </dl>
                </section>

                <section data-gruppo="volte" class="border-b border-slate-100 py-3.5 dark:border-slate-800">
                    <h3 class="mb-2 text-xs font-semibold text-slate-500 dark:text-slate-400">Le volte</h3>
                    <div class="overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                        <div v-for="(v, i) in d.volte" :key="v.id" data-testid="volta"
                            class="grid grid-cols-[5.5rem_minmax(0,1fr)_auto] items-center gap-3 border-t border-slate-100 px-3 py-2 text-[13px] first:border-t-0 dark:border-slate-800 sm:grid-cols-[6rem_9rem_minmax(0,1fr)_auto]"
                            :class="i === 0 && v.codice === d.volta?.codice ? 'bg-amber-50 dark:bg-amber-950/30' : ''">
                            <span class="font-semibold tracking-wider">{{ v.codice }}</span>
                            <span class="tabular-nums text-slate-600 dark:text-slate-300">{{ v.quando }}</span>
                            <span class="hidden text-slate-600 dark:text-slate-300 sm:block">{{ v.chi }}</span>
                            <span v-if="v.avvisato" class="rounded-md border border-amber-200 bg-amber-50 px-1.5 py-px text-xs font-medium text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">Avvisato</span>
                            <span v-else></span>
                        </div>
                    </div>
                </section>

                <section v-if="d.volta" data-gruppo="programma" class="border-b border-slate-100 py-3.5 dark:border-slate-800">
                    <h3 class="mb-2 text-xs font-semibold text-slate-500 dark:text-slate-400">Il programma</h3>
                    <dl class="grid grid-cols-1 gap-x-3.5 gap-y-1.5 text-[13px] sm:grid-cols-[8rem_minmax(0,1fr)]">
                        <dt class="text-slate-500 dark:text-slate-400">Versione</dt><dd>{{ d.volta.programma }}</dd>
                        <dt class="text-slate-500 dark:text-slate-400">Impronta</dt><dd>{{ d.impronta }} <span class="text-slate-500 dark:text-slate-400">(la stessa per lo stesso errore, in ogni installazione con questa versione)</span></dd>
                    </dl>
                </section>

                <section v-if="d.traccia.length" data-gruppo="traccia" class="py-3.5">
                    <h3 class="mb-2 text-xs font-semibold text-slate-500 dark:text-slate-400">La traccia</h3>
                    <button type="button" class="inline-flex h-7 items-center gap-1.5 rounded-md border border-slate-200 bg-white px-2.5 text-xs font-medium shadow-sm dark:border-slate-700 dark:bg-slate-900"
                        :aria-expanded="tracciaAperta" @click="tracciaAperta = !tracciaAperta">
                        <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="tracciaAperta ? 'rotate-180' : ''" />
                        {{ tracciaAperta ? 'Nascondi la traccia' : `Mostra la traccia (${d.traccia.length} righe)` }}
                    </button>
                    <pre v-if="tracciaAperta" class="mt-2 whitespace-pre-wrap font-sans text-xs leading-relaxed text-slate-600 [overflow-wrap:anywhere] dark:text-slate-300">{{ d.traccia.join('\n') }}</pre>
                </section>
            </div>

            <div class="flex flex-col gap-2.5 border-t border-slate-200 bg-slate-50 px-5 py-3 dark:border-slate-800 dark:bg-slate-900 sm:flex-row sm:items-center sm:justify-between">
                <span class="flex min-w-0 items-start gap-2 text-[13px] text-slate-600 dark:text-slate-300 sm:flex-1">
                    <ShieldCheck class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600 dark:text-emerald-400" />Senza password, senza i dati dei moduli e i valori delle query; i nomi scritti nei messaggi possono restare: rileggilo prima di mandarlo.
                </span>
                <div class="grid grid-cols-2 gap-2 sm:flex sm:shrink-0">
                    <button type="button" class="hidden h-8 items-center whitespace-nowrap rounded-md border border-slate-200 bg-white px-3 text-[13px] font-medium shadow-sm dark:border-slate-700 dark:bg-slate-950 sm:inline-flex" @click="emit('update:open', false)">Chiudi</button>
                    <a v-if="d.rapporto_url" :href="d.rapporto_url" download data-testid="scarica-rapporto"
                        class="inline-flex h-8 items-center justify-center gap-1.5 whitespace-nowrap rounded-md border border-slate-200 bg-white px-3 text-[13px] font-medium shadow-sm dark:border-slate-700 dark:bg-slate-950">
                        <Download class="h-3.5 w-3.5" />Scarica il rapporto
                    </a>
                    <button type="button" data-testid="copia-rapporto"
                        class="inline-flex h-8 items-center justify-center gap-1.5 whitespace-nowrap rounded-md border px-3 text-[13px] font-semibold shadow-sm"
                        :class="copiato ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300' : 'border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900'"
                        @click="copiaIlRapporto">
                        <Check v-if="copiato" class="h-3.5 w-3.5" /><Copy v-else class="h-3.5 w-3.5" />{{ copiato ? 'Rapporto copiato' : 'Copia il rapporto' }}
                    </button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
