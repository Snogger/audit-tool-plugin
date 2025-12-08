<?php

namespace AuditTool\Mail;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Mailer {

    /**
     * Send visitor + owner emails with attached PDFs.
     *
     * Behaviour is the same as before; we only add logging to:
     * - wp-content/audit-tool-pdf-mail.log
     * so we can see whether PDFs exist and whether wp_mail() succeeds.
     */
    public function send_emails( $user_email, $website_url, $name, $visitor_pdf, $owner_pdf ) {
        $owner_email = get_option( 'audit_tool_owner_email' );
        $from_email  = get_option( 'audit_tool_from_email', get_bloginfo( 'admin_email' ) );
        $from_name   = get_option( 'audit_tool_from_name', get_bloginfo( 'name' ) );

        $headers = [
            'From: ' . sprintf( '%s <%s>', $from_name, $from_email ),
        ];

        // ------------------------------------------------------------------
        // Visitor email
        // ------------------------------------------------------------------
        $subject_user = sprintf( 'Your Website Audit for %s', $website_url );
        $body_user    = sprintf(
            "Hi %s,\n\nYour visitor-friendly website audit for %s is attached as a PDF.\n\nKind regards,\n%s",
            $name,
            $website_url,
            $from_name
        );

        if ( $visitor_pdf && file_exists( $visitor_pdf ) ) {
            if ( defined( 'WP_CONTENT_DIR' ) ) {
                $log_file = WP_CONTENT_DIR . '/audit-tool-pdf-mail.log';
                $log_line = sprintf(
                    "[%s] Mailer: sending visitor email to %s with attachment %s (exists=%s)\n",
                    gmdate( 'c' ),
                    $user_email,
                    $visitor_pdf,
                    file_exists( $visitor_pdf ) ? 'yes' : 'no'
                );
                @file_put_contents( $log_file, $log_line, FILE_APPEND );
            }

            $sent_user = wp_mail( $user_email, $subject_user, $body_user, $headers, [ $visitor_pdf ] );

            if ( defined( 'WP_CONTENT_DIR' ) ) {
                $log_file = WP_CONTENT_DIR . '/audit-tool-pdf-mail.log';
                $log_line = sprintf(
                    "[%s] Mailer: visitor email send result to %s: %s\n",
                    gmdate( 'c' ),
                    $user_email,
                    $sent_user ? 'success' : 'failure'
                );
                @file_put_contents( $log_file, $log_line, FILE_APPEND );
            }
        } else {
            if ( defined( 'WP_CONTENT_DIR' ) ) {
                $log_file = WP_CONTENT_DIR . '/audit-tool-pdf-mail.log';
                $log_line = sprintf(
                    "[%s] Mailer: visitor PDF missing or not readable. Path=%s, exists=%s\n",
                    gmdate( 'c' ),
                    $visitor_pdf,
                    $visitor_pdf && file_exists( $visitor_pdf ) ? 'yes' : 'no'
                );
                @file_put_contents( $log_file, $log_line, FILE_APPEND );
            }
        }

        // ------------------------------------------------------------------
        // Owner email (both PDFs)
        // ------------------------------------------------------------------
        if ( $owner_email ) {
            $subject_owner = sprintf( 'Owner Website Audit for %s', $website_url );
            $body_owner    = sprintf(
                "Hi,\n\nAttached are the visitor-facing and owner/developer website audit PDFs for %s.\n\nKind regards,\n%s",
                $website_url,
                $from_name
            );

            $attachments = [];
            if ( $visitor_pdf && file_exists( $visitor_pdf ) ) {
                $attachments[] = $visitor_pdf;
            }
            if ( $owner_pdf && file_exists( $owner_pdf ) ) {
                $attachments[] = $owner_pdf;
            }

            if ( defined( 'WP_CONTENT_DIR' ) ) {
                $log_file = WP_CONTENT_DIR . '/audit-tool-pdf-mail.log';
                $log_line = sprintf(
                    "[%s] Mailer: owner attachments prepared: %s\n",
                    gmdate( 'c' ),
                    implode( ', ', $attachments )
                );
                @file_put_contents( $log_file, $log_line, FILE_APPEND );
            }

            if ( ! empty( $attachments ) ) {
                $sent_owner = wp_mail( $owner_email, $subject_owner, $body_owner, $headers, $attachments );

                if ( defined( 'WP_CONTENT_DIR' ) ) {
                    $log_file = WP_CONTENT_DIR . '/audit-tool-pdf-mail.log';
                    $log_line = sprintf(
                        "[%s] Mailer: owner email send result to %s: %s\n",
                        gmdate( 'c' ),
                        $owner_email,
                        $sent_owner ? 'success' : 'failure'
                    );
                    @file_put_contents( $log_file, $log_line, FILE_APPEND );
                }
            } else {
                if ( defined( 'WP_CONTENT_DIR' ) ) {
                    $log_file = WP_CONTENT_DIR . '/audit-tool-pdf-mail.log';
                    $log_line = sprintf(
                        "[%s] Mailer: owner email not sent because attachments are empty. visitor_pdf=%s (exists=%s), owner_pdf=%s (exists=%s)\n",
                        gmdate( 'c' ),
                        $visitor_pdf,
                        $visitor_pdf && file_exists( $visitor_pdf ) ? 'yes' : 'no',
                        $owner_pdf,
                        $owner_pdf && file_exists( $owner_pdf ) ? 'yes' : 'no'
                    );
                    @file_put_contents( $log_file, $log_line, FILE_APPEND );
                }
            }
        }
    }

    /**
     * SMTP configuration hook.
     */
    public function configure_phpmailer( $phpmailer ) {
        $host = trim( get_option( 'audit_tool_smtp_host' ) );
        $port = trim( get_option( 'audit_tool_smtp_port' ) );
        $user = trim( get_option( 'audit_tool_smtp_user' ) );
        $pass = trim( get_option( 'audit_tool_smtp_pass' ) );
        $enc  = trim( get_option( 'audit_tool_smtp_encryption' ) );

        if ( empty( $host ) || empty( $port ) || empty( $user ) || empty( $pass ) ) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host       = $host;
        $phpmailer->Port       = (int) $port;
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Username   = $user;
        $phpmailer->Password   = $pass;
        $phpmailer->SMTPSecure = $enc && 'none' !== $enc ? $enc : '';
    }
}

