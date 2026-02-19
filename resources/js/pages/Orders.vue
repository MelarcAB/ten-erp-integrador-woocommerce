<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type PedidoRow = {
    id: number;
    woocommerce_id: number | string | null;
    woocommerce_number: string | null;
    woocommerce_order_key: string | null;
    woocommerce_customer_id: number | string | null;
    cliente_id: number | string | null;
    status: string | null;
    sync_status: string | null;
    currency: string | null;
    total: string | number | null;
    total_tax: string | number | null;
    payment_method_title: string | null;
    wc_date_created: string | null;
    wc_date_paid: string | null;
    ten_codigo: string | null;
    ten_id: number | string | null;
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
    filtros?: { q?: string; sync_status?: string; status?: string; per_page?: number };
    filters?: { q?: string; sync_status?: string; status?: string; per_page?: number };
    pedidos: Paginator<PedidoRow>;
    counts?: Record<string, number>;
    statusCounts?: Record<string, number>;
}>();

defineOptions({
    layout: AppLayout,
});

const initial = computed(() => props.filters ?? props.filtros ?? {});

const q = ref(String(initial.value.q ?? ''));
const syncStatus = ref(String(initial.value.sync_status ?? ''));
const wcStatus = ref(String(initial.value.status ?? ''));
const perPage = ref<number>(Number(initial.value.per_page ?? props.pedidos.per_page ?? 20));

const isSubmitting = ref(false);
let debounceTimer: number | undefined;

const counts = computed<Record<string, number>>(() => props.counts ?? {});
const statusCounts = computed<Record<string, number>>(() => props.statusCounts ?? {});

function statusMeta(s: string | null | undefined) {
    const key = String(s ?? 'pending');

    switch (key) {
        case 'synced':
            return { label: 'Sincronizado', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-200', dot: 'bg-emerald-500' };
        case 'pending':
            return { label: 'Pendiente', cls: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-200', dot: 'bg-amber-500' };
        case 'error':
            return { label: 'Error', cls: 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-950/40 dark:text-rose-200', dot: 'bg-rose-500' };
        case 'disabled':
            return { label: 'Deshabilitado', cls: 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-900/40 dark:text-gray-200', dot: 'bg-gray-400' };
        default:
            return { label: key, cls: 'bg-slate-50 text-slate-700 ring-slate-600/20 dark:bg-slate-950/40 dark:text-slate-200', dot: 'bg-slate-400' };
    }
}

function wcStatusMeta(s: string | null | undefined) {
    const key = String(s ?? '—');
    const base = 'bg-muted text-muted-foreground ring-border dark:bg-muted/30';

    switch (key) {
        case 'completed':
            return { label: 'completed', cls: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-200' };
        case 'processing':
            return { label: 'processing', cls: 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-950/40 dark:text-blue-200' };
        case 'on-hold':
            return { label: 'on-hold', cls: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-200' };
        case 'cancelled':
            return { label: 'cancelled', cls: 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-900/40 dark:text-gray-200' };
        case 'failed':
            return { label: 'failed', cls: 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-950/40 dark:text-rose-200' };
        case 'refunded':
            return { label: 'refunded', cls: 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-950/40 dark:text-violet-200' };
        default:
            return { label: key, cls: base };
    }
}

function buildParams() {
    return {
        q: q.value.trim() || undefined,
        sync_status: syncStatus.value || undefined,
        status: wcStatus.value || undefined,
        per_page: perPage.value || undefined,
    };
}

function applyFilters() {
    isSubmitting.value = true;

    router.get(location.pathname, buildParams(), {
        replace: true,
        preserveState: true,
        preserveScroll: true,
        only: ['pedidos', 'filters', 'filtros', 'counts', 'statusCounts'],
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
}

function clearFilters() {
    q.value = '';
    syncStatus.value = '';
    wcStatus.value = '';
    perPage.value = 20;
    applyFilters();
}

function goTo(linkUrl: string | null) {
    if (!linkUrl) return;

    router.get(linkUrl, {}, {
        preserveScroll: true,
        preserveState: true,
        only: ['pedidos', 'filters', 'filtros', 'counts', 'statusCounts'],
    });
}

watch(q, () => {
    window.clearTimeout(debounceTimer);
    debounceTimer = window.setTimeout(applyFilters, 300);
});

watch([syncStatus, wcStatus, perPage], () => {
    applyFilters();
});

const totalAll = computed(() => {
    const values = Object.values(counts.value);
    return values.length ? values.reduce((a, b) => a + (Number(b) || 0), 0) : props.pedidos.total;
});

function formatMoney(amount: PedidoRow['total'], currency: string | null | undefined) {
    const n = typeof amount === 'string' ? Number(amount) : typeof amount === 'number' ? amount : NaN;
    if (Number.isNaN(n)) return '—';
    const cur = currency || 'EUR';
    try {
        return new Intl.NumberFormat('es-ES', { style: 'currency', currency: cur }).format(n);
    } catch {
        return `${n.toFixed(2)} ${cur}`;
    }
}
</script>

<template>
    <Head title="Pedidos" />

    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
        <div class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-background/60 p-4 backdrop-blur supports-[backdrop-filter]:bg-background/40 dark:border-sidebar-border">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Pedidos</h1>
                    <p class="text-sm text-muted-foreground">Auditoría de estado Woo y estado de sincronización.</p>
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

            <!-- KPIs sync -->
            <div class="grid grid-cols-2 gap-2 md:grid-cols-5">
                <button type="button" class="group flex items-center justify-between rounded-lg border border-sidebar-border/70 bg-background p-3 text-left transition hover:bg-accent/40 dark:border-sidebar-border" @click="syncStatus = ''">
                    <div>
                        <div class="text-xs text-muted-foreground">Todos</div>
                        <div class="text-lg font-semibold tabular-nums">{{ totalAll }}</div>
                    </div>
                    <div class="h-2.5 w-2.5 rounded-full bg-slate-400/70 group-hover:bg-slate-500" />
                </button>

                <button type="button" class="group flex items-center justify-between rounded-lg border border-sidebar-border/70 bg-background p-3 text-left transition hover:bg-accent/40 dark:border-sidebar-border" @click="syncStatus = 'pending'">
                    <div>
                        <div class="text-xs text-muted-foreground">Pendientes</div>
                        <div class="text-lg font-semibold tabular-nums">{{ counts.pending ?? 0 }}</div>
                    </div>
                    <div class="h-2.5 w-2.5 rounded-full bg-amber-500/80 group-hover:bg-amber-500" />
                </button>

                <button type="button" class="group flex items-center justify-between rounded-lg border border-sidebar-border/70 bg-background p-3 text-left transition hover:bg-accent/40 dark:border-sidebar-border" @click="syncStatus = 'synced'">
                    <div>
                        <div class="text-xs text-muted-foreground">Sincronizados</div>
                        <div class="text-lg font-semibold tabular-nums">{{ counts.synced ?? 0 }}</div>
                    </div>
                    <div class="h-2.5 w-2.5 rounded-full bg-emerald-500/80 group-hover:bg-emerald-500" />
                </button>

                <button type="button" class="group flex items-center justify-between rounded-lg border border-sidebar-border/70 bg-background p-3 text-left transition hover:bg-accent/40 dark:border-sidebar-border" @click="syncStatus = 'error'">
                    <div>
                        <div class="text-xs text-muted-foreground">Con error</div>
                        <div class="text-lg font-semibold tabular-nums">{{ counts.error ?? 0 }}</div>
                    </div>
                    <div class="h-2.5 w-2.5 rounded-full bg-rose-500/80 group-hover:bg-rose-500" />
                </button>

                <button type="button" class="group flex items-center justify-between rounded-lg border border-sidebar-border/70 bg-background p-3 text-left transition hover:bg-accent/40 dark:border-sidebar-border" @click="syncStatus = 'disabled'">
                    <div>
                        <div class="text-xs text-muted-foreground">Deshabilitados</div>
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
                                placeholder="# pedido, ids, ten, pago…"
                            />
                            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                                <div v-if="isSubmitting" class="h-4 w-4 animate-spin rounded-full border-2 border-muted-foreground/30 border-t-muted-foreground/80" />
                                <div v-else class="h-2 w-2 rounded-full bg-muted-foreground/40" />
                            </div>
                        </div>
                    </div>

                    <div class="w-full md:w-56">
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">Sync</label>
                        <select v-model="syncStatus" class="h-10 w-full rounded-md border border-sidebar-border/70 bg-background px-3 text-sm outline-none ring-offset-background transition focus:ring-2 focus:ring-primary/40 dark:border-sidebar-border">
                            <option value="">Todos</option>
                            <option value="pending">Pendiente</option>
                            <option value="synced">Sincronizado</option>
                            <option value="error">Error</option>
                            <option value="disabled">Deshabilitado</option>
                        </select>
                    </div>

                    <div class="w-full md:w-56">
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">Estado Woo</label>
                        <select v-model="wcStatus" class="h-10 w-full rounded-md border border-sidebar-border/70 bg-background px-3 text-sm outline-none ring-offset-background transition focus:ring-2 focus:ring-primary/40 dark:border-sidebar-border">
                            <option value="">Todos</option>
                            <option value="processing">processing</option>
                            <option value="completed">completed</option>
                            <option value="on-hold">on-hold</option>
                            <option value="cancelled">cancelled</option>
                            <option value="failed">failed</option>
                            <option value="refunded">refunded</option>
                        </select>
                        <p v-if="Object.keys(statusCounts).length" class="mt-1 text-xs text-muted-foreground">
                            <span v-for="(v, k) in statusCounts" :key="k" class="mr-2">{{ k }}: <span class="font-medium tabular-nums text-foreground">{{ v }}</span></span>
                        </p>
                    </div>

                    <div class="w-full md:w-40">
                        <label class="mb-1 block text-xs font-medium text-muted-foreground">Por página</label>
                        <select v-model.number="perPage" class="h-10 w-full rounded-md border border-sidebar-border/70 bg-background px-3 text-sm outline-none ring-offset-background transition focus:ring-2 focus:ring-primary/40 dark:border-sidebar-border">
                            <option :value="10">10</option>
                            <option :value="20">20</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select>
                    </div>
                </div>

                <div class="text-sm text-muted-foreground">
                    <span class="tabular-nums">{{ props.pedidos.from ?? 0 }}</span>
                    -
                    <span class="tabular-nums">{{ props.pedidos.to ?? 0 }}</span>
                    de
                    <span class="font-medium tabular-nums text-foreground">{{ props.pedidos.total }}</span>
                </div>
            </div>
        </div>

        <!-- tabla -->
        <div class="overflow-hidden rounded-xl border border-sidebar-border/70 bg-background dark:border-sidebar-border">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border">
                    <thead class="bg-muted/30">
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-muted-foreground">
                            <th class="px-4 py-3">Pedido</th>
                            <th class="px-4 py-3">Sync</th>
                            <th class="px-4 py-3">Estado Woo</th>
                            <th class="px-4 py-3">Cliente</th>
                            <th class="px-4 py-3">Total</th>
                            <th class="px-4 py-3">Pago</th>
                            <th class="px-4 py-3">Fechas</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-border">
                        <tr v-if="props.pedidos.data.length === 0">
                            <td colspan="7" class="px-4 py-10 text-center">
                                <div class="mx-auto max-w-md">
                                    <div class="text-sm font-medium">No hay resultados</div>
                                    <div class="mt-1 text-sm text-muted-foreground">Ajusta los filtros o limpia la búsqueda.</div>
                                </div>
                            </td>
                        </tr>

                        <tr v-for="p in props.pedidos.data" :key="p.id" class="transition hover:bg-accent/30">
                            <td class="px-4 py-3">
                                <div class="flex flex-col">
                                    <div class="flex items-center gap-2">
                                        <div class="font-medium text-foreground">#{{ p.woocommerce_number ?? p.woocommerce_id ?? p.id }}</div>
                                        <span v-if="p.ten_codigo" class="rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground">TEN: {{ p.ten_codigo }}</span>
                                    </div>
                                    <div v-if="p.last_error" class="mt-2 line-clamp-2 rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-200">
                                        {{ p.last_error }}
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <div class="inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-xs font-medium ring-1" :class="statusMeta(p.sync_status).cls">
                                    <span class="h-1.5 w-1.5 rounded-full" :class="statusMeta(p.sync_status).dot" />
                                    <span>{{ statusMeta(p.sync_status).label }}</span>
                                </div>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <div class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ring-1" :class="wcStatusMeta(p.status).cls">
                                    {{ wcStatusMeta(p.status).label }}
                                </div>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <div class="text-sm">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-xs text-muted-foreground">Woo cust</span>
                                        <span class="font-mono text-xs tabular-nums">{{ p.woocommerce_customer_id ?? '—' }}</span>
                                    </div>
                                    <div class="mt-1 flex items-center justify-between gap-3">
                                        <span class="text-xs text-muted-foreground">Cliente ID</span>
                                        <span class="font-mono text-xs tabular-nums">{{ p.cliente_id ?? '—' }}</span>
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <div class="font-medium">{{ formatMoney(p.total, p.currency) }}</div>
                                <div class="mt-1 text-xs text-muted-foreground">Tax: {{ formatMoney(p.total_tax, p.currency) }}</div>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <div class="text-sm">{{ p.payment_method_title ?? '—' }}</div>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <div class="space-y-1 text-xs text-muted-foreground">
                                    <div class="flex items-center justify-between gap-3">
                                        <span>Creado</span>
                                        <span class="font-mono">{{ p.wc_date_created ?? '—' }}</span>
                                    </div>
                                    <div class="flex items-center justify-between gap-3">
                                        <span>Pagado</span>
                                        <span class="font-mono">{{ p.wc_date_paid ?? '—' }}</span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-2 border-t border-border bg-background px-4 py-3 md:flex-row md:items-center md:justify-between">
                <div class="text-xs text-muted-foreground">
                    Página <span class="font-medium tabular-nums text-foreground">{{ props.pedidos.current_page }}</span>
                    de <span class="font-medium tabular-nums text-foreground">{{ props.pedidos.last_page }}</span>
                </div>

                <nav class="flex flex-wrap items-center gap-1">
                    <button
                        v-for="(l, idx) in props.pedidos.links"
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
