<?php

namespace App\Http\Controllers;

use App\Models\RadarItem;
use App\Support\Like;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Buyer's Radar: buying intent from every source, hot first, with filters. */
class RadarController extends Controller
{
    public function index(Request $request)
    {
        $f = [
            'level' => Like::term($request->query('level')) ?: null,
            'platform' => Like::term($request->query('platform')) ?: null,
            'country' => Like::term($request->query('country')) ?: null,
            'category' => Like::term($request->query('category')) ?: null,
            'service' => Like::term($request->query('service')) ?: null,
            'status' => Like::term($request->query('status', 'new')) ?: 'new',
            'q' => Like::term($request->query('q')),
        ];
        $base = RadarItem::query()
            ->when($f['status'] !== 'all', fn ($q) => $q->where('status', $f['status']))
            ->when($f['platform'], fn ($q) => $q->where('platform', $f['platform']))
            ->when($f['country'], fn ($q) => $q->where('country', $f['country']))
            ->when($f['category'], fn ($q) => $q->where('category', $f['category']))
            ->when($f['service'], fn ($q) => $q->where('service', $f['service']))
            ->when($f['q'] !== '', fn ($q) => $q->where(fn ($w) => Like::where(Like::where($w, 'title', $f['q']), 'excerpt', $f['q'], 'or')));
        $counts = (clone $base)->selectRaw('intent_level, count(*) as n')->groupBy('intent_level')->pluck('n', 'intent_level')->all();
        $items = (clone $base)->with('company')->when($f['level'], fn ($q) => $q->where('intent_level', $f['level']))
            ->orderByRaw("CASE intent_level WHEN 'hot' THEN 0 WHEN 'warm' THEN 1 ELSE 2 END")
            ->orderByDesc('intent_score')->orderByDesc('posted_at')->paginate(30)->withQueryString();

        $options = fn (string $col) => RadarItem::whereNotNull($col)->distinct()->orderBy($col)->pluck($col);

        return view('radar.index', ['items' => $items, 'counts' => $counts, 'f' => $f, 'platforms' => $options('platform'), 'countries' => $options('country'),
            'categories' => $options('category'), 'services' => \App\Support\ServiceCatalog::all()]);
    }

    public function status(Request $request, RadarItem $item)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['new', 'contacted', 'dismissed'])]]);
        $item->update($data);

        return back()->with('success', 'Marked ' . $data['status'] . '.');
    }
}
