<?php

namespace AuditTool\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Admin_Settings {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    /**
     * Register the main "Audit Tool" menu and subpages.
     */
    public function register_admin_menu() {
        // Main settings page (existing behaviour).
        add_menu_page(
            __( 'Audit Tool', 'audit-tool' ),
            __( 'Audit Tool', 'audit-tool' ),
            'manage_options',
            'audit-tool',
            [ $this, 'render_admin_page' ],
            'dashicons-analytics',
            80
        );

        // NEW: "Audit Reports" admin interface page.
        // This does NOT change any audit behaviour – it is an extra screen.
        add_submenu_page(
            'audit-tool',
            __( 'Audit Reports', 'audit-tool' ),
            __( 'Audit Reports', 'audit-tool' ),
            'manage_options',
            'audit-tool-reports',
            [ $this, 'render_reports_page' ]
        );
    }

    /**
     * Render the main settings page (existing behaviour).
     */
    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'AI Website Audit Tool Settings', 'audit-tool' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'audit_tool_settings_group' );
                do_settings_sections( 'audit-tool' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * NEW: Render the "Audit Reports" admin interface page.
     *
     * NOTE:
     * - This is intentionally read-only and does not alter any audit behaviour.
     * - Right now it shows a simple placeholder so we do not risk performance issues
     *   by scanning the uploads directory.
     * - Later we can extend this to read from a lightweight audit index option.
     */
    public function render_reports_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Audit Reports', 'audit-tool' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'This screen will show a list of completed audits with links to Visitor and Owner PDFs. The audit index is read-only and does not affect how audits are generated.', 'audit-tool' ); ?>
            </p>
            <?php
            // Placeholder: do not attempt to scan uploads or change behaviour yet.
            // This keeps functionality identical while adding the admin interface shell.
            $reports = get_option( 'audit_tool_audit_reports', [] );

            if ( empty( $reports ) || ! is_array( $reports ) ) :
                ?>
                <p><?php esc_html_e( 'No audit index has been recorded yet. New audits will continue to be generated and emailed as normal.', 'audit-tool' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Audit ID', 'audit-tool' ); ?></th>
                            <th><?php esc_html_e( 'Website', 'audit-tool' ); ?></th>
                            <th><?php esc_html_e( 'Client name', 'audit-tool' ); ?></th>
                            <th><?php esc_html_e( 'Client email', 'audit-tool' ); ?></th>
                            <th><?php esc_html_e( 'Date', 'audit-tool' ); ?></th>
                            <th><?php esc_html_e( 'Visitor PDF', 'audit-tool' ); ?></th>
                            <th><?php esc_html_e( 'Owner PDF', 'audit-tool' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $reports as $report ) : ?>
                        <tr>
                            <td><?php echo isset( $report['audit_id'] ) ? esc_html( $report['audit_id'] ) : ''; ?></td>
                            <td><?php echo isset( $report['website_url'] ) ? esc_html( $report['website_url'] ) : ''; ?></td>
                            <td><?php echo isset( $report['name'] ) ? esc_html( $report['name'] ) : ''; ?></td>
                            <td><?php echo isset( $report['email'] ) ? esc_html( $report['email'] ) : ''; ?></td>
                            <td><?php echo isset( $report['date'] ) ? esc_html( $report['date'] ) : ''; ?></td>
                            <td>
                                <?php if ( ! empty( $report['visitor_pdf_url'] ) ) : ?>
                                    <a href="<?php echo esc_url( $report['visitor_pdf_url'] ); ?>" target="_blank"><?php esc_html_e( 'Download', 'audit-tool' ); ?></a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! empty( $report['owner_pdf_url'] ) ) : ?>
                                    <a href="<?php echo esc_url( $report['owner_pdf_url'] ); ?>" target="_blank"><?php esc_html_e( 'Download', 'audit-tool' ); ?></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Enqueue any admin CSS/JS (existing behaviour).
     */
    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'audit-tool' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'audit-tool-admin',
            plugin_dir_url( __FILE__ ) . '../../assets/css/progress.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'audit-tool-admin',
            plugin_dir_url( __FILE__ ) . '../../assets/js/admin.js',
            [ 'jquery' ],
            '1.0.0',
            true
        );
    }

    /**
     * Register settings and sections (keep existing options intact).
     */
    public function register_settings() {
        // API & email.
        register_setting( 'audit_tool_settings_group', 'audit_tool_grok_api_key' );
        register_setting( 'audit_tool_settings_group', 'audit_tool_openai_api_key' );
        register_setting( 'audit_tool_settings_group', 'audit_tool_from_email' );
        register_setting( 'audit_tool_settings_group', 'audit_tool_from_name' );
        register_setting( 'audit_tool_settings_group', 'audit_tool_owner_email' );

        // Elementor mapping & popup.
        register_setting( 'audit_tool_settings_group', 'audit_tool_form_ids' );
        register_setting( 'audit_tool_settings_group', 'audit_tool_popup_ids' );

        // Branding & PDF styling.
        register_setting( 'audit_tool_settings_group', 'audit_tool_logo_id' );
        register_setting( 'audit_tool_settings_group', 'audit_tool_fallback_css' );
        register_setting( 'audit_tool_settings_group', 'audit_tool_pdf_css_overrides' );

        // SMTP.
        register_setting( 'audit_tool_settings_group', 'audit_tool_smtp_host' );
        register_setting( 'audit_tool_settings_group', 'audit_tool_smtp_port' );
        register_setting( 'audit_tool_settings_group', 'audit_tool_smtp_user' );
        register_setting( 'audit_tool_settings_group', 'audit_tool_smtp_pass' );
        register_setting( 'audit_tool_settings_group', 'audit_tool_smtp_encryption' );

        // Sections (existing).
        add_settings_section(
            'audit_tool_main_section',
            __( 'API & Email', 'audit-tool' ),
            null,
            'audit-tool'
        );

        add_settings_section(
            'audit_tool_elementor_section',
            __( 'Elementor Mapping', 'audit-tool' ),
            null,
            'audit-tool'
        );

        add_settings_section(
            'audit_tool_brand_section',
            __( 'Brand & PDF Styling', 'audit-tool' ),
            null,
            'audit-tool'
        );

        add_settings_section(
            'audit_tool_smtp_section',
            __( 'SMTP (Optional)', 'audit-tool' ),
            null,
            'audit-tool'
        );

        // Main text fields (existing behaviour).
        $this->add_text_field(
            'audit_tool_grok_api_key',
            __( 'Grok/X.ai API Key', 'audit-tool' ),
            'audit_tool_main_section'
        );
        $this->add_text_field(
            'audit_tool_openai_api_key',
            __( 'OpenAI API Key', 'audit-tool' ),
            'audit_tool_main_section'
        );
        $this->add_text_field(
            'audit_tool_from_email',
            __( 'From Email', 'audit-tool' ),
            'audit_tool_main_section'
        );
        $this->add_text_field(
            'audit_tool_from_name',
            __( 'From Name', 'audit-tool' ),
            'audit_tool_main_section'
        );
        $this->add_text_field(
            'audit_tool_owner_email',
            __( 'Owner Notification Email', 'audit-tool' ),
            'audit_tool_main_section'
        );

        // Textarea fields (new helper, behaviour same as before conceptually).
        $this->add_textarea_field(
            [
                'option'      => 'audit_tool_form_ids',
                'label'       => __( 'Elementor Form IDs (JSON)', 'audit-tool' ),
                'section'     => 'audit_tool_elementor_section',
                'description' => __( 'Map Elementor form IDs to field keys as JSON.', 'audit-tool' ),
            ]
        );

        $this->add_text_field(
            'audit_tool_popup_ids',
            __( 'Elementor Popup IDs (comma-separated)', 'audit-tool' ),
            'audit_tool_elementor_section',
            __( 'Optional: link audits to specific popups.', 'audit-tool' )
        );

        $this->add_text_field(
            'audit_tool_logo_id',
            __( 'Logo Attachment ID', 'audit-tool' ),
            'audit_tool_brand_section',
            __( 'Optional: logo used in PDF header.', 'audit-tool' )
        );

        $this->add_textarea_field(
            [
                'option'      => 'audit_tool_fallback_css',
                'label'       => __( 'Fallback PDF CSS', 'audit-tool' ),
                'section'     => 'audit_tool_brand_section',
                'description' => __( 'Optional: CSS used if no overrides are provided.', 'audit-tool' ),
            ]
        );

        $this->add_textarea_field(
            [
                'option'      => 'audit_tool_pdf_css_overrides',
                'label'       => __( 'Custom PDF CSS Overrides', 'audit-tool' ),
                'section'     => 'audit_tool_brand_section',
                'description' => __( 'Optional: additional CSS appended to the default PDF styles.', 'audit-tool' ),
            ]
        );

        $this->add_text_field(
            'audit_tool_smtp_host',
            __( 'SMTP Host', 'audit-tool' ),
            'audit_tool_smtp_section'
        );
        $this->add_text_field(
            'audit_tool_smtp_port',
            __( 'SMTP Port', 'audit-tool' ),
            'audit_tool_smtp_section'
        );
        $this->add_text_field(
            'audit_tool_smtp_user',
            __( 'SMTP Username', 'audit-tool' ),
            'audit_tool_smtp_section'
        );
        $this->add_text_field(
            'audit_tool_smtp_pass',
            __( 'SMTP Password', 'audit-tool' ),
            'audit_tool_smtp_section'
        );
        $this->add_text_field(
            'audit_tool_smtp_encryption',
            __( 'SMTP Encryption', 'audit-tool' ),
            'audit_tool_smtp_section',
            __( 'ssl, tls, or none', 'audit-tool' )
        );
    }

    /**
     * Helper to add a simple text field.
     */
    private function add_text_field( $option, $label, $section, $description = '' ) {
        add_settings_field(
            $option,
            $label,
            [ $this, 'render_text_field' ],
            'audit-tool',
            $section,
            [
                'label_for'   => $option,
                'description' => $description,
            ]
        );
    }

    /**
     * Helper to add a textarea field (this was missing before).
     */
    private function add_textarea_field( array $args ) {
        $option      = isset( $args['option'] ) ? $args['option'] : '';
        $label       = isset( $args['label'] ) ? $args['label'] : '';
        $section     = isset( $args['section'] ) ? $args['section'] : '';
        $description = isset( $args['description'] ) ? $args['description'] : '';

        if ( ! $option || ! $section ) {
            return;
        }

        add_settings_field(
            $option,
            $label,
            [ $this, 'render_textarea_field' ],
            'audit-tool',
            $section,
            [
                'label_for'   => $option,
                'description' => $description,
            ]
        );
    }

    /**
     * Render callback for text fields.
     */
    public function render_text_field( $args ) {
        $option = isset( $args['label_for'] ) ? $args['label_for'] : '';
        $value  = get_option( $option, '' );
        ?>
        <input type="text"
               id="<?php echo esc_attr( $option ); ?>"
               name="<?php echo esc_attr( $option ); ?>"
               value="<?php echo esc_attr( $value ); ?>"
               class="regular-text" />
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render callback for textarea fields.
     */
    public function render_textarea_field( $args ) {
        $option = isset( $args['label_for'] ) ? $args['label_for'] : '';
        $value  = get_option( $option, '' );
        ?>
        <textarea id="<?php echo esc_attr( $option ); ?>"
                  name="<?php echo esc_attr( $option ); ?>"
                  rows="6"
                  class="large-text code"><?php echo esc_textarea( $value ); ?></textarea>
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif; ?>
        <?php
    }
}

