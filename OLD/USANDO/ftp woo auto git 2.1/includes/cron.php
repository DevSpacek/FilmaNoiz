<?php
/**
 * Gerenciamento de agendamento (cron)
 */

if (!defined('ABSPATH')) {
    exit; // Saída direta se acessado diretamente
}

/**
 * Classe para gerenciamento de agendamento (cron)
 */
class FTP_Woo_Cron {
    
    /**
     * Construtor
     */
    public function __construct() {
        // Registrar intervalo personalizado
        add_filter('cron_schedules', array($this, 'register_schedules'));
        
        // Hook principal para processamento
        add_action('ftp_auto_scan_hook', array($this, 'scheduled_process'));
        
        // Verificar agendamento em cada carregamento
        add_action('init', array($this, 'check_schedule'));
        
        // Verificar processamento baseado em tráfego
        add_action('wp', array($this, 'maybe_process_on_visit'));
    }
    
    /**
     * Registrar intervalos de tempo personalizados
     * 
     * @param array $schedules Agendamentos existentes
     * @return array Agendamentos atualizados
     */
    public function register_schedules($schedules) {
        $schedules['minutely'] = array(
            'interval' => 60,
            'display'  => 'A cada minuto'
        );
        
        $schedules['every5minutes'] = array(
            'interval' => 300,
            'display'  => 'A cada 5 minutos'
        );
        
        return $schedules;
    }
    
    /**
     * Verificar e configurar agendamento
     */
    public function check_schedule() {
        // Verificar se automação está ativada
        if (get_option('ftp_auto_enabled', 'yes') !== 'yes') {
            $this->unschedule();
            return;
        }
        
        // Verificar se o evento está agendado
        $timestamp = wp_next_scheduled('ftp_auto_scan_hook');
        
        if (!$timestamp) {
            $this->schedule();
        }
    }
    
    /**
     * Método seguro de log que funciona mesmo durante ativação
     * 
     * @param string $message Mensagem para o log
     */
    private function safe_log($message) {
        // Adicionar ao registro recente para exibição na interface
        $recent_log = get_option('ftp_recent_log', '');
        $log_entry = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
        update_option('ftp_recent_log', $log_entry . $recent_log);
        
        // Se o objeto global estiver disponível, usar seu método de log também
        global $ftp_woo_auto;
        if ($ftp_woo_auto !== null && method_exists($ftp_woo_auto, 'log')) {
            $ftp_woo_auto->log($message);
        }
    }
    
    /**
     * Agendar evento
     */
    public function schedule() {
        // Remover eventos existentes para evitar duplicação
        $this->unschedule();
        
        // Obter frequência configurada
        $frequency = get_option('ftp_auto_frequency', 'every5minutes');
        
        // Agendar novo evento
        wp_schedule_event(time(), $frequency, 'ftp_auto_scan_hook');
        
        // Usar método de log seguro que não depende do objeto global
        $this->safe_log("Agendamento configurado para: $frequency");
    }
    
    /**
     * Remover agendamento
     */
    public function unschedule() {
        $timestamp = wp_next_scheduled('ftp_auto_scan_hook');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'ftp_auto_scan_hook');
        }
    }
    
    /**
     * Executar processamento agendado
     */
    public function scheduled_process() {
        global $ftp_woo_auto;
        
        // Verificar se automação está ativada
        if (get_option('ftp_auto_enabled', 'yes') !== 'yes') {
            return;
        }
        
        // Verificar se o objeto global está disponível
        if ($ftp_woo_auto !== null) {
            $ftp_woo_auto->log('Iniciando processamento via Cron WP');
            $ftp_woo_auto->process_files(true);
        } else {
            // Log seguro se o objeto não estiver disponível
            $this->safe_log('Erro: Objeto principal do plugin não está disponível');
        }
    }
    
    /**
     * Verificar se pode executar processamento durante visita do site
     * (método alternativo para ambientes com WP-Cron não confiável)
     */
    public function maybe_process_on_visit() {
        // Ignorar em áreas administrativas, AJAX, e solicitações de API
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }
        
        // Verificar se automação está ativada
        if (get_option('ftp_auto_enabled', 'yes') !== 'yes') {
            return;
        }
        
        // Verificar se passou tempo suficiente desde última execução
        $last_time = get_option('ftp_last_auto_time', 0);
        $force_minutes = intval(get_option('ftp_force_minutes', 5));
        $force_seconds = $force_minutes * 60;
        
        if ((time() - $last_time) < $force_seconds) {
            return;
        }
        
        // Executar processamento em 1% das visitas para evitar sobrecarga
        if (mt_rand(1, 100) > 1) {
            return;
        }
        
        global $ftp_woo_auto;
        
        // Verificar se o objeto global está disponível
        if ($ftp_woo_auto !== null) {
            $ftp_woo_auto->log('Iniciando processamento durante visita ao site');
            
            // Executar em segundo plano com PHP fastcgi_finish_request se disponível
            if (function_exists('fastcgi_finish_request')) {
                // Enviar resposta para o visitante imediatamente
                fastcgi_finish_request();
                
                // Continuar processamento em segundo plano
                $ftp_woo_auto->process_files(true);
            } else {
                // Alternativa: agendar para processar na próxima execução do cron
                if (!wp_next_scheduled('ftp_auto_scan_hook')) {
                    wp_schedule_single_event(time() + 10, 'ftp_auto_scan_hook');
                }
            }
        } else {
            // Log seguro se o objeto não estiver disponível
            $this->safe_log('Erro: Objeto principal do plugin não está disponível durante visita');
        }
    }
}