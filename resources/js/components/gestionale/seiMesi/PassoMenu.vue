<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * La prossima azione di una persona nel riquadro dei sei mesi (a video «la prossima azione»; nel codice il passo della regola). Con «Il programma suggerisce la
 * prossima azione» un pulsante diviso: a sinistra l'azione suggerita (piena), a destra la freccia che apre il menu, con in testa l'azione e «suggerita», una
 * linea, le altre possibili. Con «Scelgo io la prossima azione», o quando la regola non suggerisce niente (l'accordo in corso, il termine da aspettare),
 * «Scegli l'azione» con il bordo e lo stesso menu, senza la testa. Tutti e due larghi quanto la colonna, così sono uguali in ogni riga. È un menu di azioni,
 * non una scelta da un elenco: l'azione scelta apre la sua finestra o la sua pagina, e niente parte da solo.
 */
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { ChevronDown } from 'lucide-vue-next';
import { NOMI_DEI_PASSI, PASSI_CON_UNA_PORTA, type Passo, type PassoDellaPersona } from '@/lib/gestionale/debiti/seiMesiPerAgire';

const props = withDefaults(defineProps<{
    passo: PassoDellaPersona;
    /** «Il programma propone il passo» nelle impostazioni dello studio. */
    proposto: boolean;
    /** I passi che oggi aprono qualcosa. */
    disponibili?: Passo[];
}>(), { disponibili: () => PASSI_CON_UNA_PORTA });
const emit = defineEmits<{ (e: 'scegli', passo: Passo): void }>();

const proposta = computed<Passo | null>(() => (props.proposto && props.passo.passo && props.disponibili.includes(props.passo.passo) ? props.passo.passo : null));
const altri = computed<Passo[]>(() => props.passo.possibili.filter((p) => props.disponibili.includes(p) && p !== proposta.value));
</script>

<template>
    <div class="inline-flex w-full" data-testid="passo-menu">
        <template v-if="proposta">
            <Button size="sm" class="h-8 min-w-0 flex-1 rounded-r-none px-3 text-xs" :title="NOMI_DEI_PASSI[proposta]" data-testid="passo-proposto" @click="emit('scegli', proposta)">
                <span class="truncate">{{ NOMI_DEI_PASSI[proposta] }}</span>
            </Button>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button size="sm" class="h-8 w-8 shrink-0 rounded-l-none border-l border-primary-foreground/30 px-0" aria-label="Le altre azioni" data-testid="freccia-passi">
                        <ChevronDown class="h-3.5 w-3.5" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="w-60">
                    <DropdownMenuItem class="text-xs font-medium" @select="emit('scegli', proposta)">
                        {{ NOMI_DEI_PASSI[proposta] }}<span class="ml-auto text-xs font-normal text-slate-400">suggerita</span>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator v-if="altri.length" />
                    <DropdownMenuItem v-for="p in altri" :key="p" class="text-xs" @select="emit('scegli', p)">{{ NOMI_DEI_PASSI[p] }}</DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>
        <DropdownMenu v-else>
            <DropdownMenuTrigger as-child>
                <Button size="sm" variant="outline" class="h-8 w-full gap-1 px-3 text-xs" data-testid="scegli-passo">
                    Scegli l'azione <ChevronDown class="h-3.5 w-3.5" data-testid="freccia-passi" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-60">
                <DropdownMenuItem v-for="p in altri" :key="p" class="text-xs" @select="emit('scegli', p)">{{ NOMI_DEI_PASSI[p] }}</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
