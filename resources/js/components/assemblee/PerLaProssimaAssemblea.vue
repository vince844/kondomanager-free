<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La card «Per la prossima assemblea» della dashboard del condominio (§3 P9; scena 3 della bozza approvata con la decisione 501, e la scena 1b della bozza
 * delle scene della tappa 2): i punti che aspettano un'assemblea, con il badge dell'origine («Da te», «Dal programma», «Dalla nota sintetica») e la riga
 * sotto; oltre gli otto «e altre N»; «Prepara l'assemblea», o l'assemblea già in preparazione. Senza punti la card non c'è (lo decide la pagina).
 *
 * Con la decisione 574 ogni punto ha il suo menu ⋯, così si agisce sul punto anche senza «Ricordamelo» (senza il compito nell'Action inbox): «Apri …» la
 * scheda da cui nasce, dove una pagina c'è; su un memo, a chi gestisce le assemblee, «Modifica» (la finestra del memo) e «Non serve più» (con il motivo).
 * Un punto senza nessuna voce non ha il menu. Accanto a «Prepara l'assemblea», «Aggiungi un punto» apre la stessa finestra del memo. Le finestre stanno
 * fuori dai menu, che non sono modali; dopo un salvataggio la card si rilegge (una visita parziale).
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { ExternalLink, Gavel, MoreHorizontal, Pencil, Plus, XCircle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import FinestraDelMemo from '@/components/assemblee/FinestraDelMemo.vue';
import { useToast } from '@/components/ui/toast';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';
import { BADGE, CLASSI_DEL_BADGE, type ColoreDelBadge } from '@/lib/assemblee/assemblee';
import type { MemoDellaFinestra, PuntoDellaCard } from '@/lib/assemblee/pagine';

const props = defineProps<{
    condominioId: number;
    condominioNome: string;
    /** `con_la_nota`: c'è una nota sintetica di un esercizio con il rendiconto da approvare, che in «Prepara l'assemblea» può aggiungere altri punti. */
    dati: { punti: PuntoDellaCard[]; totale: number; altre: number; in_preparazione: { id: number; nome: string } | null; con_la_nota?: boolean };
}>();

const { generateRoute, hasPermission } = usePermission();
const { toast } = useToast();
const gestisce = computed(() => hasPermission([Permission.MANAGE_ASSEMBLEE]));
const colore: Record<PuntoDellaCard['gruppo'], ColoreDelBadge> = { di_serie: 'neutro', da_te: 'viola', dal_programma: 'blu', dalla_nota: 'ambra' };
const prepara = computed(() => route(generateRoute('gestionale.assemblee.create'), { condominio: props.condominioId }));
const archivio = computed(() => route(generateRoute('gestionale.assemblee.index'), { condominio: props.condominioId }));
const inPreparazione = computed(() => props.dati.in_preparazione
    ? route(generateRoute('gestionale.assemblee.show'), { condominio: props.condominioId, assemblea: props.dati.in_preparazione.id })
    : null);

/** Il punto ha un menu se c'è almeno una voce: «Apri …», o il memo per chi gestisce. */
const haIlMenu = (p: PuntoDellaCard) => !!p.apri || (gestisce.value && !!p.memo);

// La finestra del memo: nuovo con «Aggiungi un punto», in modifica con «Modifica».
const finestra = ref(false);
const memoInModifica = ref<MemoDellaFinestra | null>(null);
function aggiungi() {
    memoInModifica.value = null;
    finestra.value = true;
}
function modifica(memo: MemoDellaFinestra) {
    memoInModifica.value = memo;
    finestra.value = true;
}
function rileggi() {
    router.reload({ only: ['prossimaAssemblea'] });
}
function salvato(frase: string) {
    toast({ title: 'Da portare in assemblea', description: frase });
    rileggi();
}

// «Non serve più», con il motivo: la rotta del memo, poi la card si rilegge.
const nonServe = ref<MemoDellaFinestra | null>(null);
const motivo = ref('');
const errore = ref<string | null>(null);
const inCorso = ref(false);
function chiediNonServe(memo: MemoDellaFinestra) {
    nonServe.value = memo;
    motivo.value = '';
    errore.value = null;
}
function confermaNonServe() {
    if (!nonServe.value || motivo.value.trim() === '') return;
    inCorso.value = true;
    router.post(route(generateRoute('gestionale.memo.non-serve'), { condominio: props.condominioId, memo: nonServe.value.id }), { motivo: motivo.value.trim() }, {
        preserveScroll: true,
        only: ['prossimaAssemblea', 'flash'],
        onSuccess: () => { nonServe.value = null; },
        onError: (e: Record<string, string>) => { errore.value = Object.values(e)[0] ?? 'Il memo non si è chiuso. Riprova.'; },
        onFinish: () => { inCorso.value = false; },
    });
}

const pulsante = 'inline-flex h-9 items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
</script>

<template>
    <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="per-la-prossima-assemblea">
        <header class="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
            <div class="min-w-0">
                <h3 class="flex items-center gap-1.5 text-sm font-semibold text-slate-900 dark:text-white"><Gavel class="h-4 w-4" /> Per la prossima assemblea</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400">I punti che aspettano un'assemblea di {{ condominioNome }}.</p>
            </div>
            <span :class="[BADGE, CLASSI_DEL_BADGE.neutro, 'tabular-nums']" data-testid="totale-punti">{{ dati.totale }}</span>
        </header>
        <ul class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="p in dati.punti" :key="p.chiave_fonte" class="flex items-start gap-2 px-4 py-2.5" data-testid="punto-card">
                <div class="min-w-0 flex-1">
                    <p class="break-words text-sm text-slate-900 dark:text-slate-100">{{ p.testo }}</p>
                    <p class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                        <span :class="[BADGE, CLASSI_DEL_BADGE[colore[p.gruppo]]]">{{ p.origine }}</span>
                        <span v-if="p.frase" class="break-words">{{ p.frase }}</span>
                    </p>
                </div>
                <div v-if="haIlMenu(p)" class="shrink-0" data-testid="menu-del-punto">
                    <DropdownMenu :modal="false">
                        <DropdownMenuTrigger as-child>
                            <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
                                :aria-label="`Il punto «${p.testo}»`" data-testid="menu-punto"><MoreHorizontal class="h-4 w-4" /></button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-60">
                            <DropdownMenuItem v-if="p.apri" as-child class="text-xs">
                                <Link :href="p.apri.url" data-testid="apri-punto"><ExternalLink class="h-3.5 w-3.5" /> {{ p.apri.voce }}</Link>
                            </DropdownMenuItem>
                            <template v-if="gestisce && p.memo">
                                <DropdownMenuItem class="text-xs" data-testid="modifica-memo" @select="modifica(p.memo!)"><Pencil class="h-3.5 w-3.5" /> Modifica</DropdownMenuItem>
                                <DropdownMenuItem class="text-xs" data-testid="non-serve-memo" @select="chiediNonServe(p.memo!)"><XCircle class="h-3.5 w-3.5" /> Non serve più</DropdownMenuItem>
                            </template>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </li>
        </ul>
        <!-- La card non rifà i conti della nota sintetica: se c'è, lo dice, e i suoi punti si vedono quando si prepara l'assemblea. -->
        <p v-if="dati.con_la_nota" class="border-t border-slate-100 px-4 py-2 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400" data-testid="punti-della-nota">
            La nota sintetica può aggiungere altri punti: li vedi in «Prepara l'assemblea».
        </p>
        <p v-if="dati.altre > 0" class="border-t border-slate-100 px-4 py-2 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400" data-testid="altre-punti">
            e {{ dati.altre === 1 ? 'un altro' : `altri ${dati.altre}` }} · <Link :href="archivio" class="font-medium underline">Apri le assemblee</Link>
        </p>
        <footer v-if="gestisce" class="flex flex-col gap-2 border-t border-slate-100 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center dark:border-slate-800">
            <Link v-if="inPreparazione" :href="inPreparazione" class="inline-flex h-9 items-center rounded-md border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                data-testid="apri-in-preparazione">Apri l'{{ dati.in_preparazione!.nome.charAt(0).toLowerCase() + dati.in_preparazione!.nome.slice(1) }}</Link>
            <Link v-else :href="prepara" class="inline-flex h-9 items-center rounded-md bg-slate-900 px-3 text-sm font-medium text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900"
                data-testid="prepara-assemblea">Prepara l'assemblea</Link>
            <button type="button" :class="pulsante" data-testid="aggiungi-un-punto" @click="aggiungi"><Plus class="h-4 w-4" />Aggiungi un punto</button>
        </footer>

        <FinestraDelMemo v-if="gestisce" v-model:open="finestra" :condominio-id="condominioId" :memo="memoInModifica" @salvato="salvato" />

        <Dialog :open="nonServe !== null" @update:open="(v: boolean) => { if (!v) nonServe = null; }">
            <DialogContent class="sm:max-w-[480px]" data-testid="finestra-non-serve" @open-auto-focus.prevent>
                <DialogHeader>
                    <DialogTitle>Non serve più</DialogTitle>
                    <DialogDescription>Il memo esce dalla memoria del condominio, e il suo compito si chiude. Scrivi perché: resta con il memo.</DialogDescription>
                </DialogHeader>
                <p v-if="nonServe" class="break-words text-sm text-slate-800 dark:text-slate-200">«{{ nonServe.testo }}»</p>
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Perché non serve più</span>
                    <textarea v-model="motivo" rows="2" class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm dark:bg-slate-950" data-testid="motivo-non-serve"
                        placeholder="Per esempio: il consiglio ha già deciso" />
                </label>
                <p v-if="errore" class="text-sm text-rose-700 dark:text-rose-400">{{ errore }}</p>
                <div class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-3 sm:flex-row sm:justify-end dark:border-slate-800">
                    <Button variant="outline" @click="nonServe = null">Annulla</Button>
                    <Button :disabled="motivo.trim() === '' || inCorso" data-testid="conferma-non-serve" @click="confermaNonServe">{{ inCorso ? 'Un momento…' : 'Non serve più' }}</Button>
                </div>
            </DialogContent>
        </Dialog>
    </section>
</template>
