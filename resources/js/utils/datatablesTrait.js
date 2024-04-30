import {onMounted, reactive, watch} from "vue";

export function useDataTables(fetchRecords) {
    let orderColumn = 'created_at';
    let orderAsc = true;
    const pageLength = reactive({value: 5});
    const search = reactive({value: null});

    onMounted(() => {
        fetchRecords();
    });

    function orderBy(column) {
        if (orderColumn === column) {
            orderAsc = !orderAsc;
        } else {
            orderColumn = column;
            orderAsc = true;
        }
        fetchRecords();
    }

    watch(search, debounce(() => {
        fetchRecords();
    }, 500));

    watch(pageLength, () => fetchRecords());

    return {
        fetchRecords,
        orderAsc,
        orderBy,
        orderColumn,
        pageLength,
        search,
    };
}