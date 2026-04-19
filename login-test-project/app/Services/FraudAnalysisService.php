<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FraudAnalysisService
{
    /**
     * @param array<string,mixed> $context
     * @return array{decision:string,confidence:float,reasons:array<int,string>}
     */
    public function analyze(array $context): array
    {
        $key = (string) config('services.openai.key');
        $model = (string) config('services.openai.model');

        if ($key === '' || $model === '') {
            return $this->fallback($context, 'missing_openai_config');
        }

        $enabled = (bool) ($context['llm_enabled'] ?? true);
        if (!$enabled) {
            return $this->fallback($context, 'llm_disabled');
        }

        $system = <<<SYS
You are a fraud risk analyst for an authentication system.

IMPORTANT SEMANTICS:
- CHALLENGE is MORE EXTREME than LOCK.
- LOCK is a temporary lockout (cooldown) to slow attackers.
- CHALLENGE means the login is highly suspicious and requires strict step-up verification (OTP) BEFORE access.

Return ONLY a JSON object (no markdown, no prose).
Valid decisions are EXACTLY: ALLOW, LOCK, CHALLENGE.
Do NOT invent facts. Use ONLY the provided context.

Decision policy:
- If risk_score >= 70 OR there are multiple strong signals (new_ip AND ua_change, or recent failed attempts AND new_ip), choose CHALLENGE.
- If risk_score is moderate (40-70) OR signal indicates brute-force pattern, choose LOCK.
- Otherwise choose ALLOW.

Output must match the schema exactly.
SYS;

        $user = [
            'risk_score' => (int)($context['risk_score'] ?? 0),
            'risk_reasons' => $context['risk_reasons'] ?? [],
            'ip' => (string)($context['ip'] ?? ''),
            'user_agent' => (string)($context['user_agent'] ?? ''),
            'failed_login_attempts' => (int)($context['failed_login_attempts'] ?? 0),
            'account_state' => (string)($context['account_state'] ?? 'Active'),
            'last_login_ip' => (string)($context['last_login_ip'] ?? ''),
            'last_user_agent' => (string)($context['last_user_agent'] ?? ''),
        ];

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'decision' => [
                    'type' => 'string',
                    'enum' => ['ALLOW', 'CHALLENGE', 'LOCK'],
                ],
                'confidence' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                ],
                'reasons' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'maxItems' => 6,
                ],
            ],
            'required' => ['decision', 'confidence', 'reasons'],
        ];

        try {
            $resp = Http::timeout(12)
                ->retry(2, 250)
                ->withToken($key)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => $model,
                    'input' => [
                        [
                            'role' => 'system',
                            'content' => [
                                ['type' => 'input_text', 'text' => $system],
                            ],
                        ],
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'input_text', 'text' => json_encode($user, JSON_UNESCAPED_SLASHES)],
                            ],
                        ],
                    ],
                    // Structured Outputs (json_schema) — supported in Responses API when model supports it
                    'text' => [
                        'format' => [
                            'type' => 'json_schema',
                            'name' => 'fraud_decision',
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ],
                ]);

            if (!$resp->successful()) {
                $body = (string) $resp->body();
                Log::warning('Fraud LLM request failed', [
                    'status' => $resp->status(),
                    'body' => $body,
                ]);

                $short = substr(str_replace(["\r", "\n"], [' ', ' '], $body), 0, 200);
                return $this->fallback($context, 'http_'.$resp->status().':'.$short);
            }

            $json = $resp->json();
            if (!is_array($json)) {
                return $this->fallback($context, 'bad_response_json');
            }

            // 1) Collect candidate text outputs robustly.
            // The docs warn output[] can include multiple items; don't assume index 0.  :contentReference[oaicite:2]{index=2}
            $candidates = $this->collectTextCandidates($json);

            // 2) Try to find a JSON object that matches our expected keys.
            foreach ($candidates as $candidate) {
                $obj = $this->decodeJsonLoose($candidate);
                if (is_array($obj) && $this->looksLikeDecisionObject($obj)) {
                    $decision = $this->normalizeDecision($obj['decision'] ?? null);
                    if (!in_array($decision, ['ALLOW', 'CHALLENGE', 'LOCK'], true)) {
                        $got = is_scalar($obj['decision'] ?? null) ? (string)($obj['decision'] ?? 'NULL') : gettype($obj['decision'] ?? null);
                        return $this->fallback($context, 'bad_decision_got:'.$got);
                    }

                    return [
                        'decision' => $decision,
                        'confidence' => is_numeric($obj['confidence'] ?? null) ? (float)$obj['confidence'] : 0.5,
                        'reasons' => is_array($obj['reasons'] ?? null) ? array_values(array_map('strval', $obj['reasons'])) : ['unparsed_reasons'],
                    ];
                }
            }

            // 3) If we decoded JSON but it wasn't the decision object, capture a helpful snippet
            // to show what we *are* receiving.
            $snippetSource = $candidates[0] ?? json_encode($json, JSON_UNESCAPED_SLASHES);
            $snippet = substr(str_replace(["\r", "\n"], [' ', ' '], (string)$snippetSource), 0, 220);

            return $this->fallback($context, 'no_decision_object_snippet:'.$snippet);
        } catch (\Throwable $e) {
            $msg = substr($e->getMessage(), 0, 200);
            Log::error('Fraud LLM exception', ['err' => $e->getMessage()]);
            return $this->fallback($context, 'exception:'.$msg);
        }
    }

    /**
     * @param array<string,mixed> $respJson
     * @return array<int,string>
     */
    private function collectTextCandidates(array $respJson): array
    {
        $candidates = [];

        // Convenience field sometimes exists
        $ot = data_get($respJson, 'output_text', null);
        if (is_string($ot) && trim($ot) !== '') {
            $candidates[] = $ot;
        }

        // Walk all output items + all content entries
        $outputs = data_get($respJson, 'output', []);
        if (is_array($outputs)) {
            foreach ($outputs as $out) {
                $contentArr = data_get($out, 'content', []);
                if (!is_array($contentArr)) continue;

                foreach ($contentArr as $c) {
                    $t = data_get($c, 'text', null);
                    if (is_string($t) && trim($t) !== '') {
                        $candidates[] = $t;
                    }
                }
            }
        }

        // As a last resort, include full raw JSON string (sometimes the decision object is embedded)
        $candidates[] = json_encode($respJson, JSON_UNESCAPED_SLASHES);

        // Deduplicate
        $candidates = array_values(array_unique($candidates));

        return $candidates;
    }

    /**
     * Try to decode JSON even if it comes with code fences or extra text.
     * @return array<string,mixed>|null
     */
    private function decodeJsonLoose(string $text): ?array
    {
        $text = trim($text);

        $direct = json_decode($text, true);
        if (is_array($direct)) return $direct;

        // strip markdown fences
        $text2 = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text2 = preg_replace('/\s*```$/', '', $text2) ?? $text2;

        $direct2 = json_decode(trim($text2), true);
        if (is_array($direct2)) return $direct2;

        // extract first {...} block
        $start = strpos($text2, '{');
        $end = strrpos($text2, '}');
        if ($start === false || $end === false || $end <= $start) return null;

        $maybe = substr($text2, $start, $end - $start + 1);
        $extracted = json_decode($maybe, true);
        return is_array($extracted) ? $extracted : null;
    }

    /**
     * @param array<string,mixed> $obj
     */
    private function looksLikeDecisionObject(array $obj): bool
    {
        return array_key_exists('decision', $obj)
            && array_key_exists('confidence', $obj)
            && array_key_exists('reasons', $obj);
    }

    /**
     * Normalize common model outputs into ALLOW/CHALLENGE/LOCK.
     * @param mixed $decisionRaw
     */
    private function normalizeDecision($decisionRaw): ?string
    {
        if (!is_string($decisionRaw)) return null;

        $d = strtoupper(trim($decisionRaw));

        if (in_array($d, ['ALLOW', 'CHALLENGE', 'LOCK'], true)) return $d;

        if (in_array($d, ['APPROVE', 'APPROVED', 'OK', 'PASS', 'ACCEPT'], true)) return 'ALLOW';
        if (in_array($d, ['REVIEW', 'STEP_UP', 'MFA', 'OTP', 'VERIFY', 'CHALLENGED'], true)) return 'CHALLENGE';
        if (in_array($d, ['DENY', 'BLOCK', 'BLOCKED', 'LOCKED', 'REJECT'], true)) return 'LOCK';

        return null;
    }

    /**
     * Deterministic fallback if LLM is unavailable.
     * Keeping your design: CHALLENGE is more extreme than LOCK.
     * @param array<string,mixed> $context
     */
    private function fallback(array $context, string $why): array
    {
        $score = (int)($context['risk_score'] ?? 0);

        $decision = 'ALLOW';
        if ($score >= 85) {
            $decision = 'CHALLENGE';
        } elseif ($score >= 55) {
            $decision = 'LOCK';
        }
    
        return [
            'decision' => $decision,
            'confidence' => 0.55,
            'reasons' => ["fallback:$why"],
        ];
    }
}