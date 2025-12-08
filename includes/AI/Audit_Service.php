<?php

namespace AuditTool\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Orchestrates the full dual-AI audit (Grok + OpenAI).
 *
 * Behaviour:
 * - Grok is called in 3 grouped passes (UX/Messaging, Visibility, Authority).
 * - All successful Grok chunks are concatenated into one big Markdown body.
 * - OpenAI then refines that into:
 *     ---USER_REPORT---
 *       [visitor report]
 *
 *     ---OWNER_REPORT---
 *       [owner report]
 *
 * - If all Grok passes fail, we fall back to an OpenAI-only audit.
 *
 * Screenshot behaviour (new):
 * - Grok passes may now append a JSON Screenshot Plan between the markers:
 *     ===SCREENSHOT_PLAN_START===
 *     {...}
 *     ===SCREENSHOT_PLAN_END===
 * - This class strips those blocks out of the Grok Markdown
 *   and aggregates all `screenshots` entries into a single plan.
 * - After an audit ID is generated, we optionally call Screenshot_Service
 *   (if available) to actually generate/capture the screenshots (e.g. via BrowserShot).
 */
class Audit_Service {

    /**
     * @var AI_Client_Grok
     */
    private $grok;

    /**
     * @var AI_Client_OpenAI
     */
    private $openai;

    /**
     * @var Prompt_Factory
     */
    private $prompts;

    /**
     * @param AI_Client_Grok     $grok
     * @param AI_Client_OpenAI   $openai
     * @param Prompt_Factory     $prompts
     */
    public function __construct( AI_Client_Grok $grok, AI_Client_OpenAI $openai, Prompt_Factory $prompts ) {
        $this->grok    = $grok;
        $this->openai  = $openai;
        $this->prompts = $prompts;
    }

    /**
     * Run full dual-AI audit (Grok + OpenAI) and return structured content.
     *
     * @param array  $payload    Normalised form data (website_url, name, email, social URLs).
     * @param string $grok_key   Grok/X.ai API key.
     * @param string $openai_key OpenAI API key.
     *
     * @return array {
     *   @type string $visitor_markdown
     *   @type string $owner_markdown
     *   @type string $audit_id
     *   @type array  $screenshot_plan   // NEW: aggregated screenshot plan from all Grok passes
     * }
     *
     * @throws \RuntimeException On OpenAI failures.
     */
    public function run_audit( array $payload, $grok_key, $openai_key ) {

        $website_url = $payload['website_url'];
        $name        = isset( $payload['name'] ) ? trim( (string) $payload['name'] ) : '';
        $contact_url = rtrim( $website_url, '/' ) . '/contact';

        // ---------------------------------------------------------------------
        // 1) Build core context (used for all Grok passes + OpenAI)
        // ---------------------------------------------------------------------
        $socials = [
            'facebook'  => $payload['facebook_url']  ?? '',
            'instagram' => $payload['instagram_url'] ?? '',
            'x'         => $payload['x_url']         ?? '',
            'linkedin'  => $payload['linkedin_url']  ?? '',
        ];

        // Grok system prompt: 13 categories, crawl rules, £20k depth, etc.
        $grok_system = $this->prompts->grok_system_prompt(
            $website_url,
            $socials
        );

        // Grouped Grok passes – each pass still crawls widely but only reports
        // in its own thematic slice, mapped to the 13 canonical categories.
        $grok_groups = [
            'UX_MESSAGING' => [
                'label'      => 'UX, navigation, messaging, brand & trust',
                'categories' => [
                    'UI/UX & Navigation',
                    'Copywriting & Messaging',
                    'Brand Messaging & Value Proposition',
                    'Trust & Credibility',
                ],
            ],
            'VISIBILITY'   => [
                'label'      => 'SEO, content & performance',
                'categories' => [
                    'SEO',
                    'Blog & Content Strategy',
                    'Performance & Mobile Experience',
                ],
            ],
            'AUTHORITY'    => [
                'label'      => 'Social proof, GBP, reviews, competitors & opportunities',
                'categories' => [
                    'Social Media Presence',
                    'Google Business Profile',
                    'Advertising Readiness / Landing Pages',
                    'Missing Opportunities',
                    'Legal / Compliance / Footer',
                    'Overall Competitor Comparison',
                ],
            ],
        ];

        $all_grok_markdown  = '';
        $grok_failed_reason = '';
        $successful_groups  = [];
        $failed_groups      = [];

        // NEW: aggregated screenshot plan from all Grok passes
        $screenshot_plan = [
            'screenshots' => [],
        ];

        // ---------------------------------------------------------------------
        // 2) GROK / X.ai multi-pass phase
        // ---------------------------------------------------------------------
        if ( ! empty( $grok_key ) ) {
            foreach ( $grok_groups as $group_id => $group ) {
                try {
                    $user_prompt = $this->prompts->grok_group_user_prompt(
                        $website_url,
                        $socials,
                        $group_id,
                        $group['categories']
                    );

                    $chunk = $this->grok->call(
                        $grok_key,
                        $grok_system,
                        $user_prompt
                    );

                    $successful_groups[] = $group_id;

                    // NEW: extract and strip screenshot plan JSON from this chunk
                    list( $clean_chunk, $local_plan ) = self::extract_screenshot_plan(
                        $chunk,
                        $group_id
                    );

                    if ( ! empty( $local_plan['screenshots'] ) && is_array( $local_plan['screenshots'] ) ) {
                        // Merge into global plan
                        $screenshot_plan['screenshots'] = array_merge(
                            $screenshot_plan['screenshots'],
                            $local_plan['screenshots']
                        );
                    }

                    // Annotate each cleaned chunk with clear markers so OpenAI can see
                    // which group produced what (but without the raw screenshot JSON).
                    $all_grok_markdown .= sprintf(
                        "\n\n<!-- GROK_GROUP_%s_START -->\n\n%s\n\n<!-- GROK_GROUP_%s_END -->\n\n",
                        $group_id,
                        $clean_chunk,
                        $group_id
                    );
                } catch ( \Throwable $e ) {
                    $failed_groups[ $group_id ] = $e->getMessage();
                    error_log(
                        sprintf(
                            '[Audit Tool] Grok group %s failed: %s',
                            $group_id,
                            $e->getMessage()
                        )
                    );
                }
            }

            if ( empty( $successful_groups ) ) {
                // All groups failed – build a consolidated "reason" string.
                if ( ! empty( $failed_groups ) ) {
                    $reasons = [];
                    foreach ( $failed_groups as $gid => $message ) {
                        $reasons[] = $gid . ': ' . $message;
                    }
                    $grok_failed_reason = 'All Grok passes failed. Group errors: ' . implode( ' | ', $reasons );
                } else {
                    $grok_failed_reason = 'Grok passes failed for unknown reasons.';
                }
            }
        } else {
            // No Grok key configured – valid case, go straight to OpenAI-only path.
            $grok_failed_reason = 'No Grok/X.ai API key was configured.';
        }

        // ---------------------------------------------------------------------
        // 3) OpenAI refinement / fallback phase
        // ---------------------------------------------------------------------
        $openai_system = $this->prompts->openai_system_prompt();

        $name_info = $name !== ''
            ? "The business owner's first name is: {$name}."
            : "The business owner's first name is not known; if you greet them, just say \"Hi there\".";

        $cta_info = "Use this as the primary CTA destination: {$contact_url} (their contact page).";

        // Decide whether we are in:
        //  - "Normal" path: Grok produced at least some grouped analysis.
        //  - "Fallback" path: Grok produced nothing usable; OpenAI must do the full audit.
        if ( '' !== trim( (string) $all_grok_markdown ) ) {

            // NORMAL PATH: Grok succeeded for at least one group.
            $grok_summary_for_openai = '';

            if ( ! empty( $successful_groups ) ) {
                $grok_summary_for_openai .= 'Grok/X.ai successfully completed the following grouped passes: ' . implode( ', ', $successful_groups ) . ".\n";
            }
            if ( ! empty( $failed_groups ) ) {
                $grok_summary_for_openai .= 'The following grouped passes encountered errors (you may still infer or fill gaps where safe): ';
                $tmp = [];
                foreach ( $failed_groups as $gid => $message ) {
                    $tmp[] = $gid . ' => ' . $message;
                }
                $grok_summary_for_openai .= implode( ' | ', $tmp ) . "\n";
            }

            $openai_user = sprintf(
                "%s\n\n%s\n\nYou are refining an in-depth website and online presence audit for the site: %s.\n\n" .
                "Another model (Grok/X.ai) has already done the heavy crawling, research and competitor analysis in multiple passes.\n" .
                "%s\n\n" .
                "Below is the combined Grok analysis. It is grouped and annotated by pass markers like <!-- GROK_GROUP_UX_MESSAGING_START -->.\n" .
                "Your job is to transform this ENTIRE body of analysis into two separate, top-tier reports as per your system instructions:\n\n" .
                "1) ---USER_REPORT---\n" .
                "   - A non-technical, persuasive visitor-facing report.\n" .
                "   - For each of the 13 master categories, surface the 1–3 most impactful issues that affect perception, trust and conversions.\n" .
                "   - Use plain language, vivid examples, and direct benefit-focused framing.\n" .
                "   - Call out specific sections that should be screenshotted (hero, nav, forms, testimonials, competitor examples, etc.).\n" .
                "   - Where Grok provides statistics, retain them and make clear, credible stat blocks (include the source URLs).\n\n" .
                "2) ---OWNER_REPORT---\n" .
                "   - A deep, implementation-ready audit for the founder / marketing lead / dev.\n" .
                "   - For each of the 13 categories, list AT LEAST 5 distinct findings whenever possible (including those already mentioned in the visitor report).\n" .
                "   - For each finding, include:\n" .
                "       * A short label/name for the issue\n" .
                "       * Why it matters (impact on conversions, trust, SEO, performance, etc.)\n" .
                "       * Step-by-step remediation (1–5 steps) with enough detail for a developer, designer or copywriter to act on\n" .
                "       * Priority (High / Medium / Low)\n" .
                "       * Who should own it (developer, designer, copywriter, SEO, marketing ops, etc.).\n\n" .
                "Even if some categories are thin or missing in the Grok analysis, you MUST still produce all 13 categories in both reports. " .
                "When Grok is light on a category, fill the gaps with reasonable inferences, competitor comparisons and best-practice recommendations – " .
                "but do not fabricate analytics or internal data.\n\n" .
                "Here is the full combined Grok analysis:\n\n%s",
                $name_info,
                $cta_info,
                $website_url,
                $grok_summary_for_openai,
                $all_grok_markdown
            );
        } else {
            // FALLBACK PATH: Grok produced nothing usable – OpenAI must do full audit.
            $reason = $grok_failed_reason ?: 'Grok/X.ai returned no usable output.';

            $openai_user = sprintf(
                "%s\n\n%s\n\n" .
                "Grok/X.ai was supposed to perform the initial crawling and competitive research, " .
                "but it failed with this message:\n\"%s\".\n\n" .
                "Ignore that failure in your tone. You must now perform the FULL website and online presence audit yourself " .
                "for the site: %s.\n\n" .
                "Follow the exact 13-category audit framework and report structure described in your system prompt.\n" .
                "- Use only publicly available information (no fabricated analytics).\n" .
                "- Cover all 13 categories in both the visitor and owner reports.\n" .
                "- When you are finished, output both reports in one response using the ---USER_REPORT--- and ---OWNER_REPORT--- markers exactly.",
                $name_info,
                $cta_info,
                $reason,
                $website_url
            );
        }

        // Call OpenAI (via worker) with the combined or fallback user prompt.
        $combined = $this->openai->call( $openai_key, $openai_system, $openai_user );
        if ( '' === trim( (string) $combined ) ) {
            throw new \RuntimeException( 'No response returned from OpenAI.' );
        }

        // ---------------------------------------------------------------------
        // 4) Split into visitor / owner reports
        // ---------------------------------------------------------------------
        $parts = preg_split( '/---OWNER_REPORT---/i', $combined );
        if ( count( $parts ) < 2 ) {
            // Could not clearly split; fall back to duplicating combined content.
            $visitor_content = $combined;
            $owner_content   = "Owner report could not be clearly separated from the AI response.\n\n" . $combined;
        } else {
            $visitor_raw = $parts[0];
            $owner_raw   = $parts[1];

            $visitor_parts = preg_split( '/---USER_REPORT---/i', $visitor_raw );
            if ( count( $visitor_parts ) > 1 ) {
                $visitor_content = trim( $visitor_parts[1] );
            } else {
                $visitor_content = trim( $visitor_raw );
            }

            $owner_content = trim( $owner_raw );
            if ( empty( $visitor_content ) ) {
                $visitor_content = "This visitor report failed to parse cleanly from the AI response.";
            }
            if ( empty( $owner_content ) ) {
                $owner_content = "This owner report failed to parse cleanly from the AI response.\n\n" . $combined;
            }
        }

        // ---------------------------------------------------------------------
        // 5) Generate audit ID (AR-0120+)
        // ---------------------------------------------------------------------
        $counter = (int) get_option( 'audit_tool_report_counter', 120 );
        if ( $counter < 120 ) {
            $counter = 120;
        }
        $audit_number = str_pad( (string) $counter, 4, '0', STR_PAD_LEFT );
        $audit_id     = 'AR-' . $audit_number;
        update_option( 'audit_tool_report_counter', $counter + 1 );

        // ---------------------------------------------------------------------
        // 6) OPTIONAL: trigger screenshot generation via Screenshot_Service
        // ---------------------------------------------------------------------
        if ( ! empty( $screenshot_plan['screenshots'] ) && is_array( $screenshot_plan['screenshots'] ) ) {
            $screenshot_class = __NAMESPACE__ . '\\Screenshot_Service';

            if ( class_exists( $screenshot_class ) ) {
                try {
                    /** @var Screenshot_Service $screenshot_service */
                    $screenshot_service = new $screenshot_class();
                    $screenshot_service->generate_screenshots_from_plan( $audit_id, $screenshot_plan );
                } catch ( \Throwable $e ) {
                    error_log(
                        sprintf(
                            '[Audit Tool] Screenshot generation failed for audit %s: %s',
                            $audit_id,
                            $e->getMessage()
                        )
                    );
                }
            } else {
                error_log(
                    sprintf(
                        '[Audit Tool] Screenshot_Service class not found; skipping screenshot generation for audit %s.',
                        $audit_id
                    )
                );
            }
        }

        return [
            'visitor_markdown' => $visitor_content,
            'owner_markdown'   => $owner_content,
            'audit_id'         => $audit_id,
            'screenshot_plan'  => $screenshot_plan, // non-breaking extra
        ];
    }

    /**
     * Extract and strip screenshot plan JSON from a Grok chunk.
     *
     * - Looks for blocks wrapped in:
     *     ===SCREENSHOT_PLAN_START===
     *     {...}
     *     ===SCREENSHOT_PLAN_END===
     *
     * - Returns:
     *   [ string $clean_text, array $plan ]
     *
     *   where $plan has shape:
     *   [
     *     'screenshots' => [
     *       [ 'id' => '...', 'priority' => 1, 'url' => '...', ... ],
     *       ...
     *     ]
     *   ]
     *
     * - Any found screenshots get an extra 'group_id' field if provided.
     *
     * @param string      $text
     * @param string|null $group_id
     *
     * @return array
     */
    private static function extract_screenshot_plan( $text, $group_id = null ) {
        $clean       = (string) $text;
        $plan        = [ 'screenshots' => [] ];
        $group_id    = $group_id ? (string) $group_id : null;

        $pattern = '/===SCREENSHOT_PLAN_START===\s*(\{.*?\})\s*===SCREENSHOT_PLAN_END===/s';

        if ( preg_match_all( $pattern, $clean, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $match ) {
                $json_str = trim( $match[1] );

                $decoded = json_decode( $json_str, true );
                if ( is_array( $decoded ) && ! empty( $decoded['screenshots'] ) && is_array( $decoded['screenshots'] ) ) {
                    foreach ( $decoded['screenshots'] as $shot ) {
                        if ( ! is_array( $shot ) ) {
                            continue;
                        }

                        if ( $group_id && ! isset( $shot['group_id'] ) ) {
                            $shot['group_id'] = $group_id;
                        }

                        $plan['screenshots'][] = $shot;
                    }
                }
            }

            // Remove all plan blocks from the text
            $clean = preg_replace( $pattern, '', $clean );
        }

        $clean = trim( $clean );

        return [ $clean, $plan ];
    }
}
