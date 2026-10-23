<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\ScoringSetting;
use App\Services\LeadScoringService;
use App\Services\Sales\ScoreCalibrationService;
use Illuminate\Http\Request;

class ScoringSettingsController extends Controller
{
    public function edit(ScoreCalibrationService $calibration)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return view('settings.scoring', [
            'effective' => ScoringSetting::effective(),
            'defaults' => config('leadpilot.scoring'),
            'calibration' => $calibration->report(),
        ]);
    }

    public function update(Request $request, LeadScoringService $scoring)
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $keys = array_keys(config('leadpilot.scoring.weights'));
        $data = $request->validate([
            'weights' => ['required', 'array'],
            'weights.*' => ['required', 'integer', 'min:0', 'max:100'],
            'thresholds.high' => ['required', 'integer', 'min:1', 'max:100'],
            'thresholds.qualified' => ['required', 'integer', 'min:1', 'max:100'],
            'thresholds.nurture' => ['required', 'integer', 'min:0', 'max:100'],
        ]);
        $weights = array_intersect_key(array_map('intval', $data['weights']), array_flip($keys));
        if (array_sum($weights) !== 100) {
            return back()->withInput()->with('error', 'Weights must add up to exactly 100 (currently ' . array_sum($weights) . ').');
        }
        $t = $data['thresholds'];
        if (!($t['high'] > $t['qualified'] && $t['qualified'] > $t['nurture'])) {
            return back()->withInput()->with('error', 'Thresholds must satisfy High > Qualified > Nurture.');
        }
        ScoringSetting::create(['weights' => $weights, 'thresholds' => array_map('intval', $t), 'updated_by' => auth()->id()]);

        if ($request->boolean('rescore')) {
            \App\Jobs\RescoreAllLeadsJob::dispatch();
        }

        return redirect()->route('settings.scoring')->with('success', 'Scoring settings saved.' . ($request->boolean('rescore') ? ' Re-scoring all leads in the background.' : ''));
    }
}
