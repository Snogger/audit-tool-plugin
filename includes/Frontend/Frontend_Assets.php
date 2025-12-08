<?php

namespace AuditTool\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Frontend_Assets {

    public function __construct() {
        add_action( 'init', [ $this, 'register_shortcodes' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    public function register_shortcodes() {
        add_shortcode( 'analyzer-progress', [ $this, 'progress_shortcode' ] );
    }

    public function progress_shortcode() {
        ob_start();
        ?>
        <div id="audit-tool-progress" class="audit-tool-progress" style="display:none;">
            <div class="analyzer-progress">
                <div class="progress-circle" style="--value: 0%;">
                    <span class="progress-text">0%</span>
                </div>
                <p class="progress-message">
                    Analyzing your website audit request... this may take 2–5 minutes.
                </p>
                <p class="progress-text-nearly" style="display:none;">We’re nearly there...</p>
            </div>
            <div class="analyzer-results" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function enqueue_scripts() {
        wp_register_style(
            'audit-tool-progress',
            plugin_dir_url( __FILE__ ) . '../../assets/css/progress.css',
            [],
            '1.0.0'
        );

        wp_enqueue_style( 'audit-tool-progress' );

        // Fallback popup CSS from settings.
        $fallback_css = get_option( 'audit_tool_fallback_css', '' );
        if ( ! empty( $fallback_css ) && is_string( $fallback_css ) ) {
            wp_add_inline_style( 'audit-tool-progress', $fallback_css );
        }

        wp_register_script(
            'audit-tool-js',
            plugin_dir_url( __FILE__ ) . '../../assets/js/audit.js',
            [ 'jquery' ],
            '1.0.0',
            true
        );

        // Localize as your audit.js expects.
        $form_ids_option  = get_option( 'audit_tool_form_ids', '' );
        $popup_ids_option = get_option( 'audit_tool_popup_ids', '' );

        $form_ids  = array_filter( array_map( 'trim', explode( ',', (string) $form_ids_option ) ) );
        $popup_ids = array_filter( array_map( 'trim', explode( ',', (string) $popup_ids_option ) ) );

        $popup_id = '';
        if ( ! empty( $popup_ids ) ) {
            $popup_id = reset( $popup_ids );
        }

        $settings = [
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'formIds' => $form_ids,
            'popupId' => $popup_id,
            'nonce'   => wp_create_nonce( 'audit_tool_nonce' ),
        ];

        wp_localize_script( 'audit-tool-js', 'auditTool', $settings );
        wp_enqueue_script( 'audit-tool-js' );
    }
}
