<?php

namespace AuditTool\Ajax;

use AuditTool\AI\Audit_Service;
use AuditTool\PDF\Markdown_Renderer;
use AuditTool\PDF\Pdf_Generator;
use AuditTool\Mail\Mailer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Audit_Ajax_Handler {

    private $ai;
    private $renderer;
    private $pdf;
    private $mailer;

    public function __construct( Audit_Service $ai, Markdown_Renderer $renderer, Pdf_Generator $pdf, Mailer $mailer ) {
        $this->ai       = $ai;
        $this->renderer = $renderer;
        $this->pdf      = $pdf;
        $this->mailer   = $mailer;

        add_action( 'wp_ajax_audit_analyze', [ $this, 'handle_ajax_request' ] );
        add_action( 'wp_ajax_nopriv_audit_analyze', [ $this, 'handle_ajax_request' ] );
        add_action( 'phpmailer_init', [ $this->mailer, 'configure_phpmailer' ] );
    }

    /**
     * Main AJAX entry point for Elementor form submissions.
     *
     * Expects Elementor's `form_fields` payload or flat POST fields.
     * Returns JSON with a human-readable message; PDFs are emailed.
     */
    public function handle_ajax_request() {
        // Basic nonce protection.
        if (
            ! isset( $_POST['nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'audit_tool_nonce' )
        ) {
            wp_send_json_error( [ 'message' => 'Invalid security token. Please refresh and try again.' ] );
        }

        // Normalise form fields (Elementor can send JSON or array).
        $form_fields = [];

        if ( isset( $_POST['form_fields'] ) ) {
            $raw = wp_unslash( $_POST['form_fields'] );

            if ( is_string( $raw ) ) {
                // Try JSON (Elementor's default).
                $decoded = json_decode( $raw, true );
                if ( is_array( $decoded ) ) {
                    $form_fields = $decoded;
                } else {
                    // Fallback: query-string style.
                    $parsed = [];
                    parse_str( $raw, $parsed );
                    if ( is_array( $parsed ) ) {
                        $form_fields = $parsed;
                    }
                }
            } elseif ( is_array( $raw ) ) {
                foreach ( $raw as $key => $value ) {
                    if ( is_array( $value ) ) {
                        $form_fields[ $key ] = sanitize_text_field( wp_unslash( implode( ', ', $value ) ) );
                    } else {
                        $form_fields[ $key ] = sanitize_text_field( wp_unslash( $value ) );
                    }
                }
            }
        }

        // Helper to pull a field with optional fuzzy fallback.
        $get_field = function( $keys, $fallback_contains = null ) use ( $form_fields ) {
            foreach ( (array) $keys as $key ) {
                if ( isset( $form_fields[ $key ] ) && '' !== trim( (string) $form_fields[ $key ] ) ) {
                    return trim( (string) $form_fields[ $key ] );
                }
            }

            if ( $fallback_contains ) {
                foreach ( $form_fields as $k => $v ) {
                    if ( false !== stripos( (string) $k, $fallback_contains ) && '' !== trim( (string) $v ) ) {
                        return trim( (string) $v );
                    }
                }
            }

            return '';
        };

        // Core fields.
        $name        = $get_field( [ 'name' ], 'name' );
        $email       = $get_field( [ 'email' ], 'mail' );
        $website_url = $get_field( [ 'website_url', 'website' ], 'website' );

        // Fallbacks from flat POST if Elementor mapping is different.
        if ( empty( $name ) && isset( $_POST['name'] ) ) {
            $name = sanitize_text_field( wp_unslash( $_POST['name'] ) );
        }
        if ( empty( $email ) && isset( $_POST['email'] ) ) {
            $email = sanitize_email( wp_unslash( $_POST['email'] ) );
        }
        if ( empty( $website_url ) && isset( $_POST['website_url'] ) ) {
            $website_url = esc_url_raw( wp_unslash( $_POST['website_url'] ) );
        } elseif ( empty( $website_url ) && isset( $_POST['website'] ) ) {
            $website_url = esc_url_raw( wp_unslash( $_POST['website'] ) );
        }

        // Social URLs (optional).
        $facebook_url  = $get_field( [ 'facebook_url' ] );
        $instagram_url = $get_field( [ 'instagram_url' ] );
        $twitter_url   = $get_field( [ 'twitter_url' ] );
        $x_url         = $get_field( [ 'x_url' ] );
        $linkedin_url  = $get_field( [ 'linkedin_url' ] );

        if ( empty( $x_url ) && ! empty( $twitter_url ) ) {
            $x_url = $twitter_url;
        }

        if ( empty( $website_url ) || empty( $email ) ) {
            wp_send_json_error( [
                'message' => 'Please ensure both Website URL and Email are provided before requesting an audit.',
            ] );
        }

        // Normalise website URL and derive contact URL.
        $website_url = rtrim( $website_url, "/ \t\n\r\0\x0B" );
        $contact_url = trailingslashit( $website_url ) . 'contact';

        // API keys from settings.
        $grok_key   = trim( (string) get_option( 'audit_tool_grok_api_key', '' ) );
        $openai_key = trim( (string) get_option( 'audit_tool_openai_api_key', '' ) );

        if ( empty( $grok_key ) && empty( $openai_key ) ) {
            wp_send_json_error( [
                'message' => 'AI API keys are not configured. Please contact the site owner.',
            ] );
        }

        try {
            // 1) Run full dual-AI audit.
            $result = $this->ai->run_audit(
                [
                    'website_url'   => $website_url,
                    'name'          => $name,
                    'email'         => $email,
                    'facebook_url'  => $facebook_url,
                    'instagram_url' => $instagram_url,
                    'x_url'         => $x_url,
                    'linkedin_url'  => $linkedin_url,
                ],
                $grok_key,
                $openai_key
            );

            if ( empty( $result['visitor_markdown'] ) || empty( $result['owner_markdown'] ) ) {
                wp_send_json_error( [
                    'message' => 'The AI audit did not return complete content. Please try again in a few minutes.',
                ] );
            }

            $visitor_markdown = (string) $result['visitor_markdown'];
            $owner_markdown   = (string) $result['owner_markdown'];
            $audit_id         = isset( $result['audit_id'] ) ? (string) $result['audit_id'] : '';

            // 2) Render HTML (Markdown -> HTML + logo + summary table).
            $date_for_table = function_exists( 'date_i18n' )
                ? date_i18n( 'd-m-Y' )
                : date( 'd-m-Y' );

            $visitor_html = $this->renderer->render(
                $visitor_markdown,
                $website_url,
                [
                    'name'        => $name,
                    'audit_id'    => $audit_id,
                    'report_type' => 'Visitor Report',
                    'contact_url' => $contact_url,
                    'date'        => $date_for_table,
                ]
            );

            $owner_html = $this->renderer->render(
                $owner_markdown,
                $website_url,
                [
                    'name'        => $name,
                    'audit_id'    => $audit_id,
                    'report_type' => 'Owner Report',
                    'contact_url' => $contact_url,
                    'date'        => $date_for_table,
                ]
            );

            // 3) Filenames (match spec: URL + date + AR-ID; and Name + URL + AR-ID).
            $host = parse_url( $website_url, PHP_URL_HOST );
            if ( empty( $host ) ) {
                $host = preg_replace( '#^https?://#', '', $website_url );
            }
            $host = trim( $host, "/ \t\n\r\0\x0B" );

            $today   = function_exists( 'date_i18n' ) ? date_i18n( 'dmY' ) : date( 'dmY' );
            $name_slug = $name ? sanitize_title( $name ) : 'owner';

            // Ensure we always have an AR-0xxx style audit ID (run_audit already enforces >= AR-0120).
            if ( '' === $audit_id ) {
                $counter = (int) get_option( 'audit_tool_report_counter', 120 );
                if ( $counter < 120 ) {
                    $counter = 120;
                }
                $audit_number = str_pad( (string) $counter, 4, '0', STR_PAD_LEFT );
                $audit_id     = 'AR-' . $audit_number;
                update_option( 'audit_tool_report_counter', $counter + 1 );
            }

            $visitor_filename = "{$host}_{$today}_{$audit_id}.pdf";
            $owner_filename   = "{$name_slug}_{$host}_{$audit_id}.pdf";

            // 4) Generate PDFs.
            $visitor_pdf_path = $this->pdf->generate( $visitor_html, $visitor_filename );
            $owner_pdf_path   = $this->pdf->generate( $owner_html, $owner_filename );

            if ( ! file_exists( $visitor_pdf_path ) || ! file_exists( $owner_pdf_path ) ) {
                // Log PDF generation failure for debugging.
                if ( defined( 'WP_CONTENT_DIR' ) ) {
                    $log_file = WP_CONTENT_DIR . '/audit-tool-pdf-mail.log';
                    $log_line = sprintf(
                        "[%s] Audit %s: PDF generation failed. visitor_pdf_path=%s (exists=%s), owner_pdf_path=%s (exists=%s)\n",
                        gmdate( 'c' ),
                        $audit_id,
                        $visitor_pdf_path,
                        file_exists( $visitor_pdf_path ) ? 'yes' : 'no',
                        $owner_pdf_path,
                        file_exists( $owner_pdf_path ) ? 'yes' : 'no'
                    );
                    @file_put_contents( $log_file, $log_line, FILE_APPEND );
                }

                wp_send_json_error( [
                    'message' => 'The audit PDFs could not be created. Please try again or contact the site owner.',
                ] );
            }

            // Log successful PDF generation before mailing.
            if ( defined( 'WP_CONTENT_DIR' ) ) {
                $log_file = WP_CONTENT_DIR . '/audit-tool-pdf-mail.log';
                $log_line = sprintf(
                    "[%s] Audit %s: PDFs ready. visitor_pdf_path=%s (exists=%s), owner_pdf_path=%s (exists=%s)\n",
                    gmdate( 'c' ),
                    $audit_id,
                    $visitor_pdf_path,
                    file_exists( $visitor_pdf_path ) ? 'yes' : 'no',
                    $owner_pdf_path,
                    file_exists( $owner_pdf_path ) ? 'yes' : 'no'
                );
                @file_put_contents( $log_file, $log_line, FILE_APPEND );
            }

            // 5) Send emails (user + owner).
            $this->mailer->send_emails(
                $email,
                $website_url,
                $name,
                $visitor_pdf_path,
                $owner_pdf_path
            );

            // 6) Clean up temporary PDF files.
            @unlink( $visitor_pdf_path );
            @unlink( $owner_pdf_path );

            // 7) Respond to JS (progress popup).
            wp_send_json_success( [
                'message' => sprintf(
                    'Congratulations, your audit for %s has been generated and sent to %s. Check your inbox for both the Visitor and Owner reports.',
                    esc_html( $website_url ),
                    esc_html( $email )
                ),
            ] );
        } catch ( \Throwable $e ) {
            error_log( '[Audit Tool] AJAX audit error: ' . $e->getMessage() );

            wp_send_json_error( [
                'message' => 'Sorry, something went wrong while generating your audit. Please try again in a few minutes.',
            ] );
        }
    }
}
