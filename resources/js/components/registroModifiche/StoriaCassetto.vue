<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il cassetto della «Storia» di una scheda (scena 1 della bozza, §8 del progetto della tappa 1): si apre a destra, sopra la pagina, senza lasciarla. In testa
 * «Storia di Ida Romano» e «Anagrafica · Condominio Via Roma 12 · 4 modifiche dal 01/11/2026», «Chiudi»; sotto le righe dalla più recente: la data e l'ora,
 * chi con il ruolo, l'azione con il badge, i dati «com'era → com'è» con «Ripristina» dove si può; per un gesto il riepilogo e «Apri». Venti righe per volta,
 * «Mostra altre».
 *
 * Le righe arrivano da una rotta JSON (`axios`, standard 16: il cassetto è lo stesso in trenta pagine). Dopo un ripristino il cassetto si rilegge e dice che
 * cosa è stato rimesso; chiudendolo, la pagina si ricarica con il dato rimesso (il modulo della pagina ha ancora quello di prima).
 *
 * L'oscuramento: nella «Storia» di una persona o di un fornitore, solo per l'amministratore (lo dice il server),
 * «Oscura i suoi dati nel registro» emette `oscura` con `{ soggetto_tipo, soggetto_id, nome }`.
 */
import { ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { usePermission } from '@/composables/permissions';
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { EyeOff, History, X } from 'lucide-vue-next';
import RipristinaFinestra from '@/components/registroModifiche/RipristinaFinestra.vue';
import {
    coloreDellAzione, dettaglioDiChi, messaggioDellErrore, paroleDellAzione, testoAltre, valore, type RigaDellaStoria, type SoggettoDaOscurare, type StoriaDellaScheda,
} from '@/lib/registroModifiche/registro';

const props = defineProps<{ open: boolean; tipo: string; id?: number | null }>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void; (e: 'oscura', soggetto: SoggettoDaOscurare): void }>();

const { generateRoute } = usePermission();
const storia = ref<StoriaDellaScheda | null>(null);
const righe = ref<RigaDellaStoria[]>([]);
const errore = ref<string | null>(null);
const caricamento = ref(false);
const esito = ref<string | null>(null);
const daRipristinare = ref<{ riga: number; campo: string } | null>(null);
const finestraAperta = ref(false);
let ripristinato = false;
let lettura = 0;

const indirizzo = (pagina: number) => route(generateRoute('registro-modifiche.storia'),
    props.id !== null && props.id !== undefined ? { tipo: props.tipo, id: props.id, pagina: pagina > 1 ? pagina : undefined } : { tipo: props.tipo, pagina: pagina > 1 ? pagina : undefined });

async function leggi(pagina = 1) {
    const numero = ++lettura;
    caricamento.value = true;
    errore.value = null;
    try {
        const r = (await axios.get(indirizzo(pagina))).data as StoriaDellaScheda;
        if (numero !== lettura) {
            return;
        }
        storia.value = r;
        righe.value = pagina === 1 ? r.righe : [...righe.value, ...r.righe];
    } catch (e) {
        if (numero === lettura) {
            errore.value = messaggioDellErrore(e, 'La storia non si è caricata. Riprova.');
        }
    } finally {
        if (numero === lettura) {
            caricamento.value = false;
        }
    }
}

watch(() => props.open, (aperto) => {
    if (aperto) {
        esito.value = null;
        ripristinato = false;
        leggi(1);
    } else if (ripristinato) {
        // Il modulo della pagina ha ancora il dato di prima del ripristino: la pagina si rilegge da capo.
        ripristinato = false;
        router.visit(window.location.href, { preserveScroll: true, preserveState: false });
    }
}, { immediate: true });

function ripristina(riga: RigaDellaStoria, campo: string) {
    daRipristinare.value = { riga: riga.id, campo };
    finestraAperta.value = true;
}

function fatto(messaggio: string) {
    esito.value = `${messaggio} Chiudendo la storia, la pagina si aggiorna con il dato rimesso.`;
    ripristinato = true;
    leggi(1);
}

const mostraAltre = () => leggi((storia.value?.pagina ?? 1) + 1);
</script>

<template>
    <Sheet :open="open" @update:open="$emit('update:open', $event)">
        <!-- Il pulsante di serie del cassetto (la sola «X») lascia il posto a «Chiudi» scritto per intero. -->
        <SheetContent class="w-full gap-0 overflow-y-auto p-0 sm:max-w-2xl [&>button:last-child]:hidden" data-testid="cassetto-storia">
            <SheetHeader class="space-y-0 border-b border-slate-100 px-5 py-4 text-left dark:border-slate-800">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                        <div class="shrink-0 rounded-lg bg-slate-100 p-1.5 text-slate-700 dark:bg-slate-800 dark:text-slate-300"><History class="h-4 w-4" /></div>
                        <SheetTitle class="break-words text-base font-bold text-slate-900 dark:text-white" data-testid="titolo-storia">{{ storia?.titolo ?? 'Storia' }}</SheetTitle>
                    </div>
                    <SheetClose data-testid="chiudi-storia"
                        class="inline-flex shrink-0 items-center gap-1 rounded-md px-2 py-1 text-xs font-bold text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200">
                        <X class="h-4 w-4" /> Chiudi
                    </SheetClose>
                </div>
                <SheetDescription class="mt-1 text-xs text-slate-500 dark:text-slate-400" data-testid="sottotitolo-storia">
                    {{ storia?.sottotitolo ?? 'Chi ha cambiato che cosa e quando, dalla modifica più recente.' }}
                </SheetDescription>
                <div v-if="storia?.oscuramento" class="pt-2">
                    <button type="button" data-testid="oscura"
                        class="inline-flex h-8 items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
                        @click="emit('oscura', storia.oscuramento)">
                        <EyeOff class="h-3.5 w-3.5" /> Oscura i suoi dati nel registro
                    </button>
                </div>
            </SheetHeader>

            <p v-if="esito" class="mx-5 mt-4 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300" data-testid="esito-ripristino">{{ esito }}</p>
            <div v-if="errore" class="mx-5 mt-4 flex flex-wrap items-center gap-3 text-sm text-rose-700 dark:text-rose-400" data-testid="errore-storia">
                {{ errore }} <button type="button" class="text-xs font-semibold underline" @click="leggi(1)">Riprova</button>
            </div>
            <p v-else-if="caricamento && righe.length === 0" class="px-5 py-6 text-sm text-slate-500 dark:text-slate-400">Arriva la storia…</p>
            <p v-else-if="storia && righe.length === 0" class="px-5 py-6 text-sm text-slate-500 dark:text-slate-400" data-testid="storia-vuota">
                Nel registro non c'è ancora una modifica di questa scheda.
            </p>

            <div v-for="r in righe" :key="r.id" class="border-b border-slate-100 px-5 py-3 last:border-b-0 dark:border-slate-800" data-testid="riga-storia">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                    <span class="tabular-nums text-slate-700 dark:text-slate-300">{{ r.avvenuta_il }}</span>
                    <span class="text-slate-400">·</span>
                    <strong class="font-semibold text-slate-900 dark:text-white">{{ r.autore.nome }}</strong>
                    <!-- Il ruolo (o da dove viene la riga del programma) in un badge grigio, come l'azione accanto (prova a video, 19/11). -->
                    <span v-if="dettaglioDiChi(r)" class="inline-flex items-center rounded-md border border-slate-200 bg-slate-50 px-2 py-px text-xs font-medium text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300" data-testid="ruolo">{{ dettaglioDiChi(r) }}</span>
                    <span class="inline-flex items-center rounded-md border px-2 py-px text-xs font-medium" :class="coloreDellAzione(r.azione)" data-testid="azione">{{ paroleDellAzione(r.azione) }}</span>
                    <span v-if="r.annullata_dal_ripristino_del" class="inline-flex items-center rounded-md border border-orange-200 bg-orange-50 px-2 py-px text-xs font-medium text-orange-800 dark:border-orange-800 dark:bg-orange-950/40 dark:text-orange-300">Annullata dal ripristino del {{ r.annullata_dal_ripristino_del }}</span>
                    <span v-if="r.fuori_dal_programma" class="inline-flex items-center rounded-md border border-rose-200 bg-rose-50 px-2 py-px text-xs font-medium text-rose-800 dark:border-rose-900 dark:bg-rose-950/20 dark:text-rose-300" data-testid="fuori-dal-programma">Aggiunta fuori dal programma</span>
                </div>
                <!-- Dove: la scheda toccata, con il nome che aveva quel giorno (prova a video, 19/11: «vedo chi ha fatto la modifica ma non dove»). -->
                <p class="mt-0.5 break-words text-xs text-slate-500 dark:text-slate-400" data-testid="oggetto-riga">{{ r.oggetto.nome }}</p>
                <p v-if="r.fuori_dal_programma" class="mt-1 text-xs text-rose-800 dark:text-rose-300" data-testid="fuori-dal-programma-frase">Questa riga non ha la firma del programma: è stata aggiunta o cambiata fuori dal programma, per esempio direttamente nel database.</p>

                <!-- Il dato come nella tabella del registro: il nome sopra, piccolo; sotto il valore di prima barrato e «→ valore nuovo», che va a capo intero
                     quando non ci sta; «Ripristina» a destra. Prima le quattro colonne fisse lasciavano la freccia e il valore nuovo lontani (prova a video, 19/11). -->
                <div v-for="(c, i) in r.campi" :key="`${r.id}-${c.chiave ?? i}`" class="mt-2 flex items-end justify-between gap-3" data-testid="campo">
                    <div class="min-w-0">
                        <span class="block text-xs text-slate-500 dark:text-slate-400" data-testid="etichetta-dato">{{ c.etichetta }}</span>
                        <span class="flex flex-wrap items-baseline gap-x-1.5 text-sm" data-testid="valore-dato">
                            <span class="min-w-0 break-words" :class="c.prima === null || c.prima === '' ? 'italic text-slate-400 dark:text-slate-500' : 'text-slate-500 line-through decoration-slate-400 dark:text-slate-400'">{{ valore(c.prima) }}</span>
                            <span class="min-w-0 break-words text-slate-900 dark:text-slate-100">→&nbsp;<span :class="c.dopo === null || c.dopo === '' ? 'italic text-slate-400 dark:text-slate-500' : ''">{{ valore(c.dopo) }}</span></span>
                        </span>
                    </div>
                    <button v-if="c.chiave && r.ripristina[c.chiave]" type="button" data-testid="ripristina"
                        class="inline-flex h-7 w-[84px] shrink-0 items-center justify-center rounded-md border border-slate-200 bg-white text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800"
                        @click="ripristina(r, c.chiave)">Ripristina</button>
                </div>

                <div v-if="r.riepilogo && !(r.azione === 'modificata' && r.campi.length > 0)" class="mt-1.5 flex flex-wrap items-center justify-between gap-2 text-sm text-slate-700 dark:text-slate-300">
                    <span class="min-w-0 break-words">{{ r.riepilogo }}</span>
                    <a v-if="r.apri" :href="r.apri" class="inline-flex h-7 shrink-0 items-center rounded-md border border-slate-200 bg-white px-2.5 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800" data-testid="apri">
                        {{ r.rimando?.tipo === 'importazione' ? 'Vedi il lotto' : 'Apri' }}
                    </a>
                </div>
                <p v-if="r.perche" class="mt-1 text-xs text-slate-500 dark:text-slate-400">Perché: {{ r.perche }}</p>
                <p v-if="r.nota" class="mt-1 text-xs text-slate-500 dark:text-slate-400" data-testid="nota">{{ r.nota }}</p>
                <p v-if="r.oscurata" class="mt-1 text-xs text-slate-500 dark:text-slate-400">I dati della persona in questa riga sono oscurati.</p>
            </div>

            <div v-if="storia && storia.altre > 0" class="flex flex-wrap items-center gap-2 border-t border-slate-100 bg-slate-50 px-5 py-2.5 text-xs font-semibold text-slate-500 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-400" data-testid="altre">
                {{ testoAltre(storia.altre) }} ·
                <button type="button" class="underline" :disabled="caricamento" data-testid="mostra-altre" @click="mostraAltre">{{ caricamento ? 'Arrivano…' : 'Mostra altre' }}</button>
            </div>
        </SheetContent>
    </Sheet>

    <RipristinaFinestra v-model:open="finestraAperta" :riga-id="daRipristinare?.riga ?? null" :campo="daRipristinare?.campo ?? null" @fatto="fatto" />
</template>
