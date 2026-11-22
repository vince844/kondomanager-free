<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Il registro dei verbali» (scena 2 della bozza del verbale, scene 3, 4, 7 e 8 della bozza delle scene; art. 1130 n. 7 c.c.), del condominio o della
 * scala (decisione 598: le assemblee della sola scala hanno il loro registro), dall'archivio delle assemblee:
 *
 * In testa le tre card della guida (`cardDelRegistro`) e «Indietro» da dove si è venuti: l'assemblea, il suo verbale, o l'archivio (la prop `ritorno` la dà il server).
 *
 * - **Verbali**: per data, i verbali registrati con il numero, le mancate costituzioni senza numero («Annotazione»), le riunioni registrate a mano («A
 *   mano»), le righe annullate con il perché; la colonna «Nel libro di carta» con «Stampa la pagina» e «Segna incollata nel libro» (decisione 595 (2)); «Stampa il
 *   registro». La prima volta, «Da dove parte il numero?».
 * - **Nomina e revoca**, uno del condominio: la prima volta la nomina in corso; la riga proposta da una delibera di nomina, con «Correggi» in evidenza e
 *   «Conferma la riga» (decisioni 595 (7) e 615 (2)); le righe a mano; «Annulla» con il perché.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { cardDelRegistro } from '@/lib/assemblee/cardDellaGuida';
import RegistroDeiVerbaliGuide from '@/components/guides/RegistroDeiVerbaliGuide.vue';
import DaDoveParte from '@/components/assemblee/verbale/registro/DaDoveParte.vue';
import RigaDiNominaFinestra from '@/components/assemblee/verbale/registro/RigaDiNominaFinestra.vue';
import { CARD, PRINCIPALE, PULSANTE } from '@/components/assemblee/verbale/finestra';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { BADGE, CLASSI_DEL_BADGE } from '@/lib/assemblee/assemblee';
import { giorno } from '@/lib/assemblee/verbale/pagina';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import { usePermission } from '@/composables/permissions';
import type { PaginaDelRegistroDeiVerbali, RigaDelRegistroDeiVerbali, RigaDiNomina } from '@/types/verbale';
import type { Building } from '@/types/buildings';

/** `ritorno` lo costruisce il server (decisione 657): da dove si è venuti, l'assemblea o il suo verbale; senza, l'archivio delle assemblee. */
const props = defineProps<{ condominio: Building; condomini: Building[]; registro: PaginaDelRegistroDeiVerbali; ritorno: { url: string; testo: string } }>();
const { generatePath, generateRoute } = usePermission();
const r = computed(() => props.registro);
const scheda = ref<'verbali' | 'nomine'>(/[?&]scheda=nomine\b/.test(globalThis.location?.search ?? '') ? 'nomine' : 'verbali');
const mostraGuida = ref(false);

// ─── Il registro e l'anno ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
const base = computed(() => r.value.rotte.partenza.replace(/\/partenza$/, ''));
const registri = computed(() => r.value.registri.map((x) => ({ chiave: x.chiave, etichetta: x.nome })));
const anni = computed(() => [{ anno: null as number | null, etichetta: 'Tutti gli anni' }, ...r.value.anni.map((a) => ({ anno: a as number | null, etichetta: String(a) }))]);
function vai(dati: { registro?: string; anno?: number | null }) {
    const anno = 'anno' in dati ? dati.anno : r.value.anno;
    router.get(base.value, { registro: dati.registro ?? r.value.perimetro.chiave, ...(anno ? { anno } : {}) }, { preserveScroll: true, only: ['registro'] });
}

// ─── I verbali ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
const registrati = computed(() => r.value.righe.filter((x) => x.tipo !== 'annotazione' && x.stato !== 'annullato').length);
const annotazioni = computed(() => r.value.righe.filter((x) => x.tipo === 'annotazione').length);
const coloreDi = (x: RigaDelRegistroDeiVerbali) => CLASSI_DEL_BADGE[x.stato === 'annullato' ? 'rosso' : x.tipo === 'annotazione' ? 'neutro' : x.tipo === 'a_mano' ? 'viola' : 'verde'];
const paginaDelLibro = (x: RigaDelRegistroDeiVerbali) => (x.tipo === 'annotazione' ? `${base.value}/annotazione/${x.id}`
    : x.tipo === 'verbale' ? base.value.replace(/assemblee-registro$/, `assemblee/${x.assemblea.id}/il-verbale/${x.id}/libro`) : null);
/** La pagina del verbale, con «← Il registro» per tornare qui (decisione 659); solo per i verbali del programma. */
const paginaDelVerbale = (x: RigaDelRegistroDeiVerbali) => (x.tipo === 'verbale'
    ? base.value.replace(/assemblee-registro$/, `assemblee/${x.assemblea.id}/il-verbale?verbale=${x.id}&da=registro`) : null);
function incollata(x: RigaDelRegistroDeiVerbali) {
    router.post(r.value.rotte.libro, { ...(x.tipo === 'annotazione' ? { seduta_id: x.id } : { verbale_id: x.id }), giorno: giornoDiRoma() }, { preserveScroll: true, only: ['registro', 'flash'] });
}
const finestraPartenza = ref(false);

// ─── Nomina e revoca ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
const proposte = computed(() => r.value.nomine.filter((n) => n.stato === 'proposta'));
const altre = computed(() => r.value.nomine.filter((n) => n.stato !== 'proposta'));
const nominaInCorso = computed(() => [...r.value.nomine].reverse().find((n) => n.stato === 'confermata' && n.evento === 'nomina') ?? null);
const finestraNomina = ref<{ modo: 'in_corso' | 'nuova' | 'correggi'; riga: RigaDiNomina | null } | null>(null);
const aperta = computed({ get: () => finestraNomina.value !== null, set: (a: boolean) => { if (!a) finestraNomina.value = null; } });
const rottaDellaRiga = (n: RigaDiNomina, cosa = '') => `${r.value.rotte.nomine}/${n.id}${cosa}`;
function conferma(n: RigaDiNomina) {
    router.post(rottaDellaRiga(n, '/conferma'), {}, { preserveScroll: true, only: ['registro', 'flash'] });
}
const daAnnullare = ref<RigaDiNomina | null>(null);
const motivo = ref('');
const erroreMotivo = ref('');
function annulla() {
    if (!daAnnullare.value || !motivo.value.trim()) return;
    router.post(rottaDellaRiga(daAnnullare.value, '/annulla'), { motivo: motivo.value.trim() }, { preserveScroll: true,
        onSuccess: () => { daAnnullare.value = null; motivo.value = ''; }, onError: (e) => { erroreMotivo.value = (e as Record<string, string>).motivo ?? ''; } });
}
const fraseDellaProposta = (n: RigaDiNomina) => `Dal verbale ${n.verbale?.numero ? `n. ${n.verbale.numero}` : ''}${n.estremi ? `, ${n.estremi.replace(/^verbale n\. \d+, /, '')}` : ''}, approvato: «${n.evento_parola}: ${n.amministratore}, dal ${giorno(n.dal)}».`;

const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Assemblee', href: route(generateRoute('gestionale.assemblee.index'), { condominio: props.condominio.id }) },
    { title: 'Registro dei verbali' },
]);
const linguetta = (attiva: boolean) => ['h-9 rounded-md px-3 text-sm font-medium', attiva ? 'bg-white text-slate-900 shadow-sm dark:bg-slate-800 dark:text-white'
    : 'text-slate-600 dark:text-slate-400'];
</script>

<template>
    <Head title="Registro dei verbali" />
    <GestionaleLayout>
        <div class="space-y-4 px-4 py-6 sm:px-6 sm:py-8">
            <PageHeaderGuide page-title="Registro dei verbali" :page-subtitle="`${r.perimetro.nome} · i verbali delle assemblee, le mancate costituzioni e le dichiarazioni (art. 1130 n. 7 c.c.)`"
                :guides="cardDelRegistro()" :breadcrumbs="(breadcrumbs as any)" :video-url="null" :condominio="condominio" :condomini="condomini" :back-url="ritorno.url"
                :back-text="ritorno.testo" has-text-guide text-guide-title="Guida" una-fila @open-text-guide="mostraGuida = true">
                <template #accanto-al-titolo>
                    <span v-if="r.partenza === null" :class="[BADGE, CLASSI_DEL_BADGE.ambra, 'py-0.5']" data-testid="da-impostare">Da impostare</span>
                </template>
                <template #actions>
                    <a :href="r.rotte.stampa" target="_blank" rel="noopener" :class="PULSANTE" data-testid="stampa-il-registro">Stampa il registro</a>
                </template>
            </PageHeaderGuide>

            <div class="grid gap-3 sm:grid-cols-2">
                <div v-if="registri.length > 1">
                    <p class="mb-1 text-xs font-semibold text-slate-500 dark:text-slate-400">Il registro</p>
                    <v-select :model-value="r.perimetro.chiave" :options="registri" label="etichetta" :reduce="(x: { chiave: string }) => x.chiave" :clearable="false" :searchable="false"
                        data-testid="scegli-il-registro" @update:model-value="(k: string) => vai({ registro: k, anno: null })" />
                </div>
                <div v-if="r.anni.length">
                    <p class="mb-1 text-xs font-semibold text-slate-500 dark:text-slate-400">L'anno</p>
                    <v-select :model-value="r.anno" :options="anni" label="etichetta" :reduce="(x: { anno: number | null }) => x.anno" :clearable="false" :searchable="false"
                        data-testid="scegli-l-anno" @update:model-value="(a: number | null) => vai({ anno: a })" />
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div :class="[CARD, 'px-4 py-3']"><p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Verbali registrati qui</p>
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ registrati || 'Nessuno' }}</p>
                    <p class="text-xs text-slate-600 dark:text-slate-400">il prossimo prende il n. {{ r.prossimo_numero }}</p></div>
                <div :class="[CARD, 'px-4 py-3']"><p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Annotazioni</p>
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ annotazioni }}</p>
                    <p class="text-xs text-slate-600 dark:text-slate-400">le mancate costituzioni, senza numero</p></div>
                <div :class="[CARD, 'px-4 py-3']"><p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Nomina in corso</p>
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ nominaInCorso ? nominaInCorso.amministratore : 'Da indicare' }}</p>
                    <p class="text-xs text-slate-600 dark:text-slate-400">{{ nominaInCorso ? `dal ${giorno(nominaInCorso.dal)}` : 'nella scheda «Nomina e revoca»' }}</p></div>
            </div>

            <div class="flex gap-1 rounded-lg bg-slate-100 p-1 sm:w-fit dark:bg-slate-900" role="tablist" aria-label="Il registro">
                <button type="button" role="tab" :aria-selected="scheda === 'verbali'" :class="[linguetta(scheda === 'verbali'), 'flex-1 sm:flex-none']" data-testid="scheda-verbali"
                    @click="scheda = 'verbali'">Verbali</button>
                <button type="button" role="tab" :aria-selected="scheda === 'nomine'" :class="[linguetta(scheda === 'nomine'), 'flex-1 sm:flex-none']" data-testid="scheda-nomine"
                    @click="scheda = 'nomine'">Nomina e revoca</button>
            </div>

            <!-- I verbali -->
            <section v-if="scheda === 'verbali'" class="space-y-3" data-testid="i-verbali">
                <div v-if="r.partenza === null && r.puo.registrare" class="flex flex-col gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-amber-900 dark:bg-amber-950/30"
                    data-testid="da-dove-parte-il-numero">
                    <span class="text-sm text-amber-950 dark:text-amber-100"><b class="font-semibold">Da dove parte il numero?</b> Prima del primo verbale dimmi se continua un libro dei
                        verbali di carta.</span>
                    <button type="button" :class="PRINCIPALE" data-testid="indica-da-dove-parte" @click="finestraPartenza = true">Indica da dove parte</button>
                </div>
                <p v-else-if="r.partenza" class="text-xs text-slate-600 dark:text-slate-400">{{ r.partenza.scelta === 'libro' ? `Il registro continua il libro di carta, dopo il n. ${r.partenza.ultimo}${r.partenza.del
                    ? ` del ${giorno(r.partenza.del)}` : ''}.` : 'Il registro comincia dal n. 1.' }}<button v-if="r.partenza.si_cambia && r.puo.registrare" type="button"
                    class="ml-1 font-medium underline underline-offset-2" @click="finestraPartenza = true">Cambia da dove parte</button></p>

                <ul :class="[CARD, 'divide-y divide-slate-100 dark:divide-slate-800']">
                    <li class="hidden grid-cols-[4rem_6rem_minmax(0,1fr)_8rem_minmax(0,12rem)] gap-3 px-4 py-2 text-xs font-semibold text-slate-500 md:grid dark:text-slate-400">
                        <span>N.</span><span>Data</span><span>Assemblea</span><span>Stato</span><span>Nel libro di carta</span></li>
                    <li v-for="x in r.righe" :key="`${x.tipo}:${x.id}`" class="grid gap-x-3 gap-y-1 px-4 py-3 text-sm md:grid-cols-[4rem_6rem_minmax(0,1fr)_8rem_minmax(0,12rem)] md:items-start"
                        data-testid="riga-del-registro" :data-tipo="x.tipo" :data-stato="x.stato">
                        <span class="font-semibold text-slate-900 dark:text-white">{{ x.numero ?? '—' }}</span>
                        <span class="text-slate-700 dark:text-slate-300">{{ giorno(x.giorno) }}</span>
                        <span class="min-w-0 break-words text-slate-700 dark:text-slate-300"><a v-if="paginaDelVerbale(x)" :href="paginaDelVerbale(x)!"
                            class="font-medium underline underline-offset-2 hover:text-slate-900 dark:hover:text-white" data-testid="apri-il-verbale">{{ x.assemblea.parola }}</a><template v-else>{{ x.assemblea.parola }}</template><template v-if="x.assemblea.perimetro"> · {{ x.assemblea.perimetro }}</template>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ x.contenuto }}<template v-if="x.sostituisce"> · sostituisce il n. {{ x.sostituisce }}</template></span>
                            <span v-if="x.annullato" class="block text-xs text-rose-700 dark:text-rose-400">annullato il {{ giorno(x.annullato.il) }}<template v-if="x.annullato.da"> da {{ x.annullato.da }}</template>:
                                «{{ x.annullato.motivo }}»</span>
                        </span>
                        <span><span :class="[BADGE, coloreDi(x)]">{{ x.parola }}</span></span>
                        <span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-700 dark:text-slate-300">
                            <template v-if="x.tipo === 'a_mano'">—</template>
                            <template v-else-if="x.nel_libro_il">Incollata nel libro il {{ giorno(x.nel_libro_il) }}</template>
                            <template v-else>
                                <span>Da incollare</span>
                                <a v-if="paginaDelLibro(x)" :href="paginaDelLibro(x)!" target="_blank" rel="noopener" class="font-medium underline underline-offset-2" data-testid="stampa-la-pagina">Stampa la pagina</a>
                                <button v-if="r.puo.registrare" type="button" class="font-medium underline underline-offset-2" data-testid="segna-incollata" @click="incollata(x)">Segna incollata nel libro</button>
                            </template>
                        </span>
                    </li>
                    <li v-if="r.righe.length === 0" class="px-4 py-5 text-center text-sm text-slate-500 dark:text-slate-400">Il registro è vuoto: il primo verbale registrato lo apre.</li>
                </ul>
                <ul class="space-y-0.5 text-xs text-slate-600 dark:text-slate-400">
                    <li>Ogni verbale e ogni annotazione ha la sua pagina da incollare nel libro di carta; il numero della pagina del libro lo scrivi a mano.</li>
                    <li>«Segna incollata nel libro» lo fai tu: è un promemoria, non ferma niente. Nella stampa del registro la riga annullata c'è, con il perché.</li>
                </ul>
            </section>

            <!-- Nomina e revoca -->
            <section v-else class="space-y-3" data-testid="nomina-e-revoca">
                <div v-if="r.senza_la_nomina_in_corso && r.puo.registrare" class="flex flex-col gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between dark:border-amber-900 dark:bg-amber-950/30"
                    data-testid="nomina-da-indicare">
                    <span class="text-sm text-amber-950 dark:text-amber-100"><b class="font-semibold">La nomina in corso.</b> Il registro di nomina e revoca comincia qui: chi amministra oggi, e
                        da quando?</span>
                    <button type="button" :class="PRINCIPALE" data-testid="scrivi-la-nomina-in-corso" @click="finestraNomina = { modo: 'in_corso', riga: null }">Scrivi la nomina in corso</button>
                </div>
                <div v-for="n in proposte" :key="n.id" class="space-y-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100"
                    data-testid="riga-proposta">
                    <p class="font-medium">{{ fraseDellaProposta(n) }}</p>
                    <p class="text-xs">Il nome viene da quello detto in sala, o da chi amministra oggi: controllalo. Conferma la riga, o correggila prima.</p>
                    <div v-if="r.puo.registrare" class="flex flex-col gap-2 sm:flex-row">
                        <button type="button" :class="PRINCIPALE" data-testid="correggi-la-riga" @click="finestraNomina = { modo: 'correggi', riga: n }">Correggi</button>
                        <button type="button" :class="PULSANTE" data-testid="conferma-la-riga" @click="conferma(n)">Conferma la riga</button>
                    </div>
                </div>
                <ul :class="[CARD, 'divide-y divide-slate-100 dark:divide-slate-800']">
                    <li class="hidden grid-cols-[6rem_minmax(0,1fr)_minmax(0,1fr)_7rem_auto] gap-3 px-4 py-2 text-xs font-semibold text-slate-500 md:grid dark:text-slate-400">
                        <span>Dal</span><span>Che cosa</span><span>Da dove</span><span>Stato</span><span /></li>
                    <li v-for="n in [...proposte, ...altre]" :key="n.id" class="grid gap-x-3 gap-y-1 px-4 py-3 text-sm md:grid-cols-[6rem_minmax(0,1fr)_minmax(0,1fr)_7rem_auto] md:items-start"
                        data-testid="riga-di-nomina" :data-stato="n.stato">
                        <span class="text-slate-700 dark:text-slate-300">{{ giorno(n.dal) }}</span>
                        <span class="min-w-0 break-words"><b class="font-medium text-slate-900 dark:text-white">{{ n.evento_parola }}</b> · {{ n.amministratore }}</span>
                        <span class="min-w-0 break-words text-xs text-slate-600 dark:text-slate-400">{{ n.estremi ?? '—' }}<template v-if="n.annullata_motivo"> · annullata: «{{ n.annullata_motivo }}»</template></span>
                        <span><span :class="[BADGE, CLASSI_DEL_BADGE[n.stato === 'confermata' ? 'verde' : n.stato === 'proposta' ? 'blu' : 'rosso']]">{{ n.stato_parola }}</span></span>
                        <span class="flex gap-2 md:justify-end">
                            <button v-if="r.puo.registrare && n.stato !== 'annullata'" type="button" class="text-xs font-medium underline underline-offset-2" data-testid="annulla-la-riga"
                                @click="daAnnullare = n; motivo = ''; erroreMotivo = ''">Annulla</button>
                        </span>
                    </li>
                    <li v-if="r.nomine.length === 0" class="px-4 py-5 text-center text-sm text-slate-500 dark:text-slate-400">Ancora nessuna riga.</li>
                </ul>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-slate-600 dark:text-slate-400">Le date della nomina e della revoca di ciascun amministratore, e gli estremi del decreto se c'è (art. 1130 n. 7 c.c.).
                        Finché non la confermi, una riga proposta non va nella stampa.</p>
                    <button v-if="r.puo.registrare && !r.senza_la_nomina_in_corso" type="button" :class="PULSANTE" data-testid="riga-a-mano"
                        @click="finestraNomina = { modo: 'nuova', riga: null }">Una riga a mano</button>
                </div>
            </section>
        </div>

        <DaDoveParte v-model:open="finestraPartenza" :registro="r" />
        <RigaDiNominaFinestra v-model:open="aperta" :modo="finestraNomina?.modo ?? 'nuova'" :riga="finestraNomina?.riga ?? null" :amministratore="r.amministratore_di_oggi"
            :rotta="finestraNomina?.modo === 'correggi' && finestraNomina.riga ? rottaDellaRiga(finestraNomina.riga) : r.rotte.nomine" />
        <Dialog :open="daAnnullare !== null" @update:open="(a: boolean) => { if (!a) daAnnullare = null; }">
            <DialogContent class="top-[5vh] translate-y-0 sm:max-w-md" data-testid="finestra-annulla-riga">
                <DialogHeader><DialogTitle>Annulla la riga</DialogTitle>
                    <DialogDescription>{{ daAnnullare?.evento_parola }} · {{ daAnnullare?.amministratore }}, dal {{ giorno(daAnnullare?.dal) }}. Resta nel registro, annullata, con il perché.</DialogDescription></DialogHeader>
                <Textarea v-model="motivo" rows="2" class="min-h-[60px] resize-none text-sm" placeholder="Perché la annulli" data-testid="motivo-riga" />
                <InputError :message="erroreMotivo" />
                <div class="flex justify-end gap-2"><Button variant="outline" @click="daAnnullare = null">Chiudi</Button>
                    <Button variant="destructive" :disabled="!motivo.trim()" data-testid="conferma-annulla-riga" @click="annulla">Annulla la riga</Button></div>
            </DialogContent>
        </Dialog>
        <RegistroDeiVerbaliGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
