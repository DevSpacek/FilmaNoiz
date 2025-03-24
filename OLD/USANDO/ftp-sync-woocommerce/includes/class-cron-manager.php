<?php
/**
 * Classe para gerenciar verificações automáticas
 */

if (!defined('ABSPATH')) {
    exit; // Saída se acessado diretamente
}

class FTP_Sync_Cron_Manager {
    
    /**
     * Construtor
     */
    public function __construct() {
        // Verificar e configurar agendamento na inicialização
        add_action('init', array($this, 'check_schedule'));
        
        // Recalcular agendamento quando as configurações são alteradas
        add_action('update_option_ftp_sync_check_interval', array($this, 'reschedule'));
    }
    
    /**
     * Verificar e configurar agendamento
     */
    public function check_schedule() {
        // Verificar se já existe um evento agendado
        $next_event = wp_next_scheduled('ftp_sync_check_event');
        
        // Se não existe, agendar
        if (!$next_event) {
            $this->schedule();
        }
    }
    
    /**
     * Agendar verificação automática
     */
    public function schedule() {
        // Remover agendamento existente, se houver
        $this->unschedule();
        
        // Obter intervalo configurado
        $interval = get_option('ftp_sync_check_interval', 'hourly');
        
        // Agendar novo evento
        wp_schedule_event(time(), $interval, 'ftp_sync_check_event');
        
        $this->log("Verificação automática agendada a cada {$interval}");
    }
    
    /**
     * Remover agendamento
     */
    public function unschedule() {
        $timestamp = wp_next_scheduled('ftp_sync_check_event');
        
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ftp_sync_check_event');
            $this->log("Agendamento anterior removido");
        }
    }
    
    /**
     * Reagendar quando as configurações são alteradas
     */
    public function reschedule() {
        $this->schedule();
    }
    
    /**
     * Registrar log
     */
    private function log($message) {
        if (function_exists('ftp_sync_woocommerce')) {
            ftp_sync_woocommerce()->log("[Cron] " . $message);
        }
    }
}