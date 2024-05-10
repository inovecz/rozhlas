<script setup>

import {useToast} from "vue-toastification";
import {useDataTables} from "../../utils/datatablesTrait.js";
import LocationService from "../../services/LocationService.js";
import {ref} from "vue";

const locationGroups = ref([]);
const toast = useToast();
const {fetchRecords, orderAsc, orderBy, orderColumn, pageLength, search} = useDataTables(fetchLocationGroups, 'name', 5);

function fetchLocationGroups(paginatorUrl = null) {
  LocationService.fetchLocationGroups(paginatorUrl, search.value, pageLength.value, orderColumn.value, orderAsc.value).then(response => {
    locationGroups.value = response;
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Nastavení lokalit</h1>
    <div class="content flex flex-col space-y-4">

      <div class="component-box">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between">
          <div class="text-xl text-primary mb-4 mt-3 px-1">
            Seznam lokalit
          </div>
          <div class="flex gap-4">
            <label class="input input-bordered input-sm flex items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4 opacity-70">
                <path fill-rule="evenodd" d="M9.965 11.026a5 5 0 1 1 1.06-1.06l2.755 2.754a.75.75 0 1 1-1.06 1.06l-2.755-2.754ZM10.5 7a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0Z" clip-rule="evenodd"/>
              </svg>
              <input v-model="search.value" type="text" class="grow" placeholder="Hledat"/>
              <svg @click="() => {search.value = null}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4 opacity-70 cursor-pointer">
                <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="currentColor"></path>
              </svg>
            </label>
            <button @click="editLocationGroup" class="btn btn-primary btn-square btn-sm text-xl"><span class="mdi mdi-plus-box"></span></button>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="table">
            <!-- head -->
            <thead>
              <tr>
                <th @click="orderBy('name')">
                  <div class="flex items-center cursor-pointer underline">
                    Název lokality
                    <span v-if="orderColumn.value === 'name'">
                      <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                      <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                    </span>
                  </div>
                </th>
                <th @click="orderBy('is_hidden')">
                  <div class="flex items-center cursor-pointer underline">
                    Skrytá
                    <span v-if="orderColumn.value === 'is_hidden'">
                      <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                      <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                    </span>
                  </div>
                </th>
                <th>
                  <div class="flex items-center">
                    Počet míst
                  </div>
                </th>
                <th class="text-right">Akce</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="locationGroup in locationGroups.data" :key="locationGroup.id" class="hover">
                <td>
                  {{ locationGroup.name }}
                </td>
                <td>
                  {{ locationGroup.is_hidden ? 'Ano' : 'Ne' }}
                </td>
                <td>
                  <span>{{ locationGroup.locations_count }}</span>
                </td>
                <td class="flex gap-2 justify-end items-center pt-4">
                  <button @click="editContactGroup(locationGroup.id)"><span class="mdi mdi-rename text-primary text-xl"></span></button>
                  <button @click="deleteContactGroup(locationGroup.id)"><span class="mdi mdi-trash-can text-red-500 text-xl"></span></button>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="locationGroups?.data?.length === 0" class="text-center py-2">Nebyla nalezena žádná data</div>
          <div class="flex justify-between items-center py-2 px-1">
            <div>
              <select v-model="pageLength.value" class="select select-sm select-bordered w-full max-w-xs">
                <option value="5">5</option>
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
              </select>
            </div>
            <div v-if="locationGroups?.meta?.last_page > 1">
              <div class="flex justify-center items-center">
                <div class="join join-horizontal">
                  <template v-for="page in locationGroups?.meta?.links">
                    <button @click="fetchRecords(page.url)" :disabled="page.url === null" class="btn btn-sm join-item" :class="{['btn-primary']: page.active}">{{ page.label }}</button>
                  </template>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>

    </div>
  </div>
</template>

<style scoped>

</style>