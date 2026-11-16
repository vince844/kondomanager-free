<script setup lang="ts">
/**
 * Il calendario di un accordo di rientro, come lo legge il server: per ogni rata il giorno, l'importo e quanto è versato (in ordine, la più vecchia
 * prima; un centesimo scoperto la lascia non versata), la regola di decadenza e i suoi segnali, con il giorno in cui la condizione si è verificata e le
 * cifre di quel giorno. Il segnale non decide niente: la decadenza la registra l'amministratore. Un accordo senza calendario non mostra niente.
 */
import { computed } from 'vue';
import { euroTesto, giorno } from '@/lib/gestionale/debiti/rateScadute';
import { testoRataDelCalendario, testoRegola, testoSegnale, type AccordoRientro, type Segnale } from '@/lib/gestionale/debiti/accordiRientro';

const props = defineProps<{ accordo: AccordoRientro }>();

/** I segnali, dal più vecchio. */
const segnali = computed(() => {
    const s = props.accordo.segnali;
    const tutti: Array<{ forma: 'rate' | 'giorni'; segnale: Segnale }> = [];
    if (s?.giorni) tutti.push({ forma: 'giorni', segnale: s.giorni });
    if (s?.rate) tutti.push({ forma: 'rate', segnale: s.rate });

    return tutti.sort((a, b) => a.segnale.il.localeCompare(b.segnale.il));
});
</script>

<template>
    <div v-if="accordo.calendario" class="space-y-1" data-testid="calendario-accordo">
        <table class="w-full text-xs">
            <thead>
                <tr class="text-left text-slate-500">
                    <th class="py-0.5 pr-2 font-normal">Rata</th>
                    <th class="py-0.5 pr-2 font-normal">Il giorno</th>
                    <th class="py-0.5 pr-2 text-right font-normal">Importo</th>
                    <th class="py-0.5 font-normal">Versato</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in accordo.calendario" :key="r.numero" data-testid="rata-calendario">
                    <td class="py-0.5 pr-2">{{ r.numero }}</td>
                    <td class="py-0.5 pr-2 whitespace-nowrap">{{ giorno(r.data) }}</td>
                    <td class="py-0.5 pr-2 text-right whitespace-nowrap">{{ euroTesto(r.importo) }}</td>
                    <td class="py-0.5" :class="!r.versata && r.scaduta ? 'text-amber-800 dark:text-amber-300' : ''">{{ testoRataDelCalendario(r) }}</td>
                </tr>
            </tbody>
        </table>
        <p class="text-slate-600 dark:text-slate-300" data-testid="regola-accordo">{{ testoRegola(accordo.regola) }}</p>
        <p v-for="s in segnali" :key="s.forma" class="rounded-md border border-amber-200 bg-amber-50 p-1.5 text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100"
            data-testid="segnale-decadenza">
            {{ testoSegnale(s.forma, s.segnale, accordo.regola) }}
        </p>
    </div>
</template>
