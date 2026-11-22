<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La riunione registrata a mano e l'assemblea tenuta su carta (§3 P6, R2, R16, R17): le delibere per punto con chi le usa («La usano 2 schede: il
 * piano «Facciata 2026 — rate» dice 30/11/2026…»), il verbale sottoscritto (scaricarlo, sostituirlo con la versione di prima che resta, toglierlo) o il segno
 * «Verbale da caricare» (decisione 563), «Correggi la data» della riunione con tutte le sue delibere, e su ogni delibera a mano «Sposta in un'altra riunione»
 * ed «Elimina». Per l'assemblea tenuta su carta, sul punto senza delibera «Registra la delibera»: nasce lì; una delibera sbagliata sul punto si elimina (il
 * punto resta) e si registra di nuovo, ma non si sposta. L'ultima delibera di una riunione a mano senza il verbale propone di eliminare anche la riunione.
 *
 * Una delibera usata da una scheda non si elimina: la frase dice quale cambiare prima. Una delibera con le cose da fare del seguito si elimina con il motivo,
 * nella sua finestra (`EliminaLaDelibera`), e tutto resta nel diario. Una riunione con il verbale non si elimina: prima si toglie il verbale, con la sua
 * conferma e la sua riga del registro. Per l'assemblea tenuta su carta il riquadro del verbale dice «Carica il verbale firmato»: prende il numero del
 * registro, le schede dicono «verbale da caricare» finché non c'è, e i trenta giorni aspettano le consegne. Al telefono le delibere vanno in card.
 */
import { computed, ref } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { router, useForm } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { CalendarDays, Download, FileUp, MoreHorizontal, MoveRight, Trash2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import CampoGiorno from '@/components/CampoGiorno.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EliminaLaDelibera from '@/components/assemblee/seguito/EliminaLaDelibera.vue';
import { usePermission } from '@/composables/permissions';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import { BADGE, CLASSI_DEL_BADGE, coloreDellEsito, giornoItaliano } from '@/lib/assemblee/assemblee';
import type { AssembleaDellaPagina, DeliberaDellaPagina, PuntoDellaPagina, Scelta } from '@/lib/assemblee/pagine';
import type { CosaDaFare } from '@/types/assemblee';

const props = defineProps<{
    condominioId: number;
    assemblea: AssembleaDellaPagina;
    ordine: PuntoDellaPagina[];
    esiti: Scelta[];
    riunioni: { id: number; nome: string }[];
    gestisce: boolean;
    registraIlVerbale: boolean;
    /** Le cose da fare di ogni delibera (dal seguito), per la finestra di «Elimina». */
    cose?: Record<number, CosaDaFare[]>;
}>();

const { generateRoute } = usePermission();
const oggi = giornoDiRoma();
const parametri = computed(() => ({ condominio: props.condominioId, assemblea: props.assemblea.id }));
const delibere = computed(() => props.ordine.flatMap((p) => p.delibere));
const usate = computed(() => delibere.value.filter((d) => d.posti.length > 0).length);

// «Correggi la data»
const finestraData = ref(false);
const giorno = ref('');
function apriCorreggi() {
    giorno.value = props.assemblea.data ?? '';
    finestraData.value = true;
}
const postiDellaRiunione = computed(() => delibere.value.flatMap((d) => d.posti));
function correggi() {
    router.post(route(generateRoute('gestionale.assemblee.correggi-data'), parametri.value), { giorno: giorno.value },
        { preserveScroll: true, onSuccess: () => { finestraData.value = false; } });
}

// Il verbale sottoscritto
const moduloVerbale = useForm<{ verbale: File | null }>({ verbale: null });
const campoVerbale = ref<HTMLInputElement | null>(null);
function caricaIlVerbale(e: Event) {
    moduloVerbale.verbale = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (!moduloVerbale.verbale) return;
    moduloVerbale.post(route(generateRoute('gestionale.assemblee.verbale.store'), parametri.value), {
        preserveScroll: true, forceFormData: true, onFinish: () => { if (campoVerbale.value) campoVerbale.value.value = ''; },
    });
}
const confermaTogliVerbale = ref(false);
function togliIlVerbale() {
    router.delete(route(generateRoute('gestionale.assemblee.verbale.destroy'), parametri.value), { preserveScroll: true, onSuccess: () => { confermaTogliVerbale.value = false; } });
}
const indirizzoDelVerbale = computed(() => route(generateRoute('gestionale.assemblee.verbale.show'), parametri.value));

// Sposta ed elimina una delibera a mano
const daSpostare = ref<DeliberaDellaPagina | null>(null);
const verso = ref<number | 'nuova'>('nuova');
const nuovaGiorno = ref('');
const nuovaNumero = ref('');
const finestraSposta = ref(false);
function apriSposta(d: DeliberaDellaPagina) {
    daSpostare.value = d;
    verso.value = props.riunioni[0]?.id ?? 'nuova';
    nuovaGiorno.value = props.assemblea.data ?? '';
    nuovaNumero.value = '';
    finestraSposta.value = true;
}
const opzioniVerso = computed(() => [...props.riunioni.map((r) => ({ valore: r.id as number | 'nuova', nome: r.nome })), { valore: 'nuova' as const, nome: 'Una riunione nuova' }]);
function sposta() {
    if (!daSpostare.value) return;
    const dati = verso.value === 'nuova' ? { giorno: nuovaGiorno.value, numero_verbale: nuovaNumero.value || null } : { riunione_id: verso.value };
    router.post(route(generateRoute('gestionale.delibere.sposta'), { condominio: props.condominioId, delibera: daSpostare.value.id }), dati,
        { onSuccess: () => { finestraSposta.value = false; } });
}
const daEliminare = ref<DeliberaDellaPagina | null>(null);
const confermaElimina = ref(false);
/** L'ultima delibera di una riunione a mano senza il verbale: la conferma propone di eliminare anche la riunione, che resterebbe vuota. */
const ultimaDellaRiunione = computed(() => props.assemblea.a_mano && !props.assemblea.verbale && delibere.value.length === 1);
const conLaRiunione = ref(true);
const finestraElimina = ref(false);
const coseDi = (d: DeliberaDellaPagina | null) => (d ? props.cose?.[d.id] ?? [] : []);
const puntoDi = (d: DeliberaDellaPagina | null) => props.ordine.find((p) => p.delibere.some((x) => x.id === d?.id))?.numero ?? null;
function chiediDiEliminare(d: DeliberaDellaPagina) {
    daEliminare.value = d;
    conLaRiunione.value = true;
    // Con le cose da fare del seguito la finestra chiede il motivo, ed elenca ciò che se ne va con la delibera.
    if (coseDi(d).length > 0 && d.posti.length === 0) {
        finestraElimina.value = true;
        return;
    }
    confermaElimina.value = true;
}
function elimina() {
    if (!daEliminare.value) return;
    router.delete(route(generateRoute('gestionale.delibere.destroy'), { condominio: props.condominioId, delibera: daEliminare.value.id }), {
        data: { con_la_riunione: ultimaDellaRiunione.value && conLaRiunione.value },
        preserveScroll: true, onFinish: () => { confermaElimina.value = false; },
    });
}
const descrizioneDellElimina = computed(() => {
    if (daEliminare.value?.posti.length) return `La delibera è usata: ${daEliminare.value.usata}. Cambia prima quelle schede.`;
    if (props.assemblea.tenuta_fuori) return 'Il punto resta nell\'ordine del giorno, senza delibera: registri di nuovo quella giusta. La riga resta nel registro delle modifiche.';
    return 'La riga resta nel registro delle modifiche.';
});
const confermaEliminaRiunione = ref(false);
function eliminaLaRiunione() {
    router.delete(route(generateRoute('gestionale.assemblee.destroy'), parametri.value));
}

// «Registra la delibera» sul punto di un'assemblea tenuta su carta
const sulPunto = ref<PuntoDellaPagina | null>(null);
const esitoSulPunto = ref('approvata');
const testoSulPunto = ref('');
const finestraSulPunto = ref(false);
function apriSulPunto(p: PuntoDellaPagina) {
    sulPunto.value = p;
    esitoSulPunto.value = 'approvata';
    testoSulPunto.value = p.testo;
    finestraSulPunto.value = true;
}
function registraSulPunto() {
    if (!sulPunto.value) return;
    router.post(route(generateRoute('gestionale.assemblee.punti.delibera'), { ...parametri.value, punto: sulPunto.value.id }),
        { esito: esitoSulPunto.value, testo: testoSulPunto.value || null }, { preserveScroll: true, onSuccess: () => { finestraSulPunto.value = false; } });
}

const pulsante = 'inline-flex h-8 items-center justify-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';
</script>

<template>
    <div class="space-y-4" data-testid="riunione-registrata">
        <!-- Il verbale sottoscritto -->
        <section class="rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-950" data-testid="verbale">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Il verbale sottoscritto</h3>
                    <p v-if="assemblea.verbale" class="break-words text-sm text-slate-700 dark:text-slate-300">{{ assemblea.verbale.nome }}</p>
                    <p v-else class="text-sm"><span :class="[BADGE, CLASSI_DEL_BADGE.ambra]" data-testid="verbale-da-caricare">Verbale da caricare</span></p>
                    <template v-if="!assemblea.verbale && assemblea.tenuta_fuori">
                        <p class="mt-1 text-xs text-slate-600 dark:text-slate-300" data-testid="verbale-di-carta">Quando il presidente te lo porta firmato, caricalo: prende il
                            numero del registro dei verbali.</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Trenta giorni: la data non c'è finché il verbale non è caricato e consegnato agli assenti. Nelle
                            schede le delibere dicono «verbale da caricare» al posto del numero, e il numero compare da solo quando lo carichi.</p>
                    </template>
                    <p v-else-if="!assemblea.verbale" class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        La riunione vale lo stesso; il verbale firmato lo carica chi ha il permesso «Registra il verbale sottoscritto».
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a v-if="assemblea.verbale" :href="indirizzoDelVerbale" :class="pulsante" data-testid="scarica-verbale"><Download class="h-3.5 w-3.5" /> Scarica</a>
                    <label v-if="registraIlVerbale" :class="[pulsante, 'cursor-pointer']" data-testid="carica-verbale">
                        <FileUp class="h-3.5 w-3.5" /> {{ assemblea.verbale ? 'Sostituisci' : assemblea.tenuta_fuori ? 'Carica il verbale firmato' : 'Carica il verbale' }}
                        <input ref="campoVerbale" type="file" accept=".pdf,.p7m,.jpg,.jpeg,.png" class="sr-only" @change="caricaIlVerbale" />
                    </label>
                    <button v-if="registraIlVerbale && assemblea.verbale" type="button" :class="pulsante" data-testid="togli-verbale" @click="confermaTogliVerbale = true">
                        <Trash2 class="h-3.5 w-3.5" /> Togli
                    </button>
                </div>
            </div>
            <p v-if="moduloVerbale.errors.verbale" class="mt-1 text-xs text-rose-700 dark:text-rose-400">{{ moduloVerbale.errors.verbale }}</p>
            <p v-if="assemblea.verbale" class="mt-1 text-xs text-slate-500 dark:text-slate-400">Sostituito, il verbale di prima resta come versione, in sola lettura.</p>
        </section>

        <!-- Le delibere, per punto -->
        <section class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="delibere-della-riunione">
            <header class="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">{{ assemblea.a_mano ? 'Le delibere' : 'I punti e le delibere' }}</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ usate === 0 ? 'Nessuna scheda le usa ancora: le scegli con «Scegli la delibera» nelle pagine.' : `${usate === 1 ? 'Una delibera è usata' : `${usate} delibere sono usate`} dalle schede: una delibera corretta dopo non le cambia da sola.` }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button v-if="gestisce && assemblea.a_mano" type="button" :class="pulsante" data-testid="correggi-data" @click="apriCorreggi">
                        <CalendarDays class="h-3.5 w-3.5" /> Correggi la data
                    </button>
                    <button v-if="gestisce && assemblea.a_mano && delibere.length === 0" type="button" :class="pulsante" data-testid="elimina-riunione"
                        @click="confermaEliminaRiunione = true"><Trash2 class="h-3.5 w-3.5" /> Elimina la riunione</button>
                </div>
            </header>
            <p v-if="ordine.length === 0" class="px-4 py-6 text-center text-sm text-slate-500 dark:text-slate-400">
                Nessuna delibera in questa riunione. Se è stata registrata per errore, eliminala{{ assemblea.verbale ? ', dopo aver tolto il verbale' : '' }}.
            </p>
            <ol class="divide-y divide-slate-100 dark:divide-slate-800">
                <li v-for="p in ordine" :key="p.id" class="px-4 py-3" data-testid="punto-registrato">
                    <p class="break-words text-sm text-slate-900 dark:text-slate-100"><span class="font-semibold tabular-nums">{{ p.numero }}.</span> {{ p.testo }}</p>
                    <div v-for="d in p.delibere" :key="d.id" class="mt-2 flex flex-wrap items-start justify-between gap-2 rounded-lg border border-slate-200 p-2.5 dark:border-slate-700" data-testid="delibera-registrata">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-1.5 text-xs text-slate-600 dark:text-slate-300">
                                <span :class="[BADGE, CLASSI_DEL_BADGE[coloreDellEsito(d.esito)]]">{{ d.esito_parola }}</span>
                                <span v-if="assemblea.a_mano" :class="[BADGE, CLASSI_DEL_BADGE.neutro]">A mano</span>
                                <span>{{ giornoItaliano(d.deliberata_il) }}</span>
                            </p>
                            <p v-if="d.testo !== p.testo" class="mt-1 break-words text-sm text-slate-800 dark:text-slate-200">{{ d.testo }}</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400" data-testid="usata">{{ d.usata ?? 'Nessuna scheda la usa.' }}</p>
                        </div>
                        <DropdownMenu v-if="gestisce && (assemblea.a_mano || assemblea.tenuta_fuori)">
                            <DropdownMenuTrigger as-child>
                                <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-slate-200 dark:border-slate-700" aria-label="La delibera" data-testid="menu-delibera">
                                    <MoreHorizontal class="h-4 w-4" /></button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-56">
                                <DropdownMenuItem v-if="assemblea.a_mano" @select="apriSposta(d)"><MoveRight class="mr-2 h-4 w-4" /> Sposta in un'altra riunione</DropdownMenuItem>
                                <DropdownMenuItem class="text-rose-700 dark:text-rose-400" @select="chiediDiEliminare(d)"><Trash2 class="mr-2 h-4 w-4" /> Elimina</DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                    <div v-if="p.delibere.length === 0" class="mt-2 flex flex-wrap items-center gap-2">
                        <span :class="[BADGE, CLASSI_DEL_BADGE.neutro]">Non trattato</span>
                        <button v-if="gestisce && assemblea.tenuta_fuori" type="button" :class="pulsante" data-testid="registra-sul-punto" @click="apriSulPunto(p)">Registra la delibera</button>
                    </div>
                </li>
            </ol>
        </section>

        <!-- «Correggi la data» -->
        <Dialog v-model:open="finestraData">
            <DialogContent class="sm:max-w-[480px]" data-testid="finestra-correggi-data" @open-auto-focus.prevent>
                <DialogHeader>
                    <DialogTitle>Correggi la data della riunione</DialogTitle>
                    <DialogDescription>Il giorno cambia per la riunione e per tutte le sue delibere. Le schede che le usano non cambiano da sole.</DialogDescription>
                </DialogHeader>
                <CampoGiorno v-model="giorno" :max="oggi" class="max-w-[220px]" />
                <ul v-if="postiDellaRiunione.length" class="space-y-0.5 text-xs text-slate-600 dark:text-slate-300">
                    <li v-for="(p, i) in postiDellaRiunione" :key="i">{{ p.nome }}{{ p.data ? ` dice ${giornoItaliano(p.data)}` : '' }}: la rivedi tu.</li>
                </ul>
                <div class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-3 sm:flex-row sm:justify-end dark:border-slate-800">
                    <Button variant="outline" @click="finestraData = false">Annulla</Button>
                    <Button :disabled="giorno === '' || giorno > oggi" @click="correggi">Correggi la data</Button>
                </div>
            </DialogContent>
        </Dialog>

        <!-- «Sposta in un'altra riunione» -->
        <Dialog v-model:open="finestraSposta">
            <DialogContent class="sm:max-w-[480px]" data-testid="finestra-sposta" @open-auto-focus.prevent>
                <DialogHeader>
                    <DialogTitle>Sposta in un'altra riunione</DialogTitle>
                    <DialogDescription>La delibera prende il giorno della riunione di arrivo. Le schede che la usano non cambiano.</DialogDescription>
                </DialogHeader>
                <div class="space-y-3">
                    <div class="menu-campo">
                        <v-select v-model="verso" :options="opzioniVerso" label="nome" :reduce="(o: { valore: number | 'nuova' }) => o.valore" :clearable="false" :searchable="false" />
                    </div>
                    <div v-if="verso === 'nuova'" class="grid grid-cols-2 gap-2">
                        <CampoGiorno v-model="nuovaGiorno" :max="oggi" aria-label="Il giorno della riunione" class="h-9 text-sm" />
                        <input v-model="nuovaNumero" type="text" placeholder="Numero del verbale" class="h-9 rounded-md border border-input bg-transparent px-3 text-sm dark:bg-slate-950" />
                    </div>
                </div>
                <div class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-3 sm:flex-row sm:justify-end dark:border-slate-800">
                    <Button variant="outline" @click="finestraSposta = false">Annulla</Button>
                    <Button :disabled="verso === 'nuova' && nuovaGiorno === ''" @click="sposta">Sposta la delibera</Button>
                </div>
            </DialogContent>
        </Dialog>

        <!-- «Registra la delibera» sul punto -->
        <Dialog v-model:open="finestraSulPunto">
            <DialogContent class="sm:max-w-[520px]" data-testid="finestra-sul-punto" @open-auto-focus.prevent>
                <DialogHeader>
                    <DialogTitle>Registra la delibera{{ sulPunto ? ` sul punto ${sulPunto.numero}` : '' }}</DialogTitle>
                    <DialogDescription>Il giorno è quello della seduta tenuta, il {{ giornoItaliano(assemblea.data) }}.</DialogDescription>
                </DialogHeader>
                <div class="space-y-3">
                    <div class="menu-campo">
                        <v-select v-model="esitoSulPunto" :options="esiti" label="nome" :reduce="(o: Scelta) => o.valore" :clearable="false" :searchable="false" />
                    </div>
                    <textarea v-model="testoSulPunto" rows="3" class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm dark:bg-slate-950" aria-label="Che cosa ha deciso" />
                </div>
                <div class="flex flex-col-reverse gap-2 border-t border-slate-100 pt-3 sm:flex-row sm:justify-end dark:border-slate-800">
                    <Button variant="outline" @click="finestraSulPunto = false">Annulla</Button>
                    <Button @click="registraSulPunto">Registra la delibera</Button>
                </div>
            </DialogContent>
        </Dialog>

        <ConfirmDialog v-model="confermaElimina" title="Eliminare la delibera registrata a mano?"
            :confirm-text="ultimaDellaRiunione && conLaRiunione && !daEliminare?.posti.length ? 'Elimina la delibera e la riunione' : 'Elimina la delibera'"
            @confirm="elimina">
            <span class="block text-sm text-slate-600 dark:text-slate-300">{{ descrizioneDellElimina }}</span>
            <label v-if="ultimaDellaRiunione && !daEliminare?.posti.length" class="mt-3 flex items-start gap-2 text-sm text-slate-800 dark:text-slate-200" data-testid="elimina-anche-la-riunione">
                <Checkbox v-model="conLaRiunione" class="mt-0.5 rounded" />
                <span>Elimina anche la {{ assemblea.nome.charAt(0).toLowerCase() + assemblea.nome.slice(1) }}: senza questa delibera resta vuota.</span>
            </label>
        </ConfirmDialog>
        <EliminaLaDelibera v-model:open="finestraElimina" :condominio-id="condominioId" :delibera="daEliminare ? { id: daEliminare.id, testo: daEliminare.testo,
            punto: puntoDi(daEliminare) } : null" :cose="coseDi(daEliminare)" :con-la-riunione="ultimaDellaRiunione && conLaRiunione" />
        <ConfirmDialog v-model="confermaTogliVerbale" title="Togliere il verbale sottoscritto?" confirm-text="Togli il verbale"
            description="Il file e le sue versioni di prima se ne vanno; nel registro delle modifiche resta la riga con il nome, la grandezza e l'impronta del file." @confirm="togliIlVerbale" />
        <ConfirmDialog v-model="confermaEliminaRiunione" title="Eliminare la riunione?" confirm-text="Elimina la riunione"
            :description="assemblea.verbale ? 'La riunione ha il verbale sottoscritto: prima toglilo, poi la elimini.' : 'La riunione non ha delibere. La riga resta nel registro delle modifiche.'"
            @confirm="eliminaLaRiunione" />
    </div>
</template>
