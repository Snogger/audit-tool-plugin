<?php

// Force autoload at file top for REST and CLI contexts.
if ( file_exists( __DIR__ . '/../vendor/autoload.php' ) ) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

use AuditTool\Admin\Admin_Settings;
use AuditTool\Frontend\Frontend_Assets;
use AuditTool\Ajax\Audit_Ajax_Handler;
use AuditTool\AI\Audit_Service;
use AuditTool\AI\AI_Client_Grok;
use AuditTool\AI\AI_Client_OpenAI;
use AuditTool\AI\Prompt_Factory;
use AuditTool\PDF\Markdown_Renderer;
use AuditTool\PDF\Pdf_Generator;
use AuditTool\Mail\Mailer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main Audit Tool plugin class.
 *
 * This version removes the singleton pattern completely and uses a clean,
 * reliable, reusable constructor — fully compatible with the Option B bootstrap.
 */
class Audit_Tool {

    /** @var Admin_Settings */
    private $admin;

    /** @var Frontend_Assets */
    private $frontend;

    /** @var Audit_Ajax_Handler */
    private $ajax_handler;

    /**
     * Constructor – builds and wires the plugin components.
     *
     * IMPORTANT:
     * - Must remain PUBLIC for Procedural Bootstrap (Option B)
     * - No static instance handling, no singleton logic
     * - Called once via audit-tool.php on plugins_loaded
     */
    public function __construct() {

        // 1. Admin panel settings + UI.
        $this->admin = new Admin_Settings();

        // 2. Frontend assets (JS, CSS, progress UI).
        $this->frontend = new Frontend_Assets();

        // 3. AI pipeline (Grok + OpenAI + prompt factory).
        $grok_client   = new AI_Client_Grok();
        $openai_client = new AI_Client_OpenAI();
        $prompts       = new Prompt_Factory();

        $ai_service = new Audit_Service(
            $grok_client,
            $openai_client,
            $prompts
        );

        // 4. PDF generation pipeline (Markdown → HTML → PDF).
        $renderer = new Markdown_Renderer();
        $pdf      = new Pdf_Generator();

        // 5. Outbound mailer.
        $mailer = new Mailer();

        // 6. AJAX controller – wires up WP AJAX hooks.
        $this->ajax_handler = new Audit_Ajax_Handler(
            $ai_service,
            $renderer,
            $pdf,
            $mailer
        );
    }
}
