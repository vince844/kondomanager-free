<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La sala chiusa, com'era (decisione 660): a sala senza una seduta aperta, ma con una seduta tenuta, la pagina mostra senza nessun gesto le presenze
 * dell'ultima seduta (chi è entrato, come partecipava, a che ora è entrato e a che ora è uscito) e i punti con l'esito e i conti (favorevoli, contrari,
 * astenuti, per teste e millesimi; «Trattato» per quelli senza voto); chi è entrato con chi rappresenta la sua unità, presente senza voto (rilievo C53). Nessun «Entra», «Esce», «Si vota» o «Conferma»: chi vuole correggere qualcosa lo fa dal
 * verbale. Dal tablet in su una tabella, al telefono una scheda per riga.
 *
 * **I testi di ogni punto** (la prova a video del 22/11/2026, notte: chi guardava la sala chiusa credeva che fossero persi), sotto l'esito e i conti, in sola
 * lettura: la discussione scritta in sala (dal verbale della riunione, `ultima_seduta.discussioni`; chiusa su questo computer, quella che la sala aveva), le
 * note del presidente sul voto e su chi non ha votato, il motivo dell'esito scelto dal presidente («Un altro esito»), le dichiarazioni a verbale con chi le
 * ha fatte. Sono gli stessi testi che il verbale generato riporta (`ComposizioneDelVerbale`).
 */
import { computed } from 'vue';
import TestoDelVerbale from '@/components/assemblee/verbale/TestoDelVerbale.vue';
import { BADGE, CLASSI_DEL_BADGE, coloreDellEsito } from '@/lib/assemblee/assemblee';
import { millesimi } from '@/lib/assemblee/sala/conti';
import { testo, type NodoDelVerbale } from '@/lib/assemblee/verbale/struttura';
import type { PresenzaDellaSala, PuntoDellaSala, VotazioneDellaSala } from '@/types/assemblee';
import { useSala } from './contesto';
import { elencoDeiNomi, giornoDellaSeduta, oraDiRoma, parolaDellEsito } from './sala';

const { sala } = useSala();

/** Le presenze dell'ultima seduta: quelle lette dal server a sala chiusa, o (la chiusura segnata su questo computer) quelle che la pagina ha già. */
const presenze = computed<PresenzaDellaSala[]>(() => sala.value.ultima_seduta?.presenze ?? sala.value.presenze);
const seduta = computed(() => sala.value.sedute.find((s) => s.id === sala.value.ultima_seduta?.seduta_id)
    ?? [...sala.value.sedute].filter((s) => s.aperta_il !== null).sort((a, b) => (b.aperta_il ?? '').localeCompare(a.aperta_il ?? ''))[0] ?? null);
const deleghe = computed(() => sala.value.deleghe.filter((d) => d.stato === 'valida'));

interface Riga { chiave: string; nome: string; come: string; entrata: string; uscita: string; nota: string | null }

const righe = computed<Riga[]>(() => presenze.value.map((p) => {
    const perConto = deleghe.value.filter((d) => d.chiave_delegato === p.chiave).map((d) => d.delegante_nome);
    const inCollegamento = p.come === 'in_collegamento';

    return {
        chiave: `${p.id}-${p.chiave}`, nome: p.nome, come: inCollegamento ? 'In collegamento' : 'In sala',
        entrata: oraDiRoma(p.entrata_il), uscita: oraDiRoma(p.uscita_il),
        // I contitolari entrati con chi rappresenta l'unità, presenti senza voto (rilievo C53).
        nota: [perConto.length ? `per delega di ${perConto.join(', ')}` : null, p.insieme?.length ? `con ${elencoDeiNomi(p.insieme.map((x) => x.nome))}, `
            + (p.insieme.length === 1 ? 'contitolare, presente senza voto' : 'contitolari, presenti senza voto') : null, p.caduto ? 'collegamento caduto' : null]
            .filter(Boolean).join(' · ') || null,
    };
}));

/** Le teste della costituzione che non c'erano: nessuna loro persona in sala, e nessuna delega valida a chi c'era. */
const nonPresenti = computed(() => {
    const chiavi = new Set(presenze.value.map((p) => p.chiave));
    const persone = new Set<number>(presenze.value.map((p) => p.anagrafica_id).filter((x): x is number => x !== null));
    for (const d of deleghe.value) if (chiavi.has(d.chiave_delegato) && d.delegante_anagrafica_id !== null) persone.add(d.delegante_anagrafica_id);

    return (sala.value.fotografia?.costituzione.teste ?? []).filter((t) => !t.persone.some((p) => persone.has(p))).map((t) => t.nome);
});

const votazioniDelPunto = (p: PuntoDellaSala): VotazioneDellaSala[] => p.votazioni.filter((v) => v.conteggi !== null);
const etichetta = (v: VotazioneDellaSala) => (v.oggetto === 'riparto' ? 'Il riparto' : v.oggetto === 'rendiconto' ? 'Il rendiconto' : null);
const trattato = (p: PuntoDellaSala) => p.maggioranza === 'senza_voto';
const esitoDelPunto = (p: PuntoDellaSala) => p.esito ?? p.votazioni.at(-1)?.esito ?? null;
const conti = (v: VotazioneDellaSala) => [
    { chiave: 'favorevoli', nome: 'Favorevoli', g: v.conteggi!.favorevoli }, { chiave: 'contrari', nome: 'Contrari', g: v.conteggi!.contrari },
    { chiave: 'astenuti', nome: 'Astenuti', g: v.conteggi!.astenuti },
];
const COLORE: Record<string, keyof typeof CLASSI_DEL_BADGE> = { 'In sala': 'verde', 'In collegamento': 'blu' };

/** La discussione del punto scritta in sala: quella che il server ha letto dal verbale; chiusa su questo computer, quella che la sala aveva. Vuota, niente. */
function discussioneDi(p: PuntoDellaSala): NodoDelVerbale | null {
    const chiave = String(p.id);
    const dalServer = sala.value.ultima_seduta?.discussioni;
    const d = (dalServer && !Array.isArray(dalServer) ? dalServer[chiave] : undefined) ?? sala.value.verbale?.discussioni?.[chiave];
    const documento = (d?.documento ?? null) as NodoDelVerbale | null;
    try {
        return documento && testo(documento).trim() !== '' ? documento : null;
    } catch {
        return null;
    }
}
/** Le dichiarazioni a verbale del punto, con chi le ha fatte. */
const dichiarazioniDi = (p: PuntoDellaSala) => sala.value.dichiarazioni.filter((d) => d.punto_id === p.id);
const NOTA = 'text-xs text-slate-700 dark:text-slate-300';
</script>

<template>
    <div class="space-y-6" data-testid="sala-in-sola-lettura">
        <section class="space-y-3" data-testid="lettura-presenze">
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">
                Le presenze<template v-if="seduta"> · {{ seduta.convocazione === 'prima' ? 'prima' : 'seconda' }} convocazione, {{ giornoDellaSeduta(seduta.giorno) }}</template>
            </h2>
            <p v-if="righe.length === 0" class="text-sm text-slate-600 dark:text-slate-400">Nessuno è entrato in sala.</p>

            <div v-else class="hidden overflow-x-auto rounded-xl border border-slate-200 bg-white sm:block dark:border-slate-800 dark:bg-slate-950" data-testid="lettura-presenze-tabella">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-100 text-left text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        <tr>
                            <th class="px-3 py-2 font-medium">Chi</th>
                            <th class="px-3 py-2 font-medium">Come partecipa</th>
                            <th class="px-3 py-2 font-medium">Entrata</th>
                            <th class="px-3 py-2 font-medium">Uscita</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="r in righe" :key="r.chiave" data-testid="lettura-riga">
                            <td class="px-3 py-2 align-top font-medium text-slate-900 dark:text-white">{{ r.nome }}</td>
                            <td class="px-3 py-2 align-top">
                                <span :class="[BADGE, CLASSI_DEL_BADGE[COLORE[r.come] ?? 'neutro']]">{{ r.come }}</span>
                                <p v-if="r.nota" class="text-xs text-slate-500 dark:text-slate-400">{{ r.nota }}</p>
                            </td>
                            <td class="px-3 py-2 align-top tabular-nums text-slate-700 dark:text-slate-300">{{ r.entrata }}</td>
                            <td class="px-3 py-2 align-top tabular-nums text-slate-700 dark:text-slate-300">{{ r.uscita }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <ul v-if="righe.length > 0" class="space-y-2 sm:hidden" data-testid="lettura-presenze-schede">
                <li v-for="r in righe" :key="r.chiave" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 dark:border-slate-800 dark:bg-slate-950" data-testid="lettura-scheda">
                    <div class="flex items-start justify-between gap-2">
                        <p class="min-w-0 break-words font-medium text-slate-900 dark:text-white">{{ r.nome }}</p>
                        <span :class="[BADGE, CLASSI_DEL_BADGE[COLORE[r.come] ?? 'neutro'], 'shrink-0']">{{ r.come }}</span>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Entrata {{ r.entrata }}<template v-if="r.uscita"> · uscita {{ r.uscita }}</template></p>
                    <p v-if="r.nota" class="text-xs text-slate-500 dark:text-slate-400">{{ r.nota }}</p>
                </li>
            </ul>

            <p v-if="nonPresenti.length > 0" class="text-xs text-slate-600 dark:text-slate-400" data-testid="lettura-non-presenti">Non c'erano: {{ nonPresenti.join(', ') }}.</p>
        </section>

        <section class="space-y-3" data-testid="lettura-punti">
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">I punti</h2>
            <ul class="space-y-3">
                <li v-for="p in sala.punti" :key="p.id" class="space-y-2 rounded-xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-950"
                    :data-testid="`lettura-punto-${p.numero}`">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <p class="min-w-0 break-words text-sm font-medium text-slate-900 dark:text-white">Punto {{ p.numero }} · {{ p.testo }}</p>
                        <span v-if="esitoDelPunto(p)" :class="[BADGE, CLASSI_DEL_BADGE[coloreDellEsito(esitoDelPunto(p))], 'shrink-0']" data-testid="lettura-esito">
                            {{ parolaDellEsito(esitoDelPunto(p)) }}</span>
                        <span v-else :class="[BADGE, CLASSI_DEL_BADGE.neutro, 'shrink-0']" data-testid="lettura-esito">Senza esito</span>
                    </div>
                    <p v-if="trattato(p)" class="text-xs text-slate-600 dark:text-slate-400">Trattato, senza voto.</p>
                    <div v-for="v in votazioniDelPunto(p)" :key="v.id" data-testid="lettura-conti">
                        <p v-if="etichetta(v)" class="mb-1 text-xs text-slate-500 dark:text-slate-400">{{ etichetta(v) }}</p>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <div v-for="c in conti(v)" :key="c.chiave" class="rounded-lg border border-slate-200 px-3 py-2 dark:border-slate-800">
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ c.nome }}</p>
                                <p class="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">
                                    {{ c.g.teste }} {{ c.g.teste === 1 ? 'testa' : 'teste' }} · {{ millesimi(c.g.valore) }} millesimi</p>
                            </div>
                        </div>
                        <!-- Le note del presidente e il motivo dell'esito: vanno nel verbale, qui si leggono. -->
                        <div v-if="v.nota || v.nota_non_votato || v.motivo" class="mt-2 space-y-1">
                            <p v-if="v.nota" :class="NOTA" data-testid="lettura-nota"><b class="font-semibold">Nota del presidente sul voto:</b> {{ v.nota }}</p>
                            <p v-if="v.nota_non_votato" :class="NOTA" data-testid="lettura-nota-non-votato"><b class="font-semibold">Su chi non ha votato:</b> {{ v.nota_non_votato }}</p>
                            <p v-if="v.motivo" :class="NOTA" data-testid="lettura-motivo"><b class="font-semibold">Il motivo dell'esito:</b> {{ v.motivo }}</p>
                        </div>
                    </div>
                    <!-- La discussione scritta in sala, com'è nel verbale. -->
                    <div v-if="discussioneDi(p)" class="space-y-1" data-testid="lettura-discussione">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">La discussione</p>
                        <TestoDelVerbale :documento="discussioneDi(p)" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-900/40" />
                    </div>
                    <ul v-if="dichiarazioniDi(p).length" class="space-y-1">
                        <li v-for="d in dichiarazioniDi(p)" :key="d.id" :class="[NOTA, 'break-words']" data-testid="lettura-dichiarazione">
                            <b class="font-semibold">Dichiarazione a verbale · {{ d.nome }}:</b> «{{ d.testo }}»</li>
                    </ul>
                </li>
            </ul>
        </section>
    </div>
</template>
