export default {
    fetchOverview() {
        return http.get('system/status').then(response => response.data);
    }
}
