<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * «Il verbale» (le bozze approvate dell'editor e del verbale, decisioni 513–515 e 519; la bozza delle scene del verbale, decisione 605):
 *
 * - **Durante l'assemblea**: una card per punto con la riga della sala e «La discussione», che si scrive qui e sotto il voto nella sala; chi scrive un
 *   punto lo scrive da solo, gli altri lo leggono e lo chiedono; «Aggiungi una dichiarazione» con il gesto della sala.
 * - **A fine assemblea**: le tre card (il verbale, la copia firmata, il giorno entro cui registrare) e «Genera il verbale dal modello»; poi l'editor del
 *   verbale intero con le parti «Dati della sala» segnate, l'avviso delle cifre, chi altro lo ha aperto, «Le versioni», la scritta del salvataggio, «Stato del
 *   verbale» con la bozza da firmare e la registrazione.
 * - **Registrato**: il testo firmato in sola lettura, la copia, «Sostituisci la copia», «Annulla», la pagina per il libro, e «La trasmissione e i trenta
 *   giorni».
 *
 * Il testo si salva da solo e **non sta mai solo in memoria** (`useVerbaleSenzaRete`, decisioni 514 (5), 519, 590 (T5)): ogni lettera va sul disco del
 * browser al più ogni 300 millisecondi, parte pochi secondi dopo la pausa e almeno ogni 30 secondi, con un gesto numerato per parte; senza rete resta sul
 * disco e parte quando la rete torna, anche dopo uno spegnimento (il lavoro ritrovato); chiudendo la pagina con modifiche in attesa il browser lo chiede prima.
 * In alto il punto d'innesto della rete (`data-innesto="rete"`): la scritta del salvataggio con «Rientra», il lavoro ritrovato, i testi non accettati, l'avviso
 * del browser che non tiene i dati. Finché c'è una scelta fra due versioni la parte è ferma. «Stampa la bozza», «Genera» e «Registra» partono solo con il testo
 * arrivato tutto. Ogni altra cosa è una visita di Inertia che rilegge solo la prop `verbale`.
 */
import { computed, ref, toRef, watch } from 'vue';
import axios from 'axios';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ChevronDown, Compass, DoorOpen, FileText, ListChecks } from 'lucide-vue-next';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import VerbaleGuide from '@/components/guides/VerbaleGuide.vue';
import AvvisoDelleCifre from '@/components/assemblee/verbale/AvvisoDelleCifre.vue';
import AnnullaLaRegistrazione from '@/components/assemblee/verbale/AnnullaLaRegistrazione.vue';
import ChiScrive from '@/components/assemblee/verbale/ChiScrive.vue';
import DichiarazioneDalVerbale from '@/components/assemblee/verbale/DichiarazioneDalVerbale.vue';
import DiscussioneDelPunto from '@/components/assemblee/verbale/DiscussioneDelPunto.vue';
import EditorDelVerbale from '@/components/assemblee/verbale/EditorDelVerbale.vue';
import GeneraDalModello from '@/components/assemblee/verbale/GeneraDalModello.vue';
import RegistraIlVerbale from '@/components/assemblee/verbale/RegistraIlVerbale.vue';
import SostituisciLaCopia from '@/components/assemblee/verbale/SostituisciLaCopia.vue';
import StatoDelVerbale from '@/components/assemblee/verbale/StatoDelVerbale.vue';
import TestoDelVerbale from '@/components/assemblee/verbale/TestoDelVerbale.vue';
import TrasmissioneDelVerbale from '@/components/assemblee/verbale/TrasmissioneDelVerbale.vue';
import VersioniDelVerbale from '@/components/assemblee/verbale/VersioniDelVerbale.vue';
import VersioniInAttesa from '@/components/assemblee/verbale/VersioniInAttesa.vue';
import { CARD, PRINCIPALE, PULSANTE, percorso } from '@/components/assemblee/verbale/finestra';
import { cardDelVerbale } from '@/lib/assemblee/cardDellaGuida';
import { BADGE, CLASSI_DEL_BADGE, coloreDellEsito } from '@/lib/assemblee/assemblee';
import AvvisoDelBrowserDelVerbale from '@/components/assemblee/verbale/rete/AvvisoDelBrowserDelVerbale.vue';
import DueVersioniDelVerbale from '@/components/assemblee/verbale/rete/DueVersioniDelVerbale.vue';
import LavoroRitrovatoDelVerbale from '@/components/assemblee/verbale/rete/LavoroRitrovatoDelVerbale.vue';
import StatoDelSalvataggio from '@/components/assemblee/verbale/rete/StatoDelSalvataggio.vue';
import { discussioniScritte, entroIl, giorno, ora, rimettiLaCifra } from '@/lib/assemblee/verbale/pagina';
import { testo as testoSemplice, type NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import { apriPdfInUnaScheda, fraseDaErroreConFile } from '@/lib/gestionale/incassi/ricevute';
// Oggi a Roma, dove il programma conta i giorni, non sul computer: su un altro fuso il giorno del computer può essere ancora ieri (rilievo C54).
import { giornoDiRoma } from '@/components/assemblee/sala/sala';
import { useVerbaleSenzaRete } from '@/lib/senzaRete/useVerbaleSenzaRete';
import { nomeDellaParte } from '@/lib/senzaRete/verbale';
import { usePermission } from '@/composables/permissions';
import type { CifraCheNonTorna, PaginaDelVerbale, ParteDelVerbale, StatoDiChiScrive } from '@/types/verbale';
import type { Building } from '@/types/buildings';

const props = defineProps<{ condominio: Building; condomini: Building[]; verbale: PaginaDelVerbale; ritorno: { url: string; testo: string } }>();
const page = usePage<{ auth?: { user?: { id?: number; name?: string } } }>();
const { generatePath, generateRoute } = usePermission();

// ─── Il testo che non si perde: il disco del browser, la coda, la lettura periodica (decisioni 514 (5), 519, 590 (T5)) ───────────────────────────────────
const parteAttiva = ref<ParteDelVerbale>('intero');
const ultimoScritto = ref(0);
const rete = useVerbaleSenzaRete(toRef(props, 'verbale'), { nomeUtente: page.props.auth?.user?.name ?? '', utente: page.props.auth?.user?.id ?? null,
    fuso: 'Europe/Rome', indirizzoDiAccesso: typeof route === 'function' ? route('login') : undefined,
    // La lettura periodica dice chi scrive la parte aperta; «scrivo» solo se qui si è scritto negli ultimi 90 secondi.
    parametriDellaLettura: () => ({ parte: parteAttiva.value, ...(Date.now() - ultimoScritto.value < 90000 ? { scrivo: '1' } : {}) }) });

// ─── Ciò che la pagina mostra: la prop, rinnovata dalle visite e dalla lettura periodica (la più recente, anche quella tenuta sul disco) ─────────────────
const pagina = computed<PaginaDelVerbale>(() => rete.pagina.value);
const v = computed(() => pagina.value.verbale);
const rotte = computed(() => pagina.value.rotte);
const puo = computed(() => pagina.value.puo);
const sedutaId = computed(() => v.value?.seduta_id ?? pagina.value.seduta_id);
const riunione = computed(() => pagina.value.riunioni.find((r) => r.seduta_id === sedutaId.value) ?? null);
const inCorso = computed(() => !!riunione.value && riunione.value.aperta_il !== null && riunione.value.chiusa_il === null);
const punti = computed(() => v.value?.punti ?? pagina.value.punti);
const conTesto = computed(() => !!v.value && v.value.stato !== 'in_sala');
const ricarica = () => router.reload({ only: ['verbale', 'flash'] });

// ─── Lo scrivere: il disco e la coda; la scritta; chi scrive ──────────────────────────────────────────────────────────────────────────────────────────
/** La scritta accanto agli strumenti dell'editor, dalla tenuta senza rete. */
const scritta = computed(() => ({ testo: rete.scritta.value.titolo, dettaglio: rete.scritta.value.dettaglio ?? '' }));
function scrivi(parte: ParteDelVerbale, documento: NodoDelVerbale) {
    parteAttiva.value = parte;
    ultimoScritto.value = Date.now();
    // Una parte ferma (due versioni da scegliere, il verbale registrato) non si scrive: niente si sovrascrive.
    if (!rete.scrivi(parte, documento)) return;
    if (parte === 'intero') testoIntero.value = documento;
}
/** Il dispositivo di chi usa il browser, lo stesso della sala (dal disco del browser: c'è appena la tenuta è aperta). */
const dispositivo = computed(() => rete.dispositivo.value);
const chiScrive = ref<Record<string, StatoDiChiScrive>>({});
watch(() => v.value?.chi_scrive, (c) => { if (c) chiScrive.value = { ...chiScrive.value, ...c }; });
const statoDi = (parte: string) => chiScrive.value[parte] ?? v.value?.chi_scrive?.[parte] ?? null;
const leggeSolo = (parte: string) => !puo.value.gestire || rete.bloccata(parte) || (!!statoDi(parte)?.scrive && !statoDi(parte)!.scrive!.sono_io);
async function chiedi(parte: ParteDelVerbale) {
    if (!rotte.value.chiedi) return;
    const r = await axios.post(rotte.value.chiedi, { parte, dispositivo: dispositivo.value });
    chiScrive.value = { ...chiScrive.value, [parte]: r.data };
}
async function rispondi(parte: ParteDelVerbale, risposta: 'lascia' | 'continuo') {
    if (!rotte.value.rispondi) return;
    if (risposta === 'lascia') await rete.salvaOra();
    const r = await axios.post(rotte.value.rispondi, { parte, dispositivo: dispositivo.value, risposta });
    chiScrive.value = { ...chiScrive.value, [parte]: r.data };
}
/** Il testo di una parte come lo vede chi scrive: quello scritto qui vale più di quello del server. */
const documentoDi = (parte: string) => rete.vista.value[parte]?.documento ?? null;
/** I testi non accettati dal server: restano su questo computer, e si rimettono nell'editor. */
const nonAccettati = computed(() => rete.daRivedere.value.map((d) => ({ ...d, nome: nomeDellaParte(d.parte, punti.value) })));
async function rimettiNellEditor(gesto: string) {
    const d = rete.daRivedere.value.find((x) => x.gesto === gesto);
    if (await rete.riprendi(gesto) && d?.parte === 'intero' && d.documento) testoIntero.value = d.documento;
}

// ─── Il verbale intero ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
const testoIntero = ref<NodoDelVerbale | null>(documentoDi('intero'));
// Il testo del server (un ripristino, «Aggiorna le parti segnate», una scelta, la generazione) entra nell'editor appena qui non c'è niente da mandare: mai
// dopo dieci secondi, che lascerebbero la lettera dopo disfare il ripristino.
watch(() => rete.vista.value.intero, (p) => { if (p && !p.qui) testoIntero.value = p.documento; });
const versione = computed(() => v.value?.versioni.filter((x) => !x.in_attesa).reduce((n, x) => Math.max(n, x.numero), 0) || null);
function rimetti(c: CifraCheNonTorna) {
    if (!testoIntero.value) return;
    scrivi('intero', rimettiLaCifra(testoIntero.value, c));
}
const dueVersioniAperte = computed(() => (v.value?.in_attesa ?? []).some((x) => x.attesa === 'due_versioni' || x.attesa === 'dopo_la_generazione')
    || rete.dueVersioni.value.length > 0);
/**
 * Prima di un gesto che legge il testo dal server («Stampa la bozza», «Genera», «Registra»): il testo scritto qui deve essere arrivato tutto. Senza rete, con
 * la sessione scaduta o con due versioni il gesto aspetta, e la pagina lo dice.
 */
async function primaArrivaTutto(gesto: string): Promise<boolean> {
    if (await rete.salvaOra()) return true;
    esito.value = `Il testo scritto qui non è ancora tutto sul server (${rete.scritta.value.titolo.replace(/\.$/, '')}): ${gesto} aspetta che arrivi.`;
    return false;
}

// ─── Le azioni ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
const finestraGenera = ref(false);
const finestraRegistra = ref(false);
const finestraSostituisci = ref(false);
const finestraAnnulla = ref(false);
const confermaStampa = ref(false);
const dichiarazioneSu = ref<{ punto_id: number; numero: number } | null>(null);
const finestraDichiarazione = computed({ get: () => dichiarazioneSu.value !== null, set: (a: boolean) => { if (!a) dichiarazioneSu.value = null; } });
const inStampa = ref(false);
const esito = ref('');
async function stampaLaBozza(ancheConLeCifre = false) {
    if (!rotte.value.bozza || !v.value) return;
    if (dueVersioniAperte.value) { esito.value = 'Prima scegli fra le versioni in attesa: la bozza da firmare aspetta.'; return; }
    if ((v.value.cifre.length || v.value.sala_cambiata) && !ancheConLeCifre) { confermaStampa.value = true; return; }
    inStampa.value = true;
    esito.value = '';
    // Si stampa solo il testo arrivato tutto: la bozza firmata è quello che si registra.
    if (!(await primaArrivaTutto('la bozza'))) { inStampa.value = false; return; }
    apriPdfInUnaScheda(() => axios.post(rotte.value.bozza!, { anche_con_le_cifre: ancheConLeCifre }, { responseType: 'blob' }), 'verbale-bozza.pdf')
        .then(() => ricarica())
        .catch(async (e) => { esito.value = await fraseDaErroreConFile(e, 'La bozza non si è stampata. Riprova.'); })
        .finally(() => { inStampa.value = false; });
}
function paginaPerIlLibro() {
    if (rotte.value.libro_pagina) globalThis.open?.(rotte.value.libro_pagina, '_blank', 'noopener');
}
function incollata() {
    if (rotte.value.libro) router.post(rotte.value.libro, { giorno: giornoDiRoma() }, { preserveScroll: true, only: ['verbale', 'flash'] });
}
function aggiornaLeParti() {
    if (rotte.value.aggiorna_sala) router.post(rotte.value.aggiorna_sala, {}, { preserveScroll: true });
}
async function apriGenera() {
    if (await primaArrivaTutto('la generazione')) finestraGenera.value = true;
}
async function apriRegistra() {
    if (await primaArrivaTutto('la registrazione')) finestraRegistra.value = true;
}
/** Il browser che non tiene il testo (la finestra privata): la finestra si chiude quando si sceglie «Apri lo stesso». */
const avvisoDelBrowser = ref(true);
const versioniInAttesa = ref<{ unisci: (id: number) => void } | null>(null);

// ─── Le parole della testa ────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
const mostraGuida = ref(false);
const titolo = computed(() => (riunione.value ? `Il verbale dell'assemblea del ${giorno(riunione.value.giorno)}` : 'Il verbale dell\'assemblea'));
const sottotitolo = computed(() => {
    const r = riunione.value;
    if (!r) return pagina.value.assemblea.titolo;
    return `${pagina.value.assemblea.titolo} · ${r.convocazione === 'prima' ? 'prima' : 'seconda'} convocazione${r.chiusa_il ? ` · ${r.esito === 'sospesa' ? 'sospesa' : 'chiusa'} alle ${ora(r.chiusa_il)}`
        : r.aperta_il ? ' · in corso' : ''}`;
});
const badgeDellaTesta = computed(() => {
    if (!v.value || v.value.stato === 'in_sala') return { testo: inCorso.value ? 'In corso' : 'Da scrivere', colore: CLASSI_DEL_BADGE[inCorso.value ? 'blu' : 'ambra'] };
    if (v.value.stato === 'registrato') return { testo: `Registrato · n. ${v.value.numero}`, colore: CLASSI_DEL_BADGE.verde };
    if (v.value.stato === 'annullato') return { testo: 'Annullato', colore: CLASSI_DEL_BADGE.rosso };
    if (v.value.stampata?.cambiata) return { testo: 'Cambiato dopo la stampa', colore: CLASSI_DEL_BADGE.rosso };
    return { testo: v.value.stampata ? 'Da firmare' : 'Bozza', colore: CLASSI_DEL_BADGE.ambra };
});
const riunioni = computed(() => pagina.value.riunioni.map((r) => ({ id: r.verbale?.id ?? null, seduta: r.seduta_id,
    etichetta: `${giorno(r.giorno)}${r.verbale?.numero ? ` · n. ${r.verbale.numero}` : ''}${r.esito === 'sospesa' ? ' · sospesa' : ''}` })));
function cambiaRiunione(seduta: number) {
    const r = riunioni.value.find((x) => x.seduta === seduta);
    router.get(rotte.value.pagina, r?.id ? { verbale: r.id } : {}, { preserveScroll: true });
}
/** Il pulsante «Vai a» ha la forma e il carattere di «Guida» e di «Azioni»: alto 32 pixel, la scritta a 12. */
const pulsanteDellaTesta = 'inline-flex h-8 items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 shadow-sm transition-colors hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800';
/** Una voce del menu «Vai a»: l'icona, il nome e la riga grigia sotto, come nel menu «Azioni» della pagina dell'assemblea. */
const voce = 'flex cursor-pointer items-start gap-2 px-2 py-1.5 text-[13px]';
const iconaDellaVoce = 'mt-0.5 h-3.5 w-3.5 shrink-0';
const rigaDellaSala = computed(() => (riunione.value?.chiusa_il ? 'In sola lettura' : inCorso.value ? 'La sala in corso' : 'Non ancora aperta'));
const discussioni = computed(() => discussioniScritte(v.value?.parti ?? {}, punti.value.length));
const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Assemblee', href: route(generateRoute('gestionale.assemblee.index'), { condominio: props.condominio.id }) },
    { title: pagina.value.assemblea.nome, href: rotte.value.assemblea },
    { title: 'Il verbale' },
]);
</script>

<template>
    <Head title="Il verbale" />
    <GestionaleLayout>
        <div class="space-y-4 px-4 py-6 sm:px-6 sm:py-8">
            <PageHeaderGuide :page-title="titolo" :page-subtitle="sottotitolo" :guides="cardDelVerbale()" :breadcrumbs="(breadcrumbs as any)" :video-url="null" :condominio="condominio"
                :condomini="condomini" condominio-fisso :back-url="ritorno.url" :back-text="ritorno.testo" has-text-guide text-guide-title="Guida" una-fila @open-text-guide="mostraGuida = true">
                <template #accanto-al-titolo>
                    <span :class="[BADGE, badgeDellaTesta.colore, 'py-0.5']" data-testid="stato-della-testa">{{ badgeDellaTesta.testo }}</span>
                </template>
                <template #actions>
                    <!-- «Vai a»: la sala, il registro e l'assemblea in un menu solo, perché la testa resti su una riga (decisione 656); sotto ogni voce la riga grigia. -->
                    <DropdownMenu :modal="false">
                        <DropdownMenuTrigger as-child>
                            <button type="button" :class="pulsanteDellaTesta" data-testid="vai-a">
                                <Compass class="h-3.5 w-3.5 text-slate-500 dark:text-slate-400" aria-hidden="true" /> Vai a <ChevronDown class="h-3.5 w-3.5 text-slate-400 dark:text-slate-500" aria-hidden="true" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-64">
                            <DropdownMenuItem as-child :class="voce">
                                <Link :href="rotte.sala" data-testid="vai-alla-sala">
                                    <DoorOpen :class="iconaDellaVoce" aria-hidden="true" />
                                    <span data-testid="voce-vai-a"><span class="block">La sala</span><span class="block text-xs text-slate-500 dark:text-slate-400">{{ rigaDellaSala }}</span></span>
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem as-child :class="voce">
                                <Link :href="rotte.registro" data-testid="vai-al-registro">
                                    <FileText :class="iconaDellaVoce" aria-hidden="true" />
                                    <span data-testid="voce-vai-a"><span class="block">Il registro dei verbali</span><span class="block text-xs text-slate-500 dark:text-slate-400">I verbali di {{ condominio.nome }}</span></span>
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuItem as-child :class="voce">
                                <Link :href="rotte.assemblea" data-testid="vai-all-assemblea">
                                    <ListChecks :class="iconaDellaVoce" aria-hidden="true" />
                                    <span data-testid="voce-vai-a"><span class="block">L'assemblea</span><span class="block text-xs text-slate-500 dark:text-slate-400">I sei passi</span></span>
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </PageHeaderGuide>

            <!-- La rete: la scritta del salvataggio, il lavoro ritrovato, le due versioni, i testi non accettati, il browser che non tiene il testo -->
            <div class="space-y-2" data-innesto="rete" data-testid="innesto-rete">
                <StatoDelSalvataggio v-if="puo.gestire" :scritta="rete.scritta.value" @rientra="rete.rientra()" />
                <LavoroRitrovatoDelVerbale :ritrovato="rete.ritrovato.value" :punti="punti" @chiudi="rete.chiudiIlRitrovato()" />
                <DueVersioniDelVerbale v-for="d in rete.dueVersioni.value" :key="d.versione_id" :due="d" :puo-scegliere="puo.gestire"
                    @scegli="(scelta, id) => v && router.post(percorso.scegli(rotte.pagina, v.id, id), { scelta }, { preserveScroll: true })"
                    @uniscile="(due) => versioniInAttesa?.unisci(due.versione_id)">
                    <template #testo="{ documento }"><TestoDelVerbale :documento="documento" /></template>
                </DueVersioniDelVerbale>
                <div v-for="d in nonAccettati" :key="d.gesto" class="space-y-1.5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
                    data-testid="testo-non-accettato">
                    <p class="font-medium">{{ d.nome.charAt(0).toUpperCase() + d.nome.slice(1) }}: il server non l'ha accettato. Il testo resta su questo computer.</p>
                    <p v-if="d.messaggio" class="text-xs">{{ d.messaggio }}</p>
                    <p class="break-words text-xs opacity-80">«{{ testoSemplice(d.documento ?? { type: 'doc' }).slice(0, 160) }}»</p>
                    <button v-if="puo.gestire" type="button" :class="PULSANTE" data-testid="rimetti-nell-editor" @click="rimettiNellEditor(d.gesto)">Rimettilo nell'editor</button>
                </div>
                <AvvisoDelBrowserDelVerbale v-if="puo.gestire" v-model:open="avvisoDelBrowser" :tenuta="rete.tenuta.value" @apri-lo-stesso="avvisoDelBrowser = false" />
            </div>

            <div v-if="riunioni.length > 1" class="max-w-sm" data-testid="le-riunioni">
                <p class="mb-1 text-xs font-semibold text-slate-500 dark:text-slate-400">Una riunione, un verbale</p>
                <v-select :model-value="sedutaId" :options="riunioni" label="etichetta" :reduce="(r: { seduta: number }) => r.seduta" :clearable="false" :searchable="false"
                    @update:model-value="cambiaRiunione" />
            </div>
            <p v-if="esito" class="text-sm text-red-600 dark:text-red-500" data-testid="esito-della-pagina">{{ esito }}</p>

            <!-- Le versioni in attesa: la discussione arrivata dopo la generazione, il testo arrivato dopo la registrazione; le due versioni le dice la rete -->
            <VersioniInAttesa v-if="v" ref="versioniInAttesa" :verbale="v" :pagina="rotte.pagina" :puo-gestire="puo.gestire" senza-due-versioni />

            <!-- Nessuna riunione aperta -->
            <section v-if="!riunione" :class="[CARD, 'px-4 py-6 text-center text-sm text-slate-600 dark:text-slate-400']" data-testid="senza-riunione">
                Il verbale si scrive dall'apertura della sala: la discussione di ogni punto, poi il verbale intero.
                <Link :href="rotte.sala" class="font-medium underline underline-offset-2">Vai alla sala</Link>
            </section>

            <!-- Durante l'assemblea, e a fine assemblea prima di generare -->
            <template v-else-if="!conTesto">
                <div v-if="!inCorso" class="space-y-3" data-testid="da-generare">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div :class="[CARD, 'px-4 py-3']"><p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Il verbale</p>
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">Da generare</p><p class="text-xs text-slate-600 dark:text-slate-400">{{ discussioni }}</p></div>
                        <div :class="[CARD, 'px-4 py-3']"><p class="text-xs font-semibold text-slate-500 dark:text-slate-400">La copia firmata</p>
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">Non ancora</p><p class="text-xs text-slate-600 dark:text-slate-400">dopo la bozza, firmata da presidente e segretario</p></div>
                        <div :class="[CARD, 'px-4 py-3']"><p class="text-xs font-semibold text-slate-500 dark:text-slate-400">Da registrare</p>
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">entro il {{ entroIl(riunione.chiusa_il) }}</p><p class="text-xs text-slate-600 dark:text-slate-400">il compito è nell'Action inbox</p></div>
                    </div>
                    <div :class="[CARD, 'space-y-2 px-4 py-5 text-center']">
                        <p class="text-[15px] font-semibold text-slate-900 dark:text-white">Il verbale non c'è ancora</p>
                        <p class="text-xs text-slate-600 dark:text-slate-400">Il programma lo compone dal modello che scegli, con i dati della sala e le discussioni dei punti. Poi lo correggi qui,
                            come testo: le frasi con i dati della sala restano segnate.</p>
                        <button v-if="puo.gestire" type="button" :class="[PRINCIPALE, 'w-full sm:w-auto']" data-testid="genera-il-verbale" @click="apriGenera">Genera il verbale dal modello</button>
                    </div>
                </div>

                <ul class="space-y-1 text-xs text-slate-600 dark:text-slate-400" data-testid="regole-della-discussione">
                    <li>Ogni punto è una parte a sé: due persone scrivono due punti diversi nello stesso momento.</li>
                    <li>Lo stesso punto lo scrive una persona alla volta: l'altra lo vede cambiare, e può chiederlo.</li>
                    <li>Voti, nomi e millesimi li mette il programma; si salva pochi secondi dopo che smetti di scrivere, e almeno ogni 30 secondi.</li>
                </ul>
                <section v-for="p in punti" :key="p.punto_id" :class="[CARD, 'space-y-2.5 px-4 py-4']" data-testid="punto-del-verbale" :data-punto="p.numero">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <h2 class="min-w-0 break-words text-[15px] font-semibold text-slate-900 dark:text-white">{{ p.numero }}. {{ p.testo }}</h2>
                        <span v-if="p.votazioni.at(-1)?.esito" :class="[BADGE, CLASSI_DEL_BADGE[coloreDellEsito(p.votazioni.at(-1)!.esito!)]]">{{ p.votazioni.at(-1)!.riga.split(' · ')[0] }}</span>
                        <span v-else-if="inCorso" :class="[BADGE, CLASSI_DEL_BADGE.neutro]">Da votare</span>
                    </div>
                    <p v-for="x in p.votazioni" :key="x.id" class="text-xs text-slate-600 dark:text-slate-400">{{ x.riga }}</p>
                    <ChiScrive :stato="statoDi(p.parte)" :numero-del-punto="p.numero" :puo-gestire="puo.gestire" @chiedi="chiedi(p.parte)"
                        @rispondi="(r) => rispondi(p.parte, r)" />
                    <DiscussioneDelPunto :numero="p.numero" :documento="documentoDi(p.parte)" :solo-lettura="leggeSolo(p.parte)"
                        :scritta="parteAttiva === p.parte ? scritta : null" :con-aiuto="false" @scrivi="(d) => scrivi(p.parte, d)" />
                    <p v-for="d in p.dichiarazioni" :key="d.id" class="text-xs text-slate-700 dark:text-slate-300"><b class="font-semibold">Dichiarazione a verbale · {{ d.nome }}:</b> «{{ d.testo }}»</p>
                    <button v-if="inCorso && puo.gestire" type="button" :class="PULSANTE" data-testid="aggiungi-dichiarazione"
                        @click="dichiarazioneSu = { punto_id: p.punto_id, numero: p.numero }">Aggiungi una dichiarazione</button>
                </section>
            </template>

            <!-- Il verbale intero: la bozza, il registrato, l'annullato -->
            <template v-else-if="v">
                <AvvisoDelleCifre v-if="v.stato === 'bozza'" :cifre="v.cifre" :sala-cambiata="v.sala_cambiata" :a-mano="v.a_mano ?? []" :puo-gestire="puo.gestire" :url-della-sala="rotte.sala"
                    @rimetti="rimetti" @aggiorna="aggiornaLeParti" />
                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <div class="min-w-0 space-y-2">
                        <ChiScrive v-if="v.stato === 'bozza'" :stato="statoDi('intero')" :puo-gestire="puo.gestire" @chiedi="chiedi('intero')" @rispondi="(r) => rispondi('intero', r)" />
                        <EditorDelVerbale v-if="v.stato === 'bozza' && !leggeSolo('intero')" :model-value="testoIntero" :scritta="scritta" alto etichetta="Il testo del verbale"
                            @update:model-value="(d) => scrivi('intero', d)" />
                        <TestoDelVerbale v-else :documento="testoIntero" :class="[CARD, 'px-4 py-3']" />
                    </div>
                    <div class="space-y-3">
                        <StatoDelVerbale :verbale="v" :puo="puo" :versione="versione" :in-stampa="inStampa || dueVersioniAperte" @stampa="stampaLaBozza()" @rigenera="apriGenera"
                            @registra="apriRegistra" @sostituisci="finestraSostituisci = true" @annulla="finestraAnnulla = true" @libro="paginaPerIlLibro"
                            @incollata="incollata" />
                        <VersioniDelVerbale :versioni="v.versioni" :verbale-id="v.id" :pagina="rotte.pagina" :puo-ripristinare="v.stato === 'bozza' && puo.gestire" />
                    </div>
                </div>
                <TrasmissioneDelVerbale v-if="v.stato === 'registrato' && v.trasmissione" :verbale="v" :rotte="rotte" :puo-gestire="puo.gestire" />
            </template>
        </div>

        <GeneraDalModello v-if="sedutaId" v-model:open="finestraGenera" :pagina="pagina" :seduta-id="sedutaId" :rigenera="v?.stato === 'bozza'" :discussioni="discussioni"
            :versione="versione" />
        <RegistraIlVerbale v-if="v && rotte.registra" v-model:open="finestraRegistra" :verbale="v" :rotta="rotte.registra" :giorno-della-seduta="riunione?.giorno ?? ''" />
        <SostituisciLaCopia v-if="v && rotte.copia" v-model:open="finestraSostituisci" :verbale="v" :rotta="rotte.copia" />
        <AnnullaLaRegistrazione v-if="v && rotte.annulla" v-model:open="finestraAnnulla" :verbale="v" :rotta="rotte.annulla" />
        <DichiarazioneDalVerbale v-model:open="finestraDichiarazione" :punto="dichiarazioneSu" :seduta-id="sedutaId" :rotta="`${rotte.sala}/gesti`" :dispositivo="dispositivo"
            @fatta="ricarica" />
        <ConfirmDialog v-model="confermaStampa" :title="v?.cifre.length ? 'Una cifra non torna con i voti registrati' : 'I dati della sala sono cambiati'"
            confirm-text="Stampa lo stesso"
            :description="v?.cifre.length ? `${v?.cifre[0]?.frase ?? ''} Puoi rimettere la cifra dei voti, o correggere il voto in sala; oppure stampare lo stesso.`
                : `I dati della sala sono cambiati${v?.sala_cambiata?.alle ? ` alle ${v.sala_cambiata.alle}` : ''} dopo la generazione: «Aggiorna le parti segnate» rifà le parti con i voti. Oppure stampa lo stesso.`"
            @confirm="stampaLaBozza(true)" />
        <VerbaleGuide v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
