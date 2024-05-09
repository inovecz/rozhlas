<script setup>
import {ref} from "vue";
import {useToast} from "vue-toastification";
import {useDataTables} from "../../utils/datatablesTrait.js";
import MessageService from "../../services/MessageService.js";
import {formatDate} from "../../helper.js";

const messages = ref([]);
const toast = useToast();
const {fetchRecords, orderAsc, orderBy, orderColumn, pageLength, search} = useDataTables(fetchMessages, 'created_at', 10, false);

const channels = {
  'SMS': 'SMS',
  'EMAIL': 'E-mail'
}

const states = {
  'SENT': 'Odesláno',
  'RECEIVED': 'Přijato',
  'FAILED': 'Chyba'
}

const filter = ref({
  type: '',
  state: '',
});

function fetchMessages(paginatorUrl) {
  MessageService.fetchMessages(paginatorUrl, search, pageLength, orderColumn.value, orderAsc.value, filter.value).then(response => {
    messages.value = response;
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}
</script>

<template>
  <div class="component-box">
    <div class="flex flex-col sm:flex-row items-center sm:items-start justify-between">
      <div class="text-xl text-primary mb-4 mt-3 px-1">
        Seznam zpráv
      </div>
      <div class="flex gap-4">
        <div class="flex flex-col gap-2">
          <div class="flex flex-wrap justify-end gap-4">
            <select v-model="filter.type" @change="fetchMessages()" class="select select-bordered select-sm">
              <option value="">Všechny kanály</option>
              <option value="SMS">SMS</option>
              <option value="EMAIL">E-mail</option>
            </select>
            <select v-model="filter.state" @change="fetchMessages()" class="select select-bordered select-sm">
              <option value="">Všechny stavy</option>
              <option value="SENT">Odesláno</option>
              <option value="RECEIVED">Přijato</option>
              <option value="FAILED">Chyba</option>
            </select>
          </div>
          <label class="input input-bordered input-sm flex items-center gap-2 flex-1">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4 opacity-70">
              <path fill-rule="evenodd" d="M9.965 11.026a5 5 0 1 1 1.06-1.06l2.755 2.754a.75.75 0 1 1-1.06 1.06l-2.755-2.754ZM10.5 7a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0Z" clip-rule="evenodd"/>
            </svg>
            <input v-model="search.value" type="text" class="grow" placeholder="Hledat"/>
            <svg @click="() => {search.value = null}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4 opacity-70 cursor-pointer">
              <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="currentColor"></path>
            </svg>
          </label>
        </div>
        <router-link :to="{ name: 'CreateMessage' }"
                     class="btn btn-sm btn-primary btn-square text-xl">
          <span class="mdi mdi-plus-box"></span>
        </router-link>
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="table">
        <!-- head -->
        <thead>
          <tr>
            <th @click="orderBy('contact.fullname')">
              <div class="flex items-center cursor-pointer underline">
                Kontakt
                <span v-if="orderColumn.value === 'contact.fullname'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('type')">
              <div class="flex items-center cursor-pointer underline">
                Kanál
                <span v-if="orderColumn.value === 'type'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('state')">
              <div class="flex items-center cursor-pointer underline">
                Stav
                <span v-if="orderColumn.value === 'state'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('created_at')">
              <div class="flex items-center cursor-pointer underline">
                Datum
                <span v-if="orderColumn.value === 'created_at'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('content')">
              <div class="flex items-center cursor-pointer underline">
                Zpráva
                <span v-if="orderColumn.value === 'content'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="message in messages.data" :key="message.id" class="hover">
            <td>
              {{ message.contact.fullname }}
            </td>
            <td>
              {{ channels[message.type] }}
            </td>
            <td>
              {{ states[message.state] }}
            </td>
            <td>
              {{ formatDate(new Date(message.created_at), 'd.m.Y H:i') }}
            </td>
            <td class="max-w-36">
              <div class="truncate" :title="message.content">{{ message.content }}</div>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="messages?.data?.length === 0" class="text-center py-2">Nebyla nalezena žádná data</div>
      <div class="flex justify-between items-center py-2 px-1">
        <div>
          <select v-model="pageLength.value" class="select select-sm select-bordered w-full max-w-xs">
            <option value="5">5</option>
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
          </select>
        </div>
        <div v-if="messages?.meta?.last_page > 1">
          <div class="flex justify-center items-center">
            <div class="join join-horizontal">
              <template v-for="page in messages?.meta?.links">
                <button @click="fetchRecords(page.url)" :disabled="page.url === null" class="btn btn-sm join-item" :class="{['btn-primary']: page.active}">{{ page.label }}</button>
              </template>
            </div>
          </div>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>

</style>