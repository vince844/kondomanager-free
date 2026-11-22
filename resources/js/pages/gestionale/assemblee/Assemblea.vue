<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La pagina dell'assemblea (§3 P2, P3, P6; la bozza approvata con la decisione 513, scena 1; la variante A della bozza dei passi guidati): «Assemblea
 * ordinaria di Via Roma 12» con le date nel sottotitolo. Nell'intestazione (la scena 3 della bozza dei documenti dei punti e del diario) il condominio,
 * «Guida», il menu «Azioni» e «Indietro» all'elenco delle assemblee. Nel menu, ognuna con la sua icona: «Diario» (il cassetto a destra, `CassettoDelDiario`),
 * «Fascicolo», «Apri la sala» (spenta fino al giorno della prima convocazione, con il perché sotto la voce; durante «Torna in sala»), poi per chi gestisce e
 * in preparazione «Tenuta su carta» ed «Elimina l'assemblea». Il diario ha già le modifiche dell'assemblea, più gli invii: «Storia» non c'è. Sotto, la
 * strada dei sei passi (`StradaDellAssemblea`, i passi li scrive il server): «Date e ordine del giorno», «Documenti», «Convocazione», «In sala»,
 * «Verbale e trasmissione», «Delibere eseguite». Si apre il passo di adesso, o quello di `scheda` (`diario` apre il passo di adesso con il cassetto).
 *
 * Il passo 6 (`PassoDelleDelibere`) ha il seguito delle delibere, chiesto quando si apre: il riepilogo «Che cosa cambia nel gestionale» finché non è
 * confermato, poi la tabella delle delibere; `delibera` e `cosa` nell'indirizzo (l'azione del compito, della card) la mostrano in evidenza. «Guida» sul
 * passo 6 apre la guida delle delibere. La cosa «Crea il piano straordinario» dell'indirizzo apre la sua finestra, montata una volta sola per la strada e
 * per la riunione su carta, e solo per chi gestisce le assemblee: chi vede soltanto non ha il pulsante dell'azione nella testa del passo 6.
 *
 * Una riunione registrata a mano, o un'assemblea tenuta su carta, mostra le sue delibere con chi le usa e il verbale (`RiunioneRegistrata`), poi lo stesso
 * seguito (la riunione su carta lo apre il giorno degli esiti, con «Verbale da caricare» in testa finché il verbale firmato non c'è), e il «Diario» sotto.
 *
 * I passi «Convocazione», «Documenti» e «In sala» stanno nei loro punti d'innesto (`data-innesto`): `SchedaConvocazione`, `SchedaFascicolo` e
 * `PassoInSala` (le deleghe ricevute, il foglio della sala, la sala dal giorno della prima convocazione), con le prop che il
 * server manda quando il passo si apre (una visita parziale, come il «Diario»); finché non arrivano, il passo dice «Un momento…». «Fascicolo» nel menu
 * «Azioni» compone il fascicolo in un PDF (lo stesso di «Componi il fascicolo»). «Guida» apre la guida del passo aperto: per la convocazione e i
 * documenti la loro, per gli altri quella dell'assemblea. Le proposte arrivano differite; i gesti sull'ordine del giorno rileggono solo ciò che cambiano (e
 * il passo «Convocazione» o «Documenti» già letto: dopo una stampa le lettere da ristampare si vedono subito), e nel passo 1 i punti proposti che aspettano
 * entrano nella voce «L'ordine del giorno» di «che cosa manca» («6 punti · 3 proposti aspettano · Vedili»), che non blocca. Le voci del passo 1 portano
 * dentro la pagina: le date (`#date-dell-assemblea`), l'ordine del giorno (`#ordine-del-giorno`), i proposti (`#proposti`), la tabella del valore
 * (`#tabella-del-valore`).
 *
 * Le card del passo «In sala» aprono le finestre della pagina (la bozza delle card dei passi e del cassetto delle deleghe): «Vedi le deleghe» il cassetto
 * delle deleghe a destra (`CassettoDelleDeleghe`, lo stesso elenco della sezione del passo), «Segna una delega ricevuta» (senza deleghe) la finestra di
 * sempre; chi non gestisce le assemblee non ha quel pulsante. Dopo una stampa dalla card (il foglio della sala in una scheda nuova) la pagina, quando si
 * torna, rilegge i passi e la sala: il foglio ristampato torna verde.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { ArrowRight, ChevronDown, DoorOpen, FileSignature, FileText, History, RotateCcw, Trash2, Zap } from 'lucide-vue-next';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DateDellAssemblea from '@/components/assemblee/DateDellAssemblea.vue';
import OrdineDelGiorno from '@/components/assemblee/OrdineDelGiorno.vue';
import TabellaDiProprieta from '@/components/assemblee/TabellaDiProprieta.vue';
import DiarioDellAssemblea from '@/components/assemblee/DiarioDellAssemblea.vue';
import CassettoDelDiario from '@/components/assemblee/CassettoDelDiario.vue';
import TenutaFuoriDalProgramma from '@/components/assemblee/TenutaFuoriDalProgramma.vue';
import RiunioneRegistrata from '@/components/assemblee/RiunioneRegistrata.vue';
import StradaDellAssemblea from '@/components/assemblee/StradaDellAssemblea.vue';
import AssembleaGuide from '@/components/guides/AssembleaGuide.vue';
import ConvocazioneGuide from '@/components/guides/ConvocazioneGuide.vue';
import SchedaConvocazione from '@/components/assemblee/convocazione/SchedaConvocazione.vue';
import SchedaFascicolo from '@/components/assemblee/fascicolo/SchedaFascicolo.vue';
import PassoInSala from '@/components/assemblee/sala/PassoInSala.vue';
import CassettoDelleDeleghe from '@/components/assemblee/sala/CassettoDelleDeleghe.vue';
import SegnaUnaDelegaRicevuta from '@/components/assemblee/sala/SegnaUnaDelegaRicevuta.vue';
import PassoDelVerbale from '@/components/assemblee/verbale/PassoDelVerbale.vue';
import PassoDelleDelibere from '@/components/assemblee/seguito/PassoDelleDelibere.vue';
import CreaIlPianoStraordinario from '@/components/assemblee/seguito/CreaIlPianoStraordinario.vue';
import DelibereGuide from '@/components/guides/DelibereGuide.vue';
import type { VerbaleDelPasso } from '@/types/verbale';
import { SOLO_FASCICOLO, testoDelFile } from '@/components/assemblee/convocazione/aiuti';
import type { SchedaDelFascicolo, SchedaDellaConvocazione } from '@/components/assemblee/convocazione/tipi';
import { apriPdfInUnaScheda, fraseDaErroreConFile } from '@/lib/gestionale/incassi/ricevute';
import { usePermission } from '@/composables/permissions';
import { BADGE, CLASSI_DEL_BADGE, coloreDelloStato } from '@/lib/assemblee/assemblee';
import { controllaLeDate } from '@/lib/assemblee/date';
import {
    datiDelModulo, moduloDa, seduteDaControllare, type AssembleaDellaPagina, type ChiaveDelPasso, type DiarioDellaPagina, type FinestraDellaVoce,
    type ModuloDelleDate, type PassoDellaStrada, type PuntoDellaPagina, type ScelteDelleAssemblee, type VoceCheCosaManca,
} from '@/lib/assemblee/pagine';
import type { Building } from '@/types/buildings';
import type { EsitoDellaRiga, GruppoDiProposte, PaginaDelSeguito, SalaDellAssemblea, TerminiDellaPrima } from '@/types/assemblee';


const props = defineProps<{
    condominio: Building;
    condomini: Building[];
    esercizio: any;
    assemblea: AssembleaDellaPagina;
    ordine: PuntoDellaPagina[];
    passi: PassoDellaStrada[];
    /** Il passo da aprire (`?scheda=`); senza, quello di adesso. `diario` apre il passo di adesso con il cassetto del diario. */
    scheda: ChiaveDelPasso | 'diario' | null;
    punto: number | null;
    memo: { id: number; testo: string } | null;
    /** I giorni da cui la prima convocazione ha i cinque giorni (art. 66 c. 3), contando da oggi: la nota sotto le date. */
    termini_della_prima?: TerminiDellaPrima | null;
    scelte: ScelteDelleAssemblee;
    riunioni_a_mano: { id: number; nome: string }[];
    proposte?: GruppoDiProposte[];
    convocazione?: SchedaDellaConvocazione | Record<string, never>;
    fascicolo?: SchedaDelFascicolo | Record<string, never>;
    diario?: DiarioDellaPagina;
    /** Il passo «In sala»: la stessa lettura della pagina della sala, quando il passo si apre. */
    sala?: SalaDellAssemblea;
    /** Il passo «Verbale e trasmissione»: le riunioni con il loro verbale, quando il passo si apre. */
    verbale?: VerbaleDelPasso;
    /** Il passo «Delibere eseguite»: il seguito delle delibere, quando il passo si apre (e nella riunione su carta). */
    seguito?: PaginaDelSeguito;
    /** L'esito per riga dell'ultima conferma, o di «Applica». */
    seguito_esiti?: Record<string, EsitoDellaRiga> | null;
    /** La delibera o la cosa da fare da mostrare nel passo 6 (`?delibera=`, `?cosa=`). */
    delibera?: number | null;
    cosa?: number | null;
    puo: { gestire: boolean; registrare_il_verbale: boolean };
}>();

const { generatePath, generateRoute } = usePermission();
const mostraGuida = ref(false);
/** Il passo di adesso; senza, l'ultimo fatto. */
const diAdesso = (): ChiaveDelPasso => props.passi.find((p) => p.stato === 'adesso')?.chiave ?? [...props.passi].reverse().find((p) => p.stato === 'fatto')?.chiave ?? 'date';
const aperto = ref<ChiaveDelPasso>(props.scheda && props.scheda !== 'diario' ? props.scheda : diAdesso());
/** Il diario nel cassetto a destra: si apre da «Diario» nel menu «Azioni» (o con `?scheda=diario`), e chiede il diario al server quando si apre. */
const cassettoDelDiario = ref(props.scheda === 'diario');
/** «Annulla e riconvoca» dal menu «Azioni» (decisione 652 (2)): apre il passo «Convocazione», e la scheda della convocazione apre la finestra. */
const chiediRiconvoca = ref(0);
function riconvoca() {
    apri('convocazione');
    chiediRiconvoca.value += 1;
}
// La guida del passo aperto: la convocazione e i documenti hanno la loro, le delibere la loro.
const guidaDellaConvocazione = computed(() => aperto.value === 'convocazione' || aperto.value === 'documenti');
const guidaDelleDelibere = computed(() => aperto.value === 'delibere');
const registrata = computed(() => props.assemblea.stato === 'registrata_a_mano');
const parametri = computed(() => ({ condominio: props.condominio.id, assemblea: props.assemblea.id }));

/** «Indietro» all'elenco delle assemblee del condominio, come in «Prepara l'assemblea». */
const elenco = computed(() => route(generateRoute('gestionale.assemblee.index'), { condominio: props.condominio.id }));
const breadcrumbs = computed(() => [
    { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
    { title: 'Assemblee', href: elenco.value },
    { title: props.assemblea.nome },
]);

/**
 * «Documenti» si fa con «Avanti: Convocazione»: sul passo di adesso il pulsante del piede chiede al server di segnare i documenti visti, e la pagina torna
 * con la convocazione aperta. Con «Documenti» già fatto resta l'«Avanti» di sempre.
 */
const documentiDaVedere = computed(() => aperto.value === 'documenti' && props.puo.gestire
    && props.passi.find((p) => p.chiave === 'documenti')?.stato === 'adesso');
const segnando = ref(false);
function documentiVisti() {
    if (segnando.value) return;
    segnando.value = true;
    router.post(route(generateRoute('gestionale.assemblee.documenti-visti'), { condominio: props.condominio.id, assemblea: props.assemblea.id }), {},
        // La risposta è la stessa pagina, che tiene il passo aperto: lo apre qui, con la convocazione chiesta al server.
        { onSuccess: () => apri('convocazione'), onFinish: () => { segnando.value = false; } });
}

/** Un passo lento si chiede al server quando si apre. */
function apri(s: ChiaveDelPasso) {
    aperto.value = s;
    if (s === 'convocazione' && props.convocazione === undefined) router.reload({ only: ['convocazione'] });
    if (s === 'documenti' && props.fascicolo === undefined) router.reload({ only: ['fascicolo'] });
    if (s === 'sala' && props.sala === undefined) router.reload({ only: ['sala'] });
    if (s === 'verbale' && props.verbale === undefined) router.reload({ only: ['verbale'] });
    if (s === 'delibere' && props.seguito === undefined) router.reload({ only: ['seguito'] });
}
/**
 * Un'altra assemblea nella stessa pagina (dopo «Annulla e riconvoca» il server porta alla riconvocata, e Inertia tiene viva la pagina): il passo aperto e i
 * suoi dati ripartono da capo, come all'apertura. Senza, restava aperto il passo di prima con «Un momento…».
 */
watch(() => props.assemblea.id, () => {
    aperto.value = props.scheda && props.scheda !== 'diario' ? props.scheda : diAdesso();
    if (aperto.value !== 'date') apri(aperto.value);
});
onMounted(() => {
    if (registrata.value) {
        // La riunione su carta ha il suo seguito nella pagina: si chiede con il diario.
        router.reload({ only: ['diario', 'seguito'] });
    } else if (aperto.value !== 'date') {
        apri(aperto.value);
    }
});

/**
 * Nel passo 1 i punti proposti che non sono ancora nell'ordine del giorno (le proposte differite) entrano nella voce «L'ordine del giorno»: «6 punti · 3
 * proposti aspettano · Vedili» al riquadro dei proposti, così le voci restano tre. Con i punti la voce informa e non blocca; senza punti resta da fare.
 * Solo dove il riquadro c'è: con «Gestisce le assemblee», in preparazione.
 */
const proposti = computed(() => (props.proposte ?? []).reduce((n, g) => n + g.punti.length, 0));
/** Chi non gestisce le assemblee non segna deleghe: la card «Deleghe ricevute» senza deleghe resta senza pulsante. */
const senzaSegna = (v: VoceCheCosaManca): VoceCheCosaManca => (v.collegamento?.apre === 'segna_delega' ? { ...v, collegamento: null } : v);
/** Né esegue le delibere: la card «Da eseguire» del passo 6 dice la cosa, senza il pulsante dell'azione (la finestra gli risponderebbe 403). */
const senzaAzione = (v: VoceCheCosaManca): VoceCheCosaManca => (v.chiave === 'da_eseguire' ? { ...v, collegamento: null } : v);
const passiDellaPagina = computed<PassoDellaStrada[]>(() => {
    if (!props.puo.gestire) {
        return props.passi.map((p) => (p.chiave === 'sala' ? { ...p, manca: p.manca.map(senzaSegna) }
            : p.chiave === 'delibere' ? { ...p, manca: p.manca.map(senzaAzione) } : p));
    }
    if (proposti.value === 0 || !props.assemblea.in_preparazione) return props.passi;
    const n = proposti.value;
    const aspettano = n === 1 ? '1 proposto aspetta' : `${n} proposti aspettano`;
    const conIProposti = (v: VoceCheCosaManca): VoceCheCosaManca => (v.chiave !== 'ordine' ? v
        : { ...v, dettaglio: `${v.dettaglio} · ${aspettano}`, informa: v.fatto, collegamento: { testo: 'Vedili', url: '#proposti', passo: null } });

    return props.passi.map((p) => (p.chiave === 'date' ? { ...p, manca: p.manca.map(conIProposti) } : p));
});

// Le finestre che le card del passo «In sala» aprono: il cassetto delle deleghe e «Segna una delega ricevuta». La sala arriva con il passo aperto.
const cassettoDelleDeleghe = ref(false);
const finestraDellaDelega = ref(false);
function apreLaFinestra(f: FinestraDellaVoce) {
    if (props.sala === undefined) router.reload({ only: ['sala'] });
    if (f === 'deleghe') cassettoDelleDeleghe.value = true;
    else finestraDellaDelega.value = true;
}
/**
 * Una stampa dalla card si apre in una scheda nuova: il server segna l'ora della stampa mentre la compone. Quando si torna alla pagina, una volta, si
 * rileggono i passi (e la sala, se c'è): il foglio ristampato torna verde.
 */
let rileggiAlRitorno: (() => void) | null = null;
function dopoLaStampa() {
    if (typeof window === 'undefined' || rileggiAlRitorno) return;
    rileggiAlRitorno = () => {
        if (rileggiAlRitorno) window.removeEventListener('focus', rileggiAlRitorno);
        rileggiAlRitorno = null;
        router.reload({ only: props.sala !== undefined ? ['passi', 'sala'] : ['passi'] });
    };
    window.addEventListener('focus', rileggiAlRitorno);
}
onBeforeUnmount(() => { if (rileggiAlRitorno) window.removeEventListener('focus', rileggiAlRitorno); });

/** Il passo «Convocazione» e «Documenti» già letti si rileggono con i gesti sulle date e sull'ordine del giorno: dopo una stampa dicono «ristampale». */
const giaLetti = computed(() => [props.convocazione !== undefined ? 'convocazione' : null, props.fascicolo !== undefined ? 'fascicolo' : null]
    .filter((x): x is string => x !== null));

// Le date: si scrivono qui e si salvano con «Salva le date»; il controllo è quello del server (R11).
const modulo = ref<ModuloDelleDate>(moduloDa(props.assemblea));
watch(() => props.assemblea, (a) => { modulo.value = moduloDa(a); });
const cambiate = computed(() => JSON.stringify(datiDelModulo(modulo.value)) !== JSON.stringify(datiDelModulo(moduloDa(props.assemblea))));
const dateValide = computed(() => controllaLeDate(seduteDaControllare(modulo.value.sedute)).valide);
const salvataggio = ref(false);
const erroriDelleDate = ref<Record<string, string>>({});
function salvaLeDate() {
    salvataggio.value = true;
    router.patch(route(generateRoute('gestionale.assemblee.update'), parametri.value), datiDelModulo(modulo.value), {
        only: ['assemblea', 'passi', 'flash', ...giaLetti.value], preserveScroll: true, preserveState: true,
        onError: (e: Record<string, string>) => { erroriDelleDate.value = e; },
        onSuccess: () => { erroriDelleDate.value = {}; },
        onFinish: () => { salvataggio.value = false; },
    });
}

// Il memo arrivato con «Mettilo nell'ordine del giorno del …» (`?memo=`): niente entra senza il clic.
function mettiIlMemo() {
    if (!props.memo) return;
    router.post(route(generateRoute('gestionale.memo.nell-ordine'), { condominio: props.condominio.id, memo: props.memo.id }), { assemblea_id: props.assemblea.id });
}

// «Fascicolo» nell'intestazione: il PDF unico con la copertina, l'avviso e i documenti dei punti (come «Componi il fascicolo» del passo «Documenti»).
const fascicoloInCorso = ref(false);
const esitoDelFascicolo = ref<string | null>(null);
function componiIlFascicolo() {
    if (!props.puo.gestire || fascicoloInCorso.value) return;
    fascicoloInCorso.value = true;
    esitoDelFascicolo.value = null;
    // La scheda nuova si apre nel clic, prima della richiesta.
    apriPdfInUnaScheda(() => axios.post(route(generateRoute('gestionale.assemblee.fascicolo.componi'), parametri.value), {}, { responseType: 'blob' }),
        'fascicolo-dell-assemblea.pdf')
        .then((arrivato) => {
            esitoDelFascicolo.value = testoDelFile(arrivato);
            router.reload({ only: props.fascicolo === undefined ? ['diario'] : SOLO_FASCICOLO });
        })
        .catch(async (e) => { esitoDelFascicolo.value = await fraseDaErroreConFile(e, 'Il fascicolo non si è composto. Riprova.'); })
        .finally(() => { fascicoloInCorso.value = false; });
}

const finestraTenuta = ref(false);
const confermaElimina = ref(false);
function elimina() {
    router.delete(route(generateRoute('gestionale.assemblee.destroy'), parametri.value));
}

/** «Dall'assemblea del 15/12/2026, verbale n. 42.»; per la riunione su carta «Dall'assemblea tenuta su carta il 15/12/2026 · verbale da caricare.» */
const sottotitoloDelSeguito = computed(() => {
    const giorno = props.assemblea.data ? props.assemblea.data.split('-').reverse().join('/') : '';
    const verbale = props.assemblea.verbale_da_caricare ? 'verbale da caricare' : props.assemblea.numero_verbale ? `verbale n. ${props.assemblea.numero_verbale}` : null;

    return `Dall'assemblea${props.assemblea.tenuta_fuori ? ` tenuta su carta il ${giorno}` : ` del ${giorno}`}${verbale ? `${props.assemblea.tenuta_fuori ? ' ·' : ','} ${verbale}` : ''}.`;
});
const evidenzia = computed(() => ({ delibera: props.delibera ?? null, cosa: props.cosa ?? null }));
/**
 * «Crea il piano straordinario»: la finestra si apre con la cosa scelta nell'indirizzo (`?cosa=`), cercata fra le cose del seguito, nella strada come nella
 * riunione tenuta su carta, e solo per chi gestisce le assemblee; chiusa, l'indirizzo perde la cosa con una visita parziale della sola `cosa`, così un clic
 * nuovo sulla stessa cosa la riapre.
 */
const pianoDaCreare = computed(() => {
    if (!props.puo.gestire || props.cosa === null || props.cosa === undefined) return null;
    const cosa = (props.seguito?.tabella ?? []).flatMap((r) => r.cose).find((c) => c.id === props.cosa);

    return cosa?.tipo === 'crea_piano_straordinario' ? cosa : null;
});
function chiudiIlPiano(aperta: boolean) {
    if (aperta) {
        return;
    }
    const indirizzo = new URL(window.location.href);
    indirizzo.searchParams.delete('cosa');
    router.visit(indirizzo.pathname + indirizzo.search, { only: ['cosa'], preserveState: true, preserveScroll: true, replace: true });
}
/** Le cose da fare di ogni delibera, per la finestra di «Elimina» nella riunione registrata a mano o tenuta su carta. */
const coseDelleDelibere = computed(() => Object.fromEntries((props.seguito?.tabella ?? []).map((r) => [r.delibera_id, r.cose])));

const titoloDelDiario = computed(() => `Il diario dell'${props.assemblea.nome.charAt(0).toLowerCase()}${props.assemblea.nome.slice(1)}`);
/** Il sottotitolo del cassetto: «Ordinaria del 15/12/2026». */
const sottotitoloDelDiario = computed(() => {
    const nome = props.assemblea.nome.replace(/^Assemblea\s+/u, '');

    return nome.charAt(0).toUpperCase() + nome.slice(1);
});
/** Il pulsante «Azioni» ha la forma e il carattere di «Guida»: alto 32 pixel, la scritta a 12. */
const pulsanteDellaTesta = 'inline-flex h-8 items-center justify-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 shadow-sm transition-colors hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800';
/** Una voce del menu «Azioni»: l'icona, il nome e la riga grigia sotto. */
const voce = 'flex cursor-pointer items-start gap-2 px-2 py-1.5 text-[13px]';
const iconaDellaVoce = 'mt-0.5 h-3.5 w-3.5 shrink-0';
</script>

<template>
    <Head :title="assemblea.nome" />
    <GestionaleLayout>
        <div class="space-y-4 px-4 py-6 sm:px-6 sm:py-8">
            <PageHeaderGuide
                :page-title="assemblea.titolo"
                :page-subtitle="assemblea.sottotitolo"
                :guides="[]"
                :breadcrumbs="(breadcrumbs as any)"
                :video-url="null"
                :condominio="condominio"
                :condomini="condomini"
                condominio-fisso
                :back-url="elenco"
                back-text="Assemblee"
                has-text-guide
                text-guide-title="Guida"
                una-fila
                @open-text-guide="mostraGuida = true"
            >
                <!-- Lo stato accanto al titolo: è ciò che la scheda è, non un'azione, e fra i pulsanti allungava la fila fino a mandarla a capo. -->
                <template #accanto-al-titolo>
                    <span :class="[BADGE, CLASSI_DEL_BADGE[coloreDelloStato(assemblea.stato, assemblea.tenuta_fuori)], 'py-0.5']" data-testid="stato">{{ assemblea.stato_parola }}</span>
                    <span v-if="assemblea.tenuta_fuori && assemblea.verbale_da_caricare" :class="[BADGE, CLASSI_DEL_BADGE.ambra, 'py-0.5']" data-testid="verbale-da-caricare-in-testa">Verbale da caricare</span>
                </template>
                <template #actions>
                    <!-- Il menu «Azioni» c'è sempre, con le voci che servono a chi guarda; ogni voce ha la sua icona e la riga grigia sotto. -->
                    <DropdownMenu :modal="false">
                        <DropdownMenuTrigger as-child>
                            <button type="button" :class="pulsanteDellaTesta" data-testid="menu-assemblea">
                                <Zap class="h-3.5 w-3.5 text-slate-500" aria-hidden="true" /> Azioni <ChevronDown class="h-3.5 w-3.5 text-slate-400" aria-hidden="true" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-64">
                            <DropdownMenuItem :class="voce" data-testid="voce-diario" @select="cassettoDelDiario = true">
                                <History :class="iconaDellaVoce" aria-hidden="true" />
                                <span><span class="block" data-testid="nome-voce">Diario</span><span class="block text-xs text-slate-500 dark:text-slate-400">Le modifiche e gli invii, in un cassetto</span></span>
                            </DropdownMenuItem>
                            <template v-if="!registrata">
                                <!-- «Fascicolo»: il PDF unico del fascicolo, per chi gestisce le assemblee. -->
                                <DropdownMenuItem v-if="puo.gestire" :class="voce" :disabled="fascicoloInCorso" data-innesto="pulsante-fascicolo" data-testid="pulsante-fascicolo"
                                    @select="componiIlFascicolo">
                                    <FileText :class="iconaDellaVoce" aria-hidden="true" />
                                    <span><span class="block" data-testid="nome-voce">{{ fascicoloInCorso ? 'Un momento…' : 'Fascicolo' }}</span><span class="block text-xs text-slate-500 dark:text-slate-400">Copertina, avviso e documenti in un PDF</span></span>
                                </DropdownMenuItem>
                                <DropdownMenuItem v-if="assemblea.apri_la_sala.attiva && assemblea.apri_la_sala.url" as-child :class="voce">
                                    <Link :href="assemblea.apri_la_sala.url" data-testid="apri-la-sala">
                                        <DoorOpen :class="iconaDellaVoce" aria-hidden="true" />
                                        <span><span class="block" data-testid="nome-voce">{{ assemblea.apri_la_sala.testo ?? 'Apri la sala' }}</span><span class="block text-xs text-slate-500 dark:text-slate-400">{{ assemblea.apri_la_sala.perche }}</span></span>
                                    </Link>
                                </DropdownMenuItem>
                                <!-- Spenta, dice il perché sotto la voce: un suggerimento del browser non si legge al tocco. -->
                                <DropdownMenuItem v-else :disabled="true" :class="voce" data-testid="apri-la-sala">
                                    <DoorOpen :class="iconaDellaVoce" aria-hidden="true" />
                                    <span><span class="block" data-testid="nome-voce">Apri la sala</span><span class="block text-xs" data-testid="perche-sala">{{ assemblea.apri_la_sala.perche }}</span></span>
                                </DropdownMenuItem>
                            </template>
                            <template v-if="puo.gestire && assemblea.stato === 'convocata'">
                                <DropdownMenuSeparator />
                                <DropdownMenuItem :class="[voce, 'text-rose-700 dark:text-rose-400']" data-testid="voce-riconvoca" @select="riconvoca">
                                    <RotateCcw :class="iconaDellaVoce" aria-hidden="true" />
                                    <span><span class="block" data-testid="nome-voce">Annulla e riconvoca</span><span class="block text-xs text-slate-500 dark:text-slate-400">Le date nuove, con il motivo</span></span>
                                </DropdownMenuItem>
                            </template>
                            <template v-if="puo.gestire && assemblea.in_preparazione">
                                <DropdownMenuSeparator />
                                <DropdownMenuItem :class="voce" data-testid="voce-tenuta-fuori" @select="finestraTenuta = true">
                                    <FileSignature :class="iconaDellaVoce" aria-hidden="true" />
                                    <span><span class="block" data-testid="nome-voce">Tenuta su carta</span><span class="block text-xs text-slate-500 dark:text-slate-400">Registra gli esiti dal verbale di carta</span></span>
                                </DropdownMenuItem>
                                <DropdownMenuItem :class="[voce, 'text-rose-700 dark:text-rose-400']" data-testid="voce-elimina" @select="confermaElimina = true">
                                    <Trash2 :class="iconaDellaVoce" aria-hidden="true" />
                                    <span class="block" data-testid="nome-voce">Elimina l'assemblea</span>
                                </DropdownMenuItem>
                            </template>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </PageHeaderGuide>

            <p v-if="assemblea.riconvocata_da" class="text-sm text-slate-600 dark:text-slate-300">Riconvoca la {{ assemblea.riconvocata_da.nome.toLowerCase() }}, annullata.</p>
            <p v-if="esitoDelFascicolo" class="text-xs text-slate-600 dark:text-slate-300" data-testid="esito-fascicolo">{{ esitoDelFascicolo }}</p>

            <!-- Il memo arrivato dall'Action inbox -->
            <div v-if="memo && assemblea.in_preparazione && puo.gestire" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 dark:border-sky-800 dark:bg-sky-950/40"
                data-testid="memo-da-mettere">
                <p class="min-w-0 break-words text-sm text-sky-900 dark:text-sky-200">Il memo «{{ memo.testo }}» aspetta la prossima assemblea.</p>
                <button type="button" class="inline-flex h-8 items-center rounded-md bg-slate-900 px-3 text-sm font-medium text-white dark:bg-white dark:text-slate-900" @click="mettiIlMemo">
                    Mettilo nell'ordine del giorno
                </button>
            </div>

            <!-- La strada dei cinque passi, con il passo aperto sotto -->
            <StradaDellAssemblea v-if="!registrata" :passi="passiDellaPagina" :aperto="aperto" @apri="apri" @apre="apreLaFinestra" @stampa="dopoLaStampa">
                <template v-if="documentiDaVedere" #azione>
                    <button type="button" class="inline-flex h-8 w-full items-center justify-center gap-1.5 rounded-lg bg-slate-900 px-3 text-xs font-semibold text-white shadow-sm transition-colors disabled:cursor-not-allowed disabled:opacity-55 sm:w-auto dark:bg-white dark:text-slate-900"
                        :disabled="segnando" data-testid="documenti-visti" @click="documentiVisti">
                        Avanti: Convocazione<ArrowRight class="h-3.5 w-3.5" aria-hidden="true" />
                    </button>
                </template>
                <!-- Date e ordine del giorno -->
                <div v-show="aperto === 'date'" class="space-y-4" data-testid="scheda-date">
                    <section id="date-dell-assemblea" class="scroll-mt-4 space-y-3.5 rounded-xl border border-slate-200 bg-white px-4 py-4 dark:border-slate-800 dark:bg-slate-950">
                        <p class="text-xs text-slate-500 dark:text-slate-400">Il programma controlla le date mentre le scrivi: la seconda in un altro giorno e non oltre dieci giorni (art. 1136 c. 3). La tabella che non copre tutte le unità avvisa e non blocca.</p>
                        <DateDellAssemblea v-model="modulo" :scelte="scelte" :disabilitato="!puo.gestire || !assemblea.in_preparazione" perimetro-bloccato :termini="termini_della_prima ?? null" />
                        <div id="tabella-del-valore" class="scroll-mt-4">
                            <TabellaDiProprieta :condominio-id="condominio.id" :condominio-nome="condominio.nome" :assemblea="assemblea" :scelte="scelte" :gestisce="puo.gestire" />
                        </div>
                        <p v-for="(m, k) in erroriDelleDate" :key="k" class="text-sm text-rose-700 dark:text-rose-400">{{ m }}</p>
                        <div v-if="puo.gestire && assemblea.in_preparazione" class="flex justify-end">
                            <button type="button" class="inline-flex h-9 items-center rounded-md bg-slate-900 px-4 text-sm font-medium text-white disabled:opacity-50 dark:bg-white dark:text-slate-900"
                                :disabled="!cambiate || !dateValide || salvataggio" data-testid="salva-date" @click="salvaLeDate">
                                {{ salvataggio ? 'Un momento…' : 'Salva le date' }}
                            </button>
                        </div>
                    </section>
                    <Deferred data="proposte">
                        <template #fallback>
                            <OrdineDelGiorno :condominio-id="condominio.id" :condominio-nome="condominio.nome" :assemblea="assemblea" :ordine="ordine" :scelte="scelte"
                                :gestisce="puo.gestire" :evidenzia="punto" :rileggi="giaLetti" />
                        </template>
                        <OrdineDelGiorno :condominio-id="condominio.id" :condominio-nome="condominio.nome" :assemblea="assemblea" :ordine="ordine" :scelte="scelte"
                            :proposte="proposte ?? []" :gestisce="puo.gestire" :evidenzia="punto" :rileggi="giaLetti" />
                    </Deferred>
                </div>

                <!-- Documenti: il fascicolo arriva quando il passo si apre -->
                <div v-show="aperto === 'documenti'" data-innesto="fascicolo" data-testid="innesto-fascicolo">
                    <p v-if="fascicolo === undefined" class="rounded-xl border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400">
                        Un momento…
                    </p>
                    <SchedaFascicolo v-else :fascicolo="fascicolo" />
                </div>

                <!-- Convocazione: arriva quando il passo si apre -->
                <div v-show="aperto === 'convocazione'" data-innesto="convocazione" data-testid="innesto-convocazione">
                    <p v-if="convocazione === undefined" class="rounded-xl border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400">
                        Un momento…
                    </p>
                    <SchedaConvocazione v-else :convocazione="convocazione" :apri-riconvoca="chiediRiconvoca" />
                </div>

                <!-- In sala: le deleghe ricevute, il foglio della sala, la sala; arriva quando il passo si apre -->
                <div v-show="aperto === 'sala'" data-innesto="sala" data-testid="innesto-sala">
                    <p v-if="sala === undefined" class="rounded-xl border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400">
                        Un momento…
                    </p>
                    <PassoInSala v-else :sala="sala" :apri-la-sala="assemblea.apri_la_sala" :puo-gestire="puo.gestire" :condominio-id="condominio.id" :assemblea-id="assemblea.id" />
                </div>

                <!-- Verbale e trasmissione: le riunioni con il loro verbale, quando il passo si apre -->
                <div v-show="aperto === 'verbale'" data-innesto="verbale" data-testid="innesto-verbale">
                    <p v-if="verbale === undefined" class="rounded-xl border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-400">
                        Un momento…
                    </p>
                    <PassoDelVerbale v-else :verbale="verbale" />
                </div>

                <!-- Delibere eseguite: il seguito delle delibere, quando il passo si apre -->
                <div v-show="aperto === 'delibere'" data-innesto="delibere" data-testid="innesto-delibere">
                    <PassoDelleDelibere :seguito="seguito" :esiti="seguito_esiti" :condominio-id="condominio.id" :condominio-nome="condominio.nome" :assemblea-id="assemblea.id"
                        :sottotitolo="sottotitoloDelSeguito" :evidenzia="evidenzia" />
                </div>
            </StradaDellAssemblea>

            <!-- La riunione registrata a mano, o l'assemblea tenuta su carta: il diario resta sotto, come prima. -->
            <template v-if="registrata">
                <RiunioneRegistrata :condominio-id="condominio.id" :assemblea="assemblea" :ordine="ordine" :esiti="scelte.esiti" :riunioni="riunioni_a_mano"
                    :gestisce="puo.gestire" :registra-il-verbale="puo.registrare_il_verbale" :cose="coseDelleDelibere" />
                <PassoDelleDelibere :seguito="seguito" :esiti="seguito_esiti" :condominio-id="condominio.id" :condominio-nome="condominio.nome" :assemblea-id="assemblea.id"
                    :sottotitolo="sottotitoloDelSeguito" :su-carta="true" :evidenzia="evidenzia" />
                <DiarioDellAssemblea :diario="diario" :titolo="titoloDelDiario" />
            </template>
        </div>

        <!-- «Crea il piano straordinario», aperta dalla cosa scelta (`?cosa=`): una finestra sola per la strada e per la riunione su carta. -->
        <CreaIlPianoStraordinario v-if="pianoDaCreare" :open="true" :condominio-id="condominio.id" :assemblea-id="assemblea.id" :cosa-id="pianoDaCreare.id"
            data-innesto="crea-piano-straordinario" @update:open="chiudiIlPiano" />
        <TenutaFuoriDalProgramma v-if="puo.gestire && assemblea.in_preparazione" v-model:open="finestraTenuta" :condominio-id="condominio.id" :assemblea="assemblea"
            :ordine="ordine" :esiti="scelte.esiti" />
        <ConfirmDialog v-model="confermaElimina" :title="`Eliminare l'${assemblea.nome.charAt(0).toLowerCase()}${assemblea.nome.slice(1)}?`" confirm-text="Elimina l'assemblea"
            description="Si elimina solo un'assemblea in preparazione: i memo dei suoi punti tornano nella memoria del condominio, e la riga resta nel registro delle modifiche."
            @confirm="elimina" />
        <CassettoDelDiario v-model:open="cassettoDelDiario" :diario="diario" :sottotitolo="sottotitoloDelDiario" />
        <CassettoDelleDeleghe v-if="!registrata" v-model:open="cassettoDelleDeleghe" :sala="sala" :puo-gestire="puo.gestire" :condominio-id="condominio.id"
            :assemblea-id="assemblea.id" />
        <SegnaUnaDelegaRicevuta v-if="sala && puo.gestire" v-model:open="finestraDellaDelega" :sala="sala" />
        <ConvocazioneGuide v-if="guidaDellaConvocazione" v-model:open="mostraGuida" />
        <DelibereGuide v-else-if="guidaDelleDelibere" v-model:open="mostraGuida" />
        <AssembleaGuide v-else v-model:open="mostraGuida" />
    </GestionaleLayout>
</template>
