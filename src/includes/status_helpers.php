<?php
// Centralized status & badge helper utilities
// filepath: c:/xampp/htdocs/saku_santri/src/includes/status_helpers.php
if(!function_exists('render_status_badge')){
    /**
     * Render a standardized status badge span.
     * Known statuses: menunggu_pembayaran, menunggu_konfirmasi, lunas, ditolak
     */
    function render_status_badge(string $status): string {
        $normalized = strtolower(trim($status));
        $cls = 'status-'.str_replace('_','-',$normalized);
        $label = ucwords(str_replace('_',' ', $normalized));
        return '<span class="'.$cls.'">'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</span>';
    }
}
if(!function_exists('human_status')){
    function human_status(string $status): string {
        return ucwords(str_replace('_',' ', strtolower($status)));
    }
}
?>
