<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La tabella delle delibere del passo 6 (scena 1 della bozza della tappa, scena 2 della bozza approvata del seguito): punto, delibera, esito, seguito e le
 * azioni. Il seguito dice «Eseguita il 16/12 · Piano «Ordinario 2027»», o «Da eseguire · 2 di 3» con le cose una per riga («Avviso in bacheca: pubblicato il
 * 17/12/2026», «Firma con Serramenti Marino: il 08/01/2027, segnata da Marco Galli», «Il piano straordinario: entro il 15/01/2027, Andrea Costa»); sotto, il
 * fondo speciale quando i piani non arrivano ai lavori, i segni, e le righe tolte dal riepilogo con «Applica» (o «Apri il piano» per ciò che aspetta la
 * scheda giusta).
 *
 * L'azione principale è quella della prima cosa aperta: «Crea il piano straordinario», «Registra la dispensa», «Avvisa i condòmini»; sulla cosa «Correggi il
 * preventivo e fermalo di nuovo» è «Ferma il preventivo» (il punto alla rotta del fascicolo); sulla risposta a una respinta «Riproponi in assemblea» e
 * «Chiudi con il motivo». Il menu «⋯» ha «Applica», «Segna eseguita», «Non serve più», «Un'altra cosa da fare» e i segni, ognuno con la riga che dice a che
 * cosa serve. Al telefono le delibere sono righe, quelle da eseguire in cima, le ultime dietro «Mostra le altre».
 */
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { AlertTriangle, Check, Clock, MoreHorizontal } from 'lucide-vue-next';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import SuggerimentoDellaI from '@/components/assemblee/convocazione/SuggerimentoDellaI.vue';
import FinestraDellaCosa from '@/components/assemblee/seguito/FinestraDellaCosa.vue';
import FinestraDelSegno from '@/components/assemblee/seguito/FinestraDelSegno.vue';
import { usePermission } from '@/composables/permissions';
import { BADGE, CLASSI_DEL_BADGE, coloreDellEsito } from '@/lib/assemblee/assemblee';
import {
    dettaglioDelSeguito, nomeDelPulsante, ordinePerIlTelefono, rigaBreveDellaCosa, rigaDellaCosa, SCHEDE_CON_LA_PAGINA, vociDelMenu, type ModoDelSegno, type ModoDellaCosa, type VoceDelMenu,
} from '@/lib/assemblee/seguito';
import { PICCOLO } from '@/components/assemblee/verbale/finestra';
import type { CosaDaFare, RigaDelPassoSei, RigaDelSeguito } from '@/types/assemblee';

const props = withDefaults(defineProps<{
    condominioId: number;
    condominioNome: string;
    assembleaId: number;
    righe: (RigaDelPassoSei & { oggetto?: 'rendiconto' | 'riparto' | null })[];
    puoGestire: boolean;
    /** La delibera o la cosa da mostrare in evidenza (l'azione del compito, della card). */
    evidenzia?: { delibera: number | null; cosa: number | null };
}>(), { evidenzia: () => ({ delibera: null, cosa: null }) });

const { generateRoute } = usePermission();
const parametri = computed(() => ({ condominio: props.condominioId, assemblea: props.assembleaId }));
const inEvidenza = (r: RigaDelPassoSei) => r.delibera_id === props.evidenzia.delibera || r.cose.some((c) => c.id === props.evidenzia.cosa);

/** La stessa griglia per la testata e per ogni riga: punto stretto, delibera, esito e azioni di larghezza fissa, seguito. Niente `auto`: le colonne non si adattano al contenuto. */
const GRIGLIA = 'grid grid-cols-[3rem_minmax(0,1.5fr)_8.5rem_minmax(0,2.2fr)_12.5rem] gap-3';
/** Una cosa ancora da fare in una riga breve («Crea il piano straordinario · a Vincenzo Rossi»); le fatte e le non più utili come prima. */
const rigaDellaCosaNellaTabella = (c: CosaDaFare) => (c.stato === 'da_fare' ? rigaBreveDellaCosa(c) : rigaDellaCosa(c));
/** Una delibera senza conseguenze nei conti: la respinta lo dice; le altre (rinviata, senza voto) vanno alla prossima assemblea se serve, e dopo il memo salvato sono nella memoria. */
const senzaSeguito = (r: RigaDelPassoSei) => (r.esito === 'respinta' ? 'Nessuna conseguenza nei conti'
    : r.memo_salvato ? 'Nella memoria del condominio' : 'Portalo alla prossima assemblea, se serve');
const coloreDelSeguito = (r: RigaDelPassoSei) => ({ eseguita: 'verde', da_eseguire: 'ambra', da_confermare: 'ambra', risposta_data: 'neutro',
    senza_seguito: 'neutro' } as const)[r.seguito.stato];
const coloreDelSegno = (tipo: string) => (tipo === 'impugnata' ? 'ambra' : 'rosso') as 'ambra' | 'rosso';
const coseDaMostrare = (r: RigaDelPassoSei) => (r.seguito.stato === 'da_eseguire' || r.verbale_annullato ? r.cose : []);
const aperte = (r: RigaDelPassoSei) => r.cose.filter((c) => c.stato === 'da_fare');
/** La prima cosa aperta: dà l'azione principale della riga. */
const principale = (r: RigaDelPassoSei): CosaDaFare | null => aperte(r)[0] ?? null;
const apriLaScheda = (tipo: string, id: number) => route(generateRoute('gestionale.schede.apri'), { condominio: props.condominioId, tipo, id });
const primoPosto = (r: RigaDelPassoSei) => r.posti.find((p) => p.esiste && SCHEDE_CON_LA_PAGINA.includes(p.oggetto_tipo)) ?? null;

// Al telefono: le prime quattro, il resto dietro «Mostra le altre».
const tutte = ref(false);
const alTelefono = computed(() => ordinePerIlTelefono(props.righe));
const visibiliAlTelefono = computed(() => (tutte.value ? alTelefono.value : alTelefono.value.slice(0, 4)));

// Le finestre
const finestraCosa = ref(false);
const modoCosa = ref<ModoDellaCosa>('eseguita');
const finestraSegno = ref(false);
const modoSegno = ref<ModoDelSegno>('impugnata');
const scelta = ref<RigaDelPassoSei | null>(null);
const cosaScelta = ref<number | null>(null);
const inCorso = ref<string | null>(null);
const errore = ref<string | null>(null);

function scegli(r: RigaDelPassoSei, voce: VoceDelMenu) {
    scelta.value = r;
    cosaScelta.value = r.cose.find((c) => c.id === props.evidenzia.cosa)?.id ?? null;
    if (voce === 'applica') {
        const daApplicare = r.righe_da_applicare.find((x) => x.si_applica && !x.applicata);
        if (daApplicare) applica(daApplicare);
        return;
    }
    if (voce === 'impugnata' || voce === 'annullata' || voce === 'revocata') {
        modoSegno.value = voce;
        finestraSegno.value = true;
        return;
    }
    modoCosa.value = voce;
    finestraCosa.value = true;
}
function chiudiConIlMotivo(r: RigaDelPassoSei) {
    scelta.value = r;
    cosaScelta.value = r.cose.find((c) => c.tipo === 'dopo_il_rifiuto' && c.stato === 'da_fare')?.id ?? null;
    modoCosa.value = 'chiudi';
    finestraCosa.value = true;
}

const visita = (chiave: string) => ({
    preserveScroll: true, only: ['seguito', 'passi', 'seguito_esiti', 'flash'],
    onStart: () => { inCorso.value = chiave; errore.value = null; },
    onError: (e: Record<string, string>) => { errore.value = Object.values(e)[0] ?? 'Non è andata. Riprova.'; },
    onFinish: () => { inCorso.value = null; },
});
/** «Applica» di una riga tolta dal riepilogo: la stessa strada della conferma, per una riga. */
function applica(riga: RigaDelSeguito) {
    router.post(route(generateRoute('gestionale.assemblee.seguito.applica'), parametri.value), { chiave: riga.chiave }, visita(riga.chiave));
}
/** «Ferma il preventivo» sulla cosa «Correggi il preventivo e fermalo di nuovo»: il punto alla rotta del fascicolo. */
function fermaIlPreventivo(r: RigaDelPassoSei) {
    if (r.punto_id === null) return;
    router.post(route(generateRoute('gestionale.assemblee.fascicolo.preventivo'), parametri.value), { punto_id: r.punto_id }, visita(`ferma:${r.delibera_id}`));
}
/** «Riproponi in assemblea» di una delibera respinta: un memo con la stessa origine. */
function riproponi(c: CosaDaFare) {
    router.post(route(generateRoute('gestionale.assemblee.seguito.cose.riproponi'), { ...parametri.value, cosa: c.id }), {}, visita(`riproponi:${c.id}`));
}
</script>

<template>
    <div class="space-y-2" data-testid="tabella-delle-delibere">
        <p v-if="errore" class="text-sm text-red-600 dark:text-red-500" data-testid="errore">{{ errore }}</p>

        <!-- Dal tablet in su: la tabella, con la stessa griglia per la testata e per ogni riga (colonne fisse: esito e azioni di larghezza fissa) -->
        <div class="hidden overflow-hidden rounded-xl border border-slate-200 bg-white sm:block dark:border-slate-800 dark:bg-slate-950" data-testid="tabella-grande">
            <div :class="[GRIGLIA, 'border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs font-semibold text-slate-500 dark:border-slate-800 dark:bg-slate-900/60 dark:text-slate-400']"
                data-testid="testata-della-tabella">
                <span>Punto</span><span>Delibera</span><span>Esito</span><span>Seguito</span><span class="sr-only">Azioni</span>
            </div>
            <div v-for="r in righe" :key="r.delibera_id" :class="[GRIGLIA, 'items-start border-b border-slate-100 px-4 py-3 last:border-b-0 dark:border-slate-800', { 'bg-sky-50/70 dark:bg-sky-950/20': inEvidenza(r) }]"
                data-testid="riga-del-passo" :data-delibera="r.delibera_id">
                <span class="text-sm font-semibold tabular-nums text-slate-500 dark:text-slate-400">{{ r.punto }}</span>
                <span class="min-w-0 break-words text-sm text-slate-900 dark:text-slate-100">{{ r.testo }}<template v-if="r.oggetto"> · il {{ r.oggetto }}</template></span>
                <span><span :class="[BADGE, CLASSI_DEL_BADGE[coloreDellEsito(r.esito)]]">{{ r.esito_parola }}</span></span>
                <div class="min-w-0 space-y-1.5 text-xs text-slate-700 dark:text-slate-300">
                    <p class="flex flex-wrap items-center gap-1.5">
                        <span v-if="r.seguito.stato !== 'senza_seguito'" :class="[BADGE, CLASSI_DEL_BADGE[coloreDelSeguito(r)]]" data-testid="seguito">{{ r.seguito.parola }}</span>
                        <span v-if="dettaglioDelSeguito(r)" class="text-slate-500 dark:text-slate-400" data-testid="dettaglio">{{ dettaglioDelSeguito(r) }}</span>
                        <span v-if="r.seguito.stato === 'senza_seguito'" class="text-slate-500 dark:text-slate-400" data-testid="senza-seguito">{{ senzaSeguito(r) }}</span>
                    </p>
                    <ul v-if="coseDaMostrare(r).length" class="space-y-0.5" data-testid="cose">
                        <li v-for="c in coseDaMostrare(r)" :key="c.id" class="flex items-start gap-1.5 break-words" :class="{ 'font-medium': c.id === evidenzia.cosa }">
                            <Check v-if="c.stato !== 'da_fare'" class="mt-0.5 h-3 w-3 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                            <Clock v-else class="mt-0.5 h-3 w-3 shrink-0 text-amber-700 dark:text-amber-400" aria-hidden="true" />
                            <span>{{ rigaDellaCosaNellaTabella(c) }}</span>
                        </li>
                    </ul>
                    <p v-if="r.fondo_speciale && !r.fondo_speciale.pari" class="flex items-start gap-1.5 text-amber-800 dark:text-amber-300" data-testid="fondo-speciale">
                        <span>{{ r.fondo_speciale.frase }}</span><SuggerimentoDellaI :testo="r.fondo_speciale.legge" />
                    </p>
                    <p v-for="s in r.segni" :key="s.tipo" class="flex flex-wrap items-center gap-1.5" data-testid="segno">
                        <span :class="[BADGE, CLASSI_DEL_BADGE[coloreDelSegno(s.tipo)]]">{{ s.tipo === 'impugnata' ? 'Impugnata' : s.tipo === 'annullata' ? 'Annullata' : 'Revocata' }}</span>
                        <span>{{ s.frase }}</span>
                    </p>
                    <p v-for="d in r.righe_da_applicare" :key="d.chiave" class="flex items-start gap-1.5 break-words text-amber-800 dark:text-amber-300" data-testid="da-applicare"
                        :data-chiave="d.chiave">
                        <AlertTriangle class="mt-0.5 h-3 w-3 shrink-0" aria-hidden="true" />
                        <span>Da applicare: {{ d.titolo }}<template v-if="d.dati.differenza"> · {{ d.dati.differenza.frase }}</template></span>
                    </p>
                </div>
                <div class="flex flex-wrap items-start justify-end gap-1.5" data-testid="azioni">
                    <template v-if="puoGestire && principale(r)">
                        <button v-if="principale(r)!.tipo === 'rifai_versione'" type="button" :class="PICCOLO" :disabled="inCorso !== null || r.punto_id === null"
                            data-testid="ferma-il-preventivo" @click="fermaIlPreventivo(r)">Ferma il preventivo</button>
                        <template v-else-if="principale(r)!.tipo === 'dopo_il_rifiuto'">
                            <button type="button" :class="PICCOLO" :disabled="inCorso !== null" data-testid="riproponi" @click="riproponi(principale(r)!)">Riproponi in assemblea</button>
                            <button type="button" :class="PICCOLO" data-testid="chiudi-con-il-motivo" @click="chiudiConIlMotivo(r)">Chiudi con il motivo</button>
                        </template>
                        <Link v-else-if="principale(r)!.azione?.url" :href="principale(r)!.azione!.url!" :class="PICCOLO" data-testid="azione">
                            {{ nomeDelPulsante(principale(r)!.azione!.testo) }}</Link>
                    </template>
                    <Link v-else-if="primoPosto(r)" :href="apriLaScheda(primoPosto(r)!.oggetto_tipo, primoPosto(r)!.oggetto_id)" :class="PICCOLO" data-testid="apri">Apri</Link>
                    <template v-for="d in r.righe_da_applicare" :key="d.chiave">
                        <Link v-if="d.dati.aspetta && d.scheda && SCHEDE_CON_LA_PAGINA.includes(d.scheda.tipo)" :href="apriLaScheda(d.scheda.tipo, d.scheda.id)" :class="PICCOLO">{{ d.scheda.tipo === 'piano_rate' ? 'Apri il piano' : 'Apri' }}</Link>
                        <button v-if="puoGestire && d.si_applica && !d.applicata" type="button" :class="PICCOLO" :disabled="inCorso !== null" data-testid="applica"
                            @click="applica(d)">Applica</button>
                    </template>
                    <DropdownMenu v-if="puoGestire && vociDelMenu(r, condominioNome).length" :modal="false">
                        <DropdownMenuTrigger as-child>
                            <button type="button" class="inline-flex h-7 w-7 items-center justify-center rounded-md border border-slate-200 text-slate-500 hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800"
                                :aria-label="`Altre azioni sul punto ${r.punto}${r.oggetto ? `, il ${r.oggetto}` : ''}`" data-testid="menu-della-riga"><MoreHorizontal class="h-4 w-4" /></button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-72">
                            <DropdownMenuItem v-for="v in vociDelMenu(r, condominioNome)" :key="v.voce" class="flex cursor-pointer flex-col items-start gap-0 px-2 py-1.5 text-[13px]"
                                :data-voce="v.voce" @select="scegli(r, v.voce)">
                                <span>{{ v.etichetta }}</span><span class="text-xs text-slate-500 dark:text-slate-400">{{ v.riga }}</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>

        <!-- Al telefono: ogni delibera è una scheda, quelle da eseguire in cima; il numero e il testo, sotto esito e seguito, sotto le azioni -->
        <ul class="space-y-2 sm:hidden" data-testid="delibere-al-telefono">
            <li v-for="r in visibiliAlTelefono" :key="r.delibera_id" class="space-y-2 rounded-xl border border-slate-200 bg-white px-3 py-3 dark:border-slate-800 dark:bg-slate-950"
                :class="{ 'ring-2 ring-sky-200 dark:ring-sky-900': inEvidenza(r) }" data-testid="riga-al-telefono" :data-delibera="r.delibera_id">
                <p class="break-words text-sm text-slate-900 dark:text-slate-100"><span class="mr-1.5 font-semibold tabular-nums text-slate-500 dark:text-slate-400">{{ r.punto }}</span>{{ r.testo }}<template v-if="r.oggetto"> · il {{ r.oggetto }}</template></p>
                <p class="flex flex-wrap items-center gap-1.5 text-xs text-slate-600 dark:text-slate-400">
                    <span :class="[BADGE, CLASSI_DEL_BADGE[coloreDellEsito(r.esito)]]">{{ r.esito_parola }}</span>
                    <span v-if="r.seguito.stato !== 'senza_seguito'" :class="[BADGE, CLASSI_DEL_BADGE[coloreDelSeguito(r)]]">{{ r.seguito.parola }}</span>
                    <span v-if="dettaglioDelSeguito(r)">{{ dettaglioDelSeguito(r) }}</span>
                    <span v-if="r.seguito.stato === 'senza_seguito'">{{ senzaSeguito(r) }}</span>
                </p>
                <ul v-if="coseDaMostrare(r).length" class="space-y-0.5 text-xs text-slate-700 dark:text-slate-300">
                    <li v-for="c in coseDaMostrare(r)" :key="c.id" class="flex items-start gap-1.5 break-words">
                        <Check v-if="c.stato !== 'da_fare'" class="mt-0.5 h-3 w-3 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                        <Clock v-else class="mt-0.5 h-3 w-3 shrink-0 text-amber-700 dark:text-amber-400" aria-hidden="true" /><span>{{ rigaDellaCosaNellaTabella(c) }}</span>
                    </li>
                </ul>
                <p v-if="r.fondo_speciale && !r.fondo_speciale.pari" class="text-xs text-amber-800 dark:text-amber-300">{{ r.fondo_speciale.frase }}</p>
                <p v-for="s in r.segni" :key="s.tipo" class="text-xs text-slate-700 dark:text-slate-300">{{ s.frase }}</p>
                <p v-for="d in r.righe_da_applicare" :key="d.chiave" class="flex items-start gap-1.5 break-words text-xs text-amber-800 dark:text-amber-300">
                    <AlertTriangle class="mt-0.5 h-3 w-3 shrink-0" aria-hidden="true" /><span>Da applicare: {{ d.titolo }}</span>
                </p>
                <div v-if="puoGestire && (principale(r) || r.righe_da_applicare.length || vociDelMenu(r, condominioNome).length)" class="flex flex-wrap gap-1.5 pt-1"
                    data-testid="azioni-al-telefono">
                    <button v-if="principale(r)?.tipo === 'rifai_versione'" type="button" :class="PICCOLO" :disabled="inCorso !== null || r.punto_id === null"
                        @click="fermaIlPreventivo(r)">Ferma il preventivo</button>
                    <button v-else-if="principale(r)?.tipo === 'dopo_il_rifiuto'" type="button" :class="PICCOLO" @click="chiudiConIlMotivo(r)">Chiudi con il motivo</button>
                    <Link v-else-if="principale(r)?.azione?.url" :href="principale(r)!.azione!.url!" :class="PICCOLO">{{ nomeDelPulsante(principale(r)!.azione!.testo) }}</Link>
                    <button v-if="r.righe_da_applicare.some((d) => d.si_applica && !d.applicata)" type="button" :class="PICCOLO" :disabled="inCorso !== null"
                        @click="applica(r.righe_da_applicare.find((d) => d.si_applica && !d.applicata)!)">Applica</button>
                    <DropdownMenu v-if="vociDelMenu(r, condominioNome).length" :modal="false">
                        <DropdownMenuTrigger as-child>
                            <button type="button" class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md border border-slate-200 text-slate-500 dark:border-slate-700"
                                :aria-label="`Altre azioni sul punto ${r.punto}${r.oggetto ? `, il ${r.oggetto}` : ''}`"><MoreHorizontal class="h-4 w-4" /></button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-64">
                            <DropdownMenuItem v-for="v in vociDelMenu(r, condominioNome)" :key="v.voce" class="flex cursor-pointer flex-col items-start gap-0 px-2 py-1.5 text-[13px]"
                                @select="scegli(r, v.voce)">
                                <span>{{ v.etichetta }}</span><span class="text-xs text-slate-500 dark:text-slate-400">{{ v.riga }}</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </li>
            <li v-if="!tutte && alTelefono.length > 4">
                <button type="button" class="w-full rounded-lg py-2 text-sm font-medium underline underline-offset-2" data-testid="mostra-le-altre" @click="tutte = true">
                    Mostra le altre {{ alTelefono.length - 4 }}</button>
            </li>
        </ul>

        <p v-if="righe.some((r) => r.segni.some((s) => s.tipo === 'impugnata'))" class="flex items-start gap-1.5 text-xs text-slate-600 dark:text-slate-400">
            <AlertTriangle class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />Un'impugnazione non ferma niente: «Impugnata» resta un segno sulla delibera, con la data e
            l'atto.</p>

        <FinestraDellaCosa v-model:open="finestraCosa" :modo="modoCosa" :condominio-id="condominioId" :assemblea-id="assembleaId" :delibera="scelta" :cosa-id="cosaScelta" />
        <FinestraDelSegno v-model:open="finestraSegno" :modo="modoSegno" :condominio-id="condominioId" :condominio-nome="condominioNome" :assemblea-id="assembleaId"
            :delibera="scelta" />
    </div>
</template>
