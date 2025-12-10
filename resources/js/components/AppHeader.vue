<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Sheet, SheetContent, SheetHeader, SheetTrigger } from '@/components/ui/sheet';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { getInitials } from '@/composables/useInitials';
import { Link, usePage } from '@inertiajs/vue3';
import { Settings, GitGraph, BookText, Folders, LayoutGrid, Menu, CalendarClock, House, SquareLibrary, Tags, LayoutDashboard, ChevronRight, BriefcaseBusiness, UsersRound } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { usePermission } from "@/composables/permissions";
import { Permission } from '@/enums/Permission';
import type { BreadcrumbItem, NavItem, LinkItem, Auth} from '@/types';

const { generatePath, canAccess, hasPermission } = usePermission();

interface Props { breadcrumbs?: BreadcrumbItem[]; }

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage();
const auth = computed<Auth>(() => page.props.auth as Auth);

// Active route helper
const isCurrentRoute = (url?: string) => url && page.url === url;

// Parent active
const isParentActive = (item: NavItem) =>
    item.type === 'parent' &&
    item.children.some(child => canAccess(child) && isCurrentRoute(child.href));

// Children filter
const getAccessibleChildren = (item: NavItem): LinkItem[] =>
    item.type === 'parent' ? item.children.filter(child => canAccess(child)) : [];

// Main menu items
const mainNavItems: NavItem[] = [
    { type: 'link', title: 'Dashboard', href: generatePath('dashboard'), icon: LayoutGrid },
    { type: 'link', title: 'Condomini', href: '/condomini', icon: House, permissions: [Permission.VIEW_CONDOMINI] },
    {
        type: 'parent',
        title: 'Rubrica',
        icon: SquareLibrary,
        children: [
            { type: 'link', title: 'Anagrafiche', icon: UsersRound, href: generatePath('anagrafiche'), permissions: [Permission.VIEW_ANAGRAFICHE] },
            { type: 'link', title: 'Fornitori', icon: BriefcaseBusiness, href: generatePath('fornitori'), permissions: [Permission.VIEW_ANAGRAFICHE] },
        ]
    },
    { type: 'link', title: 'Bacheca', href: generatePath('comunicazioni'), icon: LayoutDashboard, permissions: [Permission.VIEW_COMUNICAZIONI] },
    { type: 'link', title: 'Segnalazioni', href: generatePath('segnalazioni'), icon: Tags, permissions: [Permission.VIEW_SEGNALAZIONI] },
    {
        type: 'link',
        title: 'Documenti',
        href: hasPermission([Permission.ACCESS_ADMIN_PANEL]) ? generatePath('documenti') : generatePath('categorie-documenti'),
        icon: Folders,
        permissions: [Permission.VIEW_ARCHIVE_DOCUMENTS],
    },
    { type: 'link', title: 'Agenda', href: generatePath('eventi'), icon: CalendarClock, permissions: [Permission.VIEW_EVENTS] },
];

// Right menu items
const rightNavItems = computed(() =>
    [
        { type: 'link', title: 'Repository', href: 'https://github.com/vince844/kondomanager-free', icon: GitGraph, external: true },
        { type: 'link', title: 'Documentazione', href: 'https://kondomanager.com/docs/index.html', icon: BookText, external: true },
        { type: 'link', title: 'Impostazioni', href: '/impostazioni', icon: Settings, permissions: [Permission.MANAGE_GENERAL_SETTINGS] },
    ].filter(item => canAccess(item))
);

const accessibleMainItems = computed(() =>
    mainNavItems.filter(item =>
        canAccess(item) &&
        (item.type === 'link' || getAccessibleChildren(item).length > 0)
    )
);

// Mobile expand
const expandedMobileItems = ref<Set<string>>(new Set());
const toggleMobileItem = (title: string) =>
    expandedMobileItems.value.has(title)
        ? expandedMobileItems.value.delete(title)
        : expandedMobileItems.value.add(title);
const isMobileItemExpanded = (title: string) =>
    expandedMobileItems.value.has(title);
</script>

<template>
    <div>
        <!-- HEADER -->
        <div class="border-b border-sidebar-border/80 bg-background/95 backdrop-blur">
            <div class="mx-auto flex h-16 items-center px-4 md:max-w-7xl">

                <!-- MOBILE MENU -->
                <div class="lg:hidden">
                    <Sheet>
                        <SheetTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="mr-2 h-9 w-9"
                            >
                                <Menu class="h-5 w-5" />
                            </Button>
                        </SheetTrigger>

                        <!-- MOBILE DRAWER -->
                        <SheetContent side="left" class="w-[300px] p-6">
                            <SheetHeader>
                                <AppLogoIcon class="size-6 text-black dark:text-white" />
                            </SheetHeader>

                            <div class="flex flex-col gap-3 py-6">
                                <!-- MOBILE: MAIN LINKS -->
                                <template v-for="item in accessibleMainItems" :key="item.title">
                                    <nav v-if="item.type === 'parent'">
                                        <button
                                            @click="toggleMobileItem(item.title)"
                                            class="w-full flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium hover:bg-accent"
                                            :class="isParentActive(item) ? 'bg-accent text-accent-foreground' : ''"
                                        >
                                            <div class="flex items-center gap-3">
                                                <component v-if="item.icon" :is="item.icon" class="h-5 w-5" />
                                                {{ item.title }}
                                            </div>
                                            <ChevronRight :class="isMobileItemExpanded(item.title) ? 'rotate-90' : ''" class="transition-transform" />
                                        </button>

                                        <div v-show="isMobileItemExpanded(item.title)" class="mt-1 space-y-1 pl-6">
                                            <Link
                                                v-for="child in getAccessibleChildren(item)"
                                                :key="child.title"
                                                :href="child.href"
                                                class="block rounded-lg px-3 py-2 text-sm hover:bg-accent"
                                                :class="isCurrentRoute(child.href) ? 'bg-accent text-accent-foreground' : ''"
                                            >
                                                {{ child.title }}
                                            </Link>
                                        </div>
                                    </nav>

                                    <Link
                                        v-else
                                        :href="item.href"
                                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm hover:bg-accent"
                                        :class="isCurrentRoute(item.href) ? 'bg-accent text-accent-foreground' : ''"
                                    >
                                        <component v-if="item.icon" :is="item.icon" class="h-5 w-5" />
                                        {{ item.title }}
                                    </Link>
                                </template>

                                <!-- MOBILE RIGHT NAV -->
                                <div class="my-4 h-px bg-border" />

                                <template v-for="item in rightNavItems" :key="item.title">
                                    <a
                                        v-if="item.external"
                                        :href="item.href"
                                        target="_blank"
                                        rel="noopener"
                                        class="flex items-center gap-3 px-3 py-2 text-sm hover:bg-accent rounded-lg"
                                    >
                                        <component :is="item.icon" class="h-5 w-5" />
                                        {{ item.title }}
                                    </a>

                                    <Link
                                        v-else
                                        :href="item.href"
                                        class="flex items-center gap-3 px-3 py-2 text-sm hover:bg-accent rounded-lg"
                                    >
                                        <component :is="item.icon" class="h-5 w-5" />
                                        {{ item.title }}
                                    </Link>
                                </template>
                            </div>
                        </SheetContent>
                    </Sheet>
                </div>

                <!-- LOGO -->
                <Link :href="generatePath('dashboard')" class="flex items-center gap-2 hover:opacity-80">
                    <AppLogo class="hidden h-6 xl:block" />
                </Link>

                <!-- DESKTOP MENU -->
                <div class="hidden lg:flex lg:flex-1 h-full">
                    <nav class="ml-6 flex items-center gap-1 h-full">
                        <template v-for="item in accessibleMainItems" :key="item.title">

                            <!-- DESKTOP PARENT -->
                            <HoverCard v-if="item.type === 'parent'" :open-delay="120" :close-delay="120">
                                <HoverCardTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        class="h-9 px-3 flex items-center text-sm font-medium"
                                        :class="isParentActive(item) ? 'bg-accent text-accent-foreground' : 'hover:bg-accent'"
                                    >
                                        <component v-if="item.icon" :is="item.icon" class="mr-2 h-4 w-4" />
                                        {{ item.title }}
                                    </Button>
                                </HoverCardTrigger>

                                <HoverCardContent align="start" class="w-48 p-2">
                                    <nav class="space-y-1">
                                        <Link
                                            v-for="child in getAccessibleChildren(item)"
                                            :key="child.title"
                                            :href="child.href"
                                            class="flex items-center text-sm font-medium px-3 py-2 rounded-md text-sm hover:bg-accent"
                                            :class="isCurrentRoute(child.href) ? 'bg-accent text-accent-foreground' : ''"
                                        >
                                         <component v-if="child.icon" :is="child.icon" class="mr-2 h-4 w-4" />
                                            {{ child.title }}
                                        </Link>
                                    </nav>
                                </HoverCardContent>
                            </HoverCard>

                            <!-- DESKTOP DIRECT LINK -->
                            <Link
                                v-else
                                :href="item.href"
                                class="h-9 px-3 flex items-center text-sm font-medium rounded-md"
                                :class="isCurrentRoute(item.href) ? 'bg-accent text-accent-foreground' : 'hover:bg-accent'"
                            >
                                <component v-if="item.icon" :is="item.icon" class="mr-2 h-4 w-4" />
                                {{ item.title }}
                            </Link>
                        </template>
                    </nav>
                </div>

                <!-- RIGHT SECTION -->
                <div class="ml-auto flex items-center gap-2">
                    <div class="hidden lg:flex gap-1">
                        <TooltipProvider>
                            <template v-for="item in rightNavItems" :key="item.title">
                                <Tooltip>
                                    <TooltipTrigger>
                                        <Button variant="ghost" size="icon" class="h-9 w-9">
                                            <a
                                                v-if="item.external"
                                                :href="item.href"
                                                target="_blank"
                                                rel="noopener"
                                            >
                                                <component :is="item.icon" class="size-5 opacity-80" />
                                            </a>
                                            <Link v-else :href="item.href">
                                                <component :is="item.icon" class="size-5 opacity-80" />
                                            </Link>
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent><p>{{ item.title }}</p></TooltipContent>
                                </Tooltip>
                            </template>
                        </TooltipProvider>
                    </div>

                    <!-- USER MENU -->
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="relative rounded-full p-1"
                            >
                                <Avatar class="size-8 rounded-full ring-1 ring-transparent">
                                    <AvatarImage v-if="auth.user.avatar" :src="auth.user.avatar" />
                                    <AvatarFallback class="bg-neutral-200 dark:bg-neutral-700 font-bold">
                                        {{ getInitials(auth.user.name) }}
                                    </AvatarFallback>
                                </Avatar>
                            </Button>
                        </DropdownMenuTrigger>

                        <DropdownMenuContent align="end" class="w-56">
                            <UserMenuContent :user="auth.user" />
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>
        </div>

        <!-- BREADCRUMBS -->
        <div
            v-if="props.breadcrumbs.length > 1"
            class="w-full border-b border-sidebar-border/70 bg-background/50 backdrop-blur-sm"
        >
            <div class="mx-auto h-12 flex items-center px-4 md:max-w-7xl text-neutral-500">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </div>
        </div>
    </div>
</template>
