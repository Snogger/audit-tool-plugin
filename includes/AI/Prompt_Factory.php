<?php

namespace AuditTool\AI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Builds all AI prompt strings for Grok/X.ai and OpenAI.
 *
 * IMPORTANT:
 * - This class is used by Audit_Service.
 * - Methods:
 *   - grok_system_prompt( $website_url, array $socials )
 *   - grok_group_user_prompt( $website_url, array $socials, $group_id, array $categories )
 *   - openai_system_prompt()
 *
 * Overall behaviour:
 * - Grok is the PRIMARY ANALYST.
 *   - It analyses the site, socials, GBP and real competitors.
 *   - It runs in grouped passes (UX/Messaging, Visibility, Authority).
 *   - Each pass may produce:
 *       1) Rich Markdown analysis.
 *       2) A JSON Screenshot Plan between
 *          ===SCREENSHOT_PLAN_START=== / ===SCREENSHOT_PLAN_END=== markers.
 * - OpenAI is the STRUCTURER / COPYWRITER.
 *   - It receives the combined Grok Markdown (with group markers, but without raw JSON).
 *   - It outputs TWO reports in one response:
 *       ---USER_REPORT---  (Visitor report)
 *       ---OWNER_REPORT--- (Owner report)
 *   - Both follow the finalised 13-category structure.
 */
class Prompt_Factory {

    /**
     * System prompt for Grok/X.ai (PRIMARY ANALYST).
     *
     * @param string $website_url
     * @param array  $socials
     *
     * @return string
     */
    public function grok_system_prompt( $website_url, array $socials = [] ) {
        $social_summary_parts = [];

        foreach ( $socials as $key => $url ) {
            $url = trim( (string) $url );
            if ( '' === $url ) {
                continue;
            }
            $label = ucfirst( $key );
            $social_summary_parts[] = "{$label}: {$url}";
        }

        $social_summary = ! empty( $social_summary_parts )
            ? implode( ' | ', $social_summary_parts )
            : 'No explicit social URLs were provided; infer and discover profiles from the website if possible.';

        $website_url = trim( (string) $website_url );

        return <<<PROMPT
You are **Grok/X.ai acting as the PRIMARY ANALYST** in a two-model website audit system.

A second model (OpenAI) will later turn your analysis into:
- A **Visitor report** (friendly, non-technical, sales-oriented), and
- An **Owner report** (same structure but with step-by-step fixes).

You NEVER output the final reports.  
You ONLY output:

1) Detailed Markdown analysis (per grouped pass), and  
2) A **JSON Screenshot Plan** between special markers:

   ===SCREENSHOT_PLAN_START===
   { "screenshots": [ ... ] }
   ===SCREENSHOT_PLAN_END===

These plan blocks are stripped by our backend and NOT shown to the client.

==================================================
CONTEXT
==================================================

Target website:

    {$website_url}

Social profiles from the form (highest priority):

    {$social_summary}

You must treat this as a **live, real-world** analysis.  
Use up-to-date knowledge and (where your platform allows) live web research, but:

- You must respond with **plain text only** (no tool call JSON or API metadata).
- You must NOT output anything that looks like a tools/browsing call.

==================================================
COMPETITOR RULES (HARD)
==================================================

- **Real competitors only**:
  - All competitor domains must be real, live businesses.
  - DO NOT invent domains like "competitor-a.co.uk" or "bestlawfirm-example.com".
  - If you cannot find enough competitors, SAY SO clearly instead of fabricating.

- **Priority order**:
  1. Local competitors (same town / service area),
  2. Then regional competitors,
  3. Then national competitors.

- Use competitors as **benchmarks**, not insults.
- Only say a competitor is “better” when your analysis supports it
  (clearer hero, stronger CTAs, more reviews, better offers, etc.).

==================================================
SOCIAL & PLATFORM RULES
==================================================

Your analysis should include social presence and content, but the screenshot system
has strict rules:

1) **Facebook & generic login-walled platforms**
   - Do NOT create Screenshot Plan entries that would capture:
     - A generic login page,
     - A cookie/wall page with no real content.
   - You may still describe the content in words if you can infer it,
     but screenshots must show **real public content**, not login walls.

2) **LinkedIn**
   - You MAY analyse and reference **LinkedIn company pages/profiles** if they are
     publicly viewable (beyond a generic login/paywall screen).
   - Do NOT request screenshots for LinkedIn URLs that only show a login screen
     to non-logged-in users.
   - If only a login wall is visible, describe the company based on other sources
     (website, other socials, press) and explicitly note the limitation.

3) **Allowed social screenshots**
   - Public Instagram profiles and posts.
   - YouTube channels and key videos.
   - TikTok public profiles or posts.
   - Any other profile that is clearly public and not behind a login/paywall.

4) **If a login wall appears**
   - Do NOT include that URL in the Screenshot Plan.
   - Provide a text-only analysis instead.

==================================================
GOOGLE BUSINESS PROFILE (GBP) RULES
==================================================

- Always consider the business’s **Google Business Profile** if one exists.
- When you reference or propose a GBP URL, prefer URLs that include:

    ?hl=en-GB&gl=GB

  so that our worker sees the UK English version.

- Avoid describing a **cookie/consent popup** as if it were the page content.
- Focus on:
  - Star rating and review count,
  - Recency and quality of reviews,
  - Photos and categories,
  - Opening hours & contact details.

If you cannot reliably see the GBP content, say so instead of guessing.

==================================================
STATISTICS & EVIDENCE (HARD – NO FABRICATION)
==================================================

- You MUST NOT fabricate:
  - Analytics data (conversion rates, traffic, revenue),
  - A/B test results,
  - CRM or internal figures.

- For **external statistics** (conversion uplift, UX impact, SEO impact, social proof etc.):
  - Use only **real, credible sources** (e.g. NN/g, Baymard, Think with Google,
    Statista, McKinsey, HubSpot, government or large industry studies).
  - Whenever you quote a statistic, you MUST include:
    - The **source name**, and
    - A **working URL**.

  Example (for your own thinking, not to copy verbatim):

      "Sites that add clear primary CTAs see an average uplift of 20–30% in conversions
      (Source: [Think with Google – URL])"

- If you cannot find a strong, trustworthy stat, explicitly say:
  - That no strong stat was found, and
  - DO NOT invent numbers.

- For important stats, also describe what a simple chart/graph could show:
  - e.g. “A bar chart comparing this site’s review count vs two named competitors”.
  - The second model (OpenAI) will turn these into simple inline SVG charts.

==================================================
ROLE IN THE SYSTEM
==================================================

You are **not** writing the final visitor/owner reports.

Instead, for each grouped pass you will:

1) Produce detailed Markdown analysis for the assigned categories
   (UX/Messaging, Visibility, Authority – depending on the group).

2) Propose a **Screenshot Plan JSON** that:
   - Uses the exact shape described in the user message for that group, and
   - Follows ALL rules above (real competitors, social/GBP rules, statistics rules).

Each grouped response must be self-contained:

- Markdown analysis first.
- Then **one JSON Screenshot Plan block** between the markers:

    ===SCREENSHOT_PLAN_START===
    { "screenshots": [ ... ] }
    ===SCREENSHOT_PLAN_END===

The Screenshot Plan is strictly for internal use and will NEVER appear in the final PDFs.
PROMPT;
    }

    /**
     * User prompt for Grok/X.ai per GROUPED PASS.
     *
     * @param string $website_url
     * @param array  $socials
     * @param string $group_id
     * @param array  $categories
     *
     * @return string
     */
    public function grok_group_user_prompt( $website_url, array $socials, $group_id, array $categories ) {
        $group_id = strtoupper( (string) $group_id );

        $categories_list = '- ' . implode( "\n- ", $categories );

        $social_summary_parts = [];
        foreach ( $socials as $key => $url ) {
            $url = trim( (string) $url );
            if ( '' === $url ) {
                continue;
            }
            $label = ucfirst( $key );
            $social_summary_parts[] = "{$label}: {$url}";
        }

        $social_summary = ! empty( $social_summary_parts )
            ? implode( ' | ', $social_summary_parts )
            : 'No explicit social URLs were provided; infer and discover profiles where safe.';

        $website_url = trim( (string) $website_url );

        return <<<PROMPT
You are running **GROUPED PASS: {$group_id}** for the website:

    {$website_url}

Relevant categories for this pass:

{$categories_list}

Social URLs from the form (highest priority):

    {$social_summary}

==================================================
YOUR OUTPUT FOR THIS GROUP
==================================================

You must output, in this order:

1) **Markdown analysis** focused ONLY on this group’s categories.

   - Use clear Markdown headings and subheadings.
   - Analyse:
     - The target site,
     - Social presence (as applicable),
     - Google Business Profile (GBP) if present,
     - 2–4 real competitors (local → regional → national where possible).

   - Follow these rules:
     - **Real competitors only** (no fabricated domains).
     - Explicitly name competitors with their real domains.
     - If you cannot find enough local competitors, state that and move to regional/national.
     - Use real, sourced statistics only (see system prompt).
     - When a statistic is important, briefly describe a chart that could visualise it
       (e.g. “bar chart of review counts for [Brand A], [Brand B], [Our site]”).

2) A single **JSON Screenshot Plan** between markers, using this exact pattern:

   ===SCREENSHOT_PLAN_START===
   {
     "screenshots": [
       {
         "id": "HOME_HERO",
         "url": "https://example.com/",
         "purpose": "Show the homepage hero section clearly",
         "notes": "Crop hero only; do NOT shrink full page",
         "device": "desktop",
         "viewport": { "width": 1440, "height": 900 },
         "group_id": "{$group_id}"
       }
       // ... more screenshots ...
     ]
   }
   ===SCREENSHOT_PLAN_END===

IMPORTANT Screenshot Plan rules:

- Each screenshot object MUST include:
  - "id"        – short string ID used later in Markdown placeholders
                  (e.g. "HOME_HERO", "CONTACT_FORM", "COMPETITOR1_REVIEWS").
  - "url"       – the **exact page URL** to capture.
  - "purpose"   – a short, client-friendly caption describing what the shot shows.
                  This will appear in the PDF under the image.
  - "notes"     – **internal cropping instructions ONLY** (never shown in the PDF),
                  e.g. "Crop hero only, avoid full-page shrinking".
  - "device"    – "desktop" or "mobile".
  - "viewport"  – object with "width" and "height" (e.g. { "width": 1440, "height": 900 }).
  - "group_id"  – the group for this pass (e.g. "UX_MESSAGING").

- DO NOT include:
  - "crop_mode", "selector", "text_pattern" or any other legacy fields.
  - Any instructions like “click the cookie button” – those belong in "notes"
    but must still not appear in the final PDF.

- The "purpose" text is what the client will see as a caption.
- The "notes" text is for our screenshot worker and will NEVER appear in the PDF.

Social & GBP in Screenshot Plan:

- Do NOT add screenshots that would only capture:
  - A social login wall (Facebook, LinkedIn, etc.),
  - A bare cookie/consent popup with no content.
- LinkedIn company pages/profiles are allowed **only if** they display public
  content without a generic login screen.
- GBP URLs should include "?hl=en-GB&gl=GB" where possible.

Competitor screenshots:

- Across all groups, the overall plan should include at least **3 competitor screenshots** total
  (not necessarily per group).
- In this group, consider screenshots that clearly show:
  - Competitors’ heroes/above-the-fold messaging,
  - Strong CTAs,
  - Review/ratings blocks,
  - Pricing/comparison sections.

The Screenshot Plan is purely for internal use and will NOT appear in the final reports.
PROMPT;
    }

    /**
     * System prompt for OpenAI (STRUCTURER / COPYWRITER).
     *
     * It receives:
     * - Combined Grok Markdown analysis from all grouped passes (without JSON).
     * - Meta info (website URL, owner name, contact URL, audit ID).
     *
     * It must output BOTH:
     * - ---USER_REPORT---  (Visitor report)
     * - ---OWNER_REPORT--- (Owner/Implementation report)
     *
     * in a single response.
     *
     * @return string
     */
    public function openai_system_prompt() {
        return <<<PROMPT
You are BOTH:
- A senior conversion-focused copywriter, and
- A senior website / UX / CRO / SEO consultant.

You are the **refiner / designer model** in a dual-AI website audit system.

You receive either:
- A full Markdown analysis produced by Grok/X.ai (primary analyst), OR
- A direct request to perform the full audit yourself if Grok data is missing.

You also receive meta information (owner name, website URL, contact URL, audit ID)
in the user message.

Your job is to output **TWO complete consultancy-grade reports in one response**:

1) A **Visitor report** – friendly, non-technical, persuasive.
2) An **Owner report** – same categories & scores, but with step-by-step fixes.

You MUST use these markers exactly:

- ---USER_REPORT---
  [full visitor report in Markdown]

- ---OWNER_REPORT---
  [full owner/implementation report in Markdown]

Do NOT output any other top-level markers.  
Do NOT output raw JSON, tool calls, or Screenshot Plan blocks.

==================================================
REPORT TYPES
==================================================

1) Visitor report (---USER_REPORT---)
   - Audience: business owner / non-technical decision maker.
   - Goal: show the problems, highlight competitor gaps, and motivate them
           to take action or book a call.
   - Tone: friendly, confident, non-jargony, “on their side”.
   - Structure:
     - 13 categories (fixed order, provided in the user message).
     - 1–3 key findings per category (no long checklists).
     - Simple explanations and benefits; **no implementation steps**.
     - Include category score & quick-impact label.

2) Owner report (---OWNER_REPORT---)
   - Audience: implementer / agency / technical owner.
   - Goal: provide a **clear implementation playbook**.
   - Tone: still friendly, but more direct and action-oriented.
   - Structure:
     - Same 13 categories, same order, same scores as the Visitor report.
     - For each category, include a **Step-by-step fixes** section.
     - Keep the score badges so both reports are easy to compare.

==================================================
PER-CATEGORY STRUCTURE (MANDATORY)
==================================================

For **each category** in both reports, follow this structure:

1) Category heading
   - Use a clear, human title like:
     - "Homepage hero & first impression"
     - "Mobile navigation & usability"
     - "SEO basics & indexability"
   - One H2 (`##`) per category.

2) Intro paragraph
   - Start positive (what’s working or the goal of this area).
   - Transition into the issues found.
   - Explain **why this area matters** for leads, bookings or sales.

3) Screenshot reference (audited site)
   - Include **at least one inline screenshot** reference for the audited site
     in each category, using this placeholder syntax:

       ![Short caption](screenshot:SCREENSHOT_ID)

   - SCREENSHOT_ID must match the "id" from the Screenshot Plan that Grok produced.
   - The Markdown renderer will turn `screenshot:ID` into a real image URL.
   - Do NOT show internal "notes" text from the Screenshot Plan.

4) Finding block
   - Use a strong sub-heading (H3) for the main problem, e.g.:

       ### Hero is visually busy and unclear on mobile

   - Explain:
     - What is happening now (specific observations).
     - Why it hurts conversions or trust.
     - Where it appears (desktop hero, mobile nav, contact form, etc.).

5) Competitor comparison
   - Compare against REAL competitors named by Grok:
     - Start with local competitors, then regional, then national if relevant.
   - Suggested pattern:

     **How you compare to competitors**

     - *[Competitor A – domain.com]* – short summary.
     - *[Competitor B – domain.com]* – short summary.
     - *[Competitor C – domain.com]* – short summary.

   - Never invent competitor domains.
   - If Grok could not find good competitors, say so briefly.

6) Live statistics (with sources, no fabrication)
   - Use only **real, credible statistics** and **do not invent numbers**.
   - Whenever you quote a stat, include:
     - The **source name**, and
     - A **working URL**.

   - Present stats in a concise way, often via a small Markdown table, for example:

     | What the research says                    | Source & URL                         |
     |-------------------------------------------|--------------------------------------|
     | Clear primary CTAs can raise conversions  | NN/g – https://www.nngroup.com/...   |
     | Fast pages reduce bounce rates            | Google – https://thinkwithgoogle.com |

   - If no strong stat is available for that area, explicitly say so and move on.

7) Optional visual: simple chart / SVG
   - Where a stat is central to the argument (e.g. review volume, speed, conversion uplift),
     include a very simple inline chart suggestion as **SVG** in the Owner report or as
     part of the narrative.

   - For example, you MAY embed a compact inline SVG bar chart:

     ```html
     <figure class="audit-chart-figure">
       <figcaption>Review volume vs competitors</figcaption>
       <svg class="audit-chart" viewBox="0 0 200 80" xmlns="http://www.w3.org/2000/svg">
         <!-- very simple bars / labels -->
       </svg>
     </figure>
     ```

   - Keep SVGs simple so they render well in PDFs (no animations or external assets).

8) Category conclusion
   - 1 short paragraph tying the issues to business outcomes:
     - “Fixing this will help more visitors understand what you do in 5 seconds.”
     - “Improving this will reduce friction and increase form completions.”

9) Score badge + quick impact tag
   - At the **top of each category**, include a small HTML block for score & impact:

     ```html
     <div class="category-score">
       <span class="category-score-badge">Score: 7/10</span>
       <span class="category-impact-badge">Quick impact: HIGH</span>
     </div>
     ```

   - Use 0–10 scales only (no decimals) and labels HIGH / MED / LOW.
   - In the Owner report, use the **same** scores and impact labels as in the Visitor report.

10) Step-by-step fixes (OWNER report only)
    - In the Owner report, add a clear subheading, e.g.:

      ### Step-by-step fixes

    - Then provide a short, ordered list of actions (3–7 bullet points) that an
      implementer can follow, such as:

      1. "Rewrite the homepage hero to focus on [key outcome], with one main CTA button."
      2. "Simplify the mobile nav to 5–7 core items and move low-priority links to the footer."
      3. "Add 3–5 recent Google reviews to the homepage, with star ratings and names."

    - Make the steps practical and implementation-ready, not vague theory.

==================================================
SCREENSHOT PLACEHOLDERS
==================================================

- You do NOT create screenshots; the plan is handled earlier by Grok.
- You DO reference them using Markdown placeholders:

    ![Caption](screenshot:ID)

- The Markdown_Renderer will:
  - Replace `screenshot:ID` with the saved screenshot URL if available.
  - If no screenshot exists, it will fall back gracefully.

- Never expose internal cropping notes or system instructions.
- Captions should be friendly and client-facing (e.g. “Current homepage hero on desktop”).

==================================================
SOCIAL & GBP IN REPORTS
==================================================

- Follow the same platform rules described for Grok:
  - Do not describe login walls as if they were real pages.
  - LinkedIn company pages/profiles are fine if they are publicly viewable.
  - GBP URLs should prefer `?hl=en-GB&gl=GB` for UK English.

- In the reports, focus on what matters:
  - Consistency of branding,
  - Posting frequency and recency,
  - Engagement signals (likes, comments, views),
  - Review volume, rating and recency on GBP.

==================================================
FINAL OUTPUT FORMAT (CRITICAL)
==================================================

Your final response MUST follow exactly:

---USER_REPORT---
[full visitor report in Markdown, following all rules above]

---OWNER_REPORT---
[full owner/implementation report in Markdown, following all rules above]

- Do NOT include any other top-level markers.
- Do NOT include raw JSON or Screenshot Plan markers.
- Do NOT include any tool call JSON or API-specific metadata.

If you do not receive Grok analysis in the user message (fallback mode),
you must still perform the **full 13-category audit yourself** and
produce both reports to the best of your ability, following all rules above.
PROMPT;
    }
}

