<?php

namespace App\Services\Tenant;

use App\Models\Tenant;
use App\Models\Tenant\TenantCapacityRule;
use App\Models\Tenant\TenantResource;
use App\Models\Tenant\TenantServiceCategory;
use App\Models\Tenant\TenantServiceItem;
use App\Services\Anthropic\AnthropicClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AI Quick Setup. Takes a tenant's free-text business description plus the
 * industry pack they picked at step 1, and writes prefilled wizard state
 * across steps 3-6:
 *   - Step 3 (booking): booking_mode + classes_enabled on the tenant
 *   - Step 4 (hours): TenantCapacityRule rows, one per day
 *   - Step 5 (services): TenantServiceItem rows
 *   - (Step 6 is team — we DON'T autopopulate names, only return a hint
 *      via tenant.onboarding_step landing the user on Booking for review.)
 *
 * Plus: writes a tenant.tagline suggestion (step 2 already saved a basic
 * tagline; AI can improve it if the user left it blank).
 *
 * Returns the parsed prefill data so the controller can include a summary
 * in the JSON response, but the writes themselves happen here in a single
 * DB transaction. Idempotent: deletes any existing wizard-written rules
 * and recreates from the AI output, so a tenant who runs Quick Setup twice
 * gets a clean overwrite.
 */
class OnboardingAiQuickSetupService
{
    private const MODEL = 'claude-sonnet-4-6';
    private const MAX_TOKENS = 8000;

    public function __construct(private AnthropicClient $client)
    {
    }

    /**
     * @return array{
     *   booking_mode: string,
     *   classes_enabled: bool,
     *   tagline: ?string,
     *   hours: array<int, array{day:int, is_closed:bool, open_time:?string, close_time:?string}>,
     *   services: array<int, array{name:string, duration_minutes:int, price_cents:int}>,
     * }
     */
    public function run(Tenant $tenant, string $description): array
    {
        $prefill = $this->callClaude($tenant, $description);
        $this->persist($tenant, $prefill);
        return $prefill;
    }

    private function callClaude(Tenant $tenant, string $description): array
    {
        $industryHint = $tenant->industry_pack
            ? "Industry: {$tenant->industry_pack}"
            : "Industry: not specified";

        $system = <<<PROMPT
            You help a small-business owner set up their online booking page in seconds.
            Given a 1-3 sentence description of their business, output a JSON object that
            prefills four parts of their booking system: booking style, weekly hours, services,
            and a tagline.

            **Output rules — non-negotiable:**

            - Output ONLY a single JSON object. No prose, no markdown, no code fences.
            - The JSON must validate against this schema exactly:

            {
              "booking_mode": "time_slot" | "drop_off",
              "classes_enabled": boolean,
              "tagline": string (max 100 chars),
              "hours": [
                {
                  "day": 0..6 (0=Sunday, 6=Saturday),
                  "is_closed": boolean,
                  "open_time": "HH:MM" or null,
                  "close_time": "HH:MM" or null
                }
                // EXACTLY 7 entries, one per day, in any order
              ],
              "services": [
                {
                  "name": string (max 60 chars),
                  "duration_minutes": integer (5..480, in 5-minute increments),
                  "price_cents": integer (>= 0, in whole cents)
                }
                // 3 to 8 entries
              ]
            }

            **Guidelines for content:**

            - Pick "drop_off" for repair-style businesses where customers leave items and pick
              up later (bike shop, tailor, electronics repair, auto detailing, jewelry, etc).
              Pick "time_slot" for everything else (salon, yoga, fitness, classes, lessons,
              massage, photography). When unsure, pick "time_slot" — it's the platform default.

            - Set "classes_enabled" true when the business runs group classes with capacity
              (yoga, CrossFit, pilates, HIIT, group fitness, art/pottery classes, kids
              programs, etc). False for everything else.

            - Hours: single block per day. The platform doesn't support split shifts, so if
              the user describes split shifts, pick the larger or more typical block. Use
              24-hour HH:MM format. is_closed=true for closed days, with open_time and
              close_time set to null.

            - Services: 3-8 items. Use realistic prices and durations from the user's
              description. If they didn't specify prices, use industry-typical values.
              Service names should be the kind of thing a customer would see when booking
              ("Standard Tune-up", "Vinyasa Flow Class", "60-min Massage").

            - Tagline: a short marketing-friendly description, under 100 chars. If the
              description doesn't suggest one, generate something reasonable.

            **Reminder: output ONLY the JSON object, with no surrounding text.**
            PROMPT;

        $userMessage = "{$industryHint}\n\nBusiness description:\n{$description}";

        $response = $this->client->messages(
            self::MODEL,
            self::MAX_TOKENS,
            [['role' => 'user', 'content' => $userMessage]],
            ['system' => $system, 'temperature' => 0.4]
        );

        // Detect truncation explicitly. If the model hit the token cap, the
        // response is mid-string and trying to parse it as JSON will fail
        // with a misleading "malformed output" error.
        if (($response['stop_reason'] ?? null) === 'max_tokens') {
            Log::warning('AI Quick Setup: response truncated at max_tokens', [
                'tenant_id' => $tenant->id,
                'usage'     => $response['usage'] ?? null,
            ]);
            throw new RuntimeException('AI response was cut short. Try a shorter description, or set things up manually.');
        }

        $text = $this->client->extractText($response);

        // Defensive: strip any accidental code fences. The system prompt forbids them
        // but models sometimes still wrap output, especially on shorter prompts.
        $text = preg_replace('/^```(?:json)?\s*/i', '', trim($text));
        $text = preg_replace('/\s*```$/', '', $text);

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            Log::warning('AI Quick Setup: model returned non-JSON', [
                'tenant_id'    => $tenant->id,
                'stop_reason'  => $response['stop_reason'] ?? null,
                'usage'        => $response['usage'] ?? null,
                'text_length'  => strlen($text),
                'full_text'    => $text,
                'json_error'   => json_last_error_msg(),
            ]);
            throw new RuntimeException('AI returned malformed output. Try a different description.');
        }

        return $this->validateShape($parsed);
    }

    /**
     * Lightweight shape check on the AI response. We don't run a full JSON
     * Schema validator — just enough to fail fast on obvious problems and
     * fall through to the per-field defensive defaults at write time.
     */
    private function validateShape(array $data): array
    {
        $required = ['booking_mode', 'classes_enabled', 'tagline', 'hours', 'services'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RuntimeException("AI output missing required field: {$key}");
            }
        }

        if (!in_array($data['booking_mode'], ['time_slot', 'drop_off'], true)) {
            throw new RuntimeException("AI output has invalid booking_mode: {$data['booking_mode']}");
        }

        if (!is_array($data['hours']) || count($data['hours']) !== 7) {
            throw new RuntimeException('AI output must include exactly 7 hours entries.');
        }

        if (!is_array($data['services']) || count($data['services']) < 1) {
            throw new RuntimeException('AI output must include at least one service.');
        }

        return $data;
    }

    private function persist(Tenant $tenant, array $prefill): void
    {
        DB::transaction(function () use ($tenant, $prefill) {
            // 1. Tenant-level: booking_mode, classes_enabled, tagline (if blank).
            $tenantUpdates = [
                'booking_mode'    => $prefill['booking_mode'],
                'classes_enabled' => (bool) $prefill['classes_enabled'],
                'onboarding_step' => max(3, $tenant->onboarding_step ?? 0),
            ];
            if (empty($tenant->tagline) && !empty($prefill['tagline'])) {
                $tenantUpdates['tagline'] = substr($prefill['tagline'], 0, 100);
            }
            $tenant->update($tenantUpdates);

            // 2. Hours: wipe and recreate the default-rule rows.
            TenantCapacityRule::where('tenant_id', $tenant->id)
                ->where('rule_type', 'default')
                ->whereNull('specific_date')
                ->delete();

            foreach ($prefill['hours'] as $h) {
                $day = (int) ($h['day'] ?? -1);
                if ($day < 0 || $day > 6) continue;

                $isClosed = !empty($h['is_closed']);
                TenantCapacityRule::create([
                    'tenant_id'             => $tenant->id,
                    'rule_type'             => 'default',
                    'day_of_week'           => $day,
                    'specific_date'         => null,
                    'is_closed'             => $isClosed,
                    'open_time'             => $isClosed ? null : ($h['open_time']  ?? null),
                    'close_time'            => $isClosed ? null : ($h['close_time'] ?? null),
                    'max_appointments'      => 8,
                    'slot_interval_minutes' => 30,
                    'note'                  => null,
                ]);
            }

            // 3. Services: wipe wizard-written services from the default category
            //    and recreate from the AI output. Tenants who already added services
            //    manually before clicking Generate would lose them — but that's
            //    consistent with "Generate setup" being a deliberate reset.
            $category = TenantServiceCategory::firstOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'services'],
                ['name' => 'Services', 'is_active' => true, 'sort_order' => 0]
            );

            TenantServiceItem::where('tenant_id', $tenant->id)
                ->where('category_id', $category->id)
                ->delete();

            foreach ($prefill['services'] as $i => $svc) {
                $name = trim((string) ($svc['name'] ?? ''));
                if ($name === '') continue;

                TenantServiceItem::create([
                    'tenant_id'             => $tenant->id,
                    'category_id'           => $category->id,
                    'name'                  => substr($name, 0, 100),
                    'slug'                  => Str::slug($name) . '-' . substr(md5(uniqid()), 0, 6),
                    'price_cents'           => max(0, (int) ($svc['price_cents'] ?? 0)),
                    'duration_minutes'      => max(5, min(480, (int) ($svc['duration_minutes'] ?? 30))),
                    'prep_before_minutes'   => 0,
                    'cleanup_after_minutes' => 0,
                    'slot_weight'           => 1,
                    'is_active'             => true,
                    'sort_order'            => $i,
                ]);
            }
        });
    }
}
