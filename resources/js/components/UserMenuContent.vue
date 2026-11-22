<!--
SPDX-License-Identifier: AGPL-3.0-or-later
Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.
-->
<script setup lang="ts">

import UserInfo from '@/components/UserInfo.vue';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { Link, router } from '@inertiajs/vue3';
import { LogOut, UserRound } from 'lucide-vue-next';
import { trans } from 'laravel-vue-i18n';
import type { User } from '@/types';
import { cancellaIlLavoroSenzaRete, contaIlLavoroNonArrivato } from '@/lib/senzaRete/uscita';

interface Props {
    user: User;
}

defineProps<Props>();

/**
 * «Esci»: prima il lavoro della sala tenuto in questo browser si cancella (le copie delle pagine, lo stato della sala, i gesti), così chi usa il browser dopo
 * non lo trova. I gesti della sala che non sono arrivati al server si cancellano solo dopo averlo chiesto.
 */
async function handleLogout() {
    const nonArrivati = await contaIlLavoroNonArrivato();
    if (nonArrivati > 0 && !globalThis.confirm?.(`${nonArrivati === 1 ? '1 gesto della sala non è ancora arrivato' : `${nonArrivati} gesti della sala non sono ancora arrivati`} `
        + 'al server: uscendo si cancellano da questo computer. Per mandarli, resta e aspetta la rete. Esci lo stesso?')) {
        return;
    }
    await cancellaIlLavoroSenzaRete({ ancheLaCoda: true });
    router.post(route('logout'), {}, {
        onSuccess: () => {
            router.flushAll()
        }
    })
}

</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full" :href="route('profile.edit')" as="button">
                <UserRound class="mr-2 h-4 w-4" />
                {{ trans('settings.layout.nav.profile') }}
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <button class="block w-full" @click="handleLogout">
            <LogOut class="mr-2 h-4 w-4" />
            {{ trans('auth.button.logout') }}
        </button>
    </DropdownMenuItem>
</template>
