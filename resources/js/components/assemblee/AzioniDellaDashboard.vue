<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">
/**
 * Il menu «Azioni» nell'intestazione della dashboard del condominio (decisione 574, scena 2 della bozza): un pulsante solo, alto `h-8` come «Guida», al
 * posto di «Da portare in assemblea», così con un nome di condominio lungo la fila non va a capo. Dentro oggi c'è «Da portare in assemblea»; le prossime
 * azioni veloci si aggiungono qui, una voce alla volta, ognuna con la sua decisione.
 *
 * La finestra del memo sta fuori dal menu e si apre quando il menu si chiude; il menu non è modale perché la finestra prenda il fuoco (come il menu
 * «Registro»). Lo vede chi gestisce le assemblee: senza, il menu non c'è.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { CalendarPlus, ChevronDown, Zap } from 'lucide-vue-next';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import FinestraDelMemo from '@/components/assemblee/FinestraDelMemo.vue';
import { useToast } from '@/components/ui/toast';
import { usePermission } from '@/composables/permissions';
import { Permission } from '@/enums/Permission';

const props = defineProps<{ condominioId: number }>();

const { hasPermission } = usePermission();
const { toast } = useToast();
const gestisce = computed(() => hasPermission([Permission.MANAGE_ASSEMBLEE]));
const memo = ref(false);

/** Salvato il memo, il messaggio; e la card «Per la prossima assemblea» si rilegge, perché il punto nuovo ci compaia. */
function salvato(frase: string) {
    toast({ title: 'Da portare in assemblea', description: frase });
    router.reload({ only: ['prossimaAssemblea'] });
}

const classePulsante = 'inline-flex h-8 items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 shadow-sm transition-colors hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800';
</script>

<template>
    <template v-if="gestisce">
        <DropdownMenu :modal="false">
            <DropdownMenuTrigger as-child>
                <button type="button" :class="classePulsante" data-testid="menu-azioni">
                    <Zap class="h-3.5 w-3.5 text-slate-500" /> Azioni <ChevronDown class="h-3.5 w-3.5 text-slate-400" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-60">
                <DropdownMenuItem class="text-xs" data-testid="azione-da-portare" @select="memo = true">
                    <CalendarPlus class="h-3.5 w-3.5" /> Da portare in assemblea
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
        <FinestraDelMemo v-model:open="memo" :condominio-id="props.condominioId" @salvato="salvato" />
    </template>
</template>
