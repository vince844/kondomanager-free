<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import GestionaleLayout from '@/layouts/GestionaleLayout.vue';
import StrutturaLayout from '@/layouts/gestionale/StrutturaLayout.vue';
import PageHeaderGuide from '@/components/PageHeaderGuide.vue';
import GiaVersatoGuide from '@/components/guides/GiaVersatoGuide.vue';
import MoneyInput from '@/components/MoneyInput.vue';
import ModalLiquiditaGiaVersato from '@/components/gestionale/contributi/ModalLiquiditaGiaVersato.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { useToast } from '@/components/ui/toast';
import { useCurrencyFormatter } from '@/composables/useCurrencyFormatter';
import { usePermission } from '@/composables/permissions';
import type { Building } from '@/types/buildings';
import ScegliLaDelibera from '@/components/assemblee/ScegliLaDelibera.vue';
import { gestisceLeAssemblee } from '@/lib/assemblee/permessi';
import type { DeliberaDelMenu } from '@/types/assemblee';
import {
  Wallet, Scale, ArrowRight, Sparkles, CheckCircle2,
  AlertTriangle, Info, LoaderCircle, Building2,
} from 'lucide-vue-next';

interface Riga {
  /** `${immobile_id}:${anagrafica_id ?? 0}`: una riga per (unità, persona). */
  chiave: string;
  immobile_id: number;
  /** Chi ha versato (B2, decisione 17): nullo = «l'unità», la riga storica. */
  anagrafica_id: number | null;
  nome: string;
  interno: string | null;
  millesimi: number;
  quota_lorda: number;   // centesimi — della persona, se c'è; dell'unità altrimenti
  quota_lorda_unita: number;
  gia_versato: number;   // centesimi
}

/**
 * `periodi`: per chi oggi non è più titolare (ha venduto), quando lo è stato; vuoto per i titolari di oggi. `chiusa_il`: l'ultimo suo giorno.
 * `parti`: per esercizio, la parte della spesa dell'unità che tocca alla persona, con i giorni del motore (0,5 = metà anno al 100 %). Non sommano
 * sempre a uno: i giorni in cui il motore non trova nessun titolare sono a parte (`senza_titolare`).
 */
interface Titolare { id: number; nome: string | null; tipologia: string; quota: number; chiusa_il?: string | null; periodi?: Periodo[]; parti?: Record<number, number> }

/** `dal` nullo: da sempre. `al` nullo: una riga spenta senza data di fine. */
interface Periodo { dal: string | null; al: string | null }

/** Un esercizio del condominio a cui la gestione della voce è agganciata: le scelte di «Vale per» (Coda 438). */
interface EsercizioOpzione { id: number; nome: string; stato: string }

interface CassaOpzione {
  id: number;
  nome: string;
  tipo: string;
  saldo_cents: number;
  is_utilizzabile_per_imprevisti: boolean;
}

const props = defineProps<{
  condominio: Building;
  voce: { id: number; nome: string; importo_cents: number; gestione: string | null; gestione_id?: number | null };
  righe: Riga[];
  /** Le scelte di «Versato da», per unità: i titolari di diritto reale di oggi e, dopo, chi lo è stato. */
  titolari: Record<number, Titolare[]>;
  natura: 'fondo_vincolato' | 'avanzo';
  descrizione: string | null;
  // true quando questa voce ha ripartizioni per soggetto non standard
  // (proprietario/inquilino) o unità senza un proprietario attivo: la "Quota
  // dovuta" qui sotto è una stima sui soli millesimi, il motore di riparto reale
  // al momento della generazione del piano rate può calcolare un valore diverso.
  stima_semplificata: boolean;
  // true quando la voce ha una ripartizione per soggetto (proprietario/inquilino):
  // un «già versato» SENZA persona è dell'unità — un versamento fatto da uno solo
  // dei due finisce per scontare anche l'altro (D8). Con la persona (B2, decisione 17)
  // sconta solo il suo lordo. Non bloccato: solo segnalato per le righe senza persona.
  ripartizione_mista: boolean;
  // "Dove sono questi soldi?" (D8-bis). null finché non ancora dichiarato per
  // questa voce: in tal caso la modale si apre alla prima dichiarazione con
  // già-versato > 0 e non viene più richiesta sui salvataggi successivi.
  liquidita_stato: 'registrata_in_cassa' | 'gia_speso_acconto' | null;
  cassa_id: number | null;
  casse: CassaOpzione[];
  /** Coda 438: gli esercizi della gestione della voce, in ordine di data. */
  esercizi: EsercizioOpzione[];
  /**
   * Per quale esercizio vale il già versato: «tutti» (tutti i piani della voce, di ogni esercizio: il fondo lavori su più anni) o un esercizio.
   * Nullo quando la scelta manca: una voce ancora senza già versato su una gestione di più esercizi, o righe con esercizi diversi.
   */
  vale_per: 'tutti' | number | null;
  /** Le righe valgono per esercizi diversi (scritte fuori da questa pagina): i loro esercizi, a parole. */
  vale_per_diversi: string[];
  /** La delibera del già versato, facoltativa, se è collegata. */
  delibera?: DeliberaDelMenu | null;
  /** L'esercizio su cui si contano i giorni quando «Vale per» non ne sceglie uno. */
  esercizio_di_calcolo?: number | null;
  /** Per unità e per esercizio, la parte della spesa dei giorni senza nessun titolare: il piano non la chiede a nessuno. */
  senza_titolare?: Record<number, Record<number, number>>;
}>();

const { euro } = useCurrencyFormatter({ fromCents: true });
const permessi = usePermission();
const { generatePath } = permessi;
// La delibera del già versato, facoltativa (§1.4 del progetto della tappa 2): la sua frase va in testa alla nota.
const conLaDelibera = computed(() => gestisceLeAssemblee(permessi));
const delibera = ref<DeliberaDelMenu | null>(props.delibera ?? null);
const { toast } = useToast();

// ── Stato ──────────────────────────────────────────────────────────────────
// Gli importi si editano in EURO (stringa), si inviano in centesimi.
// Le righe sono locali: si può aggiungere una seconda persona sulla stessa unità (dopo una vendita il
// venditore e l'acquirente hanno ciascuno il proprio versato sulla voce), e cambiare «Versato da».
const righe = ref<Riga[]>(props.righe.map(r => ({ ...r })));
const versato = ref<Record<string, string>>(
  Object.fromEntries(props.righe.map(r => [r.chiave, (r.gia_versato / 100).toFixed(2)]))
);
const titolariDi = (immobileId: number): Titolare[] => props.titolari?.[immobileId] ?? [];
// La parte della persona nella spesa dell'unità, per giorni nell'esercizio su cui si calcola (`parteDi`); senza, la sua quota di possesso.
// La quota lorda della riga è la spesa dell'unità per quella parte: è il tetto che il piano applica al suo versato.
const parteDi = (immobileId: number, anagraficaId: number | null): number => {
  const t = anagraficaId ? titolariDi(immobileId).find(x => x.id === anagraficaId) : null;
  if (!t) return 1;
  const es = esercizioDiCalcolo.value;
  return es !== null && t.parti && t.parti[es] !== undefined ? t.parti[es] : t.quota / 100;
};
/** La parte della spesa dell'unità dei giorni senza nessun titolare, nell'esercizio su cui si calcola. */
const senzaTitolareDi = (immobileId: number): number => {
  const es = esercizioDiCalcolo.value;
  return es !== null ? props.senza_titolare?.[immobileId]?.[es] ?? 0 : 0;
};
const quotaLordaPer = (r: Riga, anagraficaId: number | null) =>
  anagraficaId ? Math.round(r.quota_lorda_unita * parteDi(r.immobile_id, anagraficaId)) : r.quota_lorda_unita;
const cambiaPersona = (r: Riga, anagraficaId: number | null) => {
  const nuovaChiave = `${r.immobile_id}:${anagraficaId ?? 0}`;
  if (righe.value.some(x => x !== r && x.chiave === nuovaChiave)) {
    toast({ title: 'Persona già presente', description: 'Questa persona ha già una riga su questa unità.', variant: 'destructive' });
    return;
  }
  versato.value[nuovaChiave] = versato.value[r.chiave] ?? '0.00';
  delete versato.value[r.chiave];
  r.chiave = nuovaChiave;
  r.anagrafica_id = anagraficaId;
  r.quota_lorda = quotaLordaPer(r, anagraficaId);
};
const ETICHETTE_RUOLO: Record<string, string> = { proprietario: 'proprietario', nuda_proprietario: 'nudo proprietario', usufruttuario: 'usufruttuario', inquilino: 'inquilino' };

/** Una persona di questa unità senza ancora la sua riga, di oggi o di prima (in elenco quelle di oggi vengono prima): solo allora «+ persona». */
const personaLibera = (immobileId: number): Titolare | undefined => {
  const usate = new Set(righe.value.filter(x => x.immobile_id === immobileId).map(x => x.anagrafica_id));
  return titolariDi(immobileId).find(t => !usate.has(t.id));
};

/** Il periodo di chi non è più titolare, scritto accanto al nome. */
const giorno = (d: string) => new Date(d + 'T00:00:00').toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' });
const periodiInParole = (periodi: Periodo[]) => periodi
  .map(p => p.al === null ? 'riga non attiva' : p.dal === null ? `fino al ${giorno(p.al)}` : `dal ${giorno(p.dal)} al ${giorno(p.al)}`)
  .join(' e ');

// ── Le voci dei due menu ───────────────────────────────────────────────────
type OpzioneValePer = { valore: number | 'tutti'; etichetta: string };
type OpzionePersona = { valore: number; etichetta: string };
const opzioniValePer = computed<OpzioneValePer[]>(() => [
  ...props.esercizi.map(e => ({ valore: e.id, etichetta: e.nome })),
  { valore: 'tutti', etichetta: 'Tutti gli esercizi' },
]);

/** «L'unità (senza persona)»: nel menu vale 0, perché un valore nullo si legge come nessuna scelta. */
const SENZA_PERSONA = 0;
const etichettaTitolare = (t: Titolare) => `${t.nome ?? ''} · ${ETICHETTE_RUOLO[t.tipologia] ?? t.tipologia} ${t.quota} %`
  + (t.periodi?.length ? ` · ${periodiInParole(t.periodi)}` : '');
const opzioniVersatoDa = (immobileId: number): OpzionePersona[] => [
  { valore: SENZA_PERSONA, etichetta: 'L\'unità (senza persona)' },
  ...titolariDi(immobileId).map(t => ({ valore: t.id, etichetta: etichettaTitolare(t) })),
];
/** La scelta mostrata: nulla se la persona della riga non è fra le scelte (il menu resta vuoto). */
const sceltaDi = (r: Riga): number | null => r.anagrafica_id === null ? SENZA_PERSONA
  : titolariDi(r.immobile_id).some(t => t.id === r.anagrafica_id) ? r.anagrafica_id : null;
const scegliPersona = (chiave: string, valore: number) =>
  cambiaPersona(righe.value.find(x => x.chiave === chiave)!, valore === SENZA_PERSONA ? null : valore);

/** Il menu della tabella si apre nel corpo della pagina (la tabella scorre di lato e lo taglierebbe), largo come il campo, con le voci lunghe a capo. */
const menuSulCorpo = (lista: HTMLElement, _componente: unknown, { width, top, left }: { width: string; top: string; left: string }) => {
  lista.classList.add('menu-versato-da');
  Object.assign(lista.style, { top, left, width });
};

const aggiungiPersona = (r: Riga) => {
  const usate = new Set(righe.value.filter(x => x.immobile_id === r.immobile_id).map(x => x.anagrafica_id));
  const libera = personaLibera(r.immobile_id);
  const anagraficaId = libera ? libera.id : (usate.has(null) ? null : null);
  if (!libera && usate.has(null)) return;
  const nuova: Riga = { ...r, chiave: `${r.immobile_id}:${anagraficaId ?? 0}`, anagrafica_id: anagraficaId, quota_lorda: quotaLordaPer(r, anagraficaId), gia_versato: 0 };
  const idx = righe.value.lastIndexOf(righe.value.filter(x => x.immobile_id === r.immobile_id).slice(-1)[0]);
  righe.value.splice(idx + 1, 0, nuova);
  versato.value[nuova.chiave] = '0.00';
};
const togliRiga = (r: Riga) => {
  if (righe.value.filter(x => x.immobile_id === r.immobile_id).length <= 1) return;
  righe.value = righe.value.filter(x => x !== r);
  delete versato.value[r.chiave];
};

// La guida in header: qui si prendono le due decisioni che il programma non può dedurre
// (natura del versamento e stato della liquidità), ed era l'unica delle tre pagine del
// già-versato a non averla — vedi GiaVersatoGuide, scheda «Le scelte».
const mostraGuida = ref(false);

const natura = ref(props.natura);
// Coda 438: una scelta per voce, come la natura. Si rimanda a ogni salvataggio: il backend sostituisce le righe della voce.
const valePer = ref<'tutti' | number | null>(props.vale_per);
// L'esercizio su cui si contano i giorni: quello scelto, altrimenti quello che il server indica.
const esercizioDiCalcolo = computed<number | null>(() => typeof valePer.value === 'number' ? valePer.value : (props.esercizio_di_calcolo ?? null));
const piuEsercizi = computed(() => props.esercizi.length > 1);
// Il riquadro dell'effetto dice per quali piani vale il già versato (Coda 438): con un esercizio scelto lo tolgono solo i suoi piani,
// e il «prossimo piano rate» può essere quello di un altro esercizio.
const titoloEffetto = computed(() => {
  if (typeof valePer.value === 'number') {
    const esercizio = props.esercizi.find(e => e.id === valePer.value);
    if (esercizio) return `Effetto sui piani rate di «${esercizio.nome}»`;
  }
  if (valePer.value === 'tutti' && piuEsercizi.value) return 'Effetto sui piani rate della voce, in tutti gli esercizi';
  return 'Effetto sul prossimo piano rate';
});
// Riparte da quanto già salvato: senza questo, ogni ri-salvataggio che non
// tocca il campo lo azzererebbe (il backend sostituisce, non fa merge).
// Con la delibera collegata, la nota a video è il testo scritto dopo la sua frase: il server la rimette in testa.
const descrizione = ref(props.delibera && (props.descrizione ?? '').startsWith(props.delibera.frase)
  ? (props.descrizione ?? '').slice(props.delibera.frase.length).replace(/^[\s—-]+/, '') : (props.descrizione ?? ''));
const totaleRaccolto = ref('');
const salvando = ref(false);

// ── "Dove sono questi soldi?" (D8-bis) ──────────────────────────────────────
// Una volta dichiarato (qui o in un salvataggio precedente), va RIMANDATO ad
// ogni resave: il backend sostituisce integralmente le righe di questa voce,
// quindi se non lo reinviamo la dichiarazione andrebbe persa silenziosamente
// e il salvataggio successivo la richiederebbe di nuovo da capo.
const liquiditaStato = ref(props.liquidita_stato);
const cassaIdSelezionata = ref(props.cassa_id);
// Solo per la primissima dichiarazione: non è persistita sulla riga, serve
// solo a comporre il promemoria Inbox — non va rimandata sui resave.
const notaAccontoTransiente = ref('');
const mostraModaleLiquidita = ref(false);

// Formato italiano, identico al resto del gestionale: senza queste opzioni v-money3
// usa i suoi default inglesi (decimale ".", migliaia ",") e `parse` sotto leggerebbe
// "1,500.00" come 1,50 — un errore di mille volte sull'importo.
const moneyOptions = ref({
  prefix: '',
  suffix: '',
  thousands: '.',
  decimal: ',',
  precision: 2,
  allowBlank: true,
  masked: true,
  disableNegative: true,
});

const parse = (v: string | number | undefined): number => {
  if (v === undefined || v === null || v === '') return 0;
  if (typeof v === 'number') return v;
  const s = String(v).trim();
  const n = s.includes(',')
    ? Number(s.replace(/\./g, '').replace(',', '.'))
    : Number(s);
  return Number.isFinite(n) ? n : 0;
};

const cents = (v: string | number | undefined) => Math.round(parse(v) * 100);

// ── Il gesto che fa risparmiare tempo ──────────────────────────────────────
// L'accantonamento si raccoglie quasi sempre per millesimi: l'amministratore
// digita il totale una volta sola e il sistema lo ripartisce, penny-perfect.
// I millesimi si contano una volta per unità, anche con più righe (persone) sulla stessa unità.
const millesimiTotali = computed(() => Object.values(Object.fromEntries(righe.value.map(r => [r.immobile_id, r.millesimi]))).reduce((a, m) => a + m, 0));
// Il peso di una riga: i millesimi dell'unità, divisi fra le sue righe per la parte di ciascuna (a «l'unità» la parte che non è di una riga
// con la persona). Con due righe sulla stessa unità l'unità pesa sempre i suoi millesimi, non il doppio.
const pesoRiga = (r: Riga) => {
  const sue = righe.value.filter(x => x.immobile_id === r.immobile_id);
  const persone = sue.filter(x => x.anagrafica_id !== null).reduce((a, x) => a + parteDi(x.immobile_id, x.anagrafica_id), 0);
  const parte = (x: Riga) => x.anagrafica_id === null ? Math.max(0, 1 - persone - senzaTitolareDi(x.immobile_id)) : parteDi(x.immobile_id, x.anagrafica_id);
  const somma = sue.reduce((a, x) => a + parte(x), 0);
  return somma > 0 ? r.millesimi * parte(r) / somma : r.millesimi / sue.length;
};

const ripartisciPerMillesimi = () => {
  const totale = cents(totaleRaccolto.value);
  const pesoTotale = righe.value.reduce((a, r) => a + pesoRiga(r), 0);
  if (totale <= 0 || pesoTotale <= 0) return;

  let assegnato = 0;
  righe.value.forEach((r, i) => {
    const quota = i === righe.value.length - 1
      ? totale - assegnato                                     // l'ultimo assorbe il resto
      : Math.floor((totale * pesoRiga(r)) / pesoTotale);
    assegnato += quota;
    versato.value[r.chiave] = (quota / 100).toFixed(2);
  });
};

const azzera = () => {
  righe.value.forEach(r => { versato.value[r.chiave] = '0.00'; });
};

// ── Effetto sul riparto, calcolato mentre si digita ─────────────────────────
// Unità per unità, nell'ordine del piano: prima il versato dell'unità (senza persona) sulla spesa dell'unità, diviso fra tutti per la loro quota
// lorda; poi quello di ciascuna persona sulla sua quota già ridotta, e ciò che avanza è suo (eccedenza). Chi non ha una riga paga la sua parte:
// «resto» è ciò che si chiede a chi è titolare negli altri giorni (dopo una vendita, chi ha comprato). Il totale dell'unità è la sua spesa, una volta.
// La parte dei giorni senza nessun titolare («senza») il piano non la chiede a nessuno: il versato dell'unità sconta solo la parte di chi c'è.
interface RigaCalcolata extends Riga { versato_cents: number; residuo: number; eccedenza: number }
interface Unita { lordo: number; versato: number; residuo: number; eccedenza: number; senza: number; resto: { quota: number; residuo: number } | null }

const calcolo = computed(() => {
  const perUnita = new Map<number, Riga[]>();
  righe.value.forEach(r => { perUnita.set(r.immobile_id, [...(perUnita.get(r.immobile_id) ?? []), r]); });
  const calcolate = new Map<string, RigaCalcolata>();
  const unita = new Map<number, Unita>();
  perUnita.forEach((sue, immobileId) => {
    const lordoSpesa = sue[0].quota_lorda_unita;
    const senza = Math.min(lordoSpesa, Math.round(lordoSpesa * senzaTitolareDi(immobileId)));
    const lordoUnita = lordoSpesa - senza;
    const dellUnita = sue.find(r => r.anagrafica_id === null);
    const versatoUnita = dellUnita ? cents(versato.value[dellUnita.chiave]) : 0;
    const applicataUnita = Math.min(versatoUnita, lordoUnita);
    let applicatePersone = 0, residuiPersone = 0, lordiPersone = 0, eccedenze = versatoUnita - applicataUnita, versati = versatoUnita;
    sue.filter(r => r.anagrafica_id !== null).forEach(r => {
      const lordo = quotaLordaPer(r, r.anagrafica_id);
      const ridotta = lordoUnita > 0 ? Math.max(0, lordo - Math.floor(applicataUnita * lordo / lordoUnita)) : 0;
      const v = cents(versato.value[r.chiave]);
      const applicata = Math.min(v, ridotta);
      calcolate.set(r.chiave, { ...r, quota_lorda: lordo, versato_cents: v, residuo: ridotta - applicata, eccedenza: v - applicata });
      applicatePersone += applicata; residuiPersone += ridotta - applicata; lordiPersone += lordo; eccedenze += v - applicata; versati += v;
    });
    const residuoUnita = Math.max(0, lordoUnita - applicataUnita - applicatePersone);
    const altri = Math.max(0, residuoUnita - residuiPersone);
    if (dellUnita) {
      calcolate.set(dellUnita.chiave, { ...dellUnita, quota_lorda: lordoUnita, versato_cents: versatoUnita, residuo: altri, eccedenza: versatoUnita - applicataUnita });
    }
    unita.set(immobileId, {
      lordo: lordoSpesa, versato: versati, residuo: residuoUnita, eccedenza: eccedenze, senza,
      resto: !dellUnita && lordiPersone < lordoUnita ? { quota: lordoUnita - lordiPersone, residuo: altri } : null,
    });
  });
  return { righe: righe.value.map(r => calcolate.get(r.chiave)!), unita };
});

const righeCalcolate = computed(() => calcolo.value.righe);
/** Dopo l'ultima riga di un'unità, la parte di chi non ha una riga. */
const ultimaDellUnita = (r: Riga) => {
  const sue = righe.value.filter(x => x.immobile_id === r.immobile_id);
  return sue[sue.length - 1]?.chiave === r.chiave;
};
const restoDopo = (r: Riga) => ultimaDellUnita(r) ? calcolo.value.unita.get(r.immobile_id)?.resto ?? null : null;
/** Dopo l'ultima riga di un'unità, la parte dei giorni senza nessun titolare, se ce n'è. */
const senzaDopo = (r: Riga): number => ultimaDellUnita(r) ? calcolo.value.unita.get(r.immobile_id)?.senza ?? 0 : 0;

const totali = computed(() => {
  const u = [...calcolo.value.unita.values()];
  return {
    lordo:     u.reduce((a, x) => a + x.lordo, 0),
    versato:   u.reduce((a, x) => a + x.versato, 0),
    residuo:   u.reduce((a, x) => a + x.residuo, 0),
    eccedenza: u.reduce((a, x) => a + x.eccedenza, 0),
    senza:     u.reduce((a, x) => a + x.senza, 0),
  };
});

const scarto = computed(() => cents(totaleRaccolto.value) - totali.value.versato);
const haScarto = computed(() => cents(totaleRaccolto.value) > 0 && scarto.value !== 0);

// ── Salvataggio ────────────────────────────────────────────────────────────
// La domanda "dove sono questi soldi?" va posta una volta sola, alla prima
// dichiarazione di un già versato > 0 per questa voce: né prima (non c'è
// ancora nulla da localizzare), né sui resave successivi (il backend la
// ignorerebbe comunque — vedi ContributoVersatoController::update()).
const richiedeDichiarazioneLiquidita = computed(() =>
  liquiditaStato.value === null && totali.value.versato > 0
);

const salva = () => {
  if (valePer.value === null) {
    toast({ title: 'Scegli l\'esercizio', description: 'Scegli per quale esercizio vale il già versato.', variant: 'destructive' });
    return;
  }
  if (richiedeDichiarazioneLiquidita.value) {
    mostraModaleLiquidita.value = true;
    return;
  }
  eseguiSalvataggio();
};

const onConfirmLiquidita = (payload: {
  liquiditaStato: 'registrata_in_cassa' | 'gia_speso_acconto';
  cassaId: number | null;
  notaAcconto: string;
}) => {
  liquiditaStato.value = payload.liquiditaStato;
  cassaIdSelezionata.value = payload.cassaId;
  notaAccontoTransiente.value = payload.notaAcconto;
  mostraModaleLiquidita.value = false;
  eseguiSalvataggio();
};

const eseguiSalvataggio = () => {
  salvando.value = true;
  router.put(
    generatePath('gestionale/:condominio/contributi/:conto', {
      condominio: props.condominio.id,
      conto: props.voce.id,
    }),
    {
      natura: natura.value,
      vale_per: valePer.value,
      descrizione: descrizione.value || null,
      // Senza delibera la chiave non parte: le righe si riscrivono e i legami di prima se ne vanno con loro.
      ...(conLaDelibera.value && delibera.value ? { delibera_id: delibera.value.id } : {}),
      righe: righe.value.map(r => ({
        immobile_id: r.immobile_id,
        anagrafica_id: r.anagrafica_id,
        gia_versato: cents(versato.value[r.chiave]),
      })),
      liquidita_stato: liquiditaStato.value,
      cassa_id: liquiditaStato.value === 'registrata_in_cassa' ? cassaIdSelezionata.value : null,
      nota_acconto: notaAccontoTransiente.value || null,
    },
    {
      preserveScroll: true,
      onSuccess: () => {
        // Consumata: non deve essere rimandata su un eventuale prossimo resave.
        notaAccontoTransiente.value = '';
        toast({ title: 'Salvato', description: 'Contributi già versati aggiornati.', variant: 'default' });
      },
      // Senza questo handler un 422 (validazione fallita, es. un immobile non
      // di questo condominio) non dava alcun segnale: il pulsante si
      // riabilitava e la pagina restava lì, senza dire perché nulla è cambiato.
      onError: (errors) => {
        const primo = Object.values(errors)[0];
        toast({
          title: 'Salvataggio non riuscito',
          description: typeof primo === 'string' ? primo : 'Controlla i dati inseriti e riprova.',
          variant: 'destructive',
        });
        // Se l'errore riguarda proprio la dichiarazione (es. cassa_id), la
        // dichiarazione locale non era in realtà completa: la si azzera così
        // il prossimo tentativo di salvataggio riapre la modale invece di
        // ripetere silenziosamente lo stesso invio incompleto.
        if (errors.cassa_id || errors.nota_acconto) {
          liquiditaStato.value = null;
        }
      },
      onFinish: () => { salvando.value = false; },
    }
  );
};

const headerBreadcrumbs = computed(() => [
  { title: 'Gestionale', href: generatePath('gestionale/:condominio', { condominio: props.condominio.id }) },
  { title: 'Struttura', href: '#' },
  { title: 'Già versato', href: '#' },
]);

const guide = [
  {
    title: 'A cosa serve',
    description: 'Registra quanto ogni unità ha già versato per questa spesa, tipicamente un accantonamento raccolto prima di passare a Kondomanager.',
    icon: Wallet,
    colorVariant: 'emerald' as const,
  },
  {
    title: 'Effetto immediato',
    description: 'Il riparto chiederà a ciascuno solo il residuo. Senza questo dato, la spesa verrebbe richiesta una seconda volta.',
    icon: Scale,
    colorVariant: 'blue' as const,
  },
  {
    title: 'Compilazione rapida',
    description: 'Se conosci solo il totale raccolto, digitalo una volta sola: lo distribuiamo per millesimi, penny-perfect. Ogni riga resta comunque correggibile a mano.',
    icon: Sparkles,
    colorVariant: 'amber' as const,
  },
];
</script>

<template>
  <Head :title="`Già versato — ${voce.nome}`" />

  <GestionaleLayout>
    <div class="px-6 py-8 space-y-6">

      <PageHeaderGuide
        :storia="props.voce.gestione_id ? { tipo: 'gestione', id: props.voce.gestione_id } : null"
        page-title="Quanto è già stato versato"
        :page-subtitle="`Voce di spesa: ${voce.nome}`"
        :guides="guide"
        :breadcrumbs="headerBreadcrumbs"
        :condominio="condominio"
        has-text-guide
        text-guide-title="Guida"
        @open-text-guide="mostraGuida = true"
      />

      <div class="w-full">
        <StrutturaLayout>
          <div class="container mx-auto p-0 mt-4 space-y-6">

      <!-- ═══ 1. NATURA — è una qualificazione giuridica, non un'etichetta ═══ -->
      <div class="bg-white dark:bg-slate-900 border rounded-2xl p-6 dark:border-slate-800">
        <div class="flex items-start gap-2 mb-4">
          <span class="text-xs font-black uppercase tracking-widest text-slate-500 dark:text-slate-400">
            1. Che cosa sono questi versamenti?
          </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <label
            class="flex flex-col p-5 rounded-xl border cursor-pointer transition-all"
            :class="natura === 'fondo_vincolato'
              ? 'bg-emerald-50 dark:bg-emerald-950/30 border-emerald-400 ring-1 ring-emerald-400 shadow-sm'
              : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800/60'">
            <div class="flex items-start justify-between mb-2">
              <span class="font-bold text-sm"
                :class="natura === 'fondo_vincolato' ? 'text-emerald-800 dark:text-emerald-200' : 'text-slate-700 dark:text-slate-200'">
                Fondo deliberato
              </span>
              <input type="radio" v-model="natura" value="fondo_vincolato"
                class="w-4 h-4 mt-0.5 text-emerald-600 dark:text-emerald-400 focus:ring-emerald-600" />
            </div>
            <p class="text-xs leading-relaxed"
              :class="natura === 'fondo_vincolato' ? 'text-emerald-700/80 dark:text-emerald-300/80' : 'text-slate-500 dark:text-slate-400'">
              L'assemblea ha costituito un fondo per quest'opera (art. 1135 c.c.).
              Le somme hanno un <strong>vincolo di destinazione</strong>: non possono
              essere usate per altro senza una nuova delibera.
            </p>
          </label>

          <label
            class="flex flex-col p-5 rounded-xl border cursor-pointer transition-all"
            :class="natura === 'avanzo'
              ? 'bg-blue-50 dark:bg-blue-950/30 border-blue-400 ring-1 ring-blue-400 shadow-sm'
              : 'border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800/60'">
            <div class="flex items-start justify-between mb-2">
              <span class="font-bold text-sm"
                :class="natura === 'avanzo' ? 'text-blue-800 dark:text-blue-200' : 'text-slate-700 dark:text-slate-200'">
                Rate già riscosse
              </span>
              <input type="radio" v-model="natura" value="avanzo"
                class="w-4 h-4 mt-0.5 text-blue-600 focus:ring-blue-600" />
            </div>
            <p class="text-xs leading-relaxed"
              :class="natura === 'avanzo' ? 'text-blue-700/80 dark:text-blue-300/80' : 'text-slate-500 dark:text-slate-400'">
              Somme incassate dai condòmini ma non ancora spese, <strong>senza un fondo
              deliberato</strong>. Restano conguagliabili a fine gestione.
            </p>
          </label>
        </div>

        <div class="mt-4 flex gap-2.5 p-3 bg-slate-50 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800 rounded-xl">
          <Info class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" />
          <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
            La distinzione la stabilisce la <strong>delibera</strong>, non il software:
            attribuire un vincolo che l'assemblea non ha deliberato — o ignorarne uno
            esistente — è un problema legale, non contabile.
          </p>
        </div>

        <div v-if="ripartizione_mista"
          class="mt-3 flex gap-2.5 p-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 rounded-xl">
          <AlertTriangle class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
          <p class="text-xs text-amber-800 dark:text-amber-200 leading-relaxed">
            Questa voce ha una <strong>ripartizione per soggetto</strong> (proprietario/
            inquilino). Una riga registrata come «L'unità» viene sottratta dal totale
            dell'immobile <strong>prima</strong> di dividerlo fra proprietario e inquilino,
            quindi sconta entrambi. Se il versamento è di una persona sola, scegli chi in
            «Versato da»: lo sconto va solo alla sua quota, e l'eventuale avanzo resta a suo nome.
          </p>
        </div>
      </div>

      <!-- ═══ Coda 438: PER QUALE ESERCIZIO VALE — un fatto che sa solo l'amministratore ═══ -->
      <div class="bg-white dark:bg-slate-900 border rounded-2xl p-6 dark:border-slate-800">
        <div class="flex items-start gap-2 mb-1">
          <span class="text-xs font-black uppercase tracking-widest text-slate-500 dark:text-slate-400">
            Per quale esercizio vale?
          </span>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
          Il piano rate di un esercizio toglie solo il già versato di quell'esercizio. Con «Tutti gli esercizi» lo tolgono tutti i piani della
          voce, ognuno per la sua parte: è il caso di un fondo lavori che si chiede in più anni.
        </p>
        <div class="flex flex-col sm:flex-row gap-3 sm:items-center">
          <Label for="vale-per" class="text-xs font-bold text-slate-600 dark:text-slate-300">Vale per</Label>
          <div class="menu-campo menu-vale-per w-full max-w-xs text-sm">
            <v-select input-id="vale-per" v-model="valePer" :options="opzioniValePer" label="etichetta" :reduce="(o: OpzioneValePer) => o.valore"
              :clearable="false" :searchable="false" placeholder="Scegli l'esercizio…" class="w-full bg-white dark:bg-slate-950" />
          </div>
        </div>
        <div v-if="vale_per_diversi.length > 0"
          class="mt-3 flex gap-2.5 p-3 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 rounded-xl">
          <AlertTriangle class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
          <p class="text-xs text-amber-800 dark:text-amber-200 leading-relaxed">
            Le righe di questa voce valgono per esercizi diversi: {{ vale_per_diversi.join(', ') }}. Salvando, varranno tutte per l'esercizio che scegli qui.
          </p>
        </div>
        <div v-else-if="piuEsercizi && valePer === 'tutti'"
          class="mt-3 flex gap-2.5 p-3 bg-slate-50 dark:bg-slate-800/40 border border-slate-100 dark:border-slate-800 rounded-xl">
          <Info class="w-4 h-4 text-slate-400 shrink-0 mt-0.5" />
          <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
            La gestione di questa voce è in più esercizi: con «Tutti gli esercizi» i piani di ogni esercizio si dividono questo già versato, e la loro
            somma sulla voce non può superare l'importo della voce. Se il versamento era per la spesa di un anno solo, scegli quell'esercizio.
          </p>
        </div>
      </div>

      <!-- ═══ 2. IL GESTO CHE FA RISPARMIARE TEMPO ═══ -->
      <div class="bg-gradient-to-br from-indigo-50 dark:from-indigo-950/40 to-white dark:to-slate-900 border border-indigo-100 dark:border-indigo-900 rounded-2xl p-6">
        <div class="flex items-center gap-2 mb-1">
          <Sparkles class="w-4 h-4 text-indigo-500" />
          <span class="text-xs font-black uppercase tracking-widest text-indigo-600 dark:text-indigo-400">
            2. Compilazione rapida
          </span>
        </div>
        <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
          L'accantonamento si raccoglie quasi sempre per millesimi: digita il totale una
          volta sola e lo distribuiamo noi, al centesimo.
        </p>

        <div class="flex flex-col sm:flex-row gap-3 sm:items-end">
          <div class="flex-1 max-w-xs">
            <Label class="text-xs font-bold text-slate-600 dark:text-slate-300">Totale già raccolto</Label>
            <div class="relative mt-1">
              <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-bold">€</span>
              <MoneyInput v-model="totaleRaccolto" :money-options="moneyOptions"
                class="pl-7 h-10 font-bold" />
            </div>
          </div>
          <Button type="button" class="h-10 gap-2" @click="ripartisciPerMillesimi">
            <Scale class="w-4 h-4" /> Ripartisci per millesimi
          </Button>
          <Button type="button" variant="outline" class="h-10" @click="azzera">
            Azzera
          </Button>
        </div>

        <div v-if="haScarto"
          class="mt-3 flex items-center gap-2 text-xs font-bold px-3 py-2 rounded-lg"
          :class="scarto > 0 ? 'text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900'
                             : 'text-rose-700 dark:text-rose-300 bg-rose-50 dark:bg-rose-950/30 border border-rose-200 dark:border-rose-900'">
          <AlertTriangle class="w-3.5 h-3.5" />
          <span v-if="scarto > 0">
            Mancano {{ euro(scarto) }} rispetto al totale dichiarato.
          </span>
          <span v-else>
            Hai distribuito {{ euro(-scarto) }} in più rispetto al totale dichiarato.
          </span>
        </div>
      </div>

      <!-- ═══ 3. TABELLA CON EFFETTO IN TEMPO REALE ═══ -->
      <div class="bg-white dark:bg-slate-900 border rounded-2xl overflow-hidden dark:border-slate-800">
        <div class="px-6 py-4 border-b bg-slate-50/60 dark:bg-slate-800/40 flex items-center justify-between">
          <div>
            <span class="text-xs font-black uppercase tracking-widest text-slate-500 dark:text-slate-400">
              3. Dettaglio per unità
            </span>
            <p class="text-xs text-slate-400 mt-0.5">
              Spesa totale {{ euro(voce.importo_cents) }} — puoi correggere i singoli importi
            </p>
          </div>
        </div>

        <div v-if="stima_semplificata"
          class="mx-6 mt-4 flex items-start gap-2 text-xs text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-900 rounded-lg px-3 py-2.5">
          <AlertTriangle class="w-3.5 h-3.5 shrink-0 mt-0.5" />
          <span>
            Questa voce ha ripartizioni per soggetto (proprietario/inquilino) o unità
            senza un proprietario attivo: la <strong>quota dovuta</strong> qui sotto è
            una stima sui soli millesimi. Il piano rate reale, generato dal motore di
            riparto, può calcolare un importo diverso per le unità coinvolte — il
            "già versato" resta comunque corretto e verrà applicato regolarmente.
          </span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-xs uppercase tracking-wide text-slate-400 border-b">
                <th class="text-left px-6 py-3 font-bold">Unità</th>
                <th class="text-left px-3 py-3 font-bold">Versato da</th>
                <th class="text-right px-3 py-3 font-bold">Millesimi</th>
                <th class="text-right px-3 py-3 font-bold">Quota dovuta</th>
                <th class="text-right px-3 py-3 font-bold">Già versato</th>
                <th class="text-right px-6 py-3 font-bold bg-emerald-50/60 dark:bg-emerald-950/30 text-emerald-700 dark:text-emerald-400">
                  Da chiedere ora
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
              <template v-for="r in righeCalcolate" :key="r.chiave">
              <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                <td class="px-6 py-2.5">
                  <div class="flex items-center gap-2">
                    <Building2 class="w-3.5 h-3.5 text-slate-300" />
                    <div>
                      <div class="font-semibold text-slate-800 dark:text-slate-100 text-xs">{{ r.nome }}</div>
                      <div v-if="r.interno" class="text-xs text-slate-400">Int. {{ r.interno }}</div>
                    </div>
                  </div>
                </td>
                <!-- B2, decisione 17: chi ha versato. «L'unità» è la riga storica (sconta tutti i soggetti, D8);
                     con la persona lo sconto va solo al suo lordo. Dopo i titolari di oggi c'è chi lo è stato, con il periodo. -->
                <td class="px-3 py-2.5">
                  <div class="flex items-center gap-1.5">
                    <div class="menu-campo w-64 shrink-0 text-xs" :title="opzioniVersatoDa(r.immobile_id).find(o => o.valore === sceltaDi(r))?.etichetta">
                      <v-select :model-value="sceltaDi(r)" :options="opzioniVersatoDa(r.immobile_id)" label="etichetta" :reduce="(o: OpzionePersona) => o.valore"
                        :clearable="false" :searchable="false" append-to-body :calculate-position="menuSulCorpo" class="w-full bg-white dark:bg-slate-950"
                        @update:model-value="(v: number) => scegliPersona(r.chiave, v)" />
                    </div>
                    <button v-if="personaLibera(r.immobile_id)" type="button" @click="aggiungiPersona(righe.find(x => x.chiave === r.chiave)!)" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline whitespace-nowrap" title="Un'altra persona ha versato su questa unità">+ persona</button>
                    <button v-if="righe.filter(x => x.immobile_id === r.immobile_id).length > 1" type="button" @click="togliRiga(righe.find(x => x.chiave === r.chiave)!)" class="text-xs text-slate-400 hover:text-rose-600">togli</button>
                  </div>
                </td>
                <td class="px-3 py-2.5 text-right text-xs text-slate-500 dark:text-slate-400 tabular-nums">
                  {{ r.millesimi }}
                </td>
                <td class="px-3 py-2.5 text-right text-xs text-slate-600 dark:text-slate-300 tabular-nums">
                  {{ euro(r.quota_lorda) }}
                </td>
                <td class="px-3 py-2.5">
                  <div class="relative w-32 ml-auto">
                    <span class="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold">€</span>
                    <MoneyInput v-model="versato[r.chiave]" :money-options="moneyOptions"
                      class="pl-5 h-8 text-right text-xs font-semibold" />
                  </div>
                </td>
                <td class="px-6 py-2.5 text-right bg-emerald-50/40 dark:bg-emerald-950/20">
                  <span class="font-bold text-sm tabular-nums"
                    :class="r.residuo === 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-800 dark:text-slate-100'">
                    {{ euro(r.residuo) }}
                  </span>
                  <div v-if="r.eccedenza > 0" class="text-xs text-amber-600 font-semibold mt-0.5">
                    +{{ euro(r.eccedenza) }} in eccesso
                  </div>
                </td>
              </tr>
              <!-- La parte dell'unità di chi non ha una riga: la paga lui, come nel piano (dopo una vendita, chi ha comprato per i suoi giorni). -->
              <tr v-if="restoDopo(r)" data-testid="resto-dell-unita" class="text-xs text-slate-500 dark:text-slate-400">
                <td class="px-6 py-2"></td>
                <td class="px-3 py-2" colspan="2">Gli altri titolari dell'unità, per la loro parte</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ euro(restoDopo(r)!.quota) }}</td>
                <td class="px-3 py-2"></td>
                <td class="px-6 py-2 text-right tabular-nums font-bold text-slate-800 dark:text-slate-100 bg-emerald-50/40 dark:bg-emerald-950/20">{{ euro(restoDopo(r)!.residuo) }}</td>
              </tr>
              <!-- I giorni in cui il piano non trova nessun titolare: la loro parte non si chiede a nessuno, va fra le quote non assegnate. -->
              <tr v-if="senzaDopo(r) > 0" data-testid="senza-titolare-dell-unita" class="text-xs text-slate-500 dark:text-slate-400">
                <td class="px-6 py-2"></td>
                <td class="px-3 py-2" colspan="2">Giorni senza titolare: non si chiedono a nessuno, il piano li mette fra le quote non assegnate</td>
                <td class="px-3 py-2 text-right tabular-nums">{{ euro(senzaDopo(r)) }}</td>
                <td class="px-3 py-2"></td>
                <td class="px-6 py-2 text-right tabular-nums text-slate-400 bg-emerald-50/40 dark:bg-emerald-950/20">—</td>
              </tr>
              </template>
            </tbody>
            <tfoot>
              <tr class="border-t-2 border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/40 font-bold text-xs">
                <td class="px-6 py-3" colspan="2">Totali</td>
                <td class="px-3 py-3 text-right tabular-nums text-slate-500 dark:text-slate-400">{{ millesimiTotali.toFixed(2) }}</td>
                <td class="px-3 py-3 text-right tabular-nums">{{ euro(totali.lordo) }}</td>
                <td class="px-3 py-3 text-right tabular-nums text-indigo-700 dark:text-indigo-300">{{ euro(totali.versato) }}</td>
                <td class="px-6 py-3 text-right tabular-nums text-emerald-700 dark:text-emerald-400 bg-emerald-50 dark:bg-emerald-950/30">
                  {{ euro(totali.residuo) }}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- ═══ 4. IL PRIMA/DOPO — la conseguenza, prima di salvare ═══ -->
      <div class="bg-slate-900 rounded-2xl p-6 text-white relative overflow-hidden">
        <div class="absolute inset-0 opacity-[0.07]"
          style="background-image:linear-gradient(rgba(255,255,255,.5) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.5) 1px,transparent 1px);background-size:28px 28px"></div>

        <div class="relative z-10">
          <span class="text-xs font-black uppercase tracking-widest text-slate-400">
            {{ titoloEffetto }}
          </span>

          <div class="mt-4 flex flex-col sm:flex-row items-stretch sm:items-center gap-4">
            <div class="flex-1 bg-white/5 border border-white/10 rounded-xl p-4">
              <div class="text-xs uppercase tracking-wide text-slate-400 font-bold">Senza questo dato</div>
              <div class="text-2xl font-black mt-1 tabular-nums text-rose-300">{{ euro(totali.lordo - totali.senza) }}</div>
              <div class="text-xs text-slate-400 mt-1">richiesti ai condòmini</div>
            </div>

            <ArrowRight class="w-6 h-6 text-slate-500 dark:text-slate-400 mx-auto shrink-0 rotate-90 sm:rotate-0" />

            <div class="flex-1 bg-emerald-500/10 border border-emerald-400/30 rounded-xl p-4">
              <div class="text-xs uppercase tracking-wide text-emerald-300/80 font-bold">Con il già versato</div>
              <div class="text-2xl font-black mt-1 tabular-nums text-emerald-300">{{ euro(totali.residuo) }}</div>
              <div class="text-xs text-emerald-200/60 mt-1">solo il residuo dovuto</div>
            </div>
          </div>

          <div v-if="totali.versato > 0"
            class="mt-4 flex items-center gap-2 text-xs text-slate-300">
            <CheckCircle2 class="w-4 h-4 text-emerald-400 shrink-0" />
            <span>
              <strong class="text-white">{{ euro(totali.versato) }}</strong> già versati non
              verranno richiesti una seconda volta.
            </span>
          </div>

          <div v-if="totali.eccedenza > 0"
            class="mt-2 flex items-center gap-2 text-xs text-amber-300">
            <AlertTriangle class="w-4 h-4 shrink-0" />
            <span>
              <strong>{{ euro(totali.eccedenza) }}</strong> versati in eccesso: da restituire
              o conguagliare, non vengono persi.
            </span>
          </div>
        </div>
      </div>

      <!-- ═══ 5. NOTA + SALVA ═══ -->
      <div class="bg-white dark:bg-slate-900 border rounded-2xl p-6 flex flex-col sm:flex-row gap-4 sm:items-end dark:border-slate-800">
        <div v-if="conLaDelibera" class="flex-1 max-w-xl" data-testid="gia-versato-con-la-delibera">
          <ScegliLaDelibera v-model="delibera" :condominio-id="condominio.id" :condominio-nome="condominio.nome" ruolo="nota" etichetta="La delibera (facoltativa)"
            per-questo="questa voce" :con-memo="false" />
        </div>
        <div class="flex-1">
          <Label class="text-xs font-bold text-slate-600 dark:text-slate-300">
            {{ delibera ? 'Nota (facoltativa): la frase della delibera ci va in testa' : 'Nota (facoltativa) — es. estremi della delibera' }}
          </Label>
          <Input v-model="descrizione" class="mt-1 h-10 text-sm"
            placeholder="Delibera assembleare del 12/05/2025" />
        </div>
        <Button class="h-11 px-8 font-bold gap-2" :disabled="salvando" @click="salva">
          <LoaderCircle v-if="salvando" class="w-4 h-4 animate-spin" />
          <CheckCircle2 v-else class="w-4 h-4" />
          Salva
        </Button>
      </div>

          </div>
        </StrutturaLayout>
      </div>

    </div>

    <ModalLiquiditaGiaVersato
      :show="mostraModaleLiquidita"
      :totale-gia-versato="totali.versato"
      :casse="casse"
      :natura="natura"
      :is-processing="salvando"
      @update:show="mostraModaleLiquidita = $event"
      @confirm="onConfirmLiquidita"
      @open-guida="mostraGuida = true"
    />
    <GiaVersatoGuide v-model:open="mostraGuida" />
  </GestionaleLayout>
</template>

<style scoped>
.menu-vale-per :deep(.vs__dropdown-toggle) {
  min-height: 2.5rem;
}
</style>

<style>
/* Il menu di «Versato da» sta nel corpo della pagina, fuori dal riquadro del campo. */
.menu-versato-da {
  font-size: 0.75rem;
  max-height: 16rem;
}
.menu-versato-da .vs__dropdown-option {
  white-space: normal;
}
</style>
