<?php

namespace Modules\SatisfactionSurvey\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\SatisfactionSurvey\Models\SurveyResponse;

class SurveyReportController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('manage-settings');

        $workspaceId = $request->user()->workspace_id;
        $days = (int) $request->get('days', 30);

        $base = SurveyResponse::join('conversations', 'conversations.id', '=', 'survey_responses.conversation_id')
            ->where('conversations.workspace_id', $workspaceId)
            ->where('survey_responses.responded_at', '>=', now()->subDays($days));

        $total = (clone $base)->count();
        $avgScore = $total > 0 ? round((clone $base)->avg('survey_responses.rating'), 1) : null;
        $satisfied = (clone $base)->where('survey_responses.rating', '>=', 4)->count();
        $satisfactionRate = $total > 0 ? round(($satisfied / $total) * 100) : null;

        $recent = (clone $base)
            ->select([
                'survey_responses.id',
                'survey_responses.rating',
                'survey_responses.responded_at',
                'conversations.id as conversation_id',
                'conversations.subject',
            ])
            ->orderByDesc('survey_responses.responded_at')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'rating' => (int) $r->rating,
                'responded_at' => $r->responded_at,
                'conversation_id' => $r->conversation_id,
                'subject' => $r->subject,
            ]);

        return Inertia::render('Settings/SurveyReport', [
            'stats' => [
                'total' => $total,
                'avg_score' => $avgScore,
                'satisfied' => $satisfied,
                'satisfaction_rate' => $satisfactionRate,
            ],
            'recent' => $recent,
            'days' => $days,
        ]);
    }
}
