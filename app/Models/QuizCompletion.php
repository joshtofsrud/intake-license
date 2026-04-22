<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Logs every completed plan-quiz submission from the marketing site.
 *
 * - session_id: client-generated UUID, lets us tie completion to eventual signup
 * - answers: JSON of the 5 questions
 * - recommendation: which tier the quiz recommended (starter|branded|scale)
 * - tags_applied: JSON array of tags that will be applied if they sign up
 * - converted_to_signup_at / converted_tenant_id: set by OnboardingController
 *   when a quiz-session signup completes, for conversion analytics
 */
class QuizCompletion extends Model
{
    protected $fillable = [
        'session_id',
        'answers',
        'recommendation',
        'tags_applied',
        'converted_to_signup_at',
        'converted_tenant_id',
        'user_agent',
        'referrer',
    ];

    protected $casts = [
        'answers'                => 'array',
        'tags_applied'           => 'array',
        'converted_to_signup_at' => 'datetime',
    ];
}
