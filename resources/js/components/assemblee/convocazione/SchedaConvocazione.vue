<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il passo «Convocazione» della pagina dell'assemblea (scena 2 della bozza approvata; in testa «Entro quando spedire», scena 3 delle scene della tappa 2,
 * decisione 565). Si convoca chi risulta dall'anagrafe il giorno dell'invio, ognuno con un mezzo dell'art. 66: la tabella delle persone con il mezzo, lo
 * stato e la prova; «Stampa le lettere» e «Manda le PEC»; sotto, la nota del venduto dopo l'invio, l'avviso in bacheca, la prova della convocazione e
 * l'email semplice che non vale. Tutto arriva già scritto dal server nella prop `convocazione`; vuota, la scheda dice che non è ancora spedita.
 *
 * I gesti che cambiano qualcosa passano da Inertia e ricaricano il passo, la strada, il diario e l'assemblea (la prima spedizione registrata la fa
 * «convocata»); i PDF (le lettere, la prova, il foglio) arrivano da un POST, perché la stampa scrive il registro degli invii, e si aprono in una scheda nuova.
 * La stampa non spedisce: la frase lo dice, e se dopo la stampa cambia l'avviso (le date, il luogo, i punti, la persona) la nota «Hai stampato le lettere il …: ristampale» lo ricorda.
 * In basso, senza spazi vuoti (la bozza approvata del passo): gli avvisi delle unità vendute sopra, a tutta larghezza, e sotto «Avviso anche in bacheca» e
 * «La prova della convocazione» in due card affiancate e alte uguali; al telefono tutto in colonna.
 * «Vedi chi» di «Che cosa manca» (`#convocati`) apre la tabella con tutte le righe. Mentre le lettere si stampano o le PEC partono il pulsante è spento
 * e dice che sta lavorando: un secondo clic non manda due volte (e il server tiene un invio per volta per assemblea). La ricevuta PEC trascinata sulla riga
 * dell'invio la legge il server (decisione 577).
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Link, router } from '@inertiajs/vue3';
import { AlertTriangle, FileText, Info, Loader2, Mail, Printer } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { apriPdfInUnaScheda, fraseDaErroreConFile } from '@/lib/gestionale/incassi/ricevute';
import AnnullaERiconvoca from './AnnullaERiconvoca.vue';
import AvvisoInBacheca from './AvvisoInBacheca.vue';
import EntroQuandoSpedire from './EntroQuandoSpedire.vue';
import FoglioDelleConsegne from './FoglioDelleConsegne.vue';
import RegistraLaConsegna from './RegistraLaConsegna.vue';
import RegistraLaSpedizione from './RegistraLaSpedizione.vue';
import TabellaDeiConvocati from './TabellaDeiConvocati.vue';
import { RIGHE_DI_SERIE, SOLO_CONVOCAZIONE, classiBadge, classiNota, conInvio, numeroDelPulsante, percheDeiPulsanti, righeScelte, righeVisibili, testoDelFile, titoloDelPulsante } from './aiuti';
import type { MezzoConvocazione, RigaDelConvocato, SchedaDellaConvocazione } from './tipi';

// `apriRiconvoca`: un contatore che la pagina alza da «Annulla e riconvoca» del menu «Azioni» (decisione 652 (2)); ogni volta la finestra si apre.
const props = defineProps<{ convocazione: SchedaDellaConvocazione | Record<string, never>; apriRiconvoca?: number }>();

const c = computed<SchedaDellaConvocazione | null>(() => ('rotte' in props.convocazione ? (props.convocazione as SchedaDellaConvocazione) : null));

const tutte = ref(false);
const stampando = ref(false);
const inviando = ref(false);
const righe = computed(() => c.value?.convocati.righe ?? []);
const mostrate = computed(() => righeVisibili(righe.value, tutte.value));
const altre = computed(() => Math.max(0, righe.value.length - RIGHE_DI_SERIE));

// Ogni perché col suo pulsante (decisione 647): «Lettere e PEC: …», «Solo le PEC: …».
const perche = computed(() => (c.value?.gestisce ? percheDeiPulsanti(c.value) : []));

// Chi si stampa o si manda (decisione 647): di serie tutti spuntati; `nonScelte` tiene le chiavi di chi è stato tolto, così una persona nuova entra spuntata.
const nonScelte = ref<Set<string>>(new Set());
const perLeLettere = computed(() => righeScelte(righe.value, 'lettere', nonScelte.value));
const perLePec = computed(() => righeScelte(righe.value, 'pec', nonScelte.value));
const numeroLettere = computed(() => numeroDelPulsante(perLeLettere.value.scelte.length, perLeLettere.value.tutte.length));
const numeroPec = computed(() => numeroDelPulsante(perLePec.value.scelte.length, perLePec.value.tutte.length));
function spunta(riga: RigaDelConvocato, valore: boolean) {
    const nuove = new Set(nonScelte.value);
    if (valore) nuove.delete(riga.chiave);
    else nuove.add(riga.chiave);
    nonScelte.value = nuove;
}
const tuttiSpuntati = () => { nonScelte.value = new Set(); };
const nessunoSpuntato = () => { nonScelte.value = new Set(righe.value.filter((r) => r.per.lettere || r.per.pec).map((r) => r.chiave)); };
/** Gli id delle persone spuntate per un pulsante: solo se non sono tutte (senza id il server fa come oggi, con tutte quelle da spedire). */
function idDelleScelte(scelte: { scelte: RigaDelConvocato[]; tutte: RigaDelConvocato[] }): number[] | null {
    if (scelte.scelte.length === scelte.tutte.length) return null;

    return scelte.scelte.map((r) => r.anagrafica_id).filter((id): id is number => id !== null);
}

/**
 * Il mezzo di una persona per questa assemblea, con «Ricordalo nella sua scheda» se lo si vuole anche nella scheda. Si ricaricano solo i dati del passo, e la
 * scheda tiene il suo stato (le spunte, la tabella aperta): senza `preserveState` la visita rimontava la scheda e sembrava ricaricare la pagina.
 */
function scegliIlMezzo(riga: RigaDelConvocato, mezzo: MezzoConvocazione | null, ricorda: boolean) {
    if (!c.value?.rotte.mezzo || riga.anagrafica_id === null) return;
    problema.value = null;
    router.patch(c.value.rotte.mezzo, { anagrafica_id: riga.anagrafica_id, mezzo, ricorda }, {
        preserveScroll: true,
        preserveState: true,
        only: SOLO_CONVOCAZIONE,
        onError: (e) => { problema.value = Object.values(e as Record<string, string>).join(' '); },
    });
}

const daSpedire = computed(() => righe.value.filter((r) => r.azioni.spedizione));

// «Vedi chi» di «Che cosa manca» porta qui: chi manca può stare dopo l'ottava riga, e la tabella le mostra tutte.
function versoIConvocati() {
    if (window.location.hash === '#convocati') tutte.value = true;
}
onMounted(() => { versoIConvocati(); window.addEventListener('hashchange', versoIConvocati); });
onBeforeUnmount(() => window.removeEventListener('hashchange', versoIConvocati));
const aMano = computed(() => righe.value.filter((r) => r.mezzo === 'a_mano' && (r.azioni.consegna || r.stato === 'da_spedire' || r.stato === 'da_consegnare')));

const esito = ref<string | null>(null);
const problema = ref<string | null>(null);

// Le finestre.
const spedizioneAperta = ref(false);
const spedizioneScelte = ref<number[] | null>(null);
const consegnaAperta = ref(false);
const rigaDellaConsegna = ref<RigaDelConvocato | null>(null);
const foglioAperto = ref(false);
const riconvocaAperta = ref(false);
// «Annulla e riconvoca» dal menu «Azioni»: si apre la finestra; se non si può, il perché.
watch(() => props.apriRiconvoca ?? 0, (n) => {
    if (n <= 0 || !c.value) return;
    if (c.value.riconvoca.si_puo) riconvocaAperta.value = true;
    else if (c.value.riconvoca.perche) problema.value = c.value.riconvoca.perche;
}, { immediate: true });
const campoProva = ref<HTMLInputElement | null>(null);
const rigaDellaProva = ref<RigaDelConvocato | null>(null);

function pdf(url: string, corpo: Record<string, unknown>, riserva: string, dopo: string | null, ricarica: boolean, allaFine: () => void = () => {}) {
    esito.value = null;
    problema.value = null;
    // La scheda si apre nel clic, prima della richiesta: niente `await` prima di questa chiamata.
    apriPdfInUnaScheda(() => axios.post(url, corpo, { responseType: 'blob' }), riserva).then((arrivato) => {
        esito.value = testoDelFile(arrivato, dopo);
        if (ricarica) router.reload({ only: SOLO_CONVOCAZIONE });
    }).catch(async (e) => {
        problema.value = await fraseDaErroreConFile(e, 'Il PDF non è arrivato. Riprova.');
    }).finally(allaFine);
}

function stampaLeLettere() {
    if (!c.value?.lettere.attivo || stampando.value || perLeLettere.value.scelte.length === 0) return;
    stampando.value = true;
    const ids = idDelleScelte(perLeLettere.value);
    pdf(c.value.rotte.lettere, ids === null ? { formato: 'pdf' } : { formato: 'pdf', anagrafiche: ids }, 'convocazioni.pdf', 'Le lettere sono stampate, non ancora spedite: registra la spedizione quando le spedisci. '
        + 'L\'assemblea diventa «convocata» alla prima spedizione registrata.', true,
        () => { stampando.value = false; });
}
function stampaLaProva() {
    if (!c.value?.prova.attivo) return;
    pdf(c.value.rotte.prova, {}, 'prova-della-convocazione.pdf', null, false);
}
// Con l'ultima prova della casella PEC non andata il pulsante resta, e prima di mandare la finestra lo dice: decide l'amministratore (decisione 572).
const confermaDellePec = ref(false);
function mandaLePec() {
    if (!c.value?.pec.attivo || inviando.value || perLePec.value.scelte.length === 0) return;
    if (c.value.pec.avviso) {
        confermaDellePec.value = true;
        return;
    }
    inviaLePec();
}
function inviaLePec() {
    confermaDellePec.value = false;
    if (!c.value?.pec.attivo || inviando.value || perLePec.value.scelte.length === 0) return;
    esito.value = null;
    problema.value = null;
    inviando.value = true;
    const ids = idDelleScelte(perLePec.value);
    router.post(c.value.rotte.pec, ids === null ? {} : { anagrafiche: ids }, {
        preserveScroll: true,
        preserveState: true,
        only: SOLO_CONVOCAZIONE,
        onError: (e) => { problema.value = Object.values(e as Record<string, string>).join(' '); },
        onFinish: () => { inviando.value = false; },
    });
}

const ritiro = ref(false);
function azione(tipo: 'spedizione' | 'consegna' | 'correggi' | 'prova' | 'ritiro' | 'ricevuta', riga: RigaDelConvocato) {
    if (tipo === 'spedizione') {
        spedizioneScelte.value = riga.invio_id === null ? null : [riga.invio_id];
        spedizioneAperta.value = true;
    } else if (tipo === 'consegna' || tipo === 'correggi' || tipo === 'ritiro' || tipo === 'ricevuta') {
        // «Carica la ricevuta PEC» apre la stessa finestra, con la ricevuta in testa.
        ritiro.value = tipo === 'ritiro';
        rigaDellaConsegna.value = riga;
        consegnaAperta.value = true;
    } else {
        rigaDellaProva.value = riga;
        campoProva.value?.click();
    }
}
/** «Sposta le date»: con l'assemblea già convocata è la riconvocazione che il programma fa; altrimenti il passo «Date e ordine del giorno». */
function spostaLeDate() {
    spedizioneAperta.value = false;
    if (c.value?.riconvoca.si_puo) riconvocaAperta.value = true;
    else if (c.value?.rotte.date) router.visit(c.value.rotte.date);
}
function apriLaSpedizione() {
    spedizioneScelte.value = null;
    spedizioneAperta.value = true;
}
/** La ricevuta PEC trascinata sulla riga: il server ne legge il giorno e l'ora; se non è quella giusta, la frase lo dice. */
function ricevutaSullaRiga(riga: RigaDelConvocato, file: File) {
    if (!riga.invio_id || !c.value) return;
    esito.value = null;
    problema.value = null;
    router.post(conInvio(c.value.rotte.ricevuta, riga.invio_id), { ricevuta: file }, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        only: SOLO_CONVOCAZIONE,
        onError: (errori) => { problema.value = Object.values(errori as Record<string, string>).join(' '); },
    });
}
function provaScelta(e: Event) {
    const campo = e.target as HTMLInputElement;
    const file = campo.files?.[0];
    const riga = rigaDellaProva.value;
    if (!file || !riga?.invio_id || !c.value) return;
    router.post(conInvio(c.value.rotte.prova_invio, riga.invio_id), { prova: file }, {
        forceFormData: true,
        preserveScroll: true,
        preserveState: true,
        only: SOLO_CONVOCAZIONE,
        onError: (errori) => { problema.value = Object.values(errori as Record<string, string>).join(' '); },
        onFinish: () => { campo.value = ''; },
    });
}
</script>

<template>
    <div class="space-y-3.5" data-testid="scheda-convocazione">
        <div v-if="!c" class="rounded-xl border border-dashed border-slate-200 px-5 py-6 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-300" data-testid="convocazione-vuota">
            <p class="font-medium text-slate-900 dark:text-slate-100">Non ancora spedita</p>
            <p class="mt-1 text-slate-500 dark:text-slate-400">La convocazione si prepara quando l'assemblea ha le date della prima e della seconda convocazione e il suo ordine del giorno.</p>
        </div>

        <template v-else>
            <EntroQuandoSpedire v-if="c.entro" :entro="c.entro" :rotta="c.rotte.raccomandate_entro" :rotta-affissione="c.rotte.affissione" @sposta-le-date="spostaLeDate" />

            <div id="convocati" class="scroll-mt-4 rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                <div class="flex flex-col gap-3 border-b border-slate-100 px-4 py-3.5 dark:border-slate-800 sm:flex-row sm:items-start sm:justify-between sm:px-5">
                    <div class="min-w-0 flex-1">
                        <h3 class="flex flex-wrap items-center gap-2 text-base font-semibold text-slate-900 dark:text-slate-100" data-testid="titolo-convocati">
                            Convocati <span :class="classiBadge('sl')" class="tabular-nums">{{ c.convocati.totale }}</span>
                        </h3>
                        <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400" data-testid="descrizione-convocati">{{ c.convocati.descrizione }}</p>
                    </div>
                    <div v-if="c.gestisce" class="flex shrink-0 flex-col gap-1 sm:items-end" data-testid="pulsanti-convocati">
                        <div class="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" class="h-8 flex-1 sm:flex-none" :disabled="!c.lettere.attivo || stampando || perLeLettere.scelte.length === 0" :title="titoloDelPulsante(perche, 'lettere') ?? c.lettere.perche ?? (c.lettere.attivo && perLeLettere.scelte.length === 0 ? 'Nessuna persona spuntata per le lettere.' : undefined)"
                                data-testid="stampa-le-lettere" @click="stampaLeLettere">
                                <Loader2 v-if="stampando" class="h-3.5 w-3.5 animate-spin" /><Printer v-else class="h-3.5 w-3.5" />
                                {{ stampando ? 'Sto stampando le lettere…' : `Stampa le lettere (${numeroLettere})` }}
                            </Button>
                            <Button size="sm" class="h-8 flex-1 sm:flex-none" :disabled="!c.pec.attivo || inviando || perLePec.scelte.length === 0" :title="titoloDelPulsante(perche, 'pec') ?? c.pec.perche ?? (c.pec.attivo && perLePec.scelte.length === 0 ? 'Nessuna persona spuntata per le PEC.' : undefined)"
                                data-testid="manda-le-pec" @click="mandaLePec">
                                <Loader2 v-if="inviando" class="h-3.5 w-3.5 animate-spin" /><Mail v-else class="h-3.5 w-3.5" />
                                {{ inviando ? 'Sto mandando le PEC…' : `Manda le PEC (${numeroPec})` }}
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Il perché dei pulsanti spenti: una riga sotto l'intestazione, larga quanto la scheda, così la descrizione dei convocati non si stringe. -->
                <div v-if="perche.length" class="space-y-1 border-b border-slate-100 px-4 py-2.5 dark:border-slate-800 sm:px-5" data-testid="perche-spedizione">
                    <p v-for="r in perche" :key="r.chiave" class="flex items-start gap-2 text-xs text-slate-600 dark:text-slate-300" :data-testid="`perche-${r.chiave}`">
                        <Info class="mt-px h-3.5 w-3.5 shrink-0" />
                        <span>
                            <b class="font-semibold">{{ r.chi }}:</b>{{ ' ' }}<Link v-if="r.dove" :href="r.dove" class="underline underline-offset-2 hover:text-slate-800 dark:hover:text-slate-200">{{ r.testo }}</Link>
                            <template v-else>{{ r.testo }}</template>
                        </span>
                    </p>
                </div>

                <div v-if="c.lettere.ristampa" class="border-b border-slate-100 px-4 py-2.5 dark:border-slate-800 sm:px-5">
                    <div :class="classiNota('am')" data-testid="ristampa"><AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" /><p>{{ c.lettere.ristampa }}</p></div>
                </div>
                <div v-if="c.pec_non_comunicate" class="border-b border-slate-100 px-4 py-2.5 dark:border-slate-800 sm:px-5" data-testid="pec-non-comunicate">
                    <div :class="classiNota('am')"><Info class="mt-0.5 h-4 w-4 shrink-0" /><p>{{ c.pec_non_comunicate }}</p></div>
                </div>
                <div v-if="c.videoconferenza" class="border-b border-slate-100 px-4 py-2.5 dark:border-slate-800 sm:px-5" data-testid="consenso-videoconferenza">
                    <div :class="classiNota('am')"><Info class="mt-0.5 h-4 w-4 shrink-0" /><p>{{ c.videoconferenza }}</p></div>
                </div>

                <TabellaDeiConvocati :righe="mostrate" :tutte="righe" :gestisce="c.gestisce" :non-scelte="nonScelte" @azione="azione" @ricevuta="ricevutaSullaRiga"
                    @spunta="spunta" @tutti="tuttiSpuntati" @nessuno="nessunoSpuntato" @mezzo="scegliIlMezzo" />

                <div class="flex flex-col gap-2 border-t border-slate-100 px-4 py-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                    <span v-if="altre > 0">
                        <template v-if="!tutte">E altri {{ altre }} · </template>
                        <button type="button" class="underline underline-offset-2 hover:text-slate-800 dark:hover:text-slate-200" @click="tutte = !tutte">
                            {{ tutte ? `Mostra solo i primi ${RIGHE_DI_SERIE}` : 'Mostra tutti' }}
                        </button>
                    </span>
                    <span v-else />
                    <div v-if="c.gestisce && (daSpedire.length || aMano.length)" class="flex flex-wrap gap-2">
                        <Button v-if="daSpedire.length" variant="outline" size="sm" class="h-8 text-xs" @click="apriLaSpedizione">Registra la spedizione ({{ daSpedire.length }})</Button>
                        <Button v-if="aMano.length" variant="outline" size="sm" class="h-8 text-xs" @click="foglioAperto = true">Foglio delle consegne a mano ({{ c.foglio.quante }})</Button>
                    </div>
                </div>
                <p class="border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400 sm:px-5" data-testid="email-semplice">{{ c.email_semplice }}</p>
            </div>

            <div v-if="c.tardiva" class="flex flex-col gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200 sm:flex-row sm:items-center sm:justify-between"
                data-testid="spedizione-tardiva-scheda">
                <p>{{ c.tardiva.frase }}</p>
                <Button v-if="c.gestisce" variant="outline" size="sm" class="h-8 shrink-0 text-xs" data-testid="sposta-le-date-scheda" @click="spostaLeDate">Sposta le date</Button>
            </div>

            <p v-if="esito" class="text-xs text-slate-600 dark:text-slate-300" data-testid="esito-scheda">{{ esito }}</p>
            <p v-if="problema" class="text-xs text-rose-700 dark:text-rose-300" data-testid="problema-scheda">{{ problema }}</p>

            <!-- In basso, senza spazi vuoti: gli avvisi delle unità vendute sopra, a tutta larghezza; sotto due card affiancate e alte uguali. -->
            <div v-if="c.venduti.length" class="flex flex-col gap-3.5" data-testid="venduti">
                <div v-for="v in c.venduti" :key="v.titolo" :class="classiNota('or')" data-testid="venduto">
                    <AlertTriangle class="mt-0.5 h-[18px] w-[18px] shrink-0" />
                    <div class="min-w-0"><b>{{ v.titolo }}</b><p class="mt-0.5 text-xs">{{ v.frase }}</p></div>
                </div>
            </div>
            <div class="grid items-stretch gap-3.5 lg:grid-cols-2" data-testid="due-card">
                <AvvisoInBacheca :bacheca="c.bacheca" :rotta="c.rotte.bacheca" :gestisce="c.gestisce" />
                <div class="flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950" data-testid="card-prova">
                    <!-- Solo la prova (decisione 652 (2)): l'email semplice sta sotto la tabella dei convocati, «Annulla e riconvoca» nel menu «Azioni». -->
                    <div class="flex flex-col gap-2 px-4 py-3.5 sm:flex-row sm:flex-nowrap sm:items-start sm:justify-between sm:px-5" data-testid="testa-prova">
                        <div class="min-w-0">
                            <h3 class="flex items-center gap-2 text-base font-semibold text-slate-900 dark:text-slate-100"><FileText class="h-4 w-4 shrink-0" /> La prova della convocazione</h3>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Chi ha ricevuto, quando e come; chi manca.</p>
                            <p v-if="!c.prova.attivo && c.prova.perche" class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ c.prova.perche }}</p>
                        </div>
                        <Button variant="outline" size="sm" class="h-7 w-full shrink-0 gap-1 text-xs sm:w-auto" :disabled="!c.prova.attivo" @click="stampaLaProva">
                            <FileText class="h-3.5 w-3.5" /> Stampa la prova
                        </Button>
                    </div>
                </div>
            </div>

            <input ref="campoProva" type="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.eml,.p7m" aria-label="La prova della consegna" @change="provaScelta" />

            <RegistraLaSpedizione v-model:open="spedizioneAperta" :righe="daSpedire" :scelte="spedizioneScelte" :rotta="c.rotte.spedizione" :tardive="c.tardiva_oggi ?? null"
                @sposta-le-date="spostaLeDate" />
            <RegistraLaConsegna v-model:open="consegnaAperta" :riga="rigaDellaConsegna" :rotta="c.rotte.consegna" :rotta-ricevuta="c.rotte.ricevuta" :ritiro="ritiro" />
            <FoglioDelleConsegne v-model:open="foglioAperto" :righe="aMano" :rotte="{ foglio: c.rotte.foglio, foglio_firmato: c.rotte.foglio_firmato }" />
            <AnnullaERiconvoca v-model:open="riconvocaAperta" :riconvoca="c.riconvoca" :rotta="c.rotte.riconvoca" />
            <ConfirmDialog v-model="confermaDellePec" title="L'ultima prova della casella PEC non è andata" :description="c.pec.avviso ?? ''"
                :confirm-text="`Manda le PEC (${numeroPec})`" cancel-text="Annulla" variant="warning" @confirm="inviaLePec" />
        </template>
    </div>
</template>
