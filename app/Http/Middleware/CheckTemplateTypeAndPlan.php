<?php

namespace App\Http\Middleware;

use App\Helpers\Classes\Helper;
use App\Models\OpenAIGenerator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckTemplateTypeAndPlan
{
    public $openAi;

    public function handle(Request $request, Closure $next): Response
    {
        $check = $this->check($request);

        if ($request->user()->isAdmin() || $check) {
            return $next($request);
        }

        return to_route('dashboard.user.payment.subscription')->with(['message' => trans('If you want to use premium service, update your plan.') , 'type' => 'error']);
    }

    public function check(Request $request): bool
    {
        $user = Auth::user();

        $slug = $request->route('slug');

        $openAi = OpenAIGenerator::query()
            ->where('slug', $slug)
            ->where('active', 1)
            ->first();

        if (! $openAi) {
            abort(404);
        }

        $setting = $this->settingSlug($slug);

        if ($setting['status']) {

            $setting = Helper::setting($setting['setting']);

            if ($setting == 0) {
                abort(404);
            }
        }

        if ($user->getAttribute('team_manager_id')) {
            $user = $user->getAttribute('teamManager');
        }

        $plan = $user->relationPlan;

        if($plan) {
            if( $plan->getAttribute('plan_type') == 'All') {
                return true;
            }

            if ($plan->getAttribute('plan_type') == 'Premium') {
                if ($openAi->getAttribute('premium') == 1) {
                    return true;
                }
            }

            if ($plan->getAttribute('plan_type') == 'Regular') {
                if ($openAi->getAttribute('premium') == 0) {
                    return true;
                }
            }
        }

        return false;
    }


    public function settingSlug($slug)
    {
        $data = [
            'ai_article_wizard_generator' => 'feature_ai_article_wizard',
            'ai_writer' => 'feature_ai_writer',
            'ai_rewriter' => 'feature_ai_rewriter',
            'ai_chat_image' => 'feature_ai_chat_image',
            'ai_image_generator' => 'feature_ai_image',
            'ai_code_generator' => 'feature_ai_code',
            'ai_speech_to_text' => 'feature_ai_speech_to_text',
            'ai_voiceover' => 'feature_ai_voiceover',
            'ai_vision' => 'feature_ai_vision',
            'ai_pdf' => 'feature_ai_pdf',
            'ai_youtube' => 'feature_ai_youtube',
            'ai_rss' => 'feature_ai_youtube',
        ];

        if (array_key_exists($slug, $data)) {
            return [
                'status' => true,
                'setting' => $data[$slug]
            ];
        }

        return [
            'status' => false
        ];
    }
}
