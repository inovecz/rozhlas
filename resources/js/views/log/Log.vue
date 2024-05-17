<script setup>
import {formatDate} from "../../helper.js";
import {onMounted, reactive, ref} from "vue";
import {useToast} from "vue-toastification";
import LogService from "../../services/LogService.js";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";

const logs = ref([]);
let orderColumn = 'created_at';
let orderAsc = false;
const pageLength = ref(5);
const search = reactive({value: null});
const toast = useToast();

onMounted(() => {
  fetchLogs();
});

function fetchLogs(paginatorUrl) {
  LogService.fetchLogs(paginatorUrl, search.value, pageLength.value, orderColumn, orderAsc).then(response => {
    logs.value = response;
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}

function orderBy(column) {
  if (orderColumn === column) {
    orderAsc = !orderAsc;
  } else {
    orderColumn = column;
    orderAsc = true;
  }
  fetchLogs();
}

</script>

<template>
  <PageContent label="Protokoly">
    <Box label="Seznam akcí">
      <div class="overflow-x-auto">
        <table class="table">
          <!-- head -->
          <thead>
            <tr>
              <th @click="orderBy('created_at')">
                <div class="flex items-center cursor-pointer underline">
                  Datum vytvoření
                  <span v-if="orderColumn === 'created_at'">
                    <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                    <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                  </span>
                </div>
              </th>
              <th @click="orderBy('title')">
                <div class="flex items-center cursor-pointer underline">
                  Název
                  <span v-if="orderColumn === 'title'">
                    <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                    <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                  </span>
                </div>
              </th>
              <th @click="orderBy('type')">
                <div class="flex items-center cursor-pointer underline">
                  Typ
                  <span v-if="orderColumn === 'type'">
                    <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                    <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                  </span>
                </div>
              </th>
              <th @click="orderBy('originator')">
                <div class="flex items-center cursor-pointer underline">
                  Délka
                  <span v-if="orderColumn === 'originator'">
                    <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                    <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                  </span>
                </div>
              </th>
              <th class="text-right">Akce</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="log in logs.data" :key="log.id" class="hover">
              <td>
                {{ formatDate(new Date(log.created_at), 'd.m.Y H:i') }}
              </td>
              <td>
                <div class="flex items-center gap-3">
                  <div>
                    <div class="font-bold">{{ log.title }}</div>
                  </div>
                </div>
              </td>
              <td>
                <span v-if="log.type" class="mdi mdi-check text-xl"></span>
                <span v-else class="mdi mdi-close text-xl"></span>
              </td>
              <td>
                {{ log.originator }}
              </td>
              <td class="flex gap-2 justify-end items-center pt-4">
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="logs?.data?.length === 0" class="text-center py-2">Nebyla nalezena žádná data</div>
        <div class="flex justify-between items-center py-2 px-1">
          <div>
            <select v-model="pageLength" @change="fetchLogs()" class="select select-sm select-bordered w-full max-w-xs">
              <option value="5">5</option>
              <option value="10">10</option>
              <option value="25">25</option>
              <option value="50">50</option>
            </select>
          </div>
          <div v-if="logs?.meta?.last_page > 1">
            <div class="flex justify-center items-center">
              <div class="join join-horizontal">
                <template v-for="page in logs?.meta?.links">
                  <button @click="fetchLogs(page.url)" :disabled="page.url === null" class="btn btn-sm join-item" :class="{['btn-primary']: page.active}">{{ page.label }}</button>
                </template>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Box>
  </PageContent>
</template>

<style scoped>
</style>