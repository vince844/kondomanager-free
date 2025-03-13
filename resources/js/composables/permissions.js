import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

export function usePermission() {
    const page = usePage();
    const auth = computed(() => page.props.auth);

    const hasRole = (name) => {
        return auth.value?.user?.roles?.includes(name) ?? false;
    };

    const hasPermission = (name) => {
        return auth.value?.user?.permissions?.includes(name) ?? false;
    };

    return { hasRole, hasPermission };
}
