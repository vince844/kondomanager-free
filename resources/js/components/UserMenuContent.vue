<script setup lang="ts">

import UserInfo from '@/components/UserInfo.vue';
import { DropdownMenuGroup, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator } from '@/components/ui/dropdown-menu';
import { Link, router } from '@inertiajs/vue3';
import { LogOut, CircleUserRound } from 'lucide-vue-next';
import type { User } from '@/types';

interface Props {
    user: User;
}

defineProps<Props>();

function handleLogout() {
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
                <CircleUserRound class="mr-2 h-4 w-4" />
                Profilo
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <button class="block w-full" @click="handleLogout">
            <LogOut class="mr-2 h-4 w-4" />
            Log out
        </button>
    </DropdownMenuItem>
</template>
