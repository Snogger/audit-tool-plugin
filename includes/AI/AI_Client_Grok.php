<?php

namespace AuditTool\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Low-level HTTP client for talking to the Grok/xAI worker.
 *
 * This client does **not** know anything about audit plans – it only:
 * - Builds the JSON payload expected by server.js:/grok-chat
 * - Handles cURL, timeouts and basic error logging
 * - Returns the assistant content string (or an empty string on failure)
 */
class AI_Client_Grok {

    /**
     * AI worker endpoint for Grok chat.
     *
     * @var string
     */
    private $worker_endpoint;

    public function __construct() {
        /**
         * Filter: audit_tool_grok_worker_endpoint
         *
         * Allows overriding the Grok worker endpoint URL if needed.
         */
        $this->worker_endpoint = apply_filters(
            'audit_tool_grok_worker_endpoint',
            'http://46.224.119.20/grok-chat' // Must match /grok-chat in server.js
        );
    }

    /**
     * Call Grok (x.ai) via the external AI worker.
     *
     * @param string $api_key       Grok API key from plugin settings.
     * @param string $system_prompt System prompt for Grok.
     * @param string $user_content  User prompt (group-specific instructions).
     *
     * @return string Full Markdown / text response from Grok, or '' on failure.
     */
    public function call( $api_key, $system_prompt, $user_content ) {
        $payload = [
            // IMPORTANT: field name MUST be "grok_key" to match server.js.
            'grok_key'      => (string) $api_key,
            'system_prompt' => (string) $system_prompt,
            'user_content'  => (string) $user_content,
            // Let server.js choose default model if not provided.
            'model'         => 'grok-3-mini-latest',
        ];

        $headers = [
            'Content-Type: application/json',
        ];

        // Allow Grok a reasonably long time to crawl and respond.
        $result = $this->curl_post_json(
            $this->worker_endpoint,
            $payload,
            $headers,
            120 // seconds timeout at PHP level
        );

        if ( $result['error'] !== '' ) {
            error_log( '[Audit Tool] Grok worker cURL error: ' . $result['error'] );
            return '';
        }

        $code = $result['status'];
        $body = $result['body'];

        if ( $code < 200 || $code >= 300 ) {
            $snippet = substr( $body, 0, 400 );
            error_log(
                sprintf(
                    '[Audit Tool] Grok worker HTTP %d: %s',
                    $code,
                    $snippet
                )
            );
            return '';
        }

        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            $snippet = substr( $body, 0, 400 );
            error_log( '[Audit Tool] Grok worker invalid JSON: ' . $snippet );
            return '';
        }

        if ( empty( $data['success'] ) ) {
            $details = isset( $data['error'] ) ? $data['error'] : 'Unknown Grok worker error';
            error_log( '[Audit Tool] Grok worker returned failure: ' . $details );
            return '';
        }

        $content = isset( $data['content'] ) ? (string) $data['content'] : '';

        if ( '' === $content ) {
            error_log( '[Audit Tool] Grok worker returned empty content.' );
        }

        return $content;
    }

    /**
     * Perform a JSON POST with cURL.
     *
     * @param string $url
     * @param array  $payload
     * @param array  $headers
     * @param int    $timeout_seconds
     *
     * @return array{status:int,error:string,body:string}
     */
    private function curl_post_json( $url, array $payload, array $headers, $timeout_seconds ) {
        $ch = curl_init( $url );
        if ( ! $ch ) {
            return [
                'status' => 0,
                'error'  => 'Failed to initialise cURL',
                'body'   => '',
            ];
        }

        $json_payload = wp_json_encode( $payload );

        curl_setopt_array(
            $ch,
            [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => array_merge(
                    $headers,
                    [ 'Content-Length: ' . strlen( $json_payload ) ]
                ),
                CURLOPT_POSTFIELDS     => $json_payload,
                CURLOPT_TIMEOUT        => (int) $timeout_seconds,
            ]
        );

        $body = curl_exec( $ch );
        $err  = curl_error( $ch );
        $info = curl_getinfo( $ch );

        curl_close( $ch );

        if ( false === $body || '' !== $err ) {
            $error = $err ?: 'Unknown cURL error';
            return [
                'status' => 0,
                'error'  => $error,
                'body'   => '',
            ];
        }

        return [
            'status' => isset( $info['http_code'] ) ? (int) $info['http_code'] : 0,
            'error'  => '',
            'body'   => is_string( $body ) ? $body : '',
        ];
    }
}

