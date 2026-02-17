<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type CategoriaRow = {
    ten_id_numero: number | string;
    ten_codigo: string | null;
    ten_nombre: string | null;
    ten_web_nombre: string | null;
    ten_categoria_padre: number | string | null;
    woocommerce_categoria_id: number | string | null;
    woocommerce_categoria_padre_id: number | string | null;
    sync_status: string | null;
    enable_sync: boolean | number | null;
    ten_web_sincronizar: boolean | number | null;
    ten_bloqueado: boolean | number | null;
    ten_ultimo_cambio: string | null;
    ten_last_fetched_at: string | null;
    last_error: string | null;
    updated_at: string | null;
};

type Paginator<T> = {
    data: T[];
    current_page: number;
    from: number | null;
    last_page: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
    per_page: number;
    to: number | null;
    total: number;
};

const props = defineProps<{
    filtros?: { q?: string; status?: string; per_page?: number };
    filters?: { q?: string; status?: string; per_page?: number };
    categorias: Paginator<CategoriaRow>;
    counts?: Record<string, number>;
}>();

defineOptions({
    layout: AppLayout,
});

const initial = computed(() => props.filters ?? props.filtros ?? {});

const q = ref(String(initial.value.q ?? ''));
const status = ref(String(initial.value.status ?? ''));
const perPage = ref<number>(Number(initial.value.per_page ?? props.categorias.per_page ?? 20));

const isSubmitting = ref(false);
let debounceTimer: number | undefined;

const counts = computed<Record<string, number>>(() => props.counts ?? {});

function toBool(v: unknown): boolean {
    return v === true || v === 1 || v === '1' || v === 'true';
}

function statusMeta(s: string | null | undefined) {
    const key = String(s ?? 'pending');

    switch (key) {
        case 'synced':
            return { label: 'Sincronizada', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-200', dot: 'bg-emerald-500' };
        case 'pending':
            return { label: 'Pendiente', cls: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-200', dot: 'bg-amber-500' };
        case 'error':
            return { label: 'Error', cls: 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-950/40 dark:text-rose-200', dot: 'bg-rose-500' };
        case 'disabled':
            return { label: 'Deshabilitada', cls: 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-900/40 dark:text-gray-200', dot: 'bg-gray-400' };
        default:
            return { label: key, cls: 'bg-slate-50 text-slate-700 ring-slate-600/20 dark:bg-slate-950/40 dark:text-slate-200', dot: 'bg-slate-400' };
    }
}

function buildParams() {
    return {
        q: q.value.trim() || undefined,
        status: status.value || undefined,
        per_page: perPage.value || undefined,
    };
}

function applyFilters() {
    isSubmitting.value = true;

    router.get(location.pathname, buildParams(), {
        replace: true,
        preserveState: true,
        preserveScroll: true,
        only: ['categorias', 'filters', 'filtros', 'counts'],
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}

function clearFilters() {
    q.value = '';
    status.value = '';
    perPage.value = 20;
    applyFilters();
}

function goTo(linkUrl: string | null) {
    if (!linkUrl) return;

    router.get(linkUrl, {}, {
        preserveScroll: true,
        preserveState: true,
        only: ['categorias', 'filters', 'filtros', 'counts'],
    });
}

watch(q, () => {
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(applyFilters, 300);
});

watch([status, perPage], () => {
    applyFilters();
});

const totalAll = computed(() => {
    const values = Object.values(counts.value);
    return values.length ? values.reduce((a, b) => a + (Number(b) || 0), 0) : props.categorias.total;
});
</script>

<template>
    <Head title="Categorías" />

    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
        <!-- Header / filtros (estilo Dashboard) -->
        <div
            class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-background/60 p-4 backdrop-blur supports-[backdrop-filter]:bg-background/40 dark:border-sidebar-border"
        >
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Categorías</h1>
                    <p class="text-sm text-muted-foreground">Estado de sincronización Ten → WooCommerce y flags.</p>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-md border border-sidebar-border/70 bg-background px-3 py-2 text-sm font-medium text-foreground shadow-sm transition hover:bg-accent hover:text-accent-foreground disabled:cursor-not-allowed disabled:opacity-60 dark:border-sidebar-border"
                        :disabled="isSubmitting"
                        @click="applyFilters"
                    >
                        Refrescar
                    </button>

                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-md bg-primary px-3 py-2 text-sm font-medium text-primary-foreground shadow transition hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="isSubmitting"
                        @click="clearFilters"
                    >
                        Limpiar
                    </button>
                </div>
            </div>

            <!-- KPIs -->
            <div class="grid grid-cols-2 gap-2 md:grid-cols-5">
                <button
                    type="button"
                    class="group flex items-center justify-between rounded-lg border border-sidebar-border/70 bg-background p-3 text-left transition hover:bg-accent/40 dark:border-sidebar-border"
                    @click="status = ''"
                >
                    <div>
                        <div class="text-xs text-muted-foreground">Todas</div>
                        <div class="text-lg font-semibold tabular-nums">{{ totalAll }}</div>
                    </div>
                    <div class="h-2.5 w-2.5 rounded-full bg-slate-400/70 group-hover:bg-slate-500" />
                </button>

                <button
                    type="button"
                    class="group flex items-center justify-between rounded-lg border border-sidebar-border/70 bg-background p-3 text-left transition hover:bg-accent/40 dark:border-sidebar-border"
                    @click="status = 'pending'"
                >
                    <div>
                        <div class="text-xs text-muted-foreground">Pendientes</div>
                        <div class="text-lg font-semibold tabular-nums">{{ counts.pending ?? 0 }}</div>
                    </div>
                    <div class="h-2.5 w-2.5 rounded-full bg-amber-500/80 group-hover:bg-amber-500" />
                </button>

                <button
                    type="button"
                    class="group flex items-center justify-between rounded-lg border border-sidebar-border/70 bg-background p-3 text-left transition hover:bg-accent/40 dark:border-sidebar-border"
                    @click="status = 'synced'"
                >
                    <div>
                        <div class="text-xs text-muted-foreground">Sincronizadas</div>
                        <div class="text-lg font-semibold tabular-nums">{{ counts.synced ?? 0 }}</div>
                    </div>
                    <div class="h-2.5 w-2.5 rounded-full bg-emerald-500/80 group-hover:bg-emerald-500" />
                </button>

                <button
                    type="button"
                    class="group flex items-center justify-between rounded-lg border border-sidebar-border/70 bg-background p-3 text-left transition hover:bg-accent/40 dark:border-sidebar-border"
                    @click="status = 'error'"
                >
                    <div>
                        <div class="text-xs text-muted-foreground">Con error</div>
                        <div class="text-lg font-semibold tabular-nums">{{ counts.error ?? 0 }}</div>
                    </div>
                    <div class="h-2.5 w-2.5 rounded-full bg-rose-500/80 group-hover:bg-rose-500" />
                </button>

                <button
                    type="button"
                    class="group flex items-center justify-between rounded-lg border border-sidebar-border/70 bg-background p-3 text-left transition hover:bg-accent/40 dark:border-sidebar-border"
                    @click="status = 'disabled'"
                >
                    <div>
                        <div class="text-xs text-muted-foreground">Deshabilitadas</div>
                        <div class="text-lg font-semibold tabular-nums">{{ counts.disabled ?? 0 }}</div>
                    </div>
                    <div class="h-2.5 w-2.5 rounded-full bg-gray-400/80 group-hover:bg-gray-400" />
                </button>
            </div>

            <!-- filtros -->
            <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div class="flex flex-1 flex-col gap-2 md:flex-row md:items-end">
                    <div class="w-full md:max-w-md">
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">Buscar</label>
                        <div class="relative">
                            <input
                                v-model="q"
                                type="text"
                                class="h-10 w-full rounded-md border border-sidebar-border/70 bg-background px-3 pr-10 text-sm outline-none ring-offset-background transition placeholder:text-muted-foreground focus:ring-2 focus:ring-primary/40 dark:border-sidebar-border"
                                placeholder="Nombre, nombre web, código, ID WooCommerce…"
                            />
                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                <div
                                    v-if="isSubmitting"
                                    class="h-4 w-4 animate-spin rounded-full border-2 border-muted-foreground/30 border-t-muted-foreground/80"
                                />
                                <div v-else class="h-2 w-2 rounded-full bg-muted-foreground/40" />
                            </div>
                        </div>
                    </div>

                    <div class="w-full md:w-56">
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">Estado</label>
                        <select
                            v-model="status"
                            class="h-10 w-full rounded-md border border-sidebar-border/70 bg-background px-3 text-sm outline-none ring-offset-background transition focus:ring-2 focus:ring-primary/40 dark:border-sidebar-border"
                        >
                            <option value="">Todos</option>
                            <option value="pending">Pendiente</option>
                            <option value="synced">Sincronizada</option>
                            <option value="error">Error</option>
                            <option value="disabled">Deshabilitada</option>
                        </select>
                    </div>

                    <div class="w-full md:w-40">
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">Por página</label>
                        <select
                            v-model.number="perPage"
                            class="h-10 w-full rounded-md border border-sidebar-border/70 bg-background px-3 text-sm outline-none ring-offset-background transition focus:ring-2 focus:ring-primary/40 dark:border-sidebar-border"
                        >
                            <option :value="10">10</option>
                            <option :value="20">20</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select>
                    </div>
                </div>

                <div class="text-sm text-muted-foreground">
                    <span class="tabular-nums">{{ props.categorias.from ?? 0 }}</span>
                    -
                    <span class="tabular-nums">{{ props.categorias.to ?? 0 }}</span>
                    de
                    <span class="font-medium tabular-nums text-foreground">{{ props.categorias.total }}</span>
                </div>
            </div>
        </div>

        <!-- table -->
        <div class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-background dark:border-sidebar-border">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border">
                    <thead class="bg-muted/30">
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            <th class="px-4 py-3">Categoría</th>
                            <th class="px-4 py-3">Estado</th>
                            <th class="px-4 py-3">IDs</th>
                            <th class="px-4 py-3">Flags</th>
                            <th class="px-4 py-3">Último cambio</th>
                            <th class="px-4 py-3">Último fetch</th>
                            <th class="px-4 py-3">Actualizada</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-border">
                        <tr v-if="props.categorias.data.length === 0">
                            <td colspan="7" class="px-4 py-10 text-center">
                                <div class="mx-auto max-w-md">
                                    <div class="text-sm font-medium">No hay resultados</div>
                                    <div class="mt-1 text-sm text-muted-foreground">Ajusta los filtros o limpia la búsqueda.</div>
                                </div>
                            </td>
                        </tr>

                        <tr v-for="c in props.categorias.data" :key="String(c.ten_id_numero)" class="transition hover:bg-accent/30">
                            <td class="px-4 py-3">
                                <div class="flex flex-col">
                                    <div class="flex items-center gap-2">
                                        <div class="font-medium text-foreground">
                                            {{ c.ten_nombre || c.ten_web_nombre || '(Sin nombre)' }}
                                        </div>
                                        <span v-if="c.ten_codigo" class="rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ c.ten_codigo }}</span>
                                    </div>
                                    <div class="mt-1 text-xs text-muted-foreground">
                                        <span class="font-medium">Web:</span>
                                        <span>{{ c.ten_web_nombre || '—' }}</span>
                                        <span class="mx-2">·</span>
                                        <span class="font-medium">Padre Ten:</span>
                                        <span class="tabular-nums">{{ c.ten_categoria_padre ?? '—' }}</span>
                                    </div>
                                    <div
                                        v-if="c.last_error"
                                        class="mt-2 line-clamp-2 rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-200"
                                    >
                                        {{ c.last_error }}
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <div
                                    class="inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-xs font-medium ring-1"
                                    :class="statusMeta(c.sync_status).cls"
                                >
                                    <span class="h-1.5 w-1.5 rounded-full" :class="statusMeta(c.sync_status).dot" />
                                    <span>{{ statusMeta(c.sync_status).label }}</span>
                                </div>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <div class="text-sm">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-xs text-muted-foreground">Ten</span>
                                        <span class="font-mono text-xs tabular-nums">{{ c.ten_id_numero }}</span>
                                    </div>
                                    <div class="mt-1 flex items-center justify-between gap-3">
                                        <span class="text-xs text-muted-foreground">WC</span>
                                        <span class="font-mono text-xs tabular-nums">{{ c.woocommerce_categoria_id ?? '—' }}</span>
                                    </div>
                                    <div class="mt-1 flex items-center justify-between gap-3">
                                        <span class="text-xs text-muted-foreground">WC padre</span>
                                        <span class="font-mono text-xs tabular-nums">{{ c.woocommerce_categoria_padre_id ?? '—' }}</span>
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <div class="flex flex-col gap-1 text-xs">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-muted-foreground">Sync habilitada</span>
                                        <span
                                            class="font-medium"
                                            :class="toBool(c.enable_sync) ? 'text-emerald-700 dark:text-emerald-200' : 'text-gray-600 dark:text-gray-300'"
                                        >
                                            {{ toBool(c.enable_sync) ? 'Sí' : 'No' }}
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-muted-foreground">Ten sincronizar</span>
                                        <span
                                            class="font-medium"
                                            :class="toBool(c.ten_web_sincronizar) ? 'text-emerald-700 dark:text-emerald-200' : 'text-gray-600 dark:text-gray-300'"
                                        >
                                            {{ toBool(c.ten_web_sincronizar) ? 'Sí' : 'No' }}
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-muted-foreground">Bloqueada</span>
                                        <span
                                            class="font-medium"
                                            :class="toBool(c.ten_bloqueado) ? 'text-rose-700 dark:text-rose-200' : 'text-emerald-700 dark:text-emerald-200'"
                                        >
                                            {{ toBool(c.ten_bloqueado) ? 'Sí' : 'No' }}
                                        </span>
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <div class="font-mono text-xs text-muted-foreground">{{ c.ten_ultimo_cambio ?? '—' }}</div>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <div class="font-mono text-xs text-muted-foreground">{{ c.ten_last_fetched_at ?? '—' }}</div>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <div class="font-mono text-xs text-muted-foreground">{{ c.updated_at ?? '—' }}</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- paginación -->
            <div class="flex flex-col gap-2 border-t border-border bg-background px-4 py-3 md:flex-row md:items-center md:justify-between">
                <div class="text-xs text-muted-foreground">
                    Página <span class="font-medium tabular-nums text-foreground">{{ props.categorias.current_page }}</span>
                    de <span class="font-medium tabular-nums text-foreground">{{ props.categorias.last_page }}</span>
                </div>

                <nav class="flex flex-wrap items-center gap-1">
                    <button
                        v-for="(l, idx) in props.categorias.links"
                        :key="idx"
                        type="button"
                        class="min-w-10 rounded-md px-3 py-2 text-sm transition"
                        :class="[
                            l.active
                                ? 'bg-primary text-primary-foreground'
                                : l.url
                                    ? 'border border-sidebar-border/70 bg-background text-foreground hover:bg-accent hover:text-accent-foreground dark:border-sidebar-border'
                                    : 'cursor-not-allowed border border-sidebar-border/40 bg-muted/40 text-muted-foreground dark:border-sidebar-border',
                        ]"
                        :disabled="!l.url"
                        @click="goTo(l.url)"
                        v-html="l.label"
                    />
                </nav>
            </div>
        </div>
    </div>
</template>
