<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">

import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import PageHeaderGuide, { type GuideItem } from '@/components/PageHeaderGuide.vue';
import { usePermission } from "@/composables/permissions";
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { useDateConverter } from '@/composables/useDateConverter';
import { Mail, Wallet, ArrowDownCircle, ArrowUpCircle, Building2, Landmark, FileText, Banknote, HelpCircle, RotateCcw, CheckCircle2, AlertCircle, PieChart, Coins, Info, XCircle, ChevronDown, ShieldCheck, Zap, Receipt, Plus, Clock } from 'lucide-vue-next';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import MoneyInput from '@/components/MoneyInput.vue';
import { useUrlPrecedente } from '@/composables/useUrlPrecedente';
import type { Building } from '@/types/buildings';
import type { Anagrafica } from '@/types/anagrafiche';
import type { Esercizio } from '@/types/gestionale/esercizi';
import { statoRigaEstratto, type TonoStato } from '@/lib/gestionale/pianiRate/statoRigaEstratto';
import { oggiLocale } from '@/lib/giorno';
import LettereDialog from '@/components/gestionale/lettere/LettereDialog.vue';
import RegistroLettere, { type LetteraDelRegistro } from '@/components/gestionale/lettere/RegistroLettere.vue';
import FascicoliDellaPersona, { type FascicoloPreparato } from '@/components/gestionale/fascicolo/FascicoliDellaPersona.vue';
import AccordiDellaPersona from '@/components/gestionale/accordi/AccordiDellaPersona.vue';
import AddebitiDellaPersona, { type AddebitoDellaPersona } from '@/components/gestionale/addebiti/AddebitiDellaPersona.vue';
import NuovoAddebitoDialog, { type OrigineAddebito } from '@/components/gestionale/addebiti/NuovoAddebitoDialog.vue';
import AddebitaSpesaDialog from '@/components/gestionale/addebiti/AddebitaSpesaDialog.vue';
import EstrattoContoGuide from '@/components/guides/EstrattoContoGuide.vue';
import { riepilogoAddebiti, testoRiepilogoAddebiti } from '@/lib/gestionale/addebiti/addebiti';
import { euroTesto } from '@/lib/gestionale/debiti/rateScadute';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { testoUltimaLettera, type UltimaLettera } from '@/lib/gestionale/lettere/lettere';
import { numeroDellaScheda, riassuntoDellaCorrispondenza, type DocumentoInviato } from '@/lib/gestionale/lettere/corrispondenza';
import { percentuale } from '@/lib/gestionale/passaggi/percentuale';
import type { AccordoRientro } from '@/lib/gestionale/debiti/accordiRientro';

const props = defineProps<{
  condominio: Building;
  esercizio: Esercizio;
  anagrafica: Anagrafica & { immobili: any[] };
  timeline: any[];
  stats: {
    totale_addebiti: string;
    totale_versamenti: string;
    saldo_finale: string;
    saldo_raw: number;
    saldo_iniziale: string;
    saldo_iniziale_raw: number;
    /** Il credito netto della persona sulle rate emesse (`CreditoService::nettoDellaPersona`). */
    credito_disponibile: string;
    credito_disponibile_raw: number;
    /** Il debito netto della persona sulle rate emesse, quando il suo credito non lo copre. */
    credito_da_versare?: string;
    credito_da_versare_raw?: number;
    /** Il credito lordo delle sue quote, anche delle rate in bozza: sta sotto in piccolo. */
    credito_lordo?: string;
    credito_lordo_raw?: number;
    /** Il netto per gestione (`CreditoService::nettoDellaPersona`): il credito di una gestione non toglie dal debito di un'altra. */
    credito_per_gestione: Array<{ gestione_id: number | null; gestione_nome: string; importo_cents: number; importo_formatted: string; credito_cents?: number; da_versare_cents?: number; frase?: string }>;
    /** Quanto di quel credito è davvero spendibile sulle rate aperte, e su quale. */
    compensabile: string;
    compensabile_raw: number;
    compensabile_frase: string;
    compensabile_rata_id: number | null;
  };
  /** B2, S6: ciò che serve al modulo «Rimborsa il credito». */
  rimborso: {
    quote: Array<{ id: number; rata: number | null; piano: string | null; gestione: string | null; immobile: string | null; origine: 'saldi' | 'entrate' | 'misto' | 'eccedenza'; conto: 'passate_gestioni' | 'crediti_condomini'; a_giornale: boolean; credito_cents: number; credito_formattato: string; credito_euro: string }>;
    casse: Array<{ id: number; nome: string; tipo: string; saldo_cents: number; saldo_formattato: string }>;
    /** Il credito di chi esce ancora in `saldi` (coppia del passaggio non assorbita): non è ancora una quota. */
    credito_in_saldi: number;
    rate_da_pagare?: number;
  };
  /**
   * B2, S7: la solidarietà dell'art. 63 co. 4 verso questa persona come entrante — nota, non quota. Dalla beta.51 (Coda 172)
   * `residuo_uscente_cents` è al netto della coppia del conguaglio ancora nel Wallet; `residuo_lordo_cents` è la cifra prima della
   * coppia e `coppia_cents` la coppia. La pagina mostra il testo (con `subentro_id` e `immobile`), che dice la coppia nella stessa frase: le cifre in
   * centesimi servono ai test e al laboratorio.
   */
  solidarieta?: Array<{ subentro_id: number; immobile: string; uscente: string | null; entrante: string | null; decorrenza: string; esercizi: string; residuo_uscente_cents: number; residuo_uscente_formattato: string; residuo_lordo_cents: number; coppia_cents: number; testo: string }>;
  /** Coda 167, beta.40: i versamenti che questa persona ha fatto per il debito di un'altra — fuori dal libro, perché non erano suo debito. */
  versamenti_per_altri?: Array<{ scrittura_id: number; data: string | null; protocollo: string | null; per_conto_di: string; importo_cents: number; importo_formattato: string; stornato: boolean }>;
  /** 1.12.0-beta.4: le lettere a questa persona in questo condominio, dalla più recente. */
  lettere?: LetteraDelRegistro[];
  /** Coda 533: le ricevute, gli avvisi di compensazione e «non serve comunicarla» della persona, per la scheda «Corrispondenza». */
  documenti?: DocumentoInviato[];
  /** Coda 533: l'ultima lettera di oggi e quella in coda o non partita dopo, come la colonna «Ultima lettera» di «Rate scadute». */
  ultima_lettera?: { ultima: UltimaLettera | null; in_sospeso: UltimaLettera | null };
  /** Il giorno dell'amministratore, dal server. */
  oggi?: string;
  /** Gli accordi di rientro della persona in questo condominio, anche chiusi o riusciti, con il segno di oggi. */
  accordi_rientro?: Array<AccordoRientro & { in_corso: boolean }>;
  /** Gli addebiti a questa persona sola, con quelli che si possono ancora annullare. */
  addebiti?: AddebitoDellaPersona[];
  /** I fascicoli per il legale preparati per questa persona, con l'indice e il prospetto di quel giorno. */
  fascicoli?: FascicoloPreparato[];
}>();

// Le lettere: «Prepara una lettera» apre la finestra per questa persona sola.
const finestraLettera = ref(false);

// La guida della pagina, nel cassetto di lato: la apre «Guida» dell'intestazione.
const mostraGuida = ref(false);

// Gli addebiti a questa persona sola. Le due finestre stanno qui, una volta: le aprono il menu «Azioni», la card «Addebiti personali» e l'elenco.
const finestraAddebito = ref(false);
const finestraSpese = ref(false);
const origineAddebito: OrigineAddebito = { tipo: 'persona', anagraficaId: Number(props.anagrafica.id) };
const personaDelleSpese = [Number(props.anagrafica.id)];
const dopoUnAddebito = () => router.reload({ only: ['addebiti', 'timeline', 'stats'] });
// La card: il totale e quanti sono, dalla stessa lista dell'elenco.
const riepilogo = computed(() => riepilogoAddebiti(props.addebiti ?? []));

// «Unità immobiliari»: al massimo le prime due nella card, una riga ciascuna; tutte nella finestra che apre «e altre N unità».
const UNITA_NELLA_CARD = 2;
const unitaNellaCard = computed(() => props.anagrafica.immobili.slice(0, UNITA_NELLA_CARD));
const altreUnita = computed(() => Math.max(0, props.anagrafica.immobili.length - UNITA_NELLA_CARD));
const finestraUnita = ref(false);

// Coda 533: le due schede, «Movimenti contabili» (di serie) e «Corrispondenza (N)». La scheda non si ricorda; `?scheda=corrispondenza`
// nell'indirizzo apre la seconda (il collegamento «Vedi la corrispondenza» di «Rate scadute»). N si conta con la funzione che fa l'elenco.
const indirizzoDellaPagina = (usePage() as { url?: string }).url ?? '';
const scheda = ref(new URLSearchParams(indirizzoDellaPagina.split('?')[1] ?? '').get('scheda') === 'corrispondenza' ? 'corrispondenza' : 'movimenti');
const numeroCorrispondenza = computed(() => numeroDellaScheda(props.lettere ?? [], props.documenti ?? []));
// Che cosa c'è nella scheda, per il collegamento della card «Lettere»: «3 ricevute» accanto a «Nessuna lettera» non si contraddice.
const riassuntoCorrispondenza = computed(() => riassuntoDellaCorrispondenza(props.lettere ?? [], props.documenti ?? []));
// «Vedi la corrispondenza» in testa: fra la riga e le schede ci sono le note della persona, e al telefono le schede sono sotto lo schermo; dopo il
// cambio di scheda la pagina le porta in vista.
const schede = ref<HTMLElement | null>(null);
async function apriCorrispondenza() {
    scheda.value = 'corrispondenza';
    await nextTick();
    schede.value?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
}
const oggiDellaPagina = props.oggi ?? oggiLocale();

// --- Rimborso del credito (B2, S6, voce 9) ------------------------------------------------------------
const rimborsoAperto = ref(false);
const formRimborso = useForm({
    rata_quote_id: (props.rimborso?.quote[0]?.id ?? null) as number | null,
    cassa_id: (props.rimborso?.casse.length === 1 ? props.rimborso.casse[0].id : null) as number | null,
    // Non `data`: `useForm()` espone già un metodo `data()`, e un campo con quel nome lo copre — il
    // v-model legava una funzione e il campo restava vuoto.
    // Oggi nel calendario di chi usa la pagina (1.12.0-beta.3), non il giorno di Greenwich.
    data_rimborso: oggiLocale(),
    importo: props.rimborso?.quote[0]?.credito_euro ?? '',
    nota: '',
});

/** Le quote a credito con un'etichetta per `v-select`: importo, gestione, piano, rata, unità. */
const quoteRimborsabili = computed(() => (props.rimborso?.quote ?? []).map(q => ({
    ...q,
    etichetta: `${q.credito_formattato} — ${q.gestione ?? 'gestione'}${q.piano ? `, ${q.piano}` : ''}${q.rata !== null ? ` (rata ${q.rata})` : ''}${q.immobile ? ` · ${q.immobile}` : ''}`,
})));

// L'importo con la maschera italiana degli altri moduli (verifica S6, R17): «1.000» resta mille, non € 1,00.
const moneyOptionsRimborso = { prefix: '', suffix: '', thousands: '.', decimal: ',', precision: 2, allowBlank: true, masked: true, disableNegative: true };

const quotaScelta = computed(() => props.rimborso?.quote.find(q => q.id === formRimborso.rata_quote_id) ?? null);

// Da dove viene il credito (rilievo K2 della Fase 1-bis): il riporto da un esercizio precedente, le entrate dell'anno di una voce di
// tipo «entrata», tutte e due, o un versamento in eccesso.
const ETICHETTA_ORIGINE: Record<string, string> = { saldi: 'da saldi', entrate: 'entrate', misto: 'saldi ed entrate', eccedenza: 'eccedenza' };
const cassaScelta = computed(() => props.rimborso?.casse.find(c => c.id === formRimborso.cassa_id) ?? null);

// Cambiando quota, l'importo proposto è il suo credito intero: si può ridurre, non superare.
watch(() => formRimborso.rata_quote_id, () => {
    if (quotaScelta.value) formRimborso.importo = quotaScelta.value.credito_euro;
});

const apriRimborso = () => {
    formRimborso.clearErrors();
    // Dopo un rimborso la quota scelta può non essere più in lista (chiusa): si riparte dalla prima che c'è (verifica S6, R23).
    if (!quotaScelta.value) formRimborso.rata_quote_id = props.rimborso?.quote[0]?.id ?? null;
    if (quotaScelta.value) formRimborso.importo = quotaScelta.value.credito_euro;
    rimborsoAperto.value = true;
};

// Il rimborso a chi ha ancora da versare chiede una conferma: il credito potrebbe andare prima sulle sue rate. Decide l'amministratore.
const confermaRimborsoAperta = ref(false);
const inviaRimborso = () => {
    if ((props.stats.credito_da_versare_raw ?? 0) > 0) {
        confermaRimborsoAperta.value = true;
        return;
    }
    registraRimborso();
};
const confermaRimborso = () => {
    confermaRimborsoAperta.value = false;
    registraRimborso();
};
const registraRimborso = () => {
    formRimborso.post(route(generateRoute('gestionale.anagrafiche.rimborsi.store'), { condominio: props.condominio.id, anagrafica: props.anagrafica.id }), {
        preserveScroll: true,
        onSuccess: () => { rimborsoAperto.value = false; formRimborso.reset('nota'); },
    });
};

// Lo storno di un rimborso: stessa strada degli incassi (rettifica + originale annullata).
// `AlertDialogAction` chiude il dialogo da sé prima che arrivi `confirm`: la riga scelta vive in una ref
// separata dall'apertura, altrimenti al momento della conferma è già nulla e nessuna richiesta parte.
const stornoDaConfermare = ref<any | null>(null);
const stornoAperto = ref(false);
const chiediStorno = (riga: any) => { stornoDaConfermare.value = riga; stornoAperto.value = true; };
const stornaRimborso = () => {
    const riga = stornoDaConfermare.value;
    stornoAperto.value = false;
    if (!riga) return;
    router.post(route(generateRoute('gestionale.movimenti-rate.storno'), { condominio: props.condominio.id, scrittura: riga.scrittura_id }), {}, {
        preserveScroll: true,
        onFinish: () => { stornoDaConfermare.value = null; },
    });
};

const { euro } = useCurrencyFormatter({ fromCents: false }); 
const { toItalian } = useDateConverter();
const { generatePath, generateRoute } = usePermission();
const { urlPrecedente } = useUrlPrecedente();

const goBack = () => {
    if (window.history.length > 1) window.history.back();
    else router.visit(generatePath('gestionale/:condominio/piani-rate', { condominio: props.condominio.id }));
};

const rateScaduteDellaPersona = computed(() => route(generateRoute('gestionale.rate-scadute.index'), { condominio: props.condominio.id, anagrafica_id: props.anagrafica.id }));

const printPDF = () => {
    const url = route('admin.gestionale.anagrafiche.estratto-conto.print', {
        condominio: props.condominio.id,
        anagrafica: props.anagrafica.id,
    });
    window.open(url, '_blank');
};

const formatIndirizzoImmobile = (immobile: any) => {
    // ⚠️ Ripasso della beta.58: il PDF di questa stessa pagina era stato corretto e la card no.
    // Con l'interno facoltativo restava «Int. » da solo — e due unità così erano indistinguibili
    // proprio nella schermata da cui si stampa il documento che le nomina.
    // Non si può leggere `immobile.etichetta`: il modello non ha `$appends` e la risorsa non
    // espone l'accessore, quindi la stessa regola va ripetuta qui in una riga.
    let base = immobile.interno ? `Int. ${immobile.interno}` : (immobile.nome || 'Unità');
    if (immobile.piano) base += ` - P. ${immobile.piano}`;
    return base;
};

// beta.30: leggeva `window.history.state.back`, convenzione di Vue Router che
// Inertia v3 non popola — il ramo era sempre falso e si tornava sempre al
// fallback. La provenienza reale ora arriva da useUrlPrecedente().
const backUrlString = computed(() => {
    const precedente = urlPrecedente();
    if (precedente) {
        return precedente;
    }
    // Fallback alla lista dei piani rate dell'esercizio corrente
    return route('admin.gestionale.esercizi.piani-rate.index', { 
        condominio: props.condominio.id, 
        esercizio: props.esercizio.id 
    });
});

/**
 * Il link «Compensa»: porta l'anagrafica e la rata che il credito copre, così la pagina di
 * incasso si apre già puntata. Non porta `intent_usa_credito`, che dichiarerebbe una richiesta
 * del condòmino: qui a muoversi è l'amministratore.
 */
const urlCompensazione = computed(() => {
    const params = new URLSearchParams({
        prefill_anagrafica_id: String(props.anagrafica.id),
    });
    if (props.stats.compensabile_rata_id) {
        params.set('prefill_rata_id', String(props.stats.compensabile_rata_id));
    }
    return `${route(generateRoute('gestionale.movimenti-rate.create'), { condominio: props.condominio.id })}?${params}`;
});

const breadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Piani Rate', href: backUrlString.value },
  { title: `EC: ${props.anagrafica.nome}`, href: '#' },
]);

const pageGuides: GuideItem[] = [
  {
    title: 'Saldo Iniziale',
    description: 'Il debito o credito ereditato dall\'esercizio precedente. Un importo in rosso indica un debito residuo, in verde un credito della persona.',
    icon: Landmark,
    colorVariant: 'slate'
  },
  {
    title: 'Addebiti',
    description: 'Le rate emesse a carico del condòmino, gli addebiti personali e i rimborsi del suo credito. Un incasso stornato e il suo storno restano nell\'elenco, non nel totale.',
    icon: Banknote,
    colorVariant: 'amber'
  },
  {
    title: 'Pagamenti',
    description: 'Tutti gli incassi registrati e le compensazioni usate per raggiungere il saldo finale.',
    icon: Wallet,
    colorVariant: 'emerald'
  }
];

const saldoColorClass = computed(() => {
    if (props.stats.saldo_raw > 0) return 'text-red-600';
    if (props.stats.saldo_raw < 0) return 'text-emerald-600';
    return 'text-gray-600 dark:text-slate-300';
});

const saldoInizialeColorClass = computed(() => {
    if (props.stats.saldo_iniziale_raw > 0) return 'text-red-600'; 
    if (props.stats.saldo_iniziale_raw < 0) return 'text-emerald-600'; 
    return 'text-gray-600 dark:text-slate-300';
});

// Il bollino di una riga: l'etichetta (la stessa della stampa) e il tono da `statoRigaEstratto` (1.11.0-beta.49), colore e icona
// qui. Uno stato che la pagina non conosce non disegna niente: prima «stornata» usciva come un riquadro vuoto.
const STILE_DEL_TONO: Record<TonoStato, { class: string; icon: any }> = {
    verde: { class: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-300 dark:border-emerald-900/60', icon: CheckCircle2 },
    indaco: { class: 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/30 dark:text-indigo-300 dark:border-indigo-900/60', icon: Coins },
    ambra: { class: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/30 dark:text-amber-300 dark:border-amber-900/60', icon: PieChart },
    rosso: { class: 'bg-red-50 text-red-700 border-red-200 dark:bg-red-950/30 dark:text-red-300 dark:border-red-900/60', icon: AlertCircle },
    blu: { class: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/30 dark:text-blue-300 dark:border-blue-900/60', icon: ArrowDownCircle },
    grigio: { class: 'bg-slate-100 text-slate-600 border-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700', icon: XCircle },
};

const getStatoConfig = (stato: string | null) => {
    const bollino = statoRigaEstratto(stato);
    if (!bollino) return null;

    return { label: bollino.etichetta, ...STILE_DEL_TONO[bollino.tono] };
};

const getImportoStyle = (riga: any) => {
    if (riga.tipo_riga === 'dare') {
         // FIX: Se è uno storno, stile più tenue per non sembrare una vera rata
         if (riga.tipo_icona === 'rotate-ccw') {
             return 'text-slate-600 font-semibold line-through decoration-slate-300 decoration-2 dark:text-slate-400 dark:decoration-slate-600';
         }
         return 'text-red-600 font-medium';
    } else if (riga.tipo_riga === 'avere') {
         return 'text-emerald-600 font-bold tabular-nums text-sm bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-100 dark:bg-emerald-950/30 dark:border-emerald-900/50';
    }
    
    const isCredito = riga.dettagli?.some((d: any) => d.type === 'rata' && d.status === 'credito');
    if (isCredito) return 'text-blue-600 font-bold';
    
    return 'text-gray-900 font-medium dark:text-slate-100';
};
</script>

<template>
    <Head :title="`EC - ${anagrafica.nome}`" />

    <GestionaleLayout>
        
        <div class="px-6 py-8 space-y-6">
            <PageHeaderGuide
                :page-title="`Estratto Conto: ${anagrafica.nome}`"
                :page-subtitle="`Situazione contabile dettagliata, saldo progressivo e storico movimenti.${anagrafica.codice_fiscale ? ' CF: ' + anagrafica.codice_fiscale : ''}`"
                :guides="pageGuides"
                :breadcrumbs="breadcrumbs"
                :condominio="condominio"
                :esercizio="esercizio"
                :backUrl="backUrlString"
                backText="Indietro"
                has-text-guide
                text-guide-title="Guida"
                @open-text-guide="mostraGuida = true"
            >
                <template #actions>
                    <!-- Le lettere, gli addebiti e la stampa in un menu solo, a gruppi: con un pulsante per ognuno la fila andava a capo. -->
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" data-testid="menu-azioni"
                                class="h-8 px-3 border-slate-200 text-slate-700 bg-white hover:bg-slate-50 shadow-sm shrink-0 gap-2 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800">
                                <Zap class="w-4 h-4" />
                                <span>Azioni</span>
                                <ChevronDown class="w-3 h-3 opacity-60" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-80 max-w-[calc(100vw-2rem)] shadow-xl rounded-xl border-slate-100 dark:border-slate-800 p-1.5" data-testid="voci-azioni">
                            <DropdownMenuLabel class="text-xs text-slate-400 uppercase tracking-widest px-2 py-1.5 font-bold">Lettere</DropdownMenuLabel>
                            <DropdownMenuItem
                                data-testid="prepara-una-lettera"
                                @click="finestraLettera = true"
                                class="cursor-pointer flex items-center gap-2.5 px-2 py-2 rounded-lg hover:bg-indigo-50 focus:bg-indigo-50 dark:hover:bg-indigo-950/40 dark:focus:bg-indigo-950/40 text-slate-700 dark:text-slate-200">
                                <Mail class="w-3.5 h-3.5 text-indigo-500" />
                                <div>
                                    <div class="text-xs font-medium">Prepara una lettera</div>
                                    <div class="text-xs text-slate-400">Promemoria, sollecito, diffida, messa in mora</div>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuItem as-child>
                                <Link :href="rateScaduteDellaPersona" data-testid="rate-scadute-della-persona"
                                   class="cursor-pointer flex items-center gap-2.5 px-2 py-2 rounded-lg hover:bg-indigo-50 focus:bg-indigo-50 dark:hover:bg-indigo-950/40 dark:focus:bg-indigo-950/40 text-slate-700 dark:text-slate-200">
                                    <AlertCircle class="w-3.5 h-3.5 text-indigo-500" />
                                    <div>
                                        <div class="text-xs font-medium">Rate scadute</div>
                                        <div class="text-xs text-slate-400">L'elenco, con questa persona sola</div>
                                    </div>
                                </Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator class="bg-slate-100 dark:bg-slate-800" />
                            <DropdownMenuLabel class="text-xs text-slate-400 uppercase tracking-widest px-2 py-1.5 font-bold">Addebiti</DropdownMenuLabel>
                            <DropdownMenuItem
                                data-testid="voce-nuovo-addebito"
                                @click="finestraAddebito = true"
                                class="cursor-pointer flex items-center gap-2.5 px-2 py-2 rounded-lg hover:bg-amber-50 focus:bg-amber-50 dark:hover:bg-amber-950/30 dark:focus:bg-amber-950/30 text-slate-700 dark:text-slate-200">
                                <Plus class="w-3.5 h-3.5 text-amber-600" />
                                <div>
                                    <div class="text-xs font-medium">Nuovo addebito</div>
                                    <div class="text-xs text-slate-400">Un contributo, o una spesa di una sola unità già fatturata</div>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                data-testid="voce-spese-del-ritardo"
                                @click="finestraSpese = true"
                                class="cursor-pointer flex items-center gap-2.5 px-2 py-2 rounded-lg hover:bg-amber-50 focus:bg-amber-50 dark:hover:bg-amber-950/30 dark:focus:bg-amber-950/30 text-slate-700 dark:text-slate-200">
                                <Clock class="w-3.5 h-3.5 text-amber-600" />
                                <div>
                                    <div class="text-xs font-medium">Spese del ritardo</div>
                                    <div class="text-xs text-slate-400">Le spese per un pagamento in ritardo</div>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator class="bg-slate-100 dark:bg-slate-800" />
                            <DropdownMenuLabel class="text-xs text-slate-400 uppercase tracking-widest px-2 py-1.5 font-bold">Stampe</DropdownMenuLabel>
                            <DropdownMenuItem
                                data-testid="stampa-estratto-conto"
                                @click="printPDF"
                                class="cursor-pointer flex items-center gap-2.5 px-2 py-2 rounded-lg hover:bg-indigo-50 focus:bg-indigo-50 dark:hover:bg-indigo-950/40 dark:focus:bg-indigo-950/40 text-slate-700 dark:text-slate-200">
                                <FileText class="w-3.5 h-3.5 text-indigo-500" />
                                <div>
                                    <div class="text-xs font-medium">Estratto conto</div>
                                    <div class="text-xs text-slate-400">PDF riepilogativo per anagrafica</div>
                                </div>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </PageHeaderGuide>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 lg:gap-6">
            <!-- Sei card in due file. Le cifre delle prime cinque vengono dal server (`stats`); quella degli addebiti personali dalla lista dell'elenco. -->
            <div class="lg:col-span-8 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4" data-testid="le-card">
                <div class="rounded-lg border bg-gray-50/50 shadow-sm border-gray-200 dark:bg-slate-900/40 dark:border-slate-800">
                    <div class="flex flex-row items-center justify-between gap-2 p-4 pb-2">
                        <h3 class="text-xs font-medium uppercase text-muted-foreground tracking-wider">Saldo iniziale</h3>
                        <Landmark class="h-4 w-4 text-gray-400" />
                    </div>
                    <div class="p-4 pt-0">
                        <div class="text-xl font-bold" :class="saldoInizialeColorClass">{{ stats.saldo_iniziale }}</div>
                        <p class="text-xs uppercase font-bold mt-1 tracking-wide" :class="saldoInizialeColorClass">
                            {{ stats.saldo_iniziale_raw > 0 ? 'A DEBITO' : (stats.saldo_iniziale_raw < 0 ? 'A CREDITO' : 'PAREGGIO') }}
                        </p>
                    </div>
                </div>
                <div class="rounded-lg border bg-card text-card-foreground shadow-sm border-gray-200 dark:border-slate-800" data-testid="card-totale-addebiti">
                    <div class="flex flex-row items-center justify-between gap-2 p-4 pb-2">
                        <h3 class="text-xs font-medium uppercase text-muted-foreground tracking-wider">Totale addebiti</h3>
                        <ArrowDownCircle class="h-4 w-4 text-red-500" />
                    </div>
                    <div class="p-4 pt-0">
                        <div class="text-xl font-bold text-gray-900 dark:text-slate-100">{{ stats.totale_addebiti }}</div>
                        <!-- Un addebito personale è emesso quando si registra, con la sua riga in DARE sulla posizione della persona: è dentro questa cifra. -->
                        <p class="text-xs text-muted-foreground mt-1">Rate emesse e addebiti</p>
                    </div>
                </div>
                <div class="rounded-lg border bg-card text-card-foreground shadow-sm border-gray-200 dark:border-slate-800">
                    <div class="flex flex-row items-center justify-between gap-2 p-4 pb-2">
                        <h3 class="text-xs font-medium uppercase text-muted-foreground tracking-wider">Totale Versato</h3>
                        <ArrowUpCircle class="h-4 w-4 text-emerald-500" />
                    </div>
                    <div class="p-4 pt-0">
                        <div class="text-xl font-bold text-emerald-600">{{ stats.totale_versamenti }}</div>
                        <p class="text-xs text-muted-foreground mt-1">Incassi registrati</p>
                    </div>
                </div>
                <div class="rounded-lg border shadow-sm border-gray-200" :class="{'bg-red-50 border-red-200 dark:bg-red-950/20 dark:border-red-900/50': stats.saldo_raw > 0, 'bg-emerald-50 border-emerald-200 dark:bg-emerald-950/20 dark:border-emerald-900/50': stats.saldo_raw < 0, 'bg-white dark:bg-card dark:border-slate-800': stats.saldo_raw === 0}">
                    <div class="flex flex-row items-center justify-between gap-2 p-4 pb-2">
                        <!-- Uno zero è grigio: né rosso né verde. -->
                        <h3 class="text-xs font-medium uppercase tracking-wider" :class="stats.saldo_raw > 0 ? 'text-red-500' : (stats.saldo_raw < 0 ? 'text-emerald-500' : 'text-slate-500 dark:text-slate-400')">Saldo Finale</h3>
                        <Wallet class="h-4 w-4" :class="stats.saldo_raw > 0 ? 'text-red-500' : (stats.saldo_raw < 0 ? 'text-emerald-500' : 'text-slate-400')" />
                    </div>
                    <div class="p-4 pt-0">
                        <div class="text-2xl font-bold" :class="saldoColorClass">{{ stats.saldo_finale }}</div>
                        <p class="text-xs uppercase font-bold mt-1 tracking-wide" :class="stats.saldo_raw > 0 ? 'text-red-600' : (stats.saldo_raw < 0 ? 'text-emerald-600' : 'text-slate-500 dark:text-slate-400')">
                            {{ stats.saldo_raw > 0 ? 'DA VERSARE' : (stats.saldo_raw < 0 ? 'A CREDITO' : 'PAREGGIO') }}
                        </p>
                    </div>
                </div>
                <div class="rounded-lg border bg-card text-card-foreground shadow-sm border-gray-200 dark:border-slate-800">
                    <div class="flex flex-row items-center justify-between gap-2 p-4 pb-2">
                        <h3 class="text-xs font-medium uppercase text-muted-foreground tracking-wider">Credito disponibile</h3>
                        <Coins class="h-4 w-4 text-blue-500" />
                    </div>
                    <div class="p-4 pt-0">
                        <div class="flex items-center gap-1.5">
                            <div v-if="stats.credito_disponibile_raw <= 0 && (stats.credito_da_versare_raw ?? 0) > 0 && (stats.credito_lordo_raw ?? 0) > 0" class="text-xl font-bold text-gray-900 dark:text-slate-100" data-testid="credito-da-versare">{{ stats.credito_da_versare }} <span class="text-xs font-semibold uppercase tracking-wide text-red-600">da versare</span></div>
                            <div v-else class="text-xl font-bold" :class="stats.credito_disponibile_raw > 0 ? 'text-blue-600' : 'text-gray-400'">{{ stats.credito_disponibile }}</div>
                        </div>
                        <!-- Con credito e debito su gestioni diverse, una riga per gestione, a vista: il credito di una gestione copre da sé solo le rate
                             della sua, quindi non si toglie dal debito di un'altra. Dentro la stessa gestione il netto resta. -->
                        <div v-if="stats.credito_per_gestione.length > 1" class="mt-2 space-y-1" data-testid="credito-per-gestione">
                            <div v-for="g in stats.credito_per_gestione" :key="g.gestione_id ?? 0" class="flex justify-between gap-3 text-xs">
                                <span class="min-w-0 text-slate-600 dark:text-slate-300">{{ g.gestione_nome }}</span>
                                <span class="shrink-0 whitespace-nowrap font-semibold tabular-nums" :class="(g.da_versare_cents ?? 0) > 0 ? 'text-red-600' : 'text-blue-600'">{{ g.frase ?? g.importo_formatted }}</span>
                            </div>
                        </div>
                        <!-- Le note del credito, in un blocco solo e alla stessa grandezza. -->
                        <div class="mt-2 space-y-1 text-xs leading-snug text-muted-foreground" data-testid="note-del-credito"
                             :class="stats.credito_per_gestione.length > 1 ? 'border-t border-gray-100 pt-2 dark:border-slate-800' : ''">
                            <p v-if="stats.credito_per_gestione.length > 1">Il credito di una gestione copre le rate della stessa gestione.</p>
                            <!-- Il lordo, quando non coincide con il netto: comprende le quote a credito delle rate in bozza, e quelle già tolte dal debito. -->
                            <p v-if="(stats.credito_lordo_raw ?? 0) > 0 && stats.credito_lordo_raw !== stats.credito_disponibile_raw" data-testid="credito-lordo">
                                {{ stats.credito_lordo }} di credito sulle sue quote, anche di rate non ancora emesse: sulle rate emesse è già tolto dal debito della stessa gestione.
                            </p>
                            <p v-if="(stats.credito_lordo_raw ?? stats.credito_disponibile_raw) > 0">
                                {{ stats.compensabile_frase }}
                                <a
                                    v-if="stats.compensabile_rata_id"
                                    :href="urlCompensazione"
                                    class="text-blue-600 hover:text-blue-700 underline underline-offset-2 font-medium"
                                >Compensa</a>
                            </p>
                            <p v-else>Nessun credito attivo</p>
                        </div>
                        <!-- B2, S6: il rimborso — denaro che esce a fronte della quota a credito, per chi non ha più rate da compensare -->
                        <button
                            v-if="rimborso && rimborso.quote.length > 0"
                            type="button"
                            @click="apriRimborso"
                            class="mt-2 inline-flex h-7 items-center gap-1.5 rounded-md border border-blue-200 bg-blue-50 px-2.5 text-xs font-semibold text-blue-700 hover:bg-blue-100 transition-colors dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-300 dark:hover:bg-blue-950/50"
                        >
                            <Banknote class="w-3.5 h-3.5" /> Rimborsa il credito
                        </button>
                        <!-- Coda 227: con rate ancora da pagare quel credito può andare prima su quelle (con «Compensa»); si rimborsa quello che avanza. «Può»: fra
                             le rate da pagare ci sono anche quelle non ancora scadute, e lì decide l'amministratore (art. 1243 c.c., debiti esigibili). -->
                        <p v-else-if="rimborso && rimborso.credito_in_saldi > 0" class="text-xs text-blue-700/80 mt-2 leading-snug" data-testid="credito-in-saldi">
                            <template v-if="(rimborso.rate_da_pagare ?? 0) > 0">
                                {{ euro(rimborso.credito_in_saldi / 100) }} di credito dal passaggio di titolarità sono ancora nei saldi della gestione:
                                quando il prossimo piano rate li assorbe diventano una quota a credito.
                                {{ anagrafica.nome }} ha ancora {{ euro((rimborso.rate_da_pagare ?? 0) / 100) }} di rate da pagare: quel credito può andare prima
                                su quelle, con «Compensa»; si rimborsa quello che avanza.
                            </template>
                            <template v-else>
                                {{ euro(rimborso.credito_in_saldi / 100) }} di credito dal passaggio di titolarità sono ancora nei saldi della gestione:
                                diventano una quota rimborsabile quando il prossimo piano rate li assorbe.
                            </template>
                        </p>
                    </div>
                </div>
                <!-- Gli addebiti a questa persona sola: il totale e quanti sono, e le due finestre. Nel posto vuoto della seconda fila. -->
                <div class="rounded-lg border bg-card text-card-foreground shadow-sm border-gray-200 dark:border-slate-800" data-testid="card-addebiti-personali">
                    <div class="flex flex-row items-center justify-between gap-2 p-4 pb-2">
                        <h3 class="text-xs font-medium uppercase text-muted-foreground tracking-wider">Addebiti personali</h3>
                        <Receipt class="h-4 w-4 text-amber-500" />
                    </div>
                    <div class="p-4 pt-0">
                        <div class="text-xl font-bold" :class="riepilogo.numero > 0 ? 'text-gray-900 dark:text-slate-100' : 'text-gray-400'" data-testid="totale-addebiti-personali">{{ euroTesto(riepilogo.totale) }}</div>
                        <p class="text-xs text-muted-foreground mt-1" data-testid="quanti-addebiti-personali">{{ testoRiepilogoAddebiti(riepilogo.numero) }}</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <button type="button" data-testid="card-nuovo-addebito" @click="finestraAddebito = true"
                                class="inline-flex h-7 items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2.5 text-xs font-semibold text-amber-800 hover:bg-amber-100 transition-colors dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300 dark:hover:bg-amber-950/50">
                                <Plus class="w-3.5 h-3.5" /> Nuovo
                            </button>
                            <button type="button" data-testid="card-spese-del-ritardo" @click="finestraSpese = true"
                                class="inline-flex h-7 items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2.5 text-xs font-semibold text-amber-800 hover:bg-amber-100 transition-colors dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300 dark:hover:bg-amber-950/50">
                                <Clock class="w-3.5 h-3.5" /> Spese del ritardo
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <!-- La colonna di destra non entra nell'altezza della fila: il suo posto (`lg:relative`) non ha niente nel flusso, e la griglia lo stira
                 all'altezza delle due file di card; la colonna ci sta dentro (`lg:absolute lg:inset-0`). Le sei card tengono così la loro altezza con
                 qualunque numero di unità e qualunque lettera. Le unità prendono lo spazio sopra le lettere: ne mostrano al massimo due, una riga
                 ciascuna, e le altre in una finestra; l'ultima lettera sta in due righe. Sotto `lg` la colonna va sotto le card, nel flusso. -->
            <div class="lg:col-span-4 lg:relative" data-testid="colonna-destra">
                <div class="flex flex-col gap-4 lg:absolute lg:inset-0">
                    <div class="rounded-lg border bg-card text-card-foreground shadow-sm border-gray-200 dark:border-slate-800 overflow-hidden lg:flex-1 lg:min-h-0" data-testid="card-unita">
                        <div class="flex flex-row items-center justify-between gap-2 p-4 pb-2">
                            <h3 class="text-xs font-medium uppercase text-muted-foreground tracking-wider flex items-center gap-2">
                                Unità immobiliari
                                <Badge variant="secondary" class="text-xs h-5 px-1.5 normal-case tracking-normal">{{ anagrafica.immobili.length }}</Badge>
                            </h3>
                            <Building2 class="h-4 w-4 text-gray-400" />
                        </div>
                        <div class="px-4 pb-3 space-y-1.5" data-testid="unita-della-persona">
                            <div v-for="immobile in unitaNellaCard" :key="immobile.id" class="flex items-center gap-2 min-w-0" data-testid="unita">
                                <span class="truncate flex-1 min-w-0 text-xs font-semibold text-gray-800 dark:text-slate-200" :title="formatIndirizzoImmobile(immobile)"
                                    data-testid="nome-unita">{{ formatIndirizzoImmobile(immobile) }}</span>
                                <Badge variant="secondary" class="shrink-0 text-xs px-1.5 h-4">{{ immobile.pivot.tipologia }}</Badge>
                                <Badge variant="outline" class="shrink-0 text-xs px-1.5 h-4 text-gray-500 dark:text-slate-400">{{ percentuale(immobile.pivot.quota) }}</Badge>
                            </div>
                            <button v-if="altreUnita > 0" type="button" data-testid="altre-unita" @click="finestraUnita = true"
                                class="block text-xs leading-4 font-semibold text-indigo-600 hover:text-indigo-700 hover:underline underline-offset-2 dark:text-indigo-400 dark:hover:text-indigo-300">
                                {{ altreUnita === 1 ? 'e un\'altra unità' : `e altre ${altreUnita} unità` }}
                            </button>
                            <p v-if="anagrafica.immobili.length === 0" class="text-xs leading-4 text-muted-foreground italic">Nessuna unità associata.</p>
                        </div>
                    </div>

                    <!-- L'ultima lettera, con la fonte e il testo della colonna «Ultima lettera» di «Rate scadute»; sotto «Unità immobiliari». Due
                         righe al massimo, una se sotto c'è la lettera in coda: la card resta della stessa altezza; il testo intero nel `title` e in
                         «Corrispondenza». -->
                    <div class="rounded-lg border bg-card text-card-foreground shadow-sm border-gray-200 dark:border-slate-800 shrink-0" data-testid="ultima-lettera-in-testa">
                        <div class="flex flex-row items-center justify-between gap-2 p-4 pb-2">
                            <h3 class="text-xs font-medium uppercase text-muted-foreground tracking-wider">Lettere</h3>
                            <Mail class="h-4 w-4 text-gray-400" />
                        </div>
                        <div class="px-4 pb-3 space-y-1 text-xs">
                            <!-- Con la sola lettera in coda o non partita, il testo della colonna («Nessuna lettera») e sotto «poi: …». -->
                            <p v-if="ultima_lettera?.ultima || ultima_lettera?.in_sospeso" :class="ultima_lettera?.in_sospeso ? 'line-clamp-1' : 'line-clamp-2'"
                                :title="`Ultima lettera: ${testoUltimaLettera(ultima_lettera?.ultima)}`" data-testid="testo-ultima-lettera">
                                <span class="font-semibold">Ultima lettera:</span>{{ ' ' }}<span>{{ testoUltimaLettera(ultima_lettera?.ultima) }}</span>
                            </p>
                            <p v-else class="line-clamp-2" title="Nessuna lettera a questa persona in questo condominio." data-testid="testo-ultima-lettera"><span>Nessuna lettera a questa persona in questo condominio.</span></p>
                            <p v-if="ultima_lettera?.in_sospeso" class="truncate" :title="`poi: ${testoUltimaLettera(ultima_lettera.in_sospeso)}`" data-testid="lettera-in-sospeso"><span class="text-amber-700 dark:text-amber-400">poi: {{ testoUltimaLettera(ultima_lettera.in_sospeso) }}</span></p>
                            <!-- Sulla stessa riga da `lg` in su: la card resta della sua altezza, e un riassunto lungo si tronca (il testo intero nel `title` e
                                 nella scheda). A 1.440 pixel il collegamento ha 207 px: «Corrispondenza: 1 lettera e 2 ricevute» ne fa 186. Sotto `lg` va a capo. -->
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 pt-1 lg:flex-nowrap">
                                <button type="button" data-testid="card-prepara-una-lettera" @click="finestraLettera = true"
                                    class="inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md border border-indigo-200 bg-indigo-50 px-2.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100 transition-colors dark:border-indigo-900/50 dark:bg-indigo-950/30 dark:text-indigo-300 dark:hover:bg-indigo-950/50">
                                    <Mail class="w-3.5 h-3.5" /> Prepara una lettera
                                </button>
                                <button v-if="numeroCorrispondenza > 0" type="button" data-testid="vedi-la-corrispondenza" @click="apriCorrispondenza"
                                    :title="`Vedi la corrispondenza: ${riassuntoCorrispondenza}`"
                                    class="min-w-0 truncate text-xs leading-4 font-semibold text-indigo-600 hover:text-indigo-700 hover:underline underline-offset-2 dark:text-indigo-400 dark:hover:text-indigo-300">Corrispondenza: {{ riassuntoCorrispondenza }}</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

            <!-- Coda 167, beta.40: i versamenti fatti per il debito di un altro. Chiudono le quote di chi doveva e non toccano il saldo di questa persona: per questo stanno qui e non nel libro qui sotto. -->
            <div v-if="versamenti_per_altri && versamenti_per_altri.length" class="rounded-lg border border-sky-200 bg-sky-50/70 dark:border-sky-900/40 dark:bg-sky-950/20 px-4 py-3 flex items-start gap-3">
                <Banknote class="w-4 h-4 mt-0.5 shrink-0 text-sky-600" />
                <div class="text-xs leading-relaxed text-sky-950 dark:text-sky-100">
                    <span class="font-bold uppercase tracking-wider text-xs text-sky-800 dark:text-sky-300 block mb-0.5">Versamenti per conto di altri</span>
                    <p class="mb-1">Rate di altre persone pagate da {{ anagrafica.nome }}: chiudono il debito di chi le doveva e non cambiano il saldo qui sotto.</p>
                    <ul class="space-y-0.5 tabular-nums">
                        <li v-for="v in versamenti_per_altri" :key="v.scrittura_id" :class="{ 'text-sky-900/50 dark:text-sky-100/50': v.stornato }">
                            Versato il {{ v.data }}: {{ v.importo_formattato }} per conto di {{ v.per_conto_di }}<span v-if="v.protocollo"> (prot. {{ v.protocollo }})</span>
                            <span v-if="v.stornato" class="ml-1.5 inline-block rounded-md border border-slate-300 bg-slate-50 px-1.5 text-xs font-semibold uppercase leading-4 text-slate-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-300">stornato</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Gli accordi di rientro della persona, anche quando non ha più rate scadute: il segno e i versati oltre il capitale. -->
            <AccordiDellaPersona :condominio-id="Number(condominio.id)" :persona="{ id: Number(anagrafica.id), nome: anagrafica.nome }" :accordi="accordi_rientro ?? []"
                :oggi="oggiDellaPagina" @fatto="router.reload({ only: ['accordi_rientro', 'lettere', 'ultima_lettera'] })" />

            <!-- Gli addebiti a questa persona sola, solo quando ce n'è almeno uno: la causale, la natura, «Annulla» o il perché resta. Le due finestre
                 sono quelle della pagina, qui sotto. -->
            <AddebitiDellaPersona :condominio-id="Number(condominio.id)" :persona="{ id: Number(anagrafica.id), nome: anagrafica.nome }" :addebiti="addebiti ?? []"
                @nuovo="finestraAddebito = true" @spese="finestraSpese = true" @fatto="dopoUnAddebito" />

            <!-- B2, S7: la nota calcolata dell'art. 63 co. 4 — chi è entrato risponde in solido con chi è uscito. Non è una quota né un sollecito. -->
            <div v-if="solidarieta && solidarieta.length" class="space-y-2">
                <div v-for="n in solidarieta" :key="n.subentro_id" class="rounded-lg border border-amber-200 bg-amber-50/70 dark:border-amber-900/40 dark:bg-amber-950/20 px-4 py-3 flex items-start gap-3">
                    <ShieldCheck class="w-4 h-4 mt-0.5 shrink-0 text-amber-600" />
                    <div class="text-xs leading-relaxed text-amber-950 dark:text-amber-100">
                        <span class="font-bold uppercase tracking-wider text-xs text-amber-800 dark:text-amber-300 block mb-0.5">Chi risponde in solido · {{ n.immobile }}</span>
                        {{ n.testo }}
                    </div>
                </div>
            </div>

        <!-- Coda 533: i movimenti e la corrispondenza in due schede; si apre quella dei movimenti. -->
        <div ref="schede">
        <Tabs v-model="scheda" class="space-y-4">
            <TabsList class="bg-muted p-1 rounded-md">
                <TabsTrigger value="movimenti" data-testid="scheda-movimenti">Movimenti contabili</TabsTrigger>
                <TabsTrigger value="corrispondenza" data-testid="scheda-corrispondenza">Corrispondenza ({{ numeroCorrispondenza }})</TabsTrigger>
            </TabsList>
            <TabsContent value="corrispondenza" class="mt-0">
                <!-- Il fascicolo per il legale, accanto al registro delle lettere: la finestra e i fascicoli già preparati. -->
                <FascicoliDellaPersona :condominio-id="Number(condominio.id)" :persona="{ id: Number(anagrafica.id), nome: String(anagrafica.nome) }"
                    :fascicoli="fascicoli ?? []" />
                <!-- Le lettere a questa persona (con il residuo di oggi delle stesse rate), le ricevute e gli avvisi. -->
                <RegistroLettere :condominio-id="Number(condominio.id)" :lettere="lettere ?? []" :documenti="documenti ?? []" :persona-id="Number(anagrafica.id)"
                    :oggi="oggiDellaPagina" />
            </TabsContent>
            <TabsContent value="movimenti" class="mt-0 space-y-6">
        <div data-testid="legenda" class="flex flex-wrap gap-4 text-xs text-gray-500 items-center bg-gray-50/80 p-3 rounded-lg border border-dashed border-gray-200 dark:bg-slate-900/60 dark:border-slate-800 dark:text-slate-400">
            <span class="font-bold uppercase tracking-wider text-xs text-gray-400 mr-1 dark:text-slate-500">Legenda:</span>
            <div class="flex items-center gap-1.5"><div class="w-5 h-5 rounded-full bg-white border border-gray-200 flex items-center justify-center text-gray-500 shadow-sm dark:border-slate-800 dark:text-slate-400 dark:bg-slate-900"><FileText class="w-3 h-3" /></div><span>Emissione</span></div>
            <div class="flex items-center gap-1.5"><div class="w-5 h-5 rounded-full bg-emerald-100 border border-emerald-200 flex items-center justify-center text-emerald-600 shadow-sm dark:bg-emerald-950/40 dark:border-emerald-900/60"><Banknote class="w-3 h-3" /></div><span>Incasso</span></div>
            <div class="flex items-center gap-1.5"><div class="w-5 h-5 rounded-full bg-blue-50 border border-blue-200 flex items-center justify-center text-blue-600 shadow-sm dark:bg-blue-950/40 dark:border-blue-900/60"><RotateCcw class="w-3 h-3" /></div><span>Storno</span></div>
            <div class="flex items-center gap-1.5"><div class="w-5 h-5 rounded-full bg-indigo-50 border border-indigo-200 flex items-center justify-center text-indigo-600 shadow-sm dark:bg-indigo-950/40 dark:border-indigo-900/60"><Coins class="w-3 h-3" /></div><span>Compensazione</span></div>
            <div class="flex items-center gap-1.5"><div class="w-5 h-5 rounded-full bg-rose-50 border border-rose-200 flex items-center justify-center text-rose-600 shadow-sm dark:bg-rose-950/40 dark:border-rose-900/60"><Banknote class="w-3 h-3" /></div><span>Rimborso</span></div>
            <div class="h-4 w-px bg-gray-300 mx-2 hidden sm:block dark:bg-slate-700"></div>
            <div class="flex items-center gap-1.5"><CheckCircle2 class="w-3.5 h-3.5 text-emerald-600" /> <span class="text-emerald-700 font-medium dark:text-emerald-400">Saldata</span></div>
            <div class="flex items-center gap-1.5"><PieChart class="w-3.5 h-3.5 text-amber-600" /> <span class="text-amber-700 font-medium dark:text-amber-400">Parziale</span></div>
            <div class="flex items-center gap-1.5"><AlertCircle class="w-3.5 h-3.5 text-red-600" /> <span class="text-red-700 font-medium dark:text-red-400">Non pagata</span></div>
            <div class="flex items-center gap-1.5"><Coins class="w-3.5 h-3.5 text-blue-600" /> <span class="text-blue-700 font-medium dark:text-blue-400">Credito</span></div>
        </div>

        <div class="rounded-lg border bg-card text-card-foreground shadow-sm border-gray-200 dark:border-slate-800" data-testid="tabella-movimenti">
            <div class="flex flex-col gap-y-1.5 p-4 pb-3 border-b bg-gray-50/30 dark:bg-slate-900/40 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h3 class="tracking-tight text-base font-semibold">Movimenti contabili</h3>
                    <div class="text-xs text-muted-foreground">{{ esercizio.nome }}</div>
                </div>
            </div>
            <div class="p-0">
                <div class="relative w-full overflow-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 uppercase bg-gray-50/50 border-b dark:bg-slate-900/60 dark:text-slate-400 dark:border-slate-800">
                            <tr>
                                <th class="px-4 py-3 w-[50px] text-center">Tipo</th>
                                <th class="px-4 py-3 w-[120px]">Data</th>
                                <th class="px-4 py-3">Descrizione</th>
                                <th class="px-4 py-3 text-right text-gray-700 w-[140px] dark:text-slate-300">Addebiti <span class="block text-xs text-gray-400 font-normal normal-case dark:text-slate-500">(Dare)</span></th>
                                <th class="px-4 py-3 text-right text-gray-700 w-[140px] dark:text-slate-300">Pagamenti <span class="block text-xs text-gray-400 font-normal normal-case dark:text-slate-500">(Avere)</span></th>
                                <th class="px-4 py-3 text-right bg-gray-50 text-gray-800 w-[160px] dark:bg-slate-900 dark:text-slate-200">Saldo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-slate-800">
                            
                            <tr class="bg-yellow-50/30 dark:bg-yellow-950/20">
                                <td class="px-4 py-4 text-center">
                                    <div class="w-8 h-8 rounded-full bg-yellow-100 flex items-center justify-center mx-auto text-yellow-600 shadow-sm border border-yellow-200 dark:bg-yellow-950/40 dark:border-yellow-900/60"><Landmark class="w-4 h-4" /></div>
                                </td>
                                <td class="px-4 py-4 text-sm font-medium text-gray-700 dark:text-slate-300">{{ toItalian(esercizio.data_inizio) }}</td>
                                <td class="px-4 py-4 font-semibold text-gray-800 text-sm dark:text-slate-200">Saldo iniziale esercizio</td>
                                <td class="px-4 py-4 text-right text-gray-300 dark:text-slate-600">-</td>
                                <td class="px-4 py-4 text-right text-gray-300 dark:text-slate-600">-</td>
                                <td class="px-4 py-4 text-right font-bold" :class="saldoInizialeColorClass">{{ stats.saldo_iniziale }}</td>
                            </tr>

                            <tr v-if="timeline.length === 0">
                                <td colspan="6" class="px-6 py-12 text-center text-muted-foreground">Nessun movimento registrato.</td>
                            </tr>

                            <tr v-for="riga in timeline" :key="riga.id" class="hover:bg-gray-50 transition-colors group dark:hover:bg-slate-900/60">
                                <td class="px-4 py-3 align-top">
                                    <div class="flex justify-center mt-1">
                                        <div v-if="riga.tipo_icona === 'bill'" class="w-8 h-8 rounded-full bg-white flex items-center justify-center text-gray-500 border border-gray-200 shadow-sm dark:border-slate-800 dark:text-slate-400 dark:bg-slate-900"><FileText class="w-4 h-4" /></div>
                                        <div v-else-if="riga.tipo_icona === 'payment'" class="w-8 h-8 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-600 border border-emerald-200 shadow-sm dark:bg-emerald-950/40 dark:border-emerald-900/60"><Banknote class="w-4 h-4" /></div>
                                        <div v-else-if="riga.tipo_icona === 'landmark'" class="w-8 h-8 rounded-full bg-yellow-50 flex items-center justify-center text-yellow-600 border border-yellow-200 shadow-sm dark:bg-yellow-950/40 dark:border-yellow-900/60"><Landmark class="w-4 h-4" /></div>
                                        <div v-else-if="riga.tipo_icona === 'rotate-ccw'" class="w-8 h-8 rounded-full bg-blue-50 flex items-center justify-center text-blue-600 border border-blue-200 shadow-sm dark:bg-blue-950/40 dark:border-blue-900/60"><RotateCcw class="w-4 h-4" /></div>
                                        <div v-else-if="riga.tipo_icona === 'banknote'" class="w-8 h-8 rounded-full bg-rose-50 flex items-center justify-center text-rose-600 border border-rose-200 shadow-sm dark:bg-rose-950/40 dark:border-rose-900/60"><Banknote class="w-4 h-4" /></div>
                                        <div v-else-if="riga.tipo_icona === 'coins'" class="w-8 h-8 rounded-full bg-indigo-50 flex items-center justify-center text-indigo-600 border border-indigo-200 shadow-sm dark:bg-indigo-950/40 dark:border-indigo-900/60"><Coins class="w-4 h-4" /></div>
                                        <div v-else class="w-8 h-8 rounded-full bg-gray-50 flex items-center justify-center text-gray-500 border border-gray-200 shadow-sm dark:bg-slate-900 dark:border-slate-800 dark:text-slate-400"><HelpCircle class="w-4 h-4" /></div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="text-sm font-medium text-gray-700 dark:text-slate-300">{{ riga.data }}</div>
                                    <!-- Coda 317: la data è quella del movimento (competenza); il giorno in cui è stato inserito arriva solo se è diverso, ed è per l'amministratore: il PDF non lo stampa. -->
                                    <div v-if="riga.data_registrazione" class="text-xs text-gray-400 leading-tight mt-0.5 dark:text-slate-500">Registrata il {{ riga.data_registrazione }}</div>
                                    <div v-if="riga.protocollo" class="mt-1"><Badge variant="outline" class="text-xs px-1 h-4 tabular-nums text-gray-400 border-gray-200 dark:border-slate-800 dark:text-slate-500">{{ riga.protocollo }}</Badge></div>
                                </td>
                                <td class="px-4 py-3 align-top">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-semibold text-gray-800 text-sm dark:text-slate-200">{{ riga.descrizione }}</span>
                                        <Badge v-if="riga.gestione" variant="secondary" class="text-xs h-4 px-1.5 bg-gray-100 text-gray-500 font-normal dark:bg-slate-800 dark:text-slate-400">{{ riga.gestione }}</Badge>
                                    </div>
                                    <p v-if="riga.note" class="text-xs text-blue-600 italic mb-1">Note: {{ riga.note }}</p>
                                    <button
                                        v-if="riga.stornabile"
                                        type="button"
                                        @click="chiediStorno(riga)"
                                        class="inline-flex items-center gap-1 text-xs font-semibold text-rose-600 hover:underline mb-1"
                                    >
                                        <RotateCcw class="w-3 h-3" /> Storna il rimborso
                                    </button>
                                    <div v-if="riga.dettagli && riga.dettagli.length > 0" class="flex flex-col gap-1 mt-1">
                                        <div v-for="(item, index) in riga.dettagli" :key="index" class="flex items-center flex-wrap gap-2">
                                            
                                            <span class="text-xs text-gray-500 flex items-center gap-1.5 dark:text-slate-400">
                                                <span class="w-1 h-1 rounded-full bg-gray-300 shrink-0 dark:bg-slate-700"></span> {{ item.text }}
                                            </span>
                                            
                                            <span v-if="getStatoConfig(item.status)" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md border text-xs font-bold uppercase tracking-wider" :class="getStatoConfig(item.status)!.class">
                                                <component :is="getStatoConfig(item.status)!.icon" class="w-3 h-3" />
                                                <span>{{ getStatoConfig(item.status)!.label }}</span>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                
                                <td class="px-4 py-3 text-right align-top">
                                    <div v-if="riga.dare > 0" class="flex items-center justify-end gap-1.5">
                                        
                                        <span :class="getImportoStyle(riga)">{{ euro(riga.dare / 100) }}</span>
                                        
                                       <TooltipProvider v-if="riga.breakdown" :delayDuration="0">
                                            <Tooltip>
                                                <TooltipTrigger as-child>
                                                    <div class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-blue-50 text-blue-500 hover:bg-blue-100 hover:text-blue-600 transition-colors cursor-help shadow-sm border border-blue-100 dark:bg-blue-950/40 dark:border-blue-900/50 dark:hover:bg-blue-900/40">
                                                        <Info class="w-3 h-3" />
                                                    </div>
                                                </TooltipTrigger>
                                                <TooltipContent side="right" class="bg-slate-900 border-slate-700 text-slate-200 p-4 w-80 shadow-xl rounded-lg z-50">
                                                    
                                                    <div class="text-xs font-bold text-slate-400 mb-3 uppercase tracking-wider border-b border-slate-700 pb-1 flex justify-between">
                                                        <span>
                                                            {{ 
                                                              riga.breakdown.type === 'incasso' ? 'Dettaglio Incasso' : 
                                                              (riga.breakdown.type === 'storno' ? 'Dettaglio Storno' : (riga.breakdown.type === 'rimborso' ? 'Dettaglio Rimborso' : (riga.breakdown.type === 'compensazione' ? 'Dettaglio Compensazione' : 'Dettaglio Addebito'))) 
                                                            }}
                                                            <span v-if="riga.breakdown.immobile && riga.breakdown.immobile !== 'Generico'">(Int. {{ riga.breakdown.immobile }})</span>
                                                        </span>
                                                    </div>

                                                    <div class="space-y-2 text-xs">
                                                        <div class="flex justify-between items-center text-slate-400">
                                                            <span class="flex items-center gap-1">
                                                                <div class="w-1.5 h-1.5 rounded-full" :class="riga.breakdown.start < 0 ? 'bg-emerald-500' : (riga.breakdown.start > 0 ? 'bg-red-500' : 'bg-gray-500')"></div>
                                                                <span>Saldo precedente:</span>
                                                            </span>
                                                            <span class="tabular-nums">{{ euro(riga.breakdown.start) }}</span>
                                                        </div>

                                                        <div class="flex justify-between items-center text-white">
                                                            <span class="pl-2.5">
                                                                Movimento in {{ riga.breakdown.type === 'incasso' ? 'Avere' : 'Dare' }}:
                                                            </span>
                                                            <span class="tabular-nums font-bold" :class="riga.breakdown.type === 'storno' ? 'text-slate-400 line-through' : ''">
                                                                {{ riga.breakdown.type === 'incasso' ? '-' : '+' }} {{ euro(riga.breakdown.cost) }}
                                                            </span>
                                                        </div>

                                                        <template v-if="riga.breakdown.type === 'emissione' && riga.breakdown.saldo_usato && riga.breakdown.saldo_usato !== 0">
                                                            <div class="my-1.5 pl-2.5 border-l-2 border-slate-600 ml-1 py-0.5 space-y-1 text-xs">
                                                                <div class="flex justify-between items-center text-slate-300">
                                                                    <span class="italic text-slate-400">Quota pura:</span>
                                                                    <span class="tabular-nums">{{ euro(riga.breakdown.cost) }}</span>
                                                                </div>
                                                                <div class="flex justify-between items-center text-slate-300">
                                                                    <span class="italic text-slate-400">
                                                                        {{ riga.breakdown.saldo_usato > 0 ? '+ Recupero Debito:' : '- Sconto Credito:' }}
                                                                    </span>
                                                                    <span class="tabular-nums">{{ euro(riga.breakdown.saldo_usato) }}</span>
                                                                </div>
                                                                <div class="flex justify-between items-center font-bold text-white pt-1">
                                                                    <span>Da pagare per questa quota:</span>
                                                                    <span class="tabular-nums text-amber-400">{{ euro(riga.breakdown.totale_richiesto) }}</span>
                                                                </div>
                                                            </div>
                                                        </template>
                                                        <div class="border-t border-slate-700 my-2 pt-2">
                                                            <div class="flex justify-between items-center font-bold text-sm">
                                                                <span class="text-white">Nuovo saldo progressivo:</span>
                                                                <span class="tabular-nums" :class="riga.breakdown.end < 0 ? 'text-emerald-400' : (riga.breakdown.end > 0 ? 'text-red-400' : 'text-white')">
                                                                    {{ euro(riga.breakdown.end) }}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </TooltipContent>
                                            </Tooltip>
                                        </TooltipProvider>
                                    </div>
                                    <span v-else class="text-gray-200 text-xs dark:text-slate-700">-</span>
                                </td>

                                <td class="px-4 py-3 text-right align-top">
                                    <span v-if="riga.avere > 0" class="text-emerald-600 font-bold tabular-nums text-sm bg-emerald-50 px-2 py-0.5 rounded-md border border-emerald-100 dark:bg-emerald-950/30 dark:border-emerald-900/50">{{ euro(riga.avere / 100) }}</span>
                                    <span v-else class="text-gray-200 text-xs dark:text-slate-700">-</span>
                                </td>
                                
                                <td class="px-4 py-3 text-right align-top bg-gray-50/30 group-hover:bg-gray-100/50 border-l border-gray-100 dark:bg-slate-900/40 dark:group-hover:bg-slate-800/50 dark:border-slate-800">
                                    <span class="tabular-nums font-bold text-sm" :class="riga.saldo > 0 ? 'text-red-600' : (riga.saldo < 0 ? 'text-emerald-600' : 'text-gray-400')">{{ euro(riga.saldo / 100) }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
            </TabsContent>
        </Tabs>
        </div>
        </div>

        <!-- B2, S6: il modulo del rimborso -->
        <Dialog :open="rimborsoAperto" @update:open="rimborsoAperto = $event">
            <!-- Niente autofocus sul primo campo: `v-select` si apre quando riceve il focus, e il modale nasceva col menu spalancato -->
            <DialogContent class="sm:max-w-lg overflow-hidden" @open-auto-focus.prevent>
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><Banknote class="w-5 h-5 text-blue-600" /> Rimborsa il credito</DialogTitle>
                    <DialogDescription>
                        Denaro che esce dalla cassa a fronte di una quota a credito di {{ anagrafica.nome }}. La quota si chiude per l'importo
                        rimborsato; le rate emesse non cambiano. Si può stornare dalla riga dell'estratto conto.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="inviaRimborso">
                    <div class="space-y-1.5">
                        <Label class="text-xs font-bold uppercase tracking-wider text-slate-500">Credito da rimborsare</Label>
                        <v-select
                            :options="quoteRimborsabili"
                            v-model="formRimborso.rata_quote_id"
                            label="etichetta"
                            :reduce="(q: any) => q.id"
                            :clearable="false"
                            class="w-full min-w-0 bg-white text-sm shadow-sm"
                            placeholder="Scegli il credito…"
                            @update:modelValue="formRimborso.clearErrors('rata_quote_id')"
                        >
                            <template #option="{ credito_formattato, gestione, piano, rata, immobile, origine }">
                                <div class="flex flex-col py-0.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="font-bold text-sm text-slate-800">{{ credito_formattato }}</span>
                                        <span class="text-xs uppercase font-black px-1.5 py-0.5 rounded-md bg-slate-100 text-slate-500 border border-slate-200">{{ ETICHETTA_ORIGINE[origine] ?? origine }}</span>
                                    </div>
                                    <span class="text-xs text-slate-500 mt-0.5">{{ gestione ?? 'gestione' }}<template v-if="piano">, {{ piano }}</template><template v-if="rata !== null"> · rata {{ rata }}</template><template v-if="immobile"> · {{ immobile }}</template></span>
                                </div>
                            </template>
                            <!-- L'etichetta intera è lunga: nel campo si tronca, nel menu si legge per intero -->
                            <template #selected-option="{ etichetta }">
                                <span class="block truncate max-w-[24rem] text-sm">{{ etichetta }}</span>
                            </template>
                            <template #no-options><span class="text-xs text-slate-500">Nessun credito da rimborsare.</span></template>
                        </v-select>
                        <p v-if="quotaScelta" class="text-xs text-slate-500 leading-snug">
                            <template v-if="quotaScelta.origine === 'saldi' && quotaScelta.conto === 'passate_gestioni'">Credito riportato da un esercizio precedente, con la sua quota non ancora a giornale: in contabilità esce da «Passate gestioni».</template>
                            <template v-else-if="quotaScelta.origine === 'saldi'">Credito riportato da un esercizio precedente, portato a giornale con la sua rata: in contabilità rovescia l'accredito su «Crediti verso condomini».</template>
                            <template v-else-if="quotaScelta.origine === 'misto' && quotaScelta.conto === 'passate_gestioni'">Credito in parte riportato da un esercizio precedente e in parte delle entrate dell'anno, con la sua quota non ancora a giornale: in contabilità la parte riportata esce da «Passate gestioni», quella delle entrate da «Crediti verso condomini».</template>
                            <template v-else-if="(quotaScelta.origine === 'misto' || quotaScelta.origine === 'entrate') && quotaScelta.a_giornale">Credito delle entrate dell'anno{{ quotaScelta.origine === 'misto' ? ' e riportato da un esercizio precedente' : '' }}, portato a giornale con la sua rata: in contabilità rovescia l'accredito su «Crediti verso condomini».</template>
                            <template v-else-if="quotaScelta.origine === 'misto' || quotaScelta.origine === 'entrate'">Credito delle entrate dell'anno (le entrate del piano superano la spesa di questa quota), con la sua quota non ancora a giornale: in contabilità esce da «Crediti verso condomini», e la scrittura della quota, quando va a giornale, lo chiude.</template>
                            <template v-else>Credito da un versamento in eccesso: in contabilità rovescia l'accredito su «Crediti verso condomini».</template>
                        </p>
                        <p v-if="formRimborso.errors.rata_quote_id" class="text-xs text-red-600">{{ formRimborso.errors.rata_quote_id }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1.5 col-span-2 sm:col-span-1">
                            <Label class="text-xs font-bold uppercase tracking-wider text-slate-500">Cassa</Label>
                            <v-select
                                :options="rimborso.casse"
                                v-model="formRimborso.cassa_id"
                                label="nome"
                                :reduce="(c: any) => c.id"
                                class="w-full min-w-0 bg-white text-sm shadow-sm"
                                placeholder="Da dove esce il denaro?"
                                @update:modelValue="formRimborso.clearErrors('cassa_id')"
                            >
                                <template #option="{ nome, tipo, saldo_formattato, saldo_cents }">
                                    <div class="flex flex-col py-0.5">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-bold text-sm text-slate-800">{{ nome }}</span>
                                            <span class="text-xs uppercase font-black px-1.5 py-0.5 rounded-md bg-slate-100 text-slate-500 border border-slate-200">{{ tipo }}</span>
                                        </div>
                                        <span class="text-xs mt-0.5" :class="saldo_cents > 0 ? 'text-slate-500' : 'text-rose-600'">Saldo {{ saldo_formattato }}</span>
                                    </div>
                                </template>
                                <template #selected-option="{ nome, saldo_formattato }">
                                    <span class="text-sm">{{ nome }} <span class="text-slate-400">· {{ saldo_formattato }}</span></span>
                                </template>
                                <template #no-options><span class="text-xs text-slate-500">Nessuna cassa banca o contanti attiva.</span></template>
                            </v-select>
                            <p v-if="formRimborso.errors.cassa_id" class="text-xs text-red-600">{{ formRimborso.errors.cassa_id }}</p>
                        </div>
                        <div class="space-y-1.5 col-span-2 sm:col-span-1">
                            <Label class="text-xs font-bold uppercase tracking-wider text-slate-500">Data del rimborso</Label>
                            <Input type="date" v-model="formRimborso.data_rimborso" class="h-9 text-sm" />
                            <p v-if="formRimborso.errors.data_rimborso" class="text-xs text-red-600">{{ formRimborso.errors.data_rimborso }}</p>
                        </div>
                    </div>
                    <div class="space-y-1.5">
                        <Label class="text-xs font-bold uppercase tracking-wider text-slate-500">Importo</Label>
                        <div class="relative">
                            <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold">€</span>
                            <MoneyInput v-model="formRimborso.importo" :money-options="moneyOptionsRimborso" :lazy="true" class="h-9 pl-6 text-sm text-left" />
                        </div>
                        <p class="text-xs text-slate-500">Al massimo {{ quotaScelta?.credito_formattato ?? '—' }}, il credito disponibile su questa quota<template v-if="cassaScelta">; la cassa «{{ cassaScelta.nome }}» ha {{ cassaScelta.saldo_formattato }}</template>.</p>
                        <p v-if="formRimborso.errors.importo" class="text-xs text-red-600">{{ formRimborso.errors.importo }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label class="text-xs font-bold uppercase tracking-wider text-slate-500">Nota <span class="font-normal text-slate-400">(facoltativa)</span></Label>
                        <Input v-model="formRimborso.nota" placeholder="Es. bonifico del 20/09, IBAN comunicato via mail" class="h-9 text-sm" />
                    </div>
                    <DialogFooter class="gap-2">
                        <Button type="button" variant="outline" @click="rimborsoAperto = false">Annulla</Button>
                        <Button type="submit" :disabled="formRimborso.processing || !quotaScelta || !formRimborso.cassa_id">Registra il rimborso</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <LettereDialog v-model:open="finestraLettera" :condominio-id="Number(condominio.id)" :persona="{ id: Number(anagrafica.id), nome: anagrafica.nome }" :oggi="oggiDellaPagina"
            @fatto="router.reload({ only: ['lettere', 'ultima_lettera'] })" />

        <!-- Le due finestre degli addebiti, una volta sola: le aprono il menu «Azioni», la card «Addebiti personali» e l'elenco degli addebiti. -->
        <NuovoAddebitoDialog v-model:open="finestraAddebito" :condominio-id="Number(condominio.id)" :origine="origineAddebito" @fatto="dopoUnAddebito" />
        <AddebitaSpesaDialog v-model:open="finestraSpese" :condominio-id="Number(condominio.id)" :persone="personaDelleSpese" @fatto="dopoUnAddebito" />

        <EstrattoContoGuide v-model:open="mostraGuida" />

        <!-- Tutte le unità della persona, una per riga, con gli stessi badge della card. -->
        <Dialog :open="finestraUnita" @update:open="finestraUnita = $event">
            <DialogContent class="sm:max-w-md" data-testid="finestra-unita">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2"><Building2 class="w-5 h-5 text-gray-500" /> Unità immobiliari</DialogTitle>
                    <DialogDescription>Le {{ anagrafica.immobili.length }} unità di {{ anagrafica.nome }} in questo condominio, con la tipologia e la quota.</DialogDescription>
                </DialogHeader>
                <div class="max-h-[60vh] overflow-y-auto divide-y divide-gray-100 dark:divide-slate-800">
                    <div v-for="immobile in anagrafica.immobili" :key="immobile.id" class="flex items-center gap-2 min-w-0 py-2" data-testid="unita">
                        <span class="truncate flex-1 min-w-0 text-sm font-semibold text-gray-800 dark:text-slate-200" :title="formatIndirizzoImmobile(immobile)"
                            data-testid="nome-unita">{{ formatIndirizzoImmobile(immobile) }}</span>
                        <Badge variant="secondary" class="shrink-0 text-xs px-1.5 h-5">{{ immobile.pivot.tipologia }}</Badge>
                        <Badge variant="outline" class="shrink-0 text-xs px-1.5 h-5 text-gray-500 dark:text-slate-400">{{ percentuale(immobile.pivot.quota) }}</Badge>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :model-value="confermaRimborsoAperta"
            title="Rimborsi lo stesso?"
            confirm-text="Rimborsa"
            cancel-text="Non ora"
            variant="warning"
            @update:model-value="(v: boolean) => { confermaRimborsoAperta = v }"
            @confirm="confermaRimborso"
        >
            <span data-testid="conferma-rimborso">{{ anagrafica.nome }} ha {{ stats.credito_da_versare }} da versare. Rimborsi lo stesso?</span>
        </ConfirmDialog>
        <ConfirmDialog
            :model-value="stornoAperto"
            title="Stornare il rimborso?"
            confirm-text="Storna"
            cancel-text="Lascia com'è"
            variant="destructive"
            @update:model-value="(v: boolean) => { stornoAperto = v }"
            @confirm="stornaRimborso"
        >
            Il rimborso <strong>{{ stornoDaConfermare?.protocollo }}</strong> viene annullato con una scrittura di rettifica: nei libri il denaro
            torna in cassa e il credito torna disponibile sulla quota. Il movimento originale resta nel giornale come annullato.
        </ConfirmDialog>
    </GestionaleLayout>
</template>

<style scoped>
.overflow-y-auto::-webkit-scrollbar { width: 4px; }
.overflow-y-auto::-webkit-scrollbar-track { background: transparent; }
.overflow-y-auto::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 4px; }
.overflow-y-auto::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
</style>
