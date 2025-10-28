<script setup>
import {onBeforeUnmount, onMounted, ref} from "vue";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Button from "../../components/forms/Button.vue";
import SystemStatusService from "../../services/SystemStatusService.js";

const loading = ref(false);
const error = ref(null);
const overview = ref(null);
const lastUpdated = ref(null);
const pollIntervalMs = 5000;
let timerId = null;

const loadOverview = async () => {
  loading.value = true;
  error.value = null;
  try {
    const response = await SystemStatusService.fetchOverview();
    overview.value = response;
    lastUpdated.value = new Date();
  } catch (exception) {
    console.error(exception);
    error.value = exception?.response?.data?.message ?? 'Nepodařilo se načíst stav systému';
  } finally {
    loading.value = false;
  }
};

const startPolling = () => {
  timerId = setInterval(loadOverview, pollIntervalMs);
};

onMounted(async () => {
  await loadOverview();
  startPolling();
});

onBeforeUnmount(() => {
  if (timerId) {
    clearInterval(timerId);
    timerId = null;
  }
});

const formatTimestamp = (timestamp) => {
  if (!timestamp) {
    return '—';
  }
  try {
    return new Date(timestamp).toLocaleString('cs-CZ');
  } catch (error) {
    return timestamp;
  }
};

const formatLocalDate = (value) => {
  if (!value) {
    return '—';
  }
  try {
    return value.toLocaleString('cs-CZ');
  } catch (error) {
    return String(value);
  }
};

const daemonStatusClass = (status) => {
  switch ((status ?? '').toLowerCase()) {
    case 'running':
      return 'badge-success';
    case 'disabled':
      return 'badge-neutral';
    case 'stopped':
    case 'not_running':
      return 'badge-error';
    default:
      return 'badge-warning';
  }
};

const daemonStatusLabel = (status) => {
  switch ((status ?? '').toLowerCase()) {
    case 'running':
      return 'Běží';
    case 'disabled':
      return 'Vypnuto';
    case 'stopped':
      return 'Zastaveno';
    case 'not_running':
      return 'Neběží';
    default:
      return status ?? 'Neznámý';
  }
};

const refreshNow = async () => {
  if (loading.value) {
    return;
  }
  await loadOverview();
};
</script>

<template>
  <PageContent label="Systémový stav">
    <div class="flex flex-col gap-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm text-gray-600">
          <div>Poslední aktualizace: {{ formatLocalDate(lastUpdated) }}</div>
          <div v-if="overview?.timestamp" class="text-xs text-gray-500">
            Snapshot: {{ formatTimestamp(overview.timestamp) }}
          </div>
        </div>
        <Button
            icon="mdi-refresh"
            size="sm"
            :disabled="loading"
            @click="refreshNow">
          Obnovit
        </Button>
      </div>

      <div v-if="error" class="alert alert-error shadow">
        <span class="mdi mdi-alert-circle-outline text-xl"></span>
        <span>{{ error }}</span>
      </div>

      <div class="grid gap-4 lg:grid-cols-2">
      <Box label="Fronty a poplachy">
          <div v-if="!overview" class="text-sm text-gray-500">Načítám data…</div>
          <div v-else class="grid gap-3 text-sm">
            <div class="flex items-center justify-between">
              <span>Čekající úlohy ve frontě</span>
              <span class="font-semibold">{{ overview.queues.pending_jobs }}</span>
            </div>
            <div class="flex items-center justify-between">
              <span>Neúspěšné úlohy</span>
              <span class="font-semibold">{{ overview.queues.failed_jobs }}</span>
            </div>
            <div class="divider my-1"></div>
            <div class="flex items-center justify-between">
              <span>Poplachy JSVV (plánované)</span>
              <span class="font-semibold">{{ overview.queues.jsvv.planned }}</span>
            </div>
            <div class="flex items-center justify-between">
              <span>Poplachy JSVV (čekají)</span>
              <span class="font-semibold">{{ overview.queues.jsvv.queued }}</span>
            </div>
            <div class="flex items-center justify-between">
              <span>Poplachy JSVV (probíhají)</span>
              <span class="font-semibold">{{ overview.queues.jsvv.running }}</span>
            </div>
            <div class="flex items-center justify-between">
              <span>Poplachy JSVV dokončené dnes</span>
              <span class="font-semibold">{{ overview.queues.jsvv.completed_today }}</span>
            </div>
            <div v-if="overview.queues.jsvv.last_completed" class="text-xs text-gray-500">
              Poslední dokončený poplach ID: {{ overview.queues.jsvv.last_completed.id ?? '—' }}
            </div>
          </div>
        </Box>

        <Box label="Aktivní vysílání">
          <div v-if="!overview" class="text-sm text-gray-500">Načítám data…</div>
          <template v-else>
          <div v-if="overview.broadcast" class="space-y-2 text-sm">
            <div><strong>ID relace:</strong> {{ overview.broadcast.id ?? '—' }}</div>
            <div><strong>Zdroj:</strong> {{ overview.broadcast.source ?? '—' }}</div>
            <div><strong>Stav:</strong> {{ overview.broadcast.status ?? '—' }}</div>
            <div><strong>Route:</strong> {{ (overview.broadcast.route ?? []).join(', ') || '—' }}</div>
            <div><strong>Zóny:</strong> {{ (overview.broadcast.zones ?? []).join(', ') || '—' }}</div>
            <div><strong>Začátek:</strong> {{ overview.broadcast.startedAt ?? overview.broadcast.started_at ?? '—' }}</div>
          </div>
          <div v-else-if="overview.broadcast_previous" class="space-y-2 text-sm">
            <div class="badge badge-neutral">Žádné aktivní vysílání</div>
            <div><strong>Poslední relace:</strong> {{ overview.broadcast_previous.id ?? '—' }}</div>
            <div><strong>Zdroj:</strong> {{ overview.broadcast_previous.source ?? '—' }}</div>
            <div><strong>Začátek:</strong> {{ overview.broadcast_previous.started_at ?? '—' }}</div>
            <div><strong>Konec:</strong> {{ overview.broadcast_previous.stopped_at ?? '—' }}</div>
          </div>
          <div v-else class="text-sm text-gray-500">
            Žádné vysílání není aktuálně aktivní.
          </div>
        </template>
      </Box>
      </div>

      <Box label="Procesy a démoni">
        <div v-if="!overview" class="text-sm text-gray-500">Načítám data…</div>
        <div v-else class="overflow-x-auto">
          <table class="table table-zebra">
            <thead>
              <tr>
                <th>Název</th>
                <th>Status</th>
                <th>PID</th>
                <th>Typ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="daemon in overview.daemons" :key="daemon.name">
                <td>{{ daemon.label }}</td>
                <td>
                  <span class="badge" :class="daemonStatusClass(daemon.status)">
                    {{ daemonStatusLabel(daemon.status) }}
                  </span>
                </td>
                <td>{{ daemon.pid ?? '—' }}</td>
                <td class="capitalize">{{ daemon.category }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Box>
    </div>
  </PageContent>
</template>
