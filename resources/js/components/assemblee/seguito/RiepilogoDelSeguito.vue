<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Che cosa cambia nel gestionale» (la bozza approvata del seguito, scena 1, con le scene 7, 10 e 12 della bozza della tappa): un punto per riga, con l'esito,
 * ciò che si applica adesso (spuntato di serie: si toglie ciò che non si vuole adesso), «Che cosa resta da fare» con la data e la persona (con la data
 * diventa un compito dell'Action inbox), «Un'altra cosa da fare», «Avvisa i condòmini» come pulsante con la sua casella (acceso, spento o assente come dice
 * il server), la delega in vigore, ciò che resta fuori nel riquadro arancio con «Apri il piano», «Portalo alla prossima assemblea» per le varie, e per una
 * delibera approvata con modifiche «Che cosa ha cambiato l'assemblea». In fondo il conto, che segue le spunte, e «Conferma»: niente cambia in silenzio.
 *
 * Mostra solo le delibere ancora da confermare (una prosecuzione ha le sue). Il corpo di «Conferma» lo ricalcola il server (R2); la risposta torna con la
 * visita parziale del seguito, dei passi e degli esiti per riga. Chi vede soltanto legge, senza spunte né pulsanti.
 */
import { computed, onMounted, reactive, ref } from 'vue';
import axios from 'axios';
import { Link, router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import { AlertTriangle, Check, Info, Loader2, Megaphone, Plus, X } from 'lucide-vue-next';
import { Checkbox } from '@/components/ui/checkbox';
import { Textarea } from '@/components/ui/textarea';
import CampoGiorno from '@/components/CampoGiorno.vue';
import FinestraDelMemo from '@/components/assemblee/FinestraDelMemo.vue';
import { usePermission } from '@/composables/permissions';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import { BADGE, CLASSI_DEL_BADGE, coloreDellEsito, euro } from '@/lib/assemblee/assemblee';
import {
    contoDelRiepilogo, corpoDellaConferma, eSpuntata, fraseDelConto, righeDelPunto, SCHEDE_CON_LA_PAGINA, type AltraCosa, type QuandoEChi,
} from '@/lib/assemblee/seguito';
import { ARANCIO, CARD, PRINCIPALE, PULSANTE } from '@/components/assemblee/verbale/finestra';
import type { AChiMemo, RiepilogoDelSeguito, RigaDelSeguito } from '@/types/assemblee';

const props = defineProps<{
    condominioId: number;
    assembleaId: number;
    riepilogo: RiepilogoDelSeguito;
    /** «Dall'assemblea del 15/12/2026, verbale n. 42.», o per la riunione su carta «… · verbale da caricare.» */
    sottotitolo: string;
    puoGestire: boolean;
}>();

const { generateRoute } = usePermission();
const oggi = giornoDiRoma();
const punti = computed(() => props.riepilogo.punti.filter((p) => !p.confermata));
const spunte = reactive<Record<string, boolean>>({});
const cose = reactive<Record<string, QuandoEChi>>({});
const altre = ref<AltraCosa[]>([]);
const modifiche = reactive<Record<number, string>>({});
const collaboratori = ref<{ id: number; nome: string }[]>([]);
const inCorso = ref(false);
const errore = ref<string | null>(null);

/** La data e la persona di una cosa proposta: senza data, niente compito; di serie a chi scrive. */
const DI_SERIE: QuandoEChi = { entro_il: '', a_chi: 'me', assegnato_a: null };
const quandoDi = (r: RigaDelSeguito): QuandoEChi => cose[r.chiave] ?? DI_SERIE;
function cambia(r: RigaDelSeguito, campi: Partial<QuandoEChi>) {
    cose[r.chiave] = { ...quandoDi(r), ...campi };
}
const persone = computed(() => [{ valore: 'me', nome: 'A me' }, { valore: 'studio', nome: 'A tutto lo studio' },
    ...collaboratori.value.map((c) => ({ valore: `c:${c.id}`, nome: c.nome }))]);
const personaDi = (q: QuandoEChi) => (q.a_chi === 'collaboratore' && q.assegnato_a ? `c:${q.assegnato_a}` : q.a_chi);
/** La persona scelta nel menu: «A me», «A tutto lo studio», o un collaboratore (`c:7`). */
const dallaScelta = (valore: string): Pick<QuandoEChi, 'a_chi' | 'assegnato_a'> => (valore.startsWith('c:')
    ? { a_chi: 'collaboratore', assegnato_a: Number(valore.slice(2)) } : { a_chi: valore as AChiMemo, assegnato_a: null });
function spunta(r: RigaDelSeguito, v: boolean | 'indeterminate') {
    spunte[r.chiave] = v === true;
}
function aggiungi(deliberaId: number) {
    altre.value.push({ delibera_id: deliberaId, testo: '', entro_il: '', a_chi: 'me', assegnato_a: null });
}
const altreDi = (deliberaId: number) => altre.value.filter((a) => a.delibera_id === deliberaId);
const togli = (a: AltraCosa) => { altre.value = altre.value.filter((x) => x !== a); };

const conto = computed(() => contoDelRiepilogo(punti.value, spunte, altre.value.filter((a) => a.testo.trim() !== '').length));
const daEseguire = (deliberaId: number) => punti.value.find((p) => p.delibera_id === deliberaId)?.righe
    .some((r) => r.tipo === 'cosa' && r.cosa?.tipo !== 'avvisa_condomini' && eSpuntata(r, spunte)) || altreDi(deliberaId).some((a) => a.testo.trim() !== '');

onMounted(async () => {
    if (!props.puoGestire) return;
    try {
        const r = await axios.get(route(generateRoute('gestionale.memo.proposta'), { condominio: props.condominioId }));
        collaboratori.value = (r.data?.collaboratori ?? []) as { id: number; nome: string }[];
    } catch {
        collaboratori.value = [];
    }
});

function conferma() {
    if (inCorso.value) return;
    inCorso.value = true;
    errore.value = null;
    router.post(route(generateRoute('gestionale.assemblee.seguito.conferma'), { condominio: props.condominioId, assemblea: props.assembleaId }),
        corpoDellaConferma(punti.value, spunte, cose, altre.value, modifiche) as never, {
            only: ['seguito', 'passi', 'seguito_esiti', 'flash'], preserveScroll: true,
            onError: (e) => { errore.value = Object.values(e as Record<string, string>)[0] ?? 'La conferma non è andata. Riprova.'; },
            onFinish: () => { inCorso.value = false; },
        });
}

// «Portalo alla prossima assemblea»: la finestra del memo con l'origine nel punto e il testo delle dichiarazioni.
const memo = ref<RigaDelSeguito | null>(null);
const finestraDelMemo = computed({ get: () => memo.value !== null, set: (v: boolean) => { if (!v) memo.value = null; } });
function memoSalvato() {
    memo.value = null;
    router.reload({ only: ['seguito'] });
}

/** «Apri il piano» di una riga che resta fuori: la scheda nel suo posto. */
const apriLaScheda = (r: RigaDelSeguito) => (r.scheda && SCHEDE_CON_LA_PAGINA.includes(r.scheda.tipo) ? route(generateRoute('gestionale.schede.apri'), { condominio: props.condominioId, tipo: r.scheda.tipo,
    id: r.scheda.id }) : null);
const parolaDellaScheda = (r: RigaDelSeguito) => (r.scheda?.tipo === 'piano_rate' ? 'Apri il piano' : 'Apri');
// I menu leggono il valore con una funzione con il nome: lo schema scritto dentro l'attributo il controllo dei tipi non lo legge.
const perValore = (o: { valore: string }) => o.valore;
</script>

<template>
    <section :class="CARD" data-testid="riepilogo-del-seguito">
        <header class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
            <h3 class="text-[15px] font-semibold text-slate-900 dark:text-white">Che cosa cambia nel gestionale</h3>
            <p class="mt-0.5 text-xs text-slate-600 dark:text-slate-400">{{ sottotitolo }} <template v-if="puoGestire">Tutto spuntato di serie: togli ciò che non
                vuoi adesso. Niente cambia in silenzio.</template></p>
        </header>

        <ol class="divide-y divide-slate-100 dark:divide-slate-800">
            <li v-for="p in punti" :key="p.delibera_id" class="space-y-2.5 px-4 py-3" data-testid="punto-del-riepilogo" :data-delibera="p.delibera_id">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <p class="min-w-0 flex-1 break-words text-sm font-semibold text-slate-900 dark:text-white">Punto {{ p.numero }} · {{ p.testo }}</p>
                    <span class="flex flex-wrap gap-1.5">
                        <span :class="[BADGE, CLASSI_DEL_BADGE[coloreDellEsito(p.esito)]]" data-testid="esito">{{ p.esito_parola }}</span>
                        <span v-if="daEseguire(p.delibera_id)" :class="[BADGE, CLASSI_DEL_BADGE.ambra]" data-testid="da-eseguire">Da eseguire</span>
                    </span>
                </div>

                <!-- Ciò che cambia adesso, ciò che resta fuori, la delega, le varie -->
                <div v-for="r in righeDelPunto(p).cambi" :key="r.chiave" data-testid="riga-del-riepilogo" :data-chiave="r.chiave" :data-tipo="r.tipo">
                    <div v-if="r.tipo === 'fuori'" :class="ARANCIO" data-testid="resta-fuori">
                        <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <div class="min-w-0 flex-1 space-y-1">
                            <p class="font-semibold">{{ r.titolo }}</p>
                            <p>{{ r.frase }}</p>
                            <p v-if="r.dati.differenza" class="font-medium" data-testid="differenza">{{ r.dati.differenza.frase }}</p>
                            <p v-for="v in r.dati.differenza?.voci ?? []" :key="v.nome">{{ v.frase }}</p>
                            <p v-for="t in r.dati.differenza?.tabelle ?? []" :key="t.nome">{{ t.frase }}</p>
                            <p v-if="r.dati.differenza?.rate">{{ r.dati.differenza.rate.frase }}</p>
                            <div class="flex flex-wrap items-center gap-2 pt-1">
                                <Link v-if="apriLaScheda(r)" :href="apriLaScheda(r)!" :class="PULSANTE" data-testid="apri-la-scheda">{{ parolaDellaScheda(r) }}</Link>
                                <span :class="[BADGE, CLASSI_DEL_BADGE.ambra]">Da rimettere a posto</span>
                            </div>
                        </div>
                    </div>
                    <label v-else-if="r.si_applica && !r.applicata" class="flex items-start gap-2.5">
                        <Checkbox :model-value="eSpuntata(r, spunte)" class="mt-0.5 rounded" :disabled="!puoGestire" :aria-label="r.titolo" data-testid="spunta"
                            @update:model-value="(v) => spunta(r, v)" />
                        <span class="min-w-0">
                            <span class="block break-words text-sm text-slate-900 dark:text-slate-100">{{ r.titolo }}</span>
                            <span class="block break-words text-xs text-slate-600 dark:text-slate-400">{{ r.frase }}</span>
                        </span>
                    </label>
                    <div v-else class="flex items-start gap-2.5">
                        <Check v-if="r.applicata && r.tipo !== 'memo'" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                        <Info v-else class="mt-0.5 h-4 w-4 shrink-0 text-sky-700 dark:text-sky-400" aria-hidden="true" />
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-1.5 break-words text-sm text-slate-900 dark:text-slate-100">{{ r.titolo }}
                                <span v-if="r.tipo === 'gia_fatto'" :class="[BADGE, CLASSI_DEL_BADGE.neutro]">Già fatto</span>
                                <span v-else-if="r.tipo === 'delega' && r.dati.delega?.tetto" :class="[BADGE, CLASSI_DEL_BADGE.blu]">Fino a {{ euro(r.dati.delega.tetto) }}</span>
                            </p>
                            <p class="break-words text-xs text-slate-600 dark:text-slate-400">{{ r.frase }}</p>
                            <button v-if="r.tipo === 'memo' && !r.applicata && puoGestire" type="button" :class="[PULSANTE, 'mt-1.5']" data-testid="portalo-alla-prossima"
                                @click="memo = r">Portalo alla prossima assemblea</button>
                            <p v-else-if="r.tipo === 'memo' && r.applicata" class="mt-1 text-xs text-emerald-700 dark:text-emerald-400">Già nella memoria del condominio.</p>
                        </div>
                    </div>
                </div>

                <!-- Approvata con modifiche: che cosa ha cambiato l'assemblea -->
                <div v-if="p.esito === 'approvata_con_modifiche'" class="space-y-1">
                    <label :for="`modifiche-${p.delibera_id}`" class="text-xs font-semibold text-slate-500 dark:text-slate-400">Che cosa ha cambiato l'assemblea</label>
                    <Textarea :id="`modifiche-${p.delibera_id}`" v-model="modifiche[p.delibera_id]" rows="2" class="min-h-[56px] resize-none text-sm" :disabled="!puoGestire"
                        placeholder="Per esempio: pulizia delle scale a € 5.400,00." data-testid="modifiche" />
                </div>

                <!-- Che cosa resta da fare -->
                <div v-if="righeDelPunto(p).cose.length || altreDi(p.delibera_id).length" class="space-y-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 dark:border-slate-800 dark:bg-slate-900/40"
                    data-testid="resta-da-fare">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Che cosa resta da fare</p>
                    <div v-for="r in righeDelPunto(p).cose" :key="r.chiave" class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_170px_190px] sm:items-center"
                        data-testid="cosa-proposta" :data-chiave="r.chiave">
                        <label class="flex min-w-0 items-start gap-2.5">
                            <Checkbox :model-value="eSpuntata(r, spunte)" class="mt-0.5 rounded" :disabled="!puoGestire || r.applicata" :aria-label="r.cosa?.testo ?? r.titolo"
                                @update:model-value="(v) => spunta(r, v)" />
                            <span class="min-w-0">
                                <span class="block break-words text-sm text-slate-900 dark:text-slate-100">{{ r.cosa?.testo ?? r.titolo }}</span>
                                <span class="block break-words text-xs text-slate-600 dark:text-slate-400">{{ r.frase }}</span>
                            </span>
                        </label>
                        <template v-if="puoGestire && eSpuntata(r, spunte)">
                            <CampoGiorno :model-value="quandoDi(r).entro_il" :min="oggi" svuotabile class="h-8 text-sm" aria-label="Entro il" data-testid="entro-il"
                                @update:model-value="(g: string | null) => cambia(r, { entro_il: g ?? '' })" />
                            <div class="menu-campo">
                                <v-select :model-value="personaDi(quandoDi(r))" :options="persone" label="nome" :reduce="perValore" :clearable="false"
                                    :searchable="false" aria-label="A chi" @update:model-value="(v: string) => cambia(r, dallaScelta(v))" />
                            </div>
                        </template>
                    </div>
                    <div v-for="(a, i) in altreDi(p.delibera_id)" :key="i" class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_170px_190px_auto] sm:items-center" data-testid="altra-cosa">
                        <input v-model="a.testo" type="text" maxlength="255" placeholder="Per esempio: firma con Serramenti Marino"
                            class="h-8 w-full rounded-md border border-input bg-transparent px-3 text-sm dark:bg-slate-950" aria-label="Che cosa resta da fare" />
                        <CampoGiorno :model-value="a.entro_il" :min="oggi" svuotabile class="h-8 text-sm" aria-label="Entro il" @update:model-value="(g: string | null) => { a.entro_il = g ?? ''; }" />
                        <div class="menu-campo">
                            <v-select :model-value="personaDi(a)" :options="persone" label="nome" :reduce="perValore" :clearable="false"
                                :searchable="false" aria-label="A chi" @update:model-value="(v: string) => Object.assign(a, dallaScelta(v))" />
                        </div>
                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"
                            aria-label="Togli questa cosa" @click="togli(a)"><X class="h-4 w-4" /></button>
                    </div>
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Con la data diventa un compito dell'Action inbox; senza, resta nella pagina dell'assemblea e nella
                            dashboard.</p>
                        <button v-if="puoGestire" type="button" :class="PULSANTE" data-testid="un-altra-cosa" @click="aggiungi(p.delibera_id)">
                            <Plus class="h-3.5 w-3.5" aria-hidden="true" /> Un'altra cosa da fare</button>
                    </div>
                </div>
                <button v-else-if="puoGestire && (p.esito === 'approvata' || p.esito === 'approvata_con_modifiche')" type="button" :class="PULSANTE"
                    data-testid="un-altra-cosa" @click="aggiungi(p.delibera_id)"><Plus class="h-3.5 w-3.5" aria-hidden="true" /> Un'altra cosa da fare</button>

                <!-- Avvisa i condòmini: il pulsante con la sua casella -->
                <label v-if="righeDelPunto(p).avviso" class="flex w-full cursor-pointer items-start gap-2.5 rounded-lg border px-3 py-2.5 sm:w-auto"
                    :class="eSpuntata(righeDelPunto(p).avviso!, spunte) ? 'border-slate-900 bg-white dark:border-white dark:bg-slate-900' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-950'"
                    data-testid="avvisa-i-condomini" :data-acceso="eSpuntata(righeDelPunto(p).avviso!, spunte) ? 'si' : 'no'">
                    <Checkbox :model-value="eSpuntata(righeDelPunto(p).avviso!, spunte)" class="mt-0.5 rounded" :disabled="!puoGestire || righeDelPunto(p).avviso!.applicata"
                        aria-label="Avvisa i condòmini" @update:model-value="(v) => spunta(righeDelPunto(p).avviso!, v)" />
                    <span class="min-w-0">
                        <span class="flex items-center gap-1.5 text-sm font-medium text-slate-900 dark:text-white"><Megaphone class="h-3.5 w-3.5" aria-hidden="true" /> Avvisa i condòmini</span>
                        <span class="block text-xs text-slate-600 dark:text-slate-400">In bacheca, anche per gli inquilini: si prepara già scritta, e la rileggi prima di
                            pubblicarla.</span>
                    </span>
                </label>
            </li>
        </ol>

        <footer class="flex flex-col gap-2.5 border-t border-slate-100 bg-slate-50/60 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800 dark:bg-slate-900/30">
            <p class="text-xs text-slate-600 dark:text-slate-300" data-testid="conto">{{ fraseDelConto(conto) }}</p>
            <button v-if="puoGestire" type="button" :class="[PRINCIPALE, 'w-full sm:w-auto']" :disabled="inCorso || punti.length === 0" data-testid="conferma" @click="conferma">
                <Loader2 v-if="inCorso" class="h-4 w-4 animate-spin" aria-hidden="true" />Conferma</button>
        </footer>
        <p v-if="errore" class="px-4 pb-3 text-sm text-red-600 dark:text-red-500" data-testid="errore">{{ errore }}</p>

        <FinestraDelMemo v-if="memo" v-model:open="finestraDelMemo" :condominio-id="condominioId" origine-tipo="punto" :origine-id="memo.dati.memo?.origine_id ?? null"
            :testo="memo.dati.memo?.testo || null" @salvato="memoSalvato" />
    </section>
</template>
