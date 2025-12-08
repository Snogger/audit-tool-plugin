<?php
/**
 * Plugin Name:       Audit Tool
 * Description:       AI-powered website audit reports (visitor + owner) with PDF export.
 * Version:           1.0.0
 * Author:            Your Name
 * Text Domain:       audit-tool
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Plugin path and URL constants.
 */
define( 'AUDIT_TOOL_FILE', __FILE__ );
define( 'AUDIT_TOOL_PATH', plugin_dir_path( __FILE__ ) );
define( 'AUDIT_TOOL_URL',  plugin_dir_url( __FILE__ ) );

/**
 * Autoloader (Composer) if present.
 */
$autoload = AUDIT_TOOL_PATH . 'vendor/autoload.php';
if ( file_exists( $autoload ) ) {
    require_once $autoload;
}

/**
 * Core plugin class file.
 */
$core_class = AUDIT_TOOL_PATH . 'includes/class-audit-tool.php';
if ( file_exists( $core_class ) ) {
    require_once $core_class;
} else {
    error_log( '[Audit Tool] Missing core class file: includes/class-audit-tool.php' );
    // Hard fail early if the core class is missing.
    return;
}

/**
 * Bootstrap the plugin on plugins_loaded.
 *
 * IMPORTANT:
 * - We DO NOT call Audit_Tool::instance() anywhere.
 * - We simply create a new Audit_Tool() once.
 */
function audit_tool_bootstrap() {
    if ( class_exists( 'Audit_Tool' ) ) {
        // Option B: simple, procedural bootstrap (no singleton).
        new Audit_Tool();
    } else {
        error_log( '[Audit Tool] Class Audit_Tool not found. Check includes/class-audit-tool.php.' );
    }
}
add_action( 'plugins_loaded', 'audit_tool_bootstrap' );
