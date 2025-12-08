<?php

namespace AuditTool\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Screenshot_Service
 *
 * Responsible for turning a Screenshot Plan (from Grok) into real screenshots
 * by calling an external Playwright/Puppeteer-style HTTP endpoint.
 *
 * This class is intentionally decoupled from the AI logic:
 * - Audit_Service just passes $audit_id and the aggregated $screenshot_plan.
 * - This service decides whether screenshots need generating and stores the URLs.
 */
class Screenshot_Service {

    /**
     * Screenshot worker endpoint (e.g. http://46.224.119.20/screenshot).
     *
     * @var string
     */
    private $endpoint;

    public function __construct() {
        /**
         * Filter: audit_tool_screenshot_endpoint
         *
         * Allows overriding the screenshot worker endpoint URL.
         *
         * Example in mu-plugin (you already have this):
         *
         *   add_filter( 'audit_tool_screenshot_endpoint', function () {
         *       return 'http://46.224.119.20/screenshot';
         *   } );
         */
        $this->endpoint = (string) apply_filters( 'audit_tool_screenshot_endpoint', '' );
    }

    /**
     * Process the screenshot plan for a given audit.
     *
     * @param string $audit_id
     * @param array  $plan  Array with a "screenshots" key as produced by Audit_Service.
     *
     * Shape of $plan:
     * [
     *   'screenshots' => [
     *     [
     *       'id'          => 'hero_main_claim',
     *       'priority'    => 1,
     *       'url'         => 'https://example.com/',
     *       'crop_mode'   => 'css_selector' | 'full_page' | 'text_match',
     *       'selector'    => '#hero' | null,
     *       'text_pattern'=> 'Some phrase' | null,
     *       'viewport'    => [ 'width' => 1440, 'height' => 900 ],
     *       'purpose'     => '...',
     *       'notes'       => '...',
     *       'group_id'    => 'UX_MESSAGING' (optional, added by Audit_Service)
     *     ],
     *     ...
     *   ]
     * ]
     */
    public function generate_screenshots_from_plan( $audit_id, array $plan ) {
        $audit_id = (string) $audit_id;

        if ( '' === $audit_id ) {
            error_log( '[Audit Tool] Screenshot_Service: missing audit_id; aborting screenshot generation.' );
            return;
        }

        if ( empty( $plan['screenshots'] ) || ! is_array( $plan['screenshots'] ) ) {
            // Nothing to do.
            return;
        }

        if ( '' === $this->endpoint ) {
            error_log(
                sprintf(
                    '[Audit Tool] Screenshot_Service: no screenshot endpoint configured (audit %s). ' .
                    'Set audit_tool_screenshot_endpoint to point at your Playwright worker.',
                    $audit_id
                )
            );
            return;
        }

        // Existing screenshots are stored keyed by shot "id".
        $stored = $this->get_existing_screenshots( $audit_id );

        foreach ( $plan['screenshots'] as $shot ) {
            if ( ! is_array( $shot ) ) {
                continue;
            }

            if ( empty( $shot['id'] ) || empty( $shot['url'] ) ) {
                continue;
            }

            $shot_id = (string) $shot['id'];

            // Skip if already generated.
            if ( isset( $stored[ $shot_id ] ) && ! empty( $stored[ $shot_id ]['image_url'] ) ) {
                continue;
            }

            $url = (string) $shot['url'];

            // Map plan crop modes to worker expectations.
            $crop_mode = isset( $shot['crop_mode'] ) ? (string) $shot['crop_mode'] : 'full_page';
            if ( ! in_array( $crop_mode, [ 'full_page', 'viewport' ], true ) ) {
                // Fallback: treat unknown modes as full-page screenshots.
                $crop_mode = 'full_page';
            }

            $viewport_width  = isset( $shot['viewport']['width'] )  ? (int) $shot['viewport']['width']  : 1280;
            $viewport_height = isset( $shot['viewport']['height'] ) ? (int) $shot['viewport']['height'] : 720;

            // Payload expected by server.js /screenshot
            $payload = [
                'url'       => $url,
                'crop_mode' => $crop_mode,
                'width'     => $viewport_width,
                'height'    => $viewport_height,
                // Extra meta: worker will just ignore these, but useful for logs.
                'audit_id'  => $audit_id,
                'shot_id'   => $shot_id,
            ];

            $response = wp_remote_post(
                $this->endpoint,
                [
                    'timeout' => 120,
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body'    => wp_json_encode( $payload ),
                ]
            );

            if ( is_wp_error( $response ) ) {
                error_log(
                    sprintf(
                        '[Audit Tool] Screenshot_Service: HTTP error for shot %s (audit %s): %s',
                        $shot_id,
                        $audit_id,
                        $response->get_error_message()
                    )
                );
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = (string) wp_remote_retrieve_body( $response );

            if ( $code < 200 || $code >= 300 ) {
                error_log(
                    sprintf(
                        '[Audit Tool] Screenshot_Service: non-2xx HTTP %d for shot %s (audit %s): %s',
                        $code,
                        $shot_id,
                        $audit_id,
                        substr( $body, 0, 400 )
                    )
                );
                continue;
            }

            $data = json_decode( $body, true );
            if ( ! is_array( $data ) ) {
                error_log(
                    sprintf(
                        '[Audit Tool] Screenshot_Service: invalid JSON response for shot %s (audit %s): %s',
                        $shot_id,
                        $audit_id,
                        substr( $body, 0, 400 )
                    )
                );
                continue;
            }

            // New worker shape: { ok: true, file: "http://..." }
            // Backwards-compatible: also accept { image_url: "http://..." }.
            $image_url = '';
            if ( ! empty( $data['file'] ) ) {
                $image_url = (string) $data['file'];
            } elseif ( ! empty( $data['image_url'] ) ) {
                $image_url = (string) $data['image_url'];
            }

            if ( '' === $image_url ) {
                error_log(
                    sprintf(
                        '[Audit Tool] Screenshot_Service: missing file/image_url for shot %s (audit %s).',
                        $shot_id,
                        $audit_id
                    )
                );
                continue;
            }

            $stored[ $shot_id ] = [
                'image_url' => $image_url,
                'meta'      => [
                    'created_at' => current_time( 'mysql' ),
                    'shot'       => $shot,
                ],
            ];

            // Persist progressively so long audits still store partial results.
            $this->save_screenshots( $audit_id, $stored );
        }
    }

    /**
     * Retrieve already stored screenshots for an audit.
     *
     * @param string $audit_id
     *
     * @return array
     */
    private function get_existing_screenshots( $audit_id ) {
        $key      = 'audit_tool_screenshots_' . $audit_id;
        $existing = get_option( $key, [] );

        return is_array( $existing ) ? $existing : [];
    }

    /**
     * Persist screenshot metadata for an audit.
     *
     * @param string $audit_id
     * @param array  $data
     *
     * @return void
     */
    private function save_screenshots( $audit_id, array $data ) {
        $key = 'audit_tool_screenshots_' . $audit_id;
        update_option( $key, $data );
    }
}

